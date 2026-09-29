<?php
/** Remote Zalo Hub optional loader — C0. */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader' ) || ! class_exists( 'BizCity_Remote_Zalo_Feature' ) ) { return; }

if ( ! BizCity_Remote_Zalo_Feature::enabled() ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C0 — load only remote artifacts behind the feature gate; legacy Zalo remains independent.
$files = array(
	'class-remote-zalo-credentials.php', 'class-remote-zalo-http.php', 'class-remote-zalo-host-policy.php',
	'class-remote-zalo-hub-client.php', 'class-remote-zalo-profile.php', 'class-remote-zalo-cursor-store.php',
	'class-remote-zalo-normalizer.php', 'class-remote-zalo-poller.php',
);
foreach ( $files as $file ) {
	$path = __DIR__ . '/' . $file;
	if ( ! BizCity_Safe_Loader::require_file( $path, 'zalo_personal.remote.' . basename( $file, '.php' ) ) ) {
		error_log( '[BizCity_Zalo_Remote] loader_failed ' . basename( $file ) );
		return;
	}
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	BizCity_Safe_Loader::require_file( __DIR__ . '/class-remote-zalo-cli.php', 'zalo_personal.remote.cli' );
}
BizCity_Safe_Loader::require_file( __DIR__ . '/rest/class-remote-zalo-settings-rest.php', 'zalo_personal.remote.settings_rest' );
if ( class_exists( 'BizCity_Remote_Zalo_Settings_REST' ) ) { BizCity_Remote_Zalo_Settings_REST::init(); }
BizCity_Safe_Loader::require_file( __DIR__ . '/rest/class-remote-zalo-accounts-rest.php', 'zalo_personal.remote.accounts_rest' );
if ( class_exists( 'BizCity_Remote_Zalo_Accounts_REST' ) ) { BizCity_Remote_Zalo_Accounts_REST::init(); }
unset( $files, $file, $path );
