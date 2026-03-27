<?php
/**
 * Admin Menu - Settings & Submenu Pages
 */

if (!defined('ABSPATH')) exit;

class BizCity_Video_Kling_Admin_Menu {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Render workflow steps navigation
     * @param string $current_step Current step identifier
     * @param int $script_id Optional script ID for contextual links
     */
    public static function render_workflow_steps( $current_step = '', $script_id = 0 ) {
        $steps = array(
            'scripts' => array(
                'icon'  => 'dashicons-edit-page',
                'label' => __( 'Bước 1: Tạo Script', 'bizcity-video-kling' ),
                'url'   => admin_url( 'admin.php?page=bizcity-kling-scripts' ),
            ),
            'generate' => array(
                'icon'  => 'dashicons-video-alt3',
                'label' => __( 'Bước 2: Generate Video', 'bizcity-video-kling' ),
                'url'   => $script_id ? admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id ) : '#',
            ),
            'shots' => array(
                'icon'  => 'dashicons-images-alt2',
                'label' => __( 'Bước 3: Video Shots', 'bizcity-video-kling' ),
                'url'   => admin_url( 'admin.php?page=bizcity-kling-shots' ),
            ),
            'monitor' => array(
                'icon'  => 'dashicons-visibility',
                'label' => __( 'Bước 4: Monitor Jobs', 'bizcity-video-kling' ),
                'url'   => admin_url( 'admin.php?page=bizcity-kling-monitor' ),
            ),
        );
        ?>
        <div class="bizcity-workflow-steps">
            <h3>
                <span class="dashicons dashicons-welcome-learn-more"></span>
                <?php _e( 'Quy trình tạo video với Kling AI', 'bizcity-video-kling' ); ?>
            </h3>
            <div class="bizcity-workflow-steps-container">
                <?php foreach ( $steps as $key => $step ): 
                    $is_active = ( $key === $current_step );
                    $is_disabled = ( $key === 'generate' && ! $script_id );
                ?>
                <a href="<?php echo $is_disabled ? 'javascript:void(0)' : esc_url( $step['url'] ); ?>" 
                   class="bizcity-workflow-step <?php echo $is_active ? 'active' : ''; ?> <?php echo $is_disabled ? 'disabled' : ''; ?>"
                   <?php echo $is_disabled ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                    <span class="dashicons <?php echo esc_attr( $step['icon'] ); ?>"></span>
                    <span><?php echo esc_html( $step['label'] ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_bizcity_kling_test_api', [$this, 'ajax_test_api']);
    }
    
    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_assets($hook) {
        // Check if we're on a Kling page
        // Hooks: toplevel_page_bizcity-kling, video-kling_page_bizcity-kling-*
        if (strpos($hook, 'kling') === false) {
            return;
        }
        
        wp_enqueue_style(
            'bizcity-kling-admin',
            BIZCITY_VIDEO_KLING_URL . 'assets/admin.css',
            [],
            BIZCITY_VIDEO_KLING_ASSETS_VERSION
        );
        
        // Enqueue WordPress media uploader for scripts page
        if (strpos($hook, 'bizcity-kling-scripts') !== false) {
            wp_enqueue_media();
        }
    }
    
