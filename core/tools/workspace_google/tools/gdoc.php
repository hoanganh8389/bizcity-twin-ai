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
 * Google Docs — Atomic Workspace Tool Implementations
 *
 * All tools: tool_type='workspace', accepts_skill=false
 * These STORE/READ/MUTATE documents — they do NOT generate content.
 *
 * @package BizCity\TwinAI\Tools\WorkspaceGoogle
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

/* ── Helper: get Google auth context ──────────────────────── */

function _bizcity_ws_google_ctx( array $slots, string $service = 'docs' ): array {
	$blog_id = get_current_blog_id();
	$user_id = $slots['user_id'] ?? get_current_user_id();

	if ( function_exists( 'bizcity_tool_trace' ) ) {
		bizcity_tool_trace( "🔗 Google {$service} — authenticating (user {$user_id})" );
	}

	if ( ! class_exists( 'BZGoogle_Google_OAuth' ) ) {
		if ( function_exists( 'bizcity_tool_trace' ) ) {
			bizcity_tool_trace( '❌ bizgpt-tool-google plugin chưa active', [], 'error' );
		}
		return [ 'error' => 'bizgpt-tool-google plugin chưa active.' ];
	}

	if ( ! \BZGoogle_Google_OAuth::has_scope( $blog_id, $user_id, $service ) ) {
		$url = \BZGoogle_Google_OAuth::get_scope_upgrade_url( $service, home_url() );
		$labels = [
			'docs'   => 'Google Docs',
			'sheets' => 'Google Sheets',
			'slides' => 'Google Slides',
			'drive'  => 'Google Drive',
		];
		if ( function_exists( 'bizcity_tool_trace' ) ) {
			bizcity_tool_trace( "🔑 Cần cấp quyền {$labels[$service]}", [], 'warn' );
		}
		return [
			'error' => "Cần cấp quyền **{$labels[$service]}**.\n👉 {$url}",
		];
	}

	return [ 'blog_id' => $blog_id, 'user_id' => $user_id ];
}

/* ================================================================
 * gdoc_create — Create a new Google Doc
 * ================================================================ */

function bizcity_ws_gdoc_create( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$title   = sanitize_text_field( $slots['title'] ?? 'Untitled' );
	$content = $slots['content'] ?? '';

	// Step 1: Create blank doc
	$doc = \BZGoogle_Google_Service::docs_create( $ctx['blog_id'], $ctx['user_id'], [
		'title' => $title,
	] );

	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	$doc_id  = $doc['documentId'];
	$doc_url = 'https://docs.google.com/document/d/' . $doc_id . '/edit';

	// Step 2: Write content if provided
	if ( ! empty( $content ) ) {
		\BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
			[ 'insertText' => [ 'location' => [ 'index' => 1 ], 'text' => $content ] ],
		] );
	}

	return [
		'success' => true,
		'message' => "Đã tạo Google Doc: {$title}",
		'data'    => [ 'type' => 'gdoc', 'id' => $doc_id, 'url' => $doc_url, 'title' => $title ],
	];
}

/* ================================================================
 * gdoc_write — Overwrite entire document body
 * ================================================================ */

function bizcity_ws_gdoc_write( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id  = sanitize_text_field( $slots['document_id'] ?? '' );
	$content = $slots['content'] ?? '';

	if ( empty( $doc_id ) || empty( $content ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id or content.' ];
	}

	// Read current doc to get body length
	$doc = \BZGoogle_Google_Service::docs_get( $ctx['blog_id'], $ctx['user_id'], $doc_id );
	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	$end_index = $doc['body']['content'][ count( $doc['body']['content'] ) - 1 ]['endIndex'] ?? 2;

	$requests = [];
	// Delete existing content (keep index 1 = start of body)
	if ( $end_index > 2 ) {
		$requests[] = [
			'deleteContentRange' => [
				'range' => [ 'startIndex' => 1, 'endIndex' => $end_index - 1 ],
			],
		];
	}
	// Insert new content
	$requests[] = [
		'insertText' => [ 'location' => [ 'index' => 1 ], 'text' => $content ],
	];

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, $requests );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [
		'success' => true,
		'message' => 'Đã ghi nội dung vào Google Doc.',
		'data'    => [ 'type' => 'gdoc', 'id' => $doc_id ],
	];
}

