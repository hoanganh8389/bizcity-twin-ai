<?php
/**
 * BizCity CRM — Staff routing profile (PHASE-0.69 §5.1, WP-S). **user_meta, no new table.**
 *
 * Decision record summary (0.69 §5.1, R-DATA-STORAGE §2): `data_role=configuration`, tens-to-low-hundreds
 * of active staff per tenant, read as "whole team then filter in PHP" — exactly the shape `class-staff-rest.php`
 * already uses `META_STATUS`/`META_ONBOARDING_INVITED_AT` for. A dedicated indexed table is a *later*
 * decision (~500 active staff, not before), not a default.
 *
 * One meta key holds the whole profile as JSON:
 *   { skills: string[], areas: string[], capacity: {shifts_per_day, max_concurrent},
 *     roster: {"0".."6": [["08:00","18:00"], ...]}, roster_exceptions: [{date, off, reason}] }
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69 WP-S)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Staff_Profile', false ) ) {
	return;
}

final class BizCity_CRM_Staff_Profile {

	const META_KEY = 'bizcity_crm_staff_profile';

	/** @return array{skills:string[],areas:string[],capacity:array,roster:array,roster_exceptions:array} */
	public static function get( int $user_id ): array {
		$raw = $user_id > 0 && function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, self::META_KEY, true ) : null;
		$raw = is_array( $raw ) ? $raw : array();
		return self::sanitize( $raw );
	}

	/** @return true|WP_Error */
	public static function save( int $user_id, array $profile ) {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'staff_not_found', 'Không tìm thấy nhân viên.', array( 'status' => 404, 'hint' => 'Chọn một nhân viên hợp lệ.', 'help_code' => 'service_staff_not_found' ) );
		}
		$clean = self::sanitize( $profile );
		if ( ! function_exists( 'update_user_meta' ) ) {
			return new WP_Error( 'storage_unavailable', 'Kho dữ liệu chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Thử lại sau.', 'help_code' => 'service_storage_unavailable' ) );
		}
		update_user_meta( $user_id, self::META_KEY, $clean );
		return true;
	}

	/** Whether `$user_id` is on-roster at the given local timestamp (dow + window, minus exceptions). */
	public static function on_roster_at( int $user_id, int $timestamp ): bool {
		$profile = self::get( $user_id );
		$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d', $timestamp );
		foreach ( $profile['roster_exceptions'] as $exception ) {
			if ( ( $exception['date'] ?? '' ) === $date ) {
				return empty( $exception['off'] );
			}
		}
		$dow = (string) ( (int) ( function_exists( 'wp_date' ) ? wp_date( 'w', $timestamp ) : gmdate( 'w', $timestamp ) ) );
		$hm = function_exists( 'wp_date' ) ? wp_date( 'H:i', $timestamp ) : gmdate( 'H:i', $timestamp );
		foreach ( (array) ( $profile['roster'][ $dow ] ?? array() ) as $window ) {
			if ( is_array( $window ) && 2 === count( $window ) && $hm >= $window[0] && $hm <= $window[1] ) {
				return true;
			}
		}
		return false;
	}

	private static function sanitize( array $profile ): array {
		$skills = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'slug' ), (array) ( $profile['skills'] ?? array() ) ) ) ) );
		$areas = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'slug' ), (array) ( $profile['areas'] ?? array() ) ) ) ) );
		$capacity_in = is_array( $profile['capacity'] ?? null ) ? $profile['capacity'] : array();
		$capacity = array(
			'shifts_per_day' => max( 0, (int) ( $capacity_in['shifts_per_day'] ?? 6 ) ),
			'max_concurrent' => max( 1, (int) ( $capacity_in['max_concurrent'] ?? 1 ) ),
		);
		$roster = array();
		$roster_in = is_array( $profile['roster'] ?? null ) ? $profile['roster'] : array();
		foreach ( range( 0, 6 ) as $dow ) {
			$windows = array();
			foreach ( (array) ( $roster_in[ (string) $dow ] ?? $roster_in[ $dow ] ?? array() ) as $window ) {
				if ( is_array( $window ) && isset( $window[0], $window[1] ) && self::is_time( (string) $window[0] ) && self::is_time( (string) $window[1] ) ) {
					$windows[] = array( (string) $window[0], (string) $window[1] );
				}
			}
			$roster[ (string) $dow ] = $windows;
		}
		$exceptions = array();
		foreach ( (array) ( $profile['roster_exceptions'] ?? array() ) as $exception ) {
			if ( ! is_array( $exception ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $exception['date'] ?? '' ) ) ) { continue; }
			$exceptions[] = array(
				'date'   => (string) $exception['date'],
				'off'    => (bool) ( $exception['off'] ?? true ),
				'reason' => isset( $exception['reason'] ) ? substr( sanitize_text_field( (string) $exception['reason'] ), 0, 190 ) : '',
			);
		}
		return array( 'skills' => $skills, 'areas' => $areas, 'capacity' => $capacity, 'roster' => $roster, 'roster_exceptions' => $exceptions );
	}

	private static function slug( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9_]{1,64}$/', $value ) ? $value : '';
	}

	private static function is_time( string $value ): bool {
		return (bool) preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value );
	}
}
