<?php
/**
 * Remote Zalo Hub credential store — LC-12/B2.
 *
 * Stores the base URL and encrypted key server-side. Public callers receive only
 * redacted connection metadata; the plaintext key is available only inside RM.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Credentials', false ) ) {
	return;
}

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B2 — encrypt the remote key through the shared BizCity codec and keep the REST view redacted.
final class BizCity_Remote_Zalo_Credentials {

	const OPTION = 'bizcity_zalo_remote_conn';
	const PREFIX = 'rzk1_';
	const CONTEXT = 'bizcity-remote-zalo-hub';

	/** Save a validated connection; null key keeps the existing encrypted key. */
	public static function save( string $base_url, ?string $key = null ): array {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Host_Policy' ) ) {
			return self::failure( 'remote_host_policy_missing', 'Remote host policy is unavailable.' );
		}
		$validated = BizCity_Remote_Zalo_Host_Policy::validate_base_url( $base_url );
		if ( empty( $validated['ok'] ) ) {
			return self::failure( (string) ( $validated['code'] ?? 'remote_url_invalid' ), 'The remote base URL is invalid.' );
		}
		$stored = self::read();
		$encoded = (string) ( $stored['key_enc'] ?? '' );
		if ( null !== $key ) {
			$key = trim( $key );
			if ( '' !== $key && ! preg_match( '/^zk_[A-Za-z0-9_-]{8,200}$/', $key ) ) {
				return self::failure( 'remote_key_format_invalid', 'The remote key format is invalid.' );
			}
			if ( '' === $key ) {
				$encoded = '';
			} else {
				$encoded = self::encrypt( $key );
				if ( '' === $encoded ) {
					return self::failure( 'remote_key_store_failed', 'The remote key could not be stored.' );
				}
			}
		}
		$row = array(
			'base_url'  => (string) $validated['normalized'],
			'key_enc'   => $encoded,
			'key_set_at'=> '' !== $encoded ? (string) ( $stored['key_set_at'] ?? gmdate( 'c' ) ) : '',
		);
		if ( null !== $key && '' !== $key ) {
			$row['key_set_at'] = gmdate( 'c' );
			$row['key_fingerprint'] = substr( hash( 'sha256', $key ), 0, 8 );
		} elseif ( '' === $encoded ) {
			$row['key_fingerprint'] = '';
		} else {
			$row['key_fingerprint'] = (string) ( $stored['key_fingerprint'] ?? '' );
		}
		if ( false === update_option( self::OPTION, $row, false ) ) {
			return self::failure( 'remote_key_store_failed', 'The remote connection could not be saved.' );
		}
		return array( 'ok' => true ) + self::public_view_from( $row );
	}

	/** Return redacted connection metadata only. */
	public static function public_view(): array {
		return self::public_view_from( self::read() );
	}

	/** Internal-only plaintext key accessor. */
	public static function key(): string {
		$row = self::read();
		$encoded = (string) ( $row['key_enc'] ?? '' );
		if ( '' === $encoded || ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$decoded = BizCity_Codec::decrypt_json_payload( $encoded, (string) wp_salt( 'auth' ), self::PREFIX, self::CONTEXT );
		return is_array( $decoded ) ? (string) ( $decoded['key'] ?? '' ) : '';
	}

	public static function base_url(): string {
		return (string) ( self::read()['base_url'] ?? '' );
	}

	public static function clear(): bool {
		return false !== update_option( self::OPTION, array( 'base_url' => '', 'key_enc' => '', 'key_set_at' => '', 'key_fingerprint' => '' ), false );
	}

	private static function read(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function encrypt( string $key ): string {
		if ( ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		return BizCity_Codec::encrypt_json_payload( array( 'key' => $key ), (string) wp_salt( 'auth' ), self::PREFIX, self::CONTEXT );
	}

	private static function public_view_from( array $row ): array {
		return array(
			'base_url_host'  => self::host( (string) ( $row['base_url'] ?? '' ) ),
			'key_set'        => '' !== (string) ( $row['key_enc'] ?? '' ),
			'key_fingerprint'=> (string) ( $row['key_fingerprint'] ?? '' ),
			'key_set_at'     => (string) ( $row['key_set_at'] ?? '' ),
		);
	}

	private static function host( string $url ): string {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
	}

	private static function failure( string $code, string $message ): array {
		return array( 'ok' => false, 'code' => $code, 'message' => $message );
	}
}
