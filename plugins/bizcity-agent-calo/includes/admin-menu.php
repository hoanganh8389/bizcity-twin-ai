<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'bzcalo_register_admin_menu' );
function bzcalo_register_admin_menu() {
    add_menu_page(
        'BizCity Calo',
        '🍽️ Calo Tracker',
        'read',
        BZCALO_SLUG,
        'bzcalo_page_dashboard',
        'dashicons-carrot',
        59
    );
    add_submenu_page( BZCALO_SLUG, 'Dashboard',   '📊 Dashboard',   'read',            BZCALO_SLUG,                'bzcalo_page_dashboard' );
    add_submenu_page( BZCALO_SLUG, 'Ghi bữa ăn',  '🍽️ Ghi bữa ăn', 'read',            BZCALO_SLUG . '-log',       'bzcalo_page_log_meal' );
    add_submenu_page( BZCALO_SLUG, 'Lịch sử',     '📜 Lịch sử',     'read',            BZCALO_SLUG . '-history',   'bzcalo_page_history' );
    add_submenu_page( BZCALO_SLUG, 'Quản lý Users','👥 Users',       'manage_options',  BZCALO_SLUG . '-users',     'bzcalo_page_admin_users' );
    add_submenu_page( BZCALO_SLUG, 'Thực phẩm',   '🥗 Thực phẩm',   'manage_options',  BZCALO_SLUG . '-foods',     'bzcalo_page_foods' );
    add_submenu_page( BZCALO_SLUG, 'Cài đặt',     '⚙️ Cài đặt',     'manage_options',  BZCALO_SLUG . '-settings',  'bzcalo_page_settings' );
}
