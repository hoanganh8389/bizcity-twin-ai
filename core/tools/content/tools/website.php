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
 * BizCity Atomic Content Tools — Website & Landing Pages
 *
 * generate_landing_page, generate_faq
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_landing_page( array $slots ): array {
	$topic   = $slots['topic'] ?? $slots['product'] ?? '';
	$purpose = $slots['purpose'] ?? 'sales';
	$cta     = $slots['cta_text'] ?? 'Đăng ký ngay';

	$tool_template = "Viết nội dung landing page tiếng Việt, mục đích: {$purpose}.\n"
	               . "CTA button: {$cta}\n"
	               . "Cấu trúc:\n"
	               . "- Hero headline + sub-headline\n"
	               . "- Problem → Solution\n"
	               . "- 3-5 Benefits (icon-ready)\n"
	               . "- Social proof section\n"
	               . "- FAQ 3 câu\n"
	               . "- Final CTA\n"
	               . "Dùng HTML, không markdown.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","hero_headline":"...","benefits":["..."],"faq":[{"q":"...","a":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'       => ! empty( $result['content'] ),
		'title'         => $result['title'] ?? '',
		'content'       => $result['content'] ?? '',
		'hero_headline' => $result['metadata']['hero_headline'] ?? '',
		'benefits'      => $result['metadata']['benefits'] ?? [],
		'faq'           => $result['metadata']['faq'] ?? [],
		'skill_used'    => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'   => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_faq( array $slots ): array {
	$topic     = $slots['topic'] ?? '';
	$count     = min( 20, max( 3, (int) ( $slots['count'] ?? 10 ) ) );
	$audience  = $slots['audience'] ?? 'khách hàng';

	$tool_template = "Tạo {$count} câu hỏi thường gặp (FAQ) về chủ đề, đối tượng: {$audience}.\n"
	               . "Mỗi câu trả lời 2-4 câu, ngắn gọn, chuyên nghiệp.\n"
	               . "Dùng HTML, không markdown.\n"
	               . 'Trả về JSON: {"title":"FAQ: ...","content":"<div>...</div>","items":[{"question":"...","answer":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'items'      => $result['metadata']['items'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
