<?php
/**
 * BizCity Tool Image — Admin Menu
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_menu_page(
        'Image AI',
        'Image AI',
        'manage_options',
        'bztimg-dashboard',
        'bztimg_admin_dashboard',
        'dashicons-format-image',
        58
    );

    add_submenu_page(
        'bztimg-dashboard',
        'Image Editor',
        'Editor',
        'edit_posts',
        'bztimg-editor',
        'bztimg_editor_page'
    );

    add_submenu_page(
        'bztimg-dashboard',
        'Image Templates',
        'Templates',
        'manage_options',
        'bztimg-templates',
        'bztimg_admin_templates_page'
    );

    add_submenu_page(
        'bztimg-dashboard',
        'Template Categories',
        'Categories',
        'manage_options',
        'bztimg-categories',
        'bztimg_admin_categories_page'
    );

    /* Phase 3.4 — Character Studio submenu */
    add_submenu_page(
        'bztimg-dashboard',
        'Character Studio',
        '🧑 Nhân Vật AI',
        'manage_options',
        'bztimg-character-studio',
        'bztimg_character_studio_page'
    );

    /* Note: "Quản lý Mẫu Người" is auto-registered by CPT bztimg_model
       with show_in_menu => 'bztimg-dashboard' in class-model-manager.php */
} );

function bztimg_admin_dashboard() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_jobs';
    $total = 0;
    $done  = 0;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
    }
    ?>
    <div class="wrap">
        <h1>🎨 BizCity Image AI — Dashboard</h1>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-editor' ) ); ?>" class="button button-primary">🖌️ Mở Image Editor</a></p>
        <div style="display:flex;gap:16px;margin-top:16px;">
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>📊 Thống kê</h3>
                <p>Tổng ảnh đã tạo: <strong><?php echo esc_html( $total ); ?></strong></p>
                <p>Hoàn thành: <strong><?php echo esc_html( $done ); ?></strong></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>⚙️ Cài đặt nhanh</h3>
                <p>Model mặc định: <strong><?php echo esc_html( get_option( 'bztimg_default_model', 'flux-pro' ) ); ?></strong></p>
                <p>API Key: <?php echo get_option( 'bztimg_api_key' ) ? '✅ Đã cấu hình' : '❌ Chưa cấu hình'; ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>🔗 Liên kết</h3>
                <p><a href="<?php echo esc_url( home_url( '/tool-image/' ) ); ?>" target="_blank">Mở Profile View →</a></p>
            </div>
        </div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════
   EDITOR PAGE — Design Editor (Vite build)
   ═══════════════════════════════════════════════ */
function bztimg_editor_page() {
    $build_dir = BZTIMG_DIR . 'design-editor-build/';
    $build_url = BZTIMG_URL . 'design-editor-build/';

    if ( ! file_exists( $build_dir . 'index.html' ) ) {
        echo '<div class="wrap"><h1>Design Editor</h1>';
        echo '<p>Editor chưa được build. Chạy <code>cd design-editor && npm run build</code></p></div>';
        return;
    }

    $editor_url = $build_url . 'index.html';
    $rest_url   = esc_url_raw( rest_url( 'bztool-image/v1' ) );
    $nonce      = wp_create_nonce( 'wp_rest' );
    $user_id    = get_current_user_id();
    $site_url   = site_url();
    ?>
    <iframe
        id="bztimg-editor-frame"
        src="<?php echo esc_url( $editor_url ); ?>"
        style="position:fixed;inset:0;z-index:99999;width:100%;height:100%;border:0;background:#fff;"
        allow="clipboard-read; clipboard-write"
    ></iframe>
    <script>
    (function(){
        var frame = document.getElementById('bztimg-editor-frame');
        // Send WP config to editor once it signals ready
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'bztimg:ready') {
                frame.contentWindow.postMessage({
                    type: 'bztimg:init',
                    payload: {
                        restUrl:   <?php echo wp_json_encode( $rest_url ); ?>,
                        nonce:     <?php echo wp_json_encode( $nonce ); ?>,
                        userId:    <?php echo (int) $user_id; ?>,
                        siteUrl:   <?php echo wp_json_encode( $site_url ); ?>,
                        projectId: <?php echo isset($_GET['project_id']) ? (int) $_GET['project_id'] : 'null'; ?>
                    }
                }, '*');
            }
        });
    })();
    </script>
    <?php
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'bztimg-editor' ) === false ) return;

    /* Hide WP admin chrome so the editor goes full-screen */
    echo '<style>#wpcontent{padding:0!important}#wpbody-content{padding-bottom:0!important}#adminmenumain,#wpadminbar,#wpfooter,.notice,.updated,.error:not(#bztimg-editor-frame){display:none!important}</style>';
} );

