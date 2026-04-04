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
 * BizCity Atomic Content Tools — Blog & SEO
 *
 * Phase 1.4c: generate_blog_content, generate_seo_content, rewrite_content
 *
 * All functions follow the Atomic Content Tool Contract:
 *   - Input: $slots (topic, _meta._skill, tool-specific params)
 *   - Output: { success, title, content, excerpt/metadata, skill_used }
 *   - No side effects (no wp_insert_post, no API calls)
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Generate blog content (700-1000 words, Vietnamese, HTML formatting).
 */
function bizcity_atomic_generate_blog_content( array $slots ): array {
	$topic  = $slots['topic'] ?? $slots['message'] ?? '';
	$tone   = $slots['tone']  ?? 'professional';
	$length = $slots['length'] ?? '700-1000';

	$tool_template = "Viết bài blog bằng tiếng Việt, {$length} từ, tone {$tone}.\n"
	               . "Sử dụng HTML (<b>, <em>, <mark>), không markdown.\n"
	               . "Cuối bài có CTA.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","excerpt":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'excerpt'    => $result['metadata']['excerpt'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

/**
 * Generate SEO-optimized content with meta description and schema hints.
 */
function bizcity_atomic_generate_seo_content( array $slots ): array {
	$topic    = $slots['topic'] ?? $slots['message'] ?? '';
	$keywords = $slots['keywords'] ?? '';
	$length   = $slots['length'] ?? '1000-1500';

	$tool_template = "Viết bài SEO bằng tiếng Việt, {$length} từ.\n"
	               . "Từ khóa chính: {$keywords}\n"
	               . "Yêu cầu:\n"
	               . "- Tiêu đề H1 chứa từ khóa chính\n"
	               . "- Cấu trúc heading: H2, H3 rõ ràng (dùng HTML tags)\n"
	               . "- Meta description 150-160 ký tự\n"
	               . "- Internal linking hints\n"
	               . "- Sử dụng HTML, không markdown\n"
	               . 'Trả về JSON: {"title":"...","content":"...","meta_desc":"...","schema_json":""}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'     => ! empty( $result['content'] ),
		'title'       => $result['title'] ?? '',
		'content'     => $result['content'] ?? '',
		'meta_desc'   => $result['metadata']['meta_desc'] ?? '',
		'schema_json' => $result['metadata']['schema_json'] ?? '',
		'skill_used'  => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

/**
 * Rewrite / improve existing content.
 */
function bizcity_atomic_rewrite_content( array $slots ): array {
	$source      = $slots['source_content'] ?? $slots['content'] ?? '';
	$instruction = $slots['instruction'] ?? $slots['message'] ?? 'Viết lại rõ ràng, chuyên nghiệp hơn';

	$tool_template = "Viết lại nội dung dưới đây theo yêu cầu.\n"
	               . "Yêu cầu: {$instruction}\n"
	               . "Giữ nguyên ý chính, cải thiện chất lượng.\n"
	               . "Sử dụng HTML, không markdown.\n"
	               . 'Trả về JSON: {"title":"...","content":"..."}' . "\n\n"
	               . "Nội dung gốc:\n" . mb_substr( $source, 0, 8000 );

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $instruction );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
