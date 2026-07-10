<?php
/**
 * BizCity CRM — Order Public Token Codec (Phase 0.38 W3.1).
 *
 * Generates and verifies short tokens for public order tracking URLs `/o/<token>`.
 *
 * Design (Q5 locked):
 *   token = base62( HMAC-SHA256( wp_salt('nonce') + order_id ) ) truncated to 16 chars
 *   URL   = home_url('/o/' . token)
 *
 * Properties:
 *   - Stateless: no DB lookup needed to encode (HMAC is deterministic per site key).
 *   - Verify decode by re-encoding and comparing (constant-time).
 *   - Token collision probability at 16 base62 chars ≈ negligible (62^16 > 4.7 × 10^28).
 *   - Site key = wp_salt('nonce') — unique per WP install, never stored in code.
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W3.1 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Order_Public_Token' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W3.1 — token codec for public tracking URL /o/<token>
final class BizCity_CRM_Order_Public_Token {

	const TOKEN_LEN  = 16;
	const BASE62     = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

	/**
	 * Encode an order_id → base62 token.
	 *
	 * @param int $order_id
	 * @return string  16-char base62 token.
	 */
	public static function encode( int $order_id ): string {
		$secret = wp_salt( 'nonce' );
		$raw    = hash_hmac( 'sha256', (string) $order_id, $secret, true ); // 32 bytes raw binary
		return self::bin_to_base62( $raw, self::TOKEN_LEN );
	}

	/**
	 * Decode a token → order_id, or 0 on failure.
	 *
	 * Because HMAC is deterministic we verify by re-encoding order_id and comparing.
	 * To decode we need a lookup: token → order_id. We store the mapping in
	 * `_bizcity_public_token` meta on the Woo order. This REST/controller reads
	 * order_id from URL query var and verifies the token matches.
	 *
	 * Callers should use verify() rather than decode() in most cases.
	 *
	 * @param string $token
	 * @param int    $order_id  Claimed order_id to verify against.
	 * @return bool
	 */
	public static function verify( string $token, int $order_id ): bool {
		if ( $token === '' || $order_id <= 0 ) {
			return false;
		}
		$expected = self::encode( $order_id );
		return hash_equals( $expected, $token );
	}

	/**
	 * Given a token (from URL), resolve to order_id via Woo meta query.
	 * Returns 0 on failure.
	 *
	 * @param string $token
	 * @return int
	 */
	public static function resolve( string $token ): int {
		if ( $token === '' || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		// Woo HPOS-compatible query.
		$orders = wc_get_orders( array(
			'meta_key'     => '_bizcity_public_token',
			'meta_value'   => $token,
			'meta_compare' => '=',
			'limit'        => 1,
			'return'       => 'ids',
		) );
		if ( empty( $orders ) ) {
			return 0;
		}
		$order_id = (int) $orders[0];
		// Re-verify HMAC to guard against token collision / meta pollution.
		return self::verify( $token, $order_id ) ? $order_id : 0;
	}

	/**
	 * Convert raw binary to base62 string of given length.
	 *
	 * @param string $bytes  Raw binary string (from hash_hmac ... true).
	 * @param int    $len    Desired output length.
	 * @return string
	 */
	private static function bin_to_base62( string $bytes, int $len ): string {
		$chars  = self::BASE62;
		$base   = strlen( $chars );
		$result = '';
		$blen   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$byte    = ord( $bytes[ $i % $blen ] );
			// Mix byte with position to spread entropy across all 62 characters.
			$idx     = ( $byte + $i * 7 ) % $base;
			$result .= $chars[ $idx ];
		}
		return $result;
	}
}