/* ═══════════════════════════════════════════════
   TEMPLATES ADMIN PAGE
   ═══════════════════════════════════════════════ */
function bztimg_admin_templates_page() {
    $view_file = BZTIMG_DIR . 'admin/views/admin-templates.php';
    if ( file_exists( $view_file ) ) {
        include $view_file;
    } else {
        echo '<div class="wrap"><h1>Templates</h1><p>View file not found.</p></div>';
    }
}

function bztimg_admin_categories_page() {
    $view_file = BZTIMG_DIR . 'admin/views/admin-categories.php';
    if ( file_exists( $view_file ) ) {
        include $view_file;
    } else {
        echo '<div class="wrap"><h1>Categories</h1><p>View file not found.</p></div>';
    }
}

/* ═══════════════════════════════════════════════
   CHARACTER STUDIO ADMIN PAGE (Phase 3.4)
   ═══════════════════════════════════════════════ */
function bztimg_character_studio_page() {
    $models_count = wp_count_posts( BizCity_Model_Manager::CPT );
    $published    = intval( $models_count->publish ?? 0 );
    $piapi_ready  = class_exists( 'BizCity_Video_API' ) && BizCity_Video_API::is_ready();
    $router_ready = class_exists( 'BizCity_Router_Proxy' ) && BizCity_Router_Proxy::is_ready();
    ?>
    <div class="wrap">
        <h1>🧑 Character Studio — Nhân Vật AI</h1>
        <p class="description">Thử quần áo, phụ kiện và tùy chỉnh khuôn mặt trên mẫu người AI.</p>

        <!-- Status -->
        <div style="display:flex;gap:12px;margin:16px 0;">
            <div style="background:#fff;padding:16px 20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3 style="margin-top:0;">📊 Trạng thái</h3>
                <p>Mẫu người đã publish: <strong><?php echo esc_html( $published ); ?></strong>
                    &nbsp;<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=bztimg_model' ) ); ?>" class="button button-small">Quản lý →</a>
                </p>
                <p>LLM Router: <?php echo $router_ready ? '<span style="color:#22c55e;">✅ Ready</span>' : '<span style="color:#ef4444;">❌ Chưa cấu hình</span>'; ?></p>
                <p>PiAPI (Faceswap): <?php echo $piapi_ready ? '<span style="color:#22c55e;">✅ Ready</span>' : '<span style="color:#f59e0b;">⚠️ Chưa cấu hình — <a href="' . esc_url( network_admin_url( 'settings.php?page=bizcity-openrouter' ) ) . '">Cài đặt PiAPI key (Network Admin)</a></span>'; ?></p>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3 style="margin-top:0;">🚀 Bước tiếp theo</h3>
                <?php if ( $published < 1 ): ?>
                    <p>1. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=bztimg_model' ) ); ?>" class="button button-primary">Thêm Mẫu Người đầu tiên</a></p>
                    <p class="description">Upload ảnh toàn thân 3:4 (768×1024+) cho mỗi mẫu.</p>
                <?php else: ?>
                    <p>✅ Đã có <?php echo $published; ?> mẫu người. Frontend sẽ hiển thị tại <code>/tool-image/?tab=character-studio</code></p>
                    <p><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=bztimg_model' ) ); ?>" class="button">+ Thêm mẫu mới</a></p>
                <?php endif; ?>
            </div>
            <div style="background:#fff;padding:16px 20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3 style="margin-top:0;">🔗 API Endpoints</h3>
                <p><code>GET /wp-json/bztool-image/v1/character-models</code></p>
                <p><code>POST /wp-json/video/router/v1/faceswap</code></p>
                <p><code>POST /wp-json/video/router/v1/kling-vto</code></p>
                <p><code>GET /wp-json/video/router/v1/task/{id}</code></p>
            </div>
        </div>

        <!-- Quick Preview of models -->
        <?php if ( $published > 0 ):
            $models = BizCity_Model_Manager::get_models();
        ?>
        <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;margin-top:8px;">
            <h3 style="margin-top:0;">👀 Mẫu người hiện có</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(120px, 1fr));gap:12px;">
                <?php foreach ( $models as $m ): ?>
                <div style="text-align:center;">
                    <?php if ( $m['image_url'] ): ?>
                        <img src="<?php echo esc_url( $m['thumb_url'] ?: $m['image_url'] ); ?>"
                             style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;" />
                    <?php else: ?>
                        <div style="width:100%;aspect-ratio:3/4;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:24px;">📷</div>
                    <?php endif; ?>
                    <p style="margin:4px 0 0;font-size:12px;"><strong><?php echo esc_html( $m['name'] ); ?></strong></p>
                    <p style="margin:0;font-size:11px;color:#6b7280;">
                        <?php echo esc_html( $m['gender'] === 'female' ? '👩 Nữ' : ( $m['gender'] === 'male' ? '👨 Nam' : '⚧' ) ); ?>
                        · <?php echo esc_html( $m['age_group'] ); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/* Enqueue admin assets for template pages */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'bztimg-templates' ) === false && strpos( $hook, 'bztimg-categories' ) === false ) return;

    wp_enqueue_media();
    wp_enqueue_style(  'bztimg-admin-templates', BZTIMG_URL . 'assets/admin-templates.css', array(), BZTIMG_VERSION );
    wp_enqueue_script( 'bztimg-admin-templates', BZTIMG_URL . 'assets/admin-templates.js', array( 'jquery', 'jquery-ui-sortable' ), BZTIMG_VERSION, true );
    wp_localize_script( 'bztimg-admin-templates', 'BZTIMG_TPL', array(
        'rest_url'     => rest_url( 'bztool-image/v1/' ),
        'nonce'        => wp_create_nonce( 'wp_rest' ),
        'import_nonce' => wp_create_nonce( 'bztimg_import' ),
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'models'       => class_exists( 'BizCity_Tool_Image' ) ? array_keys( BizCity_Tool_Image::MODELS ) : array(),
    ) );
} );