    public function add_menu() {
        // Main menu
        add_menu_page(
            __('Video Kling', 'bizcity-video-kling'),
            __('Video Kling', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling',
            [$this, 'render_dashboard_page'],
            'dashicons-video-alt3',
            56
        );
        
        // Dashboard (default)
        add_submenu_page(
            'bizcity-kling',
            __('Dashboard', 'bizcity-video-kling'),
            __('Dashboard', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling',
            [$this, 'render_dashboard_page']
        );
        
        // Scripts
        add_submenu_page(
            'bizcity-kling',
            __('Scripts', 'bizcity-video-kling'),
            __('Scripts', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling-scripts',
            [$this, 'render_scripts_page']
        );
        
        // Video Shots
        add_submenu_page(
            'bizcity-kling',
            __('Video Shots', 'bizcity-video-kling'),
            __('Video Shots', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling-shots',
            [$this, 'render_shots_page']
        );
        
        // Monitor
        add_submenu_page(
            'bizcity-kling',
            __('Monitor', 'bizcity-video-kling'),
            __('Monitor', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling-monitor',
            [$this, 'render_monitor_page']
        );
        
        // Settings
        add_submenu_page(
            'bizcity-kling',
            __('Settings', 'bizcity-video-kling'),
            __('Settings', 'bizcity-video-kling'),
            'manage_options',
            'bizcity-kling-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Render Dashboard page
     */
    public function render_dashboard_page() {
        $stats = BizCity_Video_Kling_Database::get_stats();
        ?>
        <div class="wrap bizcity-kling-wrap">
            <h1><?php _e('Video Kling Dashboard', 'bizcity-video-kling'); ?></h1>
            
            <?php self::render_workflow_steps( '' ); ?>
            
            <!-- Stats Overview -->
            <div class="bizcity-kling-stats">
                <div class="stat-card">
                    <div class="stat-icon"><span class="dashicons dashicons-edit-page"></span></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo (int) ($stats->total_scripts ?? 0); ?></span>
                        <span class="stat-label"><?php _e('Total Scripts', 'bizcity-video-kling'); ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><span class="dashicons dashicons-video-alt3"></span></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo (int) ($stats->total_jobs ?? 0); ?></span>
                        <span class="stat-label"><?php _e('Total Jobs', 'bizcity-video-kling'); ?></span>
                    </div>
                </div>
                <div class="stat-card stat-processing">
                    <div class="stat-icon"><span class="dashicons dashicons-update"></span></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo (int) ($stats->processing_jobs ?? 0) + (int) ($stats->queued_jobs ?? 0); ?></span>
                        <span class="stat-label"><?php _e('In Progress', 'bizcity-video-kling'); ?></span>
                    </div>
                </div>
                <div class="stat-card stat-completed">
                    <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo (int) ($stats->completed_jobs ?? 0); ?></span>
                        <span class="stat-label"><?php _e('Completed', 'bizcity-video-kling'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="bizcity-kling-card">
                <h2><?php _e('Quick Actions', 'bizcity-video-kling'); ?></h2>
                <div class="quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=bizcity-kling-scripts&action=new'); ?>" class="button button-primary button-hero">
                        <?php _e('Create New Script', 'bizcity-video-kling'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-kling-monitor'); ?>" class="button button-hero">
                        <?php _e('View Job Monitor', 'bizcity-video-kling'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-kling-settings'); ?>" class="button button-hero">
                        <?php _e('Settings', 'bizcity-video-kling'); ?>
                    </a>
                </div>
            </div>
            
            <!-- Recent Jobs -->
            <?php
            global $wpdb;
            $table = BizCity_Video_Kling_Database::get_table_name('jobs');
            $recent_jobs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5");
            ?>
            <div class="bizcity-kling-card">
                <h2><?php _e('Recent Jobs', 'bizcity-video-kling'); ?></h2>
                <?php if (empty($recent_jobs)): ?>
                    <p><?php _e('No jobs yet. Create a script to generate your first video!', 'bizcity-video-kling'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php _e('Prompt', 'bizcity-video-kling'); ?></th>
                                <th><?php _e('Status', 'bizcity-video-kling'); ?></th>
                                <th><?php _e('Created', 'bizcity-video-kling'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_jobs as $job): ?>
                                <tr>
                                    <td><?php echo $job->id; ?></td>
                                    <td><?php echo esc_html(wp_trim_words($job->prompt, 8, '...')); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($job->status); ?>">
                                            <?php echo esc_html(ucfirst($job->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo human_time_diff(strtotime($job->created_at), current_time('timestamp')) . ' ago'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><a href="<?php echo admin_url('admin.php?page=bizcity-kling-monitor'); ?>"><?php _e('View all jobs →', 'bizcity-video-kling'); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Scripts page
     */
    public function render_scripts_page() {
        BizCity_Video_Kling_Scripts::render_page();
    }
    
    /**
     * Render Monitor page
     */
    public function render_monitor_page() {
        BizCity_Video_Kling_Job_Monitor::render_page();
    }
    
    /**
     * Render Shots page
     */
    public function render_shots_page() {
        BizCity_Video_Kling_Shots::render_page();
    }
    
    public function register_settings() {
        register_setting('bizcity_video_kling', 'bizcity_video_kling_api_key');
        register_setting('bizcity_video_kling', 'bizcity_video_kling_endpoint');
        register_setting('bizcity_video_kling', 'bizcity_video_kling_default_model');
        register_setting('bizcity_video_kling', 'bizcity_video_kling_default_duration');
        register_setting('bizcity_video_kling', 'bizcity_video_kling_default_aspect_ratio');
        register_setting('bizcity_video_kling', 'bizcity_video_kling_ffmpeg_path');
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Save settings
        if (isset($_POST['bizcity_kling_save_settings']) && check_admin_referer('bizcity_kling_settings')) {
            update_option('bizcity_video_kling_api_key', sanitize_text_field($_POST['api_key'] ?? ''));
            update_option('bizcity_video_kling_endpoint', esc_url_raw($_POST['endpoint'] ?? ''));
            update_option('bizcity_video_kling_default_model', sanitize_text_field($_POST['default_model'] ?? ''));
            update_option('bizcity_video_kling_default_duration', (int)($_POST['default_duration'] ?? 30));
            update_option('bizcity_video_kling_default_aspect_ratio', sanitize_text_field($_POST['default_aspect_ratio'] ?? '9:16'));
            update_option('bizcity_video_kling_ffmpeg_path', sanitize_text_field($_POST['ffmpeg_path'] ?? ''));
            
            echo '<div class="notice notice-success"><p>' . __('Đã lưu cấu hình!', 'bizcity-video-kling') . '</p></div>';
        }
        
        $api_key = get_option('bizcity_video_kling_api_key', '');
        $endpoint = get_option('bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1');
        $default_model = get_option('bizcity_video_kling_default_model', '2.6|pro');
        $default_duration = get_option('bizcity_video_kling_default_duration', 30);
        $default_aspect_ratio = get_option('bizcity_video_kling_default_aspect_ratio', '9:16');
        
        $models = waic_kling_get_models();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>⚙️ Cấu Hình PiAPI</h2>
                
                <form method="post">
                    <?php wp_nonce_field('bizcity_kling_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php _e('API Key PiAPI', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="api_key" name="api_key" 
                                       value="<?php echo esc_attr($api_key); ?>" 
                                       class="regular-text" 
                                       placeholder="sk-xxx...">
                                <p class="description">
                                    <?php _e('Lấy API key tại', 'bizcity-video-kling'); ?>
                                    <a href="https://piapi.ai" target="_blank">PiAPI.ai</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="endpoint"><?php _e('API Endpoint', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="endpoint" name="endpoint" 
                                       value="<?php echo esc_attr($endpoint); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    <?php _e('Mặc định: https://api.piapi.ai/api/v1', 'bizcity-video-kling'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3><?php _e('Cài Đặt Mặc Định', 'bizcity-video-kling'); ?></h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="default_model"><?php _e('Model Mặc Định', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <select id="default_model" name="default_model">
                                    <?php foreach ($models as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                <?php selected($default_model, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php _e('Model AI để tạo video. Pro models chất lượng cao hơn nhưng tốn hơn.', 'bizcity-video-kling'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="default_duration"><?php _e('Độ Dài Mặc Định', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <select id="default_duration" name="default_duration">
                                    <?php foreach ([5, 10, 15, 20, 30] as $dur): ?>
                                        <option value="<?php echo $dur; ?>" 
                                                <?php selected($default_duration, $dur); ?>>
                                            <?php echo $dur; ?>s
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="default_aspect_ratio"><?php _e('Tỷ Lệ Khung Hình', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <select id="default_aspect_ratio" name="default_aspect_ratio">
                                    <option value="16:9" <?php selected($default_aspect_ratio, '16:9'); ?>>
                                        16:9 (Landscape)
                                    </option>
                                    <option value="9:16" <?php selected($default_aspect_ratio, '9:16'); ?>>
                                        9:16 (Portrait/Social) ⭐
                                    </option>
                                    <option value="1:1" <?php selected($default_aspect_ratio, '1:1'); ?>>
                                        1:1 (Square)
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Tập trung vào 9:16 cho video mạng xã hội', 'bizcity-video-kling'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="bizcity_kling_save_settings" 
                                class="button button-primary">
                            <?php _e('Lưu Cấu Hình', 'bizcity-video-kling'); ?>
                        </button>
                        
                        <button type="button" class="button" id="test-api-btn">
                            <?php _e('Test API', 'bizcity-video-kling'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
            <?php
            // FFmpeg status check
            $ffmpeg_path = get_option('bizcity_video_kling_ffmpeg_path', '');
            $ffmpeg_status = array('available' => false);
            if (class_exists('BizCity_Video_Kling_FFmpeg_Presets')) {
                $ffmpeg_status = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
            }
            ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>🎬 FFmpeg Configuration</h2>
                
                <div style="padding: 15px; border-radius: 8px; margin-bottom: 15px; background: <?php echo $ffmpeg_status['available'] ? '#d1fae5' : '#fee2e2'; ?>;">
                    <?php if ($ffmpeg_status['available']): ?>
                        <p style="margin: 0; color: #065f46;">
                            <strong>✅ FFmpeg Available</strong><br>
                            Path: <code><?php echo esc_html($ffmpeg_status['path']); ?></code><br>
                            Version: <code><?php echo esc_html($ffmpeg_status['version'] ?? 'unknown'); ?></code>
                        </p>
                    <?php else: ?>
                        <p style="margin: 0; color: #991b1b;">
                            <strong>❌ FFmpeg Not Available</strong><br>
                            <?php echo esc_html($ffmpeg_status['error'] ?? 'FFmpeg not found'); ?><br>
                            Please install FFmpeg or configure the correct path below.
                        </p>
                    <?php endif; ?>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('bizcity_kling_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ffmpeg_path"><?php _e('FFmpeg Path', 'bizcity-video-kling'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="ffmpeg_path" name="ffmpeg_path" 
                                       value="<?php echo esc_attr($ffmpeg_path); ?>" 
                                       class="regular-text" 
                                       placeholder="e.g., C:\ffmpeg\bin\ffmpeg.exe or /usr/bin/ffmpeg">
                                <p class="description">
                                    <?php _e('Đường dẫn tuyệt đối đến file thực thi ffmpeg. Để trống để tự động tìm trong PATH.', 'bizcity-video-kling'); ?>
                                </p>
                                
                                <p class="description" style="margin-top: 10px;">
                                    <strong>Cách cài đặt FFmpeg:</strong><br>
                                    📥 <strong>Windows:</strong><br>
                                    &nbsp;&nbsp;1. Tải FFmpeg tại <a href="https://ffmpeg.org/download.html" target="_blank">ffmpeg.org/download.html</a><br>
                                    &nbsp;&nbsp;2. Giải nén vào <code>C:\ffmpeg</code><br>
                                    &nbsp;&nbsp;3. Điền path: <code>C:\ffmpeg\bin\ffmpeg.exe</code><br>
                                    <br>
                                    📥 <strong>Linux/Mac:</strong><br>
                                    &nbsp;&nbsp;<code>sudo apt install ffmpeg</code> hoặc <code>brew install ffmpeg</code><br>
                                    &nbsp;&nbsp;Thường sẽ ở: <code>/usr/bin/ffmpeg</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="bizcity_kling_save_settings" 
                                class="button button-primary">
                            <?php _e('Lưu FFmpeg Path', 'bizcity-video-kling'); ?>
                        </button>
                        
                        <button type="button" class="button" id="test-ffmpeg-btn">
                            <?php _e('Test FFmpeg', 'bizcity-video-kling'); ?>
                        </button>
                    </p>
                </form>
                
                <div id="ffmpeg-test-result" style="display: none; margin-top: 10px;"></div>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📋 Hướng Dẫn Sử Dụng</h2>
                
                <h3>1. Tạo Video Trong Workflow</h3>
                <p>Sử dụng 3 actions trong WAIC Workflow theo thứ tự:</p>
                <ol>
                    <li><strong>Kling - Create Job</strong>: Tạo task tạo video từ ảnh</li>
                    <li><strong>Kling - Poll Status</strong>: Kiểm tra trạng thái định kỳ</li>
                    <li><strong>Kling - Fetch Video</strong>: Tải video về</li>
                </ol>
                
                <h3>2. Tạo Video Từ Ảnh (Image to Video)</h3>
                <p>Thích hợp cho video mạng xã hội (TikTok, Instagram Reels, YouTube Shorts):</p>
                <ul>
                    <li>✅ Chọn task type: <code>image_to_video</code></li>
                    <li>✅ Tỷ lệ khung hình: <code>9:16</code> (chiều dọc)</li>
                    <li>✅ Độ dài: 20-30 giây</li>
                    <li>✅ Cung cấp URL ảnh và prompt mô tả</li>
                </ul>
                
                <h3>3. Models Khả Dụng</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Mô tả</th>
                            <th>Max Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($models as $key => $model): ?>
                            <tr>
                                <td><code><?php echo esc_html($key); ?></code></td>
                                <td><?php echo esc_html($model['description']); ?></td>
                                <td><?php echo esc_html($model['max_duration']); ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h3>4. Tài Liệu API</h3>
                <p>
                    Xem chi tiết tại: 
                    <a href="https://piapi.ai/docs/overview" target="_blank">
                        PiAPI Documentation
                    </a>
                </p>
            </div>
            
            <div id="test-result" style="display:none; margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-api-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var apiKey = $('#api_key').val();
                var endpoint = $('#endpoint').val();
                
                if (!apiKey) {
                    alert('Vui lòng nhập API Key');
                    return;
                }
                
                btn.prop('disabled', true).text('Đang test...');
                $('#test-result').hide();
                
                $.post(ajaxurl, {
                    action: 'bizcity_kling_test_api',
                    api_key: apiKey,
                    endpoint: endpoint,
                    _wpnonce: '<?php echo wp_create_nonce('bizcity_kling_test_api'); ?>'
                }, function(response) {
                    btn.prop('disabled', false).text('Test API');
                    
                    var resultDiv = $('#test-result');
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p><strong>✅ Kết nối thành công!</strong><br>' + 
                            'Response: <pre>' + JSON.stringify(response.data, null, 2) + '</pre></p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p><strong>❌ Lỗi kết nối</strong><br>' + 
                            response.data.message + '</p></div>');
                    }
                    resultDiv.show();
                });
            });
            
            // Test FFmpeg
            $('#test-ffmpeg-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var ffmpegPath = $('#ffmpeg_path').val();
                
                btn.prop('disabled', true).text('Đang test...');
                $('#ffmpeg-test-result').hide();
                
                $.post(ajaxurl, {
                    action: 'bizcity_kling_check_ffmpeg',
                    ffmpeg_path: ffmpegPath,
                    nonce: '<?php echo wp_create_nonce('bizcity_kling_nonce'); ?>'
                }, function(response) {
                    btn.prop('disabled', false).text('Test FFmpeg');
                    
                    var resultDiv = $('#ffmpeg-test-result');
                    if (response.success && response.data.available) {
                        resultDiv.html('<div class="notice notice-success"><p><strong>✅ FFmpeg hoạt động!</strong><br>' + 
                            'Path: <code>' + response.data.path + '</code><br>' +
                            'Version: <code>' + response.data.version + '</code>' +
                            (response.data.output ? '<br><pre style="margin-top:10px;background:#f5f5f5;padding:10px;font-size:12px;">' + response.data.output + '</pre>' : '') +
                            '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p><strong>❌ FFmpeg không hoạt động</strong><br>' + 
                            (response.data ? response.data.error || 'Unknown error' : 'Request failed') + '</p></div>');
                    }
                    resultDiv.show();
                });
            });
        });
        </script>
        
        <style>
        .card h2 { margin-top: 0; }
        .card h3 { margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        #test-result pre { background: #f5f5f5; padding: 10px; overflow: auto; }
        </style>
        <?php
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('bizcity_kling_test_api');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $endpoint = esc_url_raw($_POST['endpoint'] ?? '');
        
        if (!$api_key) {
            wp_send_json_error(['message' => 'Missing API key']);
        }
        
        // Test với một request đơn giản
        $settings = [
            'api_key' => $api_key,
            'endpoint' => $endpoint,
        ];
        
        // Gọi API list models hoặc test endpoint
        $url = untrailingslashit($endpoint) . '/models'; // hoặc endpoint test khác
        $result = waic_kling_http_get($url, [
            'X-API-Key' => $api_key,
        ], 30);
        
        if ($result['ok']) {
            wp_send_json_success([
                'message' => 'API connection successful',
                'data' => $result['data'],
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? 'Unknown error',
                'raw' => $result['raw'] ?? null,
            ]);
        }
    }
}
