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

    /**
     * [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — every ajax_* handler
     * below independently re-checked bare current_user_can('manage_options'), which wrongly
     * denies a Network Super Admin with no local administrator row on the mapped blog. One
     * shared helper instead of ~38 separate bare checks (see docs/audits/FRAMEWORK-CONTRACT-AUDIT-2026-07-30.md).
     */
    private static function can_manage(): bool {
        return class_exists( 'BizCity_Network_Admin_Capability' )
            ? BizCity_Network_Admin_Capability::can_manage()
            : current_user_can( 'manage_options' );
    }

    public function __construct() {
        // Menu registration moved to BizCity_Admin_Menu (centralized).
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
        add_action('wp_ajax_bizcity_knowledge_list_sources', [$this, 'ajax_list_sources']);
        add_action('wp_ajax_bizcity_knowledge_import_legacy_faq', [$this, 'ajax_import_legacy_faq']);
        add_action('wp_ajax_bizcity_knowledge_export_knowledge', [$this, 'ajax_export_knowledge']);
        add_action('wp_ajax_bizcity_knowledge_import_knowledge', [$this, 'ajax_import_knowledge']);
        add_action('wp_ajax_bizcity_knowledge_duplicate_character', [$this, 'ajax_duplicate_character']);
        add_action('wp_ajax_bizcity_knowledge_check_slug', [$this, 'ajax_check_slug']);

        // Memory Requests tracking
        add_action('wp_ajax_bizcity_knowledge_delete_memory', [$this, 'ajax_delete_memory']);
        // Knowledge Fabric — scope promote
        add_action('wp_ajax_bizcity_knowledge_promote_source', [$this, 'ajax_promote_source']);
        // Tavily search proxy — routes through BizCity_Search_Client (gateway + API key)
        add_action('wp_ajax_bizcity_knowledge_tavily_search', [$this, 'ajax_tavily_search']);

        // PHASE 0.34.2 — Character ↔ Notebook attach/detach (1:N via kg_notebooks.character_id)
        add_action('admin_post_bizcity_character_notebook_attach', [$this, 'admin_post_character_notebook_attach']);
        add_action('admin_post_bizcity_character_notebook_detach', [$this, 'admin_post_character_notebook_detach']);

        // Sprint 0.18.A.3 — inline Quick FAQ auto-save (per-row).
        add_action( 'wp_ajax_bizcity_knowledge_quick_faq_upsert', [ $this, 'ajax_quick_faq_upsert' ] );
        add_action( 'wp_ajax_bizcity_knowledge_quick_faq_delete', [ $this, 'ajax_quick_faq_delete' ] );

        // Memory Hub — full CRUD for all 5 memory zones (admin-mh.js).
        add_action( 'wp_ajax_bizcity_mh_faq_upsert',      [ $this, 'ajax_mh_faq_upsert' ] );
        add_action( 'wp_ajax_bizcity_mh_faq_delete',      [ $this, 'ajax_mh_faq_delete' ] );
        add_action( 'wp_ajax_bizcity_mh_memory_upsert',   [ $this, 'ajax_mh_memory_upsert' ] );
        add_action( 'wp_ajax_bizcity_mh_episodic_list',   [ $this, 'ajax_mh_episodic_list' ] );
        add_action( 'wp_ajax_bizcity_mh_episodic_delete', [ $this, 'ajax_mh_episodic_delete' ] );
        add_action( 'wp_ajax_bizcity_mh_rolling_list',    [ $this, 'ajax_mh_rolling_list' ] );
        add_action( 'wp_ajax_bizcity_mh_rolling_delete',  [ $this, 'ajax_mh_rolling_delete' ] );
        add_action( 'wp_ajax_bizcity_mh_notes_list',      [ $this, 'ajax_mh_notes_list' ] );
        add_action( 'wp_ajax_bizcity_mh_notes_upsert',    [ $this, 'ajax_mh_notes_upsert' ] );
        add_action( 'wp_ajax_bizcity_mh_notes_delete',    [ $this, 'ajax_mh_notes_delete' ] );
        add_action( 'wp_ajax_bizcity_mh_files_list',      [ $this, 'ajax_mh_files_list' ] );
        add_action( 'wp_ajax_bizcity_mh_files_delete',    [ $this, 'ajax_mh_files_delete' ] );
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
        
        if (!self::can_manage()) {
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
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'bizcity-knowledge') === false) {
            return;
        }

        // Legal Knowledge Graph React SPA — removed 2026-05-21 (module deleted).

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
            // Sprint 0.18.A.2 — TwinChat-aligned skin (loaded last so it wins).
            wp_enqueue_style(
                'bizcity-knowledge-character-twinchat',
                plugins_url('assets/css/character-twinchat.css', dirname(__FILE__)),
                ['bizcity-knowledge-character-edit'],
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

        // Maturity Dashboard removed 2026-05-06 — Training/Memory Hub/Monitor pages
        // now fall through to standard bizcity-knowledge-admin styling below.

        wp_enqueue_style(
            'bizcity-knowledge-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            [],
            BIZCITY_KNOWLEDGE_VERSION
        );

        // Sprint 0.18.A.2 — TwinChat skin also applies to the character LIST page (cards).
        if ( strpos( $hook, 'bizcity-knowledge-characters' ) !== false ) {
            wp_enqueue_style(
                'bizcity-knowledge-character-twinchat',
                plugins_url( 'assets/css/character-twinchat.css', dirname( __FILE__ ) ),
                [ 'bizcity-knowledge-admin' ],
                BIZCITY_KNOWLEDGE_VERSION
            );
        }
        
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

        // Memory Hub — full CRUD + charts (admin-mh.js).
        // [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — the second clause was
        // `$hook === 'toplevel_page_bizcity-knowledge'`, meant to add this bundle to the
        // Training page as well. `bizcity-knowledge` stopped being a top-level menu when it
        // became a child of `bizcity-twin-workspace`, so that exact comparison can no longer
        // match any hook and the branch is unreachable. It is removed rather than repaired:
        // re-enabling a 34 KB bundle on a page that has been running without it is a
        // behaviour change, not dead-code removal. If Quick FAQ on the Training page is in
        // fact broken, that is a separate fix with its own evidence.
        $needs_mh = ( strpos( $hook, 'bizcity-knowledge-memory-hub' ) !== false );
        if ( $needs_mh ) {
            wp_register_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
                [],
                '4.4.3',
                true
            );
            wp_enqueue_script(
                'bizcity-knowledge-admin-mh',
                plugins_url( 'assets/js/admin-mh.js', dirname( __FILE__ ) ),
                [ 'bizcity-knowledge-admin', 'jquery', 'chartjs' ],
                BIZCITY_KNOWLEDGE_VERSION,
                true
            );
        }

        // Media uploader
        wp_enqueue_media();
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
            echo '<div id="bcn-app" class="bcn-wrap" style="min-height:100vh;margin:0;"></div>';
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'Learn with AI', 'bizcity-twin-ai' ) . '</h1>';
        echo '<p>' . esc_html__( 'The Companion Notebook plugin is not active. Please activate it in Plugins.', 'bizcity-twin-ai' ) . '</p></div>';
    }
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_dashboard() removed — unreachable from every WordPress entry point.
    
    /**
     * Render Characters List Page
     */
    public function render_characters_page() {
        // Hide WP admin chrome when embedded in TwinChat iframe.
        if ( ! empty( $_GET['bizcity_iframe'] ) ) {
            echo '<style>#adminmenumain,#adminmenuback,#wpadminbar,#wpfooter,.notice,.updated,.error{display:none!important}#wpcontent,#wpbody-content{margin-left:0!important;padding-top:0!important}</style>';
        }
        $db = BizCity_Knowledge_Database::instance();
        $iframe_suffix = ! empty( $_GET['bizcity_iframe'] ) ? '&bizcity_iframe=1' : '';
        $characters = $db->get_characters(['limit' => 100]);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Twin Connector', 'bizcity-twin-ai' ); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit' . $iframe_suffix); ?>" class="page-title-action"><?php esc_html_e( 'New Twin Connector', 'bizcity-twin-ai' ); ?></a>
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
                    <tr><td colspan="11"><?php esc_html_e( 'No Twin Connectors yet.', 'bizcity-twin-ai' ); ?> <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit' . $iframe_suffix); ?>"><?php esc_html_e( 'Create new', 'bizcity-twin-ai' ); ?></a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Card-based character list (mobile-friendly) -->
            <?php if (empty($characters)): ?>
            <div class="bk-empty-characters">
                <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
                    <div style="font-size:48px;margin-bottom:12px;">🤖</div>
                    <p><?php esc_html_e( 'No Twin Connectors yet.', 'bizcity-twin-ai' ); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit' . $iframe_suffix); ?>" class="button button-primary"><?php esc_html_e( 'New Twin Connector', 'bizcity-twin-ai' ); ?></a>
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
                        <span title="<?php esc_attr_e( 'Knowledge sources', 'bizcity-twin-ai' ); ?>"><?php echo count($sources); ?> sources</span>
                        <span title="Conversations"><?php echo number_format($char->total_conversations); ?> chats</span>
                        <span title="Rating"><?php echo number_format($char->rating, 1); ?> ★</span>
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
                        <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $char->id . $iframe_suffix); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'bizcity-twin-ai' ); ?></a>
                        <a href="#" class="button button-small duplicate-character" data-id="<?php echo $char->id; ?>" data-name="<?php echo esc_attr($char->name); ?>"><?php esc_html_e( 'Clone', 'bizcity-twin-ai' ); ?></a>
                        <a href="#" class="button button-small bk-btn-danger delete-character" data-id="<?php echo $char->id; ?>"><?php esc_html_e( 'Delete', 'bizcity-twin-ai' ); ?></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <style>
            /* ── Status Badge ── */
            .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; white-space: nowrap; background: #f3f4f6; color: #374151; }
            .status-active { background: #f0fdf4; color: #166534; }
            .status-published { background: #eff6ff; color: #1e40af; }
            .status-draft { background: #f9fafb; color: #6b7280; }
            .status-archived { background: #fefce8; color: #854d0e; }
            
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
                border-radius: 8px;
                padding: 16px;
                transition: box-shadow 0.15s;
            }
            .bk-char-card:hover {
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
            }
            .bk-btn-danger:hover {
                background: #fef2f2 !important;
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
                color: #555;
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
                flex-shrink: 0;
            }
            .bk-copy-shortcode.copied {
                color: #059669 !important;
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
     * Render Guru KPI Dashboard Page
     * [2026-06-24 Johnny Chu] GURU-KPI — Submenu thống kê KPI tất cả Guru
     */
    public function render_guru_kpi_page() {
        require_once BIZCITY_KNOWLEDGE_DIR . 'views/guru-kpi.php';
    }
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_character_edit_page_old() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_knowledge_sources_section() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — get_scope_icon() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — get_scope_label() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_intents_section() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_sources_page() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_memory_page() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_memory_requests_page() removed — unreachable from every WordPress entry point.

    /**
     * AJAX: Delete a single memory from the requests page
     */
    public function ajax_delete_memory() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $memory_id = intval( $_POST['memory_id'] ?? 0 );
        if ( ! $memory_id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID' ] );
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.4 — admin memory deletion uses the canonical filestore tombstone, never a legacy SQL payload delete.
        $deleted = false;
        if ( class_exists( 'BizCity_User_Memory' ) && class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
            $request_page = BizCity_User_Memory::instance()->get_all_requests( array(
                'blog_id'    => get_current_blog_id(),
                'per_page'   => 500,
                'page'       => 1,
            ) );
            foreach ( (array) ( $request_page['items'] ?? array() ) as $memory ) {
                if ( (int) ( $memory->id ?? 0 ) === $memory_id && ! empty( $memory->record_id ) ) {
                    $deleted = BizCity_Business_JSONL_File_Store::delete(
                        BizCity_User_Memory::BUSINESS_CONTRACT_ID,
                        (string) $memory->record_id,
                        array( 'blog_id' => get_current_blog_id() )
                    );
                    break;
                }
            }
        }
        wp_send_json_success( [ 'deleted' => (bool) $deleted ] );
    }

    /**
     * AJAX: Promote source to a different scope (Knowledge Fabric v3.0)
     */
    public function ajax_promote_source() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
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
     * AJAX: Tavily web search — proxies through BizCity_Search_Client (gateway, no local Tavily key needed).
     * Mirrors BCN_Tavily_Client pattern: uses bizcity_llm_api_key → search/router/v1/query on gateway.
     */
    public function ajax_tavily_search() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        $query       = sanitize_text_field( isset( $_POST['query'] ) ? $_POST['query'] : '' );
        $max_results = intval( isset( $_POST['max_results'] ) ? $_POST['max_results'] : 10 );

        if ( empty( $query ) ) {
            wp_send_json_error( array( 'message' => 'Missing query' ) );
        }

        if ( ! class_exists( 'BizCity_Search_Client' ) ) {
            wp_send_json_error( array( 'message' => 'BizCity_Search_Client không khả dụng' ) );
        }

        $client = BizCity_Search_Client::instance();
        if ( ! $client->is_ready() ) {
            wp_send_json_error( array( 'message' => 'BizCity API key chưa được cấu hình. Vào BizCity > Settings để nhập API key.' ) );
        }

        $results = $client->search( $query, $max_results );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( array( 'message' => $results->get_error_message() ) );
        }

        wp_send_json_success( array(
            'results' => $results,
            'answer'  => '',
        ) );
    }
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — render_settings_page() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — count_knowledge_sources() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — count_total_conversations() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — count_published_characters() removed — unreachable from every WordPress entry point.
    
    /**
     * AJAX: Save character
     */
    public function ajax_save_character() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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

        // Phase 0.18 — optional max_tokens override (NULL = system default).
        // Empty string from form → store NULL so the LLM client falls back
        // to its built-in default (3000) via `?? 3000` in chat_with_character().
        if ( array_key_exists( 'max_tokens', $data ) ) {
            $raw_max = trim( (string) $data['max_tokens'] );
            if ( $raw_max === '' ) {
                $char_data['max_tokens'] = null;
            } else {
                $mt = (int) $raw_max;
                if ( $mt < 1 )      { $char_data['max_tokens'] = null; }
                elseif ( $mt > 32000 ) { $char_data['max_tokens'] = 32000; }
                else                { $char_data['max_tokens'] = $mt; }
            }
        }
        
        // Handle skills as JSON array
        $skills = isset($data['skills']) && is_array($data['skills']) ? $data['skills'] : [];
        $char_data['capabilities'] = json_encode($skills, JSON_UNESCAPED_UNICODE);

        // Wave 0.18.2 — Persona Provider binding (settings.provider_id).
        // Merge into existing settings JSON so we don't clobber unrelated keys.
        $existing_settings = [];
        $current_id        = intval( $data['id'] ?? 0 );
        if ( $current_id > 0 ) {
            $existing_char = $db->get_character( $current_id );
            $raw_settings  = is_object( $existing_char ) ? ( $existing_char->settings ?? '' )
                : ( is_array( $existing_char ) ? ( $existing_char['settings'] ?? '' ) : '' );
            if ( is_string( $raw_settings ) && $raw_settings !== '' ) {
                $decoded = json_decode( $raw_settings, true );
                if ( is_array( $decoded ) ) {
                    $existing_settings = $decoded;
                }
            } elseif ( is_array( $raw_settings ) ) {
                $existing_settings = $raw_settings;
            }
        }
        if ( array_key_exists( 'persona_provider_id', $data ) ) {
            $provider_id = sanitize_key( (string) $data['persona_provider_id'] );
            // Validate against registry when available; allow empty (= unbind).
            if ( $provider_id !== '' && class_exists( 'BizCity_Persona_Registry' ) ) {
                if ( ! BizCity_Persona_Registry::instance()->get( $provider_id ) ) {
                    // Unknown provider — keep value but log so admin can see it on next reload.
                    $existing_settings['provider_id_pending'] = $provider_id;
                }
            }
            if ( $provider_id === '' ) {
                unset( $existing_settings['provider_id'] );
            } else {
                $existing_settings['provider_id'] = $provider_id;
            }
        }
        if ( ! empty( $existing_settings ) || array_key_exists( 'persona_provider_id', $data ) ) {
            $char_data['settings'] = json_encode( $existing_settings, JSON_UNESCAPED_UNICODE );
        }
        
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

        /**
         * Fires after a character row has been saved via AJAX. Listeners may
         * persist additional fields (e.g. CRM Service Template selectors)
         * by reading from $data and writing through update_character() with
         * `settings` JSON merge.
         *
         * @param int   $id   Character id.
         * @param array $data Raw $_POST payload (already stripslashed via shortcode_atts upstream is NOT done — handler reads $_POST directly).
         */
        do_action( 'bizcity_knowledge_character_saved', (int) $id, (array) $data );
        
        // Save Quick Knowledge — Sprint 0.18.A.1: capture result for visibility/diagnostics
        $quick_knowledge_result = null;
        if ( ! empty( $data['quick_knowledge_data'] ) ) {
            $quick_knowledge = json_decode( stripslashes( $data['quick_knowledge_data'] ), true );
            if ( is_array( $quick_knowledge ) ) {
                $quick_knowledge_result = $this->save_quick_knowledge( $id, $quick_knowledge );
            } else {
                $quick_knowledge_result = [
                    'created' => [],
                    'updated' => [],
                    'deleted' => [],
                    'errors'  => [ 'quick_knowledge_data is not a JSON array' ],
                    'rows'    => [],
                ];
            }
        }

        // Save FAQs
        $faqs_result = null;
        if ( ! empty( $data['faqs_data'] ) ) {
            $faqs = json_decode( stripslashes( $data['faqs_data'] ), true );
            if ( is_array( $faqs ) ) {
                $faqs_result = $this->save_faqs( $id, $faqs );
            }
        }

        wp_send_json_success( [
            'id'              => $id,
            'quick_knowledge' => $quick_knowledge_result,
            'faqs'            => $faqs_result,
        ] );
    }
    
    /**
     * Save quick knowledge entries.
     *
     * Sprint 0.18.A.1: now returns a structured result so callers (and the FE)
     * can see exactly which rows were created / updated / deleted, fix the
     * stale `data-id="0"` issue in the editor, and surface DB errors that
     * previously failed silently.
     *
     * @param int   $character_id Character owning the FAQs.
     * @param array $entries      Posted rows: [{id, title, content}, ...].
     *
     * @return array{
     *   created: int[],
     *   updated: int[],
     *   deleted: int[],
     *   errors:  string[],
     *   rows:    array<int,array{client_index:int,id:int,title:string,op:string}>
     * }
     */
    private function save_quick_knowledge( $character_id, $entries ) {
        global $wpdb;
        $db = BizCity_Knowledge_Database::instance();

        $character_id = (int) $character_id;
        $submitted_ids = [];
        $result = [
            'created' => [],
            'updated' => [],
            'deleted' => [],
            'errors'  => [],
            'rows'    => [],
        ];

        if ( $character_id <= 0 ) {
            $result['errors'][] = 'invalid_character_id';
            return $result;
        }

        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        foreach ( $entries as $client_index => $entry ) {
            $title   = sanitize_text_field( $entry['title'] ?? '' );
            $body    = sanitize_textarea_field( $entry['content'] ?? '' );

            // Skip fully empty rows so users can leave blank trailing rows in the editor.
            if ( $title === '' && $body === '' ) {
                continue;
            }

            $source_id = intval( $entry['id'] ?? 0 );
            $content   = wp_json_encode( [
                'title'   => $title,
                'content' => $body,
            ], JSON_UNESCAPED_UNICODE );

            if ( $source_id > 0 ) {
                $updated = $wpdb->update(
                    $table,
                    [
                        'content'      => $content,
                        'content_hash' => md5( $content ),
                        'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
                        'status'       => 'ready',
                    ],
                    [ 'id' => $source_id, 'character_id' => $character_id ],
                    [ '%s', '%s', '%s', '%s' ],
                    [ '%d', '%d' ]
                );
                if ( false === $updated ) {
                    $result['errors'][] = sprintf(
                        'update_failed id=%d: %s',
                        $source_id,
                        $wpdb->last_error ?: 'unknown'
                    );
                    continue;
                }
                $submitted_ids[]    = $source_id;
                $result['updated'][] = $source_id;
                $result['rows'][]   = [
                    'client_index' => (int) $client_index,
                    'id'           => $source_id,
                    'title'        => $title,
                    'op'           => 'updated',
                ];
            } else {
                $new_id = $db->create_knowledge_source( [
                    'character_id' => $character_id,
                    'source_type'  => 'quick_faq',
                    'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
                    'content'      => $content,
                    'content_hash' => md5( $content ),
                    'status'       => 'ready',
                ] );
                if ( is_wp_error( $new_id ) ) {
                    $result['errors'][] = 'create_failed: ' . $new_id->get_error_message();
                    continue;
                }
                if ( ! $new_id ) {
                    $result['errors'][] = 'create_failed: ' . ( $wpdb->last_error ?: 'no insert_id' );
                    continue;
                }
                $submitted_ids[]    = (int) $new_id;
                $result['created'][] = (int) $new_id;
                $result['rows'][]   = [
                    'client_index' => (int) $client_index,
                    'id'           => (int) $new_id,
                    'title'        => $title,
                    'op'           => 'created',
                ];
            }
        }

        // Delete entries that were removed from the UI.
        $existing_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE character_id = %d AND source_type = 'quick_faq'",
            $character_id
        ) );

        foreach ( $existing_ids as $existing_id ) {
            $existing_id = (int) $existing_id;
            if ( ! in_array( $existing_id, $submitted_ids, true ) ) {
                $wpdb->delete(
                    $table,
                    [ 'id' => $existing_id ],
                    [ '%d' ]
                );
                $result['deleted'][] = $existing_id;
            }
        }

        return $result;
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

        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $id = intval( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid character ID' ] );
        }

        $db = BizCity_Knowledge_Database::instance();
        $db->delete_character( $id );

        wp_send_json_success();
    }

    /**
     * AJAX: Sprint 0.18.A.3 — upsert ONE inline Quick FAQ row.
     *
     * Accepts: character_id, source_id (0 for create), title, content.
     * Returns: { id, op: 'created'|'updated' }.
     */
    public function ajax_quick_faq_upsert() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );

        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        $source_id    = intval( $_POST['source_id']    ?? 0 );
        $title        = sanitize_text_field( wp_unslash( $_POST['title']   ?? '' ) );
        $body         = sanitize_textarea_field( wp_unslash( $_POST['content'] ?? '' ) );

        if ( $character_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_character_id' ] );
        }
        if ( $title === '' && $body === '' ) {
            wp_send_json_error( [ 'message' => 'empty_row' ] );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'bizcity_knowledge_sources';
        $content = wp_json_encode( [
            'title'   => $title,
            'content' => $body,
        ], JSON_UNESCAPED_UNICODE );

        if ( $source_id > 0 ) {
            $updated = $wpdb->update(
                $table,
                [
                    'content'      => $content,
                    'content_hash' => md5( $content ),
                    'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
                    'status'       => 'ready',
                ],
                [ 'id' => $source_id, 'character_id' => $character_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d', '%d' ]
            );
            if ( false === $updated ) {
                wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'update_failed' ] );
            }
            wp_send_json_success( [ 'id' => $source_id, 'op' => 'updated' ] );
        }

        $db     = BizCity_Knowledge_Database::instance();
        $new_id = $db->create_knowledge_source( [
            'character_id' => $character_id,
            'source_type'  => 'quick_faq',
            'source_name'  => $title !== '' ? $title : 'Quick Knowledge',
            'content'      => $content,
            'content_hash' => md5( $content ),
            'status'       => 'ready',
        ] );

        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
        }
        if ( ! $new_id ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'create_failed' ] );
        }

        wp_send_json_success( [ 'id' => (int) $new_id, 'op' => 'created' ] );
    }

    /**
     * AJAX: Sprint 0.18.A.3 — delete ONE inline Quick FAQ row.
     */
    public function ajax_quick_faq_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );

        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        $source_id    = intval( $_POST['source_id']    ?? 0 );

        if ( $character_id <= 0 || $source_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_args' ] );
        }

        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [ 'id' => $source_id, 'character_id' => $character_id, 'source_type' => 'quick_faq' ],
            [ '%d', '%d', '%s' ]
        );

        if ( false === $deleted ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'delete_failed' ] );
        }

        wp_send_json_success( [ 'id' => $source_id, 'rows' => (int) $deleted ] );
    }

    /* ================================================================
     *  Memory Hub — full CRUD AJAX handlers (used by admin-mh.js)
     *  All handlers: nonce = bizcity_knowledge, cap = manage_options.
     * ================================================================ */

    /**
     * AJAX: Upsert one Quick FAQ row for current user (Memory Hub).
     * Uses user_id (not character_id) as owner key.
     */
    public function ajax_mh_faq_upsert() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid       = get_current_user_id();
        $source_id = intval( $_POST['source_id'] ?? 0 );
        $question  = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );
        $answer    = sanitize_textarea_field( wp_unslash( $_POST['answer']   ?? '' ) );

        if ( $question === '' && $answer === '' ) {
            wp_send_json_error( [ 'message' => 'empty_row' ] );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'bizcity_knowledge_sources';
        $content = wp_json_encode( [ 'question' => $question, 'answer' => $answer ], JSON_UNESCAPED_UNICODE );

        if ( $source_id > 0 ) {
            $r = $wpdb->update(
                $table,
                [ 'content' => $content, 'content_hash' => md5( $content ), 'source_name' => $question ?: 'Quick FAQ', 'status' => 'ready' ],
                [ 'id' => $source_id, 'user_id' => $uid, 'source_type' => 'quick_faq' ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d', '%d', '%s' ]
            );
            if ( false === $r ) {
                wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'update_failed' ] );
            }
            wp_send_json_success( [ 'id' => $source_id, 'op' => 'updated' ] );
        }

        $r = $wpdb->insert(
            $table,
            [ 'user_id' => $uid, 'source_type' => 'quick_faq', 'source_name' => $question ?: 'Quick FAQ', 'content' => $content, 'content_hash' => md5( $content ), 'status' => 'ready' ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        if ( ! $r ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'insert_failed' ] );
        }
        wp_send_json_success( [ 'id' => (int) $wpdb->insert_id, 'op' => 'created' ] );
    }

    /**
     * AJAX: Delete one Quick FAQ row for current user (Memory Hub).
     */
    public function ajax_mh_faq_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid       = get_current_user_id();
        $source_id = intval( $_POST['source_id'] ?? 0 );
        if ( $source_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_source_id' ] );
        }

        global $wpdb;
        $r = $wpdb->delete(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [ 'id' => $source_id, 'user_id' => $uid, 'source_type' => 'quick_faq' ],
            [ '%d', '%d', '%s' ]
        );
        if ( false === $r ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'delete_failed' ] );
        }
        wp_send_json_success( [ 'id' => $source_id ] );
    }

    /**
     * AJAX: Upsert one Long-term User Memory row (Memory Hub).
     */
    public function ajax_mh_memory_upsert() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid         = get_current_user_id();
        $memory_id   = intval( $_POST['memory_id'] ?? 0 );
        $memory_type = sanitize_text_field( wp_unslash( $_POST['memory_type'] ?? 'fact' ) );
        $memory_key  = sanitize_text_field( wp_unslash( $_POST['memory_key']  ?? '' ) );
        $content     = sanitize_textarea_field( wp_unslash( $_POST['content']  ?? '' ) );
        $importance  = max( 0, min( 100, intval( $_POST['importance'] ?? 50 ) ) );

        $allowed_types = [ 'fact', 'preference', 'identity', 'goal', 'pain', 'constraint', 'habit', 'relationship', 'request' ];
        if ( ! in_array( $memory_type, $allowed_types, true ) ) {
            $memory_type = 'fact';
        }

        if ( $content === '' ) {
            wp_send_json_error( [ 'message' => 'empty_content' ] );
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.4 — Memory Hub user CRUD is filestore-owned; SQL payload writes are disabled.
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            wp_send_json_error( [ 'message' => 'memory_service_unavailable' ] );
        }
        $memory = null;
        if ( $memory_id > 0 ) {
            foreach ( (array) BizCity_User_Memory::instance()->get_memories( array( 'user_id' => $uid, 'limit' => 200 ) ) as $candidate ) {
                if ( (int) ( $candidate->id ?? 0 ) === $memory_id && ! empty( $candidate->record_id ) ) {
                    $memory = $candidate;
                    break;
                }
            }
            if ( ! $memory ) {
                wp_send_json_error( [ 'message' => 'memory_not_found' ] );
            }
        }
        $scope = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $uid ) )
            : null;
        $result = BizCity_User_Memory::instance()->upsert_public( array(
            'user_id'       => $uid,
            'identity_uuid'  => (string) ( $memory->identity_uuid ?? $scope['identity_uuid'] ?? '' ),
            'session_id'    => (string) ( $memory->session_id ?? '' ),
            'memory_tier'   => (string) ( $memory->memory_tier ?? 'explicit' ),
            'memory_type'   => $memory_type,
            'memory_key'    => $memory_key !== '' ? $memory_key : (string) ( $memory->memory_key ?? '' ),
            'memory_text'   => $content,
            'score'         => $importance,
        ) );
        if ( false === $result ) {
            wp_send_json_error( [ 'message' => 'memory_write_failed' ] );
        }
        wp_send_json_success( [ 'op' => (string) $result ] );
    }

    /**
     * AJAX: List Episodic Memory rows for current user (Memory Hub).
     */
    public function ajax_mh_episodic_list() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid   = get_current_user_id();
        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — load the canonical memory pointer reader for the Memory Hub admin surface.
        if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
            bizcity_context_bank_load_memory_runtime();
        }

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = min( 100, max( 10, intval( $_POST['per_page'] ?? 50 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — Memory Hub reads verified episodic pointers through the Context Bank adapter.
		$all_rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' )
			? BizCity_Context_Bank_Memory_Adapter::query( BizCity_Episodic_Memory::BUSINESS_CONTRACT_ID, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'limit' => 500 ) )
			: array();
		$rows = array_slice( $all_rows, $offset, $per_page );
		$rows = array_map( function ( $row ) { return array( 'id' => (int) ( $row['legacy_id'] ?? 0 ), 'record_id' => (string) ( $row['record_id'] ?? '' ), 'event_type' => (string) ( $row['event_type'] ?? $row['memory_type'] ?? '' ), 'event_key' => (string) ( $row['event_key'] ?? $row['memory_key'] ?? '' ), 'event_text' => (string) ( $row['event_text'] ?? $row['memory_text'] ?? '' ), 'importance' => (int) ( $row['importance'] ?? $row['score'] ?? 0 ), 'source_goal' => (string) ( $row['source_goal'] ?? $row['goal'] ?? '' ), 'last_seen' => (string) ( $row['last_seen'] ?? '' ), 'updated_at' => (string) ( $row['updated_at'] ?? '' ) ); }, $rows );
		$total = count( $all_rows );

        wp_send_json_success( [ 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ] );
    }

    /**
     * AJAX: Delete one Episodic Memory row (Memory Hub).
     */
    public function ajax_mh_episodic_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid = get_current_user_id();
        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — use the same Context Bank reader for owner-scoped delete lookup.
        if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
            bizcity_context_bank_load_memory_runtime();
        }
        $id  = intval( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_id' ] );
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — delete episodic records through Context Bank-backed receipt tombstones.
        $deleted = false;
        if ( class_exists( 'BizCity_Episodic_Memory' ) && class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
            $rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ? BizCity_Context_Bank_Memory_Adapter::query( BizCity_Episodic_Memory::BUSINESS_CONTRACT_ID, array(
                'blog_id' => get_current_blog_id(),
                'user_id' => $uid,
                'limit'   => 1000,
                'days'    => 3650,
                'filter'  => function ( $row ) use ( $id ) {
                    return (int) ( $row['legacy_id'] ?? 0 ) === $id;
                },
            ) ) : array();
            foreach ( $rows as $row ) {
                if ( ! empty( $row['record_id'] ) ) {
                    $delete_receipt = BizCity_Business_JSONL_File_Store::delete_with_receipt( BizCity_Episodic_Memory::BUSINESS_CONTRACT_ID, (string) $row['record_id'], array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid ) );
                    $deleted = is_array( $delete_receipt );
                    if ( $deleted ) { do_action( 'bizcity_memory_mirror_delete', 'episodic', $id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'record_id' => (string) $row['record_id'], 'filestore_receipt' => $delete_receipt ) ); }
                }
            }
        }
        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => 'delete_failed' ] );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    /**
     * AJAX: List Rolling Memory rows for current user (Memory Hub).
     */
    public function ajax_mh_rolling_list() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid   = get_current_user_id();
        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — load the canonical rolling pointer reader for the Memory Hub admin surface.
        if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
            bizcity_context_bank_load_memory_runtime();
        }

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = min( 100, max( 10, intval( $_POST['per_page'] ?? 50 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — Memory Hub reads verified rolling pointers through the Context Bank adapter.
		$all_rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' )
			? BizCity_Context_Bank_Memory_Adapter::query( BizCity_Rolling_Memory::BUSINESS_CONTRACT_ID, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'limit' => 500 ) )
			: array();
		$rows = array_slice( $all_rows, $offset, $per_page );
		$rows = array_map( function ( $row ) { return array( 'id' => (int) ( $row['legacy_id'] ?? 0 ), 'record_id' => (string) ( $row['record_id'] ?? '' ), 'goal' => (string) ( $row['goal'] ?? '' ), 'goal_label' => (string) ( $row['goal_label'] ?? '' ), 'window_summary' => (string) ( $row['window_summary'] ?? '' ), 'status' => (string) ( $row['status'] ?? '' ), 'user_goal_score' => (int) ( $row['user_goal_score'] ?? 0 ), 'bot_satisfaction_score' => (int) ( $row['bot_satisfaction_score'] ?? 0 ), 'total_turns' => (int) ( $row['total_turns'] ?? 0 ), 'updated_at' => (string) ( $row['updated_at'] ?? '' ) ); }, $rows );
		$total = count( $all_rows );

        wp_send_json_success( [ 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ] );
    }

    /**
     * AJAX: Delete one Rolling Memory row (Memory Hub).
     */
    public function ajax_mh_rolling_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid = get_current_user_id();
        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — use the same Context Bank reader for owner-scoped delete lookup.
        if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
            bizcity_context_bank_load_memory_runtime();
        }
        $id  = intval( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_id' ] );
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — delete rolling records through Context Bank-backed receipt tombstones.
        $deleted = false;
        if ( class_exists( 'BizCity_Rolling_Memory' ) && class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
            $rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ? BizCity_Context_Bank_Memory_Adapter::query( BizCity_Rolling_Memory::BUSINESS_CONTRACT_ID, array(
                'blog_id' => get_current_blog_id(),
                'user_id' => $uid,
                'limit'   => 1000,
                'days'    => 3650,
                'filter'  => function ( $row ) use ( $id ) {
                    return (int) ( $row['legacy_id'] ?? 0 ) === $id;
                },
            ) ) : array();
            foreach ( $rows as $row ) {
                if ( ! empty( $row['record_id'] ) ) {
                    $delete_receipt = BizCity_Business_JSONL_File_Store::delete_with_receipt( BizCity_Rolling_Memory::BUSINESS_CONTRACT_ID, (string) $row['record_id'], array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid ) );
                    $deleted = is_array( $delete_receipt );
                    if ( $deleted ) { do_action( 'bizcity_memory_mirror_delete', 'rolling', $id, array( 'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'record_id' => (string) $row['record_id'], 'filestore_receipt' => $delete_receipt ) ); }
                }
            }
        }
        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => 'delete_failed' ] );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    /**
     * AJAX: List Research Notes for current user (Memory Hub).
     */
    public function ajax_mh_notes_list() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid   = get_current_user_id();
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — notes list is served by the Context Bank-backed Notes service.
		$service = class_exists( 'BizCity_TwinChat_Notes_Service' ) ? new BizCity_TwinChat_Notes_Service() : null;
		if ( ! $service ) {
			wp_send_json_success( [ 'rows' => [], 'total' => 0 ] );
		}

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = min( 100, max( 10, intval( $_POST['per_page'] ?? 50 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

		$all_rows = $service->get_all_by_user( $uid, 500 );
		$rows = array_slice( array_map( function ( $row ) { return (array) $row; }, $all_rows ), $offset, $per_page );
		$total = count( $all_rows );

        wp_send_json_success( [ 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ] );
    }

    /**
     * AJAX: Upsert one Research Note (Memory Hub).
     */
    public function ajax_mh_notes_upsert() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid      = get_current_user_id();
        $note_id  = intval( $_POST['note_id'] ?? 0 );
        $title    = sanitize_text_field( wp_unslash( $_POST['title']   ?? '' ) );
        $content  = sanitize_textarea_field( wp_unslash( $_POST['content'] ?? '' ) );
        $tags     = sanitize_text_field( wp_unslash( $_POST['tags']    ?? '[]' ) );

        if ( $title === '' && $content === '' ) {
            wp_send_json_error( [ 'message' => 'empty_note' ] );
        }

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — Memory Hub notes CRUD is filestore-owned; SQL payload writes are disabled.
		if ( ! class_exists( 'BizCity_TwinChat_Notes_Service' ) ) {
			wp_send_json_error( [ 'message' => 'notes_service_unavailable' ] );
		}
		$service = new BizCity_TwinChat_Notes_Service();
		if ( $note_id > 0 ) {
			$updated = $service->update( $note_id, array( 'title' => $title, 'content' => $content ) );
			if ( ! $updated ) {
				wp_send_json_error( [ 'message' => 'note_update_failed' ] );
			}
			wp_send_json_success( [ 'id' => $note_id, 'op' => 'updated' ] );
		}
		$created = $service->create( array(
			'user_id'    => $uid,
			'title'      => $title,
			'content'    => $content,
			'tags'       => $tags,
			'note_type'  => 'manual',
			'created_by' => 'user',
		) );
		if ( is_wp_error( $created ) ) {
			wp_send_json_error( [ 'message' => $created->get_error_message() ] );
		}
		wp_send_json_success( [ 'id' => (int) $created, 'op' => 'created' ] );
    }

    /**
     * AJAX: Delete one Research Note (Memory Hub).
     */
    public function ajax_mh_notes_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid     = get_current_user_id();
        $note_id = intval( $_POST['note_id'] ?? 0 );
        if ( $note_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_id' ] );
        }

		// [2026-09-01 Johnny Chu] PHASE-CB4.4 — delete notes through the canonical filestore tombstone.
		$deleted = class_exists( 'BizCity_TwinChat_Notes_Service' )
			&& ( new BizCity_TwinChat_Notes_Service() )->delete( $note_id );
		if ( ! $deleted ) {
			wp_send_json_error( [ 'message' => 'note_delete_failed' ] );
		}
		wp_send_json_success( [ 'id' => $note_id ] );
    }

    /**
     * AJAX: List files from bizcity_rces (BCN sources) for Memory Hub Files tab.
     */
    public function ajax_mh_files_list() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        global $wpdb;
        $uid         = get_current_user_id();
        $table       = $wpdb->prefix . 'bzdoc_documents';
        $page        = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page    = min( 100, max( 10, intval( $_POST['per_page'] ?? 50 ) ) );
        $offset      = ( $page - 1 ) * $per_page;
        $notebook_id = intval( $_POST['notebook_id'] ?? 0 );
        $doc_type    = sanitize_text_field( $_POST['doc_type'] ?? '' );

        // [2026-06-21 Johnny Chu] R-SHOW-TABLES
        if ( ! bizcity_tbl_exists( $table ) ) {
            wp_send_json_success( [ 'rows' => [], 'total' => 0 ] );
        }

        $where = $wpdb->prepare( 'WHERE user_id = %d', $uid );
        if ( $notebook_id > 0 ) {
            $where .= $wpdb->prepare( ' AND notebook_id = %d', $notebook_id );
        }
        if ( $doc_type !== '' ) {
            $where .= $wpdb->prepare( ' AND doc_type = %s', $doc_type );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, doc_type, title, template_name, theme_name, status,
                        notebook_id, created_at, updated_at
                 FROM {$table} {$where}
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        wp_send_json_success( [ 'rows' => $rows, 'total' => $total ] );
    }

    /**
     * AJAX: Delete a document from bzdoc_documents for Memory Hub Files tab.
     */
    public function ajax_mh_files_delete() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );
        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        $uid    = get_current_user_id();
        $doc_id = intval( $_POST['doc_id'] ?? 0 );
        if ( $doc_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'invalid_id' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bzdoc_documents';
        $r     = $wpdb->delete(
            $table,
            [ 'id' => $doc_id, 'user_id' => $uid ],
            [ '%d', '%d' ]
        );
        if ( false === $r ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ?: 'delete_failed' ] );
        }
        wp_send_json_success( [ 'id' => $doc_id ] );
    }
    public function ajax_quick_update_status() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Gateway-only: route through BizCity_LLM_Client (R-GW-1).
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            wp_send_json_error(['message' => 'BizCity_LLM_Client not loaded — gateway client missing.']);
        }
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            wp_send_json_error(['message' => 'BizCity API key chưa cấu hình (gateway).']);
        }

        $models = $client->get_available_models();
        if ( ! empty( $models ) ) {
            wp_send_json_success(['models' => count( $models )]);
        } else {
            wp_send_json_error(['message' => 'Invalid response from gateway']);
        }
    }
    
    /**
     * AJAX: Fetch models from OpenRouter
     */
    public function ajax_fetch_models() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Gateway-only: route through BizCity_LLM_Client (R-GW-1).
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            wp_send_json_error(['message' => 'BizCity_LLM_Client not loaded — gateway client missing.']);
        }
        $client = BizCity_LLM_Client::instance();
        if ( ! $client->is_ready() ) {
            wp_send_json_error(['message' => 'BizCity API key chưa cấu hình (gateway).']);
        }

        // Check cache first
        $cache_key = 'bizcity_knowledge_openrouter_models';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            wp_send_json_success(['models' => $cached]);
        }

        $raw_models = $client->get_available_models();
        if ( ! empty( $raw_models ) ) {
            $models = [];
            foreach ( $raw_models as $model ) {
                $models[] = [
                    'id'             => $model['id'] ?? '',
                    'name'           => $model['name'] ?? ( $model['id'] ?? '' ),
                    'description'    => $model['description'] ?? '',
                    'context_length' => $model['context_length'] ?? 0,
                    'pricing'        => [
                        'prompt'     => $model['pricing']['prompt'] ?? 0,
                        'completion' => $model['pricing']['completion'] ?? 0,
                    ],
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
        
        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $character_id = intval($_POST['character_id'] ?? 0);
        $message      = sanitize_textarea_field($_POST['message'] ?? '');
        $history      = json_decode(stripslashes($_POST['history'] ?? '[]'), true);
        $images       = json_decode(stripslashes($_POST['images'] ?? '[]'), true);
        $session_id   = sanitize_text_field($_POST['session_id'] ?? '');
        
        // Allow empty message if images are provided
        if (!$character_id || (!$message && empty($images))) {
            wp_send_json_error(['message' => 'Missing parameters']);
        }
        
        $db = BizCity_Knowledge_Database::instance();
        $character = $db->get_character($character_id);
        
        if (!$character) {
            wp_send_json_error(['message' => 'Character not found']);
        }

        // Rule W-1: Session management (PHASE-0-RULES-WEBCHAT)
        $wc_db = class_exists('BizCity_WebChat_Database') ? BizCity_WebChat_Database::instance() : null;
        if ($wc_db) {
            $existing_session = $session_id ? $wc_db->get_session_v3_by_session_id($session_id) : null;
            if (!$existing_session) {
                $user      = wp_get_current_user();
                $sess_data = $wc_db->create_session_v3(
                    get_current_user_id(),
                    $user->display_name ?: $user->user_login,
                    'LEGAL-ADMIN',
                    '',
                    ['character_id' => $character_id]
                );
                $session_id = $sess_data['session_id'];
            } else {
                $session_id = $existing_session->session_id;
            }
        } elseif (!$session_id) {
            $session_id = 'legal_' . get_current_blog_id() . '_' . get_current_user_id();
        }

        // Rule W-2: Log user message
        if ($wc_db) {
            $user_msg_id = uniqid('legal_u_');
            $wc_db->log_message([
                'session_id'    => $session_id,
                'user_id'       => get_current_user_id(),
                'message_id'    => $user_msg_id,
                'message_text'  => $message ?: '[Image]',
                'message_from'  => 'user',
                'message_type'  => !empty($images) ? 'image' : 'text',
                'plugin_slug'   => 'legal-knowledge',
                'platform_type' => 'LEGAL-ADMIN',
                'attachments'   => $images ?: [],
            ]);
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
        
        // Rule W-5: Route through BizCity_LLM_Client (not direct API calls)
        // Rule W-2: Capture response to log bot reply before returning
        $llm_result = $this->get_ai_response_for_chat($character, $messages, $vision_used);

        // Rule W-2: Log bot reply
        if ($wc_db && !empty($llm_result['message'])) {
            $wc_db->log_message([
                'session_id'    => $session_id,
                'user_id'       => 0,
                'message_id'    => uniqid('legal_b_'),
                'message_text'  => $llm_result['message'],
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'plugin_slug'   => 'legal-knowledge',
                'platform_type' => 'LEGAL-ADMIN',
                'input_tokens'  => $llm_result['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $llm_result['usage']['completion_tokens'] ?? 0,
            ]);
        }

        wp_send_json_success([
            'reply'       => $llm_result['message'] ?? '',
            'message'     => $llm_result['message'] ?? '',
            'session_id'  => $session_id,
            'model'       => $llm_result['model'] ?? '',
            'usage'       => $llm_result['usage'] ?? [],
            'provider'    => $llm_result['provider'] ?? 'gateway',
            'vision_used' => $vision_used,
        ]);
    }
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — build_knowledge_context() removed — unreachable from every WordPress entry point.

    /**
     * Get AI response and return as array (for session-aware logging).
     * Routes through BizCity_LLM_Client (→ bizcity-llm-router @ bizcity.vn).
     * Rule W-5: PHASE-0-RULES-WEBCHAT
     *
     * @param object $character  Character object
     * @param array  $messages   Chat messages
     * @param bool   $vision_used Whether vision was used
     * @return array  Keys: message, model, usage, provider, success, error
     */
    private function get_ai_response_for_chat($character, $messages, $vision_used = false) {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return [ 'success' => false, 'message' => '', 'error' => 'BizCity_LLM_Client not available.' ];
        }

        $llm = BizCity_LLM_Client::instance();

        if ( ! $llm->is_ready() ) {
            return [ 'success' => false, 'message' => '', 'error' => 'LLM gateway not configured.' ];
        }

        $settings    = json_decode( $character->settings ?? '{}', true );
        $temperature = isset( $settings['temperature'] )
            ? floatval( $settings['temperature'] )
            : floatval( $character->creativity_level ?? 0.7 );
        $max_tokens  = isset( $settings['max_tokens'] )
            ? intval( $settings['max_tokens'] )
            : 1000;

        $result = $llm->chat( $messages, [
            'model'       => $character->model_id ?: $llm->get_model( 'chat' ),
            'purpose'     => 'chat',
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
        ] );

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'message' => '', 'error' => $result['error'] ?? 'LLM request failed' ];
        }

        return [
            'success'     => true,
            'message'     => $result['message'],
            'model'       => $result['model'] ?? $character->model_id,
            'usage'       => $result['usage'] ?? [],
            'provider'    => $result['provider'] ?? 'gateway',
            'vision_used' => $vision_used,
        ];
    }
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — chat_with_openrouter() removed — unreachable from every WordPress entry point.
	// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-08 H-02 — chat_with_openai() removed — unreachable from every WordPress entry point.
    
    /**
     * AJAX: Upload document
     */
    public function ajax_upload_document() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
            // Legal-scope tagging block removed 2026-05-21 (Legal module deleted).

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
            'uploaded'  => $uploaded,
            'documents' => $uploaded, // compat alias
            'message'   => $message,
            'errors'    => $errors,
        ]);
    }
    
    /**
     * AJAX: Delete document
     */
    public function ajax_delete_document() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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
     * AJAX: List knowledge sources for a character (for React persistent history).
     */
    public function ajax_list_sources() {
        check_ajax_referer( 'bizcity_knowledge', 'nonce' );

        if ( ! self::can_manage() ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        $source_type  = sanitize_text_field( $_POST['source_type'] ?? '' );

        if ( ! $character_id ) {
            wp_send_json_error( [ 'message' => 'Character ID required' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        if ( $source_type ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, source_type, source_name, source_url, status, chunks_count, created_at
                 FROM {$table}
                 WHERE character_id = %d AND source_type = %s
                 ORDER BY created_at DESC
                 LIMIT 200",
                $character_id,
                $source_type
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, source_type, source_name, source_url, status, chunks_count, created_at
                 FROM {$table}
                 WHERE character_id = %d
                 ORDER BY created_at DESC
                 LIMIT 200",
                $character_id
            ) );
        }

        wp_send_json_success( $rows ?: [] );
    }

    /**
     * AJAX: Add website to character
     */
    public function ajax_add_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
        $source_ids    = [];
        $insert_errors = [];
        $table         = $wpdb->prefix . 'bizcity_knowledge_sources';

        // Detect source_url column max length so long URLs don't silently fail insert
        $url_max_len = 500;
        $col_info = $wpdb->get_row( $wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source_url'",
            DB_NAME,
            $table
        ) );
        if ( $col_info && (int) $col_info->len > 0 ) {
            $url_max_len = (int) $col_info->len;
        }

        foreach ($urls_to_crawl as $crawl_url) {
            // Guard: skip URLs that exceed the column limit (and report so UI can show reason)
            if ( strlen( $crawl_url ) > $url_max_len ) {
                $insert_errors[] = sprintf( 'URL dài %d ký tự, vượt quá giới hạn %d của cột source_url', strlen( $crawl_url ), $url_max_len );
                continue;
            }

            // Check if URL already exists for this character
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE character_id = %d AND source_url = %s LIMIT 1",
                $character_id,
                $crawl_url
            ) );

            if ( $existing_id ) {
                // Reset to pending so processWebsite will re-crawl it
                $wpdb->update( $table, [ 'status' => 'pending' ], [ 'id' => $existing_id ], [ '%s' ], [ '%d' ] );
                $source_ids[] = $existing_id;
                $sources_added++;
                continue;
            }

            // Insert new pending source
            $inserted = $wpdb->insert(
                $table,
                [
                    'character_id' => $character_id,
                    'source_type'  => 'url',
                    'source_url'   => $crawl_url,
                    'source_name'  => mb_substr( $crawl_url, 0, 255 ),
                    'status'       => 'pending',
                    'created_at'   => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( false === $inserted ) {
                $insert_errors[] = $wpdb->last_error ?: 'Insert failed (unknown DB error)';
                continue;
            }

            $new_id = (int) $wpdb->insert_id;

            // Fallback: if insert_id not captured (e.g. unique-key conflict), query for it
            if ( ! $new_id ) {
                $new_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE character_id = %d AND source_url = %s LIMIT 1",
                    $character_id,
                    $crawl_url
                ) );
            }

            if ( $new_id ) {
                $source_ids[] = $new_id;
                $sources_added++;
            } else {
                $insert_errors[] = 'Không lấy được ID sau khi insert';
            }
        }

        if ( 0 === $sources_added && ! empty( $insert_errors ) ) {
            wp_send_json_error( [
                'message'       => 'Không thêm được nguồn: ' . implode( '; ', array_unique( $insert_errors ) ),
                'sources_added' => 0,
                'errors'        => $insert_errors,
            ] );
        }

        wp_send_json_success( [
            'message'       => sprintf( 'Added %d URL(s).', $sources_added ),
            'sources_added' => $sources_added,
            'source_ids'    => $source_ids,
            'total_urls'    => count( $urls_to_crawl ),
            'errors'        => $insert_errors,
        ] );
    }
    
    /**
     * AJAX: Process website (crawl and extract content)
     */
    public function ajax_process_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
                'source_name' => mb_substr( $result['title'] ?: $source->source_url, 0, 255 ),
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
        $chunks_created  = 0;
        $is_legal_scope  = ( (int) $source->character_id === 0 );
        foreach ($chunks as $index => $chunk_text) {
            $chunk_meta = [
                'url'          => $source->source_url,
                'title'        => $result['title'],
                'chunk_number' => $index + 1,
                'total_chunks' => count($chunks),
            ];
            // Mark as legal scope so build-batch can find it (character_id=0)
            if ( $is_legal_scope ) {
                $chunk_meta['scope'] = 'legal';
            }
            $wpdb->insert(
                $wpdb->prefix . 'bizcity_knowledge_chunks',
                [
                    'source_id'    => $source_id,
                    'character_id' => (int) $source->character_id,
                    'content'      => $chunk_text,
                    'chunk_index'  => $index,
                    'metadata'     => json_encode( $chunk_meta ),
                    'created_at'   => current_time('mysql')
                ],
                ['%d', '%d', '%s', '%d', '%s', '%s']
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
            'message'      => sprintf('Crawled successfully! Created %d chunks.', $chunks_created),
            'chunks_count' => $chunks_created,
            'word_count'   => $result['word_count'],
            'title'        => $result['title'],
            'crawl_source' => $result['source'] ?? 'direct',
            'attempts'     => $result['attempts'] ?? [],
        ]);
    }
    
    /**
     * AJAX: Delete website source
     */
    public function ajax_delete_website() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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
        
        if (!self::can_manage()) {
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

    /**
     * PHASE 0.34.2 — Attach one or more notebooks to a character (1:N via kg_notebooks.character_id).
     * Source: character-edit.php Notebooks tab form.
     */
    public function admin_post_character_notebook_attach() {
        $cid = isset( $_POST['character_id'] ) ? (int) $_POST['character_id'] : 0;
        if ( ! $cid || ! self::can_manage() ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'bk_char_nb_' . $cid );

        $ids = isset( $_POST['notebook_ids'] ) ? (array) $_POST['notebook_ids'] : [];
        $ids = array_filter( array_map( 'intval', $ids ) );

        if ( $ids && class_exists( 'BizCity_KG_Database' ) ) {
            global $wpdb;
            $tbl = BizCity_KG_Database::instance()->tbl_notebooks();
            foreach ( $ids as $nb_id ) {
                $wpdb->update( $tbl, [ 'character_id' => $cid ], [ 'id' => $nb_id ] );
            }
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    /**
     * PHASE 0.34.2 — Detach a single notebook from a character (sets character_id NULL).
     */
    public function admin_post_character_notebook_detach() {
        $cid = isset( $_POST['character_id'] ) ? (int) $_POST['character_id'] : 0;
        $nb  = isset( $_POST['notebook_id'] )  ? (int) $_POST['notebook_id']  : 0;
        if ( ! $cid || ! $nb || ! self::can_manage() ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'bk_char_nb_' . $cid );

        if ( class_exists( 'BizCity_KG_Database' ) ) {
            global $wpdb;
            $tbl = BizCity_KG_Database::instance()->tbl_notebooks();
            $wpdb->update( $tbl, [ 'character_id' => null ], [ 'id' => $nb, 'character_id' => $cid ] );
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
}

