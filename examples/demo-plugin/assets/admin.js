/**
 * Demo page: this tab's workers and the kit's drop-in dashboard.
 */
import { startWorkers } from '../lib/wasm-worker/js/host.js';
import { mountDashboard } from '../lib/wasm-worker/js/dashboard.js';

const config = JSON.parse( document.getElementById( 'wp-script-module-data-ww-demo-admin' ).textContent );
const toggle = document.getElementById( 'ww-demo-toggle' );
const statusLine = document.getElementById( 'ww-demo-status' );

const describe = ( s ) => ( {
	idle: 'Waiting for work',
	preparing: 'Loading the engine…',
	working: `Working on ${ s.unit }`,
	progress: `Working on ${ s.unit }: ${ Math.round( ( s.fraction || 0 ) * 100 ) }%`,
	completed: `${ s.accepted } result(s) accepted`,
	'unit-failed': `A unit failed: ${ s.message }`,
	'lease-lost': 'The lease was lost, the work goes back to the queue',
	'other-tab': 'Another tab of this browser is working',
	unsupported: 'This browser cannot run these tasks',
	error: `Error: ${ s.message }`,
	stopped: 'Stopped',
}[ s.state ] || s.state );

const workers = startWorkers( {
	...config,
	concurrency: 2,
	onStatus( status ) {
		statusLine.textContent = describe( status );
		toggle.textContent = workers.isRunning() ? 'Stop' : 'Start working in this tab';
	},
} );

toggle.addEventListener( 'click', () => ( workers.isRunning() ? workers.stop() : workers.start( true ) ) );

mountDashboard( document.getElementById( 'ww-demo-dashboard' ), config );
