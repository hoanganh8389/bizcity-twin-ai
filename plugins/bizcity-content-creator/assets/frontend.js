/**
 * BizCity Content Creator — Frontend JS
 *
 * Features:
 *   1. Category filter (client-side, instant)
 *   2. Search filter (client-side, debounced)
 *   3. Wizard step navigation
 *   4. Form submission via AJAX
 */
(function () {
	'use strict';

	/* ── DOM ready ── */
	document.addEventListener('DOMContentLoaded', init);

	function init() {
		var app = document.getElementById('bzcc-app');
		if (!app) return;

		var view = app.getAttribute('data-view');
		if (view === 'history') {
			initHistoryView();
		} else if (view === 'history-detail') {
			initHistoryDetailView();
		} else if (view === 'result' || document.getElementById('bzcc-result')) {
			initResultView();
		} else if (view === 'form' || app.querySelector('.bzcc-form')) {
			initFormView();
		} else {
			initBrowseView();
		}
	}

	/* ═══════════════════════════════════════
	 *  Browse View
	 * ═══════════════════════════════════════ */
	function initBrowseView() {
		initCategoryFilter();
		initSearch();
	}

	/* ── Category Filter ── */
	function initCategoryFilter() {
		var buttons = document.querySelectorAll('.bzcc-category-card');
		var cards   = document.querySelectorAll('.bzcc-tpl-card');
		var title   = document.getElementById('bzcc-templates-title');
		var empty   = document.getElementById('bzcc-empty-state');
		var grid    = document.getElementById('bzcc-template-grid');

		if (!buttons.length) return;

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var slug = btn.getAttribute('data-category') || '';

				// Toggle active class
				buttons.forEach(function (b) { b.classList.remove('bzcc-category-card--active'); });
				btn.classList.add('bzcc-category-card--active');

				// Filter cards
				var visibleCount = 0;
				cards.forEach(function (card) {
					var catSlug = card.getAttribute('data-category-slug') || '';
					var show    = !slug || catSlug === slug;
					card.hidden = !show;
					if (show) visibleCount++;
				});

				// Update title
				if (title) {
					title.textContent = slug
						? btn.querySelector('.bzcc-category-card__name').textContent
						: 'Tất cả công cụ';
				}

				// Show/hide empty state
				if (empty) {
					empty.style.display = visibleCount === 0 ? '' : 'none';
				}
				if (grid) {
					grid.style.display = visibleCount === 0 ? 'none' : '';
				}
			});
		});
	}

	/* ── Search ── */
	function initSearch() {
		var input = document.getElementById('bzcc-search');
		if (!input) return;

		var cards   = document.querySelectorAll('.bzcc-tpl-card');
		var empty   = document.getElementById('bzcc-empty-state');
		var grid    = document.getElementById('bzcc-template-grid');
		var timer   = null;

		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () {
				var query = input.value.trim().toLowerCase();
				var visibleCount = 0;

				cards.forEach(function (card) {
					if (!query) {
						card.hidden = false;
						visibleCount++;
						return;
					}
					var cardTitle = card.getAttribute('data-title') || '';
					var cardTags  = card.getAttribute('data-tags') || '';
					var match     = cardTitle.indexOf(query) !== -1 || cardTags.indexOf(query) !== -1;
					card.hidden   = !match;
					if (match) visibleCount++;
				});

				if (empty) {
					empty.style.display = visibleCount === 0 ? '' : 'none';
				}
				if (grid) {
					grid.style.display = visibleCount === 0 ? 'none' : '';
				}

				// Reset category active state when searching
				if (query) {
					document.querySelectorAll('.bzcc-category-card').forEach(function (btn) {
						btn.classList.remove('bzcc-category-card--active');
					});
				}
			}, 200);
		});
	}

	/* ═══════════════════════════════════════
	 *  Form View
	 * ═══════════════════════════════════════ */
	function initFormView() {
		initLayoutGrouping();
		initCollapsible();
		initTabGroups();
		initButtonGroups();
		initCheckboxGrids();
		initImageUpload();
		initFileUpload();
		initCardCheckbox();
		initWizard();
		initFormSubmit();
		initRating();
		initRangeOutput();
		prefillFromWebchat();
		listenAutoGenerate();
	}

	/* ── Listen for postMessage from parent (webchat iframe) to auto-submit form ── */
	function listenAutoGenerate() {
		window.addEventListener('message', function (e) {
			if (!e.data || e.data.type !== 'bzcc-auto-generate') return;
			var form = document.getElementById('bzcc-form');
			if (!form) return;
			// Trigger native submit event (same as clicking Tạo nội dung button)
			var event = new Event('submit', { bubbles: true, cancelable: true });
			form.dispatchEvent(event);
		});
	}

	/* ── Prefill form from webchat iframe params (topic, session_id) ── */
	function prefillFromWebchat() {
		var front = window.bzccFront;
		if (!front) return;

		var form = document.getElementById('bzcc-form');
		if (!form) return;

		// Prefill topic into first matching text/textarea field
		var topic = front.topic;
		if (topic) {
			var target = form.querySelector('input[name="topic"], textarea[name="topic"]')
			          || form.querySelector('.bzcc-fields input[type="text"], .bzcc-fields textarea');
			if (target && !target.value) {
				target.value = topic;
			}
		}

		// Inject session_id as hidden input (for form submit to push to webchat)
		if (front.sessionId) {
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'webchat_session_id';
			hidden.value = front.sessionId;
			form.appendChild(hidden);
		}
	}

	/* ── Star Rating click handler ── */
	function initRating() {
		document.querySelectorAll('.bzcc-rating').forEach(function (wrap) {
			var stars = wrap.querySelectorAll('.bzcc-star');
			var input = wrap.querySelector('input[type="hidden"]');
			stars.forEach(function (star) {
				star.addEventListener('click', function () {
					var val = parseInt(star.getAttribute('data-value'), 10);
					input.value = val;
					stars.forEach(function (s) {
						var sv = parseInt(s.getAttribute('data-value'), 10);
						s.classList.toggle('bzcc-star--active', sv <= val);
					});
				});
			});
		});
	}

	/* ── Range slider live output ── */
	function initRangeOutput() {
		document.querySelectorAll('.bzcc-range-input').forEach(function (slider) {
			var output = slider.parentElement.querySelector('.bzcc-range-output');
			if (output) {
				slider.addEventListener('input', function () {
					output.textContent = slider.value;
				});
			}
		});
	}

	/* ── Image Upload via WP Media ── */
	function initImageUpload() {
		document.querySelectorAll('.bzcc-image-upload').forEach(function (wrap) {
			var fileInput = wrap.querySelector('.bzcc-file-input');
			if (!fileInput) return;

			var slug = fileInput.name;

			// Create preview area
			var preview = document.createElement('div');
			preview.className = 'bzcc-image-preview';
			preview.style.display = 'none';
			preview.innerHTML =
				'<img class="bzcc-image-preview__img" src="" alt="Preview">' +
				'<button type="button" class="bzcc-image-preview__remove" title="Xóa ảnh">&times;</button>' +
				'<div class="bzcc-image-preview__uploading" style="display:none;">' +
					'<div class="bzcc-spinner-sm"></div> Đang tải lên...' +
				'</div>';
			wrap.appendChild(preview);

			// Hidden inputs for uploaded attachment
			var hiddenId  = document.createElement('input');
			hiddenId.type = 'hidden';
			hiddenId.name = slug + '_attachment_id';
			hiddenId.value = '';
			wrap.appendChild(hiddenId);

			var hiddenUrl = document.createElement('input');
			hiddenUrl.type = 'hidden';
			hiddenUrl.name = slug + '_url';
			hiddenUrl.value = '';
			wrap.appendChild(hiddenUrl);

			var previewImg  = preview.querySelector('.bzcc-image-preview__img');
			var removeBtn   = preview.querySelector('.bzcc-image-preview__remove');
			var uploadingEl = preview.querySelector('.bzcc-image-preview__uploading');

			fileInput.addEventListener('change', function () {
				var file = fileInput.files && fileInput.files[0];
				if (!file) return;

				// Show local preview immediately
				var reader = new FileReader();
				reader.onload = function (e) {
					previewImg.src = e.target.result;
					preview.style.display = '';
					uploadingEl.style.display = '';
				};
				reader.readAsDataURL(file);

				// Upload to WP via admin-ajax
				var fd = new FormData();
				fd.append('action', 'bzcc_upload_image');
				fd.append('nonce', document.querySelector('[name="bzcc_nonce"]').value);
				fd.append('file', file);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', (window.bzccFront && window.bzccFront.ajaxUrl) || '/wp-admin/admin-ajax.php');

				xhr.onload = function () {
					uploadingEl.style.display = 'none';
					try {
						var res = JSON.parse(xhr.responseText);
						if (res.success && res.data) {
							hiddenId.value  = res.data.id;
							hiddenUrl.value = res.data.url;
							previewImg.src  = res.data.url;
						} else {
							alert(res.data && res.data.message ? res.data.message : 'Upload ảnh thất bại');
							resetImageField();
						}
					} catch (err) {
						alert('Upload ảnh thất bại');
						resetImageField();
					}
				};

				xhr.onerror = function () {
					uploadingEl.style.display = 'none';
					alert('Không thể tải ảnh lên server');
					resetImageField();
				};

				xhr.send(fd);
			});

			removeBtn.addEventListener('click', function () {
				resetImageField();
			});

			function resetImageField() {
				fileInput.value = '';
				hiddenId.value  = '';
				hiddenUrl.value = '';
				preview.style.display = 'none';
				previewImg.src = '';
			}
		});
	}

	/* ── File Upload handler (Smart Input Phase 3.2) ── */
	function initFileUpload() {
		document.querySelectorAll('.bzcc-file-upload').forEach(function (wrap) {
			var fileInput   = wrap.querySelector('.bzcc-file-upload-input');
			if (!fileInput) return;

			var slug       = fileInput.name;
			var previewEl  = wrap.querySelector('.bzcc-file-upload-preview');
			var nameEl     = wrap.querySelector('.bzcc-file-upload-name');
			var sizeEl     = wrap.querySelector('.bzcc-file-upload-size');
			var removeBtn  = wrap.querySelector('.bzcc-file-upload-remove');
			var statusEl   = wrap.querySelector('.bzcc-file-upload-status');
			var statusText = wrap.querySelector('.bzcc-file-upload-status-text');
			var labelEl    = wrap.querySelector('.bzcc-file-upload-label');

			// Hidden inputs for uploaded attachment
			var hiddenId  = document.createElement('input');
			hiddenId.type = 'hidden';
			hiddenId.name = slug + '_attachment_id';
			hiddenId.value = '';
			wrap.appendChild(hiddenId);

			var hiddenFilename = document.createElement('input');
			hiddenFilename.type = 'hidden';
			hiddenFilename.name = slug + '_filename';
			hiddenFilename.value = '';
			wrap.appendChild(hiddenFilename);

			fileInput.addEventListener('change', function () {
				var file = fileInput.files && fileInput.files[0];
				if (!file) return;

				// Show uploading state
				if (statusEl) statusEl.style.display = '';
				if (statusText) statusText.textContent = 'Đang tải lên...';
				if (labelEl) labelEl.style.display = 'none';

				// Upload to WP via admin-ajax
				var fd = new FormData();
				fd.append('action', 'bzcc_upload_file');
				fd.append('nonce', document.querySelector('[name="bzcc_nonce"]').value);
				fd.append('file', file);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', (window.bzccFront && window.bzccFront.ajaxUrl) || '/wp-admin/admin-ajax.php');

				xhr.onload = function () {
					if (statusEl) statusEl.style.display = 'none';
					try {
						var res = JSON.parse(xhr.responseText);
						if (res.success && res.data) {
							hiddenId.value       = res.data.id;
							hiddenFilename.value = res.data.filename;
							if (nameEl) nameEl.textContent = res.data.filename;
							if (sizeEl) sizeEl.textContent = formatFileSize(res.data.size);
							if (previewEl) previewEl.style.display = '';
						} else {
							alert(res.data && res.data.message ? res.data.message : 'Upload file thất bại');
							resetFileField();
						}
					} catch (err) {
						alert('Upload file thất bại');
						resetFileField();
					}
				};

				xhr.onerror = function () {
					if (statusEl) statusEl.style.display = 'none';
					alert('Không thể tải file lên server');
					resetFileField();
				};

				xhr.send(fd);
			});

			if (removeBtn) {
				removeBtn.addEventListener('click', function () {
					resetFileField();
				});
			}

			function resetFileField() {
				fileInput.value = '';
				hiddenId.value  = '';
				hiddenFilename.value = '';
				if (previewEl) previewEl.style.display = 'none';
				if (nameEl)    nameEl.textContent = '';
				if (sizeEl)    sizeEl.textContent = '';
				if (labelEl)   labelEl.style.display = '';
			}
		});
	}

	function formatFileSize(bytes) {
		if (!bytes) return '';
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
	}

	/* ── Populate Confirm Step ── */
	function populateConfirmStep() {
		var body = document.getElementById('bzcc-confirm-body');
		if (!body) return;

		var form = document.getElementById('bzcc-form');
		if (!form) return;

		var html = '';

		// Collect image previews
		form.querySelectorAll('.bzcc-image-upload').forEach(function (wrap) {
			var urlInput = wrap.querySelector('[name$="_url"]');
			if (urlInput && urlInput.value) {
				html += '<div class="bzcc-confirm-image">' +
					'<img src="' + escHtml(urlInput.value) + '" alt="Preview">' +
					'</div>';
			}
		});

		html += '<div class="bzcc-confirm-fields">';

		// Iterate visible fields (skip headings, skip hidden inputs)
		form.querySelectorAll('.bzcc-field').forEach(function (fieldWrap) {
			var label = fieldWrap.querySelector('.bzcc-label');
			if (!label) return;
			var labelText = label.textContent.replace(/\s*\*\s*$/, '').trim();

			// Get value based on field type
			var value = '';

			// Card radio/checkbox
			var cardGroup = fieldWrap.querySelector('.bzcc-card-options');
			if (cardGroup) {
				var selected = cardGroup.querySelectorAll('.bzcc-card-option--selected');
				var vals = [];
				selected.forEach(function (card) {
					var icon  = card.querySelector('.bzcc-card-option__icon');
					var title = card.querySelector('.bzcc-card-option__title');
					vals.push((icon ? icon.textContent + ' ' : '') + (title ? title.textContent : ''));
				});
				value = vals.join(', ');
			}

			// Button group / pills
			var pillGroup = fieldWrap.querySelector('.bzcc-button-group');
			if (pillGroup) {
				var selected = pillGroup.querySelectorAll('.bzcc-pill--selected .bzcc-pill__label');
				var vals = [];
				selected.forEach(function (pill) { vals.push(pill.textContent); });
				value = vals.join(', ');
			}

			// Standard inputs
			if (!value) {
				var input = fieldWrap.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea, select');
				if (input) {
					if (input.tagName === 'SELECT') {
						var opt = input.options[input.selectedIndex];
						value = opt && opt.value ? opt.textContent : '';
					} else {
						value = input.value || '';
					}
				}
			}

			// Radio group
			if (!value) {
				var checkedRadio = fieldWrap.querySelector('input[type="radio"]:checked');
				if (checkedRadio) {
					var radioLabel = checkedRadio.closest('label');
					value = radioLabel ? radioLabel.textContent.trim() : checkedRadio.value;
				}
			}

			// Checkbox group
			if (!value) {
				var checkedBoxes = fieldWrap.querySelectorAll('input[type="checkbox"]:checked');
				if (checkedBoxes.length) {
					var vals = [];
					checkedBoxes.forEach(function (cb) {
						var cbLabel = cb.closest('label');
						vals.push(cbLabel ? cbLabel.textContent.trim() : cb.value);
					});
					value = vals.join(', ');
				}
			}

			if (!value || !value.trim()) return;

			html += '<div class="bzcc-confirm-row">' +
				'<span class="bzcc-confirm-label">' + escHtml(labelText) + '</span>' +
				'<span class="bzcc-confirm-value">' + escHtml(value) + '</span>' +
				'</div>';
		});

		html += '</div>';

		body.innerHTML = html;
	}

	/* ── Wizard Navigation ── */
	function initWizard() {
		var steps   = document.querySelectorAll('.bzcc-form-step');
		var circles = document.querySelectorAll('.bzcc-step');
		var btnNext = document.getElementById('bzcc-btn-next');
		var btnPrev = document.getElementById('bzcc-btn-prev');
		var btnSubmit = document.getElementById('bzcc-btn-submit');
		var btnBack   = document.getElementById('bzcc-btn-back');
		var counter   = document.getElementById('bzcc-current-step');

		if (!steps.length || steps.length < 2) return;

		var current = 1;
		var total   = steps.length;
		var hasConfirm = !!document.getElementById('bzcc-confirm-body');

		function updateWizard() {
			steps.forEach(function (s) {
				var step = parseInt(s.getAttribute('data-step'), 10);
				s.classList.toggle('bzcc-form-step--active', step === current);
			});

			circles.forEach(function (c) {
				var step = parseInt(c.getAttribute('data-step'), 10);
				c.classList.toggle('bzcc-step--active', step === current);
				c.classList.toggle('bzcc-step--done', step < current);
			});

			if (btnPrev) btnPrev.style.display = current > 1 ? '' : 'none';

			if (current === total) {
				if (btnNext) btnNext.classList.add('bzcc-hide');
				if (btnSubmit) btnSubmit.classList.remove('bzcc-hide');
				if (btnBack) btnBack.classList.remove('bzcc-hide');
			} else {
				if (btnNext) btnNext.classList.remove('bzcc-hide');
				if (btnSubmit) btnSubmit.classList.add('bzcc-hide');
				if (btnBack) btnBack.classList.add('bzcc-hide');
			}

			if (counter) counter.textContent = current;

			// Populate confirm step when reaching it
			if (hasConfirm && current === total) {
				populateConfirmStep();
			}

			// Scroll to top of form
			var form = document.getElementById('bzcc-form');
			if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		if (btnNext) {
			btnNext.addEventListener('click', function () {
				// Validate current step required fields
				var activeStep = document.querySelector('.bzcc-form-step--active');
				if (activeStep && !validateStep(activeStep)) return;

				if (current < total) {
					current++;
					updateWizard();
				}
			});
		}

		if (btnPrev) {
			btnPrev.addEventListener('click', function () {
				if (current > 1) {
					current--;
					updateWizard();
				}
			});
		}
	}

	/* ── Validate a wizard step's required fields ── */
	function validateStep(stepEl) {
		var invalid = false;
		var fields  = stepEl.querySelectorAll('[required]');

		fields.forEach(function (field) {
			if (!field.value || !field.value.trim()) {
				field.classList.add('bzcc-field--error');
				field.style.borderColor = '#ef4444';
				invalid = true;

				// Remove error on input
				field.addEventListener('input', function handler() {
					field.classList.remove('bzcc-field--error');
					field.style.borderColor = '';
					field.removeEventListener('input', handler);
				});
			}
		});

		return !invalid;
	}

	/* ── Form Submission ── */
	function initFormSubmit() {
		var form = document.getElementById('bzcc-form');
		if (!form) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			// Validate all required fields
			var allInvalid = false;
			form.querySelectorAll('[required]').forEach(function (field) {
				if (!field.value || !field.value.trim()) {
					field.classList.add('bzcc-field--error');
					field.style.borderColor = '#ef4444';
					allInvalid = true;

					field.addEventListener('input', function handler() {
						field.classList.remove('bzcc-field--error');
						field.style.borderColor = '';
						field.removeEventListener('input', handler);
					});
				}
			});
			if (allInvalid) return;

			// Collect form data
			var formData = new FormData(form);
			var data     = {};
			formData.forEach(function (value, key) {
				// Handle checkbox arrays (e.g. platforms[])
				if (key.endsWith('[]')) {
					var realKey = key.slice(0, -2);
					if (!data[realKey]) data[realKey] = [];
					data[realKey].push(value);
				} else if (key !== 'bzcc_nonce' && key !== '_wp_http_referer') {
					data[key] = value;
				}
			});

			// Include image upload data (attachment_id and url)
			form.querySelectorAll('.bzcc-image-upload').forEach(function (wrap) {
				var idInput  = wrap.querySelector('[name$="_attachment_id"]');
				var urlInput = wrap.querySelector('[name$="_url"]');
				if (idInput && idInput.value) {
					data[idInput.name] = idInput.value;
				}
				if (urlInput && urlInput.value) {
					data[urlInput.name] = urlInput.value;
				}
			});

			// Include file upload data (attachment_id and filename)
			form.querySelectorAll('.bzcc-file-upload').forEach(function (wrap) {
				var idInput   = wrap.querySelector('[name$="_attachment_id"]');
				var nameInput = wrap.querySelector('[name$="_filename"]');
				if (idInput && idInput.value) {
					data[idInput.name] = idInput.value;
				}
				if (nameInput && nameInput.value) {
					data[nameInput.name] = nameInput.value;
				}
			});

			// Show loading
			var loading = document.getElementById('bzcc-loading');
			if (loading) loading.style.display = '';

			// Disable submit button
			var submitBtn = document.getElementById('bzcc-btn-submit');
			if (submitBtn) submitBtn.disabled = true;

			// POST to AJAX
			var xhr = new XMLHttpRequest();
			xhr.open('POST', (window.bzccFront && window.bzccFront.ajaxUrl) || '/wp-admin/admin-ajax.php');
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

			var body = 'action=bzcc_submit_form'
				+ '&nonce=' + encodeURIComponent(formData.get('bzcc_nonce') || '')
				+ '&template_id=' + encodeURIComponent(data.template_id || '')
				+ '&form_data=' + encodeURIComponent(JSON.stringify(data));

			// Include webchat session_id if present (iframe mode)
			var sessionId = (window.bzccFront && window.bzccFront.sessionId) || '';
			if (sessionId) {
				body += '&session_id=' + encodeURIComponent(sessionId);
			}

			xhr.onload = function () {
				if (loading) loading.style.display = 'none';
				if (submitBtn) submitBtn.disabled = false;

				try {
					var res = JSON.parse(xhr.responseText);
					if (res.success && res.data && res.data.redirect) {
						window.location.href = res.data.redirect;
					} else if (res.success) {
						alert('Nội dung đã được tạo thành công!');
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Có lỗi xảy ra, vui lòng thử lại.');
					}
				} catch (err) {
					alert('Có lỗi xảy ra, vui lòng thử lại.');
				}
			};

			xhr.onerror = function () {
				if (loading) loading.style.display = 'none';
				if (submitBtn) submitBtn.disabled = false;
				alert('Không thể kết nối server, vui lòng thử lại.');
			};

			xhr.send(body);
		});
	}

	/* ═══════════════════════════════════════
	 *  Result View — SSE Streaming + Interactions
	 * ═══════════════════════════════════════ */
	function initResultView() {
		var result   = document.getElementById('bzcc-result');
		if (!result) return;

		var fileId     = parseInt(result.getAttribute('data-file-id'), 10);
		var fileStatus = result.getAttribute('data-file-status');
		var restUrl    = (window.bzccFront && window.bzccFront.restUrl) || '/wp-json/bzcc/v1';
		var nonce      = (window.bzccFront && window.bzccFront.nonce) || '';

		console.log('[BZCC] initResultView | fileId=' + fileId + ' | status=' + fileStatus + ' | restUrl=' + restUrl);

		// Init interactions that work immediately
		initStepperCollapse();
		initPlatformTabs();
		initStageFilter();
		initActionButtons(restUrl, nonce);
		initCardCheckbox();
		initChatbar(fileId, restUrl, nonce);

		// Render mermaid diagrams in already-completed content
		if (fileStatus === 'completed') {
			renderMermaidDiagrams(result);
		}

		// If pending, trigger generation → SSE stream
		if (fileStatus === 'pending') {
			triggerGenerate(fileId, restUrl, nonce);
		} else if (fileStatus === 'generating') {
			// Resume SSE for in-progress file
			startSSE(fileId, restUrl, nonce);
		}
	}

	/* ── Chat prompt bar: continue generating ── */
	function initChatbar(fileId, restUrl, nonce) {
		var bar   = document.getElementById('bzcc-chatbar');
		var input = document.getElementById('bzcc-chatbar-input');
		var btn   = document.getElementById('bzcc-chatbar-send');
		if (!bar || !input || !btn) return;

		// Auto-resize textarea
		input.addEventListener('input', function () {
			this.style.height = 'auto';
			this.style.height = Math.min(this.scrollHeight, 160) + 'px';
			btn.disabled = !this.value.trim();
		});

		// Enter to send (Shift+Enter for newline)
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				if (!btn.disabled) btn.click();
			}
		});

		btn.addEventListener('click', function () {
			var prompt = input.value.trim();
			if (!prompt || btn.disabled) return;

			var tone   = (document.getElementById('bzcc-chatbar-tone') || {}).value || '';
			var length = (document.getElementById('bzcc-chatbar-length') || {}).value || '';

			btn.disabled = true;
			input.disabled = true;
			btn.innerHTML = '<span class="bzcc-spinner"></span>';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', restUrl + '/file/' + fileId + '/continue');
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-WP-Nonce', nonce);

			xhr.onload = function () {
				btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4 20-7Z"/></svg>';
				input.disabled = false;
				input.value = '';
				input.style.height = 'auto';
				btn.disabled = true;

				try {
					var res = JSON.parse(xhr.responseText);
					if (res.error) {
						showToast(res.error, 'error');
						return;
					}
					if (res.new_sections && res.new_sections.length) {
						appendStepperNodes(res.new_sections, res.start_index);
					}
					if (res.chunks && res.chunks.length) {
						streamChunksParallel(res.chunks, fileId, restUrl, nonce);
					}
				} catch (e) {
					showToast('Lỗi khi xử lý phản hồi.', 'error');
				}
			};

			xhr.onerror = function () {
				btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4 20-7Z"/></svg>';
				input.disabled = false;
				btn.disabled = false;
				showToast('Lỗi kết nối.', 'error');
			};

			xhr.send(JSON.stringify({ prompt: prompt, tone: tone, length: length }));
		});
	}

	/* ── Append new stepper nodes from continue-generate ── */
	function appendStepperNodes(sections, startIndex) {
		var stepper = document.getElementById('bzcc-stepper');
		if (!stepper) return;

		sections.forEach(function (section, i) {
			var idx  = startIndex + i;
			var node = document.createElement('div');
			node.className = 'bzcc-stepper-node bzcc-stepper-node--queued';
			node.setAttribute('data-chunk-index', idx);
			node.setAttribute('data-platform', section.platform || 'general');

			var header = document.createElement('div');
			header.className = 'bzcc-stepper-node__header';
			header.innerHTML =
				'<span class="bzcc-stepper-node__icon">' + (section.emoji || '📝') + '</span>' +
				'<span class="bzcc-stepper-node__label">' + (section.label || 'Phần ' + (idx + 1)) + '</span>' +
				'<span class="bzcc-stepper-node__status">Đang chờ...</span>';

			var body = document.createElement('div');
			body.className = 'bzcc-stepper-node__body';

			var content = document.createElement('div');
			content.className = 'bzcc-stepper-node__content bzcc-prose';

			// Skeleton placeholder
			content.innerHTML =
				'<div class="bzcc-skeleton"><div class="bzcc-skeleton__line" style="width:90%"></div>' +
				'<div class="bzcc-skeleton__line" style="width:75%"></div>' +
				'<div class="bzcc-skeleton__line" style="width:60%"></div></div>';

			body.appendChild(content);
			node.appendChild(header);
			node.appendChild(body);
			stepper.appendChild(node);
		});

		// Scroll to first new node
		var firstNew = stepper.querySelector('[data-chunk-index="' + startIndex + '"]');
		if (firstNew) firstNew.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	/* ── Trigger generation pipeline ── */
	function triggerGenerate(fileId, restUrl, nonce) {
		console.log('[BZCC] triggerGenerate | fileId=' + fileId);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', restUrl + '/file/' + fileId + '/generate');
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', nonce);

		xhr.onload = function () {
			console.log('[BZCC] generate response:', xhr.status, xhr.responseText.substring(0, 300));
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.status === 'generating' || res.outline) {
					// Update file status attribute
					var result = document.getElementById('bzcc-result');
					if (result) result.setAttribute('data-file-status', 'generating');

					// Build stepper from outline
					if (res.outline && res.outline.length) {
						buildStepperFromOutline(res.outline);
					}

					// Use parallel streaming if chunk IDs are available
					if (res.chunks && res.chunks.length) {
						console.log('[BZCC] Using parallel streaming for', res.chunks.length, 'chunks');
						streamChunksParallel(res.chunks, fileId, restUrl, nonce);
					} else {
						// Fallback to sequential SSE stream
						console.log('[BZCC] Falling back to sequential SSE stream');
						startSSE(fileId, restUrl, nonce);
					}
				} else if (res.message) {
					showToast(res.message, 'info');
				}
			} catch (e) {
				showToast('Có lỗi xảy ra khi khởi tạo nội dung.', 'error');
			}
		};

		xhr.onerror = function () {
			showToast('Không thể kết nối server.', 'error');
		};

		xhr.send(JSON.stringify({}));
	}

	/* ── Build stepper nodes from outline (before SSE starts) ── */
	function buildStepperFromOutline(outline) {
		console.log('[BZCC] buildStepperFromOutline | sections=' + outline.length, outline);
		var stepper     = document.getElementById('bzcc-stepper');
		var placeholder = document.getElementById('bzcc-stepper-placeholder');
		if (!stepper) return;
		if (placeholder) placeholder.remove();

		var platformIcons = {
			facebook: '📘', tiktok: '🎵', instagram: '📸', youtube: '▶️',
			zalo: '💬', email: '📧', image: '🖼️', video: '🎬',
			general: '📄', website: '🌐'
		};
		var platformLabels = {
			facebook: 'Facebook', tiktok: 'TikTok', instagram: 'Instagram',
			youtube: 'YouTube Short', zalo: 'Zalo/SMS', email: 'Email',
			image: 'Ảnh QC', video: 'Video',
			general: '', website: 'Website'
		};

		outline.forEach(function (section, i) {
			var node = document.createElement('div');
			node.className = 'bzcc-stepper-node';
			node.setAttribute('data-chunk-index', i);
			node.setAttribute('data-chunk-id', '0');

			// Hide platform badge for document-mode content (general platform)
			var platHtml = '';
			var platLabel = platformLabels[section.platform] !== undefined ? platformLabels[section.platform] : section.platform;
			if (section.platform && section.platform !== 'general' && platLabel) {
				platHtml = '<span class="bzcc-stepper-node__platform">' +
					escHtml(platformIcons[section.platform] || '') + ' ' +
					escHtml(platLabel) +
					'</span>';
			}

			node.innerHTML =
				'<div class="bzcc-stepper-node__header" data-toggle="collapse">' +
					'<div class="bzcc-stepper-node__icon">' +
						'<span class="bzcc-stepper-node__num">' + (i + 1) + '</span>' +
					'</div>' +
					'<span class="bzcc-stepper-node__label">' +
						escHtml(section.emoji || '📝') + ' ' +
						escHtml(section.label || ('Phần ' + (i + 1))) +
					'</span>' +
					platHtml +
					'<svg class="bzcc-stepper-node__arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>' +
				'</div>' +
				'<div class="bzcc-stepper-node__progress">' +
					'<div class="bzcc-stepper-node__bar" style="width:0%;"></div>' +
				'</div>' +
				'<div class="bzcc-stepper-node__body bzcc-collapsed">' +
					'<div class="bzcc-stepper-node__content" id="bzcc-chunk-' + i + '"></div>' +
				'</div>';

			stepper.appendChild(node);
		});

		// Re-init collapse for new nodes
		initStepperCollapse();
	}

	/* ── SSE Event Stream (fallback sequential mode) ── */
	function startSSE(fileId, restUrl, nonce) {
		var chunkRawContent = {};
		// EventSource doesn't support custom headers, pass nonce via query
		var url = restUrl + '/file/' + fileId + '/stream?_wpnonce=' + encodeURIComponent(nonce);
		console.log('[BZCC] startSSE | url=' + url);
		var es  = new EventSource(url);

		es.addEventListener('outline', function (e) {
			console.log('[BZCC] SSE outline:', e.data.substring(0, 200));
			try {
				var data = JSON.parse(e.data);
				console.log('[BZCC] Outline sections:', data.outline ? data.outline.length : 0, '| chunks:', data.chunk_count);
				if (data.outline && data.outline.length) {
					buildStepperFromOutline(data.outline);
				}
				updateHeader('loading', 'AI đang tạo nội dung...', 'Vui lòng chờ trong giây lát');
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_start', function (e) {
			console.log('[BZCC] SSE chunk_start:', e.data.substring(0, 200));
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (!node) return;

				node.classList.add('bzcc-stepper-node--active');
				node.classList.remove('bzcc-stepper-node--done');
				if (data.chunk_id) node.setAttribute('data-chunk-id', data.chunk_id);

				// Show spinner icon
				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<div class="bzcc-spinner-sm"></div>';

				// Expand body, show typing indicator
				var body    = node.querySelector('.bzcc-stepper-node__body');
				var content = node.querySelector('.bzcc-stepper-node__content');
				if (body) body.classList.remove('bzcc-collapsed');
				if (content) content.innerHTML = '<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>';

				// Animate progress bar to 20%
				var bar = node.querySelector('.bzcc-stepper-node__bar');
				if (bar) bar.style.width = '20%';

				// Scroll node into view
				node.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_delta', function (e) {
			try {
				var data    = JSON.parse(e.data);
				console.log('[BZCC] SSE chunk_delta #' + data.chunk_index + ' | len=' + (data.delta || '').length);
				var node    = findNode(data.chunk_index);
				var content = node ? node.querySelector('.bzcc-stepper-node__content') : null;
				if (!content) return;

				// Remove typing indicator on first delta
				var typing = content.querySelector('.bzcc-typing-indicator');
				if (typing) typing.remove();

				// Accumulate and render markdown
				if (!chunkRawContent[data.chunk_index]) chunkRawContent[data.chunk_index] = '';
				chunkRawContent[data.chunk_index] += (data.delta || '');
				content.innerHTML = simpleMarkdown(stripPromptPreamble(chunkRawContent[data.chunk_index]));

				// Gradually increase progress bar (20% → 90%)
				var bar     = node.querySelector('.bzcc-stepper-node__bar');
				var current = parseFloat(bar ? bar.style.width : '20') || 20;
				if (current < 90 && bar) {
					bar.style.width = Math.min(current + 2, 90) + '%';
				}
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_error', function (e) {
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (!node) return;

				node.classList.remove('bzcc-stepper-node--active');
				node.classList.add('bzcc-stepper-node--error');

				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';

				var bar = node.querySelector('.bzcc-stepper-node__bar');
				if (bar) { bar.style.width = '100%'; bar.style.background = '#ef4444'; }

				var content = node.querySelector('.bzcc-stepper-node__content');
				if (content) {
					var typing = content.querySelector('.bzcc-typing-indicator');
					if (typing) typing.remove();
					content.insertAdjacentHTML('beforeend', '<div class="bzcc-chunk-error">⚠️ ' + escHtml(data.error || 'Lỗi tạo nội dung') + '</div>');
				}

				showRetryButton(node);
				showToast('Lỗi tạo nội dung phần ' + (data.chunk_index + 1), 'error');
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_done', function (e) {
			console.log('[BZCC] SSE chunk_done:', e.data.substring(0, 200));
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (!node) return;

				node.classList.remove('bzcc-stepper-node--active');
				node.classList.add('bzcc-stepper-node--done');
				if (data.chunk_id) node.setAttribute('data-chunk-id', data.chunk_id);

				// Show check icon
				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>';

				// Complete progress bar
				var bar = node.querySelector('.bzcc-stepper-node__bar');
				if (bar) bar.style.width = '100%';

				// Final markdown render
				var contentEl = node.querySelector('.bzcc-stepper-node__content');
				var raw = chunkRawContent[data.chunk_index] || '';
				if (contentEl && raw) contentEl.innerHTML = simpleMarkdown(raw);

				// Render any mermaid diagrams in this chunk
				renderMermaidDiagrams(node);

				// Add action buttons
				addChunkActions(node);

				// Collapse completed node (keep last visible)
				setTimeout(function () {
					var bodyEl = node.querySelector('.bzcc-stepper-node__body');
					if (bodyEl) bodyEl.classList.add('bzcc-collapsed');
				}, 800);
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('done', function (e) {
			console.log('[BZCC] SSE done:', e.data);
			es.close();

			try {
				var data = JSON.parse(e.data);
				updateHeader('done');

				// Update data attribute
				var result = document.getElementById('bzcc-result');
				if (result) result.setAttribute('data-file-status', 'completed');

				// Show export button
				showExportButtons();

				// Render mermaid diagrams
				renderMermaidDiagrams();

				// Reload full page data to show platform tabs
				loadAndRenderPlatforms(data.file_id);
			} catch (err) { /* ignore */ }
		});

		es.onerror = function (err) {
			console.error('[BZCC] SSE error:', err);
			es.close();
			showToast('Kết nối bị gián đoạn. Tải lại trang để xem kết quả.', 'error');
		};
	}

	/* ── Load file data and render platform tabs ── */
	function loadAndRenderPlatforms(fileId) {
		var restUrl = (window.bzccFront && window.bzccFront.restUrl) || '/wp-json/bzcc/v1';
		var nonce   = (window.bzccFront && window.bzccFront.nonce) || '';

		var xhr = new XMLHttpRequest();
		xhr.open('GET', restUrl + '/file/' + fileId + '?_wpnonce=' + encodeURIComponent(nonce));
		xhr.setRequestHeader('X-WP-Nonce', nonce);

		xhr.onload = function () {
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.chunks && res.chunks.length) {
					renderPlatformTabs(res.chunks);
				}
			} catch (e) { /* ignore */ }
		};

		xhr.send();
	}

	/* ── Render platform tabs from chunks data ── */
	function renderPlatformTabs(chunks) {
		var container = document.getElementById('bzcc-platforms');
		if (!container) {
			container = document.createElement('div');
			container.className = 'bzcc-platforms';
			container.id = 'bzcc-platforms';
			var stepper = document.getElementById('bzcc-stepper');
			if (stepper) stepper.after(container);
		}
		container.style.display = '';

		var platformIcons = {
			facebook: '📘', tiktok: '🎵', instagram: '📸', youtube: '▶️',
			zalo: '💬', email: '📧', image: '🖼️', video: '🎬'
		};
		var platformLabels = {
			facebook: 'Facebook', tiktok: 'TikTok', instagram: 'Instagram',
			youtube: 'YouTube Short', zalo: 'Zalo/SMS', email: 'Email',
			image: 'Ảnh QC', video: 'Video'
		};
		var stageLabels = {
			awareness: ['👁️', 'Nhận biết'], interest: ['💡', 'Quan tâm'],
			trust: ['🤝', 'Tin tưởng'], action: ['🎯', 'Hành động'],
			loyalty: ['❤️', 'Trung thành']
		};

		// Group by platform
		var grouped = {};
		chunks.forEach(function (c) {
			var p = c.platform || 'general';
			if (!grouped[p]) grouped[p] = [];
			grouped[p].push(c);
		});

		var platforms = Object.keys(grouped);
		if (!platforms.length) return;

		// Stage filter
		var stageHtml =
			'<div class="bzcc-stage-filter">' +
				'<span class="bzcc-stage-filter__label">' +
					'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z"/></svg> Giai đoạn:' +
				'</span>' +
				'<div class="bzcc-stage-pills">' +
					'<button class="bzcc-pill bzcc-pill--active" data-stage="all">Tất cả</button>';

		Object.keys(stageLabels).forEach(function (key) {
			stageHtml += '<button class="bzcc-pill" data-stage="' + key + '">' +
				stageLabels[key][0] + ' ' + stageLabels[key][1] + '</button>';
		});
		stageHtml += '</div></div>';

		// Tab bar
		var tabHtml = '<div class="bzcc-tab-bar" role="tablist">';
		platforms.forEach(function (p, idx) {
			tabHtml += '<button class="bzcc-tab' + (idx === 0 ? ' bzcc-tab--active' : '') + '" role="tab" data-platform="' + escAttr(p) + '" aria-selected="' + (idx === 0 ? 'true' : 'false') + '">' +
				'<span class="bzcc-tab__icon">' + escHtml(platformIcons[p] || '📄') + '</span>' +
				'<span class="bzcc-tab__label">' + escHtml(platformLabels[p] || p) + '</span>' +
				'<span class="bzcc-tab__count">' + grouped[p].length + '</span>' +
				'</button>';
		});
		tabHtml += '</div>';

		// Tab panels
		var panelsHtml = '';
		platforms.forEach(function (p, idx) {
			panelsHtml += '<div class="bzcc-tab-panel' + (idx === 0 ? ' bzcc-tab-panel--active' : '') + '" data-platform="' + escAttr(p) + '" role="tabpanel">';
			panelsHtml += '<h4 class="bzcc-panel-title">' + escHtml(platformIcons[p] || '📄') + ' ' +
				escHtml(platformLabels[p] || p) + ' (' + grouped[p].length + ')</h4>';

			grouped[p].forEach(function (chunk, ci) {
				var stageKey = '';
				var stageBadge = '';
				if (chunk.stage_label) {
					Object.keys(stageLabels).forEach(function (sk) {
						if (stageLabels[sk][1] === chunk.stage_label) stageKey = sk;
					});
					stageBadge = '<span class="bzcc-stage-badge bzcc-stage-badge--' + escAttr(stageKey) + '">' +
						escHtml(chunk.stage_emoji || '') + ' ' + escHtml(chunk.stage_label) + '</span>';
				}

				panelsHtml += '<div class="bzcc-post-card bzcc-post-card--' + escAttr(p) + '" data-chunk-id="' + chunk.id + '" data-stage="' + escAttr(chunk.stage_label || '') + '">' +
					'<div class="bzcc-post-card__header"><div class="bzcc-post-card__meta">' +
						'<span class="bzcc-badge bzcc-badge--outline">' + escHtml(chunk.format || 'text') + '</span>' +
						'<span class="bzcc-post-card__num">Post ' + (ci + 1) + '</span>' +
						stageBadge +
					'</div></div>' +
					'<div class="bzcc-post-card__content">' + simpleMarkdown(chunk.content || '') + '</div>';

				if (chunk.hashtags) {
					panelsHtml += '<div class="bzcc-post-card__hashtags">';
					chunk.hashtags.split(',').forEach(function (tag) {
						panelsHtml += '<span class="bzcc-hashtag">' + escHtml(tag.trim()) + '</span>';
					});
					panelsHtml += '</div>';
				}
				if (chunk.cta_text) {
					panelsHtml += '<span class="bzcc-post-card__cta">' + escHtml(chunk.cta_text) + '</span>';
				}

				panelsHtml += '<div class="bzcc-post-card__actions">' +
					'<button class="bzcc-action-btn" data-action="copy"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg> Copy</button>' +
					'<button class="bzcc-action-btn" data-action="edit"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg> Chỉnh sửa</button>' +
					'<button class="bzcc-action-btn" data-action="schedule"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Lên Lịch</button>' +
					'<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-image"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg> Tạo ảnh</button>' +
					'<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="save"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Lưu Kho</button>' +
					'</div>';

				if (chunk.image_url) {
					panelsHtml += '<div class="bzcc-post-card__image"><img src="' + escAttr(chunk.image_url) + '" alt="Generated" loading="lazy"></div>';
				}

				panelsHtml += '</div>';
			});

			// Add more button
			panelsHtml += '<button class="bzcc-add-more-btn" data-platform="' + escAttr(p) + '">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>' +
				' Tạo thêm nội dung ' + escHtml(platformLabels[p] || p) +
				'</button>';
			panelsHtml += '</div>';
		});

		container.innerHTML = stageHtml + tabHtml + panelsHtml;

		// Re-init interactions for new DOM
		initPlatformTabs();
		initStageFilter();
		initActionButtons(
			(window.bzccFront && window.bzccFront.restUrl) || '/wp-json/bzcc/v1',
			(window.bzccFront && window.bzccFront.nonce) || ''
		);

		// Render mermaid in platform tab content
		renderMermaidDiagrams(container);
	}

	/* ── Stepper collapse toggle ── */
	function initStepperCollapse() {
		document.querySelectorAll('.bzcc-stepper-node__header[data-toggle="collapse"]').forEach(function (header) {
			// Avoid duplicate listeners
			if (header._bzccCollapse) return;
			header._bzccCollapse = true;

			header.addEventListener('click', function () {
				var node = header.closest('.bzcc-stepper-node');
				if (!node) return;
				var body = node.querySelector('.bzcc-stepper-node__body');
				if (!body) return;
				body.classList.toggle('bzcc-collapsed');
				var arrow = header.querySelector('.bzcc-stepper-node__arrow');
				if (arrow) arrow.style.transform = body.classList.contains('bzcc-collapsed') ? '' : 'rotate(180deg)';
			});
		});
	}

	/* ── Platform Tab Switching ── */
	function initPlatformTabs() {
		document.querySelectorAll('.bzcc-tab-bar').forEach(function (bar) {
			var tabs   = bar.querySelectorAll('.bzcc-tab');
			var panels = bar.parentElement.querySelectorAll('.bzcc-tab-panel');

			tabs.forEach(function (tab) {
				// Avoid duplicate listeners
				if (tab._bzccTab) return;
				tab._bzccTab = true;

				tab.addEventListener('click', function () {
					var platform = tab.getAttribute('data-platform');

					tabs.forEach(function (t) {
						t.classList.remove('bzcc-tab--active');
						t.setAttribute('aria-selected', 'false');
					});
					tab.classList.add('bzcc-tab--active');
					tab.setAttribute('aria-selected', 'true');

					panels.forEach(function (p) {
						var show = p.getAttribute('data-platform') === platform;
						p.classList.toggle('bzcc-tab-panel--active', show);
					});
				});
			});
		});
	}

	/* ── Stage Filter Pills ── */
	function initStageFilter() {
		document.querySelectorAll('.bzcc-stage-filter').forEach(function (filter) {
			var pills = filter.querySelectorAll('.bzcc-pill');
			pills.forEach(function (pill) {
				if (pill._bzccPill) return;
				pill._bzccPill = true;

				pill.addEventListener('click', function () {
					var stage = pill.getAttribute('data-stage');

					pills.forEach(function (p) { p.classList.remove('bzcc-pill--active'); });
					pill.classList.add('bzcc-pill--active');

					// Filter post cards in active panel
					var activePanel = document.querySelector('.bzcc-tab-panel--active');
					if (!activePanel) return;

					activePanel.querySelectorAll('.bzcc-post-card').forEach(function (card) {
						if (stage === 'all') {
							card.style.display = '';
						} else {
							var cardStage = card.getAttribute('data-stage') || '';
							// Match by Vietnamese label or key
							var stageLabels = {
								awareness: 'Nhận biết', interest: 'Quan tâm',
								trust: 'Tin tưởng', action: 'Hành động', loyalty: 'Trung thành'
							};
							var matchLabel = stageLabels[stage] || stage;
							card.style.display = (cardStage === matchLabel || cardStage === stage) ? '' : 'none';
						}
					});
				});
			});
		});
	}

	/* ── Action Buttons (event delegation) ── */
	function initActionButtons(restUrl, nonce) {
		var result = document.getElementById('bzcc-result');
		if (!result || result._bzccActions) return;
		result._bzccActions = true;

		result.addEventListener('click', function (e) {
			var btn = e.target.closest('.bzcc-action-btn');
			if (!btn) return;

			var action  = btn.getAttribute('data-action');
			var card    = btn.closest('.bzcc-post-card') || btn.closest('.bzcc-stepper-node');
			var chunkId = card ? (card.getAttribute('data-chunk-id') || '0') : '0';

			switch (action) {
				case 'copy':
					handleCopy(card, btn);
					break;
				case 'edit':
					handleEdit(card, chunkId, restUrl, nonce);
					break;
				case 'regenerate':
					handleRegenerate(card, chunkId, restUrl, nonce);
					break;
				case 'schedule':
					showToast('Tính năng Lên Lịch sẽ sớm ra mắt!', 'info');
					break;
				case 'gen-image':
					handleGenImage(card, chunkId, restUrl, nonce);
					break;
				case 'gen-video':
					handleGenVideo(card, chunkId, restUrl, nonce);
					break;
				case 'gen-mindmap':
					handleMindmap(card);
					break;
				case 'save':
					handleSave(chunkId, restUrl, nonce, btn);
					break;
				case 'download-image':
					handleDownloadImage(card);
					break;
				case 'retry':
					handleRetryChunk(card, chunkId, restUrl, nonce);
					break;
			}
		});
	}

	/* ── Copy content to clipboard ── */
	function handleCopy(card, btn) {
		var contentEl = card.querySelector('.bzcc-post-card__content') ||
		                card.querySelector('.bzcc-stepper-node__content');
		if (!contentEl) return;

		var text = contentEl.innerText || contentEl.textContent;

		// Include hashtags if present
		var hashtags = card.querySelector('.bzcc-post-card__hashtags');
		if (hashtags) text += '\n' + hashtags.innerText;

		// Include CTA
		var cta = card.querySelector('.bzcc-post-card__cta');
		if (cta) text += '\n' + cta.innerText;

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				showCopyFeedback(btn);
			});
		} else {
			// Fallback
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			ta.remove();
			showCopyFeedback(btn);
		}
	}

	function showCopyFeedback(btn) {
		var orig = btn.innerHTML;
		btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Đã copy!';
		btn.style.color = '#22c55e';
		setTimeout(function () {
			btn.innerHTML = orig;
			btn.style.color = '';
		}, 2000);
	}

	/* ── Edit content inline ── */
	function handleEdit(card, chunkId, restUrl, nonce) {
		var contentEl = card.querySelector('.bzcc-post-card__content') ||
		                card.querySelector('.bzcc-stepper-node__content');
		if (!contentEl || contentEl._editing) return;
		contentEl._editing = true;

		var originalHtml = contentEl.innerHTML;
		var originalText = contentEl.innerText || contentEl.textContent;

		// Replace with textarea
		var textarea = document.createElement('textarea');
		textarea.className = 'bzcc-edit-textarea';
		textarea.value = originalText;
		textarea.style.cssText = 'width:100%;min-height:120px;padding:12px;border:2px solid var(--bzcc-primary,#6366f1);border-radius:8px;font-size:14px;line-height:1.6;font-family:inherit;resize:vertical;';

		var toolbar = document.createElement('div');
		toolbar.style.cssText = 'display:flex;gap:8px;margin-top:8px;';
		toolbar.innerHTML =
			'<button class="bzcc-btn bzcc-btn--primary bzcc-edit-save" style="font-size:13px;padding:6px 16px;">Lưu</button>' +
			'<button class="bzcc-btn bzcc-btn--outline bzcc-edit-cancel" style="font-size:13px;padding:6px 16px;">Hủy</button>';

		contentEl.innerHTML = '';
		contentEl.appendChild(textarea);
		contentEl.appendChild(toolbar);
		textarea.focus();

		toolbar.querySelector('.bzcc-edit-cancel').addEventListener('click', function () {
			contentEl.innerHTML = originalHtml;
			contentEl._editing = false;
		});

		toolbar.querySelector('.bzcc-edit-save').addEventListener('click', function () {
			var newText = textarea.value;
			var xhr = new XMLHttpRequest();
			xhr.open('POST', restUrl + '/chunk/' + chunkId + '/action');
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-WP-Nonce', nonce);

			xhr.onload = function () {
				contentEl.innerHTML = escHtml(newText).replace(/\n/g, '<br>');
				contentEl._editing = false;
				showToast('Đã lưu chỉnh sửa!', 'success');
			};

			xhr.onerror = function () {
				showToast('Không thể lưu. Thử lại sau.', 'error');
			};

			xhr.send(JSON.stringify({ action: 'edit', content: newText }));
		});
	}

	/* ── Regenerate chunk ── */
	function handleRegenerate(card, chunkId, restUrl, nonce) {
		if (!chunkId || chunkId === '0') {
			showToast('Không tìm thấy ID phần nội dung', 'error');
			return;
		}
		handleRetryChunk(card, chunkId, restUrl, nonce);
	}

	/**
	 * Retry a failed/stuck chunk: reset status → re-stream via SSE
	 */
	function handleRetryChunk(card, chunkId, restUrl, nonce) {
		if (!chunkId || chunkId === '0') {
			showToast('Không tìm thấy ID phần nội dung', 'error');
			return;
		}

		var contentEl = card.querySelector('.bzcc-stepper-node__content');
		var chunkIndex = parseInt(card.getAttribute('data-chunk-index'), 10);

		// 1. Reset visual state
		card.classList.remove('bzcc-stepper-node--error', 'bzcc-stepper-node--done');
		card.classList.add('bzcc-stepper-node--active');
		var icon = card.querySelector('.bzcc-stepper-node__icon');
		if (icon) icon.innerHTML = '<div class="bzcc-spinner-sm"></div>';
		var bar = card.querySelector('.bzcc-stepper-node__bar');
		if (bar) { bar.style.width = '10%'; bar.style.background = ''; }
		if (contentEl) contentEl.innerHTML = '<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>';

		// Remove existing action buttons
		var existingActions = card.querySelector('.bzcc-stepper-node__actions');
		if (existingActions) existingActions.remove();

		showToast('Đang tạo lại nội dung...', 'info');

		// 2. Reset chunk status on server
		var xhr = new XMLHttpRequest();
		xhr.open('POST', restUrl + '/chunk/' + chunkId + '/action');
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', nonce);
		xhr.onload = function () {
			if (xhr.status !== 200) {
				card.classList.remove('bzcc-stepper-node--active');
				card.classList.add('bzcc-stepper-node--error');
				if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
				showToast('Không thể reset phần nội dung', 'error');
				showRetryButton(card);
				return;
			}

			// 3. Re-stream via SSE
			retryStreamChunk(chunkId, chunkIndex, card, restUrl, nonce);
		};
		xhr.onerror = function () {
			card.classList.remove('bzcc-stepper-node--active');
			card.classList.add('bzcc-stepper-node--error');
			showToast('Lỗi kết nối server', 'error');
			showRetryButton(card);
		};
		xhr.send(JSON.stringify({ action: 'retry' }));
	}

	/**
	 * Re-stream a single chunk via SSE after reset
	 */
	function retryStreamChunk(chunkId, chunkIndex, card, restUrl, nonce) {
		var rawContent = '';
		var url = restUrl + '/chunk/' + chunkId + '/stream?_wpnonce=' + encodeURIComponent(nonce);
		var es = new EventSource(url);
		var timeoutId = setTimeout(function () {
			es.close();
			markChunkStuck(card, chunkIndex);
		}, 90000);

		es.addEventListener('chunk_start', function () {
			var bar = card.querySelector('.bzcc-stepper-node__bar');
			if (bar) bar.style.width = '20%';
			var contentEl = card.querySelector('.bzcc-stepper-node__content');
			if (contentEl) contentEl.innerHTML = '<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>';
		});

		es.addEventListener('chunk_delta', function (e) {
			clearTimeout(timeoutId); // got data, reset timeout
			timeoutId = setTimeout(function () { es.close(); markChunkStuck(card, chunkIndex); }, 90000);
			try {
				var data = JSON.parse(e.data);
				rawContent += (data.delta || '');
				var contentEl = card.querySelector('.bzcc-stepper-node__content');
				if (!contentEl) return;
				var typing = contentEl.querySelector('.bzcc-typing-indicator');
				if (typing) typing.remove();
				contentEl.innerHTML = simpleMarkdown(stripPromptPreamble(rawContent));
				var bar = card.querySelector('.bzcc-stepper-node__bar');
				var current = parseFloat(bar ? bar.style.width : '20') || 20;
				if (current < 90 && bar) bar.style.width = Math.min(current + 2, 90) + '%';
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_error', function (e) {
			clearTimeout(timeoutId);
			es.close();
			card.classList.remove('bzcc-stepper-node--active');
			card.classList.add('bzcc-stepper-node--error');
			var icon = card.querySelector('.bzcc-stepper-node__icon');
			if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
			var bar = card.querySelector('.bzcc-stepper-node__bar');
			if (bar) { bar.style.width = '100%'; bar.style.background = '#ef4444'; }
			showToast('Lỗi tạo lại nội dung', 'error');
			showRetryButton(card);
		});

		es.addEventListener('chunk_done', function (e) {
			clearTimeout(timeoutId);
			es.close();
			card.classList.remove('bzcc-stepper-node--active');
			card.classList.add('bzcc-stepper-node--done');
			var icon = card.querySelector('.bzcc-stepper-node__icon');
			if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>';
			var bar = card.querySelector('.bzcc-stepper-node__bar');
			if (bar) bar.style.width = '100%';
			var contentEl = card.querySelector('.bzcc-stepper-node__content');
			if (contentEl && rawContent) contentEl.innerHTML = simpleMarkdown(stripPromptPreamble(rawContent));
			addChunkActions(card);
			showToast('Đã tạo lại nội dung thành công!', 'success');
		});

		es.onerror = function () {
			clearTimeout(timeoutId);
			es.close();
			card.classList.remove('bzcc-stepper-node--active');
			card.classList.add('bzcc-stepper-node--error');
			showToast('Mất kết nối khi tạo lại nội dung', 'error');
			showRetryButton(card);
		};
	}

	/**
	 * Show retry button on a failed/stuck chunk node
	 */
	function showRetryButton(node) {
		var body = node.querySelector('.bzcc-stepper-node__body');
		if (!body) return;
		// Remove existing actions first
		var existingActions = body.querySelector('.bzcc-stepper-node__actions');
		if (existingActions) existingActions.remove();

		var actions = document.createElement('div');
		actions.className = 'bzcc-stepper-node__actions';
		actions.innerHTML =
			'<button class="bzcc-action-btn bzcc-action-btn--retry" data-action="retry" title="Thử lại">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>' +
				' Thử lại</button>';
		body.appendChild(actions);
	}

	/**
	 * Mark a chunk as stuck (timeout) and show retry button
	 */
	function markChunkStuck(node, chunkIndex) {
		node.classList.remove('bzcc-stepper-node--active', 'bzcc-stepper-node--queued');
		node.classList.add('bzcc-stepper-node--error');
		var icon = node.querySelector('.bzcc-stepper-node__icon');
		if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
		var bar = node.querySelector('.bzcc-stepper-node__bar');
		if (bar) { bar.style.width = '100%'; bar.style.background = '#f59e0b'; }
		var contentEl = node.querySelector('.bzcc-stepper-node__content');
		if (contentEl) {
			var typing = contentEl.querySelector('.bzcc-typing-indicator');
			if (typing) typing.remove();
			var queue = contentEl.querySelector('.bzcc-queue-label');
			if (queue) queue.remove();
			contentEl.insertAdjacentHTML('beforeend',
				'<div class="bzcc-chunk-error bzcc-chunk-error--timeout">⏱️ Quá thời gian chờ. Bấm "Thử lại" để tiếp tục.</div>');
		}
		showToast('Phần ' + (chunkIndex + 1) + ' quá thời gian — bấm Thử lại', 'warning');
		showRetryButton(node);
	}

	/* ── Save to Kho ── */
	function handleSave(chunkId, restUrl, nonce, btn) {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', restUrl + '/chunk/' + chunkId + '/action');
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', nonce);

		btn.disabled = true;

		xhr.onload = function () {
			btn.disabled = false;
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.success) {
					showToast('Đã lưu vào Kho!', 'success');
					btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Đã lưu';
				} else {
					showToast('Không thể lưu.', 'error');
				}
			} catch (e) {
				showToast('Lỗi khi lưu.', 'error');
			}
		};

		xhr.onerror = function () {
			btn.disabled = false;
			showToast('Không thể kết nối server.', 'error');
		};

		xhr.send(JSON.stringify({ action: 'save', notes: 'Saved from result view' }));
	}

	/* ── Download image ── */
	function handleDownloadImage(card) {
		var img = card.querySelector('.bzcc-post-card__image img');
		if (!img) return;
		var link = document.createElement('a');
		link.href = img.src;
		link.download = 'content-image.png';
		link.click();
	}

	/* ── Generate AI Image for a chunk ── */
	function handleGenImage(card, chunkId, restUrl, nonce) {
		var contentEl = card.querySelector('.bzcc-stepper-node__content') ||
		                card.querySelector('.bzcc-post-card__content');
		if (!contentEl) { showToast('Không tìm thấy nội dung', 'error'); return; }

		var labelEl = card.querySelector('.bzcc-stepper-node__label');
		var title   = labelEl ? labelEl.textContent.replace(/^[\s\S]*?(?=[A-Za-zÀ-ỹ])/, '').trim() : '';

		/* Build a visual-description prompt instead of dumping raw text.
		 * Extract key topics/keywords so the image model focuses on illustration,
		 * not trying to render Vietnamese text (which causes hallucinations). */
		var text = contentEl.innerText.trim();
		var keywords = [];
		// Extract bold phrases **xxx**
		var boldRe = /\*\*([^*]+)\*\*/g, m;
		var html = contentEl.innerHTML || '';
		while ((m = boldRe.exec(html)) !== null) {
			var kw = m[1].replace(/<[^>]*>/g, '').trim();
			if (kw.length > 2 && kw.length < 60) keywords.push(kw);
		}
		// Extract hashtags
		var tagRe = /#([\wÀ-ỹ]+)/g;
		while ((m = tagRe.exec(text)) !== null) {
			if (m[1].length > 2) keywords.push('#' + m[1]);
		}
		// Fallback: first 150 chars
		var brief = text.substring(0, 150).replace(/\n+/g, ' ');
		var topicLine = keywords.length > 0 ? keywords.slice(0, 6).join(', ') : brief;

		var autoPrompt = 'Tạo ảnh minh họa chuyên nghiệp cho bài đăng mạng xã hội.\n' +
			'Chủ đề: ' + (title || 'Nội dung marketing') + '\n' +
			'Từ khóa chính: ' + topicLine + '\n' +
			'Yêu cầu: Ảnh chất lượng cao, màu sắc bắt mắt, bố cục rõ ràng. KHÔNG chèn chữ/text vào ảnh.';

		showGenImageModal({
			chunkId: chunkId,
			card: card,
			contentEl: contentEl,
			prompt: autoPrompt,
			restUrl: restUrl,
			nonce: nonce
		});
	}

	function showGenImageModal(opts) {
		var existing = document.getElementById('bzcc-genimage-modal');
		if (existing) existing.remove();

		var modal = document.createElement('div');
		modal.id = 'bzcc-genimage-modal';
		modal.className = 'bzcc-modal-backdrop';
		modal.innerHTML =
			'<div class="bzcc-modal bzcc-genimage">' +
				'<div class="bzcc-modal__header">' +
					'<h3>🎨 Tạo ảnh AI</h3>' +
					'<button class="bzcc-modal__close" id="bzcc-gi-close">&times;</button>' +
				'</div>' +
				'<div class="bzcc-modal__body">' +
					/* Upload area */
					'<div class="bzcc-gi-section">' +
						'<span class="bzcc-gi-label">Ảnh tham chiếu (tuỳ chọn)</span>' +
						'<div class="bzcc-gi-dropzone" id="bzcc-gi-dropzone">' +
							'<input type="file" accept="image/*" id="bzcc-gi-file" style="display:none">' +
							'<div class="bzcc-gi-dropzone__placeholder" id="bzcc-gi-placeholder">' +
								'<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>' +
								'<span>Kéo thả hoặc nhấn để chọn ảnh</span>' +
							'</div>' +
							'<div class="bzcc-gi-preview" id="bzcc-gi-preview" style="display:none">' +
								'<img id="bzcc-gi-preview-img" src="" alt="Preview">' +
								'<button class="bzcc-gi-preview__remove" id="bzcc-gi-remove" title="Xoá ảnh">&times;</button>' +
							'</div>' +
						'</div>' +
					'</div>' +
					/* Prompt */
					'<div class="bzcc-gi-section">' +
						'<span class="bzcc-gi-label">Prompt mô tả ảnh</span>' +
						'<textarea class="bzcc-gi-textarea" id="bzcc-gi-prompt" rows="4">' + escHtml(opts.prompt) + '</textarea>' +
					'</div>' +
					/* Size */
					'<div class="bzcc-gi-section">' +
						'<span class="bzcc-gi-label">Kích thước</span>' +
						'<select class="bzcc-gi-select" id="bzcc-gi-size">' +
							'<option value="1024x1024" selected>1024×1024 (Vuông)</option>' +
							'<option value="1536x1024">1536×1024 (Ngang)</option>' +
							'<option value="1024x1536">1024×1536 (Dọc)</option>' +
						'</select>' +
					'</div>' +
				'</div>' +
				'<div class="bzcc-modal__footer">' +
					'<button class="bzcc-btn bzcc-btn--outline" id="bzcc-gi-cancel">Huỷ</button>' +
					'<button class="bzcc-btn bzcc-btn--primary" id="bzcc-gi-generate">🎨 Tạo ảnh</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(modal);

		/* ── State ── */
		var selectedFile = null;
		var fileInput    = document.getElementById('bzcc-gi-file');
		var dropzone     = document.getElementById('bzcc-gi-dropzone');
		var placeholder  = document.getElementById('bzcc-gi-placeholder');
		var previewWrap  = document.getElementById('bzcc-gi-preview');
		var previewImg   = document.getElementById('bzcc-gi-preview-img');

		/* ── Drop zone events ── */
		dropzone.addEventListener('click', function (e) {
			if (e.target.id !== 'bzcc-gi-remove') fileInput.click();
		});
		dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('bzcc-gi-dropzone--hover'); });
		dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('bzcc-gi-dropzone--hover'); });
		dropzone.addEventListener('drop', function (e) {
			e.preventDefault(); dropzone.classList.remove('bzcc-gi-dropzone--hover');
			if (e.dataTransfer.files.length) setPreview(e.dataTransfer.files[0]);
		});
		fileInput.addEventListener('change', function () {
			if (fileInput.files.length) setPreview(fileInput.files[0]);
		});
		document.getElementById('bzcc-gi-remove').addEventListener('click', function (e) {
			e.stopPropagation(); clearPreview();
		});

		function setPreview(file) {
			if (!file.type.startsWith('image/')) { showToast('Chỉ chấp nhận file ảnh', 'error'); return; }
			selectedFile = file;
			var reader = new FileReader();
			reader.onload = function (ev) {
				previewImg.src = ev.target.result;
				placeholder.style.display = 'none';
				previewWrap.style.display = 'flex';
			};
			reader.readAsDataURL(file);
		}
		function clearPreview() {
			selectedFile = null; fileInput.value = '';
			previewImg.src = '';
			placeholder.style.display = '';
			previewWrap.style.display = 'none';
		}

		/* ── Close / Cancel ── */
		function closeModal() { modal.remove(); }
		document.getElementById('bzcc-gi-close').addEventListener('click', closeModal);
		document.getElementById('bzcc-gi-cancel').addEventListener('click', closeModal);
		modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

		/* ── Generate ── */
		var _giGenerating = false;
		document.getElementById('bzcc-gi-generate').addEventListener('click', function () {
			if (_giGenerating) return;
			var prompt = document.getElementById('bzcc-gi-prompt').value.trim();
			var size   = document.getElementById('bzcc-gi-size').value;
			if (!prompt) { showToast('Vui lòng nhập prompt', 'error'); return; }

			_giGenerating = true;
			var genBtn = document.getElementById('bzcc-gi-generate');
			genBtn.disabled = true;
			genBtn.textContent = '⏳ Đang tạo ảnh…';

			var formData = new FormData();
			formData.append('action', 'gen-image');
			formData.append('prompt', prompt);
			formData.append('size', size);
			if (selectedFile) formData.append('reference_image', selectedFile);

			fetch(opts.restUrl + '/chunk/' + opts.chunkId + '/action', {
				method: 'POST',
				headers: { 'X-WP-Nonce': opts.nonce },
				body: formData
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success && data.image_url) {
					/* Insert image into chunk content */
					var imgWrap = document.createElement('div');
					imgWrap.className = 'bzcc-chunk-image';
					imgWrap.innerHTML = '<img src="' + escHtml(data.image_url) + '" alt="AI Generated" loading="lazy">';
					opts.contentEl.insertBefore(imgWrap, opts.contentEl.firstChild);
					showToast('Tạo ảnh thành công!', 'success');
					closeModal();
				} else {
					showToast(data.error || 'Tạo ảnh thất bại', 'error');
					genBtn.disabled = false;
					genBtn.textContent = '🎨 Tạo ảnh';
				}
			})
			.catch(function (err) {
				showToast('Lỗi: ' + err.message, 'error');
				genBtn.disabled = false;
				genBtn.textContent = '🎨 Tạo ảnh';
			});
		});
	}

	/* ═══════════════════════════════════════
	 *  Generate Video for chunk (via BizCity Video Kling)
	 * ═══════════════════════════════════════ */
	function handleGenVideo(card, chunkId, restUrl, nonce) {
		var contentEl = card.querySelector('.bzcc-stepper-node__content') ||
		                card.querySelector('.bzcc-post-card__content');
		if (!contentEl) { showToast('Không tìm thấy nội dung', 'error'); return; }

		var labelEl = card.querySelector('.bzcc-stepper-node__label');
		var title   = labelEl ? labelEl.textContent.replace(/^[\s\S]*?(?=[A-Za-zÀ-ỹ])/, '').trim() : '';

		/* Build prompt from chunk text (similar to gen-image) */
		var text = contentEl.innerText.trim();
		var brief = text.substring(0, 200).replace(/\n+/g, ' ');

		var autoPrompt = 'Tạo video B-roll chuyên nghiệp cho bài đăng mạng xã hội.\n' +
			'Chủ đề: ' + (title || 'Nội dung marketing') + '\n' +
			'Nội dung: ' + brief + '\n' +
			'Yêu cầu: Video mượt mà, chuyển động tự nhiên, chất lượng cao.';

		/* Collect available images: chunk image_url + form_data images */
		var availableImages = [];

		// 1) Chunk's generated image
		var chunkImg = card.querySelector('.bzcc-chunk-image img, .bzcc-post-card__image img');
		if (chunkImg && chunkImg.src) {
			availableImages.push({ url: chunkImg.src, label: '🖼️ Ảnh AI đã tạo' });
		}

		// 2) Images from form_data (product images, etc.)
		var result = document.getElementById('bzcc-result');
		if (result && result.getAttribute('data-form-images')) {
			try {
				var formImgs = JSON.parse(result.getAttribute('data-form-images'));
				formImgs.forEach(function (fi) {
					availableImages.push({ url: fi.url, label: '📎 ' + fi.label });
				});
			} catch (e) { /* ignore */ }
		}

		showGenVideoModal({
			chunkId: chunkId,
			card: card,
			contentEl: contentEl,
			prompt: autoPrompt,
			images: availableImages,
			restUrl: restUrl,
			nonce: nonce
		});
	}

	function showGenVideoModal(opts) {
		var existing = document.getElementById('bzcc-genvideo-modal');
		if (existing) existing.remove();

		var modal = document.createElement('div');
		modal.id = 'bzcc-genvideo-modal';
		modal.className = 'bzcc-modal-backdrop';

		/* Build image picker HTML */
		var imgPickerHtml = '';
		if (opts.images && opts.images.length) {
			imgPickerHtml = '<div class="bzcc-gv-section"><span class="bzcc-gv-label">Chọn ảnh nguồn</span>' +
				'<div class="bzcc-gv-img-grid">';
			opts.images.forEach(function (img, idx) {
				imgPickerHtml += '<label class="bzcc-gv-img-option">' +
					'<input type="radio" name="bzcc-gv-img" value="' + escAttr(img.url) + '"' + (idx === 0 ? ' checked' : '') + '>' +
					'<img src="' + escAttr(img.url) + '" alt="' + escAttr(img.label) + '">' +
					'<span>' + escHtml(img.label) + '</span>' +
				'</label>';
			});
			imgPickerHtml += '<label class="bzcc-gv-img-option bzcc-gv-upload-opt">' +
				'<input type="radio" name="bzcc-gv-img" value="__upload__">' +
				'<div class="bzcc-gv-upload-placeholder">📤<br><small>Upload</small></div>' +
				'<span>Upload mới</span>' +
			'</label>';
			imgPickerHtml += '</div>' +
				'<input type="file" accept="image/*" id="bzcc-gv-file" style="display:none">' +
			'</div>';
		} else {
			imgPickerHtml = '<div class="bzcc-gv-section"><span class="bzcc-gv-label">Ảnh nguồn</span>' +
				'<div class="bzcc-gv-dropzone" id="bzcc-gv-dropzone">' +
					'<input type="file" accept="image/*" id="bzcc-gv-file" style="display:none">' +
					'<div class="bzcc-gv-dropzone__placeholder" id="bzcc-gv-placeholder">📤 Kéo thả ảnh hoặc nhấn để chọn</div>' +
					'<div class="bzcc-gv-preview" id="bzcc-gv-preview" style="display:none">' +
						'<img id="bzcc-gv-preview-img" src="" alt="Preview">' +
						'<button class="bzcc-gv-preview__remove" id="bzcc-gv-remove" type="button">&times;</button>' +
					'</div>' +
				'</div>' +
			'</div>';
		}

		modal.innerHTML =
			'<div class="bzcc-modal bzcc-gv-modal-body">' +
				'<div class="bzcc-modal__header">' +
					'<h3>🎬 Tạo Video AI</h3>' +
					'<button class="bzcc-modal__close" id="bzcc-gv-close">&times;</button>' +
				'</div>' +
				'<div class="bzcc-modal__body">' +
				imgPickerHtml +
				'<div class="bzcc-gv-section">' +
					'<span class="bzcc-gv-label">Prompt mô tả video</span>' +
					'<textarea class="bzcc-gv-textarea" id="bzcc-gv-prompt" rows="3">' + escHtml(opts.prompt) + '</textarea>' +
				'</div>' +
				'<div class="bzcc-gv-section bzcc-gv-row">' +
					'<div>' +
						'<span class="bzcc-gv-label">Thời lượng</span>' +
						'<select class="bzcc-gv-select" id="bzcc-gv-duration">' +
							'<option value="5" selected>5 giây</option>' +
							'<option value="10">10 giây</option>' +
							'<option value="15">15 giây</option>' +
							'<option value="20">20 giây</option>' +
						'</select>' +
					'</div>' +
					'<div>' +
						'<span class="bzcc-gv-label">Tỷ lệ</span>' +
						'<select class="bzcc-gv-select" id="bzcc-gv-ratio">' +
							'<option value="9:16" selected>9:16 (TikTok)</option>' +
							'<option value="16:9">16:9 (YouTube)</option>' +
							'<option value="1:1">1:1 (Vuông)</option>' +
						'</select>' +
					'</div>' +
					'<div>' +
						'<span class="bzcc-gv-label">Model</span>' +
						'<select class="bzcc-gv-select" id="bzcc-gv-model">' +
							'<option value="2.6|pro" selected>Kling 2.6 Pro</option>' +
							'<option value="seedance:1.0">SeeDance</option>' +
							'<option value="sora:v1">Sora v1</option>' +
							'<option value="veo:3">Veo 3</option>' +
						'</select>' +
					'</div>' +
				'</div>' +
				/* Inline monitor (hidden until job created) */
				'<div class="bzcc-gv-monitor" id="bzcc-gv-monitor" style="display:none">' +
					'<div class="bzcc-gv-monitor__header">' +
						'<span class="bzcc-gv-monitor__status" id="bzcc-gv-status-badge">⏳ Đang xếp hàng...</span>' +
					'</div>' +
					'<div class="bzcc-gv-monitor__progress">' +
						'<div class="bzcc-gv-monitor__bar" id="bzcc-gv-bar" style="width:0%"></div>' +
					'</div>' +
					'<div class="bzcc-gv-monitor__log" id="bzcc-gv-log"></div>' +
					'<div class="bzcc-gv-monitor__result" id="bzcc-gv-result" style="display:none"></div>' +
				'</div>' +
				'</div>' + /* end .bzcc-modal__body */
				'<div class="bzcc-modal__footer">' +
					'<button class="bzcc-btn bzcc-btn--outline" id="bzcc-gv-cancel">Huỷ</button>' +
					'<button class="bzcc-btn bzcc-btn--primary" id="bzcc-gv-create">🎬 Tạo video</button>' +
				'</div>' +
			'</div>'; /* end .bzcc-modal */

		document.body.appendChild(modal);

		/* ── State ── */
		var selectedImageUrl = '';
		var uploadedFile = null;
		var _gvCreating = false;
		var _gvJobId = 0;
		var _gvPollTimer = null;

		/* Init selected image from radio */
		var checkedRadio = modal.querySelector('input[name="bzcc-gv-img"]:checked');
		if (checkedRadio && checkedRadio.value !== '__upload__') {
			selectedImageUrl = checkedRadio.value;
		}

		/* Image radio change */
		modal.querySelectorAll('input[name="bzcc-gv-img"]').forEach(function (r) {
			r.addEventListener('change', function () {
				if (r.value === '__upload__') {
					document.getElementById('bzcc-gv-file').click();
				} else {
					selectedImageUrl = r.value;
					uploadedFile = null;
				}
			});
		});

		/* File input for upload */
		var fileInput = document.getElementById('bzcc-gv-file');
		if (fileInput) {
			fileInput.addEventListener('change', function () {
				if (!fileInput.files.length) return;
				uploadedFile = fileInput.files[0];
				selectedImageUrl = '';
				/* Show preview if dropzone exists */
				var previewEl = document.getElementById('bzcc-gv-preview');
				var previewImg = document.getElementById('bzcc-gv-preview-img');
				var placeholder = document.getElementById('bzcc-gv-placeholder');
				if (previewEl && previewImg) {
					var reader = new FileReader();
					reader.onload = function (ev) {
						previewImg.src = ev.target.result;
						if (placeholder) placeholder.style.display = 'none';
						previewEl.style.display = 'flex';
					};
					reader.readAsDataURL(uploadedFile);
				}
			});
		}

		/* Dropzone click (when no images available) */
		var dropzone = document.getElementById('bzcc-gv-dropzone');
		if (dropzone) {
			dropzone.addEventListener('click', function (e) {
				if (e.target.id !== 'bzcc-gv-remove') fileInput.click();
			});
		}
		var removeBtn = document.getElementById('bzcc-gv-remove');
		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				uploadedFile = null;
				fileInput.value = '';
				var previewEl = document.getElementById('bzcc-gv-preview');
				var placeholder = document.getElementById('bzcc-gv-placeholder');
				if (previewEl) previewEl.style.display = 'none';
				if (placeholder) placeholder.style.display = '';
			});
		}

		/* ── Close / Cancel ── */
		function closeModal() {
			if (_gvPollTimer) clearInterval(_gvPollTimer);
			modal.remove();
		}
		document.getElementById('bzcc-gv-close').addEventListener('click', closeModal);
		document.getElementById('bzcc-gv-cancel').addEventListener('click', closeModal);
		modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

		/* ── Create Video ── */
		document.getElementById('bzcc-gv-create').addEventListener('click', function () {
			if (_gvCreating) return;
			var prompt   = document.getElementById('bzcc-gv-prompt').value.trim();
			var duration = document.getElementById('bzcc-gv-duration').value;
			var ratio    = document.getElementById('bzcc-gv-ratio').value;
			var model    = document.getElementById('bzcc-gv-model').value;

			if (!prompt && !selectedImageUrl && !uploadedFile) {
				showToast('Cần ít nhất prompt hoặc ảnh', 'error'); return;
			}

			_gvCreating = true;
			var createBtn = document.getElementById('bzcc-gv-create');
			createBtn.disabled = true;
			createBtn.textContent = '⏳ Đang gửi...';

			/* If user uploaded a file, upload to WP first, then create video */
			if (uploadedFile) {
				var upFd = new FormData();
				upFd.append('action', 'bvk_upload_photo');
				upFd.append('nonce', window.bzccFront.bvkNonce || '');
				upFd.append('photo', uploadedFile);

				fetch(window.bzccFront.ajaxUrl, { method: 'POST', body: upFd })
					.then(function (r) { return r.json(); })
					.then(function (upRes) {
						if (upRes.success && upRes.data && upRes.data.url) {
							submitVideoJob(prompt, upRes.data.url, duration, ratio, model);
						} else {
							showToast('Upload ảnh thất bại: ' + (upRes.data && upRes.data.message || 'Unknown'), 'error');
							_gvCreating = false;
							createBtn.disabled = false;
							createBtn.textContent = '🎬 Tạo video';
						}
					})
					.catch(function (err) {
						showToast('Lỗi upload: ' + err.message, 'error');
						_gvCreating = false;
						createBtn.disabled = false;
						createBtn.textContent = '🎬 Tạo video';
					});
			} else {
				submitVideoJob(prompt, selectedImageUrl, duration, ratio, model);
			}
		});

		function submitVideoJob(prompt, imgUrl, duration, ratio, model) {
			var formData = new FormData();
			formData.append('action', 'gen-video');
			formData.append('prompt', prompt);
			formData.append('image_url', imgUrl);
			formData.append('duration', duration);
			formData.append('aspect_ratio', ratio);
			formData.append('model', model);

			fetch(opts.restUrl + '/chunk/' + opts.chunkId + '/action', {
				method: 'POST',
				headers: { 'X-WP-Nonce': opts.nonce },
				body: formData
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success && data.job_id) {
					_gvJobId = data.job_id;
					showMonitor(data);
					startPollJobs();
				} else {
					showToast(data.error || 'Tạo video thất bại', 'error');
					_gvCreating = false;
					var btn = document.getElementById('bzcc-gv-create');
					if (btn) { btn.disabled = false; btn.textContent = '🎬 Tạo video'; }
				}
			})
			.catch(function (err) {
				showToast('Lỗi: ' + err.message, 'error');
				_gvCreating = false;
				var btn = document.getElementById('bzcc-gv-create');
				if (btn) { btn.disabled = false; btn.textContent = '🎬 Tạo video'; }
			});
		}

		function showMonitor(data) {
			/* Hide form, show monitor */
			document.getElementById('bzcc-gv-monitor').style.display = 'block';
			var createBtn = document.getElementById('bzcc-gv-create');
			if (createBtn) createBtn.style.display = 'none';
			var cancelBtn = document.getElementById('bzcc-gv-cancel');
			if (cancelBtn) cancelBtn.textContent = 'Đóng';
			logMsg('✅ Job #' + data.job_id + ' đã tạo — ' + (data.message || 'Đang xếp hàng...'));
		}

		function startPollJobs() {
			_gvPollTimer = setInterval(function () {
				fetch(window.bzccFront.restUrl + '/video/poll', {
					method: 'GET',
					headers: { 'X-WP-Nonce': window.bzccFront.nonce }
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						var jobs = res.jobs || (res.data && res.data.jobs) || [];
						if (!jobs.length) return;
						var job = null;
						jobs.forEach(function (j) {
							if (parseInt(j.id) === _gvJobId) job = j;
						});
						if (!job) return;
						updateMonitor(job);
					})
					.catch(function (err) {
						logMsg('⚠️ Poll error: ' + (err.message || 'unknown'));
					});
			}, 5000); // Poll every 5s
		}

		function updateMonitor(job) {
			var badge = document.getElementById('bzcc-gv-status-badge');
			var bar   = document.getElementById('bzcc-gv-bar');
			var resEl = document.getElementById('bzcc-gv-result');

			var progress = parseInt(job.progress) || 0;
			bar.style.width = progress + '%';

			var statusMap = {
				'queued': '⏳ Đang xếp hàng...',
				'processing': '🔄 Đang xử lý... ' + progress + '%',
				'completed': '✅ Hoàn thành!',
				'failed': '❌ Thất bại'
			};
			badge.textContent = statusMap[job.status] || job.status;
			badge.className = 'bzcc-gv-monitor__status bzcc-gv-st-' + job.status;

			if (job.status === 'completed') {
				clearInterval(_gvPollTimer);
				var videoSrc = job.media_url || job.video_url || '';
				if (videoSrc) {
					resEl.innerHTML = '<video controls autoplay muted playsinline style="width:100%;border-radius:8px;max-height:300px">' +
						'<source src="' + escAttr(videoSrc) + '" type="video/mp4">' +
					'</video>' +
					'<div class="bzcc-gv-result-actions">' +
						'<a href="' + escAttr(videoSrc) + '" target="_blank" class="bzcc-btn bzcc-btn--outline">↗ Mở video</a>' +
					'</div>';
					resEl.style.display = 'block';

					/* Save video_url to chunk_meta */
					saveVideoToChunk(videoSrc, job);
				}
				logMsg('✅ Video hoàn thành: ' + videoSrc.substring(0, 60) + '...');
			} else if (job.status === 'failed') {
				clearInterval(_gvPollTimer);
				logMsg('❌ Thất bại: ' + (job.error_message || 'Unknown'));
				badge.textContent = '❌ ' + (job.error_message || 'Thất bại');
			}
		}

		function saveVideoToChunk(videoSrc, job) {
			/* Call edit-like action to save video_url to chunk_meta */
			var fd2 = new FormData();
			fd2.append('action', 'save-video');
			fd2.append('video_url', videoSrc);
			fd2.append('video_id', job.attachment_id || '0');

			fetch(opts.restUrl + '/chunk/' + opts.chunkId + '/action', {
				method: 'POST',
				headers: { 'X-WP-Nonce': opts.nonce },
				body: fd2
			}).catch(function () { /* silent */ });

			/* Insert video into DOM */
			var videoWrap = document.createElement('div');
			videoWrap.className = 'bzcc-chunk-video';
			videoWrap.innerHTML = '<video controls muted playsinline loading="lazy"><source src="' + escAttr(videoSrc) + '" type="video/mp4"></video>';
			opts.contentEl.insertBefore(videoWrap, opts.contentEl.firstChild);
		}

		function logMsg(msg) {
			var logEl = document.getElementById('bzcc-gv-log');
			if (!logEl) return;
			var now = new Date();
			var time = pad2(now.getHours()) + ':' + pad2(now.getMinutes()) + ':' + pad2(now.getSeconds());
			logEl.innerHTML += '<div class="bzcc-gv-log-line"><span class="bzcc-gv-log-time">[' + time + ']</span> ' + escHtml(msg) + '</div>';
			logEl.scrollTop = logEl.scrollHeight;
		}
		function pad2(n) { return n < 10 ? '0' + n : '' + n; }
	}

	/* ── Generate Mermaid Mindmap from chunk content ── */
	function handleMindmap(card) {
		var contentEl = card.querySelector('.bzcc-stepper-node__content') ||
		                card.querySelector('.bzcc-post-card__content');
		if (!contentEl) return;

		var labelEl = card.querySelector('.bzcc-stepper-node__label');
		var rootLabel = labelEl ? labelEl.textContent.replace(/^[\s\S]*?(?=[A-Za-zÀ-ỹ])/, '').trim() : 'Nội dung';

		// Extract headings and bullets to create mindmap
		var lines = contentEl.innerText.split('\n').filter(function (l) { return l.trim(); });
		var mermaid = 'mindmap\n  root((' + sanitizeMermaid(rootLabel) + '))';
		var currentH2 = '';

		lines.forEach(function (line) {
			var trimmed = line.trim();
			// H2/H3 level headings (bold or numbered)
			if (/^#+\s/.test(trimmed) || /^\d+\.\s/.test(trimmed) || /^\*\*[^*]+\*\*:?\s*$/.test(trimmed)) {
				var heading = trimmed.replace(/^#+\s*/, '').replace(/^\d+\.\s*/, '').replace(/\*\*/g, '').replace(/:$/, '').trim();
				if (heading.length > 2 && heading.length < 80) {
					currentH2 = heading;
					mermaid += '\n    ' + sanitizeMermaid(heading);
				}
			}
			// Bullet items
			else if (/^[-•–]\s/.test(trimmed) && currentH2) {
				var item = trimmed.replace(/^[-•–]\s*/, '').replace(/\*\*/g, '').trim();
				if (item.length > 2 && item.length < 60) {
					mermaid += '\n      ' + sanitizeMermaid(item.substring(0, 50));
				}
			}
		});

		// Show in modal
		showMindmapModal(mermaid, rootLabel);
	}

	function sanitizeMermaid(text) {
		return text.replace(/[()[\]{}]/g, ' ').replace(/"/g, "'").replace(/\n/g, ' ').trim();
	}

	function showMindmapModal(mermaidCode, title) {
		// Remove existing modal
		var existing = document.getElementById('bzcc-mindmap-modal');
		if (existing) existing.remove();

		var modal = document.createElement('div');
		modal.id = 'bzcc-mindmap-modal';
		modal.className = 'bzcc-modal-backdrop';
		modal.innerHTML =
			'<div class="bzcc-modal">' +
				'<div class="bzcc-modal__header">' +
					'<h3>🧠 Mindmap: ' + escHtml(title) + '</h3>' +
					'<button class="bzcc-modal__close" id="bzcc-mindmap-close">&times;</button>' +
				'</div>' +
				'<div class="bzcc-modal__body">' +
					'<div id="bzcc-mermaid-render" class="bzcc-mermaid-container"></div>' +
					'<details class="bzcc-mermaid-source">' +
						'<summary>Xem mã Mermaid</summary>' +
						'<pre><code>' + escHtml(mermaidCode) + '</code></pre>' +
					'</details>' +
				'</div>' +
				'<div class="bzcc-modal__footer">' +
					'<button class="bzcc-btn bzcc-btn--outline" id="bzcc-mindmap-copy">📋 Copy Mermaid</button>' +
					'<button class="bzcc-btn bzcc-btn--primary" id="bzcc-mindmap-close2">Đóng</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(modal);

		// Try to render with Mermaid.js (load CDN if not available)
		var container = document.getElementById('bzcc-mermaid-render');
		if (typeof mermaid !== 'undefined' && mermaid.render) {
			try {
				mermaid.render('bzcc-mm-svg', mermaidCode).then(function (result) {
					container.innerHTML = result.svg;
				});
			} catch (e) {
				container.innerHTML = '<pre>' + escHtml(mermaidCode) + '</pre>';
			}
		} else {
			// Load Mermaid CDN
			var script = document.createElement('script');
			script.src = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
			script.onload = function () {
				window.mermaid.initialize({ startOnLoad: false, theme: 'default' });
				try {
					window.mermaid.render('bzcc-mm-svg', mermaidCode).then(function (result) {
						container.innerHTML = result.svg;
					});
				} catch (e) {
					container.innerHTML = '<pre>' + escHtml(mermaidCode) + '</pre>';
				}
			};
			script.onerror = function () {
				container.innerHTML = '<pre>' + escHtml(mermaidCode) + '</pre>';
			};
			document.head.appendChild(script);
		}

		// Event handlers
		document.getElementById('bzcc-mindmap-close').addEventListener('click', function () { modal.remove(); });
		document.getElementById('bzcc-mindmap-close2').addEventListener('click', function () { modal.remove(); });
		document.getElementById('bzcc-mindmap-copy').addEventListener('click', function () {
			if (navigator.clipboard) {
				navigator.clipboard.writeText(mermaidCode).then(function () {
					showToast('Đã copy mã Mermaid!', 'success');
				});
			}
		});
		modal.addEventListener('click', function (e) {
			if (e.target === modal) modal.remove();
		});
	}

	/* ── Card Checkbox/Radio (strategy picker) ── */
	function initCardCheckbox() {
		document.querySelectorAll('.bzcc-card-options').forEach(function (group) {
			var isMulti = group.classList.contains('bzcc-card-options--multi');
			var cards   = group.querySelectorAll('.bzcc-card-option');

			cards.forEach(function (card) {
				if (card._bzccCard) return;
				card._bzccCard = true;

				card.addEventListener('click', function () {
					var input = card.querySelector('input');
					if (!input) return;

					if (isMulti) {
						// Checkbox mode — toggle
						input.checked = !input.checked;
						card.classList.toggle('bzcc-card-option--selected', input.checked);
					} else {
						// Radio mode — single select
						cards.forEach(function (c) {
							c.classList.remove('bzcc-card-option--selected');
							var inp = c.querySelector('input');
							if (inp) inp.checked = false;
						});
						input.checked = true;
						card.classList.add('bzcc-card-option--selected');
					}
				});
			});
		});
	}

	/* ── Update result header ── */
	function updateHeader(state, title, subtitle) {
		var loadingHeader = document.getElementById('bzcc-result-header-loading');
		var infoHeader    = document.querySelector('.bzcc-result-header--info');

		if (state === 'done') {
			// Hide loading header, show info header
			if (loadingHeader) loadingHeader.style.display = 'none';
			if (infoHeader) infoHeader.style.display = '';
		} else if (state === 'loading') {
			// Show loading header
			if (loadingHeader) {
				loadingHeader.style.display = '';
				var titleEl = loadingHeader.querySelector('.bzcc-result-header__title');
				var subEl   = loadingHeader.querySelector('.bzcc-result-header__sub');
				if (titleEl && title) titleEl.textContent = title;
				if (subEl && subtitle) subEl.textContent = subtitle;
			}
		}
	}

	/* ── Find stepper node by chunk index ── */
	function findNode(index) {
		return document.querySelector('.bzcc-stepper-node[data-chunk-index="' + index + '"]');
	}

	/* ── Toast notification ── */
	function showToast(message, type) {
		type = type || 'info';
		var existing = document.querySelector('.bzcc-toast');
		if (existing) existing.remove();

		var toast = document.createElement('div');
		toast.className = 'bzcc-toast bzcc-toast--' + type;

		var icons = {
			success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>',
			error: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
			info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
		};

		toast.innerHTML = '<span class="bzcc-toast__icon">' + (icons[type] || icons.info) + '</span>' +
			'<span class="bzcc-toast__message">' + escHtml(message) + '</span>';

		document.body.appendChild(toast);

		// Animate in
		requestAnimationFrame(function () {
			toast.classList.add('bzcc-toast--visible');
		});

		// Auto-remove
		setTimeout(function () {
			toast.classList.remove('bzcc-toast--visible');
			setTimeout(function () { toast.remove(); }, 300);
		}, 3500);
	}

	/* ═══════════════════════════════════════
	 *  Mermaid.js rendering utilities
	 * ═══════════════════════════════════════ */
	var _mermaidLoaded = false;
	var _mermaidLoading = false;
	var _mermaidQueue = [];

	function loadMermaid(cb) {
		if (_mermaidLoaded && window.mermaid) { cb(); return; }
		_mermaidQueue.push(cb);
		if (_mermaidLoading) return;
		_mermaidLoading = true;
		var s = document.createElement('script');
		s.src = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
		s.onload = function () {
			_mermaidLoaded = true;
			window.mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'strict' });
			for (var i = 0; i < _mermaidQueue.length; i++) _mermaidQueue[i]();
			_mermaidQueue = [];
		};
		s.onerror = function () { _mermaidLoading = false; console.warn('[BZCC] Failed to load mermaid.js'); };
		document.head.appendChild(s);
	}

	function renderMermaidDiagrams(container) {
		var els = (container || document).querySelectorAll('.bzcc-mermaid:not([data-rendered])');
		if (!els.length) return;
		loadMermaid(function () {
			for (var i = 0; i < els.length; i++) {
				(function (el) {
					var code = el.getAttribute('data-mermaid');
					if (!code) return;
					var id = 'bzcc-mmd-' + Math.random().toString(36).substr(2, 8);
					try {
						window.mermaid.render(id, code).then(function (result) {
							el.innerHTML = result.svg;
							el.setAttribute('data-rendered', '1');
						}).catch(function (err) {
							console.warn('[BZCC] Mermaid render error:', err);
							el.innerHTML = '<pre class="bzcc-code-block"><code>' + escHtml(code) + '</code></pre>';
							el.setAttribute('data-rendered', '1');
						});
					} catch (err) {
						console.warn('[BZCC] Mermaid render error:', err);
					}
				})(els[i]);
			}
		});
	}

	/* ── HTML escape helper ── */
	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	/* ═══════════════════════════════════════
	 *  Strip prompt preamble echoed by LLM
	 * ═══════════════════════════════════════ */
	var PROMPT_HEADERS = /(?:System Prompt|Chunk Prompt|Outline Prompt|YÊU CẦU|QUY TẮC(?:\s+VIẾT)?\s*BẮT BUỘC|BỐI CẢNH|FORMAT|NHẮC LẠI)\s*:/gi;

	function stripPromptPreamble(text) {
		if (!text || !PROMPT_HEADERS.test(text)) return text;
		PROMPT_HEADERS.lastIndex = 0; // reset after test

		// Find where actual content starts: first heading, numbered list, or bullet
		var contentMatch = text.match(/\n(\d+\.\s+\S|#{1,4}\s|[-*•]\s|\*\*[^*])/);
		if (!contentMatch) return text;

		var contentStart = text.indexOf(contentMatch[0]);
		var prefix = text.substring(0, contentStart);

		// Only strip if prompt headers exist in the prefix
		if (PROMPT_HEADERS.test(prefix)) {
			PROMPT_HEADERS.lastIndex = 0;
			return text.substring(contentStart).replace(/^\n+/, '');
		}
		return text;
	}

	/* ═══════════════════════════════════════
	 *  Lightweight Markdown → HTML renderer
	 * ═══════════════════════════════════════ */
	function simpleMarkdown(text) {
		if (!text) return '';

		// ── Extract fenced code blocks BEFORE escaping ──
		var codeBlocks = [];
		text = text.replace(/```(\w*)\n([\s\S]*?)```/g, function (_, lang, code) {
			var idx = codeBlocks.length;
			lang = (lang || '').toLowerCase();
			if (lang === 'mermaid') {
				codeBlocks.push('<div class="bzcc-mermaid" data-mermaid="' + escHtml(code.trim()) + '"><pre class="mermaid">' + escHtml(code.trim()) + '</pre></div>');
			} else {
				codeBlocks.push('<pre class="bzcc-code-block"><code' + (lang ? ' class="language-' + lang + '"' : '') + '>' + escHtml(code) + '</code></pre>');
			}
			return '\x00CODEBLOCK_' + idx + '\x00';
		});

		// Escape HTML (XSS prevention)
		text = escHtml(text);

		// ── Restore code block placeholders ──
		text = text.replace(/\x00CODEBLOCK_(\d+)\x00/g, function (_, idx) {
			return codeBlocks[parseInt(idx)] || '';
		});

		var lines = text.split('\n');
		var html = '';
		var inUl = false, inOl = false, inTable = false;
		var tableRows = [];

		function flushTable() {
			if (!inTable || tableRows.length < 2) {
				if (tableRows.length) html += tableRows.join('<br>');
				tableRows = []; inTable = false;
				return;
			}
			var out = '<div class="bzcc-table-wrap"><table>';
			var isFirst = true;
			for (var r = 0; r < tableRows.length; r++) {
				var row = tableRows[r].trim();
				// Skip separator rows (|---|---|)
				if (/^\|[\s:\-|]+\|$/.test(row)) continue;
				var cells = row.replace(/^\||\|$/g, '').split('|');
				var tag = isFirst ? 'th' : 'td';
				out += isFirst ? '<thead><tr>' : '<tr>';
				for (var c = 0; c < cells.length; c++) {
					out += '<' + tag + '>' + cells[c].trim() + '</' + tag + '>';
				}
				out += isFirst ? '</tr></thead><tbody>' : '</tr>';
				isFirst = false;
			}
			out += '</tbody></table></div>';
			html += out;
			tableRows = []; inTable = false;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			var trimmed = line.trim();

			// Table rows (starts and ends with |)
			if (/^\|.+\|$/.test(trimmed)) {
				if (inUl) { html += '</ul>'; inUl = false; }
				if (inOl) { html += '</ol>'; inOl = false; }
				inTable = true;
				tableRows.push(trimmed);
				continue;
			}
			// Flush pending table when line is not a table row
			if (inTable) flushTable();

			// Headings
			if (/^#{1,5}\s/.test(trimmed)) {
				if (inUl) { html += '</ul>'; inUl = false; }
				if (inOl) { html += '</ol>'; inOl = false; }
				var lvl = trimmed.match(/^(#+)/)[1].length;
				var htag = 'h' + Math.min(lvl + 1, 6);
				html += '<' + htag + '>' + trimmed.replace(/^#+\s*/, '') + '</' + htag + '>';
				continue;
			}

			// Horizontal rule
			if (/^[-*_]{3,}\s*$/.test(trimmed)) {
				if (inUl) { html += '</ul>'; inUl = false; }
				if (inOl) { html += '</ol>'; inOl = false; }
				html += '<hr>';
				continue;
			}

			// Unordered list
			if (/^[-*•]\s/.test(trimmed)) {
				if (inOl) { html += '</ol>'; inOl = false; }
				if (!inUl) { html += '<ul>'; inUl = true; }
				html += '<li>' + trimmed.replace(/^[-*•]\s*/, '') + '</li>';
				continue;
			}

			// Ordered list
			if (/^\d+[\.\)]\s/.test(trimmed)) {
				if (inUl) { html += '</ul>'; inUl = false; }
				if (!inOl) { html += '<ol>'; inOl = true; }
				html += '<li>' + trimmed.replace(/^\d+[\.\)]\s*/, '') + '</li>';
				continue;
			}

			// Close lists on non-list line
			if (inUl) { html += '</ul>'; inUl = false; }
			if (inOl) { html += '</ol>'; inOl = false; }

			// Empty line = paragraph break
			if (trimmed === '') {
				html += '<br>';
				continue;
			}

			// Regular line
			html += trimmed + '<br>';
		}

		if (inUl) html += '</ul>';
		if (inOl) html += '</ol>';
		if (inTable) flushTable();

		// Images: ![alt](url)
		html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1" class="bzcc-chunk-image" loading="lazy">');
		// Inline formatting
		html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
		html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
		// Checkboxes
		html = html.replace(/☐/g, '<span class="bzcc-checkbox">☐</span>');
		html = html.replace(/☑/g, '<span class="bzcc-checkbox bzcc-checkbox--checked">☑</span>');

		return html;
	}

	/* ═══════════════════════════════════════
	 *  Build action buttons HTML for completed chunks
	 * ═══════════════════════════════════════ */
	function addChunkActions(node) {
		var body = node.querySelector('.bzcc-stepper-node__body');
		if (!body || body.querySelector('.bzcc-stepper-node__actions')) return;

		var actions = document.createElement('div');
		actions.className = 'bzcc-stepper-node__actions';
		actions.innerHTML =
			'<button class="bzcc-action-btn" data-action="copy" title="Sao chép">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>' +
				' Sao chép</button>' +
			'<button class="bzcc-action-btn" data-action="edit" title="Chỉnh sửa">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>' +
				' Chỉnh sửa</button>' +
			'<button class="bzcc-action-btn bzcc-action-btn--magic" data-action="regenerate" title="Tạo lại">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>' +
				' Đũa thần</button>' +
			'<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-image" title="Tạo ảnh">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>' +
				' Tạo ảnh</button>' +
			'<button class="bzcc-action-btn bzcc-action-btn--generate" data-action="gen-video" title="Tạo video">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect width="14" height="12" x="2" y="6" rx="2"/></svg>' +
				' Tạo video</button>' +
			'<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="gen-mindmap" title="Mindmap">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 3v6"/><path d="M12 15v6"/><path d="m3 12 6 0"/><path d="m15 12 6 0"/><circle cx="12" cy="3" r="2"/><circle cx="12" cy="21" r="2"/><circle cx="3" cy="12" r="2"/><circle cx="21" cy="12" r="2"/></svg>' +
				' Mindmap</button>' +
			'<button class="bzcc-action-btn" data-action="schedule" title="Lên Lịch">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>' +
				' Lên Lịch</button>' +
			'<button class="bzcc-action-btn bzcc-action-btn--outline" data-action="save" title="Lưu Kho">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>' +
				' Lưu Kho</button>';
		body.appendChild(actions);
	}

	/* ═══════════════════════════════════════
	 *  Parallel Chunk Streaming
	 *  Opens N EventSource connections concurrently (max 3)
	 * ═══════════════════════════════════════ */
	function streamChunksParallel(chunks, fileId, restUrl, nonce) {
		/* Filter out chunks that failed to create (id=0 or missing) */
		var validChunks = chunks.filter(function (c) { return c.id && c.id > 0; });
		var skippedChunks = chunks.filter(function (c) { return !c.id || c.id <= 0; });
		chunks = validChunks;
		console.log('[BZCC] streamChunksParallel | chunks=' + chunks.length + ' | skipped=' + skippedChunks.length);
		var MAX_CONCURRENT = 3;
		var queue = chunks.slice();
		var activeCount = 0;
		var completedCount = 0;
		var totalCount = chunks.length;

		/* Mark skipped nodes (insert failed) as error immediately */
		skippedChunks.forEach(function (c) {
			var node = findNode(c.chunk_index);
			if (node) {
				node.classList.add('bzcc-stepper-node--error');
				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
				var bar = node.querySelector('.bzcc-stepper-node__bar');
				if (bar) { bar.style.width = '100%'; bar.style.background = '#ef4444'; }
				var content = node.querySelector('.bzcc-stepper-node__content');
				if (content) content.innerHTML = '<div class="bzcc-chunk-error">⚠️ Lỗi tạo phần nội dung. Vui lòng tạo lại toàn bộ.</div>';
			}
		});

		// Mark all nodes as queued (show loading)
		chunks.forEach(function (c) {
			var node = findNode(c.chunk_index);
			if (node) {
				node.classList.add('bzcc-stepper-node--queued');
				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<div class="bzcc-spinner-sm bzcc-spinner-sm--dim"></div>';
				// Expand body to show status
				var body = node.querySelector('.bzcc-stepper-node__body');
				if (body) body.classList.remove('bzcc-collapsed');
				var content = node.querySelector('.bzcc-stepper-node__content');
				if (content) content.innerHTML = '<span class="bzcc-queue-label">⏳ Đang chờ...</span>';
			}
		});

		function startNext() {
			while (queue.length > 0 && activeCount < MAX_CONCURRENT) {
				var chunk = queue.shift();
				activeCount++;
				streamSingleChunk(chunk, restUrl, nonce, onChunkComplete);
			}
		}

		function onChunkComplete(allDoneFromServer) {
			activeCount--;
			completedCount++;

			updateHeader('loading',
				'AI đang tạo nội dung... (' + completedCount + '/' + totalCount + ')',
				completedCount < totalCount ? 'Đang xử lý song song' : 'Hoàn tất!');

			if (allDoneFromServer || completedCount >= totalCount) {
				finishAll();
			} else {
				startNext();
			}
		}

		function finishAll() {
			updateHeader('done');
			var result = document.getElementById('bzcc-result');
			if (result) result.setAttribute('data-file-status', 'completed');
			showExportButtons();
			renderMermaidDiagrams();
			loadAndRenderPlatforms(fileId);
		}

		startNext();
	}

	function streamSingleChunk(chunk, restUrl, nonce, onComplete) {
		var chunkIndex = chunk.chunk_index;
		var rawContent = '';
		var url = restUrl + '/chunk/' + chunk.id + '/stream?_wpnonce=' + encodeURIComponent(nonce);
		console.log('[BZCC] streamSingleChunk #' + chunkIndex + ' | url=' + url);

		var es = new EventSource(url);

		// Timeout: 90s without any data → mark stuck
		var CHUNK_TIMEOUT = 90000;
		var timeoutId = setTimeout(function () {
			es.close();
			var node = findNode(chunkIndex);
			if (node) markChunkStuck(node, chunkIndex);
			onComplete(false);
		}, CHUNK_TIMEOUT);

		function resetTimeout() {
			clearTimeout(timeoutId);
			timeoutId = setTimeout(function () {
				es.close();
				var node = findNode(chunkIndex);
				if (node) markChunkStuck(node, chunkIndex);
				onComplete(false);
			}, CHUNK_TIMEOUT);
		}

		es.addEventListener('chunk_start', function (e) {
			console.log('[BZCC] parallel chunk_start #' + chunkIndex);
			resetTimeout();
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (!node) return;

				node.classList.remove('bzcc-stepper-node--queued');
				node.classList.add('bzcc-stepper-node--active');
				if (data.chunk_id) node.setAttribute('data-chunk-id', data.chunk_id);

				var icon = node.querySelector('.bzcc-stepper-node__icon');
				if (icon) icon.innerHTML = '<div class="bzcc-spinner-sm"></div>';

				var body = node.querySelector('.bzcc-stepper-node__body');
				var content = node.querySelector('.bzcc-stepper-node__content');
				if (body) body.classList.remove('bzcc-collapsed');
				if (content) content.innerHTML = '<span class="bzcc-typing-indicator"><span></span><span></span><span></span></span>';

				var bar = node.querySelector('.bzcc-stepper-node__bar');
				if (bar) bar.style.width = '20%';
			} catch (err) { console.error('[BZCC] parallel chunk_start error', err); }
		});

		es.addEventListener('chunk_delta', function (e) {
			resetTimeout();
			try {
				var data = JSON.parse(e.data);
				rawContent += (data.delta || '');

				var node = findNode(data.chunk_index);
				var content = node ? node.querySelector('.bzcc-stepper-node__content') : null;
				if (!content) return;

				// Remove typing indicator on first delta
				var typing = content.querySelector('.bzcc-typing-indicator');
				if (typing) typing.remove();

				// Render markdown live
				content.innerHTML = simpleMarkdown(stripPromptPreamble(rawContent));

				// Progress bar
				var bar = node.querySelector('.bzcc-stepper-node__bar');
				var current = parseFloat(bar ? bar.style.width : '20') || 20;
				if (current < 90 && bar) bar.style.width = Math.min(current + 2, 90) + '%';
			} catch (err) { /* ignore */ }
		});

		es.addEventListener('chunk_error', function (e) {
			clearTimeout(timeoutId);
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (node) {
					node.classList.remove('bzcc-stepper-node--active', 'bzcc-stepper-node--queued');
					node.classList.add('bzcc-stepper-node--error');
					var icon = node.querySelector('.bzcc-stepper-node__icon');
					if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
					var bar = node.querySelector('.bzcc-stepper-node__bar');
					if (bar) { bar.style.width = '100%'; bar.style.background = '#ef4444'; }
					showToast('Lỗi tạo nội dung phần ' + (data.chunk_index + 1), 'error');
					showRetryButton(node);
				}
			} catch (err) { /* ignore */ }
			es.close();
			onComplete(false);
		});

		es.addEventListener('chunk_done', function (e) {
			clearTimeout(timeoutId);
			console.log('[BZCC] parallel chunk_done #' + chunkIndex);
			try {
				var data = JSON.parse(e.data);
				var node = findNode(data.chunk_index);
				if (node) {
					node.classList.remove('bzcc-stepper-node--active', 'bzcc-stepper-node--queued');
					node.classList.add('bzcc-stepper-node--done');
					if (data.chunk_id) node.setAttribute('data-chunk-id', data.chunk_id);

					var icon = node.querySelector('.bzcc-stepper-node__icon');
					if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>';

					var bar = node.querySelector('.bzcc-stepper-node__bar');
					if (bar) bar.style.width = '100%';

					// Final markdown render
					var content = node.querySelector('.bzcc-stepper-node__content');
					if (content && rawContent) content.innerHTML = simpleMarkdown(rawContent);

					// Render mermaid diagrams in this chunk
					renderMermaidDiagrams(node);

					// Add action buttons
					addChunkActions(node);
				}

				es.close();
				onComplete(data.all_done || false);
			} catch (err) {
				es.close();
				onComplete(false);
			}
		});

		es.onerror = function (err) {
			clearTimeout(timeoutId);
			console.error('[BZCC] parallel SSE error for chunk #' + chunkIndex, err);
			es.close();
			var node = findNode(chunkIndex);
			if (node) {
				node.classList.remove('bzcc-stepper-node--active', 'bzcc-stepper-node--queued');
				node.classList.add('bzcc-stepper-node--error');
				showRetryButton(node);
			}
			onComplete(false);
		};
	}

	/* ── Show export buttons after completion ── */
	function showExportButtons() {
		var nav = document.querySelector('.bzcc-result-nav');
		if (!nav || document.getElementById('bzcc-btn-export-pdf')) return;

		var pdfBtn = document.createElement('button');
		pdfBtn.className = 'bzcc-btn bzcc-btn--primary';
		pdfBtn.id = 'bzcc-btn-export-pdf';
		pdfBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg> Tải PDF';
		pdfBtn.addEventListener('click', function () { handleExport('pdf'); });

		var wordBtn = document.createElement('button');
		wordBtn.className = 'bzcc-btn bzcc-btn--outline';
		wordBtn.id = 'bzcc-btn-export-word';
		wordBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg> Tải Word';
		wordBtn.addEventListener('click', function () { handleExport('word'); });

		var pptxBtn = document.createElement('button');
		pptxBtn.className = 'bzcc-btn bzcc-btn--outline';
		pptxBtn.id = 'bzcc-btn-export-pptx';
		pptxBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg> Tải PPTX';
		pptxBtn.addEventListener('click', function () { handleExport('pptx'); });

		nav.appendChild(pdfBtn);
		nav.appendChild(wordBtn);
		nav.appendChild(pptxBtn);
	}

	/* ── Export content to PDF/Word ── */
	window.handleExport = handleExport;
	function handleExport(format) {
		var chunks = document.querySelectorAll('.bzcc-stepper-node');
		var content = '';
		var infoHeader = document.querySelector('.bzcc-result-header--info');
		var titleText = infoHeader ? (infoHeader.getAttribute('data-export-title') || infoHeader.querySelector('.bzcc-result-header__title').textContent) : 'Content';

		chunks.forEach(function (node) {
			var label = node.querySelector('.bzcc-stepper-node__label');
			var contentEl = node.querySelector('.bzcc-stepper-node__content');
			if (label) content += '<h2>' + escHtml(label.textContent) + '</h2>\n';
			if (contentEl) content += contentEl.innerHTML + '\n<hr>\n';
		});

		var styles = 'body{font-family:\"Segoe UI\",system-ui,sans-serif;max-width:800px;margin:0 auto;padding:40px 20px;line-height:1.8;color:#1e293b;}' +
			'h1{color:#6366f1;font-size:1.8em;margin-bottom:24px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;}' +
			'h2{color:#334155;font-size:1.3em;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;}' +
			'h3,h4{color:#475569;font-size:1.1em;margin:16px 0 8px;}' +
			'hr{border:none;border-top:1px solid #e2e8f0;margin:24px 0;}' +
			'strong{font-weight:600;}code{background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:0.9em;}' +
			'ul,ol{padding-left:24px;}li{margin:4px 0;}';

		var fullHtml =
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escHtml(titleText) + '</title>' +
			'<style>' + styles + '</style></head><body>' +
			'<h1>' + escHtml(titleText) + '</h1>' +
			content + '</body></html>';

		if (format === 'pdf') {
			var printWin = window.open('', '_blank');
			if (printWin) {
				printWin.document.write(fullHtml);
				printWin.document.close();
				setTimeout(function () { printWin.print(); }, 500);
			}
		} else if (format === 'word') {
			var wordHead = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
			var blob = new Blob([wordHead + '<head><meta charset="utf-8"><style>' + styles + '</style></head><body>' +
				'<h1>' + escHtml(titleText) + '</h1>' + content + '</body></html>'],
				{ type: 'application/msword' });
			var link = document.createElement('a');
			link.href = URL.createObjectURL(blob);
			link.download = (titleText || 'content').replace(/[^a-zA-Z0-9\u00C0-\u1EF9\s]/g, '').trim() + '.doc';
			link.click();
			URL.revokeObjectURL(link.href);
		} else if (format === 'pptx') {
			generatePptx(titleText, chunks);
		}
	}

	/**
	 * Generate PPTX using PptxGenJS CDN
	 */
	function generatePptx(titleText, chunks) {
		function doBuild() {
			var pptx = new PptxGenJS();
			pptx.layout = 'LAYOUT_WIDE';
			pptx.author = 'BizCity Content Creator';
			pptx.title  = titleText || 'Content';

			/* Title slide */
			var titleSlide = pptx.addSlide();
			titleSlide.addText(titleText || 'Content', {
				x: 0.5, y: 1.5, w: '90%', h: 2,
				fontSize: 32, bold: true, color: '6366f1',
				align: 'center', valign: 'middle'
			});
			titleSlide.addText('BizCity Content Creator', {
				x: 0.5, y: 4.5, w: '90%', h: 0.6,
				fontSize: 14, color: '94a3b8', align: 'center'
			});

			/* Content slides */
			chunks.forEach(function (node) {
				var label = node.querySelector('.bzcc-stepper-node__label');
				var contentEl = node.querySelector('.bzcc-stepper-node__content');
				if (!label && !contentEl) return;

				var slide = pptx.addSlide();
				var slideTitle = label ? label.textContent.trim() : '';

				slide.addText(slideTitle, {
					x: 0.5, y: 0.3, w: '90%', h: 0.8,
					fontSize: 24, bold: true, color: '334155',
					valign: 'top'
				});

				if (contentEl) {
					var text = contentEl.innerText || contentEl.textContent || '';
					/* Trim to avoid too-long slides */
					if (text.length > 2000) text = text.substring(0, 2000) + '…';
					slide.addText(text, {
						x: 0.5, y: 1.3, w: '90%', h: 5.0,
						fontSize: 13, color: '1e293b',
						valign: 'top', wrap: true,
						lineSpacingMultiple: 1.3
					});
				}
			});

			var fileName = (titleText || 'content').replace(/[^a-zA-Z0-9\u00C0-\u1EF9\s]/g, '').trim();
			pptx.writeFile({ fileName: fileName + '.pptx' });
		}

		if (typeof PptxGenJS !== 'undefined') {
			doBuild();
		} else {
			var s = document.createElement('script');
			s.src = 'https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgenjs.bundle.js';
			s.onload = doBuild;
			s.onerror = function () { alert('Không thể tải thư viện PPTX. Vui lòng thử lại.'); };
			document.head.appendChild(s);
		}
	}

	/* ═══════════════════════════════════════
	 *  Layout Field Interactions
	 * ═══════════════════════════════════════ */

	/* ── Move flat fields into collapsible / tab containers ── */
	function initLayoutGrouping() {
		document.querySelectorAll('.bzcc-fields').forEach(function (container) {
			var children = Array.prototype.slice.call(container.children);
			var target = null;  // current container accepting fields
			var tabGroup = null;
			var tabPaneIdx = 0;

			children.forEach(function (child) {
				if (child.classList.contains('bzcc-collapsible')) {
					target = child.querySelector('.bzcc-collapsible__body');
					tabGroup = null;
					return;
				}
				if (child.classList.contains('bzcc-tab-group')) {
					tabGroup = child;
					tabPaneIdx = 0;
					target = null;
					// Put subsequent fields into first pane
					var pane = tabGroup.querySelector('.bzcc-tab-group__pane[data-tab-index="0"]');
					if (pane) target = pane;
					return;
				}
				if (child.classList.contains('bzcc-heading')) {
					// A heading inside a tab context advances pane index
					if (tabGroup) {
						tabPaneIdx++;
						var pane = tabGroup.querySelector('.bzcc-tab-group__pane[data-tab-index="' + tabPaneIdx + '"]');
						if (pane) {
							target = pane;
							// Move the heading itself into the pane for visual context
							pane.appendChild(child);
						} else {
							target = null;
							tabGroup = null;
						}
					} else {
						target = null;
					}
					return;
				}
				// Move regular fields into the current target container
				if (target && (child.classList.contains('bzcc-field') || child.classList.contains('bzcc-button-group') || child.matches('.bzcc-field, [class*="bzcc-field"]'))) {
					target.appendChild(child);
				}
			});
		});
	}

	/* ── Collapsible expand / collapse ── */
	function initCollapsible() {
		document.querySelectorAll('.bzcc-collapsible__header').forEach(function (header) {
			if (header._bzccCol) return;
			header._bzccCol = true;

			header.addEventListener('click', function () {
				var wrap = header.closest('.bzcc-collapsible');
				if (!wrap) return;
				var body = wrap.querySelector('.bzcc-collapsible__body');
				if (!body) return;

				var isCollapsed = wrap.getAttribute('data-state') === 'collapsed';
				wrap.setAttribute('data-state', isCollapsed ? 'expanded' : 'collapsed');
				body.style.display = isCollapsed ? '' : 'none';
			});
		});
	}

	/* ── Tab Group switching ── */
	function initTabGroups() {
		document.querySelectorAll('.bzcc-tab-group').forEach(function (group) {
			if (group._bzccTG) return;
			group._bzccTG = true;

			var tabs  = group.querySelectorAll('.bzcc-tab-group__tab');
			var panes = group.querySelectorAll('.bzcc-tab-group__pane');

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					var idx = tab.getAttribute('data-tab-index');
					tabs.forEach(function (t) { t.classList.remove('bzcc-tab-group__tab--active'); });
					tab.classList.add('bzcc-tab-group__tab--active');

					panes.forEach(function (p) {
						var show = p.getAttribute('data-tab-index') === idx;
						p.style.display = show ? '' : 'none';
						p.classList.toggle('bzcc-tab-group__pane--active', show);
					});
				});
			});
		});
	}

	/* ── Button Group (pill toggle) ── */
	function initButtonGroups() {
		document.querySelectorAll('.bzcc-button-group').forEach(function (group) {
			var pills = group.querySelectorAll('.bzcc-pill');
			pills.forEach(function (pill) {
				if (pill._bzccPG) return;
				pill._bzccPG = true;

				pill.addEventListener('click', function (e) {
					e.preventDefault();
					var input = pill.querySelector('input');
					if (!input) return;

					if (input.type === 'checkbox') {
						input.checked = !input.checked;
					} else {
						pills.forEach(function (p) {
							var inp = p.querySelector('input');
							if (inp) inp.checked = false;
							p.classList.remove('bzcc-pill--selected');
						});
						input.checked = true;
					}
					pill.classList.toggle('bzcc-pill--selected', input.checked);
				});
			});
		});
	}

	/* ── Checkbox Grid toggle ── */
	function initCheckboxGrids() {
		document.querySelectorAll('.bzcc-checkbox-grid').forEach(function (grid) {
			var checks = grid.querySelectorAll('.bzcc-grid-check');
			checks.forEach(function (check) {
				if (check._bzccCG) return;
				check._bzccCG = true;

				check.addEventListener('click', function (e) {
					e.preventDefault();
					var input = check.querySelector('input');
					if (!input) return;
					input.checked = !input.checked;
					check.classList.toggle('bzcc-grid-check--selected', input.checked);
				});
			});
		});
	}

	/* ═══════════════════════════════════════
	 *  History List View
	 * ═══════════════════════════════════════ */
	function initHistoryView() {
		var grid       = document.getElementById('bzcc-history-grid');
		var searchEl   = document.getElementById('bzcc-history-search');
		var statusEl   = document.getElementById('bzcc-history-filter-status');
		var sortEl     = document.getElementById('bzcc-history-sort');
		var viewBtns   = document.querySelectorAll('.bzcc-viewtoggle-btn');

		if (!grid) return;

		var cards = Array.prototype.slice.call(grid.querySelectorAll('.bzcc-history-card'));

		/* ── Search (debounced) ── */
		var searchTimer = null;
		if (searchEl) {
			searchEl.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(applyFilters, 250);
			});
		}

		/* ── Status filter ── */
		if (statusEl) {
			statusEl.addEventListener('change', applyFilters);
		}

		/* ── Sort ── */
		if (sortEl) {
			sortEl.addEventListener('change', applySort);
		}

		/* ── View toggle (grid / list) ── */
		viewBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var mode = btn.getAttribute('data-view');
				viewBtns.forEach(function (b) { b.classList.remove('bzcc-viewtoggle-btn--active'); });
				btn.classList.add('bzcc-viewtoggle-btn--active');
				if (mode === 'list') {
					grid.classList.add('bzcc-history-grid--list');
				} else {
					grid.classList.remove('bzcc-history-grid--list');
				}
			});
		});

		/* ── Delete buttons ── */
		grid.addEventListener('click', function (e) {
			var del = e.target.closest('.bzcc-history-card__delete');
			if (!del) return;
			e.preventDefault();
			e.stopPropagation();
			var fileId = del.getAttribute('data-file-id');
			if (!fileId) return;
			if (!confirm('Bạn có chắc muốn xóa nội dung này?')) return;

			del.disabled = true;
			var restUrl = (typeof bzccFront !== 'undefined' && bzccFront.restUrl) || '/wp-json/bzcc/v1';
			var nonce   = (typeof bzccFront !== 'undefined' && bzccFront.nonce) || '';

			var xhr = new XMLHttpRequest();
			xhr.open('DELETE', restUrl + '/file/' + fileId);
			if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce);
			xhr.onload = function () {
				if (xhr.status >= 200 && xhr.status < 300) {
					var card = del.closest('.bzcc-history-card');
					if (card) {
						card.style.transition = 'opacity 0.3s, transform 0.3s';
						card.style.opacity = '0';
						card.style.transform = 'scale(0.95)';
						setTimeout(function () { card.remove(); }, 300);
					}
				} else {
					alert('Không thể xóa. Vui lòng thử lại.');
					del.disabled = false;
				}
			};
			xhr.onerror = function () {
				alert('Lỗi mạng. Vui lòng thử lại.');
				del.disabled = false;
			};
			xhr.send();
		});

		function applyFilters() {
			var query  = searchEl ? searchEl.value.trim().toLowerCase() : '';
			var status = statusEl ? statusEl.value : '';

			cards.forEach(function (card) {
				var cardTitle  = card.getAttribute('data-title') || '';
				var cardStatus = card.getAttribute('data-status') || '';

				var matchSearch = !query || cardTitle.indexOf(query) !== -1;
				var matchStatus = !status || cardStatus === status;

				card.style.display = (matchSearch && matchStatus) ? '' : 'none';
			});
		}

		function applySort() {
			var mode = sortEl ? sortEl.value : 'newest';

			cards.sort(function (a, b) {
				if (mode === 'title') {
					return (a.getAttribute('data-title') || '').localeCompare(b.getAttribute('data-title') || '');
				}
				var da = a.getAttribute('data-updated') || a.getAttribute('data-created') || '';
				var db = b.getAttribute('data-updated') || b.getAttribute('data-created') || '';
				return mode === 'oldest' ? da.localeCompare(db) : db.localeCompare(da);
			});

			cards.forEach(function (card) { grid.appendChild(card); });
		}
	}

	/* ═══════════════════════════════════════
	 *  History Detail View
	 * ═══════════════════════════════════════ */
	function initHistoryDetailView() {
		var tabs   = document.querySelectorAll('.bzcc-detail-platform-tab');
		var chunks = document.querySelectorAll('.bzcc-detail-chunk');

		if (!tabs.length) return;

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var plat = tab.getAttribute('data-platform') || '';

				tabs.forEach(function (t) { t.classList.remove('bzcc-detail-platform-tab--active'); });
				tab.classList.add('bzcc-detail-platform-tab--active');

				chunks.forEach(function (ch) {
					var cp = ch.getAttribute('data-platform') || '';
					ch.style.display = (!plat || cp === plat) ? '' : 'none';
				});
			});
		});
	}

})();
