<?php
/**
 * Bizcity TwinChat — Learning Quota Cooldown
 *
 * SaaS pro-tier pattern: when a user hits the daily KG-Hub quota (free 50 / day,
 * pro = higher, exempt = unlimited), the learning pipeline must STOP looping on
 * the same passage every 3 seconds. Instead:
 *
 *   1. Set a transient `block_user_<id>` with the *real* quota-reset timestamp
 *      (UTC midnight by default — matches `BizCity_KG_Cost_Guard::today()`).
 *   2. Pause the offending job by stamping `restartable_at` so cron / ajax tick
 *      skip it cheaply until cooldown elapses.
 *   3. Emit ONE structured `quota_exhausted` learning event so the FE banner
 *      can render "Hết quota — nâng cấp Pro hoặc đợi đến HH:MM" + retry button.
 *   4. On every subsequent tick, do a cheap transient-read; if the cooldown
 *      window passes we re-call `BizCity_KG_Cost_Guard::can_extract()` — if the
 *      user upgraded plan / admin bumped quota / midnight reset, the block
 *      clears automatically. No log spam, no LLM call.
 *
 * NOT a schema change — re-uses the existing `restartable_at` column on
 * `*_bizcity_twinchat_learning_jobs` (R-DCL-safe).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since      2026-05-26 (PHASE-0.7 Wave Pro-Tier Cooldown)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Quota_Cooldown {

	/** Transient key prefix — per-user, per-blog (transient is blog-scoped). */
	const TRANSIENT_PREFIX = 'biz_tc_qcool_';

	/** Max cooldown TTL hard cap (24h) so even if midnight calc breaks we self-heal. */
	const MAX_TTL_S = DAY_IN_SECONDS;

	/** Minimum cooldown so a single quota burst can't spawn 1000 short blocks. */
	const MIN_TTL_S = 300; // 5 min

	/**
	 * Is the user currently in a quota cooldown?
	 *
	 * Returns:
	 *   null              — no block
	 *   array{            — blocked
	 *     code:        string ('quota_exceeded'|'cap_exceeded'),
	 *     reason:      string (human readable),
	 *     retry_after: int    (unix ts),
	 *     used:        int,
	 *     cap:         int,
	 *   }
	 */
	public static function get_block( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$payload = get_transient( self::key( $user_id ) );
		if ( ! is_array( $payload ) || empty( $payload['retry_after'] ) ) {
			return null;
		}
		if ( (int) $payload['retry_after'] <= time() ) {
			// Cooldown window elapsed — proactively re-check the cost guard.
			// If user upgraded / quota reset, clear the block.
			if ( self::is_quota_available_again( $user_id ) ) {
				delete_transient( self::key( $user_id ) );
				return null;
			}
			// Still blocked — refresh the window with a fresh midnight target.
			return self::apply_block( $user_id, $payload['code'] ?? 'quota_exceeded', $payload['reason'] ?? 'Quota still exceeded' );
		}
		return $payload;
	}

	/**
	 * Apply / refresh a quota block for the given user.
	 *
	 * @param int    $user_id
	 * @param string $code     'quota_exceeded' | 'cap_exceeded'
	 * @param string $reason   Human-readable reason from WP_Error
	 * @return array Block payload (same shape as get_block()).
	 */
	public static function apply_block( int $user_id, string $code, string $reason ): array {
		$retry_after = self::seconds_until_daily_reset();
		// Clamp.
		$retry_after = max( self::MIN_TTL_S, min( self::MAX_TTL_S, $retry_after ) );

		[ $used, $cap ] = self::current_quota_snapshot( $user_id );

		$payload = [
			'code'        => $code,
			'reason'      => $reason,
			'retry_after' => time() + $retry_after,
			'used'        => $used,
			'cap'         => $cap,
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'blocked_at'  => time(),
		];

		set_transient( self::key( $user_id ), $payload, $retry_after );

		// Allow other modules (FE banner pump, admin notice) to react.
		do_action( 'bizcity_twinchat_quota_blocked', $user_id, $payload );

		return $payload;
	}

	/**
	 * Clear an active block (call after the user upgrades plan / admin grants
	 * extra quota / midnight reset is forced).
	 */
	public static function clear( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_transient( self::key( $user_id ) );
			do_action( 'bizcity_twinchat_quota_unblocked', $user_id );
		}
	}

	/**
	 * Convenience: probe BizCity_KG_Cost_Guard. If the user is now under quota
	 * again (upgrade / reset), the block is cleared and true is returned.
	 *
	 * IMPORTANT: this probes the RAW counters (not `can_extract()`) so we do
	 * NOT auto-unblock admins / exempt users — for learning cron the per-user
	 * daily cap is binding regardless of role.
	 */
	public static function is_quota_available_again( int $user_id ): bool {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			return true;
		}
		$cg   = BizCity_KG_Cost_Guard::instance();
		$cap  = (int) $cg->quota_per_user();
		$used = method_exists( $cg, 'user_passages_today' ) ? (int) $cg->user_passages_today( $user_id ) : 0;
		if ( $used + 1 > $cap ) {
			return false;
		}
		// Also respect the site-wide USD cap.
		if ( method_exists( $cg, 'daily_cap_usd' ) && method_exists( $cg, 'spent_today_usd' ) ) {
			$site_cap   = (float) $cg->daily_cap_usd();
			$site_spent = (float) $cg->spent_today_usd();
			if ( $site_cap > 0 && $site_spent >= $site_cap ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compute seconds until the daily quota bucket flips.
	 *
	 * BizCity_KG_Cost_Guard buckets by `today()` which uses gmdate('Y-m-d'),
	 * i.e. the UTC day. So the reset boundary is UTC midnight.
	 */
	public static function seconds_until_daily_reset(): int {
		$now      = time();
		$midnight = strtotime( 'tomorrow', $now ); // server-local midnight
		// Prefer UTC midnight since cost guard buckets are UTC.
		$utc_now      = (int) gmdate( 'U', $now );
		$utc_midnight = strtotime( gmdate( 'Y-m-d', $utc_now ) . ' 00:00:00 UTC' ) + DAY_IN_SECONDS;
		return max( 60, $utc_midnight - $now );
	}

	/* ── internals ──────────────────────────────────────────────────── */

	private static function key( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}

	/**
	 * Snapshot current quota usage (best-effort). Returns [used, cap].
	 */
	private static function current_quota_snapshot( int $user_id ): array {
		$used = 0;
		$cap  = 0;
		if ( $user_id > 0 && class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$cg   = BizCity_KG_Cost_Guard::instance();
			$cap  = (int) $cg->quota_per_user();
			if ( method_exists( $cg, 'user_passages_today' ) ) {
				$used = (int) $cg->user_passages_today( $user_id );
			}
		}
		return [ $used, $cap ];
	}
}
