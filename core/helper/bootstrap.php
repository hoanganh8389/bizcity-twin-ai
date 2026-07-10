<?php
/**
 * Core Helper — Bootstrap
 *
 * [2026-06-05 Johnny Chu] R-ERROR-UX — loads BizCity_Error_Payload and any
 * future shared helper utilities from core/helper/.
 *
 * Load order: early in bizcity-twin-ai.php, before channel-gateway and
 * automation, so all REST controllers can use the helper.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Core\Helper
 * @since      3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'BIZCITY_HELPER_LOADED' ) ) {
	return;
}
define( 'BIZCITY_HELPER_LOADED', true );

$_helper_includes = __DIR__ . '/includes/';

// [2026-06-05 Johnny Chu] R-ERROR-UX — canonical error payload builder
require_once $_helper_includes . 'class-bizcity-error-payload.php';

// [2026-06-09 Johnny Chu] R-CACHE — unified two-tier cache helper (object cache + transients)
require_once __DIR__ . '/class-bizcity-cache.php';

// [2026-06-21 Johnny Chu] R-CACHE — Central Cache Registry (catalog of all groups)
require_once __DIR__ . '/class-bizcity-cache-registry.php';

// [2026-06-21 Johnny Chu] R-SHOW-TABLES — canonical table-existence helper.
// SELECT 1 FROM information_schema.TABLES + dual cache (static + wp_cache/Redis, 1h TTL).
// CẤM dùng SHOW TABLES LIKE trong code mới — dùng bizcity_tbl_exists() hoặc alias bizcity_table_exists().
// Multisite-safe: cache key bao gồm blog_id để tránh cross-blog collision.
if ( ! function_exists( 'bizcity_tbl_exists' ) ) {
	function bizcity_tbl_exists( $table_name ) {
		static $s = array();
		if ( isset( $s[ $table_name ] ) ) {
			return $s[ $table_name ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table_name ] = (bool) $present;
		return $s[ $table_name ];
	}
}
// Alias used by class-chat-history-service.php and other callers.
if ( ! function_exists( 'bizcity_table_exists' ) ) {
	function bizcity_table_exists( $table_name ) {
		return bizcity_tbl_exists( $table_name );
	}
}
// Flush per-blog cache after table creation (call from installer/activate).
if ( ! function_exists( 'bizcity_tbl_invalidate' ) ) {
	function bizcity_tbl_invalidate( $table_name ) {
		$ck = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		wp_cache_delete( $ck, 'bizcity_tbl' );
	}
}

unset( $_helper_includes );
