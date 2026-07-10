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

// ── Core components — load at file scope (trước khi regular plugins load) ────
// Tool plugins extend BizCity_Intent_Provider ở file scope → class phải tồn tại sớm.
// Market đăng ký plugins_loaded @1 → phải load trước khi hook fires.
// Framework v1 contracts (Phase 0.99.2) — opt-in interfaces + module base class.
require_once __DIR__ . '/core/twin-core/contracts/framework-contracts.php';
// Phase 0.99.3 — Module registry (implements `bizcity_register_module` filter).
require_once __DIR__ . '/core/twin-core/contracts/class-module-registry.php';
// [2026-06-05 Johnny Chu] R-ERROR-UX — core/helper: BizCity_Error_Payload + shared helpers.
// Must load before channel-gateway, automation, agents — so every REST controller
// can call BizCity_Error_Payload::make() without a class_exists() guard.
require_once __DIR__ . '/core/helper/bootstrap.php';
require_once __DIR__ . '/core/bizcity-llm/bootstrap.php';

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
		|| (
			! empty( $_SERVER['REQUEST_URI'] )
			&& (
				false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/bizhook/' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], 'fbhook=1' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
				|| preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/kling-video' )
				|| false !== strpos( $_SERVER['REQUEST_URI'], '/product-studio' )
				|| false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'biz_fb_oauth' )
				|| false !== strpos( (string) ( $_SERVER['QUERY_STRING'] ?? '' ), 'fb_callback=1' )
				// [2026-07-02 Johnny Chu] HOTFIX R-PERF — CF7 old-style submission POSTs to
				// the page URL (not /wp-admin/ajax or /wp-json/). Detect via _wpcf7 POST field
				// so channel-gateway loads and BizCity_CF7_Submissions_Log is available.
				|| ( ! empty( $_POST['_wpcf7'] ) )
			)
		);
}

require_once __DIR__ . '/core/knowledge/bootstrap.php';

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
            || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )   // Facebook pretty webhook
            || false !== strpos( $_SERVER['REQUEST_URI'], 'fbhook=1' )     // Facebook legacy ?fbhook=1
            // [2026-06-09 Johnny Chu] PERF-2 — bizcity agent tool pages so tool plugins still
            // load when their URL is visited directly (rules stored in DB via add_rewrite_rule).
            // /tool-image/, /tool-doc/, /tool-google/, /tool-pagebuilder/, /tool-content-creator/, etc.
            || false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
            // [2026-06-22 Johnny Chu] PHASE-TWINWEB — /doc/ alias for Doc Studio (twinweb shortcut)
            || preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
            || false !== strpos( $_SERVER['REQUEST_URI'], '/kling-video' )    // bizcity-video-kling
            || false !== strpos( $_SERVER['REQUEST_URI'], '/product-studio' ) // tool-image product studio
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

