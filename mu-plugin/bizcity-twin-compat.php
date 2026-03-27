<?php
/**
 * BizCity Twin AI — Early Compatibility Loader
 *
 * Loads bizcity-intent (and bizcity-knowledge) classes at mu-plugin time
 * so regular tool plugins (bizcity-tool-facebook, bizcity-tool-image, etc.)
 * can extend BizCity_Intent_Provider at their file scope — exactly as the
 * old bizcity-intent.php mu-plugin did.
 *
 * This file replaces bizcity-intent.php + bizcity-knowledge.php mu-plugin
 * loaders. Twin-ai's own boot at plugins_loaded @11 detects the classes
 * as already loaded and skips re-loading via class_exists() guards.
 *
 * Load order with this file active:
 *   1. [mu-plugin time] bizcity-twin-compat.php → loads intent bootstrap
 *      → defines BizCity_Intent_Provider + all intent classes
 *      → registers plugins_loaded @5 init hook
 *   2. [regular plugin time] Tool plugins load → extend BizCity_Intent_Provider ✓
 *      → register add_action('bizcity_intent_register_providers', ...) ✓
 *   3. plugins_loaded @5 fires → Engine + Registry init → providers registered ✓
 *   4. plugins_loaded @11 fires → twin-ai boots → class_exists guard → skip ✓
 *
 * @package BizCity_Intent
 * @version 1.0.0
 */
defined( 'ABSPATH' ) || exit;

// Fingerprint — xác nhận file đúng version (bật khi cần debug)
// error_log( '[BizCity Compat] mu-plugin loaded — v2026.0324b — ' . __FILE__ );

