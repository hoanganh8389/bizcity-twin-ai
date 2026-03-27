<?php
/**
 * View: Generate Video Page
 * 
 * @var int    $script_id      Script ID
 * @var object $script         Script object
 * @var array  $metadata       Script metadata
 * @var string $image_url      Image URL from metadata
 * @var int    $existing_job_id Existing job ID for monitoring
 * @var object $existing_job   Existing job object
 * @var bool   $is_resuming    Whether resuming existing job
 * @var array  $pending_jobs   Active/pending jobs
 * @var array  $recent_history Recent completed/failed jobs
 * @var string $nonce          Security nonce
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap bizcity-kling-wrap">
    <h1>
        <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts' ); ?>" style="text-decoration: none; color: inherit;">←</a>
        <?php echo esc_html( $script->title ); ?> - <?php _e( 'Generate Video', 'bizcity-video-kling' ); ?>
    </h1>
    
    <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'generate', $script_id ); ?>
    
    <!-- Script Info -->
    <div class="bizcity-kling-card">
        <h2><?php _e( 'Script Details', 'bizcity-video-kling' ); ?></h2>
        
        <div style="display: grid; grid-template-columns: <?php echo $image_url ? '200px 1fr' : '1fr'; ?>; gap: 20px;">
            <?php if ( $image_url ): ?>
                <div>
                    <img src="<?php echo esc_url( $image_url ); ?>" style="width: 100%; border-radius: 8px; border: 1px solid #ddd;">
                    <p style="margin-top: 5px; font-size: 12px; color: #666;"><?php _e( 'Source Image', 'bizcity-video-kling' ); ?></p>
                </div>
            <?php endif; ?>
            
            <div>
                <p><strong><?php _e( 'Prompt:', 'bizcity-video-kling' ); ?></strong></p>
                <p style="background: #f5f5f5; padding: 12px; border-radius: 4px; margin: 5px 0;"><?php echo nl2br( esc_html( $script->content ) ); ?></p>
                
                <p style="margin-top: 15px;">
                    <span class="badge-info"><?php echo (int) $script->duration; ?>s</span>
                    <span class="badge-info"><?php echo esc_html( $script->aspect_ratio ); ?></span>
                    <span class="badge-info"><?php echo esc_html( $script->model ); ?></span>
                    <?php if ( ! empty( $metadata['tts_enabled'] ) ): ?>
                        <span class="badge-info" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                            🎙️ TTS: <?php echo esc_html( ucfirst( $metadata['tts_voice'] ?? 'nova' ) ); ?>
                        </span>
                    <?php endif; ?>
                </p>
                
                <?php if ( ! empty( $metadata['tts_enabled'] ) && ! empty( $metadata['tts_text'] ) ): ?>
                    <p style="margin-top: 15px;"><strong><?php _e( 'Voiceover Text:', 'bizcity-video-kling' ); ?></strong></p>
                    <p style="background: #f0f4ff; padding: 12px; border-radius: 4px; margin: 5px 0; border-left: 3px solid #667eea;">
                        <?php echo nl2br( esc_html( $metadata['tts_text'] ) ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php if ( ! empty( $pending_jobs ) || ! empty( $recent_history ) ): ?>
    <!-- Active Jobs Section -->
    <div class="bizcity-kling-card">
        <h2>
            <span class="dashicons dashicons-list-view" style="margin-right: 5px;"></span>
            <?php _e( 'Job History', 'bizcity-video-kling' ); ?>
            <?php if ( ! empty( $pending_jobs ) ): ?>
                <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 10px;">
                    <?php echo count( $pending_jobs ); ?> <?php _e( 'active', 'bizcity-video-kling' ); ?>
                </span>
            <?php endif; ?>
        </h2>
        
        <table class="wp-list-table widefat striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php _e( 'ID', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 120px;"><?php _e( 'Status', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Progress', 'bizcity-video-kling' ); ?></th>
                    <th><?php _e( 'Created', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 150px;"><?php _e( 'Actions', 'bizcity-video-kling' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $pending_jobs as $pjob ): ?>
                <tr style="background: <?php echo $pjob->id == $existing_job_id ? '#fff9e6' : ''; ?>;">
                    <td><strong>#<?php echo $pjob->id; ?></strong></td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr( $pjob->status ); ?>">
                            <?php echo ucfirst( $pjob->status ); ?>
                        </span>
                        <?php if ( ! empty( $pjob->chain_id ) && $pjob->total_segments > 1 ): ?>
                            <small style="color: #666;">(<?php echo $pjob->segment_index; ?>/<?php echo $pjob->total_segments; ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $pjob->progress; ?>%</td>
                    <td><?php echo human_time_diff( strtotime( $pjob->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'bizcity-video-kling' ); ?></td>
                    <td>
                        <?php if ( $pjob->id != $existing_job_id ): ?>
                            <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id . '&job_id=' . $pjob->id ); ?>" 
                               class="button button-small" style="background: #f59e0b; border-color: #d97706; color: white;">
                                <span class="dashicons dashicons-visibility" style="font-size: 14px; line-height: 1.3;"></span>
                                <?php _e( 'Resume', 'bizcity-video-kling' ); ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #10b981; font-weight: 500;">
                                <span class="dashicons dashicons-yes-alt" style="font-size: 14px;"></span>
                                <?php _e( 'Monitoring', 'bizcity-video-kling' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php foreach ( $recent_history as $hjob ): ?>
                <tr style="opacity: 0.7;">
                    <td>#<?php echo $hjob->id; ?></td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr( $hjob->status ); ?>">
                            <?php echo ucfirst( $hjob->status ); ?>
                        </span>
                    </td>
                    <td><?php echo (int) $hjob->progress; ?>%</td>
                    <td><?php echo human_time_diff( strtotime( $hjob->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'bizcity-video-kling' ); ?></td>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id . '&job_id=' . $hjob->id ); ?>" 
                           class="button button-small">
                            <?php _e( 'View', 'bizcity-video-kling' ); ?>
                        </a>
                        <?php if ( ! empty( $hjob->video_url ) ): ?>
                            <a href="<?php echo esc_url( $hjob->video_url ); ?>" target="_blank" class="button button-small">
                                <span class="dashicons dashicons-video-alt3" style="font-size: 14px; line-height: 1.3;"></span>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Generation Controls -->
    <div class="bizcity-kling-card">
        <h2>
            <?php if ( $is_resuming ): ?>
                <?php _e( 'Monitoring Job', 'bizcity-video-kling' ); ?> #<?php echo $existing_job->id; ?>
                <?php if ( ! empty( $existing_job->chain_id ) && $existing_job->total_segments > 1 ): ?>
                    <span style="font-weight: normal; font-size: 14px; color: #666;">
                        (<?php echo $existing_job->segment_index; ?>/<?php echo $existing_job->total_segments; ?> segments)
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <?php _e( 'Generate Video', 'bizcity-video-kling' ); ?>
            <?php endif; ?>
        </h2>
        
        <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 20px;">
            <?php if ( $is_resuming ): ?>
                <button type="button" id="start-generation-btn" class="button button-primary button-hero" data-script-id="<?php echo $script_id; ?>" 
                        data-resume-job-id="<?php echo $existing_job->id; ?>" disabled>
                    <span class="dashicons dashicons-visibility" style="margin-right: 5px;"></span>
                    <?php _e( 'Monitoring...', 'bizcity-video-kling' ); ?>
                </button>
            <?php else: ?>
                <button type="button" id="start-generation-btn" class="button button-primary button-hero" data-script-id="<?php echo $script_id; ?>">
                    <span class="dashicons dashicons-video-alt3" style="margin-right: 5px;"></span>
                    <?php _e( 'Start Generation', 'bizcity-video-kling' ); ?>
                </button>
            <?php endif; ?>
            
            <label style="display: flex; align-items: center; gap: 5px;">
                <input type="checkbox" id="auto-fetch-video" checked>
                <?php _e( 'Auto fetch video when completed', 'bizcity-video-kling' ); ?>
            </label>
            
            <?php if ( ! empty( $metadata['tts_enabled'] ) ): ?>
            <label style="display: flex; align-items: center; gap: 5px; margin-left: 15px; background: linear-gradient(135deg, #667eea22 0%, #764ba222 100%); padding: 5px 10px; border-radius: 4px;">
                <input type="checkbox" id="auto-fetch-with-tts" checked>
                🎙️ <?php _e( 'Apply TTS voiceover', 'bizcity-video-kling' ); ?>
            </label>
            <?php endif; ?>
            
            <?php if ( $is_resuming ): ?>
                <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id ); ?>" 
                   class="button button-secondary" style="margin-left: 10px;">
                    <span class="dashicons dashicons-plus-alt2" style="margin-right: 5px;"></span>
                    <?php _e( 'New Generation', 'bizcity-video-kling' ); ?>
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Progress -->
        <div id="generation-status" style="display: <?php echo $is_resuming ? 'block' : 'none'; ?>;">
            <div class="job-status-row" style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                <span id="status-badge" class="status-badge status-<?php echo $is_resuming ? esc_attr( $existing_job->status ) : 'queued'; ?>">
                    <?php echo $is_resuming ? ucfirst( $existing_job->status ) : __( 'Queued', 'bizcity-video-kling' ); ?>
                </span>
                <div class="progress-bar" style="flex: 1; max-width: 400px;">
                    <div id="progress-fill" class="progress-fill" style="width: <?php echo $is_resuming ? (int) $existing_job->progress : 0; ?>%;"></div>
                    <span id="progress-text" class="progress-text"><?php echo $is_resuming ? (int) $existing_job->progress : 0; ?>%</span>
                </div>
                <span id="job-id-display" style="font-size: 12px; color: #666;">
                    <?php if ( $is_resuming ): ?>Job #<?php echo $existing_job->id; ?><?php endif; ?>
                </span>
            </div>
        </div>
        
        <!-- Log Console -->
        <div class="job-log-wrapper">
            <h3 style="margin: 0 0 10px 0;"><?php _e( 'Generation Log', 'bizcity-video-kling' ); ?></h3>
            <div id="job-log-console" class="job-log-console">
                <?php if ( $is_resuming ): ?>
                    <div class="log-line" style="color: #f59e0b;">[<?php echo current_time( 'H:i:s' ); ?>] Resuming monitoring for Job #<?php echo $existing_job->id; ?>...</div>
                <?php else: ?>
                    <div class="log-line" style="color: #00ff00;">[<?php echo current_time( 'H:i:s' ); ?>] Ready to generate video...</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Video Preview -->
        <div id="video-preview" style="display: none; margin-top: 20px;">
            <h3><?php _e( 'Generated Video', 'bizcity-video-kling' ); ?></h3>
            <video id="video-player" controls style="max-width: 400px; border-radius: 8px;"></video>
            <div style="margin-top: 10px;">
                <a id="video-download-link" href="#" class="button button-primary" target="_blank">
                    <span class="dashicons dashicons-download" style="margin-right: 5px;"></span>
                    <?php _e( 'Download Video', 'bizcity-video-kling' ); ?>
                </a>
                <a id="video-media-link" href="#" class="button" target="_blank" style="display: none;">
                    <?php _e( 'View in Media Library', 'bizcity-video-kling' ); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.badge-info { display: inline-block; padding: 3px 10px; background: #e5e7eb; border-radius: 4px; font-size: 12px; margin-right: 5px; }
.job-log-wrapper { background: #1e1e1e; border-radius: 8px; padding: 15px; margin-top: 20px; }
.job-log-wrapper h3 { color: #fff; }
.job-log-console { background: #0d0d0d; border-radius: 4px; padding: 15px; height: 300px; overflow-y: auto; font-family: 'Monaco', 'Consolas', monospace; font-size: 13px; line-height: 1.6; }
.job-log-console .log-line { margin: 2px 0; }
</style>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    var currentJobId = <?php echo $existing_job_id ? $existing_job_id : 'null'; ?>;
    var isResuming = <?php echo $is_resuming ? 'true' : 'false'; ?>;
    var pollingInterval = null;
    var lastLogTimestamp = 0;
    
    // Auto-start polling if resuming
    if (isResuming && currentJobId) {
        setTimeout(function() {
            startPolling();
        }, 500);
    }
    
    function addLog(message, level) {
        var time = new Date().toLocaleTimeString('vi-VN', { hour12: false });
        var colorStyle = '';
        switch(level) {
            case 'success': colorStyle = 'color: #10b981;'; break;
            case 'error': colorStyle = 'color: #ef4444;'; break;
            case 'warning': colorStyle = 'color: #f59e0b;'; break;
            default: colorStyle = 'color: #00ff00;';
        }
        $('#job-log-console').append('<div class="log-line" style="' + colorStyle + '">[' + time + '] ' + message + '</div>');
        $('#job-log-console').scrollTop($('#job-log-console')[0].scrollHeight);
    }
    
    function updateStatus(status, progress, currentSegment, totalSegments) {
        var $badge = $('#status-badge');
        $badge.removeClass('status-queued status-processing status-completed status-failed');
        $badge.addClass('status-' + status).text(status.charAt(0).toUpperCase() + status.slice(1));
        
        $('#progress-fill').css('width', progress + '%');
        
        if (totalSegments && totalSegments > 1) {
            $('#progress-text').text(progress + '% (Seg ' + currentSegment + '/' + totalSegments + ')');
        } else {
            $('#progress-text').text(progress + '%');
        }
    }
    
    // Start generation
    $('#start-generation-btn').click(function() {
        var $btn = $(this);
        var scriptId = $btn.data('script-id');
        
        if ($btn.attr('data-resume-job-id') && pollingInterval) {
            return;
        }
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Starting...');
        $('#generation-status').show();
        
        addLog('Creating video generation job...', 'info');
        updateStatus('queued', 5);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_generate_video',
                script_id: scriptId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    currentJobId = response.data.job_id;
                    $('#job-id-display').text('Job #' + currentJobId);
                    
                    addLog('Job created: #' + currentJobId + ', task_id=' + response.data.task_id, 'success');
                    updateStatus('queued', 10);
                    
                    startPolling();
                } else {
                    addLog('Error: ' + (response.data.message || 'Unknown error'), 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-video-alt3"></span> Start Generation');
                }
            },
            error: function() {
                addLog('AJAX request failed', 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-video-alt3"></span> Start Generation');
            }
        });
    });
    
    function startPolling() {
        if (pollingInterval) clearInterval(pollingInterval);
        
        pollingInterval = setInterval(function() {
            checkJobStatus();
        }, 5000);
        
        checkJobStatus();
    }
    
    var currentChainId = null;
    
    function checkJobStatus() {
        if (!currentJobId) return;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_check_status',
                job_id: currentJobId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    if (data.chain_id) {
                        currentChainId = data.chain_id;
                    }
                    
                    var displayProgress = data.progress;
                    if (data.total_segments > 1) {
                        var segmentProgress = ((data.current_segment - 1) * 100) / data.total_segments;
                        var thisSegmentContribution = data.progress / data.total_segments;
                        displayProgress = Math.round(segmentProgress + thisSegmentContribution);
                        
                        updateStatus(data.status, displayProgress, data.current_segment, data.total_segments);
                    } else {
                        updateStatus(data.status, displayProgress);
                    }
                    
                    if (data.status === 'processing') {
                        if (data.total_segments > 1) {
                            addLog('Segment ' + data.current_segment + '/' + data.total_segments + ': ' + data.progress + '%', 'info');
                        } else {
                            addLog('Status: ' + data.status + ' (' + data.progress + '%)', 'info');
                        }
                    }
                    
                    if (data.chain_status === 'extending' && data.next_job_id) {
                        addLog('Segment ' + (data.current_segment - 1) + ' completed, starting segment ' + data.current_segment + '...', 'success');
                        currentJobId = data.next_job_id;
                        $('#job-id-display').text('Job #' + currentJobId + ' (Seg ' + data.current_segment + '/' + data.total_segments + ')');
                    }
                    
                    if (data.chain_status === 'chain_completed') {
                        clearInterval(pollingInterval);
                        addLog('All ' + data.total_segments + ' segments COMPLETED!', 'success');
                        
                        if (data.video_url) {
                            addLog('Final video URL: ' + data.video_url, 'info');
                            
                            if ($('#auto-fetch-video').is(':checked')) {
                                if (currentChainId) {
                                    fetchChainVideos(currentChainId);
                                } else {
                                    fetchVideoToMedia();
                                }
                            } else {
                                showVideoPreview(data.video_url);
                            }
                        }
                        
                        isResuming = false;
                        $('#start-generation-btn').removeAttr('data-resume-job-id')
                            .prop('disabled', false)
                            .html('<span class="dashicons dashicons-video-alt3"></span> Generate Again');
                        return;
                    }
                    
                    if (data.status === 'completed' && !data.chain_status) {
                        clearInterval(pollingInterval);
                        addLog('Video generation COMPLETED!', 'success');
                        
                        if (data.video_url) {
                            addLog('Video URL: ' + data.video_url, 'info');
                            
                            if ($('#auto-fetch-video').is(':checked')) {
                                fetchVideoToMedia();
                            } else {
                                showVideoPreview(data.video_url);
                            }
                        }
                        
                        isResuming = false;
                        $('#start-generation-btn').removeAttr('data-resume-job-id')
                            .prop('disabled', false)
                            .html('<span class="dashicons dashicons-video-alt3"></span> Generate Again');
                    }
                    
                    if (data.status === 'failed') {
                        clearInterval(pollingInterval);
                        addLog('Generation FAILED: ' + (data.error_message || 'Unknown error'), 'error');
                        isResuming = false;
                        $('#start-generation-btn').removeAttr('data-resume-job-id')
                            .prop('disabled', false)
                            .html('<span class="dashicons dashicons-video-alt3"></span> Retry');
                    }
                }
            }
        });
    }
    
    function fetchVideoToMedia() {
        var useTts = $('#auto-fetch-with-tts').is(':checked');
        var action = useTts ? 'bizcity_kling_fetch_video_with_tts' : 'bizcity_kling_fetch_video';
        
        if (useTts) {
            addLog('Fetching video with TTS voiceover...', 'info');
        } else {
            addLog('Fetching video and uploading to Media Library...', 'info');
        }
        
        var requestData = {
            action: action,
            job_id: currentJobId,
            nonce: nonce
        };
        
        <?php if ( ! empty( $metadata['tts_enabled'] ) ): ?>
        if (useTts) {
            requestData.tts_voice = '<?php echo esc_js( $metadata['tts_voice'] ?? 'nova' ); ?>';
            requestData.tts_model = '<?php echo esc_js( $metadata['tts_model'] ?? 'tts-1-hd' ); ?>';
            requestData.tts_speed = '<?php echo esc_js( $metadata['tts_speed'] ?? '1.0' ); ?>';
            requestData.ffmpeg_preset = '<?php echo esc_js( $metadata['ffmpeg_preset'] ?? '' ); ?>';
            requestData.custom_text = <?php echo wp_json_encode( $metadata['tts_text'] ?? '' ); ?>;
        }
        <?php endif; ?>
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                if (response.success) {
                    if (useTts && response.data.tts_used) {
                        addLog('Video with TTS uploaded to Media Library!', 'success');
                    } else {
                        addLog('Video uploaded to Media Library!', 'success');
                    }
                    addLog('Attachment ID: #' + response.data.attachment_id, 'info');
                    
                    showVideoPreview(response.data.media_url, response.data.attachment_id);
                } else {
                    addLog('Failed to upload: ' + response.data.message, 'error');
                }
            }
        });
    }
    
    function fetchChainVideos(chainId) {
        var useTts = $('#auto-fetch-with-tts').is(':checked');
        
        if (useTts) {
            addLog('Concatenating all segments + adding TTS voiceover...', 'info');
        } else {
            addLog('Fetching all chain segments and uploading to Media Library...', 'info');
        }
        
        var requestData = {
            action: useTts ? 'bizcity_kling_fetch_chain_with_tts' : 'bizcity_kling_fetch_chain',
            chain_id: chainId,
            nonce: nonce
        };
        
        <?php if ( ! empty( $metadata['tts_enabled'] ) ): ?>
        if (useTts) {
            requestData.tts_voice = '<?php echo esc_js( $metadata['tts_voice'] ?? 'nova' ); ?>';
            requestData.tts_model = '<?php echo esc_js( $metadata['tts_model'] ?? 'tts-1-hd' ); ?>';
            requestData.tts_speed = '<?php echo esc_js( $metadata['tts_speed'] ?? '1.0' ); ?>';
            requestData.ffmpeg_preset = '<?php echo esc_js( $metadata['ffmpeg_preset'] ?? '' ); ?>';
            requestData.custom_text = <?php echo wp_json_encode( $metadata['tts_text'] ?? '' ); ?>;
        }
        <?php endif; ?>
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            timeout: 300000,
            success: function(response) {
                if (response.success) {
                    addLog(response.data.message, 'success');
                    
                    if (response.data.attachment_id && response.data.media_url) {
                        if (response.data.tts_used) {
                            addLog('TTS voiceover added successfully!', 'success');
                        }
                        addLog('Final Attachment ID: #' + response.data.attachment_id, 'info');
                        showVideoPreview(response.data.media_url, response.data.attachment_id);
                    }
                    else if (response.data.results) {
                        response.data.results.forEach(function(r) {
                            var status = r.status === 'uploaded' ? 'success' : (r.status === 'already_uploaded' ? 'info' : 'warning');
                            addLog('Segment ' + r.segment + ': ' + r.status + (r.attachment_id ? ' (ID #' + r.attachment_id + ')' : ''), status);
                        });
                        
                        if (response.data.final_video) {
                            showVideoPreview(response.data.final_video.media_url, response.data.final_video.attachment_id);
                        }
                    }
                } else {
                    addLog('Failed to process chain: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                addLog('Request failed: ' + (error || status), 'error');
            }
        });
    }
    
    function showVideoPreview(videoUrl, attachmentId) {
        $('#video-player').attr('src', videoUrl);
        $('#video-download-link').attr('href', videoUrl);
        
        if (attachmentId) {
            $('#video-media-link').attr('href', '<?php echo admin_url( 'upload.php?item=' ); ?>' + attachmentId).show();
        }
        
        $('#video-preview').show();
    }
});
</script>
