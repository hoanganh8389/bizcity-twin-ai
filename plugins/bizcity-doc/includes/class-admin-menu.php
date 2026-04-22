<?php
/**
 * BZDoc Admin Menu — admin page for Doc Studio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Admin_Menu {

	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue admin CSS/JS on relevant pages.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our admin pages or the main twin-ai page
		if ( strpos( $hook, 'bizcity' ) === false && strpos( $hook, 'bzdoc' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'bzdoc-admin',
			BZDOC_URL . 'assets/admin.css',
			[],
			BZDOC_VERSION
		);
	}
}
