<?php
/**
 * Metrics and worker registry of one host plugin's queue.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Schema.

/**
 * Read-only views for dashboards and monitoring, plus the list of the workers seen recently.
 */
final class Monitor {
	/**
	 * A worker not heard of for this long is no longer listed.
	 */
	const WORKER_TTL = 300;

	/** @var Schema */
	private $schema;

	/** @var string */
	private $prefix;

	public function __construct( Schema $schema, string $prefix ) {
		$this->schema = $schema;
		$this->prefix = $prefix;
	}

	/** Returns the metrics by task type. */
	public function metrics( array $registered = array() ): array {
		global $wpdb;

		$types = array_fill_keys( $registered, array() );
		$day   = Queue::now( -DAY_IN_SECONDS );
		$hour  = Queue::now( -HOUR_IN_SECONDS );
		$q15   = Queue::now( -15 * MINUTE_IN_SECONDS );

		foreach ( (array) $wpdb->get_results( "SELECT type, state, COUNT(*) AS n FROM {$this->schema->jobs} GROUP BY type, state" ) as $row ) {
			$types[ $row->type ]['jobs'][ $row->state ] = (int) $row->n;
		}

		foreach ( (array) $wpdb->get_results( "SELECT j.type, u.state, COUNT(*) AS n FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.state NOT IN ('completed','failed','cancelled') GROUP BY j.type, u.state" ) as $row ) {
			$types[ $row->type ]['units'][ $row->state ] = (int) $row->n;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT j.type, SUM(u.state = 'completed' AND u.finished_at >= %s) AS c1h, SUM(u.state = 'completed') AS c24h, SUM(u.state = 'failed') AS f24h, AVG(CASE WHEN u.state = 'completed' THEN u.duration_ms END) AS avg_ms, SUM(u.state = 'completed' AND u.finished_at >= %s) AS c15 FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE u.finished_at >= %s GROUP BY j.type", $hour, $q15, $day ) );

		foreach ( (array) $rows as $row ) {
			$types[ $row->type ] = array_merge(
				$types[ $row->type ] ?? array(),
				array(
					'completed_1h'  => (int) $row->c1h,
					'completed_24h' => (int) $row->c24h,
					'failed_24h'    => (int) $row->f24h,
					'avg_ms_24h'    => (int) round( (float) $row->avg_ms ),
					'p95_ms_24h'    => $this->p95( $row->type, $day ),
					'per_minute'    => round( (int) $row->c15 / 15, 2 ),
				)
			);
		}

		foreach ( $types as $type => $data ) {
			$types[ $type ] = array_merge(
				array(
					'jobs'          => array(),
					'units'         => array(),
					'completed_1h'  => 0,
					'completed_24h' => 0,
					'failed_24h'    => 0,
					'avg_ms_24h'    => 0,
					'p95_ms_24h'    => 0,
					'per_minute'    => 0,
				),
				$data
			);
		}

		ksort( $types );

		return array(
			'types'   => $types,
			'workers' => count( $this->workers() ),
		);
	}

	/** Returns the 95th percentile duration of the units of a type completed since a date. */
	private function p95( string $type, string $since ): int {
		global $wpdb;

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.type = %s AND u.state = 'completed' AND u.finished_at >= %s", $type, $since ) );
		if ( ! $count ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT u.duration_ms FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.type = %s AND u.state = 'completed' AND u.finished_at >= %s ORDER BY u.duration_ms ASC LIMIT 1 OFFSET %d", $type, $since, (int) floor( 0.95 * ( $count - 1 ) ) ) );
	}

	/** Records that a worker is alive. Cheap: written at most every 30 seconds per worker. */
	public function touch( string $worker_id, \WP_User $user, array $info = array() ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $worker_id ) ) {
			return;
		}

		$workers = $this->registry();
		$known   = $workers[ $worker_id ] ?? null;

		if ( $known && time() - (int) $known['seen'] < 30 ) {
			return;
		}

		$caps = array();
		foreach ( (array) ( $info['capabilities'] ?? array() ) as $name => $value ) {
			if ( is_string( $name ) && preg_match( '/^[a-z0-9_]{1,32}$/', $name ) && is_scalar( $value ) ) {
				$caps[ $name ] = is_bool( $value ) || is_int( $value ) || is_float( $value ) ? $value : substr( (string) $value, 0, 64 );
			}
		}

		$workers[ $worker_id ] = array(
			'user'         => $user->ID,
			'name'         => $user->display_name,
			'seen'         => time(),
			'since'        => $known['since'] ?? time(),
			'types'        => array_slice( array_map( 'sanitize_key', array_filter( (array) ( $info['types'] ?? array() ), 'is_string' ) ), 0, 50 ),
			'capabilities' => array_slice( $caps, 0, 20, true ),
			'agent'        => substr( sanitize_text_field( (string) ( $info['agent'] ?? '' ) ), 0, 200 ),
		);

		set_transient( $this->prefix . '_workers', array_slice( $workers, -200, null, true ), self::WORKER_TTL * 2 );
	}

	/** Returns the workers seen in the last 5 minutes, with the units they hold. */
	public function workers(): array {
		global $wpdb;

		$workers = $this->registry();
		$leases  = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT worker_id, COUNT(*) AS n FROM {$this->schema->units} WHERE state = 'leased' AND lease_expires >= %s AND worker_id <> '' GROUP BY worker_id", Queue::now() ) ) as $row ) {
			$leases[ $row->worker_id ] = (int) $row->n;
		}

		foreach ( $workers as $id => $worker ) {
			$workers[ $id ]['units'] = $leases[ $id ] ?? 0;
		}

		return $workers;
	}

	/** Returns the registry without the workers gone quiet. */
	private function registry(): array {
		$workers = get_transient( $this->prefix . '_workers' );
		$workers = is_array( $workers ) ? $workers : array();

		return array_filter(
			$workers,
			function ( $worker ) {
				return is_array( $worker ) && time() - (int) ( $worker['seen'] ?? 0 ) < self::WORKER_TTL;
			}
		);
	}
}
