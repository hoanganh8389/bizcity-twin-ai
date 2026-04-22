/**
 * BZTwin Sources Widget — Shared source management component
 * Version: 1.0.0
 *
 * Self-contained vanilla JS widget for managing sources (file, URL, text, search).
 * Works in any plugin: bizcity-code, bizcity-doc, tool-mindmap, tool-slide, tool-pdf.
 *
 * Usage:
 *   const widget = BZTwinSources.init({
 *     container: '#my-sources-panel',   // or DOM element
 *     apiBase:   '/wp-json/bzcode/v1',  // REST namespace
 *     nonce:     'wp_rest_nonce',
 *     parentId:  42,
 *     parentField: 'project_id',        // field name for FK (project_id, doc_id, etc.)
 *     onSourcesChange: (sources) => {}, // fires after add/delete/toggle
 *     onMessage: (type,msg) => {},      // 'info'|'success'|'error' + message text
 *   });
 *
 * Public API:
 *   widget.getSources()           — all sources (with _selected flag)
 *   widget.getSelectedSources()   — only selected sources
 *   widget.reload()               — re-fetch from API
 *   widget.setParentId(id)        — update parent ID (after project creation)
 *   widget.destroy()              — remove widget DOM + listeners
 */
;(function (root) {
	'use strict';

	const PREFIX = 'bztw-src';

	/* ────────────────────────────────────────────────
	   HTML TEMPLATE
	   ──────────────────────────────────────────────── */
	function buildHTML() {
		return `
<div class="${PREFIX}-header">
	<h3 class="${PREFIX}-title">📁 Nguồn</h3>
</div>
<div class="${PREFIX}-actions">
	<button type="button" class="${PREFIX}-btn-add" data-action="toggle-form">＋ Thêm nguồn</button>
</div>
<div class="${PREFIX}-form" style="display:none;" data-ref="form">
	<div class="${PREFIX}-tabs" data-ref="tabs">
		<button type="button" class="${PREFIX}-tab ${PREFIX}-tab--active" data-tab="file">📄 File</button>
		<button type="button" class="${PREFIX}-tab" data-tab="url">🌐 URL</button>
		<button type="button" class="${PREFIX}-tab" data-tab="text">📝 Text</button>
		<button type="button" class="${PREFIX}-tab" data-tab="search">🔍 Tìm kiếm</button>
	</div>
	<div class="${PREFIX}-panel ${PREFIX}-panel--active" data-panel="file">
		<div class="${PREFIX}-dropzone" data-ref="dropzone">
			<p>📄 Kéo thả file vào đây</p>
			<p class="${PREFIX}-hint">PDF, DOCX, XLSX, TXT, JSON, CSV, MD</p>
			<input type="file" accept=".pdf,.docx,.pptx,.xlsx,.txt,.csv,.json,.md" hidden data-ref="file-input">
		</div>
	</div>
	<div class="${PREFIX}-panel" data-panel="url">
		<div class="${PREFIX}-input-row">
			<input type="url" placeholder="Dán URL hoặc tìm trên web..." autocomplete="off" spellcheck="false" data-ref="url-input">
			<button type="button" class="${PREFIX}-btn ${PREFIX}-btn--primary" data-action="add-url">Thêm</button>
		</div>
	</div>
	<div class="${PREFIX}-panel" data-panel="text">
		<textarea placeholder="Paste nội dung văn bản..." rows="4" spellcheck="false" data-ref="text-input"></textarea>
		<button type="button" class="${PREFIX}-btn ${PREFIX}-btn--primary ${PREFIX}-btn--block" data-action="add-text">📝 Thêm text</button>
	</div>
	<div class="${PREFIX}-panel" data-panel="search">
		<div class="${PREFIX}-input-row">
			<input type="text" placeholder="Tìm kiếm thông tin..." spellcheck="false" data-ref="search-input">
			<button type="button" class="${PREFIX}-btn ${PREFIX}-btn--primary" data-action="search">🔍</button>
		</div>
		<div class="${PREFIX}-search-results" data-ref="search-results"></div>
	</div>
</div>
<div class="${PREFIX}-list" data-ref="list"></div>
<div class="${PREFIX}-footer" style="display:none;" data-ref="footer">
	<label class="${PREFIX}-select-all">
		<input type="checkbox" checked data-action="select-all"> Chọn tất cả nguồn
	</label>
</div>`;
	}

	/* ────────────────────────────────────────────────
	   WIDGET CONSTRUCTOR
	   ──────────────────────────────────────────────── */
	function SourcesWidget(opts) {
		this.opts = Object.assign({
			container:       null,
			apiBase:         '',
			nonce:           '',
			parentId:        0,
			parentField:     'project_id',
			onSourcesChange: null,
			onMessage:       null,
			onEnsureParent:  null,  // async callback: returns new parentId if parentId===0
		}, opts);

		this.sources       = [];
		this._searchCache  = [];
		this._el           = null;   // container DOM
		this._refs         = {};     // data-ref elements
		this._abortCtrl    = null;

		this._mount();
		if (this.opts.parentId) this.reload();
	}

	/* ── Mount / Destroy ── */

	SourcesWidget.prototype._mount = function () {
		var container = this.opts.container;
		if (typeof container === 'string') {
			container = document.querySelector(container);
		}
		if (!container) {
			console.error('[BZTwinSources] Container not found:', this.opts.container);
			return;
		}
		this._el = container;
		this._el.innerHTML = buildHTML();
		this._cacheRefs();
		this._bindEvents();
	};

	SourcesWidget.prototype.destroy = function () {
		if (this._el) this._el.innerHTML = '';
		this._refs = {};
		this.sources = [];
	};

	/* ── DOM Ref Cache ── */

	SourcesWidget.prototype._cacheRefs = function () {
		var el = this._el;
		var refs = {};
		el.querySelectorAll('[data-ref]').forEach(function (node) {
			refs[node.dataset.ref] = node;
		});
		this._refs = refs;
	};

	SourcesWidget.prototype._ref = function (name) {
		return this._refs[name] || null;
	};

	/* ── Event Binding (delegation) ── */

	SourcesWidget.prototype._bindEvents = function () {
		var self = this;
		var el = this._el;

		// Delegated click handler
		el.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-action]');
			if (!btn) return;

			var action = btn.dataset.action;
			switch (action) {
				case 'toggle-form':   self._toggleForm(); break;
				case 'add-url':       self._addUrl(); break;
				case 'add-text':      self._addText(); break;
				case 'search':        self._search(); break;
				case 'import-search': self._importSearch(); break;
				case 'delete-source': self._deleteSource(parseInt(btn.dataset.id)); break;
				case 'select-all':    self._toggleSelectAll(btn.checked !== undefined ? btn.checked : btn.querySelector('input')?.checked); break;
			}
		});

		// Delegated change handler (checkboxes)
		el.addEventListener('change', function (e) {
			if (e.target.matches('[data-action="select-all"]')) {
				self._toggleSelectAll(e.target.checked);
				return;
			}
			if (e.target.matches('[data-source-idx]')) {
				var idx = parseInt(e.target.dataset.sourceIdx);
				if (self.sources[idx]) {
					self.sources[idx]._selected = e.target.checked;
					self._fireChange();
				}
			}
		});

		// Tab switching (delegation)
		el.addEventListener('click', function (e) {
			var tab = e.target.closest('.' + PREFIX + '-tab');
			if (!tab) return;
			self._switchTab(tab.dataset.tab);
		});

		// Enter key on url/search inputs
		el.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') return;
			var ref = e.target.dataset.ref;
			if (ref === 'url-input') self._addUrl();
			if (ref === 'search-input') self._search();
		});

		// Dropzone
		var dz = this._ref('dropzone');
		var fi = this._ref('file-input');
		if (dz && fi) {
			dz.addEventListener('click', function () { fi.click(); });
			dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add(PREFIX + '-dragging'); });
			dz.addEventListener('dragleave', function () { dz.classList.remove(PREFIX + '-dragging'); });
			dz.addEventListener('drop', function (e) {
				e.preventDefault();
				dz.classList.remove(PREFIX + '-dragging');
				if (e.dataTransfer.files.length) self._uploadFile(e.dataTransfer.files[0]);
			});
			fi.addEventListener('change', function () {
				if (fi.files.length) self._uploadFile(fi.files[0]);
				fi.value = '';
			});
		}
	};

	/* ── Tab Switching ── */

	SourcesWidget.prototype._switchTab = function (tabName) {
		this._el.querySelectorAll('.' + PREFIX + '-tab').forEach(function (t) {
			t.classList.toggle(PREFIX + '-tab--active', t.dataset.tab === tabName);
		});
		this._el.querySelectorAll('.' + PREFIX + '-panel').forEach(function (p) {
			p.classList.toggle(PREFIX + '-panel--active', p.dataset.panel === tabName);
		});
	};

	/* ── Toggle Form ── */

	SourcesWidget.prototype._toggleForm = function () {
		var form = this._ref('form');
		if (!form) return;
		var isHidden = form.style.display === 'none';
		form.style.display = isHidden ? 'block' : 'none';
	};

	/* ── API Helpers ── */

	SourcesWidget.prototype._api = function (path, opts) {
		var url = this.opts.apiBase + path;
		var defaults = {
			headers: { 'X-WP-Nonce': this.opts.nonce },
		};
		if (opts && opts.body && typeof opts.body === 'string') {
			defaults.headers['Content-Type'] = 'application/json';
		}
		var merged = Object.assign(defaults, opts || {});
		merged.headers = Object.assign(defaults.headers, (opts && opts.headers) || {});
		return fetch(url, merged).then(function (r) { return r.json(); });
	};

	/* ── Load Sources ── */

	SourcesWidget.prototype.reload = function () {
		var self = this;
		if (!this.opts.parentId) return Promise.resolve();

		return this._api('/project/' + this.opts.parentId + '/sources')
			.then(function (json) {
				if (json.ok && json.data) {
					self.sources = json.data.map(function (s) {
						return Object.assign({}, s, { _selected: true });
					});
				} else {
					self.sources = [];
				}
				self._renderList();
				self._fireChange();
			})
			.catch(function (err) {
				console.error('[BZTwinSources] Load error:', err);
			});
	};

	/* ── Render Source List ── */

	SourcesWidget.prototype._renderList = function () {
		var list = this._ref('list');
		var footer = this._ref('footer');
		if (!list) return;

		if (!this.sources.length) {
			list.innerHTML = '<p class="' + PREFIX + '-empty">Chưa có nguồn nào.</p>';
			if (footer) footer.style.display = 'none';
			return;
		}

		var icons = { file: '📄', url: '🌐', text: '📝', search: '🔍' };
		list.innerHTML = this.sources.map(function (s, i) {
			return '<div class="' + PREFIX + '-item">' +
				'<input type="checkbox" class="' + PREFIX + '-item-check" data-source-idx="' + i + '"' + (s._selected ? ' checked' : '') + '>' +
				'<span class="' + PREFIX + '-item-icon">' + (icons[s.source_type] || '📁') + '</span>' +
				'<div class="' + PREFIX + '-item-info">' +
					'<div class="' + PREFIX + '-item-title">' + escapeHtml(s.title || 'Untitled') + '</div>' +
					'<div class="' + PREFIX + '-item-meta">' + s.source_type + ' · ' + (s.token_estimate || 0) + ' tokens</div>' +
				'</div>' +
				'<button type="button" class="' + PREFIX + '-item-delete" data-action="delete-source" data-id="' + s.id + '" title="Xóa">✕</button>' +
			'</div>';
		}).join('');

		if (footer) footer.style.display = 'flex';
	};

	/* ── Ensure Parent exists (auto-create if callback provided) ── */

	SourcesWidget.prototype._ensureParent = function () {
		var self = this;
		if (this.opts.parentId) return Promise.resolve(true);
		if (typeof this.opts.onEnsureParent !== 'function') {
			this._msg('error', '⚠️ Vui lòng tạo project trước khi thêm nguồn.');
			return Promise.resolve(false);
		}
		return Promise.resolve(this.opts.onEnsureParent()).then(function (newId) {
			if (newId) { self.opts.parentId = newId; console.log('[BZTwinSources] Parent created:', newId); return true; }
			self._msg('error', '❌ Không thể tạo project. Vui lòng thử lại.');
			return false;
		});
	};

	/* ── Upload File ── */

	SourcesWidget.prototype._uploadFile = function (file) {
		var self = this;
		this._ensureParent().then(function (ok) {
			if (!ok) return;

			var formData = new FormData();
			formData.append('file', file);
			formData.append(self.opts.parentField, self.opts.parentId);

			self._msg('info', '📄 Đang upload "' + file.name + '"...');

			fetch(self.opts.apiBase + '/source/upload', {
				method: 'POST',
				headers: { 'X-WP-Nonce': self.opts.nonce },
				body: formData,
			})
			.then(function (r) { return r.json(); })
			.then(function (json) {
			if (json.ok) {
				self._msg('success', '✅ Đã thêm nguồn: ' + (json.data?.title || file.name));
				self.reload();
			} else {
				self._msg('error', '❌ ' + (json.error || 'Upload thất bại'));
			}
		})
		.catch(function (err) {
			self._msg('error', '❌ Lỗi upload: ' + err.message);
		});
		}); // end _ensureParent
	};

	/* ── Add URL Source ── */

	SourcesWidget.prototype._addUrl = function () {
		var input = this._ref('url-input');
		var url = input?.value?.trim();
		if (!url) return;

		var self = this;
		this._ensureParent().then(function (ok) {
			if (!ok) return;

			self._msg('info', '🌐 Đang tải nội dung từ URL...');

			var body = { url: url };
			body[self.opts.parentField] = self.opts.parentId;

			self._api('/source/add-url', {
				method: 'POST',
				body: JSON.stringify(body),
			}).then(function (json) {
				if (json.ok) {
					self._msg('success', '✅ Đã thêm nguồn từ URL');
					input.value = '';
					self.reload();
				} else {
					self._msg('error', '❌ ' + (json.error || 'Không thể tải URL'));
				}
			}).catch(function (err) {
				self._msg('error', '❌ Lỗi: ' + err.message);
			});
		}); // end _ensureParent
	};

	SourcesWidget.prototype._addText = function () {
		var input = this._ref('text-input');
		var text = input?.value?.trim();
		if (!text) return;

		var self = this;
		this._ensureParent().then(function (ok) {
			if (!ok) return;

			var body = { title: 'Text paste', content: text };
			body[self.opts.parentField] = self.opts.parentId;

			self._api('/source/add-text', {
				method: 'POST',
				body: JSON.stringify(body),
			}).then(function (json) {
				if (json.ok) {
					self._msg('success', '✅ Đã thêm nguồn text');
					input.value = '';
					self.reload();
				} else {
					self._msg('error', '❌ ' + (json.error || 'Thêm text thất bại'));
				}
			}).catch(function (err) {
				self._msg('error', '❌ Lỗi: ' + err.message);
			});
		}); // end _ensureParent — _addText
	};

	SourcesWidget.prototype._search = function () {
		var input = this._ref('search-input');
		var query = input?.value?.trim();
		if (!query) return;

		var self = this;
		var $results = this._ref('search-results');
		if ($results) $results.innerHTML = '<div class="' + PREFIX + '-spinner"></div>';

		this._api('/source/search', {
			method: 'POST',
			body: JSON.stringify({ query: query }),
		}).then(function (json) {
			if (json.ok && json.data?.length) {
				self._searchCache = json.data;
				$results.innerHTML = json.data.map(function (r, i) {
					return '<div class="' + PREFIX + '-search-item">' +
						'<input type="checkbox" class="' + PREFIX + '-search-check" data-search-idx="' + i + '" checked>' +
						'<div class="' + PREFIX + '-search-info">' +
							'<div class="' + PREFIX + '-search-title">' + escapeHtml(r.title || r.url) + '</div>' +
							'<div class="' + PREFIX + '-search-snippet">' + escapeHtml(r.snippet || '') + '</div>' +
						'</div>' +
					'</div>';
				}).join('') +
				'<div class="' + PREFIX + '-search-actions">' +
					'<button type="button" class="' + PREFIX + '-btn ' + PREFIX + '-btn--primary" data-action="import-search">📥 Import kết quả đã chọn</button>' +
				'</div>';
			} else {
				$results.innerHTML = '<p class="' + PREFIX + '-empty">Không tìm thấy kết quả.</p>';
			}
		}).catch(function () {
			if ($results) $results.innerHTML = '<p class="' + PREFIX + '-error">Lỗi tìm kiếm.</p>';
		});
	};

	/* ── Import Search Results ── */

	SourcesWidget.prototype._importSearch = function () {
		var results = this._searchCache || [];
		var checked = [];
		this._el.querySelectorAll('.' + PREFIX + '-search-check').forEach(function (cb, i) {
			if (cb.checked && results[i]) checked.push(results[i]);
		});
		if (!checked.length) return;

		var self = this;
		this._ensureParent().then(function (ok) {
			if (!ok) return;

			var body = { results: checked };
			body[self.opts.parentField] = self.opts.parentId;

			self._msg('info', '📥 Đang import ' + checked.length + ' kết quả...');

			self._api('/source/search/import', {
				method: 'POST',
				body: JSON.stringify(body),
			}).then(function (json) {
				if (json.ok) {
					self._msg('success', '✅ Đã import ' + (json.imported || checked.length) + ' nguồn');
					var sr = self._ref('search-results');
					if (sr) sr.innerHTML = '';
					var si = self._ref('search-input');
					if (si) si.value = '';
					self._searchCache = [];
					self.reload();
				} else {
					self._msg('error', '❌ ' + (json.error || 'Import thất bại'));
				}
			}).catch(function (err) {
				self._msg('error', '❌ Lỗi: ' + err.message);
			});
		}); // end _ensureParent — _importSearch
	};

	SourcesWidget.prototype._deleteSource = function (sourceId) {
		if (!sourceId || !confirm('Xóa nguồn này?')) return;

		var self = this;
		this._api('/source/' + sourceId, { method: 'DELETE' })
			.then(function (json) {
				if (json.ok) {
					self.sources = self.sources.filter(function (s) { return s.id != sourceId; });
					self._renderList();
					self._fireChange();
				} else {
					self._msg('error', '❌ ' + (json.error || 'Xóa thất bại'));
				}
			})
			.catch(function (err) {
				self._msg('error', '❌ Lỗi: ' + err.message);
			});
	};

	/* ── Select All Toggle ── */

	SourcesWidget.prototype._toggleSelectAll = function (checked) {
		this.sources.forEach(function (s) { s._selected = checked; });
		this._renderList();
		this._fireChange();
	};

	/* ── Public Getters ── */

	SourcesWidget.prototype.getSources = function () {
		return this.sources;
	};

	SourcesWidget.prototype.getSelectedSources = function () {
		return this.sources.filter(function (s) { return s._selected; });
	};

	SourcesWidget.prototype.setParentId = function (id) {
		this.opts.parentId = id;
		if (id) this.reload();
	};

	/* ── Helpers ── */

	SourcesWidget.prototype._fireChange = function () {
		if (typeof this.opts.onSourcesChange === 'function') {
			this.opts.onSourcesChange(this.sources);
		}
	};

	SourcesWidget.prototype._msg = function (type, text) {
		if (typeof this.opts.onMessage === 'function') {
			this.opts.onMessage(type, text);
		}
	};

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str || ''));
		return div.innerHTML;
	}

	/* ────────────────────────────────────────────────
	   PUBLIC FACTORY
	   ──────────────────────────────────────────────── */
	root.BZTwinSources = {
		init: function (opts) {
			return new SourcesWidget(opts);
		},
		VERSION: '1.0.0',
	};

})(window);
