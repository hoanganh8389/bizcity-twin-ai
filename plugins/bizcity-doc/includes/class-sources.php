<?php
/**
 * BZDoc Sources — Upload, extract, manage reference documents per project (doc_id).
 *
 * Pattern: upload file → WP media → extract text → store in bzdoc_project_sources.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Sources {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzdoc_project_sources';
	}

	/**
	 * Register WordPress filters needed for DOCX/XLSX upload support.
	 * Must be called on the 'init' hook (added from bizcity-doc.php).
	 */
	public static function register_upload_filters(): void {
		// Allow Office XML MIME types in WordPress upload
		add_filter( 'upload_mimes', function ( $mimes ) {
			$mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			$mimes['pptx'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
			return $mimes;
		} );

		// Fix WordPress real-MIME check: Office XML files are ZIP archives.
		// Some servers detect them as 'application/zip' and WordPress rejects the upload.
		add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
			if ( empty( $data['ext'] ) && ! empty( $filename ) ) {
				$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = [
					'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				];
				if ( isset( $map[ $ext ] ) ) {
					$data['ext']             = $ext;
					$data['type']            = $map[ $ext ];
					$data['proper_filename'] = $filename;
				}
			}
			return $data;
		}, 10, 4 );
	}

	/* ── Upload a file (from $_FILES) ── */
	public static function upload( int $doc_id, array $file ) {
		// Extension-to-MIME map: normalise browser/server inconsistencies.
		// DOCX/XLSX are ZIP archives — some browsers/servers report 'application/zip'.
		$ext_mime_map = [
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
			'md'   => 'text/markdown',
			'json' => 'application/json',
		];
		$file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( isset( $ext_mime_map[ $file_ext ] ) ) {
			$file['type'] = $ext_mime_map[ $file_ext ];
		}

		$allowed = [
			'application/pdf',
			'text/plain',
			'text/csv',
			'text/markdown',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/json',
		];

		if ( ! in_array( $file['type'], $allowed, true ) ) {
			return new \WP_Error( 'invalid_type', 'Định dạng file không được hỗ trợ: ' . $file['type'] );
		}

		// Max 10MB
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new \WP_Error( 'file_too_large', 'File quá lớn (tối đa 10MB).' );
		}

		// Upload to WP media
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_handle_upload( $file, [ 'test_form' => false ] );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'upload_error', $upload['error'] );
		}

		$attachment_id = wp_insert_attachment( [
			'post_mime_type' => $upload['type'],
			'post_title'     => sanitize_file_name( $file['name'] ),
			'post_status'    => 'private',
		], $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Extract text content
		$content = self::extract_text( $upload['file'], $upload['type'] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// Store source record
		return self::create( $doc_id, [
			'title'         => $file['name'],
			'source_type'   => 'file',
			'attachment_id' => $attachment_id,
			'content_text'  => $content,
		] );
	}

	/* ── Create a source record ── */
	public static function create( int $doc_id, array $data ): int {
		global $wpdb;

		$content = $data['content_text'] ?? '';
		$char_count = mb_strlen( $content );

		$wpdb->insert( self::table(), [
			'doc_id'           => $doc_id,
			'user_id'          => get_current_user_id(),
			'title'            => sanitize_text_field( $data['title'] ?? '' ),
			'source_type'      => sanitize_text_field( $data['source_type'] ?? 'file' ),
			'source_url'       => esc_url_raw( $data['source_url'] ?? '' ),
			'attachment_id'    => absint( $data['attachment_id'] ?? 0 ),
			'content_text'     => $content,
			'content_hash'     => hash( 'sha256', $content ),
			'char_count'       => $char_count,
			'token_estimate'   => (int) ( $char_count / 4 ),
			'embedding_status' => 'pending',
			'status'           => 'ready',
			'created_at'       => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	/* ── List sources for a doc/project ── */
	public static function list_by_doc( int $doc_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, doc_id, title, source_type, char_count, token_estimate,
			        chunk_count, embedding_status, status, created_at
			 FROM " . self::table() . "
			 WHERE doc_id = %d AND status = 'ready'
			 ORDER BY created_at ASC",
			$doc_id
		), ARRAY_A ) ?: [];
	}

	/* ── Get single source ── */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d",
			$id
		) );
	}

	/* ── Get all source content for RAG context ── */
	public static function get_all_content( int $doc_id, int $max_chars = 120000 ): string {
		global $wpdb;

		$sources = $wpdb->get_results( $wpdb->prepare(
			"SELECT title, content_text FROM " . self::table() . "
			 WHERE doc_id = %d AND status = 'ready'
			 ORDER BY created_at ASC",
			$doc_id
		) );

		if ( ! $sources ) return '';

		$parts = [];
		$used  = 0;

		foreach ( $sources as $src ) {
			$text = $src->content_text;
			$remaining = $max_chars - $used;
			if ( $remaining <= 0 ) break;

			if ( mb_strlen( $text ) > $remaining ) {
				$text = mb_substr( $text, 0, $remaining );
			}

			$parts[] = "[Nguồn: {$src->title}]\n{$text}";
			$used   += mb_strlen( $text );
		}

		return implode( "\n---\n", $parts );
	}

	/* ── Delete source ── */
	public static function delete( int $id ): bool {
		global $wpdb;

		$source = self::get( $id );
		if ( ! $source ) return false;

		// Must own
		if ( (int) $source->user_id !== get_current_user_id() ) return false;

		// Delete chunks
		$wpdb->delete( $wpdb->prefix . 'bzdoc_project_source_chunks', [ 'source_id' => $id ] );

		// Soft-delete source
		return (bool) $wpdb->update( self::table(), [ 'status' => 'deleted' ], [ 'id' => $id ] );
	}

	/* ── Text Extraction ── */
	private static function extract_text( string $file_path, string $mime ) {
		switch ( $mime ) {
			case 'text/plain':
			case 'text/csv':
			case 'text/markdown':
			case 'application/json':
				$text = file_get_contents( $file_path );
				return $text !== false ? $text : new \WP_Error( 'read_error', 'Cannot read file' );

			case 'application/pdf':
				return self::extract_pdf( $file_path );

			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				return self::extract_docx( $file_path );

			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
				return self::extract_pptx( $file_path );

			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
				return self::extract_xlsx( $file_path );

			default:
				return new \WP_Error( 'unsupported', 'Unsupported file type: ' . $mime );
		}
	}

	private static function extract_pdf( string $path ) {
		// Try Smalot PDF Parser if available (common in WP ecosystem)
		if ( class_exists( '\\Smalot\\PdfParser\\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $path );
				$text   = $pdf->getText();
				if ( mb_strlen( trim( $text ) ) > 50 ) return $text;
			} catch ( \Throwable $e ) {
				error_log( '[BZDoc] PDF parse error (Smalot): ' . $e->getMessage() );
			}
		}

		// Fallback: try pdftotext CLI (available on most Linux hosts)
		$output = [];
		$code   = 0;
		@exec( 'pdftotext ' . escapeshellarg( $path ) . ' -', $output, $code );
		if ( $code === 0 && ! empty( $output ) ) {
			$text = implode( "\n", $output );
			if ( mb_strlen( trim( $text ) ) > 50 ) return $text;
		}

		// Fallback: pure PHP stream extraction (no external deps)
		$text = self::extract_pdf_pure( $path );
		if ( mb_strlen( $text ) > 50 ) {
			return $text;
		}

		// Final fallback: Vision OCR — for scanned/image PDFs
		// Convert PDF pages to images via Imagick, then OCR via vision LLM
		$text = self::extract_pdf_vision_ocr( $path );
		if ( ! empty( $text ) && ! is_wp_error( $text ) ) {
			return $text;
		}

		return new \WP_Error( 'pdf_error', 'Không thể đọc PDF. File có thể là scan/image và server không hỗ trợ Imagick.' );
	}

	/**
	 * OCR scanned PDF via Vision LLM.
	 * Requires: Imagick (ext-imagick) + bizcity_llm_chat().
	 * Converts each page to PNG → base64 → sends to vision model for text extraction.
	 */
	private static function extract_pdf_vision_ocr( string $path ) {
		// Check prerequisites
		if ( ! class_exists( 'Imagick' ) ) {
			error_log( '[BZDoc] Vision OCR skipped: Imagick not available' );
			return new \WP_Error( 'no_imagick', 'Imagick extension not available for PDF OCR.' );
		}
		if ( ! function_exists( 'bizcity_llm_chat' ) ) {
			error_log( '[BZDoc] Vision OCR skipped: bizcity_llm_chat not available' );
			return new \WP_Error( 'no_llm', 'LLM not available for OCR.' );
		}

		error_log( '[BZDoc] Starting Vision OCR for scanned PDF: ' . basename( $path ) );

		try {
			$imagick = new \Imagick();
			$imagick->setResolution( 200, 200 ); // 200 DPI — good balance quality/speed
			$imagick->readImage( $path );
			$page_count = $imagick->getNumberImages();

			// Limit to 20 pages to avoid excessive API calls
			$max_pages = min( $page_count, 20 );
			$all_text  = [];

			for ( $i = 0; $i < $max_pages; $i++ ) {
				$imagick->setIteratorIndex( $i );
				$imagick->setImageFormat( 'png' );

				// Compress to reasonable size (max 1500px width)
				$width = $imagick->getImageWidth();
				if ( $width > 1500 ) {
					$imagick->resizeImage( 1500, 0, \Imagick::FILTER_LANCZOS, 1 );
				}

				$blob   = $imagick->getImageBlob();
				$base64 = base64_encode( $blob );

				// Call vision model
				$messages = [
					[
						'role'    => 'user',
						'content' => [
							[
								'type'      => 'image_url',
								'image_url' => [
									'url' => 'data:image/png;base64,' . $base64,
								],
							],
							[
								'type' => 'text',
								'text' => 'Trích xuất TOÀN BỘ text trong hình này. Giữ nguyên cấu trúc, bao gồm tiêu đề, đoạn văn, bảng, gạch đầu dòng. Chỉ trả về text thuần, không thêm giải thích.',
							],
						],
					],
				];

				$result = bizcity_llm_chat( $messages, [
					'model'      => 'google/gemini-2.0-flash-001', // Fast + cheap vision
					'purpose'    => 'vision',
					'max_tokens' => 4000,
					'timeout'    => 60,
				] );

				if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
					$all_text[] = "--- Trang " . ( $i + 1 ) . " ---\n" . trim( $result['message'] );
				} else {
					error_log( '[BZDoc] Vision OCR failed on page ' . ( $i + 1 ) . ': ' . ( $result['error'] ?? 'unknown' ) );
					$all_text[] = "--- Trang " . ( $i + 1 ) . " --- [OCR thất bại]";
				}
			}

			$imagick->clear();
			$imagick->destroy();

			$combined = implode( "\n\n", $all_text );
			error_log( '[BZDoc] Vision OCR complete: ' . $max_pages . ' pages, ' . mb_strlen( $combined ) . ' chars' );

			return mb_strlen( $combined ) > 100 ? $combined : '';

		} catch ( \Throwable $e ) {
			error_log( '[BZDoc] Vision OCR error: ' . $e->getMessage() );
			return new \WP_Error( 'ocr_error', 'OCR thất bại: ' . $e->getMessage() );
		}
	}

	/**
	 * Pure PHP PDF text extraction — decodes text streams without external libraries.
	 * Works for most text-based PDFs. Will not work for scanned/image PDFs.
	 */
	private static function extract_pdf_pure( string $path ): string {
		$content = @file_get_contents( $path );
		if ( $content === false ) return '';

		$text = '';

		// Method 1: Extract from stream objects (most common)
		// Find all stream...endstream blocks
		$streams = [];
		$offset  = 0;
		while ( ( $start = strpos( $content, 'stream', $offset ) ) !== false ) {
			// stream keyword followed by \r\n or \n
			$data_start = $start + 6;
			if ( isset( $content[ $data_start ] ) && $content[ $data_start ] === "\r" ) $data_start++;
			if ( isset( $content[ $data_start ] ) && $content[ $data_start ] === "\n" ) $data_start++;

			$end = strpos( $content, 'endstream', $data_start );
			if ( $end === false ) break;

			$stream_data = substr( $content, $data_start, $end - $data_start );

			// Try to decompress (most PDF streams are FlateDecode)
			$decoded = @gzuncompress( $stream_data );
			if ( $decoded === false ) {
				$decoded = @gzinflate( $stream_data );
			}
			if ( $decoded === false ) {
				$decoded = $stream_data; // might be uncompressed
			}

			// Extract text operators: Tj, TJ, '
			if ( preg_match( '/BT\b/s', $decoded ) ) {
				$streams[] = $decoded;
			}

			$offset = $end + 9;
		}

		foreach ( $streams as $stream ) {
			// Extract text between BT...ET blocks
			preg_match_all( '/BT\s*(.*?)\s*ET/s', $stream, $bt_matches );
			foreach ( $bt_matches[1] as $bt_block ) {
				// Tj operator: (text) Tj
				preg_match_all( '/\(([^)]*)\)\s*Tj/s', $bt_block, $tj );
				foreach ( $tj[1] as $t ) {
					$text .= self::pdf_decode_string( $t );
				}

				// TJ operator: [(text) num (text)] TJ
				preg_match_all( '/\[(.*?)\]\s*TJ/s', $bt_block, $tj_arr );
				foreach ( $tj_arr[1] as $arr ) {
					preg_match_all( '/\(([^)]*)\)/', $arr, $parts );
					foreach ( $parts[1] as $t ) {
						$text .= self::pdf_decode_string( $t );
					}
				}

				// ' operator (move to next line and show text)
				preg_match_all( "/\\(([^)]*)\\)\\s*'/s", $bt_block, $tick );
				foreach ( $tick[1] as $t ) {
					$text .= self::pdf_decode_string( $t ) . "\n";
				}

				// Td/TD — line breaks
				if ( preg_match( '/T[dD]\s/s', $bt_block ) ) {
					$text .= "\n";
				}
			}
		}

		// Clean up
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( $text );

		return $text;
	}

	/**
	 * Decode PDF string escapes: \n, \r, \t, octal \NNN, etc.
	 */
	private static function pdf_decode_string( string $str ): string {
		$str = str_replace( [ '\\n', '\\r', '\\t' ], [ "\n", "\r", "\t" ], $str );
		$str = preg_replace_callback( '/\\\\([0-7]{1,3})/', function ( $m ) {
			return chr( octdec( $m[1] ) );
		}, $str );
		$str = str_replace( [ '\\(', '\\)', '\\\\' ], [ '(', ')', '\\' ], $str );
		return $str;
	}

	private static function extract_docx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'docx_error', 'Cannot open DOCX file.' );
		}

		// Extract text from main document + headers/footers + footnotes
		$parts_to_read = [
			'word/document.xml',
			'word/header1.xml', 'word/header2.xml', 'word/header3.xml',
			'word/footer1.xml', 'word/footer2.xml', 'word/footer3.xml',
			'word/footnotes.xml', 'word/endnotes.xml',
		];

		$all_xml = '';
		foreach ( $parts_to_read as $part ) {
			$xml = $zip->getFromName( $part );
			if ( $xml ) {
				$all_xml .= $xml . "\n";
			}
		}
		$zip->close();

		if ( empty( $all_xml ) ) {
			return new \WP_Error( 'docx_error', 'No document.xml found.' );
		}

		// Preserve paragraph and table-cell breaks before stripping tags
		$all_xml = str_replace(
			[ '</w:p>', '</w:tc>', '</w:tr>' ],
			[ "\n", "\t", "\n" ],
			$all_xml
		);
		$text = strip_tags( $all_xml );

		// Clean up excessive whitespace
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", trim( $text ) );

		return $text ?: new \WP_Error( 'docx_error', 'No text extracted from DOCX.' );
	}

	private static function extract_pptx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'pptx_error', 'Cannot open PPTX file.' );
		}

		$text = '';
		for ( $i = 1; $i <= 200; $i++ ) {
			$xml = $zip->getFromName( "ppt/slides/slide{$i}.xml" );
			if ( ! $xml ) break;
			$xml  = str_replace( '</a:p>', "\n", $xml );
			$text .= "--- Slide {$i} ---\n" . strip_tags( $xml ) . "\n";
		}
		$zip->close();

		return $text ?: new \WP_Error( 'pptx_error', 'PPTX has no slides.' );
	}

	private static function extract_xlsx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'xlsx_error', 'Cannot open XLSX file.' );
		}

		// ── Load shared strings ──────────────────────────────────
		// XLSX stores all unique strings in sharedStrings.xml.
		// Each <si> (string item) can be:
		//   Simple:  <si><t>text</t></si>
		//   Rich:    <si><r><t>part1</t></r><r><t>part2</t></r></si>
		// We must concatenate ALL <t> descendants to get the full string.
		$strings = [];
		$ss_xml  = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss_xml ) {
			// Use DOMDocument for reliable namespace-agnostic parsing
			$dom = new \DOMDocument();
			if ( @$dom->loadXML( $ss_xml ) ) {
				$si_nodes = $dom->getElementsByTagName( 'si' );
				foreach ( $si_nodes as $si ) {
					$val    = '';
					$t_nodes = $si->getElementsByTagName( 't' );
					foreach ( $t_nodes as $t ) {
						$val .= $t->nodeValue;
					}
					$strings[] = $val;
				}
			}
		}

		// ── Read all sheets (not just sheet1) ────────────────────
		// Discover sheet file names from workbook.xml.rels
		$sheet_files = [];
		$rels_xml    = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( $rels_xml ) {
			$rels_dom = new \DOMDocument();
			if ( @$rels_dom->loadXML( $rels_xml ) ) {
				foreach ( $rels_dom->getElementsByTagName( 'Relationship' ) as $rel ) {
					$type   = $rel->getAttribute( 'Type' );
					$target = $rel->getAttribute( 'Target' );
					if ( strpos( $type, '/worksheet' ) !== false ) {
						// Target can be relative like 'worksheets/sheet1.xml'
						if ( strpos( $target, '/' ) === false ) {
							$target = 'worksheets/' . $target;
						}
						$sheet_files[] = 'xl/' . ltrim( $target, '/' );
					}
				}
			}
		}
		if ( empty( $sheet_files ) ) {
			// Fallback: try sheet1 directly
			$sheet_files = [ 'xl/worksheets/sheet1.xml' ];
		}

		$all_rows = [];
		foreach ( $sheet_files as $sf ) {
			$sheet_xml = $zip->getFromName( $sf );
			if ( ! $sheet_xml ) continue;

			$sheet_dom = new \DOMDocument();
			if ( ! @$sheet_dom->loadXML( $sheet_xml ) ) continue;

			$row_nodes = $sheet_dom->getElementsByTagName( 'row' );
			foreach ( $row_nodes as $row_node ) {
				$cells = [];
				foreach ( $row_node->getElementsByTagName( 'c' ) as $cell ) {
					$t_attr = $cell->getAttribute( 't' );
					$v_nodes = $cell->getElementsByTagName( 'v' );
					$val     = $v_nodes->length > 0 ? $v_nodes->item( 0 )->nodeValue : '';

					if ( $t_attr === 's' && isset( $strings[ (int) $val ] ) ) {
						// Shared string reference
						$val = $strings[ (int) $val ];
					} elseif ( $t_attr === 'inlineStr' ) {
						// Inline string — read all <t> descendants
						$is_nodes = $cell->getElementsByTagName( 'is' );
						if ( $is_nodes->length > 0 ) {
							$val = '';
							foreach ( $is_nodes->item( 0 )->getElementsByTagName( 't' ) as $t ) {
								$val .= $t->nodeValue;
							}
						}
					}
					$cells[] = $val;
				}
				if ( ! empty( array_filter( $cells, fn( $c ) => $c !== '' ) ) ) {
					$all_rows[] = implode( "\t", $cells );
				}
			}
		}
		$zip->close();

		if ( empty( $all_rows ) ) {
			return new \WP_Error( 'xlsx_error', 'No data extracted from XLSX.' );
		}

		return implode( "\n", $all_rows );
	}
}
