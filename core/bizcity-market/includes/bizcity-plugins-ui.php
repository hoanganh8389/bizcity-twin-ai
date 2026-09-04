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

/**
 * BizCity Plugins UI - Enhance plugins.php list:
 * - Add thumbnail, quickview, credit/vnd price from BizCity Market table
 * - Keep only Activate / Deactivate actions
 */

class BizCity_Plugins_UI {

    private const DISABLED_BUNDLED_OPTION = 'bizcity_disabled_bundled_plugins';

    private static $bundled_plugins = [
        'bizcity-tool-content' => [
            'file'        => 'bizcity-tool-content.php',
            'auto_loaded' => false,
        ],
        'bizcity-tool-image' => [
            'file'        => 'bizcity-tool-image.php',
            'auto_loaded' => true,
        ],
    ];

    public static function boot() {
        if (is_network_admin()) return; // chỉ chỉnh site admin plugins.php

        add_action('admin_init', [__CLASS__, 'init']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 20);
        add_action('admin_post_bizcity_bundled_activate', [__CLASS__, 'activate_bundled']);
        add_action('admin_post_bizcity_bundled_deactivate', [__CLASS__, 'deactivate_bundled']);
        add_action('admin_post_bizcity_bundled_uninstall', [__CLASS__, 'uninstall_bundled']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function init() {
        global $pagenow;
        if ($pagenow !== 'plugins.php') return;

        // 1) Columns
        add_filter('manage_plugins_columns', [__CLASS__, 'columns'], 20);

        // 2) Column content
        add_action('manage_plugins_custom_column', [__CLASS__, 'column_content'], 20, 3);

        // 3) Remove extra actions, keep only activate/deactivate
        add_filter('plugin_action_links', [__CLASS__, 'keep_only_activation_actions'], 999, 4);

        // 4) Optional: remove row meta "View details | Visit plugin site"
        add_filter('plugin_row_meta', [__CLASS__, 'row_meta'], 999, 4);

        // 5) Preload market data to avoid query per row
        add_filter('all_plugins', [__CLASS__, 'inject_bundled_plugins'], 10);
        add_filter('all_plugins', [__CLASS__, 'inject_market_cache'], 20);
        add_filter('plugin_action_links', [__CLASS__, 'bundled_action_links'], 1000, 4);
        // Đổi tiêu đề trang Plugins -> Ứng dụng mua thêm
        add_filter('admin_title', function ($title, $admin_title) {
            global $pagenow;

            if ($pagenow === 'plugins.php') {
                return 'Ứng dụng đã mua thêm ‹ ' . get_bloginfo('name');
            }
            return $title;
        }, 10, 2);
        add_action('admin_footer', function () {
            global $pagenow;
            if ($pagenow !== 'plugins.php') return;
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const h1 = document.querySelector('.wrap h1.wp-heading-inline');
                if (h1) h1.textContent = 'Ứng dụng đã mua thêm';
            });
            </script>
            <?php
        });


    }

    /**
     * Add installed nested BizCity extensions as management-only rows.
     * They remain outside active_plugins; the main loader owns auto-loaded extensions.
     */
    public static function inject_bundled_plugins($plugins) {
        global $pagenow;
        if ($pagenow !== 'plugins.php' || !is_array($plugins)) return $plugins;

        foreach (self::$bundled_plugins as $slug => $config) {
            $plugin_file = self::bundled_plugin_file($slug);
            $full_path = self::bundled_plugin_path($slug);
            if (!$plugin_file || !is_file($full_path) || !is_readable($full_path) || isset($plugins[$plugin_file])) {
                continue;
            }

            $data = function_exists('get_plugin_data') ? get_plugin_data($full_path, false, false) : [];
            $plugins[$plugin_file] = array_merge(
                [
                    'Name'        => $slug,
                    'PluginURI'   => '',
                    'Version'     => '',
                    'Description' => '',
                    'Author'      => 'BizCity',
                    'AuthorURI'   => '',
                    'Network'     => false,
                    'RequiresWP'  => '',
                    'RequiresPHP' => '',
                    'UpdateURI'   => '',
                    'TextDomain'  => '',
                    'DomainPath'  => '',
                ],
                is_array($data) ? $data : [],
                [ '_bizcity_bundled' => true, '_bizcity_bundled_slug' => $slug ]
            );
        }

        return $plugins;
    }

