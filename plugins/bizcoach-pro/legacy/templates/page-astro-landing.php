<?php
/**
 * BizCoach Map – Page Template: Astrology Landing
 *
 * Template Name: Trang Chiêm Tinh (BizCoach)
 *
 * Full-width cosmic-themed page for astrology birth chart creation.
 * Uses the [bccm_astro_landing] shortcode internally.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

get_header();
?>

<style>
/* Override theme styles for full-width cosmic page */
.astro-lp-page-wrap {
  margin: 0 !important;
  padding: 0 !important;
  max-width: 100% !important;
  width: 100% !important;
}
/* Hide theme elements that break the cosmic layout */
.astro-lp-page-wrap .page-title,
.astro-lp-page-wrap .breadcrumbs,
.astro-lp-page-wrap .entry-title {
  display: none !important;
}
</style>

<div class="astro-lp-page-wrap">
  <?php
  if (function_exists('bccm_astro_landing_shortcode')) {
    echo bccm_astro_landing_shortcode([]);
  } else {
    echo '<div style="padding:60px;text-align:center"><h2>Plugin BizCoach Map chưa được kích hoạt.</h2></div>';
  }
  ?>
</div>

<?php
// Render Nobi Progress Panel for logged-in users
if (is_user_logged_in() && function_exists('bccm_render_frontend_progress_panel')) {
  bccm_render_frontend_progress_panel();
}
?>

<?php get_footer(); ?>
