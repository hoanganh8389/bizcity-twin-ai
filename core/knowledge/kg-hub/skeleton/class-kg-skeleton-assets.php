<?php
/**
 * BizCity KG-Hub — Skeleton shared FE assets loader (PHASE-0-RULE-SKELETON
 * Sprint 0★ S0.7–S0.9, RULE-3 / RULE-4).
 *
 * Registers `bztwin-skeleton` script + style and exposes
 * `BizCity_KG_Skeleton_Assets::enqueue()` for any plugin (admin or front-end)
 * to load the shared <bztwin-notebook-selector> + <bztwin-skeleton-preview>
 * web components.  Localizes REST root + nonce so the components can call
 * /bizcity/kg/v1/* endpoints under the current user's session.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md §5 RULE-3, §6 RULE-4
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Assets {

	const HANDLE_JS  = 'bztwin-skeleton';
	const HANDLE_CSS = 'bztwin-skeleton';
	const VERSION    = '1.0.0';

	private static $registered = false;
	private static $localized  = false;

	/** Register handles once per request. Safe to call multiple times. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$base = defined( 'BIZCITY_TWIN_AI_URL' )
			? BIZCITY_TWIN_AI_URL
			: plugins_url( '/', dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/bizcity-twin-ai.php' );

		// Assets co-located with kg-hub package (single source of truth — not in shared/).
		$pkg = $base . 'core/knowledge/kg-hub/assets/';

		wp_register_script(
			self::HANDLE_JS,
			$pkg . 'bztwin-skeleton.js',
			[],
			self::VERSION,
			true
		);
		wp_register_style(
			self::HANDLE_CSS,
			$pkg . 'bztwin-skeleton.css',
			[],
			self::VERSION
		);
	}

	/**
	 * Enqueue the shared skeleton assets and inject the JS config blob.
	 * Plugins should call this from their own enqueue hook (admin or wp_enqueue_scripts).
	 */
	public static function enqueue(): void {
		self::register();
		wp_enqueue_script( self::HANDLE_JS );
		wp_enqueue_style( self::HANDLE_CSS );

		if ( ! self::$localized ) {
			self::$localized = true;
			wp_localize_script( self::HANDLE_JS, 'BizTwinSkeletonConfig', [
				'restRoot' => esc_url_raw( rest_url() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'blogId'   => (int) get_current_blog_id(),
			] );
		}
	}
}

// Pre-register handles early so callers can simply `wp_enqueue_script('bztwin-skeleton')`.
add_action( 'init', [ 'BizCity_KG_Skeleton_Assets', 'register' ], 5 );