    /** Add lifecycle links for management-only nested plugin rows. */
    public static function bundled_action_links($actions, $plugin_file, $plugin_data, $context) {
        global $pagenow;
        if ($pagenow !== 'plugins.php') return $actions;

        $config = self::bundled_config_by_file($plugin_file);
        if (!$config) return $actions;

        $slug = $config['slug'];
        $actions = [];
        if (self::bundled_is_active($slug)) {
            $actions['deactivate'] = '<a href="' . esc_url(self::lifecycle_url('deactivate', $slug)) . '">Tắt</a>';
        } else {
            $actions['activate'] = '<a href="' . esc_url(self::lifecycle_url('activate', $slug)) . '">Kích hoạt</a>';
        }
        if (current_user_can('delete_plugins')) {
            $actions['delete'] = '<a class="delete" href="' . esc_url(self::lifecycle_url('uninstall', $slug)) . '" onclick="return confirm(\'Xóa plugin và toàn bộ dữ liệu riêng của plugin này?\');">Xóa</a>';
        }
        return $actions;
    }

    public static function activate_bundled() {
        $slug = self::requested_slug('activate');
        $config = self::bundled_config_by_slug($slug);
        if (!$config || !current_user_can('activate_plugins')) self::back_to_plugins('forbidden');
        if (!self::bundled_file_ready($slug)) self::back_to_plugins('missing');

        // [2026-08-26 Johnny Chu] R-SAFE-LOADER — re-enable auto-loaded image
        // extensions without writing a synthetic nested active_plugins entry.
            self::set_bundled_disabled($slug, false);
            self::queue_rewrite_flush($slug);
        if (!$config['auto_loaded']) {
            self::load_plugin_admin_api();
            $result = activate_plugin(self::bundled_plugin_file($slug));
            if (is_wp_error($result)) self::back_to_plugins('activate_failed');
        }
        self::back_to_plugins('activated');
    }

    public static function deactivate_bundled() {
        $slug = self::requested_slug('deactivate');
        $config = self::bundled_config_by_slug($slug);
        if (!$config || !current_user_can('activate_plugins')) self::back_to_plugins('forbidden');
        if (!self::bundled_file_ready($slug)) self::back_to_plugins('missing');

        // [2026-08-26 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — deactivation
        // only disables execution; data cleanup belongs to the explicit Xóa action.
        if ($config['auto_loaded']) self::set_bundled_disabled($slug, true);
            self::queue_rewrite_flush($slug);
        self::load_plugin_admin_api();
        $plugin_file = self::bundled_plugin_file($slug);
        if (function_exists('is_plugin_active') && is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }
        do_action('bizcity_market_plugin_deactivated', $slug, $plugin_file, (int) get_current_blog_id());
        self::back_to_plugins('deactivated');
    }

    public static function uninstall_bundled() {
        $slug = self::requested_slug('uninstall');
        if (!self::bundled_config_by_slug($slug) || !current_user_can('delete_plugins')) self::back_to_plugins('forbidden');
        if (!self::bundled_file_ready($slug)) self::back_to_plugins('missing');

        // Disable before teardown so the next request cannot re-load the artifact.
        self::set_bundled_disabled($slug, true);
            self::queue_rewrite_flush($slug);
        self::load_plugin_admin_api();
        $plugin_file = self::bundled_plugin_file($slug);
        if (function_exists('is_plugin_active') && is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }

        $result = class_exists('BizCity_Plugin_Installer')
            ? BizCity_Plugin_Installer::uninstall($slug)
            : new WP_Error('installer_missing', 'Plugin installer chưa sẵn sàng.');
        if (is_wp_error($result)) self::back_to_plugins('uninstall_failed');

        self::set_bundled_disabled($slug, false);
        self::back_to_plugins('uninstalled');
    }

