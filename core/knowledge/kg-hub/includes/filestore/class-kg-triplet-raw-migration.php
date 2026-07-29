<?php
/**
 * Bizcity Twin AI — KG Triplet raw payload migration cron.
 *
 * Moves legacy `kg_triplet_queue.raw_llm_output` text blobs into
 * notebook-scoped JSONL files and clears SQL inline payload to reduce DB size.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-07-23
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Triplet_Raw_Migration {

	const HOOK       = 'bizcity_kg_triplet_raw_migration';
	const SCHEDULE   = 'bizcity_kg_5min';
	const BATCH_SIZE = 300;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function is_enabled() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — hard-cut follow-up: drain triplet raw LONGTEXT by default.
		$enabled = false;
		if ( class_exists( 'BizCity_Cron_Tier_Settings' ) && method_exists( 'BizCity_Cron_Tier_Settings', 'is_triplet_raw_migration_enabled' ) ) {
			$enabled = BizCity_Cron_Tier_Settings::is_triplet_raw_migration_enabled();
		} else {
			$enabled = (bool) get_option( 'bizcity_kg_v09_triplet_raw_migration_enabled', true );
		}
		return (bool) apply_filters( 'bizcity_kg_v09_triplet_raw_migration', $enabled );
	}

	public function bind() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bind tier-aware raw payload migration cron.
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( self::is_enabled() ) {
			if ( class_exists( 'BizCity_Cron_Tier_Settings' ) ) {
				BizCity_Cron_Tier_Settings::ensure_hook_interval( self::HOOK );
			} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
			BizCity_KG_Filestore_Backfill::wake_due_events();
			return;
		}

		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		BizCity_KG_Filestore_Backfill::wake_due_events();
	}

	public function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'KG Triplet Raw Migration (5 min)',
			);
		}
		return $schedules;
	}

	public function run() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — emit diagnostics notice per migration tick.
		$report = $this->run_once();
		update_option( 'bizcity_kg_triplet_raw_migration_last_run', array( 'at' => time(), 'report' => $report ), false );
		if ( empty( $report['skipped'] ) ) {
			do_action( 'bizcity_diagnostics_notice', 'kg_triplet_raw_migration', array(
				'blog_id'   => get_current_blog_id(),
				'report'    => $report,
				'timestamp' => time(),
			) );
		}
	}

	public function run_once() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bounded batch drain from SQL TEXT to JSONL.
		if ( ! self::is_enabled() ) {
			return array( 'migrated' => 0, 'errors' => 0, 'scrubbed_cache' => 0, 'skipped' => true );
		}

		$lock_key = $this->acquire_lock();
		if ( ! $lock_key ) {
			return array( 'migrated' => 0, 'errors' => 0, 'scrubbed_cache' => 0, 'skipped' => true, 'reason' => 'lock_active' );
		}

		try {
			$started_at = microtime( true );
			$report     = $this->migrate_batch( self::BATCH_SIZE );
			$report['elapsed_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
			return $report;
		} finally {
			delete_transient( $lock_key );
		}
	}

	private function acquire_lock() {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — avoid overlapping raw-migration writers per blog.
		$lock_key = 'bizcity_kg_triplet_raw_migrate_' . (int) get_current_blog_id();
		if ( get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );
		return $lock_key;
	}

	private function migrate_batch( $limit ) {
		global $wpdb;

		$db   = BizCity_KG_Database::instance();
		$tbl  = $db->tbl_triplet_queue();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, passage_id, raw_llm_output, status, created_at
			   FROM {$tbl}
			  WHERE raw_llm_output IS NOT NULL AND raw_llm_output<>''
			  ORDER BY id ASC LIMIT %d",
			(int) $limit
		), ARRAY_A ) ?: array();

		$out = array( 'migrated' => 0, 'errors' => 0, 'scrubbed_cache' => 0 );
		foreach ( $rows as $row ) {
			$result = $this->migrate_row( $tbl, $row );
			if ( 'migrated' === $result ) {
				$out['migrated']++;
			} elseif ( 'scrubbed_cache' === $result ) {
				$out['scrubbed_cache']++;
			} else {
				$out['errors']++;
			}
		}

		return $out;
	}

	private function migrate_row( $table, array $row ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — write raw payload to JSONL first, scrub SQL only after success.
		$queue_id = (int) $row['id'];
		$raw      = (string) ( $row['raw_llm_output'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return $this->clear_raw_llm_output( $table, $queue_id ) ? 'scrubbed_cache' : 'error';
		}

		if ( '[cache]' === $raw ) {
			return $this->clear_raw_llm_output( $table, $queue_id ) ? 'scrubbed_cache' : 'error';
		}

		$path = $this->jsonl_path( (int) $row['notebook_id'], (string) $row['created_at'] );
		if ( is_wp_error( $path ) ) {
			error_log( '[KG Triplet Raw Migration] path error: ' . $path->get_error_message() );
			return 'error';
		}

		$entry = array(
			'queue_id'        => $queue_id,
			'notebook_id'     => (int) $row['notebook_id'],
			'passage_id'      => (int) $row['passage_id'],
			'status'          => (string) ( $row['status'] ?? '' ),
			'raw_llm_output'  => $raw,
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'migrated_at'     => gmdate( 'c' ),
			'raw_sha1'        => sha1( $raw ),
		);

		$written = BizCity_KG_JSONL_Stream::append( $path, $entry );
		if ( is_wp_error( $written ) ) {
			error_log( '[KG Triplet Raw Migration] write error: ' . $written->get_error_message() );
			return 'error';
		}

		return $this->clear_raw_llm_output( $table, $queue_id ) ? 'migrated' : 'error';
	}

	private function jsonl_path( $notebook_id, $created_at ) {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — monthly triplet raw shards under notebook filestore tree.
		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( (int) $notebook_id );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		$dir = BizCity_KG_Notebook_Folder::instance()->triplet_queue_dir( 'notebooks', $uuid );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$ts = strtotime( (string) $created_at );
		if ( false === $ts || $ts <= 0 ) {
			$ts = time();
		}
		$month = gmdate( 'Y-m', $ts );
		return rtrim( $dir, '/\\' ) . '/' . $month . '.jsonl';
	}

	private function clear_raw_llm_output( $table, $queue_id ) {
		global $wpdb;
		$ok = $wpdb->update(
			$table,
			array( 'raw_llm_output' => null ),
			array( 'id' => (int) $queue_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $ok;
	}
}
