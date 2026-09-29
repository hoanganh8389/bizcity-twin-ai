<?php
/** Remote Zalo Hub account linking REST owner — C2. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Accounts_REST', false ) ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C2 — link server-listed remote accounts into existing mapping/CRM/grant owners.
final class BizCity_Remote_Zalo_Accounts_REST {
	const NS = 'bizcity-channel/v1';
	public static $client = null;
	public static $lookup = null;
	public static $save = null;
	public static $inbox = null;
	public static $grant = null;
	public static $flag = null;
	public static $owner = null;

	public static function init(): void {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Feature' ) || ! BizCity_Remote_Zalo_Feature::enabled() ) { return; }
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}
	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-remote/accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_accounts' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/link', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link_account' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/unlink', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unlink_account' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/login', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'login_start' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'login_status' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/threads', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_threads' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/threads/(?P<thread_ref>[^/]+)/messages', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_messages' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
	}
	public static function can_manage(): bool { return current_user_can( 'manage_options' ); }
	public static function list_accounts( $request = null ) {
		$client = self::client();
		$result = $client ? $client->list_accounts() : array( 'ok' => false, 'error' => array( 'code' => 'remote_not_loaded' ) );
		if ( empty( $result['ok'] ) ) { return self::error( (string) ( $result['error']['code'] ?? 'remote_unavailable' ), 'Không tải được danh sách nick Remote Zalo.', 'Kiểm tra cấu hình Remote Zalo rồi thử lại.', 'remote_unavailable', 502 ); }
		$items = is_array( $result['data']['items'] ?? null ) ? $result['data']['items'] : array();
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || '' === (string) ( $item['id'] ?? '' ) ) { continue; }
			$ref = (string) $item['id'];
			$local = self::lookup( 'rzh:' . $ref );
			$out[] = array( 'account_ref' => $ref, 'label' => (string) ( $item['label'] ?? '' ), 'session' => 'running' === (string) ( $item['status'] ?? '' ) ? 'connected' : 'stopped', 'linked' => is_array( $local ), 'owner_user_id' => (int) ( $local['owner_user_id'] ?? 0 ), 'crm_inbox_id' => (int) ( $local['crm_inbox_id'] ?? 0 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'accounts' => $out ) );
	}
	public static function link_account( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : array();
		$owner = (int) ( $body['owner_user_id'] ?? 0 );
		if ( '' === $ref || $owner <= 0 || ! self::valid_owner( $owner ) ) { return self::error( 'invalid_param', 'Nick hoặc chủ sở hữu không hợp lệ.', 'Chọn nick và thành viên thuộc website này.', 'invalid_param_generic', 400 ); }
		$remote = self::remote_account( $ref );
		if ( null === $remote ) { return self::error( 'remote_not_found', 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ); }
		$bridge_id = 'rzh:' . $ref;
		$inbox_id = self::upsert_inbox( $bridge_id, (string) ( $remote['label'] ?? $ref ) );
		if ( $inbox_id <= 0 ) { return self::error( 'crm_inbox_create_failed', 'Không tạo được CRM Inbox cho nick này.', 'Kiểm tra CRM rồi thử lại.', 'crm_inbox_create_failed', 500 ); }
		$local_id = self::save_account( array( 'kind' => 'personal', 'owner_user_id' => $owner, 'label' => (string) ( $remote['label'] ?? $ref ), 'bridge_account_id' => $bridge_id, 'zalo_uid' => '', 'crm_inbox_id' => $inbox_id, 'status' => 'running' === (string) ( $remote['status'] ?? '' ) ? 'connected' : 'disconnected' ) );
		if ( $local_id <= 0 ) { return self::error( 'mapping_insert_failed', 'Không lưu được liên kết nick.', 'Kiểm tra mapping Zalo rồi thử lại.', 'mapping_insert_failed', 500 ); }
		$grant = self::grant( $bridge_id, $owner );
		if ( empty( $grant['ok'] ) ) { return self::error( 'permission_denied', 'Không cấp được quyền sở hữu nick.', 'Kiểm tra thành viên và hạn mức rồi thử lại.', 'permission_denied', 403 ); }
		if ( is_callable( self::$flag ) ) { call_user_func( self::$flag, $bridge_id ); } elseif ( class_exists( 'BizCity_Zalo_Account_Flags' ) ) { BizCity_Zalo_Account_Flags::record( $bridge_id, array( 'provider' => 'remote_zalo_hub' ), 'remote_link' ); }
		return rest_ensure_response( array( 'ok' => true, 'account_ref' => $ref, 'crm_inbox_id' => $inbox_id, 'owner_user_id' => $owner ) );
	}
	public static function unlink_account( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) ); $local = self::lookup( 'rzh:' . $ref );
		if ( ! is_array( $local ) ) { return self::error( 'remote_not_found', 'Nick chưa được gắn trên website.', 'Tải lại danh sách rồi thử lại.', 'remote_not_found', 404 ); }
		self::save_account( array( 'kind' => 'personal', 'owner_user_id' => (int) ( $local['owner_user_id'] ?? 0 ), 'label' => (string) ( $local['label'] ?? '' ), 'bridge_account_id' => 'rzh:' . $ref, 'crm_inbox_id' => (int) ( $local['crm_inbox_id'] ?? 0 ), 'status' => 'disconnected' ) );
		return rest_ensure_response( array( 'ok' => true, 'account_ref' => $ref, 'crm_inbox_id' => (int) ( $local['crm_inbox_id'] ?? 0 ) ) );
	}
	public static function login_start( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		if ( null === self::remote_account( $ref ) || ! self::client() ) { return self::error( 'remote_not_found', 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ); }
		$body = $request->get_json_params(); $force = is_array( $body ) && ! empty( $body['force'] );
		$result = self::client()->login( $ref, $force );
		return self::client_response( $result, 'Không bắt đầu được phiên QR Remote Zalo.' );
	}
	public static function login_status( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		if ( null === self::remote_account( $ref ) || ! self::client() ) { return self::error( 'remote_not_found', 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ); }
		return self::client_response( self::client()->login_status( $ref ), 'Không đọc được trạng thái QR Remote Zalo.' );
	}
	public static function list_threads( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$query = array(); foreach ( array( 'q', 'limit', 'cursor' ) as $key ) { if ( null !== $request->get_param( $key ) && '' !== (string) $request->get_param( $key ) ) { $query[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) ); } }
		return self::client_response( self::client()->list_threads( $ref, $query ), 'Không đọc được danh sách cuộc trò chuyện Remote Zalo.' );
	}
	public static function list_messages( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$thread = sanitize_text_field( (string) $request->get_param( 'thread_ref' ) );
		$before = $request->get_param( 'before' );
		return self::client_response( self::client()->list_messages( $ref, $thread, null === $before ? null : sanitize_text_field( (string) $before ), 50 ), 'Không đọc được tin nhắn Remote Zalo.' );
	}
	private static function client() { return is_object( self::$client ) ? self::$client : ( class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ? new BizCity_Remote_Zalo_Hub_Client() : null ); }
	private static function remote_account( string $ref ): ?array { $result = self::client() ? self::client()->list_accounts() : array(); foreach ( (array) ( $result['data']['items'] ?? array() ) as $item ) { if ( is_array( $item ) && (string) ( $item['id'] ?? '' ) === $ref ) { return $item; } } return null; }
	private static function lookup( string $id ) { return is_callable( self::$lookup ) ? call_user_func( self::$lookup, $id ) : ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id ) : null ); }
	private static function save_account( array $data ): int { return is_callable( self::$save ) ? (int) call_user_func( self::$save, $data ) : ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? (int) BizCity_Zalo_Mapping_Repo::save_account( $data ) : 0 ); }
	private static function upsert_inbox( string $id, string $label ): int { return is_callable( self::$inbox ) ? (int) call_user_func( self::$inbox, $id, $label ) : ( class_exists( 'BizCity_CRM_Repository' ) ? (int) BizCity_CRM_Repository::upsert_inbox( 'zalo_personal', $id, array( 'name' => 'Zalo Cá nhân — ' . $label ) ) : 0 ); }
	private static function grant( string $id, int $owner ): array { return is_callable( self::$grant ) ? (array) call_user_func( self::$grant, $id, $owner ) : ( class_exists( 'BizCity_Channel_User_Grant' ) ? (array) BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', $id, $owner, get_current_user_id(), true, array( 'source' => 'remote_link' ) ) : array( 'ok' => false ) ); }
	private static function valid_owner( int $id ): bool { return is_callable( self::$owner ) ? (bool) call_user_func( self::$owner, $id ) : (bool) get_userdata( $id ); }
	private static function error( string $code, string $message, string $hint, string $help, int $status, array $extra = array() ) { return new WP_Error( $code, $message, array_merge( array( 'status' => $status, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help ), $extra ) ); }
	private static function client_response( array $result, string $fallback ) {
		if ( empty( $result['ok'] ) ) {
			$error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
			$report = array( 'http_status' => (int) ( $result['http_status'] ?? 0 ), 'code' => (string) ( $error['code'] ?? 'remote_unreachable' ), 'upstream_code' => (string) ( $error['upstream_code'] ?? '' ), 'transport_code' => (string) ( $error['transport_code'] ?? '' ), 'request_id' => (string) ( $result['request_id'] ?? '' ) );
			return self::error( (string) ( $error['code'] ?? 'remote_unreachable' ), $fallback, 'Gửi report này cho đơn vị vận hành Remote Zalo để họ kiểm tra API accounts:login.', 'remote_qr_unavailable', 400, array( 'report' => $report ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'data' => is_array( $result['data'] ?? null ) ? $result['data'] : array(), 'request_id' => (string) ( $result['request_id'] ?? '' ) ) );
	}
}