    public static function notices() {
        global $pagenow;
        if ($pagenow !== 'plugins.php' || empty($_GET['bizcity_bundled_notice'])) return;
        $notice = sanitize_key(wp_unslash($_GET['bizcity_bundled_notice']));
        $messages = [
            'activated'       => [ 'success', 'Plugin đã được kích hoạt.' ],
            'deactivated'     => [ 'success', 'Plugin đã được tắt. Dữ liệu vẫn được giữ nguyên.' ],
            'uninstalled'     => [ 'success', 'Plugin và dữ liệu riêng đã được xóa.' ],
            'activate_failed' => [ 'error', 'Không thể kích hoạt plugin.' ],
            'uninstall_failed'=> [ 'error', 'Không thể xóa plugin hoặc dữ liệu riêng.' ],
            'missing'         => [ 'error', 'Không tìm thấy artifact plugin trên máy chủ.' ],
            'forbidden'       => [ 'error', 'Bạn không có quyền quản lý plugin này.' ],
        ];
        if (!isset($messages[$notice])) return;
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
    }

    private static function requested_slug($action) {
        check_admin_referer('bizcity_bundled_' . $action . '_' . sanitize_key(wp_unslash($_GET['plugin'] ?? '')));
        return sanitize_key(wp_unslash($_GET['plugin'] ?? ''));
    }

