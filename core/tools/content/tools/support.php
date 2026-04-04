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
 * BizCity Atomic Content Tools — Support & Communication
 *
 * generate_support_reply, generate_chatbot_response, generate_announcement
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_support_reply( array $slots ): array {
	$ticket   = $slots['ticket'] ?? $slots['topic'] ?? '';
	$customer = $slots['customer'] ?? '';
	$tone     = $slots['tone'] ?? 'empathetic';

	$tool_template = "Viết phản hồi support / CSKH tiếng Việt, tone {$tone}.\n"
	               . "Nội dung ticket: {$ticket}\n"
	               . ( $customer ? "Khách hàng: {$customer}\n" : '' )
	               . "Cấu trúc: Xin lỗi/cảm ơn → Xác nhận hiểu vấn đề → Giải pháp → Next step → Lời kết.\n"
	               . "Tránh robot, thể hiện sự quan tâm.\n"
	               . 'Trả về JSON: {"content":"...","subject":"...","resolution_type":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $ticket );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'content'    => $result['content'] ?? '',
		'subject'    => $result['metadata']['subject'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_chatbot_response( array $slots ): array {
	$message  = $slots['message'] ?? $slots['topic'] ?? '';
	$context  = $slots['context'] ?? '';
	$persona  = $slots['persona'] ?? 'helpful_assistant';

	$tool_template = "Viết câu trả lời chatbot tiếng Việt, persona: {$persona}.\n"
	               . "Tin nhắn khách: {$message}\n"
	               . ( $context ? "Ngữ cảnh:\n{$context}\n" : '' )
	               . "Ngắn gọn (1-3 câu), thân thiện, có action rõ ràng.\n"
	               . "Nếu không biết → thừa nhận + gợi ý hướng khác.\n"
	               . 'Trả về JSON: {"content":"...","suggested_actions":["..."],"confidence":"high|medium|low"}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $message );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 512 ] );

	return [
		'success'           => ! empty( $result['content'] ),
		'content'           => $result['content'] ?? '',
		'suggested_actions' => $result['metadata']['suggested_actions'] ?? [],
		'confidence'        => $result['metadata']['confidence'] ?? 'medium',
		'skill_used'        => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'       => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_announcement( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$channel  = $slots['channel'] ?? 'all';
	$urgency  = $slots['urgency'] ?? 'normal';

	$tool_template = "Viết thông báo tiếng Việt, kênh: {$channel}, urgency: {$urgency}.\n"
	               . "Nếu urgent → highlight ngay dòng đầu.\n"
	               . "Rõ ràng, ngắn gọn: What → When → Impact → Action needed.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","summary":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 1024 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'summary'    => $result['metadata']['summary'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}
