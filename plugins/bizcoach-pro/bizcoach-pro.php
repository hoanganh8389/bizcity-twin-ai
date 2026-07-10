<?php
/*
Plugin Name: BizCoach Pro — Producer Hub
Description: Producer-hub flagship cho Twin AI. Mọi plugin tương lai cung cấp artifact đầu vào cho Guru đi qua đây (template registry + Persona Provider + Federation stamp). Xem PHASE-0.36-BIZCOACH-MAP-FRAMEWORK.md (R-PROD-HUB).
Version: 0.2.0
Role: producer-hub
Plan: in-house
Author: BizCity Twin AI Core
Text Domain: bizcoach-pro
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ----------------------------------------------------
 * CONSTANTS
 * -------------------------------------------------- */
define( 'BCPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCPRO_URL', plugin_dir_url( __FILE__ ) );
// [2026-06-06 Johnny Chu] HOTFIX — append time() in dev so rewrite/asset cache busts every request
define( 'BCPRO_VERSION', '0.3.23.' . time() );
define( 'BCPRO_DB_VERSION', '1.0.1' );
// [2026-07-06 Johnny Chu] HOTFIX — Transit writer unification: disable legacy prefetch scheduler.
if ( ! defined( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED' ) ) {
	define( 'BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED', false );
}
// [2026-06-09 Johnny Chu] HOTFIX — stable version for rewrite flush guard (BCPRO_VERSION has time())
define( 'BCPRO_REWRITE_VERSION', '0.3.23' );
define( 'BCPRO_TEMPLATE_DIR', BCPRO_DIR . 'data/coach-templates/' );

/* ----------------------------------------------------
 * INCLUDES — organised by domain (2026-05-15 reorg):
 *   includes/                 — cross-cutting (installer, REST router, legacy bridge, diag)
 *   includes/coaching/        — coach-builder + template engine + persona/intent providers
 *   includes/astro/           — astrology persona provider (chiêm tinh)
 * Order matters: install → registry → loader → providers → REST.
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/class-installer.php';
require_once BCPRO_DIR . 'includes/class-cache.php'; // Object-cache wrapper + bcpro/cache/invalidate listener (CACHE-STRATEGY.md)
// Belt-and-suspenders: explicit static boot (file-scope `::init()` at end of
// class-cache.php may be missing from stale opcache bytecode — same pattern
// we hit with class-astro-rest.php on 2026-05-16). init() is idempotent.
if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
	BizCoach_Pro_Cache::init();
}
require_once BCPRO_DIR . 'includes/coaching/class-template-registry.php';
require_once BCPRO_DIR . 'includes/coaching/class-template-loader.php';
require_once BCPRO_DIR . 'includes/coaching/class-artifact-service.php';
require_once BCPRO_DIR . 'includes/class-legacy-adopter.php';

/* ----------------------------------------------------
 * Sprint H.6 — Public hash-protected URLs for the 3 astrology systems
 * (/my-western-astrology, /my-vedic-astrology, /my-chinese-astrology).
 * Replaces admin-ajax.php?action=bccm_natal_report_full so generated luận giải
 * can be shared with end-users. Always-on (admin + public).
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/frontend/class-astro-public-router.php';
require_once BCPRO_DIR . 'includes/frontend/class-transit-public-router.php';
if ( class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) {
	BizCoach_Pro_Astro_Public_Router::init();
}
if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) {
	BizCoach_Pro_Transit_Public_Router::init();
}

// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — Self-service FE: subscriber profile CRUD + shortcode
require_once BCPRO_DIR . 'includes/astro/class-self-profile-manager.php';
require_once BCPRO_DIR . 'includes/frontend/class-self-service-rest.php';
// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-4 — Relation Manager (Ashtakoot scores)
require_once BCPRO_DIR . 'includes/astro/class-relation-manager.php';
require_once BCPRO_DIR . 'includes/frontend/class-self-service-shortcode.php';
// [2026-06-07 Johnny Chu] PHASE-C C-BE-2 — per-user usage report helper.
require_once BCPRO_DIR . 'includes/usage/class-usage-report.php';
if ( class_exists( 'BizCoach_Pro_Self_Service_REST' ) ) {
	BizCoach_Pro_Self_Service_REST::init();
}
if ( class_exists( 'BizCoach_Pro_Self_Service_Shortcode' ) ) {
	BizCoach_Pro_Self_Service_Shortcode::init();
}

// [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — daily transit sync cron
// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — also register 30-day batch cron
require_once BCPRO_DIR . 'includes/frontend/class-transit-cron.php';
if ( class_exists( 'BizCoach_Pro_Transit_Cron' ) ) {
	BizCoach_Pro_Transit_Cron::init();
	BizCoach_Pro_Transit_Cron::init_batch();
	// [2026-06-28 Johnny Chu] PHASE-A — weekly 7-day transit cron (lighter batch for AI astro freshness).
	BizCoach_Pro_Transit_Cron::init_weekly_7d();
}

// [2026-07-06 Johnny Chu] HOTFIX — remove queued legacy prefetch jobs so only do_transit_fetch writes snapshots.
if ( ! BCPRO_LEGACY_TRANSIT_PREFETCH_ENABLED ) {
	add_action( 'init', function () {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'bccm_transit_prefetch_cron' );
		}
	}, 20 );
}

// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — /astro/ virtual page + admin submenu
require_once BCPRO_DIR . 'includes/frontend/class-self-service-page.php';
if ( class_exists( 'BizCoach_Pro_Self_Service_Page' ) ) {
	BizCoach_Pro_Self_Service_Page::init();
}
// [2026-06-12 Johnny Chu] PHASE-NATAL-REPORT — public shareable natal chart report page /natal-report/?data=BASE64
require_once BCPRO_DIR . 'includes/frontend/class-natal-report-public-page.php';
if ( class_exists( 'BizCoach_Pro_Natal_Report_Public_Page' ) ) {
	BizCoach_Pro_Natal_Report_Public_Page::init();
}
// [2026-06-17 Johnny Chu] HOTFIX — SVG MIME type fix so WordPress/browser can serve chart SVG files.
require_once BCPRO_DIR . 'includes/frontend/class-svg-mime-fix.php';
require_once BCPRO_DIR . 'includes/frontend/class-svg-diagnostics.php';
if ( class_exists( 'BizCoach_Pro_SVG_MIME_Fix' ) ) {
	BizCoach_Pro_SVG_MIME_Fix::init();
}
// [2026-06-09 Johnny Chu] R-CR — migrated to Central Rewrite Flush Registry.
// BCPRO_REWRITE_VERSION (stable '0.3.23') — NEVER use BCPRO_VERSION (has time()).
BizCity_Rewrite_Flush_Registry::register( 'bizcoach-pro', BCPRO_REWRITE_VERSION );

/* ----------------------------------------------------
 * PHASE-0.2 Sprint G.1 / G.5 — Astro Gateway Client + Admin Settings.
 * Load BEFORE legacy adopter so the legacy `bccm_astro_*` choke-point
 * helpers (refactored in Sprint G.2) can resolve the client class.
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/astro/class-astro-client.php';

// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — Astro channel logger (loads on REST + cron too)
require_once BCPRO_DIR . 'includes/astro/class-astro-log.php';

// [2026-06-04 Johnny Chu] PHASE-A C.0b — Astro Transit Resolver (DB-first).
// Required by CAP filter wired below + by future BE stream_astro_mode().
require_once BCPRO_DIR . 'includes/astro/class-astro-transit-resolver.php';
// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — Astro data fetch checklist table + service.
require_once BCPRO_DIR . 'includes/astro/class-astro-checklist.php';
BizCoach_Astro_Checklist::maybe_install();

// R-1API-9 / R-1API-10 (2026-05-17): register this plugin as a canonical-key
// consumer so it shows up in the unified TwinChat settings page consumer table.
add_filter( 'bizcity_llm_consumer_plugins', static function ( $list ) {
	$list   = is_array( $list ) ? $list : [];
	$list[] = [
		'id'    => 'bizcoach-pro',
		'label' => 'BizCoach Pro — Producer Hub (Astrology)',
		'desc'  => 'Astrology /astrology/* + persona providers + coach templates. Đọc bizcity_llm_api_key với fallback bcpro_gateway_api_key.',
	];
	return $list;
} );

if ( is_admin() ) {
	require_once BCPRO_DIR . 'includes/astro/class-astro-admin-settings.php';
	if ( class_exists( 'BizCoach_Pro_Astro_Admin_Settings' ) ) {
		BizCoach_Pro_Astro_Admin_Settings::init();
	}

	// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — Astro Log Reader admin page
	require_once BCPRO_DIR . 'includes/admin/class-astro-log-admin.php';
	if ( class_exists( 'BizCoach_Pro_Astro_Log_Admin', false ) ) {
		BizCoach_Pro_Astro_Log_Admin::init();
	}
	// R-1API-9 (2026-05-17): Nudge admin to the unified TwinChat settings
	// page when no BizCity API key is configured (canonical or legacy).
	// Notice is suppressed ON the canonical settings page itself + on the
	// legacy bcpro-astro-gateway redirect page to avoid noise.
	add_action( 'admin_notices', static function () {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page === 'bizcity-twinchat-settings' || $page === 'bcpro-astro-gateway' ) {
			return;
		}
		if ( $screen && in_array( $screen->base, [ 'dashboard', 'update-core' ], true ) === false
		     && strpos( (string) $screen->id, 'bcpro' ) === false ) {
			// Only nag on BizCoach Pro admin screens + Dashboard.
			if ( strpos( (string) $screen->id, 'bizcity-twinchat' ) === false ) {
				return;
			}
		}
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) { return; }
		if ( BizCoach_Pro_Astro_Client::get_api_key() !== '' ) { return; }

		$url = admin_url( 'admin.php?page=bizcity-twinchat-settings' );
		echo '<div class="notice notice-warning"><p>'
			. '🔑 <strong>BizCoach Pro:</strong> '
			. esc_html__( 'Chưa cấu hình BizCity API key — astrology gateway sẽ fail-fast khi gọi remote.', 'bizcoach-pro' )
			. ' <a class="button button-primary" style="margin-left:8px;" href="'
			. esc_url( $url ) . '">'
			. esc_html__( '⚙ Mở BizCity API & Gateway', 'bizcoach-pro' )
			. '</a></p></div>';
	} );

	// [2026-06-10 Johnny Chu] HOTFIX — bccm_vedic_profiles + bccm_bazi_profiles menu pages removed per request.
	// BizCoach_Pro_Astro_Admin_List::init();

	// PHASE-0.3 H.3 — Dual-tab add/edit form (choose-or-create).
	require_once BCPRO_DIR . 'includes/admin/class-astro-admin-form.php';
}

// PHASE-0.3 H.2 — User picker: load + register REST OUTSIDE is_admin() so the
// rest_api_init hook fires on /wp-json/* requests (is_admin() = false there).
// Security is enforced by permission_callback = manage_options inside the class.
require_once BCPRO_DIR . 'includes/admin/class-user-picker.php';
if ( class_exists( 'BizCoach_Pro_User_Picker' ) ) {
	BizCoach_Pro_User_Picker::init();
}

if ( is_admin() ) {
	require_once BCPRO_DIR . 'includes/class-admin-coachees.php';
	if ( class_exists( 'BizCoach_Pro_Admin_Coachees' ) ) {
		BizCoach_Pro_Admin_Coachees::init();
	}
}

// Belt-and-suspenders: explicit static boot of legacy adopter. The class file
// also calls this at file-scope, but if opcache has a stale copy without the
// final ::boot() line, this re-trigger guarantees the takeover runs. The
// boot() method itself is idempotent (early-returns if BCCM_VERSION defined).
if ( class_exists( 'BizCoach_Pro_Legacy_Adopter' ) ) {
	BizCoach_Pro_Legacy_Adopter::boot();
}

/* ----------------------------------------------------
 * Activation — install schema on first load
 * -------------------------------------------------- */
register_activation_hook( __FILE__, [ 'BizCoach_Pro_Installer', 'activate' ] );
add_action( 'plugins_loaded', [ 'BizCoach_Pro_Installer', 'maybe_upgrade' ], 5 );

/* ----------------------------------------------------
 * Boot template loader (prime registry from JSON files + DB)
 * -------------------------------------------------- */
add_action( 'plugins_loaded', [ 'BizCoach_Pro_Template_Loader', 'boot' ], 9 );

/* ----------------------------------------------------
 * Persona Tool Providers (R-PP) — register via filter.
 *
 * Two distinct providers ship from this plugin so an admin can bind two
 * Twin Guru characters to two different roles (R-PP-1 disjoint id):
 *   - bizcoach_pro    → coaching template producer (class-persona-provider.php)
 *   - bizcoach_astro  → astrology / chiêm tinh        (class-astro-provider.php)
 *
 * Adding a third producer? Duplicate `class-astro-provider.php` and add
 * one more registration block here — see PROVIDER-CANON.md.
 *   - bizcoach_pro    → includes/coaching/class-persona-provider.php
 *   - bizcoach_astro  → includes/astro/class-astro-provider.php
 * -------------------------------------------------- */
if ( class_exists( 'BizCity_Persona_Tool_Provider' ) ) {
	require_once BCPRO_DIR . 'includes/coaching/class-persona-provider.php';
	require_once BCPRO_DIR . 'includes/astro/class-astro-provider.php';
	add_filter( 'bizcity_persona_tool_providers', function ( array $providers ) {
		$providers[] = new BizCoach_Pro_Persona_Provider();
		// Legacy bundled provider kept for back-compat (id=bizcoach_astro).
		$providers[] = new BizCoach_Pro_Astro_Provider();
		// Sprint H.6 — per-system Astrology providers so admin can bind a
		// dedicated Twin Guru character per astrology school.
		$providers[] = new BizCoach_Pro_Astro_Provider( 'western' );
		$providers[] = new BizCoach_Pro_Astro_Provider( 'vedic' );
		$providers[] = new BizCoach_Pro_Astro_Provider( 'chinese' );
		return $providers;
	}, 25 );

	// Astro persona REST (powers React PersonalArtifactDialog when an admin
	// binds a Twin Guru character to the `bizcoach_astro` provider). Ports
	// the legacy `bizcity-bizcoach/v1` namespace from the deleted bizcoach-map
	// plugin so the FE keeps working unchanged. See PROVIDER-CANON.md §8.
	//
	// Defensive: invalidate opcache for this single file before requiring, so
	// any past bytecode that lacked the trailing `::init();` call gets refreshed
	// on next request (validate_timestamps=0 production servers cache forever).
	$bcpro_astro_rest = BCPRO_DIR . 'includes/astro/class-astro-rest.php';
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $bcpro_astro_rest, true );
	}
	require_once $bcpro_astro_rest;
	// Belt-and-suspenders: don't rely on file-scope `::init()` at the bottom
	// of class-astro-rest.php (stale opcache may have cached a version without
	// it). Call it explicitly here — init() is idempotent (uses add_action).
	if ( class_exists( 'BizCoach_Pro_Astro_Rest' ) ) {
		BizCoach_Pro_Astro_Rest::init();
	}
}

