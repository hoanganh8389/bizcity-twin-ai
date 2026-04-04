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
 * Distribution / Delivery Tool Group — Bootstrap
 *
 * Registers delivery-only atomic tools:
 *   post_facebook, send_email, send_zalo, publish_wp_post, schedule_post
 *
 * KEY DESIGN:
 *   - accepts_skill = false  → These tools DELIVER content, not generate it.
 *   - Receive content from upstream pipeline nodes.
 *   - If content slot is empty, fallback generates a short placeholder.
 *   - tool_type = 'distribution' (separate from content).
 *
 * @package BizCity\TwinAI\Tools\Distribution
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

/* ──────────────────────────────────────────────────────────────
 * Load tool implementations
 * ────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/tools.php';

/* ──────────────────────────────────────────────────────────────
 * Register distribution tools — priority 26 (after content 25)
 * ────────────────────────────────────────────────────────────── */
add_action( 'init', function () {

	if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return;
	}

	$tools = BizCity_Intent_Tools::instance();

	/* ── Facebook ── */
	$tools->register( 'post_facebook', [
		'description'   => 'Đăng bài lên Facebook Page(s)',
		'input_fields'  => [
			'content'   => [ 'required' => true,  'type' => 'text' ],
			'image_url' => [ 'required' => false, 'type' => 'text' ],
			'link'      => [ 'required' => false, 'type' => 'text',  'desc' => 'URL bài viết (thêm vào cuối post)' ],
		],
		'tool_type'     => 'distribution',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_dist_post_facebook' );

	/* ── Email ── */
	$tools->register( 'send_email', [
		'description'   => 'Gửi email qua Gmail/SMTP/Outlook',
		'input_fields'  => [
			'to'      => [ 'required' => true,  'type' => 'text' ],
			'subject' => [ 'required' => true,  'type' => 'text' ],
			'content' => [ 'required' => true,  'type' => 'text' ],
			'cc'      => [ 'required' => false, 'type' => 'text' ],
			'bcc'     => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'distribution',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_dist_send_email' );

	/* ── Zalo ── */
	$tools->register( 'send_zalo', [
		'description'   => 'Gửi tin nhắn Zalo OA / Zalo cá nhân',
		'input_fields'  => [
			'content'  => [ 'required' => true,  'type' => 'text' ],
			'chat_id'  => [ 'required' => true,  'type' => 'text' ],
		],
		'tool_type'     => 'distribution',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_dist_send_zalo' );

	/* ── WordPress Post ── */
	$tools->register( 'publish_wp_post', [
		'description'   => 'Tạo và xuất bản bài viết WordPress',
		'input_fields'  => [
			'title'     => [ 'required' => true,  'type' => 'text' ],
			'content'   => [ 'required' => true,  'type' => 'text' ],
			'image_url' => [ 'required' => false, 'type' => 'text' ],
			'status'    => [ 'required' => false, 'type' => 'choice', 'options' => 'publish,draft,pending', 'default' => 'publish' ],
			'category'  => [ 'required' => false, 'type' => 'text' ],
		],
		'output_fields' => [
			'post_id'  => 'ID bài viết WordPress',
			'post_url' => 'URL bài viết (permalink)',
		],
		'tool_type'     => 'distribution',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_dist_publish_wp_post' );

	/* ── Scheduled Post ── */
	$tools->register( 'schedule_post', [
		'description'   => 'Lên lịch xuất bản bài viết WordPress vào thời điểm tương lai',
		'input_fields'  => [
			'title'         => [ 'required' => true,  'type' => 'text' ],
			'content'       => [ 'required' => true,  'type' => 'text' ],
			'post_datetime' => [ 'required' => true,  'type' => 'text' ],
			'image_url'     => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'distribution',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_dist_schedule_post' );

}, 26 ); // priority 26: after content (25)
