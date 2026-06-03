<?php
/**
 * Twin Shell — Auto-inject the bridge JS into any page whose URL matches a
 * registered plugin slug. The bridge syncs URL changes between the embedded
 * plugin and the parent /twin/ shell window.
 *
 * The bridge is only injected when:
 *   1. The current request matches a registered plugin's public_slug, AND
 *   2. Either ?_embed=1 is present, OR the page is loaded inside a frame
 *      (the bridge itself no-ops if window === window.top).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Bridge {

	const HANDLE = 'bizcity-twin-shell-bridge';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// Priority 5 so we run before most theme/plugin enqueue hooks.
		add_action( 'wp_enqueue_scripts',    [ $this, 'maybe_enqueue' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ], 5 );
	}

	public function maybe_enqueue() {
		// Skip if we're rendering the shell itself.
		if ( get_query_var( BizCity_Twin_Shell_Page::QUERY_VAR ) ) {
			return;
		}

		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $req ) {
			return;
		}

		$registry = BizCity_Twin_Shell_Registry::instance();
		$matched  = $registry->match_request_uri( $req );
		if ( ! $matched ) {
			return;
		}

		$src = BIZCITY_TWIN_SHELL_URL . 'assets/twin-shell-bridge.js';
		wp_enqueue_script(
			self::HANDLE,
			$src,
			[],
			BIZCITY_TWIN_SHELL_VERSION,
			false // load in <head> so it can hook history early
		);

		// Tell the bridge which plugin id this page belongs to.
		$bridge_cfg = (string) wp_json_encode( [
			'pluginId' => $matched['id'],
			'shellUrl' => esc_url_raw( class_exists( 'BizCity_Twin_Shell_Page' ) ? BizCity_Twin_Shell_Page::shell_url() : home_url( '/twin/' ) ),
		] );
		wp_add_inline_script( self::HANDLE, 'window.BIZCITY_TWIN_SHELL_BRIDGE=' . $bridge_cfg . ';', 'before' );

		// Phase 0.13 — also enqueue the cross-plugin primitives bundle so any
		// host page can call window.BizcityTwin.* (picker / source / monitor).
		if ( class_exists( 'BizCity_Twin_Shell_Primitives' ) ) {
			BizCity_Twin_Shell_Primitives::instance()->enqueue( $matched );
		}
	}
}
