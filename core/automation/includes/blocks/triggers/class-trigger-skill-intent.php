<?php
/**
 * Trigger: Skill Intent — workflow lắng nghe skill A/B/C invocation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 *
 * Wave B BRIDGE W2 — cross-tier subscribe block.
 *
 * Fires khi:
 *   - Intent engine resolve một skill archetype C (`bizcity_skill_trigger_pipeline`).
 *   - `action.invoke_skill` block trong workflow khác kích hoạt một skill
 *     (`bizcity_skill_invoked` — emit từ subscriber bridge / future).
 *
 * Trigger ctx shape:
 *   - `skill_slug`  : slug của skill được kích hoạt
 *   - `archetype`   : 'A' | 'B' | 'C' | ''
 *   - `args`        : payload nguyên gốc từ matcher / invoke_skill caller
 *   - `source`      : 'pipeline' | 'invoke_skill'
 *
 * @since 2026-06-03
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Skill_Intent extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'trigger.skill_intent'; }
	public function kind(): string { return 'trigger'; }

	public function meta(): array {
		return array(
			'label'    => 'Skill · được kích hoạt',
			'short'    => 'Skill intent',
			'category' => 'trigger',
			'color'    => '#0ea5e9',
			'icon'     => 'sparkles',
			'defaults' => array(
				'label'      => 'skill_intent',
				'skill_slug' => '',
				'archetype'  => 'any',
			),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'skill_slug', 'label' => 'Lọc theo skill_slug (vd: sales_post — để trống = mọi skill)', 'type' => 'text' ),
				array( 'name' => 'archetype',  'label' => 'Archetype', 'type' => 'select', 'options' => array( 'any', 'A', 'B', 'C' ) ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — pass-through trigger payload.
		return isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
	}
}
