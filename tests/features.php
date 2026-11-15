<?php
/**
 * Scheduling, limits, dependencies, stages and monitoring. Included by smoke.php (same scope: $admin, $wpdb,
 * expect(), the KitTestB task classes).
 *
 * @package WasmWorker
 */

defined( 'ABSPATH' ) || exit;

eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test fixture.
	'namespace KitTestB;
	final class Staged extends Worker\Task {
		public function type(): string { return "staged"; }
		public function options(): array { return array( "batch_units" => 2, "backoff" => 0 ); }
		public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
			if ( null === $cursor ) {
				return array( "units" => array( array( "key" => "s1", "input" => array( "a" => 1, "b" => 10 ) ), array( "key" => "s2", "input" => array( "a" => 2, "b" => 10 ) ) ), "next" => "join", "wait" => true );
			}
			$total = 0;
			foreach ( $outputs->results() as $value ) { $total += $value; }
			return array( "units" => array( array( "key" => "total", "input" => array( "a" => $total, "b" => 0 ) ) ), "next" => null );
		}
		public function validate( $unit, $result, array $artifacts, $job ) { return $result === $unit["a"] + $unit["b"] ? true : new \WP_Error( "bad", "wrong" ); }
		public function finalize( $job, Worker\Outputs $outputs, int $job_id ) { return iterator_to_array( $outputs->results() ); }
	}'
);

$e = new KitTestB\Worker\Kit( array( 'id' => 'kit-test-e' ) );
foreach ( array( 'Add', 'Prio', 'Limited', 'Rated', 'Slow', 'Backed', 'Later', 'Chain', 'Staged' ) as $class ) {
	$class = "KitTestB\\$class";
	$e->register( new $class() );
}
$e->install();
$eq     = $e->queue();
$e_tag  = $wpdb->prefix . 'ww_kitteste_' . substr( md5( 'kit-test-e' ), 0, 6 );
$past   = gmdate( 'Y-m-d H:i:s', time() - 120 );
$events = array();
foreach ( array( 'job_completed', 'job_failed', 'job_cancelled', 'unit_failed' ) as $event ) {
	add_action(
		$e->hook( $event ),
		function ( ...$params ) use ( &$events, $event ) {
			$events[ $event ][] = $params;
		},
		10,
		4
	);
}
$finish = function ( $batch ) use ( $eq, $admin ) {
	$results = array();
	foreach ( $batch['units'] as $u ) {
		$results[] = array( 'unit_id' => $u['id'], 'epoch' => $u['epoch'], 'completion_id' => 'done-' . $u['id'] . '-' . $u['epoch'], 'result' => $u['input']['a'] + $u['input']['b'] );
	}
	return $eq->complete( $admin, $batch['lease'], $results );
};

// Jobs of the same priority take turns; a higher priority goes first.
$x     = $e->enqueue( 'prio', array( 'n' => 3 ) );
$y     = $e->enqueue( 'prio', array( 'n' => 3 ) );
$order = array();
for ( $i = 0; $i < 3; $i++ ) {
	$order[] = $eq->claim( $admin, array( 'prio' ) )['units'][0]['job_id'];
}
expect( array( $x, $y, $x ) === $order, 'jobs of the same priority are served in turn (round-robin)' );
$z = $e->enqueue( 'prio', array( 'n' => 1 ), array( 'priority' => 10 ) );
expect( $z === $eq->claim( $admin, array( 'prio' ) )['units'][0]['job_id'], 'a higher priority job is served first' );

// Delayed jobs.
$later = $e->enqueue( 'later', array( 'n' => 1 ), array( 'delay' => 3600 ) );
expect( null === $eq->claim( $admin, array( 'later' ) ), 'a delayed job does not start early' );
$wpdb->update( "{$e_tag}_jobs", array( 'run_at' => $past ), array( 'id' => $later ) );
expect( null !== $eq->claim( $admin, array( 'later' ) ), 'it starts once due' );

