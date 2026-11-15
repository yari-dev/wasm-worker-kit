/**
 * Drop-in admin dashboard of a host plugin's queue: metrics by task type, workers, paused types, jobs with
 * their progress and controls, and the failed units of a job.
 *
 *     import { mountDashboard } from './kit/dashboard.js';
 *     mountDashboard( document.getElementById( 'my-dashboard' ), kitDashboardConfig ); // Kit::dashboard_config()
 *
 * Uses the WordPress admin styles; strings can be translated with `options.i18n`.
 *
 * @package WasmWorker
 */

const STRINGS = {
	types: 'Task types',
	workers: 'Workers',
	jobs: 'Jobs',
	waiting: 'waiting',
	running: 'running',
	done1h: 'done (1 h)',
	failed24h: 'failed (24 h)',
	perMinute: 'per minute',
	avg: 'average',
	p95: 'p95',
	paused: 'Paused',
	pause: 'Pause',
	resume: 'Resume',
	cancel: 'Cancel',
	retry: 'Retry failed',
	failures: 'Failed units',
	noJobs: 'No jobs.',
	noWorkers: 'No worker in the last 5 minutes. Workers run in the browsers of the editors with an admin page open.',
	all: 'All states',
	units: '%1$d done, %2$d running, %3$d waiting, %4$d failed',
	skipped: '%1$d skipped',
};

/**
 * Mounts the dashboard.
 *
 * @param {HTMLElement} root    Container.
 * @param {Object}      options `restUrl`, `nonce`, `canManage`, optional `i18n` and `interval` (ms, default 3000).
 * @return {{refresh: Function, destroy: Function}}
 */