/* ----------------------------------------------------
 * [2026-06-04 Johnny Chu] PHASE-A C.0b — CAP filter for astro mode.
 *
 * Subscribe to the per-turn artifact filter `bizcity_twin_context_artifacts`
 * (R-PP-4 / CAP-4): when AskBrain dispatches mode='astro', inject the
 * coachee's transit context (DB-first via BizCoach_Pro_Astro_Transit_Resolver).
 *
 * Filter signature (RFC §0.3): ( array $passages, string $mode,
 *                                 int $user_id, array $opts ) → array.
 * Listener is fail-OPEN: never throws, returns input untouched on any
 * failure. Caller (twin-core or AskBrain BE) will fire this filter once
 * C.3b BE lands — wiring it here now keeps producer-side ready.
 * -------------------------------------------------- */
add_filter( 'bizcity_twin_context_artifacts', function ( $passages, $mode = '', $user_id = 0, $opts = array() ) {
	if ( ! is_array( $passages ) ) { $passages = array(); }
	if ( $mode !== 'astro' ) { return $passages; }
	if ( ! class_exists( 'BizCoach_Pro_Astro_Transit_Resolver' ) ) { return $passages; }

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) { return $passages; }

	// Coachee resolution: prefer explicit opt, fall back to user→chính chủ.
	// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-3 — R-COACHEE.4: use bccm_get_self_coachee() not ORDER BY DESC
	$coachee_id = isset( $opts['coachee_id'] ) ? (int) $opts['coachee_id'] : 0;
	if ( $coachee_id <= 0 ) {
		if ( function_exists( 'bccm_get_self_coachee' ) ) {
			$row = bccm_get_self_coachee( $user_id );
		} elseif ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			$row = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
		} else {
			$row = null;
		}
		if ( is_array( $row ) ) {
			$coachee_id = isset( $row['id'] ) ? (int) $row['id'] : ( isset( $row['coachee_id'] ) ? (int) $row['coachee_id'] : 0 );
		} elseif ( is_numeric( $row ) ) {
			$coachee_id = (int) $row;
		}
	}
	if ( $coachee_id <= 0 ) { return $passages; }

	$period      = isset( $opts['period'] )       ? (string) $opts['period']      : 'day';
	$num_days    = isset( $opts['num_days'] )      ? (int)    $opts['num_days']    : 0;
	$render_mode = isset( $opts['render_mode'] )   ? (string) $opts['render_mode'] : 'daily';
	$req_days    = isset( $opts['requested_days'] ) ? (int)   $opts['requested_days'] : 0;
	$message     = isset( $opts['message'] )       ? (string) $opts['message']     : '';
	$trace_id    = isset( $opts['trace_id'] )      ? (string) $opts['trace_id']    : '';
	// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — offset-aware daily transit window.
	$start_offset = isset( $opts['start_offset'] ) ? max( 0, (int) $opts['start_offset'] ) : 0;

	// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — route to FAA2 transit_range
	// when render_mode='daily' (num_days ≤ 30) — live day-by-day, shared planet cache.
	// render_mode='overview' or 'fallback' → use legacy DB-first resolver.
	if ( $render_mode === 'daily' && $num_days >= 1 && $num_days <= 30 ) {
		if ( class_exists( 'BizCity_Astro_Router' ) && class_exists( 'Astro_Provider_FAA2_Western' ) ) {
			BizCity_Astro_Router::boot();
			$faa2 = BizCity_Astro_Router::get_provider( 'faa2_western' );
			if ( $faa2 && $faa2->is_ready() ) {
				// Load natal planets from DB
				global $wpdb;
				$_t_astro = $wpdb->prefix . 'bccm_astro';
				// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — ORDER BY id DESC: newest traits first.
				$_natal_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT traits FROM {$_t_astro} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
					$coachee_id
				), ARRAY_A );
				$_natal_planets = array();
				if ( $_natal_row && ! empty( $_natal_row['traits'] ) ) {
					$_traits = json_decode( $_natal_row['traits'], true );
					$_natal_planets = is_array( $_traits ) ? (array) ( $_traits['positions'] ?? array() ) : array();
				}
				if ( ! empty( $_natal_planets ) ) {
					// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — timezone-safe start date; avoid current_time('timestamp') double-offset drift.
					$_tz = function_exists( 'wp_timezone' )
						? wp_timezone()
						: new DateTimeZone( ( function_exists( 'wp_timezone_string' ) && wp_timezone_string() !== '' ) ? wp_timezone_string() : 'UTC' );
					try {
						$_base_dt = new DateTimeImmutable( 'now', $_tz );
					} catch ( Exception $e ) {
						$_base_dt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
					}
					$_base_dt    = $_base_dt->setTime( 0, 0, 0 );
					$_start_dt   = $_base_dt->modify( '+' . $start_offset . ' day' );
					$_start_date = $_start_dt->format( 'Y-m-d' );
					$_tr_input = array(
						'start_date'    => $_start_date,
						'num_days'      => $num_days,
						'natal_planets' => $_natal_planets,
						'outer_only'    => true,
					);
					// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — log before call
					if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) {
						BizCoach_Pro_Astro_Log::info( 'transit_range_request',
							'CAP filter calling FAA2 transit_range',
							array(
								'coachee_id'    => $coachee_id,
								'num_days'      => $num_days,
								'start_offset'  => $start_offset,
								'start'         => $_tr_input['start_date'],
							)
						);
					}
					$_range_result = $faa2->transit_range( $_tr_input );
					// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — log result
					if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) {
						BizCoach_Pro_Astro_Log::transit_range_call( $_tr_input, $_range_result, $coachee_id, 'cap_filter' );
					}
					if ( ! empty( $_range_result['success'] ) && ! empty( $_range_result['daily'] ) ) {
						if ( $num_days === 1 ) {
							if ( $start_offset >= 2 ) {
								$_label = 'Ngày kia';
							} elseif ( $start_offset === 1 ) {
								$_label = 'Ngày mai';
							} else {
								$_label = 'Hôm nay';
							}
						} else {
							$_label = $num_days . ' ngày tới';
						}

						$_daily_rows = (array) $_range_result['daily'];
						if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
							foreach ( $_daily_rows as $_i => $_day ) {
								if ( ! is_array( $_day ) ) { continue; }
								$_date = (string) ( $_day['date'] ?? '' );
								if ( $_date === '' ) { continue; }
								$_daily_rows[ $_i ]['day_url'] = (string) bcpro_get_transit_public_url( $coachee_id, 'day', array( 'date' => $_date ) );
								if ( empty( $_daily_rows[ $_i ]['date_label'] ) ) {
									$_daily_rows[ $_i ]['date_label'] = $_date;
								}
							}
						}

						$_body  = _bcpro_cap_format_transit_range( $_daily_rows, $_label );
						if ( $_body !== '' ) {
							// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — preserve source provenance
							// + raw daily JSON so TwinBrain runtime can score day-by-day without markdown parsing.
							$_source_url = '';
							if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
								if ( $num_days === 1 ) {
									$_source_url = (string) bcpro_get_transit_public_url( $coachee_id, 'day', array( 'date' => $_start_date ) );
								} elseif ( $num_days > 1 && $num_days <= 30 ) {
									$_end_dt   = $_start_dt->modify( '+' . ( max( 1, $num_days ) - 1 ) . ' day' );
									$_end_date = $_end_dt->format( 'Y-m-d' );
									$_source_url = (string) bcpro_get_transit_public_url( $coachee_id, 'custom', array(
										'start' => $_start_date,
										'end'   => $_end_date,
									) );
								} else {
									$_source_url = (string) bcpro_get_transit_public_url( $coachee_id, $period );
								}
							}
							$passages[] = array(
								'title'    => 'Transit — ' . $_label . ' (Day-by-day)',
								'body'     => $_body,
								'metadata' => array(
									'source'      => 'faa2_transit_range',
									'source_url'  => $_source_url,
									'source_provenance' => array(
										'provider'   => 'faa2_western',
										'resolver'   => 'cap_filter_daily_range',
										'source'     => 'live_range',
										'trace_id'   => $trace_id,
										'fetched_at' => current_time( 'mysql' ),
									),
									'num_days'    => $num_days,
									'start_offset'=> $start_offset,
									'render_mode' => 'daily',
									'period'      => $period,
									'kind'        => 'astro_transit_daily',
									'day_items_raw' => $_daily_rows,
									// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 unified artifact links in CAP metadata.
									'artifact_links' => function_exists( 'bcpro_get_astro_artifact_links' )
										? bcpro_get_astro_artifact_links( $coachee_id, '', $period )
										: array(),
									'_degraded'   => null,
								),
							);
							return $passages;  // early-return — skip legacy resolver
						}
					}
				}
			}
		}
	}

	// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — render_mode='fallback': num_days > 31.
	if ( $render_mode === 'fallback' ) {
		$passages[] = array(
			'title' => 'Transit — thông báo giới hạn',
			'body'  => "**Lưu ý:** Hiện tại hệ thống chưa thể tính toán chi tiết từng ngày cho khoảng thời gian trên 31 ngày"
				. ( $req_days > 0 ? " (bạn yêu cầu {$req_days} ngày tới)" : '' ) . ".\n\n"
				. "Dưới đây là tổng quan transit 30 ngày gần nhất:",
			'metadata' => array(
				'source'          => 'fallback_note',
				// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — preserve source provenance for fallback branch.
				'source_provenance' => array(
					'provider'   => 'fallback_note',
					'resolver'   => 'cap_filter_fallback',
					'source'     => 'range_capped_30',
					'trace_id'   => $trace_id,
					'fetched_at' => current_time( 'mysql' ),
				),
				'kind'            => 'astro_transit_fallback',
				'requested_days'  => $req_days,
				'capped_at'       => 30,
				// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 unified artifact links in CAP metadata.
				'artifact_links'  => function_exists( 'bcpro_get_astro_artifact_links' )
					? bcpro_get_astro_artifact_links( $coachee_id, '', $period )
					: array(),
				'_degraded'       => 'range_capped_30',
			),
		);
		// Fall through to legacy resolver for the 30-day overview
		$num_days    = 30;
		$render_mode = 'overview';
	}

	$resolver = BizCoach_Pro_Astro_Transit_Resolver::instance();
	$resolved = $resolver->resolve( $coachee_id, $period, array(
		'user_id'             => $user_id,
		'detect_from_message' => $message,
		'trace_id'            => $trace_id,
	) );

	$add = $resolver->to_passages( $resolved );
	if ( ! empty( $add ) ) {
		foreach ( $add as $p ) { $passages[] = $p; }
	}
	return $passages;
}, 10, 4 );

