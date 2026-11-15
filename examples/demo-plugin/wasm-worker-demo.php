<?php
/**
 * Plugin Name: WASM Worker Kit Demo
 * Description: Example host plugin of the WASM worker kit: two task types run by the administrators' browsers.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * @package WwDemo
 */

namespace WwDemo;

defined( 'ABSPATH' ) || exit;

// The kit, copied by `npm run demo` (tools/vendor.mjs) under this plugin's namespace. Not committed.
if ( ! is_file( __DIR__ . '/lib/wasm-worker/php/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>WASM Worker Kit Demo: run <code>npm run demo</code> in the kit folder first, it copies the kit into the demo.</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/lib/wasm-worker/php/autoload.php';
require_once __DIR__ . '/includes/class-sum-task.php';
require_once __DIR__ . '/includes/class-grayscale-task.php';

/**
 * Returns the kit instance of this plugin.
 *
 * @return Worker\Kit
 */
function kit(): Worker\Kit {
	static $kit = null;

	if ( null === $kit ) {
		$kit = new Worker\Kit( array( 'id' => 'ww-demo' ) );
		$kit->register( new Sum_Task() );
		$kit->register( new Grayscale_Task() );
	}

	return $kit;
}

kit()->boot();

register_activation_hook( __FILE__, function () {
	kit()->install();
} );

register_deactivation_hook( __FILE__, function () {
	kit()->deactivate();
} );

/* Admin page: Tools > WASM Worker Demo. */

add_action( 'admin_menu', function () {
	add_management_page( 'WASM Worker Demo', 'WASM Worker Demo', 'manage_options', 'ww-demo', __NAMESPACE__ . '\\render_page' );
} );

/**
 * Displays the page.
 *
 * @return void
 */
function render_page() {
	?>
	<div class="wrap">
		<h1>WASM Worker Demo</h1>
		<p>Jobs are split into units, which the administrators' browsers run in a Web Worker. Close the tab in the middle of a job: another tab (or this one, later) resumes from the last checkpoint.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="ww_demo_create" />
			<input type="hidden" name="task" value="sum" />
			<?php wp_nonce_field( 'ww_demo_create' ); ?>
			<?php submit_button( 'Queue a sum job (WASM, checkpoints)', 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="ww_demo_create" />
			<input type="hidden" name="task" value="grayscale" />
			<?php wp_nonce_field( 'ww_demo_create' ); ?>
			<?php submit_button( 'Queue grayscale copies of the last 3 images (file uploads)', 'secondary', 'submit', false ); ?>
		</form>

		<h2>This browser</h2>
		<p><button type="button" class="button button-primary" id="ww-demo-toggle">Start working in this tab</button> <span id="ww-demo-status">Stopped</span></p>

		<div id="ww-demo-dashboard"></div>
	</div>
	<?php
}

add_action( 'admin_post_ww_demo_create', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'ww_demo_create' );

	if ( isset( $_POST['task'] ) && 'grayscale' === $_POST['task'] ) {
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'post_status'    => 'inherit',
				'posts_per_page' => 3,
				'fields'         => 'ids',
			)
		);
		$input = array( 'ids' => array_map( 'intval', $ids ) );
		$type  = 'grayscale';
	} else {
		$input = array( 'pairs' => 300 );
		$type  = 'sum';
	}

	$job = kit()->enqueue( $type, $input );
	if ( is_wp_error( $job ) ) {
		wp_die( esc_html( $job->get_error_message() ) );
	}

	wp_safe_redirect( admin_url( 'tools.php?page=ww-demo' ) );
	exit;
} );

/* Scripts: the page controls the workers, the other admin pages help in the background. */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! kit()->can_work() ) {
		return;
	}

	$module = 'tools_page_ww-demo' === $hook ? 'ww-demo-admin' : 'ww-demo-background';
	$file   = 'ww-demo-admin' === $module ? 'admin.js' : 'background.js';

	wp_enqueue_script_module( $module, plugins_url( 'assets/' . $file, __FILE__ ), array(), '1.0.0' );

	add_filter(
		"script_module_data_{$module}",
		function () {
			return kit()->dashboard_config() + array( 'workerUrl' => plugins_url( 'assets/worker.js', __FILE__ ) );
		}
	);
} );
