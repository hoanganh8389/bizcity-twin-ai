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
 * BizCity LLM — Unified AI Gateway Client
 *
 * Thin client for all LLM calls across the BizCity Twin AI platform.
 * Supports two connection modes:
 *   • Gateway (default) — proxies through bizcity.vn / bizcity.ai REST API
 *   • Direct  — user's own OpenRouter API key (self-hosted, no gateway)
 *
 * All plugins should call bizcity_llm_chat() / bizcity_llm_chat_stream()
 * instead of any direct HTTP calls to LLM providers.
 *
 * Backward-compatible: bizcity_openrouter_*() functions still work.
 *
 * @package BizCity_LLM
 * @version 1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_LLM_VERSION' ) ) {
    define( 'BIZCITY_LLM_VERSION', '1.0.0' );
}
if ( ! defined( 'BIZCITY_LLM_DIR' ) ) {
    define( 'BIZCITY_LLM_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_LLM_URL' ) ) {
    define( 'BIZCITY_LLM_URL', plugin_dir_url( __FILE__ ) );
}

/* ── Load sub-classes (skip if already loaded by legacy mu-plugin) ── */
if ( class_exists( 'BizCity_LLM_Client' ) ) {
    return; // Already loaded by legacy mu-plugin — skip duplicate requires
}
require_once BIZCITY_LLM_DIR . '/includes/helpers-deprecation.php';
require_once BIZCITY_LLM_DIR . '/includes/class-llm-models.php';
require_once BIZCITY_LLM_DIR . '/includes/class-llm-client.php';
require_once BIZCITY_LLM_DIR . '/includes/class-search-client.php';
require_once BIZCITY_LLM_DIR . '/includes/class-llm-usage-log.php';
require_once BIZCITY_LLM_DIR . '/includes/class-llm-settings.php';
require_once BIZCITY_LLM_DIR . '/includes/class-smart-gateway.php';
require_once BIZCITY_LLM_DIR . '/includes/class-google-hub.php';

/* ── Boot ── */
add_action( 'plugins_loaded', function () {
    BizCity_LLM_Client::instance();
    BizCity_Search_Client::instance();
    BizCity_LLM_Settings::instance();
    BizCity_LLM_Usage_Log::maybe_install();
}, 1 );

/* ======================================================================
 * PUBLIC HELPER FUNCTIONS — Canonical API
 * All plugins should use these instead of direct HTTP calls.
 * ====================================================================== */

/**
 * Send a chat-completion request via the configured LLM gateway.
 *
 * @param array $messages  OpenAI-format messages array.
 * @param array $options {
 *   @type string $model       Override model ID.
 *   @type string $purpose     'chat'|'vision'|'code'|'fast'|'router'|'planner'|'executor' etc.
 *   @type float  $temperature Default 0.7.
 *   @type int    $max_tokens  Default 3000.
 *   @type int    $timeout     Timeout in seconds.
 *   @type array  $extra_body  Extra params merged into the API body.
 * }
 * @return array { success, message, model, provider, usage, error, fallback_used }
 */
function bizcity_llm_chat( array $messages, array $options = [] ): array {
    return BizCity_LLM_Client::instance()->chat( $messages, $options );
}

/**
 * Send a streaming chat-completion request.
 *
 * @param array         $messages  OpenAI-format messages.
 * @param array         $options   Same as bizcity_llm_chat().
 * @param callable|null $on_chunk  function(string $delta, string $full_so_far): void
 * @return array Same shape as bizcity_llm_chat().
 */
function bizcity_llm_chat_stream( array $messages, array $options = [], $on_chunk = null ): array {
    return BizCity_LLM_Client::instance()->chat_stream( $messages, $options, $on_chunk );
}

/**
 * Get the configured model for a given purpose.
 *
 * @param string $purpose
 * @return string Model ID.
 */
function bizcity_llm_get_model( string $purpose = 'chat' ): string {
    return BizCity_LLM_Client::instance()->get_model( $purpose );
}

/**
 * Check whether the LLM gateway is configured and ready.
 *
 * @return bool
 */
function bizcity_llm_is_ready(): bool {
    return BizCity_LLM_Client::instance()->is_ready();
}

/**
 * Create embeddings for one or more texts.
 *
 * @param string|string[] $input   Single text or array of texts.
 * @param array           $options { model, timeout }
 * @return array { success, embeddings, model, usage, error, dimensions }
 */
function bizcity_llm_embeddings( $input, array $options = [] ): array {
    return BizCity_LLM_Client::instance()->embeddings( $input, $options );
}

