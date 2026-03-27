<?php
/**
 * Full page template — /chatgpt-knowledge/
 *
 * @package BizCity_ChatGPT_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'bzck-public' );
wp_enqueue_script( 'bzck-public' );

wp_localize_script( 'bzck-public', 'BZCK_PUB', [
    'ajax_url' => admin_url( 'admin-ajax.php' ),
    'nonce'    => wp_create_nonce( 'bzck_pub_nonce' ),
    'user_id'  => get_current_user_id(),
] );

get_header();
?>
<div class="bzck-page-full">
    <div class="bzck-page-container" style="max-width:800px;margin:0 auto;padding:24px">
        <?php echo do_shortcode( '[bizcity_chatgpt_knowledge show_topics="yes" theme="light"]' ); ?>
    </div>
</div>
<?php
get_footer();
