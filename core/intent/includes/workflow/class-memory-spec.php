<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Spec — Pipeline Working Brief
 *
 * Stores a compact working brief in bizcity_tasks.params.meta.memory_spec
 * so the assistant always knows: what goal, which step, what's missing.
 *
 * @since Phase 1.2 v2.5
 * @see   PHASE-1.2-SKILL-PIPELINE-INTEGRATION.md §17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Memory_Spec {

	const VERSION    = 1;
	const STALE_HOURS = 4;
	const LOG        = '[Memory-Spec]';

	/* ──────────────────────────────────────────────
	 *  A. build_from_pipeline — khi pipeline mới tạo
	 * ────────────────────────────────────────────── */

	/**
	 * Build initial memory_spec from scenario graph + context.
	 *
	 * Called right after save_draft_task() in Scenario Generator.
	 *
	 * @param int   $task_id  Row ID in bizcity_tasks.
	 * @param array $scenario { nodes, edges, settings }.
	 * @param array $context  { user_id, session_id, channel }.
	 * @return array The built memory_spec.
	 */
	public static function build_from_pipeline( int $task_id, array $scenario, array $context = [] ): array {
		$nodes = $scenario['nodes'] ?? [];
		$settings = $scenario['settings'] ?? [];

		// Build checklist from action nodes (skip trigger node)
		$checklist = [];
		$step = 0;
		foreach ( $nodes as $node ) {
			$type = $node['type'] ?? '';
			if ( $type === 'trigger' ) {
				continue;
			}
			$checklist[] = [
				'step'    => $step,
				'node_id' => $node['id'] ?? (string) $step,
				'label'   => $node['data']['label'] ?? ( $node['data']['tool'] ?? 'step_' . $step ),
				'tool'    => $node['data']['tool'] ?? '',
				'status'  => 'PENDING',
			];
			$step++;
		}

		$pipeline_id = $settings['pipeline_id'] ?? '';
		$goal_label  = $settings['description'] ?? '';

		// Infer primary goal from first tool or settings
		$primary_goal = $settings['goal'] ?? ( $checklist[0]['tool'] ?? 'workflow' );

		$spec = [
			'version'    => self::VERSION,
			'scope'      => 'pipeline',
			'session_id' => $context['session_id'] ?? '',
			'updated_at' => current_time( 'mysql', true ),
			'goal'       => [
				'primary' => $primary_goal,
				'label'   => $goal_label,
			],
			'pipeline'   => [
				'pipeline_id'     => $pipeline_id,
				'task_id'         => $task_id,
				'current_step_index' => 0,
				'current_node_id' => $checklist[0]['node_id'] ?? '',
				'total_steps'     => count( $checklist ),
				'completed_steps' => 0,
				'resume_ready'    => false,
			],
			'checklist'      => $checklist,
			'current_focus'  => [
				'step'    => 0,
				'node_id' => $checklist[0]['node_id'] ?? '',
				'action'  => $checklist[0]['tool'] ?? '',
				'reason'  => 'pipeline vừa tạo — bắt đầu bước đầu tiên',
			],
			'missing_fields' => [],
			'open_loops'     => [],
			'next_actions'   => array_slice(
				array_map( fn( $c ) => $c['label'], $checklist ),
				0,
				3
			),
		];

		self::persist( $task_id, $spec );

		error_log( self::LOG . " Built from pipeline: task_id={$task_id}, steps=" . count( $checklist ) );

		return $spec;
	}

	/* ──────────────────────────────────────────────
	 *  B. refresh_on_checkpoint — mỗi node event
	 * ────────────────────────────────────────────── */

	/**
	 * Refresh memory_spec when a pipeline node changes state.
	 *
	 * Hooked to `bizcity_pipeline_node_event` pri 20.
	 *
	 * @param array $event { pipeline_id, node_id, event, tool, error_message? }.
	 * @return bool True if spec was updated.
	 */
	public static function refresh_on_checkpoint( array $event ): bool {
		$pipeline_id = $event['pipeline_id'] ?? '';
		$node_id     = (string) ( $event['node_id'] ?? '' );
		$evt_type    = $event['event'] ?? '';

		if ( empty( $pipeline_id ) || $node_id === '_pipeline' ) {
			return false;
		}

		// Find task_id from pipeline_id via todos
		$task_id = self::resolve_task_id( $pipeline_id );
		if ( ! $task_id ) {
			return false;
		}

		$spec = self::get( $task_id );
		if ( ! $spec ) {
			return false;
		}

		// Map pipeline event → todo status
		$status_map = [
			'started'   => 'IN_PROGRESS',
			'waiting'   => 'WAITING_USER',
			'completed' => 'COMPLETED',
			'failed'    => 'FAILED',
		];
		$new_status = $status_map[ $evt_type ] ?? null;
		if ( ! $new_status ) {
			return false;
		}

		// Update checklist
		$completed_count = 0;
		$current_step    = null;
		foreach ( $spec['checklist'] as &$item ) {
			if ( (string) $item['node_id'] === $node_id ) {
				$item['status'] = $new_status;
			}
			if ( $item['status'] === 'COMPLETED' ) {
				$completed_count++;
			}
			// First non-completed step = current
			if ( $current_step === null && ! in_array( $item['status'], [ 'COMPLETED', 'FAILED', 'CANCELLED', 'SKIPPED' ], true ) ) {
				$current_step = $item;
			}
		}
		unset( $item );

		// Update pipeline progress
		$spec['pipeline']['completed_steps'] = $completed_count;

		if ( $current_step ) {
			$spec['pipeline']['current_step_index'] = $current_step['step'];
			$spec['pipeline']['current_node_id']    = $current_step['node_id'];
			$spec['pipeline']['resume_ready']       = in_array( $current_step['status'], [ 'PENDING', 'WAITING_USER' ], true );

			$spec['current_focus'] = [
				'step'    => $current_step['step'],
				'node_id' => $current_step['node_id'],
				'action'  => $current_step['tool'],
				'reason'  => $current_step['status'] === 'WAITING_USER'
					? 'chờ user cung cấp thông tin'
					: 'đang thực hiện',
			];
		}

		// Rebuild missing_fields from WAITING items
		$spec['missing_fields'] = [];
		foreach ( $spec['checklist'] as $item ) {
			if ( $item['status'] === 'WAITING_USER' ) {
				$spec['missing_fields'][] = [
					'field'    => $item['tool'] . '_input',
					'reason'   => 'cần user xác nhận / cung cấp dữ liệu',
					'for_tool' => $item['tool'],
				];
			}
		}

		// Rebuild next_actions (max 3) from pending steps
		$spec['next_actions'] = [];
		foreach ( $spec['checklist'] as $item ) {
			if ( count( $spec['next_actions'] ) >= 3 ) {
				break;
			}
			if ( in_array( $item['status'], [ 'PENDING', 'WAITING_USER', 'IN_PROGRESS' ], true ) ) {
				$spec['next_actions'][] = $item['label'];
			}
		}

		// Trim open_loops to max 3
		$spec['open_loops'] = array_slice( $spec['open_loops'] ?? [], 0, 3 );

		$spec['updated_at'] = current_time( 'mysql', true );

		self::persist( $task_id, $spec );

		// ── Phase 1.6 B4-B5: Sync session spec from task spec ──
		if ( class_exists( 'BizCity_Session_Memory_Spec' ) && BizCity_Session_Memory_Spec::is_enabled() ) {
			$sess_id = isset( $spec['session_id'] ) ? $spec['session_id'] : '';
			if ( ! empty( $sess_id ) ) {
				BizCity_Session_Memory_Spec::sync_from_task( $sess_id, $spec );
			}
		}

		error_log( self::LOG . " Checkpoint: task_id={$task_id}, node={$node_id}, event={$evt_type}" );

		return true;
	}

	/* ──────────────────────────────────────────────
	 *  C. build_from_todos — rebuild khi resume
	 * ────────────────────────────────────────────── */

	/**
	 * Rebuild memory_spec from DB (todos + task params) when resuming.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return array|null The rebuilt spec, or null on failure.
	 */
	public static function build_from_todos( string $pipeline_id ): ?array {
		if ( ! class_exists( 'BizCity_Intent_Todos' ) ) {
			return null;
		}

		$todos = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
		if ( empty( $todos ) ) {
			return null;
		}

		$task_id = (int) ( $todos[0]['task_id'] ?? 0 );
		if ( ! $task_id ) {
			return null;
		}

		// Load existing spec or start fresh
		$existing = self::get( $task_id );

		// Build checklist from todos
		$checklist       = [];
		$completed_count = 0;
		$current_step    = null;

		foreach ( $todos as $idx => $todo ) {
			$item = [
				'step'    => (int) ( $todo['step_index'] ?? $idx ),
				'node_id' => $todo['node_id'] ?? (string) $idx,
				'label'   => $todo['label'] ?? ( $todo['tool_name'] ?? 'step_' . $idx ),
				'tool'    => $todo['tool_name'] ?? '',
				'status'  => $todo['status'] ?? 'PENDING',
			];
			$checklist[] = $item;

			if ( $item['status'] === 'COMPLETED' ) {
				$completed_count++;
			}
			if ( $current_step === null && ! in_array( $item['status'], [ 'COMPLETED', 'FAILED', 'CANCELLED', 'SKIPPED' ], true ) ) {
				$current_step = $item;
			}
		}

		$spec = [
			'version'    => self::VERSION,
			'scope'      => 'pipeline',
			'session_id' => $existing['session_id'] ?? '',
			'updated_at' => current_time( 'mysql', true ),
			'goal'       => $existing['goal'] ?? [
				'primary' => $checklist[0]['tool'] ?? 'workflow',
				'label'   => '',
			],
			'pipeline' => [
				'pipeline_id'        => $pipeline_id,
				'task_id'            => $task_id,
				'current_step_index' => $current_step ? $current_step['step'] : 0,
				'current_node_id'    => $current_step ? $current_step['node_id'] : '',
				'total_steps'        => count( $checklist ),
				'completed_steps'    => $completed_count,
				'resume_ready'       => $current_step !== null,
			],
			'checklist'     => $checklist,
			'current_focus' => $current_step ? [
				'step'    => $current_step['step'],
				'node_id' => $current_step['node_id'],
				'action'  => $current_step['tool'],
				'reason'  => 'resume từ DB — tiếp tục pipeline',
			] : [],
			'missing_fields' => [],
			'open_loops'     => $existing['open_loops'] ?? [],
			'next_actions'   => [],
		];

		// Populate missing_fields + next_actions
		foreach ( $checklist as $item ) {
			if ( $item['status'] === 'WAITING_USER' ) {
				$spec['missing_fields'][] = [
					'field'    => $item['tool'] . '_input',
					'reason'   => 'cần user cung cấp dữ liệu',
					'for_tool' => $item['tool'],
				];
			}
			if ( count( $spec['next_actions'] ) < 3 && in_array( $item['status'], [ 'PENDING', 'WAITING_USER', 'IN_PROGRESS' ], true ) ) {
				$spec['next_actions'][] = $item['label'];
			}
		}

		self::persist( $task_id, $spec );

		error_log( self::LOG . " Rebuilt from todos: pipeline={$pipeline_id}, task_id={$task_id}, steps=" . count( $checklist ) );

		return $spec;
	}

	/* ──────────────────────────────────────────────
	 *  D. get — đọc spec hiện tại
	 * ────────────────────────────────────────────── */

	/**
	 * Get current memory_spec for a task.
	 *
	 * @param int $task_id Task row ID.
	 * @return array|null The spec or null.
	 */
	public static function get( int $task_id ): ?array {
		$params = self::load_params( $task_id );
		if ( ! $params ) {
			return null;
		}
		return $params['meta']['memory_spec'] ?? null;
	}

	/* ──────────────────────────────────────────────
	 *  E. format_for_prompt — render cho system prompt
	 * ────────────────────────────────────────────── */

	/**
	 * Format memory_spec as a human-readable block for system prompt injection.
	 *
	 * @param array $spec The memory_spec array.
	 * @return string Formatted prompt block.
	 */
	public static function format_for_prompt( array $spec ): string {
		$goal_label      = $spec['goal']['label'] ?? $spec['goal']['primary'] ?? '';
		$total           = $spec['pipeline']['total_steps'] ?? 0;
		$current_idx     = ( $spec['pipeline']['current_step_index'] ?? 0 ) + 1;
		$current_focus   = $spec['current_focus'] ?? [];
		$focus_label     = $current_focus['action'] ?? '';
		$focus_status    = '';

		// Determine current node status from checklist
		foreach ( $spec['checklist'] ?? [] as $item ) {
			if ( isset( $current_focus['node_id'] ) && (string) $item['node_id'] === (string) $current_focus['node_id'] ) {
				$focus_status = $item['status'] ?? '';
				break;
			}
		}

		$lines = [];
		$lines[] = '---';
		$lines[] = '## 📋 PIPELINE ĐANG THỰC HIỆN';
		$lines[] = "Mục tiêu: {$goal_label}";
		$lines[] = "Đang ở bước: {$current_idx}/{$total} — {$focus_label}" . ( $focus_status ? " ({$focus_status})" : '' );
		$lines[] = '';
		$lines[] = '### Checklist:';

		$status_icons = [
			'COMPLETED'    => '✅',
			'WAITING_USER' => '⏳',
			'IN_PROGRESS'  => '🔄',
			'FAILED'       => '❌',
			'CANCELLED'    => '⏭️',
			'SKIPPED'      => '⏭️',
		];

		foreach ( $spec['checklist'] ?? [] as $item ) {
			$st   = $item['status'] ?? '';
			$icon = isset( $status_icons[ $st ] ) ? $status_icons[ $st ] : '⬜';
			$line = "{$icon} {$item['label']} ({$item['tool']}) — {$st}";
			$lines[] = $line;
		}

		if ( ! empty( $spec['missing_fields'] ) ) {
			$lines[] = '';
			$lines[] = '### Cần user:';
			foreach ( $spec['missing_fields'] as $mf ) {
				$lines[] = "- {$mf['field']}: {$mf['reason']}";
			}
		}

		if ( ! empty( $spec['next_actions'] ) ) {
			$lines[] = '';
			$lines[] = '### Hành động tiếp theo:';
			foreach ( $spec['next_actions'] as $na ) {
				$lines[] = "- {$na}";
			}
		}

		$lines[] = '';
		$lines[] = '⚠️ KHÔNG hỏi lại thông tin đã có. KHÔNG đề xuất mục tiêu khác. Bám theo checklist.';
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/* ──────────────────────────────────────────────
	 *  F. inject_if_active — filter cho Chat Gateway
	 * ────────────────────────────────────────────── */

	/**
	 * Inject memory_spec into system prompt if user has an active pipeline.
	 *
	 * Hooked to priority 1.5 in build_system_prompt() (between Memory and Profile).
	 * Called directly from Chat Gateway, not via filter.
	 *
	 * @param int    $user_id    Current user ID.
	 * @param string $session_id Current session ID.
	 * @return string Formatted spec block, or empty string.
	 */
	public static function inject_if_active( int $user_id, string $session_id = '' ): string {
		if ( ! class_exists( 'BizCity_Intent_Todos' ) ) {
			return '';
		}

		// Check Focus Gate — skip for emotion mode
		if ( class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'skill' ) ) {
			return '';
		}

		$active = BizCity_Intent_Todos::find_active_pipeline( $user_id );
		if ( ! $active || empty( $active['task_id'] ) ) {
			return '';
		}

		$task_id = (int) $active['task_id'];
		$spec    = self::get( $task_id );

		if ( ! $spec ) {
			return '';
		}

		// Stale check — skip if older than STALE_HOURS
		if ( ! empty( $spec['updated_at'] ) ) {
			$updated = strtotime( $spec['updated_at'] );
			if ( $updated && ( time() - $updated ) > self::STALE_HOURS * 3600 ) {
				return '';
			}
		}

		$block = self::format_for_prompt( $spec );
		if ( $block ) {
			error_log( '[Memory-Spec] Pipeline spec injected | task=' . $task_id . ' | len=' . strlen( $block ) );
		}
		return $block;
	}

	/* ══════════════════════════════════════════════
	 *  PRIVATE HELPERS
	 * ══════════════════════════════════════════════ */

	/**
	 * Persist memory_spec into bizcity_tasks.params.meta.
	 *
	 * @param int   $task_id Task row ID.
	 * @param array $spec    The memory_spec array.
	 * @return bool True on success.
	 */
	public static function persist( int $task_id, array $spec ): bool {
		global $wpdb;
		$table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

		$params = self::load_params( $task_id );
		if ( ! $params ) {
			return false;
		}

		if ( ! isset( $params['meta'] ) ) {
			$params['meta'] = [];
		}
		$params['meta']['memory_spec'] = $spec;

		$json = wp_json_encode( $params, JSON_UNESCAPED_UNICODE );

		$updated = $wpdb->update(
			$table,
			[ 'params' => $json, 'updated' => current_time( 'mysql', true ) ],
			[ 'id' => $task_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return $updated !== false;
	}

	/**
	 * Load params array from bizcity_tasks.
	 *
	 * @param int $task_id Task row ID.
	 * @return array|null Decoded params or null.
	 */
	private static function load_params( int $task_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT params FROM {$table} WHERE id = %d",
			$task_id
		) );

		if ( empty( $raw ) ) {
			return null;
		}

		$params = json_decode( $raw, true );
		return is_array( $params ) ? $params : null;
	}

	/**
	 * Resolve task_id from pipeline_id via todos table.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return int Task ID, or 0 if not found.
	 */
	private static function resolve_task_id( string $pipeline_id ): int {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		$task_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT task_id FROM {$table} WHERE pipeline_id = %s LIMIT 1",
			$pipeline_id
		) );

		return (int) $task_id;
	}

	/* ══════════════════════════════════════════════
	 *  SESSION MEMORY SPEC — Phase 1.11 S1
	 *
	 *  Session-scoped memory that persists across turns
	 *  even WITHOUT an active pipeline. Stored in
	 *  bizcity_intent_conversations.session_memory_spec.
	 * ══════════════════════════════════════════════ */

	/**
	 * Load session memory spec from conversation row.
	 *
	 * @param array $conversation Conversation row (from get_or_create).
	 * @return array|null Decoded session spec or null.
	 */
	public static function load_session( array $conversation ): ?array {
		$raw = $conversation['session_memory_spec'] ?? '';
		if ( empty( $raw ) ) {
			return null;
		}

		$spec = json_decode( $raw, true );
		if ( ! is_array( $spec ) ) {
			return null;
		}

		// Stale check — ignore if older than STALE_HOURS
		if ( ! empty( $spec['updated_at'] ) ) {
			$updated = strtotime( $spec['updated_at'] );
			if ( $updated && ( time() - $updated ) > self::STALE_HOURS * 3600 ) {
				error_log( self::LOG . ' Session spec stale, ignoring.' );
				return null;
			}
		}

		return $spec;
	}

	/**
	 * Save session memory spec to conversation.
	 *
	 * @param string $conversation_id Conversation UUID.
	 * @param array  $spec            Session memory spec to save.
	 * @return bool True on success.
	 */
	public static function save_session( string $conversation_id, array $spec ): bool {
		if ( empty( $conversation_id ) ) {
			return false;
		}

		$spec['version']    = self::VERSION;
		$spec['scope']      = 'session';
		$spec['updated_at'] = current_time( 'mysql', true );

		// Trim arrays to prevent bloat
		if ( isset( $spec['open_loops'] ) ) {
			$spec['open_loops'] = array_slice( $spec['open_loops'], 0, 5 );
		}
		if ( isset( $spec['next_actions'] ) ) {
			$spec['next_actions'] = array_slice( $spec['next_actions'], 0, 5 );
		}

		$json = wp_json_encode( $spec, JSON_UNESCAPED_UNICODE );

		if ( ! class_exists( 'BizCity_Intent_Database' ) ) {
			return false;
		}

		// Use the DB layer's update method
		$db = BizCity_Intent_Database::instance();
		return $db->update_conversation( $conversation_id, [
			'session_memory_spec' => $json,
		] );
	}

	/**
	 * Merge server-returned memory spec into existing session spec.
	 *
	 * Server may return partial updates (e.g. new open_loops, updated mode).
	 * We merge non-null fields, preserving local data where server is silent.
	 *
	 * @param array $existing    Current session spec (from load_session).
	 * @param array $server_spec Server-returned memory_spec.
	 * @return array Merged spec (ready for save_session).
	 */
	public static function merge_from_server( array $existing, array $server_spec ): array {
		// Fields that server can override (non-null server values win)
		$override_fields = [ 'mode', 'focus', 'pipeline_ref' ];
		foreach ( $override_fields as $field ) {
			if ( isset( $server_spec[ $field ] ) && $server_spec[ $field ] !== null ) {
				$existing[ $field ] = $server_spec[ $field ];
			}
		}

		// Arrays: merge + deduplicate
		if ( ! empty( $server_spec['open_loops'] ) ) {
			$local  = $existing['open_loops'] ?? [];
			$merged = array_values( array_unique( array_merge( $local, $server_spec['open_loops'] ) ) );
			$existing['open_loops'] = array_slice( $merged, 0, 5 );
		}

		if ( ! empty( $server_spec['next_actions'] ) ) {
			// Server next_actions fully replace (they're more accurate)
			$existing['next_actions'] = array_slice( $server_spec['next_actions'], 0, 5 );
		}

		return $existing;
	}

	/**
	 * Build a fresh session spec from server classifier response.
	 *
	 * Used when no existing session spec exists and server returns one.
	 *
	 * @param array  $server_spec Server-returned memory_spec.
	 * @param string $session_id  Session identifier.
	 * @return array Session spec ready for save_session.
	 */
	public static function build_session_from_server( array $server_spec, string $session_id = '' ): array {
		return [
			'version'      => self::VERSION,
			'scope'        => 'session',
			'session_id'   => $session_id,
			'updated_at'   => current_time( 'mysql', true ),
			'mode'         => $server_spec['mode'] ?? '',
			'focus'        => $server_spec['focus'] ?? '',
			'open_loops'   => array_slice( (array) ( $server_spec['open_loops'] ?? [] ), 0, 5 ),
			'next_actions' => array_slice( (array) ( $server_spec['next_actions'] ?? [] ), 0, 5 ),
			'pipeline_ref' => $server_spec['pipeline_ref'] ?? '',
		];
	}

	/**
	 * Format session spec for system prompt injection.
	 *
	 * @param array $spec Session memory spec.
	 * @return string Formatted prompt block.
	 */
	public static function format_session_for_prompt( array $spec ): string {
		$lines   = [];
		$lines[] = '---';
		$lines[] = '## 🧠 SESSION CONTEXT';

		if ( ! empty( $spec['mode'] ) ) {
			$lines[] = 'Mode: ' . $spec['mode'];
		}
		if ( ! empty( $spec['focus'] ) ) {
			$lines[] = 'Focus: ' . $spec['focus'];
		}
		if ( ! empty( $spec['open_loops'] ) ) {
			$lines[] = 'Open loops: ' . implode( ', ', $spec['open_loops'] );
		}
		if ( ! empty( $spec['next_actions'] ) ) {
			$lines[] = 'Next: ' . implode( ', ', $spec['next_actions'] );
		}

		$lines[] = '---';
		return implode( "\n", $lines );
	}
}
