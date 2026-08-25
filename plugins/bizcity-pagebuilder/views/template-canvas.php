<?php
/**
 * Template Name: BZPB Canvas
 * Description: Blank canvas template for BizCity Page Builder landing pages.
 *              The full HTML page is pre-rendered in the template_include filter
 *              (bizcity-pagebuilder.php) and stored in $GLOBALS['_bzpb_canvas_html'].
 *              This file just echoes it and exits — no WP hooks, no scope issues.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Primary path: pre-rendered HTML from template_include filter ────────────
if ( ! empty( $GLOBALS['_bzpb_canvas_html'] ) ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
	}
	echo $GLOBALS['_bzpb_canvas_html']; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

// ── Fallback: direct DB query (should never be reached in normal flow) ───────
global $wpdb;
$_bzpb_post_id = (int) get_queried_object_id();
if ( $_bzpb_post_id && class_exists( 'BZPB_Export' ) ) {
	$_bzpb_raw = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_bzpb_site_config' LIMIT 1",
		$_bzpb_post_id
	) );
	if ( $_bzpb_raw ) {
		$_bzpb_cfg = json_decode( $_bzpb_raw, true );
		if ( ! empty( $_bzpb_cfg ) ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=UTF-8' );
			}
			echo BZPB_Export::render_canvas_page( $_bzpb_cfg, $_bzpb_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}
}

// ── Both paths failed — output minimal debug page ───────────────────────────
if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=UTF-8' );
}
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>BZPB Debug</title></head><body>'
	. '<p style="font:14px monospace;padding:20px;white-space:pre-wrap">'
	. 'BZPB canvas debug:' . "\n"
	. 'post_id=' . esc_html( (string) ( $GLOBALS['_bzpb_canvas_post_id'] ?? get_queried_object_id() ) ) . "\n"
	. 'class_exists=' . ( class_exists( 'BZPB_Export' ) ? 'yes' : 'no' ) . "\n"
	. 'bzpb_post_id=' . esc_html( (string) ( $_bzpb_post_id ?? 0 ) ) . "\n"
	. 'raw_len_from_globals=' . esc_html( (string) ( $GLOBALS['_bzpb_canvas_raw_len'] ?? 'not set' ) ) . "\n"
	. 'json_error=' . esc_html( (string) ( $GLOBALS['_bzpb_json_error'] ?? 'not set' ) ) . "\n"
	. 'raw_from_wpdb_len=' . esc_html( (string) strlen( $_bzpb_raw ?? '' ) )
	. '</p></body></html>';
exit;
