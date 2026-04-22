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

        <!-- Batch Options -->
        <div class="bvk-aiva-group bvk-batch-options">
            <label class="bvk-aiva-label">Tùy chọn Batch</label>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#c9d1d9;">
                    <input type="checkbox" id="bvk-batch-auto-fetch" checked style="accent-color:#1f6feb;">
                    <span>📥 Auto-fetch to Media khi hoàn thành</span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#c9d1d9;">
                    <input type="checkbox" id="bvk-batch-auto-editor" style="accent-color:#1f6feb;">
                    <span>🎞️ Auto-send to Editor khi tất cả xong</span>
                </label>
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

    <!-- ═══ RIGHT PANEL: Live Monitor (identical to Monitor tab) ═══ -->
    <div class="bvk-aiva-results">
        <!-- Stats bar -->
        <div class="bvk-monitor-bar" id="bvk-create-stats-bar">
            <?php if ( $stats ): ?>
            <span class="bvk-monitor-badge bvk-badge-total">🎬 <?php echo $stats['total']; ?></span>
            <span class="bvk-monitor-badge bvk-badge-done">✅ <?php echo $stats['done']; ?></span>
            <?php if ( $stats['active'] > 0 ): ?>
            <span class="bvk-monitor-badge bvk-badge-active">⏳ <?php echo $stats['active']; ?> đang chạy</span>
            <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="bvk-btn-sm bvk-create-refresh-btn" style="margin-left:auto;padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:1px solid #30363d;background:#21262d;cursor:pointer;color:#8b949e;">🔄 Làm mới</button>
        </div>

        <!-- Live Console -->
        <div class="bvk-console" id="bvk-create-console">
            <span class="bvk-log-line"><span class="bvk-log-time">[<?php echo current_time( 'H:i:s' ); ?>]</span> <span class="bvk-log-info">Create monitor ready. Auto-poll <?php echo $stats && $stats['active'] > 0 ? 'ON' : 'OFF'; ?>.</span></span>
        </div>

        <!-- Job List -->
        <div class="bvk-section">
            <div id="bvk-create-jobs" class="bvk-job-list">
            <?php if ( empty( $recent_jobs ) ): ?>
                <div class="bvk-aiva-empty" id="bvk-results-empty">
                    <div class="bvk-aiva-empty__icon">🎬</div>
                    <h3>No video created yet</h3>
                    <p>Your generated videos will appear here</p>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
