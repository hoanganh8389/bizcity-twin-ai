<?php
/**
 * Verify a Zalo UID by a one-time link (PHASE-0.87 CL-14, D-TAA-6, seam `uid-verify@1` → HB-2 → BC-11).
 *
 *   POST bizcity-channel/v1/bot/policy/{binding_id}/verify-uid  {target:"owner"} | {user_id}
 *        ⇒ the number sends ONE 1-1 Zalo message to that UID with a link (15 min, single use) — via Hub → cell.
 *   GET  /?bizcity_uid_verify=<token>   (front end; login required)
 *        ⇒ opened while logged in as the matching WordPress user ⇒ the UID is verified for that person on that number.
 *
 * Proves "this Zalo really belongs to this website user". Unverified people keep working (Q-W2-1); the UI only labels them.
 * What is stored: owner ⇒ policy `owner_uid_verified {uid, at}` (valid only while `owner_uid` is still that UID);
 * staff ⇒ `staff_principals[].verified_at` (cleared when the UID is edited). The token lives only as a sha256 key of a
 * 15-minute transient; the raw token is never stored or logged. No LLM, no table.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Uid_Verify', false ) ) {
	return;
}

final class BizCity_Zalo_Uid_Verify {

	const CONTRACT    = 'uid-verify@1.0.0';
	const HUB_PATH    = '/zalo-hub/uid-verify';
	const QUERY_ARG   = 'bizcity_uid_verify';
	const TTL         = 900;  // 15 minutes (D-TAA-6)
	const RESEND_GAP  = 60;   // one link per person per number per minute
	const OWNER_KEY   = 'owner_uid_verified';
	const TOKEN_PREFIX = 'bizcity_uidv_';

	const MESSAGES = array(
		'uid_verify_forbidden'   => array( 'Cần quyền quản trị site hoặc supervisor CRM (hoặc chính người đó).', 'Nhờ quản trị site gửi link xác minh.' ),
		'uid_verify_no_uid'      => array( 'Chưa có UID Zalo để xác minh.', 'Điền "UID chủ tài khoản" (hoặc chọn Zalo cho nhân sự) rồi lưu trước.' ),
		'uid_verify_no_user'     => array( 'Số chưa có chủ WordPress.', 'Gán chủ WordPress cho số rồi thử lại.' ),
		'uid_verify_not_found'   => array( 'Người này không có trong danh sách dùng Agent của số.', 'Tải lại danh sách rồi thử lại.' ),
		'uid_verify_too_soon'    => array( 'Vừa gửi link cho người này.', 'Đợi 1 phút rồi gửi lại; link cũ vẫn dùng được trong 15 phút.' ),
		'uid_verify_unsupported' => array( 'Hub hoặc máy chủ Zalo Hub chưa hỗ trợ xác minh bằng link.', 'Báo quản trị nền tảng cập nhật Hub và cell.' ),
		'uid_not_principal'      => array( 'Máy chủ Zalo Hub chưa nhận người này là chủ hoặc nhân sự của số.', 'Cấu hình mới cần khoảng 1 phút để tới máy chủ — đợi rồi gửi lại.' ),
		'account_not_ready'      => array( 'Số Zalo đang không chạy.', 'Kết nối lại số rồi gửi lại.' ),
		'link_host_mismatch'     => array( 'Địa chỉ website của link không khớp với website đã gắn số này ở Hub.', 'Kiểm tra địa chỉ website (https, đúng tên miền) trong cài đặt kết nối Hub.' ),
		'account_not_found'      => array( 'Hub không thấy số này là số Zalo Hub đang hoạt động của site.', 'Kiểm tra số đã kết nối qua Zalo Hub và API key của site.' ),
		'cell_unreachable'       => array( 'Hub không liên lạc được máy chủ Zalo Hub của số.', 'Gửi lại sau ít phút.' ),
		'rate_limited'           => array( 'Số này đã gửi nhiều link xác minh trong 10 phút qua.', 'Đợi 10 phút rồi gửi lại.' ),
		'uid_verify_send_failed' => array( 'Không gửi được link xác minh.', 'Thử lại sau; nếu vẫn lỗi, xem trạng thái kết nối Hub.' ),
	);

	/**
	 * Test seams: binding(id): ?array · save(binding_id, policy): bool · owner_user_id(bridge): int · user_hash(user_id): string ·
	 * current_user(): int · can_manage(): bool · send(body): array · now(): int · token(): string · home(): string
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_landing' ), 1 );
	}

	public static function register_routes(): void {
		register_rest_route( 'bizcity-channel/v1', '/bot/policy/(?P<binding_id>\d+)/verify-uid', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_send' ),
			'permission_callback' => static function () { return function_exists( 'is_user_logged_in' ) && is_user_logged_in(); },
		) );
	}

	/* ── send ─────────────────────────────────────────────────────── */

	public static function handle_send( WP_REST_Request $req ): WP_REST_Response {
		$binding_id = (int) $req->get_param( 'binding_id' );
		$b          = self::binding( $binding_id );
		if ( null === $b ) {
			return self::error( 'uid_verify_not_found', 404 );
		}
		$body   = $req->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		$target = self::target( $b, $body );
		if ( isset( $target['error'] ) ) {
			return self::error( $target['error'], $target['status'] );
		}
		// A manager may send to anyone on the number; anyone else only to themselves (the link still needs their login).
		if ( ! self::can_manage() && self::current_user() !== (int) $target['user_id'] ) {
			return self::error( 'uid_verify_forbidden', 403 );
		}
		$gap_key = self::TOKEN_PREFIX . 'gap_' . md5( $b['bridge_id'] . '|' . $target['user_id'] . '|' . $target['uid'] );
		if ( function_exists( 'get_transient' ) && get_transient( $gap_key ) ) {
			return self::error( 'uid_verify_too_soon', 429 );
		}

		$token = self::new_token();
		$exp   = self::now() + self::TTL;
		$row   = array(
			'binding_id' => $binding_id,
			'bridge_id'  => $b['bridge_id'],
			'user_id'    => (int) $target['user_id'],
			'role'       => $target['role'],
			'uid'        => $target['uid'],
			'exp'        => $exp,
		);
		set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), $row, self::TTL );

		$res = self::send( array(
			'contract'        => self::CONTRACT,
			'account_id'      => $b['bridge_id'],
			'uid'             => $target['uid'],
			'role'            => $target['role'],
			'user_hash'       => self::user_hash( (int) $target['user_id'] ),
			'link'            => self::link( $token ),
			'expires_at'      => gmdate( 'Y-m-d\TH:i:s\Z', $exp ),
			'idempotency_key' => 'uv:' . substr( hash( 'sha256', 'uv|' . $token ), 0, 16 ),
		) );
		if ( empty( $res['success'] ) && empty( $res['ok'] ) ) {
			delete_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ) ); // a link nobody received must not stay valid
			$code = (string) ( $res['code'] ?? $res['data']['code'] ?? '' );
			$http = (int) ( $res['http_code'] ?? $res['status'] ?? 0 );
			if ( 404 === $http || in_array( $code, array( 'rest_no_route', 'cell_route_missing' ), true ) ) {
				$code = 'uid_verify_unsupported';
			}
			return self::error( isset( self::MESSAGES[ $code ] ) ? $code : 'uid_verify_send_failed', 502 );
		}
		set_transient( $gap_key, 1, self::RESEND_GAP );
		return new WP_REST_Response( array( 'ok' => true, 'sent' => true, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $exp ), 'uid_masked' => self::mask( $target['uid'] ) ), 200 );
	}

	/** @return array{role:string,user_id:int,uid:string}|array{error:string,status:int} */
	private static function target( array $b, array $body ): array {
		if ( 'owner' === (string) ( $body['target'] ?? '' ) ) {
			$uid  = self::clean_uid( (string) ( $b['policy']['owner_uid'] ?? '' ) );
			$user = self::owner_user_id( $b['bridge_id'] );
			if ( '' === $uid ) {
				return array( 'error' => 'uid_verify_no_uid', 'status' => 422 );
			}
			if ( $user <= 0 ) {
				return array( 'error' => 'uid_verify_no_user', 'status' => 422 );
			}
			return array( 'role' => 'owner', 'user_id' => $user, 'uid' => $uid );
		}
		$user = (int) ( $body['user_id'] ?? 0 );
		foreach ( self::staff( $b['policy'] ) as $row ) {
			if ( (int) $row['user_id'] === $user ) {
				return array( 'role' => 'staff', 'user_id' => $user, 'uid' => (string) $row['zalo_uid'] );
			}
		}
		return array( 'error' => 'uid_verify_not_found', 'status' => 404 );
	}

	/* ── landing ──────────────────────────────────────────────────── */

	public static function maybe_landing(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the token IS the nonce
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect(); // back to this exact URL after login
			return;
		}
		$res = self::confirm( (string) wp_unslash( $_GET[ self::QUERY_ARG ] ), self::current_user() ); // phpcs:ignore
		wp_die( esc_html( $res['message'] ), esc_html__( 'Xác minh Zalo', 'bizcity-twin-ai' ), array( 'response' => $res['ok'] ? 200 : 400, 'back_link' => false ) );
	}

	/**
	 * Consume a token for the logged-in user. A wrong user does NOT consume it (they can log in as the right one).
	 *
	 * @return array{ok:bool,code:string,message:string}
	 */
	public static function confirm( string $token, int $user_id ): array {
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );
		$key   = self::TOKEN_PREFIX . hash( 'sha256', (string) $token );
		$row   = '' !== $token && function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( ! is_array( $row ) || (int) ( $row['exp'] ?? 0 ) < self::now() ) {
			return self::landing( false, 'expired', 'Link đã hết hạn hoặc đã được dùng. Nhờ quản trị gửi link mới trong Bot Studio.' );
		}
		if ( (int) $row['user_id'] !== $user_id ) {
			return self::landing( false, 'wrong_user', 'Link này dành cho một tài khoản khác. Đăng xuất rồi đăng nhập đúng tài khoản của bạn, sau đó mở lại link.' );
		}
		$b = self::binding( (int) $row['binding_id'] );
		if ( null === $b || $b['bridge_id'] !== (string) $row['bridge_id'] ) {
			delete_transient( $key );
			return self::landing( false, 'gone', 'Số Zalo này không còn gắn Agent. Không có gì để xác minh.' );
		}
		$policy = $b['policy'];
		$at     = gmdate( 'Y-m-d\TH:i:s\Z', self::now() );
		if ( 'owner' === $row['role'] ) {
			if ( self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) ) !== (string) $row['uid'] ) {
				delete_transient( $key );
				return self::landing( false, 'uid_changed', 'UID chủ của số đã đổi sau khi gửi link. Gửi link mới để xác minh UID hiện tại.' );
			}
			$policy[ self::OWNER_KEY ] = array( 'uid' => (string) $row['uid'], 'at' => $at );
		} else {
			$found = false;
			$list  = self::staff( $policy );
			foreach ( $list as $i => $s ) {
				if ( (int) $s['user_id'] === $user_id && (string) $s['zalo_uid'] === (string) $row['uid'] ) {
					$list[ $i ]['verified_at'] = $at;
					$found = true;
				}
			}
			if ( ! $found ) {
				delete_transient( $key );
				return self::landing( false, 'uid_changed', 'Zalo của bạn ở số này đã đổi hoặc bạn không còn trong danh sách. Nhờ quản trị gửi link mới.' );
			}
			$policy['staff_principals'] = $list;
		}
		if ( ! self::save( (int) $row['binding_id'], $policy ) ) {
			return self::landing( false, 'save_failed', 'Chưa lưu được xác minh. Mở lại link sau ít phút.' );
		}
		delete_transient( $key ); // single use
		do_action( 'bizcity_bot_config_changed', 'binding', (int) $row['binding_id'] ); // bundle carries uid_verified_at ≤ 60 s
		return self::landing( true, 'verified', 'Đã xác minh Zalo ' . self::mask( (string) $row['uid'] ) . ' là của bạn. Bạn có thể đóng trang này.' );
	}

	/** Owner verification still valid = stored for the CURRENT owner UID. */
	public static function owner_verified_at( array $policy ): ?string {
		$v   = $policy[ self::OWNER_KEY ] ?? null;
		$uid = self::clean_uid( (string) ( $policy['owner_uid'] ?? '' ) );
		return is_array( $v ) && '' !== $uid && (string) ( $v['uid'] ?? '' ) === $uid && '' !== (string) ( $v['at'] ?? '' ) ? (string) $v['at'] : null;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	private static function landing( bool $ok, string $code, string $message ): array {
		return array( 'ok' => $ok, 'code' => $code, 'message' => $message );
	}

	/** @return array{binding_id:int,bridge_id:string,policy:array}|null */
	private static function binding( int $id ): ?array {
		$row = isset( self::$readers['binding'] ) ? call_user_func( self::$readers['binding'], $id ) : ( class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::find( $id ) : null );
		if ( ! is_array( $row ) || 'ZALO_PERSONAL' !== strtoupper( (string) ( $row['platform'] ?? '' ) ) ) {
			return null;
		}
		$raw    = $row['policy_json'] ?? '';
		$policy = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return array( 'binding_id' => $id, 'bridge_id' => (string) ( $row['account_id'] ?? '' ), 'policy' => is_array( $policy ) ? $policy : array() );
	}

	private static function save( int $binding_id, array $policy ): bool {
		if ( class_exists( 'BizCity_Bot_REST' ) ) {
			$policy = BizCity_Bot_REST::policy_defaults_merged( $policy ); // keeps owner_uid_verified + staff_principals
		}
		if ( isset( self::$readers['save'] ) ) {
			return (bool) call_user_func( self::$readers['save'], $binding_id, $policy );
		}
		return class_exists( 'BizCity_Channel_Binding' ) && BizCity_Channel_Binding::save_policy( $binding_id, $policy );
	}

	private static function staff( array $policy ): array {
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::from_policy( $policy ) : array();
	}

	private static function send( array $body ): array {
		if ( isset( self::$readers['send'] ) ) {
			return (array) call_user_func( self::$readers['send'], $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'success' => false, 'code' => 'uid_verify_send_failed' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::HUB_PATH, $body );
	}

	private static function link( string $token ): string {
		$home = isset( self::$readers['home'] ) ? (string) call_user_func( self::$readers['home'] ) : home_url( '/' );
		return add_query_arg( self::QUERY_ARG, $token, $home );
	}

	private static function new_token(): string {
		return isset( self::$readers['token'] ) ? (string) call_user_func( self::$readers['token'] ) : bin2hex( random_bytes( 24 ) );
	}

	private static function owner_user_id( string $bridge ): int {
		if ( isset( self::$readers['owner_user_id'] ) ) {
			return (int) call_user_func( self::$readers['owner_user_id'], $bridge );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::owner_user_id( $bridge ) : 0;
	}

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::user_hash( $user_id ) : '';
	}

	private static function can_manage(): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'] );
		}
		return class_exists( 'BizCity_Zalo_Staff_Principals_REST' ) && BizCity_Zalo_Staff_Principals_REST::can_manage();
	}

	private static function current_user(): int {
		return isset( self::$readers['current_user'] ) ? (int) call_user_func( self::$readers['current_user'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function clean_uid( string $uid ): string {
		$uid = trim( $uid );
		return preg_match( '/^\d{5,32}$/', $uid ) ? $uid : '';
	}

	private static function mask( string $uid ): string {
		return '' === $uid ? '' : '…' . substr( $uid, -4 );
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? self::MESSAGES['uid_verify_send_failed'];
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S87-UV-' . $status ), $status );
	}
}
