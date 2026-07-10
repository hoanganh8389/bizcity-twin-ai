<?php
/**
 * BizCoach Pro — Self-Service Astrology Frontend Page
 *
 * Registers the public URL /astro/ as a virtual page that renders the React
 * self-service UI (shortcode [bcpro_my_astro_profile]).
 *
 * Strategy:
 *   1. add_rewrite_rule '^astro/?$' → query var bcpro_astro_page=1
 *   2. template_redirect intercepts → renders custom template (no WP theme)
 *   3. Admin submenu "🌙 Chiêm tinh" links to /astro/ via iframe on admin page
 *
 * Flush: one-shot via 'bcpro_self_service_page_version' option, re-runs on
 * BCPRO_VERSION bump (same pattern as BizCoach_Pro_Astro_Public_Router).
 *
 * @package BizCoach_Pro
 * @since   0.5.0 (PHASE-A A-FE-1 · 2026-06-05)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Self_Service_Page' ) ) { return; }

class BizCoach_Pro_Self_Service_Page {

	const PAGE_SLUG  = 'astro';
	const QUERY_VAR  = 'bcpro_astro_page';
	const ADMIN_SLUG = 'bcpro_my_astro';
	const OPTION_VER = 'bcpro_self_service_page_version';

	/** Bootstrap — call once from bizcoach-pro.php */
	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );

		// Admin submenu (only when is_admin())
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 35 );

		// One-shot flush when version changes
		// [2026-06-09 Johnny Chu] HOTFIX — moved init:99 → admin_init:99 (prevent frontend flush loop)
		add_action( 'admin_init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/* ------------------------------------------------------------------ *
	 * Rewrite
	 * ------------------------------------------------------------------ */
	public static function register_rewrite() {
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* ------------------------------------------------------------------ *
	 * Template redirect — handle /astro/
	 * ------------------------------------------------------------------ */
	public static function maybe_handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — redirect unauthenticated to login
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( home_url( '/' . self::PAGE_SLUG . '/' ) );
			wp_redirect( esc_url_raw( $login_url ) );
			exit;
		}

		// [2026-06-06 Johnny Chu] PHASE-A A-FE-3 — standalone shell
		// /astro/ is a full-page React app; nuke admin bar, theme chrome, chat widgets,
		// floating buttons, body overlays, etc. Only our React build is allowed through.
		add_filter( 'show_admin_bar', '__return_false' );

		// Strip wp_head down to bare essentials (charset/styles/scripts only) ─
		remove_all_actions( 'wp_head' );
		add_action( 'wp_head', 'wp_enqueue_scripts', 1 );
		add_action( 'wp_head', 'wp_resource_hints', 2 );
		add_action( 'wp_head', 'wp_print_styles', 8 );
		add_action( 'wp_head', 'wp_print_head_scripts', 9 );

		// Strip wp_footer down to just script printing — removes bizchat-float-btn,
		// button-contact-vr, gom-all-in-one, ux-body-overlay, admin bar render, etc.
		remove_all_actions( 'wp_footer' );
		add_action( 'wp_footer', 'wp_print_footer_scripts', 20 );

		// Dequeue any non-bcpro style/script that slipped through (after enqueue, before print).
		add_action( 'wp_print_styles',         array( __CLASS__, 'whitelist_assets' ), 9999 );
		add_action( 'wp_print_scripts',        array( __CLASS__, 'whitelist_assets' ), 9999 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'whitelist_assets' ), 1 );

		// Enqueue React build now so it's available when wp_head fires
		if ( class_exists( 'BizCoach_Pro_Self_Service_Shortcode' ) ) {
			BizCoach_Pro_Self_Service_Shortcode::enqueue_assets();
		}

		self::render_page();
		exit;
	}

	/**
	 * [2026-06-06 Johnny Chu] PHASE-A A-FE-3
	 * Whitelist enqueued styles/scripts down to React build + WP core required pieces.
	 * Runs late (priority 9999) on wp_print_styles + wp_print_scripts so the dequeue
	 * happens AFTER every other plugin has registered its assets.
	 */
	public static function whitelist_assets() {
		$allow = array(
			'bcpro-self-service',
			'bcpro-self-service-0', 'bcpro-self-service-1', 'bcpro-self-service-2',
			'bcpro-self-service-3', 'bcpro-self-service-4',
			'wp-polyfill',
		);

		// Styles
		if ( isset( $GLOBALS['wp_styles'] ) && is_object( $GLOBALS['wp_styles'] ) ) {
			$queue = (array) $GLOBALS['wp_styles']->queue;
			foreach ( $queue as $handle ) {
				if ( in_array( $handle, $allow, true ) ) continue;
				if ( strpos( (string) $handle, 'bcpro-' ) === 0 ) continue;
				wp_dequeue_style( $handle );
			}
		}

		// Scripts
		if ( isset( $GLOBALS['wp_scripts'] ) && is_object( $GLOBALS['wp_scripts'] ) ) {
			$queue = (array) $GLOBALS['wp_scripts']->queue;
			foreach ( $queue as $handle ) {
				if ( in_array( $handle, $allow, true ) ) continue;
				if ( strpos( (string) $handle, 'bcpro-' ) === 0 ) continue;
				wp_dequeue_script( $handle );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Full-page renderer — standalone HTML, no WP theme
	 * ------------------------------------------------------------------ */
	public static function render_page() {
		$site_name  = esc_html( get_bloginfo( 'name' ) );
		$mount_html = '<div id="bcpro-self-service"></div>';

		?><!doctype html>
<html lang="vi" <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Chiêm tinh của tôi — <?php echo $site_name; ?></title>
<style>
/* Reset for standalone shell — avoids 1px scrollbar from WP defaults. */
html, body { margin: 0; padding: 0; height: 100%; background: #fff; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }
</style>
<?php wp_head(); ?>
</head>
<body class="bcpro-astro-page">
<?php echo $mount_html; ?>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Admin submenu — "🌙 Chiêm tinh của tôi"
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1
	 * Adds a submenu under the legacy BizCoach root (bccm_root). The page
	 * embeds an iframe to /astro/ so the admin/subscriber sees the React app
	 * without leaving the WP admin shell. Non-manage_options users can still
	 * access /astro/ directly (public login-gated page).
	 * ------------------------------------------------------------------ */
	public static function register_admin_menu() {
		// Attach under the BizCoach root if it exists, else create own top-level.
		$parent = menu_page_url( 'bccm_root', false ) ? 'bccm_root' : null;

		if ( $parent ) {
			add_submenu_page(
				$parent,
				'🌙 Chiêm tinh của tôi',
				'🌙 Chiêm tinh',
				'read',                         // any logged-in user (not just admins)
				self::ADMIN_SLUG,
				array( __CLASS__, 'render_admin_page' ),
				38                              // after BaZi (37)
			);
		} else {
			// Standalone top-level fallback (no BizCoach root menu present)
			add_menu_page(
				'🌙 Chiêm tinh của tôi',
				'🌙 Chiêm tinh',
				'read',
				self::ADMIN_SLUG,
				array( __CLASS__, 'render_admin_page' ),
				'dashicons-star-filled',
				82
			);
		}
	}

	public static function render_admin_page() {
		$astro_url = esc_url( home_url( '/' . self::PAGE_SLUG . '/' ) );
		?>
		<div class="wrap" style="height:calc(100vh - 64px);display:flex;flex-direction:column;padding:0 0 0 0;margin-top:-8px;">
			<iframe
				src="<?php echo $astro_url; ?>"
				style="flex:1;width:100%;border:none;display:block;"
				title="Chiêm tinh của tôi"
				id="bcpro-astro-iframe"
			></iframe>
		</div>
		<style>
			#wpcontent { padding-left: 0 !important; }
			.bcpro-astro-iframe-wrap { margin: 0; padding: 0; }
		</style>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * One-shot flush — runs at admin_init priority 99, once per BCPRO_REWRITE_VERSION
	 * [2026-06-09 Johnny Chu] HOTFIX — use BCPRO_REWRITE_VERSION (stable '0.3.23') instead of
	 * BCPRO_VERSION which contains time() → guard NEVER matched → flush on every request.
	 * update_option() set BEFORE flush_on_activation() so guard persists even if flush throws.
	 * ------------------------------------------------------------------ */
	public static function maybe_flush() {
		$ver    = defined( 'BCPRO_REWRITE_VERSION' ) ? BCPRO_REWRITE_VERSION : '0.3.23';
		$stored = (string) get_option( self::OPTION_VER, '' );
		if ( $stored === $ver ) {
			return;
		}
		update_option( self::OPTION_VER, $ver, false );
		self::flush_on_activation();
	}

	public static function flush_on_activation() {
		self::register_rewrite();
		flush_rewrite_rules( false );
	}
}