/**
 * Format transit_range daily[] array into LLM-readable markdown.
 *
 * [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — helper for CAP filter.
 */
if ( ! function_exists( '_bcpro_cap_format_transit_range' ) ) {
	function _bcpro_cap_format_transit_range( array $daily, string $label ): string {
		if ( empty( $daily ) ) { return ''; }
		$lines = array( "## Transit {$label} — Day-by-Day\n" );
		foreach ( $daily as $day ) {
			$date    = (string) ( $day['date'] ?? '' );
			$aspects = (array)  ( $day['aspects'] ?? array() );
			if ( $date === '' ) { continue; }
			if ( empty( $aspects ) ) {
				$lines[] = "**{$date}** — Không có transit aspect đáng chú ý.";
				continue;
			}
			$lines[] = "**{$date}**";
			foreach ( $aspects as $a ) {
				$tp      = (string) ( $a['transit_planet'] ?? '' );
				$np      = (string) ( $a['natal_planet']   ?? '' );
				$asp     = (string) ( $a['aspect']         ?? '' );
				$orb     = (float)  ( $a['orb']            ?? 0 );
				$forming = isset( $a['forming'] ) ? ( $a['forming'] ? ' (forming)' : ' (separating)' ) : '';
				if ( $tp && $np && $asp ) {
					$lines[] = "  - Transit **{$tp}** {$asp} natal **{$np}** (orb {$orb}°{$forming})";
				}
			}
		}
		return implode( "\n", $lines );
	}
}

