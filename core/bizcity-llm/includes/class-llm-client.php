<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity LLM — Core Client Class
 *
 * Two modes:
 *   • Gateway  — calls bizcity.vn/bizcity.ai REST API (LLM Router)
 *   • Direct   — calls OpenRouter directly with user's own key
 *
 * Provides identical interface regardless of mode, including:
 *   chat(), chat_stream(), embeddings(), get_model(), get_fallback_model()
 *
 * @package BizCity_LLM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_LLM_Client {

    /* ─── Singleton ─── */
    private static ?self $instance = null;

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /* ── Debug logging ── */
    private function debug_log( string $msg, array $ctx = [] ): void {
        if ( ! defined( 'BIZCITY_LLM_DEBUG' ) || ! BIZCITY_LLM_DEBUG ) {
            return;
        }
        $extra = $ctx ? ' | ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
        error_log( "[BizCity-LLM] {$msg}{$extra}" );
    }

    /* ================================================================
     *  Configuration
     * ================================================================ */

    /**
     * Connection mode: 'gateway' or 'direct'.
     */
    public function get_mode(): string {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option, not network-wide
        return get_option( 'bizcity_llm_mode', 'gateway' );
    }

    /**
     * Gateway base URL (only used in gateway mode).
     */
    public function get_gateway_url(): string {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option, not network-wide
        // [2026-06-24 Johnny Chu] HOTFIX-MULTISITE — fallback to main site option when sub-site url is default
        $url = trim( (string) get_option( 'bizcity_llm_gateway_url', '' ) );
        if ( $url === '' && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            switch_to_blog( get_main_site_id() );
            $url = trim( (string) get_option( 'bizcity_llm_gateway_url', '' ) );
            restore_current_blog();
        }
        return rtrim( $url ?: 'https://bizcity.vn', '/' );
    }

    /**
     * API key — gateway key in gateway mode, OpenRouter key in direct mode.
     */
    public function get_api_key(): string {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option, not network-wide
        // [2026-06-24 Johnny Chu] HOTFIX-MULTISITE — fallback to main site option when sub-site key is empty
        $key = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
        if ( $key === '' && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            switch_to_blog( get_main_site_id() );
            $key = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
            restore_current_blog();
        }
        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — normalize pasted key formats.
        return self::normalize_gateway_api_key( $key );
    }

    /**
     * [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — normalize gateway API key text.
     */
    public static function normalize_gateway_api_key( string $api_key ): string {
        $api_key = trim( $api_key );
        if ( $api_key === '' ) {
            return '';
        }

        if ( stripos( $api_key, 'Bearer ' ) === 0 ) {
            $api_key = trim( substr( $api_key, 7 ) );
        }

        $api_key = trim( $api_key, " \t\n\r\0\x0B\"'`" );
        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — the key is an
        // opaque identifier: preserve its separator because changing biz_ to
        // biz- changes the hash used by Router authentication.
        if ( preg_match( '/(biz[-_][a-z0-9]{16,80})/i', $api_key, $m ) ) {
            $api_key = (string) $m[1];
        }

        if ( function_exists( 'mb_strtolower' ) ) {
            $api_key = mb_strtolower( $api_key, 'UTF-8' );
        } else {
            $api_key = strtolower( $api_key );
        }

        return trim( $api_key );
    }

    /**
     * [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — validate canonical
     * and legacy gateway key formats without mutating the opaque key value.
     */
    public static function is_gateway_api_key( string $api_key ): bool {
        return (bool) preg_match( '/^biz[-_][a-z0-9]{16,80}$/i', trim( $api_key ) );
    }

    /**
     * Whether the client is configured and ready.
     */
    public function is_ready(): bool {
        return ! empty( $this->get_api_key() );
    }

    /**
     * [2026-07-15 Johnny Chu] HOTFIX — detect db-init degraded global fallback.
     * In this mode, current request domain was not mapped to a real blog and
     * runtime context is force-routed to blog_id=1 (global).
     */
    private function is_dbinit_degraded_global(): bool {
        if ( ! empty( $GLOBALS['bizcity_dbinit_degraded_global'] ) ) {
            return true;
        }
        return defined( 'BIZCITY_DBINIT_DEGRADED_GLOBAL' ) && BIZCITY_DBINIT_DEGRADED_GLOBAL;
    }

    /**
     * [2026-07-15 Johnny Chu] HOTFIX — best-effort unresolved host for diagnostics.
     */
    private function dbinit_degraded_domain(): string {
        $domain = '';
        if ( isset( $GLOBALS['bizcity_dbinit_degraded_domain'] ) ) {
            $domain = strtolower( trim( (string) $GLOBALS['bizcity_dbinit_degraded_domain'] ) );
        }
        if ( $domain === '' ) {
            $domain = strtolower( trim( (string) ( $_SERVER['HTTP_HOST'] ?? ( $_SERVER['SERVER_NAME'] ?? '' ) ) ) );
            $domain = preg_replace( '/:\\d+$/', '', $domain );
        }
        return (string) $domain;
    }

    /**
     * Get a setting value.
     */
    public function get_setting( string $key, $default = null ) {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option
        $settings = get_option( 'bizcity_llm_settings', [] );
        return $settings[ $key ] ?? $default;
    }

    /**
     * Get the primary model for a purpose.
     */
    public function get_model( string $purpose = 'chat' ): string {
        $stored = $this->get_setting( 'model_' . $purpose, '' );
        if ( ! empty( $stored ) ) {
            return $stored;
        }
        return BizCity_LLM_Models::DEFAULTS[ $purpose ]
            ?? BizCity_LLM_Models::DEFAULTS['chat'];
    }

    /**
     * Get the fallback model for a purpose.
     */
    public function get_fallback_model( string $purpose = 'chat' ): string {
        if ( ! empty( $this->get_setting( 'no_fallback_' . $purpose, 0 ) ) ) {
            return '';
        }
        $stored = $this->get_setting( 'model_fallback_' . $purpose, '' );
        if ( ! empty( $stored ) ) {
            return $stored;
        }
        return BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] ?? '';
    }

    /**
     * Global timeout in seconds.
     */
    public function get_timeout(): int {
        // Default 300s — long Claude Sonnet generations (60K tokens) need 120-240s.
        // Caller can override via $options['timeout'] or admin setting.
        return (int) $this->get_setting( 'timeout', 300 );
    }

    /**
     * Map hub master level slug -> local tier bucket used by cron/settings.
     *
     * Hub slugs: free, master_pro, master_premium (and enterprise variants).
     * Local buckets: free, pro, premium, enterprise.
     */
	// [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — normalize hub master level for local tier consumers.
    public static function tier_bucket_from_master_level( string $master_level, array $tier_mapping = array() ): string {
        $level = sanitize_key( strtolower( trim( $master_level ) ) );

        // [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — prefer Hub-provided mapping when present.
        if ( ! empty( $tier_mapping ) && isset( $tier_mapping[ $level ] ) ) {
            $bucket = sanitize_key( (string) $tier_mapping[ $level ] );
            if ( in_array( $bucket, array( 'free', 'pro', 'premium', 'enterprise' ), true ) ) {
                return $bucket;
            }
        }

        if ( in_array( $level, array( 'master_enterprise', 'enterprise' ), true ) ) {
            return 'enterprise';
        }
        if ( in_array( $level, array( 'master_premium', 'premium', 'business', 'plus' ), true ) ) {
            return 'premium';
        }
        if ( in_array( $level, array( 'master_pro', 'pro', 'starter' ), true ) ) {
            return 'pro';
        }
        return 'free';
    }

    /**
     * Keep local hub plan cache synced from bizcity-llm-router master/config.
     *
     * Uses lightweight cadence guard so regular requests do not trigger an
     * upstream call every load.
     *
     * @param array $options { force_refresh?: bool, sync_interval?: int, timeout?: int }
     */
    public function maybe_sync_master_plan( array $options = array() ): void {
        if ( ! $this->is_ready() ) {
            return;
        }

        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — in direct
        // mode, API key is OpenRouter-style and must never hit hub master/config.
        if ( $this->get_mode() !== 'gateway' ) {
            return;
        }

        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — scope sync
        // cadence to the exact Bearer key so key rotation cannot reuse it.
        $api_key_hash = hash( 'sha256', $this->get_api_key() );
        $force    = ! empty( $options['force_refresh'] );
        $interval = isset( $options['sync_interval'] ) ? (int) $options['sync_interval'] : 300;
        if ( $interval < 60 ) {
            $interval = 60;
        }

        $now  = time();
        $last = (int) get_option( 'bizcity_hub_master_sync_ts', 0 );
		$last_key_hash = (string) get_option( 'bizcity_hub_master_sync_key_hash', '' );
		if ( $last_key_hash !== $api_key_hash ) {
			$last = 0;
		}
        if ( ! $force && $last > 0 && ( $now - $last ) < $interval ) {
            return;
        }

        // [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — unified pull from hub master/config.
        $timeout = isset( $options['timeout'] ) ? max( 3, (int) $options['timeout'] ) : 6;
        $fresh   = $this->get_plan_config( array(
            'force_refresh' => $force,
            'timeout'       => $timeout,
        ) );

        if ( is_array( $fresh ) && ! empty( $fresh['ok'] ) ) {
            update_option( 'bizcity_hub_master_sync_ts', $now );
			update_option( 'bizcity_hub_master_sync_key_hash', $api_key_hash, false );
            return;
        }

        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — throttle
        // repeated unauthorized loops on init sync when key is invalid/missing on hub.
        if ( is_wp_error( $fresh ) ) {
            $data   = $fresh->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
            if ( in_array( $status, array( 401, 403 ), true ) ) {
                update_option( 'bizcity_hub_master_sync_ts', $now );
				update_option( 'bizcity_hub_master_sync_key_hash', $api_key_hash, false );
            }
        }
    }

    /* ================================================================
     *  Account — entitlement proxy (PHASE-0.41 / R-GW)
     * ================================================================
     *
     * The canonical entitlement endpoint
     * `GET https://bizcity.vn/wp-json/bizcity/v1/account/entitlement`
     * lives in the `bizcity-llm-router` plugin on the BizCity gateway and
     * MUST NEVER be called from client-side JS (R-GW: no cross-origin call,
     * no provider key exposure). Client sites reach it via this server-side
     * wrapper, which adds the Bearer API key and forwards the requesting
     * user's identifier so the gateway can resolve their tier.
     *
     * Returns either a normalized payload array on success, or `WP_Error`
     * on any failure (network / 4xx / 5xx / decode). Callers should treat
     * a `WP_Error` as "degrade to free tier" rather than fatal.
     *
     * @param int   $user_id   WordPress user id on the calling site.
     * @param array $options   { fresh?: bool, timeout?: int }
     * @return array|WP_Error
     */
    public function get_entitlement( int $user_id, array $options = [] ) {
        if ( $user_id <= 0 ) {
            return new WP_Error( 'invalid_user', 'user_id must be > 0', [ 'status' => 400 ] );
        }
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return new WP_Error( 'no_api_key', 'BizCity API key not configured.', [ 'status' => 503 ] );
        }

        $user = get_userdata( $user_id );
        $email = $user ? (string) $user->user_email : '';
        $login = $user ? (string) $user->user_login : '';

        $base  = $this->get_gateway_url();
        $path  = '/wp-json/bizcity/v1/account/entitlement';
        $query = [
            'site'      => home_url(),
            'user_id'   => $user_id,
            'user_email'=> $email,
            'user_login'=> $login,
        ];
        if ( ! empty( $options['fresh'] ) ) {
            $query['fresh'] = 1;
        }
        $url = $base . $path . '?' . http_build_query( $query );

        $timeout = isset( $options['timeout'] ) ? max( 2, (int) $options['timeout'] ) : 6;

        $response = wp_remote_get( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'             => 'application/json',
                'Authorization'      => 'Bearer ' . $api_key,
                'X-Site-URL'         => home_url(),
                'X-BizCity-User-Id'  => (string) $user_id,
                'X-BizCity-User-Email' => $email,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'entitlement_upstream_error',
                is_array( $decoded ) && isset( $decoded['message'] )
                    ? (string) $decoded['message']
                    : ( 'Upstream HTTP ' . $code ),
                [ 'status' => $code, 'upstream_code' => $code ]
            );
        }
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'entitlement_decode_failed', 'Invalid JSON from gateway.', [ 'status' => 502 ] );
        }
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option (not network-wide sitemeta)
        // sync hub KG quota to local option so BizCity_KG_Cost_Guard uses the hub-configured value.
        if ( ! empty( $decoded['kg_config']['quota_per_user'] ) ) {
            update_option( 'bizcity_hub_kg_quota_per_user', (int) $decoded['kg_config']['quota_per_user'] );
        }
        if ( ! empty( $decoded['kg_config']['batch_size'] ) ) {
            update_option( 'bizcity_hub_kg_batch_size', (int) $decoded['kg_config']['batch_size'] );
        }
        $plugins_src = isset( $decoded['plugins_enabled'] ) ? $decoded['plugins_enabled']
                     : ( isset( $decoded['features'] ) ? $decoded['features'] : null );
        if ( is_array( $plugins_src ) ) {
            update_option( 'bizcity_hub_plugins_enabled', json_encode( $plugins_src ) );
        }
        if ( isset( $decoded['master_level'] ) ) {
            // [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — keep both raw master level
            // and normalized tier bucket so cron/settings consume pro/premium safely.
            $master_level = sanitize_key( (string) $decoded['master_level'] );
            if ( $master_level === '' ) {
                $master_level = 'free';
            }

            // [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — Hub may return normalized_tier + tier_mapping.
            $tier_map = ( isset( $decoded['tier_mapping'] ) && is_array( $decoded['tier_mapping'] ) )
                ? $decoded['tier_mapping']
                : array();
            $normalized_tier = sanitize_key( (string) ( $decoded['normalized_tier'] ?? ( $decoded['master_tier'] ?? '' ) ) );
            if ( ! in_array( $normalized_tier, array( 'free', 'pro', 'premium', 'enterprise' ), true ) ) {
                $normalized_tier = self::tier_bucket_from_master_level( $master_level, $tier_map );
            }

            update_option( 'bizcity_hub_master_level', $master_level );
            update_option( 'bizcity_hub_master_tier', $normalized_tier );
            if ( ! empty( $tier_map ) ) {
                update_option( 'bizcity_hub_tier_mapping', wp_json_encode( $tier_map ) );
            }
        }
        // [2026-07-14 Johnny Chu] R-KG-FILE-TYPES — cache exact hub file-type gate from entitlement payload.
        if ( isset( $decoded['accepted_file_types'] ) && is_array( $decoded['accepted_file_types'] ) ) {
            $accepted = array();
            foreach ( (array) $decoded['accepted_file_types'] as $ext ) {
                $e = sanitize_key( strtolower( trim( (string) $ext ) ) );
                if ( $e !== '' && ! in_array( $e, $accepted, true ) ) {
                    $accepted[] = $e;
                }
            }
            if ( ! empty( $accepted ) ) {
                update_option( 'bizcity_hub_accepted_file_types', wp_json_encode( $accepted ) );
            }
        }
        if ( isset( $decoded['kg_max_file_size_mb'] ) && (int) $decoded['kg_max_file_size_mb'] > 0 ) {
            update_option( 'bizcity_hub_kg_max_file_size_mb', (int) $decoded['kg_max_file_size_mb'] );
        }
        if ( isset( $decoded['plan']['image_calls_day'] ) ) {
            update_option( 'bizcity_hub_image_calls_day', (int) $decoded['plan']['image_calls_day'] );
        }
        if ( isset( $decoded['plan']['video_calls_day'] ) ) {
            update_option( 'bizcity_hub_video_calls_day', (int) $decoded['plan']['video_calls_day'] );
        }
        if ( isset( $decoded['plan']['max_requests_day'] ) ) {
            update_option( 'bizcity_hub_max_requests_day', (int) $decoded['plan']['max_requests_day'] );
        }

        // [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — sync hub seat-limit aliases for membership admission gate.
        $member_seats = ( isset( $decoded['member_seats'] ) && is_array( $decoded['member_seats'] ) )
            ? $decoded['member_seats']
            : array();
        $plan_payload = ( isset( $decoded['plan'] ) && is_array( $decoded['plan'] ) )
            ? $decoded['plan']
            : array();
        $plan_member_seats = ( isset( $plan_payload['member_seats'] ) && is_array( $plan_payload['member_seats'] ) )
            ? $plan_payload['member_seats']
            : array();

        $seat_limit_candidates = array(
            $member_seats['limit'] ?? null,
            $member_seats['seat_limit'] ?? null,
            $plan_payload['member_seat_limit'] ?? null,
            $plan_member_seats['limit'] ?? null,
            $plan_payload['member_seats_limit'] ?? null,
            $decoded['member_seat_limit'] ?? null,
            $decoded['member_seats_limit'] ?? null,
            $decoded['seat_limit'] ?? null,
        );
        $seat_limit = null;
        foreach ( $seat_limit_candidates as $candidate ) {
            if ( is_numeric( $candidate ) ) {
                $seat_limit = max( 0, (int) $candidate );
                break;
            }
        }
        if ( null !== $seat_limit ) {
            update_option( 'bizcity_hub_member_seat_limit', $seat_limit );
            update_option( 'bizcity_hub_member_seats_limit', $seat_limit );
            update_option( 'bizcity_hub_member_seat_cap', $seat_limit );
        }

        return $decoded;
    }

    /* ================================================================
     *  Account info — lightweight ping (R-1API, R-GW-API-CATALOG #9)
     * ================================================================
     *
     * Wraps `GET https://bizcity.vn/wp-json/bizcity/v1/account/info`.
     * Returns the gateway `data` object on success or WP_Error on failure.
     * Caller (proxy/REST) is responsible for fail-OPEN handling.
     *
     * @param array $options { timeout?: int }
     * @return array|WP_Error
     */
    public function get_account_info( array $options = [] ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return new WP_Error( 'no_api_key', 'BizCity API key not configured.', [ 'status' => 503 ] );
        }
        $timeout = isset( $options['timeout'] ) ? max( 2, (int) $options['timeout'] ) : 8;
        $url     = $this->get_gateway_url() . '/wp-json/bizcity/v1/account/info';

        $response = wp_remote_get( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $decoded ) && ! empty( $decoded['message'] )
                ? (string) $decoded['message']
                : ( is_array( $decoded ) && ! empty( $decoded['error'] ) ? (string) $decoded['error'] : 'HTTP ' . $code );
            return new WP_Error( 'account_info_upstream_error', $msg, [ 'status' => $code, 'upstream_code' => $code ] );
        }
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'account_info_decode_failed', 'Invalid JSON from gateway.', [ 'status' => 502 ] );
        }
        $data = ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : $decoded;
        return $data;
    }

    /* ================================================================
     *  Account limits — per-service quota snapshot (R-GW-API-CATALOG #9)
     * ================================================================
     *
     * [2026-06-04 Johnny Chu] R-GW-API-CATALOG — wrapper for
     * GET /bizcity/v1/account/limits (Bearer server-to-server).
     * Returns: { is_free, balance, services{video,faceswap,vto}, reset_at }
     * or WP_Error on failure.
     *
     * @param array $options { timeout?: int }
     * @return array|WP_Error
     */
    public function get_account_limits( array $options = [] ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return new WP_Error( 'no_api_key', 'BizCity API key not configured.', [ 'status' => 503 ] );
        }
        $timeout  = isset( $options['timeout'] ) ? max( 2, (int) $options['timeout'] ) : 8;
        $url      = $this->get_gateway_url() . '/wp-json/bizcity/v1/account/limits';

        $response = wp_remote_get( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $decoded ) && ! empty( $decoded['message'] )
                ? (string) $decoded['message']
                : ( is_array( $decoded ) && ! empty( $decoded['error'] ) ? (string) $decoded['error'] : 'HTTP ' . $code );
            return new WP_Error( 'account_limits_upstream_error', $msg, [ 'status' => $code ] );
        }
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'account_limits_decode_failed', 'Invalid JSON from gateway.', [ 'status' => 502 ] );
        }
        $data = ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : $decoded;
        return $data;
    }

    /* ================================================================
     *  Master Plan Config — fetch + cache (R-GW-API-CATALOG #MASTER)
     * ================================================================
     *
     * [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — wrapper for
     * GET /bizcity/v1/master/config (Bearer server-to-server).
     * Caches full plan data as site_options so settings page can render
     * plan card without a live round-trip every page load.
     *
     * @param array $options { timeout?: int, force_refresh?: bool }
     * @return array|WP_Error
     */
    public function get_plan_config( array $options = [] ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return new WP_Error( 'no_api_key', 'BizCity API key not configured.', [ 'status' => 503 ] );
        }

        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — fail-fast
        // on non-gateway key formats to avoid pointless upstream 401 requests.
        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — accept the
        // legacy `biz_` prefix still used by existing Hub key rows.
        if ( ! self::is_gateway_api_key( $api_key ) ) {
            return new WP_Error(
                'invalid_gateway_api_key_format',
                'Gateway API key must use biz- or legacy biz_ format.',
                [ 'status' => 400 ]
            );
        }

        // Return cached version unless force_refresh requested (max 1 call per 5 min).
        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient (not network-wide)
        // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — keep both
        // successful config and auth cooldown isolated per exact API key.
        $api_key_hash = hash( 'sha256', $api_key );
        $cache_key = 'bizcity_hub_plan_config_cache_' . $api_key_hash;
        $auth_backoff_key = 'bizcity_hub_plan_config_auth_error_' . $api_key_hash;
		$auth_backoff = get_transient( $auth_backoff_key );
		if ( is_array( $auth_backoff ) ) {
			$backoff_key_hash = (string) ( $auth_backoff['key_hash'] ?? '' );
			if ( $backoff_key_hash !== '' && ! hash_equals( $backoff_key_hash, $api_key_hash ) ) {
				delete_transient( $auth_backoff_key );
				$auth_backoff = false;
			}
		}
        if ( empty( $options['force_refresh'] ) ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
                return $cached;
            }

            if ( is_array( $auth_backoff ) ) {
                $until = isset( $auth_backoff['until'] ) ? (int) $auth_backoff['until'] : 0;
                if ( $until > time() ) {
                    $msg = isset( $auth_backoff['message'] ) ? (string) $auth_backoff['message'] : 'Unauthorized API key.';
                    $code = isset( $auth_backoff['status'] ) ? (int) $auth_backoff['status'] : 401;
                    return new WP_Error( 'plan_config_auth_backoff', $msg, [ 'status' => $code ] );
                }
            }
        }

        $timeout = isset( $options['timeout'] ) ? max( 5, (int) $options['timeout'] ) : 10;
        $url     = $this->get_gateway_url() . '/wp-json/bizcity/v1/master/config';

        $response = wp_remote_get( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $decoded ) && ! empty( $decoded['message'] )
                ? (string) $decoded['message']
                : 'HTTP ' . $code;

            // [2026-07-27 Johnny Chu] PHASE-0.49-MASTER-CONFIG-401 — cache auth
            // failures briefly so init sync does not hammer upstream each request.
            if ( in_array( $code, array( 401, 403 ), true ) ) {
                $ttl = 120;
                set_transient( $auth_backoff_key, [
                    'status'  => $code,
                    'message' => $msg,
                    'until'   => time() + $ttl,
					'key_hash' => $api_key_hash,
                ], $ttl );
            }
            return new WP_Error( 'plan_config_upstream_error', $msg, [ 'status' => $code ] );
        }
        if ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
            return new WP_Error( 'plan_config_decode_failed', 'Invalid response from gateway.', [ 'status' => 502 ] );
        }

        delete_transient( $auth_backoff_key );

        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient (not network-wide)
        // Cache for 5 minutes.
        set_transient( $cache_key, $decoded, 5 * MINUTE_IN_SECONDS );

        // Persist individual options for server-side rendering without extra HTTP call.
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option (not network-wide sitemeta)
        $master_level = sanitize_key( (string) ( $decoded['master_level'] ?? 'free' ) );
        if ( $master_level === '' ) {
            $master_level = 'free';
        }
        // [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — persist raw + normalized tier for downstream modules.
        $tier_map = ( isset( $decoded['tier_mapping'] ) && is_array( $decoded['tier_mapping'] ) )
            ? $decoded['tier_mapping']
            : array();
        $normalized_tier = sanitize_key( (string) ( $decoded['normalized_tier'] ?? ( $decoded['master_tier'] ?? '' ) ) );
        if ( ! in_array( $normalized_tier, array( 'free', 'pro', 'premium', 'enterprise' ), true ) ) {
            $normalized_tier = self::tier_bucket_from_master_level( $master_level, $tier_map );
        }

        update_option( 'bizcity_hub_master_level',        $master_level );
        update_option( 'bizcity_hub_master_tier',         $normalized_tier );
        if ( ! empty( $tier_map ) ) {
            update_option( 'bizcity_hub_tier_mapping', wp_json_encode( $tier_map ) );
        }
        update_option( 'bizcity_hub_master_label',        sanitize_text_field( $decoded['master_label'] ?? 'Free' ) );
        update_option( 'bizcity_hub_price_usd',           (float) ( $decoded['plan']['price_usd'] ?? 0 ) );
        update_option( 'bizcity_hub_monthly_credit_usd',  (float) ( $decoded['plan']['monthly_credit_usd'] ?? 0 ) );
        update_option( 'bizcity_hub_daily_cap_usd',       (float) ( $decoded['plan']['daily_cap_usd'] ?? 1 ) );
        update_option( 'bizcity_hub_max_requests_day',    (int)   ( $decoded['plan']['max_requests_day'] ?? 100 ) );
        update_option( 'bizcity_hub_image_calls_day',     (int)   ( $decoded['plan']['image_calls_day'] ?? 5 ) );
        update_option( 'bizcity_hub_video_calls_day',     (int)   ( $decoded['plan']['video_calls_day'] ?? 1 ) );
        update_option( 'bizcity_hub_kg_batch_size',       (int)   ( $decoded['kg_config']['batch_size'] ?? 5 ) );
        update_option( 'bizcity_hub_kg_quota_per_user',   (int)   ( $decoded['kg_config']['quota_per_user'] ?? 100 ) );

        // [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — persist seat-limit aliases from /master/config for Woo admission gate.
        $member_seats = ( isset( $decoded['member_seats'] ) && is_array( $decoded['member_seats'] ) )
            ? $decoded['member_seats']
            : array();
        $plan_payload = ( isset( $decoded['plan'] ) && is_array( $decoded['plan'] ) )
            ? $decoded['plan']
            : array();
        $plan_member_seats = ( isset( $plan_payload['member_seats'] ) && is_array( $plan_payload['member_seats'] ) )
            ? $plan_payload['member_seats']
            : array();

        $seat_limit_candidates = array(
            $member_seats['limit'] ?? null,
            $member_seats['seat_limit'] ?? null,
            $plan_payload['member_seat_limit'] ?? null,
            $plan_member_seats['limit'] ?? null,
            $plan_payload['member_seats_limit'] ?? null,
            $decoded['member_seat_limit'] ?? null,
            $decoded['member_seats_limit'] ?? null,
            $decoded['seat_limit'] ?? null,
        );
        $seat_limit = null;
        foreach ( $seat_limit_candidates as $candidate ) {
            if ( is_numeric( $candidate ) ) {
                $seat_limit = max( 0, (int) $candidate );
                break;
            }
        }
        if ( null !== $seat_limit ) {
            update_option( 'bizcity_hub_member_seat_limit', $seat_limit );
            update_option( 'bizcity_hub_member_seats_limit', $seat_limit );
            update_option( 'bizcity_hub_member_seat_cap', $seat_limit );
        }

        $plugins_src = isset( $decoded['plugins_enabled'] ) ? $decoded['plugins_enabled'] : ( $decoded['features'] ?? [] );
        if ( is_array( $plugins_src ) ) {
            update_option( 'bizcity_hub_plugins_enabled', json_encode( $plugins_src ) );
        }

        return $decoded;
    }

    /* ================================================================
     *  Usage Stats — 6-dimension analytics from hub
     * ================================================================ */

    /**
     * [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — fetch 6-dimension usage stats
     * for the configured API key from hub GET /bizcity/v1/master/usage-stats.
     *
     * @param array $options { period?: 'today'|'7d'|'30d'|'all', timeout?: int, force_refresh?: bool }
     * @return array|WP_Error
     */
    public function get_usage_stats( array $options = [] ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return new WP_Error( 'no_api_key', 'BizCity API key not configured.', [ 'status' => 503 ] );
        }

        $period  = isset( $options['period'] ) ? (string) $options['period'] : '30d';
        if ( ! in_array( $period, [ 'today', '7d', '30d', 'all' ], true ) ) {
            $period = '30d';
        }

        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient (not network-wide)
        $cache_key = 'bizcity_hub_usage_stats_' . $period;
        if ( empty( $options['force_refresh'] ) ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
                return $cached;
            }
        }

        $timeout = isset( $options['timeout'] ) ? max( 5, (int) $options['timeout'] ) : 15;
        $url     = $this->get_gateway_url() . '/wp-json/bizcity/v1/master/usage-stats?period=' . rawurlencode( $period );

        $response = wp_remote_get( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        // Strip BOM if present.
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = ( is_array( $decoded ) && ! empty( $decoded['message'] ) )
                ? (string) $decoded['message']
                : 'HTTP ' . $code;
            return new WP_Error( 'usage_stats_upstream_error', $msg, [ 'status' => $code ] );
        }

        if ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
            return new WP_Error( 'usage_stats_decode_failed', 'Invalid response from gateway.', [ 'status' => 502 ] );
        }

        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient
        // Cache for 5 minutes.
        set_transient( $cache_key, $decoded, 5 * MINUTE_IN_SECONDS );

        return $decoded;
    }

    /**
     * [2026-06-10 Johnny Chu] PHASE-LLM-ACTIVITY R7 — fetch unified activity rollup
     * (request/day + meter) from hub GET /bizcity/v1/master/activity.
     *
     * Fail-OPEN: never throws — returns [ 'ok' => false, '_degraded' => true ]
     * when the key is missing or the gateway is unreachable (R-GW-8). Caches the
     * last good payload for 5 minutes per period.
     *
     * @param string $period today | 7d | 30d (default) | all
     * @param bool   $force_refresh Skip the transient cache.
     * @return array See docs/api/16-llm-activity.md.
     */
    public function get_activity_rollup( string $period = '30d', bool $force_refresh = false ): array {
        if ( ! in_array( $period, [ 'today', '7d', '30d', 'all' ], true ) ) {
            $period = '30d';
        }

        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return [ 'ok' => false, 'error' => 'no_api_key', '_degraded' => true ];
        }

        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient
        $cache_key = 'bizcity_hub_activity_' . $period;
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
                return $cached;
            }
        }

        $url = $this->get_gateway_url() . '/wp-json/bizcity/v1/master/activity?period=' . rawurlencode( $period );

        $response = wp_remote_get( $url, [
            'timeout'     => 15,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_code(), '_degraded' => true ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
            // Fall back to last good cache if available, else degraded.
            $stale = get_transient( $cache_key );
            if ( is_array( $stale ) && ! empty( $stale['ok'] ) ) {
                return $stale;
            }
            return [ 'ok' => false, 'error' => 'gateway_error', '_degraded' => true, 'http_code' => $code ];
        }

        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient
        set_transient( $cache_key, $decoded, 5 * MINUTE_IN_SECONDS );

        return $decoded;
    }

    /**
     * Analyze image/file media through the BizCity hub vision tool.
     *
     * Fail-OPEN: returns a degraded payload when the hub endpoint is missing or
     * the API key is not configured. The caller must not claim the media was
     * analyzed unless success=true.
     *
     * @param array  $media  Attachment manifest rows with url/mime_type/filename.
     * @param string $prompt User prompt.
     * @param array  $options { purpose, model, timeout }.
     * @return array
     */
    public function analyze_media( array $media, string $prompt = '', array $options = [] ): array {
        // [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — canonical client wrapper for vision/file analysis via hub.
        $base = [
            'success'       => false,
            '_degraded'     => true,
            'reason_bucket' => '',
            'summary'       => '',
            'entities'      => [],
            'ocr_text'      => [],
            'layout'        => [],
            'style'         => [],
            'objects'       => [],
            'confidence'    => 'low',
            'raw'           => [],
        ];

        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            $base['reason_bucket'] = 'api_key_missing';
            return $base;
        }

        $clean_media = [];
        foreach ( $media as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
            if ( $url === '' ) {
                continue;
            }
            $clean_media[] = [
                'id'        => (int) ( $item['id'] ?? 0 ),
                'url'       => $url,
                'mime_type' => sanitize_text_field( (string) ( $item['mime_type'] ?? '' ) ),
                'filename'  => sanitize_file_name( (string) ( $item['filename'] ?? '' ) ),
                'size'      => (int) ( $item['size'] ?? 0 ),
                'kind'      => sanitize_key( (string) ( $item['kind'] ?? '' ) ),
            ];
        }
        if ( empty( $clean_media ) ) {
            $base['reason_bucket'] = 'media_url_missing';
            return $base;
        }

        $endpoint = $this->get_gateway_url() . '/wp-json/bizcity/v1/tools/vision-analyze';
        $timeout  = isset( $options['timeout'] ) ? max( 10, (int) $options['timeout'] ) : 45;
        $body     = [
            'prompt'   => (string) $prompt,
            'media'    => $clean_media,
            'model'    => isset( $options['model'] ) ? sanitize_text_field( (string) $options['model'] ) : '',
            'purpose'  => isset( $options['purpose'] ) ? sanitize_key( (string) $options['purpose'] ) : 'vision',
            'site_url' => home_url(),
        ];

        $response = wp_remote_post( $endpoint, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->analyze_media_via_chat_fallback( $clean_media, $prompt, $options, array_merge( $base, [
                'reason_bucket' => $response->get_error_code(),
            ] ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }
        $decoded = json_decode( trim( $raw ), true );

        if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ( ! empty( $decoded['success'] ) || ! empty( $decoded['ok'] ) ) ) {
            $payload = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
            return array_merge( $base, [
                'success'       => true,
                '_degraded'     => false,
                'reason_bucket' => '',
                'summary'       => (string) ( $payload['summary'] ?? $decoded['summary'] ?? '' ),
                'entities'      => array_values( (array) ( $payload['entities'] ?? [] ) ),
                'ocr_text'      => array_values( (array) ( $payload['ocr_text'] ?? $payload['ocr'] ?? [] ) ),
                'layout'        => (array) ( $payload['layout'] ?? [] ),
                'style'         => (array) ( $payload['style'] ?? [] ),
                'objects'       => array_values( (array) ( $payload['objects'] ?? [] ) ),
                'confidence'    => (string) ( $payload['confidence'] ?? 'medium' ),
                'raw'           => $payload,
            ] );
        }

        $reason = 'vision_endpoint_unavailable';
        if ( $code > 0 && $code !== 404 ) {
            $reason = 'vision_http_' . $code;
        }

        return $this->analyze_media_via_chat_fallback( $clean_media, $prompt, $options, array_merge( $base, [
            'reason_bucket' => $reason,
            'http_code'     => $code,
        ] ) );
    }

    /**
     * Fallback to the existing chat gateway using OpenAI multimodal message
     * shape. If the router/model cannot process image_url content, return the
     * degraded payload from analyze_media().
     *
     * @param array $media Clean media rows.
     * @param string $prompt User prompt.
     * @param array $options Options.
     * @param array $base Base degraded payload.
     * @return array
     */
    private function analyze_media_via_chat_fallback( array $media, string $prompt, array $options, array $base ): array {
        // [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — reuse chat gateway as best-effort vision fallback without provider-direct calls.
        $content = [];
        $content[] = [
            'type' => 'text',
            'text' => "Phân tích ảnh/file đính kèm cho TwinBrain. Trả JSON ngắn với keys: summary, entities[], ocr_text[], layout, style, objects[], confidence. User hỏi: " . (string) $prompt,
        ];
        foreach ( $media as $item ) {
            $mime = strtolower( (string) ( $item['mime_type'] ?? '' ) );
            if ( 0 !== strpos( $mime, 'image/' ) ) {
                continue;
            }
            $content[] = [
                'type'      => 'image_url',
                'image_url' => [ 'url' => (string) $item['url'] ],
            ];
        }

        if ( count( $content ) <= 1 ) {
            return $base;
        }

        $result = $this->chat( [
            [ 'role' => 'user', 'content' => $content ],
        ], [
            'purpose'     => 'vision',
            'model'       => isset( $options['model'] ) ? (string) $options['model'] : $this->get_model( 'vision' ),
            'temperature' => 0.1,
            'max_tokens'  => 900,
            'timeout'     => isset( $options['timeout'] ) ? max( 10, (int) $options['timeout'] ) : 45,
            'no_fallback' => ! empty( $options['no_fallback'] ),
        ] );

        if ( empty( $result['success'] ) || trim( (string) ( $result['message'] ?? '' ) ) === '' ) {
            if ( empty( $base['reason_bucket'] ) ) {
                $base['reason_bucket'] = 'vision_chat_fallback_failed';
            }
            $base['chat_error'] = (string) ( $result['error'] ?? '' );
            return $base;
        }

        $message = trim( (string) $result['message'] );
        $json = json_decode( trim( preg_replace( '/^```(?:json)?|```$/m', '', $message ) ), true );
        if ( ! is_array( $json ) ) {
            $json = [ 'summary' => $message ];
        }

        return array_merge( $base, [
            'success'       => true,
            '_degraded'     => false,
            'reason_bucket' => '',
            'summary'       => (string) ( $json['summary'] ?? $message ),
            'entities'      => array_values( (array) ( $json['entities'] ?? [] ) ),
            'ocr_text'      => array_values( (array) ( $json['ocr_text'] ?? $json['ocr'] ?? [] ) ),
            'layout'        => (array) ( $json['layout'] ?? [] ),
            'style'         => (array) ( $json['style'] ?? [] ),
            'objects'       => array_values( (array) ( $json['objects'] ?? [] ) ),
            'confidence'    => (string) ( $json['confidence'] ?? 'medium' ),
            'raw'           => [ 'chat_fallback' => true, 'message' => $message, 'parsed' => $json ],
        ] );
    }

    /* ================================================================
     *  Chat — delegates to gateway or direct based on mode
     * ================================================================ */

    /**
     * Send a chat-completion request.
     *
     * @param array $messages  OpenAI-format messages.
     * @param array $options   { model, purpose, temperature, max_tokens, extra_body, no_fallback, ... }
     * @return array { success, message, model, model_primary, fallback_used, provider, usage, error }
     */
    public function chat( array $messages, array $options = [] ): array {
        $mode    = $this->get_mode();
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? $this->get_model( $purpose );
        $start   = microtime( true );

        $this->debug_log( 'chat() START', [
            'mode' => $mode, 'purpose' => $purpose, 'model' => $model,
            'api_key_set' => ! empty( $this->get_api_key() ),
            'msg_count' => count( $messages ),
        ] );

        // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — file log is append-only, no pending row.
        $_llm_pending_id = class_exists( 'BizCity_LLM_Usage_File_Log' )
            ? BizCity_LLM_Usage_File_Log::log_pending( [
                'service'         => $options['service'] ?? 'llm',
                'mode'            => $this->get_mode(),
                'purpose'         => $purpose,
                'endpoint'        => 'chat',
                'model_requested' => $model,
            ] )
            : 0;

        $result = $this->chat_gateway( $messages, $options );

        // ── Client-side fallback: retry with fallback model on failure ──
        $no_fallback    = ! empty( $options['no_fallback'] ) || ! empty( $options['_is_fallback'] );
        // [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — skip fallback when the whole
        // account quota is exhausted. A fallback model on the same gateway will also
        // hit 429; retrying wastes latency and produces a misleading second error log.
        $quota_exhausted = ! empty( $result['quota_exhausted'] );
        $should_retry   = ! $result['success'] && ! $no_fallback && ! $quota_exhausted;
        if ( $should_retry ) {
            $fallback_model = $this->get_fallback_model( $purpose );
            if ( $fallback_model && $fallback_model !== $model ) {
                $this->debug_log( 'chat() PRIMARY FAILED — trying fallback', [
                    'primary_model'  => $model,
                    'fallback_model' => $fallback_model,
                    'primary_error'  => $result['error'] ?? '',
                    'quota_exhausted'=> $result['quota_exhausted'] ?? false,
                ] );
                error_log( sprintf(
                    '[bizcity-llm] chat() fallback: primary=%s failed (%s) → retrying with %s purpose=%s',
                    $model,
                    $result['error'] ?? 'unknown',
                    $fallback_model,
                    $purpose
                ) );

                $fallback_options                = $options;
                $fallback_options['model']       = $fallback_model;
                $fallback_options['_is_fallback'] = true;
                $fallback_result = $this->chat_gateway( $messages, $fallback_options );

                if ( ! empty( $fallback_result['success'] ) ) {
                    $fallback_result['fallback_used'] = true;
                    $fallback_result['model_primary'] = $model;
                    $result = $fallback_result;
                } else {
                    // Both primary and fallback failed — keep original error,
                    // but annotate with fallback attempt info.
                    $result['fallback_error'] = $fallback_result['error'] ?? 'fallback also failed';
                    error_log( sprintf(
                        '[bizcity-llm] chat() fallback ALSO FAILED: model=%s error=%s',
                        $fallback_model,
                        $fallback_result['error'] ?? 'unknown'
                    ) );
                }
            }
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'chat() END', [
            'success' => $result['success'], 'model' => $result['model'] ?? '',
            'provider' => $result['provider'] ?? '', 'ms' => $ms,
            'fallback' => $result['fallback_used'] ?? false,
            'error' => $result['error'] ?? '',
            'reply_len' => mb_strlen( $result['message'] ?? '' ),
        ] );

        $this->log_usage( $result, $options, 'chat', $model, $ms, $_llm_pending_id );

        return $result;
    }

    /**
     * Send a streaming chat-completion request.
     *
     * @param array         $messages
     * @param array         $options
     * @param callable|null $on_chunk  function(string $delta, string $full): void
     * @return array Same shape as chat().
     */
    public function chat_stream( array $messages, array $options = [], $on_chunk = null ): array {
        $mode    = $this->get_mode();
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? $this->get_model( $purpose );
        $start   = microtime( true );

        $this->debug_log( 'chat_stream() START', [
            'mode' => $mode, 'purpose' => $purpose, 'model' => $model,
            'api_key_set' => ! empty( $this->get_api_key() ),
        ] );

        // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — file log is append-only, no pending row.
        $_llm_pending_id = class_exists( 'BizCity_LLM_Usage_File_Log' )
            ? BizCity_LLM_Usage_File_Log::log_pending( [
                'service'         => $options['service'] ?? 'llm',
                'mode'            => $this->get_mode(),
                'purpose'         => $purpose,
                'endpoint'        => 'stream',
                'model_requested' => $model,
            ] )
            : 0;

        $result = $this->chat_stream_gateway( $messages, $options, $on_chunk );

        // ── Client-side fallback: retry with fallback model on failure ──
        $no_fallback    = ! empty( $options['no_fallback'] ) || ! empty( $options['_is_fallback'] );
        $should_retry   = ! $result['success'] && ! $no_fallback;
        if ( $should_retry ) {
            $fallback_model = $this->get_fallback_model( $purpose );
            if ( $fallback_model && $fallback_model !== $model ) {
                error_log( sprintf(
                    '[bizcity-llm] chat_stream() fallback: primary=%s failed (%s) → retrying with %s',
                    $model, $result['error'] ?? 'unknown', $fallback_model
                ) );

                $fallback_options                = $options;
                $fallback_options['model']       = $fallback_model;
                $fallback_options['_is_fallback'] = true;
                $fallback_result = $this->chat_stream_gateway( $messages, $fallback_options, $on_chunk );

                if ( ! empty( $fallback_result['success'] ) ) {
                    $fallback_result['fallback_used'] = true;
                    $fallback_result['model_primary'] = $model;
                    $result = $fallback_result;
                }
            }
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'chat_stream() END', [
            'success' => $result['success'], 'ms' => $ms,
            'error' => $result['error'] ?? '',
        ] );

        $this->log_usage( $result, $options, 'stream', $model, $ms, $_llm_pending_id );

        return $result;
    }

    /* ================================================================
     *  Chat with character (backward compat with bizcity-knowledge)
     * ================================================================ */

    public function chat_with_character( object $character, array $messages ): array {
        $result = $this->chat( $messages, [
            'model'       => $character->model_id       ?? '',
            'temperature' => floatval( $character->creativity_level ?? 0.7 ),
            'max_tokens'  => intval( $character->max_tokens ?? 3000 ),
        ] );

        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'llm_call',
                'message'          => 'chat_with_character()',
                'mode'             => $this->get_mode(),
                'functions_called' => 'BizCity_LLM_Client::chat_with_character()',
                'model_requested'  => $character->model_id ?? '',
                'model_actual'     => $result['model'] ?? '',
                'fallback_used'    => $result['fallback_used'] ?? false,
                'success'          => $result['success'] ?? false,
                'error'            => $result['error'] ?? '',
                'usage'            => $result['usage'] ?? [],
                'reply_length'     => mb_strlen( $result['message'] ?? '', 'UTF-8' ),
            ] );
        }

        return $result;
    }

    public function chat_stream_with_character( object $character, array $messages, $on_chunk = null ): array {
        return $this->chat_stream( $messages, [
            'model'       => $character->model_id       ?? '',
            'temperature' => floatval( $character->creativity_level ?? 0.7 ),
            'max_tokens'  => intval( $character->max_tokens ?? 3000 ),
        ], $on_chunk );
    }

    /* ================================================================
     *  GATEWAY MODE — call bizcity.vn REST API
     * ================================================================ */

    private function chat_gateway( array $messages, array $options ): array {
        $base = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            $this->debug_log( 'chat_gateway() ABORT — no API key' );
            return $base;
        }

        // [2026-07-15 Johnny Chu] HOTFIX — hard-block expensive gateway calls
        // when db-init has already fallen back to global due unresolved domain.
        if ( $this->is_dbinit_degraded_global() ) {
            $domain = $this->dbinit_degraded_domain();
            $base['error'] = 'BizCity multisite domain mapping missing. LLM request blocked in degraded global fallback mode.';
            $base['error_code'] = 'site_unmapped';
            $base['degraded_domain'] = $domain;
            error_log( '[bizcity-llm] BLOCKED site_unmapped in degraded-global mode: domain=' . ( $domain !== '' ? $domain : '(unknown)' ) );
            return $base;
        }

        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $options['temperature'] ?? 0.7 ),
            'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            'purpose'     => $purpose,
            'site_url'    => home_url(),
        ];
        if ( ! empty( $options['extra_body'] ) ) {
            $body = array_merge( $body, $options['extra_body'] );
        }
        // Forward optional cross-vendor fallback (caller can override Hub default).
        if ( ! empty( $options['fallback_model'] ) ) {
            $body['fallback_model'] = (string) $options['fallback_model'];
        }

        $timeout  = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();
        // Forward timeout to the router. Without this the gateway REST handler
        // defaults to 60s and aborts long generations (Claude Sonnet 4.5 docs
        // routinely take 90-180s) regardless of the local CURLOPT_TIMEOUT.
        $body['timeout'] = $timeout;
        // [2026-03-25] Unified API namespace: migrate llm/router/v1/chat → bizcity/v1/llm/chat
        // $endpoint = $this->get_gateway_url() . '/wp-json/llm/router/v1/chat';
        $endpoint = $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/chat';

        $this->debug_log( 'chat_gateway() POST', [
            'endpoint' => $endpoint, 'model' => $model, 'purpose' => $purpose,
            'timeout' => $timeout, 'key_prefix' => substr( $api_key, 0, 8 ) . '…',
        ] );

        $response = wp_remote_post( $endpoint, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        // Strip BOM (UTF-8 byte-order mark) that bizcity.vn may prepend
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $raw_body = trim( $raw_body );
        $decoded  = json_decode( $raw_body, true );

        if ( $code === 402 ) {
            $base['error'] = $decoded['message'] ?? 'Hết credit. Vui lòng nạp thêm tại bizcity.vn';
            return $base;
        }

        if ( $code === 429 ) {
            // Gateway returns {"success":false,"error":"...","tier":"free"}
            // Fields can be in 'message' or 'error' depending on gateway version.
            $err_msg          = $decoded['message'] ?? $decoded['error'] ?? 'Rate limit exceeded.';
            $base['error']    = $err_msg;
            $retry_after      = wp_remote_retrieve_header( $response, 'retry-after' );
            $remaining        = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining-requests' );
            $reset            = wp_remote_retrieve_header( $response, 'x-ratelimit-reset-requests' );
            // [2026-07-15 Johnny Chu] HOTFIX — some gateways only return
            // `master_level` (without `tier`) on quota errors.
            $tier             = $decoded['tier'] ?? $decoded['master_level'] ?? '';
            // [2026-06-10 Johnny Chu] R-QUOTA-KEY — new quota field from enriched 429 response.
            // Hub now returns quota=true for actual quota exhaustion (not transient rate limit).
            // Use that as primary signal; fall back to text heuristic for older router versions.
            $is_quota         = ! empty( $decoded['quota'] )
                             || stripos( $err_msg, 'quota' ) !== false
                             || stripos( $err_msg, 'monthly' ) !== false
                             || stripos( $err_msg, 'daily' ) !== false
                             || ( $tier !== '' && $retry_after === '' );
            // [2026-06-09 Johnny Chu] R-QUOTA-KEY — guard false-positive 0/0 block.
            // A REAL quota always carries a positive cap (requests/day or USD).
            // If the 429 is flagged quota but sends no usable cap (0/0), it is a
            // transient rate-limit or a stale/older gateway response — do NOT hard-
            // block the user with a misleading "0/0" message; treat as retryable.
            if ( $is_quota ) {
                $cap_r = is_array( $decoded ) ? (int)   ( $decoded['cap_requests_day'] ?? 0 ) : 0;
                $cap_u = is_array( $decoded ) ? (float) ( $decoded['cap_usd'] ?? 0 )          : 0.0;
                if ( $cap_r <= 0 && $cap_u <= 0 ) {
                    $is_quota = false;
                }
            }
            $base['quota_exhausted'] = $is_quota;
            // [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — label layer=hub (Layer 1 master)
            // so callers and SSE emitters can distinguish from Layer 2 (local membership).
            if ( $is_quota ) {
                $base['quota_layer']        = 'hub';
                $base['tier']               = $tier;
                // [2026-06-10 Johnny Chu] R-QUOTA-KEY — forward usage counters from 429 body
                // so FE can display used/limit bar in QuotaErrorBanner.
                $base['used_requests']      = isset( $decoded['used_requests'] )      ? (int)   $decoded['used_requests']      : 0;
                $base['cap_requests_day']   = isset( $decoded['cap_requests_day'] )   ? (int)   $decoded['cap_requests_day']   : 0;
                $base['used_usd']           = isset( $decoded['used_usd'] )           ? (float) $decoded['used_usd']           : 0.0;
                $base['cap_usd']            = isset( $decoded['cap_usd'] )            ? (float) $decoded['cap_usd']            : 0.0;
                $base['reset_at']           = isset( $decoded['reset_at'] )           ? (string)$decoded['reset_at']           : '';
                $base['quota_period']       = isset( $decoded['period'] )             ? (string)$decoded['period']             : 'day';
                $base['master_level']       = isset( $decoded['master_level'] )       ? (string)$decoded['master_level']       : $tier;
                // [2026-07-15 Johnny Chu] PHASE-MASTER-KEY-SYNC — a quota
                // response is authoritative for the exact Bearer key; sync the
                // local per-site plan cache immediately instead of waiting for poll.
                $resolved_master_level = sanitize_key( (string) $base['master_level'] );
                if ( $resolved_master_level !== '' ) {
                    update_option( 'bizcity_hub_master_level', $resolved_master_level );
                    update_option( 'bizcity_hub_master_tier', self::tier_bucket_from_master_level( $resolved_master_level ) );
                    update_option( 'bizcity_hub_master_sync_ts', time() );
                }
                // Cache usage to site_options for admin panel display.
                update_site_option( 'bizcity_hub_quota_used_requests',  $base['used_requests'] );
                update_site_option( 'bizcity_hub_quota_cap_requests',   $base['cap_requests_day'] );
                update_site_option( 'bizcity_hub_quota_used_usd',       $base['used_usd'] );
                update_site_option( 'bizcity_hub_quota_cap_usd',        $base['cap_usd'] );
                update_site_option( 'bizcity_hub_quota_reset_at',       $base['reset_at'] );
                update_site_option( 'bizcity_hub_quota_period',         $base['quota_period'] );
            }
            // [2026-07-17 Johnny Chu] HOTFIX — suppress noisy 429 flood in production logs.
            // Opt-in diagnostics: define( 'BIZCITY_LLM_LOG_429', true ) in wp-config.php.
            if ( defined( 'BIZCITY_LLM_LOG_429' ) && BIZCITY_LLM_LOG_429 ) {
                error_log( sprintf(
                    '[bizcity-llm] 429 %s layer=hub: model=%s purpose=%s tier=%s retry_after=%s remaining=%s reset=%s used=%d/%d usd=%.4f/%.4f msg=%s raw=%s',
                    $is_quota ? 'QUOTA_EXHAUSTED' : 'RATE_LIMIT',
                    $model, $purpose, $tier ?: 'n/a',
                    $retry_after ?: 'n/a',
                    $remaining   ?: 'n/a',
                    $reset       ?: 'n/a',
                    $base['used_requests'] ?? 0, $base['cap_requests_day'] ?? 0,
                    $base['used_usd'] ?? 0, $base['cap_usd'] ?? 0,
                    $err_msg,
                    substr( $raw_body, 0, 400 )
                ) );
            }
            return $base;
        }

        if ( isset( $decoded['success'] ) && $decoded['success'] ) {
            $msg    = $decoded['message'] ?? '';
            $finish = $decoded['finish_reason'] ?? '';
            // [2026-06-09 Johnny Chu] PHASE-D D-EMPTY-REPLY — Hub returned success:true
            // but message is empty string. Possible causes: content_filter, safety block,
            // provider returned empty choices[], or hub-side parsing issue.
            // Demote to error so callers surface a specific reason instead of blank bubble.
            if ( $msg === '' ) {
                $reason = 'AI trả về phản hồi rỗng';
                if ( $finish !== '' ) {
                    $reason .= " (finish_reason: {$finish})";
                }
                $reason .= '. Model: ' . $model . '.';
                error_log( "[bizcity-llm] chat_gateway WARN empty-reply: model={$model} purpose={$purpose} finish_reason={$finish} usage=" . wp_json_encode( $decoded['usage'] ?? [] ) . ' raw=' . substr( $raw_body, 0, 300 ) );
                $base['error'] = $reason;
                return $base; // success = false — triggers fallback / error path in callers
            }
            $merged = array_merge( $base, [
                'success'       => true,
                'message'       => $msg,
                'model'         => $decoded['model'] ?? $model,
                'model_primary' => $decoded['model_primary'] ?? $model,
                'fallback_used' => $decoded['fallback_used'] ?? false,
                'usage'         => $decoded['usage'] ?? [],
            ] );
            // Pass through optional fields used by image-modality callers.
            if ( ! empty( $decoded['images'] ) ) {
                $merged['images'] = $decoded['images'];
            }
            if ( isset( $decoded['finish_reason'] ) ) {
                $merged['finish_reason'] = $decoded['finish_reason'];
            }
            // Keep full upstream response for advanced consumers (e.g. extractors).
            $merged['raw'] = $decoded;
            return $merged;
        }

        // [2026-06-17 Johnny Chu] R-ERROR-UX — structured error with provider details
        $error_msg = $decoded['error'] ?? $decoded['message'] ?? "HTTP {$code}";
        $base['error'] = $error_msg;

        // Forward provider_error block from hub for transparency
        if ( ! empty( $decoded['provider_error'] ) && is_array( $decoded['provider_error'] ) ) {
            $pe = $decoded['provider_error'];
            $base['provider_error'] = $pe;

            // Map to R-ERROR-UX fields
            $base['error_code']    = $this->map_provider_error_code( $pe );
            $base['error_hint']    = $this->get_provider_error_hint( $pe, $code );
            $base['error_help']    = $this->get_provider_error_help( $pe, $code );

            error_log( sprintf(
                '[bizcity-llm] chat_gateway ERROR: HTTP %d | provider=%s provider_code=%d type=%s code=%s model=%s purpose=%s msg=%s',
                $code,
                $pe['provider'] ?? 'unknown',
                $pe['http_code'] ?? 0,
                $pe['type'] ?? '',
                $pe['code'] ?? '',
                $model,
                $purpose,
                substr( $pe['message'] ?? $error_msg, 0, 200 )
            ) );
        } else {
            // No provider_error block — generic error
            $base['error_code'] = $code >= 500 ? 'gateway_error' : 'llm_error';
            $base['error_hint'] = $code >= 500
                ? 'Máy chủ AI đang gặp sự cố. Thử lại sau vài phút.'
                : 'Kiểm tra kết nối và thử lại.';
            $base['error_help'] = 'llm_generic_error';

            error_log( "[bizcity-llm] chat_gateway error: HTTP {$code} model={$model} purpose={$purpose} error={$error_msg}" );
        }

        return $base;
    }

    /**
     * Map provider error to R-ERROR-UX error code.
     * [2026-06-17 Johnny Chu] R-ERROR-UX — provider error code mapping
     *
     * @param array $pe Provider error block
     * @return string Error code for catalog
     */
    private function map_provider_error_code( array $pe ): string {
        $http = (int) ( $pe['http_code'] ?? 0 );
        $type = (string) ( $pe['type'] ?? '' );
        $code = (string) ( $pe['code'] ?? '' );

        // OpenRouter specific codes
        if ( $http === 401 || $type === 'authentication_error' ) {
            return 'api_key_invalid';
        }
        if ( $http === 402 ) {
            return 'quota_exceeded';
        }
        if ( $http === 429 || $type === 'rate_limit_error' ) {
            return 'rate_limited';
        }
        if ( $http === 400 || $type === 'invalid_request_error' ) {
            return 'invalid_param';
        }
        if ( $http === 503 || $type === 'service_unavailable' ) {
            return 'gateway_degraded';
        }
        if ( $http >= 500 ) {
            return 'gateway_error';
        }
        if ( $code === 'content_filter' || stripos( $type, 'content' ) !== false ) {
            return 'content_filtered';
        }

        return 'llm_error';
    }

    /**
     * Get user-friendly hint for provider error.
     * [2026-06-17 Johnny Chu] R-ERROR-UX — provider error hints
     *
     * @param array $pe Provider error block
     * @param int   $http_code HTTP status code
     * @return string Hint message in Vietnamese
     */
    private function get_provider_error_hint( array $pe, int $http_code ): string {
        $type = (string) ( $pe['type'] ?? '' );
        $pe_http = (int) ( $pe['http_code'] ?? $http_code );

        if ( $pe_http === 401 || $type === 'authentication_error' ) {
            return 'API key của BizCity không hợp lệ. Liên hệ hỗ trợ.';
        }
        if ( $pe_http === 402 ) {
            return 'Tài khoản BizCity hết quota. Nâng cấp gói hoặc nạp thêm credit.';
        }
        if ( $pe_http === 429 || $type === 'rate_limit_error' ) {
            return 'Đang có nhiều request. Chờ vài giây rồi thử lại.';
        }
        if ( $pe_http === 503 || $type === 'service_unavailable' ) {
            return 'Máy chủ AI tạm ngưng. Thử lại sau 1-2 phút.';
        }
        if ( $pe_http === 502 || $pe_http === 504 ) {
            return 'Máy chủ AI không phản hồi. Thử lại hoặc đổi model khác.';
        }
        if ( $pe_http >= 500 ) {
            return 'Lỗi máy chủ AI. Thử lại sau vài phút.';
        }

        return 'Có lỗi xảy ra. Thử lại hoặc liên hệ hỗ trợ.';
    }

    /**
     * Get help_code for provider error.
     * [2026-06-17 Johnny Chu] R-ERROR-UX — provider error help codes
     *
     * @param array $pe Provider error block
     * @param int   $http_code HTTP status code
     * @return string Help code for HELP_CATALOG
     */
    private function get_provider_error_help( array $pe, int $http_code ): string {
        $type = (string) ( $pe['type'] ?? '' );
        $pe_http = (int) ( $pe['http_code'] ?? $http_code );

        if ( $pe_http === 401 ) {
            return 'llm_auth_error';
        }
        if ( $pe_http === 402 ) {
            return 'llm_quota_exceeded';
        }
        if ( $pe_http === 429 ) {
            return 'llm_rate_limited';
        }
        if ( $pe_http >= 500 ) {
            return 'llm_server_error';
        }

        return 'llm_generic_error';
    }

    private function chat_stream_gateway( array $messages, array $options, $on_chunk = null ): array {
        $stream_t0 = microtime( true );
        $base = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return $this->chat_gateway( $messages, $options );
        }

        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $options['temperature'] ?? 0.7 ),
            'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            'purpose'     => $purpose,
            'stream'      => true,
            'site_url'    => home_url(),
        ];
        if ( ! empty( $options['extra_body'] ) ) {
            $body = array_merge( $body, $options['extra_body'] );
        }

        $timeout  = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();
        if ( $timeout <= 0 ) {
            $timeout = $this->get_timeout();
        }
        // Forward timeout to the router so its proxy curl call uses the same value.
        // Without this, the router REST handler defaults to 90s and kills long
        // generations at ~92s wall clock regardless of the local CURLOPT_TIMEOUT.
        $body['timeout'] = $timeout;
        // SSE streaming MUST use the direct llm/router/v1/chat/stream endpoint.
        // bizcity/v1/llm/chat/stream is handled by Hub REST which internally dispatches
        // into handle_chat_stream() — the ob_end_flush() calls inside it cannot escape
        // Hub REST's own WP_REST_Server output-buffer layer, so all SSE chunks arrive
        // buffered in one burst at the end (cURL sees HTTP 200 + empty stream, then falls
        // back to blocking). The legacy llm/router/v1 route goes directly to the SSE
        // handler with no intermediate wrapper, so real-time streaming works correctly.
        // DO NOT migrate this URL to bizcity/v1 until Hub REST supports SSE pass-through.
        $endpoint = $this->get_gateway_url() . '/wp-json/llm/router/v1/chat/stream';

        $full_text     = '';
        $usage         = [];
        $finish_reason = '';
        $buffer        = '';
        $raw_response  = '';
        $actual_model  = '';

        error_log( '[LLM-Client] v2-curl_multi | mode=gateway | endpoint=' . $endpoint . ' | model=' . $model );

        // Chunk queue: WRITEFUNCTION pushes parsed deltas here.
        // The curl_multi polling loop reads from the queue and calls on_chunk
        // *outside* the cURL callback context.
        //
        // Why curl_multi instead of curl_exec:
        // On LiteSpeed / shared hosting, curl_exec blocks the PHP process inside
        // the C library. The web server sees zero PHP output for 8-10s, interprets
        // it as an idle LSAPI connection, and kills the process → CURLE_WRITE_ERROR 23.
        // curl_multi keeps PHP's execution loop active, preventing the kill.
        $chunk_queue  = [];
        $on_keepalive = $options['on_keepalive'] ?? null;

        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'X-Site-URL: ' . home_url(),
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_BUFFERSIZE     => 1024,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_text, &$usage, &$finish_reason, &$buffer, &$raw_response, &$actual_model, &$chunk_queue ) {
                $raw_len = strlen( $data );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    static $write_t0;
                    if ( ! $write_t0 ) { $write_t0 = microtime( true ); }
                    $w_ms = (int) round( ( microtime( true ) - $write_t0 ) * 1000 );
                    error_log( '[llm-stream-write] +' . $w_ms . 'ms | recv ' . $raw_len . 'B | queue=' . count( $chunk_queue ) . ' | full_len=' . strlen( $full_text ) );
                }
                if ( $buffer === '' && $raw_len >= 3 && substr( $data, 0, 3 ) === "\xEF\xBB\xBF" ) {
                    $data = substr( $data, 3 );
                }
                $raw_response .= $data;

                $data = str_replace( [ "\r\n", "\r" ], "\n", $data );
                $buffer .= $data;

                while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
                    $line   = trim( substr( $buffer, 0, $nl ) );
                    $buffer = substr( $buffer, $nl + 1 );

                    if ( $line === '' || stripos( $line, 'data:' ) !== 0 ) {
                        continue;
                    }
                    $json_str = ltrim( substr( $line, 5 ) );
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }

                    if ( ! empty( $chunk['done'] ) ) {
                        if ( isset( $chunk['usage'] ) ) {
                            $usage = $chunk['usage'];
                        }
                        if ( isset( $chunk['model'] ) ) {
                            $actual_model = $chunk['model'];
                        }
                        continue;
                    }

                    $delta = $chunk['choices'][0]['delta']['content']
                          ?? $chunk['delta']
                          ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        $chunk_queue[] = [ $delta, $full_text ];
                    }
                    if ( isset( $chunk['choices'][0]['finish_reason'] ) && ! empty( $chunk['choices'][0]['finish_reason'] ) ) {
                        $finish_reason = (string) $chunk['choices'][0]['finish_reason'];
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }
                return $raw_len;
            },
        ] );

        // ── curl_multi polling loop ──
        $mh = curl_multi_init();
        curl_multi_add_handle( $mh, $ch );
        $last_ping = microtime( true );

        do {
            $status = curl_multi_exec( $mh, $still_running );

            // Drain chunk queue → deliver to browser immediately
            if ( ! empty( $chunk_queue ) && is_callable( $on_chunk ) ) {
                foreach ( $chunk_queue as $qc ) {
                    call_user_func( $on_chunk, $qc[0], $qc[1] );
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && count( $chunk_queue ) > 0 ) {
                    $p_ms = (int) round( ( microtime( true ) - $stream_t0 ) * 1000 );
                    error_log( '[llm-stream-poll] +' . $p_ms . 'ms | drained ' . count( $chunk_queue ) . ' chunks | full_len=' . strlen( $full_text ) );
                }
                $chunk_queue = [];
                $last_ping = microtime( true );
            }

            // Send SSE keepalive ping every 3 seconds to prevent web server
            // from killing the browser→PHP connection during LLM thinking time.
            $now = microtime( true );
            if ( is_callable( $on_keepalive ) && ( $now - $last_ping ) >= 3.0 ) {
                call_user_func( $on_keepalive );
                $last_ping = $now;
            }

            if ( $still_running > 0 ) {
                curl_multi_select( $mh, 0.05 );
            }
        } while ( $still_running > 0 && $status === CURLM_OK );

        // Flush remaining queued chunks
        if ( ! empty( $chunk_queue ) && is_callable( $on_chunk ) ) {
            foreach ( $chunk_queue as $qc ) {
                call_user_func( $on_chunk, $qc[0], $qc[1] );
            }
            $chunk_queue = [];
        }

        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        $ok        = ( $curl_err === '' && $http_code >= 200 && $http_code < 300 );
        curl_multi_remove_handle( $mh, $ch );
        curl_close( $ch );
        curl_multi_close( $mh );

        $stream_ms = (int) round( ( microtime( true ) - $stream_t0 ) * 1000 );

        if ( ! $ok || $http_code < 200 || $http_code >= 300 ) {
            $err_detail = $buffer ? mb_substr( trim( $buffer ), 0, 500 ) : '';
            $base['error'] = $curl_err ?: "HTTP {$http_code}";
            if ( $err_detail ) {
                $base['error'] .= ' | ' . $err_detail;
            }
            $base['stream_ms']       = $stream_ms;
            $base['stream_fallback'] = false;
            if ( $http_code === 429 ) {
                $retry_after = '';
                // Parse Retry-After from buffered response headers if available
                if ( preg_match( '/retry-after:\s*(\S+)/i', $raw_response, $m ) ) { $retry_after = $m[1]; }
                // Parse actual error message from JSON body (gateway returns {error:...,tier:...})
                $decoded_err = json_decode( $err_detail, true );
                $real_err    = is_array( $decoded_err ) ? ( $decoded_err['message'] ?? $decoded_err['error'] ?? '' ) : '';
                $tier        = is_array( $decoded_err ) ? ( $decoded_err['tier'] ?? '' ) : '';
                if ( $real_err ) {
                    $base['error'] = $real_err;
                }
                // [2026-06-10 Johnny Chu] R-QUOTA-KEY — use enriched quota fields from 429 response.
                $is_quota = ! empty( $decoded_err['quota'] )
                         || stripos( $base['error'], 'quota' ) !== false
                         || stripos( $base['error'], 'monthly' ) !== false
                         || stripos( $base['error'], 'daily' ) !== false
                         || ( $tier !== '' && $retry_after === '' );
                // [2026-06-09 Johnny Chu] R-QUOTA-KEY — guard false-positive 0/0 block.
                // A REAL quota always carries a positive cap (requests/day or USD).
                // If flagged quota but no usable cap (0/0) → transient/stale 429, not a
                // hard block. Avoid the misleading "0/0" quota error on the user.
                if ( $is_quota ) {
                    $cap_r = is_array( $decoded_err ) ? (int)   ( $decoded_err['cap_requests_day'] ?? 0 ) : 0;
                    $cap_u = is_array( $decoded_err ) ? (float) ( $decoded_err['cap_usd'] ?? 0 )          : 0.0;
                    if ( $cap_r <= 0 && $cap_u <= 0 ) {
                        $is_quota = false;
                    }
                }
                $base['quota_exhausted'] = $is_quota;
                // [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — label layer=hub (Layer 1 master)
                if ( $is_quota ) {
                    $base['quota_layer']      = 'hub';
                    $base['tier']             = $tier;
                    $base['used_requests']    = is_array( $decoded_err ) ? (int)   ( $decoded_err['used_requests']    ?? 0 ) : 0;
                    $base['cap_requests_day'] = is_array( $decoded_err ) ? (int)   ( $decoded_err['cap_requests_day'] ?? 0 ) : 0;
                    $base['used_usd']         = is_array( $decoded_err ) ? (float) ( $decoded_err['used_usd']         ?? 0 ) : 0.0;
                    $base['cap_usd']          = is_array( $decoded_err ) ? (float) ( $decoded_err['cap_usd']          ?? 0 ) : 0.0;
                    $base['reset_at']         = is_array( $decoded_err ) ? (string)( $decoded_err['reset_at']         ?? '' ) : '';
                    $base['quota_period']     = is_array( $decoded_err ) ? (string)( $decoded_err['period']           ?? 'day' ) : 'day';
                    $base['master_level']     = is_array( $decoded_err ) ? (string)( $decoded_err['master_level']     ?? $tier ) : $tier;
                    update_site_option( 'bizcity_hub_quota_used_requests', $base['used_requests'] );
                    update_site_option( 'bizcity_hub_quota_cap_requests',  $base['cap_requests_day'] );
                    update_site_option( 'bizcity_hub_quota_used_usd',      $base['used_usd'] );
                    update_site_option( 'bizcity_hub_quota_cap_usd',       $base['cap_usd'] );
                    update_site_option( 'bizcity_hub_quota_reset_at',      $base['reset_at'] );
                    update_site_option( 'bizcity_hub_quota_period',        $base['quota_period'] );
                }
                error_log( sprintf(
                    '[bizcity-llm] STREAM 429 %s layer=hub: model=%s stream_ms=%d tier=%s retry_after=%s used=%d/%d usd=%.4f/%.4f msg=%s',
                    $is_quota ? 'QUOTA_EXHAUSTED' : 'RATE_LIMIT',
                    $model, $stream_ms, $tier ?: 'n/a', $retry_after ?: 'n/a',
                    $base['used_requests'] ?? 0, $base['cap_requests_day'] ?? 0,
                    $base['used_usd'] ?? 0, $base['cap_usd'] ?? 0,
                    mb_substr( $base['error'], 0, 200 )
                ) );
            } else {
                // [2026-06-17 Johnny Chu] R-ERROR-UX — structured error for non-429 stream errors
                $decoded_err = json_decode( $err_detail, true );
                if ( is_array( $decoded_err ) && ! empty( $decoded_err['provider_error'] ) ) {
                    $pe = $decoded_err['provider_error'];
                    $base['provider_error'] = $pe;
                    $base['error_code']     = $this->map_provider_error_code( $pe );
                    $base['error_hint']     = $this->get_provider_error_hint( $pe, $http_code );
                    $base['error_help']     = $this->get_provider_error_help( $pe, $http_code );

                    error_log( sprintf(
                        '[bizcity-llm] STREAM ERROR: HTTP %d | provider=%s provider_http=%d type=%s model=%s stream_ms=%d msg=%s',
                        $http_code,
                        $pe['provider'] ?? 'unknown',
                        $pe['http_code'] ?? 0,
                        $pe['type'] ?? '',
                        $model,
                        $stream_ms,
                        substr( $pe['message'] ?? $base['error'], 0, 200 )
                    ) );
                } else {
                    // Generic error without provider_error block
                    $base['error_code'] = $http_code >= 500 ? 'gateway_error' : 'llm_error';
                    $base['error_hint'] = $http_code >= 500
                        ? 'Máy chủ AI đang gặp sự cố. Thử lại sau vài phút.'
                        : 'Kiểm tra kết nối và thử lại.';
                    $base['error_help'] = 'llm_generic_error';

                    error_log( "[bizcity-llm] chat_stream_gateway error: HTTP {$http_code} curl={$curl_err} model={$model} stream_ms={$stream_ms} full_text_len=" . strlen( $full_text ) . " body={$err_detail}" );
                }
            }
            // CURLE_WRITE_ERROR (23) often means the client SSE connection dropped
            // while cURL was still receiving data. If we managed to collect text
            // before the abort, recover it so the reply can still be logged/saved.
            if ( ! empty( $full_text ) ) {
                $base['success'] = true;
                $base['message'] = $full_text;
                $base['model']   = $actual_model ?: $model;
                $base['usage']   = $usage;
                $base['finish_reason'] = $finish_reason;
            }
            return $base;
        }

        $base['success'] = true;
        $base['message'] = $full_text;
        $base['model']   = $actual_model ?: $model;
        $base['usage']   = $usage;
        $base['finish_reason'] = $finish_reason;
        $base['stream_ms'] = $stream_ms;
        $base['stream_fallback'] = false;

        // If upstream/proxy buffered SSE into a single payload, recover chunks from raw body.
        if ( $full_text === '' && $raw_response !== '' ) {
            $normalized = str_replace( [ "\r\n", "\r" ], "\n", $raw_response );
            if ( preg_match_all( '/(?:^|\n)\s*data:\s*(.+?)(?=\n\s*data:\s*|\z)/s', $normalized, $matches ) ) {
                foreach ( $matches[1] as $json_str ) {
                    $json_str = trim( $json_str );
                    if ( $json_str === '' || $json_str === '[DONE]' ) {
                        continue;
                    }

                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }

                    if ( ! empty( $chunk['done'] ) ) {
                        if ( isset( $chunk['usage'] ) ) {
                            $usage = $chunk['usage'];
                        }
                        if ( isset( $chunk['model'] ) ) {
                            $actual_model = $chunk['model'];
                        }
                        continue;
                    }

                    $delta = $chunk['choices'][0]['delta']['content']
                          ?? $chunk['delta']
                          ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $delta, $full_text );
                        }
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }

                $base['message'] = $full_text;
                $base['model']   = $actual_model ?: $model;
                $base['usage']   = $usage;
            }

            // Some gateways/proxies may downgrade stream endpoint to blocking JSON.
            // Recover here to avoid a second blocking retry call (saves ~10-20s).
            if ( $full_text === '' ) {
                $trimmed = trim( $raw_response );
                $decoded_raw = json_decode( $trimmed, true );
                if ( is_array( $decoded_raw ) ) {
                    $msg = $decoded_raw['message']
                        ?? $decoded_raw['choices'][0]['message']['content']
                        ?? '';
                    if ( is_string( $msg ) && $msg !== '' ) {
                        $full_text = $msg;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $msg, $msg );
                        }
                        $usage = is_array( $decoded_raw['usage'] ?? null ) ? $decoded_raw['usage'] : $usage;
                        $actual_model = (string) ( $decoded_raw['model'] ?? $actual_model ?: $model );
                        $base['message'] = $full_text;
                        $base['model']   = $actual_model ?: $model;
                        $base['usage']   = $usage;
                        $base['stream_fallback'] = false;
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[bizcity-llm] chat_stream_gateway recovered from non-SSE JSON body | model=' . $base['model'] . ' | stream_ms=' . $stream_ms . ' | msg_len=' . strlen( $msg ) );
                        }
                    }
                }
            }
        }

        // Gateway returned 200 but no content was streamed — likely gateway-side error (BOM-only, silent crash, etc.)
        // Retry as non-streaming call so user gets a response
        if ( $full_text === '' && is_callable( $on_chunk ) ) {
            $preview = mb_substr( trim( str_replace( [ "\r", "\n" ], ' ', $raw_response ) ), 0, 240 );
            error_log( '[bizcity-llm] chat_stream_gateway empty-stream diagnostics | model=' . $model . ' | http=' . $http_code . ' | stream_ms=' . $stream_ms . ' | raw_len=' . strlen( $raw_response ) . ' | buf_len=' . strlen( $buffer ) . ' | preview=' . $preview );
            error_log( '[bizcity-llm] chat_stream_gateway: 200 OK but empty stream — retrying as blocking call' );
            $blocking = $this->chat_gateway( $messages, $options );
            $blocking['stream_fallback'] = true;
            $blocking['stream_ms'] = $stream_ms;
            if ( ! empty( $blocking['message'] ) ) {
                // Send the full reply as a single SSE chunk so the frontend receives it
                call_user_func( $on_chunk, $blocking['message'], $blocking['message'] );
            }
            return $blocking;
        }

        return $base;
    }

    /* ================================================================
     *  DIRECT MODE — call OpenRouter directly
     * ================================================================ */

    private function chat_direct( array $messages, array $options ): array {
        $base    = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        $base['provider'] = 'openrouter';
        $purpose  = $options['purpose'] ?? 'chat';

        $primary = $options['model'] ?? '';
        if ( empty( $primary ) ) {
            $primary = $this->get_model( $purpose );
        }

        $fallback = array_key_exists( 'fallback_model', $options )
            ? (string) $options['fallback_model']
            : $this->get_fallback_model( $purpose );

        $no_fallback = ! empty( $options['no_fallback'] ) || $fallback === '' || $fallback === $primary;

        $base['model_primary'] = $primary;

        $result = $this->call_openrouter( $primary, $messages, $options, $api_key );
        if ( $result['success'] ) {
            return array_merge( $base, $result );
        }

        $primary_error = $result['error'];
        error_log( "[BizCity_LLM] Primary model '{$primary}' failed: {$primary_error}" );

        if ( $no_fallback ) {
            return array_merge( $base, $result );
        }

        error_log( "[BizCity_LLM] Falling back to '{$fallback}'" );
        $fb_result = $this->call_openrouter( $fallback, $messages, $options, $api_key );

        if ( $fb_result['success'] ) {
            $fb_result['fallback_used'] = true;
            $fb_result['model_primary'] = $primary;
            $fb_result['error']         = '';
            return array_merge( $base, $fb_result );
        }

        $base['error'] = "Primary ({$primary}): {$primary_error} | Fallback ({$fallback}): {$fb_result['error']}";
        return $base;
    }

    /**
     * Single model call to OpenRouter (direct mode).
     */
    private function call_openrouter( string $model_id, array $messages, array $options, string $api_key ): array {
        $result = [
            'success'       => false,
            'message'       => '',
            'model'         => $model_id,
            'model_primary' => $model_id,
            'fallback_used' => false,
            'provider'      => 'openrouter',
            'usage'         => [],
            'error'         => '',
        ];

        $timeout = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();

        $body = array_merge(
            [
                'model'       => $model_id,
                'messages'    => $messages,
                'temperature' => floatval( $options['temperature'] ?? 0.7 ),
                'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            ],
            $options['extra_body'] ?? []
        );

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => $options['http_referer'] ?? network_home_url(),
                'X-Title'       => $options['x_title'] ?? $this->get_setting( 'site_name', 'BizCity' ),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
            $result['success'] = true;
            $result['message'] = trim( $decoded['choices'][0]['message']['content'] );
            $result['model']   = $decoded['model'] ?? $model_id;
            $result['usage']   = $decoded['usage'] ?? [];
            return $result;
        }

        $result['error'] = $decoded['error']['message'] ?? 'Không nhận được phản hồi từ LLM.';
        return $result;
    }

    private function chat_stream_direct( array $messages, array $options, $on_chunk = null ): array {
        $base    = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return $this->chat_direct( $messages, $options );
        }

        $base['provider'] = 'openrouter';
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $result = $this->stream_single_model( $model, $messages, $options, $api_key, $on_chunk );

        if ( $result['success'] ) {
            $result['fallback_used'] = false;
            return array_merge( $base, $result );
        }

        // Fallback only if no partial output was streamed
        if ( ! empty( $result['message'] ) ) {
            return array_merge( $base, $result, [ 'fallback_used' => false ] );
        }

        $fallback = $options['fallback_model'] ?? '';
        if ( empty( $fallback ) ) {
            $fallback = $this->get_fallback_model( $purpose );
        }
        if ( empty( $fallback ) || $fallback === $model ) {
            return array_merge( $base, $result, [ 'fallback_used' => false ] );
        }

        error_log( "[BizCity-LLM] Stream direct primary '{$model}' failed: {$result['error']}. Trying '{$fallback}'" );
        $fb = $this->stream_single_model( $fallback, $messages, $options, $api_key, $on_chunk );
        $fb['fallback_used'] = true;
        $fb['model_primary'] = $model;
        $fb['model']         = $fallback;

        if ( ! $fb['success'] ) {
            $fb['error'] = "Primary ({$model}): {$result['error']} | Fallback ({$fallback}): {$fb['error']}";
        }

        return array_merge( $base, $fb );
    }

    /**
     * Stream a single model call to OpenRouter (used by chat_stream_direct).
     */
    private function stream_single_model( string $model, array $messages, array $options, string $api_key, $on_chunk = null ): array {
        $result = [
            'success' => false,
            'message' => '',
            'model'   => $model,
            'usage'   => [],
            'error'   => '',
        ];

        $body = array_merge(
            [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => floatval( $options['temperature'] ?? 0.7 ),
                'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
                'stream'      => true,
            ],
            $options['extra_body'] ?? []
        );

        $full_text = '';
        $usage     = [];
        $buffer    = '';

        $ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'HTTP-Referer: ' . ( $options['http_referer'] ?? network_home_url() ),
                'X-Title: ' . ( $options['x_title'] ?? $this->get_setting( 'site_name', 'BizCity' ) ),
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout(),
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_text, &$usage, &$buffer, $on_chunk ) {
                $buffer .= $data;
                while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
                    $line   = trim( substr( $buffer, 0, $nl ) );
                    $buffer = substr( $buffer, $nl + 1 );

                    if ( $line === '' || strpos( $line, 'data: ' ) !== 0 ) {
                        continue;
                    }
                    $json_str = substr( $line, 6 );
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }
                    $delta = $chunk['choices'][0]['delta']['content'] ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $delta, $full_text );
                        }
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }
                return strlen( $data );
            },
        ] );

        $ok        = curl_exec( $ch );
        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        curl_close( $ch );

        if ( ! $ok || $http_code < 200 || $http_code >= 300 ) {
            $result['error'] = $curl_err ?: "HTTP {$http_code}";
            if ( ! empty( $full_text ) ) {
                $result['success'] = true;
                $result['message'] = $full_text;
                $result['usage']   = $usage;
            }
            return $result;
        }

        $result['success'] = true;
        $result['message'] = $full_text;
        $result['usage']   = $usage;
        return $result;
    }

    /* ================================================================
     *  Embeddings
     * ================================================================ */

    public function embeddings( $input, array $options = [] ): array {
        $start = microtime( true );
        $model = $options['model'] ?? $this->get_model( 'embedding' );

        $this->debug_log( 'embeddings() START', [ 'mode' => $this->get_mode(), 'model' => $model ] );

        // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — file log is append-only, no pending row.
        $_llm_pending_id = class_exists( 'BizCity_LLM_Usage_File_Log' )
            ? BizCity_LLM_Usage_File_Log::log_pending( [
                'service'         => 'embedding',
                'mode'            => $this->get_mode(),
                'purpose'         => 'embedding',
                'endpoint'        => 'embeddings',
                'model_requested' => $model,
            ] )
            : 0;

        if ( $this->get_mode() === 'direct' ) {
            // Direct mode disabled for IP protection — force gateway
            $result = $this->embeddings_gateway( $input, $options );
        } else {
            $result = $this->embeddings_gateway( $input, $options );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'embeddings() END', [ 'success' => $result['success'], 'ms' => $ms ] );

        $this->log_usage( [
            'success' => $result['success'] ?? false,
            'model'   => $result['model'] ?? $model,
            'usage'   => $result['usage'] ?? [],
            'error'   => $result['error'] ?? '',
        ], $options, 'embeddings', $model, $ms, $_llm_pending_id );

        return $result;
    }

    /**
     * Re-rank semantic candidates through Hub Branch #8.
     *
     * @param string $query
     * @param array<int,array<string,mixed>> $candidates
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function rerank( string $query, array $candidates, array $options = [] ): array {
        // [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.3 — client-side wrapper for Hub Branch #8 rerank, fail-open to caller order.
        $start = microtime( true );
        $base = [
            'success'   => false,
            '_degraded' => true,
            'results'   => [],
            'model'     => '',
            'usage'     => [],
            'error'     => '',
            'error_code'=> '',
            'http_status' => 0,
        ];

        $query = trim( $query );
        $candidates = array_values( $candidates );
        // [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.3 — fail-open returns caller candidates per Branch #8 client contract.
        $base['results'] = $candidates;
        if ( $query === '' || empty( $candidates ) ) {
            $base['error_code'] = 'invalid_param';
            $base['error'] = 'Query hoặc candidates rỗng.';
            return $base;
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error_code'] = 'api_key_missing';
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }
        if ( $this->is_dbinit_degraded_global() ) {
            $base['error_code'] = 'site_unmapped';
            $base['error'] = 'Multishard domain mapping chưa hợp lệ; rerank bị chặn để tránh đọc sai tenant.';
            $base['http_status'] = 503;
            return $base;
        }

        $_llm_pending_id = class_exists( 'BizCity_LLM_Usage_File_Log' )
            ? BizCity_LLM_Usage_File_Log::log_pending( [
                'service'         => 'tools',
                'mode'            => $this->get_mode(),
                'purpose'         => (string) ( $options['purpose'] ?? 'rerank' ),
                'endpoint'        => 'rerank',
                'model_requested' => (string) ( $options['model'] ?? '' ),
            ] )
            : 0;

        $top_k = isset( $options['top_k'] ) ? max( 1, min( 50, (int) $options['top_k'] ) ) : min( 8, count( $candidates ) );
        $body = [
            'query'      => $query,
            'candidates' => $candidates,
            'top_k'      => $top_k,
        ];
        foreach ( [ 'model', 'trace_id', 'session_id', 'conversation_id' ] as $field ) {
            if ( ! empty( $options[ $field ] ) ) {
                $body[ $field ] = $options[ $field ];
            }
        }

        $response = wp_remote_post( $this->get_gateway_url() . '/wp-json/llm/router/v1/rerank', [
            'timeout'     => intval( $options['timeout'] ?? 10 ),
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'        => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            $base['error_code'] = $response->get_error_code();
            $base['http_status'] = 502;
            $this->log_usage( $base, [ 'service' => 'tools', 'purpose' => 'rerank' ], 'rerank', (string) ( $options['model'] ?? '' ), intval( ( microtime( true ) - $start ) * 1000 ), $_llm_pending_id );
            return $base;
        }

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( is_array( $decoded ) && ! empty( $decoded['success'] ) && isset( $decoded['results'] ) && is_array( $decoded['results'] ) ) {
            $result = array_merge( $base, [
                'success'    => true,
                '_degraded'  => false,
                'results'    => $decoded['results'],
                'model'      => (string) ( $decoded['model'] ?? '' ),
                'usage'      => (array) ( $decoded['usage'] ?? [] ),
                'cost_usd'   => $decoded['cost_usd'] ?? null,
                'latency_ms' => $decoded['latency_ms'] ?? null,
                'cached'     => ! empty( $decoded['cached'] ),
                'http_status'=> $http_status,
            ] );
            $this->log_usage( $result, [ 'service' => 'tools', 'purpose' => 'rerank' ], 'rerank', (string) ( $options['model'] ?? '' ), intval( ( microtime( true ) - $start ) * 1000 ), $_llm_pending_id );
            return $result;
        }

        $base['error'] = is_array( $decoded ) ? (string) ( $decoded['message'] ?? $decoded['error'] ?? 'Rerank failed.' ) : 'Rerank gateway returned invalid JSON.';
        $base['error_code'] = is_array( $decoded ) ? (string) ( $decoded['code'] ?? 'rerank_failed' ) : 'rerank_invalid_response';
        $base['http_status'] = $http_status > 0 ? $http_status : 502;
        $this->log_usage( $base, [ 'service' => 'tools', 'purpose' => 'rerank' ], 'rerank', (string) ( $options['model'] ?? '' ), intval( ( microtime( true ) - $start ) * 1000 ), $_llm_pending_id );
        return $base;
    }

    private function embeddings_gateway( $input, array $options ): array {
        $base = [
            'success' => false, 'embeddings' => [], 'model' => '',
            'usage' => [], 'error' => '', 'error_code' => '',
            'http_status' => 0, 'dimensions' => 0,
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            $base['error_code'] = 'api_key_missing';
            return $base;
        }

        // [2026-07-15 Johnny Chu] HOTFIX — never send embedding work with
        // global fallback context when the tenant domain did not resolve.
        if ( $this->is_dbinit_degraded_global() ) {
            $base['error'] = 'Multishard domain mapping chưa hợp lệ; embedding bị chặn để tránh ghi sai tenant.';
            $base['error_code'] = 'site_unmapped';
            $base['http_status'] = 503;
            return $base;
        }

        $texts = is_array( $input ) ? array_values( $input ) : [ (string) $input ];
        $model = $options['model'] ?? $this->get_model( 'embedding' );

        // [2026-03-25] Unified API namespace: migrate llm/router/v1/embeddings → bizcity/v1/llm/embeddings
        // $response = wp_remote_post( $this->get_gateway_url() . '/wp-json/llm/router/v1/embeddings', [
        $response = wp_remote_post( $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/embeddings', [
            'timeout'     => intval( $options['timeout'] ?? 30 ),
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model' => $model,
                'input' => count( $texts ) === 1 ? $texts[0] : $texts,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            $base['error_code'] = $response->get_error_code();
            $base['http_status'] = 502;
            return $base;
        }

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $decoded['success'] ) && ! empty( $decoded['embeddings'] ) ) {
            return array_merge( $base, [
                'success'    => true,
                'embeddings' => $decoded['embeddings'],
                'model'      => $decoded['model'] ?? $model,
                'usage'      => $decoded['usage'] ?? [],
                'dimensions' => $decoded['dimensions'] ?? ( ! empty( $decoded['embeddings'][0] ) ? count( $decoded['embeddings'][0] ) : 0 ),
            ] );
        }

        $base['error'] = is_array( $decoded )
            ? ( $decoded['message'] ?? $decoded['error'] ?? 'Embedding failed.' )
            : 'Embedding gateway returned invalid JSON.';
        $base['error_code'] = is_array( $decoded )
            ? (string) ( $decoded['code'] ?? ( $http_status === 429 ? 'rate_limited' : 'embed_failed' ) )
            : 'embed_invalid_response';
        $base['http_status'] = $http_status > 0 ? $http_status : 502;
        if ( is_array( $decoded ) ) {
            foreach ( [ 'quota', 'master_level', 'used_requests', 'cap_requests_day', 'reset_at', 'help_code', 'hint' ] as $field ) {
                if ( array_key_exists( $field, $decoded ) ) {
                    $base[ $field ] = $decoded[ $field ];
                }
            }
        }
        return $base;
    }

    private function embeddings_direct( $input, array $options ): array {
        $base = [
            'success' => false, 'embeddings' => [], 'model' => '',
            'usage' => [], 'error' => '', 'dimensions' => 0,
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        $texts   = is_array( $input ) ? array_values( $input ) : [ (string) $input ];
        $primary = $options['model'] ?? $this->get_model( 'embedding' );
        $timeout = intval( $options['timeout'] ?? 30 );

        $result = $this->call_openrouter_embeddings( $primary, $texts, $api_key, $timeout );
        if ( $result['success'] ) {
            return $result;
        }

        $fallback = $this->get_fallback_model( 'embedding' );
        if ( $fallback && $fallback !== $primary ) {
            $fb = $this->call_openrouter_embeddings( $fallback, $texts, $api_key, $timeout );
            if ( $fb['success'] ) {
                return $fb;
            }
            $base['error'] = "Primary ({$primary}): {$result['error']} | Fallback ({$fallback}): {$fb['error']}";
        } else {
            $base['error'] = $result['error'];
        }

        return $base;
    }

    private function call_openrouter_embeddings( string $model_id, array $texts, string $api_key, int $timeout ): array {
        $result = [
            'success' => false, 'embeddings' => [], 'model' => $model_id,
            'usage' => [], 'error' => '', 'dimensions' => 0,
        ];

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/embeddings', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => network_home_url(),
                'X-Title'       => get_site_option( 'site_name', 'BizCity' ),
            ],
            'body' => wp_json_encode( [
                'model' => $model_id,
                'input' => count( $texts ) === 1 ? $texts[0] : $texts,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            $embeddings = [];
            foreach ( $decoded['data'] as $item ) {
                if ( isset( $item['embedding'] ) ) {
                    $embeddings[] = $item['embedding'];
                }
            }
            if ( ! empty( $embeddings ) ) {
                $result['success']    = true;
                $result['embeddings'] = $embeddings;
                $result['model']      = $decoded['model'] ?? $model_id;
                $result['usage']      = $decoded['usage'] ?? [];
                $result['dimensions'] = count( $embeddings[0] );
                return $result;
            }
        }

        $result['error'] = $decoded['error']['message'] ?? 'Không nhận được embedding.';
        return $result;
    }

    /* ================================================================
     *  Fetch available models (from gateway or OpenRouter)
     * ================================================================ */

    public function get_available_models( ?string $category = null ): array {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient (not network-wide)
        $transient_key = 'bizcity_llm_models';
        $cached = get_transient( $transient_key );

        if ( false === $cached ) {
            if ( $this->get_mode() === 'gateway' ) {
                $cached = $this->fetch_gateway_models();
            } else {
                $cached = $this->fetch_openrouter_models();
            }
            if ( ! empty( $cached ) ) {
                set_transient( $transient_key, $cached, DAY_IN_SECONDS );
            }
        }

        if ( $category && is_array( $cached ) ) {
            return array_filter( $cached, fn( $m ) =>
                isset( $m['id'] ) && (
                    str_contains( strtolower( $m['id'] ), strtolower( $category ) ) ||
                    str_contains( strtolower( $m['name'] ?? '' ), strtolower( $category ) )
                )
            );
        }

        return is_array( $cached ) ? $cached : [];
    }

    private function fetch_gateway_models(): array {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) return [];

        // [2026-03-25] Unified API namespace: migrate llm/router/v1/models → bizcity/v1/llm/models
        // $response = wp_remote_get( $this->get_gateway_url() . '/wp-json/llm/router/v1/models', [
        $response = wp_remote_get( $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/models', [
            'timeout'     => 15,
            'redirection' => 0,
            'headers'     => [ 'Authorization' => 'Bearer ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) ) return [];

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'] ?? $body['models'] ?? [];
    }

    private function fetch_openrouter_models(): array {
        $api_key  = $this->get_api_key();
        $response = wp_remote_get( 'https://openrouter.ai/api/v1/models', [
            'timeout' => 15,
            'headers' => empty( $api_key ) ? [] : [ 'Authorization' => 'Bearer ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) ) return [];

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'] ?? [];
    }

    public function bust_models_cache(): void {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site transient
        delete_transient( 'bizcity_llm_models' );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    private function empty_result(): array {
        return [
            'success'       => false,
            'message'       => '',
            'model'         => '',
            'model_primary' => '',
            'fallback_used' => false,
            'provider'      => $this->get_mode() === 'direct' ? 'openrouter' : 'bizcity-gateway',
            'usage'         => [],
            'error'         => '',
        ];
    }

    /**
     * Record a usage log entry if the usage-log class is loaded.
     */
    // [2026-06-10 Johnny Chu] R-LLM-USAGE — write to per-blog clients table, not hub table.
    // Hub (bizcity-llm-router) owns bizcity_llm_usage (base_prefix).
    // Client (this plugin) owns bizcity_llm_usage_clients (per-blog prefix).
    private function log_usage( array $result, array $options, string $endpoint, string $model_requested, int $ms, int $pending_id = 0 ): void {
        if ( ! class_exists( 'BizCity_LLM_Usage_File_Log' ) ) {
            return;
        }
        $log_data = [
            'service'         => $options['service'] ?? '',
            'mode'            => $this->get_mode(),
            'flow'            => $options['flow'] ?? '',
            'surface'         => $options['surface'] ?? '',
            'channel'         => $options['channel'] ?? '',
            'purpose'         => $options['purpose'] ?? 'chat',
            'endpoint'        => $endpoint,
            'model_requested' => $model_requested,
            'model_used'      => $result['model'] ?? '',
            'fallback_used'   => $result['fallback_used'] ?? false,
            'success'         => $result['success'] ?? false,
            'usage'           => $result['usage'] ?? [],
            'latency_ms'      => $ms,
            'error'           => $result['error'] ?? '',
        ];
        if ( $pending_id > 0 ) {
            BizCity_LLM_Usage_File_Log::log_done( $pending_id, $log_data );
            return;
        }
        BizCity_LLM_Usage_File_Log::log( $log_data );
    }

    /* ================================================================
     *  Image Generation (via gateway or direct OpenAI)
     * ================================================================ */

    /**
     * Generate an image via the LLM gateway (or direct OpenAI in direct mode).
     *
     * @param string $prompt  Image prompt.
     * @param array  $options { model, size, n, timeout, site_url }
     * @return array { success, image_url, b64_json, model, error }
     */
    public function generate_image( string $prompt, array $options = [] ): array {
        $start = microtime( true );

        $this->debug_log( 'generate_image() START', [
            'mode' => $this->get_mode(), 'model' => $options['model'] ?? 'gpt-image-1',
        ] );

        // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — file log is append-only, no pending row.
        $_llm_pending_id = class_exists( 'BizCity_LLM_Usage_File_Log' )
            ? BizCity_LLM_Usage_File_Log::log_pending( [
                'service'         => 'image',
                'mode'            => $this->get_mode(),
                'purpose'         => 'image',
                'endpoint'        => 'image',
                'model_requested' => $options['model'] ?? 'gpt-image-1',
            ] )
            : 0;

        if ( $this->get_mode() === 'direct' ) {
            $result = $this->generate_image_direct( $prompt, $options );
        } else {
            $result = $this->generate_image_gateway( $prompt, $options );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'generate_image() END', [
            'success' => $result['success'], 'ms' => $ms,
        ] );

        $this->log_usage( [
            'success' => $result['success'],
            'model'   => $result['model'] ?? 'gpt-image-1',
            'usage'   => [],
            'error'   => $result['error'] ?? '',
        ], array_merge( $options, [ 'purpose' => 'image', 'service' => 'image' ] ), 'image', $options['model'] ?? 'gpt-image-1', $ms, $_llm_pending_id );

        return $result;
    }

    /**
     * Image generation via gateway REST endpoint.
     */
    private function generate_image_gateway( string $prompt, array $options ): array {
        $base = [
            'success'   => false,
            'image_url' => '',
            'b64_json'  => '',
            'model'     => $options['model'] ?? 'gpt-image-1',
            'error'     => '',
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }

        $body = [
            'prompt'  => $prompt,
            'model'   => $options['model'] ?? 'gpt-image-1',
            'size'    => $options['size'] ?? '1024x1024',
            'n'       => intval( $options['n'] ?? 1 ),
            'timeout' => intval( $options['timeout'] ?? 120 ),
            'site_url' => home_url(),
        ];

        // Optional reference images (HTTPS URL or data:image/...;base64,...).
        // Gateway uses these to anchor / edit the output (e.g. QR Studio places a
        // QR onto a template, doc image pipeline preserves brand style).
        if ( ! empty( $options['input_images'] ) && is_array( $options['input_images'] ) ) {
            $body['input_images'] = array_values( array_filter(
                $options['input_images'],
                'is_string'
            ) );
        }
        if ( ! empty( $options['stream'] ) ) {
            $body['stream'] = true;
        }

        // [2026-06-06 Johnny Chu] PHASE-IMAGE-SIMPLE — fix 404: server only registers
        // llm/router/v1/images/generations (class-router-rest.php:95). bizcity/v1/llm/images/generations
        // has no matching route (Hub REST not deployed for image endpoint yet).
        $endpoint = $this->get_gateway_url() . '/wp-json/llm/router/v1/images/generations';

        $response = wp_remote_post( $endpoint, [
            'timeout'     => intval( $options['timeout'] ?? 120 ) + 10,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $raw_body, true );

        // [2026-07-04 Johnny Chu] PHASE-IMG-TPL — log raw response so 502 HTML pages are visible.
        if ( $code !== 200 ) {
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            $is_json      = ( strpos( (string) $content_type, 'application/json' ) !== false );
            error_log( sprintf(
                '[BIZCITY][generate_image_gateway] HTTP %d content-type=%s decoded_success=%s decoded_error=%s raw_preview=%.200s',
                $code,
                (string) $content_type,
                isset( $decoded['success'] ) ? ( $decoded['success'] ? 'true' : 'false' ) : 'n/a',
                (string) ( $decoded['error'] ?? '' ),
                $is_json ? '' : substr( strip_tags( $raw_body ), 0, 200 )
            ) );
        }

        if ( $code === 402 ) {
            $base['error'] = $decoded['error'] ?? 'Hết credit. Vui lòng nạp thêm tại bizcity.vn';
            return $base;
        }

        if ( ! empty( $decoded['success'] ) ) {
            return array_merge( $base, [
                'success'   => true,
                'image_url' => $decoded['image_url'] ?? '',
                'b64_json'  => $decoded['b64_json'] ?? '',
                'model'     => $decoded['model'] ?? $base['model'],
            ] );
        }

        // [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — surface router/provider JSON errors instead of hiding them behind HTTP status.
        $base['error'] = is_array( $decoded ) && ! empty( $decoded['error'] )
            ? (string) $decoded['error']
            : 'Image generation failed. HTTP ' . $code;
        return $base;
    }

    /**
     * Image generation direct to OpenAI (fallback when in direct mode).
     */
    private function generate_image_direct( string $prompt, array $options ): array {
        $base = [
            'success'   => false,
            'image_url' => '',
            'b64_json'  => '',
            'model'     => $options['model'] ?? 'gpt-image-1',
            'error'     => '',
        ];

        // In direct mode, use local OpenAI key
        $api_key = get_option( 'twf_openai_api_key', '' );
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'bztimg_openai_key', '' );
        }
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenAI API key chưa được cấu hình.';
            return $base;
        }

        $model   = $options['model'] ?? 'gpt-image-1';
        $size    = $options['size'] ?? '1024x1024';
        $timeout = intval( $options['timeout'] ?? 120 );

        $body = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $decoded['data'][0]['url'] ) ) {
            $base['success']   = true;
            $base['image_url'] = $decoded['data'][0]['url'];
            return $base;
        }

        if ( ! empty( $decoded['data'][0]['b64_json'] ) ) {
            $base['success']  = true;
            $base['b64_json'] = $decoded['data'][0]['b64_json'];
            return $base;
        }

        $base['error'] = $decoded['error']['message'] ?? 'OpenAI image generation failed.';
        return $base;
    }

    // [2026-06-10 Johnny Chu] USAGE-ROLLUP-SPEC — generic gateway REST helper (fail-OPEN)
    /**
     * Make a GET or DELETE request to any /wp-json/bizcity/v1/* endpoint on the gateway.
     * Always returns an array — WP_Error or HTTP failure yields ['ok'=>false,'_degraded'=>true].
     *
     * @param string $path     e.g. '/account/usage-by-type'
     * @param array  $query    Query params to append.
     * @param string $method   'GET' or 'DELETE'.
     * @param int    $timeout  Seconds.
     */
    public function gateway_get( string $path, array $query = [], string $method = 'GET', int $timeout = 10 ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return [ 'ok' => false, 'error' => 'no_api_key', '_degraded' => true ];
        }
        $url = rtrim( $this->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1' . $path;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( array_map( 'strval', $query ), $url );
        }
        $response = wp_remote_request( $url, [
            'method'      => $method,
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_code(), '_degraded' => true ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body = substr( $body, 3 );
        }
        $decoded = json_decode( trim( $body ), true );
        if ( ! is_array( $decoded ) ) {
            return [ 'ok' => false, 'error' => 'decode_failed', '_degraded' => true, 'http_code' => $code ];
        }
        if ( $code < 200 || $code >= 300 ) {
            return array_merge( [ '_degraded' => true, 'http_code' => $code ], $decoded );
        }
        return $decoded;
    }

    /**
     * Make a POST request to any /wp-json/bizcity/v1/* endpoint on the gateway.
     *
     * @param string $path    e.g. '/account/api-keys'
     * @param array  $body    JSON body.
     * @param int    $timeout Seconds.
     */
    public function gateway_post( string $path, array $body = [], int $timeout = 10 ) {
        $api_key = $this->get_api_key();
        if ( $api_key === '' ) {
            return [ 'ok' => false, 'error' => 'no_api_key', '_degraded' => true ];
        }
        $url = rtrim( $this->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1' . $path;
        $response = wp_remote_post( $url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_code(), '_degraded' => true ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body_str = (string) wp_remote_retrieve_body( $response );
        if ( substr( $body_str, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $body_str = substr( $body_str, 3 );
        }
        $decoded = json_decode( trim( $body_str ), true );
        if ( ! is_array( $decoded ) ) {
            return [ 'ok' => false, 'error' => 'decode_failed', '_degraded' => true, 'http_code' => $code ];
        }
        if ( $code < 200 || $code >= 300 ) {
            return array_merge( [ '_degraded' => true, 'http_code' => $code ], $decoded );
        }
        return $decoded;
    }
}
