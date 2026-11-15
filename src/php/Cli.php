<?php
/**
 * WP-CLI commands of one host plugin's queue, e.g. `wp my-plugin-worker jobs`.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Operates the job queue from the command line.
 */
final class Cli {
	/** @var Kit */
	private $kit;

	public function __construct( Kit $kit ) {
		$this->kit = $kit;
	}

	/**
	 * Lists jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : active, waiting, paused, finalizing, completed, failed or cancelled.
	 *
	 * [--type=<type>]
	 * : Task type.
	 *
	 * [--limit=<limit>]
	 * : Number of jobs.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function jobs( $args, $assoc_args ) {
		$rows = array_map(
			function ( $job ) {
				return array(
					'id'       => $job['id'],
					'type'     => $job['type'],
					'state'    => $job['state'],
					'priority' => $job['priority'],
					'progress' => round( $job['progress'] * 100 ) . '%',
					'units'    => sprintf( '%d done, %d running, %d waiting, %d failed', $job['units']['completed'], $job['units']['leased'], $job['units']['pending'], $job['units']['failed'] ) . ( $job['units']['skipped'] ? sprintf( ', %d skipped', $job['units']['skipped'] ) : '' ),
					'created'  => $job['created'],
					'error'    => (string) $job['error'],
				);
			},
			$this->kit->jobs(
				array(
					'state' => $assoc_args['state'] ?? '',
					'type'  => $assoc_args['type'] ?? '',
					'limit' => (int) ( $assoc_args['limit'] ?? 20 ),
				)
			)
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'type', 'state', 'priority', 'progress', 'units', 'created', 'error' ) );
	}

	/**
	 * Shows a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function job( $args ) {
		$job = $this->kit->job( (int) $args[0] );
		if ( ! $job ) {
			\WP_CLI::error( 'Job not found.' );
		}
		\WP_CLI::line( (string) wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Queues a job.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Task type.
	 *
	 * [<input>]
	 * : Job input as JSON. Default: {}
	 *
	 * [--key=<key>]
	 * : Idempotency key: an existing job with this key is returned instead.
	 *
	 * [--priority=<priority>]
	 * : Higher first.
	 *
	 * [--delay=<seconds>]
	 * : Start no earlier than this many seconds from now.
	 *
	 * [--after=<ids>]
	 * : Comma-separated job IDs this job waits for.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function enqueue( $args, $assoc_args ) {
		$input = json_decode( $args[1] ?? '{}', true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			\WP_CLI::error( 'The input is not valid JSON.' );
		}

		$options = array();
		foreach ( array( 'key', 'priority', 'delay' ) as $name ) {
			if ( isset( $assoc_args[ $name ] ) ) {
				$options[ $name ] = 'key' === $name ? (string) $assoc_args[ $name ] : (int) $assoc_args[ $name ];
			}
		}
		if ( isset( $assoc_args['after'] ) ) {
			$options['after'] = array_map( 'intval', explode( ',', (string) $assoc_args['after'] ) );
		}

		$job = $this->kit->enqueue( (string) $args[0], $input, $options );
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}
		\WP_CLI::success( "Job $job queued." );
	}

	/**
	 * Lists the units of a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * [--state=<state>]
	 * : pending, leased, completed or failed.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function units( $args, $assoc_args ) {
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $this->kit->units( (int) $args[0], (string) ( $assoc_args['state'] ?? '' ), 500 ), array( 'id', 'key', 'state', 'attempts', 'progress', 'duration_ms', 'retry_at', 'error' ) );
	}

	/**
	 * Pauses a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function pause( $args ) {
		$this->control( (int) $args[0], 'pause' );
	}

	/**
	 * Resumes a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function resume( $args ) {
		$this->control( (int) $args[0], 'resume' );
	}

	/**
	 * Cancels a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function cancel( $args ) {
		$this->control( (int) $args[0], 'cancel' );
	}

	/**
	 * Retries the failed units of a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function retry( $args ) {
		$this->control( (int) $args[0], 'retry' );
	}

	/**
	 * Pauses a task type (no new units handed out).
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Task type.
	 *
	 * @subcommand pause-type
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function pause_type( $args ) {
		$this->kit->pause_type( (string) $args[0], true );
		\WP_CLI::success( "Task type {$args[0]} paused." );
	}

	/**
	 * Resumes a task type.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Task type.
	 *
	 * @subcommand resume-type
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function resume_type( $args ) {
		$this->kit->pause_type( (string) $args[0], false );
		\WP_CLI::success( "Task type {$args[0]} resumed." );
	}

	/**
	 * Shows the metrics by task type.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function metrics( $args, $assoc_args ) {
		$metrics = $this->kit->metrics();
		$rows    = array();

		foreach ( $metrics['types'] as $type => $m ) {
			$rows[] = array(
				'type'          => $type,
				'paused'        => ! empty( $m['paused'] ) ? 'yes' : 'no',
				'waiting units' => (int) ( $m['units']['pending'] ?? 0 ),
				'running units' => (int) ( $m['units']['leased'] ?? 0 ),
				'done 1h'       => $m['completed_1h'],
				'done 24h'      => $m['completed_24h'],
				'failed 24h'    => $m['failed_24h'],
				'avg ms'        => $m['avg_ms_24h'],
				'p95 ms'        => $m['p95_ms_24h'],
				'per minute'    => $m['per_minute'],
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'type', 'paused', 'waiting units', 'running units', 'done 1h', 'done 24h', 'failed 24h', 'avg ms', 'p95 ms', 'per minute' ) );
		\WP_CLI::line( sprintf( '%d worker(s) active.', $metrics['workers'] ) );
	}

	/**
	 * Lists the workers seen in the last 5 minutes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function workers( $args, $assoc_args ) {
		$rows = array();
		foreach ( $this->kit->workers() as $id => $worker ) {
			$rows[] = array(
				'id'           => $id,
				'user'         => $worker['name'],
				'units'        => $worker['units'],
				'types'        => implode( ',', $worker['types'] ),
				'capabilities' => (string) wp_json_encode( $worker['capabilities'] ),
				'seen'         => human_time_diff( (int) $worker['seen'] ) . ' ago',
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'user', 'units', 'types', 'capabilities', 'seen' ) );
	}

	/**
	 * Runs the recovery and planning now (normally done by the workers' requests and hourly cron).
	 *
	 * @return void
	 */
	public function recover() {
		$this->kit->maintenance();
		\WP_CLI::success( 'Recovered, planned and pruned.' );
	}

	/**
	 * Deletes the finished jobs older than a number of days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * ---
	 * default: 30
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function prune( $args, $assoc_args ) {
		\WP_CLI::success( sprintf( '%d job(s) deleted.', $this->kit->queue()->prune( (int) ( $assoc_args['days'] ?? 30 ) ) ) );
	}

	/** Applies a job action. */
	private function control( int $job_id, string $action ) {
		$result = $this->kit->control( $job_id, $action );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( "Job $job_id: {$result['state']}." );
	}
}
