<?php
/**
 * Canonical cached metadata helpers for tenant table/schema checks.
 *
 * Contract: core.helper.table_metadata
 * - metadata only; no schema repair or option writes on cache miss;
 * - static memo plus wp_cache with finite TTL;
 * - cache identity includes current blog and routed database;
 * - DDL callers must invoke the matching invalidation helper after success.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [2026-08-29 Johnny Chu] R-METADATA-CACHE — use a conditional class declaration so PHP compile-time class registration cannot return before global compatibility wrappers are defined.
if ( ! class_exists( 'BizCity_Table_Metadata', false ) ) {
final class BizCity_Table_Metadata {

	const CONTRACT_ID = 'core.helper.table_metadata';
	const CACHE_GROUP = 'bizcity_tbl';
	const CACHE_TTL   = 3600;

	/** Return the current tenant/database cache identity. */
	private static function context() {
		global $wpdb;
		return array(
			'blog_id'  => (int) get_current_blog_id(),
			'database' => isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '',
		);
	}

	/** Build one namespaced metadata cache key. */
	private static function cache_key( $prefix, $table_name, $column_name = '' ) {
		$context = self::context();
		$identity = $context['database'] . '|' . (string) $table_name . '|' . (string) $column_name;
		return (string) $prefix . '_' . $context['blog_id'] . '_' . md5( $identity );
	}

	/** Check one table through static memo and the shared object cache. */
	public static function table_exists( $table_name ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — centralize tenant/database-aware table existence checks.
		$table_name = (string) $table_name;
		if ( $table_name === '' ) {
			return false;
		}
		static $memo = array();
		static $generation = null;
		$current_generation = isset( $GLOBALS['bizcity_table_cache_generation'] )
			? (int) $GLOBALS['bizcity_table_cache_generation']
			: 0;
		if ( $generation !== $current_generation ) {
			$memo = array();
			$generation = $current_generation;
		}
		$context = self::context();
		$memo_key = $context['blog_id'] . ':' . $context['database'] . ':' . $table_name;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return (bool) $memo[ $memo_key ];
		}
		$cache_key = self::cache_key( 'bz_tbl', $table_name );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$memo[ $memo_key ] = (bool) $cached;
			return $memo[ $memo_key ];
		}
		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		wp_cache_set( $cache_key, $present, self::CACHE_GROUP, self::CACHE_TTL );
		$memo[ $memo_key ] = (bool) $present;
		return $memo[ $memo_key ];
	}

	/** Return TABLE_TYPE using the same tenant/database cache contract. */
	public static function table_type( $table_name ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — share database-scoped TABLE_TYPE caching with table checks.
		$table_name = (string) $table_name;
		if ( $table_name === '' ) {
			return '';
		}
		static $memo = array();
		static $generation = null;
		$current_generation = isset( $GLOBALS['bizcity_table_cache_generation'] )
			? (int) $GLOBALS['bizcity_table_cache_generation']
			: 0;
		if ( $generation !== $current_generation ) {
			$memo = array();
			$generation = $current_generation;
		}
		$context = self::context();
		$memo_key = $context['blog_id'] . ':' . $context['database'] . ':' . $table_name;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return (string) $memo[ $memo_key ];
		}
		$cache_key = self::cache_key( 'bz_tbl_type', $table_name );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$memo[ $memo_key ] = (string) $cached;
			return $memo[ $memo_key ];
		}
		global $wpdb;
		$type = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		wp_cache_set( $cache_key, $type, self::CACHE_GROUP, self::CACHE_TTL );
		$memo[ $memo_key ] = $type;
		return $memo[ $memo_key ];
	}

	/** Check one column with static memo and the shared object cache. */
	public static function column_exists( $table_name, $column_name ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — centralize database-scoped column checks.
		$table_name  = (string) $table_name;
		$column_name = (string) $column_name;
		if ( $table_name === '' || $column_name === '' ) {
			return false;
		}
		static $memo = array();
		static $generation = null;
		$current_generation = isset( $GLOBALS['bizcity_column_cache_generation'] )
			? (int) $GLOBALS['bizcity_column_cache_generation']
			: 0;
		if ( $generation !== $current_generation ) {
			$memo = array();
			$generation = $current_generation;
		}
		$context = self::context();
		$memo_key = $context['blog_id'] . ':' . $context['database'] . ':' . $table_name . ':' . $column_name;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return (bool) $memo[ $memo_key ];
		}
		$cache_key = self::cache_key( 'bz_col_' . $current_generation, $table_name, $column_name );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$memo[ $memo_key ] = (bool) $cached;
			return $memo[ $memo_key ];
		}
		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table_name,
			$column_name
		) );
		wp_cache_set( $cache_key, $present, self::CACHE_GROUP, self::CACHE_TTL );
		$memo[ $memo_key ] = (bool) $present;
		return $memo[ $memo_key ];
	}

	/** Check a set of columns with one bounded information_schema query. */
	public static function columns_exist( $table_name, $column_names ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — batch column checks under one database-scoped contract.
		$table_name = (string) $table_name;
		$columns = array_values( array_unique( array_filter( array_map( 'strval', (array) $column_names ) ) ) );
		if ( $table_name === '' || empty( $columns ) ) {
			return $table_name !== '';
		}
		$context = self::context();
		$generation = isset( $GLOBALS['bizcity_column_cache_generation'] ) ? (int) $GLOBALS['bizcity_column_cache_generation'] : 0;
		static $memo = array();
		static $memo_generation = null;
		if ( $memo_generation !== $generation ) {
			$memo = array();
			$memo_generation = $generation;
		}
		$columns_key = implode( ',', $columns );
		$memo_key = $context['blog_id'] . ':' . $context['database'] . ':' . $table_name . ':' . $columns_key;
		if ( array_key_exists( $memo_key, $memo ) ) {
			return (bool) $memo[ $memo_key ];
		}
		$cache_key = self::cache_key( 'bz_cols_' . $generation, $table_name, $columns_key );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$memo[ $memo_key ] = (bool) $cached;
			return $memo[ $memo_key ];
		}
		$placeholders = implode( ',', array_fill( 0, count( $columns ), '%s' ) );
		$args = array_merge( array( $table_name ), $columns );
		global $wpdb;
		$found = $wpdb->get_col( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ({$placeholders})",
			$args
		) );
		$ready = count( array_unique( array_map( 'strval', (array) $found ) ) ) === count( $columns );
		wp_cache_set( $cache_key, $ready ? 1 : 0, self::CACHE_GROUP, self::CACHE_TTL );
		$memo[ $memo_key ] = $ready;
		return $memo[ $memo_key ];
	}

	/** Invalidate table, type and all column metadata after successful DDL. */
	public static function invalidate( $table_name ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — invalidate all same-request metadata generations after DDL.
		$table_name = (string) $table_name;
		$GLOBALS['bizcity_table_cache_generation'] = isset( $GLOBALS['bizcity_table_cache_generation'] )
			? (int) $GLOBALS['bizcity_table_cache_generation'] + 1
			: 1;
		$GLOBALS['bizcity_column_cache_generation'] = isset( $GLOBALS['bizcity_column_cache_generation'] )
			? (int) $GLOBALS['bizcity_column_cache_generation'] + 1
			: 1;
		if ( $table_name === '' ) {
			return;
		}
		wp_cache_delete( self::cache_key( 'bz_tbl', $table_name ), self::CACHE_GROUP );
		wp_cache_delete( self::cache_key( 'bz_tbl_type', $table_name ), self::CACHE_GROUP );
	}

	/** Invalidate one column and advance the shared column generation. */
	public static function invalidate_column( $table_name, $column_name ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — invalidate one column and force batched checks to refresh.
		$previous_generation = isset( $GLOBALS['bizcity_column_cache_generation'] )
			? (int) $GLOBALS['bizcity_column_cache_generation']
			: 0;
		$GLOBALS['bizcity_column_cache_generation'] = isset( $GLOBALS['bizcity_column_cache_generation'] )
			? (int) $GLOBALS['bizcity_column_cache_generation'] + 1
			: 1;
		if ( (string) $table_name !== '' && (string) $column_name !== '' ) {
			wp_cache_delete( self::cache_key( 'bz_col_' . $previous_generation, $table_name, $column_name ), self::CACHE_GROUP );
		}
	}

	/** Invalidate a requested column set and force batched checks to refresh. */
	public static function invalidate_columns( $table_name, $column_names ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — invalidate batched column metadata after schema repair.
		$GLOBALS['bizcity_column_cache_generation'] = isset( $GLOBALS['bizcity_column_cache_generation'] )
			? (int) $GLOBALS['bizcity_column_cache_generation'] + 1
			: 1;
		$columns = array_values( array_unique( array_filter( array_map( 'strval', (array) $column_names ) ) ) );
		if ( (string) $table_name !== '' && ! empty( $columns ) ) {
			wp_cache_delete( self::cache_key( 'bz_cols_' . ( (int) $GLOBALS['bizcity_column_cache_generation'] - 1 ), $table_name, implode( ',', $columns ) ), self::CACHE_GROUP );
		}
	}
}
}

