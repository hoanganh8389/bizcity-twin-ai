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
 * BizCity Atomic Content Tools — Video & Media Scripts
 *
 * generate_video_script, generate_shorts_script, generate_podcast_outline,
 * generate_presentation
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_video_script( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$duration = $slots['duration'] ?? '5-7 phút';
	$style    = $slots['style'] ?? 'educational';

	$tool_template = "Viết kịch bản video tiếng Việt, {$duration}, style {$style}.\n"
	               . "Cấu trúc:\n"
	               . "- Intro (hook + giới thiệu)\n"
	               . "- Body (chia section, mỗi section có: narration + visual direction)\n"
	               . "- Outro (tóm tắt + CTA subscribe)\n"
	               . "Ghi rõ [VISUAL: ...] cho mỗi đoạn.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","duration":"...","sections":[{"section":"...","narration":"...","visual":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'sections'   => $result['metadata']['sections'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_shorts_script( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$platform = $slots['platform'] ?? 'youtube_shorts';

	$tool_template = "Viết kịch bản video ngắn ({$platform}) tiếng Việt, 30-60 giây.\n"
	               . "Hook 3 giây đầu bắt buộc gây tò mò.\n"
	               . "Nội dung nhanh, cắt nhịp. Cuối: CTA follow/like.\n"
	               . "Gợi ý text overlay + trending audio.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","hook":"...","text_overlays":["..."],"music":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'       => ! empty( $result['content'] ),
		'title'         => $result['title'] ?? '',
		'content'       => $result['content'] ?? '',
		'hook'          => $result['metadata']['hook'] ?? '',
		'text_overlays' => $result['metadata']['text_overlays'] ?? [],
		'skill_used'    => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'   => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_podcast_outline( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$duration = $slots['duration'] ?? '20-30 phút';
	$guests   = $slots['guests'] ?? '';

	$tool_template = "Viết outline podcast tiếng Việt, {$duration}.\n"
	               . ( $guests ? "Khách mời: {$guests}\n" : '' )
	               . "Cấu trúc:\n"
	               . "- Intro (music + giới thiệu chủ đề + khách mời)\n"
	               . "- Segments (câu hỏi chính + follow-up + transition)\n"
	               . "- Rapid-fire round\n"
	               . "- Outro (key takeaways + CTA)\n"
	               . 'Trả về JSON: {"title":"...","content":"...","segments":[{"segment":"...","questions":["..."],"duration":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'segments'   => $result['metadata']['segments'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_presentation( array $slots ): array {
	$topic  = $slots['topic'] ?? '';
	$slides = min( 30, max( 5, (int) ( $slots['slides'] ?? 10 ) ) );
	$style  = $slots['style'] ?? 'professional';

	$tool_template = "Tạo nội dung bài thuyết trình tiếng Việt, {$slides} slides, style {$style}.\n"
	               . "Mỗi slide: title + bullet points (3-5) + speaker notes.\n"
	               . "Cấu trúc: Cover → Agenda → Content slides → Summary → Q&A → Thank you.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","slides":[{"slide_num":1,"title":"...","bullets":["..."],"speaker_notes":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'slides'     => $result['metadata']['slides'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
