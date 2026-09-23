<?php
/**
 * Plugin Name:       BizCity Twin CRM (Inbox Hub)
 * Plugin URI:        https://bizcity.vn
 * Description:       Unified multi-channel inbox (Facebook / Messenger / Zalo / WebChat) with Twin Brain trace. Phase 0.32 — M1.
 * Version:           0.32.1
 * Author:            BizCity
 * License:           GPL-2.0-or-later
 * Text Domain:       bizcity-twin-crm
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

// [2026-09-22 09:30 AM GitHub Copilot] PHASE-CRM-MUSTLOAD — CRM is a
// mandatory bundled runtime of Twin AI. Do not silently return because one
// optional/relocated artifact is absent; the bootstrap owns guarded loading
// and the Twin AI loader records a clear mandatory-bundle failure.
if ( ! defined( 'BIZCITY_CRM_MUSTLOAD_CONTRACT' ) ) {
	define( 'BIZCITY_CRM_MUSTLOAD_CONTRACT', 'surfaces_for@1' );
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-BUNDLE - the default TwinChat admin
// shell renders its own iframe and does not need the CRM runtime graph. Keep
// CRM surface requests (plugin=crm), REST, webhooks, cron and public /crm/ alive.
$_bizcity_crm_shell_plugin = isset( $_GET['plugin'] )
	? sanitize_key( (string) $_GET['plugin'] )
	: '';
if ( is_admin()
	&& isset( $_GET['page'] )
	&& 'bizcity-twinchat' === sanitize_key( (string) $_GET['page'] )
	&& ( $_bizcity_crm_shell_plugin === '' || $_bizcity_crm_shell_plugin === 'twinchat' ) ) {
	return;
}
unset( $_bizcity_crm_shell_plugin );

/* ------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------ */
if ( ! defined( 'BIZCITY_CRM_VERSION' ) )    { define( 'BIZCITY_CRM_VERSION', '0.32.2' ); }
if ( ! defined( 'BIZCITY_CRM_FILE' ) )       { define( 'BIZCITY_CRM_FILE', __FILE__ ); }
if ( ! defined( 'BIZCITY_CRM_DIR' ) )        { define( 'BIZCITY_CRM_DIR', __DIR__ ); }
if ( ! defined( 'BIZCITY_CRM_URL' ) )        { define( 'BIZCITY_CRM_URL', plugins_url( '', __FILE__ ) ); }
if ( ! defined( 'BIZCITY_CRM_REST_NS' ) )    { define( 'BIZCITY_CRM_REST_NS', 'bizcity-crm/v1' ); }
if ( ! defined( 'BIZCITY_CRM_DB_VERSION' ) ) { define( 'BIZCITY_CRM_DB_VERSION', '1.36.0' ); } // [2026-09-23 04:20 PM Claude Fable 5.1] PHASE-0.60B — contacts.birthday + birthday_md (was 1.35.0: PHASE-0.63A WP-0.5 pipeline run columns, tasks.data_json, bizcity_crm_pipeline_deadlines)

require_once __DIR__ . '/bootstrap.php';

// Bootstrap on plugins_loaded priority 6 — after twin-core (priority 0-5),
// before channel plugins (priority 10) so we can subscribe their actions.
add_action( 'plugins_loaded', static function () {
	BizCity_CRM_Plugin::instance();
}, 6 );

// Activation: ensure tables on activate (works when standalone).
register_activation_hook( __FILE__, static function () {
	require_once __DIR__ . '/includes/class-db-installer.php';
	require_once __DIR__ . '/includes/class-capabilities.php';
	BizCity_CRM_DB_Installer_V2::install();
	BizCity_CRM_Capabilities::grant_all();

	// M-PA.W1 — Print-Ads template library tables + first-time seed.
	require_once __DIR__ . '/includes/print-ads/class-print-templates-installer.php';
	BizCity_CRM_Print_Templates_Installer::install();

	// [2026-06-07 Johnny Chu] PHASE-0.38.W3.2 — flush rewrite rules so /o/<token> works immediately.
	flush_rewrite_rules( false );
} );

/* ------------------------------------------------------------------
 * Public slug `/crm/` — direct CRM SPA mount.
 *
 * TwinShell already provides the outer iframe. Do not create a second iframe
 * or load wp-admin inside `/crm/`; the CRM admin asset is mounted directly by
 * the public route below. This keeps the runtime at one TwinShell iframe and
 * avoids nested admin/bootstrap/URL-sync failures.
 * ------------------------------------------------------------------ */
add_action( 'init', static function () {
	add_rewrite_rule( '^crm/?$', 'index.php?bizcity_agent_page=crm', 'top' );
	add_rewrite_tag( '%bizcity_agent_page%', '([^&]+)' );
}, 11 );

add_action( 'template_redirect', static function () {
	if ( get_query_var( 'bizcity_agent_page' ) !== 'crm' ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/crm/' ) ) );
		exit;
	}
	// [2026-09-22 12:30 AM OpenAI GPT-5.6 Luna] CRM-BACKEND-SURFACE — hide the public WordPress admin bar from the CRM backend shell.
	add_filter( 'show_admin_bar', '__return_false', 100 );
	// [2026-09-19 Johnny Chu] PHASE-0.60 C2/C5 — public /crm/ uses the same action gate as wp-admin and TwinShell.
	if ( class_exists( 'BizCity_CRM_Authority' ) && ! BizCity_CRM_Authority::can( 'crm.inbox.open', array(), BizCity_CRM_Actor::current( 'be' ) )['ok'] ) {
		wp_die( 'Tài khoản này chưa được cấp quyền sử dụng CRM Inbox. Hãy liên hệ quản lý để được cấp quyền.', 'CRM', array( 'response' => 403 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'CRM Inbox', 'bizcity-twin-crm' ); ?></title>
<?php
// [2026-09-22 02:00 AM OpenAI GPT-5.6 Luna] R-PERF-LOADER — print only the
// canonical CRM handles. Do not run wp_head()/wp_footer(): they load the active
// public theme, public widgets and diagnostic panels such as Query Monitor.
$crm_public_assets = array();
if ( class_exists( 'BizCity_CRM_Admin_Menu' ) ) {
	$crm_public_assets = BizCity_CRM_Admin_Menu::instance()->enqueue_public_assets();
}
$crm_script_handle = ! empty( $crm_public_assets['script'] ) ? $crm_public_assets['script'] : '';
if ( ! empty( $crm_public_assets['style'] ) ) {
	wp_print_styles( array( $crm_public_assets['style'] ) );
}
?>
<style>
html,body{margin:0;padding:0;height:100%;background:#FAFBFC;}
html { margin-top: 0 !important; }
body { padding-top: 0 !important; }
#wpadminbar, #bizchat-float-btn, #bizchat-window, .bizchat-window, [id*="bizchat"], [class*="bizchat"] { display:none !important; }

#bizcity-crm-inbox-root{display:block;width:100vw;height:100vh;min-height:600px;}
</style>
</head>
<body>
<div id="bizcity-crm-inbox-root"></div>
<?php
if ( '' !== $crm_script_handle ) {
	// [2026-09-23 05:30 PM OpenAI GPT-5.6 Luna] PHASE-CRM-MUSTLOAD — the
	// bundle must execute after the mount element exists. Printing it in <head>
	// loads the resource successfully but main.jsx sees no root and silently
	// returns, producing the observed empty #bizcity-crm-inbox-root.
	wp_print_scripts( array( $crm_script_handle ) );
}
?>
</body>
</html><?php
	exit;
} );
