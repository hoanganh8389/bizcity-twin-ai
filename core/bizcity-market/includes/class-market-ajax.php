<?php
/**
 * BizCity Market — AJAX Handler for Remote Marketplace (Client-side)
 *
 * All remote catalog operations are triggered by JS via wp_ajax_*
 * so the admin page load stays instant (no blocking REST calls).
 *
 * AJAX actions:
 *   bizcity_remote_catalog     — Proxy fetch catalog (paginated, filterable)
 *   bizcity_remote_detail      — Proxy fetch single plugin detail
 *   bizcity_remote_categories  — Proxy fetch categories
 *   bizcity_remote_install     — Download + install plugin from remote
 *   bizcity_remote_update      — Update an installed plugin
 *   bizcity_remote_check_updates — Batch update check
 *
 * Security:
 *   - All actions require nonce (bizcity_remote_market_nonce)
 *   - Install/update require install_plugins / update_plugins caps
 *   - Downloads use signed URLs (HMAC + expiry) — no API key in JS
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Market
 * @since      1.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Market_Ajax {

    const NONCE_ACTION = 'bizcity_remote_market_nonce';

    /**
     * Boot — register all AJAX hooks.
     */
    public static function boot(): void {
        $actions = [
            'bizcity_remote_catalog'       => 'handle_catalog',
            'bizcity_remote_detail'        => 'handle_detail',
            'bizcity_remote_categories'    => 'handle_categories',
            'bizcity_remote_install'       => 'handle_install',
            'bizcity_remote_update'        => 'handle_update',
            'bizcity_remote_check_updates' => 'handle_check_updates',
        ];

        foreach ( $actions as $action => $method ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $method ] );
        }
    }

    /* ================================================================
     *  Catalog browsing (proxy to server)
     * ================================================================ */

    /**
     * AJAX: Fetch catalog from remote server.
     * JS sends: search, category, plan, page, per_page
     */
    public static function handle_catalog(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! BizCity_Remote_Catalog::is_available() ) {
            wp_send_json( [ 'ok' => false, 'msg' => __( 'API key chưa được cấu hình.', 'bizcity-twin-ai' ) ] );
        }

        $params = [];
        foreach ( [ 'search', 'category', 'plan', 'page', 'per_page', 'orderby', 'order' ] as $k ) {
            $v = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
            if ( $v !== '' ) {
                $params[ $k ] = $v;
            }
        }

        $result = BizCity_Remote_Catalog::proxy_get( 'catalog', $params );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        // Merge local install status
        $data = $result['data'] ?? [];
        if ( ! empty( $data['plugins'] ) ) {
            $data['plugins'] = self::merge_local_status( $data['plugins'] );
        }

        wp_send_json( [ 'ok' => true, 'data' => $data, 'tier' => $result['tier'] ?? 'free' ] );
    }

    /**
     * AJAX: Fetch single plugin detail.
     * JS sends: slug
     */
    public static function handle_detail(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $slug = sanitize_key( wp_unslash( $_GET['slug'] ?? '' ) );
        if ( ! $slug ) {
            wp_send_json( [ 'ok' => false, 'msg' => 'Missing slug.' ] );
        }

        $result = BizCity_Remote_Catalog::proxy_get( 'catalog/' . $slug );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        $plugin = $result['data'] ?? null;
        if ( $plugin ) {
            $merged = self::merge_local_status( [ $plugin ] );
            $plugin = $merged[0];
        }

        wp_send_json( [ 'ok' => true, 'data' => $plugin ] );
    }

    /**
     * AJAX: Fetch categories.
     */
    public static function handle_categories(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $result = BizCity_Remote_Catalog::proxy_get( 'categories' );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        wp_send_json( [ 'ok' => true, 'data' => $result['data'] ?? [] ] );
    }

    /* ================================================================
     *  Install / Update (server-side operations via AJAX)
     * ================================================================ */

    /**
     * AJAX: Install a plugin from remote.
     * JS sends: slug, download_url
     */
    public static function handle_install(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        // On multisite, install_plugins is stripped from sub-site admins.
        // Bundled plugins land in bizcity-twin-ai/plugins/ (not WP plugins/),
        // so activate_plugins is the appropriate gating capability.
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => __( 'Bạn không có quyền cài đặt plugin.', 'bizcity-twin-ai' ) ] );
        }

        // API key required to install plugins from marketplace
        $gate = BizCity_Connection_Gate::instance();
        if ( ! $gate->get_api_key() ) {
            wp_send_json( [
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => __( 'Bạn cần đăng ký API Key với BizCity để cài đặt plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key, sau đó vào Cài đặt API để cấu hình.', 'bizcity-twin-ai' ),
            ] );
        }

        $slug         = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
        $download_url = esc_url_raw( wp_unslash( $_POST['download_url'] ?? '' ) );

        if ( ! $slug || ! $download_url ) {
            wp_send_json( [ 'ok' => false, 'msg' => 'Missing slug or download URL.' ] );
        }

        // Validate download URL domain matches gateway
        if ( ! self::validate_download_url( $download_url ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => __( 'Download URL không hợp lệ.', 'bizcity-twin-ai' ) ] );
        }

        // Download ZIP via signed URL
        $download = BizCity_Remote_Catalog::download( $download_url );
        if ( is_wp_error( $download ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $download->get_error_message() ] );
        }

        // Install
        $result = BizCity_Plugin_Installer::install_from_zip(
            $slug,
            $download['tmp_file'],
            $download['checksum']
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        wp_send_json( [
            'ok'      => true,
            'msg'     => __( 'Plugin đã được cài đặt thành công!', 'bizcity-twin-ai' ),
            'status'  => 'installed',
            'version' => $download['version'],
        ] );
    }

    /**
     * AJAX: Update an installed plugin.
     * JS sends: slug, download_url
     */
    public static function handle_update(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => __( 'Bạn không có quyền cập nhật plugin.', 'bizcity-twin-ai' ) ] );
        }

        // API key required to update plugins from marketplace
        $gate = BizCity_Connection_Gate::instance();
        if ( ! $gate->get_api_key() ) {
            wp_send_json( [
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => __( 'Bạn cần đăng ký API Key với BizCity để cập nhật plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key.', 'bizcity-twin-ai' ),
            ] );
        }

        $slug         = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
        $download_url = esc_url_raw( wp_unslash( $_POST['download_url'] ?? '' ) );

        if ( ! $slug || ! $download_url ) {
            wp_send_json( [ 'ok' => false, 'msg' => 'Missing slug or download URL.' ] );
        }

        if ( ! self::validate_download_url( $download_url ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => __( 'Download URL không hợp lệ.', 'bizcity-twin-ai' ) ] );
        }

        $download = BizCity_Remote_Catalog::download( $download_url );
        if ( is_wp_error( $download ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $download->get_error_message() ] );
        }

        $result = BizCity_Plugin_Installer::update_from_zip(
            $slug,
            $download['tmp_file'],
            $download['checksum']
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        wp_send_json( [
            'ok'      => true,
            'msg'     => __( 'Plugin đã được cập nhật thành công!', 'bizcity-twin-ai' ),
            'status'  => 'updated',
            'version' => $download['version'],
        ] );
    }

    /**
     * AJAX: Batch check for updates.
     */
    public static function handle_check_updates(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! BizCity_Remote_Catalog::is_available() ) {
            wp_send_json( [ 'ok' => true, 'data' => [] ] );
        }

        $installed = BizCity_Update_Checker::get_installed();
        if ( empty( $installed ) ) {
            wp_send_json( [ 'ok' => true, 'data' => [] ] );
        }

        $result = BizCity_Remote_Catalog::proxy_post( 'updates', [ 'plugins' => $installed ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => $result->get_error_message() ] );
        }

        wp_send_json( [ 'ok' => true, 'data' => $result['data'] ?? [] ] );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Validate that download URL belongs to the configured gateway domain.
     * Prevents SSRF by ensuring we only fetch from the trusted gateway.
     *
     * @param string $url
     * @return bool
     */
    private static function validate_download_url( string $url ): bool {
        $gateway_host = wp_parse_url(
            get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ),
            PHP_URL_HOST
        );
        $url_host = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $gateway_host || ! $url_host ) {
            return false;
        }

        return strcasecmp( $gateway_host, $url_host ) === 0;
    }

    /**
     * Merge local install/active status into remote plugin list.
     * Adds: local_installed (bool), local_active (bool), local_version (string)
     *
     * @param array $plugins
     * @return array
     */
    private static function merge_local_status( array $plugins ): array {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = BizCity_Update_Checker::get_installed();

        foreach ( $plugins as &$p ) {
            $slug = $p['slug'] ?? '';
            $plugin_file = 'bizcity-twin-ai/plugins/' . $slug . '/' . $slug . '.php';

            $p['local_installed'] = isset( $installed[ $slug ] );
            $p['local_active']    = is_plugin_active( $plugin_file );
            $p['local_version']   = $installed[ $slug ] ?? '';
            $p['has_update']      = $p['local_installed']
                && ! empty( $p['version'] )
                && version_compare( $p['version'], $p['local_version'], '>' );
        }

        return $plugins;
    }
}
