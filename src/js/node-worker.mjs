#!/usr/bin/env node
/**
 * Headless worker: runs the same runners as the browsers, in Node (20+), on a server or a CI machine, for
 * sites that need work done without an open admin page. It authenticates with a WordPress Application
 * Password (Users > Profile) of a user allowed to run workers.
 *
 *     node node-worker.mjs --url https://example.com/wp-json/my-plugin-worker/v1/ \
 *         --user worker-bot --password "abcd efgh ijkl mnop qrst uvwx" --runners ./runners.mjs --concurrency 2
 *
 * `runners.mjs` exports the runners (`export default { 'my-task': { prepare, run } }`), usually the same
 * module the browser worker imports. Runners whose `supports()` returns false in Node are skipped.
 * The password can also come from the WW_PASSWORD environment variable (better than the command line).
 * For a local site with its own certificate authority, set NODE_EXTRA_CA_CERTS.
 */
import { isMainThread, parentPort, Worker, workerData } from 'node:worker_threads';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import { parseArgs } from 'node:util';
import os from 'node:os';

if ( isMainThread ) {
	let args;
	try {
		( { values: args } = parseArgs( {
			options: {
				url: { type: 'string' },
				user: { type: 'string' },
				password: { type: 'string' },
				runners: { type: 'string' },
				concurrency: { type: 'string', default: '1' },
				quiet: { type: 'boolean', default: false },
			},
		} ) );
	} catch ( error ) {
		console.error( error.message );
		process.exit( 1 );
	}
	const password = args.password || process.env.WW_PASSWORD || '';

	if ( ! args.url || ! args.user || ! password || ! args.runners ) {
		console.error( 'Usage: node node-worker.mjs --url <REST namespace URL> --user <login> --password <application password> --runners <module> [--concurrency N] [--quiet]' );
		process.exit( 1 );
	}

	const config = {
		restUrl: args.url.endsWith( '/' ) ? args.url : `${ args.url }/`,
		auth: 'Basic ' + Buffer.from( `${ args.user }:${ password.replace( /\s+/g, '' ) }` ).toString( 'base64' ),
		runners: pathToFileURL( path.resolve( args.runners ) ).href,
	};
	const count = Math.max( 1, Number( args.concurrency ) || 1 );
	const quiet = args.quiet;
	const threads = [];

	for ( let i = 0; i < count; i++ ) {
		const thread = new Worker( fileURLToPath( import.meta.url ), { workerData: { ...config, workerId: `node-${ os.hostname().replace( /[^A-Za-z0-9_-]/g, '-' ).slice( 0, 40 ) }-${ i }` } } );
		thread.on( 'message', ( message ) => {
			if ( 'status' !== message.type || ( quiet && ! [ 'error', 'unit-failed', 'completed', 'unsupported' ].includes( message.state ) ) ) {
				return;
			}
			const { type, state, ...rest } = message; // eslint-disable-line no-unused-vars
			console.log( `${ new Date().toISOString() } [${ i }] ${ state } ${ JSON.stringify( rest ) }` );
		} );
		thread.on( 'error', ( error ) => console.error( `[${ i }]`, error ) );
		threads.push( thread );
	}

	const stop = () => {
		console.log( 'Stopping: finished units are submitted, the others go back to the queue.' );
		threads.forEach( ( thread ) => thread.postMessage( { type: 'stop' } ) );
		setTimeout( () => process.exit( 0 ), 3000 );
	};
	process.on( 'SIGINT', stop );
	process.on( 'SIGTERM', stop );
} else {
	// Inside a thread: give the runtime the globals of a Web Worker.
	globalThis.self = globalThis;
	self.postMessage = ( message ) => parentPort.postMessage( message );

	const module = await import( workerData.runners );
	const runners = module.default || module.runners;
	const { serve } = await import( './worker.js' );

	serve( { runners } );
	parentPort.on( 'message', ( message ) => self.onmessage( { data: message } ) );
	self.onmessage( { data: { type: 'start', restUrl: workerData.restUrl, auth: workerData.auth, workerId: workerData.workerId } } );
}
