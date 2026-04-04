/**
 * BizCity Pipeline Monitor Sidebar — SSE-based Real-Time Pipeline Visualization
 *
 * Renders a persistent sidebar next to admin chat showing pipeline progress.
 * Uses Server-Sent Events (SSE) for real-time updates instead of polling.
 *
 * Integration:
 *   - Activated when chat response contains pipeline_id
 *   - Mounts into #bc-pipeline-sidebar container
 *   - Receives events via admin-ajax SSE endpoint
 *
 * @since Phase 1.2 — Pipeline Visualization Sidebar (v2.4)
 */
(function ($) {
    'use strict';

    class BizCityPipelineMonitor {

        /**
         * @param {HTMLElement} container - Sidebar DOM element
         * @param {string} pipelineId - Pipeline execution ID
         * @param {object} [options] - { ajaxUrl, nonce }
         */
        constructor(container, pipelineId, options = {}) {
            this.container = container;
            this.pid = pipelineId;
            this.nodes = {};
            this.logs = [];
            this.eventSource = null;
            this.ajaxUrl = options.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
            this.nonce = options.nonce || window.BIZC_PIPELINE_MONITOR?.nonce || '';
            this.status = 'connecting';
            this.startTime = Date.now();
        }

        /**
         * Initialize the sidebar with pipeline node structure and start SSE.
         *
         * @param {Array} nodes - Initial node list [{node_id, label, tool, type, status}]
         */
        init(nodes) {
            // Build initial node map
            nodes.forEach(n => {
                this.nodes[n.node_id] = {
                    ...n,
                    status: n.status || 'pending',
                    duration_ms: 0,
                    output_preview: '',
                    error_message: '',
                    skill_used: '',
                    progress: 0,
                };
            });

            this.render();
            this.start();
        }

        /**
         * Connect to the SSE event stream.
         */
        start() {
            const url = this.ajaxUrl
                + '?action=bizc_pipeline_sse'
                + '&pid=' + encodeURIComponent(this.pid)
                + '&_wpnonce=' + encodeURIComponent(this.nonce);

            this.eventSource = new EventSource(url);
            this.status = 'connected';

            this.eventSource.onmessage = (e) => {
                try {
                    const evt = JSON.parse(e.data);
                    this.handleEvent(evt);
                } catch (err) {
                    // Ignore parse errors
                }
            };

            this.eventSource.addEventListener('done', () => {
                this.status = 'done';
                this.eventSource.close();
                this.updateHeader();
            });

            this.eventSource.onerror = () => {
                // EventSource auto-reconnects; mark status
                if (this.eventSource.readyState === EventSource.CLOSED) {
                    this.status = 'disconnected';
                    this.updateHeader();
                }
            };
        }

        /**
         * Handle a single SSE event.
         */
        handleEvent(evt) {
            const nodeId = evt.node_id;
            const node = this.nodes[nodeId];

            if (node) {
                // Update node state
                if (evt.event) node.status = evt.event;
                if (evt.duration_ms) node.duration_ms = evt.duration_ms;
                if (evt.output_preview) node.output_preview = evt.output_preview;
                if (evt.error_message) node.error_message = evt.error_message;
                if (evt.skill_used) node.skill_used = evt.skill_used;
                if (typeof evt.progress === 'number') node.progress = evt.progress;

                this.updateNodeCard(nodeId);
            }

            // Append console log
            if (evt.log_line) {
                this.appendLog(evt.timestamp || (Date.now() / 1000), evt.log_line);
            }

            // Update header stats
            this.updateHeader();
        }

        /**
         * Full render of the sidebar.
         */
        render() {
            const ordered = Object.values(this.nodes).sort((a, b) =>
                parseInt(a.node_id) - parseInt(b.node_id)
            );

            let html = '<div class="bc-pipeline-monitor">';

            // Header
            html += '<div class="bc-pm-header">';
            html += '<div class="bc-pm-title">📊 Pipeline Monitor</div>';
            html += '<div class="bc-pm-status" id="bc-pm-status">' + this._statusBadge() + '</div>';
            html += '<button class="bc-pm-close" title="Đóng">&times;</button>';
            html += '</div>';

            // Nodes
            html += '<div class="bc-pm-nodes">';
            ordered.forEach(n => {
                html += this._nodeCardHTML(n);
            });
            html += '</div>';

            // Console log
            html += '<div class="bc-pm-console">';
            html += '<div class="bc-pm-console-header">Console</div>';
            html += '<div class="bc-pm-console-log" id="bc-pm-console-log"></div>';
            html += '</div>';

            html += '</div>';

            this.container.innerHTML = html;

            // Bind close
            this.container.querySelector('.bc-pm-close')?.addEventListener('click', () => {
                this.stop();
                this.container.classList.remove('active');
            });
        }

        /**
         * Update a single node card in place.
         */
        updateNodeCard(nodeId) {
            const node = this.nodes[nodeId];
            if (!node) return;

            const el = this.container.querySelector(`.bc-pipeline-node[data-node-id="${nodeId}"]`);
            if (!el) return;

            el.dataset.status = node.status;
            el.outerHTML = this._nodeCardHTML(node);
        }

        /**
         * Update header status badge.
         */
        updateHeader() {
            const el = this.container.querySelector('#bc-pm-status');
            if (el) el.innerHTML = this._statusBadge();
        }

        /**
         * Append a line to the console log.
         */
        appendLog(ts, line) {
            const logEl = this.container.querySelector('#bc-pm-console-log');
            if (!logEl) return;

            const time = new Date(ts * 1000).toLocaleTimeString('vi-VN');
            const div = document.createElement('div');
            div.className = 'bc-console-line';
            div.innerHTML = '<span class="bc-log-time">' + this._esc(time) + '</span>'
                + '<span class="bc-log-text">' + this._esc(line) + '</span>';
            logEl.appendChild(div);
            logEl.scrollTop = logEl.scrollHeight;

            this.logs.push({ ts, line });
        }

        /**
         * Stop the SSE connection.
         */
        stop() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
        }

        // ── Private helpers ──

        _nodeCardHTML(node) {
            const icons = {
                pending: '⬜', started: '🔄', running: '🔄',
                completed: '✅', failed: '❌', waiting: '⏸️',
            };
            const st = node.status || 'pending';

            let html = '<div class="bc-pipeline-node" data-node-id="' + this._esc(node.node_id) + '" data-status="' + st + '">';

            // Header row
            html += '<div class="bc-node-header">';
            html += '<span class="bc-node-icon">' + (icons[st] || '▶️') + '</span>';
            html += '<span class="bc-node-title">' + this._esc(node.label || node.tool || 'Node ' + node.node_id) + '</span>';
            if (node.duration_ms > 0) {
                html += '<span class="bc-node-duration">' + (node.duration_ms / 1000).toFixed(1) + 's</span>';
            }
            html += '</div>';

            // Body
            html += '<div class="bc-node-body">';

            // Running: progress bar + skill
            if (st === 'running' || st === 'started') {
                if (node.progress > 0) {
                    html += '<div class="bc-progress-track"><div class="bc-progress-bar" style="width:' + Math.round(node.progress * 100) + '%"></div></div>';
                }
                if (node.skill_used) {
                    html += '<div class="bc-node-skill">Skill: ' + this._esc(node.skill_used) + '</div>';
                }
            }

            // Completed: output preview
            if (st === 'completed' && node.output_preview) {
                html += '<div class="bc-node-preview">' + this._esc(node.output_preview) + '</div>';
            }

            // Failed: error
            if (st === 'failed' && node.error_message) {
                html += '<div class="bc-node-error">' + this._esc(node.error_message) + '</div>';
            }

            // Waiting: HIL note
            if (st === 'waiting') {
                html += '<div class="bc-node-waiting">Chờ xác nhận nội dung...</div>';
            }

            html += '</div></div>';
            return html;
        }

        _statusBadge() {
            const all = Object.values(this.nodes);
            const completed = all.filter(n => n.status === 'completed').length;
            const running = all.filter(n => n.status === 'running' || n.status === 'started').length;
            const failed = all.filter(n => n.status === 'failed').length;
            const total = all.length;

            if (failed > 0) return '<span class="bc-badge bc-badge-red">❌ ' + failed + ' lỗi</span>';
            if (running > 0) return '<span class="bc-badge bc-badge-blue">▶ ' + completed + '/' + total + '</span>';
            if (completed === total && total > 0) return '<span class="bc-badge bc-badge-green">✅ Hoàn tất</span>';
            return '<span class="bc-badge bc-badge-gray">⏳ ' + completed + '/' + total + '</span>';
        }

        _esc(text) {
            if (!text) return '';
            const el = document.createElement('span');
            el.textContent = String(text);
            return el.innerHTML;
        }
    }

    // Expose globally
    window.BizCityPipelineMonitor = BizCityPipelineMonitor;

})(jQuery);
