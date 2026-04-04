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
 * Google Drive — Atomic Workspace Tool Implementations
 *
 * All tools: tool_type='workspace', accepts_skill=false
 * These SEARCH/DOWNLOAD/DELETE/EXPORT Drive files — they do NOT generate content.
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================
 * gdrive_search — Search files in Google Drive
 * ================================================================ */

function bizcity_ws_gdrive_search( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'drive' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$keyword     = sanitize_text_field( $slots['query'] ?? $slots['keyword'] ?? '' );
	$mime_type   = sanitize_text_field( $slots['mime_type'] ?? '' );
	$max_results = absint( $slots['max_results'] ?? 20 );

	if ( $max_results < 1 ) $max_results = 20;
	if ( $max_results > 100 ) $max_results = 100;

	// Build Drive query
	$parts = [ 'trashed = false' ];
	if ( $keyword !== '' ) {
		$escaped = str_replace( "'", "\\'", $keyword );
		$parts[] = "fullText contains '{$escaped}'";
	}
	if ( $mime_type !== '' ) {
		$parts[] = "mimeType = '{$mime_type}'";
	}

	$query = implode( ' and ', $parts );

	$result = \BZGoogle_Google_Service::drive_search( $ctx['blog_id'], $ctx['user_id'], $query, $max_results );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	$files = $result['files'] ?? [];
	$items = [];
	foreach ( $files as $f ) {
		$items[] = [
			'id'        => $f['id'],
			'name'      => $f['name'],
			'mimeType'  => $f['mimeType'] ?? '',
			'size'      => $f['size'] ?? null,
			'modifiedTime' => $f['modifiedTime'] ?? '',
		];
	}

	return [
		'success' => true,
		'message' => 'Tìm thấy ' . count( $items ) . ' file trên Drive.',
		'data'    => [ 'type' => 'gdrive_search', 'count' => count( $items ), 'files' => $items ],
	];
}

/* ================================================================
 * gdrive_download — Download file content (text-based)
 * ================================================================ */

function bizcity_ws_gdrive_download( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'drive' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$file_id = sanitize_text_field( $slots['file_id'] ?? '' );

	if ( empty( $file_id ) ) {
		return [ 'success' => false, 'error' => 'Missing file_id.' ];
	}

	// First get file metadata to check type
	$meta = \BZGoogle_Google_Service::drive_get( $ctx['blog_id'], $ctx['user_id'], $file_id );

	if ( is_wp_error( $meta ) ) {
		return [ 'success' => false, 'error' => $meta->get_error_message() ];
	}

	$mime = $meta['mimeType'] ?? '';
	$name = $meta['name'] ?? $file_id;

	// Google Workspace files need export, others can download directly
	$export_map = [
		'application/vnd.google-apps.document'     => 'text/plain',
		'application/vnd.google-apps.spreadsheet'  => 'text/csv',
		'application/vnd.google-apps.presentation' => 'text/plain',
	];

	if ( isset( $export_map[ $mime ] ) ) {
		$content = \BZGoogle_Google_Service::drive_export( $ctx['blog_id'], $ctx['user_id'], $file_id, $export_map[ $mime ] );
	} else {
		$content = \BZGoogle_Google_Service::drive_download( $ctx['blog_id'], $ctx['user_id'], $file_id );
	}

	if ( is_wp_error( $content ) ) {
		return [ 'success' => false, 'error' => $content->get_error_message() ];
	}

	// Truncate large content for chat context
	$text = is_string( $content ) ? $content : wp_json_encode( $content );
	if ( mb_strlen( $text ) > 50000 ) {
		$text = mb_substr( $text, 0, 50000 ) . "\n...(truncated)";
	}

	return [
		'success' => true,
		'message' => 'Đã tải file: ' . $name,
		'data'    => [ 'type' => 'gdrive', 'id' => $file_id, 'name' => $name, 'mimeType' => $mime, 'content' => $text ],
	];
}

/* ================================================================
 * gdrive_delete — Delete a file from Drive
 * ================================================================ */

