<?php
/**
 * BZCode Source Manager — Upload, extract, manage reference sources per project.
 *
 * Pattern mirrors BZDoc_Sources: upload file → extract text → store in bizcity_code_sources.
 * Sources provide context for richer, more accurate landing page generation.
 *
 * @package BizCity_Code
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Source_Manager {

	private static function table(): string {
		return BZCode_Installer::table_sources();
	}

	private static function chunks_table(): string {
		return BZCode_Installer::table_source_chunks();
	}

	/* ═══════════════════════════════════════════════
	 *  Upload a file (from $_FILES)
	 * ═══════════════════════════════════════════════ */

	public static function upload( int $project_id, array $file ) {
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

		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new \WP_Error( 'file_too_large', 'File quá lớn (tối đa 10MB).' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Temporarily allow our MIME types (multisite may restrict uploads)
		$mime_filter = function ( $mimes ) {
			$mimes['pdf']  = 'application/pdf';
			$mimes['csv']  = 'text/csv';
			$mimes['md']   = 'text/markdown';
			$mimes['json'] = 'application/json';
			$mimes['txt']  = 'text/plain';
			$mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$mimes['pptx'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
			$mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			return $mimes;
		};
		add_filter( 'upload_mimes', $mime_filter, 999 );

		// Disable real MIME check (wp_check_filetype_and_ext may reject valid files)
		$filetype_filter = function ( $data, $file_path, $filename, $mimes ) {
			if ( ! $data['type'] ) {
				$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = [
					'pdf'  => 'application/pdf',
					'csv'  => 'text/csv',
					'md'   => 'text/markdown',
					'json' => 'application/json',
					'txt'  => 'text/plain',
					'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
					'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				];
				if ( isset( $map[ $ext ] ) ) {
					$data['ext']             = $ext;
					$data['type']            = $map[ $ext ];
					$data['proper_filename'] = false;
				}
			}
			return $data;
		};
		add_filter( 'wp_check_filetype_and_ext', $filetype_filter, 999, 4 );

		$upload = wp_handle_upload( $file, [ 'test_form' => false ] );

		remove_filter( 'upload_mimes', $mime_filter, 999 );
		remove_filter( 'wp_check_filetype_and_ext', $filetype_filter, 999 );

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

		$content = self::extract_text( $upload['file'], $upload['type'] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return self::create( $project_id, [
			'title'         => $file['name'],
			'source_type'   => 'file',
			'attachment_id' => $attachment_id,
			'content_text'  => $content,
		] );
	}

	/* ═══════════════════════════════════════════════
	 *  Create a source record
	 * ═══════════════════════════════════════════════ */

	public static function create( int $project_id, array $data ): int {
		global $wpdb;

		$content    = $data['content_text'] ?? '';
		$char_count = mb_strlen( $content );

		$wpdb->insert( self::table(), [
			'project_id'       => $project_id,
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

	/* ═══════════════════════════════════════════════
	 *  List sources for a project
	 * ═══════════════════════════════════════════════ */

	public static function list_by_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, project_id, title, source_type, char_count, token_estimate,
			        chunk_count, embedding_status, status, created_at
			 FROM " . self::table() . "
			 WHERE project_id = %d AND status = 'ready'
			 ORDER BY created_at ASC",
			$project_id
		), ARRAY_A ) ?: [];
	}

	/* ═══════════════════════════════════════════════
	 *  Get single source
	 * ═══════════════════════════════════════════════ */

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE id = %d",
			$id
		) );
	}

	/* ═══════════════════════════════════════════════
	 *  Get all source content for LLM context
	 * ═══════════════════════════════════════════════ */

	public static function get_all_content( int $project_id, int $max_chars = 120000 ): string {
		global $wpdb;

		$sources = $wpdb->get_results( $wpdb->prepare(
			"SELECT title, content_text FROM " . self::table() . "
			 WHERE project_id = %d AND status = 'ready'
			 ORDER BY created_at ASC",
			$project_id
		) );

		if ( ! $sources ) return '';

		$parts = [];
		$used  = 0;

		foreach ( $sources as $src ) {
			$text      = $src->content_text;
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

	/* ═══════════════════════════════════════════════
	 *  Delete source (soft-delete)
	 * ═══════════════════════════════════════════════ */

	public static function delete( int $id ): bool {
		global $wpdb;

		$source = self::get( $id );
		if ( ! $source ) return false;

		if ( (int) $source->user_id !== get_current_user_id() ) return false;

		// Delete chunks
		$wpdb->delete( self::chunks_table(), [ 'source_id' => $id ] );

		// Soft-delete source
		return (bool) $wpdb->update( self::table(), [ 'status' => 'deleted' ], [ 'id' => $id ] );
	}

	/* ═══════════════════════════════════════════════
	 *  Clone source from another table (notebook transfer)
	 *
	 *  Copies a source record + its chunks into this project.
	 *  Preserves embeddings so no re-embedding is needed.
	 * ═══════════════════════════════════════════════ */

	public static function clone_source( int $project_id, array $source_data, array $chunks = [] ): int {
		$source_id = self::create( $project_id, $source_data );
		if ( ! $source_id ) return 0;

		if ( ! empty( $chunks ) ) {
			global $wpdb;
			$chunks_table = self::chunks_table();
			foreach ( $chunks as $chunk ) {
				$wpdb->insert( $chunks_table, [
					'source_id'       => $source_id,
					'project_id'      => $project_id,
					'chunk_index'     => (int) ( $chunk['chunk_index'] ?? 0 ),
					'content'         => $chunk['content'] ?? '',
					'token_count'     => (int) ( $chunk['token_count'] ?? 0 ),
					'embedding'       => $chunk['embedding'] ?? '',
					'embedding_model' => $chunk['embedding_model'] ?? '',
					'created_at'      => current_time( 'mysql' ),
				] );
			}

			// Update chunk_count on source
			$wpdb->update( self::table(), [
				'chunk_count'      => count( $chunks ),
				'embedding_status' => 'complete',
			], [ 'id' => $source_id ] );
		}

		return $source_id;
	}

	/* ═══════════════════════════════════════════════
	 *  Text Extraction (delegated to BZDoc_Sources if available)
	 * ═══════════════════════════════════════════════ */

	private static function extract_text( string $file_path, string $mime ) {
		// Reuse BZDoc_Sources extraction if available (avoid code duplication)
		if ( class_exists( 'BZDoc_Sources' ) && method_exists( 'BZDoc_Sources', 'extract_text_public' ) ) {
			return BZDoc_Sources::extract_text_public( $file_path, $mime );
		}

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
		// Try Smalot PDF Parser if available
		if ( class_exists( '\\Smalot\\PdfParser\\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $path );
				$text   = $pdf->getText();
				if ( mb_strlen( trim( $text ) ) > 50 ) return $text;
			} catch ( \Throwable $e ) {
				error_log( '[BZCode] PDF parse error (Smalot): ' . $e->getMessage() );
			}
		}

		// Fallback: pdftotext CLI
		$output = [];
		$code   = 0;
		@exec( 'pdftotext ' . escapeshellarg( $path ) . ' -', $output, $code );
		if ( $code === 0 && ! empty( $output ) ) {
			$text = implode( "\n", $output );
			if ( mb_strlen( trim( $text ) ) > 50 ) return $text;
		}

		// Fallback: pure PHP stream extraction
		$text = self::extract_pdf_pure( $path );
		if ( mb_strlen( $text ) > 50 ) {
			return $text;
		}

		// Final fallback: Vision OCR (if bizcity_llm_chat + Imagick available)
		if ( class_exists( 'Imagick' ) && function_exists( 'bizcity_llm_chat' ) ) {
			$text = self::extract_pdf_vision_ocr( $path );
			if ( ! empty( $text ) && ! is_wp_error( $text ) ) {
				return $text;
			}
		}

		return new \WP_Error( 'pdf_error', 'Không thể đọc PDF.' );
	}

	/**
	 * Pure PHP PDF text extraction — decodes text streams without external libraries.
	 */
	private static function extract_pdf_pure( string $path ): string {
		$content = @file_get_contents( $path );
		if ( $content === false ) return '';

		$text = '';
		$streams = [];
		$offset  = 0;

		while ( ( $start = strpos( $content, 'stream', $offset ) ) !== false ) {
			$data_start = $start + 6;
			if ( isset( $content[ $data_start ] ) && $content[ $data_start ] === "\r" ) $data_start++;
			if ( isset( $content[ $data_start ] ) && $content[ $data_start ] === "\n" ) $data_start++;

			$end = strpos( $content, 'endstream', $data_start );
			if ( $end === false ) break;

			$stream_data = substr( $content, $data_start, $end - $data_start );

			$decoded = @gzuncompress( $stream_data );
			if ( $decoded === false ) $decoded = @gzinflate( $stream_data );
			if ( $decoded === false ) $decoded = $stream_data;

			if ( preg_match( '/BT\b/s', $decoded ) ) {
				$streams[] = $decoded;
			}
			$offset = $end + 9;
		}

		foreach ( $streams as $stream ) {
			preg_match_all( '/BT\s*(.*?)\s*ET/s', $stream, $bt_matches );
			foreach ( $bt_matches[1] as $bt_block ) {
				preg_match_all( '/\(([^)]*)\)\s*Tj/s', $bt_block, $tj );
				foreach ( $tj[1] as $t ) {
					$text .= self::pdf_decode_string( $t );
				}

				preg_match_all( '/\[(.*?)\]\s*TJ/s', $bt_block, $tj_arr );
				foreach ( $tj_arr[1] as $arr ) {
					preg_match_all( '/\(([^)]*)\)/', $arr, $parts );
					foreach ( $parts[1] as $t ) {
						$text .= self::pdf_decode_string( $t );
					}
				}

				preg_match_all( "/\\(([^)]*)\\)\\s*'/s", $bt_block, $tick );
				foreach ( $tick[1] as $t ) {
					$text .= self::pdf_decode_string( $t ) . "\n";
				}

				if ( preg_match( '/T[dD]\s/s', $bt_block ) ) {
					$text .= "\n";
				}
			}
		}

		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	private static function pdf_decode_string( string $str ): string {
		$str = str_replace( [ '\\n', '\\r', '\\t' ], [ "\n", "\r", "\t" ], $str );
		$str = preg_replace_callback( '/\\\\([0-7]{1,3})/', function ( $m ) {
			return chr( octdec( $m[1] ) );
		}, $str );
		$str = str_replace( [ '\\(', '\\)', '\\\\' ], [ '(', ')', '\\' ], $str );
		return $str;
	}

	/**
	 * OCR scanned PDF via Vision LLM (Imagick + bizcity_llm_chat).
	 */
	private static function extract_pdf_vision_ocr( string $path ) {
		try {
			$imagick = new \Imagick();
			$imagick->setResolution( 200, 200 );
			$imagick->readImage( $path );
			$page_count = $imagick->getNumberImages();
			$max_pages  = min( $page_count, 20 );
			$all_text   = [];

			for ( $i = 0; $i < $max_pages; $i++ ) {
				$imagick->setIteratorIndex( $i );
				$imagick->setImageFormat( 'png' );

				$width = $imagick->getImageWidth();
				if ( $width > 1500 ) {
					$imagick->resizeImage( 1500, 0, \Imagick::FILTER_LANCZOS, 1 );
				}

				$blob   = $imagick->getImageBlob();
				$base64 = base64_encode( $blob );

				$messages = [
					[
						'role'    => 'user',
						'content' => [
							[ 'type' => 'image_url', 'image_url' => [ 'url' => 'data:image/png;base64,' . $base64 ] ],
							[ 'type' => 'text', 'text' => 'Trích xuất TOÀN BỘ text trong hình này. Giữ nguyên cấu trúc. Chỉ trả về text thuần.' ],
						],
					],
				];

				$result = bizcity_llm_chat( $messages, [
					'model'      => 'google/gemini-2.0-flash-001',
					'purpose'    => 'vision',
					'max_tokens' => 4000,
					'timeout'    => 60,
				] );

				if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
					$all_text[] = "--- Trang " . ( $i + 1 ) . " ---\n" . trim( $result['message'] );
				}
			}

			$imagick->clear();
			$imagick->destroy();

			$combined = implode( "\n\n", $all_text );
			return mb_strlen( $combined ) > 100 ? $combined : '';

		} catch ( \Throwable $e ) {
			error_log( '[BZCode] Vision OCR error: ' . $e->getMessage() );
			return new \WP_Error( 'ocr_error', 'OCR thất bại: ' . $e->getMessage() );
		}
	}

	private static function extract_docx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'docx_error', 'Cannot open DOCX file.' );
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( ! $xml ) {
			return new \WP_Error( 'docx_error', 'Cannot read document.xml' );
		}
		$text = strip_tags( str_replace( '<', ' <', $xml ) );
		return preg_replace( '/\s+/', ' ', trim( $text ) );
	}

	private static function extract_pptx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'pptx_error', 'Cannot open PPTX file.' );
		}
		$texts = [];
		for ( $i = 1; $i <= 200; $i++ ) {
			$xml = $zip->getFromName( "ppt/slides/slide{$i}.xml" );
			if ( ! $xml ) break;
			$texts[] = strip_tags( str_replace( '<', ' <', $xml ) );
		}
		$zip->close();
		return implode( "\n\n", $texts );
	}

	private static function extract_xlsx( string $path ) {
		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new \WP_Error( 'xlsx_error', 'Cannot open XLSX file.' );
		}
		$shared = $zip->getFromName( 'xl/sharedStrings.xml' );
		$zip->close();
		if ( ! $shared ) {
			return new \WP_Error( 'xlsx_error', 'Cannot read shared strings.' );
		}
		$text = strip_tags( str_replace( '<', ' <', $shared ) );
		return preg_replace( '/\s+/', ' ', trim( $text ) );
	}
}
