<?php
/**
 * Trigger: Webhook public endpoint (BE-4 sẽ ship REST route).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Webhook extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.webhook'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Webhook',
			'short'    => 'Webhook',
			'category' => 'trigger',
			'color'    => '#0e7490',
			'icon'     => 'globe',
			'defaults' => array(
				'label'  => 'Webhook',
				'slug'   => '',
				'secret' => '',
			),
			'fields'   => array(
				array( 'name' => 'label',  'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'slug',   'label' => 'Slug URL',     'type' => 'text', 'hint' => 'POST /bizcity-automation/v1/webhook/{slug}' ),
				array( 'name' => 'secret', 'label' => 'Token bí mật', 'type' => 'text', 'hint' => 'gửi qua header X-Bizcity-Webhook-Token' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
