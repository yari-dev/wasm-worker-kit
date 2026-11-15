<?php
/**
 * Smoke test of the kit, run inside WordPress:
 *
 *     node tools/vendor.mjs --to /tmp/kit-a --namespace "KitTestA\Worker"
 *     node tools/vendor.mjs --to /tmp/kit-b --namespace "KitTestB\Worker"
 *     wp eval-file tests/smoke.php /tmp/kit-a /tmp/kit-b
 *
 * Two vendored copies are loaded side by side, to check that they do not clash. Everything is removed at the end.
 *
 * @package WasmWorker
 */

if ( ! defined( 'ABSPATH' ) || count( $args ) < 2 ) {
	echo "Usage: wp eval-file tests/smoke.php <kit-a> <kit-b>\n";
	return;
}

require_once $args[0] . '/php/autoload.php';
require_once $args[1] . '/php/autoload.php';

$GLOBALS['ww_checks'] = 0;
function expect( $condition, string $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( "FAILED: $message" );
	}
	++$GLOBALS['ww_checks'];
}

/**
 * Defines a test task class in a namespace (the kit's namespace differs per copy).
 */
function define_task( string $ns, string $class, string $type, array $options, bool $flaky_finalize = false ) {
	$body = '
	namespace ' . $ns . ';
	final class ' . $class . ' extends Worker\Task {
		public static $finalized = 0;
		public static $fail_finalize = ' . ( $flaky_finalize ? 'true' : 'false' ) . ';
		public function type(): string { return "' . $type . '"; }
		public function options(): array { return ' . var_export( $options, true ) . '; }
		public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
			$first = (int) $cursor; $units = array();
			for ( $i = $first; $i < min( $input["n"], $first + 2 ); $i++ ) { $units[] = array( "key" => "u$i", "input" => array( "a" => $i, "b" => 10 ) ); }
			return array( "units" => $units, "next" => $first + 2 < $input["n"] ? (string) ( $first + 2 ) : null );
		}
		public function validate( $unit, $result, array $artifacts, $job ) {
			return $result === $unit["a"] + $unit["b"] ? true : new \WP_Error( "bad", "wrong sum" );
		}
		public function finalize( $job, Worker\Outputs $outputs, int $job_id ) {
			if ( self::$fail_finalize ) { self::$fail_finalize = false; throw new \RuntimeException( "temporary" ); }
			++self::$finalized; $sum = 0; foreach ( $outputs->results() as $r ) { $sum += $r; }
			return array( "sum" => $sum, "failed" => array_keys( $outputs->failures() ) );
		}
	}';
	eval( $body ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test fixture.
}

define_task( 'KitTestA', 'Add', 'add', array( 'batch_units' => 3, 'lease_seconds' => 60, 'max_attempts' => 2, 'backoff' => 0 ), true );
define_task( 'KitTestA', 'Strict', 'strict', array( 'on_failure' => 'fail_job', 'max_attempts' => 1, 'backoff' => 0 ) );
define_task( 'KitTestB', 'Add', 'add', array( 'backoff' => 0 ) );
define_task( 'KitTestB', 'Prio', 'prio', array( 'backoff' => 0 ) );
define_task( 'KitTestB', 'Limited', 'limited', array( 'max_leased' => 1 ) );
define_task( 'KitTestB', 'Rated', 'rated', array( 'rate_limit' => array( 'units' => 2, 'seconds' => 60 ) ) );
define_task( 'KitTestB', 'Slow', 'slow', array( 'max_runtime' => 30, 'backoff' => 0 ) );
define_task( 'KitTestB', 'Backed', 'backed', array( 'backoff' => 30 ) );
define_task( 'KitTestB', 'Later', 'later', array( 'backoff' => 0 ) );
define_task( 'KitTestB', 'Chain', 'chain', array( 'backoff' => 0 ) );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
expect( $admin instanceof WP_User, 'an administrator exists' );
wp_set_current_user( $admin->ID );

$a = new KitTestA\Worker\Kit( array( 'id' => 'kit-test-a' ) );
$a->register( new KitTestA\Add() )->register( new KitTestA\Strict() );
$b = new KitTestB\Worker\Kit( array( 'id' => 'kit-test-b' ) );
$b->register( new KitTestB\Add() );
$a->install();
$b->install();

