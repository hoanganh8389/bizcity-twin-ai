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
 * BizCity Atomic Content Tools — Social Media (Extended)
 *
 * generate_threads_post, generate_ig_caption, generate_youtube_desc,
 * generate_tiktok_script, generate_linkedin_post, generate_zalo_message
 *
 * (generate_fb_post, generate_ad_copy already in social.php)
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_threads_post( array $slots ): array {
	$topic = $slots['topic'] ?? '';
	$style = $slots['style'] ?? 'casual';

	$tool_template = "Viết bài Threads tiếng Việt, style {$style}.\n"
	               . "Giới hạn 500 ký tự. Hook mạnh dòng đầu.\n"
	               . "Cuối: 3-5 hashtags.\n"
	               . 'Trả về JSON: {"title":"","content":"...","hashtags":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'hashtags'   => $result['metadata']['hashtags'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_ig_caption( array $slots ): array {
	$topic = $slots['topic'] ?? '';
	$style = $slots['style'] ?? 'engaging';
	$cta   = $slots['cta'] ?? '';

	$tool_template = "Viết caption Instagram tiếng Việt, style {$style}.\n"
	               . "Dòng đầu hook. Nội dung 100-200 từ. Emoji phù hợp.\n"
	               . ( $cta ? "CTA: {$cta}\n" : '' )
	               . "Cuối: 10-15 hashtags (mix trending + niche).\n"
	               . 'Trả về JSON: {"content":"...","hashtags":["..."],"emoji_count":N}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'hashtags'   => $result['metadata']['hashtags'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_youtube_desc( array $slots ): array {
	$topic = $slots['topic'] ?? $slots['video_title'] ?? '';

	$tool_template = "Viết mô tả video YouTube tiếng Việt.\n"
	               . "Cấu trúc:\n"
	               . "- Dòng 1-2: Hook + tóm tắt (hiện trên fold)\n"
	               . "- Timestamps (giả định)\n"
	               . "- Links placeholder\n"
	               . "- Hashtags 5-8\n"
	               . "- Tags SEO (comma-separated)\n"
	               . 'Trả về JSON: {"content":"...","tags":"...","hashtags":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'tags'       => $result['metadata']['tags'] ?? '',
		'hashtags'   => $result['metadata']['hashtags'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_tiktok_script( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$duration = $slots['duration'] ?? '60s';

	$tool_template = "Viết kịch bản TikTok tiếng Việt, thời lượng {$duration}.\n"
	               . "Cấu trúc:\n"
	               . "- Hook 3s đầu (bắt buộc gây tò mò)\n"
	               . "- Body: vấn đề → giải pháp\n"
	               . "- CTA cuối\n"
	               . "- Gợi ý âm nhạc / trending sound\n"
	               . 'Trả về JSON: {"title":"...","content":"...","hook":"...","cta":"...","music_suggestion":"...","hashtags":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'          => ! empty( $result['content'] ),
		'title'            => $result['title'] ?? '',
		'content'          => $result['content'] ?? '',
		'hook'             => $result['metadata']['hook'] ?? '',
		'music_suggestion' => $result['metadata']['music_suggestion'] ?? '',
		'hashtags'         => $result['metadata']['hashtags'] ?? [],
		'skill_used'       => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'      => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_linkedin_post( array $slots ): array {
	$topic = $slots['topic'] ?? '';
	$style = $slots['style'] ?? 'thought_leadership';

	$tool_template = "Viết bài LinkedIn tiếng Việt, style {$style}.\n"
	               . "Tone chuyên nghiệp, insight-driven.\n"
	               . "200-400 từ. Dòng đầu hook. Xuống dòng nhiều (LinkedIn format).\n"
	               . "Cuối: câu hỏi engage + 3-5 hashtags.\n"
	               . 'Trả về JSON: {"content":"...","hashtags":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'hashtags'   => $result['metadata']['hashtags'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_zalo_message( array $slots ): array {
	$topic   = $slots['topic'] ?? '';
	$purpose = $slots['purpose'] ?? 'customer_care';

	$tool_template = "Viết tin nhắn Zalo OA tiếng Việt, mục đích: {$purpose}.\n"
	               . "Ngắn gọn, thân thiện, tối đa 500 ký tự.\n"
	               . "Nếu chăm sóc khách hàng → xưng hô lịch sự.\n"
	               . "Nếu quảng cáo → hook mạnh + CTA.\n"
	               . 'Trả về JSON: {"content":"...","cta":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 512 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'cta'        => $result['metadata']['cta'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