/* -------------------------------------------------------------------
 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — bcpro_get_transit_public_url()
 * Canonical helper dùng cho automation block action.run_astro_transit và
 * các caller cần public /my-transit/ URL với hash auth (không cần login).
 * Dùng cùng hash mechanism với natal chart (AUTH_KEY salt + coachee_id).
 * ------------------------------------------------------------------- */
if ( ! function_exists( 'bcpro_get_transit_public_url' ) ) {
	function bcpro_get_transit_public_url( $coachee_id, $period = 'day', $extra = array() ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) { return ''; }
		$extra = is_array( $extra ) ? $extra : array();

		// Prefer Transit Public Router if available
		if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' )
			&& method_exists( 'BizCoach_Pro_Transit_Public_Router', 'get_public_url' ) ) {
			return (string) BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, $period, $extra );
		}

		// Fallback: reuse natal chart hash (same salt) for /my-transit/
		$hash = function_exists( 'bccm_generate_natal_chart_hash' )
			? bccm_generate_natal_chart_hash( $coachee_id )
			: substr( md5( $coachee_id . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bccm' ) ), 0, 16 );

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — support day-specific public links.
		$_args = array(
			'id'     => $coachee_id,
			'hash'   => $hash,
			'period' => sanitize_key( $period ),
		);
		if ( ! empty( $extra['start'] ) )      { $_args['start']      = (string) $extra['start']; }
		if ( ! empty( $extra['end'] ) )        { $_args['end']        = (string) $extra['end']; }
		if ( ! empty( $extra['day'] ) )        { $_args['day']        = (string) $extra['day']; }
		if ( ! empty( $extra['date'] ) )       { $_args['date']       = (string) $extra['date']; }
		if ( ! empty( $extra['format'] ) )     { $_args['format']     = (string) $extra['format']; }
		if ( ! empty( $extra['regenerate'] ) ) { $_args['regenerate'] = 1; }

		return add_query_arg( $_args, home_url( '/my-transit/' ) );
	}
}

