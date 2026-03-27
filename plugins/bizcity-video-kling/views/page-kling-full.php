<?php
/**
 * BizCity Video Kling – Full-Page Template
 *
 * Served at /kling-video/ via rewrite rule.
 * Also available as page template via theme_page_templates.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>
<div id="bvk-full-page-wrap" style="max-width:960px;margin:0 auto;padding:30px 20px;">
    <?php
    // If shortcode exists, use it; otherwise show placeholder
    if ( shortcode_exists( 'bizcity_kling_video' ) ) {
        echo do_shortcode( '[bizcity_kling_video]' );
    } else {
    ?>
    <div style="text-align:center;padding:60px 20px;">
        <div style="font-size:64px;margin-bottom:16px;">🎬</div>
        <h1 style="font-size:28px;font-weight:700;color:#1a1a2e;margin-bottom:8px;">BizCity Video Kling</h1>
        <p style="color:#6b7280;font-size:16px;max-width:480px;margin:0 auto 24px;">
            Tạo video bằng Kling AI – Image to Video cho Social Media.
        </p>
        <?php if ( is_user_logged_in() && current_user_can( 'manage_options' ) ): ?>
        <a href="<?php echo admin_url( 'admin.php?page=bizcity-video-kling' ); ?>"
           style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-radius:12px;text-decoration:none;font-weight:600;">
            Mở Dashboard Kling
        </a>
        <?php endif; ?>
    </div>
    <?php } ?>
</div>
<?php
get_footer();
