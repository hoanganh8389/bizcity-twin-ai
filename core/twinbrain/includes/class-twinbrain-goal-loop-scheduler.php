<?php
/**
 * Stale Goal Loop scanner for dormant/abandoned policy.
 *
 * Dormant is a derived read label. Abandoned is the only inactivity transition
 * persisted as a terminal Goal Loop snapshot.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Scheduler {

	const JOB_ID = 'twinbrain.goal_loop_stale_scan';
	const HOOK = 'bizcity_twinbrain_goal_loop_stale_scan';
	const INTERVAL = 'bizcity_twinbrain_6hours';
	const DEFAULT_DORMANT_AFTER_HOURS = 48;
	const DEFAULT_ABANDONED_AFTER_DAYS = 14;
	const MAX_EVENTS_PER_SCAN = 1000;
	const SCAN_PAGES = 5;

	private static $initialized = false;

	public static function init(): void {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G7 — bind one idempotent cron registration and handler.
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK, array( __CLASS__, 'scan_stale_goals' ) );
		add_action( 'init', array( __CLASS__, 'register_cron' ), 20 );
	}

	public static function add_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => 'Every 6 Hours (Twin Goal Loop)',
			);
		}
		return $schedules;
	}

	public static function register_cron(): void {
		// [2026-08-02 Johnny Chu] R-CRON-META — register through the central Cron Manager; do not schedule a private fallback.
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::HOOK,
			'interval'    => self::INTERVAL,
			'owner'       => 'core/twinbrain',
			'description' => 'Derive dormant goals and close inactive Goal Loop snapshots as abandoned.',
			'singleton'   => true,
			'enabled'     => true,
			'retention'   => 30,
		) );
	}

	public static function scan_stale_goals(): void {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G7 — scan current tenant event stream in a bounded batch and persist only valid abandoned transitions.
		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		if ( $cron && $cron->is_locked_out( self::JOB_ID ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) || ! class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) || ! class_exists( 'BizCity_TwinBrain_Goal_Loop_State' ) ) {
			if ( $cron ) {
				$cron->note_event( 'goal_loop_scan_skipped', array( 'reason' => 'dependency_missing' ), self::JOB_ID );
			}
			return;
		}

		$blog_id = (int) get_current_blog_id();
		$rows = self::latest_goal_events( $blog_id );
		$counters = array(
			'scanned'   => 0,
			'dormant'   => 0,
			'abandoned' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
		$now = time();
		$dormant_after = self::dormant_after_hours() * HOUR_IN_SECONDS;
		$abandoned_after = self::abandoned_after_days() * DAY_IN_SECONDS;

		foreach ( $rows as $row ) {
			$counters['scanned']++;
			$state = self::state_from_row( $row, $blog_id );
			if ( empty( $state ) || BizCity_TwinBrain_Goal_Loop_State::is_terminal( (string) ( $state['status'] ?? '' ) ) ) {
				$counters['skipped']++;
				continue;
			}
			$last_activity = self::last_activity_epoch( $state, $row );
			$age = $last_activity > 0 ? max( 0, $now - $last_activity ) : 0;
			if ( $age >= $dormant_after ) {
				$counters['dormant']++;
			}
			if ( $age < $abandoned_after ) {
				continue;
			}

			$state['status'] = 'abandoned';
			$state['closure_signal'] = array(
				'type'       => BizCity_TwinBrain_Goal_Loop_State::CLOSURE_INACTIVITY,
				'evidence'   => 'cron_inactivity_timeout',
				'created_at' => gmdate( 'c' ),
			);
			$event_uuid = BizCity_TwinBrain_Goal_Loop_Repository::close( $state, array(
				'identity_uuid' => (string) ( $state['identity_uuid'] ?? '' ),
				'blog_id'       => $blog_id,
				'user_id'       => (int) ( $state['user_id'] ?? 0 ),
				'session_id'    => (string) ( $state['session_id'] ?? '' ),
				'event_source'  => 'twinbrain',
			) );
			if ( $event_uuid === '' ) {
				$counters['errors']++;
				if ( $cron ) {
					$cron->note_event( 'goal_loop_abandoned_failed', array(
						'goal_id' => (string) ( $state['goal_id'] ?? '' ),
						'reason'  => 'invalid_transition_or_event_write',
					), self::JOB_ID );
				}
				continue;
			}
			$counters['abandoned']++;
			if ( $cron ) {
				$cron->note_event( 'goal_loop_abandoned', array(
					'goal_id'    => (string) ( $state['goal_id'] ?? '' ),
					'event_uuid' => $event_uuid,
					'reason'     => 'inactivity_timeout',
				), self::JOB_ID );
			}
		}

		if ( $cron ) {
			$cron->note( array(
				'goal_loop_stale_scan' => array(
					'blog_id'                 => $blog_id,
					'counters'                => $counters,
					'dormant_after_hours'     => self::dormant_after_hours(),
					'abandoned_after_days'    => self::abandoned_after_days(),
					'event_batch_limit'       => self::MAX_EVENTS_PER_SCAN,
				),
			), self::JOB_ID );
		}
	}

	private static function latest_goal_events( int $blog_id ): array {
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$latest = array();
		$cursor_id = PHP_INT_MAX;
		for ( $page = 0; $page < self::SCAN_PAGES; $page++ ) {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G7 — bounded keyset scan prevents one recent-event window from hiding older active goals without allowing an unbounded cron query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_type, payload_json, session_id, created_at, created_epoch_ms FROM {$table} WHERE blog_id = %d AND id < %d AND event_type IN (%s, %s, %s) ORDER BY id DESC LIMIT %d",
					$blog_id,
					$cursor_id,
					'twin_goal_opened',
					'twin_goal_progressed',
					'twin_goal_closed',
					self::MAX_EVENTS_PER_SCAN
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				$state = is_array( $payload ) && is_array( $payload['state'] ?? null ) ? $payload['state'] : $payload;
				if ( ! is_array( $state ) ) {
					continue;
				}
				$goal_id = (string) ( $state['goal_id'] ?? $payload['goal_id'] ?? '' );
				$identity_uuid = strtolower( trim( (string) ( $payload['identity_uuid'] ?? $state['identity_uuid'] ?? '' ) ) );
				if ( $goal_id === '' || $identity_uuid === '' || isset( $latest[ $goal_id ] ) ) {
					continue;
				}
				$latest[ $goal_id ] = $row;
			}
			$last_row = end( $rows );
			$last_id = (int) ( $last_row['id'] ?? 0 );
			if ( $last_id <= 0 || count( $rows ) < self::MAX_EVENTS_PER_SCAN ) {
				break;
			}
			$cursor_id = $last_id;
		}
		return array_values( $latest );
	}

	private static function state_from_row( array $row, int $blog_id ): array {
		$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
		$state['blog_id'] = $blog_id;
		$state['session_id'] = (string) ( $row['session_id'] ?? $state['session_id'] ?? '' );
		$state['identity_uuid'] = strtolower( trim( (string) ( $payload['identity_uuid'] ?? $state['identity_uuid'] ?? '' ) ) );
		return BizCity_TwinBrain_Goal_Loop_State::normalize( $state );
	}

	private static function last_activity_epoch( array $state, array $row ): int {
		$updated_at = strtotime( (string) ( $state['updated_at'] ?? '' ) );
		if ( $updated_at > 0 ) {
			return $updated_at;
		}
		$created_at = strtotime( (string) ( $row['created_at'] ?? '' ) );
		return $created_at > 0 ? $created_at : (int) floor( (int) ( $row['created_epoch_ms'] ?? 0 ) / 1000 );
	}

	private static function dormant_after_hours(): int {
		$value = (int) apply_filters( 'bizcity_twinbrain_goal_dormant_after_hours', self::DEFAULT_DORMANT_AFTER_HOURS );
		return max( 1, min( 8760, $value ) );
	}

	private static function abandoned_after_days(): int {
		$value = (int) apply_filters( 'bizcity_twinbrain_goal_abandoned_after_days', self::DEFAULT_ABANDONED_AFTER_DAYS );
		return max( 1, min( 3650, $value ) );
	}
}
