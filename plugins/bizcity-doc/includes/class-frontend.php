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
		if ( file_exists( $dist_dir . 'doc-app.css' ) ) {
			wp_enqueue_style(
				'bzdoc-app',
				$dist_url . 'doc-app.css',
				[],
				BZDOC_VERSION
			);
		}
	}

}
