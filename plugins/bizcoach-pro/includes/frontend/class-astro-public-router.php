<?php
/**
 * BizCoach Pro — Astrology Public Router (Sprint H.6)
 *
 * Public hash-protected URLs for the 3 astrology systems so generated
 * luận giải pages can be shared with end-users without admin login:
 *
 *   /my-western-astrology/?id=&hash=   (chart_type=western)
 *   /my-vedic-astrology/?id=&hash=     (chart_type=vedic)
 *   /my-chinese-astrology/?id=&hash=   (chart_type=chinese)
 *
 * Replaces the legacy admin-ajax.php?action=bccm_natal_report_full flow
 * (nonce-based, admin-only — unshareable). The underlying renderer
 * (`bccm_natal_report_full_handler`) is reused by setting a global
 * bypass flag after the hash is verified.
 *
 * @since 0.35.x
 * @see   legacy/lib/astro-report-llm.php   (renderer)
 * @see   legacy/lib/astro-transit-report.php (transit renderer)
 * @see   legacy/includes/frontend-natal-chart.php (pattern this generalizes)
 */

defined( 'ABSPATH' ) || exit;

// ────────────────────────────────────────────────────────────────
// Global helper functions — defined FIRST so they are always
// available even if this file was previously loaded and the class
// guard below early-returns. Without this, the legacy renderer in
// astro-report-llm.php sees `function_exists('bcpro_astro_public_ctx_matches')`
// as false and falls back to the admin-only nonce path, which prints
// `-1` on public hash-protected URLs.
// ────────────────────────────────────────────────────────────────

if ( ! function_exists( 'bcpro_astro_public_ctx_matches' ) ) {
	/**
	 * Auth-check helper used by patched legacy handlers (R-SEC) — accepts
	 * either the legacy nonce flow OR the public hash flow.
	 */
	function bcpro_astro_public_ctx_matches( $coachee_id, $chart_type ) {
		if ( empty( $GLOBALS['bcpro_public_astro_ctx'] ) ) { return false; }
		$ctx = $GLOBALS['bcpro_public_astro_ctx'];
		if ( (int) $ctx['coachee_id'] !== (int) $coachee_id ) { return false; }
		if ( (string) $ctx['chart_type'] !== (string) $chart_type ) { return false; }
		if ( ! class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) { return false; }
		return BizCoach_Pro_Astro_Public_Router::verify_hash( $coachee_id, $chart_type, $ctx['hash'] );
	}
}

if ( ! function_exists( 'bcpro_get_astro_public_url' ) ) {
	/** Convenience global URL builder (mirror legacy bccm_get_natal_chart_public_url). */
	function bcpro_get_astro_public_url( $coachee_id, $chart_type = 'western', $regenerate = false ) {
		if ( ! class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) { return ''; }
		return BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, $chart_type, $regenerate );
	}
}

if ( class_exists( 'BizCoach_Pro_Astro_Public_Router' ) ) { return; }

class BizCoach_Pro_Astro_Public_Router {

	/** @var string[] Map of URL slug → chart_type. */
	const SYSTEMS = array(
		'western' => 'my-western-astrology',
		'vedic'   => 'my-vedic-astrology',
		'chinese' => 'my-chinese-astrology',
	);