$q = $a->queue();
global $wpdb;
$units = $wpdb->prefix . 'ww_kittesta_' . substr( md5( 'kit-test-a' ), 0, 6 ) . '_units';
$jobs  = $wpdb->prefix . 'ww_kittesta_' . substr( md5( 'kit-test-a' ), 0, 6 ) . '_jobs';
$expire = function ( $id ) use ( $wpdb, $units ) {
	$wpdb->update( $units, array( 'lease_expires' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $id ) );
};

// Two copies, two queues.
expect( $a->rest_namespace() !== $b->rest_namespace(), 'separate REST namespaces' );
$job_b = $b->enqueue( 'add', array( 'n' => 1 ) );
expect( is_int( $job_b ), 'kit B creates a job' );

// Idempotency and planning in steps.
$job = $a->enqueue( 'add', array( 'n' => 5 ), array( 'key' => 'job-1' ) );
expect( is_int( $job ), 'job created' );
expect( $a->enqueue( 'add', array( 'n' => 5 ), array( 'key' => 'job-1' ) ) === $job, 'same key returns the same job' );
expect( false === $a->job( $job )['planned'] && 2 === array_sum( $a->job( $job )['units'] ), 'first plan step only' );

// Batch claim (plans the rest of the job first).
$batch = $q->claim( $admin, array( 'add' ) );
expect( $batch && 3 === count( $batch['units'] ), 'a batch of 3 units' );
expect( true === $a->job( $job )['planned'] && 5 === array_sum( $a->job( $job )['units'] ), 'planning finished during the claim' );
expect( null === $q->claim( $admin, array( 'nothing' ) ), 'unknown types get nothing' );
$other = $q->claim( $admin, array( 'add' ) );
expect( $other && 2 === count( $other['units'] ), 'the rest goes to another claim' );
expect( ! array_intersect( wp_list_pluck( $batch['units'], 'id' ), wp_list_pluck( $other['units'], 'id' ) ), 'no unit is leased twice' );
expect( ! is_wp_error( $q->renew( $admin, $batch['lease'] ) ), 'renew' );

// Checkpoint with an artifact, then a dead browser.
$u0  = $batch['units'][0];
$tmp = wp_tempnam( 'kit' );
file_put_contents( $tmp, 'partial output' );
$receipt = $q->attach( $admin, $batch['lease'], $u0['id'], $u0['epoch'], 'part-1.bin', $tmp, hash_file( 'sha256', $tmp ), 'application/octet-stream' );
expect( ! is_wp_error( $receipt ) && 14 === $receipt['size'], 'artifact stored' );
expect( is_wp_error( $q->attach( $admin, $batch['lease'], $u0['id'], $u0['epoch'], 'bad.bin', $tmp, str_repeat( '0', 64 ), '' ) ), 'artifact with a wrong hash refused' );
expect( ! is_wp_error( $q->checkpoint( $admin, $batch['lease'], $u0['id'], $u0['epoch'], 1, array( 'step' => 1 ) ) ), 'checkpoint' );
expect( $q->checkpoint( $admin, $batch['lease'], $u0['id'], $u0['epoch'], 1, array( 'step' => 1 ) )['duplicate'], 'repeated checkpoint ignored' );

$expire( $u0['id'] );
$again = $q->claim( $admin, array( 'add' ) );
$u0b   = $again['units'][0] ?? null;
expect( $u0b && $u0b['id'] === $u0['id'] && $u0b['epoch'] > $u0['epoch'], 'expired unit leased again with a new epoch' );
expect( 1 === $u0b['checkpoint']['step'] && 'part-1.bin' === ( $u0b['artifacts'][0]['key'] ?? '' ), 'checkpoint and kept artifact handed over' );

// Fencing: the old lease cannot commit, the new one can, once.
$old = $q->complete( $admin, $batch['lease'], array( array( 'unit_id' => $u0['id'], 'epoch' => $u0['epoch'], 'completion_id' => 'old-attempt-1', 'result' => 10 ) ) );
expect( empty( $old[0]['accepted'] ), 'stale lease rejected' );
$done = $q->complete( $admin, $again['lease'], array( array( 'unit_id' => $u0['id'], 'epoch' => $u0b['epoch'], 'completion_id' => 'new-attempt-1', 'result' => 10 ) ) );
expect( ! empty( $done[0]['accepted'] ), 'current lease accepted' );
$dup = $q->complete( $admin, $again['lease'], array( array( 'unit_id' => $u0['id'], 'epoch' => $u0b['epoch'], 'completion_id' => 'new-attempt-1', 'result' => 10 ) ) );
expect( ! empty( $dup[0]['duplicate'] ), 'duplicate completion returns its receipt' );

