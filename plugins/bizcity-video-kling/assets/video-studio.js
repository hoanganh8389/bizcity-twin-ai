/**
 * BizCity Video Kling — Video Studio JS
 * Handles: Featured carousel, Category filter, Multi-scene upload, Generate form
 */
(function () {
    'use strict';

    var ajaxUrl = (typeof bvk_studio !== 'undefined' && bvk_studio.ajax_url) || '/wp-admin/admin-ajax.php';
    var nonce   = (typeof bvk_studio !== 'undefined' && bvk_studio.nonce) || '';

    /* ═══════════════════════════════
       FEATURED CAROUSEL
       ═══════════════════════════════ */
    var carousel = document.getElementById('bvk-featured-carousel');
    if (carousel) {
        var slides = carousel.querySelectorAll('.bvk-featured-slide');
        var idx = 0;
        var totalSlides = slides.length;
        var counterEl = document.getElementById('bvk-featured-idx');
        var navContainer = document.getElementById('bvk-featured-nav');

        function showSlide(n) {
            slides.forEach(function (s) { s.classList.remove('active'); });
            idx = Math.max(0, Math.min(n, totalSlides - 1));
            slides[idx].classList.add('active');
            if (counterEl) counterEl.textContent = idx + 1;
            if (navContainer) {
                var prevBtn = navContainer.querySelector('[data-dir="prev"]');
                var nextBtn = navContainer.querySelector('[data-dir="next"]');
                if (prevBtn) prevBtn.disabled = (idx === 0);
                if (nextBtn) nextBtn.disabled = (idx === totalSlides - 1);
            }
        }

        if (navContainer) {
            navContainer.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-dir]');
                if (!btn) return;
                showSlide(idx + (btn.dataset.dir === 'next' ? 1 : -1));
            });
        }
    }

    /* ═══════════════════════════════
       CATEGORY TABS (client-side filter)
       ═══════════════════════════════ */
    var tabsContainer = document.getElementById('bvk-cat-tabs');
    var grid = document.getElementById('bvk-effect-grid');

    if (tabsContainer && grid) {
        tabsContainer.addEventListener('click', function (e) {
            var tab = e.target.closest('.bvk-cat-tab');
            if (!tab) return;

            tabsContainer.querySelectorAll('.bvk-cat-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');

            var cat = tab.dataset.cat || '';
            grid.querySelectorAll('.bvk-effect-card').forEach(function (card) {
                if (!cat || card.dataset.cat === cat) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    /* ═══════════════════════════════
       MULTI-SCENE UPLOAD
       ═══════════════════════════════ */
    var scenesContainer = document.getElementById('bvk-scenes-container');
    var addSceneBtn = document.getElementById('bvk-add-scene');
    var removeLastBtn = document.getElementById('bvk-remove-last-scene');

    function getSceneCount() {
        return scenesContainer ? scenesContainer.querySelectorAll('.bvk-scene').length : 0;
    }

    function updateRemoveBtn() {
        if (removeLastBtn) {
            removeLastBtn.style.display = getSceneCount() > 1 ? '' : 'none';
        }
    }

    function createSceneHTML(num) {
        var div = document.createElement('div');
        div.className = 'bvk-scene';
        div.dataset.scene = num;
        div.innerHTML =
            '<div class="bvk-scene__header">' +
                '<span class="bvk-scene__label">Cảnh ' + num + '</span>' +
                '<button type="button" class="bvk-scene__remove" title="Xóa cảnh này">✖</button>' +
            '</div>' +
            '<label class="bvk-scene__dropzone" data-scene="' + num + '">' +
                '<input type="file" accept="image/*" class="bvk-scene__file" data-scene="' + num + '" style="display:none">' +
                '<div class="bvk-scene__preview" style="display:none"><img src="" alt=""><button type="button" class="bvk-scene__clear" title="Xóa ảnh">✕</button></div>' +
                '<div class="bvk-scene__placeholder"><span>📷</span><p>Kéo thả hoặc nhấn để chọn ảnh</p><small>JPG, PNG, WebP — tối đa 10MB</small></div>' +
            '</label>' +
            '<input type="hidden" class="bvk-scene__url" data-scene="' + num + '" value="">' +
            '<div class="bvk-scene__progress"><div class="bvk-scene__progress-bar"></div></div>';
        return div;
    }

    if (addSceneBtn && scenesContainer) {
        addSceneBtn.addEventListener('click', function () {
            var num = getSceneCount() + 1;
            scenesContainer.appendChild(createSceneHTML(num));
            updateRemoveBtn();
            updateGenerateBtn();
        });
    }

    if (removeLastBtn && scenesContainer) {
        removeLastBtn.addEventListener('click', function () {
            var scenes = scenesContainer.querySelectorAll('.bvk-scene');
            if (scenes.length > 1) {
                scenes[scenes.length - 1].remove();
                updateRemoveBtn();
                updateGenerateBtn();
            }
        });
    }

    // Delegate scene events
    if (scenesContainer) {
        // File input change
        scenesContainer.addEventListener('change', function (e) {
            if (e.target.classList.contains('bvk-scene__file')) {
                handleSceneFile(e.target);
            }
        });

        // Remove scene button
        scenesContainer.addEventListener('click', function (e) {
            if (e.target.classList.contains('bvk-scene__remove')) {
                var scene = e.target.closest('.bvk-scene');
                if (scene && getSceneCount() > 1) {
                    scene.remove();
                    renumberScenes();
                    updateRemoveBtn();
                    updateGenerateBtn();
                }
            }
            // Clear image
            if (e.target.classList.contains('bvk-scene__clear')) {
                e.preventDefault();
                e.stopPropagation();
                var scene = e.target.closest('.bvk-scene');
                if (scene) {
                    clearScene(scene);
                    updateGenerateBtn();
                }
            }
        });

        // Drag & drop
        scenesContainer.addEventListener('dragover', function (e) {
            var dz = e.target.closest('.bvk-scene__dropzone');
            if (dz) { e.preventDefault(); dz.classList.add('dragover'); }
        });
        scenesContainer.addEventListener('dragleave', function (e) {
            var dz = e.target.closest('.bvk-scene__dropzone');
            if (dz) dz.classList.remove('dragover');
        });
        scenesContainer.addEventListener('drop', function (e) {
            var dz = e.target.closest('.bvk-scene__dropzone');
            if (!dz) return;
            e.preventDefault();
            dz.classList.remove('dragover');
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                var fileInput = dz.querySelector('.bvk-scene__file');
                // Can't set file input value, so trigger upload directly
                uploadSceneImage(dz.closest('.bvk-scene'), files[0]);
            }
        });
    }

    function handleSceneFile(input) {
        var scene = input.closest('.bvk-scene');
        var file = input.files[0];
        if (!file || !scene) return;
        uploadSceneImage(scene, file);
    }

    function uploadSceneImage(scene, file) {
        var preview = scene.querySelector('.bvk-scene__preview');
        var placeholder = scene.querySelector('.bvk-scene__placeholder');
        var urlInput = scene.querySelector('.bvk-scene__url');
        var progressWrap = scene.querySelector('.bvk-scene__progress');
        var progressBar = scene.querySelector('.bvk-scene__progress-bar');

        // Show local preview
        var reader = new FileReader();
        reader.onload = function (ev) {
            preview.querySelector('img').src = ev.target.result;
            preview.style.display = '';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);

        // Upload via AJAX
        var fd = new FormData();
        fd.append('action', 'bvk_upload_photo');
        fd.append('nonce', nonce);
        fd.append('photo', file);

        progressWrap.classList.add('active');
        progressBar.style.width = '30%';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);

        xhr.upload.onprogress = function (ev) {
            if (ev.lengthComputable) {
                progressBar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%';
            }
        };

        xhr.onload = function () {
            progressBar.style.width = '100%';
            setTimeout(function () {
                progressWrap.classList.remove('active');
                progressBar.style.width = '0';
            }, 600);

            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) {
                    urlInput.value = res.data.url;
                    updateGenerateBtn();
                } else {
                    alert('Upload thất bại: ' + (res.data && res.data.message || 'Lỗi không xác định'));
                    clearScene(scene);
                }
            } catch (err) {
                alert('Upload thất bại.');
                clearScene(scene);
            }
        };

        xhr.onerror = function () {
            progressWrap.classList.remove('active');
            alert('Lỗi kết nối khi upload.');
            clearScene(scene);
        };

        xhr.send(fd);
    }

    function clearScene(scene) {
        var preview = scene.querySelector('.bvk-scene__preview');
        var placeholder = scene.querySelector('.bvk-scene__placeholder');
        var urlInput = scene.querySelector('.bvk-scene__url');
        var fileInput = scene.querySelector('.bvk-scene__file');

        if (preview) { preview.style.display = 'none'; preview.querySelector('img').src = ''; }
        if (placeholder) placeholder.style.display = '';
        if (urlInput) urlInput.value = '';
        if (fileInput) fileInput.value = '';
    }

    function renumberScenes() {
        if (!scenesContainer) return;
        var scenes = scenesContainer.querySelectorAll('.bvk-scene');
        scenes.forEach(function (s, i) {
            var num = i + 1;
            s.dataset.scene = num;
            var label = s.querySelector('.bvk-scene__label');
            if (label) label.textContent = 'Cảnh ' + num;
        });
    }

    /* ═══════════════════════════════
       GENERATE BUTTON STATE
       ═══════════════════════════════ */
    var generateBtn = document.getElementById('bvk-btn-generate');

    function updateGenerateBtn() {
        if (!generateBtn || !scenesContainer) return;
        var urls = scenesContainer.querySelectorAll('.bvk-scene__url');
        var allFilled = true;
        urls.forEach(function (u) { if (!u.value) allFilled = false; });
        generateBtn.disabled = !allFilled;
    }

    /* ═══════════════════════════════
       GENERATE — Submit
       ═══════════════════════════════ */
    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            if (generateBtn.disabled) return;
            generateBtn.disabled = true;
            generateBtn.textContent = '⏳ Đang gửi...';

            var statusEl = document.getElementById('bvk-gen-status');
            var resultEl = document.getElementById('bvk-gen-result');
            var resultBody = document.getElementById('bvk-gen-result-body');

            if (statusEl) { statusEl.className = 'bvk-status loading'; statusEl.textContent = 'Đang gửi yêu cầu tạo video...'; }

            // Collect scene URLs
            var sceneUrls = [];
            scenesContainer.querySelectorAll('.bvk-scene__url').forEach(function (u) {
                sceneUrls.push(u.value);
            });

            // Build prompt with image placeholders replaced
            var prompt = (document.getElementById('bvk-gen-prompt') || {}).value || '';
            sceneUrls.forEach(function (url, i) {
                prompt = prompt.replace('{{image_' + (i + 1) + '}}', url);
            });

            var duration = document.querySelector('input[name="bvk_gen_duration"]:checked');
            var ratio = document.querySelector('input[name="bvk_gen_ratio"]:checked');
            var model = document.getElementById('bvk-gen-model');

            var fd = new FormData();
            fd.append('action', 'bvk_create_video');
            fd.append('nonce', nonce);
            fd.append('prompt', prompt);
            fd.append('image_url', sceneUrls[0] || '');
            fd.append('duration', duration ? duration.value : '5');
            fd.append('aspect_ratio', ratio ? ratio.value : '9:16');
            fd.append('model', model ? model.value : '2.6|pro');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.onload = function () {
                generateBtn.disabled = false;
                generateBtn.textContent = '🚀 Tạo Video';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        if (statusEl) { statusEl.className = 'bvk-status success'; statusEl.textContent = 'Đã gửi yêu cầu thành công!'; }
                        if (resultEl) resultEl.classList.add('show');
                        if (resultBody) resultBody.innerHTML = '<p>Job ID: <strong>' + (res.data.job_id || '') + '</strong></p><p>Trạng thái: ' + (res.data.status || 'queued') + '</p><p><a href="?tab=monitor">→ Theo dõi tiến trình</a></p>';
                    } else {
                        if (statusEl) { statusEl.className = 'bvk-status error'; statusEl.textContent = (res.data && res.data.message) || 'Lỗi khi tạo video.'; }
                    }
                } catch (err) {
                    if (statusEl) { statusEl.className = 'bvk-status error'; statusEl.textContent = 'Lỗi phản hồi từ server.'; }
                }
            };
            xhr.onerror = function () {
                generateBtn.disabled = false;
                generateBtn.textContent = '🚀 Tạo Video';
                if (statusEl) { statusEl.className = 'bvk-status error'; statusEl.textContent = 'Lỗi kết nối.'; }
            };
            xhr.send(fd);
        });
    }

    // Initial state
    updateRemoveBtn();
    updateGenerateBtn();
})();
