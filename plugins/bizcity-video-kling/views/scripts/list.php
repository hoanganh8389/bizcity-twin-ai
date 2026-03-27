<?php
/**
 * View: Scripts List
 * 
 * @var array  $scripts Array of script objects
 * @var string $nonce   Security nonce
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<style>
.script-status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
.script-status.status-queued { background: #dbeafe; color: #1e40af; }
.script-status.status-processing { background: #fef3c7; color: #92400e; animation: pulse 1.5s infinite; }
.script-status.status-completed { background: #d1fae5; color: #065f46; }
.script-status.status-failed { background: #fee2e2; color: #991b1b; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
</style>
<div class="wrap bizcity-kling-wrap">
    <h1 class="wp-heading-inline"><?php _e( 'Scripts', 'bizcity-video-kling' ); ?></h1>
    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=new' ); ?>" class="page-title-action">
        <?php _e( 'Add New', 'bizcity-video-kling' ); ?>
    </a>
    <hr class="wp-header-end">
    
    <?php BizCity_Video_Kling_Admin_Menu::render_workflow_steps( 'scripts' ); ?>
    
    <div class="bizcity-kling-card">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th><?php _e( 'Title', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Duration', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Ratio', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 100px;"><?php _e( 'Model', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 120px;"><?php _e( 'Created', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 80px;"><?php _e( 'Status', 'bizcity-video-kling' ); ?></th>
                    <th style="width: 280px;"><?php _e( 'Actions', 'bizcity-video-kling' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $scripts ) ): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <?php _e( 'No scripts yet.', 'bizcity-video-kling' ); ?>
                            <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=new' ); ?>">
                                <?php _e( 'Create your first script', 'bizcity-video-kling' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $scripts as $script ): 
                        // Check for active job
                        $active_job = BizCity_Video_Kling_Database::get_latest_active_job( $script->id );
                        $has_active_job = $active_job && in_array( $active_job->status, array( 'queued', 'processing', 'draft' ) );
                        $job_status = $active_job ? $active_job->status : null;
                        $is_chain = $active_job && ! empty( $active_job->chain_id ) && $active_job->total_segments > 1;
                    ?>
                        <tr data-script-id="<?php echo $script->id; ?>">
                            <td><?php echo $script->id; ?></td>
                            <td><?php echo esc_html( $script->title ); ?></td>
                            <td><?php echo (int) $script->duration; ?>s</td>
                            <td><?php echo esc_html( $script->aspect_ratio ); ?></td>
                            <td><?php echo esc_html( $script->model ); ?></td>
                            <td><?php echo human_time_diff( strtotime( $script->created_at ), current_time( 'timestamp' ) ) . ' ago'; ?></td>
                            <td>
                                <?php if ( $has_active_job ): ?>
                                    <span class="script-status status-<?php echo esc_attr( $job_status ); ?>">
                                        <?php echo ucfirst( $job_status ); ?>
                                        <?php if ( $is_chain ): ?>
                                            (<?php echo $active_job->segment_index; ?>/<?php echo $active_job->total_segments; ?>)
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ( $active_job && $active_job->status === 'completed' ): ?>
                                    <span class="script-status status-completed">✓</span>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=edit&id=' . $script->id ); ?>" class="button">
                                    <?php _e( 'Edit', 'bizcity-video-kling' ); ?>
                                </a>
                                <?php if ( $has_active_job ): ?>
                                    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script->id . '&job_id=' . $active_job->id ); ?>" class="button button-primary" style="background: #f59e0b; border-color: #d97706;">
                                        <?php _e( 'Monitor', 'bizcity-video-kling' ); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script->id ); ?>" class="button button-primary">
                                        <?php _e( 'Generate', 'bizcity-video-kling' ); ?>
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="button delete-btn" data-script-id="<?php echo $script->id; ?>" style="color: #a00;">
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
    var nonce = '<?php echo esc_js( $nonce ); ?>';
    
    // Delete script
    $('.delete-btn').on('click', function() {
        if (!confirm('<?php echo esc_js( __( 'Delete this script?', 'bizcity-video-kling' ) ); ?>')) return;
        
        var $btn = $(this);
        var scriptId = $btn.data('script-id');
        
        $btn.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bizcity_kling_delete_script',
                script_id: scriptId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(function() { $(this).remove(); });
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
    
    // Generate video (inline)
    $('.generate-btn').on('click', function() {
        var $btn = $(this);
        var scriptId = $btn.data('script-id');
        
        $btn.prop('disabled', true).text('Generating...');
        
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
                    alert('<?php echo esc_js( __( 'Video job created! Go to Monitor page to track progress.', 'bizcity-video-kling' ) ); ?>');
                    window.location.href = '<?php echo admin_url( 'admin.php?page=bizcity-kling-monitor' ); ?>';
                } else {
                    alert(response.data.message || 'Error');
                }
            },
            error: function() {
                alert('Request failed');
            },
            complete: function() {
                $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Generate', 'bizcity-video-kling' ) ); ?>');
            }
        });
    });
});
</script>
