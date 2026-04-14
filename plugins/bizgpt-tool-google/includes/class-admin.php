<?php
/**
 * Admin settings page — hub config + user account management.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Admin {

    const MENU_SLUG = 'bzgoogle-settings';

    public static function init() {
        if ( ! is_admin() ) return;

        // Menu registration moved to BizCity_Admin_Menu (centralized).
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // User profile page — Google connection section
        add_action( 'show_user_profile', [ __CLASS__, 'render_profile_section' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_profile_section' ] );

        // Network Admin page for hub config
        if ( is_multisite() ) {
            add_action( 'network_admin_menu', [ __CLASS__, 'register_network_menu' ] );
            add_action( 'network_admin_edit_bzgoogle_network', [ __CLASS__, 'handle_network_settings' ] );
        }
    }

    public static function register_menu() {
        add_menu_page(
            'Google Tools',
            'Google Tools',
            'read',
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ],
            'dashicons-google',
            72
        );
    }

    public static function register_settings() {
        // Hub settings (network-wide, only for admins)
        if ( BZGoogle_Google_OAuth::is_hub() && current_user_can( 'manage_options' ) ) {
            register_setting( 'bzgoogle_hub', 'bzgoogle_client_id_raw' );
            register_setting( 'bzgoogle_hub', 'bzgoogle_client_secret_raw' );

            // Save handler: encrypt client_secret before storing
            add_action( 'update_option_bzgoogle_client_secret_raw', [ __CLASS__, 'encrypt_client_secret' ], 10, 2 );
            add_action( 'add_option_bzgoogle_client_secret_raw', [ __CLASS__, 'encrypt_client_secret_add' ], 10, 2 );
        }
    }

    public static function encrypt_client_secret( $old, $new ) {
        if ( empty( $new ) ) return;
        update_site_option( 'bzgoogle_client_id', get_option( 'bzgoogle_client_id_raw', '' ) );
        update_site_option( 'bzgoogle_client_secret', BZGoogle_Token_Store::encrypt( $new ) );
    }

    public static function encrypt_client_secret_add( $option, $value ) {
        if ( empty( $value ) ) return;
        update_site_option( 'bzgoogle_client_id', get_option( 'bzgoogle_client_id_raw', '' ) );
        update_site_option( 'bzgoogle_client_secret', BZGoogle_Token_Store::encrypt( $value ) );
    }

    public static function enqueue_assets( $hook ) {
        // Load on plugin settings page
        if ( strpos( $hook, self::MENU_SLUG ) !== false ) {
            wp_enqueue_style(
                'bzgoogle-admin',
                BZGOOGLE_URL . 'assets/admin.css',
                [],
                BZGOOGLE_VERSION
            );
            wp_enqueue_script(
                'bzgoogle-admin',
                BZGOOGLE_URL . 'assets/admin.js',
                [ 'jquery' ],
                BZGOOGLE_VERSION,
                true
            );
            wp_localize_script( 'bzgoogle-admin', 'bzgoogle', [
                'rest_url' => rest_url( BZGoogle_REST_API::NAMESPACE ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            ] );
        }

        // Load CSS on profile page too
        if ( in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
            wp_enqueue_style(
                'bzgoogle-admin',
                BZGOOGLE_URL . 'assets/admin.css',
                [],
                BZGOOGLE_VERSION
            );
        }
    }

    public static function render_page() {
        $is_hub  = BZGoogle_Google_OAuth::is_hub();
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();

        // Handle success/error messages from OAuth redirect
        $connected    = ! empty( $_GET['bzgoogle_connected'] );
        $disconnected = ! empty( $_GET['bzgoogle_disconnected'] );
        $email        = sanitize_email( $_GET['email'] ?? '' );

        // Get user's connected accounts on THIS site
        $accounts = BZGoogle_Token_Store::get_accounts( $blog_id, $user_id );

        // Hub info for client sites
        $hub_url    = BZGoogle_Google_OAuth::get_hub_url();
        $hub_domain = BZGoogle_Google_OAuth::get_hub_domain();

        // Connect URL
        $connect_url = BZGoogle_Google_OAuth::get_connect_url( [
            'blog_id'    => $blog_id,
            'return_url' => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
        ] );

        include BZGOOGLE_DIR . 'views/admin-settings.php';
    }

    /* ══════════════════════════════════════════════════════════
     *  USER PROFILE — Google connection section
     * ══════════════════════════════════════════════════════════ */

    /**
     * Render Google connection section on user profile page.
     * Each user sees their own connection status and can connect/disconnect.
     */
    public static function render_profile_section( $user ) {
        $profile_user_id = $user->ID;
        $blog_id         = get_current_blog_id();
        $is_hub          = BZGoogle_Google_OAuth::is_hub();
        $hub_domain      = BZGoogle_Google_OAuth::get_hub_domain();
        $accounts        = BZGoogle_Token_Store::get_accounts( $blog_id, $profile_user_id );
        $has_token       = ! empty( $accounts );

        // Connect URL — opens in new tab, returns to profile page
        $connect_url = BZGoogle_Google_OAuth::get_connect_url( [
            'blog_id'    => $blog_id,
            'user_id'    => $profile_user_id,
            'return_url' => admin_url( 'profile.php?bzgoogle_connected=1' ),
        ] );

        // Success message
        $just_connected = ! empty( $_GET['bzgoogle_connected'] );
        ?>
        <h2>🔗 Google — Kết nối tài khoản</h2>

        <?php if ( $just_connected ) : ?>
            <div class="notice notice-success inline" style="margin: 8px 0 16px;">
                <p>✅ Đã kết nối Google thành công!</p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th><label>Trạng thái</label></th>
                <td>
                    <?php if ( $has_token ) : ?>
                        <?php foreach ( $accounts as $acc ) :
                            if ( $acc->status !== 'active' ) continue;
                        ?>
                            <div style="margin-bottom: 10px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; display: inline-flex; align-items: center; gap: 10px;">
                                <span style="color: #16a34a; font-size: 18px;">✅</span>
                                <div>
                                    <strong><?php echo esc_html( $acc->google_email ); ?></strong><br>
                                    <span style="font-size: 12px; color: #666;">
                                        Kết nối lúc: <?php echo esc_html( $acc->created_at ); ?>
                                        · Cập nhật: <?php echo esc_html( $acc->updated_at ); ?>
                                    </span>
                                </div>
                                <?php
                                $disconnect_url = wp_nonce_url(
                                    add_query_arg( [
                                        'account_id' => $acc->id,
                                        'return_url' => admin_url( 'profile.php?bzgoogle_disconnected=1' ),
                                    ], home_url( '/google-auth/disconnect' ) ),
                                    'bzgoogle_disconnect_' . $acc->id
                                );
                                ?>
                                <a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary button-small"
                                   onclick="return confirm('Bạn có chắc muốn ngắt kết nối Google?');"
                                   style="margin-left: 12px;">
                                    Ngắt kết nối
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <span style="color: #94a3b8;">⚠️ Chưa kết nối tài khoản Google nào.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label>Kết nối</label></th>
                <td>
                    <a href="<?php echo esc_url( $connect_url ); ?>"
                       target="_blank" rel="noopener"
                       class="button button-primary">
                        <span class="dashicons dashicons-google" style="margin-top: 4px; margin-right: 4px;"></span>
                        <?php echo $has_token ? 'Kết nối thêm / Cập nhật' : 'Kết nối Google'; ?>
                    </a>
                    <?php if ( ! $is_hub ) : ?>
                        <p class="description" style="margin-top: 6px;">
                            Bạn sẽ được chuyển sang <strong><?php echo esc_html( $hub_domain ); ?></strong> để xác thực với Google (mở tab mới), sau đó quay về.
                        </p>
                    <?php else : ?>
                        <p class="description" style="margin-top: 6px;">
                            Xác thực với Google để sử dụng Gmail, Calendar, Drive, Contacts qua chat AI.
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
     *  NETWORK ADMIN — Hub configuration
     * ══════════════════════════════════════════════════════════ */

    public static function register_network_menu() {
        add_submenu_page(
            'settings.php',
            'Google OAuth Hub',
            'Google OAuth Hub',
            'manage_network_options',
            'bzgoogle-network',
            [ __CLASS__, 'render_network_page' ]
        );
    }

    public static function render_network_page() {
        $hub_blog_id    = get_site_option( 'bzgoogle_hub_blog_id', 0 );
        $client_id      = get_site_option( 'bzgoogle_client_id', '' );
        $has_secret     = ! empty( get_site_option( 'bzgoogle_client_secret', '' ) );
        $saved          = ! empty( $_GET['updated'] );

        // Get all sites for dropdown
        $sites = get_sites( [ 'number' => 0 ] );
        ?>
        <div class="wrap">
            <h1>Google OAuth Hub — Cấu hình Network</h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=bzgoogle_network' ) ); ?>">
                <?php wp_nonce_field( 'bzgoogle_network_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="hub_blog_id">Hub Site (OAuth Server)</label></th>
                        <td>
                            <select name="hub_blog_id" id="hub_blog_id" style="min-width:300px">
                                <option value="0">— Auto-detect —</option>
                                <?php foreach ( $sites as $site ) :
                                    $bid   = (int) $site->blog_id;
                                    $label = $site->domain . $site->path . " (ID: {$bid})";
                                ?>
                                    <option value="<?php echo $bid; ?>" <?php selected( $hub_blog_id, $bid ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Site cài plugin <code>bizgpt-oauth-server-new</code> và có Google Client ID/Secret.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_id">Google Client ID</label></th>
                        <td>
                            <input type="text" name="client_id" id="client_id" class="regular-text" style="min-width:400px"
                                   value="<?php echo esc_attr( $client_id ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_secret">Google Client Secret</label></th>
                        <td>
                            <input type="password" name="client_secret" id="client_secret" class="regular-text" style="min-width:400px"
                                   placeholder="<?php echo $has_secret ? '••••••••••• (đã lưu)' : ''; ?>">
                            <p class="description">Để trống nếu không muốn thay đổi.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Lưu cấu hình' ); ?>
            </form>

            <hr>
            <h2>Trạng thái hiện tại</h2>
            <table class="widefat striped" style="max-width:600px">
                <tr><td><strong>Hub Blog ID</strong></td><td><?php echo $hub_blog_id ?: '<em>auto-detect</em>'; ?></td></tr>
                <tr><td><strong>Hub URL</strong></td><td><?php echo esc_html( BZGoogle_Google_OAuth::get_hub_url() ); ?></td></tr>
                <tr><td><strong>Callback URL</strong></td><td><code><?php echo esc_html( BZGoogle_Google_OAuth::get_callback_url() ); ?></code></td></tr>
                <tr><td><strong>Client ID</strong></td><td><?php echo $client_id ? esc_html( substr( $client_id, 0, 20 ) . '...' ) : '<em>chưa cấu hình</em>'; ?></td></tr>
                <tr><td><strong>Client Secret</strong></td><td><?php echo $has_secret ? '✅ Đã lưu (encrypted)' : '❌ Chưa cấu hình'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    public static function handle_network_settings() {
        check_admin_referer( 'bzgoogle_network_settings' );

        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Không có quyền.', 'Lỗi', [ 'response' => 403 ] );
        }

        $hub_blog_id = absint( $_POST['hub_blog_id'] ?? 0 );
        $client_id   = sanitize_text_field( $_POST['client_id'] ?? '' );
        $secret_raw  = sanitize_text_field( $_POST['client_secret'] ?? '' );

        // Save hub blog ID
        if ( $hub_blog_id ) {
            update_site_option( 'bzgoogle_hub_blog_id', $hub_blog_id );
        } else {
            delete_site_option( 'bzgoogle_hub_blog_id' );
        }

        // Save credentials
        if ( $client_id ) {
            update_site_option( 'bzgoogle_client_id', $client_id );
        }
        if ( $secret_raw ) {
            update_site_option( 'bzgoogle_client_secret', BZGoogle_Token_Store::encrypt( $secret_raw ) );
        }

        wp_safe_redirect( network_admin_url( 'settings.php?page=bzgoogle-network&updated=1' ) );
        exit;
    }
}
