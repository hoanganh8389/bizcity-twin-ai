<?php
/**
 * Bizcity Twin AI — TwinChat REST Controller
 *
 * Routes registered on namespace `bizcity-twinchat/v1`:
 *   POST /chat/(?P<notebook_id>\d+)/stream  → SSE pipeline
 *   GET  /sessions/(?P<notebook_id>\d+)     → list sessions
 *   GET  /messages/(?P<session_id>[A-Za-z0-9\-]+) → session history
 *   GET  /stats/(?P<notebook_id>\d+)        → KG-Hub stats summary
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_REST_Controller {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = BIZCITY_TWINCHAT_REST_NS;

		register_rest_route( $ns, '/chat/(?P<notebook_id>\d+)/stream', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_stream' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/sessions/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_sessions' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/messages/(?P<session_id>[A-Za-z0-9\-_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_messages' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/stats/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// Sprint 0.6.9 — standalone passage search (no agent loop, no answer LLM).
		register_rest_route( $ns, '/search/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'q'           => [ 'type' => 'string',  'required' => true ],
				'top_k'       => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );

		// [2026-07-14 Johnny Chu] PHASE-0.43 — lexical document search endpoint (notebook/all notebooks).
		register_rest_route( $ns, '/search-documents', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_search_documents' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'q'           => [ 'type' => 'string',  'required' => true ],
				'scope'       => [ 'type' => 'string',  'default' => 'notebook' ],
				'notebook_id' => [ 'type' => 'integer', 'required' => false ],
				'page'        => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'    => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );

		// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — drill-down: every matching passage excerpt for one search-documents doc_key.
		register_rest_route( $ns, '/search-documents/matches', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_search_document_matches' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'q'              => [ 'type' => 'string',  'required' => true ],
				'doc_key'        => [ 'type' => 'string',  'required' => true ],
				'notebook_id'    => [ 'type' => 'integer', 'required' => true ],
				'character_uuid' => [ 'type' => 'string',  'required' => false ],
				'page'           => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'       => [ 'type' => 'integer', 'default' => 30 ],
			],
		] );

		// 2026-05-21 — API key health probe used by the React SetupApiKeyDialog
		// (R-LEARN §6 E10 — "api key missing/invalid" surface).
		//   GET  /api-key/status    → cached snapshot from get_api_key_status()
		//   POST /api-key/test      → live ping to bizcity.vn /account/info
		register_rest_route( $ns, '/api-key/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_api_key_status' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
		register_rest_route( $ns, '/api-key/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_api_key_test' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// ── Direct Sources routes (no KG-Hub dependency) ──────────────────────
		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_sources' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'limit'       => [ 'type' => 'integer', 'default' => 200 ],
				'search'      => [ 'type' => 'string',  'default' => '' ],
			],
		] );

		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)/(?P<source_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
		] );

		// [2026-07-23 Johnny Chu] PHASE-0.44 — source-scoped learning evidence for drawer tab.
		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)/(?P<source_id>\d+)/learning-log', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_source_learning_log' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id'    => [ 'type' => 'integer', 'required' => true ],
				'source_id'      => [ 'type' => 'integer', 'required' => true ],
				'limit'          => [ 'type' => 'integer', 'default' => 120 ],
				'include_chunks' => [ 'type' => 'integer', 'default' => 1 ],
				'chunk_limit'    => [ 'type' => 'integer', 'default' => 120 ],
			],
		] );

		// [2026-07-25 Johnny Chu] HOTFIX async-retry — manual one-click retry for sources stuck 'failed' after async ingest exhausted attempts.
		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)/(?P<source_id>\d+)/retry-ingest', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'retry_source_ingest' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'source_id'   => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK — mint a
		// public no-login link to this source's learning console log (Source
		// drawer "Learning Log" tab → Share button).
		register_rest_route( $ns, '/sources/(?P<notebook_id>\d+)/(?P<source_id>\d+)/share-link', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_source_share_link' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'source_id'   => [ 'type' => 'integer', 'required' => true ],
				'ttl_days'    => [ 'type' => 'integer', 'default' => 30 ],
			],
		] );

		// Delete legacy passages by origin string (for notebooks predating bizcity_twinchat_sources)
		// Sprint 5.0d — FE→BE event dispatch (whitelisted user-action types only).
		// All other event types must be emitted server-side via Event_Bus::dispatch_v2().
		register_rest_route( $ns, '/events/dispatch', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_dispatch_event' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.3 — same-origin test/UI entry
		// point into the canonical channel capture bridge. Lets "New Note" style
		// UI (and manual smoke-testing) exercise the exact same
		// BizCity_KG_Channel_Notebook_Bridge::capture() path a Zalo "@notebook"
		// message goes through, without needing a live channel webhook.
		register_rest_route( $ns, '/notebooks/quick-capture', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_quick_capture' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'title'   => [ 'type' => 'string', 'default' => '' ],
				'content' => [ 'type' => 'string', 'required' => true ],
				'day_key' => [ 'type' => 'string', 'default' => '' ],
			],
		] );

		// Wave 0.18.3 — Notebook persona context (character + provider chips).
		register_rest_route( $ns, '/notebooks/(?P<notebook_id>\d+)/persona-context', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_persona_context' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// Wave 0.18.5c — Twin Guru picker (composer @-mention) + sticky persistence.
		// (1) Catalog of available Gurus for the current user.
		register_rest_route( $ns, '/gurus/list', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_gurus' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
		// PHASE 0.31 T-S3.2 — Per-passage actions (Tag note + Trigger workflow).
		// Backed by `BizCity_KG_Source_Service::tag_passage()` which fires
		// `bizcity_twin_notebook_event('note_tagged', ...)` so workflow trigger
		// `nb_note_tagged` reacts. "Trigger workflow" is implemented as a
		// dedicated reserved tag (default `#trigger`) to reuse the same pipeline.
		register_rest_route( $ns, '/passages/(?P<passage_id>\d+)/tag', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tag_passage' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'passage_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
		register_rest_route( $ns, '/passages/(?P<passage_id>\d+)/trigger-workflow', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'trigger_workflow_for_passage' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'passage_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// (2)(3)(4) Per-(user, notebook) sticky Guru — saved in user_meta.
		register_rest_route( $ns, '/notebooks/(?P<notebook_id>\d+)/sticky-guru', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'clear_sticky_guru' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			],
		] );
	}

	/* ── Permission ────────────────────────────────────────────────────── */

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	/* ── Notebook quick-capture (PHASE-0.46 W2 S2.3) ───────────────────── */

	/**
	 * POST /bizcity-twinchat/v1/notebooks/quick-capture
	 *
	 * Same-origin test/UI entry point that calls
	 * BizCity_KG_Channel_Notebook_Bridge::capture() with channel='twinchat'
	 * for the CURRENT logged-in user only (never trusts a client-supplied
	 * user_id). No `inbound{}` block is passed — there is no external chat
	 * to reply to, the caller already sees the result in the response body.
	 */
	public function handle_quick_capture( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			return new WP_Error( 'service_unavailable', 'Notebook capture bridge chưa sẵn sàng trên site này.', [ 'status' => 503 ] );
		}

		$content = trim( (string) $request->get_param( 'content' ) );
		if ( $content === '' ) {
			return new WP_Error( 'invalid_param', 'Thiếu nội dung để lưu vào ghi chú.', [ 'status' => 400 ] );
		}

		$envelope = [
			'user_id'    => get_current_user_id(),
			'channel'    => 'twinchat',
			'chat_id'    => 'twinchat_' . get_current_user_id(),
			'chat_kind'  => 'private',
			'title_hint' => (string) $request->get_param( 'title' ),
			'day_key'    => (string) $request->get_param( 'day_key' ),
			'kind'       => 'text',
			'content'    => $content,
		];

		$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture( $envelope );
		if ( is_wp_error( $res ) ) {
			$data   = $res->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( class_exists( 'BizCity_Error_Payload' )
				? BizCity_Error_Payload::from_wp_error( $res, 'Kiểm tra nội dung/tệp rồi thử lại.' )
				: [ 'success' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message() ], $status );
		}

		return rest_ensure_response( array_merge( [ 'success' => true ], $res ) );
	}

	/* ── API key health (R-LEARN §6 E10) ───────────────────────────────── */

	/**
	 * GET /bizcity-twinchat/v1/api-key/status
	 * Returns the cached snapshot from the public-page helper.
	 */
	public function handle_api_key_status() {
		if ( ! class_exists( 'BizCity_TwinChat_Public_Page' ) ) {
			return new WP_Error( 'public_page_missing', 'Public page class not loaded.', [ 'status' => 500 ] );
		}
		return rest_ensure_response( BizCity_TwinChat_Public_Page::get_api_key_status() );
	}

	/**
	 * POST /bizcity-twinchat/v1/api-key/test
	 * Live-pings the gateway `/bizcity/v1/account/info` endpoint with the
	 * currently configured key. Persists the result in `bizcity_llm_last_test`
	 * so `handle_api_key_status` reflects it next call.
	 */
	public function handle_api_key_test() {
		// [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — read normalized
		// key via canonical LLM client to avoid raw `biz_` / pasted Bearer formats.
		$key = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$key = BizCity_LLM_Client::instance()->get_api_key();
		}
		if ( $key === '' ) {
			// [2026-06-10 Johnny Chu] HOTFIX — per-site option
			$key = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
		}
		if ( $key === '' ) {
			return new WP_Error(
				'bizcity_api_key_missing',
				'Chưa có API key trong cấu hình site này.',
				[
					'status'       => 412,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		// [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — keep gateway source
		// aligned with canonical client wrapper used by other TwinChat paths.
		$gateway = class_exists( 'BizCity_LLM_Client' )
			? BizCity_LLM_Client::instance()->get_gateway_url()
			: (string) get_option( 'bizcity_llm_gateway_url', '' );
		if ( $gateway === '' ) {
			$gateway = 'https://bizcity.vn';
		}
		$url = trailingslashit( $gateway ) . 'wp-json/bizcity/v1/account/info';

		$started_at = microtime( true );
		$resp       = wp_remote_get( $url, [
			'timeout'     => 8,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => [
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			],
		] );
		$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			// [2026-06-11 Johnny Chu] HOTFIX — per-site option (multisite: mỗi site lưu riêng)
			update_option( 'bizcity_llm_last_test', [
				'ok'      => false,
				'ts'      => time(),
				'ms'      => $elapsed_ms,
				'code'    => 'network_error',
				'message' => $resp->get_error_message(),
			] );
			return new WP_Error(
				'bizcity_api_key_test_failed',
				sprintf( 'Không gọi được gateway %s: %s', $gateway, $resp->get_error_message() ),
				[
					'status'       => 502,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $resp );
		$body  = (string) wp_remote_retrieve_body( $resp );
		$json  = json_decode( $body, true );
		$ok    = ( $code === 200 );

		// [2026-06-11 Johnny Chu] HOTFIX — per-site option (multisite: mỗi site lưu riêng)
		update_option( 'bizcity_llm_last_test', [
			'ok'      => $ok,
			'ts'      => time(),
			'ms'      => $elapsed_ms,
			'code'    => 'http_' . $code,
			'message' => is_array( $json ) ? '' : substr( $body, 0, 200 ),
		] );

		if ( ! $ok ) {
			return new WP_Error(
				'bizcity_api_key_invalid',
				sprintf( 'Gateway từ chối key (HTTP %d). Có thể key sai/đã thu hồi, hoặc gateway URL không đúng.', $code ),
				[
					'status'       => $code === 401 || $code === 403 ? 401 : 502,
					'gateway_url'  => $gateway,
					'http_status'  => $code,
					'settings_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
				]
			);
		}

		return rest_ensure_response( [
			'ok'           => true,
			'http_status'  => $code,
			'elapsed_ms'   => $elapsed_ms,
			'gateway_url'  => $gateway,
			'account'      => is_array( $json ) ? $json : null,
			'status'       => BizCity_TwinChat_Public_Page::get_api_key_status(),
		] );
	}

	/**
	 * Verify current user owns (or can access) the given notebook.
	 * Returns true on success, WP_Error(403/404) on failure.
	 */
	private function check_notebook_access( int $notebook_id ) {
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'Invalid notebook_id.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) return true; // graceful degrade
		$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
		if ( ! $nb ) {
			return new WP_Error( 'not_found', 'Notebook not found.', [ 'status' => 404 ] );
		}
		$owner = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Notebook not accessible.', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ── Handlers ──────────────────────────────────────────────────────── */

	public function handle_stream( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$args = [
			'notebook_id'     => $notebook_id,
			'session_id'      => isset( $body['session_id'] ) ? (string) $body['session_id'] : '',
			'user_message'    => isset( $body['message'] ) ? (string) $body['message'] : '',
			'history'         => isset( $body['history'] ) && is_array( $body['history'] ) ? $body['history'] : [],
			'source_ids'      => isset( $body['source_ids'] ) && is_array( $body['source_ids'] ) ? $body['source_ids'] : [],
			'use_kg'          => isset( $body['use_kg'] ) ? (bool) $body['use_kg'] : true,
			'enable_thinking' => isset( $body['enable_thinking'] ) ? (bool) $body['enable_thinking'] : false,
		];

		// Wave 0.18.5c — Twin Guru @-mention from composer.
		// Shape: { character_id?, provider_id?, character_slug?, character_name?, avatar_url? }
		// Source: 'mention' = user picked via @, 'pinned' = sticky restored on mount.
		if ( isset( $body['target_guru'] ) && is_array( $body['target_guru'] ) ) {
			// [2026-07-05 Johnny Chu] HOTFIX — allow provider-first guru payloads without character_id.
			$tg_cid = (int) ( $body['target_guru']['character_id'] ?? 0 );
			$tg_provider_id = isset( $body['target_guru']['provider_id'] )
				? sanitize_key( (string) $body['target_guru']['provider_id'] )
				: '';
			if ( $tg_cid > 0 || $tg_provider_id !== '' ) {
				$args['target_guru'] = [
					'character_id'   => $tg_cid,
					'provider_id'    => $tg_provider_id,
					'character_slug' => isset( $body['target_guru']['character_slug'] ) ? (string) $body['target_guru']['character_slug'] : '',
					'character_name' => isset( $body['target_guru']['character_name'] ) ? (string) $body['target_guru']['character_name'] : '',
					'provider_label' => isset( $body['target_guru']['provider_label'] ) ? sanitize_text_field( (string) $body['target_guru']['provider_label'] ) : '',
					'avatar_url'     => isset( $body['target_guru']['avatar_url'] ) ? esc_url_raw( (string) $body['target_guru']['avatar_url'] ) : '',
					'sticky_source'  => isset( $body['target_guru']['sticky_source'] ) && in_array( $body['target_guru']['sticky_source'], [ 'mention', 'pinned' ], true )
						? (string) $body['target_guru']['sticky_source']
						: 'mention',
				];
			}
		}

		// Trim user message guard.
		$args['user_message'] = trim( $args['user_message'] );
		if ( $args['user_message'] === '' ) {
			return new WP_Error( 'empty_message', 'message is required', [ 'status' => 400 ] );
		}

		// Hand off to the SSE handler — it will write directly + exit.
		BizCity_TwinChat_Stream_Handler::instance()->handle( $args );
		// Stop WP from appending JSON envelope.
		exit;
	}

	public function list_sessions( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$rows = BizCity_TwinChat_Database::instance()->list_sessions( $notebook_id, 50 );
		return rest_ensure_response( [
			'ok'   => true,
			'data' => $rows,
		] );
	}

	public function get_messages( WP_REST_Request $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		$rows = BizCity_TwinChat_Database::instance()->get_session_messages( $session_id, 200 );
		return rest_ensure_response( [
			'ok'   => true,
			'data' => $rows,
		] );
	}

	public function get_stats( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$entities = 0;
		$relations = 0;
		if ( class_exists( 'BizCity_KG_Graph_Service' ) ) {
			$svc = BizCity_KG_Graph_Service::instance();
			if ( method_exists( $svc, 'get_full_graph' ) ) {
				$g = $svc->get_full_graph( $notebook_id, 1000 );
				if ( is_array( $g ) ) {
					$entities  = isset( $g['nodes'] ) ? count( $g['nodes'] ) : 0;
					$relations = isset( $g['links'] ) ? count( $g['links'] ) : 0;
				}
			}
		}

		// 4.10.4 — analytics breakdown (best-effort; tolerate missing tables).
		$type_distribution = [];
		$top_entities      = [];
		$passages_total    = 0;
		$embedding_coverage = 0.0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$db  = BizCity_KG_Database::instance();
			$te  = $db->tbl_entities();
			$tr  = method_exists( $db, 'tbl_relations' )       ? $db->tbl_relations()       : '';
			$tp  = method_exists( $db, 'tbl_passages' )        ? $db->tbl_passages()        : '';
			$tpe = method_exists( $db, 'tbl_passage_entities' )? $db->tbl_passage_entities(): '';

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT type, COUNT(*) AS cnt FROM {$te} WHERE notebook_id = %d GROUP BY type ORDER BY cnt DESC",
				$notebook_id
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$type_distribution[] = [
					'type'  => (string) ( $r['type'] ?? 'Other' ),
					'count' => (int) $r['cnt'],
				];
			}

			if ( $tr ) {
				// 4.10.4 hotfix — single-pass aggregate (O(n) over relations) instead of
				// per-entity correlated subquery. Counts each relation twice (head + tail)
				// via UNION ALL, groups, joins to entity name/type, returns top 10.
				$top = $wpdb->get_results( $wpdb->prepare(
					"SELECT e.id AS entity_id, e.name, e.type, agg.rel_count
					   FROM (
					     SELECT entity_id, COUNT(*) AS rel_count FROM (
					       SELECT head_entity_id AS entity_id FROM {$tr} WHERE notebook_id = %d
					       UNION ALL
					       SELECT tail_entity_id AS entity_id FROM {$tr} WHERE notebook_id = %d
					     ) ee
					     GROUP BY entity_id
					   ) agg
					   INNER JOIN {$te} e ON e.id = agg.entity_id AND e.notebook_id = %d
					   ORDER BY agg.rel_count DESC, e.name ASC
					   LIMIT 10",
					$notebook_id, $notebook_id, $notebook_id
				), ARRAY_A );
				foreach ( (array) $top as $r ) {
					$top_entities[] = [
						'entity_id' => (int) $r['entity_id'],
						'name'      => (string) $r['name'],
						'type'      => (string) ( $r['type'] ?? '' ),
						'count'     => (int) $r['rel_count'],
					];
				}
			}

			if ( $tp ) {
				$passages_total = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tp} WHERE notebook_id = %d", $notebook_id
				) );
				$with_embedding = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tp} WHERE notebook_id = %d AND embedding IS NOT NULL AND CHAR_LENGTH(embedding) > 0", $notebook_id
				) );
				if ( $passages_total > 0 ) {
					$embedding_coverage = round( ( $with_embedding / $passages_total ) * 100, 1 );
				}
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'notebook_id'        => $notebook_id,
				'entities'           => $entities,
				'relations'          => $relations,
				'passages'           => $passages_total,
				'embedding_coverage' => $embedding_coverage,
				'type_distribution'  => $type_distribution,
				'top_entities'       => $top_entities,
			],
		] );
	}

	/* ── Direct Sources handlers ────────────────────────────────────────── */

	/**
	 * Sprint 0.6.9 — Standalone Search.
	 * Thin wrapper around BizCity_KG_Retriever::search() — vector search over
	 * passages of one notebook, no agent loop, no answer generation.
	 */
	public function handle_search( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$q     = trim( (string) $request->get_param( 'q' ) );
		$top_k = (int) $request->get_param( 'top_k' );
		if ( $q === '' ) {
			return new WP_Error( 'empty_query', 'q is required.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			return new WP_Error( 'unavailable', 'Retriever not loaded.', [ 'status' => 503 ] );
		}
		$out = BizCity_KG_Retriever::instance()->search( $notebook_id, $q, $top_k );
		return rest_ensure_response( [
			'ok'    => true,
			'query' => $q,
			'data'  => $out,
		] );
	}

	/**
	 * PHASE-0.43 — keyword document search across one notebook or all notebooks.
	 */
	public function handle_search_documents( WP_REST_Request $request ) {
		// [2026-07-14 Johnny Chu] PHASE-0.43 — parse and validate lexical search params.
		$q           = trim( (string) $request->get_param( 'q' ) );
		$scope       = sanitize_key( (string) ( $request->get_param( 'scope' ) ?: 'notebook' ) );
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$page        = max( 1, (int) $request->get_param( 'page' ) );
		$per_page    = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		if ( $q === '' ) {
			return new WP_Error( 'empty_query', 'q is required.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $scope, [ 'notebook', 'all' ], true ) ) {
			$scope = 'notebook';
		}

		if ( $scope === 'notebook' ) {
			if ( $notebook_id <= 0 ) {
				return new WP_Error( 'invalid_notebook', 'notebook_id is required for notebook scope.', [ 'status' => 400 ] );
			}
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
		}

		if ( ! class_exists( 'BizCity_TwinChat_Search_Engine' ) ) {
			return new WP_Error( 'search_engine_missing', 'TwinChat search engine not loaded.', [ 'status' => 503 ] );
		}

		$data = BizCity_TwinChat_Search_Engine::instance()->search_documents( [
			'user_id'     => (int) get_current_user_id(),
			'scope'       => $scope,
			'notebook_id' => $notebook_id,
			'query'       => $q,
			'page'        => $page,
			'per_page'    => $per_page,
		] );

		return rest_ensure_response( [
			'ok'    => true,
			'query' => $q,
			'data'  => $data,
		] );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — drill-down: list every
	 * matching passage excerpt for a single search-documents() doc_key (the "N matches"
	 * badge). notebook_id is required so we can reuse check_notebook_access() for auth
	 * even when the original search ran with scope=all.
	 */
	public function handle_search_document_matches( WP_REST_Request $request ) {
		$q              = trim( (string) $request->get_param( 'q' ) );
		$doc_key        = trim( (string) $request->get_param( 'doc_key' ) );
		$notebook_id    = (int) $request->get_param( 'notebook_id' );
		$character_uuid = strtolower( trim( (string) $request->get_param( 'character_uuid' ) ) );
		$page           = max( 1, (int) $request->get_param( 'page' ) );
		$per_page       = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		if ( $q === '' || $doc_key === '' ) {
			return new WP_Error( 'invalid_param', 'q and doc_key are required.', [ 'status' => 400 ] );
		}
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id is required.', [ 'status' => 400 ] );
		}
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! class_exists( 'BizCity_TwinChat_Search_Engine' ) ) {
			return new WP_Error( 'search_engine_missing', 'TwinChat search engine not loaded.', [ 'status' => 503 ] );
		}

		$data = BizCity_TwinChat_Search_Engine::instance()->search_document_matches( [
			'query'          => $q,
			'doc_key'        => $doc_key,
			'notebook_id'    => $notebook_id,
			'character_uuid' => $character_uuid,
			'page'           => $page,
			'per_page'       => $per_page,
		] );

		return rest_ensure_response( [
			'ok'   => true,
			'data' => $data,
		] );
	}


	public function list_sources( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$args        = [
			'limit'  => (int) $request->get_param( 'limit' ),
			'search' => (string) $request->get_param( 'search' ),
		];
		try {
			// Phase 0.6 — Read switch: query bizcity_kg_sources when flag is on.
			// Default now honors the central `bizcity_kg_unified_read_enabled` option so a
			// single toggle flips reads for both the facade-level and TwinChat-direct paths.
			$read_switch_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $read_switch_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				$rows = self::_list_kg_sources( $notebook_id, $args );
				return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
			}
			// Ensure the sources table exists before querying (may not be installed yet on server).
			// Cached via option `bizcity_known_tables` — chỉ hit DB 1 lần / blog.
			$db  = BizCity_TwinChat_Sources_Database::instance();
			$tbl = $db->table_sources();
			$exists = function_exists( 'bizcity_table_exists' ) ? bizcity_table_exists( $tbl ) : ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			if ( ! $exists ) {
				// Table not yet installed — trigger install and return empty for now.
				$db->maybe_install();
				return rest_ensure_response( [ 'ok' => true, 'data' => [] ] );
			}
			$rows = BizCity_TwinChat_Sources_Service::instance()->list_sources( $notebook_id, $args );
			$rows = is_array( $rows ) ? $rows : [];

			// Phase 0.13 — enrich each row with KG triplet-extraction stats so the
			// FE can light up the 🧠 "learned" brain badge per source.
			//
			// Wave 10d.5c BUGFIX (2026-05-02) — dual-id lookup, mirrors the fix
			// already in `_list_kg_sources()` for the read-switch path.
			// Symptom: every source shows 0% even though Twin_Context_Resolver
			// returns passages + citations for the same notebook (PHASE-0.13
			// "Per-Source Learning Progress" doc, root cause #1). Reason:
			// `kg_passages.source_id` for these notebooks holds the CANONICAL
			// `kg_sources.id` (e.g. 200) while this legacy path returns rows with
			// `bizcity_webchat_sources.id` (1..N local ids). Aggregating only on
			// the local id returned zero, so the brain badge never lit up and
			// the sweep cron kept re-enqueuing "learning" jobs (root cause #2,
			// loop). Fix: also probe by `origin_id` from the mirrored kg_sources
			// row (one extra small SELECT keyed by origin_table) and bucket the
			// totals back to the local row id.
			if ( ! empty( $rows ) && class_exists( 'BizCity_KG_Database' ) ) {
				$ids = array_values( array_unique( array_filter( array_map(
					static function ( $r ) { return (int) ( $r['id'] ?? 0 ); },
					$rows
				) ) ) );
				if ( ! empty( $ids ) ) {
					$db_kg        = BizCity_KG_Database::instance();
					$tbl_passages = $db_kg->tbl_passages();
					$tbl_sources  = $db_kg->tbl_sources();

					// Build lookup: passage_source_id (any of legacy id OR kg_sources.id)
					//               → canonical local row id (what FE sees).
					$lookup    = [];
					foreach ( $ids as $local_id ) { $lookup[ $local_id ] = $local_id; }

					// Probe kg_sources for mirror rows whose origin_id == local id.
					// Only run if the kg_sources table actually exists.
					$placeholders_local = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$mirror_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id AS kg_id, origin_id FROM {$tbl_sources}
						  WHERE origin_id IN ({$placeholders_local})
						    AND scope_type = %s AND scope_id = %s",
						array_merge( $ids, [ 'notebook', (string) $notebook_id ] )
					), ARRAY_A );
					if ( is_array( $mirror_rows ) ) {
						foreach ( $mirror_rows as $mr ) {
							$kg_id = (int) $mr['kg_id'];
							$oid   = (int) $mr['origin_id'];
							if ( $kg_id > 0 && $oid > 0 && isset( $lookup[ $oid ] ) ) {
								$lookup[ $kg_id ] = $oid; // canonical bucket = local row id
							}
						}
					}

					$query_ids    = array_values( array_unique( array_keys( $lookup ) ) );
					$placeholders = implode( ',', array_fill( 0, count( $query_ids ), '%d' ) );
					// [2026-07-23 Johnny Chu] PHASE-0.47-KG-SOURCE-PROGRESS — same cross-notebook
					// source_id collision fix as _list_kg_sources(); this legacy path is only
					// reached when the bizcity_kg_v06_read_switch filter is forced off.
					$agg_sql      = "SELECT source_id,
						COUNT(*) AS total_chunks,
						SUM(CASE WHEN extraction_status = 'done'  THEN 1 ELSE 0 END) AS done_chunks,
						SUM(CASE WHEN extraction_status = 'error' THEN 1 ELSE 0 END) AS error_chunks
						FROM {$tbl_passages}
						WHERE notebook_id = %d AND source_id IN ({$placeholders})
						GROUP BY source_id";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$agg_rows = $wpdb->get_results( $wpdb->prepare( $agg_sql, array_merge( [ $notebook_id ], $query_ids ) ), ARRAY_A );
					$agg_map  = [];
					if ( is_array( $agg_rows ) ) {
						foreach ( $agg_rows as $a ) {
							$psid = (int) $a['source_id'];
							$lid  = $lookup[ $psid ] ?? 0;
							if ( $lid <= 0 ) continue;
							if ( ! isset( $agg_map[ $lid ] ) ) {
								$agg_map[ $lid ] = [ 'total' => 0, 'done' => 0, 'error' => 0 ];
							}
							$agg_map[ $lid ]['total'] += (int) $a['total_chunks'];
							$agg_map[ $lid ]['done']  += (int) $a['done_chunks'];
							$agg_map[ $lid ]['error'] += (int) $a['error_chunks'];
						}
					}
					foreach ( $rows as &$r ) {
						$rid   = (int) ( $r['id'] ?? 0 );
						$stat  = $agg_map[ $rid ] ?? [ 'total' => 0, 'done' => 0, 'error' => 0 ];
						$total = $stat['total'];
						$done  = $stat['done'];
						$r['extraction_total']    = $total;
						$r['extraction_done']     = $done;
						$r['extraction_error']    = $stat['error'];
						$r['extraction_complete'] = ( $total > 0 && $done >= $total );
						$r['extraction_progress'] = $total > 0 ? round( $done / $total, 4 ) : 0.0;
					}
					unset( $r );
				}
			}

			return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] list_sources error: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			return new WP_Error( 'list_sources_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function get_source( WP_REST_Request $request ) {
		try {
			if ( ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
				error_log( '[TwinChat] get_source: BizCity_TwinChat_Sources_Service not loaded' );
				return new WP_Error( 'service_unavailable', 'Sources service not available', [ 'status' => 503 ] );
			}
			$source_id   = (int) $request->get_param( 'source_id' );
			$notebook_id = (int) $request->get_param( 'notebook_id' );
			// [2026-07-14 Johnny Chu] PHASE-0.43 — source sheet must enforce notebook ownership before any source lookup.
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			// 2026-05-05 — Synthetic source IDs. The Twin context resolver
			// emits a synthetic id (1_000_000_000 + passage_id) for chat-promoted
			// passages whose underlying `source_id` is NULL (auto-promoter rows).
			// See BizCity_Twin_Context_Resolver::_resolve_citable_source_id().
			// Resolve those directly from kg_passages and return a virtual source row
			// so the FE source-detail panel can render the chip content instead of 404.
			if ( $source_id >= 1000000000 && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg  = BizCity_KG_Database::instance();
				$pid = $source_id - 1000000000;
				// kg_passages: only `notebook_id`, `content`, `origin`, `metadata`, `created_at` exist
				// (no `heading_path`/`scope_id` columns). Older deployments may also have a `scope_id`
				// column added by the Phase-0.6 migration — try that defensively.
				$pas = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$kg->tbl_passages()} WHERE id = %d LIMIT 1",
					$pid
				), ARRAY_A );
				if ( ! $pas ) {
					return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
				}
				// [2026-07-14 Johnny Chu] PHASE-0.43 — hydrate source-less passages stored in KG filestore.
				if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
					$hydrate_rows = array( $pas );
					BizCity_KG_Content_Router::instance()->hydrate_passages( $hydrate_rows );
					$pas = $hydrate_rows[0];
				}
				$pas_nb = (int) ( $pas['notebook_id'] ?? $pas['scope_id'] ?? 0 );
				if ( $notebook_id > 0 && $pas_nb !== $notebook_id ) {
					$pas_uuid = strtolower( (string) ( $pas['character_uuid'] ?? '' ) );
					$attached = $kg->get_attached_guru_uuids( $notebook_id );
					if ( '' === $pas_uuid || ! in_array( $pas_uuid, array_map( 'strtolower', $attached ), true ) ) {
						return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
					}
					$pas_nb = $notebook_id;
				}
				// Try to extract a useful title from `metadata` JSON if present.
				$title = 'Chat memory #' . $pid;
				if ( ! empty( $pas['metadata'] ) ) {
					$meta = json_decode( (string) $pas['metadata'], true );
					if ( is_array( $meta ) ) {
						if ( ! empty( $meta['heading_path'] ) ) {
							$title = is_array( $meta['heading_path'] ) ? implode( ' › ', $meta['heading_path'] ) : (string) $meta['heading_path'];
						} elseif ( ! empty( $meta['title'] ) ) {
							$title = (string) $meta['title'];
						}
					}
				}
				$row = [
					'id'               => (int) $source_id,
					'notebook_id'      => $pas_nb,
					'user_id'          => 0,
					'title'            => $title,
					'source_type'      => (string) ( $pas['origin'] ?? 'chat' ),
					'source_url'       => '',
					'content_text'     => (string) ( $pas['content'] ?? '' ),
					'embedding_status' => 'ready',
					'status'           => 'active',
					'created_at'       => (string) ( $pas['created_at'] ?? '' ),
					'updated_at'       => (string) ( $pas['updated_at'] ?? $pas['created_at'] ?? '' ),
					'is_synthetic'     => true,
				];
				return rest_ensure_response( [ 'ok' => true, 'data' => $row ] );
			}

			// Wave 0.6.C — source_id may be either a kg_sources.id (new write path)
			// OR a legacy webchat_sources.id (old citations / older messages). We must
			// resolve both, scoped by notebook_id to avoid cross-notebook id collisions.
			$row        = null;
			$rs_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $rs_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg     = BizCity_KG_Database::instance();
				// 1) Try as kg_sources.id (scoped).
				$kg_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, origin_id, origin_kind, title, origin_url, status, scope_id, user_id, character_uuid, content_text, passage_count, created_at
					   FROM {$kg->tbl_sources()}
					  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
					$source_id, 'notebook', (string) $notebook_id
				), ARRAY_A );
				// 2) Fallback: maybe source_id is a legacy webchat_sources.id → look up
				//    the mirror row via origin_id (also scoped).
				if ( ! $kg_row ) {
					$kg_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, origin_id, origin_kind, title, origin_url, status, scope_id, user_id, character_uuid, content_text, passage_count, created_at
						   FROM {$kg->tbl_sources()}
						  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
						$source_id, 'notebook', (string) $notebook_id
					), ARRAY_A );
				}
				// [2026-07-14 Johnny Chu] PHASE-0.43 — permit read-only Guru source only when attached to this notebook.
				if ( ! $kg_row ) {
					$guru_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, origin_id, origin_kind, title, origin_url, status, scope_id, user_id, character_uuid, content_text, passage_count, created_at
						   FROM {$kg->tbl_sources()}
						  WHERE id = %d AND character_uuid IS NOT NULL AND character_uuid <> '' LIMIT 1",
						$source_id
					), ARRAY_A );
					$attached_uuids = $kg->get_attached_guru_uuids( $notebook_id );
					if ( $guru_row && in_array( strtolower( (string) $guru_row['character_uuid'] ), array_map( 'strtolower', $attached_uuids ), true ) ) {
						$kg_row = $guru_row;
						$kg_row['scope_id'] = (string) $notebook_id;
					}
				}
				if ( $kg_row ) {
					$legacy_id    = (int) $kg_row['origin_id'];
					$legacy_row   = array();
					$content_text = (string) ( $kg_row['content_text'] ?? '' );
					if ( $legacy_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
						$db_tc        = BizCity_TwinChat_Sources_Database::instance();
						$tbl_src      = $db_tc->table_sources();
						// [2026-07-23 Johnny Chu] PHASE-0.43 — read placeholder metadata/status so async source detail does not look like an empty ready file.
						$legacy_row = $wpdb->get_row( $wpdb->prepare(
							"SELECT content_text, char_count, token_estimate, chunk_count, embedding_status, error_message, metadata FROM {$tbl_src} WHERE id = %d LIMIT 1",
							$legacy_id
						), ARRAY_A );
						// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — keep non-empty KG body; only override when legacy has real body.
						$content_text_legacy = is_array( $legacy_row ) ? (string) ( $legacy_row['content_text'] ?? '' ) : '';
						if ( $content_text_legacy !== '' ) {
							$content_text = $content_text_legacy;
						}
						if ( $content_text === '' && is_array( $legacy_row ) && ! empty( $legacy_row['metadata'] ) && class_exists( 'BizCity_KG_Source_Body_File_Store' ) ) {
							$legacy_meta_for_body = json_decode( (string) $legacy_row['metadata'], true );
							if ( is_array( $legacy_meta_for_body ) && ( $legacy_meta_for_body['body_storage'] ?? '' ) === 'filestore' ) {
								$file_body = BizCity_KG_Source_Body_File_Store::read_source( (int) $notebook_id, $legacy_id );
								if ( is_string( $file_body ) && $file_body !== '' ) {
									$content_text = $file_body;
								}
							}
						}
						// Fallback: stitch from chunk rows if content_text was never set.
						if ( $content_text === '' ) {
							// [2026-08-04 Johnny Chu] HOTFIX — read through canonical chunk storage; legacy table may not exist on the shard.
							$chunk_rows = $db_tc->list_chunks( $legacy_id );
							$texts      = array_filter( array_map( static function ( $chunk ) {
								return (string) ( $chunk['content'] ?? '' );
							}, (array) $chunk_rows ) );
							if ( $texts ) $content_text = implode( "\n\n", $texts );
						}
					}
					$legacy_meta = array();
					if ( isset( $legacy_row['metadata'] ) && is_string( $legacy_row['metadata'] ) && $legacy_row['metadata'] !== '' ) {
						$decoded_meta = json_decode( $legacy_row['metadata'], true );
						$legacy_meta  = is_array( $decoded_meta ) ? $decoded_meta : array();
					}
					$kg_status = (string) ( $kg_row['status'] ?? 'active' );
					$embedding_status = 'ready';
					if ( $kg_status === 'processing' ) {
						$embedding_status = 'processing';
					} elseif ( $kg_status === 'error' ) {
						$embedding_status = 'error';
					} elseif ( isset( $legacy_row['embedding_status'] ) && in_array( (string) $legacy_row['embedding_status'], array( 'pending', 'processing', 'ready', 'error' ), true ) ) {
						$embedding_status = (string) $legacy_row['embedding_status'];
					}
					$row = [
						'id'               => (int) $kg_row['id'],
						'notebook_id'      => (int) $kg_row['scope_id'],
						'user_id'          => (int) $kg_row['user_id'],
						'title'            => (string) ( $kg_row['title'] ?? '' ),
						'source_type'      => (string) ( $kg_row['origin_kind'] ?? 'file' ),
						'source_url'       => (string) ( $kg_row['origin_url'] ?? '' ),
						'char_count'       => isset( $legacy_row['char_count'] ) ? (int) $legacy_row['char_count'] : 0,
						'token_estimate'   => isset( $legacy_row['token_estimate'] ) ? (int) $legacy_row['token_estimate'] : 0,
						'chunk_count'      => isset( $legacy_row['chunk_count'] ) ? (int) $legacy_row['chunk_count'] : (int) ( $kg_row['passage_count'] ?? 0 ),
						'content_text'     => $content_text,
						'embedding_status' => $embedding_status,
						'status'           => (string) ( $kg_row['status'] ?? 'active' ),
						'error_message'    => isset( $legacy_row['error_message'] ) ? (string) $legacy_row['error_message'] : '',
						'metadata'         => $legacy_meta,
						'created_at'       => (string) ( $kg_row['created_at'] ?? '' ),
						'updated_at'       => (string) ( $kg_row['created_at'] ?? '' ),
						'__legacy_source_id' => $legacy_id,
					];
				}
			}

			if ( ! $row ) {
				$row = BizCity_TwinChat_Sources_Service::instance()->get_source( $source_id );
			}
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
			}
			// Ownership check — only enforce when notebook_id is present in the row.
			// Fallback KG-Hub sources don't have notebook_id in their shape → skip.
			if ( isset( $row['notebook_id'] ) && (int) $row['notebook_id'] > 0 && (int) $row['notebook_id'] !== $notebook_id ) {
				return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
			}
			// Append full content_text if stored in chunks (for detail view)
			if ( empty( $row['content_text'] ) ) {
				global $wpdb;
				$db           = BizCity_TwinChat_Sources_Database::instance();
				$chunk_source_id = isset( $row['__legacy_source_id'] ) ? (int) $row['__legacy_source_id'] : $source_id;
				// [2026-08-04 Johnny Chu] HOTFIX — keep source-detail reads on the canonical chunk resolver.
				$chunk_rows = $db->list_chunks( $chunk_source_id );
				$texts      = array_filter( array_map( static function ( $chunk ) {
					return (string) ( $chunk['content'] ?? '' );
				}, (array) $chunk_rows ) );
				if ( $texts ) {
					$row['content_text'] = implode( "\n\n", $texts );
				}
			}

			if ( empty( $row['content_text'] ) && class_exists( 'BizCity_KG_Database' ) ) {
				// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — last-resort reconstruction from kg_passages.
				global $wpdb;
				$kg_db = BizCity_KG_Database::instance();
				$try_ids = array_values( array_unique( array_filter( array_map( 'intval', array(
					$source_id,
					isset( $row['id'] ) ? (int) $row['id'] : 0,
					isset( $row['__legacy_source_id'] ) ? (int) $row['__legacy_source_id'] : 0,
				) ) ) ) );
				if ( ! empty( $try_ids ) ) {
					$ids_csv = implode( ',', $try_ids );
					$passage_rows = $wpdb->get_results(
						"SELECT id, content, storage_ver, file_shard, file_offset, file_length, notebook_id
						   FROM {$kg_db->tbl_passages()}
						  WHERE notebook_id = " . (int) $notebook_id . " AND source_id IN ({$ids_csv})
						  ORDER BY id ASC LIMIT 3000",
						ARRAY_A
					);
					if ( $passage_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
						BizCity_KG_Content_Router::instance()->hydrate_passages( $passage_rows );
					}
					if ( is_array( $passage_rows ) && ! empty( $passage_rows ) ) {
						$chunks = array();
						foreach ( $passage_rows as $p_row ) {
							$txt = trim( (string) ( $p_row['content'] ?? '' ) );
							if ( $txt !== '' ) {
								$chunks[] = $txt;
							}
						}
						if ( ! empty( $chunks ) ) {
							$row['content_text'] = implode( "\n\n", $chunks );
						}
					}
				}
			}

			if ( isset( $row['__legacy_source_id'] ) ) {
				unset( $row['__legacy_source_id'] );
			}
			return rest_ensure_response( [ 'ok' => true, 'data' => $row ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] get_source error: ' . $e->getMessage() );
			return new WP_Error( 'get_source_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * PHASE-0.44 — source-scoped learning summary for drawer "Learning Log" tab.
	 *
	 * GET /sources/{notebook_id}/{source_id}/learning-log
	 */
	public function get_source_learning_log( WP_REST_Request $request ) {
		// [2026-07-23 Johnny Chu] PHASE-0.44 — dual-id aggregate (kg_sources.id + origin_id) to avoid false 0%.
		global $wpdb;

		$notebook_id    = (int) $request->get_param( 'notebook_id' );
		$source_id      = (int) $request->get_param( 'source_id' );
		$limit          = max( 10, min( 500, (int) $request->get_param( 'limit' ) ) );
		$include_chunks = (int) $request->get_param( 'include_chunks' ) !== 0;
		$chunk_limit    = max( 20, min( 400, (int) $request->get_param( 'chunk_limit' ) ) );

		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( $source_id <= 0 ) {
			return new WP_Error( 'invalid_source', 'Invalid source_id.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_missing', 'KG database runtime is unavailable.', [ 'status' => 503 ] );
		}

		$db          = BizCity_KG_Database::instance();
		$tbl_sources = $db->tbl_sources();
		$tbl_passage = $db->tbl_passages();

		// [2026-07-23 Johnny Chu] HOTFIX kg-source-lookup — origin_id is the real FK
		// (webchat_sources.id → kg_sources.origin_id, see _upsert_kg_source_row()).
		// `source_id` passed by the FE is ALWAYS the legacy webchat id, never a
		// kg_sources.id. Matching `id = %d` FIRST was unsafe: kg_sources is a
		// single autoincrement table shared by every notebook/plugin, so a small
		// legacy id (e.g. #56) can coincidentally collide with an unrelated,
		// already-populated kg_sources row from an older source in the SAME
		// notebook — silently returning 0 passages / 0% while the freshly
		// uploaded file's real mirror row (with the growing passage count) is
		// ignored. Try the origin_id FK first; only fall back to `id = %d` for
		// callers that legitimately pass a kg_sources.id directly.
		$kg_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, origin_id, title, status, passage_count, origin_kind, origin_url, created_at, updated_at
			   FROM {$tbl_sources}
			  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s
			  ORDER BY id DESC LIMIT 1",
			$source_id, 'notebook', (string) $notebook_id
		), ARRAY_A );
		if ( ! $kg_row ) {
			$kg_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, origin_id, title, status, passage_count, origin_kind, origin_url, created_at, updated_at
				   FROM {$tbl_sources}
				  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
				$source_id, 'notebook', (string) $notebook_id
			), ARRAY_A );
		}
		if ( ! $kg_row ) {
			return new WP_Error( 'not_found', 'Source not found in this notebook.', [ 'status' => 404 ] );
		}

		$kg_source_id     = (int) $kg_row['id'];
		$legacy_source_id = (int) $kg_row['origin_id'];
		$source_ids       = array_values( array_unique( array_filter( [
			$kg_source_id,
			$legacy_source_id,
		], static function ( $v ) {
			return (int) $v > 0;
		} ) ) );
		if ( empty( $source_ids ) ) {
			$source_ids = [ $kg_source_id ];
		}

		$counts = [
			'total'              => 0,
			'done'               => 0,
			'processing'         => 0,
			'pending'            => 0,
			'error'              => 0,
			'skipped'            => 0,
			'triplets_pending'   => 0,
			'entities_approved'  => 0,
			'relations_approved' => 0,
		];

		$src_ph = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$status_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT extraction_status, COUNT(*) AS n
			   FROM {$tbl_passage}
			  WHERE notebook_id = %d AND source_id IN ({$src_ph})
			  GROUP BY extraction_status",
			array_merge( [ $notebook_id ], $source_ids )
		), ARRAY_A );
		foreach ( (array) $status_rows as $sr ) {
			$bucket = strtolower( (string) ( $sr['extraction_status'] ?? '' ) );
			$n      = (int) ( $sr['n'] ?? 0 );
			if ( ! isset( $counts[ $bucket ] ) ) {
				$bucket = 'pending';
			}
			$counts[ $bucket ] += $n;
			$counts['total']   += $n;
		}

		$chunks       = [];
		$passage_ids  = [];
		$passage_set  = [];
		if ( $include_chunks ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$chunk_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, extraction_status, updated_at, content, metadata,
				        storage_ver, file_shard, file_offset, file_length, notebook_id
				   FROM {$tbl_passage}
				  WHERE notebook_id = %d AND source_id IN ({$src_ph})
				  ORDER BY id ASC
				  LIMIT %d",
				array_merge( [ $notebook_id ], $source_ids, [ $chunk_limit ] )
			), ARRAY_A );

			if ( class_exists( 'BizCity_KG_Content_Router' ) && ! empty( $chunk_rows ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_passages( $chunk_rows );
			}

			foreach ( (array) $chunk_rows as $pr ) {
				$passage_id = (int) ( $pr['id'] ?? 0 );
				if ( $passage_id > 0 ) {
					$passage_ids[] = $passage_id;
					$passage_set[ $passage_id ] = true;
				}
			}

			$triplet_by_passage = [];
			if ( ! empty( $passage_ids ) ) {
				$ps_ph = implode( ',', array_fill( 0, count( $passage_ids ), '%d' ) );
				$tbl_pr = $db->tbl_passage_relations();
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$triplet_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT passage_id, COUNT(*) AS c
					   FROM {$tbl_pr}
					  WHERE passage_id IN ({$ps_ph})
					  GROUP BY passage_id",
					$passage_ids
				), ARRAY_A );
				foreach ( (array) $triplet_rows as $tr ) {
					$triplet_by_passage[ (int) $tr['passage_id'] ] = (int) $tr['c'];
				}
			}

			foreach ( (array) $chunk_rows as $pr ) {
				$pid     = (int) ( $pr['id'] ?? 0 );
				$status  = strtolower( (string) ( $pr['extraction_status'] ?? 'pending' ) );
				if ( ! in_array( $status, [ 'pending', 'processing', 'done', 'error', 'skipped' ], true ) ) {
					$status = 'pending';
				}
				$content = trim( (string) ( $pr['content'] ?? '' ) );
				if ( function_exists( 'mb_substr' ) ) {
					$snippet = mb_substr( $content, 0, 160 );
				} else {
					$snippet = substr( $content, 0, 160 );
				}
				$meta        = [];
				$chunk_index = null;
				if ( isset( $pr['metadata'] ) && is_string( $pr['metadata'] ) && $pr['metadata'] !== '' ) {
					$meta = json_decode( $pr['metadata'], true );
					if ( is_array( $meta ) && isset( $meta['chunk_index'] ) ) {
						$chunk_index = (int) $meta['chunk_index'];
					}
				}
				$chunks[] = [
					'passage_id'   => $pid,
					'chunk_index'  => $chunk_index,
					'status'       => $status,
					'triplets'     => (int) ( $triplet_by_passage[ $pid ] ?? 0 ),
					'updated_at'   => (string) ( $pr['updated_at'] ?? '' ),
					'snippet'      => $snippet,
				];
			}
		}

		$tbl_tq = $db->tbl_triplet_queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['triplets_pending'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$tbl_tq} tq
			   INNER JOIN {$tbl_passage} p ON p.id = tq.passage_id
			  WHERE p.notebook_id = %d
			    AND p.source_id IN ({$src_ph})
			    AND tq.status = %s",
			array_merge( [ $notebook_id ], $source_ids, [ 'pending' ] )
		) );

		$tbl_pe = $db->tbl_passage_entities();
		$tbl_pr = $db->tbl_passage_relations();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['entities_approved'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pe.entity_id)
			   FROM {$tbl_pe} pe
			   INNER JOIN {$tbl_passage} p ON p.id = pe.passage_id
			  WHERE p.notebook_id = %d AND p.source_id IN ({$src_ph})",
			array_merge( [ $notebook_id ], $source_ids )
		) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['relations_approved'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pr.relation_id)
			   FROM {$tbl_pr} pr
			   INNER JOIN {$tbl_passage} p ON p.id = pr.passage_id
			  WHERE p.notebook_id = %d AND p.source_id IN ({$src_ph})",
			array_merge( [ $notebook_id ], $source_ids )
		) );

		$job       = null;
		$job_id    = 0;
		$job_phase = '';
		$job_state = '';
		if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			$ldb = BizCity_TwinChat_Learning_Database::instance();
			if ( $ldb->is_ready() ) {
				$tbl_jobs = $ldb->table_jobs();
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$job_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, notebook_id, source_id, source_title, user_id, status, phase,
					        progress, passages_processed, triplets_extracted, entities_approved,
					        batches_total, batches_done, entity_ids, error, started_at, finished_at, created_at
					   FROM {$tbl_jobs}
					  WHERE notebook_id = %d AND source_id IN ({$src_ph})
					  ORDER BY id DESC LIMIT 1",
					array_merge( [ $notebook_id ], $source_ids )
				), ARRAY_A );
				if ( is_array( $job_row ) ) {
					$job_id    = (int) $job_row['id'];
					$job_phase = (string) ( $job_row['phase'] ?? '' );
					$job_state = (string) ( $job_row['status'] ?? '' );
					$entity_ids = [];
					if ( isset( $job_row['entity_ids'] ) && is_string( $job_row['entity_ids'] ) && $job_row['entity_ids'] !== '' ) {
						$decoded = json_decode( $job_row['entity_ids'], true );
						if ( is_array( $decoded ) ) {
							$entity_ids = array_values( array_map( 'intval', $decoded ) );
						}
					}
					$job = [
						'id'                 => $job_id,
						'notebook_id'        => (int) $job_row['notebook_id'],
						'source_id'          => (int) $job_row['source_id'],
						'source_title'       => (string) ( $job_row['source_title'] ?? '' ),
						'user_id'            => (int) $job_row['user_id'],
						'status'             => $job_state,
						'phase'              => $job_phase,
						'progress'           => (int) ( $job_row['progress'] ?? 0 ),
						'passages_processed' => (int) ( $job_row['passages_processed'] ?? 0 ),
						'triplets_extracted' => (int) ( $job_row['triplets_extracted'] ?? 0 ),
						'entities_approved'  => (int) ( $job_row['entities_approved'] ?? 0 ),
						'batches_total'      => (int) ( $job_row['batches_total'] ?? 0 ),
						'batches_done'       => (int) ( $job_row['batches_done'] ?? 0 ),
						'entity_ids'         => $entity_ids,
						'error'              => isset( $job_row['error'] ) ? (string) $job_row['error'] : null,
						'started_at'         => isset( $job_row['started_at'] ) ? (string) $job_row['started_at'] : null,
						'finished_at'        => isset( $job_row['finished_at'] ) ? (string) $job_row['finished_at'] : null,
						'created_at'         => (string) ( $job_row['created_at'] ?? '' ),
					];
				}
			}
		}

		$events = [];
		if ( class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			$progress_rows = BizCity_KG_Source_Progress_Log::get_for_source( $kg_source_id, $limit );
			if ( $legacy_source_id > 0 && $legacy_source_id !== $kg_source_id ) {
				$progress_rows = array_merge( $progress_rows, BizCity_KG_Source_Progress_Log::get_for_source( $legacy_source_id, $limit ) );
			}
			if ( ! empty( $progress_rows ) ) {
				usort( $progress_rows, static function ( $a, $b ) {
					return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
				} );
				foreach ( $progress_rows as $pe ) {
					$payload = isset( $pe['payload'] ) && is_array( $pe['payload'] ) ? $pe['payload'] : [];
					$event   = (string) ( $pe['event'] ?? 'log' );
					$events[] = [
						'id'         => (int) ( $pe['id'] ?? 0 ),
						'ts'         => (string) ( $pe['created_at'] ?? '' ),
						'level'      => $this->source_learning_event_level( $event, $payload ),
						'event'      => $event,
						'message'    => $this->source_learning_event_message( $event, $payload ),
						'passage_id' => isset( $pe['passage_id'] ) ? (int) $pe['passage_id'] : 0,
						'job_id'     => 0,
						'payload'    => $payload,
					];
				}
			}
		}

		if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			$ldb = BizCity_TwinChat_Learning_Database::instance();
			if ( $ldb->is_ready() ) {
				$tbl_events = $ldb->table_events();
				if ( $job_id > 0 ) {
					$event_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, job_id, ts, event, payload
						   FROM {$tbl_events}
						  WHERE notebook_id = %d AND job_id = %d
						  ORDER BY id DESC
						  LIMIT %d",
						$notebook_id, $job_id, $limit
					), ARRAY_A );
				} else {
					$event_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, job_id, ts, event, payload
						   FROM {$tbl_events}
						  WHERE notebook_id = %d
						  ORDER BY id DESC
						  LIMIT %d",
						$notebook_id, $limit
					), ARRAY_A );
				}
				$event_rows = array_reverse( (array) $event_rows );
				foreach ( $event_rows as $er ) {
					$payload = [];
					if ( isset( $er['payload'] ) && is_string( $er['payload'] ) && $er['payload'] !== '' ) {
						$decoded = json_decode( $er['payload'], true );
						if ( is_array( $decoded ) ) {
							$payload = $decoded;
						}
					}

					// [2026-07-23 Johnny Chu] HOTFIX learning-log-timeline-leak — a learning
					// job is notebook-wide (one job can walk passages across MANY sources),
					// so job_id alone does not scope events to THIS source. Verify
					// ownership via payload.source_id or payload.passage_id; when neither
					// is present AND the row wasn't already pinned to a job_id by the SQL
					// above, fail closed instead of leaking unrelated sources' events
					// (previously showed other sources' passage sync lines on a freshly
					// uploaded, still-materializing file with zero passages of its own).
					$payload_source_id  = isset( $payload['source_id'] ) ? (int) $payload['source_id'] : 0;
					$payload_passage_id = isset( $payload['passage_id'] ) ? (int) $payload['passage_id'] : 0;
					if ( $payload_source_id > 0 ) {
						if ( ! in_array( $payload_source_id, $source_ids, true ) ) {
							continue;
						}
					} elseif ( $payload_passage_id > 0 ) {
						if ( empty( $passage_set ) || ! isset( $passage_set[ $payload_passage_id ] ) ) {
							continue;
						}
					} elseif ( $job_id <= 0 ) {
						continue;
					}

					$event = (string) ( $er['event'] ?? 'log' );
					$events[] = [
						'id'         => (int) ( $er['id'] ?? 0 ),
						'ts'         => (string) ( $er['ts'] ?? '' ),
						'level'      => $this->source_learning_event_level( $event, $payload ),
						'event'      => $event,
						'message'    => $this->source_learning_event_message( $event, $payload ),
						'passage_id' => $payload_passage_id,
						'job_id'     => isset( $er['job_id'] ) ? (int) $er['job_id'] : 0,
						'payload'    => $payload,
					];
				}
			}
		}

		if ( count( $events ) > $limit ) {
			$events = array_slice( $events, -1 * $limit );
		}

		$done      = (int) $counts['done'];
		$skipped   = (int) $counts['skipped'];
		$total     = (int) $counts['total'];
		$progress  = $total > 0 ? round( min( 1, max( 0, ( $done + $skipped ) / $total ) ), 4 ) : 0.0;

		$status = 'unknown';
		if ( $job_state === 'failed' || (string) ( $kg_row['status'] ?? '' ) === 'error' ) {
			$status = 'failed';
		} elseif ( $job_state === 'queued' || $job_phase === 'queued' ) {
			$status = 'queued';
		} elseif ( $job_phase === 'approving' ) {
			$status = 'approving';
		} elseif ( $job_phase === 'extracting' || ( $total > 0 && $done < $total ) ) {
			$status = 'extracting';
		} elseif ( $job_state === 'done' || ( $total > 0 && $done >= $total && (int) $counts['triplets_pending'] <= 0 ) ) {
			$status = 'done';
			$progress = $total > 0 ? 1.0 : $progress;
		} elseif ( (string) ( $kg_row['status'] ?? '' ) === 'processing' ) {
			$status = 'materializing';
		}

		// [2026-07-25 Johnny Chu] HOTFIX async-retry — surface the real failure reason + whether a one-click retry is possible.
		$error_message  = '';
		// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — structured code so FE
		// can call humanizeIngestError() instead of showing raw text + blind Retry.
		$error_code     = '';
		$retryable      = false;
		if ( $status === 'failed' && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			$legacy_row = BizCity_TwinChat_Sources_Database::instance()->get_source( $legacy_source_id > 0 ? $legacy_source_id : $source_id );
			if ( is_array( $legacy_row ) ) {
				$error_message = isset( $legacy_row['error_message'] ) ? (string) $legacy_row['error_message'] : '';
				$legacy_meta   = ! empty( $legacy_row['metadata'] ) ? json_decode( (string) $legacy_row['metadata'], true ) : array();
				if ( is_array( $legacy_meta ) && ! empty( $legacy_meta['async_error_code'] ) ) {
					$error_code = sanitize_key( (string) $legacy_meta['async_error_code'] );
				} elseif ( $error_message !== '' ) {
					// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — retro-fix: sources
					// that failed BEFORE async_error_code existed only have the free-text
					// message. Infer the code from known message patterns at read-time —
					// no backfill migration/script needed, self-heals on next real failure.
					$error_code = self::infer_legacy_async_error_code( $error_message );
				}
				if ( is_array( $legacy_meta ) && ! empty( $legacy_meta['async_file'] ) && class_exists( 'BizCity_KG_Scoped_REST_Controller' ) ) {
					$retryable = BizCity_KG_Scoped_REST_Controller::async_source_file_exists( $legacy_meta['async_file'] );
				}
			}
		}

		$phases = [
			[
				'id'          => 'materialize',
				'label'       => 'Materialize text',
				'status'      => $total > 0 ? 'done' : ( (string) ( $kg_row['status'] ?? '' ) === 'processing' ? 'running' : 'pending' ),
				'count_done'  => $total > 0 ? 1 : 0,
				'count_total' => 1,
			],
			[
				'id'          => 'chunk',
				'label'       => 'Chunking',
				'status'      => $total > 0 ? 'done' : 'pending',
				'count_done'  => $total,
				'count_total' => $total,
			],
			[
				'id'          => 'extract',
				'label'       => 'Graph extraction',
				'status'      => ( $counts['error'] > 0 && $done === 0 ) ? 'error' : ( $total > 0 && $done >= $total ? 'done' : ( $total > 0 ? 'running' : 'pending' ) ),
				'count_done'  => $done,
				'count_total' => $total,
			],
			[
				'id'          => 'approve',
				'label'       => 'Approve triplets',
				'status'      => (int) $counts['triplets_pending'] > 0 ? 'running' : ( $done > 0 ? 'done' : 'pending' ),
				'count_done'  => (int) $counts['relations_approved'],
				'count_total' => (int) $counts['relations_approved'] + (int) $counts['triplets_pending'],
			],
		];

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'source_id'        => $source_id,
				'kg_source_id'     => $kg_source_id,
				'legacy_source_id' => $legacy_source_id > 0 ? $legacy_source_id : null,
				'notebook_id'      => $notebook_id,
				'title'            => (string) ( $kg_row['title'] ?? '' ),
				'status'           => $status,
				'progress'         => $progress,
				'error_message'    => $error_message,
				'error_code'       => $error_code,
				'retryable'        => $retryable,
				'counts'           => $counts,
				'job'              => $job,
				'phases'           => $phases,
				'chunks'           => $chunks,
				'events'           => $events,
				'raw_log_hint'     => [
					'date'    => gmdate( 'Y-m-d' ),
					'markers' => array_values( array_filter( [
						$job_id > 0 ? 'job=' . $job_id : '',
						'kg_source=' . $kg_source_id,
						$legacy_source_id > 0 ? 'legacy_source=' . $legacy_source_id : '',
					] ) ),
				],
			],
		] );
	}

	/**
	 * [2026-07-25 Johnny Chu] HOTFIX async-retry — one-click retry for a source stuck 'failed' after
	 * async ingest exhausted attempts. Requeues from the still-staged temp file when available;
	 * returns a clear R-ERROR-UX payload (delete + re-upload guidance) when the file is already gone.
	 */
	public function retry_source_ingest( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$source_id   = (int) $request->get_param( 'source_id' );
		$auth        = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! class_exists( 'BizCity_KG_Scoped_REST_Controller' ) ) {
			return new WP_Error( 'service_unavailable', 'KG ingest runtime is unavailable.', [ 'status' => 503 ] );
		}
		$result = BizCity_KG_Scoped_REST_Controller::retry_async_source( $source_id, $notebook_id );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( class_exists( 'BizCity_Error_Payload' )
				? BizCity_Error_Payload::from_wp_error( $result, 'Xóa nguồn này và tải lại file để thử lại từ đầu.' )
				: [ 'success' => false, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], $status );
		}
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * POST /sources/{notebook_id}/{source_id}/share-link
	 * Mints a stateless, no-login share token scoped to this
	 * (notebook_id, source_id) pair via BizCity_TwinChat_Learning_Share_Adapter.
	 * Ownership check reuses check_notebook_access() — same guard as every
	 * other /sources/{notebook_id}/... route in this controller.
	 */
	public function create_source_share_link( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$source_id   = (int) $request->get_param( 'source_id' );
		$auth        = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( $source_id <= 0 ) {
			return new WP_Error( 'invalid_source', 'Invalid source_id.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' ) ) {
			return new WP_Error( 'unavailable', 'Learning share adapter chưa sẵn sàng trên site này.', [ 'status' => 503 ] );
		}

		$ttl_days = max( 1, min( 180, (int) $request->get_param( 'ttl_days' ) ?: 30 ) );
		$link = BizCity_TwinChat_Learning_Share_Adapter::instance()->create_link( $notebook_id, $source_id, [
			'ttl_s' => $ttl_days * DAY_IN_SECONDS,
		] );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return rest_ensure_response( [ 'ok' => true, 'data' => $link ] );
	}

	/**
	 * [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — retro-fix helper for
	 * sources that failed BEFORE `async_error_code` was persisted in metadata
	 * (see BizCity_KG_Scoped_REST_Controller::mark_async_placeholder_failed()).
	 * Maps the exact free-text messages that code has ever written into their
	 * matching error code, so old rows still humanize correctly without any
	 * DB backfill/migration. New failures always carry the real code already;
	 * this is a read-time fallback only.
	 *
	 * @param string $message
	 * @return string  Error code, or '' when no known pattern matches.
	 */
	private static function infer_legacy_async_error_code( string $message ): string {
		$patterns = [
			'đã dùng hết quota Hub'              => 'gemini_quota_exhausted',
			'File xử lý nền đã thất lạc'          => 'async_file_lost',
			'File gốc đã bị xoá sau khi hết số lần thử' => 'async_file_missing',
			'async ingest file missing'           => 'async_file_missing',
			'Đã vượt quá số lần thử xử lý file'   => 'async_max_attempts_exceeded',
			'Không thể xếp lịch xử lý file nền'   => 'async_ingest_schedule_failed',
			'Chunk persistence failed'             => 'chunk_persist_failed',
			'Passage persistence failed'          => 'passage_persist_failed',
			'Không lưu đủ các đoạn nội dung'      => 'chunk_persist_failed',
			'Không lưu đủ dữ liệu tìm kiếm'       => 'passage_persist_failed',
		];
		foreach ( $patterns as $needle => $code ) {
			if ( mb_stripos( $message, $needle ) !== false ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Normalize event level for mixed sources: learning events + source progress log.
	 */
	private function source_learning_event_level( $event, array $payload = [] ) {
		$event = strtolower( (string) $event );
		$level = isset( $payload['level'] ) ? strtolower( (string) $payload['level'] ) : '';
		if ( in_array( $level, [ 'info', 'warn', 'error', 'ok' ], true ) ) {
			return $level;
		}
		if ( $level === 'step' ) {
			return 'info';
		}
		if ( strpos( $event, 'error' ) !== false || $event === 'failed' ) {
			return 'error';
		}
		if ( strpos( $event, 'done' ) !== false || strpos( $event, 'complete' ) !== false ) {
			return 'ok';
		}
		if ( strpos( $event, 'warn' ) !== false ) {
			return 'warn';
		}
		return 'info';
	}

	/**
	 * Build a readable event message from structured payload.
	 */
	private function source_learning_event_message( $event, array $payload = [] ) {
		if ( isset( $payload['msg'] ) && is_string( $payload['msg'] ) && $payload['msg'] !== '' ) {
			return (string) $payload['msg'];
		}
		$event = strtolower( (string) $event );
		if ( $event === 'progress' ) {
			$processed = isset( $payload['processed'] ) ? (int) $payload['processed'] : 0;
			$remaining = isset( $payload['remaining'] ) ? (int) $payload['remaining'] : 0;
			$triplets  = isset( $payload['total_triplets'] ) ? (int) $payload['total_triplets'] : 0;
			return sprintf( 'Processed %d passages, %d triplets, remaining %d.', $processed, $triplets, $remaining );
		}
		if ( $event === 'passage_done' ) {
			$triplets = isset( $payload['triplets'] ) ? (int) $payload['triplets'] : 0;
			return sprintf( 'Passage extracted successfully (%d triplets).', $triplets );
		}
		if ( $event === 'passage_error' ) {
			$err = isset( $payload['error'] ) ? (string) $payload['error'] : 'unknown_error';
			return 'Passage extraction failed: ' . $err;
		}
		if ( $event === 'batch_done' ) {
			$processed = isset( $payload['processed'] ) ? (int) $payload['processed'] : 0;
			$triplets  = isset( $payload['total_triplets'] ) ? (int) $payload['total_triplets'] : 0;
			$errors    = isset( $payload['errors'] ) ? (int) $payload['errors'] : 0;
			return sprintf( 'Batch done: %d passages, %d triplets, %d errors.', $processed, $triplets, $errors );
		}
		if ( $event === 'done' ) {
			return 'Learning job completed.';
		}
		if ( $event === 'job' && isset( $payload['status'] ) ) {
			return 'Job status: ' . (string) $payload['status'];
		}
		return str_replace( '_', ' ', $event );
	}

	public function delete_source( WP_REST_Request $request ) {
		try {
			if ( ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
				return new WP_Error( 'service_unavailable', 'Sources service not available', [ 'status' => 503 ] );
			}
			$notebook_id = (int) $request->get_param( 'notebook_id' );
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) return $auth;
			$source_id   = (int) $request->get_param( 'source_id' );

			// Wave 0.6.C — source_id may be kg_sources.id OR legacy webchat_sources.id.
			$rs_default = (bool) get_option( 'bizcity_kg_unified_read_enabled', true );
			if ( apply_filters( 'bizcity_kg_v06_read_switch', $rs_default ) && class_exists( 'BizCity_KG_Database' ) ) {
				global $wpdb;
				$kg     = BizCity_KG_Database::instance();
				// Try kg_sources.id (scoped), then origin_id (legacy), both scoped by notebook.
				$kg_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, origin_id, scope_id FROM {$kg->tbl_sources()}
					  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
					$source_id, 'notebook', (string) $notebook_id
				), ARRAY_A );
				if ( ! $kg_row ) {
					$kg_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, origin_id, scope_id FROM {$kg->tbl_sources()}
						  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
						$source_id, 'notebook', (string) $notebook_id
					), ARRAY_A );
				}
				if ( $kg_row ) {
					if ( (int) $kg_row['scope_id'] !== $notebook_id ) {
						return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
					}
					$kg_source_id = (int) $kg_row['id'];
					$legacy_id    = (int) $kg_row['origin_id'];
					$scope_str    = (string) $kg_row['scope_id'];
					// Fire hook before deletion so cascade listeners can still resolve passage_ids.
					do_action( 'bizcity_twinchat_after_source_delete', $legacy_id > 0 ? $legacy_id : $source_id, $scope_str );
					// Delete kg_passages for both id-paths.
					$wpdb->delete( $kg->tbl_passages(), [ 'source_id' => $kg_source_id ] );
					if ( $legacy_id > 0 ) {
						$wpdb->delete( $kg->tbl_passages(), [ 'scope_id' => $scope_str, 'source_id' => $legacy_id ] );
					}
					// Delete the kg_sources row.
					$wpdb->delete( $kg->tbl_sources(), [ 'id' => $kg_source_id ] );
					// Delete legacy webchat_sources row (no hook — already fired above).
					if ( $legacy_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
						BizCity_TwinChat_Sources_Database::instance()->delete_source( $legacy_id );
					}
					return rest_ensure_response( [ 'ok' => true ] );
				}
			}

			$row = BizCity_TwinChat_Sources_Service::instance()->get_source( $source_id );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
			}
			// Ownership check — only enforce when notebook_id is present in the row.
			if ( isset( $row['notebook_id'] ) && (int) $row['notebook_id'] > 0 && (int) $row['notebook_id'] !== $notebook_id ) {
				return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
			}
			$ok = BizCity_TwinChat_Sources_Service::instance()->delete_source( $source_id );
			return rest_ensure_response( [ 'ok' => (bool) $ok ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] delete_source error: ' . $e->getMessage() );
			return new WP_Error( 'delete_source_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function delete_by_origin( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$origin      = (string) $request->get_param( 'origin' );
		if ( $notebook_id <= 0 || $origin === '' ) {
			return new WP_Error( 'bad_request', 'notebook_id and origin are required', [ 'status' => 400 ] );
		}
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		try {
			$deleted = 0;
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$kg     = BizCity_KG_Database::instance();
				$tbl    = $kg->tbl_passages();
				$deleted = (int) $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$tbl} WHERE notebook_id = %d AND origin = %s AND source_id IS NULL",
					$notebook_id, $origin
				) );
			}
			return rest_ensure_response( [ 'ok' => true, 'deleted' => $deleted ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] delete_by_origin error: ' . $e->getMessage() );
			return new WP_Error( 'delete_by_origin_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function get_by_origin( WP_REST_Request $request ) {
		global $wpdb;
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$origin      = (string) $request->get_param( 'origin' );
		if ( $notebook_id <= 0 || $origin === '' ) {
			return new WP_Error( 'bad_request', 'notebook_id and origin are required', [ 'status' => 400 ] );
		}
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		try {
			if ( ! class_exists( 'BizCity_KG_Database' ) ) {
				return new WP_Error( 'no_kg', 'KG database not available', [ 'status' => 500 ] );
			}
			$kg  = BizCity_KG_Database::instance();
			$tbl = $kg->tbl_passages();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, created_at FROM {$tbl}
				  WHERE notebook_id = %d AND origin = %s AND source_id IS NULL
				  ORDER BY id ASC",
				$notebook_id, $origin
			), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];
			$texts = array_map( static function ( $r ) { return (string) $r['content']; }, $rows );
			$content_text = implode( "\n\n", $texts );
			$char_count   = mb_strlen( $content_text );
			// Derive title + type from origin string (mirror UI logic)
			$type = 'text'; $title = 'Văn bản thủ công';
			if ( strpos( $origin, 'file:' ) === 0 ) { $type = 'file'; $title = substr( $origin, 5 ); }
			elseif ( strpos( $origin, 'url:' ) === 0 ) { $type = 'url';  $title = substr( $origin, 4 ); }
			elseif ( $origin === 'url' ) { $type = 'url'; $title = 'Trang web'; }
			$created_at = $rows ? (string) $rows[0]['created_at'] : '';
			$data = [
				'id'               => 0,
				'notebook_id'      => $notebook_id,
				'user_id'          => 0,
				'title'            => $title,
				'source_type'      => $type,
				'source_url'       => ( $type === 'url' ) ? $title : '',
				'attachment_id'    => 0,
				'content_hash'     => '',
				'char_count'       => $char_count,
				'token_estimate'   => (int) round( $char_count / 4 ),
				'chunk_count'      => count( $rows ),
				'embedding_model'  => '',
				'embedding_status' => 'ready',
				'status'           => 'ready',
				'error_message'    => '',
				'created_at'       => $created_at,
				'updated_at'       => $created_at,
				'origin'           => $origin,
				'content_text'     => $content_text,
			];
			return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] get_by_origin error: ' . $e->getMessage() );
			return new WP_Error( 'get_by_origin_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/* ── Phase 0.6 — KG read-switch helper ─────────────────────────────── */

	/**
	 * Read sources from bizcity_kg_sources (Phase 0.6 unified table).
	 * Normalises rows to the same shape the FE expects from the legacy table.
	 *
	 * @param  int   $notebook_id
	 * @param  array $args  { limit: int, search: string }
	 * @return array[]
	 */
	private static function _list_kg_sources( int $notebook_id, array $args ): array {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?: 50 ) ) );

		// Build WHERE clause. Note: scope_id is stored as a string in the KG table.
		// [2026-07-23 Johnny Chu] PHASE-0.43 — include async placeholders while extraction is still processing.
		$where  = $wpdb->prepare( 'scope_type = %s AND scope_id = %s', 'notebook', (string) $notebook_id );
		$params = [];

		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= $wpdb->prepare( ' AND title LIKE %s', $like );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, uuid, title, status, origin_kind, origin_url, origin_id,
			        passage_count, created_at, updated_at
			   FROM {$db->tbl_sources()}
			  WHERE {$where}
			    AND status IN ('active','processing','error')
			  ORDER BY created_at DESC
			  LIMIT {$limit}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) return [];

		// PHASE-0.13 — aggregate graph-extraction completion per source from kg_passages.
		// (TwinChat sources are mirrored as kg_sources; passages belonging to each
		//  source carry `extraction_status` updated by BizCity_KG_Triplet_Extractor.
		//  Note: `kg_source_chunks` is bizdoc-specific and is empty for TwinChat,
		//  which is why the brain badge previously never lit up.)
		//
		// PHASE-0.13 Wave 10c BUGFIX (2026-05-01) — `kg_passages.source_id` historically
		// holds the LEGACY `bizcity_webchat_sources.id` (which equals `kg_sources.origin_id`
		// for mirror rows), NOT the new auto-increment `kg_sources.id`. Aggregating on
		// `kg_sources.id` alone made every source render at 0% even after extraction
		// completed. Fix: query passages on BOTH ids (current + legacy origin_id), then
		// bucket the result back into the canonical `kg_sources.id`.
		$ids = array_values( array_unique( array_filter( array_map(
			static function ( $r ) { return (int) ( $r['id'] ?? 0 ); },
			$rows
		) ) ) );
		$agg_map = [];
		if ( ! empty( $ids ) ) {
			// Build a passage_source_id → kg_sources.id lookup so we can aggregate
			// across both the new kg id and the legacy origin id without losing
			// attribution (canonical bucket = kg_sources.id).
			$lookup     = []; // passage_source_id => kg_sources.id
			$query_ids  = [];
			foreach ( $rows as $r ) {
				$sid = (int) ( $r['id'] ?? 0 );
				$oid = (int) ( $r['origin_id'] ?? 0 );
				if ( $sid <= 0 ) continue;
				if ( ! isset( $lookup[ $sid ] ) ) { $lookup[ $sid ] = $sid; $query_ids[] = $sid; }
				if ( $oid > 0 && ! isset( $lookup[ $oid ] ) ) {
					// Only safe if origin_id doesn't collide with a different source's kg id.
					$lookup[ $oid ] = $sid;
					$query_ids[]   = $oid;
				}
			}
			$query_ids    = array_values( array_unique( $query_ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $query_ids ), '%d' ) );
			$tbl_passages = $db->tbl_passages();
			// [2026-07-23 Johnny Chu] PHASE-0.47-KG-SOURCE-PROGRESS — kg_passages.source_id
			// (and especially origin_id, the legacy webchat_sources.id) is NOT globally
			// unique across notebooks; without a notebook_id filter, another notebook's
			// passages that happen to share the same numeric id got summed into this
			// source's total/done, silently deflating the % shown on the main list even
			// though get_source_learning_log() (which DOES filter by notebook_id) reports
			// the source as 100% done. Same class of bug as the dual-id fix above this,
			// just missing the notebook scope on top of it.
			$agg_sql      = "SELECT source_id,
				COUNT(*) AS total_chunks,
				SUM(CASE WHEN extraction_status = 'done'  THEN 1 ELSE 0 END) AS done_chunks,
				SUM(CASE WHEN extraction_status = 'error' THEN 1 ELSE 0 END) AS error_chunks
				FROM {$tbl_passages}
				WHERE notebook_id = %d AND source_id IN ({$placeholders})
				GROUP BY source_id";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$agg_rows = $wpdb->get_results( $wpdb->prepare( $agg_sql, array_merge( [ $notebook_id ], $query_ids ) ), ARRAY_A );
			if ( is_array( $agg_rows ) ) {
				foreach ( $agg_rows as $a ) {
					$psid = (int) $a['source_id'];
					$kid  = $lookup[ $psid ] ?? 0;
					if ( $kid <= 0 ) continue;
					if ( ! isset( $agg_map[ $kid ] ) ) {
						$agg_map[ $kid ] = [ 'total' => 0, 'done' => 0, 'error' => 0 ];
					}
					$agg_map[ $kid ]['total'] += (int) $a['total_chunks'];
					$agg_map[ $kid ]['done']  += (int) $a['done_chunks'];
					$agg_map[ $kid ]['error'] += (int) $a['error_chunks'];
				}
			}
		}

		return array_map( static function ( $r ) use ( $agg_map ) {
			$kid   = (int) $r['id'];
			$stat  = $agg_map[ $kid ] ?? [ 'total' => 0, 'done' => 0, 'error' => 0 ];
			$total = $stat['total'];
			$done  = $stat['done'];
			// [2026-07-23 Johnny Chu] PHASE-0.43 — fallback chunk_count to extracted passage total when kg_sources.passage_count is stale zero.
			$chunk_count = max( (int) ( $r['passage_count'] ?? 0 ), $total );
			return [
				'id'                  => (int) $r['id'],
				'source_id'           => (int) $r['id'],
				'uuid'                => (string) ( $r['uuid'] ?? '' ),
				'title'               => (string) ( $r['title'] ?? '' ),
				'source_type'         => (string) ( $r['origin_kind'] ?? 'file' ),
				'source_url'          => (string) ( $r['origin_url'] ?? '' ),
				'chunk_count'         => $chunk_count,
				'embedding_status'    => ( (string) ( $r['status'] ?? '' ) === 'processing' ) ? 'processing' : ( ( (string) ( $r['status'] ?? '' ) === 'error' ) ? 'error' : 'ready' ),
				'status'              => (string) ( $r['status'] ?? 'active' ),
				'created_at'          => (string) ( $r['created_at'] ?? '' ),
				'updated_at'          => (string) ( $r['updated_at'] ?? $r['created_at'] ?? '' ),
				'extraction_total'    => $total,
				'extraction_done'     => $done,
				'extraction_error'    => $stat['error'],
				'extraction_complete' => ( $total > 0 && $done >= $total ),
				'extraction_progress' => $total > 0 ? round( $done / $total, 4 ) : 0.0,
			];
		}, $rows );
	}

	/* ── Sprint 5.0d — POST /events/dispatch ───────────────────────────── */

	/**
	 * Whitelist of event_types the FE is allowed to dispatch directly.
	 * Everything else MUST originate server-side (R-EVT-3).
	 *
	 * @var string[]
	 */
	private static $fe_dispatchable_types = [
		'suggestion_clicked',
		'note_pinned',
	];

	/**
	 * POST /events/dispatch — FE-initiated event dispatch (audit trail for user actions).
	 *
	 * Body JSON:
	 *   {
	 *     "event_type":      "suggestion_clicked",   // required, must be whitelisted
	 *     "payload":         { ... },                // required, validated by taxonomy required_fields()
	 *     "notebook_id":     123,                    // optional, sets opts.blog_id-like scope
	 *     "session_id":      "abc-123",              // optional
	 *     "conversation_id": "conv-xyz",             // optional
	 *     "trace_id":        "uuid",                 // optional
	 *     "parent_event_uuid": "uuid"                // optional
	 *   }
	 *
	 * Response: { ok: true, event_uuid: "..." }
	 */
	public function handle_dispatch_event( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return new WP_Error( 'event_bus_missing', 'Twin Event Bus not loaded.', [ 'status' => 500 ] );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];

		$event_type = isset( $body['event_type'] ) ? (string) $body['event_type'] : '';
		$payload    = isset( $body['payload'] ) && is_array( $body['payload'] ) ? $body['payload'] : [];

		if ( $event_type === '' ) {
			return new WP_Error( 'invalid_event_type', 'event_type is required.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $event_type, self::$fe_dispatchable_types, true ) ) {
			return new WP_Error(
				'event_type_not_allowed',
				sprintf( 'event_type "%s" is not FE-dispatchable.', $event_type ),
				[ 'status' => 403 ]
			);
		}

		// Optional notebook scope check (only if notebook_id given).
		$notebook_id = isset( $body['notebook_id'] ) ? (int) $body['notebook_id'] : 0;
		if ( $notebook_id > 0 ) {
			$auth = $this->check_notebook_access( $notebook_id );
			if ( is_wp_error( $auth ) ) return $auth;
		}

		// 2026-04-30 — event_source must be one of the taxonomy-allowed values
		// (see BizCity_Twin_Event_Taxonomy::allowed_sources()). 'user' was rejected
		// because the FE-dispatch surface is part of the twinchat module.
		$opts = [
			'event_source' => 'twinchat',
			'user_id'      => get_current_user_id(),
		];
		if ( ! empty( $body['trace_id'] ) )           $opts['trace_id']           = (string) $body['trace_id'];
		if ( ! empty( $body['conversation_id'] ) )    $opts['conversation_id']    = (string) $body['conversation_id'];
		if ( ! empty( $body['session_id'] ) )         $opts['session_id']         = (string) $body['session_id'];
		if ( ! empty( $body['parent_event_uuid'] ) )  $opts['parent_event_uuid']  = (string) $body['parent_event_uuid'];

		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, $opts );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'event_dispatch_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( [
			'ok'         => true,
			'event_uuid' => $uuid,
		] );
	}

	/**
	 * Wave 0.18.3 — Persona context for a notebook.
	 *
	 * Returns the bound character (id, name, avatar, description, system_prompt
	 * preview, starter prompts derived from greeting_messages) plus any
	 * persona-tool provider chips registered through `BizCity_Persona_Registry`.
	 * Used by the SmartSourcesPanel "Persona Tools" section.
	 *
	 * Response shape:
	 * {
	 *   ok: true,
	 *   data: {
	 *     character: { id, name, slug, avatar, description, system_prompt_excerpt,
	 *                  capabilities[], industries[], starter_prompts[] } | null,
	 *     provider:  { id, label, chips: [{ label, icon, action, payload_schema }] } | null,
	 *     tools:     [{ name, label, description, side_effect, cost_class }]
	 *   }
	 * }
	 */
	public function get_persona_context( WP_REST_Request $request ) {
		// [2026-07-05 Johnny Chu] HOTFIX — support provider-first Twin Guru mode via notebook settings.
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;

		$character = null;
		$provider_payload = null;
		$tools = [];
		$provider_id = '';
		$sticky_provider_id = '';
		$sticky_character_id = 0;

		// [2026-07-05 Johnny Chu] HOTFIX — persona context must follow sticky connector selection first.
		$sticky_key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		$sticky_row = get_user_meta( get_current_user_id(), $sticky_key, true );
		if ( is_array( $sticky_row ) ) {
			$sticky_provider_id = isset( $sticky_row['provider_id'] ) ? sanitize_key( (string) $sticky_row['provider_id'] ) : '';
			$sticky_character_id = (int) ( $sticky_row['character_id'] ?? 0 );
		}

		// [2026-07-06 Johnny Chu] HOTFIX — sticky connector must drive persona tools even when notebook service is unavailable.
		if ( $sticky_provider_id !== '' ) {
			$provider_id = $sticky_provider_id;
		}

		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
			$character_id = 0;
			if ( $sticky_character_id > 0 ) {
				$character_id = $sticky_character_id;
			} elseif ( $sticky_provider_id === '' ) {
				$character_id = (int) ( $nb['character_id'] ?? 0 );
			}
			if ( $character_id > 0 && class_exists( 'BizCity_Character' ) ) {
				$char = BizCity_Character::get( $character_id );
				if ( $char ) {
					// Decode greeting_messages directly from the row (not on the model).
					$starter_prompts = [];
					if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
						$row = BizCity_Knowledge_Database::instance()->get_character( $character_id );
						$raw = is_object( $row ) ? ( $row->greeting_messages ?? '' ) : ( is_array( $row ) ? ( $row['greeting_messages'] ?? '' ) : '' );
						if ( is_string( $raw ) && $raw !== '' ) {
							$decoded = json_decode( $raw, true );
							if ( is_array( $decoded ) ) {
								foreach ( $decoded as $g ) {
									if ( is_string( $g ) && $g !== '' ) {
										$starter_prompts[] = $g;
									} elseif ( is_array( $g ) && ! empty( $g['text'] ) ) {
										$starter_prompts[] = (string) $g['text'];
									}
								}
							}
						}
					}
					$starter_prompts = array_slice( $starter_prompts, 0, 6 );

					$prompt_excerpt = '';
					if ( ! empty( $char->system_prompt ) ) {
						$plain = trim( wp_strip_all_tags( (string) $char->system_prompt ) );
						$prompt_excerpt = mb_substr( $plain, 0, 280 );
						if ( mb_strlen( $plain ) > 280 ) {
							$prompt_excerpt .= '…';
						}
					}

					$character = [
						'id'                   => (int) $char->id,
						'name'                 => (string) $char->name,
						'slug'                 => (string) $char->slug,
						'avatar'               => (string) ( $char->avatar ?? '' ),
						'description'          => (string) ( $char->description ?? '' ),
						'system_prompt_excerpt'=> $prompt_excerpt,
						'capabilities'         => is_array( $char->capabilities ) ? array_values( $char->capabilities ) : [],
						'industries'           => is_array( $char->industries ) ? array_values( $char->industries ) : [],
						'starter_prompts'      => $starter_prompts,
					];

					// Settings JSON may carry a `provider_id` (Wave 0.18 contract).
					$settings = is_array( $char->settings ) ? $char->settings : [];
					$provider_id = isset( $settings['provider_id'] ) ? sanitize_key( (string) $settings['provider_id'] ) : '';
				}
			}

			if ( $sticky_provider_id !== '' ) {
				$provider_id = $sticky_provider_id;
			}

			if ( $provider_id === '' ) {
				$provider_id = $this->notebook_provider_from_settings( is_array( $nb ) ? $nb : [] );
			}

		}

		// [2026-07-06 Johnny Chu] HOTFIX — resolve provider bundle outside KG service gate so direct connector tools always inject.
		if ( $provider_id !== '' ) {
			$bundle = $this->build_provider_bundle( $provider_id );
			if ( ! empty( $bundle ) ) {
				$provider_payload = $bundle['provider'];
				$tools = $bundle['tools'];
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'character' => $character,
				'provider'  => $provider_payload,
				'tools'     => $tools,
			],
		] );
	}

	/**
	 * Resolve direct provider_id from notebook.settings.
	 */
	private function notebook_provider_from_settings( array $nb ): string {
		$settings = $nb['settings'] ?? [];
		if ( is_string( $settings ) ) {
			$decoded = json_decode( $settings, true );
			$settings = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return isset( $settings['twin_guru_provider_id'] )
			? sanitize_key( (string) $settings['twin_guru_provider_id'] )
			: '';
	}

	/**
	 * Build provider payload + tools for FE persona context and picker cards.
	 *
	 * @return array{provider:array,tools:array}|array
	 */
	private function build_provider_bundle( string $provider_id ): array {
		if ( $provider_id === '' || ! class_exists( 'BizCity_Persona_Registry' ) ) {
			return [];
		}
		$provider = BizCity_Persona_Registry::instance()->get( $provider_id );
		if ( ! $provider ) {
			return [];
		}

		$chips = [];
		try {
			$chips = (array) $provider->get_smart_source_chips();
		} catch ( \Throwable $e ) {
			$chips = [];
		}

		$tool_defs = [];
		try {
			$tool_defs = (array) $provider->get_tool_definitions();
		} catch ( \Throwable $e ) {
			$tool_defs = [];
		}

		$tools = [];
		foreach ( $tool_defs as $name => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$tools[] = [
				'name'        => isset( $def['name'] ) ? (string) $def['name'] : (string) $name,
				'label'       => isset( $def['label'] ) ? (string) $def['label'] : (string) $name,
				'description' => isset( $def['description'] ) ? (string) $def['description'] : '',
				'side_effect' => isset( $def['side_effect'] ) ? (string) $def['side_effect'] : '',
				'cost_class'  => isset( $def['cost_class'] ) ? (string) $def['cost_class'] : 'free',
			];
		}

		return [
			'provider' => [
				'id'    => $provider_id,
				'label' => method_exists( $provider, 'label' ) ? (string) $provider->label() : $provider_id,
				'chips' => array_values( $chips ),
			],
			'tools' => $tools,
		];
	}

	/* ── Wave 0.18.5c — Twin Guru picker (composer @-mention) ─────────── */

	/**
	 * GET /gurus/list
	 *
	 * Catalog of active Twin Gurus available to the current user. Used by the
	 * composer `@` picker (TwinGuruDialog). PHASE-0.13-v1 §10.2 contract:
	 * dropdown card list with avatar + name + slug + counts.
	 *
	 * Response:
	 * {
	 *   ok: true,
	 *   data: {
	 *     gurus: [
	 *       { character_id, slug, name, avatar, description,
	 *         system_prompt_excerpt, capabilities[], industries[] }
	 *     ]
	 *   }
	 * }
	 */
	public function list_gurus( WP_REST_Request $request ) {
		// [2026-07-05 Johnny Chu] HOTFIX — provider-only by default; character list is opt-in.
		$include_characters = (bool) rest_sanitize_boolean( $request->get_param( 'include_characters' ) );
		$provider_whitelist = apply_filters(
			'bizcity_twin_guru_provider_whitelist',
			array( 'content-creator', 'twinsearch', 'bizcoach_pro', 'bizcoach_astro' ),
			get_current_user_id(),
			$request
		);
		if ( ! is_array( $provider_whitelist ) ) {
			$provider_whitelist = array();
		}

		$out = $this->build_provider_guru_rows( $provider_whitelist );

		if ( ! class_exists( 'BizCity_Knowledge_Database' ) || ! $include_characters ) {
			$out = apply_filters( 'bizcity_twin_guru_catalog', $out, get_current_user_id() );
			return rest_ensure_response( [ 'ok' => true, 'data' => [ 'gurus' => array_values( $out ) ] ] );
		}

		$db   = BizCity_Knowledge_Database::instance();
		$rows = $db->get_characters( [ 'status' => 'active', 'limit' => 200 ] );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$cid = (int) ( is_object( $row ) ? ( $row->id ?? 0 ) : ( $row['id'] ?? 0 ) );
				if ( $cid <= 0 ) continue;
				$prompt = (string) ( is_object( $row ) ? ( $row->system_prompt ?? '' ) : ( $row['system_prompt'] ?? '' ) );
				$plain  = trim( wp_strip_all_tags( $prompt ) );
				$excerpt = mb_substr( $plain, 0, 160 );
				if ( mb_strlen( $plain ) > 160 ) $excerpt .= '…';
				$caps  = json_decode( (string) ( is_object( $row ) ? ( $row->capabilities ?? '' ) : ( $row['capabilities'] ?? '' ) ), true );
				$inds  = json_decode( (string) ( is_object( $row ) ? ( $row->industries   ?? '' ) : ( $row['industries']   ?? '' ) ), true );
				$out[] = [
					'character_id'         => $cid,
					'slug'                 => (string) ( is_object( $row ) ? ( $row->slug ?? '' ) : ( $row['slug'] ?? '' ) ),
					'name'                 => (string) ( is_object( $row ) ? ( $row->name ?? '' ) : ( $row['name'] ?? '' ) ),
					'avatar'               => (string) ( is_object( $row ) ? ( $row->avatar ?? '' ) : ( $row['avatar'] ?? '' ) ),
					'description'          => (string) ( is_object( $row ) ? ( $row->description ?? '' ) : ( $row['description'] ?? '' ) ),
					'system_prompt_excerpt' => $excerpt,
					'capabilities'         => is_array( $caps ) ? array_values( $caps ) : [],
					'industries'           => is_array( $inds ) ? array_values( $inds ) : [],
				];
			}
		}

		// Allow plugins to filter / extend the catalog.
		$out = apply_filters( 'bizcity_twin_guru_catalog', $out, get_current_user_id() );
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'gurus' => array_values( $out ) ] ] );
	}

	/**
	 * Build virtual provider-first Twin Guru rows.
	 *
	 * @param array<int|string,mixed> $allowed_provider_ids Optional whitelist.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_provider_guru_rows( array $allowed_provider_ids ): array {
		$out = array();
		if ( ! class_exists( 'BizCity_Persona_Registry' ) ) {
			return $out;
		}

		$allowed = array();
		foreach ( $allowed_provider_ids as $pid ) {
			$pid = sanitize_key( (string) $pid );
			if ( $pid !== '' ) {
				$allowed[] = $pid;
			}
		}
		$allowed   = array_values( array_unique( $allowed ) );
		$order_map = array_flip( $allowed );
		$use_allow = ! empty( $order_map );

		foreach ( BizCity_Persona_Registry::instance()->all() as $slug => $provider ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			if ( $use_allow && ! isset( $order_map[ $slug ] ) ) {
				continue;
			}

			$out[] = [
				'character_id'          => 0,
				'provider_id'           => $slug,
				'slug'                  => $slug,
				'name'                  => method_exists( $provider, 'label' ) ? (string) $provider->label() : $slug,
				'avatar'                => '',
				// [2026-07-06 Johnny Chu] HOTFIX — unify provider card branding text to Twin Connector.
				'description'           => 'BizCoach trực tiếp theo provider — không cần tạo character.',
				'system_prompt_excerpt' => '',
				'capabilities'          => [],
				'industries'            => [],
				'mode'                  => 'provider',
			];
		}

		if ( $use_allow && count( $out ) > 1 ) {
			usort(
				$out,
				static function ( array $a, array $b ) use ( $order_map ): int {
					$ia = isset( $order_map[ (string) ( $a['provider_id'] ?? '' ) ] ) ? (int) $order_map[ (string) ( $a['provider_id'] ?? '' ) ] : 9999;
					$ib = isset( $order_map[ (string) ( $b['provider_id'] ?? '' ) ] ) ? (int) $order_map[ (string) ( $b['provider_id'] ?? '' ) ] : 9999;
					if ( $ia === $ib ) {
						return strcmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
					}
					return ( $ia < $ib ) ? -1 : 1;
				}
			);
		}

		return $out;
	}

	/**
	 * GET /notebooks/{id}/sticky-guru
	 *
	 * Returns the per-(user, notebook) sticky Guru pinned via the @-picker.
	 * Stored as user_meta `bizcity_twin_sticky_guru_<notebook_id>`.
	 */
	public function get_sticky_guru( WP_REST_Request $request ) {
		// [2026-07-05 Johnny Chu] HOTFIX — support sticky provider_id without character_id.
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		$row = get_user_meta( get_current_user_id(), $key, true );
		if ( ! is_array( $row ) ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => null ] ] );
		}
		$cid = (int) ( $row['character_id'] ?? 0 );
		$provider_id = isset( $row['provider_id'] ) ? sanitize_key( (string) $row['provider_id'] ) : '';
		if ( $cid <= 0 && $provider_id === '' ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => null ] ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => [
			'character_id'   => $cid,
			'provider_id'    => $provider_id,
			'provider_label' => (string) ( $row['provider_label'] ?? '' ),
			'character_slug' => (string) ( $row['character_slug'] ?? $provider_id ),
			'character_name' => (string) ( $row['character_name'] ?? '' ),
			'avatar_url'     => (string) ( $row['avatar_url'] ?? '' ),
			'set_at'         => (int) ( $row['set_at'] ?? 0 ),
			'source'         => (string) ( $row['source'] ?? 'mention' ),
		] ] ] );
	}

	/**
	 * POST /notebooks/{id}/sticky-guru
	 * Body: { character_id?, provider_id?, character_slug?, character_name?, avatar_url? }
	 */
	public function set_sticky_guru( WP_REST_Request $request ) {
		// [2026-07-05 Johnny Chu] HOTFIX — permit sticky provider-only Twin Guru.
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];
		$cid = (int) ( $body['character_id'] ?? 0 );
		$provider_id = isset( $body['provider_id'] ) ? sanitize_key( (string) $body['provider_id'] ) : '';
		if ( $cid <= 0 && $provider_id === '' ) {
			return new WP_Error( 'invalid_guru', 'character_id or provider_id is required', [ 'status' => 400 ] );
		}
		// Validate character exists + active.
		if ( class_exists( 'BizCity_Character' ) ) {
			if ( $cid > 0 ) {
				$char = BizCity_Character::get( $cid );
				if ( ! $char ) {
					return new WP_Error( 'character_not_found', 'Character not found', [ 'status' => 404 ] );
				}
			}
		}
		if ( $provider_id !== '' && class_exists( 'BizCity_Persona_Registry' ) ) {
			$provider = BizCity_Persona_Registry::instance()->get( $provider_id );
			if ( ! $provider ) {
				return new WP_Error( 'provider_not_found', 'Provider not found', [ 'status' => 404 ] );
			}
		}
		$payload = [
			'character_id'   => $cid,
			'provider_id'    => $provider_id,
			'provider_label' => isset( $body['provider_label'] ) ? sanitize_text_field( (string) $body['provider_label'] ) : '',
			'character_slug' => isset( $body['character_slug'] ) ? sanitize_key( (string) $body['character_slug'] ) : '',
			'character_name' => isset( $body['character_name'] ) ? sanitize_text_field( (string) $body['character_name'] ) : '',
			'avatar_url'     => isset( $body['avatar_url'] ) ? esc_url_raw( (string) $body['avatar_url'] ) : '',
			'set_at'         => time(),
			'source'         => 'mention',
		];
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		update_user_meta( get_current_user_id(), $key, $payload );
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'guru' => $payload ] ] );
	}

	/**
	 * DELETE /notebooks/{id}/sticky-guru
	 */
	public function clear_sticky_guru( WP_REST_Request $request ) {
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $notebook_id );
		if ( is_wp_error( $auth ) ) return $auth;
		$key = 'bizcity_twin_sticky_guru_' . $notebook_id;
		delete_user_meta( get_current_user_id(), $key );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/* ── PHASE 0.31 T-S3.2 — Per-passage actions ─────────────────────── */

	/**
	 * Look up `notebook_id` for a passage and verify access.
	 * Returns array{passage_id, notebook_id} on success, WP_Error otherwise.
	 */
	private function load_passage_with_access( int $passage_id ) {
		global $wpdb;
		if ( $passage_id <= 0 ) {
			return new WP_Error( 'invalid_passage', 'Invalid passage_id', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG-Hub not loaded', [ 'status' => 503 ] );
		}
		$db  = BizCity_KG_Database::instance();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id FROM {$db->tbl_passages()} WHERE id = %d LIMIT 1",
			$passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'passage_not_found', 'Passage not found', [ 'status' => 404 ] );
		}
		$auth = $this->check_notebook_access( (int) $row['notebook_id'] );
		if ( is_wp_error( $auth ) ) return $auth;
		return [
			'passage_id'  => (int) $row['id'],
			'notebook_id' => (int) $row['notebook_id'],
		];
	}

	/**
	 * POST /passages/{id}/tag
	 * Body: { tag: string, action?: 'added'|'removed' }
	 * Adds/removes a tag on the passage's metadata.tags and fires note_tagged.
	 */
	public function tag_passage( WP_REST_Request $request ) {
		$ctx = $this->load_passage_with_access( (int) $request->get_param( 'passage_id' ) );
		if ( is_wp_error( $ctx ) ) return $ctx;

		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) { $body = []; }
		$tag    = isset( $body['tag'] )    ? sanitize_text_field( (string) $body['tag'] )    : '';
		$action = isset( $body['action'] ) ? (string) $body['action'] : 'added';
		$action = ( $action === 'removed' ) ? 'removed' : 'added';

		if ( $tag === '' ) {
			return new WP_Error( 'tag_required', 'Body must include non-empty `tag`.', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Source_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG service unavailable', [ 'status' => 503 ] );
		}
		$result = BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, $action );
		if ( is_wp_error( $result ) ) return $result;

		return rest_ensure_response( [
			'ok'          => true,
			'passage_id'  => $ctx['passage_id'],
			'notebook_id' => $ctx['notebook_id'],
			'tag'         => strtolower( trim( $tag ) ),
			'action'      => $action,
		] );
	}

	/**
	 * POST /passages/{id}/trigger-workflow
	 * Body: { tag?: string }  (default: filterable, fallback `#trigger`)
	 *
	 * Implementation: tag the passage with the reserved "trigger" tag so any
	 * workflow whose `nb_note_tagged` trigger filters on that tag fires. This
	 * keeps a single mechanism (the existing trigger) instead of inventing a
	 * second event channel.
	 */
	public function trigger_workflow_for_passage( WP_REST_Request $request ) {
		$ctx = $this->load_passage_with_access( (int) $request->get_param( 'passage_id' ) );
		if ( is_wp_error( $ctx ) ) return $ctx;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { $body = []; }
		$tag  = isset( $body['tag'] ) && trim( (string) $body['tag'] ) !== ''
			? sanitize_text_field( (string) $body['tag'] )
			: apply_filters( 'bizcity_twin_default_trigger_tag', 'trigger' );

		if ( ! class_exists( 'BizCity_KG_Source_Service' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG service unavailable', [ 'status' => 503 ] );
		}

		// Force-add (re-add idempotent: the service no-ops if already present, which
		// would not fire the event again — so for retriggering we remove-then-add).
		BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, 'removed' );
		$result = BizCity_KG_Source_Service::instance()->tag_passage( $ctx['passage_id'], $tag, 'added' );
		if ( is_wp_error( $result ) ) return $result;

		return rest_ensure_response( [
			'ok'          => true,
			'passage_id'  => $ctx['passage_id'],
			'notebook_id' => $ctx['notebook_id'],
			'tag'         => strtolower( trim( $tag ) ),
			'note'        => 'Fired bizcity_twin_notebook_event(note_tagged); workflows with matching nb_note_tagged trigger will queue.',
		] );
	}
}
