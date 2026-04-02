<?php
/**
 * BizCity Scheduler Module — Calendar + Reminders + AI Planning
 *
 * Independent module: core/scheduler/
 * DB-based event storage with Google Calendar sync, reminder cron, and React admin SPA.
 *
 * Extension points (hooks):
 *   - bizcity_scheduler_event_created   → Intent/Automation can react to new events
 *   - bizcity_scheduler_event_updated   → Sync / re-plan triggers
 *   - bizcity_scheduler_event_deleted   → Cleanup cross-module references
 *   - bizcity_scheduler_reminder_fire   → Channel Gateway / Webchat push notification
 *   - bizcity_scheduler_plan_generated  → Twin Core snapshot / dashboard awareness
 *   - bizcity_scheduler_google_synced   → Market / analytics tracking
 *   - bizcity_scheduler_context         → Inject events into LLM context (Intent module)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Constants ────────────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_SCHEDULER_DIR' ) ) {
	define( 'BIZCITY_SCHEDULER_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_SCHEDULER_VERSION' ) ) {
	define( 'BIZCITY_SCHEDULER_VERSION', '1.0.0' );
}

/* ── Includes ─────────────────────────────────────────────────────── */
require_once BIZCITY_SCHEDULER_DIR . 'includes/class-scheduler-manager.php';
require_once BIZCITY_SCHEDULER_DIR . 'includes/class-scheduler-rest-api.php';
require_once BIZCITY_SCHEDULER_DIR . 'includes/class-scheduler-google.php';
require_once BIZCITY_SCHEDULER_DIR . 'includes/class-scheduler-cron.php';
require_once BIZCITY_SCHEDULER_DIR . 'includes/class-scheduler-tools.php';

if ( is_admin() ) {
	require_once BIZCITY_SCHEDULER_DIR . 'includes/class-admin-page.php';
}

/* ── Initialize ───────────────────────────────────────────────────── */
BizCity_Scheduler_Manager::instance();
BizCity_Scheduler_REST_API::instance();
BizCity_Scheduler_Google::instance();
BizCity_Scheduler_Cron::instance();

if ( is_admin() ) {
	BizCity_Scheduler_Admin_Page::instance();
}

/* ══════════════════════════════════════════════════════════════
 *  PUBLIC PAGE — /scheduler/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
	add_rewrite_rule( '^scheduler/?$', 'index.php?bizcity_agent_page=scheduler', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) {
		$vars[] = 'bizcity_agent_page';
	}
	return $vars;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'bizcity_agent_page' ) === 'scheduler' ) {
		include BIZCITY_SCHEDULER_DIR . 'views/page-scheduler.php';
		exit;
	}
} );

/**
 * Integration hook: provide scheduler context to LLM prompt.
 *
 * Other modules call:
 *   $context = apply_filters( 'bizcity_scheduler_context', '', $user_id );
 *
 * Returns compact text: "Lịch hôm nay: 09:00 Họp team, 14:00 Call khách..."
 */
add_filter( 'bizcity_scheduler_context', function ( string $context, int $user_id ): string {
	return BizCity_Scheduler_Manager::instance()->build_today_context( $user_id );
}, 10, 2 );

