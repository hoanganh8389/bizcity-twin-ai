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
// [2026-08-26 Johnny Chu] PHASE-1.29-MARKET-LITE — plugins.php only needs
// the nested-plugin management adapter; avoid loading market schema/catalog
// services on this admin screen.
if ( is_admin()
    && isset( $_SERVER['SCRIPT_NAME'] )
    && false !== strpos( (string) $_SERVER['SCRIPT_NAME'], '/plugins.php' )
    && class_exists( 'BizCity_Safe_Loader', false ) ) {
    $_bc_plugins_ui = __DIR__ . '/includes/bizcity-plugins-ui.php';
    if ( is_file( $_bc_plugins_ui ) && is_readable( $_bc_plugins_ui ) ) {
        BizCity_Safe_Loader::require_file( $_bc_plugins_ui, 'market.plugins_ui' );
        if ( class_exists( 'BizCity_Plugins_UI', false ) ) {
            BizCity_Plugins_UI::boot();
        }
    }
    unset( $_bc_plugins_ui );
    return;
}
// Bootstrap file for BizCity Market MU Plugin
// bootstrap.php
// Define constants
// ----------------

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_MARKET_VER' ) ) {
    define('BIZCITY_MARKET_VER', '1.1.7');
}
if ( ! defined( 'BIZCITY_MARKET_DIR' ) ) {
    define('BIZCITY_MARKET_DIR', __DIR__);
}
if ( ! defined( 'BIZCITY_MARKET_URL' ) ) {
    define('BIZCITY_MARKET_URL', plugin_dir_url( __FILE__ ) );
}

// Skip if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_Market_Utils' ) ) {
    return;
}

require_once BIZCITY_MARKET_DIR . '/lib/class-utils.php';
require_once BIZCITY_MARKET_DIR . '/lib/class-cache.php';
require_once BIZCITY_MARKET_DIR . '/lib/class-logger.php';
require_once BIZCITY_MARKET_DIR . '/lib/class-db.php';
require_once BIZCITY_MARKET_DIR . '/lib/class-ui.php';

// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R13d-a — class-credit, class-transactions (loaded, never used) and the empty
// class-market.php stub are in core/_archived/market-dead/ with three unreferenced admin templates and a duplicate admin.js.
require_once BIZCITY_MARKET_DIR . '/includes/class-entitlements.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-hooks.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-admin.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-cron.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-network-admin.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-site-apps.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-catalog.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-shortcodes.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-install.php';
// Initialize components
require_once BIZCITY_MARKET_DIR . '/includes/class-woo-sync.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-marketplace.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-template-guard.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-remote-catalog.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-plugin-installer.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-update-checker.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-market-ajax.php';
require_once BIZCITY_MARKET_DIR . '/includes/bizcity-plugins-ui.php'; // Chỉnh lại UI trang plugins.php

add_action('plugins_loaded', function () {
    BizCity_Market_Install::boot();

    BizCity_Market_UI::boot_admin_style(); // class-ui.php ==> BC Admin Style
    
    BizCity_Market_Admin::boot(); // class-admin.php ==> quản lý chợ apps trên network-level  
    BizCity_Market_Cron::boot(); // class-cron.php ==> quản lý cron jobs
    BizCity_Market_Network_Admin::boot(); // class-network-admin.php ==> quản lý chợ apps trên network-level
    BizCity_Market_Site_Apps::boot(); // class-site-apps.php ==> quản lý apps trên site-level
    BizCity_Market_Shortcodes::boot(); // class-shortcodes.php ==> shortcodes liên quan đến chợ apps

    // Các component bổ sung
    BizCity_Market_Woo_Sync::boot(); // class-woo-sync.php ==> đồng bộ trạng thái cài đặt plugin với WooCommerce
    BizCity_Market_Marketplace::boot();   // class-marketplace.php ==> quản lý marketplace
    BizCity_Market_Template_Guard::boot(); // class-template-guard.php ==> guard cho template page khi plugin inactive
    // BizCity_Update_Checker không boot tự động — chỉ check khi user mở chợ (on-demand via AJAX)
    BizCity_Market_Ajax::boot();           // class-market-ajax.php ==> AJAX proxy cho remote marketplace JS
    BizCity_Plugins_UI::boot(); // class-bizcity-plugins-ui.php ==> chỉnh sửa UI trang plugins.php

    // Auto-sync agent plugins vào marketplace DB (throttled: 1 lần / 24h bằng transient)
    add_action( 'admin_init', [ 'BizCity_Market_Catalog', 'sync_agent_plugins' ] );
    // Chỉ giới hạn trên hệ thống bizcity.vn multisite (tránh ảnh hưởng bản cài của khách)
    $is_bizcity_net = is_multisite() && false !== strpos( network_site_url(), 'bizcity.vn' );
    if ( ! $is_bizcity_net ) {
        BizCity_Market_Hooks::boot(); // khách tải về dùng — boot bình thường, không hạn chế
    } else {
        // Trên bizcity.vn: chỉ admin1 và nnhshu97 được thấy plugins.php nguyên bản
        $u             = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
        $current_login = ( $u && ! empty( $u->user_login ) ) ? $u->user_login : '';
        if ( ! in_array( $current_login, [ 'admin1', 'nnhshu97' ], true ) ) {
            BizCity_Market_Hooks::boot(); // class-hooks.php ==> các hook chung
        }
    }
}, 1);

add_action('wp_enqueue_scripts', function(){
  wp_enqueue_style('bizcity-market-front', BIZCITY_MARKET_URL.'/assets/front.css', [], BIZCITY_MARKET_VER);
});