/* ================================================================
 * gdoc_append — Append content to end of doc
 * ================================================================ */

function bizcity_ws_gdoc_append( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id  = sanitize_text_field( $slots['document_id'] ?? '' );
	$content = $slots['content'] ?? '';

	if ( empty( $doc_id ) || empty( $content ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id or content.' ];
	}

	// Get current end index
	$doc = \BZGoogle_Google_Service::docs_get( $ctx['blog_id'], $ctx['user_id'], $doc_id );
	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	$body_elements = $doc['body']['content'] ?? [];
	$end_index     = end( $body_elements )['endIndex'] ?? 1;
	$insert_at     = max( 1, $end_index - 1 );

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
		[ 'insertText' => [ 'location' => [ 'index' => $insert_at ], 'text' => "\n" . $content ] ],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => 'Đã thêm nội dung vào cuối Google Doc.', 'data' => [ 'type' => 'gdoc', 'id' => $doc_id ] ];
}

/* ================================================================
 * gdoc_replace — Find and replace text
 * ================================================================ */

function bizcity_ws_gdoc_replace( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id       = sanitize_text_field( $slots['document_id'] ?? '' );
	$find_text    = $slots['find_text'] ?? '';
	$replace_text = $slots['replace_text'] ?? '';

	if ( empty( $doc_id ) || empty( $find_text ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id or find_text.' ];
	}

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
		[
			'replaceAllText' => [
				'containsText' => [ 'text' => $find_text, 'matchCase' => true ],
				'replaceText'  => $replace_text,
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	$count = $result['replies'][0]['replaceAllText']['occurrencesChanged'] ?? 0;

	return [
		'success' => true,
		'message' => "Đã thay thế {$count} chỗ trong Google Doc.",
		'data'    => [ 'type' => 'gdoc', 'id' => $doc_id, 'replacements' => $count ],
	];
}

/* ================================================================
 * gdoc_read — Read document as plaintext
 * ================================================================ */

function bizcity_ws_gdoc_read( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id = sanitize_text_field( $slots['document_id'] ?? '' );
	if ( empty( $doc_id ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id.' ];
	}

	$doc = \BZGoogle_Google_Service::docs_get( $ctx['blog_id'], $ctx['user_id'], $doc_id );
	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	// Extract plaintext from structured content
	$text = _bizcity_ws_gdoc_extract_text( $doc['body']['content'] ?? [] );

	return [
		'success' => true,
		'message' => 'Đã đọc Google Doc: ' . ( $doc['title'] ?? $doc_id ),
		'data'    => [ 'type' => 'gdoc', 'id' => $doc_id, 'title' => $doc['title'] ?? '', 'content' => $text, 'char_count' => mb_strlen( $text ) ],
	];
}

/* ================================================================
 * gdoc_summarize — AI summarize document content
 * ================================================================ */

function bizcity_ws_gdoc_summarize( array $slots ): array {
	$read = bizcity_ws_gdoc_read( $slots );
	if ( ! $read['success'] ) return $read;

	$content   = $read['data']['content'];
	$max_words = absint( $slots['max_words'] ?? 200 );

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [ 'success' => false, 'error' => 'LLM not available.' ];
	}

	$result = bizcity_llm_chat( [
		[ 'role' => 'system', 'content' => "Tóm tắt nội dung sau trong khoảng {$max_words} từ, tiếng Việt, ngắn gọn, đầy đủ ý chính." ],
		[ 'role' => 'user',   'content' => mb_substr( $content, 0, 12000 ) ],
	], [ 'max_tokens' => 1000 ] );

	if ( empty( $result['success'] ) ) {
		return [ 'success' => false, 'error' => $result['error'] ?? 'LLM failed.' ];
	}

	return [
		'success' => true,
		'message' => 'Đã tóm tắt Google Doc: ' . $read['data']['title'],
		'data'    => [ 'type' => 'gdoc', 'id' => $read['data']['id'], 'title' => $read['data']['title'], 'summary' => $result['message'] ],
	];
}

/* ================================================================
 * gdoc_extract_sections — Extract heading structure
 * ================================================================ */

function bizcity_ws_gdoc_extract_sections( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id = sanitize_text_field( $slots['document_id'] ?? '' );
	if ( empty( $doc_id ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id.' ];
	}

	$doc = \BZGoogle_Google_Service::docs_get( $ctx['blog_id'], $ctx['user_id'], $doc_id );
	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	$sections = [];
	foreach ( $doc['body']['content'] ?? [] as $el ) {
		if ( ! isset( $el['paragraph'] ) ) continue;
		$style = $el['paragraph']['paragraphStyle']['namedStyleType'] ?? '';
		if ( strpos( $style, 'HEADING' ) === 0 ) {
			$text = '';
			foreach ( $el['paragraph']['elements'] ?? [] as $run ) {
				$text .= $run['textRun']['content'] ?? '';
			}
			$sections[] = [
				'level' => (int) str_replace( 'HEADING_', '', $style ),
				'text'  => trim( $text ),
				'index' => $el['startIndex'] ?? 0,
			];
		}
	}

	return [
		'success' => true,
		'message' => 'Trích xuất ' . count( $sections ) . ' section từ Google Doc.',
		'data'    => [ 'type' => 'gdoc', 'id' => $doc_id, 'sections' => $sections, 'count' => count( $sections ) ],
	];
}

/* ================================================================
 * gdoc_search — Search text within document
 * ================================================================ */

function bizcity_ws_gdoc_search( array $slots ): array {
	$read = bizcity_ws_gdoc_read( $slots );
	if ( ! $read['success'] ) return $read;

	$query   = $slots['query'] ?? '';
	$content = $read['data']['content'];

	if ( empty( $query ) ) {
		return [ 'success' => false, 'error' => 'Missing query.' ];
	}

	$matches = [];
	$pos     = 0;
	$q_lower = mb_strtolower( $query );
	$c_lower = mb_strtolower( $content );

	while ( ( $pos = mb_strpos( $c_lower, $q_lower, $pos ) ) !== false ) {
		$start   = max( 0, $pos - 50 );
		$excerpt = mb_substr( $content, $start, mb_strlen( $query ) + 100 );
		$matches[] = [ 'position' => $pos, 'excerpt' => trim( $excerpt ) ];
		$pos += mb_strlen( $query );
		if ( count( $matches ) >= 20 ) break;
	}

	return [
		'success' => true,
		'message' => 'Tìm thấy ' . count( $matches ) . ' kết quả trong Google Doc.',
		'data'    => [ 'type' => 'gdoc', 'id' => $read['data']['id'], 'query' => $query, 'matches' => $matches, 'count' => count( $matches ) ],
	];
}

/* ================================================================
 * gdoc_insert_heading — Insert heading at position
 * ================================================================ */

function bizcity_ws_gdoc_insert_heading( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id = sanitize_text_field( $slots['document_id'] ?? '' );
	$text   = $slots['text'] ?? '';
	$level  = max( 1, min( 6, absint( $slots['level'] ?? 1 ) ) );
	$index  = max( 1, absint( $slots['index'] ?? 1 ) );

	if ( empty( $doc_id ) || empty( $text ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id or text.' ];
	}

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
		[ 'insertText' => [ 'location' => [ 'index' => $index ], 'text' => $text . "\n" ] ],
		[
			'updateParagraphStyle' => [
				'range'          => [ 'startIndex' => $index, 'endIndex' => $index + mb_strlen( $text ) + 1 ],
				'paragraphStyle' => [ 'namedStyleType' => "HEADING_{$level}" ],
				'fields'         => 'namedStyleType',
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => "Đã chèn heading H{$level} vào Google Doc.", 'data' => [ 'type' => 'gdoc', 'id' => $doc_id ] ];
}

/* ================================================================
 * gdoc_insert_table — Insert table into document
 * ================================================================ */

function bizcity_ws_gdoc_insert_table( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id  = sanitize_text_field( $slots['document_id'] ?? '' );
	$rows    = max( 1, absint( $slots['rows'] ?? 2 ) );
	$columns = max( 1, absint( $slots['columns'] ?? 2 ) );

	if ( empty( $doc_id ) ) {
		return [ 'success' => false, 'error' => 'Missing document_id.' ];
	}

	// Get end of doc for insertion point
	$doc = \BZGoogle_Google_Service::docs_get( $ctx['blog_id'], $ctx['user_id'], $doc_id );
	if ( is_wp_error( $doc ) ) {
		return [ 'success' => false, 'error' => $doc->get_error_message() ];
	}

	$body_elements = $doc['body']['content'] ?? [];
	$end_index     = end( $body_elements )['endIndex'] ?? 1;
	$insert_at     = max( 1, $end_index - 1 );

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
		[
			'insertTable' => [
				'rows'    => $rows,
				'columns' => $columns,
				'location' => [ 'index' => $insert_at ],
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => "Đã chèn bảng {$rows}x{$columns} vào Google Doc.", 'data' => [ 'type' => 'gdoc', 'id' => $doc_id ] ];
}

/* ================================================================
 * gdoc_format_text — Apply text formatting
 * ================================================================ */

function bizcity_ws_gdoc_format_text( array $slots ): array {
	$ctx = _bizcity_ws_google_ctx( $slots, 'docs' );
	if ( isset( $ctx['error'] ) ) {
		return [ 'success' => false, 'message' => $ctx['error'] ];
	}

	$doc_id      = sanitize_text_field( $slots['document_id'] ?? '' );
	$start_index = absint( $slots['start_index'] ?? 0 );
	$end_index   = absint( $slots['end_index'] ?? 0 );
	$format_str  = sanitize_text_field( $slots['format'] ?? '' );

	if ( empty( $doc_id ) || ! $start_index || ! $end_index || empty( $format_str ) ) {
		return [ 'success' => false, 'error' => 'Missing required fields.' ];
	}

	// Parse format string: "bold", "italic", "bold,italic", "fontSize:14", "color:#FF0000"
	$style  = [];
	$fields = [];

	foreach ( array_map( 'trim', explode( ',', $format_str ) ) as $f ) {
		if ( $f === 'bold' ) {
			$style['bold'] = true;
			$fields[]      = 'bold';
		} elseif ( $f === 'italic' ) {
			$style['italic'] = true;
			$fields[]        = 'italic';
		} elseif ( $f === 'underline' ) {
			$style['underline'] = true;
			$fields[]           = 'underline';
		} elseif ( strpos( $f, 'fontSize:' ) === 0 ) {
			$size = absint( str_replace( 'fontSize:', '', $f ) );
			if ( $size ) {
				$style['fontSize'] = [ 'magnitude' => $size, 'unit' => 'PT' ];
				$fields[]          = 'fontSize';
			}
		}
	}

	if ( empty( $fields ) ) {
		return [ 'success' => false, 'error' => 'Unsupported format. Use: bold, italic, underline, fontSize:N' ];
	}

	$result = \BZGoogle_Google_Service::docs_batch_update( $ctx['blog_id'], $ctx['user_id'], $doc_id, [
		[
			'updateTextStyle' => [
				'range'     => [ 'startIndex' => $start_index, 'endIndex' => $end_index ],
				'textStyle' => $style,
				'fields'    => implode( ',', $fields ),
			],
		],
	] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'error' => $result->get_error_message() ];
	}

	return [ 'success' => true, 'message' => 'Đã format text trong Google Doc.', 'data' => [ 'type' => 'gdoc', 'id' => $doc_id ] ];
}

/* ================================================================
 * INTERNAL HELPER — extract plaintext from Google Docs body
 * ================================================================ */

function _bizcity_ws_gdoc_extract_text( array $body_content ): string {
	$text = '';
	foreach ( $body_content as $element ) {
		if ( isset( $element['paragraph'] ) ) {
			foreach ( $element['paragraph']['elements'] ?? [] as $run ) {
				$text .= $run['textRun']['content'] ?? '';
			}
		} elseif ( isset( $element['table'] ) ) {
			foreach ( $element['table']['tableRows'] ?? [] as $row ) {
				$cells = [];
				foreach ( $row['tableCells'] ?? [] as $cell ) {
					$cell_text = '';
					foreach ( $cell['content'] ?? [] as $cel ) {
						if ( isset( $cel['paragraph'] ) ) {
							foreach ( $cel['paragraph']['elements'] ?? [] as $run ) {
								$cell_text .= $run['textRun']['content'] ?? '';
							}
						}
					}
					$cells[] = trim( $cell_text );
				}
				$text .= implode( ' | ', $cells ) . "\n";
			}
		}
	}
	return trim( $text );
}
