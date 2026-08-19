<?php
/**
 * TwinWeb — Public Page (Shortcode Mode)
 *
 * Registers shortcode [bizcity_twin] for embedding the React SPA inside any
 * WP page. No standalone /twin/ URL — that slug belongs to TwinShell (Phase 0.11).
 *
 * Twinweb is surfaced exclusively via:
 *   1. Shortcode [bizcity_twin height="100vh"] on any WP page.
 *   2. Auto-created WP page at /gpt/ (created by class-twinweb-installer.php).
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
	const SKIN_DEFAULT = 'chatgpt';
	const SURFACE_FULL = 'full';
	const SURFACE_BLOCK = 'block';
	const SURFACE_FLOAT = 'float';

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
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — direct-path fallback for /gpt/.
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-4 — also serve /gpt/myaccount/ from the same SPA shell.
		// Guarantees public entry URL works even if page option/slug is stale.
		if ( self::is_direct_gpt_request() ) {
			add_filter( 'show_admin_bar', '__return_false' );
			status_header( 200 );
			header( 'Content-Type: text/html; charset=UTF-8' );
			echo self::get_page_html( self::current_request_url() );
			exit;
		}

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

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — block/float shortcodes must render inside the theme, not full-page takeover.
		if ( ! self::content_requests_full_surface( (string) $post->post_content ) ) {
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
		$rest_url    = (string) rest_url();
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — prefer blog name from option for UI labels.
		$site_name_raw = (string) get_option( 'blogname', '' );
		if ( '' === $site_name_raw ) {
			$site_name_raw = (string) get_bloginfo( 'name' );
		}
		$site_name   = esc_html( $site_name_raw );
		$logo_url    = has_custom_logo() ? (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '';
		$user_id     = (int) get_current_user_id();
		$is_admin    = current_user_can( 'manage_options' );
		$admin_url   = (string) admin_url();
		$login_url   = (string) wp_login_url( $page_url );
		$logout_url  = (string) wp_logout_url( $page_url );
		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — SSO URLs for AuthModal (Google + BizCity ID)
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — hub site uses login-with-google (wp-login.php?auto_google_login=1)
		//   Client sites use bizgpt-oauth-client-new (?auth=sso).
		//   Detect by checking if WPOSSO_Client class is loaded (blocked on hub blog_id 1258).
		if ( class_exists( 'WPOSSO_Client' ) ) {
			// Client site: OAuth SSO via bizcity.vn
			$sso_google_url  = (string) add_query_arg( array( 'auth' => 'sso', 'redirect_to' => rawurlencode( $page_url ) ), home_url( '/' ) );
			$sso_bizcity_url = (string) add_query_arg( array( 'auth' => 'sso', 'provider' => 'bizcity', 'redirect_to' => rawurlencode( $page_url ) ), home_url( '/' ) );
		} else {
			// Hub site (bizcity.vn): login-with-google plugin handles Google OAuth
			$sso_google_url  = (string) add_query_arg( 'auto_google_login', '1', wp_login_url( $page_url ) );
			// BizCity ID on hub = standard WP login (user IS on the provider)
			$sso_bizcity_url = (string) wp_login_url( $page_url );
		}
		$display     = '';
		$avatar      = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? (string) $u->display_name : '';
			$avatar  = (string) get_avatar_url( $user_id, array( 'size' => 40 ) );
		}
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — allow /gpt/?skin=claude style preview; FE still validates against effective allowed skins.
		$query_skin = isset( $_GET['skin'] )
			? self::normalize_skin( sanitize_text_field( wp_unslash( (string) $_GET['skin'] ) ) )
			: '';
		// [2026-07-29 Johnny Chu] HOTFIX — JSON-encode runtime config so quoted site metadata cannot create invalid inline JavaScript.
		$twinweb_config_json = wp_json_encode(
			array(
				'restRoot'     => $rest_url,
				'nonce'        => $nonce,
				'siteName'     => $site_name_raw,
				'logoUrl'      => $logo_url,
				'adminUrl'     => $admin_url,
				'loginUrl'     => $login_url,
				'logoutUrl'    => $logout_url,
				'ssoGoogleUrl' => $sso_google_url,
				'ssoBizcityUrl'=> $sso_bizcity_url,
				'userId'       => $user_id,
				'isAdmin'      => $is_admin,
				'displayName'  => $display,
				'avatarUrl'    => $avatar,
				'surface'      => 'full',
				'skin'         => $query_skin,
				'mountId'      => 'bizcity-twinweb-root',
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $twinweb_config_json ) {
			$twinweb_config_json = '{}';
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

		// [2026-07-29 Johnny Chu] HOTFIX — scope Twin GPT utilities so shortcode CSS cannot affect the host theme.
		return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — canonical user-facing product brand. -->
<title>{$site_name} — Twin GPT</title>
{$css_tags}
<script>
window.twinwebConfig =
{$twinweb_config_json};
</script>
</head>
<body>
<div id="bizcity-twinweb-root" class="bizcity-twin-embed bizcity-twin-embed-full" data-tw-surface="full" data-tw-skin="{$query_skin}"></div>
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
					// [2026-08-01 Johnny Chu] HOTFIX — bust page/CDN cache when the deployed Vite manifest changes.
					$asset_version = (int) @filemtime( $path );
					$asset_query   = $asset_version > 0 ? array( 'ver' => $asset_version ) : array();
					$js            = add_query_arg( $asset_query, self::DIST_URL . $entry['file'] );
					if ( isset( $entry['css'] ) && is_array( $entry['css'] ) ) {
						foreach ( $entry['css'] as $css_file ) {
							$css[] = add_query_arg( $asset_query, self::DIST_URL . $css_file );
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
.bizcity-twin-embed[data-tw-surface="block"]{margin-left:auto;margin-right:auto;}
.bizcity-twin-embed[data-tw-surface="float"]{position:fixed;z-index:99998;width:min(420px,calc(100vw - 32px));height:min(720px,80vh);right:24px;bottom:24px;border-radius:18px;box-shadow:0 20px 60px rgba(15,23,42,.22);}
.bizcity-twin-embed[data-tw-surface="float"][data-tw-position="bottom-left"]{left:24px;right:auto;}
.bizcity-twin-embed[data-tw-surface="float"][data-tw-state="closed"]{width:156px;height:56px;border-radius:999px;box-shadow:none;}
.bizcity-twin-embed .bizcity-twinweb-root{height:100%;width:100%;}
body.page .bizcity-twin-embed[data-tw-surface="full"]{max-width:100%!important;margin-left:0!important;margin-right:0!important;}
@media (max-width: 640px){.bizcity-twin-embed[data-tw-surface="float"]{inset:auto 0 0 0;width:100vw;height:100dvh;border-radius:0;}.bizcity-twin-embed[data-tw-surface="float"][data-tw-state="closed"]{left:auto;right:16px;bottom:16px;width:148px;height:52px;border-radius:999px;}}
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
				'height'       => '100vh',
				'class'        => '',
				'surface'      => self::SURFACE_FULL,
				'skin'         => '',
				'max_width'    => '',
				'align'        => 'stretch',
				'mode'         => '',
				'show_prompts' => '',
				'position'     => 'bottom-right',
			),
			$atts,
			'bizcity_twin'
		);

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — normalize shortcode surface and skin attrs for block/float embeds.
		$surface    = self::normalize_surface( (string) $atts['surface'] );
		$skin       = self::normalize_skin( (string) $atts['skin'] );
		$height     = esc_attr( sanitize_text_field( $atts['height'] ) );
		$max_width  = esc_attr( sanitize_text_field( $atts['max_width'] ) );
		$align      = self::normalize_align( (string) $atts['align'] );
		$mode       = sanitize_key( (string) $atts['mode'] );
		$position   = self::normalize_position( (string) $atts['position'] );
		$show_prompts = self::truthy_attr( $atts['show_prompts'] ) ? 'true' : 'false';
		$extra_cls  = esc_attr( self::sanitize_class_list( (string) $atts['class'] ) );
		$root_id    = 'bizcity-twinweb-root-' . substr( md5( wp_json_encode( $atts ) . microtime( true ) ), 0, 10 );

		// Inject twinwebConfig inline (same data as standalone page)
		$nonce       = wp_create_nonce( 'wp_rest' );
		$rest_url    = (string) rest_url();
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — prefer blog name from option for UI labels.
		$site_name_raw = (string) get_option( 'blogname', '' );
		if ( '' === $site_name_raw ) {
			$site_name_raw = (string) get_bloginfo( 'name' );
		}
		$site_name   = $site_name_raw;
		$logo_url    = has_custom_logo() ? (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '';
		$user_id     = (int) get_current_user_id();
		$is_admin_js = current_user_can( 'manage_options' );
		$admin_url   = (string) admin_url();
		$login_url   = (string) wp_login_url( (string) get_permalink() );
		$logout_url  = (string) wp_logout_url( (string) get_permalink() );
		$display     = '';
		$avatar      = '';
		if ( $user_id > 0 ) {
			$u       = get_userdata( $user_id );
			$display = $u ? (string) $u->display_name : '';
			$avatar  = (string) get_avatar_url( $user_id, array( 'size' => 40 ) );
		}
		// [2026-07-29 Johnny Chu] HOTFIX — JSON-encode shortcode config so quoted metadata cannot create invalid inline JavaScript.
		$config_json = wp_json_encode(
			array(
				'restRoot'    => $rest_url,
				'nonce'       => $nonce,
				'siteName'    => $site_name,
				'logoUrl'     => $logo_url,
				'adminUrl'    => $admin_url,
				'loginUrl'    => $login_url,
				'logoutUrl'   => $logout_url,
				'userId'      => $user_id,
				'isAdmin'     => $is_admin_js,
				'displayName' => $display,
				'avatarUrl'   => $avatar,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $config_json ) {
			$config_json = '{}';
		}
		$mount_json = wp_json_encode(
			array(
				'surface'    => $surface,
				'skin'       => $skin,
				'mountId'    => $root_id,
				'mode'       => $mode,
				'showPrompts'=> ( 'true' === $show_prompts ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $mount_json ) {
			$mount_json = '{}';
		}
		$root_id_json = wp_json_encode( $root_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		// Ensure assets are enqueued (fallback if called without wp hook)
		$this->enqueue_shortcode_assets();

		$config_script = '<script>
if (typeof window.twinwebConfig === "undefined") {
  window.twinwebConfig = ' . $config_json . ';
}
window.twinwebMounts = window.twinwebMounts || {};
window.twinwebMounts[' . $root_id_json . '] = Object.assign({}, window.twinwebConfig, ' . $mount_json . ');
</script>';

		$cls = 'bizcity-twin-embed bizcity-twin-embed-' . $surface . ( $extra_cls ? ' ' . $extra_cls : '' );
		$style = self::build_embed_style( $surface, $height, $max_width, $align );
		// [2026-07-18 Johnny Chu] SPRINT-21 UIS-5 — float shortcode starts collapsed; React restores persisted open state.
		$state = self::SURFACE_FLOAT === $surface ? 'closed' : 'open';

		return $config_script
			. '<div id="' . esc_attr( $root_id ) . '" class="' . esc_attr( $cls ) . '" '
			. 'data-tw-surface="' . esc_attr( $surface ) . '" '
			. 'data-tw-skin="' . esc_attr( $skin ) . '" '
			. 'data-tw-position="' . esc_attr( $position ) . '" '
			. 'data-tw-state="' . esc_attr( $state ) . '" '
			. 'style="' . esc_attr( $style ) . '">'
			. '</div>';
	}

	/**
	 * Detect whether page content asks for the full-page Twin GPT surface.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function content_requests_full_surface( $content ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — only full/default shortcode gets standalone page takeover.
		if ( ! preg_match_all( '/\[bizcity_twin([^\]]*)\]/i', (string) $content, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $raw_atts ) {
			$atts    = shortcode_parse_atts( (string) $raw_atts );
			$surface = isset( $atts['surface'] ) ? self::normalize_surface( (string) $atts['surface'] ) : self::SURFACE_FULL;
			if ( self::SURFACE_FULL === $surface ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $surface Raw surface.
	 * @return string
	 */
	private static function normalize_surface( $surface ) {
		$surface = sanitize_key( (string) $surface );
		$allowed = array( self::SURFACE_FULL, self::SURFACE_BLOCK, self::SURFACE_FLOAT );
		return in_array( $surface, $allowed, true ) ? $surface : self::SURFACE_FULL;
	}

	/**
	 * @param string $skin Raw skin.
	 * @return string
	 */
	private static function normalize_skin( $skin ) {
		$skin = sanitize_key( (string) $skin );
		$allowed = array( 'chatgpt', 'claude', 'perplexity', 'gemini', 'grok' );
		return in_array( $skin, $allowed, true ) ? $skin : '';
	}

	/**
	 * @param string $align Raw align.
	 * @return string
	 */
	private static function normalize_align( $align ) {
		$align = sanitize_key( (string) $align );
		$allowed = array( 'left', 'center', 'right', 'stretch' );
		return in_array( $align, $allowed, true ) ? $align : 'stretch';
	}

	/**
	 * @param string $position Raw position.
	 * @return string
	 */
	private static function normalize_position( $position ) {
		$position = sanitize_key( (string) $position );
		$allowed = array( 'bottom-right', 'bottom-left' );
		return in_array( $position, $allowed, true ) ? $position : 'bottom-right';
	}

	/**
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function truthy_attr( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * @param string $class_list Raw class list.
	 * @return string
	 */
	private static function sanitize_class_list( $class_list ) {
		$parts = preg_split( '/\s+/', trim( (string) $class_list ) );
		$out = array();
		foreach ( $parts as $part ) {
			$clean = sanitize_html_class( $part );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * @param string $surface Surface type.
	 * @param string $height CSS height.
	 * @param string $max_width CSS max-width.
	 * @param string $align Alignment.
	 * @return string
	 */
	private static function build_embed_style( $surface, $height, $max_width, $align ) {
		$style = 'height:' . $height . ';width:100%;position:relative;overflow:hidden;';
		if ( self::SURFACE_BLOCK === $surface ) {
			if ( '' !== $max_width ) {
				$style .= 'max-width:' . $max_width . ';';
			}
			if ( 'center' === $align ) {
				$style .= 'margin-left:auto;margin-right:auto;';
			} elseif ( 'right' === $align ) {
				$style .= 'margin-left:auto;margin-right:0;';
			} elseif ( 'left' === $align ) {
				$style .= 'margin-left:0;margin-right:auto;';
			}
		} elseif ( self::SURFACE_FLOAT === $surface ) {
			$style .= 'height:min(720px,80vh);';
		}
		return $style;
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

	/**
	 * Detect direct request to canonical TwinWeb URLs /gpt/, /gpt/{uuid}/ and /gpt/myaccount/.
	 *
	 * @return bool
	 */
	private static function is_direct_gpt_request() {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — accept shareable thread UUID subpaths without adding a rewrite rule.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $uri === '' ) {
			return false;
		}

		$request_path = (string) parse_url( $uri, PHP_URL_PATH );
		$target_path  = (string) parse_url( home_url( '/gpt/' ), PHP_URL_PATH );
		$account_path = (string) parse_url( home_url( '/gpt/myaccount/' ), PHP_URL_PATH );
		if ( $request_path === '' || $target_path === '' ) {
			return false;
		}

		$normalized = self::normalize_path( $request_path );
		$root_path = self::normalize_path( $target_path );
		if ( $normalized === $root_path || ( $account_path !== '' && $normalized === self::normalize_path( $account_path ) ) ) {
			return true;
		}

		$root_prefix = rtrim( $root_path, '/' ) . '/';
		if ( 0 !== strpos( $normalized, $root_prefix ) ) {
			return false;
		}

		$tail = trim( substr( $normalized, strlen( $root_prefix ) ), '/' );
		// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — allow async iframe shell routes /gpt/{app}/ without stealing /gpt/{uuid}/ thread links.
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — include My Channels/My Workflows so routes survive F5 and OAuth redirect-backs.
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — expose /gpt/myplan/ while keeping /gpt/mycontent/ as legacy alias.
		if ( in_array( $tail, array( 'twinchat', 'astro', 'creator', 'doc', 'image', 'profile', 'mychannels', 'myworkflows' , 'mycontent', 'myplan', 'mymcp' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $tail );
	}

	/**
	 * Current request URL for login/logout redirect-back values.
	 *
	 * @return string
	 */
	private static function current_request_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/gpt/';
		return home_url( $uri );
	}

	/**
	 * Normalize URL path to compare safely.
	 *
	 * @param string $path Input path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$trimmed = trim( (string) $path );
		if ( $trimmed === '' || $trimmed === '/' ) {
			return '/';
		}
		return '/' . trim( $trimmed, '/' );
	}
}
