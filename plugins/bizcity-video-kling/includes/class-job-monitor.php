<?php
/**
 * Job Monitor - AJAX polling to check job status and fetch videos
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Job_Monitor {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action( 'wp_ajax_bizcity_kling_poll_job', array( __CLASS__, 'ajax_poll_job' ) );
        add_action( 'wp_ajax_bizcity_kling_check_status', array( __CLASS__, 'ajax_check_status' ) );
        add_action( 'wp_ajax_bizcity_kling_fetch_video', array( __CLASS__, 'ajax_fetch_video' ) );
        add_action( 'wp_ajax_bizcity_kling_fetch_video_with_tts', array( __CLASS__, 'ajax_fetch_video_with_tts' ) );
        add_action( 'wp_ajax_bizcity_kling_fetch_chain', array( __CLASS__, 'ajax_fetch_chain' ) );
        add_action( 'wp_ajax_bizcity_kling_fetch_chain_with_tts', array( __CLASS__, 'ajax_fetch_chain_with_tts' ) );
        add_action( 'wp_ajax_bizcity_kling_get_logs', array( __CLASS__, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_bizcity_kling_retry_job', array( __CLASS__, 'ajax_retry_job' ) );
        add_action( 'wp_ajax_bizcity_kling_resume_job', array( __CLASS__, 'ajax_resume_job' ) );
        add_action( 'wp_ajax_bizcity_kling_check_ffmpeg', array( __CLASS__, 'ajax_check_ffmpeg' ) );
    }
    
    /**
     * Add log entry
     */
    public static function add_log( $job_id, $message, $level = 'info' ) {
        $log_key = "bizcity_kling_logs_{$job_id}";
        $logs = get_transient( $log_key );
        
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }
        
        $logs[] = array(
            'time' => current_time( 'H:i:s' ),
            'timestamp' => time(),
            'message' => $message,
            'level' => $level,
        );
        
        // Keep only last 200 logs for better debugging
        if ( count( $logs ) > 200 ) {
            $logs = array_slice( $logs, -200 );
        }
        
        // Store for 2 hours
        set_transient( $log_key, $logs, 2 * HOUR_IN_SECONDS );
        
        // Also log to error_log if debug enabled (via Queue Dashboard)
        if ( get_option( 'bizcity_kling_queue_debug', false ) ) {
            error_log( sprintf( '[BizCity-Kling][Job#%d][%s] %s', $job_id, strtoupper( $level ), $message ) );
        }
        
        return true;
    }
    
    /**
     * Get logs
     */
    public static function get_logs( $job_id, $since_timestamp = 0 ) {
        $log_key = "bizcity_kling_logs_{$job_id}";
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
     * Clear logs
     */
    public static function clear_logs( $job_id ) {
        $log_key = "bizcity_kling_logs_{$job_id}";
        delete_transient( $log_key );
    }
    
    /**
     * AJAX: Poll job status with logs
     */
    public static function ajax_poll_job() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        $since_timestamp = intval( $_POST['since_timestamp'] ?? 0 );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Get new logs since last poll
        $logs = self::get_logs( $job_id, $since_timestamp );
        
        // Get metadata
        $metadata = json_decode( $job->metadata ?? '{}', true );
        
        wp_send_json_success( array(
            'job_id' => $job_id,
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'video_url' => $job->video_url,
            'media_url' => $job->media_url,
            'attachment_id' => $job->attachment_id,
            'error_message' => $job->error_message,
            'logs' => $logs,
            'last_timestamp' => time(),
        ) );
    }
    
    /**
     * AJAX: Check job status from API and update
     */
    public static function ajax_check_status() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
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
        
        // If already completed or failed, return current status
        if ( in_array( $job->status, array( 'completed', 'failed' ) ) ) {
            self::add_log( $job_id, sprintf( 'Job already %s', $job->status ), 'info' );
            
            // Check if this is a chain and need to continue
            $chain_response = self::maybe_continue_chain( $job );
            
            wp_send_json_success( array_merge( array(
                'status' => $job->status,
                'message' => __( 'Job already processed', 'bizcity-video-kling' ),
            ), $chain_response ) );
            return;
        }
        
        if ( empty( $job->task_id ) ) {
            self::add_log( $job_id, 'Missing task_id', 'error' );
            wp_send_json_error( array( 'message' => __( 'Missing task_id', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Log start
        self::add_log( $job_id, sprintf( 'Checking status for task_id=%s', $job->task_id ), 'info' );
        
        // Get API settings
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        
        if ( empty( $api_key ) ) {
            self::add_log( $job_id, 'API key not configured', 'error' );
            wp_send_json_error( array( 'message' => __( 'API key not configured', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Call API to get task status
        $settings = array(
            'api_key' => $api_key,
            'endpoint' => $endpoint,
        );
        
        $result = waic_kling_get_task( $settings, $job->task_id );
        
        if ( ! $result['ok'] ) {
            self::add_log( $job_id, sprintf( 'API error: %s', $result['error'] ?? 'Unknown' ), 'error' );
            wp_send_json_error( array( 'message' => $result['error'] ?? __( 'API error', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $status = waic_kling_normalize_status( $result['data'] );
        $progress = self::get_progress_from_status( $status );
        
        self::add_log( $job_id, sprintf( 'Status: %s, Progress: %d%%', $status, $progress ), 'info' );
        
        // Update job
        $update_data = array(
            'status' => $status,
            'progress' => $progress,
        );
        
        $chain_response = array();
        
        // If completed, extract video URL
        if ( in_array( $status, array( 'succeeded', 'success', 'completed', 'done' ) ) ) {
            $video_url = waic_kling_extract_video_url( $result['data'] );
            if ( $video_url ) {
                $update_data['video_url'] = $video_url;
                $update_data['status'] = 'completed';
                self::add_log( $job_id, 'Segment completed!', 'success' );
                self::add_log( $job_id, sprintf( 'Video URL: %s', $video_url ), 'info' );
                
                // Update job first
                BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
                
                // Reload job with updated data
                $job = BizCity_Video_Kling_Database::get_job( $job_id );
                
                // Notify Zalo admins: video is ready to fetch
                $urls = self::get_admin_monitor_urls( $job_id, $job->script_id ?? 0 );
                $done_msg = "✅ Video tạo xong! (job #{$job_id})\n";
                if ( ! empty( $job->prompt ) ) {
                    $done_msg .= "📝 " . mb_substr( $job->prompt, 0, 80 ) . "\n";
                }
                $done_msg .= "\n🎬 Theo dõi & tải video:\n" . $urls['monitor'] . "\n\n";
                $done_msg .= "📋 Danh sách kịch bản:\n" . $urls['list'];
                self::notify_zalo_admins_video_status( $job, $done_msg );

                // Check if need to continue chain (auto-extend)
                $chain_response = self::maybe_continue_chain( $job );
            }
        }
        
        // If failed
        if ( in_array( $status, array( 'failed', 'error', 'canceled' ) ) ) {
            $update_data['status'] = 'failed';
            $update_data['error_message'] = $result['data']['message'] ?? __( 'Video generation failed', 'bizcity-video-kling' );
            self::add_log( $job_id, sprintf( 'Failed: %s', $update_data['error_message'] ), 'error' );
        }
        
        // Save metadata
        $metadata = json_decode( $job->metadata ?? '{}', true );
        $metadata['last_check'] = current_time( 'mysql' );
        $metadata['raw_status'] = $result['data'];
        $update_data['metadata'] = json_encode( $metadata );
        
        BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
        
        wp_send_json_success( array_merge( array(
            'status' => $update_data['status'],
            'progress' => $update_data['progress'],
            'video_url' => $update_data['video_url'] ?? null,
            'message' => sprintf( 'Status: %s', $update_data['status'] ),
        ), $chain_response ) );
    }
    
    /**
     * Maybe continue chain by creating next video segment job
     * Using FFmpeg concat approach instead of extend_video to avoid distortion
     * 
     * @param object $job Current job
     * @return array Chain status info
     */
    private static function maybe_continue_chain( $job ) {
        // Not a chain job
        if ( empty( $job->chain_id ) || $job->total_segments <= 1 ) {
            return array();
        }
        
        // Job not completed
        if ( $job->status !== 'completed' ) {
            return array();
        }
        
        $chain_id = $job->chain_id;
        $current_segment = (int) $job->segment_index;
        $total_segments = (int) $job->total_segments;
        
        // Already final segment - check if all segments completed
        if ( $current_segment >= $total_segments ) {
            self::add_log( $job->id, 'Chain completed! All segments done. Ready for FFmpeg concat.', 'success' );
            
            // Check if TTS is enabled in metadata and auto-process
            $metadata = json_decode( $job->metadata ?? '{}', true );
            $tts_enabled = $metadata['tts_enabled'] ?? false;
            
            return array(
                'chain_status' => 'chain_completed',
                'chain_id' => $chain_id,
                'current_segment' => $current_segment,
                'total_segments' => $total_segments,
                'ready_for_concat' => true,
                'tts_enabled' => $tts_enabled,
                'tts_voice' => $metadata['tts_voice'] ?? 'nova',
                'tts_text' => $metadata['tts_text'] ?? '',
            );
        }
        
        // Check if next segment already exists
        $next_segment_index = $current_segment + 1;
        $existing_jobs = BizCity_Video_Kling_Database::get_jobs_by_chain( $chain_id );
        
        $next_exists = false;
        foreach ( $existing_jobs as $existing ) {
            if ( (int) $existing->segment_index === $next_segment_index ) {
                $next_exists = true;
                break;
            }
        }
        
        if ( $next_exists ) {
            // Next segment already created, just return chain info
            return array(
                'chain_status' => 'creating_segment',
                'chain_id' => $chain_id,
                'current_segment' => $current_segment,
                'total_segments' => $total_segments,
            );
        }
        
        // Create next segment job (new video_generation, NOT extend_video)
        self::add_log( $job->id, sprintf( 
            'Creating new video segment %d/%d (FFmpeg concat mode)...', 
            $next_segment_index, 
            $total_segments 
        ), 'info' );
        
        $segment_result = self::create_new_segment_job( $job, $next_segment_index );
        
        if ( $segment_result['success'] ) {
            return array(
                'chain_status' => 'creating_segment',
                'chain_id' => $chain_id,
                'current_segment' => $next_segment_index,
                'total_segments' => $total_segments,
                'next_job_id' => $segment_result['job_id'],
            );
        } else {
            self::add_log( $job->id, 'Failed to create segment job: ' . $segment_result['message'], 'error' );
            return array(
                'chain_status' => 'segment_failed',
                'chain_error' => $segment_result['message'],
            );
        }
    }
    
    /**
     * Create NEW independent video generation job for next segment (FFmpeg concat mode)
     * Instead of using extend_video API (which causes distortion), we create fresh video jobs
     * with the same prompt and then concat all segments using FFmpeg
     * 
     * @param object $parent_job Parent job
     * @param int $segment_index New segment index
     * @return array Result
     */
    private static function create_new_segment_job( $parent_job, $segment_index ) {
        // Get API settings
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'message' => 'API key not configured' );
        }
        
        $total_segments = (int) $parent_job->total_segments;
        $is_final = ( $segment_index >= $total_segments );
        
        // Get segment durations from parent job metadata
        $parent_metadata = json_decode( $parent_job->metadata ?? '{}', true );
        $segment_durations = $parent_metadata['segment_durations'] ?? array();
        $segment_duration = isset( $segment_durations[ $segment_index - 1 ] ) 
            ? (int) $segment_durations[ $segment_index - 1 ] 
            : 10;
        
        // Get original image from first segment for consistency
        $original_image_url = '';
        if ( ! empty( $parent_job->chain_id ) ) {
            $chain_jobs = BizCity_Video_Kling_Database::get_jobs_by_chain( $parent_job->chain_id );
            if ( ! empty( $chain_jobs ) ) {
                // First segment has the original image
                foreach ( $chain_jobs as $chain_job ) {
                    if ( ! empty( $chain_job->image_url ) ) {
                        $original_image_url = $chain_job->image_url;
                        break;
                    }
                }
            }
        }
        // Fallback to parent's image if no chain image found
        if ( empty( $original_image_url ) && ! empty( $parent_job->image_url ) ) {
            $original_image_url = $parent_job->image_url;
        }
        
        // Create job record
        $job_id = BizCity_Video_Kling_Database::create_job( array(
            'script_id' => $parent_job->script_id,
            'job_key' => 'kling_seg_' . time() . '_' . wp_rand( 1000, 9999 ),
            'prompt' => $parent_job->prompt,
            'image_url' => $original_image_url, // Keep original image for consistency
            'duration' => $segment_duration,
            'aspect_ratio' => $parent_job->aspect_ratio,
            'model' => $parent_job->model,
            'status' => 'draft',
            'progress' => 0,
            'chain_id' => $parent_job->chain_id,
            'parent_job_id' => $parent_job->id,
            'segment_index' => $segment_index,
            'total_segments' => $total_segments,
            'is_final' => $is_final ? 1 : 0,
            'metadata' => json_encode( array_merge( $parent_metadata, array(
                'source' => 'auto_segment',
                'chain_mode' => 'ffmpeg_concat',
                'segment_duration' => $segment_duration,
                'original_image_url' => $original_image_url,
            ) ) ),
        ) );
        
        if ( ! $job_id ) {
            return array( 'success' => false, 'message' => 'Failed to create job record' );
        }
        
        self::add_log( $job_id, sprintf( 
            'New segment job created (Segment %d/%d, Duration: %ds, FFmpeg concat mode)', 
            $segment_index, 
            $total_segments,
            $segment_duration
        ), 'info' );
        
        // Prepare API settings for NEW video generation (not extend)
        $settings = array(
            'api_key' => $api_key,
            'endpoint' => $endpoint,
            'model' => $parent_job->model,
        );
        
        $input = array(
            'prompt' => $parent_job->prompt,
            'duration' => $segment_duration,
            'aspect_ratio' => $parent_job->aspect_ratio,
        );
        
        // Add original image for consistency across segments
        if ( ! empty( $original_image_url ) ) {
            $input['image_url'] = $original_image_url;
            self::add_log( $job_id, 'Using original image for visual consistency', 'info' );
        }
        
        // Enable sound effects if configured in parent
        $with_audio = $parent_metadata['with_audio'] ?? true;
        if ( $with_audio ) {
            $input['with_audio'] = true;
        }
        
        self::add_log( $job_id, 'Calling Kling API (video_generation - NEW segment' . ( ! empty( $original_image_url ) ? ' with image' : '' ) . ')...', 'info' );
        
        $result = waic_kling_create_task( $settings, $input );
        
        if ( ! $result['ok'] ) {
            self::add_log( $job_id, 'API Error: ' . ( $result['error'] ?? 'Unknown' ), 'error' );
            
            BizCity_Video_Kling_Database::update_job( $job_id, array(
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Failed to create video segment',
            ) );
            
            return array( 'success' => false, 'message' => $result['error'] ?? 'API error' );
        }
        
        // Extract task_id
        $data = $result['data'];
        $task_id = $data['task_id'] ?? ( $data['data']['task_id'] ?? null );
        
        if ( ! $task_id ) {
            self::add_log( $job_id, 'Missing task_id in response', 'error' );
            
            BizCity_Video_Kling_Database::update_job( $job_id, array(
                'status' => 'failed',
                'error_message' => 'Missing task_id',
            ) );
            
            return array( 'success' => false, 'message' => 'Missing task_id in response' );
        }
        
        // Update job
        BizCity_Video_Kling_Database::update_job( $job_id, array(
            'task_id' => $task_id,
            'status' => 'queued',
            'progress' => 5,
        ) );
        
        self::add_log( $job_id, 'New segment task created: ' . $task_id, 'success' );
        
        return array(
            'success' => true,
            'job_id' => $job_id,
            'task_id' => $task_id,
        );
    }
    
    /**
     * Create extend job for next segment (DEPRECATED - use create_new_segment_job)
     * Kept for backwards compatibility
     * 
     * @deprecated Use create_new_segment_job instead
     */
    private static function create_extend_job( $parent_job, $segment_index ) {
        // Redirect to new method
        return self::create_new_segment_job( $parent_job, $segment_index );
    }
    
    /**
     * AJAX: Fetch video and upload to Media Library
     */
    public static function ajax_fetch_video() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
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
        
        // Check if already has attachment
        if ( $job->attachment_id ) {
            $media_url = wp_get_attachment_url( $job->attachment_id );
            wp_send_json_success( array(
                'message' => __( 'Already uploaded to Media Library', 'bizcity-video-kling' ),
                'attachment_id' => $job->attachment_id,
                'media_url' => $media_url,
            ) );
            return;
        }
        
        // If no video_url but has task_id, try to fetch from API first
        if ( empty( $job->video_url ) && ! empty( $job->task_id ) ) {
            self::add_log( $job_id, 'No video URL in database, querying API...', 'info' );
            
            $api_key = get_option( 'bizcity_video_kling_api_key', '' );
            $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
            
            $settings = array(
                'api_key' => $api_key,
                'endpoint' => $endpoint,
            );
            
            $result = waic_kling_get_task( $settings, $job->task_id );
            
            if ( $result['ok'] ) {
                $video_url = waic_kling_extract_video_url( $result['data'] );
                if ( $video_url ) {
                    // Update job with video_url
                    BizCity_Video_Kling_Database::update_job( $job_id, array( 'video_url' => $video_url ) );
                    $job->video_url = $video_url;
                    self::add_log( $job_id, sprintf( 'Got video URL: %s', $video_url ), 'success' );
                }
            }
        }
        
        if ( empty( $job->video_url ) ) {
            // Notify Zalo admins that video is still processing
            $urls = self::get_admin_monitor_urls( $job_id, $job->script_id ?? 0 );
            $notify_msg = "🎬 Video đang được tạo (job #{$job_id})\n\n";
            $notify_msg .= "📊 Theo dõi tiến trình tại:\n" . $urls['monitor'] . "\n\n";
            $notify_msg .= "📋 Danh sách kịch bản:\n" . $urls['list'];
            self::notify_zalo_admins_video_status( $job, $notify_msg );

            wp_send_json_error( array( 'message' => __( 'No video URL available', 'bizcity-video-kling' ), 'still_processing' => true ) );
            return;
        }
        
        self::add_log( $job_id, 'Downloading video and uploading to Media Library...', 'info' );
        
        // Download and upload
        $result = self::download_and_upload_video( $job );
        
        if ( ! $result['success'] ) {
            self::add_log( $job_id, sprintf( 'Upload failed: %s', $result['message'] ), 'error' );
            wp_send_json_error( array( 'message' => $result['message'] ) );
            return;
        }
        
        // Update job
        $update_data = array(
            'media_url' => $result['url'],
            'attachment_id' => $result['attachment_id'],
        );
        
        $metadata = json_decode( $job->metadata ?? '{}', true );
        $metadata['wp_attachment_id'] = $result['attachment_id'];
        $metadata['wp_attachment_url'] = $result['url'];
        $metadata['downloaded_at'] = current_time( 'mysql' );
        $update_data['metadata'] = json_encode( $metadata );
        
        BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
        
        self::add_log( $job_id, sprintf( 'Uploaded! Attachment ID: %d', $result['attachment_id'] ), 'success' );
        
        wp_send_json_success( array(
            'message' => __( 'Video uploaded to Media Library', 'bizcity-video-kling' ),
            'attachment_id' => $result['attachment_id'],
            'media_url' => $result['url'],
        ) );
    }
    
    /**
     * AJAX: Fetch all chain videos and upload to Media Library
     */
    public static function ajax_fetch_chain() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $chain_id = sanitize_text_field( $_POST['chain_id'] ?? '' );
        
        if ( empty( $chain_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid chain ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Get all jobs in chain
        $jobs = BizCity_Video_Kling_Database::get_jobs_by_chain( $chain_id );
        
        if ( empty( $jobs ) ) {
            wp_send_json_error( array( 'message' => __( 'No jobs found for this chain', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $results = array();
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        $settings = array( 'api_key' => $api_key, 'endpoint' => $endpoint );
        
        foreach ( $jobs as $job ) {
            // Skip if already has attachment
            if ( $job->attachment_id ) {
                $results[] = array(
                    'job_id' => $job->id,
                    'segment' => $job->segment_index,
                    'is_final' => (bool) $job->is_final,
                    'status' => 'already_uploaded',
                    'attachment_id' => $job->attachment_id,
                    'media_url' => wp_get_attachment_url( $job->attachment_id ),
                );
                continue;
            }
            
            // Skip if not completed
            if ( $job->status !== 'completed' ) {
                $results[] = array(
                    'job_id' => $job->id,
                    'segment' => $job->segment_index,
                    'is_final' => (bool) $job->is_final,
                    'status' => 'skipped',
                    'reason' => 'Job not completed (status: ' . $job->status . ')',
                );
                continue;
            }
            
            // Try to get video_url from API if not available
            if ( empty( $job->video_url ) && ! empty( $job->task_id ) ) {
                $api_result = waic_kling_get_task( $settings, $job->task_id );
                if ( $api_result['ok'] ) {
                    $video_url = waic_kling_extract_video_url( $api_result['data'] );
                    if ( $video_url ) {
                        BizCity_Video_Kling_Database::update_job( $job->id, array( 'video_url' => $video_url ) );
                        $job->video_url = $video_url;
                    }
                }
            }
            
            if ( empty( $job->video_url ) ) {
                $results[] = array(
                    'job_id' => $job->id,
                    'segment' => $job->segment_index,
                    'is_final' => (bool) $job->is_final,
                    'status' => 'error',
                    'reason' => 'No video URL available',
                );
                continue;
            }
            
            // Download and upload
            $download_result = self::download_and_upload_video( $job );
            
            if ( $download_result['success'] ) {
                // Update job
                $update_data = array(
                    'media_url' => $download_result['url'],
                    'attachment_id' => $download_result['attachment_id'],
                );
                
                $metadata = json_decode( $job->metadata ?? '{}', true );
                $metadata['wp_attachment_id'] = $download_result['attachment_id'];
                $metadata['wp_attachment_url'] = $download_result['url'];
                $metadata['downloaded_at'] = current_time( 'mysql' );
                $update_data['metadata'] = json_encode( $metadata );
                
                BizCity_Video_Kling_Database::update_job( $job->id, $update_data );
                
                $results[] = array(
                    'job_id' => $job->id,
                    'segment' => $job->segment_index,
                    'is_final' => (bool) $job->is_final,
                    'status' => 'uploaded',
                    'attachment_id' => $download_result['attachment_id'],
                    'media_url' => $download_result['url'],
                );
            } else {
                $results[] = array(
                    'job_id' => $job->id,
                    'segment' => $job->segment_index,
                    'is_final' => (bool) $job->is_final,
                    'status' => 'error',
                    'reason' => $download_result['message'],
                );
            }
        }
        
        // Check if all completed jobs were uploaded
        $uploaded = array_filter( $results, function( $r ) {
            return in_array( $r['status'], array( 'uploaded', 'already_uploaded' ) );
        } );
        
        // Find final video
        $final_video = array_filter( $results, function( $r ) {
            return $r['is_final'] && in_array( $r['status'], array( 'uploaded', 'already_uploaded' ) );
        } );
        $final_video = reset( $final_video );
        
        wp_send_json_success( array(
            'message' => sprintf( __( 'Processed %d/%d videos', 'bizcity-video-kling' ), count( $uploaded ), count( $jobs ) ),
            'results' => $results,
            'final_video' => $final_video ?: null,
            'chain_id' => $chain_id,
        ) );
    }
    
    /**
     * AJAX: Fetch all chain videos, concat with FFmpeg, add TTS, then upload
     * This is the main method for processing multi-segment videos with voiceover
     */
    public static function ajax_fetch_chain_with_tts() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $chain_id = sanitize_text_field( $_POST['chain_id'] ?? '' );
        $tts_options = array(
            'voice'  => sanitize_text_field( $_POST['tts_voice'] ?? 'nova' ),
            'model'  => sanitize_text_field( $_POST['tts_model'] ?? 'tts-1-hd' ),
            'speed'  => floatval( $_POST['tts_speed'] ?? 1.0 ),
        );
        $custom_text = sanitize_textarea_field( $_POST['custom_text'] ?? '' );
        $ffmpeg_preset = sanitize_text_field( $_POST['ffmpeg_preset'] ?? '' );
        
        if ( empty( $chain_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid chain ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Get all jobs in chain, ordered by segment_index
        $jobs = BizCity_Video_Kling_Database::get_jobs_by_chain( $chain_id );
        
        if ( empty( $jobs ) ) {
            wp_send_json_error( array( 'message' => __( 'No jobs found for this chain', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Sort by segment_index
        usort( $jobs, function( $a, $b ) {
            return (int) $a->segment_index - (int) $b->segment_index;
        } );
        
        // Find the final job for logging
        $final_job = null;
        foreach ( $jobs as $job ) {
            if ( $job->is_final ) {
                $final_job = $job;
                break;
            }
        }
        $log_job_id = $final_job ? $final_job->id : $jobs[0]->id;
        
        self::add_log( $log_job_id, sprintf( 
            'Starting chain concat + TTS processing for %d segments...', 
            count( $jobs ) 
        ), 'info' );
        
        // Check FFmpeg availability
        if ( ! class_exists( 'BizCity_Video_Kling_FFmpeg_Presets' ) ) {
            wp_send_json_error( array( 'message' => 'FFmpeg Presets class not available' ) );
            return;
        }
        
        $ffmpeg_status = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
        if ( ! $ffmpeg_status['available'] ) {
            self::add_log( $log_job_id, 'FFmpeg not available: ' . ( $ffmpeg_status['error'] ?? 'Unknown' ), 'error' );
            wp_send_json_error( array( 'message' => 'FFmpeg not available' ) );
            return;
        }
        
        self::add_log( $log_job_id, sprintf( 'FFmpeg available: %s', $ffmpeg_status['version'] ), 'info' );
        
        // Create temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/bizcity-kling-temp/';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }
        
        // Step 1: Download all segment videos
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        $settings = array( 'api_key' => $api_key, 'endpoint' => $endpoint );
        
        $video_paths = array();
        $prompt_text = '';
        
        foreach ( $jobs as $index => $job ) {
            if ( $job->status !== 'completed' ) {
                self::add_log( $log_job_id, sprintf( 
                    'Segment %d not completed (status: %s), skipping', 
                    $job->segment_index, 
                    $job->status 
                ), 'warning' );
                continue;
            }
            
            // Get video URL if not available
            if ( empty( $job->video_url ) && ! empty( $job->task_id ) ) {
                $api_result = waic_kling_get_task( $settings, $job->task_id );
                if ( $api_result['ok'] ) {
                    $video_url = waic_kling_extract_video_url( $api_result['data'] );
                    if ( $video_url ) {
                        BizCity_Video_Kling_Database::update_job( $job->id, array( 'video_url' => $video_url ) );
                        $job->video_url = $video_url;
                    }
                }
            }
            
            if ( empty( $job->video_url ) ) {
                self::add_log( $log_job_id, sprintf( 'Segment %d has no video URL', $job->segment_index ), 'error' );
                continue;
            }
            
            // Download segment to temp file
            self::add_log( $log_job_id, sprintf( 
                'Downloading segment %d/%d...', 
                $job->segment_index, 
                count( $jobs ) 
            ), 'info' );
            
            $temp_path = $temp_dir . 'seg_' . $chain_id . '_' . $job->segment_index . '_' . time() . '.mp4';
            
            $response = wp_remote_get( $job->video_url, array( 'timeout' => 300 ) );
            
            if ( is_wp_error( $response ) ) {
                self::add_log( $log_job_id, sprintf( 
                    'Failed to download segment %d: %s', 
                    $job->segment_index, 
                    $response->get_error_message() 
                ), 'error' );
                continue;
            }
            
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                self::add_log( $log_job_id, sprintf( 
                    'HTTP error %d downloading segment %d', 
                    $http_code, 
                    $job->segment_index 
                ), 'error' );
                continue;
            }
            
            $video_content = wp_remote_retrieve_body( $response );
            if ( empty( $video_content ) ) {
                continue;
            }
            
            file_put_contents( $temp_path, $video_content );
            $video_paths[] = $temp_path;
            
            self::add_log( $log_job_id, sprintf( 
                'Segment %d downloaded (%d bytes)', 
                $job->segment_index, 
                strlen( $video_content ) 
            ), 'success' );
            
            // Upload shot (segment) to R2
            $r2_uploader = new BizCity_Video_Kling_R2_Uploader();
            if ( $r2_uploader->is_available() ) {
                $shot_r2_result = $r2_uploader->upload_shot( 
                    $temp_path, 
                    $chain_id, 
                    (int) $job->segment_index 
                );
                
                if ( $shot_r2_result['success'] ) {
                    self::add_log( $log_job_id, sprintf( 
                        'Segment %d uploaded to R2: %s', 
                        $job->segment_index, 
                        $shot_r2_result['url'] 
                    ), 'success' );
                    
                    // Update job metadata with R2 URL
                    $job_meta = json_decode( $job->metadata ?? '{}', true );
                    $job_meta['r2_shot_url'] = $shot_r2_result['url'];
                    $job_meta['r2_shot_key'] = $shot_r2_result['key'];
                    BizCity_Video_Kling_Database::update_job( $job->id, array(
                        'metadata' => json_encode( $job_meta ),
                    ) );
                } else {
                    self::add_log( $log_job_id, sprintf( 
                        'Failed to upload segment %d to R2: %s', 
                        $job->segment_index, 
                        $shot_r2_result['error'] ?? 'Unknown' 
                    ), 'warning' );
                }
            }
            
            // Get prompt from first segment for TTS
            if ( empty( $prompt_text ) && ! empty( $job->prompt ) ) {
                $prompt_text = $job->prompt;
            }
        }
        
        if ( count( $video_paths ) < 1 ) {
            wp_send_json_error( array( 'message' => 'No valid video segments to concat' ) );
            return;
        }
        
        // Step 2: Concat all videos using FFmpeg
        self::add_log( $log_job_id, sprintf( 
            'Concatenating %d video segments with FFmpeg...', 
            count( $video_paths ) 
        ), 'info' );
        
        $concat_output = $temp_dir . 'concat_' . $chain_id . '_' . time() . '.mp4';
        
        // Get aspect ratio from first job for scaling
        $aspect_ratio = $jobs[0]->aspect_ratio ?? '9:16';
        $dimensions = self::get_dimensions_from_aspect_ratio( $aspect_ratio );
        
        $concat_result = BizCity_Video_Kling_FFmpeg_Presets::concat_videos( 
            $video_paths, 
            $concat_output,
            array(
                'reencode' => true,
                'scale' => $dimensions['width'] . ':' . $dimensions['height'],
                'fps' => 30,
            )
        );
        
        // Clean up segment temp files
        foreach ( $video_paths as $path ) {
            @unlink( $path );
        }
        
        if ( ! $concat_result['success'] || ! file_exists( $concat_output ) ) {
            self::add_log( $log_job_id, 'FFmpeg concat failed: ' . ( $concat_result['error'] ?? 'Unknown' ), 'error' );
            wp_send_json_error( array( 'message' => 'FFmpeg concat failed: ' . ( $concat_result['error'] ?? 'Unknown' ) ) );
            return;
        }
        
        self::add_log( $log_job_id, 'Video segments concatenated successfully!', 'success' );
        
        $final_video_path = $concat_output;
        $tts_used = false;
        $audio_path = null;
        
        // Step 3: Generate TTS audio and merge if enabled
        $tts_text = ! empty( $custom_text ) ? $custom_text : $prompt_text;
        
        if ( ! empty( $tts_text ) && class_exists( 'BizCity_Video_Kling_OpenAI_TTS' ) && BizCity_Video_Kling_OpenAI_TTS::is_configured() ) {
            self::add_log( $log_job_id, sprintf( 
                'Generating TTS audio (Voice: %s, Model: %s)...', 
                $tts_options['voice'], 
                $tts_options['model'] 
            ), 'info' );
            
            $tts_result = BizCity_Video_Kling_OpenAI_TTS::generate_voiceover(
                $tts_text,
                $log_job_id,
                $tts_options
            );
            
            if ( $tts_result['success'] && ! empty( $tts_result['path'] ) ) {
                $audio_path = $tts_result['path'];
                self::add_log( $log_job_id, 'TTS audio generated!', 'success' );
                
                // Merge audio with concatenated video
                self::add_log( $log_job_id, 'Merging video with TTS audio...', 'info' );
                
                $merge_output = $temp_dir . 'merged_' . $chain_id . '_' . time() . '.mp4';
                
                $merge_options = array(
                    'video_volume' => 0.0, // Mute original video audio
                    'audio_volume' => 1.0,
                );
                
                // Apply FFmpeg preset if specified
                if ( ! empty( $ffmpeg_preset ) ) {
                    $preset_filters = self::get_preset_filters( $ffmpeg_preset, $final_job ?? $jobs[0] );
                    if ( ! empty( $preset_filters ) ) {
                        $merge_options['additional_filters'] = $preset_filters;
                        $merge_options['video_codec'] = 'libx264';
                    }
                }
                
                $merge_result = BizCity_Video_Kling_FFmpeg_Presets::merge_video_audio(
                    $concat_output,
                    $audio_path,
                    $merge_output,
                    $merge_options
                );
                
                if ( $merge_result['success'] && file_exists( $merge_output ) ) {
                    // Clean up concat output
                    @unlink( $concat_output );
                    $final_video_path = $merge_output;
                    $tts_used = true;
                    self::add_log( $log_job_id, 'Video + TTS audio merged successfully!', 'success' );
                } else {
                    self::add_log( $log_job_id, 'Audio merge failed: ' . ( $merge_result['error'] ?? 'Unknown' ), 'warning' );
                }
            } else {
                self::add_log( $log_job_id, 'TTS generation failed: ' . ( $tts_result['error'] ?? 'Unknown' ), 'warning' );
            }
        } else {
            self::add_log( $log_job_id, 'TTS not enabled or not configured, skipping voiceover', 'info' );
        }
        
        // Step 4: Upload final video to R2
        $r2_final_url = null;
        $r2_final_key = null;
        $r2_uploader = new BizCity_Video_Kling_R2_Uploader();
        
        if ( $r2_uploader->is_available() ) {
            self::add_log( $log_job_id, 'Uploading final video to R2...', 'info' );
            
            $r2_result = $r2_uploader->upload_final( $final_video_path, $chain_id );
            
            if ( $r2_result['success'] ) {
                $r2_final_url = $r2_result['url'];
                $r2_final_key = $r2_result['key'];
                self::add_log( $log_job_id, sprintf( 
                    'Final video uploaded to R2: %s', 
                    $r2_final_url 
                ), 'success' );
            } else {
                self::add_log( $log_job_id, 'Failed to upload final to R2: ' . ( $r2_result['error'] ?? 'Unknown' ), 'warning' );
            }
        }
        
        // Step 5: Upload final video to WordPress Media Library
        self::add_log( $log_job_id, 'Uploading final video to Media Library...', 'info' );
        
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        $final_content = file_get_contents( $final_video_path );
        
        // Clean up temp files
        @unlink( $final_video_path );
        if ( $audio_path ) {
            @unlink( $audio_path );
        }
        
        if ( empty( $final_content ) ) {
            wp_send_json_error( array( 'message' => 'Final video is empty' ) );
            return;
        }
        
        $tts_suffix = $tts_used ? '-tts' : '';
        $filename = 'bizcity-chain-' . $chain_id . $tts_suffix . '-' . time() . '.mp4';
        
        $upload = wp_upload_bits( $filename, null, $final_content );
        
        if ( $upload['error'] ) {
            self::add_log( $log_job_id, 'Upload failed: ' . $upload['error'], 'error' );
            wp_send_json_error( array( 'message' => $upload['error'] ) );
            return;
        }
        
        // Create attachment
        $title = sprintf( 
            'BizCity Chain Video - %d segments%s', 
            count( $jobs ),
            $tts_used ? ' [TTS]' : ''
        );
        
        if ( ! empty( $prompt_text ) ) {
            $title .= ' - ' . wp_trim_words( $prompt_text, 5, '...' );
        }
        
        $attachment = array(
            'post_mime_type' => 'video/mp4',
            'post_title' => $title,
            'post_content' => $prompt_text,
            'post_status' => 'inherit',
        );
        
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
            return;
        }
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );
        
        $final_url = wp_get_attachment_url( $attachment_id );
        
        // Update all jobs in chain with the final attachment info
        foreach ( $jobs as $job ) {
            $job_metadata = json_decode( $job->metadata ?? '{}', true );
            $job_metadata['chain_attachment_id'] = $attachment_id;
            $job_metadata['chain_attachment_url'] = $final_url;
            $job_metadata['chain_processed_at'] = current_time( 'mysql' );
            $job_metadata['tts_used'] = $tts_used;
            
            // Add R2 final URL if available
            if ( $r2_final_url ) {
                $job_metadata['r2_final_url'] = $r2_final_url;
                $job_metadata['r2_final_key'] = $r2_final_key;
            }
            
            BizCity_Video_Kling_Database::update_job( $job->id, array(
                'metadata' => json_encode( $job_metadata ),
            ) );
            
            // Update final job with attachment
            if ( $job->is_final ) {
                BizCity_Video_Kling_Database::update_job( $job->id, array(
                    'attachment_id' => $attachment_id,
                    'media_url' => $final_url,
                ) );
            }
        }
        
        self::add_log( $log_job_id, sprintf( 
            'Chain video uploaded! Attachment ID: %d%s', 
            $attachment_id,
            $r2_final_url ? ' | R2: ' . $r2_final_url : ''
        ), 'success' );
        
        wp_send_json_success( array(
            'message' => sprintf( 
                __( 'Chain video processed: %d segments concatenated%s', 'bizcity-video-kling' ), 
                count( $jobs ),
                $tts_used ? ' + TTS' : ''
            ),
            'attachment_id' => $attachment_id,
            'media_url' => $final_url,
            'r2_url' => $r2_final_url,
            'r2_key' => $r2_final_key,
            'tts_used' => $tts_used,
            'chain_id' => $chain_id,
            'segments' => count( $jobs ),
        ) );
    }
    
    /**
     * Download video and upload to WordPress Media Library
     */
    private static function download_and_upload_video( $job ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        $video_url = $job->video_url;
        
        // Build filename with bizcity-video- prefix
        $segment_suffix = '';
        if ( ! empty( $job->chain_id ) && $job->total_segments > 1 ) {
            $segment_suffix = '-seg' . $job->segment_index;
            if ( $job->is_final ) {
                $segment_suffix .= '-final';
            }
        }
        $filename = 'bizcity-video-' . $job->id . $segment_suffix . '-' . time() . '.mp4';
        
        self::add_log( $job->id, sprintf( 'Downloading from: %s', $video_url ), 'info' );
        
        // Download video - PiAPI may or may not require auth
        $response = wp_remote_get( $video_url, array(
            'timeout' => 300,
            'headers' => array(),
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }
        
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            return array(
                'success' => false,
                'message' => sprintf( 'HTTP error %d', $http_code ),
            );
        }
        
        $video_content = wp_remote_retrieve_body( $response );
        
        if ( empty( $video_content ) ) {
            return array(
                'success' => false,
                'message' => __( 'Downloaded video is empty', 'bizcity-video-kling' ),
            );
        }
        
        self::add_log( $job->id, sprintf( 'Downloaded %d bytes', strlen( $video_content ) ), 'info' );
        
        // Upload to WordPress
        $upload = wp_upload_bits( $filename, null, $video_content );
        
        if ( $upload['error'] ) {
            return array(
                'success' => false,
                'message' => $upload['error'],
            );
        }
        
        // Create attachment title
        $title_parts = array( 'Bizcity Video', '#' . $job->id );
        
        // Add segment info for chains
        if ( ! empty( $job->chain_id ) && $job->total_segments > 1 ) {
            $title_parts[] = sprintf( 'Seg %d/%d', $job->segment_index, $job->total_segments );
            if ( $job->is_final ) {
                $title_parts[] = '(Final)';
            }
        }
        
        $title = implode( ' ', $title_parts );
        
        if ( ! empty( $job->prompt ) ) {
            $title .= ' - ' . wp_trim_words( $job->prompt, 5, '...' );
        }
        
        $attachment = array(
            'post_mime_type' => 'video/mp4',
            'post_title' => $title,
            'post_content' => $job->prompt ?? '',
            'post_status' => 'inherit',
        );
        
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        
        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'success' => false,
                'message' => $attachment_id->get_error_message(),
            );
        }
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );
        
        $final_url = wp_get_attachment_url( $attachment_id );
        
        return array(
            'success' => true,
            'path' => $upload['file'],
            'url' => $final_url,
            'attachment_id' => $attachment_id,
        );
    }
    
    /**
     * AJAX: Fetch video with TTS audio and FFmpeg processing
     * Downloads video → Generate TTS → Merge with FFmpeg → Upload to Media Library
     */
    public static function ajax_fetch_video_with_tts() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        $tts_options = array(
            'voice'  => sanitize_text_field( $_POST['tts_voice'] ?? 'nova' ),
            'model'  => sanitize_text_field( $_POST['tts_model'] ?? 'tts-1-hd' ),
            'speed'  => floatval( $_POST['tts_speed'] ?? 1.0 ),
        );
        $ffmpeg_preset = sanitize_text_field( $_POST['ffmpeg_preset'] ?? '' );
        $custom_text = sanitize_textarea_field( $_POST['custom_text'] ?? '' );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Check if already has attachment
        if ( $job->attachment_id ) {
            $media_url = wp_get_attachment_url( $job->attachment_id );
            wp_send_json_success( array(
                'message' => __( 'Already uploaded to Media Library', 'bizcity-video-kling' ),
                'attachment_id' => $job->attachment_id,
                'media_url' => $media_url,
            ) );
            return;
        }
        
        // Get video URL from API if not available
        if ( empty( $job->video_url ) && ! empty( $job->task_id ) ) {
            self::add_log( $job_id, 'No video URL in database, querying API...', 'info' );
            
            $api_key = get_option( 'bizcity_video_kling_api_key', '' );
            $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
            
            $settings = array( 'api_key' => $api_key, 'endpoint' => $endpoint );
            $result = waic_kling_get_task( $settings, $job->task_id );
            
            if ( $result['ok'] ) {
                $video_url = waic_kling_extract_video_url( $result['data'] );
                if ( $video_url ) {
                    BizCity_Video_Kling_Database::update_job( $job_id, array( 'video_url' => $video_url ) );
                    $job->video_url = $video_url;
                    self::add_log( $job_id, sprintf( 'Got video URL: %s', $video_url ), 'success' );
                }
            }
        }
        
        if ( empty( $job->video_url ) ) {
            // Notify Zalo admins that video is still processing
            $urls = self::get_admin_monitor_urls( $job_id, $job->script_id ?? 0 );
            $notify_msg = "🎬 Video đang được tạo (job #{$job_id})\n\n";
            $notify_msg .= "📊 Theo dõi tiến trình tại:\n" . $urls['monitor'] . "\n\n";
            $notify_msg .= "📋 Danh sách kịch bản:\n" . $urls['list'];
            self::notify_zalo_admins_video_status( $job, $notify_msg );

            wp_send_json_error( array( 'message' => __( 'No video URL available', 'bizcity-video-kling' ), 'still_processing' => true ) );
            return;
        }
        
        self::add_log( $job_id, 'Starting video processing with TTS...', 'info' );
        
        // Call the new processing method
        $result = self::download_process_and_upload_video( $job, array(
            'enable_tts'    => true,
            'tts_options'   => $tts_options,
            'custom_text'   => $custom_text,
            'ffmpeg_preset' => $ffmpeg_preset,
        ) );
        
        if ( ! $result['success'] ) {
            self::add_log( $job_id, sprintf( 'Processing failed: %s', $result['message'] ), 'error' );
            wp_send_json_error( array( 'message' => $result['message'] ) );
            return;
        }
        
        // Update job
        $update_data = array(
            'media_url' => $result['url'],
            'attachment_id' => $result['attachment_id'],
        );
        
        $metadata = json_decode( $job->metadata ?? '{}', true );
        $metadata['wp_attachment_id'] = $result['attachment_id'];
        $metadata['wp_attachment_url'] = $result['url'];
        $metadata['downloaded_at'] = current_time( 'mysql' );
        $metadata['tts_enabled'] = true;
        $metadata['tts_options'] = $tts_options;
        $metadata['ffmpeg_preset'] = $ffmpeg_preset;
        if ( ! empty( $result['audio_path'] ) ) {
            $metadata['audio_path'] = $result['audio_path'];
        }
        // Add R2 URL if available
        if ( ! empty( $result['r2_url'] ) ) {
            $metadata['r2_url'] = $result['r2_url'];
            $metadata['r2_key'] = $result['r2_key'];
        }
        $update_data['metadata'] = json_encode( $metadata );
        
        BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
        
        self::add_log( $job_id, sprintf( 
            'Uploaded! Attachment ID: %d%s', 
            $result['attachment_id'],
            ! empty( $result['r2_url'] ) ? ' | R2: ' . $result['r2_url'] : ''
        ), 'success' );
        
        wp_send_json_success( array(
            'message' => __( 'Video processed and uploaded to Media Library', 'bizcity-video-kling' ),
            'attachment_id' => $result['attachment_id'],
            'media_url' => $result['url'],
            'r2_url' => $result['r2_url'] ?? null,
            'r2_key' => $result['r2_key'] ?? null,
            'tts_used' => $result['tts_used'] ?? false,
            'ffmpeg_used' => $result['ffmpeg_used'] ?? false,
        ) );
    }
    
    /**
     * Download video, process with TTS + FFmpeg, then upload to WordPress
     * Supports checkpoint-based resume if previous attempt failed mid-process
     * 
     * @param object $job Job object
     * @param array  $options Processing options
     * @return array Result
     */
    private static function download_process_and_upload_video( $job, $options = array() ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        $defaults = array(
            'enable_tts'    => false,
            'tts_options'   => array(),
            'custom_text'   => '',
            'ffmpeg_preset' => '',
        );
        $options = wp_parse_args( $options, $defaults );
        
        $video_url = $job->video_url;
        $job_id = $job->id;
        
        // Get resume point - check what steps are already completed
        $resume = BizCity_Video_Kling_Database::get_resume_point( $job_id );
        $temp_files = $resume['temp_files'];
        
        self::add_log( $job_id, sprintf( 'Resume point: %s', $resume['step'] ), 'info' );
        
        // Create temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/bizcity-kling-temp/';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }
        
        $result = array(
            'success' => false,
            'tts_used' => false,
            'ffmpeg_used' => false,
        );
        
        // Initialize paths from previous run or create new
        $temp_video_path = $temp_files['video_path'] ?? null;
        $audio_path = $temp_files['audio_path'] ?? null;
        $merged_video_path = $temp_files['merged_path'] ?? null;
        
        // ========================================
        // Step 1: Download video (skip if already done)
        // ========================================
        if ( ! BizCity_Video_Kling_Database::has_checkpoint( $job_id, 'video_fetched' ) ) {
            self::add_log( $job_id, sprintf( 'Downloading from: %s', $video_url ), 'info' );
            
            $temp_video_filename = 'temp_video_' . $job_id . '_' . time() . '.mp4';
            $temp_video_path = $temp_dir . $temp_video_filename;
            
            $response = wp_remote_get( $video_url, array(
                'timeout' => 300,
                'headers' => array(),
            ) );
            
            if ( is_wp_error( $response ) ) {
                return array(
                    'success' => false,
                    'message' => $response->get_error_message(),
                );
            }
            
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                return array(
                    'success' => false,
                    'message' => sprintf( 'HTTP error %d', $http_code ),
                );
            }
            
            $video_content = wp_remote_retrieve_body( $response );
            
            if ( empty( $video_content ) ) {
                return array(
                    'success' => false,
                    'message' => __( 'Downloaded video is empty', 'bizcity-video-kling' ),
                );
            }
            
            // Save to temp file
            file_put_contents( $temp_video_path, $video_content );
            self::add_log( $job_id, sprintf( 'Downloaded %d bytes to temp', strlen( $video_content ) ), 'info' );
            
            // Save checkpoint
            BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'video_fetched', array( 'size' => strlen( $video_content ) ) );
            BizCity_Video_Kling_Database::save_temp_files( $job_id, array( 'video_path' => $temp_video_path ) );
        } else {
            self::add_log( $job_id, '✓ Video already downloaded, skipping...', 'info' );
            
            // Verify file still exists
            if ( ! file_exists( $temp_video_path ) ) {
                self::add_log( $job_id, 'Temp video file missing, re-downloading...', 'warning' );
                // Clear checkpoint and re-run
                BizCity_Video_Kling_Database::update_job( $job_id, array( 
                    'checkpoints' => wp_json_encode( array() ),
                    'temp_files' => wp_json_encode( array() ),
                ) );
                return self::download_process_and_upload_video( $job, $options );
            }
        }
        
        $final_video_path = $temp_video_path;
        
        // ========================================
        // Step 2: Generate TTS audio (skip if already done)
        // ========================================
        if ( $options['enable_tts'] && class_exists( 'BizCity_Video_Kling_OpenAI_TTS' ) ) {
            if ( ! BizCity_Video_Kling_Database::has_checkpoint( $job_id, 'tts_generated' ) ) {
                if ( ! BizCity_Video_Kling_OpenAI_TTS::is_configured() ) {
                    self::add_log( $job_id, 'TTS API key not configured (twf_openai_api_key), skipping TTS', 'warning' );
                } else {
                    // Use custom text or job prompt
                    $tts_text = ! empty( $options['custom_text'] ) ? $options['custom_text'] : $job->prompt;
                    
                    if ( ! empty( $tts_text ) ) {
                        self::add_log( $job_id, 'Generating TTS audio...', 'info' );
                        
                        $tts_result = BizCity_Video_Kling_OpenAI_TTS::generate_voiceover(
                            $tts_text,
                            $job_id,
                            $options['tts_options']
                        );
                        
                        if ( $tts_result['success'] ) {
                            $audio_path = $tts_result['path'];
                            $result['audio_path'] = $audio_path;
                            $result['tts_used'] = true;
                            self::add_log( $job_id, sprintf( 'TTS generated: %s', $audio_path ), 'success' );
                            
                            // Save checkpoint
                            BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'tts_generated', array( 'path' => $audio_path ) );
                            BizCity_Video_Kling_Database::save_temp_files( $job_id, array( 'audio_path' => $audio_path ) );
                        } else {
                            self::add_log( $job_id, 'TTS failed: ' . ( $tts_result['error'] ?? 'Unknown' ), 'error' );
                        }
                    }
                }
            } else {
                self::add_log( $job_id, '✓ TTS already generated, skipping...', 'info' );
                $result['tts_used'] = true;
                $result['audio_path'] = $audio_path;
            }
        }
        
        // ========================================
        // Step 3: Merge video + audio with FFmpeg (skip if already done)
        // ========================================
        if ( $audio_path && file_exists( $audio_path ) && class_exists( 'BizCity_Video_Kling_FFmpeg_Presets' ) ) {
            if ( ! BizCity_Video_Kling_Database::has_checkpoint( $job_id, 'audio_merged' ) ) {
                // Check FFmpeg availability
                $ffmpeg_status = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
                
                if ( ! $ffmpeg_status['available'] ) {
                    self::add_log( $job_id, 'FFmpeg not available: ' . ( $ffmpeg_status['error'] ?? 'Unknown' ), 'warning' );
                    self::add_log( $job_id, 'Uploading video without audio merge', 'warning' );
                } else {
                    self::add_log( $job_id, sprintf( 'Merging video + audio using FFmpeg (%s)...', $ffmpeg_status['version'] ), 'info' );
                    
                    // Build FFmpeg options
                    $ffmpeg_options = array(
                        'video_volume' => 0.0, // Mute original video audio
                        'audio_volume' => 1.0,
                    );
                    
                    // Apply preset filters if specified
                    if ( ! empty( $options['ffmpeg_preset'] ) ) {
                        $additional_filters = self::get_preset_filters( $options['ffmpeg_preset'], $job );
                        if ( ! empty( $additional_filters ) ) {
                            $ffmpeg_options['additional_filters'] = $additional_filters;
                            $ffmpeg_options['video_codec'] = 'libx264'; // Need re-encode for filters
                        }
                    }
                    
                    // Output path
                    $merged_video_path = $temp_dir . 'merged_' . $job_id . '_' . time() . '.mp4';
                    
                    // Execute merge
                    $merge_result = BizCity_Video_Kling_FFmpeg_Presets::merge_video_audio(
                        $temp_video_path,
                        $audio_path,
                        $merged_video_path,
                        $ffmpeg_options
                    );
                    
                    if ( $merge_result['success'] && file_exists( $merged_video_path ) ) {
                        $final_video_path = $merged_video_path;
                        $result['ffmpeg_used'] = true;
                        self::add_log( $job_id, 'Video + audio merged successfully!', 'success' );
                        
                        // Save checkpoint
                        BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'audio_merged', array( 'path' => $merged_video_path ) );
                        BizCity_Video_Kling_Database::save_temp_files( $job_id, array( 'merged_path' => $merged_video_path ) );
                    } else {
                        self::add_log( $job_id, 'FFmpeg merge failed: ' . ( $merge_result['error'] ?? 'Unknown' ), 'error' );
                        return array(
                            'success' => false,
                            'message' => 'FFmpeg merge failed: ' . ( $merge_result['error'] ?? 'Unknown' ),
                            'can_resume' => true,
                            'resume_step' => 'audio_merged',
                        );
                    }
                }
            } else {
                self::add_log( $job_id, '✓ Audio already merged, skipping...', 'info' );
                $final_video_path = $merged_video_path;
                $result['ffmpeg_used'] = true;
                
                // Verify file still exists
                if ( ! file_exists( $merged_video_path ) ) {
                    self::add_log( $job_id, 'Merged video file missing, need to re-merge...', 'warning' );
                    // Clear only the audio_merged checkpoint
                    $checkpoints = BizCity_Video_Kling_Database::get_checkpoints( $job_id );
                    unset( $checkpoints['audio_merged'] );
                    BizCity_Video_Kling_Database::update_job( $job_id, array( 
                        'checkpoints' => wp_json_encode( $checkpoints ) 
                    ) );
                    return self::download_process_and_upload_video( $job, $options );
                }
            }
        }
        
        // ========================================
        // Step 4: Upload to R2 if available
        // ========================================
        $r2_url = null;
        $r2_key = null;
        $r2_uploader = new BizCity_Video_Kling_R2_Uploader();
        
        if ( $r2_uploader->is_available() ) {
            self::add_log( $job_id, 'Uploading video to R2...', 'info' );
            
            $r2_result = $r2_uploader->upload_file( 
                $final_video_path, 
                'video/mp4', 
                array(
                    'job_id' => $job_id,
                    'type' => 'single',
                )
            );
            
            if ( $r2_result['success'] ) {
                $r2_url = $r2_result['url'];
                $r2_key = $r2_result['key'];
                $result['r2_url'] = $r2_url;
                $result['r2_key'] = $r2_key;
                self::add_log( $job_id, sprintf( 'Video uploaded to R2: %s', $r2_url ), 'success' );
            } else {
                self::add_log( $job_id, 'Failed to upload to R2: ' . ( $r2_result['error'] ?? 'Unknown' ), 'warning' );
            }
        }
        
        // Step 5: Upload final video to WordPress
        self::add_log( $job_id, 'Uploading to Media Library...', 'info' );
        
        // Build filename
        $segment_suffix = '';
        if ( ! empty( $job->chain_id ) && $job->total_segments > 1 ) {
            $segment_suffix = '-seg' . $job->segment_index;
            if ( $job->is_final ) {
                $segment_suffix .= '-final';
            }
        }
        
        $tts_suffix = $result['tts_used'] ? '-tts' : '';
        $filename = 'bizcity-video-' . $job_id . $segment_suffix . $tts_suffix . '-' . time() . '.mp4';
        
        // Read final video content
        $final_video_content = file_get_contents( $final_video_path );
        
        if ( empty( $final_video_content ) ) {
            // Cleanup temp files
            self::cleanup_temp_files( $temp_dir, $job_id );
            return array(
                'success' => false,
                'message' => __( 'Final video is empty', 'bizcity-video-kling' ),
            );
        }
        
        // Upload to WordPress
        $upload = wp_upload_bits( $filename, null, $final_video_content );
        
        // Cleanup temp files
        self::cleanup_temp_files( $temp_dir, $job_id );
        
        if ( $upload['error'] ) {
            return array(
                'success' => false,
                'message' => $upload['error'],
            );
        }
        
        // Create attachment title
        $title_parts = array( 'Bizcity Video', '#' . $job_id );
        
        if ( ! empty( $job->chain_id ) && $job->total_segments > 1 ) {
            $title_parts[] = sprintf( 'Seg %d/%d', $job->segment_index, $job->total_segments );
            if ( $job->is_final ) {
                $title_parts[] = '(Final)';
            }
        }
        
        if ( $result['tts_used'] ) {
            $title_parts[] = '[TTS]';
        }
        
        $title = implode( ' ', $title_parts );
        
        if ( ! empty( $job->prompt ) ) {
            $title .= ' - ' . wp_trim_words( $job->prompt, 5, '...' );
        }
        
        $attachment = array(
            'post_mime_type' => 'video/mp4',
            'post_title' => $title,
            'post_content' => $job->prompt ?? '',
            'post_status' => 'inherit',
        );
        
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        
        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'success' => false,
                'message' => $attachment_id->get_error_message(),
            );
        }
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );
        
        $final_url = wp_get_attachment_url( $attachment_id );
        
        // Save checkpoint - upload completed
        BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'media_uploaded', array(
            'attachment_id' => $attachment_id,
            'url'           => $final_url,
            'r2_url'        => $r2_url,
        ) );
        
        // Clear temp files tracking since we're done
        BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'cleanup_done', array() );
        
        $result['success'] = true;
        $result['path'] = $upload['file'];
        $result['url'] = $final_url;
        $result['attachment_id'] = $attachment_id;
        
        return $result;
    }
    
    /**
     * Get FFmpeg filter string from preset name
     * 
     * @param string $preset Preset name
     * @param object $job    Job object for context
     * @return string FFmpeg filter string
     */
    private static function get_preset_filters( $preset, $job ) {
        if ( ! class_exists( 'BizCity_Video_Kling_FFmpeg_Presets' ) ) {
            return '';
        }
        
        // Parse aspect ratio for dimensions
        $dimensions = self::get_dimensions_from_aspect_ratio( $job->aspect_ratio ?? '9:16' );
        
        switch ( $preset ) {
            case 'lower_third':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_lower_third( array(
                    'title'        => wp_trim_words( $job->prompt, 5, '...' ),
                    'subtitle'     => 'Generated by BizCity',
                    'width'        => $dimensions['width'],
                    'video_height' => $dimensions['height'],
                    'start_time'   => 1,
                    'duration'     => min( 5, (int) $job->duration - 2 ),
                ) );
            
            case 'zoom_gentle':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_zoom_gentle(
                    (float) $job->duration,
                    array(
                        'width'  => $dimensions['width'],
                        'height' => $dimensions['height'],
                    )
                );
            
            case 'cinematic':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_professional( 'cinematic', (float) $job->duration );
            
            case 'vintage':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_professional( 'vintage', (float) $job->duration );
            
            case 'modern':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_professional( 'modern', (float) $job->duration );
            
            case 'minimal':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_professional( 'minimal', (float) $job->duration );
            
            case 'warm':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_color_grade( 'warm' );
            
            case 'cool':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_color_grade( 'cool' );
            
            case 'dramatic':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_color_grade( 'dramatic' );
            
            case 'golden_hour':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_color_grade( 'golden_hour' );
            
            case 'vignette':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_vignette_subtle();
            
            case 'scale_9_16':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_scale_9_16( 1080 );
            
            case 'scale_16_9':
                return BizCity_Video_Kling_FFmpeg_Presets::preset_scale_16_9( 1920 );
            
            default:
                return '';
        }
    }
    
    /**
     * Get video dimensions from aspect ratio
     */
    private static function get_dimensions_from_aspect_ratio( $aspect_ratio ) {
        $dimensions = array( 'width' => 1080, 'height' => 1920 ); // Default 9:16
        
        switch ( $aspect_ratio ) {
            case '9:16':
                $dimensions = array( 'width' => 1080, 'height' => 1920 );
                break;
            case '16:9':
                $dimensions = array( 'width' => 1920, 'height' => 1080 );
                break;
            case '1:1':
                $dimensions = array( 'width' => 1080, 'height' => 1080 );
                break;
            case '4:3':
                $dimensions = array( 'width' => 1440, 'height' => 1080 );
                break;
            case '3:4':
                $dimensions = array( 'width' => 1080, 'height' => 1440 );
                break;
        }
        
        return $dimensions;
    }
    
    /**
     * Cleanup temporary files for a job
     */
    private static function cleanup_temp_files( $temp_dir, $job_id ) {
        // Find and delete temp files for this job
        $patterns = array(
            $temp_dir . 'temp_video_' . $job_id . '_*.mp4',
            $temp_dir . 'merged_' . $job_id . '_*.mp4',
        );
        
        foreach ( $patterns as $pattern ) {
            foreach ( glob( $pattern ) as $file ) {
                @unlink( $file );
            }
        }
    }
    
    /**
     * AJAX: Check FFmpeg availability
     */
    public static function ajax_check_ffmpeg() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! class_exists( 'BizCity_Video_Kling_FFmpeg_Presets' ) ) {
            wp_send_json_error( array( 'message' => 'FFmpeg Presets class not loaded' ) );
            return;
        }
        
        // If custom path provided, temporarily save it for testing
        $custom_path = isset( $_POST['ffmpeg_path'] ) ? sanitize_text_field( $_POST['ffmpeg_path'] ) : '';
        if ( ! empty( $custom_path ) ) {
            update_option( 'bizcity_video_kling_ffmpeg_path', $custom_path );
        }
        
        $status = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
        
        if ( $status['available'] ) {
            wp_send_json_success( array(
                'available' => true,
                'version' => $status['version'],
                'path' => $status['path'],
                'output' => $status['output'] ?? '',
                'tts_configured' => class_exists( 'BizCity_Video_Kling_OpenAI_TTS' ) 
                    ? BizCity_Video_Kling_OpenAI_TTS::is_configured()
                    : false,
            ) );
        } else {
            wp_send_json_error( array(
                'available' => false,
                'error' => $status['error'] ?? 'FFmpeg not found',
                'path' => $status['path'],
            ) );
        }
    }
    
    /**
     * AJAX: Get job logs
     */
    public static function ajax_get_logs() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        $since_timestamp = intval( $_POST['since_timestamp'] ?? 0 );
        
        $logs = self::get_logs( $job_id, $since_timestamp );
        
        wp_send_json_success( array(
            'logs' => $logs,
            'last_timestamp' => time(),
        ) );
    }
    
    /**
     * AJAX: Retry failed job
     */
    public static function ajax_retry_job() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
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
        
        self::add_log( $job_id, 'Retrying job...', 'info' );
        self::clear_logs( $job_id );
        
        // Get API settings
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'API key not configured', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Create new task
        $settings = array(
            'api_key' => $api_key,
            'endpoint' => $endpoint,
            'model' => $job->model ?? 'kling-v1',
            'task_type' => 'image_to_video',
        );
        
        $input = array(
            'prompt' => $job->prompt,
            'duration' => (int) $job->duration,
            'aspect_ratio' => $job->aspect_ratio ?? '9:16',
        );
        
        if ( ! empty( $job->image_url ) ) {
            $input['image_url'] = $job->image_url;
        }
        
        self::add_log( $job_id, 'Creating new video task...', 'info' );
        
        $result = waic_kling_create_task( $settings, $input );
        
        if ( ! $result['ok'] ) {
            self::add_log( $job_id, sprintf( 'Failed: %s', $result['error'] ?? 'Unknown error' ), 'error' );
            wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Failed to create task', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Extract new task_id
        $data = $result['data'];
        $task_id = $data['task_id'] ?? ( $data['data']['task_id'] ?? null );
        
        if ( ! $task_id ) {
            self::add_log( $job_id, 'Missing task_id in response', 'error' );
            wp_send_json_error( array( 'message' => __( 'Missing task_id in response', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Update job
        $update_data = array(
            'task_id' => $task_id,
            'status' => 'queued',
            'progress' => 10,
            'video_url' => null,
            'media_url' => null,
            'attachment_id' => null,
            'error_message' => null,
        );
        
        $metadata = json_decode( $job->metadata ?? '{}', true );
        $metadata['retry_at'] = current_time( 'mysql' );
        $metadata['retry_count'] = ( $metadata['retry_count'] ?? 0 ) + 1;
        $update_data['metadata'] = json_encode( $metadata );
        
        BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
        
        self::add_log( $job_id, sprintf( 'New task created: %s', $task_id ), 'success' );
        
        wp_send_json_success( array(
            'message' => __( 'Job retry started', 'bizcity-video-kling' ),
            'task_id' => $task_id,
        ) );
    }
    
    /**
     * AJAX: Resume job from checkpoint
     * Continues processing from where it failed without re-downloading/re-generating completed steps
     */
    public static function ajax_resume_job() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        $job_id = intval( $_POST['job_id'] ?? 0 );
        $resume_step = sanitize_text_field( $_POST['resume_step'] ?? '' );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $resume_point = BizCity_Video_Kling_Database::get_resume_point( $job_id );
        
        self::add_log( $job_id, sprintf( 'Resuming from checkpoint: %s', $resume_point['step'] ), 'info' );
        
        // Check what needs to be done based on resume point
        switch ( $resume_point['step'] ) {
            case 'video_fetched':
            case 'tts_generated':
            case 'audio_merged':
                // Resume video processing - these steps are handled by download_process_and_upload_video
                // which already checks checkpoints internally
                
                // Get TTS options from previous metadata
                $metadata = json_decode( $job->metadata ?? '{}', true );
                $tts_options = $metadata['tts_options'] ?? array(
                    'voice' => 'nova',
                    'model' => 'tts-1-hd',
                    'speed' => 1.0,
                );
                $ffmpeg_preset = $metadata['ffmpeg_preset'] ?? '';
                
                $options = array(
                    'enable_tts'    => ! empty( $metadata['tts_enabled'] ) || ! empty( $resume_point['temp_files']['audio_path'] ),
                    'tts_options'   => $tts_options,
                    'custom_text'   => '',
                    'ffmpeg_preset' => $ffmpeg_preset,
                );
                
                $result = self::download_process_and_upload_video( $job, $options );
                
                if ( ! $result['success'] ) {
                    self::add_log( $job_id, sprintf( 'Resume failed at %s: %s', $resume_point['step'], $result['message'] ?? 'Unknown' ), 'error' );
                    wp_send_json_error( array( 
                        'message' => $result['message'] ?? 'Resume failed',
                        'step' => $resume_point['step'],
                        'can_resume' => $result['can_resume'] ?? false,
                    ) );
                    return;
                }
                
                // Update job with result
                $update_data = array(
                    'media_url' => $result['url'],
                    'attachment_id' => $result['attachment_id'],
                    'status' => 'completed',
                );
                
                $metadata['resumed_at'] = current_time( 'mysql' );
                $metadata['resume_count'] = ( $metadata['resume_count'] ?? 0 ) + 1;
                $metadata['wp_attachment_id'] = $result['attachment_id'];
                $metadata['wp_attachment_url'] = $result['url'];
                if ( ! empty( $result['r2_url'] ) ) {
                    $metadata['r2_url'] = $result['r2_url'];
                    $metadata['r2_key'] = $result['r2_key'];
                }
                $update_data['metadata'] = json_encode( $metadata );
                
                BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
                
                self::add_log( $job_id, sprintf( 'Resume completed! Attachment ID: %d', $result['attachment_id'] ), 'success' );

                // Notify Zalo admins that resume / video is ready
                $urls = self::get_admin_monitor_urls( $job_id, $job->script_id ?? 0 );
                $done_msg = "✅ Video đã hoàn tất (job #{$job_id})\n";
                if ( ! empty( $job->prompt ) ) {
                    $done_msg .= "📝 " . mb_substr( $job->prompt, 0, 80 ) . "\n";
                }
                $done_msg .= "\n🎬 Xem & tải video:\n" . $urls['monitor'] . "\n\n";
                $done_msg .= "📋 Danh sách kịch bản:\n" . $urls['list'];
                self::notify_zalo_admins_video_status( $job, $done_msg );

                wp_send_json_success( array(
                    'message' => __( 'Resume completed!', 'bizcity-video-kling' ),
                    'attachment_id' => $result['attachment_id'],
                    'media_url' => $result['url'],
                ) );
                break;

            case 'media_uploaded':
            case 'cleanup_done':
            case 'completed':
                // Already done
                wp_send_json_success( array( 
                    'message' => __( 'Job already completed', 'bizcity-video-kling' ),
                    'attachment_id' => $job->attachment_id,
                ) );
                break;
                
            default:
                // Need to start from scratch - use retry instead
                wp_send_json_error( array( 
                    'message' => __( 'Cannot resume from this point. Please use Retry instead.', 'bizcity-video-kling' ),
                    'step' => $resume_point['step'],
                ) );
                break;
        }
    }
    
    /**
     * Get progress percentage from status
     */
    private static function get_progress_from_status( $status ) {
        $map = array(
            'draft' => 0,
            'created' => 5,
            'queued' => 10,
            'pending' => 20,
            'processing' => 50,
            'rendering' => 75,
            'succeeded' => 100,
            'completed' => 100,
            'done' => 100,
            'failed' => 0,
            'error' => 0,
        );
        
        return $map[ strtolower( $status ) ] ?? 30;
    }
    
    /**
     * Render monitor page
     */
    public static function render_page() {
        global $wpdb;
        
        // Get all jobs
        $table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $jobs = $wpdb->get_results( "
            SELECT * FROM {$table}
            ORDER BY created_at DESC
            LIMIT 50
        " );
        
        // Get stats
        $stats = BizCity_Video_Kling_Database::get_stats();
        
        $nonce = wp_create_nonce( 'bizcity_kling_nonce' );
        
        include BIZCITY_VIDEO_KLING_DIR . 'views/monitor/index.php';
    }

    /**
     * Notify all Zalo admin users about a video job's status.
     *
     * Covers two channels:
     *  1. Zalo Personal / Zalo BOT via send_zalo_botbanhang (client_ids from global_user_admin)
     *  2. Any chat_id registered in the job (Telegram / Zalo personal prefix)
     *
     * @param object      $job     Job DB row (must have ->id and optionally ->script_id)
     * @param string      $msg     Message to send
     * @param string|null $chat_id Optional single chat_id to notify as well
     */
    public static function notify_zalo_admins_video_status( $job, $msg, $chat_id = null ) {
        $sent_ids = array();

        // 1. Send to all Zalo admins for this blog via Zalo OA / personal bot
        if ( function_exists( 'twf_list_client_ids_by_blog_id' ) && function_exists( 'send_zalo_botbanhang' ) ) {
            $client_ids = twf_list_client_ids_by_blog_id( get_current_blog_id() );
            foreach ( (array) $client_ids as $cid ) {
                if ( ! empty( $cid ) && ! in_array( $cid, $sent_ids, true ) ) {
                    send_zalo_botbanhang( $msg, $cid, 'text' );
                    $sent_ids[] = $cid;
                }
            }
        }

        // 2. Also notify the single chat_id if provided (Telegram / Zalo prefixed)
        if ( ! empty( $chat_id ) && function_exists( 'twf_telegram_send_message' ) ) {
            // Only send if not already covered by Zalo admin list
            $bare = str_replace( 'zalo_', '', $chat_id );
            if ( ! in_array( $bare, $sent_ids, true ) ) {
                twf_telegram_send_message( $chat_id, $msg );
            }
        }
    }

    /**
     * Build admin job monitor URL for a given job
     *
     * @param int $job_id
     * @param int $script_id  Optional script_id
     * @return array [ 'monitor' => string, 'list' => string ]
     */
    public static function get_admin_monitor_urls( $job_id, $script_id = 0 ) {
        $list_url = admin_url( 'admin.php?page=bizcity-kling-scripts' );
        if ( $script_id ) {
            $monitor_url = admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . intval( $script_id ) . '&job_id=' . intval( $job_id ) );
        } else {
            $monitor_url = $list_url;
        }
        return array(
            'monitor' => $monitor_url,
            'list'    => $list_url,
        );
    }
}