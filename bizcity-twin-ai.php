<?php
/**
 * BizCity Twin Brain — Nền tảng AI Companion cá nhân hóa
 * BizCity Twin Brain — Personalized AI Companion Platform
 *
 * @package    Bizcity_Twin_Claw
 * @subpackage Core
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * This file is part of BizCity Twin Brain.
 * Unauthorized copying, modification, or distribution is prohibited.
 * Sao chép, chỉnh sửa hoặc phân phối trái phép bị nghiêm cấm.
 *
 * Plugin Name:       BizCity Twin Brain
 * Plugin URI:        https://bizcity.vn
 * Description:       AI Companion Platform — Personalized AI with Identity, Memory, and Intent. Nền tảng AI đồng hành cá nhân hóa.
 * Version:           1.3.7
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
    define( 'BIZCITY_TWIN_AI_VERSION', '1.3.7' );
}
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION_SOURCE' ) ) {
    // [2026-08-11 Johnny Chu] PHASE-1.23-VERSION-AUTH - identify the main
    // entrypoint when it owns the canonical version definition.
    define( 'BIZCITY_TWIN_AI_VERSION_SOURCE', 'main_constant' );
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

// [2026-09-02 09:20 AM Johnny Chu - Chu Hoàng Anh] B2C-F8 — payment and confirmation pages must not boot optional Twin Brain helpers.
$_bizcity_woo_payment_surface = ! empty( $_SERVER['REQUEST_URI'] )
    && ( false !== strpos( (string) $_SERVER['REQUEST_URI'], '/order-pay/' )
        || false !== strpos( (string) $_SERVER['REQUEST_URI'], '/order-received/' )
        || ( isset( $_GET['pay_for_order'], $_GET['key'] ) && 'true' === (string) $_GET['pay_for_order'] && '' !== (string) $_GET['key'] )
        || ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) );
if ( $_bizcity_woo_payment_surface ) {
    return;
}
unset( $_bizcity_woo_payment_surface );

// [2026-08-07 Johnny Chu] R-PERF - the admin TwinChat wrapper only renders an iframe; defer runtime preloads to the iframe/REST request.
$_bizcity_twinchat_admin_shell_request = is_admin()
    && isset( $_GET['page'] )
    && sanitize_key( (string) $_GET['page'] ) === 'bizcity-twinchat';

// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — load optional Composer
// dependencies (FPDI/FPDF) used for physical PDF page-window splitting.
$bizcity_composer_autoload = __DIR__ . '/vendor/autoload.php';
// [2026-08-09 Johnny Chu] R-PERF-LOADER-COMPOSER - the TwinChat admin shell
// renders an iframe and does not parse PDF files; keep Composer off this path.
$bizcity_composer_context = ! $_bizcity_twinchat_admin_shell_request
    && (
        ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ( ! empty( $_SERVER['REQUEST_URI'] ) && (
            false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' )
            || false !== strpos( (string) $_SERVER['REQUEST_URI'], '/tool-' )
            || false !== strpos( (string) $_SERVER['REQUEST_URI'], '/doc/' )
            || false !== strpos( (string) $_SERVER['REQUEST_URI'], '/gpt/' )
            || false !== strpos( (string) $_SERVER['REQUEST_URI'], '/twin/' )
        ) )
    );
if ( $bizcity_composer_context && file_exists( $bizcity_composer_autoload ) ) {
    require_once $bizcity_composer_autoload;
}
unset( $bizcity_composer_autoload, $bizcity_composer_context );


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

// [2026-06-09 Johnny Chu] R-CR — Central registries must load BEFORE any module bootstrap
// so ALL modules (including those loaded before core/runtime/bootstrap.php) can call
// ::register() at file-load time. core/runtime/bootstrap.php will call boot() later.
if ( ! class_exists( 'BizCity_Rewrite_Flush_Registry', false ) ) {
    require_once __DIR__ . '/core/runtime/class-rewrite-flush-registry.php';
}
if ( ! class_exists( 'BizCity_Schema_Registry', false ) ) {
    require_once __DIR__ . '/core/runtime/class-schema-registry.php';
}

// Infrastructure
require_once __DIR__ . '/includes/helpers-table-cache.php';
require_once __DIR__ . '/includes/class-module-loader.php';
require_once __DIR__ . '/includes/class-connection-gate.php';
require_once __DIR__ . '/includes/class-admin-support-link.php';
require_once __DIR__ . '/includes/class-admin-menu.php';
require_once __DIR__ . '/includes/class-twin-ai.php';

// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - register only Query Monitor
// extension filters early; collector/output classes load only when QM requests them.
if ( file_exists( __DIR__ . '/core/diagnostics/includes/class-qm-loader-integration.php' ) ) {
    require_once __DIR__ . '/core/diagnostics/includes/class-qm-loader-integration.php';
}

// [2026-08-07 Johnny Chu] R-AUTO-MU — sync the bundled early loader before normal plugin boot after a Git pull.
add_action( 'plugins_loaded', [ 'BizCity_Twin_AI', 'sync_compat_loader' ], -100 );

// PHASE-0.41 L3 — REST_Error trait must load BEFORE any controller that
// `use`s it (research/twinbrain/twinchat-sources). Diagnostics bootstrap
// (loaded later) re-requires it via require_once, so this is idempotent.
if ( file_exists( __DIR__ . '/core/diagnostics/includes/trait-rest-error.php' ) ) {
    require_once __DIR__ . '/core/diagnostics/includes/trait-rest-error.php';
}

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

// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W2 - load the observe-only
// ownership registry before the first core feature claim.
$_bizcity_loader_registry_file = __DIR__ . '/core/runtime/class-loader-ownership-registry.php';
if ( file_exists( $_bizcity_loader_registry_file ) && ! class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
    require_once $_bizcity_loader_registry_file;
}
unset( $_bizcity_loader_registry_file );

// ── Core components — load at file scope (trước khi regular plugins load) ────
// Tool plugins extend BizCity_Intent_Provider ở file scope → class phải tồn tại sớm.
// Market đăng ký plugins_loaded @1 → phải load trước khi hook fires.
// Framework v1 contracts (Phase 0.99.2) — opt-in interfaces + module base class.
require_once __DIR__ . '/core/twin-core/contracts/framework-contracts.php';
// [2026-07-29 Johnny Chu] PHASE-1.21-F — opt-in content contracts for new extensions.
require_once __DIR__ . '/core/twin-core/contracts/content-contracts.php';
// [2026-08-12 Johnny Chu] PHASE-1.26-CONTRACT — opt-in navigation metadata registry; WordPress registration remains central.
require_once __DIR__ . '/core/twin-core/contracts/class-admin-navigation-registry.php';
// Phase 0.99.3 — Module registry (implements `bizcity_register_module` filter).
require_once __DIR__ . '/core/twin-core/contracts/class-module-registry.php';
// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — expose the seven-verb facade before extension plugins load.
if ( file_exists( __DIR__ . '/core/twin-core/contracts/class-twin-plugin-sdk.php' ) ) {
    require_once __DIR__ . '/core/twin-core/contracts/class-twin-plugin-sdk.php';
}
// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — load the lightweight manifest registry and bounded framework facade before extensions boot.
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
    $_bizcity_safe_loader = __DIR__ . '/core/helper/class-bizcity-safe-loader.php';
    if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
        require_once $_bizcity_safe_loader;
    }
    unset( $_bizcity_safe_loader );
}
$_bizcity_framework_contract_files = array(
    __DIR__ . '/core/twin-core/contracts/class-framework-manifest-registry.php',
    __DIR__ . '/core/twin-core/contracts/class-framework-sdk.php',
);
foreach ( $_bizcity_framework_contract_files as $_bizcity_framework_contract_file ) {
    if ( is_file( $_bizcity_framework_contract_file ) && is_readable( $_bizcity_framework_contract_file ) ) {
        if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
            BizCity_Safe_Loader::require_file( $_bizcity_framework_contract_file, 'twin_core.framework_contract' );
        }
    }
}
unset( $_bizcity_framework_contract_files, $_bizcity_framework_contract_file );
// [2026-06-05 Johnny Chu] R-ERROR-UX — core/helper: BizCity_Error_Payload + shared helpers.
// Must load before channel-gateway, automation, agents — so every REST controller
// can call BizCity_Error_Payload::make() without a class_exists() guard.
if ( ! $_bizcity_twinchat_admin_shell_request ) {
    // [2026-09-02 07:45 AM Johnny Chu - Chu Hoàng Anh] R-SAFE-LOADER — prevent a partial helper deployment from turning checkout/REST requests into a fatal.
    $_bizcity_helper_bootstrap = __DIR__ . '/core/helper/bootstrap.php';
    if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( $_bizcity_helper_bootstrap ) && is_readable( $_bizcity_helper_bootstrap ) ) {
        BizCity_Safe_Loader::require_file( $_bizcity_helper_bootstrap, 'core.helper.bootstrap' );
    }
    unset( $_bizcity_helper_bootstrap );
    // [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — make taxonomy-gated event registration available before extension plugins boot.
    $bizcity_event_contract_dir = __DIR__ . '/core/twin-core/event-stream';
    if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
        $bizcity_event_taxonomy = $bizcity_event_contract_dir . '/class-twin-event-taxonomy.php';
        $bizcity_event_registry = $bizcity_event_contract_dir . '/class-twin-event-registry.php';
        if ( is_file( $bizcity_event_taxonomy ) && is_readable( $bizcity_event_taxonomy ) ) {
            BizCity_Safe_Loader::require_file( $bizcity_event_taxonomy, 'twin_core.event_taxonomy' );
        }
        if ( is_file( $bizcity_event_registry ) && is_readable( $bizcity_event_registry ) ) {
            BizCity_Safe_Loader::require_file( $bizcity_event_registry, 'twin_core.event_registry' );
        }
        unset( $bizcity_event_taxonomy, $bizcity_event_registry );
    }
    unset( $bizcity_event_contract_dir );
    // [2026-08-09 Johnny Chu] R-PERF — the LLM client is needed by backend/API and AI surfaces, not plain HTML.
    $bizcity_llm_bootstrap_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    $bizcity_llm_bootstrap_context = is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/wp-json/' )
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/bizhook/' )
        // [2026-08-26 Johnny Chu] HOTFIX-FB-WEBHOOK — load the gateway client for the canonical central Facebook endpoint.
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/facehook/' )
        // [2026-08-13 Johnny Chu] HOTFIX-ZALO-LLM-LOADER — the Zalo Bot rewrite is /zalohook/, so load the gateway client before TwinBrain synthesis.
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/zalohook/' )
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/tool-' )
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/gpt' )
        || false !== strpos( $bizcity_llm_bootstrap_uri, '/twin' );
    if ( $bizcity_llm_bootstrap_context ) {
            if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
                BizCity_Loader_Ownership_Registry::claim( 'llm_client', 'main_plugin', __DIR__ . '/core/bizcity-llm/bootstrap.php', defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '', 'early_loader', 'pre_plugins_loaded' );
            }
        require_once __DIR__ . '/core/bizcity-llm/bootstrap.php';
            if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
                BizCity_Loader_Ownership_Registry::transition( 'llm_client', BizCity_Loader_Ownership_Registry::STATE_CONTRACT_READY, 'main_plugin', 'pre_plugins_loaded' );
            }
    }
    unset( $bizcity_llm_bootstrap_uri, $bizcity_llm_bootstrap_context );
}
// [2026-08-01 Johnny Chu] PHASE-1.23-TABLE-ACTIVITY — lightweight query telemetry must load before context gates so frontend/REST/cron activity is observable.
if ( ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/diagnostics/includes/class-diagnostics-table-activity.php' ) ) {
    require_once __DIR__ . '/core/diagnostics/includes/class-diagnostics-table-activity.php';
}

// [2026-06-29 Johnny Chu] HOTFIX — $_bizcity_admin_ctx MUST be defined BEFORE core/knowledge/bootstrap.php
// because knowledge bootstrap uses it immediately (file-scope) to gate class-chat-gateway.php.
// Previously this was defined at line ~182 (AFTER knowledge loaded) → $__kg_admin_ctx fell back to
// the inline check which excluded /bizhook/ and /bizfbhook/ → BizCity_Chat_Gateway never loaded
// on Facebook webhook requests → CRM AI Replier couldn't apply character context → note=kg-rag-direct.
if ( ! isset( $_bizcity_admin_ctx ) ) {
	$_bizcity_admin_ctx =
		is_admin()
		|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		|| ( defined( 'WP_CLI' ) && WP_CLI )
        || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
		|| (
			! empty( $_SERVER['REQUEST_URI'] )
			&& (
				false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/bizhook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' )
                // [2026-08-26 Johnny Chu] HOTFIX-FB-WEBHOOK — keep the canonical /facehook/ request inside the backend gate.
                || false !== strpos( $_SERVER['REQUEST_URI'], '/facehook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], 'fbhook=1' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
                // [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — public upload-link
                // requests must load the bundled Zalo Bot handler even though the
                // rest of the Zalo Bot plugin remains admin/webhook gated.
                || false !== strpos( $_SERVER['REQUEST_URI'], '/zalo-upload/' )
                // [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — public /flow/ iframe must load core/automation outside wp-admin.
                || preg_match( '#^/flow/?(\?|$)#', $_SERVER['REQUEST_URI'] )
				|| preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/kling-video' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/product-studio' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/canva/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/profile-studio/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/qr-studio/' )
				|| false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'biz_fb_oauth' )
				|| false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'fb_callback=1' )
				// [2026-07-02 Johnny Chu] HOTFIX R-PERF — CF7 old-style submission POSTs to
				// the page URL (not /wp-admin/ajax or /wp-json/). Detect via _wpcf7 POST field
				// so channel-gateway loads and BizCity_CF7_Submissions_Log is available.
				|| ( ! empty( $_POST['_wpcf7'] ) )
			)
		);
}

// [2026-08-09 Johnny Chu] R-PERF — Knowledge is needed by admin/REST/webhook runtime, not plain frontend HTML.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_shell_request ) {
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::claim( 'knowledge', 'main_plugin', __DIR__ . '/core/knowledge/bootstrap.php', defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '', 'early_loader', 'pre_plugins_loaded' );
    }
    require_once __DIR__ . '/core/knowledge/bootstrap.php';
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::transition( 'knowledge', BizCity_Loader_Ownership_Registry::STATE_CONTRACT_READY, 'main_plugin', 'pre_plugins_loaded' );
    }
}

// [2026-08-13 Johnny Chu] PHASE-1.26-MENU — load only the Knowledge admin-menu renderer on the TwinChat shell so the Characters link remains visible without preloading the full Knowledge runtime.
if ( $_bizcity_twinchat_admin_shell_request ) {
    $bizcity_knowledge_admin_menu_file = __DIR__ . '/core/knowledge/includes/class-admin-menu.php';
    if ( file_exists( $bizcity_knowledge_admin_menu_file ) ) {
        require_once $bizcity_knowledge_admin_menu_file;
    }
    unset( $bizcity_knowledge_admin_menu_file );
}

// [2026-07-14 Johnny Chu] PHASE-0.43 — shared local-document search for TwinChat, TwinWeb and future surfaces.
if ( ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/twinsearch/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/twinsearch/bootstrap.php';
}

// [2026-06-11 Johnny Chu] PERF-CRON-FIX — Register ALL custom cron schedule NAMES
// unconditionally (every request, BEFORE the $_bizcity_admin_ctx gate below).
//
// ROOT CAUSE (incident 2026-06-10 19:30-19:36): PERF-1/PERF-2 moved the modules
// that register these interval names (core/automation, core/content-ops, core/cron,
// channel-gateway) BEHIND the $_bizcity_admin_ctx gate. But WP-Cron's
// wp_reschedule_event() runs on EVERY frontend request (the default spawner fires
// before DOING_CRON is set), where the gate is false → the module didn't load →
// the schedule name was missing from wp_get_schedules() → reschedule returned
// WP_Error('invalid_schedule') → the event stayed "due" and re-fired on every
// request → per-blog shard query storm → MySQL 800/800 → cascade.
//
// Defining schedule names is just array additions (cheap, no DB/Redis), so they
// MUST be registered unconditionally. The heavy runners/dispatchers stay gated.
// PHP 7.4 compat: no arrow-fn capture issues, plain array.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! is_array( $schedules ) ) {
		$schedules = array();
	}
	$bizcity_intervals = array(
		'bizcity_automation_minute'       => array( 'interval' => 60,  'display' => 'Every Minute (BizCity Automation)' ),
		'every_minute'                    => array( 'interval' => 60,  'display' => 'Every Minute' ),
		'bizcity_5min'                    => array( 'interval' => 300, 'display' => 'Every 5 Minutes (Scheduler)' ),
		'bizcity_kg_5min'                 => array( 'interval' => 300, 'display' => 'Every 5 Minutes (KG Filestore)' ),
		'bizcity_twinchat_learning_15min' => array( 'interval' => 900, 'display' => 'Every 15 minutes (TwinChat learning sweep)' ),
        // [2026-08-09 Johnny Chu] R-CRON-SCHEDULE-EARLY — names must exist before
        // WP-Cron reschedules events; handlers remain surface-gated elsewhere.
        'bizcity_twinweb_artifact_jobs_minute' => array( 'interval' => 60,  'display' => 'Every Minute (TwinWeb artifact jobs)' ),
        'bizcity_crm_3min'                 => array( 'interval' => 180, 'display' => 'Every 3 Minutes (BizCity CRM SLA)' ),
		// [2026-07-15 Johnny Chu] R-CRON-TIER — tier-based intervals (free 10' / pro 5' / premium 1').
		// Registered unconditionally here so wp_reschedule_event() never hits invalid_schedule
		// on frontend (core/cron is gated behind $_bizcity_admin_ctx). Covers the default minutes;
		// BizCity_Cron_Tier_Settings::register_schedules() adds custom values in admin/cron context.
		'bizcity_tier_1min'               => array( 'interval' => 60,  'display' => 'BizCity Tier — mỗi 1 phút' ),
		'bizcity_tier_5min'               => array( 'interval' => 300, 'display' => 'BizCity Tier — mỗi 5 phút' ),
		'bizcity_tier_10min'              => array( 'interval' => 600, 'display' => 'BizCity Tier — mỗi 10 phút' ),
	);
	foreach ( $bizcity_intervals as $name => $def ) {
		if ( ! isset( $schedules[ $name ] ) ) {
			$schedules[ $name ] = $def;
		}
	}
	return $schedules;
}, 1 );

// [2026-06-09 Johnny Chu] PERF-1 — Define admin/REST/webhook context gate EARLY.
// Must be before core/intent/bootstrap.php because that fires bizcity_intent_register_providers
// at load time (not lazy), triggering provider callbacks like bzcc_get_intent_plans()
// which load 341 KB of template data from Redis on every request.
// PHP 7.4 compat: strpos() instead of str_contains(), no nullsafe.
$_bizcity_admin_ctx =
    is_admin()
    || ( defined( 'DOING_CRON' ) && DOING_CRON )
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
    || (
        ! empty( $_SERVER['REQUEST_URI'] )
        && (
            false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )       // REST API
            || false !== strpos( $_SERVER['REQUEST_URI'], '/bizhook/' )    // Zalo webhook (/bizhook/)
            // [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — FB Messenger webhook arrives at ?fbhook=1
            // (legacy query-string) or /bizfbhook/ (pretty URL rewrite). Without these patterns
            // core/channel-gateway (and thus BizCity_CG_Debug_Logger) would NOT load during FB
            // webhook requests, making the referral → campaign dispatch flow unloggable.
            || false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' ) 
            // [2026-08-26 Johnny Chu] HOTFIX-FB-WEBHOOK — canonical central Facebook webhook path.
            || false !== strpos( $_SERVER['REQUEST_URI'], '/facehook/' )
            || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )   // Facebook pretty webhook
            || false !== strpos( $_SERVER['REQUEST_URI'], 'fbhook=1' )     // Facebook legacy ?fbhook=1
            // [2026-06-09 Johnny Chu] PERF-2 — bizcity agent tool pages so tool plugins still
            // load when their URL is visited directly (rules stored in DB via add_rewrite_rule).
            // /tool-image/, /tool-doc/, /tool-google/, /tool-pagebuilder/, /tool-content-creator/, etc.
            || false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — public upload-link
			// route must pass the admin-context load gate for early_route().
			|| false !== strpos( $_SERVER['REQUEST_URI'], '/zalo-upload/' )
            // [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — public /flow/ iframe must load core/automation outside wp-admin.
            || preg_match( '#^/flow/?(\?|$)#', $_SERVER['REQUEST_URI'] )
            // [2026-06-22 Johnny Chu] PHASE-TWINWEB — /doc/ alias for Doc Studio (twinweb shortcut)
            || preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
            || false !== strpos( $_SERVER['REQUEST_URI'], '/kling-video' )    // bizcity-video-kling
            || false !== strpos( $_SERVER['REQUEST_URI'], '/product-studio' ) // tool-image product studio
            || false !== strpos( $_SERVER['REQUEST_URI'], '/canva/' )        // tool-image Canva studio
            || false !== strpos( $_SERVER['REQUEST_URI'], '/profile-studio/' ) // tool-image profile studio
            || false !== strpos( $_SERVER['REQUEST_URI'], '/qr-studio/' )     // tool-image QR studio
            // [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — load MCP discovery and browser consent on normal frontend requests.
            || false !== strpos( $_SERVER['REQUEST_URI'], '/.well-known/oauth-' )
            || false !== strpos( $_SERVER['REQUEST_URI'], '/bizcity-mcp/v1/oauth/' )
            // [2026-06-12 Johnny Chu] HOTFIX — Facebook OAuth public landing (?biz_fb_oauth=user_start)
            // hits home_url (frontend), not wp-admin. bizcity-facebook-bot must load so
            // BizCity_Facebook_OAuth::handle_user_start() can wp_redirect to facebook.com.
            || false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'biz_fb_oauth' )
            // [2026-06-12 Johnny Chu] HOTFIX — support legacy callback style ?fb_callback=1
            // so frontend callback requests still load channel-gateway/facebook handlers.
            || false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'fb_callback=1' )
            // [2026-07-02 Johnny Chu] HOTFIX R-PERF — CF7 old-style submission POSTs to
            // the page URL (not /wp-admin/ajax or /wp-json/). Detect via _wpcf7 POST field
            // so channel-gateway loads and BizCity_CF7_Submissions_Log is available.
            || ( ! empty( $_POST['_wpcf7'] ) )
        )
    );

// [2026-08-22 Johnny Chu] PHASE-TBP-6.3 — Profile Care/Public editor needs the owner-scoped Zalo Personal mapping contract.
$_bizcity_zalo_personal_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/(?:gpt|profile|profile-care|profile-public)(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] );

// [2026-08-07 Johnny Chu] R-PERF - the TwinChat admin shell does not need every backend/admin module in its HTML request.
$_bizcity_twinchat_admin_page = is_admin()
    && isset( $_GET['page'] )
    && in_array( sanitize_key( (string) $_GET['page'] ), array( 'bizcity-twinchat', 'bizcity-twinbrain' ), true );

// Intent engine fires do_action('bizcity_intent_register_providers') at load time.
// On unrelated frontend HTML and wp-admin pages this is wasted; only Intent-owned
// surfaces and backend dispatch contexts need the full graph.
// [2026-08-09 Johnny Chu] R-PERF-LOADER-INTENT - the TwinChat admin shell
// renders an iframe and must not preload the full Intent graph; its iframe/REST
// request loads Intent through the normal backend gate when required.
$_bizcity_intent_admin_page = is_admin()
    && isset( $_GET['page'] )
    && ( false !== strpos( sanitize_key( (string) $_GET['page'] ), 'bizcity-intent' )
        || false !== strpos( sanitize_key( (string) $_GET['page'] ), 'bizcity-tool' )
        || false !== strpos( sanitize_key( (string) $_GET['page'] ), 'bizcity-data-browser' )
        // [2026-09-02 09:35 PM Johnny Chu - Chu Hoàng Anh] R-DDV — load Intent registrations on Diagnostics so the unified tool registry can populate before focused probes run.
        || 'bizcity-diagnostics' === sanitize_key( (string) $_GET['page'] ) );
$_bizcity_intent_ajax_request = false;
if ( isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ) {
    $bizcity_intent_ajax_action = sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) );
    $_bizcity_intent_ajax_request = 0 === strpos( $bizcity_intent_ajax_action, 'bizcity_intent' )
        || 0 === strpos( $bizcity_intent_ajax_action, 'bizcity_chat' )
        || 0 === strpos( $bizcity_intent_ajax_action, 'bizcity_webchat' )
        || 0 === strpos( $bizcity_intent_ajax_action, 'bizc_pipeline' )
        || 0 === strpos( $bizcity_intent_ajax_action, 'bizcity_rolling_memory' )
        || 'bizcity_project_move_conv' === $bizcity_intent_ajax_action;
    unset( $bizcity_intent_ajax_action );
}
$_bizcity_intent_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/(?:tools-map|tool-control-panel|tool-stats|tasks|chat-sessions)(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] );
$_bizcity_intent_runtime_request = $_bizcity_intent_public_request
    || ( $_bizcity_admin_ctx && ( ! is_admin()
        || $_bizcity_intent_admin_page
        || $_bizcity_intent_ajax_request
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI ) ) );
if ( $_bizcity_intent_runtime_request && ! $_bizcity_twinchat_admin_shell_request ) {
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::claim( 'intent', 'main_plugin', __DIR__ . '/core/intent/bootstrap.php', defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '', 'early_loader', 'pre_plugins_loaded' );
    }
    require_once __DIR__ . '/core/intent/bootstrap.php';
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::transition( 'intent', BizCity_Loader_Ownership_Registry::STATE_CONTRACT_READY, 'main_plugin', 'pre_plugins_loaded' );
    }
}
unset( $_bizcity_intent_admin_page, $_bizcity_intent_ajax_request, $_bizcity_intent_public_request, $_bizcity_intent_runtime_request );
// Phase 0.18 / Wave 0.18.0 — Persona Provider contract + registry.
// [2026-08-09 Johnny Chu] R-PERF — Guru runtime/bridge is not needed by unrelated frontend HTML.
$_bizcity_persona_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && ( preg_match( '#/gpt(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] )
        || preg_match( '#/twin(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] ) );
if ( ( $_bizcity_admin_ctx || $_bizcity_persona_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/core/persona/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/persona/bootstrap.php';
}

// [2026-09-01 Johnny Chu] CB2.1 — load the side-effect-free Context Bank boundary only on its own surfaces.
$_bizcity_context_bank_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
$_bizcity_context_bank_admin_page = is_admin()
    && isset( $_GET['page'] )
    && 'bizcity-context-bank' === sanitize_key( (string) $_GET['page'] );
$_bizcity_context_bank_runtime_request =
    $_bizcity_context_bank_admin_page
    || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
    || ( is_admin() && isset( $_GET['page'] ) && 'bizcity-diagnostics' === sanitize_key( (string) $_GET['page'] ) )
    || false !== strpos( $_bizcity_context_bank_uri, '/wp-json/bizcity-context/' );
if ( $_bizcity_context_bank_runtime_request
    && ! $_bizcity_twinchat_admin_shell_request
    && class_exists( 'BizCity_Safe_Loader', false ) ) {
    $_bizcity_context_bank_bootstrap = __DIR__ . '/core/context-bank/bootstrap.php';
    if ( is_file( $_bizcity_context_bank_bootstrap ) && is_readable( $_bizcity_context_bank_bootstrap ) ) {
        BizCity_Safe_Loader::require_file( $_bizcity_context_bank_bootstrap, 'context_bank.bootstrap' );
    }
    unset( $_bizcity_context_bank_bootstrap );
}
unset( $_bizcity_context_bank_uri, $_bizcity_context_bank_admin_page, $_bizcity_context_bank_runtime_request );

// [2026-09-02 Johnny Chu] PHASE-CB4.3 — load the Context Bank Woo adapter only when WooCommerce owns the lifecycle hook.
if ( function_exists( 'add_action' ) ) {
    add_action( 'woocommerce_init', function () {
        $bootstrap = __DIR__ . '/core/context-bank/bootstrap.php';
        if ( ! class_exists( 'BizCity_Context_Bank_Commerce_Adapter', false )
            && class_exists( 'BizCity_Safe_Loader', false )
            && is_file( $bootstrap )
            && is_readable( $bootstrap ) ) {
            BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.commerce_bootstrap' );
        }
    }, 1 );
}
// [2026-08-09 Johnny Chu] R-PERF — defer Twin Core file graph and schema work off plain frontend HTML.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/twin-core/bootstrap.php' ) ) {
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::claim( 'twin_core', 'main_plugin', __DIR__ . '/core/twin-core/bootstrap.php', defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '', 'early_loader', 'pre_plugins_loaded' );
    }
    require_once __DIR__ . '/core/twin-core/bootstrap.php';
    if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
        BizCity_Loader_Ownership_Registry::transition( 'twin_core', BizCity_Loader_Ownership_Registry::STATE_CONTRACT_READY, 'main_plugin', 'pre_plugins_loaded' );
    }
}
// [2026-08-26 Johnny Chu] PHASE-1.29-MARKET-LITE — load only the Market
// surface requested by WordPress: plugins.php management or Marketplace.
// Other requests keep the full marketplace schema/catalog/cron disabled.
$_bizcity_market_admin_surface = is_admin()
    && (
        ( isset( $_SERVER['SCRIPT_NAME'] ) && false !== strpos( (string) $_SERVER['SCRIPT_NAME'], '/plugins.php' ) )
        || ( isset( $_GET['page'] ) && 'bizcity-marketplace' === sanitize_key( (string) $_GET['page'] ) )
    );
if ( $_bizcity_market_admin_surface && class_exists( 'BizCity_Safe_Loader', false ) ) {
    $_bizcity_market_bootstrap = __DIR__ . '/core/bizcity-market/bootstrap.php';
    if ( is_file( $_bizcity_market_bootstrap ) && is_readable( $_bizcity_market_bootstrap ) ) {
        BizCity_Safe_Loader::require_file( $_bizcity_market_bootstrap, 'market.bootstrap' );
    }
    unset( $_bizcity_market_bootstrap );
}
unset( $_bizcity_market_admin_surface );
// [2026-06-12 Johnny Chu] HOTFIX — FB Chat Widget injector must fire on EVERY frontend request
// (wp_footer hook) even when the full channel-gateway is gated. Load the single lightweight
// class unconditionally here so the widget injects before </body> on all public pages.
$_bzc_widget_file = __DIR__ . '/core/channel-gateway/includes/class-fb-chat-widget.php';
if ( file_exists( $_bzc_widget_file ) && ! class_exists( 'BizCity_FB_Chat_Widget' ) ) {
    require_once $_bzc_widget_file;
}
unset( $_bzc_widget_file );

// [2026-06-30 Johnny Chu] HOTFIX — Tracking Codes injector (Meta Pixel, GA4, GTM, TikTok...)
// must fire on EVERY frontend request via wp_head/wp_footer even when channel-gateway is gated.
// Load the single lightweight class unconditionally; the class_exists guard prevents double-load
// when channel-gateway bootstrap already loaded it in admin/REST context.
$_bzc_tracking_file = __DIR__ . '/core/channel-gateway/includes/class-tracking-codes-rest.php';
if ( file_exists( $_bzc_tracking_file ) && ! class_exists( 'BizCity_Tracking_Codes_REST' ) ) {
    require_once $_bzc_tracking_file;
    BizCity_Tracking_Codes_REST::init();
}
unset( $_bzc_tracking_file );

// [2026-06-09 Johnny Chu] PERF-2 — channel-gateway: webhook routing + channel admin UI.
// Not needed on plain frontend HTML renders — twinchat has its own REST routes.
// Still loads on: REST (/wp-json/), /bizhook/ webhooks, wp-admin, cron, WP-CLI, /tool-* pages.
if ( ( $_bizcity_admin_ctx || $_bizcity_zalo_personal_public_request ) && ! $_bizcity_twinchat_admin_page ) {
    require_once __DIR__ . '/core/channel-gateway/bootstrap.php';
}

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP Wave A — Twin Client Brain MCP gateway.
// REST-only (bizcity-mcp/v1/mcp); no frontend HTML footprint, safe to gate
// behind $_bizcity_admin_ctx same as channel-gateway (R-PERF).
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_page && file_exists( __DIR__ . '/core/mcp/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/mcp/bootstrap.php';
}

// [2026-06-09 Johnny Chu] PERF-1 — Admin/cron context gate.
// Modules below are NOT needed on regular frontend page renders (HTML, CSS, JS).
// They only need to load for:
//   a) wp-admin pages (is_admin())
//   b) REST API requests (REQUEST_URI contains /wp-json/)
//   c) WP-Cron execution (DOING_CRON)
//   d) WP-CLI (WP_CLI)
//   e) Channel webhooks (REQUEST_URI contains /bizhook/)
// Skipping on frontend saves ~8-12 MB RAM + ~200-400ms startup per request.
// PHP 7.4 compat: no nullsafe, no union types, no str_contains.
// NOTE: $_bizcity_admin_ctx already defined above (early gate for intent bootstrap).

// Phase AUTOMATION S0 — visual workflow builder (own SPA, own bundle).
// Admin UI + cron runner + REST → gate; not needed on frontend HTML render.
// [2026-08-11 Johnny Chu] PHASE-1.23-AUTOMATION-SURFACE - resolve Automation
// by its own page/REST/public/webhook/cron surface instead of all admin pages.
$_bizcity_automation_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
$_bizcity_automation_page = is_admin()
    && isset( $_GET['page'] )
    && 'bizcity-automation' === sanitize_key( (string) $_GET['page'] );
// [2026-08-16 Johnny Chu] R-DDV — Diagnostics runs automation-dependent probes and must load the Automation contract before probe execution.
$_bizcity_automation_diagnostics_page = is_admin()
    && isset( $_GET['page'] )
    && 'bizcity-diagnostics' === sanitize_key( (string) $_GET['page'] );
$_bizcity_automation_runtime_request =
    $_bizcity_automation_page
    || $_bizcity_automation_diagnostics_page
    || ( defined( 'DOING_CRON' ) && DOING_CRON )
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
    || false !== strpos( $_bizcity_automation_uri, '/wp-json/bizcity-automation/' )
    || preg_match( '#^/flow(?:/|\?|$)#', $_bizcity_automation_uri )
    || false !== strpos( $_bizcity_automation_uri, '/bizhook/' )
    || false !== strpos( $_bizcity_automation_uri, '/bizfbhook' )
    // [2026-08-26 Johnny Chu] HOTFIX-FB-WEBHOOK — load automation dependencies for central Facebook dispatch.
    || false !== strpos( $_bizcity_automation_uri, '/facehook/' )
    || false !== strpos( $_bizcity_automation_uri, '/zalohook/' )
    || false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'fbhook=1' );
if ( $_bizcity_automation_runtime_request
    && ! $_bizcity_twinchat_admin_page
    && file_exists( __DIR__ . '/core/automation/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/automation/bootstrap.php';
}
unset( $_bizcity_automation_uri, $_bizcity_automation_page, $_bizcity_automation_diagnostics_page, $_bizcity_automation_runtime_request );
// [2026-06-22 Johnny Chu] PHASE-TWINWEB — QUARANTINED: core/content-ops chưa sử dụng.
// Uncomment khi sẵn sàng ship Content-Ops SPA (page=bizcity-content-ops).
// Phase CO-1 — Content Ops (Layer 2: AI content + schedule + cross-channel publish)
// if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/content-ops/bootstrap.php' ) ) {
//     require_once __DIR__ . '/core/content-ops/bootstrap.php';
// }
// [2026-06-09 Johnny Chu] R-PERF — skills registers activity bar items for admin and TwinShell surfaces.
$_bizcity_skills_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/(?:skills|twin)(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] );
if ( ( $_bizcity_admin_ctx || $_bizcity_skills_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/core/skills/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/skills/bootstrap.php';
}
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_page && file_exists( __DIR__ . '/core/tools/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/tools/bootstrap.php';
}
// Phase 1 — Unified cron registry & observability (see core/cron/PHASE-CRON.md).
// [2026-06-09 Johnny Chu] PERF-2 — cron registry only needed on admin/REST/cron context.
// Always-load modules (twinchat, knowledge) use wp_schedule_event() directly without BizCity_Cron_Manager.
// WP cron fires via wp-cron.php which sets DOING_CRON=true → included in $_bizcity_admin_ctx.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_page && file_exists( __DIR__ . '/core/cron/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/cron/bootstrap.php';
}
// [2026-06-09 Johnny Chu] R-PERF — scheduler registers activity bar items → must load on all requests.
// [2026-08-09 Johnny Chu] R-PERF — scheduler runtime is needed for admin/REST/cron and its own public page only.
$_bizcity_scheduler_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/scheduler(?:/|\\?|$)#', (string) $_SERVER['REQUEST_URI'] );
if ( ( $_bizcity_admin_ctx || $_bizcity_scheduler_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/core/scheduler/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/scheduler/bootstrap.php';
}
// Phase 0.35 — SMTP bridge (replaces legacy mu-plugin bizcity-smtp-gmail.php).
// No-ops unless BIZCITY_SMTP_* constants in wp-config.php OR option `bizcity_smtp_settings` is set.
if ( ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/smtp/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/smtp/bootstrap.php';
}
// Admin settings page — wp-admin/admin.php?page=bizcity-smtp-settings
if ( is_admin() && file_exists( __DIR__ . '/core/smtp/admin.php' ) ) {
    require_once __DIR__ . '/core/smtp/admin.php';
}
// [2026-08-09 Johnny Chu] R-PERF — memory services are backend/admin/REST/cron runtime, not ordinary frontend HTML.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/memory/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/memory/bootstrap.php';
}
// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M1 — client-side membership plans
// (Free/Pro/Plus). Self-written lean core; PayPal self-billing in later phases.
if ( ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/membership/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/membership/bootstrap.php';
}
// Phase 0.13 / 0.15 — TwinShell Runtime (agents, runner, REST /run endpoint)
// [2026-08-09 Johnny Chu] R-PERF — agent runtime is needed by backend requests and the public TwinShell surface.
$_bizcity_agent_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/twin(?:/|\\?|$)#', (string) $_SERVER['REQUEST_URI'] );
if ( ( $_bizcity_admin_ctx || $_bizcity_agent_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/core/agents/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/agents/bootstrap.php';
}
// [2026-08-09 Johnny Chu] R-PERF — core runtime only serves backend/REST/cron execution.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/core/runtime/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/runtime/bootstrap.php';
}
// Phase 0.16 / Vòng 4 — Intent Shell (foundation only, not yet wired into Intent_Engine)
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_page && file_exists( __DIR__ . '/core/intent/shell/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/intent/shell/bootstrap.php';
}

// Phase 0.18.1 — Guru Research Studio (Tavily ReAct port; multi-scope: character | user)
// REST routes only → admin_ctx (REST gate) is sufficient; not needed on HTML renders.
if ( $_bizcity_admin_ctx && ! $_bizcity_twinchat_admin_page && file_exists( __DIR__ . '/core/research/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/research/bootstrap.php';
}

// Diagnostics (PHASE-0.36) — multisite schema audit + repair + cron hygiene.
// WP-CLI `wp bizcity diag` — only load in admin/CLI context.
if ( ( is_admin()
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/tools/class-diagnostics.php' ) ) {
    require_once __DIR__ . '/tools/class-diagnostics.php';
}

// Diagnostics Core (PHASE-0.40) — table inventory + soft-guard notices + 81 probe classes.
// Heaviest single module (957 KB / 101 files). Never needed on frontend HTML renders.
// [2026-08-09 Johnny Chu] R-PERF — do not load the full diagnostics probe graph on unrelated REST requests.
$_bizcity_diagnostics_ctx =
    is_admin()
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI )
    || ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/bizcity-diagnostics/' ) );
// [2026-08-26 Johnny Chu] R-SAFE-LOADER — Diagnostics entrypoint must degrade safely when its optional bootstrap artifact is absent or unreadable.
if ( $_bizcity_diagnostics_ctx
    && ! $_bizcity_twinchat_admin_page
    && class_exists( 'BizCity_Safe_Loader', false )
    && is_file( __DIR__ . '/core/diagnostics/bootstrap.php' )
    && is_readable( __DIR__ . '/core/diagnostics/bootstrap.php' ) ) {
    BizCity_Safe_Loader::require_file( __DIR__ . '/core/diagnostics/bootstrap.php', 'diagnostics.bootstrap' );
}

// [2026-08-27 Johnny Chu] PHASE-1.31 — load the unified WP-CLI command family through Safe Loader.
if ( defined( 'WP_CLI' ) && WP_CLI
    && class_exists( 'BizCity_Safe_Loader', false )
    && is_file( __DIR__ . '/core/cli/class-bizcity-framework-cli.php' )
    && is_readable( __DIR__ . '/core/cli/class-bizcity-framework-cli.php' ) ) {
    BizCity_Safe_Loader::require_file( __DIR__ . '/core/cli/class-bizcity-framework-cli.php', 'cli.framework_commands' );
}

// Test pages — archived 2026-06-01, moved to tests/_archived/

// ── Modules — feature modules layered on top of core ─────────────────────────
// [2026-08-09 Johnny Chu] R-PERF — TwinChat has a public /twinchat/ route; do not load its full DB/studio/learning graph elsewhere.
$_bizcity_twinchat_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/twinchat(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] );
if ( ( $_bizcity_admin_ctx || $_bizcity_twinchat_public_request )
    && file_exists( __DIR__ . '/modules/twinchat/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinchat/bootstrap.php';
}
// [2026-06-17 Johnny Chu] PHASE-TWINWEB Wave 1 — Public user frontend (ChatGPT-like SPA).
// Always-load: serves /twin/ public page + bizcity-twinweb/v1 REST (needed for guests + WP REST).
if ( ! $_bizcity_twinchat_admin_shell_request && file_exists( __DIR__ . '/modules/twinweb/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinweb/bootstrap.php';
}
// Phase 0.11 — Twin Shell (universal /twin/ ActivityBar wrapper, iframe-based).
// [2026-08-09 Johnny Chu] R-PERF — TwinShell is required for /twin/ and backend requests, not ordinary public pages.
$_bizcity_twinshell_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && preg_match( '#/twin(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] );
if ( ( $_bizcity_admin_ctx || $_bizcity_twinshell_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/modules/twinshell/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinshell/bootstrap.php';
}
// Phase 6.1 — Twinsource (standard source-management panel for all plugins).
// See PHASE-6.1-TWINSOURCE-STANDARD.md
// enqueue() is called explicitly by host pages (not via wp_enqueue_scripts hook)
// → safe to gate: only admin/REST callers need the REST routes.
if ( $_bizcity_admin_ctx
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/modules/twinsource/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinsource/bootstrap.php';
}
// Phase 0.18.1.7 — TwinSearch (Tavily research input gate, retrieval family).
// See PHASE-0-RULE-INPUT-PROVIDER.md + PHASE-0.18.1-GURU-RESEARCH-TAVILY.md
// [2026-08-09 Johnny Chu] R-PERF — TwinSearch is a backend/public Twin GPT dependency, not a generic frontend dependency.
$_bizcity_twinsearch_public_request = ! empty( $_SERVER['REQUEST_URI'] )
    && ( preg_match( '#/gpt(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] )
        || preg_match( '#/twin(?:/|\?|$)#', (string) $_SERVER['REQUEST_URI'] ) );
if ( ( $_bizcity_admin_ctx || $_bizcity_twinsearch_public_request )
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/modules/twinsearch/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinsearch/bootstrap.php';
}

// Phase 0.36 v3 — TwinBrain (Não tổng / Central Brain Orchestrator).
// BE-only orchestrator; UI lives inside TwinChat (mode='brain'). Moved from
// modules/twinbrain/ → core/twinbrain/ on 2026-05-10 (no SPA = no module).
// See PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md
// [2026-06-09 Johnny Chu] PERF-2 — TwinBrain is REST-only (37 files). TwinChat uses
// class_exists() guards for BizCity_TwinBrain_* — safe to skip on frontend HTML renders.
if ( $_bizcity_admin_ctx
    && ! $_bizcity_twinchat_admin_shell_request
    && file_exists( __DIR__ . '/core/twinbrain/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/twinbrain/bootstrap.php';
}

// ── Legacy helpers — flow functions that automation blocks depend on ──────────
// Loaded here so bizcity-twin-ai works standalone (without mu-plugin).
// function_exists() guards inside prevent double-loading when mu-plugin is also active.
if ( ! $_bizcity_twinchat_admin_shell_request ) {
    require_once __DIR__ . '/core/helper-legacy/bootstrap.php';
}

// ── Required bundled runtimes ────────────────────────────────────────────────
// Only framework-owned runtimes are loaded here. Feature extensions must be
// activated manually as normal WordPress plugins.
// Guard bằng constant riêng của mỗi plugin để tránh load trùng khi đã activate bình thường.
$_bizcity_bundled_must_load = [
    'bizcity-admin-hook-zalo'     => 'BIZCITY_ADMIN_ZALO_DIR',     // [2026-07-21 Johnny Chu] R-GW-8 — optional legacy Zalo Hotline adapter if deployed; not required for standalone Zalo Bot/Twin GPT.
    'bizcity-facebook-bot'        => 'BIZCITY_FACEBOOK_BOT_VERSION', // Facebook Messenger + Page webhook (PHASE 0.31 Sprint 6 — moved from mu-plugins)
    'bizgpt-tool-google'          => 'BZGOOGLE_VERSION',           // Google Workspace tools
    // 'bizcity-tool-facebook'       => 'BZTOOL_FB_VERSION',          // ARCHIVED 2026-05-24 → plugins/_archived/. Slug /tool-facebook/ now owned by core/channel-gateway (canonical /channel/).
    'bizcity-zalo-bot'            => 'BIZCITY_ZALO_BOT_VERSION',   // Zalo Bot — CG channel sub-plugin
    // [2026-06-10 Johnny Chu] PHASE-0.39 — Zalo Personal & OA Gateway (ZP.x probes, R-ZONE-2 isolation).
    'bizcity-zalo-personal'       => 'BIZCITY_ZALO_PERSONAL_VERSION', // Zalo Personal + OA channel via zca-bridge sidecar (PHASE-0.39)
    // 'bizcity-companion-notebook'  => 'BCN_VERSION',                // DISABLED — Companion Notebook (gitignored, không load mặc định)
    // 'bizcity-automation'          => 'BIZCITY_AUTOMATION_VERSION', // ARCHIVED 2026-06-01 → plugins/_archived/bizcity-automation/. Replaced by core/automation/ (native xyflow runtime, BE-1..BE-5 shipped).
    'bizcity-doc'                 => 'BZDOC_VERSION',              // Doc Studio — AI tạo Word, PowerPoint, Excel
    // 'bizcity-code'                => 'BZCODE_VERSION',             // Code Builder — AI tạo web & landing page (ARCHIVED)
    // 'bizcity-tool-mindmap'        => 'BZTOOL_MINDMAP_VERSION',     // ARCHIVED 2026-06-01 → plugins/_archived/bizcity-tool-mindmap/. Mindmap functionality moved to bizcity-doc (Phase 6.3 PHASE-0.7-DOCGEN).
    // [2026-06-14 Johnny Chu] HOTFIX — uncommented; foreach guard (is_dir + file_exists) ensures
    // this only loads when the folder is deployed. Gitignored on public repo — safe to list here.
    'bizcity-twin-crm'            => 'BIZCITY_CRM_VERSION',        // PROPRIETARY (PHASE-0.98) — gitignored, commercial-only. Loads when deployed under plugins/bizcity-twin-crm/.
    'bizcoach-pro'                => 'BCPRO_VERSION',              // BizCoach Pro — Producer hub flagship (PHASE-0.36 / R-PROD-HUB) — gitignored, in-house only
    // [2026-08-19 Johnny Chu] HOTFIX — không bundle Video Kling; chỉ menu /gpt/video/ khi plugin được cài và active riêng.
    // 'bizcity-video-kling'         => 'BIZCITY_VIDEO_KLING_VERSION', // B-roll Video — Kling/Sora/Veo3/SeeDance image-to-video via PiAPI
    'bizcity-pagebuilder'         => 'BZPB_VERSION',               // Page Builder — AI tạo website drag-and-drop, 19 block types, export HTML
    // [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — load the Profile module from its physical slug while preserving Personal identifiers.
    'bizcity-profile'             => 'BIZCITY_PERSONAL_VERSION',    // Profile + Personal Assistant — canonical app path /profile/
];
// [2026-06-09 Johnny Chu] PERF-2 — Admin-only bundled plugins (no public shortcodes, no
// public URL patterns outside /tool-* or /kling-video/ covered by $_bizcity_admin_ctx).
// Public shortcode/legacy-route plugins remain always-load: bizcoach-pro,
// bizcity-content-creator. Doc/Image/Page Builder are loaded on their own public
// route, backend requests, or TwinShell only; they do not need full runtime on
// ordinary frontend HTML.
$_bizcity_admin_only_slugs = [
    'bizcity-admin-hook-zalo',  // Optional legacy Zalo Hotline + /bizhook/ webhook + admin.
    'bizcity-facebook-bot',     // FB Messenger webhook + admin
    'bizcity-zalo-bot',         // Zalo Bot webhook + admin
    // [2026-06-10 Johnny Chu] PHASE-0.39 — no public shortcodes; REST at /wp-json/bizcity-channel/v1/zalo-bridge/* covered by admin_ctx gate.
    'bizcity-zalo-personal',    // Zalo Personal + OA gateway — admin + /wp-json/ only
    'bizgpt-tool-google',       // Google Tools — /tool-google/ + admin REST
    'bizcity-doc',              // /tool-doc/ and /doc/ are covered by admin_ctx
    'bizcity-pagebuilder',      // /tool-pagebuilder/ is covered by admin_ctx
];
foreach ( $_bizcity_bundled_must_load as $_slug => $_guard_const ) {
    if ( defined( $_guard_const ) ) {
        continue; // Already loaded (activated as regular plugin or by mu-plugin)
    }
    if ( $_bizcity_twinchat_admin_shell_request ) {
        continue;
    }
    // [2026-06-09 Johnny Chu] PERF-2 — Skip admin-only plugins on plain frontend HTML renders.
    if ( ( ( ! $_bizcity_admin_ctx && ! $_bizcity_agent_public_request && !( 'bizcity-zalo-personal' === $_slug && $_bizcity_zalo_personal_public_request ) ) || $_bizcity_twinchat_admin_page )
        && in_array( $_slug, $_bizcity_admin_only_slugs, true ) ) {
        continue;
    }
    // Guard: only load if plugin folder exists — skip gracefully if not deployed
    $_bundled_dir  = __DIR__ . '/plugins/' . $_slug;
    $_bundled_file = $_bundled_dir . '/' . $_slug . '.php';
    // [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — bizcity-profile keeps the shipped bizcity-personal.php entrypoint for compatibility.
    if ( 'bizcity-profile' === $_slug ) {
        $_bundled_file = $_bundled_dir . '/bizcity-personal.php';
    }
    // [2026-07-26 Johnny Chu] R-GW-8 — cutover fallback: legacy slug
    // `bizcity-admin-hook-zalo` may be deployed under
    // `plugins/bizcity-zalo-bizcity/bizcity-admin-hook-zalo.php`.
    if ( $_slug === 'bizcity-admin-hook-zalo' && ! file_exists( $_bundled_file ) ) {
        $_bundled_dir  = __DIR__ . '/plugins/bizcity-zalo-bizcity';
        $_bundled_file = $_bundled_dir . '/bizcity-admin-hook-zalo.php';
    }
    // [2026-08-25 Johnny Chu] PHASE-1.24 — skip an incomplete proprietary CRM checkout before its legacy bootstrap can fatal Diagnostics.
    if ( 'bizcity-twin-crm' === $_slug ) {
        $_crm_inbox_access = array(
            $_bundled_dir . '/includes/class-inbox-access.php',
            $_bundled_dir . '/includes/inbox/class-inbox-access.php',
        );
        $_crm_has_inbox_access = false;
        foreach ( $_crm_inbox_access as $_crm_inbox_access_file ) {
            if ( is_readable( $_crm_inbox_access_file ) ) {
                $_crm_has_inbox_access = true;
                break;
            }
        }
        unset( $_crm_inbox_access, $_crm_inbox_access_file );
        if ( ! $_crm_has_inbox_access ) {
            unset( $_crm_has_inbox_access );
            continue;
        }
        unset( $_crm_has_inbox_access );
    }
    // [2026-08-26 Johnny Chu] R-SAFE-LOADER — bundled feature artifacts are
    // optional/deployable and must not turn a partial checkout into a fatal.
    if ( is_dir( $_bundled_dir )
        && is_file( $_bundled_file )
        && is_readable( $_bundled_file )
        && class_exists( 'BizCity_Safe_Loader', false ) ) {
        BizCity_Safe_Loader::require_file( $_bundled_file, 'bundled.' . $_slug );
    }
}
// [2026-08-22 Johnny Chu] PHASE-PROFILE-ROLE-SPLIT — force-load all Profile role routes even when a stale loader already defined its version constant.
$_bizcity_profile_request_path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
$__bizcity_profile_route_match = false;
foreach ( array( 'profile', 'profile-care', 'profile-public' ) as $__bizcity_profile_slug ) {
    if ( trim( $_bizcity_profile_request_path, '/' ) === trim( (string) parse_url( home_url( '/' . $__bizcity_profile_slug . '/' ), PHP_URL_PATH ), '/' ) ) {
        $__bizcity_profile_route_match = true;
        break;
    }
}
if ( $__bizcity_profile_route_match && ! class_exists( 'BizCity_Personal_Page', false ) ) {
    $_bizcity_profile_bootstrap = __DIR__ . '/plugins/bizcity-profile/bizcity-personal.php';
    if ( file_exists( $_bizcity_profile_bootstrap ) ) {
        require_once $_bizcity_profile_bootstrap;
    }
    unset( $_bizcity_profile_bootstrap );
}
unset( $_bizcity_profile_request_path, $__bizcity_profile_route_match, $__bizcity_profile_slug );
// Translations — load Vietnamese (and other) .po files from /languages/
add_action( 'init', function() {
    load_plugin_textdomain( 'bizcity-twin-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

BizCity_Admin_Support_Link::init();
BizCity_Admin_Menu::boot();

// Boot at plugins_loaded priority 0 — load modules + fire loaded action
if ( ! $_bizcity_twinchat_admin_shell_request ) {
    add_action( 'plugins_loaded', [ 'BizCity_Twin_AI', 'boot' ], 0 );
}

unset( $_bizcity_bundled_must_load, $_slug, $_guard_const, $_bundled_dir, $_bundled_file, $_bizcity_admin_ctx, $_bizcity_admin_only_slugs, $_bizcity_zalo_personal_public_request, $_bizcity_twinchat_admin_page, $_bizcity_twinchat_admin_shell_request, $_bizcity_diagnostics_ctx, $_bizcity_scheduler_public_request, $_bizcity_agent_public_request, $_bizcity_skills_public_request, $_bizcity_persona_public_request, $_bizcity_twinchat_public_request, $_bizcity_twinshell_public_request, $_bizcity_twinsearch_public_request );

// Activation hook — install DB tables, set defaults
register_activation_hook( __FILE__, [ 'BizCity_Twin_AI', 'activate' ] );

// Phase 0.7 — deactivation: clear scheduled crons so they don't fire after
// disable (would emit "hook target missing" notices on next reactivation).
register_deactivation_hook( __FILE__, static function () {
    // [2026-08-27 Johnny Chu] PHASE-1.30-DEACTIVATE — remove only approved empty retired tables; ordinary deactivation remains fail-closed.
    if ( class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
        BizCity_Legacy_Table_Policy::deactivate_retired_tables();
    }
	// Wave A learning sweep (per-blog, hourly).
	wp_clear_scheduled_hook( 'bizcity_kg_learning_sweep' );
	// Wave B cleanup engine (weekly Sunday 03:00).
	wp_clear_scheduled_hook( 'bizcity_kg_orphan_cleanup_weekly' );
} );

unset( $_bizcity_twinchat_admin_shell_request );

// ── Compat Loader Check ──────────────────────────────────────────────────────
// Cảnh báo admin nếu bizcity-twin-compat.php chưa được copy vào mu-plugins/.
// Không có file này → Intent providers, Market Catalog, và TouchBar sẽ lỗi.
add_action( 'admin_notices', 'bizcity_twin_ai_notice_compat_loader' );
add_action( 'admin_init',    'bizcity_twin_ai_maybe_copy_compat_loader' );

// ── Changelog Dashboard — archived 2026-06-01, moved to changelog/_archived/ ─

function bizcity_twin_ai_notice_compat_loader(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $compat_status = BizCity_Twin_AI::compat_loader_status();
    $dest = WPMU_PLUGIN_DIR . '/bizcity-twin-compat.php';
    $src  = BIZCITY_TWIN_AI_DIR . 'mu-plugin/bizcity-twin-compat.php';

    // [2026-08-26 Johnny Chu] R-AUTO-MU — version match is the canonical current-state check; source/deployed comments may differ harmlessly.
    if ( ! empty( $compat_status['current'] ) ) {
        return;
    }

    // Missing entirely
    if ( ! file_exists( $dest ) ) {
        $copy_url = wp_nonce_url(
            add_query_arg( 'bizcity_copy_compat', '1', admin_url() ),
            'bizcity_copy_compat'
        );

        echo '<div class="notice notice-error">';
        // [2026-09-02 06:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-BRAND — standardize the product name in loader diagnostics.
        echo '<p><strong>⚠ BizCity Twin Brain:</strong> Missing mu-plugin loader '
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
        echo '<p><strong>🔄 BizCity Twin Brain:</strong> The mu-plugin loader is outdated. '
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

// [2026-06-04 Johnny Chu] HOTFIX — removed temporary debug notice (was always visible for admins).
