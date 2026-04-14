<?php
/**
 * Frontend: Video Generate — Multi-scene image-to-video form
 *
 * When ?effect_id=X is present, loads effect template (prompt, num_images, model, etc.)
 * Otherwise shows a blank multi-scene creator.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Note: This file is included inside page-kling-profile.php
// $user_id, $is_logged_in, $active_tab are available from parent scope

// Load effect if specified
$effect    = null;
$effect_id = isset( $_GET['effect_id'] ) ? absint( $_GET['effect_id'] ) : 0;
if ( $effect_id && class_exists( 'BizCity_Video_Kling_Database' ) ) {
    $effect = BizCity_Video_Kling_Database::get_video_effect( $effect_id );
    // Only count view when generate tab is actually active (not on other tabs)
    if ( $effect && isset( $active_tab ) && $active_tab === 'generate' ) {
        BizCity_Video_Kling_Database::increment_effect_view( $effect_id );
    }
}

$num_images = $effect ? max( 1, (int) $effect->num_images ) : 1;
$prompt_tpl = $effect ? $effect->prompt_template : '';
$model_val  = $effect ? $effect->model : get_option( 'bizcity_video_kling_default_model', '2.6|pro' );
$dur_val    = $effect ? $effect->duration : (int) get_option( 'bizcity_video_kling_default_duration', 5 );
$ratio_val  = $effect ? $effect->aspect_ratio : get_option( 'bizcity_video_kling_default_aspect_ratio', '9:16' );

$nonce    = wp_create_nonce( 'bvk_nonce' );
$base_url = home_url( '/kling-video/' );
?>

<!-- ═══ GENERATE FORM ═══ -->
<div class="bvk-generate">

    <?php /* Parent profile page already checks is_logged_in */ ?>

    <!-- Back link -->
    <a href="<?php echo esc_url( $base_url . '?tab=studio' ); ?>" class="bvk-back-link">← Quay lại Video Studio</a>

    <?php if ( $effect ): ?>
    <!-- Effect Info Banner -->
    <div class="bvk-effect-banner">
        <?php if ( $effect->thumbnail_url ): ?>
            <img src="<?php echo esc_url( $effect->thumbnail_url ); ?>" alt="" class="bvk-effect-banner__thumb">
        <?php endif; ?>
        <div class="bvk-effect-banner__info">
            <h2><?php echo esc_html( $effect->title ); ?></h2>
            <?php if ( $effect->description ): ?>
                <p><?php echo esc_html( $effect->description ); ?></p>
            <?php endif; ?>
            <?php if ( $effect->badge ): ?>
                <span class="bvk-badge-chip" style="background:<?php echo esc_attr( $effect->badge_color ); ?>"><?php echo esc_html( $effect->badge ); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Multi-scene upload form -->
    <div class="bvk-card">
        <h3>🖼️ Upload ảnh cho từng cảnh</h3>
        <p class="bvk-card__subtitle">
            <?php if ( $effect ): ?>
                Effect này cần <strong><?php echo $num_images; ?> ảnh</strong>. Mỗi ảnh tương ứng 1 cảnh trong video.
            <?php else: ?>
                Thêm ảnh cho mỗi cảnh bạn muốn tạo. Ảnh sẽ được chuyển thành video bằng AI.
            <?php endif; ?>
        </p>

        <div id="bvk-scenes-container">
            <?php for ( $s = 1; $s <= $num_images; $s++ ): ?>
            <div class="bvk-scene" data-scene="<?php echo $s; ?>">
                <div class="bvk-scene__header">
                    <span class="bvk-scene__label">Cảnh <?php echo $s; ?></span>
                    <?php if ( $s > 1 && ! $effect ): ?>
                        <button type="button" class="bvk-scene__remove" title="Xóa cảnh này">✖</button>
                    <?php endif; ?>
                </div>
                <label class="bvk-scene__dropzone" data-scene="<?php echo $s; ?>">
                    <input type="file" accept="image/*" class="bvk-scene__file" data-scene="<?php echo $s; ?>" style="display:none">
                    <div class="bvk-scene__preview" style="display:none">
                        <img src="" alt="">
                        <button type="button" class="bvk-scene__clear" title="Xóa ảnh">✕</button>
                    </div>
                    <div class="bvk-scene__placeholder">
                        <span>📷</span>
                        <p>Kéo thả hoặc nhấn để chọn ảnh</p>
                        <small>JPG, PNG, WebP — tối đa 10MB</small>
                    </div>
                </label>
                <input type="hidden" class="bvk-scene__url" data-scene="<?php echo $s; ?>" value="">
                <div class="bvk-scene__progress"><div class="bvk-scene__progress-bar"></div></div>
            </div>
            <?php endfor; ?>
        </div>

        <?php if ( ! $effect ): ?>
        <div class="bvk-scene-actions">
            <button type="button" id="bvk-add-scene" class="bvk-btn-outline">
                + Thêm cảnh cuối
            </button>
            <button type="button" id="bvk-remove-last-scene" class="bvk-btn-outline bvk-btn-outline--danger" style="display:none">
                ✖ Hủy cảnh cuối
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Prompt + Params -->
    <div class="bvk-card">
        <h3>📝 Mô tả video</h3>

        <div class="bvk-field">
            <label>Prompt</label>
            <textarea id="bvk-gen-prompt" rows="4" placeholder="Mô tả video bạn muốn tạo..."><?php echo esc_textarea( $prompt_tpl ); ?></textarea>
            <?php if ( $effect && $prompt_tpl ): ?>
                <small class="bvk-field__hint">
                    💡 Prompt template từ effect. Các placeholder <code>{{image_1}}</code>, <code>{{image_2}}</code>... sẽ tự động được thay bằng URL ảnh upload.
                </small>
            <?php endif; ?>
        </div>

        <!-- Duration -->
        <div class="bvk-field">
            <label>⏱ Thời lượng</label>
            <div class="bvk-pill-row">
                <?php foreach ( [ 5, 10, 15, 20, 30 ] as $d ): ?>
                <label class="bvk-pill"><input type="radio" name="bvk_gen_duration" value="<?php echo $d; ?>"<?php checked( $dur_val, $d ); ?>> <?php echo $d; ?>s</label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Aspect ratio -->
        <div class="bvk-field">
            <label>📐 Tỉ lệ khung hình</label>
            <div class="bvk-pill-row">
                <label class="bvk-pill"><input type="radio" name="bvk_gen_ratio" value="9:16"<?php checked( $ratio_val, '9:16' ); ?>> 📱 Dọc TikTok</label>
                <label class="bvk-pill"><input type="radio" name="bvk_gen_ratio" value="16:9"<?php checked( $ratio_val, '16:9' ); ?>> 🖥 Ngang YouTube</label>
                <label class="bvk-pill"><input type="radio" name="bvk_gen_ratio" value="1:1"<?php checked( $ratio_val, '1:1' ); ?>> ⬜ Vuông</label>
            </div>
        </div>

        <!-- Model -->
        <div class="bvk-field">
            <label>🤖 Model</label>
            <select id="bvk-gen-model">
                <optgroup label="Kling AI">
                    <option value="2.6|pro"<?php selected( $model_val, '2.6|pro' ); ?>>Kling v2.6 Pro</option>
                    <option value="2.6|std"<?php selected( $model_val, '2.6|std' ); ?>>Kling v2.6 Standard</option>
                    <option value="2.5|pro"<?php selected( $model_val, '2.5|pro' ); ?>>Kling v2.5 Pro</option>
                    <option value="1.6|pro"<?php selected( $model_val, '1.6|pro' ); ?>>Kling v1.6 Pro</option>
                </optgroup>
                <optgroup label="SeeDance">
                    <option value="seedance:1.0"<?php selected( $model_val, 'seedance:1.0' ); ?>>SeeDance v1.0</option>
                </optgroup>
                <optgroup label="Sora (OpenAI)">
                    <option value="sora:v1"<?php selected( $model_val, 'sora:v1' ); ?>>Sora v1</option>
                </optgroup>
                <optgroup label="Veo (Google)">
                    <option value="veo:3"<?php selected( $model_val, 'veo:3' ); ?>>Veo 3</option>
                </optgroup>
            </select>
        </div>

        <!-- CTA -->
        <div class="bvk-btn-row">
            <button type="button" id="bvk-btn-generate" class="bvk-btn bvk-btn-primary" disabled>
                🚀 Tạo Video
            </button>
        </div>

        <div id="bvk-gen-status" class="bvk-status"></div>

        <!-- Result -->
        <div id="bvk-gen-result" class="bvk-result">
            <h4>✅ Đã gửi yêu cầu tạo video!</h4>
            <div id="bvk-gen-result-body"></div>
        </div>
    </div>

</div>
