<?php
/**
 * Standalone Avatar LipSync Studio at /avatar/
 *
 * Pattern follows class-video-editor-page.php:
 *   WP rewrite → query var → template_redirect → render full-page HTML
 *
 * @package BizCity_Video_Kling
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Avatar_Page {

    const SLUG = 'avatar';

    public static function init() {
        add_action( 'init',              [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'render' ] );
    }

    public static function register_rewrite() {
        add_rewrite_rule(
            '^' . self::SLUG . '/?$',
            'index.php?bvk_avatar_page=1',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'bvk_avatar_page';
        return $vars;
    }

    public static function render() {
        if ( ! get_query_var( 'bvk_avatar_page' ) ) {
            return;
        }

        // Must be logged in
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( home_url( '/' . self::SLUG . '/' ) ) );
            exit;
        }

        include BIZCITY_VIDEO_KLING_DIR . 'views/page-avatar.php';
        exit;
    }
}
