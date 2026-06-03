<?php
/**
 * Trigger: TwinBrain tool decided (Stage 3 suggestion).
 *
 * BE-7.A — fires when TwinBrain's tool router picks a skill (event_key
 * `tool_decided`). Workflow can filter by `skill_slug` in trigger_config.
 *
 * Trigger ctx shape:
 * - `trace_id`   : Twin trace id
 * - `skill_slug` : decided skill (e.g. `web.search`, `kg.create_passage`)
 * - `args`       : tool arguments suggested by router
 * - `confidence` : 0..1 if available
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-7.A (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_TwinBrain_Tool_Decided extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.twinbrain_tool_decided'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'TwinBrain · gợi ý tool',
			'short'    => 'TB tool',
			'category' => 'trigger',
			'color'    => '#c026d3',
			'icon'     => 'wand-2',
			'defaults' => array(
				'label'      => 'TwinBrain · gợi ý tool',
				'skill_slug' => '',
			),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'skill_slug', 'label' => 'Lọc theo skill (vd: web.search) — để trống = mọi tool', 'type' => 'text' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
