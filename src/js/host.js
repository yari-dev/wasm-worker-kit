/**
 * Page side of the WASM worker kit: starts the Dedicated Workers that run the host plugin's runners.
 *
 * Only one tab per browser works for a given host plugin (Web Locks API). Typical uses:
 *
 *     // A "process now" page: work until the user leaves.
 *     const workers = startWorkers( { ...config, workerUrl } );
 *     workers.start();
 *
 *     // Every admin page: help in the background when there is work, free the browser otherwise.
 *     watchQueue( { ...config, workerUrl } );
 *
 *     // Run inputs right away with the same runners, outside of the queue (previews, interactive tools).
 *     const direct = createDirectWorker( { ...config, workerUrl } );
 *     const [ outcome ] = await direct.run( 'my-task', [ input ] ); // { result } or { error }
 *
 * @package WasmWorker
 */

/**
 * Tells whether the browser can run the workers.
 *
 * @return {boolean}
 */
export function isSupported() {
	return 'undefined' !== typeof Worker && 'object' === typeof WebAssembly && !! ( self.crypto && self.crypto.subtle && self.crypto.randomUUID );
}

/** Gets a new REST nonce (admin pages stay open for days). */
async function renewNonce( config ) {
	try {
		const response = await fetch( config.nonceUrl, { credentials: 'same-origin' } );
		const nonce = ( await response.text() ).trim();
		if ( response.ok && /^[a-f0-9]{10}$/.test( nonce ) ) {
			config.nonce = nonce;
			return true;
		}
	} catch ( e ) {
		// Logged out or offline.
	}
	return false;
}

/** Returns the ID of this browser profile, so that a reload is not listed as a new worker. */
function getBrowserId() {
	try {
		const id = localStorage.getItem( 'ww-worker-id' ) || crypto.randomUUID();
		localStorage.setItem( 'ww-worker-id', id );
		return id;
	} catch ( error ) {
		return crypto.randomUUID();
	}
}

/**
 * Gives back the units a worker held, with a request that outlives the page (`sendBeacon()`, which cannot set
 * headers: the REST nonce goes in the query string). The server checks the lease as for any other write, so a
 * late or repeated release changes nothing.
 */
function releaseUnits( config, held ) {
	const url = `${ config.restUrl }release${ config.restUrl.includes( '?' ) ? '&' : '?' }_wpnonce=${ encodeURIComponent( config.nonce || '' ) }`;
	const body = JSON.stringify( { lease: held.lease, units: held.units } );

	try {
		if ( navigator.sendBeacon && navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) ) ) {
			return;
		}
	} catch ( e ) {
		// Fall back to fetch below.
	}

	fetch( url, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' }, body } ).catch( () => {} );
}

/** Creates a Dedicated Worker of the host plugin. */
function newWorker( config, index ) {
	return new Worker( config.workerUrl, { type: 'classic' === config.workerType ? 'classic' : 'module', name: `${ config.id }-${ index }` } );
}

/**
 * Creates the controller of this tab's workers.
 *
 * @param {Object}   options              Options.
 * @param {string}   options.id           Host plugin ID (from `Kit::client_config()`).
 * @param {string}   options.restUrl      REST base URL, with trailing slash.
 * @param {string}   options.nonce        REST nonce.
 * @param {string}   options.nonceUrl     URL returning a new nonce.
 * @param {string}   options.workerUrl    URL of the host plugin's worker module.
 * @param {string}   options.workerType   `module` (default) or `classic` (a bundled worker using importScripts()).
 * @param {Object}   options.workerOptions JSON data for the runners (`options` in their context).
 * @param {number}   options.concurrency  Workers in this tab. Default 1. Use more for CPU bound units.
 * @param {number}   options.stopWhenIdle Stop after this many empty claims in a row (0: never). Default 0.
 * @param {Function} options.onStatus     Called with `{ state, worker, ... }` status messages.
 * @return {{start: Function, stop: Function, isRunning: Function}}
 */
