<?php
/**
 * Bizcity Twin AI — TwinChat Entitlement Proxy (R-GW)
 *
 * Client-side REST proxy that fronts the BizCity gateway's canonical
 * `GET /wp-json/bizcity/v1/account/entitlement` endpoint per
 * PHASE-0-RULE-GATEWAY-ONLY (R-GW): no JS code on a client site is ever
 * allowed to call bizcity.vn directly, and the bizcity-llm-router plugin
 * (which serves the canonical namespace) is never installed on client
 * sites — only on the gateway. The browser instead calls this proxy on
 * the same origin (cookie-authenticated, X-WP-Nonce), and the proxy
 * delegates server-side to `BizCity_LLM_Client::get_entitlement()` which
 * carries the shared Bearer API key.
 *
 * Routes:
 *   GET  /wp-json/bizcity-twinchat/v1/entitlement[?fresh=1]
 *
 * Failure policy (graceful degrade — see FE entitlementStore.ts):
 *   • Network / 4xx / 5xx from upstream → 200 with a synthetic fail-OPEN
 *     payload (`tier=free`, `bypass=true`, empty features) so the FE can
 *     stop the boot-loop retry while still rendering the UI.
 *   • The original upstream error is reported under `_degraded` for
 *     visibility (admin diagnostics + reportError pipeline).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      PHASE-0.41 L6 (2026-05-21)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Entitlement_Proxy {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' )
			? BIZCITY_TWINCHAT_REST_NS
			: 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/entitlement', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_get' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'fresh' => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
			],
		] );

		// [2026-06-03 Johnny Chu] R-1API — Same-origin proxy cho /account/info
		// (balance, tier, requests_today/limit). FE dùng để render badge usage
		// dialog cạnh nút Health. Cookie-auth, gateway URL động (server-side).
		register_rest_route( $ns, '/account-info', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_account_info' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
	}

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * Returns the normalized entitlement payload for the current user.
	 * Always 200 — upstream failures are downgraded to a synthetic free
	 * payload with `_degraded` populated.
	 */
	public function handle_get( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) get_current_user_id();
		$fresh   = (bool) $request->get_param( 'fresh' );

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_REST_Response(
				$this->synthetic_payload( $user_id, 'llm_client_missing', 'BizCity_LLM_Client not loaded.' ),
				200
			);
		}

		$result = BizCity_LLM_Client::instance()->get_entitlement( $user_id, [
			'fresh'   => $fresh,
			'timeout' => 6,
		] );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$msg  = $result->get_error_message();
			$data = $result->get_error_data();
			$ustatus = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$payload = $this->synthetic_payload( $user_id, (string) $code, (string) $msg, $ustatus );
			return new WP_REST_Response( $payload, 200 );
		}

		// Upstream success — pass through, but make sure required fields exist
		// so the FE type contract (EntitlementPayload) never breaks.
		$normalized = $this->normalize_payload( $result, $user_id );
		return new WP_REST_Response( $normalized, 200 );
	}

	/**
	 * Build a fail-OPEN payload that satisfies the FE EntitlementPayload type
	 * so the boot-fetch resolves and the visibility-driven refresher stops
	 * spamming the route.
	 */
	private function synthetic_payload( int $user_id, string $code, string $message, int $upstream_status = 0 ): array {
		// [2026-08-05 Johnny Chu] R-LLM-KEY-ONLY — TwinChat is an admin/BE
		// surface. Never substitute the local /gpt/ membership plan when the
		// exact Hub API-key entitlement cannot be resolved.
		return [
			'user_id'              => $user_id,
			'tier'                 => 'free',
			'generated_at'         => gmdate( 'c' ),
			'balance_usd'          => 0.0,
			'features'             => (object) [],
			'plan_label'           => 'Hub unavailable',
			'kg_max_file_size_mb'  => 0,
			'accepted_file_types'  => array(),
			'membership_plan_slug' => '',
			'master_plan_level'    => '',
			'bypass'               => true,
			'cached'               => false,
			'_degraded'            => [
				'code'            => $code,
				'message'         => $message,
				'upstream_status' => $upstream_status,
				'reason'          => 'gateway_unreachable_or_unauthorized',
			],
		];
	}

	private function normalize_payload( array $raw, int $user_id ): array {
		$raw['user_id']      = $raw['user_id']      ?? $user_id;
		$raw['tier']         = $raw['tier']         ?? 'free';
		$raw['generated_at'] = $raw['generated_at'] ?? gmdate( 'c' );
		$raw['balance_usd']  = isset( $raw['balance_usd'] ) ? (float) $raw['balance_usd'] : 0.0;
		if ( ! isset( $raw['features'] ) || ! is_array( $raw['features'] ) ) {
			$raw['features'] = (object) [];
		}

		// [2026-08-05 Johnny Chu] R-LLM-KEY-ONLY — the authenticated Hub
		// master_level is the only TwinChat plan identity. Local Membership belongs
		// to the public /gpt/ surface and must not fill missing Hub fields here.
		$hub_level = sanitize_key( (string) ( $raw['master_level'] ?? '' ) );
		if ( $hub_level !== '' ) {
			$raw['master_plan_level'] = $hub_level;
			if ( in_array( $hub_level, array( 'master_premium', 'premium' ), true ) ) {
				$raw['plan_label'] = 'Master Premium';
			} elseif ( in_array( $hub_level, array( 'master_pro', 'pro' ), true ) ) {
				$raw['plan_label'] = 'Master Pro';
			} elseif ( $hub_level === 'free' || $hub_level === 'master_free' ) {
				$raw['plan_label'] = 'Free';
			}
		}
		$raw['kg_max_file_size_mb'] = isset( $raw['kg_max_file_size_mb'] )
			? max( 0, (int) $raw['kg_max_file_size_mb'] )
			: 0;
		$raw['accepted_file_types'] = isset( $raw['accepted_file_types'] ) && is_array( $raw['accepted_file_types'] )
			? array_values( $raw['accepted_file_types'] )
			: array();
		$raw['membership_plan_slug'] = '';
		if ( empty( $raw['master_plan_level'] ) ) {
			$raw['master_plan_level'] = $hub_level;
		}

		return $raw;
	}

	/**
	 * [2026-06-03 Johnny Chu] R-1API — GET /account-info.
	 *
	 * Proxy lightweight cho gateway `/bizcity/v1/account/info`. Trả về:
	 *   {
	 *     key_set, key_masked, gateway_url, settings_url,
	 *     success, status, latency_ms, error?,
	 *     tier, plan, master_level, balance_usd, requests_today, requests_limit,
	 *     requests_remaining, is_free_tier, my_account_url, register_url
	 *   }
	 *
	 * Fail-OPEN: luôn HTTP 200 + success boolean để FE không retry-loop.
	 */
	public function handle_account_info( WP_REST_Request $request ): WP_REST_Response {
		// [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — read normalized
		// canonical key so status payload (key_set/key_masked) matches runtime behavior.
		$api_key      = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$api_key = BizCity_LLM_Client::instance()->get_api_key();
		}
		if ( $api_key === '' ) {
			// [2026-06-10 Johnny Chu] HOTFIX — per-site option
			$api_key = (string) get_option( 'bizcity_llm_api_key', '' );
		}
		$gateway_url  = rtrim( (string) get_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ), '/' );
		$masked       = '';
		if ( $api_key !== '' ) {
			$masked = substr( $api_key, 0, 6 ) . '…' . substr( $api_key, -4 );
		}
		$settings_url = admin_url( 'admin.php?page=bizcity-twinchat-settings' );

		$out = [
			'key_set'            => $api_key !== '',
			'key_masked'         => $masked,
			'gateway_url'        => $gateway_url,
			'settings_url'       => $settings_url,
			'success'            => false,
			'status'             => 0,
			'latency_ms'         => 0,
			'error'              => null,
			'tier'               => '',
			'plan'               => '',
			// [2026-07-14 Johnny Chu] PHASE-MEMBERSHIP FE-ACCOUNTINFO — expose master_level for concrete paid-plan mapping on FE.
			'master_level'       => (string) get_option( 'bizcity_hub_master_level', '' ),
			'balance_usd'        => null,
			'requests_today'     => null,
			'requests_limit'     => null,
			'requests_remaining' => null,
			'is_free_tier'       => null,
			'my_account_url'     => $gateway_url . '/my-account/',
			'register_url'       => $gateway_url . '/my-account/api-keys/',
			'checked_at'         => time(),
			// [2026-06-04 Johnny Chu] R-GW-API-CATALOG — always include kg_config shape
			'kg_config'          => null,
		];

		if ( ! $out['key_set'] ) {
			$out['error'] = 'no_api_key';
			return new WP_REST_Response( $out, 200 );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			$out['error'] = 'llm_client_missing';
			return new WP_REST_Response( $out, 200 );
		}

		$started = microtime( true );
		$result  = BizCity_LLM_Client::instance()->get_account_info( [ 'timeout' => 6 ] );
		$out['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$out['status']  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$out['error']   = $result->get_error_message();
			$out['success'] = false;
			return new WP_REST_Response( $out, 200 );
		}

		$out['success']            = true;
		$out['status']             = 200;
		$out['tier']               = (string) ( $result['tier'] ?? '' );
		$out['plan']               = (string) ( $result['plan'] ?? '' );
		// [2026-07-14 Johnny Chu] PHASE-MEMBERSHIP FE-ACCOUNTINFO — prefer upstream master_level, fallback to cached site option.
		$out['master_level']       = (string) ( $result['master_level'] ?? $out['master_level'] );
		$out['balance_usd']        = isset( $result['balance_usd'] )        ? (float) $result['balance_usd']        : null;
		$out['requests_today']     = isset( $result['requests_today'] )     ? (int)   $result['requests_today']     : null;
		$out['requests_limit']     = isset( $result['requests_limit'] )     ? (int)   $result['requests_limit']     : null;
		$out['requests_remaining'] = isset( $result['requests_remaining'] ) ? (int)   $result['requests_remaining'] : null;
		$out['is_free_tier']       = isset( $result['is_free_tier'] )       ? (bool)  $result['is_free_tier']       : null;
		if ( ! empty( $result['my_account_url'] ) ) {
			$out['my_account_url'] = (string) $result['my_account_url'];
		}
		if ( ! empty( $result['register_url'] ) ) {
			$out['register_url'] = (string) $result['register_url'];
		}

		// [2026-07-14 Johnny Chu] R-GW-API-CATALOG — prefer upstream KG config from account/info; fallback to local cached options.
		$hub_quota = (int) get_option( 'bizcity_hub_kg_quota_per_user', 0 );
		$hub_batch = (int) get_option( 'bizcity_hub_kg_batch_size', 0 );
		$hub_cap   = (float) get_option( 'bizcity_hub_daily_cap_usd', 0 );
		$up_plan   = ( isset( $result['plan'] ) && is_array( $result['plan'] ) ) ? $result['plan'] : [];
		$up_kg     = ( isset( $result['kg_config'] ) && is_array( $result['kg_config'] ) ) ? $result['kg_config'] : [];

		$has_up_quota  = array_key_exists( 'quota_per_user', $up_kg ) || array_key_exists( 'quota_per_user', $result );
		$has_up_batch  = array_key_exists( 'batch_size', $up_kg ) || array_key_exists( 'batch_size', $result );
		$has_up_cap    = array_key_exists( 'daily_cap_usd', $up_kg ) || array_key_exists( 'daily_cap_usd', $up_plan ) || array_key_exists( 'daily_cap_usd', $result );
		$has_up_dedupe = array_key_exists( 'dedupe_threshold', $up_kg ) || array_key_exists( 'dedupe_threshold', $result );
		$has_up_guard  = array_key_exists( 'cost_guard_on', $up_kg ) || array_key_exists( 'cost_guard_on', $result );

		$up_quota  = array_key_exists( 'quota_per_user', $up_kg )
			? (int) $up_kg['quota_per_user']
			: ( array_key_exists( 'quota_per_user', $result ) ? (int) $result['quota_per_user'] : 0 );
		$up_batch  = array_key_exists( 'batch_size', $up_kg )
			? (int) $up_kg['batch_size']
			: ( array_key_exists( 'batch_size', $result ) ? (int) $result['batch_size'] : 0 );
		$up_cap    = array_key_exists( 'daily_cap_usd', $up_kg )
			? (float) $up_kg['daily_cap_usd']
			: ( array_key_exists( 'daily_cap_usd', $up_plan )
				? (float) $up_plan['daily_cap_usd']
				: ( array_key_exists( 'daily_cap_usd', $result ) ? (float) $result['daily_cap_usd'] : 0.0 ) );
		$up_dedupe = array_key_exists( 'dedupe_threshold', $up_kg )
			? (float) $up_kg['dedupe_threshold']
			: ( array_key_exists( 'dedupe_threshold', $result ) ? (float) $result['dedupe_threshold'] : 0.0 );
		$up_guard  = array_key_exists( 'cost_guard_on', $up_kg )
			? (bool) $up_kg['cost_guard_on']
			: ( array_key_exists( 'cost_guard_on', $result ) ? (bool) $result['cost_guard_on'] : false );

		$out['kg_config'] = [
			'quota_per_user'   => $has_up_quota
				? $up_quota
				: ( $hub_quota > 0 ? $hub_quota : (int) apply_filters( 'bizcity_kg_quota_per_user', 50 ) ),
			'hub_quota_synced' => $has_up_quota || $hub_quota > 0,
			'daily_cap_usd'    => $has_up_cap
				? $up_cap
				: ( $hub_cap > 0
					? $hub_cap
					: (float) apply_filters( 'bizcity_kg_daily_cap_usd', 5.0 ) ),
			'batch_size'       => $has_up_batch
				? $up_batch
				: ( $hub_batch > 0
					? $hub_batch
					: ( class_exists( 'BizCity_KG_Cost_Guard' ) ? BizCity_KG_Cost_Guard::instance()->batch_size() : (int) apply_filters( 'bizcity_kg_extract_batch_size', 5 ) ) ),
			'dedupe_threshold' => $has_up_dedupe
				? $up_dedupe
				: (float) apply_filters( 'bizcity_kg_dedupe_cosine_threshold', 0.92 ),
			'cost_guard_on'    => $has_up_guard
				? $up_guard
				: (bool) apply_filters( 'bizcity_kg_cost_guard_enabled', true ),
		];

		return new WP_REST_Response( $out, 200 );
	}
}
