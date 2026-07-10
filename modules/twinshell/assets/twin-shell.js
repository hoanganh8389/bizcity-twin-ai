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
  var activityState = {
    open: false,
    loading: false,
    loaded: false,
    events: [],
    nextBeforeId: 0,
    error: '',
    filters: {
      action: '',
      outcome: '',
      plugin_id: '',
    },
  };

  // ── SVG icon map (Lucide-compatible, 24×24 viewBox) ────────────────────
  var ICON_PATHS = {
    home:      '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    // [2026-07-04 Johnny Chu] PHASE-FAA2-FE — astro (crescent moon) icon for bizcoach-pro /astro/
    astro:     '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    workspace: '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="12" y1="10" x2="12" y2="12"/><line x1="16" y1="10" x2="16" y2="16"/>',
    creator:   '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    doc:       '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    image:     '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    profile:   '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',  // single user silhouette
    video:     '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    web:       '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    notebook:  '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    tools:     '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    skills:    '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    scheduler: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    automation:'<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    gateway:   '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
    funnel:    '<path d="M22 3H2l8 9.46V19l4 2V12.46L22 3z"/>',
    users:     '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    explore:   '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    // External-link icon (used when inside admin iframe → click to pop out to /twin/).
    'external':'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    // WP/admin (layout) icon (used when at standalone /twin/ → click to enter wp-admin).
    'admin':   '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
    // Panel-left-close (used to collapse the wp-admin sidebar inside the active iframe).
    'panel-left-close': '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><polyline points="16 15 13 12 16 9"/>',
    'panel-left-open':  '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><polyline points="14 9 17 12 14 15"/>',
    default:   '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/>',
  };

  function renderIcon(name) {
    var p = ICON_PATHS[name] || ICON_PATHS['default'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"' +
           ' fill="none" stroke="currentColor" stroke-width="1.75"' +
           ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  }

  function getActivityApiBase() {
    var base = String(cfg.restRoot || '');
    if (base.indexOf('/bizcity-twinchat/v1/') !== -1) {
      return base.replace('/bizcity-twinchat/v1/', '/bizcity-twin/v1/');
    }
    if (base.indexOf('/wp-json/') !== -1) {
      var m = base.match(/^(https?:\/\/[^/]+)(\/wp-json\/)/i);
      if (m && m[1]) {
        return m[1] + '/wp-json/bizcity-twin/v1/';
      }
    }
    return window.location.origin + '/wp-json/bizcity-twin/v1/';
  }

  function formatActivityTime(ms) {
    var ts = Number(ms || 0);
    if (!ts) return '--';
    try {
      var d = new Date(ts);
      return d.toLocaleString();
    } catch (e) {
      return '--';
    }
  }

  function normalizeActivityFilterValue(v) {
    var raw = String(v || '').trim().toLowerCase();
    return raw.replace(/[^a-z0-9._-]/g, '');
  }

  function populateActivityPluginFilter() {
    var panel = root.querySelector('.ts-activity-panel');
    if (!panel) return;
    var pluginSel = panel.querySelector('.ts-activity-filter-plugin');
    if (!pluginSel) return;

    pluginSel.innerHTML = '';

    var allOpt = document.createElement('option');
    allOpt.value = '';
    allOpt.textContent = 'Tất cả plugin';
    pluginSel.appendChild(allOpt);

    cfg.plugins.forEach(function (p) {
      var pid = String((p && p.id) || '').trim();
      if (!pid) return;
      var opt = document.createElement('option');
      opt.value = pid;
      opt.textContent = String((p && p.label) || pid);
      pluginSel.appendChild(opt);
    });
  }

  function getActivityPanelFilters() {
    var panel = root.querySelector('.ts-activity-panel');
    if (!panel) {
      return {
        action: '',
        outcome: '',
        plugin_id: '',
      };
    }

    var actionInput = panel.querySelector('.ts-activity-filter-action');
    var outcomeSel = panel.querySelector('.ts-activity-filter-outcome');
    var pluginSel = panel.querySelector('.ts-activity-filter-plugin');

    return {
      action: normalizeActivityFilterValue(actionInput ? actionInput.value : ''),
      outcome: normalizeActivityFilterValue(outcomeSel ? outcomeSel.value : ''),
      plugin_id: normalizeActivityFilterValue(pluginSel ? pluginSel.value : ''),
    };
  }

  function setActivityPanelFilters(filters) {
    var panel = root.querySelector('.ts-activity-panel');
    if (!panel) return;

    var f = filters || {};
    var actionInput = panel.querySelector('.ts-activity-filter-action');
    var outcomeSel = panel.querySelector('.ts-activity-filter-outcome');
    var pluginSel = panel.querySelector('.ts-activity-filter-plugin');

    if (actionInput) actionInput.value = String(f.action || '');
    if (outcomeSel) outcomeSel.value = String(f.outcome || '');
    if (pluginSel) pluginSel.value = String(f.plugin_id || '');
  }

  function applyActivityFilters() {
    activityState.filters = getActivityPanelFilters();
    activityState.loaded = false;
    if (activityState.open) {
      fetchActivityTimeline(true);
    }
  }

  function resetActivityFilters() {
    activityState.filters = {
      action: '',
      outcome: '',
      plugin_id: '',
    };
    setActivityPanelFilters(activityState.filters);
    activityState.loaded = false;
    if (activityState.open) {
      fetchActivityTimeline(true);
    }
  }

  function renderActivityPanel() {
    var panel = root.querySelector('.ts-activity-panel');
    if (!panel) return;
    var list = panel.querySelector('.ts-activity-list');
    var state = panel.querySelector('.ts-activity-state');
    var loadMoreBtn = panel.querySelector('.ts-activity-load-more');
    if (!list || !state || !loadMoreBtn) return;

    list.innerHTML = '';
    state.textContent = '';

    if (activityState.loading) {
      state.textContent = 'Đang tải activity...';
      loadMoreBtn.disabled = true;
      loadMoreBtn.hidden = true;
      return;
    }
    if (activityState.error) {
      state.textContent = activityState.error;
      loadMoreBtn.disabled = true;
      loadMoreBtn.hidden = true;
      return;
    }
    if (!activityState.events.length) {
      state.textContent = 'Chưa có activity TwinShell.';
      loadMoreBtn.disabled = true;
      loadMoreBtn.hidden = true;
      return;
    }

    activityState.events.forEach(function (ev) {
      var payload = ev && ev.payload ? ev.payload : {};
      var action = payload.action || payload.milestone_type || 'milestone';
      var outcome = payload.outcome || 'success';
      var pluginId = payload.plugin_id || '';

      var item = document.createElement('div');
      item.className = 'ts-activity-item';

      var head = document.createElement('div');
      head.className = 'ts-activity-item-head';

      var actionEl = document.createElement('span');
      actionEl.className = 'ts-activity-action';
      actionEl.textContent = String(action);

      var outcomeEl = document.createElement('span');
      outcomeEl.className = 'ts-activity-outcome ts-activity-outcome--' + String(outcome);
      outcomeEl.textContent = String(outcome);

      head.appendChild(actionEl);
      head.appendChild(outcomeEl);

      var meta = document.createElement('div');
      meta.className = 'ts-activity-meta';
      var timeText = formatActivityTime(ev.created_epoch_ms);
      meta.textContent = pluginId ? (timeText + ' · ' + pluginId) : timeText;

      item.appendChild(head);
      item.appendChild(meta);
      list.appendChild(item);
    });

    loadMoreBtn.hidden = !(activityState.nextBeforeId > 0);
    loadMoreBtn.disabled = false;
  }

  function setActivityPanelOpen(on) {
    var panel = root.querySelector('.ts-activity-panel');
    var btn = root.querySelector('.ts-ab-activity-log');
    if (!panel) return;

    activityState.open = !!on;
    panel.hidden = !activityState.open;
    panel.classList.toggle('is-open', activityState.open);
    setActivityPanelFilters(activityState.filters);
    if (btn) {
      btn.classList.toggle('is-active', activityState.open);
      btn.setAttribute('aria-pressed', activityState.open ? 'true' : 'false');
    }

    if (activityState.open && !activityState.loaded) {
      fetchActivityTimeline(true);
    }
  }

  function fetchActivityTimeline(reset) {
    if (activityState.loading) return;
    activityState.loading = true;
    if (reset) {
      activityState.events = [];
      activityState.nextBeforeId = 0;
      activityState.error = '';
    }
    renderActivityPanel();

    var base = getActivityApiBase();
    var q = new URLSearchParams();
    q.set('surface', 'twinshell');
    q.set('event_type', 'milestone');
    q.set('limit', '30');

    var filters = activityState.filters || {};
    if (filters.action) {
      q.set('action', filters.action);
    }
    if (filters.outcome) {
      q.set('outcome', filters.outcome);
    }
    if (filters.plugin_id) {
      q.set('plugin_id', filters.plugin_id);
    }

    if (!reset && activityState.nextBeforeId > 0) {
      q.set('before_id', String(activityState.nextBeforeId));
    }

    fetch(base + 'events/my_activity?' + q.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': String(cfg.nonce || ''),
      },
    }).then(function (res) {
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      return res.json();
    }).then(function (json) {
      var rows = Array.isArray(json && json.events) ? json.events : [];
      if (reset) {
        activityState.events = rows;
      } else {
        activityState.events = activityState.events.concat(rows);
      }
      activityState.nextBeforeId = Number(json && json.next_before_id ? json.next_before_id : 0);
      activityState.loaded = true;
      activityState.error = '';
    }).catch(function (err) {
      activityState.error = 'Không tải được activity. ' + (err && err.message ? err.message : 'Unknown error');
    }).finally(function () {
      activityState.loading = false;
      renderActivityPanel();
    });
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
    if (!p) return '';

    // mode='link' — dùng target_url, chỉ thêm bizcity_iframe=1.
    if (p.mode === 'link' && p.target_url) {
      var sep = p.target_url.indexOf('?') === -1 ? '?' : '&';
      return p.target_url + sep + 'bizcity_iframe=1';
    }

    // mode='embed' (default) — build từ public_slug.
    if (!p.public_slug) return '';
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

  // [2026-06-29 Johnny Chu] HOTFIX — debounce writeShellUrl to prevent rapid-fire
  // concurrent React re-renders that cause insertBefore NotFoundError.
  // onClick fires writeShellUrl immediately + 3 more times via setTimeout(onNav).
  // Each call does history.replaceState which React 18 HashRouter treats as a
  // navigation → concurrent re-renders race → stale nextSibling reference.
  // Debounce to 1 unique call per 80 ms; last call wins (captures final URL).
  var _writeShellUrlTimer = null;
  var _writeShellUrlPending = null;

  function _doWriteShellUrl(pluginId, paramsObj, iframeUrl) {
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
          // Always bake bizcity_iframe=1 into the stored path so that sub-page
          // navigations (e.g. /creator/result/31/ with no query string) still
          // load in iframe mode when restored via F5 or copy-paste.
          var iusp = new URLSearchParams(iu.search);
          iusp.set('bizcity_iframe', '1');
          // Include hash fragment (#tab=inbox, etc.) so SPA deep-links survive reload.
          sp.set('_iurl', iu.pathname + '?' + iusp.toString() + (iu.hash || ''));
        }
      } catch (e) {}
    }
    var newUrl = window.location.pathname + '?' + sp.toString();
    console.log('[twin-shell][writeShellUrl]', { pluginId: pluginId, params: paramsObj, iframeUrl: iframeUrl, newUrl: newUrl });
    window.history.replaceState({ pluginId: pluginId }, '', newUrl);

    // ── Broadcast to ancestor (e.g. WP admin page hosting /twin/ in an iframe)
    // so the OUTER address bar (admin.php?page=bizcity-twinchat&...) reflects
    // the current deep-link. Receiver in class-twinchat-admin-menu.php updates
    // history via replaceState.
    try {
      if (window.parent && window.parent !== window) {
        var payload = {
          source:   'bizcity-twin-shell',
          type:     'url-change',
          pluginId: pluginId,
          params:   paramsObj || {},
          iurl:     sp.get('_iurl') || '',
          shellUrl: newUrl
        };
        console.log('[twin-shell][postMessage->parent]', payload);
        window.parent.postMessage(payload, window.location.origin);
      }
    } catch (e) { console.warn('[twin-shell][postMessage->parent] err', e); }
  }

  // Debounced public wrapper — coalesces rapid calls into 1 per 80 ms.
  function writeShellUrl(pluginId, paramsObj, iframeUrl) {
    _writeShellUrlPending = { pluginId: pluginId, paramsObj: paramsObj, iframeUrl: iframeUrl };
    if (_writeShellUrlTimer) { clearTimeout(_writeShellUrlTimer); }
    _writeShellUrlTimer = setTimeout(function () {
      _writeShellUrlTimer = null;
      var p = _writeShellUrlPending;
      _writeShellUrlPending = null;
      if (p) { _doWriteShellUrl(p.pluginId, p.paramsObj, p.iframeUrl); }
    }, 80);
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

  function pluginIdFromWindow(win) {
    if (!win) return '';
    for (var pid in iframeCache) {
      if (!Object.prototype.hasOwnProperty.call(iframeCache, pid)) continue;
      var fr = iframeCache[pid];
      try {
        if (fr && fr.contentWindow === win) return pid;
      } catch (e) {}
    }
    return '';
  }

  function syncAddressBarFromIframe(iframe, force) {
    if (!iframe || !iframe.contentWindow) return;
    var pid = iframe.dataset.pluginId || '';
    if (!pid) return;

    // Keep parent URL consistent with the currently active iframe only.
    if (!force && current.pluginId && pid !== current.pluginId) return;

    try {
      var loc = iframe.contentWindow.location;
      if (!loc || loc.origin !== window.location.origin) return;
      var href = loc.href;
      if (!href) return;
      if (!force && iframe.__lastSyncedHref === href) return;
      iframe.__lastSyncedHref = href;
      writeShellUrl(pid, paramsFromIframeUrl(href), href);
    } catch (e) {}
  }

  function installIframeDeepLinkSync(iframe) {
    if (!iframe || !iframe.contentWindow) return;

    // New navigation can recreate the inner document/window state.
    if (iframe.__deepSyncCleanup) {
      try { iframe.__deepSyncCleanup(); } catch (e) {}
      iframe.__deepSyncCleanup = null;
    }

    var w;
    try {
      w = iframe.contentWindow;
      if (!w || !w.location || w.location.origin !== window.location.origin) return;
    } catch (e) {
      return;
    }

    var onNav = function () {
      syncAddressBarFromIframe(iframe, false);
    };

    var resolveClickedHref = function (ev) {
      var target = ev && ev.target ? ev.target : null;
      if (!target || !target.closest) return '';

      var el = target.closest('a[href], [data-href], [data-url], [data-route], [data-path], [data-to]');
      if (!el || !el.getAttribute) return '';

      var raw =
        el.getAttribute('href') ||
        el.getAttribute('data-href') ||
        el.getAttribute('data-url') ||
        el.getAttribute('data-route') ||
        el.getAttribute('data-path') ||
        el.getAttribute('data-to') ||
        '';

      raw = String(raw || '').trim();
      if (!raw || raw === '#') return '';
      if (/^(javascript:|mailto:|tel:|data:)/i.test(raw)) return '';

      try {
        var next =
          raw.charAt(0) === '#'
            ? new URL((w.location.pathname || '/') + (w.location.search || '') + raw, w.location.origin)
            : new URL(raw, w.location.href);
        if (next.origin !== window.location.origin) return '';
        return next.href;
      } catch (e) {
        return '';
      }
    };

    var onClick = function (ev) {
      // Some apps keep routing in internal state and do not update location
      // immediately. Sync parent URL from clicked route hints first.
      var clickedHref = resolveClickedHref(ev);
      if (clickedHref) {
        try {
          var pid = pluginIdFromWindow(w) || current.pluginId;
          if (pid) {
            iframe.__lastSyncedHref = clickedHref;
            writeShellUrl(pid, paramsFromIframeUrl(clickedHref), clickedHref);
          }
        } catch (e) {}
      }

      // UI actions in admin/SPA can resolve async after click.
      setTimeout(onNav, 0);
      setTimeout(onNav, 150);
      setTimeout(onNav, 500);
    };

    try {
      w.addEventListener('hashchange', onNav);
      w.addEventListener('popstate', onNav);
    } catch (e) {}

    var doc = null;
    try {
      doc = w.document || null;
      if (doc) {
        doc.addEventListener('click', onClick, true);
      }
    } catch (e) {}

    // Catch routes pushed by SPA code without hashchange/popstate listeners.
    try {
      if (w.history && !w.history.__twinShellDeepSyncPatched) {
        var origPush = w.history.pushState;
        var origReplace = w.history.replaceState;
        if (typeof origPush === 'function') {
          w.history.pushState = function () {
            var out = origPush.apply(this, arguments);
            setTimeout(onNav, 0);
            return out;
          };
        }
        if (typeof origReplace === 'function') {
          w.history.replaceState = function () {
            var out = origReplace.apply(this, arguments);
            setTimeout(onNav, 0);
            return out;
          };
        }
        w.history.__twinShellDeepSyncPatched = true;
      }
    } catch (e) {}

    iframe.__deepSyncCleanup = function () {
      try { w.removeEventListener('hashchange', onNav); } catch (e) {}
      try { w.removeEventListener('popstate', onNav); } catch (e) {}
      try { if (doc) doc.removeEventListener('click', onClick, true); } catch (e) {}
    };

    syncAddressBarFromIframe(iframe, true);
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
      '</main>' +
      '<aside class="ts-activity-panel" hidden>' +
        '<div class="ts-activity-panel-head">' +
          '<h3 class="ts-activity-title">Activity</h3>' +
          '<div class="ts-activity-head-actions">' +
            '<button type="button" class="ts-activity-hide" aria-label="Ẩn Activity">Ẩn</button>' +
            '<button type="button" class="ts-activity-close" aria-label="Đóng">✕</button>' +
          '</div>' +
        '</div>' +
        '<div class="ts-activity-filters">' +
          '<label class="ts-activity-filter-label">Plugin' +
            '<select class="ts-activity-filter-plugin"></select>' +
          '</label>' +
          '<label class="ts-activity-filter-label">Outcome' +
            '<select class="ts-activity-filter-outcome">' +
              '<option value="">Tất cả outcome</option>' +
              '<option value="success">success</option>' +
              '<option value="blocked">blocked</option>' +
              '<option value="failed">failed</option>' +
              '<option value="degraded">degraded</option>' +
            '</select>' +
          '</label>' +
          '<label class="ts-activity-filter-label ts-activity-filter-label--full">Action' +
            '<input type="text" class="ts-activity-filter-action" placeholder="shell.nav.open_plugin" />' +
          '</label>' +
          '<div class="ts-activity-filter-actions">' +
            '<button type="button" class="ts-activity-filter-apply">Lọc</button>' +
            '<button type="button" class="ts-activity-filter-reset">Reset</button>' +
          '</div>' +
        '</div>' +
        '<div class="ts-activity-state"></div>' +
        '<div class="ts-activity-list"></div>' +
        '<div class="ts-activity-foot">' +
          '<button type="button" class="ts-activity-load-more" hidden>Tải thêm</button>' +
        '</div>' +
      '</aside>';

    var closeBtn = root.querySelector('.ts-activity-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setActivityPanelOpen(false);
      });
    }

    var hideBtn = root.querySelector('.ts-activity-hide');
    if (hideBtn) {
      hideBtn.addEventListener('click', function () {
        setActivityPanelOpen(false);
      });
    }

    populateActivityPluginFilter();
    setActivityPanelFilters(activityState.filters);

    var pluginSel = root.querySelector('.ts-activity-filter-plugin');
    if (pluginSel) {
      pluginSel.addEventListener('change', function () {
        applyActivityFilters();
      });
    }

    var outcomeSel = root.querySelector('.ts-activity-filter-outcome');
    if (outcomeSel) {
      outcomeSel.addEventListener('change', function () {
        applyActivityFilters();
      });
    }

    var actionInput = root.querySelector('.ts-activity-filter-action');
    if (actionInput) {
      actionInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          applyActivityFilters();
        }
      });
    }

    var applyBtn = root.querySelector('.ts-activity-filter-apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        applyActivityFilters();
      });
    }

    var resetBtn = root.querySelector('.ts-activity-filter-reset');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        resetActivityFilters();
      });
    }

    var loadMoreBtn = root.querySelector('.ts-activity-load-more');
    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', function () {
        fetchActivityTimeline(false);
      });
    }
  }

  function buildItem(p) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ts-ab-item';
    // [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP — dim plan/plugin-locked items.
    if (p.plan_locked || p.plugin_locked) {
      btn.className += ' is-plan-locked';
    }
    btn.dataset.pluginId = p.id;
    var titleSuffix = p.plan_badge ? ' [' + p.plan_badge + ']' : '';
    btn.title = (p.label || p.id) + titleSuffix;
    btn.setAttribute('role', 'tab');
    btn.setAttribute('aria-label', btn.title);
    // Render icon + optional plan badge chip.
    var iconHtml = renderIcon(p.icon || '');
    if (p.plan_badge) {
      iconHtml += '<span class="ts-ab-plan-badge ts-ab-plan-badge--' +
        p.plan_badge.toLowerCase() + '">' + p.plan_badge + '</span>';
    }
    btn.innerHTML = iconHtml;
    btn.addEventListener('click', function () {
      // Plan-locked or plugin-locked: navigate to notice page (PHP renders it).
      if (p.plan_locked || p.plugin_locked) {
        var shellUrl = cfg.shellUrl || '/twin/';
        var sep = shellUrl.indexOf('?') !== -1 ? '&' : '?';
        window.location.href = shellUrl + sep + 'plugin=' + encodeURIComponent(p.id);
        return;
      }
      // [2026-06-08 Johnny Chu] HOTFIX — nav_plugin/nav_iurl: redirect to another
      // plugin + deep-link instead of creating an iframe for this button's own id.
      if (p.nav_plugin) {
        navigate(p.nav_plugin, {}, { iurl: p.nav_iurl || '' });
      } else {
        navigate(p.id, {});
      }
    });
    return btn;
  }

  function buildActivityToggle() {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ts-ab-item ts-ab-activity-log';
    btn.dataset.role = 'activity-log';
    btn.setAttribute('role', 'button');
    btn.setAttribute('aria-pressed', 'false');
    btn.title = 'Xem activity TwinShell';
    btn.setAttribute('aria-label', btn.title);
    btn.innerHTML = renderIcon('explore');

    btn.addEventListener('click', function () {
      setActivityPanelOpen(!activityState.open);
    });

    return btn;
  }

  function renderActivityBar() {
    var top = root.querySelector('.ts-ab-top');
    var bottom = root.querySelector('.ts-ab-bottom');

    // Activity timeline toggle.
    top.appendChild(buildActivityToggle());

    // Context-toggle button — switches between standalone /twin/ and the
    // wp-admin TwinChat page so the user has one click to flip surfaces.
    top.appendChild(buildContextToggle());

    // Fold-admin button — collapses the wp-admin sidebar inside the active iframe
    // (when the embedded page is a wp-admin screen). No-op for non-admin iframes.
    top.appendChild(buildFoldAdminToggle());

    cfg.plugins.forEach(function (p) {
      var item = buildItem(p);
      if (p.section === 'bottom') bottom.appendChild(item);
      else top.appendChild(item);
    });
  }

  // Detect whether we are loaded inside the WP admin iframe wrapper.
  // The wrapper always appends ?bizcity_iframe=1, AND we are framed.
  function isInsideAdminIframe() {
    try {
      if (window.top === window.self) return false;
    } catch (e) { return true; /* cross-origin → assume framed */ }
    var sp = new URLSearchParams(window.location.search);
    return sp.get('bizcity_iframe') === '1';
  }

  function buildContextToggle() {
    var inAdmin = isInsideAdminIframe();
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ts-ab-item ts-ab-context-toggle';
    btn.dataset.role = 'context-toggle';
    btn.setAttribute('role', 'button');

    if (inAdmin) {
      btn.title = 'Mở /twin/ (thoát wp-admin)';
      btn.setAttribute('aria-label', btn.title);
      btn.innerHTML = renderIcon('external');
    } else {
      btn.title = 'Mở trong wp-admin';
      btn.setAttribute('aria-label', btn.title);
      btn.innerHTML = renderIcon('admin');
    }

    btn.addEventListener('click', function () {
      var pluginId = current.pluginId || cfg.defaultPlugin || (cfg.plugins[0] && cfg.plugins[0].id) || '';
      if (inAdmin) {
        // Pop out: navigate the TOP window to standalone /twin/.
        var url = '/twin/' + (pluginId ? '?plugin=' + encodeURIComponent(pluginId) : '');
        try { window.top.location.href = url; }
        catch (e) { window.location.href = url; }
      } else {
        // Enter wp-admin TwinChat page (which itself iframes /twin/).
        var adminUrl = '/wp-admin/admin.php?page=bizcity-twinchat'
                     + (pluginId ? '&plugin=' + encodeURIComponent(pluginId) : '');
        window.location.href = adminUrl;
      }
    });
    return btn;
  }

  // Check if this shell is running inside wp-admin (admin.php) by inspecting
  // the parent frame URL — more reliable than DOM lookup which may fail if
  // admin chrome hasn't painted yet.
  function getAdminParentWindow() {
    // Case A: this script runs directly on admin.php (no parent frame).
    try {
      if (document.getElementById('collapse-button')) return window;
    } catch (e) {}

    // Case B: walk parent chain, check URL for admin.php.
    var win = window;
    for (var i = 0; i < 6; i++) {
      var p;
      try { p = win.parent; } catch (e) { return null; }
      if (!p || p === win) return null;
      try {
        var href = p.location.href;
        if (href && href.indexOf('admin.php') !== -1 &&
            p.location.origin === window.location.origin) {
          return p;
        }
      } catch (e) { return null; }
      win = p;
    }
    return null;
  }

  function syncFoldAdminBtnState(btn) {
    var adminWin = getAdminParentWindow();
    var has = !!adminWin;
    btn.disabled = !has;
    btn.style.opacity = has ? '' : '0.35';
    btn.style.cursor  = has ? '' : 'not-allowed';

    var folded = false;
    if (has) {
      try { folded = adminWin.document.body.classList.contains('folded'); } catch (e) {}
    }
    btn.innerHTML = renderIcon(folded ? 'panel-left-open' : 'panel-left-close');
    var title = !has
      ? 'Thu/mở wp-admin sidebar (chỉ khả dụng khi /twin/ chạy trong wp-admin)'
      : (folded ? 'Mở wp-admin sidebar' : 'Thu gọn wp-admin sidebar');
    btn.title = title;
    btn.setAttribute('aria-label', title);
    btn.setAttribute('aria-pressed', folded ? 'true' : 'false');
  }

  function buildFoldAdminToggle() {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ts-ab-item ts-ab-fold-admin';
    btn.dataset.role = 'fold-admin';
    btn.setAttribute('role', 'button');
    btn.innerHTML = renderIcon('panel-left-close');
    btn.title = 'Thu gọn wp-admin sidebar';
    btn.setAttribute('aria-label', btn.title);

    btn.addEventListener('click', function () {
      var adminWin = getAdminParentWindow();
      if (!adminWin) return;
      try {
        // Click WP's native #collapse-button → persists folded state to user_meta.
        var collapseBtn = adminWin.document.getElementById('collapse-button');
        if (collapseBtn && typeof collapseBtn.click === 'function') {
          collapseBtn.click();
        } else {
          // Fallback: toggle class directly.
          adminWin.document.body.classList.toggle('folded');
        }
      } catch (e) { console.warn('[twin-shell][fold-admin] err', e); }
      // Re-sync icon/state after WP toggles the class.
      setTimeout(function () { syncFoldAdminBtnState(btn); }, 0);
      setTimeout(function () { syncFoldAdminBtnState(btn); }, 300);
    });

    // Keep icon/state in sync (parent may toggle independently).
    var refresh = function () { syncFoldAdminBtnState(btn); };
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 1500);
    setTimeout(refresh, 200);

    return btn;
  }

  function setActiveButton(pluginId) {
    var btns = root.querySelectorAll('.ts-ab-item');
    for (var i = 0; i < btns.length; i++) {
      var pid = btns[i].dataset.pluginId || '';
      if (!pid) continue;
      var on = pid === pluginId;
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
        if (cur.pathname !== next.pathname || cur.search !== next.search || cur.hash !== next.hash) {
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
          var thisPid = iframe.dataset.pluginId;
          console.log('[twin-shell][load]', { pluginId: thisPid, iframeHref: loc.href, params: paramsFromIframeUrl(loc.href) });

          // 1) Immediate sync on full-page load.
          syncAddressBarFromIframe(iframe, true);
          // 2) Hook SPA/admin navigation events inside iframe.
          installIframeDeepLinkSync(iframe);
          // 3) Poll fallback for edge cases where pushState wrappers are bypassed.
          if (!iframe.__urlPoll) {
            iframe.__urlPoll = setInterval(function () {
              syncAddressBarFromIframe(iframe, false);
            }, 300);
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
      if (fr && fr.__deepSyncCleanup) { try { fr.__deepSyncCleanup(); } catch (e) {} fr.__deepSyncCleanup = null; }
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

    if (activityState.open && !activityState.loading) {
      // Refresh timeline shortly after nav to pick up newly emitted shell events.
      setTimeout(function () {
        fetchActivityTimeline(true);
      }, 250);
    }

    if (!opts.skipUrlWrite) {
      writeShellUrl(pluginId, paramsObj);
    }
  }

  // ── postMessage bridge ─────────────────────────────────────────────────
  window.addEventListener('message', function (ev) {
    if (ev.origin !== SHELL_ORIGIN) return;
    var data = ev.data;
    if (!data || data.source !== 'twin-plugin') return;

    var senderPluginId = pluginIdFromWindow(ev.source) || current.pluginId;

    if (data.type === 'nav' && typeof data.url === 'string') {
      // Child tells us its URL changed — update parent shell URL + persist deep link.
      if (!senderPluginId) return;
      var params = paramsFromIframeUrl(data.url);
      writeShellUrl(senderPluginId, params, data.url);
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
      if (senderPluginId && data.url && typeof data.url === 'string') {
        var rparams = paramsFromIframeUrl(data.url);
        writeShellUrl(senderPluginId, rparams, data.url);
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
