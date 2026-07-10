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

// ── Dynamic folder detection ─────────────────────────────────────────────────
// [2026-06-03 Johnny Chu] HOTFIX — Cộng đồng pull GitHub zip → folder có thể là
// `bizcity-twin-ai-main`, `bizcity-twin-ai-master`, hoặc tên bất kỳ user đổi.
// Trước đây hard-code `/bizcity-twin-ai/` → fatal `require_once` khi folder ≠.
//
// Cách dò (theo thứ tự ưu tiên — deterministic, không đoán):
//   1. Đọc option `active_plugins` của WP — đã lưu sẵn `<slug>/bizcity-twin-ai.php`
//      khi plugin được activate. Đây là source of truth chuẩn xác.
//   2. Đọc option `bizcity_twin_ai_slug` — cache slug đã dò ở lần trước (cho
//      trường hợp plugin chưa activate hoặc đang ở network).
//   3. Glob scan `WP_PLUGIN_DIR/*/bizcity-twin-ai.php` — fallback cuối cho
//      fresh install. Cache lại vào option để lần sau không phải glob.
if ( ! defined( 'BIZCITY_TWIN_AI_SLUG' ) ) {
    $_bc_slug = '';

    // ① active_plugins — WP đã lưu relative path khi activate.
    $_bc_active = (array) get_option( 'active_plugins', array() );
    // Trên multisite cũng check network-active.
    if ( is_multisite() ) {
        $_bc_network = (array) get_site_option( 'active_sitewide_plugins', array() );
        if ( ! empty( $_bc_network ) ) {
            $_bc_active = array_merge( $_bc_active, array_keys( $_bc_network ) );
        }
    }
    foreach ( $_bc_active as $_bc_rel ) {
        if ( substr( $_bc_rel, -strlen( '/bizcity-twin-ai.php' ) ) === '/bizcity-twin-ai.php' ) {
            $_bc_slug = dirname( $_bc_rel );
            break;
        }
    }

    // ② Cached slug từ lần dò trước.
    if ( $_bc_slug === '' ) {
        $_bc_cached = (string) get_option( 'bizcity_twin_ai_slug', '' );
        if ( $_bc_cached !== '' && file_exists( WP_PLUGIN_DIR . '/' . $_bc_cached . '/bizcity-twin-ai.php' ) ) {
            $_bc_slug = $_bc_cached;
        }
    }

    // ③ Glob fallback (chỉ chạy 1 lần — kết quả được cache xuống option).
    if ( $_bc_slug === '' ) {
        $_bc_glob = glob( WP_PLUGIN_DIR . '/*/bizcity-twin-ai.php' );
        if ( ! empty( $_bc_glob ) ) {
            $_bc_slug = basename( dirname( $_bc_glob[0] ) );
        }
    }

    if ( $_bc_slug === '' ) {
        $_bc_slug = 'bizcity-twin-ai'; // giữ behavior cũ để error message rõ ràng
    }

    // Cache slug để lần sau bỏ qua glob.
    if ( get_option( 'bizcity_twin_ai_slug' ) !== $_bc_slug ) {
        update_option( 'bizcity_twin_ai_slug', $_bc_slug, true );
    }

    define( 'BIZCITY_TWIN_AI_SLUG', $_bc_slug );
    unset( $_bc_slug, $_bc_active, $_bc_network, $_bc_rel, $_bc_cached, $_bc_glob );
}

// ── Suppress WP 6.7+ "translation loading too early" notice ─────────────────
// [2026-06-03 Johnny Chu] HOTFIX — WP 6.7 thêm doing_it_wrong notice khi __()
// được gọi trước hook `init`. Codebase này có 200+ call site __() trải dài
// trong admin pages, class init, intent providers — audit từng cái không khả
// thi và rủi ro phá UI. WordPress vẫn JIT-load translation đúng dù trước
// `init`, chỉ là log noise. Filter này tắt ĐÚNG notice đó, ĐÚNG domain của
// plugin, KHÔNG che các doing_it_wrong khác.
//
// Tham chiếu: WP core ticket #61794 — nhiều plugin lớn (WC, Yoast) dùng
// pattern này trong khi audit dần.
add_filter( 'doing_it_wrong_trigger_error', function ( $trigger, $function_name, $message ) {
    if ( $function_name === '_load_textdomain_just_in_time'
        && is_string( $message )
        && strpos( $message, 'bizcity-twin-ai' ) !== false
    ) {
        return false;
    }
    return $trigger;
}, 10, 3 );

