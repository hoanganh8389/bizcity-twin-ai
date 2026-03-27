<?php
/**
 * Full-page template wrapper — selected via Page Attributes.
 *
 * @package BizCity_{Name}
 */

get_header(); ?>

<main class="bz{prefix}-page-wrapper" style="max-width:1200px;margin:0 auto;padding:20px;">
    <?php echo do_shortcode( '[bizcity_{slug}]' ); ?>
</main>

<?php get_footer();
