<?php
/**
 * Full page template — /gemini-knowledge/
 *
 * @package BizCity_Gemini_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'bzgk-public' );
wp_enqueue_script( 'bzgk-public' );

wp_localize_script( 'bzgk-public', 'BZGK_PUB', [
    'ajax_url' => admin_url( 'admin-ajax.php' ),
    'nonce'    => wp_create_nonce( 'bzgk_pub_nonce' ),
    'user_id'  => get_current_user_id(),
] );

get_header();
?>
<div class="bzgk-page-full">
    <div class="bzgk-page-container" style="max-width:800px;margin:0 auto;padding:24px">
        <?php echo do_shortcode( '[bizcity_knowledge show_topics="yes" theme="light"]' ); ?>
    </div>
</div>
<?php
get_footer();