/**
 * Generate an image via the BizCity LLM gateway (or direct OpenAI).
 *
 * @param string $prompt  Image prompt.
 * @param array  $options { model, size, n, timeout }
 * @return array { success, image_url, b64_json, model, error }
 */
function bizcity_llm_generate_image( string $prompt, array $options = [] ): array {
    return BizCity_LLM_Client::instance()->generate_image( $prompt, $options );
}

/**
 * Get the current connection mode.
 *
 * @return string 'gateway' | 'direct'
 */
function bizcity_llm_mode(): string {
    return BizCity_LLM_Client::instance()->get_mode();
}

/* ======================================================================
 * GOOGLE HUB — Canonical 1-API for Google connection (since 2026-06-04)
 * All Google tools (Scheduler, Gmail, Drive, Sheets, Docs, Slides,
 * Calendar, Contacts) MUST use these helpers to obtain a hub connect URL.
 * No tool may build its own /google-auth/connect URL.
 * ====================================================================== */

if ( ! function_exists( 'bizcity_google_hub_connect_url' ) ) {
    /**
     * Build the hub Google connect URL with HMAC sig (R-GW-8 compliant).
     *
     * @param array $args  See BizCity_Google_Hub::get_connect_url().
     * @return string|WP_Error
     */
    function bizcity_google_hub_connect_url( array $args = [] ) {
        return BizCity_Google_Hub::get_connect_url( $args );
    }
}

if ( ! function_exists( 'bizcity_google_hub_service_url' ) ) {
    /**
     * Per-service incremental scope URL (gmail / calendar / drive / …).
     *
     * @param string $service
     * @param string $return_url
     * @return string|WP_Error
     */
    function bizcity_google_hub_service_url( string $service, string $return_url = '' ) {
        return BizCity_Google_Hub::get_service_connect_url( $service, $return_url );
    }
}

if ( ! function_exists( 'bizcity_google_hub_status' ) ) {
    /**
     * Connection status snapshot — { connected, email, scope, services[], path, hub_domain }.
     *
     * @return array
     */
    function bizcity_google_hub_status(): array {
        return BizCity_Google_Hub::get_status();
    }
}

/* ======================================================================
 * BACKWARD COMPATIBILITY — bizcity_openrouter_*() aliases
 * These map 1:1 to the new bizcity_llm_*() functions.
 * Only created when the real bizcity-openrouter plugin is NOT present
 * (i.e. on client sites that only have bizcity-llm installed).
 * On the Hub server, bizcity-openrouter defines these authoritatively.
 * ====================================================================== */

