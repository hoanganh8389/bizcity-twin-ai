<?php
/**
 * Frontend: Video Create — AIVA-style two-panel layout
 * Left panel: Multi-scene form with AI model + params
 * Right panel: Job queue / results
 *
 * Included by page-kling-profile.php (Tab 1)
 * Variables from parent scope: $stats, $recent_jobs, $cfg_model, $cfg_duration, $cfg_ratio
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="bvk-aiva">
    <!-- ═══ LEFT PANEL: Form ═══ -->
    <div class="bvk-aiva-form">
        <div class="bvk-aiva-header">
            <span class="bvk-aiva-header__icon">✨</span>
            <p class="bvk-aiva-header__title">Tạo video từ ảnh</p>
        </div>

        <!-- Mode Tabs -->
        <div class="bvk-aiva-modes">
            <button type="button" class="bvk-aiva-mode active" data-mode="image">Tạo từ ảnh</button>
            <button type="button" class="bvk-aiva-mode" data-mode="compose">Tạo từ thành phần</button>
        </div>

        <!-- AI Model -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">AI Model</label>
            <select id="bvk-create-model" class="bvk-aiva-select">
                <optgroup label="Kling AI">
                    <option value="2.6|pro"<?php selected( $cfg_model, '2.6|pro' ); ?>>Kling v2.6 Pro (Recommended)</option>
                    <option value="2.6|std"<?php selected( $cfg_model, '2.6|std' ); ?>>Kling v2.6 Standard</option>
                    <option value="2.5|pro"<?php selected( $cfg_model, '2.5|pro' ); ?>>Kling v2.5 Pro</option>
                    <option value="1.6|pro"<?php selected( $cfg_model, '1.6|pro' ); ?>>Kling v1.6 Pro</option>
                </optgroup>
                <optgroup label="SeeDance">
                    <option value="seedance:1.0"<?php selected( $cfg_model, 'seedance:1.0' ); ?>>SeeDance v1.0</option>
                </optgroup>
                <optgroup label="Sora (OpenAI)">
                    <option value="sora:v1"<?php selected( $cfg_model, 'sora:v1' ); ?>>Sora v1</option>
                </optgroup>
                <optgroup label="Veo (Google)">
                    <option value="veo:3"<?php selected( $cfg_model, 'veo:3' ); ?>>Veo 3</option>
                </optgroup>
            </select>
        </div>

        <!-- Tỷ lệ khung hình -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">Tỷ lệ khung hình</label>
            <div class="bvk-aiva-pills">
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_ratio" value="16:9"<?php checked( $cfg_ratio, '16:9' ); ?>> 16:9</label>
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_ratio" value="9:16"<?php checked( $cfg_ratio, '9:16' ); ?>> 9:16</label>
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_ratio" value="1:1"<?php checked( $cfg_ratio, '1:1' ); ?>> 1:1</label>
            </div>
        </div>

        <!-- Độ phân giải -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">Độ phân giải</label>
            <div class="bvk-aiva-pills">
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_resolution" value="1080p"> 1080p</label>
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_resolution" value="720p" checked> 720p</label>
            </div>
        </div>

        <!-- Thời lượng -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">Thời lượng</label>
            <div class="bvk-aiva-pills">
                <?php foreach ( [ 5, 10 ] as $d ): ?>
                <label class="bvk-aiva-pill"><input type="radio" name="bvk_duration" value="<?php echo $d; ?>"<?php checked( $cfg_duration ?: 10, $d ); ?>> <?php echo $d; ?>s</label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Scenes: Image + Prompt per scene (max 3) -->
        <div class="bvk-aiva-group">
            <div class="bvk-aiva-group__head">
                <label class="bvk-aiva-label">Image</label>
                <button type="button" id="bvk-create-add-scene" class="bvk-aiva-add-scene">+ Thêm cảnh cuối</button>
            </div>
            <div id="bvk-create-scenes">

                <!-- Scene 1 (always present, no remove button) -->
                <div class="bvk-aiva-scene" data-scene="1">
                    <label class="bvk-aiva-dropzone" data-scene="1">
                        <input type="file" accept="image/*" class="bvk-aiva-scene-file" data-scene="1" style="display:none">
                        <div class="bvk-aiva-scene-preview" style="display:none">
                            <img src="" alt="">
                            <button type="button" class="bvk-aiva-scene-clear" title="Xóa ảnh">✕</button>
                        </div>
                        <div class="bvk-aiva-scene-placeholder">
                            <span>📤</span>
                            <p>Click để tải lên ảnh</p>
                            <small>JPG/PNG/WEBP tối đa 10MB, kích thước tối thiểu 300px</small>
                        </div>
                    </label>
                    <input type="hidden" class="bvk-aiva-scene-url" data-scene="1" value="">
                    <div class="bvk-aiva-scene-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>
                    <div class="bvk-aiva-scene-prompt-wrap">
                        <div class="bvk-aiva-prompt-head">
                            <span class="bvk-aiva-prompt-label">Prompt</span>
                            <label class="bvk-aiva-switch">
                                <span>Translate Prompt</span>
                                <input type="checkbox" class="bvk-translate-prompt">
                                <span class="bvk-aiva-switch__track"></span>
                            </label>
                        </div>
                        <textarea class="bvk-aiva-scene-prompt bvk-aiva-textarea" rows="4" placeholder="What do you want to create with this image?" maxlength="2000"></textarea>
                        <div class="bvk-aiva-prompt-foot">
                            <button type="button" class="bvk-aiva-optimize">✨ Tối ưu prompt</button>
                            <span class="bvk-aiva-charcount">0/2000</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Create CTA -->
        <div class="bvk-aiva-cta">
            <button type="button" id="bvk-btn-create-video" class="bvk-aiva-create-btn" disabled>
                <span>▶</span> Tạo video
            </button>
        </div>

        <div id="bvk-create-status" class="bvk-status" style="margin-top:10px;"></div>
    </div>

    <!-- ═══ RIGHT PANEL: Results / Queue ═══ -->
    <div class="bvk-aiva-results">
        <?php if ( empty( $recent_jobs ) ): ?>
        <div class="bvk-aiva-empty" id="bvk-results-empty">
            <div class="bvk-aiva-empty__icon">🎬</div>
            <h3>No video created yet</h3>
            <p>Your generated videos will appear here</p>
        </div>
        <?php else: ?>
        <!-- Multi-select bar -->
        <div id="bvk-multi-select-bar" style="display:none;position:sticky;top:0;z-index:10;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:8px 12px;margin-bottom:8px;align-items:center;gap:8px;">
            <span id="bvk-multi-select-count" style="font-size:12px;color:#e6edf3;font-weight:600;">0 video đã chọn</span>
            <button type="button" onclick="bvkSendSelectedToEditor()" style="background:#1f6feb;color:#fff;border:1px solid #388bfd;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">🎞️ Mở trong Editor</button>
            <button type="button" onclick="bvkClearSelection()" style="background:transparent;color:#8b949e;border:1px solid #30363d;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;">✕ Bỏ chọn</button>
        </div>
        <div id="bvk-create-jobs" class="bvk-aiva-jobs">
            <?php foreach ( $recent_jobs as $job ):
                $st_class  = 'st-' . esc_attr( $job['status'] );
                $st_labels = [ 'draft' => 'Nháp', 'queued' => 'Đang chờ', 'processing' => 'Đang xử lý', 'completed' => 'Hoàn thành', 'failed' => 'Lỗi' ];
                $video_url = $job['media_url'] ?: $job['video_url'];
                $model_labels = [
                    '2.6|pro' => 'Kling 2.6 Pro', '2.6|std' => 'Kling 2.6 Std', '2.5|pro' => 'Kling 2.5 Pro', '1.6|pro' => 'Kling 1.6 Pro',
                    'seedance:1.0' => 'SeeDance', 'sora:v1' => 'Sora', 'veo:3' => 'Veo 3',
                ];
                $model_label = $model_labels[ $job['model'] ?? '' ] ?? ( $job['model'] ?: 'Kling' );
            ?>
            <div class="bvk-aiva-job" data-job-id="<?php echo (int) $job['id']; ?>" data-status="<?php echo esc_attr( $job['status'] ); ?>">
                <div class="bvk-job-top">
                    <span class="bvk-job-status <?php echo $st_class; ?>"><?php echo $st_labels[ $job['status'] ] ?? $job['status']; ?></span>
                    <span style="font-size:10px;color:#58a6ff;font-weight:600;background:rgba(31,111,235,.15);padding:1px 6px;border-radius:4px;"><?php echo esc_html( $model_label ); ?></span>
                    <span style="font-size:11px;color:#484f58;margin-left:auto;"><?php echo esc_html( $job['created_at'] ); ?></span>
                </div>
                <div class="bvk-job-prompt"><?php echo esc_html( mb_strimwidth( $job['prompt'] ?: 'No prompt', 0, 100, '...' ) ); ?></div>
                <div class="bvk-job-meta">
                    <span>⏱ <?php echo esc_html( $job['duration'] ); ?>s</span>
                    <span>📐 <?php echo esc_html( $job['aspect_ratio'] ); ?></span>
                    <span>#<?php echo (int) $job['id']; ?></span>
                    <?php if ( $video_url && $job['status'] === 'completed' ): ?>
                    <a href="<?php echo esc_url( $video_url ); ?>" target="_blank">▶ Xem video</a>
                    <?php endif; ?>
                </div>
                <?php if ( in_array( $job['status'], [ 'queued', 'processing' ], true ) ): ?>
                <div class="bvk-progress"><div class="bvk-progress-bar" style="width:<?php echo (int) $job['progress']; ?>%"></div></div>
                <?php endif; ?>
                <?php if ( $job['status'] === 'completed' && $video_url ): ?>
                <div class="bvk-job-actions">
                    <label style="display:flex;align-items:center;gap:4px;cursor:pointer;margin-right:4px;"><input type="checkbox" class="bvk-job-select" data-url="<?php echo esc_attr( $video_url ); ?>"> <span style="font-size:11px;color:#8b949e;">Chọn</span></label>
                    <?php if ( ! empty( $job['media_url'] ) ): ?>
                    <button type="button" class="bvk-job-act done" disabled>✅ Media</button>
                    <?php elseif ( ! empty( $job['video_url'] ) ): ?>
                    <button type="button" class="bvk-job-act" onclick="bvkUploadToMedia(<?php echo (int) $job['id']; ?>, this)">📥 Upload</button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $video_url ); ?>" target="_blank" class="bvk-job-act" style="text-decoration:none;">▶ Xem</a>
                    <button type="button" class="bvk-job-act" onclick="bvkCopyLink('<?php echo esc_js( $video_url ); ?>', this)">🔗 Copy</button>
                    <button type="button" class="bvk-job-act" style="background:#1f6feb;color:#fff;border-color:#388bfd;" onclick="bvkSendToEditor([<?php echo esc_attr( json_encode( $video_url ) ); ?>])">🎞️ Editor</button>
                </div>
                <?php endif; ?>
                <?php if ( $job['status'] === 'failed' && ! empty( $job['error_message'] ) ): ?>
                <div style="font-size:11px;color:#f85149;margin-top:6px;">💡 <?php echo esc_html( $job['error_message'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
