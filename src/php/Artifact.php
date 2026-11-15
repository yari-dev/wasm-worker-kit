<?php
/**
 * A file uploaded by a browser worker for a unit.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only description of a staged artifact. The file stays in the kit's staging folder until the host
 * plugin's `finalize()` copies or moves it where it belongs; the kit deletes the staging folder of a job
 * when the job is pruned.
 */
final class Artifact {
	/** @var string Artifact key, chosen by the runner (e.g. `output.webp`). */
	public $key;

	/** @var string Absolute path of the staged file. */
	public $path;

	/** @var string MIME type declared by the browser: not trusted. */
	public $mime;

	/** @var int Size in bytes. */
	public $size;

	/** @var string SHA-256 of the content, checked on upload. */
	public $sha256;

	public function __construct( $row ) {
		$this->key    = (string) $row->art_key;
		$this->path   = (string) $row->path;
		$this->mime   = (string) $row->mime;
		$this->size   = (int) $row->size;
		$this->sha256 = (string) $row->sha256;
	}

	/**
	 * Returns the description sent to the browser (no server path).
	 *
	 * @return array
	 */
	public function receipt(): array {
		return array(
			'key'    => $this->key,
			'size'   => $this->size,
			'sha256' => $this->sha256,
		);
	}
}
