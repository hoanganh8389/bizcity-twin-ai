<?php
/** Remote Zalo Hub settings REST owner — C1, revised E3 (probe-before-persist + last_check). */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Settings_REST', false ) ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C1 — own the single same-origin settings boundary for Remote Zalo Hub.
// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — `save_settings()` now probes a CANDIDATE base URL + key
// before ever calling `Credentials::save()`. The previous order (save, then probe, then `clear()` on
// failure) could destroy a working configuration the instant a transient network error or a typo in a
// re-entered field caused the probe to fail. Persisting only after a successful probe means a failed
// "Lưu và kiểm tra" always leaves the site exactly as it was.
final class BizCity_Remote_Zalo_Settings_REST {
	const NS = 'bizcity-channel/v1';
	const KEY_FORMAT = '/^zk_[A-Za-z0-9_\-]{8,200}$/';
	const CHECK_RATE_TRANSIENT_PREFIX = 'rzh_check_rl_';
	const CHECK_RATE_SECONDS = 10;

	public static function init(): void {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Feature' ) || ! BizCity_Remote_Zalo_Feature::enabled() ) { return; }
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}
	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-remote/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'clear_settings' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
		register_rest_route( self::NS, '/zalo-remote/gap-ack', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ack_gap' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — re-run the same checks against the SAVED configuration,
		// no body required. Never sends a customer message and never advances the poll cursor (C6's job).
		register_rest_route( self::NS, '/zalo-remote/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_settings' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
	}
	public static function can_manage(): bool { return current_user_can( 'manage_options' ); }
	public static function get_settings( $request = null ) { return rest_ensure_response( self::payload() ); }

	public static function save_settings( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$base_url = trim( (string) ( $body['base_url'] ?? '' ) );
		$key = array_key_exists( 'api_key', $body ) ? trim( (string) $body['api_key'] ) : null;
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) || ! class_exists( 'BizCity_Remote_Zalo_Host_Policy' ) || ! class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) {
			return self::error( 'remote_not_loaded', 'Remote Zalo chưa được nạp.', 'Bật cờ Remote Zalo rồi thử lại.', 'remote_not_loaded', 503 );
		}
		$validated = BizCity_Remote_Zalo_Host_Policy::validate_base_url( $base_url );
		if ( empty( $validated['ok'] ) ) {
			return self::error( (string) ( $validated['code'] ?? 'remote_url_invalid' ), 'Base URL Remote Zalo không hợp lệ.', 'Dùng URL HTTPS công khai của Remote Zalo Hub.', 'remote_settings_invalid', 400 );
		}
		if ( null !== $key && '' !== $key && ! preg_match( self::KEY_FORMAT, $key ) ) {
			return self::error( 'remote_key_format_invalid', 'Khoá Remote Zalo không đúng định dạng.', 'Khoá bắt đầu bằng zk_ và dài ít nhất 8 ký tự.', 'remote_settings_invalid', 400 );
		}
		// A blank/omitted `api_key` means "keep the saved key" — probe with it, never with an empty string.
		$probe_key = ( null !== $key && '' !== $key ) ? $key : BizCity_Remote_Zalo_Credentials::key();
		if ( '' === $probe_key ) {
			return self::error( 'remote_key_missing', 'Chưa có khoá Remote Zalo để kiểm tra.', 'Nhập khoá zk_… rồi thử lại.', 'remote_settings_invalid', 400 );
		}
		// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — probe the CANDIDATE pair first. Nothing is persisted yet:
		// a failed probe here returns an error and the saved configuration (if any) is completely untouched.
		$probe = BizCity_Remote_Zalo_Hub_Client::with_credentials( (string) $validated['normalized'], $probe_key )->list_accounts();
		if ( empty( $probe['ok'] ) ) {
			return self::error( (string) ( $probe['error']['code'] ?? 'remote_auth_failed' ), 'Remote Zalo chưa chấp nhận cấu hình.', 'Kiểm tra khóa, Base URL và quyền truy cập rồi thử lại.', 'remote_auth_failed', 400, array( 'upstream_code' => (string) ( $probe['error']['upstream_code'] ?? '' ), 'transport_code' => (string) ( $probe['error']['transport_code'] ?? '' ) ) );
		}
		$result = BizCity_Remote_Zalo_Credentials::save( $base_url, $key );
		if ( empty( $result['ok'] ) ) {
			// The probe succeeded but the store itself refused (e.g. re-validated URL/key shape mismatch) —
			// still nothing destructive happened to whatever was there before this call.
			return self::error( (string) ( $result['code'] ?? 'remote_settings_invalid' ), 'Không lưu được cấu hình Remote Zalo.', 'Sửa URL hoặc khóa rồi thử lại.', 'remote_settings_invalid', 400 );
		}
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::reset(); }
		self::record_check( $probe, (string) ( $result['key_fingerprint'] ?? '' ) );
		// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS2 — a freshly saved connection starts polling without waiting for a page load.
		if ( class_exists( 'BizCity_Remote_Zalo_Poller' ) && method_exists( 'BizCity_Remote_Zalo_Poller', 'ensure_scheduled' ) ) { BizCity_Remote_Zalo_Poller::ensure_scheduled(); }
		return rest_ensure_response( self::payload() );
	}

	/**
	 * [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — "Kiểm tra lại": re-probe the ALREADY-SAVED configuration
	 * without asking the admin to re-type the key. Rate-limited per user; never mutates the cursor.
	 */
	public static function check_settings( $request = null ) {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) || ! class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) {
			return self::error( 'remote_not_loaded', 'Remote Zalo chưa được nạp.', 'Bật cờ Remote Zalo rồi thử lại.', 'remote_not_loaded', 503 );
		}
		$user_id = get_current_user_id();
		$rl_key = self::CHECK_RATE_TRANSIENT_PREFIX . (int) $user_id;
		if ( function_exists( 'get_transient' ) && get_transient( $rl_key ) ) {
			return self::error( 'remote_check_rate_limited', 'Vừa kiểm tra xong.', 'Chờ vài giây rồi thử lại.', 'rate_limited', 429 );
		}
		$conn = BizCity_Remote_Zalo_Credentials::public_view();
		if ( empty( $conn['key_set'] ) || '' === (string) ( $conn['base_url_host'] ?? '' ) ) {
			return self::error( 'remote_not_configured', 'Chưa cấu hình Remote Zalo.', 'Nhập Base URL và khoá rồi lưu.', 'remote_not_configured', 400 );
		}
		if ( function_exists( 'set_transient' ) ) { set_transient( $rl_key, 1, self::CHECK_RATE_SECONDS ); }
		$probe = ( new BizCity_Remote_Zalo_Hub_Client() )->list_accounts();
		self::record_check( $probe, (string) ( $conn['key_fingerprint'] ?? '' ) );
		if ( empty( $probe['ok'] ) ) {
			return self::error( (string) ( $probe['error']['code'] ?? 'remote_auth_failed' ), 'Remote Zalo chưa kết nối được.', 'Kiểm tra khóa, Base URL và quyền truy cập của Remote Zalo Hub.', 'remote_auth_failed', 200, array( 'upstream_code' => (string) ( $probe['error']['upstream_code'] ?? '' ), 'transport_code' => (string) ( $probe['error']['transport_code'] ?? '' ) ) );
		}
		return rest_ensure_response( self::payload() );
	}

	public static function clear_settings( $request = null ) {
		if ( class_exists( 'BizCity_Remote_Zalo_Credentials' ) ) { BizCity_Remote_Zalo_Credentials::clear(); }
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::reset(); }
		if ( class_exists( 'BizCity_Remote_Zalo_Last_Check' ) ) { BizCity_Remote_Zalo_Last_Check::clear(); }
		// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS2 — no connection ⇒ no poll schedule (D82-23 disconnect).
		if ( class_exists( 'BizCity_Remote_Zalo_Poller' ) && method_exists( 'BizCity_Remote_Zalo_Poller', 'unschedule' ) ) { BizCity_Remote_Zalo_Poller::unschedule(); }
		return rest_ensure_response( self::payload() );
	}
	public static function ack_gap( $request = null ) {
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::ack_gap(); }
		return rest_ensure_response( self::payload() );
	}

	private static function record_check( array $probe, string $key_fingerprint ): void {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Last_Check' ) ) { return; }
		BizCity_Remote_Zalo_Last_Check::record( BizCity_Remote_Zalo_Last_Check::from_probe( $probe, get_current_user_id(), $key_fingerprint ) );
	}

	private static function payload(): array {
		$conn = class_exists( 'BizCity_Remote_Zalo_Credentials' ) ? BizCity_Remote_Zalo_Credentials::public_view() : array( 'base_url_host' => '', 'key_set' => false, 'key_fingerprint' => '', 'key_set_at' => '' );
		$cursor = class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ? BizCity_Remote_Zalo_Cursor_Store::get() : array( 'state' => 'unavailable' );
		$last_check = class_exists( 'BizCity_Remote_Zalo_Last_Check' ) ? BizCity_Remote_Zalo_Last_Check::get() : array();
		return array(
			'available'   => class_exists( 'BizCity_Remote_Zalo_Feature' ) && BizCity_Remote_Zalo_Feature::enabled(),
			'configured'  => ! empty( $conn['key_set'] ) && '' !== (string) ( $conn['base_url_host'] ?? '' ),
			'conn'        => $conn,
			'cursor'      => array( 'state' => $cursor['state'] ?? 'ok', 'last_ok_at' => $cursor['last_ok_at'] ?? '', 'gap_since' => $cursor['gap_since'] ?? null ),
			// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — last "Lưu và kiểm tra" / "Kiểm tra lại" outcome; see BizCity_Remote_Zalo_Last_Check.
			'last_check'  => array(
				'at'               => (string) ( $last_check['at'] ?? '' ),
				'status'           => (string) ( $last_check['status'] ?? 'unknown' ),
				'code'             => (string) ( $last_check['code'] ?? '' ),
				'checks'           => is_array( $last_check['checks'] ?? null ) ? $last_check['checks'] : array(),
				'accounts_total'   => (int) ( $last_check['accounts_total'] ?? 0 ),
				'accounts_running' => (int) ( $last_check['accounts_running'] ?? 0 ),
			),
			// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B9 — visible backlog state for throttled sends (04 §6.5 row 2).
			'queue'       => class_exists( 'BizCity_Remote_Zalo_Send_Queue' ) ? BizCity_Remote_Zalo_Send_Queue::stats() : array( 'pending' => 0, 'dead' => 0, 'oldest_queued_at' => null ),
			'accounts_linked' => 0,
		);
	}
	private static function error( string $code, string $message, string $hint, string $help_code, int $status, array $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $extra ) );
	}
}
