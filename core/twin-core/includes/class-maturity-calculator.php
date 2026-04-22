<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Maturity Calculator — 5-Dimension Twin AI Growth Score
 *
 * Reads from 29 EXISTING tables to compute 5 maturity scores + 1 overall.
 * NO new data tables — only 1 snapshot cache table for timeline history.
 *
 * Dimensions:
 *   1. Knowledge Intake  (/knowledge/ pillar)
 *   2. Compression        (/note/ pillar)
 *   3. Continuity         (/chat/ pillar)
 *   4. Execution          (/intent/ → goal pillar)
 *   5. Retrieval          (cross-cutting)
 *
 * @package  BizCity_Twin_Core
 * @version  1.0.0
 * @since    2026-03-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Maturity_Calculator {

	const DB_VERSION        = '2.0';  // ↑ Phase 2 — tool_stats + evidence backbone
	const DB_VERSION_OPTION = 'bizcity_maturity_db_ver';
	const CRON_HOOK         = 'bizcity_maturity_daily_snapshot';
	const CRON_AGGREGATE    = 'bizcity_maturity_aggregate_refresh';
	const DASHBOARD_CACHE_TTL = 300;

	/* ================================================================
	 * TABLE SETUP — Single snapshot cache table
	 * ================================================================ */

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twin_maturity_snapshots';
	}

	public static function aggregate_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twin_aggregate_metrics';
	}

	public static function ensure_table(): void {
		global $wpdb;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			// Verify both tables exist — multisite sites may have the option set but
			// the aggregate table missing if it was added in a later deploy.
			$agg = self::aggregate_table_name();
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$agg}'" ) === $agg ) {
				return;
			}
			// Fall through: option is current but aggregate table is absent → recreate.
		}

		$table   = self::table_name();
		$charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			snapshot_date   DATE NOT NULL,
			intake_score    TINYINT UNSIGNED DEFAULT 0,
			compression_score TINYINT UNSIGNED DEFAULT 0,
			continuity_score  TINYINT UNSIGNED DEFAULT 0,
			execution_score   TINYINT UNSIGNED DEFAULT 0,
			retrieval_score   TINYINT UNSIGNED DEFAULT 0,
			overall_score     TINYINT UNSIGNED DEFAULT 0,
			raw_data        LONGTEXT,
			created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_user_date (user_id, snapshot_date),
			KEY idx_date (snapshot_date)
		) {$charset};" );

		// Aggregate metrics table — rolling-up metrics for fast dashboard queries
		$agg_table = self::aggregate_table_name();
		dbDelta( "CREATE TABLE {$agg_table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			blog_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
			refresh_date    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			
			intake_projects      MEDIUMINT UNSIGNED DEFAULT 0,
			intake_sources       MEDIUMINT UNSIGNED DEFAULT 0,
			intake_tokens        INT UNSIGNED DEFAULT 0,
			intake_char_sources  MEDIUMINT UNSIGNED DEFAULT 0,
			intake_research      MEDIUMINT UNSIGNED DEFAULT 0,
			
			compression_notes            MEDIUMINT UNSIGNED DEFAULT 0,
			compression_ai_notes         MEDIUMINT UNSIGNED DEFAULT 0,
			compression_starred          MEDIUMINT UNSIGNED DEFAULT 0,
			compression_summarized       MEDIUMINT UNSIGNED DEFAULT 0,
			compression_completed_goals  MEDIUMINT UNSIGNED DEFAULT 0,
			compression_artifacts        MEDIUMINT UNSIGNED DEFAULT 0,
			compression_skeletons        MEDIUMINT UNSIGNED DEFAULT 0,
			
			continuity_active_days       SMALLINT UNSIGNED DEFAULT 0,
			continuity_events            MEDIUMINT UNSIGNED DEFAULT 0,
			continuity_avg_recurrence    DECIMAL(6,4) DEFAULT 1.0,
			continuity_memories          MEDIUMINT UNSIGNED DEFAULT 0,
			continuity_type_diversity    SMALLINT UNSIGNED DEFAULT 0,
			continuity_sessions_with_memory MEDIUMINT UNSIGNED DEFAULT 0,
			
			execution_completed   MEDIUMINT UNSIGNED DEFAULT 0,
			execution_cancelled   MEDIUMINT UNSIGNED DEFAULT 0,
			execution_active      MEDIUMINT UNSIGNED DEFAULT 0,
			execution_expired     MEDIUMINT UNSIGNED DEFAULT 0,
			execution_avg_turns   DECIMAL(8,2) DEFAULT 0.0,
			execution_completion_rate DECIMAL(6,4) DEFAULT 0.0,
			execution_completed_tasks    MEDIUMINT UNSIGNED DEFAULT 0,
			execution_avg_satisfaction   DECIMAL(6,4) DEFAULT 0.0,
			execution_avg_goal_progress  DECIMAL(7,4) DEFAULT 100.0,
			
			retrieval_total_bot_msgs    MEDIUMINT UNSIGNED DEFAULT 0,
			retrieval_msgs_with_tool    MEDIUMINT UNSIGNED DEFAULT 0,
			retrieval_avg_importance    DECIMAL(6,4) DEFAULT 50.0,
			retrieval_projects_with_usage MEDIUMINT UNSIGNED DEFAULT 0,
			retrieval_avg_confidence    DECIMAL(6,4) DEFAULT 0.0,
			retrieval_tools_used        MEDIUMINT UNSIGNED DEFAULT 0,
			retrieval_total_tools       MEDIUMINT UNSIGNED DEFAULT 0,

			evidence_msg_sources        MEDIUMINT UNSIGNED DEFAULT 0,
			evidence_msg_source_chunks  MEDIUMINT UNSIGNED DEFAULT 0,
			evidence_msg_projects       MEDIUMINT UNSIGNED DEFAULT 0,
			evidence_msg_notes          MEDIUMINT UNSIGNED DEFAULT 0,

			capability_total_tools      MEDIUMINT UNSIGNED DEFAULT 0,
			capability_active_tools     MEDIUMINT UNSIGNED DEFAULT 0,
			capability_total_calls      INT UNSIGNED DEFAULT 0,
			capability_avg_success_rate DECIMAL(6,4) DEFAULT 0.0000,
			capability_avg_latency_ms   DECIMAL(10,2) DEFAULT 0.00,
			
			PRIMARY KEY (id),
			UNIQUE KEY idx_user_blog (user_id, blog_id),
			KEY idx_refresh (refresh_date)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/* ================================================================
	 * CRON — Daily snapshot
	 * ================================================================ */

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_AGGREGATE ) ) {
			wp_schedule_event( strtotime( 'now +5 minutes' ), 'hourly', self::CRON_AGGREGATE );
		}
	}

	public static function cron_save_snapshots(): void {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'bizcity_webchat_sessions';

		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$table_sessions} WHERE user_id > 0"
		);

		foreach ( $user_ids as $uid ) {
			self::save_daily_snapshot( (int) $uid );
		}
	}

	public static function cron_refresh_all_aggregates(): void {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'bizcity_webchat_sessions';

		// Guard: skip blogs that don't have the required tables
		$required_tables = [
			$wpdb->prefix . 'bizcity_webchat_sources',
			$wpdb->prefix . 'bizcity_memory_notes',
		];
		foreach ( $required_tables as $tbl ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( ! $exists ) {
				return; // Tables not created for this blog — skip silently
			}
		}

		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$table_sessions} WHERE user_id > 0"
		);

		foreach ( $user_ids as $uid ) {
			self::refresh_aggregate_metrics( (int) $uid );
		}
	}

	public static function save_daily_snapshot( int $user_id ): void {
		global $wpdb;
		$table = self::table_name();
		$today = current_time( 'Y-m-d' );

		$scores = self::get_current_scores( $user_id );

		$wpdb->replace( $table, [
			'user_id'           => $user_id,
			'snapshot_date'     => $today,
			'intake_score'      => $scores['intake'],
			'compression_score' => $scores['compression'],
			'continuity_score'  => $scores['continuity'],
			'execution_score'   => $scores['execution'],
			'retrieval_score'   => $scores['retrieval'],
			'overall_score'     => $scores['overall'],
			'raw_data'          => wp_json_encode( $scores['raw'] ),
			'created_at'        => current_time( 'mysql' ),
		] );

		// Also refresh aggregate metrics
		self::refresh_aggregate_metrics( $user_id );
	}

	/* ================================================================
	 * AGGREGATE METRICS — Rolling-up skeleton data from 5 dimensions for fast queries
	 * ================================================================ */

	public static function refresh_aggregate_metrics( int $user_id ): void {
		global $wpdb;
		$table = self::aggregate_table_name();
		$p     = $wpdb->prefix . 'bizcity_';

		// Fresh calcs from all 5 dimensions
		$intake      = self::calc_intake( $user_id );
		$compression = self::calc_compression( $user_id );
		$continuity  = self::calc_continuity( $user_id );
		$execution   = self::calc_execution( $user_id );
		$retrieval   = self::calc_retrieval( $user_id );
		$evidence    = self::calc_evidence_backbone( $user_id );
		$capability  = self::calc_capability_backbone( $user_id );

		$blog_id = get_current_blog_id();

		$wpdb->replace( $table, [
			'user_id'  => $user_id,
			'blog_id'  => $blog_id,
			'refresh_date' => current_time( 'mysql' ),

			// Intake metrics
			'intake_projects'     => $intake['projects'] ?? 0,
			'intake_sources'      => $intake['sources'] ?? 0,
			'intake_tokens'       => $intake['tokens'] ?? 0,
			'intake_char_sources' => $intake['char_sources'] ?? 0,
			'intake_research'     => $intake['research'] ?? 0,

			// Compression metrics
			'compression_notes'            => $compression['notes'] ?? 0,
			'compression_ai_notes'         => $compression['ai_notes'] ?? 0,
			'compression_starred'          => $compression['starred'] ?? 0,
			'compression_summarized'       => $compression['summarized'] ?? 0,
			'compression_completed_goals'  => $compression['completed_goals'] ?? 0,
			'compression_artifacts'        => $compression['artifacts'] ?? 0,
			'compression_skeletons'        => $compression['skeletons'] ?? 0,

			// Continuity metrics
			'continuity_active_days'       => $continuity['active_days'] ?? 0,
			'continuity_events'            => $continuity['events'] ?? 0,
			'continuity_avg_recurrence'    => $continuity['avg_recurrence'] ?? 1.0,
			'continuity_memories'          => $continuity['memories'] ?? 0,
			'continuity_type_diversity'    => $continuity['type_diversity'] ?? 0,
			'continuity_sessions_with_memory' => $continuity['sessions_with_memory'] ?? 0,

			// Execution metrics
			'execution_completed'          => $execution['completed'] ?? 0,
			'execution_cancelled'          => $execution['cancelled'] ?? 0,
			'execution_active'             => $execution['active'] ?? 0,
			'execution_expired'            => $execution['expired'] ?? 0,
			'execution_avg_turns'          => $execution['avg_turns'] ?? 0.0,
			'execution_completion_rate'    => $execution['completion_rate'] ?? 0.0,
			'execution_completed_tasks'    => $execution['completed_tasks'] ?? 0,
			'execution_avg_satisfaction'   => $execution['avg_satisfaction'] ?? 0.0,
			'execution_avg_goal_progress'  => $execution['avg_goal_progress'] ?? 100.0,

			// Retrieval metrics
			'retrieval_total_bot_msgs'     => $retrieval['total_bot_msgs'] ?? 0,
			'retrieval_msgs_with_tool'     => $retrieval['msgs_with_tool'] ?? 0,
			'retrieval_avg_importance'     => $retrieval['avg_importance'] ?? 50.0,
			'retrieval_projects_with_usage' => $retrieval['projects_with_usage'] ?? 0,
			'retrieval_avg_confidence'     => $retrieval['avg_confidence'] ?? 0.0,
			'retrieval_tools_used'         => $retrieval['tools_used'] ?? 0,
			'retrieval_total_tools'        => $retrieval['total_tools'] ?? 0,

			// Evidence backbone (Priority 2)
			'evidence_msg_sources'         => $evidence['msg_sources'] ?? 0,
			'evidence_msg_source_chunks'   => $evidence['msg_source_chunks'] ?? 0,
			'evidence_msg_projects'        => $evidence['msg_projects'] ?? 0,
			'evidence_msg_notes'           => $evidence['msg_notes'] ?? 0,

			// Capability backbone (Priority 2)
			'capability_total_tools'       => $capability['total_tools'] ?? 0,
			'capability_active_tools'      => $capability['active_tools'] ?? 0,
			'capability_total_calls'       => $capability['total_calls'] ?? 0,
			'capability_avg_success_rate'  => $capability['avg_success_rate'] ?? 0.0,
			'capability_avg_latency_ms'    => $capability['avg_latency_ms'] ?? 0.0,
		] );
	}

	/* ================================================================
	 * AJAX ENDPOINTS
	 * ================================================================ */

	public static function register_ajax(): void {
		add_action( 'wp_ajax_bizcity_twin_maturity_data', [ __CLASS__, 'ajax_maturity_data' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_detail', [ __CLASS__, 'ajax_maturity_detail' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_save', [ __CLASS__, 'ajax_maturity_save' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_inline_save', [ __CLASS__, 'ajax_maturity_inline_save' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_export', [ __CLASS__, 'ajax_maturity_export' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_import', [ __CLASS__, 'ajax_maturity_import' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_upload_source', [ __CLASS__, 'ajax_upload_source' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_embed_source', [ __CLASS__, 'ajax_embed_source' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_delete_source', [ __CLASS__, 'ajax_delete_source' ] );
		add_action( 'wp_ajax_bizcity_twin_maturity_add_url_source', [ __CLASS__, 'ajax_add_url_source' ] );
	}

	private static function get_cache_version( int $user_id ): string {
		$version = (string) get_user_meta( $user_id, 'bizcity_maturity_cache_ver', true );
		return $version !== '' ? $version : '1';
	}

	private static function get_dashboard_cache_key( int $user_id, ?string $version = null ): string {
		$version = null === $version ? self::get_cache_version( $user_id ) : $version;
		return 'bizcity_maturity_data_' . get_current_blog_id() . '_' . $user_id . '_' . md5( $version );
	}

	public static function invalidate_dashboard_cache( int $user_id ): void {
		$old_version = self::get_cache_version( $user_id );
		delete_transient( self::get_dashboard_cache_key( $user_id, $old_version ) );
		update_user_meta( $user_id, 'bizcity_maturity_cache_ver', (string) microtime( true ) );

		// Refresh aggregate metrics immediately when UI data changes
		self::refresh_aggregate_metrics( $user_id );
	}

	private static function ensure_today_snapshot( int $user_id, ?array $scores = null ): void {
		global $wpdb;
		$table = self::table_name();
		$today = current_time( 'Y-m-d' );

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND snapshot_date = %s",
			$user_id,
			$today
		) );

		if ( $exists > 0 ) {
			return;
		}

		if ( null === $scores ) {
			self::save_daily_snapshot( $user_id );
			return;
		}

		$wpdb->replace( $table, [
			'user_id'           => $user_id,
			'snapshot_date'     => $today,
			'intake_score'      => $scores['intake'],
			'compression_score' => $scores['compression'],
			'continuity_score'  => $scores['continuity'],
			'execution_score'   => $scores['execution'],
			'retrieval_score'   => $scores['retrieval'],
			'overall_score'     => $scores['overall'],
			'raw_data'          => wp_json_encode( $scores['raw'] ),
			'created_at'        => current_time( 'mysql' ),
		] );
	}

	private static function build_dashboard_payload( int $user_id ): array {
		$scores = self::get_current_scores( $user_id );
		$payload = [
			'scores'    => $scores,
			'stats'     => self::get_raw_stats( $user_id ),
			'timeline'  => self::get_timeline( $user_id, 30 ),
			'growth'    => self::get_knowledge_growth( $user_id, 30 ),
			'execution' => self::get_daily_execution( $user_id, 30 ),
			'wave'      => self::get_knowledge_wave( $user_id ),
		];

		self::ensure_today_snapshot( $user_id, $scores );

		return $payload;
	}

	public static function ajax_maturity_data(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$cache_key = self::get_dashboard_cache_key( $user_id );
		$payload   = get_transient( $cache_key );

		if ( false === $payload || ! is_array( $payload ) ) {
			$payload = self::build_dashboard_payload( $user_id );
			set_transient( $cache_key, $payload, self::DASHBOARD_CACHE_TTL );
		}

		wp_send_json_success( $payload );
	}

	/* ================================================================
	 * MAIN ENTRY — Get current scores (computed live)
	 * ================================================================ */

	public static function get_current_scores( int $user_id ): array {
		$intake      = self::calc_intake( $user_id );
		$compression = self::calc_compression( $user_id );
		$continuity  = self::calc_continuity( $user_id );
		$execution   = self::calc_execution( $user_id );
		$retrieval   = self::calc_retrieval( $user_id );

		$overall = (int) round(
			$intake['score']      * 0.20
			+ $compression['score'] * 0.20
			+ $continuity['score']  * 0.25
			+ $execution['score']   * 0.20
			+ $retrieval['score']   * 0.15
		);

		return [
			'intake'      => $intake['score'],
			'compression' => $compression['score'],
			'continuity'  => $continuity['score'],
			'execution'   => $execution['score'],
			'retrieval'   => $retrieval['score'],
			'overall'     => min( 100, $overall ),
			'raw'         => [
				'intake'      => $intake,
				'compression' => $compression,
				'continuity'  => $continuity,
				'execution'   => $execution,
				'retrieval'   => $retrieval,
			],
		];
	}

	/* ================================================================
	 * SCORE 1: Knowledge Intake — /knowledge/ pillar
	 * Tables: webchat_projects, webchat_sources, knowledge_sources, research_jobs
	 * ================================================================ */

	private static function calc_intake( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$projects = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_projects WHERE user_id = %d AND is_archived = 0",
			$user_id
		) );

		$src_table = $p . 'webchat_sources';
		$src = null;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$src_table}'" ) === $src_table ) {
			$src = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(chunk_count),0) AS chunks
				 FROM {$p}webchat_sources WHERE user_id = %d AND embedding_status = 'ready'",
				$user_id
			) );
		}
		$sources = $src ? (int) $src->cnt : 0;
		$tokens  = 0;

		// Character knowledge (shared across projects)
		$char_sources = 0;
		$char_table = $wpdb->prefix . 'bizcity_knowledge_sources';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$char_table}'" ) === $char_table ) {
			$char_sources = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$char_table} WHERE character_id IN
				 (SELECT character_id FROM {$p}webchat_projects WHERE user_id = %d AND character_id > 0)
				 AND status = 'ready'",
				$user_id
			) );
		}

		// Research jobs (deep research effort)
		$research = 0;
		$rj_table = $p . 'memory_research';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rj_table}'" ) === $rj_table ) {
			$research = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$rj_table} WHERE user_id = %d AND status = 'completed'",
				$user_id
			) );
		}

		$score = min( 100,
			$projects * 5
			+ $sources * 3
			+ (int) ( $tokens / 10000 )
			+ $char_sources * 2
			+ $research * 3
		);

		return [
			'score'        => $score,
			'projects'     => $projects,
			'sources'      => $sources,
			'tokens'       => $tokens,
			'char_sources' => $char_sources,
			'research'     => $research,
		];
	}

	/* ================================================================
	 * SCORE 2: Compression — /note/ pillar
	 * Tables: memory_notes, webchat_sessions, memory_rolling, studio_outputs, project_skeletons
	 * ================================================================ */

	private static function calc_compression( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$notes_table = $p . 'memory_notes';
		$notes_row = null;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$notes_table}'" ) === $notes_table ) {
			$notes_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(created_by='ai') AS ai_notes,
					SUM(is_starred=1) AS starred,
					COUNT(DISTINCT note_type) AS type_variety
				 FROM {$p}memory_notes WHERE user_id = %d",
				$user_id
			) );
		}
		$total_notes  = $notes_row ? (int) $notes_row->total : 0;
		$ai_notes     = $notes_row ? (int) $notes_row->ai_notes : 0;
		$starred      = $notes_row ? (int) $notes_row->starred : 0;
		$type_variety = $notes_row ? (int) $notes_row->type_variety : 0;

		$summarized = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_sessions
			 WHERE user_id = %d AND rolling_summary IS NOT NULL AND rolling_summary != ''",
			$user_id
		) );

		$completed_goals = 0;
		$rm_table = $p . 'memory_rolling';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rm_table}'" ) === $rm_table ) {
			$completed_goals = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$rm_table} WHERE user_id = %d AND status = 'completed'",
				$user_id
			) );
		}

		$artifacts = 0;
		$so_table = $p . 'webchat_studio_outputs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$so_table}'" ) === $so_table ) {
			$artifacts = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$so_table} WHERE user_id = %d",
				$user_id
			) );
		}

		$skeletons = 0;
		$sk_table = $p . 'webchat_project_skeletons';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sk_table}'" ) === $sk_table ) {
			$skeletons = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$sk_table}
				 WHERE project_id IN (SELECT project_id FROM {$p}webchat_projects WHERE user_id = %d)",
				$user_id
			) );
		}

		$score = min( 100,
			$total_notes * 2
			+ $ai_notes * 1
			+ $starred * 3
			+ $summarized * 1
			+ $completed_goals * 5
			+ $artifacts * 4
			+ $skeletons * 5
			+ $type_variety * 3
		);

		return [
			'score'           => $score,
			'notes'           => $total_notes,
			'ai_notes'        => $ai_notes,
			'starred'         => $starred,
			'summarized'      => $summarized,
			'completed_goals' => $completed_goals,
			'artifacts'       => $artifacts,
			'skeletons'       => $skeletons,
		];
	}

	/* ================================================================
	 * SCORE 3: Continuity — /chat/ pillar
	 * Tables: webchat_sessions, memory_episodic, memory_users, memory_session, character_conversations
	 * ================================================================ */

	private static function calc_continuity( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$sess = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total_sessions,
				DATEDIFF(MAX(started_at), MIN(started_at)) AS active_days,
				COALESCE(SUM(message_count),0) AS total_messages
			 FROM {$p}webchat_sessions WHERE user_id = %d AND status != 'archived'",
			$user_id
		) );
		$active_days = $sess ? max( 0, (int) $sess->active_days ) : 0;

		// Episodic memory
		$events = 0;
		$avg_recurrence = 0;
		$avg_importance = 0;
		$em_table = $p . 'memory_episodic';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$em_table}'" ) === $em_table ) {
			$ep = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS events, COALESCE(AVG(times_seen),0) AS avg_rec,
					COALESCE(AVG(importance),0) AS avg_imp
				 FROM {$em_table} WHERE user_id = %d",
				$user_id
			) );
			$events         = $ep ? (int) $ep->events : 0;
			$avg_recurrence = $ep ? (float) $ep->avg_rec : 0;
			$avg_importance = $ep ? (float) $ep->avg_imp : 0;
		}

		// User memory (long-term, cross-session)
		$memories       = 0;
		$type_diversity = 0;
		$um_table = $wpdb->prefix . 'bizcity_memory_users';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$um_table}'" ) === $um_table ) {
			$um = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COUNT(DISTINCT memory_type) AS types
				 FROM {$um_table} WHERE user_id = %d",
				$user_id
			) );
			$memories       = $um ? (int) $um->cnt : 0;
			$type_diversity = $um ? (int) $um->types : 0;
		}

		// Session memory cross-references
		$sessions_with_memory = 0;
		$wm_table = $p . 'memory_session';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wm_table}'" ) === $wm_table ) {
			$sessions_with_memory = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT session_id) FROM {$wm_table}
				 WHERE user_id = %d AND times_seen > 1",
				$user_id
			) );
		}

		$score = min( 100,
			min( $active_days, 30 ) * 1
			+ $events * 2
			+ (int) ( $avg_recurrence * 3 )
			+ $memories * 1
			+ $type_diversity * 5
			+ $sessions_with_memory * 2
			+ (int) ( $avg_importance / 10 )
		);

		return [
			'score'          => $score,
			'active_days'    => $active_days,
			'events'         => $events,
			'avg_recurrence' => round( $avg_recurrence, 1 ),
			'memories'       => $memories,
			'type_diversity' => $type_diversity,
			'sessions_with_memory' => $sessions_with_memory,
		];
	}

	/* ================================================================
	 * SCORE 4: Execution — /intent/ → goal pillar
	 * Tables: intent_conversations, webchat_tasks, memory_rolling, intent_classify_cache
	 * ================================================================ */

	private static function calc_execution( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		// Intent conversation outcomes (last 30 days)
		$completed  = 0;
		$cancelled  = 0;
		$active     = 0;
		$expired    = 0;
		$avg_turns  = 0;
		$ic_table = $p . 'intent_conversations';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ic_table}'" ) === $ic_table ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt, AVG(turn_count) AS avg_turns
				 FROM {$ic_table}
				 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY status",
				$user_id
			) );
			foreach ( $rows as $r ) {
				$s = strtoupper( $r->status );
				if ( $s === 'COMPLETED' ) { $completed = (int) $r->cnt; $avg_turns = (float) $r->avg_turns; }
				elseif ( $s === 'CANCELLED' ) { $cancelled = (int) $r->cnt; }
				elseif ( $s === 'ACTIVE' )    { $active = (int) $r->cnt; }
				elseif ( $s === 'EXPIRED' )   { $expired = (int) $r->cnt; }
			}
		}

		// Task completion
		$completed_tasks = 0;
		$tk_table = $p . 'webchat_tasks';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tk_table}'" ) === $tk_table ) {
			$completed_tasks = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tk_table}
				 WHERE user_id = %d AND task_status = 'completed'
				 AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$user_id
			) );
		}

		// User satisfaction from rolling memory
		$avg_satisfaction  = 0;
		$avg_goal_progress = 0;
		$rm_table = $p . 'memory_rolling';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rm_table}'" ) === $rm_table ) {
			$sat = $wpdb->get_row( $wpdb->prepare(
				"SELECT AVG(bot_satisfaction_score) AS sat, AVG(user_goal_score) AS goal
				 FROM {$rm_table} WHERE user_id = %d AND status = 'completed'",
				$user_id
			) );
			$avg_satisfaction  = $sat ? (float) $sat->sat : 0;
			$avg_goal_progress = $sat ? (float) $sat->goal : 0;
		}

		$total_intents   = $completed + $cancelled + $expired + $active;
		$completion_rate  = $total_intents > 0 ? $completed / $total_intents : 0;
		$efficiency       = max( 0, 10 - $avg_turns ) * 2;

		$score = min( 100, (int) round(
			$completion_rate * 50
			+ $efficiency
			+ $avg_satisfaction * 0.2
			+ $avg_goal_progress * 0.1
			+ $completed_tasks * 2
		) );

		return [
			'score'             => $score,
			'completed'         => $completed,
			'cancelled'         => $cancelled,
			'active'            => $active,
			'expired'           => $expired,
			'avg_turns'         => round( $avg_turns, 1 ),
			'completion_rate'   => round( $completion_rate, 2 ),
			'completed_tasks'   => $completed_tasks,
			'avg_satisfaction'  => round( $avg_satisfaction, 1 ),
			'avg_goal_progress' => round( $avg_goal_progress, 1 ),
		];
	}

	/* ================================================================
	 * SCORE 5: Retrieval — Cross-cutting
	 * Tables: webchat_messages, intent_prompt_logs, tool_registry
	 * ================================================================ */

	private static function calc_retrieval( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		// Bot messages with tool usage (last 30 days)
		$msgs = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN tool_name != '' AND tool_name IS NOT NULL THEN 1 ELSE 0 END) AS with_tool
			 FROM {$p}webchat_messages
			 WHERE user_id = %d AND message_from = 'bot'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
			$user_id
		) );
		$total_bot   = $msgs ? (int) $msgs->total : 0;
		$with_tool   = $msgs ? (int) $msgs->with_tool : 0;

		// importance_score column may not exist — skip entirely to avoid DB errors
		$avg_imp = 50; // default mid-range

		// Projects where sources are actually used in bot messages
		$projects_with_usage = 0;
		$src_table_r = $p . 'webchat_sources';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$src_table_r}'" ) === $src_table_r ) {
			$projects_with_usage = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT s.project_id)
				 FROM {$p}webchat_sources s
				 WHERE s.user_id = %d AND s.embedding_status = 'ready'
				   AND EXISTS (
					   SELECT 1 FROM {$p}webchat_messages m
					   WHERE m.project_id = s.project_id AND m.message_from = 'bot'
				   )",
				$user_id
			) );
		}

		// Mode classification confidence
		$avg_confidence = 0;
		$pl_table = $p . 'intent_prompt_logs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$pl_table}'" ) === $pl_table ) {
			$avg_confidence = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(mode_confidence) FROM {$pl_table}
				 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$user_id
			) );
		}

		// Tool adoption
		$total_tools = 0;
		$tr_table = $p . 'tool_registry';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tr_table}'" ) === $tr_table ) {
			$total_tools = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tr_table} WHERE active = 1" );
		}
		$tools_used = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT tool_name) FROM {$p}webchat_messages
			 WHERE user_id = %d AND tool_name != '' AND tool_name IS NOT NULL
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
			$user_id
		) );

		$tool_rate = $total_bot > 0 ? $with_tool / $total_bot : 0;
		$adoption  = $total_tools > 0 ? $tools_used / $total_tools : 0;

		$score = min( 100, (int) round(
			$tool_rate * 30
			+ $avg_imp * 0.3
			+ $projects_with_usage * 5
			+ $avg_confidence * 30
			+ $adoption * 20
		) );

		return [
			'score'              => $score,
			'total_bot_msgs'     => $total_bot,
			'msgs_with_tool'     => $with_tool,
			'avg_importance'     => round( $avg_imp, 1 ),
			'projects_with_usage' => $projects_with_usage,
			'avg_confidence'     => round( $avg_confidence, 3 ),
			'tools_used'         => $tools_used,
			'total_tools'        => $total_tools,
		];
	}

	/* ================================================================
	 * EVIDENCE BACKBONE — Message-linked evidence counts (Priority 2)
	 * Tables: webchat_message_sources, _source_chunks, _projects, _notes
	 * ================================================================ */

	private static function calc_evidence_backbone( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$result = [
			'msg_sources'       => 0,
			'msg_source_chunks' => 0,
			'msg_projects'      => 0,
			'msg_notes'         => 0,
		];

		$ms_table = $p . 'webchat_message_sources';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ms_table}'" ) === $ms_table ) {
			$result['msg_sources'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ms_table} ms
				 INNER JOIN {$p}webchat_messages m ON m.id = ms.message_id
				 WHERE m.user_id = %d",
				$user_id
			) );
		}

		$msc_table = $p . 'webchat_message_source_chunks';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$msc_table}'" ) === $msc_table ) {
			$result['msg_source_chunks'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$msc_table} msc
				 INNER JOIN {$p}webchat_messages m ON m.id = msc.message_id
				 WHERE m.user_id = %d",
				$user_id
			) );
		}

		$mp_table = $p . 'webchat_message_projects';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mp_table}'" ) === $mp_table ) {
			$result['msg_projects'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$mp_table} mp
				 INNER JOIN {$p}webchat_messages m ON m.id = mp.message_id
				 WHERE m.user_id = %d",
				$user_id
			) );
		}

		$mn_table = $p . 'webchat_message_notes';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mn_table}'" ) === $mn_table ) {
			$result['msg_notes'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$mn_table} mn
				 INNER JOIN {$p}webchat_messages m ON m.id = mn.message_id
				 WHERE m.user_id = %d",
				$user_id
			) );
		}

		return $result;
	}

	/* ================================================================
	 * CAPABILITY BACKBONE — Tool registry + tool_stats aggregate (Priority 2)
	 * Tables: tool_registry, tool_stats
	 * ================================================================ */

	private static function calc_capability_backbone( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$result = [
			'total_tools'      => 0,
			'active_tools'     => 0,
			'total_calls'      => 0,
			'avg_success_rate' => 0.0,
			'avg_latency_ms'   => 0.0,
		];

		$tr_table = $p . 'tool_registry';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tr_table}'" ) === $tr_table ) {
			$tr = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(active = 1) AS active FROM {$tr_table}" );
			if ( $tr ) {
				$result['total_tools']  = (int) $tr->total;
				$result['active_tools'] = (int) $tr->active;
			}
		}

		$ts_table = $p . 'tool_stats';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ts_table}'" ) === $ts_table
			&& $wpdb->get_var( "SHOW COLUMNS FROM {$ts_table} LIKE 'call_count'" ) ) {
			$ts = $wpdb->get_row(
				"SELECT SUM(call_count) AS total_calls,
					AVG(success_rate) AS avg_success,
					AVG(avg_latency_ms) AS avg_latency
				 FROM {$ts_table}
				 WHERE call_count > 0"
			);
			if ( $ts ) {
				$result['total_calls']      = (int) ( $ts->total_calls ?? 0 );
				$result['avg_success_rate'] = round( (float) ( $ts->avg_success ?? 0 ), 4 );
				$result['avg_latency_ms']   = round( (float) ( $ts->avg_latency ?? 0 ), 2 );
			}
		}

		return $result;
	}

	/* ================================================================
	 * RAW STATS — Quick counts for dashboard stats row
	 * ================================================================ */

	public static function get_raw_stats( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$projects = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_projects WHERE user_id = %d AND is_archived = 0", $user_id
		) );
		$sources = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_sources WHERE user_id = %d AND embedding_status = 'ready'", $user_id
		) );
		$notes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}memory_notes WHERE user_id = %d", $user_id
		) );
		$sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_sessions WHERE user_id = %d", $user_id
		) );

		$memories = 0;
		$um_table = $wpdb->prefix . 'bizcity_memory_users';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$um_table}'" ) === $um_table ) {
			$memories = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$um_table} WHERE user_id = %d", $user_id
			) );
		}

		$episodic = 0;
		$em_table = $p . 'memory_episodic';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$em_table}'" ) === $em_table ) {
			$episodic = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$em_table} WHERE user_id = %d", $user_id
			) );
		}

		$rolling = 0;
		$rm_table = $p . 'memory_rolling';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rm_table}'" ) === $rm_table ) {
			$rolling = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$rm_table} WHERE user_id = %d", $user_id
			) );
		}

		$knowledge = 0;
		$ch_table = $p . 'characters';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ch_table}'" ) === $ch_table ) {
			$knowledge = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ch_table} WHERE author_id = %d OR status = 'published'", $user_id
			) );
		}

		$goals_done = 0;
		$ic_table = $p . 'intent_conversations';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ic_table}'" ) === $ic_table ) {
			$goals_done = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ic_table} WHERE user_id = %d AND status = 'COMPLETED'", $user_id
			) );
		}

		$messages = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}webchat_messages WHERE user_id = %d", $user_id
		) );

		// Quick FAQ count
		$quickfaq = 0;
		$ks_table = $p . 'knowledge_sources';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ks_table}'" ) === $ks_table ) {
			$quickfaq = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ks_table} WHERE user_id = %d AND source_type = 'quick_faq'", $user_id
			) );
		}

		// Trend snapshot count
		$trend = 0;
		$snap_table = self::table_name();
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$snap_table}'" ) === $snap_table ) {
			$trend = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$snap_table} WHERE user_id = %d", $user_id
			) );
		}

		return compact( 'projects', 'sources', 'notes', 'sessions', 'memories', 'episodic', 'rolling', 'knowledge', 'goals_done', 'messages', 'quickfaq', 'trend' );
	}

	/* ================================================================
	 * TIMELINE — Score history from snapshots
	 * ================================================================ */

	public static function get_timeline( int $user_id, int $days = 30 ): array {
		global $wpdb;
		$table = self::table_name();

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT snapshot_date, intake_score, compression_score, continuity_score,
				execution_score, retrieval_score, overall_score
			 FROM {$table}
			 WHERE user_id = %d AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 ORDER BY snapshot_date ASC",
			$user_id, $days
		), ARRAY_A ) ?: [];
	}

	/* ================================================================
	 * KNOWLEDGE GROWTH — Daily accumulation timeline
	 * ================================================================ */

	public static function get_knowledge_growth( int $user_id, int $days = 30 ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day, 'source' AS type, COUNT(*) AS cnt
			 FROM {$p}webchat_sources WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(created_at)
			 UNION ALL
			 SELECT DATE(created_at), 'note', COUNT(*)
			 FROM {$p}memory_notes WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(created_at)
			 UNION ALL
			 SELECT DATE(created_at), 'message', COUNT(*)
			 FROM {$p}webchat_messages WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(created_at)",
			$user_id, $days, $user_id, $days, $user_id, $days
		), ARRAY_A ) ?: [];

		// Also add goals if intent table exists
		$ic_table = $p . 'intent_conversations';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ic_table}'" ) === $ic_table ) {
			$goals = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(created_at) AS day, 'goal' AS type, COUNT(*) AS cnt
				 FROM {$ic_table} WHERE user_id = %d AND status = 'COMPLETED'
				   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at)",
				$user_id, $days
			), ARRAY_A ) ?: [];

			$results = array_merge( $results, $goals );
		}

		return $results;
	}

	/* ================================================================
	 * DAILY EXECUTION — Goal status per day
	 * ================================================================ */

	public static function get_daily_execution( int $user_id, int $days = 30 ): array {
		global $wpdb;
		$ic_table = $wpdb->prefix . 'bizcity_intent_conversations';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ic_table}'" ) !== $ic_table ) {
			return [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day,
				SUM(status='COMPLETED') AS completed,
				SUM(status='ACTIVE') AS active,
				SUM(status='CANCELLED') AS cancelled,
				SUM(status='EXPIRED') AS expired
			 FROM {$ic_table}
			 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(created_at)
			 ORDER BY day ASC",
			$user_id, $days
		), ARRAY_A ) ?: [];
	}

	/* ================================================================
	 * KNOWLEDGE WAVE — Source types → Notes flow
	 * ================================================================ */

	public static function get_knowledge_wave( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		// Sources by type
		$source_types = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(source_type, 'unknown') AS source_type, COUNT(*) AS cnt,
				COALESCE(SUM(chunk_count), 0) AS chunks
			 FROM {$p}webchat_sources WHERE user_id = %d AND embedding_status = 'ready'
			 GROUP BY source_type ORDER BY cnt DESC",
			$user_id
		), ARRAY_A ) ?: [];

		// Notes by type + created_by
		$note_types = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(note_type, 'manual') AS note_type, created_by, COUNT(*) AS cnt
			 FROM {$p}memory_notes WHERE user_id = %d
			 GROUP BY note_type, created_by ORDER BY cnt DESC",
			$user_id
		), ARRAY_A ) ?: [];

		return [
			'source_types' => $source_types,
			'note_types'   => $note_types,
		];
	}

	/* ================================================================
	 * DETAIL AJAX — Tab-level data for each stat card
	 * ================================================================ */

	public static function ajax_maturity_detail(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$tab    = sanitize_key( $_POST['tab'] ?? '' );
		$page   = max( 1, intval( $_POST['page'] ?? 1 ) );
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$method = 'get_detail_' . $tab;
		if ( ! method_exists( __CLASS__, $method ) ) {
			wp_send_json_error( 'Invalid tab' );
		}

		$data = self::$method( $user_id, $limit, $offset );
		wp_send_json_success( $data );
	}

	/* ── Detail: Projects ── */
	private static function get_detail_projects( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.project_id, p.name, p.description, p.icon, p.color,
				p.is_archived, p.is_public, p.session_count, p.last_activity_at, p.created_at,
				(SELECT COUNT(*) FROM {$p}webchat_sources s WHERE s.project_id = p.project_id AND s.user_id = %d) AS source_count,
				(SELECT COUNT(*) FROM {$p}memory_notes n WHERE n.project_id = p.project_id AND n.user_id = %d) AS note_count
			 FROM {$p}webchat_projects p WHERE p.user_id = %d
			 ORDER BY p.last_activity_at DESC LIMIT %d OFFSET %d",
			$uid, $uid, $uid, $limit, $offset
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows ];
	}

	/* ── Detail: Sources (from knowledge_sources, excluding quick_faq) ── */
	private static function get_detail_sources( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [ 'items' => [], 'chart' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_name, source_type, source_url, status, chunks_count,
				attachment_id, error_message, created_at, updated_at
			 FROM {$table}
			 WHERE user_id = %d AND source_type != 'quick_faq'
			 ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		// Type breakdown for chart
		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(source_type, 'unknown') AS label, COUNT(*) AS cnt
			 FROM {$table} WHERE user_id = %d AND source_type != 'quick_faq'
			 GROUP BY source_type ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $types ];
	}

	/* ── Detail: Notes ── */
	private static function get_detail_notes( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, note_type, created_by, is_starred, tags, project_id, created_at
			 FROM {$p}memory_notes WHERE user_id = %d
			 ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT CONCAT(COALESCE(note_type,'manual'), ' / ', created_by) AS label, COUNT(*) AS cnt
			 FROM {$p}memory_notes WHERE user_id = %d GROUP BY note_type, created_by ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $types ];
	}

	/* ── Detail: Sessions ── */
	private static function get_detail_sessions( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_id, title, platform_type, status, message_count,
				last_message_at, last_message_preview, kci_ratio, project_id, started_at
			 FROM {$p}webchat_sessions WHERE user_id = %d
			 ORDER BY last_message_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$platforms = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(platform_type, 'WEBCHAT') AS label, COUNT(*) AS cnt
			 FROM {$p}webchat_sessions WHERE user_id = %d GROUP BY platform_type ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $platforms ];
	}

	/* ── Detail: User Memory ── */
	private static function get_detail_memories( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$um_table = $wpdb->prefix . 'bizcity_memory_users';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$um_table}'" ) !== $um_table ) {
			return [ 'items' => [], 'chart' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, memory_tier, memory_type, memory_key, memory_text AS content,
				score AS importance, times_seen, last_seen, created_at, updated_at
			 FROM {$um_table} WHERE user_id = %d
			 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(memory_type, 'fact') AS label, COUNT(*) AS cnt
			 FROM {$um_table} WHERE user_id = %d GROUP BY memory_type ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $types ];
	}

	/* ── Detail: Episodic Memory ── */
	private static function get_detail_episodic( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$em_table = $wpdb->prefix . 'bizcity_memory_episodic';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$em_table}'" ) !== $em_table ) {
			return [ 'items' => [], 'chart' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, event_type, event_key, event_text AS content,
				source_goal, source_tool, importance, times_seen,
				source_conversation_id, last_seen, created_at, updated_at
			 FROM {$em_table} WHERE user_id = %d
			 ORDER BY last_seen DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(event_type, 'fact') AS label, COUNT(*) AS cnt
			 FROM {$em_table} WHERE user_id = %d GROUP BY event_type ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $types ];
	}

	/* ── Detail: Rolling Memory ── */
	private static function get_detail_rolling( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$rm_table = $wpdb->prefix . 'bizcity_memory_rolling';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rm_table}'" ) !== $rm_table ) {
			return [ 'items' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, conversation_id, goal, goal_label,
				window_summary AS content, window_turn_count,
				user_goal_score, bot_satisfaction_score, status,
				completion_summary, total_turns, created_at, updated_at
			 FROM {$rm_table} WHERE user_id = %d
			 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows ];
	}

	/* ── Detail: Knowledge Characters ── */
	private static function get_detail_knowledge( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$ch_table = $wpdb->prefix . 'bizcity_characters';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ch_table}'" ) !== $ch_table ) {
			return [ 'items' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug, avatar, description, model_id, status,
				owner_type, total_conversations, total_messages, rating,
				created_at, updated_at
			 FROM {$ch_table} WHERE author_id = %d OR status = 'published'
			 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows ];
	}

	/* ── Detail: Goals (intent_conversations) ── */
	private static function get_detail_goals( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$ic_table = $wpdb->prefix . 'bizcity_intent_conversations';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ic_table}'" ) !== $ic_table ) {
			return [ 'items' => [], 'chart' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT conversation_id, goal, goal_label, status, channel, turn_count,
				rolling_summary, created_at, last_activity_at
			 FROM {$ic_table} WHERE user_id = %d
			 ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$statuses = $wpdb->get_results( $wpdb->prepare(
			"SELECT status AS label, COUNT(*) AS cnt
			 FROM {$ic_table} WHERE user_id = %d GROUP BY status ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $statuses ];
	}

	/* ── Detail: Messages (recent) ── */
	private static function get_detail_messages( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT message_id, message_from, message_type, status,
				LEFT(message_text, 200) AS message_preview, tool_name,
				session_id, created_at
			 FROM {$p}webchat_messages WHERE user_id = %d
			 ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT CONCAT(message_from, ' / ', COALESCE(message_type,'text')) AS label, COUNT(*) AS cnt
			 FROM {$p}webchat_messages WHERE user_id = %d GROUP BY message_from, message_type ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $types ];
	}

	/* ── Detail: Quick FAQ (§30 Knowledge Training Hub) ── */
	private static function get_detail_quickfaq( int $uid, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';
		$table = $p . 'knowledge_sources';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return [ 'items' => [], 'chart' => [] ];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_name AS title, content, source_type, status, chunks_count, scope, created_at, updated_at
			 FROM {$table} WHERE user_id = %d AND source_type = 'quick_faq'
			 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		// Parse JSON content to extract Q&A preview
		foreach ( $rows as &$row ) {
			$json = json_decode( $row['content'] ?? '', true );
			if ( is_array( $json ) ) {
				$row['question'] = $json['question'] ?? $json['title'] ?? '';
				$row['answer']   = $json['answer'] ?? $json['content'] ?? '';
			} else {
				$row['question'] = $row['title'] ?? '';
				$row['answer']   = $row['content'] ?? '';
			}
		}
		unset( $row );

		// Status breakdown for chart
		$statuses = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(status, 'pending') AS label, COUNT(*) AS cnt
			 FROM {$table} WHERE user_id = %d AND source_type = 'quick_faq' GROUP BY status ORDER BY cnt DESC",
			$uid
		), ARRAY_A ) ?: [];

		return [ 'items' => $rows, 'chart' => $statuses ];
	}

	/* ── Detail: Trend (§30 Knowledge Training Hub — snapshot timeline) ── */
	private static function get_detail_trend( int $uid, int $limit, int $offset ): array {
		$table = self::table_name();
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT snapshot_date, intake_score, compression_score, continuity_score,
				execution_score, retrieval_score, overall_score
			 FROM {$table} WHERE user_id = %d
			 ORDER BY snapshot_date DESC LIMIT %d OFFSET %d",
			$uid, $limit, $offset
		), ARRAY_A ) ?: [];

		// Reverse for chronological order in chart
		$rows = array_reverse( $rows );

		return [ 'items' => $rows ];
	}

	/* ================================================================
	 * QUICK SAVE — Add / Update items from dashboard
	 * ================================================================ */

	public static function ajax_maturity_save(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$tab    = sanitize_key( $_POST['tab'] ?? '' );
		$action_type = sanitize_key( $_POST['action_type'] ?? '' ); // 'add' or 'edit'
		$item_id = absint( $_POST['item_id'] ?? 0 );

		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';
		$new_id = 0;

		switch ( $tab ) {
			case 'memories':
				$table = $wpdb->prefix . 'bizcity_memory_users';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
					wp_send_json_error( 'Table not found' );
				}
				$data = [
					'memory_type' => sanitize_text_field( $_POST['memory_type'] ?? 'fact' ),
					'memory_key'  => sanitize_title( $_POST['memory_key'] ?? '' ),
					'memory_text' => sanitize_textarea_field( $_POST['content'] ?? '' ),
					'memory_tier' => 'explicit',
					'score'       => min( 100, absint( $_POST['importance'] ?? 50 ) ),
				];
				if ( ! $data['memory_text'] ) wp_send_json_error( 'Content required' );
				if ( ! $data['memory_key'] ) $data['memory_key'] = sanitize_title( mb_substr( $data['memory_text'], 0, 40 ) );

				if ( $action_type === 'edit' && $item_id ) {
					$wpdb->update( $table, $data, [ 'id' => $item_id, 'user_id' => $user_id ] );
				} else {
					$data['user_id'] = $user_id;
					$data['blog_id'] = get_current_blog_id();
					$wpdb->insert( $table, $data );
					$new_id = $wpdb->insert_id;
				}
				break;

			case 'notes':
				$table = $p . 'memory_notes';
				$data = [
					'title'      => sanitize_text_field( $_POST['title'] ?? '' ),
					'content'    => sanitize_textarea_field( $_POST['content'] ?? '' ),
					'note_type'  => sanitize_text_field( $_POST['note_type'] ?? 'manual' ),
					'created_by' => 'user',
				];
				if ( ! $data['title'] && ! $data['content'] ) wp_send_json_error( 'Title or content required' );

				if ( $action_type === 'edit' && $item_id ) {
					$wpdb->update( $table, $data, [ 'id' => $item_id, 'user_id' => $user_id ] );
				} else {
					$data['user_id'] = $user_id;
					$wpdb->insert( $table, $data );
					$new_id = $wpdb->insert_id;
				}
				break;

			case 'episodic':
				$table = $p . 'memory_episodic';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
					wp_send_json_error( 'Table not found' );
				}
				$data = [
					'event_type' => sanitize_text_field( $_POST['event_type'] ?? 'fact' ),
					'event_key'  => sanitize_title( $_POST['event_key'] ?? '' ),
					'event_text' => sanitize_textarea_field( $_POST['content'] ?? '' ),
					'importance' => min( 100, absint( $_POST['importance'] ?? 50 ) ),
				];
				if ( ! $data['event_text'] ) wp_send_json_error( 'Content required' );
				if ( ! $data['event_key'] ) $data['event_key'] = sanitize_title( mb_substr( $data['event_text'], 0, 40 ) );

				if ( $action_type === 'edit' && $item_id ) {
					$wpdb->update( $table, $data, [ 'id' => $item_id, 'user_id' => $user_id ] );
				} else {
					$data['user_id'] = $user_id;
					$data['blog_id'] = get_current_blog_id();
					$wpdb->insert( $table, $data );
					$new_id = $wpdb->insert_id;
				}
				break;

			case 'rolling':
				// Rolling memory: allow editing window_summary AND adding new entries
				$table = $p . 'memory_rolling';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
					wp_send_json_error( 'Table not found' );
				}
				if ( $action_type === 'edit' && $item_id ) {
					$wpdb->update( $table, [
						'window_summary' => sanitize_textarea_field( $_POST['content'] ?? '' ),
					], [ 'id' => $item_id, 'user_id' => $user_id ] );
				} elseif ( $action_type === 'add' ) {
					$wpdb->insert( $table, [
						'user_id'        => $user_id,
						'goal'           => sanitize_text_field( $_POST['goal'] ?? 'manual' ),
						'goal_label'     => sanitize_text_field( $_POST['goal_label'] ?? 'Ghi nhớ thủ công' ),
						'window_summary' => sanitize_textarea_field( $_POST['content'] ?? '' ),
						'status'         => 'completed',
						'blog_id'        => get_current_blog_id(),
					] );
					$new_id = $wpdb->insert_id;
				} elseif ( $action_type === 'delete' && $item_id ) {
					$wpdb->delete( $table, [ 'id' => $item_id, 'user_id' => $user_id ] );
				} else {
					wp_send_json_error( 'Invalid action for rolling memory' );
				}
				break;

			case 'quickfaq':
				// Quick FAQ: add/edit/delete Q&A entries
				$table = $p . 'knowledge_sources';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
					wp_send_json_error( 'Table not found' );
				}
				$question = sanitize_text_field( $_POST['question'] ?? '' );
				$answer   = sanitize_textarea_field( $_POST['answer'] ?? '' );

				if ( $action_type === 'delete' && $item_id ) {
					$wpdb->delete( $table, [ 'id' => $item_id, 'user_id' => $user_id ] );
				} else {
					if ( ! $question && ! $answer ) wp_send_json_error( 'Question or answer required' );
					$content_json = wp_json_encode( [ 'question' => $question, 'answer' => $answer ], JSON_UNESCAPED_UNICODE );
					$data = [
						'source_name'  => $question ?: mb_substr( $answer, 0, 80, 'UTF-8' ),
						'content'      => $content_json,
						'content_hash' => md5( $content_json ),
						'source_type'  => 'quick_faq',
						'status'       => 'ready',
					];
					if ( $action_type === 'edit' && $item_id ) {
						$wpdb->update( $table, $data, [ 'id' => $item_id, 'user_id' => $user_id ] );
					} else {
						$data['user_id']      = $user_id;
						$data['character_id'] = 0; // global quick FAQ, not bound to a character
						$wpdb->insert( $table, $data );
						$new_id = $wpdb->insert_id;
					}
				}
				break;

			default:
				wp_send_json_error( 'Tab not editable' );
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [ 'saved' => true, 'id' => $new_id ] );
	}

	/* ================================================================
	 * INLINE SAVE — cell-level update for editable tables
	 * ================================================================ */
	public static function ajax_maturity_inline_save(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) wp_send_json_error( 'Not logged in' );

		$tab     = sanitize_key( $_POST['tab'] ?? '' );
		$item_id = absint( $_POST['item_id'] ?? 0 );
		$field   = sanitize_key( $_POST['field'] ?? '' );
		$value   = sanitize_textarea_field( wp_unslash( $_POST['value'] ?? '' ) );

		if ( ! $tab || ! $item_id || ! $field ) wp_send_json_error( 'Missing params' );

		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';

		// Table + allowed fields mapping (JS field → DB column)
		$config = [
			'memories' => [
				'table' => $wpdb->prefix . 'bizcity_memory_users',
				'map'   => [ 'memory_type' => 'memory_type', 'content' => 'memory_text', 'importance' => 'score' ],
			],
			'notes' => [
				'table' => $p . 'memory_notes',
				'map'   => [ 'title' => 'title', 'content' => 'content', 'note_type' => 'note_type' ],
			],
			'episodic' => [
				'table' => $p . 'memory_episodic',
				'map'   => [ 'event_type' => 'event_type', 'content' => 'event_text', 'importance' => 'importance' ],
			],
			'rolling' => [
				'table' => $p . 'memory_rolling',
				'map'   => [ 'goal_label' => 'goal_label', 'content' => 'window_summary' ],
			],
			'quickfaq' => [
				'table' => $p . 'knowledge_sources',
				'map'   => [ 'question' => '_json', 'answer' => '_json' ],
			],
		];

		if ( ! isset( $config[ $tab ] ) ) wp_send_json_error( 'Tab not editable' );
		$cfg = $config[ $tab ];

		if ( ! isset( $cfg['map'][ $field ] ) ) wp_send_json_error( 'Field not allowed: ' . $field );

		$table    = $cfg['table'];
		$db_field = $cfg['map'][ $field ];

		if ( $db_field === '_json' ) {
			// quickfaq: question/answer stored as JSON in content column
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT content FROM {$table} WHERE id = %d AND user_id = %d", $item_id, $user_id
			) );
			$content = json_decode( $existing ?: '{}', true ) ?: [];
			$content[ $field ] = $value;
			$wpdb->update( $table, [
				'content'     => wp_json_encode( $content, JSON_UNESCAPED_UNICODE ),
				'source_name' => $content['question'] ?? mb_substr( $content['answer'] ?? '', 0, 80, 'UTF-8' ),
			], [ 'id' => $item_id, 'user_id' => $user_id ] );
		} else {
			$wpdb->update( $table, [ $db_field => $value ], [ 'id' => $item_id, 'user_id' => $user_id ] );
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [ 'saved' => true ] );
	}

	/* ================================================================
	 * EXPORT — return tab data as JSON or CSV
	 * ================================================================ */
	public static function ajax_maturity_export(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) wp_send_json_error( 'Not logged in' );

		$tab    = sanitize_key( $_POST['tab'] ?? '' );
		$format = sanitize_key( $_POST['format'] ?? 'json' );

		$method = 'get_detail_' . $tab;
		if ( ! method_exists( __CLASS__, $method ) ) wp_send_json_error( 'Invalid tab' );

		$data  = self::$method( $user_id, 9999, 0 );
		$items = $data['items'] ?? [];

		if ( $format === 'csv' ) {
			$csv = '';
			if ( ! empty( $items ) ) {
				$headers = array_keys( (array) $items[0] );
				$csv .= implode( ',', $headers ) . "\n";
				foreach ( $items as $item ) {
					$row = [];
					foreach ( $headers as $h ) {
						$val = is_object( $item ) ? ( $item->$h ?? '' ) : ( $item[ $h ] ?? '' );
						$row[] = '"' . str_replace( '"', '""', (string) $val ) . '"';
					}
					$csv .= implode( ',', $row ) . "\n";
				}
			}
			wp_send_json_success( [ 'content' => $csv ] );
		} else {
			wp_send_json_success( [ 'content' => $items ] );
		}
	}

	/* ================================================================
	 * IMPORT — bulk insert from JSON or CSV
	 * ================================================================ */
	public static function ajax_maturity_import(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) wp_send_json_error( 'Not logged in' );

		$tab     = sanitize_key( $_POST['tab'] ?? '' );
		$format  = sanitize_key( $_POST['format'] ?? 'json' );
		$content = wp_unslash( $_POST['content'] ?? '' );

		$importable = [ 'quickfaq', 'memories', 'notes', 'episodic', 'rolling' ];
		if ( ! in_array( $tab, $importable, true ) ) wp_send_json_error( 'Tab not importable' );

		$items = [];
		if ( $format === 'csv' ) {
			$lines = explode( "\n", trim( $content ) );
			if ( count( $lines ) < 2 ) wp_send_json_error( 'Empty CSV' );
			$headers = str_getcsv( array_shift( $lines ) );
			foreach ( $lines as $line ) {
				if ( trim( $line ) === '' ) continue;
				$values = str_getcsv( $line );
				$item = [];
				foreach ( $headers as $i => $h ) { $item[ trim( $h ) ] = $values[ $i ] ?? ''; }
				$items[] = $item;
			}
		} else {
			$items = json_decode( $content, true );
			if ( ! is_array( $items ) ) wp_send_json_error( 'Invalid JSON' );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'bizcity_';
		$count = 0;

		foreach ( $items as $item ) {
			switch ( $tab ) {
				case 'quickfaq':
					$q = sanitize_text_field( $item['question'] ?? '' );
					$a = sanitize_textarea_field( $item['answer'] ?? '' );
					if ( ! $q && ! $a ) continue 2;
					$cj = wp_json_encode( [ 'question' => $q, 'answer' => $a ], JSON_UNESCAPED_UNICODE );
					$wpdb->insert( $p . 'knowledge_sources', [
						'user_id'      => $user_id,
						'character_id' => 0, // global quick FAQ, not bound to a character
						'title'        => $q ?: mb_substr( $a, 0, 80, 'UTF-8' ),
						'content'      => $cj,
						'content_hash' => md5( $cj ),
						'source_type'  => 'quick_faq',
						'status'       => 'ready',
					] );
					break;
				case 'memories':
					$txt = sanitize_textarea_field( $item['content'] ?? '' );
					if ( ! $txt ) continue 2;
					$wpdb->insert( $wpdb->prefix . 'bizcity_memory_users', [
						'user_id' => $user_id, 'blog_id' => get_current_blog_id(),
						'memory_type' => sanitize_key( $item['memory_type'] ?? 'fact' ),
						'memory_key'  => sanitize_title( $item['memory_key'] ?? mb_substr( $txt, 0, 40 ) ),
						'memory_text' => $txt, 'memory_tier' => 'explicit',
						'score' => min( 100, absint( $item['importance'] ?? 50 ) ),
					] );
					break;
				case 'episodic':
					$txt = sanitize_textarea_field( $item['content'] ?? '' );
					if ( ! $txt ) continue 2;
					$wpdb->insert( $p . 'memory_episodic', [
						'user_id' => $user_id, 'blog_id' => get_current_blog_id(),
						'event_type' => sanitize_key( $item['event_type'] ?? 'fact' ),
						'event_key'  => sanitize_title( $item['event_key'] ?? mb_substr( $txt, 0, 40 ) ),
						'event_text' => $txt,
						'importance' => min( 100, absint( $item['importance'] ?? 50 ) ),
					] );
					break;
				case 'rolling':
					$wpdb->insert( $p . 'memory_rolling', [
						'user_id' => $user_id, 'blog_id' => get_current_blog_id(),
						'goal' => sanitize_text_field( $item['goal'] ?? 'manual' ),
						'goal_label' => sanitize_text_field( $item['goal_label'] ?? 'Import' ),
						'window_summary' => sanitize_textarea_field( $item['content'] ?? '' ),
						'status' => 'completed',
					] );
					break;
				case 'notes':
					$wpdb->insert( $p . 'memory_notes', [
						'user_id' => $user_id,
						'title'   => sanitize_text_field( $item['title'] ?? '' ),
						'content' => sanitize_textarea_field( $item['content'] ?? '' ),
						'note_type' => sanitize_key( $item['note_type'] ?? 'manual' ),
						'created_by' => 'user',
					] );
					break;
			}
			$count++;
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [ 'count' => $count ] );
	}

	/* ================================================================
	 * SOURCE MANAGEMENT — Upload, Embed, Delete
	 * ================================================================ */

	/**
	 * AJAX: Upload file(s) → create knowledge_sources row(s) + parse content
	 *
	 * Uses BizCity_Knowledge_FileParser to extract text, then stores in
	 * bizcity_knowledge_sources. Embedding is done separately via ajax_embed_source.
	 */
	public static function ajax_upload_source(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		if ( empty( $_FILES['files'] ) ) {
			wp_send_json_error( 'No files provided' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = [];
		$errors   = [];
		$files    = self::normalize_files_array( $_FILES['files'] );

		$allowed = [
			'pdf', 'txt', 'md', 'docx', 'doc', 'csv', 'json',
			'pptx', 'ppt', 'xlsx', 'xls',
			'jpg', 'jpeg', 'png', 'webp', 'gif',
		];

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		foreach ( $files as $file ) {
			if ( $file['error'] !== UPLOAD_ERR_OK ) {
				$errors[] = $file['name'] . ': Upload error code ' . $file['error'];
				continue;
			}

			$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed, true ) ) {
				$errors[] = $file['name'] . ': File type not allowed';
				continue;
			}

			// Upload to WP media
			$upload = wp_handle_upload( $file, [ 'test_form' => false ] );
			if ( isset( $upload['error'] ) ) {
				$errors[] = $file['name'] . ': ' . $upload['error'];
				continue;
			}

			$attachment_id = wp_insert_attachment( [
				'post_title'     => sanitize_file_name( $file['name'] ),
				'post_mime_type' => $upload['type'],
				'post_status'    => 'private',
			], $upload['file'] );

			// Parse text content from file
			$content = '';
			if ( class_exists( 'BizCity_Knowledge_FileParser' ) ) {
				$parser  = BizCity_Knowledge_FileParser::instance();
				$parsed  = $parser->parse_attachment( $attachment_id );
				$content = is_wp_error( $parsed ) ? '' : $parsed;
			}

			$source_name = pathinfo( $file['name'], PATHINFO_FILENAME );
			$wpdb->insert( $table, [
				'user_id'       => $user_id,
				'character_id'  => 0,
				'source_type'   => 'file',
				'source_name'   => sanitize_text_field( $source_name ),
				'source_url'    => esc_url_raw( $upload['url'] ),
				'attachment_id' => $attachment_id,
				'content'       => $content,
				'content_hash'  => $content ? md5( $content ) : '',
				'chunks_count'  => 0,
				'status'        => 'pending',
				'scope'         => 'user',
				'created_at'    => current_time( 'mysql' ),
			] );

			if ( $wpdb->insert_id ) {
				$uploaded[] = (int) $wpdb->insert_id;
			} else {
				$errors[] = $file['name'] . ': DB insert failed';
			}
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [
			'uploaded' => count( $uploaded ),
			'ids'      => $uploaded,
			'errors'   => $errors,
		] );
	}

	/**
	 * AJAX: Add a URL source (insert into knowledge_sources)
	 */
	public static function ajax_add_url_source(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$url = esc_url_raw( sanitize_text_field( $_POST['url'] ?? '' ) );
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( 'Invalid URL' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';
		$source_name = sanitize_text_field( wp_parse_url( $url, PHP_URL_HOST ) ?: 'URL Source' );

		$wpdb->insert( $table, [
			'user_id'      => $user_id,
			'character_id' => 0,
			'source_type'  => 'url',
			'source_name'  => $source_name,
			'source_url'   => $url,
			'status'       => 'pending',
			'scope'        => 'user',
			'created_at'   => current_time( 'mysql' ),
		] );

		$source_id = $wpdb->insert_id;
		if ( ! $source_id ) {
			wp_send_json_error( 'DB insert failed' );
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [ 'source_id' => $source_id ] );
	}

	/**
	 * AJAX: Embed source(s) via BizCity_Knowledge_Embedding::process_source()
	 *
	 * POST params:
	 *   source_id  — single source to embed (0 = all pending for this user)
	 */
	public static function ajax_embed_source(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		if ( ! class_exists( 'BizCity_Knowledge_Embedding' ) ) {
			wp_send_json_error( 'Embedding module (BizCity_Knowledge_Embedding) not available.' );
		}

		$source_id = intval( $_POST['source_id'] ?? 0 );
		$embedding = BizCity_Knowledge_Embedding::instance();

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		if ( $source_id > 0 ) {
			// Verify ownership
			$source = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, user_id, content FROM {$table} WHERE id = %d", $source_id
			) );
			if ( ! $source || (int) $source->user_id !== $user_id ) {
				wp_send_json_error( 'Permission denied' );
			}

			$content = $source->content ?? '';
			if ( empty( $content ) ) {
				wp_send_json_error( 'Nguồn không có nội dung text để embed. Hãy kiểm tra lại file/URL.' );
			}

			$result = $embedding->process_source( $source_id, $content );
			self::invalidate_dashboard_cache( $user_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( [
				'message' => $result['message'] ?? ( 'Embedded ' . ( $result['chunks_count'] ?? 0 ) . ' chunks' ),
				'chunks'  => $result['chunks_count'] ?? 0,
			] );
		} else {
			// Embed all pending/error sources for this user (excluding quick_faq)
			$pending = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content FROM {$table}
				 WHERE user_id = %d AND source_type != 'quick_faq'
				   AND status IN ('pending', 'error') AND content IS NOT NULL AND content != ''
				 ORDER BY id ASC",
				$user_id
			) );

			if ( empty( $pending ) ) {
				wp_send_json_success( [ 'message' => 'Không có nguồn nào cần embed.', 'total' => 0, 'success_count' => 0 ] );
			}

			$success_count = 0;
			$fail_count    = 0;
			$total_chunks  = 0;
			$errors        = [];

			foreach ( $pending as $src ) {
				$r = $embedding->process_source( (int) $src->id, $src->content );
				if ( ! is_wp_error( $r ) ) {
					$success_count++;
					$total_chunks += $r['chunks_count'] ?? 0;
				} else {
					$fail_count++;
					$errors[] = "Source #{$src->id}: " . $r->get_error_message();
				}
			}

			self::invalidate_dashboard_cache( $user_id );

			wp_send_json_success( [
				'message'       => "Embed xong {$success_count}/" . count( $pending ) . " nguồn, {$total_chunks} chunks",
				'total'         => count( $pending ),
				'success_count' => $success_count,
				'fail_count'    => $fail_count,
				'total_chunks'  => $total_chunks,
				'errors'        => $errors,
			] );
		}
	}

	/**
	 * AJAX: Delete a source from knowledge_sources + its chunks
	 */
	public static function ajax_delete_source(): void {
		check_ajax_referer( 'bizcity_maturity_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$source_id = intval( $_POST['source_id'] ?? 0 );
		if ( ! $source_id ) {
			wp_send_json_error( 'Missing source_id' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';

		// Verify ownership
		$owner = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE id = %d", $source_id
		) );
		if ( $owner !== $user_id ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			BizCity_Knowledge_Database::instance()->delete_source_and_chunks( $source_id );
		} else {
			$wpdb->delete( $wpdb->prefix . 'bizcity_knowledge_chunks', [ 'source_id' => $source_id ] );
			$wpdb->delete( $table, [ 'id' => $source_id ] );
		}

		self::invalidate_dashboard_cache( $user_id );

		wp_send_json_success( [ 'deleted' => true ] );
	}

	/**
	 * Normalize $_FILES array for multiple file uploads.
	 */
	private static function normalize_files_array( array $files_input ): array {
		$files = [];
		if ( is_array( $files_input['name'] ) ) {
			$count = count( $files_input['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$files[] = [
					'name'     => $files_input['name'][ $i ],
					'type'     => $files_input['type'][ $i ],
					'tmp_name' => $files_input['tmp_name'][ $i ],
					'error'    => $files_input['error'][ $i ],
					'size'     => $files_input['size'][ $i ],
				];
			}
		} else {
			$files[] = $files_input;
		}
		return $files;
	}
}
