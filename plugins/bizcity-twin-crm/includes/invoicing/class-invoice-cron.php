<?php
/**
 * BizCity CRM — Invoice Cron (PHASE 0.35 M-CRM.M2).
 *
 * Hourly tick: flag SENT invoices past their due_date as OVERDUE.
 * Idempotent + lock-guarded.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Invoice_Cron {

	const HOOK         = 'bizcity_crm_invoice_overdue_tick';
	const LOCK_KEY     = 'bizcity_crm_invoice_overdue_lock';
	const LOCK_TTL_SEC = 300;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function run(): void {
		// Single-runner lock.
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL_SEC );
		try {
			if ( class_exists( 'BizCity_CRM_Invoice_Repository' ) ) {
				BizCity_CRM_Invoice_Repository::mark_overdue_now();
			}
		} catch ( \Throwable $e ) {
			error_log( '[bizcity-crm] invoice overdue tick failed: ' . $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}
}
