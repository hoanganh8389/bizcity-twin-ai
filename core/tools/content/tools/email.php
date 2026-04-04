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
 * BizCity Atomic Content Tools — Email
 *
 * Phase 1.4c: generate_email_sales, generate_email_reply
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Generate sales email content.
 */
function bizcity_atomic_generate_email_sales( array $slots ): array {
	$product  = $slots['product'] ?? $slots['topic'] ?? '';
	$audience = $slots['audience'] ?? '';
	$offer    = $slots['offer'] ?? '';

	$tool_template = "Viết email bán hàng bằng tiếng Việt, chuyên nghiệp.\n"
	               . "Sản phẩm: {$product}\n"
	               . ( $audience ? "Đối tượng: {$audience}\n" : '' )
	               . ( $offer ? "Ưu đãi: {$offer}\n" : '' )
	               . "Yêu cầu:\n"
	               . "- Subject line hấp dẫn\n"
	               . "- Body HTML 200-400 từ\n"
	               . "- Preview text 90 ký tự\n"
	               . "- CTA rõ ràng\n"
	               . 'Trả về JSON: {"subject":"...","body_html":"...","preview":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 2048 ] );

	return [
		'success'    => ! empty( $result['content'] ) || ! empty( $result['metadata']['body_html'] ),
		'content'    => $result['metadata']['body_html'] ?? $result['content'] ?? '',
		'title'      => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'body_html'  => $result['metadata']['body_html'] ?? $result['content'] ?? '',
		'preview'    => $result['metadata']['preview'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

/**
 * Generate email reply to a customer/partner.
 */
function bizcity_atomic_generate_email_reply( array $slots ): array {
	$original = $slots['original_email'] ?? $slots['content'] ?? '';
	$intent   = $slots['intent'] ?? $slots['message'] ?? 'Phản hồi tích cực, chuyên nghiệp';

	$tool_template = "Viết email phản hồi bằng tiếng Việt, tone chuyên nghiệp.\n"
	               . "Ý định phản hồi: {$intent}\n"
	               . "Yêu cầu:\n"
	               . "- Subject line phù hợp\n"
	               . "- Body HTML rõ ràng, ngắn gọn\n"
	               . 'Trả về JSON: {"subject":"...","body_html":"..."}' . "\n\n"
	               . "Email gốc cần phản hồi:\n" . mb_substr( $original, 0, 4000 );

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $intent );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 2048 ] );

	return [
		'success'    => ! empty( $result['content'] ) || ! empty( $result['metadata']['body_html'] ),
		'content'    => $result['metadata']['body_html'] ?? $result['content'] ?? '',
		'title'      => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'body_html'  => $result['metadata']['body_html'] ?? $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
