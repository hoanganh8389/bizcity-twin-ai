<?php
/**
 * BizCity Zalo Bridge Client — HTTP wrapper for zca-bridge sidecar (PHASE-0.39)
 *
 * Reads the explicit custom bridge options only when mode=custom_bridge:
 *   bizcity_zalo_bridge_url   — base URL of the custom Node sidecar
 *   bizcity_zalo_bridge_token — custom Bearer token for WP ↔ bridge authentication
 * Unset mode defaults to managed_1api through BizCity_LLM_Client.
 *
 * All methods return arrays (never throw). On sidecar error: { success:false, _degraded:true, message: ... }
 * R-ZP-3: NEVER return 5xx. Fail-OPEN so CRM Inbox does not retry-loop.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — sidecar HTTP wrapper, fail-OPEN (R-ZP-3)
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Bridge_Client {

	const OPTION_URL   = 'bizcity_zalo_bridge_url';
	const OPTION_TOKEN = 'bizcity_zalo_bridge_token';
	const OPTION_MODE  = 'bizcity_zalo_bridge_mode';
	const TIMEOUT      = 10;
	const HEALTH_CACHE = 'bizcity_zalo_bridge_health_cache';
	const HEALTH_TTL   = 30; // seconds

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private $url = '';

	/** @var string */
	private $token = '';

	private function __construct() {
		$this->url   = rtrim( (string) get_option( self::OPTION_URL, '' ), '/' );
		$this->token = (string) get_option( self::OPTION_TOKEN, '' );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Resolve the server-authorized bridge mode. */
	public function get_mode(): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — default all new and unset clients to managed 1API.
		return get_option( self::OPTION_MODE, 'managed_1api' ) === 'custom_bridge' ? 'custom_bridge' : 'managed_1api';
	}

	private function is_managed_mode(): bool {
		return $this->get_mode() === 'managed_1api';
	}

	/** Check the complete managed Hub client contract before invoking it. */
	private function managed_hub_available(): bool {
		// [2026-08-22 Johnny Chu] R-GW-8 — stale client deployments fail gracefully when the Hub singleton accessor is absent.
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'instance' );
	}

	// ── Public: configuration status ──────────────────────────────────────

	/** Fast check: URL + token configured (no HTTP call). */
	public function is_ready_fast(): bool {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() && BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast();
		}
		return $this->url !== '' && $this->token !== '';
	}

	/** Return the active mode's health envelope without exposing credentials. */
	public function health(): array {
		// [2026-08-22 Johnny Chu] HOTFIX-ZALO-HEALTH — expose one health boundary for managed Hub and custom sidecar modes.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available()
				? BizCity_Zalo_Personal_Hub_Client::instance()->health()
				: $this->degraded( 'managed_client_missing' );
		}
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'bridge_not_configured' );
		}
		return $this->get( 'wp/health' );
	}

	/** Read redacted sidecar diagnostics without exposing the bridge credential. */
	public function diagnostics( array $args = array() ): array {
		// [2026-08-23 Johnny Chu] PHASE-0.39E — diagnostics remain server-side and mode-aware.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available()
				? BizCity_Zalo_Personal_Hub_Client::instance()->diagnostics( $args )
				: $this->degraded( 'managed_client_missing' );
		}
		$map = array(
			'account_id' => 'accountId',
			'before_id'  => 'beforeId',
			'since'      => 'since',
			'level'      => 'level',
			'phase'      => 'phase',
			'trace_id'   => 'traceId',
			'limit'      => 'limit',
		);
		$query = array();
		foreach ( $map as $source => $target ) {
			if ( isset( $args[ $source ] ) && $args[ $source ] !== '' ) {
				$query[ $target ] = sanitize_text_field( (string) $args[ $source ] );
			}
		}
		$path = 'wp/diagnostics' . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		return $this->get( $path );
	}

	/** Full check: URL+token set AND sidecar responds to /health. Cached 30s. */
	public function is_ready(): bool {
		$cached = get_transient( self::HEALTH_CACHE );
		if ( $cached !== false ) {
			return (bool) $cached;
		}
		$result = $this->health();
		$ok     = ( ! empty( $result['success'] ) || ! empty( $result['ok'] ) )
			&& empty( $result['_degraded'] ) && empty( $result['degraded'] );
		set_transient( self::HEALTH_CACHE, $ok ? '1' : '0', self::HEALTH_TTL );
		return $ok;
	}

	// ── Public: account management ─────────────────────────────────────────

	/**
	 * List all accounts registered on the bridge.
	 *
	 * @return array { success:bool, accounts:array, _degraded?:bool }
	 */
	public function list_accounts(): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->list_accounts() : $this->degraded( 'managed_client_missing' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M /wp/accounts (Bearer), not cookie /admin.
		return $this->get( 'wp/accounts' );
	}

	/**
	 * Get single account by bridge ID.
	 *
	 * @param string $account_id
	 * @return array { success:bool, account:array, _degraded?:bool }
	 */
	public function get_account( string $account_id ): array {
		// [2026-06-07 Johnny Chu] PHASE-0.39 — derive single account from the M2M list (no single-get on /wp/*).
		$list = $this->list_accounts();
		if ( empty( $list['success'] ) || ! isset( $list['accounts'] ) || ! is_array( $list['accounts'] ) ) {
			return $list;
		}
		foreach ( $list['accounts'] as $acc ) {
			if ( isset( $acc['id'] ) && (string) $acc['id'] === (string) $account_id ) {
				return array( 'success' => true, 'account' => $acc );
			}
		}
		return array( 'success' => false, 'message' => 'not_found' );
	}

	/**
	 * Create a new account on the bridge.
	 *
	 * @param array $data { label:string, type:'personal'|'oa' }
	 * @return array { success:bool, id:string, _degraded?:bool }
	 */
	public function create_account( array $data ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->create_account( $data ) : $this->degraded( 'managed_client_missing' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M create; OA uses a dedicated sub-path.
		$is_oa = isset( $data['type'] ) && 'oa' === $data['type'];
		return $this->post( $is_oa ? 'wp/accounts/oa' : 'wp/accounts', $data );
	}

	/**
	 * Delete account from bridge.
	 *
	 * @param string $account_id
	 * @return array { success:bool, _degraded?:bool }
	 */
	public function delete_account( string $account_id ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->delete_account( $account_id ) : $this->degraded( 'managed_client_missing' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M DELETE /wp/accounts/{id}.
		return $this->delete( 'wp/accounts/' . rawurlencode( $account_id ) );
	}

	// ── Public: Zalo Personal (QR) ─────────────────────────────────────────

	/**
	 * Initiate QR login for a personal account.
	 * Returns base64-encoded QR image in result['qr_base64'].
	 *
	 * @param string $account_id
	 * @return array { success:bool, qr_base64:string, _degraded?:bool }
	 */
	public function start_qr( string $account_id ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->start_qr( $account_id ) : $this->degraded( 'managed_client_missing' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M /wp/accounts/{id}/qr.
		return $this->post( 'wp/accounts/' . rawurlencode( $account_id ) . '/qr', array() );
	}

	/** Reset only the runtime session and start QR again for the same account ID. */
	public function reset_qr( string $account_id ): array {
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — keep account/mapping identity while delegating controlled QR session reset by mode.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->reset_qr( $account_id ) : $this->degraded( 'managed_client_missing' );
		}
		return $this->post( 'wp/accounts/' . rawurlencode( $account_id ) . '/qr/reset', array() );
	}

	/**
	 * Poll QR login status.
	 *
	 * @param string $account_id
	 * @return array { success:bool, status:'pending_qr'|'connected'|'expired', _degraded?:bool }
	 */
	public function get_qr_status( string $account_id ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->get_qr_status( $account_id ) : $this->degraded( 'managed_client_missing' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M /wp/accounts/{id}/qr-status.
		return $this->get( 'wp/accounts/' . rawurlencode( $account_id ) . '/qr-status' );
	}

	/** Read hash-only experimental group candidates through the active server boundary. */
	public function get_group_candidates( string $account_id ): array {
		// [2026-09-03 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H3-GROUP — keep group discovery behind managed Hub or custom M2M, never from the browser.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->get_group_candidates( $account_id ) : $this->degraded( 'managed_client_missing' );
		}
		return $this->get( 'wp/accounts/' . rawurlencode( $account_id ) . '/history/groups' );
	}

	/** Read one bounded experimental group-history page through the active server boundary. */
	public function get_group_history( string $account_id, string $thread_ref, int $count = 20 ): array {
		// [2026-09-03 02:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H1-GROUP — expose group history only through managed Hub or custom M2M, never from the browser.
		$count = max( 1, min( 50, $count ) );
		$query = '?threadRef=' . rawurlencode( $thread_ref ) . '&count=' . $count;
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->get_group_history( $account_id, $thread_ref, $count ) : $this->degraded( 'managed_client_missing' );
		}
		return $this->get( 'wp/accounts/' . rawurlencode( $account_id ) . '/history/group' . $query );
	}

	// ── Public: Zalo OA (OAuth) ────────────────────────────────────────────

	/**
	 * Get the OAuth permission URL for an OA account.
	 *
	 * @param string $account_id  Bridge account ID.
	 * @param string $state       Optional state passthrough.
	 * @return array { success:bool, url:string, _degraded?:bool }
	 */
	public function get_oa_connect_url( string $account_id, string $state = '' ): array {
		if ( $this->is_managed_mode() ) {
			return $this->degraded( 'managed_operation_not_supported' );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.39 — M2M /wp/oa/connect-url.
		$path = 'wp/oa/connect-url?accountId=' . rawurlencode( $account_id );
		if ( $state !== '' ) {
			$path .= '&state=' . rawurlencode( $state );
		}
		return $this->get( $path );
	}

	// ── Public: outbound ──────────────────────────────────────────────────

	/**
	 * Enqueue an outbound message to the bridge worker.
	 *
	 * @param string $account_id  Bridge account ID.
	 * @param string $recipient   Thread/user ID on Zalo side.
	 * @param string $text        Message text.
	 * @param string $type        'text'|'image'|'file'.
	 * @param array  $attachments [{ url:string, name:string }].
	 * @return array { success:bool, job_id?:string, _degraded?:bool }
	 */
	public function enqueue_outbound( string $account_id, string $recipient, string $text, string $type = 'text', array $attachments = array(), string $thread_kind = 'user' ): array { // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — carry the canonical Zalo thread kind through outbound transport.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->enqueue_outbound( $account_id, $recipient, $text, $type, $attachments, $thread_kind ) : $this->degraded( 'managed_client_missing' );
		}
		return $this->post( 'wp/outbound', array(
			'account_id'  => $account_id,
			'recipient'   => $recipient,
			'text'        => $text,
			'type'        => $type,
			'attachments' => $attachments,
			'thread_kind' => in_array( $thread_kind, array( 'user', 'group' ), true ) ? $thread_kind : 'user',
		) );
	}

	/**
	 * Send a friend request to a Zalo user (Personal accounts only).
	 * Maps to sidecar POST /wp/friend-request.
	 *
	 * @param string $account_id  Bridge account ID (personal).
	 * @param string $user_id     Target Zalo UID.
	 * @param string $msg         Greeting message to attach to the friend request.
	 * @return array { success:bool, _degraded?:bool }
	 */
	// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — friend request via sidecar /wp/friend-request
	public function send_friend_request( string $account_id, string $user_id, string $msg = '' ): array {
		if ( $this->is_managed_mode() ) {
			return $this->degraded( 'managed_operation_not_supported' );
		}
		return $this->post( 'wp/friend-request', array(
			'account_id' => $account_id,
			'user_id'    => $user_id,
			'msg'        => $msg,
		) );
	}

	/**
	 * Invite a user to a Zalo group (Personal accounts only).
	 * Maps to sidecar POST /wp/group-invite.
	 *
	 * @param string $account_id  Bridge account ID (personal).
	 * @param string $group_id    Target Zalo group ID.
	 * @param string $user_id     Zalo UID of the user to invite.
	 * @return array { success:bool, _degraded?:bool }
	 */
	// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — group invite via sidecar /wp/group-invite
	public function invite_to_group( string $account_id, string $group_id, string $user_id ): array {
		if ( $this->is_managed_mode() ) {
			return $this->degraded( 'managed_operation_not_supported' );
		}
		return $this->post( 'wp/group-invite', array(
			'account_id' => $account_id,
			'group_id'   => $group_id,
			'user_id'    => $user_id,
		) );
	}

	// ── Private: HTTP helpers ──────────────────────────────────────────────

	/**
	 * Layered connection self-test against the sidecar /wp/health endpoint.
	 *
	 * Returns 3-layer diagnostics (config / reachable / authed / accounts) so the
	 * admin UI can pinpoint exactly where the WP ↔ bridge link breaks.
	 *
	 * @return array {
	 *   success:bool,
	 *   checks:{
	 *     config:{ ok:bool, url_set:bool, token_set:bool },
	 *     reachable:{ ok:bool, latency_ms:int, http:int, error?:array },
	 *     authed:{ ok:bool, error?:array },
	 *     accounts:{ ok:bool, total:int, connected:int, personal:int, oa:int }
	 *   }
	 * }
	 */
	public function test_connection(): array {
		if ( $this->is_managed_mode() ) {
			// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — managed health is checked through Branch 19, never with a client-held bridge secret.
			$health = $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->health() : array();
			$ok = ! empty( $health['success'] );
			return array(
				'success' => $ok,
				'mode'    => 'managed_1api',
				'checks'  => array(
					'config'    => array( 'ok' => class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->get_api_key() !== '', 'url_set' => true, 'token_set' => false ),
					'reachable' => array( 'ok' => $ok, 'latency_ms' => 0, 'http' => $ok ? 200 : 503 ),
					'authed'    => array( 'ok' => $ok ),
					'accounts'  => array( 'ok' => $ok, 'total' => 0, 'connected' => 0, 'personal' => 0, 'oa' => 0 ),
				),
			);
		}
		$url_set   = $this->url !== '';
		$token_set = $this->token !== '';
		$checks    = array(
			'config'    => array(
				'ok'        => $url_set && $token_set,
				'url_set'   => $url_set,
				'token_set' => $token_set,
			),
			'reachable' => array( 'ok' => false, 'latency_ms' => 0, 'http' => 0 ),
			'authed'    => array( 'ok' => false ),
			'accounts'  => array( 'ok' => false, 'total' => 0, 'connected' => 0, 'personal' => 0, 'oa' => 0 ),
		);

		if ( ! $url_set || ! $token_set ) {
			$checks['config']['error'] = $this->error_payload(
				'api_key_missing',
				'Chưa cấu hình URL hoặc Token của zca-bridge.',
				'Vào Cài đặt → Zalo Personal → nhập Bridge URL và Token rồi lưu.',
				'zalo_bridge_not_configured'
			);
			return array( 'success' => false, 'checks' => $checks );
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			$this->url . '/wp/health',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
			)
		);
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$checks['reachable']['latency_ms'] = $latency;
			$checks['reachable']['error']      = $this->error_payload(
				'gateway_degraded',
				'Không kết nối được tới zca-bridge: ' . $response->get_error_message(),
				'Kiểm tra sidecar đang chạy và Bridge URL đúng (vd http://127.0.0.1:4000).',
				'zalo_bridge_unreachable'
			);
			return array( 'success' => false, 'checks' => $checks );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$checks['reachable']['ok']         = $code > 0 && $code < 500;
		$checks['reachable']['latency_ms'] = $latency;
		$checks['reachable']['http']       = $code;

		if ( 401 === $code || 403 === $code ) {
			$checks['authed']['error'] = $this->error_payload(
				'token_invalid',
				'zca-bridge từ chối Token (HTTP ' . $code . ').',
				'Đảm bảo Token ở WP đúng bằng BIZCITY_INBOUND_TOKEN trong .env của sidecar.',
				'zalo_bridge_token_mismatch'
			);
			return array( 'success' => false, 'checks' => $checks );
		}

		if ( $code >= 400 || empty( $body['ok'] ) ) {
			$checks['reachable']['error'] = $this->error_payload(
				'gateway_degraded',
				'zca-bridge trả lỗi (HTTP ' . $code . ').',
				'Xem log sidecar để biết chi tiết.',
				'zalo_bridge_bad_response'
			);
			return array( 'success' => false, 'checks' => $checks );
		}

		$checks['authed']['ok']   = true;
		$accounts                 = isset( $body['accounts'] ) && is_array( $body['accounts'] ) ? $body['accounts'] : array();
		$checks['accounts']['ok'] = true;
		$checks['accounts']['total']     = (int) ( $accounts['total'] ?? 0 );
		$checks['accounts']['connected'] = (int) ( $accounts['connected'] ?? 0 );
		$checks['accounts']['personal']  = (int) ( $accounts['personal'] ?? 0 );
		$checks['accounts']['oa']        = (int) ( $accounts['oa'] ?? 0 );

		// Refresh the fast-path health cache while we are here.
		set_transient( self::HEALTH_CACHE, '1', self::HEALTH_TTL );

		return array(
			'success' => true,
			'version' => isset( $body['version'] ) ? (string) $body['version'] : '',
			'checks'  => $checks,
		);
	}

	/**
	 * Build a 4-field error payload (R-ERROR-UX). Falls back to a plain array
	 * if the shared helper class is unavailable on the client.
	 *
	 * @param string $code
	 * @param string $message
	 * @param string $hint
	 * @param string $help_code
	 * @return array
	 */
	private function error_payload( string $code, string $message, string $hint, string $help_code ): array {
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			return BizCity_Error_Payload::make( $code, $message, $hint, $help_code );
		}
		return array(
			'code'      => $code,
			'message'   => $message,
			'hint'      => $hint,
			'help_code' => $help_code,
		);
	}

	/**
	 * GET {url}/{path}. Returns decoded JSON or degraded error.
	 *
	 * @param string $path
	 * @return array
	 */
	private function get( string $path ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->get_managed_path( $path ) : $this->degraded( 'managed_client_missing' );
		}
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'bridge_not_configured' );
		}
		$response = wp_remote_get(
			$this->url . '/' . ltrim( $path, '/' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
			)
		);
		return $this->parse_response( $response );
	}

	/**
	 * POST {url}/{path} with JSON body. Returns decoded JSON or degraded error.
	 *
	 * @param string $path
	 * @param array  $body
	 * @return array
	 */
	private function post( string $path, array $body ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( $path, $body ) : $this->degraded( 'managed_client_missing' );
		}
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'bridge_not_configured' );
		}
		$response = wp_remote_post(
			$this->url . '/' . ltrim( $path, '/' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body' => wp_json_encode( $body ),
			)
		);
		return $this->parse_response( $response );
	}

	/**
	 * DELETE {url}/{path}. Returns decoded JSON or degraded error.
	 *
	 * @param string $path
	 * @return array
	 */
	private function delete( string $path ): array {
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->delete_managed_path( $path ) : $this->degraded( 'managed_client_missing' );
		}
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'bridge_not_configured' );
		}
		$response = wp_remote_request(
			$this->url . '/' . ltrim( $path, '/' ),
			array(
				'method'  => 'DELETE',
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
			)
		);
		return $this->parse_response( $response );
	}

	/**
	 * Parse wp_remote_* response. On error → degraded shape.
	 *
	 * @param array|WP_Error $response
	 * @return array
	 */
	private function parse_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return $this->degraded( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( (int) $code >= 400 ) {
			$data['_degraded'] = true;
			$data['success']   = false;
			if ( ! isset( $data['message'] ) ) {
				$data['message'] = 'HTTP ' . $code;
			}
			return $data;
		}
		if ( ! isset( $data['success'] ) ) {
			$data['success'] = true;
		}
		return $data;
	}

	/**
	 * Standard degraded response (R-ZP-3: fail-OPEN).
	 *
	 * @param string $reason
	 * @return array
	 */
	private function degraded( string $reason = '' ): array {
		return array(
			'success'   => false,
			'_degraded' => true,
			'message'   => $reason !== '' ? $reason : 'zca-bridge không phản hồi.',
		);
	}

	/** Return the inbound callback credential for the active mode. */
	public function expected_inbound_token( string $account_id = '' ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — managed callbacks use per-account encrypted capability; custom uses explicit local token.
		if ( $this->is_managed_mode() ) {
			return $this->managed_hub_available() ? BizCity_Zalo_Personal_Hub_Client::instance()->get_callback_token( $account_id ) : '';
		}
		return $this->token;
	}
}