if ( ! function_exists( 'bizcity_tbl_exists' ) ) {
	function bizcity_tbl_exists( $table_name ) {
		return BizCity_Table_Metadata::table_exists( $table_name );
	}
}
if ( ! function_exists( 'bizcity_table_exists' ) ) {
	function bizcity_table_exists( $table_name ) {
		return BizCity_Table_Metadata::table_exists( $table_name );
	}
}
if ( ! function_exists( 'bizcity_table_type' ) ) {
	function bizcity_table_type( $table_name ) {
		return BizCity_Table_Metadata::table_type( $table_name );
	}
}
if ( ! function_exists( 'bizcity_table_type_invalidate' ) ) {
	function bizcity_table_type_invalidate( $table_name ) {
		BizCity_Table_Metadata::invalidate( $table_name );
	}
}
if ( ! function_exists( 'bizcity_column_exists' ) ) {
	function bizcity_column_exists( $table_name, $column_name ) {
		return BizCity_Table_Metadata::column_exists( $table_name, $column_name );
	}
}
if ( ! function_exists( 'bizcity_columns_exist' ) ) {
	function bizcity_columns_exist( $table_name, $column_names ) {
		return BizCity_Table_Metadata::columns_exist( $table_name, $column_names );
	}
}
if ( ! function_exists( 'bizcity_column_invalidate' ) ) {
	function bizcity_column_invalidate( $table_name, $column_name ) {
		BizCity_Table_Metadata::invalidate_column( $table_name, $column_name );
	}
}
if ( ! function_exists( 'bizcity_columns_invalidate' ) ) {
	function bizcity_columns_invalidate( $table_name, $column_names ) {
		BizCity_Table_Metadata::invalidate_columns( $table_name, $column_names );
	}
}
if ( ! function_exists( 'bizcity_tbl_invalidate' ) ) {
	function bizcity_tbl_invalidate( $table_name ) {
		BizCity_Table_Metadata::invalidate( $table_name );
	}
}
