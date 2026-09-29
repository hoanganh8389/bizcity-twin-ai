<?php
/** Remote Zalo Hub settings REST owner — C1. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Settings_REST', false ) ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C1 — own the single same-origin settings boundary for Remote Zalo Hub.
final class BizCity_Remote_Zalo_Settings_REST {
	const NS = 'bizcity-channel/v1';

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
	}
	public static function can_manage(): bool { return current_user_can( 'manage_options' ); }
	public static function get_settings( $request = null ) { return rest_ensure_response( self::payload() ); }
	public static function save_settings( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$base_url = trim( (string) ( $body['base_url'] ?? '' ) );
		$key = array_key_exists( 'api_key', $body ) ? (string) $body['api_key'] : null;
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) ) { return self::error( 'remote_not_loaded', 'Remote Zalo chưa được nạp.', 'Bật cờ Remote Zalo rồi thử lại.', 'remote_not_loaded', 503 ); }
		$result = BizCity_Remote_Zalo_Credentials::save( $base_url, $key );
		if ( empty( $result['ok'] ) ) { return self::error( (string) $result['code'], 'Không lưu được cấu hình Remote Zalo.', 'Sửa URL hoặc khóa rồi thử lại.', 'remote_settings_invalid', 400 ); }
		if ( class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) {
			$probe = ( new BizCity_Remote_Zalo_Hub_Client() )->list_accounts();
			if ( empty( $probe['ok'] ) ) {
				BizCity_Remote_Zalo_Credentials::clear();
				return self::error( (string) ( $probe['error']['code'] ?? 'remote_auth_failed' ), 'Remote Zalo chưa chấp nhận cấu hình.', 'Kiểm tra khóa, Base URL và quyền truy cập rồi thử lại.', 'remote_auth_failed', 400, array( 'upstream_code' => (string) ( $probe['error']['upstream_code'] ?? '' ), 'transport_code' => (string) ( $probe['error']['transport_code'] ?? '' ) ) );
			}
		}
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::reset(); }
		return rest_ensure_response( self::payload() );
	}
	public static function clear_settings( $request = null ) {
		if ( class_exists( 'BizCity_Remote_Zalo_Credentials' ) ) { BizCity_Remote_Zalo_Credentials::clear(); }
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::reset(); }
		return rest_ensure_response( self::payload() );
	}
	public static function ack_gap( $request = null ) {
		if ( class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ) { BizCity_Remote_Zalo_Cursor_Store::ack_gap(); }
		return rest_ensure_response( self::payload() );
	}
	private static function payload(): array {
		$conn = class_exists( 'BizCity_Remote_Zalo_Credentials' ) ? BizCity_Remote_Zalo_Credentials::public_view() : array( 'base_url_host' => '', 'key_set' => false, 'key_fingerprint' => '', 'key_set_at' => '' );
		$cursor = class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) ? BizCity_Remote_Zalo_Cursor_Store::get() : array( 'state' => 'unavailable' );
		return array( 'available' => class_exists( 'BizCity_Remote_Zalo_Feature' ) && BizCity_Remote_Zalo_Feature::enabled(), 'configured' => ! empty( $conn['key_set'] ) && '' !== (string) ( $conn['base_url_host'] ?? '' ), 'conn' => $conn, 'cursor' => array( 'state' => $cursor['state'] ?? 'ok', 'last_ok_at' => $cursor['last_ok_at'] ?? '', 'gap_since' => $cursor['gap_since'] ?? null ), 'accounts_linked' => 0 );
	}
	private static function error( string $code, string $message, string $hint, string $help_code, int $status, array $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $extra ) );
	}
}
