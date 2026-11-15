<?php
/**
 * Staging folder of the files uploaded by the browser workers.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Files are stored under `<base>/<prefix>-<random>/<job>/<unit>/`. The base is the `staging_dir` setting of
 * the kit, ideally a folder outside the web root; by default it is the uploads folder, where the files are
 * protected by layers that do not depend on the web server: an unguessable folder name, unguessable file
 * names without extension (served as plain bytes, never as HTML or SVG), no directory listing, and deny
 * files for Apache (`.htaccess`) and IIS (`web.config`). `Task::finalize()` moves the files where they belong.
 */
final class Storage {
	/** @var string */
	private $prefix;

	/** @var int */
	private $max_bytes;

	/** @var string */
	private $base;

	/** @var bool */
	private $private;

	public function __construct( string $prefix, int $max_bytes, string $base = '', bool $private = false ) {
		$this->prefix    = $prefix;
		$this->max_bytes = $max_bytes;
		$this->base      = untrailingslashit( $base );
		$this->private   = $private;
	}

	/**
	 * Tells whether a folder is inside the public WordPress tree (WordPress, wp-content or uploads). A folder
	 * elsewhere may still be public if the web root is set up differently: this is a safety net, not a proof.
	 */
	public static function is_public( string $path ): bool {
		$path    = trailingslashit( wp_normalize_path( (string) ( realpath( $path ) ?: $path ) ) );
		$uploads = wp_get_upload_dir();

		foreach ( array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ?? '' ) as $public ) {
			$public = trailingslashit( wp_normalize_path( (string) ( realpath( $public ) ?: $public ) ) );
			if ( '/' !== $public && 0 === strpos( $path, $public ) ) {
				return true;
			}
		}

		return false;
	}

	/** Describes the staging folder, for a host plugin's settings or health screen. */
	public function status(): array {
		$base   = '' === $this->base ? (string) ( wp_get_upload_dir()['basedir'] ?? '' ) : $this->base;
		$public = self::is_public( $base );

		return array(
			'path'             => $base,
			'public'           => $public,
			'private_required' => $this->private,
			'ok'               => ! ( $this->private && $public ),
		);
	}

	/** Returns the staging folder, created on first use. */
	public function root() {
		$base = $this->base;

		if ( '' === $base ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				return new \WP_Error( 'ww_storage', (string) $uploads['error'], array( 'status' => 500 ) );
			}
			$base = untrailingslashit( $uploads['basedir'] );
		}

		if ( $this->private && self::is_public( $base ) ) {
			return new \WP_Error( 'ww_staging_public', __( 'This plugin stores private files: set its staging_dir to a folder outside the web root.', 'wasm-worker' ), array( 'status' => 500 ) );
		}

		$secret = substr( hash_hmac( 'sha256', $this->prefix, wp_salt( 'auth' ) ), 0, 16 );
		$root   = $base . '/' . $this->prefix . '-' . $secret;

		if ( ! is_dir( $root ) ) {
			if ( ! wp_mkdir_p( $root ) ) {
				return new \WP_Error( 'ww_storage', __( 'The staging folder could not be created.', 'wasm-worker' ), array( 'status' => 500 ) );
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $root . '/web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
			// phpcs:enable
		}

		return $root;
	}

	/** Moves an uploaded file into the staging folder after checking its size and hash. */
	public function store( int $job_id, int $unit_id, int $epoch, string $key, string $tmp, string $sha256 ) {
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$size = is_file( $tmp ) ? (int) filesize( $tmp ) : -1;
		if ( $size < 0 || $size > $this->max_bytes ) {
			return new \WP_Error( 'ww_artifact_size', __( 'The file is missing or too large.', 'wasm-worker' ), array( 'status' => 413 ) );
		}

		$hash = (string) hash_file( 'sha256', $tmp );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || ! hash_equals( $hash, $sha256 ) ) {
			return new \WP_Error( 'ww_artifact_hash', __( 'The file was damaged during the upload (checksum mismatch).', 'wasm-worker' ), array( 'status' => 422 ) );
		}

		$dir = "$root/$job_id/$unit_id";
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'ww_storage', __( 'The staging folder could not be created.', 'wasm-worker' ), array( 'status' => 500 ) );
		}

		$path = $dir . '/' . sha1( $key ) . '-' . $epoch . '-' . bin2hex( random_bytes( 8 ) );
		$part = "$path.part";

		// Copied, not moved: PHP deletes the uploaded temporary file at the end of the request.
		$moved = copy( $tmp, $part );
		if ( ! $moved || ! rename( $part, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $part );
			return new \WP_Error( 'ww_storage', __( 'The file could not be saved.', 'wasm-worker' ), array( 'status' => 500 ) );
		}

		return array(
			'path'   => $path,
			'size'   => $size,
			'sha256' => $hash,
		);
	}

	/** Deletes one staged file (replaced, or refused at commit time). Only files inside the staging folder. */
	public function delete( string $path ) {
		$root = $this->root();
		if ( '' !== $path && ! is_wp_error( $root ) && 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( $root ) . '/' ) ) {
			wp_delete_file( $path );
		}
	}

	/** Deletes the staged files of a job. */
	public function delete_job( int $job_id ) {
		$root = $this->root();
		if ( ! is_wp_error( $root ) ) {
			self::delete_tree( "$root/$job_id" );
		}
	}

	/** Deletes the whole staging folder (uninstall). */
	public function delete_all() {
		$root = $this->root();
		if ( ! is_wp_error( $root ) ) {
			self::delete_tree( $root );
		}
	}

	/** Deletes a folder recursively. */
	private static function delete_tree( string $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