/* -------------------------------------------------------------------
 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 Artifact Catalog helper
 * Canonical links object for TwinBrain CAP metadata + automation ctx.
 * ------------------------------------------------------------------- */
if ( ! function_exists( 'bcpro_get_astro_artifact_links' ) ) {
	function bcpro_get_astro_artifact_links( $coachee_id, $chat_id = '', $period = 'day' ) {
		$coachee_id = (int) $coachee_id;
		$chat_id    = (string) $chat_id;
		$period     = sanitize_key( (string) $period );
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			$period = 'day';
		}

		$links = array(
			'wheel'             => '',
			'western_vi'        => '',
			'western_en'        => '',
			'western_regenerate'=> '',
			'vedic'             => '',
			'chinese'           => '',
			'transit_day'       => '',
			'transit_week'      => '',
			'transit_month'     => '',
			'transit_year'      => '',
		);

		if ( $coachee_id <= 0 ) {
			return $links;
		}

		if ( function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$links['wheel'] = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		if ( function_exists( 'bcpro_get_astro_public_url' ) ) {
			$links['western_vi']         = (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
			$links['western_regenerate'] = (string) bcpro_get_astro_public_url( $coachee_id, 'western', true );
			$links['vedic']              = (string) bcpro_get_astro_public_url( $coachee_id, 'vedic' );
			$links['chinese']            = (string) bcpro_get_astro_public_url( $coachee_id, 'chinese' );
		}

		if ( $links['western_vi'] === '' ) {
			$links['western_vi'] = $links['wheel'];
		}
		if ( $links['western_vi'] !== '' ) {
			$links['western_en'] = (string) add_query_arg( 'lang', 'en', $links['western_vi'] );
		}

		if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
			$links['transit_day']   = (string) bcpro_get_transit_public_url( $coachee_id, 'day' );
			$links['transit_week']  = (string) bcpro_get_transit_public_url( $coachee_id, 'week' );
			$links['transit_month'] = (string) bcpro_get_transit_public_url( $coachee_id, 'month' );
			$links['transit_year']  = (string) bcpro_get_transit_public_url( $coachee_id, 'year' );
		}

		return apply_filters( 'bcpro_astro_artifact_links', $links, $coachee_id, $chat_id, $period );
	}
}

