<?php
/**
 * Adapter: telegram_send (Channel Gateway Telegram outbound).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Telegram_Send' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Telegram_Send extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'telegram_send';
	}

	public function label() {
		return 'Gửi Telegram';
	}

	public function metadata_schema() {
		return [
			'tg_bot_id'         => [ 'type' => 'int',    'required' => false ],
			'tg_chat_id'        => [ 'type' => 'string', 'required' => true ],
			'tg_text'           => [ 'type' => 'string', 'required' => true ],
			'tg_parse_mode'     => [ 'type' => 'string', 'required' => false ],
			'tg_send_status'    => [ 'type' => 'string', 'required' => false ],
		];
	}
}
