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
 * Google Sheets — Atomic Workspace Tool Implementations
 *
 * All tools: tool_type='workspace', accepts_skill=false
 * These STORE/READ/MUTATE spreadsheets — they do NOT generate content.
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================
 * gsheet_create — Create new spreadsheet
 * ================================================================ */

function bizcity_ws_gsheet_create( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$title        = sanitize_text_field( $slots['title'] ?? 'Untitled' );
	$sheet_titles = $slots['sheet_titles'] ?? '';

	$args = [ 'title' => $title ];
	if ( ! empty( $sheet_titles ) ) {
		$args['sheet_titles'] = is_array( $sheet_titles )
			? $sheet_titles
			: array_map( 'trim', explode( ',', $sheet_titles ) );
	}

	$result = \BZGoogle_Google_Service::sheets_create( $ctx['blog_id'], $ctx['user_id'], $args );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => "Đã tạo Google Sheets: {$title}",
		'data'    => [ 'type' => 'gsheet', 'id' => $result['spreadsheetId'], 'url' => $result['spreadsheetUrl'] ?? 'https://docs.google.com/spreadsheets/d/' . $result['spreadsheetId'] ],
	];
}

/* ================================================================
 * gsheet_add_sheet — Add new sheet tab
 * ================================================================ */

function bizcity_ws_gsheet_add_sheet( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$title = sanitize_text_field( $slots['title'] ?? 'Sheet' );

	if ( empty( $ss_id ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id.' ];
	}

	$result = \BZGoogle_Google_Service::sheets_batch_update( $ctx['blog_id'], $ctx['user_id'], $ss_id, [
		[ 'addSheet' => [ 'properties' => [ 'title' => $title ] ] ],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	$new_sheet_id = $result['replies'][0]['addSheet']['properties']['sheetId'] ?? null;

	return [
		'success' => true,
		'message' => "Đã thêm sheet tab: {$title}",
		'data'    => [ 'type' => 'gsheet_tab', 'id' => $new_sheet_id ],
	];
}

/* ================================================================
 * gsheet_delete_sheet — Delete a sheet tab
 * ================================================================ */

function bizcity_ws_gsheet_delete_sheet( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id    = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$sheet_id = absint( $slots['sheet_id'] ?? 0 );

	if ( empty( $ss_id ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id.' ];
	}

	$result = \BZGoogle_Google_Service::sheets_batch_update( $ctx['blog_id'], $ctx['user_id'], $ss_id, [
		[ 'deleteSheet' => [ 'sheetId' => $sheet_id ] ],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => 'Đã xóa sheet tab.', 'data' => [ 'type' => 'gsheet' ] ];
}

/* ================================================================
 * gsheet_append_row — Append row(s) to end of sheet
 * ================================================================ */

function bizcity_ws_gsheet_append_row( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id  = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$range  = sanitize_text_field( $slots['range'] ?? 'Sheet1' );
	$values = _bizcity_ws_parse_sheet_values( $slots['values'] ?? '' );

	if ( empty( $ss_id ) || empty( $values ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id or values.' ];
	}

	$result = \BZGoogle_Google_Service::sheets_append(
		$ctx['blog_id'], $ctx['user_id'], $ss_id, $range, $values
	);

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã thêm ' . count( $values ) . ' hàng vào sheet.',
		'data'    => [ 'type' => 'gsheet', 'rows_added' => count( $values ) ],
	];
}

/* ================================================================
 * gsheet_update_cell — Update cell range
 * ================================================================ */

function bizcity_ws_gsheet_update_cell( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id  = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$range  = sanitize_text_field( $slots['range'] ?? '' );
	$values = _bizcity_ws_parse_sheet_values( $slots['values'] ?? '' );

	if ( empty( $ss_id ) || empty( $range ) || empty( $values ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id, range, or values.' ];
	}

	$result = \BZGoogle_Google_Service::sheets_update(
		$ctx['blog_id'], $ctx['user_id'], $ss_id, $range, $values
	);

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => "Đã cập nhật range {$range}.", 'data' => [ 'type' => 'gsheet' ] ];
}

/* ================================================================
 * gsheet_bulk_write — Write many rows (batch)
 * ================================================================ */

function bizcity_ws_gsheet_bulk_write( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$range = sanitize_text_field( $slots['range'] ?? 'Sheet1' );
	$data  = _bizcity_ws_parse_sheet_values( $slots['data'] ?? '' );

	if ( empty( $ss_id ) || empty( $data ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id or data.' ];
	}

	// Start from A1 if bare range
	$full_range = ( strpos( $range, '!' ) === false && strpos( $range, ':' ) === false )
		? $range . '!A1'
		: $range;

	$result = \BZGoogle_Google_Service::sheets_update(
		$ctx['blog_id'], $ctx['user_id'], $ss_id, $full_range, $data
	);

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã ghi ' . count( $data ) . ' hàng vào sheet.',
		'data'    => [ 'type' => 'gsheet', 'rows_written' => count( $data ) ],
	];
}

/* ================================================================
 * gsheet_read — Read cell values from range
 * ================================================================ */

function bizcity_ws_gsheet_read( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'sheets' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$ss_id = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$range = sanitize_text_field( $slots['range'] ?? 'Sheet1' );

	if ( empty( $ss_id ) ) {
		return [ 'success' => false, 'error' => 'Missing spreadsheet_id.' ];
	}

	$result = \BZGoogle_Google_Service::sheets_read( $ctx['blog_id'], $ctx['user_id'], $ss_id, $range );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	$values = $result['values'] ?? [];

	return [
		'success' => true,
		'message' => 'Đọc ' . count( $values ) . ' hàng từ range ' . ( $result['range'] ?? $range ) . '.',
		'data'    => [ 'type' => 'gsheet', 'range' => $result['range'] ?? $range, 'rows' => count( $values ), 'values' => $values ],
	];
}

/* ================================================================
 * gsheet_query — AI-powered query on sheet data
 * ================================================================ */

function bizcity_ws_gsheet_query( array $slots ): array {
	// First, read the data
	$read = bizcity_ws_gsheet_read( $slots );
	if ( ! $read['success'] ) return $read;

	$query = $slots['query'] ?? '';
	if ( empty( $query ) ) {
		return [ 'success' => false, 'error' => 'Missing query.' ];
	}

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [ 'success' => false, 'error' => 'LLM not available.' ];
	}

	// Serialize data for AI
	$rows_text = '';
	foreach ( $read['data']['values'] as $i => $row ) {
		$rows_text .= 'Row ' . ( $i + 1 ) . ': ' . implode( ' | ', $row ) . "\n";
		if ( $i >= 100 ) {
			$rows_text .= "...(truncated)\n";
			break;
		}
	}

	$result = bizcity_llm_chat( [
		[ 'role' => 'system', 'content' => 'Bạn là data analyst. Trả lời câu hỏi dựa trên dữ liệu bảng tính. Trả về JSON: {"answer":"...","matching_rows":[[row1],[row2],...]}' ],
		[ 'role' => 'user',   'content' => "Dữ liệu:\n{$rows_text}\n\nCâu hỏi: {$query}" ],
	], [ 'max_tokens' => 2000, 'temperature' => 0.2 ] );

	if ( empty( $result['success'] ) ) {
		return [ 'success' => false, 'error' => $result['error'] ?? 'LLM failed.' ];
	}

	return [
		'success' => true,
		'message' => 'Kết quả query trên sheet.',
		'data'    => [ 'type' => 'gsheet_query', 'query' => $query, 'result' => $result['message'] ],
	];
}

/* ================================================================
 * gsheet_filter — Filter rows by column value
 * ================================================================ */

function bizcity_ws_gsheet_filter( array $slots ): array {
	$read = bizcity_ws_gsheet_read( $slots );
	if ( ! $read['success'] ) return $read;

	$column = $slots['column'] ?? '';
	$value  = $slots['value'] ?? '';
	$data   = $read['data']['values'];

	if ( empty( $column ) || empty( $data ) ) {
		return [ 'success' => false, 'error' => 'Missing column or no data.' ];
	}

	// Find column index from header row
	$headers  = $data[0] ?? [];
	$col_idx  = array_search( $column, $headers, true );

	if ( $col_idx === false ) {
		// Try case-insensitive
		foreach ( $headers as $i => $h ) {
			if ( mb_strtolower( trim( $h ) ) === mb_strtolower( trim( $column ) ) ) {
				$col_idx = $i;
				break;
			}
		}
	}

	if ( $col_idx === false ) {
		return [ 'success' => false, 'error' => "Column '{$column}' not found. Headers: " . implode( ', ', $headers ) ];
	}

	$matched = [ $headers ];
	$v_lower = mb_strtolower( trim( $value ) );
	foreach ( array_slice( $data, 1 ) as $row ) {
		$cell = mb_strtolower( trim( $row[ $col_idx ] ?? '' ) );
		if ( $cell === $v_lower || strpos( $cell, $v_lower ) !== false ) {
			$matched[] = $row;
		}
	}

	return [
		'success' => true,
		'message' => 'Lọc ' . ( count( $matched ) - 1 ) . ' hàng theo ' . $column . ' = ' . $value,
		'data'    => [ 'type' => 'gsheet_filter', 'column' => $column, 'value' => $value, 'matched_rows' => count( $matched ) - 1, 'rows' => $matched ],
	];
}

/* ================================================================
 * gsheet_analyze_data — AI analysis of sheet data
 * ================================================================ */

function bizcity_ws_gsheet_analyze_data( array $slots ): array {
	$read = bizcity_ws_gsheet_read( $slots );
	if ( ! $read['success'] ) return $read;

	$goal = $slots['goal'] ?? 'Phân tích tổng quan dữ liệu, tìm insights, xu hướng, anomalies.';

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [ 'success' => false, 'error' => 'LLM not available.' ];
	}

	$rows_text = '';
	foreach ( $read['data']['values'] as $i => $row ) {
		$rows_text .= implode( ' | ', $row ) . "\n";
		if ( $i >= 200 ) {
			$rows_text .= "...(truncated at 200 rows)\n";
			break;
		}
	}

	$result = bizcity_llm_chat( [
		[ 'role' => 'system', 'content' => "Bạn là data analyst. Phân tích dữ liệu bảng tính và trả lời tiếng Việt, rõ ràng, có số liệu cụ thể." ],
		[ 'role' => 'user',   'content' => "Dữ liệu ({$read['data']['rows']} hàng):\n{$rows_text}\n\nMục tiêu phân tích: {$goal}" ],
	], [ 'max_tokens' => 3000 ] );

	if ( empty( $result['success'] ) ) {
		return [ 'success' => false, 'error' => $result['error'] ?? 'LLM failed.' ];
	}

	return [
		'success' => true,
		'message' => 'Phân tích dữ liệu ' . $read['data']['rows'] . ' hàng.',
		'data'    => [ 'type' => 'gsheet_analysis', 'analysis' => $result['message'], 'rows_analyzed' => $read['data']['rows'] ],
	];
}

/* ================================================================
 * gsheet_generate_formula — AI generates Sheets formula
 * ================================================================ */

function bizcity_ws_gsheet_generate_formula( array $slots ): array {
	$description = $slots['description'] ?? '';
	$context     = $slots['context'] ?? '';

	if ( empty( $description ) ) {
		return [ 'success' => false, 'error' => 'Missing description.' ];
	}

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [ 'success' => false, 'error' => 'LLM not available.' ];
	}

	$prompt = "Yêu cầu: {$description}";
	if ( $context ) {
		$prompt .= "\nContext bảng tính: {$context}";
	}

	$result = bizcity_llm_chat( [
		[ 'role' => 'system', 'content' => 'Bạn là chuyên gia Google Sheets. Chỉ trả về JSON: {"formula":"=...","explanation":"giải thích ngắn"}. Không giải thích thêm.' ],
		[ 'role' => 'user',   'content' => $prompt ],
	], [ 'max_tokens' => 500, 'temperature' => 0.1 ] );

	if ( empty( $result['success'] ) ) {
		return [ 'success' => false, 'error' => $result['error'] ?? 'LLM failed.' ];
	}

	return [
		'success' => true,
		'message' => 'Công thức Google Sheets.',
		'data'    => [ 'type' => 'gsheet_formula', 'result' => $result['message'] ],
	];
}

/* ================================================================
 * gsheet_generate_report — AI generates summary report from data
 * ================================================================ */

function bizcity_ws_gsheet_generate_report( array $slots ): array {
	$read = bizcity_ws_gsheet_read( $slots );
	if ( ! $read['success'] ) return $read;

	$report_type = sanitize_text_field( $slots['report_type'] ?? 'summary' );

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [ 'success' => false, 'error' => 'LLM not available.' ];
	}

	$rows_text = '';
	foreach ( $read['data']['values'] as $i => $row ) {
		$rows_text .= implode( ' | ', $row ) . "\n";
		if ( $i >= 200 ) break;
	}

	$result = bizcity_llm_chat( [
		[ 'role' => 'system', 'content' => "Tạo báo cáo dạng {$report_type} từ dữ liệu bảng tính. Tiếng Việt, có tiêu đề, tóm tắt, phân tích, kết luận." ],
		[ 'role' => 'user',   'content' => "Dữ liệu ({$read['data']['rows']} hàng):\n{$rows_text}" ],
	], [ 'max_tokens' => 4000 ] );

	if ( empty( $result['success'] ) ) {
		return [ 'success' => false, 'error' => $result['error'] ?? 'LLM failed.' ];
	}

	return [
		'success' => true,
		'message' => 'Báo cáo dạng ' . $report_type . '.',
		'data'    => [ 'type' => 'gsheet_report', 'report_type' => $report_type, 'report' => $result['message'] ],
	];
}

/* ================================================================
 * INTERNAL HELPER — parse values from string/JSON/array
 * ================================================================ */

function _bizcity_ws_parse_sheet_values( $raw ): array {
	if ( is_array( $raw ) ) {
		// Already 2D array
		if ( ! empty( $raw[0] ) && is_array( $raw[0] ) ) return $raw;
		// 1D array → wrap as single row
		return [ $raw ];
	}

	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
		// Try JSON decode
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded[0] ) && is_array( $decoded[0] ) ) return $decoded;
			return [ $decoded ];
		}
		// CSV-like: rows separated by \n, cells by | or ,
		$rows = [];
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;
			$sep  = strpos( $line, '|' ) !== false ? '|' : ',';
			$rows[] = array_map( 'trim', explode( $sep, $line ) );
		}
		return $rows;
	}

	return [];
}