add_action( 'bizcity_intent_register_providers', function ( $registry ) {
	bizcity_intent_register_plugin( $registry, [
		'id'   => 'scheduler',
		'name' => 'BizCity Scheduler - Atomic Calendar Tools',
		'patterns' => [
			'/lịch hôm nay|xem agenda hôm nay|agenda hôm nay|hôm nay có lịch gì|hom nay co lich gi/ui' => [
				'goal'        => 'scheduler_get_today_agenda',
				'label'       => 'Xem agenda hom nay',
				'description' => 'Tom tat nhanh lich hom nay cua nguoi dung.',
				'extract'     => [],
			],
			'/xem lịch|xem lich|đọc lịch|doc lich|lịch tuần|lich tuan|lịch ngày mai|calendar|sự kiện|su kien/ui' => [
				'goal'        => 'scheduler_list_events',
				'label'       => 'Liet ke su kien lich',
				'description' => 'Liet ke su kien theo khoang thoi gian va trang thai.',
				'extract'     => [ 'date_from', 'date_to', 'status', 'max_results' ],
			],
			'/tạo lịch|tao lich|tạo sự kiện|tao su kien|thêm lịch|them lich|hẹn lịch|hen lich|đặt lịch|dat lich|book meeting|create event|add event/ui' => [
				'goal'        => 'scheduler_create_event',
				'label'       => 'Tao su kien lich',
				'description' => 'Tao su kien lich noi bo va dong bo Google neu da ket noi.',
				'extract'     => [ 'title', 'start_at', 'end_at', 'description', 'all_day', 'reminder_min' ],
			],
			'/dời lịch|doi lich|đổi lịch|cập nhật lịch|cap nhat lich|reschedule|update event|chuyển lịch|chuyen lich/ui' => [
				'goal'        => 'scheduler_update_event',
				'label'       => 'Cap nhat su kien lich',
				'description' => 'Sua thoi gian, noi dung, reminder hoac trang thai cua su kien.',
				'extract'     => [ 'event_ref', 'title', 'start_at', 'end_at', 'description', 'all_day', 'reminder_min', 'status' ],
			],
			'/hủy lịch|huy lich|hủy sự kiện|huy su kien|cancel event|cancel meeting/ui' => [
				'goal'        => 'scheduler_cancel_event',
				'label'       => 'Huy su kien lich',
				'description' => 'Danh dau mot su kien la cancelled de dung reminder va follow-up.',
				'extract'     => [ 'event_ref' ],
			],
			'/hoàn thành lịch|hoan thanh lich|đánh dấu xong|danh dau xong|mark done|xong cuộc hẹn|xong cuoc hen/ui' => [
				'goal'        => 'scheduler_mark_done',
				'label'       => 'Danh dau su kien da xong',
				'description' => 'Danh dau su kien da hoan thanh.',
				'extract'     => [ 'event_ref' ],
			],
			'/xóa lịch|xoa lich|xóa sự kiện|xoa su kien|delete event|remove event/ui' => [
				'goal'        => 'scheduler_delete_event',
				'label'       => 'Xoa su kien lich',
				'description' => 'Xoa han mot su kien khoi local scheduler va Google neu co.',
				'extract'     => [ 'event_ref' ],
			],
			'/khoảng trống|khoang trong|free slot|slot trống|slot trong|rảnh lúc nào|ranh luc nao/ui' => [
				'goal'        => 'scheduler_find_free_slots',
				'label'       => 'Tim khung gio trong',
				'description' => 'Tim cac khoang thoi gian con trong de chen lich moi.',
				'extract'     => [ 'date', 'duration_min', 'day_start', 'day_end', 'max_results' ],
			],
			'/đồng bộ lịch google|dong bo lich google|sync google calendar|đồng bộ google calendar/ui' => [
				'goal'        => 'scheduler_sync_google',
				'label'       => 'Dong bo Google Calendar',
				'description' => 'Keo su kien tu Google Calendar ve local scheduler.',
				'extract'     => [],
			],
		],
		'plans' => [
			'scheduler_get_today_agenda' => [
				'required_slots' => [],
				'optional_slots' => [],
				'tool'           => 'scheduler_get_today_agenda',
				'ai_compose'     => false,
				'slot_order'     => [],
			],
			'scheduler_list_events' => [
				'required_slots' => [],
				'optional_slots' => [
					'date_from'   => [ 'type' => 'text', 'default' => '' ],
					'date_to'     => [ 'type' => 'text', 'default' => '' ],
					'status'      => [ 'type' => 'choice', 'default' => 'all' ],
					'max_results' => [ 'type' => 'number', 'default' => 20 ],
				],
				'tool'       => 'scheduler_list_events',
				'ai_compose' => false,
				'slot_order' => [ 'date_from', 'date_to', 'status', 'max_results' ],
			],
			'scheduler_create_event' => [
				'required_slots' => [
					'title'    => [ 'type' => 'text', 'prompt' => 'Ten su kien la gi?' ],
					'start_at' => [ 'type' => 'text', 'prompt' => 'Bat dau luc nao? Vi du 2026-04-04 09:00' ],
				],
				'optional_slots' => [
					'end_at'       => [ 'type' => 'text', 'default' => '' ],
					'description'  => [ 'type' => 'text', 'default' => '' ],
					'all_day'      => [ 'type' => 'choice', 'default' => '0' ],
					'reminder_min' => [ 'type' => 'number', 'default' => 15 ],
				],
				'tool'       => 'scheduler_create_event',
				'ai_compose' => false,
				'slot_order' => [ 'title', 'start_at', 'end_at', 'all_day', 'reminder_min', 'description' ],
			],
			'scheduler_update_event' => [
				'required_slots' => [
					'event_ref' => [ 'type' => 'text', 'prompt' => 'Ban muon sua su kien nao? Nhap ID hoac ten su kien.' ],
				],
				'optional_slots' => [
					'title'        => [ 'type' => 'text', 'default' => '' ],
					'start_at'     => [ 'type' => 'text', 'default' => '' ],
					'end_at'       => [ 'type' => 'text', 'default' => '' ],
					'description'  => [ 'type' => 'text', 'default' => '' ],
					'all_day'      => [ 'type' => 'choice', 'default' => '' ],
					'reminder_min' => [ 'type' => 'number', 'default' => '' ],
					'status'       => [ 'type' => 'choice', 'default' => '' ],
				],
				'tool'       => 'scheduler_update_event',
				'ai_compose' => false,
				'slot_order' => [ 'event_ref', 'start_at', 'end_at', 'title', 'reminder_min', 'status', 'description' ],
			],
			'scheduler_cancel_event' => [
				'required_slots' => [
					'event_ref' => [ 'type' => 'text', 'prompt' => 'Su kien nao can huy?' ],
				],
				'optional_slots' => [],
				'tool'       => 'scheduler_cancel_event',
				'ai_compose' => false,
				'slot_order' => [ 'event_ref' ],
			],
			'scheduler_mark_done' => [
				'required_slots' => [
					'event_ref' => [ 'type' => 'text', 'prompt' => 'Su kien nao da hoan thanh?' ],
				],
				'optional_slots' => [],
				'tool'       => 'scheduler_mark_done',
				'ai_compose' => false,
				'slot_order' => [ 'event_ref' ],
			],
			'scheduler_delete_event' => [
				'required_slots' => [
					'event_ref' => [ 'type' => 'text', 'prompt' => 'Su kien nao can xoa han?' ],
				],
				'optional_slots' => [],
				'tool'       => 'scheduler_delete_event',
				'ai_compose' => false,
				'slot_order' => [ 'event_ref' ],
			],
			'scheduler_find_free_slots' => [
				'required_slots' => [],
				'optional_slots' => [
					'date'         => [ 'type' => 'text', 'default' => '' ],
					'duration_min' => [ 'type' => 'number', 'default' => 60 ],
					'day_start'    => [ 'type' => 'text', 'default' => '08:00' ],
					'day_end'      => [ 'type' => 'text', 'default' => '18:00' ],
					'max_results'  => [ 'type' => 'number', 'default' => 5 ],
				],
				'tool'       => 'scheduler_find_free_slots',
				'ai_compose' => false,
				'slot_order' => [ 'date', 'duration_min', 'day_start', 'day_end', 'max_results' ],
			],
			'scheduler_sync_google' => [
				'required_slots' => [],
				'optional_slots' => [],
				'tool'       => 'scheduler_sync_google',
				'ai_compose' => false,
				'slot_order' => [],
			],
		],
		'tools' => BizCity_Scheduler_Tools::get_provider_tools(),
		'examples' => BizCity_Scheduler_Tools::get_examples(),
		'context' => function ( $goal, $slots, $user_id, $conversation ) {
			$agenda = '';
			if ( $user_id > 0 ) {
				$agenda = BizCity_Scheduler_Manager::instance()->build_today_context( (int) $user_id );
			}

			return "Provider: scheduler\n"
				. "Role: high-priority atomic calendar tools for planner composition\n"
				. "Policy: use the smallest scheduler_* tool that can satisfy the request; do not jump to macro workflows unless the case repeats often and remains transparent.\n"
				. ( $agenda ? $agenda . "\n" : '' )
				. 'Goal: ' . $goal . "\n";
		},
		'instructions' => function ( $goal ) {
			return 'Voi cac yeu cau lich, uu tien tool atomic scheduler_* co I/O ro rang. Neu can workflow lon, phai giai thich duoc no gom nhung atomic tool nao va output trung gian la gi.';
		},
	] );
} );
