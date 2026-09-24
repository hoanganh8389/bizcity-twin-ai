<?php
/**
 * Bizcity Twin AI — TwinKG Module Bootstrap
 *
 * The Knowledge Graph / Knowledge configuration UI, extracted out of
 * `core/knowledge/kg-hub/ui` so that core carries no React application.
 *
 * Surface:  /twinkg/            (public page, login required)
 *           /twin/?plugin=twinkg (same page inside the Twin Shell)
 *           admin.php?page=bizcity-twinkg (wp-admin wrapper, iframe of the above)
 *
 * Governing documents:
 *   core/knowledge/docs/CORE-REDUCTION-WP-09-TWINKG-MODULE.md   (design, steps T0-T6)
 *   core/knowledge/docs/CORE-REDUCTION-WP-08-HTML-RENDER-TO-REST.md (why)
 *
 * Boundary (proposed rule R-KG-UI, WP-09 §3):
 *   This module is the VIEW. It owns no table, option (beyond its own rewrite
 *   sentinel), cron or REST route, and never calls a KG service in PHP. All data
 *   flows through the REST namespaces owned by `core/knowledge`
 *   (`bizcity-knowledge/v2` for KG-Hub). Server-side PHP callers keep using the
 *   `BizCity_KG` facade — that boundary is unchanged by this module.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinKG
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_TWINKG_DIR' ) ) {
	define( 'BIZCITY_TWINKG_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWINKG_URL' ) ) {
	define( 'BIZCITY_TWINKG_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWINKG_VERSION' ) ) {
	define( 'BIZCITY_TWINKG_VERSION', '0.1.0' );
}
if ( ! defined( 'BIZCITY_TWINKG_INCLUDES' ) ) {
	define( 'BIZCITY_TWINKG_INCLUDES', BIZCITY_TWINKG_DIR . 'includes/' );
}
if ( ! defined( 'BIZCITY_TWINKG_UI_DIR' ) ) {
	define( 'BIZCITY_TWINKG_UI_DIR', BIZCITY_TWINKG_DIR . 'ui/' );
}

// [2026-09-24 Claude Opus 5] R-SAFE-LOADER — bootstrap the canonical loader only through a guarded core artifact path.
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_twinkg_safe_loader = defined( 'BIZCITY_TWIN_AI_DIR' )
		? BIZCITY_TWIN_AI_DIR . 'core/helper/class-bizcity-safe-loader.php'
		: dirname( dirname( __DIR__ ) ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_twinkg_safe_loader ) && is_readable( $_bizcity_twinkg_safe_loader ) ) {
		require_once $_bizcity_twinkg_safe_loader;
	}
	unset( $_bizcity_twinkg_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}

// ── Includes ─────────────────────────────────────────────────────────────────
BizCity_Safe_Loader::require_file( BIZCITY_TWINKG_INCLUDES . 'class-twinkg-bootstrap-data.php', 'twinkg.bootstrap_data' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINKG_INCLUDES . 'class-twinkg-public-page.php',    'twinkg.public_page' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINKG_INCLUDES . 'class-twinkg-admin-menu.php',     'twinkg.admin_menu' );

// ── Public surface — /twinkg/ ────────────────────────────────────────────────
if ( class_exists( 'BizCity_TwinKG_Public_Page' ) ) {
	BizCity_TwinKG_Public_Page::instance()->register();
}

// ── wp-admin wrapper + legacy slug redirects ─────────────────────────────────
if ( is_admin() && class_exists( 'BizCity_TwinKG_Admin_Menu' ) ) {
	// admin_init@0 — before wp-admin emits HTML, so `wp_safe_redirect()` is valid.
	add_action( 'admin_init', array( BizCity_TwinKG_Admin_Menu::instance(), 'redirect_legacy_slugs' ), 0 );
	// Parent `bizcity-twin-workspace` registers at admin_menu@5 (includes/class-admin-menu.php:84);
	// registering later keeps this a child instead of an orphan top-level page.
	add_action( 'admin_menu', static function () {
		BizCity_TwinKG_Admin_Menu::instance()->register();
	}, 25 );
}

// ── Twin Shell ActivityBar entry ─────────────────────────────────────────────
// The shell does not create the page; it wraps `/twinkg/` in its iframe and adds
// the unified left nav. `capability` mirrors what the retired wp-admin KG page
// required, so this move widens access to nobody — REST re-checks every route.
add_filter( 'bizcity_twin_register_plugins', static function ( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		$plugins = array();
	}
	$plugins[] = array(
		'id'          => 'twinkg',
		'label'       => __( 'Knowledge Graph', 'bizcity-twin-ai' ),
		'icon'        => 'explore',
		'emoji'       => '🕸️',
		'mode'        => 'embed',
		'public_slug' => '/twinkg/',
		'capability'  => class_exists( 'BizCity_TwinKG_Admin_Menu' )
			? BizCity_TwinKG_Admin_Menu::menu_cap()
			: 'manage_options',
		'section'     => 'top',
		'params'      => array( 'view', 'notebook', 'notebook_id', 'guru' ),
		'desc'        => __( 'Configure knowledge: notebooks, sources, graph, Gurus and access.', 'bizcity-twin-ai' ),
		'requires'    => array( 'const' => 'BIZCITY_TWINKG_VERSION' ),
	);
	return $plugins;
} );

// ── Rewrite flush ────────────────────────────────────────────────────────────
// R-CR — the central registry fires ONE consolidated flush at admin_init:1 when
// the module version changes.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'twinkg', BIZCITY_TWINKG_VERSION );
}

// One-time flush for installs that upgrade into this module after the registry
// has already run for this version. R-PERF: admin_init only — never on a
// front-end request, and never at file scope.
add_action( 'admin_init', static function () {
	if ( ! class_exists( 'BizCity_TwinKG_Public_Page' ) ) {
		return;
	}
	if ( ! get_option( BizCity_TwinKG_Public_Page::OPTION_KEY ) ) {
		flush_rewrite_rules( false );
		update_option( BizCity_TwinKG_Public_Page::OPTION_KEY, 1 );
	}
} );
