/**
 * Every other admin page: help with the queue in the background, one tab per browser.
 */
import { watchQueue } from '../lib/wasm-worker/js/host.js';

const config = JSON.parse( document.getElementById( 'wp-script-module-data-ww-demo-background' ).textContent );

watchQueue( {
	...config,
	onStatus( status ) {
		if ( 'error' === status.state ) {
			console.warn( 'WASM Worker Demo:', status.message ); // eslint-disable-line no-console
		}
	},
} );
