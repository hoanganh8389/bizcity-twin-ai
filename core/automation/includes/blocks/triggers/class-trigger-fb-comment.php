<?php
/**
 * Trigger: Facebook comment received.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_FB_Comment extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.fb_comment'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Facebook · comment Page',
			'short'    => 'FB comment',
			'category' => 'trigger',
			'color'    => '#1d4ed8',
			'icon'     => 'message-square',
			'defaults' => array( 'label' => 'FB · comment Page', 'instance_id' => '', 'filter' => '', 'guru_id' => 0 ),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'instance_id', 'label' => 'Page (bỏ trống = mọi page)', 'type' => 'channel_instance_picker', 'platform' => 'FACEBOOK' ),
				array( 'name' => 'filter',      'label' => 'Bộ lọc chứa từ (optional)', 'type' => 'text' ),
				// [2026-06-02 Johnny Chu] GURU W1 — cross-cutting guru filter.
				array( 'name' => 'guru_id',     'label' => 'Guru ID (0 = mọi guru)', 'type' => 'number', 'hint' => 'chỉ chạy khi character_id binding = guru này' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
