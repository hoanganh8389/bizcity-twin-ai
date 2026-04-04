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

class BizCity_Market_Network_Admin {

    const OPT_CATALOG = 'bizcity_app_catalog';

    public static function boot() {
        #add_action('network_admin_menu', [__CLASS__, 'menu'], 30);
        #add_action('network_admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function menu() {
        // Submenu dưới bizcity-dashboard (anh đang dùng)
        add_submenu_page(
            'bizcity-dashboard',
            'Ứng dụng quicksetup',
            'Ứng dụng quicksetup',
            'manage_network',
            'bizcity-apps-page',
            [__CLASS__, 'render_page'],
            2
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'bizcity-apps-page') === false) return;

        wp_enqueue_script('jquery-ui-sortable');
        // UI style (dùng asset chung của bizcity-market)
        wp_enqueue_style('bizcity-market-admin', BIZCITY_MARKET_URL . '/assets/admin.css', [], BIZCITY_MARKET_VER);
        wp_enqueue_script('bizcity-market-admin', BIZCITY_MARKET_URL . '/assets/admin.js', ['jquery'], BIZCITY_MARKET_VER, true);
    }

    // ====== DATA ======

    public static function get_catalog() : array {
        $catalog = get_site_option(self::OPT_CATALOG, []);
        return is_array($catalog) ? $catalog : [];
    }

    public static function set_catalog(array $catalog) : void {
        update_site_option(self::OPT_CATALOG, $catalog);
    }

    public static function sanitize_app_item($item) : ?array {
        $item = is_array($item) ? $item : [];

        $key = sanitize_key($item['key'] ?? '');
        if (!$key) return null;

        return [
            'key'             => $key,
            'name'            => sanitize_text_field($item['name'] ?? $key),
            'category'        => sanitize_text_field($item['category'] ?? 'Khác'),
            'plugin_file'     => sanitize_text_field($item['plugin_file'] ?? ''), // ex: woocommerce/woocommerce.php
            'link'            => esc_url_raw($item['link'] ?? ''),                // admin.php?page=...
            'desc'            => sanitize_textarea_field($item['desc'] ?? ''),
            'icon'            => sanitize_text_field($item['icon'] ?? 'dashicons-admin-plugins'),
            'default_checked' => !empty($item['default_checked']) ? 1 : 0,
            'is_core'         => !empty($item['is_core']) ? 1 : 0,
        ];
    }

    public static function seed_if_empty() : void {
        $existing = self::get_catalog();
        if (!empty($existing)) return;

        // --- Seed tối thiểu: anh có thể copy thêm list lớn ở bước sau ---
        $apps = [
            [
                'key' => 'website',
                'name' => 'Trang web',
                'desc' => 'Website lõi mặc định',
                'category' => 'Trang web',
                'icon' => 'dashicons-admin-site',
                'is_core' => 1,
                'default_checked' => 1,
                'plugin_file' => '',
                'link' => '',
            ],
        ];

        self::set_catalog($apps);
    }

    // ====== PAGE ======

    public static function render_page() {
        if (!current_user_can('manage_network_options')) {
            wp_die('No permission');
        }

        self::seed_if_empty();

        // SAVE
        if (isset($_POST['bizcity_apps_save']) && check_admin_referer('bizcity_apps_save_action')) {
            $items = wp_unslash($_POST['apps'] ?? []);
            $new = [];
            $seen = [];

            if (is_array($items)) {
                foreach ($items as $row) {
                    $san = self::sanitize_app_item($row);
                    if (!$san) continue;

                    // unique key
                    if (isset($seen[$san['key']])) continue;
                    $seen[$san['key']] = 1;

                    // enforce website core/default
                    if ($san['key'] === 'website') {
                        $san['is_core'] = 1;
                        $san['default_checked'] = 1;
                    }

                    $new[] = $san;
                }
            }

            // ensure website exists
            $hasWebsite = false;
            foreach ($new as $a) if (($a['key'] ?? '') === 'website') $hasWebsite = true;
            if (!$hasWebsite) {
                array_unshift($new, [
                    'key' => 'website',
                    'name' => 'Trang web',
                    'category' => 'Trang web',
                    'plugin_file' => '',
                    'link' => '',
                    'desc' => 'Website lõi mặc định',
                    'icon' => 'dashicons-admin-site',
                    'default_checked' => 1,
                    'is_core' => 1,
                ]);
            }

            self::set_catalog($new);

            echo '<div class="notice notice-success is-dismissible"><p><strong>Đã lưu BizCity Apps Catalog.</strong></p></div>';
        }

        $catalog = self::get_catalog();

        $category_suggestions = [
            'Trang web', 'Bán hàng', 'Tài chính', 'Dịch vụ', 'Năng suất',
            'Chuỗi cung ứng', 'Marketing', 'Nhân sự', 'Tùy chỉnh', 'Khác'
        ];

        // Template
        require BIZCITY_MARKET_DIR . '/templates/network/page-apps.php';
    }

    // ====== HELPERS ======

    public static function group_by_category(array $catalog) : array {
        $groups = [];
        foreach ($catalog as $app) {
            $cat = $app['category'] ?? 'Khác';
            if (!isset($groups[$cat])) $groups[$cat] = [];
            $groups[$cat][] = $app;
        }
        return $groups;
    }
}
