<?php
/**
 * BizCity Scheduler — Event Adapter Interface (R-SCH §4)
 *
 * Hợp đồng chung cho mọi `event_type` được tạo qua scheduler.
 * Adapter bao bọc 3 trách nhiệm:
 *   1) Mô tả schema metadata bắt buộc cho event_type.
 *   2) Validate payload trước INSERT (Manager gọi ở W3).
 *   3) Hook on_fire / on_completed cho cron + completion notifier.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( interface_exists( 'BizCity_Scheduler_Event_Adapter' ) ) {
	return;
}

interface BizCity_Scheduler_Event_Adapter {

	/**
	 * Mã định danh event_type adapter này phụ trách.
	 * VD: 'fb_post', 'reminder_zalo', 'reminder_personal', 'automation_workflow'.
	 *
	 * @return string
	 */
	public function event_type();

	/**
	 * Nhãn hiển thị (ngắn gọn, tiếng Việt) — dùng cho dashboard / drawer.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * JSON-schema-lite mô tả metadata bắt buộc.
	 * Format: [ 'field_name' => [ 'type' => 'string|int|bool|array', 'required' => bool, 'desc' => string ] ]
	 *
	 * @return array
	 */
	public function metadata_schema();

	/**
	 * Validate payload trước khi INSERT.
	 * Return true nếu hợp lệ, WP_Error nếu fail.
	 *
	 * @param array $payload Mảng đã sanitize bởi Manager (gồm title, start_at, metadata, ...).
	 * @return true|WP_Error
	 */
	public function validate( array $payload );

	/**
	 * Gọi khi cron fire reminder (do_action 'bizcity_scheduler_reminder_fire').
	 * Adapter có thể no-op nếu publisher khác đã subscribe trực tiếp.
	 *
	 * @param array $event Row từ DB (đã decode metadata).
	 * @return void
	 */
	public function on_fire( array $event );

	/**
	 * Gọi sau khi event chuyển status → 'done' (qua bizcity_scheduler_event_completed).
	 * Dùng cho post-processing per-type (logging, audit, follow-up).
	 *
	 * @param array $event Row từ DB.
	 * @return void
	 */
	public function on_completed( array $event );
}
