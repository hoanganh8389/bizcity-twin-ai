<?php
/**
 * Bot Studio — the document data model and its validator (PHASE-0.60K K4).
 *
 * The model never writes code and never writes a file format: it writes DATA, in one of two shapes, and PHP renders it.
 * That is Libe-Zalo's central lesson for document tools ("chỉ dữ liệu, model không gửi code"), and it means a hostile or
 * confused model can at worst produce an ugly document, never a macro, a script or a formula that runs anything.
 *
 *   doc    (docx · pdf · md)  {"title":"…","blocks":[
 *              {"type":"heading","text":"…","level":1..3}
 *              {"type":"paragraph","text":"… **đậm** …","align":"left|center|right|justify"}
 *              {"type":"bullets","items":["…"]}
 *              {"type":"table","headers":["…"],"rows":[["…"]]}
 *              {"type":"two_columns","left":"…","right":"…"} ]}
 *   sheet  (xlsx · csv)       {"title":"…","sheets":[{"name":"…","headers":["…"],"rows":[[cell,…]],"note":"…"}]}
 *              cell = "text" | 12.5 | {"kind":"number","value":12.5,"format":"money|percent"} | "=B2*C2" | "=SUM(D2:D9)"
 *              Row 1 of a sheet is the header row, data starts at row 2 — so a formula's A1 references mean exactly that.
 *
 * validate() never throws and never rejects a whole document for a cosmetic fault: over-long tables are cut, short rows padded,
 * an unsupported formula becomes plain text — each recorded in `warnings`. Only an unusable document (no content at all, wrong
 * shape) is an error, and then `errors` says why, which is what the single retry to the model quotes back.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Document_Schema {

	const MAX_BLOCKS      = 60;
	const MAX_ROWS        = 200;
	const MAX_CHARS       = 20000;
	const MAX_SHEETS      = 10;
	const MAX_DOC_COLS    = 8;
	const MAX_SHEET_COLS  = 20;
	const MAX_CELL_CHARS  = 500;
	const MAX_ITEMS       = 60;

	/** format => [shape, extension, mime]. `pptx` and `html` are deliberately absent — see class-bot-documents.php. */
	const FORMATS = array(
		'docx' => array( 'doc', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
		'pdf'  => array( 'doc', 'pdf', 'application/pdf' ),
		'md'   => array( 'doc', 'md', 'text/markdown' ),
		'xlsx' => array( 'sheet', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
		'csv'  => array( 'sheet', 'csv', 'text/csv' ),
	);

	public static function shape_of( string $format ): string {
		return self::FORMATS[ $format ][0] ?? '';
	}

	/**
	 * Text form of the schema, quoted into the compose prompt.
	 */
	public static function describe( string $format ): string {
		if ( 'sheet' === self::shape_of( $format ) ) {
			return "Trả về DUY NHẤT một JSON (không markdown, không giải thích):\n"
				. '{"title":"Tên tài liệu","sheets":[{"name":"Tên sheet","headers":["Cột 1","Cột 2"],"rows":[["a",1],["b",2]],"note":"ghi chú cuối bảng (tuỳ chọn)"}]}' . "\n"
				. "- Dòng 1 của mỗi sheet là dòng tiêu đề cột (headers); dữ liệu bắt đầu từ dòng 2. Mỗi dòng đúng bằng số cột của headers.\n"
				. '- Ô: chuỗi | số | {"kind":"number","value":1500000,"format":"money"} (format: money|percent; percent viết 0.15 = 15%).' . "\n"
				. '- Công thức chỉ dạng =B2*C2 (nhân/cộng/trừ đúng hai ô) hoặc =SUM(D2:D9). Không dùng hàm khác.' . "\n"
				. '- Tối đa ' . self::MAX_SHEETS . ' sheet, ' . self::MAX_ROWS . ' dòng/sheet, ' . self::MAX_SHEET_COLS . ' cột.';
		}
		return "Trả về DUY NHẤT một JSON (không markdown, không giải thích):\n"
			. '{"title":"Tên tài liệu","blocks":[{"type":"heading","text":"…","level":1},{"type":"paragraph","text":"… **đậm** …","align":"left"},{"type":"bullets","items":["…"]},{"type":"table","headers":["Cột 1","Cột 2"],"rows":[["a","b"]]},{"type":"two_columns","left":"…","right":"…"}]}' . "\n"
			. '- type: heading (level 1–3) · paragraph (align: left|center|right|justify) · bullets · table · two_columns.' . "\n"
			. '- Bảng: tối đa ' . self::MAX_DOC_COLS . ' cột, ' . self::MAX_ROWS . ' dòng; mỗi dòng đúng bằng số cột của headers.' . "\n"
			. '- Tối đa ' . self::MAX_BLOCKS . ' khối, ' . self::MAX_CHARS . ' ký tự tổng.';
	}

	/**
	 * @param mixed $doc Decoded JSON.
	 * @return array{ok:bool,doc:array,errors:string[],warnings:string[]}
	 */
	public static function validate( string $format, $doc ): array {
		$shape = self::shape_of( $format );
		if ( '' === $shape ) {
			return self::result( false, array(), array( 'format_unsupported' ), array() );
		}
		if ( ! is_array( $doc ) ) {
			return self::result( false, array(), array( 'not_an_object' ), array() );
		}
		$warnings = array();
		$budget   = self::MAX_CHARS;
		$title    = self::text( $doc['title'] ?? '', 200 );
		$out      = 'sheet' === $shape ? self::sheets( $doc, $warnings, $budget ) : self::blocks( $doc, $warnings, $budget );
		if ( is_string( $out ) ) {
			return self::result( false, array(), array( $out ), $warnings );
		}
		$out['title'] = '' !== $title ? $title : 'Tài liệu';
		return self::result( true, $out, array(), $warnings );
	}

	/* ── doc shape ─────────────────────────────────────────────────────── */

	/** @return array|string blocks array or an error code */
	private static function blocks( array $doc, array &$warnings, int &$budget ) {
		$raw = isset( $doc['blocks'] ) && is_array( $doc['blocks'] ) ? array_values( $doc['blocks'] ) : array();
		if ( empty( $raw ) ) {
			return 'no_blocks';
		}
		if ( count( $raw ) > self::MAX_BLOCKS ) {
			$raw        = array_slice( $raw, 0, self::MAX_BLOCKS );
			$warnings[] = 'blocks_truncated';
		}
		$blocks = array();
		foreach ( $raw as $b ) {
			if ( ! is_array( $b ) || $budget <= 0 ) {
				if ( $budget <= 0 ) {
					$warnings[] = 'chars_truncated';
					break;
				}
				continue;
			}
			$type = (string) ( $b['type'] ?? '' );
			switch ( $type ) {
				case 'heading':
					$t = self::take( $b['text'] ?? '', 200, $budget );
					if ( '' !== $t ) {
						$blocks[] = array( 'type' => 'heading', 'text' => $t, 'level' => max( 1, min( 3, (int) ( $b['level'] ?? 1 ) ) ) );
					}
					break;
				case 'paragraph':
					$t = self::take( $b['text'] ?? '', 4000, $budget );
					if ( '' !== $t ) {
						$want     = is_string( $b['align'] ?? null ) ? $b['align'] : 'left';
						$align    = in_array( $want, array( 'left', 'center', 'right', 'justify' ), true ) ? $want : 'left';
						$blocks[] = array( 'type' => 'paragraph', 'text' => $t, 'align' => $align );
					}
					break;
				case 'bullets':
					$items = array();
					foreach ( array_slice( array_values( (array) ( $b['items'] ?? array() ) ), 0, self::MAX_ITEMS ) as $it ) {
						$t = self::take( $it, 500, $budget );
						if ( '' !== $t ) {
							$items[] = $t;
						}
					}
					if ( ! empty( $items ) ) {
						$blocks[] = array( 'type' => 'bullets', 'items' => $items );
					}
					break;
				case 'table':
					$table = self::table( $b, self::MAX_DOC_COLS, $warnings, $budget );
					if ( null !== $table ) {
						$blocks[] = array( 'type' => 'table' ) + $table;
					}
					break;
				case 'two_columns':
					$l = self::take( $b['left'] ?? '', 3000, $budget );
					$r = self::take( $b['right'] ?? '', 3000, $budget );
					if ( '' !== $l || '' !== $r ) {
						$blocks[] = array( 'type' => 'two_columns', 'left' => $l, 'right' => $r );
					}
					break;
				default:
					$warnings[] = 'block_type_ignored:' . preg_replace( '/[^a-z_]/', '', strtolower( $type ) );
			}
		}
		return empty( $blocks ) ? 'no_usable_blocks' : array( 'blocks' => $blocks );
	}

	/** @return array{headers:string[],rows:array}|null */
	private static function table( array $b, int $max_cols, array &$warnings, int &$budget ): ?array {
		$headers = array();
		foreach ( array_slice( array_values( (array) ( $b['headers'] ?? array() ) ), 0, $max_cols ) as $h ) {
			$headers[] = self::take( $h, 100, $budget );
		}
		if ( empty( $headers ) ) {
			return null;
		}
		if ( count( (array) ( $b['headers'] ?? array() ) ) > $max_cols ) {
			$warnings[] = 'columns_truncated';
		}
		$rows = array();
		foreach ( array_values( (array) ( $b['rows'] ?? array() ) ) as $row ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				$warnings[] = 'rows_truncated';
				break;
			}
			$cells = array();
			$src   = is_array( $row ) ? array_values( $row ) : array( $row );
			for ( $i = 0; $i < count( $headers ); $i++ ) {
				$cells[] = self::take( $src[ $i ] ?? '', self::MAX_CELL_CHARS, $budget );
			}
			if ( count( $src ) !== count( $headers ) ) {
				$warnings[] = 'row_padded_or_cut';
			}
			$rows[] = $cells;
		}
		return array( 'headers' => $headers, 'rows' => $rows );
	}

	/* ── sheet shape ───────────────────────────────────────────────────── */

	/** @return array|string */
	private static function sheets( array $doc, array &$warnings, int &$budget ) {
		$raw = isset( $doc['sheets'] ) && is_array( $doc['sheets'] ) ? array_values( $doc['sheets'] ) : array();
		if ( empty( $raw ) ) {
			return 'no_sheets';
		}
		if ( count( $raw ) > self::MAX_SHEETS ) {
			$raw        = array_slice( $raw, 0, self::MAX_SHEETS );
			$warnings[] = 'sheets_truncated';
		}
		$sheets = array();
		$names  = array();
		foreach ( $raw as $i => $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$headers = array();
			foreach ( array_slice( array_values( (array) ( $s['headers'] ?? array() ) ), 0, self::MAX_SHEET_COLS ) as $h ) {
				$headers[] = self::take( $h, 100, $budget );
			}
			if ( empty( $headers ) ) {
				continue;
			}
			$rows = array();
			foreach ( array_values( (array) ( $s['rows'] ?? array() ) ) as $row ) {
				if ( count( $rows ) >= self::MAX_ROWS ) {
					$warnings[] = 'rows_truncated';
					break;
				}
				$src   = is_array( $row ) ? array_values( $row ) : array( $row );
				$cells = array();
				for ( $c = 0; $c < count( $headers ); $c++ ) {
					$cells[] = self::cell( $src[ $c ] ?? '', $budget, $warnings );
				}
				if ( count( $src ) !== count( $headers ) ) {
					$warnings[] = 'row_padded_or_cut';
				}
				$rows[] = $cells;
			}
			$rows_count = count( $rows );
			foreach ( $rows as $r => $cells ) {
				foreach ( $cells as $c => $cell ) {
					if ( 'formula' === $cell['t'] && ! self::formula_in_range( $cell['f'], $rows_count + 1, count( $headers ) ) ) {
						$rows[ $r ][ $c ] = array( 't' => 's', 'v' => $cell['f'] ); // out-of-range refs: keep the text, run nothing.
						$warnings[]       = 'formula_out_of_range';
					}
				}
			}
			$name = self::sheet_name( self::text( $s['name'] ?? '', 60 ), $i + 1, $names );
			$sheets[] = array( 'name' => $name, 'headers' => $headers, 'rows' => $rows, 'note' => self::take( $s['note'] ?? '', 500, $budget ) );
		}
		return empty( $sheets ) ? 'no_usable_sheets' : array( 'sheets' => $sheets );
	}

	/**
	 * A cell is one of: {t:'s',v:string} · {t:'n',v:float,fmt:''|'money'|'percent'} · {t:'formula',f:'B2*C2'|'SUM(D2:D9)',fmt:''}
	 * A formula string that is not one of the two allowed forms is DEMOTED to text (never rejected, never evaluated).
	 */
	private static function cell( $value, int &$budget, array &$warnings ): array {
		if ( is_array( $value ) ) {
			$kind = (string) ( $value['kind'] ?? '' );
			if ( 'number' === $kind && isset( $value['value'] ) && is_numeric( $value['value'] ) ) {
				$fmt = in_array( $value['format'] ?? '', array( 'money', 'percent' ), true ) ? $value['format'] : '';
				return array( 't' => 'n', 'v' => (float) $value['value'], 'fmt' => $fmt );
			}
			if ( 'number' === $kind && isset( $value['value'] ) && is_string( $value['value'] ) && self::is_formula( $value['value'] ) ) {
				$fmt = in_array( $value['format'] ?? '', array( 'money', 'percent' ), true ) ? $value['format'] : '';
				return array( 't' => 'formula', 'f' => substr( trim( $value['value'] ), 1 ), 'fmt' => $fmt );
			}
			return array( 't' => 's', 'v' => self::take( wp_json_encode( $value ), self::MAX_CELL_CHARS, $budget ) );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return array( 't' => 'n', 'v' => (float) $value, 'fmt' => '' );
		}
		if ( is_bool( $value ) || null === $value ) {
			return array( 't' => 's', 'v' => '' );
		}
		$s = (string) $value;
		if ( '' !== $s && '=' === $s[0] ) {
			if ( self::is_formula( $s ) ) {
				return array( 't' => 'formula', 'f' => substr( trim( $s ), 1 ), 'fmt' => '' );
			}
			$warnings[] = 'formula_unsupported';
		}
		return array( 't' => 's', 'v' => self::take( $s, self::MAX_CELL_CHARS, $budget ) );
	}

	/** Exactly: =A1*B1 / =A1+B1 / =A1-B1 / =SUM(A1:A9) — nothing else, ever. */
	public static function is_formula( string $s ): bool {
		$s = trim( $s );
		return (bool) preg_match( '/^=(?:[A-Z]{1,2}[1-9]\d{0,3}[*+\-][A-Z]{1,2}[1-9]\d{0,3}|SUM\([A-Z]{1,2}[1-9]\d{0,3}:[A-Z]{1,2}[1-9]\d{0,3}\))$/', $s );
	}

	/** Every cell reference must lie inside the sheet (header row 1 … last data row; first … last column). */
	private static function formula_in_range( string $f, int $max_row, int $max_col ): bool {
		if ( ! preg_match_all( '/([A-Z]{1,2})([1-9]\d{0,3})/', $f, $m, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $m as $ref ) {
			if ( self::col_index( $ref[1] ) >= $max_col || (int) $ref[2] > $max_row ) {
				return false;
			}
		}
		return true;
	}

	public static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	public static function col_letters( int $index ): string {
		$s = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$s = chr( 65 + ( $n - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	/* ── file name ─────────────────────────────────────────────────────── */

	/**
	 * Libe-Zalo rules: no path characters, the model's own extension dropped, ≤ 80 chars, a default, and the right extension
	 * forced. Vietnamese diacritics are kept (a customer-facing name).
	 */
	public static function safe_filename( string $title, string $ext ): string {
		$base = preg_replace( '/\.[A-Za-z0-9]{1,5}$/u', '', trim( $title ) );
		$base = str_replace( array( '/', '\\' ), ' ', (string) $base );
		$base = (string) preg_replace( '/[^\p{L}\p{N}\s._-]+/u', '', $base );
		$base = trim( (string) preg_replace( '/\s+/u', ' ', $base ), " .-_\t" );
		$base = function_exists( 'mb_substr' ) ? mb_substr( $base, 0, 80 ) : substr( $base, 0, 80 );
		$base = trim( str_replace( ' ', '-', $base ), '.-_' );
		return ( '' !== $base ? $base : 'tai-lieu' ) . '.' . $ext;
	}

	/* ── helpers ───────────────────────────────────────────────────────── */

	private static function sheet_name( string $name, int $n, array &$used ): string {
		$name = trim( (string) preg_replace( '/[\\\\\/?*\[\]:]+/u', ' ', $name ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 ); // Excel's hard limit.
		$name = '' !== $name ? $name : 'Sheet' . $n;
		$base = $name;
		for ( $i = 2; in_array( strtolower( $name ), $used, true ); $i++ ) {
			$suffix = ' ' . $i;
			$name   = ( function_exists( 'mb_substr' ) ? mb_substr( $base, 0, 31 - strlen( $suffix ) ) : substr( $base, 0, 31 - strlen( $suffix ) ) ) . $suffix;
		}
		$used[] = strtolower( $name );
		return $name;
	}

	/** Plain text, control characters removed, capped. */
	private static function text( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = trim( $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** text() that also spends the document's character budget. */
	private static function take( $v, int $max, int &$budget ): string {
		$s = self::text( $v, min( $max, max( 0, $budget ) ) );
		$budget -= ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ) );
		return $s;
	}

	private static function result( bool $ok, array $doc, array $errors, array $warnings ): array {
		return array( 'ok' => $ok, 'doc' => $doc, 'errors' => $errors, 'warnings' => array_values( array_unique( $warnings ) ) );
	}
}
