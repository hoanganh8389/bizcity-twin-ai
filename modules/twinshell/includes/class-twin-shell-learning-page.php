<?php
/**
 * Twin Shell — Public page /learning-hub/ (Wave E).
 *
 * Renders the React Learning Hub bundle in a minimal HTML5 shell. Mirrors the
 * conventions used by class-twin-shell-page.php (rewrite + theme isolation +
 * inline style reset + script enqueue), specialised for one mount point.
 *
 * Capability gate: `bizcity_view_kg_learning` OR `manage_options`. The cap is
 * declared via `map_meta_cap` so it can be granted to additional roles by
 * filtering `bizcity_kg_learning_view_cap`. We never gate the page at
 * `read` — the data is per-user but exposes site-scope analytics when the
 * user is an admin.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell\Learning
 * @since 0.13.38
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Learning_Page {

	const QUERY_VAR    = 'bizcity_learning_hub';
	const REWRITE_KEY  = '^learning-hub/?$';
	const OPTION_KEY   = 'bizcity_learning_hub_rewrite_flushed_v1';
	const VIEW_CAP     = 'bizcity_view_kg_learning';

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
		add_filter( 'qm/dispatch/html',  [ $this, 'disable_qm' ] );
		add_filter( 'qm/process',        [ $this, 'disable_qm' ] );

		// Map the custom cap to manage_options unless an integrator overrides.
		add_filter( 'map_meta_cap', [ $this, 'map_view_cap' ], 10, 4 );
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

	/**
	 * Map our virtual cap.
	 *
	 * Default: anyone who can `manage_options` also has `bizcity_view_kg_learning`.
	 * Override via `add_filter( 'bizcity_kg_learning_view_cap', fn() => 'edit_posts' );`.
	 */
	public function map_view_cap( $caps, $cap, $user_id, $args ) {
		unset( $user_id, $args );
		if ( self::VIEW_CAP !== $cap ) {
			return $caps;
		}
		$primitive = (string) apply_filters( 'bizcity_kg_learning_view_cap', 'manage_options' );
		if ( '' === $primitive ) {
			$primitive = 'manage_options';
		}
		// Idiomatic WP: map the meta-cap to a primitive cap and let core
		// resolve user_can() against it. This automatically honours role/cap
		// edits and integrates with multisite super-admin handling.
		return [ $primitive ];
	}

	public function disable_qm( $val ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $val;
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

		if ( ! current_user_can( self::VIEW_CAP ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die(
				esc_html__( 'You do not have permission to view the Learning Hub.', 'bizcity-twin-ai' ),
				esc_html__( 'Forbidden', 'bizcity-twin-ai' ),
				[ 'response' => 403 ]
			);
		}

		$this->render();
		exit;
	}

	private function render() {
		$ver       = BIZCITY_TWIN_SHELL_VERSION;
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );

		$js_file  = BIZCITY_TWIN_SHELL_DIR . 'assets/learning-hub/index.js';
		$css_file = BIZCITY_TWIN_SHELL_DIR . 'assets/learning-hub/index.css';

		$js_url   = BIZCITY_TWIN_SHELL_URL . 'assets/learning-hub/index.js?ver='
		            . ( file_exists( $js_file ) ? filemtime( $js_file ) : $ver );
		$css_url  = BIZCITY_TWIN_SHELL_URL . 'assets/learning-hub/index.css?ver='
		            . ( file_exists( $css_file ) ? filemtime( $css_file ) : $ver );

		$bundle_present = file_exists( $js_file );

		$config = (string) wp_json_encode( [
			'restRoot'         => esc_url_raw( rest_url( 'bizcity-twinchat/v1/' ) ),
			'twinShellRest'    => esc_url_raw( rest_url( BizCity_Twin_Shell_Learning_REST::NS . '/' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'userId'           => get_current_user_id(),
			'canManageSite'    => current_user_can( 'manage_options' ),
			'canManageCleanup' => current_user_can( 'manage_options' ),
		] );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html>' . "\n";
		echo '<html lang="' . $lang . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>Learning Hub — ' . $site_name . '</title>' . "\n";
		if ( $bundle_present ) {
			echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' . "\n";
		}
		echo '<style>html,body{margin:0;padding:0;min-height:100%;background:#0f1115;color:#e6e6e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>' . "\n";
		echo '</head>' . "\n";
		echo '<body>' . "\n";
		echo '<div id="bizcity-learning-hub-root"></div>' . "\n";
		echo '<script data-cfasync="false">window.BIZCITY_LEARNING_HUB = ' . $config . ';</script>' . "\n";
		if ( $bundle_present ) {
			echo '<script data-cfasync="false" type="module" src="' . esc_url( $js_url ) . '"></script>' . "\n";
		} else {
			echo '<noscript style="display:block;padding:24px;color:#f7768e">'
			   . esc_html__( 'Learning Hub bundle is missing. Build it with `npm run build` inside modules/twinshell/learning-hub.', 'bizcity-twin-ai' )
			   . '</noscript>' . "\n";
			echo '<div style="padding:24px;color:#f7768e">'
			   . esc_html__( 'Learning Hub bundle is missing. Build it with `npm run build` inside modules/twinshell/learning-hub.', 'bizcity-twin-ai' )
			   . '</div>' . "\n";
		}
		echo '</body></html>' . "\n";
	}

	public static function on_activate() {
		delete_option( self::OPTION_KEY );
	}
}
