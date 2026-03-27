<?php
/**
 * BizCity Tarot – Full-Page Template
 *
 * Applied when a WordPress page uses the "Bói Bài Tarot (BizCity)" page template.
 * Renders the landing shortcode inside the theme's header/footer shell.
 *
 * @package BizCity_Tarot
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>
<div id="bct-full-page-wrap" style="padding:0;margin:0">
    <?php
    while ( have_posts() ) :
        the_post();
        // Embed landing shortcode with title pulled from page title
        $title    = get_the_title();
        $excerpt  = get_the_excerpt();
        echo do_shortcode( '[bizcity_tarot_landing title="' . esc_attr( $title ) . '" subtitle="' . esc_attr( $excerpt ?: 'Nhận thông điệp từ vũ trụ dành riêng cho bạn' ) . '"]' );
    endwhile;
    ?>
</div>
<?php
get_footer();
