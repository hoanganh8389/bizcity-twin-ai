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
 * BizCity Atomic Content Tools — Marketing & Advertising
 *
 * generate_comparison, generate_testimonial_request, generate_campaign_brief
 *
 * (generate_ad_copy already in social.php, generate_product_desc in product.php)
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_comparison( array $slots ): array {
	$product_a = $slots['product_a'] ?? $slots['topic'] ?? '';
	$product_b = $slots['product_b'] ?? '';

	$tool_template = "Viết bài so sánh sản phẩm/dịch vụ tiếng Việt.\n"
	               . "Sản phẩm A: {$product_a}\n"
	               . "Sản phẩm B: {$product_b}\n"
	               . "Cấu trúc: Giới thiệu → Bảng so sánh → Ưu nhược điểm → Kết luận (gợi ý chọn theo nhu cầu).\n"
	               . "Công bằng, không thiên vị.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","comparison_table":[{"feature":"...","product_a":"...","product_b":"..."}],"verdict":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product_a );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'          => ! empty( $result['content'] ),
		'title'            => $result['title'] ?? '',
		'content'          => $result['content'] ?? '',
		'comparison_table' => $result['metadata']['comparison_table'] ?? [],
		'verdict'          => $result['metadata']['verdict'] ?? '',
		'skill_used'       => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'      => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_testimonial_request( array $slots ): array {
	$product  = $slots['product'] ?? $slots['topic'] ?? '';
	$customer = $slots['customer'] ?? '';
	$channel  = $slots['channel'] ?? 'email';

	$tool_template = "Viết tin nhắn xin đánh giá / testimonial tiếng Việt.\n"
	               . "Kênh: {$channel}, khách hàng: {$customer}, sản phẩm: {$product}\n"
	               . "Lịch sự, ngắn gọn, gợi ý cụ thể những gì khách có thể chia sẻ.\n"
	               . 'Trả về JSON: {"content":"...","subject":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_campaign_brief( array $slots ): array {
	$topic    = $slots['topic'] ?? $slots['campaign'] ?? '';
	$budget   = $slots['budget'] ?? '';
	$duration = $slots['duration'] ?? '';
	$channels = $slots['channels'] ?? 'facebook,google';

	$tool_template = "Viết campaign brief tiếng Việt.\n"
	               . "Chiến dịch: {$topic}\n"
	               . ( $budget ? "Ngân sách: {$budget}\n" : '' )
	               . ( $duration ? "Thời gian: {$duration}\n" : '' )
	               . "Kênh: {$channels}\n"
	               . "Cấu trúc: Mục tiêu → Target audience → Key message → Kênh truyền thông → Timeline → KPI → Creative guidelines.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","objectives":["..."],"kpis":["..."],"channels":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'objectives' => $result['metadata']['objectives'] ?? [],
		'kpis'       => $result['metadata']['kpis'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
