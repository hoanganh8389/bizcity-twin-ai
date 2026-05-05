<?php
/**
 * Bizcity KG-Hub — Cleanup Service
 *
 * PHASE-0.6.6 implementation (Wave B of TwinShell Learning Hub).
 *
 * Responsibilities:
 *   - Stage A "detect": find orphan triplet_queue / relations / entities and
 *                       hard-delete (queue) or soft-delete (rel/ent).
 *   - Stage B "reaper": permanently delete soft-deleted rows older than
 *                       GRACE_DAYS (30) so storage actually shrinks.
 *   - Audit:            every action lands in `bizcity_kg_cleanup_log` so the
 *                       Learning Hub can show "lần dọn rác cuối / 23 ghost…".
 *   - On-demand:        `Clean Now` button → fires HOOK_AD_HOC immediately.
 *   - Source-delete hook: pre-empts queue orphan when a source is deleted.
 *
 * Multisite + concurrency: per-blog cron (get_option) + transient lock
 * `bizcity_kg_cleanup_running` (TTL 60s) — second invocation early-returns.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Cleanup_Service {

	const HOOK_WEEKLY  = 'bizcity_kg_orphan_cleanup_weekly';
	const HOOK_AD_HOC  = 'bizcity_kg_orphan_cleanup_now';
	const GRACE_DAYS   = 30;
	const BATCH_SIZE   = 500;
	const SCAN_TIME_S  = 25;
	const LOCK_KEY     = 'bizcity_kg_cleanup_running';
	const LOCK_TTL_S   = 60;
	const OPT_LAST_RUN = 'bizcity_kg_cleanup_last_run';   // [run_id, ts, detected, reaped, errors, duration_ms]

	const SCHEMA_VERSION     = '1.0.0';
	const OPTION_VERSION_KEY = 'bizcity_kg_cleanup_db_version';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Bootstrap ───────────────────────────────────────────────────────

	public static function bind() {
		$self = self::instance();
		add_action( self::HOOK_WEEKLY, [ $self, 'run' ] );
		add_action( self::HOOK_AD_HOC, [ $self, 'run' ] );
		add_action( 'init',            [ __CLASS__, 'maybe_install_and_schedule' ], 7 );

		// Pre-emptive cleanup when source deleted (any cortex).
		// CONTRACT (PHASE-0.7 Wave B): every plugin/cortex that deletes a source MUST fire
		//   do_action( 'bizcity_kg_after_source_delete', (int) $source_id, (string) $scope_id );
		// AFTER physically removing the source row but BEFORE returning to caller.
		// TwinChat fires its own `bizcity_twinchat_after_source_delete`. Future cortex
		// (bzdoc/webchat/intent) should fire `bizcity_kg_after_source_delete` directly.
		// Without this, orphan triplet_queue + provenance rows wait until next weekly cron.
		add_action( 'bizcity_twinchat_after_source_delete', [ $self, 'on_source_deleted' ], 10, 2 );
		add_action( 'bizcity_kg_after_source_delete',       [ $self, 'on_source_deleted' ], 10, 2 );
	}

	public static function maybe_install_and_schedule() {
		self::instance()->maybe_install_log_table();
		if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
			$next_sun_3am = strtotime( 'next sunday 03:00' );
			if ( $next_sun_3am === false ) {
				$next_sun_3am = time() + 7 * DAY_IN_SECONDS;
			}
			wp_schedule_event( $next_sun_3am, 'weekly', self::HOOK_WEEKLY );
		}
	}

	public function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_cleanup_log';
	}

	public function maybe_install_log_table() {
		if ( get_option( self::OPTION_VERSION_KEY ) === self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tbl     = $this->table_log();
		$charset = $wpdb->get_charset_collate();

		// Suppress dbDelta noise (router can re-issue ALTERs).
		$prev_supp = $wpdb->suppress_errors( true );
		dbDelta( "CREATE TABLE {$tbl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id CHAR(36) NOT NULL,
			run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			trigger_kind VARCHAR(20) NOT NULL DEFAULT 'cron',
			triggered_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			stage VARCHAR(20) NOT NULL,
			target_table VARCHAR(64) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL,
			reason VARCHAR(120) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_run (run_id),
			KEY idx_run_at (run_at),
			KEY idx_target (target_table, target_id),
			KEY idx_stage (stage)
		) {$charset};" );
		$wpdb->suppress_errors( $prev_supp );

		update_option( self::OPTION_VERSION_KEY, self::SCHEMA_VERSION, false );
	}

	// ── Public surface ──────────────────────────────────────────────────

	/**
	 * Single entry-point for cron + ad-hoc + REST.
	 *
	 * @param array|null $opts { trigger_kind, triggered_by }
	 * @return array { run_id, ok, busy?, detected, reaped, errors, duration_ms }
	 */
	public function run( $opts = [] ) {
		if ( get_transient( self::LOCK_KEY ) ) {
			return [ 'ok' => false, 'busy' => true ];
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL_S );

		$run_id  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cl_', true );
		$start   = microtime( true );
		$trigger = is_array( $opts ) && ! empty( $opts['trigger_kind'] ) ? sanitize_key( $opts['trigger_kind'] ) : 'cron';
		$by      = is_array( $opts ) ? (int) ( $opts['triggered_by'] ?? 0 ) : 0;

		$detected = [ 'queue' => 0, 'relations' => 0, 'entities' => 0 ];
		$reaped   = [ 'relations' => 0, 'entities' => 0 ];
		$errors   = 0;

		try {
			$detected = $this->detect_orphans( $run_id, $trigger, $by );
			$reaped   = $this->reap_expired( $run_id, $trigger, $by );
		} catch ( \Throwable $e ) {
			$errors = 1;
			$this->log( $run_id, $trigger, $by, 'detect', '_run_', 0, 'skip', 'exception:' . substr( $e->getMessage(), 0, 80 ) );
		}

		delete_transient( self::LOCK_KEY );

		$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		$summary = [
			'ok'           => true,
			'run_id'       => $run_id,
			'trigger'      => $trigger,
			'triggered_by' => $by,
			'ts'           => time(),
			'detected'     => $detected,
			'reaped'       => $reaped,
			'errors'       => $errors,
			'duration_ms'  => $duration_ms,
		];
		update_option( self::OPT_LAST_RUN, $summary, false );
		return $summary;
	}

	/** Counts of soft-deleted rows still inside grace window. */
	public function pending_reap_counts() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return [ 'entities' => 0, 'relations' => 0 ];
		}
		$db = BizCity_KG_Database::instance();
		return [
			'entities'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_entities()}  WHERE deleted_at IS NOT NULL" ),
			'relations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->tbl_relations()} WHERE deleted_at IS NOT NULL" ),
		];
	}

	public function get_status() {
		$last = get_option( self::OPT_LAST_RUN, [] );
		$next = wp_next_scheduled( self::HOOK_WEEKLY );
		return [
			'last_run'        => is_array( $last ) ? $last : null,
			'next_scheduled'  => $next ? (int) $next : null,
			'pending_reap'    => $this->pending_reap_counts(),
			'grace_days'      => self::GRACE_DAYS,
			'busy'            => (bool) get_transient( self::LOCK_KEY ),
		];
	}

	public function get_log( $args = [] ) {
		global $wpdb;
		$tbl    = $this->table_log();
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where  = '1=1';
		$params = [];
		if ( ! empty( $args['stage'] ) ) {
			$where   .= ' AND stage = %s';
			$params[] = sanitize_key( (string) $args['stage'] );
		}
		if ( ! empty( $args['run_id'] ) ) {
			$where   .= ' AND run_id = %s';
			$params[] = (string) $args['run_id'];
		}
		$sql = "SELECT id, run_id, run_at, trigger_kind, triggered_by, stage, target_table, target_id, action, reason
		        FROM {$tbl}
		        WHERE {$where}
		        ORDER BY id DESC
		        LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	// ── Stage A — detect orphans ────────────────────────────────────────

	protected function detect_orphans( $run_id, $trigger, $by ) {
		global $wpdb;
		$out = [ 'queue' => 0, 'relations' => 0, 'entities' => 0 ];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return $out;
		}
		$db        = BizCity_KG_Database::instance();
		$queue_tbl = $db->tbl_triplet_queue();
		$pe_tbl    = $db->tbl_passage_entities();
		$pr_tbl    = $db->tbl_passage_relations();
		$ent_tbl   = $db->tbl_entities();
		$rel_tbl   = $db->tbl_relations();
		// Passage table — prefer source_chunks (0.6.5+), fall back to legacy passages.
		$psg_tbl = method_exists( $db, 'tbl_source_chunks' ) ? $db->tbl_source_chunks() : $db->tbl_passages();

		// A0 — provenance orphan (passage missing) → hard delete pe/pr rows. Must run
		// BEFORE A2/A3 so relations/entities lose phantom evidence and become detectable.
		$orphan_pe = (int) $wpdb->query(
			"DELETE pe FROM {$pe_tbl} pe
			 LEFT JOIN {$psg_tbl} p ON p.id = pe.passage_id
			 WHERE p.id IS NULL"
		);
		$orphan_pr = (int) $wpdb->query(
			"DELETE pr FROM {$pr_tbl} pr
			 LEFT JOIN {$psg_tbl} p ON p.id = pr.passage_id
			 WHERE p.id IS NULL"
		);
		if ( $orphan_pe > 0 ) {
			$this->log( $run_id, $trigger, $by, 'detect', 'kg_passage_entities',  0, 'hard_delete', 'passage_missing_x' . $orphan_pe );
		}
		if ( $orphan_pr > 0 ) {
			$this->log( $run_id, $trigger, $by, 'detect', 'kg_passage_relations', 0, 'hard_delete', 'passage_missing_x' . $orphan_pr );
		}

		// A1 — triplet queue orphan (passage missing) → hard delete + log.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id, q.passage_id
			 FROM {$queue_tbl} q
			 LEFT JOIN {$psg_tbl} p ON p.id = q.passage_id
			 WHERE p.id IS NULL AND q.status = 'pending'
			 LIMIT %d",
			self::BATCH_SIZE
		), ARRAY_A );
		foreach ( $rows ?: [] as $r ) {
			$id = (int) $r['id'];
			$wpdb->delete( $queue_tbl, [ 'id' => $id ] );
			$this->log( $run_id, $trigger, $by, 'detect', 'kg_triplet_queue', $id, 'hard_delete', 'passage_missing' );
			$out['queue']++;
		}

		// A2 — relations with no evidence → soft delete.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.id
			 FROM {$rel_tbl} r
			 LEFT JOIN {$pr_tbl} pr ON pr.relation_id = r.id
			 WHERE r.deleted_at IS NULL
			   AND r.status = 'approved'
			   AND pr.relation_id IS NULL
			 LIMIT %d",
			self::BATCH_SIZE
		), ARRAY_A );
		foreach ( $rows ?: [] as $r ) {
			$id = (int) $r['id'];
			$wpdb->update( $rel_tbl, [ 'deleted_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			$this->log( $run_id, $trigger, $by, 'detect', 'kg_relations', $id, 'soft_delete', 'no_evidence' );
			$out['relations']++;
		}

		// A3 — entities with no mention + no live relation → soft delete.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id
			 FROM {$ent_tbl} e
			 WHERE e.deleted_at IS NULL
			   AND e.status = 'approved'
			   AND NOT EXISTS (
			       SELECT 1 FROM {$pe_tbl} pe WHERE pe.entity_id = e.id
			   )
			   AND NOT EXISTS (
			       SELECT 1 FROM {$rel_tbl} r
			       WHERE (r.head_entity_id = e.id OR r.tail_entity_id = e.id)
			         AND r.deleted_at IS NULL
			   )
			 LIMIT %d",
			self::BATCH_SIZE
		), ARRAY_A );
		foreach ( $rows ?: [] as $r ) {
			$id = (int) $r['id'];
			$wpdb->update( $ent_tbl, [ 'deleted_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			$this->log( $run_id, $trigger, $by, 'detect', 'kg_entities', $id, 'soft_delete', 'no_mentions' );
			$out['entities']++;
		}

		return $out;
	}

	// ── Stage B — reap soft-deleted rows past grace ─────────────────────

	protected function reap_expired( $run_id, $trigger, $by ) {
		global $wpdb;
		$out = [ 'relations' => 0, 'entities' => 0 ];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return $out;
		}
		$db      = BizCity_KG_Database::instance();
		$ent_tbl = $db->tbl_entities();
		$rel_tbl = $db->tbl_relations();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_DAYS * DAY_IN_SECONDS );

		// Reap relations first (referencing entities can then go).
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$rel_tbl}
			 WHERE deleted_at IS NOT NULL AND deleted_at < %s
			 LIMIT %d",
			$cutoff, self::BATCH_SIZE
		) );
		foreach ( $ids ?: [] as $id ) {
			$id = (int) $id;
			$wpdb->delete( $rel_tbl, [ 'id' => $id ] );
			$this->log( $run_id, $trigger, $by, 'reaper', 'kg_relations', $id, 'hard_delete', 'grace_expired' );
			$out['relations']++;
		}

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$ent_tbl}
			 WHERE deleted_at IS NOT NULL AND deleted_at < %s
			 LIMIT %d",
			$cutoff, self::BATCH_SIZE
		) );
		foreach ( $ids ?: [] as $id ) {
			$id = (int) $id;
			$wpdb->delete( $ent_tbl, [ 'id' => $id ] );
			$this->log( $run_id, $trigger, $by, 'reaper', 'kg_entities', $id, 'hard_delete', 'grace_expired' );
			$out['entities']++;
		}

		return $out;
	}

	// ── Restore (within grace) ──────────────────────────────────────────

	public function restore( $target_table, $target_id ) {
		global $wpdb;
		$target_table = sanitize_key( (string) $target_table );
		$target_id    = (int) $target_id;
		if ( $target_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'invalid_args', 'target_table + target_id required' );
		}
		$db = BizCity_KG_Database::instance();
		$tbl = null;
		if ( $target_table === 'kg_entities' )  $tbl = $db->tbl_entities();
		if ( $target_table === 'kg_relations' ) $tbl = $db->tbl_relations();
		if ( ! $tbl ) {
			return new WP_Error( 'unsupported', 'Restore only supported for entities or relations' );
		}
		$wpdb->update( $tbl, [ 'deleted_at' => null ], [ 'id' => $target_id ] );
		$run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'rs_', true );
		$this->log( $run_id, 'manual', (int) get_current_user_id(), 'detect', $target_table, $target_id, 'restore', 'user_restore' );
		return true;
	}

	// ── Hook handler — pre-empt queue orphan on source delete ───────────

	public function on_source_deleted( $source_id, $scope_id = '' ) {
		global $wpdb;
		$source_id = (int) $source_id;
		if ( $source_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return;
		}
		$db        = BizCity_KG_Database::instance();
		$queue_tbl = $db->tbl_triplet_queue();
		$pe_tbl    = $db->tbl_passage_entities();
		$pr_tbl    = $db->tbl_passage_relations();
		$psg_tbl   = method_exists( $db, 'tbl_source_chunks' ) ? $db->tbl_source_chunks() : $db->tbl_passages();

		// Look up passages once (may be empty if source already deleted them).
		$pids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$psg_tbl} WHERE source_id = %d", $source_id
		) );
		$pids = array_filter( array_map( 'intval', $pids ?: [] ) );

		// Always also drop queue rows that reference passages no longer in DB
		// (cheap left-join hard-delete; matches A1 detection but runs eagerly).
		$wpdb->query(
			"DELETE q FROM {$queue_tbl} q
			 LEFT JOIN {$psg_tbl} p ON p.id = q.passage_id
			 WHERE p.id IS NULL"
		);

		if ( ! empty( $pids ) ) {
			$in = implode( ',', $pids );
			$wpdb->query( "DELETE FROM {$queue_tbl} WHERE passage_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$pr_tbl}    WHERE passage_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$pe_tbl}    WHERE passage_id IN ({$in})" );
		}

		// One audit row per source delete (no per-passage spam).
		$run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sd_', true );
		$this->log( $run_id, 'source_delete', (int) get_current_user_id(), 'detect', 'kg_source', $source_id, 'hard_delete', 'pre_empt_on_source_delete' );
	}

	// ── Internal logger ────────────────────────────────────────────────

	protected function log( $run_id, $trigger, $by, $stage, $target_table, $target_id, $action, $reason, $payload = null ) {
		global $wpdb;
		$wpdb->insert( $this->table_log(), [
			'run_id'       => (string) $run_id,
			'run_at'       => current_time( 'mysql', true ),
			'trigger_kind' => (string) $trigger,
			'triggered_by' => (int) $by,
			'stage'        => (string) $stage,
			'target_table' => (string) $target_table,
			'target_id'    => (int) $target_id,
			'action'       => (string) $action,
			'reason'       => (string) $reason,
			'payload'      => $payload ? wp_json_encode( $payload ) : null,
		] );
	}
}
