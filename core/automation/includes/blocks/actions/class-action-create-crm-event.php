<?php
/**
 * Action: Create CRM event (canonical bridge).
 *
 * Bắt buộc sử dụng để Scheduler thấy được kết quả workflow.
 * Delegate qua action `bizcity_crm_event_create` (chuẩn của CRM plugin).
 * Nếu CRM listener không có, fallback INSERT trực tiếp vào `bizcity_crm_events`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Create_CRM_Event extends BizCity_Automation_Block_Base {

	const EVENT_TYPES = array( 'meeting', 'reminder', 'task', 'reminder_zalo', 'lead_report', 'fb_post', 'web_post' );

	public function id(): string   { return 'action.create_crm_event'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Tạo CRM event / task',
			'short'    => 'crm_event',
			'category' => 'output',
			'color'    => '#7e22ce',
			'icon'     => 'calendar',
			'defaults' => array(
				'label'      => 'create_crm_event',
				'event_type' => 'task',
				'title'      => '',
				'start_at'   => '',
				'description'=> '',
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'event_type',  'label' => 'Loại event',   'type' => 'select', 'options' => self::EVENT_TYPES ),
				array( 'name' => 'title',       'label' => 'Tiêu đề',      'type' => 'text' ),
				array( 'name' => 'start_at',    'label' => 'Thời gian bắt đầu (ISO/empty=now)', 'type' => 'text', 'hint' => '2026-06-01 08:00:00' ),
				array( 'name' => 'description', 'label' => 'Mô tả',         'type' => 'textarea' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$event_type = (string) ( $data['event_type'] ?? 'task' );
		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			$event_type = 'task';
		}
		$title       = (string) $this->resolve( $data['title'] ?? '', $ctx );
		$start_at    = (string) $this->resolve( $data['start_at'] ?? '', $ctx );
		$description = (string) $this->resolve( $data['description'] ?? '', $ctx );

		$payload = array(
			'event_type'  => $event_type,
			'title'       => $title !== '' ? $title : '[automation] event',
			'description' => $description,
			'start_at'    => $start_at,
			'related_id'  => $ctx['_run_id'] ?? '',
			'workflow_id' => $ctx['_workflow_id'] ?? 0,
			'status'      => 'active',
			'source'      => 'workflow',
			// [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound{} qua helper.
			'metadata'    => $this->build_event_metadata( $ctx, array() ),
		);

		$event_id = BizCity_Automation_CRM_Bridge::create_event( $payload );

		return array( 'event_id' => $event_id, 'event_type' => $event_type );
	}
}
