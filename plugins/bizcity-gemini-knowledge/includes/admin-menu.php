<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'bzgk_register_admin_menu' );
function bzgk_register_admin_menu() {
    add_menu_page(
        'Gemini Knowledge',
        '🧠 Knowledge AI',
        'read',
        BZGK_SLUG,
        'bzgk_page_dashboard',
        'dashicons-welcome-learn-more',
        58
    );
    add_submenu_page( BZGK_SLUG, 'Dashboard',       '📊 Dashboard',       'read',           BZGK_SLUG,                  'bzgk_page_dashboard' );
    add_submenu_page( BZGK_SLUG, 'Hỏi đáp',         '💡 Hỏi Knowledge',   'read',           BZGK_SLUG . '-ask',         'bzgk_page_ask' );
    add_submenu_page( BZGK_SLUG, 'Lịch sử tìm kiếm','📜 Lịch sử',        'read',           BZGK_SLUG . '-history',     'bzgk_page_history' );
    add_submenu_page( BZGK_SLUG, 'Bookmarks',        '🔖 Bookmarks',      'read',           BZGK_SLUG . '-bookmarks',   'bzgk_page_bookmarks' );
    add_submenu_page( BZGK_SLUG, 'Cài đặt',          '⚙️ Cài đặt',        'manage_options', BZGK_SLUG . '-settings',    'bzgk_page_settings' );
}
