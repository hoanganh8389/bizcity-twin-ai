<?php
/**
 * BizCity Memory — D7 Legacy Drop Migration (DRY-RUN / EXECUTE).
 *
 * Wave 2.8d TBR.MEM-D7 — Drop 5 legacy memory tables sau khi unified
 * dual-write + read cutover đã chạy ≥ 1 sprint với 2 probes PASS.
 *
 * **CHƯA EXECUTE** — chỉ ship script. Để chạy thực:
 *   wp-cli:  wp eval-file path/to/this-file.php --execute=1
 *   PHP:     define('BIZCITY_D7_EXECUTE', true); require this file;
 *
 * Default = DRY-RUN: print SQL ra screen / WP-CLI, KHÔNG động DB.
 *
 * Pre-flight gates (HARD STOP nếu FAIL):
 *   1. `BizCity_Memory_Unified_Installer::is_enabled()` === TRUE
 *   2. Unified table `bizcity_memory` exists + row_count ≥ legacy_total
 *   3. Probe `core.memory.unified.dual-write-parity` last status = PASS
 *   4. Probe `core.memory.unified.recall-parity` last status = PASS
 *   5. Flag enabled ≥ 7 days (option `bizcity_memory_unified_enabled_at`)
 *   6. Founder sign-off marker: option `bizcity_d7_founder_signoff` = '1'
 *
 * Migration steps:
 *   a) Snapshot legacy row counts → log.
 *   b) `RENAME TABLE legacy → legacy_d7backup` (NOT DROP — recoverable 7 days).
 *   c) Bump R-DCL changelog `core.memory.unified.json` v2.0.0 + history row.
 *   d) Update `bizcity_memory_unified_db_ver` = '2.0.0'.
 *   e) Schedule cron `bizcity_d7_purge_backups` after +7d (real DROP).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory\Migrations
 * @since      Wave 2.8d D7 (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_D7_Drop_Legacy' ) ) {
	return;
}

final class BizCity_Memory_D7_Drop_Legacy {

	const LEGACY_TABLES = [
		'bizcity_memory_users',
		'bizcity_memory_episodic',
		'bizcity_memory_rolling',
		'bizcity_memory_session',
		'bizcity_memory_notes',
	];

	const BACKUP_SUFFIX     = '_d7backup';
	const STAGING_MIN_DAYS  = 7;
	const SIGNOFF_OPTION    = 'bizcity_d7_founder_signoff';
	const PURGE_CRON_HOOK   = 'bizcity_d7_purge_backups';
	const PURGE_DELAY_DAYS  = 7;

	/**
	 * Run migration.
	 *
	 * @param bool $execute  FALSE = dry-run only (default). TRUE = actually rename + bump version.
	 * @param bool $force    Skip safety gates 5+6 (staging duration + founder sign-off). Probes still required.
	 * @return array { ok, dry_run, gates:array, plan:array, executed?:array, error? }
	 */
	public static function run( bool $execute = false, bool $force = false ): array {
		global $wpdb;

		$result = [
			'ok'        => false,
			'dry_run'   => ! $execute,
			'timestamp' => current_time( 'mysql' ),
			'gates'     => [],
			'plan'      => [],
		];

		/* ── Gate 1: flag enabled ──────────────────────────────────── */
		$flag_ok = class_exists( 'BizCity_Memory_Unified_Installer' )
		        && BizCity_Memory_Unified_Installer::is_enabled();
		$result['gates']['flag_enabled'] = $flag_ok;
		if ( ! $flag_ok ) {
			$result['error'] = 'Gate 1 FAIL: bizcity_memory_unified_enabled is FALSE — must enable + run staging first.';
			return $result;
		}

		/* ── Gate 2: unified table exists + has data ───────────────── */
		$unified_tbl    = BizCity_Memory_Unified_Installer::table();
		$unified_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $unified_tbl ) ) === $unified_tbl );
		$unified_rows   = $unified_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$unified_tbl}" ) : 0;
		$legacy_total   = 0;
		foreach ( self::LEGACY_TABLES as $suffix ) {
			$tbl = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$legacy_total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
			}
		}
		// Allow 5% drift (deleted rows during dual-write window).
		$threshold = (int) ceil( $legacy_total * 0.95 );
		$rows_ok   = ( $unified_exists && $unified_rows >= $threshold );
		$result['gates']['unified_rows'] = [
			'ok'        => $rows_ok,
			'unified'   => $unified_rows,
			'legacy'    => $legacy_total,
			'threshold' => $threshold,
		];
		if ( ! $rows_ok ) {
			$result['error'] = sprintf(
				'Gate 2 FAIL: unified rows (%d) < 95%% threshold (%d) of legacy total (%d). Dual-write hasn\'t caught up.',
				$unified_rows, $threshold, $legacy_total
			);
			return $result;
		}

		/* ── Gate 3+4: probe last-run status (best-effort, fall back to manual) ── */
		$probes = self::check_probes_status();
		$result['gates']['probes'] = $probes;
		if ( ! $probes['dual_write_pass'] || ! $probes['recall_pass'] ) {
			$result['error'] = 'Gate 3/4 FAIL: probes not PASS. Run `core.memory.unified.dual-write-parity` + `core.memory.unified.recall-parity` in Diagnostics admin first.';
			return $result;
		}

		/* ── Gate 5: staging duration ──────────────────────────────── */
		$enabled_at   = (int) get_option( 'bizcity_memory_unified_enabled_at', 0 );
		$staging_days = $enabled_at > 0 ? floor( ( time() - $enabled_at ) / DAY_IN_SECONDS ) : 0;
		$staging_ok   = $force ? true : ( $staging_days >= self::STAGING_MIN_DAYS );
		$result['gates']['staging'] = [
			'ok'           => $staging_ok,
			'days'         => $staging_days,
			'required'     => self::STAGING_MIN_DAYS,
			'force_bypass' => $force,
		];
		if ( ! $staging_ok ) {
			$result['error'] = sprintf( 'Gate 5 FAIL: flag enabled only %dd, need ≥ %dd. Use --force to bypass at your own risk.', $staging_days, self::STAGING_MIN_DAYS );
			return $result;
		}

		/* ── Gate 6: founder sign-off ──────────────────────────────── */
		$signoff_ok = $force ? true : ( get_option( self::SIGNOFF_OPTION, '0' ) === '1' );
		$result['gates']['founder_signoff'] = [
			'ok'           => $signoff_ok,
			'option'       => self::SIGNOFF_OPTION,
			'force_bypass' => $force,
		];
		if ( ! $signoff_ok ) {
			$result['error'] = sprintf( 'Gate 6 FAIL: option `%s` !== "1". Set via wp option update before running.', self::SIGNOFF_OPTION );
			return $result;
		}

		/* ── Build plan (rename, not drop — recoverable) ───────────── */
		foreach ( self::LEGACY_TABLES as $suffix ) {
			$src = $wpdb->prefix . $suffix;
			$dst = $src . self::BACKUP_SUFFIX;
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $src ) ) === $src );
			$result['plan'][] = [
				'sql'    => sprintf( 'RENAME TABLE `%s` TO `%s`', $src, $dst ),
				'src'    => $src,
				'dst'    => $dst,
				'rows'   => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$src}" ) : 0,
				'skip'   => ! $exists,
				'reason' => $exists ? 'rename' : 'table_missing',
			];
		}
		$result['plan'][] = [
			'sql'    => sprintf( "UPDATE %s SET option_value='2.0.0' WHERE option_name='bizcity_memory_unified_db_ver'", $wpdb->options ),
			'note'   => 'bump R-DCL version after migration',
		];

		if ( ! $execute ) {
			$result['ok']      = true;
			$result['message'] = 'DRY-RUN complete — all gates PASS. Re-run with $execute=true to actually rename tables.';
			return $result;
		}

		/* ── EXECUTE ─────────────────────────────────────────────── */
		$executed = [];
		foreach ( $result['plan'] as $step ) {
			if ( ! empty( $step['skip'] ) || empty( $step['sql'] ) ) {
				$executed[] = [ 'sql' => $step['sql'] ?? '', 'skipped' => true ];
				continue;
			}
			$wpdb->hide_errors();
			$ok = ( false !== $wpdb->query( $step['sql'] ) );
			$executed[] = [
				'sql'   => $step['sql'],
				'ok'    => $ok,
				'error' => $ok ? null : $wpdb->last_error,
			];
			if ( ! $ok ) {
				$result['error']    = 'EXECUTE failed at: ' . $step['sql'] . ' — ' . $wpdb->last_error;
				$result['executed'] = $executed;
				return $result;
			}
		}

		// Schedule purge cron (real DROP after 7 days grace).
		if ( ! wp_next_scheduled( self::PURGE_CRON_HOOK ) ) {
			wp_schedule_single_event(
				time() + ( self::PURGE_DELAY_DAYS * DAY_IN_SECONDS ),
				self::PURGE_CRON_HOOK
			);
		}

		// Bump R-DCL version option (already in plan but force as fallback).
		update_option( 'bizcity_memory_unified_db_ver', '2.0.0' );

		$result['ok']       = true;
		$result['executed'] = $executed;
		$result['message']  = 'D7 migration EXECUTED — 5 legacy tables renamed to *_d7backup. Real DROP scheduled in ' . self::PURGE_DELAY_DAYS . ' days.';
		return $result;
	}

	/**
	 * Real DROP of backup tables, fires from scheduled cron.
	 */
	public static function purge_backups(): void {
		global $wpdb;
		foreach ( self::LEGACY_TABLES as $suffix ) {
			$tbl = $wpdb->prefix . $suffix . self::BACKUP_SUFFIX;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$wpdb->query( "DROP TABLE `{$tbl}`" );
			}
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[BizCity_Memory_D7] purged ' . count( self::LEGACY_TABLES ) . ' backup tables.' );
		}
	}

	/**
	 * Best-effort: lookup last probe run status from option/transient written by Smoke Runner.
	 * Falls back to TRUE if no transient (assume admin already ran probes).
	 */
	private static function check_probes_status(): array {
		$dual_write_pass = self::probe_passed( 'core.memory.unified.dual-write-parity' );
		$recall_pass     = self::probe_passed( 'core.memory.unified.recall-parity' );
		return [
			'dual_write_pass' => $dual_write_pass,
			'recall_pass'     => $recall_pass,
		];
	}

	private static function probe_passed( string $probe_id ): bool {
		// Smoke Runner stores results in option `bizcity_diagnostics_smoke_last`.
		$last = get_option( 'bizcity_diagnostics_smoke_last', [] );
		if ( is_array( $last ) && isset( $last[ $probe_id ] ) ) {
			$status = strtolower( (string) ( $last[ $probe_id ]['status'] ?? '' ) );
			return $status === 'pass';
		}
		// No data → conservative: REQUIRE admin to run probe first.
		return false;
	}
}

// Wire purge cron hook (idempotent).
add_action( BizCity_Memory_D7_Drop_Legacy::PURGE_CRON_HOOK, [ 'BizCity_Memory_D7_Drop_Legacy', 'purge_backups' ] );

// CLI / eval-file path: respect BIZCITY_D7_EXECUTE constant.
if ( defined( 'BIZCITY_D7_DRYRUN_NOW' ) && BIZCITY_D7_DRYRUN_NOW ) {
	$execute = defined( 'BIZCITY_D7_EXECUTE' ) && BIZCITY_D7_EXECUTE;
	$force   = defined( 'BIZCITY_D7_FORCE' )   && BIZCITY_D7_FORCE;
	$out     = BizCity_Memory_D7_Drop_Legacy::run( $execute, $force );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	} else {
		echo '<pre>' . esc_html( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}
}
