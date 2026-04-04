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
 * BizCity Intent — ToDos CRUD
 *
 * Manages pipeline step tracking records in bizcity_intent_todos.
 * Each row = one step in a pipeline plan. Updates via tool_name match.
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Todos {

	/**
	 * Bulk-create todos from a planner output.
	 *
	 * @param string $pipeline_id  Pipeline identifier.
	 * @param array  $steps        [ ['tool_name' => 'wp_create_post', 'label' => 'Tạo bài viết', 'node_id' => '3', 'node_code' => 'ai_write_article'], ... ]
	 * @param int    $user_id      Owner user.
	 * @param array  $pipeline_meta Optional: ['task_id' => int, 'pipeline_version' => int]
	 * @return int Number of rows inserted.
	 */
	public static function create_from_plan( $pipeline_id, array $steps, $user_id = 0, array $pipeline_meta = [] ) {
		$db    = BizCity_Intent_Database::instance();
		$table = $db->todos_table();
		global $wpdb;

		$task_id          = $pipeline_meta['task_id'] ?? null;
		$pipeline_version = $pipeline_meta['pipeline_version'] ?? 1;

		$count = 0;
		foreach ( $steps as $index => $step ) {
			$row_data = [
				'pipeline_id'      => $pipeline_id,
				'step_index'       => $index,
				'tool_name'        => $step['tool_name'] ?? '',
				'label'            => $step['label']     ?? '',
				'status'           => 'PENDING',
				'score'            => 0,
				'user_id'          => intval( $user_id ),
			];

			// v4.0.0 columns — graph mapping
			if ( $task_id ) {
				$row_data['task_id']          = intval( $task_id );
				$row_data['pipeline_version'] = intval( $pipeline_version );
			}
			if ( ! empty( $step['node_id'] ) ) {
				$row_data['node_id'] = sanitize_text_field( $step['node_id'] );
			}
			if ( ! empty( $step['node_code'] ) ) {
				$row_data['node_code'] = sanitize_text_field( $step['node_code'] );
			}

			$inserted = $wpdb->insert( $table, $row_data );
			if ( $inserted ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Update status + optional meta for a todo by pipeline_id + node_id/tool_name.
	 *
	 * Lookup priority: node_id (most precise) → step_index → tool_name (legacy).
	 * If multiple rows match (same tool used twice), updates the first PENDING one.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param string $tool_name   Block code.
	 * @param string $status      PENDING | WAITING_USER | IN_PROGRESS | COMPLETED | FAILED | CANCELLED
	 * @param array  $extra       Optional: score, output_summary, error_message, node_id, step_index,
	 *                            node_input_json, node_output_json.
	 * @return bool Whether update succeeded.
	 */
	public static function update_status( $pipeline_id, $tool_name, $status, array $extra = [] ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();
		$row   = null;

		// Priority 1: Match by node_id (most precise — maps exactly to workflow graph node)
		if ( ! empty( $extra['node_id'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE pipeline_id = %s AND node_id = %s AND status NOT IN ('COMPLETED','FAILED','CANCELLED')
				 ORDER BY step_index ASC LIMIT 1",
				$pipeline_id, $extra['node_id']
			) );
			if ( ! $row ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE pipeline_id = %s AND node_id = %s
					 ORDER BY step_index DESC LIMIT 1",
					$pipeline_id, $extra['node_id']
				) );
			}
		}

		// Priority 2: Match by step_index
		if ( ! $row && isset( $extra['step_index'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE pipeline_id = %s AND step_index = %d AND status NOT IN ('COMPLETED','FAILED','CANCELLED')
				 ORDER BY id ASC LIMIT 1",
				$pipeline_id, intval( $extra['step_index'] )
			) );
		}

		// Priority 3: Match by tool_name (legacy — least precise)
		if ( ! $row && $tool_name ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE pipeline_id = %s AND tool_name = %s AND status NOT IN ('COMPLETED','FAILED','CANCELLED')
				 ORDER BY step_index ASC LIMIT 1",
				$pipeline_id, $tool_name
			) );

			if ( ! $row ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE pipeline_id = %s AND tool_name = %s
					 ORDER BY step_index DESC LIMIT 1",
					$pipeline_id, $tool_name
				) );
			}
		}

		if ( ! $row ) {
			return false;
		}

		$update = [ 'status' => $status ];
		if ( isset( $extra['score'] ) )            $update['score']            = intval( $extra['score'] );
		if ( isset( $extra['output_summary'] ) )   $update['output_summary']   = sanitize_text_field( $extra['output_summary'] );
		if ( isset( $extra['error_message'] ) )    $update['error_message']    = sanitize_text_field( $extra['error_message'] );
		if ( isset( $extra['node_input_json'] ) ) {
			$update['node_input_json'] = is_string( $extra['node_input_json'] ) ? $extra['node_input_json'] : wp_json_encode( $extra['node_input_json'] );
		}
		if ( isset( $extra['node_output_json'] ) ) {
			$update['node_output_json'] = is_string( $extra['node_output_json'] ) ? $extra['node_output_json'] : wp_json_encode( $extra['node_output_json'] );
		}

		return (bool) $wpdb->update( $table, $update, [ 'id' => $row->id ] );
	}

	/**
	 * Get all todos for a pipeline.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return array Rows ordered by step_index.
	 */
	public static function get_pipeline_todos( $pipeline_id ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE pipeline_id = %s ORDER BY step_index ASC",
			$pipeline_id
		), ARRAY_A );
	}

	/**
	 * Get progress summary for a pipeline.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return array [ 'total' => N, 'completed' => N, 'failed' => N, 'pending' => N, 'avg_score' => float ]
	 */
	public static function get_progress( $pipeline_id ) {
		$todos = self::get_pipeline_todos( $pipeline_id );

		$summary = [
			'total'     => count( $todos ),
			'completed' => 0,
			'failed'    => 0,
			'pending'   => 0,
			'waiting'   => 0,
			'avg_score' => 0,
		];

		$score_sum = 0;
		$score_cnt = 0;

		foreach ( $todos as $todo ) {
			switch ( $todo['status'] ) {
				case 'COMPLETED':
					$summary['completed']++;
					break;
				case 'FAILED':
				case 'CANCELLED':
					$summary['failed']++;
					break;
				case 'WAITING_USER':
					$summary['waiting']++;
					break;
				default:
					$summary['pending']++;
					break;
			}
			if ( $todo['score'] > 0 ) {
				$score_sum += $todo['score'];
				$score_cnt++;
			}
		}

		$summary['avg_score'] = $score_cnt > 0 ? round( $score_sum / $score_cnt, 1 ) : 0;
		return $summary;
	}

	/**
	 * Format todos as a user-readable message (Vietnamese).
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return string Formatted markdown.
	 */
	public static function get_formatted_message( $pipeline_id ) {
		$todos    = self::get_pipeline_todos( $pipeline_id );
		$progress = self::get_progress( $pipeline_id );

		if ( empty( $todos ) ) {
			return '📋 Chưa có kế hoạch nào.';
		}

		$lines = [];
		$lines[] = sprintf(
			'📋 **Tiến trình** — %d/%d hoàn thành (điểm TB: %.0f)',
			$progress['completed'],
			$progress['total'],
			$progress['avg_score']
		);

		$icons = [
			'COMPLETED'    => '✅',
			'FAILED'       => '❌',
			'CANCELLED'    => '⏭️',
			'WAITING_USER' => '⏳',
			'IN_PROGRESS'  => '🔄',
			'PENDING'      => '⬜',
		];

		foreach ( $todos as $todo ) {
			$icon   = $icons[ $todo['status'] ] ?? '⬜';
			$label  = $todo['label'] ?: $todo['tool_name'];
			$suffix = '';
			if ( $todo['score'] > 0 ) {
				$suffix .= " ({$todo['score']}đ)";
			}
			if ( ! empty( $todo['output_summary'] ) ) {
				$suffix .= " — " . mb_substr( $todo['output_summary'], 0, 80 );
			}
			$lines[] = "{$icon} {$label}{$suffix}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Delete all todos for a pipeline.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return int Number of rows deleted.
	 */
	public static function delete_pipeline_todos( $pipeline_id ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();
		return (int) $wpdb->delete( $table, [ 'pipeline_id' => $pipeline_id ] );
	}

	/* ================================================================
	 *  Phase 1.1d: Resume + Cancel + Retry
	 * ================================================================ */

	/**
	 * Find the most recent active/waiting pipeline for a user.
	 *
	 * Searches for pipelines that have at least 1 step in ACTIVE/WAITING_USER/PENDING status
	 * AND at least 1 step already COMPLETED (i.e., pipeline started but didn't finish).
	 *
	 * @param int    $user_id  User ID.
	 * @param string $channel  Optional channel filter.
	 * @return array|null { pipeline_id, task_id, total, completed, pending, waiting, failed, last_tool } or null.
	 */
	public static function find_active_pipeline( $user_id, $channel = '' ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		// Find the most recent pipeline_id with incomplete steps
		$sql = $wpdb->prepare(
			"SELECT pipeline_id, MAX(task_id) AS task_id,
			        COUNT(*) AS total,
			        SUM( CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END ) AS completed,
			        SUM( CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END ) AS pending,
			        SUM( CASE WHEN status = 'WAITING_USER' THEN 1 ELSE 0 END ) AS waiting,
			        SUM( CASE WHEN status IN ('ACTIVE','IN_PROGRESS') THEN 1 ELSE 0 END ) AS active,
			        SUM( CASE WHEN status IN ('FAILED','CANCELLED','SKIPPED') THEN 1 ELSE 0 END ) AS failed,
			        MAX(updated_at) AS last_update
			 FROM {$table}
			 WHERE user_id = %d
			 GROUP BY pipeline_id
			 HAVING (pending > 0 OR waiting > 0 OR active > 0)
			 ORDER BY last_update DESC
			 LIMIT 1",
			$user_id
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		// Get the next pending/waiting/active step
		$next = $wpdb->get_row( $wpdb->prepare(
			"SELECT tool_name, label, status, step_index, node_id
			 FROM {$table}
			 WHERE pipeline_id = %s AND status IN ('PENDING','WAITING_USER','ACTIVE','IN_PROGRESS')
			 ORDER BY step_index ASC LIMIT 1",
			$row['pipeline_id']
		), ARRAY_A );

		$row['next_tool']       = $next['tool_name'] ?? '';
		$row['next_label']      = $next['label'] ?? '';
		$row['next_status']     = $next['status'] ?? '';
		$row['next_step_index'] = $next['step_index'] ?? 0;
		$row['next_node_id']    = $next['node_id'] ?? '';

		return $row;
	}

	/**
	 * Find the most recent FAILED step in a pipeline for retry.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return array|null { id, step_index, tool_name, label, node_id, error_message } or null.
	 */
	public static function find_failed_step( $pipeline_id ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, step_index, tool_name, label, node_id, node_code, error_message
			 FROM {$table}
			 WHERE pipeline_id = %s AND status = 'FAILED'
			 ORDER BY step_index ASC LIMIT 1",
			$pipeline_id
		), ARRAY_A );
	}

	/**
	 * Cancel a step and auto-skip all steps that depend on it.
	 *
	 * Dependency is tracked via `depends_on_steps` column (JSON array of step_indexes)
	 * OR via simple sequential order: all steps after the cancelled one that are still PENDING.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param int    $step_index  Step to cancel.
	 * @param bool   $skip_downstream If true, SKIP all downstream PENDING steps.
	 * @return array { cancelled => int, skipped => int }
	 */
	public static function cancel_step( $pipeline_id, $step_index, $skip_downstream = true ) {
		global $wpdb;
		$table  = BizCity_Intent_Database::instance()->todos_table();
		$result = [ 'cancelled' => 0, 'skipped' => 0 ];

		// Cancel the target step
		$updated = $wpdb->update(
			$table,
			[ 'status' => 'CANCELLED' ],
			[ 'pipeline_id' => $pipeline_id, 'step_index' => (int) $step_index ],
			[ '%s' ],
			[ '%s', '%d' ]
		);
		if ( $updated ) {
			$result['cancelled'] = 1;
		}

		if ( ! $skip_downstream ) {
			return $result;
		}

		// Find steps that depend on the cancelled step
		$all_todos = self::get_pipeline_todos( $pipeline_id );
		foreach ( $all_todos as $todo ) {
			if ( (int) $todo['step_index'] <= (int) $step_index ) {
				continue;
			}
			if ( $todo['status'] !== 'PENDING' ) {
				continue;
			}

			// Check explicit dependency via depends_on_steps JSON
			$depends = json_decode( $todo['depends_on_steps'] ?? '[]', true );
			$has_dep = is_array( $depends ) && in_array( (int) $step_index, $depends, true );

			// For sequential pipelines without explicit deps, skip only the immediate next?
			// Conservative: skip ALL downstream PENDING to prevent cascade errors.
			// (User can retry/resume to re-queue them.)
			if ( $has_dep || empty( $depends ) ) {
				$wpdb->update(
					$table,
					[ 'status' => 'SKIPPED', 'error_message' => 'Auto-skipped: step ' . $step_index . ' was cancelled' ],
					[ 'id' => $todo['id'] ]
				);
				$result['skipped']++;
			}
		}

		return $result;
	}

	/**
	 * Reset a FAILED step back to PENDING for retry.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param int    $step_index  Step to retry.
	 * @return bool Whether reset succeeded.
	 */
	public static function reset_step_for_retry( $pipeline_id, $step_index ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		$updated = (bool) $wpdb->update(
			$table,
			[
				'status'        => 'PENDING',
				'error_message' => '',
				'score'         => 0,
			],
			[
				'pipeline_id' => $pipeline_id,
				'step_index'  => (int) $step_index,
				'status'      => 'FAILED',
			]
		);

		if ( ! $updated ) {
			// Also allow retry of CANCELLED steps (user skipped then wants to retry)
			$updated = (bool) $wpdb->update(
				$table,
				[
					'status'        => 'PENDING',
					'error_message' => '',
					'score'         => 0,
				],
				[
					'pipeline_id' => $pipeline_id,
					'step_index'  => (int) $step_index,
					'status'      => 'CANCELLED',
				]
			);
		}

		return $updated;
	}

	/**
	 * Reset all SKIPPED steps after a cancelled step back to PENDING (for re-queue after retry).
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param int    $from_step   Reset steps with step_index >= this value.
	 * @return int Number of rows reset.
	 */
	public static function reset_skipped_from( $pipeline_id, $from_step ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->todos_table();

		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'PENDING', error_message = ''
			 WHERE pipeline_id = %s AND step_index >= %d AND status IN ('SKIPPED','CANCELLED')",
			$pipeline_id, (int) $from_step
		) );
	}
}
