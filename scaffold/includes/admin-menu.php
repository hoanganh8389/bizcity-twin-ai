<?php
/**
 * WordPress Admin menu registration.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'bz{prefix}_register_admin_menu' );
function bz{prefix}_register_admin_menu() {
    add_menu_page(
        'BizCity {Name}',
        '🔮 {Name}',
        'manage_options',
        BZ{PREFIX}_SLUG,
        'bz{prefix}_page_dashboard',
        'dashicons-admin-generic',
        58
    );

    add_submenu_page(
        BZ{PREFIX}_SLUG,
        'Dashboard',
        '📊 Dashboard',
        'manage_options',
        BZ{PREFIX}_SLUG,
        'bz{prefix}_page_dashboard'
    );

    add_submenu_page(
        BZ{PREFIX}_SLUG,
        'Quản lý dữ liệu',
        '🗂️ Dữ liệu',
        'manage_options',
        BZ{PREFIX}_SLUG . '-data',
        'bz{prefix}_page_data'
    );

    add_submenu_page(
        BZ{PREFIX}_SLUG,
        'Lịch sử',
        '📜 Lịch sử',
        'manage_options',
        BZ{PREFIX}_SLUG . '-history',
        'bz{prefix}_page_history'
    );

    add_submenu_page(
        BZ{PREFIX}_SLUG,
        'Cài đặt',
        '⚙️ Cài đặt',
        'manage_options',
        BZ{PREFIX}_SLUG . '-settings',
        'bz{prefix}_page_settings'
    );
}
