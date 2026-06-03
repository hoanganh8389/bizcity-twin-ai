<?php
/**
 * Trigger: Zalo inbound message.
 *
 * Runtime: hook `bizcity_channel_inbound` (BE-4) sẽ enqueue run, payload chứa
 * `{ channel: 'zalo', text, user_id, page_id, ... }`. Execute ở đây chỉ
 * forward payload từ ctx['trigger'] về cho downstream nodes.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Zalo extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.zalo_inbound'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Zalo · tin nhắn mới',
			'short'    => 'Zalo msg',
			'category' => 'trigger',
			'color'    => '#7c3aed',
			'icon'     => 'message-circle',
			'defaults' => array( 'label' => 'Zalo · tin nhắn mới', 'instance_id' => '', 'filter' => '' ),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'instance_id', 'label' => 'OA (bỏ trống = mọi OA)', 'type' => 'channel_instance_picker', 'platform' => 'ZALO_BOT' ),
				array( 'name' => 'filter',      'label' => 'Bộ lọc chứa từ', 'type' => 'text', 'hint' => 'để trống = nhận mọi message' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
