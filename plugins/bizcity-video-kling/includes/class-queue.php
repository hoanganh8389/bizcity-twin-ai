<?php
/**
 * Video Queue Dashboard with Real-time Logging
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Queue {
    
    /**
     * Debug mode
     */
    private static $debug = false;
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 20 );
        add_action( 'wp_ajax_bizcity_kling_queue_refresh', array( __CLASS__, 'ajax_refresh_queue' ) );
        add_action( 'wp_ajax_bizcity_kling_queue_poll', array( __CLASS__, 'ajax_poll_jobs' ) );
        add_action( 'wp_ajax_bizcity_kling_queue_logs', array( __CLASS__, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_bizcity_kling_queue_cancel', array( __CLASS__, 'ajax_cancel_job' ) );
        add_action( 'wp_ajax_bizcity_kling_toggle_debug', array( __CLASS__, 'ajax_toggle_debug' ) );
    }
    
    /**
     * Add admin submenu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'bizcity-kling',
            __( 'Queue Dashboard', 'bizcity-video-kling' ),
            __( '📊 Queue', 'bizcity-video-kling' ),
            'manage_options',
            'bizcity-kling-queue',
            array( __CLASS__, 'render_page' )
        );
    }
    
    /**
     * Add log entry for a job
     * 
     * @param int    $job_id  Job ID
     * @param string $message Log message
     * @param string $level   Log level: info, success, warning, error
     */
    public static function add_log( $job_id, $message, $level = 'info' ) {
        $log_key = 'bizcity_kling_logs_' . $job_id;
        $logs = get_transient( $log_key );
        
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }
        
        $logs[] = array(
            'time'      => current_time( 'H:i:s' ),
            'timestamp' => time(),
            'message'   => $message,
            'level'     => $level,
        );
        
        // Keep only last 200 logs
        if ( count( $logs ) > 200 ) {
            $logs = array_slice( $logs, -200 );
        }
        
        // Store for 2 hours
        set_transient( $log_key, $logs, 2 * HOUR_IN_SECONDS );
        
        // Also log to error_log if debug enabled
        if ( self::is_debug() ) {
            error_log( sprintf( '[BizCity-Kling][Job#%d][%s] %s', $job_id, strtoupper( $level ), $message ) );
        }
        
        return true;
    }
    
    /**
     * Get logs for a job
     */
    public static function get_logs( $job_id, $since_timestamp = 0 ) {
        $log_key = 'bizcity_kling_logs_' . $job_id;
        $logs = get_transient( $log_key );
        
        if ( ! is_array( $logs ) ) {
            return array();
        }
        
        if ( $since_timestamp > 0 ) {
            $logs = array_filter( $logs, function( $log ) use ( $since_timestamp ) {
                return $log['timestamp'] > $since_timestamp;
            } );
        }
        
        return array_values( $logs );
    }
    
    /**
     * Clear logs for a job
     */
    public static function clear_logs( $job_id ) {
        delete_transient( 'bizcity_kling_logs_' . $job_id );
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function is_debug() {
        return get_option( 'bizcity_kling_queue_debug', false );
    }
    
    /**
     * Render Queue Dashboard page
     */
    public static function render_page() {
        global $wpdb;
        $table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        
        // Get statistics
        $stats = $wpdb->get_row( "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        " );
        
        // Get active jobs (queued + processing)
        $active_jobs = $wpdb->get_results( "
            SELECT * FROM {$table}
            WHERE status IN ('queued', 'processing')
            ORDER BY created_at DESC
            LIMIT 50
        " );
        
        // Get recent completed jobs
        $recent_jobs = $wpdb->get_results( "
            SELECT * FROM {$table}
            WHERE status IN ('completed', 'failed')
            ORDER BY updated_at DESC
            LIMIT 20
        " );
        
        $nonce = wp_create_nonce( 'bizcity_kling_queue_nonce' );
        $is_debug = self::is_debug();
        ?>
        <div class="wrap bizcity-kling-wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0;">
                    <span class="dashicons dashicons-list-view" style="font-size: 28px; margin-right: 8px;"></span>
                    <?php _e( 'Video Queue Dashboard', 'bizcity-video-kling' ); ?>
                </h1>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" id="auto-poll" checked>
                        <?php _e( 'Auto-poll (5s)', 'bizcity-video-kling' ); ?>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: <?php echo $is_debug ? '#10b981' : '#666'; ?>;">
                        <input type="checkbox" id="debug-mode" <?php checked( $is_debug ); ?>>
                        🐛 <?php _e( 'Debug', 'bizcity-video-kling' ); ?>
                    </label>
                    <button type="button" class="button" id="refresh-queue-btn">
                        ↻ <?php _e( 'Refresh', 'bizcity-video-kling' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="poll-all-btn">
                        🔄 <?php _e( 'Poll All', 'bizcity-video-kling' ); ?>
                    </button>
                </div>
            </div>
            
            <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'monitor' ); ?>
            
            <!-- Statistics Cards -->
            <div class="bizcity-kling-stats" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 20px;">
                <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <span class="stat-number"><?php echo (int) ( $stats->total ?? 0 ); ?></span>
                    <span class="stat-label"><?php _e( 'Total (24h)', 'bizcity-video-kling' ); ?></span>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <span class="stat-number" id="stat-queued"><?php echo (int) ( $stats->queued ?? 0 ); ?></span>
                    <span class="stat-label"><?php _e( 'Queued', 'bizcity-video-kling' ); ?></span>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <span class="stat-number" id="stat-processing"><?php echo (int) ( $stats->processing ?? 0 ); ?></span>
                    <span class="stat-label"><?php _e( 'Processing', 'bizcity-video-kling' ); ?></span>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <span class="stat-number" id="stat-completed"><?php echo (int) ( $stats->completed ?? 0 ); ?></span>
                    <span class="stat-label"><?php _e( 'Completed', 'bizcity-video-kling' ); ?></span>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <span class="stat-number" id="stat-failed"><?php echo (int) ( $stats->failed ?? 0 ); ?></span>
                    <span class="stat-label"><?php _e( 'Failed', 'bizcity-video-kling' ); ?></span>
                </div>
            </div>
            
            <!-- Real-time Log Console -->
            <div class="bizcity-kling-card" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;">
                        <span class="dashicons dashicons-editor-code"></span>
                        <?php _e( 'Real-time Log Console', 'bizcity-video-kling' ); ?>
                    </h3>
                    <div>
                        <select id="log-job-select" style="min-width: 200px;">
                            <option value="all"><?php _e( '-- All Active Jobs --', 'bizcity-video-kling' ); ?></option>
                            <?php foreach ( $active_jobs as $job ): ?>
                                <option value="<?php echo $job->id; ?>">
                                    #<?php echo $job->id; ?> - <?php echo esc_html( wp_trim_words( $job->prompt, 5, '...' ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-small" id="clear-logs-btn">
                            🗑️ <?php _e( 'Clear', 'bizcity-video-kling' ); ?>
                        </button>
                    </div>
                </div>
                <div id="log-console" class="log-console">
                    <div class="log-line log-info">[<?php echo current_time( 'H:i:s' ); ?>] <?php _e( 'Queue Dashboard ready. Waiting for jobs...', 'bizcity-video-kling' ); ?></div>
                </div>
            </div>
            
            <!-- Active Jobs Table -->
            <div class="bizcity-kling-card" style="margin-bottom: 20px;">
                <h3 style="margin: 0 0 15px 0;">
                    <span class="dashicons dashicons-clock"></span>
                    <?php _e( 'Active Jobs (Queued & Processing)', 'bizcity-video-kling' ); ?>
                    <span class="count" id="active-count">(<?php echo count( $active_jobs ); ?>)</span>
                </h3>
                
                <div id="active-jobs-table">
                    <?php if ( empty( $active_jobs ) ): ?>
                        <div class="empty-state">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <p><?php _e( 'No active jobs. All clear!', 'bizcity-video-kling' ); ?></p>
                        </div>
                    <?php else: ?>
                        <?php self::render_jobs_table( $active_jobs, true ); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Completed Jobs -->
            <div class="bizcity-kling-card">
                <h3 style="margin: 0 0 15px 0;">
                    <span class="dashicons dashicons-backup"></span>
                    <?php _e( 'Recent Completed Jobs', 'bizcity-video-kling' ); ?>
                </h3>
                
                <div id="recent-jobs-table">
                    <?php if ( empty( $recent_jobs ) ): ?>
                        <div class="empty-state">
                            <span class="dashicons dashicons-info"></span>
                            <p><?php _e( 'No recent jobs in the last 24 hours.', 'bizcity-video-kling' ); ?></p>
                        </div>
                    <?php else: ?>
                        <?php self::render_jobs_table( $recent_jobs, false ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <style>
        .bizcity-kling-stats .stat-card {
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card .stat-number {
            display: block;
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-card .stat-label {
            font-size: 13px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Log Console */
        .log-console {
            background: #1e1e1e;
            color: #00ff00;
            padding: 16px;
            border-radius: 8px;
            height: 250px;
            overflow-y: auto;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
        }
        .log-line {
            margin: 2px 0;
            padding: 2px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .log-info { color: #00bcd4; }
        .log-success { color: #4caf50; }
        .log-warning { color: #ff9800; }
        .log-error { color: #f44336; }
        .log-debug { color: #9c27b0; }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state .dashicons {
            font-size: 48px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        /* Progress bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #1d4ed8);
            transition: width 0.3s ease;
        }
        .progress-fill.completed { background: linear-gradient(90deg, #10b981, #059669); }
        .progress-fill.failed { background: linear-gradient(90deg, #ef4444, #dc2626); }
        
        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-queued { background: #fef3c7; color: #92400e; }
        .status-processing { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        
        /* Job actions */
        .job-actions button {
            margin-right: 5px;
        }
        
        /* Chain badge */
        .chain-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: #dbeafe;
            color: #2563eb;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo $nonce; ?>';
            var pollInterval = null;
            var lastLogTimestamp = 0;
            var selectedJobId = 'all';
            
            // Log to console
            function addLog(message, level) {
                level = level || 'info';
                var time = new Date().toLocaleTimeString('en-GB');
                var $console = $('#log-console');
                var $line = $('<div class="log-line log-' + level + '">[' + time + '] ' + message + '</div>');
                $console.append($line);
                $console.scrollTop($console[0].scrollHeight);
            }
            
            // Poll active jobs
            function pollActiveJobs() {
                if (!$('#auto-poll').is(':checked')) return;
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_queue_poll',
                        nonce: nonce,
                        since_timestamp: lastLogTimestamp
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            
                            // Update stats
                            if (data.stats) {
                                $('#stat-queued').text(data.stats.queued || 0);
                                $('#stat-processing').text(data.stats.processing || 0);
                                $('#stat-completed').text(data.stats.completed || 0);
                                $('#stat-failed').text(data.stats.failed || 0);
                            }
                            
                            // Append new logs
                            if (data.logs && data.logs.length > 0) {
                                data.logs.forEach(function(log) {
                                    if (selectedJobId === 'all' || log.job_id == selectedJobId) {
                                        var prefix = log.job_id ? '[Job#' + log.job_id + '] ' : '';
                                        addLog(prefix + log.message, log.level);
                                    }
                                });
                                lastLogTimestamp = data.last_timestamp || Date.now() / 1000;
                            }
                            
                            // Update job rows if status changed
                            if (data.jobs) {
                                data.jobs.forEach(function(job) {
                                    updateJobRow(job);
                                });
                            }
                            
                            // Reload if jobs completed
                            if (data.reload_needed) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            }
                        }
                    },
                    error: function() {
                        addLog('<?php _e( 'Poll request failed', 'bizcity-video-kling' ); ?>', 'error');
                    }
                });
            }
            
            // Update job row
            function updateJobRow(job) {
                var $row = $('#job-row-' + job.id);
                if (!$row.length) return;
                
                $row.find('.status-badge')
                    .removeClass('status-queued status-processing status-completed status-failed')
                    .addClass('status-' + job.status)
                    .text(job.status);
                
                $row.find('.progress-fill')
                    .removeClass('completed failed')
                    .addClass(job.status === 'completed' ? 'completed' : (job.status === 'failed' ? 'failed' : ''))
                    .css('width', job.progress + '%');
                
                $row.find('.progress-text').text(job.progress + '%');
            }
            
            // Start auto-polling
            function startPolling() {
                if (pollInterval) return;
                pollInterval = setInterval(pollActiveJobs, 5000);
                addLog('<?php _e( 'Auto-polling started (every 5s)', 'bizcity-video-kling' ); ?>', 'info');
            }
            
            // Stop auto-polling
            function stopPolling() {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    addLog('<?php _e( 'Auto-polling stopped', 'bizcity-video-kling' ); ?>', 'warning');
                }
            }
            
            // Toggle auto-poll
            $('#auto-poll').on('change', function() {
                if ($(this).is(':checked')) {
                    startPolling();
                } else {
                    stopPolling();
                }
            });
            
            // Toggle debug mode
            $('#debug-mode').on('change', function() {
                var enabled = $(this).is(':checked');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_toggle_debug',
                        nonce: nonce,
                        enabled: enabled ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            addLog(enabled ? '<?php _e( 'Debug mode ENABLED', 'bizcity-video-kling' ); ?>' : '<?php _e( 'Debug mode DISABLED', 'bizcity-video-kling' ); ?>', enabled ? 'debug' : 'info');
                            $('#debug-mode').parent().css('color', enabled ? '#10b981' : '#666');
                        }
                    }
                });
            });
            
            // Log job select
            $('#log-job-select').on('change', function() {
                selectedJobId = $(this).val();
                addLog('<?php _e( 'Filtering logs for:', 'bizcity-video-kling' ); ?> ' + (selectedJobId === 'all' ? '<?php _e( 'All Jobs', 'bizcity-video-kling' ); ?>' : 'Job #' + selectedJobId), 'info');
            });
            
            // Clear logs
            $('#clear-logs-btn').on('click', function() {
                $('#log-console').html('<div class="log-line log-info">[' + new Date().toLocaleTimeString('en-GB') + '] <?php _e( 'Logs cleared', 'bizcity-video-kling' ); ?></div>');
            });
            
            // Refresh button
            $('#refresh-queue-btn').on('click', function() {
                location.reload();
            });
            
            // Poll All button
            $('#poll-all-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e( 'Polling...', 'bizcity-video-kling' ); ?>');
                
                addLog('<?php _e( 'Manual poll started...', 'bizcity-video-kling' ); ?>', 'info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_queue_poll',
                        nonce: nonce,
                        force: 1,
                        since_timestamp: 0
                    },
                    success: function(response) {
                        if (response.success) {
                            addLog('<?php _e( 'Poll completed successfully', 'bizcity-video-kling' ); ?>', 'success');
                            
                            if (response.data.logs) {
                                response.data.logs.forEach(function(log) {
                                    var prefix = log.job_id ? '[Job#' + log.job_id + '] ' : '';
                                    addLog(prefix + log.message, log.level);
                                });
                            }
                            
                            // Reload to show updated data
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            addLog('<?php _e( 'Poll failed:', 'bizcity-video-kling' ); ?> ' + (response.data.message || 'Unknown error'), 'error');
                        }
                    },
                    error: function() {
                        addLog('<?php _e( 'Poll request failed', 'bizcity-video-kling' ); ?>', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('🔄 <?php _e( 'Poll All', 'bizcity-video-kling' ); ?>');
                    }
                });
            });
            
            // Cancel job
            $(document).on('click', '.cancel-job-btn', function() {
                var jobId = $(this).data('job-id');
                
                if (!confirm('<?php _e( 'Cancel this job?', 'bizcity-video-kling' ); ?>')) return;
                
                addLog('<?php _e( 'Cancelling job #', 'bizcity-video-kling' ); ?>' + jobId + '...', 'warning');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_queue_cancel',
                        nonce: nonce,
                        job_id: jobId
                    },
                    success: function(response) {
                        if (response.success) {
                            addLog('<?php _e( 'Job #', 'bizcity-video-kling' ); ?>' + jobId + ' <?php _e( 'cancelled', 'bizcity-video-kling' ); ?>', 'success');
                            location.reload();
                        } else {
                            addLog('<?php _e( 'Failed to cancel:', 'bizcity-video-kling' ); ?> ' + response.data.message, 'error');
                        }
                    }
                });
            });
            
            // View logs for specific job
            $(document).on('click', '.view-job-logs-btn', function() {
                var jobId = $(this).data('job-id');
                $('#log-job-select').val(jobId).trigger('change');
                
                // Fetch logs for this job
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_queue_logs',
                        nonce: nonce,
                        job_id: jobId
                    },
                    success: function(response) {
                        if (response.success && response.data.logs) {
                            response.data.logs.forEach(function(log) {
                                addLog(log.message, log.level);
                            });
                        }
                    }
                });
            });
            
            // Start polling on load if there are active jobs
            <?php if ( ! empty( $active_jobs ) ): ?>
                startPolling();
            <?php endif; ?>
        });
        </script>
        <?php
    }
    
    /**
     * Render jobs table
     */
    private static function render_jobs_table( $jobs, $show_actions = false ) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 200px;"><?php _e( 'Prompt', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Chain', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Duration', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Status', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 120px;"><?php _e( 'Progress', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Created', 'bizcity-video-kling' ); ?></th>
                    <?php if ( $show_actions ): ?>
                    <th style="width: 150px;"><?php _e( 'Actions', 'bizcity-video-kling' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $jobs as $job ): 
                    $has_chain = ! empty( $job->chain_id ) && $job->total_segments > 1;
                    $time_ago = human_time_diff( strtotime( $job->created_at ), current_time( 'timestamp' ) );
                ?>
                    <tr id="job-row-<?php echo $job->id; ?>">
                        <td><strong>#<?php echo $job->id; ?></strong></td>
                        <td>
                            <div title="<?php echo esc_attr( $job->prompt ); ?>" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo esc_html( wp_trim_words( $job->prompt, 8, '...' ) ); ?>
                            </div>
                        </td>
                        <td>
                            <?php if ( $has_chain ): ?>
                                <span class="chain-badge">
                                    <?php echo $job->segment_index; ?>/<?php echo $job->total_segments; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $job->duration; ?>s</td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr( $job->status ); ?>">
                                <?php echo esc_html( $job->status ); ?>
                            </span>
                        </td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $job->status === 'completed' ? 'completed' : ( $job->status === 'failed' ? 'failed' : '' ); ?>" style="width: <?php echo (int) $job->progress; ?>%"></div>
                            </div>
                            <small class="progress-text"><?php echo (int) $job->progress; ?>%</small>
                        </td>
                        <td>
                            <span title="<?php echo esc_attr( $job->created_at ); ?>">
                                <?php printf( __( '%s ago', 'bizcity-video-kling' ), $time_ago ); ?>
                            </span>
                        </td>
                        <?php if ( $show_actions ): ?>
                        <td class="job-actions">
                            <button type="button" class="button button-small view-job-logs-btn" data-job-id="<?php echo $job->id; ?>" title="<?php _e( 'View Logs', 'bizcity-video-kling' ); ?>">
                                📋
                            </button>
                            <?php if ( in_array( $job->status, array( 'queued', 'processing' ) ) ): ?>
                                <button type="button" class="button button-small cancel-job-btn" data-job-id="<?php echo $job->id; ?>" title="<?php _e( 'Cancel', 'bizcity-video-kling' ); ?>">
                                    ❌
                                </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * AJAX: Refresh queue data
     */
    public static function ajax_refresh_queue() {
        check_ajax_referer( 'bizcity_kling_queue_nonce', 'nonce' );
        wp_send_json_success( array( 'message' => __( 'Refreshed', 'bizcity-video-kling' ) ) );
    }
    
    /**
     * AJAX: Poll active jobs and get new logs
     */
    public static function ajax_poll_jobs() {
        check_ajax_referer( 'bizcity_kling_queue_nonce', 'nonce' );
        
        global $wpdb;
        $table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $since_timestamp = intval( $_POST['since_timestamp'] ?? 0 );
        $force = ! empty( $_POST['force'] );
        
        // Get current stats
        $stats = $wpdb->get_row( "
            SELECT 
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        " );
        
        // Get active jobs
        $active_jobs = $wpdb->get_results( "
            SELECT id, task_id, status, progress FROM {$table}
            WHERE status IN ('queued', 'processing')
            AND task_id IS NOT NULL
            AND task_id != ''
            ORDER BY created_at ASC
            LIMIT 20
        " );
        
        $logs = array();
        $jobs_data = array();
        $reload_needed = false;
        
        // Check status for each active job
        if ( ! empty( $active_jobs ) && ( $force || count( $active_jobs ) > 0 ) ) {
            $api_key = get_option( 'bizcity_video_kling_api_key', '' );
            $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
            $settings = array( 'api_key' => $api_key, 'endpoint' => $endpoint );
            
            foreach ( $active_jobs as $job ) {
                // Check status with API
                $result = waic_kling_get_task( $settings, $job->task_id );
                
                if ( $result['ok'] ) {
                    $api_status = waic_kling_extract_status( $result['data'] );
                    $api_progress = waic_kling_extract_progress( $result['data'] );
                    $video_url = waic_kling_extract_video_url( $result['data'] );
                    
                    // Map API status to local status
                    $new_status = $job->status;
                    if ( in_array( $api_status, array( 'completed', 'succeed' ) ) ) {
                        $new_status = 'completed';
                    } elseif ( in_array( $api_status, array( 'failed', 'error' ) ) ) {
                        $new_status = 'failed';
                    } elseif ( in_array( $api_status, array( 'processing', 'running' ) ) ) {
                        $new_status = 'processing';
                    }
                    
                    // Update if changed
                    if ( $new_status !== $job->status || $api_progress != $job->progress ) {
                        $update_data = array(
                            'status' => $new_status,
                            'progress' => (int) $api_progress,
                            'updated_at' => current_time( 'mysql' ),
                        );
                        
                        if ( $video_url ) {
                            $update_data['video_url'] = $video_url;
                        }
                        
                        BizCity_Video_Kling_Database::update_job( $job->id, $update_data );
                        
                        // Add log
                        $log_message = sprintf( 
                            __( 'Status: %s → %s | Progress: %d%%', 'bizcity-video-kling' ),
                            $job->status,
                            $new_status,
                            $api_progress
                        );
                        self::add_log( $job->id, $log_message, $new_status === 'completed' ? 'success' : ( $new_status === 'failed' ? 'error' : 'info' ) );
                        
                        $logs[] = array(
                            'job_id'    => $job->id,
                            'message'   => $log_message,
                            'level'     => $new_status === 'completed' ? 'success' : ( $new_status === 'failed' ? 'error' : 'info' ),
                            'timestamp' => time(),
                        );
                        
                        if ( $new_status === 'completed' || $new_status === 'failed' ) {
                            $reload_needed = true;
                        }
                    }
                    
                    $jobs_data[] = array(
                        'id'       => $job->id,
                        'status'   => $new_status,
                        'progress' => (int) $api_progress,
                    );
                } else {
                    // API error
                    $logs[] = array(
                        'job_id'    => $job->id,
                        'message'   => __( 'API check failed: ', 'bizcity-video-kling' ) . ( $result['error'] ?? 'Unknown' ),
                        'level'     => 'warning',
                        'timestamp' => time(),
                    );
                }
            }
        }
        
        wp_send_json_success( array(
            'stats'          => $stats,
            'jobs'           => $jobs_data,
            'logs'           => $logs,
            'last_timestamp' => time(),
            'reload_needed'  => $reload_needed,
        ) );
    }
    
    /**
     * AJAX: Get logs for a specific job
     */
    public static function ajax_get_logs() {
        check_ajax_referer( 'bizcity_kling_queue_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $logs = self::get_logs( $job_id, 0 );
        
        wp_send_json_success( array(
            'logs'           => $logs,
            'last_timestamp' => time(),
        ) );
    }
    
    /**
     * AJAX: Cancel a job
     */
    public static function ajax_cancel_job() {
        check_ajax_referer( 'bizcity_kling_queue_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found', 'bizcity-video-kling' ) ) );
            return;
        }
        
        if ( ! in_array( $job->status, array( 'queued', 'processing' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Job cannot be cancelled', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Update status to failed
        BizCity_Video_Kling_Database::update_job( $job_id, array(
            'status'        => 'failed',
            'error_message' => __( 'Cancelled by user', 'bizcity-video-kling' ),
            'updated_at'    => current_time( 'mysql' ),
        ) );
        
        self::add_log( $job_id, __( 'Job cancelled by user', 'bizcity-video-kling' ), 'warning' );
        
        wp_send_json_success( array( 'message' => __( 'Job cancelled', 'bizcity-video-kling' ) ) );
    }
    
    /**
     * AJAX: Toggle debug mode
     */
    public static function ajax_toggle_debug() {
        check_ajax_referer( 'bizcity_kling_queue_nonce', 'nonce' );
        
        $enabled = ! empty( $_POST['enabled'] );
        update_option( 'bizcity_kling_queue_debug', $enabled );
        
        wp_send_json_success( array( 'enabled' => $enabled ) );
    }
}
