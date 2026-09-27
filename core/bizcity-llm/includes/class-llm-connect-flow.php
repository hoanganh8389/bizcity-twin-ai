<?php
/**
 * PHASE-0.80 doc 26 (OB-5) — site side of "Kết nối BizCity" one-click (no my-account visit, no
 * copy/paste). Counterpart of Hub's BizCity_Router_Connect_Flow (bizcity-llm-router). This is the
 * ONE owner that writes `bizcity_llm_api_key` from this flow — it never bypasses the existing
 * key format check, matching the manual paste path (`class-llm-settings.php::ajax_save_key`).
 *
 * Flow (PKCE, RFC 7636 — see the Hub class's docblock for the full picture):
 *   1. handle_start() (admin-post, this site): make {state, code_verifier}, keep code_verifier in
 *      a 10-minute transient, redirect the ADMIN'S BROWSER to the Hub's /connect with
 *      {site, return=this site's return_url, state, code_challenge, code_challenge_method=S256}.
 *   2. GET bizcity-connect/v1/proof?state=… (public, called BY THE HUB, server-to-server): answers
 *      {ok:true} only while that exact state is still pending here — this callback IS the domain
 *      control proof (only the box that can answer HTTP on this domain has that transient).
 *   3. maybe_handle_return() (admin_init, this site): the browser comes back with {code,state} or
 *      {error,state}; exchange the code server-to-server for the plaintext key and save it, or
 *      show a plain-language reason why not.
 *
 * // [2026-09-27 Claude Sonnet 5] PHASE-0.80 doc 26 OB-5
 *
 * @package BizCity_LLM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_LLM_Connect_Flow {

	const NS             = 'bizcity-connect/v1';
	const PENDING_PREFIX = 'bizcity_connect_pending_';
	const PENDING_TTL    = 600; // 10 minutes
	const NOTICE_PREFIX  = 'bizcity_connect_notice_';
	const RETURN_PARAMS  = array( 'bizcity_connect_return', 'code', 'state', 'error', 'owner' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_bizcity_connect_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_return' ), 5 );
	}

	public static function can(): bool {
		return class_exists( 'BizCity_Zalo_Bridge_REST' ) ? BizCity_Zalo_Bridge_REST::can_manage() : current_user_can( 'manage_options' );
	}

	/** wp-admin trigger for the "Kết nối BizCity" button — a whole-page navigation, not a fetch. */
	public static function start_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=bizcity_connect_start' ), 'bizcity_connect_start' );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/proof', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_proof' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * [2026-09-27, G-16] The Hub's verify_domain_proof() (OB-5 G-3 fix) requires this response to
	 * echo the SAME PKCE challenge the browser was issued — not just {ok}. The challenge is not
	 * stored separately; it's the deterministic hash of the code_verifier we already keep, so there
	 * is nothing new to persist here — just compute it the same way build_start() derived it for
	 * the Hub in the first place (see challenge_for(), identical formula on both sides).
	 */
	public static function handle_proof( $request ) {
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$pending = $state !== '' ? get_transient( self::PENDING_PREFIX . $state ) : false;
		$ok = is_array( $pending );
		$body = array( 'ok' => $ok );
		if ( $ok ) {
			$body['code_challenge'] = self::challenge_for( (string) $pending['code_verifier'] );
		}
		return new WP_REST_Response( $body, $ok ? 200 : 404 );
	}

	public static function state_is_pending( string $state ): bool {
		if ( $state === '' ) {
			return false;
		}
		return is_array( get_transient( self::PENDING_PREFIX . $state ) );
	}

	/* ================================================================
	 *  Start: kick the browser to the Hub with a fresh {state, PKCE challenge}
	 * ================================================================ */

	public static function handle_start(): void {
		check_admin_referer( 'bizcity_connect_start' );
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'bizcity-twin-ai' ) );
		}
		$state = self::random_token( 16 );
		$verifier = self::random_token( 32 );
		set_transient( self::PENDING_PREFIX . $state, array( 'code_verifier' => $verifier, 'created_at' => time() ), self::PENDING_TTL );
		wp_redirect( self::build_connect_url( self::gateway_url(), home_url(), self::return_url(), $state, self::challenge_for( $verifier ) ) );
		exit;
	}

	public static function return_url(): string {
		return admin_url( 'admin.php?page=bizcity-start&bizcity_connect_return=1' );
	}

	public static function build_connect_url( string $gateway, string $site, string $return, string $state, string $challenge ): string {
		return rtrim( $gateway, '/' ) . '/wp-json/bizcity/v1/connect?' . http_build_query( array(
			'site' => $site, 'return' => $return, 'state' => $state,
			'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
		) );
	}

	/* ================================================================
	 *  Return: pure decision (testable), then a thin admin_init wrapper.
	 * ================================================================ */

	/**
	 * @return array{action:'noop'}|array{action:'error',code:string,message:string}|array{action:'exchange',code:string,code_verifier:string}
	 */
	public static function resolve_return( array $get ): array {
		if ( empty( $get['bizcity_connect_return'] ) ) {
			return array( 'action' => 'noop' );
		}
		$state = sanitize_text_field( (string) ( $get['state'] ?? '' ) );
		if ( $state === '' ) {
			return array( 'action' => 'noop' );
		}
		$pending = get_transient( self::PENDING_PREFIX . $state );
		delete_transient( self::PENDING_PREFIX . $state ); // single use, same as the Hub's exchange code
		if ( ! is_array( $pending ) ) {
			return array( 'action' => 'error', 'code' => 'state_expired', 'message' => 'Phiên kết nối đã hết hạn. Bấm "Kết nối BizCity" để thử lại.' );
		}
		if ( ! empty( $get['error'] ) ) {
			return array( 'action' => 'error', 'code' => sanitize_text_field( (string) $get['error'] ), 'message' => self::error_message( sanitize_text_field( (string) $get['error'] ), sanitize_text_field( (string) ( $get['owner'] ?? '' ) ) ) );
		}
		$code = sanitize_text_field( (string) ( $get['code'] ?? '' ) );
		if ( $code === '' ) {
			return array( 'action' => 'error', 'code' => 'no_code', 'message' => 'Không nhận được mã kết nối từ BizCity. Thử lại.' );
		}
		return array( 'action' => 'exchange', 'code' => $code, 'code_verifier' => (string) $pending['code_verifier'] );
	}

	/** One plain-language sentence per Hub error code — never the raw code alone. */
	public static function error_message( string $code, string $owner = '' ): string {
		switch ( $code ) {
			case 'domain_proof_failed':
				return 'BizCity không xác minh được website (không gọi tới được /wp-json/bizcity-connect/v1/proof). Kiểm tra tường lửa hoặc plugin bảo mật đang chặn /wp-json rồi thử lại.';
			case 'domain_taken':
				return 'Tên miền này đã thuộc một tài khoản BizCity khác' . ( $owner !== '' ? ' (' . $owner . ')' : '' ) . '. Đăng nhập đúng tài khoản đó trên bizcity.vn, hoặc liên hệ hỗ trợ.';
			case 'session_expired':
			case 'state_expired':
				return 'Phiên xác nhận đã hết hạn. Bấm "Kết nối BizCity" để thử lại.';
			case 'rotate_cooldown':
				return 'Website vừa kết nối xong. Vui lòng đợi ít phút rồi thử lại.';
			case 'no_code':
				return 'Không nhận được mã kết nối từ BizCity. Thử lại.';
			default:
				return 'Không kết nối được BizCity. Thử lại sau ít phút hoặc dùng cách "Tôi đã có mã — dán vào đây".';
		}
	}

	/** Server-to-server: trade the one-time code for the real key. Never logs the plaintext. */
	public static function exchange( string $code, string $code_verifier ): array {
		$response = wp_remote_post( rtrim( self::gateway_url(), '/' ) . '/wp-json/bizcity/v1/connect/token', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'    => wp_json_encode( array( 'code' => $code, 'code_verifier' => $code_verifier, 'site' => home_url() ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 'hub_unreachable', 'message' => 'Không liên lạc được với BizCity. Thử lại sau ít phút.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status !== 200 || ! is_array( $body ) || empty( $body['success'] ) || empty( $body['api_key'] ) ) {
			$code_out = is_array( $body ) ? (string) ( $body['code'] ?? 'exchange_failed' ) : 'exchange_failed';
			return array( 'ok' => false, 'code' => $code_out, 'message' => self::error_message( $code_out ) );
		}
		return array( 'ok' => true, 'api_key' => (string) $body['api_key'], 'key_id' => (int) ( $body['key_id'] ?? 0 ), 'domain' => (string) ( $body['domain'] ?? '' ), 'plan' => (array) ( $body['plan'] ?? array() ) );
	}

	/** Same option, same format gate as the manual paste flow (class-llm-settings.php::ajax_save_key) — one key owner. */
	public static function save_api_key( string $api_key ): bool {
		if ( ! preg_match( '/^biz[-_][a-z0-9]{16,80}$/i', $api_key ) ) {
			return false;
		}
		update_option( 'bizcity_llm_api_key', $api_key );
		update_option( 'bizcity_llm_mode', 'gateway' );
		return true;
	}

	/**
	 * Everything the return trip needs to decide, with zero redirects/exits — the pure seam the
	 * tests call. The one caller that DOES redirect (maybe_handle_return) is a one-liner.
	 *
	 * @return array{type:'noop'}|array{type:'done',notice:array}
	 */
	public static function process_return( array $get ): array {
		$decision = self::resolve_return( $get );
		if ( $decision['action'] === 'noop' ) {
			return array( 'type' => 'noop' );
		}
		if ( $decision['action'] === 'error' ) {
			return array( 'type' => 'done', 'notice' => array( 'status' => 'error', 'code' => $decision['code'], 'message' => $decision['message'] ) );
		}
		$result = self::exchange( $decision['code'], $decision['code_verifier'] );
		if ( empty( $result['ok'] ) ) {
			return array( 'type' => 'done', 'notice' => array( 'status' => 'error', 'code' => $result['code'], 'message' => $result['message'] ) );
		}
		if ( ! self::save_api_key( $result['api_key'] ) ) {
			return array( 'type' => 'done', 'notice' => array( 'status' => 'error', 'code' => 'invalid_key_format', 'message' => 'BizCity trả về mã kết nối không đúng định dạng. Thử lại.' ) );
		}
		return array( 'type' => 'done', 'notice' => array( 'status' => 'success', 'domain' => $result['domain'], 'plan' => $result['plan'] ) );
	}

	public static function maybe_handle_return(): void {
		if ( wp_doing_ajax() || ! self::can() ) {
			return;
		}
		$outcome = self::process_return( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- `state` IS the one-time token; nothing here mutates without it matching a pending transient
		if ( $outcome['type'] === 'noop' ) {
			return;
		}
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $outcome['notice'], 60 );
		wp_safe_redirect( remove_query_arg( self::RETURN_PARAMS ) );
		exit;
	}

	/** Read-once notice for whoever renders the start page (BizCity_Zalo_Start_Page, doc 26 OB-4). */
	public static function pop_notice(): ?array {
		$key = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}
		return is_array( $notice ) ? $notice : null;
	}

	/* ================================================================
	 *  Small helpers
	 * ================================================================ */

	private static function gateway_url(): string {
		return class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->get_gateway_url() : 'https://bizcity.vn';
	}

	private static function random_token( int $bytes ): string {
		return self::base64url_encode( random_bytes( $bytes ) );
	}

	public static function challenge_for( string $verifier ): string {
		return self::base64url_encode( hash( 'sha256', $verifier, true ) );
	}

	private static function base64url_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
