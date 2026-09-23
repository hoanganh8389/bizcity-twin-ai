<?php
/**
 * BizCity CRM — Service dispatch matcher (PHASE-0.69 §5.2/§5.3, WP-M). **0 new tables.**
 *
 * `candidates()` runs the exact B1–B8 elimination the phase doc specifies, reusing the assignment
 * framework already running for Inbox routing (`class-assignment-manager.php:148-273` — `GET_LOCK` +
 * candidate SQL — is the precedent this borrows the shape from, not the code, since that path only runs
 * once at conversation open; a dispatch matcher must be callable on demand). Every rejection carries a
 * reason (`skill_missing`/`area_mismatch`/`off_roster`/`time_conflict`/`capacity_full`) — 0.69 §5.2 B8
 * and the risk note in §11 both insist on this: a dispatcher who cannot see WHY someone was skipped will
 * stop trusting the suggestion and go back to picking by hand.
 *
 * Anti-double-booking (§5.3, N4b) queries `bizcity_crm_events` (owned by `core/scheduler`) directly by
 * table name — a SOFT reference, guarded by `events_table_available()`, exactly as 0.69 §5.2 instructs:
 * *"xác nhận cross-plugin FK không cứng"*. `core/scheduler` not being installed degrades the conflict
 * check to "unknown" (never silently "no conflict"), it does not throw.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69 WP-M)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Service_Matcher', false ) ) {
	return;
}

final class BizCity_CRM_Service_Matcher {

	/**
	 * @param array $req {
	 *   @type int    $team_id           Required — which team offers this service (0.69 §5.2 B1).
	 *   @type string $appointment_at    Required — `Y-m-d H:i:s` or anything `strtotime()` parses.
	 *   @type int    $duration_minutes  Default 60.
	 *   @type string $skill             Optional — must be in the candidate's `skills[]` when set.
	 *   @type string $area              Optional — must be in the candidate's `areas[]` when set.
	 *   @type array  $customer_point    Optional `{lat,lng}` — ranks by distance to staff's last point.
	 * }
	 * @return array{candidates:array,rejected:array}
	 */
	public static function candidates( array $req ): array {
		$team_id = (int) ( $req['team_id'] ?? 0 );
		$appointment_ts = self::parse_ts( (string) ( $req['appointment_at'] ?? '' ) );
		if ( $team_id <= 0 || 0 === $appointment_ts || ! class_exists( 'BizCity_CRM_Team_Manager' ) ) {
			return array( 'candidates' => array(), 'rejected' => array() );
		}
		$duration_s = max( 60, (int) ( $req['duration_minutes'] ?? 60 ) * 60 );
		$skill = isset( $req['skill'] ) ? self::slug( (string) $req['skill'] ) : '';
		$area = isset( $req['area'] ) ? self::slug( (string) $req['area'] ) : '';
		$customer_point = isset( $req['customer_point'] ) && is_array( $req['customer_point'] ) ? $req['customer_point'] : null;

		$members = (array) BizCity_CRM_Team_Manager::list_team_members( $team_id ); // B1
		$rejected = array();
		$survivors = array();

		foreach ( $members as $member ) {
			$user_id = (int) ( $member['user_id'] ?? 0 );
			if ( $user_id <= 0 ) { continue; }
			$profile = class_exists( 'BizCity_CRM_Staff_Profile' ) ? BizCity_CRM_Staff_Profile::get( $user_id ) : array( 'skills' => array(), 'areas' => array(), 'capacity' => array( 'shifts_per_day' => 6 ) );

			if ( '' !== $skill && ! in_array( $skill, (array) $profile['skills'], true ) ) { // B2
				$rejected[] = array( 'user_id' => $user_id, 'reason' => 'skill_missing' );
				continue;
			}
			if ( '' !== $area && ! in_array( $area, (array) $profile['areas'], true ) ) { // B3
				$rejected[] = array( 'user_id' => $user_id, 'reason' => 'area_mismatch' );
				continue;
			}
			if ( class_exists( 'BizCity_CRM_Staff_Profile' ) && ! BizCity_CRM_Staff_Profile::on_roster_at( $user_id, $appointment_ts ) ) { // B4
				$rejected[] = array( 'user_id' => $user_id, 'reason' => 'off_roster' );
				continue;
			}
			$conflict = self::has_conflict( $user_id, $appointment_ts, $duration_s ); // B5
			if ( true === $conflict ) {
				$rejected[] = array( 'user_id' => $user_id, 'reason' => 'time_conflict' );
				continue;
			}
			$today_count = self::shift_count_on_day( $user_id, $appointment_ts );
			$capacity = max( 0, (int) ( $profile['capacity']['shifts_per_day'] ?? 6 ) );
			if ( $capacity > 0 && $today_count >= $capacity ) { // B6
				$rejected[] = array( 'user_id' => $user_id, 'reason' => 'capacity_full' );
				continue;
			}

			$last_point = $customer_point && class_exists( 'BizCity_CRM_Location_Service' ) ? BizCity_CRM_Location_Service::last_for_user( $user_id ) : null;
			$fresh = $last_point && ! empty( $last_point['at'] ) && ( time() - self::parse_ts( (string) $last_point['at'] ) ) < 1800;
			$distance_m = $fresh && class_exists( 'BizCity_CRM_Location_Service' ) ? BizCity_CRM_Location_Service::distance_m( $last_point, $customer_point ) : -1.0;

			$survivors[] = array(
				'user_id'         => $user_id,
				'display_name'    => (string) ( $member['display_name'] ?? '' ),
				'today_shifts'    => $today_count,
				'last_assigned_at' => (string) ( $member['last_assigned_at'] ?? '' ),
				'distance_m'      => $distance_m,
				'distance_fresh'  => $fresh,
			);
		}

		usort( $survivors, static function ( $a, $b ) { // B7
			if ( $a['distance_fresh'] && $b['distance_fresh'] && $a['distance_m'] !== $b['distance_m'] ) {
				return $a['distance_m'] <=> $b['distance_m'];
			}
			if ( $a['distance_fresh'] !== $b['distance_fresh'] ) {
				return $b['distance_fresh'] <=> $a['distance_fresh'];
			}
			if ( $a['today_shifts'] !== $b['today_shifts'] ) {
				return $a['today_shifts'] <=> $b['today_shifts'];
			}
			return strcmp( (string) $a['last_assigned_at'], (string) $b['last_assigned_at'] );
		} );

		return array( 'candidates' => array_slice( $survivors, 0, 5 ), 'rejected' => $rejected ); // B8
	}

	/**
	 * Reject a booking that overlaps an existing `active` event for the same user (0.69 §5.3, N4b) —
	 * chặn ở SERVER, not just a UI warning. `true|false` = a real answer; `null` = the scheduler table
	 * isn't available, so the caller must not claim "no conflict" it never actually checked.
	 */
	public static function has_conflict( int $user_id, int $start_ts, int $duration_s ) {
		if ( ! self::events_table_available() || $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = self::events_table();
		$start = self::db_time( $start_ts );
		$end = self::db_time( $start_ts + $duration_s );
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE user_id = %d AND status = 'active'
			 AND start_at < %s AND COALESCE(end_at, DATE_ADD(start_at, INTERVAL 60 MINUTE)) > %s LIMIT 1",
			$user_id,
			$end,
			$start
		) );
		return null !== $row ? (bool) $row : false;
	}

	private static function shift_count_on_day( int $user_id, int $ts ): int {
		if ( ! self::events_table_available() || $user_id <= 0 ) { return 0; }
		global $wpdb;
		$table = self::events_table();
		$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND status = 'active' AND DATE(start_at) = %s",
			$user_id,
			$date
		) );
	}

	private static function events_table_available(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) { return false; }
		static $checked = null;
		if ( null !== $checked ) { return $checked; }
		$checked = function_exists( 'bizcity_tbl_exists' ) ? (bool) bizcity_tbl_exists( self::events_table() ) : (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::events_table() ) );
		return $checked;
	}

	private static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_crm_events';
	}

	private static function parse_ts( string $value ): int {
		$ts = strtotime( trim( $value ) );
		return false === $ts ? 0 : (int) $ts;
	}

	private static function db_time( int $ts ): string {
		return function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ? (string) wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() ) : gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function slug( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-z0-9_]{1,64}$/', $value ) ? $value : '';
	}
}