function bizcity_ws_gdrive_delete( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'drive' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$file_id = sanitize_text_field( $slots['file_id'] ?? '' );

	if ( empty( $file_id ) ) {
		return [ 'success' => false, 'error' => 'Missing file_id.' ];
	}

	$result = \BZGoogle_Google_Service::drive_delete( $ctx['blog_id'], $ctx['user_id'], $file_id );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => "Đã xóa file {$file_id} khỏi Drive.", 'data' => [ 'type' => 'gdrive' ] ];
}

/* ================================================================
 * gdrive_convert_doc_to_pdf — Export Google Doc as PDF
 * ================================================================ */

function bizcity_ws_gdrive_convert_doc_to_pdf( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'drive' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$file_id = sanitize_text_field( $slots['file_id'] ?? '' );

	if ( empty( $file_id ) ) {
		return [ 'success' => false, 'error' => 'Missing file_id.' ];
	}

	$pdf_content = \BZGoogle_Google_Service::drive_export(
		$ctx['blog_id'], $ctx['user_id'], $file_id, 'application/pdf'
	);

	if ( is_wp_error( $pdf_content ) ) {
		return [ 'success' => false, 'error' => $pdf_content->get_error_message() ];
	}

	// Save to WP uploads
	$upload_dir = wp_upload_dir();
	$filename   = sanitize_file_name( ( $slots['filename'] ?? $file_id ) . '.pdf' );
	$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$written = file_put_contents( $filepath, $pdf_content );

	if ( ! $written ) {
		return [ 'success' => false, 'error' => 'Failed to save PDF.' ];
	}

	$url = trailingslashit( $upload_dir['url'] ) . $filename;

	return [
		'success' => true,
		'message' => 'Đã chuyển đổi sang PDF.',
		'data'    => [ 'type' => 'gdrive_pdf', 'url' => $url, 'path' => $filepath, 'size' => $written ],
	];
}

/* ================================================================
 * gdrive_export — Export Google Workspace file to format
 * ================================================================ */

function bizcity_ws_gdrive_export( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'drive' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$file_id   = sanitize_text_field( $slots['file_id'] ?? '' );
	$mime_type = sanitize_text_field( $slots['mime_type'] ?? '' );

	if ( empty( $file_id ) || empty( $mime_type ) ) {
		return [ 'success' => false, 'error' => 'Missing file_id or mime_type.' ];
	}

	// Allowed export formats
	$allowed_exports = [
		'application/pdf',
		'text/plain',
		'text/csv',
		'text/html',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/rtf',
		'application/epub+zip',
	];

	if ( ! in_array( $mime_type, $allowed_exports, true ) ) {
		return [ 'success' => false, 'error' => 'Unsupported export format: ' . $mime_type ];
	}

	$content = \BZGoogle_Google_Service::drive_export( $ctx['blog_id'], $ctx['user_id'], $file_id, $mime_type );

	if ( is_wp_error( $content ) ) {
		return [ 'success' => false, 'error' => $content->get_error_message() ];
	}

	// Determine extension
	$ext_map = [
		'application/pdf'  => 'pdf',
		'text/plain'       => 'txt',
		'text/csv'         => 'csv',
		'text/html'        => 'html',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document'       => 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'             => 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation'     => 'pptx',
		'application/rtf'      => 'rtf',
		'application/epub+zip' => 'epub',
	];

	$ext = $ext_map[ $mime_type ] ?? 'bin';

	$upload_dir = wp_upload_dir();
	$filename   = sanitize_file_name( ( $slots['filename'] ?? $file_id ) . '.' . $ext );
	$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$written = file_put_contents( $filepath, $content );

	if ( ! $written ) {
		return [ 'success' => false, 'error' => 'Failed to save exported file.' ];
	}

	$url = trailingslashit( $upload_dir['url'] ) . $filename;

	return [
		'success' => true,
		'message' => "Đã export file sang {$ext}.",
		'data'    => [ 'type' => 'gdrive_export', 'url' => $url, 'path' => $filepath, 'mime_type' => $mime_type, 'size' => $written ],
	];
}
