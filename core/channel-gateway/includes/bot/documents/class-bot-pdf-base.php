<?php
/**
 * Bot Studio — the tFPDF subclass the PDF renderer draws with (PHASE-0.60K K4, D-K2).
 *
 * Loaded on demand by BizCity_Bot_Doc_Pdf (never by the bootstrap): tFPDF is 63 KB and only a turn that really produces a PDF needs it.
 * tFPDF is bundled UNMODIFIED in ../../../lib/tfpdf (see NOTICE.txt there); the only changes live in this subclass — the page footer,
 * and pointing the font path at a directory the web server can write, because tFPDF caches font metrics next to the TTF and those cache
 * files contain absolute paths (so they must be generated on the host that uses them, never committed).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 3 ) . '/lib/tfpdf/tfpdf.php';
require_once dirname( __DIR__, 3 ) . '/lib/tfpdf/font/unifont/ttfonts.php';

class BizCity_Bot_PDF_Base extends tFPDF {

	const FAMILY = 'NotoSans';

	public function __construct( string $fontpath ) {
		parent::__construct( 'P', 'mm', 'A4' );
		$this->fontpath = rtrim( $fontpath, '/\\' ) . '/';
	}

	/** Y coordinate after which the next line starts a new page. */
	public function page_bottom(): float {
		return (float) $this->PageBreakTrigger;
	}

	/** Usable width between the margins. */
	public function usable_width(): float {
		return (float) ( $this->w - $this->lMargin - $this->rMargin );
	}

	public function Footer() {
		$this->SetY( -12 );
		$this->SetFont( self::FAMILY, '', 8 );
		$this->SetTextColor( 120, 120, 120 );
		$this->Cell( 0, 6, 'Trang ' . $this->PageNo() . '/{nb}', 0, 0, 'C' );
		$this->SetTextColor( 0, 0, 0 );
	}
}
