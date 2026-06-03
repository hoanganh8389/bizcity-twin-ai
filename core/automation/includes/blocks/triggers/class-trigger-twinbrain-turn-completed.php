<?php
/**
 * Trigger: TwinBrain turn completed (synthesis_done / final_done / agent_loop_done).
 *
 * BE-7.A — subscribe canonical Twin Event Bus stream via
 * `BizCity_Automation_TwinBrain_Bridge::on_twin_event()`. R-EVT-1 compliant:
 * we DON'T create a new event channel; we tap the existing `bizcity_twin_event`
 * action and fan out into automation's matcher.
 *
 * Use cases:
 * - Auto-log every completed brain turn to CRM.
 * - Notify admin via Zalo/FB when AI finishes a long-running answer.
 * - Pipe synthesis output into a downstream workflow (translate, summarize,
 *   publish to channel, …).
 *
 * Trigger ctx shape (merged into run payload):
 * - `trace_id`  : Twin trace id (string)
 * - `event_key` : original bus key (synthesis_done | final_done | agent_loop_done)
 * - `answer`    : final answer text if available
 * - `citations` : array of citation rows (if final_done)
 * - `ms`        : duration in ms (if available)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-7.A (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_TwinBrain_Turn_Completed extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.twinbrain_turn_completed'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'TwinBrain · trả lời hoàn tất',
			'short'    => 'TB done',
			'category' => 'trigger',
			'color'    => '#7c3aed',
			'icon'     => 'check-circle-2',
			'defaults' => array(
				'label' => 'TwinBrain · trả lời hoàn tất',
			),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
