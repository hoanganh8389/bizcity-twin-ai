<?php
/**
 * Trigger: Cron schedule (parsed in BE-4 via scan handler).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Cron extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.cron'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Lịch định kỳ',
			'short'    => 'Cron',
			'category' => 'trigger',
			'color'    => '#0891b2',
			'icon'     => 'clock',
			'defaults' => array( 'label' => 'Cron · mỗi ngày 08:00', 'schedule' => '0 8 * * *' ),
			'fields'   => array(
				array( 'name' => 'label',    'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'schedule', 'label' => 'Cron expression', 'type' => 'text', 'hint' => 'vd: 0 8 * * *' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		return array( 'fired_at' => current_time( 'mysql' ) );
	}
}