/* ----------------------------------------------------
 * Intent Provider — Sprint I, stub require for forward compat
 * -------------------------------------------------- */
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
	require_once BCPRO_DIR . 'includes/coaching/class-intent-provider.php';
	add_action( 'bizcity_intent_register_providers', function ( $registry ) {
		if ( $registry && method_exists( $registry, 'register' ) ) {
			$registry->register( new BizCoach_Pro_Intent_Provider() );
		}
	} );
}

/* ----------------------------------------------------
 * REST routes — list templates, create artifact (Sprint I extends)
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/class-rest.php';
add_action( 'rest_api_init', [ 'BizCoach_Pro_Rest', 'register_routes' ] );

/* ----------------------------------------------------
 * Coach Builder (Sprint K.B) — public landing /coach-builder/ + AI quick-fill.
 * Adds REST endpoints under bizcoach-pro/v1/coach-builder/* and injects the
 * AI-fill widget into legacy admin Step 2 page.
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/coaching/class-section-renderer.php';
require_once BCPRO_DIR . 'includes/coaching/class-coach-builder.php';
BizCoach_Pro_Coach_Builder::init();

/* ----------------------------------------------------
 * Sprint Diagnostic (R-DDV) — own tools.php page (admin only).
 * URL: /wp-admin/tools.php?page=bizcoach-pro-diag
 * Mirrors BizCity_CRM_Sprint_Diagnostic singleton pattern.
 * -------------------------------------------------- */
