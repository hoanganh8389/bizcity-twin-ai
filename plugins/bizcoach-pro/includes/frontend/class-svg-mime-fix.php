<?php
/**
 * BizCoach Pro — SVG MIME Type Fix
 *
 * Ensures SVG files uploaded/saved by astro chart generator can be served
 * correctly by WordPress. Fixes "server cannot process the image" errors.
 *
 * @package BizCoach_Pro
 * @since   0.3.24 (HOTFIX 2026-06-17)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_SVG_MIME_Fix' ) ) { return; }

/**
 * SVG MIME Fix — Allow SVG uploads + correct MIME type.
 *
 * [2026-06-17 Johnny Chu] HOTFIX — WordPress core + security plugins often
 * block or serve wrong MIME type for .svg files, causing browser errors.
 * This class ensures SVG files saved by bccm_astro_save_svg_file() can be
 * served correctly.
 */
class BizCoach_Pro_SVG_MIME_Fix {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Allow SVG in WordPress upload MIME types.
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_svg_mime' ), 99 );

		// Fix MIME type for SVG files when serving from uploads.
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_svg_mime' ), 10, 4 );

		// Add SVG to allowed extensions.
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'allow_svg_upload' ) );
	}

	/**
	 * Allow SVG MIME type in WordPress uploads.
	 *
	 * @param array $mimes Current allowed MIME types.
	 * @return array Modified MIME types with SVG.
	 */
	public static function allow_svg_mime( $mimes ) {
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Fix MIME type detection for SVG files.
	 *
	 * WordPress wp_check_filetype_and_ext() may return false for SVG even when
	 * allowed in upload_mimes. This filter forces correct type.
	 *
	 * @param array  $data     File data array.
	 * @param string $file     Full path to the file.
	 * @param string $filename The name of the file (may differ from $file).
	 * @param array  $mimes    Allowed MIME types.
	 * @return array Modified file data.
	 */
	public static function fix_svg_mime( $data, $file, $filename, $mimes ) {
		$ext = pathinfo( $filename, PATHINFO_EXTENSION );
		if ( $ext === 'svg' || $ext === 'svgz' ) {
			$data['ext']             = $ext;
			$data['type']            = 'image/svg+xml';
			$data['proper_filename'] = $filename;
		}
		return $data;
	}

	/**
	 * Allow SVG files in wp_handle_upload prefilter.
	 *
	 * @param array $file Upload file data.
	 * @return array Modified file data.
	 */
	public static function allow_svg_upload( $file ) {
		$ext = pathinfo( isset( $file['name'] ) ? $file['name'] : '', PATHINFO_EXTENSION );
		if ( $ext === 'svg' || $ext === 'svgz' ) {
			$file['type'] = 'image/svg+xml';
		}
		return $file;
	}
}
