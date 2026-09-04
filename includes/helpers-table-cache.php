<?php
/**
 * Bizcity Twin AI — Legacy table metadata compatibility shim.
 *
 * The canonical implementation lives in
 * `core/helper/class-bizcity-table-metadata.php`. This file remains loaded by
 * the main plugin entrypoint for compatibility with old callers, but it no
 * longer owns a second option-backed cache or information_schema query.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

$_bzc_table_metadata = dirname( __DIR__ ) . '/core/helper/class-bizcity-table-metadata.php';
$_bzc_safe_loader = dirname( __DIR__ ) . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $_bzc_safe_loader ) && is_readable( $_bzc_safe_loader ) ) {
	require_once $_bzc_safe_loader;
}
if ( class_exists( 'BizCity_Safe_Loader', false ) && ! class_exists( 'BizCity_Table_Metadata', false ) && is_file( $_bzc_table_metadata ) && is_readable( $_bzc_table_metadata ) ) {
	BizCity_Safe_Loader::require_file( $_bzc_table_metadata, 'helper.table_metadata.legacy_shim' );
}
unset( $_bzc_table_metadata, $_bzc_safe_loader );

if ( ! function_exists( 'bizcity_table_cache_remember' ) ) {
	function bizcity_table_cache_remember( $table ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — retain legacy API while routing invalidation to the canonical owner.
		if ( class_exists( 'BizCity_Table_Metadata', false ) ) {
			BizCity_Table_Metadata::invalidate( $table );
		}
	}
}

if ( ! function_exists( 'bizcity_table_cache_forget' ) ) {
	function bizcity_table_cache_forget( $table = null ) {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — legacy forget API no longer writes an option-backed metadata cache.
		if ( class_exists( 'BizCity_Table_Metadata', false ) && null !== $table ) {
			BizCity_Table_Metadata::invalidate( $table );
		}
	}
}

if ( ! function_exists( 'bizcity_tbl_exists' ) ) {
	function bizcity_tbl_exists( $table_name ) {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-WAVE5 — export the canonical metadata wrapper before early Knowledge bootstrap.
		return class_exists( 'BizCity_Table_Metadata', false ) ? BizCity_Table_Metadata::table_exists( $table_name ) : false;
	}
}

if ( ! function_exists( 'bizcity_table_exists' ) ) {
	function bizcity_table_exists( $table_name ) {
		return bizcity_tbl_exists( $table_name );
	}
}

if ( ! function_exists( 'bizcity_table_type' ) ) {
	function bizcity_table_type( $table_name ) {
		return class_exists( 'BizCity_Table_Metadata', false ) ? BizCity_Table_Metadata::table_type( $table_name ) : null;
	}
}

if ( ! function_exists( 'bizcity_column_exists' ) ) {
	function bizcity_column_exists( $table_name, $column_name ) {
		return class_exists( 'BizCity_Table_Metadata', false ) ? BizCity_Table_Metadata::column_exists( $table_name, $column_name ) : false;
	}
}

if ( ! function_exists( 'bizcity_columns_exist' ) ) {
	function bizcity_columns_exist( $table_name, $column_names ) {
		return class_exists( 'BizCity_Table_Metadata', false ) ? BizCity_Table_Metadata::columns_exist( $table_name, $column_names ) : false;
	}
}

if ( ! function_exists( 'bizcity_table_type_invalidate' ) ) {
	function bizcity_table_type_invalidate( $table_name ) {
		if ( class_exists( 'BizCity_Table_Metadata', false ) ) {
			BizCity_Table_Metadata::invalidate( $table_name );
		}
	}
}

if ( ! function_exists( 'bizcity_column_invalidate' ) ) {
	function bizcity_column_invalidate( $table_name, $column_name ) {
		if ( class_exists( 'BizCity_Table_Metadata', false ) ) {
			BizCity_Table_Metadata::invalidate_column( $table_name, $column_name );
		}
	}
}

if ( ! function_exists( 'bizcity_columns_invalidate' ) ) {
	function bizcity_columns_invalidate( $table_name, $column_names ) {
		if ( class_exists( 'BizCity_Table_Metadata', false ) ) {
			BizCity_Table_Metadata::invalidate_columns( $table_name, $column_names );
		}
	}
}
