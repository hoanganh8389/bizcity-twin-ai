<?php
/**
 * Adapter: reminder_zalo (Channel Gateway Zalo Reminder).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Reminder_Zalo' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Reminder_Zalo extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'reminder_zalo';
	}

	public function label() {
		return 'Nhắc Zalo';
	}

	public function metadata_schema() {
		return [
			'zalo_bot_id'           => [ 'type' => 'int',    'required' => true ],
			'zalo_user_id'          => [ 'type' => 'string', 'required' => true ],
			'zalo_text'             => [ 'type' => 'string', 'required' => true ],
			'zalo_reminder_status'  => [ 'type' => 'string', 'required' => false ],
		];
	}
}
