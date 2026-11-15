/**
 * Runners of the demo's two task types, shared by the browser worker (worker.js) and the headless Node
 * worker (lib/wasm-worker/js/node-worker.mjs --runners assets/runners.js).
 */

// A tiny WebAssembly module exporting `add( a, b )`. A real plugin loads its engine with fetchAsset().
const ADD_WASM = new Uint8Array( [
	0, 97, 115, 109, 1, 0, 0, 0, 1, 7, 1, 96, 2, 127, 127, 1, 127, 3, 2, 1, 0,
	7, 7, 1, 3, 97, 100, 100, 0, 0, 10, 9, 1, 7, 0, 32, 0, 32, 1, 106, 11,
] );

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

export default {
	sum: {
		async prepare() {
			const { instance } = await WebAssembly.instantiate( ADD_WASM );
			return instance.exports;
		},

		// Resumes from the last checkpoint: a replacement worker skips the pairs already summed.
		async run( { input, resume, engine, checkpoint, progress, signal } ) {
			const sums = resume ? resume.sums : [];

			for ( let i = sums.length; i < input.pairs.length; i++ ) {
				signal.throwIfAborted();
				sums.push( engine.add( input.pairs[ i ][ 0 ], input.pairs[ i ][ 1 ] ) );
				await sleep( 40 ); // Pretend it is heavy.

				if ( 0 === ( i + 1 ) % 8 && i + 1 < input.pairs.length ) {
					await checkpoint( { sums } );
				}
				progress( ( i + 1 ) / input.pairs.length );
			}

			return sums;
		},
	},

	grayscale: {
		// Needs OffscreenCanvas (all current browsers, not Node).
		supports: () => 'undefined' !== typeof OffscreenCanvas,

		async run( { input, upload, signal } ) {
			const response = await fetch( input.url, { signal } );
			if ( ! response.ok ) {
				const error = new Error( `The image could not be downloaded (HTTP ${ response.status }).` );
				error.retry = 404 !== response.status; // A missing image will not come back.
				throw error;
			}

			const bitmap = await createImageBitmap( await response.blob() );
			const canvas = new OffscreenCanvas( bitmap.width, bitmap.height );
			const context = canvas.getContext( '2d' );
			context.drawImage( bitmap, 0, 0 );

			// The pixel work a WASM encoder would do in a real plugin.
			const image = context.getImageData( 0, 0, bitmap.width, bitmap.height );
			const data = image.data;
			for ( let p = 0; p < data.length; p += 4 ) {
				const y = Math.round( 0.299 * data[ p ] + 0.587 * data[ p + 1 ] + 0.114 * data[ p + 2 ] );
				data[ p ] = data[ p + 1 ] = data[ p + 2 ] = y;
			}
			context.putImageData( image, 0, 0 );

			await upload( 'gray.png', await canvas.convertToBlob( { type: 'image/png' } ) );

			return { width: bitmap.width, height: bitmap.height };
		},
	},
};
