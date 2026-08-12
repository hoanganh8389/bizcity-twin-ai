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

// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — canonical phone identity normalizer.
require_once $_helper_includes . 'class-bizcity-phone-normalizer.php';

// [2026-08-01 Johnny Chu] PHASE-LOG-SPLIT — per-blog JSONL logs for CRM and memory.
require_once __DIR__ . '/class-bizcity-jsonl-file-logger.php';
// [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — register one bounded
// sweep for shared JSONL evidence folders after all cron owners are loaded.
add_action( 'init', array( 'BizCity_JSONL_File_Logger', 'register_retention_cron' ), 20 );
add_action( 'bizcity_jsonl_retention', array( 'BizCity_JSONL_File_Logger', 'gc_standard_logs' ), 10, 0 );
// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — one event_uuid/trace_id/
// parent_event_uuid contract shared by Channel JSONL and Twin Event Stream.
require_once __DIR__ . '/class-bizcity-chat-correlation.php';

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
		if ( function_exists( 'bizcity_table_type_invalidate' ) ) {
			bizcity_table_type_invalidate( $table_name );
		}
	}
}

// [2026-07-30 Johnny Chu] R-PERF — cache TABLE_TYPE checks used by KG schema readiness.
if ( ! function_exists( 'bizcity_table_type' ) ) {
	function bizcity_table_type( $table_name ) {
		global $wpdb;
		$database  = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = 'bz_tbl_type_' . (int) get_current_blog_id() . '_' . md5( $database . '|' . $table_name );
		$cached    = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$type = $wpdb->get_var( $wpdb->prepare(
			'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		$type = (string) $type;
		wp_cache_set( $cache_key, $type, 'bizcity_tbl', HOUR_IN_SECONDS );
		return $type;
	}
}

if ( ! function_exists( 'bizcity_table_type_invalidate' ) ) {
	function bizcity_table_type_invalidate( $table_name ) {
		global $wpdb;
		$database  = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = 'bz_tbl_type_' . (int) get_current_blog_id() . '_' . md5( $database . '|' . $table_name );
		wp_cache_delete( $cache_key, 'bizcity_tbl' );
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — cache repeated information_schema column checks per blog and routed database.
if ( ! function_exists( 'bizcity_column_exists' ) ) {
	function bizcity_column_exists( $table_name, $column_name ) {
		static $memo = array();
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$memo_key = (int) get_current_blog_id() . ':' . $database . ':' . $table_name . ':' . $column_name;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return $memo[ $memo_key ];
		}
		$cache_key = 'bz_col_' . md5( $memo_key );
		$cached = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( false !== $cached ) {
			$memo[ $memo_key ] = (bool) $cached;
			return $memo[ $memo_key ];
		}

		$present = (int) (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
				$table_name,
				$column_name
			)
		);
		wp_cache_set( $cache_key, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		$memo[ $memo_key ] = (bool) $present;
		return $memo[ $memo_key ];
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — invalidate schema cache after additive DDL or repair.
if ( ! function_exists( 'bizcity_column_invalidate' ) ) {
	function bizcity_column_invalidate( $table_name, $column_name ) {
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = 'bz_col_' . (int) get_current_blog_id() . '_' . md5( $database . '|' . $table_name . '|' . $column_name );
		wp_cache_delete( $cache_key, 'bizcity_tbl' );
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — batch/cache KG schema columns so one table needs one metadata query per cache key.
if ( ! function_exists( 'bizcity_columns_exist' ) ) {
	function bizcity_columns_exist( $table_name, $column_names ) {
		global $wpdb;
		$columns = array_values( array_unique( array_filter( array_map( 'strval', (array) $column_names ) ) ) );
		if ( empty( $columns ) ) {
			return true;
		}

		$database  = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = 'bz_cols_' . (int) get_current_blog_id() . '_' . md5( $database . '|' . $table_name . '|' . implode( ',', $columns ) );
		$cached    = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$placeholders = implode( ',', array_fill( 0, count( $columns ), '%s' ) );
		$args         = array_merge( array( $table_name ), $columns );
		$found        = $wpdb->get_col( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ({$placeholders})",
			$args
		) );
		$ready = count( array_unique( array_map( 'strval', (array) $found ) ) ) === count( $columns );
		wp_cache_set( $cache_key, $ready ? 1 : 0, 'bizcity_tbl', HOUR_IN_SECONDS );
		return $ready;
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — clear the batched schema result after KG DDL.
if ( ! function_exists( 'bizcity_columns_invalidate' ) ) {
	function bizcity_columns_invalidate( $table_name, $column_names ) {
		global $wpdb;
		$columns = array_values( array_unique( array_filter( array_map( 'strval', (array) $column_names ) ) ) );
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = 'bz_cols_' . (int) get_current_blog_id() . '_' . md5( $database . '|' . $table_name . '|' . implode( ',', $columns ) );
		wp_cache_delete( $cache_key, 'bizcity_tbl' );
	}
}

unset( $_helper_includes );