	const QUERY_VAR = 'bcpro_astro_view';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
	}

	public static function register_rewrites() {
		foreach ( self::SYSTEMS as $type => $slug ) {
			add_rewrite_rule(
				'^' . preg_quote( $slug, '/' ) . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . $type,
				'top'
			);
		}
	}

	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'chart_id';
		$vars[] = 'chart_hash';
		$vars[] = 'regenerate';
		return (array) $vars;
	}

	public static function maybe_handle() {
		$type = get_query_var( self::QUERY_VAR );
		if ( ! $type || ! array_key_exists( $type, self::SYSTEMS ) ) {
			return;
		}

		$coachee_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : (int) get_query_var( 'chart_id' );
		$hash       = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : (string) get_query_var( 'chart_hash' );
		$regenerate = ! empty( $_GET['regenerate'] ) ? 1 : 0;

		if ( $coachee_id <= 0 || $hash === '' ) {
			status_header( 400 );
			wp_die( esc_html__( 'Thiếu tham số id hoặc hash.', 'bizcoach-pro' ) );
		}

		if ( ! self::verify_hash( $coachee_id, $type, $hash ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Đường dẫn không hợp lệ hoặc đã hết hạn.', 'bizcoach-pro' ) );
		}

		// Make context available to legacy report handler so it skips
		// nonce + cap checks. See patches in astro-report-llm.php /
		// astro-transit-report.php (auth header block).
		$GLOBALS['bcpro_public_astro_ctx'] = array(
			'coachee_id' => $coachee_id,
			'chart_type' => $type,
			'hash'       => $hash,
		);

		// Lazy-load report dependencies (admin-only require chain skips them
		// on public hits otherwise).
		self::ensure_renderer_loaded();

		// Synthesize the GET payload the legacy handler expects.
		$_GET['coachee_id'] = $coachee_id;
		$_GET['chart_type'] = $type;
		$_GET['hash']       = $hash;
		if ( $regenerate ) { $_GET['regenerate'] = 1; }

		if ( $type === 'chinese' && ! function_exists( 'bccm_natal_report_full_handler' ) ) {
			// Chinese system shares the same renderer (chart_type discriminator).
			status_header( 500 );
			wp_die( esc_html__( 'Renderer chưa được nạp.', 'bizcoach-pro' ) );
		}

		if ( ! function_exists( 'bccm_natal_report_full_handler' ) ) {
			status_header( 500 );
			wp_die( esc_html__( 'AJAX handler chưa khả dụng.', 'bizcoach-pro' ) );
		}

		bccm_natal_report_full_handler();
		exit;
	}

	/**
	 * Make sure the legacy renderer (`bccm_natal_report_full_handler`) is
	 * loaded. On public requests the legacy adopter may not have run yet
	 * because some of its includes are gated to is_admin().
	 */
	private static function ensure_renderer_loaded() {
		if ( function_exists( 'bccm_natal_report_full_handler' ) ) { return; }
		$candidates = array(
			BCPRO_DIR . 'legacy/lib/astro-report-llm.php',
			BCPRO_DIR . 'legacy/lib/astro-helpers.php',
		);
		foreach ( $candidates as $f ) {
			if ( file_exists( $f ) ) { require_once $f; }
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Hash helpers (chart_type bound, HMAC-SHA256)
	 * ────────────────────────────────────────────────────────────── */

	public static function generate_hash( $coachee_id, $chart_type = 'western' ) {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bcpro_astro';
		$msg  = (int) $coachee_id . '|' . (string) $chart_type;
		return substr( hash_hmac( 'sha256', $msg, $salt ), 0, 32 );
	}

	public static function verify_hash( $coachee_id, $chart_type, $hash ) {
		$expected = self::generate_hash( $coachee_id, $chart_type );
		if ( hash_equals( $expected, (string) $hash ) ) { return true; }

		// Back-compat: accept legacy western hash for chart_type=western
		// so existing /my-natal-chart/ shares stay valid through migration.
		if ( $chart_type === 'western' && function_exists( 'bccm_generate_natal_chart_hash' ) ) {
			$legacy = bccm_generate_natal_chart_hash( $coachee_id );
			if ( hash_equals( $legacy, (string) $hash ) ) { return true; }
		}
		return false;
	}

	/**
	 * Public URL builder. Returns home_url() variant.
	 */
	public static function get_public_url( $coachee_id, $chart_type = 'western', $regenerate = false ) {
		if ( ! array_key_exists( $chart_type, self::SYSTEMS ) ) { $chart_type = 'western'; }
		$slug = self::SYSTEMS[ $chart_type ];
		$args = array(
			'id'   => (int) $coachee_id,
			'hash' => self::generate_hash( $coachee_id, $chart_type ),
		);
		if ( $regenerate ) { $args['regenerate'] = 1; }
		return add_query_arg( $args, home_url( '/' . $slug . '/' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * One-shot rewrite flush helper — called from activation hook.
	 * ────────────────────────────────────────────────────────────── */
	public static function flush_on_activation() {
		self::register_rewrites();
		flush_rewrite_rules( false );
	}
}
