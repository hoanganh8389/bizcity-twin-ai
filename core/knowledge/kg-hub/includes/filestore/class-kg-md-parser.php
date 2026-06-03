<?php
/**
 * Bizcity Twin AI — KG Markdown Parser (Phase 0.7 Wave F1).
 *
 * Minimal YAML-frontmatter + body parser for `passages/*.md` shard files.
 * NOT a general YAML parser — supports only the flat key:value + nested 1-level
 * map / array literal that the writer emits. This keeps it dependency-free
 * (no symfony/yaml) and predictable.
 *
 * On-disk record format:
 *
 *   ---
 *   id: 38268
 *   uid: 0193a8e3-7e2f-7b1c-9d4f-...
 *   notebook_id: 30
 *   content_hash: a7f3...e2
 *   token_count: 412
 *   created_at: 2026-05-12T10:30:42Z
 *   metadata: { "page_num": 5, "asset_refs": ["asset-1234"] }
 *   ---
 *   <body text — raw, no escape>
 *   ---END---
 *
 * Why `---END---` instead of standard `---`:
 *   User-authored bodies may legitimately contain `---` (horizontal rule).
 *   A fixed END marker eliminates that ambiguity.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_MD_Parser {

	const FRONT_DELIM = '---';
	const END_MARKER  = '---END---';

	/**
	 * Encode a record (frontmatter + body) into the on-disk byte string.
	 *
	 * @param array  $frontmatter Flat keys (scalars + simple JSON-encoded arrays).
	 * @param string $body
	 * @return array { 'bytes'=>string, 'body_offset'=>int, 'body_length'=>int }
	 *               body_offset/body_length are byte-positions within `bytes`
	 *               so callers can record file_offset = base + body_offset.
	 */
	public static function encode( array $frontmatter, $body ) {
		$lines = [ self::FRONT_DELIM ];
		foreach ( $frontmatter as $k => $v ) {
			$lines[] = self::format_kv( $k, $v );
		}
		$lines[] = self::FRONT_DELIM;
		$head = implode( "\n", $lines ) . "\n";
		$body = (string) $body;
		// Normalise body: ensure exactly one trailing newline before END marker.
		$body_norm = rtrim( $body, "\r\n" ) . "\n";
		$bytes = $head . $body_norm . self::END_MARKER . "\n";
		return [
			'bytes'       => $bytes,
			'body_offset' => strlen( $head ),
			'body_length' => strlen( $body_norm ) - 1, // exclude the single trailing \n
		];
	}

	/**
	 * Decode the next record at `$pos` in `$bytes`. Returns null if no record
	 * begins at $pos (e.g. EOF).
	 *
	 * @return array|null { frontmatter, body, end_pos } where end_pos points
	 *                    to the byte AFTER the trailing newline of `---END---`.
	 */
	public static function decode_at( $bytes, $pos = 0 ) {
		$len = strlen( $bytes );
		if ( $pos >= $len ) { return null; }

		// Find opening `---\n`.
		$open = strpos( $bytes, self::FRONT_DELIM . "\n", $pos );
		if ( false === $open || $open !== $pos ) {
			return null;
		}
		$cursor = $open + strlen( self::FRONT_DELIM ) + 1;

		// Find closing `---\n`.
		$close = strpos( $bytes, "\n" . self::FRONT_DELIM . "\n", $cursor );
		if ( false === $close ) { return null; }
		$front_raw = substr( $bytes, $cursor, $close - $cursor );
		$body_start = $close + strlen( "\n" . self::FRONT_DELIM . "\n" );

		// Find `---END---\n` after body_start.
		$end = strpos( $bytes, self::END_MARKER, $body_start );
		if ( false === $end ) { return null; }
		$body = substr( $bytes, $body_start, $end - $body_start );
		$body = rtrim( $body, "\n" );
		$end_pos = $end + strlen( self::END_MARKER );
		if ( $end_pos < $len && "\n" === $bytes[ $end_pos ] ) { $end_pos++; }

		return [
			'frontmatter'  => self::parse_front( $front_raw ),
			'body'         => $body,
			'end_pos'      => $end_pos,
			'body_offset'  => $body_start,
			'body_length'  => strlen( $body ),
		];
	}

	/**
	 * Slice raw body bytes by absolute offset+length (used by Passage_File_Store
	 * via the kg_passages.file_offset/file_length index — random-access O(1)).
	 */
	public static function slice_body( $bytes, $offset, $length ) {
		return substr( $bytes, (int) $offset, (int) $length );
	}

	// -------------------------------------------------------------------
	// Internal — primitive (de)serialisation
	// -------------------------------------------------------------------

	private static function format_kv( $key, $value ) {
		$key = preg_replace( '/[^a-z0-9_]/i', '', (string) $key );
		if ( is_null( $value ) ) {
			return $key . ': null';
		}
		if ( is_bool( $value ) ) {
			return $key . ': ' . ( $value ? 'true' : 'false' );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $key . ': ' . $value;
		}
		if ( is_array( $value ) ) {
			// Emit as compact JSON literal — round-trips via json_decode.
			return $key . ': ' . wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$v = (string) $value;
		// Quote when contains delimiter chars; otherwise bare scalar.
		if ( preg_match( '/[:#\n"\']/', $v ) || '' === $v ) {
			return $key . ': ' . wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return $key . ': ' . $v;
	}

	private static function parse_front( $raw ) {
		$out = [];
		$lines = preg_split( '/\r?\n/', $raw );
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$pos = strpos( $line, ':' );
			if ( false === $pos ) { continue; }
			$key = trim( substr( $line, 0, $pos ) );
			$val = trim( substr( $line, $pos + 1 ) );
			$out[ $key ] = self::parse_value( $val );
		}
		return $out;
	}

	private static function parse_value( $raw ) {
		if ( '' === $raw || 'null' === $raw ) { return null; }
		if ( 'true'  === $raw ) { return true; }
		if ( 'false' === $raw ) { return false; }
		$c = $raw[0];
		if ( '"' === $c || '{' === $c || '[' === $c ) {
			$decoded = json_decode( $raw, true );
			if ( null !== $decoded || 'null' === $raw ) { return $decoded; }
		}
		if ( is_numeric( $raw ) ) {
			return ( false === strpos( $raw, '.' ) ) ? (int) $raw : (float) $raw;
		}
		return $raw;
	}
}
