<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Admin Menu & Dashboard for Knowledge Module
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_Admin_Menu {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'database_update_notice']);
        
        // AJAX handlers
        add_action('wp_ajax_bizcity_knowledge_save_character', [$this, 'ajax_save_character']);
        add_action('wp_ajax_bizcity_knowledge_delete_character', [$this, 'ajax_delete_character']);
        add_action('wp_ajax_bizcity_knowledge_quick_update_status', [$this, 'ajax_quick_update_status']);
        add_action('wp_ajax_bizcity_knowledge_test_openrouter', [$this, 'ajax_test_openrouter']);
        add_action('wp_ajax_bizcity_knowledge_fetch_models', [$this, 'ajax_fetch_models']);
        add_action('wp_ajax_bizcity_knowledge_chat', [$this, 'ajax_chat']);
        add_action('wp_ajax_bizcity_knowledge_upload_document', [$this, 'ajax_upload_document']);
        add_action('wp_ajax_bizcity_knowledge_delete_document', [$this, 'ajax_delete_document']);
        add_action('wp_ajax_bizcity_knowledge_update_database', [$this, 'ajax_update_database']);
        add_action('wp_ajax_bizcity_knowledge_reprocess_document', [$this, 'ajax_reprocess_document']);
        add_action('wp_ajax_bizcity_knowledge_add_website', [$this, 'ajax_add_website']);
        add_action('wp_ajax_bizcity_knowledge_delete_website', [$this, 'ajax_delete_website']);
        add_action('wp_ajax_bizcity_knowledge_process_website', [$this, 'ajax_process_website']);
        add_action('wp_ajax_bizcity_knowledge_import_legacy_faq', [$this, 'ajax_import_legacy_faq']);
        add_action('wp_ajax_bizcity_knowledge_export_knowledge', [$this, 'ajax_export_knowledge']);
        add_action('wp_ajax_bizcity_knowledge_import_knowledge', [$this, 'ajax_import_knowledge']);
        add_action('wp_ajax_bizcity_knowledge_duplicate_character', [$this, 'ajax_duplicate_character']);
        add_action('wp_ajax_bizcity_knowledge_check_slug', [$this, 'ajax_check_slug']);

        // Memory Requests tracking
        add_action('wp_ajax_bizcity_knowledge_delete_memory', [$this, 'ajax_delete_memory']);
        // Knowledge Fabric — scope promote
        add_action('wp_ajax_bizcity_knowledge_promote_source', [$this, 'ajax_promote_source']);
    }
    
    /**
     * Show database update notice if needed
     */
    public function database_update_notice() {
        $current_version = get_option('bizcity_knowledge_db_version', '');
        
        if ($current_version !== BizCity_Knowledge_Database::SCHEMA_VERSION) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>BizCity Knowledge:</strong> <?php printf( esc_html__( 'Database update needed to version %s.', 'bizcity-twin-ai' ), BizCity_Knowledge_Database::SCHEMA_VERSION ); ?>
                    <button type="button" class="button button-primary" id="bizcity-update-db" style="margin-left: 10px;">
                        <?php esc_html_e( 'Update Now', 'bizcity-twin-ai' ); ?>
                    </button>
                    <span class="spinner" style="float: none; margin: 0 10px;"></span>
                    <span id="update-db-result"></span>
                </p>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#bizcity-update-db').on('click', function() {
                    var $btn = $(this);
                    var $spinner = $btn.next('.spinner');
                    var $result = $('#update-db-result');
                    
                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'bizcity_knowledge_update_database',
                            nonce: '<?php echo wp_create_nonce('bizcity_knowledge_update_db'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<span style="color:green;">✓ <?php esc_html_e( "Updated successfully! Reloading...", "bizcity-twin-ai" ); ?></span>');
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                $result.html('<span style="color:red;">✗ ' + (response.data.message || '<?php esc_html_e( "Error", "bizcity-twin-ai" ); ?>') + '</span>');
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function() {
                            $result.html('<span style="color:red;">✗ <?php esc_html_e( "Connection error", "bizcity-twin-ai" ); ?></span>');
                            $btn.prop('disabled', false);
                        },
                        complete: function() {
                            $spinner.removeClass('is-active');
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * AJAX: Update database
     */
    public function ajax_update_database() {
        check_ajax_referer('bizcity_knowledge_update_db', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        try {
            // Force database update
            $db = new BizCity_Knowledge_Database();
            $db->create_tables();
            
            wp_send_json_success(['message' => __( 'Database updated successfully', 'bizcity-twin-ai' )]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    public function add_menu() {
        $td = 'bizcity-twin-ai';

        // Main menu
        add_menu_page(
            __( 'Teach AI', $td ),
            __( 'Teach AI', $td ),
            'manage_options',
            'bizcity-knowledge',
            [$this, 'render_maturity_dashboard'],
            plugins_url( 'assets/icon/joy.png', BIZCITY_KNOWLEDGE_DIR . 'bootstrap.php' ),
            28
        );
        
        // Submenu: Dashboard (Maturity Overview)
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Knowledge Dashboard', $td ),
            '📊 ' . __( 'Dashboard', $td ),
            'manage_options',
            'bizcity-knowledge',
            [$this, 'render_maturity_dashboard']
        );
        
        // Submenu: Training (Quick FAQ, Documents, Knowledge)
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Training', $td ),
            '📚 ' . __( 'Training', $td ),
            'manage_options',
            'bizcity-knowledge-training',
            [$this, 'render_training_page']
        );
        
        // Submenu: Memory Hub (Memory, Episodic, Rolling, Research)
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Memory Hub', $td ),
            '🧠 ' . __( 'Memory', $td ),
            'manage_options',
            'bizcity-knowledge-memory-hub',
            [$this, 'render_memory_hub_page']
        );
        
        // Submenu: Chat Monitor (Sessions, Goals, Messages, Trend)
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Chat Monitor', $td ),
            '💬 ' . __( 'Chat Monitor', $td ),
            'manage_options',
            'bizcity-knowledge-monitor',
            [$this, 'render_monitor_page']
        );

        

        // Submenu: Trợ lý AI — Character list
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Trợ lý AI', $td ),
            '🤖 ' . __( 'Trợ lý AI', $td ),
            'manage_options',
            'bizcity-knowledge-characters',
            [$this, 'render_characters_page']
        );

        // Hidden submenu: Character Edit (no sidebar link, accessed via list page)
        add_submenu_page(
            null,
            __( 'Chỉnh sửa Trợ lý AI', $td ),
            __( 'Chỉnh sửa Trợ lý AI', $td ),
            'manage_options',
            'bizcity-knowledge-character-edit',
            [$this, 'render_character_edit_page']
        );

        // Submenu: Learn with AI (Notebook companion)
        add_submenu_page(
            'bizcity-knowledge',
            __( 'Dạy AI bằng sổ tay', $td ),
            '📖 ' . __( 'Dạy AI bằng sổ tay', $td ),
            'read',
            'bizcity-knowledge-notebook',
            [$this, 'render_notebook_page']
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'bizcity-knowledge') === false) {
            return;
        }

        // Load character-edit specific assets
        $is_character_edit = strpos($hook, 'bizcity-knowledge-character-edit') !== false
            || strpos($hook, 'knowledge-character-edit') !== false;

        if ($is_character_edit) {
            wp_enqueue_style(
                'bizcity-knowledge-character-edit',
                plugins_url('assets/css/character-edit.css', dirname(__FILE__)),
                [],
                BIZCITY_KNOWLEDGE_VERSION
            );
            wp_enqueue_script(
                'bizcity-knowledge-character-edit',
                plugins_url('assets/js/character-edit.js', dirname(__FILE__)),
                ['jquery', 'wp-util'],
                BIZCITY_KNOWLEDGE_VERSION,
                true
            );
            wp_localize_script('bizcity-knowledge-character-edit', 'bizcity_knowledge_vars', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('bizcity_knowledge'),
            ]);
            wp_enqueue_media();
            return;
        }

        // Load maturity dashboard assets for Training, Memory Hub, Monitor, and Dashboard pages
        $maturity_pages = ['bizcity-knowledge-training', 'bizcity-knowledge-memory-hub', 'bizcity-knowledge-monitor'];
        $is_maturity_page = false;
        foreach ($maturity_pages as $page_slug) {
            if (strpos($hook, $page_slug) !== false) {
                $is_maturity_page = true;
                break;
            }
        }
        // Dashboard page also uses maturity assets
        if (!$is_maturity_page && preg_match('/page_bizcity-knowledge$/', $hook)) {
            $is_maturity_page = true;
        }
        if ($is_maturity_page && class_exists('BizCity_Maturity_Dashboard')) {
            BizCity_Maturity_Dashboard::do_enqueue_assets();
            return; // Only maturity assets needed for these pages
        }
        
        wp_enqueue_style(
            'bizcity-knowledge-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            [],
            BIZCITY_KNOWLEDGE_VERSION
        );
        
        wp_enqueue_script(
            'bizcity-knowledge-admin',
            plugins_url('assets/js/admin.js', dirname(__FILE__)),
            ['jquery', 'wp-util'],
            BIZCITY_KNOWLEDGE_VERSION,
            true
        );
        
        wp_localize_script('bizcity-knowledge-admin', 'bizcity_knowledge_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bizcity_knowledge'),
        ]);
        
        // Media uploader
        wp_enqueue_media();
    }
    
    /**
     * Render Maturity Dashboard (replaces old Knowledge Dashboard)
     */
    public function render_maturity_dashboard() {
        if ( defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
            $template = BIZCITY_TWIN_CORE_DIR . '/templates/maturity-dashboard.php';
            if ( file_exists( $template ) ) {
                include $template;
                return;
            }
        }
        echo '<div class="wrap"><h1>Knowledge Dashboard</h1><p>Twin Core module not found.</p></div>';
    }

    /**
     * Render Training page (Quick FAQ, Documents, Knowledge)
     */
    public function render_training_page() {
        if ( defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
            $template = BIZCITY_TWIN_CORE_DIR . '/templates/admin-training.php';
            if ( file_exists( $template ) ) {
                include $template;
                return;
            }
        }
        echo '<div class="wrap"><h1>Đào tạo AI</h1><p>Template not found.</p></div>';
    }

    /**
     * Render Memory Hub page (Memory, Episodic, Rolling, Research)
     */
    public function render_memory_hub_page() {
        if ( defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
            $template = BIZCITY_TWIN_CORE_DIR . '/templates/admin-memory.php';
            if ( file_exists( $template ) ) {
                include $template;
                return;
            }
        }
        echo '<div class="wrap"><h1>Memory Hub</h1><p>Template not found.</p></div>';
    }

    /**
     * Render Chat Monitor page (Sessions, Goals, Messages, Trend)
     */
    public function render_monitor_page() {
        if ( defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
            $template = BIZCITY_TWIN_CORE_DIR . '/templates/admin-monitor.php';
            if ( file_exists( $template ) ) {
                include $template;
                return;
            }
        }
        echo '<div class="wrap"><h1>Chat Monitor</h1><p>Template not found.</p></div>';
    }

    /**
     * Render Notebook companion page (Learn with AI)
     */
    public function render_notebook_page() {
        if ( class_exists( 'BCN_Admin_Page' ) ) {
            BCN_Admin_Page::enqueue_note_assets();
            echo '<div id="bcn-app" class="bcn-wrap" style="min-height:80vh;margin:-8px -20px 0"></div>';
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'Learn with AI', 'bizcity-twin-ai' ) . '</h1>';
        echo '<p>' . esc_html__( 'The Companion Notebook plugin is not active. Please activate it in Plugins.', 'bizcity-twin-ai' ) . '</p></div>';
    }

    /**
     * Render Dashboard with Guide (legacy — kept for reference)
     */
    public function render_dashboard() {
        $db = BizCity_Knowledge_Database::instance();
        $characters = $db->get_characters(['limit' => 5]);
        $total_characters = count($db->get_characters(['limit' => 1000]));
        ?>
        <style>
            .bk-wrap { max-width: 1400px; margin-top: 20px; }
            .bk-hero {
                background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
                color: #fff;
                padding: 40px;
                border-radius: 16px;
                margin-bottom: 30px;
            }
            .bk-hero h1 { margin: 0 0 10px 0; font-size: 32px; }
            .bk-hero p { margin: 0; opacity: 0.9; font-size: 16px; }
            .bk-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            .bk-stat-card {
                background: #fff;
                padding: 24px;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                text-align: center;
            }
            .bk-stat-card .number { font-size: 36px; font-weight: bold; color: #6366f1; }
            .bk-stat-card .label { color: #666; margin-top: 5px; }
            .bk-guide-section {
                background: #fff;
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            .bk-guide-section h2 {
                margin-top: 0;
                padding-bottom: 15px;
                border-bottom: 2px solid #f0f0f0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .bk-guide-section h2 .icon { font-size: 28px; }
            .bk-steps {
                counter-reset: step;
                list-style: none;
                padding: 0;
                margin: 20px 0;
            }
            .bk-steps li {
                position: relative;
                padding-left: 60px;
                margin-bottom: 25px;
                min-height: 50px;
            }
            .bk-steps li::before {
                counter-increment: step;
                content: counter(step);
                position: absolute;
                left: 0;
                top: 0;
                width: 40px;
                height: 40px;
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: #fff;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 16px;
            }
            .bk-steps li h4 { margin: 0 0 5px 0; color: #333; }
            .bk-steps li p { margin: 0; color: #666; line-height: 1.6; }
            .bk-feature-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .bk-feature-card {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 20px;
            }
            .bk-feature-card .icon { font-size: 32px; margin-bottom: 10px; }
            .bk-feature-card h4 { margin: 0 0 8px 0; color: #334155; }
            .bk-feature-card p { margin: 0; color: #64748b; font-size: 14px; }
            .bk-btn-primary {
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: #fff;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }
            .bk-btn-primary:hover { opacity: 0.9; color: #fff; }
            .bk-workflow-diagram {
                background: #f8fafc;
                border: 2px dashed #e2e8f0;
                border-radius: 12px;
                padding: 30px;
                text-align: center;
                margin: 20px 0;
            }
            .bk-workflow-diagram .flow {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .bk-workflow-diagram .node {
                background: #fff;
                border: 2px solid #6366f1;
                border-radius: 8px;
                padding: 10px 20px;
                font-weight: 600;
            }
            .bk-workflow-diagram .arrow { color: #6366f1; font-size: 24px; }
            .bk-monetize-section {
                background: linear-gradient(135deg, #fef3c7, #fde68a);
                border-radius: 12px;
                padding: 30px;
                margin-top: 20px;
            }
            .bk-monetize-section h3 { margin-top: 0; color: #92400e; }
            .bk-monetize-section ul { margin: 15px 0; padding-left: 20px; }
            .bk-monetize-section li { margin-bottom: 8px; color: #78350f; }
        </style>
        
        <div class="wrap bk-wrap">
            <div class="bk-hero">
                <h1>🧠 <?php echo esc_html__( 'AI Assistant Team', 'bizcity-twin-ai' ); ?></h1>
                <p><?php echo esc_html__( 'Build a smart AI assistant team with custom knowledge, serving your work and earning on marketplace.', 'bizcity-twin-ai' ); ?></p>
            </div>
            
            <!-- Stats -->
            <div class="bk-stats-grid">
                <div class="bk-stat-card">
                    <div class="number"><?php echo esc_html($total_characters); ?></div>
                    <div class="label"><?php esc_html_e( 'AI Assistants', 'bizcity-twin-ai' ); ?></div>
                </div>
                <div class="bk-stat-card">
                    <div class="number"><?php echo esc_html($this->count_knowledge_sources()); ?></div>
                    <div class="label"><?php esc_html_e( 'Knowledge Sources', 'bizcity-twin-ai' ); ?></div>
                </div>
                <div class="bk-stat-card">
                    <div class="number"><?php echo esc_html($this->count_total_conversations()); ?></div>
                    <div class="label"><?php esc_html_e( 'Conversations', 'bizcity-twin-ai' ); ?></div>
                </div>
                <div class="bk-stat-card">
                    <div class="number"><?php echo esc_html($this->count_published_characters()); ?></div>
                    <div class="label"><?php esc_html_e( 'Published', 'bizcity-twin-ai' ); ?></div>
                </div>
            </div>
            
            <!-- Quick Start Guide -->
            <div class="bk-guide-section">
                <h2><span class="icon">🚀</span> <?php esc_html_e( 'Quick Start', 'bizcity-twin-ai' ); ?></h2>
                
                <ol class="bk-steps">
                    <li>
                        <h4><?php esc_html_e( 'Create New AI Assistant', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Set name, description, avatar and system prompt. Define capabilities and industry.', 'bizcity-twin-ai' ); ?></p>
                    </li>
                    <li>
                        <h4><?php esc_html_e( 'Feed Knowledge', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Import knowledge from multiple sources: Quick FAQ, upload files (CSV, PDF, Excel), crawl website, or sync from Fanpage.', 'bizcity-twin-ai' ); ?></p>
                    </li>
                    <li>
                        <h4><?php esc_html_e( 'Configure Intents', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Set up intents the assistant can recognize, along with variables to extract as output.', 'bizcity-twin-ai' ); ?></p>
                    </li>
                    <li>
                        <h4><?php esc_html_e( 'Integrate with Workflow', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Connect assistant with bizcity-automation to automate tasks based on AI analysis.', 'bizcity-twin-ai' ); ?></p>
                    </li>
                    <li>
                        <h4><?php esc_html_e( 'Publish & Earn', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'When ready, publish to bizcity-agent-market to get work, chat and earn income.', 'bizcity-twin-ai' ); ?></p>
                    </li>
                </ol>
                
                <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>" class="bk-btn-primary">
                    ➕ <?php esc_html_e( 'Create First Assistant', 'bizcity-twin-ai' ); ?>
                </a>
                <button type="button" class="bk-btn-primary bk-import-json-btn" id="dashboard-import-json-btn" style="margin-left: 10px; background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <span class="dashicons dashicons-upload" style="color:white;vertical-align:middle;"></span> <?php esc_html_e( 'Import from JSON', 'bizcity-twin-ai' ); ?>
                </button>
            </div>
            
            <!-- Knowledge Sources -->
            <div class="bk-guide-section">
                <h2><span class="icon">📚</span> <?php esc_html_e( 'Knowledge Sources', 'bizcity-twin-ai' ); ?></h2>
                <p><?php esc_html_e( 'Assistants can learn from various data sources:', 'bizcity-twin-ai' ); ?></p>
                
                <div class="bk-feature-grid">
                    <div class="bk-feature-card">
                        <div class="icon">📋</div>
                        <h4>Quick FAQ</h4>
                        <p><?php esc_html_e( 'Use the built-in quick_faq post type to create Q&A sets for character reference.', 'bizcity-twin-ai' ); ?></p>
                    </div>
                    <div class="bk-feature-card">
                        <div class="icon">📁</div>
                        <h4><?php esc_html_e( 'Upload Files', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Upload CSV, Excel, PDF, JSON to Media Library (R2). System will parse and chunk for context.', 'bizcity-twin-ai' ); ?></p>
                    </div>
                    <div class="bk-feature-card">
                        <div class="icon">🌐</div>
                        <h4><?php esc_html_e( 'From Website', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Crawl content from other websites, supports Simple HTML or Text-only mode.', 'bizcity-twin-ai' ); ?></p>
                    </div>
                    <div class="bk-feature-card">
                        <div class="icon">📱</div>
                        <h4><?php esc_html_e( 'Sync Fanpage', 'bizcity-twin-ai' ); ?></h4>
                        <p><?php esc_html_e( 'Connect with Facebook Fanpage to auto-import posts and comments as knowledge.', 'bizcity-twin-ai' ); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Workflow Integration -->
            <div class="bk-guide-section">
                <h2><span class="icon">⚡</span> <?php esc_html_e( 'Workflow Integration', 'bizcity-twin-ai' ); ?></h2>
                <p><?php esc_html_e( 'Assistants work as "AI Agents" in bizcity-automation:', 'bizcity-twin-ai' ); ?></p>
                
                <div class="bk-workflow-diagram">
                    <div class="flow">
                        <div class="node">🔔 <?php esc_html_e( 'Trigger', 'bizcity-twin-ai' ); ?><br><small>(Webchat, Zalo, FB)</small></div>
                        <div class="arrow">→</div>
                        <div class="node">🤖 <?php esc_html_e( 'AI Character', 'bizcity-twin-ai' ); ?><br><small>(<?php esc_html_e( 'Parse Intent', 'bizcity-twin-ai' ); ?>)</small></div>
                        <div class="arrow">→</div>
                        <div class="node">📤 <?php esc_html_e( 'Output Variables', 'bizcity-twin-ai' ); ?><br><small>(intent, data)</small></div>
                        <div class="arrow">→</div>
                        <div class="node">⚙️ <?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?><br><small>(Workflow)</small></div>
                    </div>
                </div>
                
                <p><strong><?php esc_html_e( 'Example:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'User sends "I want to order 2 shirts size M" → Assistant parses intent = "order" and extracts variables:', 'bizcity-twin-ai' ); ?> <code>{quantity: 2, product_type: "shirt", size: "M"}</code> → <?php esc_html_e( 'Workflow automatically creates the order.', 'bizcity-twin-ai' ); ?></p>
            </div>
            
            <!-- Monetization -->
            <div class="bk-guide-section">
                <h2><span class="icon">💰</span> <?php esc_html_e( 'Earn with AI Assistants', 'bizcity-twin-ai' ); ?></h2>
                
                <div class="bk-monetize-section">
                    <h3>🏪 BizCity Agent Market</h3>
                    <p><?php esc_html_e( 'When your character is ready, you can:', 'bizcity-twin-ai' ); ?></p>
                    <ul>
                        <li>✅ <strong><?php esc_html_e( 'Publish:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'Appear on marketplace with profile & ratings', 'bizcity-twin-ai' ); ?></li>
                        <li>✅ <strong><?php esc_html_e( 'Auto-accept jobs:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'Clients hire character for work', 'bizcity-twin-ai' ); ?></li>
                        <li>✅ <strong><?php esc_html_e( 'Chat & Consult:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'Integrate webchat widget for direct interaction', 'bizcity-twin-ai' ); ?></li>
                        <li>✅ <strong><?php esc_html_e( 'Per-use/package pricing:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'Set pricing for character services', 'bizcity-twin-ai' ); ?></li>
                    </ul>
                    
                    <a href="<?php echo admin_url('admin.php?page=bizcity-agent-market'); ?>" class="bk-btn-primary" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        🏪 <?php esc_html_e( 'Open Agent Market', 'bizcity-twin-ai' ); ?>
                    </a>
                </div>
            </div>
            
            <!-- Recent Characters -->
            <?php if (!empty($characters)): ?>
            <div class="bk-guide-section">
                <h2><span class="icon">🤖</span> <?php esc_html_e( 'Recent Assistants', 'bizcity-twin-ai' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Avatar', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Conversations', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Rating', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($characters as $char): ?>
                        <tr>
                            <td style="width: 50px;">
                                <?php if ($char->avatar): ?>
                                    <img src="<?php echo esc_url($char->avatar); ?>" style="width:40px;height:40px;border-radius:50%;">
                                <?php else: ?>
                                    <span style="font-size:32px;">🤖</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html($char->name); ?></strong></td>
                            <td>
                                <span class="status-<?php echo esc_attr($char->status); ?>">
                                    <?php echo esc_html(ucfirst($char->status)); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($char->total_conversations); ?></td>
                            <td><?php echo number_format($char->rating, 1); ?> ⭐</td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $char->id); ?>"><?php esc_html_e( 'Edit', 'bizcity-twin-ai' ); ?></a> |
                                <a href="#" class="duplicate-character" data-id="<?php echo $char->id; ?>" data-name="<?php echo esc_attr($char->name); ?>" style="color:#059669;"><?php esc_html_e( 'Clone', 'bizcity-twin-ai' ); ?></a> |
                                <a href="#" class="delete-character" data-id="<?php echo $char->id; ?>" style="color:#a00;"><?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render Characters List Page
     */
    public function render_characters_page() {
        $db = BizCity_Knowledge_Database::instance();
        $characters = $db->get_characters(['limit' => 100]);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'AI Assistants', 'bizcity-twin-ai' ); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>" class="page-title-action"><?php esc_html_e( 'Create New', 'bizcity-twin-ai' ); ?></a>
            <button type="button" class="page-title-action bk-import-json-btn" id="import-character-json-btn" style="background:#3b82f6;color:white;border-color:#2563eb;">
                <span class="dashicons dashicons-upload" style="color:white;"></span> Import JSON
            </button>
            <hr class="wp-header-end">
            
            <table class="wp-list-table widefat fixed striped" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th style="width:60px;">Avatar</th>
                        <th><?php esc_html_e( 'Name', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Web Visibility', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:200px;">Shortcode</th>
                        <th><?php esc_html_e( 'Sources', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Conversations', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Rating', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($characters)): ?>
                    <tr><td colspan="11"><?php esc_html_e( 'No assistants yet.', 'bizcity-twin-ai' ); ?> <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>"><?php esc_html_e( 'Create new', 'bizcity-twin-ai' ); ?></a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Card-based character list (mobile-friendly) -->
            <?php if (empty($characters)): ?>
            <div class="bk-empty-characters">
                <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
                    <div style="font-size:48px;margin-bottom:12px;">🤖</div>
                    <p><?php esc_html_e( 'No assistants yet.', 'bizcity-twin-ai' ); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>" class="button button-primary"><?php esc_html_e( 'Create New', 'bizcity-twin-ai' ); ?></a>
                </div>
            </div>
            <?php else: ?>
            <div class="bk-char-grid">
                <?php foreach ($characters as $char): 
                    $sources = $db->get_knowledge_sources($char->id);
                    $show_on_web = in_array($char->status, ['active', 'published']);
                ?>
                <div class="bk-char-card" data-character-id="<?php echo $char->id; ?>">
                    <!-- Card Header: Avatar + Name + Status -->
                    <div class="bk-char-card-header">
                        <div class="bk-char-card-avatar">
                            <?php if ($char->avatar): ?>
                                <img src="<?php echo esc_url($char->avatar); ?>" alt="">
                            <?php else: ?>
                                <span>🤖</span>
                            <?php endif; ?>
                        </div>
                        <div class="bk-char-card-title">
                            <strong><?php echo esc_html($char->name); ?></strong>
                            <span class="bk-char-card-slug"><?php echo esc_html($char->slug); ?></span>
                        </div>
                        <span class="status-badge status-<?php echo esc_attr($char->status); ?>"><?php echo esc_html(ucfirst($char->status)); ?></span>
                    </div>
                    
                    <!-- Description -->
                    <?php if ($char->description): ?>
                    <p class="bk-char-card-desc"><?php echo esc_html(wp_trim_words($char->description, 20)); ?></p>
                    <?php endif; ?>
                    
                    <!-- Stats Row -->
                    <div class="bk-char-card-stats">
                        <span title="<?php esc_attr_e( 'Knowledge sources', 'bizcity-twin-ai' ); ?>">📚 <?php echo count($sources); ?></span>
                        <span title="Conversations">💬 <?php echo number_format($char->total_conversations); ?></span>
                        <span title="Rating"><?php echo number_format($char->rating, 1); ?> ⭐</span>
                    </div>
                    
                    <!-- Visibility Toggle + Shortcode -->
                    <div class="bk-char-card-meta">
                        <div class="bk-char-card-vis">
                            <label class="bk-toggle-switch" title="<?php esc_attr_e( 'Show on website', 'bizcity-twin-ai' ); ?>">
                                <input type="checkbox" 
                                    class="bk-web-visibility-toggle" 
                                    data-id="<?php echo $char->id; ?>"
                                    data-current-status="<?php echo esc_attr($char->status); ?>"
                                    <?php checked($show_on_web); ?>>
                                <span class="bk-toggle-slider"></span>
                            </label>
                            <span class="bk-visibility-label" style="font-size: 12px; color: <?php echo $show_on_web ? '#10b981' : '#6b7280'; ?>;">
                                <?php echo $show_on_web ? '✓ ' . esc_html__( 'Visible', 'bizcity-twin-ai' ) : '✗ ' . esc_html__( 'Hidden', 'bizcity-twin-ai' ); ?>
                            </span>
                        </div>
                        <div class="bk-shortcode-cell">
                            <code class="bk-shortcode-text">[chatbot character_id="<?php echo $char->id; ?>"]</code>
                            <button type="button" class="button button-small bk-copy-shortcode" data-shortcode='[chatbot character_id="<?php echo $char->id; ?>"]' title="Copy shortcode">
                                <span class="dashicons dashicons-admin-page" style="font-size:13px;width:13px;height:13px;margin-top:2px;"></span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="bk-char-card-actions">
                        <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $char->id); ?>" class="button button-primary button-small">✏️ <?php esc_html_e( 'Edit', 'bizcity-twin-ai' ); ?></a>
                        <a href="#" class="button button-small duplicate-character" data-id="<?php echo $char->id; ?>" data-name="<?php echo esc_attr($char->name); ?>">📋 <?php esc_html_e( 'Clone', 'bizcity-twin-ai' ); ?></a>
                        <a href="#" class="button button-small bk-btn-danger delete-character" data-id="<?php echo $char->id; ?>">🗑 <?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <style>
            /* ── Status Badge ── */
            .status-badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; white-space: nowrap; }
            .status-draft { background: #e5e7eb; color: #374151; }
            .status-active { background: #d1fae5; color: #065f46; }
            .status-published { background: #dbeafe; color: #1e40af; }
            .status-archived { background: #fef3c7; color: #92400e; }
            
            /* ── Card Grid ── */
            .bk-char-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
                gap: 16px;
                margin-top: 16px;
            }
            .bk-char-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 16px;
                transition: box-shadow 0.2s, border-color 0.2s;
            }
            .bk-char-card:hover {
                border-color: #a5b4fc;
                box-shadow: 0 4px 16px rgba(99,102,241,0.1);
            }
            
            /* Card Header */
            .bk-char-card-header {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 10px;
            }
            .bk-char-card-avatar {
                width: 48px; height: 48px;
                border-radius: 50%;
                overflow: hidden;
                flex-shrink: 0;
                background: #f3f4f6;
                display: flex; align-items: center; justify-content: center;
            }
            .bk-char-card-avatar img {
                width: 48px; height: 48px;
                border-radius: 50%;
                object-fit: cover;
            }
            .bk-char-card-avatar span { font-size: 28px; }
            .bk-char-card-title {
                flex: 1;
                min-width: 0;
            }
            .bk-char-card-title strong {
                display: block;
                font-size: 15px;
                color: #1a1a2e;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .bk-char-card-slug {
                font-size: 11px;
                color: #9ca3af;
                font-family: monospace;
            }
            
            /* Description */
            .bk-char-card-desc {
                margin: 0 0 10px;
                font-size: 13px;
                color: #6b7280;
                line-height: 1.5;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            
            /* Stats Row */
            .bk-char-card-stats {
                display: flex;
                gap: 16px;
                margin-bottom: 12px;
                font-size: 13px;
                color: #6b7280;
            }
            .bk-char-card-stats span {
                display: flex; align-items: center; gap: 3px;
            }
            
            /* Meta: visibility + shortcode */
            .bk-char-card-meta {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 12px;
                padding: 10px 0;
                border-top: 1px solid #f3f4f6;
                border-bottom: 1px solid #f3f4f6;
            }
            .bk-char-card-vis {
                display: flex; align-items: center; gap: 8px;
            }
            
            /* Actions */
            .bk-char-card-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .bk-char-card-actions .button {
                font-size: 12px;
            }
            .bk-btn-danger {
                color: #dc2626 !important;
                border-color: #fca5a5 !important;
            }
            .bk-btn-danger:hover {
                background: #fef2f2 !important;
                border-color: #f87171 !important;
            }
            
            /* Shortcode Cell */
            .bk-shortcode-cell {
                display: flex;
                align-items: center;
                gap: 6px;
                min-width: 0;
            }
            .bk-shortcode-text {
                background: #f8f9fa;
                padding: 3px 6px;
                border-radius: 4px;
                font-family: 'Courier New', monospace;
                font-size: 11px;
                color: #1e40af;
                border: 1px solid #e0e0e0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 170px;
            }
            .bk-copy-shortcode {
                padding: 2px 8px !important;
                height: 24px !important;
                min-height: 24px !important;
                line-height: 1 !important;
                border: 1px solid #0ea5e9 !important;
                background: #e0f2fe !important;
                color: #0369a1 !important;
                flex-shrink: 0;
            }
            .bk-copy-shortcode:hover {
                background: #0ea5e9 !important;
                color: #fff !important;
                border-color: #0284c7 !important;
            }
            .bk-copy-shortcode.copied {
                background: #10b981 !important;
                border-color: #059669 !important;
                color: #fff !important;
            }
            
            /* Toggle Switch */
            .bk-toggle-switch {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                vertical-align: middle;
            }
            .bk-toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .bk-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #cbd5e1;
                transition: .3s;
                border-radius: 24px;
            }
            .bk-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .3s;
                border-radius: 50%;
            }
            .bk-toggle-switch input:checked + .bk-toggle-slider {
                background-color: #10b981;
            }
            .bk-toggle-switch input:checked + .bk-toggle-slider:before {
                transform: translateX(20px);
            }
            .bk-toggle-switch input:disabled + .bk-toggle-slider {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* ── Responsive: single column on mobile ── */
            @media (max-width: 600px) {
                .bk-char-grid {
                    grid-template-columns: 1fr;
                    gap: 12px;
                }
                .bk-char-card { padding: 14px; }
                .bk-char-card-meta { flex-direction: column; align-items: flex-start; }
                .bk-shortcode-text { max-width: 200px; font-size: 10px; }
                .bk-char-card-actions { width: 100%; }
                .bk-char-card-actions .button { flex: 1; text-align: center; }
                .wp-heading-inline { font-size: 18px; }
                .page-title-action { font-size: 11px; padding: 4px 8px; }
            }
            @media (max-width: 782px) {
                .bk-char-grid { grid-template-columns: 1fr; }
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Handle web visibility toggle
            $('.bk-web-visibility-toggle').on('change', function() {
                var $checkbox = $(this);
                var characterId = $checkbox.data('id');
                var currentStatus = $checkbox.data('current-status');
                var isChecked = $checkbox.prop('checked');
                var $label = $checkbox.closest('.bk-char-card-vis, td').find('.bk-visibility-label');
                var $card = $checkbox.closest('.bk-char-card, tr');
                
                // Determine new status
                var newStatus;
                if (isChecked) {
                    // If turning on visibility:
                    // draft/archived -> active
                    // published stays published
                    newStatus = (currentStatus === 'published') ? 'published' : 'active';
                } else {
                    // If turning off visibility, set to draft
                    newStatus = 'draft';
                }
                
                // Disable toggle during save
                $checkbox.prop('disabled', true);
                $label.html('⏳ <?php esc_html_e( "Saving...", "bizcity-twin-ai" ); ?>');
                
                // AJAX save
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'bizcity_knowledge_quick_update_status',
                        nonce: '<?php echo wp_create_nonce('bizcity_knowledge'); ?>',
                        character_id: characterId,
                        status: newStatus
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update UI
                            $checkbox.data('current-status', newStatus);
                            $label.html(isChecked ? '✓ <?php esc_html_e( "Visible", "bizcity-twin-ai" ); ?>' : '✗ <?php esc_html_e( "Hidden", "bizcity-twin-ai" ); ?>');
                            $label.css('color', isChecked ? '#10b981' : '#6b7280');
                            
                            // Update status badge
                            var $statusBadge = $card.find('.status-badge');
                            $statusBadge.removeClass('status-draft status-active status-published status-archived')
                                .addClass('status-' + newStatus)
                                .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                            
                            // Show success message briefly
                            var originalText = $label.html();
                            $label.html('✓ <?php esc_html_e( "Saved!", "bizcity-twin-ai" ); ?>');
                            setTimeout(function() {
                                $label.html(originalText);
                            }, 2000);
                        } else {
                            alert('<?php esc_html_e( "Error:", "bizcity-twin-ai" ); ?> ' + (response.data.message || 'Cannot update'));
                            // Revert checkbox
                            $checkbox.prop('checked', !isChecked);
                            $label.html(isChecked ? '✗ <?php esc_html_e( "Hidden", "bizcity-twin-ai" ); ?>' : '✓ <?php esc_html_e( "Visible", "bizcity-twin-ai" ); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e( "Connection error. Please try again.", "bizcity-twin-ai" ); ?>');
                        // Revert checkbox
                        $checkbox.prop('checked', !isChecked);
                        $label.html(isChecked ? '✗ <?php esc_html_e( "Hidden", "bizcity-twin-ai" ); ?>' : '✓ <?php esc_html_e( "Visible", "bizcity-twin-ai" ); ?>');
                    },
                    complete: function() {
                        $checkbox.prop('disabled', false);
                    }
                });
            });
            
            // Handle copy shortcode button
            $('.bk-copy-shortcode').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var shortcode = $btn.data('shortcode');
                
                // Copy to clipboard
                navigator.clipboard.writeText(shortcode).then(function() {
                    // Visual feedback
                    var $icon = $btn.find('.dashicons');
                    var originalClass = $icon.attr('class');
                    
                    $btn.addClass('copied');
                    $icon.removeClass('dashicons-admin-page').addClass('dashicons-yes');
                    
                    setTimeout(function() {
                        $btn.removeClass('copied');
                        $icon.attr('class', originalClass);
                    }, 2000);
                }).catch(function(err) {
                    // Fallback for older browsers
                    var $temp = $('<textarea>');
                    $('body').append($temp);
                    $temp.val(shortcode).select();
                    document.execCommand('copy');
                    $temp.remove();
                    
                    // Visual feedback
                    var $icon = $btn.find('.dashicons');
                    var originalClass = $icon.attr('class');
                    
                    $btn.addClass('copied');
                    $icon.removeClass('dashicons-admin-page').addClass('dashicons-yes');
                    
                    setTimeout(function() {
                        $btn.removeClass('copied');
                        $icon.attr('class', originalClass);
                    }, 2000);
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render Character Edit Page
     */
    public function render_character_edit_page() {
        $db = BizCity_Knowledge_Database::instance();
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $character = $id ? $db->get_character($id) : null;
        $is_new = empty($character);
        
        // Load view file
        require_once BIZCITY_KNOWLEDGE_DIR . 'views/character-edit.php';
    }
    
    /**
     * OLD Render Character Edit Page - BACKUP
     */
    public function render_character_edit_page_old() {
        $db = BizCity_Knowledge_Database::instance();
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $character = $id ? $db->get_character($id) : null;
        $is_new = empty($character);
        
        // Get knowledge sources if editing
        $quick_knowledge = [];
        $documents = [];
        $websites = [];
        if ($id) {
            $sources = $db->get_knowledge_sources($id);
            foreach ($sources as $source) {
                if ($source->source_type === 'quick_faq') {
                    $quick_knowledge[] = $source;
                } elseif ($source->source_type === 'file') {
                    $documents[] = $source;
                } elseif ($source->source_type === 'url') {
                    $websites[] = $source;
                }
            }
        }
        ?>
        <div class="wrap bk-character-edit-wrap">
            <div class="bk-character-header">
                <div class="bk-header-left">
                    <div class="bk-character-avatar-mini">
                        <?php if (!empty($character->avatar)): ?>
                            <img src="<?php echo esc_url($character->avatar); ?>" alt="">
                        <?php else: ?>
                            <span class="bk-avatar-placeholder">👤</span>
                        <?php endif; ?>
                    </div>
                    <div class="bk-header-info">
                        <h1><?php echo $is_new ? esc_html__( 'Create New AI Assistant', 'bizcity-twin-ai' ) : esc_html($character->name); ?></h1>
                        <?php if (!$is_new): ?>
                            <div class="bk-character-meta">
                                <span class="bk-status bk-status-<?php echo esc_attr($character->status); ?>">
                                    <?php echo esc_html(ucfirst($character->status)); ?>
                                </span>
                                <span class="bk-created">Created: <?php echo date('M d, Y', strtotime($character->created_at)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bk-header-right">
                    <button type="button" class="button button-large bk-save-btn" id="save-character-btn">
                        <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save', 'bizcity-twin-ai' ); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" class="button button-large"><?php esc_html_e( 'Back', 'bizcity-twin-ai' ); ?></a>
                </div>
            </div>
            
            <form id="character-form" method="post">
                <?php wp_nonce_field('bizcity_knowledge', 'bizcity_knowledge_nonce'); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>" id="character-id">
                
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Name', 'bizcity-twin-ai' ); ?> *</th>
                        <td>
                            <input type="text" name="name" class="regular-text" required
                                value="<?php echo esc_attr($character->name ?? ''); ?>"
                                placeholder="<?php esc_attr_e( 'Ex: Sales Assistant', 'bizcity-twin-ai' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Slug</th>
                        <td>
                            <input type="text" name="slug" class="regular-text"
                                value="<?php echo esc_attr($character->slug ?? ''); ?>"
                                placeholder="<?php esc_attr_e( 'Auto-generated from name', 'bizcity-twin-ai' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Avatar</th>
                        <td>
                            <input type="text" name="avatar" id="avatar-url" class="regular-text"
                                value="<?php echo esc_url($character->avatar ?? ''); ?>"
                                placeholder="<?php esc_attr_e( 'Avatar image URL', 'bizcity-twin-ai' ); ?>">
                            <button type="button" class="button" id="select-avatar"><?php esc_html_e( 'Select', 'bizcity-twin-ai' ); ?></button>
                            <div id="avatar-preview" style="margin-top:10px;">
                                <?php if (!empty($character->avatar)): ?>
                                    <img src="<?php echo esc_url($character->avatar); ?>" style="max-width:100px;border-radius:50%;">
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Description', 'bizcity-twin-ai' ); ?></th>
                        <td>
                            <textarea name="description" rows="3" class="large-text"
                                placeholder="<?php esc_attr_e( 'Short description of character and capabilities', 'bizcity-twin-ai' ); ?>"><?php echo esc_textarea($character->description ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>System Prompt</th>
                        <td>
                            <textarea name="system_prompt" rows="8" class="large-text code"
                                placeholder="<?php esc_attr_e( 'System prompt defining the character...', 'bizcity-twin-ai' ); ?>"><?php echo esc_textarea($character->system_prompt ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e( 'This prompt is sent to AI each time the character processes a message.', 'bizcity-twin-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Capabilities', 'bizcity-twin-ai' ); ?></th>
                        <td>
                            <div class="bk-tag-input-wrapper">
                                <div class="bk-tag-input" id="capabilities-tags">
                                    <?php 
                                    $caps = $character->capabilities ?? [];
                                    if (is_string($caps)) {
                                        $caps = json_decode($caps, true) ?: [];
                                    }
                                    foreach ($caps as $cap): 
                                    ?>
                                        <span class="bk-tag">
                                            <span class="bk-tag-text"><?php echo esc_html($cap); ?></span>
                                            <button type="button" class="bk-tag-remove" data-value="<?php echo esc_attr($cap); ?>">×</button>
                                        </span>
                                    <?php endforeach; ?>
                                    <input type="text" class="bk-tag-input-field" placeholder="<?php esc_attr_e( 'Add capability...', 'bizcity-twin-ai' ); ?>" data-target="capabilities">
                                </div>
                                <input type="hidden" name="capabilities" id="capabilities-value" value="<?php echo esc_attr(json_encode($caps)); ?>">
                                <p class="description"><?php esc_html_e( 'Press Enter to add new capability', 'bizcity-twin-ai' ); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Industries', 'bizcity-twin-ai' ); ?></th>
                        <td>
                            <div class="bk-tag-input-wrapper">
                                <div class="bk-tag-input" id="industries-tags">
                                    <?php 
                                    $inds = $character->industries ?? [];
                                    if (is_string($inds)) {
                                        $inds = json_decode($inds, true) ?: [];
                                    }
                                    foreach ($inds as $ind): 
                                    ?>
                                        <span class="bk-tag">
                                            <span class="bk-tag-text"><?php echo esc_html($ind); ?></span>
                                            <button type="button" class="bk-tag-remove" data-value="<?php echo esc_attr($ind); ?>">×</button>
                                        </span>
                                    <?php endforeach; ?>
                                    <input type="text" class="bk-tag-input-field" placeholder="<?php esc_attr_e( 'Add industry...', 'bizcity-twin-ai' ); ?>" data-target="industries">
                                </div>
                                <input type="hidden" name="industries" id="industries-value" value="<?php echo esc_attr(json_encode($inds)); ?>">
                                <p class="description"><?php esc_html_e( 'Press Enter to add new industry', 'bizcity-twin-ai' ); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Output Variables<br><small style="font-weight:normal;color:#666;"><?php esc_html_e( 'Output Variables', 'bizcity-twin-ai' ); ?></small></th>
                        <td>
                            <div class="bk-output-vars-builder">
                                <div class="bk-output-vars-header">
                                    <span class="bk-header-icon">📤</span>
                                    <span><?php esc_html_e( 'Define variables extracted from AI response', 'bizcity-twin-ai' ); ?></span>
                                </div>
                                
                                <table class="bk-output-vars-table" id="output-vars-table">
                                    <thead>
                                        <tr>
                                            <th style="width:35%"><?php esc_html_e( 'Variable Name', 'bizcity-twin-ai' ); ?></th>
                                            <th style="width:25%"><?php esc_html_e( 'Data Type', 'bizcity-twin-ai' ); ?></th>
                                            <th style="width:30%"><?php esc_html_e( 'Description', 'bizcity-twin-ai' ); ?></th>
                                            <th style="width:10%"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="output-vars-body">
                                        <?php 
                                        $schema = $character->variables_schema ?? '{}';
                                        if (is_string($schema)) {
                                            $schema = json_decode($schema, true) ?: [];
                                        }
                                        if (empty($schema)) {
                                            // Mẫu mặc định
                                            $schema = [
                                                'intent' => ['type' => 'string', 'desc' => 'Request type'],
                                                'reply' => ['type' => 'string', 'desc' => 'AI response'],
                                                'confidence' => ['type' => 'number', 'desc' => 'Confidence (0-100)'],
                                            ];
                                        }
                                        foreach ($schema as $var_name => $var_config):
                                            $var_type = is_array($var_config) ? ($var_config['type'] ?? 'string') : $var_config;
                                            $var_desc = is_array($var_config) ? ($var_config['desc'] ?? '') : '';
                                        ?>
                                        <tr class="bk-output-var-row" draggable="true">
                                            <td>
                                                <div class="bk-var-name-wrap">
                                                    <span class="bk-drag-handle">⋮⋮</span>
                                                    <input type="text" class="bk-var-name" value="<?php echo esc_attr($var_name); ?>" placeholder="variable_name">
                                                </div>
                                            </td>
                                            <td>
                                                <select class="bk-var-type">
                                                    <option value="string" <?php selected($var_type, 'string'); ?>>String (Chuỗi)</option>
                                                    <option value="number" <?php selected($var_type, 'number'); ?>>Number (Số)</option>
                                                    <option value="boolean" <?php selected($var_type, 'boolean'); ?>>Boolean (Đúng/Sai)</option>
                                                    <option value="array" <?php selected($var_type, 'array'); ?>>Array (Mảng)</option>
                                                    <option value="object" <?php selected($var_type, 'object'); ?>>Object (Đối tượng)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="bk-var-desc" value="<?php echo esc_attr($var_desc); ?>" placeholder="<?php esc_attr_e( 'Description...', 'bizcity-twin-ai' ); ?>">
                                            </td>
                                            <td>
                                                <button type="button" class="bk-var-remove" title="<?php esc_attr_e( 'Remove', 'bizcity-twin-ai' ); ?>">×</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <div class="bk-output-vars-actions">
                                    <button type="button" class="button bk-add-var-btn" id="add-output-var">
                                        <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Variable', 'bizcity-twin-ai' ); ?>
                                    </button>
                                    <div class="bk-quick-add">
                                        <span><?php esc_html_e( 'Quick Add:', 'bizcity-twin-ai' ); ?></span>
                                        <button type="button" class="bk-quick-var" data-vars='[{"name":"title","type":"string","desc":"Title"},{"name":"content","type":"string","desc":"Main content"}]'>📝 <?php esc_html_e( 'Article', 'bizcity-twin-ai' ); ?></button>
                                        <button type="button" class="bk-quick-var" data-vars='[{"name":"product_name","type":"string","desc":"Product"},{"name":"price","type":"number","desc":"Price"},{"name":"quantity","type":"number","desc":"Qty"}]'>🛒 <?php esc_html_e( 'Order', 'bizcity-twin-ai' ); ?></button>
                                        <button type="button" class="bk-quick-var" data-vars='[{"name":"customer_name","type":"string","desc":"Customer"},{"name":"phone","type":"string","desc":"Phone"},{"name":"address","type":"string","desc":"Address"}]'>👤 <?php esc_html_e( 'Customer', 'bizcity-twin-ai' ); ?></button>
                                    </div>
                                </div>
                                
                                <div class="bk-output-preview">
                                    <div class="bk-preview-header">
                                        <span>📋 Preview JSON Schema</span>
                                        <button type="button" class="bk-toggle-preview">▼</button>
                                    </div>
                                    <pre class="bk-preview-json" id="schema-preview"></pre>
                                </div>
                                
                                <input type="hidden" name="variables_schema" id="variables-schema-value">
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                        <td>
                            <select name="status">
                                <option value="draft" <?php selected(($character->status ?? 'draft'), 'draft'); ?>>Draft</option>
                                <option value="active" <?php selected(($character->status ?? ''), 'active'); ?>>Active</option>
                                <option value="published" <?php selected(($character->status ?? ''), 'published'); ?>>Published (on Market)</option>
                                <option value="archived" <?php selected(($character->status ?? ''), 'archived'); ?>>Archived</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <?php echo $is_new ? esc_html__( 'Create', 'bizcity-twin-ai' ) : esc_html__( 'Update', 'bizcity-twin-ai' ); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" class="button"><?php esc_html_e( 'Cancel', 'bizcity-twin-ai' ); ?></a>
                </p>
            </form>
            
            <?php if (!$is_new): ?>
            <!-- Knowledge Sources Section -->
            <hr style="margin: 40px 0;">
            <h2>📚 <?php esc_html_e( 'Knowledge Sources', 'bizcity-twin-ai' ); ?></h2>
            <?php $this->render_knowledge_sources_section($id); ?>
            
            <!-- Intents Section -->
            <hr style="margin: 40px 0;">
            <h2>🎯 Intent Configuration</h2>
            <?php $this->render_intents_section($id); ?>
            <?php endif; ?>
        </div>
        
        <style>
            .bk-tag-input-wrapper { margin-bottom: 10px; }
            .bk-tag-input {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                padding: 5px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                background: #fff;
                min-height: 40px;
                align-items: center;
            }
            .bk-tag-input:focus-within {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }
            .bk-tag {
                display: inline-flex;
                align-items: center;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                padding: 3px 8px;
                font-size: 13px;
                line-height: 1.4;
                gap: 6px;
            }
            .bk-tag-text {
                color: #2c3338;
            }
            .bk-tag-remove {
                background: none;
                border: none;
                color: #50575e;
                cursor: pointer;
                padding: 0;
                font-size: 18px;
                line-height: 1;
                width: 16px;
                height: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .bk-tag-remove:hover {
                color: #d63638;
            }
            .bk-tag-input-field {
                flex: 1;
                border: none;
                outline: none;
                min-width: 150px;
                padding: 3px;
                font-size: 13px;
            }
            
            /* Output Variables Builder */
            .bk-output-vars-builder {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 8px;
                overflow: hidden;
            }
            .bk-output-vars-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 12px 16px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .bk-header-icon {
                font-size: 20px;
            }
            .bk-output-vars-table {
                width: 100%;
                border-collapse: collapse;
            }
            .bk-output-vars-table thead th {
                background: #f0f0f1;
                padding: 10px 12px;
                text-align: left;
                font-weight: 600;
                font-size: 13px;
                border-bottom: 2px solid #c3c4c7;
            }
            .bk-output-var-row {
                transition: all 0.2s ease;
            }
            .bk-output-var-row:hover {
                background: #f6f7f7;
            }
            .bk-output-var-row.dragging {
                opacity: 0.5;
                background: #e8f4fc;
            }
            .bk-output-var-row td {
                padding: 8px 12px;
                border-bottom: 1px solid #e2e4e7;
                vertical-align: middle;
            }
            .bk-var-name-wrap {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .bk-drag-handle {
                cursor: grab;
                color: #999;
                font-size: 14px;
                padding: 4px;
            }
            .bk-drag-handle:hover {
                color: #667eea;
            }
            .bk-var-name {
                flex: 1;
                padding: 6px 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
                background: #f9f9f9;
            }
            .bk-var-name:focus {
                border-color: #667eea;
                background: #fff;
                outline: none;
                box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
            }
            .bk-var-type {
                width: 100%;
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #fff;
            }
            .bk-var-desc {
                width: 100%;
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .bk-var-desc:focus, .bk-var-type:focus {
                border-color: #667eea;
                outline: none;
            }
            .bk-var-remove {
                background: none;
                border: none;
                color: #999;
                font-size: 20px;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            .bk-var-remove:hover {
                background: #fee2e2;
                color: #dc2626;
            }
            .bk-output-vars-actions {
                padding: 12px 16px;
                background: #f9fafb;
                border-top: 1px solid #e2e4e7;
                display: flex;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
            }
            .bk-add-var-btn {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .bk-add-var-btn .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .bk-quick-add {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .bk-quick-add span {
                color: #666;
                font-size: 13px;
            }
            .bk-quick-var {
                background: #e0e7ff;
                border: 1px solid #c7d2fe;
                color: #4338ca;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .bk-quick-var:hover {
                background: #c7d2fe;
                border-color: #a5b4fc;
            }
            .bk-output-preview {
                border-top: 1px solid #e2e4e7;
            }
            .bk-preview-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 16px;
                background: #f0f0f1;
                cursor: pointer;
                font-size: 13px;
                color: #666;
            }
            .bk-preview-header:hover {
                background: #e5e5e5;
            }
            .bk-toggle-preview {
                background: none;
                border: none;
                cursor: pointer;
                font-size: 12px;
                color: #666;
            }
            .bk-preview-json {
                margin: 0;
                padding: 12px 16px;
                background: #1e1e1e;
                color: #9cdcfe;
                font-family: Consolas, Monaco, monospace;
                font-size: 12px;
                line-height: 1.5;
                max-height: 200px;
                overflow: auto;
                display: none;
            }
            .bk-preview-json.active {
                display: block;
            }
            /* Row add animation */
            @keyframes rowSlideIn {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .bk-output-var-row.new-row {
                animation: rowSlideIn 0.3s ease;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Tag Input Handler
            function initTagInput() {
                $('.bk-tag-input-field').on('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        var value = $(this).val().trim().replace(/,$/g, '');
                        if (value === '') return;
                        
                        var target = $(this).data('target');
                        addTag(target, value);
                        $(this).val('');
                    }
                });
                
                $(document).on('click', '.bk-tag-remove', function(e) {
                    e.preventDefault();
                    var $tag = $(this).closest('.bk-tag');
                    var $wrapper = $tag.closest('.bk-tag-input');
                    var target = $wrapper.find('.bk-tag-input-field').data('target');
                    var value = $(this).data('value');
                    
                    $tag.remove();
                    updateHiddenField(target);
                });
            }
            
            function addTag(target, value) {
                var $container = $('#' + target + '-tags');
                var $input = $container.find('.bk-tag-input-field');
                
                // Check duplicate
                var exists = false;
                $container.find('.bk-tag').each(function() {
                    if ($(this).find('.bk-tag-text').text() === value) {
                        exists = true;
                        return false;
                    }
                });
                
                if (exists) return;
                
                var $tag = $('<span class="bk-tag">' +
                    '<span class="bk-tag-text">' + escapeHtml(value) + '</span>' +
                    '<button type="button" class="bk-tag-remove" data-value="' + escapeHtml(value) + '">×</button>' +
                    '</span>');
                
                $tag.insertBefore($input);
                updateHiddenField(target);
            }
            
            function updateHiddenField(target) {
                var $container = $('#' + target + '-tags');
                var tags = [];
                
                $container.find('.bk-tag-text').each(function() {
                    tags.push($(this).text());
                });
                
                $('#' + target + '-value').val(JSON.stringify(tags));
            }
            
            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            initTagInput();
            
            // ========== OUTPUT VARIABLES BUILDER ==========
            function initOutputVarsBuilder() {
                // Update schema on any change
                function updateSchema() {
                    var schema = {};
                    $('#output-vars-body .bk-output-var-row').each(function() {
                        var name = $(this).find('.bk-var-name').val().trim();
                        var type = $(this).find('.bk-var-type').val();
                        var desc = $(this).find('.bk-var-desc').val().trim();
                        
                        if (name !== '') {
                            schema[name] = {
                                type: type,
                                desc: desc
                            };
                        }
                    });
                    
                    var json = JSON.stringify(schema, null, 2);
                    $('#variables-schema-value').val(JSON.stringify(schema));
                    $('#schema-preview').text(json);
                }
                
                // Initial update
                updateSchema();
                
                // Add new variable row
                $('#add-output-var').on('click', function() {
                    addVarRow('', 'string', '');
                });
                
                function addVarRow(name, type, desc) {
                    var $row = $('<tr class="bk-output-var-row new-row" draggable="true">' +
                        '<td>' +
                            '<div class="bk-var-name-wrap">' +
                                '<span class="bk-drag-handle">⋮⋮</span>' +
                                '<input type="text" class="bk-var-name" value="' + escapeHtml(name) + '" placeholder="var_name">'  +
                            '</div>' +
                        '</td>' +
                        '<td>' +
                            '<select class="bk-var-type">' +
                                '<option value="string"' + (type === 'string' ? ' selected' : '') + '>String</option>' +
                                '<option value="number"' + (type === 'number' ? ' selected' : '') + '>Number</option>' +
                                '<option value="boolean"' + (type === 'boolean' ? ' selected' : '') + '>Boolean</option>' +
                                '<option value="array"' + (type === 'array' ? ' selected' : '') + '>Array</option>' +
                                '<option value="object"' + (type === 'object' ? ' selected' : '') + '>Object</option>'  +
                            '</select>' +
                        '</td>' +
                        '<td>' +
                            '<input type="text" class="bk-var-desc" value="' + escapeHtml(desc) + '" placeholder="Short description...">'  +
                        '</td>' +
                        '<td>' +
                            '<button type="button" class="bk-var-remove" title="Remove variable">×</button>'  +
                        '</td>' +
                    '</tr>');
                    
                    $('#output-vars-body').append($row);
                    $row.find('.bk-var-name').focus();
                    
                    setTimeout(function() {
                        $row.removeClass('new-row');
                    }, 300);
                    
                    updateSchema();
                }
                
                // Remove variable row
                $(document).on('click', '.bk-var-remove', function() {
                    var $row = $(this).closest('.bk-output-var-row');
                    $row.css('opacity', '0');
                    setTimeout(function() {
                        $row.remove();
                        updateSchema();
                    }, 200);
                });
                
                // Update on input change
                $(document).on('input change', '.bk-var-name, .bk-var-type, .bk-var-desc', function() {
                    updateSchema();
                });
                
                // Quick add presets
                $('.bk-quick-var').on('click', function() {
                    var vars = $(this).data('vars');
                    if (Array.isArray(vars)) {
                        vars.forEach(function(v) {
                            // Check if already exists
                            var exists = false;
                            $('#output-vars-body .bk-var-name').each(function() {
                                if ($(this).val() === v.name) {
                                    exists = true;
                                    return false;
                                }
                            });
                            if (!exists) {
                                addVarRow(v.name, v.type, v.desc);
                            }
                        });
                    }
                });
                
                // Toggle preview
                $('.bk-preview-header').on('click', function() {
                    var $preview = $('#schema-preview');
                    var $btn = $(this).find('.bk-toggle-preview');
                    $preview.toggleClass('active');
                    $btn.text($preview.hasClass('active') ? '▲' : '▼');
                });
                
                // Drag and drop reorder
                var draggedRow = null;
                
                $(document).on('dragstart', '.bk-output-var-row', function(e) {
                    draggedRow = this;
                    $(this).addClass('dragging');
                });
                
                $(document).on('dragend', '.bk-output-var-row', function() {
                    $(this).removeClass('dragging');
                    draggedRow = null;
                    updateSchema();
                });
                
                $(document).on('dragover', '.bk-output-var-row', function(e) {
                    e.preventDefault();
                    if (this !== draggedRow) {
                        var $rows = $('#output-vars-body .bk-output-var-row');
                        var draggedIdx = $rows.index(draggedRow);
                        var targetIdx = $rows.index(this);
                        
                        if (draggedIdx < targetIdx) {
                            $(this).after(draggedRow);
                        } else {
                            $(this).before(draggedRow);
                        }
                    }
                });
            }
            
            initOutputVarsBuilder();
            
            // Avatar upload
            $('#select-avatar').on('click', function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title: 'Select Avatar',
                    button: { text: 'Use' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#avatar-url').val(attachment.url);
                    $('#avatar-preview').html('<img src="' + attachment.url + '" style="max-width:100px;border-radius:50%;">');
                });
                frame.open();
            });
            
            // Form submit
            $('#character-form').on('submit', function(e) {
                e.preventDefault();
                
                $.post(bizcity_knowledge_vars.ajaxurl, {
                    action: 'bizcity_knowledge_save_character',
                    nonce: bizcity_knowledge_vars.nonce,
                    data: $(this).serialize()
                }, function(response) {
                    if (response.success) {
                        alert('Character saved!');
                        if (response.data.id) {
                            window.location.href = '<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit&id='); ?>' + response.data.id;
                        }
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render Knowledge Sources section (with scope column)
     */
    private function render_knowledge_sources_section($character_id) {
        $db = BizCity_Knowledge_Database::instance();
        $sources = $db->get_knowledge_sources($character_id);
        ?>
        <div class="knowledge-sources-section">
            <div style="display:flex;gap:10px;margin-bottom:20px;">
                <button type="button" class="button" data-action="add-faq">➕ <?php esc_html_e( 'Add Quick FAQ', 'bizcity-twin-ai' ); ?></button>
                <button type="button" class="button" data-action="upload-file">📁 <?php esc_html_e( 'Upload File', 'bizcity-twin-ai' ); ?></button>
                <button type="button" class="button" data-action="import-url">🌐 <?php esc_html_e( 'Import URL', 'bizcity-twin-ai' ); ?></button>
                <button type="button" class="button" data-action="sync-fanpage">📱 <?php esc_html_e( 'Sync Fanpage', 'bizcity-twin-ai' ); ?></button>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Type', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Name/URL', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Scope', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Chunks', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Last Sync', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sources)): ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No knowledge sources yet.', 'bizcity-twin-ai' ); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($sources as $src):
                            $scope = isset($src->scope) ? $src->scope : 'agent';
                            $scope_icon = $this->get_scope_icon($scope);
                            $scope_label = $this->get_scope_label($scope);
                        ?>
                        <tr>
                            <td><?php echo esc_html(ucfirst($src->source_type)); ?></td>
                            <td>
                                <strong><?php echo esc_html($src->source_name); ?></strong>
                                <?php if ($src->source_url): ?>
                                <br><small><?php echo esc_url($src->source_url); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="bk-scope-badge bk-scope-<?php echo esc_attr($scope); ?>"
                                      title="<?php echo esc_attr($scope_label); ?>">
                                    <?php echo $scope_icon; ?> <?php echo esc_html($scope_label); ?>
                                </span>
                            </td>
                            <td><?php echo $src->chunks_count; ?></td>
                            <td><span class="status-<?php echo $src->status; ?>"><?php echo ucfirst($src->status); ?></span></td>
                            <td><?php echo $src->last_synced_at ?: '—'; ?></td>
                            <td>
                                <a href="#" class="resync-source" data-id="<?php echo $src->id; ?>">Sync</a> |
                                <?php if (in_array($scope, array('session', 'project'), true)): ?>
                                <a href="#" class="promote-source" data-id="<?php echo $src->id; ?>" data-scope="user" title="<?php esc_attr_e( 'Save permanently → Personal', 'bizcity-twin-ai' ); ?>">⬆ <?php esc_html_e( 'Promote', 'bizcity-twin-ai' ); ?></a> |
                                <?php endif; ?>
                                <a href="#" class="delete-source" data-id="<?php echo $src->id; ?>"><?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /* ── Scope helpers (Knowledge Fabric v3.0) ── */

    /**
     * Get emoji icon for a scope.
     *
     * @param string $scope
     * @return string
     */
    private function get_scope_icon( $scope ) {
        $map = array(
            'agent'   => '🤖',
            'user'    => '👤',
            'project' => '📁',
            'session' => '💬',
        );
        return isset( $map[ $scope ] ) ? $map[ $scope ] : '❓';
    }

    /**
     * Get human-readable label for a scope.
     *
     * @param string $scope
     * @return string
     */
    private function get_scope_label( $scope ) {
        $map = array(
            'agent'   => 'Agent',
            'user'    => __( 'Personal', 'bizcity-twin-ai' ),
            'project' => __( 'Project', 'bizcity-twin-ai' ),
            'session' => 'Session',
        );
        return isset( $map[ $scope ] ) ? $map[ $scope ] : ucfirst( $scope );
    }

    /**
     * Render Intents section
     */
    private function render_intents_section($character_id) {
        $db = BizCity_Knowledge_Database::instance();
        $intents = $db->get_intents($character_id);
        ?>
        <div class="intents-section">
            <button type="button" class="button" id="add-intent">➕ <?php esc_html_e( 'Add Intent', 'bizcity-twin-ai' ); ?></button>
            
            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th>Intent Name</th>
                        <th>Description</th>
                        <th>Keywords</th>
                        <th>Output Variables</th>
                        <th>Action Hook</th>
                        <th><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($intents)): ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No intents yet. Add intents to enable user intent analysis.', 'bizcity-twin-ai' ); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($intents as $intent): ?>
                        <tr>
                            <td><strong><?php echo esc_html($intent->intent_name); ?></strong></td>
                            <td><?php echo esc_html($intent->intent_description); ?></td>
                            <td><code><?php echo esc_html($intent->keywords); ?></code></td>
                            <td><code><?php echo esc_html($intent->output_variables); ?></code></td>
                            <td><code><?php echo esc_html($intent->action_hook ?: '—'); ?></code></td>
                            <td>
                                <a href="#" class="edit-intent" data-id="<?php echo $intent->id; ?>"><?php esc_html_e( 'Edit', 'bizcity-twin-ai' ); ?></a> |
                                <a href="#" class="delete-intent" data-id="<?php echo $intent->id; ?>"><?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render Sources Page — Scope-aware Knowledge Browser (Knowledge Fabric v3.0)
     */
    public function render_sources_page() {
        $db = BizCity_Knowledge_Database::instance();
        $filter_scope = isset($_GET['scope']) ? sanitize_text_field($_GET['scope']) : '';
        $filter_user  = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

        // Build query params for scope-filtered listing
        $query_params = array();
        if ($filter_scope) {
            $query_params['scope'] = $filter_scope;
        }
        if ($filter_user) {
            $query_params['user_id'] = $filter_user;
        }

        // Use scope-aware query if available, else fall back
        if (method_exists($db, 'get_sources_by_scope') && !empty($query_params)) {
            $sources = $db->get_sources_by_scope($query_params);
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_knowledge_sources';
            $where = '1=1';
            if ($filter_scope) {
                $where .= $wpdb->prepare(' AND scope = %s', $filter_scope);
            }
            if ($filter_user) {
                $where .= $wpdb->prepare(' AND user_id = %d', $filter_user);
            }
            $sources = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 200");
        }

        $scope_counts = array('all' => 0, 'agent' => 0, 'user' => 0, 'project' => 0, 'session' => 0);
        if (!empty($sources)) {
            $scope_counts['all'] = count($sources);
        }
        // Count per scope
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';
        $counts_raw = $wpdb->get_results("SELECT scope, COUNT(*) as cnt FROM {$table} GROUP BY scope");
        $total_all = 0;
        foreach ($counts_raw as $row) {
            $s = $row->scope ?: 'agent';
            if (isset($scope_counts[$s])) {
                $scope_counts[$s] = (int) $row->cnt;
            }
            $total_all += (int) $row->cnt;
        }
        $scope_counts['all'] = $total_all;

        $base_url = admin_url('admin.php?page=bizcity-knowledge-sources');
        ?>
        <div class="wrap">
            <h1>📚 <?php esc_html_e( 'Knowledge Fabric', 'bizcity-twin-ai' ); ?></h1>
            <p><?php esc_html_e( 'Manage knowledge by 4 scopes: Agent (system), User (personal), Project, Session (temporary).', 'bizcity-twin-ai' ); ?></p>

            <div class="bk-scope-filter-bar" style="margin: 15px 0; display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="<?php echo esc_url($base_url); ?>"
                   class="button <?php echo empty($filter_scope) ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'All', 'bizcity-twin-ai' ); ?> (<?php echo $scope_counts['all']; ?>)
                </a>
                <?php
                $scope_defs = array(
                    'agent'   => array('icon' => '🤖', 'label' => 'Agent'),
                    'user'    => array('icon' => '👤', 'label' => __( 'Personal', 'bizcity-twin-ai' ) ),
                    'project' => array('icon' => '📁', 'label' => __( 'Project', 'bizcity-twin-ai' ) ),
                    'session' => array('icon' => '💬', 'label' => 'Session'),
                );
                foreach ($scope_defs as $sk => $sd): ?>
                <a href="<?php echo esc_url(add_query_arg('scope', $sk, $base_url)); ?>"
                   class="button <?php echo ($filter_scope === $sk) ? 'button-primary' : ''; ?>">
                    <?php echo $sd['icon']; ?> <?php echo $sd['label']; ?> (<?php echo $scope_counts[$sk]; ?>)
                </a>
                <?php endforeach; ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">ID</th>
                        <th style="width:70px">Scope</th>
                        <th><?php esc_html_e( 'Type', 'bizcity-twin-ai' ); ?></th>
                        <th><?php esc_html_e( 'Name/URL', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Owner', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:60px"><?php esc_html_e( 'Chunks', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Created', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:160px"><?php esc_html_e( 'Actions', 'bizcity-twin-ai' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sources)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:20px;"><?php esc_html_e( 'No knowledge sources', 'bizcity-twin-ai' ); ?><?php echo $filter_scope ? ' ' . sprintf( esc_html__( 'for scope "%s"', 'bizcity-twin-ai' ), esc_html( $filter_scope ) ) : ''; ?>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sources as $src):
                            $scope = isset($src->scope) ? $src->scope : 'agent';
                            $scope_icon = $this->get_scope_icon($scope);
                            $scope_label = $this->get_scope_label($scope);
                            $owner_id = isset($src->user_id) ? (int) $src->user_id : 0;
                            $owner_name = $owner_id ? get_userdata($owner_id) : null;
                            $owner_display = $owner_name ? $owner_name->display_name : ($owner_id ? "User #{$owner_id}" : '—');
                        ?>
                        <tr>
                            <td><?php echo $src->id; ?></td>
                            <td>
                                <span class="bk-scope-badge bk-scope-<?php echo esc_attr($scope); ?>">
                                    <?php echo $scope_icon; ?> <?php echo esc_html($scope_label); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(ucfirst($src->source_type)); ?></td>
                            <td>
                                <strong><?php echo esc_html($src->source_name); ?></strong>
                                <?php if (!empty($src->source_url)): ?>
                                <br><small><a href="<?php echo esc_url($src->source_url); ?>" target="_blank"><?php echo esc_url($src->source_url); ?></a></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($owner_display); ?></td>
                            <td><?php echo isset($src->chunks_count) ? $src->chunks_count : 0; ?></td>
                            <td><span class="status-<?php echo esc_attr($src->status); ?>"><?php echo ucfirst($src->status); ?></span></td>
                            <td><?php echo isset($src->created_at) ? $src->created_at : ($src->last_synced_at ?: '—'); ?></td>
                            <td>
                                <?php if (in_array($scope, array('session', 'project'), true)): ?>
                                <a href="#" class="promote-source" data-id="<?php echo $src->id; ?>" data-scope="user">⬆ Promote</a> |
                                <?php endif; ?>
                                <a href="#" class="resync-source" data-id="<?php echo $src->id; ?>">🔄 Sync</a> |
                                <a href="#" class="delete-source" data-id="<?php echo $src->id; ?>" style="color:#a00;">🗑 <?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .bk-scope-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                white-space: nowrap;
            }
            .bk-scope-agent   { background: #e3f2fd; color: #1565c0; }
            .bk-scope-user    { background: #e8f5e9; color: #2e7d32; }
            .bk-scope-project { background: #fff3e0; color: #e65100; }
            .bk-scope-session { background: #f3e5f5; color: #7b1fa2; }
        </style>
        <?php
    }
    
    /**
     * Render Chat Page — disabled (dùng webchat thay vì admin page)
     */
    // public function render_chat_page() {
    //     // Enqueue chat-specific styles
    //     wp_enqueue_style(
    //         'bizcity-knowledge-chat',
    //         plugins_url('assets/css/chat.css', dirname(__FILE__)),
    //         [],
    //         BIZCITY_KNOWLEDGE_VERSION
    //     );
    //     
    //     require_once BIZCITY_KNOWLEDGE_DIR . 'views/chat.php';
    // }

    /**
     * Render User Memory Management Page
     */
    public function render_memory_page() {
        $memory = BizCity_User_Memory::instance();
        $stats  = $memory->get_stats();

        // If user_id filter provided, get that user's memories
        $filter_user = intval( $_GET['filter_user'] ?? 0 );
        $filter_session = sanitize_text_field( $_GET['filter_session'] ?? '' );
        $memories = [];
        if ( $filter_user || $filter_session ) {
            $memories = $memory->get_memories( [
                'user_id'    => $filter_user,
                'session_id' => $filter_session,
                'limit'      => 100,
            ] );
        }
        ?>
        <div class="wrap">
            <h1>🧠 <?php esc_html_e( 'User Memory', 'bizcity-twin-ai' ); ?></h1>
            <p><?php esc_html_e( 'Manage long-term memory — 2-tier system:', 'bizcity-twin-ai' ); ?> <strong>extracted</strong> (<?php esc_html_e( 'AI-analyzed from conversation', 'bizcity-twin-ai' ); ?>) + <strong>explicit</strong> (<?php esc_html_e( 'user-requested', 'bizcity-twin-ai' ); ?>).</p>

            <!-- Stats -->
            <div class="bizcity-memory-stats" style="display:flex;gap:16px;margin:20px 0;">
                <div style="background:#f0f7ff;padding:16px 24px;border-radius:8px;min-width:140px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#0b57d0;"><?php echo esc_html($stats['total']); ?></div>
                    <div style="color:#666;"><?php esc_html_e( 'Total', 'bizcity-twin-ai' ); ?></div>
                </div>
                <div style="background:#f0fdf0;padding:16px 24px;border-radius:8px;min-width:140px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#188038;"><?php echo esc_html($stats['unique_users']); ?></div>
                    <div style="color:#666;">Users</div>
                </div>
                <?php foreach ( $stats['by_tier'] as $tier ): ?>
                <div style="background:<?php echo $tier['memory_tier'] === 'explicit' ? '#fff7ed' : '#f5f0ff'; ?>;padding:16px 24px;border-radius:8px;min-width:140px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:<?php echo $tier['memory_tier'] === 'explicit' ? '#c2410c' : '#7c3aed'; ?>;"><?php echo esc_html($tier['count']); ?></div>
                    <div style="color:#666;"><?php echo $tier['memory_tier'] === 'explicit' ? '📌 Explicit' : '🧠 Extracted'; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filter form -->
            <form method="get" style="margin:20px 0;display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="page" value="bizcity-knowledge-memory">
                <label>User ID: <input type="number" name="filter_user" value="<?php echo esc_attr($filter_user); ?>" style="width:80px;"></label>
                <label>Session ID: <input type="text" name="filter_session" value="<?php echo esc_attr($filter_session); ?>" placeholder="(optional)" style="width:240px;"></label>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'bizcity-twin-ai' ); ?></button>
            </form>

            <!-- By Type -->
            <?php if ( $stats['by_type'] ): ?>
            <h3><?php esc_html_e( 'Distribution by Type', 'bizcity-twin-ai' ); ?></h3>
            <table class="wp-list-table widefat fixed striped" style="max-width:600px;">
                <thead><tr><th>Type</th><th>Count</th><th>Avg Score</th></tr></thead>
                <tbody>
                <?php foreach ( $stats['by_type'] as $st ): ?>
                    <tr>
                        <td><code><?php echo esc_html($st['memory_type']); ?></code></td>
                        <td><?php echo esc_html($st['count']); ?></td>
                        <td><?php echo round($st['avg_score'], 1); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Memory list -->
            <?php if ( $memories ): ?>
            <h3><?php printf( esc_html__( 'Memories of User #%s', 'bizcity-twin-ai' ), esc_html( $filter_user ?: $filter_session ) ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px;">ID</th>
                        <th style="width:70px;">Tier</th>
                        <th style="width:90px;">Type</th>
                        <th style="width:160px;">Key</th>
                        <th>Memory Text</th>
                        <th style="width:50px;">Score</th>
                        <th style="width:50px;">Seen</th>
                        <th style="width:130px;">Last Seen</th>
                        <th style="width:70px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $memories as $m ): ?>
                    <tr>
                        <td><?php echo esc_html($m->id); ?></td>
                        <td><?php echo $m->memory_tier === 'explicit' ? '<span style="color:#c2410c;">📌</span>' : '<span style="color:#7c3aed;">🧠</span>'; ?> <?php echo esc_html($m->memory_tier); ?></td>
                        <td><code><?php echo esc_html($m->memory_type); ?></code></td>
                        <td style="font-size:11px;word-break:break-all;"><?php echo esc_html($m->memory_key); ?></td>
                        <td><?php echo esc_html($m->memory_text); ?></td>
                        <td style="text-align:center;"><?php echo esc_html($m->score); ?></td>
                        <td style="text-align:center;"><?php echo esc_html($m->times_seen); ?></td>
                        <td style="font-size:11px;"><?php echo esc_html($m->last_seen); ?></td>
                        <td>
                            <button class="button button-small bizcity-memory-delete" data-id="<?php echo esc_attr($m->id); ?>">🗑</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <script>
            jQuery(function($){
                $('.bizcity-memory-delete').on('click', function(){
                    if (!confirm('<?php esc_html_e( 'Delete this memory?', 'bizcity-twin-ai' ); ?>')) return;
                    var btn = $(this), id = btn.data('id');
                    $.post(ajaxurl, {
                        action: 'bizcity_memory_delete',
                        nonce: '<?php echo wp_create_nonce("bizcity_chat"); ?>',
                        memory_id: id
                    }, function(r){
                        if (r.success) btn.closest('tr').fadeOut();
                        else alert('<?php esc_html_e( 'Error:', 'bizcity-twin-ai' ); ?> ' + (r.data || 'unknown'));
                    });
                });
            });
            </script>
            <?php elseif ( $filter_user || $filter_session ): ?>
                <p><em><?php esc_html_e( 'No memories found for this user/session.', 'bizcity-twin-ai' ); ?></em></p>
            <?php endif; ?>

            <!-- Manual add -->
            <h3 style="margin-top:30px;">➕ <?php esc_html_e( 'Add Memory Manually', 'bizcity-twin-ai' ); ?></h3>
            <form id="bizcity-memory-add-form" style="max-width:600px;">
                <table class="form-table">
                    <tr><th>User ID</th><td><input type="number" name="user_id" value="<?php echo esc_attr($filter_user ?: get_current_user_id()); ?>" style="width:100px;"></td></tr>
                    <tr><th>Session ID</th><td><input type="text" name="session_id" value="<?php echo esc_attr($filter_session); ?>" placeholder="(optional)" style="width:300px;"></td></tr>
                    <tr><th>Type</th><td>
                        <select name="memory_type">
                            <option value="fact">fact</option>
                            <option value="identity">identity</option>
                            <option value="preference">preference</option>
                            <option value="goal">goal</option>
                            <option value="pain">pain</option>
                            <option value="constraint">constraint</option>
                            <option value="habit">habit</option>
                            <option value="relationship">relationship</option>
                            <option value="request">request (explicit)</option>
                        </select>
                    </td></tr>
                    <tr><th>Key</th><td><input type="text" name="memory_key" placeholder="vd: likes:coffee" style="width:300px;"></td></tr>
                    <tr><th><?php esc_html_e( 'Content', 'bizcity-twin-ai' ); ?></th><td><textarea name="memory_text" rows="3" style="width:100%;"></textarea></td></tr>
                </table>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Memory', 'bizcity-twin-ai' ); ?></button>
                <span id="bizcity-memory-add-result" style="margin-left:12px;"></span>
            </form>

            <script>
            jQuery(function($){
                $('#bizcity-memory-add-form').on('submit', function(e){
                    e.preventDefault();
                    var data = $(this).serializeArray();
                    data.push({name:'action', value:'bizcity_memory_add'});
                    data.push({name:'nonce', value:'<?php echo wp_create_nonce("bizcity_chat"); ?>'});
                    $.post(ajaxurl, data, function(r){
                        $('#bizcity-memory-add-result').text(r.success ? '✅ <?php esc_html_e( 'Added', 'bizcity-twin-ai' ); ?>' : '❌ <?php esc_html_e( 'Error', 'bizcity-twin-ai' ); ?>');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Render Memory Requests Tracking Page
     * Shows ALL memory requests across all users with filters & pagination.
     */
    public function render_memory_requests_page() {
        $memory = BizCity_User_Memory::instance();

        // Parse filters from URL
        $page   = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $tier   = sanitize_text_field( $_GET['memory_tier'] ?? '' );
        $type   = sanitize_text_field( $_GET['memory_type'] ?? '' );
        $source = sanitize_text_field( $_GET['source'] ?? '' );
        $search = sanitize_text_field( $_GET['s'] ?? '' );

        $result = $memory->get_all_requests( [
            'page'        => $page,
            'per_page'    => 30,
            'memory_tier' => $tier,
            'memory_type' => $type,
            'source'      => $source,
            'search'      => $search,
        ] );

        $items = $result['items'];
        $total = $result['total'];
        $pages = $result['pages'];

        // Stats
        $stats = $memory->get_stats();
        $tier_map = [];
        foreach ( $stats['by_tier'] as $t ) { $tier_map[ $t['memory_tier'] ] = (int) $t['count']; }

        // Global character info
        $global_char_id = BizCity_User_Memory::get_global_character_id();
        global $wpdb;
        $global_sources = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE character_id = %d",
            $global_char_id
        ) );
        $global_chunks = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_chunks WHERE character_id = %d",
            $global_char_id
        ) );

        // Base URL for filters
        $base_url = admin_url( 'admin.php?page=bizcity-knowledge-requests' );
        ?>
        <style>
            .bk-req-stats { display:flex; gap:12px; flex-wrap:wrap; margin:16px 0; }
            .bk-req-stat { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 20px; min-width:140px; text-align:center; }
            .bk-req-stat .num { font-size:28px; font-weight:700; line-height:1.2; }
            .bk-req-stat .lbl { font-size:12px; color:#64748b; margin-top:2px; }
            .bk-req-stat.purple .num { color:#7c3aed; }
            .bk-req-stat.blue .num { color:#2563eb; }
            .bk-req-stat.amber .num { color:#d97706; }
            .bk-req-stat.green .num { color:#059669; }
            .bk-req-stat.indigo .num { color:#4f46e5; }
            .bk-req-filters { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
            .bk-req-filters select, .bk-req-filters input[type=search] { height:32px; }
            .bk-mem-content { max-width:380px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; }
            .bk-mem-content.expanded { white-space:normal; word-break:break-word; }
            .bk-tier-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
            .bk-tier-explicit { background:#fef3c7; color:#92400e; }
            .bk-tier-extracted { background:#dbeafe; color:#1e40af; }
            .bk-type-badge { display:inline-block; padding:2px 7px; border-radius:8px; font-size:11px; background:#f1f5f9; color:#334155; }
            .bk-source-icon { font-size:16px; }
            .bk-score-bar { display:inline-block; width:50px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; vertical-align:middle; }
            .bk-score-fill { height:100%; border-radius:4px; }
        </style>

        <div class="wrap">
            <h1 class="wp-heading-inline">📋 <?php esc_html_e( 'Memory Requests', 'bizcity-twin-ai' ); ?></h1>
            <?php if ( $global_char_id ): ?>
            <a href="<?php echo admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $global_char_id ); ?>" class="page-title-action" style="background:#4f46e5;color:#fff;border-color:#4338ca;">
                🌐 <?php printf( esc_html__( 'Manage Global Memory (%1$d sources, %2$d chunks)', 'bizcity-twin-ai' ), $global_sources, $global_chunks ); ?>
            </a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <p><?php esc_html_e( 'Track memory requests, files, links from users. Files &amp; links are auto-ingested for', 'bizcity-twin-ai' ); ?> <strong>🌐 Global Memory</strong> character.</p>

            <!-- Stats -->
            <div class="bk-req-stats">
                <div class="bk-req-stat purple"><div class="num"><?php echo $stats['total']; ?></div><div class="lbl"><?php esc_html_e( 'Total', 'bizcity-twin-ai' ); ?></div></div>
                <div class="bk-req-stat amber"><div class="num"><?php echo $tier_map['explicit'] ?? 0; ?></div><div class="lbl">📌 Explicit</div></div>
                <div class="bk-req-stat blue"><div class="num"><?php echo $tier_map['extracted'] ?? 0; ?></div><div class="lbl">🧠 Extracted</div></div>
                <div class="bk-req-stat green"><div class="num"><?php echo $global_sources; ?></div><div class="lbl">🌐 <?php esc_html_e( 'Global Sources', 'bizcity-twin-ai' ); ?></div></div>
                <div class="bk-req-stat indigo"><div class="num"><?php echo $stats['unique_users']; ?></div><div class="lbl">👤 Users</div></div>
            </div>

            <!-- Filters -->
            <form method="get" class="bk-req-filters">
                <input type="hidden" name="page" value="bizcity-knowledge-requests">
                <select name="memory_tier">
                    <option value="">-- Tier --</option>
                    <option value="explicit" <?php selected( $tier, 'explicit' ); ?>>📌 Explicit</option>
                    <option value="extracted" <?php selected( $tier, 'extracted' ); ?>>🧠 Extracted</option>
                </select>
                <select name="memory_type">
                    <option value="">-- Type --</option>
                    <?php foreach ( ['identity','preference','goal','pain','constraint','habit','relationship','fact','request'] as $t ): ?>
                    <option value="<?php echo $t; ?>" <?php selected( $type, $t ); ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="source">
                    <option value="">-- <?php esc_html_e( 'Source', 'bizcity-twin-ai' ); ?> --</option>
                    <option value="text" <?php selected( $source, 'text' ); ?>>💬 Text</option>
                    <option value="file" <?php selected( $source, 'file' ); ?>>📎 File</option>
                    <option value="url" <?php selected( $source, 'url' ); ?>>🔗 URL</option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'bizcity-twin-ai' ); ?>" style="width:200px;">
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'bizcity-twin-ai' ); ?></button>
                <?php if ( $tier || $type || $source || $search ): ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear filters', 'bizcity-twin-ai' ); ?></a>
                <?php endif; ?>
            </form>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:120px">👤 User</th>
                        <th style="width:80px">Tier</th>
                        <th style="width:90px">Type</th>
                        <th><?php esc_html_e( 'Content', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:60px"><?php esc_html_e( 'Source', 'bizcity-twin-ai' ); ?></th>
                        <th style="width:80px">Score</th>
                        <th style="width:50px">Seen</th>
                        <th style="width:130px">Created</th>
                        <th style="width:60px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ): ?>
                    <tr><td colspan="10"><em><?php esc_html_e( 'No data available.', 'bizcity-twin-ai' ); ?></em></td></tr>
                    <?php else: ?>
                    <?php foreach ( $items as $i => $row ):
                        $meta = json_decode( $row->metadata, true ) ?: [];
                        $user_label = '';
                        if ( (int) $row->user_id > 0 ) {
                            $u = get_userdata( (int) $row->user_id );
                            $user_label = $u ? esc_html( $u->display_name ) : '#' . $row->user_id;
                        } elseif ( $row->session_id ) {
                            $user_label = '👻 ' . substr( $row->session_id, 0, 12 ) . '…';
                        }

                        // Determine source icon
                        $source_icon = '💬';
                        $source_link = '';
                        if ( ! empty( $meta['source_file'] ) ) {
                            $source_icon = '📎';
                            $fname = $meta['file_name'] ?? basename( $meta['source_file'] );
                            $source_link = '<a href="' . esc_url( $meta['source_file'] ) . '" target="_blank" title="' . esc_attr( $fname ) . '">' . $source_icon . '</a>';
                        } elseif ( ! empty( $meta['source_url'] ) ) {
                            $source_icon = '🔗';
                            $source_link = '<a href="' . esc_url( $meta['source_url'] ) . '" target="_blank" title="' . esc_attr( $meta['source_url'] ) . '">' . $source_icon . '</a>';
                        } else {
                            $source_link = $source_icon;
                        }

                        // Score color
                        $score = (int) $row->score;
                        $score_color = $score >= 80 ? '#059669' : ( $score >= 50 ? '#d97706' : '#dc2626' );

                        $row_num = ( $page - 1 ) * 30 + $i + 1;
                    ?>
                    <tr>
                        <td><?php echo $row_num; ?></td>
                        <td title="user_id=<?php echo $row->user_id; ?> session=<?php echo esc_attr( $row->session_id ); ?>">
                            <?php echo $user_label; ?>
                        </td>
                        <td><span class="bk-tier-badge bk-tier-<?php echo $row->memory_tier; ?>">
                            <?php echo $row->memory_tier === 'explicit' ? '📌' : '🧠'; ?> <?php echo $row->memory_tier; ?>
                        </span></td>
                        <td><span class="bk-type-badge"><?php echo esc_html( $row->memory_type ); ?></span></td>
                        <td>
                            <div class="bk-mem-content" title="Click to expand">
                                <?php echo esc_html( $row->memory_text ); ?>
                            </div>
                        </td>
                        <td class="bk-source-icon"><?php echo $source_link; ?></td>
                        <td>
                            <span class="bk-score-bar"><span class="bk-score-fill" style="width:<?php echo $score; ?>%;background:<?php echo $score_color; ?>"></span></span>
                            <small><?php echo $score; ?></small>
                        </td>
                        <td><?php echo (int) $row->times_seen; ?></td>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                        <td>
                            <button type="button" class="button button-small bk-delete-memory" data-id="<?php echo $row->id; ?>" title="<?php esc_attr_e( 'Delete', 'bizcity-twin-ai' ); ?>">🗑</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $pages > 1 ): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total; ?> items</span>
                    <span class="pagination-links">
                        <?php for ( $p = 1; $p <= $pages; $p++ ):
                            $url = add_query_arg( 'paged', $p, $base_url );
                            if ( $tier )   $url = add_query_arg( 'memory_tier', $tier, $url );
                            if ( $type )   $url = add_query_arg( 'memory_type', $type, $url );
                            if ( $source ) $url = add_query_arg( 'source', $source, $url );
                            if ( $search ) $url = add_query_arg( 's', $search, $url );
                        ?>
                            <?php if ( $p === $page ): ?>
                                <span class="tablenav-pages-navspan button disabled" aria-hidden="true"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            // Expand / collapse memory content
            $(document).on('click', '.bk-mem-content', function(){ $(this).toggleClass('expanded'); });

            // Delete memory
            $(document).on('click', '.bk-delete-memory', function(){
                if ( ! confirm('<?php esc_html_e( 'Delete this memory?', 'bizcity-twin-ai' ); ?>') ) return;
                var btn = $(this), id = btn.data('id');
                btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'bizcity_knowledge_delete_memory',
                    nonce:  '<?php echo wp_create_nonce("bizcity_knowledge"); ?>',
                    memory_id: id
                }, function(r){
                    if ( r.success ) {
                        btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
                    } else {
                        alert( r.data?.message || '<?php esc_html_e( 'Error', 'bizcity-twin-ai' ); ?>' );
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Delete a single memory from the requests page
     */
    public function ajax_delete_memory() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $memory_id = intval( $_POST['memory_id'] ?? 0 );
        if ( ! $memory_id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID' ] );
        }

        global $wpdb;
        $deleted = $wpdb->delete( BizCity_User_Memory::table(), [ 'id' => $memory_id ] );
        wp_send_json_success( [ 'deleted' => (bool) $deleted ] );
    }

    /**
     * AJAX: Promote source to a different scope (Knowledge Fabric v3.0)
     */
    public function ajax_promote_source() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        $source_id = intval( isset( $_POST['source_id'] ) ? $_POST['source_id'] : 0 );
        $new_scope = sanitize_text_field( isset( $_POST['new_scope'] ) ? $_POST['new_scope'] : '' );

        if ( ! $source_id || ! in_array( $new_scope, array( 'user', 'project', 'agent' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
        }

        $db = BizCity_Knowledge_Database::instance();
        if ( ! method_exists( $db, 'update_source_scope' ) ) {
            wp_send_json_error( array( 'message' => 'Schema v3.0 not yet migrated' ) );
        }

        $extra = array();
        if ( $new_scope === 'user' ) {
            $extra['user_id'] = get_current_user_id();
        }

        $result = $db->update_source_scope( $source_id, $new_scope, $extra );
        if ( $result !== false ) {
            wp_send_json_success( array(
                'message'   => sprintf( 'Source #%d promoted to %s successfully', $source_id, $new_scope ),
                'source_id' => $source_id,
                'new_scope' => $new_scope,
            ) );
        } else {
            wp_send_json_error( array( 'message' => 'Cannot update scope' ) );
        }
    }

    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        // Save settings
        if (isset($_POST['bizcity_knowledge_save_settings'])) {
            check_admin_referer('bizcity_knowledge_settings');
            
            update_option('bizcity_knowledge_default_character', intval($_POST['bizcity_knowledge_default_character'] ?? 0));
            update_option('bizcity_knowledge_openai_key', sanitize_text_field($_POST['bizcity_knowledge_openai_key'] ?? ''));
            update_option('bizcity_knowledge_openrouter_api_key', sanitize_text_field($_POST['bizcity_knowledge_openrouter_api_key'] ?? ''));
            update_option('bizcity_knowledge_embedding_model', sanitize_text_field($_POST['bizcity_knowledge_embedding_model'] ?? 'text-embedding-3-small'));
            
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'bizcity-twin-ai' ) . '</p></div>';
        }
        
        $openrouter_key = get_option('bizcity_knowledge_openrouter_api_key', '');
        $openai_key = get_option('twf_openai_api_key', '');
        ?>
        <div class="wrap">
            <h1>⚙️ <?php esc_html_e( 'Knowledge Base Settings', 'bizcity-twin-ai' ); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('bizcity_knowledge_settings'); ?>
                <input type="hidden" name="bizcity_knowledge_save_settings" value="1">
                
                <h2 class="title">🤖 OpenAI API (<?php esc_html_e( 'Default', 'bizcity-twin-ai' ); ?>)</h2>
                <p class="description">
                    <?php esc_html_e( 'Default API used for all characters when no specific model is selected.', 'bizcity-twin-ai' ); ?><br>
                    <strong><?php esc_html_e( 'Model:', 'bizcity-twin-ai' ); ?></strong> GPT-4o-mini | 
                    <strong>API Key:</strong> <code>twf_openai_api_key</code>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>OpenAI API Key</label>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($openai_key ? substr($openai_key, 0, 10) . '...' : ''); ?>" 
                                class="regular-text" disabled>
                            <p class="description">
                                <?php if (!empty($openai_key)): ?>
                                    <span style="color:green;">✓ <?php esc_html_e( 'Configured', 'bizcity-twin-ai' ); ?> (option: <code>twf_openai_api_key</code>)</span>
                                <?php else: ?>
                                    <span style="color:red;">⚠ <?php esc_html_e( 'Not configured. Please set', 'bizcity-twin-ai' ); ?> <code>twf_openai_api_key</code> <?php esc_html_e( 'in database options.', 'bizcity-twin-ai' ); ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <hr style="margin:30px 0;">
                
                <h2 class="title">🌐 OpenRouter API (<?php esc_html_e( 'Optional', 'bizcity-twin-ai' ); ?>)</h2>
                <p class="description">
                    <?php esc_html_e( 'OpenRouter allows using various AI models (Claude, Gemini, Llama, etc.).', 'bizcity-twin-ai' ); ?><br>
                    <strong><?php esc_html_e( 'Note:', 'bizcity-twin-ai' ); ?></strong> <?php esc_html_e( 'Only needed for non-OpenAI models.', 'bizcity-twin-ai' ); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="openrouter_api_key">OpenRouter API Key</label>
                        </th>
                        <td>
                            <input type="password" name="bizcity_knowledge_openrouter_api_key" id="openrouter_api_key" 
                                class="regular-text" value="<?php echo esc_attr($openrouter_key); ?>"
                                placeholder="sk-or-... (optional)">
                            <button type="button" class="button" id="toggle-api-key" style="margin-left:5px;">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <p class="description">
                                <?php esc_html_e( 'Get API key at', 'bizcity-twin-ai' ); ?> <a href="https://openrouter.ai/keys" target="_blank">OpenRouter Keys</a>.
                                <?php if (!empty($openrouter_key)): ?>
                                    <span style="color:green;">✓ <?php esc_html_e( 'Configured', 'bizcity-twin-ai' ); ?></span>
                                <?php else: ?>
                                    <span style="color:gray;">ℹ <?php esc_html_e( 'Not configured (optional)', 'bizcity-twin-ai' ); ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th>Test Connection</th>
                        <td>
                            <button type="button" class="button" id="test-openrouter-connection" <?php echo empty($openrouter_key) ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Test Connection', 'bizcity-twin-ai' ); ?>
                            </button>
                            <span id="test-result"></span>
                        </td>
                    </tr>
                </table>
                
                <hr style="margin:30px 0;">
                
                <h2 class="title">⚙️ <?php esc_html_e( 'General Settings', 'bizcity-twin-ai' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th>Default Character</th>
                        <td>
                            <select name="bizcity_knowledge_default_character">
                                <option value="">— <?php esc_html_e( 'None', 'bizcity-twin-ai' ); ?> —</option>
                                <?php
                                $db = BizCity_Knowledge_Database::instance();
                                $chars = $db->get_characters(['status' => 'active']);
                                foreach ($chars as $c) {
                                    printf(
                                        '<option value="%d" %s>%s</option>',
                                        $c->id,
                                        selected(get_option('bizcity_knowledge_default_character'), $c->id, false),
                                        esc_html($c->name)
                                    );
                                }
                                ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Default character for webchat when not specified.', 'bizcity-twin-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th>OpenAI API Key</th>
                        <td>
                            <input type="password" name="bizcity_knowledge_openai_key" class="regular-text"
                                value="<?php echo esc_attr(get_option('bizcity_knowledge_openai_key')); ?>">
                            <p class="description"><?php esc_html_e( 'API key for Knowledge module (embedding). Falls back to system key if empty.', 'bizcity-twin-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Embedding Model</th>
                        <td>
                            <select name="bizcity_knowledge_embedding_model">
                                <option value="text-embedding-3-small" <?php selected(get_option('bizcity_knowledge_embedding_model'), 'text-embedding-3-small'); ?>>text-embedding-3-small</option>
                                <option value="text-embedding-3-large" <?php selected(get_option('bizcity_knowledge_embedding_model'), 'text-embedding-3-large'); ?>>text-embedding-3-large</option>
                                <option value="text-embedding-ada-002" <?php selected(get_option('bizcity_knowledge_embedding_model'), 'text-embedding-ada-002'); ?>>text-embedding-ada-002</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button( __( 'Save Settings', 'bizcity-twin-ai' ) ); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Toggle API key visibility
            $('#toggle-api-key').on('click', function() {
                var $input = $('#openrouter_api_key');
                var type = $input.attr('type');
                $input.attr('type', type === 'password' ? 'text' : 'password');
                $(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
            });
            
            // Test OpenRouter connection
            $('#test-openrouter-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#test-result');
                
                $btn.prop('disabled', true);
                $result.html('<span style="color:blue;">⏳ <?php esc_html_e( "Testing...", "bizcity-twin-ai" ); ?></span>');
                
                $.ajax({
                    url: bizcity_knowledge_vars.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'bizcity_knowledge_test_openrouter',
                        nonce: bizcity_knowledge_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color:green;">✓ <?php esc_html_e( "Connected!", "bizcity-twin-ai" ); ?> ' + response.data.models + ' <?php esc_html_e( "models available.", "bizcity-twin-ai" ); ?></span>');
                        } else {
                            $result.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color:red;">✗ <?php esc_html_e( "Connection error!", "bizcity-twin-ai" ); ?></span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    // Helper methods
    private function count_knowledge_sources() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources");
    }
    
    private function count_total_conversations() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_character_conversations");
    }
    
    private function count_published_characters() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_characters WHERE status = 'published'");
    }
    
    /**
     * AJAX: Save character
     */
    public function ajax_save_character() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $data = $_POST;
        
        $db = BizCity_Knowledge_Database::instance();
        
        $char_data = [
            'name' => sanitize_text_field($data['name'] ?? ''),
            'slug' => sanitize_title($data['slug'] ?? $data['name'] ?? ''),
            'avatar' => esc_url_raw($data['avatar'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'system_prompt' => wp_kses_post($data['system_prompt'] ?? ''),
            'model_id' => sanitize_text_field($data['model_id'] ?? ''),
            'creativity_level' => floatval($data['creativity_level'] ?? 0.7),
            'status' => sanitize_text_field($data['status'] ?? 'draft'),
        ];
        
        // Handle skills as JSON array
        $skills = isset($data['skills']) && is_array($data['skills']) ? $data['skills'] : [];
        $char_data['capabilities'] = json_encode($skills, JSON_UNESCAPED_UNICODE);
        
        // Handle greeting messages
        if (!empty($data['greeting_messages'])) {
            $greetings = json_decode(stripslashes($data['greeting_messages']), true);
            if (is_array($greetings)) {
                $char_data['greeting_messages'] = json_encode($greetings, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $char_data['greeting_messages'] = '[]';
        }
        
        $id = intval($data['id'] ?? 0);
        
        if ($id > 0) {
            $result = $db->update_character($id, $char_data);
        } else {
            $result = $db->create_character($char_data);
            $id = is_numeric($result) ? $result : 0;
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        // Save Quick Knowledge
        if (!empty($data['quick_knowledge_data'])) {
            $quick_knowledge = json_decode(stripslashes($data['quick_knowledge_data']), true);
            if (is_array($quick_knowledge)) {
                $this->save_quick_knowledge($id, $quick_knowledge);
            }
        }
        
        // Save FAQs
        if (!empty($data['faqs_data'])) {
            $faqs = json_decode(stripslashes($data['faqs_data']), true);
            if (is_array($faqs)) {
                $this->save_faqs($id, $faqs);
            }
        }
        
        wp_send_json_success(['id' => $id]);
    }
    
    /**
     * Save quick knowledge entries
     */
    private function save_quick_knowledge($character_id, $entries) {
        global $wpdb;
        $db = BizCity_Knowledge_Database::instance();

        $submitted_ids = [];

        foreach ($entries as $entry) {
            $source_id = intval($entry['id'] ?? 0);
            $content = json_encode([
                'title' => sanitize_text_field($entry['title'] ?? ''),
                'content' => sanitize_textarea_field($entry['content'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            
            if ($source_id > 0) {
                // Update existing
                $wpdb->update(
                    $wpdb->prefix . 'bizcity_knowledge_sources',
                    [
                        'content' => $content,
                        'content_hash' => md5($content),
                        'status' => 'ready'
                    ],
                    ['id' => $source_id]
                );
                $submitted_ids[] = $source_id;
            } else {
                // Create new
                $new_id = $db->create_knowledge_source([
                    'character_id' => $character_id,
                    'source_type' => 'quick_faq',
                    'source_name' => $entry['title'] ?? 'Quick Knowledge',
                    'content' => $content,
                    'content_hash' => md5($content),
                    'status' => 'ready'
                ]);
                if ($new_id) {
                    $submitted_ids[] = intval($new_id);
                }
            }
        }

        // Delete entries that were removed from the UI
        $existing_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE character_id = %d AND source_type = 'quick_faq'",
            $character_id
        ));

        foreach ($existing_ids as $existing_id) {
            if (!in_array(intval($existing_id), $submitted_ids)) {
                $wpdb->delete(
                    $wpdb->prefix . 'bizcity_knowledge_sources',
                    ['id' => intval($existing_id)],
                    ['%d']
                );
            }
        }
    }
    
    /**
     * Save FAQs
     */
    private function save_faqs($character_id, $faqs) {
        $db = BizCity_Knowledge_Database::instance();
        
        foreach ($faqs as $faq) {
            $source_id = intval($faq['id'] ?? 0);
            $content = json_encode([
                'question' => sanitize_text_field($faq['question'] ?? ''),
                'answer' => sanitize_textarea_field($faq['answer'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            
            if ($source_id > 0) {
                // Update existing
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'bizcity_knowledge_sources',
                    [
                        'content' => $content,
                        'content_hash' => md5($content),
                        'status' => 'ready'
                    ],
                    ['id' => $source_id]
                );
            } else {
                // Create new
                $db->create_knowledge_source([
                    'character_id' => $character_id,
                    'source_type' => 'manual',
                    'source_name' => $faq['question'] ?? 'FAQ',
                    'content' => $content,
                    'content_hash' => md5($content),
                    'status' => 'ready'
                ]);
            }
        }
    }
    
    /**
     * AJAX: Delete character
     */
    public function ajax_delete_character() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $id = intval($_POST['id']);
        
        $db = BizCity_Knowledge_Database::instance();
        $db->delete_character($id);
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Quick update character status (for web visibility toggle)
     */
    public function ajax_quick_update_status() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'draft');
        
        // Validate status
        $allowed_statuses = ['draft', 'active', 'published', 'archived'];
        if (!in_array($status, $allowed_statuses)) {
            wp_send_json_error(['message' => 'Invalid status value']);
        }
        
        if ($character_id <= 0) {
            wp_send_json_error(['message' => 'Invalid character ID']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        $result = $db->update_character($character_id, ['status' => $status]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Status updated successfully',
            'new_status' => $status
        ]);
    }
    
    /**
     * AJAX: Test OpenRouter connection
     */
    public function ajax_test_openrouter() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $api_key = get_option('bizcity_knowledge_openrouter_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key not configured']);
        }
        
        $response = wp_remote_get('https://openrouter.ai/api/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => home_url(),
                'X-Title' => get_bloginfo('name')
            ],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['data']) && is_array($body['data'])) {
            wp_send_json_success(['models' => count($body['data'])]);
        } else {
            wp_send_json_error(['message' => 'Invalid response from OpenRouter']);
        }
    }
    
    /**
     * AJAX: Fetch models from OpenRouter
     */
    public function ajax_fetch_models() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $api_key = get_option('bizcity_knowledge_openrouter_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key not configured']);
        }
        
        // Check cache first
        $cache_key = 'bizcity_knowledge_openrouter_models';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            wp_send_json_success(['models' => $cached]);
        }
        
        $response = wp_remote_get('https://openrouter.ai/api/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => home_url(),
                'X-Title' => get_bloginfo('name')
            ],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['data']) && is_array($body['data'])) {
            $models = [];
            
            foreach ($body['data'] as $model) {
                $models[] = [
                    'id' => $model['id'] ?? '',
                    'name' => $model['name'] ?? $model['id'],
                    'description' => $model['description'] ?? '',
                    'context_length' => $model['context_length'] ?? 0,
                    'pricing' => [
                        'prompt' => $model['pricing']['prompt'] ?? 0,
                        'completion' => $model['pricing']['completion'] ?? 0
                    ]
                ];
            }
            
            // Cache for 1 hour
            set_transient($cache_key, $models, HOUR_IN_SECONDS);
            
            wp_send_json_success(['models' => $models]);
        } else {
            wp_send_json_error(['message' => 'Invalid response from OpenRouter']);
        }
    }
    
    /**
     * AJAX: Chat with character
     */
    public function ajax_chat() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $history = json_decode(stripslashes($_POST['history'] ?? '[]'), true);
        $images = json_decode(stripslashes($_POST['images'] ?? '[]'), true);
        
        // Allow empty message if images are provided
        if (!$character_id || (!$message && empty($images))) {
            wp_send_json_error(['message' => 'Missing parameters']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        $character = $db->get_character($character_id);
        
        if (!$character) {
            wp_send_json_error(['message' => 'Character not found']);
        }
        
        // Get Context API instance
        $context_api = BizCity_Knowledge_Context_API::instance();
        
        // Build knowledge context using Context API
        $knowledge_context = $context_api->build_context($character_id, $message, [
            'max_tokens' => 3000,
            'include_vision' => !empty($images),
            'images' => $images
        ]);
        
        // Check if model supports vision
        $model_id = !empty($character->model_id) ? $character->model_id : 'gpt-4o-mini';
        $supports_vision = $context_api->model_supports_vision($model_id);
        $vision_used = false;
        
        // Build messages
        $messages = [];
        
        // System prompt with knowledge context
        $system_content = '';
        
        if (!empty($character->system_prompt)) {
            $system_content = $character->system_prompt;
        }
        
        if (!empty($knowledge_context['context'])) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo / Reference Knowledge:\n" . $knowledge_context['context'];
            $system_content .= "\n\n---\n\nHãy sử dụng kiến thức trên để trả lời câu hỏi của người dùng một cách chính xác. Nếu thông tin không có trong kiến thức, hãy trả lời dựa trên hiểu biết chung của bạn và ghi chú rằng thông tin này không từ nguồn kiến thức được cung cấp. / Use the above knowledge to answer user questions accurately. If the information is not in the knowledge base, answer based on your general knowledge and note that this information is not from the provided knowledge sources.";
        }
        
        if (!empty($system_content)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_content
            ];
        }
        
        // Add history (last 10 messages) - text only for history
        foreach (array_slice($history, -10) as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
        
        // Current message - with image support if vision model
        if (!empty($images) && $supports_vision) {
            // Build multimodal message
            $content = [];
            
            // Add text first
            if (!empty($message)) {
                $content[] = [
                    'type' => 'text',
                    'text' => $message
                ];
            } else {
                $content[] = [
                    'type' => 'text',
                    'text' => 'Hãy mô tả hoặc phân tích hình ảnh này. / Please describe or analyze this image.'
                ];
            }
            
            // Add images
            foreach ($images as $image_data) {
                // Handle both data URLs and regular URLs
                if (strpos($image_data, 'data:') === 0) {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image_data,
                            'detail' => 'auto'
                        ]
                    ];
                } else {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image_data,
                            'detail' => 'auto'
                        ]
                    ];
                }
            }
            
            $messages[] = [
                'role' => 'user',
                'content' => $content
            ];
            $vision_used = true;
        } elseif (!empty($images) && !$supports_vision) {
            // Model doesn't support vision, describe images first using a vision-capable model
            $image_descriptions = [];
            foreach ($images as $image_data) {
                $desc = $context_api->describe_image($image_data, $message, [
                    'vision_provider' => 'openai',
                    'vision_model' => 'gpt-4o-mini'
                ]);
                if (!empty($desc) && !is_wp_error($desc)) {
                    $image_descriptions[] = $desc;
                }
            }
            
            $full_message = $message;
            if (!empty($image_descriptions)) {
                $full_message .= "\n\n[Mô tả hình ảnh đính kèm / Attached image description:\n" . implode("\n\n", $image_descriptions) . "]";
            }
            
            $messages[] = [
                'role' => 'user',
                'content' => $full_message
            ];
        } else {
            // No images, just text
            $messages[] = [
                'role' => 'user',
                'content' => $message
            ];
        }
        
        // Check if character has custom model (OpenRouter) or use default OpenAI
        if (!empty($character->model_id)) {
            // Use OpenRouter API
            $this->chat_with_openrouter($character, $messages, $vision_used);
        } else {
            // Use default OpenAI API
            $this->chat_with_openai($messages, $character, $vision_used);
        }
    }
    
    /**
     * Build knowledge context using hybrid approach
     * 
     * @param int $character_id Character ID
     * @param string $query User query
     * @return string Combined knowledge context
     */
    private function build_knowledge_context($character_id, $query) {
        $db = BizCity_Knowledge_Database::instance();
        $context_parts = [];
        $total_tokens = 0;
        $max_tokens = 3000; // Max tokens for context
        
        // Part 1: Quick Knowledge / FAQ (inject directly - no embedding needed)
        $sources = $db->get_knowledge_sources($character_id, 'ready');
        $quick_knowledge = [];
        
        foreach ($sources as $source) {
            if (in_array($source->source_type, ['quick_faq', 'manual'])) {
                $content = json_decode($source->content, true);
                if (is_array($content)) {
                    if (isset($content['question']) && isset($content['answer'])) {
                        // FAQ format
                        $quick_knowledge[] = "Q: {$content['question']}\nA: {$content['answer']}";
                    } elseif (isset($content['title']) && isset($content['content'])) {
                        // Quick knowledge format
                        $quick_knowledge[] = "### {$content['title']}\n{$content['content']}";
                    }
                } elseif (!empty($source->content)) {
                    $quick_knowledge[] = $source->content;
                }
            }
        }
        
        // Add quick knowledge if under token limit
        if (!empty($quick_knowledge)) {
            $quick_text = implode("\n\n", $quick_knowledge);
            $quick_tokens = BizCity_Knowledge_Embedding::instance()->estimate_tokens($quick_text);
            
            if ($quick_tokens <= $max_tokens * 0.5) { // Use max 50% for quick knowledge
                $context_parts[] = "### Kiến thức nhanh / Quick Knowledge:\n" . $quick_text;
                $total_tokens += $quick_tokens;
            }
        }
        
        // Part 2: Semantic search for file documents (embedding-based)
        $remaining_tokens = $max_tokens - $total_tokens;
        
        if ($remaining_tokens > 500) {
            $embedding = BizCity_Knowledge_Embedding::instance();
            $similar_chunks = $embedding->search_similar($query, $character_id, 5, 0.65);
            
            if (!empty($similar_chunks)) {
                $doc_context = [];
                $doc_tokens = 0;
                
                foreach ($similar_chunks as $chunk) {
                    $chunk_tokens = $embedding->estimate_tokens($chunk['content']);
                    
                    if ($doc_tokens + $chunk_tokens <= $remaining_tokens) {
                        $source_name = $chunk['metadata']['source_name'] ?? 'Document';
                        $similarity_pct = round($chunk['similarity'] * 100);
                        $doc_context[] = "[Nguồn / Source: {$source_name}, Độ liên quan / Relevance: {$similarity_pct}%]\n{$chunk['content']}";
                        $doc_tokens += $chunk_tokens;
                    }
                }
                
                if (!empty($doc_context)) {
                    $context_parts[] = "### Tài liệu liên quan / Related Documents:\n" . implode("\n\n---\n\n", $doc_context);
                }
            }
        }
        
        return implode("\n\n", $context_parts);
    }

    /**
     * Chat using OpenRouter API
     */
    /**
     * Chat using OpenRouter API
     * 
     * @param object $character Character object
     * @param array $messages Chat messages
     * @param bool $vision_used Whether vision was used
     */
    private function chat_with_openrouter($character, $messages, $vision_used = false) {
        $api_key = get_option('bizcity_knowledge_openrouter_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'OpenRouter API key not configured']);
        }
        
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => home_url(),
                'X-Title' => get_bloginfo('name'),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => $character->model_id,
                'messages' => $messages,
                'temperature' => floatval($character->creativity_level ?? 0.7),
                'max_tokens' => 1000
            ]),
            'timeout' => 60 // Longer timeout for vision requests
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            wp_send_json_success([
                'message' => $body['choices'][0]['message']['content'],
                'model' => $body['model'] ?? $character->model_id,
                'usage' => $body['usage'] ?? [],
                'provider' => 'openrouter',
                'vision_used' => $vision_used
            ]);
        } else {
            $error_msg = $body['error']['message'] ?? 'OpenRouter API error';
            wp_send_json_error(['message' => $error_msg]);
        }
    }
    
    /**
     * Chat using default OpenAI API
     * 
     * @param array $messages Chat messages
     * @param object|null $character Character object
     * @param bool $vision_used Whether vision was used
     */
    private function chat_with_openai($messages, $character = null, $vision_used = false) {
        global $wpdb;
        
        // Get OpenAI API key from twf_openai_api_key option
        $api_key = get_option('twf_openai_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'OpenAI API key not configured (twf_openai_api_key)']);
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'temperature' => $character ? floatval($character->creativity_level ?? 0.7) : 0.7,
                'max_tokens' => 1000
            ]),
            'timeout' => 60 // Longer timeout for vision requests
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            wp_send_json_success([
                'message' => $body['choices'][0]['message']['content'],
                'model' => $body['model'] ?? 'gpt-4o-mini',
                'usage' => $body['usage'] ?? [],
                'provider' => 'openai',
                'vision_used' => $vision_used
            ]);
        } else {
            $error_msg = $body['error']['message'] ?? 'OpenAI API error';
            wp_send_json_error(['message' => $error_msg]);
        }
    }
    
    /**
     * AJAX: Upload document
     */
    public function ajax_upload_document() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $files = json_decode(stripslashes($_POST['files'] ?? '[]'), true);
        
        if (!$character_id || empty($files)) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        $parser = BizCity_Knowledge_FileParser::instance();
        $embedding = BizCity_Knowledge_Embedding::instance();
        $uploaded = [];
        $errors = [];
        
        foreach ($files as $file) {
            $attachment_id = intval($file['id'] ?? 0);
            if (!$attachment_id) continue;
            
            $attachment = get_post($attachment_id);
            if (!$attachment) continue;
            
            $file_url = wp_get_attachment_url($attachment_id);
            $file_path = get_attached_file($attachment_id);
            
            // Create knowledge source
            $source_data = [
                'character_id' => $character_id,
                'source_type' => 'file',
                'source_name' => $attachment->post_title ?: basename($file_path),
                'source_url' => $file_url,
                'attachment_id' => $attachment_id,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ];
            
            $source_id = $db->create_knowledge_source($source_data);
            
            if (is_wp_error($source_id)) {
                $errors[] = $source_data['source_name'] . ': ' . $source_id->get_error_message();
                continue;
            }
            
            // Parse file content (supports both local and R2/CDN storage)
            $content = $parser->parse_attachment($attachment_id);
            
            if (is_wp_error($content)) {
                $errors[] = $source_data['source_name'] . ': ' . $content->get_error_message();
                // Update source status to error
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'bizcity_knowledge_sources',
                    ['status' => 'error', 'error_message' => $content->get_error_message()],
                    ['id' => $source_id]
                );
                continue;
            }
            
            // Process and create embeddings
            $result = $embedding->process_source($source_id, $content);
            
            if (is_wp_error($result)) {
                $errors[] = $source_data['source_name'] . ': ' . $result->get_error_message();
            }
            
            // Get updated source status
            global $wpdb;
            $source = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE id = %d",
                $source_id
            ));
            
            $uploaded[] = [
                'id' => $source_id,
                'name' => $source_data['source_name'],
                'url' => $file_url,
                'status' => $source->status ?? 'pending',
                'chunks_count' => $source->chunks_count ?? 0,
                'date' => date('M d, Y')
            ];
        }
        
        $message = count($uploaded) . ' file(s) processed';
        if (!empty($errors)) {
            $message .= '. Errors: ' . implode('; ', $errors);
        }
        
        wp_send_json_success([
            'documents' => $uploaded,
            'message' => $message,
            'errors' => $errors
        ]);
    }
    
    /**
     * AJAX: Delete document
     */
    public function ajax_delete_document() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $source_id = intval($_POST['source_id'] ?? 0);
        
        if (!$source_id) {
            wp_send_json_error(['message' => 'Invalid source ID']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';
        
        // Delete related chunks first
        $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_chunks',
            ['source_id' => $source_id]
        );
        
        // Delete source
        $result = $wpdb->delete($table, ['id' => $source_id]);
        
        if ($result) {
            wp_send_json_success(['message' => 'Document deleted']);
        } else {
            wp_send_json_error(['message' => 'Cannot delete document']);
        }
    }
    
    /**
     * AJAX: Reprocess document (re-extract text and create embeddings)
     */
    public function ajax_reprocess_document() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $source_id = intval($_POST['source_id'] ?? 0);
        
        if (!$source_id) {
            wp_send_json_error(['message' => 'Invalid source ID']);
        }
        
        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE id = %d",
            $source_id
        ));
        
        if (!$source) {
            wp_send_json_error(['message' => 'Source not found']);
        }
        
        // Only process file sources
        if ($source->source_type !== 'file' || !$source->attachment_id) {
            wp_send_json_error(['message' => 'Can only reprocess file documents']);
        }
        
        $parser = BizCity_Knowledge_FileParser::instance();
        $embedding = BizCity_Knowledge_Embedding::instance();
        
        // Parse file content (supports both local and R2/CDN storage)
        $content = $parser->parse_attachment($source->attachment_id);
        
        if (is_wp_error($content)) {
            $wpdb->update(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                ['status' => 'error', 'error_message' => $content->get_error_message()],
                ['id' => $source_id]
            );
            wp_send_json_error(['message' => $content->get_error_message()]);
        }
        
        // Process and create embeddings
        $result = $embedding->process_source($source_id, $content);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => $result['message'],
            'chunks_count' => $result['chunks_count']
        ]);
    }
    
    /**
     * AJAX: Add website to character
     */
    public function ajax_add_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $url = sanitize_url($_POST['url'] ?? '');
        $mode = sanitize_text_field($_POST['mode'] ?? 'single');
        
        if (!$character_id || !$url) {
            wp_send_json_error(['message' => 'Character ID and URL required']);
        }
        
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => 'Invalid URL format']);
        }
        
        require_once BIZCITY_KNOWLEDGE_LIB . 'class-web-crawler.php';
        
        $urls_to_crawl = [];
        
        // Get URLs based on mode
        switch ($mode) {
            case 'single':
                $urls_to_crawl = [$url];
                break;
                
            case 'sublinks':
                // Find sublinks (max depth 1, max 50 links)
                $urls_to_crawl = BizCity_Knowledge_Web_Crawler::find_sublinks($url, 1, 50);
                if (is_wp_error($urls_to_crawl)) {
                    wp_send_json_error(['message' => $urls_to_crawl->get_error_message()]);
                }
                break;
                
            case 'sitemap':
                // Parse sitemap
                $urls_to_crawl = BizCity_Knowledge_Web_Crawler::parse_sitemap($url);
                if (is_wp_error($urls_to_crawl)) {
                    wp_send_json_error(['message' => $urls_to_crawl->get_error_message()]);
                }
                // Limit to 100 URLs from sitemap
                $urls_to_crawl = array_slice($urls_to_crawl, 0, 100);
                break;
        }
        
        if (empty($urls_to_crawl)) {
            wp_send_json_error(['message' => 'No URLs found to crawl']);
        }
        
        // Save URLs to database with status "pending"
        global $wpdb;
        $sources_added = 0;
        
        foreach ($urls_to_crawl as $crawl_url) {
            // Check if URL already exists for this character
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources 
                WHERE character_id = %d AND source_url = %s",
                $character_id,
                $crawl_url
            ));
            
            if ($exists) {
                continue; // Skip duplicate
            }
            
            // Insert pending source
            $wpdb->insert(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'character_id' => $character_id,
                    'source_type' => 'url',
                    'source_url' => $crawl_url,
                    'source_name' => $crawl_url,
                    'status' => 'pending',
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
            
            $sources_added++;
        }
        
        wp_send_json_success([
            'message' => sprintf('Added %d URL(s). Click "Process" to crawl content.', $sources_added, $sources_added),
            'sources_added' => $sources_added,
            'total_urls' => count($urls_to_crawl)
        ]);
    }
    
    /**
     * AJAX: Process website (crawl and extract content)
     */
    public function ajax_process_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $source_id = intval($_POST['source_id'] ?? 0);
        
        if (!$source_id) {
            wp_send_json_error(['message' => 'Source ID required']);
        }
        
        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE id = %d",
            $source_id
        ));
        
        if (!$source || $source->source_type !== 'url') {
            wp_send_json_error(['message' => 'Invalid website source']);
        }
        
        require_once BIZCITY_KNOWLEDGE_LIB . 'class-web-crawler.php';
        
        // Update status to processing
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['status' => 'processing'],
            ['id' => $source_id],
            ['%s'],
            ['%d']
        );
        
        // Crawl page
        $result = BizCity_Knowledge_Web_Crawler::crawl_single_page($source->source_url);
        
        if (is_wp_error($result)) {
            // Update error status
            $wpdb->update(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'status' => 'error',
                    'error_message' => $result->get_error_message()
                ],
                ['id' => $source_id],
                ['%s', '%s'],
                ['%d']
            );
            
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        // Update source with crawled data
        $metadata = [
            'title' => $result['title'],
            'description' => $result['metadata']['description'] ?? '',
            'word_count' => $result['word_count']
        ];
        
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [
                'source_name' => $result['title'] ?: $source->source_url,
                'content' => $result['content'],
                'metadata' => json_encode($metadata),
                'status' => 'processing'
            ],
            ['id' => $source_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        // Split into chunks
        $chunks = BizCity_Knowledge_Web_Crawler::split_into_chunks(
            $result['content'], 
            500, // chunk size in words
            50   // overlap
        );
        
        // Delete old chunks if any
        $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_chunks',
            ['source_id' => $source_id],
            ['%d']
        );
        
        // Insert chunks
        $chunks_created = 0;
        foreach ($chunks as $index => $chunk_text) {
            $wpdb->insert(
                $wpdb->prefix . 'bizcity_knowledge_chunks',
                [
                    'source_id' => $source_id,
                    'chunk_text' => $chunk_text,
                    'chunk_index' => $index,
                    'metadata' => json_encode([
                        'url' => $source->source_url,
                        'title' => $result['title'],
                        'chunk_number' => $index + 1,
                        'total_chunks' => count($chunks)
                    ]),
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%d', '%s', '%s']
            );
            $chunks_created++;
        }
        
        // Update chunks_count and status to ready
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [
                'chunks_count' => $chunks_created,
                'status' => 'ready'
            ],
            ['id' => $source_id],
            ['%d', '%s'],
            ['%d']
        );
        
        wp_send_json_success([
            'message' => sprintf('Crawled successfully! Created %d chunks.', $chunks_created, $chunks_created),
            'chunks_count' => $chunks_created,
            'word_count' => $result['word_count'],
            'title' => $result['title']
        ]);
    }
    
    /**
     * AJAX: Delete website source
     */
    public function ajax_delete_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $source_id = intval($_POST['source_id'] ?? 0);
        
        if (!$source_id) {
            wp_send_json_error(['message' => 'Source ID required']);
        }
        
        global $wpdb;
        
        // Delete chunks first
        $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_chunks',
            ['source_id' => $source_id],
            ['%d']
        );
        
        // Delete source
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['id' => $source_id],
            ['%d']
        );
        
        if ($deleted) {
            wp_send_json_success(['message' => 'Website deleted']);
        } else {
            wp_send_json_error(['message' => 'Cannot delete']);
        }
    }
    
    /**
     * AJAX: Import Legacy FAQ Posts
     */
    public function ajax_import_legacy_faq() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $post_ids = $_POST['post_ids'] ?? [];
        
        if (!$character_id || empty($post_ids)) {
            wp_send_json_error(['message' => 'Character ID and post IDs required']);
        }
        
        global $wpdb;
        $imported_count = 0;
        $failed_count = 0;
        
        foreach ($post_ids as $post_id) {
            $post_id = intval($post_id);
            $post = get_post($post_id);
            
            if (!$post || $post->post_type !== 'quick_faq') {
                $failed_count++;
                continue;
            }
            
            // Check if already imported
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources 
                WHERE character_id = %d AND source_type = 'legacy_faq' AND post_id = %d",
                $character_id,
                $post_id
            ));
            
            if ($exists > 0) {
                continue; // Skip if already imported
            }
            
            // Get metadata
            $action_faq = get_post_meta($post_id, '_action_faq', true);
            $link_faq = get_post_meta($post_id, '_link_faq', true);
            $tags = get_the_terms($post_id, 'quick_faq_tag');
            $tag_names = $tags ? implode(', ', wp_list_pluck($tags, 'name')) : '';
            
            // Prepare content
            $title = $post->post_title;
            $content = $post->post_content;
            
            // Build knowledge text
            $knowledge_text = "Q: {$title}\nA: {$content}";
            if ($action_faq) {
                $knowledge_text .= "\nAction: {$action_faq}";
            }
            if ($link_faq) {
                $knowledge_text .= "\nLink: {$link_faq}";
            }
            
            // Prepare metadata
            $metadata = [
                'post_id' => $post_id,
                'title' => $title,
                'action' => $action_faq,
                'link' => $link_faq,
                'tags' => $tag_names,
                'imported_at' => current_time('mysql')
            ];
            
            // Insert to sources table
            $inserted = $wpdb->insert(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'character_id' => $character_id,
                    'source_type' => 'legacy_faq',
                    'source_name' => $title,
                    'source_url' => get_permalink($post_id),
                    'content' => $knowledge_text,
                    'metadata' => json_encode($metadata),
                    'status' => 'ready',
                    'chunks_count' => 0,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
            );
            
            if ($inserted) {
                $imported_count++;
            } else {
                $failed_count++;
            }
        }
        
        if ($imported_count > 0) {
            wp_send_json_success([
                'message' => sprintf('Successfully imported %d FAQ posts!', $imported_count),
                'imported' => $imported_count,
                'failed' => $failed_count
            ]);
        } else {
            wp_send_json_error([
                'message' => 'No FAQ imported. Items may already be imported or there was an error.',
                'failed' => $failed_count
            ]);
        }
    }
    
    /**
     * AJAX: Export knowledge data for character
     */
    public function ajax_export_knowledge() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        
        if ($character_id <= 0) {
            wp_send_json_error(['message' => 'Invalid character ID']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        
        // Get character info
        $character = $db->get_character($character_id);
        if (!$character) {
            wp_send_json_error(['message' => 'Character not found']);
        }
        
        // Get all knowledge sources
        $sources = $db->get_knowledge_sources($character_id);
        
        // Prepare export data
        $export_data = [
            'version' => '1.0',
            'exported_at' => current_time('Y-m-d H:i:s'),
            'character' => [
                'name' => $character->name,
                'slug' => $character->slug,
                'avatar' => $character->avatar ?? '',
                'description' => $character->description ?? '',
                'system_prompt' => $character->system_prompt ?? '',
                'model_id' => $character->model_id ?? '',
                'creativity_level' => $character->creativity_level ?? 0.7,
                'greeting_messages' => $character->greeting_messages ?? '',
                'capabilities' => $character->capabilities ?? '',
                'industries' => $character->industries ?? '',
                'variables_schema' => $character->variables_schema ?? '',
                'settings' => $character->settings ?? '',
            ],
            'knowledge_sources' => []
        ];
        
        // Process each source
        foreach ($sources as $source) {
            $source_data = [
                'source_type' => $source->source_type,
                'source_name' => $source->source_name,
                'content' => $source->content,
                'metadata' => $source->metadata,
                'status' => $source->status,
                'chunks_count' => $source->chunks_count,
            ];
            
            // Get chunks if available
            if ($source->chunks_count > 0) {
                global $wpdb;
                $chunks_table = $wpdb->prefix . 'bizcity_knowledge_chunks';
                
                // Get full chunk data including embedding for export
                $chunks = $wpdb->get_results($wpdb->prepare(
                    "SELECT content, embedding, metadata, chunk_index, token_count
                     FROM {$chunks_table}
                     WHERE source_id = %d
                     ORDER BY chunk_index ASC",
                    $source->id
                ));
                
                $source_data['chunks'] = array_map(function($chunk) {
                    return [
                        'content' => $chunk->content,
                        'embedding' => $chunk->embedding,
                        'metadata' => $chunk->metadata,
                        'chunk_index' => $chunk->chunk_index ?? 0,
                        'token_count' => $chunk->token_count ?? 0,
                    ];
                }, $chunks);
            } else {
                $source_data['chunks'] = [];
            }
            
            $export_data['knowledge_sources'][] = $source_data;
        }
        
        // Return as downloadable JSON
        wp_send_json_success([
            'data' => $export_data,
            'filename' => sanitize_file_name($character->slug . '-knowledge-' . date('Ymd-His') . '.json')
        ]);
    }
    
    /**
     * AJAX: Import knowledge data for character
     */
    public function ajax_import_knowledge() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $import_data = json_decode(stripslashes($_POST['import_data'] ?? '{}'), true);
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === 'true';
        
        if ($character_id <= 0) {
            wp_send_json_error(['message' => 'Invalid character ID']);
        }
        
        if (empty($import_data) || !isset($import_data['knowledge_sources'])) {
            wp_send_json_error(['message' => 'Invalid import data format']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        
        // Verify character exists
        $character = $db->get_character($character_id);
        if (!$character) {
            wp_send_json_error(['message' => 'Character not found']);
        }
        
        // If overwrite, delete existing knowledge sources
        if ($overwrite) {
            global $wpdb;
            $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
            $chunks_table = $wpdb->prefix . 'bizcity_knowledge_chunks';
            
            // Delete chunks first
            $wpdb->delete($chunks_table, ['character_id' => $character_id]);
            // Delete sources
            $wpdb->delete($sources_table, ['character_id' => $character_id]);
        }
        
        $imported_count = 0;
        $failed_count = 0;
        
        // Import each knowledge source
        foreach ($import_data['knowledge_sources'] as $source_data) {
            try {
                // Prepare source data - only use fields that exist in the table
                $source_insert_data = [
                    'character_id' => $character_id,
                    'source_type' => $source_data['source_type'],
                    'source_name' => $source_data['source_name'],
                    'content' => $source_data['content'],
                    'status' => $source_data['status'] ?? 'pending',
                    'chunks_count' => intval($source_data['chunks_count'] ?? 0),
                ];
                
                // Create knowledge source
                $source_id = $db->create_knowledge_source($source_insert_data);
                
                if (is_wp_error($source_id)) {
                    $failed_count++;
                    continue;
                }
                
                // Import chunks if available
                if (!empty($source_data['chunks'])) {
                    global $wpdb;
                    $chunks_table = $wpdb->prefix . 'bizcity_knowledge_chunks';
                    
                    foreach ($source_data['chunks'] as $chunk_data) {
                        $wpdb->insert(
                            $chunks_table,
                            [
                                'character_id' => $character_id,
                                'source_id' => $source_id,
                                'content' => $chunk_data['content'] ?? '',
                                'embedding' => $chunk_data['embedding'] ?? null,
                                'metadata' => $chunk_data['metadata'] ?? null,
                                'chunk_index' => intval($chunk_data['chunk_index'] ?? 0),
                                'token_count' => intval($chunk_data['token_count'] ?? 0)
                            ],
                            ['%d', '%d', '%s', '%s', '%s', '%d', '%d']
                        );
                    }
                }
                
                $imported_count++;
                
            } catch (Exception $e) {
                $failed_count++;
            }
        }
        
        if ($imported_count > 0) {
            wp_send_json_success([
                'message' => sprintf('Successfully imported %d knowledge sources!', $imported_count, $imported_count),
                'imported' => $imported_count,
                'failed' => $failed_count
            ]);
        } else {
            wp_send_json_error([
                'message' => 'No knowledge sources imported. Please check the data.',
                'failed' => $failed_count
            ]);
        }
    }
    
    /**
     * AJAX: Duplicate character with all knowledge
     */
    public function ajax_duplicate_character() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        
        if ($character_id <= 0) {
            wp_send_json_error(['message' => 'Invalid character ID']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        
        // Get original character
        $original = $db->get_character($character_id);
        if (!$original) {
            wp_send_json_error(['message' => 'Character not found']);
        }
        
        // Create duplicate character with modified name and slug
        $new_name = $original->name . ' (Copy)';
        $base_slug = $original->slug . '-copy';
        $new_slug = $base_slug;
        
        // Find unique slug
        $counter = 1;
        global $wpdb;
        $characters_table = $wpdb->prefix . 'bizcity_characters';
        while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$characters_table} WHERE slug = %s", $new_slug)) > 0) {
            $new_slug = $base_slug . '-' . $counter;
            $counter++;
        }
        
        // Create new character
        $new_char_data = [
            'name' => $new_name,
            'slug' => $new_slug,
            'avatar' => $original->avatar,
            'description' => $original->description,
            'system_prompt' => $original->system_prompt,
            'model_id' => $original->model_id ?? '',
            'creativity_level' => $original->creativity_level ?? 0.7,
            'greeting_messages' => $original->greeting_messages ?? '',
            'capabilities' => $original->capabilities ?? '',
            'industries' => $original->industries ?? '',
            'variables_schema' => $original->variables_schema ?? '',
            'settings' => $original->settings ?? '',
            'status' => 'draft', // Always set to draft
            'author_id' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ];
        
        $wpdb->insert($characters_table, $new_char_data);
        $new_character_id = $wpdb->insert_id;
        
        if (!$new_character_id) {
            wp_send_json_error(['message' => 'Failed to create duplicate character']);
        }
        
        // Copy all knowledge sources
        $sources = $db->get_knowledge_sources($character_id);
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        $chunks_table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        
        $copied_sources = 0;
        $copied_chunks = 0;
        
        foreach ($sources as $source) {
            // Create duplicate source
            $wpdb->insert(
                $sources_table,
                [
                    'character_id' => $new_character_id,
                    'source_type' => $source->source_type,
                    'source_name' => $source->source_name,
                    'content' => $source->content,
                    'metadata' => $source->metadata,
                    'status' => $source->status,
                    'chunks_count' => $source->chunks_count,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
            );
            
            $new_source_id = $wpdb->insert_id;
            
            if ($new_source_id) {
                $copied_sources++;
                
                // Copy chunks if exist
                if ($source->chunks_count > 0) {
                    // Get full chunk data for duplication
                    $chunks = $wpdb->get_results($wpdb->prepare(
                        "SELECT content, embedding, metadata, chunk_index, token_count
                         FROM {$chunks_table}
                         WHERE source_id = %d
                         ORDER BY chunk_index ASC",
                        $source->id
                    ));
                    
                    foreach ($chunks as $chunk) {
                        $wpdb->insert(
                            $chunks_table,
                            [
                                'character_id' => $new_character_id,
                                'source_id' => $new_source_id,
                                'content' => $chunk->content,
                                'embedding' => $chunk->embedding,
                                'metadata' => $chunk->metadata,
                                'chunk_index' => $chunk->chunk_index ?? 0,
                                'token_count' => $chunk->token_count ?? 0,
                                'created_at' => current_time('mysql')
                            ],
                            ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s']
                        );
                        
                        if ($wpdb->insert_id) {
                            $copied_chunks++;
                        }
                    }
                }
            }
        }
        
        wp_send_json_success([
            'message' => sprintf(
                'Character duplicated successfully! (%d sources, %d chunks)',
                $copied_sources,
                $copied_chunks
            ),
            'new_character_id' => $new_character_id,
            'redirect_url' => admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $new_character_id)
        ]);
    }
    
    /**
     * AJAX: Check if slug exists and suggest unique name/slug
     */
    public function ajax_check_slug() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $slug = sanitize_title($_POST['slug'] ?? '');
        
        if (empty($slug) && !empty($name)) {
            $slug = sanitize_title($name);
        }
        
        global $wpdb;
        $characters_table = $wpdb->prefix . 'bizcity_characters';
        
        // Check if slug exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$characters_table} WHERE slug = %s",
            $slug
        )) > 0;
        
        if (!$exists) {
            wp_send_json_success([
                'exists' => false,
                'suggested_name' => $name,
                'suggested_slug' => $slug
            ]);
        }
        
        // Find unique slug and name
        $base_name = $name;
        $base_slug = $slug;
        $counter = 1;
        
        // Try different suffixes
        while ($counter <= 100) {
            $new_name = $base_name . ' (' . $counter . ')';
            $new_slug = $base_slug . '-' . $counter;
            
            $slug_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$characters_table} WHERE slug = %s",
                $new_slug
            )) > 0;
            
            if (!$slug_exists) {
                wp_send_json_success([
                    'exists' => true,
                    'suggested_name' => $new_name,
                    'suggested_slug' => $new_slug,
                    'original_slug' => $slug
                ]);
                return;
            }
            
            $counter++;
        }
        
        // If all fail, use timestamp
        $timestamp_suffix = date('YmdHis');
        wp_send_json_success([
            'exists' => true,
            'suggested_name' => $base_name . ' (' . $timestamp_suffix . ')',
            'suggested_slug' => $base_slug . '-' . $timestamp_suffix,
            'original_slug' => $slug
        ]);
    }
}

