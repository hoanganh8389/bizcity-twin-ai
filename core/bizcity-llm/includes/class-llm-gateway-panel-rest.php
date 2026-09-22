<?php
/**
 * BizCity LLM — API Gateway settings REST for the Setting Panel.
 *
 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-02 — the Control Panel renders
 * the API Gateway form in-panel, but `core/bizcity-llm` stays the only owner of the options, their
 * validation and the provider call. The panel never reads or writes `bizcity_llm_*` directly.
 *
 * Routes (manage_options, cookie + X-WP-Nonce):
 *   GET  /bizcity-twinchat/v1/settings/api-gateway       → projection (key is masked, never echoed)
 *   POST /bizcity-twinchat/v1/settings/api-gateway       → partial update, merged into saved settings
 *   POST /bizcity-twinchat/v1/settings/api-gateway/test  → probe the gateway with the saved key
 *
 * Errors follow R-ERROR-UX: { code, message, hint, help_code }.
 *
 * PHP 7.4 compat — no union types, no nullsafe, no str_contains.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      2026-09-16
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_LLM_Gateway_Panel_REST' ) ) {
	return;
}

final class BizCity_LLM_Gateway_Panel_REST {

	const NS = 'bizcity-twinchat/v1';

	const DEFAULT_GATEWAY_URL = 'https://bizcity.vn';

	const TIMEOUT_MIN = 5;
	const TIMEOUT_MAX = 300;

	/**
	 * Purposes exposed in the panel. Same set and order as the owner's own admin form; the
	 * remaining catalog purposes (goal_*, free, embedding) are caller-pinned and stay untouched.
	 */
	const PURPOSES = array( 'chat', 'vision', 'code', 'fast', 'router', 'planner', 'executor', 'twinbrain_wisdom' );

	public static function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : self::NS;

		register_rest_route( $ns, '/settings/api-gateway', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );

		register_rest_route( $ns, '/settings/api-gateway/test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_test' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	public static function can_manage() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot manage the API gateway on this site.', array( 'status' => 403 ) );
		}
		return true;
	}

	// ── Handlers ─────────────────────────────────────────────────────────

	public static function handle_get( WP_REST_Request $request ) {
		return new WP_REST_Response( self::projection(), 200 );
	}

	public static function handle_save( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$errors = array();

		// API key — only replaced when a new one is supplied. An empty field means "keep".
		if ( isset( $params['api_key'] ) && '' !== trim( (string) $params['api_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( (string) $params['api_key'] ) );
			$key = BizCity_LLM_Client::normalize_gateway_api_key( $key );
			if ( ! BizCity_LLM_Client::is_gateway_api_key( $key ) ) {
				$errors['api_key'] = __( 'The key must look like biz-… or biz_… followed by 16–80 letters or digits.', 'bizcity-twin-ai' );
			} else {
				$new_key = $key;
			}
		}

		if ( array_key_exists( 'gateway_url', $params ) ) {
			$url = self::normalize_gateway_url( (string) $params['gateway_url'] );
			if ( '' === $url ) {
				$errors['gateway_url'] = __( 'Enter a full http(s) address, for example https://bizcity.vn.', 'bizcity-twin-ai' );
			} else {
				$new_url = $url;
			}
		}

		$settings = get_option( 'bizcity_llm_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( isset( $params['general'] ) && is_array( $params['general'] ) ) {
			$general = $params['general'];
			if ( array_key_exists( 'site_name', $general ) ) {
				$settings['site_name'] = sanitize_text_field( wp_unslash( (string) $general['site_name'] ) );
			}
			if ( array_key_exists( 'timeout', $general ) ) {
				$timeout = (int) $general['timeout'];
				if ( $timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX ) {
					/* translators: 1: minimum seconds, 2: maximum seconds */
					$errors['timeout'] = sprintf( __( 'Timeout must be between %1$d and %2$d seconds.', 'bizcity-twin-ai' ), self::TIMEOUT_MIN, self::TIMEOUT_MAX );
				} else {
					$settings['timeout'] = $timeout;
				}
			}
		}

		if ( isset( $params['purposes'] ) && is_array( $params['purposes'] ) ) {
			foreach ( $params['purposes'] as $purpose => $row ) {
				$purpose = sanitize_key( (string) $purpose );
				if ( ! in_array( $purpose, self::PURPOSES, true ) || ! is_array( $row ) ) {
					continue;
				}
				foreach ( array( 'primary' => 'model_', 'fallback' => 'model_fallback_' ) as $field => $prefix ) {
					if ( ! array_key_exists( $field, $row ) ) {
						continue;
					}
					$model = trim( sanitize_text_field( wp_unslash( (string) $row[ $field ] ) ) );
					if ( '' !== $model && ! self::is_model_id( $model ) ) {
						$errors[ 'purposes.' . $purpose . '.' . $field ] = __( 'Model ids look like provider/model-name.', 'bizcity-twin-ai' );
						continue;
					}
					$settings[ $prefix . $purpose ] = $model;
				}
				if ( array_key_exists( 'no_fallback', $row ) ) {
					$settings[ 'no_fallback_' . $purpose ] = ! empty( $row['no_fallback'] ) ? 1 : 0;
				}
			}
		}

		// Validate everything before persisting anything: a partially saved form is worse than none.
		if ( ! empty( $errors ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_settings',
				'message'   => __( 'Some fields need attention before saving.', 'bizcity-twin-ai' ),
				'hint'      => __( 'Fix the highlighted fields; nothing was saved.', 'bizcity-twin-ai' ),
				'help_code' => 'api_gateway_invalid',
				'fields'    => $errors,
			), 400 );
		}

		// Same side effects as the owner's own form (BizCity_LLM_Settings::do_save): gateway mode is
		// the only mode, and a changed key/url/model set must drop the cached model catalog.
		update_option( 'bizcity_llm_mode', 'gateway' );
		if ( isset( $new_key ) ) {
			update_option( 'bizcity_llm_api_key', $new_key );
		}
		if ( isset( $new_url ) ) {
			update_option( 'bizcity_llm_gateway_url', $new_url );
		}
		update_option( 'bizcity_llm_settings', $settings );
		if ( method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
			BizCity_LLM_Client::instance()->bust_models_cache();
		}

		$payload            = self::projection();
		$payload['saved']   = true;
		$payload['message'] = __( 'API gateway settings saved.', 'bizcity-twin-ai' );
		return new WP_REST_Response( $payload, 200 );
	}

	public static function handle_test( WP_REST_Request $request ) {
		$client = BizCity_LLM_Client::instance();
		$key    = $client->get_api_key();
		if ( '' === $key ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'api_key_missing',
				'message'   => __( 'No API key is configured for this site.', 'bizcity-twin-ai' ),
				'hint'      => __( 'Save a BizCity API key first, then test again.', 'bizcity-twin-ai' ),
				'help_code' => 'api_key_missing',
			), 200 );
		}

		$started  = microtime( true );
		$response = wp_remote_get( $client->get_gateway_url() . '/wp-json/bizcity/v1/llm/models', array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array_merge(
				array( 'Authorization' => 'Bearer ' . $key ),
				method_exists( $client, 'get_client_domain_headers' ) ? $client->get_client_domain_headers() : array()
			),
		) );
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'success'    => false,
				'code'       => 'gateway_unreachable',
				'message'    => __( 'The gateway could not be reached.', 'bizcity-twin-ai' ),
				'hint'       => $response->get_error_message(),
				'help_code'  => 'gateway_unreachable',
				'latency_ms' => $latency,
			), 200 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		$body = json_decode( trim( $raw ), true );

		if ( $status >= 300 && $status < 400 ) {
			return new WP_REST_Response( array(
				'success'    => false,
				'code'       => 'gateway_redirect',
				/* translators: %d: HTTP status */
				'message'    => sprintf( __( 'The gateway answered with a redirect (HTTP %d).', 'bizcity-twin-ai' ), $status ),
				'hint'       => __( 'Check the Gateway URL: use the final https address without a trailing path.', 'bizcity-twin-ai' ),
				'help_code'  => 'gateway_redirect',
				'http_status'=> $status,
				'latency_ms' => $latency,
			), 200 );
		}

		if ( 200 === $status && is_array( $body ) && ( ! isset( $body['success'] ) || $body['success'] ) ) {
			$models = isset( $body['data'] ) && is_array( $body['data'] )
				? $body['data']
				: ( isset( $body['models'] ) && is_array( $body['models'] ) ? $body['models'] : array() );
			return new WP_REST_Response( array(
				'success'     => true,
				'code'        => 'gateway_ok',
				/* translators: %d: number of models */
				'message'     => sprintf( __( 'Connected — %d models available.', 'bizcity-twin-ai' ), count( $models ) ),
				'model_count' => count( $models ),
				'http_status' => $status,
				'latency_ms'  => $latency,
			), 200 );
		}

		$reason = '';
		if ( is_array( $body ) ) {
			if ( isset( $body['error']['message'] ) ) {
				$reason = (string) $body['error']['message'];
			} elseif ( isset( $body['message'] ) ) {
				$reason = (string) $body['message'];
			} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
				$reason = $body['error'];
			}
		} else {
			$reason = mb_substr( wp_strip_all_tags( $raw ), 0, 150 );
		}

		$auth_failure = in_array( $status, array( 401, 403 ), true );
		return new WP_REST_Response( array(
			'success'     => false,
			'code'        => $auth_failure ? 'api_key_rejected' : 'gateway_error',
			'message'     => $auth_failure
				? __( 'The gateway rejected this API key.', 'bizcity-twin-ai' )
				/* translators: %d: HTTP status */
				: sprintf( __( 'The gateway returned HTTP %d.', 'bizcity-twin-ai' ), $status ),
			'hint'        => '' !== $reason ? $reason : __( 'Try again in a moment.', 'bizcity-twin-ai' ),
			'help_code'   => $auth_failure ? 'api_key_rejected' : 'gateway_error',
			'http_status' => $status,
			'latency_ms'  => $latency,
		), 200 );
	}

	// ── Projection ───────────────────────────────────────────────────────

	/**
	 * Everything the panel needs to render the form. The key is reduced to presence, origin and a
	 * masked preview: the browser never receives key material.
	 *
	 * @return array<string,mixed>
	 */
	public static function projection() {
		$client   = BizCity_LLM_Client::instance();
		$settings = get_option( 'bizcity_llm_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$local_key = BizCity_LLM_Client::normalize_gateway_api_key( (string) get_option( 'bizcity_llm_api_key', '' ) );
		$effective = $client->get_api_key();
		$key_source = '' !== $local_key ? 'site' : ( '' !== $effective ? 'main_site' : 'none' );

		$local_url  = trim( (string) get_option( 'bizcity_llm_gateway_url', '' ) );
		$url_source = '' !== $local_url ? 'site' : ( is_multisite() && get_current_blog_id() !== get_main_site_id() ? 'main_site' : 'default' );

		$purposes = array();
		foreach ( self::PURPOSES as $purpose ) {
			$default_primary  = isset( BizCity_LLM_Models::DEFAULTS[ $purpose ] ) ? BizCity_LLM_Models::DEFAULTS[ $purpose ] : '';
			$default_fallback = isset( BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] ) ? BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] : '';
			$primary          = isset( $settings[ 'model_' . $purpose ] ) && '' !== $settings[ 'model_' . $purpose ] ? (string) $settings[ 'model_' . $purpose ] : $default_primary;
			// Mirror the owner form: the former built-in chat default is shown as the current default.
			if ( 'chat' === $purpose && 'google/gemini-2.5-flash' === $primary ) {
				$primary = $default_primary;
			}
			$catalog = array();
			foreach ( BizCity_LLM_Models::get( $purpose ) as $model ) {
				$catalog[] = array(
					'id'   => (string) $model['id'],
					'name' => isset( $model['name'] ) ? (string) $model['name'] : (string) $model['id'],
					'ctx'  => isset( $model['ctx'] ) ? (int) $model['ctx'] : 0,
				);
			}
			$purposes[] = array(
				'id'               => $purpose,
				'primary'          => $primary,
				'fallback'         => isset( $settings[ 'model_fallback_' . $purpose ] ) && '' !== $settings[ 'model_fallback_' . $purpose ] ? (string) $settings[ 'model_fallback_' . $purpose ] : $default_fallback,
				'no_fallback'      => ! empty( $settings[ 'no_fallback_' . $purpose ] ),
				'default_primary'  => $default_primary,
				'default_fallback' => $default_fallback,
				'catalog'          => $catalog,
			);
		}

		return array(
			'success'  => true,
			'contract' => 'api-gateway-settings',
			'version'  => '1.0.0',
			'owner'    => 'core/bizcity-llm',
			'mode'     => (string) get_option( 'bizcity_llm_mode', 'gateway' ),
			'gateway'  => array(
				'url'         => $client->get_gateway_url(),
				'default_url' => self::DEFAULT_GATEWAY_URL,
				'source'      => $url_source,
			),
			'key'      => array(
				'configured'   => '' !== $effective,
				'source'       => $key_source,
				'preview'      => self::mask_key( $effective ),
				'format_valid' => '' === $effective || BizCity_LLM_Client::is_gateway_api_key( $effective ),
			),
			'general'  => array(
				'site_name'   => isset( $settings['site_name'] ) ? (string) $settings['site_name'] : '',
				'timeout'     => isset( $settings['timeout'] ) && (int) $settings['timeout'] > 0 ? (int) $settings['timeout'] : 60,
				'timeout_min' => self::TIMEOUT_MIN,
				'timeout_max' => self::TIMEOUT_MAX,
			),
			'purposes' => $purposes,
			'legacy_url' => admin_url( 'admin.php?page=bizcity-twinchat-settings' ),
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * @param string $key
	 * @return string e.g. "biz-1a2b…9z8y"; '' when no key.
	 */
	private static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) <= 12 ) {
			return substr( $key, 0, 4 ) . '…';
		}
		return substr( $key, 0, 8 ) . '…' . substr( $key, -4 );
	}

	/**
	 * Same normalization the client applies on read: a base URL, never a pasted REST path.
	 *
	 * @param string $raw
	 * @return string '' when not a usable http(s) URL.
	 */
	private static function normalize_gateway_url( $raw ) {
		$url = trim( wp_unslash( (string) $raw ) );
		if ( '' === $url ) {
			return '';
		}
		$url = preg_replace( '#/wp-json(?:/.*)?$#i', '', $url );
		$url = rtrim( (string) esc_url_raw( $url, array( 'http', 'https' ) ), '/' );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return ( '' !== $url && is_string( $host ) && '' !== $host ) ? $url : '';
	}

	/**
	 * @param string $model
	 * @return bool
	 */
	private static function is_model_id( $model ) {
		return (bool) preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._:+-]*$#i', (string) $model );
	}
}
