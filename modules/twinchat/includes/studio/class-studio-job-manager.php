<?php
/**
 * Studio Job Manager — Generic async job layer for TwinChat Studio tools.
 *
 * Cung cấp tiêu chuẩn cho các plugin tool bridge vào Studio:
 *
 *   1. Plugin đăng ký bridge:
 *        BizCity_Studio_Job_Manager::register_bridge(
 *            'doc_document',
 *            function(array $job, array $skeleton): array|WP_Error { ... },
 *            'dispatch'   // 'dispatch' | 'async' | 'wait'
 *        );
 *
 *   2. Khi user trigger tool → Studio gọi Job_Manager::create() → job pending.
 *
 *   3. dispatch_mode:
 *        'dispatch' — chạy inline cùng request (sub-1s, không cần worker).
 *        'async'    — chạy qua shutdown handler / Action Scheduler (cron safety net).
 *        'wait'     — async nhưng FE poll job status cho đến khi done.
 *
 *   4. Khi bridge fn hoàn thành → Job_Manager::complete() ghi bizcity_webchat_studio_outputs
 *      chỉ MỘT LẦN khi job=done → outputs chỉ chứa kết quả thật.
 *
 *   5. Plugin async có thể gọi từ xa:
 *        do_action('bizcity_studio_job_complete', $job_id, $result, $skeleton);
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Studio
 * @since      0.8.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Studio_Job_Manager {

	/** @var array<string, array{fn: callable, mode: string}> */
	private static $bridges = [];

	/* ── Bridge registration ─────────────────────────────────────────────── */

	/**
	 * Register a plugin bridge for a tool type.
	 *
	 * @param string   $tool_type     e.g. 'doc_document', 'mindmap'
	 * @param callable $dispatch_fn   fn(array $job, array $skeleton): array|WP_Error
	 *                                Trả về array với keys: title, content, content_format,
	 *                                external_url, external_post_id (hoặc data.url / data.id)
	 * @param string   $dispatch_mode 'dispatch' | 'async' | 'wait'
	 */
	public static function register_bridge( string $tool_type, callable $dispatch_fn, string $dispatch_mode = 'async' ): void {
		self::$bridges[ $tool_type ] = [
			'fn'   => $dispatch_fn,
			'mode' => $dispatch_mode,
		];
	}

	public static function get_bridge( string $tool_type ): ?array {
		return self::$bridges[ $tool_type ] ?? null;
	}

	public static function has_bridge( string $tool_type ): bool {
		return isset( self::$bridges[ $tool_type ] );
	}

	/** Return dispatch mode registered for a tool, or 'async' if no bridge. */
	public static function dispatch_mode( string $tool_type ): string {
		return self::$bridges[ $tool_type ]['mode'] ?? 'async';
	}

	/* ── Table ───────────────────────────────────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_webchat_studio_jobs';
	}

	/* ── CRUD ────────────────────────────────────────────────────────────── */

	/**
	 * Create a new pending job.
	 *
	 * @param array $args {
	 *   @type string $project_id
	 *   @type int    $user_id
	 *   @type string $tool_type
	 *   @type bool   $allow_duplicate   Set true to bypass idempotency guard.
	 * }
	 * @return int|\WP_Error  Job ID on success.
	 */
	public static function create( array $args ) {
		global $wpdb;
		$tbl = self::table();

		$project_id = sanitize_text_field( $args['project_id'] ?? '' );
		$user_id    = (int) ( $args['user_id'] ?? 0 );
		$tool_type  = sanitize_key( $args['tool_type'] ?? '' );
		$mode       = sanitize_key( $args['dispatch_mode'] ?? self::dispatch_mode( $tool_type ) );

		// Idempotency: return existing pending/processing job for same project+tool.
		if ( empty( $args['allow_duplicate'] ) ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl}
				 WHERE project_id = %s AND tool_type = %s AND status IN ('pending','processing')
				 ORDER BY id DESC LIMIT 1",
				$project_id,
				$tool_type
			) );
			if ( $existing > 0 ) return $existing;
		}

		$ok = $wpdb->insert( $tbl, [
			'project_id'    => $project_id,
			'user_id'       => $user_id,
			'tool_type'     => $tool_type,
			'dispatch_mode' => $mode,
			'status'        => 'pending',
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		] );

		if ( ! $ok ) {
			return new WP_Error( 'job_insert_failed', $wpdb->last_error ?: 'DB insert failed' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Atomically claim a job (pending → processing).
	 * Returns true if this caller won the race.
	 */
	public static function claim( int $job_id ): bool {
		global $wpdb;
		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . "
			 SET status = 'processing', started_at = %s, updated_at = %s
			 WHERE id = %d AND status = 'pending'",
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$job_id
		) );
		return (bool) $rows;
	}

	/**
	 * Save skeleton JSON into the job row (called by worker after building it).
	 */
	public static function save_payload( int $job_id, array $skeleton ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'payload_json' => wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $job_id ]
		);
	}

	/**
	 * Mark job done and write the output row into bizcity_webchat_studio_outputs.
	 * This is the ONLY place that inserts into studio_outputs for Studio jobs.
	 *
	 * @param int   $job_id
	 * @param array $result   Bridge result: title, content, content_format, external_url, external_post_id
	 * @param array $skeleton Skeleton used for this job (source_count, note_count metadata).
	 */
	public static function complete( int $job_id, array $result, array $skeleton = [] ): void {
		global $wpdb;

		$job = self::get( $job_id );
		if ( ! $job ) return;

		// Write studio_outputs only when done (real content, not a placeholder).
		$output_id = self::write_output( $job, $result, $skeleton );

		$wpdb->update( self::table(), [
			'status'       => 'done',
			'output_id'    => $output_id ?: null,
			'result_url'   => esc_url_raw( (string) ( $result['data']['url'] ?? $result['external_url'] ?? '' ) ),
			'result_data'  => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ),
			'completed_at' => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		], [ 'id' => $job_id ] );

		$notebook_id = self::notebook_id_from_project( (string) $job->project_id );
		do_action( 'bizcity_twinchat_studio_generated', $output_id, (string) $job->tool_type, $notebook_id );

		error_log( sprintf(
			'[Studio Job] DONE job_id=%d output_id=%d tool=%s project=%s',
			$job_id, (int) $output_id, (string) $job->tool_type, (string) $job->project_id
		) );
	}

	/**
	 * Mark job as failed.
	 */
	public static function fail( int $job_id, string $error ): void {
		global $wpdb;
		$wpdb->update( self::table(), [
			'status'        => 'failed',
			'error_message' => mb_substr( sanitize_text_field( $error ), 0, 500 ),
			'completed_at'  => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		], [ 'id' => $job_id ] );

		error_log( "[Studio Job] FAILED job_id={$job_id}: {$error}" );
	}

	/**
	 * Dispatch mode 'dispatch': run bridge inline (same request, must be fast).
	 * For 'async'/'wait' modes, the worker calls complete()/fail() after doing work.
	 *
	 * @param int   $job_id
	 * @param array $skeleton  Already-built skeleton (passed from worker or caller).
	 */
	public static function dispatch_inline( int $job_id, array $skeleton = [] ): void {
		$job = self::get( $job_id );
		if ( ! $job || ! self::claim( $job_id ) ) return;

		// If skeleton not passed, try stored payload.
		if ( empty( $skeleton ) && $job->payload_json ) {
			$stored = json_decode( $job->payload_json, true );
			if ( is_array( $stored ) ) $skeleton = $stored;
		}

		$bridge = self::get_bridge( (string) $job->tool_type );
		if ( ! $bridge ) {
			self::fail( $job_id, "No bridge registered for tool '{$job->tool_type}'" );
			return;
		}

		try {
			$result = call_user_func( $bridge['fn'], (array) $job, $skeleton );
			if ( is_wp_error( $result ) ) {
				self::fail( $job_id, $result->get_error_message() );
				return;
			}
			self::complete( $job_id, (array) $result, $skeleton );
		} catch ( \Throwable $e ) {
			self::fail( $job_id, $e->getMessage() );
		}
	}

	/* ── Read ────────────────────────────────────────────────────────────── */

	public static function get( int $job_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $job_id ) ) ?: null;
	}

	/**
	 * List jobs for a project, newest-first. Joined with output data when done.
	 *
	 * @param string $project_id  e.g. "tc_2"
	 * @param string $tool_type   optional filter
	 * @return object[]
	 */
	public static function list_for_project( string $project_id, string $tool_type = '' ): array {
		global $wpdb;
		$jobs_tbl    = self::table();
		$outputs_tbl = class_exists( 'BCN_Schema_Extend' )
			? BCN_Schema_Extend::table_studio_outputs()
			: $wpdb->prefix . 'bizcity_webchat_studio_outputs';

		$where  = 'WHERE j.project_id = %s';
		$params = [ $project_id ];

		if ( $tool_type ) {
			$where   .= ' AND j.tool_type = %s';
			$params[] = sanitize_key( $tool_type );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT j.id,
			        j.project_id,
			        j.user_id,
			        j.tool_type,
			        j.dispatch_mode,
			        j.status        AS job_status,
			        j.error_message,
			        j.result_url,
			        j.output_id,
			        j.created_at,
			        j.updated_at,
			        COALESCE(o.title, 'Đang tạo\u2026')  AS title,
			        COALESCE(o.content_format, 'json') AS content_format,
			        COALESCE(o.source_count, 0)        AS source_count,
			        COALESCE(o.note_count, 0)          AS note_count,
			        COALESCE(o.external_url, j.result_url) AS external_url,
			        o.external_post_id
			 FROM {$jobs_tbl} j
			 LEFT JOIN {$outputs_tbl} o ON o.id = j.output_id
			 {$where}
			 ORDER BY j.id DESC LIMIT 100",
			$params
		) );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete a job (and optionally cascade-delete the linked output).
	 */
	public static function delete( int $job_id, int $user_id = 0, bool $delete_output = false ): bool {
		global $wpdb;

		if ( $delete_output ) {
			$job = self::get( $job_id );
			if ( $job && $job->output_id ) {
				$out_tbl = class_exists( 'BCN_Schema_Extend' )
					? BCN_Schema_Extend::table_studio_outputs()
					: $wpdb->prefix . 'bizcity_webchat_studio_outputs';
				$wpdb->delete( $out_tbl, [ 'id' => (int) $job->output_id ] );
			}
		}

		$where = [ 'id' => $job_id ];
		if ( $user_id > 0 ) $where['user_id'] = $user_id;
		return (bool) $wpdb->delete( self::table(), $where );
	}

	/* ── Internal helpers ────────────────────────────────────────────────── */

	/**
	 * Insert a row into studio_outputs when a job is completed.
	 * Only called once per job from complete().
	 */
	private static function write_output( object $job, array $result, array $skeleton ): int {
		$tbl = class_exists( 'BCN_Schema_Extend' )
			? BCN_Schema_Extend::table_studio_outputs()
			: null;
		if ( ! $tbl ) return 0;

		global $wpdb;

		// Resolve external_url / external_post_id from nested data key or flat result.
		$external_url     = esc_url_raw( (string) ( $result['data']['url'] ?? $result['external_url'] ?? '' ) );
		$external_post_id = isset( $result['data']['id'] )
			? absint( $result['data']['id'] )
			: ( isset( $result['external_post_id'] ) ? absint( $result['external_post_id'] ) : null );

		$ok = $wpdb->insert( $tbl, [
			'user_id'          => (int) $job->user_id,
			'project_id'       => (string) $job->project_id,
			'session_id'       => '',
			'caller'           => 'twinchat',
			'tool_type'        => (string) $job->tool_type,
			'title'            => sanitize_text_field( (string) ( $result['title'] ?? '' ) ),
			'content'          => (string) ( $result['content'] ?? '' ),
			'content_format'   => sanitize_text_field( (string) ( $result['content_format'] ?? 'json' ) ),
			'source_count'     => (int) ( $skeleton['meta']['source_count'] ?? 0 ),
			'note_count'       => (int) ( $skeleton['meta']['note_count']   ?? 0 ),
			'external_post_id' => $external_post_id,
			'external_url'     => $external_url,
			'status'           => 'ready',
			'input_snapshot'   => wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE ),
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		] );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function notebook_id_from_project( string $project_id ): int {
		if ( preg_match( '/^tc_(\d+)$/', $project_id, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}

/*
 * Allow async plugins to complete a job via WP action — bridge fires this when
 * its background process finishes (e.g. bzdoc SSE completes, AI model returns).
 *
 * Usage:
 *   do_action( 'bizcity_studio_job_complete', $job_id, $result_array, $skeleton_array );
 */
add_action( 'bizcity_studio_job_complete', static function ( $job_id, $result, $skeleton = [] ) {
	BizCity_Studio_Job_Manager::complete( (int) $job_id, (array) $result, (array) $skeleton );
}, 10, 3 );
