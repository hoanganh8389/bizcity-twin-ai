<?php
/**
 * Twin Shell — Public page /twin/.
 *
 * Renders the iframe-shell wrapper. Mirrors the conventions used by
 * BizCity_TwinChat_Public_Page (rewrite + theme isolation + minimal HTML5
 * shell), but keeps the front-end as plain JS — no Vite build needed for v1.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Page {

	const QUERY_VAR   = 'bizcity_twin_shell';
	const REWRITE_KEY = '^twin/?$';
	const OPTION_KEY  = 'bizcity_twin_shell_rewrite_flushed_v2';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		// Disable Query Monitor on /twin/.
		add_filter( 'qm/dispatch/html',  [ $this, 'disable_qm' ] );
		add_filter( 'qm/process',        [ $this, 'disable_qm' ] );
	}

	public function add_rewrite_rule() {
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);

		if ( ! get_option( self::OPTION_KEY ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_KEY, 1 );
		}
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			$redirect = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		$this->render();
		exit;
	}

	public function disable_qm( $val ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $val;
	}

	private function render() {
		$registry = BizCity_Twin_Shell_Registry::instance();
		$plugins  = $registry->all();

		// Filter to only plugins the current user is allowed to see.
		$visible = [];
		foreach ( $plugins as $p ) {
			if ( ! empty( $p['capability'] ) && ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$visible[] = $p;
		}

		// Resolve initial plugin from ?plugin=, falling back to default.
		$req_plugin = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
		$initial    = '';
		foreach ( $visible as $p ) {
			if ( $p['id'] === $req_plugin ) {
				$initial = $p['id'];
				break;
			}
		}
		if ( '' === $initial ) {
			$initial = $registry->default_id();
			// Make sure default is in visible list.
			$ok = false;
			foreach ( $visible as $p ) {
				if ( $p['id'] === $initial ) { $ok = true; break; }
			}
			if ( ! $ok && ! empty( $visible ) ) {
				$initial = $visible[0]['id'];
			}
		}

		$initial_url = $initial ? $registry->build_iframe_url( $initial, $_GET ) : '';

		$config = (string) wp_json_encode( [
			'restRoot'      => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'plugins'       => $visible,
			'defaultPlugin' => $initial,
			'initialUrl'    => $initial_url,
			'shellUrl'      => esc_url_raw( home_url( '/twin/' ) ),
			'pluginUrl'     => BIZCITY_TWIN_SHELL_URL,
		] );

		$ver       = BIZCITY_TWIN_SHELL_VERSION;
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );

		$js_file   = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.js';
		$css_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/twin-shell.css';
		$js_ver    = file_exists( $js_file )  ? filemtime( $js_file )  : $ver;
		$css_ver   = file_exists( $css_file ) ? filemtime( $css_file ) : $ver;

		$shell_js  = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.js?ver='  . $js_ver;
		$shell_css = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell.css?ver=' . $css_ver;

		// Phase 0.13 — primitives bundle (picker, source upload, monitor stub).
		$prim_js_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.js';
		$prim_css_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-primitives.css';
		$upload_js_file = BIZCITY_TWIN_SHELL_DIR . 'assets/primitives/twin-source-upload.js';
		$prim_js  = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.js?ver='  . ( file_exists( $prim_js_file )  ? filemtime( $prim_js_file )  : $ver );
		$prim_css = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-primitives.css?ver=' . ( file_exists( $prim_css_file ) ? filemtime( $prim_css_file ) : $ver );
		$upload_js = BIZCITY_TWIN_SHELL_URL . 'assets/primitives/twin-source-upload.js?ver=' . ( file_exists( $upload_js_file ) ? filemtime( $upload_js_file ) : $ver );
		$prim_cfg = (string) wp_json_encode( [
			'restRoot' => esc_url_raw( rest_url( BizCity_Twin_Shell_Primitives::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'plugin'   => 'twinshell',
		] );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>Twin — ' . $site_name . '</title>' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $shell_css ) . '">' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $prim_css ) . '">' . "\n";
		echo '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#0f1115;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="twin-shell"></div>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_SHELL = ' . $config . ';</script>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_TWIN_PRIMITIVES_CFG = ' . $prim_cfg . ';</script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $prim_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $upload_js ) . '"></script>' . "\n";
		echo '<script data-cfasync="false" src="' . esc_url( $shell_js ) . '"></script>' . "\n";
		echo '</body></html>' . "\n";
	}

	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}
}
