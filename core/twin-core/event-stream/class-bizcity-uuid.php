<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Bizcity_Uuid — Time-ordered UUID v7 generator.
 *
 * Phase 0.12 — UUID v7 (RFC 9562 draft) for `bizcity_twin_event_stream.event_uuid`.
 * Time-ordered, sortable, cross-site portable in WordPress Multisite.
 *
 * Layout (128 bits):
 *   - 48 bits: unix_ts_ms (big-endian)
 *   - 4  bits: version = 0b0111
 *   - 12 bits: rand_a   (random)
 *   - 2  bits: variant  = 0b10
 *   - 62 bits: rand_b   (random)
 *
 * @since 2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class Bizcity_Uuid {

	/**
	 * Generate a UUID v7 string (lowercase, hyphenated).
	 *
	 * @param int|null $unix_ts_ms Override timestamp in ms (test only). Default: now.
	 * @return string e.g. "018f6c8a-bd6e-7c3a-8a01-1a2b3c4d5e6f"
	 */
	public static function v7( ?int $unix_ts_ms = null ): string {
		$ts = $unix_ts_ms !== null ? $unix_ts_ms : (int) ( microtime( true ) * 1000 );

		// 48-bit timestamp
		$ts_hex = str_pad( dechex( $ts ), 12, '0', STR_PAD_LEFT );

		// 10 bytes of randomness (we'll mask version + variant)
		try {
			$rand = random_bytes( 10 );
		} catch ( \Exception $e ) {
			// Fallback for environments without CSPRNG (extremely rare)
			$rand = openssl_random_pseudo_bytes( 10 );
			if ( $rand === false ) {
				$rand = '';
				for ( $i = 0; $i < 10; $i++ ) {
					$rand .= chr( mt_rand( 0, 255 ) );
				}
			}
		}

		// rand_a: 12 bits (bytes 0..1, mask top 4 bits with version 0x7)
		$b0 = ord( $rand[0] );
		$b1 = ord( $rand[1] );
		$b0 = ( $b0 & 0x0F ) | 0x70; // version = 7

		// variant: top 2 bits of byte 2 = 0b10
		$b2 = ord( $rand[2] );
		$b2 = ( $b2 & 0x3F ) | 0x80;

		$rand_hex = sprintf( '%02x%02x%02x', $b0, $b1, $b2 ) . bin2hex( substr( $rand, 3, 7 ) );
		// Total = 6 hex (3 bytes) + 14 hex (7 bytes) = 20 hex

		// Compose: 8-4-4-4-12
		// timestamp: 12 hex   → 8-4
		// rand:      20 hex   → 4-4-12
		$full = $ts_hex . $rand_hex; // 32 hex chars

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $full, 0,  8 ),
			substr( $full, 8,  4 ),
			substr( $full, 12, 4 ),
			substr( $full, 16, 4 ),
			substr( $full, 20, 12 )
		);
	}

	/**
	 * Validate a UUID string (any version), case-insensitive.
	 */
	public static function is_valid( string $uuid ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$uuid
		);
	}

	/**
	 * Validate specifically as UUID v7 (version nibble == 7, variant bits == 10xx).
	 */
	public static function is_v7( string $uuid ): bool {
		if ( ! self::is_valid( $uuid ) ) return false;
		// Version nibble is the 1st char of group 3 (index 14 in stripped form).
		$hex = str_replace( '-', '', strtolower( $uuid ) );
		if ( $hex[12] !== '7' ) return false;
		// Variant: top 2 bits of byte 8 (hex chars 16..17) must be 10 → first hex char ∈ {8,9,a,b}
		$variant_char = $hex[16];
		return in_array( $variant_char, [ '8', '9', 'a', 'b' ], true );
	}

	/**
	 * Extract the embedded timestamp (ms) from a UUID v7. Returns 0 if invalid.
	 */
	public static function extract_ts_ms( string $uuid ): int {
		if ( ! self::is_v7( $uuid ) ) return 0;
		$hex = str_replace( '-', '', strtolower( $uuid ) );
		return (int) hexdec( substr( $hex, 0, 12 ) );
	}
}
