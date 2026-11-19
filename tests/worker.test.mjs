/**
 * Tests of the worker runtime (src/js/worker.js) against a fake kit REST API, in Node (20+):
 *
 *     node tests/worker.test.mjs
 *
 * worker.js runs in a worker thread with the globals of a Web Worker, as in node-worker.mjs. The fake server
 * hands out batches, checks what comes back, and records the statuses the worker posts.
 */
import http from 'node:http';
import { isMainThread, parentPort, Worker, workerData } from 'node:worker_threads';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const here = path.dirname( fileURLToPath( import.meta.url ) );

if ( ! isMainThread ) {
	// Inside the thread: Web Worker globals, then the runtime with test runners.
	globalThis.self = globalThis;
	self.postMessage = ( message ) => parentPort.postMessage( message );
	const { serve } = await import( pathToFileURL( path.join( here, '..', 'src', 'js', 'worker.js' ) ).href );

	serve( {
		runners: {
			add: {
				prepare: ( env ) => ( { offset: env.options.offset } ),
				async run( ctx ) {
					ctx.report( 'custom', { unit: ctx.unit ? ctx.unit.key : 'direct' } );
					if ( 'bad' === ctx.input.kind ) {
						const error = new Error( 'cannot add' );
						error.retry = false;
						throw error;
					}
					const echo = await ( await ctx.fetch( workerData.base + 'echo' ) ).json();
					return { sum: ctx.input.a + ctx.input.b + ctx.engine.offset, auth: echo.auth };
				},
			},
			slow: {
				// Waits until the worker stops when asked to (a long unit interrupted by the page going away).
				run( ctx ) {
					if ( ! ctx.input.wait ) {
						return ctx.input.a;
					}
					return new Promise( ( resolve, reject ) => ctx.signal.addEventListener( 'abort', () => reject( new Error( 'aborted' ) ) ) );
				},
			},
			up: {
				async run( ctx ) {
					const receipt = await ctx.upload( 'file.bin', new Uint8Array( [ 1, 2, 3 ] ) );
					return receipt.key;
				},
			},
			pair: {
				runBatch( ctx ) {
					ctx.progress( 0.5 );
					return ctx.units.map( ( { input } ) => ( input.fail ? { error: 'no pair', retry: false } : { result: input.a * 2 } ) );
				},
			},
		},
	} );
	parentPort.on( 'message', ( message ) => self.onmessage( { data: message } ) );
} else {
	let failures = 0;
	const check = ( ok, label ) => {
		console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ label }` );
		failures += ok ? 0 : 1;
	};

	// Fake server: two batches (one per runner style), one that uploads, one stopped mid-way, then nothing.
	const seen = { claims: [], completes: [], fails: [], releases: [], artifacts: [] };
	const batches = [
		{ lease: 'a'.repeat( 64 ), type: 'add', affinity: 'nl-en', ttl: 60, units: [ { id: 1, epoch: 1, key: 'u1', job_id: 7, attempt: 1, input: { a: 1, b: 2 } }, { id: 2, epoch: 1, key: 'u2', job_id: 7, attempt: 2, input: { kind: 'bad' } } ] },
		{ lease: 'b'.repeat( 64 ), type: 'pair', affinity: 'de-en', ttl: 60, units: [ { id: 3, epoch: 1, key: 'p1', job_id: 8, input: { a: 5 } }, { id: 4, epoch: 1, key: 'p2', job_id: 8, input: { fail: true } }, { id: 5, epoch: 1, key: 'p3', job_id: 8, input: { a: 7 } } ] },
		{ lease: 'd'.repeat( 64 ), type: 'up', ttl: 60, units: [ { id: 20, epoch: 1, key: 'f1', job_id: 10, input: {} } ] },
		{ lease: 'c'.repeat( 64 ), type: 'slow', ttl: 60, units: [ { id: 6, epoch: 3, key: 's1', job_id: 9, input: { a: 1 } }, { id: 7, epoch: 3, key: 's2', job_id: 9, input: { wait: true } }, { id: 8, epoch: 4, key: 's3', job_id: 9, input: { a: 3 } } ] },
	];
	const server = http.createServer( async ( req, res ) => {
		let body = '';
		for await ( const chunk of req ) {
			body += chunk;
		}
		// An upload is multipart, not JSON.
		const data = body && ( req.headers[ 'content-type' ] || '' ).startsWith( 'application/json' ) ? JSON.parse( body ) : {};
		const route = req.url.split( '/' ).pop();
		const send = ( value ) => {
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( value ) );
		};

		if ( 'claim' === route ) {
			seen.claims.push( data );
			return send( { batch: batches.shift() || null } );
		}
		if ( 'complete' === route ) {
			seen.completes.push( data );
			return send( { receipts: data.results.map( ( r ) => ( { unit_id: r.unit_id, accepted: true } ) ) } );
		}
		if ( 'fail' === route ) {
			seen.fails.push( data );
			return send( { ok: true } );
		}
		if ( 'release' === route ) {
			seen.releases.push( data );
			return send( { released: data.units.map( ( u ) => u.unit_id ) } );
		}
		if ( 'artifact' === route ) {
			seen.artifacts.push( req.headers[ 'content-type' ] || '' );
			if ( 1 === seen.artifacts.length ) {
				// The server is down for the first upload: the worker sends it again.
				res.writeHead( 503, { 'Content-Type': 'application/json' } );
				return res.end( JSON.stringify( { code: 'down', message: 'down' } ) );
			}
			return send( { key: 'file.bin', size: 3, sha256: 'x' } );
		}
		if ( 'echo' === route ) {
			return send( { auth: req.headers.authorization || req.headers[ 'x-wp-nonce' ] || '' } );
		}
		return send( {} );
	} );
	await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const base = `http://127.0.0.1:${ server.address().port }/kit/`;

	const thread = new Worker( fileURLToPath( import.meta.url ), { workerData: { base } } );
	const statuses = [];
	const results = new Map();
	const leases = [];
	thread.on( 'message', ( message ) => {
		if ( 'lease' === message.type ) {
			leases.push( message );
		} else if ( 'status' === message.type ) {
			statuses.push( message );
		} else if ( 'run-result' === message.type ) {
			results.set( message.id, message );
		}
	} );

	// Queue mode.
	thread.postMessage( { type: 'start', restUrl: base, auth: 'Basic dGVzdA==', options: { offset: 100 }, workerId: 'test-worker-0' } );
	const until = async ( test, ms = 8000 ) => {
		const end = Date.now() + ms;
		while ( ! test() && Date.now() < end ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );
		}
		return test();
	};

	await until( () => seen.completes.length >= 3 && seen.claims.length >= 4 && statuses.some( ( s ) => 'working' === s.state && 's2' === s.unit ) );

	const first = seen.completes[ 0 ];
	check( 1 === first.results.length && 103 === first.results[ 0 ].result.sum, 'run(): result accepted, prepare() got the host options' );
	check( 'Basic dGVzdA==' === first.results[ 0 ].result.auth, 'ctx.fetch() sends the worker credentials' );
	check( seen.fails.some( ( f ) => 2 === f.unit_id && false === f.retry && 'cannot add' === f.error ), 'a thrown error with retry = false fails the unit for good' );
	check( '' === seen.claims[ 0 ].affinity && 'nl-en' === seen.claims[ 1 ].affinity && 'de-en' === seen.claims[ 2 ].affinity, 'each claim asks for the affinity of the previous batch' );
	check( 'test-worker-0' === seen.claims[ 0 ].worker.id, 'the worker ID from the host is used' );

	const second = seen.completes[ 1 ];
	check( '10,14' === second.results.map( ( r ) => r.result ).join() && seen.fails.some( ( f ) => 4 === f.unit_id && 'no pair' === f.error ), 'runBatch(): one outcome per unit, errors given back' );
	check( statuses.some( ( s ) => 'custom' === s.state && 'add' === s.task && 'u1' === s.unit ), 'report() posts a custom status with the task type' );
	check( statuses.some( ( s ) => 'working' === s.state && 'add' === s.task ) && statuses.some( ( s ) => 'completed' === s.state && 'pair' === s.task ), 'statuses keep their type (working, completed)' );
	check( statuses.some( ( s ) => 'progress' === s.state && 'pair' === s.task && 0.5 === s.fraction ), 'batch progress' );

	const third = seen.completes[ 2 ] || { results: [] };
	check( 2 === seen.artifacts.length && seen.artifacts.every( ( type ) => type.startsWith( 'multipart/form-data' ) ), 'upload(): a failed upload (503) is sent again' );
	check( 1 === third.results.length && 20 === third.results[ 0 ].unit_id && 'file.bin' === third.results[ 0 ].result, 'upload(): the unit completes with the receipt of the second attempt' );

	// Direct runs.
	thread.postMessage( { type: 'run', id: 1, task: 'pair', inputs: [ { a: 2 }, { fail: true } ] } );
	thread.postMessage( { type: 'run', id: 2, task: 'add', inputs: [ { a: 1, b: 1 } ] } );
	thread.postMessage( { type: 'run', id: 3, task: 'nope', inputs: [ {} ] } );
	await until( () => results.size >= 3 );
	check( 4 === results.get( 1 )?.outcomes?.[ 0 ]?.result && 'no pair' === results.get( 1 )?.outcomes?.[ 1 ]?.error, 'direct run with runBatch()' );
	check( 102 === results.get( 2 )?.outcomes?.[ 0 ]?.result?.sum, 'direct run with run() and the prepared engine' );
	check( /cannot run/.test( results.get( 3 )?.error || '' ), 'direct run of an unknown type is refused' );

	// Stopped in the middle of a batch: what is done is submitted, the rest is released at once.
	check( 'a'.repeat( 64 ) === leases[ 0 ]?.lease && '1/1,2/1' === leases[ 0 ].units.map( ( u ) => `${ u.unit_id }/${ u.epoch }` ).join(), 'the worker tells the page which lease and units it holds' );
	check( leases.length >= 5 && null === leases[ 1 ].lease && 'c'.repeat( 64 ) === leases[ leases.length - 1 ].lease, 'and when a batch ends' );
	thread.postMessage( { type: 'stop' } );
	await until( () => seen.releases.length >= 1 && null === leases[ leases.length - 1 ].lease );
	const release = seen.releases[ 0 ] || {};
	check( 'c'.repeat( 64 ) === release.lease && '7/3,8/4' === ( release.units || [] ).map( ( u ) => `${ u.unit_id }/${ u.epoch }` ).join(), 'stop(): the unfinished units of the batch are released' );
	check( 4 === seen.completes.length && 6 === seen.completes[ 3 ].results[ 0 ].unit_id && 1 === seen.completes[ 3 ].results.length, 'stop(): the finished unit is submitted, not released' );
	check( 1 === seen.releases.length && null === leases[ leases.length - 1 ].lease, 'stop(): one release, then the page is told nothing is held' );
	await thread.terminate();
	server.close();

	// Page side (host.js): units of a page that goes away are given back with a beacon.
	const beacons = [];
	const pagehide = [];
	const fakes = [];
	globalThis.self = globalThis;
	globalThis.Worker = class {
		constructor() {
			this.messages = [];
			fakes.push( this );
		}
		postMessage( message ) {
			this.messages.push( message );
		}
		terminate() {
			this.terminated = true;
		}
	};
	Object.defineProperty( globalThis, 'navigator', { configurable: true, value: { sendBeacon: ( url, data ) => beacons.push( { url, data } ) > 0 } } );
	globalThis.addEventListener = ( type, listener ) => 'pagehide' === type && pagehide.push( listener );
	const { startWorkers } = await import( pathToFileURL( path.join( here, '..', 'src', 'js', 'host.js' ) ).href );
	const lease = ( worker, token, units ) => worker.onmessage( { data: { type: 'lease', lease: token, units } } );
	const beacon = async ( index ) => ( { url: beacons[ index ]?.url, type: beacons[ index ]?.data?.type, body: JSON.parse( ( await beacons[ index ]?.data?.text() ) || '{}' ) } );

	const page = startWorkers( { id: 'test', restUrl: 'https://site.test/wp-json/kit/v1/', nonce: 'abc123', workerUrl: 'worker.js' } );
	await page.start();
	lease( fakes[ 0 ], 'd'.repeat( 64 ), [ { unit_id: 9, epoch: 2 } ] );
	pagehide.forEach( ( listener ) => listener() );
	const sent = await beacon( 0 );
	check( 1 === beacons.length && 'https://site.test/wp-json/kit/v1/release?_wpnonce=abc123' === sent.url && 'application/json' === sent.type, 'pagehide: a JSON beacon to release, with the nonce in the query string' );
	check( 'd'.repeat( 64 ) === sent.body.lease && 9 === sent.body.units?.[ 0 ]?.unit_id && 2 === sent.body.units[ 0 ].epoch, 'pagehide: the beacon names the lease and the units with their epochs' );
	pagehide.forEach( ( listener ) => listener() );
	lease( fakes[ 0 ], 'e'.repeat( 64 ), [ { unit_id: 10, epoch: 1 } ] );
	lease( fakes[ 0 ], null, [] );
	pagehide.forEach( ( listener ) => listener() );
	check( 1 === beacons.length, 'pagehide: nothing is sent twice, or for a batch that ended' );

	lease( fakes[ 0 ], 'f'.repeat( 64 ), [ { unit_id: 11, epoch: 1 } ] );
	page.stop();
	check( 'stop' === fakes[ 0 ].messages[ fakes[ 0 ].messages.length - 1 ].type && 1 === beacons.length, 'stop(): the worker is asked to stop and release first' );
	await new Promise( ( resolve ) => setTimeout( resolve, 2100 ) );
	check( fakes[ 0 ].terminated && 2 === beacons.length && 'f'.repeat( 64 ) === ( await beacon( 1 ) ).body.lease, 'stop(): a worker that did not finish in time is ended and its units released from the page' );

	const plain = startWorkers( { id: 'plain', restUrl: 'https://site.test/?rest_route=/kit/v1/', nonce: 'abc123', workerUrl: 'worker.js' } );
	await plain.start();
	lease( fakes[ 1 ], 'a'.repeat( 64 ), [ { unit_id: 12, epoch: 1 } ] );
	pagehide.forEach( ( listener ) => listener() );
	check( 'https://site.test/?rest_route=/kit/v1/release&_wpnonce=abc123' === ( await beacon( 2 ) ).url, 'pagehide: plain permalinks get the nonce with &' );
	plain.stop();

	console.log( failures ? `${ failures } check(s) failed.` : 'All worker checks passed.' );
	process.exitCode = failures ? 1 : 0;
}
