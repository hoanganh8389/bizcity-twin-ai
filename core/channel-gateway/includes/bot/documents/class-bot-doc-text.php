<?php
/**
 * Bot Studio — CSV and Markdown renderers (PHASE-0.60K K4).
 *
 * CSV: UTF-8 WITH a BOM (Excel guesses the legacy code page without one and garbles Vietnamese), RFC 4180 quoting, and the
 * CSV-injection guard — a TEXT cell that starts with = + - @ TAB or CR gets a leading apostrophe, so opening the file in a
 * spreadsheet can never run a formula the model (or a hostile customer message it echoed) wrote. Real numbers are exempt: "-5"
 * typed as a number is a number.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Doc_Text {

	const BOM = "\xEF\xBB\xBF";

	/** First sheet only — a CSV is one table; the caller mentions extra sheets. */
	public static function csv( array $doc ): string {
		$sheet = (array) ( ( $doc['sheets'] ?? array() )[0] ?? array() );
		if ( empty( $sheet['headers'] ) ) {
			return '';
		}
		$headers = (array) $sheet['headers'];
		$rows    = (array) ( $sheet['rows'] ?? array() );
		$lines   = array( self::csv_line( array_map( array( __CLASS__, 'csv_text' ), $headers ) ) );
		foreach ( $rows as $r => $cells ) {
			$out = array();
			foreach ( array_values( (array) $cells ) as $c => $cell ) {
				if ( 'n' === $cell['t'] ) {
					$out[] = self::csv_number( (float) $cell['v'], (string) ( $cell['fmt'] ?? '' ) );
				} elseif ( 'formula' === $cell['t'] ) {
					$out[] = self::csv_number( BizCity_Bot_Doc_Xlsx::evaluate( $headers, $rows, $c, $r ), (string) ( $cell['fmt'] ?? '' ) );
				} else {
					$out[] = self::csv_text( (string) $cell['v'] );
				}
			}
			$lines[] = self::csv_line( $out );
		}
		if ( '' !== (string) ( $sheet['note'] ?? '' ) ) {
			$lines[] = self::csv_line( array( self::csv_text( (string) $sheet['note'] ) ) );
		}
		return self::BOM . implode( "\r\n", $lines ) . "\r\n";
	}

	/** Text cell: neutralise a spreadsheet formula trigger. */
	public static function csv_text( string $s ): string {
		// Check the trigger on the ORIGINAL first character: normalising a leading CR to LF first would let "\rcmd" through.
		$guarded = '' !== $s && false !== strpos( "=+-@\t\r", $s[0] ) ? "'" . $s : $s;
		return str_replace( array( "\r\n", "\r" ), "\n", $guarded );
	}

	private static function csv_number( float $v, string $fmt ): string {
		if ( 'percent' === $fmt ) {
			return rtrim( rtrim( number_format( $v * 100, 4, '.', '' ), '0' ), '.' ) . '%';
		}
		return rtrim( rtrim( number_format( $v, 10, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/** RFC 4180: quote when the field has a comma, a quote or a line break; a quote is doubled. */
	private static function csv_line( array $fields ): string {
		return implode( ',', array_map( static function ( $f ) {
			$f = (string) $f;
			return preg_match( '/[",\r\n]/', $f ) ? '"' . str_replace( '"', '""', $f ) . '"' : $f;
		}, $fields ) );
	}

	/* ── markdown ──────────────────────────────────────────────────────── */

	public static function markdown( array $doc ): string {
		$md = array();
		if ( '' !== (string) ( $doc['title'] ?? '' ) ) {
			$md[] = '# ' . self::inline( (string) $doc['title'] );
		}
		foreach ( (array) ( $doc['blocks'] ?? array() ) as $b ) {
			switch ( $b['type'] ) {
				case 'heading':
					$md[] = str_repeat( '#', min( 6, (int) $b['level'] + 1 ) ) . ' ' . self::inline( (string) $b['text'] );
					break;
				case 'paragraph':
					$md[] = (string) $b['text'];
					break;
				case 'bullets':
					$md[] = implode( "\n", array_map( static function ( $i ) { return '- ' . str_replace( "\n", ' ', (string) $i ); }, (array) $b['items'] ) );
					break;
				case 'table':
					$md[] = self::md_table( (array) $b['headers'], (array) $b['rows'] );
					break;
				case 'two_columns':
					$md[] = (string) $b['left'] . "\n\n" . (string) $b['right'];
					break;
			}
		}
		return implode( "\n\n", $md ) . "\n";
	}

	private static function md_table( array $headers, array $rows ): string {
		$cell  = static function ( $c ): string { return str_replace( array( '|', "\n" ), array( '\\|', '<br>' ), (string) $c ); };
		$lines = array( '| ' . implode( ' | ', array_map( $cell, $headers ) ) . ' |', '|' . str_repeat( ' --- |', count( $headers ) ) );
		foreach ( $rows as $row ) {
			$lines[] = '| ' . implode( ' | ', array_map( $cell, array_values( (array) $row ) ) ) . ' |';
		}
		return implode( "\n", $lines );
	}

	private static function inline( string $s ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}
}
