<?php
/**
 * Frontend: Face Swap — AIVA-style two-panel layout
 * Left panel: Face image upload + Video template gallery/upload
 * Right panel: Job queue / results with status polling
 *
 * Included by page-kling-profile.php (Tab: faceswap)
 * Variables from parent scope: $stats, $recent_jobs
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="bvk-aiva bvk-faceswap">
    <!-- ═══ LEFT PANEL: Form ═══ -->
    <div class="bvk-aiva-form">
        <div class="bvk-aiva-header">
            <span class="bvk-aiva-header__icon">🎭</span>
            <p class="bvk-aiva-header__title">Face Swap Video</p>
        </div>

        <!-- ① Face Image (swap_image) -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">① Ảnh khuôn mặt</label>
            <label class="bvk-aiva-dropzone bvk-fs-dropzone" id="bvk-fs-face-dropzone">
                <input type="file" accept="image/*" id="bvk-fs-face-file" style="display:none">
                <div class="bvk-aiva-scene-preview" id="bvk-fs-face-preview" style="display:none">
                    <img src="" alt="">
                    <button type="button" class="bvk-aiva-scene-clear" id="bvk-fs-face-clear" title="Xóa ảnh">✕</button>
                </div>
                <div class="bvk-aiva-scene-placeholder" id="bvk-fs-face-placeholder">
                    <span>🧑</span>
                    <p>Upload ảnh chứa khuôn mặt</p>
                    <small>JPG/PNG/WEBP — rõ nét, chính diện tốt nhất</small>
                </div>
            </label>
            <input type="hidden" id="bvk-fs-face-url" value="">
            <div class="bvk-aiva-scene-progress" id="bvk-fs-face-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>
            <div style="margin-top:6px;">
                <button type="button" id="bvk-fs-face-media-btn" class="bvk-fs-media-btn">📁 Chọn từ Media Library</button>
            </div>
        </div>

        <!-- ② Video mẫu (target_video) -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">② Video mẫu</label>

            <!-- Source Tabs -->
            <div class="bvk-fs-source-tabs">
                <button type="button" class="bvk-fs-source-tab active" data-source="gallery">📋 Gallery</button>
                <button type="button" class="bvk-fs-source-tab" data-source="upload">📤 Upload</button>
                <button type="button" class="bvk-fs-source-tab" data-source="url">🔗 URL</button>
            </div>

            <!-- Source: Gallery -->
            <div class="bvk-fs-source-panel active" id="bvk-fs-panel-gallery">
                <div class="bvk-fs-gallery-filter">
                    <input type="text" id="bvk-fs-gallery-search" placeholder="Tìm template..." class="bvk-aiva-input">
                    <select id="bvk-fs-gallery-cat" class="bvk-aiva-select" style="max-width:140px;">
                        <option value="">Tất cả</option>
                    </select>
                </div>
                <div id="bvk-fs-gallery-grid" class="bvk-fs-gallery-grid">
                    <div class="bvk-fs-gallery-loading">⏳ Đang tải templates...</div>
                </div>
            </div>

            <!-- Source: Upload -->
            <div class="bvk-fs-source-panel" id="bvk-fs-panel-upload">
                <label class="bvk-aiva-dropzone bvk-fs-dropzone" id="bvk-fs-video-dropzone">
                    <input type="file" accept="video/*" id="bvk-fs-video-file" style="display:none">
                    <div class="bvk-aiva-scene-preview" id="bvk-fs-video-preview" style="display:none">
                        <video src="" muted playsinline style="max-width:100%;max-height:200px;border-radius:8px;"></video>
                        <button type="button" class="bvk-aiva-scene-clear" id="bvk-fs-video-clear" title="Xóa video">✕</button>
                    </div>
                    <div class="bvk-aiva-scene-placeholder" id="bvk-fs-video-placeholder">
                        <span>🎬</span>
                        <p>Upload video để face swap</p>
                        <small>MP4/MOV tối đa 100MB</small>
                    </div>
                </label>
                <div class="bvk-aiva-scene-progress" id="bvk-fs-video-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>
            </div>

            <!-- Source: URL -->
            <div class="bvk-fs-source-panel" id="bvk-fs-panel-url">
                <input type="text" id="bvk-fs-video-url-input" class="bvk-aiva-input" placeholder="https://... (URL video)" style="width:100%;">
            </div>

            <input type="hidden" id="bvk-fs-target-url" value="">
        </div>

        <!-- Submit -->
        <div class="bvk-aiva-cta">
            <button type="button" id="bvk-fs-submit" class="bvk-aiva-create-btn" disabled>
                <span>🎭</span> Bắt đầu Face Swap
            </button>
        </div>
        <div id="bvk-fs-status" class="bvk-status" style="margin-top:10px;"></div>
    </div>

    <!-- ═══ RIGHT PANEL: Results / Queue ═══ -->
    <div class="bvk-aiva-results">
        <div class="bvk-fs-empty" id="bvk-fs-empty">
            <div class="bvk-aiva-empty__icon">🎭</div>
            <h3>Chưa có kết quả</h3>
            <p>Video face swap sẽ hiển thị tại đây</p>
        </div>
        <div id="bvk-fs-jobs" class="bvk-aiva-jobs" style="display:none;"></div>
    </div>
</div>

<style>
/* Face Swap specific styles */
.bvk-fs-media-btn{background:transparent;color:#58a6ff;border:1px solid #30363d;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;transition:background .2s;}
.bvk-fs-media-btn:hover{background:#161b22;}

.bvk-fs-source-tabs{display:flex;gap:4px;margin-bottom:10px;}
.bvk-fs-source-tab{background:#0d1117;color:#8b949e;border:1px solid #30363d;border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;transition:all .2s;}
.bvk-fs-source-tab.active{background:#1f6feb;color:#fff;border-color:#388bfd;}

.bvk-fs-source-panel{display:none;}
.bvk-fs-source-panel.active{display:block;}

.bvk-fs-gallery-filter{display:flex;gap:8px;margin-bottom:10px;}
.bvk-fs-gallery-filter .bvk-aiva-input{flex:1;background:#0d1117;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:6px 10px;font-size:13px;}
.bvk-fs-gallery-filter .bvk-aiva-select{background:#0d1117;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:6px 8px;font-size:12px;}

.bvk-fs-gallery-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-height:320px;overflow-y:auto;padding-right:4px;}
.bvk-fs-gallery-grid::-webkit-scrollbar{width:4px;}
.bvk-fs-gallery-grid::-webkit-scrollbar-track{background:#0d1117;}
.bvk-fs-gallery-grid::-webkit-scrollbar-thumb{background:#30363d;border-radius:2px;}

.bvk-fs-gallery-item{position:relative;border:2px solid #30363d;border-radius:8px;overflow:hidden;cursor:pointer;transition:border-color .2s;aspect-ratio:9/16;}
.bvk-fs-gallery-item:hover{border-color:#58a6ff;}
.bvk-fs-gallery-item.selected{border-color:#3fb950;box-shadow:0 0 0 2px rgba(63,185,80,.3);}
.bvk-fs-gallery-item img,.bvk-fs-gallery-item video{width:100%;height:100%;object-fit:cover;}
.bvk-fs-gallery-item__title{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.8));padding:4px 6px;font-size:10px;color:#e6edf3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bvk-fs-gallery-item__badge{position:absolute;top:4px;right:4px;font-size:9px;font-weight:700;padding:1px 6px;border-radius:4px;color:#fff;}

.bvk-fs-gallery-loading{grid-column:1/-1;text-align:center;padding:30px;color:#8b949e;font-size:13px;}
.bvk-fs-gallery-empty{grid-column:1/-1;text-align:center;padding:20px;color:#484f58;font-size:12px;}

/* Job cards in face swap results */
.bvk-fs-job{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:12px;margin-bottom:10px;}
.bvk-fs-job__top{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.bvk-fs-job__status{font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;}
.bvk-fs-job__status.pending{background:rgba(210,153,34,.15);color:#d29922;}
.bvk-fs-job__status.processing{background:rgba(31,111,235,.15);color:#58a6ff;}
.bvk-fs-job__status.completed{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-fs-job__status.failed{background:rgba(218,54,51,.15);color:#f85149;}
.bvk-fs-job__time{font-size:11px;color:#484f58;margin-left:auto;}
.bvk-fs-job__preview{margin:8px 0;border-radius:8px;overflow:hidden;}
.bvk-fs-job__preview video{width:100%;max-height:300px;display:block;background:#000;}
.bvk-fs-job__actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.bvk-fs-job__actions button,.bvk-fs-job__actions a{background:#21262d;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;text-decoration:none;transition:background .2s;}
.bvk-fs-job__actions button:hover,.bvk-fs-job__actions a:hover{background:#30363d;}
.bvk-fs-job__error{color:#f85149;font-size:12px;margin-top:6px;padding:6px 10px;background:rgba(218,54,51,.1);border-radius:6px;}
</style>

<script>
(function(){
    'use strict';

    /* ── References ── */
    var faceUrl    = document.getElementById('bvk-fs-face-url');
    var targetUrl  = document.getElementById('bvk-fs-target-url');
    var submitBtn  = document.getElementById('bvk-fs-submit');
    var statusEl   = document.getElementById('bvk-fs-status');
    var jobsEl     = document.getElementById('bvk-fs-jobs');
    var emptyEl    = document.getElementById('bvk-fs-empty');
    var galleryGrid = document.getElementById('bvk-fs-gallery-grid');

    var fsJobs     = []; // { taskId, mode, status, progress, resultUrl, createdAt }
    var pollTimer  = null;
    var isSubmitting = false;

    /* ── BVK config (inherited from profile page) ── */
    /* BVK.ajax_url and BVK.nonce are set in the parent script block */

    /* ── Validate button state ── */
    function updateSubmitBtn() {
        if (submitBtn) submitBtn.disabled = !faceUrl.value || !targetUrl.value || isSubmitting;
    }

    /* ── Status helper ── */
    function showStatus(msg, type) {
        if (!statusEl) return;
        statusEl.className = 'bvk-status';
        if (msg && type) { statusEl.textContent = msg; statusEl.classList.add(type); }
    }

    /* ════════════════════════════════════════
     *  ① FACE IMAGE UPLOAD
     * ════════════════════════════════════════ */
    var faceFile     = document.getElementById('bvk-fs-face-file');
    var faceDropzone = document.getElementById('bvk-fs-face-dropzone');
    var facePreview  = document.getElementById('bvk-fs-face-preview');
    var facePlaceholder = document.getElementById('bvk-fs-face-placeholder');
    var faceProgress = document.getElementById('bvk-fs-face-progress');
    var faceClearBtn = document.getElementById('bvk-fs-face-clear');
    var faceMediaBtn = document.getElementById('bvk-fs-face-media-btn');

    if (faceFile) {
        faceFile.addEventListener('change', function() {
            if (faceFile.files[0]) uploadFaceImage(faceFile.files[0]);
        });
    }
    if (faceDropzone) {
        faceDropzone.addEventListener('dragover', function(e) { e.preventDefault(); faceDropzone.classList.add('dragover'); });
        faceDropzone.addEventListener('dragleave', function() { faceDropzone.classList.remove('dragover'); });
        faceDropzone.addEventListener('drop', function(e) {
            e.preventDefault();
            faceDropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) uploadFaceImage(e.dataTransfer.files[0]);
        });
    }
    if (faceClearBtn) {
        faceClearBtn.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            faceUrl.value = '';
            facePreview.style.display = 'none';
            facePlaceholder.style.display = '';
            if (faceFile) faceFile.value = '';
            updateSubmitBtn();
        });
    }
    if (faceMediaBtn) {
        faceMediaBtn.addEventListener('click', function() {
            if (typeof wp === 'undefined' || !wp.media) { alert('WP Media Library không khả dụng.'); return; }
            var frame = wp.media({ title: 'Chọn ảnh khuôn mặt', multiple: false, library: { type: 'image' } });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                faceUrl.value = attachment.url;
                facePreview.querySelector('img').src = attachment.url;
                facePreview.style.display = '';
                facePlaceholder.style.display = 'none';
                updateSubmitBtn();
            });
            frame.open();
        });
    }

    function uploadFaceImage(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            facePreview.querySelector('img').src = ev.target.result;
            facePreview.style.display = '';
            facePlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);

        var fd = new FormData();
        fd.append('action', 'bvk_upload_photo');
        fd.append('nonce', BVK.nonce);
        fd.append('photo', file);
        var bar = faceProgress.querySelector('.bvk-aiva-scene-progress-bar');
        faceProgress.classList.add('active');
        bar.style.width = '30%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.upload.onprogress = function(ev) { if (ev.lengthComputable) bar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%'; };
        xhr.onload = function() {
            bar.style.width = '100%';
            setTimeout(function() { faceProgress.classList.remove('active'); bar.style.width = '0'; }, 600);
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) {
                    faceUrl.value = res.data.url;
                    updateSubmitBtn();
                } else {
                    showStatus('Upload ảnh thất bại: ' + (res.data && res.data.message || 'Lỗi'), 'error');
                    facePreview.style.display = 'none'; facePlaceholder.style.display = '';
                }
            } catch(e) { showStatus('Upload ảnh thất bại.', 'error'); }
        };
        xhr.onerror = function() { faceProgress.classList.remove('active'); showStatus('Lỗi kết nối.', 'error'); };
        xhr.send(fd);
    }

    /* ════════════════════════════════════════
     *  ② VIDEO SOURCE TABS
     * ════════════════════════════════════════ */
    document.querySelectorAll('.bvk-fs-source-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.bvk-fs-source-tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.bvk-fs-source-panel').forEach(function(p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var panel = document.getElementById('bvk-fs-panel-' + tab.dataset.source);
            if (panel) panel.classList.add('active');
        });
    });

    /* ── Gallery: Load templates ── */
    function loadGallery(search, category) {
        galleryGrid.innerHTML = '<div class="bvk-fs-gallery-loading">⏳ Đang tải templates...</div>';
        var fd = new FormData();
        fd.append('action', 'bvk_faceswap_gallery');
        fd.append('nonce', BVK.nonce);
        fd.append('search', search || '');
        fd.append('category', category || '');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data) {
                    renderGallery(res.data.templates || [], res.data.categories || []);
                } else {
                    galleryGrid.innerHTML = '<div class="bvk-fs-gallery-empty">Không tải được templates</div>';
                }
            } catch(e) { galleryGrid.innerHTML = '<div class="bvk-fs-gallery-empty">Lỗi</div>'; }
        };
        xhr.onerror = function() { galleryGrid.innerHTML = '<div class="bvk-fs-gallery-empty">Lỗi kết nối</div>'; };
        xhr.send(fd);
    }

    function renderGallery(templates, categories) {
        /* Populate category filter */
        var catSelect = document.getElementById('bvk-fs-gallery-cat');
        if (catSelect && categories.length > 0) {
            var current = catSelect.value;
            catSelect.innerHTML = '<option value="">Tất cả</option>';
            categories.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c; opt.textContent = c;
                if (c === current) opt.selected = true;
                catSelect.appendChild(opt);
            });
        }

        if (templates.length === 0) {
            galleryGrid.innerHTML = '<div class="bvk-fs-gallery-empty">Chưa có template nào</div>';
            return;
        }

        galleryGrid.innerHTML = '';
        templates.forEach(function(t) {
            var item = document.createElement('div');
            item.className = 'bvk-fs-gallery-item';
            if (t.preview_video_url === targetUrl.value && targetUrl.value) item.classList.add('selected');
            item.dataset.videoUrl = t.preview_video_url || '';
            item.dataset.title = t.title || '';

            var media = '';
            if (t.preview_video_url) {
                media = '<video src="' + escAttr(t.preview_video_url) + '" muted playsinline preload="metadata"></video>';
            } else if (t.thumbnail_url) {
                media = '<img src="' + escAttr(t.thumbnail_url) + '" alt="' + escAttr(t.title) + '" loading="lazy">';
            } else {
                media = '<div style="width:100%;height:100%;background:#21262d;display:flex;align-items:center;justify-content:center;font-size:24px;">🎬</div>';
            }

            var badge = '';
            if (t.badge) {
                badge = '<span class="bvk-fs-gallery-item__badge" style="background:' + escAttr(t.badge_color || '#3b82f6') + '">' + escHtml(t.badge) + '</span>';
            }

            item.innerHTML = media + badge + '<span class="bvk-fs-gallery-item__title">' + escHtml(t.title) + '</span>';

            /* Hover play */
            item.addEventListener('mouseenter', function() {
                var v = item.querySelector('video');
                if (v) v.play().catch(function(){});
            });
            item.addEventListener('mouseleave', function() {
                var v = item.querySelector('video');
                if (v) { v.pause(); v.currentTime = 0; }
            });

            /* Select */
            item.addEventListener('click', function() {
                document.querySelectorAll('.bvk-fs-gallery-item').forEach(function(g) { g.classList.remove('selected'); });
                item.classList.add('selected');
                targetUrl.value = item.dataset.videoUrl;
                updateSubmitBtn();
            });

            galleryGrid.appendChild(item);
        });
    }

    /* Gallery search & filter */
    var searchTimeout = null;
    var searchInput = document.getElementById('bvk-fs-gallery-search');
    var catSelect   = document.getElementById('bvk-fs-gallery-cat');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { loadGallery(searchInput.value, catSelect ? catSelect.value : ''); }, 400);
        });
    }
    if (catSelect) {
        catSelect.addEventListener('change', function() { loadGallery(searchInput ? searchInput.value : '', catSelect.value); });
    }

    /* Load gallery on init */
    loadGallery();

    /* ── Upload video ── */
    var videoFile    = document.getElementById('bvk-fs-video-file');
    var videoDropzone = document.getElementById('bvk-fs-video-dropzone');
    var videoPreview = document.getElementById('bvk-fs-video-preview');
    var videoPlaceholder = document.getElementById('bvk-fs-video-placeholder');
    var videoProgress = document.getElementById('bvk-fs-video-progress');
    var videoClearBtn = document.getElementById('bvk-fs-video-clear');

    if (videoFile) {
        videoFile.addEventListener('change', function() { if (videoFile.files[0]) uploadVideo(videoFile.files[0]); });
    }
    if (videoDropzone) {
        videoDropzone.addEventListener('dragover', function(e) { e.preventDefault(); videoDropzone.classList.add('dragover'); });
        videoDropzone.addEventListener('dragleave', function() { videoDropzone.classList.remove('dragover'); });
        videoDropzone.addEventListener('drop', function(e) {
            e.preventDefault(); videoDropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) uploadVideo(e.dataTransfer.files[0]);
        });
    }
    if (videoClearBtn) {
        videoClearBtn.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            targetUrl.value = '';
            videoPreview.style.display = 'none'; videoPlaceholder.style.display = '';
            if (videoFile) videoFile.value = '';
            updateSubmitBtn();
        });
    }

    function uploadVideo(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            videoPreview.querySelector('video').src = ev.target.result;
            videoPreview.style.display = '';
            videoPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);

        var fd = new FormData();
        fd.append('action', 'bvk_tc_upload_file');
        fd.append('nonce', BVK.nonce);
        fd.append('payload', JSON.stringify({}));
        fd.append('file', file);
        var bar = videoProgress.querySelector('.bvk-aiva-scene-progress-bar');
        videoProgress.classList.add('active');
        bar.style.width = '10%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.upload.onprogress = function(ev) { if (ev.lengthComputable) bar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%'; };
        xhr.onload = function() {
            bar.style.width = '100%';
            setTimeout(function() { videoProgress.classList.remove('active'); bar.style.width = '0'; }, 600);
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) {
                    targetUrl.value = res.data.url;
                    updateSubmitBtn();
                } else {
                    showStatus('Upload video thất bại: ' + (res.data && res.data.message || 'Lỗi'), 'error');
                    videoPreview.style.display = 'none'; videoPlaceholder.style.display = '';
                }
            } catch(e) { showStatus('Upload video thất bại.', 'error'); }
        };
        xhr.onerror = function() { videoProgress.classList.remove('active'); showStatus('Lỗi kết nối.', 'error'); };
        xhr.send(fd);
    }

    /* ── URL paste ── */
    var urlInput = document.getElementById('bvk-fs-video-url-input');
    if (urlInput) {
        urlInput.addEventListener('input', function() {
            targetUrl.value = urlInput.value.trim();
            updateSubmitBtn();
        });
    }

    /* ════════════════════════════════════════
     *  ③ SUBMIT FACE SWAP
     * ════════════════════════════════════════ */
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            if (isSubmitting || submitBtn.disabled) return;
            if (!faceUrl.value || !targetUrl.value) { showStatus('Vui lòng chọn ảnh khuôn mặt và video mẫu.', 'error'); return; }

            isSubmitting = true;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>⏳</span> Đang gửi...';
            showStatus('Đang gửi yêu cầu Face Swap...', 'loading');

            var fd = new FormData();
            fd.append('action', 'bvk_tc_faceswap');
            fd.append('nonce', BVK.nonce);
            fd.append('payload', JSON.stringify({
                mode: 'video',
                swap_image: faceUrl.value,
                target_video: targetUrl.value
            }));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                isSubmitting = false;
                submitBtn.innerHTML = '<span>🎭</span> Bắt đầu Face Swap';
                updateSubmitBtn();
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.taskId) {
                        showStatus('Đã gửi thành công! Đang xử lý...', 'success');
                        addJob(res.data.taskId);
                        startPolling();
                    } else {
                        showStatus('Lỗi: ' + (res.data && res.data.message || 'Face swap thất bại'), 'error');
                    }
                } catch(e) { showStatus('Server error.', 'error'); }
            };
            xhr.onerror = function() {
                isSubmitting = false;
                submitBtn.innerHTML = '<span>🎭</span> Bắt đầu Face Swap';
                updateSubmitBtn();
                showStatus('Lỗi kết nối.', 'error');
            };
            xhr.send(fd);
        });
    }

    /* ════════════════════════════════════════
     *  ④ JOB QUEUE & POLLING
     * ════════════════════════════════════════ */
    function addJob(taskId) {
        fsJobs.unshift({
            taskId: taskId,
            status: 'pending',
            progress: 0,
            resultUrl: null,
            createdAt: new Date().toLocaleTimeString('vi-VN')
        });
        renderJobs();
    }

    function renderJobs() {
        if (fsJobs.length === 0) {
            emptyEl.style.display = '';
            jobsEl.style.display = 'none';
            return;
        }
        emptyEl.style.display = 'none';
        jobsEl.style.display = '';

        jobsEl.innerHTML = '';
        fsJobs.forEach(function(job, idx) {
            var statusLabel = { pending: '⏳ Đang chờ', processing: '🔄 Đang xử lý', completed: '✅ Hoàn thành', failed: '❌ Thất bại' };
            var card = document.createElement('div');
            card.className = 'bvk-fs-job';

            var html = '<div class="bvk-fs-job__top">' +
                '<span class="bvk-fs-job__status ' + job.status + '">' + (statusLabel[job.status] || job.status) + '</span>' +
                '<span class="bvk-fs-job__time">' + escHtml(job.createdAt) + '</span></div>';

            if (job.status === 'processing' || job.status === 'pending') {
                html += '<div class="bvk-progress"><div class="bvk-progress-bar" style="width:' + (job.progress || 5) + '%"></div></div>';
            }

            if (job.status === 'completed' && job.resultUrl) {
                html += '<div class="bvk-fs-job__preview"><video src="' + escAttr(job.resultUrl) + '" controls muted playsinline preload="metadata"></video></div>';
                html += '<div class="bvk-fs-job__actions">' +
                    '<button type="button" onclick="bvkFsSaveMedia(' + idx + ')">📥 Lưu vào Media</button>' +
                    '<a href="' + escAttr(job.resultUrl) + '" target="_blank">▶ Xem</a>' +
                    '<button type="button" onclick="bvkFsCopyLink(\'' + escAttr(job.resultUrl) + '\', this)">🔗 Copy</button>' +
                    '<button type="button" style="background:#1f6feb;color:#fff;border-color:#388bfd;" onclick="bvkFsSendToEditor(\'' + escAttr(job.resultUrl) + '\')">🎞️ Editor</button>' +
                    '</div>';
            }

            if (job.status === 'failed') {
                html += '<div class="bvk-fs-job__error">' + escHtml(job.error || 'Face swap thất bại. Vui lòng thử lại.') + '</div>';
                html += '<div class="bvk-fs-job__actions"><button type="button" onclick="bvkFsRetry(' + idx + ')">🔄 Thử lại</button></div>';
            }

            card.innerHTML = html;
            jobsEl.appendChild(card);
        });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(pollFsJobs, 8000);
    }

    function pollFsJobs() {
        var hasActive = false;
        fsJobs.forEach(function(job) {
            if (job.status === 'pending' || job.status === 'processing') {
                hasActive = true;
                pollSingleJob(job);
            }
        });
        if (!hasActive && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function pollSingleJob(job) {
        var fd = new FormData();
        fd.append('action', 'bvk_tc_faceswap_status');
        fd.append('nonce', BVK.nonce);
        fd.append('payload', JSON.stringify({ taskId: job.taskId }));
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data) {
                    job.status = res.data.status || job.status;
                    job.progress = res.data.progress || job.progress;
                    if (res.data.resultUrl) job.resultUrl = res.data.resultUrl;
                    renderJobs();
                } else if (!res.success && res.data && res.data.status === 'failed') {
                    job.status = 'failed';
                    job.error = res.data.message || '';
                    renderJobs();
                }
            } catch(e) {}
        };
        xhr.send(fd);
    }

    /* ── Global action handlers ── */
    window.bvkFsSaveMedia = function(idx) {
        var job = fsJobs[idx];
        if (!job || !job.resultUrl) return;
        var fd = new FormData();
        fd.append('action', 'bvk_tc_save_url_to_media');
        fd.append('nonce', BVK.nonce);
        fd.append('url', job.resultUrl);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    job.mediaUrl = res.data.url;
                    showStatus('✅ Đã lưu vào Media Library!', 'success');
                    renderJobs();
                } else {
                    showStatus('Lưu Media thất bại: ' + (res.data && res.data.message || ''), 'error');
                }
            } catch(e) { showStatus('Lỗi lưu Media.', 'error'); }
        };
        xhr.send(fd);
    };

    window.bvkFsCopyLink = function(url, btn) {
        navigator.clipboard.writeText(url).then(function() {
            var orig = btn.textContent;
            btn.textContent = '✅ Copied!';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    };

    window.bvkFsSendToEditor = function(url) {
        /* Switch to Editor tab and postMessage */
        var editorNav = document.querySelector('.bvk-nav-item[data-tab="editor"]');
        if (editorNav) editorNav.click();
        setTimeout(function() {
            var editorFrame = document.getElementById('bvk-editor-frame');
            if (editorFrame && editorFrame.contentWindow) {
                editorFrame.contentWindow.postMessage({ type: 'bvk:add-videos', videos: [url] }, '*');
            }
        }, 500);
    };

    window.bvkFsRetry = function(idx) {
        var job = fsJobs[idx];
        if (!job) return;
        /* Remove failed job and let user re-submit */
        fsJobs.splice(idx, 1);
        renderJobs();
        showStatus('Đã xóa job lỗi. Nhấn "Bắt đầu Face Swap" để thử lại.', 'info');
    };

    /* ── Helpers ── */
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

})();
</script>
