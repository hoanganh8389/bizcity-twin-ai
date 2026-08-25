<?php
/**
 * BizCity Tool Image uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'BZTIMG_DEACTIVATION_PURGE' ) ) {
	exit;
}

// [2026-08-25 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — remove Image-owned tenant data, CPT rows, and settings.
function bizcity_tool_image_uninstall() {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'bztimg_jobs',
		$wpdb->prefix . 'bztimg_template_categories',
		$wpdb->prefix . 'bztimg_templates',
		$wpdb->prefix . 'bztimg_projects',
		$wpdb->prefix . 'bztimg_compositions',
		$wpdb->prefix . 'bztimg_editor_shapes',
		$wpdb->prefix . 'bztimg_editor_frames',
		$wpdb->prefix . 'bztimg_editor_fonts',
		$wpdb->prefix . 'bztimg_editor_text_presets',
		$wpdb->prefix . 'bztimg_editor_templates',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	do {
		$ids = get_posts( array(
			'post_type'      => 'bztimg_model',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );
		$deleted = 0;
		foreach ( $ids as $post_id ) {
			if ( wp_delete_post( (int) $post_id, true ) ) {
				$deleted++;
			}
		}
	} while ( ! empty( $ids ) && $deleted > 0 );

	$options = array(
		'bztimg_api_key',
		'bztimg_api_endpoint',
		'bztimg_default_model',
		'bztimg_default_size',
		'bztimg_openai_key',
		'bztimg_editor_url',
		'bztimg_editor_hub_url',
		'bztimg_schema_version',
		'bztimg_db_version',
		'bztimg_seed_last_checked',
		'bztimg_hub_manifest_etag',
		'bztimg_hub_last_sync_report',
		'bztimg_hub_last_sync_at',
		'bztimg_profile_seeded',
		'bztimg_profile_seed_version',
		'bztimg_profile_seed_lock',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bztimg_hub_samples_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	delete_transient( 'bztimg_skill_sync_hash' );
}

bizcity_tool_image_uninstall();
