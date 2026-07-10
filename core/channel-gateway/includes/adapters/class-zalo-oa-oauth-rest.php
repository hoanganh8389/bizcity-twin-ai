<?php
/**
 * Zalo OA — OAuth v4 REST Controller (no bridge)
 *
 * Exposes 2 REST routes for the direct OAuth flow:
 *   GET /bizcity-channel/v1/zalo-oa/oauth/connect-url
 *       Params: account_uid (string, required)
 *       Returns: { ok: true, url: "https://oauth.zaloapp.com/v4/oa/permission?..." }
 *       Stores CSRF state in transient (5 min TTL).
 *
 *   GET /bizcity-channel/v1/zalo-oa/oauth/callback
 *       Params: code, state, oa_id (Zalo-provided)
 *       Exchanges code → access_token + refresh_token.
 *       Saves tokens to integration account via BizCity_Integration_Registry.
 *       Redirects to Channel Gateway admin page.
 *
 * Security:
 *   - connect-url: requires manage_options.
 *   - callback: state transient CSRF check (no WP auth needed — Zalo redirect target).
 *   - Tokens never logged or exposed in URL.
 *
 * [2026-06-19 Johnny Chu] PHASE-0.39 — Zalo OA OAuth REST (no bridge).
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_OAuth_REST' ) ) {
	return;
}

class BizCity_Zalo_OA_OAuth_REST {

	const NS            = 'bizcity-channel/v1';
	const STATE_TTL     = 300; // 5 minutes
	const OAUTH_BASE    = 'https://oauth.zaloapp.com';
	const CALLBACK_SLUG = 'bizcity-channel/v1/zalo-oa/oauth/callback';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// Generate OAuth authorization URL.
		register_rest_route(
			self::NS,
			'/zalo-oa/oauth/connect-url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_connect_url' ),
				'permission_callback' => array( __CLASS__, 'perm_admin' ),
				'args'                => array(
					'account_uid' => array(
						'required' => true,
						'type'     => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// OAuth callback — Zalo redirects here after permission grant.
		register_rest_route(
			self::NS,
			'/zalo-oa/oauth/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_callback' ),
				'permission_callback' => '__return_true', // CSRF via state param
			)
		);
	}

	/* ──────────────────────────────────────────
	 * GET /zalo-oa/oauth/connect-url
	 * ────────────────────────────────────────── */

	/**
	 * Generate Zalo OAuth authorization URL for an account.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_connect_url( WP_REST_Request $request ) {
		$account_uid = $request->get_param( 'account_uid' );

		// Load account from registry.
		$registry = class_exists( 'BizCity_Integration_Registry' )
			? BizCity_Integration_Registry::instance()
			: null;

		if ( ! $registry ) {
			return new WP_Error( 'zalooa_no_registry', 'Integration registry unavailable.', array( 'status' => 500 ) );
		}

		$account = self::find_account_by_uid( $registry, 'zalo_oa', (string) $account_uid );
		if ( ! $account ) {
			return new WP_Error( 'zalooa_not_found', 'Account not found.', array( 'status' => 404 ) );
		}

		$app_id = (string) ( $account['app_id'] ?? '' );
		if ( $app_id === '' ) {
			return new WP_Error( 'zalooa_no_app_id', 'App ID chưa được cấu hình cho tài khoản này.', array( 'status' => 400 ) );
		}

		// Generate CSRF state and store.
		$state = wp_generate_password( 32, false );
		set_transient( 'bizcity_zalooa_oauth_state_' . $state, array( 'account_uid' => $account_uid ), self::STATE_TTL );

		$redirect_uri = rest_url( self::CALLBACK_SLUG );
		$auth_url     = add_query_arg(
			array(
				'app_id'       => $app_id,
				'redirect_uri' => rawurlencode( $redirect_uri ),
				'state'        => $state,
			),
			self::OAUTH_BASE . '/v4/oa/permission'
		);

		return rest_ensure_response(
			array(
				'ok'          => true,
				'url'         => $auth_url,
				'redirect_uri' => $redirect_uri,
			)
		);
	}

	/* ──────────────────────────────────────────
	 * GET /zalo-oa/oauth/callback
	 * ────────────────────────────────────────── */

	/**
	 * Handle Zalo OAuth callback. Exchanges code → tokens, saves, redirects.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|void  Redirects on success/failure.
	 */
	public static function handle_callback( WP_REST_Request $request ) {
		$code  = (string) ( $request->get_param( 'code' )  ?? '' );
		$state = (string) ( $request->get_param( 'state' ) ?? '' );

		// [2026-06-20 Johnny Chu] PHASE-0.39 — correct redirect target: SPA route for Zalo OA.
		// NOTE: wp_safe_redirect() strips hash fragment — must use header() directly.
		$spa_base  = admin_url( 'admin.php?page=bizchat-gateway-spa' );
		$admin_url = $spa_base . '#/p/zalo_oa';
		$error_url = $spa_base . '#/p/zalo_oa';

		// Helper closure for fragment-safe redirect.
		$do_redirect = function ( $url ) {
			status_header( 302 );
			header( 'Location: ' . $url );
			exit;
		};

		// CSRF check.
		$state_data = $state ? get_transient( 'bizcity_zalooa_oauth_state_' . $state ) : false;
		if ( ! $state_data || ! is_array( $state_data ) ) {
			$do_redirect( $error_url );
		}

		delete_transient( 'bizcity_zalooa_oauth_state_' . $state );

		$account_uid = (string) ( $state_data['account_uid'] ?? '' );
		if ( $account_uid === '' || $code === '' ) {
			$do_redirect( $error_url );
		}

		// Load account.
		$registry = class_exists( 'BizCity_Integration_Registry' )
			? BizCity_Integration_Registry::instance()
			: null;

		if ( ! $registry ) {
			$do_redirect( $error_url );
		}

		$account = self::find_account_by_uid( $registry, 'zalo_oa', $account_uid );
		if ( ! $account ) {
			$do_redirect( $error_url );
		}

		$app_id     = (string) ( $account['app_id']     ?? '' );
		$app_secret = (string) ( $account['app_secret'] ?? '' );

		if ( $app_id === '' || $app_secret === '' ) {
			$do_redirect( $error_url );
		}

		// Exchange authorization code for tokens.
		$redirect_uri = rest_url( self::CALLBACK_SLUG );
		$token_result = self::exchange_code( $code, $app_id, $app_secret, $redirect_uri );

		if ( ! $token_result || empty( $token_result['access_token'] ) ) {
			$do_redirect( $error_url );
		}

		// Save tokens to account (via registry update).
		$expires_in = (int) ( $token_result['expires_in'] ?? 3600 );
		$integ      = $registry->get( 'zalo_oa' );
		if ( ! ( $integ instanceof BizCity_Channel_Integration ) ) {
			$do_redirect( $error_url );
		}

		// [2026-06-19 Johnny Chu] PHASE-0.39 — mark account connected right after OAuth callback.
		$clone = clone $integ;
		$clone->set_account(
			array_merge(
				$account,
				array(
					'access_token'     => (string) $token_result['access_token'],
					'refresh_token'    => (string) ( $token_result['refresh_token'] ?? '' ),
					'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
					'_status'          => 1,
					'_status_error'    => '',
					'_last_success_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);

		$encrypted = $clone->get_encrypted_params();
		$registry->update_channel_account_status( 'zalo_oa', $account_uid, $encrypted );

		// [2026-06-20 Johnny Chu] PHASE-0.39 — redirect to SPA Zalo OA route on success.
		$do_redirect( $admin_url );
	}

	/**
	 * Find account by uid and return decrypted fields.
	 *
	 * @param BizCity_Integration_Registry $registry
	 * @param string                       $code
	 * @param string                       $uid
	 * @return array|null
	 */
	private static function find_account_by_uid( BizCity_Integration_Registry $registry, string $code, string $uid ) {
		// [2026-06-20 Johnny Chu] PHASE-0.39 — must include private params (app_secret, refresh_token)
		// so the OAuth callback can use app_secret for token exchange.
		// get_accounts($code, true) calls get_decrypted_params(false) which excludes private_params
		// → app_secret would be '' → token exchange fails with 'no_credentials'.
		// Fix: get raw encrypted accounts, then decrypt with include_private=true.
		$raw_accounts = $registry->get_accounts( $code ); // raw, not decrypted
		$integ        = $registry->get( $code );
		foreach ( $raw_accounts as $acc ) {
			if ( (string) ( $acc['_uid'] ?? '' ) === $uid ) {
				if ( $integ ) {
					$clone = clone $integ;
					$clone->set_account( $acc );
					return $clone->get_decrypted_params( true ); // include private params
				}
				return is_array( $acc ) ? $acc : null;
			}
		}
		return null;
	}

	/* ──────────────────────────────────────────
	 * Private helpers
	 * ────────────────────────────────────────── */

	/**
	 * Exchange authorization code for access_token + refresh_token.
	 *
	 * POST https://oauth.zaloapp.com/v4/oa/access_token
	 * Header: secret_key: {app_secret}
	 * Body:   grant_type=authorization_code&code={code}&app_id={app_id}&redirect_uri={uri}
	 *
	 * @param string $code
	 * @param string $app_id
	 * @param string $app_secret
	 * @param string $redirect_uri
	 * @return array|null Decoded response or null on failure.
	 */
	private static function exchange_code( string $code, string $app_id, string $app_secret, string $redirect_uri ) {
		$response = wp_remote_post(
			self::OAUTH_BASE . '/v4/oa/access_token',
			array(
				'timeout' => 15,
				'headers' => array(
					'secret_key'   => $app_secret,
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'app_id'       => $app_id,
					'redirect_uri' => $redirect_uri,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[bizcity-zalooa] token exchange error: ' . $response->get_error_message() );
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			error_log( '[bizcity-zalooa] token exchange failed: ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		return $decoded;
	}

	/** @return bool */
	public static function perm_admin(): bool {
		return current_user_can( 'manage_options' );
	}
}
