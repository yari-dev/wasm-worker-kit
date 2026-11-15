<?php
/**
 * Concurrency test of the per-type limits: many processes claim at the same time, at most `max_leased`
 * units may be leased. See README.md, Tests.
 *
 *     wp eval-file tests/race.php /tmp/kit-b setup
 *     for i in 1 2 3 4 5 6 7 8; do wp eval-file tests/race.php /tmp/kit-b claim & done; wait
 *     wp eval-file tests/race.php /tmp/kit-b count     # leased: 2
 *     wp eval-file tests/race.php /tmp/kit-b clean
 *
 * @package WasmWorker
 */

// phpcs:disable -- Test fixture.
require_once $args[0] . '/php/autoload.php';
if ( ! class_exists( 'RaceTask' ) ) {
	eval( 'final class RaceTask extends KitTestB\Worker\Task {
		public function type(): string { return "race"; }
		public function options(): array { return array( "max_leased" => 2, "batch_units" => 1, "lease_seconds" => 300 ); }
		public function plan( $input, ?string $cursor, KitTestB\Worker\Outputs $outputs ): array { $u = array(); for ( $i = 0; $i < 40; $i++ ) { $u[] = array( "key" => "u$i", "input" => $i ); } return array( "units" => $u, "next" => null ); }
		public function validate( $u, $r, array $a, $j ) { return true; }
		public function finalize( $j, KitTestB\Worker\Outputs $o, int $id ) { return null; }
	}' );
}
$kit = new KitTestB\Worker\Kit( array( 'id' => 'kit-race' ) );
$kit->register( new RaceTask() );
$admin = get_user_by( 'id', get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
switch ( $args[1] ) {
	case 'setup': $kit->install(); $kit->enqueue( 'race', array() ); echo "setup\n"; break;
	case 'claim': $b = $kit->queue()->claim( $admin, array( 'race' ), 'race-' . getmypid() ); echo $b ? count( $b['units'] ) : 0, "\n"; break;
	case 'count': echo 'leased: ', $kit->metrics()['types']['race']['units']['leased'] ?? 0, "\n"; break;
	case 'clean': $kit->uninstall(); echo "clean\n"; break;
}
