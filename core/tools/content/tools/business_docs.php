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
 * BizCity Atomic Content Tools — Business Documents
 *
 * generate_proposal, generate_report_content, generate_policy,
 * generate_sop, generate_job_description, generate_meeting_notes
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_atomic_generate_proposal( array $slots ): array {
	$topic    = $slots['topic'] ?? '';
	$client   = $slots['client'] ?? '';
	$scope    = $slots['scope'] ?? '';

	$tool_template = "Viết proposal / đề xuất tiếng Việt.\n"
	               . "Khách hàng: {$client}\n"
	               . "Phạm vi: {$scope}\n"
	               . "Cấu trúc: Executive Summary → Vấn đề → Giải pháp → Phạm vi → Timeline → Báo giá → Điều khoản.\n"
	               . "Tone: chuyên nghiệp, thuyết phục.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","executive_summary":"...","sections":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'           => ! empty( $result['content'] ),
		'title'             => $result['title'] ?? '',
		'content'           => $result['content'] ?? '',
		'executive_summary' => $result['metadata']['executive_summary'] ?? '',
		'skill_used'        => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'       => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_report_content( array $slots ): array {
	$topic  = $slots['topic'] ?? '';
	$type   = $slots['report_type'] ?? 'summary';
	$data   = $slots['data'] ?? '';

	$tool_template = "Viết báo cáo tiếng Việt, loại: {$type}.\n"
	               . ( $data ? "Dữ liệu tham khảo:\n{$data}\n" : '' )
	               . "Cấu trúc: Tóm tắt → Phân tích chi tiết → Kết luận → Khuyến nghị.\n"
	               . "Dùng bảng, số liệu cụ thể nếu có.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","summary":"...","recommendations":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'         => ! empty( $result['content'] ),
		'title'           => $result['title'] ?? '',
		'content'         => $result['content'] ?? '',
		'summary'         => $result['metadata']['summary'] ?? '',
		'recommendations' => $result['metadata']['recommendations'] ?? [],
		'skill_used'      => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'     => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_policy( array $slots ): array {
	$topic   = $slots['topic'] ?? '';
	$org     = $slots['organization'] ?? '';

	$tool_template = "Viết chính sách / quy định tiếng Việt.\n"
	               . "Tổ chức: {$org}\n"
	               . "Cấu trúc: Mục đích → Phạm vi áp dụng → Định nghĩa → Nội dung chính → Thực hiện → Xử lý vi phạm.\n"
	               . "Tone: chính thức, rõ ràng, dễ hiểu.\n"
	               . 'Trả về JSON: {"title":"...","content":"..."}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_sop( array $slots ): array {
	$topic      = $slots['topic'] ?? '';
	$department = $slots['department'] ?? '';

	$tool_template = "Viết SOP (Standard Operating Procedure) tiếng Việt.\n"
	               . "Bộ phận: {$department}\n"
	               . "Cấu trúc: Mục đích → Phạm vi → Trách nhiệm → Quy trình chi tiết (đánh số) → Biểu mẫu → Lưu ý.\n"
	               . "Mỗi bước có: người thực hiện, hành động, output, thời gian.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","steps":[{"step":1,"action":"...","owner":"...","output":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'    => ! empty( $result['content'] ),
		'title'      => $result['title'] ?? '',
		'content'    => $result['content'] ?? '',
		'steps'      => $result['metadata']['steps'] ?? [],
		'skill_used' => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used' => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_job_description( array $slots ): array {
	$position   = $slots['position'] ?? $slots['topic'] ?? '';
	$department = $slots['department'] ?? '';
	$level      = $slots['level'] ?? 'mid';

	$tool_template = "Viết JD (Job Description) tiếng Việt.\n"
	               . "Vị trí: {$position}, cấp: {$level}, bộ phận: {$department}\n"
	               . "Cấu trúc: Giới thiệu → Trách nhiệm → Yêu cầu → Quyền lợi → Cách ứng tuyển.\n"
	               . 'Trả về JSON: {"title":"...","content":"...","requirements":["..."],"benefits":["..."]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $position );
	$result = BizCity_Content_Engine::generate( $prompt );

	return [
		'success'      => ! empty( $result['content'] ),
		'title'        => $result['title'] ?? '',
		'content'      => $result['content'] ?? '',
		'requirements' => $result['metadata']['requirements'] ?? [],
		'benefits'     => $result['metadata']['benefits'] ?? [],
		'skill_used'   => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'  => $result['tokens_used'] ?? 0,
	];
}

function bizcity_atomic_generate_meeting_notes( array $slots ): array {
	$topic        = $slots['topic'] ?? '';
	$participants = $slots['participants'] ?? '';
	$transcript   = $slots['transcript'] ?? '';

	$tool_template = "Tóm tắt biên bản họp tiếng Việt.\n"
	               . "Người tham dự: {$participants}\n"
	               . ( $transcript ? "Nội dung thảo luận:\n{$transcript}\n" : '' )
	               . "Cấu trúc: Thông tin chung → Nội dung thảo luận → Quyết định → Action items (ai, làm gì, deadline).\n"
	               . 'Trả về JSON: {"title":"...","content":"...","decisions":["..."],"action_items":[{"who":"...","what":"...","deadline":"..."}]}';

	$prompt = BizCity_Content_Engine::build_skill_prompt( $slots, $tool_template, $topic );
	$result = BizCity_Content_Engine::generate( $prompt, [ 'max_tokens' => 4096 ] );

	return [
		'success'      => ! empty( $result['content'] ),
		'title'        => $result['title'] ?? '',
		'content'      => $result['content'] ?? '',
		'decisions'    => $result['metadata']['decisions'] ?? [],
		'action_items' => $result['metadata']['action_items'] ?? [],
		'skill_used'   => $slots['_meta']['_skill']['title'] ?? 'none',
		'tokens_used'  => $result['tokens_used'] ?? 0,
	];
}
