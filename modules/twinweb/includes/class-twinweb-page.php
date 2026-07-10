<?php
/**
 * TwinWeb — Public Page (Shortcode Mode)
 *
 * Registers shortcode [bizcity_twin] for embedding the React SPA inside any
 * WP page. No standalone /twin/ URL — that slug belongs to TwinShell (Phase 0.11).
 *
 * Twinweb is surfaced exclusively via:
 *   1. Shortcode [bizcity_twin height="100vh"] on any WP page.
 *   2. Auto-created WP page at /twin-ai/ (created by class-twinweb-installer.php).
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 *
 * [2026-06-18 Johnny Chu] PHASE-TWINWEB — removed standalone /twin/ rewrite to
 * restore /twin/ ownership to TwinShell (class-twin-shell-page.php REWRITE_KEY=^twin/?$).
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Page' ) ) { return; }

class BizCity_TwinWeb_Page {

	// [2026-06-18 Johnny Chu] PHASE-TWINWEB — QUERY_VAR kept for legacy compat; REWRITE removed.
	// /twin/ belongs to twinshell (BizCity_Twin_Shell_Page::REWRITE_KEY = '^twin/?$').
	const QUERY_VAR = 'bizcity_twinweb_page';
	const DIST_DIR  = BIZCITY_TWINWEB_DIR . 'ui/dist/';
	const DIST_URL  = BIZCITY_TWINWEB_URL . 'ui/dist/';
	const HANDLE    = 'bizcity-twinweb-app';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — full-page takeover via template_redirect.
		// Detection: any singular page whose post_content contains [bizcity_twin shortcode.
		// No custom rewrite rule needed — zero /twin/ conflict with twinshell (Phase 0.11).
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Shortcode [bizcity_twin] registered for do_shortcode() compatibility in other surfaces.
		add_shortcode( 'bizcity_twin', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Intercept template loading for any WP page that contains [bizcity_twin shortcode.
	 * Outputs a standalone HTML shell (no WP theme) — same full-page experience as /twin/
	 * for twinshell, but without a custom rewrite rule.
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB — detect by post_content, not QUERY_VAR.
	 */
	public function maybe_render() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		// Only take over pages that have our shortcode in their content.
		if ( false === strpos( $post->post_content, '[bizcity_twin' ) ) {
			return;
		}

		// Full-page standalone takeover — no WP theme, no header/footer.
		add_filter( 'show_admin_bar', '__return_false' );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		// Pass current page URL for login/logout redirects.
		$page_url = (string) get_permalink( $post->ID );
		echo self::get_page_html( $page_url );
		exit;
	}

	/**
	 * Output the standalone HTML shell (no WP theme, no wp_head/wp_footer).
	 *
	 * @param string $page_url Redirect-back URL for login/logout links.
	 */
	private static function get_page_html( $page_url = '' ) {
		if ( $page_url === '' ) {
			$page_url = home_url( '/' );
		}
		$nonce       = wp_create_nonce( 'wp_rest' );
		$rest_url    = esc_js( rest_url() );
		$site_name   = esc_js( get_bloginfo( 'name' ) );
		$logo_url    = esc_js( has_custom_logo() ? (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '' );
		$user_id     = (int) get_current_user_id();
		$is_admin    = current_user_can( 'manage_options' ) ? 'true' : 'false';
		$admin_url   = esc_js( admin_url() );
		$login_url   = esc_js( wp_login_url( $page_url ) );
		$logout_url  = esc_js( wp_logout_url( $page_url ) );
		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — SSO URLs for AuthModal (Google + BizCity ID)
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — hub site uses login-with-google (wp-login.php?auto_google_login=1)
		//   Client sites use bizgpt-oauth-client-new (?auth=sso).
		//   Detect by checking if WPOSSO_Client class is loaded (blocked on hub blog_id 1258).
		if ( class_exists( 'WPOSSO_Client' ) ) {
			// Client site: OAuth SSO via bizcity.vn
			$sso_google_url  = esc_js( add_query_arg( array( 'auth' => 'sso', 'redirect_to' => rawurlencode( $page_url ) ), home_url( '/' ) ) );
			$sso_bizcity_url = esc_js( add_query_arg( array( 'auth' => 'sso', 'provider' => 'bizcity', 'redirect_to' => rawurlencode( $page_url ) ), home_url( '/' ) ) );
		} else {
			// Hub site (bizcity.vn): login-with-google plugin handles Google OAuth
			$sso_google_url  = esc_js( add_query_arg( 'auto_google_login', '1', wp_login_url( $page_url ) ) );
			// BizCity ID on hub = standard WP login (user IS on the provider)
			$sso_bizcity_url = esc_js( wp_login_url( $page_url ) );
		}
		$display     = '';
		$avatar      = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? esc_js( $u->display_name ) : '';
			$avatar  = esc_js( get_avatar_url( $user_id, array( 'size' => 40 ) ) );
		}

		// Build asset URLs from Vite manifest
		$manifest = self::read_manifest();
		$entry_js  = $manifest['js']  ?? '';
		$entry_css = $manifest['css'] ?? array();

		$css_tags = '';
		foreach ( $entry_css as $css_url ) {
			$css_tags .= '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' . "\n";
		}

		$js_tag = $entry_js ? '<script type="module" src="' . esc_url( $entry_js ) . '"></script>' : '<!-- twinweb: no JS build found -->';

		return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$site_name} — TwinWeb</title>
{$css_tags}
<script>
window.twinwebConfig = {
  restRoot:   "{$rest_url}",
  nonce:      "{$nonce}",
  siteName:   "{$site_name}",
  logoUrl:    "{$logo_url}",
  adminUrl:   "{$admin_url}",
  loginUrl:   "{$login_url}",
  logoutUrl:  "{$logout_url}",
  ssoGoogleUrl:  "{$sso_google_url}",
  ssoBizcityUrl: "{$sso_bizcity_url}",
  userId:     {$user_id},
  isAdmin:    {$is_admin},
  displayName:"{$display}",
  avatarUrl:  "{$avatar}"
};
</script>
</head>
<body>
<div id="bizcity-twinweb-root"></div>
{$js_tag}
</body>
</html>
HTML;
	}

	/**
	 * Parse Vite manifest and return { js: url, css: url[] }.
	 *
	 * @return array{js:string,css:string[]}
	 */
	private static function read_manifest() {
		// Vite 5 puts manifest at dist/.vite/manifest.json
		$paths = array(
			self::DIST_DIR . '.vite/manifest.json',
			self::DIST_DIR . 'manifest.json',
		);
		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$js  = '';
			$css = array();
			foreach ( $data as $entry ) {
				if ( ! empty( $entry['isEntry'] ) && isset( $entry['file'] ) ) {
					$js = self::DIST_URL . $entry['file'];
					if ( isset( $entry['css'] ) && is_array( $entry['css'] ) ) {
						foreach ( $entry['css'] as $css_file ) {
							$css[] = self::DIST_URL . $css_file;
						}
					}
				}
			}
			return array( 'js' => $js, 'css' => $css );
		}
		return array( 'js' => '', 'css' => array() );
	}

	/**
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB — kept for do_shortcode() callers that
	 * embed outside a full WP page (e.g. widget, email preview). In the normal flow,
	 * maybe_render() intercepts first and outputs standalone HTML directly.
	 */
	public function maybe_enqueue_for_shortcode() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		if ( false === strpos( $post->post_content, '[bizcity_twin' ) ) {
			return;
		}
		$this->enqueue_shortcode_assets();

		// Inline CSS to break out of theme content-width constraints
		add_action( 'wp_head', function () {
			echo '<style>
.bizcity-twin-embed{box-sizing:border-box;display:block;overflow:hidden;}
.bizcity-twin-embed #bizcity-twinweb-root{height:100%;width:100%;}
body.page .bizcity-twin-embed{max-width:100%!important;margin-left:0!important;margin-right:0!important;}
</style>';
		} );
	}

	/**
	 * Enqueue JS + CSS assets for shortcode embedding (inside WP theme).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB — JS output via add_action(wp_footer)
	 * with hardcoded type="module" to avoid browser SyntaxError when wp_enqueue_script
	 * omits that attribute. CSS uses normal wp_enqueue_style (no module type needed).
	 */
	private function enqueue_shortcode_assets() {
		// Static guard — prevent double-output if called from both wp hook and render_shortcode fallback.
		static $assets_done = false;
		if ( $assets_done ) {
			return;
		}
		$assets_done = true;

		$manifest = self::read_manifest();

		// CSS via wp_enqueue_style (no module type issue).
		foreach ( $manifest['css'] as $i => $css_url ) {
			wp_enqueue_style(
				self::HANDLE . '-css-' . $i,
				$css_url,
				array(),
				BIZCITY_TWINWEB_VERSION
			);
		}

		// JS: output directly in wp_footer with type="module" to bypass script_loader_tag unreliability.
		if ( $manifest['js'] ) {
			$js_url = esc_url( $manifest['js'] );
			add_action( 'wp_footer', function () use ( $js_url ) {
				echo '<script type="module" src="' . $js_url . '"></script>' . "\n";
			}, 20 );
			// Dummy wp_enqueue_script so other plugins see the handle as "registered" (prevents duplicates).
			wp_register_script( self::HANDLE, $manifest['js'], array(), BIZCITY_TWINWEB_VERSION, true );
			// Note: NOT calling wp_enqueue_script to avoid a second non-module <script> tag.
			// The actual output is handled by the wp_footer action above.
		}
	}

	/**
	 * Shortcode handler: [bizcity_twin height="100vh" class=""]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — shortcode embed in WP page
		$atts = shortcode_atts(
			array(
				'height' => '100vh',
				'class'  => '',
			),
			$atts,
			'bizcity_twin'
		);

		$height     = esc_attr( sanitize_text_field( $atts['height'] ) );
		$extra_cls  = esc_attr( sanitize_html_class( $atts['class'] ) );
		$root_id    = 'bizcity-twinweb-root';

		// Inject twinwebConfig inline (same data as standalone page)
		$nonce       = wp_create_nonce( 'wp_rest' );
		$rest_url    = esc_js( rest_url() );
		$site_name   = esc_js( get_bloginfo( 'name' ) );
		$logo_url    = esc_js( has_custom_logo() ? (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '' );
		$user_id     = (int) get_current_user_id();
		$is_admin_js = current_user_can( 'manage_options' ) ? 'true' : 'false';
		$admin_url   = esc_js( admin_url() );
		$login_url   = esc_js( wp_login_url( (string) get_permalink() ) );
		$logout_url  = esc_js( wp_logout_url( (string) get_permalink() ) );
		$display     = '';
		$avatar      = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? esc_js( $u->display_name ) : '';
			$avatar  = esc_js( get_avatar_url( $user_id, array( 'size' => 40 ) ) );
		}

		// Ensure assets are enqueued (fallback if called without wp hook)
		$this->enqueue_shortcode_assets();

		$config_script = '<script>
if (typeof window.twinwebConfig === "undefined") {
  window.twinwebConfig = {
    restRoot:   "' . $rest_url . '",
    nonce:      "' . $nonce . '",
    siteName:   "' . $site_name . '",
    logoUrl:    "' . $logo_url . '",
    adminUrl:   "' . $admin_url . '",
    loginUrl:   "' . $login_url . '",
    logoutUrl:  "' . $logout_url . '",
    userId:     ' . $user_id . ',
    isAdmin:    ' . $is_admin_js . ',
    displayName:"' . $display . '",
    avatarUrl:  "' . $avatar . '"
  };
}
</script>';

		$cls = 'bizcity-twin-embed' . ( $extra_cls ? ' ' . $extra_cls : '' );

		return $config_script
			. '<div id="' . esc_attr( $root_id ) . '" class="' . esc_attr( $cls ) . '" '
			. 'style="height:' . $height . ';width:100%;position:relative;overflow:hidden;">'
			. '</div>';
	}

	// add_module_type() and strip_foreign_assets() removed — no longer needed.
	// JS is output directly in get_page_html() with hardcoded type="module".
	// Asset stripping not needed because maybe_render() exits before WP theme loads.

	/**
	 * Return the URL of the auto-created WP page (shortcode embed).
	 * Null if page was never created.
	 *
	 * @return string|null
	 */
	public static function get_page_url() {
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — public accessor for /me endpoint
		$page_id = (int) get_option( 'bizcity_twinweb_page_id', 0 );
		if ( $page_id < 1 ) {
			return null;
		}
		$url = get_permalink( $page_id );
		return $url ? (string) $url : null;
	}
}
