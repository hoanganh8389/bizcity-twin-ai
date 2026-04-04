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
 * Google Slides — Atomic Workspace Tool Implementations
 *
 * All tools: tool_type='workspace', accepts_skill=false
 * These CREATE/MUTATE Google Slides presentations — they do NOT generate content.
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================
 * gslide_create — Create new presentation
 * ================================================================ */

function bizcity_ws_gslide_create( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'slides' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$title = sanitize_text_field( $slots['title'] ?? 'Untitled Presentation' );

	$result = \BZGoogle_Google_Service::slides_create( $ctx['blog_id'], $ctx['user_id'], [ 'title' => $title ] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => "Đã tạo Google Slides: {$title}",
		'data'    => [ 'type' => 'gslide', 'id' => $result['presentationId'], 'url' => 'https://docs.google.com/presentation/d/' . $result['presentationId'] ],
	];
}

/* ================================================================
 * gslide_add_slide — Add a new slide
 * ================================================================ */

function bizcity_ws_gslide_add_slide( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'slides' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$pres_id = sanitize_text_field( $slots['presentation_id'] ?? '' );
	$layout  = sanitize_text_field( $slots['layout'] ?? 'BLANK' );

	if ( empty( $pres_id ) ) {
		return [ 'success' => false, 'error' => 'Missing presentation_id.' ];
	}

	// Generate a unique object ID for the new slide
	$slide_object_id = 'slide_' . wp_generate_password( 12, false );

	// Predefined layout mapping
	$predefined = strtoupper( $layout );
	$layout_map = [
		'BLANK'            => 'BLANK',
		'TITLE'            => 'TITLE',
		'TITLE_AND_BODY'   => 'TITLE_AND_BODY',
		'TITLE_ONLY'       => 'TITLE_ONLY',
		'SECTION_HEADER'   => 'SECTION_HEADER',
		'ONE_COLUMN_TEXT'  => 'ONE_COLUMN_TEXT',
		'MAIN_POINT'       => 'MAIN_POINT',
		'BIG_NUMBER'       => 'BIG_NUMBER',
	];

	$predefined_layout = $layout_map[ $predefined ] ?? 'BLANK';

	$result = \BZGoogle_Google_Service::slides_batch_update( $ctx['blog_id'], $ctx['user_id'], $pres_id, [
		[
			'createSlide' => [
				'objectId'             => $slide_object_id,
				'slideLayoutReference' => [ 'predefinedLayout' => $predefined_layout ],
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => "Đã thêm slide ({$predefined_layout}).",
		'data'    => [ 'type' => 'gslide_page', 'id' => $slide_object_id ],
	];
}

/* ================================================================
 * gslide_insert_text — Insert text box into a slide
 * ================================================================ */

function bizcity_ws_gslide_insert_text( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'slides' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$pres_id  = sanitize_text_field( $slots['presentation_id'] ?? '' );
	$slide_id = sanitize_text_field( $slots['slide_id'] ?? $slots['slide_index'] ?? '' );
	$text     = $slots['text'] ?? '';

	if ( empty( $pres_id ) || empty( $slide_id ) || $text === '' ) {
		return [ 'success' => false, 'error' => 'Missing presentation_id, slide_id, or text.' ];
	}

	$box_id = 'textbox_' . wp_generate_password( 12, false );

	// Position defaults (EMU units: 1 inch = 914400 EMU)
	$left   = intval( $slots['left']   ?? 50000 );
	$top    = intval( $slots['top']    ?? 50000 );
	$width  = intval( $slots['width']  ?? 6000000 );
	$height = intval( $slots['height'] ?? 4000000 );

	$requests = [
		[
			'createShape' => [
				'objectId'    => $box_id,
				'shapeType'   => 'TEXT_BOX',
				'elementProperties' => [
					'pageObjectId' => $slide_id,
					'size'         => [
						'width'  => [ 'magnitude' => $width,  'unit' => 'EMU' ],
						'height' => [ 'magnitude' => $height, 'unit' => 'EMU' ],
					],
					'transform'    => [
						'scaleX'     => 1,
						'scaleY'     => 1,
						'translateX' => $left,
						'translateY' => $top,
						'unit'       => 'EMU',
					],
				],
			],
		],
		[
			'insertText' => [
				'objectId'       => $box_id,
				'insertionIndex' => 0,
				'text'           => $text,
			],
		],
	];

	$result = \BZGoogle_Google_Service::slides_batch_update( $ctx['blog_id'], $ctx['user_id'], $pres_id, $requests );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã chèn text box vào slide.',
		'data'    => [ 'type' => 'gslide_textbox', 'id' => $box_id ],
	];
}

/* ================================================================
 * gslide_insert_image — Insert image into a slide
 * ================================================================ */

function bizcity_ws_gslide_insert_image( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'slides' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$pres_id   = sanitize_text_field( $slots['presentation_id'] ?? '' );
	$slide_id  = sanitize_text_field( $slots['slide_id'] ?? $slots['slide_index'] ?? '' );
	$image_url = esc_url_raw( $slots['image_url'] ?? '' );

	if ( empty( $pres_id ) || empty( $slide_id ) || empty( $image_url ) ) {
		return [ 'success' => false, 'error' => 'Missing presentation_id, slide_id, or image_url.' ];
	}

	$img_id = 'img_' . wp_generate_password( 12, false );

	$left   = intval( $slots['left']   ?? 500000 );
	$top    = intval( $slots['top']    ?? 500000 );
	$width  = intval( $slots['width']  ?? 5000000 );
	$height = intval( $slots['height'] ?? 3500000 );

	$result = \BZGoogle_Google_Service::slides_batch_update( $ctx['blog_id'], $ctx['user_id'], $pres_id, [
		[
			'createImage' => [
				'objectId'          => $img_id,
				'url'               => $image_url,
				'elementProperties' => [
					'pageObjectId' => $slide_id,
					'size'         => [
						'width'  => [ 'magnitude' => $width,  'unit' => 'EMU' ],
						'height' => [ 'magnitude' => $height, 'unit' => 'EMU' ],
					],
					'transform'    => [
						'scaleX'     => 1,
						'scaleY'     => 1,
						'translateX' => $left,
						'translateY' => $top,
						'unit'       => 'EMU',
					],
				],
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã chèn hình ảnh vào slide.',
		'data'    => [ 'type' => 'gslide_image', 'id' => $img_id ],
	];
}

/* ================================================================
 * gslide_insert_chart — Insert chart from Sheets into slide
 * ================================================================ */

function bizcity_ws_gslide_insert_chart( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'slides' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$pres_id  = sanitize_text_field( $slots['presentation_id'] ?? '' );
	$slide_id = sanitize_text_field( $slots['slide_id'] ?? $slots['slide_index'] ?? '' );
	$ss_id    = sanitize_text_field( $slots['spreadsheet_id'] ?? '' );
	$chart_id = intval( $slots['chart_id'] ?? 0 );

	if ( empty( $pres_id ) || empty( $slide_id ) || empty( $ss_id ) || ! $chart_id ) {
		return [ 'success' => false, 'error' => 'Missing presentation_id, slide_id, spreadsheet_id, or chart_id.' ];
	}

	$obj_id = 'chart_' . wp_generate_password( 12, false );

	$left   = intval( $slots['left']   ?? 500000 );
	$top    = intval( $slots['top']    ?? 500000 );
	$width  = intval( $slots['width']  ?? 6000000 );
	$height = intval( $slots['height'] ?? 4000000 );

	$result = \BZGoogle_Google_Service::slides_batch_update( $ctx['blog_id'], $ctx['user_id'], $pres_id, [
		[
			'createSheetsChart' => [
				'objectId'       => $obj_id,
				'spreadsheetId'  => $ss_id,
				'chartId'        => $chart_id,
				'linkingMode'    => 'LINKED',
				'elementProperties' => [
					'pageObjectId' => $slide_id,
					'size'         => [
						'width'  => [ 'magnitude' => $width,  'unit' => 'EMU' ],
						'height' => [ 'magnitude' => $height, 'unit' => 'EMU' ],
					],
					'transform'    => [
						'scaleX'     => 1,
						'scaleY'     => 1,
						'translateX' => $left,
						'translateY' => $top,
						'unit'       => 'EMU',
					],
				],
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã chèn chart từ Sheets vào slide.',
		'data'    => [ 'type' => 'gslide_chart', 'id' => $obj_id ],
	];
}
