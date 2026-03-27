<?php
/**
 * Landing page template filter — intercept WP pages with [bizcity_calo] shortcode.
 *
 * @package BizCity_Calo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'template_include', 'bzcalo_landing_template', 99 );
function bzcalo_landing_template( $template ) {
    if ( ! is_singular( 'page' ) ) return $template;

    global $post;
    if ( ! $post || strpos( $post->post_content, '[bizcity_calo]' ) === false ) {
        return $template;
    }

    // If theme has a clean full-width template, prefer it
    $custom = locate_template( 'template-fullwidth.php' );
    return $custom ?: $template;
}
