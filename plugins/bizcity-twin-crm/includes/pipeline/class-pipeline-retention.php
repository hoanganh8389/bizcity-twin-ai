<?php
/**
 * BizCity CRM — deadline retention (PHASE-0.63A WP-0.8, lane S).
 *
 * R-SPINE-9: the deadline queue is working state, not evidence. Every rung that ever fired is already
 * in `bizcity_crm_audit_log` and `reporting_events`, so a finished row (`met` / `cancelled`) has no
 * reason to sit in the hot table where the 60s runner range-scans. Delete it after 30 days.
 *
 * Registered through `BizCity_Cron_Manager` — a bare `wp_schedule_event()` would leave the job without
 * a run record, and R-CRON-META is explicit that no meta means the task is not done.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-0.8)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Retention', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Retention {

	const JOB_ID   = 'crm_pipeline_deadline_retention';
	const HOOK     = 'bizcity_crm_pipeline_deadline_retention';
	const INTERVAL = 'daily';
	const OPTION_DAYS = 'bizcity_crm_pipeline_deadline_retention_days';
	const DEFAULT_DAYS = 30;
	const BATCH = 500;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			// No cron manager on this deployment: skip rather than schedule an untracked job.
			return;
		}

		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::HOOK,
			'interval'    => self::INTERVAL,
			'owner'       => 'bizcity-twin-crm',
			'description' => 'Drop finished pipeline SLA deadlines older than the retention window (PHASE-0.63A WP-0.8)',
			'singleton'   => true,
			'enabled'     => true,
			'retention'   => 14,
		) );
	}

	public static function run(): void {
		global $wpdb;
		if ( ! $wpdb || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}

		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		if ( $cron && method_exists( $cron, 'try_lock' ) && ! $cron->try_lock( self::JOB_ID, 900 ) ) {
			return;
		}

		$days = (int) get_option( self::OPTION_DAYS, self::DEFAULT_DAYS );
		if ( $days < 1 ) {
			$days = self::DEFAULT_DAYS;
		}

		$table  = BizCity_CRM_DB_Installer_V2::tbl_pipeline_deadlines();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = 0;
		for ( $pass = 0; $pass < 10; $pass++ ) {
			$rows = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM `{$table}` WHERE state IN ('met','cancelled') AND updated_at < %s LIMIT %d",
				$cutoff,
				self::BATCH
			) );
			$deleted += $rows;
			if ( $rows < self::BATCH ) {
				break;
			}
		}

		// Kept as a counter even when zero: R-CRON-META wants evidence the pass ran, not just that it found work.
		if ( $cron ) {
			$cron->note( array(
				'counters' => array(
					'deleted'        => $deleted,
					'retention_days' => $days,
				),
			) );
			if ( $deleted > 0 ) {
				$cron->note_event( 'pipeline_deadlines_pruned', array(
					'deleted' => $deleted,
					'cutoff'  => $cutoff,
				) );
			}
		}
	}
}