// ── Constants + Connection Gate (other components depend on these) ────────────
// BIZCITY_TWIN_AI_VERSION must be defined early so bundled plugins don't show
// "requires Bizcity Twin AI" warnings. Connection Gate is needed by Market.
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    define( 'BIZCITY_TWIN_AI_VERSION', '1.0.1' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
    define( 'BIZCITY_TWIN_AI_DIR', WP_PLUGIN_DIR . '/bizcity-twin-ai/' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_URL' ) ) {
    define( 'BIZCITY_TWIN_AI_URL', plugins_url( '/', WP_PLUGIN_DIR . '/bizcity-twin-ai/bizcity-twin-ai.php' ) );
}

$_bc_gate = WP_PLUGIN_DIR . '/bizcity-twin-ai/includes/class-connection-gate.php';
if ( file_exists( $_bc_gate ) && ! class_exists( 'BizCity_Connection_Gate', false ) ) {
    require_once $_bc_gate;
}
unset( $_bc_gate );

// ── LLM Client (phải load trước — intent + knowledge depend on it) ───────────
$_bc_llm = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/bizcity-llm/bootstrap.php';
if ( file_exists( $_bc_llm ) && ! class_exists( 'BizCity_LLM_Client', false ) ) {
    require_once $_bc_llm;
}
unset( $_bc_llm );

// ── Knowledge ────────────────────────────────────────────────────────────────
$_bc_knowledge = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/bootstrap.php';
if ( file_exists( $_bc_knowledge ) && ! class_exists( 'BizCity_Knowledge', false ) ) {
    require_once $_bc_knowledge;
}
unset( $_bc_knowledge );

// ── Intent ───────────────────────────────────────────────────────────────────
$_bc_intent = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/intent/bootstrap.php';
if ( file_exists( $_bc_intent ) && ! class_exists( 'BizCity_Intent_Engine', false ) ) {
    require_once $_bc_intent;
}
unset( $_bc_intent );

// ── Twin Core (Focus Router + Context Resolver — phải load trước prepare_llm_call) ──
$_bc_twin_core = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/twin-core/bootstrap.php';
if ( file_exists( $_bc_twin_core ) && ! class_exists( 'BizCity_Twin_Context_Resolver', false ) ) {
    require_once $_bc_twin_core;
}
unset( $_bc_twin_core );

// ── Market (plugins_loaded @1 phải đăng ký ở mu-plugin time — nếu load ở @11 thì quá muộn) ──
// BizCity_Market_Catalog::get_agent_plugins_with_headers() cần được init trước khi
// render_dashboard_react() gọi nó để build TouchBar agents.
$_bc_market = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/bizcity-market/bootstrap.php';
if ( file_exists( $_bc_market ) && ! class_exists( 'BizCity_Market_Utils', false ) ) {
    require_once $_bc_market;
}
unset( $_bc_market );

// ── WebChat (BizCity_Intent_Provider phải có trước khi regular plugins load) ─
// Cần thiết vì page-aiagent-home.php dùng BizCity_WebChat_Admin_Dashboard
// và các tool plugins extend BizCity_Intent_Provider ở file scope.
$_bc_webchat = WP_PLUGIN_DIR . '/bizcity-twin-ai/modules/webchat/bootstrap.php';
if ( file_exists( $_bc_webchat ) && ! class_exists( 'BizCity_WebChat_Database', false ) ) {
    require_once $_bc_webchat;
}
unset( $_bc_webchat );

// ── Bundled Agent Plugins — bizcity-twin-ai/plugins/ ─────────────────────────
// Mục tiêu: plugins từ 2 nguồn đều load & hiển thị y hệt nhau:
//   1. wp-content/plugins/{slug}/              ← WordPress default
//   2. wp-content/plugins/bizcity-twin-ai/plugins/{slug}/  ← bundled
//
// Vấn đề: WP core get_plugins() chỉ scan 1 cấp trong WP_PLUGIN_DIR.
// Giải pháp: inject bundled plugins vào MỌI điểm mà WP dùng để biết plugin:
//   ① get_plugins() cache   — để sync_agent_plugins(), marketplace đều thấy
//   ② all_plugins filter    — để plugins.php hiển thị
//   ③ active_plugins KHÔNG cần patch — WP lưu relative path, file_exists() resolve đúng

/**
 * Scan bundled plugins trong bizcity-twin-ai/plugins/
 * @return array [ 'bizcity-twin-ai/plugins/{slug}/{slug}.php' => [ ...plugin_data... ] ]
 */
function bizcity_get_bundled_plugins_data() {
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }

    $cache = array();
    $base  = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/';

    if ( ! is_dir( $base ) ) {
        return $cache;
    }

    $dirs = glob( $base . '*', GLOB_ONLYDIR );

    if ( empty( $dirs ) ) {
        return $cache;
    }

    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    foreach ( $dirs as $plugin_dir ) {
        $slug      = basename( $plugin_dir );
        $main_file = $plugin_dir . '/' . $slug . '.php';
        $rel_path  = 'bizcity-twin-ai/plugins/' . $slug . '/' . $slug . '.php';

        if ( ! file_exists( $main_file ) ) {
            continue;
        }

        $data = get_plugin_data( $main_file, false, false );
        if ( ! empty( $data['Name'] ) ) {
            $cache[ $rel_path ] = $data;
        }
    }

    return $cache;
}

// ① Inject vào get_plugins() cache — ĐÂY LÀ THEN CHỐT
// get_plugins() lưu kết quả vào wp_cache_get('plugins', 'plugins')
// Sau khi WP build cache xong, ta inject bundled plugins vào.
// Dùng 'plugins_loaded' priority 0 (trước mọi thứ khác) để đảm bảo
// bất kỳ code nào gọi get_plugins() đều thấy bundled plugins.
add_action( 'plugins_loaded', function () {
    $bundled = bizcity_get_bundled_plugins_data();
    if ( empty( $bundled ) ) {
        return;
    }

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Gọi get_plugins() để nó build cache nếu chưa có
    $all = get_plugins();

    // Merge bundled plugins vào
    $merged = false;
    foreach ( $bundled as $rel_path => $data ) {
        if ( ! isset( $all[ $rel_path ] ) ) {
            $all[ $rel_path ] = $data;
            $merged = true;
        }
    }

    // Ghi đè cache nếu có thay đổi
    if ( $merged ) {
        wp_cache_set( 'plugins', array( '' => $all ), 'plugins' );
    }
}, 0 );

// ② all_plugins filter — cho plugins.php admin page
add_filter( 'all_plugins', function ( $plugins ) {
    foreach ( bizcity_get_bundled_plugins_data() as $rel_path => $data ) {
        if ( ! isset( $plugins[ $rel_path ] ) ) {
            $plugins[ $rel_path ] = $data;
        }
    }
    return $plugins;
} );
