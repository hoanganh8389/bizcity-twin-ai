<?php
/**
 * Bot Studio — XLSX renderer (PHASE-0.60K K4).
 *
 * Input is a validated `sheet` document (BizCity_Bot_Document_Schema). Two Libe-Zalo lessons drive the design:
 *  1. every formula cell is written with its `<f>` AND a pre-computed `<v>` — Zalo, Google Drive and most previewers show only
 *     the cached `<v>`, so a formula without one renders as an empty cell;
 *  2. text goes through sharedStrings (the form every reader understands) rather than inlineStr.
 * Row 1 is the frozen header row; data starts at row 2, so a model's `=B2*C2` means what it looks like it means.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Doc_Xlsx {

	// cellXfs indexes (see styles()).
	const S_DEFAULT = 0;
	const S_HEADER  = 1;
	const S_TEXT    = 2;
	const S_MONEY   = 3;
	const S_PERCENT = 4;
	const S_NUMBER  = 5;
	const S_NOTE    = 6;

	/** @return string binary .xlsx, or '' on failure. */
	public static function render( array $doc ): string {
		$sheets = (array) ( $doc['sheets'] ?? array() );
		if ( empty( $sheets ) ) {
			return '';
		}
		$strings = array(); // text => index
		$sheet_xml = array();
		foreach ( $sheets as $i => $sheet ) {
			$sheet_xml[ $i + 1 ] = self::sheet_xml( $sheet, $strings );
		}

		$ct = BizCity_Bot_Doc_Office::XML_HEAD . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
		foreach ( array_keys( $sheet_xml ) as $n ) {
			$ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$ct .= '</Types>';

		$wb_sheets = '';
		$wb_rels   = '';
		foreach ( $sheets as $i => $sheet ) {
			$n          = $i + 1;
			$wb_sheets .= '<sheet name="' . BizCity_Bot_Doc_Office::esc( (string) $sheet['name'] ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$wb_rels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		}
		$k = count( $sheets );
		$wb_rels .= '<Relationship Id="rId' . ( $k + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '<Relationship Id="rId' . ( $k + 2 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

		$parts = array(
			'[Content_Types].xml'        => $ct,
			'_rels/.rels'                => BizCity_Bot_Doc_Office::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
			'docProps/core.xml'          => BizCity_Bot_Doc_Office::core_props( (string) ( $doc['title'] ?? '' ) ),
			'xl/workbook.xml'            => BizCity_Bot_Doc_Office::XML_HEAD . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb_sheets . '</sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => BizCity_Bot_Doc_Office::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wb_rels . '</Relationships>',
			'xl/styles.xml'              => self::styles(),
			'xl/sharedStrings.xml'       => self::shared_strings( $strings ),
		);
		foreach ( $sheet_xml as $n => $xml ) {
			$parts[ 'xl/worksheets/sheet' . $n . '.xml' ] = $xml;
		}
		return BizCity_Bot_Doc_Office::zip( $parts );
	}

	/* ── one worksheet ─────────────────────────────────────────────────── */

	private static function sheet_xml( array $sheet, array &$strings ): string {
		$headers = (array) $sheet['headers'];
		$rows    = (array) $sheet['rows'];
		$cols    = count( $headers );
		$grid    = self::grid( $headers, $rows );

		$widths = array_fill( 0, $cols, 8 );
		foreach ( $headers as $c => $h ) {
			$widths[ $c ] = max( $widths[ $c ], mb_strlen( (string) $h ) + 4 );
		}
		$xml_rows = '<row r="1">';
		foreach ( $headers as $c => $h ) {
			$xml_rows .= '<c r="' . BizCity_Bot_Document_Schema::col_letters( $c ) . '1" s="' . self::S_HEADER . '" t="s"><v>' . self::sst( $strings, (string) $h ) . '</v></c>';
		}
		$xml_rows .= '</row>';
		foreach ( $rows as $r => $cells ) {
			$row_no    = $r + 2;
			$xml_rows .= '<row r="' . $row_no . '">';
			foreach ( $cells as $c => $cell ) {
				$ref   = BizCity_Bot_Document_Schema::col_letters( $c ) . $row_no;
				$style = self::style_for( $cell );
				if ( 's' === $cell['t'] ) {
					$xml_rows .= '' === $cell['v']
						? '<c r="' . $ref . '" s="' . $style . '"/>'
						: '<c r="' . $ref . '" s="' . $style . '" t="s"><v>' . self::sst( $strings, (string) $cell['v'] ) . '</v></c>';
					$widths[ $c ] = max( $widths[ $c ], min( 60, mb_strlen( (string) $cell['v'] ) + 2 ) );
				} elseif ( 'n' === $cell['t'] ) {
					$xml_rows    .= '<c r="' . $ref . '" s="' . $style . '"><v>' . self::num( (float) $cell['v'] ) . '</v></c>';
					$widths[ $c ] = max( $widths[ $c ], min( 30, strlen( self::num( (float) $cell['v'] ) ) + 4 ) );
				} else {
					$value        = self::value_of( $grid, $c, $r + 2, 0 );
					$xml_rows    .= '<c r="' . $ref . '" s="' . $style . '"><f>' . BizCity_Bot_Doc_Office::esc( (string) $cell['f'] ) . '</f><v>' . self::num( $value ) . '</v></c>';
					$widths[ $c ] = max( $widths[ $c ], min( 30, strlen( self::num( $value ) ) + 4 ) );
				}
			}
			$xml_rows .= '</row>';
		}
		if ( '' !== (string) ( $sheet['note'] ?? '' ) ) {
			$note_row  = count( $rows ) + 3;
			$xml_rows .= '<row r="' . $note_row . '"><c r="A' . $note_row . '" s="' . self::S_NOTE . '" t="s"><v>' . self::sst( $strings, (string) $sheet['note'] ) . '</v></c></row>';
		}

		$col_xml = '<cols>';
		foreach ( $widths as $c => $w ) {
			$col_xml .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . min( 60, $w ) . '" customWidth="1"/>';
		}
		$col_xml .= '</cols>';
		return BizCity_Bot_Doc_Office::XML_HEAD . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>' . $col_xml . '<sheetData>' . $xml_rows . '</sheetData></worksheet>';
	}

	private static function style_for( array $cell ): int {
		$fmt = (string) ( $cell['fmt'] ?? '' );
		if ( 'money' === $fmt ) {
			return self::S_MONEY;
		}
		if ( 'percent' === $fmt ) {
			return self::S_PERCENT;
		}
		return 's' === $cell['t'] ? self::S_TEXT : self::S_NUMBER;
	}

	/* ── formula values ────────────────────────────────────────────────── */

	/** @return array<int,array<int,array>> 1-based row → 0-based column → cell (row 1 = headers as text cells). */
	private static function grid( array $headers, array $rows ): array {
		$grid = array( 1 => array() );
		foreach ( $headers as $c => $h ) {
			$grid[1][ $c ] = array( 't' => 's', 'v' => (string) $h );
		}
		foreach ( $rows as $r => $cells ) {
			$grid[ $r + 2 ] = array_values( $cells );
		}
		return $grid;
	}

	/** Cached value of one cell; a formula is evaluated recursively (depth-limited, so a cycle yields 0 instead of looping). */
	private static function value_of( array $grid, int $col, int $row, int $depth ): float {
		if ( $depth > 20 || ! isset( $grid[ $row ][ $col ] ) ) {
			return 0.0;
		}
		$cell = $grid[ $row ][ $col ];
		if ( 'n' === $cell['t'] ) {
			return (float) $cell['v'];
		}
		if ( 's' === $cell['t'] ) {
			$raw = str_replace( array( ',', ' ' ), '', (string) $cell['v'] );
			return is_numeric( $raw ) ? (float) $raw : 0.0;
		}
		$f = (string) $cell['f'];
		if ( preg_match( '/^SUM\(([A-Z]{1,2})([1-9]\d*):([A-Z]{1,2})([1-9]\d*)\)$/', $f, $m ) ) {
			$sum = 0.0;
			$c1  = BizCity_Bot_Document_Schema::col_index( $m[1] );
			$c2  = BizCity_Bot_Document_Schema::col_index( $m[3] );
			for ( $r = min( (int) $m[2], (int) $m[4] ); $r <= max( (int) $m[2], (int) $m[4] ); $r++ ) {
				for ( $c = min( $c1, $c2 ); $c <= max( $c1, $c2 ); $c++ ) {
					if ( $r === $row && $c === $col ) {
						continue; // a SUM that contains itself must not recurse into itself.
					}
					$sum += self::value_of( $grid, $c, $r, $depth + 1 );
				}
			}
			return $sum;
		}
		if ( preg_match( '/^([A-Z]{1,2})([1-9]\d*)([*+\-])([A-Z]{1,2})([1-9]\d*)$/', $f, $m ) ) {
			$a = self::value_of( $grid, BizCity_Bot_Document_Schema::col_index( $m[1] ), (int) $m[2], $depth + 1 );
			$b = self::value_of( $grid, BizCity_Bot_Document_Schema::col_index( $m[4] ), (int) $m[5], $depth + 1 );
			return '*' === $m[3] ? $a * $b : ( '+' === $m[3] ? $a + $b : $a - $b );
		}
		return 0.0;
	}

	/** Public for tests: the value a formula cell will be cached with. */
	public static function evaluate( array $headers, array $rows, int $col, int $data_row_index ): float {
		return self::value_of( self::grid( $headers, $rows ), $col, $data_row_index + 2, 0 );
	}

	private static function num( float $v ): string {
		if ( is_nan( $v ) || is_infinite( $v ) ) {
			return '0';
		}
		return rtrim( rtrim( number_format( $v, 10, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/* ── parts ─────────────────────────────────────────────────────────── */

	private static function sst( array &$strings, string $s ): int {
		if ( ! isset( $strings[ $s ] ) ) {
			$strings[ $s ] = count( $strings );
		}
		return $strings[ $s ];
	}

	private static function shared_strings( array $strings ): string {
		$xml = '';
		foreach ( array_keys( $strings ) as $s ) {
			$xml .= '<si><t xml:space="preserve">' . BizCity_Bot_Doc_Office::esc( (string) $s ) . '</t></si>';
		}
		return BizCity_Bot_Doc_Office::XML_HEAD . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count( $strings ) . '" uniqueCount="' . count( $strings ) . '">' . $xml . '</sst>';
	}

	private static function styles(): string {
		return BizCity_Bot_Doc_Office::XML_HEAD . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="4"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font>'
			. '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><i/><sz val="10"/><name val="Arial"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="7">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
			. '<xf numFmtId="10" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
			. '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}
}
