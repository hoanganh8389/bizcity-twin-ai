<?php
/**
 * BizCity Doc Studio uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'BZDOC_DEACTIVATION_PURGE' ) ) {
	exit;
}

// [2026-08-25 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — remove Doc-owned tenant tables and schema markers.
function bizcity_doc_uninstall() {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'bzdoc_documents',
		$wpdb->prefix . 'bzdoc_project_sources',
		$wpdb->prefix . 'bzdoc_project_source_chunks',
		$wpdb->prefix . 'bzdoc_generations',
		$wpdb->prefix . 'bzdoc_image_prompts',
		$wpdb->prefix . 'bzdoc_image_jobs',
		$wpdb->prefix . 'bzdoc_mindmap_definitions',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	delete_option( 'bzdoc_schema_version' );
	delete_option( 'bzdoc_image_prompts_db_version' );
}

bizcity_doc_uninstall();
