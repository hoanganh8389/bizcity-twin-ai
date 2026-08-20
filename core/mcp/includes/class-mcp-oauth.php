<?php
/**
 * BizCity_MCP_OAuth — OAuth 2.1 compatibility bridge for MCP hosts.
 *
 * The native MCP credential remains a local Bearer API key. This adapter
 * lets OAuth-only hosts obtain a short-lived Bearer access token after the
 * WordPress user approves the requested MCP scopes. No provider credential
 * or plaintext MCP key is exposed to the OAuth client.
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-28 (PHASE-0.53-MCP OAuth bridge)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_MCP_OAuth' ) ) { return; }

final class BizCity_MCP_OAuth {

	const NS                    = 'bizcity-mcp/v1';
	const CLIENT_TTL            = 2592000;
	const CODE_TTL              = 300;
	const TOKEN_TTL             = 3600;
	const CONSENT_ACTION        = 'bizcity_mcp_oauth_consent';
	const AUTHORIZATION_ENDPOINT = '/oauth/authorize';
	const TOKEN_ENDPOINT         = '/oauth/token';
	const REGISTRATION_ENDPOINT  = '/oauth/register';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_html' ), 10, 4 );
		add_action( 'parse_request', array( __CLASS__, 'serve_well_known' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'preserve_login_redirect' ), 1, 3 );
	}

	public static function preserve_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		unset( $user );
		$requested = (string) $requested_redirect_to;
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — preserve the authorize request through custom login pages so OAuth consent can resume after authentication.
		if ( strpos( $requested, '/' . self::NS . self::AUTHORIZATION_ENDPOINT ) === false ) {
			return $redirect_to;
		}
		return wp_validate_redirect( $requested, home_url( '/' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, self::AUTHORIZATION_ENDPOINT, array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'authorize' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'authorize' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( self::NS, self::TOKEN_ENDPOINT, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'token' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, self::REGISTRATION_ENDPOINT, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'register_client' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function issuer_url() {
		return rest_url( self::NS );
	}

	public static function protected_resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	public static function authorization_server_metadata() {
		return array(
			'issuer'                           => self::issuer_url(),
			'authorization_endpoint'           => rest_url( self::NS . self::AUTHORIZATION_ENDPOINT ),
			'token_endpoint'                   => rest_url( self::NS . self::TOKEN_ENDPOINT ),
			'registration_endpoint'            => rest_url( self::NS . self::REGISTRATION_ENDPOINT ),
			'response_types_supported'         => array( 'code' ),
			'grant_types_supported'            => array( 'authorization_code' ),
			'code_challenge_methods_supported' => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                 => self::supported_scopes(),
		);
	}

	public static function protected_resource_metadata() {
		return array(
			'resource'                  => rest_url( self::NS . '/mcp' ),
			'authorization_servers'     => array( self::issuer_url() ),
			'scopes_supported'          => self::supported_scopes(),
			'bearer_methods_supported'  => array( 'header' ),
		);
	}

	public static function serve_well_known( $wp ) {
		unset( $wp );
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$path = '/' . trim( $path, '/' );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — serve OAuth POST routes before REST dispatch so generic multisite POST guards cannot block ChatGPT token/registration exchange.
		if ( self::maybe_serve_oauth_post_routes( $path ) ) {
			return;
		}
		$payload = null;
		if ( $path === '/.well-known/oauth-protected-resource' ) {
			$payload = self::protected_resource_metadata();
		} elseif ( $path === '/.well-known/oauth-authorization-server' ) {
			$payload = self::authorization_server_metadata();
		}
		if ( ! is_array( $payload ) ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload );
		exit;
	}

	public static function register_client( WP_REST_Request $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		$body = is_array( $body ) ? $body : array();
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$redirect_uris = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) ) );
		if ( empty( $redirect_uris ) || count( $redirect_uris ) > 10 ) {
			return self::oauth_error( 'invalid_client_metadata', 'redirect_uris phải có từ 1 đến 10 URL hợp lệ.', 400 );
		}
		foreach ( $redirect_uris as $redirect_uri ) {
			$scheme = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_SCHEME ) );
			$host   = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) );
			if ( $scheme !== 'https' && ! ( $scheme === 'http' && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) ) {
				return self::oauth_error( 'invalid_client_metadata', 'redirect_uri phải dùng HTTPS; localhost chỉ dùng cho môi trường cục bộ.', 400 );
			}
		}
		$client_id = 'mcp_client_' . wp_generate_password( 32, false, false );
		$record = array(
			'client_id'     => $client_id,
			'client_name'   => sanitize_text_field( isset( $body['client_name'] ) ? $body['client_name'] : 'MCP client' ),
			'redirect_uris' => $redirect_uris,
			'created_at'    => time(),
		);
		set_transient( self::client_key( $client_id ), $record, self::CLIENT_TTL );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — retain DCR records in the current site option so object-cache eviction cannot invalidate an active connector client.
		$clients = get_option( 'bizcity_mcp_oauth_clients', array() );
		$clients = is_array( $clients ) ? $clients : array();
		$clients[ $client_id ] = $record;
		update_option( 'bizcity_mcp_oauth_clients', $clients, false );
		return new WP_REST_Response( array(
			'client_id'                  => $client_id,
			'client_name'                => $record['client_name'],
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'client_id_issued_at'        => time(),
			'client_secret_expires_at'   => 0,
		), 201 );
	}

	public static function authorize( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — OAuth `state` is opaque and must be echoed back byte-for-byte; sanitize_text_field() strips `%xx` sequences and can break ChatGPT callback validation.
		$state_param = $request->get_param( 'state' );
		$state       = is_scalar( $state_param ) ? (string) $state_param : '';
		if ( strlen( $state ) > 2048 ) {
			return self::oauth_html_error( 'Tham số state OAuth không hợp lệ hoặc quá dài.', 400 );
		}
		$params = array(
			'client_id'             => sanitize_text_field( (string) $request->get_param( 'client_id' ) ),
			'redirect_uri'          => esc_url_raw( (string) $request->get_param( 'redirect_uri' ) ),
			'response_type'         => sanitize_key( (string) $request->get_param( 'response_type' ) ),
			'scope'                 => sanitize_text_field( (string) $request->get_param( 'scope' ) ),
			'state'                 => $state,
			'code_challenge'        => sanitize_text_field( (string) $request->get_param( 'code_challenge' ) ),
			'code_challenge_method' => sanitize_key( (string) $request->get_param( 'code_challenge_method' ) ),
			'resource'              => esc_url_raw( (string) $request->get_param( 'resource' ) ),
		);
		$client = self::get_client( $params['client_id'] );
		if ( ! $client ) {
			return self::oauth_html_error( 'OAuth client chưa được đăng ký hoặc đã hết hạn. Hãy tạo Client ID mới.', 400 );
		}
		if ( ! in_array( $params['redirect_uri'], (array) $client['redirect_uris'], true ) ) {
			return self::oauth_html_error( 'Redirect URI không khớp Client ID đã đăng ký. Hãy đăng ký lại đúng Callback URL của ChatGPT.', 400 );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — reject a resource indicator that targets another MCP resource server.
		if ( $params['resource'] !== '' && untrailingslashit( $params['resource'] ) !== untrailingslashit( rest_url( self::NS . '/mcp' ) ) ) {
			return self::oauth_html_error( 'Resource OAuth không trỏ tới MCP server này.', 400 );
		}
		$redirect_host = strtolower( (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST ) );
		$is_chatgpt_client =
			stripos( (string) ( $client['client_name'] ?? '' ), 'chatgpt' ) !== false ||
			$redirect_host === 'chatgpt.com' ||
			substr( $redirect_host, -strlen( '.chatgpt.com' ) ) === '.chatgpt.com';
		$chatgpt_no_pkce =
			$is_chatgpt_client &&
			( $params['code_challenge'] === '' || $params['code_challenge_method'] !== 'S256' );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — ChatGPT manual connectors may omit PKCE; keep S256 mandatory for every non-ChatGPT OAuth client.
		if ( $params['response_type'] !== 'code' || ( ! $chatgpt_no_pkce && ( $params['code_challenge'] === '' || $params['code_challenge_method'] !== 'S256' ) ) ) {
			return self::oauth_html_error( 'OAuth phải dùng authorization code và PKCE S256; ChatGPT manual connector được hỗ trợ tương thích.', 400 );
		}
		$scopes = self::normalize_scopes( $params['scope'] );
		if ( empty( $scopes ) ) {
			return self::oauth_html_error( 'MCP client chưa yêu cầu scope hợp lệ.', 400 );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — REST cookie auth may downgrade current_user to 0 when nonce is missing; recover user from LOGGED_IN_COOKIE for browser-based OAuth authorize only.
		self::prime_user_from_login_cookie();
		if ( ! is_user_logged_in() ) {
			$login_url = self::mapped_login_url( self::current_url() );
			return self::redirect_response( $login_url );
		}
		$consent = sanitize_key( (string) $request->get_param( 'consent' ) );
		if ( $consent !== '' ) {
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — use a dedicated consent nonce field so REST does not treat it as wp_rest cookie nonce.
			$nonce = sanitize_text_field( (string) $request->get_param( '_bizcity_oauth_nonce' ) );
			if ( ! wp_verify_nonce( $nonce, self::CONSENT_ACTION ) ) {
				return self::oauth_html_error( 'Phiên xác nhận OAuth đã hết hạn.', 403 );
			}
			if ( $consent !== 'allow' ) {
				return self::redirect_with_error( $params, 'access_denied', 'Người dùng không cấp quyền MCP.' );
			}
			$code = wp_generate_password( 64, false, false );
			set_transient( self::code_key( $code ), array(
				'client_id'             => $params['client_id'],
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $params['redirect_uri'],
				'scope'                 => $scopes,
				'code_challenge'        => $params['code_challenge'],
				'code_challenge_method' => $chatgpt_no_pkce ? '' : 'S256',
				'pkce_compat'           => $chatgpt_no_pkce,
				'resource'              => $params['resource'],
				'created_at'            => time(),
			), self::CODE_TTL );
			return self::redirect_response( self::append_query( $params['redirect_uri'], array( 'code' => $code, 'state' => $params['state'] ) ) );
		}
		return self::consent_response( $params, $client, $scopes );
	}

	public static function token( WP_REST_Request $request ) {
		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — write structured OAuth token endpoint evidence to JSONL for multishard-safe debugging.
		$started_at = microtime( true );
		$grant_type    = sanitize_text_field( (string) $request->get_param( 'grant_type' ) );
		$code          = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$client_id     = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — some OAuth clients place client_id in Basic auth even for `token_endpoint_auth_method=none`.
		if ( $client_id === '' ) {
			$client_id = self::client_id_from_authorization_header( $request );
		}
		$redirect_uri  = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
		$verifier      = sanitize_text_field( (string) $request->get_param( 'code_verifier' ) );
		$log_ctx       = array(
			'grant_type'       => $grant_type,
			'client_id'        => $client_id,
			'redirect_host'    => (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ),
			'code_present'     => $code !== '',
			'verifier_present' => $verifier !== '',
		);
		if ( $grant_type !== 'authorization_code' || $code === '' || $client_id === '' ) {
			return self::oauth_token_error( 'invalid_request', 'Token request thiếu trường OAuth bắt buộc.', 400, 'missing_required', $log_ctx, $started_at );
		}
		$grant = get_transient( self::code_key( $code ) );
		if ( ! is_array( $grant ) || $grant['client_id'] !== $client_id ) {
			return self::oauth_token_error( 'invalid_grant', 'Authorization code không hợp lệ hoặc đã hết hạn.', 400, 'grant_not_found_or_client_mismatch', $log_ctx, $started_at );
		}
		$log_ctx['user_id']              = (int) ( $grant['user_id'] ?? 0 );
		$log_ctx['pkce_compat']          = ! empty( $grant['pkce_compat'] );
		$log_ctx['scope_requested_count'] = count( (array) ( $grant['scope'] ?? array() ) );
		if ( ! empty( $grant['redirect_uri'] ) ) {
			$log_ctx['redirect_host'] = (string) wp_parse_url( (string) $grant['redirect_uri'], PHP_URL_HOST );
		}
		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — keep redirect_uri binding but tolerate equivalent URL forms used by OAuth clients.
		if ( $redirect_uri === '' ) {
			$redirect_uri = (string) ( $grant['redirect_uri'] ?? '' );
		}
		if ( $redirect_uri === '' || ! self::redirect_uri_matches( (string) $grant['redirect_uri'], $redirect_uri ) ) {
			return self::oauth_token_error( 'invalid_grant', 'Authorization code không hợp lệ hoặc đã hết hạn.', 400, 'redirect_uri_mismatch', $log_ctx, $started_at );
		}
		if ( empty( $grant['pkce_compat'] ) ) {
			if ( $verifier === '' ) {
				return self::oauth_token_error( 'invalid_request', 'Token request thiếu code_verifier PKCE.', 400, 'pkce_verifier_missing', $log_ctx, $started_at );
			}
			$challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
			if ( ! hash_equals( (string) $grant['code_challenge'], $challenge ) ) {
				return self::oauth_token_error( 'invalid_grant', 'PKCE code_verifier không hợp lệ.', 400, 'pkce_verifier_mismatch', $log_ctx, $started_at );
			}
		}
		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — effective OAuth scopes = intersection(grant_scopes, currently_supported_scopes).
		// The active key is AUTHENTICATION evidence only; scopes are driven by what the user approved (grant) and what the
		// server currently supports (feature flags). Using key_context['scopes'] here would silently downgrade tokens for
		// old keys created before new flag waves were enabled, causing ChatGPT to reject the token (scope mismatch).
		$effective_scopes = array_values( array_intersect( (array) $grant['scope'], self::supported_scopes() ) );
		$log_ctx['scope_effective_count'] = count( $effective_scopes );
		if ( empty( $effective_scopes ) ) {
			return self::oauth_token_error( 'invalid_scope', 'MCP server hiện tại không hỗ trợ scope mà ứng dụng yêu cầu.', 400, 'no_supported_scope', $log_ctx, $started_at );
		}
		$client_record = self::get_client( $client_id );
		$client_name   = (string) ( $client_record['client_name'] ?? 'OAuth MCP client' );

		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — auto-provision an active MCP key for OAuth users that approved consent
		// but do not yet have any key row in the current shard/blog context.
		$key_row = BizCity_MCP_Auth::get_active_key_for_user( (int) $grant['user_id'] );
		if ( ! $key_row ) {
			$allowed_notebooks = class_exists( 'BizCity_MCP_Client_Scope_Resolver' )
				? BizCity_MCP_Client_Scope_Resolver::allowed_notebook_ids( array( 'user_id' => (int) $grant['user_id'] ) )
				: array();
			$allowed_notebooks = array_values( array_unique( array_map( 'intval', (array) $allowed_notebooks ) ) );
			$issued_key = BizCity_MCP_Auth::issue_key(
				'oauth_' . $client_id,
				$client_name,
				(int) $grant['user_id'],
				$effective_scopes,
				$allowed_notebooks
			);
			if ( is_wp_error( $issued_key ) ) {
				return self::oauth_token_error( 'invalid_grant', 'Không thể khởi tạo MCP API key cho tài khoản hiện tại.', 400, 'active_key_issue_failed', $log_ctx, $started_at );
			}
			$key_row = BizCity_MCP_Auth::get_active_key_for_user( (int) $grant['user_id'] );
		}
		if ( ! $key_row ) {
			return self::oauth_token_error( 'invalid_grant', 'Tài khoản chưa có MCP API key đang hoạt động. Vào My MCP → tạo key mới rồi kết nối lại.', 400, 'active_key_missing', $log_ctx, $started_at );
		}
		$log_ctx['key_id'] = (int) ( $key_row['id'] ?? 0 );
		$key_context = BizCity_MCP_Auth::context_from_row( $key_row );
		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — consume authorization code only after all grant validations pass.
		delete_transient( self::code_key( $code ) );
		$token = 'mcp_at_' . wp_generate_password( 64, false, false );
		$auth_ctx = array(
			'key_id'              => (int) $key_row['id'],
			'client_id'           => 'oauth_' . $client_id,
			'client_name'         => $client_name,
			'user_id'             => (int) $grant['user_id'],
			'scopes'              => $effective_scopes,
			'allowed_notebook_ids' => (array) $key_context['allowed_notebook_ids'],
			'oauth_client_id'     => $client_id,
			'oauth_token_hash'    => hash( 'sha256', $token ),
		);
		set_transient( self::token_key( $token ), $auth_ctx, self::TOKEN_TTL );
		$log_ctx['client_name'] = $client_name;
		self::log_oauth_token_event( 'ok', '', 200, 'token_issued', $log_ctx, $started_at );
		return new WP_REST_Response( array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => self::TOKEN_TTL,
			'scope'        => implode( ' ', (array) $effective_scopes ),
		), 200 );
	}

	public static function authenticate_token( $token ) {
		if ( ! is_string( $token ) || $token === '' ) {
			return false;
		}
		$ctx = get_transient( self::token_key( $token ) );
		return is_array( $ctx ) && ! empty( $ctx['user_id'] ) ? $ctx : false;
	}

	private static function supported_scopes() {
		$scopes = array( 'brain.read', 'document.context.build', 'document.validate' );
		if ( ! defined( 'BIZCITY_MCP_RENDER_ENABLED' ) || BIZCITY_MCP_RENDER_ENABLED ) {
			$scopes = array_merge( $scopes, array( 'document.render.docx', 'document.render.pptx' ) );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — advertise page scopes only for the opt-in Action wave.
		if ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED ) {
			$scopes = array_merge( $scopes, array( 'page.read', 'page.write', 'page.publish' ) );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — advertise business.read only when metrics tools are enabled.
		if ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) {
			$scopes[] = 'business.read';
		}
 		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — advertise content.read only for the opt-in BZCC bridge.
		if ( defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_TOOLS_ENABLED ) {
			$scopes[] = 'content.read';
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — advertise content.write only for the opt-in create-draft Action.
		if ( defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED ) {
			$scopes[] = 'content.write';
		}
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — advertise report.read only for the opt-in dataset bridge.
		if ( defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && BIZCITY_MCP_REPORT_TOOLS_ENABLED ) {
			$scopes[] = 'report.read';
		}
		return $scopes;
	}

	private static function normalize_scopes( $scope ) {
		$requested = preg_split( '/\s+/', trim( (string) $scope ) );
		$out = array();
		foreach ( (array) $requested as $item ) {
			$item = sanitize_text_field( $item );
			if ( in_array( $item, self::supported_scopes(), true ) && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function allowed_notebook_ids() {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — show OAuth consent against the live auto/Guru scope and exclusions.
		$ids = class_exists( 'BizCity_MCP_Client_Scope_Resolver' )
			? BizCity_MCP_Client_Scope_Resolver::allowed_notebook_ids( array( 'user_id' => (int) get_current_user_id() ) )
			: array();
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	}

	private static function get_client( $client_id ) {
		$record = get_transient( self::client_key( $client_id ) );
		if ( is_array( $record ) ) {
			return $record;
		}
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — restore a valid DCR record after transient/object-cache eviction.
		$clients = get_option( 'bizcity_mcp_oauth_clients', array() );
		$record  = is_array( $clients ) && isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : false;
		if ( is_array( $record ) ) {
			set_transient( self::client_key( $client_id ), $record, self::CLIENT_TTL );
		}
		return is_array( $record ) ? $record : false;
	}

	private static function client_key( $client_id ) { return 'bizcity_mcp_oauth_client_' . substr( hash( 'sha256', (string) $client_id ), 0, 40 ); }
	private static function code_key( $code ) { return 'bizcity_mcp_oauth_code_' . substr( hash( 'sha256', (string) $code ), 0, 40 ); }
	private static function token_key( $token ) { return 'bizcity_mcp_oauth_token_' . substr( hash( 'sha256', (string) $token ), 0, 40 ); }

	private static function consent_response( array $params, array $client, array $scopes ) {
		$action = esc_url( rest_url( self::NS . self::AUTHORIZATION_ENDPOINT ) );
		$hidden = '';
		foreach ( $params as $name => $value ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — avoid `_wpnonce` in REST HTML form to prevent `rest_cookie_invalid_nonce` interception.
		$hidden .= '<input type="hidden" name="_bizcity_oauth_nonce" value="' . esc_attr( wp_create_nonce( self::CONSENT_ACTION ) ) . '">';
		// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — show the exact approved scope groups so optional Brain/Action permissions are visible at OAuth consent.
		$scope_labels = array(
			'brain.read'             => 'Brain: đọc notebook, tìm kiếm evidence và nhận citation.',
			'document.context.build' => 'Document: tạo context pack từ evidence đã được cấp quyền.',
			'document.validate'      => 'Document: kiểm tra citation và claim trong bản nháp.',
			'document.render.docx'   => 'Document Action: chuẩn bị handoff xuất DOCX ở browser.',
			'document.render.pptx'   => 'Document Action: chuẩn bị handoff xuất PPTX ở browser.',
			'page.read'              => 'Page Brain: đọc schema, project và preview landing page.',
			'page.write'             => 'Page Action: tạo hoặc cập nhật landing page draft.',
			'page.publish'           => 'Page Action: publish landing page sau confirmation.',
			'business.read'          => 'Business Brain: đọc doanh thu, khách hàng và tồn kho.',
			'content.read'           => 'Content Brain: đọc file, chunk và template Content Creator.',
			'content.write'          => 'Content Action: tạo hoặc chỉnh sửa content draft, chưa publish.',
			'report.read'            => 'Report Brain: đọc template và xây dataset báo cáo.',
		);
		$scope_items = array();
		foreach ( $scopes as $scope ) {
			$scope = (string) $scope;
			$scope_items[] = '<li><strong>' . esc_html( $scope ) . '</strong> — ' . esc_html( $scope_labels[ $scope ] ?? 'Quyền MCP được cấp theo policy hiện tại.' ) . '</li>';
		}
		$list = '<ul><li>Đọc nội dung trong ' . esc_html( count( self::allowed_notebook_ids() ) ) . ' notebook được quản trị viên cho phép.</li>' . implode( '', $scope_items ) . '</ul>';
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — submit consent via signed GET to avoid multisite REST POST hard-gate while preserving nonce verification.
		$html = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cấp quyền MCP</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f6f8;color:#172033;margin:0;padding:32px}.card{max-width:520px;margin:7vh auto;background:#fff;border:1px solid #dfe5ec;border-radius:18px;padding:28px;box-shadow:0 14px 40px rgba(15,23,42,.09)}h1{font-size:22px;margin:0 0 8px}p,li{color:#526174;line-height:1.55}.scope{background:#f5f8fb;border-radius:12px;padding:12px 16px;margin:18px 0}.actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px}button{border:0;border-radius:10px;padding:11px 16px;font-weight:600;cursor:pointer}.allow{background:#0f766e;color:#fff}.deny{background:#eef2f6;color:#263244}</style></head><body><main class="card"><h1>Cho phép kết nối MCP?</h1><p><strong>' . esc_html( $client['client_name'] ) . '</strong> muốn kết nối với Twin GPT của bạn.</p><div class="scope"><strong>Quyền được yêu cầu</strong>' . $list . '</div><p>Chỉ cấp quyền nếu bạn nhận ra ứng dụng này. Bạn có thể thu hồi kết nối trong My MCP.</p><form method="get" action="' . $action . '">' . $hidden . '<div class="actions"><button class="deny" name="consent" value="deny" type="submit">Từ chối</button><button class="allow" name="consent" value="allow" type="submit">Cho phép</button></div></form></main></body></html>';
		$response = new WP_REST_Response( $html, 200 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		return $response;
	}

	private static function oauth_html_error( $message, $status ) {
		$html = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lỗi OAuth MCP</title></head><body style="font-family:system-ui;padding:32px"><h1>Không thể kết nối MCP</h1><p>' . esc_html( $message ) . '</p></body></html>';
		$response = new WP_REST_Response( $html, (int) $status );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		return $response;
	}

	private static function oauth_error( $error, $description, $status ) {
		$response = new WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), (int) $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function oauth_token_error( $error, $description, $status, $reason, array $ctx, $started_at ) {
		self::log_oauth_token_event( 'error', (string) $error, (int) $status, (string) $reason, $ctx, $started_at );
		return self::oauth_error( $error, $description, $status );
	}

	private static function log_oauth_token_event( $status, $error_code, $http_status, $reason, array $ctx, $started_at ) {
		if ( ! class_exists( 'BizCity_MCP_File_Logger' ) ) {
			return;
		}
		$redirect_host = sanitize_text_field( (string) ( $ctx['redirect_host'] ?? '' ) );
		$payload = array(
			'client_id'        => (string) ( $ctx['client_id'] ?? '' ),
			'grant_type'       => (string) ( $ctx['grant_type'] ?? '' ),
			'redirect_host'    => $redirect_host,
			'reason'           => sanitize_key( (string) $reason ),
			'status'           => sanitize_key( (string) $status ),
			'error_code'       => sanitize_key( (string) $error_code ),
			'code_present'     => ! empty( $ctx['code_present'] ) ? 1 : 0,
			'verifier_present' => ! empty( $ctx['verifier_present'] ) ? 1 : 0,
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - (float) $started_at ) * 1000 ) );
		BizCity_MCP_File_Logger::write( array(
			'timestamp'   => gmdate( 'c' ),
			'trace_id'    => 'oauth_token_' . substr( hash( 'sha256', wp_json_encode( $payload ) . '|' . microtime( true ) ), 0, 16 ),
			'blog_id'     => (int) get_current_blog_id(),
			'user_id'     => (int) ( $ctx['user_id'] ?? 0 ),
			'key_id'      => (int) ( $ctx['key_id'] ?? 0 ),
			'client_id'   => (string) ( $ctx['client_id'] ?? '' ),
			'client_name' => (string) ( $ctx['client_name'] ?? '' ),
			'tool_name'   => 'oauth.token',
			'status'      => sanitize_key( (string) $status ),
			'error_code'  => sanitize_key( (string) $error_code ),
			'duration_ms' => $duration_ms,
			'request_hash' => substr( hash( 'sha256', wp_json_encode( $payload ) ), 0, 40 ),
			'evaluation'  => array(
				'http_status'          => (int) $http_status,
				'reason'               => sanitize_key( (string) $reason ),
				'grant_type'           => sanitize_key( (string) ( $ctx['grant_type'] ?? '' ) ),
				'redirect_host'        => $redirect_host,
				'pkce_compat'          => ! empty( $ctx['pkce_compat'] ) ? 1 : 0,
				'code_present'         => ! empty( $ctx['code_present'] ) ? 1 : 0,
				'verifier_present'     => ! empty( $ctx['verifier_present'] ) ? 1 : 0,
				'scope_requested_count' => (int) ( $ctx['scope_requested_count'] ?? 0 ),
				'scope_effective_count' => (int) ( $ctx['scope_effective_count'] ?? 0 ),
			),
		) );
	}

	private static function redirect_response( $url ) {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}

	private static function redirect_with_error( array $params, $error, $description ) {
		return self::redirect_response( self::append_query( $params['redirect_uri'], array( 'error' => $error, 'error_description' => $description, 'state' => $params['state'] ) ) );
	}

	private static function append_query( $url, array $args ) {
		return add_query_arg( $args, $url );
	}

	private static function redirect_uri_matches( $expected, $actual ) {
		$expected = (string) $expected;
		$actual   = (string) $actual;
		if ( $expected === '' || $actual === '' ) {
			return false;
		}
		if ( hash_equals( $expected, $actual ) ) {
			return true;
		}
		$e = wp_parse_url( $expected );
		$a = wp_parse_url( $actual );
		if ( ! is_array( $e ) || ! is_array( $a ) ) {
			return false;
		}
		$e_scheme = strtolower( (string) ( $e['scheme'] ?? '' ) );
		$a_scheme = strtolower( (string) ( $a['scheme'] ?? '' ) );
		$e_host   = strtolower( (string) ( $e['host'] ?? '' ) );
		$a_host   = strtolower( (string) ( $a['host'] ?? '' ) );
		$e_port   = isset( $e['port'] ) ? (int) $e['port'] : 0;
		$a_port   = isset( $a['port'] ) ? (int) $a['port'] : 0;
		if ( $e_scheme !== $a_scheme || $e_host !== $a_host || $e_port !== $a_port ) {
			return false;
		}
		$e_path = untrailingslashit( (string) ( $e['path'] ?? '' ) );
		$a_path = untrailingslashit( (string) ( $a['path'] ?? '' ) );
		if ( $e_path !== $a_path ) {
			return false;
		}
		$e_query = isset( $e['query'] ) ? (string) $e['query'] : '';
		$a_query = isset( $a['query'] ) ? (string) $a['query'] : '';
		if ( $e_query === $a_query ) {
			return true;
		}
		$e_args = array();
		$a_args = array();
		parse_str( $e_query, $e_args );
		parse_str( $a_query, $a_args );
		if ( ! is_array( $e_args ) || ! is_array( $a_args ) ) {
			return false;
		}
		ksort( $e_args );
		ksort( $a_args );
		return wp_json_encode( $e_args ) === wp_json_encode( $a_args );
	}

	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$scheme = ( is_ssl() || ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) ) ? 'https' : 'http';
		$host = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			$host = (string) $_SERVER['HTTP_X_FORWARDED_HOST'];
		} elseif ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = (string) $_SERVER['HTTP_HOST'];
		} else {
			$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}
		if ( strpos( $host, ',' ) !== false ) {
			$parts = explode( ',', $host );
			$host = trim( (string) $parts[0] );
		}
		$host = preg_replace( '/\s+/', '', (string) $host );
		if ( $host === '' ) {
			return home_url( $uri );
		}
		return $scheme . '://' . $host . $uri;
	}

	private static function mapped_login_url( $redirect_url ) {
		$redirect_url = (string) $redirect_url;
		$base = self::current_url();
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
		if ( $host === '' ) {
			return wp_login_url( $redirect_url );
		}
		$login_url = $scheme . '://' . $host . '/wp-login.php';
		return add_query_arg( 'redirect_to', $redirect_url, $login_url );
	}

	private static function prime_user_from_login_cookie() {
		if ( is_user_logged_in() ) {
			return;
		}
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return;
		}
		$cookie = (string) wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$user_id = wp_validate_auth_cookie( $cookie, 'logged_in' );
		if ( ! empty( $user_id ) ) {
			wp_set_current_user( (int) $user_id );
		}
	}

	private static function base64url_encode( $value ) {
		// [2026-08-20 Johnny Chu] CODEC-CORE — delegate MCP URL-safe Base64 to the shared helper.
		return BizCity_Codec::base64url_encode( (string) $value );
	}

	private static function maybe_serve_oauth_post_routes( $path ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( $method !== 'POST' ) {
			return false;
		}

		$register_path = '/wp-json/' . self::NS . self::REGISTRATION_ENDPOINT;
		$token_path    = '/wp-json/' . self::NS . self::TOKEN_ENDPOINT;
		if ( $path !== $register_path && $path !== $token_path ) {
			return false;
		}

		$rest_request = new WP_REST_Request( 'POST', '/' . self::NS . ( $path === $token_path ? self::TOKEN_ENDPOINT : self::REGISTRATION_ENDPOINT ) );
		$raw_body     = file_get_contents( 'php://input' );
		$raw_body     = is_string( $raw_body ) ? $raw_body : '';
		$rest_request->set_body( $raw_body );
		$auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
		if ( $auth_header !== '' ) {
			$rest_request->set_header( 'authorization', $auth_header );
		}

		if ( $path === $token_path ) {
			$params = array();
			$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';
			if ( strpos( $content_type, 'application/json' ) !== false ) {
				$decoded = json_decode( $raw_body, true );
				$params  = is_array( $decoded ) ? $decoded : array();
			} else {
				parse_str( $raw_body, $params );
				if ( ! is_array( $params ) ) {
					$params = array();
				}
			}
			foreach ( $params as $k => $v ) {
				if ( is_scalar( $k ) && is_scalar( $v ) ) {
					$rest_request->set_param( (string) $k, (string) $v );
				}
			}
			$response = self::token( $rest_request );
		} else {
			$response = self::register_client( $rest_request );
		}

		self::emit_rest_response( $response );
		return true;
	}

	private static function emit_rest_response( $response ) {
		if ( is_wp_error( $response ) ) {
			$response = new WP_REST_Response( array(
				'error'             => (string) $response->get_error_code(),
				'error_description' => (string) $response->get_error_message(),
			), 500 );
		}
		if ( ! $response instanceof WP_REST_Response ) {
			$response = new WP_REST_Response( $response, 200 );
		}

		nocache_headers();
		$status = (int) $response->get_status();
		if ( $status > 0 ) {
			status_header( $status );
		}
		$headers = $response->get_headers();
		if ( empty( $headers['Content-Type'] ) ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		$data = $response->get_data();
		echo is_string( $data ) ? $data : wp_json_encode( $data );
		exit;
	}

	private static function client_id_from_authorization_header( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( $header !== '' && stripos( $header, 'basic ' ) === 0 ) {
			$decoded = BizCity_Codec::base64_decode( trim( substr( $header, 6 ) ), true );
			if ( is_string( $decoded ) && $decoded !== '' ) {
				$parts = explode( ':', $decoded, 2 );
				$user  = isset( $parts[0] ) ? sanitize_text_field( (string) $parts[0] ) : '';
				if ( $user !== '' ) {
					return $user;
				}
			}
		}
		$php_auth_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( (string) $_SERVER['PHP_AUTH_USER'] ) : '';
		return $php_auth_user;
	}

	public static function serve_html( $served, $result, $request, $server ) {
		unset( $request, $server );
		if ( ! $result instanceof WP_REST_Response ) { return $served; }
		$headers = $result->get_headers();
		$content_type = isset( $headers['Content-Type'] ) ? (string) $headers['Content-Type'] : '';
		if ( stripos( $content_type, 'text/html' ) === false ) { return $served; }
		foreach ( $headers as $name => $value ) { header( $name . ': ' . $value ); }
		$status = (int) $result->get_status();
		if ( $status >= 300 ) { status_header( $status ); }
		echo (string) $result->get_data();
		return true;
	}
}