// ── Constants + Connection Gate (other components depend on these) ────────────
// BIZCITY_TWIN_AI_VERSION must be defined early so bundled plugins don't show
// "requires Bizcity Twin AI" warnings. Connection Gate is needed by Market.
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    define( 'BIZCITY_TWIN_AI_VERSION', '1.0.1' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
    define( 'BIZCITY_TWIN_AI_DIR', WP_PLUGIN_DIR . '/' . BIZCITY_TWIN_AI_SLUG . '/' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_URL' ) ) {
    define( 'BIZCITY_TWIN_AI_URL', plugins_url( '/', WP_PLUGIN_DIR . '/' . BIZCITY_TWIN_AI_SLUG . '/bizcity-twin-ai.php' ) );
}

// Twin feature flags must exist even when regular plugin bootstrap has not run yet.
// Without these, runtime logs show RESOLVER_ENABLED=UNDEFINED and behavior can diverge
// between mu-plugin bootstrap and regular plugin bootstrap.
if ( ! defined( 'BIZCITY_TWIN_FOCUS_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_FOCUS_ENABLED', true );
}
if ( ! defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_RESOLVER_ENABLED', true );
}
if ( ! defined( 'BIZCITY_TWIN_SNAPSHOT_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_SNAPSHOT_ENABLED', false );
}
if ( ! defined( 'BIZCITY_SMART_GATEWAY_ENABLED' ) ) {
    define( 'BIZCITY_SMART_GATEWAY_ENABLED', true );
}

// [2026-06-03 Johnny Chu] HOTFIX — dùng BIZCITY_TWIN_AI_DIR đã dò động.
$_bc_gate = BIZCITY_TWIN_AI_DIR . 'includes/class-connection-gate.php';
if ( file_exists( $_bc_gate ) && ! class_exists( 'BizCity_Connection_Gate', false ) ) {
    require_once $_bc_gate;
}
unset( $_bc_gate );

// ── LLM Client (phải load trước — intent + knowledge depend on it) ───────────
$_bc_llm = BIZCITY_TWIN_AI_DIR . 'core/bizcity-llm/bootstrap.php';
if ( file_exists( $_bc_llm ) && ! class_exists( 'BizCity_LLM_Client', false ) ) {
    require_once $_bc_llm;
}
unset( $_bc_llm );

// ── Knowledge ────────────────────────────────────────────────────────────────
// [2026-06-29 Johnny Chu] HOTFIX — Define $_bizcity_admin_ctx HERE (mu-plugin time)
// BEFORE loading core/knowledge/bootstrap.php so that $_kg_admin_ctx picks it up
// correctly. Without this, knowledge bootstrap falls back to its own gate which
// only checks /wp-json/ — missing /bizfbhook/, /?fbhook=1, /bizhook/, /zalohook/.
// Facebook webhook URL is /?fbhook=1 or /bizfbhook/ → gate was false → Chat_Gateway
// never loaded → system_prompt + quick_faq never injected (MISSING).
if ( ! isset( $_bizcity_admin_ctx ) ) {
    $_bizcity_admin_ctx =
        is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || (
            ! empty( $_SERVER['REQUEST_URI'] )
            && (
                false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/bizhook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
                || preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/kling-video' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/product-studio' )
            )
        )
        || (
            ! empty( $_SERVER['QUERY_STRING'] )
            && (
                false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fbhook=1' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'biz_fb_oauth' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fb_callback=1' )
            )
        );
}
$_bc_knowledge = BIZCITY_TWIN_AI_DIR . 'core/knowledge/bootstrap.php';
if ( file_exists( $_bc_knowledge ) && ! class_exists( 'BizCity_Knowledge', false ) ) {
    require_once $_bc_knowledge;
}
unset( $_bc_knowledge );

// ── Intent ───────────────────────────────────────────────────────────────────
$_bc_intent = BIZCITY_TWIN_AI_DIR . 'core/intent/bootstrap.php';
if ( file_exists( $_bc_intent ) && ! class_exists( 'BizCity_Intent_Engine', false ) ) {
    require_once $_bc_intent;
}
unset( $_bc_intent );

// ── Twin Core (Focus Router + Context Resolver — phải load trước prepare_llm_call) ──
$_bc_twin_core = BIZCITY_TWIN_AI_DIR . 'core/twin-core/bootstrap.php';
if ( file_exists( $_bc_twin_core ) && ! class_exists( 'BizCity_Twin_Context_Resolver', false ) ) {
    require_once $_bc_twin_core;
}
unset( $_bc_twin_core );

// ── Market (plugins_loaded @1 phải đăng ký ở mu-plugin time — nếu load ở @11 thì quá muộn) ──
// BizCity_Market_Catalog::get_agent_plugins_with_headers() cần được init trước khi
// render_dashboard_react() gọi nó để build TouchBar agents.
$_bc_market = BIZCITY_TWIN_AI_DIR . 'core/bizcity-market/bootstrap.php';
if ( file_exists( $_bc_market ) && ! class_exists( 'BizCity_Market_Utils', false ) ) {
    require_once $_bc_market;
}
unset( $_bc_market );

// ── WebChat (BizCity_Intent_Provider phải có trước khi regular plugins load) ─
// Cần thiết vì page-aiagent-home.php dùng BizCity_WebChat_Admin_Dashboard
// và các tool plugins extend BizCity_Intent_Provider ở file scope.
$_bc_webchat = BIZCITY_TWIN_AI_DIR . 'modules/webchat/bootstrap.php';
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
    $base  = BIZCITY_TWIN_AI_DIR . 'plugins/';

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
        $rel_path  = BIZCITY_TWIN_AI_SLUG . '/plugins/' . $slug . '/' . $slug . '.php';

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
