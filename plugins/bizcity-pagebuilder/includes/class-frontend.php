<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Frontend {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
		add_filter( 'theme_page_templates', [ __CLASS__, 'register_canvas_template' ] );
		add_filter( 'template_include',    [ __CLASS__, 'load_canvas_template' ] );
	}

	public static function maybe_enqueue(): void {
		if ( get_query_var( 'bizcity_agent_page' ) !== 'tool-pagebuilder' ) {
			return;
		}

		$dist_css = BZPB_DIR . 'assets/dist/pagebuilder-app.css';
		if ( file_exists( $dist_css ) ) {
			$version = filemtime( $dist_css );
			wp_enqueue_style( 'bzpb-app', BZPB_URL . 'assets/dist/pagebuilder-app.css', [], $version );
		}
	}

	public static function isolate_theme(): void {
		global $wp_styles, $wp_scripts;

		$keep_styles = [ 'bzpb-app', 'thickbox', 'media-views', 'imgareaselect', 'wp-mediaelement', 'mediaelement' ];

		// WP core + media library deps needed for wp.media() picker
		$keep_scripts = [
			'bzpb-app', 'wp-api-fetch',
			// jQuery
			'jquery', 'jquery-core', 'jquery-migrate',
			// jQuery UI (required by media drag/drop)
			'jquery-ui-core', 'jquery-ui-draggable',
			// Media library
			'media-upload', 'media-views', 'media-editor', 'media-audiovideo',
			// Plupload (file upload inside media)
			'moxiejs', 'plupload', 'wp-plupload', 'plupload-handlers', 'plupload-all',
			// Misc media deps
			'imgareaselect', 'thickbox', 'clipboard',
			// Backbone / Underscore (media-views depends on these)
			'backbone', 'underscore', 'wp-backbone',
			// WP utilities
			'wp-util', 'wp-ajax-response', 'wp-i18n', 'wp-hooks', 'wp-dom-ready', 'wp-polyfill',
			// Media element (audio/video preview)
			'mediaelement', 'wp-mediaelement',
		];

		if ( $wp_styles ) {
			foreach ( $wp_styles->registered as $handle => $dep ) {
				if ( ! in_array( $handle, $keep_styles, true ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		if ( $wp_scripts ) {
			foreach ( $wp_scripts->registered as $handle => $dep ) {
				if ( ! in_array( $handle, $keep_scripts, true ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
	}

	/**
	 * Register "BZPB Canvas" as a page template option in the editor.
	 */
	public static function register_canvas_template( array $templates ): array {
		$templates['bzpb-canvas'] = __( 'BZPB Canvas (Landing Page)', 'bizcity-pagebuilder' );
		return $templates;
	}

	/**
	 * Serve the plugin's canvas template file when _wp_page_template = bzpb-canvas.
	 */
	public static function load_canvas_template( string $template ): string {
		if ( ! is_singular( 'page' ) ) {
			return $template;
		}
		if ( get_post_meta( get_queried_object_id(), '_wp_page_template', true ) === 'bzpb-canvas' ) {
			$canvas = BZPB_DIR . 'views/template-canvas.php';
			if ( file_exists( $canvas ) ) {
				return $canvas;
			}
		}
		return $template;
	}
}
