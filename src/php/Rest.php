<?php
/**
 * REST routes of one host plugin's queue.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Worker routes (eligible users only):
 * - POST claim      { types, affinity, worker: { id, capabilities, agent } }  A batch of units and its lease.
 * - POST renew      { lease, progress: { unit_id: 0..1 } }           Extends the lease, records progress.
 * - POST checkpoint { lease, unit_id, epoch, sequence, checkpoint }
 * - POST artifact   multipart: lease, unit_id, epoch, key, sha256, file
 * - POST complete   { lease, results: [ { unit_id, epoch, completion_id, result } ] }
 * - POST fail       { lease, unit_id, epoch, error, retry }
 * - POST release    { lease, units: [ { unit_id, epoch } ] }         Gives back units of a closing page at once.
 * - GET  waiting                                                     Amount of waiting work, for idle tabs.
 *
 * Job routes (job owner, or the manage capability):
 * - GET  jobs?state=&type=&group=&limit=&offset=,  GET jobs/{id},  GET jobs/{id}/units?state=
 * - POST jobs/{id} { action: pause|resume|cancel|retry }
 * - GET  groups/{group},  POST groups/{group} { action: pause|resume|cancel|retry }
 *
 * Operations (manage capability): GET metrics, GET workers, GET types, POST types/{type} { action: pause|resume }.
 */
final class Rest {
	/** @var Kit */
	private $kit;

	public function __construct( Kit $kit ) {
		$this->kit = $kit;
	}

