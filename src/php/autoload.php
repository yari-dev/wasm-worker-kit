<?php
/**
 * Autoloader of the kit's classes. The vendoring tool rewrites the namespace for each host plugin, so
 * copies of different versions in different plugins never clash.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 === strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			$file = __DIR__ . '/' . substr( $class, strlen( $prefix ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);
