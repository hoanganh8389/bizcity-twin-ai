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
 * Google Workspace Tool Group — Bootstrap
 *
 * Registers atomic workspace tools for Google Docs, Sheets, Slides, Drive.
 *
 * HARD RULES:
 *   - tool_type = 'workspace'  → workspace mutation tools (NOT content generators)
 *   - accepts_skill = false    → these tools STORE/RENDER, not generate
 *   - Composite workflows chain: generate_* (atomic, skill-aware) → g*_* (workspace, no skill)
 *
 * Requires: bizgpt-tool-google plugin (OAuth + BZGoogle_Google_Service)
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ──────────────────────────────────────────────────────────────
 * Load tool implementations
 * ────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/tools/gdoc.php';
require_once __DIR__ . '/tools/gsheet.php';
require_once __DIR__ . '/tools/gslide.php';
require_once __DIR__ . '/tools/gdrive.php';
require_once __DIR__ . '/tools/composite.php';

/* ──────────────────────────────────────────────────────────────
 * Register workspace tools — priority 27 (after distribution 26)
 * ────────────────────────────────────────────────────────────── */
add_action( 'init', function () {

	if ( ! class_exists( 'BZGoogle_Google_Service' ) || ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return; // bizgpt-tool-google or intent tools not active
	}

	$tools = BizCity_Intent_Tools::instance();

	/* ════════════════════════════════════════════════════════════════
	 * GOOGLE DOCS — atomic workspace mutations
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'gdoc_create', [
		'description'   => 'Tạo Google Doc mới (trả về doc_id + url)',
		'input_fields'  => [
			'title'   => [ 'required' => true,  'type' => 'text' ],
			'content' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_create' );

	$tools->register( 'gdoc_write', [
		'description'   => 'Ghi nội dung vào Google Doc (ghi đè toàn bộ body)',
		'input_fields'  => [
			'document_id' => [ 'required' => true, 'type' => 'text' ],
			'content'     => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_write' );

	$tools->register( 'gdoc_append', [
		'description'   => 'Thêm nội dung vào cuối Google Doc',
		'input_fields'  => [
			'document_id' => [ 'required' => true, 'type' => 'text' ],
			'content'     => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_append' );

	$tools->register( 'gdoc_replace', [
		'description'   => 'Tìm và thay thế text trong Google Doc',
		'input_fields'  => [
			'document_id' => [ 'required' => true,  'type' => 'text' ],
			'find_text'   => [ 'required' => true,  'type' => 'text' ],
			'replace_text' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_replace' );

	$tools->register( 'gdoc_read', [
		'description'   => 'Đọc nội dung Google Doc (plaintext)',
		'input_fields'  => [
			'document_id' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gdoc_read' );

	$tools->register( 'gdoc_summarize', [
		'description'   => 'Tóm tắt nội dung Google Doc bằng AI',
		'input_fields'  => [
			'document_id' => [ 'required' => true,  'type' => 'text' ],
			'max_words'   => [ 'required' => false, 'type' => 'number', 'default' => 200 ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gdoc_summarize' );

	$tools->register( 'gdoc_extract_sections', [
		'description'   => 'Trích xuất danh sách headings/sections từ Google Doc',
		'input_fields'  => [
			'document_id' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gdoc_extract_sections' );

	$tools->register( 'gdoc_search', [
		'description'   => 'Tìm kiếm text trong Google Doc',
		'input_fields'  => [
			'document_id' => [ 'required' => true, 'type' => 'text' ],
			'query'       => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gdoc_search' );

	$tools->register( 'gdoc_insert_heading', [
		'description'   => 'Chèn heading vào Google Doc tại vị trí chỉ định',
		'input_fields'  => [
			'document_id' => [ 'required' => true,  'type' => 'text' ],
			'text'        => [ 'required' => true,  'type' => 'text' ],
			'level'       => [ 'required' => false, 'type' => 'number', 'default' => 1 ],
			'index'       => [ 'required' => false, 'type' => 'number', 'default' => 1 ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_insert_heading' );

	$tools->register( 'gdoc_insert_table', [
		'description'   => 'Chèn bảng vào Google Doc',
		'input_fields'  => [
			'document_id' => [ 'required' => true,  'type' => 'text' ],
			'rows'        => [ 'required' => true,  'type' => 'number' ],
			'columns'     => [ 'required' => true,  'type' => 'number' ],
			'data'        => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_insert_table' );

	$tools->register( 'gdoc_format_text', [
		'description'   => 'Format text trong Google Doc (bold, italic, font size, color)',
		'input_fields'  => [
			'document_id' => [ 'required' => true,  'type' => 'text' ],
			'start_index' => [ 'required' => true,  'type' => 'number' ],
			'end_index'   => [ 'required' => true,  'type' => 'number' ],
			'format'      => [ 'required' => true,  'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdoc_format_text' );

	/* ════════════════════════════════════════════════════════════════
	 * GOOGLE SHEETS — atomic workspace mutations
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'gsheet_create', [
		'description'   => 'Tạo Google Spreadsheet mới',
		'input_fields'  => [
			'title'        => [ 'required' => true,  'type' => 'text' ],
			'sheet_titles' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_create' );

	$tools->register( 'gsheet_add_sheet', [
		'description'   => 'Thêm sheet tab mới vào spreadsheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'title'          => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_add_sheet' );

	$tools->register( 'gsheet_delete_sheet', [
		'description'   => 'Xóa sheet tab khỏi spreadsheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'sheet_id'       => [ 'required' => true, 'type' => 'number' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_delete_sheet' );

	$tools->register( 'gsheet_append_row', [
		'description'   => 'Thêm hàng mới vào cuối sheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
			'values'         => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_append_row' );

	$tools->register( 'gsheet_update_cell', [
		'description'   => 'Cập nhật giá trị ô/range trong sheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'range'          => [ 'required' => true, 'type' => 'text' ],
			'values'         => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_update_cell' );

	$tools->register( 'gsheet_bulk_write', [
		'description'   => 'Ghi nhiều hàng dữ liệu vào sheet (batch)',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
			'data'           => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_bulk_write' );

	$tools->register( 'gsheet_read', [
		'description'   => 'Đọc dữ liệu từ range trong sheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true, 'type' => 'text' ],
			'range'          => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gsheet_read' );

	$tools->register( 'gsheet_query', [
		'description'   => 'Truy vấn dữ liệu sheet theo điều kiện (AI-powered filter)',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true,  'type' => 'text' ],
			'query'          => [ 'required' => true,  'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gsheet_query' );

	$tools->register( 'gsheet_filter', [
		'description'   => 'Lọc hàng theo điều kiện column = value',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true,  'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
			'column'         => [ 'required' => true,  'type' => 'text' ],
			'value'          => [ 'required' => true,  'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gsheet_filter' );

	$tools->register( 'gsheet_analyze_data', [
		'description'   => 'Phân tích dữ liệu sheet bằng AI (insights, trends, anomalies)',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true,  'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
			'goal'           => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_analyze_data' );

	$tools->register( 'gsheet_generate_formula', [
		'description'   => 'AI sinh công thức Google Sheets từ mô tả tự nhiên',
		'input_fields'  => [
			'description' => [ 'required' => true, 'type' => 'text' ],
			'context'     => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_generate_formula' );

	$tools->register( 'gsheet_generate_report', [
		'description'   => 'AI tạo báo cáo tổng hợp từ dữ liệu sheet',
		'input_fields'  => [
			'spreadsheet_id' => [ 'required' => true,  'type' => 'text' ],
			'range'          => [ 'required' => false, 'type' => 'text', 'default' => 'Sheet1' ],
			'report_type'    => [ 'required' => false, 'type' => 'choice', 'options' => 'summary,detailed,executive' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gsheet_generate_report' );

	/* ════════════════════════════════════════════════════════════════
	 * GOOGLE SLIDES — atomic workspace mutations
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'gslide_create', [
		'description'   => 'Tạo Google Slides presentation mới',
		'input_fields'  => [
			'title' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gslide_create' );

	$tools->register( 'gslide_add_slide', [
		'description'   => 'Thêm slide mới với nội dung (text/image)',
		'input_fields'  => [
			'presentation_id' => [ 'required' => true,  'type' => 'text' ],
			'title'           => [ 'required' => false, 'type' => 'text' ],
			'body'            => [ 'required' => false, 'type' => 'text' ],
			'layout'          => [ 'required' => false, 'type' => 'choice', 'options' => 'BLANK,TITLE,TITLE_AND_BODY,TITLE_AND_TWO_COLUMNS' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gslide_add_slide' );

	$tools->register( 'gslide_insert_text', [
		'description'   => 'Chèn text vào slide tại vị trí chỉ định',
		'input_fields'  => [
			'presentation_id' => [ 'required' => true, 'type' => 'text' ],
			'slide_id'        => [ 'required' => true, 'type' => 'text' ],
			'text'            => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gslide_insert_text' );

	$tools->register( 'gslide_insert_image', [
		'description'   => 'Chèn hình ảnh vào slide',
		'input_fields'  => [
			'presentation_id' => [ 'required' => true, 'type' => 'text' ],
			'slide_id'        => [ 'required' => true, 'type' => 'text' ],
			'image_url'       => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gslide_insert_image' );

	$tools->register( 'gslide_insert_chart', [
		'description'   => 'Chèn chart từ Google Sheets vào slide',
		'input_fields'  => [
			'presentation_id' => [ 'required' => true, 'type' => 'text' ],
			'slide_id'        => [ 'required' => true, 'type' => 'text' ],
			'spreadsheet_id'  => [ 'required' => true, 'type' => 'text' ],
			'chart_id'        => [ 'required' => true, 'type' => 'number' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gslide_insert_chart' );

	/* ════════════════════════════════════════════════════════════════
	 * GOOGLE DRIVE — file operations
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'gdrive_search', [
		'description'   => 'Tìm kiếm file trong Google Drive',
		'input_fields'  => [
			'query'       => [ 'required' => true,  'type' => 'text' ],
			'max_results' => [ 'required' => false, 'type' => 'number', 'default' => 20 ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
		'auto_execute'  => true,
	], 'bizcity_ws_gdrive_search' );

	$tools->register( 'gdrive_download', [
		'description'   => 'Tải file từ Google Drive',
		'input_fields'  => [
			'file_id' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdrive_download' );

	$tools->register( 'gdrive_delete', [
		'description'   => 'Xóa file trong Google Drive',
		'input_fields'  => [
			'file_id' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdrive_delete' );

	$tools->register( 'gdrive_convert_doc_to_pdf', [
		'description'   => 'Xuất Google Doc/Sheet/Slide thành PDF',
		'input_fields'  => [
			'file_id' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdrive_convert_doc_to_pdf' );

	$tools->register( 'gdrive_export', [
		'description'   => 'Xuất Google Workspace file sang format khác (CSV, DOCX, PPTX, ...)',
		'input_fields'  => [
			'file_id'   => [ 'required' => true,  'type' => 'text' ],
			'mime_type' => [ 'required' => true,  'type' => 'text' ],
		],
		'tool_type'     => 'workspace',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_gdrive_export' );

	/* ════════════════════════════════════════════════════════════════
	 * COMPOSITE TOOLS — chain atomic content → workspace save
	 * (tool_type = 'composite', accepts_skill = false)
	 * The upstream atomic tool has skill injection, NOT the composite.
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'proposal_to_gdoc', [
		'description'   => 'Tạo proposal → lưu vào Google Docs',
		'input_fields'  => [
			'topic'  => [ 'required' => true,  'type' => 'text' ],
			'client' => [ 'required' => false, 'type' => 'text' ],
			'scope'  => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'composite',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_composite_proposal_to_gdoc' );

	$tools->register( 'report_to_gsheet', [
		'description'   => 'Tạo báo cáo → lưu vào Google Sheets',
		'input_fields'  => [
			'topic'       => [ 'required' => true,  'type' => 'text' ],
			'report_type' => [ 'required' => false, 'type' => 'choice', 'options' => 'summary,analysis,monthly,quarterly' ],
			'data'        => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'composite',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_composite_report_to_gsheet' );

	$tools->register( 'presentation_to_gslide', [
		'description'   => 'Tạo bài thuyết trình → lưu vào Google Slides',
		'input_fields'  => [
			'topic'  => [ 'required' => true,  'type' => 'text' ],
			'slides' => [ 'required' => false, 'type' => 'number', 'default' => 10 ],
			'style'  => [ 'required' => false, 'type' => 'choice', 'options' => 'professional,creative,minimal,data_driven' ],
		],
		'tool_type'     => 'composite',
		'accepts_skill' => false,
		'content_tier'  => 0,
	], 'bizcity_ws_composite_presentation_to_gslide' );

}, 27 ); // priority 27: after distribution (26)
