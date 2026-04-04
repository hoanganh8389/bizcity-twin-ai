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

    public static function boot() {
        if (is_network_admin()) return; // chỉ chỉnh site admin plugins.php

        add_action('admin_init', [__CLASS__, 'init']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 20);
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
        add_filter('all_plugins', [__CLASS__, 'inject_market_cache'], 20);
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

