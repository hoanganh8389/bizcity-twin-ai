<?php
/**
 * Bizcity Twin AI — TwinKG Public Page
 *
 * Serves the Knowledge Graph React workspace at the pretty URL `/twinkg/`.
 *
 * URL:   https://example.com/twinkg/
 * Query: index.php?bizcity_twinkg_page=1
 *
 * Access: LOGIN REQUIRED. Unlike `/twinchat/` this is a configuration surface
 * (notebooks, graph, ACL, Guru promotion), never a guest door. Every data call
 * is a separate REST request that re-checks capability per route — the page
 * gate only keeps anonymous visitors off the bundle.
 *
 * Ownership (WP-09 §3 / proposed rule R-KG-UI): VIEW only. No table, option,
 * cron or REST route is owned here; the app talks to `bizcity-knowledge/v2`.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinKG
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinKG_Public_Page {

	const QUERY_VAR   = 'bizcity_twinkg_page';
	const REWRITE_KEY = '^twinkg(?:/.*)?$';
	const OPTION_KEY  = 'bizcity_twinkg_rewrite_flushed_v1';

	/** Root element the React bundle mounts into (ui/src/main.tsx). */
	const ROOT_ID = 'bizcity-kg-hub-root';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'init',              array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars',        array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Register the rewrite rule. The actual flush is a one-time admin_init guard
	 * in bootstrap.php (R-PERF: never flush on a front-end request).
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Intercept the request and output the standalone app page.
	 */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Configuration surface — anonymous visitors go to the login form and
		// come back here afterwards.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		// Standalone visits join the unified Twin Shell so the operator keeps the
		// ActivityBar, unless this IS the shell iframe (`?bizcity_iframe=1`) or the
		// caller opted out (`?shell=0`) — same convention as /twinchat/.
		$is_embed = ! empty( $_GET['bizcity_iframe'] );
		$opt_out  = isset( $_GET['shell'] ) && '0' === (string) $_GET['shell'];
		if ( ! $is_embed && ! $opt_out ) {
			$shell = add_query_arg( 'plugin', 'twinkg', home_url( '/twin/' ) );
			if ( ! empty( $_GET['view'] ) ) {
				$shell = add_query_arg( 'view', sanitize_key( wp_unslash( $_GET['view'] ) ), $shell );
			}
			if ( ! empty( $_GET['notebook_id'] ) ) {
				$shell = add_query_arg( 'notebook_id', (int) $_GET['notebook_id'], $shell );
			}
			wp_safe_redirect( $shell, 302 );
			exit;
		}

		$this->render_full_page();
		exit;
	}

	/**
	 * Output a minimal HTML5 document — no theme, no WP head cruft.
	 *
	 * The manifest is read directly (rather than going through wp_enqueue_*) so
	 * asset order cannot be disturbed by a theme or another plugin on this page.
	 */
	private function render_full_page() {
		// The HTML embeds hashed Vite filenames; never let a CDN or browser cache
		// an old document after `npm run build` rotated the assets.
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );

		$assets = BizCity_TwinKG_Bootstrap_Data::assets();
		$ver    = BizCity_TwinKG_Bootstrap_Data::build_version();
		$config = (string) wp_json_encode(
			BizCity_TwinKG_Bootstrap_Data::build( array( 'surface' => 'public' ) )
		);

		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$site_name = esc_html( get_bloginfo( 'name' ) );

		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
		echo '<title>Knowledge Graph — ' . $site_name . '</title>' . "\n";

		foreach ( $assets['chunk_js'] as $chunk ) {
			echo '<link rel="modulepreload" crossorigin href="' . esc_url( $chunk ) . '?ver=' . esc_attr( $ver ) . '">' . "\n";
		}
		foreach ( $assets['css'] as $css_url ) {
			echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?ver=' . esc_attr( $ver ) . '">' . "\n";
		}

		echo '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#fff;}#'
			. esc_attr( self::ROOT_ID ) . '{height:100vh;width:100%;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="' . esc_attr( self::ROOT_ID ) . '"></div>' . "\n";

		// Plain (non-module) script so it runs synchronously BEFORE the ES module.
		// data-cfasync="false" keeps Cloudflare Rocket Loader from deferring it.
		echo '<script data-cfasync="false">window.BIZCITY_KG_HUB = ' . $config . ';</script>' . "\n";

		if ( '' !== $assets['entry_js'] ) {
			echo '<script type="module" src="' . esc_url( $assets['entry_js'] ) . '?ver=' . esc_attr( $ver ) . '"></script>' . "\n";
		} else {
			echo $this->unbuilt_notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_html() below.
		}

		// Twin Shell bridge (deep-link sync). render_full_page() exits before
		// wp_enqueue_scripts fires, so the bridge the shell normally injects has
		// to be added here when this page IS the shell iframe.
		if ( ! empty( $_GET['bizcity_iframe'] ) && defined( 'BIZCITY_TWIN_SHELL_URL' ) ) {
			$bridge_cfg = (string) wp_json_encode( array(
				'pluginId' => 'twinkg',
				'shellUrl' => esc_url_raw( home_url( '/twin/' ) ),
			) );
			$bridge_ver = defined( 'BIZCITY_TWIN_SHELL_VERSION' ) ? BIZCITY_TWIN_SHELL_VERSION : '0.11.0';
			$bridge_js  = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell-bridge.js?ver=' . rawurlencode( $bridge_ver );
			echo '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL_BRIDGE=' . $bridge_cfg . ';</script>' . "\n";
			echo '<script data-cfasync="false" src="' . esc_url( $bridge_js ) . '"></script>' . "\n";
		}

		echo '</body></html>' . "\n";
	}

	/**
	 * Bounded, actionable message when `ui/dist/` is missing (R-ERROR-UX: a
	 * degraded surface explains itself instead of rendering blank).
	 *
	 * @return string
	 */
	private function unbuilt_notice(): string {
		$dir  = defined( 'BIZCITY_TWINKG_UI_DIR' ) ? BIZCITY_TWINKG_UI_DIR : '';
		$rest = esc_html( rest_url( 'bizcity-knowledge/v2' ) );
		return '<div style="padding:40px;font-family:system-ui;max-width:760px;">'
			. '<h1 style="font-size:22px;margin-bottom:8px;">Knowledge Graph</h1>'
			. '<p style="color:#666;">UI chưa được build. Chạy lệnh sau trong terminal:</p>'
			. '<pre style="background:#f4f4f5;padding:14px;border-radius:8px;font-size:13px;overflow:auto;">cd '
			. esc_html( $dir ) . "\nnpm install\nnpm run build</pre>"
			. '<p style="color:#666;margin-top:16px;">REST endpoints đã sẵn sàng tại: <code>' . $rest . '/</code></p>'
			. '</div>';
	}

	/**
	 * Reset the flush sentinel so the next admin_init rewrites the rules.
	 * Called from plugin activation/deactivation.
	 */
	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}
}
