<?php
/**
 * TwinWeb — Identity Helper
 *
 * Resolves current user identity (WP user or guest cookie).
 * R-TWEB-1: mọi proxy handler gọi BizCity_TwinWeb_Identity::current() TRƯỚC khi query.
 *
 * Guest session: HMAC-signed cookie `bizcity_tw_guest` — TTL 24h.
 * Value format: `{sid}.{hmac}` — hmac = sha256(NONCE_SALT . sid . expiry)
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Identity' ) ) { return; }

class BizCity_TwinWeb_Identity {

	const COOKIE_NAME = 'bizcity_tw_guest';
	const COOKIE_TTL  = DAY_IN_SECONDS;

	/**
	 * Resolve identity from current request.
	 *
	 * @return array{user_id:int, guest_sid:string, is_guest:bool, display:string}
	 */
	public static function current() {
		$user_id = (int) get_current_user_id();

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			return array(
				'user_id'   => $user_id,
				'guest_sid' => '',
				'is_guest'  => false,
				'display'   => $user ? $user->display_name : 'User',
			);
		}

		// Guest path — resolve/issue cookie
		$sid = self::resolve_guest_sid();
		return array(
			'user_id'   => 0,
			'guest_sid' => $sid,
			'is_guest'  => true,
			'display'   => 'Guest',
		);
	}

	/**
	 * Validate existing guest cookie or issue a new one.
	 *
	 * @return string Session ID (32-char hex).
	 */
	public static function resolve_guest_sid() {
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) $_COOKIE[ self::COOKIE_NAME ] : '';

		if ( $raw ) {
			$sid = self::validate_guest_cookie( $raw );
			if ( $sid ) {
				return $sid;
			}
		}

		// Issue new guest session
		$sid = bin2hex( random_bytes( 16 ) );
		self::set_guest_cookie( $sid );
		return $sid;
	}

	/**
	 * @return string|false Validated SID or false.
	 */
	private static function validate_guest_cookie( $raw ) {
		$parts = explode( '.', $raw, 3 );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		list( $sid, $expiry, $hmac ) = $parts;
		if ( (int) $expiry < time() ) {
			return false;
		}
		$expected = self::sign( $sid, (int) $expiry );
		if ( ! hash_equals( $expected, $hmac ) ) {
			return false;
		}
		return $sid;
	}

	private static function set_guest_cookie( $sid ) {
		$expiry = time() + self::COOKIE_TTL;
		$hmac   = self::sign( $sid, $expiry );
		$value  = $sid . '.' . $expiry . '.' . $hmac;
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expiry,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function sign( $sid, $expiry ) {
		$secret = defined( 'NONCE_SALT' ) ? NONCE_SALT : wp_salt( 'nonce' );
		return hash_hmac( 'sha256', $sid . '|' . $expiry, $secret );
	}
}
