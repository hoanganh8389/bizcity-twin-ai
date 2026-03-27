<?php
/**
 * View: Job Monitor page
 * 
 * Variables available:
 * - $jobs: array of job objects
 * - $stats: stats object with job counts
 * - $nonce: security nonce
 * 
 * @package BizCity_Video_Kling
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap bizcity-kling-wrap">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h1 style="margin: 0;"><?php _e( 'Video Jobs Monitor', 'bizcity-video-kling' ); ?></h1>
        <div class="header-actions">
            <button type="button" class="button button-primary" id="monitor-all-btn" title="<?php _e( 'Check status of all processing jobs', 'bizcity-video-kling' ); ?>">
                🔄 <?php _e( 'Monitor All', 'bizcity-video-kling' ); ?>
            </button>
            <button type="button" class="button" id="refresh-page-btn" title="<?php _e( 'Refresh page', 'bizcity-video-kling' ); ?>">
                ↻ <?php _e( 'Refresh', 'bizcity-video-kling' ); ?>
            </button>
        </div>
    </div>
    
    <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'monitor' ); ?>
    
    <!-- Stats Cards -->
    <div class="bizcity-kling-stats">
        <div class="stat-card">
            <span class="stat-number"><?php echo (int) ( $stats->total_jobs ?? 0 ); ?></span>
            <span class="stat-label"><?php _e( 'Total Jobs', 'bizcity-video-kling' ); ?></span>
        </div>
        <div class="stat-card stat-queued">
            <span class="stat-number"><?php echo (int) ( $stats->queued_jobs ?? 0 ); ?></span>
            <span class="stat-label"><?php _e( 'Queued', 'bizcity-video-kling' ); ?></span>
        </div>
        <div class="stat-card stat-processing">
            <span class="stat-number"><?php echo (int) ( $stats->processing_jobs ?? 0 ); ?></span>
            <span class="stat-label"><?php _e( 'Processing', 'bizcity-video-kling' ); ?></span>
        </div>
        <div class="stat-card stat-completed">
            <span class="stat-number"><?php echo (int) ( $stats->completed_jobs ?? 0 ); ?></span>
            <span class="stat-label"><?php _e( 'Completed', 'bizcity-video-kling' ); ?></span>
        </div>
        <div class="stat-card stat-failed">
            <span class="stat-number"><?php echo (int) ( $stats->failed_jobs ?? 0 ); ?></span>
            <span class="stat-label"><?php _e( 'Failed', 'bizcity-video-kling' ); ?></span>
        </div>
    </div>
    
    <!-- Jobs Table -->
    <div class="bizcity-kling-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;"><?php _e( 'Recent Jobs', 'bizcity-video-kling' ); ?></h3>
            <label>
                <input type="checkbox" id="auto-refresh" checked>
                <?php _e( 'Auto-refresh (5s)', 'bizcity-video-kling' ); ?>
            </label>
        </div>
        <table class="wp-list-table widefat fixed striped" id="jobs-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 180px;"><?php _e( 'Prompt', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Chain', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Duration', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Status', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 120px;"><?php _e( 'Progress', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Created', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 200px;"><?php _e( 'Actions', 'bizcity-video-kling' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $jobs ) ): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <?php _e( 'No jobs yet. Create a script to generate videos.', 'bizcity-video-kling' ); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $jobs as $job ): 
                        $has_chain = ! empty( $job->chain_id ) && $job->total_segments > 1;
                        $is_final = $has_chain && $job->is_final;
                        $chain_class = $has_chain ? 'chain-job' : '';
                        if ( $is_final ) $chain_class .= ' chain-final';
                    ?>
                        <tr id="job-row-<?php echo $job->id; ?>" 
                            data-job-id="<?php echo $job->id; ?>" 
                            data-status="<?php echo esc_attr( $job->status ); ?>"
                            <?php if ( $has_chain ): ?>data-chain-id="<?php echo esc_attr( $job->chain_id ); ?>"<?php endif; ?>
                            class="<?php echo $chain_class; ?>">
                            <td><?php echo $job->id; ?></td>
                            <td title="<?php echo esc_attr( $job->prompt ); ?>">
                                <?php echo esc_html( wp_trim_words( $job->prompt, 6, '...' ) ); ?>
                            </td>
                            <td>
                                <?php if ( $has_chain ): ?>
                                    <span class="chain-badge">
                                        <?php echo $job->segment_index; ?>/<?php echo $job->total_segments; ?>
                                    </span>
                                    <?php if ( $is_final ): ?>
                                        <span class="chain-badge chain-badge-final">Final</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $job->duration; ?>s</td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr( $job->status ); ?>">
                                    <?php echo esc_html( ucfirst( $job->status ) ); ?>
                                </span>
                            </td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo (int) $job->progress; ?>%"></div>
                                    <span class="progress-text"><?php echo (int) $job->progress; ?>%</span>
                                </div>
                            </td>
                            <td><?php echo human_time_diff( strtotime( $job->created_at ), current_time( 'timestamp' ) ) . ' ago'; ?></td>
                            <td>
                                <?php if ( in_array( $job->status, array( 'queued', 'processing' ) ) ): ?>
                                    <button type="button" class="button check-status-btn" data-job-id="<?php echo $job->id; ?>">
                                        <?php _e( 'Check Status', 'bizcity-video-kling' ); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ( $job->status === 'completed' && empty( $job->attachment_id ) ): ?>
                                    <button type="button" class="button button-primary fetch-video-btn" data-job-id="<?php echo $job->id; ?>">
                                        <?php _e( 'Fetch Video', 'bizcity-video-kling' ); ?>
                                    </button>
                                    <button type="button" class="button fetch-tts-btn" data-job-id="<?php echo $job->id; ?>" data-prompt="<?php echo esc_attr( $job->prompt ); ?>" title="Fetch + TTS Audio">
                                        🎙️ +TTS
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ( $job->attachment_id ): ?>
                                    <a href="<?php echo wp_get_attachment_url( $job->attachment_id ); ?>" target="_blank" class="button">
                                        <?php _e( 'View', 'bizcity-video-kling' ); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ( $job->status === 'failed' ): 
                                    $checkpoints = BizCity_Video_Kling_Database::get_checkpoints( $job->id );
                                    $has_checkpoints = ! empty( $checkpoints );
                                    $resume_point = BizCity_Video_Kling_Database::get_resume_point( $job->id );
                                ?>
                                    <?php if ( $has_checkpoints ): ?>
                                        <button type="button" class="button button-primary resume-job-btn" 
                                                data-job-id="<?php echo $job->id; ?>"
                                                data-resume-step="<?php echo esc_attr( $resume_point['step'] ); ?>"
                                                title="<?php 
                                                    $done_steps = array_keys( $checkpoints );
                                                    echo 'Done: ' . implode( ', ', $done_steps ) . ' | Resume from: ' . $resume_point['step'];
                                                ?>">
                                            ▶ <?php _e( 'Resume', 'bizcity-video-kling' ); ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="button retry-job-btn" data-job-id="<?php echo $job->id; ?>">
                                            <?php _e( 'Retry', 'bizcity-video-kling' ); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <button type="button" class="button view-logs-btn" data-job-id="<?php echo $job->id; ?>">
                                    <?php _e( 'Logs', 'bizcity-video-kling' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Logs Modal -->
    <div id="logs-modal" class="bizcity-kling-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php _e( 'Job Logs', 'bizcity-video-kling' ); ?> - #<span id="modal-job-id"></span></h2>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="logs-container"></div>
            </div>
        </div>
    </div>
    
    <!-- TTS Options Modal -->
    <div id="tts-modal" class="bizcity-kling-modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2><?php _e( 'Fetch Video + TTS', 'bizcity-video-kling' ); ?> - #<span id="tts-modal-job-id"></span></h2>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="ffmpeg-status" style="margin-bottom: 15px; padding: 10px; border-radius: 5px; background: #f0f0f1;"></div>
                
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row"><?php _e( 'TTS Voice', 'bizcity-video-kling' ); ?></th>
                        <td>
                            <select id="tts-voice" class="regular-text">
                                <option value="nova"><?php _e( 'Nova - Female, warm', 'bizcity-video-kling' ); ?></option>
                                <option value="shimmer"><?php _e( 'Shimmer - Female, clear', 'bizcity-video-kling' ); ?></option>
                                <option value="alloy"><?php _e( 'Alloy - Neutral, balanced', 'bizcity-video-kling' ); ?></option>
                                <option value="echo"><?php _e( 'Echo - Male, smooth', 'bizcity-video-kling' ); ?></option>
                                <option value="fable"><?php _e( 'Fable - Male, British', 'bizcity-video-kling' ); ?></option>
                                <option value="onyx"><?php _e( 'Onyx - Male, deep', 'bizcity-video-kling' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'TTS Model', 'bizcity-video-kling' ); ?></th>
                        <td>
                            <select id="tts-model" class="regular-text">
                                <option value="tts-1-hd"><?php _e( 'TTS-1 HD (High Quality)', 'bizcity-video-kling' ); ?></option>
                                <option value="tts-1"><?php _e( 'TTS-1 (Standard)', 'bizcity-video-kling' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Speed', 'bizcity-video-kling' ); ?></th>
                        <td>
                            <input type="range" id="tts-speed" min="0.5" max="2.0" step="0.1" value="1.0" style="width: 200px;">
                            <span id="tts-speed-value">1.0x</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'FFmpeg Preset', 'bizcity-video-kling' ); ?></th>
                        <td>
                            <select id="ffmpeg-preset" class="regular-text">
                                <option value=""><?php _e( '-- None --', 'bizcity-video-kling' ); ?></option>
                                <optgroup label="<?php _e( 'Combined Presets', 'bizcity-video-kling' ); ?>">
                                    <option value="cinematic"><?php _e( 'Cinematic - Điện ảnh chuyên nghiệp', 'bizcity-video-kling' ); ?></option>
                                    <option value="vintage"><?php _e( 'Vintage - Phong cách cổ điển', 'bizcity-video-kling' ); ?></option>
                                    <option value="modern"><?php _e( 'Modern - Hiện đại rực rỡ', 'bizcity-video-kling' ); ?></option>
                                    <option value="minimal"><?php _e( 'Minimal - Tối giản sạch sẽ', 'bizcity-video-kling' ); ?></option>
                                </optgroup>
                                <optgroup label="<?php _e( 'Color Grading', 'bizcity-video-kling' ); ?>">
                                    <option value="warm"><?php _e( 'Warm - Ấm áp hoàng hôn', 'bizcity-video-kling' ); ?></option>
                                    <option value="cool"><?php _e( 'Cool - Lạnh hiện đại', 'bizcity-video-kling' ); ?></option>
                                    <option value="dramatic"><?php _e( 'Dramatic - Kịch tính cao', 'bizcity-video-kling' ); ?></option>
                                    <option value="golden_hour"><?php _e( 'Golden Hour - Giờ vàng', 'bizcity-video-kling' ); ?></option>
                                </optgroup>
                                <optgroup label="<?php _e( 'Effects', 'bizcity-video-kling' ); ?>">
                                    <option value="zoom_gentle"><?php _e( 'Zoom Gentle (Ken Burns)', 'bizcity-video-kling' ); ?></option>
                                    <option value="lower_third"><?php _e( 'Lower Third (Title Bar)', 'bizcity-video-kling' ); ?></option>
                                    <option value="vignette"><?php _e( 'Vignette - Tối góc', 'bizcity-video-kling' ); ?></option>
                                </optgroup>
                            </select>
                            <p class="description"><?php _e( 'Apply FFmpeg filter preset to video', 'bizcity-video-kling' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Custom Text', 'bizcity-video-kling' ); ?></th>
                        <td>
                            <textarea id="tts-custom-text" rows="3" class="large-text" placeholder="<?php _e( 'Leave empty to use job prompt', 'bizcity-video-kling' ); ?>"></textarea>
                            <p class="description"><?php _e( 'Custom text for TTS (optional)', 'bizcity-video-kling' ); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="button" id="cancel-tts-btn"><?php _e( 'Cancel', 'bizcity-video-kling' ); ?></button>
                    <button type="button" class="button button-primary" id="process-tts-btn">
                        🎙️ <?php _e( 'Process Video + TTS', 'bizcity-video-kling' ); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo $nonce; ?>';
    var activePolling = {};
    
    // Check status button
    $('.check-status-btn').on('click', function() {
        var $btn = $(this);
        var jobId = $btn.data('job-id');
        
        $btn.prop('disabled', true).text('Checking...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_check_status',
                job_id: jobId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    updateJobRow(jobId, response.data);
                } else {
                    alert(response.data.message || 'Error');
                }
            },
            error: function() {
                alert('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Check Status');
            }
        });
    });
    
    // Fetch video button
    $('.fetch-video-btn').on('click', function() {
        var $btn = $(this);
        var jobId = $btn.data('job-id');
        
        $btn.prop('disabled', true).text('Fetching...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_fetch_video',
                job_id: jobId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.replaceWith('<a href="' + response.data.media_url + '" target="_blank" class="button">View</a>');
                    alert('Video uploaded to Media Library!');
                } else {
                    alert(response.data.message || 'Error');
                    $btn.prop('disabled', false).text('Fetch Video');
                }
            },
            error: function() {
                alert('Request failed');
                $btn.prop('disabled', false).text('Fetch Video');
            }
        });
    });
    
    // Retry job button
    $('.retry-job-btn').on('click', function() {
        var $btn = $(this);
        var jobId = $btn.data('job-id');
        
        if (!confirm('Retry this job?')) return;
        
        $btn.prop('disabled', true).text('Retrying...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_retry_job',
                job_id: jobId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error');
                }
            },
            error: function() {
                alert('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Retry');
            }
        });
    });
    
    // Resume job button (for jobs with checkpoints)
    $(document).on('click', '.resume-job-btn', function() {
        var $btn = $(this);
        var jobId = $btn.data('job-id');
        var resumeStep = $btn.data('resume-step');
        
        if (!confirm('Resume from step: ' + resumeStep + '?')) return;
        
        $btn.prop('disabled', true).html('▶ Resuming...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_resume_job',
                job_id: jobId,
                resume_step: resumeStep,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Resume completed! ' + (response.data.message || ''));
                    location.reload();
                } else {
                    alert(response.data.message || 'Resume failed');
                    $btn.prop('disabled', false).html('▶ Resume');
                }
            },
            error: function() {
                alert('Request failed');
                $btn.prop('disabled', false).html('▶ Resume');
            }
        });
    });
    
    // View logs button
    $('.view-logs-btn').on('click', function() {
        var jobId = $(this).data('job-id');
        $('#modal-job-id').text(jobId);
        $('#logs-modal').show();
        loadLogs(jobId);
    });
    
    // Close modal
    $('.close-modal').on('click', function() {
        $(this).closest('.bizcity-kling-modal').hide();
    });
    
    // TTS button
    $('.fetch-tts-btn').on('click', function() {
        var jobId = $(this).data('job-id');
        $('#tts-modal-job-id').text(jobId);
        $('#tts-custom-text').val('');
        $('#tts-modal').data('job-id', jobId);
        $('#tts-modal').show();
        
        // Check FFmpeg status
        checkFFmpegStatus();
    });
    
    // Cancel TTS button
    $('#cancel-tts-btn').on('click', function() {
        $('#tts-modal').hide();
    });
    
    // Speed slider label
    $('#tts-speed').on('input', function() {
        $('#tts-speed-value').text($(this).val() + 'x');
    });
    
    // Check FFmpeg availability
    function checkFFmpegStatus() {
        $('#ffmpeg-status').html('<span>Checking FFmpeg...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_check_ffmpeg',
                nonce: nonce
            },
            success: function(response) {
                var html = '';
                if (response.success) {
                    html = '<span style="color: green;">✓ FFmpeg ' + response.data.version + '</span>';
                    if (response.data.tts_configured) {
                        html += ' | <span style="color: green;">✓ TTS API configured</span>';
                    } else {
                        html += ' | <span style="color: orange;">⚠ TTS API not configured</span>';
                    }
                } else {
                    html = '<span style="color: red;">✗ FFmpeg not found</span>';
                    html += '<br><small>Path tried: ' + (response.data.path || 'ffmpeg') + '</small>';
                }
                $('#ffmpeg-status').html(html);
            },
            error: function() {
                $('#ffmpeg-status').html('<span style="color: red;">Error checking FFmpeg</span>');
            }
        });
    }
    
    // Process TTS button
    $('#process-tts-btn').on('click', function() {
        var $btn = $(this);
        var jobId = $('#tts-modal').data('job-id');
        
        $btn.prop('disabled', true).html('Processing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_fetch_video_with_tts',
                job_id: jobId,
                tts_voice: $('#tts-voice').val(),
                tts_model: $('#tts-model').val(),
                tts_speed: $('#tts-speed').val(),
                ffmpeg_preset: $('#ffmpeg-preset').val(),
                custom_text: $('#tts-custom-text').val(),
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#tts-modal').hide();
                    
                    // Update the row
                    var $row = $('#job-row-' + jobId);
                    $row.find('.fetch-video-btn, .fetch-tts-btn').replaceWith(
                        '<a href="' + response.data.media_url + '" target="_blank" class="button">View</a>'
                    );
                    
                    var msg = 'Video uploaded!';
                    if (response.data.tts_used) msg += ' (TTS added)';
                    if (response.data.ffmpeg_used) msg += ' (FFmpeg processed)';
                    alert(msg);
                } else {
                    alert(response.data.message || 'Error processing video');
                }
            },
            error: function() {
                alert('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).html('🎙️ Process Video + TTS');
            }
        });
    });
    
    function loadLogs(jobId) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_get_logs',
                job_id: jobId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    renderLogs(response.data.logs);
                }
            }
        });
    }
    
    function renderLogs(logs) {
        if (!logs || logs.length === 0) {
            $('#logs-container').html('<p>No logs available</p>');
            return;
        }
        
        var html = '<div class="logs-list">';
        logs.forEach(function(log) {
            html += '<div class="log-entry log-' + log.level + '">';
            html += '<span class="log-time">' + log.time + '</span>';
            html += '<span class="log-message">' + log.message + '</span>';
            html += '</div>';
        });
        html += '</div>';
        
        $('#logs-container').html(html);
    }
    
    function updateJobRow(jobId, data) {
        var $row = $('#job-row-' + jobId);
        
        $row.find('.status-badge')
            .removeClass('status-queued status-processing status-completed status-failed')
            .addClass('status-' + data.status)
            .text(data.status.charAt(0).toUpperCase() + data.status.slice(1));
        
        $row.find('.progress-fill').css('width', data.progress + '%');
        $row.find('.progress-text').text(data.progress + '%');
        $row.attr('data-status', data.status);
        
        // Update buttons if completed
        if (data.status === 'completed' && data.video_url) {
            $row.find('.check-status-btn').replaceWith(
                '<button type="button" class="button button-primary fetch-video-btn" data-job-id="' + jobId + '">Fetch Video</button>'
            );
        }
    }
    
    // Auto-refresh for pending jobs
    var autoRefreshInterval = null;
    
    function startAutoRefresh() {
        if (autoRefreshInterval) return;
        
        autoRefreshInterval = setInterval(function() {
            if (!$('#auto-refresh').is(':checked')) return;
            
            // Find all pending jobs (queued or processing)
            var pendingJobs = [];
            $('#jobs-table tbody tr').each(function() {
                var status = $(this).attr('data-status');
                if (status === 'queued' || status === 'processing') {
                    pendingJobs.push($(this).data('job-id'));
                }
            });
            
            if (pendingJobs.length === 0) return;
            
            // Check status for each pending job
            pendingJobs.forEach(function(jobId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_check_status',
                        job_id: jobId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            updateJobRow(jobId, response.data);
                            
                            // If chain created new job, reload page to show it
                            if (response.data.next_job_id) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            }
                        }
                    }
                });
            });
        }, 5000);
    }
    
    // Start auto-refresh on page load
    startAutoRefresh();
    
    // Toggle auto-refresh
    $('#auto-refresh').on('change', function() {
        if ($(this).is(':checked')) {
            startAutoRefresh();
        }
    });
    
    // Monitor All button - check status of all processing jobs
    $('#monitor-all-btn').on('click', function() {
        var $btn = $(this);
        var originalText = $btn.html();
        
        // Find all pending jobs (queued or processing)
        var pendingJobs = [];
        $('#jobs-table tbody tr').each(function() {
            var status = $(this).attr('data-status');
            if (status === 'queued' || status === 'processing') {
                pendingJobs.push($(this).data('job-id'));
            }
        });
        
        if (pendingJobs.length === 0) {
            alert('<?php _e( 'No pending jobs to monitor.', 'bizcity-video-kling' ); ?>');
            return;
        }
        
        $btn.prop('disabled', true).html('🔄 <?php _e( 'Monitoring...', 'bizcity-video-kling' ); ?> (0/' + pendingJobs.length + ')');
        
        var completed = 0;
        var hasChanges = false;
        
        // Check status for each pending job
        pendingJobs.forEach(function(jobId, index) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_kling_check_status',
                    job_id: jobId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateJobRow(jobId, response.data);
                        
                        if (response.data.status === 'completed' || response.data.status === 'failed') {
                            hasChanges = true;
                        }
                        
                        // If chain created new job, mark for reload
                        if (response.data.next_job_id) {
                            hasChanges = true;
                        }
                    }
                },
                complete: function() {
                    completed++;
                    $btn.html('🔄 <?php _e( 'Monitoring...', 'bizcity-video-kling' ); ?> (' + completed + '/' + pendingJobs.length + ')');
                    
                    if (completed >= pendingJobs.length) {
                        $btn.prop('disabled', false).html(originalText);
                        
                        if (hasChanges) {
                            // Reload page to show updated data
                            setTimeout(function() {
                                location.reload();
                            }, 500);
                        }
                    }
                }
            });
        });
    });
    
    // Refresh button
    $('#refresh-page-btn').on('click', function() {
        location.reload();
    });
});
</script>

<style>
/* Header Actions */
.header-actions {
    display: flex;
    gap: 8px;
}
.header-actions .button {
    display: flex;
    align-items: center;
    gap: 4px;
}
#monitor-all-btn {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-color: #059669 !important;
    color: white !important;
}
#monitor-all-btn:hover {
    opacity: 0.9;
}
#monitor-all-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
.chain-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    background: #dbeafe;
    color: #2563eb;
    margin-right: 4px;
}
.chain-badge-final {
    background: #d1fae5;
    color: #059669;
}
.chain-job {
    background: #f0f9ff !important;
}
.chain-job.chain-final {
    background: #f0fdf4 !important;
}
#jobs-table .chain-job td:first-child::before {
    content: "⤷ ";
    color: #3b82f6;
}
/* TTS Button */
.fetch-tts-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: white !important;
    border: none !important;
}
.fetch-tts-btn:hover {
    opacity: 0.9;
}
/* TTS Modal */
#tts-modal .modal-content {
    max-width: 550px;
}
#tts-modal .form-table th {
    padding: 10px 10px 10px 0;
    font-weight: 500;
}
#tts-modal .form-table td {
    padding: 10px 0;
}
#tts-speed {
    vertical-align: middle;
}
#tts-speed-value {
    margin-left: 10px;
    font-weight: 600;
    color: #2271b1;
}
</style>