// A wrong result is a failed attempt; a non-retryable failure fails the unit, the job goes on.
$u1  = $batch['units'][1];
$bad = $q->complete( $admin, $batch['lease'], array( array( 'unit_id' => $u1['id'], 'epoch' => $u1['epoch'], 'completion_id' => 'wrong-result-1', 'result' => 999 ) ) );
expect( 'ww_invalid_result' === ( $bad[0]['code'] ?? '' ), 'invalid result rejected' );
$retry = $q->claim( $admin, array( 'add' ) );
$u1b   = $retry['units'][0];
expect( $u1b['id'] === $u1['id'], 'rejected unit retried' );
expect( 'failed' === $q->fail( $admin, $retry['lease'], $u1b['id'], $u1b['epoch'], 'cannot work', false )['state'], 'non-retryable failure' );
expect( 'active' === $a->job( $job )['state'], 'job continues after a unit failed (continue policy)' );

// Pause stops new claims; leased units can still finish.
$u2 = $batch['units'][2];
expect( ! is_wp_error( $a->control( $job, 'pause' ) ), 'pause' );
foreach ( $other['units'] as $u ) {
	$expire( $u['id'] ); // That browser died.
}
expect( null === $q->claim( $admin, array( 'add' ) ), 'no claims while paused' );
$fin = $q->complete( $admin, $batch['lease'], array( array( 'unit_id' => $u2['id'], 'epoch' => $u2['epoch'], 'completion_id' => 'while-paused-1', 'result' => 12 ) ) );
expect( ! empty( $fin[0]['accepted'] ), 'a leased unit finishes while paused' );
expect( ! is_wp_error( $a->control( $job, 'resume' ) ), 'resume' );

// The last units; the first finalization throws, then is retried once, and applied once.
$last    = $q->claim( $admin, array( 'add' ) );
$results = array();
foreach ( $last['units'] as $u ) {
	$results[] = array( 'unit_id' => $u['id'], 'epoch' => $u['epoch'], 'completion_id' => 'last-' . $u['id'] . '-x', 'result' => 10 + (int) substr( $u['key'], 1 ) );
}
$q->complete( $admin, $last['lease'], $results );
expect( 'finalizing' === $a->job( $job )['state'] && 0 === KitTestA\Add::$finalized, 'interrupted finalization waits' );
$wpdb->update( $jobs, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $job ) );
$q->recover();
$q->recover();
$final = $a->job( $job );
expect( 'completed' === $final['state'] && 1 === KitTestA\Add::$finalized, 'finalization retried and applied once' );
expect( 10 + 12 + 13 + 14 === $final['result']['sum'] && array( 'u1' ) === $final['result']['failed'], 'result with the failure listed' );

// Retry the failed unit of a completed job.
expect( ! is_wp_error( $a->control( $job, 'retry' ) ) && 'active' === $a->job( $job )['state'], 'retry failed units' );

// Fail-job policy and cancel.
$strict = $a->enqueue( 'strict', array( 'n' => 2 ) );
$s      = $q->claim( $admin, array( 'strict' ) );
$q->fail( $admin, $s['lease'], $s['units'][0]['id'], $s['units'][0]['epoch'], 'broken' );
expect( 'failed' === $a->job( $strict )['state'], 'fail_job policy fails the job' );

$cancel = $a->enqueue( 'add', array( 'n' => 1 ) );
$c      = $q->claim( $admin, array( 'add' ) );
$cu     = null;
foreach ( $c['units'] as $u ) {
	if ( $u['job_id'] === $cancel ) {
		$cu = $u;
	}
}
$a->control( $cancel, 'cancel' );
if ( $cu ) {
	$late = $q->complete( $admin, $c['lease'], array( array( 'unit_id' => $cu['id'], 'epoch' => $cu['epoch'], 'completion_id' => 'late-result-1', 'result' => 10 ) ) );
	expect( empty( $late[0]['accepted'] ), 'cancelled job rejects late results' );
}

