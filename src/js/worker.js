/**
 * Worker runtime of the WASM worker kit. Runs inside a Dedicated Worker, so the engine never freezes the admin
 * page and leases keep being renewed in background tabs.
 *
 * The host plugin's worker file registers its runners:
 *
 *     import { serve } from './kit/worker.js';
 *     serve( { runners: { 'my-task': { prepare, run } } } );
 *
 * The runner API is described in the "Runners" section of the kit's README.md.
 *
 * @package WasmWorker
 */

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/** Tells whether an error means the WebAssembly instance is unusable (trap, abort, out of memory). */
function isEngineCrash( error ) {
	return ( 'undefined' !== typeof WebAssembly && error instanceof WebAssembly.RuntimeError ) ||
		/^(RuntimeError|CompileError|LinkError)$/.test( error && error.name ) ||
		/\bAborted\(|out of memory|memory access out of bounds|unreachable/i.test( String( error && error.message ) );
}

/** Hex SHA-256 of bytes. */
async function sha256( bytes ) {
	const digest = new Uint8Array( await crypto.subtle.digest( 'SHA-256', bytes ) );
	return Array.from( digest, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * Describes what this worker's environment can do, for the server's worker registry.
 *
 * @return {Object}
 */
export function capabilities() {
	const nav = 'undefined' !== typeof navigator ? navigator : {};
	return {
		webgpu: !! nav.gpu,
		cores: nav.hardwareConcurrency || 0,
		memory: nav.deviceMemory || 0,
		threads: 'undefined' !== typeof SharedArrayBuffer && !! self.crossOriginIsolated,
		node: 'undefined' !== typeof process && !! ( process.versions && process.versions.node ),
	};
}

/**
 * Starts serving the page: waits for `start`, then processes the queue until `stop`.
 *
 * @param {Object} options         Options.
 * @param {Object} options.runners Runners by task type.
 */
export function serve( { runners } ) {
	if ( ! runners || ! Object.keys( runners ).length ) {
		throw new Error( 'serve() needs at least one runner.' );
	}

	const state = {
		config: null, // { restUrl, nonce, auth }
		options: {}, // The host's `workerOptions`, given to the runners.
		running: false,
		current: null, // AbortController of the batch in progress.
		affinity: '', // Affinity of the previous batch, asked for first.
		engines: new Map(), // Type => Promise of the prepared engine.
		progress: new Map(), // Unit ID => fraction, sent with the renewals.
		nonceWaiters: [],
		direct: Promise.resolve(), // Direct runs, one at a time.
		worker: {
			id: crypto.randomUUID(),
			capabilities: capabilities(),
			agent: 'undefined' !== typeof navigator && navigator.userAgent ? navigator.userAgent.slice( 0, 200 ) : 'unknown',
		},
	};

	const post = ( type, data = {} ) => self.postMessage( { ...data, type } ); // The message type always wins.
	const status = ( name, data = {} ) => post( 'status', { state: name, ...data } );
	const message = ( error ) => String( ( error && error.message ) || error );

	/** Takes the connection settings of a `start` or `configure` message. */
	const configure = ( data ) => {
		// `auth` (an Authorization header value) replaces the cookie and nonce, for headless workers.
		state.config = { restUrl: data.restUrl, nonce: data.nonce || '', auth: data.auth || '' };
		state.options = data.options && 'object' === typeof data.options ? data.options : {};
		// A stable ID from the host (one per browser or machine) keeps the worker list short.
		if ( data.workerId ) {
			state.worker.id = String( data.workerId ).slice( 0, 64 );
		}
	};

	self.onmessage = ( event ) => {
		const data = event.data || {};

		if ( 'start' === data.type ) {
			configure( data );
			if ( ! state.running ) {
				state.running = true;
				loop();
			}
		} else if ( 'configure' === data.type ) {
			configure( data );
		} else if ( 'stop' === data.type ) {
			state.running = false;
			if ( state.current ) {
				state.current.abort();
			}
		} else if ( 'nonce' === data.type ) {
			state.config.nonce = data.nonce;
			state.nonceWaiters.splice( 0 ).forEach( ( resolve ) => resolve() );
		} else if ( 'run' === data.type ) {
			state.direct = state.direct.then( () => runDirect( data ) );
		}
	};

	/**
	 * Fetches a URL with the worker's credentials (nonce and cookie, or the headless worker's Authorization
	 * header), renewing an expired nonce once. Only URLs of the site get the credentials.
	 */
	async function authFetch( url, init = {}, retry = true ) {
		const own = new URL( url, state.config.restUrl ).origin === new URL( state.config.restUrl ).origin;
		const headers = new Headers( init.headers || {} );

		if ( own ) {
			if ( state.config.auth ) {
				headers.set( 'Authorization', state.config.auth );
			} else {
				headers.set( 'X-WP-Nonce', state.config.nonce );
			}
		}

		const response = await fetch( url, { ...init, headers, credentials: own && ! state.config.auth ? 'same-origin' : 'omit' } );

		if ( own && 403 === response.status && retry && ! state.config.auth ) {
			const data = await response.clone().json().catch( () => null );
			if ( data && 'rest_cookie_invalid_nonce' === data.code ) {
				await new Promise( ( resolve ) => {
					state.nonceWaiters.push( resolve );
					post( 'need-nonce' );
					setTimeout( resolve, 30000 );
				} );
				return authFetch( url, init, false );
			}
		}

		return response;
	}

	/** Calls a REST route of the kit. A JSON object is sent as JSON, a FormData as multipart. */
	async function api( path, body ) {
		const isForm = body instanceof FormData;
		const response = await authFetch( state.config.restUrl + path, {
			method: body ? 'POST' : 'GET',
			headers: body && ! isForm ? { 'Content-Type': 'application/json' } : {},
			body: body ? ( isForm ? body : JSON.stringify( body ) ) : undefined,
		} );
		const data = await response.json().catch( () => null );

		if ( ! response.ok ) {
			const error = new Error( ( data && data.message ) || `HTTP ${ response.status }` );
			error.code = data && data.code;
			error.status = response.status;
			throw error;
		}

		return data;
	}

	/** What every runner function gets: credentials, custom statuses, the host's options. */
	function env( type ) {
		return {
			fetch: ( url, init ) => authFetch( url, init ),
			report: ( name, data = {} ) => status( String( name ), { ...data, task: type } ),
			options: state.options,
		};
	}

	/** Returns the prepared engine of a type (prepared once, again after a crash). */
	function getEngine( type ) {
		if ( ! state.engines.has( type ) ) {
			const runner = runners[ type ];
			const promise = Promise.resolve( runner.prepare ? runner.prepare( env( type ) ) : null );
			promise.catch( () => state.engines.delete( type ) );
			state.engines.set( type, promise );
		}
		return state.engines.get( type );
	}

	/** Runs inputs sent by the page, outside of the queue, and posts the outcomes. */
	async function runDirect( data ) {
		const runner = runners[ data.task ];
		const inputs = Array.isArray( data.inputs ) ? data.inputs : [];

		try {
			if ( ! runner || ( runner.supports && false === runner.supports() ) ) {
				throw new Error( `This worker cannot run "${ data.task }".` );
			}
			if ( ! state.config ) {
				throw new Error( 'The worker is not configured.' );
			}

			const engine = await getEngine( data.task );
			const signal = new AbortController().signal;
			const unavailable = () => Promise.reject( new Error( 'Not available in a direct run.' ) );
			let outcomes;

			if ( runner.runBatch ) {
				outcomes = await runner.runBatch( {
					...env( data.task ),
					units: inputs.map( ( input ) => ( { input, unit: null, attempt: 1 } ) ),
					engine,
					signal,
					progress: () => {},
				} );
			} else {
				outcomes = [];
				for ( const input of inputs ) {
					try {
						outcomes.push( { result: await runner.run( { ...env( data.task ), input, resume: null, artifacts: [], engine, signal, unit: null, attempt: 1, checkpoint: unavailable, upload: unavailable, progress: () => {} } ) } );
					} catch ( error ) {
						if ( isEngineCrash( error ) ) {
							state.engines.delete( data.task );
						}
						outcomes.push( { error: message( error ) } );
					}
				}
			}

			post( 'run-result', { id: data.id, outcomes } );
		} catch ( error ) {
			if ( isEngineCrash( error ) ) {
				state.engines.delete( data.task );
			}
			post( 'run-result', { id: data.id, error: message( error ) } );
		}
	}

	/** Main loop: claim, run, submit; back off while there is nothing to do. */
	async function loop() {
		let idle = 0;

		while ( state.running ) {
			try {
				const types = Object.keys( runners ).filter( ( type ) => ! runners[ type ].supports || false !== runners[ type ].supports() );

				if ( ! types.length ) {
					status( 'unsupported' );
					return;
				}

				const { batch } = await api( 'claim', { types, affinity: state.affinity, worker: state.worker } );

				if ( ! batch ) {
					idle++;
					status( 'idle', { idle } );
					await sleep( Math.min( 30000, 2000 * 2 ** Math.min( idle, 4 ) ) );
					continue;
				}

				idle = 0;
				state.affinity = batch.affinity || '';
				await runBatch( batch );
			} catch ( error ) {
				status( 'error', { message: message( error ) } );
				await sleep( 10000 );
			}
		}

		status( 'stopped' );
	}

	/** Runs a leased batch. */
	async function runBatch( batch ) {
		const controller = new AbortController();
		const runner = runners[ batch.type ];
		const results = [];
		const settled = new Set(); // Units given back or submitted: nothing left to release.
		let lost = false;

		state.current = controller;

		// The page gives these back if it goes away before the batch ends (see `startWorkers()` in host.js).
		post( 'lease', { lease: batch.lease, units: batch.units.map( ( unit ) => ( { unit_id: unit.id, epoch: unit.epoch } ) ) } );

		// Renew at a third of the lease duration, with the progress of the units in this batch.
		const renew = setInterval( async () => {
			const progress = {};
			for ( const unit of batch.units ) {
				if ( state.progress.has( unit.id ) ) {
					progress[ unit.id ] = state.progress.get( unit.id );
				}
			}
			try {
				await api( 'renew', { lease: batch.lease, progress, worker: { id: state.worker.id } } );
			} catch ( error ) {
				if ( 'ww_stale_lease' === error.code ) {
					lost = true;
					controller.abort();
					status( 'lease-lost', { task: batch.type } );
				}
			}
		}, Math.max( 5, batch.ttl / 3 ) * 1000 );

		const giveBack = ( unit, error ) => {
			settled.add( unit.id );
			return api( 'fail', {
				lease: batch.lease,
				unit_id: unit.id,
				epoch: unit.epoch,
				error: String( ( error && error.message ) || error ).slice( 0, 500 ),
				retry: ! ( error && false === error.retry ),
			} ).catch( () => {} );
		};

		try {
			let engine;

			try {
				status( 'preparing', { task: batch.type } );
				engine = await getEngine( batch.type );
			} catch ( error ) {
				for ( const unit of batch.units ) {
					await giveBack( unit, error );
				}
				throw error;
			}

			const accept = ( unit, result ) => results.push( { unit_id: unit.id, epoch: unit.epoch, completion_id: crypto.randomUUID(), result: undefined === result ? null : result } );

			if ( runner.runBatch ) {
				// The whole batch in one call; progress is shared by its units.
				status( 'working', { task: batch.type, count: batch.units.length } );

				let outcomes;
				try {
					outcomes = await runner.runBatch( {
						...env( batch.type ),
						units: batch.units.map( ( unit ) => ( { input: unit.input, unit: { id: unit.id, key: unit.key, jobId: unit.job_id }, attempt: unit.attempt || 1 } ) ),
						engine,
						signal: controller.signal,
						progress( fraction, text = '' ) {
							const value = Math.max( 0, Math.min( 1, Number( fraction ) || 0 ) );
							batch.units.forEach( ( unit ) => state.progress.set( unit.id, value ) );
							status( 'progress', { task: batch.type, fraction: value, text } );
						},
					} );
				} catch ( error ) {
					if ( lost || ! state.running ) {
						return; // Stopped: released below. Lost: another worker has them.
					}
					if ( isEngineCrash( error ) ) {
						state.engines.delete( batch.type );
					}
					for ( const unit of batch.units ) {
						await giveBack( unit, error );
					}
					throw error;
				}

				if ( ! Array.isArray( outcomes ) || outcomes.length !== batch.units.length ) {
					throw new Error( `runBatch() of "${ batch.type }" must return one outcome per unit.` );
				}

				for ( let i = 0; i < batch.units.length; i++ ) {
					const outcome = outcomes[ i ] || {};
					if ( 'error' in outcome && outcome.error ) {
						const error = new Error( String( outcome.error ) );
						error.retry = false !== outcome.retry;
						await giveBack( batch.units[ i ], error );
						status( 'unit-failed', { task: batch.type, unit: batch.units[ i ].key, message: error.message } );
					} else {
						accept( batch.units[ i ], outcome.result );
					}
				}
			} else {
				for ( let i = 0; i < batch.units.length; i++ ) {
					const unit = batch.units[ i ];

					if ( controller.signal.aborted ) {
						break;
					}

					status( 'working', { task: batch.type, unit: unit.key, index: i, count: batch.units.length } );

					try {
						accept( unit, await runner.run( makeContext( batch, unit, engine, controller.signal ) ) );
					} catch ( error ) {
						if ( lost || ! state.running ) {
							break; // Stopped: released below. Lost: another worker has it.
						}

						if ( isEngineCrash( error ) ) {
							state.engines.delete( batch.type );
							engine = await getEngine( batch.type ).catch( () => null );
						}

						await giveBack( unit, error );
						status( 'unit-failed', { task: batch.type, unit: unit.key, message: message( error ) } );

						if ( ! engine ) {
							break;
						}
					}
				}
			}

			if ( results.length && ! lost ) {
				const receipts = await submit( batch.lease, results );
				results.forEach( ( item ) => settled.add( item.unit_id ) );
				status( 'completed', { task: batch.type, accepted: receipts.filter( ( r ) => r.accepted ).length, rejected: receipts.filter( ( r ) => ! r.accepted ).length } );
			}
		} finally {
			clearInterval( renew );

			// Stopped mid-batch: give the unfinished units back now rather than at lease expiry (the attempt is
			// refunded). A worker that keeps running keeps its old behavior: what it did not run expires.
			const unfinished = batch.units.filter( ( unit ) => ! settled.has( unit.id ) );
			if ( ! state.running && ! lost && unfinished.length ) {
				await api( 'release', { lease: batch.lease, units: unfinished.map( ( unit ) => ( { unit_id: unit.id, epoch: unit.epoch } ) ) } ).catch( () => {} );
			}

			post( 'lease', { lease: null, units: [] } );
			state.current = null;
			for ( const unit of batch.units ) {
				state.progress.delete( unit.id );
			}
		}
	}

	/** Submits results, retrying network and server errors (a completion ID makes it safe). */
	async function submit( lease, results ) {
		for ( let attempt = 0; ; attempt++ ) {
			try {
				const { receipts } = await api( 'complete', { lease, results } );
				return receipts || [];
			} catch ( error ) {
				if ( ( error.status && error.status < 500 ) || attempt >= 3 ) {
					throw error;
				}
				await sleep( 2000 * ( attempt + 1 ) );
			}
		}
	}

	/** Builds the context of a unit for its runner. */
	function makeContext( batch, unit, engine, signal ) {
		let sequence = unit.checkpoint_seq || 0;

		return {
			...env( batch.type ),
			input: unit.input,
			resume: unit.checkpoint,
			artifacts: unit.artifacts || [],
			engine,
			signal,
			unit: { id: unit.id, key: unit.key, jobId: unit.job_id },

			async checkpoint( data ) {
				signal.throwIfAborted();
				const receipt = await api( 'checkpoint', { lease: batch.lease, unit_id: unit.id, epoch: unit.epoch, sequence: sequence + 1, checkpoint: data } );
				sequence = receipt.sequence;
				return receipt;
			},

			async upload( key, data, type = 'application/octet-stream' ) {
				signal.throwIfAborted();
				const blob = data instanceof Blob ? data : new Blob( [ data ], { type } );
				const form = new FormData();
				form.append( 'lease', batch.lease );
				form.append( 'unit_id', String( unit.id ) );
				form.append( 'epoch', String( unit.epoch ) );
				form.append( 'key', key );
				form.append( 'sha256', await sha256( await blob.arrayBuffer() ) );
				form.append( 'file', blob, key.split( '/' ).pop() );
				return api( 'artifact', form );
			},

			progress( fraction, text = '' ) {
				state.progress.set( unit.id, Math.max( 0, Math.min( 1, Number( fraction ) || 0 ) ) );
				status( 'progress', { task: batch.type, unit: unit.key, fraction, text } );
			},

			attempt: unit.attempt || 1,
		};
	}
}