    private static function lifecycle_url($action, $slug) {
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'bizcity_bundled_' . $action, 'plugin' => $slug ],
                admin_url('admin-post.php')
            ),
            'bizcity_bundled_' . $action . '_' . $slug
        );
    }

    private static function back_to_plugins($notice) {
        wp_safe_redirect(add_query_arg('bizcity_bundled_notice', sanitize_key($notice), admin_url('plugins.php')));
        exit;
    }

    private static function bundled_config_by_slug($slug) {
        if (!isset(self::$bundled_plugins[$slug])) return null;
        return array_merge(self::$bundled_plugins[$slug], [ 'slug' => $slug ]);
    }

    private static function bundled_config_by_file($plugin_file) {
        foreach (self::$bundled_plugins as $slug => $config) {
            if (self::bundled_plugin_file($slug) === $plugin_file) {
                return array_merge($config, [ 'slug' => $slug ]);
            }
        }
        return null;
    }

    private static function bundled_plugin_path($slug) {
        $config = self::bundled_config_by_slug($slug);
        return $config ? BIZCITY_TWIN_AI_DIR . 'plugins/' . $slug . '/' . $config['file'] : '';
    }

    private static function bundled_plugin_file($slug) {
        $path = self::bundled_plugin_path($slug);
        return $path && function_exists('plugin_basename') ? plugin_basename($path) : '';
    }

    private static function bundled_file_ready($slug) {
        $path = self::bundled_plugin_path($slug);
        return '' !== $path && is_file($path) && is_readable($path);
    }

    private static function bundled_is_active($slug) {
        $config = self::bundled_config_by_slug($slug);
        if (!$config) return false;
        if ($config['auto_loaded']) {
            $disabled = get_option(self::DISABLED_BUNDLED_OPTION, []);
            return !in_array($slug, is_array($disabled) ? $disabled : [], true);
        }
        self::load_plugin_admin_api();
        return function_exists('is_plugin_active') && is_plugin_active(self::bundled_plugin_file($slug));
    }

    private static function set_bundled_disabled($slug, $disabled) {
        $items = get_option(self::DISABLED_BUNDLED_OPTION, []);
        $items = is_array($items) ? array_values(array_unique(array_map('sanitize_key', $items))) : [];
        $items = $disabled ? array_values(array_unique(array_merge($items, [ $slug ]))) : array_values(array_diff($items, [ $slug ]));
        update_option(self::DISABLED_BUNDLED_OPTION, $items, false);
    }

    private static function load_plugin_admin_api() {
        if (function_exists('activate_plugin')) return;
        $file = ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_file($file) && is_readable($file)) require_once $file;
    }

    private static function queue_rewrite_flush($slug) {
        if (class_exists('BizCity_Rewrite_Flush_Registry')) {
            BizCity_Rewrite_Flush_Registry::queue_flush($slug);
        }
    }

    /** Map plugin file -> plugin_slug in market table */
    private static function plugin_file_to_slug(string $plugin_file): string {
        // plugin file: woocommerce-subscriptions/woocommerce-subscriptions.php => slug woocommerce-subscriptions
        $parts = explode('/', $plugin_file);
        $dir = sanitize_key($parts[0] ?? '');
        if ($dir) return $dir;

        // fallback: main file name without .php
        $base = basename($plugin_file, '.php');
        return sanitize_key($base);
    }

    /** Load all market rows for visible plugins into a cache keyed by slug */
    public static function inject_market_cache($plugins) {
        if (empty($plugins) || !is_array($plugins)) return $plugins;

        $slugs = [];
        foreach ($plugins as $file => $data) {
            $slugs[] = self::plugin_file_to_slug((string)$file);
        }
        $slugs = array_values(array_unique(array_filter($slugs)));
        if (!$slugs) return $plugins;

        // Global DB table
        if (!class_exists('BizCity_Market_DB')) return $plugins;
        $db = BizCity_Market_DB::globaldb();
        if (!$db) return $plugins;

        $tP = BizCity_Market_DB::t_plugins();

        // build placeholders
        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
        $sql = "SELECT plugin_slug,title,quickview,image_url,credit_price,vnd_price
                FROM {$tP}
                WHERE plugin_slug IN ($placeholders)";

        $rows = $db->get_results($db->prepare($sql, $slugs));
        $map = [];
        foreach ((array)$rows as $r) {
            $map[sanitize_key($r->plugin_slug)] = $r;
        }

        // store to a static cache (cheap)
        self::$market_cache = $map;

        return $plugins;
    }

    private static $market_cache = [];

    private static function market_row_for_plugin(string $plugin_file) {
        $slug = self::plugin_file_to_slug($plugin_file);
        return self::$market_cache[$slug] ?? null;
    }

    public static function columns($cols) {
        // giữ checkbox + Plugin core column, thêm BizCity Info, ẩn Description mặc định
        // $cols thường có: cb, name, description
        $new = [];
        if (isset($cols['cb'])) $new['cb'] = $cols['cb'];
        if (isset($cols['name'])) $new['name'] = 'Ứng dụng';
        $new['bizcity_info'] = 'Thông tin BizCity';

        return $new;
    }

    public static function column_content($column_name, $plugin_file, $plugin_data) {
        if ($column_name === 'name') {
            // column "Ứng dụng" -> hiển thị ảnh + tên + author (từ plugin header)
            $m = self::market_row_for_plugin((string)$plugin_file);

            $title = $plugin_data['Name'] ?? $plugin_file;
            $author = $plugin_data['AuthorName'] ?? ($plugin_data['Author'] ?? '');

            $img = '';
            if (!empty($m) && !empty($m->image_url)) {
                $img = esc_url($m->image_url);
            }

            echo '<div class="bcpl-app">';
            echo '  <div class="bcpl-thumb" style="background-image:url(\'' . esc_url($img) . '\')"></div>';
            echo '  <div class="bcpl-main">';
            echo '      <div class="bcpl-title">' . esc_html($title) . '</div>';
            if (!empty($author)) {
                echo '  <div class="bcpl-sub">' . wp_kses_post($author) . '</div>';
            }
            echo '  </div>';
            echo '</div>';
            return;
        }

        if ($column_name === 'bizcity_info') {
            $m = self::market_row_for_plugin((string)$plugin_file);

            $quick = (!empty($m) && !empty($m->quickview)) ? (string)$m->quickview : '';
            $credit = (!empty($m) && isset($m->credit_price)) ? (int)$m->credit_price : 0;
            $vnd = (!empty($m) && isset($m->vnd_price)) ? (int)$m->vnd_price : 0;

            echo '<div class="bcpl-info">';
            echo '  <div class="bcpl-price">';
            echo '    <span class="bcpl-credit">' . (int)$credit . ' credit</span>';
            echo '    <span class="bcpl-vnd">' . number_format_i18n($vnd) . ' đ</span>';
            echo '  </div>';

            if ($quick) {
                echo '  <div class="bcpl-quick">' . esc_html($quick) . '</div>';
            } else {
                // fallback: lấy Description từ plugin header
                $desc = $plugin_data['Description'] ?? '';
                if ($desc) {
                    echo '  <div class="bcpl-quick">' . esc_html(wp_strip_all_tags($desc)) . '</div>';
                } else {
                    echo '  <div class="bcpl-quick bcpl-muted">Chưa có quickview.</div>';
                }
            }

            echo '</div>';
            return;
        }
    }

    /** Keep only activate / deactivate links */
    public static function keep_only_activation_actions($actions, $plugin_file, $plugin_data, $context) {
        // chỉ tác động trong plugins.php
        global $pagenow;
        if ($pagenow !== 'plugins.php') return $actions;

        $keep = [];

        // WP action keys thường là 'activate', 'deactivate'
        if (isset($actions['activate'])) $keep['activate'] = $actions['activate'];
        if (isset($actions['deactivate'])) $keep['deactivate'] = $actions['deactivate'];

        // nếu plugin network only / hoặc không có quyền, giữ nguyên cái có
        return $keep ?: $actions;
    }

    /** Remove row meta links (optional) */
    public static function row_meta($meta, $plugin_file, $plugin_data, $status) {
        global $pagenow;
        if ($pagenow !== 'plugins.php') return $meta;
        return []; // ẩn hết row meta
    }

    public static function assets($hook) {
        if ($hook !== 'plugins.php') return;

        // CSS inline nhanh, không phụ thuộc file
        $css = '
        /* BizCity Plugins UI */
        .wp-list-table.plugins { border-radius:14px; overflow:hidden; }
        .wp-list-table.plugins td, .wp-list-table.plugins th { vertical-align: top; }

        .wp-list-table.plugins .column-name { width: 320px; }
        .wp-list-table.plugins .column-bizcity_info { width:auto; }

        .bcpl-app { display:flex; gap:12px; align-items:flex-start; }
        .bcpl-thumb{
            width:56px; height:56px; border-radius:14px;
            background:#f1f5f9 center/cover no-repeat;
            border:1px solid #e5e7eb;
            box-shadow: 0 12px 26px rgba(2,6,23,.06);
            flex:0 0 auto;
        }
        .bcpl-title{ font-weight:900; font-size:14px; color:#0f172a; margin-top:2px; }
        .bcpl-sub{ color:#64748b; font-size:12px; margin-top:4px; }

        .bcpl-info{ padding-top:2px; }
        .bcpl-price{ display:flex; gap:10px; align-items:baseline; margin-bottom:8px; }
        .bcpl-credit{
            display:inline-flex; align-items:center;
            padding:5px 10px; border-radius:999px;
            border:1px solid #e5e7eb; background:#f8fafc;
            font-weight:900; font-size:12px; color:#0f172a;
        }
        .bcpl-vnd{ color:#64748b; font-size:12px; font-weight:800; }
        .bcpl-quick{
            color:#334155; font-size:13px; line-height:1.6;
            max-width: 900px;
        }
        .bcpl-muted{ color:#94a3b8; }

        /* only keep action links styling */
        .wp-list-table.plugins .row-actions { visibility: visible; }
        .wp-list-table.plugins .row-actions span { margin-right:10px; }
        .wp-list-table.plugins .row-actions a { font-weight:800; }
        ';
        wp_register_style('bizcity-plugins-ui', false);
        wp_enqueue_style('bizcity-plugins-ui');
        wp_add_inline_style('bizcity-plugins-ui', $css);
    }
}

