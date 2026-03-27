<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'bzck_register_admin_menu' );
function bzck_register_admin_menu() {
    add_menu_page(
        'ChatGPT Knowledge',
        '🧠 ChatGPT AI',
        'read',
        BZCK_SLUG,
        'bzck_page_dashboard',
        'dashicons-welcome-learn-more',
        59
    );
    add_submenu_page( BZCK_SLUG, 'Dashboard',        '📊 Dashboard',        'read',           BZCK_SLUG,                  'bzck_page_dashboard' );
    add_submenu_page( BZCK_SLUG, 'Hỏi đáp',          '💡 Hỏi Knowledge',    'read',           BZCK_SLUG . '-ask',         'bzck_page_ask' );
    add_submenu_page( BZCK_SLUG, 'Lịch sử tìm kiếm', '📜 Lịch sử',         'read',           BZCK_SLUG . '-history',     'bzck_page_history' );
    add_submenu_page( BZCK_SLUG, 'Bookmarks',         '🔖 Bookmarks',       'read',           BZCK_SLUG . '-bookmarks',   'bzck_page_bookmarks' );
    add_submenu_page( BZCK_SLUG, 'Cài đặt',           '⚙️ Cài đặt',        'manage_options', BZCK_SLUG . '-settings',    'bzck_page_settings' );
}
