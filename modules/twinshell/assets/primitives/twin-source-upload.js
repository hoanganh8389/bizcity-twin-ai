/**
 * Bizcity Twin Shell — Source Upload primitive (Phase 0.13 W2).
 *
 * Vanilla unified dialog for: upload file / paste URL / paste text → notebook.
 * After submit, opens SSE learning stream and shows live console log.
 * "Minimize" button stows the dialog into a floating chip (top-right of viewport)
 * with spinner + count; click chip → restore dialog.
 *
 * Public API:
 *   window.BizcityTwin.openSourceUpload({
 *     notebook_id?: number,            // if missing AND host given → lazy auto-create
 *     fallbackTitle?: string,          // used as notebook title when auto-create
 *     host?: { plugin, entity_type, entity_id },  // for bind on auto-create
 *     onSourceAdded?: (source) => void,
 *   })
 *
 * Activity layout:
 *   - Modal (overlay): full upload + console UI.
 *   - Floating chip: bottom-right, persists across page navigations within the
 *     iframe (sessionStorage rehydrate).
 *
 * Note: "auto-switch ajax/cron" runs server-side already in
 *       BizCity_TwinChat_Learning_Pipeline. Frontend just consumes SSE.
 */
(function () {
  'use strict';

  if (!window.BizcityTwin) {
    console.warn('[twin-source-upload] BizcityTwin not loaded yet — skipping');
    return;
  }
  if (window.BizcityTwin.__sourceUploadLoaded) return;
  window.BizcityTwin.__sourceUploadLoaded = true;

  var CFG = window.BIZCITY_TWIN_PRIMITIVES_CFG || {};
  var NONCE = CFG.nonce || '';

  // Knowledge namespace (canonical scoped routes).
  var KG_ROOT = (function () {
    try {
      // restRoot is bizcity-twin-shell/v1 — derive sibling KG namespace.
      var u = new URL(CFG.restRoot || '/wp-json/bizcity-twin-shell/v1/', window.location.origin);
      return u.origin + '/wp-json/bizcity-knowledge/v2/';
    } catch (e) { return '/wp-json/bizcity-knowledge/v2/'; }
  })();
  var TC_ROOT = (function () {
    try {
      var u = new URL(CFG.restRoot || '/wp-json/bizcity-twin-shell/v1/', window.location.origin);
      return u.origin + '/wp-json/bizcity-twinchat/v1/';
    } catch (e) { return '/wp-json/bizcity-twinchat/v1/'; }
  })();
  // Tavily search gateway (LLM Router proxy) — unified across all plugins.
  var SEARCH_ROOT = (function () {
    try {
      var u = new URL(CFG.restRoot || '/wp-json/bizcity-twin-shell/v1/', window.location.origin);
      return u.origin + '/wp-json/search/router/v1/';
    } catch (e) { return '/wp-json/search/router/v1/'; }
  })();

  var SESSION_KEY = 'bizcity_twin_uploads_state';
  // Persist to localStorage so logs survive iframe reload + plugin switching
  // inside Twin Shell (sessionStorage is per browsing-context).
  var STORE = (function () {
    try { return window.localStorage; } catch (e) { return window.sessionStorage; }
  })();

  // ── State ──────────────────────────────────────────────────────────────
  // sessions[notebook_id] = { logs:[], jobs:[], minimized:bool, eventSource:EventSource|null,
  //                           done:bool, openCount:int }
  var sessions = Object.create(null);
  var modalEl = null;          // current open modal DOM
  var chipEl = null;           // floating chip DOM
  var currentNb = null;        // notebook_id whose modal is open

  function loadPersisted() {
    try {
      var raw = STORE.getItem(SESSION_KEY);
      if (!raw) return;
      var data = JSON.parse(raw);
      Object.keys(data || {}).forEach(function (nb) {
        sessions[nb] = Object.assign({ logs: [], jobs: [], minimized: true, done: false, eventSource: null }, data[nb]);
        sessions[nb].eventSource = null;
      });
    } catch (e) {}
  }
  function persist() {
    try {
      var snap = {};
      Object.keys(sessions).forEach(function (nb) {
        var s = sessions[nb];
        if (!s) return;
        if (s.done && (!s.logs || s.logs.length === 0)) return;
        snap[nb] = { logs: s.logs.slice(-200), jobs: s.jobs, minimized: true, done: s.done };
      });
      STORE.setItem(SESSION_KEY, JSON.stringify(snap));
    } catch (e) {}
  }

  function getSession(nb) {
    nb = Number(nb);
    if (!sessions[nb]) {
      sessions[nb] = { logs: [], jobs: [], minimized: false, done: false, eventSource: null, openCount: 0, batchesDone: 0, batchesTotal: 0 };
    }
    return sessions[nb];
  }

  function activeCount() {
    var n = 0;
    Object.keys(sessions).forEach(function (k) {
      if (sessions[k] && !sessions[k].done) n++;
    });
    return n;
  }

  function logTo(nb, level, msg) {
    var s = getSession(nb);
    s.logs.push({ ts: Date.now(), level: level || 'info', msg: String(msg) });
    if (s.logs.length > 500) s.logs = s.logs.slice(-500);
    if (modalEl && currentNb === Number(nb)) renderConsole(modalEl, s);
    persist();
    refreshChip();
    notifyParentChip(nb);
  }

  // ── Parent shell bridge (when running inside Twin Shell iframe) ────────
  function inIframe() { try { return window.self !== window.top; } catch (e) { return true; } }
  function notifyParentChip(nb) {
    if (!inIframe()) return;
    var s = sessions[nb]; if (!s) return;
    try {
      window.parent.postMessage({
        source: 'twin-plugin',
        type: 'upload:chip',
        notebook_id: Number(nb),
        is_live: !s.done,
        active_count: activeCount(),
        last_msg: (s.logs[s.logs.length - 1] || {}).msg || '',
      }, '*');
    } catch (e) {}
  }
  function notifyParentClear(nb) {
    if (!inIframe()) return;
    try {
      window.parent.postMessage({
        source: 'twin-plugin',
        type: 'upload:clear',
        notebook_id: Number(nb),
      }, '*');
    } catch (e) {}
  }
  // Listen for parent asking us to restore.
  window.addEventListener('message', function (ev) {
    var d = ev.data;
    if (!d || d.source !== 'twin-shell') return;
    if (d.type === 'upload:restore' && d.notebook_id) {
      openModal(Number(d.notebook_id));
    }
  });

  // ── DOM helpers ────────────────────────────────────────────────────────
  function $(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') el.className = attrs[k];
      else if (k === 'style' && typeof attrs[k] === 'object') Object.assign(el.style, attrs[k]);
      else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') el.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
      else if (attrs[k] != null) el.setAttribute(k, attrs[k]);
    }
    if (children) (Array.isArray(children) ? children : [children]).forEach(function (c) {
      if (c == null) return;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }
  function fmtTime(ts) {
    var d = new Date(ts);
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
  }

  // ── Floating chip ──────────────────────────────────────────────────────
  var CHIP_HINT_KEY = 'bizcity_twin_chip_hint_dismissed_v1';
  var chipHintEl = null;

  function isChipHintDismissed() {
    try { return STORE.getItem(CHIP_HINT_KEY) === '1'; } catch (e) { return false; }
  }
  function showChipHint() {
    if (chipHintEl || isChipHintDismissed() || !chipEl) return;
    var hint = $('div', { class: 'btsu-chip-hint' });
    hint.innerHTML =
      '<span class="btsu-chip-hint-icon">💡</span>'
      + '<span class="btsu-chip-hint-text">Twin is learning. Answers will be more accurate once the deep-learning step finishes.</span>'
      + '<button type="button" class="btsu-chip-hint-close" aria-label="Dismiss">✕</button>';
    hint.querySelector('.btsu-chip-hint-close').addEventListener('click', function (ev) {
      ev.stopPropagation();
      dismissChipHint();
      try { STORE.setItem(CHIP_HINT_KEY, '1'); } catch (e) {}
    });
    document.body.appendChild(hint);
    chipHintEl = hint;
    positionChipHint();
    if (hint._rl) window.removeEventListener('resize', hint._rl);
    hint._rl = positionChipHint;
    window.addEventListener('resize', positionChipHint);
  }
  function positionChipHint() {
    if (!chipHintEl || !chipEl) return;
    var r = chipEl.getBoundingClientRect();
    // Bubble sits to the LEFT of the chip; arrow on right side points →
    chipHintEl.style.right = (window.innerWidth - r.left + 10) + 'px';
    chipHintEl.style.bottom = r.height + 'px';
    chipHintEl.style.left = 'auto';
    chipHintEl.style.top = 'auto';
  }
  function dismissChipHint() {
    if (chipHintEl) {
      if (chipHintEl._rl) window.removeEventListener('resize', chipHintEl._rl);
      if (chipHintEl.parentNode) chipHintEl.parentNode.removeChild(chipHintEl);
      chipHintEl = null;
    }
  }

  function computeProgress() {
    var done = 0, total = 0;
    Object.keys(sessions).forEach(function (k) {
      var s = sessions[k];
      if (!s || s.done) return;
      done  += s.batchesDone  || 0;
      total += s.batchesTotal || 0;
    });
    var pct = total > 0 ? Math.min(1, done / total) : 0;
    return { done: done, total: total, pct: pct };
  }

  function refreshChip() {
    var n = activeCount();
    var hasAny = Object.keys(sessions).length > 0;
    if (!hasAny || (n === 0 && !chipNeedsLast())) { hideChip(); dismissChipHint(); return; }
    if (!chipEl) {
      chipEl = $('button', {
        type: 'button',
        class: 'btsu-chip',
        title: 'Open learning monitor',
        onClick: function () {
          dismissChipHint();
          var nb = pickActiveNb();
          if (nb) openModal(nb);
        },
      });
      document.body.appendChild(chipEl);
    }
    var label = n > 0 ? ('🧠 Learning · ' + n) : '🧠 Done';
    var prog = n > 0 ? computeProgress() : null;
    var indeterminate = prog && prog.total === 0;
    var pctLabel = prog && prog.total > 0 ? (' · ' + Math.round(prog.pct * 100) + '%') : '';
    var hasBar = prog != null; // show bar whenever active
    chipEl.className = 'btsu-chip' + (hasBar ? ' has-bar' : '');
    chipEl.innerHTML = '';
    var row = $('span', { class: 'btsu-chip-row' });
    row.appendChild($('span', { class: 'btsu-chip-dot' + (n > 0 ? ' is-live' : '') }));
    row.appendChild($('span', { class: 'btsu-chip-label' }, label + pctLabel));
    chipEl.appendChild(row);
    if (hasBar) {
      var bar = $('span', { class: 'btsu-chip-bar' });
      var fill = $('span', { class: 'btsu-chip-bar-fill' + (indeterminate ? ' is-indet' : '') });
      if (!indeterminate) fill.style.width = Math.round((prog.pct || 0) * 100) + '%';
      bar.appendChild(fill);
      chipEl.appendChild(bar);
      if (prog.total > 0) {
        chipEl.appendChild($('span', { class: 'btsu-chip-batch' }, prog.done + '/' + prog.total + ' batches'));
      }
    }
    if (n > 0) showChipHint();
    else dismissChipHint();
  }
  function chipNeedsLast() {
    // Show "done" chip briefly until user dismisses by opening once.
    return Object.keys(sessions).some(function (k) { return sessions[k] && sessions[k].done && (sessions[k].openCount || 0) === 0; });
  }
  function hideChip() {
    if (chipEl && chipEl.parentNode) chipEl.parentNode.removeChild(chipEl);
    chipEl = null;
  }
  function pickActiveNb() {
    // Prefer running, fallback to most recent.
    var keys = Object.keys(sessions);
    var running = keys.filter(function (k) { return sessions[k] && !sessions[k].done; });
    return Number(running[0] || keys[keys.length - 1] || 0) || null;
  }

  // ── Console rendering ──────────────────────────────────────────────────
  function renderConsole(modal, s) {
    var box = modal.querySelector('.btsu-console');
    if (!box) return;
    box.innerHTML = '';
    s.logs.forEach(function (e) {
      var line = $('div', { class: 'btsu-line btsu-' + (e.level || 'info') }, [
        $('span', { class: 'btsu-line-ts' }, fmtTime(e.ts)),
        $('span', { class: 'btsu-line-msg' }, e.msg),
      ]);
      box.appendChild(line);
    });
    box.scrollTop = box.scrollHeight;
    // Update head status.
    var statusEl = modal.querySelector('.btsu-term-status');
    if (statusEl) {
      statusEl.className = 'btsu-term-status ' + (s.done ? 'is-done' : 'is-running');
      statusEl.textContent = s.done ? '● done' : '● running';
    }
  }

  // ── REST helpers ───────────────────────────────────────────────────────
  function uploadSource(notebook_id, payload) {
    var url = KG_ROOT + 'scoped/twinchat/' + Number(notebook_id) + '/sources';
    var headers = { 'X-WP-Nonce': NONCE };
    var body;
    if (payload.file) {
      var fd = new FormData();
      fd.append('type', 'file');
      if (payload.title) fd.append('title', payload.title);
      fd.append('file', payload.file);
      body = fd;
    } else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify({
        type:    payload.type,            // 'url' | 'text'
        title:   payload.title || '',
        content: payload.content || '',
        url:     payload.url || '',
      });
    }
    return fetch(url, { method: 'POST', headers: headers, credentials: 'same-origin', body: body })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || (j && j.ok === false)) {
            throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
          }
          return j.data || j;
        });
      });
  }

  // Tavily web search via LLM Router gateway (cross-plugin).
  function searchWeb(query, maxResults) {
    var body = JSON.stringify({
      query: String(query || '').trim(),
      max_results: Number(maxResults) || 5,
      search_depth: 'basic',
      include_answer: true,
      include_raw_content: false,
    });
    return fetch(SEARCH_ROOT + 'query', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      credentials: 'same-origin',
      body: body,
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error((j && (j.message || j.error)) || ('HTTP ' + r.status));
        if (j && j.success === false) throw new Error(j.error || j.message || 'Search failed');
        return j; // { results:[...], answer, query }
      });
    });
  }

  function startLearningStream(notebook_id) {
    var s = getSession(notebook_id);
    if (s.eventSource) return;
    // Reconnect/event-receipt counters — prevent infinite reconnect-with-no-data loop.
    s.streamReconnects = (s.streamReconnects || 0);
    s.streamRowsThisSession = 0;
    if (s.streamReconnects >= 3) {
      logTo(notebook_id, 'warn', 'Twin im lặng sau 3 lần thử — dừng theo dõi. Mở lại nếu cần.');
      return;
    }
    var url = TC_ROOT + 'learning/stream?notebook_id=' + Number(notebook_id) + '&_wpnonce=' + encodeURIComponent(NONCE);
    var es;
    try { es = new EventSource(url, { withCredentials: true }); }
    catch (e) { logTo(notebook_id, 'warn', 'SSE not available: ' + e.message); return; }
    s.eventSource = es;
    s.lastStreamOpenAt = Date.now();

    function payloadOf(ev) { try { return (JSON.parse(ev.data) || {}).payload || JSON.parse(ev.data) || {}; } catch (e) { return {}; } }
    es.addEventListener('open', function () { logTo(notebook_id, 'info', 'Connected to learning stream…'); });
    es.addEventListener('log', function (ev) {
      s.streamRowsThisSession++;
      var p = payloadOf(ev);
      logTo(notebook_id, p.level || 'info', String(p.msg || ''));
    });
    es.addEventListener('job', function (ev) {
      s.streamRowsThisSession++;
      var p = payloadOf(ev);
      if (p.status === 'queued')    logTo(notebook_id, 'info', 'Job #' + (p.job_id || '?') + ' queued' + (p.source_title ? ' (' + p.source_title + ')' : ''));
      if (p.status === 'running')   logTo(notebook_id, 'info', 'Job #' + (p.job_id || '?') + ' running');
      if (p.status === 'cancelled') logTo(notebook_id, 'warn', 'Job #' + (p.job_id || '?') + ' cancelled');
    });
    es.addEventListener('progress', function (ev) {
      s.streamRowsThisSession++;
      var p = payloadOf(ev);
      // Capture batch progress for chip progress bar.
      if (p.batches_total != null) s.batchesTotal = Number(p.batches_total) || 0;
      if (p.batches_done  != null) s.batchesDone  = Number(p.batches_done)  || 0;
      refreshChip();
      var loop = p.loop != null ? p.loop : '?';
      var proc = p.processed_this_loop || 0;
      var trip = p.triplets_this_loop || 0;
      if (proc > 0 || trip > 0) logTo(notebook_id, 'info', '[' + loop + '] +' + proc + ' passages → ' + trip + ' triplets');
    });
    es.addEventListener('done', function (ev) {
      var p = payloadOf(ev);
      if (p && p.failed) {
        logTo(notebook_id, 'error', 'Failed: ' + (p.error || 'unknown error'));
      } else {
        logTo(notebook_id, 'ok', 'Memory consolidation complete.');
      }
      s.done = true;
      s.streamReconnects = 0;
      closeStream(notebook_id);
    });
    // 2026-04-30 — backend short-circuits with `event: idle` when queue is empty.
    // Close so the browser stops auto-reconnecting (legacy bug spammed every ~90s).
    es.addEventListener('idle', function (ev) {
      var p = payloadOf(ev);
      logTo(notebook_id, 'info', 'Twin idle — không còn job nào đang học' + (p.reason ? ' (' + p.reason + ').' : '.'));
      s.done = true;
      s.streamReconnects = 0;
      closeStream(notebook_id);
    });
    // 2026-04-30 — backend emits `event: stale` when a job is `running` but
    // produced no events for STALE_AFTER_S seconds. Show actionable info so
    // the user knows EXACTLY what is stuck instead of staring at reconnects.
    es.addEventListener('stale', function (ev) {
      var p = payloadOf(ev);
      var jobs = (p && p.jobs) || [];
      if (jobs.length === 0) {
        logTo(notebook_id, 'warn', 'Twin im lặng — không có tiến triển nào trong ' + (p.silent_for_s || '?') + 's.');
      } else {
        jobs.forEach(function (j) {
          var min = j.silent_min != null ? j.silent_min + ' phút' : '?';
          logTo(notebook_id, 'warn',
            'Job #' + j.job_id + ' (source ' + j.source_id + ') đã ở trạng thái “' + j.status + '” ' + min +
            ' nhưng không phát tiến triển. Có thể worker bị kẹt — cân nhắc cancel.'
          );
        });
      }
      s.done = true;
      s.streamReconnects = 0;
      closeStream(notebook_id);
    });
    // Native EventSource auto-reconnects with Last-Event-ID. Track reconnects
    // so we can stop after N attempts with no data — prevents the silent loop
    // the user reported (“Connected to learning stream…” every 90s forever).
    es.addEventListener('error', function () {
      if (es.readyState === 2 /* CLOSED */) {
        s.eventSource = null;
        // Closed by server. If we got no rows this session, count it as a wasted reconnect.
        if (s.streamRowsThisSession === 0) {
          s.streamReconnects++;
          if (s.streamReconnects >= 3) {
            logTo(notebook_id, 'warn', 'Twin im lặng sau 3 lần kết nối — dừng auto-stream. Reload để thử lại.');
            s.done = true;
          }
        }
      }
      // CONNECTING (0) / OPEN (1): silent, browser handles reconnect.
    });
  }
  function closeStream(nb) {
    var s = sessions[nb];
    if (s && s.eventSource) { try { s.eventSource.close(); } catch (e) {} s.eventSource = null; }
    persist();
    refreshChip();
  }

  // ── Modal ──────────────────────────────────────────────────────────────
  function buildModal(notebook_id, opts) {
    var s = getSession(notebook_id);
    s.openCount = (s.openCount || 0) + 1;
    s.minimized = false;
    currentNb = Number(notebook_id);

    var titleInput = $('input', { type: 'text', class: 'btsu-input', placeholder: 'Title (optional)' });
    var urlInput   = $('input', { type: 'url',  class: 'btsu-input', placeholder: 'https://…' });
    var textArea   = $('textarea', { class: 'btsu-input btsu-textarea', placeholder: 'Paste text…', rows: '6' });
    var fileInput  = $('input', { type: 'file', class: 'btsu-file-input', multiple: 'multiple' });

    var dropzone = $('div', { class: 'btsu-dropzone' }, [
      $('div', { class: 'btsu-dropzone-icon' }, '⬆'),
      $('div', { class: 'btsu-dropzone-text' }, 'Drop files here, or click to browse'),
      fileInput,
    ]);
    dropzone.addEventListener('click', function () { fileInput.click(); });
    ;['dragenter','dragover'].forEach(function (e) { dropzone.addEventListener(e, function (ev) { ev.preventDefault(); dropzone.classList.add('is-over'); }); });
    ;['dragleave','drop'].forEach(function (e) { dropzone.addEventListener(e, function (ev) { ev.preventDefault(); dropzone.classList.remove('is-over'); }); });
    dropzone.addEventListener('drop', function (ev) {
      var files = ev.dataTransfer && ev.dataTransfer.files;
      if (files && files.length) handleFiles(files);
    });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files.length) handleFiles(fileInput.files);
    });

    var fileTab = $('div', { class: 'btsu-dz-wrap' }, [ dropzone ]);

    // Inline collapsible URL form
    var urlForm = $('div', { class: 'btsu-mini-form', style: { display: 'none' } }, [
      $('div', { class: 'btsu-mini-row' }, [
        urlInput,
        $('button', { type: 'button', class: 'btsu-btn btsu-btn-primary', onClick: function () {
          var u = (urlInput.value || '').trim();
          if (!u) return;
          submitOne({ type: 'url', url: u, title: '' });
          urlInput.value = '';
          urlForm.style.display = 'none';
        } }, 'Add URL'),
      ]),
    ]);

    // Inline collapsible Text form
    var textForm = $('div', { class: 'btsu-mini-form', style: { display: 'none' } }, [
      textArea,
      $('div', { class: 'btsu-mini-row', style: { justifyContent: 'flex-end' } }, [
        $('button', { type: 'button', class: 'btsu-btn btsu-btn-primary', onClick: function () {
          var t = (textArea.value || '').trim();
          if (!t) return;
          submitOne({ type: 'text', content: t, title: 'Text · ' + new Date().toLocaleString() });
          textArea.value = '';
          textForm.style.display = 'none';
        } }, 'Save text'),
      ]),
    ]);

    // ── Tab: Search (Tavily) ──
    var searchInput  = $('input', { type: 'search', class: 'btsu-input', placeholder: 'BizCity deepSearch — search the web…' });
    var searchCount  = $('select', null, [
      $('option', { value: '3'  }, '3 results'),
      $('option', { value: '5', selected: 'selected' }, '5 results'),
      $('option', { value: '8'  }, '8 results'),
      $('option', { value: '12' }, '12 results'),
    ]);
    var searchGoBtn  = $('button', { type: 'button', class: 'btsu-btn btsu-btn-primary' }, 'Search');
    var searchAnswerEl = $('div', { class: 'btsu-search-answer', style: { display: 'none' } });
    var searchListEl   = $('div', { class: 'btsu-search-list' });
    var searchSelected = Object.create(null); // url -> { title, url, content }
    var searchSelCount = $('span', { class: 'count' }, '0 selected');
    var searchImportBtn = $('button', { type: 'button', class: 'btsu-btn btsu-btn-primary' }, 'Import selected');
    searchImportBtn.disabled = true;
    // Actions bar lives ABOVE the list (below AI summary).
    var searchActionsEl = $('div', { class: 'btsu-search-actions', style: { display: 'none' } }, [ searchSelCount, searchImportBtn ]);

    function refreshSearchSelCount() {
      var n = Object.keys(searchSelected).length;
      searchSelCount.textContent = n + ' selected';
      searchImportBtn.disabled = n === 0;
    }
    function showSearchActions(show) {
      searchActionsEl.style.display = show ? '' : 'none';
    }
    function renderSearchResults(resp) {
      searchListEl.innerHTML = '';
      if (resp && resp.answer) {
        searchAnswerEl.style.display = '';
        searchAnswerEl.innerHTML = '';
        searchAnswerEl.appendChild($('b', null, 'AI summary:'));
        searchAnswerEl.appendChild(document.createTextNode(' ' + resp.answer));
      } else {
        searchAnswerEl.style.display = 'none';
      }
      var results = (resp && resp.results) || [];
      if (!results.length) {
        searchListEl.appendChild($('div', { class: 'btsu-search-empty' }, 'No results.'));
        showSearchActions(false);
        return;
      }
      showSearchActions(true);
      results.forEach(function (r) {
        var cb = $('input', { type: 'checkbox' });
        cb.checked = !!searchSelected[r.url];
        var item = $('div', { class: 'btsu-search-item' + (cb.checked ? ' is-checked' : '') }, [
          cb,
          $('div', { style: { flex: '1' } }, [
            $('div', { class: 'ttl' }, r.title || r.url),
            $('div', { class: 'url' }, r.url),
            r.content ? $('div', { class: 'snippet' }, String(r.content).slice(0, 220)) : null,
          ]),
        ]);
        function toggle() {
          cb.checked = !cb.checked;
          if (cb.checked) {
            searchSelected[r.url] = { title: r.title || r.url, url: r.url, content: r.content || '' };
            item.classList.add('is-checked');
          } else {
            delete searchSelected[r.url];
            item.classList.remove('is-checked');
          }
          refreshSearchSelCount();
        }
        item.addEventListener('click', function (ev) {
          if (ev.target === cb) { /* let native toggle, but sync state */
            if (cb.checked) {
              searchSelected[r.url] = { title: r.title || r.url, url: r.url, content: r.content || '' };
              item.classList.add('is-checked');
            } else {
              delete searchSelected[r.url];
              item.classList.remove('is-checked');
            }
            refreshSearchSelCount();
            return;
          }
          toggle();
        });
        searchListEl.appendChild(item);
      });
    }
    function runSearch() {
      var q = (searchInput.value || '').trim();
      if (!q) return;
      searchListEl.innerHTML = '';
      searchListEl.appendChild($('div', { class: 'btsu-search-empty' }, 'Searching “' + q + '”…'));
      searchAnswerEl.style.display = 'none';      showSearchActions(false);      searchGoBtn.disabled = true;
      searchWeb(q, Number(searchCount.value) || 5).then(function (resp) {
        renderSearchResults(resp);
      }).catch(function (err) {
        searchListEl.innerHTML = '';
        searchListEl.appendChild($('div', { class: 'btsu-search-empty', style: { color: '#f87171' } }, 'Error: ' + (err.message || err)));
      }).then(function () { searchGoBtn.disabled = false; });
    }
    searchGoBtn.addEventListener('click', runSearch);
    searchInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); } });
    searchImportBtn.addEventListener('click', function () {
      var picks = Object.keys(searchSelected).map(function (k) { return searchSelected[k]; });
      if (!picks.length) return;
      logTo(notebook_id, 'step', 'Importing ' + picks.length + ' search result(s)…');
      picks.forEach(function (p) {
        submitOne({
          type: 'url',
          url: p.url,
          title: p.title,
          content: p.content || '',
        });
      });
      searchSelected = Object.create(null);
      refreshSearchSelCount();
      // Re-render to clear checkmarks visually.
      Array.prototype.forEach.call(searchListEl.querySelectorAll('.btsu-search-item'), function (el) {
        el.classList.remove('is-checked');
        var c = el.querySelector('input[type=checkbox]'); if (c) c.checked = false;
      });
    });

    var searchTab = $('div', { class: 'btsu-search-wrap' }, [
      searchAnswerEl,
      searchActionsEl,   // Import selected — above the list, below AI summary
      searchListEl,
    ]);

    // ── Composite single-view (no tabs) ──
    var searchSubmitBtn = $('button', { type: 'button', class: 'btsu-search-go', title: 'Search' }, '→');
    function triggerSearch() {
      // Re-route the existing runSearch() flow via the dedicated submit btn.
      searchGoBtn.click();
    }
    searchSubmitBtn.addEventListener('click', triggerSearch);

    var searchHeader = $('div', { class: 'btsu-compose-search' }, [
      $('span', { class: 'btsu-compose-search-icon' }, '🔍'),
      searchInput,
      searchCount,
      searchSubmitBtn,
    ]);
    // hide the legacy 'Search' button visually — we reuse it programmatically only.
    searchGoBtn.style.display = 'none';

    // ── Notebook picker (above deepSearch) ──
    // Browse + import sources from notebooks the user has previously trained.
    var nbPickerInput = $('input', {
      type: 'search', class: 'btsu-input',
      placeholder: 'Browse existing notebooks (already trained)…',
      autocomplete: 'off',
    });
    var nbPickerDropdown = $('div', { class: 'btsu-nb-dropdown' });
    nbPickerDropdown.style.display = 'none';
    var nbPickerHeader = $('div', { class: 'btsu-compose-nb' }, [
      $('span', { class: 'btsu-compose-search-icon' }, '📚'),
      nbPickerInput,
      nbPickerDropdown,
    ]);
    var nbPickerCache = null; // [{id,name,description,stats,updated_at}]

    function loadNotebookList() {
      if (nbPickerCache) return Promise.resolve(nbPickerCache);
      var api = window.BizcityTwin && window.BizcityTwin._api;
      if (!api) return Promise.resolve([]);
      return api('notebooks').then(function (res) {
        nbPickerCache = ((res && res.notebooks) || []).filter(function (n) {
          return Number(n.id) !== Number(notebook_id); // exclude current
        });
        return nbPickerCache;
      }).catch(function () { return []; });
    }
    function renderNotebookDropdown(filter) {
      nbPickerDropdown.innerHTML = '';
      var f = String(filter || '').trim().toLowerCase();
      var rows = (nbPickerCache || []).filter(function (n) {
        if (!f) return true;
        return (n.name || '').toLowerCase().indexOf(f) >= 0
          || (n.description || '').toLowerCase().indexOf(f) >= 0;
      }).slice(0, 12);
      if (!rows.length) {
        nbPickerDropdown.appendChild($('div', { class: 'btsu-nb-empty' },
          (nbPickerCache && nbPickerCache.length) ? 'No matching notebook.' : 'You have no other notebooks yet.'));
        return;
      }
      rows.forEach(function (nb) {
        var stats = nb.stats || {};
        var sCount = stats.source_count != null ? stats.source_count : (stats.sources != null ? stats.sources : '?');
        var item = $('div', { class: 'btsu-nb-item' }, [
          $('div', { class: 'btsu-nb-item-main' }, [
            $('div', { class: 'ttl' }, nb.name || ('Notebook #' + nb.id)),
            nb.description ? $('div', { class: 'desc' }, String(nb.description).slice(0, 120)) : null,
          ]),
          $('div', { class: 'btsu-nb-item-meta' }, sCount + ' src'),
        ]);
        item.addEventListener('click', function () {
          nbPickerInput.value = nb.name || ('Notebook #' + nb.id);
          nbPickerDropdown.style.display = 'none';
          loadNotebookSources(Number(nb.id), nb.name || '');
        });
        nbPickerDropdown.appendChild(item);
      });
    }
    function loadNotebookSources(srcNb, srcName) {
      searchAnswerEl.style.display = '';
      searchAnswerEl.innerHTML = '';
      searchAnswerEl.appendChild($('b', null, '📚 From notebook:'));
      searchAnswerEl.appendChild(document.createTextNode(' ' + (srcName || ('#' + srcNb))));
      searchListEl.innerHTML = '';
      searchListEl.appendChild($('div', { class: 'btsu-search-empty' }, 'Loading sources…'));
      showSearchActions(false);
      var url = KG_ROOT + 'scoped/twinchat/' + srcNb + '/sources';
      fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var rows = (j && (j.sources || j.data || j)) || [];
          if (!Array.isArray(rows)) rows = [];
          var resp = { results: rows.map(function (s) {
            return {
              title: s.title || s.source_title || s.name || ('Source #' + (s.source_id || s.id)),
              url:   s.source_url || s.url || s.uri || '',
              content: s.summary || s.excerpt || '',
            };
          }).filter(function (r) { return !!r.url; }) };
          var skipped = rows.length - resp.results.length;
          renderSearchResults(resp);
          if (skipped > 0) {
            searchListEl.appendChild($('div', { class: 'btsu-search-empty', style: { fontSize: '11px' } },
              skipped + ' file/text source(s) skipped (only URL sources can be re-imported).'));
          }
        })
        .catch(function (err) {
          searchListEl.innerHTML = '';
          searchListEl.appendChild($('div', { class: 'btsu-search-empty', style: { color: '#f87171' } },
            'Failed to load sources: ' + (err.message || err)));
        });
    }
    nbPickerInput.addEventListener('focus', function () {
      loadNotebookList().then(function () {
        renderNotebookDropdown(nbPickerInput.value);
        nbPickerDropdown.style.display = '';
      });
    });
    nbPickerInput.addEventListener('input', function () {
      if (!nbPickerCache) loadNotebookList().then(function () { renderNotebookDropdown(nbPickerInput.value); nbPickerDropdown.style.display = ''; });
      else { renderNotebookDropdown(nbPickerInput.value); nbPickerDropdown.style.display = ''; }
    });
    document.addEventListener('click', function (ev) {
      if (!nbPickerHeader.contains(ev.target)) nbPickerDropdown.style.display = 'none';
    });

    var quickRow = $('div', { class: 'btsu-quick-row' }, [
      $('button', { type: 'button', class: 'btsu-quick-btn', onClick: function () { fileInput.click(); } }, [
        $('span', { class: 'btsu-quick-icon' }, '↑'), $('span', null, 'Upload file'),
      ]),
      $('button', { type: 'button', class: 'btsu-quick-btn', onClick: function () {
        urlForm.style.display = urlForm.style.display === 'none' ? '' : 'none';
        if (urlForm.style.display !== 'none') urlInput.focus();
      } }, [
        $('span', { class: 'btsu-quick-icon' }, '🔗'), $('span', null, 'Website'),
      ]),
      $('button', { type: 'button', class: 'btsu-quick-btn', onClick: function () {
        textForm.style.display = textForm.style.display === 'none' ? '' : 'none';
        if (textForm.style.display !== 'none') textArea.focus();
      } }, [
        $('span', { class: 'btsu-quick-icon' }, '📋'), $('span', null, 'Text'),
      ]),
    ]);

    var composePane = $('div', { class: 'btsu-compose' }, [
      nbPickerHeader,   // existing notebook picker (top)
      searchHeader,
      searchTab,        // results / answer / actions (empty initially)
      fileTab,          // dropzone
      urlForm,
      textForm,
      quickRow,
    ]);

    // Empty placeholders (kept to avoid touching existing references later).
    var tabBar = $('div', { style: { display: 'none' } });
    var paneWrap = $('div', { style: { display: 'none' } });

    var consoleBox = $('div', { class: 'btsu-console' });
    var minimizeBtn = $('button', { type: 'button', class: 'btsu-btn', title: 'Minimize — keep running in background' }, '— Minimize');
    var closeBtn    = $('button', { type: 'button', class: 'btsu-btn', title: 'Close' }, 'Close');
    var hostBadge   = opts && opts.hostHint ? $('span', { class: 'btsu-host-badge' }, opts.hostHint) : null;

    var modal = $('div', { class: 'btsu-modal', role: 'dialog', 'aria-modal': 'true' }, [
      $('div', { class: 'btsu-modal-head' }, [
        $('div', { class: 'btsu-modal-title' }, [
          'Add documents to project',
          hostBadge,
          $('span', { class: 'btsu-nb-tag' }, '#NB ' + notebook_id),
        ]),
        $('div', { class: 'btsu-modal-actions' }, [
          minimizeBtn,
          $('button', { type: 'button', class: 'btsu-modal-close', 'aria-label': 'Close', onClick: closeOrMinimize }, '✕'),
        ]),
      ]),
      $('div', { class: 'btsu-modal-body' }, [
        $('div', { class: 'btsu-upload-col' }, [ tabBar, paneWrap, composePane ]),
        $('div', { class: 'btsu-console-col' }, [
          $('div', { class: 'btsu-term-head' }, [
            $('span', { class: 'btsu-dot btsu-dot-r' }),
            $('span', { class: 'btsu-dot btsu-dot-y' }),
            $('span', { class: 'btsu-dot btsu-dot-g' }),
            $('span', { class: 'btsu-term-title' }, 'Twin · Second Brain log'),
            $('span', { class: 'btsu-term-status ' + (s.done ? 'is-done' : 'is-running') }, s.done ? '● done' : '● running'),
          ]),
          consoleBox,
        ]),
      ]),
      $('div', { class: 'btsu-modal-foot' }, [
        $('div', { class: 'btsu-foot-hint' }, 'When the upload finishes, click “Minimize” — Twin Shell keeps learning in the background.'),
        closeBtn,
      ]),
    ]);

    var overlay = $('div', { class: 'btsu-overlay' }, modal);
    document.body.appendChild(overlay);
    modalEl = modal;
    renderConsole(modal, s);

    // If caller passed defaultUrl, open the inline URL form pre-filled.
    if (opts && opts.defaultUrl) {
      try {
        urlForm.style.display = '';
        urlInput.value = opts.defaultUrl;
        urlInput.focus();
      } catch (e) {}
    }
    // Caller can request a Tavily search pre-filled.
    if (opts && opts.defaultSearch) {
      try {
        searchInput.value = String(opts.defaultSearch);
        runSearch();
      } catch (e) {}
    }

    minimizeBtn.addEventListener('click', minimize);
    closeBtn.addEventListener('click', closeOrMinimize);

    function handleFiles(files) {
      Array.prototype.forEach.call(files, function (f) {
        submitOne({ type: 'file', file: f, title: f.name });
      });
    }

    function submitOne(payload) {
      logTo(notebook_id, 'step', 'Uploading: ' + (payload.title || payload.url || payload.type));
      uploadSource(notebook_id, payload).then(function (data) {
        logTo(notebook_id, 'ok', 'Source loaded (id=' + (data.source_id || data.id || '?') + '). Deep learning in progress — you can keep working while it finishes.');
        if (opts && typeof opts.onSourceAdded === 'function') {
          try { opts.onSourceAdded(data); } catch (e) {}
        }
        // Same-window event for React hosts (e.g. bzdoc SourceSidebar).
        try {
          document.dispatchEvent(new CustomEvent('bizcity-twin:source-added', {
            detail: { notebook_id: Number(notebook_id), source: data },
          }));
        } catch (e) {}
        startLearningStream(notebook_id);
      }).catch(function (err) {
        logTo(notebook_id, 'error', 'Upload error: ' + (err.message || err));
      });
    }

    function minimize() {
      s.minimized = true;
      teardownModal();
      refreshChip();
    }
    function closeOrMinimize() {
      // If still running, minimize instead of fully closing.
      if (!s.done) { minimize(); return; }
      teardownModal();
      // Fully done → drop session.
      delete sessions[Number(notebook_id)];
      persist();
      refreshChip();
      notifyParentClear(notebook_id);
    }
    function teardownModal() {
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      modalEl = null;
      currentNb = null;
    }

    return modal;
  }

  function openModal(notebook_id, opts) {
    var nb = Number(notebook_id);
    // If a modal is already open for THIS notebook, just bring it forward
    // (no rebuild → prevents duplicate overlay stacking from rapid chip clicks).
    if (modalEl && currentNb === nb) {
      try { modalEl.focus && modalEl.focus(); } catch (e) {}
      return modalEl;
    }
    // Switching to a different notebook → fully tear down the previous overlay.
    if (modalEl) {
      var overlay = modalEl.parentNode; // .btsu-overlay
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      modalEl = null;
      currentNb = null;
    }
    // Belt-and-suspenders: drop any orphaned overlays left over from older bugs.
    Array.prototype.forEach.call(document.querySelectorAll('.btsu-overlay'), function (n) {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
    return buildModal(nb, opts || {});
  }

  // ── Public API ─────────────────────────────────────────────────────────
  window.BizcityTwin.openSourceUpload = function (opts) {
    opts = opts || {};
    var ensure;
    if (opts.notebook_id) {
      ensure = Promise.resolve({ notebook_id: Number(opts.notebook_id), title: '', is_new: false });
    } else if (opts.host && opts.pickIfMissing !== true) {
      // Default for HOSTED callers (e.g. bizdoc): "1 entity = 1 notebook"
      // model — silently auto-create a notebook bound to this host entity.
      // Per PHASE-6.1-DOC.md the document title IS the notebook name and
      // its source list is the notebook's source list. Showing a picker
      // here would break that mental model.
      //
      // Opt-in: pass `opts.pickIfMissing = true` to force the picker
      // (n-1 share scenarios — multiple host entities sharing 1 notebook).
      ensure = window.BizcityTwin.ensureNotebook({
        currentNotebookId: 0,
        fallbackTitle: opts.fallbackTitle || 'Untitled · ' + new Date().toLocaleString(),
        host: opts.host,
      });
    } else {
      // No host (or explicit pickIfMissing) → let user pick or create.
      ensure = window.BizcityTwin.pickNotebook({
        allowCreate: true,
        title: opts.fallbackTitle || '',
        host: opts.host || null,
      });
    }
    return ensure.then(function (res) {
      if (!res || !res.notebook_id) return null;
      // If the user picked an EXISTING notebook (is_new=false) and we have a
      // host entity, persist the binding so the next reload of that doc loads
      // straight into this notebook (no re-prompt). For is_new=true the bind
      // already happened server-side via POST /notebooks {host}.
      var bindP = Promise.resolve();
      if (opts.host && res.is_new === false && window.BizcityTwin.bindNotebook) {
        bindP = window.BizcityTwin.bindNotebook({
          plugin:      opts.host.plugin,
          entity_type: opts.host.entity_type,
          entity_id:   opts.host.entity_id,
          notebook_id: res.notebook_id,
        }).catch(function () { /* non-fatal */ });
      }
      return bindP.then(function () {
        openModal(res.notebook_id, opts);
        window.BizcityTwin.notifyNotebookActive(res.notebook_id);
        return res;
      });
    });
  };

  // Replace old stub.
  window.BizcityTwin.openSourcePanel = window.BizcityTwin.openSourceUpload;

  // Re-hydrate from sessionStorage on load → if there are pending uploads,
  // show the chip so user can resume monitoring.
  loadPersisted();
  refreshChip();
  // Resume any non-done streams — BUT only if the session is recent (< 5 min)
  // and has actual recent activity. Stale sessions from yesterday must NOT
  // auto-reconnect (was the cause of the “Connected to learning stream…” spam).
  Object.keys(sessions).forEach(function (nb) {
    var s = sessions[nb];
    if (!s || s.done) return;
    var lastLog = (s.logs && s.logs.length) ? s.logs[s.logs.length - 1].ts : 0;
    var ageMin  = (Date.now() - lastLog) / 60000;
    if (lastLog === 0 || ageMin > 5) {
      // Mark as done so it stops haunting next page-load too.
      s.done = true;
      persist();
      return;
    }
    startLearningStream(nb);
  });
})();
