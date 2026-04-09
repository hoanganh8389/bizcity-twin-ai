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
 * BizCity Video Tools — Bootstrap
 *
 * Registers 5 atomic video tools into BizCity_Intent_Tools.
 * Actual execution delegates to Kling/PiAPI blocks in bizcity-automation plugin.
 *
 * Tools:
 *   video_create_script     — AI tạo kịch bản video từ topic
 *   video_create_job        — Gửi job tạo video (Kling/PiAPI) — async
 *   video_poll_status       — Check trạng thái video job — async poll
 *   video_fetch             — Download video về Media Library
 *   video_post_production   — TTS + BGM + FFmpeg post-production
 *
 * Reference: workflow-41 (Zalo → script → kling → poll → fetch → post-prod)
 *
 * @package  BizCity_Video
 * @since    2026-04-07 (Phase 1.12)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Register video tools into Intent Tools registry ──────────────── */
add_action( 'init', function () {
	if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return;
	}

	$tools = BizCity_Intent_Tools::instance();

	// ── 1. Script Generator (content_tier=1: tạo nội dung kịch bản, giống generate_tiktok_script)
	// → Được gọi bởi it_call_content (Ngón 4) — KHÔNG dùng ai_video_script_generator block cũ
	$tools->register( 'video_create_script', [
		'description'   => 'AI tạo kịch bản video (scene-by-scene script) từ chủ đề',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text', 'description' => 'Chủ đề hoặc ý tưởng video' ],
			'tone'     => [ 'required' => false, 'type' => 'choice', 'options' => 'professional,casual,dramatic,fun,cinematic', 'description' => 'Phong cách kịch bản' ],
			'duration' => [ 'required' => false, 'type' => 'choice', 'options' => '15s,30s,60s,90s', 'description' => 'Thời lượng mong muốn' ],
			'language' => [ 'required' => false, 'type' => 'choice', 'options' => 'vi,en', 'description' => 'Ngôn ngữ kịch bản' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
		'async'         => false,
		'output_fields' => [
			'script_id' => [ 'type' => 'int',    'description' => 'ID kịch bản đã tạo' ],
			'content'   => [ 'type' => 'string', 'description' => 'Nội dung kịch bản' ],
		],
	], 'bizcity_video_create_script' );

	// ── 2. Create Video Job (content_tier=0: utility — submit job, ko tạo content)
	// → Được gọi bởi it_call_tool (Ngón 5), delegate tới BizCity_Tool_Kling::create_video()
	$tools->register( 'video_create_job', [
		'description'   => 'Gửi yêu cầu tạo video AI (Kling/PiAPI) — trả về job_id để theo dõi',
		'input_fields'  => [
			'script_id' => [ 'required' => false, 'type' => 'text', 'description' => 'ID kịch bản (từ video_create_script)' ],
			'image_url' => [ 'required' => true,  'type' => 'text', 'description' => 'URL ảnh gốc (dùng làm frame đầu)' ],
			'prompt'    => [ 'required' => false, 'type' => 'text', 'description' => 'Mô tả chuyển động / hiệu ứng mong muốn' ],
			'model'     => [ 'required' => false, 'type' => 'choice', 'options' => 'kling-v1,kling-v1-5,kling-v2', 'description' => 'Model AI video' ],
			'duration'  => [ 'required' => false, 'type' => 'choice', 'options' => '5,10', 'description' => 'Thời lượng clip (giây)' ],
			'ratio'     => [ 'required' => false, 'type' => 'choice', 'options' => '16:9,9:16,1:1', 'description' => 'Tỷ lệ khung hình' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'async'         => true,
		'output_fields' => [
			'job_id' => [ 'type' => 'string', 'description' => 'Job ID từ Kling/PiAPI' ],
			'status' => [ 'type' => 'string', 'description' => 'Trạng thái job' ],
		],
	], 'bizcity_video_create_job' );

	// ── 3. Poll Video Job Status ─────────────────────────────────
	$tools->register( 'video_poll_status', [
		'description'   => 'Kiểm tra trạng thái video job (pending/processing/completed/failed)',
		'input_fields'  => [
			'job_id' => [ 'required' => true, 'type' => 'text', 'description' => 'Job ID từ video_create_job' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'async'         => true,
		'output_fields' => [
			'status'    => [ 'type' => 'string', 'description' => 'Trạng thái: pending|processing|completed|failed' ],
			'progress'  => [ 'type' => 'int',    'description' => 'Tiến trình %' ],
			'video_url' => [ 'type' => 'string', 'description' => 'URL video khi hoàn thành' ],
		],
	], 'bizcity_video_poll_status' );

	// ── 4. Fetch / Download Video ────────────────────────────────
	$tools->register( 'video_fetch', [
		'description'   => 'Download video hoàn thành về WordPress Media Library',
		'input_fields'  => [
			'job_id'    => [ 'required' => true,  'type' => 'text', 'description' => 'Job ID từ video_create_job' ],
			'video_url' => [ 'required' => false, 'type' => 'text', 'description' => 'URL video (nếu đã có từ poll)' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'async'         => false,
		'output_fields' => [
			'file_path'     => [ 'type' => 'string', 'description' => 'Đường dẫn file video local' ],
			'attachment_id' => [ 'type' => 'int',    'description' => 'WordPress Attachment ID' ],
		],
	], 'bizcity_video_fetch' );

	// ── 5. Post-Production (content_tier=0: utility — xử lý file, ko tạo content mới)
	// → Được gọi bởi it_call_tool (Ngón 5), delegate tới kling_post_production block
	$tools->register( 'video_post_production', [
		'description'   => 'Hậu kỳ video: thêm TTS voiceover, nhạc nền BGM, hiệu ứng FFmpeg',
		'input_fields'  => [
			'video_path' => [ 'required' => true,  'type' => 'text', 'description' => 'Đường dẫn file video (local hoặc attachment_id)' ],
			'tts_text'   => [ 'required' => false, 'type' => 'text', 'description' => 'Nội dung voiceover (TTS)' ],
			'tts_voice'  => [ 'required' => false, 'type' => 'choice', 'options' => 'vi-female,vi-male,en-female,en-male', 'description' => 'Giọng đọc' ],
			'bgm_style'  => [ 'required' => false, 'type' => 'choice', 'options' => 'upbeat,calm,dramatic,corporate,none', 'description' => 'Phong cách nhạc nền' ],
			'subtitle'   => [ 'required' => false, 'type' => 'choice', 'options' => 'yes,no', 'description' => 'Thêm phụ đề tự động' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'async'         => false,
		'output_fields' => [
			'output_path'   => [ 'type' => 'string', 'description' => 'Đường dẫn video đã hậu kỳ' ],
			'attachment_id' => [ 'type' => 'int',    'description' => 'WordPress Attachment ID' ],
		],
	], 'bizcity_video_post_production' );

}, 25 ); // priority 25 = same tier as content tools


/* ── Callback stubs — delegate to bizcity-automation Kling blocks ── */

/**
 * Stub: video_create_script
 * Delegates to AI script generation (LLM call similar to ai_video_script_generator block).
 */
function bizcity_video_create_script( $params, $context = [] ) {
	// TODO Phase 1.12: Implement via LLM call or delegate to ai_video_script_generator block
	return [
		'success' => false,
		'message' => 'video_create_script — chưa triển khai. Sẽ delegate tới ai_video_script_generator.',
		'data'    => [],
	];
}

/**
 * Stub: video_create_job
 * Delegates to kling_create_job block in bizcity-automation.
 */
function bizcity_video_create_job( $params, $context = [] ) {
	// TODO Phase 1.12: Delegate to kling_create_job block via do_action or direct call
	return [
		'success' => false,
		'message' => 'video_create_job — chưa triển khai. Sẽ delegate tới kling_create_job.',
		'data'    => [],
	];
}

/**
 * Stub: video_poll_status
 * Delegates to kling_poll_status block in bizcity-automation.
 */
function bizcity_video_poll_status( $params, $context = [] ) {
	// TODO Phase 1.12: Delegate to kling_poll_status block
	return [
		'success' => false,
		'message' => 'video_poll_status — chưa triển khai. Sẽ delegate tới kling_poll_status.',
		'data'    => [],
	];
}

/**
 * Stub: video_fetch
 * Delegates to kling_fetch_video block in bizcity-automation.
 */
function bizcity_video_fetch( $params, $context = [] ) {
	// TODO Phase 1.12: Delegate to kling_fetch_video block
	return [
		'success' => false,
		'message' => 'video_fetch — chưa triển khai. Sẽ delegate tới kling_fetch_video.',
		'data'    => [],
	];
}

/**
 * Stub: video_post_production
 * Delegates to kling_post_production block in bizcity-automation.
 */
function bizcity_video_post_production( $params, $context = [] ) {
	// TODO Phase 1.12: Delegate to kling_post_production block
	return [
		'success' => false,
		'message' => 'video_post_production — chưa triển khai. Sẽ delegate tới kling_post_production.',
		'data'    => [],
	];
}
