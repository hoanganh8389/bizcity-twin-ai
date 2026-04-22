/**
 * BizCity Code Builder — Editor JS (vanilla, no framework dependency)
 *
 * Handles: prompt input, image upload, SSE streaming, code editor,
 * live preview, variant selection, tab switching.
 */
(function () {
	'use strict';

	const config    = window.bzcode_config || {};
	const API       = config.rest_url || '/wp-json/bzcode/v1';
	const AJAX_URL  = config.ajax_url || '/wp-admin/admin-ajax.php';
	const nonce     = config.nonce || '';
	const sseNonce  = config.sse_nonce || '';
	const stacks    = config.stacks || {};

	/* ── State ── */
	let currentProjectId = parseInt(config.project_id, 10) || getUrlId() || 0;

	/* ── URL helpers (like bizcity-doc) ── */
	function getUrlId() {
		const params = new URLSearchParams(window.location.search);
		const id = params.get('id');
		return id ? parseInt(id, 10) || 0 : 0;
	}
	function setUrlId(id) {
		const url = new URL(window.location.href);
		url.searchParams.set('id', String(id));
		window.history.replaceState({}, '', url.toString());
	}
	let currentTab       = 'code';
	let attachedImages   = [];
	let variants         = [];
	let selectedVariant  = 0;
	let previewTimer     = null;
	let isGenerating     = false;
	let isPublishing     = false;
	let publishedPageUrl = '';
	let $generatingMsg   = null;

	/* ── DOM refs ── */
	const $app            = document.getElementById('bzcode-app');
	const $promptInput    = document.getElementById('bzcode-prompt-input');
	const $sendBtn        = document.getElementById('bzcode-btn-send');
	const $codeEditor     = document.getElementById('bzcode-code-editor');
	const $previewIframe  = document.getElementById('bzcode-preview-iframe');
	const $codeArea      = document.getElementById('bzcode-code-area');
	const $previewArea   = document.getElementById('bzcode-preview-area');
	const $chatMessages  = document.getElementById('bzcode-chat-messages');
	const $imagePreview  = document.getElementById('bzcode-image-preview');
	const $startPane     = document.getElementById('bzcode-start-pane');
	const $dropzone      = document.getElementById('bzcode-dropzone');
	const $fileInput     = document.getElementById('bzcode-file-input');
	const $chatFileInput = document.getElementById('bzcode-chat-file-input');
	const $stackSelector = document.getElementById('bzcode-stack-selector');
	const $downloadBtn   = document.getElementById('bzcode-btn-download');
	const $tabs          = document.querySelectorAll('.bzcode-tab');
	const $inputTabs     = document.querySelectorAll('#bzcode-input-tabs .bzcode-input-tab');
	const $inputPanels   = document.querySelectorAll('#bzcode-start-pane .bzcode-input-panel');
	const $urlInput      = document.getElementById('bzcode-url-input');
	const $urlPreview    = document.getElementById('bzcode-url-preview');
	const $importCode    = document.getElementById('bzcode-import-code');
	const $historyList   = document.getElementById('bzcode-history-list');
	const $historyCount  = document.getElementById('bzcode-history-count');

	/* ── Sources Widget (shared component) ── */
	let sourcesWidget = null;

	/* ═══════════════════════════════════════════════
	   INIT
	   ═══════════════════════════════════════════════ */

	function init() {
		renderStackSelector();
		bindEvents();
		initSourcesWidget();

		if (currentProjectId) {
			loadProject(currentProjectId);
			loadGenerationHistory();
		}
	}

	function initSourcesWidget() {
		if (typeof BZTwinSources === 'undefined') return;
		sourcesWidget = BZTwinSources.init({
			container:    '#bzcode-sources-panel',
			apiBase:      API,
			nonce:        nonce,
			parentId:     currentProjectId,
			parentField:  'project_id',
			onSourcesChange: function () { /* future: update source count badge */ },
			onMessage: function (type, text) { addChatMessage(type === 'error' ? 'system' : 'assistant', text); },
			onEnsureParent: async function () {
				// Auto-create an empty project so sources can be attached
				try {
					const res = await fetch(API + '/save', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify({ code: '', title: 'Untitled', stack: getSelectedStack(), empty_project: true }),
					});
					const json = await res.json();
					if (json.ok && json.project_id) {
						currentProjectId = json.project_id;
						setUrlId(currentProjectId);
						return currentProjectId;
					}
				} catch (e) { console.error('Auto-create project failed:', e); }
				return 0;
			},
		});
	}

	function renderStackSelector() {
		// Single stack — hide selector, show label only
		if ($stackSelector) {
			$stackSelector.innerHTML = '<span class="bzcode-stack-label">HTML + CSS</span>';
		}
	}

	function getSelectedStack() {
		return 'html_css'; // locked to vanilla HTML/CSS
	}

	/* ═══════════════════════════════════════════════
	   EVENTS
	   ═══════════════════════════════════════════════ */

	function bindEvents() {
		// Send
		$sendBtn?.addEventListener('click', handleSend);
		$promptInput?.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) handleSend();
		});

		// File upload (start pane dropzone)
		$dropzone?.addEventListener('click', () => $fileInput?.click());
		$fileInput?.addEventListener('change', handleFileSelect);

		// Chat attach button — uses dedicated input (always visible)
		$chatFileInput?.addEventListener('change', handleFileSelect);
		document.getElementById('bzcode-btn-attach')?.addEventListener('click', () => ($chatFileInput || $fileInput)?.click());

		// Drag & drop (start pane dropzone)
		$dropzone?.addEventListener('dragover', (e) => { e.preventDefault(); $dropzone.classList.add('dragging'); });
		$dropzone?.addEventListener('dragleave', () => $dropzone.classList.remove('dragging'));
		$dropzone?.addEventListener('drop', handleDrop);

		// Drag & drop images on chat input area
		const $chatInput = document.querySelector('.bzcode-chat__input');
		if ($chatInput) {
			$chatInput.addEventListener('dragover', (e) => {
				if ([...e.dataTransfer.types].includes('Files')) {
					e.preventDefault();
					$chatInput.classList.add('dragging');
				}
			});
			$chatInput.addEventListener('dragleave', (e) => {
				if (!$chatInput.contains(e.relatedTarget)) $chatInput.classList.remove('dragging');
			});
			$chatInput.addEventListener('drop', (e) => {
				e.preventDefault();
				$chatInput.classList.remove('dragging');
				const files = [...e.dataTransfer.files].filter(f => f.type.startsWith('image/'));
				if (files.length) processFiles(files);
			});
		}

		// Tabs (code/preview/split)
		$tabs.forEach(tab => tab.addEventListener('click', () => switchTab(tab.dataset.tab)));

		// Input mode tabs (upload/url/text/import)
		$inputTabs.forEach(tab => tab.addEventListener('click', () => switchInputTab(tab.dataset.inputTab)));

		// Screen capture
		document.getElementById('bzcode-btn-screencapture')?.addEventListener('click', handleScreenCapture);

		// URL screenshot
		document.getElementById('bzcode-btn-fetch-url')?.addEventListener('click', handleFetchUrl);
		$urlInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') handleFetchUrl(); });

		// Import code
		document.getElementById('bzcode-btn-import')?.addEventListener('click', handleImportCode);

		// Download
		$downloadBtn?.addEventListener('click', handleDownload);

		// Publish to WP Page
		document.getElementById('bzcode-btn-publish')?.addEventListener('click', handlePublish);
		document.getElementById('bzcode-btn-delete-page')?.addEventListener('click', handleDeletePage);

		// History toggle + refresh
		document.getElementById('bzcode-btn-toggle-history')?.addEventListener('click', toggleHistory);
		document.getElementById('bzcode-btn-refresh-history')?.addEventListener('click', loadGenerationHistory);

		// Sources are handled by BZTwinSources widget (see initSourcesWidget)

		// Paste image from clipboard into chat
		$promptInput?.addEventListener('paste', (e) => {
			const items = e.clipboardData?.items;
			if (!items) return;
			for (const item of items) {
				if (item.type.startsWith('image/')) {
					e.preventDefault();
					const file = item.getAsFile();
					if (file) processFiles([file]);
					break;
				}
			}
		});
	}

	/* ═══════════════════════════════════════════════
	   INPUT MODE TABS
	   ═══════════════════════════════════════════════ */

	function switchInputTab(tabName) {
		$inputTabs.forEach(t => t.classList.toggle('bzcode-input-tab--active', t.dataset.inputTab === tabName));
		$inputPanels.forEach(p => p.classList.toggle('bzcode-input-panel--active', p.dataset.inputPanel === tabName));
	}

	/* ═══════════════════════════════════════════════
	   HISTORY INLINE (toggle)
	   ═══════════════════════════════════════════════ */

	function toggleHistory() {
		if (!$historyList) return;
		const isHidden = $historyList.style.display === 'none';
		$historyList.style.display = isHidden ? 'flex' : 'none';
		if (isHidden && currentProjectId) loadGenerationHistory();
	}

	/* ═══════════════════════════════════════════════
	   SCREEN CAPTURE — getDisplayMedia API
	   ═══════════════════════════════════════════════ */

	async function handleScreenCapture() {
		if (!navigator.mediaDevices?.getDisplayMedia) {
			alert('Trình duyệt không hỗ trợ chụp màn hình. Vui lòng dùng Chrome/Edge.');
			return;
		}

		try {
			const stream = await navigator.mediaDevices.getDisplayMedia({
				video: { cursor: 'never' },
				preferCurrentTab: false,
			});

			// Create a video element to capture a frame
			const video = document.createElement('video');
			video.srcObject = stream;
			video.autoplay = true;

			await new Promise(resolve => { video.onloadeddata = resolve; });
			// Small delay for the frame to render
			await new Promise(resolve => setTimeout(resolve, 200));

			const canvas = document.createElement('canvas');
			canvas.width = video.videoWidth;
			canvas.height = video.videoHeight;
			canvas.getContext('2d').drawImage(video, 0, 0);

			// Stop all tracks
			stream.getTracks().forEach(t => t.stop());

			const dataUrl = canvas.toDataURL('image/png');
			attachedImages.push(dataUrl);
			renderImagePreview();
			addChatMessage('system', '📸 Đã chụp màn hình — nhập mô tả rồi nhấn Tạo Code ▶');
		} catch (err) {
			if (err.name !== 'AbortError' && err.name !== 'NotAllowedError') {
				addChatMessage('system', '❌ Lỗi chụp màn hình: ' + err.message);
			}
		}
	}

	/* ═══════════════════════════════════════════════
	   URL → SCREENSHOT — server-side capture
	   ═══════════════════════════════════════════════ */

	async function handleFetchUrl() {
		const url = $urlInput?.value?.trim();
		if (!url) return;

		try { new URL(url); } catch (_) {
			alert('URL không hợp lệ.');
			return;
		}

		const $btn = document.getElementById('bzcode-btn-fetch-url');
		if ($btn) { $btn.disabled = true; $btn.textContent = '⏳ Đang chụp...'; }
		if ($urlPreview) $urlPreview.innerHTML = '<div class="bzcode-spinner bzcode-spinner--sm"></div>';

		try {
			const res = await fetch(AJAX_URL + '?action=bzcode_screenshot_url&_nonce=' + encodeURIComponent(sseNonce), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ url }),
			});
			const json = await res.json();

			if (json.success && json.data?.image) {
				attachedImages.push(json.data.image);
				renderImagePreview();
				if ($urlPreview) $urlPreview.innerHTML = `<img src="${json.data.image}" alt="Screenshot of ${escapeHtml(url)}">`;
				// Pre-fill prompt with URL
				if ($promptInput && !$promptInput.value.trim()) {
					$promptInput.value = 'Clone chính xác trang web này: ' + url;
				}
				addChatMessage('system', `📸 Đã chụp screenshot từ URL — nhấn Tạo Code ▶`);
			} else {
				const errMsg = json.data || 'Không thể chụp screenshot URL này.';
				if ($urlPreview) $urlPreview.innerHTML = `<p class="bzcode-error">${escapeHtml(errMsg)}</p>`;
			}
		} catch (err) {
			if ($urlPreview) $urlPreview.innerHTML = `<p class="bzcode-error">${escapeHtml(err.message)}</p>`;
		}

		if ($btn) { $btn.disabled = false; $btn.textContent = 'Chụp →'; }
	}

	/* ═══════════════════════════════════════════════
	   IMPORT CODE — paste existing HTML
	   ═══════════════════════════════════════════════ */

	function handleImportCode() {
		const code = $importCode?.value?.trim();
		if (!code) { alert('Vui lòng paste code HTML vào.'); return; }

		// Create local variant immediately
		variants = [{ code, status: 'complete' }];
		selectedVariant = 0;
		$codeEditor.value = code;
		updatePreview(code);
		renderVariantList();

		// Hide start pane
		if ($startPane) $startPane.style.display = 'none';

		$sendBtn.textContent = 'Viết code ▶';
		addChatMessage('system', '📋 Đã import code — bạn có thể chỉnh sửa bằng AI qua chat.');

		// Save to server to get project_id (required for publish/edit)
		saveCodeToServer(code, 'Imported Code');
	}

	/**
	 * Save code to server — creates project + page + variant.
	 * Sets currentProjectId on success.
	 */
	async function saveCodeToServer(code, title) {
		try {
			const res = await fetch(`${API}/save`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ code, title, stack: getSelectedStack() }),
			});
			const json = await res.json();
			if (json.ok && json.project_id) {
				currentProjectId = json.project_id;
				setUrlId(currentProjectId);
				if (sourcesWidget) sourcesWidget.setParentId(currentProjectId);
			}
		} catch (err) {
			console.error('Failed to save code:', err);
		}
	}

	/* ═══════════════════════════════════════════════
	   SEND — Generate or Edit
	   ═══════════════════════════════════════════════ */

	async function handleSend() {
		const prompt = $promptInput?.value?.trim();
		if (!prompt && attachedImages.length === 0) return;

		$sendBtn.disabled = true;
		$sendBtn.textContent = '⏳ Generating...';

		addChatMessage('user', prompt, attachedImages);
		showGeneratingIndicator(); // ── feedback

		const isEdit = currentProjectId > 0 && variants.length > 0;
		const endpoint = isEdit ? 'edit' : 'generate';
		const generateBody = { prompt, images: attachedImages, stack: getSelectedStack(), mode: attachedImages.length > 0 ? 'screenshot' : 'text', variants: 2 };
		if (currentProjectId > 0) generateBody.project_id = currentProjectId;
		const body = isEdit
			? { project_id: currentProjectId, instruction: prompt, images: attachedImages }
			: generateBody;

		// Clear input
		$promptInput.value = '';
		attachedImages = [];
		renderImagePreview();

		// Hide start pane
		if ($startPane) $startPane.style.display = 'none';

		try {
			await streamGeneration(endpoint, body);
		} catch (err) {
			addChatMessage('system', '❌ Error: ' + err.message);
		} finally {
			// Safety net: always hide generating indicator when stream ends,
			// even if 'done' SSE event was missed.
			hideGeneratingIndicator();

			// Auto-finalize any in-progress variants (strip fences, update preview)
			variants.forEach((v, i) => {
				if (v.status === 'generating' && v.code) {
					v.code = stripCodeFences(v.code);
					v.status = 'complete';
					if (i === selectedVariant) {
						$codeEditor.value = v.code;
						updatePreview(v.code);
					}
				}
			});
			renderVariantList();
		}

		$sendBtn.disabled = false;
		$sendBtn.textContent = currentProjectId ? 'Chỉnh sửa ▶' : 'Tạo Code ▶';
	}

	/* ═══════════════════════════════════════════════
	   SSE STREAMING (via wp_ajax_ endpoint)
	   ═══════════════════════════════════════════════ */

	function streamGeneration(endpoint, body) {
		// endpoint = 'generate' or 'edit'
		const action = 'bzcode_' + endpoint;
		const url = AJAX_URL + '?action=' + action + '&_nonce=' + encodeURIComponent(sseNonce);

		return new Promise((resolve, reject) => {
			fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(body),
			}).then(async response => {
				if (!response.ok) {
					let msg = 'Request failed: ' + response.status;
					try {
						const errBody = await response.json();
						if (errBody.data) msg = errBody.data;
					} catch (_) { /* non-JSON response */ }
					throw new Error(msg);
				}

				const reader = response.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';
				let currentEvent = 'message';

					function processBuffer() {
					const lines = buffer.split('\n');
					buffer = lines.pop(); // Keep incomplete line

					for (const line of lines) {
						if (line.startsWith(': ')) {
							// SSE comment (prelude padding) — ignore
							continue;
						}
						if (line.startsWith('event: ')) {
							currentEvent = line.slice(7).trim();
							continue;
						}
						if (line.startsWith('data: ')) {
							try {
								const data = JSON.parse(line.slice(6));
								handleSSEEvent(currentEvent, data);
							} catch (e) { /* ignore parse errors */ }
							currentEvent = 'message'; // Reset after consuming
						}
					}
				}

				function read() {
					reader.read().then(({ done, value }) => {
						if (done) {
							// Flush remaining bytes from decoder
							buffer += decoder.decode();
							// Process any remaining data in buffer
							if (buffer.trim()) {
								buffer += '\n'; // ensure last line is processed
								processBuffer();
							}
							resolve();
							return;
						}

						buffer += decoder.decode(value, { stream: true });
						processBuffer();

						read();
					}).catch(reject);
				}

				read();
			}).catch(reject);
		});
	}

	function handleSSEEvent(event, data) {
		switch (event) {
			case 'chunk':
				if (data.delta !== undefined && data.variant !== undefined) {
					appendToVariant(data.variant, data.delta);
				}
				break;

			case 'variant_complete':
				if (data.code !== undefined && data.variant !== undefined) {
					finalizeVariant(data.variant, data.code);
				}
				break;

			case 'variant_error':
				addChatMessage('system', '❌ Variant ' + ((data.variant || 0) + 1) + ': ' + (data.error || 'Unknown error'));
				break;

			case 'section_progress':
				if (data.name !== undefined) {
					addChatMessage('system', `📐 Section ${data.index + 1}/${data.total}: ${data.name}...`);
				}
				break;

			case 'done':
				if (data.project_id) {
					currentProjectId = data.project_id;
					setUrlId(currentProjectId);
				}
				hideGeneratingIndicator();
				// Auto-load history after generation
				if (currentProjectId) loadGenerationHistory();
				// Auto-publish → preview via real URL
				autoPublishAndPreview();
				break;
		}
	}

	/* ═══════════════════════════════════════════════
	   VARIANTS
	   ═══════════════════════════════════════════════ */

	function appendToVariant(index, token) {
		if (!variants[index]) {
			variants[index] = { code: '', status: 'generating' };
		}
		variants[index].code += token;

		// Strip markdown code fences that LLM may wrap around the code
		const displayCode = stripCodeFences(variants[index].code);

		if (index === selectedVariant) {
			$codeEditor.value = displayCode;
			// Auto-scroll textarea to bottom during streaming
			$codeEditor.scrollTop = $codeEditor.scrollHeight;
			// Debounce preview — avoid reloading iframe on every token
			schedulePreview(); // no code arg — reads latest at fire time
		}
		renderVariantList();
	}

	function finalizeVariant(index, code) {
		if (!variants[index]) variants[index] = {};
		variants[index].code = stripCodeFences(code);
		variants[index].status = 'complete';
		const cleanCode = variants[index].code;
		if (index === selectedVariant) {
			$codeEditor.value = cleanCode;
			// Cancel any pending debounced refresh
			if (previewTimer) { clearTimeout(previewTimer); previewTimer = null; }
			// Force iframe reload: blank first, then load clean code in next frame
			$previewIframe.srcdoc = '';
			requestAnimationFrame(() => {
				$previewIframe.srcdoc = cleanCode;
			});
		}
		addChatMessage('assistant', `✅ Variant ${index + 1} hoàn thành!`);
		renderVariantList();
		$sendBtn.textContent = 'Viết code ▶';
	}

	function selectVariant(index) {
		selectedVariant = index;
		if (variants[index]) {
			$codeEditor.value = variants[index].code;
			updatePreview(variants[index].code);
		}
		renderVariantList();
	}

	function renderVariantList() {
		// No dedicated variant panel in 2-column layout.
		// Variants still work internally for code state management.
	}

	// Expose for inline onclick
	window.__bzcode_selectVariant = selectVariant;

	/* ═══════════════════════════════════════════════
	   PREVIEW
	   ═══════════════════════════════════════════════ */

	function updatePreview(code) {
		if (!$previewIframe) return;
		// Cancel any pending debounced update
		if (previewTimer) { clearTimeout(previewTimer); previewTimer = null; }
		$previewIframe.srcdoc = code || '';
	}

	/**
	 * Debounced preview during streaming — fires at most every 800 ms.
	 * Uses current variant code at fire time (avoids stale closure).
	 */
	function schedulePreview() {
		if (previewTimer) return; // already scheduled
		previewTimer = setTimeout(() => {
			previewTimer = null;
			const latestCode = stripCodeFences(variants[selectedVariant]?.code || '');
			$previewIframe.srcdoc = latestCode;
		}, 800);
	}

	function switchTab(tab) {
		currentTab = tab;
		$tabs.forEach(t => t.classList.toggle('bzcode-tab--active', t.dataset.tab === tab));

		if (tab === 'code') {
			$codeArea.style.display = 'flex';
			$previewArea.style.display = 'none';
		} else if (tab === 'preview') {
			$codeArea.style.display = 'none';
			$previewArea.style.display = 'flex';
			// If published URL exists, use real page; otherwise fall back to srcdoc
			if (publishedPageUrl) {
				refreshPreviewUrl();
			} else {
				const code = $codeEditor?.value || '';
				$previewIframe.srcdoc = '';
				requestAnimationFrame(() => { $previewIframe.srcdoc = code; });
			}
		} else {
			// Split mode
			$codeArea.style.display = 'flex';
			$previewArea.style.display = 'flex';
			$codeArea.style.flex = '1';
			$previewArea.style.flex = '1';
			if (publishedPageUrl) {
				refreshPreviewUrl();
			} else {
				const splitCode = $codeEditor?.value || '';
				$previewIframe.srcdoc = '';
				requestAnimationFrame(() => { $previewIframe.srcdoc = splitCode; });
			}
		}
	}

	/* ═══════════════════════════════════════════════
	   FILE UPLOAD
	   ═══════════════════════════════════════════════ */

	function handleFileSelect(e) {
		processFiles(e.target.files);
	}

	function handleDrop(e) {
		e.preventDefault();
		$dropzone?.classList.remove('dragging');
		processFiles(e.dataTransfer.files);
	}

	function processFiles(files) {
		Array.from(files).forEach(file => {
			if (!file.type.startsWith('image/')) return;
			const reader = new FileReader();
			reader.onload = (e) => {
				attachedImages.push(e.target.result);
				renderImagePreview();
			};
			reader.readAsDataURL(file);
		});
	}

	function renderImagePreview() {
		if (!$imagePreview) return;
		$imagePreview.innerHTML = attachedImages.map((img, i) =>
			`<img src="${img}" alt="Attached ${i + 1}" onclick="window.__bzcode_removeImage(${i})">`
		).join('');
	}

	window.__bzcode_removeImage = function (i) {
		attachedImages.splice(i, 1);
		renderImagePreview();
	};

	/* ═══════════════════════════════════════════════
	   CHAT MESSAGES
	   ═══════════════════════════════════════════════ */

	function addChatMessage(role, text, images) {
		if (!$chatMessages) return;
		// Remove empty-state placeholder
		const empty = $chatMessages.querySelector('.bzcode-chat__empty');
		if (empty) empty.remove();

		const row = document.createElement('div');
		row.className = 'bzcode-chat-msg bzcode-chat-msg--' + role;

		const bubble = document.createElement('div');
		bubble.className = 'bzcode-chat-bubble';

		let html = '';
		if (images?.length) {
			html += '<div class="bzcode-chat-msg__images">';
			images.forEach(img => { html += `<img src="${escapeAttr(img)}" alt="attached">`; });
			html += '</div>';
		}
		if (text) html += `<p>${escapeHtml(text)}</p>`;
		bubble.innerHTML = html;
		row.appendChild(bubble);
		$chatMessages.appendChild(row);
		$chatMessages.scrollTop = $chatMessages.scrollHeight;
	}

	function showGeneratingIndicator() {
		isGenerating = true;
		// Mark code area as streaming
		$codeArea?.classList.add('streaming');
		if ($codeEditor) $codeEditor.readOnly = true;
		// Chat animated dots
		if ($chatMessages && !$generatingMsg) {
			$generatingMsg = document.createElement('div');
			$generatingMsg.className = 'bzcode-chat-msg bzcode-chat-msg--system bzcode-generating';
			$generatingMsg.innerHTML =
				'<div class="bzcode-chat-bubble">' +
				'<div class="bzcode-dots"><span></span><span></span><span></span></div>' +
				'<p>AI đang tạo code...</p>' +
				'</div>';
			$chatMessages.appendChild($generatingMsg);
			$chatMessages.scrollTop = $chatMessages.scrollHeight;
		}
	}

	function hideGeneratingIndicator() {
		isGenerating = false;
		// Remove streaming state from code area
		$codeArea?.classList.remove('streaming');
		if ($codeEditor) $codeEditor.readOnly = false;
		if ($generatingMsg) { $generatingMsg.remove(); $generatingMsg = null; }
		// Preview will be updated by autoPublishAndPreview() after done event
	}

	/* ═══════════════════════════════════════════════
	   LOAD PROJECT
	   ═══════════════════════════════════════════════ */

	async function loadProject(id) {
		try {
			const res = await fetch(`${API}/project/${id}`, {
				headers: { 'X-WP-Nonce': nonce },
			});
			if (!res.ok) {
				console.error('[BZCode] loadProject HTTP', res.status);
				addChatMessage('system', '⚠️ Không tải được project (HTTP ' + res.status + ')');
				return;
			}
			const json = await res.json();
			if (!json.ok) {
				console.error('[BZCode] loadProject API error:', json);
				addChatMessage('system', '⚠️ ' + (json.error || 'Không tải được project.'));
				return;
			}

			const project = json.data;
			if ($startPane) $startPane.style.display = 'none';

			// If project has a published page URL, use it for preview
			if (project.publish_url) {
				publishedPageUrl = project.publish_url;
				const $del = document.getElementById('bzcode-btn-delete-page');
				if ($del) $del.style.display = '';
			}

			// Load first page's variants
			if (project.pages?.length) {
				const page = project.pages[0];
				variants = (page.variants || []).map(v => ({
					id: v.id,
					code: v.code || '',
					status: v.status || 'complete',
				}));

				const selIdx = (page.variants || []).findIndex(v => v.is_selected == 1);
				selectedVariant = selIdx >= 0 ? selIdx : 0;

				if (variants[selectedVariant] && variants[selectedVariant].code) {
					$codeEditor.value = variants[selectedVariant].code;
					if (publishedPageUrl) {
						refreshPreviewUrl();
					} else {
						updatePreview(variants[selectedVariant].code);
					}
				}
				renderVariantList();
			}

			$sendBtn.textContent = 'Viết code ▶';
			// Load sources and history for this project
			if (sourcesWidget) sourcesWidget.setParentId(currentProjectId);

			// Load generation history + reconstruct chat timeline
			await loadGenerationHistory();
			reconstructChatFromHistory();
		} catch (err) {
			console.error('[BZCode] loadProject error:', err);
			addChatMessage('system', '⚠️ Lỗi tải project: ' + err.message);
		}
	}

	/* ═══════════════════════════════════════════════
	   PUBLISH TO WP PAGE
	   ═══════════════════════════════════════════════ */

	/**
	 * Silent auto-publish: save code as WP page and load its URL into preview iframe.
	 * Called automatically after generation completes and on manual "Xuất bản" click.
	 */
	async function autoPublishAndPreview(title) {
		if (isPublishing) return;
		const code = variants[selectedVariant]?.code || $codeEditor?.value;
		if (!code || !currentProjectId) return;

		isPublishing = true;
		const $btn = document.getElementById('bzcode-btn-publish');
		if ($btn) { $btn.disabled = true; $btn.textContent = '⏳ Đang xuất bản...'; }

		try {
			const res = await fetch(`${API}/project/${currentProjectId}/publish-page`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ title: title || 'Landing Page AI', code }),
			});
			const json = await res.json();
			if (json.ok && json.page_url) {
				publishedPageUrl = json.page_url;
				// Refresh preview iframe with real URL
				refreshPreviewUrl();
				// Show delete button
				const $del = document.getElementById('bzcode-btn-delete-page');
				if ($del) $del.style.display = '';
			} else {
				console.warn('[BZCode] publish failed:', json.message || json.error);
			}
		} catch (err) {
			console.warn('[BZCode] publish error:', err.message);
		} finally {
			isPublishing = false;
			if ($btn) { $btn.disabled = false; $btn.textContent = '🚀 Xuất bản'; }
		}
	}

	/** Set preview iframe src to published page URL (bust cache with timestamp). */
	function refreshPreviewUrl() {
		if (!publishedPageUrl || !$previewIframe) return;
		const sep = publishedPageUrl.includes('?') ? '&' : '?';
		$previewIframe.removeAttribute('srcdoc');
		$previewIframe.src = publishedPageUrl + sep + '_t=' + Date.now();
	}

	/** Manual publish (button click) — just calls autoPublishAndPreview. */
	async function handlePublish() {
		if (isPublishing) {
			addChatMessage('system', '⏳ Đang xuất bản, vui lòng chờ...');
			return;
		}
		const code = variants[selectedVariant]?.code || $codeEditor?.value;
		if (!code) { alert('Chưa có code để xuất bản.'); return; }

		// Auto-save project if none exists yet
		if (!currentProjectId) {
			await saveCodeToServer(code, 'Landing Page AI');
			if (!currentProjectId) {
				addChatMessage('system', '❌ Không thể lưu project.');
				return;
			}
		}
		await autoPublishAndPreview();
		if (publishedPageUrl) {
			addChatMessage('assistant', `✅ Đã xuất bản: <a href="${publishedPageUrl}" target="_blank">${publishedPageUrl}</a>`);
		}
	}

	/** Delete the published WP page. */
	async function handleDeletePage() {
		if (!currentProjectId) return;
		if (!confirm('Xóa trang đã xuất bản?')) return;
		try {
			const res = await fetch(`${API}/project/${currentProjectId}/delete-page`, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': nonce },
			});
			const json = await res.json();
			if (json.ok) {
				publishedPageUrl = '';
				$previewIframe.removeAttribute('src');
				$previewIframe.srcdoc = $codeEditor?.value || '';
				addChatMessage('system', '🗑️ Đã xóa trang xuất bản.');
				const $del = document.getElementById('bzcode-btn-delete-page');
				if ($del) $del.style.display = 'none';
			} else {
				addChatMessage('system', '❌ ' + (json.message || 'Không xóa được.'));
			}
		} catch (err) {
			addChatMessage('system', '❌ Lỗi: ' + err.message);
		}
	}

	/* ═══════════════════════════════════════════════
	   GENERATION HISTORY (checkpoints)
	   ═══════════════════════════════════════════════ */

	let _generationsData = []; // cached for chat reconstruction

	async function loadGenerationHistory() {
		if (!currentProjectId || !$historyList) return;

		$historyList.innerHTML = '<div class="bzcode-spinner bzcode-spinner--sm"></div>';
		$historyList.style.display = 'flex';

		try {
			const res = await fetch(`${API}/project/${currentProjectId}/generations`, {
				headers: { 'X-WP-Nonce': nonce },
			});
			const json = await res.json();
			_generationsData = (json.ok && json.data) ? json.data : [];

			if (!_generationsData.length) {
				$historyList.innerHTML = '<p class="bzcode-history__empty">Chưa có checkpoint nào.</p>';
				if ($historyCount) $historyCount.textContent = '0';
				return;
			}

			if ($historyCount) $historyCount.textContent = _generationsData.length;

			$historyList.innerHTML = _generationsData.map(gen => {
				const date = new Date(gen.created_at).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
				const statusIcon = gen.status === 'complete' ? '✅' : gen.status === 'error' ? '❌' : '⏳';
				const actionLabel = { create: 'Tạo', edit: 'Sửa', sectional: 'Sectional', restore: '🔄 Khôi phục', import: 'Import' }[gen.action] || gen.action;
				const canRestore = gen.has_snapshot == 1 || gen.has_snapshot === '1';
				return `
					<div class="bzcode-history-card">
						<div class="bzcode-history-card__info">
							<span class="bzcode-history-card__status">${statusIcon}</span>
							<span class="bzcode-history-card__action">${actionLabel}</span>
							<span class="bzcode-history-card__date">${date}</span>
						</div>
						<div class="bzcode-history-card__prompt">${escapeHtml(gen.prompt || '—').substring(0, 80)}</div>
						<div class="bzcode-history-card__meta">
							${gen.model ? `<span class="bzcode-tag">${escapeHtml(gen.model)}</span>` : ''}
							${gen.duration_ms > 0 ? `<span>${(gen.duration_ms / 1000).toFixed(1)}s</span>` : ''}
							${gen.tokens_used > 0 ? `<span>${gen.tokens_used} tokens</span>` : ''}
						</div>
						${canRestore ? `<button class="bzcode-btn bzcode-btn--sm bzcode-btn--restore" onclick="window.__bzcode_restoreGen(${gen.id})">🔄 Khôi phục</button>` : ''}
					</div>
				`;
			}).join('');
		} catch (err) {
			$historyList.innerHTML = '<p class="bzcode-history__empty">Lỗi tải history.</p>';
		}
	}

	window.__bzcode_restoreGen = async function (genId) {
		if (!confirm('Khôi phục checkpoint này? Code hiện tại sẽ được giữ lại như một variant.')) return;

		try {
			const res = await fetch(`${API}/generation/restore`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ gen_id: genId }),
			});
			const json = await res.json();
			if (json.ok) {
				addChatMessage('assistant', '🔄 Đã khôi phục checkpoint — đang tải lại...');
				// Reload the project to pick up the new variant
				await loadProject(currentProjectId);
				loadGenerationHistory();
			} else {
				addChatMessage('system', '❌ ' + (json.error || json.message || 'Khôi phục thất bại.'));
			}
		} catch (err) {
			addChatMessage('system', '❌ Lỗi: ' + err.message);
		}
	};

	/**
	 * Reconstruct chat timeline from saved generations (so chat survives F5).
	 * Called once after loadProject + loadGenerationHistory.
	 */
	function reconstructChatFromHistory() {
		if (!_generationsData.length) return;
		// Generations are DESC — reverse to show oldest first
		const sorted = [..._generationsData].reverse();
		for (const gen of sorted) {
			if (gen.prompt) {
				addChatMessage('user', gen.prompt);
			}
			const actionLabel = { create: 'Tạo code', edit: 'Chỉnh sửa', sectional: 'Sectional', restore: 'Khôi phục', import: 'Import code' }[gen.action] || gen.action;
			if (gen.status === 'complete') {
				const dur = gen.duration_ms > 0 ? ' (' + (gen.duration_ms / 1000).toFixed(1) + 's)' : '';
				addChatMessage('assistant', '✅ ' + actionLabel + ' hoàn thành' + dur);
			} else if (gen.status === 'error') {
				addChatMessage('system', '❌ ' + actionLabel + ' thất bại: ' + (gen.error_message || 'Unknown error'));
			}
		}
	}

	/* ═══════════════════════════════════════════════
	   DOWNLOAD
	   ═══════════════════════════════════════════════ */

	function handleDownload() {
		const code = $codeEditor?.value;
		if (!code) return;

		const blob = new Blob([code], { type: 'text/html' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = 'index.html';
		a.click();
		URL.revokeObjectURL(url);
	}

	/* ═══════════════════════════════════════════════
	   UTILS
	   ═══════════════════════════════════════════════ */

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function escapeAttr(str) {
		return str
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function stripCodeFences(str) {
		if (!str) return str;
		// Remove leading ```html (or any language tag) and trailing ```
		return str
			.replace(/^\s*```[a-zA-Z]*\s*\n?/, '')
			.replace(/\n?```\s*$/, '');
	}

	/* ── Boot ── */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