	/** Registers the routes. */
	public function register() {
		$ns     = $this->kit->rest_namespace();
		$worker = function () {
			return $this->kit->can_work();
		};
		$user   = function () {
			return is_user_logged_in();
		};
		$manage = function () {
			return $this->kit->can_manage();
		};

		$lease = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-f0-9]{64}$',
		);
		$unit  = array(
			'unit_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
			'epoch'   => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		$routes = array(
			'/claim'      => array( 'POST', $worker, array( $this, 'claim' ), array( 'types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'required' => true ) ) ),
			'/renew'      => array( 'POST', $worker, array( $this, 'renew' ), array( 'lease' => $lease ) ),
			'/checkpoint' => array( 'POST', $worker, array( $this, 'checkpoint' ), $unit + array( 'lease' => $lease, 'sequence' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ) ),
			'/artifact'   => array( 'POST', $worker, array( $this, 'artifact' ), $unit + array( 'lease' => $lease, 'key' => array( 'type' => 'string', 'required' => true ), 'sha256' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-fA-F0-9]{64}$' ) ) ),
			'/complete'   => array( 'POST', $worker, array( $this, 'complete' ), array( 'lease' => $lease, 'results' => array( 'type' => 'array', 'required' => true, 'maxItems' => 500 ) ) ),
			'/fail'       => array( 'POST', $worker, array( $this, 'fail' ), $unit + array( 'lease' => $lease, 'error' => array( 'type' => 'string', 'default' => '' ), 'retry' => array( 'type' => 'boolean', 'default' => true ) ) ),
			'/release'    => array( 'POST', $worker, array( $this, 'release' ), array( 'lease' => $lease, 'units' => array( 'type' => 'array', 'required' => true, 'maxItems' => 500 ) ) ),
			'/waiting'    => array( 'GET', $worker, array( $this, 'waiting' ), array() ),
			'/jobs'       => array( 'GET', $user, array( $this, 'jobs' ), array() ),
			'/metrics'    => array( 'GET', $manage, array( $this, 'metrics' ), array() ),
			'/workers'    => array( 'GET', $manage, array( $this, 'workers' ), array() ),
			'/types'      => array( 'GET', $manage, array( $this, 'types' ), array() ),
		);

		foreach ( $routes as $path => list( $method, $permission, $callback, $args ) ) {
			register_rest_route(
				$ns,
				$path,
				array(
					'methods'             => $method,
					'permission_callback' => $permission,
					'callback'            => $callback,
					'args'                => $args,
				)
			);
		}

		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $user,
					'callback'            => array( $this, 'job' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $user,
					'callback'            => array( $this, 'control' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'pause', 'resume', 'cancel', 'retry' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/groups/(?P<group>[A-Za-z0-9_.:-]{1,64})',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $user,
					'callback'            => array( $this, 'group' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $user,
					'callback'            => array( $this, 'control_group' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'pause', 'resume', 'cancel', 'retry' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)/units',
			array(
				'methods'             => 'GET',
				'permission_callback' => $user,
				'callback'            => array( $this, 'units' ),
			)
		);

		register_rest_route(
			$ns,
			'/types/(?P<type>[a-z][a-z0-9_-]{0,63})',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => array( $this, 'pause_type' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'pause', 'resume' ),
						'required' => true,
					),
				),
			)
		);
	}

	/* Workers. */

	/** Claims a batch. */
	public function claim( \WP_REST_Request $request ) {
		$types  = array_values( array_filter( (array) $request['types'], 'is_string' ) );
		$worker = is_array( $request['worker'] ) ? $request['worker'] : array();
		$id     = (string) ( $worker['id'] ?? '' );

		$this->kit->monitor()->touch( $id, wp_get_current_user(), array( 'types' => $types ) + $worker );

		return array( 'batch' => $this->kit->queue()->claim( wp_get_current_user(), $types, $id, is_string( $request['affinity'] ) ? $request['affinity'] : '' ) );
	}

	/** Renews a lease. */
	public function renew( \WP_REST_Request $request ) {
		if ( is_array( $request['worker'] ) && isset( $request['worker']['id'] ) ) {
			$this->kit->monitor()->touch( (string) $request['worker']['id'], wp_get_current_user(), $request['worker'] );
		}

		return $this->kit->queue()->renew( wp_get_current_user(), (string) $request['lease'], is_array( $request['progress'] ) ? $request['progress'] : array() );
	}

	/** Saves a checkpoint. */
	public function checkpoint( \WP_REST_Request $request ) {
		$json = $request->get_json_params();
		if ( ! is_array( $json ) || ! array_key_exists( 'checkpoint', $json ) ) {
			return new \WP_Error( 'ww_checkpoint', __( 'A checkpoint is required.', 'wasm-worker' ), array( 'status' => 400 ) );
		}
		return $this->kit->queue()->checkpoint( wp_get_current_user(), (string) $request['lease'], (int) $request['unit_id'], (int) $request['epoch'], (int) $request['sequence'], $json['checkpoint'] );
	}

	/** Receives an artifact. */
	public function artifact( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'ww_artifact', __( 'The file did not arrive (check upload_max_filesize and post_max_size).', 'wasm-worker' ), array( 'status' => 400 ) );
		}

		return $this->kit->queue()->attach( wp_get_current_user(), (string) $request['lease'], (int) $request['unit_id'], (int) $request['epoch'], (string) $request['key'], (string) $file['tmp_name'], (string) $request['sha256'], (string) ( $file['type'] ?? '' ) );
	}

	/** Receives results. */
	public function complete( \WP_REST_Request $request ) {
		$json = $request->get_json_params();
		return array( 'receipts' => $this->kit->queue()->complete( wp_get_current_user(), (string) $request['lease'], is_array( $json['results'] ?? null ) ? $json['results'] : array() ) );
	}

	/** Gives a unit back. */
	public function fail( \WP_REST_Request $request ) {
		return $this->kit->queue()->fail( wp_get_current_user(), (string) $request['lease'], (int) $request['unit_id'], (int) $request['epoch'], (string) $request['error'], (bool) $request['retry'] );
	}

	/**
	 * Gives back units a worker will not finish (its page is going away). Sent with `navigator.sendBeacon()`,
	 * which cannot set headers: the REST nonce comes as `_wpnonce` in the query string.
	 */
	public function release( \WP_REST_Request $request ) {
		return $this->kit->queue()->release( wp_get_current_user(), (string) $request['lease'], is_array( $request['units'] ) ? $request['units'] : array() );
	}

	/** Amount of waiting work. */
	public function waiting() {
		return array( 'waiting' => $this->kit->queue()->waiting() );
	}

	/* Jobs. */

	/** Lists jobs: all of them for managers, their own for the others. */
	public function jobs( \WP_REST_Request $request ) {
		return $this->kit->jobs(
			array(
				'limit'  => (int) ( $request['limit'] ?? 20 ),
				'offset' => (int) ( $request['offset'] ?? 0 ),
				'state'  => sanitize_key( (string) ( $request['state'] ?? '' ) ),
				'type'   => sanitize_key( (string) ( $request['type'] ?? '' ) ),
				'group'  => is_string( $request['group'] ) ? substr( $request['group'], 0, 64 ) : '',
				'owner'  => $this->kit->can_manage() ? 0 : get_current_user_id(),
			)
		);
	}

	/** Returns a job. */
	public function job( \WP_REST_Request $request ) {
		$job = $this->visible_job( (int) $request['id'] );
		return $job ? $job : self::not_found();
	}

	/** Lists the units of a job. */
	public function units( \WP_REST_Request $request ) {
		if ( ! $this->visible_job( (int) $request['id'] ) ) {
			return self::not_found();
		}
		return $this->kit->units( (int) $request['id'], sanitize_key( (string) ( $request['state'] ?? '' ) ), (int) ( $request['limit'] ?? 50 ), (int) ( $request['offset'] ?? 0 ) );
	}

	/** Controls a job. */
	public function control( \WP_REST_Request $request ) {
		if ( ! $this->visible_job( (int) $request['id'] ) ) {
			return self::not_found();
		}
		return $this->kit->control( (int) $request['id'], (string) $request['action'] );
	}

	/** Sums up a group of jobs. */
	public function group( \WP_REST_Request $request ) {
		$group = $this->visible_group( (string) $request['group'] );
		return $group ? $group : self::not_found();
	}

	/** Controls the jobs of a group. */
	public function control_group( \WP_REST_Request $request ) {
		if ( ! $this->visible_group( (string) $request['group'] ) ) {
			return self::not_found();
		}
		$changed = $this->kit->control_group( (string) $request['group'], (string) $request['action'] );
		return is_wp_error( $changed ) ? $changed : array( 'changed' => $changed ) + $this->kit->group( (string) $request['group'] );
	}

	/* Operations. */

	/** Metrics. */
	public function metrics() {
		return $this->kit->metrics();
	}

	/** Workers. */
	public function workers() {
		return array_values(
			array_map(
				function ( $id, $worker ) {
					return array( 'id' => $id ) + $worker;
				},
				array_keys( $this->kit->workers() ),
				$this->kit->workers()
			)
		);
	}

	/** Task types and their pause state. */
	public function types() {
		return $this->kit->types();
	}

	/** Pauses or resumes a task type. */
	public function pause_type( \WP_REST_Request $request ) {
		$type = (string) $request['type'];

		if ( ! array_key_exists( $type, $this->kit->types() ) ) {
			return self::not_found();
		}

		$this->kit->pause_type( $type, 'pause' === $request['action'] );
		return $this->kit->types();
	}

	/* Helpers. */

	/** Returns a job if the current user may see it. */
	private function visible_job( int $job_id ) {
		$job = $this->kit->job( $job_id );
		return $job && ( $this->kit->can_manage() || ( $job['owner'] && get_current_user_id() === $job['owner'] ) ) ? $job : null;
	}

	/** Returns the summary of a group if the current user may see it: managers, or the owner of all its jobs. */
	private function visible_group( string $group ) {
		$summary = $this->kit->group( $group );
		if ( ! $summary['jobs']['total'] ) {
			return null;
		}
		return $this->kit->can_manage() || array( get_current_user_id() ) === $summary['owners'] ? $summary : null;
	}

	/** Not found error. */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'ww_not_found', __( 'Not found.', 'wasm-worker' ), array( 'status' => 404 ) );
	}
}
