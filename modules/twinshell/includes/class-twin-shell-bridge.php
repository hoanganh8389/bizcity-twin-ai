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
	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — idempotent register.
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

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

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — do not inject bridge on
		// normal standalone page loads; inject only for explicit iframe/embed intent.
		if ( ! $this->is_embed_intent_request() ) {
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
		$uid        = (int) get_current_user_id();
		$session_id = 'shell_' . (int) get_current_blog_id() . '_' . $uid;
		$trace_id   = 'tsb_' . substr( md5( $session_id . '|' . (string) microtime( true ) ), 0, 16 );
		$bridge_cfg = (string) wp_json_encode( [
			'pluginId' => $matched['id'],
			'shellUrl' => esc_url_raw( class_exists( 'BizCity_Twin_Shell_Page' ) ? BizCity_Twin_Shell_Page::shell_url() : home_url( '/twin/' ) ),
			'userId'   => $uid,
			'sessionId'=> $session_id,
			'traceId'  => $trace_id,
		] );
		wp_add_inline_script( self::HANDLE, 'window.BIZCITY_TWIN_SHELL_BRIDGE=' . $bridge_cfg . ';', 'before' );

		// Phase 0.13 — also enqueue the cross-plugin primitives bundle so any
		// host page can call window.BizcityTwin.* (picker / source / monitor).
		if ( class_exists( 'BizCity_Twin_Shell_Primitives' ) ) {
			BizCity_Twin_Shell_Primitives::instance()->enqueue( $matched );
		}
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — strict embed intent check.
	 *
	 * @return bool
	 */
	private function is_embed_intent_request() {
		$embed = isset( $_GET['bizcity_iframe'] ) ? (string) wp_unslash( $_GET['bizcity_iframe'] ) : '';
		if ( '1' === $embed ) {
			return true;
		}

		$legacy = isset( $_GET['_embed'] ) ? (string) wp_unslash( $_GET['_embed'] ) : '';
		if ( '1' === $legacy ) {
			return true;
		}

		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( $ref !== '' && false !== strpos( $ref, '/twin/' ) ) {
			return true;
		}

		return false;
	}
}