// Retry backoff.
$backed = $e->enqueue( 'backed', array( 'n' => 1 ) );
$bb     = $eq->claim( $admin, array( 'backed' ) );
$eq->fail( $admin, $bb['lease'], $bb['units'][0]['id'], $bb['units'][0]['epoch'], 'temporary' );
expect( null === $eq->claim( $admin, array( 'backed' ) ), 'a failed unit waits for its backoff' );
$retry_at = strtotime( $e->units( $backed, 'pending' )[0]['retry_at'] );
expect( $retry_at > time() + 20 && $retry_at <= time() + 31, 'the first backoff is the configured delay' );
$wpdb->query( $wpdb->prepare( "UPDATE {$e_tag}_units SET available_at = %s WHERE job_id = %d", $past, $backed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
expect( null !== $eq->claim( $admin, array( 'backed' ) ), 'then it is retried' );

// Concurrency limit across all workers.
$e->enqueue( 'limited', array( 'n' => 2 ) );
$l1 = $eq->claim( $admin, array( 'limited' ) );
expect( 1 === count( $l1['units'] ) && null === $eq->claim( $admin, array( 'limited' ) ), 'max_leased: one unit at a time' );
$finish( $l1 );
expect( null !== $eq->claim( $admin, array( 'limited' ) ), 'the next one starts when the first is done' );

// Rate limit.
$e->enqueue( 'rated', array( 'n' => 3 ) );
$r1 = $eq->claim( $admin, array( 'rated' ) );
$r2 = $eq->claim( $admin, array( 'rated' ) );
expect( $r1 && $r2 && null === $eq->claim( $admin, array( 'rated' ) ), 'rate_limit: 2 units per minute' );

// Maximum runtime.
$slow = $e->enqueue( 'slow', array( 'n' => 1 ) );
$sl   = $eq->claim( $admin, array( 'slow' ) );
$wpdb->update( "{$e_tag}_units", array( 'leased_at' => $past ), array( 'id' => $sl['units'][0]['id'] ) );
expect( is_wp_error( $eq->renew( $admin, $sl['lease'] ) ), 'a unit over its maximum runtime loses its lease' );
$slow_unit = $e->units( $slow )[0];
expect( 'pending' === $slow_unit['state'] && 'The unit ran longer than its maximum runtime.' === $slow_unit['error'], 'and goes back to the queue as a failed attempt' );

// Progress reported with the renewals.
$progress = $e->enqueue( 'add', array( 'n' => 2 ) );
$pr       = $eq->claim( $admin, array( 'add' ) );
$eq->renew( $admin, $pr['lease'], array( $pr['units'][0]['id'] => 0.5 ) );
expect( abs( $e->job( $progress )['progress'] - 0.25 ) < 0.001, 'job progress includes the running units' );

// Dependencies.
$dep_a = $e->enqueue( 'chain', array( 'n' => 1 ) );
$dep_b = $e->enqueue( 'chain', array( 'n' => 1 ), array( 'after' => array( $dep_a ) ) );
expect( 'waiting' === $e->job( $dep_b )['state'], 'a dependent job waits' );
$ca = $eq->claim( $admin, array( 'chain' ) );
expect( 1 === count( $ca['units'] ) && $dep_a === $ca['units'][0]['job_id'], 'only the dependency runs' );
$finish( $ca );
expect( 'completed' === $e->job( $dep_a )['state'] && 'active' === $e->job( $dep_b )['state'], 'the dependent job starts when its dependency completes' );
$dep_c = $e->enqueue( 'chain', array( 'n' => 1 ) );
$dep_d = $e->enqueue( 'chain', array( 'n' => 1 ), array( 'after' => array( $dep_c ) ) );
$e->control( $dep_c, 'cancel' );
expect( 'failed' === $e->job( $dep_d )['state'], 'a job fails when its dependency is cancelled' );
expect( 1 === $e->job( $dep_c )['units']['skipped'] && 0 === $e->job( $dep_c )['units']['pending'] && 1.0 === (float) $e->job( $dep_c )['progress'], 'the units of a cancelled job are skipped' );
expect( is_wp_error( $e->enqueue( 'chain', array( 'n' => 1 ), array( 'after' => array( $dep_c ) ) ) ), 'no new job can depend on a cancelled one' );

// Stages: the second stage is planned from the results of the first.
$staged = $e->enqueue( 'staged', array() );
$st1    = $eq->claim( $admin, array( 'staged' ) );
expect( 2 === count( $st1['units'] ) && false === $e->job( $staged )['planned'], 'first stage' );
$finish( $st1 );
$st2 = $eq->claim( $admin, array( 'staged' ) );
expect( $st2 && 'total' === $st2['units'][0]['key'] && 23 === $st2['units'][0]['input']['a'], 'second stage planned from the first stage results' );
$finish( $st2 );
expect( 'completed' === $e->job( $staged )['state'] && 23 === $e->job( $staged )['result']['total'], 'staged job completed' );

// Pausing a task type.
$e->pause_type( 'prio' );
expect( null === $eq->claim( $admin, array( 'prio' ) ) && true === $e->types()['prio'], 'a paused type hands out nothing' );
$e->pause_type( 'prio', false );
expect( null !== $eq->claim( $admin, array( 'prio' ) ), 'resumed' );

// Repeatable jobs: idempotent per period.
$e->repeat(
	'hourly-later',
	'later',
	function () {
		return array( 'n' => 1 );
	},
	'hourly'
);
$repeat_hook = 'ww_kitteste_' . substr( md5( 'kit-test-e' ), 0, 6 ) . '_repeat_hourly-later';
expect( (bool) wp_next_scheduled( $repeat_hook ), 'repeatable job scheduled' );
$before = count( $e->jobs( array( 'type' => 'later' ) ) );
do_action( $repeat_hook );
do_action( $repeat_hook );
expect( $before + 1 === count( $e->jobs( array( 'type' => 'later' ) ) ), 'one job per period, even if the cron fires twice' );

// Workers and metrics.
$e->monitor()->touch( 'test-worker-1', $admin, array( 'types' => array( 'add' ), 'capabilities' => array( 'webgpu' => true, 'cores' => 8 ) ) );
$listed = $e->workers()['test-worker-1'] ?? null;
expect( $listed && true === $listed['capabilities']['webgpu'] && $admin->ID === $listed['user'], 'worker registry' );
$metrics = $e->metrics();
expect( isset( $metrics['types']['staged'] ) && $metrics['types']['staged']['completed_24h'] >= 3 && $metrics['workers'] >= 1, 'metrics by type' );

// Lifecycle events.
expect( array( $dep_a, $staged ) === array_column( $events['job_completed'] ?? array(), 0 ) && 1 === count( $events['job_cancelled'] ?? array() ), 'completed and cancelled events' );
expect( in_array( $dep_d, array_column( $events['job_failed'] ?? array(), 0 ), true ), 'failed event of the dependent job' );

// Affinity, job data, finalization as the owner, retry of a failed finalization, groups.
eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test fixture.
	'namespace KitTestB;
	final class Pair extends Worker\Task {
		public static $refuse = 0;
		public function type(): string { return "pair"; }
		public function options(): array { return array( "batch_units" => 10, "backoff" => 0 ); }
		public function affinity( $job ): string { return (string) ( $job["pair"] ?? "" ); }
		public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
			$units = array();
			for ( $i = 0; $i < $input["n"]; $i++ ) { $units[] = array( "key" => "u$i", "input" => array( "a" => $i, "b" => 1 ) ); }
			return array( "units" => $units, "next" => null, "data" => array( "pair" => $input["pair"], "note" => "kept" ) );
		}
		public function validate( $unit, $result, array $artifacts, $job ) { return true; }
		public function finalize( $job, Worker\Outputs $outputs, int $job_id ) {
			if ( self::$refuse > 0 ) { --self::$refuse; return new \WP_Error( "refused", "refused" ); }
			$inputs = 0; foreach ( $outputs->completed() as $unit ) { $inputs += $unit["input"]["a"] + $unit["result"]; }
			return array( "user" => get_current_user_id(), "data" => $outputs->data(), "inputs" => $inputs );
		}
	}'
);
$e->register( new KitTestB\Pair() );

