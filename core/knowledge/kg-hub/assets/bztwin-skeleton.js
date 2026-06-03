/*!
 * BizCity Twin — Notebook Skeleton shared FE bundle.
 *
 * Reference: PHASE-0-RULE-SKELETON.md
 *   §5 RULE-3 — <bztwin-notebook-selector>  (S0.7)
 *   §6 RULE-4 — <bztwin-skeleton-preview>   (S0.8)
 *   §7        — useNotebookSkeleton hook    (S0.9)
 *
 * Vanilla Web Components + ES2015. Zero deps. Idempotent.
 * Consumers (TwinChat React app, bizcity-doc, admin pages) just enqueue
 * 'bztwin-skeleton' (handle registered by BizCity_KG_Skeleton_Assets).
 *
 * Public API:
 *   window.BizTwinSkeleton = {
 *     api:   { list, getStatus, getSkeleton, rebuild },
 *     useNotebookSkeleton(notebookId, opts) -> { promise, stop() },
 *     statusBadge(status) -> string (HTML),
 *     version: '1.0.0',
 *   }
 *
 * Server config injected via wp_localize_script as `BizTwinSkeletonConfig`:
 *   { restRoot: '/wp-json/', nonce: '...', blogId: 1 }
 */
(function (global) {
	'use strict';

	if (global.BizTwinSkeleton && global.BizTwinSkeleton.version) {
		return; // Idempotent — never double-define.
	}

	var CFG = global.BizTwinSkeletonConfig || { restRoot: '/wp-json/', nonce: '', blogId: 0 };
	var NS  = 'bizcity/kg/v1';

	/* ── REST helpers ─────────────────────────────────────────────────── */

	function _url(path) {
		var root = String(CFG.restRoot || '/wp-json/').replace(/\/+$/, '');
		return root + '/' + NS + path;
	}

	function _fetch(path, opts) {
		opts = opts || {};
		var headers = opts.headers || {};
		headers['X-WP-Nonce'] = CFG.nonce || '';
		if (opts.body && !headers['Content-Type']) {
			headers['Content-Type'] = 'application/json';
		}
		return fetch(_url(path), {
			method:      opts.method || 'GET',
			credentials: 'same-origin',
			headers:     headers,
			body:        opts.body || undefined,
		}).then(function (r) {
			return r.text().then(function (txt) {
				if (!r.ok) {
					throw new Error('[bztwin-skeleton] HTTP ' + r.status + ': ' + txt.slice(0, 200));
				}
				try { return txt ? JSON.parse(txt) : null; }
				catch (e) { throw new Error('[bztwin-skeleton] non-JSON: ' + txt.slice(0, 200)); }
			});
		});
	}

	var API = {
		list:        function ()                  { return _fetch('/notebooks'); },
		getStatus:   function (id)                { return _fetch('/notebook/' + (+id) + '/skeleton/status'); },
		getSkeleton: function (id)                { return _fetch('/notebook/' + (+id) + '/skeleton'); },
		rebuild:     function (id, force)         { return _fetch('/notebook/' + (+id) + '/skeleton/rebuild',
		                                                          { method: 'POST', body: JSON.stringify({ force: !!force }) }); },
	};

	/* ── useNotebookSkeleton (S0.9) ───────────────────────────────────── */

	function useNotebookSkeleton(notebookId, opts) {
		opts = opts || {};
		var intervalMs = opts.intervalMs || 3000;
		var timeoutMs  = opts.timeoutMs  || 60000;
		var onStatus   = typeof opts.onStatus === 'function' ? opts.onStatus : function () {};
		var onReady    = typeof opts.onReady  === 'function' ? opts.onReady  : function () {};

		var stopped   = false;
		var startedAt = Date.now();
		var timerId   = 0;

		var promise = new Promise(function (resolve, reject) {
			function tick() {
				if (stopped) { return; }
				if (Date.now() - startedAt > timeoutMs) {
					stopped = true;
					return reject(new Error('skeleton-poll-timeout'));
				}
				API.getStatus(notebookId).then(function (state) {
					if (stopped) { return; }
					try { onStatus(state); } catch (e) {}
					var st = (state && state.status) || 'none';
					if (st === 'ready') {
						API.getSkeleton(notebookId).then(function (sk) {
							if (stopped) { return; }
							stopped = true;
							try { onReady(sk); } catch (e) {}
							resolve(sk);
						}, reject);
					} else if (st === 'failed') {
						stopped = true;
						reject(new Error('skeleton-failed'));
					} else {
						timerId = setTimeout(tick, intervalMs);
					}
				}, function (err) {
					if (stopped) { return; }
					timerId = setTimeout(tick, intervalMs); // transient — keep polling.
				});
			}
			tick();
		});

		return {
			promise: promise,
			stop:    function () { stopped = true; if (timerId) { clearTimeout(timerId); } },
		};
	}

	/* ── statusBadge ──────────────────────────────────────────────────── */

	var BADGES = {
		ready:    { icon: '📋', label: 'Sẵn sàng',  cls: 'is-ready'    },
		building: { icon: '⏳', label: 'Đang dựng', cls: 'is-building' },
		pending:  { icon: '🕒', label: 'Chờ',       cls: 'is-pending'  },
		stale:    { icon: '⚠️',  label: 'Cũ',        cls: 'is-stale'    },
		failed:   { icon: '❌', label: 'Lỗi',       cls: 'is-failed'   },
		none:     { icon: '—',  label: '—',         cls: 'is-none'     },
	};

	function statusBadge(status) {
		var b = BADGES[status] || BADGES.none;
		return '<span class="bztwin-skel-badge ' + b.cls + '" title="skeleton:' + status + '">'
		     + b.icon + ' ' + b.label + '</span>';
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function renderTree(nodes) {
		if (!nodes || !nodes.length) { return ''; }
		var html = '<ul class="bztwin-skel-tree">';
		for (var i = 0; i < nodes.length; i++) {
			var n = nodes[i] || {};
			html += '<li class="bztwin-skel-node"><strong>' + esc(n.label || '(không nhãn)') + '</strong>';
			if (n.summary) { html += ' — <em>' + esc(n.summary) + '</em>'; }
			if (n.children && n.children.length) { html += renderTree(n.children); }
			html += '</li>';
		}
		return html + '</ul>';
	}

	function renderSkeleton(sk) {
		if (!sk) { return '<div class="bztwin-skel-empty">Chưa có skeleton.</div>'; }
		var html = '';
		if (sk.nucleus) {
			html += '<div class="bztwin-skel-nucleus">'
			      +   '<div class="bztwin-skel-h">' + esc(sk.nucleus.title || '(chưa có chủ đề)') + '</div>';
			if (sk.nucleus.thesis) {
				html += '<div class="bztwin-skel-thesis">' + esc(sk.nucleus.thesis) + '</div>';
			}
			html += '</div>';
		}
		if (sk.skeleton && sk.skeleton.length) {
			html += renderTree(sk.skeleton);
		}
		if (sk.key_points && sk.key_points.length) {
			html += '<div class="bztwin-skel-kp"><strong>Key points</strong><ul>';
			for (var i = 0; i < sk.key_points.length; i++) {
				html += '<li>' + esc(sk.key_points[i]) + '</li>';
			}
			html += '</ul></div>';
		}
		var meta = sk.meta || {};
		html += '<div class="bztwin-skel-meta">v' + esc(meta.schema_version || 1)
		      + ' · ' + esc(meta.source_count || 0) + ' sources'
		      + ' · ' + esc(meta.chunk_count  || 0) + ' chunks'
		      + (meta.model ? ' · ' + esc(meta.model) : '')
		      + '</div>';
		return html;
	}

	/* ── Custom Element factory (Reflect.construct safe) ──────────────── */

	function defineCE(name, ctor) {
		if (!global.customElements || global.customElements.get(name)) { return; }
		try { global.customElements.define(name, ctor); }
		catch (e) { try { console.warn('[bztwin-skeleton] define failed', name, e); } catch (_) {} }
	}

	/* ── <bztwin-notebook-selector> (S0.7) ────────────────────────────── */

	function NotebookSelector() {
		var self = Reflect.construct(HTMLElement, [], NotebookSelector);
		self._notebooks  = [];
		self._previewEl  = null;
		return self;
	}
	NotebookSelector.prototype = Object.create(HTMLElement.prototype);
	NotebookSelector.prototype.constructor = NotebookSelector;
	NotebookSelector.observedAttributes = ['value', 'placeholder', 'show-preview'];

	NotebookSelector.prototype.connectedCallback = function () {
		var self = this;
		this.classList.add('bztwin-skel-selector');
		this.innerHTML = '<select class="bztwin-skel-select" disabled>'
		               +   '<option>Đang tải notebook…</option></select>'
		               + '<span class="bztwin-skel-status"></span>'
		               + '<div class="bztwin-skel-mount"></div>';
		this._select = this.querySelector('select');
		this._status = this.querySelector('.bztwin-skel-status');
		this._mount  = this.querySelector('.bztwin-skel-mount');

		this._select.addEventListener('change', function () {
			var id = parseInt(self._select.value, 10) || 0;
			self.setAttribute('value', String(id));
			self.dispatchEvent(new CustomEvent('change', { detail: { notebookId: id }, bubbles: true }));
			self._renderPreview(id);
		});

		API.list().then(function (rows) {
			self._notebooks = (rows && (rows.items || rows.notebooks)) || (Array.isArray(rows) ? rows : []);
			self._renderOptions();
		}).catch(function (err) {
			self._select.innerHTML = '<option>(lỗi tải)</option>';
			self._status.textContent = String(err && err.message || err);
		});
	};

	NotebookSelector.prototype._renderOptions = function () {
		var ph = this.getAttribute('placeholder') || '— chọn notebook —';
		var cur = parseInt(this.getAttribute('value') || '0', 10);
		var html = '<option value="0">' + esc(ph) + '</option>';
		for (var i = 0; i < this._notebooks.length; i++) {
			var nb = this._notebooks[i] || {};
			var id = parseInt(nb.id || nb.notebook_id || 0, 10);
			var st = nb.skeleton_status || 'none';
			var ico = (BADGES[st] || BADGES.none).icon;
			var sel = (id === cur) ? ' selected' : '';
			html += '<option value="' + id + '"' + sel + '>'
			      + ico + ' ' + esc(nb.title || nb.name || ('Notebook #' + id))
			      + '</option>';
		}
		this._select.innerHTML = html;
		this._select.disabled = false;
		if (cur) { this._renderPreview(cur); }
	};

	NotebookSelector.prototype._renderPreview = function (id) {
		if (!this.hasAttribute('show-preview')) { this._mount.innerHTML = ''; return; }
		if (!id) { this._mount.innerHTML = ''; return; }
		this._mount.innerHTML = '';
		var p = document.createElement('bztwin-skeleton-preview');
		p.setAttribute('notebook-id', String(id));
		p.setAttribute('expanded', '');
		this._mount.appendChild(p);
	};

	NotebookSelector.prototype.attributeChangedCallback = function (name, _old, val) {
		if (name === 'value' && this._select && this._select.value !== val) {
			this._select.value = val || '0';
			this._renderPreview(parseInt(val || '0', 10));
		}
	};

	defineCE('bztwin-notebook-selector', NotebookSelector);

	/* ── <bztwin-skeleton-preview> (S0.8) ─────────────────────────────── */

	function SkeletonPreview() {
		var self = Reflect.construct(HTMLElement, [], SkeletonPreview);
		self._poll     = null;
		self._lastSk   = null;
		self._lastSt   = 'none';
		self._expanded = true;
		return self;
	}
	SkeletonPreview.prototype = Object.create(HTMLElement.prototype);
	SkeletonPreview.prototype.constructor = SkeletonPreview;
	SkeletonPreview.observedAttributes = ['notebook-id', 'expanded'];

	SkeletonPreview.prototype.connectedCallback = function () {
		var self = this;
		this._expanded = this.hasAttribute('expanded');
		this.classList.add('bztwin-skel-panel');
		this.innerHTML = ''
			+ '<div class="bztwin-skel-head">'
			+   '<span class="bztwin-skel-title">📋 Skeleton</span>'
			+   '<span class="bztwin-skel-badge-slot">' + statusBadge('none') + '</span>'
			+   '<button type="button" data-act="toggle" title="Thu/mở">' + (this._expanded ? '−' : '+') + '</button>'
			+   '<button type="button" data-act="rebuild" title="Dựng lại">♻ Dựng lại</button>'
			+ '</div>'
			+ '<div class="bztwin-skel-body bztwin-skel-loading">Đang tải…</div>';
		this._head = this.querySelector('.bztwin-skel-head');
		this._badge = this.querySelector('.bztwin-skel-badge-slot');
		this._body = this.querySelector('.bztwin-skel-body');

		this._head.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest && e.target.closest('button[data-act]');
			if (!btn) { return; }
			if (btn.getAttribute('data-act') === 'toggle') {
				self._expanded = !self._expanded;
				btn.textContent = self._expanded ? '−' : '+';
				self._body.style.display = self._expanded ? '' : 'none';
			} else if (btn.getAttribute('data-act') === 'rebuild') {
				var id = parseInt(self.getAttribute('notebook-id') || '0', 10);
				if (!id) { return; }
				btn.disabled = true;
				API.rebuild(id, true).then(function () { self._refresh(); })
				                     .catch(function (err) { self._showError(err); })
				                     .then(function () { btn.disabled = false; });
			}
		});

		this._refresh();
	};

	SkeletonPreview.prototype.disconnectedCallback = function () {
		if (this._poll) { this._poll.stop(); this._poll = null; }
	};

	SkeletonPreview.prototype.attributeChangedCallback = function (name, _old, _val) {
		if (!this.isConnected) { return; }
		if (name === 'notebook-id') { this._refresh(); }
		if (name === 'expanded') {
			this._expanded = this.hasAttribute('expanded');
			if (this._body) { this._body.style.display = this._expanded ? '' : 'none'; }
		}
	};

	SkeletonPreview.prototype._setBadge = function (status) {
		this._lastSt = status;
		if (this._badge) { this._badge.innerHTML = statusBadge(status); }
		// PHASE-0-RULE-SKELETON S0.14 — surface a top banner whenever the
		// upstream skeleton has moved past the snapshot the host artifact was
		// authored against (status==='stale'). Lightweight: just toggles a
		// child element, no shadow DOM.
		var banner = this.querySelector('.bztwin-skel-stale-banner');
		if (status === 'stale') {
			if (!banner) {
				banner = document.createElement('div');
				banner.className = 'bztwin-skel-stale-banner';
				banner.innerHTML = '⚠️ Skeleton nguồn đã cập nhật — nhấn <strong>♻ Dựng lại</strong> để đồng bộ phiên bản mới.';
				this.insertBefore(banner, this._body);
			}
		} else if (banner) {
			banner.parentNode.removeChild(banner);
		}
	};

	SkeletonPreview.prototype._showError = function (err) {
		if (!this._body) { return; }
		this._body.classList.remove('bztwin-skel-loading');
		this._body.innerHTML = '<div class="bztwin-skel-err">' + esc(err && err.message || err) + '</div>';
	};

	SkeletonPreview.prototype._refresh = function () {
		var self = this;
		var id = parseInt(this.getAttribute('notebook-id') || '0', 10);
		if (this._poll) { this._poll.stop(); this._poll = null; }
		if (!id) {
			this._setBadge('none');
			this._body.classList.remove('bztwin-skel-loading');
			this._body.innerHTML = '<div class="bztwin-skel-empty">(chưa chọn notebook)</div>';
			return;
		}
		this._body.classList.add('bztwin-skel-loading');
		this._body.innerHTML = 'Đang tải skeleton…';

		this._poll = useNotebookSkeleton(id, {
			intervalMs: 3000,
			timeoutMs:  90000,
			onStatus:   function (state) { self._setBadge((state && state.status) || 'none'); },
			onReady:    function (sk)    { self._lastSk = sk; },
		});
		this._poll.promise.then(function (sk) {
			self._body.classList.remove('bztwin-skel-loading');
			self._body.innerHTML = renderSkeleton(sk);
		}).catch(function (err) {
			self._showError(err);
		});
	};

	defineCE('bztwin-skeleton-preview', SkeletonPreview);

	/* ── Public API ───────────────────────────────────────────────────── */

	global.BizTwinSkeleton = {
		api:                 API,
		useNotebookSkeleton: useNotebookSkeleton,
		statusBadge:         statusBadge,
		version:             '1.0.0',
	};

})(typeof window !== 'undefined' ? window : this);
