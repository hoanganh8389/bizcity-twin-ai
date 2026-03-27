<?php
/**
 * BizCity Market — Update Checker (Client-side)
 *
 * Lazy-load approach: updates are checked when the Marketplace page opens
 * (via AJAX), NOT via background cron. Results are cached in a transient.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Market
 * @since      1.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Update_Checker {

    const CRON_HOOK    = 'bizcity_market_check_updates';
    const TRANSIENT    = 'bizcity_market_available_updates';
    const CACHE_TTL    = DAY_IN_SECONDS;

    /**
     * Boot — no cron. Register AJAX handler + admin hooks only.
     */
    public static function boot(): void {
        // ── Remove legacy cron (migrated to lazy-load) ──
        wp_clear_scheduled_hook( self::CRON_HOOK );

        // AJAX: lazy update check triggered when Marketplace page opens
        add_action( 'wp_ajax_bizcity_market_lazy_updates', array( __CLASS__, 'ajax_lazy_check' ) );

        // Admin notice — reads from cached transient only (no remote call)
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );

        // Manual force-check from admin
        add_action( 'admin_post_bizcity_market_force_update_check', array( __CLASS__, 'handle_force_check' ) );
    }

    /**
     * AJAX: Lazy update check — called by Marketplace JS on page load.
     *
     * If a fresh transient exists, returns cached data immediately.
     * Otherwise fetches from remote, caches, and returns.
     */
    public static function ajax_lazy_check(): void {
        check_ajax_referer( 'bizcity_market_nonce', 'nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( 'No permission.' );
        }

        // Force refresh requested?
        $force = ! empty( $_GET['force'] );

        // Return cached if fresh (and not forced)
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                wp_send_json_success( array(
                    'updates' => $cached,
                    'count'   => count( $cached ),
                    'cached'  => true,
                ) );
            }
        }

        // Fetch from remote
        $updates = self::check();

        wp_send_json_success( array(
            'updates' => $updates,
            'count'   => count( $updates ),
            'cached'  => false,
        ) );
    }

    /**
     * Get list of installed bundled plugins with their versions.
     *
     * @return array { slug => version, ... }
     */
    public static function get_installed(): array {
        $installed = array();
        $base      = BIZCITY_TWIN_AI_DIR . 'plugins/';

        if ( ! is_dir( $base ) ) {
            return $installed;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $dirs = glob( $base . '*', GLOB_ONLYDIR );
        if ( empty( $dirs ) ) {
            return $installed;
        }

        foreach ( $dirs as $dir ) {
            $slug      = basename( $dir );
            $main_file = $dir . '/' . $slug . '.php';

            if ( ! file_exists( $main_file ) ) {
                continue;
            }

            $data = get_plugin_data( $main_file, false, false );
            if ( ! empty( $data['Version'] ) ) {
                $installed[ $slug ] = $data['Version'];
            }
        }

        return $installed;
    }

    /**
     * Check remote catalog for updates.
     * Stores results in transient.
     *
     * @return array { slug => { new_version, changelog, zip_size }, ... }
     */
    public static function check(): array {
        if ( ! BizCity_Remote_Catalog::is_available() ) {
            return array();
        }

        $installed = self::get_installed();
        if ( empty( $installed ) ) {
            delete_transient( self::TRANSIENT );
            return array();
        }

        $response = BizCity_Remote_Catalog::proxy_post( 'updates', array( 'plugins' => $installed ) );
        if ( is_wp_error( $response ) ) {
            error_log( '[BizCity Update Checker] Error: ' . $response->get_error_message() );
            return array();
        }

        $updates = isset( $response['updates'] ) ? $response['updates'] : array();
        if ( ! is_array( $updates ) ) {
            $updates = array();
        }

        // Store only plugins that actually have newer versions
        $available = array();
        foreach ( $updates as $slug => $info ) {
            if ( ! empty( $info['new_version'] ) ) {
                $available[ $slug ] = $info;
            }
        }

        if ( ! empty( $available ) ) {
            set_transient( self::TRANSIENT, $available, self::CACHE_TTL );
        } else {
            delete_transient( self::TRANSIENT );
        }

        return $available;
    }

    /**
     * Get cached available updates.
     *
     * @return array { slug => { new_version, changelog, zip_size }, ... }
     */
    public static function get_available(): array {
        $cached = get_transient( self::TRANSIENT );
        return is_array( $cached ) ? $cached : array();
    }

    /**
     * Get count of available updates.
     *
     * @return int
     */
    public static function count(): int {
        return count( self::get_available() );
    }

    /**
     * Admin notice — show update badge.
     */
    public static function admin_notice(): void {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $count = self::count();
        if ( $count === 0 ) {
            return;
        }

        $url = admin_url( 'index.php?page=bizcity-marketplace&tab=updates' );

        printf(
            '<div class="notice notice-info is-dismissible"><p>'
            . '<strong>BizCity Market:</strong> Có <a href="%s">%d plugin</a> cần cập nhật.'
            . '</p></div>',
            esc_url( $url ),
            $count
        );
    }

    /**
     * Handle manual force-check from admin.
     */
    public static function handle_force_check(): void {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( 'Bạn không có quyền thực hiện thao tác này.' );
        }

        check_admin_referer( 'bizcity_market_force_update_check' );

        // Clear cache and force re-check
        delete_transient( self::TRANSIENT );
        $updates = self::check();

        $count    = count( $updates );
        $redirect = add_query_arg(
            array(
                'page'            => 'bizcity-marketplace',
                'update_checked'  => '1',
                'updates_found'   => $count,
            ),
            admin_url( 'index.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Deactivation cleanup — remove cron schedule.
     */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        delete_transient( self::TRANSIENT );
    }
}
