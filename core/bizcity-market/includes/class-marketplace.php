<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Marketplace {

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu'], 25);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 25);

        // Handle sync BEFORE output (wp_redirect needs headers not sent yet)
        add_action('admin_init', [__CLASS__, 'handle_sync_early']);

        // ajax load plugin detail
        add_action('wp_ajax_bizcity_market_plugin_detail', [__CLASS__, 'ajax_plugin_detail']);

        // ajax activate plugin (all plugins freely activatable — no credit purchase)
        add_action('wp_ajax_bizcity_market_activate_plugin', [__CLASS__, 'ajax_activate_plugin']);

        // ajax deactivate plugin from marketplace
        add_action('wp_ajax_bizcity_market_deactivate_plugin', [__CLASS__, 'ajax_deactivate_plugin']);
    }

    /**
     * Handle sync action early (admin_init) so wp_redirect works before output.
     */
    public static function handle_sync_early(): void {
        if ( ! isset( $_GET['page'], $_GET['action'] ) ) return;
        if ( $_GET['page'] !== 'bizcity-marketplace' || $_GET['action'] !== 'sync' ) return;
        if ( ! current_user_can( 'activate_plugins' ) ) return;

        check_admin_referer( 'bc_market_sync' );

        $sync_ver = '3';
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
                'title'             => 'Chợ ứng dụng BizCity',
                'searchPlaceholder' => 'Tìm plugin...',
                'hasApiKey'         => class_exists('BizCity_Connection_Gate') ? (bool) BizCity_Connection_Gate::instance()->get_api_key() : false,
                'settingsUrl'       => admin_url( 'admin.php?page=bizcity-llm-router' ),
                'registerUrl'       => 'https://bizcity.vn/my-account/api-keys/',
            ] );
        }
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
            wp_send_json(['ok'=>false, 'msg'=>'Bạn không có quyền kích hoạt plugin.']);
        }

        // API key required to activate agent plugins
        if ( ! class_exists('BizCity_Connection_Gate') || ! BizCity_Connection_Gate::instance()->get_api_key() ) {
            wp_send_json([
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => 'Bạn cần đăng ký API Key với BizCity để kích hoạt plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key, sau đó vào Cài đặt API để cấu hình.',
            ]);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Thiếu plugin_slug.']);

        // All plugins are freely activatable — no credit/entitlement check needed
        $db = BizCity_Market_DB::globaldb();

        // Resolve plugin file — try catalog DB first, then scan local filesystem
        $plugin_file = '';
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $plugin_file = $db->get_var($db->prepare(
                "SELECT plugin_file FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug
            ));
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

        if (!$plugin_file) {
            wp_send_json(['ok'=>false, 'msg'=>'Không tìm thấy plugin "' . $slug . '" trong catalog hoặc trên server.']);
        }

        // Check if file exists on disk
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (!file_exists($full_path)) {
            wp_send_json(['ok'=>false, 'msg'=>'File plugin không tồn tại trên server. Liên hệ admin.']);
        }

        // Check if already active
        if (is_plugin_active($plugin_file)) {
            wp_send_json(['ok'=>true, 'msg'=>'Plugin đã được kích hoạt.', 'status'=>'active']);
        }

        // Activate the plugin
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            wp_send_json(['ok'=>false, 'msg'=>'Kích hoạt thất bại: ' . $result->get_error_message()]);
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
            'msg'    => 'Kích hoạt thành công! Plugin đã sẵn sàng sử dụng.',
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
            wp_send_json(['ok'=>false, 'msg'=>'Bạn không có quyền ngừng kích hoạt plugin.']);
        }

        // API key required to manage agent plugins
        if ( ! class_exists('BizCity_Connection_Gate') || ! BizCity_Connection_Gate::instance()->get_api_key() ) {
            wp_send_json([
                'ok'          => false,
                'need_api_key'=> true,
                'msg'         => 'Bạn cần đăng ký API Key với BizCity để quản lý plugin. Truy cập https://bizcity.vn/my-account/api-keys/ để tạo API Key.',
            ]);
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin_slug'] ?? ''));
        if (!$slug) wp_send_json(['ok'=>false, 'msg'=>'Thiếu plugin_slug.']);

        // Resolve plugin file — DB first, then local filesystem fallback
        $db = BizCity_Market_DB::globaldb();
        $plugin_file = '';
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $plugin_file = $db->get_var($db->prepare(
                "SELECT plugin_file FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug
            ));
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

        if (!$plugin_file) {
            wp_send_json(['ok'=>false, 'msg'=>'Không tìm thấy plugin "' . $slug . '".']);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active($plugin_file)) {
            wp_send_json(['ok'=>true, 'msg'=>'Plugin đã được ngừng kích hoạt.', 'status'=>'inactive']);
        }

        deactivate_plugins($plugin_file);

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
            'msg'    => 'Đã ngừng kích hoạt plugin thành công.',
            'status' => 'inactive',
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
        if (!$db) wp_send_json(['ok'=>false, 'msg'=>'No globaldb']);

        $tP = BizCity_Market_DB::t_plugins();
        $p = $db->get_row($db->prepare("SELECT * FROM {$tP} WHERE plugin_slug=%s LIMIT 1", $slug));
        if (!$p) wp_send_json(['ok'=>false, 'msg'=>'Plugin not found']);

        // All plugins are freely activatable — no entitlement purchase needed
        $owned = true;

        // Check if plugin is activated on this site
        $is_activated = false;
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!empty($p->plugin_file)) {
            $is_activated = is_plugin_active($p->plugin_file);
        }

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
        $thumb = !empty($p->image_url) ? esc_url($p->image_url) : '';
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
     * Render local marketplace (existing PHP-driven grid).
     */
    private static function render_local( string $base_url ): void {

        $q = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $cat_filter = sanitize_text_field(wp_unslash($_GET['cat'] ?? ''));
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $per = 24;

        $data = BizCity_Market_Catalog::list([
            'q' => $q,
            'page' => $page,
            'per' => $per,
            'category' => $cat_filter,
        ]);

        $rows = $data['rows'] ?? [];
        $total = (int)($data['total'] ?? 0);

        $blog_id = (int)get_current_blog_id();

        // build active plugins map
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_plugins_map = [];
        foreach ($rows as $p) {
            if (!empty($p->plugin_file)) {
                $s = sanitize_key($p->plugin_slug ?? '');
                $active_plugins_map[$s] = is_plugin_active($p->plugin_file);
            }
        }

        // Fetch categories for filter bar
        $db = BizCity_Market_DB::globaldb();
        $categories = [];
        if ($db) {
            $tP = BizCity_Market_DB::t_plugins();
            $cats = $db->get_col("SELECT DISTINCT category FROM {$tP} WHERE category != '' AND is_active=1 ORDER BY category ASC");
            if ($cats) $categories = $cats;
        }

        ?>
            <div class="bc-market-head">
                <h1>Ứng dụng</h1>
                <?php if ( current_user_can('activate_plugins') ): ?>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( $base_url . '&action=sync', 'bc_market_sync' ) ); ?>">🔄 Sync Agent Plugins</a>
                <?php endif; ?>
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
                    $is_active = !empty($active_plugins_map[$slug]);
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
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            // paging
            $pages = max(1, (int)ceil($total / $per));
            if ($pages > 1):
                $base = add_query_arg(['page'=>'bizcity-marketplace','s'=>$q,'cat'=>$cat_filter,'paged'=>'%#%'], admin_url('admin.php'));
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base' => $base,
                    'format' => '',
                    'current' => $page,
                    'total' => $pages,
                ]);
                echo '</div></div>';
            endif;
            ?>

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
