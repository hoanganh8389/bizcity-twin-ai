<?php
/**
 * BizCity Channel Gateway — Flows · Ref Codec
 *
 * Encodes / decodes the `?ref=…` parameter embedded in Messenger deep-links
 * generated for each flow (e.g. `https://m.me/<page_id>?ref=<TOKEN>`).
 *
 * **Legacy compatibility (R-GW-N.flows.ref-codec)**
 *   The legacy mu-plugin `biz-id.php` (waic_twf family) exposes two helpers
 *   used widely across printed QR / Messenger campaign links:
 *
 *     function twf_encrypt_chat_id($chat_id, $slug = 'vietqr') {
 *         $secret_key = AUTH_SALT ?: 'your-fallback-secret';
 *         $plaintext  = $slug . '|' . $chat_id;
 *         $data = base64_encode(
 *             openssl_encrypt($plaintext, 'aes-256-cbc', $secret_key, 0, substr($secret_key,0,16))
 *         );
 *         return rtrim( strtr( $data, '+/', '-_' ), '=' );  // URL-safe
 *     }
 *
 *     function twf_decrypt_chat_id($encrypted, $slug = 'vietqr') {
 *         $secret_key = AUTH_SALT ?: 'your-fallback-secret';
 *         $data = base64_decode( strtr( $encrypted, '-_', '+/' ) );
 *         $decrypted = openssl_decrypt($data, 'aes-256-cbc', $secret_key, 0, substr($secret_key,0,16));
 *         if ( strpos( $decrypted, $slug . '|' ) === 0 ) {
 *             return substr( $decrypted, strlen( $slug ) + 1 );
 *         }
 *         return false;
 *     }
 *
 *   Existing QR codes / Messenger campaign links printed on brochures must
 *   keep resolving. This codec is a 1:1 PHP re-implementation so tokens are
 *   byte-identical regardless of whether `biz-id.php` is still loaded.
 *
 *   Resolution order on encode/decode:
 *     1. Delegate to `twf_encrypt_chat_id` / `twf_decrypt_chat_id` if loaded.
 *     2. Internal implementation (same algorithm, key, IV — see fallback_*).
 *
 *   Because both paths use AUTH_SALT directly there is **no extra wp-config
 *   constant required** for migration — as long as AUTH_SALT in wp-config.php
 *   does not change, old + new tokens round-trip on the same install.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N.1 (2026-05-26)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CG_Flow_Ref_Codec {

	const CIPHER         = 'aes-256-cbc';
	const DEFAULT_BUCKET = 'vietqr';
	const FALLBACK_KEY   = 'your-fallback-secret'; // matches legacy mu-plugin.

	/**
	 * Encode a flow id into the URL-safe ref token used in m.me links.
	 *
	 * Output is byte-identical to legacy `twf_encrypt_chat_id($id, $bucket)`
	 * whether the legacy helper is loaded or not (same algorithm + AUTH_SALT).
	 *
	 * @param int    $id     Flow / row id (must be > 0).
	 * @param string $bucket Slug prefix (default `vietqr` — matches legacy).
	 * @return string Empty string if $id <= 0 or encryption failed.
	 */
	public static function encode( int $id, string $bucket = self::DEFAULT_BUCKET ): string {
		if ( $id <= 0 ) {
			return '';
		}
		if ( function_exists( 'twf_encrypt_chat_id' ) ) {
			$token = (string) twf_encrypt_chat_id( $id, $bucket );
			if ( '' !== $token ) {
				return $token;
			}
		}
		return self::fallback_encode( (string) $id, $bucket );
	}

	/**
	 * Decode a ref token back to a flow id.
	 *
	 * @param string $ref    Raw token from `?ref=…` (already url-decoded by PHP).
	 * @param string $bucket Slug prefix expected (default `vietqr`).
	 * @return int Flow id, or 0 if the token could not be resolved.
	 */
	public static function decode( string $ref, string $bucket = self::DEFAULT_BUCKET ): int {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return 0;
		}
		if ( function_exists( 'twf_decrypt_chat_id' ) ) {
			$plain = twf_decrypt_chat_id( $ref, $bucket );
			if ( false !== $plain && is_numeric( $plain ) && (int) $plain > 0 ) {
				return (int) $plain;
			}
		}
		$plain = self::fallback_decode( $ref, $bucket );
		return ( '' !== $plain && is_numeric( $plain ) && (int) $plain > 0 ) ? (int) $plain : 0;
	}

	/**
	 * Convenience: build the full Messenger deep-link for a flow.
	 *
	 * @param string $page_id FB Page ID.
	 * @param int    $id      Flow id.
	 * @param string $bucket  Slug prefix (default `vietqr`).
	 * @return string Empty string if any arg invalid.
	 */
	public static function build_messenger_link( string $page_id, int $id, string $bucket = self::DEFAULT_BUCKET ): string {
		$page_id = trim( $page_id );
		if ( '' === $page_id || $id <= 0 ) {
			return '';
		}
		$ref = self::encode( $id, $bucket );
		if ( '' === $ref ) {
			return '';
		}
		// Token is already URL-safe (no '+', '/', '='), no extra encoding needed.
		return 'https://m.me/' . rawurlencode( $page_id ) . '?ref=' . $ref;
	}

	/* ----------------------------------------------------------------
	 * Internals — exact 1:1 with legacy twf_*.
	 * ---------------------------------------------------------------- */

	private static function secret_key(): string {
		return ( defined( 'AUTH_SALT' ) && AUTH_SALT ) ? (string) AUTH_SALT : self::FALLBACK_KEY;
	}

	private static function iv(): string {
		return substr( self::secret_key(), 0, 16 );
	}

	/**
	 * Mirror of legacy `twf_encrypt_chat_id` body.
	 * Returns URL-safe single base64 over the (already-base64) openssl ciphertext.
	 */
	private static function fallback_encode( string $chat_id, string $slug ): string {
		$plaintext = $slug . '|' . $chat_id;
		$cipher    = openssl_encrypt( $plaintext, self::CIPHER, self::secret_key(), 0, self::iv() );
		if ( false === $cipher ) {
			return '';
		}
		$data = base64_encode( $cipher );
		return rtrim( strtr( $data, '+/', '-_' ), '=' );
	}

	/**
	 * Mirror of legacy `twf_decrypt_chat_id` body.
	 * Returns chat_id string on success, '' on failure (callers cast to int).
	 */
	private static function fallback_decode( string $encrypted, string $slug ): string {
		$data = base64_decode( strtr( $encrypted, '-_', '+/' ), true );
		if ( false === $data ) {
			return '';
		}
		$decrypted = openssl_decrypt( $data, self::CIPHER, self::secret_key(), 0, self::iv() );
		if ( false === $decrypted ) {
			return '';
		}
		$prefix = $slug . '|';
		if ( strpos( $decrypted, $prefix ) !== 0 ) {
			return '';
		}
		return substr( $decrypted, strlen( $prefix ) );
	}
}
