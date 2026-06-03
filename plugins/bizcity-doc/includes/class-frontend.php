<?php
/**
 * BZDoc Frontend — Template page rendering for /tool-doc/ route.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
	}

	/**
	 * Enqueue CSS only. JS is output manually with type="module" in the template.
	 */
	public static function maybe_enqueue() {
		if ( get_query_var( 'bizcity_agent_page' ) !== 'tool-doc' ) {
			return;
		}

		$dist_dir = BZDOC_DIR . 'assets/dist/';
		$dist_url = BZDOC_URL . 'assets/dist/';

		// Main CSS bundle
		$css_file = $dist_dir . 'doc-app.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'bzdoc-app',
				$dist_url . 'doc-app.css',
				[],
				filemtime( $css_file )
			);
		}

		// PHASE-0-RULE-SKELETON Sprint 0★ (S0.fe-enqueue) — ship the shared
		// <bztwin-notebook-selector> + <bztwin-skeleton-preview> web components
		// on every Doc Studio page, so PromptInput can render them inline.
		if ( class_exists( 'BizCity_KG_Skeleton_Assets' ) ) {
			BizCity_KG_Skeleton_Assets::enqueue();
		}

		// Phase 6.1 — Twinsource bundle is printed manually in page-doc-studio.php
		// (we can't use wp_footer() because the theme would re-inject Flatsome/global-styles).
	}

}
