<?php
/**
 * Bizcity Twin AI — KG-Hub Scoped REST Controller
 *
 * Routes plugin-agnostic ingest/list/attach calls through `BizCity_KG`.
 * All routes mounted under `bizcity-knowledge/v2/scoped/...`
 *
 * Endpoints (PHASE-0-RULE-KG-HUB-CONTRACT.md §3.2):
 *   GET    /scoped/registry                         → list registered plugins
 *   GET    /scoped/scopes/available?plugin=...      → user-accessible scopes
 *   GET    /scoped/{plugin}/{scope_id}/sources      → list sources
 *   POST   /scoped/{plugin}/{scope_id}/sources      → ingest (file/url/text)
 *   DELETE /scoped/{plugin}/{scope_id}/sources/(?P<source_id>\d+)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Scoped_REST_Controller {

	const NAMESPACE_V2 = 'bizcity-knowledge/v2';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE_V2, '/scoped/registry', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_registry' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE_V2, '/scoped/scopes/available', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_available_scopes' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'plugin'     => [ 'type' => 'string', 'required' => false ],
				'scope_type' => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_sources' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'ingest_source' ],
					'permission_callback' => [ $this, 'check_logged_in' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources/(?P<source_id>\d+)',
			[
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
			]
		);

		// Cross-scope catalog for KGSourcePicker (Hình thức A).
		register_rest_route( self::NAMESPACE_V2, '/scoped/sources/all', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_all_sources' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'plugin'          => [ 'type' => 'string', 'required' => false ],
				'scope_type'      => [ 'type' => 'string', 'required' => false ],
				'search'          => [ 'type' => 'string', 'required' => false ],
				'limit_per_scope' => [ 'type' => 'integer', 'required' => false ],
			],
		] );

		// Attach an existing source from another scope into the current scope.
		register_rest_route(
			self::NAMESPACE_V2,
			'/scoped/(?P<plugin>[a-z0-9_\-]+)/(?P<scope_id>\d+)/sources/attach',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'attach_source' ],
				'permission_callback' => [ $this, 'check_logged_in' ],
			]
		);
	}

	/* ──────────────────────  Permission  ────────────────────── */

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	/* ──────────────────────  Handlers  ────────────────────── */

	public function get_registry() {
		$reg = BizCity_KG::register();
		// Strip non-serializable callbacks.
		$out = [];
		foreach ( $reg as $slug => $entry ) {
			unset( $entry['list_scopes_cb'] );
			$out[] = $entry;
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
	}

	public function get_available_scopes( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$ctx     = [];
		if ( $req->get_param( 'plugin' ) ) {
			$ctx['plugin'] = sanitize_key( (string) $req->get_param( 'plugin' ) );
		}
		if ( $req->get_param( 'scope_type' ) ) {
			$ctx['scope_type'] = sanitize_key( (string) $req->get_param( 'scope_type' ) );
		}
		$scopes = BizCity_KG::available_scopes( $user_id, $ctx );
		return rest_ensure_response( [ 'ok' => true, 'data' => $scopes ] );
	}

	public function list_sources( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$args = [
			'limit'  => (int) ( $req->get_param( 'limit' ) ?: 50 ),
			'offset' => (int) ( $req->get_param( 'offset' ) ?: 0 ),
			'search' => (string) ( $req->get_param( 'search' ) ?: '' ),
		];
		$rows = BizCity_KG::list_sources( $scope, $args );
		if ( is_wp_error( $rows ) ) return $rows;
		return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
	}

	public function ingest_source( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		// multipart vs JSON: prefer body params (POST fields), fall back to JSON.
		$body = $req->get_body_params();
		if ( empty( $body ) ) {
			$json = $req->get_json_params();
			if ( is_array( $json ) ) {
				$body = $json;
			}
		}
		if ( ! is_array( $body ) ) $body = [];

		$type = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : 'text';

		$payload = [
			'type'          => $type,
			'title'         => isset( $body['title'] ) ? (string) $body['title'] : '',
			'content'       => isset( $body['content'] ) ? (string) $body['content'] : '',
			'url'           => isset( $body['url'] ) ? (string) $body['url'] : '',
			'attachment_id' => isset( $body['attachment_id'] ) ? (int) $body['attachment_id'] : 0,
			'metadata'      => isset( $body['metadata'] ) && is_array( $body['metadata'] ) ? $body['metadata'] : [],
		];

		// File upload (multipart).
		$files = $req->get_file_params();
		if ( $type === 'file' && ! empty( $files['file'] ) ) {
			$payload['file'] = $files['file'];
		}

		$res = BizCity_KG::ingest( $scope, $payload );
		if ( is_wp_error( $res ) ) return self::normalize_ingest_error( $res );
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	/**
	 * Phase 0.7 / Wave UI-ERR — translate adapter / tier WP_Errors into proper
	 * HTTP status codes so the SourcesPanel receives 4xx (not 500) and can
	 * render an actionable upgrade/retry message instead of a raw stack.
	 *
	 * Maps:
	 *   tier_required               → 402 Payment Required
	 *   insufficient_credit         → 402
	 *   pdf_extract_empty           → 422 Unprocessable Entity (scan PDF)
	 *   office_adapter_pending      → 422
	 *   adapter_empty               → 422
	 *   unsupported_ext             → 415 Unsupported Media Type
	 *   file_missing|file_read_failed → 400
	 *   pdf_file_*                  → 400
	 *   anything else with explicit data.http_status → honor it
	 *   fallback                    → 500
	 *
	 * Honors `data.http_status` set by adapters (see PDF/Office adapters).
	 */
	private static function normalize_ingest_error( WP_Error $err ) {
		$code = $err->get_error_code();
		$data = (array) $err->get_error_data();
		$explicit = isset( $data['http_status'] ) ? (int) $data['http_status'] : 0;
		$map = [
			'tier_required'          => 402,
			'insufficient_credit'    => 402,
			'quota_exceeded_free'    => 402,
			'pdf_extract_empty'      => 422,
			'office_adapter_pending' => 422, // legacy stub code (kept for back-compat)
			'office_extract_empty'   => 422,
			'office_docx_empty'      => 422,
			'office_xlsx_empty'      => 422,
			'office_pptx_empty'      => 422,
			'office_rtf_empty'       => 422,
			'office_xlsx_no_sheets'  => 422,
			'office_unknown_format'  => 415,
			'office_unsupported_kind'=> 415,
			'office_zip_missing'     => 500,
			'office_zip_open_failed' => 422,
			'office_file_too_large'  => 413,
			'adapter_empty'          => 422,
			// URL / web import errors (Wave 0.7 — surface 422/502 instead of 500).
			'no_url'                 => 400,
			'url_fetch_failed'       => 502,
			'url_empty'              => 422,
			'url_empty_text'         => 422,
			'youtube_no_captions'    => 422,
			'youtube_invalid_url'    => 400,
			'youtube_not_a_yt_url'   => 400,
			'youtube_player_response_missing' => 422,
			'youtube_player_response_invalid' => 422,
			'youtube_empty_transcript'        => 422,
			// Wave E0.AV — audio/video adapter
			'av_file_missing'        => 400,
			'av_file_unreadable'     => 400,
			'av_file_too_large'      => 413,
			'av_invalid_kind'        => 400,
			'av_missing_media_url'   => 400,
			'av_no_public_url'       => 500,
			'av_tmp_copy_failed'     => 500,
			'av_sideload_failed'     => 500,
			'av_client_missing'      => 500,
			'av_not_configured'      => 503,
			'av_transport_error'     => 502,
			'av_provider_error'      => 502,
			'av_invalid_response'    => 502,
			'av_no_speech'           => 422,
			'unsupported_ext'        => 415,
			'file_missing'           => 400,
			'file_read_failed'       => 400,
			'pdf_file_missing'       => 400,
			'pdf_file_unreadable'    => 400,
			'invalid_scope'          => 400,
			'file_too_large'         => 413,
		];
		$status = $explicit ?: ( isset( $map[ $code ] ) ? $map[ $code ] : 500 );
		$data['status'] = $status; // WP REST reads `data.status`
		return new WP_Error( $code, $err->get_error_message(), $data );
	}

	public function delete_source( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$source_id = (int) $req->get_param( 'source_id' );
		$res = BizCity_KG::delete_source( $scope, $source_id );
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( [ 'ok' => (bool) $res ] );
	}

	public function get_source( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		if ( is_wp_error( $scope ) ) return $scope;

		$source_id = (int) $req->get_param( 'source_id' );
		$res = BizCity_KG::get_source( $scope, $source_id );
		if ( is_wp_error( $res ) ) return $res;
		if ( ! $res ) {
			return new WP_Error( 'not_found', 'Source not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	public function list_all_sources( WP_REST_Request $req ) {
		$args = [
			'plugin'          => (string) ( $req->get_param( 'plugin' ) ?: '' ),
			'scope_type'      => (string) ( $req->get_param( 'scope_type' ) ?: '' ),
			'search'          => (string) ( $req->get_param( 'search' ) ?: '' ),
			'limit_per_scope' => (int) ( $req->get_param( 'limit_per_scope' ) ?: 50 ),
		];
		$rows = BizCity_KG::list_all_sources( get_current_user_id(), $args );
		return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
	}

	public function attach_source( WP_REST_Request $req ) {
		$dest = $this->resolve_scope( $req );
		if ( is_wp_error( $dest ) ) return $dest;

		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) $body = $req->get_body_params();
		if ( ! is_array( $body ) ) $body = [];

		$from_plugin   = isset( $body['from_plugin'] ) ? sanitize_key( (string) $body['from_plugin'] ) : '';
		$from_scope_id = isset( $body['from_scope_id'] ) ? (int) $body['from_scope_id'] : 0;
		$source_id     = isset( $body['source_id'] ) ? (int) $body['source_id'] : 0;
		if ( $from_plugin === '' || $from_scope_id <= 0 || $source_id <= 0 ) {
			return new WP_Error( 'invalid_attach', 'from_plugin + from_scope_id + source_id required', [ 'status' => 400 ] );
		}

		$res = BizCity_KG::attach_source(
			[ 'plugin' => $from_plugin, 'scope_id' => $from_scope_id ],
			$source_id,
			$dest
		);
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	/* ──────────────────────  internals  ────────────────────── */

	private function resolve_scope( WP_REST_Request $req ) {
		$plugin   = sanitize_key( (string) $req->get_param( 'plugin' ) );
		$scope_id = (int) $req->get_param( 'scope_id' );
		if ( $plugin === '' || $scope_id <= 0 ) {
			return new WP_Error( 'invalid_scope', 'plugin + scope_id required', [ 'status' => 400 ] );
		}
		$scope = [ 'plugin' => $plugin, 'scope_id' => $scope_id ];
		// SECURITY: verify the current user is allowed to access this scope before
		// allowing any read or write operation on the underlying KG data.
		if ( ! $this->authorize_scope( $scope ) ) {
			return new WP_Error( 'forbidden', 'Scope not accessible.', [ 'status' => 403 ] );
		}
		return $scope;
	}

	/**
	 * Returns true when the current user can access the given plugin scope.
	 * Delegates to BizCity_KG::available_scopes() so each plugin's registered
	 * list_scopes_cb enforces its own ownership rules.
	 */
	private function authorize_scope( array $scope ) {
		$user_id = get_current_user_id();
		if ( user_can( $user_id, 'manage_options' ) ) return true; // admins bypass
		$available = BizCity_KG::available_scopes( $user_id, [ 'plugin' => $scope['plugin'] ] );
		foreach ( $available as $s ) {
			if ( (int) $s['scope_id'] === (int) $scope['scope_id'] ) return true;
		}
		return false;
	}
}