$pa = $e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 2 ) );
$pb = $e->enqueue( 'pair', array( 'pair' => 'y', 'n' => 2 ) );
$pc = $e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 2 ) );
$b1 = $eq->claim( $admin, array( 'pair' ) );
expect( 'x' === $b1['affinity'] && 4 === count( $b1['units'] ) && ! array_diff( array_column( $b1['units'], 'job_id' ), array( $pa, $pc ) ), 'a batch only holds jobs of one affinity' );
$finish( $b1 );
$pd = $e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 1 ) );
$b2 = $eq->claim( $admin, array( 'pair' ), '', 'y' );
expect( 'y' === $b2['affinity'] && array( $pb ) === array_values( array_unique( array_column( $b2['units'], 'job_id' ) ) ), 'the worker gets its previous affinity first' );
$finish( $b2 );
$finish( $eq->claim( $admin, array( 'pair' ) ) );
expect( array( 'pair' => 'x', 'note' => 'kept' ) === $e->job( $pa )['result']['data'] && 'x' === $e->job( $pa )['affinity'], 'finalize() reads the data stored by plan()' );
expect( 4 === $e->job( $pa )['result']['inputs'], 'completed() gives each input with its result' );

$owner_id = wp_insert_user( array( 'user_login' => 'ww-kit-owner-test', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$po       = $e->enqueue( 'pair', array( 'pair' => 'z', 'n' => 1 ), array( 'owner' => $owner_id ) );
$finish( $eq->claim( $admin, array( 'pair' ) ) );
expect( $owner_id === $e->job( $po )['result']['user'] && $admin->ID === get_current_user_id(), 'finalize() runs as the job owner, then the user is restored' );
wp_delete_user( $owner_id );

KitTestB\Pair::$refuse = 1;
$pr = $e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 1 ) );
$finish( $eq->claim( $admin, array( 'pair' ) ) );
expect( 'failed' === $e->job( $pr )['state'], 'a refused finalization fails the job' );
$e->control( $pr, 'retry' );
expect( 'completed' === $e->job( $pr )['state'], 'retry finalizes it again' );

