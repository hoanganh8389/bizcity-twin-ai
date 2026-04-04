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
 * BizCity Planning Tools — Bootstrap (Tier 0)
 *
 * Highest-priority tool family: workflow orchestration + knowledge management.
 * These tools run FIRST in any multi-step flow — the planner invokes them
 * before content, scheduler, or distribution tools.
 *
 * Tools:
 *   build_workflow        — Create multi-step execution plan
 *   knowledge_train       — Ingest + embed new knowledge source
 *   knowledge_search      — Semantic search across knowledge base
 *   knowledge_manage      — List, delete, promote knowledge sources
 *
 * @package  BizCity_Tools\Planning
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/tools.php';

add_action( 'init', function () {
	if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return;
	}

	$tools = BizCity_Intent_Tools::instance();

	/* ──────────────────────────────────────────────────────────────────
	 * build_workflow — Create multi-step execution plan
	 * ────────────────────────────────────────────────────────────────── */
	$tools->register( 'build_workflow', [
		'description'   => 'Tạo kế hoạch thực thi nhiều bước (multi-step workflow plan)',
		'input_fields'  => [
			'goal'          => [ 'required' => true,  'type' => 'text' ],
			'context'       => [ 'required' => false, 'type' => 'text' ],
			'max_steps'     => [ 'required' => false, 'type' => 'number', 'default' => 5 ],
			'available_tools' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'planning',
		'tier'          => 0,
		'auto_execute'  => false,
	], 'bizcity_tool_build_workflow' );

	/* ──────────────────────────────────────────────────────────────────
	 * knowledge_train — Ingest + embed new knowledge source
	 * ────────────────────────────────────────────────────────────────── */
	$tools->register( 'knowledge_train', [
		'description'   => 'Huấn luyện / nạp kiến thức mới vào hệ thống (file, URL, text)',
		'input_fields'  => [
			'source_type'  => [ 'required' => true,  'type' => 'choice', 'options' => 'url,text,file,manual,quick_faq' ],
			'source_name'  => [ 'required' => true,  'type' => 'text' ],
			'content'      => [ 'required' => false, 'type' => 'text' ],
			'url'          => [ 'required' => false, 'type' => 'text' ],
			'scope'        => [ 'required' => false, 'type' => 'choice', 'options' => 'user,project,session,agent', 'default' => 'user' ],
		],
		'tool_type'     => 'planning',
		'tier'          => 0,
		'auto_execute'  => false,
	], 'bizcity_tool_knowledge_train' );

	/* ──────────────────────────────────────────────────────────────────
	 * knowledge_search — Semantic search across knowledge base
	 * ────────────────────────────────────────────────────────────────── */
	$tools->register( 'knowledge_search', [
		'description'   => 'Tìm kiếm ngữ nghĩa trong kho kiến thức (semantic search)',
		'input_fields'  => [
			'query'        => [ 'required' => true,  'type' => 'text' ],
			'scope'        => [ 'required' => false, 'type' => 'choice', 'options' => 'user,project,session,all', 'default' => 'all' ],
			'max_results'  => [ 'required' => false, 'type' => 'number', 'default' => 5 ],
		],
		'tool_type'     => 'planning',
		'tier'          => 0,
		'auto_execute'  => true,
	], 'bizcity_tool_knowledge_search' );

	/* ──────────────────────────────────────────────────────────────────
	 * knowledge_manage — List, delete, promote knowledge sources
	 * ────────────────────────────────────────────────────────────────── */
	$tools->register( 'knowledge_manage', [
		'description'   => 'Quản lý kho kiến thức: liệt kê, xóa, chuyển scope',
		'input_fields'  => [
			'action'       => [ 'required' => true,  'type' => 'choice', 'options' => 'list,delete,promote' ],
			'source_id'    => [ 'required' => false, 'type' => 'number' ],
			'scope'        => [ 'required' => false, 'type' => 'choice', 'options' => 'user,project,session,agent' ],
		],
		'tool_type'     => 'planning',
		'tier'          => 0,
		'auto_execute'  => true,
	], 'bizcity_tool_knowledge_manage' );

}, 15 );  // Priority 15 — before content tools (25) and builtin tools (20)
