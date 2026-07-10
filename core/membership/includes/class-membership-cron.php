<?php
/**
 * Bizcity Twin AI — Membership_Cron
 *
 * PHASE-MEMBERSHIP M7.
 *
 * Daily expiry sweep for paid membership plans. Finds active subscriptions
 * whose expiration_date has passed and downgrades the owner to the default
 * (free) plan. Without this, a one-time/recurring purchase would never lapse
 * and the buyer would keep paid limits forever.
 *
 * Prefers the unified core/cron manager so each run is traced into
 * bizcity_cron_runs and writes R-CRON-META evidence (counters + fail buckets);
 * falls back to a plain wp_schedule_event on sites where the manager is absent.
 *
 * Money note: membership revenue is the CLIENT's own PayPal (R-GW-8). This
 * sweep never talks to bizcity-llm-router.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Cron {

	const HOOK     = 'bizcity_membership_expiry_sweep';
	const JOB_ID   = 'membership.expiry';
	const INTERVAL = 'daily';
	const BATCH    = 200;

	/** @var BizCity_Membership_Cron|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the hook + ensure the schedule exists.
	 *
	 * @return void
	 */
	public static function init() {
		$self = self::instance();
		add_action( self::HOOK, array( $self, 'run_sweep' ) );
		add_action( 'init', array( $self, 'schedule' ) );
	}

	/**
	 * Ensure the daily sweep is scheduled. Uses the unified cron manager when
	 * available (adds tracing + meta), else a direct wp_schedule_event.
	 *
	 * @return void
	 */
	public function schedule() {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — register daily expiry sweep.
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => self::JOB_ID,
				'hook'        => self::HOOK,
				'interval'    => self::INTERVAL,
				'owner'       => 'core/membership',
				'description' => 'Expire lapsed membership plans → downgrade to free (daily).',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 14,
			) );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Remove the schedule (deactivation hook).
	 *
	 * @return void
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * The sweep itself. Idempotent; safe to run repeatedly.
	 *
	 * @return void
	 */
	public function run_sweep() {
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			$this->note_event( 'membership_sweep_skipped', array( 'reason' => 'manager_missing' ) );
			return;
		}

		$manager = BizCity_Membership_Manager::instance();

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — R-CRON-META evidence.
		try {
			$summary = $manager->expire_due( self::BATCH );
		} catch ( \Throwable $e ) {
			$this->note_event( 'membership_sweep_failed', array(
				'reason' => 'expire_due_error',
				'error'  => $e->getMessage(),
			) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Membership] expiry sweep failed: ' . $e->getMessage() );
			}
			return;
		}

		$scanned = isset( $summary['scanned'] ) ? (int) $summary['scanned'] : 0;
		$expired = isset( $summary['expired'] ) ? (int) $summary['expired'] : 0;
		$errors  = isset( $summary['errors'] ) ? (int) $summary['errors'] : 0;

		// [2026-07-17 Johnny Chu] PHASE-D G-1 — fire plan_expired action for each expired user.
		$expired_user_ids = isset( $summary['user_ids'] ) ? array_map( 'intval', (array) $summary['user_ids'] ) : array();
		foreach ( $expired_user_ids as $uid ) {
			do_action( 'bizcity_membership_plan_expired', $uid );
		}

		$this->note( array(
			'sweep' => array(
				'scanned'  => $scanned,
				'expired'  => $expired,
				'errors'   => $errors,
				'user_ids' => $expired_user_ids,
			),
			'counters' => array(
				'membership_expired' => $expired,
			),
		) );

		if ( $errors > 0 ) {
			$this->note_event( 'membership_sweep_partial', array(
				'reason'  => 'row_error',
				'errors'  => $errors,
				'scanned' => $scanned,
			) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $expired > 0 ) {
			error_log( sprintf( '[Membership] expiry sweep: %d/%d plans downgraded to free.', $expired, $scanned ) );
		}

		// [2026-07-17 Johnny Chu] PHASE-D G-6 — send expiry warning for plans expiring in 7 days.
		$this->send_expiry_warnings();
	}

	/**
	 * Find users whose plan expires in exactly 7 days and fire expiry warning action.
	 * Runs once per day as part of the daily sweep.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-6 — expiry warning email (7-day notice).
	 *
	 * @return void
	 */
	public function send_expiry_warnings() {
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return;
		}

		$warn_at_days = array( 7, 3, 1 ); // fire once at each threshold
		foreach ( $warn_at_days as $days ) {
			$date_str    = gmdate( 'Y-m-d', strtotime( '+' . $days . ' days' ) );
			$uids        = BizCity_Membership_Manager::instance()->users_expiring_on( $date_str );

			foreach ( (array) $uids as $uid ) {
				do_action( 'bizcity_membership_expiry_warning', (int) $uid, $days );
			}
		}
	}

	/* ── R-CRON-META helpers ────────────────────────────────────────────── */

	private function note( array $patch ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( $patch );
		}
	}

	private function note_event( $name, array $data ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( (string) $name, $data );
		}
	}
}