export function startWorkers( options ) {
	// The options object is kept, not copied: a nonce renewed here is seen by the caller, and the other way round.
	const config = Object.assign( options, { concurrency: 1, stopWhenIdle: 0, onStatus: () => {}, ...options } );
	const lockName = `ww:${ config.id }`;
	let workers = [];
	let release = null;
	const idle = new Map();
	const held = new Map(); // Worker => the lease and units it is running, given back if the page goes away.
	const browserId = getBrowserId();

	const giveBack = ( worker ) => {
		if ( held.has( worker ) ) {
			releaseUnits( config, held.get( worker ) );
			held.delete( worker );
		}
	};

	// Closed, reloaded or left: the workers die with the page, so their units go back to the queue now
	// instead of staying locked until their lease expires.
	if ( 'function' === typeof addEventListener ) {
		addEventListener( 'pagehide', () => [ ...held.keys() ].forEach( giveBack ) );
	}

	const spawn = ( index ) => {
		const worker = newWorker( config, index );

		worker.onmessage = async ( event ) => {
			const message = event.data || {};

			if ( 'lease' === message.type ) {
				if ( message.lease && Array.isArray( message.units ) && message.units.length ) {
					held.set( worker, { lease: message.lease, units: message.units } );
				} else {
					held.delete( worker );
				}
				return;
			}

			if ( 'need-nonce' === message.type ) {
				await renewNonce( config );
				worker.postMessage( { type: 'nonce', nonce: config.nonce } );
				return;
			}

			if ( 'status' !== message.type ) {
				return;
			}

			config.onStatus( { ...message, worker: index } );

			idle.set( index, 'idle' === message.state ? message.idle : 0 );
			if ( config.stopWhenIdle && workers.length && [ ...idle.values() ].every( ( n ) => n >= config.stopWhenIdle ) ) {
				controller.stop();
			}
		};

		worker.onerror = ( event ) => config.onStatus( { state: 'error', worker: index, message: event.message || 'Worker error' } );
		worker.postMessage( { type: 'start', restUrl: config.restUrl, nonce: config.nonce, options: config.workerOptions || {}, workerId: `${ browserId.slice( 0, 36 ) }-${ index }` } );

		return worker;
	};

	const run = () => {
		idle.clear();
		workers = Array.from( { length: Math.max( 1, config.concurrency ) }, ( _, i ) => spawn( i ) );
	};

	const controller = {
		/**
		 * Starts working in this tab, once no other tab of the browser does.
		 *
		 * @param {boolean} wait Wait for the other tab (true) or give up right away (false).
		 * @return {Promise<boolean>} False when another tab works and `wait` is false.
		 */
		start( wait = false ) {
			if ( workers.length || release ) {
				return Promise.resolve( true );
			}

			if ( ! isSupported() ) {
				config.onStatus( { state: 'unsupported' } );
				return Promise.resolve( false );
			}

			if ( ! navigator.locks ) {
				run();
				return Promise.resolve( true );
			}

			return new Promise( ( resolve ) => {
				let acquired = false;

				navigator.locks.request( lockName, wait ? {} : { ifAvailable: true }, ( lock ) => {
					if ( ! lock ) {
						config.onStatus( { state: 'other-tab' } );
						resolve( false );
						return null;
					}

					acquired = true;
					run();
					resolve( true );

					// Hold the lock until stopped (closing the tab releases it too).
					return new Promise( ( done ) => {
						release = done;
					} );
				} );

				// Waiting for another tab: say so (the workers start here when that tab closes).
				if ( wait ) {
					navigator.locks.query().then( ( locks ) => {
						if ( ! acquired && ( locks.held || [] ).some( ( lock ) => lockName === lock.name ) ) {
							config.onStatus( { state: 'other-tab' } );
						}
					} ).catch( () => {} );
				}
			} );
		},

		/**
		 * Stops the workers. Each submits what it finished and gives the rest of its batch back to the queue;
		 * a worker that does not answer within 2 s is ended and its units are given back from here.
		 */
		stop() {
			for ( const worker of workers ) {
				worker.postMessage( { type: 'stop' } );
				setTimeout( () => {
					worker.terminate();
					giveBack( worker );
				}, 2000 );
			}
			workers = [];

			if ( release ) {
				release();
				release = null;
			}

			config.onStatus( { state: 'stopped' } );
		},

		isRunning() {
			return workers.length > 0;
		},
	};

	return controller;
}

