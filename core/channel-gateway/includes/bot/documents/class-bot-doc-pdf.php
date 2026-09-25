<?php
/**
 * Bot Studio — PDF renderer (PHASE-0.60K K4, D-K2).
 *
 * tFPDF + Noto Sans (SIL OFL, embedded as a subset) — plain FPDF is Latin-1 and cannot print Vietnamese, and there is no Chrome
 * on shared hosting to print HTML with. Draws the validated `doc` model: title, headings, paragraphs (with `**bold**`), bullets,
 * tables that break across pages with the header repeated, two columns, and a "Trang x/y" footer.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Doc_Pdf {

	const LH = 5.6; // line height (mm) of body text at 11pt.

	/** @var string|null test seam: a writable directory for tFPDF's font cache (default: uploads/bizcity-bot-docs/_pdf). */
	public static $font_dir = null;
	/** @var bool test seam: false leaves the page streams uncompressed so a test can read the drawing operators. */
	public static $compress = true;

	/** @return string binary %PDF, or '' when the library, the fonts or the cache directory are unavailable. */
	public static function render( array $doc ): string {
		$fontpath = self::prepare_fonts();
		if ( '' === $fontpath ) {
			return '';
		}
		require_once __DIR__ . '/class-bot-pdf-base.php';
		try {
			$pdf = new BizCity_Bot_PDF_Base( $fontpath );
			$pdf->SetTitle( (string) ( $doc['title'] ?? '' ), true );
			$pdf->SetAuthor( 'BizCity Bot', true );
			$pdf->SetCompression( self::$compress );
			$pdf->SetMargins( 20, 18, 20 );
			$pdf->SetAutoPageBreak( true, 18 );
			$pdf->AddFont( BizCity_Bot_PDF_Base::FAMILY, '', 'NotoSans-Regular.ttf', true );
			$pdf->AddFont( BizCity_Bot_PDF_Base::FAMILY, 'B', 'NotoSans-Bold.ttf', true );
			$pdf->AliasNbPages();
			$pdf->AddPage();
			self::title( $pdf, (string) ( $doc['title'] ?? '' ) );
			foreach ( (array) ( $doc['blocks'] ?? array() ) as $b ) {
				switch ( $b['type'] ) {
					case 'heading':
						self::heading( $pdf, (string) $b['text'], (int) $b['level'] );
						break;
					case 'paragraph':
						self::paragraph( $pdf, (string) $b['text'], (string) $b['align'] );
						break;
					case 'bullets':
						foreach ( (array) $b['items'] as $item ) {
							self::bullet( $pdf, (string) $item );
						}
						$pdf->Ln( 1.5 );
						break;
					case 'table':
						self::table( $pdf, (array) $b['headers'], (array) $b['rows'] );
						$pdf->Ln( 3 );
						break;
					case 'two_columns':
						self::two_columns( $pdf, (string) $b['left'], (string) $b['right'] );
						break;
				}
			}
			return (string) $pdf->Output( 'S' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* ── blocks ────────────────────────────────────────────────────────── */

	private static function title( BizCity_Bot_PDF_Base $pdf, string $title ): void {
		if ( '' === $title ) {
			return;
		}
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, 'B', 18 );
		$pdf->MultiCell( 0, 9, $title, 0, 'C' );
		$pdf->Ln( 4 );
	}

	private static function heading( BizCity_Bot_PDF_Base $pdf, string $text, int $level ): void {
		$size = array( 1 => 15, 2 => 13, 3 => 12 )[ $level ] ?? 12;
		// keep a heading with the line that follows it: start a page rather than strand it at the bottom.
		if ( $pdf->GetY() + 22 > $pdf->page_bottom() ) {
			$pdf->AddPage();
		}
		$pdf->Ln( 2 );
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, 'B', $size );
		$pdf->MultiCell( 0, $size * 0.45, BizCity_Bot_Doc_Office::plain( $text ), 0, 'L' );
		$pdf->Ln( 1.5 );
	}

	private static function paragraph( BizCity_Bot_PDF_Base $pdf, string $text, string $align ): void {
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, '', 11 );
		if ( false === strpos( $text, '**' ) ) {
			$pdf->MultiCell( 0, self::LH, $text, 0, array( 'left' => 'L', 'center' => 'C', 'right' => 'R', 'justify' => 'J' )[ $align ] ?? 'L' );
		} else {
			// inline bold: Write() flows runs, at the cost of alignment (always left) — bold is worth more than justify here.
			foreach ( BizCity_Bot_Doc_Office::runs( $text ) as $run ) {
				$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, $run[1] ? 'B' : '', 11 );
				$pdf->Write( self::LH, $run[0] );
			}
			$pdf->Ln( self::LH );
		}
		$pdf->Ln( 1.5 );
	}

	private static function bullet( BizCity_Bot_PDF_Base $pdf, string $text ): void {
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, '', 11 );
		$x = $pdf->GetX();
		$pdf->Cell( 6, self::LH, '•' );
		$pdf->SetX( $x + 6 );
		$pdf->MultiCell( 0, self::LH, BizCity_Bot_Doc_Office::plain( $text ), 0, 'L' );
		$pdf->SetX( $x );
	}

	private static function two_columns( BizCity_Bot_PDF_Base $pdf, string $left, string $right ): void {
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, '', 11 );
		$w    = ( $pdf->usable_width() - 6 ) / 2;
		$h    = max( self::count_lines( $pdf, BizCity_Bot_Doc_Office::plain( $left ), $w ), self::count_lines( $pdf, BizCity_Bot_Doc_Office::plain( $right ), $w ) ) * self::LH;
		if ( $pdf->GetY() + $h > $pdf->page_bottom() ) {
			$pdf->AddPage();
		}
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->MultiCell( $w, self::LH, BizCity_Bot_Doc_Office::plain( $left ), 0, 'L' );
		$pdf->SetXY( $x + $w + 6, $y );
		$pdf->MultiCell( $w, self::LH, BizCity_Bot_Doc_Office::plain( $right ), 0, 'L' );
		$pdf->SetXY( $x, $y + $h + 2 );
	}

	/* ── table ─────────────────────────────────────────────────────────── */

	private static function table( BizCity_Bot_PDF_Base $pdf, array $headers, array $rows ): void {
		$n = max( 1, count( $headers ) );
		// Column weight = the longest content in it (capped), so a "STT" column stays narrow and a description column wide.
		$weights = array();
		foreach ( $headers as $c => $h ) {
			$weights[ $c ] = max( 4, min( 40, (int) ceil( mb_strlen( (string) $h ) * 1.15 ) ) ); // bold is wider
		}
		foreach ( $rows as $row ) {
			foreach ( array_values( (array) $row ) as $c => $cell ) {
				$weights[ $c ] = max( $weights[ $c ] ?? 4, min( 40, mb_strlen( BizCity_Bot_Doc_Office::plain( (string) $cell ) ) ) );
			}
		}
		$total = max( 1, array_sum( $weights ) );
		$avail = $pdf->usable_width();
		$min   = min( 14, $avail / $n );
		$w     = array();
		foreach ( $weights as $c => $wt ) {
			$w[ $c ] = max( $min, $avail * $wt / $total );
		}
		$scale = $avail / max( 1, array_sum( $w ) ); // the minimum widths may have overshot; rescale to fit exactly.
		foreach ( $w as $c => $v ) {
			$w[ $c ] = $v * $scale;
		}

		self::table_row( $pdf, $w, $headers, true );
		foreach ( $rows as $row ) {
			$cells = array_map( static function ( $c ) { return BizCity_Bot_Doc_Office::plain( (string) $c ); }, array_values( (array) $row ) );
			$h     = self::row_height( $pdf, $w, $cells );
			if ( $pdf->GetY() + $h > $pdf->page_bottom() ) {
				$pdf->AddPage();
				self::table_row( $pdf, $w, $headers, true ); // header repeats on every page.
			}
			self::table_row( $pdf, $w, $cells, false );
		}
	}

	/** Measured in the font the row is DRAWN in — a bold header is wider than the same words in regular, and mis-measuring it overlaps the next row. */
	private static function row_height( BizCity_Bot_PDF_Base $pdf, array $w, array $cells, bool $bold = false ): float {
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, $bold ? 'B' : '', 10 );
		$lines = 1;
		foreach ( $cells as $c => $text ) {
			$lines = max( $lines, self::count_lines( $pdf, (string) $text, ( $w[ $c ] ?? 20 ) - 2 ) );
		}
		return $lines * 5.2 + 2;
	}

	private static function table_row( BizCity_Bot_PDF_Base $pdf, array $w, array $cells, bool $head ): void {
		$cells = array_values( $cells );
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, $head ? 'B' : '', 10 );
		$h = self::row_height( $pdf, $w, $cells, $head );
		if ( $pdf->GetY() + $h > $pdf->page_bottom() ) {
			$pdf->AddPage();
		}
		$pdf->SetFont( BizCity_Bot_PDF_Base::FAMILY, $head ? 'B' : '', 10 );
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->SetDrawColor( 170, 170, 170 );
		$pdf->SetFillColor( 217, 226, 243 );
		foreach ( $w as $c => $cw ) {
			$pdf->Rect( $x, $y, $cw, $h, $head ? 'DF' : 'D' );
			$pdf->SetXY( $x + 1, $y + 1 );
			$pdf->MultiCell( $cw - 2, 5.2, (string) ( $cells[ $c ] ?? '' ), 0, 'L' );
			$x += $cw;
		}
		$pdf->SetXY( 20, $y + $h ); // back to the left margin, below the row.
	}

	/** Greedy word wrap using the real font metrics — the same breaking MultiCell does, so heights agree with what is drawn. */
	private static function count_lines( BizCity_Bot_PDF_Base $pdf, string $text, float $width ): int {
		$width = max( 5.0, $width );
		$lines = 0;
		foreach ( explode( "\n", str_replace( "\r", '', $text ) ) as $para ) {
			$lines++;
			$cur = '';
			foreach ( preg_split( '/\s+/u', trim( $para ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $word ) {
				$try = '' === $cur ? $word : $cur . ' ' . $word;
				if ( $pdf->GetStringWidth( $try ) <= $width ) {
					$cur = $try;
					continue;
				}
				if ( '' !== $cur ) {
					$lines++;
				}
				// a single word wider than the column is broken by character
				while ( $pdf->GetStringWidth( $word ) > $width && mb_strlen( $word ) > 1 ) {
					$cut = mb_strlen( $word );
					while ( $cut > 1 && $pdf->GetStringWidth( mb_substr( $word, 0, $cut ) ) > $width ) {
						$cut--;
					}
					$word = mb_substr( $word, $cut );
					if ( '' !== $word ) {
						$lines++;
					}
				}
				$cur = $word;
			}
		}
		return max( 1, $lines );
	}

	/* ── fonts ─────────────────────────────────────────────────────────── */

	/**
	 * A writable `<dir>/font/` holding `unifont/NotoSans-*.ttf`, where tFPDF may cache. Returns the `<dir>/font/` path, '' on failure.
	 * Falls back to reading the bundled fonts in place (no cache: metrics are recomputed each render, slower but working) when no
	 * writable directory exists.
	 */
	private static function prepare_fonts(): string {
		$src_dir = dirname( __DIR__, 3 ) . '/assets/fonts/';
		foreach ( array( 'NotoSans-Regular.ttf', 'NotoSans-Bold.ttf' ) as $f ) {
			if ( ! is_readable( $src_dir . $f ) ) {
				return '';
			}
		}
		if ( ! is_readable( dirname( __DIR__, 3 ) . '/lib/tfpdf/tfpdf.php' ) ) {
			return '';
		}
		$base = self::$font_dir;
		if ( null === $base && function_exists( 'wp_upload_dir' ) ) {
			$up   = wp_upload_dir( null, false );
			$base = ! empty( $up['basedir'] ) ? rtrim( (string) $up['basedir'], '/\\' ) . '/bizcity-bot-docs/_pdf' : '';
		}
		if ( is_string( $base ) && '' !== $base ) {
			$uni = rtrim( $base, '/\\' ) . '/font/unifont';
			if ( ( is_dir( $uni ) || ( function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $uni ) : @mkdir( $uni, 0755, true ) ) ) && is_writable( $uni ) ) {
				$ok = true;
				foreach ( array( 'NotoSans-Regular.ttf', 'NotoSans-Bold.ttf' ) as $f ) {
					if ( ! is_file( $uni . '/' . $f ) || filesize( $uni . '/' . $f ) !== filesize( $src_dir . $f ) ) {
						$ok = $ok && @copy( $src_dir . $f, $uni . '/' . $f );
					}
				}
				if ( $ok ) {
					return rtrim( $base, '/\\' ) . '/font/';
				}
			}
		}
		// No writable cache: read the bundled fonts directly.
		if ( ! defined( '_SYSTEM_TTFONTS' ) ) {
			define( '_SYSTEM_TTFONTS', $src_dir );
		}
		return dirname( __DIR__, 3 ) . '/lib/tfpdf/font/';
	}
}