if ( is_admin() ) {
	require_once BCPRO_DIR . 'includes/class-sprint-diagnostic.php';
	BizCoach_Pro_Sprint_Diagnostic::instance();
}

/* ----------------------------------------------------
 * Manual rewrite-flush handler for F.12 panel button.
 * Used to recover /coachee-map/{key}/ after legacy plugin's deactivation
 * hook purged the rule from DB.
 * -------------------------------------------------- */
add_action( 'admin_post_bcpro_legacy_force_flush', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
	check_admin_referer( 'bcpro_legacy_force_flush' );
	delete_option( 'bcpro_adopter_flushed_v1' ); // force one-shot to re-run on next init
	flush_rewrite_rules( false );
	wp_safe_redirect( admin_url( 'tools.php?page=bizcoach-pro-diag&flushed=1' ) );
	exit;
} );

/* ----------------------------------------------------
 * R-NO-CONFLICT runtime sentinel (PHASE-0.36 §5b)
 * --------------------------------------------------
 * Khi cả `bizcoach-map` (legacy) và `bizcoach-pro` cùng active, log cảnh
 * báo nếu phát hiện collision namespace. KHÔNG fatal — chỉ surface warning
 * cho admin biết có vi phạm contract. DDV Phase F probe sẽ chặn ship.
 */
