<?php
/** Remote Zalo Hub account linking REST owner — C2, revised XS1 (link service, owner route, LX-1 list). */

defined( 'ABSPATH' ) || exit;

// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS1 — the link itself lives in one service shared with the CRM route (XS3).
// Must stay ABOVE the guard below: PHP early-binds the top-level class, so that guard returns on first include.
if ( ! class_exists( 'BizCity_Remote_Zalo_Link_Service', false ) ) {
	require_once dirname( __DIR__ ) . '/class-remote-zalo-link-service.php';
}

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
	/** @var callable|null XS1 test seam: (string $bridge_id) => array{character_id:int}|null */
	public static $binding = null;
	/** @var callable|null XS1 test seam: (int $user_id) => string display name */
	public static $display = null;

	public static function init(): void {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Feature' ) || ! BizCity_Remote_Zalo_Feature::enabled() ) { return; }
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}
	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-remote/accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_accounts' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/link', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link_account' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/unlink', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unlink_account' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS1 (LX-2) — change who owns a linked nick, from the "Số đang kết nối" row.
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/owner', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_owner' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/login', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'login_start' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'login_status' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
		// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS6 (51 §4.6) — "Đồng bộ ngay" for one nick's Agent Guru → remote agent.
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/agent/sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sync_agent' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/threads', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_threads' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/zalo-remote/accounts/(?P<account_ref>[^/]+)/threads/(?P<thread_ref>[^/]+)/messages', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_messages' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
	}
	public static function can_manage(): bool { return current_user_can( 'manage_options' ); }

	/** LX-1 — one row per provisioned nick, merged with the local link state. Never `zaloUid`. */
	public static function list_accounts( $request = null ) {
		$client = self::client();
		$result = $client ? $client->list_accounts() : array( 'ok' => false, 'error' => array( 'code' => 'remote_not_loaded' ) );
		if ( empty( $result['ok'] ) ) { return self::error( (string) ( $result['error']['code'] ?? 'remote_unavailable' ), 'Không tải được danh sách nick Remote Zalo.', 'Kiểm tra cấu hình Remote Zalo rồi thử lại.', 'remote_unavailable', 502 ); }
		$items = is_array( $result['data']['items'] ?? null ) ? $result['data']['items'] : array();
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || '' === (string) ( $item['id'] ?? '' ) ) { continue; }
			$ref = (string) $item['id'];
			$bridge_id = BizCity_Remote_Zalo_Link_Service::PREFIX . $ref;
			$local = BizCity_Remote_Zalo_Link_Service::lookup( $bridge_id, self::deps() );
			// [2026-09-30 Claude Sonnet 5] PHASE-0.82 bugfix — unlink() only sets status=disconnected, it never
			// deletes the mapping row (CRM Inbox/history stay). `is_array($local)` alone stayed true forever after
			// unlink, so the table kept showing the row as fully linked (owner/Guru selects, Sửa Guru/Xem tin/unlink).
			$linked = is_array( $local ) && 'disconnected' !== (string) ( $local['status'] ?? '' );
			$owner = (int) ( $local['owner_user_id'] ?? 0 );
			$out[] = array(
				'account_ref'   => $ref,
				'bridge_id'     => $bridge_id,
				'label'         => (string) ( $item['label'] ?? '' ),
				'session'       => 'running' === (string) ( $item['status'] ?? '' ) ? 'connected' : 'stopped',
				'linked'        => $linked,
				'owner_user_id' => $owner,
				'owner_display' => $owner > 0 ? self::display( $owner ) : '',
				'crm_inbox_id'  => (int) ( $local['crm_inbox_id'] ?? 0 ),
				'binding'       => $linked ? self::binding( $bridge_id ) : null,
				'agent_sync'    => $linked ? self::agent_sync( $bridge_id ) : null,
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'accounts' => $out ) );
	}

	public static function link_account( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : array();
		$result = BizCity_Remote_Zalo_Link_Service::link( $ref, (int) ( $body['owner_user_id'] ?? 0 ), (int) get_current_user_id(), self::deps() );
		if ( empty( $result['ok'] ) ) { return self::link_error( (string) ( $result['code'] ?? 'remote_link_failed' ) ); }
		return rest_ensure_response( $result );
	}

	public static function set_owner( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : array();
		$result = BizCity_Remote_Zalo_Link_Service::set_owner( $ref, (int) ( $body['owner_user_id'] ?? 0 ), (int) get_current_user_id(), self::deps() );
		if ( empty( $result['ok'] ) ) { return self::link_error( (string) ( $result['code'] ?? 'remote_link_failed' ) ); }
		return rest_ensure_response( $result );
	}

	/** POST …/agent/sync — run the sync for THIS nick now (rate-limited by BizCity_Remote_Zalo_Agent_Sync's own repush log for drift; direct calls here are user-initiated and not throttled). */
	public static function sync_agent( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		$bridge_id = BizCity_Remote_Zalo_Link_Service::PREFIX . $ref;
		$local = BizCity_Remote_Zalo_Link_Service::lookup( $bridge_id, self::deps() );
		if ( ! is_array( $local ) ) { return self::error( 'remote_not_found', 'Nick chưa được gắn trên website.', 'Tải lại danh sách rồi thử lại.', 'remote_not_found', 404 ); }
		if ( ! class_exists( 'BizCity_Remote_Zalo_Agent_Sync' ) ) { return self::error( 'remote_not_loaded', 'Đồng bộ agent chưa được nạp.', 'Bật cờ Remote Zalo rồi thử lại.', 'remote_not_loaded', 503 ); }
		$character_id = (int) ( self::binding( $bridge_id )['character_id'] ?? 0 );
		$result = BizCity_Remote_Zalo_Agent_Sync::sync_one( $bridge_id, $character_id );
		return rest_ensure_response( array( 'ok' => true, 'account_ref' => $ref, 'agent_sync' => $result ) );
	}

	public static function unlink_account( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) ); $local = BizCity_Remote_Zalo_Link_Service::lookup( 'rzh:' . $ref, self::deps() );
		if ( ! is_array( $local ) ) { return self::error( 'remote_not_found', 'Nick chưa được gắn trên website.', 'Tải lại danh sách rồi thử lại.', 'remote_not_found', 404 ); }
		self::save_account( array( 'kind' => 'personal', 'owner_user_id' => (int) ( $local['owner_user_id'] ?? 0 ), 'label' => (string) ( $local['label'] ?? '' ), 'bridge_account_id' => 'rzh:' . $ref, 'crm_inbox_id' => (int) ( $local['crm_inbox_id'] ?? 0 ), 'status' => 'disconnected' ) );
		return rest_ensure_response( array( 'ok' => true, 'account_ref' => $ref, 'crm_inbox_id' => (int) ( $local['crm_inbox_id'] ?? 0 ) ) );
	}
	public static function login_start( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		if ( null === BizCity_Remote_Zalo_Link_Service::remote_account( $ref, self::deps() ) || ! self::client() ) { return self::error( 'remote_not_found', 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ); }
		$body = $request->get_json_params(); $force = is_array( $body ) && ! empty( $body['force'] );
		$result = self::client()->login( $ref, $force );
		return self::client_response( $result, 'Không bắt đầu được phiên QR Remote Zalo.' );
	}
	public static function login_status( $request ) {
		$ref = sanitize_text_field( (string) $request->get_param( 'account_ref' ) );
		if ( null === BizCity_Remote_Zalo_Link_Service::remote_account( $ref, self::deps() ) || ! self::client() ) { return self::error( 'remote_not_found', 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ); }
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

	/** Seams handed to the link service; null props fall back to the real owners inside the service. */
	private static function deps(): array {
		$deps = array();
		foreach ( array( 'client', 'lookup', 'save', 'inbox', 'grant', 'flag', 'owner' ) as $key ) {
			if ( null !== self::$$key ) { $deps[ $key ] = self::$$key; }
		}
		return $deps;
	}
	private static function client() { return BizCity_Remote_Zalo_Link_Service::client( self::deps() ); }
	private static function save_account( array $data ): int { return is_callable( self::$save ) ? (int) call_user_func( self::$save, $data ) : ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? (int) BizCity_Zalo_Mapping_Repo::save_account( $data ) : 0 ); }
	private static function binding( string $bridge_id ): ?array {
		if ( is_callable( self::$binding ) ) { $row = call_user_func( self::$binding, $bridge_id ); }
		else { $row = class_exists( 'BizCity_Channel_Binding' ) && method_exists( 'BizCity_Channel_Binding', 'resolve' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id ) : null; }
		$cid = is_array( $row ) ? (int) ( $row['character_id'] ?? 0 ) : 0;
		return $cid > 0 ? array( 'character_id' => $cid ) : null;
	}
	private static function agent_sync( string $bridge_id ): ?array {
		return class_exists( 'BizCity_Remote_Zalo_Agent_Sync' ) ? BizCity_Remote_Zalo_Agent_Sync::status_for( $bridge_id ) : null;
	}
	private static function display( int $user_id ): string {
		if ( is_callable( self::$display ) ) { return (string) call_user_func( self::$display, $user_id ); }
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		return is_object( $user ) ? (string) ( $user->display_name ?? '' ) : '';
	}
	private static function link_error( string $code ) {
		$map = array(
			'invalid_param'           => array( 'Nick hoặc chủ sở hữu không hợp lệ.', 'Chọn nick và thành viên thuộc website này.', 'invalid_param_generic', 400 ),
			'remote_not_found'        => array( 'Không tìm thấy nick Remote Zalo.', 'Tải lại danh sách nick rồi thử lại.', 'remote_not_found', 404 ),
			'crm_inbox_create_failed' => array( 'Không tạo được CRM Inbox cho nick này.', 'Kiểm tra CRM rồi thử lại.', 'crm_inbox_create_failed', 500 ),
			'mapping_insert_failed'   => array( 'Không lưu được liên kết nick.', 'Kiểm tra mapping Zalo rồi thử lại.', 'mapping_insert_failed', 500 ),
			'permission_denied'       => array( 'Không cấp được quyền sở hữu nick.', 'Kiểm tra thành viên và hạn mức rồi thử lại.', 'permission_denied', 403 ),
		);
		$row = $map[ $code ] ?? array( 'Không gắn được nick Remote Zalo.', 'Thử lại sau.', 'remote_link_failed', 500 );
		return self::error( $code, $row[0], $row[1], $row[2], $row[3] );
	}
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
