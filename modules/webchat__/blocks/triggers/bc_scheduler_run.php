<?php
/**
 * Bizcity Twin AI — Trigger Block: Scheduler Run
 * Block trigger: Chạy workflow theo lịch (cron) — lưu DB + Google sync + WP cron
 *
 * Khi workflow dùng trigger này:
 *  1. Lưu sự kiện vào bizcity_scheduler_events (source = 'workflow')
 *  2. Đồng bộ Google Calendar qua hook bizcity_scheduler_event_created
 *  3. Đăng ký WP single cron event để kích hoạt workflow đúng giờ
 *  4. Khi cron fire: controlRun() output giống bc_instant_run → downstream nodes chạy bình thường
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      4.10.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BizCity Chat Agent Trigger — Scheduler Run
 *
 * Subtype 1 = scheduled trigger: fires via WP cron at configured time.
 * On "Publish", saves event to bizcity_scheduler_events, registers WP cron,
 * and syncs to Google Calendar via existing hook system.
 *
 * Output variables are compatible with bc_instant_run so the same
 * action pipeline (it_call_tool, bc_send_adminchat) works seamlessly.
 *
 * @since 4.10.0
 */
class WaicTrigger_bc_scheduler_run extends WaicTrigger {
	protected $_code    = 'bc_scheduler_run';
	protected $_subtype = 1; // scheduled — fires via WP cron
	protected $_order   = 1;

	/** WP cron hook name prefix. Full hook = {prefix}_{task_id} */
	const CRON_HOOK = 'bizcity_scheduler_workflow_fire';

	public function __construct( $block = null ) {
		$this->_name = __( '📅 Scheduler Run — Lên lịch', 'bizcity-twin-ai' );
		$this->_desc = __( 'Lên lịch chạy workflow. Lưu vào DB + đồng bộ Google Calendar + WP cron tự kích hoạt.', 'bizcity-twin-ai' );
		$this->_sublabel = [ 'mode', 'date', 'time', 'frequency', 'units' ];
		$this->setBlock( $block );
	}

	/* ================================================================
	 *  Settings UI (shown in workflow builder trigger panel)
	 * ================================================================ */

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$now = function_exists( 'WaicUtils' ) && method_exists( 'WaicUtils', 'getFormatedDateTime' )
			? WaicUtils::getFormatedDateTime( WaicUtils::getTimestamp(), 'Y-m-d' )
			: current_time( 'Y-m-d' );

