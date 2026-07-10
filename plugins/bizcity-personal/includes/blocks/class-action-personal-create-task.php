<?php
/**
 * Action Block: personal_create_task
 *
 * Tạo task (event_type=task) vào bizcity_crm_events scoped theo user_id.
 *
 * Dùng trong automation workflow:
 *   trigger.zalo_inbound (keyword "việc:") → action.personal_create_task → action.reply_zalo
 *
 * Input fields (từ workflow graph node data):
 *   - title       : string  Tiêu đề task (hỗ trợ {{token}})
 *   - description : string  Mô tả (optional)
 *   - due_at      : string  ISO / strtotime string (optional, default = '+1 day')
 *   - priority    : string  low|medium|high (default = medium)
 *   - user_id     : int     0 = current_user_id() (runtime fallback)
 *   - source      : string  Nguồn (zalo_bot / admin / cron...)
 *
 * Output (ctx key = node_id):
 *   - event_id    : int
 *   - title       : string (resolved)
 *   - status      : 'active'
 *
 * Architecture: PHASE-HOME-ARCH §3 — action block only, no direct channel hook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal\Blocks
 * @since      2026-06-24 (PHASE-HOME-ARCH v1.0)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Personal_Action_Create_Task extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.personal_create_task'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Personal — Tạo Task',
			'short'    => 'personal_task',
			'category' => 'personal',
			'color'    => '#4f46e5',
			'icon'     => 'CheckSquare',
			'plugin'   => 'bizcity-personal',
			'defaults' => array(
				'label'       => 'Tạo task cá nhân',
				'title'       => '{{trigger.text}}',
				'description' => '',
				'due_at'      => '+1 day',
				'priority'    => 'medium',
				'user_id'     => 0,
				'source'      => 'automation',
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'title',       'label' => 'Tiêu đề task', 'type' => 'text' ),
				array( 'name' => 'description', 'label' => 'Mô tả',        'type' => 'textarea' ),
				array( 'name' => 'due_at',      'label' => 'Hạn chót (ISO / strtotime / rỗng=+1 ngày)', 'type' => 'text' ),
				array( 'name' => 'priority',    'label' => 'Ưu tiên',      'type' => 'select', 'options' => array( 'low', 'medium', 'high' ) ),
				array( 'name' => 'user_id',     'label' => 'User ID (0 = current_user)',  'type' => 'number' ),
				array( 'name' => 'source',      'label' => 'Nguồn',        'type' => 'text' ),
			),
		);
	}

	/**
	 * @param array $ctx  Runner context
	 * @param array $data Node data (from graph)
	 * @return array|WP_Error
	 */
	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — create task via BizCity_Scheduler_Manager
		// bizcity_crm_events, scoped user_id. No direct DB. No channel hook.

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'personal_task_no_scheduler', 'BizCity_Scheduler_Manager không tải được.' );
		}

		$title       = sanitize_text_field( (string) $this->resolve( $data['title'] ?? '', $ctx ) );
		$description = sanitize_textarea_field( (string) $this->resolve( $data['description'] ?? '', $ctx ) );
		$due_at_raw  = (string) $this->resolve( $data['due_at'] ?? '+1 day', $ctx );
		$priority    = sanitize_key( (string) ( $data['priority'] ?? 'medium' ) );
		$source      = sanitize_key( (string) $this->resolve( $data['source'] ?? 'automation', $ctx ) );

		// Resolve user_id: explicit > trigger.user_id > wp current user
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = (int) ( $ctx['trigger']['user_id'] ?? 0 );
		}
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}

		if ( $title === '' ) {
			return new WP_Error( 'personal_task_empty_title', 'Tiêu đề task không được rỗng.' );
		}

		// Parse due_at
		$due_ts = strtotime( $due_at_raw );
		if ( ! $due_ts ) {
			$due_ts = strtotime( '+1 day' );
		}
		$due_at_iso = date( 'Y-m-d H:i:s', $due_ts );

		// Build inbound for R-SCH-REPLY (forwarded from trigger context)
		$inbound = isset( $ctx['trigger']['inbound'] ) ? $ctx['trigger']['inbound'] : array();

		// Create via Scheduler Manager
		$event_id = BizCity_Scheduler_Manager::instance()->create_event( array(
			'user_id'    => $user_id,
			'event_type' => 'task',
			'title'      => $title,
			'description'=> $description,
			'start_at'   => $due_at_iso,
			'status'     => 'active',
			'source'     => $source,
			'metadata'   => wp_json_encode( array(
				'priority' => $priority,
				'inbound'  => $inbound,
				'_block'   => 'personal_create_task',
			) ),
		) );

		if ( ! $event_id ) {
			return new WP_Error( 'personal_task_create_failed', 'Không tạo được task.' );
		}

		$this->debug( 'Created task event_id=' . $event_id . ' user=' . $user_id . ' title=' . $title );

		return array(
			'event_id' => $event_id,
			'title'    => $title,
			'status'   => 'active',
			'due_at'   => $due_at_iso,
			'user_id'  => $user_id,
		);
	}
}
