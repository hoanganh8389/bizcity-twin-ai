<?php
/**
 * Bizcity Twin AI — Citation ID Generator
 *
 * Sprint 4.7f — Generate collision-free 4-char alphanumeric citation IDs:
 * `[a3x9]`, `[b2m7]`, ... (ít nhất 1 chữ cái để phân biệt với số trang).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Citation_Id_Generator {

	/** Lowercase letters + digits, exclude confusing chars (0/o, 1/l/i). */
	const CHARSET_LETTERS = 'abcdefghjkmnpqrstuvwxyz';   // 23
	const CHARSET_DIGITS  = '23456789';                  // 8

	/**
	 * Generate $count IDs, đảm bảo unique trong batch.
	 *
	 * @param int    $count
	 * @param string $prefix  optional, ví dụ 'IMG-' cho image refs
	 * @return string[]
	 */
	public static function generate_batch( int $count, string $prefix = '' ): array {
		$out = [];
		$attempts = 0;
		while ( count( $out ) < $count && $attempts < $count * 10 ) {
			$id = self::generate_one( $prefix );
			if ( ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			$attempts++;
		}
		return $out;
	}

	public static function generate_one( string $prefix = '' ): string {
		// Pattern: letter + (letter|digit) x 2 + letter|digit
		// → ≥1 chữ cái, 4 char total, không bắt đầu bằng số.
		$letters = self::CHARSET_LETTERS;
		$mixed   = self::CHARSET_LETTERS . self::CHARSET_DIGITS;

		$id  = $letters[ random_int( 0, strlen( $letters ) - 1 ) ];
		$id .= $mixed[ random_int( 0, strlen( $mixed ) - 1 ) ];
		$id .= $mixed[ random_int( 0, strlen( $mixed ) - 1 ) ];
		$id .= $mixed[ random_int( 0, strlen( $mixed ) - 1 ) ];

		return $prefix . $id;
	}

	/**
	 * Validate format. Accepted markers:
	 *  - Phase 0.6: `src:N#pM` or `src:N`, `draft:N`
	 *  - Legacy short id `[a3x9]` (≥1 letter + 4 alphanumeric)
	 *  - Numeric source index `[1]`, `[12]`
	 *  - KG entity index `[K1]`
	 */
	public static function is_valid( string $id ): bool {
		// Phase 0.6: src:N#pM, src:N, draft:N
		if ( preg_match( '/^(?:src|draft):\d+(?:#p\d+)?$/i', $id ) ) {
			return true;
		}
		// Legacy: a3x9, K1, 1
		return (bool) preg_match( '/^([a-z][a-z0-9]{3}|\d+|K\d+)$/i', $id );
	}

	/**
	 * Extract citation markers from text. Accepted formats:
	 *  - Phase 0.6: `[src:N#pM]`, `[src:N]`, `[draft:N]`
	 *  - Legacy: `[a3x9]`, `[K1]`, `[1]`
	 *
	 * @return string[]  list of unique IDs found (in order of appearance, without brackets)
	 */
	public static function extract_from_text( string $text ): array {
		$out = [];
		// Phase 0.6: [src:N#pM], [src:N], [draft:N]
		if ( preg_match_all( '/\[((?:src|draft):\d+(?:#p\d+)?)\]/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = strtolower( $id );
				if ( ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		// Legacy: [a3x9], [K1], [1]
		if ( preg_match_all( '/\[([a-z][a-z0-9]{3}|K\d+|\d+)\]/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = strtolower( $id );
				if ( ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}
}
