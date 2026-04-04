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
 * BizCity Atomic Content Tools — Email (Extended)
 *
 * generate_email_quote, generate_email_contract, generate_email_announce,
 * generate_email_newsletter, generate_email_followup
 *
 * (generate_email_sales, generate_email_reply already in email.php)
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_email_quote( array $slots ): array {
	$product  = $slots['product'] ?? $slots['topic'] ?? '';
	$customer = $slots['customer'] ?? '';
	$price    = $slots['price'] ?? '';

	$tool_template = "Viết email báo giá tiếng Việt, chuyên nghiệp.\n"
	               . "Khách hàng: {$customer}\n"
	               . "Sản phẩm/dịch vụ: {$product}\n"
	               . ( $price ? "Mức giá: {$price}\n" : '' )
	               . "Cấu trúc: Lời chào → Tóm tắt yêu cầu → Bảng báo giá → Điều khoản → CTA.\n"
	               . 'Trả về JSON: {"title":"...","subject":"...","content":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $product );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_email_contract( array $slots ): array {
	$topic   = $slots['topic'] ?? '';
	$parties = $slots['parties'] ?? '';

	$tool_template = "Viết email gửi hợp đồng / thỏa thuận tiếng Việt.\n"
	               . "Các bên: {$parties}\n"
	               . "Nội dung: tóm tắt điều khoản chính + hướng dẫn ký.\n"
	               . "Tone: trang trọng, pháp lý.\n"
	               . 'Trả về JSON: {"title":"...","subject":"...","content":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_email_announce( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$audience = $slots['audience'] ?? 'all_staff';

	$tool_template = "Viết email thông báo tiếng Việt, đối tượng: {$audience}.\n"
	               . "Rõ ràng, ngắn gọn. Highlight thông tin quan trọng bằng bold.\n"
	               . "Cuối: next steps hoặc contact info.\n"
	               . 'Trả về JSON: {"title":"...","subject":"...","content":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_email_newsletter( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$sections = $slots['sections'] ?? '3';

	$tool_template = "Viết email newsletter tiếng Việt, {$sections} sections.\n"
	               . "Cấu trúc: Subject line hook → Greeting → Sections (title+content+link) → CTA → Footer.\n"
	               . "Tone: thân thiện, informative.\n"
	               . 'Trả về JSON: {"title":"...","subject":"...","content":"...","sections":[{"heading":"...","body":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'sections'   => $result['metadata']['sections'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_email_followup( array $slots ): array {
	$topic   = $slots['topic'] ?? $slots['context'] ?? '';
	$purpose = $slots['purpose'] ?? 'general';

	$tool_template = "Viết email follow-up tiếng Việt, mục đích: {$purpose}.\n"
	               . "Nhắc lại ngữ cảnh trước → Lý do follow-up → Next step cụ thể.\n"
	               . "Ngắn gọn, 100-200 từ, không gây áp lực.\n"
	               . 'Trả về JSON: {"title":"...","subject":"...","content":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