/* Enqueue wp.media for Model CPT edit screen (image picker in meta box) */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== BizCity_Model_Manager::CPT ) return;
    wp_enqueue_media();
} );

/* ═══════════════════════════════════════════════
   AJAX: Import Templates (bypasses REST firewall)
   ═══════════════════════════════════════════════ */
add_action( 'wp_ajax_bztimg_import', function() {
    check_ajax_referer( 'bztimg_import', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }
    $json_text = wp_unslash( $_POST['json_data'] ?? '' );
    $data = json_decode( $json_text, true );
    if ( ! is_array( $data ) || empty( $data ) ) {
        wp_send_json_error( array( 'message' => 'JSON không hợp lệ' ), 400 );
    }
    // bztimg_template single-schema → import directly (not wrapped in array)
    if ( isset( $data['_meta']['schema'] ) && $data['_meta']['schema'] === 'bztimg_template' ) {
        $result = BizCity_Template_Manager::import( $data, true );
        // Process library items from uploaded data
        $lib_count = bztimg_import_library_items( $data );
        error_log( '[bztimg] Import: main=' . ( is_wp_error( $result ) ? 'error:' . $result->get_error_message() : $result ) . ' lib=' . $lib_count );
        $result = ( is_wp_error( $result ) ? 0 : (int) $result ) + $lib_count;
    } else {
        $result = BizCity_Template_Manager::import( $data );
    }
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    wp_send_json_success( array( 'imported' => $result ) );
} );
