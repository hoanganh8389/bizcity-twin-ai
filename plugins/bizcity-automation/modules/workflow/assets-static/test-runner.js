/**
 * AJAX Test Runner for Workflow Development Mode
 * 
 * This module provides real-time feedback for workflow testing in admin.
 * Production still uses WP-Cron, but admin test mode uses AJAX polling for instant feedback.
 * 
 * Usage:
 * ```js
 * window.WaicTestRunner.startTestRun(taskId, testData, {
 *   onStatusUpdate: (data) => console.log('Progress:', data),
 *   onComplete: (data) => console.log('Success!', data),
 *   onError: (data) => console.error('Failed:', data)
 * });
 * ```
 */

(function($) {
    'use strict';

    const WaicTestRunner = {
        pollInterval: null,
        pollTimer: null,
        runId: null,
        maxPollTime: 5 * 60 * 1000, // 5 minutes timeout
        pollDelay: 2000, // Poll every 2 seconds
        callbacks: {},

        /**
         * Start a test workflow run with AJAX polling
         * 
         * @param {number} taskId - Workflow task ID
         * @param {object} testData - Test trigger data (optional)
         * @param {object} callbacks - Callback functions
         * @param {function} callbacks.onStatusUpdate - Called on each poll (data: {status, message, logs})
         * @param {function} callbacks.onComplete - Called when workflow completes (data: {status, logs})
         * @param {function} callbacks.onError - Called on error (data: {status, message, logs})
         */
        startTestRun: function(taskId, testData, callbacks) {
            this.callbacks = callbacks || {};
            
            console.log('[WaicTestRunner] Starting test run for task:', taskId);
            console.log('[WaicTestRunner] AJAX URL:', ajaxurl);
            console.log('[WaicTestRunner] Nonce:', window.WAIC_DATA?.waicNonce);
            
            // Clear any existing poll
            this.stopPolling();

            // Create test run via AJAX
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'waic_test_workflow_run',
                    nonce: window.WAIC_DATA?.waicNonce || '',
                    task_id: taskId,
                    test_data: JSON.stringify(testData || {})
                },
                success: (response) => {
                    console.log('[WaicTestRunner] AJAX response:', response);
                    
                    if (response.success && response.data?.run_id) {
                        this.runId = response.data.run_id;
                        this.updateStatus(1, 'Workflow started...', [], response.data.run_id);
                        this.startPolling();
                    } else {
                        console.error('[WaicTestRunner] Failed to start:', response);
                        this.updateStatus(7, 'Failed to start: ' + (response.data?.message || 'Unknown error'), []);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[WaicTestRunner] AJAX error:', xhr, status, error);
                    this.updateStatus(7, 'AJAX error: ' + error, []);
                }
            });
        },

        /**
         * Start polling for workflow status
         */
        startPolling: function() {
            const startTime = Date.now();
            
            this.pollInterval = setInterval(() => {
                // Check timeout
                if (Date.now() - startTime > this.maxPollTime) {
                    this.stopPolling();
                    this.updateStatus(7, 'Timeout: Workflow took longer than 5 minutes', []);
                    return;
                }

                this.pollStatus();
            }, this.pollDelay);
        },

        /**
         * Poll workflow status
         */
        pollStatus: function() {
            if (!this.runId) return;

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'waic_poll_workflow_status',
                    nonce: window.WAIC_DATA?.waicNonce || '',
                    run_id: this.runId
                },
                success: (response) => {
                    if (response.success && response.data) {
                        const status = parseInt(response.data.status);
                        const message = response.data.message || '';
                        const logs = response.data.logs || [];

                        // Update UI
                        this.updateStatus(status, message, logs);

                        // Check if completed
                        if (status === 3) {
                            // Completed successfully
                            this.stopPolling();
                            if (this.callbacks.onComplete) {
                                this.callbacks.onComplete({ status, logs });
                            }
                        } else if (status === 6 || status === 7) {
                            // Stopped or error
                            this.stopPolling();
                            if (this.callbacks.onError) {
                                this.callbacks.onError({ status, message, logs });
                            }
                        }
                    } else {
                        this.stopPolling();
                        this.updateStatus(7, 'Poll failed: ' + (response.data?.message || 'Unknown error'), []);
                    }
                },
                error: (xhr, status, error) => {
                    this.stopPolling();
                    this.updateStatus(7, 'Poll AJAX error: ' + error, []);
                }
            });
        },

        /**
         * Stop polling
         */
        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
            this.runId = null;
        },

        /**
         * Update status and trigger callbacks
         * 
         * @param {number} status - Workflow status (1=processing, 3=completed, 6=stopped, 7=error)
         * @param {string} message - Status message
         * @param {array} logs - Execution logs
         * @param {number} runId - Run ID (optional)
         */
        updateStatus: function(status, message, logs, runId) {
            const data = { 
                status, 
                message, 
                logs,
                run_id: runId || this.runId
            };

            // Trigger status update callback
            if (this.callbacks.onStatusUpdate) {
                this.callbacks.onStatusUpdate(data);
            }

            // Log to console
            console.log('[WaicTestRunner]', message, { status, logs, run_id: data.run_id });
        }
    };

    // Export to global scope
    window.WaicTestRunner = WaicTestRunner;

})(jQuery);
