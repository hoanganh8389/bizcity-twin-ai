<?php
/**
 * Trigger: TwinBrain intent (action.mpr_think downstream).
 *
 * BE-6.E hook — khi TwinBrain runtime nhận diện intent
 * `create_spreadsheet` / `compose_post` ... sẽ fire
 * `do_action('bizcity_twinbrain_intent', $intent_id, $payload)` →
 * matcher route sang trigger_type=`twinbrain_intent`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_TwinBrain_Intent extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.twinbrain_intent'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'TwinBrain · intent (chat)',
			'short'    => 'TB intent',
			'category' => 'trigger',
			'color'    => '#a855f7',
			'icon'     => 'sparkles',
			'defaults' => array(
				'label'     => 'TwinBrain · intent',
				'intent_id' => '',
			),
			'fields'   => array(
				array( 'name' => 'label',     'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'intent_id', 'label' => 'Intent ID (vd: create_spreadsheet, compose_post)', 'type' => 'text' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