export function mountDashboard( root, options ) {
	const t = { ...STRINGS, ...( options.i18n || {} ) };
	const config = { interval: 3000, ...options };
	const state = { filter: '', open: new Set(), timer: null, last: {} };

	// A section is rebuilt only when its data changed, so a refresh never swallows a click or a hover.
	const changed = ( section, data ) => {
		const json = JSON.stringify( data );
		if ( state.last[ section ] === json ) {
			return false;
		}
		state.last[ section ] = json;
		return true;
	};

	const el = ( tag, props = {}, ...children ) => {
		const node = document.createElement( tag );
		for ( const [ key, value ] of Object.entries( props ) ) {
			if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( null !== value && false !== value && undefined !== value ) {
				node.setAttribute( 'class' === key ? 'class' : key, value );
			}
		}
		node.append( ...children.filter( ( child ) => null !== child && undefined !== child ) );
		return node;
	};

	const format = ( text, ...args ) => text.replace( /%(\d+)\$d/g, ( m, n ) => String( args[ n - 1 ] ) );
	const ms = ( value ) => ( value >= 1000 ? `${ ( value / 1000 ).toFixed( 1 ) } s` : `${ value } ms` );

	const api = async ( path, body ) => {
		const response = await fetch( config.restUrl + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: body ? { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' } : { 'X-WP-Nonce': config.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const data = await response.json().catch( () => null );
		if ( ! response.ok ) {
			throw new Error( ( data && data.message ) || `HTTP ${ response.status }` );
		}
		return data;
	};

	const style = el( 'style', {
		text: '.ww-dash-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin:8px 0 20px}' +
			'.ww-dash-card{background:#fff;border:1px solid #c3c4c7;padding:10px 12px}.ww-dash-card h3{margin:0 0 6px;font-size:14px;display:flex;justify-content:space-between;align-items:center}' +
			'.ww-dash-card dl{display:grid;grid-template-columns:auto auto;gap:2px 12px;margin:0}.ww-dash-card dd{margin:0;text-align:right;font-variant-numeric:tabular-nums}' +
			'.ww-dash-bar{background:#f0f0f1;height:8px;border-radius:3px;overflow:hidden;min-width:120px}.ww-dash-bar span{display:block;height:100%;background:#2271b1}' +
			'.ww-dash-jobs td{vertical-align:top}.ww-dash-jobs .button{margin:0 4px 4px 0}.ww-dash-error{color:#b32d2e}',
	} );

	const sections = {
		types: el( 'div' ),
		workers: el( 'div' ),
		jobs: el( 'div' ),
	};

	root.replaceChildren(
		style,
		el( 'h2', { text: t.types } ),
		sections.types,
		config.canManage ? el( 'h2', { text: t.workers } ) : null,
		config.canManage ? sections.workers : null,
		el( 'h2', { text: t.jobs } ),
		sections.jobs
	);

	const renderTypes = ( metrics, types ) => {
		const names = [ ...new Set( [ ...Object.keys( types || {} ), ...Object.keys( metrics.types || {} ) ] ) ].sort();
		sections.types.replaceChildren(
			el(
				'div',
				{ class: 'ww-dash-cards' },
				...names.map( ( name ) => {
					const m = metrics.types[ name ] || { units: {} };
					const paused = types && types[ name ];
					const toggle = config.canManage && types
						? el( 'button', {
							type: 'button',
							class: 'button button-small',
							text: paused ? t.resume : t.pause,
							onclick: async () => {
								await api( `types/${ name }`, { action: paused ? 'resume' : 'pause' } );
								refresh();
							},
						} )
						: null;
					return el(
						'div',
						{ class: 'ww-dash-card' },
						el( 'h3', {}, el( 'span', { text: name + ( paused ? ` (${ t.paused })` : '' ) } ), toggle ),
						el(
							'dl',
							{},
							...[
								[ t.waiting, ( m.units && m.units.pending ) || 0 ],
								[ t.running, ( m.units && m.units.leased ) || 0 ],
								[ t.done1h, m.completed_1h || 0 ],
								[ t.failed24h, m.failed_24h || 0 ],
								[ t.perMinute, m.per_minute || 0 ],
								[ t.avg, ms( m.avg_ms_24h || 0 ) ],
								[ t.p95, ms( m.p95_ms_24h || 0 ) ],
							].flatMap( ( [ label, value ] ) => [ el( 'dt', { text: label } ), el( 'dd', { text: String( value ) } ) ] )
						)
					);
				} )
			)
		);
	};

	const renderWorkers = ( workers ) => {
		if ( ! workers.length ) {
			sections.workers.replaceChildren( el( 'p', { text: t.noWorkers } ) );
			return;
		}
		sections.workers.replaceChildren(
			el(
				'table',
				{ class: 'widefat striped' },
				el(
					'tbody',
					{},
					...workers.map( ( w ) => el(
						'tr',
						{},
						el( 'td', { text: w.name } ),
						el( 'td', { text: `${ w.units } unit(s)` } ),
						el( 'td', { text: ( w.types || [] ).join( ', ' ) } ),
						el( 'td', { text: Object.entries( w.capabilities || {} ).filter( ( [ , v ] ) => v ).map( ( [ k, v ] ) => ( true === v ? k : `${ k } ${ v }` ) ).join( ', ' ) } ),
						el( 'td', { text: new Date( w.seen * 1000 ).toLocaleTimeString() } )
					) )
				)
			)
		);
	};

	const renderJobs = ( jobs ) => {
		const filter = el(
			'select',
			{ onchange: ( event ) => { state.filter = event.target.value; refresh(); } },
			...[ '', 'active', 'waiting', 'paused', 'finalizing', 'completed', 'failed', 'cancelled' ].map( ( s ) => {
				const option = el( 'option', { value: s, text: s || t.all } );
				option.selected = s === state.filter;
				return option;
			} )
		);

		if ( ! jobs.length ) {
			sections.jobs.replaceChildren( filter, el( 'p', { text: t.noJobs } ) );
			return;
		}

		const rows = jobs.map( ( job ) => {
			const actions = [];
			if ( 'active' === job.state ) {
				actions.push( [ 'pause', t.pause ] );
			}
			if ( 'paused' === job.state ) {
				actions.push( [ 'resume', t.resume ] );
			}
			if ( [ 'active', 'paused', 'waiting' ].includes( job.state ) ) {
				actions.push( [ 'cancel', t.cancel ] );
			}
			if ( job.units.failed && [ 'failed', 'completed' ].includes( job.state ) ) {
				actions.push( [ 'retry', t.retry ] );
			}

			const details = el( 'div' );
			if ( state.open.has( job.id ) ) {
				api( `jobs/${ job.id }/units?state=failed&limit=100` ).then( ( units ) => details.replaceChildren(
					el( 'ul', {}, ...units.map( ( u ) => el( 'li', {}, el( 'code', { text: u.key } ), ` ${ u.attempts }× `, el( 'span', { class: 'ww-dash-error', text: u.error || '' } ) ) ) )
				) ).catch( () => {} );
			}

			return el(
				'tr',
				{},
				el( 'td', {}, el( 'strong', { text: `#${ job.id } ${ job.type }` } ), el( 'br' ), el( 'span', { class: 'description', text: `${ job.state }${ job.priority ? ` · priority ${ job.priority }` : '' }${ job.run_at ? ` · ${ new Date( job.run_at ).toLocaleString() }` : '' }` } ) ),
				el(
					'td',
					{},
					el( 'div', { class: 'ww-dash-bar' }, el( 'span', { style: `width:${ Math.round( job.progress * 100 ) }%` } ) ),
					el( 'span', { class: 'description', text: `${ Math.round( job.progress * 100 ) }% · ${ format( t.units, job.units.completed, job.units.leased, job.units.pending, job.units.failed ) }${ job.units.skipped ? `, ${ format( t.skipped, job.units.skipped ) }` : '' }` } ),
					job.error ? el( 'div', { class: 'ww-dash-error', text: job.error } ) : null
				),
				el(
					'td',
					{},
					...actions.map( ( [ action, label ] ) => el( 'button', {
						type: 'button',
						class: 'button button-small',
						text: label,
						onclick: async () => {
							await api( `jobs/${ job.id }`, { action } );
							refresh();
						},
					} ) ),
					job.units.failed ? el( 'button', {
						type: 'button',
						class: 'button-link',
						text: `${ t.failures } (${ job.units.failed })`,
						onclick: () => {
							state.open.has( job.id ) ? state.open.delete( job.id ) : state.open.add( job.id );
							refresh();
						},
					} ) : null,
					details
				)
			);
		} );

		sections.jobs.replaceChildren( filter, el( 'table', { class: 'widefat striped ww-dash-jobs' }, el( 'tbody', {}, ...rows ) ) );
	};

	const refresh = async () => {
		clearTimeout( state.timer );
		try {
			const [ jobs, metrics, workers, types ] = await Promise.all( [
				api( `jobs?limit=30${ state.filter ? `&state=${ state.filter }` : '' }` ),
				config.canManage ? api( 'metrics' ) : Promise.resolve( { types: {} } ),
				config.canManage ? api( 'workers' ) : Promise.resolve( [] ),
				config.canManage ? api( 'types' ) : Promise.resolve( null ),
			] );
			if ( changed( 'types', [ metrics, types ] ) ) {
				renderTypes( metrics, types );
			}
			if ( config.canManage && changed( 'workers', workers ) ) {
				renderWorkers( workers );
			}
			if ( changed( 'jobs', [ jobs, state.filter, [ ...state.open ] ] ) ) {
				renderJobs( jobs );
			}
		} catch ( error ) {
			state.last.jobs = null;
			sections.jobs.replaceChildren( el( 'p', { class: 'ww-dash-error', text: error.message } ) );
		}
		state.timer = setTimeout( refresh, document.hidden ? config.interval * 5 : config.interval );
	};

	refresh();

	return {
		refresh,
		destroy() {
			clearTimeout( state.timer );
			root.replaceChildren();
		},
	};
}