/**
 * Background helper: polls the amount of waiting work and starts this tab's workers when there is some
 * and no other tab of the browser works. The workers stop after about half a minute without work.
 *
 * @param {Object}   options          Same as `startWorkers()`.
 * @param {number}   options.interval Polling interval in ms. Default 60000.
 * @param {string[]} options.types    Task types this tab's worker runs: only their work wakes it. Default: all.
 * @return {{stop: Function, workers: Object}}
 */
export function watchQueue( options ) {
	const config = Object.assign( options, { interval: 60000, stopWhenIdle: 3, ...options } );
	const workers = startWorkers( config );
	let timer = null;

	const check = async () => {
		if ( workers.isRunning() ) {
			return;
		}

		try {
			// The address may already have a query (plain permalinks: ?rest_route=/ns/).
			const query = Array.isArray( config.types ) && config.types.length ? config.types.map( ( type ) => `types[]=${ encodeURIComponent( type ) }` ).join( '&' ) : '';
			const url = config.restUrl + 'waiting' + ( query ? ( config.restUrl.includes( '?' ) ? '&' : '?' ) + query : '' );
			const response = await fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } } );
			if ( 403 === response.status ) {
				await renewNonce( config );
				return;
			}
			const data = await response.json();
			if ( data && data.waiting > 0 ) {
				await workers.start( false );
			}
		} catch ( e ) {
			// Offline or logged out: try again later.
		}
	};

	check();
	timer = setInterval( check, config.interval );

	return {
		workers,
		stop() {
			clearInterval( timer );
			workers.stop();
		},
	};
}

/**
 * Creates a worker that runs inputs right away, outside of the queue, with the same runners (and their
 * `prepare()`): previews, interactive tools, a "translate this field" button. It is separate from the queue
 * workers and takes no lock. Runs are done one at a time, in order.
 *
 * @param {Object}   options          Same as `startWorkers()` (`id`, `restUrl`, `nonce`, `nonceUrl`, `workerUrl`,
 *                                    `workerType`, `workerOptions`).
 * @param {Function} options.onStatus Optional: status messages of the runners (`report()`, `progress`).
 * @return {{run: Function, close: Function}}
 */
export function createDirectWorker( options ) {
	const config = Object.assign( options, { onStatus: () => {}, ...options } );
	const pending = new Map();
	let worker = null;
	let sequence = 0;

	const fail = ( error ) => {
		pending.forEach( ( request ) => request.reject( error ) );
		pending.clear();
	};

	const ensure = () => {
		if ( worker ) {
			return worker;
		}

		worker = newWorker( config, 'direct' );
		worker.onmessage = async ( event ) => {
			const message = event.data || {};

			if ( 'need-nonce' === message.type ) {
				await renewNonce( config );
				worker.postMessage( { type: 'nonce', nonce: config.nonce } );
			} else if ( 'run-result' === message.type ) {
				const request = pending.get( message.id );
				if ( request ) {
					pending.delete( message.id );
					if ( message.error ) {
						request.reject( new Error( message.error ) );
					} else {
						request.resolve( message.outcomes );
					}
				}
			} else if ( 'status' === message.type ) {
				config.onStatus( message );
			}
		};
		worker.onerror = ( event ) => {
			fail( new Error( event.message || 'Worker error' ) );
			worker.terminate();
			worker = null;
		};
		worker.postMessage( { type: 'configure', restUrl: config.restUrl, nonce: config.nonce, options: config.workerOptions || {}, workerId: `${ getBrowserId().slice( 0, 36 ) }-direct` } );

		return worker;
	};

	return {
		/**
		 * Runs inputs of a task type.
		 *
		 * @param {string} type   Task type.
		 * @param {Array}  inputs Inputs, as the runner gets them from `Task::payload()`.
		 * @return {Promise<Object[]>} One outcome per input: `{ result }` or `{ error }`.
		 */
		run( type, inputs ) {
			if ( ! isSupported() ) {
				return Promise.reject( new Error( 'This browser cannot run the workers.' ) );
			}

			const id = ++sequence;
			const target = ensure();

			return new Promise( ( resolve, reject ) => {
				pending.set( id, { resolve, reject } );
				target.postMessage( { type: 'run', id, task: type, inputs } );
			} );
		},

		/**
		 * Ends the worker (it starts again at the next run).
		 */
		close() {
			if ( worker ) {
				worker.terminate();
				worker = null;
			}
			fail( new Error( 'The worker was closed.' ) );
		},
	};
}
