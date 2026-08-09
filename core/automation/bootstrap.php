<?php
/**
 * Core Automation — Bootstrap
 *
 * Visual workflow builder built on the WAIC engine + xyflow canvas.
 * Mounts its own React SPA at:
 *   wp-admin/admin.php?page=bizcity-automation
 *
 * This module is intentionally separate from `core/channel-gateway/` to:
 *  - keep the gateway SPA bundle small (xyflow + dagre = ~250 KB extra);
 *  - allow porting xyflow community examples drop-in without conflict;
 *  - follow AUTOMATION-0-CANON v0.3 §7 (one module = one bootstrap +
 *    one frontend + one assets/dist output).
 *
 * S0 POC scope (sprint S0): admin page + canvas with hard-coded nodes.
 * NO REST routes, NO DB, NO save / run pipeline yet.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Automation
 * @since      AUTOMATION S0 (2026-05-28)
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_AUTOMATION_LOADED' ) ) {
	return;
}
define( 'BIZCITY_AUTOMATION_LOADED', true );
define( 'BIZCITY_AUTOMATION_DIR', __DIR__ );
define( 'BIZCITY_AUTOMATION_URL', plugins_url( '', __FILE__ ) );

require_once __DIR__ . '/includes/class-automation-admin-spa.php';
require_once __DIR__ . '/includes/class-automation-installer.php';
require_once __DIR__ . '/includes/class-automation-repo-workflows.php';
// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — expose workflow triggers to TwinBrain conversational routing.
require_once __DIR__ . '/includes/class-automation-workflow-catalog.php';
require_once __DIR__ . '/includes/class-automation-repo-runs.php';
require_once __DIR__ . '/includes/class-automation-repo-templates.php';     // BE-7
require_once __DIR__ . '/includes/class-automation-repo-config-packs.php';  // [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — DataTable config packs.
require_once __DIR__ . '/includes/class-automation-templates-seeder.php';   // BE-7

// BE-2 — Block registry (load BEFORE REST so /blocks route can use it).
require_once __DIR__ . '/includes/blocks/interface-block.php';
require_once __DIR__ . '/includes/blocks/abstract-block.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-manual.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-zalo.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-fb-comment.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-fb-message.php';      // BE-6.D
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-telegram.php';        // BE-6.D
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-twinbrain-intent.php';// BE-6.E
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-twinbrain-turn-completed.php';// BE-7.A
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-twinbrain-tool-decided.php';  // BE-7.A
// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — trigger.skill_intent (skill A/B/C invocation listener).
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-skill-intent.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-cron.php';
require_once __DIR__ . '/includes/blocks/triggers/class-trigger-webhook.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-search-kg.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-reply-zalo.php';
// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — action.reply_zalo_each_day block (independent day messages).
require_once __DIR__ . '/includes/blocks/actions/class-action-reply-zalo-each-day.php';
// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — daily session memory blocks for BTnet/ZaloBot continuity.
require_once __DIR__ . '/includes/blocks/actions/class-action-session-memory-day.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-send-email.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-http.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-db-write.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-log.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-create-crm-event.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-capture-attachment.php';   // BE-7.C
require_once __DIR__ . '/includes/blocks/actions/class-action-set-pending-intent.php';   // BE-7.C
require_once __DIR__ . '/includes/blocks/actions/class-action-consume-attachment.php';   // BE-7.C
// [2026-07-24 Johnny Chu] PHASE-0.46 W2 — direct workflow→notebook bridge action.
require_once __DIR__ . '/includes/blocks/actions/class-action-capture-to-notebook.php';
// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK — mint a public no-login learning console-log link (chain after action.capture_to_notebook).
require_once __DIR__ . '/includes/blocks/actions/class-action-learning-share-link.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-publish-wp-post.php';      // BE-7.C
require_once __DIR__ . '/includes/blocks/actions/class-action-publish-fb-post.php';      // BE-7.C
require_once __DIR__ . '/includes/blocks/actions/class-action-schedule-event.php';       // BE-7.D
// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W1 — action.invoke_skill bridge block.
require_once __DIR__ . '/includes/blocks/actions/class-action-invoke-skill.php';
// [2026-06-07 Johnny Chu] PHASE-0.38.W1.5 — action.create_woo_order block (Order Fulfillment Hub).
require_once __DIR__ . '/includes/blocks/actions/class-action-create-woo-order.php';
// [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — action.notify_discord block (Discord webhook).
require_once __DIR__ . '/includes/blocks/actions/class-action-notify-discord.php';
// [2026-06-18 Johnny Chu] PHASE-ZALOBOT-ASTRO — action.run_astro block (resolve coachee + natal chart)
require_once __DIR__ . '/includes/blocks/actions/class-action-run-astro.php';
// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — action.run_astro_transit block (transit day-by-day)
require_once __DIR__ . '/includes/blocks/actions/class-action-run-astro-transit.php';
// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — action.run_astro_relation_assessment block.
require_once __DIR__ . '/includes/blocks/actions/class-action-run-astro-relation-assessment.php';
// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — action.run_products + action.run_products_solution.
require_once __DIR__ . '/includes/blocks/actions/class-action-run-products.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-run-products-solution.php';
// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — deterministic best-day selector.
require_once __DIR__ . '/includes/blocks/actions/class-action-pick-best-day-for-intent.php';
// [2026-07-04 Johnny Chu] HOTFIX — missing require_once for blocks that existed but were never loaded.
require_once __DIR__ . '/includes/blocks/actions/class-action-trending-research.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-web-research.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-generate-content.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-video-submit.php';
require_once __DIR__ . '/includes/blocks/actions/class-action-reply-fb-message.php';
// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — action.generate_image block
require_once __DIR__ . '/includes/blocks/actions/class-action-generate-image.php';
// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45 — action.edit_image block for Seedream image editing templates.
require_once __DIR__ . '/includes/blocks/actions/class-action-edit-image.php';
require_once __DIR__ . '/includes/blocks/llm/class-llm-compose.php';
require_once __DIR__ . '/includes/blocks/llm/class-llm-mpr-think.php';   // BE-6.E
require_once __DIR__ . '/includes/blocks/logic/class-logic-condition.php';
require_once __DIR__ . '/includes/blocks/class-block-registry.php';

require_once __DIR__ . '/includes/class-automation-rest.php';
require_once __DIR__ . '/includes/class-automation-config-packs-rest.php';  // [2026-07-20 Johnny Chu] PHASE-1-TEMPLATES-AUTOMATION — DataTable REST.
require_once __DIR__ . '/includes/class-automation-runner.php';
require_once __DIR__ . '/includes/class-automation-pending-state.php';   // BE-7.C
require_once __DIR__ . '/includes/class-automation-crm-bridge.php';      // BE-7.D
require_once __DIR__ . '/includes/class-content-artifact-service.php';   // [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — CPT-backed My Content artifact trace.
require_once __DIR__ . '/includes/class-automation-matcher-trace.php';   // PG-S9-fix
require_once __DIR__ . '/includes/class-automation-file-logger.php';     // PG-S9-fix v6 (per-wf JSONL)
require_once __DIR__ . '/includes/class-automation-trigger-matcher.php';
require_once __DIR__ . '/includes/class-automation-listener.php';        // BE-6.B
require_once __DIR__ . '/includes/class-automation-twinbrain-bridge.php';// BE-6.E
require_once __DIR__ . '/includes/class-automation-default-reply.php';   // R-CH-UNI 1.2
require_once __DIR__ . '/includes/class-automation-twin-event-tap.php';  // PG-S3 (Playground MPR pane)
require_once __DIR__ . '/includes/class-automation-skill-bridge.php';    // [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — skill_intent dispatcher
require_once __DIR__ . '/includes/class-workflow-md-compiler.php';       // [2026-06-03 Johnny Chu] WF-AUTO W3 — .workflow.md round-trip compiler
require_once __DIR__ . '/includes/class-automation-community.php';       // [2026-06-03 Johnny Chu] WF-AUTO W7 — Community gallery (GitHub raw fetch)

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — guard bootstrap calls so deploy/version skew cannot kill webhook/cron automation runtime.
if ( class_exists( 'BizCity_Automation_Admin_SPA' ) ) { BizCity_Automation_Admin_SPA::instance(); }
if ( class_exists( 'BizCity_Automation_REST' ) ) { BizCity_Automation_REST::init(); }
if ( class_exists( 'BizCity_Automation_Config_Packs_REST' ) ) { BizCity_Automation_Config_Packs_REST::init(); }
if ( class_exists( 'BizCity_Content_Artifact_Service' ) ) { BizCity_Content_Artifact_Service::init(); }
if ( class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) { BizCity_Automation_Trigger_Matcher::init(); }
if ( class_exists( 'BizCity_Automation_Listener' ) ) { BizCity_Automation_Listener::init(); }
if ( class_exists( 'BizCity_Automation_TwinBrain_Bridge' ) ) { BizCity_Automation_TwinBrain_Bridge::init(); }
if ( class_exists( 'BizCity_Automation_Twin_Event_Tap' ) ) { BizCity_Automation_Twin_Event_Tap::init(); }
if ( class_exists( 'BizCity_Automation_Skill_Bridge' ) ) { BizCity_Automation_Skill_Bridge::init(); }
if ( class_exists( 'BizCity_Automation_File_Logger' ) ) { BizCity_Automation_File_Logger::init(); }

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — public /flow/ iframe route rewrite for subscriber-safe Workflow Library.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'automation-flow', '2026.07.21' );
}

// BE-3 — Runner cron dispatcher (R-CRON-META compliant).
// BE-6.C — also stamp last_tick option for health probe / admin notice.
add_action( BizCity_Automation_Runner::CRON_HOOK, function () {
	update_option( 'bizcity_automation_cron_last_tick', time(), false );
	BizCity_Automation_Runner::instance()->on_cron_dispatch();
}, 5 );

// BE-7.E — Async single-run dispatcher for "Chạy thử" UX (FE realtime SSE).
// REST run_workflow with `async=1` schedules this hook + spawn_cron(); the
// loopback request fires the runner immediately while the FE response has
// already returned `run_id` so EventSource can stream per-node logs live.
add_action( 'bizcity_automation_run_async', function ( $run_id ) {
	if ( ! is_string( $run_id ) || $run_id === '' ) { return; }
	if ( class_exists( 'BizCity_Automation_Runner' ) ) {
		BizCity_Automation_Runner::instance()->execute( $run_id );
	}
}, 10, 1 );
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['bizcity_automation_minute'] ) ) {
		$schedules['bizcity_automation_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Every Minute (BizCity Automation)',
		);
	}
	return $schedules;
} );
add_action( 'init', function () {
	if ( class_exists( 'BizCity_Cron_Manager' ) ) {
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => BizCity_Automation_Runner::CRON_JOB_ID,
			'hook'        => BizCity_Automation_Runner::CRON_HOOK,
			'interval'    => 'bizcity_automation_minute',
			'owner'       => 'core/automation',
			'description' => 'Automation runner — pick queued runs (BE-3)',
			'retention'   => 7,
		) );
	} elseif ( ! wp_next_scheduled( BizCity_Automation_Runner::CRON_HOOK ) ) {
		wp_schedule_event( time() + 30, 'bizcity_automation_minute', BizCity_Automation_Runner::CRON_HOOK );
	}
}, 20 );

// Ensure DB schema exists / built-in templates are seeded — but only when the
// Automation admin screen is actually being viewed, not on every wp-admin page.
// [2026-07-24 Johnny Chu] PHASE-DIAG-PERF — this used to be a blanket `admin_init`
// hook (priority 5/6) that ran BizCity_Automation_Installer::ensure() AND
// BizCity_Automation_Templates_Seeder::maybe_seed() on EVERY wp-admin request for
// EVERY admin, even when automation was never touched. Every other automation
// surface (REST controllers, repo classes, cron tick, trigger matcher) already
// calls ::ensure()/::maybe_seed() lazily right before it needs the tables, so this
// current_screen gate only exists to self-heal when the Automation page itself is
// opened directly (no REST round-trip yet to trigger the lazy path). See
// core/diagnostics/docs/PHASE-DIAG-PERF-AUTO-CREATE-AUDIT.md.
add_action( 'current_screen', function ( $screen ) {
	if ( ! $screen || false === strpos( (string) $screen->id, 'bizcity-automation' ) ) {
		return;
	}
	if ( class_exists( 'BizCity_Automation_Installer' ) ) {
		BizCity_Automation_Installer::ensure();
	}
	if ( class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
		BizCity_Automation_Templates_Seeder::maybe_seed();
	}
}, 1 );

// BE-5 — Detect legacy WAIC plugin still active → admin notice.
// `plugins/bizcity-automation/` (Laravel-style WAIC framework) was deprecated
// 2026-05-29 after native runtime (BE-1..BE-4) shipped. Leaving both active
// causes hook collisions on `bizcity_channel_message_received` and confusing
// dual workflow lists. We do NOT auto-deactivate (sysadmin's call) — only warn.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) { return; }
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$legacy = 'bizcity-automation/bizcity-automation.php';
	if ( ! is_plugin_active( $legacy ) ) { return; }
	echo '<div class="notice notice-warning is-dismissible"><p><strong>BizCity Automation:</strong> Legacy plugin <code>bizcity-automation</code> đang active đồng thời với native runtime (core/automation BE-1..BE-4). Hai bộ workflow song song có thể gây trùng trigger. Khuyến nghị: <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">deactivate plugin cũ</a> sau khi đã migrate workflow.</p></div>';
} );

// BE-6.C — Cron health admin notice (dead > 30 min).
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$last = (int) get_option( 'bizcity_automation_cron_last_tick', 0 );
	if ( $last === 0 ) { return; } // never ticked yet (fresh install) — silent.
	$age  = time() - $last;
	if ( $age < 30 * MINUTE_IN_SECONDS ) { return; }
	$next = wp_next_scheduled( BizCity_Automation_Runner::CRON_HOOK );
	$next_str = $next ? human_time_diff( time(), $next ) : 'KHÔNG còn lịch';
	echo '<div class="notice notice-error"><p><strong>BizCity Automation:</strong> Cron dispatcher không chạy trong <strong>' . esc_html( human_time_diff( $last, time() ) ) . '</strong> qua. Workflow sẽ KHÔNG tự chạy. (lịch tiếp: ' . esc_html( $next_str ) . ') &mdash; kiểm tra DISABLE_WP_CRON / system cron.</p></div>';
} );
