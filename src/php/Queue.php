<?php
/**
 * The job queue: planning, scheduling, leases, checkpoints, results, dependencies and finalization.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from Schema, fences are prepared.

/**
 * The database is the authority. A browser works on units it leased: a batch of units shares one secret
 * token, each unit has an epoch incremented on every claim, and nothing is written for a unit unless the
 * token, the epoch, the expiry and the job state still match at the moment of the write. Recovery (expired
 * leases, interrupted planning or finalization, dependencies, stages) runs during the workers' requests, so
 * no cron is needed.
 *
 * Scheduling: jobs by priority (higher first), then round-robin between the jobs of the same priority, so a
 * large job does not starve the small ones queued after it; units of a job oldest first.
 */
final class Queue {
	/**
	 * Job states.
	 */
	const ACTIVE     = 'active';
	const WAITING    = 'waiting';    // For its dependencies.
	const PAUSED     = 'paused';
	const FINALIZING = 'finalizing';
	const COMPLETED  = 'completed';
	const FAILED     = 'failed';
	const CANCELLED  = 'cancelled';

	/**
	 * States a job never leaves.
	 */
	const FINAL_STATES = array( 'completed', 'failed', 'cancelled' );

	/**
	 * Seconds after which an interrupted finalization is retried.
	 */
	const FINALIZE_TIMEOUT = 300;

	/**
	 * Maximum size of a unit input, payload, checkpoint or result, in bytes of JSON.
	 */
	const MAX_JSON = 262144;

	/**
	 * Claim limits: jobs considered, units examined in all, units read per page.
	 */
	const CLAIM_JOBS = 50;
	const CLAIM_SCAN = 1000;
	const CLAIM_PAGE = 100;

	/** @var Schema */
	private $schema;

	/** @var Storage */
	private $storage;

	/** @var string */
	private $prefix;

	/** @var string */
	private $hooks;

	/** @var Task[] */
	private $tasks = array();

	public function __construct( Schema $schema, Storage $storage, string $prefix, string $hooks ) {
		$this->schema  = $schema;
		$this->storage = $storage;
		$this->prefix  = $prefix;
		$this->hooks   = $hooks;
	}

