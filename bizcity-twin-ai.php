<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * @package    Bizcity_Twin_Claw
 * @subpackage Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * This file is part of Bizcity Twin AI.
 * Unauthorized copying, modification, or distribution is prohibited.
 * Sao chép, chỉnh sửa hoặc phân phối trái phép bị nghiêm cấm.
 *
 * Plugin Name:       Bizcity Twin AI
 * Plugin URI:        https://bizcity.vn
 * Description:       AI Companion Platform — Personalized AI with Identity, Memory, and Intent. Nền tảng AI đồng hành cá nhân hóa.
 * Version:           1.3.2
 * Author:            Johnny Chu (Chu Hoàng Anh)
 * Author URI:        https://bizcity.vn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bizcity-twin-ai
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

// Guard: mu-plugin loader may have already required this file
if ( defined( 'BIZCITY_TWIN_AI_MAIN_LOADED' ) ) return;
define( 'BIZCITY_TWIN_AI_MAIN_LOADED', true );

// Constants — guarded because compat mu-plugin may have defined them early
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    define( 'BIZCITY_TWIN_AI_VERSION', '1.3.2' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
    define( 'BIZCITY_TWIN_AI_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWIN_AI_URL' ) ) {
    define( 'BIZCITY_TWIN_AI_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_DB_PREFIX' ) ) {
    define( 'BIZCITY_DB_PREFIX', 'bizcity_' );
}


// Feature flags — Twin Core (có thể override trong wp-config.php)
if ( ! defined( 'BIZCITY_TWIN_FOCUS_ENABLED' ) )    define( 'BIZCITY_TWIN_FOCUS_ENABLED', true );
if ( ! defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) ) define( 'BIZCITY_TWIN_RESOLVER_ENABLED', true );
if ( ! defined( 'BIZCITY_TWIN_SNAPSHOT_ENABLED' ) )  define( 'BIZCITY_TWIN_SNAPSHOT_ENABLED', false );

// Phase 1.6 — Session Memory Spec (off by default, enable via wp-config.php)
if ( ! defined( 'BIZCITY_SESSION_SPEC_ENABLED' ) )  define( 'BIZCITY_SESSION_SPEC_ENABLED', true );

// Smart Gateway — offload Intent Engine + Twin Core to bizcity-llm-router server
if ( ! defined( 'BIZCITY_SMART_GATEWAY_ENABLED' ) )  define( 'BIZCITY_SMART_GATEWAY_ENABLED', true );

if ( ! defined( 'BIZCITY_INTENT_LOG_PROMPTS' ) )  define( 'BIZCITY_INTENT_LOG_PROMPTS', true );



// PHP 7.4 polyfills — str_starts_with, str_contains, str_ends_with, array_is_list
if ( ! function_exists( 'str_starts_with' ) ) {
    require_once __DIR__ . '/includes/compat-php74.php';
}

// Infrastructure
require_once __DIR__ . '/includes/class-module-loader.php';
require_once __DIR__ . '/includes/class-connection-gate.php';
require_once __DIR__ . '/includes/class-admin-support-link.php';
require_once __DIR__ . '/includes/class-admin-menu.php';
require_once __DIR__ . '/includes/class-twin-ai.php';

/**
 * Safe charset+collate for CREATE TABLE — fixes shard mismatch.
 *
 * On multisite shards $wpdb->get_charset_collate() may return an impossible
 * combination like "DEFAULT CHARACTER SET latin1 COLLATE utf8_general_ci"
 * because charset is inherited from the shard database default while collation
 * comes from the WP config. This helper detects the mismatch and corrects it.
 *
 * @since 1.3.3
 * @return string  e.g. "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 */
