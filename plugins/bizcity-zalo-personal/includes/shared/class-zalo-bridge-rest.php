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

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — Hub relays a "session_superseded" webhook from this same per-account credential when a QR login elsewhere took over the account's callback.
		register_rest_route( self::NS, '/' . self::PREFIX . '/session-event', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_session_event' ),
			'permission_callback' => '__return_true', // Bearer verified in handler.
		) );

		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 D-ZA-2 — the Hub pulls this site's zalo-hub config_bundle for a cell that reconciles; same per-account Bearer as /session-event.
		register_rest_route( self::NS, '/' . self::PREFIX . '/hub-config-pull', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_hub_config_pull' ),
			'permission_callback' => '__return_true', // Bearer verified in handler.
		) );

		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 R-GURU-SOURCE GS-4 — Hub → site Guru Context API (bizcity-guru-context/1.0): profile
		// (instruction) and turn context (prompt) of the Guru answering ONE number; per-account Bearer, same boundary as /session-event.
		register_rest_route( self::NS, '/' . self::PREFIX . '/guru-profile', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_guru_profile' ),
			'permission_callback' => '__return_true', // Bearer verified in handler.
		) );
		register_rest_route( self::NS, '/' . self::PREFIX . '/guru-context', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_guru_context' ),
			'permission_callback' => '__return_true', // Bearer verified in handler.
		) );

		// [2026-08-23 Johnny Chu] PHASE-0.39E — independent monitor alert ingress; no CRM/bridge write.
		register_rest_route( self::NS, '/' . self::PREFIX . '/health-alert', array(
			'methods'              => 'POST',
			'callback'            => array( __CLASS__, 'handle_health_alert' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-09-18] R-ZP-ERR — read-only, credential-free contract catalog for any logged-in client.
		register_rest_route( self::NS, '/' . self::PREFIX . '/error-catalog', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_error_catalog' ),
			'permission_callback' => 'is_user_logged_in',
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

		// [2026-09-25 Claude Sonnet 5] Channel Gateway "Trợ lý & số Zalo" → tab "Thành viên WP": members of THIS
		// blog with the Zalo Personal numbers each one owns. Same owner boundary as CRM's `crm-phones` (the
		// `*_for_owner` methods below); only the actor differs — a site administrator here, `can_manage()`.
		register_rest_route( self::NS, '/' . self::PREFIX . '/members', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list_members' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		register_rest_route( self::NS, '/' . self::PREFIX . '/members/(?P<user_id>\d+)/accounts', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_member_create_account' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		register_rest_route( self::NS, '/' . self::PREFIX . '/members/(?P<user_id>\d+)/accounts/(?P<id>[^/]+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'handle_member_update_account' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_member_delete_account' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );
		register_rest_route( self::NS, '/' . self::PREFIX . '/members/(?P<user_id>\d+)/accounts/(?P<id>[^/]+)/transfer', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_member_transfer_account' ),
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

		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-3 (D-L36/D-L43) — owner keeps/stops AI per number inside the plan pool.
		register_rest_route( self::NS, '/' . self::PREFIX . '/accounts/(?P<id>[^/]+)/ai-enabled', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_set_ai_enabled' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// [2026-09-26] PHASE-0.80 Lane C 4a-8 — zalo-hub config sync: last result (GET) and "Đồng bộ lại toàn bộ" (POST).
		register_rest_route( self::NS, '/' . self::PREFIX . '/hub-config', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_hub_config_status' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_hub_config_sync' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
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
		return class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' );
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
	/**
	 * Hub session-event webhook: a QR login on another website superseded this account's Zalo
	 * login. Marks the local account `logged_out` immediately instead of waiting for the next
	 * status poll to discover `managed_account_other_site`. Auth reuses the same per-account
	 * callback token the Hub already uses for /inbound.
	 */
	public static function handle_session_event( WP_REST_Request $request ): WP_REST_Response {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — same Bearer boundary as handle_inbound; a mismatched or missing token must not reveal whether the account exists.
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$bridge_id = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$stored_token = $bridge_id !== '' ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $bridge_id ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( $stored_token === '' || $bearer === '' || ! hash_equals( $stored_token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$event = sanitize_key( (string) ( $body['event'] ?? '' ) );
		if ( 'session_superseded' === $event && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			self::mark_moved_away( $bridge_id );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

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
					// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — R-CH-10 drops a channel record with no exact account
					// scope, so inbound_attempt / inbound_failed / inbound_accepted were never written. The bridge account
					// id is already the scope of every bizcity_zalo_message_received row in the same file.
					'account_id'        => sanitize_text_field( (string) ( $probe_body['account_id'] ?? '' ) ),
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
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-5/4a-6 (D-L43) — every authenticated relay states this account's provider
		// (cell events carry provider=zalo_hub) and, via X-BizCity-Account-AI: off, that its AI is off. Recorded BEFORE the emitter runs,
		// so Bot Studio's claim in this same request already sees it. zca payloads (no `event`) keep the historical path below.
		if ( is_array( $body ) && class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			BizCity_Zalo_Account_Flags::observe_inbound( $account_id, $body, (string) $request->get_header( BizCity_Zalo_Account_Flags::AI_HEADER ) );
		}
		$hub_event = is_array( $body ) ? sanitize_key( (string) ( $body['event'] ?? '' ) ) : '';
		if ( '' !== $hub_event && 'inbound_forward' !== $hub_event && class_exists( 'BizCity_Zalo_Hub_Events' ) ) {
			$handled = BizCity_Zalo_Hub_Events::handle( $hub_event, $body );
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( $probe_channel, BizCity_Channel_File_Logger::LEVEL_INFO, 'hub_event', 'Zalo Hub event processed.', array( 'account_id' => $account_id, 'event' => $hub_event, 'status' => (int) $handled['status'], 'trace_id' => $trace_id, 'request_id' => sanitize_text_field( (string) $request->get_header( 'x-bizcity-request-id' ) ) ) );
			}
			return new WP_REST_Response( $handled['body'], (int) $handled['status'] );
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
				BizCity_Channel_File_Logger::write( $probe_channel, BizCity_Channel_File_Logger::LEVEL_ERROR, 'inbound_failed', 'Zalo channel CRM ingest failed.', array( 'reason' => 'crm_ingest_failed', 'account_id' => $account_id, 'trace_id' => $trace_id, 'thread_kind' => sanitize_key( (string) ( $body['thread_kind'] ?? '' ) ), 'provider_id_hash' => substr( hash( 'sha256', (string) ( $body['message_id'] ?? '' ) ), 0, 16 ), 'text_bytes' => strlen( (string) ( $body['message_text'] ?? '' ) ) ) );
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
			BizCity_Channel_File_Logger::write( $probe_channel, BizCity_Channel_File_Logger::LEVEL_INFO, 'inbound_accepted', 'Zalo channel inbound processed.', array( 'account_id' => $account_id, 'trace_id' => $trace_id, 'crm_message_id' => $msg_id > 0 ? (int) $msg_id : 0, 'ignored' => $msg_id < 0 ) );
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
	 * Receive a signed state transition from a VPS/Hub monitor. Since WP-14 (2026-09-28) nothing is
	 * delivered to operators: the Notification Center was retired; the route answers `notifications_retired`.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_health_alert( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-23 Johnny Chu] PHASE-0.39E — verify the existing inbound M2M credential before notification.
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
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
		// [2026-09-28 Claude Opus 5.5] WP-14 W5 — the Notification Center (CG dispatcher) was retired by the owner.
		// Accept the alert (auth and state checks above still apply) and say it was not delivered, so the
		// monitor does not treat a missing module as an outage and retry. Before WP-14 this answered 503.
		return new WP_REST_Response( array(
			'success'   => true,
			'accepted'  => true,
			'delivered' => false,
			'code'      => 'notifications_retired',
			'state'     => $state,
			'message'   => 'Đã nhận alert; Thông báo đã ngừng nên không gửi cho quản trị viên.',
		), 200 );
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
			// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-2 (T-13) — provider for NEW numbers only; existing numbers keep theirs.
		) + self::provider_choice( $capability ) );
	}

	/**
	 * Which assistant a NEW number may get (T-13, 4a-4/4a-9) — one answer for Bot Studio › Thành viên and CRM › Thêm SĐT.
	 * zalo_hub is offered only when the Hub says this exact key may use it (P-5). `default_provider` is the stored site default
	 * as-is (the Settings screen edits it); a UI pre-selects zalo_hub only when BOTH say so, as ZaloPersonalMembers.jsx does.
	 *
	 * @param array|null $capability `BizCity_Zalo_Personal_Hub_Client::capability()` result, or null to read it now (managed mode only).
	 * @return array{default_provider:string,zalo_hub_allowed:bool,zalo_hub_reason:string}
	 */
	public static function provider_choice( $capability = null ): array {
		if ( null === $capability && 'managed_1api' === BizCity_Zalo_Bridge_Client::instance()->get_mode() && class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			$capability = BizCity_Zalo_Personal_Hub_Client::instance()->capability();
		}
		$hub = is_array( $capability ) && isset( $capability['capability']['providers']['zalo_hub'] ) && is_array( $capability['capability']['providers']['zalo_hub'] ) ? $capability['capability']['providers']['zalo_hub'] : array();
		$allowed = ! empty( $hub['allowed'] );
		$default = class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::default_provider() : 'zca';
		return array(
			'default_provider' => $default,
			'zalo_hub_allowed' => $allowed,
			'zalo_hub_reason'  => $allowed ? '' : sanitize_key( (string) ( $hub['reason'] ?? ( is_array( $capability ) && empty( $capability['success'] ) ? 'hub_unavailable' : 'feature_not_enabled' ) ) ),
		);
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
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-2/4a-9 (P-5) — zalo_hub may only become the default when the Hub says this key may use it.
		if ( isset( $body['default_provider'] ) && class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			$wanted = BizCity_Zalo_Account_Flags::normalize_provider( (string) $body['default_provider'] );
			if ( BizCity_Zalo_Account_Flags::PROVIDER_ZALO_HUB === $wanted ) {
				$cap = 'managed_1api' === $mode && class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ? BizCity_Zalo_Personal_Hub_Client::instance()->capability() : array();
				if ( empty( $cap['capability']['providers']['zalo_hub']['allowed'] ) ) {
					return new WP_REST_Response( array(
						'ok'        => false,
						'code'      => 'zalo_hub_not_enabled',
						'message'   => 'Gói hiện tại của website chưa dùng được zalo-hub.',
						'hint'      => 'Giữ zca làm mặc định; zalo-hub mở khi BizCity bật cho API key này.',
						'help_code' => 'zalo_hub_not_enabled',
						'reason'    => (string) ( $cap['capability']['providers']['zalo_hub']['reason'] ?? '' ),
					), 200 );
				}
			}
			update_option( BizCity_Zalo_Account_Flags::DEFAULT_PROVIDER_OPTION, $wanted, false );
		}
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

	/**
	 * Run the no-side-effect checks used by account creation.
	 *
	 * @return WP_REST_Response|null An error response when creation is blocked; null when ready.
	 */
	public static function preflight_create_account_for_owner( WP_REST_Request $request, int $owner_user_id, bool $personal_only = false ) {
		// [2026-09-19 Johnny Chu] PHASE-0.54A K-04 — expose the duplicate/quota gate without creating a Hub account or local mapping.
		$body = $request->get_json_params();
		$label = sanitize_text_field( $body['label'] ?? '' );
		$kind = in_array( $body['kind'] ?? 'personal', array( 'personal', 'oa' ), true ) ? $body['kind'] : 'personal';
		if ( $personal_only ) {
			$kind = 'personal';
		}
		if ( 'personal' === $kind && class_exists( 'BizCity_Zalo_Duplicate_Guard' ) && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$duplicate = BizCity_Zalo_Duplicate_Guard::find_phone_duplicate( $label, BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) );
			if ( $duplicate ) {
				return new WP_REST_Response( self::with_error_contract( BizCity_Zalo_Duplicate_Guard::create_blocked_payload( $duplicate ) ), 200 );
			}
		}
		if ( 'personal' === $kind && class_exists( 'BizCity_Channel_User_Grant' ) && method_exists( 'BizCity_Channel_User_Grant', 'personal_quota_status' ) ) {
			$quota_status = BizCity_Channel_User_Grant::personal_quota_status( $owner_user_id );
			if ( ! empty( $quota_status['reached'] ) ) {
				return new WP_REST_Response( self::personal_quota_payload( (int) $quota_status['quota'] ), 200 );
			}
		}
		return null;
	}

	/**
	 * Create and bind an account for an already-resolved tenant owner.
	 *
	 * @param bool $authorized_for_other [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N2 (E4-03/G3) —
	 *   when true and `$owner_user_id !== $actor_user_id`, the caller has ALREADY authorized adding a
	 *   number for someone else (CRM's `Staff_Policy::can(actor,'phone.add_for_other',owner)`, admin-only
	 *   per D2 2026-09-18) and the primary bind goes through `BizCity_Channel_User_Grant::bind_primary_for_owner()`
	 *   instead of the self-only `bind_primary_from_current()`. Every existing call site omits this (default
	 *   false) and keeps binding to the caller themselves, unchanged.
	 * @param int  $actor_user_id Who is actually calling (for grant audit); defaults to `$owner_user_id` self-bind.
	 */
	public static function create_account_for_owner( WP_REST_Request $request, int $owner_user_id, bool $personal_only = false, bool $authorized_for_other = false, int $actor_user_id = 0 ): WP_REST_Response {
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
		$preflight = self::preflight_create_account_for_owner( $request, $owner_user_id, $personal_only );
		if ( $preflight instanceof WP_REST_Response ) {
			$preflight_data = $preflight->get_data();
			self::trace_create_step( 'create_preflight_blocked', array( 'code' => sanitize_key( (string) ( $preflight_data['code'] ?? '' ) ) ) );
			return $preflight;
		}
		$client = BizCity_Zalo_Bridge_Client::instance();
		self::trace_create_step( 'hub_request', array( 'kind' => $kind ) );
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-2 — optional `provider` (zca|zalo_hub) per new number; absent = site default (T-13). The Hub enforces the plan (P-5).
		$create_data = array( 'label' => $label, 'type' => $kind );
		if ( 'personal' === $kind && isset( $body['provider'] ) && is_string( $body['provider'] ) && '' !== $body['provider'] ) {
			$create_data['provider'] = sanitize_key( $body['provider'] );
		}
		$result = $client->create_account( $create_data );
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
			return new WP_REST_Response( self::with_error_contract( $response ) );
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
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — bind the server-resolved self-connect user as the sole channel primary only after mapping and CRM ownership persist.
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'orphaned' );
			$client->delete_account( $bridge_id );
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => 'channel_grant_unavailable',
				'message'   => 'Chưa khởi tạo được quyền sở hữu kênh Zalo.',
				'hint'      => 'Tải lại Channel Gateway rồi thử kết nối lại.',
				'help_code' => 'module_not_loaded',
			), 200 );
		}
		$grant = ( $authorized_for_other && $actor_user_id > 0 && $actor_user_id !== $owner_user_id )
			? BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', $bridge_id, $owner_user_id, $actor_user_id, true, array( 'source' => 'crm_add_for_other' ) )
			: BizCity_Channel_User_Grant::bind_primary_from_current( 'zalo_personal', $bridge_id, array(
				'connection_verified'    => true,
				'connection_owner_user_id' => $owner_user_id,
				'source'                 => 'twinweb_self_connect',
			) );
		if ( empty( $grant['ok'] ) ) {
			self::trace_create_step( 'channel_grant_failed', array( 'reason' => sanitize_key( (string) ( $grant['reason'] ?? 'grant_write_failed' ) ) ) );
			BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'orphaned' );
			$client->delete_account( $bridge_id );
			$reason = sanitize_key( (string) ( $grant['reason'] ?? 'grant_write_failed' ) );
			if ( 'personal_account_quota_reached' === $reason ) {
				// Race with another connect finishing first; same R-ERROR-UX copy as the pre-check.
				return new WP_REST_Response( self::personal_quota_payload( (int) ( $grant['quota'] ?? 0 ) ), 200 );
			}
			return new WP_REST_Response( array(
				'ok'        => false,
				'code'      => $reason,
				'message'   => 'Không thể cấp quyền sở hữu tài khoản Zalo này.',
				'hint'      => 'Kiểm tra tài khoản thành viên và trạng thái kết nối rồi thử lại.',
				'help_code' => 'permission_denied',
			), 200 );
		}
		self::trace_create_step( 'channel_grant_bound', array( 'relation' => 'primary' ) );
		self::trace_create_step( 'create_complete', array( 'local_id' => (int) $local_id, 'inbox_id' => (int) $inbox_id ) );

		$created = array( 'ok' => true, 'id' => $bridge_id, 'crm_inbox_id' => $inbox_id, 'owner_user_id' => $owner_user_id );
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-3 — additive: which provider really serves the number, and a fallback note when the site default could not be used.
		if ( class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			$created['provider'] = BizCity_Zalo_Account_Flags::provider( $bridge_id );
		}
		if ( isset( $result['provider_fallback'] ) && is_array( $result['provider_fallback'] ) ) {
			$created['provider_fallback'] = $result['provider_fallback'];
		}
		// [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 OB-6 — additive hook after a number is fully created (mapping + CRM inbox + grant),
		// BEFORE the config sync below, so a listener that binds a Guru (first number ⇒ bot on with guru:0, D-OB-3) is part of that sync.
		$created = (array) apply_filters( 'bizcity_zalo_personal_account_created', $created, (string) $bridge_id, (string) $kind, (int) $owner_user_id );
		// [2026-09-26] PHASE-0.80 Lane C 4a-8 — a new zalo-hub number must not answer with an empty persona: sync soon (WP-Cron; the 5-minute tick is the backstop).
		if ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) && isset( $created['provider'] ) && 'zalo_hub' === $created['provider'] ) {
			BizCity_Zalo_Hub_Config_Sync::schedule( 2 );
		}
		return new WP_REST_Response( $created, 200 );
	}

	/** PHASE-0.50 UID-02 — R-ERROR-UX envelope when a user already owns the site's allowed number of Zalo Personal accounts. */
	private static function personal_quota_payload( int $quota ): array {
		return array(
			'ok'        => false,
			'code'      => 'personal_account_quota_reached',
			'message'   => $quota > 0
				? sprintf( 'Bạn đã dùng hết %d SĐT Zalo Cá nhân được phép trên website này.', $quota )
				: 'Bạn đã dùng hết số SĐT Zalo Cá nhân được phép trên website này.',
			'hint'      => 'Gỡ một SĐT không còn dùng, hoặc nhờ quản trị viên tăng hạn mức ở CRM → Nhân sự.',
			'help_code' => 'personal_account_quota_reached',
			'quota'     => $quota,
		);
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
		// [2026-09-27 Claude Sonnet 5] Same fix as `delete_account_for_owner()` (the CRM "Gỡ số" path) — this is the
		// SEPARATE "Kênh của tôi" admin delete, which had the identical bug: the Hub saying `account_not_owned`
		// (deleted server-side / API key no longer matches) made this call fail forever, so a stuck number could
		// never be gỡ'd here even though the goal — no live session at the Hub — was already true.
		$already_gone = false;
		if ( ! $ok && class_exists( 'BizCity_Zalo_Session_Errors' ) ) {
			$bucket = BizCity_Zalo_Session_Errors::bucket_for( (string) ( $result['reason_bucket'] ?? '' ) );
			if ( '' === $bucket ) {
				$bucket = BizCity_Zalo_Session_Errors::bucket_for( (string) ( $result['code'] ?? '' ) );
			}
			if ( 'account_not_owned' === $bucket ) {
				$ok = true;
				$already_gone = true;
			}
		}
		if ( $ok && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id );
			if ( ! $local ) {
				$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'oa', $id );
			}
			if ( $local ) {
				// [2026-08-21 Johnny Chu] PHASE-0.39B — retain CRM history; only the local session state changes.
				// [2026-09-18] R-ZP-DUP DUP-11 — a deleted bridge account is 'revoked', not 'logged_out': it must stop
				// offering QR re-login and stop being counted as a duplicate twin. CRM Inbox + history are kept.
				BizCity_Zalo_Mapping_Repo::update_account_status( (int) $local['id'], BizCity_Zalo_Duplicate_Guard::STATUS_REVOKED );
			}
		}
		return new WP_REST_Response( array( 'ok' => $ok, 'success' => $ok, 'already_gone' => $already_gone ) );
	}

	// ── Members of this blog + the Zalo Personal numbers each one owns ────

	/** Statuses that mean the number is gone; hidden from the member view (CRM history is kept). */
	private const MEMBER_DEAD_STATUSES = array( 'revoked', 'orphaned', 'deleted' );

	private static function member_error( string $code, string $message, string $hint = '', int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'        => false,
			'code'      => $code,
			'message'   => $message,
			'hint'      => $hint,
			'help_code' => 404 === $status ? 'not_found' : 'invalid_param_generic',
		), $status );
	}

	/** A user counts as a member only when they belong to the CURRENT blog (multisite-safe). */
	/**
	 * User ids of this blog with a manager-level role (administrator, editor, shop_manager) plus multisite super admins
	 * that belong to the blog. Never empty: `[0]` matches nobody, because an empty `include` means "no restriction".
	 *
	 * @return int[]
	 */
	private static function manager_user_ids(): array {
		$ids = get_users( array(
			'blog_id'  => (int) get_current_blog_id(),
			'role__in' => array( 'administrator', 'editor', 'shop_manager' ),
			'fields'   => 'ID',
			'number'   => 500,
		) );
		$ids = array_map( 'intval', (array) $ids );
		if ( function_exists( 'get_super_admins' ) && function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( (array) get_super_admins() as $login ) {
				$user = get_user_by( 'login', (string) $login );
				if ( $user && self::is_blog_member( (int) $user->ID ) ) {
					$ids[] = (int) $user->ID;
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return $ids ? $ids : array( 0 );
	}

	private static function is_blog_member( int $user_id ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}
		return ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $user_id, (int) get_current_blog_id() );
	}

	/** Client-safe number row: no raw provider identifiers (zalo_uid stays server-side). */
	private static function member_account_row( array $account ): array {
		$row = array(
			'id'           => (string) ( $account['bridge_account_id'] ?? '' ),
			'label'        => (string) ( $account['label'] ?? '' ),
			'status'       => sanitize_key( (string) ( $account['status'] ?? '' ) ),
			'crm_inbox_id' => (int) ( $account['crm_inbox_id'] ?? 0 ),
			'updated_at'   => (string) ( $account['updated_at'] ?? '' ),
		);
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-3 — additive: badge zca/zalo-hub and whether the number still has AI (over_limit).
		if ( class_exists( 'BizCity_Zalo_Account_Flags' ) && '' !== $row['id'] ) {
			$flags = BizCity_Zalo_Account_Flags::get( $row['id'] );
			$row['provider']   = $flags['provider'];
			$row['ai_enabled'] = $flags['ai_enabled'];
			$row['over_limit'] = ! $flags['ai_enabled'];
		}
		return $row;
	}

	/**
	 * GET /zalo-bridge/hub-config — last zalo-hub config sync (counts and codes only, never persona text).
	 * [2026-09-27 Claude Opus 5.5] PHASE-0.81 C3.5 — `?cell=1` also reads what the cell holds (C-7 brain overview via the Hub relay):
	 * `cell = {config_version, platform_config_version, snapshot}` (tenant level, so one zalo-hub number is enough), or `cell: null`
	 * with `cell_code` when it cannot be read. Opt-in because it is a remote call.
	 */
	public static function handle_hub_config_status( $request = null ): WP_REST_Response {
		$out = array( 'ok' => true, 'sync' => BizCity_Zalo_Hub_Config_Sync::status() );
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) && ! empty( $request->get_param( 'cell' ) ) ) {
			$accounts = BizCity_Zalo_Hub_Config_Sync::accounts();
			$bridge   = $accounts ? (string) $accounts[0]['bridge_id'] : '';
			$overview = '' !== $bridge && class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'brain_overview' )
				? BizCity_Zalo_Personal_Hub_Client::instance()->brain_overview( $bridge ) : null;
			$out['cell'] = is_array( $overview ) ? array(
				'config_version'          => (int) $overview['config_version'],
				'platform_config_version' => (int) ( $overview['platform_config_version'] ?? 0 ),
				'snapshot'                => array_key_exists( 'snapshot', $overview ) ? ( is_array( $overview['snapshot'] ) ? array( 'allowed' => ! empty( $overview['snapshot']['allowed'] ), 'expires_at' => (string) ( $overview['snapshot']['expires_at'] ?? '' ) ) : null ) : 'unknown',
			) : null;
			if ( null === $out['cell'] ) {
				$out['cell_code'] = '' === $bridge ? 'no_accounts' : 'cell_unreadable';
			}
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /zalo-bridge/hub-config-pull {account_id, have_version} — Hub → site (D-ZA-2). Auth = the per-account callback
	 * token of one zalo-hub number of this site (the Hub never holds WP credentials); a wrong token reveals nothing.
	 * Returns the same bundle the push would send, or `unchanged` when the cell already has this version.
	 */
	public static function handle_hub_config_pull( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$bridge_id = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$stored_token = $bridge_id !== '' ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $bridge_id ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( $stored_token === '' || $bearer === '' || ! hash_equals( $stored_token, $bearer ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'config_sync_unavailable' ), 503 );
		}
		$result = BizCity_Zalo_Hub_Config_Sync::pull( max( 0, (int) ( $body['have_version'] ?? 0 ) ) );
		return new WP_REST_Response( $result, $result['ok'] ? 200 : 409 );
	}

	/* ================================================================
	 *  R-GURU-SOURCE GS-4 — Guru Context API, site side (Hub → site)
	 * ================================================================ */

	/**
	 * Shared gate of guru-profile / guru-context: per-account callback token (a wrong token reveals nothing), then the Guru that
	 * ANSWERS this number (bound Guru, else the default Guru = gate 0). Least privilege (R-GP-3): the caller may only read that one
	 * Guru, addressed as `guru:0` or `guru:<id>`; any other ref is 404 so a cell can never enumerate the site's Gurus.
	 *
	 * @return array{ok:bool,response?:WP_REST_Response,bridge_id?:string,character_id?:int}
	 */
	private static function guru_gate( WP_REST_Request $request, string $bridge_id, string $ref ): array {
		$stored_token = $bridge_id !== '' ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $bridge_id ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( $stored_token === '' || $bearer === '' || ! hash_equals( $stored_token, $bearer ) ) {
			return array( 'ok' => false, 'response' => new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized', 'message' => 'Unauthorized.' ), 401 ) );
		}
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			return array( 'ok' => false, 'response' => new WP_REST_Response( array( 'ok' => false, 'code' => 'site_guru_unsupported', 'message' => 'Guru context resolver is not loaded on this site.', 'hint' => 'Update bizcity-twin-ai (channel gateway) to the R-GURU-SOURCE release.', 'help_code' => 'site_guru_unsupported' ), 503 ) );
		}
		$binding = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', $bridge_id ) : null;
		$bound   = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
		$answering = BizCity_Guru_Context_Resolver::answering_character_id( $bound );
		$default   = BizCity_Guru_Context_Resolver::default_character_id( false );
		// `auto` = "the Guru answering this number" (Hub console R8 does not know which one); still exactly one Guru, never a list.
		$allowed   = $answering > 0 && ( 'auto' === $ref || $ref === 'guru:' . $answering || ( BizCity_Guru_Context_Resolver::DEFAULT_REF === $ref && $answering === $default ) );
		if ( ! $allowed ) {
			return array( 'ok' => false, 'response' => new WP_REST_Response( array( 'ok' => false, 'code' => 'guru_not_found', 'message' => 'This Guru does not answer this number.', 'hint' => 'Use the agent_ref of the latest config_bundle, or guru:0.', 'help_code' => 'guru_not_found' ), 404 ) );
		}
		return array( 'ok' => true, 'bridge_id' => $bridge_id, 'character_id' => $answering );
	}

	/** [2026-09-27 Claude Opus 5.5] PHASE-0.81 C1.1 — same gate for the notebook routes (BizCity_Zalo_Guru_Knowledge_REST). */
	public static function guru_gate_s81( WP_REST_Request $request, string $bridge_id, string $ref ): array {
		return self::guru_gate( $request, $bridge_id, $ref );
	}

	/** Contract envelope minus internal fields. */
	private static function guru_public( array $profile ): array {
		unset( $profile['_character_id'] );
		return $profile;
	}

	/** GET /zalo-bridge/guru-profile?account_id=&ref= — instruction + scope + compose (no turn data). ETag / If-None-Match ⇒ 304. */
	public static function handle_guru_profile( WP_REST_Request $request ): WP_REST_Response {
		$bridge_id = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$ref       = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$gate = self::guru_gate( $request, $bridge_id, $ref );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$profile = self::guru_public( BizCity_Guru_Context_Resolver::profile( (int) $gate['character_id'] ) );
		$etag = (string) $profile['guru']['etag'];
		if ( $etag !== '' && trim( (string) $request->get_header( 'if_none_match' ), " \"" ) === $etag ) {
			$r = new WP_REST_Response( null, 304 );
			$r->header( 'ETag', '"' . $etag . '"' );
			return $r;
		}
		$r = new WP_REST_Response( $profile, 200 );
		$r->header( 'ETag', '"' . $etag . '"' );
		return $r;
	}

	/**
	 * POST /zalo-bridge/guru-context {account_id, ref, thread_id, query, include_instruction, max_blocks, max_chars} — the turn's `prompt`
	 * (customer block, scope-allowed knowledge). The customer text travels in the body only, never in a URL. Read-only.
	 */
	public static function handle_guru_context( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$bridge_id = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$ref       = sanitize_text_field( (string) ( $body['ref'] ?? '' ) );
		$gate = self::guru_gate( $request, $bridge_id, $ref );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$cid        = (int) $gate['character_id'];
		$thread_id  = sanitize_text_field( (string) ( $body['thread_id'] ?? '' ) );
		$contact_id = self::crm_contact_for( $bridge_id, $thread_id );
		$prompt = BizCity_Guru_Context_Resolver::context( $cid, array(
			'contact_id' => $contact_id,
			'query'      => mb_substr( (string) ( $body['query'] ?? '' ), 0, 2000 ),
			'max_blocks' => (int) ( $body['max_blocks'] ?? 0 ) ?: null,
			'max_chars'  => (int) ( $body['max_chars'] ?? 0 ) ?: null,
			'notebooks'  => false, // PHASE-0.81 S81-R4 — the cell retrieves from its own copy of the notebooks (C-4); never twice
		) );
		$profile = self::guru_public( BizCity_Guru_Context_Resolver::profile( $cid ) );
		$out = array(
			'contract'     => $profile['contract'],
			'guru'         => $profile['guru'],
			'prompt'       => $prompt,
			'scope'        => $profile['scope'],
			'compose'      => $profile['compose'],
			'generated_at' => gmdate( 'c' ),
		);
		if ( ! empty( $body['include_instruction'] ) ) {
			$out['instruction'] = $profile['instruction'];
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** CRM contact of a Zalo thread of this number (read-only) — the customer block of the prompt needs it. 0 when unknown. */
	private static function crm_contact_for( string $bridge_id, string $thread_id ): int {
		if ( '' === $thread_id || ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'get_inbox_by_ref' ) || ! method_exists( 'BizCity_CRM_Repository', 'find_contact_id_by_source' ) ) {
			return 0;
		}
		$code  = class_exists( 'BizCity_Bot_Turn_Claim' ) && defined( 'BizCity_Bot_Turn_Claim::CODE' ) ? (string) constant( 'BizCity_Bot_Turn_Claim::CODE' ) : 'zalo_personal';
		$inbox = BizCity_CRM_Repository::get_inbox_by_ref( $code, $bridge_id );
		return is_array( $inbox ) ? (int) BizCity_CRM_Repository::find_contact_id_by_source( (int) ( $inbox['id'] ?? 0 ), $thread_id ) : 0;
	}

	/** POST /zalo-bridge/hub-config — "Đồng bộ lại toàn bộ": send now even if nothing changed. */
	public static function handle_hub_config_sync(): WP_REST_Response {
		$result = BizCity_Zalo_Hub_Config_Sync::run( true );
		return new WP_REST_Response( array( 'ok' => (bool) $result['ok'], 'code' => (string) $result['code'], 'message' => (string) $result['message'], 'sync' => BizCity_Zalo_Hub_Config_Sync::status() ), 200 );
	}

	/**
	 * POST /zalo-bridge/accounts/{id}/ai-enabled {enabled} — the site owner decides which numbers keep AI inside the
	 * plan's account pool (D-L36/D-L43). Managed mode only; the Hub enforces the pool (`account_limit_reached`).
	 */
	public static function handle_set_ai_enabled( WP_REST_Request $request ): WP_REST_Response {
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-3.
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( '' === $id || ! array_key_exists( 'enabled', $body ) ) {
			return new WP_REST_Response( self::with_error_contract( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu mã tài khoản hoặc trường enabled.', 'hint' => 'Gửi {"enabled": true|false}.', 'help_code' => 'invalid_param_generic' ) ), 400 );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return new WP_REST_Response( self::with_error_contract( array( 'ok' => false, '_degraded' => true, 'code' => 'managed_client_missing', 'message' => 'Chỉ số Zalo qua BizCity 1API mới có công tắc AI theo gói.', 'hint' => 'Kiểm tra API key BizCity của website.', 'help_code' => 'api_key_missing' ) ), 200 );
		}
		$result = BizCity_Zalo_Personal_Hub_Client::instance()->set_ai_enabled( $id, (bool) $body['enabled'] );
		if ( empty( $result['success'] ) ) {
			$payload = array(
				'ok'        => false,
				'code'      => (string) ( $result['code'] ?? 'bridge_unavailable' ),
				'message'   => (string) ( $result['message'] ?? 'Không đổi được trạng thái AI của số này.' ),
				'hint'      => (string) ( $result['hint'] ?? 'Thử lại sau.' ),
				'help_code' => (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ),
			);
			foreach ( array( 'account_limit', 'ai_enabled_accounts', 'reason_bucket' ) as $field ) {
				if ( array_key_exists( $field, $result ) ) { $payload[ $field ] = $result[ $field ]; }
			}
			$status = (int) ( $result['http_code'] ?? 200 );
			return new WP_REST_Response( self::with_error_contract( $payload ), $status >= 400 && $status < 500 ? $status : 200 );
		}
		return new WP_REST_Response( array(
			'ok'         => true,
			'id'         => $id,
			'ai_enabled' => BizCity_Zalo_Account_Flags::ai_enabled( $id ),
			'provider'   => BizCity_Zalo_Account_Flags::provider( $id ),
			'capability' => isset( $result['capability'] ) && is_array( $result['capability'] ) ? $result['capability'] : null,
		), 200 );
	}

	/**
	 * GET /zalo-bridge/members — WordPress users of THIS blog (`blog_id`), each with the Zalo Personal
	 * numbers they own and their quota. `role` = '' (default: every role but subscriber, matching CRM's
	 * "assignable" rule), 'all', or one role slug.
	 */
	public static function handle_list_members( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$q        = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$role     = sanitize_key( (string) $request->get_param( 'role' ) );
		$wp_roles = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		$args = array(
			'blog_id'     => (int) get_current_blog_id(),
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
		);
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		if ( 'all' === $role ) {
			// No role filter.
		} elseif ( 'managers' === $role ) {
			// [2026-09-27 Claude Sonnet 5] "Thêm số Zalo" owner picker — manager level and above only: administrator, editor, shop_manager
			// (+ multisite super admins that are members of this blog). Resolved to an `include` id list so search + pagination keep working.
			$args['include'] = self::manager_user_ids();
		} elseif ( '' !== $role && isset( $wp_roles[ $role ] ) ) {
			$args['role'] = $role;
		} else {
			$args['role__not_in'] = array( 'subscriber' );
		}

		$query     = new WP_User_Query( $args );
		$has_repo  = class_exists( 'BizCity_Zalo_Mapping_Repo' );
		$has_grant = class_exists( 'BizCity_Channel_User_Grant' ) && method_exists( 'BizCity_Channel_User_Grant', 'personal_quota_status' );
		$members   = array();
		foreach ( (array) $query->get_results() as $user ) {
			$user_id  = (int) $user->ID;
			$accounts = array();
			if ( $has_repo ) {
				foreach ( BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $account ) {
					if ( in_array( (string) ( $account['status'] ?? '' ), self::MEMBER_DEAD_STATUSES, true ) ) {
						continue;
					}
					$accounts[] = self::member_account_row( $account );
				}
			}
			$quota_status = $has_grant ? BizCity_Channel_User_Grant::personal_quota_status( $user_id ) : array();
			$role_labels  = array();
			foreach ( (array) $user->roles as $slug ) {
				$role_labels[] = isset( $wp_roles[ $slug ] ) ? translate_user_role( $wp_roles[ $slug ] ) : (string) $slug;
			}
			$members[] = array(
				'user_id'      => $user_id,
				'display_name' => sanitize_text_field( (string) $user->display_name ),
				'email'        => sanitize_email( (string) $user->user_email ),
				'avatar_url'   => (string) get_avatar_url( $user_id, array( 'size' => 64 ) ),
				'roles'        => $role_labels,
				'accounts'     => $accounts,
				'quota'        => array(
					'limit'     => (int) ( $quota_status['quota'] ?? 0 ),
					'used'      => (int) ( $quota_status['owned'] ?? 0 ),
					'reached'   => ! empty( $quota_status['reached'] ),
				),
			);
		}

		$role_options = array();
		foreach ( $wp_roles as $slug => $name ) {
			$role_options[] = array( 'slug' => (string) $slug, 'label' => translate_user_role( $name ) );
		}

		return new WP_REST_Response( array(
			'ok'       => true,
			'blog_id'  => (int) get_current_blog_id(),
			'actor_id' => (int) get_current_user_id(),
			'members'  => $members,
			'total'    => (int) $query->get_total(),
			'page'     => $page,
			'per_page' => $per_page,
			'roles'    => $role_options,
		), 200 );
	}

	/** POST /zalo-bridge/members/{user_id}/accounts — add a Zalo Personal number owned by that member (QR follows in the UI). */
	public static function handle_member_create_account( WP_REST_Request $request ) {
		$owner_id = (int) $request->get_param( 'user_id' );
		if ( ! self::is_blog_member( $owner_id ) ) {
			return self::member_error( 'invalid_param', 'Thành viên không thuộc website này.', 'Chọn một thành viên trong danh sách của website.' );
		}
		$body  = $request->get_json_params();
		$label = trim( sanitize_text_field( (string) ( is_array( $body ) ? ( $body['label'] ?? '' ) : '' ) ) );
		if ( '' === $label ) {
			return self::member_error( 'invalid_param', 'Nhập nhãn cho số Zalo.', 'Ví dụ: "Nick CSKH chính".' );
		}
		$actor_id = (int) get_current_user_id();
		// `true` = personal only; the 4th arg marks "an administrator adds it for someone else" (grant audit).
		return self::create_account_for_owner( $request, $owner_id, true, $owner_id !== $actor_id, $actor_id );
	}

	/**
	 * Resolve `{user_id}` + `{id}` (bridge account id) to a number that member really owns — never trusts the
	 * URL pair alone, so an id from another member's row cannot be edited through this member's path.
	 *
	 * @return array{owner_id:int,account:array}|WP_REST_Response
	 */
	private static function resolve_member_account( WP_REST_Request $request ) {
		$owner_id = (int) $request->get_param( 'user_id' );
		$id       = (string) $request->get_param( 'id' );
		$account  = ( $owner_id > 0 && '' !== $id && class_exists( 'BizCity_Zalo_Mapping_Repo' ) )
			? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $id )
			: null;
		if ( ! is_array( $account ) || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_id ) {
			return self::member_error( 'not_found', 'Không tìm thấy số Zalo này của thành viên.', 'Tải lại danh sách rồi thử lại.', 404 );
		}
		return array( 'owner_id' => $owner_id, 'account' => $account );
	}

	/** PATCH /zalo-bridge/members/{user_id}/accounts/{id} — rename the number (account row + CRM inbox name). */
	public static function handle_member_update_account( WP_REST_Request $request ) {
		$resolved = self::resolve_member_account( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		$account = $resolved['account'];
		$body    = $request->get_json_params();
		$label   = mb_substr( trim( sanitize_text_field( (string) ( is_array( $body ) ? ( $body['label'] ?? '' ) : '' ) ) ), 0, 190 );
		if ( '' === $label ) {
			return self::member_error( 'invalid_param', 'Nhập tên hiển thị.' );
		}
		BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $resolved['owner_id'],
			'label'             => $label,
			'bridge_account_id' => (string) ( $account['bridge_account_id'] ?? '' ),
			'zalo_uid'          => (string) ( $account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => (string) ( $account['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => (int) ( $account['crm_inbox_id'] ?? 0 ),
			'status'            => (string) ( $account['status'] ?? 'pending_qr' ),
		) );
		// The CRM rail renders the inbox name, which is only synced with the label at creation time.
		$inbox_id = (int) ( $account['crm_inbox_id'] ?? 0 );
		if ( $inbox_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_inboxes(), array( 'name' => 'Zalo Cá nhân — ' . $label ), array( 'id' => $inbox_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'id' => (string) ( $account['bridge_account_id'] ?? '' ), 'label' => $label ), 200 );
	}

	/**
	 * DELETE /zalo-bridge/members/{user_id}/accounts/{id} — "Gỡ số": Hub session torn down, status `revoked`,
	 * CRM inbox + conversations kept (same as CRM `mode=disconnect`). The number cannot be QR-relogged after this.
	 */
	public static function handle_member_delete_account( WP_REST_Request $request ) {
		$resolved = self::resolve_member_account( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		return self::delete_account_for_owner( $resolved['account'], $resolved['owner_id'], true );
	}

	/** POST /zalo-bridge/members/{user_id}/accounts/{id}/transfer — hand the number to another member of this blog. */
	public static function handle_member_transfer_account( WP_REST_Request $request ) {
		$resolved = self::resolve_member_account( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		$account = $resolved['account'];
		$body    = $request->get_json_params();
		$to_id   = max( 0, (int) ( is_array( $body ) ? ( $body['to_user_id'] ?? 0 ) : 0 ) );
		if ( $to_id <= 0 || $to_id === $resolved['owner_id'] || ! self::is_blog_member( $to_id ) ) {
			return self::member_error( 'invalid_param', 'Chọn thành viên nhận hợp lệ.', 'Chọn một thành viên khác của website này.' );
		}
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Channel Gateway chưa sẵn sàng.', 'hint' => 'Tải lại trang rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
		}
		// Grant first, mapping second (same order as CRM `transfer_phone_owner`): the mapping must never say
		// "owned by B" while the grant layer used by /gpt/ and Context Bank still says "A".
		$grant = BizCity_Channel_User_Grant::reassign_owner(
			'zalo_personal', (string) ( $account['bridge_account_id'] ?? '' ), $to_id, (int) get_current_user_id(), true,
			array( 'source' => 'gateway_member_transfer' )
		);
		if ( empty( $grant['ok'] ) ) {
			$reason = sanitize_key( (string) ( $grant['reason'] ?? 'grant_write_failed' ) );
			if ( 'personal_account_quota_reached' === $reason ) {
				return new WP_REST_Response( self::personal_quota_payload( (int) ( $grant['quota'] ?? 0 ) ), 200 );
			}
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'transfer_failed', 'message' => 'Không chuyển được quyền sở hữu SĐT.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'transfer_failed', 'reason' => $reason ), 200 );
		}
		BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $to_id,
			'label'             => (string) ( $account['label'] ?? '' ),
			'bridge_account_id' => (string) ( $account['bridge_account_id'] ?? '' ),
			'zalo_uid'          => (string) ( $account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => (string) ( $account['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => (int) ( $account['crm_inbox_id'] ?? 0 ),
			'status'            => (string) ( $account['status'] ?? 'pending_qr' ),
		) );
		return new WP_REST_Response( array( 'ok' => true, 'id' => (string) ( $account['bridge_account_id'] ?? '' ), 'from_user_id' => $resolved['owner_id'], 'to_user_id' => $to_id ), 200 );
	}

	/**
	 * Delete a Personal account after Twin GPT has resolved its tenant owner.
	 *
	 * @param bool $authorized_by_caller [2026-09-18 Johnny Chu - Chu Hoàng Anh]
	 *   PHASE-0.53 N5 (S5) — same bypass pattern as {@see start_qr_for_owner()}:
	 *   when true, the caller (`bizcity-twin-crm`'s staff REST) has already run
	 *   its own `Staff_Policy::can('phone.assign', ...)` check for a
	 *   supervisor/admin removing SOMEONE ELSE's phone, so the strict
	 *   "only the account's own owner" equality below is skipped. Default
	 *   `false` preserves the existing self-service `/gpt/` call site exactly.
	 */
	public static function delete_account_for_owner( array $account, int $owner_user_id, bool $authorized_by_caller = false ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1/R-TWEB-14 — retain owner scope through bridge deletion and local status persistence.
		if ( 'personal' !== (string) ( $account['kind'] ?? '' )
			|| ( ! $authorized_by_caller && ( $owner_user_id <= 0 || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) )
		) {
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		if ( $id === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'success' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy tài khoản Zalo.', 'hint' => 'Tải lại danh sách Kênh của tôi rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' ), 200 );
		}
		$result = BizCity_Zalo_Bridge_Client::instance()->delete_account( $id );
		$ok = empty( $result['_degraded'] ) && ! empty( $result['success'] );
		// [2026-09-27 Claude Sonnet 5] The Hub no longer knows this account (deleted server-side / API key changed → `account_not_owned`): the goal of
		// "gỡ số" — no live session at the Hub — is already true, so it must NOT fail. Before this, such a number could never be removed from the site.
		$already_gone = false;
		if ( ! $ok && class_exists( 'BizCity_Zalo_Session_Errors' ) ) {
			$bucket = BizCity_Zalo_Session_Errors::bucket_for( (string) ( $result['reason_bucket'] ?? '' ) );
			if ( '' === $bucket ) {
				$bucket = BizCity_Zalo_Session_Errors::bucket_for( (string) ( $result['code'] ?? '' ) );
			}
			if ( 'account_not_owned' === $bucket ) {
				$ok = true;
				$already_gone = true;
			}
		}
		if ( $ok && class_exists( 'BizCity_Zalo_Mapping_Repo' ) && ! empty( $account['id'] ) ) {
			// [2026-09-18] R-ZP-DUP DUP-11 — deleted ⇒ 'revoked' (history kept, no QR offer, not a twin).
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $account['id'], BizCity_Zalo_Duplicate_Guard::STATUS_REVOKED );
		}
		return new WP_REST_Response( array( 'ok' => $ok, 'success' => $ok, 'already_gone' => $already_gone, 'code' => $ok ? '' : (string) ( $result['code'] ?? 'zalo_bridge_unreachable' ), 'message' => $ok ? '' : (string) ( $result['message'] ?? 'Chưa ngắt được tài khoản Zalo.' ), 'hint' => $ok ? '' : (string) ( $result['hint'] ?? 'Kiểm tra trạng thái managed bridge rồi thử lại.' ), 'help_code' => $ok ? '' : (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ) ), 200 );
	}

	/**
	 * PHASE-0.53 N5 (S6/G6) — re-provision a Zalo Personal account whose Hub-side account is gone
	 * (`account_not_owned`), adopting the SAME CRM inbox instead of letting a fresh
	 * `create_account_for_owner()` spin up a second one (the "Trùng SĐT" split-history bug this
	 * flow exists to avoid).
	 *
	 * Marks `$old_account` `orphaned` first — R-ZP-DUP's duplicate guard excludes `orphaned` rows
	 * (`BizCity_Zalo_Duplicate_Guard::DEAD_STATUSES`), so the Hub create below is never blocked by
	 * the very row this call just retired. Then it deliberately skips
	 * `BizCity_CRM_Repository::upsert_inbox()` — that function looks up an existing inbox by the
	 * TUPLE `(channel_type, channel_ref_id)`, so calling it with the new bridge id would silently
	 * create a second inbox instead of adopting the old one. Both sides of the old inbox↔account
	 * link are re-pointed instead: the new account row's `crm_inbox_id` (fixes inbound routing —
	 * `BizCity_Zalo_Inbound_Emitter::emit()` reads this column) AND the inbox row's `channel_ref_id`
	 * (fixes OUTBOUND send — `BizCity_CRM_Adapter_ZaloPersonal::send()` resolves the live bridge
	 * account id from THIS column, not from the account row; leaving it on the dead bridge id would
	 * silently break replies even though inbound + rail both looked fixed).
	 *
	 * Known gap: quota is enforced by `bind_primary_for_owner()`/`personal_quota_status()` counting
	 * the owner's live grants, and there is no public API to clear a PRIMARY's own grant without
	 * transferring it to someone else (`BizCity_Channel_User_Grant::revoke()` explicitly refuses
	 * that — `primary_transfer_required`). An owner sitting exactly at quota can see
	 * `personal_account_quota_reached` here even though the account being replaced is dead. Left
	 * as-is: a "force-clear my own primary grant" capability is a bigger change than this recovery
	 * flow needs; §10.5 in the phase doc has more detail.
	 */
	public static function recover_account_for_owner( array $old_account, int $adopt_inbox_id, int $actor_user_id ): WP_REST_Response {
		if ( 'personal' !== (string) ( $old_account['kind'] ?? '' ) || $adopt_inbox_id <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Không xác định được SĐT cần khôi phục.', 'hint' => '', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$owner_user_id = (int) ( $old_account['owner_user_id'] ?? 0 );
		if ( $owner_user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy SĐT này.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$label = (string) ( $old_account['label'] ?? '' );
		if ( ! empty( $old_account['id'] ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $old_account['id'], 'orphaned' );
		}

		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->create_account( array( 'label' => $label, 'type' => 'personal' ) );
		if ( ! empty( $result['_degraded'] ) ) {
			return new WP_REST_Response( self::with_error_contract( array(
				'ok'        => false,
				'_degraded' => true,
				'code'      => (string) ( $result['code'] ?? $result['error'] ?? 'bridge_unavailable' ),
				'message'   => (string) ( $result['message'] ?? 'Không tạo lại được tài khoản Zalo.' ),
				'hint'      => (string) ( $result['hint'] ?? 'Kiểm tra trạng thái Managed 1API rồi thử lại.' ),
				'help_code' => (string) ( $result['help_code'] ?? 'zalo_bridge_unreachable' ),
			) ) );
		}
		$bridge_account = isset( $result['account'] ) && is_array( $result['account'] ) ? $result['account'] : $result;
		$bridge_id = (string) ( $bridge_account['id'] ?? '' );
		if ( $bridge_id === '' ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'zalo_bridge_bad_response', 'message' => 'Không tạo lại được tài khoản Zalo.', 'hint' => '', 'help_code' => 'zalo_bridge_bad_response' ), 200 );
		}
		BizCity_Zalo_Mapping_Repo::maybe_install();
		$local_id = BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $owner_user_id,
			'label'             => $label,
			'bridge_account_id' => $bridge_id,
			'zalo_uid'          => (string) ( $bridge_account['zaloUid'] ?? $bridge_account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => '',
			'crm_inbox_id'      => $adopt_inbox_id,
			'status'            => 'pending_qr',
		) );
		if ( $local_id <= 0 ) {
			$client->delete_account( $bridge_id );
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'mapping_insert_failed', 'message' => 'Không lưu được liên kết tài khoản Zalo.', 'hint' => '', 'help_code' => 'zalo_bridge_bad_response' ), 200 );
		}
		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_inboxes(), array( 'channel_ref_id' => $bridge_id ), array( 'id' => $adopt_inbox_id ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'crm_repository' );
		}
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			$client->delete_account( $bridge_id );
			BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'orphaned' );
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'channel_grant_unavailable', 'message' => 'Chưa khởi tạo được quyền sở hữu kênh Zalo.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 200 );
		}
		$grant = BizCity_Channel_User_Grant::bind_primary_for_owner( 'zalo_personal', $bridge_id, $owner_user_id, $actor_user_id, true, array( 'source' => 'crm_recover' ) );
		if ( empty( $grant['ok'] ) ) {
			$client->delete_account( $bridge_id );
			BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'orphaned' );
			$reason = sanitize_key( (string) ( $grant['reason'] ?? 'grant_write_failed' ) );
			if ( 'personal_account_quota_reached' === $reason ) {
				return new WP_REST_Response( self::personal_quota_payload( (int) ( $grant['quota'] ?? 0 ) ), 200 );
			}
			return new WP_REST_Response( array( 'ok' => false, 'code' => $reason, 'message' => 'Không thể cấp quyền sở hữu tài khoản Zalo này.', 'hint' => '', 'help_code' => 'permission_denied' ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'id' => $bridge_id, 'crm_inbox_id' => $adopt_inbox_id, 'owner_user_id' => $owner_user_id ), 200 );
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

	/**
	 * Start QR for an account already resolved inside the current tenant owner scope.
	 *
	 * @param bool $authorized_by_caller [2026-09-17 Johnny Chu - Chu Hoàng Anh]
	 *   PHASE-0.48F R-CRMF-2 — when true, the caller has already run its OWN
	 *   authorization (`BizCity_CRM_Staff_Policy::can('phone.qr', ...)`, a
	 *   supervisor/admin acting on behalf of a lower-rank team member) and this
	 *   method skips the strict "only the account's own owner" check below —
	 *   it still requires `kind === 'personal'`. Never set from a value a
	 *   browser request can influence; `bizcity-twin-crm`'s staff REST resolves
	 *   the account from `crm_inbox_id` and the policy check server-side
	 *   before calling this. Default `false` preserves every existing call
	 *   site's exact behavior (self-service `/gpt/`, R-TWEB-1).
	 */
	public static function start_qr_for_owner( array $account, int $owner_user_id, bool $authorized_by_caller = false ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1 — QR control receives the resolved owner row, not an ambient admin permission check.
		if ( 'personal' !== (string) ( $account['kind'] ?? '' )
			|| ( ! $authorized_by_caller && ( $owner_user_id <= 0 || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) )
		) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		return self::start_qr_response( $id );
	}

	/**
	 * Reset the sidecar session and start QR again for an account already
	 * resolved inside the current tenant owner scope — the `_for_owner`
	 * counterpart to `handle_reset_qr()`, mirroring `start_qr_for_owner()`.
	 * Never deletes the account, mapping or CRM Inbox history.
	 *
	 * @param bool $authorized_by_caller See {@see start_qr_for_owner()}.
	 */
	public static function reset_qr_for_owner( array $account, int $owner_user_id, bool $authorized_by_caller = false ): WP_REST_Response {
		if ( 'personal' !== (string) ( $account['kind'] ?? '' )
			|| ( ! $authorized_by_caller && ( $owner_user_id <= 0 || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) )
		) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		return self::reset_qr_response( $id );
	}

	/** Start QR through one normalized operation/result boundary. */
	private static function start_qr_response( string $account_id ): WP_REST_Response {
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — preserve QR operation correlation and normalize every managed/custom result before returning to the UI.
		$operation_id = 'qr_' . str_replace( '-', '', wp_generate_uuid4() );
		$request_id   = 'wp_' . str_replace( '-', '', wp_generate_uuid4() );
		$account_hash = $account_id !== '' ? substr( hash( 'sha256', $account_id ), 0, 16 ) : '';
		self::trace_qr_step( 'qr_operation_attempt', array( 'operation_id' => $operation_id, 'request_id' => $request_id, 'account_id_hash' => $account_hash, 'stage' => 'qr_generation' ) );
		$blocked = self::duplicate_login_block( $account_id, $operation_id, $request_id );
		if ( $blocked ) {
			return new WP_REST_Response( $blocked, 200 );
		}
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
			'upstream' => (string) ( $normalized['upstream_code'] ?? '' ),
		) );
		// [2026-09-28 Claude Opus 5.5] WP-14 W5 — QR outcome notification removed with the Notification Center.
		return new WP_REST_Response( $normalized, 200 );
	}

	/** Execute the explicit reset operation through the same normalized QR boundary. */
	private static function reset_qr_response( string $account_id ): WP_REST_Response {
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1C — reset session runtime first, then reuse the canonical QR response normalizer.
		$operation_id = 'qr_reset_' . str_replace( '-', '', wp_generate_uuid4() );
		$request_id   = 'wp_' . str_replace( '-', '', wp_generate_uuid4() );
		$account_hash = $account_id !== '' ? substr( hash( 'sha256', $account_id ), 0, 16 ) : '';
		self::trace_qr_step( 'qr_reset_attempt', array( 'operation_id' => $operation_id, 'request_id' => $request_id, 'account_id_hash' => $account_hash, 'stage' => 'session' ) );
		$blocked = self::duplicate_login_block( $account_id, $operation_id, $request_id );
		if ( $blocked ) {
			return new WP_REST_Response( $blocked, 200 );
		}
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
			'upstream' => (string) ( $normalized['upstream_code'] ?? '' ),
		) );
		// [2026-09-28 Claude Opus 5.5] WP-14 W5 — QR outcome notification removed with the Notification Center.
		return new WP_REST_Response( $normalized, 200 );
	}

	/**
	 * [2026-09-18] PHASE-0.48F U10 R-ZP-DUP — block a QR start/reset that would supersede another CONNECTED
	 * account holding the same Zalo login. Fail-open: when the account list cannot be read, never block.
	 */
	private static function duplicate_login_block( string $account_id, string $operation_id, string $request_id ): ?array {
		if ( $account_id === '' || ! class_exists( 'BizCity_Zalo_Duplicate_Guard' ) ) {
			return null;
		}
		try {
			$list = BizCity_Zalo_Bridge_Client::instance()->list_accounts();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! empty( $list['_degraded'] ) || empty( $list['accounts'] ) || ! is_array( $list['accounts'] ) ) {
			return null;
		}
		// The list is already in hand: refresh local zalo_uid so the CRM rail can label twins (DUP-10).
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'sync_zalo_uids' ) ) {
			BizCity_Zalo_Mapping_Repo::sync_zalo_uids( $list['accounts'] );
		}
		$sibling = BizCity_Zalo_Duplicate_Guard::find_connected_sibling( $account_id, $list['accounts'] );
		if ( ! $sibling ) {
			return null;
		}
		self::trace_qr_step( 'qr_duplicate_login_blocked', array(
			'operation_id'    => $operation_id,
			'request_id'      => $request_id,
			'account_id_hash' => substr( hash( 'sha256', $account_id ), 0, 16 ),
			'sibling_id_hash' => substr( hash( 'sha256', (string) ( $sibling['id'] ?? '' ) ), 0, 16 ),
		) );
		return self::with_error_contract( BizCity_Zalo_Duplicate_Guard::qr_blocked_payload( $sibling, $operation_id, $request_id ) );
	}

	/** [2026-09-18] R-ZP-ERR — add reason_bucket/action/contract from the published catalog to a failure payload. */
	private static function with_error_contract( array $payload ): array {
		return class_exists( 'BizCity_Zalo_Session_Errors' ) ? BizCity_Zalo_Session_Errors::enrich( $payload ) : $payload;
	}

	/**
	 * [2026-09-18] R-ZP-ERR — publish the session-state + error catalog so every client (B2 `/crm/`,
	 * C `/gpt/`, wp-admin, mobile) prints the same sentence and button for the same failure.
	 */
	public static function handle_error_catalog(): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Zalo_Session_Errors' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded' ), 200 );
		}
		return new WP_REST_Response( array_merge( array( 'ok' => true ), BizCity_Zalo_Session_Errors::catalog() ), 200 );
	}

	/**
	 * Normalize a QR result without exposing the upstream response body.
	 * [2026-09-18] R-ZP-ERR — every failure leaves through BizCity_Zalo_Session_Errors::enrich() so the client
	 * always gets `reason_bucket` + `message` + `hint` + `action` from the one published catalog.
	 */
	public static function normalize_qr_result( $result, string $operation_id, string $request_id ): array {
		$normalized = self::normalize_qr_result_inner( $result, $operation_id, $request_id );
		return class_exists( 'BizCity_Zalo_Session_Errors' ) ? BizCity_Zalo_Session_Errors::enrich( $normalized ) : $normalized;
	}

	private static function normalize_qr_result_inner( $result, string $operation_id, string $request_id ): array {
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
				// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — tell the UI this scan will move the account from another website to this one.
				'rebind_pending' => ! empty( $result['rebind_pending'] ),
			);
		}
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — cross-site denials need an actionable message instead of the generic QR failure.
		$site_reason = sanitize_key( (string) ( $result['reason_bucket'] ?? '' ) );
		if ( in_array( $site_reason, array( 'managed_account_other_site', 'key_domain_mismatch' ), true ) ) {
			return array(
				'ok' => false,
				'success' => false,
				'operation_status' => 'blocked',
				'code' => 'permission_denied',
				'message' => 'key_domain_mismatch' === $site_reason
					? 'Tài khoản Zalo này đang nhận tin tại website khác, và API key của website này không gắn đúng domain nên chưa thể chuyển về đây.'
					: 'Tài khoản Zalo này đang nhận tin tại website khác.',
				'hint' => 'key_domain_mismatch' === $site_reason
					? 'Gắn domain của website này cho API key (hoặc dùng API key riêng), rồi bấm Đăng nhập lại để quét QR.'
					: 'Bấm Đăng nhập lại và quét QR tại website này để chuyển tài khoản về đây.',
				'help_code' => 'permission_denied',
				'stage' => 'account_scope',
				'reason_bucket' => $site_reason,
				'operation_id' => sanitize_text_field( $operation_id ),
				'request_id' => sanitize_text_field( $request_id ),
			);
		}
		$stage = sanitize_key( (string) ( $result['stage'] ?? '' ) );
		$allowed_stages = array( 'account_scope', 'mapping', 'relay', 'session', 'qr_generation', 'payload', 'presentation' );
		if ( ! in_array( $stage, $allowed_stages, true ) ) {
			$stage = 'qr_generation';
		}
		// [2026-09-03 11:58 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — accept the sidecar's `error` field so already-connected sessions are not misclassified as empty QR responses.
		$reason = sanitize_key( (string) ( $result['reason_bucket'] ?? $result['code'] ?? $result['error'] ?? '' ) );
		// [2026-09-18] PHASE-0.48F U10 — expose the upstream code only when it is a KNOWN slug so support can tell causes
		// apart; anything else (free text from a provider) is reduced to an opaque hash for log correlation.
		$raw_upstream = sanitize_key( (string) ( $result['code'] ?? $result['error'] ?? $reason ) );
		$has_catalog  = class_exists( 'BizCity_Zalo_Session_Errors' );
		$known_codes  = array_merge( array( 'personal_accounts_only' ), $has_catalog ? BizCity_Zalo_Session_Errors::known_codes() : array() );
		$upstream_code = in_array( $raw_upstream, $known_codes, true )
			? $raw_upstream
			: ( $raw_upstream !== '' ? 'unrecognized:' . substr( hash( 'sha256', $raw_upstream ), 0, 8 ) : '' );
		// Buckets with their own branch (already_connected) or never produced by the upstream (site checks, empty payload).
		$own_branch = array( 'already_connected', 'qr_response_empty', 'duplicate_phone', 'duplicate_zalo_login', 'managed_account_other_site', 'key_domain_mismatch' );
		$bucket = $has_catalog ? BizCity_Zalo_Session_Errors::bucket_for( $reason ) : '';
		if ( $bucket === '' && $has_catalog ) {
			$bucket = BizCity_Zalo_Session_Errors::bucket_for( $raw_upstream );
		}
		if ( $bucket !== '' && ! in_array( $bucket, $own_branch, true ) ) {
			$entry = BizCity_Zalo_Session_Errors::ERRORS[ $bucket ];
			return array(
				'ok'               => false,
				'success'          => false,
				'_degraded'        => $entry['status'] === 'degraded',
				'operation_status' => $entry['status'],
				'code'             => 'qr_operation_failed',
				'message'          => $entry['message'],
				'hint'             => $entry['hint'],
				'help_code'        => 'zalo_qr_generation_failed',
				'stage'            => $stage,
				'reason_bucket'    => $bucket,
				'upstream_code'    => $upstream_code,
				'operation_id'     => sanitize_text_field( $operation_id ),
				'request_id'       => sanitize_text_field( $request_id ),
			);
		}
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
			'upstream_code' => $upstream_code,
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
		$id = (string) $request->get_param( 'id' );
		return new WP_REST_Response( self::sync_account_status( $id, (int) get_current_user_id() ) );
	}

	/**
	 * [2026-09-19 Johnny Chu] PHASE-0.60 — core "ask the Hub for this account's real status and
	 * mirror it locally" logic, extracted out of `handle_qr_status()` so the periodic reconciliation
	 * cron (`BizCity_Zalo_Personal_Reconciler::tick()`) can reuse the EXACT same detection (including
	 * the `managed_account_other_site` branch) instead of a second copy that could drift.
	 *
	 * Why this needed extracting: a real incident showed a phone re-scanned into a second site
	 * (same Hub API key) kept showing "Đang kết nối" on the FIRST site indefinitely — the cross-site
	 * webhook (`handle_session_event()`) is best-effort with no retry, and this status check
	 * otherwise only ran when a human happened to open that exact account's QR sheet. No behavior
	 * change for the existing REST caller; this is a pure extraction.
	 */
	public static function sync_account_status( string $id, int $owner_user_id = 0 ): array {
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $client->get_qr_status( $id );
		if ( ! empty( $result['_degraded'] ) ) {
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — the account now delivers to another website: mark it here so the UI offers re-login instead of showing it as connected.
			if ( 'managed_account_other_site' === sanitize_key( (string) ( $result['reason_bucket'] ?? '' ) ) ) {
				self::mark_moved_away( $id );
				return array( 'ok' => false, '_degraded' => true, 'status' => 'logged_out', 'reason_bucket' => 'managed_account_other_site', 'bound_site_host' => sanitize_text_field( (string) ( $result['bound_site_host'] ?? '' ) ), 'message' => 'Tài khoản Zalo đang nhận tin tại website khác.', 'hint' => 'Đăng nhập lại QR tại website này để chuyển tài khoản về đây.' );
			}
			return array( 'ok' => false, '_degraded' => true, 'message' => $result['message'] ?? '' );
		}
		if ( ! empty( $result['rebound'] ) ) {
			self::adopt_rebound_account( $id, $owner_user_id );
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
				if ( 'connected' === $status ) {
					self::sync_uids_on_connect( $local );
				}
			}
		}
		return array( 'ok' => true, 'status' => $status, 'success' => true, 'rebound' => ! empty( $result['rebound'] ), 'rebind_pending' => ! empty( $result['rebind_pending'] ) );
	}

	/**
	 * [2026-09-18] PHASE-0.48F U10 DUP-10 — on the connect transition only (the row was not yet connected
	 * or has no uid), read the bridge list once and copy zaloUid onto every local row. The sidecar
	 * superseded the twin during this same login, so this is exactly when the rail needs the uid.
	 * Steady-state polling of an already-connected row with a uid makes no extra call.
	 */
	private static function sync_uids_on_connect( array $local_row ): void {
		if ( (string) ( $local_row['status'] ?? '' ) === 'connected' && (string) ( $local_row['zalo_uid'] ?? '' ) !== '' ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! method_exists( 'BizCity_Zalo_Mapping_Repo', 'sync_zalo_uids' ) ) {
			return;
		}
		try {
			$list = BizCity_Zalo_Bridge_Client::instance()->list_accounts();
		} catch ( \Throwable $e ) {
			return;
		}
		if ( empty( $list['_degraded'] ) && ! empty( $list['accounts'] ) && is_array( $list['accounts'] ) ) {
			BizCity_Zalo_Mapping_Repo::sync_zalo_uids( $list['accounts'] );
		}
	}

	/** Bind an account that a QR login just moved to this website into the local mapping and CRM Inbox. */
	private static function adopt_rebound_account( string $bridge_id, int $owner_user_id ): void {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — inbound for this account now arrives here; without a local mapping the emitter would drop it as unbound.
		if ( $bridge_id === '' || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return;
		}
		BizCity_Zalo_Mapping_Repo::maybe_install();
		$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		if ( $local ) {
			$extra = array();
			if ( (int) ( $local['crm_inbox_id'] ?? 0 ) <= 0 ) {
				$label = (string) ( $local['label'] ?? '' );
				$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'zalo_personal', $bridge_id, array( 'name' => 'Zalo Cá nhân — ' . ( $label !== '' ? $label : $bridge_id ) ) );
				if ( $inbox_id > 0 ) {
					$extra['crm_inbox_id'] = (int) $inbox_id;
				}
			}
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $local['id'], 'connected', $extra );
			self::trace_create_step( 'rebind_adopted', array( 'local_id' => (int) $local['id'], 'created' => false ) );
			return;
		}
		if ( $owner_user_id <= 0 ) {
			self::trace_create_step( 'rebind_adopt_skipped', array( 'reason' => 'owner_missing' ) );
			return;
		}
		$remote = BizCity_Zalo_Bridge_Client::instance()->get_account( $bridge_id );
		$label = sanitize_text_field( (string) ( $remote['account']['label'] ?? '' ) );
		$local_id = BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => 'personal',
			'owner_user_id'     => $owner_user_id,
			'label'             => $label,
			'bridge_account_id' => $bridge_id,
			'zalo_uid'          => (string) ( $remote['account']['zaloUid'] ?? '' ),
			'crm_inbox_id'      => 0,
			'status'            => 'connected',
		) );
		if ( $local_id <= 0 ) {
			self::trace_create_step( 'rebind_adopt_failed', array( 'reason' => 'mapping_insert_failed' ) );
			return;
		}
		$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'zalo_personal', $bridge_id, array( 'name' => 'Zalo Cá nhân — ' . ( $label !== '' ? $label : $bridge_id ) ) );
		BizCity_Zalo_Mapping_Repo::update_account_status( $local_id, 'connected', $inbox_id > 0 ? array( 'crm_inbox_id' => (int) $inbox_id ) : array() );
		if ( class_exists( 'BizCity_Channel_User_Grant' ) ) {
			// Grant failure is non-fatal: the account already delivers here and the owner can be re-bound from Channel Gateway.
			$grant = BizCity_Channel_User_Grant::bind_primary_from_current( 'zalo_personal', $bridge_id, array(
				'connection_verified'      => true,
				'connection_owner_user_id' => $owner_user_id,
				'source'                   => 'managed_rebind',
			) );
			self::trace_create_step( 'rebind_grant', array( 'ok' => ! empty( $grant['ok'] ), 'reason' => sanitize_key( (string) ( $grant['reason'] ?? '' ) ) ) );
		}
		self::trace_create_step( 'rebind_adopted', array( 'local_id' => (int) $local_id, 'inbox_id' => (int) $inbox_id, 'created' => true ) );
	}

	/** Mark a local account whose inbound was moved to another website by a later QR login. */
	private static function mark_moved_away( string $bridge_id ): void {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — keep mapping, CRM Inbox and history; only the session state changes.
		if ( $bridge_id === '' || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return;
		}
		$local = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		if ( $local && (string) ( $local['status'] ?? '' ) !== 'logged_out' ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $local['id'], 'logged_out' );
			self::trace_create_step( 'account_moved_away', array( 'local_id' => (int) $local['id'] ) );
		}
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

	/**
	 * Poll QR status for an account already resolved inside the current tenant owner scope.
	 *
	 * @param bool $authorized_by_caller See {@see start_qr_for_owner()} — same
	 *   PHASE-0.48F R-CRMF-2 bypass for a supervisor/admin polling on behalf of
	 *   a lower-rank team member.
	 */
	public static function qr_status_for_owner( array $account, int $owner_user_id, bool $authorized_by_caller = false ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] R-TWEB-1 — status polling keeps the resolved owner row through local state mirroring.
		if ( 'personal' !== (string) ( $account['kind'] ?? '' )
			|| ( ! $authorized_by_caller && ( $owner_user_id <= 0 || (int) ( $account['owner_user_id'] ?? 0 ) !== $owner_user_id ) )
		) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Tài khoản Zalo này không thuộc tài khoản của bạn.', 'hint' => 'Chọn tài khoản Zalo Personal trong Kênh của tôi.', 'help_code' => 'permission_denied' ), 200 );
		}
		$id = (string) ( $account['bridge_account_id'] ?? '' );
		$client = BizCity_Zalo_Bridge_Client::instance();
		$result = $id !== '' ? $client->get_qr_status( $id ) : array( 'success' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy tài khoản Zalo.', 'hint' => 'Tải lại danh sách Kênh của tôi rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' );
		if ( ! empty( $result['_degraded'] ) || empty( $result['success'] ) ) {
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48E-E5 — an account moved to another website must surface as re-loginable, not as a bridge outage.
			if ( 'managed_account_other_site' === sanitize_key( (string) ( $result['reason_bucket'] ?? '' ) ) ) {
				self::mark_moved_away( $id );
				return new WP_REST_Response( array( 'ok' => false, 'status' => 'logged_out', 'qr_status' => 'logged_out', 'can_relogin' => true, 'reason_bucket' => 'managed_account_other_site', 'bound_site_host' => sanitize_text_field( (string) ( $result['bound_site_host'] ?? '' ) ), 'message' => 'Tài khoản Zalo đang nhận tin tại website khác.', 'hint' => 'Đăng nhập lại QR tại đây để chuyển tài khoản về website này.', 'readiness' => self::readiness_envelope( $account, array( 'status' => 'logged_out' ), $client ) ), 200 );
			}
			// [2026-09-27 Claude Sonnet 5] The envelope was merged at the TOP level here but nested under `readiness` on success, so a failed status (e.g. `account_not_owned`) left every consumer (CRM ping) reading an empty `readiness` and printing unknown/down with no reason.
				$envelope = self::readiness_envelope( $account, $result, $client );
				return new WP_REST_Response( array_merge( array( 'ok' => false ), $result, $envelope, array( 'readiness' => $envelope ) ), 200 );
		}
		if ( ! empty( $result['rebound'] ) ) {
			self::adopt_rebound_account( $id, $owner_user_id );
		}
		$status = (string) ( $result['status'] ?? 'pending_qr' );
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && ! empty( $account['id'] ) && in_array( $status, array( 'connected', 'expired', 'logged_out' ), true ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $account['id'], $status );
			if ( 'connected' === $status ) {
				self::sync_uids_on_connect( $account );
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => $status, 'success' => true, 'qr_status' => $status, 'can_relogin' => in_array( $status, array( 'expired', 'logged_out', 'revoked' ), true ), 'readiness' => self::readiness_envelope( $account, $result, $client ) ), 200 );
	}

	/** Build a redacted readiness envelope without treating account mapping as bridge health. */
	private static function readiness_envelope( array $account, array $qr_result, BizCity_Zalo_Bridge_Client $client ): array {
		// [2026-09-09 10:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W4.8 — expose mapping, bridge, session, queue and callback evidence as separate states.
		$health = method_exists( $client, 'health' ) ? $client->health() : array( 'success' => false, '_degraded' => true, 'code' => 'health_method_missing' );
		$health_ok = ( ! empty( $health['success'] ) || ! empty( $health['ok'] ) ) && empty( $health['_degraded'] ) && empty( $health['degraded'] );
		$callback_ts = 0;
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			foreach ( BizCity_Zalo_Hook_Log::read( 100 ) as $row ) {
				if ( ! is_array( $row ) || (string) ( $row['dir'] ?? '' ) !== 'inbound' ) { continue; }
				if ( (string) ( $row['account_id'] ?? '' ) !== (string) ( $account['bridge_account_id'] ?? '' ) ) { continue; }
				$callback_ts = max( $callback_ts, (int) ( $row['ts'] ?? 0 ) );
			}
		}
		$queue_status = sanitize_key( (string) ( $qr_result['queue_status'] ?? $qr_result['queue']['status'] ?? '' ) );
		if ( ! in_array( $queue_status, array( 'healthy', 'ready', 'queued', 'stalled', 'offline', 'unknown' ), true ) ) { $queue_status = 'unknown'; }
		// [2026-09-17 09:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39C-C8 Task 4a — the sidecar /wp/accounts/:id/qr-status returns `session_live` (real in-memory RAM state) alongside `status` (DB state). They can disagree: after a deferred boot-time restore (Task 1) the DB still says `connected` while no live session exists. Collapsing them into `status` alone made the UI tell the user to re-scan a QR that may not be needed.
		$session_live = array_key_exists( 'session_live', $qr_result ) ? (bool) $qr_result['session_live'] : null;
		$db_status = sanitize_key( (string) ( $qr_result['status'] ?? 'unknown' ) );
		$session_status = sanitize_key( (string) ( $qr_result['session_status'] ?? $db_status ) );
		if ( null !== $session_live && ! $session_live && 'connected' === $db_status ) {
			$session_status = 'session_disconnected';
		}
		// [2026-09-27 Claude Sonnet 5] Which branch this number runs on — recorded at creation (Account_Flags), so it can be told apart on screen.
		$provider = class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::provider( (string) ( $account['bridge_account_id'] ?? '' ) ) : 'zca';
		// [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 UX-1 — a zalo-hub number's "bridge" is its cell, not the zca sidecar that $client->health() probes.
		if ( 'zalo_hub' === $provider && class_exists( 'BizCity_Zalo_Connection_Status' ) ) {
			$backend = BizCity_Zalo_Connection_Status::account_backend( (string) ( $account['bridge_account_id'] ?? '' ) );
			if ( null !== $backend ) {
				$health_ok = $backend['ok'];
				$health = array( 'code' => $backend['code'], '_degraded' => ! $backend['ok'] );
			}
		}
		return array(
			'readiness_version' => '1.0.0',
			'provider' => $provider,
			'provider_label' => 'zalo_hub' === $provider ? 'zalo-hub (BizCity Hub)' : 'zca-bridge (legacy)',
			'checked_at' => gmdate( 'c' ),
			'account_mapping' => array(
				'status' => ! empty( $account['bridge_account_id'] ) && (int) ( $account['owner_user_id'] ?? 0 ) > 0 ? 'mapped' : 'missing',
				'account_key' => substr( hash( 'sha256', (string) ( $account['bridge_account_id'] ?? '' ) ), 0, 16 ),
			),
			'bridge_health' => array(
				'status' => $health_ok ? 'healthy' : ( ! empty( $health['_degraded'] ) ? 'degraded' : 'unavailable' ),
				'code' => sanitize_key( (string) ( $health['code'] ?? '' ) ),
			),
			'session_status' => $session_status,
			'session_live' => $session_live,
			'queue_status' => $queue_status,
			'last_callback_at' => $callback_ts > 0 ? gmdate( 'c', $callback_ts ) : null,
			'callback_observed' => $callback_ts > 0,
		);
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
