<?php
/**
 * BizCity Tool Content uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'BZTOOL_CONTENT_DEACTIVATION_PURGE' ) ) {
	exit;
}

// [2026-08-25 Johnny Chu] PHASE-1.29-OPTIONAL-TEARDOWN — remove plugin-owned history and CPT data.
function bizcity_tool_content_uninstall() {
	global $wpdb;

	$table = $wpdb->prefix . 'bztc_prompt_history';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

	do {
		$ids = get_posts( array(
			'post_type'      => 'bza-content',
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
}

bizcity_tool_content_uninstall();
