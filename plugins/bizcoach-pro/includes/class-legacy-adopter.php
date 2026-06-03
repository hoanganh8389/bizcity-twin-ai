<?php
/**
 * BizCoach Pro — Legacy Pages Adopter (Sprint K, simplified).
 *
 * Static port of bizcoach-map admin UI: 5 wizard pages + customer list +
 * frontend rewrites. Files committed under `bcpro/legacy/` (snapshot from
 * bizcoach-map). When bizcoach-map plugin is INACTIVE, bcpro requires the
 * shadow files at file-include time so the same menu slugs (bccm_*) keep
 * rendering — zero downtime UX takeover.
 *
 * Design choice: NO option toggle, NO runtime copy, NO heartbeat. Pure
 * static require gated by `BCCM_VERSION` presence. If legacy plugin gets
 * reactivated, this file no-ops automatically (BCCM_VERSION wins).
 *
 * R-NO-CONFLICT: shadow excludes class-intent-provider / class-persona-* —
 * bizcoach-pro owns those.
 *
 * @since 0.2.0 (Sprint K.A2 — simplified)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Legacy_Adopter' ) ) { return; }

class BizCoach_Pro_Legacy_Adopter {

	const SHADOW_SUBDIR = 'legacy/';
	public static $boot_trace = array(); // diagnostic: why did boot() exit?

	public static function shadow_dir() { return BCPRO_DIR . self::SHADOW_SUBDIR; }
	public static function shadow_url() { return BCPRO_URL . self::SHADOW_SUBDIR; }

	/**
	 * Files to require, in legacy load order. Mirrors `bizcoach-map.php`
	 * lines 32-61 minus the 3 R-NO-CONFLICT files bizcoach-pro owns.
	 */
	public static function file_list() {
		return array(
			// libs
			'lib/helpers.php',
			'lib/ai.php',
			'lib/helper-biz.php',
			'lib/helper-tiktok.php',
			'lib/helper-baby.php',
			'lib/ai-baby.php',
			'lib/helper-health.php',
			'lib/helper-career.php',
			'lib/astro-api.php',
			// Sprint H (2026-05-16): V2 bridge — replaces legacy
			// freeastrologyapi.com path with api.freeastroapi.com FAA V2
			// providers for the admin "Generate chart" buttons.
			'lib/astro-v2-bridge.php',
			// Astro AI handlers (registers admin-ajax `bccm_natal_report_full`
			// and `bccm_transit_report` consumed by the Astrology Persona
			// Dialog through `BizCoach_Pro_Astro_Rest::ingest_link()`).
			'lib/astro-transit.php',
			'lib/astro-transit-report.php',
			'lib/astro-transit-timeline.php',
			'lib/astro-transit-ai.php',
			'lib/astro-report-llm.php',
			// Sprint H.4 — Chinese BaZi / Tử Bình renderer + LLM helpers.
			// Provides bccm_chinese_render_natal_report(),
			// bccm_chinese_build_chart_context(),
			// bccm_chinese_llm_get_sections(), bccm_chinese_llm_system_prompt().
			'lib/astro-chinese.php',
			// Sprint H.5 — Vedic / Jyotish renderer + LLM helpers.
			// Provides bccm_vedic_render_natal_report(),
			// bccm_vedic_llm_get_sections(), bccm_vedic_llm_system_prompt().
			'lib/astro-vedic-renderer.php',
			// install + admin foundation
			'includes/install.php',
			'includes/admin-pages.php',
			'includes/admin-pages-json.php',
			'includes/frontend.php',
			'includes/frontend-astro-form.php',
			'includes/class-nobi-float.php',
			'includes/admin-dashboard.php',
			// 4-step wizard
			'includes/admin-self-profile.php',
			'includes/admin-step2-coach-template.php',
			'includes/admin-step3-character.php',
			'includes/admin-step4-success-plan.php',
			'includes/admin-lifemap.php',
			// frontend + ajax
			'includes/frontend-profile.php',
			'includes/frontend-astro-landing.php',
			'includes/frontend-natal-chart.php',
			'includes/frontend-progress-panel.php',
			'includes/ajax-coach-map-generator.php',
			// network + user list
			'includes/network-admin.php',
			'includes/admin-user-profiles.php',
		);
	}

	/**
	 * Snapshot status for diag panel.
	 */
	public static function status() {
		$legacy_active = defined( 'BCCM_VERSION' ) && BCCM_VERSION !== '0.0.0-adopted';
		$loaded        = defined( 'BCCM_VERSION' ) && BCCM_VERSION === '0.0.0-adopted';
		$shadow_ok     = is_dir( self::shadow_dir() ) && file_exists( self::shadow_dir() . 'includes/admin-pages.php' );
		if ( $legacy_active )    { $mode = 'legacy_active'; }
		elseif ( $loaded )       { $mode = 'adopted'; }
		elseif ( ! $shadow_ok )  { $mode = 'missing_shadow'; }
		else                     { $mode = 'pending'; }
		return array(
			'mode'          => $mode,
			'legacy_active' => $legacy_active,
			'loaded'        => $loaded,
			'shadow_ok'     => $shadow_ok,
		);
	}

	/**
	 * Boot — STATIC require chain. Skips entirely if legacy plugin active.
	 */
	public static function boot() {
		self::$boot_trace[] = 'enter@' . microtime( true );
		if ( defined( 'BCCM_VERSION' ) ) {
			self::$boot_trace[] = 'skip:BCCM_VERSION_already_defined=' . BCCM_VERSION;
			return;
		}
		if ( ! is_dir( self::shadow_dir() ) ) {
			self::$boot_trace[] = 'skip:shadow_dir_missing=' . self::shadow_dir();
			return;
		}

		define( 'BCCM_DIR',     self::shadow_dir() );
		define( 'BCCM_URL',     self::shadow_url() );
		define( 'BCCM_VERSION', '0.0.0-adopted' );
		self::$boot_trace[] = 'defined_constants';

		if ( ! function_exists( 'dbDelta' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$loaded = 0;
		$failed = array();
		foreach ( self::file_list() as $rel ) {
			$path = self::shadow_dir() . $rel;
			if ( file_exists( $path ) ) {
				require_once $path;
				$loaded++;
			} else {
				$failed[] = $rel;
			}
		}
		self::$boot_trace[] = 'required_files=' . $loaded;
		if ( ! empty( $failed ) ) {
			self::$boot_trace[] = 'missing=' . implode( ',', $failed );
		}

		// File-scope hooks ported from bizcoach-map.php (template_include,
		// rewrites, asset registration, one-shot rewrite flush after takeover).
		$bootstrap = self::shadow_dir() . '_adopter-bootstrap.php';
		if ( file_exists( $bootstrap ) ) {
			require_once $bootstrap;
			self::$boot_trace[] = 'bootstrap_loaded';
		} else {
			self::$boot_trace[] = 'bootstrap_missing';
		}
	}
}

BizCoach_Pro_Legacy_Adopter::boot();
