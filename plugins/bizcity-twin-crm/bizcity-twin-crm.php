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

/* ------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------ */
if ( ! defined( 'BIZCITY_CRM_VERSION' ) )    { define( 'BIZCITY_CRM_VERSION', '0.32.2' ); }
if ( ! defined( 'BIZCITY_CRM_FILE' ) )       { define( 'BIZCITY_CRM_FILE', __FILE__ ); }
if ( ! defined( 'BIZCITY_CRM_DIR' ) )        { define( 'BIZCITY_CRM_DIR', __DIR__ ); }
if ( ! defined( 'BIZCITY_CRM_URL' ) )        { define( 'BIZCITY_CRM_URL', plugins_url( '', __FILE__ ) ); }
if ( ! defined( 'BIZCITY_CRM_REST_NS' ) )    { define( 'BIZCITY_CRM_REST_NS', 'bizcity-crm/v1' ); }
if ( ! defined( 'BIZCITY_CRM_DB_VERSION' ) ) { define( 'BIZCITY_CRM_DB_VERSION', '1.25.0' ); } // [2026-07-05 Johnny Chu] R-UNIFY GAP-B — v1.25.0 add platform/platform_uid/source to contacts; platform/channel_thread_id/chat_id/contact_id/account_id/blog_id to conversations; platform/platform_msg_id/body/payload_json to messages

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
 * Public slug `/crm/` — front-end shell that iframes the admin CRM Inbox.
 *
 * Twin Shell ActivityBar registers an embed entry with public_slug `/crm/`
 * (see modules/twinshell/includes/default-plugins.php). The shell already
 * iframes /crm/ inside its workspace; this front-end route then renders a
 * second-level iframe pointing at the admin CRM page with `bizcity_iframe=1`
 * so wp-admin chrome is hidden.
 *
 * We render directly (no redirect) to avoid cross-host loops on multisite +
 * domain-mapped installs where admin_url() and home_url() resolve to
 * different hostnames.
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

	$forward = [ 'page' => 'bizcity-crm', 'bizcity_iframe' => '1' ];
	foreach ( [ 'id', 'tab', 'inbox', 'thread', 'contact_id' ] as $key ) {
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			$forward[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		}
	}
	$admin_url = add_query_arg( $forward, admin_url( 'admin.php' ) );

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'CRM Inbox', 'bizcity-twin-crm' ); ?></title>
<style>
html,body{margin:0;padding:0;height:100%;background:#FAFBFC;}

#bizcity-crm-frame{display:block;border:0;width:100vw;height:100vh;}
</style>
</head>
<body>
<iframe id="bizcity-crm-frame" src="<?php echo esc_url( $admin_url ); ?>" allow="clipboard-read; clipboard-write" loading="eager"></iframe>
</body>
</html><?php
	exit;
} );
