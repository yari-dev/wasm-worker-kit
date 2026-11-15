<?php
/**
 * Database tables of one host plugin's queue.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, upgrades and drops the tables. The schema version is stored per host plugin and checked on boot,
 * so a plugin update migrates by itself.
 */
final class Schema {
	/**
	 * Schema version of this kit release.
	 */
	const VERSION = 3;

	/** @var string */
	public $jobs;
	public $units;
	public $artifacts;

	/** @var string */
	private $version_option;

	public function __construct( string $prefix ) {
		global $wpdb;

		$this->jobs           = $wpdb->prefix . $prefix . '_jobs';
		$this->units          = $wpdb->prefix . $prefix . '_units';
		$this->artifacts      = $wpdb->prefix . $prefix . '_artifacts';
		$this->version_option = $prefix . '_schema';
	}

	/** Installs or upgrades the tables when needed. Cheap: one autoloaded option read. */
	public function maybe_upgrade() {
		if ( (int) get_option( $this->version_option ) !== self::VERSION ) {
			$this->install();
		}
	}

	/** Creates the tables. */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			array(
				"CREATE TABLE {$this->jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(64) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'active',
  priority int(11) NOT NULL DEFAULT 0,
  planned tinyint(1) unsigned NOT NULL DEFAULT 0,
  plan_cursor text NULL,
  plan_wait tinyint(1) unsigned NOT NULL DEFAULT 0,
  depends_on text NULL,
  group_key varchar(64) NOT NULL DEFAULT '',
  affinity varchar(64) NOT NULL DEFAULT '',
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  idem_hash char(64) NULL,
  input longtext NOT NULL,
  data longtext NULL,
  result longtext NULL,
  error text NULL,
  run_at datetime NULL,
  last_claim datetime NULL,
  started_at datetime NULL,
  finished_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idem_hash (idem_hash),
  KEY state (state,planned),
  KEY schedule (state,priority,last_claim),
  KEY finished (state,finished_at),
  KEY group_key (group_key)
) $charset;",
				"CREATE TABLE {$this->units} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  unit_key varchar(191) NOT NULL,
  key_hash char(40) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'pending',
  cost int(10) unsigned NOT NULL DEFAULT 1,
  input longtext NOT NULL,
  checkpoint longtext NULL,
  checkpoint_seq int(10) unsigned NOT NULL DEFAULT 0,
  result longtext NULL,
  completion_hash char(64) NULL,
  lease_hash char(64) NULL,
  lease_epoch int(10) unsigned NOT NULL DEFAULT 0,
  lease_expires datetime NULL,
  leased_at datetime NULL,
  worker_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  available_at datetime NULL,
  progress float NOT NULL DEFAULT 0,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  error text NULL,
  finished_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_key (job_id,key_hash),
  KEY claim (state,lease_expires),
  KEY job_state (job_id,state),
  KEY lease (lease_hash),
  KEY leased_at (leased_at),
  KEY finished_at (finished_at)
) $charset;",
				"CREATE TABLE {$this->artifacts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  lease_epoch int(10) unsigned NOT NULL,
  kept tinyint(1) unsigned NOT NULL DEFAULT 0,
  art_key varchar(191) NOT NULL,
  path varchar(255) NOT NULL,
  mime varchar(100) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY unit_key (unit_id,art_key),
  KEY job_id (job_id)
) $charset;",
			)
		);

		update_option( $this->version_option, self::VERSION, true );
	}

	/** Drops the tables (host plugin uninstall). */
	public function drop() {
		global $wpdb;

		foreach ( array( $this->artifacts, $this->units, $this->jobs ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( $this->version_option );
	}
}
