<?php
/**
 * Demo task: sums of integer pairs, computed by a tiny WebAssembly module.
 *
 * Shows planning in steps (a cursor), batches of small units, and checkpoints.
 *
 * @package WwDemo
 */

namespace WwDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Units of 16 pairs, planned 5 units per step, leased 4 units per batch.
 */
final class Sum_Task extends Worker\Task {
	/**
	 * Pairs per unit.
	 */
	const PAIRS_PER_UNIT = 16;

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'sum';
	}

	/**
	 * {@inheritDoc}
	 */
	public function options(): array {
		return array(
			'batch_units'   => 4,
			'lease_seconds' => 60,
		);
	}

	/**
	 * Plans 5 units per step, to show how a large job is planned without a long request.
	 *
	 * @param mixed          $input   `pairs`: number of pairs.
	 * @param string|null    $cursor  Index of the next unit.
	 * @param Worker\Outputs $outputs Unused.
	 * @return array
	 */
	public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
		$total = max( 1, min( 100000, (int) ( $input['pairs'] ?? 0 ) ) );
		$units = (int) ceil( $total / self::PAIRS_PER_UNIT );
		$first = (int) $cursor;
		$last  = min( $units, $first + 5 );
		$out   = array();

		for ( $u = $first; $u < $last; $u++ ) {
			$pairs = array();
			for ( $i = $u * self::PAIRS_PER_UNIT; $i < min( $total, ( $u + 1 ) * self::PAIRS_PER_UNIT ); $i++ ) {
				$pairs[] = array( $i, $i * 2 );
			}
			$out[] = array(
				'key'   => "unit-$u",
				'input' => array( 'pairs' => $pairs ),
			);
		}

		return array(
			'units' => $out,
			'next'  => $last < $units ? (string) $last : null,
		);
	}

	/**
	 * Checks every sum (cheap here; a real task checks what it can).
	 *
	 * @param mixed $unit_input Unit input.
	 * @param mixed $result     Sums.
	 * @param array $artifacts  Unused.
	 * @param mixed $job_input  Unused.
	 * @return true|\WP_Error
	 */
	public function validate( $unit_input, $result, array $artifacts, $job_input ) {
		if ( ! is_array( $result ) || count( $result ) !== count( $unit_input['pairs'] ) ) {
			return new \WP_Error( 'demo_result', 'Wrong number of sums.' );
		}

		foreach ( $unit_input['pairs'] as $i => $pair ) {
			if ( ( $result[ $i ] ?? null ) !== $pair[0] + $pair[1] ) {
				return new \WP_Error( 'demo_result', "Wrong sum at $i." );
			}
		}

		return true;
	}

	/**
	 * Adds everything up. Idempotent: it only computes a value.
	 *
	 * @param mixed                $job_input Job input.
	 * @param Worker\Outputs $outputs   Results.
	 * @param int                  $job_id    Job ID.
	 * @return array
	 */
	public function finalize( $job_input, Worker\Outputs $outputs, int $job_id ) {
		$sum   = 0;
		$count = 0;

		foreach ( $outputs->results() as $sums ) {
			$sum   += array_sum( $sums );
			$count += count( $sums );
		}

		return array(
			'pairs'  => $count,
			'total'  => $sum,
			'failed' => count( $outputs->failures() ),
		);
	}
}
