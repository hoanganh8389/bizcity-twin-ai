<?php
/**
 * Canvas Adapter — Handoff layer giữa Intent Engine và Canvas Panel.
 *
 * Intent (Não) gọi dispatch() → Adapter tạo initial state → trả handoff signal.
 * Tool's native REST execute → Adapter::complete() khi xong.
 *
 * KHÔNG chứa execution logic. KHÔNG gọi LLM. KHÔNG generate content.
 * Chỉ tạo job record + studio_output + trả launch info.
 *
 * @package BizCity\TwinAI\Tools
 * @since   5.7.0
 * @see     PHASE-1.20-CANVAS-ADAPTER.md
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Canvas_Adapter {

	/**
	 * Check if a tool should be dispatched to Canvas vs inline execution.
	 *
	 * TRUE khi: studio_enabled=true AND content_tier >= 1 (creative tools).
	 * FALSE khi: atomic utility tools (scheduler, gmail, etc.).
	 *
	 * @param string $tool_id Tool identifier from registry.
	 * @return bool
	 */
	public static function should_handoff( string $tool_id ): bool {
		// Feature flag — allow disabling Canvas Adapter globally
		if ( ! (bool) get_option( 'bizcity_canvas_adapter_enabled', true ) ) {
			error_log( '[CanvasAdapter] should_handoff: DISABLED by feature flag | tool=' . $tool_id );
			return false;
		}

		if ( ! class_exists( 'BizCity_Tool_Registry' ) ) {
			error_log( '[CanvasAdapter] should_handoff: Tool_Registry not loaded | tool=' . $tool_id );
			return false;
		}

		$tool = BizCity_Tool_Registry::get( $tool_id );
		if ( ! $tool ) {
			error_log( '[CanvasAdapter] should_handoff: tool NOT in registry | tool=' . $tool_id );
			return false;
		}

		$studio_enabled = ! empty( $tool['studio_enabled'] );
		$content_tier   = (int) ( $tool['content_tier'] ?? 0 );
		$result         = $studio_enabled && $content_tier >= 1;

		error_log( '[CanvasAdapter] should_handoff: tool=' . $tool_id
			. ' | studio_enabled=' . ( $studio_enabled ? 'true' : 'false' )
			. ' | content_tier=' . $content_tier
			. ' | result=' . ( $result ? 'HANDOFF' : 'INLINE' ) );

		return $result;
	}

	/**
	 * Dispatch a creative tool to Canvas — tạo initial state, KHÔNG execute.
	 *
	 * @param string $tool_id Tool identifier.
	 * @param array  $params  Resolved params (entities/slots đã fill).
	 * @param array  $context {session_id, user_id, conv_id, message, goal, goal_label, character_id}
	 * @return array {canvas_handoff, artifact_id, workshop, launch_url, sse_endpoint, prefill_data, auto_execute, reply}
	 */
	public static function dispatch( string $tool_id, array $params, array $context ): array {
		$user_id    = (int) ( $context['user_id'] ?? get_current_user_id() );
		$session_id = $context['session_id'] ?? '';

		// ── 1. Tìm handler cho tool ──
		$handler = self::get_handler( $tool_id );

		if ( ! $handler || ! is_callable( $handler ) ) {
			return [
				'canvas_handoff' => false,
				'error'          => sprintf( 'Tool "%s" chưa có Canvas handler.', $tool_id ),
			];
		}

		// ── 2. Gọi handler → tạo initial state (file/job record) ──
		$job = call_user_func( $handler, $tool_id, $params, $context );

		if ( empty( $job ) || ! empty( $job['error'] ) ) {
			return [
				'canvas_handoff' => false,
				'error'          => $job['error'] ?? 'Canvas handler failed.',
			];
		}

		// ── 3. Ghi webchat_studio_outputs (status=pending) ──
		$artifact_id = 0;
		if ( class_exists( 'BizCity_Output_Store' ) ) {
			$artifact_id = (int) BizCity_Output_Store::save_artifact( [
				'tool_id'    => $tool_id,
				'caller'     => 'intent',
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'success'    => true,
				'verified'   => false,
				'data'       => [
					'canvas_job' => $job['job_data'] ?? [],
					'status'     => 'pending',
					'workshop'   => $job['workshop'] ?? '',
					'launch_url' => $job['launch_url'] ?? '',
				],
				'message'    => $job['title'] ?? 'Canvas output pending',
			], $job['tool_type'] ?? 'content' );
		}

		// ── 4. Trả handoff signal ──
		$reply = $job['reply'] ?? sprintf(
			'Đang mở %s — "%s"...',
			$job['workshop_label'] ?? 'Canvas',
			$job['title'] ?? $tool_id
		);

		error_log( '[Canvas-Adapter] dispatch: tool=' . $tool_id . ' artifact=' . $artifact_id . ' url=' . ( $job['launch_url'] ?? '' ) );

		return [
			'canvas_handoff' => true,
			'artifact_id'    => $artifact_id,
			'workshop'       => $job['workshop'] ?? '',
			'launch_url'     => $job['launch_url'] ?? '',
			'sse_endpoint'   => $job['sse_endpoint'] ?? '',
			'prefill_data'   => $job['prefill_data'] ?? $params,
			'auto_execute'   => $job['auto_execute'] ?? false,
			'reply'          => $reply,
		];
	}

	/**
	 * Mark a Canvas job as completed.
	 *
	 * Gọi bởi tool's native REST API SAU KHI execution xong.
	 *
	 * @param int   $artifact_id webchat_studio_outputs.id
	 * @param array $result      {title, content, content_format, file_url, status}
	 * @return bool
	 */
	public static function complete( int $artifact_id, array $result ): bool {
		if ( ! $artifact_id ) {
			return false;
		}

		if ( ! class_exists( 'BCN_Schema_Extend' ) ) {
			return false;
		}

		global $wpdb;
		$table = BCN_Schema_Extend::table_studio_outputs();

		$update_data = [
			'updated_at' => current_time( 'mysql' ),
		];

		// Only set fields that have values
		if ( ! empty( $result['title'] ) ) {
			$update_data['title'] = wp_strip_all_tags( $result['title'] );
		}
		if ( isset( $result['content'] ) ) {
			$update_data['content'] = $result['content'];
		}
		if ( ! empty( $result['content_format'] ) ) {
			$update_data['content_format'] = sanitize_key( $result['content_format'] );
		}
		if ( ! empty( $result['file_url'] ) ) {
			$update_data['file_url'] = esc_url_raw( $result['file_url'] );
		}

		// Mark as verified (execution complete)
		$update_data['verified'] = 1;

		$updated = $wpdb->update( $table, $update_data, [ 'id' => $artifact_id ] );

		if ( $updated === false ) {
			error_log( '[Canvas-Adapter] complete() failed: ' . $wpdb->last_error );
			return false;
		}

		error_log( '[Canvas-Adapter] complete: artifact=' . $artifact_id );

		/**
		 * Fires after a Canvas job completes.
		 *
		 * @param int   $artifact_id Studio output row ID.
		 * @param array $result      Completion data.
		 */
		do_action( 'bizcity_canvas_completed', $artifact_id, $result );

		return true;
	}

	/**
	 * Mark a Canvas job as failed.
	 *
	 * @param int    $artifact_id webchat_studio_outputs.id
	 * @param string $error       Error message.
	 * @return bool
	 */
	public static function fail( int $artifact_id, string $error ): bool {
		if ( ! $artifact_id || ! class_exists( 'BCN_Schema_Extend' ) ) {
			return false;
		}

		global $wpdb;
		$table = BCN_Schema_Extend::table_studio_outputs();

		$updated = $wpdb->update( $table, [
			'verified'   => 0,
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $artifact_id ] );

		if ( $updated === false ) {
			error_log( '[Canvas-Adapter] fail() DB error: ' . $wpdb->last_error );
			return false;
		}

		error_log( '[Canvas-Adapter] fail: artifact=' . $artifact_id . ' error=' . $error );

		do_action( 'bizcity_canvas_failed', $artifact_id, $error );

		return true;
	}

	/**
	 * Get dispatch handler for a specific tool.
	 *
	 * Plugins register handlers via 'bizcity_canvas_handlers' filter.
	 *
	 * @param string $tool_id
	 * @return callable|null
	 */
	public static function get_handler( string $tool_id ) {
		/**
		 * Mỗi creative plugin register handler qua filter.
		 *
		 * @param array $handlers Associative array: tool_id => callable
		 */
		$handlers = apply_filters( 'bizcity_canvas_handlers', [] );
		return isset( $handlers[ $tool_id ] ) ? $handlers[ $tool_id ] : null;
	}

	/**
	 * Find artifact_id from studio_outputs by job data.
	 *
	 * @param string $tool_id  Tool identifier.
	 * @param array  $job_data Data to match in input_snapshot.
	 * @return int Artifact ID or 0.
	 */
	public static function find_artifact( string $tool_id, array $job_data ): int {
		if ( ! class_exists( 'BCN_Schema_Extend' ) ) {
			return 0;
		}

		global $wpdb;
		$table = BCN_Schema_Extend::table_studio_outputs();

		// Search by tool_id + recent + unverified (pending)
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE tool_id = %s AND verified = 0
			 ORDER BY created_at DESC LIMIT 1",
			$tool_id
		) );

		return $row ? (int) $row->id : 0;
	}
}
