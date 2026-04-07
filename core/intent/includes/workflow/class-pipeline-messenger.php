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
 * BizCity Pipeline Messenger
 *
 * Gửi message từ pipeline background process vào chat session.
 * Sử dụng BizCity_WebChat_Database::log_message() để ghi message trực tiếp
 * vào DB conversation — user thấy ngay khi reload hoặc qua long-poll.
 *
 * Phase 1.1 v1.5 — Execute Messenger wrapper.
 *
 * @package BizCity_Intent
 * @since   3.9.1
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Pipeline_Messenger {

	/**
	 * Gửi message vào chat session.
	 *
	 * @param array  $execution_state ExecutionState từ execute-api.php.
	 * @param string $message         Nội dung tin nhắn (markdown/text).
	 * @param string $type            'info' | 'success' | 'error' | 'progress'.
	 * @param array  $meta            Extra metadata (tool_name, step_index, etc.).
	 * @return int|false Conversation ID or false on failure.
	 */
	public static function send( array $execution_state, string $message, string $type = 'info', array $meta = [] ) {
		$session_id = $execution_state['session_id'] ?? '';
		$user_id    = $execution_state['user_id'] ?? 0;
		$channel    = $execution_state['channel'] ?? 'adminchat';
		$to_chat    = $meta['_to_chat'] ?? true;

		if ( empty( $session_id ) ) {
			error_log( '[Pipeline Messenger] Cannot send — missing session_id' );
			return false;
		}

		// Always log to Execution Logger (transient-based, for Working Panel)
		if ( class_exists( 'BizCity_Execution_Logger' ) ) {
			BizCity_Execution_Logger::instance()->log( [
				'type'       => 'pipeline_step',
				'step'       => $meta['tool_name'] ?? $type,
				'label'      => wp_strip_all_tags( $message ),
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'meta'       => $meta,
			] );
		}

		// Skip chat insertion when _to_chat is false (micro-steps, progress)
		if ( ! $to_chat ) {
			return true;
		}

		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			error_log( '[Pipeline Messenger] WebChat Database not available' );
			return false;
		}

		$platform_map = [
			'adminchat' => 'ADMINCHAT',
			'webchat'   => 'WEBCHAT',
			'zalo'      => 'ZALO_BOT',
			'telegram'  => 'TELEGRAM',
			'facebook'  => 'FACEBOOK',
		];

		try {
			return BizCity_WebChat_Database::instance()->log_message( [
				'session_id'              => $session_id,
				'user_id'                 => $user_id,
				'message_id'              => 'pipe_' . uniqid( '', true ),
				'message_text'            => $message,
				'message_from'            => 'bot',
				'message_type'            => 'text',
				'plugin_slug'             => 'bizcity-pipeline',
				'tool_name'               => $meta['tool_name'] ?? 'pipeline_messenger',
				'intent_conversation_id'  => $execution_state['intent_conversation_id'] ?? '',
				'platform_type'           => $platform_map[ $channel ] ?? 'ADMINCHAT',
				'todo_id'                 => $meta['todo_id'] ?? 0,
				'meta'                    => array_merge( $meta, [
					'type'        => $type,
					'source'      => 'pipeline_messenger',
					'pipeline_id' => $execution_state['pipeline_id'] ?? '',
				] ),
			] );
		} catch ( \Throwable $e ) {
			error_log( '[Pipeline Messenger] send failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Gửi progress update (todo checkpoint).
	 *
	 * @param array  $execution_state ExecutionState.
	 * @param int    $completed       Number of completed steps.
	 * @param int    $total           Total number of steps.
	 * @param string $current_label   Label of last completed step.
	 * @return int|false
	 */
	public static function send_progress( array $execution_state, int $completed, int $total, string $current_label = '' ) {
		$bar = str_repeat( '█', $completed ) . str_repeat( '░', max( 0, $total - $completed ) );
		$msg = "📋 Tiến độ: {$completed}/{$total} {$bar}";
		if ( $current_label ) {
			$msg .= "\n✅ Hoàn tất: {$current_label}";
		}
		return self::send( $execution_state, $msg, 'progress', [
			'completed' => $completed,
			'total'     => $total,
			'_to_chat'  => false,
		] );
	}

	/**
	 * Gửi micro-step message (granular progress cho từng bước nhỏ).
	 *
	 * @param array  $execution_state ExecutionState.
	 * @param string $icon            Emoji icon (🔍, ⏳, ✅, etc.).
	 * @param string $label           Step label (e.g. "Tìm skill cho generate_blog_content").
	 * @param string $step_code       Step code (e.g. "resolve_skill").
	 * @param int    $duration_ms     Duration in ms (0 = in-progress).
	 * @return int|false
	 */
	public static function send_micro_step( array $execution_state, string $icon, string $label, string $step_code = '', int $duration_ms = 0 ) {
		$ms_html = $duration_ms > 0
			? '<span class="bizc-wp-entry-ms">' . esc_html( $duration_ms . 'ms' ) . '</span>'
			: '';

		$html = '<div class="bizc-wp-entry done">'
		      . '<span class="bizc-wp-entry-icon">' . $icon . '</span>'
		      . '<div class="bizc-wp-entry-main">'
		      . '<span class="bizc-wp-entry-label">' . esc_html( $label ) . '</span>'
		      . ( $step_code ? '<span class="bizc-wp-entry-step">' . esc_html( $step_code ) . '</span>' : '' )
		      . '</div>'
		      . $ms_html
		      . '</div>';

		return self::send( $execution_state, $html, 'progress', [
			'tool_name'   => $step_code,
			'duration_ms' => $duration_ms,
			'format'      => 'html',
			'_to_chat'    => false,
		] );
	}

	/**
	 * Gửi node result ngay sau execution thành công.
	 * Renders as bizc-wp-entry.result with link + timing.
	 *
	 * @param array  $execution_state ExecutionState.
	 * @param string $tool_name       Block/tool code.
	 * @param array  $result_data     Result data from block.
	 * @param int    $step            Current step index.
	 * @param int    $total           Total steps.
	 * @param int    $todo_id         Todo ID.
	 * @param int    $duration_ms     Execution duration in ms.
	 * @return int|false
	 */
	public static function send_node_result( array $execution_state, string $tool_name, array $result_data, int $step, int $total, int $todo_id = 0, int $duration_ms = 0 ) {
		$summary_parts = [];
		$has_url = false;
		if ( ! empty( $result_data['title'] ) )         $summary_parts[] = $result_data['title'];
		// Support both 'url'/'post_url' and 'resource_url' (from it_call_tool envelope)
		$url = $result_data['post_url'] ?? $result_data['url'] ?? $result_data['resource_url'] ?? '';
		if ( ! empty( $url ) ) {
			$summary_parts[] = '🔗 ' . esc_url( $url );
			$has_url = true;
		}
		if ( ! empty( $result_data['message'] ) && ! $has_url ) {
			$summary_parts[] = mb_substr( $result_data['message'], 0, 200 );
		}
		$post_id = $result_data['post_id'] ?? $result_data['resource_id'] ?? '';
		if ( ! empty( $post_id ) && is_numeric( $post_id ) ) {
			$summary_parts[] = 'post #' . intval( $post_id );
		}

		$summary = implode( ' — ', $summary_parts ) ?: 'Xong';

		// Build HTML result entry (distinct from progress entries)
		$ms_html = $duration_ms > 0
			? '<span class="bizc-wp-entry-ms">' . esc_html( $duration_ms . 'ms' ) . '</span>'
			: '';

		// Title: clickable link when URL available, plain text otherwise
		$title_text = ! empty( $result_data['title'] ) ? $result_data['title'] : $summary;
		if ( ! empty( $url ) ) {
			$label_html = '<a href="' . esc_url( $url ) . '" target="_blank" class="bizc-wp-entry-label bizc-wp-entry-link">' . esc_html( $title_text ) . '</a>';
		} else {
			$label_html = '<span class="bizc-wp-entry-label">' . esc_html( $summary ) . '</span>';
		}

		$html = '<div class="bizc-wp-entry done result">'
		      . '<span class="bizc-wp-entry-icon">✅</span>'
		      . '<div class="bizc-wp-entry-main">'
		      . $label_html
		      . '<span class="bizc-wp-entry-step">' . esc_html( $tool_name ) . '</span>'
		      . '</div>'
		      . $ms_html
		      . '</div>'
		      . '<div style="font-size:11px;color:#94a3b8;margin:2px 0 8px 26px;">📋 ' . $step . '/' . $total . '</div>';

		return self::send( $execution_state, $html, 'success', [
			'tool_name'   => $tool_name,
			'step_index'  => $step,
			'todo_id'     => $todo_id,
			'duration_ms' => $duration_ms,
			'format'      => 'html',
		] );
	}

	/**
	 * Gửi error message với retry/skip options.
	 *
	 * @param array  $execution_state ExecutionState.
	 * @param string $tool_name       Block/tool code that failed.
	 * @param string $error           Error description.
	 * @param int    $step            Current step index.
	 * @param int    $total           Total steps.
	 * @return int|false
	 */
	public static function send_error( array $execution_state, string $tool_name, string $error, int $step, int $total, int $todo_id = 0 ) {
		$safe_error = mb_substr( $error, 0, 200 );
		$msg = "❌ **{$tool_name}** gặp lỗi: {$safe_error}\n"
		     . "📋 Tiến độ: {$step}/{$total}\n"
		     . "🔄 Thử lại · ⏭️ Bỏ qua · ❌ Dừng pipeline";
		return self::send( $execution_state, $msg, 'error', [
			'tool_name'  => $tool_name,
			'step_index' => $step,
			'error'      => $safe_error,
			'todo_id'    => $todo_id,
		] );
	}

	/**
	 * Gửi summary khi pipeline hoàn tất.
	 *
	 * @param array  $execution_state ExecutionState.
	 * @param int    $completed       Number of completed steps.
	 * @param int    $total           Total steps.
	 * @param string $detail          Detailed summary text.
	 * @return int|false
	 */
	public static function send_summary( array $execution_state, int $completed, int $total, string $detail = '' ) {
		$msg = "🎉 **Hoàn tất toàn bộ kế hoạch!**\n"
		     . "📊 {$completed}/{$total} bước thành công";
		if ( $detail ) {
			$msg .= "\n" . $detail;
		}
		return self::send( $execution_state, $msg, 'success', [
			'completed' => $completed,
			'total'     => $total,
		] );
	}

	/**
	 * Gửi pipeline start message (khi it_todos_planner tạo plan).
	 *
	 * @param array $execution_state ExecutionState.
	 * @param array $steps           Array of step labels.
	 * @return int|false
	 */
	public static function send_plan_start( array $execution_state, array $steps ) {
		$lines = [ '📋 **Bắt đầu kế hoạch (' . count( $steps ) . ' bước):**' ];
		foreach ( $steps as $idx => $label ) {
			$lines[] = ( $idx + 1 ) . '. ⏳ ' . $label;
		}
		$lines[] = "\n_Đang thực hiện..._";
		return self::send( $execution_state, implode( "\n", $lines ), 'info', [
			'total' => count( $steps ),
		] );
	}
}
