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
 * BizCity Pipeline Resume — Rebuild Execution State from DB
 *
 * When transient cache expires or is evicted, this class rebuilds the
 * execution state from the two DB sources of truth:
 *   - bizcity_tasks.params  → canonical workflow graph
 *   - bizcity_intent_todos  → execution ledger (status, input/output per node)
 *
 * Implements contracts from PHASE-1.2 §3.4.2 Sections E–H:
 *   E. Resume contract — rebuild safely
 *   F. Ready contract — 6 conditions before resume
 *   G. Mismatch policy — detect & handle
 *   H. Pipeline versioning — lock on version drift
 *
 * @package BizCity_Intent
 * @since   4.3.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Pipeline_Resume {

	/** @var string Log prefix */
	private const LOG = '[PipelineResume]';

	/**
	 * Resume a pipeline from DB when transient is lost.
	 *
	 * Steps (§3.4.2-E):
	 *   1. Load todos by pipeline_id
	 *   2. Load canonical graph from bizcity_tasks via task_id
	 *   3. Validate ready contract (6 conditions)
	 *   4. Rebuild completed variables from node_output_json
	 *   5. Find next pending node
	 *   6. Reconstruct execution_state transient
	 *   7. Execute from that node
	 *
	 * @param string $pipeline_id Pipeline identifier (e.g. 'pipe_28_1774000000').
	 * @param int    $user_id     Owner user ID.
	 * @param string $session_id  Chat session ID.
	 * @param string $channel     Channel (webchat, adminchat, etc.).
	 * @return array { success: bool, execution_id: string, status: string, error: string }
	 */
	public static function resume( string $pipeline_id, int $user_id = 0, string $session_id = '', string $channel = 'webchat' ): array {
		error_log( self::LOG . ' Resume requested: pipeline_id=' . $pipeline_id );

		// ── 1. Validate contract ──
		$contract = self::validate_contract( $pipeline_id );

		if ( ! $contract['ready'] ) {
			error_log( self::LOG . ' ❌ Contract failed: ' . $contract['error'] );
			return [
				'success'      => false,
				'execution_id' => '',
				'status'       => $contract['status'],
				'error'        => $contract['error'],
			];
		}

		$todos       = $contract['todos'];
		$task_id     = $contract['task_id'];
		$graph       = $contract['graph'];
		$nodes       = $graph['nodes'];
		$edges       = $graph['edges'] ?? [];

		// ── 2. Rebuild variables from completed todos ──
		$variables = self::rebuild_variables( $todos );
		error_log( self::LOG . ' Rebuilt variables from ' . count( $variables ) . ' completed nodes' );

		// ── 2.5 Rebuild Memory Spec from todos (Phase 1.2 §17) ──
		if ( class_exists( 'BizCity_Memory_Spec' ) ) {
			BizCity_Memory_Spec::build_from_todos( $pipeline_id );
		}

		// ── 3. Determine visited nodes + next pending node ──
		$visited_nodes = [];
		$next_node_id  = null;

		foreach ( $todos as $todo ) {
			if ( in_array( $todo['status'], [ 'COMPLETED', 'FAILED', 'CANCELLED', 'SKIPPED' ], true ) ) {
				if ( ! empty( $todo['node_id'] ) ) {
					$visited_nodes[] = $todo['node_id'];
				}
			}
		}

		// Find first non-terminal todo → that's where we resume
		foreach ( $todos as $todo ) {
			if ( in_array( $todo['status'], [ 'PENDING', 'IN_PROGRESS', 'WAITING_USER' ], true ) ) {
				$next_node_id = $todo['node_id'] ?? null;
				break;
			}
		}

		// Also add trigger node to visited (always already executed)
		foreach ( $nodes as $node ) {
			if ( ( $node['type'] ?? '' ) === 'trigger' && ! in_array( $node['id'], $visited_nodes, true ) ) {
				$visited_nodes[] = $node['id'];
			}
		}

		if ( ! $next_node_id ) {
			// All todos are terminal — pipeline is already done
			error_log( self::LOG . ' ⚠️ All todos are terminal — nothing to resume' );
			return [
				'success'      => false,
				'execution_id' => '',
				'status'       => 'ALREADY_COMPLETE',
				'error'        => 'Pipeline already completed — no pending steps.',
			];
		}

		error_log( self::LOG . ' Next node to resume: ' . $next_node_id . ' | Visited: ' . implode( ',', $visited_nodes ) );

		// ── 4. Build node_step_map ──
		$node_step_map = [];
		$step_idx      = 0;
		foreach ( $nodes as $node ) {
			if ( ( $node['type'] ?? '' ) !== 'trigger' ) {
				$node_step_map[ $node['id'] ] = $step_idx;
				$step_idx++;
			}
		}

		// ── 5. Reconstruct execution_state ──
		$execution_id = 'waic_exec_' . $task_id . '_' . time();

		$execution_state = [
			'execution_id'           => $execution_id,
			'task_id'                => $task_id,
			'status'                 => 'running',
			'mode'                   => 'test',
			'started_at'             => current_time( 'mysql' ),
			'current_node'           => null,
			'nodes'                  => $nodes,
			'edges'                  => $edges,
			'test_data'              => [],
			'node_status'            => [],
			'variables'              => $variables,
			'logs'                   => [ [
				'timestamp' => current_time( 'mysql' ),
				'level'     => 'NOTICE',
				'message'   => 'Resumed from DB — transient rebuilt',
			] ],
			'error'                  => null,
			'visited_nodes'          => $visited_nodes,
			'pipeline_id'            => $pipeline_id,
			'user_id'                => $user_id,
			'session_id'             => $session_id,
			'channel'                => $channel,
			'intent_conversation_id' => '',
			'node_step_map'          => $node_step_map,
			'pending_delay_node'     => $next_node_id,
			'last_sourceHandle'      => 'output-right',
			'resumed_from_db'        => true,
		];

		// Mark completed visited nodes in node_status
		foreach ( $visited_nodes as $vid ) {
			$execution_state['node_status'][ $vid ] = 'success';
		}

		// ── 6. Persist reconstructed state ──
		set_transient( $execution_id, $execution_state, 3600 );
		update_option( 'waic_active_execution_' . $task_id, $execution_id );

		error_log( self::LOG . ' ✅ Execution state rebuilt: ' . $execution_id . ' | task_id=' . $task_id );

		// ── 7. Execute via BFS from the pending node ──
		if ( class_exists( 'WaicWorkflowExecuteAPI' ) ) {
			$api = WaicWorkflowExecuteAPI::getInstance();
			$api->executeWorkflowBackground( $execution_id );

			error_log( self::LOG . ' ✅ Execution resumed via WaicWorkflowExecuteAPI' );

			return [
				'success'      => true,
				'execution_id' => $execution_id,
				'status'       => 'RESUMED',
				'error'        => '',
			];
		}

		error_log( self::LOG . ' ❌ WaicWorkflowExecuteAPI not available' );
		return [
			'success'      => false,
			'execution_id' => $execution_id,
			'status'       => 'EXECUTOR_MISSING',
			'error'        => 'Workflow executor class not loaded.',
		];
	}

	/**
	 * Validate the 6 Ready Contract conditions (§3.4.2-F).
	 *
	 * Returns structured result with all context needed for resume.
	 *
	 * @param string $pipeline_id
	 * @return array { ready: bool, status: string, error: string, todos: array, task_id: int, graph: array, mismatches: array }
	 */
	public static function validate_contract( string $pipeline_id ): array {
		$result = [
			'ready'      => false,
			'status'     => 'UNKNOWN',
			'error'      => '',
			'todos'      => [],
			'task_id'    => 0,
			'graph'      => [],
			'mismatches' => [],
		];

		// ── Condition 1: Load todos ──
		if ( ! class_exists( 'BizCity_Intent_Todos' ) ) {
			$result['status'] = 'CLASS_MISSING';
			$result['error']  = 'BizCity_Intent_Todos class not loaded.';
			return $result;
		}

		$todos = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
		if ( empty( $todos ) ) {
			$result['status'] = 'NO_TODOS';
			$result['error']  = 'No todo rows found for pipeline_id=' . $pipeline_id;
			return $result;
		}
		$result['todos'] = $todos;

		// ── Condition 1: task_id hợp lệ ──
		$task_id = 0;
		foreach ( $todos as $t ) {
			if ( ! empty( $t['task_id'] ) ) {
				$task_id = (int) $t['task_id'];
				break;
			}
		}
		if ( $task_id <= 0 ) {
			$result['status'] = 'NO_TASK_ID';
			$result['error']  = 'No valid task_id found in todo rows.';
			return $result;
		}
		$result['task_id'] = $task_id;

		// ── Condition 2: Load canonical graph ──
		$graph = self::load_canonical_graph( $task_id );
		if ( ! $graph || empty( $graph['nodes'] ) ) {
			$result['status'] = 'INVALID_GRAPH';
			$result['error']  = 'bizcity_tasks.params is missing or invalid for task_id=' . $task_id;
			return $result;
		}
		$result['graph'] = $graph;

		// ── Condition 3: Every todo has pipeline_id + (node_id OR tool_name) ──
		foreach ( $todos as $i => $todo ) {
			if ( empty( $todo['pipeline_id'] ) ) {
				$result['status'] = 'BROKEN_TODO';
				$result['error']  = 'Todo row #' . $i . ' missing pipeline_id.';
				return $result;
			}
			if ( empty( $todo['node_id'] ) && empty( $todo['tool_name'] ) ) {
				$result['status'] = 'BROKEN_TODO';
				$result['error']  = 'Todo row #' . $i . ' has neither node_id nor tool_name.';
				return $result;
			}
		}

		// ── Condition 4: pipeline_version alignment ──
		$graph_version = (int) ( $graph['meta']['pipeline_version'] ?? 1 );
		foreach ( $todos as $i => $todo ) {
			$todo_version = (int) ( $todo['pipeline_version'] ?? 1 );
			if ( $todo_version !== $graph_version ) {
				$result['status']     = 'VERSION_MISMATCH';
				$result['error']      = sprintf(
					'pipeline_version mismatch: graph=%d, todo row #%d=%d. Resume locked — re-sync required.',
					$graph_version, $i, $todo_version
				);
				$result['mismatches'][] = [
					'type'          => 'version',
					'graph_version' => $graph_version,
					'todo_index'    => $i,
					'todo_version'  => $todo_version,
				];
				return $result;
			}
		}

		// ── Condition 5: Step count alignment ──
		$mismatches    = self::detect_mismatches( $todos, $graph['nodes'] );
		$result['mismatches'] = $mismatches;

		$blocking = array_filter( $mismatches, function( $m ) { return ( $m['severity'] ?? '' ) === 'blocking'; } );
		if ( ! empty( $blocking ) ) {
			$first = $blocking[0] ?? $blocking[ array_key_first( $blocking ) ];
			$result['status'] = 'BROKEN_STATE';
			$result['error']  = 'Blocking mismatch: ' . ( $first['message'] ?? 'unknown' );
			return $result;
		}

		// ── Condition 6: Completed todos must have node_output_json ──
		foreach ( $todos as $i => $todo ) {
			if ( $todo['status'] === 'COMPLETED' && empty( $todo['node_output_json'] ) ) {
				$result['mismatches'][] = [
					'type'     => 'missing_output',
					'severity' => 'warning',
					'message'  => 'Completed todo #' . $i . ' (' . ( $todo['tool_name'] ?? '?' ) . ') has no node_output_json — variable chain may have gaps.',
				];
				// This is a warning, not blocking — we can still resume but variables may be incomplete.
				error_log( self::LOG . ' ⚠️ Completed todo #' . $i . ' missing node_output_json' );
			}
		}

		// ── All 6 conditions met ──
		$result['ready']  = true;
		$result['status'] = 'READY';
		error_log( self::LOG . ' ✅ Contract validated: pipeline_id=' . $pipeline_id . ' task_id=' . $task_id . ' todos=' . count( $todos ) );

		return $result;
	}

	/**
	 * Detect mismatches between todos and canonical graph (§3.4.2-G).
	 *
	 * @param array $todos       Todo rows from DB.
	 * @param array $graph_nodes Nodes from bizcity_tasks.params.
	 * @return array Mismatch descriptors: [ { type, severity, message } ]
	 */
	private static function detect_mismatches( array $todos, array $graph_nodes ): array {
		$mismatches = [];

		// Count action nodes in graph (exclude trigger, planner, verifier)
		$skip_codes  = [ 'it_todos_planner', 'it_summary_verifier' ];
		$action_nodes = [];
		foreach ( $graph_nodes as $node ) {
			if ( ( $node['type'] ?? '' ) === 'action' ) {
				$code = $node['data']['code'] ?? '';
				if ( ! in_array( $code, $skip_codes, true ) ) {
					$action_nodes[] = $node;
				}
			}
		}

		$graph_action_count = count( $action_nodes );
		$todo_count         = count( $todos );

		// Step count check — allow ±1 tolerance for verify-content nodes
		$diff = abs( $graph_action_count - $todo_count );
		if ( $diff > 1 ) {
			// Check if pipeline has started (any COMPLETED/FAILED todo)
			$has_progress = false;
			foreach ( $todos as $t ) {
				if ( in_array( $t['status'], [ 'COMPLETED', 'FAILED', 'IN_PROGRESS' ], true ) ) {
					$has_progress = true;
					break;
				}
			}

			$mismatches[] = [
				'type'     => 'step_count',
				'severity' => $has_progress ? 'blocking' : 'warning',
				'message'  => sprintf(
					'Step count mismatch: graph has %d action nodes, todos has %d rows (diff=%d). %s',
					$graph_action_count,
					$todo_count,
					$diff,
					$has_progress ? 'Pipeline has progress — cannot rebuild, state is BROKEN.' : 'Pipeline not started — todos can be regenerated.'
				),
				'graph_count' => $graph_action_count,
				'todo_count'  => $todo_count,
			];
		}

		// node_id alignment check
		$graph_node_ids = array_map( function( $n ) { return $n['id']; }, $action_nodes );
		foreach ( $todos as $i => $todo ) {
			$tid = $todo['node_id'] ?? '';
			if ( $tid && ! in_array( $tid, $graph_node_ids, true ) ) {
				$mismatches[] = [
					'type'     => 'orphan_node_id',
					'severity' => 'warning',
					'message'  => sprintf(
						'Todo #%d references node_id=%s not found in graph action nodes.',
						$i, $tid
					),
				];
			}
		}

		return $mismatches;
	}

	/**
	 * Rebuild variables map from completed todo outputs (§3.4.2-E step 5).
	 *
	 * Merges node_output_json from each COMPLETED row in step_index order.
	 * Uses the same 'node#ID' key format as the BFS executor.
	 *
	 * @param array $todos Sorted by step_index ASC.
	 * @return array Keyed by 'node#<node_id>' → output array.
	 */
	private static function rebuild_variables( array $todos ): array {
		$variables = [];

		foreach ( $todos as $todo ) {
			if ( $todo['status'] !== 'COMPLETED' ) {
				continue;
			}

			$node_id = $todo['node_id'] ?? '';
			if ( ! $node_id ) {
				continue;
			}

			$output_raw = $todo['node_output_json'] ?? '';
			if ( empty( $output_raw ) ) {
				continue;
			}

			$output = is_string( $output_raw ) ? json_decode( $output_raw, true ) : $output_raw;
			if ( is_array( $output ) ) {
				$variables[ 'node#' . $node_id ] = $output;
			}
		}

		return $variables;
	}

	/**
	 * Load canonical workflow graph from bizcity_tasks table.
	 *
	 * @param int $task_id
	 * @return array|null Decoded params { nodes, edges, settings, meta } or null.
	 */
	private static function load_canonical_graph( int $task_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

		$params_raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT params FROM {$table} WHERE id = %d",
			$task_id
		) );

		if ( empty( $params_raw ) ) {
			return null;
		}

		$params = json_decode( $params_raw, true );
		if ( ! is_array( $params ) || empty( $params['nodes'] ) ) {
			return null;
		}

		return $params;
	}

	/**
	 * Quick check: can this pipeline be resumed?
	 *
	 * Lighter than validate_contract — just checks if transient exists
	 * and if not, whether todos + task_id are present.
	 *
	 * @param string $pipeline_id
	 * @param int    $task_id If known; 0 to look up from todos.
	 * @return array { can_resume: bool, has_transient: bool, reason: string }
	 */
	public static function can_resume( string $pipeline_id, int $task_id = 0 ): array {
		// Check if active transient already exists
		if ( $task_id > 0 ) {
			$active_exec_id = get_option( 'waic_active_execution_' . $task_id );
			if ( $active_exec_id && get_transient( $active_exec_id ) ) {
				return [
					'can_resume'    => true,
					'has_transient' => true,
					'reason'        => 'Active execution transient exists: ' . $active_exec_id,
				];
			}
		}

		// No active transient — check DB
		if ( ! class_exists( 'BizCity_Intent_Todos' ) ) {
			return [ 'can_resume' => false, 'has_transient' => false, 'reason' => 'Todos class not loaded.' ];
		}

		$todos = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
		if ( empty( $todos ) ) {
			return [ 'can_resume' => false, 'has_transient' => false, 'reason' => 'No todos for pipeline.' ];
		}

		// Check if any steps are still pending
		$has_pending = false;
		foreach ( $todos as $t ) {
			if ( in_array( $t['status'], [ 'PENDING', 'IN_PROGRESS', 'WAITING_USER' ], true ) ) {
				$has_pending = true;
				break;
			}
		}

		if ( ! $has_pending ) {
			return [ 'can_resume' => false, 'has_transient' => false, 'reason' => 'All todos are terminal.' ];
		}

		// Has pending todos + no transient → can rebuild
		return [
			'can_resume'    => true,
			'has_transient' => false,
			'reason'        => 'Transient expired but DB has pending todos — rebuild possible.',
		];
	}
}
