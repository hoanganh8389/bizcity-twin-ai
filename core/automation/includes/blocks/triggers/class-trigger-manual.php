<?php
/**
 * Trigger: Manual / fire-now (palette không hiển, runner mặc định cho POST /run).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Manual extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.manual'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Chạy thủ công',
			'short'    => 'Manual',
			'category' => 'trigger',
			'color'    => '#475569',
			'icon'     => 'play',
			'defaults' => array( 'label' => 'Chạy thủ công' ),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		// Manual trigger: trả payload từ runner (đã merge vào ctx['trigger']).
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
