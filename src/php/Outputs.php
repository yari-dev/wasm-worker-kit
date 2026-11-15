<?php
/**
 * What `Task::finalize()` receives: the results of a job's units, read in chunks.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Iterates over the unit results without loading a large job in memory at once.
 */
final class Outputs {
	/**
	 * Rows per query.
	 */
	const CHUNK = 500;

	/** @var Schema */
	private $schema;

	/** @var int */
	private $job_id;

	public function __construct( Schema $schema, int $job_id ) {
		$this->schema = $schema;
		$this->job_id = $job_id;
	}

	/**
	 * Yields the results of the completed units, in plan order.
	 *
	 * @return \Generator<string, mixed> Unit key => result.
	 */
	public function results(): \Generator {
		foreach ( $this->rows( 'completed', 'unit_key, result' ) as $row ) {
			yield $row->unit_key => json_decode( (string) $row->result, true );
		}
	}

	/**
	 * Iterates over the completed units with their input, keyed by unit key: `[ 'input' => ..., 'result' => ... ]`.
	 * For a `finalize()` that needs the input next to the result.
	 *
	 * @return \Generator
	 */
	public function completed(): \Generator {
		foreach ( $this->rows( 'completed', 'unit_key, input, result' ) as $row ) {
			yield $row->unit_key => array(
				'input'  => json_decode( (string) $row->input, true ),
				'result' => json_decode( (string) $row->result, true ),
			);
		}
	}

	/**
	 * Returns the failed units and their last error.
	 *
	 * @return string[] Unit key => error.
	 */
	public function failures(): array {
		$out = array();
		foreach ( $this->rows( 'failed', 'unit_key, error' ) as $row ) {
			$out[ $row->unit_key ] = (string) $row->error;
		}
		return $out;
	}

	/**
	 * Returns the files uploaded with the accepted result of a unit.
	 *
	 * @param string $unit_key Unit key.
	 * @return Artifact[] By artifact key.
	 */
	public function artifacts( string $unit_key ): array {
		global $wpdb;

		// Artifacts kept by a checkpoint, or uploaded under the lease whose result was accepted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.* FROM {$this->schema->artifacts} a JOIN {$this->schema->units} u ON u.id = a.unit_id AND ( a.kept = 1 OR a.lease_epoch = u.lease_epoch ) WHERE u.job_id = %d AND u.key_hash = %s AND u.state = 'completed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->job_id,
				sha1( $unit_key )
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$artifact               = new Artifact( $row );
			$out[ $artifact->key ] = $artifact;
		}
		return $out;
	}

	/**
	 * Returns the data the plan stored for the job (`data` in the return value of `Task::plan()`).
	 *
	 * @return mixed Null when none.
	 */
	public function data() {
		global $wpdb;

		$json = $this->job_id ? $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$this->schema->jobs} WHERE id = %d", $this->job_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $json ? null : json_decode( (string) $json, true );
	}

	/**
	 * Counts the units by state.
	 *
	 * @return int[] Keys `completed`, `failed`.
	 */
	public function counts(): array {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS n FROM {$this->schema->units} WHERE job_id = %d GROUP BY state", $this->job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array(
			'completed' => 0,
			'failed'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ $row->state ] = (int) $row->n;
		}
		return $counts;
	}

	/** Reads unit rows of a state in chunks. */
	private function rows( string $state, string $columns ): \Generator {
		global $wpdb;

		$last = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, $columns FROM {$this->schema->units} WHERE job_id = %d AND state = %s AND id > %d ORDER BY id ASC LIMIT %d", $this->job_id, $state, $last, self::CHUNK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$last = (int) $row->id;
				yield $row;
			}
		} while ( count( $rows ) === self::CHUNK );
	}
}
