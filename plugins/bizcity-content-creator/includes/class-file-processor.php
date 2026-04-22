<?php
/**
 * BZCC File Processor — Extract text from uploaded CSV, Excel, PDF files.
 *
 * Phase 3.2 Sprint 2: Parses file uploads into text/markdown tables
 * for injection into Content Creator prompts.
 *
 * @package    Bizcity_Content_Creator
 * @subpackage Includes
 * @since      0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_File_Processor {

	/** Max file size: 10MB. */
	const MAX_SIZE = 10 * 1024 * 1024;

	/** Default max rows for CSV/Excel parsing. */
	const DEFAULT_MAX_ROWS = 500;

	/** Allowed MIME types for file uploads. */
	const ALLOWED_MIMES = [
		'text/csv'                                                                => 'csv',
		'application/csv'                                                         => 'csv',
		'application/vnd.ms-excel'                                                => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
		'application/pdf'                                                         => 'pdf',
	];

	/**
	 * Process all file-type fields in form data.
	 *
	 * @param array $form_data    Cleaned form data.
	 * @param array $form_fields  Template form_fields definition.
	 * @return array<string, string>  [ field_slug => extracted_text ]
	 */
	public static function process( array $form_data, array $form_fields ): array {
		$results = [];

		foreach ( $form_fields as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'file' ) {
				continue;
			}

			$slug          = $field['slug'] ?? '';
			$attachment_id = (int) ( $form_data[ $slug . '_attachment_id' ] ?? 0 );
			if ( ! $attachment_id ) {
				continue;
			}

			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				error_log( '[BZCC-File] Attachment #' . $attachment_id . ' file not found' );
				continue;
			}

			// File size check
			if ( filesize( $file_path ) > self::MAX_SIZE ) {
				error_log( '[BZCC-File] File too large: ' . filesize( $file_path ) . ' bytes' );
				continue;
			}

			$mime     = get_post_mime_type( $attachment_id );
			$max_rows = (int) ( $field['max_rows'] ?? self::DEFAULT_MAX_ROWS );

			error_log( '[BZCC-File] Processing "' . $slug . '" | mime=' . $mime . ' | path=' . basename( $file_path ) );

			$text = self::extract( $file_path, $mime, $max_rows );

			if ( $text ) {
				$results[ $slug ] = $text;
				error_log( '[BZCC-File] Field "' . $slug . '": ' . mb_strlen( $text ) . ' chars extracted' );
			}
		}

		return $results;
	}

	/**
	 * Process a single attachment. Used by the preview REST endpoint.
	 *
	 * @param int $attachment_id  WP attachment ID.
	 * @param int $max_rows       Max rows for CSV/Excel.
	 * @return array { success: bool, content: string, rows: int }
	 */
	public static function process_single( int $attachment_id, int $max_rows = 0 ): array {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return [ 'success' => false, 'content' => '', 'rows' => 0 ];
		}

		if ( filesize( $file_path ) > self::MAX_SIZE ) {
			return [ 'success' => false, 'content' => 'File quá lớn (tối đa 10MB)', 'rows' => 0 ];
		}

		$mime     = get_post_mime_type( $attachment_id );
		$max_rows = $max_rows > 0 ? $max_rows : self::DEFAULT_MAX_ROWS;
		$text     = self::extract( $file_path, $mime, $max_rows );

		// Count rows (rough: lines in output)
		$row_count = $text ? max( 0, substr_count( $text, "\n" ) - 1 ) : 0;

		return [
			'success' => ! empty( $text ),
			'content' => $text,
			'rows'    => $row_count,
		];
	}

	/**
	 * Extract text content from file based on MIME type.
	 */
	private static function extract( string $path, string $mime, int $max_rows ): string {
		// CSV
		if ( in_array( $mime, [ 'text/csv', 'application/csv' ], true ) || self::has_extension( $path, 'csv' ) ) {
			return self::parse_csv( $path, $max_rows );
		}

		// Excel (xlsx)
		if ( in_array( $mime, [
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-excel',
		], true ) || self::has_extension( $path, 'xlsx' ) || self::has_extension( $path, 'xls' ) ) {
			return self::parse_excel( $path, $max_rows );
		}

		// PDF — delegate to existing BizCity file processor if available
		if ( $mime === 'application/pdf' ) {
			if ( class_exists( 'BizCity_File_Processor' ) ) {
				$content = BizCity_File_Processor::instance()->extract_content( $path, $mime );
				return is_wp_error( $content ) ? '' : (string) $content;
			}
			return '';
		}

		return '';
	}

	/**
	 * Parse CSV file into markdown table.
	 */
	private static function parse_csv( string $path, int $max_rows ): string {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return '';
		}

		$rows  = [];
		$count = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false && $count < $max_rows ) {
			// Sanitize cell values
			$row = array_map( function ( $cell ) {
				return is_string( $cell ) ? sanitize_text_field( $cell ) : (string) $cell;
			}, $row );
			$rows[] = $row;
			$count++;
		}
		fclose( $handle );

		return self::rows_to_markdown( $rows );
	}

	/**
	 * Parse Excel via PhpSpreadsheet (if available) or fallback XML parser.
	 */
	private static function parse_excel( string $path, int $max_rows ): string {
		// Try PhpSpreadsheet first
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			try {
				$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
				$sheet       = $spreadsheet->getActiveSheet();
				$rows        = [];

				$highest_row = min( $sheet->getHighestRow(), $max_rows );
				foreach ( $sheet->getRowIterator( 1, $highest_row ) as $row ) {
					$cells = [];
					foreach ( $row->getCellIterator() as $cell ) {
						$cells[] = sanitize_text_field( (string) $cell->getValue() );
					}
					$rows[] = $cells;
				}

				return self::rows_to_markdown( $rows );
			} catch ( \Exception $e ) {
				error_log( '[BZCC-File] PhpSpreadsheet error: ' . $e->getMessage() );
			}
		}

		// Fallback: simple XML parse for .xlsx files
		if ( self::has_extension( $path, 'xlsx' ) ) {
			return self::parse_xlsx_simple( $path, $max_rows );
		}

		return '';
	}

	/**
	 * Convert row arrays to markdown table format.
	 */
	private static function rows_to_markdown( array $rows ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$lines = [];

		// Header row
		$header = array_shift( $rows );
		if ( empty( $header ) ) {
			return '';
		}

		$col_count = count( $header );
		$lines[]   = '| ' . implode( ' | ', $header ) . ' |';
		$lines[]   = '| ' . implode( ' | ', array_fill( 0, $col_count, '---' ) ) . ' |';

		// Data rows
		foreach ( $rows as $row ) {
			// Pad to match header column count
			$row     = array_pad( $row, $col_count, '' );
			$row     = array_slice( $row, 0, $col_count );
			$lines[] = '| ' . implode( ' | ', $row ) . ' |';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Simple .xlsx parser without PhpSpreadsheet.
	 * Reads sharedStrings.xml and sheet1.xml from the ZIP archive.
	 */
	private static function parse_xlsx_simple( string $path, int $max_rows ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			error_log( '[BZCC-File] ZipArchive not available for xlsx parsing' );
			return '';
		}

		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return '';
		}

		// Read shared strings
		$strings = [];
		$ss_xml  = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss_xml ) {
			// Disable external entity loading for security
			if ( PHP_MAJOR_VERSION < 8 ) { @libxml_disable_entity_loader( true ); }
			$xml    = simplexml_load_string( $ss_xml, 'SimpleXMLElement', LIBXML_NONET );
			if ( PHP_MAJOR_VERSION < 8 ) { @libxml_disable_entity_loader( false ); }

			if ( $xml ) {
				foreach ( $xml->si as $si ) {
					// Handle both simple <t> and rich text <r><t>
					$text = '';
					if ( isset( $si->t ) ) {
						$text = (string) $si->t;
					} elseif ( isset( $si->r ) ) {
						foreach ( $si->r as $r ) {
							$text .= (string) $r->t;
						}
					}
					$strings[] = sanitize_text_field( $text );
				}
			}
		}

		// Read first worksheet
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( ! $sheet_xml ) {
			return '';
		}

		if ( PHP_MAJOR_VERSION < 8 ) { @libxml_disable_entity_loader( true ); }
		$xml  = simplexml_load_string( $sheet_xml, 'SimpleXMLElement', LIBXML_NONET );
		if ( PHP_MAJOR_VERSION < 8 ) { @libxml_disable_entity_loader( false ); }

		if ( ! $xml || ! isset( $xml->sheetData->row ) ) {
			return '';
		}

		$rows  = [];
		$count = 0;
		foreach ( $xml->sheetData->row as $row ) {
			if ( $count >= $max_rows ) {
				break;
			}
			$cells = [];
			foreach ( $row->c as $c ) {
				$type = (string) ( $c['t'] ?? '' );
				$val  = (string) ( $c->v ?? '' );

				// t="s" → shared string reference
				if ( $type === 's' && isset( $strings[ (int) $val ] ) ) {
					$cells[] = $strings[ (int) $val ];
				} else {
					$cells[] = sanitize_text_field( $val );
				}
			}
			$rows[] = $cells;
			$count++;
		}

		return self::rows_to_markdown( $rows );
	}

	/**
	 * Check file extension.
	 */
	private static function has_extension( string $path, string $ext ): bool {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === strtolower( $ext );
	}

	/**
	 * Get the accept string for HTML file input based on accepted_types config.
	 *
	 * @param array $accepted_types  e.g. ['csv', 'xlsx', 'pdf']
	 * @return string  e.g. '.csv,.xlsx,.pdf'
	 */
	public static function get_accept_string( array $accepted_types ): string {
		$map = [
			'csv'  => '.csv',
			'xlsx' => '.xlsx,.xls',
			'xls'  => '.xlsx,.xls',
			'pdf'  => '.pdf',
		];

		$parts = [];
		foreach ( $accepted_types as $type ) {
			if ( isset( $map[ $type ] ) ) {
				$parts[] = $map[ $type ];
			}
		}

		return implode( ',', array_unique( $parts ) );
	}
}