	/** Registers a task type. */
	public function register( Task $task ) {
		$type = $task->type();

		if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $type ) || isset( $this->tasks[ $type ] ) ) {
			throw new \InvalidArgumentException( 'Invalid or duplicate task type: ' . esc_html( $type ) );
		}

		$this->tasks[ $type ] = $task;
	}

	/** Returns the registered types. */
	public function types(): array {
		return array_keys( $this->tasks );
	}

	/** Returns an option of a task type, validated. */
	public function option( string $type, string $name ) {
		$defaults = array(
			'lease_seconds'     => 120,
			'max_attempts'      => 3,
			'backoff'           => 10,
			'max_runtime'       => 0,
			'batch_units'       => 1,
			'batch_cost'        => 0,
			'max_leased'        => 0,
			'rate_limit'        => array(),
			'priority'          => 0,
			'on_failure'        => 'continue',
			'finalize_attempts' => 5,
			'keep_artifacts'    => false,
		);
		$options  = isset( $this->tasks[ $type ] ) ? array_merge( $defaults, $this->tasks[ $type ]->options() ) : $defaults;

		switch ( $name ) {
			case 'lease_seconds':
				return max( 20, min( 3600, (int) $options['lease_seconds'] ) );
			case 'max_attempts':
				return max( 1, min( 50, (int) $options['max_attempts'] ) );
			case 'backoff':
			case 'max_runtime':
			case 'batch_cost':
			case 'max_leased':
				return max( 0, (int) $options[ $name ] );
			case 'batch_units':
				return max( 1, min( 500, (int) $options['batch_units'] ) );
			case 'priority':
				return (int) $options['priority'];
			case 'rate_limit':
				$limit = (array) $options['rate_limit'];
				return empty( $limit['units'] ) ? null : array(
					'units'   => max( 1, (int) $limit['units'] ),
					'seconds' => max( 1, (int) ( $limit['seconds'] ?? 60 ) ),
				);
			case 'finalize_attempts':
				return max( 1, min( 50, (int) $options['finalize_attempts'] ) );
			case 'keep_artifacts':
				return ! empty( $options['keep_artifacts'] );
			case 'on_failure':
				return 'fail_job' === $options['on_failure'] ? 'fail_job' : 'continue';
		}

		return null;
	}

	/** Fires an action of this queue, e.g. `my_plugin_worker_job_completed`. */
	private function emit( string $event, ...$args ) {
		do_action( $this->hooks . $event, ...$args );
	}

	/* Task types paused for maintenance. */

	/** Returns the paused task types. */
	public function paused_types(): array {
		$types = get_option( $this->prefix . '_paused_types', array() );
		return is_array( $types ) ? array_values( array_intersect( $this->types(), $types ) ) : array();
	}

	/** Pauses or resumes a task type: no unit of the type is handed out while paused (leased ones finish). */
	public function set_type_paused( string $type, bool $paused ) {
		$types = array_diff( $this->paused_types(), array( $type ) );
		if ( $paused && isset( $this->tasks[ $type ] ) ) {
			$types[] = $type;
		}
		update_option( $this->prefix . '_paused_types', array_values( $types ), true );
	}

	/* Jobs. */

	/**
	 * Creates a job and plans its first step.
	 *
	 * @param string $type  Task type.
	 * @param mixed  $input Job input (JSON serializable).
	 * @param array  $args  {
	 *     @type string $key      Idempotency key: creating a job with the key of an existing job returns that job.
	 *     @type bool   $rerun    With a key: when the existing job is over, create a new one. Default false.
	 *     @type int    $owner    Owner user ID. Default: current user.
	 *     @type int    $priority Priority, higher first. Default: the task type's.
	 *     @type int    $delay    Seconds before the job starts.
	 *     @type int    $run_at   Timestamp before which the job does not start (wins over `delay`).
	 *     @type int[]  $after    Jobs that must complete first. If one of them fails or is cancelled, so is this job.
	 *     @type string $group    Group of jobs (up to 64 characters), e.g. one user request split into many jobs:
	 *                            see `group()` and `control_group()`.
	 * }
	 * @return int|\WP_Error Job ID.
	 */
	public function enqueue( string $type, $input, array $args = array() ) {
		global $wpdb;

		if ( ! isset( $this->tasks[ $type ] ) ) {
			/* translators: %s: task type */
			return new \WP_Error( 'ww_type', sprintf( __( 'Unknown task type: %s', 'wasm-worker' ), $type ) );
		}

		$key  = isset( $args['key'] ) ? (string) $args['key'] : '';
		$hash = '' === $key ? null : hash( 'sha256', $type . "\0" . $key );

		if ( null !== $hash ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, state FROM {$this->schema->jobs} WHERE idem_hash = %s", $hash ) );
			if ( $existing ) {
				if ( empty( $args['rerun'] ) || ! in_array( $existing->state, self::FINAL_STATES, true ) ) {
					return (int) $existing->id;
				}
				$wpdb->update( $this->schema->jobs, array( 'idem_hash' => null ), array( 'id' => (int) $existing->id ) ); // Release the key.
			}
		}

		$input_json = self::encode( $input, 4 * self::MAX_JSON );
		if ( is_wp_error( $input_json ) ) {
			return $input_json;
		}

		$after = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['after'] ?? array() ) ) ) ) );
		$state = self::ACTIVE;
		if ( $after ) {
			$in     = implode( ',', $after );
			$states = $wpdb->get_col( "SELECT state FROM {$this->schema->jobs} WHERE id IN ($in)" );
			if ( count( $states ) !== count( $after ) ) {
				return new \WP_Error( 'ww_after', __( 'A job this one depends on does not exist.', 'wasm-worker' ) );
			}
			if ( array_intersect( $states, array( self::FAILED, self::CANCELLED ) ) ) {
				return new \WP_Error( 'ww_after', __( 'A job this one depends on failed or was cancelled.', 'wasm-worker' ) );
			}
			if ( array_diff( $states, array( self::COMPLETED ) ) ) {
				$state = self::WAITING;
			}
		}

		$run_at = isset( $args['run_at'] ) ? (int) $args['run_at'] : ( isset( $args['delay'] ) ? time() + (int) $args['delay'] : 0 );
		$group  = substr( (string) ( $args['group'] ?? '' ), 0, 64 );

		try {
			$affinity = substr( (string) $this->tasks[ $type ]->affinity( $input ), 0, 64 );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'ww_plan', $error->getMessage() );
		}

		$step = $this->run_plan( $type, $input, null, 0 );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		$now = self::now();
		$wpdb->query( 'START TRANSACTION' );

		$created = $wpdb->insert(
			$this->schema->jobs,
			array(
				'type'        => $type,
				'state'       => $state,
				'priority'    => isset( $args['priority'] ) ? (int) $args['priority'] : $this->option( $type, 'priority' ),
				'planned'     => null === $step['next'] ? 1 : 0,
				'plan_cursor' => $step['next'],
				'plan_wait'   => $step['wait'] ? 1 : 0,
				'depends_on'  => $after ? ',' . implode( ',', $after ) . ',' : null,
				'group_key'   => $group,
				'affinity'    => $affinity,
				'data'        => false === $step['data'] ? null : $step['data'],
				'owner_id'    => isset( $args['owner'] ) ? (int) $args['owner'] : get_current_user_id(),
				'idem_hash'   => $hash,
				'input'       => $input_json,
				'run_at'      => $run_at > time() ? gmdate( 'Y-m-d H:i:s', $run_at ) : null,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		if ( ! $created ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = null === $hash ? null : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->schema->jobs} WHERE idem_hash = %s", $hash ) );
			return $existing ? (int) $existing : new \WP_Error( 'ww_storage', __( 'The job could not be created.', 'wasm-worker' ) );
		}

		$job_id = (int) $wpdb->insert_id;

		if ( ! $this->insert_units( $job_id, $step['units'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ww_storage', __( 'The job units could not be created.', 'wasm-worker' ) );
		}

		$wpdb->query( 'COMMIT' );

		$this->emit( 'job_created', $job_id, $type );
		$this->maybe_finalize( $job_id ); // A job planned with no units is done right away.

		return $job_id;
	}

	/** Calls the planner of a task and checks what it returns. */
	private function run_plan( string $type, $input, ?string $cursor, int $job_id ) {
		try {
			$step = $this->tasks[ $type ]->plan( $input, $cursor, new Outputs( $this->schema, $job_id ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'ww_plan', $error->getMessage() );
		}

		if ( is_wp_error( $step ) ) {
			return $step;
		}

		if ( ! is_array( $step ) || ! isset( $step['units'] ) || ! is_array( $step['units'] ) || count( $step['units'] ) > 5000 ) {
			return new \WP_Error( 'ww_plan', __( 'A plan step must return up to 5000 units and a `next` cursor.', 'wasm-worker' ) );
		}

		$next  = isset( $step['next'] ) && '' !== (string) $step['next'] ? (string) $step['next'] : null;
		$units = array();
		$seen  = array();

		foreach ( $step['units'] as $unit ) {
			$key = is_array( $unit ) && isset( $unit['key'] ) ? (string) $unit['key'] : '';

			if ( '' === $key || strlen( $key ) > 191 || isset( $seen[ $key ] ) || ! array_key_exists( 'input', $unit ) ) {
				return new \WP_Error( 'ww_plan', __( 'Unit keys must be unique, non-empty, at most 191 bytes, and each unit needs an input.', 'wasm-worker' ) );
			}

			$json = self::encode( $unit['input'], self::MAX_JSON );
			if ( is_wp_error( $json ) ) {
				return $json;
			}

			$seen[ $key ] = true;
			$units[]      = array(
				'key'   => $key,
				'input' => $json,
				'cost'  => max( 1, (int) ( $unit['cost'] ?? 1 ) ),
			);
		}

		// Data for the job, kept as is when the step returns none.
		$data = false;
		if ( array_key_exists( 'data', $step ) ) {
			$data = self::encode( $step['data'], 4 * self::MAX_JSON );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}

		return array(
			'units' => $units,
			'next'  => $next,
			'wait'  => null !== $next && ! empty( $step['wait'] ),
			'data'  => $data,
		);
	}

	/** Inserts units, ignoring the keys already in the job (a plan step can run twice). */
	private function insert_units( int $job_id, array $units ): bool {
		global $wpdb;

		$now = self::now();

		foreach ( array_chunk( $units, 200 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $unit ) {
				$values[] = '(%d, %s, %s, %d, %s, %s, %s)';
				array_push( $params, $job_id, $unit['key'], sha1( $unit['key'] ), $unit['cost'], $unit['input'], $now, $now );
			}
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$this->schema->units} (job_id, unit_key, key_hash, cost, input, created_at, updated_at) VALUES " . implode( ', ', $values ), ...$params ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Continues the planning of the jobs not fully planned yet, within a time budget. Jobs waiting for the end
	 * of a stage are planned once all their units are done.
	 */
	public function plan_pending( float $budget = 1.0 ) {
		global $wpdb;

		$start = microtime( true );
		$ids   = $wpdb->get_col( "SELECT j.id FROM {$this->schema->jobs} j WHERE j.planned = 0 AND j.state IN ('active','paused') AND ( j.plan_wait = 0 OR NOT EXISTS ( SELECT 1 FROM {$this->schema->units} u WHERE u.job_id = j.id AND u.state IN ('pending','leased') ) ) ORDER BY j.priority DESC, j.id ASC LIMIT 10" );

		foreach ( array_map( 'intval', (array) $ids ) as $job_id ) {
			while ( microtime( true ) - $start < $budget && $this->plan_step( $job_id ) ) {
				continue;
			}
		}
	}

	/** Plans one step of a job. */
	private function plan_step( int $job_id ): bool {
		global $wpdb;

		// One request plans a job at a time: the others move on (two of them inserting the same units
		// could deadlock, and the work would be done twice).
		$lock = 'ww_' . md5( $this->prefix . '_plan_' . $job_id );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return false;
		}

		try {
			return $this->plan_step_locked( $job_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/** Plans one step of a job, under its planning lock. */
	private function plan_step_locked( int $job_id ): bool {
		global $wpdb;

		$job = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, input, plan_cursor, plan_wait FROM {$this->schema->jobs} WHERE id = %d AND planned = 0", $job_id ) );
		if ( ! $job || ! isset( $this->tasks[ $job->type ] ) ) {
			return false;
		}

		if ( $job->plan_wait && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->schema->units} WHERE job_id = %d AND state IN ('pending','leased') LIMIT 1", $job_id ) ) ) {
			return false; // The current stage is not over.
		}

		$step = $this->run_plan( $job->type, json_decode( $job->input, true ), $job->plan_cursor, $job_id );
		if ( is_wp_error( $step ) ) {
			$this->fail_job( $job_id, $step->get_error_message() );
			return false;
		}

		$wpdb->query( 'START TRANSACTION' );

		if ( ! $this->insert_units( $job_id, $step['units'] ) ) {
			// The cursor must not move past units that were not stored: this step is planned again later.
			$wpdb->query( 'ROLLBACK' );
			$wpdb->update( $this->schema->jobs, array( 'error' => __( 'Planning paused after a database error, it will be retried.', 'wasm-worker' ) ), array( 'id' => $job_id ) );
			return false;
		}

		// Only the request which read this cursor moves it on.
		$set    = 'plan_cursor = %s, planned = %d, plan_wait = %d, error = NULL, updated_at = %s';
		$params = array( (string) $step['next'], null === $step['next'] ? 1 : 0, $step['wait'] ? 1 : 0, self::now() );
		if ( false !== $step['data'] ) {
			$set     .= ', data = %s';
			$params[] = $step['data'];
		}
		array_push( $params, $job_id, $job->plan_cursor );
		$moved = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET $set WHERE id = %d AND planned = 0 AND plan_cursor = %s", ...$params ) );
		$wpdb->query( $moved ? 'COMMIT' : 'ROLLBACK' );

		if ( ! $moved ) {
			return false; // Another request planned this step.
		}

		if ( null === $step['next'] || ( $step['wait'] && $step['units'] ) ) {
			$this->maybe_finalize( $job_id );
			return false;
		}

		return true;
	}

	/* Leases. */

	/** Leases a batch of units of one type to a worker. */
	public function claim( \WP_User $user, array $types, string $worker = '', string $affinity = '' ) {
		global $wpdb;

		$types = array_values( array_diff( array_intersect( $this->types(), $types ), $this->paused_types() ) );
		if ( empty( $types ) ) {
			return null;
		}

		$this->recover();
		$this->plan_pending( 1.0 );

		// Claims of types with limits are serialized per type, so the limits hold with many workers at once.
		$locks = array();
		foreach ( $types as $i => $type ) {
			if ( $this->option( $type, 'max_leased' ) || $this->option( $type, 'rate_limit' ) ) {
				$lock = 'ww_' . md5( $this->prefix . '_claim_' . $type );
				if ( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ) ) {
					$locks[] = $lock;
				} else {
					unset( $types[ $i ] ); // Busy: another claim of this type is running, try again later.
				}
			}
		}

		try {
			return $this->lease_batch( $user, array_values( $types ), $worker, substr( $affinity, 0, 64 ) );
		} finally {
			foreach ( $locks as $lock ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
	}

	/** Leases a batch: the claim itself, under the limit locks. */
	private function lease_batch( \WP_User $user, array $types, string $worker, string $affinity ) {
		global $wpdb;

		// Per-type limits (all browsers together): how many units each type may still start now.
		$room = array();
		foreach ( $types as $type ) {
			$room[ $type ] = $this->room( $type );
		}
		$types = array_keys( array_filter( $room ) );
		if ( empty( $types ) ) {
			return null;
		}

		$now   = self::now();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ready = "( ( u.state = 'pending' AND ( u.available_at IS NULL OR u.available_at <= '$now' ) ) OR ( u.state = 'leased' AND u.lease_expires < '$now' ) )";

		// Jobs by priority, then those with the worker's affinity, then the one served least recently.
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT j.id, j.type, j.affinity, j.input FROM {$this->schema->jobs} j WHERE j.state = 'active' AND j.type IN ($in) AND ( j.run_at IS NULL OR j.run_at <= %s ) AND EXISTS ( SELECT 1 FROM {$this->schema->units} u WHERE u.job_id = j.id AND $ready ) ORDER BY j.priority DESC, j.affinity = %s DESC, j.last_claim IS NOT NULL, j.last_claim ASC, j.id ASC LIMIT %d", ...array_merge( $types, array( $now, $affinity, self::CLAIM_JOBS ) ) ) );

		$token   = bin2hex( random_bytes( 32 ) );
		$hash    = hash( 'sha256', $token );
		$units          = array();
		$cost           = 0;
		$type           = null;
		$batch_affinity = '';
		$scanned        = 0;
		$cursors        = $this->scan_cursors( $user );
		$moved          = false;

		foreach ( (array) $jobs as $job ) {
			if ( $scanned >= self::CLAIM_SCAN ) {
				break;
			}
			// A batch holds one type and one affinity.
			if ( null !== $type && ( $job->type !== $type || $job->affinity !== $batch_affinity ) ) {
				continue;
			}

			$task       = $this->tasks[ $job->type ];
			$job_input  = json_decode( $job->input, true );
			$max_units  = min( $this->option( $job->type, 'batch_units' ), $room[ $job->type ] );
			$max_cost   = $this->option( $job->type, 'batch_cost' );
			$ttl        = $this->option( $job->type, 'lease_seconds' );
			$until      = self::now( $ttl );
			$job_id     = (int) $job->id;
			$start      = (int) ( $cursors[ $job_id ] ?? 0 );
			$after      = $start;
			$wrapped    = 0 === $start;
			$skipped    = 0;
			$claimed_in = 0;

			// Units of this job oldest first; units this user cannot run are skipped, the position is remembered.
			while ( $scanned < self::CLAIM_SCAN ) {
				$page = $wpdb->get_results( $wpdb->prepare( "SELECT u.id, u.cost, u.input FROM {$this->schema->units} u WHERE u.job_id = %d AND $ready AND u.id > %d ORDER BY u.id ASC LIMIT %d", $job_id, $after, self::CLAIM_PAGE ) );

				if ( empty( $page ) ) {
					if ( $wrapped ) {
						break;
					}
					$wrapped = true;
					$after   = 0;
					continue;
				}

				foreach ( $page as $candidate ) {
					if ( $wrapped && $start && (int) $candidate->id > $start ) {
						break 2;
					}

					$after = (int) $candidate->id;
					++$scanned;

					$unit_input = json_decode( $candidate->input, true );
					if ( ! $task->authorize( $user, $unit_input, $job_input ) ) {
						++$skipped;
						continue;
					}

					if ( $max_cost && $units && $cost + (int) $candidate->cost > $max_cost ) {
						break 3;
					}

					$claimed = $this->claim_unit( $task, (int) $candidate->id, $unit_input, $job_input, $hash, $until, $now, $user, $worker );
					if ( $claimed ) {
						$units[] = $claimed;
						$cost   += (int) $candidate->cost;
						$type           = $job->type;
						$batch_affinity = (string) $job->affinity;
						++$claimed_in;
						if ( count( $units ) >= $max_units ) {
							break 2;
						}
					}
				}
			}

			if ( $claimed_in ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET last_claim = %s, started_at = COALESCE( started_at, %s ) WHERE id = %d", $now, $now, $job_id ) );
			}

			// Remember where to continue for this user when many units of this job are not for them.
			if ( $skipped >= self::CLAIM_PAGE || ( ! $claimed_in && $scanned >= self::CLAIM_SCAN ) ) {
				$cursors[ $job_id ] = $claimed_in ? $units[ count( $units ) - $claimed_in ]['id'] - 1 : $after;
				$moved              = true;
			} elseif ( isset( $cursors[ $job_id ] ) ) {
				unset( $cursors[ $job_id ] );
				$moved = true;
			}

			if ( null !== $type && count( $units ) >= $max_units ) {
				break;
			}
		}

		if ( $moved ) {
			$this->save_scan_cursors( $user, $cursors );
		}

		if ( empty( $units ) ) {
			return null;
		}

		return array(
			'lease'    => $token,
			'type'     => $type,
			'affinity' => $batch_affinity,
			'ttl'      => $this->option( $type, 'lease_seconds' ),
			'units'    => $units,
		);
	}

	/** Returns how many units of a type may start now, given its concurrency and rate limits. */
	private function room( string $type ): int {
		global $wpdb;

		$room = PHP_INT_MAX;
		$now  = self::now();

		$max = $this->option( $type, 'max_leased' );
		if ( $max ) {
			$running = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.type = %s AND u.state = 'leased' AND u.lease_expires >= %s", $type, $now ) );
			$room    = min( $room, $max - $running );
		}

		$rate = $this->option( $type, 'rate_limit' );
		if ( $rate ) {
			$started = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.type = %s AND u.leased_at >= %s", $type, self::now( -$rate['seconds'] ) ) );
			$room    = min( $room, $rate['units'] - $started );
		}

		return max( 0, $room );
	}

	/** Returns where the scans of a user continue, by job. */
	private function scan_cursors( \WP_User $user ): array {
		$cursors = get_transient( $this->prefix . '_scan_' . $user->ID );
		return is_array( $cursors ) ? array_map( 'intval', $cursors ) : array();
	}

	/** Saves the scan positions of a user. */
	private function save_scan_cursors( \WP_User $user, array $cursors ) {
		if ( $cursors ) {
			set_transient( $this->prefix . '_scan_' . $user->ID, array_slice( $cursors, -50, null, true ), 10 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( $this->prefix . '_scan_' . $user->ID );
		}
	}

	/** Leases one unit of a batch, atomically, and builds what the browser receives. */
	private function claim_unit( Task $task, int $unit_id, $unit_input, $job_input, string $hash, string $until, string $now, \WP_User $user, string $worker ) {
		global $wpdb;

		// Atomic: only one claimer wins a unit, and the epoch fences the previous holder.
		$won = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id SET u.state = 'leased', u.lease_hash = %s, u.lease_epoch = u.lease_epoch + 1, u.lease_expires = %s, u.leased_at = %s, u.worker_id = %s, u.user_id = %d, u.progress = 0, u.attempts = u.attempts + 1, u.updated_at = %s WHERE u.id = %d AND j.state = 'active' AND ( ( u.state = 'pending' AND ( u.available_at IS NULL OR u.available_at <= %s ) ) OR ( u.state = 'leased' AND u.lease_expires < %s ) )", $hash, $until, $now, substr( $worker, 0, 64 ), $user->ID, $now, $unit_id, $now, $now ) );
		if ( 1 !== (int) $won ) {
			return null;
		}

		$unit = $wpdb->get_row( $wpdb->prepare( "SELECT u.*, j.type FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE u.id = %d", $unit_id ) );

		try {
			$payload = $task->payload( $unit_input, $job_input );
		} catch ( \Throwable $error ) {
			$payload = new \WP_Error( 'ww_payload', $error->getMessage() );
		}

		$payload_json = is_wp_error( $payload ) ? $payload : self::encode( $payload, self::MAX_JSON );
		if ( is_wp_error( $payload_json ) ) {
			// A payload error is a failed attempt: retried with backoff, then the unit fails.
			$this->give_back( $unit, $payload_json->get_error_message(), true );
			return null;
		}

		return array(
			'id'             => (int) $unit->id,
			'job_id'         => (int) $unit->job_id,
			'key'            => $unit->unit_key,
			'epoch'          => (int) $unit->lease_epoch,
			'attempt'        => (int) $unit->attempts,
			'input'          => $payload,
			'checkpoint'     => null === $unit->checkpoint ? null : json_decode( $unit->checkpoint, true ),
			'checkpoint_seq' => (int) $unit->checkpoint_seq,
			'artifacts'      => $this->kept_artifacts( (int) $unit->id ),
		);
	}

	/**
	 * Extends a lease and records progress. Units finished or taken over in the meantime are no longer
	 * covered; units the user may no longer run are given back (attempt refunded); units over their maximum
	 * runtime fail this attempt.
	 */
	public function renew( \WP_User $user, string $token, array $progress = array() ) {
		global $wpdb;

		$hash = self::lease_hash( $token );
		if ( '' === $hash ) {
			return self::stale();
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT u.*, j.type, j.input AS job_input FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE u.lease_hash = %s AND u.state = 'leased' AND u.lease_expires >= %s AND j.state IN ('active','paused')", $hash, self::now() ) );
		$kept = array();

		foreach ( (array) $rows as $row ) {
			$task    = $this->tasks[ $row->type ] ?? null;
			$runtime = $task ? $this->option( $row->type, 'max_runtime' ) : 0;

			if ( ! $task || ! $task->authorize( $user, json_decode( $row->input, true ), json_decode( $row->job_input, true ) ) ) {
				$this->requeue( (int) $row->id, $hash, (int) $row->lease_epoch, __( 'The worker is no longer allowed to run this unit.', 'wasm-worker' ), true );
			} elseif ( $runtime && strtotime( $row->leased_at . ' UTC' ) + $runtime < time() ) {
				$this->give_back( $row, __( 'The unit ran longer than its maximum runtime.', 'wasm-worker' ), true );
			} else {
				$kept[] = (int) $row->id;
			}
		}

		if ( empty( $kept ) ) {
			return self::stale();
		}

		$ttl = $this->option( $rows[0]->type, 'lease_seconds' );
		$ids = implode( ',', $kept );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id SET u.lease_expires = %s WHERE u.id IN ($ids) AND u.lease_hash = %s AND u.state = 'leased' AND u.lease_expires >= %s AND j.state IN ('active','paused')", self::now( $ttl ), $hash, self::now() ) );

		foreach ( $progress as $unit_id => $fraction ) {
			if ( in_array( (int) $unit_id, $kept, true ) && is_numeric( $fraction ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} SET progress = %f WHERE id = %d AND lease_hash = %s", max( 0, min( 1, (float) $fraction ) ), (int) $unit_id, $hash ) );
			}
		}

		return array(
			'units' => $kept,
			'ttl'   => $ttl,
		);
	}

	/** Returns a unit leased under a token and epoch, after checking the user may still work on it. */
	public function leased_unit( \WP_User $user, string $token, int $unit_id, int $epoch ) {
		global $wpdb;

		$hash = self::lease_hash( $token );
		if ( '' === $hash || $unit_id < 1 || $epoch < 1 ) {
			return self::stale();
		}

		$unit = $wpdb->get_row( "SELECT u.*, j.type, j.input AS job_input FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE " . $this->fence( $unit_id, $hash, $epoch ) );
		if ( ! $unit ) {
			return self::stale();
		}

		$task = $this->tasks[ $unit->type ] ?? null;
		if ( ! $task || ! $task->authorize( $user, json_decode( $unit->input, true ), json_decode( $unit->job_input, true ) ) ) {
			return new \WP_Error( 'ww_forbidden', __( 'You may no longer work on this unit.', 'wasm-worker' ), array( 'status' => 403 ) );
		}

		return $unit;
	}

	/**
	 * Records a checkpoint: a point from which another worker can resume. The artifacts uploaded so far
	 * under this lease are kept for the next attempts.
	 */
	public function checkpoint( \WP_User $user, string $token, int $unit_id, int $epoch, int $sequence, $checkpoint ) {
		global $wpdb;

		$unit = $this->leased_unit( $user, $token, $unit_id, $epoch );
		if ( is_wp_error( $unit ) ) {
			return $unit;
		}

		if ( $sequence <= (int) $unit->checkpoint_seq ) {
			return array(
				'sequence'  => (int) $unit->checkpoint_seq,
				'duplicate' => true,
			);
		}

		$json = self::encode( $checkpoint, self::MAX_JSON );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		// The checkpoint and the artifacts it keeps are committed together, under a still valid lease.
		if ( ! $this->begin_fenced( $unit_id, $unit->lease_hash, $epoch ) ) {
			return self::stale();
		}

		$saved = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} SET checkpoint = %s, checkpoint_seq = %d, updated_at = %s WHERE id = %d AND checkpoint_seq < %d", $json, $sequence, self::now(), $unit_id, $sequence ) );
		if ( ! $saved ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'sequence'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT checkpoint_seq FROM {$this->schema->units} WHERE id = %d", $unit_id ) ),
				'duplicate' => true,
			);
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->artifacts} SET kept = 1 WHERE unit_id = %d AND lease_epoch = %d", $unit_id, $epoch ) );
		$wpdb->query( 'COMMIT' );

		return array(
			'sequence'  => $sequence,
			'duplicate' => false,
		);
	}

	/** Records an uploaded artifact for a leased unit. */
	public function attach( \WP_User $user, string $token, int $unit_id, int $epoch, string $key, string $file, string $sha256, string $mime ) {
		global $wpdb;

		$unit = $this->leased_unit( $user, $token, $unit_id, $epoch );
		if ( is_wp_error( $unit ) ) {
			return $unit;
		}

		if ( ! preg_match( '/^[A-Za-z0-9._\/-]{1,191}$/', $key ) || false !== strpos( $key, '..' ) ) {
			return new \WP_Error( 'ww_artifact_key', __( 'Invalid artifact key.', 'wasm-worker' ), array( 'status' => 400 ) );
		}

		// Hashing and moving a large file takes time: the lease is checked again when the receipt is written.
		$stored = $this->storage->store( (int) $unit->job_id, $unit_id, $epoch, $key, $file, strtolower( $sha256 ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( ! $this->begin_fenced( $unit_id, $unit->lease_hash, $epoch ) ) {
			$this->storage->delete( $stored['path'] ); // Too late: another attempt owns the unit now.
			return self::stale();
		}

		$previous = $wpdb->get_var( $wpdb->prepare( "SELECT path FROM {$this->schema->artifacts} WHERE unit_id = %d AND art_key = %s", $unit_id, $key ) );

		$saved = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->schema->artifacts} (job_id, unit_id, lease_epoch, kept, art_key, path, mime, size, sha256, created_at) VALUES (%d, %d, %d, 0, %s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE lease_epoch = VALUES(lease_epoch), kept = 0, path = VALUES(path), mime = VALUES(mime), size = VALUES(size), sha256 = VALUES(sha256), created_at = VALUES(created_at)",
				(int) $unit->job_id,
				$unit_id,
				$epoch,
				$key,
				$stored['path'],
				substr( sanitize_mime_type( $mime ), 0, 100 ),
				$stored['size'],
				$stored['sha256'],
				self::now()
			)
		);

		if ( false === $saved ) {
			$wpdb->query( 'ROLLBACK' );
			$this->storage->delete( $stored['path'] );
			return new \WP_Error( 'ww_storage', __( 'The file could not be recorded.', 'wasm-worker' ), array( 'status' => 500 ) );
		}

		$wpdb->query( 'COMMIT' );

		// The file this upload replaced (same key, earlier upload of this lease or a kept one).
		if ( $previous && $previous !== $stored['path'] ) {
			$this->storage->delete( (string) $previous );
		}

		return array(
			'key'    => $key,
			'size'   => $stored['size'],
			'sha256' => $stored['sha256'],
		);
	}

	/** Returns the artifacts kept by a checkpoint of a unit. */
	private function kept_artifacts( int $unit_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->schema->artifacts} WHERE unit_id = %d AND kept = 1", $unit_id ) );

		return array_map(
			function ( $row ) {
				return ( new Artifact( $row ) )->receipt();
			},
			(array) $rows
		);
	}

	/** Returns the artifacts valid for the current lease of a unit (kept, or uploaded under this epoch). */
	private function lease_artifacts( int $unit_id, int $epoch ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->schema->artifacts} WHERE unit_id = %d AND ( kept = 1 OR lease_epoch = %d )", $unit_id, $epoch ) );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$artifact               = new Artifact( $row );
			$out[ $artifact->key ] = $artifact;
		}

		return $out;
	}

	/**
	 * Accepts the results of leased units. Each result is validated by the task type, and accepted once:
	 * repeating a completion ID returns the same receipt, a stale lease cannot overwrite anything.
	 */
	public function complete( \WP_User $user, string $token, array $results ): array {
		global $wpdb;

		$receipts = array();
		$jobs     = array();

		foreach ( $results as $item ) {
			$unit_id       = (int) ( $item['unit_id'] ?? 0 );
			$completion_id = (string) ( $item['completion_id'] ?? '' );
			$receipt       = array( 'unit_id' => $unit_id );

			if ( ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $completion_id ) || ! array_key_exists( 'result', $item ) ) {
				$receipts[] = $receipt + array(
					'code'  => 'ww_complete',
					'error' => __( 'A completion ID and a result are required.', 'wasm-worker' ),
				);
				continue;
			}

			$completion_hash = hash( 'sha256', $completion_id );
			$done            = $wpdb->get_var( $wpdb->prepare( "SELECT completion_hash FROM {$this->schema->units} WHERE id = %d AND state = 'completed'", $unit_id ) );

			if ( $done && hash_equals( (string) $done, $completion_hash ) ) {
				$receipts[] = $receipt + array(
					'accepted'  => true,
					'duplicate' => true,
				);
				continue;
			}

			$unit = $this->leased_unit( $user, $token, $unit_id, (int) ( $item['epoch'] ?? 0 ) );
			if ( is_wp_error( $unit ) ) {
				$receipts[] = $receipt + array(
					'code'  => $unit->get_error_code(),
					'error' => $unit->get_error_message(),
				);
				continue;
			}

			$json = self::encode( $item['result'], self::MAX_JSON );
			if ( is_wp_error( $json ) ) {
				$receipts[] = $receipt + array(
					'code'  => $json->get_error_code(),
					'error' => $json->get_error_message(),
				);
				continue;
			}

			try {
				$valid = $this->tasks[ $unit->type ]->validate( json_decode( $unit->input, true ), $item['result'], $this->lease_artifacts( $unit_id, (int) $unit->lease_epoch ), json_decode( $unit->job_input, true ) );
			} catch ( \Throwable $error ) {
				$valid = new \WP_Error( 'ww_invalid_result', $error->getMessage() );
			}

			if ( true !== $valid ) {
				// A rejected result counts as a failed attempt.
				$message = is_wp_error( $valid ) ? $valid->get_error_message() : __( 'The result did not pass validation.', 'wasm-worker' );
				$this->give_back( $unit, $message, true );
				$receipts[] = $receipt + array(
					'code'  => 'ww_invalid_result',
					'error' => $message,
				);
				continue;
			}

			$duration = max( 0, (int) round( ( microtime( true ) - (float) strtotime( $unit->leased_at . ' UTC' ) ) * 1000 ) );
			$accepted = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id SET u.state = 'completed', u.result = %s, u.completion_hash = %s, u.lease_hash = NULL, u.lease_expires = NULL, u.error = NULL, u.progress = 1, u.duration_ms = %d, u.finished_at = %s, u.updated_at = %s WHERE ", $json, $completion_hash, min( $duration, 4294967295 ), self::now(), self::now() ) . $this->fence( $unit_id, $unit->lease_hash, (int) $unit->lease_epoch ) );

			if ( 1 !== (int) $accepted ) {
				$stale      = self::stale();
				$receipts[] = $receipt + array(
					'code'  => $stale->get_error_code(),
					'error' => $stale->get_error_message(),
				);
				continue;
			}

			$this->emit( 'unit_completed', $unit_id, (int) $unit->job_id, $unit->type, $unit->unit_key );

			$jobs[ (int) $unit->job_id ] = true;
			$receipts[]                  = $receipt + array(
				'accepted'  => true,
				'duplicate' => false,
			);
		}

		foreach ( array_keys( $jobs ) as $job_id ) {
			$this->maybe_finalize( $job_id );
		}

		return $receipts;
	}

	/**
	 * Gives a unit back on the worker's request: retried later (with backoff), or failed after the last
	 * attempt or for an error retrying cannot fix.
	 */
	public function fail( \WP_User $user, string $token, int $unit_id, int $epoch, string $error, bool $retry = true ) {
		$unit = $this->leased_unit( $user, $token, $unit_id, $epoch );
		if ( is_wp_error( $unit ) ) {
			return $unit;
		}

		$state = $this->give_back( $unit, sanitize_text_field( $error ), $retry );

		return null === $state ? self::stale() : array( 'state' => $state );
	}

	/**
	 * Gives units back without a result because their worker is going away (the page was closed, reloaded or
	 * left, or the worker was stopped): they are claimable again at once, instead of at lease expiry. The
	 * interrupted run is not the unit's fault, so its attempt is refunded and there is no backoff; the error
	 * and the last checkpoint are kept. Each unit is fenced like every other write (token, epoch, unexpired
	 * lease, running job), and only the user who leased it may release it.
	 */
	public function release( \WP_User $user, string $token, array $units ) {
		global $wpdb;

		$hash = self::lease_hash( $token );
		if ( '' === $hash ) {
			return self::stale();
		}

		$released = array();
		foreach ( array_slice( $units, 0, 500 ) as $unit ) {
			$unit_id = is_array( $unit ) ? (int) ( $unit['unit_id'] ?? 0 ) : 0;
			$epoch   = is_array( $unit ) ? (int) ( $unit['epoch'] ?? 0 ) : 0;
			if ( $unit_id < 1 || $epoch < 1 || in_array( $unit_id, $released, true ) ) {
				continue;
			}

			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id SET u.state = 'pending', u.attempts = GREATEST( u.attempts, 1 ) - 1, u.lease_hash = NULL, u.lease_expires = NULL, u.available_at = NULL, u.progress = 0, u.updated_at = %s WHERE u.user_id = %d AND ", self::now(), $user->ID ) . $this->fence( $unit_id, $hash, $epoch ) );
			if ( 1 === (int) $changed ) {
				$released[] = $unit_id;
			}
		}

		return $released ? array( 'released' => $released ) : self::stale();
	}

	/**
	 * Ends the current attempt of a leased unit without a result: back to the queue after a backoff delay,
	 * or failed after the last attempt. Fenced by the unit's lease.
	 */
	private function give_back( $unit, string $error, bool $retry ) {
		global $wpdb;

		$type     = (string) $unit->type;
		$attempts = (int) $unit->attempts;
		$state    = $retry && $attempts < $this->option( $type, 'max_attempts' ) ? 'pending' : 'failed';
		$backoff  = $this->option( $type, 'backoff' );
		$delay    = 'pending' === $state && $backoff ? min( HOUR_IN_SECONDS, $backoff * ( 2 ** max( 0, $attempts - 1 ) ) ) : 0;
		$error    = substr( $error, 0, 500 );

		// Equality conditions only, so $wpdb->update() can write real NULLs; the lease hash and epoch fence it.
		$changed = $wpdb->update(
			$this->schema->units,
			array(
				'state'         => $state,
				'error'         => $error,
				'available_at'  => $delay ? self::now( $delay ) : null,
				'lease_hash'    => null,
				'lease_expires' => null,
				'finished_at'   => 'failed' === $state ? self::now() : null,
				'updated_at'    => self::now(),
			),
			array(
				'id'          => (int) $unit->id,
				'state'       => 'leased',
				'lease_hash'  => (string) $unit->lease_hash,
				'lease_epoch' => (int) $unit->lease_epoch,
			)
		);

		if ( ! $changed ) {
			return null;
		}

		if ( 'failed' === $state ) {
			$this->unit_failed( (int) $unit->job_id, $type, (int) $unit->id, $error );
		}

		return $state;
	}

	/** Applies the failure policy of a task type after a unit failed for good. */
	private function unit_failed( int $job_id, string $type, int $unit_id, string $error ) {
		$this->emit( 'unit_failed', $unit_id, $job_id, $type, $error );

		if ( 'fail_job' === $this->option( $type, 'on_failure' ) ) {
			$this->fail_job( $job_id, $error );
		} else {
			$this->maybe_finalize( $job_id );
		}
	}

	/** Puts a unit back in the queue without counting the attempt as the unit's failure. */
	private function requeue( int $unit_id, string $hash, int $epoch, string $error, bool $refund = false ) {
		global $wpdb;

		$attempts = $refund ? 'GREATEST( attempts, 1 ) - 1' : 'attempts';
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} SET state = 'pending', attempts = $attempts, lease_hash = NULL, lease_expires = NULL, error = %s, updated_at = %s WHERE id = %d AND lease_hash = %s AND lease_epoch = %d", substr( $error, 0, 500 ), self::now(), $unit_id, $hash, $epoch ) );
	}

	/* Recovery, dependencies and finalization. */

	/**
	 * Fails the units whose last lease expired, finalizes the jobs whose finalization was interrupted, plans
	 * the stages that can start, and starts the jobs whose dependencies are done. Runs in the claim requests,
	 * so recovery works without cron.
	 */
	public function recover() {
		global $wpdb;

		$now = self::now();

		// Expired leases on the last attempt: the unit fails (the others are claimable again as they are).
		$silent = __( 'The worker stopped answering too many times.', 'wasm-worker' );
		foreach ( $this->tasks as $type => $task ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT u.id, u.job_id FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.type = %s AND u.state = 'leased' AND u.lease_expires < %s AND u.attempts >= %d LIMIT 50", $type, $now, $this->option( $type, 'max_attempts' ) ) );
			foreach ( (array) $rows as $row ) {
				$failed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} SET state = 'failed', lease_hash = NULL, lease_expires = NULL, error = %s, finished_at = %s, updated_at = %s WHERE id = %d AND state = 'leased' AND lease_expires < %s", $silent, $now, $now, $row->id, $now ) );
				if ( $failed ) {
					$this->unit_failed( (int) $row->job_id, $type, (int) $row->id, $silent );
				}
			}
		}

		// Interrupted finalizations, jobs finished while paused then resumed, stages whose units are all done.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT j.id FROM {$this->schema->jobs} j WHERE ( ( j.state = 'finalizing' AND j.updated_at < %s ) OR ( j.state = 'active' AND ( j.planned = 1 OR j.plan_wait = 1 ) AND NOT EXISTS ( SELECT 1 FROM {$this->schema->units} u WHERE u.job_id = j.id AND u.state IN ('pending','leased') ) ) ) ORDER BY j.id ASC LIMIT 5", self::now( -self::FINALIZE_TIMEOUT ) ) );
		foreach ( array_map( 'intval', (array) $ids ) as $job_id ) {
			$this->maybe_finalize( $job_id );
		}

		// Jobs waiting for dependencies finished in a request that died before releasing them.
		$waiting = $wpdb->get_col( "SELECT id FROM {$this->schema->jobs} WHERE state = 'waiting' ORDER BY id ASC LIMIT 20" );
		foreach ( array_map( 'intval', (array) $waiting ) as $job_id ) {
			$this->check_dependencies( $job_id );
		}
	}

	/**
	 * Finalizes a job when none of its units is waiting: plans the next stage, or applies the outcome. The
	 * finalization is claimed once; when it throws or the request dies, it is retried later, and after
	 * `finalize_attempts` throws the job fails (it would otherwise stay in `finalizing` for ever).
	 */
	public function maybe_finalize( int $job_id ) {
		global $wpdb;

		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} WHERE job_id = %d AND state IN ('pending','leased')", $job_id ) );
		if ( $open ) {
			return;
		}

		$planned = $wpdb->get_row( $wpdb->prepare( "SELECT planned, plan_wait, state FROM {$this->schema->jobs} WHERE id = %d", $job_id ) );
		if ( ! $planned ) {
			return;
		}

		if ( ! $planned->planned ) {
			if ( $planned->plan_wait && self::ACTIVE === $planned->state ) {
				$this->plan_step( $job_id ); // End of a stage: plan the next one.
			}
			return;
		}

		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET state = 'finalizing', updated_at = %s WHERE id = %d AND planned = 1 AND ( state = 'active' OR ( state = 'finalizing' AND updated_at < %s ) )", self::now(), $job_id, self::now( -self::FINALIZE_TIMEOUT ) ) );
		if ( 1 !== (int) $claimed ) {
			return;
		}

		$job  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->schema->jobs} WHERE id = %d", $job_id ) );
		$task = $this->tasks[ $job->type ] ?? null;

		if ( ! $task ) {
			$wpdb->update( $this->schema->jobs, array( 'state' => self::ACTIVE ), array( 'id' => $job_id ) ); // Retried once the type is registered again.
			return;
		}

		// Finalization runs as the job's owner, also from cron or another user's request: it often writes
		// content, which WordPress checks against the current user.
		$previous = get_current_user_id();
		$owner    = (int) $job->owner_id;
		$switch   = $owner && $owner !== $previous && get_userdata( $owner );

		try {
			if ( $switch ) {
				wp_set_current_user( $owner );
			}
			$result = $task->finalize( json_decode( $job->input, true ), new Outputs( $this->schema, $job_id ), $job_id );
		} catch ( \Throwable $error ) {
			// Perhaps a temporary problem: try again later (recover() picks it up after the timeout), but not
			// for ever: a job that never leaves `finalizing` keeps its group open and cannot be retried.
			$message = substr( $error->getMessage(), 0, 500 );
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET finalize_tries = finalize_tries + 1, error = %s, updated_at = %s WHERE id = %d", $message, self::now(), $job_id ) );
			$tries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT finalize_tries FROM {$this->schema->jobs} WHERE id = %d", $job_id ) );
			if ( $tries >= $this->option( $job->type, 'finalize_attempts' ) ) {
				$this->fail_job( $job_id, $message, array( self::FINALIZING ) );
			}
			return;
		} finally {
			if ( $switch ) {
				wp_set_current_user( $previous );
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, $result->get_error_message(), array( self::FINALIZING ) );
			return;
		}

		$json = self::encode( $result, 4 * self::MAX_JSON );
		if ( is_wp_error( $json ) ) {
			$this->fail_job( $job_id, $json->get_error_message(), array( self::FINALIZING ) );
			return;
		}

		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET state = 'completed', finalize_tries = 0, result = %s, error = NULL, finished_at = %s, updated_at = %s WHERE id = %d AND state = 'finalizing'", $json, self::now(), self::now(), $job_id ) );

		if ( $done ) {
			$this->emit( 'job_completed', $job_id, $job->type, $result );
			$this->release_dependents( $job_id );

			// The outputs are applied: the staged files are no longer needed, unless failed units may still be
			// retried (their finalization reads the files of the units that completed).
			$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} WHERE job_id = %d AND state = 'failed'", $job_id ) );
			if ( ! $failed && ! $this->option( $job->type, 'keep_artifacts' ) ) {
				$this->storage->delete_job( $job_id );
				$wpdb->delete( $this->schema->artifacts, array( 'job_id' => $job_id ) );
			}
		}
	}

	/** Fails a job, and the jobs depending on it. */
	private function fail_job( int $job_id, string $error, array $from = array( 'active', 'paused', 'waiting' ) ) {
		global $wpdb;

		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$failed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET state = 'failed', error = %s, finished_at = %s, updated_at = %s WHERE id = %d AND state IN ($in)", ...array_merge( array( substr( $error, 0, 500 ), self::now(), self::now(), $job_id ), $from ) ) );

		if ( $failed ) {
			$this->emit( 'job_failed', $job_id, (string) $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$this->schema->jobs} WHERE id = %d", $job_id ) ), $error );
			$this->release_dependents( $job_id );
		}
	}

	/** Re-evaluates the jobs waiting for a job that just ended. */
	private function release_dependents( int $job_id ) {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->schema->jobs} WHERE state = 'waiting' AND depends_on LIKE %s", '%,' . $job_id . ',%' ) );
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$this->check_dependencies( $id );
		}
	}

	/** Starts a waiting job when its dependencies completed, fails it when one failed or was cancelled. */
	private function check_dependencies( int $job_id ) {
		global $wpdb;

		$deps = (string) $wpdb->get_var( $wpdb->prepare( "SELECT depends_on FROM {$this->schema->jobs} WHERE id = %d AND state = 'waiting'", $job_id ) );
		$ids  = array_filter( array_map( 'intval', explode( ',', $deps ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$states = (array) $wpdb->get_col( "SELECT state FROM {$this->schema->jobs} WHERE id IN (" . implode( ',', $ids ) . ')' );

		if ( count( $states ) < count( $ids ) || array_intersect( $states, array( self::FAILED, self::CANCELLED ) ) ) {
			$this->fail_job( $job_id, __( 'A job this one depends on failed or was cancelled.', 'wasm-worker' ), array( self::WAITING ) );
		} elseif ( ! array_diff( $states, array( self::COMPLETED ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->jobs} SET state = 'active', updated_at = %s WHERE id = %d AND state = 'waiting'", self::now(), $job_id ) );
			$this->maybe_finalize( $job_id );
		}
	}

	/* Management. */

	/** Returns a job with its progress. */
	public function job( int $job_id ) {
		global $wpdb;

		$job = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, state, priority, planned, plan_wait, depends_on, group_key, affinity, owner_id, result, error, run_at, started_at, finished_at, created_at, updated_at FROM {$this->schema->jobs} WHERE id = %d", $job_id ) );
		if ( ! $job ) {
			return null;
		}

		$counts  = array_fill_keys( array( 'pending', 'leased', 'completed', 'failed' ), 0 );
		$running = 0.0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS n, SUM(progress) AS p FROM {$this->schema->units} WHERE job_id = %d GROUP BY state", $job_id ) ) as $row ) {
			$counts[ $row->state ] = (int) $row->n;
			if ( 'leased' === $row->state ) {
				$running = (float) $row->p;
			}
		}

		$total = array_sum( $counts );

		// Units a cancelled or failed job never ran are skipped, not waiting.
		$counts['skipped'] = 0;
		if ( in_array( $job->state, array( 'cancelled', 'failed' ), true ) ) {
			$counts['skipped'] = $counts['pending'] + $counts['leased'];
			$counts['pending'] = 0;
			$counts['leased']  = 0;
			$running           = 0.0;
		}

		$date  = function ( $value ) {
			return $value ? str_replace( ' ', 'T', $value ) . 'Z' : null;
		};

		return array(
			'id'         => (int) $job->id,
			'type'       => $job->type,
			'state'      => $job->state,
			'priority'   => (int) $job->priority,
			'planned'    => (bool) $job->planned,
			'depends_on' => array_values( array_filter( array_map( 'intval', explode( ',', (string) $job->depends_on ) ) ) ),
			'group'      => (string) $job->group_key,
			'affinity'   => (string) $job->affinity,
			'owner'      => (int) $job->owner_id,
			'units'      => $counts,
			'progress'   => $total ? round( ( $counts['completed'] + $counts['failed'] + $counts['skipped'] + $running ) / $total, 4 ) : ( self::COMPLETED === $job->state ? 1 : 0 ),
			'result'     => null === $job->result ? null : json_decode( $job->result, true ),
			'error'      => $job->error,
			'run_at'     => $date( $job->run_at ),
			'started'    => $date( $job->started_at ),
			'finished'   => $date( $job->finished_at ),
			'created'    => $date( $job->created_at ),
			'updated'    => $date( $job->updated_at ),
		);
	}

	/** Lists jobs, most recent first. */
	public function jobs( array $args = array() ): array {
		global $wpdb;

		$where = array( '1=1' );
		if ( ! empty( $args['owner'] ) ) {
			$where[] = $wpdb->prepare( 'owner_id = %d', (int) $args['owner'] );
		}
		if ( ! empty( $args['state'] ) ) {
			$where[] = $wpdb->prepare( 'state = %s', (string) $args['state'] );
		}
		if ( ! empty( $args['type'] ) ) {
			$where[] = $wpdb->prepare( 'type = %s', (string) $args['type'] );
		}
		if ( isset( $args['group'] ) && '' !== (string) $args['group'] ) {
			$where[] = $wpdb->prepare( 'group_key = %s', (string) $args['group'] );
		}

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->schema->jobs} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', max( 1, min( 200, (int) ( $args['limit'] ?? 20 ) ) ), max( 0, (int) ( $args['offset'] ?? 0 ) ) ) );

		return array_values( array_filter( array_map( array( $this, 'job' ), array_map( 'intval', (array) $ids ) ) ) );
	}

	/** Sums up a group of jobs (see the `group` argument of `enqueue()`). */
	public function group( string $group ): array {
		global $wpdb;

		$jobs = array_fill_keys( array( self::ACTIVE, self::WAITING, self::PAUSED, self::FINALIZING, self::COMPLETED, self::FAILED, self::CANCELLED ), 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS n FROM {$this->schema->jobs} WHERE group_key = %s GROUP BY state", $group ) ) as $row ) {
			$jobs[ $row->state ] = (int) $row->n;
		}
		$jobs['total'] = array_sum( $jobs );

		$units   = array_fill_keys( array( 'pending', 'leased', 'completed', 'failed', 'skipped' ), 0 );
		$running = 0.0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT u.state, j.state IN ('cancelled','failed') AS ended, COUNT(*) AS n, SUM(u.progress) AS p FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.group_key = %s GROUP BY u.state, ended", $group ) ) as $row ) {
			$state = $row->ended && in_array( $row->state, array( 'pending', 'leased' ), true ) ? 'skipped' : $row->state;

			$units[ $state ] += (int) $row->n;
			if ( 'leased' === $state ) {
				$running += (float) $row->p;
			}
		}

		$total = array_sum( $units );
		$open  = $jobs[ self::ACTIVE ] + $jobs[ self::WAITING ] + $jobs[ self::PAUSED ] + $jobs[ self::FINALIZING ] > 0;

		return array(
			'jobs'     => $jobs,
			'units'    => $units,
			'progress' => $total ? round( ( $units['completed'] + $units['failed'] + $units['skipped'] + $running ) / $total, 4 ) : ( $open ? 0.0 : 1.0 ),
			'open'     => $open,
			'owners'   => array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner_id FROM {$this->schema->jobs} WHERE group_key = %s LIMIT 20", $group ) ) ),
		);
	}

	/** Applies `control()` to the jobs of a group that can take the action. */
	public function control_group( string $group, string $action ) {
		global $wpdb;

		$states = array(
			'pause'  => array( self::ACTIVE ),
			'resume' => array( self::PAUSED ),
			'cancel' => array( self::ACTIVE, self::PAUSED, self::WAITING, self::FINALIZING ),
			'retry'  => array( self::FAILED, self::COMPLETED ),
		);
		if ( ! isset( $states[ $action ] ) || '' === $group ) {
			return new \WP_Error( 'ww_action', __( 'Unknown action.', 'wasm-worker' ), array( 'status' => 400 ) );
		}

		$in      = implode( ',', array_fill( 0, count( $states[ $action ] ), '%s' ) );
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->schema->jobs} WHERE group_key = %s AND state IN ($in) ORDER BY id ASC", ...array_merge( array( $group ), $states[ $action ] ) ) );
		$changed = 0;

		// A job whose dependency was just cancelled has failed with it: control() refuses it, which is fine.
		// So is the refusal of a finalization that may still be running.
		foreach ( array_map( 'intval', (array) $ids ) as $job_id ) {
			if ( ! is_wp_error( $this->control( $job_id, $action ) ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/** Lists the units of a job (e.g. the failed ones, the dead letters). */
	public function units( int $job_id, string $state = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$filter = '' === $state ? '' : $wpdb->prepare( ' AND state = %s', $state );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, unit_key, state, attempts, progress, error, duration_ms, available_at, finished_at FROM {$this->schema->units} WHERE job_id = %d$filter ORDER BY id ASC LIMIT %d OFFSET %d", $job_id, max( 1, min( 500, $limit ) ), max( 0, $offset ) ) );

		return array_map(
			function ( $row ) {
				return array(
					'id'          => (int) $row->id,
					'key'         => $row->unit_key,
					'state'       => $row->state,
					'attempts'    => (int) $row->attempts,
					'progress'    => (float) $row->progress,
					'error'       => $row->error,
					'duration_ms' => (int) $row->duration_ms,
					'retry_at'    => $row->available_at ? str_replace( ' ', 'T', $row->available_at ) . 'Z' : null,
					'finished'    => $row->finished_at ? str_replace( ' ', 'T', $row->finished_at ) . 'Z' : null,
				);
			},
			(array) $rows
		);
	}

	/** Pauses, resumes, cancels a job, or retries its failed units. */
	public function control( int $job_id, string $action ) {
		global $wpdb;

		$now  = self::now();
		$jobs = $this->schema->jobs;

		switch ( $action ) {
			case 'pause':
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $jobs SET state = 'paused', updated_at = %s WHERE id = %d AND state = 'active'", $now, $job_id ) );
				break;
			case 'resume':
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $jobs SET state = 'active', updated_at = %s WHERE id = %d AND state = 'paused'", $now, $job_id ) );
				break;
			case 'cancel':
				// A finalization updated within the timeout may still be running; an older one is stuck.
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $jobs SET state = 'cancelled', finished_at = %s, updated_at = %s WHERE id = %d AND ( state IN ('active','paused','waiting') OR ( state = 'finalizing' AND updated_at < %s ) )", $now, $now, $job_id, self::now( -self::FINALIZE_TIMEOUT ) ) );
				if ( $changed ) {
					$this->emit( 'job_cancelled', $job_id, (string) $wpdb->get_var( $wpdb->prepare( "SELECT type FROM $jobs WHERE id = %d", $job_id ) ) );
					$this->release_dependents( $job_id );
				}
				break;
			case 'retry':
				// The failed units run again; a failed job without failed units (its finalization failed) is
				// finalized again.
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE $jobs SET state = 'active', finalize_tries = 0, result = NULL, error = NULL, finished_at = NULL, updated_at = %s WHERE id = %d AND ( state = 'failed' OR ( state = 'completed' AND EXISTS ( SELECT 1 FROM {$this->schema->units} u WHERE u.job_id = %d AND u.state = 'failed' ) ) )", $now, $job_id, $job_id ) );
				if ( $changed ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$this->schema->units} SET state = 'pending', attempts = 0, error = NULL, available_at = NULL, finished_at = NULL, updated_at = %s WHERE job_id = %d AND state = 'failed'", $now, $job_id ) );
				}
				break;
			default:
				return new \WP_Error( 'ww_action', __( 'Unknown action.', 'wasm-worker' ), array( 'status' => 400 ) );
		}

		if ( ! $changed ) {
			return new \WP_Error( 'ww_state', __( 'The job cannot do that in its current state.', 'wasm-worker' ), array( 'status' => 409 ) );
		}

		if ( 'resume' === $action || 'retry' === $action ) {
			$this->maybe_finalize( $job_id );
		}

		$job = $this->job( $job_id );
		return array( 'state' => $job ? $job['state'] : '' );
	}

	/**
	 * Counts the work a browser could start now, cheaply (polled by idle tabs). With `$types`, only the work of
	 * those task types: a tab whose worker runs some types is not woken for the others.
	 */
	public function waiting( array $types = array() ): int {
		global $wpdb;

		$now    = self::now();
		$paused = $this->paused_types();
		$skip   = $paused ? " AND j.type NOT IN ('" . implode( "','", array_map( 'esc_sql', $paused ) ) . "')" : '';

		if ( $types ) {
			$types = array_values( array_intersect( $this->types(), $types ) );
			if ( ! $types ) {
				return 0;
			}
			$skip .= " AND j.type IN ('" . implode( "','", array_map( 'esc_sql', $types ) ) . "')";
		}

		$units = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE j.state = 'active' AND ( j.run_at IS NULL OR j.run_at <= %s )$skip AND ( ( u.state = 'pending' AND ( u.available_at IS NULL OR u.available_at <= %s ) ) OR ( u.state = 'leased' AND u.lease_expires < %s ) )", $now, $now, $now ) );
		$plans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->schema->jobs} j WHERE j.state = 'active' AND j.planned = 0$skip" );

		return $units + $plans;
	}

	/** Deletes the finished jobs older than a number of days, with their units and staged files. */
	public function prune( int $days = 30 ): int {
		global $wpdb;

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->schema->jobs} WHERE state IN ('completed','failed','cancelled') AND updated_at < %s LIMIT 200", self::now( -$days * DAY_IN_SECONDS ) ) ) );

		foreach ( $ids as $job_id ) {
			$this->storage->delete_job( $job_id );
			$wpdb->delete( $this->schema->artifacts, array( 'job_id' => $job_id ) );
			$wpdb->delete( $this->schema->units, array( 'job_id' => $job_id ) );
			$wpdb->delete( $this->schema->jobs, array( 'id' => $job_id ) );
		}

		return count( $ids );
	}

	/* Helpers. */

	/**
	 * The fence of every write for a unit: same token and epoch, unexpired lease, job still running.
	 * Use with `{$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id`.
	 */
	private function fence( int $unit_id, string $hash, int $epoch ): string {
		global $wpdb;

		return $wpdb->prepare( "u.id = %d AND u.state = 'leased' AND u.lease_hash = %s AND u.lease_epoch = %d AND u.lease_expires >= %s AND j.state IN ('active','paused')", $unit_id, $hash, $epoch, self::now() );
	}

	/**
	 * Starts a transaction and locks a unit row if its lease is still valid. The lock holds off a claim of
	 * the same unit (which updates that row) until the caller commits.
	 */
	private function begin_fenced( int $unit_id, string $hash, int $epoch ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_var( "SELECT u.id FROM {$this->schema->units} u JOIN {$this->schema->jobs} j ON j.id = u.job_id WHERE " . $this->fence( $unit_id, $hash, $epoch ) . ' FOR UPDATE' );

		if ( ! $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		return true;
	}

	/** Returns the stored hash of a lease token, empty if the token is malformed. */
	private static function lease_hash( string $token ): string {
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? hash( 'sha256', $token ) : '';
	}

	/** Error for a lease which expired, was taken over, or whose job stopped. */
	private static function stale(): \WP_Error {
		return new \WP_Error( 'ww_stale_lease', __( 'The lease expired, was taken over, or its job stopped.', 'wasm-worker' ), array( 'status' => 409 ) );
	}

	/** Returns a GMT MySQL date. */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/** Encodes JSON with a size limit. */
	private static function encode( $value, int $max_bytes ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json || strlen( $json ) > $max_bytes ) {
			return new \WP_Error( 'ww_json', __( 'The data is not valid JSON, or too large.', 'wasm-worker' ), array( 'status' => 413 ) );
		}

		return $json;
	}
}
