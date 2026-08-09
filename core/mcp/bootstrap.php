<?php
/**
 * core/mcp/bootstrap.php — Twin Client Brain MCP module loader.
 *
 * Exposes the KG-Hub Graph RAG stack to external LLM clients (Claude
 * Desktop, Cursor, or any MCP-compliant host) via a Streamable-HTTP-style
 * REST endpoint at `bizcity-mcp/v1/mcp`. Runs entirely inside this Twin
 * Client — never proxied through bizcity-llm-router (that Hub is the
 * OUTBOUND boundary per R-GW-8; MCP here is an INBOUND boundary, a
 * different direction, no relation to the Hub credential flow).
 *
 * Loaded only in admin/REST/cron context (see $_bizcity_admin_ctx gate in
 * bizcity-twin-ai.php, PERF-2) — MCP has no frontend HTML footprint.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new module bootstrap.
if ( ! defined( 'BIZCITY_MCP_DIR' ) ) {
	define( 'BIZCITY_MCP_DIR', __DIR__ . '/' );
}

// Feature flags are wp-config.php overrideable and provide wave-level rollback;
// key management lives in Channel Gateway and customer My MCP surfaces.
if ( ! defined( 'BIZCITY_MCP_ENABLED' ) ) {
	define( 'BIZCITY_MCP_ENABLED', true );
}
if ( ! defined( 'BIZCITY_MCP_BRAIN_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_BRAIN_TOOLS_ENABLED', true );
}
if ( ! defined( 'BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED', true );
}
if ( ! defined( 'BIZCITY_MCP_RENDER_ENABLED' ) ) {
	define( 'BIZCITY_MCP_RENDER_ENABLED', true );
}
// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — enable the validated Page Action wave by default; wp-config.php can still override this constant before plugin load.
if ( ! defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED', true );
}
// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — enable the validated Business Brain wave by default; wp-config.php can still override this constant before plugin load.
if ( ! defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED', true );
}
// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — enable the validated Content Brain bridge by default; wp-config.php can still override this constant before plugin load.
if ( ! defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED', true );
}
// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — enable validated Content draft create/update Actions by default; publishing remains unavailable.
if ( ! defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED', true );
}
// [2026-07-29 Johnny Chu] PHASE-0.54-MCP — enable the validated Report Brain dataset wave by default; wp-config.php can still override this constant before plugin load.
if ( ! defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED', true );
}
// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — enable the read-only WooCommerce catalog/order/customer bridge by default; wp-config.php can still override this constant before plugin load. Real exposure to MCP clients still requires the admin to opt in per-tool via BizCity_MCP_Tool_Policy (Wave Q), which defaults commerce.* to OFF.
if ( ! defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) ) {
	define( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED', true );
}

if ( ! BIZCITY_MCP_ENABLED ) {
	return;
}

require_once BIZCITY_MCP_DIR . 'includes/class-mcp-error.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-file-logger.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-citation.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-installer.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-auth.php';
// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — load the admin tool allowlist before the tool registry enforces it.
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-tool-policy.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load the shared Action confirmation boundary before Action services.
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-action-confirmation.php';
// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-OAUTH — load OAuth discovery, PKCE consent, and token exchange before transport auth resolves tokens.
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-oauth.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-session-store.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-client-scope-resolver.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-retrieval-snapshot-store.php';
require_once BIZCITY_MCP_DIR . 'includes/class-brain-mcp-service.php';
// [2026-07-28 Johnny Chu] PHASE-0.53-MCP Wave E/F — document context, validation, and renderer handoff service.
require_once BIZCITY_MCP_DIR . 'includes/class-document-mcp-service.php';
require_once BIZCITY_MCP_DIR . 'includes/class-mcp-tool-registry.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load the PageBuilder bridge for opt-in page Action tools.
require_once BIZCITY_MCP_DIR . 'includes/actions/class-page-action-mcp-service.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load read-only business metrics bridge.
require_once BIZCITY_MCP_DIR . 'includes/brain/class-business-mcp-service.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load read-only Content Creator bridge.
require_once BIZCITY_MCP_DIR . 'includes/brain/class-content-brain-mcp-service.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load the create-draft Content Action bridge.
require_once BIZCITY_MCP_DIR . 'includes/actions/class-content-action-mcp-service.php';
// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — load read-only report dataset bridge.
require_once BIZCITY_MCP_DIR . 'includes/brain/class-report-brain-mcp-service.php';
// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — load read-only WooCommerce catalog/order/customer bridge.
require_once BIZCITY_MCP_DIR . 'includes/brain/class-commerce-brain-mcp-service.php';
require_once BIZCITY_MCP_DIR . 'rest/class-mcp-http-controller.php';
require_once BIZCITY_MCP_DIR . 'rest/class-mcp-admin-rest.php';

// R-PERF: dbDelta() only actually runs once per DB_VERSION change (Installer::ensure()
// short-circuits via the stored option compare) — safe to hook on every admin/REST load.
add_action( 'plugins_loaded', array( 'BizCity_MCP_Installer', 'ensure' ), 20 );
// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — bounded cleanup for the MCP audit log (full evidence stays in JSONL).
add_action( 'init', array( 'BizCity_MCP_Installer', 'register_retention_cron' ), 20 );
add_action( BizCity_MCP_Installer::AUDIT_RETENTION_HOOK, array( 'BizCity_MCP_Installer', 'gc_audit_log' ) );

// [2026-07-28 Johnny Chu] HOTFIX P0 — fail-graceful boot: a transient deploy-time parse/load
// failure in one MCP class (e.g. mid-deploy file overwrite) must degrade only the MCP module,
// not cascade into an uncaught fatal that breaks every admin/REST/cron request on the site.
if ( class_exists( 'BizCity_MCP_HTTP_Controller' ) ) {
	BizCity_MCP_HTTP_Controller::init();
} else {
	error_log( '[BizCity_MCP] BizCity_MCP_HTTP_Controller missing after require_once — skipping init (check deploy artifact for a partial/corrupt upload).' );
}
if ( class_exists( 'BizCity_MCP_OAuth' ) ) {
	BizCity_MCP_OAuth::init();
} else {
	error_log( '[BizCity_MCP] BizCity_MCP_OAuth missing after require_once — skipping init (check deploy artifact for a partial/corrupt upload).' );
}
if ( class_exists( 'BizCity_MCP_Admin_REST' ) ) {
	BizCity_MCP_Admin_REST::init();
} else {
	error_log( '[BizCity_MCP] BizCity_MCP_Admin_REST missing after require_once — skipping init (check deploy artifact for a partial/corrupt upload).' );
}
