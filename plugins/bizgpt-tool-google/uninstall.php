<?php
/**
 * BizCity Google Tool uninstall.
 *
 * Google tables are global on multisite. A subsite uninstall only removes that
 * blog's rows and leaves the shared table available to other blogs.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'BZGOOGLE_DEACTIVATION_PURGE' ) ) {
	exit;
}

// [2026-08-25 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — remove Google data without dropping shared multisite tables.
function bizcity_google_tool_uninstall() {
	global $wpdb;

	$accounts = $wpdb->base_prefix . 'bizcity_google_accounts';
	$logs     = $wpdb->base_prefix . 'bizcity_google_usage_logs';
	$blog_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

	if ( is_multisite() ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$accounts}` WHERE blog_id = %d", $blog_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$logs}` WHERE blog_id = %d", $blog_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return;
	}

	$wpdb->query( "DROP TABLE IF EXISTS `{$accounts}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$logs}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	delete_site_option( 'bzgoogle_db_version' );
	delete_site_option( 'bzgoogle_client_id' );
	delete_site_option( 'bzgoogle_client_secret' );
	delete_site_option( 'bzgoogle_byo_client_id' );
	delete_site_option( 'bzgoogle_byo_client_secret' );
	delete_site_option( 'bzgoogle_hub_blog_id' );
}

bizcity_google_tool_uninstall();
