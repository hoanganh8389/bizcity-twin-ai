<?php
/**
 * Trigger: Facebook Messenger DM (Page inbox).
 *
 * BE-6.D — tách khỏi `trigger.fb_comment` (feed comment). Matcher route
 * dựa trên `event_subtype`: messaging → `fb_message`, feed → `fb_comment`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_FB_Message extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.fb_message'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Facebook · tin nhắn (Messenger)',
			'short'    => 'FB DM',
			'category' => 'trigger',
			'color'    => '#1d4ed8',
			'icon'     => 'message-circle',
			'defaults' => array(
				'label'       => 'FB · Messenger',
				'instance_id' => '',
				'filter'      => '',
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',          'type' => 'text' ),
				array( 'name' => 'instance_id', 'label' => 'Page ID (bỏ trống = mọi page)', 'type' => 'channel_instance_picker', 'platform' => 'FACEBOOK' ),
				array( 'name' => 'filter',      'label' => 'Substring filter (optional)',   'type' => 'text' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