		$this->_settings = [
			'mode' => [
				'type'    => 'select',
				'label'   => __( 'Chế độ', 'bizcity-twin-ai' ),
				'options' => [
					'one'    => __( 'Chạy một lần', 'bizcity-twin-ai' ),
					'period' => __( 'Lặp định kỳ', 'bizcity-twin-ai' ),
				],
				'ndesc'   => [
					'one'    => __( 'Lịch 1 lần vào', 'bizcity-twin-ai' ),
					'period' => __( 'Mỗi', 'bizcity-twin-ai' ),
				],
				'default' => 'one',
			],
			'date' => [
				'type'    => 'date',
				'label'   => __( 'Ngày chạy', 'bizcity-twin-ai' ),
				'ldesc'   => " \n",
				'default' => $now,
				'show'    => [ 'mode' => [ 'one' ] ],
				'add'     => [ 'time' ],
			],
			'time' => [
				'type'    => 'time',
				'label'   => '',
				'ldesc'   => __( 'lúc', 'bizcity-twin-ai' ),
				'default' => '20:00',
				'show'    => [ 'mode' => [ 'one' ] ],
				'inner'   => true,
			],
			'frequency' => [
				'type'    => 'number',
				'label'   => __( 'Tần suất', 'bizcity-twin-ai' ),
				'default' => '1',
				'show'    => [ 'mode' => [ 'period' ] ],
				'add'     => [ 'units' ],
			],
			'units' => [
				'type'    => 'select',
				'label'   => '',
				'default' => 'd',
				'options' => [
					'd' => __( 'Ngày', 'bizcity-twin-ai' ),
					'h' => __( 'Giờ', 'bizcity-twin-ai' ),
					'm' => __( 'Phút', 'bizcity-twin-ai' ),
				],
				'ndesc' => [ 'd' => 'ngày', 'h' => 'giờ', 'm' => 'phút' ],
				'inner' => true,
			],
			'from_date' => [
				'type'    => 'date',
				'label'   => __( 'Bắt đầu từ', 'bizcity-twin-ai' ),
				'ldesc'   => " \n" . __( 'từ', 'bizcity-twin-ai' ),
				'default' => $now,
				'show'    => [ 'mode' => [ 'period' ] ],
				'add'     => [ 'from_time' ],
			],
			'from_time' => [
				'type'    => 'time',
				'label'   => '',
				'default' => '08:00',
				'show'    => [ 'mode' => [ 'period' ] ],
				'inner'   => true,
			],
			'default_text' => [
				'type'    => 'textarea',
				'label'   => __( 'Nội dung mặc định (text)', 'bizcity-twin-ai' ),
				'default' => '',
				'desc'    => __( 'Text sẽ truyền vào {{node#1.text}} khi cron fire. Nếu trống, dùng workflow title.', 'bizcity-twin-ai' ),
			],
		];
	}

	/* ================================================================
	 *  Output Variables
	 * ================================================================ */

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = array_merge(
			$this->getDTVariables(),
			[
				'session_id'    => __( 'Session ID (auto-generated)', 'bizcity-twin-ai' ),
				'user_id'       => __( 'WordPress User ID', 'bizcity-twin-ai' ),
				'display_name'  => __( 'Display name', 'bizcity-twin-ai' ),
				'text'          => __( 'Input text content', 'bizcity-twin-ai' ),
				'message_id'    => __( 'Message ID (auto-generated)', 'bizcity-twin-ai' ),
				'image_url'     => __( 'Image URL (if any)', 'bizcity-twin-ai' ),
				'platform'      => __( 'Platform (scheduler)', 'bizcity-twin-ai' ),
				'reply_to'      => __( 'Reply To — Session ID', 'bizcity-twin-ai' ),
				'scheduler_event_id' => __( 'Scheduler Event ID in DB', 'bizcity-twin-ai' ),
			]
		);
	}

	/* ================================================================
	 *  Schedule helpers — sy_schedule compatible
	 * ================================================================ */

	/**
	 * Get first scheduled start time (Y-m-d H:i:s).
	 */
	public function getSchStart() {
		if ( $this->getParam( 'mode' ) === 'one' ) {
			$start = $this->getParam( 'date' ) . ' ' . $this->getParam( 'time' );
		} else {
			$start = $this->getParam( 'from_date' ) . ' ' . $this->getParam( 'from_time' );
		}
		if ( strlen( $start ) === 16 ) {
			$start .= ':00';
		} else {
			$start = null;
		}
		return $start;
	}

	/**
	 * Get recurrence period in seconds. 0 = one-time.
	 */
	public function getPeriod() {
		if ( $this->getParam( 'mode' ) === 'one' ) {
			return 0;
		}
		$frequency = max( 1, (int) $this->getParam( 'frequency' ) );
		$units     = $this->getParam( 'units' );
		switch ( $units ) {
			case 'm': $k = 60; break;
			case 'h': $k = 3600; break;
			default:  $k = 86400; break;
		}
		return $frequency * $k;
	}

	/* ================================================================
	 *  Publish — Save to DB + Google sync + WP cron
	 * ================================================================ */

	/**
	 * Publish workflow schedule.
	 *
	 * Called when user clicks "Xuất bản" (Publish) in the workflow builder.
	 * Creates scheduler event, registers WP cron, Google sync fires via hook.
	 *
	 * @param int    $task_id   Workflow task ID.
	 * @param string $title     Workflow title (used as event title).
	 * @param int    $user_id   Owner user ID. Defaults to current user.
	 * @return int|WP_Error     Scheduler event ID on success.
	 */
	public function publish_schedule( int $task_id, string $title = '', int $user_id = 0 ): int {
		$start_at = $this->getSchStart();
		if ( ! $start_at ) {
			return new \WP_Error( 'invalid_schedule', __( 'Chưa cấu hình thời gian chạy.', 'bizcity-twin-ai' ) );
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $title ) {
			$title = sprintf( 'Workflow #%d', $task_id );
		}

		// Duration estimate: 1 hour default for one-time, period-based for recurring
		$period  = $this->getPeriod();
		$end_at  = $period > 0
			? date( 'Y-m-d H:i:s', strtotime( $start_at ) + min( $period, 3600 ) )
			: date( 'Y-m-d H:i:s', strtotime( $start_at ) + 3600 );

		// ── 1. Save to bizcity_scheduler_events ──
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$manager_file = BIZCITY_TWIN_AI_DIR . 'core/scheduler/includes/class-scheduler-manager.php';
			if ( file_exists( $manager_file ) ) {
				require_once $manager_file;
			}
		}

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new \WP_Error( 'no_scheduler', 'Scheduler Manager not available.' );
		}

		$manager  = BizCity_Scheduler_Manager::instance();
		$event_id = $manager->create_event( [
			'user_id'      => $user_id,
			'title'        => $title,
			'description'  => sprintf(
				'Auto-scheduled from workflow #%d. Trigger: bc_scheduler_run.',
				$task_id
			),
			'start_at'     => $start_at,
			'end_at'       => $end_at,
			'all_day'      => false,
			'reminder_min' => 5,
			'source'       => 'workflow',
			'ai_context'   => wp_json_encode( [
				'workflow_task_id' => $task_id,
				'trigger_code'    => 'bc_scheduler_run',
				'mode'            => $this->getParam( 'mode' ),
				'period'          => $period,
			] ),
		] );

		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		// ── 2. Register WP cron ──
		$hook      = self::CRON_HOOK;
		$timestamp = strtotime( get_gmt_from_date( $start_at ) );
		$args      = [ $task_id, $event_id, $user_id ];

		// Clear any previous cron for this task
		$existing = wp_next_scheduled( $hook, $args );
		if ( $existing ) {
			wp_unschedule_event( $existing, $hook, $args );
		}

		if ( $period > 0 ) {
			// Recurring — register a custom schedule
			$schedule_name = 'bizcity_wf_' . $task_id;
			add_filter( 'cron_schedules', function( $schedules ) use ( $schedule_name, $period ) {
				$schedules[ $schedule_name ] = [
					'interval' => $period,
					'display'  => sprintf( 'BizCity Workflow #%s every %ds', $schedule_name, $period ),
				];
				return $schedules;
			} );
			wp_schedule_event( $timestamp, $schedule_name, $hook, $args );
		} else {
			// One-time
			wp_schedule_single_event( $timestamp, $hook, $args );
		}

		// ── 3. Google Calendar sync happens automatically via ──
		// do_action('bizcity_scheduler_event_created') inside create_event()

		return $event_id;
	}

	/**
	 * Unpublish — remove cron + cancel event.
	 *
	 * @param int $task_id   Workflow task ID.
	 * @param int $event_id  Scheduler event ID (optional, if known).
	 */
	public function unpublish_schedule( int $task_id, int $event_id = 0 ): void {
		// Remove cron
		$hook = self::CRON_HOOK;
		// Try to find and unschedule — we don't know exact args, so clear all for this hook
		wp_clear_scheduled_hook( $hook, [ $task_id, $event_id, 0 ] );

		// Cancel event in DB
		if ( $event_id && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->update_event( $event_id, [
				'status' => 'cancelled',
			] );
		}
	}

	/* ================================================================
	 *  controlRun — called either by cron fire or by manual test
	 * ================================================================ */

	/**
	 * controlRun — produces output variables for downstream nodes.
	 *
	 * When cron fires: $args = [ task_id, event_id, user_id ]
	 * When manual test: $args = test_data from Execute panel
	 *
	 * @param array $args  Cron args or test data.
	 * @return array       Output variables map.
	 */
	public function controlRun( $args = [] ) {
		$data = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : ( is_array( $args ) ? $args : [] );

		// Text: test_data > settings default > workflow title
		$text = '';
		if ( ! empty( $data['text'] ) ) {
			$text = (string) $data['text'];
		} elseif ( ! empty( $data['message_text'] ) ) {
			$text = (string) $data['message_text'];
		}
		if ( $text === '' ) {
			$text = (string) $this->getParam( 'default_text' );
		}

		// User context — from cron args or current user
		$user_id = 0;
		if ( ! empty( $data['user_id'] ) ) {
			$user_id = (int) $data['user_id'];
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_data = $user_id ? get_userdata( $user_id ) : null;
		$display   = $user_data ? $user_data->display_name : 'System';

		// Session: use provided or generate
		$session_id = ! empty( $data['session_id'] )
			? (string) $data['session_id']
			: 'sched_' . get_current_blog_id() . '_' . $user_id . '_' . time();

		// Scheduler event ID (passed from cron fire)
		$event_id = ! empty( $data['event_id'] ) ? (int) $data['event_id'] : 0;
		if ( ! $event_id && ! empty( $data['scheduler_event_id'] ) ) {
			$event_id = (int) $data['scheduler_event_id'];
		}

		$this->_results = [
			'date'               => date( 'Y-m-d' ),
			'time'               => date( 'H:i:s' ),
			'session_id'         => $session_id,
			'user_id'            => $user_id,
			'display_name'       => $display,
			'text'               => $text,
			'message_id'         => 'sched_' . uniqid(),
			'image_url'          => '',
			'platform'           => 'scheduler',
			'reply_to'           => $session_id,
			'scheduler_event_id' => $event_id,
		];

		return $this->_results;
	}

	/* ================================================================
	 *  Static: Cron callback — fires the workflow
	 * ================================================================ */

	/**
	 * WP Cron callback: fire the scheduled workflow.
	 *
	 * Registered via: add_action( self::CRON_HOOK, [...], 10, 3 )
	 *
	 * @param int $task_id   Workflow task ID.
	 * @param int $event_id  Scheduler event ID.
	 * @param int $user_id   Owner user ID.
	 */
	public static function cron_fire( int $task_id, int $event_id, int $user_id ): void {
		// Mark event as running
		if ( $event_id && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->mark_reminder_sent( $event_id );
		}

		/**
		 * Fire: let the workflow execution engine handle it.
		 *
		 * Consumers:
		 *   - Workflow Execute API hooks into this to run the task.
		 *
		 * @param int $task_id   Workflow task/automation ID.
		 * @param int $event_id  Scheduler event ID.
		 * @param int $user_id   Owner user ID.
		 */
		do_action( 'bizcity_scheduler_workflow_execute', $task_id, $event_id, $user_id );
	}
}
