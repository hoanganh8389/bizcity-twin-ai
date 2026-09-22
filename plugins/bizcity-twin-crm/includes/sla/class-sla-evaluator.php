<?php
/**
 * BizCity CRM — SLA Evaluator (PHASE 0.35 M4.W3).
 *
 * Owns the cron tick that walks `applied_slas` rows and:
 *   • emits `crm_sla_breached` when now > FRT/NRT/RT due
 *   • emits `crm_sla_met` when conversation resolved before threshold
 *
 * Single-host lock via transient (Risk §7) — the cron lock is held for 90s
 * so a slow tick can't double-fire on overlapping wp-cron runs.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M4.W3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_SLA_Evaluator {

	const CRON_HOOK    = 'bizcity_crm_sla_tick';
	// [2026-07-26 Johnny Chu] CRON-OVERLOAD-OPTIMIZE — reduce SLA tick from 1 minute to 3 minutes.
	const SCHEDULE_KEY = 'bizcity_crm_3min';
	const INTERVAL_SEC = 180;
	const LOCK_KEY     = 'bizcity_crm_sla_lock';
	const LOCK_TTL     = 90;

	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );

		$next = wp_next_scheduled( self::CRON_HOOK );
		$cur  = $next ? (string) wp_get_schedule( self::CRON_HOOK ) : '';
		if ( ! $next ) {
			wp_schedule_event( time() + 30, self::SCHEDULE_KEY, self::CRON_HOOK );
			return;
		}

		// [2026-07-26 Johnny Chu] CRON-OVERLOAD-OPTIMIZE — migrate old per-minute schedule.
		if ( $cur !== self::SCHEDULE_KEY ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( time() + 30, self::SCHEDULE_KEY, self::CRON_HOOK );
		}
	}

	public static function cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) { $schedules = array(); }
		if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
			$schedules[ self::SCHEDULE_KEY ] = array(
				'interval' => self::INTERVAL_SEC,
				'display'  => __( 'Every 3 Minutes (BizCity CRM)', 'bizcity-twin-crm' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cron entry point. Returns evaluation summary for diag/REST callers.
	 *
	 * @param bool $force Bypass lock (used by "Force tick" diag button).
	 */
	public static function tick( bool $force = false ): array {
		if ( ! $force ) {
			if ( get_transient( self::LOCK_KEY ) ) {
				return array( 'skipped' => true, 'reason' => 'locked' );
			}
			set_transient( self::LOCK_KEY, (string) wp_generate_uuid4(), self::LOCK_TTL );
		}
		$evaluated = 0;
		$breached  = 0;
		$met       = 0;
		try {
			$rows = BizCity_CRM_Repository::list_active_applied_slas( 500 );
			$now  = time();
			foreach ( $rows as $row ) {
				$evaluated++;
				$res = self::evaluate_row( $row, $now );
				if ( ! empty( $res['breached'] ) ) { $breached += (int) $res['breached']; }
				if ( ! empty( $res['met'] ) )      { $met++; }
			}
		} finally {
			if ( ! $force ) { delete_transient( self::LOCK_KEY ); }
		}
		return array( 'skipped' => false, 'evaluated' => $evaluated, 'breached' => $breached, 'met' => $met );
	}

	/**
	 * Evaluate a single applied_slas row. Pure-ish: only writes when state
	 * needs to change. Emits SLA events for each transition.
	 */
	public static function evaluate_row( array $row, int $now ): array {
		$conv_id = (int) $row['conversation_id'];
		$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
		if ( ! $conv ) {
			// Conversation deleted: drop tracking row to inactive state.
			BizCity_CRM_Repository::update_applied_sla_fields( (int) $row['id'], array(
				'state'             => 'cancelled',
				'last_evaluated_at' => $now,
			) );
			return array( 'cancelled' => true );
		}

		// MET path: conversation resolved.
		if ( ( $conv['status'] ?? '' ) === 'resolved' && empty( $row['met_at'] ) ) {
			$resolved_at = self::resolved_at( $conv, $now );
			$rt_due = isset( $row['rt_due_at'] ) ? (int) $row['rt_due_at'] : 0;
			if ( $rt_due > 0 && $resolved_at > $rt_due ) {
				BizCity_CRM_Repository::update_applied_sla_fields( (int) $row['id'], array( 'state' => 'breached', 'last_evaluated_at' => $now ) );
				return array( 'breached' => 0, 'met' => false );
			}
			BizCity_CRM_Repository::update_applied_sla_fields( (int) $row['id'], array(
				'state'             => 'met',
				'met_at'            => $resolved_at,
				'last_evaluated_at' => $now,
			) );
			BizCity_CRM_Event_Emitter::emit( 'crm_sla_met', array(
				'conversation_id' => $conv_id,
				'sla_policy_id'   => (int) $row['sla_policy_id'],
				'applied_sla_id'  => (int) $row['id'],
				'resolved_at'     => $resolved_at,
			) );
			return array( 'met' => true );
		}

		// BREACH path: check FRT/NRT/RT thresholds, fire-once each.
		$breach_count = 0;
		$updates      = array( 'last_evaluated_at' => $now );
		foreach ( array( 'frt', 'nrt', 'rt' ) as $kind ) {
			$due_field   = $kind . '_due_at';
			$breach_field = $kind . '_breached_at';
			$due  = isset( $row[ $due_field ] ) ? (int) $row[ $due_field ] : 0;
			$prev = isset( $row[ $breach_field ] ) ? (int) $row[ $breach_field ] : 0;
			if ( $due > 0 && $prev === 0 && $now >= $due ) {
				$updates[ $breach_field ] = $now;
				$breach_count++;
				BizCity_CRM_Event_Emitter::emit( 'crm_sla_breached', array(
					'conversation_id' => $conv_id,
					'sla_policy_id'   => (int) $row['sla_policy_id'],
					'applied_sla_id'  => (int) $row['id'],
					'kind'            => $kind, // 'frt' | 'nrt' | 'rt'
					'due_at'          => $due,
					'breached_at'     => $now,
					'overdue_seconds' => $now - $due,
				) );
			}
		}
		if ( $breach_count > 0 ) {
			$updates['state'] = 'breached';
		}
		if ( 'breached' === (string) ( $row['state'] ?? '' ) && 0 === $breach_count ) {
			$updates['state'] = 'active';
		}
		BizCity_CRM_Repository::update_applied_sla_fields( (int) $row['id'], $updates );
		return array( 'breached' => $breach_count );
	}

	/**
	 * Compute due timestamps from a policy + conversation. Honors
	 * `only_during_business_hours` by shifting due timestamps forward by
	 * the cumulative closed-seconds in the threshold window.
	 *
	 * @return array{frt_due_at:?int, nrt_due_at:?int, rt_due_at:?int}
	 */
	public static function compute_due_times( array $policy, array $conv, ?int $applied_at = null ): array {
		$applied_at = $applied_at ?? time();
		$inbox_id   = (int) ( $conv['inbox_id'] ?? 0 );
		$bh_only    = ! empty( $policy['only_during_business_hours'] );

		$out = array( 'frt_due_at' => null, 'nrt_due_at' => null, 'rt_due_at' => null );
		foreach ( array( 'frt', 'nrt', 'rt' ) as $kind ) {
			$col = $kind . '_threshold_minutes';
			$min = isset( $policy[ $col ] ) ? (int) $policy[ $col ] : 0;
			if ( $min <= 0 ) { continue; }
			$naive_due = $applied_at + ( $min * 60 );
			if ( $bh_only && $inbox_id > 0 ) {
				$out[ $kind . '_due_at' ] = self::working_due( $inbox_id, $applied_at, $min * 60 );
			} else {
				$out[ $kind . '_due_at' ] = $naive_due;
			}
		}
		return $out;
	}

	private static function working_due( int $inbox_id, int $anchor, int $budget ): int {
		$cursor = $anchor;
		$remaining = $budget;
		for ( $round = 0; $round < 30 && $remaining > 0; $round++ ) {
			$probe = $cursor + $remaining;
			$closed = BizCity_CRM_Working_Hours::closed_seconds_between( $inbox_id, $cursor, $probe );
			$next = $probe + $closed;
			if ( $next === $cursor ) { break; }
			$cursor = $next;
			$elapsed = max( 0, $cursor - $anchor - $closed );
			$remaining = max( 0, $budget - $elapsed );
		}
		return $cursor;
	}

	private static function resolved_at( array $conversation, int $fallback ): int {
		foreach ( array( 'resolved_at', 'closed_at', 'updated_at' ) as $key ) {
			if ( ! empty( $conversation[ $key ] ) ) {
				$value = is_numeric( $conversation[ $key ] ) ? (int) $conversation[ $key ] : strtotime( (string) $conversation[ $key ] );
				if ( $value > 0 ) { return $value; }
			}
		}
		return $fallback;
	}
}
