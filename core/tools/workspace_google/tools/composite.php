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
 * Google Workspace — Composite Tool Implementations
 *
 * Composite = Atomic content tool (skill-aware) → Workspace tool (no skill)
 * These tools orchestrate: generate content → save to Google Workspace.
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ── Helper: start trace for composite tools ──────────────── */

function _bizcity_ws_composite_trace( array $slots, string $tool_name, array $steps ): ?object {
	if ( ! class_exists( 'BizCity_Job_Trace' ) ) {
		return null;
	}
	$session_id = $slots['_trace_session_id'] ?? '';
	return BizCity_Job_Trace::start( $session_id, $tool_name, $steps );
}

/* ================================================================
 * proposal_to_gdoc
 *
 * generate_proposal (atomic, skill) → gdoc_create → gdoc_write
 * ================================================================ */

function bizcity_ws_composite_proposal_to_gdoc( array $slots ): array {
	$trace = _bizcity_ws_composite_trace( $slots, 'proposal_to_gdoc', [
		'T1' => 'Tạo nội dung proposal',
		'T2' => 'Tạo Google Doc',
		'T3' => 'Ghi nội dung vào Doc',
	] );

	// Step 1 — Generate proposal content (skill-aware atomic)
	if ( $trace ) $trace->step( 'T1', 'running' );
	if ( ! function_exists( 'bizcity_atomic_generate_proposal' ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Content tool generate_proposal not available.' );
		return [ 'success' => false, 'error' => 'Content tool generate_proposal not available.' ];
	}

	$gen = bizcity_atomic_generate_proposal( $slots );
	if ( empty( $gen['success'] ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Proposal generation failed.' );
		return [ 'success' => false, 'error' => 'Proposal generation failed.', 'detail' => $gen ];
	}
	if ( $trace ) $trace->step( 'T1', 'done' );

	// Step 2 — Create Google Doc
	if ( $trace ) $trace->step( 'T2', 'running' );
	$doc_title = $gen['title'] ?: ( 'Proposal - ' . ( $slots['topic'] ?? date( 'Y-m-d' ) ) );
	$doc = bizcity_ws_gdoc_create( array_merge( $slots, [ 'title' => $doc_title ] ) );
	if ( empty( $doc['success'] ) ) {
		if ( $trace ) $trace->step( 'T2', 'failed', [], 'Google Doc creation failed.' );
		return [
			'success'  => false,
			'error'    => 'Google Doc creation failed.',
			'detail'   => $doc,
			'proposal' => $gen['content'],
		];
	}
	if ( $trace ) $trace->step( 'T2', 'done', [ 'document_id' => $doc['data']['id'] ] );

	// Step 3 — Write content to doc
	if ( $trace ) $trace->step( 'T3', 'running' );
	$write = bizcity_ws_gdoc_write( array_merge( $slots, [
		'document_id' => $doc['data']['id'],
		'content'     => $gen['content'],
	] ) );
	if ( $trace ) $trace->step( 'T3', ! empty( $write['success'] ) ? 'done' : 'failed' );

	return [
		'success' => ! empty( $write['success'] ),
		'message' => "Đã tạo proposal và lưu vào Google Docs: {$doc_title}",
		'data'    => [
			'type'        => 'gdoc',
			'id'          => $doc['data']['id'],
			'url'         => $doc['data']['url'] ?? '',
			'title'       => $doc_title,
			'skill_used'  => $gen['skill_used'] ?? 'none',
			'tokens_used' => $gen['tokens_used'] ?? 0,
		],
	];
}

/* ================================================================
 * report_to_gsheet
 *
 * generate_report_content (atomic, skill) → gsheet_create → gsheet_bulk_write
 * ================================================================ */

function bizcity_ws_composite_report_to_gsheet( array $slots ): array {
	$trace = _bizcity_ws_composite_trace( $slots, 'report_to_gsheet', [
		'T1' => 'Tạo nội dung report',
		'T2' => 'Tạo Google Sheet',
		'T3' => 'Ghi dữ liệu vào Sheet',
	] );

	// Step 1 — Generate report content (skill-aware atomic)
	if ( $trace ) $trace->step( 'T1', 'running' );
	if ( ! function_exists( 'bizcity_atomic_generate_report_content' ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Content tool generate_report_content not available.' );
		return [ 'success' => false, 'error' => 'Content tool generate_report_content not available.' ];
	}

	$gen = bizcity_atomic_generate_report_content( $slots );
	if ( empty( $gen['success'] ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Report generation failed.' );
		return [ 'success' => false, 'error' => 'Report generation failed.', 'detail' => $gen ];
	}
	if ( $trace ) $trace->step( 'T1', 'done' );

	// Step 2 — Create Google Sheet
	if ( $trace ) $trace->step( 'T2', 'running' );
	$sheet_title = $gen['title'] ?: ( 'Report - ' . ( $slots['topic'] ?? date( 'Y-m-d' ) ) );
	$sheet = bizcity_ws_gsheet_create( array_merge( $slots, [
		'title'        => $sheet_title,
		'sheet_titles' => 'Report,Data',
	] ) );
	if ( empty( $sheet['success'] ) ) {
		if ( $trace ) $trace->step( 'T2', 'failed', [], 'Google Sheet creation failed.' );
		return [
			'success' => false,
			'error'   => 'Google Sheet creation failed.',
			'detail'  => $sheet,
			'report'  => $gen['content'],
		];
	}
	if ( $trace ) $trace->step( 'T2', 'done', [ 'spreadsheet_id' => $sheet['data']['id'] ] );

	// Step 3 — Write report summary to first sheet
	if ( $trace ) $trace->step( 'T3', 'running' );
	$summary_rows = [
		[ 'Báo cáo', $sheet_title ],
		[ 'Ngày tạo', date( 'Y-m-d H:i' ) ],
		[ '' ],
		[ 'Tóm tắt' ],
		[ $gen['summary'] ?? '' ],
		[ '' ],
		[ 'Nội dung chi tiết' ],
	];

	// Split content into rows (line by line)
	$content_lines = explode( "\n", $gen['content'] ?? '' );
	foreach ( $content_lines as $line ) {
		$summary_rows[] = [ $line ];
	}

	// Add recommendations
	$recs = $gen['recommendations'] ?? [];
	if ( ! empty( $recs ) ) {
		$summary_rows[] = [ '' ];
		$summary_rows[] = [ 'Khuyến nghị' ];
		foreach ( $recs as $i => $rec ) {
			$summary_rows[] = [ ( $i + 1 ) . '. ' . $rec ];
		}
	}

	$write = bizcity_ws_gsheet_bulk_write( array_merge( $slots, [
		'spreadsheet_id' => $sheet['data']['id'],
		'range'          => 'Report',
		'data'           => $summary_rows,
	] ) );
	if ( $trace ) $trace->step( 'T3', ! empty( $write['success'] ) ? 'done' : 'failed' );

	return [
		'success' => ! empty( $write['success'] ),
		'message' => "Đã tạo report và lưu vào Google Sheets: {$sheet_title}",
		'data'    => [
			'type'        => 'gsheet',
			'id'          => $sheet['data']['id'],
			'url'         => $sheet['data']['url'] ?? '',
			'title'       => $sheet_title,
			'skill_used'  => $gen['skill_used'] ?? 'none',
			'tokens_used' => $gen['tokens_used'] ?? 0,
		],
	];
}

/* ================================================================
 * presentation_to_gslide
 *
 * generate_presentation (atomic, skill) → gslide_create → loop(add_slide + insert_text)
 * ================================================================ */

function bizcity_ws_composite_presentation_to_gslide( array $slots ): array {
	$trace = _bizcity_ws_composite_trace( $slots, 'presentation_to_gslide', [
		'T1' => 'Tạo nội dung presentation',
		'T2' => 'Tạo Google Slides',
		'T3' => 'Thêm slides và nội dung',
	] );

	// Step 1 — Generate presentation content (skill-aware atomic)
	if ( $trace ) $trace->step( 'T1', 'running' );
	if ( ! function_exists( 'bizcity_atomic_generate_presentation' ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Content tool generate_presentation not available.' );
		return [ 'success' => false, 'error' => 'Content tool generate_presentation not available.' ];
	}

	$gen = bizcity_atomic_generate_presentation( $slots );
	if ( empty( $gen['success'] ) ) {
		if ( $trace ) $trace->step( 'T1', 'failed', [], 'Presentation generation failed.' );
		return [ 'success' => false, 'error' => 'Presentation generation failed.', 'detail' => $gen ];
	}
	if ( $trace ) $trace->step( 'T1', 'done' );

	// Step 2 — Create Google Slides presentation
	if ( $trace ) $trace->step( 'T2', 'running' );
	$pres_title = $gen['title'] ?: ( 'Presentation - ' . ( $slots['topic'] ?? date( 'Y-m-d' ) ) );
	$pres = bizcity_ws_gslide_create( array_merge( $slots, [ 'title' => $pres_title ] ) );
	if ( empty( $pres['success'] ) ) {
		if ( $trace ) $trace->step( 'T2', 'failed', [], 'Google Slides creation failed.' );
		return [
			'success' => false,
			'error'   => 'Google Slides creation failed.',
			'detail'  => $pres,
			'content' => $gen['content'],
		];
	}
	if ( $trace ) $trace->step( 'T2', 'done', [ 'presentation_id' => $pres['data']['id'] ] );

	$pres_id = $pres['data']['id'];
	$slides_data = $gen['slides'] ?? [];

	// Try parsing from JSON content if metadata slides not available
	if ( empty( $slides_data ) && ! empty( $gen['content'] ) ) {
		$decoded = json_decode( $gen['content'], true );
		if ( is_array( $decoded ) && isset( $decoded['slides'] ) ) {
			$slides_data = $decoded['slides'];
		}
	}

	$created_slides = [];

	// Step 3 — Create each slide + insert content
	if ( $trace ) $trace->step( 'T3', 'running' );
	foreach ( $slides_data as $i => $slide_info ) {
		$slide_title  = $slide_info['title']         ?? 'Slide ' . ( $i + 1 );
		$bullets      = $slide_info['bullets']        ?? [];
		$notes        = $slide_info['speaker_notes']  ?? '';

		// Choose layout based on content
		$layout = ( $i === 0 ) ? 'TITLE' : ( ! empty( $bullets ) ? 'TITLE_AND_BODY' : 'BLANK' );

		// Add slide
		$add = bizcity_ws_gslide_add_slide( array_merge( $slots, [
			'presentation_id' => $pres_id,
			'layout'          => $layout,
		] ) );

		if ( empty( $add['success'] ) ) continue;

		$slide_id = $add['data']['id'];

		// Build text content for the slide
		$text_parts = [ $slide_title ];
		if ( ! empty( $bullets ) ) {
			$text_parts[] = '';
			foreach ( $bullets as $b ) {
				$text_parts[] = '• ' . $b;
			}
		}
		if ( ! empty( $notes ) ) {
			$text_parts[] = '';
			$text_parts[] = '[Notes: ' . $notes . ']';
		}

		$full_text = implode( "\n", $text_parts );

		// Insert text into slide
		bizcity_ws_gslide_insert_text( array_merge( $slots, [
			'presentation_id' => $pres_id,
			'slide_id'        => $slide_id,
			'text'            => $full_text,
		] ) );

		$created_slides[] = [
			'slide_id' => $slide_id,
			'title'    => $slide_title,
		];
	}
	if ( $trace ) $trace->step( 'T3', 'done', [ 'slides_created' => count( $created_slides ) ] );

	return [
		'success' => true,
		'message' => "Đã tạo presentation ({$pres_title}) với " . count( $created_slides ) . " slides.",
		'data'    => [
			'type'           => 'gslide',
			'id'             => $pres_id,
			'url'            => $pres['data']['url'] ?? '',
			'title'          => $pres_title,
			'slides_created' => count( $created_slides ),
			'slides'         => $created_slides,
			'skill_used'     => $gen['skill_used'] ?? 'none',
			'tokens_used'    => $gen['tokens_used'] ?? 0,
		],
	];
}
