<?php
/**
 * BizCity Tarot – Admin Menu Registration
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'bct_register_admin_menu' );
function bct_register_admin_menu(): void {
    add_menu_page(
        'BizCity Tarot',
        'Bói Tarot',
        'manage_options',
        BCT_SLUG,
        'bct_page_dashboard',
        'dashicons-superhero',
        58
    );

    add_submenu_page(
        BCT_SLUG,
        'Quản lý bài Tarot',
        '🃏 Quản lý bài',
        'manage_options',
        BCT_SLUG,
        'bct_page_dashboard'
    );

    add_submenu_page(
        BCT_SLUG,
        'Crawl / Import dữ liệu',
        '🔄 Crawl dữ liệu',
        'manage_options',
        BCT_SLUG . '-crawl',
        'bct_page_crawl'
    );

    add_submenu_page(
        BCT_SLUG,
        'Lịch sử trải bài',
        '📜 Lịch sử',
        'manage_options',
        BCT_SLUG . '-history',
        'bct_page_history'
    );

    add_submenu_page(
        BCT_SLUG,
        'Cài đặt Shortcode',
        '⚙️ Cài đặt',
        'manage_options',
        BCT_SLUG . '-settings',
        'bct_page_settings'
    );
}
