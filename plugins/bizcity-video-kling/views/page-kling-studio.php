<?php
/**
 * Frontend: Video Studio — Effect Gallery + Hero Cards
 *
 * Inspired by AIVA Video Studio sidebar/home.
 * Shows: Hero cards (Text-to-Video, Image-to-Video, AI Avatar),
 *        Featured Effect, Category tabs, Effect Gallery grid, Explore feed.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load effects — guard against missing table (table created on plugin activation)
$featured_effects = [];
$categories       = [];
$all_effects      = [];

if ( class_exists( 'BizCity_Video_Kling_Database' ) ) {
    global $wpdb;
    $_effects_table = BizCity_Video_Kling_Database::get_table_name( 'video_effects' );
    $_table_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $_effects_table ) ) === $_effects_table;
    if ( $_table_exists ) {
        $featured_effects = BizCity_Video_Kling_Database::get_video_effects( [
            'featured' => true,
            'limit'    => 6,
            'orderby'  => 'sort_order',
            'order'    => 'ASC',
        ] ) ?: [];
        $categories = BizCity_Video_Kling_Database::get_effect_categories() ?: [];
        $all_effects = BizCity_Video_Kling_Database::get_video_effects( [
            'limit'    => 60,
            'orderby'  => 'sort_order',
            'order'    => 'ASC',
        ] ) ?: [];
    }
}

$nonce = wp_create_nonce( 'bvk_nonce' );  // Used by video-studio.js via bvk_studio global
$base_url = home_url( '/kling-video/' );
?>

<div class="bvk-studio">

    <!-- ═══ HERO CARDS ═══ -->
    <div class="bvk-hero-grid">
        <a href="<?php echo esc_url( $base_url . '?tab=create' ); ?>" class="bvk-hero-card" style="background:linear-gradient(to right bottom,#7c3aed,#4c1d95);">
            <div class="bvk-hero-card__head">
                <span class="bvk-hero-card__icon">🎬</span>
                <span class="bvk-hero-card__title">Tạo video từ text</span>
            </div>
            <p class="bvk-hero-card__desc">Nhập mô tả văn bản và tạo ra video tuyệt đẹp với AI. Biến ý tưởng thành video chỉ trong vài phút.</p>
        </a>
        <a href="<?php echo esc_url( $base_url . '?tab=create' ); ?>" class="bvk-hero-card" style="background:linear-gradient(to right bottom,#0d9488,#115e59);">
            <div class="bvk-hero-card__head">
                <span class="bvk-hero-card__icon">🖼️</span>
                <span class="bvk-hero-card__title">Tạo video từ ảnh</span>
            </div>
            <p class="bvk-hero-card__desc">Tải lên hình ảnh và tạo ra video sinh động với công nghệ AI tiên tiến. Chuyển đổi ảnh tĩnh thành video chuyển động.</p>
        </a>
        <div class="bvk-hero-card bvk-hero-card--coming" style="background:linear-gradient(to right bottom,#2563eb,#1e40af);">
            <span class="bvk-hero-card__badge">Coming soon</span>
            <div class="bvk-hero-card__head">
                <span class="bvk-hero-card__icon">🧑‍💻</span>
                <span class="bvk-hero-card__title">Tạo avatar AI</span>
            </div>
            <p class="bvk-hero-card__desc">Tạo avatar AI cá nhân hóa với công nghệ deepfake tiên tiến. Tạo video người nói ảo từ văn bản.</p>
        </div>
    </div>

    <?php if ( ! empty( $featured_effects ) ): ?>
    <!-- ═══ FEATURED EFFECT ═══ -->
    <div class="bvk-section">
        <div class="bvk-section__header">
            <h3 class="bvk-section__title">⭐ Featured Effect</h3>
            <div class="bvk-section__nav" id="bvk-featured-nav">
                <button type="button" class="bvk-nav-arrow" data-dir="prev" disabled>‹</button>
                <span class="bvk-nav-counter"><span id="bvk-featured-idx">1</span> / <?php echo count( $featured_effects ); ?></span>
                <button type="button" class="bvk-nav-arrow" data-dir="next">›</button>
            </div>
        </div>
        <div class="bvk-featured-carousel" id="bvk-featured-carousel">
            <?php foreach ( $featured_effects as $i => $fe ): ?>
            <div class="bvk-featured-slide<?php echo $i === 0 ? ' active' : ''; ?>" data-idx="<?php echo $i; ?>">
                <div class="bvk-featured-slide__img">
                    <?php if ( $fe->thumbnail_url ): ?>
                        <img src="<?php echo esc_url( $fe->thumbnail_url ); ?>" alt="<?php echo esc_attr( $fe->title ); ?>">
                    <?php endif; ?>
                    <?php if ( $fe->badge ): ?>
                        <span class="bvk-badge-chip" style="background:<?php echo esc_attr( $fe->badge_color ); ?>"><?php echo esc_html( $fe->badge ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bvk-featured-slide__info">
                    <h4><?php echo esc_html( $fe->title ); ?></h4>
                    <p><?php echo esc_html( $fe->description ); ?></p>
                    <a href="<?php echo esc_url( $base_url . '?tab=generate&effect_id=' . $fe->id ); ?>" class="bvk-btn-link">Tạo ngay →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ CATEGORY TABS ═══ -->
    <div class="bvk-cat-tabs" id="bvk-cat-tabs">
        <button type="button" class="bvk-cat-tab active" data-cat="">Tất cả</button>
        <?php foreach ( (array) $categories as $cat ): ?>
            <button type="button" class="bvk-cat-tab" data-cat="<?php echo esc_attr( $cat ); ?>">
                <?php echo esc_html( $cat ); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ═══ EFFECT GALLERY GRID ═══ -->
    <div class="bvk-section">
        <div class="bvk-section__header">
            <div>
                <h3 class="bvk-section__title">🎨 Effect Gallery</h3>
                <p class="bvk-section__subtitle">Popular Effects</p>
            </div>
        </div>

        <?php if ( empty( $all_effects ) ): ?>
            <div class="bvk-empty-gallery">
                <span>🎬</span>
                <p>Chưa có effect nào.</p>
            </div>
        <?php else: ?>
        <div class="bvk-effect-grid" id="bvk-effect-grid">
            <?php foreach ( $all_effects as $eff ): ?>
            <a href="<?php echo esc_url( $base_url . '?tab=generate&effect_id=' . $eff->id ); ?>" class="bvk-effect-card" data-cat="<?php echo esc_attr( $eff->category ); ?>">
                <div class="bvk-effect-card__media">
                    <div class="bvk-effect-card__thumb">
                        <?php if ( $eff->thumbnail_url ): ?>
                            <img src="<?php echo esc_url( $eff->thumbnail_url ); ?>" alt="<?php echo esc_attr( $eff->title ); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="bvk-effect-card__placeholder">🎬</div>
                        <?php endif; ?>

                        <?php if ( $eff->badge ): ?>
                            <span class="bvk-effect-card__badge" style="background:<?php echo esc_attr( $eff->badge_color ); ?>">
                                <?php echo esc_html( $eff->badge ); ?>
                            </span>
                        <?php endif; ?>

                        <span class="bvk-effect-card__views">👁 <?php echo number_format( $eff->view_count ); ?></span>

                        <div class="bvk-effect-card__overlay">
                            <span class="bvk-effect-card__cta">Use this effect</span>
                        </div>
                    </div>
                </div>
                <p class="bvk-effect-card__name"><?php echo esc_html( $eff->title ); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- .bvk-studio -->
