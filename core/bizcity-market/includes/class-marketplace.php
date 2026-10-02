<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

class BizCity_Market_Marketplace {

    public static function boot() {
        // Menu registration moved to BizCity_Admin_Menu (centralized).
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 25);
        add_filter('admin_body_class', [__CLASS__, 'iframe_body_class']);

        // Handle sync BEFORE output (wp_redirect needs headers not sent yet)
        add_action('admin_init', [__CLASS__, 'handle_sync_early']);

        // ajax load plugin detail
        add_action('wp_ajax_bizcity_market_plugin_detail', [__CLASS__, 'ajax_plugin_detail']);

        // ajax activate plugin (all plugins freely activatable — no credit purchase)
        add_action('wp_ajax_bizcity_market_activate_plugin', [__CLASS__, 'ajax_activate_plugin']);

        // ajax deactivate plugin from marketplace
        add_action('wp_ajax_bizcity_market_deactivate_plugin', [__CLASS__, 'ajax_deactivate_plugin']);

        // ajax uninstall plugin from marketplace
        add_action('wp_ajax_bizcity_market_uninstall_plugin', [__CLASS__, 'ajax_uninstall_plugin']);
    }

    /**
     * Handle sync action early (admin_init) so wp_redirect works before output.
     */
    public static function handle_sync_early(): void {
        if ( ! isset( $_GET['page'], $_GET['action'] ) ) return;
        if ( $_GET['page'] !== 'bizcity-marketplace' || $_GET['action'] !== 'sync' ) return;
        if ( ! current_user_can( 'activate_plugins' ) ) return;

        check_admin_referer( 'bc_market_sync' );

        $sync_ver = '5'; // [2026-08-26 Johnny Chu] PHASE-1.29-MARKET-CATALOG — invalidate pre-render reconciliation cache.
        delete_site_transient( 'bizcity_agent_plugins_synced_v' . $sync_ver );
        BizCity_Market_Catalog::sync_agent_plugins( true );

        $base_args = [ 'page' => 'bizcity-marketplace', 'synced' => '1' ];
        if ( ! empty( $_GET['bizcity_iframe'] ) ) {
            $base_args['bizcity_iframe'] = '1';
        }
        $redirect = add_query_arg( $base_args, admin_url( 'index.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function menu() {

        // ✅ CHANGE: cho cả site admin xem marketplace trong wp-admin của họ
        if (is_network_admin()) {
            $parent_slug = 'index.php';
            $cap = 'manage_network';
        } else {
            // site admin
            $parent_slug = 'index.php';
            $cap = 'read'; // hoặc 'manage_options' nếu muốn chặt hơn
        }

        add_submenu_page(
            $parent_slug,
            'BizCity Apps - Chợ ứng dụng',
            'Chợ ứng dụng',
            $cap,
            'bizcity-marketplace',
            [__CLASS__, 'render'],
            2
        );
    }

    public static function assets($hook) {
        if (strpos($hook, 'bizcity-marketplace') === false) return;
        $v = BIZCITY_MARKET_VER . '.' . date('ymdHi');
        wp_enqueue_style('bizcity-market-marketplace', BIZCITY_MARKET_URL . '/assets/marketplace.css', [], $v);
        wp_enqueue_script('bizcity-market-marketplace', BIZCITY_MARKET_URL . '/assets/marketplace.js', ['jquery'], $v, true);

        wp_localize_script('bizcity-market-marketplace', 'BCMarket', [
            'ajax'        => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('bizcity_market_nonce'),
            'hasApiKey'   => class_exists('BizCity_Connection_Gate') ? (bool) BizCity_Connection_Gate::instance()->get_api_key() : false,
            'settingsUrl' => admin_url( 'admin.php?page=bizcity-llm-router' ),
            'registerUrl' => 'https://bizcity.vn/my-account/api-keys/',
        ]);

        // ── Lazy update check: fetch updates via AJAX when marketplace opens ──
        wp_add_inline_script( 'bizcity-market-marketplace', self::lazy_update_js() );

        // Remote marketplace assets
        $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
        if ( $tab === 'remote' ) {
            wp_enqueue_style( 'bizcity-remote-market', BIZCITY_MARKET_URL . '/assets/remote-market.css', [], $v );
            wp_enqueue_script( 'bizcity-remote-market', BIZCITY_MARKET_URL . '/assets/remote-market.js', [], $v, true );

            wp_localize_script( 'bizcity-remote-market', 'BCRemoteMarket', [
                'ajax'              => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'bizcity_remote_market_nonce' ),
                'localNonce'        => wp_create_nonce( 'bizcity_market_nonce' ),
                'title'             => __( 'Chợ ứng dụng BizCity', 'bizcity-twin-ai' ),
                'searchPlaceholder' => __( 'Tìm plugin...', 'bizcity-twin-ai' ),
                'hasApiKey'         => class_exists('BizCity_Connection_Gate') ? (bool) BizCity_Connection_Gate::instance()->get_api_key() : false,
                'settingsUrl'       => admin_url( 'admin.php?page=bizcity-llm-router' ),
                'registerUrl'       => 'https://bizcity.vn/my-account/api-keys/',
            ] );
        }
    }

    /** Keep embedded Marketplace content inside the TwinShell frame. */
    public static function iframe_body_class($classes) {
        if (isset($_GET['page'], $_GET['bizcity_iframe'])
            && 'bizcity-marketplace' === sanitize_key((string) $_GET['page'])
            && '1' === sanitize_key((string) $_GET['bizcity_iframe'])) {
            $classes .= ' bizcity-market-iframe';
        }
        return $classes;
    }

    /**
     * Inline JS: lazy-load update check when Marketplace page opens.
     * Fires AJAX → bizcity_market_lazy_updates, updates badge in nav tab.
     */
    private static function lazy_update_js(): string {
        return <<<'JS'
(function(){
    if (!window.BCMarket) return;
    jQuery.post(BCMarket.ajax, {
        action: 'bizcity_market_lazy_updates',
        nonce:  BCMarket.nonce
    }, function(res) {
        if (!res || !res.success) return;
        var c = parseInt(res.data.count, 10) || 0;
        var badge = document.getElementById('bc-update-badge');
        if (!badge) return;
        if (c > 0) {
            badge.className = 'update-plugins count-' + c;
            badge.innerHTML = '<span class="plugin-count">' + c + '</span>';
            badge.style.display = '';
        }
    });
})();
JS;
    }

    // ajax_buy_credit() — REMOVED in v0.9
    // Credit không dùng cho việc mua plugin.
    // Tất cả plugin đều kích hoạt tự do.
    // Credit dùng cho per-use cost (mỗi job).

    /**
     * ajax_activate_plugin()
     *
     * Activate a purchased plugin directly from the marketplace.
     * Requirements:
     * - User must have 'activate_plugins' cap
     * - Plugin must be entitled (purchased) for current blog
     * - Plugin file must exist on disk
     */
    public static function ajax_activate_plugin() {
        check_ajax_referer('bizcity_market_nonce', 'nonce');

        if (!current_user_can('activate_plugins')) {
            wp_send_json(['ok'=>false, 'msg'=> __( 'Bạn không có quyền kích hoạt plugin.', 'bizcity-twin-ai' )]);
        }

        // API key required to activate agent plugins
        if ( ! class_exists('BizCity_Connection_Gate') || ! BizCity_Connection_Gate::instance()->get_api_key() ) {
            wp_send_json([
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => __( 'Bạn cần đăng ký API Key với BizCity để kích hoạt plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key, sau đó vào Cài đặt API để cấu hình.', 'bizcity-twin-ai' ),
            ]);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Thiếu plugin_slug.']);

        // All plugins are freely activatable — no credit/entitlement check needed
        $db = BizCity_Market_DB::globaldb();

        // Bundle filesystem is authoritative for the local application list.
        $bundle = self::get_bundle_plugin($slug);
        $plugin_file = $bundle ? $bundle->plugin_file : '';
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $catalog_file = $db->get_var($db->prepare(
                "SELECT plugin_file FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug
            ));
            if ( ! $plugin_file ) {
                $plugin_file = $catalog_file;
            }
        }

        // Fallback: scan local plugins for matching directory slug
        if (!$plugin_file || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $pf => $pd) {
                $dir = dirname($pf);
                $pslug = ($dir === '.') ? sanitize_key(basename($pf, '.php')) : sanitize_key($dir);
                if ($pslug === $slug) { $plugin_file = $pf; break; }
            }
        }

        if (!$plugin_file || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            $bundle = self::get_bundle_plugin($slug);
            $plugin_file = $bundle ? $bundle->plugin_file : '';
        }

        if (!$plugin_file) {
            wp_send_json(['ok'=>false, 'msg'=> sprintf( __( 'Không tìm thấy plugin "%s" trong catalog hoặc trên server.', 'bizcity-twin-ai' ), $slug )]);
        }

        // Check if file exists on disk
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (!file_exists($full_path)) {
            wp_send_json(['ok'=>false, 'msg'=> __( 'File plugin không tồn tại trên server. Liên hệ admin.', 'bizcity-twin-ai' )]);
        }

        // [2026-08-27 Johnny Chu] PHASE-1.29-MARKET-LIFECYCLE — load the
        // WordPress plugin API before checking an inactive bundle plugin.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            $plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
            if ( is_file( $plugin_api ) && is_readable( $plugin_api ) ) {
                require_once $plugin_api;
            }
        }

        // [2026-08-29 Johnny Chu] HOTFIX-MARKET-BUNDLE-STATE — bundled children are loaded by Twin AI guards, not active_plugins rows.
        if ( self::is_market_plugin_active( $slug, $plugin_file ) ) {
            wp_send_json(['ok'=>true, 'msg'=> __( 'Plugin đã được kích hoạt.', 'bizcity-twin-ai' ), 'status'=>'active']);
        }

        // Activate the plugin
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            wp_send_json(['ok'=>false, 'msg'=> sprintf( __( 'Kích hoạt thất bại: %s', 'bizcity-twin-ai' ), $result->get_error_message() )]);
        }

        // ✅ Schedule deferred rewrite flush on NEXT request via central registry.
        // During AJAX, init has already fired — the new plugin's add_rewrite_rule()
        // hooks don't run in this request. Flushing now would miss them.
        // [2026-06-26 Johnny Chu] R-PERF — use queue_flush() instead of own pending option.
        if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
            BizCity_Rewrite_Flush_Registry::queue_flush( 'marketplace' );
        }

        // ✅ Notify system — Tool Registry + other listeners
        do_action( 'bizcity_market_plugin_activated', $slug, $plugin_file, (int) get_current_blog_id() );

        // ✅ Update installs count in global_plugins_meta
        if (method_exists('BizCity_Market_DB', 't_plugins_meta')) {
            $tM = BizCity_Market_DB::t_plugins_meta();
            $db->query($db->prepare(
                "UPDATE {$tM} SET total_installs = total_installs + 1, active_count = active_count + 1, updated_at = %s WHERE plugin_slug = %s",
                current_time('mysql'), $slug
            ));
        }

        wp_send_json([
            'ok'     => true,
            'msg'    => __( 'Kích hoạt thành công! Plugin đã sẵn sàng sử dụng.', 'bizcity-twin-ai' ),
            'status' => 'active',
        ]);
    }

    /**
     * ajax_deactivate_plugin()
     *
     * Deactivate a plugin directly from the marketplace.
     */
    public static function ajax_deactivate_plugin() {
        check_ajax_referer('bizcity_market_nonce', 'nonce');

        if (!current_user_can('activate_plugins')) {
            wp_send_json(['ok'=>false, 'msg'=> __( 'Bạn không có quyền ngừng kích hoạt plugin.', 'bizcity-twin-ai' )]);
        }

        // API key required to manage agent plugins
        if ( ! class_exists('BizCity_Connection_Gate') || ! BizCity_Connection_Gate::instance()->get_api_key() ) {
            wp_send_json([
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => __( 'Bạn cần đăng ký API Key với BizCity để quản lý plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key.', 'bizcity-twin-ai' ),
            ]);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Thiếu plugin_slug.']);

        // Resolve bundle file first, then catalog fallback for remote apps.
        $db = BizCity_Market_DB::globaldb();
        $bundle = self::get_bundle_plugin($slug);
        $plugin_file = $bundle ? $bundle->plugin_file : '';
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $catalog_file = $db->get_var($db->prepare(
                "SELECT plugin_file FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug
            ));
            if ( ! $plugin_file ) {
                $plugin_file = $catalog_file;
            }
        }

        // Fallback: scan local plugins for matching directory slug
        if (!$plugin_file) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $pf => $pd) {
                $dir = dirname($pf);
                $pslug = ($dir === '.') ? sanitize_key(basename($pf, '.php')) : sanitize_key($dir);
                if ($pslug === $slug) { $plugin_file = $pf; break; }
            }
        }

        if (!$plugin_file || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            $bundle = self::get_bundle_plugin($slug);
            $plugin_file = $bundle ? $bundle->plugin_file : '';
        }

        if (!$plugin_file) {
            wp_send_json(['ok'=>false, 'msg'=> sprintf( __( 'Không tìm thấy plugin "%s".', 'bizcity-twin-ai' ), $slug )]);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! is_plugin_active( $plugin_file ) ) {
            // [2026-08-29 Johnny Chu] HOTFIX-MARKET-BUNDLE-STATE — managed bundle children cannot be deactivated as independent WordPress plugins.
            if ( self::is_market_plugin_active( $slug, $plugin_file ) ) {
                wp_send_json(['ok'=>true, 'msg'=> __( 'Plugin đang được Twin AI quản lý và đã hoạt động.', 'bizcity-twin-ai' ), 'status'=>'active']);
            }
            wp_send_json(['ok'=>true, 'msg'=> __( 'Plugin đã được ngừng kích hoạt.', 'bizcity-twin-ai' ), 'status'=>'inactive']);
        }

        deactivate_plugins($plugin_file);

        // ✅ Schedule deferred rewrite flush on NEXT request via central registry.
        // [2026-06-26 Johnny Chu] R-PERF — use queue_flush() instead of own pending option.
        if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
            BizCity_Rewrite_Flush_Registry::queue_flush( 'marketplace' );
        }

        // ✅ Notify system — Tool Registry + other listeners
        do_action( 'bizcity_market_plugin_deactivated', $slug, $plugin_file, (int) get_current_blog_id() );

        // Update active_count in global_plugins_meta
        if (method_exists('BizCity_Market_DB', 't_plugins_meta')) {
            $tM = BizCity_Market_DB::t_plugins_meta();
            $db->query($db->prepare(
                "UPDATE {$tM} SET active_count = GREATEST(active_count - 1, 0), updated_at = %s WHERE plugin_slug = %s",
                current_time('mysql'), $slug
            ));
        }

        wp_send_json([
            'ok'     => true,
            'msg'    => __( 'Đã ngừng kích hoạt plugin thành công.', 'bizcity-twin-ai' ),
            'status' => 'inactive',
        ]);
    }

    /**
     * Uninstall a plugin after an explicit user confirmation.
     * Deactivation alone never removes plugin data.
     */
    public static function ajax_uninstall_plugin() {
        check_ajax_referer('bizcity_market_nonce', 'nonce');

        if (!current_user_can('delete_plugins')) {
            wp_send_json(['ok'=>false, 'msg'=>'Bạn không có quyền gỡ cài đặt plugin.']);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Thiếu plugin_slug.']);

        $db = BizCity_Market_DB::globaldb();
        $bundle = self::get_bundle_plugin($slug);
        $plugin_file = $bundle ? $bundle->plugin_file : '';
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $catalog_file = $db->get_var($db->prepare(
                "SELECT plugin_file FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug
            ));
            if ( ! $plugin_file ) {
                $plugin_file = $catalog_file;
            }
        }
        if (!$plugin_file || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            $bundle = self::get_bundle_plugin($slug);
            $plugin_file = $bundle ? $bundle->plugin_file : '';
        }
        if (!$plugin_file) wp_send_json(['ok'=>false, 'msg'=>'Không tìm thấy plugin trong catalog hoặc bundle.']);

        if (!function_exists('is_plugin_active')) {
            $plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($plugin_api) && is_readable($plugin_api)) require_once $plugin_api;
        }
        $full_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
        if (!is_file($full_path) || !is_readable($full_path)) {
            wp_send_json(['ok'=>false, 'msg'=>'Không tìm thấy file plugin đã cài đặt.']);
        }

        if ( self::is_market_plugin_active( $slug, $plugin_file ) ) {
            // [2026-08-29 Johnny Chu] HOTFIX-MARKET-BUNDLE-STATE — managed children are part of Twin AI and cannot be uninstalled independently.
            if ( ! is_plugin_active( $plugin_file ) ) {
                wp_send_json(['ok'=>false, 'msg'=> __( 'Plugin này đang được Twin AI quản lý, không thể gỡ riêng.', 'bizcity-twin-ai' )]);
            }
            deactivate_plugins( $plugin_file, true );
        }

        // [2026-08-26 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — use the
        // canonical nested-plugin installer so the complete directory and its
        // guarded uninstall artifact are removed, not only the entrypoint.
        $nested_dir = defined('BIZCITY_TWIN_AI_DIR')
            ? BIZCITY_TWIN_AI_DIR . 'plugins/' . $slug . '/'
            : '';
        if (is_dir($nested_dir) && class_exists('BizCity_Plugin_Installer')) {
            $result = BizCity_Plugin_Installer::uninstall($slug);
            if (is_wp_error($result)) {
                wp_send_json(['ok'=>false, 'msg'=>'Không thể gỡ cài đặt plugin: ' . $result->get_error_message()]);
            }
            do_action('bizcity_market_plugin_uninstalled', $slug, $plugin_file, (int) get_current_blog_id());
            wp_send_json([
                'ok'     => true,
                'msg'    => 'Đã gỡ cài đặt plugin và dữ liệu riêng thành công.',
                'status' => 'uninstalled',
            ]);
        }

        // [2026-08-26 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — run the
        // plugin-owned uninstall contract before deleting its files.
        $uninstall_file = dirname($full_path) . '/uninstall.php';
        if (is_file($uninstall_file) && is_readable($uninstall_file)) {
            if (!defined('WP_UNINSTALL_PLUGIN')) define('WP_UNINSTALL_PLUGIN', true);
            if (class_exists('BizCity_Safe_Loader', false)) {
                if (!BizCity_Safe_Loader::require_file($uninstall_file, 'market.uninstall.' . $slug)) {
                    wp_send_json(['ok'=>false, 'msg'=>'Không thể dọn dữ liệu plugin.']);
                }
            } else {
                wp_send_json(['ok'=>false, 'msg'=>'Bộ dọn dẹp plugin chưa sẵn sàng.']);
            }
        }

        WP_Filesystem();
        global $wp_filesystem;
        $removed = $wp_filesystem && $wp_filesystem->delete(dirname($full_path), true);
        if (!$removed) wp_send_json(['ok'=>false, 'msg'=>'Không thể xóa thư mục plugin.']);

        do_action('bizcity_market_plugin_uninstalled', $slug, $plugin_file, (int) get_current_blog_id());
        wp_send_json([
            'ok'     => true,
            'msg'    => 'Đã gỡ cài đặt plugin và dữ liệu riêng thành công.',
            'status' => 'uninstalled',
        ]);
    }

    // ✅ NEW: load detail html
    public static function ajax_plugin_detail() {
        check_ajax_referer('bizcity_market_nonce', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json(['ok'=>false, 'msg'=>'No permission']);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Missing slug']);

        $db = BizCity_Market_DB::globaldb();
        $p = self::get_bundle_plugin($slug);
        if (!$p && $db) {
            $tP = BizCity_Market_DB::t_plugins();
            $p = $db->get_row($db->prepare("SELECT * FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug));
        }
        if (!$p) wp_send_json(['ok'=>false, 'msg'=>'Plugin not found']);

        // All plugins are freely activatable — no entitlement purchase needed
        $owned = true;

        // Check if plugin is activated on this site
        $is_activated = false;
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! empty( $p->plugin_file ) ) {
            // [2026-08-29 Johnny Chu] HOTFIX-MARKET-BUNDLE-STATE — modal state must match the bundled runtime state used by the local grid.
            $is_activated = self::is_market_plugin_active( $slug, $p->plugin_file );
        }
        $is_installed = !empty($p->plugin_file)
            && is_file(WP_PLUGIN_DIR . '/' . ltrim($p->plugin_file, '/'));

        // gallery parse (anh lưu kiểu JSON array url hoặc newline-separated đều được)
        $gallery = [];
        if (!empty($p->gallery)) {
            $raw = trim((string)$p->gallery);
            $json = json_decode($raw, true);
            if (is_array($json)) {
                foreach ($json as $u) {
                    $u = esc_url_raw($u);
                    if ($u) $gallery[] = $u;
                }
            } else {
                $lines = preg_split('/\r\n|\r|\n/', $raw);
                foreach ((array)$lines as $u) {
                    $u = esc_url_raw(trim($u));
                    if ($u) $gallery[] = $u;
                }
            }
        }

        // description (wp editor content)
        $desc_html = '';
        if (!empty($p->description)) {
            // apply wpautop + shortcodes nhẹ nhàng
            $desc_html = wp_kses_post(wpautop(do_shortcode((string)$p->description)));
        }

        $title = $p->title ? $p->title : $slug;
        $thumb = !empty($p->image_url) ? esc_url($p->image_url) : self::default_plugin_cover();
        $author = $p->author_name ? $p->author_name : 'BizCity';
        $views = (int)($p->views ?? 0);
        $credit = (int)($p->credit_price ?? 0);
        $vnd = (int)($p->vnd_price ?? 0);
        $demo = !empty($p->demo_url) ? esc_url($p->demo_url) : '';

        ob_start();
        ?>
        <div class="bc-modal-head">
            <div class="bc-modal-thumb" style="background-image:url('<?php echo esc_url($thumb); ?>')"></div>
            <div class="bc-modal-meta">
                <div class="bc-modal-title"><?php echo esc_html($title); ?></div>
                <div class="bc-modal-sub">
                    <span><?php echo esc_html($author); ?></span>
                    <span>•</span>
                    <span><?php echo number_format_i18n($views); ?> views</span>
                </div>

                <div class="bc-modal-price">
                    <div class="bc-credit-info"><?php echo $credit; ?> credit / lần sử dụng</div>
                </div>

                <div class="bc-modal-actions">
                    <?php if ($demo): ?>
                        <a class="button" href="<?php echo esc_url($demo); ?>" target="_blank">Preview</a>
                    <?php else: ?>
                        <button class="button" disabled>Preview</button>
                    <?php endif; ?>

                    <?php if ($is_activated): ?>
                        <button class="button bc-deactivate" data-slug="<?php echo esc_attr($slug); ?>">
                            ⏸ Ngừng kích hoạt
                        </button>
                        <span class="bc-badge bc-badge-active">✓ Đang hoạt động</span>
                    <?php else: ?>
                        <button class="button button-primary bc-activate" data-slug="<?php echo esc_attr($slug); ?>">
                            ⚡ Cài đặt & Kích hoạt
                        </button>
                    <?php endif; ?>
                    <?php if ($is_installed): ?>
                        <button class="button bc-uninstall" data-slug="<?php echo esc_attr($slug); ?>">
                            🗑 Gỡ cài đặt
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($p->quickview)): ?>
                    <div class="bc-modal-quick"><?php echo esc_html($p->quickview); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($gallery): ?>
            <div class="bc-modal-gallery">
                <?php foreach ($gallery as $u): ?>
                    <a class="bc-gimg" href="<?php echo esc_url($u); ?>" target="_blank" style="background-image:url('<?php echo esc_url($u); ?>')"></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bc-modal-tabs">
            <button class="bc-tab is-active" data-tab="desc">Mô tả</button>
            <button class="bc-tab" data-tab="info">Thông tin</button>
        </div>

        <div class="bc-modal-tabpanes">
            <div class="bc-pane is-active" data-pane="desc">
                <?php if ($desc_html): ?>
                    <div class="bc-modal-desc"><?php echo $desc_html; ?></div>
                <?php else: ?>
                    <div class="bc-empty">Chưa có mô tả chi tiết.</div>
                <?php endif; ?>
            </div>

            <div class="bc-pane" data-pane="info">
                <div class="bc-info-grid">
                    <div class="bc-info-item"><b>Slug</b><div><?php echo esc_html($slug); ?></div></div>
                    <div class="bc-info-item"><b>Tác giả</b><div><?php echo esc_html($author); ?></div></div>
                    <div class="bc-info-item"><b>Views</b><div><?php echo number_format_i18n($views); ?></div></div>
                    <div class="bc-info-item"><b>Giá credit</b><div><?php echo (int)$credit; ?> / lần sử dụng</div></div>
                    <div class="bc-info-item"><b>Giá VNĐ</b><div><?php echo number_format_i18n($vnd); ?> đ / lần sử dụng</div></div>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json([
            'ok' => true,
            'slug' => $slug,
            'title' => $title,
            'owned' => $owned ? 1 : 0,
            'activated' => $is_activated ? 1 : 0,
            'html' => $html,
        ]);
    }

    public static function render() {
        if (!current_user_can('read')) wp_die('No permission');

        $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );

        // ── Base URL for links ──
        $parent = is_network_admin() ? 'index.php' : 'index.php';
        $base_args = [ 'page' => 'bizcity-marketplace' ];
        if ( ! empty( $_GET['bizcity_iframe'] ) ) {
            $base_args['bizcity_iframe'] = '1';
        }
        $base_url = add_query_arg( $base_args, admin_url( $parent ) );

        if ( isset($_GET['synced']) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã đồng bộ plugin agent.</p></div>';
        }

        // ── Tab Navigation ──
        $local_url  = add_query_arg( 'tab', '', $base_url );
        $remote_url = add_query_arg( 'tab', 'remote', $base_url );
        ?>
        <div class="wrap bc-market-wrap">
            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <a class="nav-tab <?php echo $tab !== 'remote' ? 'nav-tab-active' : ''; ?>"
                   href="<?php echo esc_url( $local_url ); ?>">Ứng dụng</a>
                <a class="nav-tab <?php echo $tab === 'remote' ? 'nav-tab-active' : ''; ?>"
                   href="<?php echo esc_url( $remote_url ); ?>" id="bc-remote-tab">
                    Chợ ứng dụng BizCity
                    <span id="bc-update-badge" style="display:none"></span>
                </a>
            </nav>
        <?php

        if ( $tab === 'remote' ) {
            self::render_remote();
        } else {
            self::render_local( $base_url );
        }

        echo '</div>'; // .wrap
    }

    /**
     * Render remote marketplace — shows API key onboarding if not configured,
     * otherwise renders the JS-driven container (no blocking remote call).
     */
    private static function render_remote(): void {
        if ( ! BizCity_Remote_Catalog::is_available() ) {
            self::render_api_setup_prompt();
            return;
        }
        echo '<div id="bc-remote-market"></div>';
    }

    /**
     * Render onboarding prompt when no API key is configured.
     */
    private static function render_api_setup_prompt(): void {
        $setup_url = 'https://bizcity.vn/my-account/';
        $settings_url = admin_url( 'admin.php?page=bizcity-llm-router' );
        ?>
        <div class="bcr-setup-wrap">
            <div class="bcr-setup-card">
                <div class="bcr-setup-icon">🏪</div>
                <h2>Chào mừng đến Chợ ứng dụng BizCity</h2>
                <p class="bcr-setup-lead">Để truy cập chợ và tải ứng dụng, bạn cần cài đặt <strong>BizCity API Key</strong>.</p>

                <div class="bcr-setup-features">
                    <div class="bcr-setup-feature">
                        <span class="bcr-feat-icon">🤖</span>
                        <div>
                            <strong>300+ mô hình LLM</strong>
                            <p>Tự động chọn mô hình phù hợp theo từng công việc, giúp tiết kiệm chi phí API đáng kể so với dùng một mô hình cố định.</p>
                        </div>
                    </div>
                    <div class="bcr-setup-feature">
                        <span class="bcr-feat-icon">📦</span>
                        <div>
                            <strong>Plugin tự động hóa liên tục cập nhật</strong>
                            <p>Kho plugin tự động hóa ngày càng mở rộng — luôn có công cụ mới nhất để tối ưu quy trình làm việc của bạn.</p>
                        </div>
                    </div>
                    <div class="bcr-setup-feature">
                        <span class="bcr-feat-icon">🔄</span>
                        <div>
                            <strong>Fallback LLM tự động</strong>
                            <p>Khi một mô hình tạm thời lỗi, hệ thống tự chuyển sang mô hình thay thế — không gián đoạn công việc.</p>
                        </div>
                    </div>
                </div>

                <div class="bcr-setup-steps">
                    <p><strong>Cách cài đặt:</strong></p>
                    <ol>
                        <li>Truy cập <a href="<?php echo esc_url( $setup_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $setup_url ); ?></a> để tạo API Key.</li>
                        <li>Vào <a href="<?php echo esc_url( $settings_url ); ?>">Cài đặt BizCity LLM Router</a> và dán API Key vào ô <em>API Gateway Key</em>.</li>
                        <li>Quay lại tab này để duyệt và cài đặt ứng dụng.</li>
                    </ol>
                </div>

                <a href="<?php echo esc_url( $setup_url ); ?>" target="_blank" rel="noopener" class="button button-primary bcr-setup-cta">
                    Tạo API Key tại bizcity.vn →
                </a>
            </div>
        </div>
        <style>
        .bcr-setup-wrap{display:flex;align-items:flex-start;justify-content:center;padding:32px 16px;}
        .bcr-setup-card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:32px 36px;max-width:680px;width:100%;box-shadow:0 4px 16px rgba(0,0,0,.06);}
        .bcr-setup-icon{font-size:48px;margin-bottom:12px;}
        .bcr-setup-card h2{margin:0 0 8px;font-size:1.5em;}
        .bcr-setup-lead{color:#50575e;margin-bottom:24px;font-size:15px;}
        .bcr-setup-features{display:flex;flex-direction:column;gap:16px;margin-bottom:24px;}
        .bcr-setup-feature{display:flex;gap:14px;align-items:flex-start;background:#f9f9f9;border-radius:8px;padding:14px;}
        .bcr-feat-icon{font-size:28px;flex-shrink:0;line-height:1;}
        .bcr-setup-feature strong{display:block;margin-bottom:4px;}
        .bcr-setup-feature p{margin:0;color:#50575e;font-size:13px;line-height:1.5;}
        .bcr-setup-steps{background:#f0f6fc;border-left:3px solid #2271b1;border-radius:4px;padding:14px 18px;margin-bottom:24px;}
        .bcr-setup-steps p{margin:0 0 8px;}
        .bcr-setup-steps ol{margin:0;padding-left:20px;}
        .bcr-setup-steps li{margin-bottom:6px;font-size:13px;}
        .bcr-setup-cta{font-size:15px;padding:10px 20px !important;height:auto !important;}
        </style>
        <?php
    }

    /**
     * List installed bundle plugins directly from the filesystem.
     * The local Marketplace tab must not depend on catalog rows or activation state.
     */
    private static function get_bundle_plugins( string $search = '', string $category = '' ): array {
        // [2026-08-26 Johnny Chu] PHASE-1.29-MARKET-BUNDLE-LIST — direct
        // bundle discovery keeps inactive optional plugins visible and avoids
        // stale/duplicate rows from the global catalog table.
        $bundle_root = self::get_bundle_root();
        if ( '' === $bundle_root || ! is_dir( $bundle_root . 'plugins' ) ) {
            return array();
        }
        $plugins = array();
        $dirs    = glob( $bundle_root . 'plugins/*', GLOB_ONLYDIR );
        foreach ( (array) $dirs as $dir ) {
            $slug = sanitize_key( basename( $dir ) );
            if ( '' === $slug || '_archived' === $slug || false !== strpos( $slug, '_archived' ) ) {
                continue;
            }

            // [2026-08-27 Johnny Chu] PHASE-1.29-MARKET-BUNDLE-LIST — every
            // non-archived bundle directory is listable, even legacy plugins
            // whose entrypoint has no Role or Plugin Name header.
            $preferred = trailingslashit( $dir ) . $slug . '.php';
            $files     = is_file( $preferred ) ? array( $preferred ) : glob( trailingslashit( $dir ) . '*.php' );
            $file      = '';
            $headers   = array();
            $fallback  = '';
            foreach ( (array) $files as $candidate ) {
                if ( ! is_file( $candidate ) || ! is_readable( $candidate ) ) {
                    continue;
                }
                $candidate_name = basename( $candidate );
                if ( in_array( $candidate_name, array( 'index.php', 'bootstrap.php', 'uninstall.php' ), true ) ) {
                    continue;
                }
                $candidate_headers = get_file_data( $candidate, array(
                    'Name'          => 'Plugin Name',
                    'Role'          => 'Role',
                    'Icon Path'     => 'Icon Path',
                    'Credit'        => 'Credit',
                    'Price'         => 'Price',
                    'Cover URI'     => 'Cover URI',
                    'Category'      => 'Category',
                    'Plan'          => 'Plan',
                    'Featured'      => 'Featured',
                ) );
                $role = strtolower( trim( (string) ( $candidate_headers['Role'] ?? '' ) ) );
                if ( '' !== trim( (string) ( $candidate_headers['Name'] ?? '' ) ) ) {
                    $file    = $candidate;
                    $headers = $candidate_headers;
                    break;
                }
                if ( '' === $fallback ) {
                    $fallback = $candidate;
                }
            }
            if ( '' === $file && '' !== $fallback ) {
                $file    = $fallback;
                $headers = get_file_data( $file, array(
                    'Name'          => 'Plugin Name',
                    'Icon Path'     => 'Icon Path',
                    'Credit'        => 'Credit',
                    'Price'         => 'Price',
                    'Cover URI'     => 'Cover URI',
                    'Category'      => 'Category',
                    'Plan'          => 'Plan',
                    'Featured'      => 'Featured',
                ) );
            }
            if ( '' === $file ) {
                continue;
            }

            $data       = get_file_data( $file, array(
                'Name'        => 'Plugin Name',
                'Description' => 'Description',
                'Author'      => 'Author',
                'AuthorURI'   => 'Author URI',
            ) );
            $relative   = str_replace( '\\', '/', str_replace( WP_PLUGIN_DIR . '/', '', $file ) );
            $icon_path  = ltrim( trim( (string) ( $headers['Icon Path'] ?? '' ) ), '/' );
            $icon_url   = $icon_path ? plugins_url( $icon_path, $file ) : '';
            $cover      = esc_url_raw( (string) ( $headers['Cover URI'] ?? '' ) );
            $title      = sanitize_text_field( (string) ( $data['Name'] ?? '' ) );
            if ( '' === $title ) {
                $title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
            }
            $plugin_cat = sanitize_text_field( (string) ( $headers['Category'] ?? '' ) );

            if ( '' !== $search ) {
                $haystack = strtolower( $slug . ' ' . $title . ' ' . $plugin_cat );
                if ( false === strpos( $haystack, strtolower( $search ) ) ) {
                    continue;
                }
            }
            if ( '' !== $category && $plugin_cat !== $category ) {
                continue;
            }

            $plugins[] = (object) array(
                'plugin_slug'   => $slug,
                'plugin_file'   => $relative,
                'directory'     => 'bizcity-twin-ai/plugins/' . $slug,
                'title'         => $title,
                'author_name'   => sanitize_text_field( (string) ( $data['Author'] ?? 'BizCity' ) ),
                'author_url'    => esc_url_raw( (string) ( $data['AuthorURI'] ?? '' ) ),
                'image_url'     => $cover ? $cover : ( $icon_url ? $icon_url : self::default_plugin_cover() ),
                'icon_url'      => $icon_url,
                'quickview'     => sanitize_text_field( (string) ( $data['Description'] ?? '' ) ),
                'description'   => wp_kses_post( (string) ( $data['Description'] ?? '' ) ),
                'credit_price'  => (int) ( $headers['Credit'] ?? 0 ),
                'vnd_price'     => (int) ( $headers['Price'] ?? 0 ),
                'is_featured'   => ! empty( $headers['Featured'] ),
                'required_plan' => sanitize_key( (string) ( $headers['Plan'] ?? 'free' ) ),
            );
        }

        usort( $plugins, static function ( $left, $right ) {
            if ( $left->is_featured !== $right->is_featured ) {
                return $left->is_featured ? -1 : 1;
            }
            return strcasecmp( (string) $left->title, (string) $right->title );
        } );
        return $plugins;
    }

    private static function get_bundle_root(): string {
        // [2026-08-27 Johnny Chu] PHASE-1.29-MARKET-BUNDLE-LIST — resolve
        // from this canonical core file when compat slug detection is stale.
        $canonical = dirname( __DIR__, 3 ) . '/';
        if ( is_dir( $canonical . 'plugins' ) ) {
            return $canonical;
        }
        if ( defined( 'BIZCITY_TWIN_AI_DIR' ) && is_dir( BIZCITY_TWIN_AI_DIR . 'plugins' ) ) {
            return trailingslashit( BIZCITY_TWIN_AI_DIR );
        }
        return '';
    }

    private static function get_bundle_plugin( string $slug ) {
        foreach ( self::get_bundle_plugins() as $plugin ) {
            if ( $plugin->plugin_slug === sanitize_key( $slug ) ) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Resolve the guard constant used by a bundled child plugin.
     *
     * Bundled children are intentionally loaded by bizcity-twin-ai rather than
     * stored as independent entries in active_plugins.
     */
    private static function bundle_guard_constant( string $slug ): string {
        $guards = array(
            'bizcity-admin-hook-zalo' => 'BIZCITY_ADMIN_ZALO_DIR',
            'bizcity-facebook-bot'    => 'BIZCITY_FACEBOOK_BOT_VERSION',
            'bizgpt-tool-google'      => 'BZGOOGLE_VERSION',
            'bizcity-zalo-bot'        => 'BIZCITY_ZALO_BOT_VERSION',
            'bizcity-zalo-personal'   => 'BIZCITY_ZALO_PERSONAL_VERSION',
            'bizcity-doc'             => 'BZDOC_VERSION',
            'bizcity-twin-crm'        => 'BIZCITY_CRM_VERSION',
            'bizcoach-pro'            => 'BCPRO_VERSION',
            'bizcity-pagebuilder'     => 'BZPB_VERSION',
            'bizcity-profile'         => 'BIZCITY_PERSONAL_VERSION',
        );
        $slug = sanitize_key( $slug );
        return isset( $guards[ $slug ] ) ? $guards[ $slug ] : '';
    }

    /**
     * Check native WordPress activation and Twin AI bundle ownership.
     */
    private static function is_market_plugin_active( string $slug, string $plugin_file = '' ): bool {
        if ( $plugin_file && function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
            return true;
        }
        $guard = self::bundle_guard_constant( $slug );
        return '' !== $guard && defined( $guard );
    }

    private static function default_plugin_cover(): string {
        // [2026-08-27 Johnny Chu] PHASE-1.29-MARKET-COVER — provide a local
        // logo when plugin metadata does not include a cover or icon asset.
        return trailingslashit( BIZCITY_MARKET_URL ) . 'assets/default-plugin-logo.svg';
    }

    /**
     * Render local marketplace (existing PHP-driven grid).
     */
    private static function render_local( string $base_url ): void {

        $q = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $cat_filter = sanitize_text_field(wp_unslash($_GET['cat'] ?? ''));
        $rows       = self::get_bundle_plugins( $q, $cat_filter );
        $categories = array_values( array_unique( array_filter( array_map( static function ( $plugin ) {
            return isset( $plugin->category ) ? (string) $plugin->category : '';
        }, $rows ) ) ) );
        sort( $categories, SORT_STRING );

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        ?>
            <div class="bc-market-head">
                <h1>Ứng dụng</h1>
            </div>
            <form method="get" class="bc-market-search">
                <input type="hidden" name="page" value="bizcity-marketplace"/>
                <?php if ($cat_filter): ?>
                    <input type="hidden" name="cat" value="<?php echo esc_attr($cat_filter); ?>"/>
                <?php endif; ?>
                <input type="text" name="s" value="<?php echo esc_attr($q); ?>" placeholder="Tìm ứng dụng..."/>
                <button class="button button-primary">Tìm</button>
            </form>

            <?php if ($categories): ?>
            <div class="bc-market-cats">
                <a class="bc-cat-btn <?php echo !$cat_filter ? 'is-active' : ''; ?>"
                   href="<?php echo esc_url(add_query_arg(['page'=>'bizcity-marketplace','s'=>$q,'cat'=>''], admin_url('admin.php'))); ?>">
                    Tất cả
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a class="bc-cat-btn <?php echo $cat_filter === $cat ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg(['page'=>'bizcity-marketplace','s'=>$q,'cat'=>$cat], admin_url('admin.php'))); ?>">
                        <?php echo esc_html(ucfirst($cat)); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="bc-market-grid">
                <?php if (empty($rows)): ?>
                    <div class="bc-empty" style="grid-column:1/-1">Không tìm thấy ứng dụng nào.</div>
                <?php endif; ?>

                <?php foreach ($rows as $p):
                    $slug = sanitize_key($p->plugin_slug ?? '');
                    // [2026-08-29 Johnny Chu] HOTFIX-MARKET-BUNDLE-STATE — reflect runtime-loaded bundled plugins as active.
                    $is_active = self::is_market_plugin_active( $slug, $p->plugin_file );
                    $is_installed = !empty($p->plugin_file)
                        && is_file(WP_PLUGIN_DIR . '/' . ltrim($p->plugin_file, '/'));
                    $credit = (int)($p->credit_price ?? 0);
                    ?>
                    <div class="bc-card" data-slug="<?php echo esc_attr($slug); ?>">
                        <div class="bc-thumb bc-detail" data-slug="<?php echo esc_attr($slug); ?>" style="background-image:url('<?php echo esc_url($p->image_url ?? ''); ?>')">
                            <?php if (!empty($p->category)): ?>
                                <span class="bc-thumb-badge"><?php echo esc_html($p->category); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="bc-body">
                            <div class="bc-title">
                                <a href="#" class="bc-detail" data-slug="<?php echo esc_attr($slug); ?>">
                                    <?php echo esc_html($p->title ?? $slug); ?>
                                </a>
                            </div>

                            <div class="bc-sub">
                                <span><?php echo esc_html($p->author_name ?: 'BizCity'); ?></span>
                            </div>

                            <?php if ($credit > 0): ?>
                            <div class="bc-price">
                                <div class="bc-credit-info"><?php echo $credit; ?> credit / lần sử dụng</div>
                            </div>
                            <?php else: ?>
                            <div class="bc-price">
                                <div class="bc-credit-info">Miễn phí</div>
                            </div>
                            <?php endif; ?>

                            <div class="bc-actions">
                                <?php if ($is_active): ?>
                                    <button class="button bc-deactivate" data-slug="<?php echo esc_attr($slug); ?>">⏸ Tắt</button>
                                    <span class="bc-badge bc-badge-active">✓ Đang dùng</span>
                                <?php else: ?>
                                    <button class="button button-primary bc-activate" data-slug="<?php echo esc_attr($slug); ?>">
                                        ⚡ Kích hoạt
                                    </button>
                                <?php endif; ?>
                                <?php if ($is_installed): ?>
                                    <button class="button bc-uninstall" data-slug="<?php echo esc_attr($slug); ?>">
                                        🗑 Gỡ cài đặt
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Modal -->
            <div class="bc-modal" id="bc-market-modal" aria-hidden="true">
                <div class="bc-modal-backdrop"></div>
                <div class="bc-modal-dialog" role="dialog" aria-modal="true">
                    <button class="bc-modal-close" type="button" aria-label="Close">×</button>
                    <div class="bc-modal-content">
                        <div class="bc-modal-loading">Đang tải...</div>
                    </div>
                </div>
            </div>

        <?php
    }
}
