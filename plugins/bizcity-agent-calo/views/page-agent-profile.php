<?php
/**
 * Full page template for /calo/ rewrite rule.
 *
 * When loaded inside the Touch Bar iframe (?bizcity_iframe=1), renders
 * a compact agent profile with guided command buttons.
 * Otherwise, renders the full shortcode dashboard.
 *
 * @package BizCity_Calo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Iframe mode: Agent Profile with guided commands ── */
#if ( ! empty( $_GET['bizcity_iframe'] ) ) {
    #include BZCALO_DIR . 'views/page-agent-profile.php';
    #return;
##}

/* ── Run shortcode FIRST so wp_localize_script attaches BZCALO data ── */
$bzcalo_html = do_shortcode( '[bizcity_calo]' );

/* ── Now print styles + scripts (BZCALO is localized) ── */
wp_print_styles( 'bzcalo-public' );
?>
<div id="bzcalo-page" style="min-height:100vh;background:#f9fafb">
    <?php echo $bzcalo_html; ?>
</div>
<?php
wp_print_scripts( 'bzcalo-public' );
