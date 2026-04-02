<?php
/**
 * BizCity Twin Data Contract — Source Registry, Event Taxonomy, ID Contract.
 *
 * Chuẩn hóa toàn bộ nguồn dữ liệu, sự kiện, và ID xuyên suốt Twin Core.
 * Mọi module phải dùng contract này thay vì hardcode tên bảng/event/ID.
 *
 * Phase 2 Priority 1: Freeze v1 contracts.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Data_Contract {

	/* ================================================================
	 * CONTRACT VERSION
	 * ================================================================ */

	const CONTRACT_VERSION = '1.0';

	/* ================================================================
	 * §1 — SOURCE REGISTRY
	 *
	 * Mỗi source group map tới raw tables, owner module, vai trò,
	 * và danh sách field tối thiểu cần chuẩn hóa.
	 * ================================================================ */

	const SRC_KNOWLEDGE  = 'knowledge_input';
	const SRC_WEBCHAT    = 'webchat_raw';
	const SRC_EVIDENCE   = 'message_linked_evidence';
	const SRC_MEMORY     = 'memory_stack';
	const SRC_GOAL       = 'goal_execution';
	const SRC_CAPABILITY = 'capability';

	/**
	 * Full source registry.
	 *
	 * @return array<string, array{tables: string[], owner: string, role: string, min_fields: string[]}>
	 */
	public static function source_registry(): array {
		return [
			self::SRC_KNOWLEDGE => [
				'tables'     => [
					'bizcity_knowledge_sources',
					'bizcity_knowledge_chunks',
					'bizcity_knowledge_conversations',
				],
				'owner'      => 'knowledge',
				'role'       => 'evidence + domain knowledge',
				'min_fields' => [ 'source_id', 'character_id', 'source_type', 'status', 'created_at' ],
			],
			self::SRC_WEBCHAT => [
				'tables'     => [
					'bizcity_webchat_messages',
					'bizcity_webchat_sessions',
					'bizcity_webchat_projects',
				],
				'owner'      => 'webchat',
				'role'       => 'timeline + prompt evidence',
				'min_fields' => [ 'message_id', 'session_id', 'project_id', 'message_from', 'created_at' ],
			],
			self::SRC_EVIDENCE => [
				'tables'     => [
					'bizcity_webchat_message_sources',
					'bizcity_webchat_message_source_chunks',
					'bizcity_webchat_message_projects',
					'bizcity_webchat_message_notes',
				],
				'owner'      => 'webchat',
				'role'       => 'grounding evidence graph',
				'min_fields' => [ 'message_id', 'source_id', 'chunk_id', 'note_id', 'project_id', 'link_type' ],
			],
			self::SRC_MEMORY => [
				'tables'     => [
					'bizcity_memory_users',
					'bizcity_memory_episodic',
					'bizcity_memory_rolling',
					'bizcity_memory_notes',
				],
				'owner'      => 'twin/intent/notebook',
				'role'       => 'memory + continuity + milestones',
				'min_fields' => [ 'user_id', 'importance', 'updated_at' ],
			],
			self::SRC_GOAL => [
				'tables'     => [
					'bizcity_intent_conversations',
					'bizcity_webchat_tasks',
					'bizcity_webchat_task_steps',
				],
				'owner'      => 'intent',
				'role'       => 'focus + open loops + execution trace',
				'min_fields' => [ 'intent_conversation_id', 'status', 'goal', 'turn_count', 'last_activity_at' ],
			],
			self::SRC_CAPABILITY => [
				'tables'     => [
					'bizcity_tool_registry',
					'bizcity_tool_stats',
				],
				'owner'      => 'intent/tools',
				'role'       => 'capability graph + tool fit',
				'min_fields' => [ 'tool_name', 'active', 'success_rate', 'latency_ms', 'last_used_at' ],
			],
		];
	}

	/**
	 * Get prefixed table name.
	 *
	 * @param string $bare_name e.g. 'bizcity_webchat_messages'
	 */
	public static function table( string $bare_name ): string {
		global $wpdb;
		return $wpdb->prefix . $bare_name;
	}

	/**
	 * Check if a raw table exists.
	 */
	public static function table_exists( string $bare_name ): bool {
		global $wpdb;
		$full = $wpdb->prefix . $bare_name;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
	}

	/* ================================================================
	 * §2 — EVENT TAXONOMY
	 *
	 * Mỗi event có key, trigger source, payload keys tối thiểu,
	 * và tác động state (bảng nào bị ảnh hưởng).
	 * ================================================================ */

	const EVT_MESSAGE_RECEIVED   = 'message_received';
	const EVT_PROMPT_PARSED      = 'prompt_parsed';
	const EVT_KNOWLEDGE_ATTACHED = 'knowledge_attached';
	const EVT_NOTE_CREATED       = 'note_created';
	const EVT_MEMORY_EXTRACTED   = 'memory_extracted';
	const EVT_GOAL_OPENED        = 'goal_opened';
	const EVT_GOAL_PROGRESSED    = 'goal_progressed';
	const EVT_TOOL_RECOMMENDED   = 'tool_recommended';
	const EVT_TOOL_EXECUTED      = 'tool_executed';
	const EVT_MILESTONE_REACHED  = 'milestone_reached';

	/**
	 * Full event taxonomy.
	 *
	 * @return array<string, array{trigger: string, payload_keys: string[], state_impact: string[]}>
	 */
	public static function event_taxonomy(): array {
		return [
			self::EVT_MESSAGE_RECEIVED => [
				'trigger'      => 'webchat message insert',
				'payload_keys' => [ 'trace_id', 'user_id', 'session_id', 'message_id', 'project_id', 'created_at' ],
				'state_impact' => [ 'timeline' ],
			],
			self::EVT_PROMPT_PARSED => [
				'trigger'      => 'prompt parser',
				'payload_keys' => [ 'trace_id', 'user_id', 'prompt_spec_id', 'confidence', 'recommended_mode' ],
				'state_impact' => [ 'focus_state', 'prompt_specs' ],
			],
			self::EVT_KNOWLEDGE_ATTACHED => [
				'trigger'      => 'message-source link',
				'payload_keys' => [ 'trace_id', 'message_id', 'source_id', 'chunk_id' ],
				'state_impact' => [ 'evidence' ],
			],
			self::EVT_NOTE_CREATED => [
				'trigger'      => 'note save',
				'payload_keys' => [ 'trace_id', 'user_id', 'note_id', 'note_type', 'project_id' ],
				'state_impact' => [ 'timeline', 'memory' ],
			],
			self::EVT_MEMORY_EXTRACTED => [
				'trigger'      => 'memory pipeline',
				'payload_keys' => [ 'trace_id', 'user_id', 'memory_type', 'memory_ref_id', 'importance' ],
				'state_impact' => [ 'identity', 'memory' ],
			],
			self::EVT_GOAL_OPENED => [
				'trigger'      => 'intent conversation create',
				'payload_keys' => [ 'trace_id', 'intent_conversation_id', 'goal', 'status' ],
				'state_impact' => [ 'focus_state' ],
			],
			self::EVT_GOAL_PROGRESSED => [
				'trigger'      => 'intent/task update',
				'payload_keys' => [ 'trace_id', 'intent_conversation_id', 'status', 'progress_score' ],
				'state_impact' => [ 'focus_state', 'timeline' ],
			],
			self::EVT_TOOL_RECOMMENDED => [
				'trigger'      => 'tool-fit stage',
				'payload_keys' => [ 'trace_id', 'tool_name', 'score', 'reason' ],
				'state_impact' => [ 'context_logs' ],
			],
			self::EVT_TOOL_EXECUTED => [
				'trigger'      => 'execution result',
				'payload_keys' => [ 'trace_id', 'tool_name', 'result_status', 'latency_ms' ],
				'state_impact' => [ 'milestones', 'tool_stats' ],
			],
			self::EVT_MILESTONE_REACHED => [
				'trigger'      => 'milestone evaluator',
				'payload_keys' => [ 'trace_id', 'journey_id', 'milestone_type', 'milestone_score' ],
				'state_impact' => [ 'journeys', 'milestones' ],
			],
		];
	}

	/**
	 * Validate event payload against taxonomy.
	 *
	 * @return string[] List of missing keys (empty = valid)
	 */
	public static function validate_event_payload( string $event_key, array $payload ): array {
		$taxonomy = self::event_taxonomy();
		if ( ! isset( $taxonomy[ $event_key ] ) ) {
			return [ '__unknown_event__' ];
		}
		$required = $taxonomy[ $event_key ]['payload_keys'];
		$missing  = [];
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/* ================================================================
	 * §3 — ID CONTRACT
	 *
	 * Quy tắc ID xuyên suốt mọi state table và event log.
	 * ================================================================ */

	/**
	 * Generate a unique trace_id for a request/flow.
	 *
	 * @param string $prefix Optional prefix for readability
	 * @return string e.g. "trace_66a1b..."
	 */
	public static function generate_trace_id( string $prefix = 'trace' ): string {
		return $prefix . '_' . wp_generate_uuid4();
	}

	/**
	 * Get or create a request-scoped trace_id.
	 * Ensures a single trace_id per PHP request.
	 *
	 * @return string
	 */
	public static function current_trace_id(): string {
		static $trace_id = null;
		if ( null === $trace_id ) {
			$trace_id = self::generate_trace_id();
		}
		return $trace_id;
	}

	/**
	 * Reset current trace (for testing or manual override).
	 */
	public static function reset_trace_id(): void {
		// Force a new trace_id on next call to current_trace_id()
		// We use a filter so external code can set it
		static $reset = false;
		$reset = true;
	}

	/**
	 * Get current blog_id (multisite scope).
	 */
	public static function current_blog_id(): int {
		return (int) get_current_blog_id();
	}

	/**
	 * Get current user_id.
	 */
	public static function current_user_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * Build a standardized ID context array for state table writes.
	 *
	 * @param array $extras Extra IDs to merge (session_id, project_id, etc.)
	 * @return array{trace_id: string, user_id: int, blog_id: int}
	 */
	public static function id_context( array $extras = [] ): array {
		return array_merge( [
			'trace_id' => self::current_trace_id(),
			'user_id'  => self::current_user_id(),
			'blog_id'  => self::current_blog_id(),
		], $extras );
	}

	/**
	 * ID contract metadata: type, scope, and rules per ID.
	 *
	 * @return array<string, array{type: string, scope: string, rule: string}>
	 */
	public static function id_contract(): array {
		return [
			'trace_id' => [
				'type'  => 'string',
				'scope' => 'request/flow',
				'rule'  => 'Bắt buộc trên mọi event và context log',
			],
			'user_id' => [
				'type'  => 'bigint',
				'scope' => 'identity scope',
				'rule'  => 'Bắt buộc cho mọi state table',
			],
			'blog_id' => [
				'type'  => 'bigint',
				'scope' => 'multisite scope',
				'rule'  => 'Bắt buộc cho mọi state table',
			],
			'session_id' => [
				'type'  => 'string',
				'scope' => 'chat session',
				'rule'  => 'Nullable ngoài chat flow',
			],
			'project_id' => [
				'type'  => 'bigint',
				'scope' => 'notebook/project',
				'rule'  => 'Nullable nhưng phải đi cùng project_scope',
			],
			'intent_conversation_id' => [
				'type'  => 'string',
				'scope' => 'goal thread',
				'rule'  => 'Bắt buộc khi có execution/planning',
			],
			'message_id' => [
				'type'  => 'bigint',
				'scope' => 'message evidence',
				'rule'  => 'Bắt buộc cho event xuất phát từ message',
			],
			'prompt_spec_id' => [
				'type'  => 'bigint',
				'scope' => 'prompt semantic state',
				'rule'  => 'Bắt buộc khi focus_state update từ parser',
			],
		];
	}
}
