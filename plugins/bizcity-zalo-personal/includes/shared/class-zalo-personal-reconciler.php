<?php
/**
 * BizCity Zalo Personal — periodic session reconciliation (PHASE-0.60).
 *
 * Real incident (2026-09-19): a phone number was re-scanned into a second WordPress site sharing
 * the same Hub API key. The first site kept showing "Đang kết nối" indefinitely — the cross-site
 * takeover webhook (`BizCity_Zalo_Bridge_REST::handle_session_event()`) is fire-and-forget with no
 * retry/ack from the sidecar, and the only other place that ever asks the Hub "is this account
 * still really connected" is `handle_qr_status()`, which only runs when a human opens that exact
 * account's QR/relogin sheet. Nobody did, on the first site, after the takeover happened elsewhere.
 * There was no backstop.
 *
 * This tick is that backstop: on a schedule, re-ask the Hub about accounts this site believes are
 * `connected`, reusing the EXACT same detection `handle_qr_status()` already has (including the
 * `managed_account_other_site` branch) via {@see BizCity_Zalo_Bridge_REST::sync_account_status()}
 * — no second copy of that logic.
 *
 * Off by default (R-CRON-OVERLOAD precedent — see `BizCity_Broadcast_Dispatcher`): a new recurring
 * tick added to shared plugin code runs on EVERY tenant site, so it must not silently start
 * consuming Hub API budget tenant-wide the moment this file ships. Turn on per-site with
 * `update_option( 'bizcity_zp_reconcile_enabled', 1 )` or the `bizcity_zp_reconcile_enabled` filter.
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.60 2026-09-19
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Personal_Reconciler' ) ) {
	return;
}

final class BizCity_Zalo_Personal_Reconciler {

	const CRON_HOOK     = 'bizcity_zp_reconcile_tick';
	const CRON_INTERVAL = 'bizcity_zp_reconcile_15min';
	const OPT_ENABLED   = 'bizcity_zp_reconcile_enabled';
	/** Hub status calls per tick — bounds worst-case load on a site with many connected numbers. */
	const BATCH_SIZE    = 20;

	/** Register cron hook + schedule event. Called from bootstrap at file-load time (matches R-CR.1 convention). */
	public static function init_cron(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		if ( ! self::is_enabled() ) {
			self::unschedule();
			return;
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		$cur  = $next ? (string) wp_get_schedule( self::CRON_HOOK ) : '';
		if ( $next && $cur !== self::CRON_INTERVAL ) {
			self::unschedule();
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	private static function is_enabled(): bool {
		$enabled = (bool) get_option( self::OPT_ENABLED, false );
		/** @param bool $enabled */
		return (bool) apply_filters( self::OPT_ENABLED, $enabled );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'Every 15 Minutes (BizCity Zalo Personal Reconcile)',
			);
		}
		return $schedules;
	}

	/**
	 * One sweep: the `self::BATCH_SIZE` locally-`connected` accounts that were checked longest ago
	 * (`ORDER BY updated_at ASC`). `sync_account_status()` bumps `updated_at` on every call whose
	 * status resolves to `connected`/`expired`/`logged_out` — including "still connected, nothing
	 * changed" — so successfully-checked rows naturally move to the back of the queue and every
	 * connected account gets swept in turn, without a separate "last checked" column.
	 */
	public static function tick(): void {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, bridge_account_id FROM `{$table}` WHERE kind = 'personal' AND status = 'connected' ORDER BY updated_at ASC LIMIT %d",
			self::BATCH_SIZE
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) { return; }
		foreach ( $rows as $row ) {
			$bridge_id = (string) ( $row['bridge_account_id'] ?? '' );
			if ( $bridge_id === '' ) { continue; }
			try {
				BizCity_Zalo_Bridge_REST::sync_account_status( $bridge_id );
			} catch ( \Throwable $e ) {
				// One account's Hub call failing must never stop the rest of the sweep.
				continue;
			}
		}
	}
}
