/**
 * BizCity Research Studio — vanilla JS port of Tavily Chat UI.
 *
 * Mounts on any element with id="bizcity-research-studio-root" reading
 *   data-scope-type ("character" | "user")
 *   data-scope-id   (int)
 *
 * Layout:
 *  ┌── sidebar: sessions ──┐ ┌── main ─────────────────────────────┐
 *  │ + new                 │ │ mode toggle · query input           │
 *  │ • session A           │ │─────────────────────────────────────│
 *  │ • session B           │ │ Thinking Timeline                   │
 *  │                       │ │  ✓ Planning · ⟳ Searching · …       │
 *  │                       │ │ ──────                              │
 *  │                       │ │ Markdown report (typing animation)  │
 *  │                       │ │ ──────                              │
 *  │                       │ │ Sources (N)  [☐] favicon · title    │
 *  │                       │ │ [+ Add N to Knowledge] (sticky)     │
 *  └───────────────────────┘ └─────────────────────────────────────┘
 *
 * Streaming protocol = NDJSON (1 JSON event per \n). See PHP
 * BizCity_Research_Agent::run() for the event taxonomy.
 */
(function () {
    'use strict';

    var CFG = window.BIZCITY_RESEARCH || {};
    var I18N = CFG.i18n || {};
    var TOOL_COLORS = {
        search:  { dot: '#3b82f6', bg: '#eff6ff', text: '#1d4ed8' },
        extract: { dot: '#ef4444', bg: '#fef2f2', text: '#b91c1c' },
        crawl:   { dot: '#eab308', bg: '#fefce8', text: '#a16207' }
    };

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (k === 'class') n.className = attrs[k];
                else if (k === 'html') n.innerHTML = attrs[k];
                else if (k === 'text') n.textContent = attrs[k];
                else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
                else n.setAttribute(k, attrs[k]);
            }
        }
        if (children) {
            children.forEach(function (c) {
                if (c == null) return;
                n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
            });
        }
        return n;
    }
    function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]; }); }
    function fav(url, fallback) {
        if (url) return url;
        try { var u = new URL(fallback); return 'https://www.google.com/s2/favicons?domain=' + u.hostname + '&sz=64'; } catch(e) { return ''; }
    }
    function domain(u) { try { return new URL(u).hostname.replace(/^www\./,''); } catch(e) { return u; } }

    /* ────────────── Tiny markdown renderer (subset: h1-4, p, ul, ol, code, blockquote, table, links, bold, italic) ────────────── */
    function md(src) {
        if (!src) return '';
        var lines = src.split('\n');
        var out = [];
        var i = 0, inUl = false, inOl = false, inCode = false, inTable = false, tableHead = false;

        function closeLists() {
            if (inUl) { out.push('</ul>'); inUl = false; }
            if (inOl) { out.push('</ol>'); inOl = false; }
        }

        function inlineMd(s) {
            s = esc(s);
            s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
            s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
            return s;
        }

        for (; i < lines.length; i++) {
            var line = lines[i];

            // fenced code
            if (/^```/.test(line)) {
                if (!inCode) { closeLists(); out.push('<pre><code>'); inCode = true; }
                else { out.push('</code></pre>'); inCode = false; }
                continue;
            }
            if (inCode) { out.push(esc(line) + '\n'); continue; }

            // tables (gfm-ish)
            if (/^\s*\|.+\|\s*$/.test(line)) {
                if (!inTable) { closeLists(); out.push('<table class="bcr-table"><thead>'); inTable = true; tableHead = true; }
                var cells = line.trim().slice(1, -1).split('|').map(function (c) { return c.trim(); });
                if (tableHead && /^\s*\|?\s*[:\-]+\s*\|/.test(lines[i+1] || '')) {
                    out.push('<tr>' + cells.map(function (c) { return '<th>' + inlineMd(c) + '</th>'; }).join('') + '</tr></thead><tbody>');
                    i++;
                    tableHead = false;
                } else {
                    out.push('<tr>' + cells.map(function (c) { return '<td>' + inlineMd(c) + '</td>'; }).join('') + '</tr>');
                }
                continue;
            } else if (inTable) {
                out.push('</tbody></table>');
                inTable = false;
            }

            // headings
            var h = line.match(/^(#{1,4})\s+(.+)$/);
            if (h) { closeLists(); out.push('<h' + h[1].length + '>' + inlineMd(h[2]) + '</h' + h[1].length + '>'); continue; }

            // blockquote
            if (/^>\s?/.test(line)) { closeLists(); out.push('<blockquote>' + inlineMd(line.replace(/^>\s?/, '')) + '</blockquote>'); continue; }

            // ordered list
            if (/^\s*\d+\.\s+/.test(line)) {
                if (!inOl) { closeLists(); out.push('<ol>'); inOl = true; }
                out.push('<li>' + inlineMd(line.replace(/^\s*\d+\.\s+/, '')) + '</li>');
                continue;
            }
            // unordered list
            if (/^\s*[-*]\s+/.test(line)) {
                if (!inUl) { closeLists(); out.push('<ul>'); inUl = true; }
                out.push('<li>' + inlineMd(line.replace(/^\s*[-*]\s+/, '')) + '</li>');
                continue;
            }

            // paragraph / blank
            closeLists();
            if (line.trim() === '') out.push('');
            else out.push('<p>' + inlineMd(line) + '</p>');
        }
        closeLists();
        if (inCode)  out.push('</code></pre>');
        if (inTable) out.push('</tbody></table>');
        return out.join('\n');
    }

    /* ────────────── REST client ────────────── */
    function api(path, opts) {
        opts = opts || {};
        var url = CFG.restBase + path;
        var headers = { 'X-WP-Nonce': CFG.nonce };
        if (opts.body) headers['Content-Type'] = 'application/json';
        return fetch(url, {
            method: opts.method || 'GET',
            headers: headers,
            credentials: 'include',
            body: opts.body ? JSON.stringify(opts.body) : undefined
        }).then(function (r) {
            if (!r.ok) return r.json().then(function (e) { throw e; });
            return r.json();
        });
    }

    /* ────────────── Studio class ────────────── */
    function Studio(root) {
        this.root       = root;
        this.scopeType  = root.dataset.scopeType || 'user';
        this.scopeId    = parseInt(root.dataset.scopeId || '0', 10);
        this.sessions   = [];
        this.currentSid = null;
        this.turns      = [];
        this.activeTurn = null; // live turn state
        this.mode       = 'deep';
        this.mount();
        this.loadSessions();
    }

    Studio.prototype.mount = function () {
        clear(this.root);
        this.root.classList.add('bcr-studio');

        // Sidebar
        this.sidebar = el('aside', { class: 'bcr-sidebar' });
        this.sidebarHeader = el('div', { class: 'bcr-side-head' }, [
            el('strong', { text: '🔬 Research Projects' }),
            el('button', { type: 'button', class: 'button button-primary bcr-new-btn', onclick: this.createSession.bind(this) }, [
                document.createTextNode('+ ' + (I18N.newSession || 'New'))
            ])
        ]);
        this.sessionsList = el('div', { class: 'bcr-sessions' });
        this.sidebar.appendChild(this.sidebarHeader);
        this.sidebar.appendChild(this.sessionsList);

        // Main
        this.main      = el('section', { class: 'bcr-main' });
        this.mainEmpty = el('div', { class: 'bcr-empty', text: I18N.noSessions || 'Chưa có dự án.' });
        this.main.appendChild(this.mainEmpty);

        this.root.appendChild(this.sidebar);
        this.root.appendChild(this.main);
    };

    Studio.prototype.loadSessions = function () {
        var self = this;
        api('/sessions?scope_type=' + this.scopeType + '&scope_id=' + this.scopeId)
            .then(function (r) {
                self.sessions = r.items || [];
                self.renderSessions();
                if (self.sessions.length && !self.currentSid) self.openSession(self.sessions[0].id);
            })
            .catch(function (e) { console.warn('[research] loadSessions', e); });
    };

    Studio.prototype.renderSessions = function () {
        var self = this;
        clear(this.sessionsList);
        if (!this.sessions.length) {
            this.sessionsList.appendChild(el('div', { class: 'bcr-side-empty', text: I18N.noSessions || '—' }));
            return;
        }
        this.sessions.forEach(function (s) {
            var item = el('div', {
                class: 'bcr-session-item' + (s.id === self.currentSid ? ' is-active' : ''),
                onclick: function () { self.openSession(s.id); }
            }, [
                el('div', { class: 'bcr-session-title', text: s.title }),
                el('div', { class: 'bcr-session-meta', text: (s.total_turns || 0) + ' lượt · ' + (s.total_ingested || 0) + ' nguồn' })
            ]);
            self.sessionsList.appendChild(item);
        });
    };

    Studio.prototype.createSession = function () {
        var self = this;
        var title = window.prompt(I18N.newSession || 'Tên dự án mới:', 'Nghiên cứu ' + new Date().toLocaleDateString('vi-VN'));
        if (!title) return;
        api('/sessions', {
            method: 'POST',
            body: { scope_type: this.scopeType, scope_id: this.scopeId, title: title, agent_mode: this.mode }
        }).then(function (s) {
            self.sessions.unshift(s);
            self.renderSessions();
            self.openSession(s.id);
        }).catch(function (e) { alert('Lỗi: ' + (e.message || JSON.stringify(e))); });
    };

    Studio.prototype.openSession = function (sid) {
        var self = this;
        this.currentSid = sid;
        this.renderSessions();
        api('/sessions/' + sid).then(function (r) {
            self.turns = r.turns || [];
            self.renderMain(r.session);
        });
    };

    Studio.prototype.renderMain = function (session) {
        clear(this.main);
        this.main.classList.remove('bcr-main-empty');

        // Header bar
        var modeWrap = el('div', { class: 'bcr-mode-wrap' });
        ['fast', 'deep'].forEach(function (m) {
            var b = el('button', {
                type: 'button',
                class: 'bcr-mode-btn' + (this.mode === m ? ' is-active' : ''),
                onclick: (function (mm) { return function () { this.mode = mm; this.renderMain(session); }.bind(this); }.bind(this))(m)
            }, [ document.createTextNode(I18N[m] || m) ]);
            modeWrap.appendChild(b);
        }.bind(this));

        var head = el('div', { class: 'bcr-main-head' }, [
            el('h2', { class: 'bcr-session-h', text: session.title }),
            modeWrap
        ]);

        // Composer
        this.input = el('textarea', { class: 'bcr-input', placeholder: I18N.placeholder || 'Hỏi gì cũng được…', rows: '2' });
        this.sendBtn = el('button', { type: 'button', class: 'button button-primary bcr-send', onclick: this.send.bind(this) }, [ document.createTextNode(I18N.send || 'Send') ]);
        var composer = el('div', { class: 'bcr-composer' }, [ this.input, this.sendBtn ]);

        // Turns container
        this.turnsHost = el('div', { class: 'bcr-turns' });

        this.main.appendChild(head);
        this.main.appendChild(composer);
        this.main.appendChild(this.turnsHost);

        // Render existing turns
        this.turns.forEach(this.renderTurnPersisted.bind(this));
        this.scrollDown();
    };

    Studio.prototype.scrollDown = function () {
        var self = this;
        setTimeout(function () { self.main.scrollTop = self.main.scrollHeight; }, 30);
    };

    Studio.prototype.send = function () {
        if (!this.currentSid || !this.input) return;
        var q = this.input.value.trim();
        if (!q) return;
        this.input.value = '';
        var self = this;
        this.sendBtn.disabled = true;

        api('/sessions/' + this.currentSid + '/chat', {
            method: 'POST',
            body: { query: q, mode: this.mode }
        }).then(function (r) {
            self.startStream(r.turn_id, q, r.stream_url);
        }).catch(function (e) {
            self.sendBtn.disabled = false;
            alert('Lỗi: ' + (e.message || JSON.stringify(e)));
        });
    };

    /* ────────────── Live turn rendering ────────────── */

    Studio.prototype.startStream = function (turnId, query, url) {
        var t = {
            turnId: turnId,
            query:  query,
            phases: { planning: { status: 'pending', label: I18N.planning || 'Planning' },
                      searching: { status: 'pending', label: I18N.searching || 'Searching' },
                      generating:{ status: 'pending', label: I18N.generating|| 'Generating Report' } },
            ops: [],
            recap: '',
            displayed: '',
            sources: {},
            selected: {},
            done: false,
            error: null
        };
        this.activeTurn = t;
        var dom = this.buildTurnDom(t);
        this.turnsHost.appendChild(dom.wrapper);
        t.dom = dom;
        this.scrollDown();
        this.startTyping(t);
        this.streamNDJSON(url, t);
    };

    Studio.prototype.streamNDJSON = function (url, t) {
        var self = this;
        fetch(url, { credentials: 'include', headers: { 'X-WP-Nonce': CFG.nonce } }).then(function (resp) {
            if (!resp.body || !resp.body.getReader) {
                throw new Error('Streaming not supported');
            }
            var reader = resp.body.getReader();
            var decoder = new TextDecoder();
            var buf = '';
            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        if (buf.trim()) { self.handleEvent(JSON.parse(buf), t); }
                        self.finishTurn(t);
                        return;
                    }
                    buf += decoder.decode(chunk.value, { stream: true });
                    var lines = buf.split('\n');
                    buf = lines.pop() || '';
                    lines.forEach(function (ln) {
                        if (!ln.trim()) return;
                        try { self.handleEvent(JSON.parse(ln), t); } catch (e) { console.warn('[research] bad ndjson', ln, e); }
                    });
                    return pump();
                });
            }
            return pump();
        }).catch(function (err) {
            t.error = err.message || String(err);
            self.finishTurn(t);
        });
    };

    Studio.prototype.handleEvent = function (ev, t) {
        if (ev.type === 'research_phase') {
            if (t.phases[ev.phase]) {
                t.phases[ev.phase].status = ev.status;
                if (ev.duration_ms) t.phases[ev.phase].duration_ms = ev.duration_ms;
                if (ev.label) t.phases[ev.phase].label = ev.label;
            }
            this.renderPhases(t);
            return;
        }
        if (ev.type === 'tool_start') {
            t.ops.push({
                type: ev.tool_type, index: ev.operation_index, data: ev.content,
                status: 'active', results: null
            });
            this.renderOps(t);
            this.scrollDown();
            return;
        }
        if (ev.type === 'tool_end') {
            for (var i = t.ops.length - 1; i >= 0; i--) {
                var op = t.ops[i];
                if (op.status === 'active' && op.type === ev.tool_type) {
                    op.status  = 'complete';
                    try { op.results = JSON.parse(ev.content); } catch (e) { op.results = ev.content; }
                    op.duration_ms = ev.duration_ms;
                    // merge into source list
                    var items = (op.results && (op.results.items || op.results.results)) || [];
                    items.forEach(function (it) {
                        if (it && it.url && !t.sources[it.url]) {
                            t.sources[it.url] = {
                                url: it.url, title: it.title || it.url, favicon: it.favicon || '',
                                origin: ev.tool_type
                            };
                        }
                    });
                    break;
                }
            }
            this.renderOps(t);
            this.renderSources(t);
            return;
        }
        if (ev.type === 'chatbot') {
            t.recap += ev.content || '';
            return;
        }
        if (ev.type === 'research_done') {
            t.done = true;
            return;
        }
        if (ev.type === 'error') {
            t.error = ev.message || 'unknown';
            return;
        }
    };

    Studio.prototype.startTyping = function (t) {
        var self = this;
        function tick() {
            if (!t.dom) return;
            if (t.recap.length > t.displayed.length) {
                var remaining = t.recap.length - t.displayed.length;
                var chunk = Math.max(1, Math.floor(remaining / 20));
                t.displayed = t.recap.slice(0, t.displayed.length + chunk);
                t.dom.report.innerHTML = md(t.displayed);
                self.scrollDown();
            }
            if (!t.done || t.recap.length > t.displayed.length) {
                setTimeout(tick, 50);
            } else {
                t.dom.report.innerHTML = md(t.recap);
            }
        }
        setTimeout(tick, 50);
    };

    Studio.prototype.finishTurn = function (t) {
        this.sendBtn && (this.sendBtn.disabled = false);
        if (t.error) {
            t.dom.report.innerHTML = '<div class="notice notice-error"><p>' + esc(t.error) + '</p></div>';
        }
        this.renderPhases(t); // mark all complete
        this.renderSources(t);
        // refresh sidebar counts
        this.loadSessions();
    };

    /* ────────────── Turn DOM ────────────── */

    Studio.prototype.buildTurnDom = function (t) {
        var wrapper  = el('div', { class: 'bcr-turn' });
        var query    = el('div', { class: 'bcr-turn-query' }, [
            el('span', { class: 'bcr-q-icon', text: '❓' }),
            el('span', { class: 'bcr-q-text', text: t.query })
        ]);
        var timeline = el('div', { class: 'bcr-timeline' });
        var ops      = el('div', { class: 'bcr-ops' });
        var report   = el('div', { class: 'bcr-report' });
        var sources  = el('div', { class: 'bcr-sources' });
        var ingest   = el('div', { class: 'bcr-ingest-bar' });

        wrapper.appendChild(query);
        wrapper.appendChild(timeline);
        wrapper.appendChild(ops);
        wrapper.appendChild(report);
        wrapper.appendChild(sources);
        wrapper.appendChild(ingest);

        var dom = { wrapper: wrapper, timeline: timeline, ops: ops, report: report, sources: sources, ingest: ingest };
        this.renderPhasesInto(t, dom.timeline);
        return dom;
    };

    Studio.prototype.renderPhases = function (t) { this.renderPhasesInto(t, t.dom.timeline); };

    Studio.prototype.renderPhasesInto = function (t, host) {
        clear(host);
        ['planning', 'searching', 'generating'].forEach(function (key) {
            var p = t.phases[key];
            var icon = p.status === 'complete' ? '✓' : p.status === 'active' ? '⟳' : '○';
            var cls  = 'bcr-phase bcr-phase-' + p.status;
            var dur  = p.duration_ms ? ' · ' + (p.duration_ms / 1000).toFixed(1) + 's' : '';
            host.appendChild(el('div', { class: cls }, [
                el('span', { class: 'bcr-phase-icon', text: icon }),
                el('span', { class: 'bcr-phase-label', text: p.label + dur })
            ]));
        });
    };

    Studio.prototype.renderOps = function (t) {
        clear(t.dom.ops);
        t.ops.forEach(function (op, idx) {
            var color = TOOL_COLORS[op.type] || TOOL_COLORS.search;
            var icon  = op.status === 'complete' ? '✓' : '⟳';
            var label = ({ search: 'Search', extract: 'Extract', crawl: 'Crawl' })[op.type] || op.type;
            var btn = el('button', {
                type: 'button',
                class: 'bcr-op-btn bcr-op-' + op.type + ' bcr-op-' + op.status,
                style: 'background:' + color.bg + ';color:' + color.text + ';border-color:' + color.dot
            }, [
                el('span', { class: 'bcr-op-dot', style: 'background:' + color.dot }),
                document.createTextNode(' ' + label + ' ' + icon)
            ]);
            var detail = el('div', { class: 'bcr-op-detail', style: 'display:none' });
            btn.addEventListener('click', function () {
                detail.style.display = (detail.style.display === 'none') ? 'block' : 'none';
                if (detail.style.display === 'block') renderOpDetail(op, detail);
            });
            t.dom.ops.appendChild(btn);
            t.dom.ops.appendChild(detail);
        });
    };

    function renderOpDetail(op, host) {
        clear(host);
        // Params
        var params = el('div', { class: 'bcr-op-params' });
        params.appendChild(el('div', { class: 'bcr-op-params-h', text: 'Params' }));
        var pre = el('pre', { class: 'bcr-op-pre', text: JSON.stringify(op.data, null, 2) });
        params.appendChild(pre);
        host.appendChild(params);
        // Results
        if (op.results) {
            var res = el('div', { class: 'bcr-op-results' });
            res.appendChild(el('div', { class: 'bcr-op-results-h', text: 'Results' + (op.duration_ms ? ' · ' + (op.duration_ms/1000).toFixed(1) + 's' : '') }));
            var items = op.results.items || op.results.results || [];
            var list = el('ol', { class: 'bcr-op-list' });
            items.forEach(function (it) {
                var li = el('li', null, [
                    el('img', { class: 'bcr-fav', src: fav(it.favicon, it.url), alt: '' }),
                    el('a', { href: it.url, target: '_blank', rel: 'noopener', text: it.title || it.url }),
                    el('span', { class: 'bcr-op-list-domain', text: ' · ' + domain(it.url) })
                ]);
                list.appendChild(li);
            });
            res.appendChild(list);
            if (op.results.summary) {
                res.appendChild(el('div', { class: 'bcr-op-summary', html: md(op.results.summary) }));
            }
            host.appendChild(res);
        }
    }

    Studio.prototype.renderSources = function (t) {
        clear(t.dom.sources);
        var urls = Object.keys(t.sources);
        if (!urls.length) return;
        var head = el('div', { class: 'bcr-sources-head', text: (I18N.sources || 'Nguồn') + ' (' + urls.length + ')' });
        t.dom.sources.appendChild(head);

        var self = this;
        var grid = el('div', { class: 'bcr-sources-grid' });
        urls.forEach(function (u) {
            var s = t.sources[u];
            var color = TOOL_COLORS[s.origin] || TOOL_COLORS.search;
            var checked = !!t.selected[u];
            var cb = el('input', { type: 'checkbox' });
            cb.checked = checked;
            cb.addEventListener('change', function () {
                if (cb.checked) t.selected[u] = true; else delete t.selected[u];
                self.renderIngestBar(t);
            });
            var card = el('label', { class: 'bcr-src-card' }, [
                cb,
                el('img', { class: 'bcr-fav', src: fav(s.favicon, s.url), alt: '' }),
                el('div', { class: 'bcr-src-meta' }, [
                    el('div', { class: 'bcr-src-title', text: s.title }),
                    el('div', { class: 'bcr-src-domain', text: domain(s.url) })
                ]),
                el('span', { class: 'bcr-src-origin', style: 'background:' + color.bg + ';color:' + color.text, text: s.origin })
            ]);
            grid.appendChild(card);
        });
        t.dom.sources.appendChild(grid);

        // "Select all" helper
        var actionRow = el('div', { class: 'bcr-src-actions' }, [
            el('button', { type: 'button', class: 'button-link', onclick: function () {
                urls.forEach(function (u) { t.selected[u] = true; });
                self.renderSources(t);
            }, text: 'Chọn tất cả' }),
            el('button', { type: 'button', class: 'button-link', onclick: function () {
                t.selected = {}; self.renderSources(t);
            }, text: 'Bỏ chọn' })
        ]);
        t.dom.sources.appendChild(actionRow);

        this.renderIngestBar(t);
    };

    Studio.prototype.renderIngestBar = function (t) {
        clear(t.dom.ingest);
        var n = Object.keys(t.selected).length;
        if (n === 0) return;
        var self = this;
        var btn = el('button', {
            type: 'button',
            class: 'button button-primary button-large',
            onclick: function () { self.ingest(t); }
        }, [ document.createTextNode('➕ ' + (I18N.addToKnowledge || 'Add') + ' ' + n + ' → ' + (self.scopeType === 'character' ? 'Twin Guru L2' : 'Personal Knowledge')) ]);
        t.dom.ingest.appendChild(el('div', { class: 'bcr-ingest-inner' }, [
            el('span', { text: n + ' nguồn đã chọn' }),
            btn
        ]));
    };

    Studio.prototype.ingest = function (t) {
        var self = this;
        var urls = Object.keys(t.selected);
        var bar = t.dom.ingest;
        bar.innerHTML = '<span class="spinner is-active" style="float:none;"></span> Đang ingest…';
        api('/turns/' + t.turnId + '/ingest', { method: 'POST', body: { urls: urls } })
            .then(function (r) {
                bar.innerHTML = '<div class="notice notice-success inline"><p>✓ ' +
                    'Đã ingest ' + r.ingested + ' / trùng ' + r.duplicate + ' / lỗi ' + r.failed + '</p></div>';
                t.selected = {};
                self.loadSessions();
            })
            .catch(function (e) {
                bar.innerHTML = '<div class="notice notice-error inline"><p>Lỗi: ' + esc(e.message || JSON.stringify(e)) + '</p></div>';
            });
    };

    /* ────────────── Persisted turn render (history) ────────────── */
    Studio.prototype.renderTurnPersisted = function (turn) {
        var t = {
            turnId: turn.id,
            query:  turn.user_query,
            phases: {
                planning:   { status: 'complete', label: I18N.planning   || 'Planning',         duration_ms: 0 },
                searching:  { status: 'complete', label: I18N.searching  || 'Searching',        duration_ms: turn.duration_ms },
                generating: { status: 'complete', label: I18N.generating || 'Generating Report' }
            },
            ops: [],
            recap: turn.agent_answer_md || '',
            displayed: turn.agent_answer_md || '',
            sources: {},
            selected: {},
            done: true
        };
        // synthesize ops from reasoning_trace
        (turn.reasoning_trace || []).forEach(function (st, i) {
            t.ops.push({
                type: st.tool_type, index: i, data: st.input,
                status: 'complete', duration_ms: st.duration_ms,
                results: { items: (st.urls || []).map(function (u) { return { url: u, title: u, favicon: '' }; }), urls: st.urls || [] }
            });
        });
        (turn.source_urls || []).forEach(function (s) {
            if (s && s.url) t.sources[s.url] = s;
        });

        var dom = this.buildTurnDom(t);
        t.dom = dom;
        this.turnsHost.appendChild(dom.wrapper);
        dom.report.innerHTML = md(t.displayed);
        this.renderOps(t);
        this.renderSources(t);
    };

    /* ────────────── Bootstrap ────────────── */
    function boot() {
        var roots = document.querySelectorAll('#bizcity-research-studio-root');
        roots.forEach(function (r) {
            if (r.dataset._mounted) return;
            r.dataset._mounted = '1';
            new Studio(r);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    // Also re-boot when the character-edit tab is switched in (in case the
    // element appears later via the JS injector).
    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('[data-tab="research"]')) {
            setTimeout(boot, 30);
        }
    });
})();
