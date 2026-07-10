<?php
/**
 * BizCoach Pro — Self Profile Manager
 *
 * Enforces UNIQUE "self/primary" profile per user on bccm_coachees.is_self.
 *
 * @package BizCoach_Pro
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Self_Profile_Manager' ) ) { return; }

class BizCoach_Pro_Self_Profile_Manager {

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — cache has-column probe per table.
	 *
	 * @var array<string,bool>
	 */
	private static $has_col_cache = array();

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — canonical table name for coachees.
	 */
	private static function table_coachees() {
		global $wpdb;
		return $wpdb->prefix . 'bccm_coachees';
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — detect is_self column on bccm_coachees.
	 */
	public static function has_is_self_column() {
		global $wpdb;
		$table = self::table_coachees();
		if ( array_key_exists( $table, self::$has_col_cache ) ) {
			return (bool) self::$has_col_cache[ $table ];
		}

		$has_col = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = %s
			   AND COLUMN_NAME = %s
			 LIMIT 1",
			$table,
			'is_self'
		) );

		self::$has_col_cache[ $table ] = $has_col;
		return $has_col;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — true if user currently has a self profile.
	 */
	public static function user_has_self( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::has_is_self_column() ) {
			return false;
		}

		$table = self::table_coachees();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND is_self = 1 LIMIT 1",
			$user_id
		) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — get current self profile id (0 if absent).
	 */
	public static function get_self_coachee_id( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::has_is_self_column() ) {
			return 0;
		}

		$table = self::table_coachees();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND is_self = 1 ORDER BY id ASC LIMIT 1",
			$user_id
		) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — set exactly one self profile for user.
	 * All other rows of same user are demoted to is_self=0.
	 */
	public static function set_self_coachee( $user_id, $coachee_id ) {
		global $wpdb;
		$user_id    = (int) $user_id;
		$coachee_id = (int) $coachee_id;
		if ( $user_id <= 0 || $coachee_id <= 0 || ! self::has_is_self_column() ) {
			return false;
		}

		$table = self::table_coachees();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET is_self = 0 WHERE user_id = %d",
			$user_id
		) );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET is_self = 1 WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id,
			$user_id
		) );

		return true;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — unset self flag for specific profile.
	 */
	public static function unset_self_coachee( $user_id, $coachee_id ) {
		global $wpdb;
		$user_id    = (int) $user_id;
		$coachee_id = (int) $coachee_id;
		if ( $user_id <= 0 || $coachee_id <= 0 || ! self::has_is_self_column() ) {
			return false;
		}

		$table = self::table_coachees();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET is_self = 0 WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id,
			$user_id
		) );

		return true;
	}
}
