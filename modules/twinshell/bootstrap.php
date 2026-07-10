<?php
/**
 * Bizcity Twin AI — Twin Shell module bootstrap.
 *
 * Phase 0.11 — universal Activity-Bar shell at /twin/ that wraps every
 * registered plugin in an <iframe>, syncs URL state both ways, and exposes
 * a single canonical entry point so plugin pages don't need to ship their
 * own ActivityBar copy.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 * @since 0.11.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_TWIN_SHELL_DIR' ) ) {
	define( 'BIZCITY_TWIN_SHELL_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWIN_SHELL_URL' ) ) {
	define( 'BIZCITY_TWIN_SHELL_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWIN_SHELL_VERSION' ) ) {
	define( 'BIZCITY_TWIN_SHELL_VERSION', '0.13.38' );
}

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — bootstrap idempotency guard
// to avoid duplicate hook registration if this file is loaded from multiple paths.
if ( defined( 'BIZCITY_TWIN_SHELL_BOOTSTRAPPED' ) ) {
	return;
}
define( 'BIZCITY_TWIN_SHELL_BOOTSTRAPPED', 1 );

require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-registry.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-page.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-rest.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-bridge.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-primitives.php';

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — load Learning Hub stack only
// in relevant contexts to reduce baseline bootstrap cost on unrelated requests.
$bz_twinshell_req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
$bz_twinshell_load_learning =
	( defined( 'REST_REQUEST' ) && REST_REQUEST )
	|| ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( strpos( $bz_twinshell_req_uri, '/learning-hub' ) !== false )
	|| ( strpos( $bz_twinshell_req_uri, '/bizcity-twin-shell/v1/learning/' ) !== false );

if ( $bz_twinshell_load_learning ) {
	// Phase 0.7 Wave D + E — Learning Hub SDK, REST proxy, public page.
	require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-learning-sdk.php';
	require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-learning-rest.php';
	require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-learning-page.php';
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-learning-cli.php';
	}
}

// Register default plugins shipped with the bundle.
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/default-plugins.php';

// Public page /twin/ — registers rewrite + render handler.
BizCity_Twin_Shell_Page::instance()->register();

// REST: GET /bizcity-twinchat/v1/shell/plugins.
BizCity_Twin_Shell_REST::instance()->register();

// REST: bizcity-twin-shell/v1/{notebooks,host/bind-notebook,...} (Phase 0.13).
BizCity_Twin_Shell_Primitives::instance()->register();

if ( $bz_twinshell_load_learning ) {
	// Phase 0.7 Wave D — Learning Hub cortex SDK + REST proxy.
	BizCity_Twin_Shell_Learning_SDK::instance()->bind();
	BizCity_Twin_Shell_Learning_REST::instance()->register();

	// Phase 0.7 Wave E — public page /learning-hub/.
	BizCity_Twin_Shell_Learning_Page::instance()->register();
}

// Auto-inject bridge JS into any page whose URL matches a registered plugin slug.
BizCity_Twin_Shell_Bridge::instance()->register();

// [2026-06-09 Johnny Chu] R-CR — migrated to Central Rewrite Flush Registry.
// [2026-06-26 Johnny Chu] R-PERF — removed legacy admin_init guards (2× non-autoloaded
// get_option per admin request). Registry handles version-based flush at admin_init:1.
// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — guard class load order to keep shell fail-open.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'twinshell', BIZCITY_TWIN_SHELL_VERSION );
}
