<?php
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
}
