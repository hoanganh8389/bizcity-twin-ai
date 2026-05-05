<?php
/**
 * Bizcity TwinChat — Welcome Job Database (Sprint 5.1)
 *
 * Operational table for the AI-welcome-after-upload pipeline. Lifecycle events
 * are NOT logged here — they go through the canonical Twin Event Stream
 * (`event_type=welcome_job`) per R-EVT-* rules. This table only tracks
 * "currently scheduled / running" so cron + ajax lanes can dedupe and resume.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Welcome
 * @since 2026-04-30
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Welcome_Database {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_VERSION = 'bizcity_twinchat_welcome_db_version';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function table_jobs() {
		global $wpdb;
		return $wpdb->prefix . 'tc_welcome_jobs';
	}

	/** Run dbDelta if schema version changed. Cheap to call on every boot. */
	public function maybe_install() {
		$current = (string) get_option( self::OPTION_VERSION, '' );
		if ( $current === self::SCHEMA_VERSION ) {
			return;
		}
		$this->create_tables();
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = $this->table_jobs();

		$sql = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			source_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			message_id BIGINT UNSIGNED NULL,
			error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_notebook (notebook_id),
			KEY idx_source   (source_id),
			KEY idx_status   (status)
		) {$charset};";

		$prev = $wpdb->suppress_errors( true );
		dbDelta( $sql );
		$wpdb->suppress_errors( $prev );
	}

	public function insert( array $row ) {
		global $wpdb;
		$ok = $wpdb->insert( $this->table_jobs(), [
			'notebook_id' => (int) ( $row['notebook_id'] ?? 0 ),
			'source_id'   => isset( $row['source_id'] ) ? (int) $row['source_id'] : null,
			'user_id'     => (int) ( $row['user_id'] ?? 0 ),
			'status'      => (string) ( $row['status'] ?? 'queued' ),
			'created_at'  => current_time( 'mysql', true ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update( $job_id, array $fields ) {
		global $wpdb;
		$allow = [ 'status', 'message_id', 'error' ];
		$row   = [];
		foreach ( $allow as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$row[ $k ] = $fields[ $k ];
			}
		}
		if ( empty( $row ) ) {
			return 0;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		return (int) $wpdb->update( $this->table_jobs(), $row, [ 'id' => (int) $job_id ] );
	}

	public function get( $job_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_jobs()} WHERE id=%d",
			(int) $job_id
		), ARRAY_A );
		return $row ?: null;
	}

	/** Has there already been a non-failed welcome for this source? */
	public function source_already_welcomed( $notebook_id, $source_id ) {
		global $wpdb;
		$nb  = (int) $notebook_id;
		$src = (int) $source_id;
		if ( $nb <= 0 || $src <= 0 ) {
			return false;
		}
		$cnt = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_jobs()}
			  WHERE notebook_id=%d AND source_id=%d AND status IN ('queued','running','done')",
			$nb, $src
		) );
		return $cnt > 0;
	}
}
