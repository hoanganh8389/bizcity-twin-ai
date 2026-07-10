<?php
/**
 * Trigger: Telegram bot inbound.
 *
 * BE-6.D — channel-gateway adapter Telegram chuẩn hoá payload sang
 * `platform=TELEGRAM` + `instance_id=<bot_token_hash>`. Matcher route
 * sang `trigger_type='telegram_inbound'`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Telegram extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.telegram_inbound'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Telegram · tin nhắn mới',
			'short'    => 'Telegram',
			'category' => 'trigger',
			'color'    => '#0ea5e9',
			'icon'     => 'send',
			'defaults' => array(
				'label'       => 'Telegram · tin nhắn',
				'instance_id' => '',
				'filter'      => '',
				'guru_id'     => 0,
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',                       'type' => 'text' ),
				array( 'name' => 'instance_id', 'label' => 'Bot (bỏ trống = mọi bot)',           'type' => 'channel_instance_picker', 'platform' => 'TELEGRAM' ),
				array( 'name' => 'filter',      'label' => 'Substring filter (optional)',         'type' => 'text' ),
				// [2026-06-02 Johnny Chu] GURU W1 — cross-cutting guru filter.
				array( 'name' => 'guru_id',     'label' => 'Guru ID (0 = mọi guru)', 'type' => 'number', 'hint' => 'chỉ chạy khi character_id binding = guru này' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
