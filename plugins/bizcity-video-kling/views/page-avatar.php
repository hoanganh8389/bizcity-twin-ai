<?php
/**
 * AI Avatar LipSync Studio — Standalone full-page
 * Two-panel AIVA layout: Portrait + Audio → Video results
 *
 * Included by class-avatar-page.php via template_redirect.
 *
 * @package BizCity_Video_Kling
 * @since   2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user    = wp_get_current_user();
$nonce   = wp_create_nonce( 'bvk_nonce' );
$ajaxurl = admin_url( 'admin-ajax.php' );
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Avatar LipSync Studio — BizCity</title>
<style>
/* ═══ Reset & Base ═══ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;font-family:'Inter',system-ui,-apple-system,sans-serif;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.5;}
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:#0d1117;}
::-webkit-scrollbar-thumb{background:#30363d;border-radius:3px;}
a{color:#58a6ff;text-decoration:none;}

/* ═══ AIVA Two-Panel Layout ═══ */
.bvk-aiva{display:flex;height:100vh;overflow:hidden;}
.bvk-aiva-form{width:400px;min-width:360px;max-width:420px;background:#161b22;border-right:1px solid #30363d;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;}
.bvk-aiva-results{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;}

.bvk-aiva-header{display:flex;align-items:center;gap:10px;margin-bottom:4px;}
.bvk-aiva-header__icon{font-size:28px;}
.bvk-aiva-header__title{font-size:18px;font-weight:700;color:#e6edf3;}
.bvk-aiva-header__sub{font-size:12px;color:#8b949e;margin-left:auto;}

.bvk-aiva-group{display:flex;flex-direction:column;gap:6px;}
.bvk-aiva-label{font-size:13px;font-weight:600;color:#c9d1d9;}

/* Dropzone */
.bvk-aiva-dropzone{border:2px dashed #30363d;border-radius:10px;cursor:pointer;padding:0;overflow:hidden;transition:border-color .2s;position:relative;display:block;}
.bvk-aiva-dropzone:hover,.bvk-aiva-dropzone.dragover{border-color:#58a6ff;}
.bvk-aiva-scene-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 12px;text-align:center;}
.bvk-aiva-scene-placeholder span{font-size:36px;margin-bottom:6px;}
.bvk-aiva-scene-placeholder p{font-size:13px;font-weight:600;color:#c9d1d9;}
.bvk-aiva-scene-placeholder small{font-size:11px;color:#8b949e;}
.bvk-aiva-scene-preview{position:relative;display:flex;align-items:center;justify-content:center;min-height:160px;background:#0d1117;}
.bvk-aiva-scene-preview img{max-width:100%;max-height:240px;object-fit:contain;}
.bvk-aiva-scene-clear{position:absolute;top:6px;right:6px;width:24px;height:24px;background:rgba(0,0,0,.6);color:#e6edf3;border:none;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.bvk-aiva-scene-progress{height:3px;background:#21262d;border-radius:2px;overflow:hidden;margin-top:4px;opacity:0;transition:opacity .2s;}
.bvk-aiva-scene-progress.active{opacity:1;}
.bvk-aiva-scene-progress-bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);width:0;transition:width .3s;}

/* Inputs & Selects */
.bvk-aiva-input,.bvk-aiva-select,.bvk-aiva-textarea{background:#0d1117;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:8px 10px;font-size:13px;font-family:inherit;width:100%;transition:border-color .2s;}
.bvk-aiva-input:focus,.bvk-aiva-select:focus,.bvk-aiva-textarea:focus{outline:none;border-color:#58a6ff;}
.bvk-aiva-textarea{resize:vertical;min-height:80px;}
.bvk-aiva-select{cursor:pointer;}

/* Tabs */
.bvk-av-tabs{display:flex;gap:4px;margin-bottom:10px;}
.bvk-av-tab{background:#0d1117;color:#8b949e;border:1px solid #30363d;border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;transition:all .2s;border:1px solid #30363d;}
.bvk-av-tab.active{background:#1f6feb;color:#fff;border-color:#388bfd;}
.bvk-av-panel{display:none;}
.bvk-av-panel.active{display:block;}

/* Recorder */
.bvk-av-recorder{display:flex;flex-direction:column;gap:8px;padding:12px;background:#0d1117;border-radius:8px;}
.bvk-av-recorder__controls{display:flex;align-items:center;gap:8px;}
.bvk-av-recorder__btn{width:40px;height:40px;border-radius:50%;border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;}
.bvk-av-recorder__btn.rec{background:#da3633;color:#fff;}
.bvk-av-recorder__btn.rec:hover{background:#f85149;}
.bvk-av-recorder__btn.rec.recording{animation:pulse 1s infinite;}
.bvk-av-recorder__btn.stop{background:#30363d;color:#e6edf3;}
.bvk-av-recorder__btn.stop:hover{background:#484f58;}
.bvk-av-recorder__time{font-size:14px;color:#e6edf3;font-variant-numeric:tabular-nums;min-width:48px;text-align:center;}
.bvk-av-recorder__max{font-size:11px;color:#484f58;}
.bvk-av-recorder__wave{flex:1;height:36px;background:#161b22;border-radius:4px;overflow:hidden;position:relative;}
.bvk-av-recorder__wave canvas{width:100%;height:100%;display:block;}
.bvk-av-recorder__hint{font-size:11px;color:#8b949e;text-align:center;padding:2px 0;}
.bvk-av-recorder__hint.warn{color:#d29922;}
.bvk-av-recorder__hint.err{color:#f85149;}
.bvk-av-recorder__bar{height:3px;background:#21262d;border-radius:2px;overflow:hidden;}
.bvk-av-recorder__bar-fill{height:100%;background:linear-gradient(90deg,#3fb950,#d29922,#f85149);width:0;transition:width .3s;}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(218,54,51,.4);}50%{box-shadow:0 0 0 8px rgba(218,54,51,0);}}

/* Audio preview (re-used for TTS result or uploaded file) */
.bvk-av-audio-preview{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#0d1117;border-radius:8px;margin-top:6px;}
.bvk-av-audio-preview audio{flex:1;height:32px;}
.bvk-av-audio-preview .clear-audio{background:transparent;border:none;color:#f85149;cursor:pointer;font-size:14px;}

/* CTA */
.bvk-aiva-cta{margin-top:auto;padding-top:12px;}
.bvk-aiva-create-btn{width:100%;padding:12px 20px;background:linear-gradient(135deg,#238636,#2ea043);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .2s;}
.bvk-aiva-create-btn:disabled{opacity:.4;cursor:not-allowed;}
.bvk-aiva-create-btn:not(:disabled):hover{opacity:.9;}

/* Settings row */
.bvk-av-settings{display:grid;grid-template-columns:1fr 1fr;gap:8px;}

/* Status */
.bvk-status{font-size:12px;padding:6px 10px;border-radius:6px;display:none;}
.bvk-status.error{display:block;background:rgba(218,54,51,.1);color:#f85149;}
.bvk-status.success{display:block;background:rgba(63,185,80,.1);color:#3fb950;}
.bvk-status.loading{display:block;background:rgba(31,111,235,.1);color:#58a6ff;}
.bvk-status.info{display:block;background:rgba(210,153,34,.1);color:#d29922;}

/* Progress bar */
.bvk-progress{height:4px;background:#21262d;border-radius:2px;overflow:hidden;margin:6px 0;}
.bvk-progress-bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);transition:width .4s;}

/* ═══ Empty state ═══ */
.bvk-aiva-empty__icon{font-size:56px;margin-bottom:12px;}
.bvk-av-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;text-align:center;color:#484f58;}
.bvk-av-empty h3{font-size:18px;color:#8b949e;margin-bottom:4px;}
.bvk-av-empty p{font-size:13px;}

/* ═══ Job cards ═══ */
.bvk-av-jobs{display:flex;flex-direction:column;gap:10px;}
.bvk-av-job{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:12px;}
.bvk-av-job__top{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.bvk-av-job__status{font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;}
.bvk-av-job__status.pending{background:rgba(210,153,34,.15);color:#d29922;}
.bvk-av-job__status.processing{background:rgba(31,111,235,.15);color:#58a6ff;}
.bvk-av-job__status.completed{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-av-job__status.failed{background:rgba(218,54,51,.15);color:#f85149;}
.bvk-av-job__model{font-size:10px;color:#8b949e;padding:2px 6px;background:#21262d;border-radius:4px;}
.bvk-av-job__time{font-size:11px;color:#484f58;margin-left:auto;}
.bvk-av-job__preview{margin:8px 0;border-radius:8px;overflow:hidden;}
.bvk-av-job__preview video{width:100%;max-height:360px;display:block;background:#000;}
.bvk-av-job__actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.bvk-av-job__actions button,.bvk-av-job__actions a{background:#21262d;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;text-decoration:none;transition:background .2s;}
.bvk-av-job__actions button:hover,.bvk-av-job__actions a:hover{background:#30363d;}
.bvk-av-job__error{color:#f85149;font-size:12px;margin-top:6px;padding:6px 10px;background:rgba(218,54,51,.1);border-radius:6px;}

/* ═══ Media btn ═══ */
.bvk-av-media-btn{background:transparent;color:#58a6ff;border:1px solid #30363d;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;transition:background .2s;}
.bvk-av-media-btn:hover{background:#161b22;}

/* ═══ TTS generate button ═══ */
.bvk-av-tts-btn{padding:8px 16px;background:#1f6feb;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s;}
.bvk-av-tts-btn:disabled{opacity:.4;cursor:not-allowed;}

/* ═══ Console ═══ */
.bvk-console{background:#0d1117;border:1px solid #21262d;border-radius:8px;padding:10px 14px;font-size:11px;font-family:'Consolas','Monaco','Courier New',monospace;max-height:150px;overflow-y:auto;margin-bottom:14px;line-height:1.7;flex-shrink:0;}
.bvk-log-line{display:block;}
.bvk-log-time{color:#484f58;}
.bvk-log-info{color:#8b949e;}
.bvk-log-success{color:#3fb950;}
.bvk-log-error{color:#f85149;}
.bvk-log-warn{color:#d29922;}
.bvk-log-proc{color:#58a6ff;}

/* Responsive */
@media(max-width:768px){
    .bvk-aiva{flex-direction:column;height:auto;min-height:100vh;}
    .bvk-aiva-form{width:100%;min-width:unset;max-width:unset;max-height:55vh;border-right:none;border-bottom:1px solid #30363d;}
    .bvk-aiva-results{min-height:45vh;}
}
</style>
</head>
<body>

<div class="bvk-aiva bvk-avatar">
    <!-- ═══ LEFT PANEL: Form ═══ -->
    <div class="bvk-aiva-form">
        <div class="bvk-aiva-header">
            <span class="bvk-aiva-header__icon">🧑‍🎤</span>
            <p class="bvk-aiva-header__title">AI Avatar LipSync</p>
            <span class="bvk-aiva-header__sub"><?php echo esc_html( $user->display_name ); ?></span>
        </div>

        <!-- ① Ảnh chân dung -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">① Ảnh chân dung</label>
            <label class="bvk-aiva-dropzone" id="bvk-av-portrait-dropzone">
                <input type="file" accept="image/*" id="bvk-av-portrait-file" style="display:none">
                <div class="bvk-aiva-scene-preview" id="bvk-av-portrait-preview" style="display:none">
                    <img src="" alt="Portrait">
                    <button type="button" class="bvk-aiva-scene-clear" id="bvk-av-portrait-clear" title="Xóa ảnh">✕</button>
                </div>
                <div class="bvk-aiva-scene-placeholder" id="bvk-av-portrait-placeholder">
                    <span>🧑</span>
                    <p>Upload ảnh chân dung</p>
                    <small>JPG/PNG — Chính diện, rõ nét, nền đơn giản</small>
                </div>
            </label>
            <input type="hidden" id="bvk-av-portrait-url" value="">
            <div class="bvk-aiva-scene-progress" id="bvk-av-portrait-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>
            <div style="margin-top:6px;">
                <button type="button" id="bvk-av-portrait-media-btn" class="bvk-av-media-btn">📁 Chọn từ Media Library</button>
            </div>
        </div>

        <!-- ② Nguồn âm thanh -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">② Âm thanh (Audio)</label>

            <div class="bvk-av-tabs">
                <button type="button" class="bvk-av-tab active" data-panel="tts">🗣️ TTS</button>
                <button type="button" class="bvk-av-tab" data-panel="upload">📤 Upload</button>
                <button type="button" class="bvk-av-tab" data-panel="record">🎙️ Ghi âm</button>
            </div>

            <!-- Panel: TTS -->
            <div class="bvk-av-panel active" id="bvk-av-panel-tts">
                <textarea id="bvk-av-tts-text" class="bvk-aiva-textarea" placeholder="Nhập nội dung cần đọc..." rows="4"></textarea>
                <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                    <select id="bvk-av-tts-voice" class="bvk-aiva-select" style="flex:1;">
                        <option value="nova">Nova (nữ)</option>
                        <option value="alloy">Alloy (trung tính)</option>
                        <option value="echo">Echo (nam)</option>
                        <option value="fable">Fable (nam UK)</option>
                        <option value="onyx">Onyx (nam trầm)</option>
                        <option value="shimmer">Shimmer (nữ ấm)</option>
                    </select>
                    <button type="button" id="bvk-av-tts-btn" class="bvk-av-tts-btn">🔊 Tạo audio</button>
                </div>
                <div id="bvk-av-tts-status" class="bvk-status" style="margin-top:6px;"></div>
            </div>

            <!-- Panel: Upload audio -->
            <div class="bvk-av-panel" id="bvk-av-panel-upload">
                <label class="bvk-aiva-dropzone" id="bvk-av-audio-dropzone">
                    <input type="file" accept="audio/*" id="bvk-av-audio-file" style="display:none">
                    <div class="bvk-aiva-scene-placeholder" id="bvk-av-audio-placeholder">
                        <span>🎵</span>
                        <p>Upload file âm thanh</p>
                        <small>MP3/WAV/M4A — tối đa 20MB</small>
                    </div>
                </label>
                <div class="bvk-aiva-scene-progress" id="bvk-av-audio-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>
            </div>

            <!-- Panel: Record -->
            <div class="bvk-av-panel" id="bvk-av-panel-record">
                <div class="bvk-av-recorder" id="bvk-av-recorder">
                    <div class="bvk-av-recorder__controls">
                        <button type="button" class="bvk-av-recorder__btn rec" id="bvk-av-rec-btn" title="Bắt đầu ghi âm">🎙️</button>
                        <button type="button" class="bvk-av-recorder__btn stop" id="bvk-av-rec-stop" title="Dừng ghi âm" style="display:none">⏹</button>
                        <div class="bvk-av-recorder__wave" id="bvk-av-rec-wave"><canvas id="bvk-av-rec-canvas"></canvas></div>
                        <span class="bvk-av-recorder__time" id="bvk-av-rec-time">0:00</span>
                        <span class="bvk-av-recorder__max">/0:35</span>
                    </div>
                    <div class="bvk-av-recorder__bar"><div class="bvk-av-recorder__bar-fill" id="bvk-av-rec-bar"></div></div>
                    <div class="bvk-av-recorder__hint" id="bvk-av-rec-hint">Nhấn 🎙️ để bắt đầu ghi âm (tối đa 35 giây)</div>
                </div>
            </div>

            <!-- Audio preview (shown after TTS / upload / record) -->
            <div class="bvk-av-audio-preview" id="bvk-av-audio-preview" style="display:none;">
                <audio id="bvk-av-audio-player" controls preload="metadata"></audio>
                <button type="button" class="clear-audio" id="bvk-av-audio-clear" title="Xóa audio">✕</button>
            </div>
            <input type="hidden" id="bvk-av-audio-url" value="">
        </div>

        <!-- ③ Cài đặt -->
        <div class="bvk-aiva-group">
            <label class="bvk-aiva-label">③ Cài đặt</label>
            <div class="bvk-av-settings">
                <div>
                    <label style="font-size:11px;color:#8b949e;">Model</label>
                    <select id="bvk-av-model" class="bvk-aiva-select">
                        <option value="kling-avatar-std" selected>Kling Avatar (std)</option>
                        <option value="kling-avatar-pro">Kling Avatar (pro)</option>
                        <option value="omni-human">OmniHuman 1.5</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;color:#8b949e;">Thời lượng</label>
                    <select id="bvk-av-duration" class="bvk-aiva-select">
                        <option value="5">5 giây</option>
                        <option value="10" selected>10 giây</option>
                        <option value="15">15 giây</option>
                        <option value="20">20 giây</option>
                        <option value="30">30 giây</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="bvk-aiva-cta">
            <button type="button" id="bvk-av-submit" class="bvk-aiva-create-btn" disabled>
                <span>🧑‍🎤</span> Tạo Avatar Video
            </button>
        </div>
        <div id="bvk-av-status" class="bvk-status" style="margin-top:6px;"></div>
    </div>

    <!-- ═══ RIGHT PANEL: Results ═══ -->
    <div class="bvk-aiva-results">
        <!-- Console log -->
        <div class="bvk-console" id="bvk-console">
            <span class="bvk-log-line"><span class="bvk-log-time">[--:--:--]</span> <span class="bvk-log-info">Avatar Studio khởi tạo. Sẵn sàng.</span></span>
        </div>
        <div class="bvk-av-empty" id="bvk-av-empty">
            <div class="bvk-aiva-empty__icon">🧑‍🎤</div>
            <h3>Chưa có kết quả</h3>
            <p>Video avatar sẽ hiển thị tại đây</p>
        </div>
        <div id="bvk-av-jobs" class="bvk-av-jobs" style="display:none;"></div>
    </div>
</div>

<script>
(function(){
    'use strict';

    /* ── Config ── */
    var BVK = {
        ajax_url: <?php echo wp_json_encode( $ajaxurl ); ?>,
        nonce:    <?php echo wp_json_encode( $nonce ); ?>
    };

    /* ── DOM refs ── */
    var portraitUrl  = document.getElementById('bvk-av-portrait-url');
    var audioUrl     = document.getElementById('bvk-av-audio-url');
    var submitBtn    = document.getElementById('bvk-av-submit');
    var statusEl     = document.getElementById('bvk-av-status');
    var jobsEl       = document.getElementById('bvk-av-jobs');
    var emptyEl      = document.getElementById('bvk-av-empty');
    var audioPlayer  = document.getElementById('bvk-av-audio-player');
    var audioPreview = document.getElementById('bvk-av-audio-preview');

    var avJobs    = [];
    var pollTimer = null;
    var isSubmitting = false;

    /* ── Console ── */
    var consoleEl = document.getElementById('bvk-console');
    function bvkLog(msg, type) {
        if (!consoleEl) return;
        var now = new Date().toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        var line = document.createElement('span');
        line.className = 'bvk-log-line';
        line.innerHTML = '<span class="bvk-log-time">[' + now + ']</span> <span class="bvk-log-' + (type || 'info') + '">' + escHtml(String(msg)) + '</span>';
        consoleEl.appendChild(line);
        consoleEl.scrollTop = consoleEl.scrollHeight;
        // Update init timestamp on first real log
        var init = consoleEl.querySelector('.bvk-log-time');
        if (init && init.textContent === '[--:--:--]') init.textContent = '[' + now + ']';
    }

    /* ── Submit btn state ── */
    function updateSubmitBtn() {
        if (submitBtn) submitBtn.disabled = !portraitUrl.value || !audioUrl.value || isSubmitting;
    }

    /* ── Status helper ── */
    function showStatus(msg, type, el) {
        el = el || statusEl;
        if (!el) return;
        el.className = 'bvk-status';
        if (msg && type) { el.textContent = msg; el.classList.add(type); }
    }

    /* ════════════════════════════════════════
     *  ① PORTRAIT IMAGE UPLOAD
     * ════════════════════════════════════════ */
    var portraitFile     = document.getElementById('bvk-av-portrait-file');
    var portraitDropzone = document.getElementById('bvk-av-portrait-dropzone');
    var portraitPreview  = document.getElementById('bvk-av-portrait-preview');
    var portraitPlaceholder = document.getElementById('bvk-av-portrait-placeholder');
    var portraitProgress = document.getElementById('bvk-av-portrait-progress');
    var portraitClear    = document.getElementById('bvk-av-portrait-clear');
    var portraitMediaBtn = document.getElementById('bvk-av-portrait-media-btn');

    if (portraitFile) {
        portraitFile.addEventListener('change', function() {
            if (portraitFile.files[0]) uploadPortrait(portraitFile.files[0]);
        });
    }
    if (portraitDropzone) {
        portraitDropzone.addEventListener('dragover', function(e) { e.preventDefault(); portraitDropzone.classList.add('dragover'); });
        portraitDropzone.addEventListener('dragleave', function() { portraitDropzone.classList.remove('dragover'); });
        portraitDropzone.addEventListener('drop', function(e) {
            e.preventDefault(); portraitDropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) uploadPortrait(e.dataTransfer.files[0]);
        });
    }
    if (portraitClear) {
        portraitClear.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            portraitUrl.value = '';
            portraitPreview.style.display = 'none';
            portraitPlaceholder.style.display = '';
            if (portraitFile) portraitFile.value = '';
            updateSubmitBtn();
        });
    }
    if (portraitMediaBtn) {
        portraitMediaBtn.addEventListener('click', function() {
            if (typeof wp === 'undefined' || !wp.media) { alert('WP Media Library không khả dụng.'); return; }
            var frame = wp.media({ title: 'Chọn ảnh chân dung', multiple: false, library: { type: 'image' } });
            frame.on('select', function() {
                var att = frame.state().get('selection').first().toJSON();
                portraitUrl.value = att.url;
                portraitPreview.querySelector('img').src = att.url;
                portraitPreview.style.display = '';
                portraitPlaceholder.style.display = 'none';
                updateSubmitBtn();
            });
            frame.open();
        });
    }

    function uploadPortrait(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            portraitPreview.querySelector('img').src = ev.target.result;
            portraitPreview.style.display = '';
            portraitPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);

        var fd = new FormData();
        fd.append('action', 'bvk_upload_photo');
        fd.append('nonce', BVK.nonce);
        fd.append('photo', file);
        var bar = portraitProgress.querySelector('.bvk-aiva-scene-progress-bar');
        portraitProgress.classList.add('active');
        bar.style.width = '30%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.upload.onprogress = function(ev) { if (ev.lengthComputable) bar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%'; };
        xhr.onload = function() {
            bar.style.width = '100%';
            setTimeout(function() { portraitProgress.classList.remove('active'); bar.style.width = '0'; }, 600);
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) {
                    portraitUrl.value = res.data.url;
                    updateSubmitBtn();
                } else {
                    showStatus('Upload ảnh thất bại: ' + (res.data && res.data.message || 'Lỗi'), 'error');
                    portraitPreview.style.display = 'none'; portraitPlaceholder.style.display = '';
                }
            } catch(e) { showStatus('Upload ảnh thất bại.', 'error'); }
        };
        xhr.onerror = function() { portraitProgress.classList.remove('active'); showStatus('Lỗi kết nối.', 'error'); };
        xhr.send(fd);
    }

    /* ════════════════════════════════════════
     *  ② AUDIO SOURCE TABS
     * ════════════════════════════════════════ */
    document.querySelectorAll('.bvk-av-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.bvk-av-tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.bvk-av-panel').forEach(function(p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var panel = document.getElementById('bvk-av-panel-' + tab.dataset.panel);
            if (panel) panel.classList.add('active');
        });
    });

    /* ── Set audio URL & show preview ── */
    function setAudioUrl(url) {
        audioUrl.value = url;
        audioPlayer.src = url;
        audioPreview.style.display = '';
        updateSubmitBtn();
    }
    function clearAudio() {
        audioUrl.value = '';
        audioPlayer.src = '';
        audioPlayer.pause();
        audioPreview.style.display = 'none';
        updateSubmitBtn();
    }
    document.getElementById('bvk-av-audio-clear').addEventListener('click', clearAudio);

    /* ── TTS ── */
    var ttsBtn    = document.getElementById('bvk-av-tts-btn');
    var ttsText   = document.getElementById('bvk-av-tts-text');
    var ttsVoice  = document.getElementById('bvk-av-tts-voice');
    var ttsStat   = document.getElementById('bvk-av-tts-status');
    var ttsRunning = false;

    if (ttsBtn) {
        ttsBtn.addEventListener('click', function() {
            if (ttsRunning) return;
            var text = (ttsText.value || '').trim();
            if (!text) { showStatus('Vui lòng nhập nội dung.', 'error', ttsStat); return; }
            ttsRunning = true;
            ttsBtn.disabled = true;
            ttsBtn.textContent = '⏳ Đang tạo...';
            showStatus('Đang tạo audio TTS...', 'loading', ttsStat);

            var fd = new FormData();
            fd.append('action', 'bvk_avatar_tts');
            fd.append('nonce', BVK.nonce);
            fd.append('payload', JSON.stringify({ text: text, voice: ttsVoice.value }));
            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                ttsRunning = false;
                ttsBtn.disabled = false;
                ttsBtn.textContent = '🔊 Tạo audio';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.url) {
                        showStatus('✅ Tạo audio thành công!', 'success', ttsStat);
                        setAudioUrl(res.data.url);
                    } else {
                        showStatus('Lỗi TTS: ' + (res.data && res.data.message || 'Thất bại'), 'error', ttsStat);
                    }
                } catch(e) { showStatus('Server error.', 'error', ttsStat); }
            };
            xhr.onerror = function() { ttsRunning = false; ttsBtn.disabled = false; ttsBtn.textContent = '🔊 Tạo audio'; showStatus('Lỗi kết nối.', 'error', ttsStat); };
            xhr.send(fd);
        });
    }

    /* ── Audio Upload ── */
    var audioFile     = document.getElementById('bvk-av-audio-file');
    var audioDropzone = document.getElementById('bvk-av-audio-dropzone');
    var audioPlaceholder = document.getElementById('bvk-av-audio-placeholder');
    var audioProgress = document.getElementById('bvk-av-audio-progress');

    if (audioFile) {
        audioFile.addEventListener('change', function() { if (audioFile.files[0]) uploadAudio(audioFile.files[0]); });
    }
    if (audioDropzone) {
        audioDropzone.addEventListener('dragover', function(e) { e.preventDefault(); audioDropzone.classList.add('dragover'); });
        audioDropzone.addEventListener('dragleave', function() { audioDropzone.classList.remove('dragover'); });
        audioDropzone.addEventListener('drop', function(e) {
            e.preventDefault(); audioDropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) uploadAudio(e.dataTransfer.files[0]);
        });
    }

    function uploadAudio(file) {
        if (!file) return;
        var fd = new FormData();
        fd.append('action', 'bvk_tc_upload_file');
        fd.append('nonce', BVK.nonce);
        fd.append('payload', JSON.stringify({}));
        fd.append('file', file);
        var bar = audioProgress.querySelector('.bvk-aiva-scene-progress-bar');
        audioProgress.classList.add('active');
        bar.style.width = '10%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.upload.onprogress = function(ev) { if (ev.lengthComputable) bar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%'; };
        xhr.onload = function() {
            bar.style.width = '100%';
            setTimeout(function() { audioProgress.classList.remove('active'); bar.style.width = '0'; }, 600);
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) {
                    setAudioUrl(res.data.url);
                } else {
                    showStatus('Upload audio thất bại: ' + (res.data && res.data.message || 'Lỗi'), 'error');
                }
            } catch(e) { showStatus('Upload audio thất bại.', 'error'); }
        };
        xhr.onerror = function() { audioProgress.classList.remove('active'); showStatus('Lỗi kết nối.', 'error'); };
        xhr.send(fd);
    }

    /* ── Microphone Recording with waveform + auto-stop ── */
    var recBtn    = document.getElementById('bvk-av-rec-btn');
    var recStop   = document.getElementById('bvk-av-rec-stop');
    var recTime   = document.getElementById('bvk-av-rec-time');
    var recHint   = document.getElementById('bvk-av-rec-hint');
    var recBar    = document.getElementById('bvk-av-rec-bar');
    var recCanvas = document.getElementById('bvk-av-rec-canvas');
    var recCtx    = recCanvas ? recCanvas.getContext('2d') : null;

    var mediaRecorder = null;
    var recChunks  = [];
    var recTimer   = null;
    var recSec     = 0;
    var recStream  = null;
    var recAnalyser = null;
    var recAnimFrame = null;
    var REC_MAX_SEC = 35; /* OmniHuman max 35s */

    function recSetHint(msg, cls) {
        if (!recHint) return;
        recHint.textContent = msg;
        recHint.className = 'bvk-av-recorder__hint' + (cls ? ' ' + cls : '');
    }

    /* ── Waveform draw ── */
    function drawWaveform() {
        if (!recAnalyser || !recCtx || !recCanvas) return;
        var w = recCanvas.width = recCanvas.offsetWidth * (window.devicePixelRatio || 1);
        var h = recCanvas.height = recCanvas.offsetHeight * (window.devicePixelRatio || 1);
        var bufLen = recAnalyser.frequencyBinCount;
        var data = new Uint8Array(bufLen);

        function tick() {
            recAnimFrame = requestAnimationFrame(tick);
            recAnalyser.getByteTimeDomainData(data);
            recCtx.fillStyle = '#161b22';
            recCtx.fillRect(0, 0, w, h);
            recCtx.lineWidth = 2;
            recCtx.strokeStyle = '#f85149';
            recCtx.beginPath();
            var sliceW = w / bufLen;
            var x = 0;
            for (var i = 0; i < bufLen; i++) {
                var v = data[i] / 128.0;
                var y = (v * h) / 2;
                if (i === 0) recCtx.moveTo(x, y); else recCtx.lineTo(x, y);
                x += sliceW;
            }
            recCtx.lineTo(w, h / 2);
            recCtx.stroke();
        }
        tick();
    }

    function stopWaveform() {
        if (recAnimFrame) { cancelAnimationFrame(recAnimFrame); recAnimFrame = null; }
        if (recCtx && recCanvas) {
            var w = recCanvas.width, h = recCanvas.height;
            recCtx.fillStyle = '#161b22';
            recCtx.fillRect(0, 0, w, h);
            recCtx.strokeStyle = '#30363d';
            recCtx.beginPath();
            recCtx.moveTo(0, h / 2);
            recCtx.lineTo(w, h / 2);
            recCtx.stroke();
        }
    }

    function stopRecordingCleanup() {
        clearInterval(recTimer);
        if (recStream) { recStream.getTracks().forEach(function(t) { t.stop(); }); recStream = null; }
        stopWaveform();
        recBtn.classList.remove('recording');
        recBtn.style.display = '';
        recStop.style.display = 'none';
        if (recBar) recBar.style.width = '0';
    }

    if (recBtn) {
        recBtn.addEventListener('click', function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') return;
            recSetHint('Đang xin quyền microphone...');

            navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function(stream) {
                recStream = stream;
                mediaRecorder = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm' });
                recChunks = [];
                mediaRecorder.ondataavailable = function(e) { if (e.data.size > 0) recChunks.push(e.data); };
                mediaRecorder.onstop = function() {
                    stopRecordingCleanup();
                    if (recChunks.length === 0) { recSetHint('Ghi âm trống — thử lại.', 'warn'); return; }
                    var blob = new Blob(recChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                    recSetHint('✅ Đã ghi ' + recSec + 's — đang upload...');
                    uploadAudioBlob(blob);
                };

                /* Audio analyser for waveform */
                try {
                    var actx = new (window.AudioContext || window.webkitAudioContext)();
                    var source = actx.createMediaStreamSource(stream);
                    recAnalyser = actx.createAnalyser();
                    recAnalyser.fftSize = 256;
                    source.connect(recAnalyser);
                    drawWaveform();
                } catch(e) { /* waveform not critical */ }

                mediaRecorder.start(250); /* collect in 250ms chunks */
                recBtn.classList.add('recording');
                recBtn.style.display = 'none';
                recStop.style.display = '';
                recSec = 0;
                recTime.textContent = '0:00';
                recSetHint('🔴 Đang ghi âm... Nhấn ⏹ để dừng');

                recTimer = setInterval(function() {
                    recSec++;
                    recTime.textContent = Math.floor(recSec / 60) + ':' + String(recSec % 60).padStart(2, '0');
                    if (recBar) recBar.style.width = Math.min((recSec / REC_MAX_SEC) * 100, 100) + '%';
                    /* Warn at 30s */
                    if (recSec === REC_MAX_SEC - 5) {
                        recSetHint('⚠️ Còn 5 giây...', 'warn');
                    }
                    /* Auto-stop at max */
                    if (recSec >= REC_MAX_SEC) {
                        recSetHint('Đã đạt tối đa ' + REC_MAX_SEC + 's — tự động dừng.', 'warn');
                        if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.stop();
                    }
                }, 1000);
            }).catch(function(err) {
                var msg = 'Không thể truy cập microphone.';
                if (err.name === 'NotAllowedError') msg = 'Bạn đã từ chối quyền microphone. Hãy cho phép trong cài đặt trình duyệt.';
                else if (err.name === 'NotFoundError') msg = 'Không tìm thấy microphone trên thiết bị.';
                recSetHint(msg, 'err');
                showStatus(msg, 'error');
            });
        });
    }
    if (recStop) {
        recStop.addEventListener('click', function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
            } else {
                stopRecordingCleanup();
            }
        });
    }

    function uploadAudioBlob(blob) {
        var file = new File([blob], 'recording_' + Date.now() + '.webm', { type: blob.type || 'audio/webm' });
        uploadAudio(file);
    }

    /* ════════════════════════════════════════
     *  ③ SUBMIT AVATAR
     * ════════════════════════════════════════ */
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            if (isSubmitting || submitBtn.disabled) return;
            if (!portraitUrl.value || !audioUrl.value) { showStatus('Vui lòng chọn ảnh và audio.', 'error'); return; }

            isSubmitting = true;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>⏳</span> Đang gửi...';
            showStatus('Đang gửi yêu cầu Avatar...', 'loading');

            var modelSel = document.getElementById('bvk-av-model').value;
            var duration = document.getElementById('bvk-av-duration').value;

            bvkLog('Gửi yêu cầu → model: ' + modelSel + ' | duration: ' + duration + 's', 'proc');
            bvkLog('Audio: ' + audioUrl.value, 'info');

            var fd = new FormData();
            fd.append('action', 'bvk_avatar_create');
            fd.append('nonce', BVK.nonce);
            fd.append('payload', JSON.stringify({
                image_url: portraitUrl.value,
                audio_url: audioUrl.value,
                model:     modelSel,
                duration:  parseInt(duration, 10)
            }));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                isSubmitting = false;
                submitBtn.innerHTML = '<span>🧑‍🎤</span> Tạo Avatar Video';
                updateSubmitBtn();
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data && res.data.taskId) {
                        showStatus('Đã gửi thành công! Đang xử lý...', 'success');
                        bvkLog('✅ Task ID: ' + res.data.taskId, 'success');
                        bvkLog('🔄 Bắt đầu polling mỗi 8s...', 'info');
                        addJob(res.data.taskId, res.data.model || modelSel);
                        startPolling();
                    } else {
                        var createErr = res.data && res.data.message || 'Tạo avatar thất bại';
                        if (createErr && typeof createErr === 'object') createErr = createErr.raw_message || createErr.message || 'Thất bại';
                        showStatus('Lỗi: ' + createErr, 'error');
                        bvkLog('❌ ' + createErr, 'error');
                    }
                } catch(e) { showStatus('Server error.', 'error'); }
            };
            xhr.onerror = function() {
                isSubmitting = false;
                submitBtn.innerHTML = '<span>🧑‍🎤</span> Tạo Avatar Video';
                updateSubmitBtn();
                showStatus('Lỗi kết nối.', 'error');
            };
            xhr.send(fd);
        });
    }

    /* ════════════════════════════════════════
     *  ④ JOB QUEUE & POLLING
     * ════════════════════════════════════════ */
    function addJob(taskId, model) {
        avJobs.unshift({
            taskId: taskId,
            model: model || 'kling-avatar',
            status: 'pending',
            progress: 0,
            resultUrl: null,
            createdAt: new Date().toLocaleTimeString('vi-VN')
        });
        renderJobs();
    }

    function renderJobs() {
        if (avJobs.length === 0) { emptyEl.style.display = ''; jobsEl.style.display = 'none'; return; }
        emptyEl.style.display = 'none';
        jobsEl.style.display = '';
        jobsEl.innerHTML = '';

        avJobs.forEach(function(job, idx) {
            var statusLabel = { pending: '⏳ Đang chờ', processing: '🔄 Đang xử lý', completed: '✅ Hoàn thành', failed: '❌ Thất bại' };
            var card = document.createElement('div');
            card.className = 'bvk-av-job';

            var html = '<div class="bvk-av-job__top">' +
                '<span class="bvk-av-job__status ' + job.status + '">' + (statusLabel[job.status] || job.status) + '</span>' +
                '<span class="bvk-av-job__model">' + escHtml(job.model) + '</span>' +
                '<span class="bvk-av-job__time">' + escHtml(job.createdAt) + '</span></div>';

            if (job.status === 'processing' || job.status === 'pending') {
                html += '<div class="bvk-progress"><div class="bvk-progress-bar" style="width:' + (job.progress || 5) + '%"></div></div>';
            }

            if (job.status === 'completed' && job.resultUrl) {
                html += '<div class="bvk-av-job__preview"><video src="' + escAttr(job.resultUrl) + '" controls playsinline preload="metadata"></video></div>';
                html += '<div class="bvk-av-job__actions">' +
                    '<button type="button" data-action="save" data-idx="' + idx + '">📥 Lưu vào Media</button>' +
                    '<a href="' + escAttr(job.resultUrl) + '" target="_blank">▶ Xem</a>' +
                    '<button type="button" data-action="copy" data-url="' + escAttr(job.resultUrl) + '">🔗 Copy</button>' +
                    '</div>';
            }

            if (job.status === 'failed') {
                html += '<div class="bvk-av-job__error">' + escHtml(job.error || 'Tạo avatar thất bại. Vui lòng thử lại.') + '</div>';
                html += '<div class="bvk-av-job__actions"><button type="button" data-action="retry" data-idx="' + idx + '">🔄 Thử lại</button></div>';
            }

            card.innerHTML = html;
            jobsEl.appendChild(card);
        });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(pollJobs, 8000);
    }

    function pollJobs() {
        var hasActive = false;
        avJobs.forEach(function(job) {
            if (job.status === 'pending' || job.status === 'processing') {
                hasActive = true;
                pollSingle(job);
            }
        });
        if (!hasActive && pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function pollSingle(job) {
        var fd = new FormData();
        fd.append('action', 'bvk_avatar_status');
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
                    if (job.status === 'completed') {
                        bvkLog('✅ Hoàn thành! ' + (job.resultUrl || ''), 'success');
                    } else {
                        bvkLog('[' + job.taskId.substring(0, 8) + '…] ' + job.status + ' ' + (job.progress || 0) + '%', 'proc');
                    }
                    renderJobs();
                } else if (!res.success && res.data && res.data.status === 'failed') {
                    job.status = 'failed';
                    var failMsg = res.data.message || 'Tạo avatar thất bại.';
                    if (failMsg && typeof failMsg === 'object') failMsg = failMsg.raw_message || failMsg.message || 'Thất bại';
                    job.error = failMsg;
                    bvkLog('❌ Thất bại: ' + failMsg, 'error');
                    renderJobs();
                }
            } catch(e) {}
        };
        xhr.send(fd);
    }

    /* ── Event delegation for job actions ── */
    jobsEl.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');
        var idx    = parseInt(btn.getAttribute('data-idx'), 10);

        if (action === 'save') {
            var job = avJobs[idx];
            if (!job || !job.resultUrl) { showStatus('Không tìm thấy video URL.', 'error'); return; }
            btn.disabled = true;
            btn.textContent = '⏳ Đang lưu...';
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
                        btn.textContent = '✅ Đã lưu!';
                        showStatus('✅ Đã lưu vào Media Library!', 'success');
                    } else {
                        btn.disabled = false;
                        btn.textContent = '📥 Lưu vào Media';
                        showStatus('Lưu Media thất bại: ' + (res.data && res.data.message || ''), 'error');
                    }
                } catch(err) { btn.disabled = false; btn.textContent = '📥 Lưu vào Media'; showStatus('Lỗi.', 'error'); }
            };
            xhr.onerror = function() { btn.disabled = false; btn.textContent = '📥 Lưu vào Media'; showStatus('Lỗi kết nối.', 'error'); };
            xhr.send(fd);
        }

        if (action === 'copy') {
            var url = btn.getAttribute('data-url');
            if (!url) return;
            navigator.clipboard.writeText(url).then(function() {
                var orig = btn.textContent;
                btn.textContent = '✅ Copied!';
                setTimeout(function() { btn.textContent = orig; }, 1500);
            });
        }

        if (action === 'retry') {
            avJobs.splice(idx, 1);
            renderJobs();
            showStatus('Đã xóa job lỗi. Nhấn "Tạo Avatar Video" để thử lại.', 'info');
        }
    });

    /* ── Helpers ── */
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

})();
</script>
</body>
</html>
