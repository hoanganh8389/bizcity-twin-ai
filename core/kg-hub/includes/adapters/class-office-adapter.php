<?php
/**
 * BizCity KG-Hub — Office Source Adapter (E1)
 *
 * Real text extractor for Microsoft Office files (DOCX/XLSX/PPTX) plus a
 * best-effort RTF stripper. All formats are parsed in pure PHP using
 * `ZipArchive` so we ship without composer / phpoffice deps.
 *
 * Supported MIMEs / extensions:
 *   - .docx, .docm  → application/vnd.openxmlformats-officedocument.wordprocessingml.document
 *   - .xlsx, .xlsm  → application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
 *   - .pptx, .pptm  → application/vnd.openxmlformats-officedocument.presentationml.presentation
 *   - .rtf          → application/rtf, text/rtf
 *
 * Tier gate (Phase 0.7 / Wave T0):
 *   - Free tier has quota `learning.office` = 10 files/day; paid = unlimited.
 *   - Gate via `BizCity_Entitlement::can($user_id, 'learning.office')` →
 *     returns `tier_required` (HTTP 402) when daily quota is exceeded.
 *   - Records usage `office_file` (amount=1, cost_usd=0) on success.
 *
 * Returns the canonical adapter shape:
 *   [ 'text', 'segments' (per-page / per-sheet / per-slide),
 *     'assets' [], 'modality' => 'office', 'meta' ]
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-05-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Office_Adapter implements BizCity_KG_Source_Adapter {

	const MIN_CHARS     = 1; // any extracted text counts as success
	const MAX_SHEETS    = 50;
	const MAX_SLIDES    = 200;
	const MAX_DOC_BYTES = 25 * 1024 * 1024; // 25MB hard cap on raw upload

	const SUPPORTED_EXTS  = [ 'docx', 'docm', 'xlsx', 'xlsm', 'pptx', 'pptm', 'rtf' ];
	const SUPPORTED_MIMES = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-word.document.macroenabled.12',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel.sheet.macroenabled.12',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.ms-powerpoint.presentation.macroenabled.12',
		'application/rtf',
		'text/rtf',
	];

	public static function id() {
		return 'office';
	}

	public static function supports( $ext, $mime ) {
		if ( in_array( $ext, self::SUPPORTED_EXTS, true ) ) return true;
		if ( in_array( $mime, self::SUPPORTED_MIMES, true ) ) return true;
		return false;
	}

	/**
	 * Extract text from an office document.
	 *
	 * @param string $file_path
	 * @param array  $opts  Recognized: skip_tier_gate (bool), skip_record_usage (bool)
	 * @return array|WP_Error
	 */
	public function extract( $file_path, array $opts ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error( 'office_file_missing', 'Office file not found at expected path' );
		}
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'office_file_unreadable', 'Office file is not readable' );
		}
		$size = (int) @filesize( $file_path );
		if ( $size > self::MAX_DOC_BYTES ) {
			return new WP_Error(
				'office_file_too_large',
				sprintf( 'Office file too large (%dMB > %dMB cap)', $size >> 20, self::MAX_DOC_BYTES >> 20 ),
				[ 'http_status' => 413 ]
			);
		}

		// Tier / quota gate (T0). Skippable for diagnostics / internal callers.
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( empty( $opts['skip_tier_gate'] ) && class_exists( 'BizCity_Entitlement' ) ) {
			if ( ! BizCity_Entitlement::can( $user_id, 'learning.office' ) ) {
				// Wave T2 — record blocked attempt for upgrade-conversion analytics.
				if ( method_exists( 'BizCity_Entitlement', 'record_blocked' ) ) {
					BizCity_Entitlement::record_blocked( $user_id, [
						'code'        => 'tier_required',
						'modality'    => 'learning.office',
						'feature'     => 'learning.office',
						'plugin_name' => 'bizcity-twin-ai',
					] );
				}
				return new WP_Error(
					'tier_required',
					'Daily office-document quota exceeded. Upgrade to Pro for unlimited DOCX/XLSX/PPTX learning.',
					[ 'status' => 402, 'feature' => 'learning.office' ]
				);
			}
		}

		// Detect actual format from header bytes (don't trust the extension blindly).
		$kind = self::detect_kind( $file_path );
		if ( ! $kind ) {
			return new WP_Error( 'office_unknown_format', 'Could not detect office file format from header bytes.' );
		}

		switch ( $kind ) {
			case 'docx':
				$result = self::extract_docx( $file_path );
				break;
			case 'xlsx':
				$result = self::extract_xlsx( $file_path );
				break;
			case 'pptx':
				$result = self::extract_pptx( $file_path );
				break;
			case 'rtf':
				$result = self::extract_rtf( $file_path );
				break;
			default:
				return new WP_Error( 'office_unsupported_kind', 'Unsupported office kind: ' . $kind );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text  = isset( $result['text'] ) ? (string) $result['text'] : '';
		$chars = strlen( $text );
		if ( $chars < self::MIN_CHARS ) {
			return new WP_Error(
				'office_extract_empty',
				'No text extracted from office file (file may be image-only or password-protected).',
				[ 'http_status' => 422, 'kind' => $kind ]
			);
		}

		// Record usage (T0 — entitlement counter, 1 file = 1 unit, no embed cost yet).
		if ( class_exists( 'BizCity_Entitlement' ) && $user_id > 0 && empty( $opts['skip_record_usage'] ) ) {
			BizCity_Entitlement::record_usage( $user_id, 'office_file', 1, [
				'cost_usd'   => 0.0,
				'source'     => 'kg_office_adapter',
				'capability' => 'office',
				'kind'       => $kind,
				'bytes'      => $size,
			] );
		}

		$segments = isset( $result['segments'] ) && is_array( $result['segments'] ) ? $result['segments'] : [];
		$meta     = isset( $result['meta'] ) && is_array( $result['meta'] ) ? $result['meta'] : [];
		$meta['kind']        = $kind;
		$meta['total_chars'] = $chars;
		$meta['bytes']       = $size;

		return [
			'text'     => $text,
			'segments' => $segments,
			'assets'   => [],
			'modality' => 'office',
			'meta'     => $meta,
		];
	}

	/* ───────────────────────  Format detection  ─────────────────────── */

	/**
	 * Read the first ~8 bytes to differentiate ZIP-based OOXML vs RTF vs unknown.
	 * Then for OOXML, peek inside the zip to choose docx/xlsx/pptx.
	 */
	private static function detect_kind( $file_path ) {
		$fh = @fopen( $file_path, 'rb' );
		if ( ! $fh ) return null;
		$head = (string) fread( $fh, 8 );
		fclose( $fh );

		// RTF: starts with `{\rtf`
		if ( strncmp( $head, '{\\rtf', 5 ) === 0 ) {
			return 'rtf';
		}
		// OOXML: ZIP local-file header `PK\x03\x04`
		if ( strncmp( $head, "PK\x03\x04", 4 ) !== 0 ) {
			return null;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) return null;
		$has_docx = $zip->locateName( 'word/document.xml' )    !== false;
		$has_xlsx = $zip->locateName( 'xl/workbook.xml' )      !== false;
		$has_pptx = $zip->locateName( 'ppt/presentation.xml' ) !== false;
		$zip->close();
		if ( $has_docx ) return 'docx';
		if ( $has_xlsx ) return 'xlsx';
		if ( $has_pptx ) return 'pptx';
		return null;
	}

	/* ───────────────────────  DOCX  ─────────────────────── */

	private static function extract_docx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'office_zip_missing', 'PHP ZipArchive extension required for DOCX extraction.' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return new WP_Error( 'office_zip_open_failed', 'Could not open DOCX (zip) container.' );
		}

		// Pull main document + any header*.xml / footer*.xml.
		$parts = [ 'word/document.xml' ];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#^word/(header|footer)\d+\.xml$#', $name ) ) {
				$parts[] = $name;
			}
		}

		$paragraphs = [];
		foreach ( $parts as $part ) {
			$xml = $zip->getFromName( $part );
			if ( $xml === false || $xml === '' ) continue;
			$paragraphs = array_merge( $paragraphs, self::docx_paragraphs_from_xml( $xml ) );
		}
		$zip->close();

		if ( empty( $paragraphs ) ) {
			return new WP_Error( 'office_docx_empty', 'DOCX contained no paragraph text.' );
		}

		$text = implode( "\n\n", $paragraphs );
		return [
			'text'     => $text,
			'segments' => [
				[ 'page_num' => 1, 'text' => $text ],
			],
			'meta'     => [
				'paragraphs' => count( $paragraphs ),
				'parts'      => count( $parts ),
			],
		];
	}

	/**
	 * Extract paragraphs from a `word/document.xml`-style XML blob.
	 * One paragraph per `<w:p>`; tabs preserved; line breaks (`<w:br/>`) kept.
	 */
	private static function docx_paragraphs_from_xml( $xml ) {
		$chunks = preg_split( '#</w:p>#u', $xml );
		$out    = [];
		foreach ( $chunks as $chunk ) {
			if ( strpos( $chunk, '<w:p' ) === false ) continue;
			$chunk = preg_replace( '#<w:br\s*/>#u', "\n", $chunk );
			$chunk = preg_replace( '#<w:tab\s*/>#u', "\t", $chunk );
			if ( preg_match_all( '#<w:t[^>]*>(.*?)</w:t>#us', $chunk, $m ) ) {
				$line = '';
				foreach ( $m[1] as $piece ) {
					$line .= self::xml_decode( $piece );
				}
				$line = trim( $line );
				if ( $line !== '' ) $out[] = $line;
			}
		}
		return $out;
	}

	/* ───────────────────────  XLSX  ─────────────────────── */

	private static function extract_xlsx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'office_zip_missing', 'PHP ZipArchive extension required for XLSX extraction.' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return new WP_Error( 'office_zip_open_failed', 'Could not open XLSX (zip) container.' );
		}

		$shared = [];
		$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( is_string( $ss_xml ) && $ss_xml !== '' ) {
			$shared = self::xlsx_parse_shared_strings( $ss_xml );
		}

		$sheet_index = self::xlsx_resolve_sheet_paths( $zip );
		if ( empty( $sheet_index ) ) {
			$zip->close();
			return new WP_Error( 'office_xlsx_no_sheets', 'XLSX has no sheets.' );
		}

		$lines    = [];
		$segments = [];
		$count    = 0;
		foreach ( $sheet_index as $entry ) {
			if ( $count >= self::MAX_SHEETS ) break;
			$count++;
			$path = $entry['path'];
			$name = $entry['name'];
			$xml  = $zip->getFromName( $path );
			if ( ! is_string( $xml ) || $xml === '' ) continue;
			$rows = self::xlsx_parse_sheet_rows( $xml, $shared );
			if ( empty( $rows ) ) continue;

			$lines[] = '[Sheet ' . $count . ': ' . $name . ']';
			$body    = '';
			foreach ( $rows as $row ) {
				$line    = implode( "\t", $row );
				$lines[] = $line;
				$body   .= $line . "\n";
			}
			$lines[]    = '';
			$segments[] = [
				'page_num'   => $count,
				'sheet_name' => $name,
				'text'       => trim( $body ),
			];
		}
		$zip->close();

		if ( empty( $segments ) ) {
			return new WP_Error( 'office_xlsx_empty', 'XLSX contained no cell data.' );
		}

		return [
			'text'     => implode( "\n", $lines ),
			'segments' => $segments,
			'meta'     => [
				'sheets'         => count( $segments ),
				'shared_strings' => count( $shared ),
			],
		];
	}

	private static function xlsx_parse_shared_strings( $xml ) {
		$out = [];
		if ( preg_match_all( '#<si\b[^>]*>(.*?)</si>#us', $xml, $m ) ) {
			foreach ( $m[1] as $blob ) {
				$buf = '';
				if ( preg_match_all( '#<t[^>]*>(.*?)</t>#us', $blob, $tm ) ) {
					foreach ( $tm[1] as $piece ) {
						$buf .= self::xml_decode( $piece );
					}
				}
				$out[] = $buf;
			}
		}
		return $out;
	}

	/**
	 * Map sheet display-name → underlying xl/worksheets/sheetN.xml path via rels.
	 *
	 * @return array<int,array{name:string,path:string}>
	 */
	private static function xlsx_resolve_sheet_paths( ZipArchive $zip ) {
		$wb_xml = $zip->getFromName( 'xl/workbook.xml' );
		if ( ! is_string( $wb_xml ) || $wb_xml === '' ) return [];
		$rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		$sheets = [];
		// 2026-05-20 — accept BOTH self-closed `<sheet ... />` and paired
		// `<sheet ...></sheet>` forms; some spreadsheet apps (Office 365,
		// LibreOffice Calc, Google Sheets export) emit one or the other.
		// Match `sheet` with optional XML namespace prefix (e.g. `x:sheet`).
		if ( preg_match_all( '#<(?:[a-zA-Z0-9]+:)?sheet\b[^>]*?/?>#us', $wb_xml, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$name = ''; $rid = '';
				if ( preg_match( '#\bname="([^"]*)"#u', $tag, $nm ) ) $name = self::xml_decode( $nm[1] );
				// `r:id` is canonical but some emitters use bare `rId` or namespace-prefixed.
				if ( preg_match( '#\b(?:[a-zA-Z0-9]+:)?id="(rId[^"]*)"#u', $tag, $rm ) ) {
					$rid = $rm[1];
				} elseif ( preg_match( '#\bsheetId="([^"]*)"#u', $tag, $sm ) ) {
					// Fall back to sheetId-based path resolution below.
					$rid = 'sheet' . $sm[1];
				}
				if ( $name !== '' || $rid !== '' ) {
					$sheets[] = [ 'name' => $name, 'rid' => $rid ];
				}
			}
		}
		if ( empty( $sheets ) ) return [];

		$rid_map = [];
		if ( is_string( $rels_xml ) && $rels_xml !== '' ) {
			if ( preg_match_all( '#<Relationship\b[^/>]*?/>#us', $rels_xml, $rm ) ) {
				foreach ( $rm[0] as $tag ) {
					$id = ''; $target = '';
					if ( preg_match( '#\bId="([^"]*)"#u', $tag, $im ) )      $id     = $im[1];
					if ( preg_match( '#\bTarget="([^"]*)"#u', $tag, $tm2 ) ) $target = $tm2[1];
					if ( $id && $target ) $rid_map[ $id ] = $target;
				}
			}
		}

		$out = [];
		foreach ( $sheets as $s ) {
			$target = isset( $rid_map[ $s['rid'] ] ) ? $rid_map[ $s['rid'] ] : '';
			// Fallback — if rels lookup failed (corrupted / minimal XLSX), try
			// the conventional path `xl/worksheets/sheet{n}.xml` directly.
			if ( $target === '' && preg_match( '#^sheet(\d+)$#', (string) $s['rid'], $sm ) ) {
				$target = 'worksheets/sheet' . $sm[1] . '.xml';
			}
			if ( $target === '' ) continue;
			// workbook.xml is at xl/, so 'worksheets/sheet1.xml' → 'xl/worksheets/sheet1.xml'.
			$path = ( strncmp( $target, '/', 1 ) === 0 ) ? ltrim( $target, '/' ) : 'xl/' . ltrim( $target, '/' );
			$out[] = [ 'name' => $s['name'] !== '' ? $s['name'] : 'Sheet', 'path' => $path ];
		}
		// Last-resort — walk every `xl/worksheets/sheet*.xml` entry in the zip.
		if ( empty( $out ) ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$nm = (string) $zip->getNameIndex( $i );
				if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#i', $nm ) ) {
					$out[] = [ 'name' => basename( $nm, '.xml' ), 'path' => $nm ];
				}
			}
		}
		return $out;
	}

	private static function xlsx_parse_sheet_rows( $xml, array $shared ) {
		$rows = [];
		if ( ! preg_match_all( '#<row\b[^>]*>(.*?)</row>#us', $xml, $rm ) ) {
			return $rows;
		}
		foreach ( $rm[1] as $row_xml ) {
			$cells = [];
			if ( preg_match_all( '#<c\b([^>]*)>(.*?)</c>#us', $row_xml, $cm, PREG_SET_ORDER ) ) {
				foreach ( $cm as $hit ) {
					$attrs = $hit[1];
					$body  = $hit[2];
					$type  = '';
					if ( preg_match( '#\bt="([^"]*)"#u', $attrs, $tm ) ) $type = $tm[1];

					$value = '';
					if ( $type === 'inlineStr' ) {
						if ( preg_match_all( '#<t[^>]*>(.*?)</t>#us', $body, $im ) ) {
							foreach ( $im[1] as $piece ) $value .= self::xml_decode( $piece );
						}
					} else {
						if ( preg_match( '#<v>(.*?)</v>#us', $body, $vm ) ) {
							$raw = self::xml_decode( $vm[1] );
							if ( $type === 's' ) {
								$idx   = (int) $raw;
								$value = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
							} else {
								$value = $raw;
							}
						}
					}
					$cells[] = $value;
				}
			}
			while ( ! empty( $cells ) && $cells[ count( $cells ) - 1 ] === '' ) {
				array_pop( $cells );
			}
			if ( ! empty( $cells ) ) $rows[] = $cells;
		}
		return $rows;
	}

	/* ───────────────────────  PPTX  ─────────────────────── */

	private static function extract_pptx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'office_zip_missing', 'PHP ZipArchive extension required for PPTX extraction.' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return new WP_Error( 'office_zip_open_failed', 'Could not open PPTX (zip) container.' );
		}

		$slides = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#^ppt/slides/slide(\d+)\.xml$#', $name, $m ) ) {
				$slides[ (int) $m[1] ] = $name;
			}
		}
		ksort( $slides, SORT_NUMERIC );

		$lines    = [];
		$segments = [];
		$count    = 0;
		foreach ( $slides as $slide_num => $part ) {
			if ( $count >= self::MAX_SLIDES ) break;
			$count++;
			$xml = $zip->getFromName( $part );
			if ( ! is_string( $xml ) || $xml === '' ) continue;
			$texts = [];
			if ( preg_match_all( '#<a:t[^>]*>(.*?)</a:t>#us', $xml, $tm ) ) {
				foreach ( $tm[1] as $piece ) {
					$piece = trim( self::xml_decode( $piece ) );
					if ( $piece !== '' ) $texts[] = $piece;
				}
			}
			if ( empty( $texts ) ) continue;
			$body       = implode( "\n", $texts );
			$lines[]    = '[Slide ' . $slide_num . ']';
			$lines[]    = $body;
			$lines[]    = '';
			$segments[] = [
				'page_num' => $slide_num,
				'text'     => $body,
			];
		}
		$zip->close();

		if ( empty( $segments ) ) {
			return new WP_Error( 'office_pptx_empty', 'PPTX contained no slide text (image-only deck?).' );
		}

		return [
			'text'     => implode( "\n", $lines ),
			'segments' => $segments,
			'meta'     => [ 'slides' => count( $segments ) ],
		];
	}

	/* ───────────────────────  RTF  ─────────────────────── */

	/**
	 * Minimalist RTF stripper. Handles: control words, control symbols,
	 * Unicode escapes (\uNNNN?), groups, and common escapes (\par, \tab).
	 * Does NOT decode font tables / pictures — body text only.
	 */
	private static function extract_rtf( $file_path ) {
		$raw = @file_get_contents( $file_path );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return new WP_Error( 'office_rtf_empty', 'RTF file empty or unreadable.' );
		}

		$txt = $raw;
		$txt = preg_replace( '#\\\\par[d]?\b#u', "\n", $txt );
		$txt = preg_replace( '#\\\\line\b#u', "\n", $txt );
		$txt = preg_replace( '#\\\\tab\b#u', "\t", $txt );

		// Decode Unicode escapes: \u1234? (the trailing '?' is the fallback char)
		$txt = preg_replace_callback(
			'#\\\\u(-?\d+)\??#u',
			function ( $m ) {
				$cp = (int) $m[1];
				if ( $cp < 0 ) $cp += 65536;
				return self::utf8_chr( $cp );
			},
			$txt
		);

		// Hex escapes \'XX → byte
		$txt = preg_replace_callback(
			"#\\\\'([0-9a-fA-F]{2})#",
			function ( $m ) {
				return chr( hexdec( $m[1] ) );
			},
			$txt
		);

		// Drop remaining control words: \word or \word123
		$txt = preg_replace( '#\\\\[a-zA-Z]+-?\d*\s?#', '', $txt );
		// Drop control symbols: \\ \{ \}
		$txt = preg_replace( '#\\\\[^a-zA-Z]#', '', $txt );
		// Drop braces
		$txt = str_replace( [ '{', '}' ], '', $txt );

		$txt = preg_replace( "#[ \t]+#u", ' ', $txt );
		$txt = preg_replace( "#\n{3,}#u", "\n\n", $txt );
		$txt = trim( $txt );

		if ( $txt === '' ) {
			return new WP_Error( 'office_rtf_empty', 'RTF body produced no text after stripping.' );
		}

		return [
			'text'     => $txt,
			'segments' => [
				[ 'page_num' => 1, 'text' => $txt ],
			],
			'meta'     => [ 'bytes' => strlen( $raw ) ],
		];
	}

	/* ───────────────────────  Helpers  ─────────────────────── */

	private static function xml_decode( $s ) {
		// Office files use &amp; &lt; &gt; &quot; &apos; — handle both layers
		// (some producers double-encode).
		return html_entity_decode(
			html_entity_decode( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			ENT_QUOTES | ENT_XML1,
			'UTF-8'
		);
	}

	/**
	 * Build a UTF-8 character from a code point (without ext-mbstring/iconv).
	 */
	private static function utf8_chr( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) )
			. chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $cp & 0x3F ) );
	}
}