if ( ! file_exists( BIZCITY_LLM_DIR . '/../bizcity-openrouter/bootstrap.php' ) ) {

    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        function bizcity_openrouter_chat( array $messages, array $options = [] ): array {
            return bizcity_llm_chat( $messages, $options );
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_chat_stream' ) ) {
        function bizcity_openrouter_chat_stream( array $messages, array $options = [], $on_chunk = null ): array {
            return bizcity_llm_chat_stream( $messages, $options, $on_chunk );
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_get_model' ) ) {
        function bizcity_openrouter_get_model( string $purpose = 'chat' ): string {
            return bizcity_llm_get_model( $purpose );
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_is_ready' ) ) {
        function bizcity_openrouter_is_ready(): bool {
            return bizcity_llm_is_ready();
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_get_api_key' ) ) {
        function bizcity_openrouter_get_api_key(): string {
            return BizCity_LLM_Client::instance()->get_api_key();
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_get_models' ) ) {
        function bizcity_openrouter_get_models( ?string $category = null ): array {
            return BizCity_LLM_Client::instance()->get_available_models( $category );
        }
    }

    if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
        function bizcity_openrouter_embeddings( $input, array $options = [] ): array {
            return bizcity_llm_embeddings( $input, $options );
        }
    }

    /* ── Backward-compat class alias ── */
    if ( ! class_exists( 'BizCity_OpenRouter' ) ) {
        class_alias( 'BizCity_LLM_Client', 'BizCity_OpenRouter' );
    }

} /* end backward-compat block */

/* ======================================================================
 * TAVILY HELPER FUNCTIONS (kept here for centralized API key management)
 * ====================================================================== */

if ( ! function_exists( 'bizcity_tavily_api_key' ) ) {
    function bizcity_tavily_api_key(): string {
        $key = (string) get_site_option( 'bizcity_tavily_api_key', '' );
        if ( empty( $key ) && defined( 'TAVILY_API_KEY' ) ) {
            $key = TAVILY_API_KEY;
        }
        return $key;
    }
}

if ( ! function_exists( 'bizcity_tavily_is_ready' ) ) {
    function bizcity_tavily_is_ready(): bool {
        return ! empty( bizcity_tavily_api_key() );
    }
}

/* ======================================================================
 * SEARCH HELPER FUNCTIONS — Canonical API for web search
 * All plugins should use these instead of BCN_Tavily_Client directly.
 * ====================================================================== */

/**
 * Search the web via BizCity Search Router.
 *
 * @param string $query        Search query.
 * @param int    $max_results  1–20, default 10.
 * @param array  $options      { search_depth, topic, include_domains, exclude_domains, ... }
 * @return array|WP_Error  Normalized results array or WP_Error.
 */
if ( ! function_exists( 'bizcity_search' ) ) {
    function bizcity_search( string $query, int $max_results = 10, array $options = [] ) {
        return BizCity_Search_Client::instance()->search( $query, $max_results, $options );
    }
}

/**
 * Extract full-text content from URLs via BizCity Search Router.
 *
 * @param string[] $urls  Up to 20 URLs.
 * @return array|WP_Error
 */
if ( ! function_exists( 'bizcity_search_extract' ) ) {
    function bizcity_search_extract( array $urls ) {
        return BizCity_Search_Client::instance()->extract( $urls );
    }
}

/**
 * Check whether BizCity Search is configured and ready.
 */
if ( ! function_exists( 'bizcity_search_is_ready' ) ) {
    function bizcity_search_is_ready(): bool {
        return BizCity_Search_Client::instance()->is_ready();
    }
}

/* ── Google OAuth Hub helpers ────────────────────────────────────────── */

/**
 * Get a Google OAuth authorization URL from the hub.
 * User is redirected here to connect their Google account.
 *
 * @param string $return_url URL to redirect back to after OAuth completes.
 * @param array  $scopes     Extra OAuth scopes (e.g. ['https://www.googleapis.com/auth/calendar']).
 * @return array{auth_url:string,redirect_uri:string}|array{error:string}
 */
if ( ! function_exists( 'bizcity_llm_google_auth_url' ) ) {
    function bizcity_llm_google_auth_url( string $return_url, array $scopes = [] ): array {
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            return [ 'error' => 'LLM client not configured' ];
        }
        $args = [ 'return_url' => $return_url ];
        if ( ! empty( $scopes ) ) {
            $args['scopes'] = implode( ',', $scopes );
        }
        $url  = $client->get_gateway_url() . '/wp-json/bizcity/v1/google/auth-url?' . http_build_query( $args );
        $resp = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $client->get_api_key() ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'error' => $resp->get_error_message() ];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body ) || ! isset( $body['auth_url'] ) ) {
            return [ 'error' => $body['message'] ?? 'Unexpected response' ];
        }
        return $body;
    }
}

/**
 * Get the current Google access token for the hub user owning this site's API key.
 * Auto-refreshes on the hub side if expired.
 *
 * @return array{connected:bool,access_token:string,google_email:string,expires_at:string}|array{connected:false,error:string}
 */
if ( ! function_exists( 'bizcity_llm_google_token' ) ) {
    function bizcity_llm_google_token(): array {
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            return [ 'connected' => false, 'error' => 'LLM client not configured' ];
        }
        $url  = $client->get_gateway_url() . '/wp-json/bizcity/v1/google/token';
        $resp = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $client->get_api_key() ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'connected' => false, 'error' => $resp->get_error_message() ];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return is_array( $body ) ? $body : [ 'connected' => false, 'error' => 'Invalid response' ];
    }
}

/**
 * Get Google connection status (does not return the token).
 *
 * @return array{connected:bool,google_email:string,expires_at:string,scope:string,has_credentials:bool}
 */
if ( ! function_exists( 'bizcity_llm_google_status' ) ) {
    function bizcity_llm_google_status(): array {
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            return [ 'connected' => false, 'has_credentials' => false, 'error' => 'LLM client not configured' ];
        }
        $url  = $client->get_gateway_url() . '/wp-json/bizcity/v1/google/status';
        $resp = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $client->get_api_key() ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'connected' => false, 'has_credentials' => false, 'error' => $resp->get_error_message() ];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return is_array( $body ) ? $body : [ 'connected' => false, 'has_credentials' => false ];
    }
}

/**
 * Disconnect Google (revoke token on the hub).
 *
 * @return bool True on success.
 */
if ( ! function_exists( 'bizcity_llm_google_disconnect' ) ) {
    function bizcity_llm_google_disconnect(): bool {
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            return false;
        }
        $url  = $client->get_gateway_url() . '/wp-json/bizcity/v1/google/disconnect';
        $resp = wp_remote_post( $url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $client->get_api_key(),
                'Content-Type'  => 'application/json',
            ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ! empty( $body['disconnected'] );
    }
}