add_action( 'plugins_loaded', function () {
	if ( ! defined( 'BCCM_VERSION' ) ) { return; } // legacy not active → nothing to check
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) { return; }

	$violations = [];

	// Persona id collision (R-NO-CONFLICT.4)
	if ( class_exists( 'BizCoach_Pro_Persona_Provider' ) && class_exists( 'BizCoach_Persona_Provider' ) ) {
		$pro_id    = ( new BizCoach_Pro_Persona_Provider() )->id();
		$legacy_id = ( new BizCoach_Persona_Provider() )->id();
		if ( $pro_id === $legacy_id ) {
			$violations[] = 'Persona Provider id() collision: pro=' . $pro_id . ' legacy=' . $legacy_id;
		}
	}

	// Source-kind collision (R-NO-CONFLICT)
	if ( class_exists( 'BizCoach_Pro_Persona_Provider' ) && class_exists( 'BizCoach_Persona_Provider' ) ) {
		$pro_kinds    = ( new BizCoach_Pro_Persona_Provider() )->get_source_kinds();
		$legacy_kinds = ( new BizCoach_Persona_Provider() )->get_source_kinds();
		$overlap = array_intersect( (array) $pro_kinds, (array) $legacy_kinds );
		if ( ! empty( $overlap ) ) {
			$violations[] = 'Persona source_kinds overlap: ' . implode( ',', $overlap );
		}
	}

	if ( ! empty( $violations ) ) {
		error_log( '[bizcoach-pro] R-NO-CONFLICT VIOLATION → ' . implode( ' | ', $violations ) );
	}
}, 50 );
