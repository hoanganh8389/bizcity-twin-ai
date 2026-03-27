<?php
/**
 * Video Shots Management - List all generated videos
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Shots {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action( 'wp_ajax_bizcity_kling_delete_shot', array( __CLASS__, 'ajax_delete_shot' ) );
        add_action( 'wp_ajax_bizcity_kling_sync_to_media', array( __CLASS__, 'ajax_sync_to_media' ) );
    }
    
    /**
     * Render shots list page
     */
    public static function render_page() {
        global $wpdb;
        
        $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $scripts_table = BizCity_Video_Kling_Database::get_table_name( 'scripts' );
        
        $shots = $wpdb->get_results( "
            SELECT j.*, s.title as script_title
            FROM {$jobs_table} j
            LEFT JOIN {$scripts_table} s ON j.script_id = s.id
            ORDER BY j.created_at DESC
            LIMIT 100
        " );
        
        $nonce = wp_create_nonce( 'bizcity_kling_nonce' );
        
        // Stats
        $stats = BizCity_Video_Kling_Database::get_stats();
        ?>
        <div class="wrap bizcity-kling-wrap bizcity-video-wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h1><?php _e( 'Video Shots', 'bizcity-video-kling' ); ?></h1>
                <div>
                    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-monitor' ); ?>" class="bizcity-btn bizcity-btn-secondary" style="margin-right: 10px;">
                        <span class="dashicons dashicons-clock" style="margin-right: 5px;"></span>
                        <?php _e( 'Queue Monitor', 'bizcity-video-kling' ); ?>
                    </a>
                    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=new' ); ?>" class="bizcity-btn bizcity-btn-primary">
                        <span class="dashicons dashicons-plus-alt" style="margin-right: 5px;"></span>
                        <?php _e( 'Create New Script', 'bizcity-video-kling' ); ?>
                    </a>
                </div>
            </div>
            
            <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'shots' ); ?>
            
            <!-- Stats -->
            <div class="bizcity-stats-grid">
                <div class="bizcity-stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-video-alt3"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ( $stats->total_jobs ?? 0 ); ?></div>
                        <div class="stat-label"><?php _e( 'Total Videos', 'bizcity-video-kling' ); ?></div>
                    </div>
                </div>
                <div class="bizcity-stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ( $stats->completed_jobs ?? 0 ); ?></div>
                        <div class="stat-label"><?php _e( 'Completed', 'bizcity-video-kling' ); ?></div>
                    </div>
                </div>
                <div class="bizcity-stat-card" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ( $stats->processing_jobs ?? 0 ) + (int) ( $stats->queued_jobs ?? 0 ); ?></div>
                        <div class="stat-label"><?php _e( 'In Progress', 'bizcity-video-kling' ); ?></div>
                    </div>
                </div>
                <div class="bizcity-stat-card" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ( $stats->failed_jobs ?? 0 ); ?></div>
                        <div class="stat-label"><?php _e( 'Failed', 'bizcity-video-kling' ); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Shots Table -->
            <div class="bizcity-card">
                <table class="bizcity-table">
                    <thead>
                        <tr>
                            <th><?php _e( 'Preview', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Prompt', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Script', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Provider', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Status', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Created', 'bizcity-video-kling' ); ?></th>
                            <th><?php _e( 'Actions', 'bizcity-video-kling' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $shots ) ): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 60px;">
                                    <span class="dashicons dashicons-video-alt3" style="font-size: 48px; color: #ccc; margin-bottom: 15px; display: block;"></span>
                                    <p style="color: #6b7280; font-size: 15px; margin: 0;">
                                        <?php _e( 'No video shots yet.', 'bizcity-video-kling' ); ?>
                                        <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=new' ); ?>">
                                            <?php _e( 'Create a script to generate your first video!', 'bizcity-video-kling' ); ?>
                                        </a>
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ( $shots as $shot ): 
                                $metadata = json_decode( $shot->metadata ?? '{}', true );
                                $video_url = $shot->media_url ?: $shot->video_url;
                                
                                // Chain info
                                $has_chain = ! empty( $shot->chain_id ) && $shot->total_segments > 1;
                                $is_final_segment = $has_chain && $shot->is_final;
                            ?>
                                <tr id="shot-row-<?php echo $shot->id; ?>" <?php echo $has_chain ? 'data-chain-id="' . esc_attr( $shot->chain_id ) . '"' : ''; ?>>
                                    <td>
                                        <?php if ( $video_url ): ?>
                                            <video style="width: 100px; height: 178px; border-radius: 8px; object-fit: cover; background: #000;" 
                                                   src="<?php echo esc_url( $video_url ); ?>" controls></video>
                                        <?php else: ?>
                                            <div style="width: 100px; height: 178px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                <span class="dashicons dashicons-video-alt3" style="font-size: 32px; color: #9ca3af;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 250px;">
                                        <?php echo esc_html( wp_trim_words( $shot->prompt, 15, '...' ) ); ?>
                                        <?php if ( $has_chain ): ?>
                                            <div style="margin-top: 8px;">
                                                <span class="bizcity-badge bizcity-badge-info" style="font-size: 10px;">
                                                    Seg <?php echo $shot->segment_index; ?>/<?php echo $shot->total_segments; ?>
                                                </span>
                                                <?php if ( $is_final_segment ): ?>
                                                    <span class="bizcity-badge bizcity-badge-success" style="font-size: 10px;">Final</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $shot->script_title ): ?>
                                            <span class="bizcity-badge bizcity-badge-secondary" style="text-transform: uppercase; font-size: 10px;">
                                                <?php echo esc_html( $shot->script_title ); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #6b7280;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $shot->model ?? 'kling' ); ?></td>
                                    <td>
                                        <?php echo self::get_status_badge( $shot->status ); ?>
                                    </td>
                                    <td>
                                        <?php echo human_time_diff( strtotime( $shot->created_at ), current_time( 'timestamp' ) ); ?> ago
                                    </td>
                                    <td>
                                        <?php if ( $shot->status === 'completed' && $video_url ): ?>
                                            <a href="<?php echo esc_url( $video_url ); ?>" target="_blank" 
                                               class="bizcity-btn bizcity-btn-sm bizcity-btn-success">
                                                <?php _e( 'Download', 'bizcity-video-kling' ); ?>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ( $shot->status === 'completed' && $shot->video_url && ! $shot->attachment_id ): ?>
                                            <button type="button" class="bizcity-btn bizcity-btn-sm bizcity-btn-primary sync-media-btn" 
                                                    data-shot-id="<?php echo $shot->id; ?>">
                                                <?php _e( 'Upload to Media', 'bizcity-video-kling' ); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ( $has_chain && $is_final_segment && $shot->status === 'completed' ): ?>
                                            <button type="button" class="bizcity-btn bizcity-btn-sm bizcity-btn-warning fetch-chain-btn" 
                                                    data-chain-id="<?php echo esc_attr( $shot->chain_id ); ?>">
                                                <?php _e( 'Fetch All Segments', 'bizcity-video-kling' ); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="bizcity-btn bizcity-btn-sm bizcity-btn-danger delete-shot-btn" 
                                                data-shot-id="<?php echo $shot->id; ?>">
                                            <?php _e( 'Delete', 'bizcity-video-kling' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo $nonce; ?>';
            
            // Delete shot
            $('.delete-shot-btn').on('click', function() {
                if (!confirm('<?php _e( 'Delete this video shot?', 'bizcity-video-kling' ); ?>')) return;
                
                var $btn = $(this);
                var shotId = $btn.data('shot-id');
                
                $btn.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_delete_shot',
                        shot_id: shotId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#shot-row-' + shotId).fadeOut(function() { $(this).remove(); });
                        } else {
                            alert(response.data.message || 'Error');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Request failed');
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Sync to media
            $('.sync-media-btn').on('click', function() {
                var $btn = $(this);
                var shotId = $btn.data('shot-id');
                
                $btn.prop('disabled', true).text('Uploading...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_fetch_video',
                        job_id: shotId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php _e( 'Uploaded to Media Library!', 'bizcity-video-kling' ); ?>');
                            location.reload();
                        } else {
                            alert(response.data.message || 'Error');
                            $btn.prop('disabled', false).text('Upload to Media');
                        }
                    },
                    error: function() {
                        alert('Request failed');
                        $btn.prop('disabled', false).text('Upload to Media');
                    }
                });
            });
            
            // Fetch all chain videos
            $('.fetch-chain-btn').on('click', function() {
                var $btn = $(this);
                var chainId = $btn.data('chain-id');
                
                $btn.prop('disabled', true).text('Fetching...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_kling_fetch_chain',
                        chain_id: chainId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var msg = response.data.message;
                            if (response.data.final_video) {
                                msg += '\n\n<?php _e( 'Final video uploaded with ID:', 'bizcity-video-kling' ); ?> ' + response.data.final_video.attachment_id;
                            }
                            alert(msg);
                            location.reload();
                        } else {
                            alert(response.data.message || 'Error');
                            $btn.prop('disabled', false).text('Fetch All Segments');
                        }
                    },
                    error: function() {
                        alert('Request failed');
                        $btn.prop('disabled', false).text('Fetch All Segments');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get status badge HTML
     */
    public static function get_status_badge( $status ) {
        $badges = array(
            'draft' => '<span class="bizcity-badge bizcity-badge-draft">Draft</span>',
            'queued' => '<span class="bizcity-badge bizcity-badge-queued">Queued</span>',
            'processing' => '<span class="bizcity-badge bizcity-badge-processing">Processing</span>',
            'completed' => '<span class="bizcity-badge bizcity-badge-completed">✓ Completed</span>',
            'failed' => '<span class="bizcity-badge bizcity-badge-failed">Failed</span>',
        );
        
        return $badges[ $status ] ?? '<span class="bizcity-badge">' . esc_html( ucfirst( $status ) ) . '</span>';
    }
    
    /**
     * AJAX: Delete shot
     */
    public static function ajax_delete_shot() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $shot_id = intval( $_POST['shot_id'] ?? 0 );
        
        if ( ! $shot_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid shot ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $result = BizCity_Video_Kling_Database::delete_job( $shot_id );
        
        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Shot deleted', 'bizcity-video-kling' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete shot', 'bizcity-video-kling' ) ) );
        }
    }
    
    /**
     * AJAX: Sync to media (alias for fetch_video)
     */
    public static function ajax_sync_to_media() {
        // Reuse fetch_video logic
        BizCity_Video_Kling_Job_Monitor::ajax_fetch_video();
    }
}
