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
 * BizCity Atomic Content Tools — Product Description
 *
 * Phase 1.4c: generate_product_desc
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Generate product description for WooCommerce / e-commerce.
 */
function bizcity_atomic_generate_product_desc( array $slots ): array {
	$product  = $slots['product_name'] ?? $slots['topic'] ?? '';
	$features = $slots['features'] ?? '';
	$audience = $slots['audience'] ?? '';

	$tool_template = "Viết mô tả sản phẩm bằng tiếng Việt, hấp dẫn.\n"
	               . "Tên sản phẩm: {$product}\n"
	               . ( $features ? "Đặc điểm: {$features}\n" : '' )
	               . ( $audience ? "Đối tượng: {$audience}\n" : '' )
	               . "Yêu cầu:\n"
	               . "- Short description (50-100 từ) cho listing\n"
	               . "- Long description (200-400 từ) HTML\n"
	               . "- SEO title\n"
	               . 'Trả về JSON: {"short_desc":"...","long_desc":"...","seo_title":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 2048 ] );

	return [
		'success'    => ! empty( $result['content'] ) || ! empty( $result['metadata']['long_desc'] ),
		'content'    => $result['metadata']['long_desc'] ?? $result['content'] ?? '',
		'title'      => $result['metadata']['seo_title'] ?? $result['title'] ?? '',
		'short_desc' => $result['metadata']['short_desc'] ?? '',
		'long_desc'  => $result['metadata']['long_desc'] ?? $result['content'] ?? '',
		'seo_title'  => $result['metadata']['seo_title'] ?? $result['title'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
