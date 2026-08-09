<?php
/**
 * Bizcity Twin AI — Table existence cache.
 *
 * Mục đích: tránh chạy metadata query mỗi request cho các bảng đã tồn tại
 * (bizcity_intent_conversations, bizcity_webchat_messages, …). Mỗi bảng chỉ
 * cần check 1 lần / blog → ghi vào option `bizcity_known_tables` (autoload=no);
 * lần sau đọc thẳng từ option, không hit DB.
 *
 * Sau khi `CREATE TABLE` mới, gọi `bizcity_table_cache_remember()` hoặc
 * `bizcity_table_cache_forget()` để cập nhật.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! function_exists( 'bizcity_table_exists' ) ) {

	/**
	 * In-process memo. Lazy-loaded từ option ở lần gọi đầu.
	 * Format: [ 'wp_xxx' => true, 'wp_1258_yyy' => true, ... ].
	 *
	 * @return array
	 */
	function bizcity_table_cache_known() {
		static $known = null;
		if ( null === $known ) {
			$known = get_option( 'bizcity_known_tables', [] );
			if ( ! is_array( $known ) ) {
				$known = [];
			}
		}
		return $known;
	}

	/**
	 * Đánh dấu table đã tồn tại (sau khi CREATE TABLE thành công hoặc check OK).
	 */
	function bizcity_table_cache_remember( $table ) {
		$table = (string) $table;
		if ( '' === $table ) return;
		$known = &_bizcity_table_cache_ref();
		if ( isset( $known[ $table ] ) ) return;
		$known[ $table ] = true;
		update_option( 'bizcity_known_tables', $known, false );
	}

	/**
	 * Quên 1 table (khi DROP / migration reset). Truyền null để xoá cả option.
	 */
	function bizcity_table_cache_forget( $table = null ) {
		if ( null === $table ) {
			delete_option( 'bizcity_known_tables' );
			$known = &_bizcity_table_cache_ref();
			$known = [];
			return;
		}
		$known = &_bizcity_table_cache_ref();
		if ( isset( $known[ $table ] ) ) {
			unset( $known[ $table ] );
			update_option( 'bizcity_known_tables', $known, false );
		}
	}

	/**
	 * Cached table existence. Prefer the canonical information_schema helper when
	 * it is available; retain an option-backed fallback for standalone load order.
	 *
	 * @param string $table Tên table đầy đủ (đã prefix).
	 * @return bool
	 */
	function bizcity_table_exists( $table ) {
		$table = (string) $table;
		if ( '' === $table ) return false;
		// [2026-08-09 Johnny Chu] R-SHOW-TABLES — use the canonical blog/database-aware metadata cache.
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return bizcity_tbl_exists( $table );
		}
		$known = &_bizcity_table_cache_ref();
		if ( array_key_exists( $table, $known ) ) {
			return (bool) $known[ $table ];
		}
		global $wpdb;
		$present = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		$known[ $table ] = $present;
		update_option( 'bizcity_known_tables', $known, false );
		return $present;
	}

	/**
	 * Internal: trả về reference vào memo array để các hàm trên cùng share state.
	 *
	 * @return array
	 */
	function &_bizcity_table_cache_ref() {
		static $ref = null;
		if ( null === $ref ) {
			$ref = bizcity_table_cache_known();
		}
		return $ref;
	}
}
