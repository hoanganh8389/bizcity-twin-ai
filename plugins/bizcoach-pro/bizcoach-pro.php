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
define( 'BCPRO_VERSION', '0.3.2' );
define( 'BCPRO_DB_VERSION', '1.0.1' );
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
// Version-gated rewrite flush — re-runs automatically every time BCPRO_VERSION
// bumps so newly added/changed rewrite rules become resolvable on next request
// without requiring a manual Permalinks re-save. Doubles as a lightweight
// migration trigger: bump BCPRO_VERSION in any release that adds endpoints.
add_action( 'init', function () {
	$stored = (string) get_option( 'bcpro_astro_router_version', '' );
	if ( $stored === BCPRO_VERSION ) { return; }
	if ( class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) {
		BizCoach_Pro_Astro_Public_Router::flush_on_activation();
	}
	if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) {
		BizCoach_Pro_Transit_Public_Router::flush_on_activation();
	}
	update_option( 'bcpro_astro_router_version', BCPRO_VERSION, false );
}, 99 );

/* ----------------------------------------------------
 * PHASE-0.2 Sprint G.1 / G.5 — Astro Gateway Client + Admin Settings.
 * Load BEFORE legacy adopter so the legacy `bccm_astro_*` choke-point
 * helpers (refactored in Sprint G.2) can resolve the client class.
 * -------------------------------------------------- */
require_once BCPRO_DIR . 'includes/astro/class-astro-client.php';

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

	// PHASE-0.3 H.1 — Multi-system astrology list pages (Vedic + Chinese).
	require_once BCPRO_DIR . 'includes/admin/class-astro-admin-list.php';
	if ( class_exists( 'BizCoach_Pro_Astro_Admin_List' ) ) {
		BizCoach_Pro_Astro_Admin_List::init();
	}

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