// Rerun with a key.
expect( $a->enqueue( 'add', array( 'n' => 5 ), array( 'key' => 'job-1', 'rerun' => true ) ) === $job, 'rerun does not apply to a job still active' );

// Eligibility.
$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) )[0] ?? null;
expect( ! $a->can_work( new WP_User( 0 ) ), 'visitors cannot work' );
if ( $subscriber ) {
	expect( ! $a->can_work( $subscriber ), 'subscribers cannot work by default' );
	$a->save_allowed_roles( array( 'subscriber' ) );
	expect( $a->can_work( $subscriber ), 'an allowed role can work' );
	$a->save_allowed_roles( array() );
}

// Kit B never saw kit A's jobs.
expect( 1 === count( $b->jobs() ), 'kit B has only its own job' );

/*
 * Regression tests of the review fixes, on a third queue (same classes as kit B, another ID).
 */
eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test fixture.
	'namespace KitTestB;
	final class Guarded extends Worker\Task {
		public static $blocked = array();
		public function type(): string { return "guarded"; }
		public function options(): array { return array( "batch_units" => 3, "backoff" => 0 ); }
		public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
			return array( "units" => array_map( function ( $k ) { return array( "key" => $k, "input" => array( "k" => $k ) ); }, $input["keys"] ), "next" => null );
		}
		public function authorize( \WP_User $user, $unit, $job ): bool { return ! in_array( $unit["k"], self::$blocked, true ); }
		public function validate( $unit, $result, array $artifacts, $job ) { return true; }
		public function finalize( $job, Worker\Outputs $outputs, int $job_id ) { return null; }
	}
	final class Unsendable extends Worker\Task {
		public function type(): string { return "unsendable"; }
		public function options(): array { return array( "max_attempts" => 2, "backoff" => 0 ); }
		public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array { return array( "units" => array( array( "key" => "only", "input" => 1 ) ), "next" => null ); }
		public function payload( $unit, $job ) { return new \WP_Error( "gone", "The source file is gone." ); }
		public function validate( $unit, $result, array $artifacts, $job ) { return true; }
		public function finalize( $job, Worker\Outputs $outputs, int $job_id ) { return array( "failed" => count( $outputs->failures() ) ); }
	}'
);

$c = new KitTestB\Worker\Kit( array( 'id' => 'kit-test-c' ) );
$c->register( new KitTestB\Add() )->register( new KitTestB\Guarded() )->register( new KitTestB\Unsendable() );
$c->install();
$cq       = $c->queue();
$c_tag    = $wpdb->prefix . 'ww_kittestc_' . substr( md5( 'kit-test-c' ), 0, 6 );
$c_units  = $c_tag . '_units';
$c_expire = function ( $id ) use ( $wpdb, $c_units ) {
	$wpdb->update( $c_units, array( 'lease_expires' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $id ) );
};

// Units the worker may not run do not block other work (they are older here).
KitTestB\Guarded::$blocked = array( 'g1', 'g2', 'g3' );
$guarded = $c->enqueue( 'guarded', array( 'keys' => array( 'g1', 'g2', 'g3', 'g4' ) ) );
$open    = $c->enqueue( 'add', array( 'n' => 1 ) );
$first   = $cq->claim( $admin, array( 'guarded', 'add' ) );
expect( $first && 'guarded' === $first['type'] && 'g4' === $first['units'][0]['key'], 'inaccessible older units are skipped' );
KitTestB\Guarded::$blocked = array( 'g1', 'g2', 'g3', 'g4' );
$cq->fail( $admin, $first['lease'], $first['units'][0]['id'], $first['units'][0]['epoch'], 'give back' );
$next = $cq->claim( $admin, array( 'guarded', 'add' ) );
expect( $next && 'add' === $next['type'], 'another type is served when one type is all inaccessible' );

