<?php
/**
 * BizCoach Pro — Schema Installer (NO-OP facade).
 *
 * IMPORTANT (PHASE-0.36 / R-PROD-HUB / R-NO-CONFLICT):
 *   bizcoach-pro is a STRUCTURAL FACADE over the legacy bizcoach-map plugin.
 *   It does NOT own any DB tables. All persistence reuses the existing
 *   `wp_*bccm_*` tables (templates, coachees, answers, plan_templates,
 *   action_plans, daily_logs, metrics, astro, transit_snapshots,
 *   reminder_logs, gen_results) installed by `bizcoach-map/includes/install.php`
 *   (BCCM_Installer).
 *
 * Rationale (user directive 2026-05-15):
 *   "ko đổi bảng, giữ nguyên bảng cũ, cấu trúc db cũ, vì còn cơ chế bản đồ
 *    sao... chúng ta cần port y nguyên."
 *   → No `bcpro_*` tables; no schema fork; astro pipeline keeps working
 *     unchanged through legacy lib/astro-api-free.php + lib/astro-transit.php.
 *
 * Sprint K (legacy retire):
 *   When bizcoach-map is archived, this installer will be promoted to OWN
 *   the bccm_* schema (port BCCM_Installer logic verbatim). Until then it
 *   is a no-op so dbDelta never races during co-existence.
 *
 * @since 0.1.0
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Installer' ) ) { return; }

class BizCoach_Pro_Installer {

	const OPT_DB_VERSION = 'bcpro_db_version';

	/** Activation entry — records version marker only, no schema mutation. */
	public static function activate() {
		update_option( self::OPT_DB_VERSION, BCPRO_DB_VERSION, false );
	}

	/** Idempotent upgrade — no schema changes while legacy bizcoach-map owns bccm_*. */
	public static function maybe_upgrade() {
		$current = get_option( self::OPT_DB_VERSION, '0.0.0' );
		if ( version_compare( $current, BCPRO_DB_VERSION, '<' ) ) {
			update_option( self::OPT_DB_VERSION, BCPRO_DB_VERSION, false );
		}
	}

	/**
	 * Probe legacy bccm_* schema readiness without mutating anything.
	 * Used by Sprint Diagnostic + Artifact_Service preflight.
	 *
	 * @return array  ['ready'=>bool, 'present'=>[suffix=>bool], 'missing'=>[full table names]]
	 */
	public static function probe_legacy_schema(): array {
		global $wpdb;
		$tables = array(
			'templates', 'coachees', 'answers', 'plan_templates', 'action_plans',
			'daily_logs', 'metrics', 'astro', 'transit_snapshots', 'reminder_logs',
			'gen_results',
		);
		$present = array();
		$missing = array();
		foreach ( $tables as $suffix ) {
			$tbl = $wpdb->prefix . 'bccm_' . $suffix;
			$ok  = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			$present[ $suffix ] = $ok;
			if ( ! $ok ) { $missing[] = $tbl; }
		}
		return array(
			'ready'   => empty( $missing ),
			'present' => $present,
			'missing' => $missing,
		);
	}
}
