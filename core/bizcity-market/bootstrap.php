<?php
if (!defined('ABSPATH')) exit;
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

require_once BIZCITY_MARKET_DIR . '/includes/class-entitlements.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-hooks.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-admin.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-cron.php';
#require_once BIZCITY_MARKET_DIR . '/includes/class-market.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-network-admin.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-site-apps.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-catalog.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-shortcodes.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-install.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-transactions.php';
// Initialize components
require_once BIZCITY_MARKET_DIR . '/includes/class-woo-sync.php';
require_once BIZCITY_MARKET_DIR . '/includes/class-credit.php';
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
    // Skip chỉnh UI plugins.php cho user cụ thể (debug/ẩn tính năng)
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    if ($u->user_login !== 'admin1') {
      BizCity_Market_Hooks::boot(); // class-hooks.php ==> các hook chung
      
    }
}, 1);

add_action('wp_enqueue_scripts', function(){
  wp_enqueue_style('bizcity-market-front', BIZCITY_MARKET_URL.'/assets/front.css', [], BIZCITY_MARKET_VER);
});
