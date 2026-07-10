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
 * BizCity Twin Event Bus — Event dispatch, milestone recording, context log writing.
 *
 * Phase 2 Priority 5: Trung tâm ghi nhận event theo taxonomy.
 *
 * Responsibilities:
 *   1. Dispatch events theo BizCity_Twin_Data_Contract::event_taxonomy().
 *   2. Ghi milestone vào bizcity_twin_milestones.
 *   3. Ghi context decision vào bizcity_twin_context_logs.
 *   4. Fire WordPress actions để downstream modules lắng nghe.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Event_Bus {

	/** @var bool Whether event bus is booted */
	private static bool $booted = false;

	/**
	 * Boot event bus — register WP action listener.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Central event listener
		add_action( 'bizcity_twin_event', [ __CLASS__, 'handle_event' ], 10, 2 );
	}

	/* ================================================================
	 * §1 — EVENT DISPATCH
	 * ================================================================ */

	/**
	 * Dispatch a Twin event.
	 *
	 * @param string $event_key  Event key from BizCity_Twin_Data_Contract::EVT_*
	 * @param array  $payload    Event payload (must include trace_id at minimum).
	 */
	public static function dispatch( string $event_key, array $payload ): void {
		// Ensure trace_id
		if ( empty( $payload['trace_id'] ) ) {
			$payload['trace_id'] = BizCity_Twin_Data_Contract::current_trace_id();
		}

		// Validate payload against taxonomy
		$missing = BizCity_Twin_Data_Contract::validate_event_payload( $event_key, $payload );
		if ( ! empty( $missing ) ) {
			BizCity_Twin_Trace::log( 'event_invalid', [
				'event'   => $event_key,
				'missing' => $missing,
			], 'warn' );
		}

		// Fire the central action
		do_action( 'bizcity_twin_event', $event_key, $payload );
	}

	/**
	 * Central event handler — routes events to appropriate recorders.
	 *
	 * @param string $event_key
	 * @param array  $payload
	 */
	public static function handle_event( string $event_key, array $payload ): void {
		// Log to trace
		BizCity_Twin_Trace::log( 'event:' . $event_key, array_intersect_key(
			$payload,
			array_flip( [ 'trace_id', 'user_id', 'message_id', 'tool_name', 'confidence' ] )
		) );

		// Route to specific handlers based on state_impact
		$taxonomy = BizCity_Twin_Data_Contract::event_taxonomy();
		if ( ! isset( $taxonomy[ $event_key ] ) ) {
			return;
		}

		$impacts = $taxonomy[ $event_key ]['state_impact'];

		// Record milestone for events that affect milestones
		if ( in_array( 'milestones', $impacts, true ) ) {
			self::record_milestone_from_event( $event_key, $payload );
		}

		// Record context_log for events that affect context decisions
		if ( in_array( 'context_logs', $impacts, true ) ) {
			self::record_context_log_from_event( $event_key, $payload );
		}

		// Fire specific WordPress action for downstream listeners
		do_action( 'bizcity_twin_event_' . $event_key, $payload );
	}

	/* ================================================================
	 * §2 — MILESTONE RECORDING
	 * ================================================================ */

	/**
	 * Record a milestone directly.
	 *
	 * @param array $data {
	 *   @type string $trace_id
	 *   @type int    $user_id
	 *   @type int    $blog_id
	 *   @type int    $journey_id      Optional.
	 *   @type string $milestone_type  Required: goal_completed, tool_executed, note_created, memory_extracted, etc.
	 *   @type string $milestone_label Optional description.
	 *   @type float  $milestone_score 0.0 — 100.0
	 *   @type string $source_type     Optional: message, intent, tool, note, memory.
	 *   @type string $source_ref_id   Optional: reference to originating record.
	 *   @type array  $payload         Optional: extra data.
	 *   @type string $occurred_at     Optional: defaults to now.
	 * }
	 * @return int milestone_id (0 on failure).
	 */
	public static function record_milestone( array $data ): int {
		global $wpdb;
		$table = BizCity_Twin_State_Schema::milestones_table();

		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return 0;
		}

		$row = [
			'trace_id'        => $data['trace_id'] ?? BizCity_Twin_Data_Contract::current_trace_id(),
			'user_id'         => $data['user_id'] ?? BizCity_Twin_Data_Contract::current_user_id(),
			'blog_id'         => $data['blog_id'] ?? BizCity_Twin_Data_Contract::current_blog_id(),
			'journey_id'      => $data['journey_id'] ?? null,
			'milestone_type'  => $data['milestone_type'],
			'milestone_label' => $data['milestone_label'] ?? null,
			'milestone_score' => $data['milestone_score'] ?? 0.0,
			'source_type'     => $data['source_type'] ?? null,
			'source_ref_id'   => $data['source_ref_id'] ?? null,
			'payload_json'    => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
			'occurred_at'     => $data['occurred_at'] ?? current_time( 'mysql' ),
		];

		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Auto-record milestone from a taxonomy event.
	 */
	private static function record_milestone_from_event( string $event_key, array $payload ): void {
		switch ( $event_key ) {
			case BizCity_Twin_Data_Contract::EVT_TOOL_EXECUTED:
				$milestone_type = 'tool_executed';
				break;
			case BizCity_Twin_Data_Contract::EVT_MILESTONE_REACHED:
				$milestone_type = $payload['milestone_type'] ?? 'milestone';
				break;
			case BizCity_Twin_Data_Contract::EVT_GOAL_PROGRESSED:
				$milestone_type = 'goal_progress';
				break;
			default:
				$milestone_type = $event_key;
				break;
		}

		self::record_milestone( [
			'trace_id'        => $payload['trace_id'] ?? null,
			'user_id'         => $payload['user_id'] ?? null,
			'milestone_type'  => $milestone_type,
			'milestone_label' => $payload['tool_name'] ?? $payload['goal'] ?? $payload['milestone_label'] ?? null,
			'milestone_score' => (float) ( $payload['milestone_score'] ?? $payload['progress_score'] ?? 0 ),
			'source_type'     => $event_key,
			'source_ref_id'   => $payload['intent_conversation_id'] ?? $payload['message_id'] ?? null,
			'payload'         => $payload,
		] );
	}

	/* ================================================================
	 * §3 — CONTEXT LOG RECORDING
	 * ================================================================ */

	/**
	 * Record a context decision log.
	 *
	 * @param array $data {
	 *   @type string $trace_id
	 *   @type int    $user_id
	 *   @type int    $blog_id
	 *   @type string $path            Required: chat|notebook|intent|execution|studio
	 *   @type string $mode            Optional: emotion|knowledge|planning|execution|ambiguous
	 *   @type string $decision_type   Required: focus|suppress|extend|tool_recommended|tool_executed|clarify
	 *   @type string $decision_label  Optional description.
	 *   @type float  $decision_score  Optional relevance/confidence score.
	 *   @type array  $payload         Optional: extra data.
	 * }
	 * @return int log_id (0 on failure).
	 */
	public static function record_context_log( array $data ): int {
		global $wpdb;
		$table = BizCity_Twin_State_Schema::context_logs_table();

		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return 0;
		}

		$row = [
			'trace_id'       => $data['trace_id'] ?? BizCity_Twin_Data_Contract::current_trace_id(),
			'user_id'        => $data['user_id'] ?? BizCity_Twin_Data_Contract::current_user_id(),
			'blog_id'        => $data['blog_id'] ?? BizCity_Twin_Data_Contract::current_blog_id(),
			'path'           => $data['path'] ?? 'unknown',
			'mode'           => $data['mode'] ?? null,
			'decision_type'  => $data['decision_type'],
			'decision_label' => $data['decision_label'] ?? null,
			'decision_score' => $data['decision_score'] ?? null,
			'payload_json'   => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
		];

		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Auto-record context log from a taxonomy event.
	 */
	private static function record_context_log_from_event( string $event_key, array $payload ): void {
		self::record_context_log( [
			'trace_id'       => $payload['trace_id'] ?? null,
			'user_id'        => $payload['user_id'] ?? null,
			'path'           => $payload['path'] ?? 'system',
			'mode'           => $payload['mode'] ?? null,
			'decision_type'  => $event_key,
			'decision_label' => $payload['tool_name'] ?? $payload['reason'] ?? null,
			'decision_score' => (float) ( $payload['score'] ?? 0 ),
			'payload'        => $payload,
		] );
	}

	/* ================================================================
	 * §4 — CONVENIENCE: Log focus/suppress/extend decisions
	 * ================================================================ */

	/**
	 * Log a focus decision (context was selected as primary focus).
	 */
	public static function log_focus( string $path, string $mode, string $label, float $score = 0.0, array $extra = [] ): int {
		return self::record_context_log( array_merge( [
			'path'           => $path,
			'mode'           => $mode,
			'decision_type'  => 'focus',
			'decision_label' => $label,
			'decision_score' => $score,
		], $extra ) );
	}

	/**
	 * Log a suppress decision (context was excluded from prompt).
	 */
	public static function log_suppress( string $path, string $mode, string $label, string $reason = '', array $extra = [] ): int {
		return self::record_context_log( array_merge( [
			'path'           => $path,
			'mode'           => $mode,
			'decision_type'  => 'suppress',
			'decision_label' => $label,
			'payload'        => [ 'reason' => $reason ],
		], $extra ) );
	}

	/**
	 * Log an extend decision (context was included as supplementary).
	 */
	public static function log_extend( string $path, string $mode, string $label, float $score = 0.0, array $extra = [] ): int {
		return self::record_context_log( array_merge( [
			'path'           => $path,
			'mode'           => $mode,
			'decision_type'  => 'extend',
			'decision_label' => $label,
			'decision_score' => $score,
		], $extra ) );
	}

	/* ================================================================
	 * §5 — QUERY HELPERS
	 * ================================================================ */

	/**
	 * Get recent milestones for a user.
	 *
	 * @param int    $user_id
	 * @param int    $limit
	 * @param string $type     Optional: filter by milestone_type.
	 * @return array
	 */
	public static function get_milestones( int $user_id, int $limit = 20, string $type = '' ): array {
		global $wpdb;
		$table   = BizCity_Twin_State_Schema::milestones_table();
		$blog_id = get_current_blog_id();

		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return [];
		}

		$sql = "SELECT * FROM {$table} WHERE user_id = %d AND blog_id = %d";
		$params = [ $user_id, $blog_id ];

		if ( $type !== '' ) {
			$sql .= ' AND milestone_type = %s';
			$params[] = $type;
		}

		$sql .= ' ORDER BY occurred_at DESC LIMIT %d';
		$params[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: [];
	}

	/**
	 * Get context logs for a trace.
	 *
	 * @param string $trace_id
	 * @return array
	 */
	public static function get_logs_by_trace( string $trace_id ): array {
		global $wpdb;
		$table = BizCity_Twin_State_Schema::context_logs_table();

		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE trace_id = %s ORDER BY created_at ASC",
			$trace_id
		) ) ?: [];
	}

	/**
	 * Get context logs for a user (recent).
	 *
	 * @param int $user_id
	 * @param int $limit
	 * @return array
	 */
	public static function get_recent_logs( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$table   = BizCity_Twin_State_Schema::context_logs_table();
		$blog_id = get_current_blog_id();

		if ( ! bizcity_tbl_exists( $table ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			return [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND blog_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id, $blog_id, $limit
		) ) ?: [];
	}

	/* ================================================================
	 * §6 — PHASE 0.12 — TWIN EVENT STREAM (canonical spine)
	 *
	 * New API: dispatch_v2() / ingest_remote() write to the single
	 * append-only `bizcity_twin_event_stream` table. Synchronous projectors
	 * (registered via filter `bizcity_twin_event_projectors`) materialize
	 * read views (traces, webchat_messages, twin_milestones, …).
	 *
	 * Per R-EVT-1 — every state change MUST go through this path.
	 * Per R-EVT-7 — fail loud on schema violation.
	 *
	 * The legacy dispatch() above is kept only as a backward-compat shim
	 * during Wave 0.12.B cutover; new code MUST use dispatch_v2().
	 * ================================================================ */

	/**
	 * Dispatch a Twin Event into the canonical event stream.
	 *
	 * @param string $event_type   One of BizCity_Twin_Event_Taxonomy::* constants.
	 * @param array  $payload      Type-specific payload (validated against required_fields).
	 * @param array  $opts {
	 *   @type string $event_source       One of allowed_sources(). Default 'system'.
	 *   @type string $trace_id           Default = current trace_id.
	 *   @type int    $user_id            Default = current user.
	 *   @type int    $blog_id            Default = current blog.
	 *   @type int    $conversation_id
	 *   @type string $session_id
	 *   @type string $parent_event_uuid  For causal chain.
	 *   @type int    $schema_version     Default 1.
	 *   @type int    $created_epoch_ms   Default = now (ms).
	 *   @type string $event_uuid         Override (e.g. when re-emitting from server).
	 * }
	 * @return string event_uuid of the persisted event.
	 *
	 * @throws BizCity_Event_Validation_Exception on invalid type/source/payload.
	 */
	public static function dispatch_v2( string $event_type, array $payload, array $opts = [] ): string {
		// 1) Validate taxonomy
		BizCity_Twin_Event_Taxonomy::assert_valid_type( $event_type );

		$source = (string) ( $opts['event_source'] ?? 'system' );
		BizCity_Twin_Event_Taxonomy::assert_valid_source( $source );

		// 2) Validate payload required fields
		BizCity_Twin_Event_Taxonomy::assert_payload_valid( $event_type, $payload );

		// 3) Build envelope
		$event = self::build_envelope( $event_type, $source, $payload, $opts );

		// 4) Persist (single INSERT into event_stream)
		$id = BizCity_Twin_Event_Store::persist( $event );
		if ( $id === 0 ) {
			throw new BizCity_Event_Validation_Exception(
				"Failed to persist event {$event['event_uuid']} (type={$event_type})"
			);
		}
		$event['id'] = $id;

		// 5) Run synchronous projectors (registered via filter)
		self::run_sync_projectors( $event );

		// 6) Fire WP hook for async listeners (analytics, etc.)
		do_action( 'bizcity_twin_event_v2', $event );

		return $event['event_uuid'];
	}

	/**
	 * Ingest a remote event (e.g. from bizcity-llm-router server piggyback SSE).
	 *
	 * Idempotent — dedupes by event_uuid. Validates same as dispatch_v2.
	 * Source must already be set in $event (typically 'server').
	 *
	 * @param array $event Remote event envelope (must include event_uuid, event_type, event_source, payload).
	 * @return string event_uuid (existing or newly inserted).
	 *
	 * @throws BizCity_Event_Validation_Exception on invalid payload.
	 */
	public static function ingest_remote( array $event ): string {
		if ( empty( $event['event_uuid'] ) || ! Bizcity_Uuid::is_valid( $event['event_uuid'] ) ) {
			throw new BizCity_Event_Validation_Exception( 'ingest_remote: missing or invalid event_uuid' );
		}
		// Dedupe
		if ( BizCity_Twin_Event_Store::exists( $event['event_uuid'] ) ) {
			return $event['event_uuid'];
		}

		$event_type = (string) ( $event['event_type'] ?? '' );
		$source     = (string) ( $event['event_source'] ?? 'server' );
		$payload    = (array)  ( $event['payload'] ?? [] );

		BizCity_Twin_Event_Taxonomy::assert_valid_type( $event_type );
		BizCity_Twin_Event_Taxonomy::assert_valid_source( $source );
		BizCity_Twin_Event_Taxonomy::assert_payload_valid( $event_type, $payload );

		// Build a normalized envelope using remote-provided uuid + ts
		$envelope = self::build_envelope( $event_type, $source, $payload, [
			'event_uuid'        => $event['event_uuid'],
			'trace_id'          => $event['trace_id']         ?? null,
			'conversation_id'   => $event['conversation_id']  ?? null,
			'session_id'        => $event['session_id']       ?? null,
			'user_id'           => $event['user_id']          ?? null,
			'blog_id'           => $event['blog_id']          ?? null,
			'parent_event_uuid' => $event['parent_event_uuid']?? null,
			'schema_version'    => (int) ( $event['schema_version'] ?? 1 ),
			'created_epoch_ms'  => (int) ( $event['created_epoch_ms']
				?? Bizcity_Uuid::extract_ts_ms( $event['event_uuid'] )
				?: (int) ( microtime( true ) * 1000 ) ),
		] );

		$id = BizCity_Twin_Event_Store::persist( $envelope );
		if ( $id === 0 ) {
			// Existing row was inserted between exists() check and persist() — fine.
			return $envelope['event_uuid'];
		}
		$envelope['id'] = $id;

		self::run_sync_projectors( $envelope );
		do_action( 'bizcity_twin_event_v2', $envelope );

		return $envelope['event_uuid'];
	}

	/**
	 * Build a fully-normalized event envelope (does NOT persist).
	 *
	 * @internal
	 */
	private static function build_envelope(
		string $event_type,
		string $event_source,
		array $payload,
		array $opts
	): array {
		$now_ms     = (int) ( $opts['created_epoch_ms'] ?? ( microtime( true ) * 1000 ) );
		$event_uuid = (string) ( $opts['event_uuid'] ?? Bizcity_Uuid::v7( $now_ms ) );

		$trace_id = (string) (
			$opts['trace_id']
			?? ( method_exists( 'BizCity_Twin_Data_Contract', 'current_trace_id' )
				? BizCity_Twin_Data_Contract::current_trace_id()
				: '' )
		);
		if ( $trace_id === '' ) {
			// Stream MUST always have a trace_id; synthesize one from event_uuid as last resort.
			$trace_id = 'trace-' . substr( str_replace( '-', '', $event_uuid ), 0, 16 );
		}

		$user_id = isset( $opts['user_id'] )
			? (int) $opts['user_id']
			: (int) (
				method_exists( 'BizCity_Twin_Data_Contract', 'current_user_id' )
					? BizCity_Twin_Data_Contract::current_user_id()
					: get_current_user_id()
			);

		$blog_id = isset( $opts['blog_id'] )
			? (int) $opts['blog_id']
			: (int) (
				method_exists( 'BizCity_Twin_Data_Contract', 'current_blog_id' )
					? BizCity_Twin_Data_Contract::current_blog_id()
					: get_current_blog_id()
			);

		$created_at_dt = sprintf(
			'%s.%03d',
			gmdate( 'Y-m-d H:i:s', (int) ( $now_ms / 1000 ) ),
			$now_ms % 1000
		);

		return [
			'event_uuid'        => $event_uuid,
			'trace_id'          => $trace_id,
			'conversation_id'   => isset( $opts['conversation_id'] ) ? (int) $opts['conversation_id'] : null,
			'session_id'        => $opts['session_id'] ?? null,
			'user_id'           => $user_id,
			'blog_id'           => $blog_id,
			'event_type'        => $event_type,
			'event_source'      => $event_source,
			'parent_event_uuid' => $opts['parent_event_uuid'] ?? null,
			'parent_event_id'   => null, // Resolved by Event_Store on persist
			'payload'           => $payload,
			'payload_json'      => wp_json_encode( $payload ),
			'schema_version'    => (int) ( $opts['schema_version'] ?? 1 ),
			'created_at'        => $created_at_dt,
			'created_epoch_ms'  => $now_ms,
		];
	}

	/**
	 * Run all registered synchronous projectors for an event.
	 *
	 * Projectors register via:
	 *   add_filter('bizcity_twin_event_projectors', function($list) {
	 *     $list[] = [ MyProjector::class, 'project' ];
	 *     return $list;
	 *   });
	 *
	 * Each projector receives the persisted event envelope (with id) and is
	 * responsible for materializing its own read view. Projector failures are
	 * isolated (try/catch + error_log) to avoid blocking the hot path.
	 *
	 * @internal
	 */
	private static function run_sync_projectors( array $event ): void {
		$projectors = apply_filters( 'bizcity_twin_event_projectors', [], $event );
		if ( ! is_array( $projectors ) ) return;

		foreach ( $projectors as $projector ) {
			if ( ! is_callable( $projector ) ) continue;
			try {
				call_user_func( $projector, $event );
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'[BizCity Twin Event Bus] Projector failed for event %s (%s): %s',
					$event['event_uuid'] ?? '?',
					$event['event_type'] ?? '?',
					$e->getMessage()
				) );
			}
		}
	}
}
