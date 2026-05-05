/**
 * Bizcity Twin Shell — front-end runtime.
 *
 * Vanilla JS, no build step. Reads window.BIZCITY_TWIN_SHELL config, renders an
 * ActivityBar + iframe, and keeps the parent URL in sync with whatever URL the
 * embedded plugin reports via postMessage.
 *
 * Phase 0.11 — Phương án A (iframe wrapper).
 */
(function () {
  'use strict';

  var cfg = window.BIZCITY_TWIN_SHELL;
  if (!cfg || !Array.isArray(cfg.plugins) || cfg.plugins.length === 0) {
    document.body.innerHTML =
      '<div style="padding:32px;font:14px/1.5 system-ui;color:#e6e6e6;background:#0f1115;height:100vh;">' +
      'Twin Shell: no plugins registered. Add a `bizcity_twin_register_plugins` filter callback.' +
      '</div>';
    return;
  }

  var root = document.getElementById('twin-shell');
  if (!root) return;

  var SHELL_ORIGIN = window.location.origin;

  // ── State ──────────────────────────────────────────────────────────────
  var current = {
    pluginId: '',
    iframeEl: null,
  };
  var iframeCache = Object.create(null); // pluginId → iframe element (LRU keep 2)
  var lruOrder = [];                     // most-recent first

  // ── SVG icon map (Lucide-compatible, 24×24 viewBox) ────────────────────
  var ICON_PATHS = {
    home:      '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    workspace: '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="12" y1="10" x2="12" y2="12"/><line x1="16" y1="10" x2="16" y2="16"/>',
    creator:   '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    doc:       '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    image:     '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    video:     '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    web:       '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    notebook:  '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    tools:     '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    skills:    '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    scheduler: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    automation:'<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    gateway:   '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
    explore:   '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    default:   '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/>',
  };

  function renderIcon(name) {
    var p = ICON_PATHS[name] || ICON_PATHS['default'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"' +
           ' fill="none" stroke="currentColor" stroke-width="1.75"' +
           ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  }

  // ── Helpers ────────────────────────────────────────────────────────────
  function findPlugin(id) {
    for (var i = 0; i < cfg.plugins.length; i++) {
      if (cfg.plugins[i].id === id) return cfg.plugins[i];
    }
    return null;
  }

  function buildIframeUrl(pluginId, paramsObj) {
    var p = findPlugin(pluginId);
    if (!p || !p.public_slug) return '';
    var base = window.location.origin + '/' + p.public_slug.replace(/^\/+|\/+$/g, '') + '/';
    var qs = [];
    var allowed = p.params && p.params.length ? p.params : null;
    if (paramsObj) {
      for (var k in paramsObj) {
        if (!Object.prototype.hasOwnProperty.call(paramsObj, k)) continue;
        if (k === 'plugin' || k === '_view' || k === '_t' || k === 'bizcity_iframe') continue;
        if (allowed && allowed.indexOf(k) === -1) continue;
        qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(paramsObj[k]));
      }
    }
    qs.push('bizcity_iframe=1');
    return base + (qs.length ? '?' + qs.join('&') : '');
  }

  function parseShellUrl() {
    var sp = new URLSearchParams(window.location.search);
    var pluginId = sp.get('plugin') || cfg.defaultPlugin || cfg.plugins[0].id;
    var iurl = sp.get('_iurl') || '';
    var params = {};
    sp.forEach(function (v, k) {
      if (k !== 'plugin' && k !== '_view' && k !== '_t' && k !== '_iurl') params[k] = v;
    });
    return { pluginId: pluginId, params: params, iurl: iurl };
  }

  function writeShellUrl(pluginId, paramsObj, iframeUrl) {
    var sp = new URLSearchParams();
    sp.set('plugin', pluginId);
    if (paramsObj) {
      for (var k in paramsObj) {
        if (!Object.prototype.hasOwnProperty.call(paramsObj, k)) continue;
        sp.set(k, paramsObj[k]);
      }
    }
    // Persist the exact child URL so F5 restores the deep link.
    if (iframeUrl) {
      try {
        var iu = new URL(iframeUrl, window.location.origin);
        if (iu.origin === window.location.origin) {
          sp.set('_iurl', iu.pathname + iu.search);
        }
      } catch (e) {}
    }
    var newUrl = window.location.pathname + '?' + sp.toString();
    console.log('[twin-shell][writeShellUrl]', { pluginId: pluginId, params: paramsObj, iframeUrl: iframeUrl, newUrl: newUrl });
    window.history.replaceState({ pluginId: pluginId }, '', newUrl);
  }

  function paramsFromIframeUrl(rawUrl) {
    try {
      var u = new URL(rawUrl, window.location.origin);
      var params = {};
      u.searchParams.forEach(function (v, k) {
        if (k === 'bizcity_iframe') return;
        params[k] = v;
      });
      return params;
    } catch (e) {
      return {};
    }
  }

  // ── Rendering ──────────────────────────────────────────────────────────
  function buildLayout() {
    root.innerHTML =
      '<aside class="ts-activitybar" role="tablist" aria-label="Twin Shell navigation">' +
        '<div class="ts-ab-section ts-ab-top"></div>' +
        '<div class="ts-ab-section ts-ab-bottom"></div>' +
      '</aside>' +
      '<main class="ts-main">' +
        '<div class="ts-frame-stack"></div>' +
        '<div class="ts-loading" hidden>' +
          '<div class="ts-spinner"></div>' +
        '</div>' +
      '</main>';
  }

  function buildItem(p) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ts-ab-item';
    btn.dataset.pluginId = p.id;
    btn.title = p.label || p.id;
    btn.setAttribute('role', 'tab');
    btn.setAttribute('aria-label', p.label || p.id);
    btn.innerHTML = renderIcon(p.icon || '');
    btn.addEventListener('click', function () {
      navigate(p.id, {});
    });
    return btn;
  }

  function renderActivityBar() {
    var top = root.querySelector('.ts-ab-top');
    var bottom = root.querySelector('.ts-ab-bottom');
    cfg.plugins.forEach(function (p) {
      var item = buildItem(p);
      if (p.section === 'bottom') bottom.appendChild(item);
      else top.appendChild(item);
    });
  }

  function setActiveButton(pluginId) {
    var btns = root.querySelectorAll('.ts-ab-item');
    for (var i = 0; i < btns.length; i++) {
      var on = btns[i].dataset.pluginId === pluginId;
      btns[i].classList.toggle('is-active', on);
      btns[i].setAttribute('aria-selected', on ? 'true' : 'false');
    }
  }

  function showLoading(on) {
    var el = root.querySelector('.ts-loading');
    if (el) el.hidden = !on;
  }

  function ensureIframe(pluginId, paramsObj, iurl) {
    var stack = root.querySelector('.ts-frame-stack');
    // iurl: stored deep-link path (e.g. '/twinchat/?bizcity_iframe=1&notebook_id=1').
    var url = (iurl && iurl.charAt(0) === '/') ? (window.location.origin + iurl) : buildIframeUrl(pluginId, paramsObj);
    if (!url) return null;

    // Reuse cached iframe if present.
    if (iframeCache[pluginId]) {
      var existing = iframeCache[pluginId];
      // If params changed, navigate the iframe.
      try {
        var cur = new URL(existing.src, window.location.origin);
        var next = new URL(url, window.location.origin);
        if (cur.pathname !== next.pathname || cur.search !== next.search) {
          existing.src = url;
        }
      } catch (e) {
        existing.src = url;
      }
      lruBump(pluginId);
      return existing;
    }

    var iframe = document.createElement('iframe');
    iframe.className = 'ts-frame';
    iframe.dataset.pluginId = pluginId;
    iframe.title = (findPlugin(pluginId) || {}).label || pluginId;
    iframe.src = url;
    iframe.setAttribute('allow', 'clipboard-read; clipboard-write; fullscreen; microphone; camera');
    showLoading(true);
    iframe.addEventListener('load', function () {
      showLoading(false);
      // Same-origin iframe → read URL directly (bypass bridge/postMessage race conditions).
      // Fires after EVERY full-page reload inside the iframe (window.location.href = ...).
      try {
        var loc = iframe.contentWindow && iframe.contentWindow.location;
        if (loc && loc.origin === window.location.origin) {
          var fullUrl  = loc.pathname + loc.search + loc.hash;
          var rparams  = paramsFromIframeUrl(loc.href);
          var thisPid  = iframe.dataset.pluginId;
          console.log('[twin-shell][load]', { pluginId: thisPid, iframeHref: loc.href, params: rparams });
          if (thisPid) writeShellUrl(thisPid, rparams, loc.href);
          // Also poll for in-page hash/history changes (covers SPAs that don't
          // ship the bridge or where Cloudflare defers it).
          if (!iframe.__urlPoll) {
            var lastHref = loc.href;
            iframe.__urlPoll = setInterval(function () {
              try {
                var l = iframe.contentWindow && iframe.contentWindow.location;
                if (!l || l.origin !== window.location.origin) return;
                if (l.href !== lastHref) {
                  console.log('[twin-shell][poll] iframe url changed', { from: lastHref, to: l.href });
                  lastHref = l.href;
                  var pp = paramsFromIframeUrl(l.href);
                  writeShellUrl(iframe.dataset.pluginId, pp, l.href);
                }
              } catch (e) { /* cross-origin or detached */ }
            }, 500);
          }
        } else {
          console.log('[twin-shell][load] cross-origin or no loc, skipping');
        }
      } catch (e) { console.warn('[twin-shell][load] error', e); }
    });
    stack.appendChild(iframe);

    iframeCache[pluginId] = iframe;
    lruBump(pluginId);
    return iframe;
  }

  function lruBump(pluginId) {
    var idx = lruOrder.indexOf(pluginId);
    if (idx !== -1) lruOrder.splice(idx, 1);
    lruOrder.unshift(pluginId);

    // Keep at most 3 cached iframes.
    while (lruOrder.length > 3) {
      var dropId = lruOrder.pop();
      var fr = iframeCache[dropId];
      if (fr && fr.__urlPoll) { clearInterval(fr.__urlPoll); fr.__urlPoll = null; }
      if (fr && fr.parentNode) fr.parentNode.removeChild(fr);
      delete iframeCache[dropId];
    }
  }

  function focusFrame(pluginId) {
    var stack = root.querySelector('.ts-frame-stack');
    var frames = stack.querySelectorAll('iframe');
    for (var i = 0; i < frames.length; i++) {
      var on = frames[i].dataset.pluginId === pluginId;
      frames[i].classList.toggle('is-active', on);
    }
  }

  // ── Navigation ─────────────────────────────────────────────────────────
  function navigate(pluginId, paramsObj, opts) {
    opts = opts || {};
    var p = findPlugin(pluginId);
    if (!p) return;

    var iframe = ensureIframe(pluginId, paramsObj, opts.iurl || '');
    if (!iframe) return;

    current.pluginId = pluginId;
    current.iframeEl = iframe;

    setActiveButton(pluginId);
    focusFrame(pluginId);

    if (!opts.skipUrlWrite) {
      writeShellUrl(pluginId, paramsObj);
    }
  }

  // ── postMessage bridge ─────────────────────────────────────────────────
  window.addEventListener('message', function (ev) {
    if (ev.origin !== SHELL_ORIGIN) return;
    var data = ev.data;
    if (!data || data.source !== 'twin-plugin') return;

    if (data.type === 'nav' && typeof data.url === 'string') {
      // Child tells us its URL changed — update parent shell URL + persist deep link.
      if (!current.pluginId) return;
      var params = paramsFromIframeUrl(data.url);
      writeShellUrl(current.pluginId, params, data.url);
      if (data.title && typeof data.title === 'string') {
        document.title = data.title + ' — Twin';
      }
    } else if (data.type === 'open-external' && typeof data.url === 'string') {
      // Child asks parent to open something outside the shell.
      window.open(data.url, '_blank', 'noopener');
    } else if (data.type === 'ready') {
      showLoading(false);
      // Sync _iurl whenever the iframe finishes loading (covers full-page
      // navigations via window.location.href, e.g. BrainHome → notebook).
      if (current.pluginId && data.url && typeof data.url === 'string') {
        var rparams = paramsFromIframeUrl(data.url);
        writeShellUrl(current.pluginId, rparams, data.url);
      }
    } else if (data.type === 'navigate-shell' && typeof data.pluginId === 'string') {
      navigate(data.pluginId, data.params || {});
    } else if (data.type === 'upload:chip') {
      // Phase 0.13 W3 — child now renders its own floating chip; parent suppresses ActivityBar dupe.
      // upsertUploadChip({...}) intentionally disabled.
    } else if (data.type === 'upload:clear' && data.notebook_id) {
      removeUploadChip(Number(data.notebook_id));
    }
  });

  // ── Upload chips (Phase 0.13 W3) ──────────────────────────────────────
  // Renders a persistent chip per active upload notebook in `.ts-ab-bottom`.
  // Click → postMessage child iframe to re-open the upload modal.
  var uploadChips = Object.create(null); // notebook_id → { el, fromPlugin, isLive }

  function upsertUploadChip(info) {
    if (!info.notebook_id) return;
    var bottom = root.querySelector('.ts-ab-bottom');
    if (!bottom) return;
    var entry = uploadChips[info.notebook_id];
    if (!entry) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ts-ab-item ts-upload-chip';
      btn.title = 'Reopen source uploader (NB#' + info.notebook_id + ')';
      btn.innerHTML = '<span class="ts-ab-icon">🧠</span><span class="ts-ab-label"></span>';
      btn.addEventListener('click', function () {
        var e = uploadChips[info.notebook_id];
        if (!e) return;
        // Debounce: prevent rapid duplicate clicks (each one would post a
        // restore, stacking multiple modal overlays in the iframe).
        if (e.pending) return;
        e.pending = true;
        btn.classList.add('is-pending');
        setTimeout(function () {
          if (uploadChips[info.notebook_id]) {
            uploadChips[info.notebook_id].pending = false;
          }
          btn.classList.remove('is-pending');
        }, 1200);
        // Dismiss the hint bubble on first user click.
        dismissChipHint(info.notebook_id, /*persist*/ true);
        // Switch to the originating plugin first if needed.
        if (e.fromPlugin && e.fromPlugin !== current.pluginId) {
          navigate(e.fromPlugin, {});
          // Wait briefly for iframe load before posting.
          setTimeout(function () { postRestore(info.notebook_id); }, 600);
        } else {
          postRestore(info.notebook_id);
        }
      });
      bottom.appendChild(btn);
      entry = uploadChips[info.notebook_id] = { el: btn, fromPlugin: info.from_plugin, isLive: info.is_live, pending: false };
    } else {
      entry.fromPlugin = info.from_plugin || entry.fromPlugin;
      entry.isLive = info.is_live;
    }
    var label = entry.el.querySelector('.ts-ab-label');
    if (label) label.textContent = info.is_live ? ('Learning · NB#' + info.notebook_id) : ('Done · NB#' + info.notebook_id);
    entry.el.classList.toggle('is-live', info.is_live);
    // Show a one-time gentle hint while learning is live.
    if (info.is_live) showChipHint(info.notebook_id, entry.el);
    else dismissChipHint(info.notebook_id, false);
  }

  function removeUploadChip(nb) {
    var e = uploadChips[nb];
    if (!e) return;
    dismissChipHint(nb, false);
    if (e.el && e.el.parentNode) e.el.parentNode.removeChild(e.el);
    delete uploadChips[nb];
  }

  // ── Chip hint bubble ───────────────────────────────────────────────────
  // Persistent dismissal flag (survives reload). Cleared per-notebook so a
  // brand-new learning session can re-surface the hint.
  var HINT_KEY = 'bizcity_twin_chip_hint_dismissed_v1';
  var chipHints = Object.create(null); // notebook_id → bubbleEl

  function isHintDismissed() {
    try { return window.localStorage.getItem(HINT_KEY) === '1'; } catch (e) { return false; }
  }
  function persistDismiss() {
    try { window.localStorage.setItem(HINT_KEY, '1'); } catch (e) {}
  }
  function showChipHint(nb, anchorEl) {
    if (chipHints[nb] || isHintDismissed()) return;
    var bubble = document.createElement('div');
    bubble.className = 'ts-chip-hint';
    bubble.innerHTML =
      '<div class="ts-chip-hint-body">'
      + '<span class="ts-chip-hint-icon">💡</span>'
      + '<span class="ts-chip-hint-text">Twin is learning. Answers will be more accurate once the deep-learning step finishes.</span>'
      + '</div>'
      + '<button type="button" class="ts-chip-hint-close" aria-label="Đóng">✕</button>';
    bubble.querySelector('.ts-chip-hint-close').addEventListener('click', function (ev) {
      ev.stopPropagation();
      dismissChipHint(nb, /*persist*/ true);
    });
    document.body.appendChild(bubble);
    positionHint(bubble, anchorEl);
    chipHints[nb] = bubble;
    // Reposition on resize while visible.
    bubble._reposition = function () { positionHint(bubble, anchorEl); };
    window.addEventListener('resize', bubble._reposition);
  }
  function positionHint(bubble, anchor) {
    var r = anchor.getBoundingClientRect();
    // Anchor right next to the chip (ActivityBar is on the left).
    bubble.style.left = (r.right + 10) + 'px';
    bubble.style.top  = (r.top - 4) + 'px';
  }
  function dismissChipHint(nb, persistFlag) {
    var b = chipHints[nb];
    if (b) {
      if (b._reposition) window.removeEventListener('resize', b._reposition);
      if (b.parentNode) b.parentNode.removeChild(b);
      delete chipHints[nb];
    }
    if (persistFlag) {
      persistDismiss();
      // Also clear any other open hints — user dismissed once = dismiss all.
      Object.keys(chipHints).forEach(function (k) { dismissChipHint(Number(k), false); });
    }
  }

  function postRestore(nb) {
    var iframe = current.iframeEl;
    if (!iframe || !iframe.contentWindow) return;
    try {
      iframe.contentWindow.postMessage({
        source: 'twin-shell', type: 'upload:restore', notebook_id: Number(nb),
      }, SHELL_ORIGIN);
    } catch (e) {}
  }

  // Browser back/forward.
  window.addEventListener('popstate', function () {
    var s = parseShellUrl();
    navigate(s.pluginId, s.params, { skipUrlWrite: true });
  });

  // ── Boot ───────────────────────────────────────────────────────────────
  buildLayout();
  renderActivityBar();
  var initial = parseShellUrl();
  navigate(initial.pluginId, initial.params, { skipUrlWrite: true, iurl: initial.iurl });
  // Make sure URL has ?plugin= for predictable refresh — preserve _iurl if present.
  var initIurlFull = initial.iurl ? (window.location.origin + initial.iurl) : '';
  writeShellUrl(initial.pluginId, initial.params, initIurlFull);
})();
