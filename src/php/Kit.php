<?php
/**
 * Entry point of the WASM worker kit for a host plugin.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per host plugin. Everything is scoped by the plugin ID: tables, options, REST namespace,
 * hooks, cron events, CLI command, staging folder and the browser lock.
 *
 *     $kit = new Kit( array( 'id' => 'my-plugin' ) );
 *     $kit->register( new My_Task() );
 *     $kit->boot();
 *
 *     $job_id = $kit->enqueue( 'my-task', $input, array( 'key' => 'post-42', 'priority' => 5 ) );
 *     add_action( $kit->hook( 'job_completed' ), function ( $job_id, $type, $result ) { ... }, 10, 3 );
 */
final class Kit {
	/**
	 * Kit release.
	 */
	const VERSION = '1.0.0';

	/** @var array */
	private $config;

	/** @var string */
	private $prefix;

	/** @var Schema */
	private $schema;

	/** @var Queue */
	private $queue;

	/** @var Storage */
	private $storage;

	/** @var Monitor */
	private $monitor;

	/** @var array[] */
	private $repeats = array();

	/**
	 * Sets up the queue of a host plugin.
	 *
	 * @param array $config {
	 *     @type string $id                Stable ID of the host plugin (lowercase letters, digits, `-`). Required.
	 *     @type string $rest_namespace    REST namespace. Default `<id>-worker/v1`.
	 *     @type string $cli_command       WP-CLI command. Default `<id>-worker`, false to disable.
	 *     @type string $hook_prefix       Prefix of the action hooks. Default `<id with _>_worker_`.
	 *     @type string $capability        Capability needed to run browser workers. Default `manage_options`.
	 *     @type string $manage_capability Capability needed to see and control all jobs (owners always can
	 *                                     control their own). Default `manage_options`.
	 *     @type int    $prune_days        Finished jobs are deleted after this many days. Default 30, 0 to keep.
	 *     @type int    $max_upload        Maximum artifact size in bytes. Default 64 MB.
	 *     @type string $staging_dir       Folder for the uploaded files, ideally outside the web root (e.g.
	 *                                     `dirname( ABSPATH, 2 ) . '/storage'` on Bedrock). Default: the uploads folder.
	 *     @type bool   $private_files     True when the outputs must never be reachable over the web (documents,
	 *                                     private media...): uploads are refused while `staging_dir` is inside the
	 *                                     WordPress tree. Default false (outputs published anyway, e.g. optimized images).
	 * }
	 * @throws \InvalidArgumentException Without a valid ID.
	 */
	public function __construct( array $config ) {
		$id = (string) ( $config['id'] ?? '' );

		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,39}$/', $id ) ) {
			throw new \InvalidArgumentException( 'The WASM worker kit needs a stable lowercase plugin ID.' );
		}

		$this->config = array_merge(
			array(
				'id'                => $id,
				'rest_namespace'    => $id . '-worker/v1',
				'cli_command'       => $id . '-worker',
				'hook_prefix'       => str_replace( '-', '_', $id ) . '_worker_',
				'capability'        => 'manage_options',
				'manage_capability' => 'manage_options',
				'prune_days'        => 30,
				'max_upload'        => 64 * MB_IN_BYTES,
				'staging_dir'       => '',
				'private_files'     => false,
			),
			$config
		);

		// Readable and short, unique per ID: e.g. `ww_myplugin_1a2b3c`.
		$this->prefix  = 'ww_' . substr( str_replace( '-', '', $id ), 0, 12 ) . '_' . substr( md5( $id ), 0, 6 );
		$this->schema  = new Schema( $this->prefix );
		$this->storage = new Storage( $this->prefix, (int) $this->config['max_upload'], (string) $this->config['staging_dir'], (bool) $this->config['private_files'] );
		$this->queue   = new Queue( $this->schema, $this->storage, $this->prefix, (string) $this->config['hook_prefix'] );
		$this->monitor = new Monitor( $this->schema, $this->prefix );
	}

	/**
	 * Registers a task type. Call before `boot()`.
	 *
	 * @param Task $task Task.
	 * @return self
	 */
	public function register( Task $task ): self {
		$this->queue->register( $task );
		return $this;
	}

	/**
	 * Hooks the kit into WordPress. Call once, when the host plugin loads.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'rest_api_init', array( new Rest( $this ), 'register' ) );
		add_action( 'admin_init', array( $this->schema, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this->schema, 'maybe_upgrade' ), 1 );

		$hook = $this->prefix . '_maintenance';
		add_action( $hook, array( $this, 'maintenance' ) );
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && $this->config['cli_command'] ) {
			$this->schema->maybe_upgrade(); // WP-CLI requests have no admin_init.
			\WP_CLI::add_command( (string) $this->config['cli_command'], new Cli( $this ) );
		}
	}

	/**
	 * Hourly maintenance: continues planning, recovers interrupted work, prunes old jobs. The queue does not
	 * depend on it (the workers' requests do the same), it only keeps things tidy on quiet sites.
	 *
	 * @return void
	 */
	public function maintenance() {
		$this->schema->maybe_upgrade();
		$this->queue->recover();
		$this->queue->plan_pending( 10.0 );

		if ( (int) $this->config['prune_days'] > 0 ) {
			$this->queue->prune( (int) $this->config['prune_days'] );
		}
	}

	/* Jobs. */

	/**
	 * Creates a job, see `Queue::enqueue()`.
	 *
	 * @param string $type  Task type.
	 * @param mixed  $input Job input.
	 * @param array  $args  `key`, `rerun`, `owner`, `priority`, `delay`, `run_at`, `after`.
	 * @return int|\WP_Error Job ID.
	 */
	public function enqueue( string $type, $input, array $args = array() ) {
		$this->schema->maybe_upgrade();
		return $this->queue->enqueue( $type, $input, $args );
	}

	/**
	 * Creates a job on a schedule, through WP-Cron. Call on every load, like `add_action()`. Each run gets the
	 * idempotency key `<name>:<period>`, so a cron that fires twice does not create two jobs.
	 *
	 * @param string   $name       Unique name of the schedule.
	 * @param string   $type       Task type.
	 * @param callable $input      Returns the job input, or null to skip this run.
	 * @param string   $recurrence A WP-Cron recurrence: `hourly`, `twicedaily`, `daily`, `weekly` or a custom one.
	 * @param array    $args       Extra `enqueue()` arguments (`priority`...).
	 * @return void
	 */
	public function repeat( string $name, string $type, callable $input, string $recurrence = 'daily', array $args = array() ) {
		$name     = sanitize_key( $name );
		$hook     = $this->prefix . '_repeat_' . $name;
		$schedule = wp_get_schedules()[ $recurrence ] ?? null;

		if ( ! $schedule ) {
			return;
		}

		$this->repeats[ $name ] = $hook;

		add_action(
			$hook,
			function () use ( $name, $type, $input, $schedule, $args ) {
				$value = call_user_func( $input );
				if ( null !== $value ) {
					$period = (int) floor( time() / max( 60, (int) $schedule['interval'] ) );
					$this->enqueue( $type, $value, array_merge( $args, array( 'key' => "$name:$period" ) ) );
				}
			}
		);

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 60, $recurrence, $hook );
		}
	}

	/**
	 * Returns a job and its progress.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null
	 */
	public function job( int $job_id ) {
		return $this->queue->job( $job_id );
	}

	/**
	 * Lists jobs.
	 *
	 * @param array $args `limit`, `offset`, `owner`, `state`, `type`.
	 * @return array[]
	 */
	public function jobs( array $args = array() ): array {
		return $this->queue->jobs( $args );
	}

	/**
	 * Lists the units of a job.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $state  State filter, e.g. `failed`.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @return array[]
	 */
	public function units( int $job_id, string $state = '', int $limit = 50, int $offset = 0 ): array {
		return $this->queue->units( $job_id, $state, $limit, $offset );
	}

	/**
	 * Pauses, resumes, cancels or retries a job. The caller checks permissions (the REST route does).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $action Action.
	 * @return array|\WP_Error
	 */
	public function control( int $job_id, string $action ) {
		return $this->queue->control( $job_id, $action );
	}

	/**
	 * Sums up a group of jobs, see `Queue::group()`.
	 *
	 * @param string $group Group.
	 * @return array
	 */
	public function group( string $group ): array {
		return $this->queue->group( $group );
	}

	/**
	 * Pauses, resumes, cancels or retries the jobs of a group.
	 *
	 * @param string $group  Group.
	 * @param string $action `pause`, `resume`, `cancel` or `retry`.
	 * @return int|\WP_Error Number of jobs changed.
	 */
	public function control_group( string $group, string $action ) {
		return $this->queue->control_group( $group, $action );
	}

	/**
	 * Pauses or resumes a whole task type (e.g. during a maintenance of what its finalization writes to).
	 *
	 * @param string $type   Task type.
	 * @param bool   $paused Paused.
	 * @return void
	 */
	public function pause_type( string $type, bool $paused = true ) {
		$this->queue->set_type_paused( $type, $paused );
	}

	/**
	 * Returns the task types with their pause state.
	 *
	 * @return bool[] Paused, by type.
	 */
	public function types(): array {
		$paused = $this->queue->paused_types();
		$out    = array();

		foreach ( $this->queue->types() as $type ) {
			$out[ $type ] = in_array( $type, $paused, true );
		}

		return $out;
	}

	/**
	 * Returns the name of an action hook of this queue. Events: `job_created` ( $job_id, $type ),
	 * `job_completed` ( $job_id, $type, $result ), `job_failed` ( $job_id, $type, $error ),
	 * `job_cancelled` ( $job_id, $type ), `unit_completed` ( $unit_id, $job_id, $type, $key ),
	 * `unit_failed` ( $unit_id, $job_id, $type, $error ).
	 *
	 * @param string $event Event.
	 * @return string
	 */
	public function hook( string $event ): string {
		return $this->config['hook_prefix'] . $event;
	}

	/* Monitoring. */

	/**
	 * Returns the metrics, see `Monitor::metrics()`.
	 *
	 * @return array
	 */
	public function metrics(): array {
		$metrics = $this->monitor->metrics( $this->queue->types() );
		foreach ( $this->types() as $type => $paused ) {
			$metrics['types'][ $type ]['paused'] = $paused;
		}

		return $metrics;
	}

	/**
	 * Returns the workers seen recently.
	 *
	 * @return array[]
	 */
	public function workers(): array {
		return $this->monitor->workers();
	}

	/**
	 * Returns the monitor (used by the REST routes).
	 *
	 * @return Monitor
	 */
	public function monitor(): Monitor {
		return $this->monitor;
	}

	/**
	 * Returns the queue, for advanced uses and tests.
	 *
	 * @return Queue
	 */
	public function queue(): Queue {
		return $this->queue;
	}

	/**
	 * Describes the staging folder: show a notice when `ok` is false (private files, public folder).
	 *
	 * @return array `path`, `public`, `private_required`, `ok`.
	 */
	public function staging_status(): array {
		return $this->storage->status();
	}

	/* Permissions. */

	/**
	 * Returns the REST namespace.
	 *
	 * @return string
	 */
	public function rest_namespace(): string {
		return (string) $this->config['rest_namespace'];
	}

	/**
	 * Whether a user may run workers: the capability, or one of the extra allowed roles.
	 *
	 * @param \WP_User|null $user User, default current.
	 * @return bool
	 */
	public function can_work( $user = null ): bool {
		$user = $user instanceof \WP_User ? $user : wp_get_current_user();

		if ( ! $user->exists() ) {
			return false;
		}

		return user_can( $user, (string) $this->config['capability'] ) || (bool) array_intersect( $this->allowed_roles(), (array) $user->roles );
	}

	/**
	 * Whether the current user may see and control all jobs.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( (string) $this->config['manage_capability'] );
	}

	/**
	 * Returns the extra roles allowed to run workers (besides the capability).
	 *
	 * @return string[]
	 */
	public function allowed_roles(): array {
		$roles = get_option( $this->prefix . '_roles', array() );
		return is_array( $roles ) ? array_values( array_map( 'sanitize_key', $roles ) ) : array();
	}

	/**
	 * Saves the extra roles allowed to run workers. The host plugin calls it from its own settings handler,
	 * after checking a nonce.
	 *
	 * @param string[] $roles Role names.
	 * @return void
	 * @throws \RuntimeException When the current user cannot manage the kit.
	 */
	public function save_allowed_roles( array $roles ) {
		if ( ! $this->can_manage() ) {
			throw new \RuntimeException( 'You are not allowed to change the worker roles.' );
		}

		$known = array_keys( wp_roles()->roles );
		update_option( $this->prefix . '_roles', array_values( array_intersect( $known, array_map( 'sanitize_key', $roles ) ) ) );
	}

	/* Browser side. */

	/**
	 * Returns what the browser side needs (`startWorkers()` / `watchQueue()` options without `workerUrl`).
	 *
	 * @return array
	 */
	public function client_config(): array {
		return array(
			'id'       => (string) $this->config['id'],
			'restUrl'  => esc_url_raw( rest_url( $this->rest_namespace() . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ),
		);
	}

	/**
	 * Returns what `mountDashboard()` needs.
	 *
	 * @return array
	 */
	public function dashboard_config(): array {
		return $this->client_config() + array( 'canManage' => $this->can_manage() );
	}

	/* Lifecycle. */

	/**
	 * Creates the tables. The kit also does it by itself on boot when its schema changes.
	 *
	 * @return void
	 */
	public function install() {
		$this->schema->install();
	}

	/**
	 * Deactivation: stops the cron events (the data stays).
	 *
	 * @return void
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( $this->prefix . '_maintenance' );
		foreach ( $this->repeats as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Removes everything: tables, staged files, options, transients, cron. Call from the host plugin's uninstall.
	 *
	 * @return void
	 */
	public function uninstall() {
		global $wpdb;

		$this->deactivate();
		$this->storage->delete_all();
		$this->schema->drop();
		delete_option( $this->prefix . '_roles' );
		delete_option( $this->prefix . '_paused_types' );

		// Transients (worker registry, scan positions) and the cron events of repeatable jobs not registered now.
		$like = $wpdb->esc_like( '_transient_' . $this->prefix . '_' ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $like ) ) as $option ) {
			delete_transient( substr( $option, strlen( '_transient_' ) ) );
		}

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, $this->prefix . '_' ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}
}
