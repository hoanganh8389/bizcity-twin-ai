/**
 * BizCity Pipeline Working Panel — Step-by-Step Execution UI for Chat
 *
 * Manages the .bizc-working execution panel that appears in chat when
 * the user confirms a pipeline plan. Polls admin-ajax for step status,
 * displays real-time progress, and handles HIL interactions.
 *
 * Integration:
 *   - Renders inside chat message area as a working card
 *   - Uses admin-ajax endpoints from BizCity_Step_Executor
 *   - Mirrors patterns from execute-api.php + test-runner.js
 *
 * @since 4.2.0
 */
(function ($) {
    'use strict';

    const BizcPipelinePanel = {

        /** @type {string} Current execution ID */
        executionId: null,

        /** @type {number} Poll interval handle */
        pollHandle: null,

        /** Poll config */
        pollDelay: 2000,       // 2 seconds
        maxPollTime: 10 * 60 * 1000, // 10 minutes
        pollStartTime: 0,

        /** @type {object} Callbacks (optional) */
        callbacks: {},

        /**
         * Start a pipeline execution from a confirmed plan.
         *
         * @param {number} taskId       - bizcity_tasks row ID
         * @param {Element} container   - DOM element to render into
         * @param {object} callbacks    - { onStepUpdate, onComplete, onError }
         */
        start: function (taskId, container, callbacks) {
            this.callbacks = callbacks || {};
            this.stopPolling();

            // Render initial UI
            this.renderPanel(container, {
                status: 'starting',
                steps: [],
                logs: [],
            });

            // Call AJAX start
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizc_pipeline_start',
                    nonce: window.BIZC_PIPELINE?.nonce || '',
                    task_id: taskId,
                },
                success: (response) => {
                    if (response.success && response.data?.execution_id) {
                        this.executionId = response.data.execution_id;
                        this.updatePanel(container, response.data);
                        this.startPolling(container);
                    } else {
                        this.updatePanel(container, {
                            status: 'failed',
                            steps: [],
                            logs: ['Error: ' + (response.data?.message || 'Unknown')],
                        });
                    }
                },
                error: (xhr, status, error) => {
                    this.updatePanel(container, {
                        status: 'failed',
                        steps: [],
                        logs: ['AJAX error: ' + error],
                    });
                },
            });
        },

        /**
         * Start polling loop.
         */
        startPolling: function (container) {
            this.pollStartTime = Date.now();
            this.pollHandle = setInterval(() => {
                if (Date.now() - this.pollStartTime > this.maxPollTime) {
                    this.stopPolling();
                    this.updatePanel(container, {
                        status: 'failed',
                        steps: [],
                        logs: ['Timeout: Pipeline exceeded 10 minute limit.'],
                    });
                    return;
                }
                this.poll(container);
            }, this.pollDelay);
        },

        stopPolling: function () {
            if (this.pollHandle) {
                clearInterval(this.pollHandle);
                this.pollHandle = null;
            }
        },

        /**
         * Poll current execution status.
         */
        poll: function (container) {
            if (!this.executionId) return;

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizc_pipeline_poll',
                    nonce: window.BIZC_PIPELINE?.nonce || '',
                    execution_id: this.executionId,
                },
                success: (response) => {
                    if (!response.success) return;

                    const data = response.data;
                    this.updatePanel(container, data);

                    // Terminal states
                    if (['completed', 'failed', 'cancelled'].includes(data.status)) {
                        this.stopPolling();
                        if (data.status === 'completed' && this.callbacks.onComplete) {
                            this.callbacks.onComplete(data);
                        } else if (this.callbacks.onError) {
                            this.callbacks.onError(data);
                        }
                    }

                    // Waiting state — stop polling until user acts
                    if (data.status === 'waiting') {
                        this.stopPolling();
                    }

                    if (this.callbacks.onStepUpdate) {
                        this.callbacks.onStepUpdate(data);
                    }
                },
            });
        },

        /**
         * Send a step action (resume, retry, skip, cancel).
         */
        stepAction: function (container, stepIndex, action, inputData) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizc_pipeline_step_action',
                    nonce: window.BIZC_PIPELINE?.nonce || '',
                    execution_id: this.executionId,
                    step_index: stepIndex,
                    action: action,
                    input_data: JSON.stringify(inputData || {}),
                },
                success: (response) => {
                    if (!response.success) return;

                    this.updatePanel(container, response.data);

                    // Resume polling if back to running
                    if (response.data.status === 'running') {
                        this.startPolling(container);
                    }
                },
            });
        },

        // ── Rendering ──

        /**
         * Render the working panel into the container.
         */
        renderPanel: function (container, data) {
            const $el = $(container);
            $el.html(this.buildPanelHTML(data));
            $el.attr('data-bizc-working', '1');
            this.bindActions($el);
        },

        /**
         * Update the panel content without full re-render.
         */
        updatePanel: function (container, data) {
            const $el = $(container);
            $el.html(this.buildPanelHTML(data));
            this.bindActions($el);
        },

        /**
         * Build the panel HTML.
         */
        buildPanelHTML: function (data) {
            const status = data.status || 'starting';
            const steps = data.steps || [];
            const logs = data.logs || [];

            const statusIcons = {
                starting: '⏳',
                running: '⚡',
                waiting: '⏸️',
                completed: '✅',
                failed: '❌',
                cancelled: '🚫',
            };

            const statusLabels = {
                starting: 'Đang khởi tạo...',
                running: 'Đang thực hiện...',
                waiting: 'Chờ phản hồi',
                completed: 'Hoàn thành!',
                failed: 'Lỗi',
                cancelled: 'Đã hủy',
            };

            let html = '<div class="bizc-working-panel bizc-working-' + status + '">';

            // Header
            html += '<div class="bizc-working-header">';
            html += '<span class="bizc-working-icon">' + (statusIcons[status] || '▶️') + '</span>';
            html += '<span class="bizc-working-title">' + (statusLabels[status] || status) + '</span>';

            // Progress bar
            const completed = steps.filter(s => s.status === 'completed' || s.status === 'skipped').length;
            const total = steps.length || 1;
            const pct = Math.round((completed / total) * 100);
            html += '<span class="bizc-working-progress">' + completed + '/' + total + '</span>';
            html += '</div>';

            // Progress bar visual
            html += '<div class="bizc-working-bar"><div class="bizc-working-bar-fill" style="width:' + pct + '%"></div></div>';

            // Steps
            if (steps.length > 0) {
                html += '<div class="bizc-working-steps">';
                steps.forEach((step) => {
                    const stepIcons = {
                        pending: '⬜',
                        running: '🔄',
                        waiting: '⏸️',
                        completed: '✅',
                        failed: '❌',
                        skipped: '⏭️',
                    };

                    html += '<div class="bizc-step bizc-step-' + step.status + '" data-step="' + step.step_index + '">';
                    html += '<span class="bizc-step-icon">' + (stepIcons[step.status] || '▶️') + '</span>';
                    html += '<span class="bizc-step-name">' + this.escapeHtml(step.label || step.tool) + '</span>';

                    // Result preview for completed steps
                    if (step.status === 'completed' && step.result_preview) {
                        html += '<div class="bizc-step-result">' + this.escapeHtml(step.result_preview) + '</div>';
                    }

                    // Error message
                    if (step.error && (step.status === 'failed' || step.status === 'waiting')) {
                        html += '<div class="bizc-step-error">' + this.escapeHtml(step.error) + '</div>';
                    }

                    // Action buttons for failed/waiting steps
                    if (step.status === 'failed') {
                        html += '<div class="bizc-step-actions">';
                        html += '<button class="bizc-btn bizc-btn-retry" data-action="retry" data-step="' + step.step_index + '">🔄 Thử lại</button>';
                        html += '<button class="bizc-btn bizc-btn-skip" data-action="skip" data-step="' + step.step_index + '">⏭️ Bỏ qua</button>';
                        html += '<button class="bizc-btn bizc-btn-cancel" data-action="cancel" data-step="' + step.step_index + '">🚫 Hủy</button>';
                        html += '</div>';
                    }

                    // Missing fields input for waiting HIL steps
                    if (step.status === 'waiting' && step.missing_fields && step.missing_fields.length > 0) {
                        html += '<div class="bizc-step-hil">';
                        html += '<p class="bizc-hil-label">Vui lòng bổ sung:</p>';
                        step.missing_fields.forEach((field) => {
                            html += '<div class="bizc-hil-field">';
                            html += '<label>' + this.escapeHtml(field) + '</label>';
                            html += '<input type="text" class="bizc-hil-input" data-field="' + this.escapeHtml(field) + '" placeholder="Nhập ' + this.escapeHtml(field) + '..." />';
                            html += '</div>';
                        });
                        html += '<button class="bizc-btn bizc-btn-resume" data-action="resume" data-step="' + step.step_index + '">▶️ Tiếp tục</button>';
                        html += '<button class="bizc-btn bizc-btn-skip" data-action="skip" data-step="' + step.step_index + '">⏭️ Bỏ qua</button>';
                        html += '</div>';
                    }

                    html += '</div>';
                });
                html += '</div>';
            }

            // Logs (collapsed by default for clean UI)
            if (logs.length > 0) {
                html += '<details class="bizc-working-logs">';
                html += '<summary>📋 Logs (' + logs.length + ')</summary>';
                html += '<div class="bizc-logs-content">';
                logs.forEach((log) => {
                    html += '<div class="bizc-log-line">' + this.escapeHtml(log) + '</div>';
                });
                html += '</div></details>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Bind action button events.
         */
        bindActions: function ($el) {
            const self = this;
            const container = $el[0];

            $el.find('.bizc-btn[data-action]').off('click').on('click', function () {
                const action = $(this).data('action');
                const stepIndex = $(this).data('step');

                let inputData = {};

                // Collect HIL input fields for resume
                if (action === 'resume') {
                    const $stepEl = $(this).closest('.bizc-step');
                    $stepEl.find('.bizc-hil-input').each(function () {
                        const field = $(this).data('field');
                        const val = $(this).val().trim();
                        if (val) {
                            inputData[field] = val;
                        }
                    });
                }

                self.stepAction(container, stepIndex, action, inputData);
            });
        },

        escapeHtml: function (text) {
            if (!text) return '';
            const el = document.createElement('span');
            el.textContent = String(text);
            return el.innerHTML;
        },
    };

    // Expose globally
    window.BizcPipelinePanel = BizcPipelinePanel;

})(jQuery);