// Renewal rechecks authorization and gives the unit back.
KitTestB\Guarded::$blocked = array();
$g = $cq->claim( $admin, array( 'guarded' ) );
KitTestB\Guarded::$blocked = array( 'g1' );
$renewed = $cq->renew( $admin, $g['lease'] );
expect( ! is_wp_error( $renewed ) && ! in_array( $g['units'][0]['id'], $renewed['units'], true ), 'renew drops a unit no longer authorized' );
expect( 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT state FROM $c_units WHERE id = %d", $g['units'][0]['id'] ) ), 'that unit is back in the queue' );
expect( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $c_units WHERE id = %d", $g['units'][0]['id'] ) ), 'its attempt was refunded (the user lost access, not the unit\'s fault)' );

// Writes are fenced against a cancelled job.
KitTestB\Guarded::$blocked = array();
$gu = $g['units'][1];
$c->control( $guarded, 'cancel' );
expect( is_wp_error( $cq->checkpoint( $admin, $g['lease'], $gu['id'], $gu['epoch'], 1, array( 'late' => true ) ) ), 'checkpoint refused after cancel' );
expect( is_wp_error( $cq->fail( $admin, $g['lease'], $gu['id'], $gu['epoch'], 'late' ) ), 'failure refused after cancel' );

// Uploads: every attempt has its own file, and a lease lost during the upload cannot replace anything.
$up   = $c->enqueue( 'add', array( 'n' => 1 ) );
$la   = $cq->claim( $admin, array( 'add' ) );
$ua   = null;
foreach ( $la['units'] as $u ) {
	if ( $u['job_id'] === $up ) {
		$ua = $u;
	}
}
$file = wp_tempnam( 'kit' );
file_put_contents( $file, 'attempt A' );
$cq->attach( $admin, $la['lease'], $ua['id'], $ua['epoch'], 'out.html', $file, hash_file( 'sha256', $file ), 'text/html' );
$path_a = $wpdb->get_var( $wpdb->prepare( "SELECT path FROM {$c_tag}_artifacts WHERE unit_id = %d", $ua['id'] ) );
expect( $path_a && false === strpos( basename( $path_a ), '.' ), 'staged files have no extension' );

$c_expire( $ua['id'] );
$lb = $cq->claim( $admin, array( 'add' ) );
$ub = $lb['units'][0];
file_put_contents( $file, 'attempt B' );
$cq->attach( $admin, $lb['lease'], $ub['id'], $ub['epoch'], 'out.html', $file, hash_file( 'sha256', $file ), 'text/html' );
$path_b = $wpdb->get_var( $wpdb->prepare( "SELECT path FROM {$c_tag}_artifacts WHERE unit_id = %d", $ub['id'] ) );
expect( $path_b !== $path_a && ! file_exists( $path_a ) && 'attempt B' === file_get_contents( $path_b ), 'a new attempt gets its own file, the replaced one is deleted' );
expect( is_wp_error( $cq->attach( $admin, $la['lease'], $ua['id'], $ua['epoch'], 'out.html', $file, hash_file( 'sha256', $file ), 'text/html' ) ), 'the old lease cannot upload' );

// The lease expires while the file is being stored: the commit is refused and nothing changes.
$expire_once = function ( $sql ) use ( &$expire_once, $ub, $c_expire ) {
	if ( false !== strpos( $sql, 'FOR UPDATE' ) ) {
		remove_filter( 'query', $expire_once );
		$c_expire( $ub['id'] );
	}
	return $sql;
};
add_filter( 'query', $expire_once );
file_put_contents( $file, 'too late' );
$late_upload = $cq->attach( $admin, $lb['lease'], $ub['id'], $ub['epoch'], 'out.html', $file, hash_file( 'sha256', $file ), 'text/html' );
$files       = glob( dirname( $path_b ) . '/*' );
expect( is_wp_error( $late_upload ) && 'ww_stale_lease' === $late_upload->get_error_code(), 'upload refused when the lease expired during storage' );
expect( 1 === count( $files ) && 'attempt B' === file_get_contents( $path_b ), 'the refused file was deleted, the current one kept' );

// A database error while planning does not move the plan forward.
$break_once = function ( $sql ) use ( &$break_once, $c_units ) {
	if ( 0 === strpos( $sql, "INSERT IGNORE INTO $c_units" ) ) {
		remove_filter( 'query', $break_once );
		return 'SELECT * FROM a_table_that_does_not_exist_ww';
	}
	return $sql;
};
$big = $c->enqueue( 'add', array( 'n' => 6 ) );
add_filter( 'query', $break_once );
$suppress = $wpdb->suppress_errors( true );
$cq->plan_pending( 1.0 );
$wpdb->suppress_errors( $suppress );
expect( false === $c->job( $big )['planned'] && 2 === array_sum( $c->job( $big )['units'] ), 'failed plan step rolled back' );
$cq->plan_pending( 1.0 );
expect( true === $c->job( $big )['planned'] && 6 === array_sum( $c->job( $big )['units'] ), 'plan step retried, no unit lost' );

// More than CLAIM_SCAN inaccessible units in front of an accessible one: later claims continue the scan.
$keys = array();
for ( $i = 0; $i < 1100; $i++ ) {
	$keys[] = "hidden-$i";
}
$keys[]                    = 'visible';
KitTestB\Guarded::$blocked = array_slice( $keys, 0, 1100 );
$many                      = $c->enqueue( 'guarded', array( 'keys' => $keys ) );
$found                     = null;
for ( $i = 0; $i < 3 && ! $found; $i++ ) {
	$found = $cq->claim( $admin, array( 'guarded' ) );
}
expect( $found && 'visible' === $found['units'][0]['key'] && $i <= 2, 'an accessible unit behind more inaccessible units than one claim scans is found by the next claim' );
$c->control( $many, 'cancel' );

// A payload that keeps failing uses up the attempts and fails the unit, instead of cycling forever.
$unsendable = $c->enqueue( 'unsendable', array() );
expect( null === $cq->claim( $admin, array( 'unsendable' ) ) && null === $cq->claim( $admin, array( 'unsendable' ) ), 'no work handed out without a payload' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT state, attempts FROM $c_units WHERE job_id = %d", $unsendable ) );
expect( 'failed' === $row->state && 2 === (int) $row->attempts, 'the unit failed after max_attempts payload errors' );
expect( null === $cq->claim( $admin, array( 'unsendable' ) ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $c_units WHERE job_id = %d", $unsendable ) ), 'and is not tried again' );
expect( 'completed' === $c->job( $unsendable )['state'] && 1 === $c->job( $unsendable )['result']['failed'], 'the job finished with the failure listed' );

$c->uninstall();

// Private files: refused in a public folder, accepted outside the WordPress tree.
$public_kit = new KitTestB\Worker\Kit( array( 'id' => 'kit-test-d', 'private_files' => true ) );
$public_kit->register( new KitTestB\Add() );
$public_kit->install();
expect( false === $public_kit->staging_status()['ok'], 'private files in the uploads folder are reported' );
$d_job  = $public_kit->enqueue( 'add', array( 'n' => 1 ) );
$d      = $public_kit->queue()->claim( $admin, array( 'add' ) );
$d_file = wp_tempnam( 'kit' );
file_put_contents( $d_file, 'secret' );
$refused = $public_kit->queue()->attach( $admin, $d['lease'], $d['units'][0]['id'], $d['units'][0]['epoch'], 'secret.pdf', $d_file, hash_file( 'sha256', $d_file ), 'application/pdf' );
expect( is_wp_error( $refused ) && 'ww_staging_public' === $refused->get_error_code(), 'upload refused while the staging folder is public' );
$public_kit->uninstall();

$outside     = trailingslashit( get_temp_dir() ) . 'ww-kit-test-' . wp_generate_password( 6, false );
$private_kit = new KitTestB\Worker\Kit( array( 'id' => 'kit-test-d', 'private_files' => true, 'staging_dir' => $outside ) );
$private_kit->register( new KitTestB\Add() );
$private_kit->install();
if ( ! KitTestB\Worker\Storage::is_public( $outside ) ) { // The temp folder may be inside WordPress on some hosts.
	expect( true === $private_kit->staging_status()['ok'], 'a staging folder outside the WordPress tree is accepted' );
	$d = $private_kit->queue()->claim( $admin, array( 'add' ) );
	if ( ! $d ) {
		$private_kit->enqueue( 'add', array( 'n' => 1 ) );
		$d = $private_kit->queue()->claim( $admin, array( 'add' ) );
	}
	$accepted = $private_kit->queue()->attach( $admin, $d['lease'], $d['units'][0]['id'], $d['units'][0]['epoch'], 'secret.pdf', $d_file, hash_file( 'sha256', $d_file ), 'application/pdf' );
	expect( ! is_wp_error( $accepted ) && 6 === $accepted['size'], 'upload accepted outside the web root' );
}
$private_kit->uninstall();
@rmdir( $outside ); // phpcs:ignore

// Release: a closing page gives its units back at once, fenced like every other write.
$r = new KitTestA\Worker\Kit( array( 'id' => 'kit-test-r' ) );
$r->register( new KitTestA\Add() );
$r->install();
$r->boot();
$rq       = $r->queue();
$r_units  = $wpdb->prefix . 'ww_kittestr_' . substr( md5( 'kit-test-r' ), 0, 6 ) . '_units';
$fence_of = function ( array $units ) {
	return array_map(
		function ( $u ) {
			return array(
				'unit_id' => $u['id'],
				'epoch'   => $u['epoch'],
			);
		},
		$units
	);
};
$r->enqueue( 'add', array( 'n' => 3 ) );
$rb = $rq->claim( $admin, array( 'add' ) );
expect( $rb && 3 === count( $rb['units'] ), 'release: a batch of 3 units' );
list( $r0, $r1, $r2 ) = $rb['units'];
expect( is_wp_error( $rq->release( $admin, str_repeat( 'f', 64 ), $fence_of( $rb['units'] ) ) ), 'release: a foreign token is refused' );
expect( is_wp_error( $rq->release( $admin, 'not-a-token', $fence_of( $rb['units'] ) ) ), 'release: a malformed token is refused' );
expect( is_wp_error( $rq->release( $admin, $rb['lease'], array( array( 'unit_id' => $r0['id'], 'epoch' => $r0['epoch'] + 1 ) ) ) ), 'release: a wrong epoch is refused' );
expect( is_wp_error( $rq->release( new WP_User( 0 ), $rb['lease'], $fence_of( $rb['units'] ) ) ), 'release: another user cannot release the lease' );
$wpdb->update( $r_units, array( 'lease_expires' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $r2['id'] ) );
$rel = $rq->release( $admin, $rb['lease'], $fence_of( $rb['units'] ) );
expect( ! is_wp_error( $rel ) && array( $r0['id'], $r1['id'] ) === $rel['released'], 'release: only units under an unexpired lease are given back (late release refused)' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT state, attempts, lease_hash, lease_expires, available_at FROM $r_units WHERE id = %d", $r0['id'] ) );
expect( 'pending' === $row->state && 0 === (int) $row->attempts && null === $row->lease_hash && null === $row->lease_expires && null === $row->available_at, 'release: pending, lease cleared, attempt refunded, no backoff' );
expect( is_wp_error( $rq->release( $admin, $rb['lease'], $fence_of( array( $r0, $r1 ) ) ) ), 'release: a repeated release changes nothing' );
$ra    = $rq->claim( $admin, array( 'add' ) );
$by_id = array_column( $ra['units'] ?? array(), null, 'id' );
expect( isset( $by_id[ $r0['id'] ], $by_id[ $r1['id'] ] ) && $by_id[ $r0['id'] ]['epoch'] > $r0['epoch'], 'release: released units are claimable at once, with a new epoch' );
expect( 1 === $by_id[ $r0['id'] ]['attempt'] && 2 === ( $by_id[ $r2['id'] ]['attempt'] ?? 0 ), 'release: the interrupted run is not counted, an expired lease is' );
$late = $rq->complete( $admin, $rb['lease'], array( array( 'unit_id' => $r0['id'], 'epoch' => $r0['epoch'], 'completion_id' => 'released-late-1', 'result' => 10 ) ) );
expect( empty( $late[0]['accepted'] ), 'release: the released lease cannot complete' );
rest_get_server();
$req = new WP_REST_Request( 'POST', '/' . $r->rest_namespace() . '/release' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( wp_json_encode( array( 'lease' => $ra['lease'], 'units' => $fence_of( $ra['units'] ) ) ) );
$res = rest_do_request( $req );
expect( 200 === $res->get_status() && count( $ra['units'] ) === count( $res->get_data()['released'] ?? array() ), 'release: REST route' );
expect( 409 === rest_do_request( $req )->get_status(), 'release: REST route answers 409 for a stale lease' );
$r->uninstall();

// Scheduling, limits, dependencies, stages and monitoring.
require __DIR__ . '/features.php';

// Pruning and uninstall.
$wpdb->query( "UPDATE $jobs SET updated_at = '2000-01-01 00:00:00' WHERE state IN ('completed','failed','cancelled')" );
expect( $q->prune( 30 ) >= 2, 'old jobs pruned' );
$a->uninstall();
$b->uninstall();
expect( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $units ) ), 'tables dropped' );

echo 'All ' . $GLOBALS['ww_checks'] . " checks passed.\n";
