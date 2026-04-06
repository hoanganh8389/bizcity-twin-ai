<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Connection Gate — Server Origin Check + Tier Resolution.
 * Determines connection state:
 *   - bizcity: API key valid + gateway = bizcity.vn → full access (tier from API key)
 *   - standalone: no key or non-bizcity gateway → restricted mode
 *
 * API key = license + API access. No separate license key needed.
 * Tier (lite/pro/enterprise) is resolved from the API key account.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * This file is part of Bizcity Twin AI.
 * Unauthorized copying, modification, or distribution is prohibited.
 * Sao chép, chỉnh sửa hoặc phân phối trái phép bị nghiêm cấm.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Connection_Gate {

    private static ?self $instance = null;

    private string $state   = 'standalone'; // bizcity | standalone
    private string $tier    = 'lite';       // lite | pro | enterprise
    private ?string $api_key = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->resolve();
    }

    /**
     * Resolve connection state from LLM settings.
     */
    private function resolve(): void {
        // Read LLM mode and gateway URL from site options
        $mode        = get_site_option( 'bizcity_llm_mode', 'gateway' );
        $gateway_url = get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );
        $api_key     = get_site_option( 'bizcity_llm_api_key', '' );

        $this->api_key = $api_key ?: null;

        // Gateway mode pointing to bizcity.vn = full access
        if ( $mode === 'gateway' && $api_key ) {
            $gateway_host = wp_parse_url( $gateway_url, PHP_URL_HOST ) ?: '';
            if ( strpos( $gateway_host, 'bizcity.vn' ) !== false || strpos( $gateway_host, 'bizcity.ai' ) !== false ) {
                $this->state = 'bizcity';
                $this->tier  = $this->resolve_tier_from_key( $api_key );
                return;
            }
        }

        // Fallback: standalone mode
        $this->state = 'standalone';
        $this->tier  = 'lite';
    }

    /**
     * Resolve tier from API key prefix or cached account info.
     */
    private function resolve_tier_from_key( string $api_key ): string {
        // Check cached tier (refreshed periodically via cron/API call)
        $cached = get_site_transient( 'bizcity_api_tier' );
        if ( $cached && in_array( $cached, [ 'lite', 'pro', 'enterprise' ], true ) ) {
            return $cached;
        }

        // Default to lite until tier is verified via API
        return 'lite';
    }

    /* ── Public API ─────────────────────────────────────────── */

    public function get_state(): string {
        return $this->state;
    }

    public function is_bizcity(): bool {
        return $this->state === 'bizcity';
    }

    public function is_standalone(): bool {
        return $this->state === 'standalone';
    }

    public function get_tier(): string {
        return $this->tier;
    }

    public function has_tier( string $required ): bool {
        $hierarchy = [ 'lite' => 0, 'pro' => 1, 'enterprise' => 2 ];
        $current   = $hierarchy[ $this->tier ] ?? 0;
        $needed    = $hierarchy[ $required ]   ?? 0;
        return $current >= $needed;
    }

    public function get_api_key(): ?string {
        return $this->api_key;
    }
}
