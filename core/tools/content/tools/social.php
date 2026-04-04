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
 * BizCity Atomic Content Tools — Social Media
 *
 * Phase 1.4c: generate_fb_post, generate_ad_copy
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Generate Facebook post content.
 */
function bizcity_atomic_generate_fb_post( array $slots ): array {
	$topic = $slots['topic'] ?? $slots['message'] ?? '';
	$style = $slots['style'] ?? 'engaging';

	$tool_template = "Viết bài Facebook bằng tiếng Việt, 100-300 từ, tone {$style}.\n"
	               . "Mở đầu gây tò mò, kết bằng CTA.\n"
	               . "Thêm emoji phù hợp.\n"
	               . 'Trả về JSON: {"text":"...","hashtags":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['metadata']['text'] ?? $result['content'] ?? '',
		'text'       => $result['metadata']['text'] ?? $result['content'] ?? '',
		'hashtags'   => $result['metadata']['hashtags'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

/**
 * Generate ad copy (Facebook Ads, Google Ads).
 */
function bizcity_atomic_generate_ad_copy( array $slots ): array {
	$product  = $slots['product'] ?? $slots['topic'] ?? '';
	$audience = $slots['audience'] ?? '';
	$platform = $slots['platform'] ?? 'facebook';

	$tool_template = "Viết copy quảng cáo {$platform} bằng tiếng Việt.\n"
	               . "Sản phẩm/dịch vụ: {$product}\n"
	               . ( $audience ? "Đối tượng: {$audience}\n" : '' )
	               . "Yêu cầu:\n"
	               . "- Headline ngắn gọn, attention-grabbing\n"
	               . "- Primary text 80-125 từ\n"
	               . "- CTA rõ ràng\n"
	               . 'Trả về JSON: {"headline":"...","primary_text":"...","description":"...","cta":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'      => ! empty( $result['content'] ) || ! empty( $result['metadata']['headline'] ),
		'content'      => $result['metadata']['primary_text'] ?? $result['content'] ?? '',
		'headline'     => $result['metadata']['headline'] ?? $result['title'] ?? '',
		'primary_text' => $result['metadata']['primary_text'] ?? $result['content'] ?? '',
		'description'  => $result['metadata']['description'] ?? '',
		'cta'          => $result['metadata']['cta'] ?? '',
		'skill_used'   => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'  => $result['tokens_used'] ?? 0,
	];
}
