<?php
/**
 * Removes the demo's queue, tables and staged files.
 *
 * @package WwDemo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/lib/wasm-worker/php/autoload.php';

( new WwDemo\Worker\Kit( array( 'id' => 'ww-demo' ) ) )->uninstall();
