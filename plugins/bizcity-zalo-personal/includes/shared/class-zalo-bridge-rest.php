<?php
/**
 * BizCity Zalo Bridge REST Controller (PHASE-0.39)
 *
 * Namespace: bizcity-channel/v1  (R-CH-NS — KHÔNG dùng bizcity/v1)
 *
 * Routes:
 *   POST  /bizcity-channel/v1/zalo-bridge/inbound         ← from sidecar (Bearer)
 *   GET   /bizcity-channel/v1/zalo-bridge/accounts         → proxy sidecar
 *   POST  /bizcity-channel/v1/zalo-bridge/accounts         → proxy sidecar create
 *   DELETE /bizcity-channel/v1/zalo-bridge/accounts/(?P<id>[^/]+) → proxy sidecar delete
 *   POST  /bizcity-channel/v1/zalo-bridge/accounts/(?P<id>[^/]+)/qr        → proxy sidecar QR
 *   GET   /bizcity-channel/v1/zalo-bridge/accounts/(?P<id>[^/]+)/qr-status → proxy sidecar poll
 *   GET   /bizcity-channel/v1/zalo-bridge/oa/connect-url  → get OAuth URL from sidecar
 *   GET   /bizcity-channel/v1/zalo-bridge/health          → proxy sidecar health
 *   GET   /bizcity-channel/v1/zalo-bridge/settings        → read/write bridge URL+token option
 *   POST  /bizcity-channel/v1/zalo-bridge/settings        → save bridge URL+token
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — REST proxy controller (R-CH-NS bizcity-channel/v1)
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Bridge_REST {

	const NS     = 'bizcity-channel/v1';
	const PREFIX = 'zalo-bridge';

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$base = self::NS . '/' . self::PREFIX;

		// Inbound from sidecar — no WP auth, verified by Bearer token.
		register_rest_route( self::NS, '/' . self::PREFIX . '/inbound', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_inbound' ),
			'permission_callback' => '__return_true', // Bearer verified in handler.
		) );

		// [2026-08-23 Johnny Chu] PHASE-0.39E — independent monitor alert ingress; no CRM/bridge write.
		register_rest_route( self::NS, '/' . self::PREFIX . '/health-alert', array(
			'methods'              => 'POST',
			'callback'            => array( __CLASS__, 'handle_health_alert' ),
			'permission_callback' => '__return_true',
		) );

		// Bridge health (admin only).
		register_rest_route( self::NS, '/' . self::PREFIX . '/health', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_health' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-08-23 Johnny Chu] PHASE-0.39E — read-only redacted sidecar diagnostics.
		register_rest_route( self::NS, '/' . self::PREFIX . '/diagnostics', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_diagnostics' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-06-07 Johnny Chu] PHASE-0.39 — connection self-test (layered diagnostics).
		register_rest_route( self::NS, '/' . self::PREFIX . '/test', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_test' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-06-07 Johnny Chu] PHASE-0.39 — read/clear hook log (read-hook tooling).
		register_rest_route( self::NS, '/' . self::PREFIX . '/hook-log', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_hook_log' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_clear_hook_log' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		// Settings (bridge URL + token).
		register_rest_route( self::NS, '/' . self::PREFIX . '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		// Accounts list + create.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_list_accounts' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_create_account' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		// Account delete.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'handle_delete_account' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// Personal QR initiate.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/qr', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_start_qr' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — expose explicit session reset; this route never deletes the account or CRM mapping.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/qr/reset', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_reset_qr' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// Personal QR poll status.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/qr-status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_qr_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-09-03 02:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H1-GROUP — expose bounded group history only to tenant operators; no CRM import is performed here.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/history/group', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_group_history' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'args'                => array(
				'thread_ref' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'cursor'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'count'    => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );

		// [2026-09-03 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H3-GROUP — expose hash-only group discovery through the server-side bridge client only.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/history/groups', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_group_history_candidates' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// OA OAuth connect URL.
		register_rest_route( self::NS, '/' . self::PREFIX . '/oa/connect-url', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_oa_connect_url' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	// ── Permission ────────────────────────────────────────────────────────

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Inbound handler ───────────────────────────────────────────────────

	/**
	 * Receive inbound event from zca-bridge sidecar.
	 * Body: { account_id, account_name, kind, from_user_id, from_user_name,
	 *         conversation_id, message_id, message_text, message_type,
	 *         message_time, image_url?, file_url?, file_name?, raw? }
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_inbound( WP_REST_Request $request ) {
		// [2026-06-07 Johnny Chu] PHASE-0.39 — verify Bearer + emit bizcity_zalo_message_received.
		// [2026-08-22 Johnny Chu] R-CH-FILE-LOG — write a redacted attempt before option, mapping, or CRM reads.
		$probe_body = $request->get_json_params();
		$probe_body = is_array( $probe_body ) ? $probe_body : array();
		$probe_channel = 'zalo_personal';
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			$probe_channel = (string) ( $probe_body['kind'] ?? 'personal' ) === 'oa'
				? BizCity_Channel_File_Logger::CH_ZALO_OA
				: BizCity_Channel_File_Logger::CH_ZALO_PERSONAL;
			BizCity_Channel_File_Logger::write(
				$probe_channel,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'inbound_attempt',
				'Zalo Personal inbound received.',
				array(
					'kind'              => sanitize_key( (string) ( $probe_body['kind'] ?? 'personal' ) ),
					'account_id_hash'   => substr( hash( 'sha256', (string) ( $probe_body['account_id'] ?? '' ) ), 0, 16 ),
					'provider_id_hash'  => substr( hash( 'sha256', (string) ( $probe_body['message_id'] ?? '' ) ), 0, 16 ),
					'trace_id'          => sanitize_text_field( (string) ( $probe_body['trace_id'] ?? '' ) ),
				)
			);
		}

		// Verify the active mode's callback credential (managed is per account; custom is explicit per-blog).
		$account_id = (string) ( $probe_body['account_id'] ?? '' );
		$stored_token = BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account_id );
		if ( $stored_token === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'callback_credential_missing', 'message' => 'Chưa cấu hình quyền nhận tin Zalo.', 'hint' => 'Kiểm tra mode Bridge và kết nối lại tài khoản.', 'help_code' => 'zalo_bridge_not_configured' ), 401 );
		}
		$header = (string) $request->get_header( 'authorization' );
		$bearer = '';
		if ( strpos( $header, 'Bearer ' ) === 0 ) {
			$bearer = substr( $header, 7 );
		}
		if ( ! hash_equals( $stored_token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
		}

		$body = $request->get_json_params();
		// [2026-08-24 Johnny Chu] PHASE-0.39E-D1 — retain the opaque sidecar trace for CRM/archive correlation only.
		$trace_id = sanitize_text_field( (string) ( is_array( $body ) ? ( $body['trace_id'] ?? $request->get_header( 'x-correlation-id' ) ) : $request->get_header( 'x-correlation-id' ) ) );
		$trace_id = substr( $trace_id, 0, 128 );
		if ( is_array( $body ) && $trace_id !== '' ) {
			$body['trace_id'] = $trace_id;
		}
		if ( ! is_array( $body ) || empty( $body['from_user_id'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
		}
		// [2026-08-23 Johnny Chu] PHASE-0.39D — Personal plugin refuses OA payloads; OA is owned by a separate plugin.
		if ( 'personal' !== sanitize_key( (string) ( $body['kind'] ?? 'personal' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unsupported_channel', 'message' => 'Endpoint này chỉ nhận Zalo Cá nhân.', 'hint' => 'Dùng endpoint của plugin Zalo OA cho tài khoản Official Account.', 'help_code' => 'invalid_param_generic' ), 400 );
		}

		// Delegate to emitter (maps payload → bizcity_zalo_message_received shape + fires action).
		$emitter = BizCity_Zalo_Inbound_Emitter::instance();
		$msg_id  = $emitter->emit( $body );
		// [2026-08-21 Johnny Chu] PHASE-0.39B — only a zero result is retryable; -1 means intentional self/unbound drop.
		if ( 0 === $msg_id ) {
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( $probe_channel, BizCity_Channel_File_Logger::LEVEL_ERROR, 'inbound_failed', 'Zalo channel CRM ingest failed.', array( 'reason' => 'crm_ingest_failed' ) );
			}
			return new WP_REST_Response( array(
				'ok'        => false,
				'code'      => 'crm_ingest_failed',
				'message'   => 'CRM chưa nhận được tin nhắn Zalo.',
				'hint'      => 'Kiểm tra mapping account và CRM Inbox rồi thử lại.',
				'help_code' => 'crm_ingest_failed',
			), 503 );
		}

		// [2026-06-07 Johnny Chu] PHASE-0.39 — capture for the read-hook Logs tab.
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			BizCity_Zalo_Hook_Log::record_inbound( $body, (int) $msg_id );
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( $probe_channel, BizCity_Channel_File_Logger::LEVEL_INFO, 'inbound_accepted', 'Zalo channel inbound processed.', array( 'crm_message_id' => $msg_id > 0 ? (int) $msg_id : 0, 'ignored' => $msg_id < 0 ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'accepted' => $msg_id > 0, 'ignored' => $msg_id < 0, 'crm_message_id' => $msg_id > 0 ? $msg_id : 0 ) );
	}

	// ── Connection test ───────────────────────────────────────────────────

	/**
	 * Layered connection self-test against the sidecar /wp/health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_test(): WP_REST_Response {
		// [2026-06-07 Johnny Chu] PHASE-0.39 — fail-OPEN: always HTTP 200, success flag inside.
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->test_connection();
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Receive a signed state transition from a VPS/Hub monitor and email operators.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_health_alert( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-23 Johnny Chu] PHASE-0.39E — verify the existing inbound M2M credential before notification.
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$instance = sanitize_text_field( (string) ( $body['bridge_instance_id'] ?? 'default' ) );
		$account_id = (string) ( $body['account_id'] ?? '' );
		$token = BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account_id );
		$header = (string) $request->get_header( 'authorization' );
		$given = strpos( $header, 'Bearer ' ) === 0 ? substr( $header, 7 ) : '';
		if ( $token === '' || $given === '' || ! hash_equals( $token, $given ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'unauthorized', 'message' => 'Monitor chưa được xác thực.', 'hint' => 'Dùng đúng M2M token của bridge account.', 'help_code' => 'zalo_bridge_token_mismatch' ), 401 );
		}
		$state = sanitize_key( (string) ( $body['state'] ?? '' ) );
		$allowed = array( 'offline', 'worker_stalled', 'auth_failed', 'session_disconnected', 'mapping_failed', 'recovered' );
		if ( ! in_array( $state, $allowed, true ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'invalid_param', 'message' => 'Trạng thái bridge không hợp lệ.', 'hint' => 'Gửi một state đã có trong contract diagnostics.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Notify_Dispatcher' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'module_not_loaded', 'message' => 'Notification Center chưa sẵn sàng.', 'hint' => 'Nạp lại Channel Gateway rồi gửi lại alert.', 'help_code' => 'module_not_loaded' ), 503 );
		}
		BizCity_Notify_Dispatcher::on_bridge_state_changed( array(
			'state'              => $state,
			'bridge_instance_id' => $instance !== '' ? $instance : 'default',
			'account_id_hash'    => substr( hash( 'sha256', $account_id ), 0, 12 ),
			'reason'             => sanitize_key( (string) ( $body['reason'] ?? $state ) ),
			'trace_id'           => sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ),
			'dedupe_key'         => sanitize_key( (string) ( $body['dedupe_key'] ?? '' ) ),
		) );
		return new WP_REST_Response( array( 'success' => true, 'accepted' => true, 'state' => $state ), 200 );
	}

	/**
	 * Read redacted bridge diagnostics through the active server-side client.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_diagnostics( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-23 Johnny Chu] PHASE-0.39E — keep diagnostics read-only and inside the active credential boundary.
		$args = array();
		foreach ( array( 'account_id', 'before_id', 'since', 'level', 'phase', 'trace_id', 'limit' ) as $key ) {
			$value = $request->get_param( $key );
			if ( $value !== null && $value !== '' && is_scalar( $value ) ) {
				$args[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		$args['limit'] = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$result = BizCity_Zalo_Bridge_Client::instance()->diagnostics( $args );
		if ( ! is_array( $result ) ) {
			$result = array();
		}
		if ( empty( $result['success'] ) && empty( $result['ok'] ) && empty( $result['error'] ) ) {
			$result = array_merge( array(
				'success'  => false,
				'_degraded' => true,
				'error'    => array(
					'code'      => 'bridge_offline',
					'message'   => 'Không đọc được log zca-bridge.',
					'hint'      => 'Kiểm tra trạng thái bridge và mở lại trong giây lát.',
					'help_code' => 'zalo_bridge_offline',
				),
				'events' => array(),
			), $result );
		}
		return new WP_REST_Response( $result, 200 );
	}

	// ── Hook log ──────────────────────────────────────────────────────────

	public static function handle_get_hook_log( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		$rows = class_exists( 'BizCity_Zalo_Hook_Log' ) ? BizCity_Zalo_Hook_Log::read( $limit ) : array();
		return new WP_REST_Response( array( 'ok' => true, 'logs' => $rows ) );
	}

	public static function handle_clear_hook_log(): WP_REST_Response {
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			BizCity_Zalo_Hook_Log::clear();
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	// ── Health ────────────────────────────────────────────────────────────

	public static function handle_health(): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-GW-8 — managed mode health must probe Hub /health, not account listing or custom URL/token options.
		$client = BizCity_Zalo_Bridge_Client::instance();
		$mode = $client->get_mode();
		// [2026-08-22 Johnny Chu] HOTFIX-ZALO-HEALTH — mixed-version deployments must degrade instead of calling a missing client method.
		$result = method_exists( $client, 'health' )
			? $client->health()
			: array( 'success' => false, '_degraded' => true, 'code' => 'bridge_health_method_missing' );
		$success = ! empty( $result['success'] ) || ! empty( $result['ok'] );
		$degraded = ! empty( $result['_degraded'] ) || ! empty( $result['degraded'] );
		$ok = $success && ! $degraded;
		return new WP_REST_Response( array(
			'ok'          => $ok,
			'degraded'    => ! $ok,
			'mode'        => $mode,
			'code'        => (string) ( $result['code'] ?? '' ),
			'key_id'      => isset( $result['key_id'] ) ? (int) $result['key_id'] : 0,
			'domain_set'  => isset( $result['domain_set'] ) ? (bool) $result['domain_set'] : null,
			'capability'  => isset( $result['capability'] ) && is_array( $result['capability'] ) ? $result['capability'] : null,
			'config_state'=> isset( $result['config_state'] ) && is_array( $result['config_state'] ) ? $result['config_state'] : null,
			'bridge_url'  => 'custom_bridge' === $mode && get_option( BizCity_Zalo_Bridge_Client::OPTION_URL, '' ) !== '',
		) );
	}

	// ── Settings ─────────────────────────────────────────────────────────

	public static function handle_get_settings(): WP_REST_Response {
		$mode = BizCity_Zalo_Bridge_Client::instance()->get_mode();
		$capability = 'managed_1api' === $mode && class_exists( 'BizCity_Zalo_Personal_Hub_Client' )
			? BizCity_Zalo_Personal_Hub_Client::instance()->capability()
			: null;
		return new WP_REST_Response( array(
			'ok'          => true,
			'mode'        => $mode,
			'managed_capability' => $capability,
			'bridge_url'  => (string) get_option( BizCity_Zalo_Bridge_Client::OPTION_URL, '' ),
			// Token: return masked value for security (never expose real token).
			'bridge_token_set' => 'custom_bridge' === $mode && get_option( BizCity_Zalo_Bridge_Client::OPTION_TOKEN, '' ) !== '',
		) );
	}

	public static function handle_save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body  = $request->get_json_params();
		$mode  = isset( $body['mode'] ) && 'custom_bridge' === sanitize_key( (string) $body['mode'] ) ? 'custom_bridge' : 'managed_1api';
		$url   = isset( $body['bridge_url'] ) ? esc_url_raw( sanitize_text_field( $body['bridge_url'] ) ) : null;
		$token = isset( $body['bridge_token'] ) ? sanitize_text_field( $body['bridge_token'] ) : null;

		if ( 'custom_bridge' === $mode && $url !== null ) {
			// [2026-08-22 Johnny Chu] R-GW-8 — reject loopback/private custom bridge targets before storing a server-side egress destination.
			if ( ! self::is_safe_custom_bridge_url( $url ) ) {
				return new WP_REST_Response( array(
					'ok'        => false,
					'code'      => 'invalid_param',
					'message'   => 'Bridge URL custom không hợp lệ.',
					'hint'      => 'Dùng URL HTTPS public của bridge hoặc allowlist rõ trong môi trường phát triển.',
					'help_code' => 'invalid_param_generic',
				), 200 );
			}
			update_option( BizCity_Zalo_Bridge_Client::OPTION_URL, $url, false );
		}
		if ( 'custom_bridge' === $mode && $token !== null && $token !== '' ) {
			update_option( BizCity_Zalo_Bridge_Client::OPTION_TOKEN, $token, false );
		}
		update_option( BizCity_Zalo_Bridge_Client::OPTION_MODE, $mode, false );
		// Reset the singleton so it picks up new options.
		// Re-create instance via reflection is impractical in PHP 7.4 without exposing constructor.
		// Simpler: store in cache cleared transient.
		delete_transient( BizCity_Zalo_Bridge_Client::HEALTH_CACHE );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	private static function is_safe_custom_bridge_url( string $url ): bool {
		// [2026-08-22 Johnny Chu] R-GW-8 — constrain custom bridge egress to HTTPS/public hosts.
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( $scheme !== 'https' || $host === '' || in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false === strpos( $host, '.' ) ? false : false !== wp_http_validate_url( $url );
	}

	// ── Accounts ─────────────────────────────────────────────────────────

	public static function handle_list_accounts(): WP_REST_Response {
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->list_accounts();
		if ( ! empty( $result['_degraded'] ) ) {
			// [2026-08-22 Johnny Chu] R-ERROR-UX — preserve the managed Hub reason instead of returning a blank account-list error.
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => (string) ( $result['code'] ?? $result['error'] ?? 'bridge_unavailable' ),
				'message'   => (string) ( $result['message'] ?? 'Không tải được danh sách tài khoản Zalo.' ),
				'hint'      => (string) ( $result['hint'] ?? 'Kiểm tra trạng thái Managed 1API rồi thử lại.' ),
				'help_code' => (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ),
			) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'accounts' => $result['accounts'] ?? array() ) );
	}

	public static function handle_create_account( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — retain the admin REST callback while routing identity-aware callers through the owner boundary.
		return self::create_account_for_owner( $request, (int) get_current_user_id() );
	}

	/** Create and bind an account for an already-resolved tenant owner. */
	public static function create_account_for_owner( WP_REST_Request $request, int $owner_user_id, bool $personal_only = false ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1/R-TWEB-14 — never resolve the /gpt/ owner from ambient request state inside the service.
		$body   = $request->get_json_params();
		$label  = sanitize_text_field( $body['label'] ?? '' );
		$kind   = in_array( $body['kind'] ?? 'personal', array( 'personal', 'oa' ), true ) ? $body['kind'] : 'personal';
		// [2026-08-22 Johnny Chu] R-ZONE/R-TWEB-14 — the Personal route cannot be repurposed to provision a Zalo OA account.
		if ( $personal_only ) {
			$kind = 'personal';
		}
		self::trace_create_step( 'create_start', array( 'owner_user_id' => $owner_user_id, 'kind' => $kind ) );
		if ( $owner_user_id <= 0 ) {
			self::trace_create_step( 'auth_failed', array( 'reason' => 'owner_missing' ) );
			return new WP_REST_Response( array(
				'ok'        => false,
				'code'      => 'auth_required',
				'message'   => 'Bạn cần đăng nhập để tạo tài khoản Zalo.',
				'hint'      => 'Đăng nhập WordPress rồi thử lại.',
				'help_code' => 'auth_required',
			), 401 );
		}
		$client = BizCity_Zalo_Bridge_Client::instance();
		self::trace_create_step( 'hub_request', array( 'kind' => $kind ) );
		$result = $client->create_account( array( 'label' => $label, 'type' => $kind ) );
		self::trace_create_step( 'hub_response', array(
			'ok'   => ! empty( $result['success'] ) || ! empty( $result['ok'] ),
			'code' => sanitize_key( (string) ( $result['code'] ?? $result['error'] ?? '' ) ),
		) );
		if ( ! empty( $result['_degraded'] ) ) {
			// [2026-08-22 Johnny Chu] R-ERROR-UX — expose code/hint/help_code from the exact-key create boundary for support diagnosis.
			$response = array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => (string) ( $result['code'] ?? $result['error'] ?? 'bridge_unavailable' ),
				'message'   => (string) ( $result['message'] ?? 'Không tạo được tài khoản Zalo.' ),
				'hint'      => (string) ( $result['hint'] ?? 'Kiểm tra trạng thái Managed 1API rồi thử lại.' ),
				'help_code' => (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ),
			);
			// [2026-08-22 Johnny Chu] R-DDV-TRACE — forward safe key/domain correlation fields from the Hub domain gate.
			foreach ( array( 'key_id', 'domain_set', 'site_host_hash', 'callback_host_hash', 'domain_hash' ) as $field ) {
				if ( array_key_exists( $field, $result ) ) {
					$response[ $field ] = $result[ $field ];
				}
			}
			return new WP_REST_Response( $response );
		}
		$bridge_account = isset( $result['account'] ) && is_array( $result['account'] ) ? $result['account'] : $result;
		$bridge_id      = (string) ( $bridge_account['id'] ?? '' );
		self::trace_create_step( 'bridge_account_received', array( 'bridge_id_hash' => $bridge_id !== '' ? substr( hash( 'sha256', $bridge_id ), 0, 12 ) : '' ) );
		if ( $bridge_id === '' || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			self::trace_create_step( 'bridge_account_invalid', array( 'has_bridge_id' => $bridge_id !== '', 'crm_loaded' => class_exists( 'BizCity_CRM_Repository' ) ) );
			if ( $bridge_id !== '' ) {
				$client->delete_account( $bridge_id );
			}
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Chưa khởi tạo được CRM cho tài khoản Zalo.',
				'hint'      => 'Tải lại trang hoặc liên hệ quản trị viên.',
				'help_code' => 'module_not_loaded',
			), 200 );
		}

		// [2026-08-21 Johnny Chu] R-DCL — repair the mapping schema in this REST maintenance context before writing owner binding.
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			self::trace_create_step( 'mapping_schema_check' );
			BizCity_Zalo_Mapping_Repo::maybe_install();
		}

		self::trace_create_step( 'mapping_save_start', array( 'bridge_id_hash' => substr( hash( 'sha256', $bridge_id ), 0, 12 ) ) );
		$local_id = BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => $kind,
			'owner_user_id'     => $owner_user_id,
			'label'             => $label,
			'bridge_account_id' => $bridge_id,
			'zalo_uid'          => (string) ( $bridge_account['zaloUid'] ?? $bridge_account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => (string) ( $bridge_account['zaloOaId'] ?? $bridge_account['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => 0,
			'status'            => 'pending_qr',
		) );
		self::trace_create_step( 'mapping_save_result', array( 'local_id' => (int) $local_id, 'success' => $local_id > 0 ) );
		if ( $local_id <= 0 ) {
			$client->delete_account( $bridge_id );
			global $wpdb;
			$db_reason = 'mapping_insert_failed';
			if ( ! empty( $wpdb->last_error ) ) {
				$db_error = strtolower( (string) $wpdb->last_error );
				// [2026-08-21 Johnny Chu] R-DCL — classify all known legacy mapping-column drift as schema repair.
				if ( strpos( $db_error, 'owner_user_id' ) !== false || strpos( $db_error, 'account_name' ) !== false || strpos( $db_error, 'user_id' ) !== false || strpos( $db_error, 'doesn\'t exist' ) !== false || strpos( $db_error, 'unknown column' ) !== false ) {
					$db_reason = 'mapping_schema_not_ready';
				}
			}
			self::trace_create_step( 'mapping_save_failed', array( 'reason' => $db_reason, 'db_error_present' => ! empty( $wpdb->last_error ) ) );
			if ( 'mapping_schema_not_ready' === $db_reason ) {
				return new WP_REST_Response( array(
					'ok'        => false,
					'_degraded' => true,
					'code'      => 'mapping_schema_not_ready',
					'message'   => 'Bảng liên kết Zalo chưa được cập nhật.',
					'hint'      => 'Tải lại trang admin để chạy repair schema rồi thử tạo lại tài khoản.',
					'help_code' => 'zalo_bridge_bad_response',
				), 200 );
			}
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => 'mapping_insert_failed',
				'message'   => 'Không lưu được liên kết chủ tài khoản Zalo.',
				'hint'      => 'Kiểm tra log WordPress và thử lại sau.',
				'help_code' => 'zalo_bridge_bad_response',
			), 200 );
		}

		$crm_channel_type = 'oa' === $kind ? 'zalo_oa' : 'zalo_personal';
		$inbox_label = 'oa' === $kind ? 'Zalo OA — ' : 'Zalo Cá nhân — ';
		self::trace_create_step( 'inbox_upsert_start', array( 'channel_type' => $crm_channel_type, 'bridge_id_hash' => substr( hash( 'sha256', $bridge_id ), 0, 12 ) ) );
		$inbox_id = BizCity_CRM_Repository::upsert_inbox( $crm_channel_type, $bridge_id, array(
			'name' => $label !== '' ? $inbox_label . $label : $inbox_label . $bridge_id,
		) );
		self::trace_create_step( 'inbox_upsert_result', array( 'inbox_id' => (int) $inbox_id, 'success' => $inbox_id > 0 ) );
		if ( $inbox_id <= 0 ) {
			self::trace_create_step( 'inbox_upsert_failed' );
			BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'orphaned' );
			$client->delete_account( $bridge_id );
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'message' => 'Không tạo được CRM Inbox cho tài khoản Zalo.' ), 200 );
		}
		BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'pending_qr', array( 'crm_inbox_id' => $inbox_id ) );
		self::trace_create_step( 'create_complete', array( 'local_id' => (int) $local_id, 'inbox_id' => (int) $inbox_id ) );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $bridge_id, 'crm_inbox_id' => $inbox_id, 'owner_user_id' => $owner_user_id ), 200 );
	}

	/** Write redacted account-provisioning evidence before and after each persistence boundary. */
	private static function trace_create_step( string $step, array $context = array() ): void {
		// [2026-08-22 Johnny Chu] R-CH-FILE-LOG — trace create flow without labels, tokens, URLs, message content, or raw account IDs.
		$context['blog_id'] = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$context['step'] = sanitize_key( $step );
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_PERSONAL, BizCity_Channel_File_Logger::LEVEL_INFO, 'account_create_' . sanitize_key( $step ), 'Zalo Personal account create trace.', $context );
		}
		error_log( '[BIZCITY_ZCA_TRACE] ' . wp_json_encode( $context ) );
	}

	public static function handle_delete_account( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'id' );
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->delete_account( $id );
		$ok = empty( $result['_degraded'] ) && ! empty( $result['success'] );
		if ( $ok && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			if ( ! $local ) {
				$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'oa', $id );
			}
			if ( $local ) {
				// [2026-08-21 Johnny Chu] PHASE-0.39B — retain CRM history while marking the bridge account logged out.
				BizCity_Zalo_Mapping_Repo::update_account_status( (int) $local['id'], 'logged_out' );
			}
		}
		return new WP_REST_Response( array( 'ok' => $ok, 'success' => $ok ) );
	}

	/** Delete a Personal account after Twin GPT has resolved its tenant owner. */
	public static function delete_account_for_owner( array $account, int $owner_user_id ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1/R-TWEB-14 — retain owner scope through bridge deletion and local status persistence.
		if ( $owner_user_id <= 0 || (string) ( $account['kind'] ?? '' ) !== 'personal' || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		if ( $id === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy tài khoản Zalo.', 'hint' => 'Tải lại danh sách Kênh của tôi rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' ), 200 );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->delete_account( $id );
		$ok = empty( $result['_degraded'] ) && ! empty( $result['success'] );
		if ( $ok && class_exists( 'BizCity_Zalo_Mapping_Repo' ) && ! empty( $account['id'] ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $account['id'], 'logged_out' );
		}
		return new WP_REST_Response( array( 'ok' => $ok, 'success' => $ok, 'code' => $ok ? '' : (string) ( $result['code'] ?? 'zalo_bridge_unreachable' ), 'message' => $ok ? '' : (string) ( $result['message'] ?? 'Chưa ngắt được tài khoản Zalo.' ), 'hint' => $ok ? '' : (string) ( $result['hint'] ?? 'Kiểm tra trạng thái managed bridge rồi thử lại.' ), 'help_code' => $ok ? '' : (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ) ), 200 );
	}

	// ── QR ───────────────────────────────────────────────────────────────

	public static function handle_start_qr( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		return self::start_qr_response( $id );
	}

	/** Reset the sidecar session and start QR again without deleting the account. */
	public static function handle_reset_qr( WP_REST_Request $request ): WP_REST_Response {
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — keep the account ID, mapping and CRM history while resetting only the bridge session.
		$id = (string) $request->get_param( 'id' );
		return self::reset_qr_response( $id );
	}

	/** Start QR for an account already resolved inside the current tenant owner scope. */
	public static function start_qr_for_owner( array $account, int $owner_user_id ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1 — QR control receives the resolved owner row, not an ambient admin permission check.
		if ( $owner_user_id <= 0 || (string) ( $account['kind'] ?? '' ) !== 'personal' || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		return self::start_qr_response( $id );
	}

	/** Start QR through one normalized operation/result boundary. */
	private static function start_qr_response( string $account_id ): WP_REST_Response {
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — preserve QR operation correlation and normalize every managed/custom result before returning to the UI.
		$operation_id = 'qr_' . str_replace( '-', '', wp_generate_uuid4() );
		$request_id   = 'wp_' . str_replace( '-', '', wp_generate_uuid4() );
		$account_hash = $account_id !== '' ? substr( hash( 'sha256', $account_id ), 0, 16 ) : '';
		self::trace_qr_step( 'qr_operation_attempt', array( 'operation_id' => $operation_id, 'request_id' => $request_id, 'account_id_hash' => $account_hash, 'stage' => 'qr_generation' ) );
		try {
			$result = $account_id !== ''
				? BizCity_Zalo_Bridge_Client::instance()->start_qr( $account_id )
				: array( 'success' => false, 'code' => 'account_not_found' );
		} catch ( \Throwable $e ) {
			$result = array( 'success' => false, 'code' => 'qr_session_start_failed', 'exception_class' => get_class( $e ) );
		}
		$normalized = self::normalize_qr_result( $result, $operation_id, $request_id );
		self::trace_qr_step( ! empty( $normalized['ok'] ) ? 'qr_operation_success' : 'qr_operation_failed', array(
			'operation_id' => $operation_id,
			'request_id' => $request_id,
			'account_id_hash' => $account_hash,
			'stage' => $normalized['stage'],
			'reason' => $normalized['reason_bucket'],
		) );
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — do not open an outage incident for an account that already has an active session.
		if ( class_exists( 'BizCity_Notify_Dispatcher' ) && (string) ( $normalized['operation_status'] ?? '' ) !== 'blocked' ) {
			BizCity_Notify_Dispatcher::on_qr_operation_result( array(
				'state' => ! empty( $normalized['ok'] ) ? 'qr_operation_success' : 'qr_operation_failed',
				'account_id_hash' => $account_hash,
				'operation_id' => $operation_id,
				'request_id' => $request_id,
				'stage' => $normalized['stage'],
				'reason' => $normalized['reason_bucket'],
				'status_code' => 200,
			) );
		}
		return new WP_REST_Response( $normalized, 200 );
	}

	/** Execute the explicit reset operation through the same normalized QR boundary. */
	private static function reset_qr_response( string $account_id ): WP_REST_Response {
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — reset session runtime first, then reuse the canonical QR response normalizer.
		$operation_id = 'qr_reset_' . str_replace( '-', '', wp_generate_uuid4() );
		$request_id   = 'wp_' . str_replace( '-', '', wp_generate_uuid4() );
		$account_hash = $account_id !== '' ? substr( hash( 'sha256', $account_id ), 0, 16 ) : '';
		self::trace_qr_step( 'qr_reset_attempt', array( 'operation_id' => $operation_id, 'request_id' => $request_id, 'account_id_hash' => $account_hash, 'stage' => 'session' ) );
		try {
			$result = $account_id !== ''
				? BizCity_Zalo_Bridge_Client::instance()->reset_qr( $account_id )
				: array( 'success' => false, 'code' => 'account_not_found' );
		} catch ( \Throwable $e ) {
			$result = array( 'success' => false, 'code' => 'qr_session_start_failed' );
		}
		$normalized = self::normalize_qr_result( $result, $operation_id, $request_id );
		if ( ! empty( $normalized['ok'] ) ) {
			$normalized['reset'] = true;
		}
		self::trace_qr_step( ! empty( $normalized['ok'] ) ? 'qr_reset_success' : 'qr_reset_failed', array(
			'operation_id' => $operation_id,
			'request_id' => $request_id,
			'account_id_hash' => $account_hash,
			'stage' => $normalized['stage'],
			'reason' => $normalized['reason_bucket'],
		) );
		if ( class_exists( 'BizCity_Notify_Dispatcher' ) && (string) ( $normalized['operation_status'] ?? '' ) !== 'blocked' ) {
			BizCity_Notify_Dispatcher::on_qr_operation_result( array(
				'state' => ! empty( $normalized['ok'] ) ? 'qr_operation_success' : 'qr_operation_failed',
				'account_id_hash' => $account_hash,
				'operation_id' => $operation_id,
				'request_id' => $request_id,
				'stage' => $normalized['stage'],
				'reason' => $normalized['reason_bucket'],
				'status_code' => 200,
			) );
		}
		return new WP_REST_Response( $normalized, 200 );
	}

	/** Normalize a QR result without exposing the upstream response body. */
	public static function normalize_qr_result( $result, string $operation_id, string $request_id ): array {
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — convert empty/ambiguous QR responses into an explicit R-ERROR-UX operation envelope.
		$result = is_array( $result ) ? $result : array();
		$qr_base64 = '';
		foreach ( array( 'qr_base64', 'qrImageBase64', 'qr_image_base64' ) as $key ) {
			if ( ! empty( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				$qr_base64 = trim( $result[ $key ] );
				break;
			}
		}
		$transport_ok = ! empty( $result['success'] ) || ! empty( $result['ok'] );
		$degraded = ! empty( $result['_degraded'] ) || ! empty( $result['degraded'] );
		$ok = $transport_ok && ! $degraded && $qr_base64 !== '';
		if ( $ok ) {
			return array(
				'ok' => true,
				'success' => true,
				'operation_status' => 'ready',
				'qr_base64' => $qr_base64,
				'operation_id' => sanitize_text_field( $operation_id ),
				'request_id' => sanitize_text_field( $request_id ),
				'stage' => 'qr_generation',
				'reason_bucket' => 'qr_generated',
			);
		}
		$stage = sanitize_key( (string) ( $result['stage'] ?? '' ) );
		$allowed_stages = array( 'account_scope', 'mapping', 'relay', 'session', 'qr_generation', 'payload', 'presentation' );
		if ( ! in_array( $stage, $allowed_stages, true ) ) {
			$stage = 'qr_generation';
		}
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — accept the sidecar's `error` field so already-connected sessions are not misclassified as empty QR responses.
		$reason = sanitize_key( (string) ( $result['reason_bucket'] ?? $result['code'] ?? $result['error'] ?? '' ) );
		$allowed_reasons = array( 'account_not_found', 'personal_accounts_only', 'already_connected', 'qr_in_progress', 'qr_expired', 'qr_declined', 'qr_failed', 'mapping_failed', 'mapping_missing', 'relay_auth_failed', 'relay_timeout', 'sidecar_session_failed', 'qr_session_start_failed', 'invalid_json', 'qr_response_invalid', 'qr_response_empty', 'unauthorized', 'managed_bridge_upstream_error' );
		if ( ! in_array( $reason, $allowed_reasons, true ) ) {
			$reason = 'qr_response_empty';
		}
		if ( $reason === 'already_connected' ) {
			return array(
				'ok' => false,
				'success' => false,
				'operation_status' => 'blocked',
				'code' => 'invalid_param',
				'message' => 'Tài khoản Zalo đã kết nối, không cần tạo mã QR mới.',
				'hint' => 'Mở trạng thái tài khoản hoặc ngắt kết nối trước khi tạo mã QR mới.',
				'help_code' => 'invalid_param_generic',
				'stage' => $stage,
				'reason_bucket' => $reason,
				'operation_id' => sanitize_text_field( $operation_id ),
				'request_id' => sanitize_text_field( $request_id ),
			);
		}
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — never reflect upstream message/hint fields because they may contain response bodies, credentials or provider identity.
		return array(
			'ok' => false,
			'success' => false,
			'_degraded' => true,
			'operation_status' => 'degraded',
			'code' => 'qr_operation_failed',
			'message' => 'Chưa tạo được mã QR đăng nhập Zalo Cá nhân.',
			'hint' => 'Kiểm tra trạng thái bridge và thử tạo mã QR lại sau ít phút.',
			'help_code' => 'zalo_qr_generation_failed',
			'stage' => $stage,
			'reason_bucket' => $reason,
			'operation_id' => sanitize_text_field( $operation_id ),
			'request_id' => sanitize_text_field( $request_id ),
		);
	}

	/** Write only bounded QR operation evidence to the channel log. */
	private static function trace_qr_step( string $event, array $context = array() ): void {
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — record QR stage/reason correlation without account IDs, credentials or upstream bodies.
		$context['blog_id'] = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_PERSONAL, BizCity_Channel_File_Logger::LEVEL_INFO, sanitize_key( $event ), 'Zalo Personal QR operation trace.', $context );
		}
	}

	public static function handle_qr_status( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'id' );
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->get_qr_status( $id );
		if ( ! empty( $result['_degraded'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'message' => $result['message'] ?? '' ) );
		}
		$status = (string) ( $result['status'] ?? 'pending_qr' );
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && in_array( $status, array( 'connected', 'expired', 'logged_out' ), true ) ) {
			$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			if ( ! $local ) {
				$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'oa', $id );
			}
			if ( $local ) {
				// [2026-08-21 Johnny Chu] PHASE-0.39B — mirror terminal sidecar state into the local account registry.
				BizCity_Zalo_Mapping_Repo::update_account_status( (int) $local['id'], $status );
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => $status, 'success' => true ) );
	}

	/** Read one bounded experimental group-history page for an admin-scoped account. */
	public static function handle_group_history( WP_REST_Request $request ): WP_REST_Response {
		// [2026-09-03 02:17 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H1-GROUP — expose group history only through the server-side bridge client; do not ingest CRM rows in this experimental route.
		$id = (string) $request->get_param( 'id' );
		$thread_ref = sanitize_text_field( (string) $request->get_param( 'thread_ref' ) );
		$cursor = sanitize_text_field( (string) $request->get_param( 'cursor' ) );
		$count = max( 1, min( 50, absint( $request->get_param( 'count' ) ?: 20 ) ) );
		if ( $thread_ref === '' ) {
			// [2026-09-03 04:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H4-GROUP — keep invalid history requests explicitly dry-run and non-resumable at the WordPress boundary.
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, '_degraded' => true, 'experimental' => true, 'import_mode' => 'dry_run', 'side_effects_allowed' => false, 'resume_supported' => false, 'storage_target' => 'context_bank_filestore', 'duplicate_policy' => 'record_id_before_write', 'write_enabled' => false, 'code' => 'invalid_param', 'message' => 'Thiếu tham chiếu nhóm Zalo cần đọc lịch sử thử nghiệm.', 'hint' => 'Chọn một nhóm từ danh sách lịch sử thử nghiệm rồi thử lại.', 'help_code' => 'invalid_param_generic' ), 200 );
		}
		if ( $cursor !== '' ) {
			// [2026-09-03 04:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H4-GROUP — reject unsupported cursor replay before any managed/custom bridge transport.
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, '_degraded' => true, 'experimental' => true, 'import_mode' => 'dry_run', 'side_effects_allowed' => false, 'resume_supported' => false, 'storage_target' => 'context_bank_filestore', 'duplicate_policy' => 'record_id_before_write', 'write_enabled' => false, 'code' => 'history_pagination_unavailable', 'message' => 'Lịch sử nhóm thử nghiệm chưa hỗ trợ tiếp tục bằng cursor.', 'hint' => 'Đọc lại trang thử nghiệm từ đầu khi public API hỗ trợ cursor ổn định.', 'help_code' => 'gateway_degraded', 'reason_bucket' => 'history_pagination_unavailable' ), 200 );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->get_group_history( $id, $thread_ref, $count );
		return new WP_REST_Response( is_array( $result ) ? $result : array( 'ok' => false, 'success' => false, '_degraded' => true, 'experimental' => true, 'import_mode' => 'dry_run', 'side_effects_allowed' => false, 'resume_supported' => false, 'storage_target' => 'context_bank_filestore', 'duplicate_policy' => 'record_id_before_write', 'write_enabled' => false, 'code' => 'history_unavailable', 'message' => 'Chưa đọc được lịch sử nhóm Zalo.', 'hint' => 'Kiểm tra session Zalo Personal và thử lại.', 'help_code' => 'gateway_degraded' ), 200 );
	}

	/** Read hash-only experimental group candidates for an admin-scoped account. */
	public static function handle_group_history_candidates( WP_REST_Request $request ): WP_REST_Response {
		// [2026-09-03 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39F-H3-GROUP — keep discovery read-only and avoid CRM/archive ingestion at the WordPress boundary.
		$id = (string) $request->get_param( 'id' );
		$result = BizCity_Zalo_Bridge_Client::instance()->get_group_candidates( $id );
		return new WP_REST_Response( is_array( $result ) ? $result : array( 'ok' => false, 'success' => false, '_degraded' => true, 'experimental' => true, 'import_mode' => 'dry_run', 'side_effects_allowed' => false, 'resume_supported' => false, 'storage_target' => 'context_bank_filestore', 'duplicate_policy' => 'record_id_before_write', 'write_enabled' => false, 'code' => 'history_unavailable', 'message' => 'Chưa lấy được danh sách nhóm Zalo.', 'hint' => 'Kiểm tra session Zalo Personal rồi thử lại.', 'help_code' => 'gateway_degraded' ), 200 );
	}

	/** Poll QR status for an account already resolved inside the current tenant owner scope. */
	public static function qr_status_for_owner( array $account, int $owner_user_id ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1 — status polling keeps the resolved owner row through local state mirroring.
		if ( $owner_user_id <= 0 || (string) ( $account['kind'] ?? '' ) !== 'personal' || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		$result = $id !== '' ? BizCity_Zalo_Bridge_Client::instance()->get_qr_status( $id ) : array( 'success' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy tài khoản Zalo.', 'hint' => 'Tải lại danh sách Kênh của tôi rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' );
		if ( ! empty( $result['_degraded'] ) || empty( $result['success'] ) ) {
			return new WP_REST_Response( array_merge( array( 'ok' => false ), $result ), 200 );
		}
		$status = (string) ( $result['status'] ?? 'pending_qr' );
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && ! empty( $account['id'] ) && in_array( $status, array( 'connected', 'expired', 'logged_out' ), true ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $account['id'], $status );
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => $status, 'success' => true ), 200 );
	}

	// ── OA OAuth ─────────────────────────────────────────────────────────

	public static function handle_oa_connect_url( WP_REST_Request $request ): WP_REST_Response {
		$account_id = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$state      = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$client     = BizCity_Zalo_Bridge_Client::instance();
		$result     = $client->get_oa_connect_url( $account_id, $state );
		if ( ! empty( $result['_degraded'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'message' => $result['message'] ?? '' ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'url' => $result['url'] ?? '' ) );
	}
}
