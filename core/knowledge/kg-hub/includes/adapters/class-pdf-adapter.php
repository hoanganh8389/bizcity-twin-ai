<?php
/**
 * BizCity KG-Hub — PDF Source Adapter (E2.PRIORITY)
 *
 * Tier-1: text-layer extraction. Tier-2/3 (local Tesseract / Vision OCR) ship
 * in Wave E2.SCAN once /tools/ocr endpoint is live (E0).
 *
 * Tier-1 strategy (best → worst, first that yields >50 chars wins):
 *   A. Smalot\PdfParser (composer-installed)        — preferred when present
 *   B. Shell `pdftotext` (poppler-utils)            — Linux servers w/ binary
 *   C. Built-in stream parser                        — last-resort regex pass over
 *      uncompressed PDF text streams, good enough for many text-layer PDFs
 *      that don't FlateDecode (which is rare for native exports anyway)
 *
 * Returns the canonical adapter shape:
 *   [ 'text', 'segments' (per-page), 'assets' [], 'modality' => 'pdf_text', 'meta' ]
 *
 * If all 3 tiers yield empty text → returns WP_Error('pdf_extract_empty')
 * which the caller may translate into a 422 + suggestion to upgrade to Pro
 * (scan PDF requires OCR, Wave E2.SCAN).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-05-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Pdf_Adapter implements BizCity_KG_Source_Adapter {

	const MIN_TIER1_CHARS = 50; // threshold below which we declare "scan PDF"

	public static function id() {
		return 'pdf';
	}

	public static function supports( $ext, $mime ) {
		if ( $ext === 'pdf' ) return true;
		if ( $mime === 'application/pdf' ) return true;
		if ( $mime === 'application/x-pdf' ) return true;
		return false;
	}

	public function extract( $file_path, array $opts ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error( 'pdf_file_missing', 'PDF file not found at expected path' );
		}
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'pdf_file_unreadable', 'PDF file is not readable' );
		}

		$strategy = '';
		$pages    = []; // [ page_num => text ]

		// Strategy A — Smalot
		if ( class_exists( '\\Smalot\\PdfParser\\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $file_path );
				$pgs    = $pdf->getPages();
				foreach ( $pgs as $i => $p ) {
					$txt = trim( (string) $p->getText() );
					$pages[ $i + 1 ] = $txt;
				}
				$strategy = 'smalot';
			} catch ( \Throwable $e ) {
				$pages    = [];
				$strategy = '';
			}
		}

		// Strategy B — pdftotext (shell)
		if ( ! $pages && self::is_shell_pdftotext_available() ) {
			$pages    = self::extract_with_pdftotext( $file_path );
			$strategy = $pages ? 'pdftotext' : '';
		}

		// Strategy C — built-in stream regex
		if ( ! $pages ) {
			$pages    = self::extract_with_builtin( $file_path );
			$strategy = $pages ? 'builtin' : '';
		}

		// Aggregate
		$text_total = '';
		$segments   = [];
		$total_chars = 0;
		foreach ( $pages as $page_num => $txt ) {
			$txt = self::cleanup_text( (string) $txt );
			$total_chars += strlen( $txt );
			$segments[]   = [
				'page_num' => (int) $page_num,
				'text'     => $txt,
			];
			if ( $txt !== '' ) {
				$text_total .= "\n\n[Page {$page_num}]\n" . $txt;
			}
		}
		$text_total = trim( $text_total );

		if ( $total_chars < self::MIN_TIER1_CHARS ) {
			// Tier-1 text-layer empty → likely a scan PDF. Try Tier-2 OCR fallback (E2.SCAN).
			$ocr_result = self::try_ocr_fallback( $file_path, $opts );
			if ( ! is_wp_error( $ocr_result ) && is_array( $ocr_result ) ) {
				return $ocr_result;
			}
			// Tier gate: pass tier_required through directly so the REST layer returns 402.
			if ( is_wp_error( $ocr_result ) && $ocr_result->get_error_code() === 'tier_required' ) {
				return $ocr_result;
			}
			// OCR not available / disabled / failed — return informative error.
			$ocr_err_code = is_wp_error( $ocr_result ) ? $ocr_result->get_error_code() : 'ocr_unavailable';
			$ocr_err_msg  = is_wp_error( $ocr_result ) ? $ocr_result->get_error_message() : 'OCR fallback not available.';
			return new WP_Error(
				'pdf_extract_empty',
				'No selectable text in PDF — OCR fallback also failed: ' . $ocr_err_msg,
				[
					'http_status'      => 422,
					'strategy_tried'   => $strategy ?: 'none',
					'page_count'       => count( $pages ),
					'requires_feature' => 'learning.pdf_scan',
					'ocr_error_code'   => $ocr_err_code,
				]
			);
		}

		return [
			'text'     => $text_total,
			'segments' => $segments,
			'assets'   => [],
			'modality' => 'pdf_text',
			'meta'     => [
				'page_count'    => count( $pages ),
				'strategy'      => $strategy,
				'total_chars'   => $total_chars,
				'avg_per_page'  => count( $pages ) > 0 ? (int) ( $total_chars / count( $pages ) ) : 0,
			],
		];
	}

	/* ──────────────────────  Strategy B: pdftotext  ────────────────────── */

	private static function is_shell_pdftotext_available() {
		// Don't try shell on hostile environments.
		if ( ! function_exists( 'shell_exec' ) ) return false;
		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );
		if ( in_array( 'shell_exec', $disabled, true ) ) return false;
		// Cheap probe — cached for the request.
		static $available = null;
		if ( $available !== null ) return $available;
		$cmd = ( PHP_OS_FAMILY === 'Windows' ) ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null';
		$out = @shell_exec( $cmd );
		$available = ! empty( trim( (string) $out ) );
		return $available;
	}

	private static function extract_with_pdftotext( $file_path ) {
		$tmp = wp_tempnam( 'bizcity-pdftotext-' );
		if ( ! $tmp ) return [];
		// -layout preserves column order; -enc UTF-8 forces utf8.
		$cmd = sprintf(
			'pdftotext -layout -enc UTF-8 %s %s 2>&1',
			escapeshellarg( $file_path ),
			escapeshellarg( $tmp )
		);
		@shell_exec( $cmd );
		if ( ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) {
			@unlink( $tmp );
			return [];
		}
		$raw = (string) file_get_contents( $tmp );
		@unlink( $tmp );
		if ( $raw === '' ) return [];
		// pdftotext separates pages with form-feed (\f). Split into pages.
		$parts = explode( "\f", $raw );
		$out = [];
		foreach ( $parts as $i => $chunk ) {
			$chunk = trim( $chunk );
			if ( $chunk === '' ) continue;
			$out[ $i + 1 ] = $chunk;
		}
		return $out;
	}

	/* ──────────────────────  Strategy C: built-in regex  ────────────────────── */

	/**
	 * Last-resort PDF text extractor — scans uncompressed text-showing
	 * operators (Tj / TJ) inside content streams. Works for many
	 * native-exported PDFs (Word/LibreOffice/Pages) where streams aren't
	 * FlateDecode-compressed. Definitely will MISS:
	 *   - Compressed (FlateDecode) streams without zlib decoding
	 *   - Scanned PDFs (no text operators)
	 *   - Encrypted PDFs
	 * That's acceptable as a Tier-1 best-effort; failures bubble up to OCR.
	 */
	private static function extract_with_builtin( $file_path ) {
		$raw = @file_get_contents( $file_path );
		if ( $raw === false || $raw === '' ) return [];

		// Split by `endobj` and look at content streams between `stream` ... `endstream`.
		// Then attempt zlib decompress; on failure use raw bytes.
		$pages   = [];
		$page_no = 0;

		// Crude page split: many PDFs delimit pages with `/Type /Page` markers.
		// Use stream ... endstream scan instead for robustness.
		if ( preg_match_all( '/stream\r?\n(.+?)\r?\nendstream/s', $raw, $m ) ) {
			foreach ( $m[1] as $i => $stream ) {
				$decoded = @gzuncompress( $stream );
				if ( $decoded === false ) {
					// Try inflate (raw deflate without zlib header)
					$decoded = @gzinflate( $stream );
				}
				$body = $decoded !== false ? $decoded : $stream;
				$txt  = self::pull_tj_text( $body );
				if ( $txt !== '' ) {
					$page_no++;
					$pages[ $page_no ] = $txt;
				}
			}
		}
		return $pages;
	}

	/**
	 * Pull text from PDF content stream by collecting Tj / TJ operands.
	 * Heuristic — adequate for ASCII/Latin text, partial for embedded fonts
	 * with custom encoding (where glyphs aren't valid UTF-8 codepoints).
	 */
	private static function pull_tj_text( $body ) {
		$out = '';
		// Tj operator: ( ... ) Tj  — string literal in parens
		if ( preg_match_all( '/\(((?:\\\\.|[^\\\\\)])*)\)\s*Tj/s', $body, $m ) ) {
			foreach ( $m[1] as $s ) {
				$out .= self::unescape_pdf_literal( $s ) . ' ';
			}
		}
		// TJ operator: [ (a) 1 (b) ] TJ — array of strings + kerning ints
		if ( preg_match_all( '/\[((?:[^\[\]]|\[[^\]]*\])+?)\]\s*TJ/s', $body, $m ) ) {
			foreach ( $m[1] as $arr ) {
				if ( preg_match_all( '/\(((?:\\\\.|[^\\\\\)])*)\)/s', $arr, $sm ) ) {
					foreach ( $sm[1] as $s ) {
						$out .= self::unescape_pdf_literal( $s );
					}
					$out .= ' ';
				}
			}
		}
		return trim( $out );
	}

	private static function unescape_pdf_literal( $s ) {
		$s = str_replace(
			[ '\\\\', '\\(', '\\)', '\\n', '\\r', '\\t', '\\b', '\\f' ],
			[ '\\',   '(',   ')',   "\n",  "\r",  "\t",  "\x08", "\x0C" ],
			$s
		);
		// Octal escapes \ddd → byte
		$s = preg_replace_callback( '/\\\\([0-7]{1,3})/', function ( $m ) {
			return chr( octdec( $m[1] ) );
		}, $s );
		// Strip non-printable control bytes that PDF custom encodings may leave behind.
		$s = preg_replace( '/[\x00-\x08\x0B\x0E-\x1F]/', '', (string) $s );
		return (string) $s;
	}

	/* ──────────────────────  Common  ────────────────────── */

	private static function cleanup_text( $txt ) {
		// Normalize whitespace and strip soft-hyphen artifacts.
		$txt = str_replace( "\xC2\xAD", '', $txt ); // soft hyphen
		$txt = preg_replace( '/[ \t]+/', ' ', $txt );
		$txt = preg_replace( '/\n{3,}/', "\n\n", $txt );
		return trim( (string) $txt );
	}

	/* ──────────────────────  Tier-2: OCR fallback (E2.SCAN)  ────────────────────── */

	/**
	 * When Tier-1 text-layer extraction yields <50 chars, attempt OCR fallback
	 * by rasterizing PDF pages → PNG images → /tools/ocr (Vision LLM).
	 *
	 * Requires:
	 *   - BizCity_OCR_Client class (kg-hub clients)
	 *   - Imagick extension OR ghostscript (`gs`) shell binary on PATH
	 *   - User's gateway has tier ≥ paid (enforced server-side by /tools/ocr)
	 *
	 * Disable per-call via $opts['skip_ocr'] = true.
	 * Disable globally via add_filter('bizcity_pdf_ocr_enabled', '__return_false').
	 *
	 * @return array|WP_Error  Canonical adapter shape on success.
	 */
	private static function try_ocr_fallback( $file_path, array $opts ) {
		// 1) Per-call opt-out
		if ( ! empty( $opts['skip_ocr'] ) ) {
			return new WP_Error( 'ocr_skipped', 'OCR fallback skipped via opts.' );
		}

		// 2) Global filter gate
		$enabled = apply_filters( 'bizcity_pdf_ocr_enabled', true, $file_path, $opts );
		if ( ! $enabled ) {
			return new WP_Error( 'ocr_disabled', 'OCR fallback disabled by filter.' );
		}

		// 2b) Entitlement gate — scan-PDF OCR is a paid feature (T0+T2 gate)
		// Prefer the unified BizCity_Entitlement service; fall back to legacy tier check.
		$user_id = get_current_user_id();
		if ( class_exists( 'BizCity_Entitlement' ) ) {
			if ( ! BizCity_Entitlement::can( $user_id, 'learning.pdf_scan' ) ) {
				if ( method_exists( 'BizCity_Entitlement', 'record_blocked' ) ) {
					BizCity_Entitlement::record_blocked( $user_id, [
						'code'        => 'tier_required',
						'modality'    => 'learning.pdf_scan',
						'feature'     => 'learning.pdf_scan',
						'plugin_name' => 'bizcity-twin-ai',
					] );
				}
				return new WP_Error(
					'tier_required',
					'Scan PDF OCR requires a paid plan. Please upgrade to continue.',
					[ 'status' => 402, 'feature' => 'learning.pdf_scan' ]
				);
			}
		} elseif ( class_exists( 'BizCity_Router_Auth' ) ) {
			$tier = BizCity_Router_Auth::get_user_tier( $user_id );
			if ( 'free' === $tier ) {
				if ( class_exists( 'BizCity_Entitlement' ) && method_exists( 'BizCity_Entitlement', 'record_blocked' ) ) {
					BizCity_Entitlement::record_blocked( $user_id, [
						'code'        => 'tier_required',
						'modality'    => 'learning.pdf_scan',
						'feature'     => 'pdf_ocr',
						'plugin_name' => 'bizcity-twin-ai',
					] );
				}
				return new WP_Error(
					'tier_required',
					'Scan PDF OCR requires a paid plan. Please upgrade to continue.',
					[ 'status' => 402, 'tier' => $tier, 'feature' => 'pdf_ocr' ]
				);
			}
		}

		// 3) Client availability
		if ( ! class_exists( 'BizCity_OCR_Client' ) ) {
			return new WP_Error( 'ocr_client_missing', 'BizCity_OCR_Client class not loaded.' );
		}
		$client = BizCity_OCR_Client::instance();
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'ocr_not_configured', 'OCR gateway URL or API key not configured.' );
		}

		// 4) Rasterize pages → PNG paths
		$max_pages = isset( $opts['ocr_max_pages'] ) ? max( 1, intval( $opts['ocr_max_pages'] ) ) : 20;
		$dpi       = isset( $opts['ocr_dpi'] )       ? max( 72, min( 300, intval( $opts['ocr_dpi'] ) ) ) : 150;

		$page_paths = self::rasterize_pdf_pages( $file_path, $max_pages, $dpi );
		if ( is_wp_error( $page_paths ) ) {
			return $page_paths;
		}
		if ( empty( $page_paths ) ) {
			return new WP_Error( 'ocr_rasterize_empty', 'PDF rasterization produced no pages.' );
		}

		// 5) OCR each page
		$ocr_opts = [
			'lang'        => isset( $opts['ocr_lang'] )        ? (string) $opts['ocr_lang']        : 'auto',
			'prompt_hint' => isset( $opts['ocr_prompt_hint'] ) ? (string) $opts['ocr_prompt_hint'] : '',
			'timeout'     => isset( $opts['ocr_timeout'] )     ? max( 30, intval( $opts['ocr_timeout'] ) ) : 90,
		];
		$ocr = $client->ocr_pdf_pages( $page_paths, $ocr_opts );

		// 6) Cleanup tmp images
		foreach ( $page_paths as $p ) { @unlink( $p ); }

		if ( empty( $ocr['segments'] ) || $ocr['total_chars'] === 0 ) {
			return new WP_Error( 'ocr_empty_result', 'OCR returned no text.', [ 'errors' => $ocr['errors'] ?? [] ] );
		}

		// 6b) Record usage (T0 — entitlement counter, per page)
		if ( class_exists( 'BizCity_Entitlement' ) && $user_id > 0 ) {
			$pages_done = count( $ocr['segments'] );
			BizCity_Entitlement::record_usage( $user_id, 'ocr_page', $pages_done, [
				'cost_usd'   => (float) ( $ocr['total_cost_usd'] ?? 0.0 ),
				'source'     => 'kg_pdf_adapter',
				'capability' => 'ocr',
			] );
		}

		// 7) Build canonical response
		$text_total = '';
		foreach ( $ocr['segments'] as $seg ) {
			if ( ! empty( $seg['text'] ) ) {
				$text_total .= "\n\n[Page {$seg['page_num']}]\n" . $seg['text'];
			}
		}
		$text_total = trim( $text_total );

		return [
			'text'     => $text_total,
			'segments' => $ocr['segments'],
			'assets'   => [],
			'modality' => 'pdf_scan',
			'meta'     => [
				'page_count'       => intval( $ocr['page_count'] ?? count( $ocr['segments'] ) ),
				'strategy'         => 'ocr_vision',
				'total_chars'      => intval( $ocr['total_chars'] ?? 0 ),
				'avg_per_page'     => count( $ocr['segments'] ) > 0
					? (int) ( intval( $ocr['total_chars'] ?? 0 ) / count( $ocr['segments'] ) )
					: 0,
				'ocr_cost_usd'     => (float) ( $ocr['total_cost_usd'] ?? 0.0 ),
				'ocr_latency_ms'   => intval( $ocr['total_latency_ms'] ?? 0 ),
				'ocr_errors_count' => count( $ocr['errors'] ?? [] ),
				'rasterizer'       => self::detect_rasterizer(),
				'dpi'              => $dpi,
			],
		];
	}

	/**
	 * Detect available PDF rasterizer.
	 * Returns 'imagick' | 'ghostscript' | 'none'.
	 */
	private static function detect_rasterizer() {
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			return 'imagick';
		}
		if ( self::is_ghostscript_available() ) {
			return 'ghostscript';
		}
		return 'none';
	}

	private static function is_ghostscript_available() {
		static $cached = null;
		if ( $cached !== null ) return $cached;
		if ( ! function_exists( 'shell_exec' ) ) { $cached = false; return false; }
		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );
		if ( in_array( 'shell_exec', $disabled, true ) ) { $cached = false; return false; }
		$bin = ( PHP_OS_FAMILY === 'Windows' ) ? 'gswin64c' : 'gs';
		$cmd = ( PHP_OS_FAMILY === 'Windows' ) ? "where {$bin} 2>NUL" : "command -v {$bin} 2>/dev/null";
		$out = @shell_exec( $cmd );
		$cached = ! empty( trim( (string) $out ) );
		return $cached;
	}

	/**
	 * Rasterize PDF pages → PNG temp files. Returns array of file paths or WP_Error.
	 */
	private static function rasterize_pdf_pages( $file_path, $max_pages, $dpi ) {
		$rasterizer = self::detect_rasterizer();
		if ( $rasterizer === 'imagick' ) {
			return self::rasterize_with_imagick( $file_path, $max_pages, $dpi );
		}
		if ( $rasterizer === 'ghostscript' ) {
			return self::rasterize_with_ghostscript( $file_path, $max_pages, $dpi );
		}
		return new WP_Error( 'ocr_no_rasterizer', 'No PDF rasterizer available (need Imagick or ghostscript).' );
	}

	private static function rasterize_with_imagick( $file_path, $max_pages, $dpi ) {
		$paths = [];
		try {
			$im = new Imagick();
			$im->setResolution( $dpi, $dpi );
			$im->readImage( $file_path );
			$count = min( $im->getNumberImages(), $max_pages );
			$tmp_dir = trailingslashit( get_temp_dir() );
			for ( $i = 0; $i < $count; $i++ ) {
				$im->setIteratorIndex( $i );
				$im->setImageFormat( 'png' );
				$im->setImageBackgroundColor( 'white' );
				$im = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
				$path = $tmp_dir . 'bizcity-pdf-ocr-' . uniqid() . '-p' . ( $i + 1 ) . '.png';
				$im->writeImage( $path );
				if ( file_exists( $path ) && filesize( $path ) > 0 ) {
					$paths[] = $path;
				}
			}
			$im->clear();
			$im->destroy();
		} catch ( \Throwable $e ) {
			foreach ( $paths as $p ) { @unlink( $p ); }
			return new WP_Error( 'ocr_imagick_failed', 'Imagick rasterization failed: ' . $e->getMessage() );
		}
		return $paths;
	}

	private static function rasterize_with_ghostscript( $file_path, $max_pages, $dpi ) {
		$tmp_dir   = trailingslashit( get_temp_dir() );
		$prefix    = 'bizcity-pdf-ocr-' . uniqid();
		$pattern   = $tmp_dir . $prefix . '-p%d.png';
		$bin       = ( PHP_OS_FAMILY === 'Windows' ) ? 'gswin64c' : 'gs';
		$cmd = sprintf(
			'%s -dNOPAUSE -dBATCH -dQUIET -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=%d -sOutputFile=%s %s 2>&1',
			$bin,
			intval( $dpi ),
			intval( $max_pages ),
			escapeshellarg( $pattern ),
			escapeshellarg( $file_path )
		);
		@shell_exec( $cmd );
		$paths = [];
		for ( $i = 1; $i <= $max_pages; $i++ ) {
			$p = sprintf( $pattern, $i );
			if ( file_exists( $p ) && filesize( $p ) > 0 ) {
				$paths[] = $p;
			} else {
				break; // contiguous numbering — stop at first gap
			}
		}
		if ( empty( $paths ) ) {
			return new WP_Error( 'ocr_ghostscript_failed', 'Ghostscript produced no pages.' );
		}
		return $paths;
	}
}
