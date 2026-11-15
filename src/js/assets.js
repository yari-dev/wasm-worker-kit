/**
 * Loading engine files in a runner's `prepare()`: WebAssembly binaries, models, classic glue scripts.
 *
 * @package WasmWorker
 */

/**
 * Downloads a file once and keeps it in the browser cache (Cache API), with progress and an optional
 * SHA-256 check. Name the files by version or hash: a cached URL is never fetched again.
 *
 * @param {string}    url               File URL.
 * @param {Object=}   options           Options.
 * @param {string=}   options.sha256    Expected hex SHA-256 of the downloaded bytes.
 * @param {string=}   options.cache     Cache name. Default `ww-assets`.
 * @param {Function=} options.onProgress Called with `( loaded, total )` while downloading.
 * @return {Promise<Uint8Array>}
 */
export async function fetchAsset( url, { sha256: expected = '', cache: cacheName = 'ww-assets', onProgress } = {} ) {
	const cache = self.caches ? await self.caches.open( cacheName ).catch( () => null ) : null;
	let bytes = null;

	if ( cache ) {
		const hit = await cache.match( url );
		if ( hit ) {
			bytes = new Uint8Array( await hit.arrayBuffer() );
		}
	}

	const cached = !! bytes;

	if ( ! bytes ) {
		const response = await fetch( url, { credentials: 'same-origin' } );
		if ( ! response.ok || ! response.body ) {
			throw new Error( `Could not download ${ url } (HTTP ${ response.status }).` );
		}

		const total = Number( response.headers.get( 'Content-Length' ) ) || 0;
		const reader = response.body.getReader();
		const chunks = [];
		let loaded = 0;

		for ( ;; ) {
			const { done, value } = await reader.read();
			if ( done ) {
				break;
			}
			chunks.push( value );
			loaded += value.length;
			if ( onProgress ) {
				onProgress( loaded, total );
			}
		}

		bytes = new Uint8Array( loaded );
		let offset = 0;
		for ( const chunk of chunks ) {
			bytes.set( chunk, offset );
			offset += chunk.length;
		}
	}

	if ( expected ) {
		const digest = new Uint8Array( await crypto.subtle.digest( 'SHA-256', bytes ) );
		const hash = Array.from( digest, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
		if ( hash !== expected.toLowerCase() ) {
			if ( cache ) {
				await cache.delete( url );
			}
			throw new Error( `${ url } is damaged (checksum mismatch).` );
		}
	}

	if ( cache && ! cached ) {
		await cache.put( url, new Response( bytes ) ).catch( () => {} );
	}

	return bytes;
}

/**
 * Runs a classic script (e.g. an Emscripten glue file written for `importScripts()`) in a module worker,
 * where `importScripts()` is not available. Its top-level declarations become globals.
 *
 * @param {string} url Script URL (same origin).
 * @return {Promise<void>}
 */
export async function loadScript( url ) {
	const response = await fetch( url, { credentials: 'same-origin' } );
	if ( ! response.ok ) {
		throw new Error( `Could not load ${ url } (HTTP ${ response.status }).` );
	}
	// Indirect eval: runs in the global scope, like importScripts().
	( 0, eval )( `${ await response.text() }\n//# sourceURL=${ url }` ); // eslint-disable-line no-eval
}

/**
 * Compiles a WebAssembly module from bytes, cached in memory for the life of the worker.
 *
 * @param {string}     url   Key (usually the URL).
 * @param {Uint8Array} bytes Bytes.
 * @return {Promise<WebAssembly.Module>}
 */
const modules = new Map();
export function compileWasm( url, bytes ) {
	if ( ! modules.has( url ) ) {
		modules.set( url, WebAssembly.compile( bytes ) );
	}
	return modules.get( url );
}
