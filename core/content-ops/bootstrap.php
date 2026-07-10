<?php
/**
 * BizCity Content Ops — Module bootstrap
 *
 * Layer 2 of BizCity stack. Handles AI content generation, scheduling,
 * cross-channel publishing and analytics over Layer 1 (Channel Gateway).
 *
 * Module ID: core.content-ops
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_CONTENT_OPS_LOADED' ) ) {
	return;
}
define( 'BIZCITY_CONTENT_OPS_LOADED', true );
define( 'BIZCITY_CONTENT_OPS_VERSION', '1.0.0' );
define( 'BIZCITY_CONTENT_OPS_DIR', __DIR__ );

$inc = __DIR__ . '/includes/';

require_once $inc . 'class-schema.php';
require_once $inc . 'class-post-repo.php';
require_once $inc . 'class-asset-repo.php';
require_once $inc . 'class-llm-proxy.php';
require_once $inc . 'class-scheduler.php';
require_once $inc . 'class-cpt-bridge.php';
require_once $inc . 'class-channel-readiness.php';
require_once $inc . 'class-rest-api.php';
require_once $inc . 'class-admin-menu-spa.php';

// [2026-06-03 Johnny Chu] CONSOLIDATION-M8 — Sprint diagnostic migrated sang
// canonical probe `content_ops.co1`. Standalone tools.php?page=bizcity-content-ops-sprint-diag
// đã bị retire (DIAGNOSTIC-CONSOLIDATION-PLAN.md §3.2 M8). Probe class self-registers
// qua filter `bizcity_diagnostics_register_probes`; ownership = module này (R-DDV).
if ( defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ) {
	require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-content-ops-co1.php';
}

// Boot subsystems
add_action( 'plugins_loaded', static function () {
	BizCity_Content_Ops_Schema::maybe_install();
}, 20 );

BizCity_Content_REST_API::init();
BizCity_Content_CPT_Bridge::init();
BizCity_Content_Scheduler::init();

if ( is_admin() ) {
	BizCity_Content_Admin_SPA::instance();
}

// Activation hook for schema install on plugin activate.
register_activation_hook(
	dirname( __DIR__, 1 ) . '/bizcity-twin-ai.php',
	array( 'BizCity_Content_Ops_Schema', 'install' )
);
