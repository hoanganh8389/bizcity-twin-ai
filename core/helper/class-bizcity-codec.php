<?php
/**
 * BizCity shared encoding and encryption primitives.
 *
 * Keep URL state, JWT/base64url, authenticated payloads, and legacy storage
 * formats in one place. Callers own the domain payload and validation rules.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Codec {

	const CIPHER = 'aes-256-cbc';

	/**
	 * [2026-08-20 Johnny Chu] CODEC-CORE — canonical URL-safe base64 encoder.
	 */
	public static function base64url_encode( string $value ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize URL-safe base64 encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * [2026-08-20 Johnny Chu] CODEC-CORE — canonical ordinary Base64 encoding for binary/storage formats.
	 */
	public static function base64_encode( string $value ): string {
		return base64_encode( $value );
	}

	/**
	 * Decode ordinary Base64 for binary/storage formats.
	 *
	 * @return string|false
	 */
	public static function base64_decode( string $value, bool $strict = true ) {
		return base64_decode( $value, $strict );
	}

	/**
	 * Decode a URL-safe base64 value.
	 *
	 * @return string|false
	 */
	public static function base64url_decode( string $value ) {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize strict URL-safe base64 decoding.
		$value = trim( $value );
		if ( $value === '' || preg_match( '/[^A-Za-z0-9_-]/', $value ) ) {
			return false;
		}
		$value .= str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Encode an array as compact URL-safe JSON state.
	 */
	public static function json_base64url_encode( array $payload ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize compact JSON URL state encoding.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : self::base64url_encode( $json );
	}

	/**
	 * Decode URL-safe JSON state.
	 *
	 * @return array|false
	 */
	public static function json_base64url_decode( string $value ) {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize compact JSON URL state decoding.
		$json = self::base64url_decode( $value );
		if ( false === $json ) {
			return false;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * [2026-08-20 Johnny Chu] CODEC-CORE — canonical HMAC-SHA256 primitive.
	 *
	 * @return string Raw binary digest when $raw is true, hex digest otherwise.
	 */
	public static function hmac_sha256( string $value, string $key, bool $raw = true ): string {
		return hash_hmac( 'sha256', $value, $key, $raw );
	}

	/**
	 * [2026-08-20 Johnny Chu] CODEC-CORE — preserve legacy HMAC-SHA1 token formats.
	 */
	public static function hmac_sha1( string $value, string $key, bool $raw = true ): string {
		return hash_hmac( 'sha1', $value, $key, $raw );
	}

	/**
	 * Encrypt and authenticate a JSON payload for a short-lived URL token.
	 */
	public static function encrypt_json_payload( array $payload, string $key, string $prefix = '', string $mac_context = '' ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize authenticated JSON payload encryption.
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_cipher_iv_length' ) || $key === '' ) {
			return '';
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '';
		}
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		try {
			$iv = random_bytes( $iv_length );
		} catch ( Exception $e ) {
			return '';
		}
		$binary_key = hash( 'sha256', $key, true );
		$ciphertext = openssl_encrypt( $json, self::CIPHER, $binary_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}
		$mac = self::hmac_sha256( $mac_context . $iv . $ciphertext, $binary_key, true );
		return $prefix . self::base64url_encode( $iv . $mac . $ciphertext );
	}

	/**
	 * Decrypt and authenticate a JSON payload created by encrypt_json_payload().
	 *
	 * @return array|false
	 */
	public static function decrypt_json_payload( string $token, string $key, string $prefix = '', string $mac_context = '' ) {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize authenticated JSON payload decryption.
		if ( ! function_exists( 'openssl_decrypt' ) || $key === '' || ( $prefix !== '' && strpos( $token, $prefix ) !== 0 ) ) {
			return false;
		}
		$encoded = $prefix !== '' ? substr( $token, strlen( $prefix ) ) : $token;
		$blob = self::base64url_decode( $encoded );
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $blob || ! $iv_length || strlen( $blob ) <= $iv_length + 32 ) {
			return false;
		}
		$iv = substr( $blob, 0, $iv_length );
		$mac = substr( $blob, $iv_length, 32 );
		$ciphertext = substr( $blob, $iv_length + 32 );
		$binary_key = hash( 'sha256', $key, true );
		$expected = self::hmac_sha256( $mac_context . $iv . $ciphertext, $binary_key, true );
		if ( ! hash_equals( $expected, $mac ) ) {
			return false;
		}
		$plain = openssl_decrypt( $ciphertext, self::CIPHER, $binary_key, OPENSSL_RAW_DATA, $iv );
		$data = false === $plain ? false : json_decode( $plain, true );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Encrypt the legacy raw format: base64(iv + raw ciphertext).
	 * Used for existing CF7/ZNS values that must remain readable.
	 */
	public static function encrypt_raw_payload( string $plain, string $key, string $iv, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize legacy raw ciphertext encoding.
		if ( ! function_exists( 'openssl_encrypt' ) || $plain === '' ) {
			return $plain;
		}
		$ciphertext = openssl_encrypt( $plain, $cipher, $key, OPENSSL_RAW_DATA, $iv );
		return false === $ciphertext ? '' : base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt the legacy raw format created by encrypt_raw_payload().
	 *
	 * @return string
	 */
	public static function decrypt_raw_payload( string $encoded, string $key, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — centralize legacy raw ciphertext decoding.
		if ( ! function_exists( 'openssl_decrypt' ) || $encoded === '' ) {
			return $encoded;
		}
		$raw = base64_decode( $encoded, true );
		$iv_length = openssl_cipher_iv_length( $cipher );
		if ( false === $raw || strlen( $raw ) <= $iv_length ) {
			return '';
		}
		$iv = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$plain = openssl_decrypt( $ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Decrypt base64(raw ciphertext) with a caller-supplied fixed IV.
	 *
	 * @return string|false
	 */
	public static function decrypt_fixed_iv_raw( string $encoded, string $key, string $iv, string $cipher = self::CIPHER ) {
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve fixed-IV fallback storage decoding.
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return false;
		}
		$plain = openssl_decrypt( $raw, $cipher, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? false : $plain;
	}

	/**
	 * Preserve the legacy XOR/Base64 password format during migration.
	 * New secret storage must use authenticated encryption instead.
	 */
	public static function legacy_xor_encode( string $plain, string $key ): string {
		return self::base64_encode( self::xor_bytes( $plain, hash( 'sha256', $key, true ) ) );
	}

	/**
	 * Decode the legacy XOR/Base64 password format.
	 */
	public static function legacy_xor_decode( string $encoded, string $key ): string {
		$raw = self::base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}
		return self::xor_bytes( $raw, hash( 'sha256', $key, true ) );
	}

	/**
	 * [2026-08-20 Johnny Chu] CODEC-CORE — preserve caller-supplied legacy XOR key semantics.
	 */
	public static function xor_bytes( string $value, string $key ): string {
		if ( $key === '' ) {
			return $value;
		}
		$out = '';
		$key_length = strlen( $key );
		for ( $i = 0, $length = strlen( $value ); $i < $length; $i++ ) {
			$out .= chr( ord( $value[ $i ] ) ^ ord( $key[ $i % $key_length ] ) );
		}
		return $out;
	}

	/**
	 * Encrypt the old integration storage format without changing stored bytes.
	 */
	public static function encrypt_legacy_storage( string $value, string $key, string $iv, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve and centralize legacy integration storage encoding.
		if ( ! function_exists( 'openssl_encrypt' ) || $value === '' ) {
			return $value;
		}
		$encrypted = openssl_encrypt( $value, $cipher, $key, 0, $iv );
		return false === $encrypted ? '' : base64_encode( $encrypted );
	}

	/**
	 * Decrypt the old integration storage format without changing stored bytes.
	 */
	public static function decrypt_legacy_storage( string $value, string $key, string $iv, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve and centralize legacy integration storage decoding.
		if ( ! function_exists( 'openssl_decrypt' ) || $value === '' ) {
			return $value;
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return $value;
		}
		$plain = openssl_decrypt( $decoded, $cipher, $key, 0, $iv );
		return false === $plain ? $value : $plain;
	}

	/**
	 * Encode the legacy flow ref format byte-for-byte.
	 */
	public static function legacy_url_encode( string $plain, string $key, string $iv, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve byte-compatible legacy URL token encoding.
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$ciphertext = openssl_encrypt( $plain, $cipher, $key, 0, $iv );
		return false === $ciphertext ? '' : self::base64url_encode( base64_encode( $ciphertext ) );
	}

	/**
	 * Decode the legacy flow ref format byte-for-byte.
	 *
	 * @return string
	 */
	public static function legacy_url_decode( string $encoded, string $key, string $iv, string $cipher = self::CIPHER ): string {
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve byte-compatible legacy URL token decoding.
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$outer = self::base64url_decode( $encoded );
		if ( false === $outer ) {
			return '';
		}
		$inner = base64_decode( $outer, true );
		if ( false === $inner ) {
			return '';
		}
		$plain = openssl_decrypt( $inner, $cipher, $key, 0, $iv );
		return false === $plain ? '' : $plain;
	}
}
