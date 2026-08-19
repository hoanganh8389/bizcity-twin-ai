<?php
/**
 * TwinBrain V5 — Automation progress notice projector.
 *
 * Projects existing automation runner hooks to user-bound Zalo Bot notices.
 * It deliberately does not own terminal completion delivery; Scheduler's
 * Completion Notifier remains the canonical owner for that message.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-15
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Progress_Notice_Projector' ) ) {
	return;
}

final class BizCity_TwinBrain_Progress_Notice_Projector {

	/** @var array<string,bool> */
	private static $seen = array();

	public static function init(): void {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — project existing runner lifecycle hooks to the canonical Gateway Sender.
		static $bound = false;
		if ( $bound ) {
			return;
		}
		$bound = true;
		add_action( 'bizcity_automation_run_started', array( __CLASS__, 'on_run_started' ), 20, 2 );
		add_action( 'bizcity_automation_run_resumed', array( __CLASS__, 'on_run_resumed' ), 20, 3 );
		add_action( 'bizcity_automation_run_paused', array( __CLASS__, 'on_run_paused' ), 20, 2 );
		add_action( 'bizcity_automation_run_ended', array( __CLASS__, 'on_run_ended' ), 20, 3 );
		add_action( 'bizcity_automation_log_appended', array( __CLASS__, 'on_log_appended' ), 20, 2 );
	}

	public static function on_run_started( $run_id, $workflow = array() ): void {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — announce the start of a user-bound automation run.
		$run_id = (string) $run_id;
		$run = self::find_run( $run_id );
		$target = self::target_from_run( $run );
		if ( $target === '' ) {
			return;
		}
		$name = is_array( $workflow ) ? (string) ( $workflow['name'] ?? '' ) : '';
		if ( $name === '' && is_array( $run ) ) {
			$name = 'workflow #' . (int) ( $run['workflow_id'] ?? 0 );
		}
		self::send_once(
			$target,
			'run|' . $run_id,
			'⏳ Đã nhận yêu cầu. Đang bắt đầu ' . self::safe_label( $name, 'workflow' ) . '.',
			array( 'status' => 'started', 'run_id' => $run_id )
		);
	}

	public static function on_run_resumed( $run_id, $workflow = array(), $resume_node = '' ): void {
		// [2026-08-16 Johnny Chu] MPR-V5-NOTICE — project a resumed automation lifecycle without duplicating terminal completion.
		$run_id = (string) $run_id;
		$run = self::find_run( $run_id );
		$target = self::target_from_run( $run );
		if ( $target === '' ) {
			return;
		}
		$name = is_array( $workflow ) ? (string) ( $workflow['name'] ?? '' ) : '';
		$detail = trim( (string) $resume_node );
		$message = '▶️ Đang tiếp tục workflow' . ( $name !== '' ? ' ' . self::safe_label( $name, 'workflow' ) : '' );
		if ( $detail !== '' ) {
			$message .= ' từ bước ' . self::safe_label( $detail, 'tiếp theo' );
		}
		self::send_once( $target, 'run|' . $run_id . '|resumed|' . md5( $detail ), $message . '.', array(
			'status' => 'started',
			'run_id' => $run_id,
		) );
	}

	public static function on_run_paused( $run_id, $node_id = '' ): void {
		// [2026-08-16 Johnny Chu] MPR-V5-NOTICE — tell the user when execution is waiting before a node.
		$run_id = (string) $run_id;
		$run = self::find_run( $run_id );
		$target = self::target_from_run( $run );
		if ( $target === '' ) {
			return;
		}
		$node_label = self::safe_label( (string) $node_id, 'bước tiếp theo' );
		self::send_once( $target, 'run|' . $run_id . '|paused|' . md5( (string) $node_id ), '⏸️ Workflow đang tạm dừng trước bước ' . $node_label . '.', array(
			'status'  => 'waiting_user',
			'run_id'  => $run_id,
			'node_id' => (string) $node_id,
		) );
	}

	public static function on_run_ended( $run_id, $success = false, $context = array() ): void {
		// [2026-08-16 Johnny Chu] MPR-V5-NOTICE — project failed terminal runs only; successful completion stays with Scheduler Completion Notifier.
		if ( $success ) {
			return;
		}
		$run_id = (string) $run_id;
		$run = self::find_run( $run_id );
		$target = self::target_from_run( $run );
		if ( $target === '' ) {
			return;
		}
		$raw_error = is_array( $context ) ? (string) ( $context['error'] ?? '' ) : '';
		$error = self::normalize_error( $raw_error, 'workflow' );
		self::send_once( $target, 'run|' . $run_id . '|failed', '❌ Workflow không hoàn tất. ' . $error['message'] . ' ' . $error['hint'], array(
			'status' => 'failed',
			'run_id' => $run_id,
			'error'  => $error,
		) );
	}

	public static function on_hil_milestone( string $chat_id, array $state, string $milestone = 'progress' ): void {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-NOTICE — project persisted HIL milestones without storing chat_id in Event Stream.
		if ( strpos( strtolower( trim( $chat_id ) ), 'zalobot_' ) !== 0 ) {
			return;
		}
		$status = (string) ( $state['status'] ?? 'collecting' );
		$notice_status = in_array( $status, array( 'ready' ), true ) ? 'completed' : ( in_array( $status, array( 'expired', 'failed', 'cancelled' ), true ) ? 'failed' : 'started' );
		if ( class_exists( 'BizCity_TwinBrain_Progress_Notice_Policy' ) && ! BizCity_TwinBrain_Progress_Notice_Policy::should_send( $notice_status, array( 'hil' => true, 'status' => $notice_status ) ) ) {
			return;
		}
		$hil_id = self::safe_label( (string) ( $state['hil_id'] ?? 'hil' ), 'hil' );
		if ( $status === 'ready' ) {
			$message = '✅ Đã đủ thông tin và xác nhận. Em sẵn sàng thực hiện bước tiếp theo.';
		} elseif ( $status === 'expired' ) {
			$message = '⌛ Phiên thu thập thông tin đã hết thời gian. Hãy gửi lại yêu cầu khi sẵn sàng.';
		} elseif ( $status === 'failed' ) {
			$message = '❌ Phiên thu thập thông tin bị lỗi do vượt giới hạn. Hãy gửi lại yêu cầu.';
		} elseif ( $status === 'cancelled' ) {
			$message = '↩️ Đã hủy phiên thu thập thông tin.';
		} elseif ( $status === 'confirming' ) {
			$message = '🔎 Em đã thu đủ thông tin. Vui lòng kiểm tra và xác nhận trước khi thực hiện.';
		} else {
			$message = $milestone === 'opened'
				? '⏳ Đã nhận yêu cầu. Em sẽ hỏi lần lượt các thông tin cần thiết.'
				: '⏳ Đã nhận thông tin. Em đang tiếp tục kiểm tra các mục còn thiếu.';
		}
		self::send_once(
			$chat_id,
			'hil|' . $hil_id . '|' . $status . '|' . (int) ( $state['turn_count'] ?? 0 ),
			$message,
			array(
				'status'       => $notice_status,
				'notice_stage' => 'hil_milestone',
				'hil_id'       => $hil_id,
				'hil_status'   => $status,
			)
		);
	}

	public static function on_hil_step( string $chat_id, array $state, array $result, array $spec = array() ): void {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-NOTICE — project slot wait/invalid state without exposing slot values.
		if ( strpos( strtolower( trim( $chat_id ) ), 'zalobot_' ) !== 0 ) {
			return;
		}
		$action = sanitize_key( (string) ( $result['action'] ?? '' ) );
		$slot_id = sanitize_key( (string) ( $result['slot_id'] ?? $state['pending_slot_id'] ?? '' ) );
		$slot_label = $slot_id;
		$slot_type = 'text';
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( sanitize_key( (string) ( $slot['id'] ?? '' ) ) === $slot_id ) {
				$slot_label = self::safe_label( (string) ( $slot['label'] ?? $slot_id ), 'thông tin cần thiết' );
				$slot_type = sanitize_key( (string) ( $slot['type'] ?? 'text' ) );
				break;
			}
		}
		$required = 0;
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( ! empty( $slot['required'] ) ) {
				$required++;
			}
		}
		$filled = count( (array) ( $state['slot_values'] ?? array() ) );
		$hil_id = self::safe_label( (string) ( $state['hil_id'] ?? 'hil' ), 'hil' );
		$filled_slot_id = sanitize_key( (string) ( $result['slot_filled'] ?? '' ) );
		if ( $filled_slot_id !== '' ) {
			$filled_label = $filled_slot_id;
			foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
				if ( sanitize_key( (string) ( $slot['id'] ?? '' ) ) === $filled_slot_id ) {
					$filled_label = self::safe_label( (string) ( $slot['label'] ?? $filled_slot_id ), 'thông tin cần thiết' );
					break;
				}
			}
			self::send_once( $chat_id, 'hil-slot|' . $hil_id . '|' . (int) ( $state['turn_count'] ?? 0 ) . '|filled|' . $filled_slot_id, '✅ Đã ghi nhận ' . $filled_label . '. Tiến độ ' . $filled . '/' . max( $required, $filled ) . '.', array(
				'status'       => 'started',
				'notice_stage' => 'hil_slot_filled',
				'hil_id'       => $hil_id,
				'slot_id'      => $filled_slot_id,
				'filled'       => $filled,
				'required'     => $required,
			) );
		}
		if ( ! in_array( $action, array( 'ask', 'reask', 'reask_confirm', 'confirm' ), true ) ) {
			return;
		}
		$media_candidate_count = max( 0, (int) ( $result['media_candidate_count'] ?? 0 ) );
		if ( in_array( $slot_type, array( 'image', 'file' ), true ) && $media_candidate_count > 0 && in_array( $action, array( 'ask', 'reask' ), true ) ) {
			// [2026-08-19 Johnny Chu] PHASE-TWINBRAIN-V5.9 — show candidate-found milestone before waiting so users can choose by index or request another file.
			$candidate_message = $media_candidate_count === 1
				? '🖼️ Em đã tìm thấy 1 tệp đính kèm phù hợp. Bạn có thể trả lời "chọn 1" hoặc "chọn ảnh khác".'
				: '🖼️ Em đã tìm thấy ' . $media_candidate_count . ' tệp đính kèm phù hợp. Bạn trả lời "chọn số" hoặc "chọn ảnh khác".';
			self::send_once( $chat_id, 'hil-media|' . $hil_id . '|' . (int) ( $state['turn_count'] ?? 0 ) . '|found|' . $slot_id, $candidate_message, array(
				'status'       => 'started',
				'notice_stage' => 'hil_evidence_candidate_found',
				'hil_id'       => $hil_id,
				'slot_id'      => $slot_id,
				'count'        => $media_candidate_count,
			) );
		}
		if ( $action === 'reask' || $action === 'reask_confirm' ) {
			self::send_once( $chat_id, 'hil-slot|' . $hil_id . '|' . (int) ( $state['turn_count'] ?? 0 ) . '|invalid', '⚠️ Thông tin cho ' . $slot_label . ' chưa hợp lệ. Bạn kiểm tra và gửi lại giúp mình.', array(
				'status'       => 'failed',
				'notice_stage' => 'hil_slot_invalid',
				'hil_id'       => $hil_id,
				'slot_id'      => $slot_id,
			) );
			return;
		}
		$message = $action === 'confirm'
			? '🔎 Đã đủ dữ liệu. Vui lòng xác nhận thông tin trước khi thực hiện.'
			: sprintf( '⏳ Đang chờ %s. Tiến độ %d/%d thông tin.', $slot_label, $filled, max( $required, $filled ) );
		if ( in_array( $slot_type, array( 'image', 'file' ), true ) && $action !== 'confirm' ) {
			if ( $media_candidate_count > 0 ) {
				$message = '⏳ Đang chờ bạn chọn tệp cho ' . $slot_label . '. Trả lời "chọn số" hoặc "chọn ảnh khác".';
			} else {
				$message = '📎 Mình chưa thấy tệp phù hợp cho ' . $slot_label . '. Bạn gửi ảnh/tệp rồi trả lời lại để tiếp tục.';
			}
		}
		self::send_once( $chat_id, 'hil-slot|' . $hil_id . '|' . (int) ( $state['turn_count'] ?? 0 ) . '|waiting', $message, array(
			'status'       => 'waiting_user',
			'notice_stage' => $action === 'confirm' ? 'hil_confirmation_requested' : 'hil_waiting_user',
			'hil_id'       => $hil_id,
			'slot_id'      => $slot_id,
			'filled'       => $filled,
			'required'     => $required,
		) );
	}

	public static function on_log_appended( $run_id, $log_id ): void {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — translate one persisted node log into a user-safe progress status.
		$run_id = (string) $run_id;
		$run = self::find_run( $run_id );
		$target = self::target_from_run( $run );
		if ( $target === '' || ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			return;
		}
		$log = self::find_log( $run_id, (int) $log_id );
		if ( ! is_array( $log ) ) {
			return;
		}
		$status = (int) ( $log['status'] ?? -1 );
		$notify_progress = is_array( $log['input'] ?? null ) && ! empty( $log['input']['notify_progress'] );
		if ( in_array( $status, array( 0, 1, 3 ), true ) && ! $notify_progress ) {
			// [2026-08-16 Johnny Chu] CCG-7 — only opted-in nodes emit milestones; failures remain visible.
			return;
		}
		if ( $status === 0 ) {
			$verb = '⏳ Đang thực hiện bước';
		} elseif ( $status === 1 ) {
			$verb = '✅ Hoàn thành bước';
		} elseif ( $status === 3 ) {
			$verb = '↪️ Bỏ qua bước';
		} elseif ( $status === 2 ) {
			$verb = '❌ Lỗi tại bước';
		} else {
			return;
		}
		$step = max( 1, (int) ( $log['step'] ?? 0 ) );
		$label = self::safe_label( (string) ( $log['block_id'] ?? '' ), 'node' );
		$message = sprintf( '%s %d: %s.', $verb, $step, $label );
		$notice_status = $status === 0 ? 'started' : ( $status === 1 ? 'completed' : ( $status === 3 ? 'skipped' : 'failed' ) );
		$notice_context = array(
			'status' => $notice_status,
			'run_id' => $run_id,
			'node_id' => (string) ( $log['node_id'] ?? '' ),
		);
		if ( $status === 2 ) {
			$error = self::normalize_error( (string) ( $log['error'] ?? '' ), $label );
			$message .= ' ' . $error['message'] . ' ' . $error['hint'];
			$notice_context['error'] = $error;
		} elseif ( $status === 3 ) {
			$skip_reason = is_array( $log['input'] ?? null ) ? sanitize_key( (string) ( $log['input']['reason_code'] ?? '' ) ) : '';
			$skip_reason = $skip_reason !== '' ? $skip_reason : 'condition_false';
			$message .= ' Nhánh điều kiện không được chọn (' . $skip_reason . ').';
			$notice_context['reason_code'] = $skip_reason;
		}
		self::send_once( $target, 'log|' . $run_id . '|' . (int) $log_id . '|' . $status, $message, $notice_context );
	}

	private static function normalize_error( string $raw, string $label ): array {
		// [2026-08-15 Johnny Chu] R-ERROR-UX — map runner failure markers to a catalog payload without exposing raw exception text.
		$haystack = strtolower( $raw . ' ' . $label );
		if ( strpos( $haystack, 'unknown_block' ) !== false || strpos( $haystack, 'not register' ) !== false ) {
			return array(
				'code' => 'module_not_loaded',
				'message' => 'Khối xử lý chưa được nạp.',
				'hint' => 'Kiểm tra block này đã được đăng ký rồi chạy lại.',
				'help_code' => 'module_not_loaded',
			);
		}
		if ( strpos( $haystack, 'graph_cycle' ) !== false || strpos( $haystack, 'validation_failed' ) !== false ) {
			return array(
				'code' => 'automation_graph_invalid',
				'message' => 'Workflow có cấu trúc chưa hợp lệ.',
				'hint' => 'Mở workflow, kiểm tra nhánh và nối lại các bước rồi thử lại.',
				'help_code' => 'automation_graph_invalid',
			);
		}
		if ( strpos( $haystack, 'token_invalid' ) !== false || strpos( $haystack, 'token expired' ) !== false || strpos( $haystack, 'page token' ) !== false ) {
			$is_zalo = strpos( $haystack, 'zalo' ) !== false;
			return array(
				'code' => 'token_invalid',
				'message' => 'Token của kênh đã hết hạn hoặc không hợp lệ.',
				'hint' => 'Vào phần Kênh, cấp quyền lại rồi thử lại workflow.',
				'help_code' => $is_zalo ? 'zalo_bad_token' : 'fb_token_expired',
			);
		}
		if ( strpos( $haystack, 'permission' ) !== false || strpos( $haystack, 'auth' ) !== false ) {
			return array(
				'code' => 'permission_denied',
				'message' => 'Kết nối hoặc quyền của bước này không hợp lệ.',
				'hint' => 'Kiểm tra kết nối dịch vụ và cấp lại quyền nếu cần.',
				'help_code' => 'permission_denied',
			);
		}
		if ( strpos( $haystack, 'quota' ) !== false || strpos( $haystack, 'rate' ) !== false ) {
			return array(
				'code' => 'quota_exceeded',
				'message' => 'Dịch vụ đã chạm giới hạn sử dụng.',
				'hint' => 'Đợi một lúc rồi thử lại hoặc kiểm tra gói dịch vụ.',
				'help_code' => 'quota_exceeded',
			);
		}
		return array(
			'code' => 'automation_block_error',
			'message' => 'Bước này không thực hiện được.',
			'hint' => 'Kiểm tra cấu hình bước rồi thử lại.',
			'help_code' => 'automation_block_error',
		);
	}

	private static function find_run( string $run_id ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — read the canonical run record for inbound target resolution.
		if ( $run_id === '' || ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			return array();
		}
		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		return is_array( $run ) ? $run : array();
	}

	private static function find_log( string $run_id, int $log_id ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — locate the canonical node log emitted by the Runner hook.
		if ( method_exists( 'BizCity_Automation_Repo_Runs', 'log_by_id' ) ) {
			return BizCity_Automation_Repo_Runs::log_by_id( $run_id, $log_id );
		}
		foreach ( BizCity_Automation_Repo_Runs::logs( $run_id ) as $log ) {
			if ( (int) ( $log['id'] ?? 0 ) === $log_id ) {
				return is_array( $log ) ? $log : array();
			}
		}
		return array();
	}

	public static function resolve_progress_target( array $run ): string {
		// [2026-08-17 Johnny Chu] MPR-V5-DDV — expose the read-only Zone 2 target boundary to aggregate diagnostics without duplicating Scheduler precedence.
		return self::target_from_run( $run );
	}

	private static function target_from_run( array $run ): string {
		// [2026-08-16 Johnny Chu] R-SCH-TARGET — resolve through the same Scheduler precedence before applying the Zone 2 projector guard.
		$trigger = isset( $run['trigger_payload'] ) && is_array( $run['trigger_payload'] ) ? $run['trigger_payload'] : array();
		$meta = isset( $run['metadata'] ) && is_array( $run['metadata'] ) ? $run['metadata'] : array();
		if ( isset( $trigger['metadata'] ) && is_array( $trigger['metadata'] ) ) {
			$meta = array_merge( $meta, $trigger['metadata'] );
		}
		if ( isset( $trigger['inbound'] ) && is_array( $trigger['inbound'] ) && empty( $meta['inbound'] ) ) {
			$meta['inbound'] = $trigger['inbound'];
		}
		if ( isset( $trigger['notify'] ) && is_array( $trigger['notify'] ) && empty( $meta['notify'] ) ) {
			$meta['notify'] = $trigger['notify'];
		}
		$target = class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? BizCity_Scheduler_Notify_Target_Resolver::resolve( array( 'user_id' => (int) ( $run['user_id'] ?? $trigger['wp_user_id'] ?? 0 ) ), $meta )
			: null;
		$chat_id = is_array( $target ) ? trim( (string) ( $target['chat_id'] ?? '' ) ) : '';
		// [2026-08-16 Johnny Chu] CCG-7 — reject explicit Zone 1 discriminators before the legacy zalobot prefix fallback.
		$platform = strtolower( trim( (string) ( $target['platform'] ?? $trigger['platform'] ?? '' ) ) );
		$channel  = strtolower( trim( (string) ( $trigger['code'] ?? $trigger['channel'] ?? $platform ) ) );
		$zone     = strtolower( trim( (string) ( $trigger['zone'] ?? '' ) ) );
		if ( in_array( $zone, array( 'crm', 'customer', 'zone1', 'guest_channel' ), true ) ) {
			return '';
		}
		if ( $platform !== '' && ! in_array( $platform, array( 'zalo_bot', 'zalo' ), true ) ) {
			return '';
		}
		if ( $channel !== '' && ! in_array( $channel, array( 'zalo_bot', 'zalo' ), true ) ) {
			return '';
		}
		// [2026-08-15 Johnny Chu] R-ZONE — this first projector wave is Zone 2 only; never send technical progress to CRM guests.
		return strpos( $chat_id, 'zalobot_' ) === 0 ? $chat_id : '';
	}

	private static function safe_label( string $value, string $fallback ): string {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — keep node labels bounded and free of control characters.
		$value = sanitize_text_field( $value );
		$value = preg_replace( '/[\r\n\x00-\x1F]+/u', ' ', $value );
		return mb_substr( trim( $value !== '' ? $value : $fallback ), 0, 80, 'UTF-8' );
	}

	private static function send_once( string $chat_id, string $key, string $message, array $context = array() ): void {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — request-scope dedupe prevents duplicate RUNNING/terminal hook delivery in one runner pass.
		if ( isset( self::$seen[ $key ] ) || ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}
		if ( class_exists( 'BizCity_TwinBrain_Progress_Notice_Policy' )
			&& ! BizCity_TwinBrain_Progress_Notice_Policy::should_send( (string) ( $context['status'] ?? 'progress' ), $context ) ) {
			return;
		}
		if ( ! self::claim( $key, $context ) ) {
			return;
		}
		self::$seen[ $key ] = true;
		try {
			$trace_id = (string) ( $context['trace_id'] ?? '' );
			if ( $trace_id === '' && (string) ( $context['run_id'] ?? '' ) !== '' && class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
				$run = self::find_run( (string) $context['run_id'] );
				$trigger = is_array( $run['trigger_payload'] ?? null ) ? $run['trigger_payload'] : array();
				$trace_id = (string) ( $trigger['trace_id'] ?? ( $trigger['correlation']['trace_id'] ?? '' ) );
			}
			$extra = array(
				'notice_version' => 'twin_notice.v1',
				'notice_stage'   => (string) ( $context['notice_stage'] ?? 'automation_progress' ),
				'notice_key'     => $key,
				'source'         => 'twinbrain.progress_notice',
				'channel_role'   => 'ASSISTANT',
				'_trace_source'  => 'twinbrain.progress_notice',
				'_no_automation_reentry' => true,
			);
			if ( $trace_id !== '' ) {
				$extra['_trace_id'] = $trace_id;
				$extra['trace_id'] = $trace_id;
			}
			if ( is_array( $context['error'] ?? null ) ) {
				$extra['error_payload'] = array(
					'code'      => (string) ( $context['error']['code'] ?? 'automation_block_error' ),
					'message'   => (string) ( $context['error']['message'] ?? '' ),
					'hint'      => (string) ( $context['error']['hint'] ?? '' ),
					'help_code' => (string) ( $context['error']['help_code'] ?? 'automation_block_error' ),
				);
			}
			$result = BizCity_Gateway_Sender::instance()->send( $chat_id, $message, 'text', $extra );
			if ( ! is_array( $result ) || empty( $result['sent'] ) ) {
				unset( self::$seen[ $key ] );
				self::release_claim( $key );
			}
		} catch ( \Throwable $e ) {
			// Notice delivery must not change the automation result.
			unset( self::$seen[ $key ] );
			self::release_claim( $key );
			error_log( '[TwinBrain][progress-notice] delivery failed: ' . $e->getMessage() );
		}
	}

	private static function claim( string $key, array $context ): bool {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — claim a notice across requests using blog-scoped cache or transient TTL.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$cache_key = 'claim_' . $blog_id . '_' . md5( $key );
		$ttl = class_exists( 'BizCity_TwinBrain_Progress_Notice_Policy' )
			? (int) ( BizCity_TwinBrain_Progress_Notice_Policy::resolve( $context )['dedupe_window_seconds'] ?? 30 )
			: 30;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $cache_key, 1, 'bizcity_twin_notice', $ttl );
		}
		$transient_key = 'bz_tw_notice_' . md5( $blog_id . '|' . $key );
		if ( get_transient( $transient_key ) ) {
			return false;
		}
		return (bool) set_transient( $transient_key, 1, $ttl );
	}

	private static function release_claim( string $key ): void {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — allow a failed delivery to retry without waiting for the dedupe window.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$cache_key = 'claim_' . $blog_id . '_' . md5( $key );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_delete( $cache_key, 'bizcity_twin_notice' );
			return;
		}
		delete_transient( 'bz_tw_notice_' . md5( $blog_id . '|' . $key ) );
	}
}