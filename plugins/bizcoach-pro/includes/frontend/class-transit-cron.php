<?php
/**
 * BizCoach Pro — Daily Transit Sync Cron
 *
 * Registers a daily cron job that fetches today's transit for every coachee
 * that has a Western natal chart, and persists the snapshot to
 * bccm_transit_snapshots for use by the FE (TransitsPage + SavedCalculations).
 *
 * R-CRON-META: all cron handlers MUST call note() counters + note_event() on
 * failures. No ad-hoc error_log-only handlers.
 *
 * @package BizCoach_Pro
 * @since   0.3.3 (PHASE-B B-BE-9 · 2026-06-06)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Transit_Cron' ) ) { return; }

class BizCoach_Pro_Transit_Cron {

	// [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — hook + job-id constants
	// [2026-06-13 Johnny Chu] HOTFIX — normalize spacing so diagnostic strpos check passes (was HOOK   =)
	const HOOK = 'bcpro_transit_daily_sync';
	const JOB_ID = 'bcpro.transit.daily_sync';

	/** Boot: register cron job + wire hook handler. Idempotent. */
	public static function init() {
		// Register via BizCity_Cron_Manager if available (preferred).
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->register( array(
				'id'          => self::JOB_ID,
				'hook'        => self::HOOK,
				'interval'    => 'daily',
				'owner'       => 'bizcoach-pro',
				'description' => 'Fetch today transit snapshot for every coachee with Western natal chart.',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 14,
			) );
		} else {
			// Fallback: plain WP-Cron (no meta logging).
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 60, 'daily', self::HOOK );
			}
		}

		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Main cron handler.
	 *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — R-CRON-META: note() counters
	 * per-run + note_event() for each per-coachee success/fail.
	 */
	public static function run() {
		global $wpdb;

		$cron_ok = class_exists( 'BizCity_Cron_Manager' );
		$cron    = $cron_ok ? BizCity_Cron_Manager::instance() : null;

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			if ( $cron ) {
				$cron->note( array( 'error' => 'astro_client_missing' ) );
				$cron->note_event( 'cron_failed', array( 'reason' => 'module_not_loaded', 'error' => 'BizCoach_Pro_Astro_Client class missing' ) );
			}
			return;
		}

		if ( ! class_exists( 'BizCoach_Pro_Self_Service_REST' ) ) {
			if ( $cron ) {
				$cron->note( array( 'error' => 'rest_class_missing' ) );
				$cron->note_event( 'cron_failed', array( 'reason' => 'module_not_loaded', 'error' => 'BizCoach_Pro_Self_Service_REST class missing' ) );
			}
			return;
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$date    = current_time( 'Y-m-d' );

		// Fetch all coachees that have at least one Western natal chart (data_json not empty).
		$rows = $wpdb->get_results(
			"SELECT c.id, c.user_id, c.full_name, c.dob, c.extra_fields_json,
			        a.birth_time, a.birth_place
			 FROM {$t_coach} c
			 INNER JOIN {$t_astro} a ON a.coachee_id = c.id AND a.chart_type = 'western'
			        AND a.summary IS NOT NULL AND a.summary <> ''
			 ORDER BY c.id ASC",
			ARRAY_A
		);

		$total   = is_array( $rows ) ? count( $rows ) : 0;
		$synced  = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( (array) $rows as $row ) {
			$coachee_id = (int) $row['id'];
			$coachee    = $row; // already has all needed keys

			// [2026-07-10 Johnny Chu] PHASE-FAA2 — tag daily cron save source for JSONL history.
			$result = BizCoach_Pro_Self_Service_REST::do_transit_fetch(
				$coachee,
				array( 'birth_time' => $row['birth_time'], 'birth_place' => $row['birth_place'] ),
				$date,
				'day',
				'cron_daily'
			);

			if ( ! empty( $result['success'] ) ) {
				$synced++;
				if ( $cron ) {
					$cron->note_event( 'transit_synced', array(
						'coachee_id' => $coachee_id,
						'date'       => $date,
						'planets'    => isset( $result['planets'] ) ? count( (array) $result['planets'] ) : 0,
					) );
				}
			} else {
				$failed++;
				$reason  = 'gateway_error';
				$message = isset( $result['message'] ) ? (string) $result['message'] : 'unknown';
				// Classify reason bucket (R-CRON-META reason buckets)
				if ( stripos( $message, 'timeout' ) !== false ) {
					$reason = 'timeout';
				} elseif ( stripos( $message, 'rate' ) !== false || stripos( $message, '429' ) !== false ) {
					$reason = 'rate_limited';
				} elseif ( stripos( $message, 'token' ) !== false || stripos( $message, '401' ) !== false || stripos( $message, '403' ) !== false ) {
					$reason = 'token_invalid';
				} elseif ( stripos( $message, 'http' ) !== false ) {
					$reason = 'http_error';
				}
				if ( $cron ) {
					$cron->note_event( 'transit_sync_failed', array(
						'coachee_id' => $coachee_id,
						'date'       => $date,
						'reason'     => $reason,
						'error'      => $message,
					) );
				}
			}
		}

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-9 R-CRON-META — write final counters
		if ( $cron ) {
			$cron->note( array(
				'counters' => array(
					'transit_total'   => $total,
					'transit_synced'  => $synced,
					'transit_skipped' => $skipped,
					'transit_failed'  => $failed,
				),
			) );
		}
	}

	/* ================================================================== *
	 * [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT
	 * 30-day batch transit cron — runs monthly, pre-fetches 30 days of
	 * transit for every coachee with a Western natal chart.
	 * This powers the DashboardPage transit strip cache.
	 * ================================================================== */
	const BATCH_HOOK   = 'bcpro_transit_30day_batch';
	const BATCH_JOB_ID = 'bcpro.transit.30day_batch';

	public static function init_batch() {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->register( array(
				'id'          => self::BATCH_JOB_ID,
				'hook'        => self::BATCH_HOOK,
				'interval'    => 'weekly',   // run every 7 days (covers 30-day window rolling)
				'owner'       => 'bizcoach-pro',
				'description' => 'Pre-fetch 30-day transit snapshot batch for all coachees with Western natal chart.',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 14,
			) );
		} else {
			if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
				wp_schedule_event( time() + 120, 'weekly', self::BATCH_HOOK );
			}
		}

		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch_30d' ) );
	}

	/**
	 * 30-day batch transit cron handler.
	 *
	 * [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT R-CRON-META: note() counters.
	 */
	public static function run_batch_30d() {
		global $wpdb;

		$cron_ok = class_exists( 'BizCity_Cron_Manager' );
		$cron    = $cron_ok ? BizCity_Cron_Manager::instance() : null;

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) || ! class_exists( 'BizCoach_Pro_Self_Service_REST' ) ) {
			if ( $cron ) {
				$cron->note( array( 'error' => 'dependencies_missing' ) );
				$cron->note_event( 'cron_failed', array( 'reason' => 'module_not_loaded', 'error' => 'Missing BizCoach_Pro_Astro_Client or REST' ) );
			}
			return;
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_snap  = $wpdb->prefix . 'bccm_transit_snapshots';
		$start   = current_time( 'Y-m-d' );
		$days    = 30;

		// All coachees with a Western natal chart already generated.
		$rows = $wpdb->get_results(
			"SELECT c.id, c.user_id, c.full_name, c.dob, c.extra_fields_json,
			        a.birth_time, a.birth_place
			 FROM {$t_coach} c
			 INNER JOIN {$t_astro} a ON a.coachee_id = c.id AND a.chart_type = 'western'
			        AND a.summary IS NOT NULL AND a.summary <> ''
			 ORDER BY c.id ASC",
			ARRAY_A
		);

		$stale_cutoff  = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$total_coachee = is_array( $rows ) ? count( $rows ) : 0;
		$total_fetched = 0;
		$total_skipped = 0;
		$total_failed  = 0;

		foreach ( (array) $rows as $row ) {
			$coachee_id = (int) $row['id'];
			$end        = date( 'Y-m-d', strtotime( $start . ' +' . ( $days - 1 ) . ' days' ) );

			// Which days in the window are already fresh?
			$cached = $wpdb->get_col( $wpdb->prepare(
				"SELECT target_date FROM {$t_snap}
				 WHERE coachee_id = %d AND target_date BETWEEN %s AND %s AND fetched_at > %s",
				$coachee_id, $start, $end, $stale_cutoff
			) );

			$coachee_failed = 0;
			for ( $i = 0; $i < $days; $i++ ) {
				$date = date( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );
				if ( in_array( $date, $cached, true ) ) {
					$total_skipped++;
					continue;
				}

				// [2026-07-10 Johnny Chu] PHASE-FAA2 — tag 30-day batch cron source for JSONL history.
				$res = BizCoach_Pro_Self_Service_REST::do_transit_fetch(
					$row,
					array( 'birth_time' => $row['birth_time'], 'birth_place' => $row['birth_place'] ),
					$date,
					'day',
					'cron_batch_30d'
				);

				if ( ! empty( $res['success'] ) ) {
					$total_fetched++;
				} else {
					$total_failed++;
					$coachee_failed++;
					$message = isset( $res['message'] ) ? (string) $res['message'] : 'unknown';
					$reason  = 'gateway_error';
					if ( stripos( $message, 'timeout' ) !== false ) { $reason = 'timeout'; }
					elseif ( stripos( $message, 'rate' ) !== false || stripos( $message, '429' ) !== false ) { $reason = 'rate_limited'; }
					elseif ( stripos( $message, 'token' ) !== false || stripos( $message, '40' ) !== false ) { $reason = 'token_invalid'; }
					if ( $cron ) {
						$cron->note_event( 'batch_transit_failed', array(
							'coachee_id' => $coachee_id,
							'date'       => $date,
							'reason'     => $reason,
							'error'      => $message,
						) );
					}
					if ( $coachee_failed >= 3 ) { break; } // abort coachee after 3 errors
				}
			}
		}

		// R-CRON-META: write final counters.
		if ( $cron ) {
			$cron->note( array(
				'counters' => array(
					'batch_coachees'       => $total_coachee,
					'batch_days_fetched'   => $total_fetched,
					'batch_days_skipped'   => $total_skipped,
					'batch_days_failed'    => $total_failed,
				),
			) );
		}
	}

	/* ================================================================== *
	 * [2026-06-28 Johnny Chu] PHASE-A — Weekly 7-day transit cron.
	 *
	 * Runs once a week. Fetches transit cho từng ngày trong 7 ngày tới
	 * (hôm nay + 6 ngày kế tiếp) cho mọi coachee có bản đồ sao Western.
	 * Lighter than the 30-day batch — designed to keep the AI astro mode
	 * always having fresh 7-day data without burning gateway quota.
	 * ================================================================== */
	const WEEKLY_7D_HOOK   = 'bcpro_transit_weekly_7d';
	const WEEKLY_7D_JOB_ID = 'bcpro.transit.weekly_7d';

	public static function init_weekly_7d() {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->register( array(
				'id'          => self::WEEKLY_7D_JOB_ID,
				'hook'        => self::WEEKLY_7D_HOOK,
				'interval'    => 'weekly',
				'owner'       => 'bizcoach-pro',
				'description' => 'Fetch transit cho 7 ngày kế tiếp cho mọi coachee có Western natal chart.',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 14,
			) );
		} else {
			if ( ! wp_next_scheduled( self::WEEKLY_7D_HOOK ) ) {
				wp_schedule_event( time() + 180, 'weekly', self::WEEKLY_7D_HOOK );
			}
		}

		add_action( self::WEEKLY_7D_HOOK, array( __CLASS__, 'run_weekly_7d' ) );
	}

	/**
	 * Weekly 7-day transit cron handler.
	 *
	 * [2026-06-28 Johnny Chu] PHASE-A — R-CRON-META: note() counters + note_event() fails.
	 */
	public static function run_weekly_7d() {
		global $wpdb;

		$cron_ok = class_exists( 'BizCity_Cron_Manager' );
		$cron    = $cron_ok ? BizCity_Cron_Manager::instance() : null;

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) || ! class_exists( 'BizCoach_Pro_Self_Service_REST' ) ) {
			if ( $cron ) {
				$cron->note( array( 'error' => 'dependencies_missing' ) );
				$cron->note_event( 'cron_failed', array(
					'reason' => 'module_not_loaded',
					'error'  => 'Missing BizCoach_Pro_Astro_Client or Self_Service_REST',
				) );
			}
			return;
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_snap  = $wpdb->prefix . 'bccm_transit_snapshots';
		$today   = current_time( 'Y-m-d' );
		$end_day = date( 'Y-m-d', strtotime( $today . ' +6 days' ) );
		$days    = 7;

		// Skip days already in cache and fetched within 6 hours (fresh enough).
		$stale_cutoff = date( 'Y-m-d H:i:s', strtotime( '-6 hours' ) );

		// All coachees with a Western natal chart.
		$rows = $wpdb->get_results(
			"SELECT c.id, c.user_id, c.full_name, c.dob, c.extra_fields_json,
			        a.birth_time, a.birth_place
			 FROM {$t_coach} c
			 INNER JOIN {$t_astro} a ON a.coachee_id = c.id AND a.chart_type = 'western'
			        AND a.summary IS NOT NULL AND a.summary <> ''
			 ORDER BY c.id ASC",
			ARRAY_A
		);

		$total_coachee = is_array( $rows ) ? count( $rows ) : 0;
		$total_fetched = 0;
		$total_skipped = 0;
		$total_failed  = 0;

		foreach ( (array) $rows as $row ) {
			$coachee_id = (int) $row['id'];

			// Fetch only missing/stale days for this coachee.
			$cached = $wpdb->get_col( $wpdb->prepare(
				"SELECT target_date FROM {$t_snap}
				 WHERE coachee_id = %d AND target_date BETWEEN %s AND %s AND fetched_at > %s",
				$coachee_id, $today, $end_day, $stale_cutoff
			) );

			$coachee_failed = 0;
			for ( $i = 0; $i < $days; $i++ ) {
				$date = date( 'Y-m-d', strtotime( $today . ' +' . $i . ' days' ) );
				if ( in_array( $date, (array) $cached, true ) ) {
					$total_skipped++;
					continue;
				}

				// [2026-07-10 Johnny Chu] PHASE-FAA2 — tag weekly 7-day cron source for JSONL history.
				$res = BizCoach_Pro_Self_Service_REST::do_transit_fetch(
					$row,
					array( 'birth_time' => $row['birth_time'], 'birth_place' => $row['birth_place'] ),
					$date,
					'day',
					'cron_weekly_7d'
				);

				if ( ! empty( $res['success'] ) ) {
					$total_fetched++;
					if ( $cron ) {
						$cron->note_event( 'weekly7d_transit_synced', array(
							'coachee_id' => $coachee_id,
							'date'       => $date,
						) );
					}
				} else {
					$total_failed++;
					$coachee_failed++;
					$message = isset( $res['message'] ) ? (string) $res['message'] : 'unknown';
					$reason  = 'gateway_error';
					if ( stripos( $message, 'timeout' ) !== false ) {
						$reason = 'timeout';
					} elseif ( stripos( $message, 'rate' ) !== false || stripos( $message, '429' ) !== false ) {
						$reason = 'rate_limited';
					} elseif ( stripos( $message, 'token' ) !== false || stripos( $message, '401' ) !== false || stripos( $message, '403' ) !== false ) {
						$reason = 'token_invalid';
					} elseif ( stripos( $message, 'http' ) !== false ) {
						$reason = 'http_error';
					}
					if ( $cron ) {
						$cron->note_event( 'weekly7d_transit_failed', array(
							'coachee_id' => $coachee_id,
							'date'       => $date,
							'reason'     => $reason,
							'error'      => $message,
						) );
					}
					if ( $coachee_failed >= 3 ) { break; } // abort coachee after 3 consecutive errors
				}
			}
		}

		// [2026-06-28 Johnny Chu] PHASE-A — R-CRON-META: write final counters.
		if ( $cron ) {
			$cron->note( array(
				'counters' => array(
					'weekly7d_coachees'       => $total_coachee,
					'weekly7d_days_fetched'   => $total_fetched,
					'weekly7d_days_skipped'   => $total_skipped,
					'weekly7d_days_failed'    => $total_failed,
				),
			) );
		}
	}
}
