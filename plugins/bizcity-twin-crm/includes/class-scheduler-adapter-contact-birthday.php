<?php
/**
 * Scheduler adapter — event_type `contact_birthday` (PHASE-0.60B §5, C4.2).
 *
 * Registered through BizCity_Scheduler_Adapter_Registry (fail-open when the
 * Scheduler is absent). No cron of its own: the Scheduler's reminder scan fires
 * `bizcity_scheduler_reminder_fire`, which BizCity_CRM_Contact_Enrichment handles.
 *
 * @package BizCity_Twin_CRM
 * @since PHASE-0.60B (2026-09-23)
 */

// [2026-09-23 04:15 PM Claude Fable 5.1] PHASE-0.60B C4.2 — one adapter, validation only; firing lives in the enrichment owner.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Scheduler_Adapter_Contact_Birthday' ) || ! class_exists( 'BizCity_Scheduler_Adapter_Base' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Contact_Birthday extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'contact_birthday';
	}

	public function label() {
		return 'Sinh nhật khách hàng';
	}

	public function metadata_schema() {
		return array(
			'contact_id'      => array( 'type' => 'int', 'required' => true, 'desc' => 'CRM contact id' ),
			'birthday_md'     => array( 'type' => 'string', 'required' => true, 'desc' => 'MM-DD' ),
			'notify_staff'    => array( 'type' => 'bool', 'required' => false, 'desc' => 'Nhắc nhân viên phụ trách (mặc định bật)' ),
			'notify_customer' => array( 'type' => 'bool', 'required' => false, 'desc' => 'Nhắn khách (tin chủ động, mặc định tắt, tính vào trần ngày)' ),
		);
	}

	public function on_fire( array $event ) {
		// Handled by BizCity_CRM_Contact_Enrichment::on_reminder_fire() on the same hook.
	}
}