if ( ! function_exists( 'bizcity_get_charset_collate' ) ) {
    function bizcity_get_charset_collate(): string {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Detect charset/collation mismatch (e.g. latin1 + utf8_general_ci)
        if ( preg_match( '/CHARACTER\s+SET\s+(\S+)/i', $charset_collate, $cs )
            && preg_match( '/COLLATE\s+(\S+)/i', $charset_collate, $co )
        ) {
            $charset   = strtolower( $cs[1] );
            $collation = strtolower( $co[1] );
            // Mismatch: charset is latin1 but collation expects utf8/utf8mb3/utf8mb4
            if ( $charset === 'latin1' && strpos( $collation, 'utf8' ) !== false ) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        return $charset_collate;
    }
}

// ── Core components — load at file scope (trước khi regular plugins load) ────
// Tool plugins extend BizCity_Intent_Provider ở file scope → class phải tồn tại sớm.
// Market đăng ký plugins_loaded @1 → phải load trước khi hook fires.
require_once __DIR__ . '/core/bizcity-llm/bootstrap.php';
require_once __DIR__ . '/core/knowledge/bootstrap.php';
require_once __DIR__ . '/core/intent/bootstrap.php';
if ( file_exists( __DIR__ . '/core/twin-core/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/twin-core/bootstrap.php';
}
require_once __DIR__ . '/core/bizcity-market/bootstrap.php';
require_once __DIR__ . '/core/channel-gateway/bootstrap.php';
if ( file_exists( __DIR__ . '/core/skills/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/skills/bootstrap.php';
}
if ( file_exists( __DIR__ . '/core/tools/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/tools/bootstrap.php';
}
if ( file_exists( __DIR__ . '/core/scheduler/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/scheduler/bootstrap.php';
}
if ( file_exists( __DIR__ . '/core/memory/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/memory/bootstrap.php';
}

// ── Legacy helpers — flow functions that automation blocks depend on ──────────
// Loaded here so bizcity-twin-ai works standalone (without mu-plugin).
// function_exists() guards inside prevent double-loading when mu-plugin is also active.
require_once __DIR__ . '/core/helper-legacy/bootstrap.php';

// ── Must-load bundled plugins (hoạt động như mu-plugins) ─────────────────────
// Các plugin trong plugins/ được load trực tiếp, KHÔNG cần activate thủ công.
// Tương tự cơ chế must-use: luôn chạy khi bizcity-twin-ai active.
// Guard bằng constant riêng của mỗi plugin để tránh load trùng khi đã activate bình thường.
$_bizcity_bundled_must_load = [
    'bizgpt-tool-google'          => 'BZGOOGLE_VERSION',           // Google Workspace tools
    'bizcity-tool-facebook'       => 'BZTOOL_FB_VERSION',          // Facebook standalone — /bizfbhook/, OAuth, Messenger
    'bizcity-tool-image'          => 'BZTIMG_VERSION',             // Image Studio, templates, editor assets, product/image tools
    'bizcity-zalo-bot'            => 'BIZCITY_ZALO_BOT_VERSION',   // Zalo Bot — webhook, user linker, gateway bridge
    'bizcity-companion-notebook'  => 'BCN_VERSION',                // Companion Notebook — Studio, tool registry, research memory
    'bizcity-content-creator'     => 'BZCC_VERSION',               // Content Creator — template-driven AI content generation
    'bizcity-doc'                 => 'BZDOC_VERSION',              // Doc Studio — AI tạo Word, PowerPoint, Excel
    'bizcity-code'                => 'BZCODE_VERSION',             // Code Builder — AI tạo web & landing page
    'bizcity-tool-mindmap'        => 'BZTOOL_MINDMAP_VERSION',     // Mindmap Tool — AI tạo sơ đồ tư duy
];
foreach ( $_bizcity_bundled_must_load as $_slug => $_guard_const ) {
    if ( defined( $_guard_const ) ) {
        continue; // Already loaded (activated as regular plugin or by mu-plugin)
    }
    // Guard: only load if plugin folder exists — skip gracefully if not deployed
    $_bundled_dir  = __DIR__ . '/plugins/' . $_slug;
    $_bundled_file = $_bundled_dir . '/' . $_slug . '.php';
    if ( is_dir( $_bundled_dir ) && file_exists( $_bundled_file ) ) {
        require_once $_bundled_file;
    }
}
unset( $_bizcity_bundled_must_load, $_slug, $_guard_const, $_bundled_dir, $_bundled_file );

// Translations — load Vietnamese (and other) .po files from /languages/
add_action( 'init', function() {
    load_plugin_textdomain( 'bizcity-twin-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

BizCity_Admin_Support_Link::init();
BizCity_Admin_Menu::boot();

// Boot at plugins_loaded priority 0 — load modules + fire loaded action
add_action( 'plugins_loaded', [ 'BizCity_Twin_AI', 'boot' ], 0 );

// Activation hook — install DB tables, set defaults
register_activation_hook( __FILE__, [ 'BizCity_Twin_AI', 'activate' ] );

// ── Compat Loader Check ──────────────────────────────────────────────────────
// Cảnh báo admin nếu bizcity-twin-compat.php chưa được copy vào mu-plugins/.
// Không có file này → Intent providers, Market Catalog, và TouchBar sẽ lỗi.
add_action( 'admin_notices', 'bizcity_twin_ai_notice_compat_loader' );
add_action( 'admin_init',    'bizcity_twin_ai_maybe_copy_compat_loader' );

// ── Changelog Dashboard — admin only ─────────────────────────────────────────
// URL: wp-admin/admin.php?page=bizcity-changelog
// Per-phase: wp-admin/admin.php?page=bizcity-changelog-1.4 (etc.)
// WP-CLI: wp bizcity changelog [phase_id]
if ( is_admin() && file_exists( __DIR__ . '/changelog/bootstrap.php' ) ) {
    require_once __DIR__ . '/changelog/bootstrap.php';
}

function bizcity_twin_ai_notice_compat_loader(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $dest = WPMU_PLUGIN_DIR . '/bizcity-twin-compat.php';
    $src  = BIZCITY_TWIN_AI_DIR . 'mu-plugin/bizcity-twin-compat.php';

    // Both exist and identical — nothing to show
    if ( file_exists( $dest ) && file_exists( $src ) && md5_file( $src ) === md5_file( $dest ) ) {
        return;
    }

    // Missing entirely
    if ( ! file_exists( $dest ) ) {
        $copy_url = wp_nonce_url(
            add_query_arg( 'bizcity_copy_compat', '1', admin_url() ),
            'bizcity_copy_compat'
        );

        echo '<div class="notice notice-error">';
        echo '<p><strong>⚠ BizCity Twin AI:</strong> Missing mu-plugin loader '
           . '<code>mu-plugins/bizcity-twin-compat.php</code>. '
           . 'Without this file, Intent Providers, Market Catalog and TouchBar will not work.'
           . '<br><small>Thiếu file mu-plugin loader. Không có file này, các tính năng chính sẽ không hoạt động.</small></p>';

        $dest_dir = rtrim( WPMU_PLUGIN_DIR, '/\\' );
        if ( file_exists( $src ) && is_writable( $dest_dir ) ) {
            echo '<p><a href="' . esc_url( $copy_url ) . '" class="button button-primary">'
               . 'Auto-copy to mu-plugins/</a></p>';
        } else {
            echo '<p>Manual copy:<br>'
               . '<code>cp plugins/bizcity-twin-ai/mu-plugin/bizcity-twin-compat.php mu-plugins/bizcity-twin-compat.php</code></p>';
        }
        echo '</div>';
        return;
    }

    // Exists but outdated
    if ( file_exists( $src ) && md5_file( $src ) !== md5_file( $dest ) ) {
        $copy_url = wp_nonce_url(
            add_query_arg( 'bizcity_copy_compat', '1', admin_url() ),
            'bizcity_copy_compat'
        );

        $dest_dir = rtrim( WPMU_PLUGIN_DIR, '/\\' );
        echo '<div class="notice notice-warning">';
        echo '<p><strong>🔄 BizCity Twin AI:</strong> The mu-plugin loader is outdated. '
           . 'Please update to match the current plugin version.'
           . '<br><small>File mu-plugin loader đã cũ. Cần cập nhật cho đồng bộ với phiên bản plugin hiện tại.</small></p>';

        if ( is_writable( $dest_dir ) ) {
            echo '<p><a href="' . esc_url( $copy_url ) . '" class="button button-primary">'
               . 'Update mu-plugin now</a></p>';
        }
        echo '</div>';
    }
}

function bizcity_twin_ai_maybe_copy_compat_loader(): void {
    if ( ! isset( $_GET['bizcity_copy_compat'] ) ) {
        return;
    }
    if ( ! check_admin_referer( 'bizcity_copy_compat' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    BizCity_Twin_AI::sync_compat_loader();

    wp_safe_redirect( add_query_arg( 'bizcity_compat_copied', '1', admin_url() ) );
    exit;
}

// ── Module Debug Notice ──────────────────────────────────────────────────────
// TEMPORARILY always visible for admins — remove after debugging.
add_action( 'admin_notices', 'bizcity_twin_ai_notice_debug_modules' );
function bizcity_twin_ai_notice_debug_modules(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $diag    = BizCity_Twin_AI::get_diag();
    $loaded  = BizCity_Module_Loader::get_all_loaded();

    echo '<div class="notice notice-info" style="padding:10px">';
    echo '<strong>🔍 BizCity Twin AI v' . BIZCITY_TWIN_AI_VERSION . ' — Debug (PHP ' . PHP_VERSION . ')</strong><br>';

    // boot() status
    echo $diag['boot'] ? '✅ boot() ran' : '❌ boot() NOT called';
    echo '<br>';

    // Module load results
    if ( ! empty( $diag['modules'] ) ) {
        echo '<strong>Modules:</strong><br>';
        foreach ( $diag['modules'] as $name => $status ) {
            $icon = ( $status === 'OK' ) ? '✅' : '❌';
            echo "&nbsp;&nbsp;{$icon} <code>{$name}</code>: {$status}<br>";
        }
    }

    // Registered modules (via guard checks)
    $reg_names = array_keys( $loaded );
    echo '<strong>Registered:</strong> ' . ( $reg_names ? implode( ', ', $reg_names ) : 'NONE' ) . '<br>';

    // Errors
    if ( ! empty( $diag['errors'] ) ) {
        echo '<strong style="color:#c62828">Errors:</strong><br>';
        foreach ( $diag['errors'] as $err ) {
            echo "&nbsp;&nbsp;⚠ <code>" . esc_html( $err ) . "</code><br>";
        }
    }

    // Guard checks — show what exists
    echo '<strong>Guard checks:</strong> ';
    $guards = [
        'BizCity_WebChat_Database' => class_exists( 'BizCity_WebChat_Database', false ),
        'BCCM_DIR'                 => defined( 'BCCM_DIR' ),
        'WaicFrame'                => class_exists( 'WaicFrame', false ),
        'BCN_PLUGIN_FILE'          => defined( 'BCN_PLUGIN_FILE' ),
    ];
    $gparts = [];
    foreach ( $guards as $g => $v ) {
        $gparts[] = $g . '=' . ( $v ? 'YES' : 'NO' );
    }
    echo implode( ' | ', $gparts );

    echo '</div>';
}
