<?php
/**
 * TwinBrain — Scheduler Tool · `scheduler_set_reminder` (Wave SCH-NC W6).
 *
 * MemGPT-style function-call tool: LLM master xuất
 * `<tool name="scheduler_set_reminder">{...}</tool>` → dispatcher gọi
 * `execute()` → tạo event `reminder_personal` ở `status='draft'` (HIL pending),
 * gửi confirmation envelope về channel gốc qua Gateway Sender, fire
 * `bizcity_scheduler_hil_pending`. User reply OK/Hủy/Sửa được match bởi
 * `BizCity_Scheduler_HIL_Router` ở priority 5 (trước automation matcher).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Tools
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once dirname( __DIR__, 3 ) . '/twin-core/includes/interface-twin-tool.php';
}

if ( class_exists( 'BizCity_TwinBrain_Scheduler_Tool_Set_Reminder' ) ) {
	return;
}

final class BizCity_TwinBrain_Scheduler_Tool_Set_Reminder implements BizCity_Twin_Tool {

	const TOOL_NAME = 'scheduler_set_reminder';

	public function name(): string {
		return self::TOOL_NAME;
	}

	public function description(): string {
		return 'Tạo nhắc nhở cá nhân (reminder_personal) cho user. '
			. 'Tool sẽ KHÔNG kích hoạt nhắc ngay — bot gửi snippet xác nhận và '
			. 'chờ user reply OK/Hủy/Sửa trong 5 phút. Dùng KHI user nói "nhắc tôi …", '
			. '"đặt báo thức …", "mai 8h gọi cho A". KHÔNG dùng cho FB post / web post '
			. '/ workflow — những loại đó có UI builder riêng.';
	}

	public function parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'        => array(
					'type'        => 'string',
					'description' => 'Tiêu đề ngắn cho nhắc nhở (≤ 200 ký tự).',
				),
				'when'         => array(
					'type'        => 'string',
					'description' => 'Thời điểm bằng natural language hoặc ISO (vd "8h tối mai", "2026-06-04 20:00:00").',
				),
				'reminder_min' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 1440,
					'default'     => 0,
					'description' => 'Số phút nhắc trước thời điểm. Mặc định 0 (nhắc đúng giờ).',
				),
				'description'  => array(
					'type'        => 'string',
					'description' => 'Mô tả thêm (optional).',
				),
			),
			'required'   => array( 'title', 'when' ),
		);
	}

	public function execute( array $args, array $context ): array {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — tool execute → draft + envelope.
		$title = trim( (string) ( $args['title'] ?? '' ) );
		$when  = trim( (string) ( $args['when'] ?? '' ) );
		if ( $title === '' || $when === '' ) {
			return array( 'ok' => false, 'error' => 'missing_title_or_when', 'summary' => 'Cần title + when.', 'result' => null );
		}
		if ( mb_strlen( $title ) > 200 ) {
			$title = mb_substr( $title, 0, 200 );
		}

		// Resolve start_at (best-effort natural-language parse).
		$start_at = $this->parse_when( $when );
		if ( $start_at === '' ) {
			return array(
				'ok'      => false,
				'error'   => 'when_unparseable',
				'summary' => sprintf( 'Không nhận dạng được thời gian "%s".', $when ),
				'result'  => null,
			);
		}

		// Build inbound block từ TwinBrain ctx.
		$inbound = null;
		if ( class_exists( 'BizCity_Scheduler_Inbound_Provenance' ) ) {
			$inbound = BizCity_Scheduler_Inbound_Provenance::from_twinbrain_ctx( $context, 'reminder' );
			if ( ! BizCity_Scheduler_Inbound_Provenance::is_valid( $inbound ) ) {
				$inbound = null;
			}
		}
		if ( ! $inbound ) {
			return array(
				'ok'      => false,
				'error'   => 'no_inbound_context',
				'summary' => 'Tool cần platform/chat_id từ ctx để gửi confirm envelope.',
				'result'  => null,
			);
		}

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return array( 'ok' => false, 'error' => 'scheduler_unavailable', 'summary' => '', 'result' => null );
		}
		$mgr = BizCity_Scheduler_Manager::instance();

		$user_id      = (int) ( $context['user_id'] ?? get_current_user_id() );
		$reminder_min = max( 0, min( 1440, (int) ( $args['reminder_min'] ?? 0 ) ) );

		$metadata = array(
			'inbound' => $inbound,
			'hil'     => array(
				'state'      => 'pending',
				'created_at' => current_time( 'mysql' ),
				'source'     => 'twinbrain.tool.scheduler_set_reminder',
			),
		);

		$row = array(
			'user_id'      => $user_id,
			'title'        => $title,
			'description'  => (string) ( $args['description'] ?? '' ),
			'start_at'     => $start_at,
			'event_type'   => 'reminder_personal',
			'status'       => 'draft', // ← HIL gating.
			'source'       => 'ai_reminder',
			'reminder_min' => $reminder_min,
			'metadata'     => $metadata,
		);
		$event_id = $mgr->create_event( $row );
		if ( is_wp_error( $event_id ) ) {
			return array( 'ok' => false, 'error' => $event_id->get_error_code(), 'summary' => $event_id->get_error_message(), 'result' => null );
		}
		if ( ! is_int( $event_id ) || $event_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'create_failed', 'summary' => '', 'result' => null );
		}

		$created = $mgr->get_event( $event_id );
		$row_arr = is_object( $created ) ? (array) $created : ( is_array( $created ) ? $created : $row );

		// Send confirmation envelope.
		$send_result = array( 'sent' => false );
		if ( class_exists( 'BizCity_Scheduler_HIL_Router' ) ) {
			$send_result = BizCity_Scheduler_HIL_Router::send_envelope( $row_arr, $metadata );
		}

		do_action( 'bizcity_scheduler_hil_pending', $event_id, $row_arr );

		$summary = sprintf(
			'⏳ Đã tạo nhắc DRAFT "%s" lúc %s. Đợi sếp gõ OK / Hủy / sửa <giờ> trong 5 phút.',
			$title,
			$start_at
		);
		return array(
			'ok'      => true,
			'summary' => $summary,
			'result'  => array(
				'event_id'      => $event_id,
				'status'        => 'draft',
				'start_at'      => $start_at,
				'reminder_min'  => $reminder_min,
				'envelope_sent' => ! empty( $send_result['sent'] ),
				'platform'      => isset( $send_result['platform'] ) ? (string) $send_result['platform'] : '',
				'token'         => sprintf( '[evt:#%d]', $event_id ),
			),
		);
	}

	/**
	 * Parse natural-language datetime → 'Y-m-d H:i:s' (UTC).
	 * Reuse scheduler tools normalizer if available; fallback strtotime.
	 *
	 * @param string $when
	 * @return string  Empty string if unparseable.
	 */
	private function parse_when( string $when ): string {
		if ( function_exists( '_bizcity_scheduler_normalize_datetime' ) ) {
			$norm = _bizcity_scheduler_normalize_datetime( $when, '' );
			if ( ! empty( $norm ) ) {
				return $norm;
			}
		}
		$ts = strtotime( $when );
		if ( ! $ts || $ts < time() ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
