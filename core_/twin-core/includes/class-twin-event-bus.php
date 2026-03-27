<?php
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

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
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

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
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

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
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

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
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

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND blog_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id, $blog_id, $limit
		) ) ?: [];
	}
}