// Intent engine fires do_action('bizcity_intent_register_providers') at load time.
// On plain frontend HTML pages this is wasted (intent only processes on REST/webhook/admin).
// On REST/webhook/admin/cron: $_bizcity_admin_ctx=true → loads normally.
if ( $_bizcity_admin_ctx ) {
    require_once __DIR__ . '/core/intent/bootstrap.php';
}
// Phase 0.18 / Wave 0.18.0 — Persona Provider contract + registry.
if ( file_exists( __DIR__ . '/core/persona/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/persona/bootstrap.php';
}
if ( file_exists( __DIR__ . '/core/twin-core/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/twin-core/bootstrap.php';
}
// [2026-06-10 Johnny Chu] HOTFIX — core/bizcity-market disabled: module not yet implemented,
// creates 5 unused DB tables on every client install (performance + DB clutter).
// Re-enable when marketplace is production-ready.
// require_once __DIR__ . '/core/bizcity-market/bootstrap.php';
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
if ( $_bizcity_admin_ctx ) {
    require_once __DIR__ . '/core/channel-gateway/bootstrap.php';
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
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/automation/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/automation/bootstrap.php';
}
// [2026-06-22 Johnny Chu] PHASE-TWINWEB — QUARANTINED: core/content-ops chưa sử dụng.
// Uncomment khi sẵn sàng ship Content-Ops SPA (page=bizcity-content-ops).
// Phase CO-1 — Content Ops (Layer 2: AI content + schedule + cross-channel publish)
// if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/content-ops/bootstrap.php' ) ) {
//     require_once __DIR__ . '/core/content-ops/bootstrap.php';
// }
// [2026-06-09 Johnny Chu] R-PERF — skills registers activity bar items → must load on all requests.
if ( file_exists( __DIR__ . '/core/skills/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/skills/bootstrap.php';
}
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/tools/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/tools/bootstrap.php';
}
// Phase 1 — Unified cron registry & observability (see core/cron/PHASE-CRON.md).
// [2026-06-09 Johnny Chu] PERF-2 — cron registry only needed on admin/REST/cron context.
// Always-load modules (twinchat, knowledge) use wp_schedule_event() directly without BizCity_Cron_Manager.
// WP cron fires via wp-cron.php which sets DOING_CRON=true → included in $_bizcity_admin_ctx.
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/cron/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/cron/bootstrap.php';
}
// [2026-06-09 Johnny Chu] R-PERF — scheduler registers activity bar items → must load on all requests.
if ( file_exists( __DIR__ . '/core/scheduler/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/scheduler/bootstrap.php';
}
// Phase 0.35 — SMTP bridge (replaces legacy mu-plugin bizcity-smtp-gmail.php).
// No-ops unless BIZCITY_SMTP_* constants in wp-config.php OR option `bizcity_smtp_settings` is set.
if ( file_exists( __DIR__ . '/core/smtp/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/smtp/bootstrap.php';
}
// Admin settings page — wp-admin/admin.php?page=bizcity-smtp-settings
if ( is_admin() && file_exists( __DIR__ . '/core/smtp/admin.php' ) ) {
    require_once __DIR__ . '/core/smtp/admin.php';
}
if ( file_exists( __DIR__ . '/core/memory/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/memory/bootstrap.php';
}
// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M1 — client-side membership plans
// (Free/Pro/Plus). Self-written lean core; PayPal self-billing in later phases.
if ( file_exists( __DIR__ . '/core/membership/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/membership/bootstrap.php';
}
// Phase 0.13 / 0.15 — TwinShell Runtime (agents, runner, REST /run endpoint)
if ( file_exists( __DIR__ . '/core/agents/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/agents/bootstrap.php';
}
if ( file_exists( __DIR__ . '/core/runtime/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/runtime/bootstrap.php';
}
// Phase 0.16 / Vòng 4 — Intent Shell (foundation only, not yet wired into Intent_Engine)
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/intent/shell/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/intent/shell/bootstrap.php';
}

// Phase 0.18.1 — Guru Research Studio (Tavily ReAct port; multi-scope: character | user)
// REST routes only → admin_ctx (REST gate) is sufficient; not needed on HTML renders.
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/research/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/research/bootstrap.php';
}

// Diagnostics (PHASE-0.36) — multisite schema audit + repair + cron hygiene.
// WP-CLI `wp bizcity diag` — only load in admin/CLI context.
if ( ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) && file_exists( __DIR__ . '/tools/class-diagnostics.php' ) ) {
    require_once __DIR__ . '/tools/class-diagnostics.php';
}

// Diagnostics Core (PHASE-0.40) — table inventory + soft-guard notices + 81 probe classes.
// Heaviest single module (957 KB / 101 files). Never needed on frontend HTML renders.
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/diagnostics/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/diagnostics/bootstrap.php';
}

// Test pages — archived 2026-06-01, moved to tests/_archived/

// ── Modules — feature modules layered on top of core ─────────────────────────
if ( file_exists( __DIR__ . '/modules/twinchat/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinchat/bootstrap.php';
}
// [2026-06-17 Johnny Chu] PHASE-TWINWEB Wave 1 — Public user frontend (ChatGPT-like SPA).
// Always-load: serves /twin/ public page + bizcity-twinweb/v1 REST (needed for guests + WP REST).
if ( file_exists( __DIR__ . '/modules/twinweb/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinweb/bootstrap.php';
}
// Phase 0.11 — Twin Shell (universal /twin/ ActivityBar wrapper, iframe-based).
if ( file_exists( __DIR__ . '/modules/twinshell/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinshell/bootstrap.php';
}
// Phase 6.1 — Twinsource (standard source-management panel for all plugins).
// See PHASE-6.1-TWINSOURCE-STANDARD.md
// enqueue() is called explicitly by host pages (not via wp_enqueue_scripts hook)
// → safe to gate: only admin/REST callers need the REST routes.
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/modules/twinsource/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinsource/bootstrap.php';
}
// Phase 0.18.1.7 — TwinSearch (Tavily research input gate, retrieval family).
// See PHASE-0-RULE-INPUT-PROVIDER.md + PHASE-0.18.1-GURU-RESEARCH-TAVILY.md
if ( file_exists( __DIR__ . '/modules/twinsearch/bootstrap.php' ) ) {
    require_once __DIR__ . '/modules/twinsearch/bootstrap.php';
}

// Phase 0.36 v3 — TwinBrain (Não tổng / Central Brain Orchestrator).
// BE-only orchestrator; UI lives inside TwinChat (mode='brain'). Moved from
// modules/twinbrain/ → core/twinbrain/ on 2026-05-10 (no SPA = no module).
// See PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md
// [2026-06-09 Johnny Chu] PERF-2 — TwinBrain is REST-only (37 files). TwinChat uses
// class_exists() guards for BizCity_TwinBrain_* — safe to skip on frontend HTML renders.
if ( $_bizcity_admin_ctx && file_exists( __DIR__ . '/core/twinbrain/bootstrap.php' ) ) {
    require_once __DIR__ . '/core/twinbrain/bootstrap.php';
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
    'bizcity-admin-hook-zalo'     => 'BIZCITY_ADMIN_ZALO_DIR',     // Zalo Hotline (ZNS) adapter + /bizhook/ webhook + admin page (bundled must-load, replaces mu-plugin copy)
    'bizcity-facebook-bot'        => 'BIZCITY_FACEBOOK_BOT_VERSION', // Facebook Messenger + Page webhook (PHASE 0.31 Sprint 6 — moved from mu-plugins)
    'bizgpt-tool-google'          => 'BZGOOGLE_VERSION',           // Google Workspace tools
    // 'bizcity-tool-facebook'       => 'BZTOOL_FB_VERSION',          // ARCHIVED 2026-05-24 → plugins/_archived/. Slug /tool-facebook/ now owned by core/channel-gateway (canonical /channel/).
    'bizcity-tool-image'          => 'BZTIMG_VERSION',             // Image Studio, templates, editor assets, product/image tools
    'bizcity-zalo-bot'            => 'BIZCITY_ZALO_BOT_VERSION',   // Zalo Bot — CG channel sub-plugin
    // [2026-06-10 Johnny Chu] PHASE-0.39 — Zalo Personal & OA Gateway (ZP.x probes, R-ZONE-2 isolation).
    'bizcity-zalo-personal'       => 'BIZCITY_ZALO_PERSONAL_VERSION', // Zalo Personal + OA channel via zca-bridge sidecar (PHASE-0.39)
    // 'bizcity-companion-notebook'  => 'BCN_VERSION',                // DISABLED — Companion Notebook (gitignored, không load mặc định)
    // 'bizcity-automation'          => 'BIZCITY_AUTOMATION_VERSION', // ARCHIVED 2026-06-01 → plugins/_archived/bizcity-automation/. Replaced by core/automation/ (native xyflow runtime, BE-1..BE-5 shipped).
    'bizcity-content-creator'     => 'BZCC_VERSION',               // Content Creator — template-driven AI content generation
    'bizcity-doc'                 => 'BZDOC_VERSION',              // Doc Studio — AI tạo Word, PowerPoint, Excel
    // 'bizcity-code'                => 'BZCODE_VERSION',             // Code Builder — AI tạo web & landing page (ARCHIVED)
    // 'bizcity-tool-mindmap'        => 'BZTOOL_MINDMAP_VERSION',     // ARCHIVED 2026-06-01 → plugins/_archived/bizcity-tool-mindmap/. Mindmap functionality moved to bizcity-doc (Phase 6.3 PHASE-0.7-DOCGEN).
    // [2026-06-14 Johnny Chu] HOTFIX — uncommented; foreach guard (is_dir + file_exists) ensures
    // this only loads when the folder is deployed. Gitignored on public repo — safe to list here.
    'bizcity-twin-crm'            => 'BIZCITY_CRM_VERSION',        // PROPRIETARY (PHASE-0.98) — gitignored, commercial-only. Loads when deployed under plugins/bizcity-twin-crm/.
    'bizcoach-pro'                => 'BCPRO_VERSION',              // BizCoach Pro — Producer hub flagship (PHASE-0.36 / R-PROD-HUB) — gitignored, in-house only
    'bizcity-video-kling'         => 'BIZCITY_VIDEO_KLING_VERSION', // B-roll Video — Kling/Sora/Veo3/SeeDance image-to-video via PiAPI
    'bizcity-pagebuilder'         => 'BZPB_VERSION',               // Page Builder — AI tạo website drag-and-drop, 19 block types, export HTML
    // [2026-06-24 Johnny Chu] PHASE-HOME — Personal Assistant (Trợ lý cá nhân) — scheduler, budget, KG, journal
    'bizcity-personal'            => 'BIZCITY_PERSONAL_VERSION',    // Personal Assistant — calendar, tasks, budget, journal at /personal/
];
// [2026-06-09 Johnny Chu] PERF-2 — Admin-only bundled plugins (no public shortcodes, no
// public URL patterns outside /tool-* or /kling-video/ covered by $_bizcity_admin_ctx).
// NOT listed: bizcoach-pro, bizcity-content-creator, bizcity-doc, bizcity-tool-image,
// bizcity-pagebuilder — these register activity bar items and must load on all requests.
$_bizcity_admin_only_slugs = [
    'bizcity-admin-hook-zalo',  // Zalo Hotline + /bizhook/ webhook + admin
    'bizcity-facebook-bot',     // FB Messenger webhook + admin
    'bizcity-zalo-bot',         // Zalo Bot webhook + admin
    // [2026-06-10 Johnny Chu] PHASE-0.39 — no public shortcodes; REST at /wp-json/bizcity-channel/v1/zalo-bridge/* covered by admin_ctx gate.
    'bizcity-zalo-personal',    // Zalo Personal + OA gateway — admin + /wp-json/ only
    'bizgpt-tool-google',       // Google Tools — /tool-google/ + admin REST
];
foreach ( $_bizcity_bundled_must_load as $_slug => $_guard_const ) {
    if ( defined( $_guard_const ) ) {
        continue; // Already loaded (activated as regular plugin or by mu-plugin)
    }
    // [2026-06-09 Johnny Chu] PERF-2 — Skip admin-only plugins on plain frontend HTML renders.
    if ( ! $_bizcity_admin_ctx && in_array( $_slug, $_bizcity_admin_only_slugs, true ) ) {
        continue;
    }
    // Guard: only load if plugin folder exists — skip gracefully if not deployed
    $_bundled_dir  = __DIR__ . '/plugins/' . $_slug;
    $_bundled_file = $_bundled_dir . '/' . $_slug . '.php';
    if ( is_dir( $_bundled_dir ) && file_exists( $_bundled_file ) ) {
        require_once $_bundled_file;
    }
}
unset( $_bizcity_bundled_must_load, $_slug, $_guard_const, $_bundled_dir, $_bundled_file, $_bizcity_admin_ctx, $_bizcity_admin_only_slugs );

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

// Phase 0.7 — deactivation: clear scheduled crons so they don't fire after
// disable (would emit "hook target missing" notices on next reactivation).
register_deactivation_hook( __FILE__, static function () {
	// Wave A learning sweep (per-blog, hourly).
	wp_clear_scheduled_hook( 'bizcity_kg_learning_sweep' );
	// Wave B cleanup engine (weekly Sunday 03:00).
	wp_clear_scheduled_hook( 'bizcity_kg_orphan_cleanup_weekly' );
} );

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

// [2026-06-04 Johnny Chu] HOTFIX — removed temporary debug notice (was always visible for admins).
