<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler Tools — Bootstrap
 *
 * Atomic calendar tool family — wraps existing BizCity_Scheduler_Manager.
 * High priority: if prompt contains time elements, planner invokes these first.
 * These are the timeline anchors for workflow, reminder, deadline, follow-up.
 *
 * Tools:
 *   scheduler_create_event     — Tạo sự kiện lịch
 *   scheduler_update_event     — Cập nhật sự kiện
 *   scheduler_complete_event   — Đánh dấu hoàn thành
 *   scheduler_cancel_event     — Hủy sự kiện
 *   scheduler_list_events      — Liệt kê sự kiện theo khoảng thời gian
 *   scheduler_today_context    — Lấy agenda hôm nay (LLM context)
 *   scheduler_due_followups    — Liệt kê nhắc nhở sắp đến hạn
 *   scheduler_next_actions     — Gợi ý hành động tiếp theo dựa trên lịch
 *
 * @package  BizCity_Tools\Scheduler
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/tools.php';

add_action( 'init', function () {
	if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return;
	}

	$tools = BizCity_Intent_Tools::instance();

	/* ── Write operations (trust_tier=2) ── */

	$tools->register( 'scheduler_create_event', [
		'description'  => 'Tạo sự kiện / lịch hẹn mới',
		'input_fields' => [
			'title'        => [ 'required' => true,  'type' => 'text' ],
			'start_at'     => [ 'required' => true,  'type' => 'text' ],
			'end_at'       => [ 'required' => false, 'type' => 'text' ],
			'description'  => [ 'required' => false, 'type' => 'text' ],
			'all_day'      => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			'reminder_min' => [ 'required' => false, 'type' => 'number',  'default' => 15 ],
			'source'       => [ 'required' => false, 'type' => 'text',    'default' => 'ai_plan' ],
			'ai_context'   => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
	], 'bizcity_tool_scheduler_create_event' );

	$tools->register( 'scheduler_update_event', [
		'description'  => 'Cập nhật sự kiện đã có (đổi giờ, tên, mô tả)',
		'input_fields' => [
			'event_id'     => [ 'required' => true,  'type' => 'number' ],
			'title'        => [ 'required' => false, 'type' => 'text' ],
			'start_at'     => [ 'required' => false, 'type' => 'text' ],
			'end_at'       => [ 'required' => false, 'type' => 'text' ],
			'description'  => [ 'required' => false, 'type' => 'text' ],
			'all_day'      => [ 'required' => false, 'type' => 'boolean' ],
			'reminder_min' => [ 'required' => false, 'type' => 'number' ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
	], 'bizcity_tool_scheduler_update_event' );

	$tools->register( 'scheduler_complete_event', [
		'description'  => 'Đánh dấu sự kiện đã hoàn thành',
		'input_fields' => [
			'event_id' => [ 'required' => true, 'type' => 'number' ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
	], 'bizcity_tool_scheduler_complete_event' );

	$tools->register( 'scheduler_cancel_event', [
		'description'  => 'Hủy sự kiện lịch',
		'input_fields' => [
			'event_id' => [ 'required' => true, 'type' => 'number' ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
	], 'bizcity_tool_scheduler_cancel_event' );

	/* ── Read operations (auto_execute) ── */

	$tools->register( 'scheduler_list_events', [
		'description'  => 'Liệt kê sự kiện theo khoảng thời gian',
		'input_fields' => [
			'date_from'   => [ 'required' => false, 'type' => 'text' ],
			'date_to'     => [ 'required' => false, 'type' => 'text' ],
			'status'      => [ 'required' => false, 'type' => 'choice', 'options' => 'active,done,cancelled,all', 'default' => 'active' ],
			'max_results' => [ 'required' => false, 'type' => 'number', 'default' => 20 ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
		'auto_execute' => true,
	], 'bizcity_tool_scheduler_list_events' );

	$tools->register( 'scheduler_today_context', [
		'description'  => 'Lấy agenda / lịch trình hôm nay (cho LLM context)',
		'input_fields' => [],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
		'auto_execute' => true,
	], 'bizcity_tool_scheduler_today_context' );

	$tools->register( 'scheduler_due_followups', [
		'description'  => 'Liệt kê nhắc nhở / follow-up sắp đến hạn',
		'input_fields' => [
			'hours_ahead' => [ 'required' => false, 'type' => 'number', 'default' => 24 ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
		'auto_execute' => true,
	], 'bizcity_tool_scheduler_due_followups' );

	$tools->register( 'scheduler_next_actions', [
		'description'  => 'Gợi ý hành động tiếp theo dựa trên lịch trình và deadline',
		'input_fields' => [
			'context' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'    => 'scheduler',
		'tier'         => 1,
		'auto_execute' => true,
	], 'bizcity_tool_scheduler_next_actions' );

}, 16 );  // Priority 16 — after planning (15), before builtin (20)
