<?php
/**
 * AI Image Dialog — Partial
 *
 * Included by both the admin editor page (admin-menu.php) and the
 * frontend editor tab (page-image-profile.php).
 *
 * Requires BZTIMG_AI config object to be wp_localize_script'd before include.
 * CSS class namespace: bztai-*
 *
 * @package BizCity_Tool_Image
 * @since   3.7.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<!-- ═══════════════════════════════════════════════════════════════════
     AI IMAGE DIALOG OVERLAY
     ═══════════════════════════════════════════════════════════════════ -->
<div id="bztai-overlay" role="dialog" aria-modal="true" aria-label="AI Xử lý ảnh" hidden aria-hidden="true">
  <div id="bztai-backdrop"></div>

  <div id="bztai-dialog">

    <!-- ── Header ── -->
    <div class="bztai-header">
      <div class="bztai-header-left">
        <svg class="bztai-icon-star" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/></svg>
        <h2>AI Xử lý ảnh</h2>
        <span class="bztai-badge" id="bztai-cat-badge"></span>
      </div>
      <div class="bztai-header-right">
        <button id="bztai-close" class="bztai-icon-btn" title="Đóng (Esc)" aria-label="Đóng">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
      </div>
    </div>

    <!-- ── Body ── -->
    <div id="bztai-body">

      <!-- LEFT: category list + template grid -->
      <div id="bztai-left">

        <!-- Category pills -->
        <div id="bztai-cats" class="bztai-section">
          <div id="bztai-cat-pills">
            <span class="bztai-spinner-sm"></span>
          </div>
        </div>

        <!-- Template grid -->
        <div id="bztai-tpl-section" class="bztai-section">
          <div class="bztai-section-title">Chọn kịch bản</div>
          <div id="bztai-tpl-grid">
            <div class="bztai-loading">
              <span class="bztai-spinner-sm"></span> Tải danh sách...
            </div>
          </div>
        </div>

      </div><!-- /#bztai-left -->

      <!-- RIGHT: input image + form + results -->
      <div id="bztai-right">

        <!-- Input image -->
        <div id="bztai-input-section" class="bztai-section">
          <div class="bztai-section-title">Ảnh đầu vào <span class="bztai-optional">(nếu cần)</span></div>
          <div id="bztai-input-row">
            <div id="bztai-dropzone" class="bztai-dropzone" tabindex="0" role="button" aria-label="Tải ảnh lên">
              <input type="file" id="bztai-file-input" accept="image/*" style="display:none;">
              <div id="bztai-dz-empty">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>
                <div class="bztai-dz-label">Kéo thả hoặc click</div>
                <div class="bztai-dz-sub">JPG, PNG, WEBP · tối đa 20MB</div>
              </div>
              <img id="bztai-dz-preview" src="" alt="" hidden>
              <button id="bztai-dz-remove" title="Xóa ảnh" hidden>✕</button>
              <input type="hidden" id="bztai-dz-url" name="input_image" value="">
            </div>
            <div id="bztai-input-btns">
              <button id="bztai-media-btn" class="bztai-btn-outline" title="Chọn từ Media Library WordPress">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                Media Library
              </button>
              <button id="bztai-canvas-btn" class="bztai-btn-outline" title="Lấy ảnh đang selected trong canvas">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="16" height="16" x="4" y="4" rx="2"/><path d="M9 9h.01"/><rect width="6" height="6" x="9" y="9" rx="1"/></svg>
                Từ Canvas
              </button>
            </div>
          </div>
        </div>

        <!-- Dynamic form (rendered by JS from form_fields[]) -->
        <div id="bztai-form-section" class="bztai-section" hidden>
          <div class="bztai-section-title" id="bztai-form-title">Cài đặt</div>
          <div id="bztai-form-fields"></div>
        </div>

        <!-- Status -->
        <div id="bztai-status" class="bztai-status" hidden></div>

        <!-- Generate CTA -->
        <div id="bztai-cta-section" class="bztai-section" hidden>
          <button id="bztai-gen-btn" class="bztai-gen-btn">
            <span class="bztai-spinner" id="bztai-gen-spinner" style="display:none;"></span>
            <svg id="bztai-gen-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/></svg>
            <span id="bztai-gen-text">Tạo ảnh</span>
          </button>
        </div>

        <!-- Results grid -->
        <div id="bztai-results"></div>

      </div><!-- /#bztai-right -->

    </div><!-- /#bztai-body -->

  </div><!-- /#bztai-dialog -->
</div><!-- /#bztai-overlay -->

<!-- Floating AI trigger button (only when editor page context) -->
<div id="bztai-trigger-wrap">
  <button id="bztai-trigger" title="AI Tools (Ctrl+Shift+I)">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/></svg>
    AI Tools
  </button>
</div>
