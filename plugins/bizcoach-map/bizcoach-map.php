<?php 
/*
Plugin Name: Agent chiêm tinh
Description: Trợ lý chiêm tinh dự đoán vận hạn theo sự dịch chuyển của các vì sao từng thời điểm
Version: 0.1.0
Icon Path: /assets/horoscope.png
Role: agent
Credit: 100
Price: 1000000
Cover URI: https://media.bizcity.vn/uploads/sites/1258/2026/03/469509887_607309268386186_7939697289516759569_n.jpg
Template Page: chiem-tinh-profile
Category: astrology, lifestyle, personal
Plan: free
Author: Chu Hoàng Anh
Author URI: https://bizcity.vn
Text Domain: bizcoach-map
*/


if (!defined('ABSPATH')) exit;

/* ----------------------------------------------------
 * CONSTANTS & INCLUDES
 * -------------------------------------------------- */
define('BCCM_DIR', plugin_dir_path(__FILE__));
define('BCCM_URL', plugin_dir_url(__FILE__));
define('BCCM_VERSION', '0.1.0.37');

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

/* ---- lib: helpers, AI generators, coach-type utilities ---- */
require_once BCCM_DIR . 'lib/helpers.php';
require_once BCCM_DIR . 'lib/ai.php';
require_once BCCM_DIR . 'lib/helper-biz.php';
require_once BCCM_DIR . 'lib/helper-tiktok.php';
require_once BCCM_DIR . 'lib/helper-baby.php';
require_once BCCM_DIR . 'lib/ai-baby.php';
require_once BCCM_DIR . 'lib/helper-health.php';
require_once BCCM_DIR . 'lib/helper-career.php';
require_once BCCM_DIR . 'lib/astro-api.php';

/* ---- includes: admin pages, frontend, features ---- */
require_once BCCM_DIR . 'includes/install.php';
require_once BCCM_DIR . 'includes/admin-pages.php';
require_once BCCM_DIR . 'includes/admin-pages-json.php';
require_once BCCM_DIR . 'includes/frontend.php';
require_once BCCM_DIR . 'includes/frontend-astro-form.php';
require_once BCCM_DIR . 'includes/class-nobi-float.php';
require_once BCCM_DIR . 'includes/admin-dashboard.php';
require_once BCCM_DIR . 'includes/admin-self-profile.php';
require_once BCCM_DIR . 'includes/admin-step2-coach-template.php';
require_once BCCM_DIR . 'includes/admin-step3-character.php';
require_once BCCM_DIR . 'includes/admin-step4-success-plan.php';
require_once BCCM_DIR . 'includes/admin-lifemap.php';
require_once BCCM_DIR . 'includes/frontend-profile.php';
require_once BCCM_DIR . 'includes/frontend-astro-landing.php';
require_once BCCM_DIR . 'includes/frontend-natal-chart.php';
require_once BCCM_DIR . 'includes/frontend-progress-panel.php';
require_once BCCM_DIR . 'includes/ajax-coach-map-generator.php';
require_once BCCM_DIR . 'includes/network-admin.php';
require_once BCCM_DIR . 'includes/admin-user-profiles.php';

/* ---- Intent Provider: register Astro/Coaching skills with the AI Agent engine ---- */
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
    require_once BCCM_DIR . 'includes/class-intent-provider.php';
    add_action( 'bizcity_intent_register_providers', function( $registry ) {
        $registry->register( new BizCoach_Intent_Provider() );
    } );
}


/* ----------------------------------------------------
 * AGENT PAGE: /chiem-tinh-profile/
 * Trang hồ sơ chiêm tinh frontend — load trong Touch Bar iframe.
 * Tạo WP Page khi kích hoạt, dùng template_include để load template riêng.
 * -------------------------------------------------- */
add_filter( 'template_include', function( $template ) {
    if ( is_page( 'chiem-tinh-profile' ) ) {
        // Touch Bar iframe → Mobile agent profile with tabs
        if ( ! empty( $_GET['bizcity_iframe'] ) ) {
            $mobile = BCCM_DIR . 'templates/page-agent-mobile.php';
            if ( file_exists( $mobile ) ) return $mobile;
        }
        $custom = BCCM_DIR . 'templates/page-chiem-tinh-profile.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    // Giữ landing page cũ nếu cần
    if ( is_page( 'chiem-tinh-astro' ) ) {
        $custom = BCCM_DIR . 'templates/page-astro-landing.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
} );

/* ── Rewrite rule: /chiem-tinh-agent/ → Mobile agent page (standalone) ── */
add_action( 'init', function() {
    add_rewrite_rule( '^chiem-tinh-agent/?$', 'index.php?bizcity_agent_page=chiem-tinh-agent', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'chiem-tinh-agent' ) {
        include BCCM_DIR . 'templates/page-agent-mobile.php';
        exit;
    }
} );

/* ----------------------------------------------------
 * Ensure profile page exists on current site.
 * register_activation_hook only runs once on the main site for
 * network-activated plugins, so this init check covers sub-sites
 * and handles trashed / deleted pages.
 * -------------------------------------------------- */
add_action( 'init', function () {
    // Run once per site — flag stored as option
    $flag = 'bccm_page_ensured_v1';
    if ( get_option( $flag ) ) {
        return;
    }

    // Check for existing page (including trashed)
    $existing = get_page_by_path( 'chiem-tinh-profile' );

    if ( $existing ) {
        // Restore if trashed
        if ( $existing->post_status === 'trash' ) {
            wp_update_post( [
                'ID'          => $existing->ID,
                'post_status' => 'publish',
            ] );
        }
    } else {
        wp_insert_post( [
            'post_title'   => 'Hồ sơ chiêm tinh',
            'post_name'    => 'chiem-tinh-profile',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- Profile page managed by BizCoach Map plugin -->',
        ] );
    }

    flush_rewrite_rules();
    update_option( $flag, 1 );
}, 20 );

/* ----------------------------------------------------
 * ACTIVATION / REWRITE
 * -------------------------------------------------- */
function bccm_activate(){
  bccm_install_tables();
  bccm_add_rewrite();
  /* life-map endpoint cho WooCommerce my-account */
  add_rewrite_endpoint('life-map', EP_ROOT | EP_PAGES);

  // Create profile page if not exists
  if ( ! get_page_by_path( 'chiem-tinh-profile' ) ) {
      wp_insert_post([
          'post_title'   => 'Hồ sơ chiêm tinh',
          'post_name'    => 'chiem-tinh-profile',
          'post_status'  => 'publish',
          'post_type'    => 'page',
          'post_content' => '<!-- Profile page managed by BizCoach Map plugin -->',
      ]);
  }

  flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'bccm_activate');

function bccm_deactivate(){ flush_rewrite_rules(); }
register_deactivation_hook(__FILE__, 'bccm_deactivate');

/* ----------------------------------------------------
 * Minimal assets
 * -------------------------------------------------- */
function bccm_assets(){
  wp_register_style('bccm-admin', BCCM_URL.'assets/admin.css', [], BCCM_VERSION);
  wp_register_style('bccm-public', BCCM_URL.'assets/public.css', [], BCCM_VERSION);
}
add_action('init','bccm_assets');

/* Auto-enqueue admin CSS on all BizCoach pages */
add_action('admin_enqueue_scripts', function ($hook) {
  if (
    strpos($hook, 'bccm_') !== false ||
    strpos($hook, 'bizcoach') !== false ||
    (isset($_GET['page']) && strpos($_GET['page'], 'bccm_') === 0)
  ) {
    wp_enqueue_style('bccm-admin');
  }
});