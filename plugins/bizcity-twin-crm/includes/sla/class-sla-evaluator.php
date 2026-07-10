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
	const SCHEDULE_KEY = 'bizcity_crm_minute';
	const LOCK_KEY     = 'bizcity_crm_sla_lock';
	const LOCK_TTL     = 90;

	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE_KEY, self::CRON_HOOK );
		}
	}

	public static function cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) { $schedules = array(); }
		if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
			$schedules[ self::SCHEDULE_KEY ] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute (BizCity CRM)', 'bizcity-twin-crm' ),
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
			BizCity_CRM_Repository::update_applied_sla_fields( (int) $row['id'], array(
				'state'             => 'met',
				'met_at'            => $now,
				'last_evaluated_at' => $now,
			) );
			BizCity_CRM_Event_Emitter::emit( 'crm_sla_met', array(
				'conversation_id' => $conv_id,
				'sla_policy_id'   => (int) $row['sla_policy_id'],
				'applied_sla_id'  => (int) $row['id'],
				'resolved_at'     => $now,
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
				// Add back the closed-seconds inside [applied_at, naive_due) so
				// the *effective* business-hour budget equals threshold.
				$closed   = BizCity_CRM_Working_Hours::closed_seconds_between( $inbox_id, $applied_at, $naive_due );
				$shifted  = $naive_due + $closed;
				$out[ $kind . '_due_at' ] = $shifted;
			} else {
				$out[ $kind . '_due_at' ] = $naive_due;
			}
		}
		return $out;
	}
}
