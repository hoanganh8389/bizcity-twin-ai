<?php
/**
 * BizCity Personal — Public Page
 *
 * Registers shortcode [bizcity_personal] for embedding the React SPA inside
 * any WP page. Also auto-intercepts pages that contain the shortcode and
 * renders a full-screen standalone HTML shell (no WP theme).
 *
 * URL: configured via WP page slug (e.g. /personal/).
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME Wave 0)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Page' ) ) { return; }

class BizCity_Personal_Page {

	const QUERY_VAR = 'bizcity_personal_page';
	const DIST_DIR  = BIZCITY_PERSONAL_DIR . 'ui/dist/';
	const DIST_URL  = BIZCITY_PERSONAL_URL . 'ui/dist/';
	const HANDLE    = 'bizcity-personal-app';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// [2026-06-24 Johnny Chu] PHASE-HOME — full-page takeover via template_redirect.
		// Detect: any singular page whose post_content contains [bizcity_personal shortcode.
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Shortcode [bizcity_personal] for embedding in WP pages.
		add_shortcode( 'bizcity_personal', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Intercept template loading for any WP page that contains [bizcity_personal shortcode.
	 * Outputs a standalone HTML shell (no WP theme).
	 */
	public function maybe_render() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		if ( false === strpos( $post->post_content, '[bizcity_personal' ) ) {
			return;
		}

		add_filter( 'show_admin_bar', '__return_false' );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		$page_url = (string) get_permalink( $post->ID );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::get_page_html( $page_url );
		exit;
	}

	/**
	 * Shortcode handler — renders a <div> mount point + inline SPA assets.
	 * Used when the page is rendered inside the WP theme (non-full-screen mode).
	 *
	 * @param array $atts Shortcode attributes: height (default 100vh).
	 */
	public function render_shortcode( $atts ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME — shortcode mode (embedded, not standalone)
		$atts = shortcode_atts( array( 'height' => '100vh' ), $atts, 'bizcity_personal' );
		$h    = esc_attr( $atts['height'] );
		ob_start();
		self::enqueue_inline_config();
		self::enqueue_assets();
		echo '<div id="bizcity-personal-root" style="height:' . $h . ';min-height:400px"></div>';
		return ob_get_clean();
	}

	/**
	 * Full standalone HTML shell (no WP theme).
	 *
	 * @param string $page_url Redirect-back URL for login/logout.
	 * @return string
	 */
	private static function get_page_html( $page_url = '' ) {
		if ( '' === $page_url ) {
			$page_url = home_url( '/' );
		}
		$nonce      = wp_create_nonce( 'wp_rest' );
		$rest_url   = esc_js( rest_url() );
		$site_name  = esc_js( get_bloginfo( 'name' ) );
		$logo_url   = esc_js( has_custom_logo() ? (string) wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' ) : '' );
		$user_id    = (int) get_current_user_id();
		$is_admin   = current_user_can( 'manage_options' ) ? 'true' : 'false';
		$admin_url  = esc_js( admin_url() );
		$login_url  = esc_js( wp_login_url( $page_url ) );
		$logout_url = esc_js( wp_logout_url( $page_url ) );

		// [2026-06-24 Johnny Chu] PHASE-HOME — SSO URLs (same logic as twinweb)
		if ( class_exists( 'WPOSSO_Client' ) ) {
			$sso_google_url = esc_js( add_query_arg( array( 'auth' => 'sso', 'redirect_to' => rawurlencode( $page_url ) ), home_url( '/' ) ) );
		} else {
			$sso_google_url = esc_js( add_query_arg( 'auto_google_login', '1', wp_login_url( $page_url ) ) );
		}

		$display = '';
		$avatar  = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? esc_js( $u->display_name ) : '';
			$avatar  = esc_js( get_avatar_url( $user_id, array( 'size' => 40 ) ) );
		}

		$manifest  = self::read_manifest();
		$entry_js  = $manifest['js']  ?? '';
		$entry_css = $manifest['css'] ?? array();

		$css_tags = '';
		foreach ( $entry_css as $css_url ) {
			$css_tags .= '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' . "\n";
		}

		$js_tag = $entry_js
			? '<script type="module" src="' . esc_url( $entry_js ) . '"></script>'
			: '<!-- bizcity-personal: no JS build found — run: cd plugins/bizcity-personal/ui && npm install && npm run build -->';

		return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$site_name} — Trợ lý cá nhân</title>
{$css_tags}
<script>
window.personalConfig = {
  restRoot:      "{$rest_url}",
  nonce:         "{$nonce}",
  siteName:      "{$site_name}",
  logoUrl:       "{$logo_url}",
  adminUrl:      "{$admin_url}",
  loginUrl:      "{$login_url}",
  logoutUrl:     "{$logout_url}",
  ssoGoogleUrl:  "{$sso_google_url}",
  userId:        {$user_id},
  isAdmin:       {$is_admin},
  displayName:   "{$display}",
  avatarUrl:     "{$avatar}"
};
</script>
</head>
<body>
<div id="bizcity-personal-root"></div>
{$js_tag}
</body>
</html>
HTML;
	}

	/**
	 * Enqueue inline window.personalConfig script (shortcode mode).
	 */
	private static function enqueue_inline_config() {
		$nonce     = wp_create_nonce( 'wp_rest' );
		$user_id   = (int) get_current_user_id();
		$is_admin  = current_user_can( 'manage_options' ) ? 'true' : 'false';
		$display   = '';
		$avatar    = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? esc_js( $u->display_name ) : '';
			$avatar  = esc_js( get_avatar_url( $user_id, array( 'size' => 40 ) ) );
		}
		wp_add_inline_script(
			self::HANDLE,
			'window.personalConfig={restRoot:"' . esc_js( rest_url() ) . '",nonce:"' . esc_js( $nonce ) . '",siteName:"' . esc_js( get_bloginfo( 'name' ) ) . '",logoUrl:"",adminUrl:"' . esc_js( admin_url() ) . '",loginUrl:"' . esc_js( wp_login_url() ) . '",logoutUrl:"' . esc_js( wp_logout_url() ) . '",ssoGoogleUrl:"",userId:' . $user_id . ',isAdmin:' . $is_admin . ',displayName:"' . $display . '",avatarUrl:"' . $avatar . '"};',
			'before'
		);
	}

	/**
	 * Enqueue compiled SPA assets via WP (shortcode mode).
	 */
	private static function enqueue_assets() {
		$manifest = self::read_manifest();
		foreach ( $manifest['css'] ?? array() as $css_url ) {
			wp_enqueue_style( self::HANDLE . '-css', $css_url, array(), BIZCITY_PERSONAL_VERSION );
		}
		if ( ! empty( $manifest['js'] ) ) {
			wp_enqueue_script( self::HANDLE, $manifest['js'], array(), BIZCITY_PERSONAL_VERSION, true );
			wp_script_add_data( self::HANDLE, 'type', 'module' );
		}
	}

	/**
	 * Parse Vite manifest and return { js: url, css: url[] }.
	 *
	 * @return array
	 */
	private static function read_manifest() {
		$paths = array(
			self::DIST_DIR . '.vite/manifest.json',
			self::DIST_DIR . 'manifest.json',
		);
		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $raw ) {
				continue;
			}
			$data = json_decode( $raw, true );
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
}
