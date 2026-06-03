<?php
/**
 * Legacy Adopter Bootstrap — file-scope hooks ported from bizcoach-map.php
 *
 * Mirrors bizcoach-map.php lines 95–193 (everything after the require chain),
 * but skips:
 *   - register_activation_hook / register_deactivation_hook (different __FILE__,
 *     adopter is bundled, never (de)activated independently)
 *   - Intent_Provider / Persona_Provider blocks (R-NO-CONFLICT — bizcoach-pro
 *     owns those personas; shadow excludes the legacy class files)
 *
 * Adds: one-shot flush_rewrite_rules() after adoption (handles the case where
 * bizcoach-map's deactivation hook purged /coachee-map/<key>/ from rewrite DB).
 *
 * @since 0.2.1 (Sprint K.A3 — fix admin menus + /coachee-map/ 500)
 */
defined( 'ABSPATH' ) || exit;

/* ----------------------------------------------------
 * AGENT PAGE: /chiem-tinh-profile/
 * -------------------------------------------------- */
add_filter( 'template_include', function ( $template ) {
	if ( is_page( 'chiem-tinh-profile' ) ) {
		if ( ! empty( $_GET['bizcity_iframe'] ) ) {
			$mobile = BCCM_DIR . 'templates/page-agent-mobile.php';
			if ( file_exists( $mobile ) ) { return $mobile; }
		}
		$custom = BCCM_DIR . 'templates/page-chiem-tinh-profile.php';
		if ( file_exists( $custom ) ) { return $custom; }
	}
	if ( is_page( 'chiem-tinh-astro' ) ) {
		$custom = BCCM_DIR . 'templates/page-astro-landing.php';
		if ( file_exists( $custom ) ) { return $custom; }
	}
	return $template;
} );

/* ── Rewrite rule: /chiem-tinh-agent/ → Mobile agent page (standalone) ── */
add_action( 'init', function () {
	add_rewrite_rule( '^chiem-tinh-agent/?$', 'index.php?bizcity_agent_page=chiem-tinh-agent', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) { $vars[] = 'bizcity_agent_page'; }
	return $vars;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'bizcity_agent_page' ) === 'chiem-tinh-agent' ) {
		include BCCM_DIR . 'templates/page-agent-mobile.php';
		exit;
	}
} );

/* ----------------------------------------------------
 * Ensure profile page exists on current site.
 * (Reuses legacy flag bccm_page_ensured_v1 — won't re-run if legacy already did it.)
 * -------------------------------------------------- */
add_action( 'init', function () {
	$flag = 'bccm_page_ensured_v1';
	if ( get_option( $flag ) ) { return; }

	$existing = get_page_by_path( 'chiem-tinh-profile' );
	if ( $existing ) {
		if ( $existing->post_status === 'trash' ) {
			wp_update_post( array( 'ID' => $existing->ID, 'post_status' => 'publish' ) );
		}
	} else {
		wp_insert_post( array(
			'post_title'   => 'Hồ sơ chiêm tinh',
			'post_name'    => 'chiem-tinh-profile',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- Profile page managed by BizCoach Pro adopter -->',
		) );
	}

	flush_rewrite_rules();
	update_option( $flag, 1 );
}, 20 );

/* ----------------------------------------------------
 * Adopter takeover: flush rewrite rules ONCE after bizcoach-map deactivation.
 *
 * Why: bizcoach-map's register_deactivation_hook runs flush_rewrite_rules()
 * which PURGES /coachee-map/<key>/ from the DB. Adopter's frontend.php calls
 * add_rewrite_rule() at init, but that only registers in memory — needs flush
 * to persist. Without this, /coachee-map/<key>/ → 404 → 500 (ErrorDocument loop).
 *
 * Flag is bumped on each adopter version so a code change can re-flush.
 * -------------------------------------------------- */
add_action( 'init', function () {
	$flag    = 'bcpro_adopter_flushed_v1';
	$current = '1.' . ( defined( 'BCPRO_VERSION' ) ? BCPRO_VERSION : '0' );
	if ( get_option( $flag ) === $current ) { return; }

	flush_rewrite_rules( false );
	update_option( $flag, $current );
}, 99 );

/* ----------------------------------------------------
 * Provide bccm_activate() if legacy install code calls it elsewhere.
 * (Original: triggered by register_activation_hook; here we expose it as a
 * callable so admin code that references the function name doesn't fatal.)
 * -------------------------------------------------- */
if ( ! function_exists( 'bccm_activate' ) ) {
	function bccm_activate() {
		if ( function_exists( 'bccm_install_tables' ) ) { bccm_install_tables(); }
		if ( function_exists( 'bccm_add_rewrite' ) )    { bccm_add_rewrite(); }
		add_rewrite_endpoint( 'life-map', EP_ROOT | EP_PAGES );

		if ( ! get_page_by_path( 'chiem-tinh-profile' ) ) {
			wp_insert_post( array(
				'post_title'   => 'Hồ sơ chiêm tinh',
				'post_name'    => 'chiem-tinh-profile',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- Profile page managed by BizCoach Pro adopter -->',
			) );
		}
		flush_rewrite_rules();
	}
}

/* ----------------------------------------------------
 * Minimal assets
 * -------------------------------------------------- */
if ( ! function_exists( 'bccm_assets' ) ) {
	function bccm_assets() {
		wp_register_style( 'bccm-admin',  BCCM_URL . 'assets/admin.css',  array(), BCCM_VERSION );
		wp_register_style( 'bccm-public', BCCM_URL . 'assets/public.css', array(), BCCM_VERSION );
	}
}
add_action( 'init', 'bccm_assets' );

/* Auto-enqueue admin CSS on all BizCoach pages */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if (
		strpos( (string) $hook, 'bccm_' ) !== false ||
		strpos( (string) $hook, 'bizcoach' ) !== false ||
		( isset( $_GET['page'] ) && strpos( (string) $_GET['page'], 'bccm_' ) === 0 )
	) {
		wp_enqueue_style( 'bccm-admin' );
	}
} );