$g1 = $e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 2 ), array( 'group' => 'run-1' ) );
$g2 = $e->enqueue( 'pair', array( 'pair' => 'y', 'n' => 1 ), array( 'group' => 'run-1' ) );
$e->enqueue( 'pair', array( 'pair' => 'x', 'n' => 1 ), array( 'group' => 'run-2' ) );
$summary = $e->group( 'run-1' );
expect( 2 === $summary['jobs']['total'] && 3 === $summary['units']['pending'] && $summary['open'] && 'run-1' === $e->job( $g1 )['group'], 'group summary' );
expect( 2 === $e->control_group( 'run-1', 'pause' ) && 'paused' === $e->job( $g2 )['state'] && 'active' === $e->jobs( array( 'group' => 'run-2' ) )[0]['state'], 'pausing a group leaves the other groups' );
$e->control_group( 'run-1', 'cancel' );
$summary = $e->group( 'run-1' );
expect( 3 === $summary['units']['skipped'] && ! $summary['open'] && 1.0 === (float) $summary['progress'], 'a cancelled group is over, its units skipped' );
expect( is_wp_error( $e->control_group( 'run-1', 'explode' ) ), 'unknown group action' );

$e->uninstall();
expect( ! wp_next_scheduled( $repeat_hook ), 'uninstall removes the repeatable job schedule' );
