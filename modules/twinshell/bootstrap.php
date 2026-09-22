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
	// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — bump to flush the new /twin/panel/ rewrite rule via the central registry.
	define( 'BIZCITY_TWIN_SHELL_VERSION', '0.13.39' );
}

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — bootstrap idempotency guard
// to avoid duplicate hook registration if this file is loaded from multiple paths.
if ( defined( 'BIZCITY_TWIN_SHELL_BOOTSTRAPPED' ) ) {
	return;
}
define( 'BIZCITY_TWIN_SHELL_BOOTSTRAPPED', 1 );

require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-registry.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-page.php';
// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — canonical link builder
// (Twin Route Contract v1). Depends on `BizCity_Twin_Shell_Page::is_safe_route()`/`shell_url()`,
// loaded just above.
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-route.php';
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-rest.php';
// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — value owner + REST for Appearance (site) and User Preferences (user).
require_once BIZCITY_TWIN_SHELL_DIR . 'includes/class-twin-shell-appearance.php';
BizCity_Twin_Shell_Appearance::boot();
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

// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6 — register TwinShell general/appearance settings with explicit site/user scope.
if ( ! defined( 'BIZCITY_TWIN_SHELL_SETTING_PANEL_REGISTERED' )
	&& class_exists( 'BizCity_Twin_Plugin_SDK' )
	&& class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
	BizCity_Twin_Plugin_SDK::register_ui( array(
		'setting_panel' => array(
			array(
				'contract'        => 'setting-panel-registration',
				'version'         => '1.0.0',
				'id'              => 'core.twinshell.appearance',
				'owner'           => 'modules/twinshell',
				'origin'          => 'module',
				'destination'     => 'settings',
				'group'           => 'appearance',
				'label_key'       => 'settings.appearance.label',
				'description_key' => 'settings.appearance.description',
				'icon'            => 'cil-settings',
				'capability'      => 'manage_options',
				'scope'           => 'site',
				'surface'         => 'admin_shell',
				'renderer'        => array(
					'type'  => 'route',
					'id'    => 'core.twinshell.appearance',
					// [2026-09-16] Distinct in-panel route so the item is deep-linkable (was the shared /setting-panel/settings).
					'route' => '/settings/appearance',
				),
				'availability'    => array(
					'policy'         => 'registered-owner',
					'dependency_ids' => array( 'modules.twinshell' ),
				),
				'position'        => 320,
				'aliases'         => array( 'bizcity-webchat-appearance' ),
			),
			array(
				'contract'        => 'setting-panel-registration',
				'version'         => '1.0.0',
				'id'              => 'core.twinshell.user_preferences',
				'owner'           => 'modules/twinshell',
				'origin'          => 'module',
				'destination'     => 'settings',
				'group'           => 'appearance',
				'label_key'       => 'settings.user_preferences.label',
				'description_key' => 'settings.user_preferences.description',
				'icon'            => 'cil-settings',
				'capability'      => 'read',
				'scope'           => 'user',
				'surface'         => 'admin_shell',
				'renderer'        => array(
					'type'  => 'route',
					'id'    => 'core.twinshell.user_preferences',
					'route' => '/settings/user-preferences',
				),
				'availability'    => array(
					'policy'         => 'registered-owner',
					'dependency_ids' => array( 'modules.twinshell' ),
				),
				'position'        => 330,
			),
		),
	) );
	define( 'BIZCITY_TWIN_SHELL_SETTING_PANEL_REGISTERED', true );
}

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
