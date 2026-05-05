/**
 * Bizcity Twin Shell — Primitives bundle (Phase 0.13 W1).
 *
 * Vanilla JS, no build. Exposes window.BizcityTwin with cross-plugin UI:
 *   - pickNotebook({ allowCreate, currentId, title, hostHint })
 *       → Promise<{ notebook_id, title, is_new }>
 *   - openSourcePanel({ notebook_id, host, on_change })   [W2 stub]
 *   - openLearningMonitor({ notebook_id })                [W4 stub]
 *   - notifyNotebookActive(notebook_id)
 *   - isInShell()
 *
 * Same code runs in iframe-shell mode AND standalone mode. The picker
 * mounts its modal into the local document either way.
 */
(function () {
  'use strict';

  if (window.BizcityTwin && window.BizcityTwin.__loaded) return;

  var CFG = window.BIZCITY_TWIN_PRIMITIVES_CFG || {};
  var REST_ROOT = (CFG.restRoot || '').replace(/\/+$/, '') + '/';
  var NONCE = CFG.nonce || '';

  // ── Utilities ──────────────────────────────────────────────────────────
  function $(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (k === 'class') el.className = attrs[k];
        else if (k === 'style' && typeof attrs[k] === 'object') Object.assign(el.style, attrs[k]);
        else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') el.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
        else if (attrs[k] !== null && attrs[k] !== undefined) el.setAttribute(k, attrs[k]);
      }
    }
    if (children) {
      (Array.isArray(children) ? children : [children]).forEach(function (c) {
        if (c == null) return;
        el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      });
    }
    return el;
  }

  function api(path, opts) {
    opts = opts || {};
    var url = REST_ROOT + path.replace(/^\/+/, '');
    var headers = { 'X-WP-Nonce': NONCE };
    if (opts.body) headers['Content-Type'] = 'application/json';
    return fetch(url, {
      method: opts.method || 'GET',
      headers: headers,
      credentials: 'same-origin',
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    }).then(function (r) {
      if (!r.ok) {
        return r.json().then(function (e) {
          var err = new Error(e && e.message ? e.message : 'HTTP ' + r.status);
          err.code = e && e.code;
          err.status = r.status;
          throw err;
        }, function () { throw new Error('HTTP ' + r.status); });
      }
      return r.json();
    });
  }

  function fmtCount(n) { return n == null ? '0' : String(n); }

  // ── Recent notebooks tracking ──────────────────────────────────────────
  var RECENT_KEY = 'bizcity_recent_notebooks';
  function recentGet() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
    catch (e) { return []; }
  }
  function recentAdd(id) {
    if (!id) return;
    var list = recentGet().filter(function (x) { return x !== id; });
    list.unshift(id);
    if (list.length > 10) list = list.slice(0, 10);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) {}
  }

  // ── Picker modal ───────────────────────────────────────────────────────
  function openPickerModal(opts) {
    opts = opts || {};
    return new Promise(function (resolve, reject) {
      var resolved = false;
      function done(val) { if (!resolved) { resolved = true; cleanup(); resolve(val); } }
      function bail(err) { if (!resolved) { resolved = true; cleanup(); reject(err); } }
      function cleanup() { document.removeEventListener('keydown', onEsc); if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay); }
      function onEsc(e) { if (e.key === 'Escape') done(null); }
      document.addEventListener('keydown', onEsc);

      var search = $('input', { type: 'search', placeholder: 'Tìm notebook...', class: 'btp-input', autofocus: 'autofocus' });
      var listEl = $('div', { class: 'btp-list' });
      var newTitleInput = $('input', { type: 'text', placeholder: 'Tên notebook mới...', class: 'btp-input', value: opts.title || '' });
      var createBtn = $('button', { type: 'button', class: 'btp-btn btp-btn-primary' }, '+ Tạo');
      var cancelBtn = $('button', { type: 'button', class: 'btp-btn' }, 'Hủy');
      var statusEl = $('div', { class: 'btp-status' }, 'Đang tải...');

      var modal = $('div', { class: 'btp-modal', role: 'dialog', 'aria-modal': 'true' }, [
        $('div', { class: 'btp-modal-head' }, [
          $('div', { class: 'btp-modal-title' }, 'Chọn Notebook'),
          $('button', { type: 'button', class: 'btp-modal-close', 'aria-label': 'Đóng', onClick: function () { done(null); } }, '✕'),
        ]),
        $('div', { class: 'btp-modal-body' }, [
          search,
          listEl,
          statusEl,
        ]),
        opts.allowCreate === false ? null : $('div', { class: 'btp-modal-foot' }, [
          $('div', { class: 'btp-create-row' }, [ newTitleInput, createBtn ]),
          $('div', { class: 'btp-foot-actions' }, [ cancelBtn ]),
        ]),
      ]);

      var overlay = $('div', { class: 'btp-overlay', onClick: function (e) { if (e.target === overlay) done(null); } }, modal);
      document.body.appendChild(overlay);
      setTimeout(function () { search.focus(); }, 30);

      var allRows = [];

      function render(filter) {
        listEl.innerHTML = '';
        var q = (filter || '').toLowerCase().trim();
        var shown = allRows.filter(function (r) { return !q || (r.name || '').toLowerCase().indexOf(q) !== -1; });
        if (!shown.length) {
          listEl.appendChild($('div', { class: 'btp-empty' }, q ? 'Không có notebook khớp.' : 'Chưa có notebook. Hãy tạo mới ↓'));
          return;
        }
        shown.forEach(function (r) {
          var stats = r.stats || {};
          var meta = fmtCount(stats.sources) + ' sources · ' + fmtCount(stats.passages) + ' passages';
          var isCurrent = opts.currentId && Number(opts.currentId) === Number(r.id);
          var item = $('button', {
            type: 'button',
            class: 'btp-item' + (isCurrent ? ' is-current' : ''),
            onClick: function () {
              recentAdd(r.id);
              done({ notebook_id: r.id, title: r.name, is_new: false });
            },
          }, [
            $('div', { class: 'btp-item-dot', style: { background: r.color || '#6366f1' } }),
            $('div', { class: 'btp-item-body' }, [
              $('div', { class: 'btp-item-title' }, r.name || ('#' + r.id)),
              $('div', { class: 'btp-item-meta' }, meta),
            ]),
            isCurrent ? $('div', { class: 'btp-item-tag' }, 'đang dùng') : null,
          ]);
          listEl.appendChild(item);
        });
      }

      search.addEventListener('input', function () { render(search.value); });
      cancelBtn.addEventListener('click', function () { done(null); });
      createBtn.addEventListener('click', function () {
        var title = (newTitleInput.value || '').trim();
        if (!title) { newTitleInput.focus(); return; }
        createBtn.disabled = true;
        createBtn.textContent = '...';
        var body = { title: title };
        if (opts.host) body.host = opts.host;
        api('notebooks', { method: 'POST', body: body })
          .then(function (res) {
            recentAdd(res.notebook_id);
            done({ notebook_id: res.notebook_id, title: res.title, is_new: true, bound_to: res.bound_to || null });
          })
          .catch(function (err) {
            createBtn.disabled = false;
            createBtn.textContent = '+ Tạo';
            statusEl.textContent = 'Lỗi: ' + (err.message || 'không tạo được');
            statusEl.className = 'btp-status btp-error';
          });
      });
      newTitleInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); createBtn.click(); }
      });

      // Load list.
      api('notebooks').then(function (res) {
        allRows = (res && res.notebooks) || [];
        statusEl.textContent = allRows.length + ' notebook' + (allRows.length === 1 ? '' : 's');
        statusEl.className = 'btp-status';
        render('');
      }).catch(function (err) {
        statusEl.textContent = 'Lỗi tải danh sách: ' + (err.message || err);
        statusEl.className = 'btp-status btp-error';
      });
    });
  }

  // ── Public API ─────────────────────────────────────────────────────────
  var BizcityTwin = {
    __loaded: true,
    __version: '0.13.0',

    isInShell: function () { return window !== window.top; },

    pickNotebook: function (opts) {
      return openPickerModal(opts || {});
    },

    /**
     * Lazy auto-create notebook (W2 helper). If currentNotebookId truthy → resolve immediately.
     * Else → create notebook with given title and bind to host entity.
     *
     * Hardening: in-flight + result cache keyed by host (plugin/entity_type/entity_id) so
     * concurrent or rapidly-repeated calls (e.g. user picks 5 files in a row before React
     * re-renders with the new boundNotebookId) reuse the SAME notebook instead of
     * spawning a duplicate per call.
     */
    ensureNotebook: function (opts) {
      opts = opts || {};
      if (opts.currentNotebookId) {
        return Promise.resolve({ notebook_id: Number(opts.currentNotebookId), title: opts.fallbackTitle || '', is_new: false });
      }
      var hostKey = '';
      if (opts.host && opts.host.plugin && opts.host.entity_type && opts.host.entity_id != null) {
        hostKey = String(opts.host.plugin) + '|' + String(opts.host.entity_type) + '|' + String(opts.host.entity_id);
      }
      if (hostKey) {
        BizcityTwin.__nbEnsureCache = BizcityTwin.__nbEnsureCache || Object.create(null);
        var cached = BizcityTwin.__nbEnsureCache[hostKey];
        if (cached) return cached;
      }
      var body = { title: (opts.fallbackTitle || '').trim() };
      if (opts.host) body.host = opts.host;
      var p = api('notebooks', { method: 'POST', body: body }).then(function (res) {
        recentAdd(res.notebook_id);
        var out = { notebook_id: res.notebook_id, title: res.title, is_new: true, bound_to: res.bound_to || null };
        // Re-cache as a resolved value so subsequent callers skip the network round-trip
        // for the lifetime of this page (until reload / explicit invalidation).
        if (hostKey) BizcityTwin.__nbEnsureCache[hostKey] = Promise.resolve(out);
        return out;
      }).catch(function (err) {
        // Don't poison the cache on failure — let next call retry.
        if (hostKey && BizcityTwin.__nbEnsureCache) delete BizcityTwin.__nbEnsureCache[hostKey];
        throw err;
      });
      if (hostKey) BizcityTwin.__nbEnsureCache[hostKey] = p;
      return p;
    },

    bindNotebook: function (args) {
      return api('host/bind-notebook', { method: 'POST', body: args });
    },

    notifyNotebookActive: function (id) {
      var n = Number(id);
      if (!n) return;
      recentAdd(n);
      // Bubble to parent shell so it can subscribe to learning streams.
      if (window !== window.top) {
        try {
          window.parent.postMessage({
            source: 'twin-plugin',
            type: 'notebook:active',
            notebook_id: n,
            pluginId: (window.BIZCITY_TWIN_SHELL_BRIDGE || {}).pluginId || ''
          }, '*');
        } catch (e) {}
      }
    },

    // Stubs for W2 / W4 — log so callers can wire trigger buttons early.
    openSourcePanel: function (opts) {
      console.log('[BizcityTwin] openSourcePanel (W2 stub)', opts);
      alert('Source Panel sẽ có ở Wave 2. Notebook: ' + (opts && opts.notebook_id));
    },
    openLearningMonitor: function (opts) {
      console.log('[BizcityTwin] openLearningMonitor (W4 stub)', opts);
      alert('Learning Monitor sẽ có ở Wave 4.');
    },

    // Internal helper for plugins that need REST quickly.
    _api: api,
    _recent: recentGet,
  };

  window.BizcityTwin = BizcityTwin;
  document.dispatchEvent(new CustomEvent('bizcity-twin:ready', { detail: { version: BizcityTwin.__version } }));
})();
