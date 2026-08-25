<?php
/**
 * BizCity Content Creator uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'BZCC_DEACTIVATION_PURGE' ) ) {
	exit;
}

// [2026-08-25 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — remove Creator-owned tenant tables and settings.
function bizcity_content_creator_uninstall() {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'bizcity_creator_categories',
		$wpdb->prefix . 'bizcity_creator_templates',
		$wpdb->prefix . 'bizcity_creator_files',
		$wpdb->prefix . 'bizcity_creator_chunk_meta',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	$options = array(
		'bzcc_db_version',
		'bzcc_skill_sync_bootstrapped',
		'bzcc_skill_sync_fingerprints',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'bzcc_skill_sync_hash' );
}

bizcity_content_creator_uninstall();
