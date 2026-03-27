<?php

defined( 'ABSPATH' ) || exit;

class BizCity_Market_Admin {

    public static function boot() {
        add_action('network_admin_menu', [__CLASS__, 'network_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function network_menu() {
        add_menu_page(
            'BizCity Market',
            'BizCity Market',
            'manage_network_options',
            'bizcity-market',
            [__CLASS__, 'page_network_plugins'],
            'dashicons-store',
            56
        );

        add_submenu_page(
            'bizcity-market',
            'Danh sách plugin',
            'Danh sách plugin',
            'manage_network_options',
            'bizcity-market',
            [__CLASS__, 'page_network_plugins']
        );

        add_submenu_page(
            'bizcity-market',
            'Thêm plugin',
            'Thêm plugin',
            'manage_network_options',
            'bizcity-market-add',
            [__CLASS__, 'page_network_plugin_add']
        );

        add_submenu_page(
            'bizcity-dashboard',
            'Chính sách hoa hồng',
            'Chính sách hoa hồng',
            'manage_network_options',
            'bizcity-hub-commission-policy',
            [__CLASS__, 'page_hub_commission_policy']
        );
            
    }
    /**
     * Trang quản trị chính sách hoa hồng các hub
     */
    public static function page_hub_commission_policy() {
        require BIZCITY_MARKET_DIR . '/templates/admin/hub-commission-policy.php';
    }
    public static function page_network_plugins() {
        require BIZCITY_MARKET_DIR . '/templates/admin/network-plugins-list.php';
    }

    public static function page_network_plugin_add() {
        require BIZCITY_MARKET_DIR . '/templates/admin/network-plugin-edit.php';
    }

    public static function assets($hook) {
        if (strpos($hook, 'bizcity-market') === false) return;
        wp_enqueue_media();
        wp_enqueue_style('bizcity-market-admin', BIZCITY_MARKET_URL . '/assets/admin.css', [], BIZCITY_MARKET_VER);
        wp_enqueue_script('bizcity-market-admin', BIZCITY_MARKET_URL . '/assets/admin.js', ['jquery'], BIZCITY_MARKET_VER, true);
    }
}
