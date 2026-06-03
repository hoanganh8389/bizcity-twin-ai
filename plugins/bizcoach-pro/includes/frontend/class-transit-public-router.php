<?php
/**
 * BizCoach Pro — Transit Public Router
 *
 * Hash-protected public URL for transit timeline / snapshot pages:
 *
 *   /my-transit/?id=&hash=&period=day|week|month|year|custom[&start=YYYY-MM-DD&end=YYYY-MM-DD]
 *
 * Auth model mirrors Astro_Public_Router (HMAC-SHA256, chart_type='transit').
 * Period & date params are NOT in the signed payload — they only select which
 * slice to render, not which coachee. This lets a single share link cover all
 * ranges (day/week/month/year/custom).
 *
 * Rendering is delegated to bccm_transit_report_handler() in
 * legacy/lib/astro-transit-report.php, which is patched (alongside
 * astro-report-llm.php) to accept the public context via
 * bcpro_astro_public_ctx_matches() instead of requiring nonce + admin cap.
 *
 * @since 0.36.x
 */

defined( 'ABSPATH' ) || exit;

// Global helper — defined FIRST so it survives the class guard
// (mirrors the pattern in class-astro-public-router.php).
if ( ! function_exists( 'bcpro_get_transit_public_url' ) ) {
	/**
	 * @param int    $coachee_id
	 * @param string $period   day|week|month|year|custom
	 * @param array  $extra    ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD', 'regenerate' => 1]
	 */
	function bcpro_get_transit_public_url( $coachee_id, $period = 'week', $extra = array() ) {
		if ( ! class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) { return ''; }
		return BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, $period, $extra );
	}
}

if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) { return; }

class BizCoach_Pro_Transit_Public_Router {

	const SLUG       = 'my-transit';
	const QUERY_VAR  = 'bcpro_transit_view';
	const CHART_TYPE = 'transit';

	/** Allowed period values (also drives default range_start/range_end). */
	const PERIODS = array( 'day', 'week', 'month', 'year', 'custom' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
	}

	public static function register_rewrites() {
		add_rewrite_rule(
			'^' . preg_quote( self::SLUG, '/' ) . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'transit_period';
		$vars[] = 'transit_start';
		$vars[] = 'transit_end';
		return (array) $vars;
	}

	public static function maybe_handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) { return; }

		$coachee_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$hash       = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		$period     = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'week';
		$start      = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
		$end        = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : '';
		$regenerate = ! empty( $_GET['regenerate'] ) ? 1 : 0;

		if ( $coachee_id <= 0 || $hash === '' ) {
			status_header( 400 );
			wp_die( esc_html__( 'Thiếu tham số id hoặc hash.', 'bizcoach-pro' ) );
		}

		if ( ! in_array( $period, self::PERIODS, true ) ) { $period = 'week'; }

		if ( ! self::verify_hash( $coachee_id, $hash ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Đường dẫn không hợp lệ hoặc đã hết hạn.', 'bizcoach-pro' ) );
		}

		// Expose context to legacy handler (chart_type='transit' so
		// bcpro_astro_public_ctx_matches($coachee, 'transit') returns true).
		$GLOBALS['bcpro_public_astro_ctx'] = array(
			'coachee_id' => $coachee_id,
			'chart_type' => self::CHART_TYPE,
			'hash'       => $hash,
		);

		self::ensure_renderer_loaded();

		// Synthesize GET payload for the legacy AJAX handler.
		$_GET['coachee_id'] = $coachee_id;
		$_GET['period']     = $period;
		if ( $start !== '' ) { $_GET['start'] = $start; }
		if ( $end   !== '' ) { $_GET['end']   = $end;   }
		if ( $regenerate )   { $_GET['regenerate'] = 1; }

		if ( ! function_exists( 'bccm_transit_report_handler' ) ) {
			status_header( 500 );
			wp_die( esc_html__( 'Transit renderer chưa được nạp.', 'bizcoach-pro' ) );
		}

		bccm_transit_report_handler();
		exit;
	}

	private static function ensure_renderer_loaded() {
		if ( function_exists( 'bccm_transit_report_handler' ) ) { return; }
		$candidates = array(
			BCPRO_DIR . 'legacy/lib/astro-transit-report.php',
			BCPRO_DIR . 'legacy/lib/astro-transit.php',
			BCPRO_DIR . 'legacy/lib/astro-transit-timeline.php',
			BCPRO_DIR . 'legacy/lib/astro-transit-ai.php',
			BCPRO_DIR . 'legacy/lib/astro-report-llm.php',
			BCPRO_DIR . 'legacy/lib/astro-helpers.php',
		);
		foreach ( $candidates as $f ) {
			if ( file_exists( $f ) ) { require_once $f; }
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Hash helpers
	 * ────────────────────────────────────────────────────────────── */

	public static function generate_hash( $coachee_id ) {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bcpro_astro';
		$msg  = (int) $coachee_id . '|' . self::CHART_TYPE;
		return substr( hash_hmac( 'sha256', $msg, $salt ), 0, 32 );
	}

	public static function verify_hash( $coachee_id, $hash ) {
		return hash_equals( self::generate_hash( $coachee_id ), (string) $hash );
	}

	/**
	 * Public URL builder.
	 *
	 * @param int    $coachee_id
	 * @param string $period      day|week|month|year|custom
	 * @param array  $extra       ['start','end','regenerate']
	 */
	public static function get_public_url( $coachee_id, $period = 'week', $extra = array() ) {
		if ( ! in_array( $period, self::PERIODS, true ) ) { $period = 'week'; }
		$args = array(
			'id'     => (int) $coachee_id,
			'hash'   => self::generate_hash( $coachee_id ),
			'period' => $period,
		);
		if ( ! empty( $extra['start'] ) )      { $args['start']      = (string) $extra['start']; }
		if ( ! empty( $extra['end'] ) )        { $args['end']        = (string) $extra['end']; }
		if ( ! empty( $extra['regenerate'] ) ) { $args['regenerate'] = 1; }
		return add_query_arg( $args, home_url( '/' . self::SLUG . '/' ) );
	}

	public static function flush_on_activation() {
		self::register_rewrites();
		flush_rewrite_rules( false );
	}
}
