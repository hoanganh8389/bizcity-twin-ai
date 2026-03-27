<?php
/**
 * Template page registration — adds custom template to Page Attributes.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Add template to Page Attributes dropdown
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['bz{prefix}-landing'] = '{Name} (BizCity)';
    return $templates;
} );

// Route to custom template file when selected
add_filter( 'template_include', function( $template ) {
    if ( is_page() ) {
        $page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'bz{prefix}-landing' === $page_template ) {
            $custom = BZ{PREFIX}_DIR . 'views/page-{slug}-full.php';
            if ( file_exists( $custom ) ) return $custom;
        }
    }
    return $template;
} );
