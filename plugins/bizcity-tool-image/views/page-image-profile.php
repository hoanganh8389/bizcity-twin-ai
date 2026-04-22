<?php
/**
 * BizCity Tool Image — Agent Profile View: /tool-image/
 *
 * Type B: 4 Tabs — Create (+ Prompt Library), Monitor, Chat, Settings
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();

// Stats
$stats       = [ 'total' => 0, 'done' => 0, 'active' => 0 ];
$recent_jobs = [];
if ( $is_logged_in ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_jobs';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
        $stats['total']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
        $stats['done']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'completed'", $user_id ) );
        $stats['active'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'processing'", $user_id ) );

        $recent_jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, model, size, style, status, image_url, attachment_id, ref_image, error_message, created_at
             FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
            $user_id
        ), ARRAY_A );
    }
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'templates';
$allowed_tabs = [ 'templates', 'create', 'monitor', 'chat', 'editor', 'settings' ];
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) $active_tab = 'templates';

$cfg_model    = get_option( 'bztimg_default_model', 'flux-pro' );
$cfg_size     = get_option( 'bztimg_default_size', '1024x1024' );
$cfg_api_key  = get_option( 'bztimg_api_key', '' );
$cfg_endpoint = get_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
$cfg_openai   = get_option( 'bztimg_openai_key', '' );
$is_admin     = current_user_can( 'manage_options' );

// Prompt library
$prompt_lib = class_exists( 'BizCity_Tool_Image' ) ? BizCity_Tool_Image::get_prompt_library() : [];

// Template library (Phase 3)
$tpl_categories = class_exists( 'BizCity_Template_Category_Manager' ) ? BizCity_Template_Category_Manager::get_all( array( 'status' => 'active' ) ) : [];
$tpl_featured = class_exists( 'BizCity_Template_Manager' ) ? BizCity_Template_Manager::get_featured( 20 ) : [];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Image AI — Tạo ảnh AI</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f9fafb;font-family:system-ui,-apple-system,sans-serif;color:#1a1a2e;line-height:1.5;min-height:100vh;}

.bti-app{max-width:100%;padding:0 0 72px;position:relative;}

/* ── Bottom Nav ── */
.bti-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:#fff;border-top:1px solid #e5e7eb;z-index:100;padding:6px 0 env(safe-area-inset-bottom, 4px);}
.bti-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;text-decoration:none;color:#9ca3af;font-size:10px;font-weight:600;transition:color .2s;cursor:pointer;border:none;background:none;}
.bti-nav-item.active{color:#8b5cf6;}
.bti-nav-icon{font-size:20px;}

/* ── Tab ── */
.bti-tab{display:none;}
.bti-tab.active{display:block;}

/* ── Hero ── */
.bti-hero{background:linear-gradient(135deg,#7c3aed 0%,#8b5cf6 40%,#a78bfa 70%,#c4b5fd 100%);padding:24px 16px;color:#fff;position:relative;overflow:hidden;}
.bti-hero::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:rgba(255,255,255,0.08);border-radius:50%;}
.bti-hero-icon{font-size:36px;margin-bottom:4px;}
.bti-hero h2{font-size:20px;font-weight:800;margin-bottom:2px;}
.bti-hero p{opacity:0.9;font-size:12px;}
.bti-hero-stats{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;}
.bti-hero-stat{background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;}

/* ── Card ── */
.bti-card{background:#fff;border-radius:16px;padding:20px 16px;margin:12px 12px 0;box-shadow:0 1px 4px rgba(0,0,0,0.04);}
.bti-card h3{font-size:16px;font-weight:700;margin-bottom:12px;}

/* ── Photo Zone ── */
.bti-photo-zone{display:block;border:2px dashed #d1d5db;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px;position:relative;overflow:hidden;}
.bti-photo-zone:hover{border-color:#8b5cf6;}
.bti-photo-placeholder{color:#9ca3af;}
.bti-photo-placeholder span{font-size:36px;display:block;margin-bottom:4px;}
.bti-photo-placeholder p{font-size:13px;}
.bti-photo-placeholder small{font-size:11px;color:#d1d5db;}
#bti-photo-preview{display:none;}
#bti-photo-preview img{max-width:100%;max-height:180px;border-radius:8px;object-fit:contain;}

/* ── Fields ── */
.bti-field{margin-bottom:12px;}
.bti-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
.bti-field textarea,.bti-field input,.bti-field select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#1a1a2e;}
.bti-field textarea:focus,.bti-field input:focus,.bti-field select:focus{outline:none;border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,0.1);}

/* ── Pills ── */
.bti-pill-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bti-pill{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;background:#fff;}
.bti-pill:has(input:checked){border-color:#8b5cf6;background:#f5f3ff;color:#8b5cf6;}
.bti-pill input{display:none;}

/* ── Buttons ── */
.bti-btn{width:100%;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
.bti-btn-primary{background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;}
.bti-btn-primary:hover{opacity:0.9;transform:translateY(-1px);}
.bti-btn-primary:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.bti-btn-secondary{background:#fff;border:1.5px solid #e5e7eb;color:#374151;padding:8px 14px;width:auto;font-size:12px;border-radius:8px;}
.bti-btn-secondary:hover{border-color:#8b5cf6;color:#8b5cf6;}

/* ── Status ── */
.bti-status{margin-top:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600;display:none;}
.bti-status.success{display:block;background:#dcfce7;color:#166534;}
.bti-status.error{display:block;background:#fef2f2;color:#991b1b;}
.bti-status.loading{display:block;background:#ede9fe;color:#5b21b6;}

/* ── Result ── */
.bti-result{display:none;margin-top:12px;border-radius:12px;overflow:hidden;background:#fff;border:1px solid #e5e7eb;}
.bti-result.show{display:block;}
.bti-result img{width:100%;max-height:400px;object-fit:contain;background:#f9fafb;}
.bti-result-info{padding:12px;font-size:12px;}
.bti-result-actions{display:flex;gap:6px;padding:0 12px 12px;flex-wrap:wrap;}

/* ── Prompt Library ── */
.bti-lib{margin:12px 12px 0;}
.bti-lib h3{font-size:15px;font-weight:700;margin-bottom:10px;padding:0 4px;}
.bti-lib-cats{display:flex;gap:6px;overflow-x:auto;padding:0 4px 8px;-webkit-overflow-scrolling:touch;}
.bti-lib-cats::-webkit-scrollbar{height:0;}
.bti-lib-cat{flex-shrink:0;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:#f3f4f6;color:#6b7280;border:1.5px solid transparent;transition:all .2s;white-space:nowrap;}
.bti-lib-cat.active{background:#f5f3ff;color:#8b5cf6;border-color:#8b5cf6;}
.bti-lib-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:4px;max-height:300px;overflow-y:auto;}
.bti-lib-item{padding:10px;border:1px solid #f3f4f6;border-radius:10px;cursor:pointer;transition:all .2s;background:#fff;}
.bti-lib-item:hover{border-color:#8b5cf6;background:#f5f3ff;}
.bti-lib-item h4{font-size:12px;font-weight:600;margin-bottom:2px;}
.bti-lib-item p{font-size:10px;color:#9ca3af;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}

/* ── Monitor ── */
.bti-section{padding:12px;}
.bti-section-title{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
.bti-job-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.bti-job{border:1px solid #f3f4f6;border-radius:12px;background:#fff;overflow:hidden;}
.bti-job-img{width:100%;aspect-ratio:1;object-fit:cover;background:#f9fafb;display:block;}
.bti-job-img-placeholder{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#f3f4f6;font-size:36px;color:#d1d5db;}
.bti-job-body{padding:8px;}
.bti-job-prompt{font-size:11px;color:#374151;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:4px;}
.bti-job-meta{display:flex;gap:4px;align-items:center;font-size:10px;color:#9ca3af;flex-wrap:wrap;}
.bti-job-status{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;}
.st-completed{background:#dcfce7;color:#166534;}
.st-processing{background:#ede9fe;color:#5b21b6;}
.st-failed{background:#fef2f2;color:#991b1b;}
.bti-job-actions{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;}
.bti-job-actions button,.bti-job-actions a{padding:4px 8px;border-radius:6px;font-size:10px;font-weight:600;cursor:pointer;border:1px solid #e5e7eb;background:#fff;color:#374151;text-decoration:none;transition:all .15s;}
.bti-job-actions button:hover,.bti-job-actions a:hover{border-color:#8b5cf6;color:#8b5cf6;}

/* ── Chat Commands ── */
.bti-cmd{padding:14px;border:1px solid #f3f4f6;border-radius:12px;cursor:pointer;transition:all .2s;background:#fff;margin-bottom:8px;display:flex;align-items:center;gap:12px;}
.bti-cmd:hover{border-color:#8b5cf6;background:#f5f3ff;}
.bti-cmd.primary{border-color:#8b5cf6;background:#f5f3ff;}
.bti-cmd-icon{font-size:24px;flex-shrink:0;}
.bti-cmd-text h4{font-size:13px;font-weight:700;margin-bottom:1px;}
.bti-cmd-text p{font-size:11px;color:#9ca3af;}

/* ── Settings ── */
.bti-settings-group{margin-bottom:16px;}
.bti-settings-group h4{font-size:13px;font-weight:700;margin-bottom:8px;color:#374151;display:flex;align-items:center;gap:6px;}
.bti-settings-row{margin-bottom:10px;}
.bti-settings-row label{display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;}
.bti-settings-row input,.bti-settings-row select{width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;}
.bti-settings-info{margin-top:16px;padding:12px;background:#f3f4f6;border-radius:10px;font-size:11px;color:#6b7280;}

/* ── Tip Cards ── */
.bti-tips{display:flex;flex-direction:column;gap:6px;margin-top:10px;}
.bti-tip{padding:10px 14px;background:#f5f3ff;border-radius:10px;font-size:12px;cursor:pointer;transition:background .2s;}
.bti-tip:hover{background:#ede9fe;}

/* ── Editor tab ── */
#bti-editor-wrapper{height:calc(100vh - 60px);position:relative;overflow:hidden;background:#fff;}
#bti-editor-wrapper #root{height:100%;width:100%;}
.bti-tab#tab-editor.active{padding:0;}
.bti-tab#tab-editor .bti-hero{display:none;}

/* ── Loading spinner ── */
@keyframes bti-spin{to{transform:rotate(360deg)}}
.bti-spinner{display:inline-block;width:16px;height:16px;border:2px solid #e5e7eb;border-top-color:#8b5cf6;border-radius:50%;animation:bti-spin .6s linear infinite;vertical-align:middle;margin-right:6px;}

/* ── Template Browser (Phase 3) ── */
.bti-tpl-cats{display:flex;gap:6px;overflow-x:auto;padding:0 12px 8px;-webkit-overflow-scrolling:touch;margin-top:12px;}
.bti-tpl-cats::-webkit-scrollbar{height:0;}
.bti-tpl-cat{flex-shrink:0;padding:8px 16px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:#f3f4f6;color:#6b7280;border:1.5px solid transparent;transition:all .2s;white-space:nowrap;}
.bti-tpl-cat.active{background:#f5f3ff;color:#8b5cf6;border-color:#8b5cf6;}

.bti-tpl-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:12px;transition:opacity .2s;}
@media (min-width:640px){.bti-tpl-grid{grid-template-columns:repeat(3,1fr);}}
@media (min-width:960px){.bti-tpl-grid{grid-template-columns:repeat(4,1fr);}}
.bti-tpl-card{background:#fff;border:1.5px solid #f3f4f6;border-radius:12px;overflow:hidden;cursor:pointer;transition:all .2s;position:relative;}
.bti-tpl-card:hover{border-color:#8b5cf6;transform:translateY(-2px);box-shadow:0 4px 12px rgba(139,92,246,0.12);}
.bti-tpl-card-img{width:100%;aspect-ratio:1;object-fit:cover;background:#f9fafb;display:block;}
.bti-tpl-card-img-ph{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f5f3ff,#ede9fe);font-size:40px;}
.bti-tpl-card-body{padding:8px 10px;}
.bti-tpl-card-body h4{font-size:12px;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bti-tpl-card-body p{font-size:10px;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bti-tpl-badge{position:absolute;top:6px;right:6px;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;color:#fff;}
.bti-tpl-featured{position:absolute;top:6px;left:6px;font-size:14px;}
.bti-tpl-loadmore{text-align:center;padding:16px;}

/* Template detail modal */
.bti-tpl-modal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:flex-end;justify-content:center;}
.bti-tpl-modal.open{display:flex;}
.bti-tpl-modal-content{background:#fff;border-radius:20px 20px 0 0;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;padding:20px 16px env(safe-area-inset-bottom,16px);animation:bti-slide-up .3s ease;}
@keyframes bti-slide-up{from{transform:translateY(100%)}to{transform:translateY(0)}}
.bti-tpl-modal-close{display:flex;justify-content:center;margin-bottom:12px;}
.bti-tpl-modal-close span{width:40px;height:4px;background:#d1d5db;border-radius:2px;cursor:pointer;}
.bti-tpl-modal-header{display:flex;gap:12px;margin-bottom:16px;}
.bti-tpl-modal-header img{width:100px;height:100px;border-radius:10px;object-fit:cover;}
.bti-tpl-modal-header .info h3{font-size:16px;font-weight:700;}
.bti-tpl-modal-header .info p{font-size:12px;color:#6b7280;margin-top:4px;}
.bti-tpl-form-fields{margin-bottom:16px;}
.bti-tpl-form-field{margin-bottom:12px;}
.bti-tpl-form-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
.bti-tpl-form-field input,.bti-tpl-form-field textarea,.bti-tpl-form-field select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;}
.bti-tpl-form-field .card-radio-group{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;}
.bti-tpl-form-field .card-radio{padding:10px;border:1.5px solid #e5e7eb;border-radius:10px;cursor:pointer;text-align:center;font-size:12px;transition:all .2s;}
.bti-tpl-form-field .card-radio.selected{border-color:#8b5cf6;background:#f5f3ff;color:#8b5cf6;}
.bti-tpl-form-field .card-radio .cr-icon{font-size:20px;margin-bottom:4px;}
.bti-tpl-prompt-preview{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-size:11px;color:#6b7280;margin-bottom:12px;font-family:monospace;white-space:pre-wrap;}
.bti-tpl-size-pills{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bti-tpl-size-pill{padding:6px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:11px;cursor:pointer;transition:all .2s;}
.bti-tpl-size-pill.selected{border-color:#8b5cf6;background:#f5f3ff;color:#8b5cf6;}
/* Inline Template Form (no modal) */
.bti-tpl-inline-form{display:flex;gap:24px;padding:8px 0;}
.bti-inline-left{flex:0 0 360px;max-width:360px;}
.bti-inline-right{flex:1;min-width:0;}
.bti-inline-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
.bti-inline-gallery-card{border-radius:12px;overflow:hidden;cursor:pointer;border:2px solid #e5e7eb;background:#fff;transition:all .2s;}
.bti-inline-gallery-card:hover{border-color:#a78bfa;box-shadow:0 2px 8px rgba(139,92,246,.15);}
.bti-inline-gallery-card.selected{border-color:#22c55e;background:#f0fdf4;}
.bti-inline-gallery-card img{width:100%;aspect-ratio:1;object-fit:cover;}
.bti-inline-gallery-card .card-label{padding:6px 8px;font-size:11px;font-weight:500;color:#374151;line-height:1.3;}
.bti-inline-gallery-card .card-tag{display:inline-block;font-size:9px;background:#f3f4f6;color:#6b7280;padding:1px 6px;border-radius:4px;margin:0 8px 6px;}
.bti-inline-gallery-card.selected .card-label{color:#16a34a;font-weight:700;}
@media(max-width:768px){.bti-tpl-inline-form{flex-direction:column;}.bti-inline-left{flex:none;max-width:none;}.bti-inline-gallery-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));}}
</style>
</head>
<body>
<div class="bti-app">

<!-- ═══════════ TAB 0: TEMPLATES ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'templates' ? 'active' : ''; ?>" id="tab-templates">
    <!--
    <div class="bti-hero">
        <div class="bti-hero-icon">📸</div>
        <h2>Studio ảnh sản phẩm</h2>
        <p>Chọn template có sẵn → Điền thông tin → AI tạo ảnh</p>
        <div class="bti-hero-stats">
            <div class="bti-hero-stat">📁 <?php echo count( $tpl_categories ); ?> chủ đề</div>
            <div class="bti-hero-stat">⭐ <?php echo count( $tpl_featured ); ?> nổi bật</div>
        </div>
    </div>-->

    <!-- Category tabs -->
    <div class="bti-tpl-cats">
        <span class="bti-tpl-cat active" data-category="all">Tất cả</span>
        <?php foreach ( $tpl_categories as $cat ) : ?>
            <span class="bti-tpl-cat" data-category="<?php echo esc_attr( $cat['slug'] ); ?>">
                <?php echo esc_html( $cat['icon_emoji'] . ' ' . $cat['name'] ); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <!-- Template Grid -->
    <div class="bti-tpl-grid" id="bti-tpl-grid">
        <div style="grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af;">
            <span class="bti-spinner"></span> Đang tải templates...
        </div>
    </div>

    <div class="bti-tpl-loadmore" id="bti-tpl-loadmore" style="display:none;">
        <button class="bti-btn-secondary" onclick="btiTplLoadMore()">Xem thêm →</button>
    </div>
</div>

<!-- Template Detail Modal -->
<div class="bti-tpl-modal" id="bti-tpl-modal">
    <div class="bti-tpl-modal-content" id="bti-tpl-modal-body">
        <div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>
        <!-- filled by JS -->
    </div>
</div>

<!-- ═══════════ TAB 1: TẠO ẢNH ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'create' ? 'active' : ''; ?>" id="tab-create">

    <div class="bti-hero">
        <div class="bti-hero-icon">🎨</div>
        <h2>Image AI</h2>
        <p>Tạo ảnh AI chuyên nghiệp — FLUX.2 · Gemini · Seedream · GPT-5</p>
        <div class="bti-hero-stats">
            <div class="bti-hero-stat">🖼️ <?php echo esc_html( $stats['total'] ); ?> ảnh</div>
            <div class="bti-hero-stat">✅ <?php echo esc_html( $stats['done'] ); ?> xong</div>
            <?php if ( $stats['active'] > 0 ) : ?>
            <div class="bti-hero-stat">⏳ <?php echo esc_html( $stats['active'] ); ?> đang tạo</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bti-card">
        <h3>🎨 Tạo ảnh mới</h3>

        <!-- Upload ảnh tham chiếu -->
        <label class="bti-photo-zone" id="bti-photo-zone">
            <input type="file" id="bti-photo-input" accept="image/*" style="display:none">
            <div class="bti-photo-placeholder" id="bti-photo-placeholder">
                <span>📷</span>
                <p>Ảnh tham chiếu (tùy chọn)</p>
                <small>Kéo thả hoặc nhấn để chọn ảnh</small>
            </div>
            <div id="bti-photo-preview">
                <img id="bti-photo-img" src="" alt="">
                <p style="font-size:11px;color:#6b7280;margin-top:4px;">Nhấn để đổi ảnh</p>
            </div>
        </label>

        <!-- Prompt -->
        <div class="bti-field">
            <label>✍️ Mô tả ảnh (prompt)</label>
            <textarea id="bti-prompt" rows="3" placeholder="Mô tả chi tiết ảnh bạn muốn tạo..."></textarea>
        </div>

        <!-- Model pills -->
        <div class="bti-field">
            <label>🤖 Model AI</label>
            <div class="bti-pill-row" id="bti-models">
                <label class="bti-pill"><input type="radio" name="model" value="flux-pro" <?php echo $cfg_model === 'flux-pro' ? 'checked' : ''; ?>> 🔥 FLUX.2 Pro</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-flex" <?php echo $cfg_model === 'flux-flex' ? 'checked' : ''; ?>> ⚡ FLUX.2 Flex</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-max" <?php echo $cfg_model === 'flux-max' ? 'checked' : ''; ?>> 💎 FLUX.2 Max</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-klein" <?php echo $cfg_model === 'flux-klein' ? 'checked' : ''; ?>> ⚡ FLUX.2 Klein</label>
                <label class="bti-pill"><input type="radio" name="model" value="gemini-image" <?php echo $cfg_model === 'gemini-image' ? 'checked' : ''; ?>> ✨ Gemini Flash</label>
                <label class="bti-pill"><input type="radio" name="model" value="gemini-pro" <?php echo $cfg_model === 'gemini-pro' ? 'checked' : ''; ?>> ✨ Gemini Pro</label>
                <label class="bti-pill"><input type="radio" name="model" value="seedream" <?php echo $cfg_model === 'seedream' ? 'checked' : ''; ?>> 🎭 Seedream</label>
                <label class="bti-pill"><input type="radio" name="model" value="gpt-image" <?php echo $cfg_model === 'gpt-image' ? 'checked' : ''; ?>> 🧠 GPT-5 Image</label>
                <label class="bti-pill"><input type="radio" name="model" value="gpt-image-mini" <?php echo $cfg_model === 'gpt-image-mini' ? 'checked' : ''; ?>> 🧠 GPT-5 Mini</label>
            </div>
        </div>

        <!-- Size pills -->
        <div class="bti-field">
            <label>📐 Kích thước</label>
            <div class="bti-pill-row" id="bti-sizes">
                <label class="bti-pill"><input type="radio" name="size" value="1024x1024" <?php echo $cfg_size === '1024x1024' ? 'checked' : ''; ?>> 1:1 Vuông</label>
                <label class="bti-pill"><input type="radio" name="size" value="1024x1536" <?php echo $cfg_size === '1024x1536' ? 'checked' : ''; ?>> 2:3 Dọc</label>
                <label class="bti-pill"><input type="radio" name="size" value="1536x1024" <?php echo $cfg_size === '1536x1024' ? 'checked' : ''; ?>> 3:2 Ngang</label>
                <label class="bti-pill"><input type="radio" name="size" value="768x1344" <?php echo $cfg_size === '768x1344' ? 'checked' : ''; ?>> 9:16 Story</label>
                <label class="bti-pill"><input type="radio" name="size" value="1344x768" <?php echo $cfg_size === '1344x768' ? 'checked' : ''; ?>> 16:9 Landscape</label>
            </div>
        </div>

        <!-- Style pills -->
        <div class="bti-field">
            <label>🎭 Phong cách</label>
            <div class="bti-pill-row">
                <label class="bti-pill"><input type="radio" name="style" value="auto" checked> Tự động</label>
                <label class="bti-pill"><input type="radio" name="style" value="photorealistic"> Chân thực</label>
                <label class="bti-pill"><input type="radio" name="style" value="artistic"> Nghệ thuật</label>
                <label class="bti-pill"><input type="radio" name="style" value="anime"> Anime</label>
                <label class="bti-pill"><input type="radio" name="style" value="illustration"> Minh họa</label>
            </div>
        </div>

        <!-- Submit -->
        <button class="bti-btn bti-btn-primary" id="bti-submit" onclick="btiGenerate()">
            🎨 Tạo ảnh
        </button>

        <div class="bti-status" id="bti-create-status"></div>

        <!-- Result -->
        <div class="bti-result" id="bti-result">
            <img id="bti-result-img" src="" alt="AI Generated Image">
            <div class="bti-result-info" id="bti-result-info"></div>
            <div class="bti-result-actions" id="bti-result-actions"></div>
        </div>
    </div>

    <!-- ── PROMPT LIBRARY ── -->
    <div class="bti-lib">
        <h3>📚 Thư viện Prompt</h3>
        <div class="bti-lib-cats" id="bti-lib-cats">
            <?php $first = true; foreach ( $prompt_lib as $cat_key => $cat ) : ?>
            <div class="bti-lib-cat <?php echo $first ? 'active' : ''; ?>" data-cat="<?php echo esc_attr( $cat_key ); ?>">
                <?php echo esc_html( $cat['icon'] . ' ' . $cat['label'] ); ?>
            </div>
            <?php $first = false; endforeach; ?>
        </div>

        <?php foreach ( $prompt_lib as $cat_key => $cat ) : ?>
        <div class="bti-lib-grid" id="lib-<?php echo esc_attr( $cat_key ); ?>" style="<?php echo $cat_key === array_key_first( $prompt_lib ) ? '' : 'display:none'; ?>">
            <?php foreach ( $cat['prompts'] as $p ) : ?>
            <div class="bti-lib-item" data-prompt="<?php echo esc_attr( $p['prompt'] ); ?>">
                <h4><?php echo esc_html( $p['title'] ); ?></h4>
                <p><?php echo esc_html( mb_strimwidth( $p['prompt'], 0, 80, '...' ) ); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- ═══════════ TAB 2: MONITOR ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'monitor' ? 'active' : ''; ?>" id="tab-monitor">

    <div class="bti-hero">
        <div class="bti-hero-icon">📊</div>
        <h2>Lịch sử tạo ảnh</h2>
        <p>Xem, tải về, up Media, chia sẻ ảnh đã tạo</p>
        <div class="bti-hero-stats" id="bti-monitor-stats">
            <div class="bti-hero-stat">🖼️ <span id="ms-total"><?php echo esc_html( $stats['total'] ); ?></span> ảnh</div>
            <div class="bti-hero-stat">✅ <span id="ms-done"><?php echo esc_html( $stats['done'] ); ?></span> xong</div>
            <div class="bti-hero-stat">⏳ <span id="ms-active"><?php echo esc_html( $stats['active'] ); ?></span> đang tạo</div>
        </div>
    </div>

    <div class="bti-section">
        <div class="bti-section-title">
            <span>🖼️ Ảnh gần đây</span>
            <button class="bti-btn-secondary" onclick="btiPollJobs()">🔄 Làm mới</button>
        </div>

        <div class="bti-job-grid" id="bti-job-grid">
            <?php if ( empty( $recent_jobs ) ) : ?>
                <p style="grid-column:1/-1;text-align:center;padding:30px;color:#9ca3af;font-size:13px;">📭 Chưa tạo ảnh nào. Qua tab Tạo ảnh để bắt đầu!</p>
            <?php else : ?>
                <?php foreach ( $recent_jobs as $job ) : ?>
                <div class="bti-job" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
                    <?php if ( ! empty( $job['image_url'] ) ) : ?>
                    <img class="bti-job-img" src="<?php echo esc_url( $job['image_url'] ); ?>" alt="" loading="lazy">
                    <?php else : ?>
                    <div class="bti-job-img-placeholder"><?php echo $job['status'] === 'processing' ? '⏳' : '❌'; ?></div>
                    <?php endif; ?>
                    <div class="bti-job-body">
                        <div class="bti-job-prompt"><?php echo esc_html( mb_strimwidth( $job['prompt'], 0, 80, '...' ) ); ?></div>
                        <div class="bti-job-meta">
                            <span class="bti-job-status st-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span>
                            <span><?php echo esc_html( $job['model'] ); ?></span>
                            <span><?php echo esc_html( $job['size'] ); ?></span>
                            <span><?php echo esc_html( wp_date( 'd/m H:i', strtotime( $job['created_at'] ) ) ); ?></span>
                        </div>
                        <?php if ( $job['status'] === 'completed' && ! empty( $job['image_url'] ) ) : ?>
                        <div class="bti-job-actions">
                            <a href="<?php echo esc_url( $job['image_url'] ); ?>" target="_blank">👁️ Xem</a>
                            <a href="<?php echo esc_url( $job['image_url'] ); ?>" download>⬇️ Tải</a>
                            <?php if ( empty( $job['attachment_id'] ) || $job['attachment_id'] == 0 ) : ?>
                            <button onclick="btiUploadMedia(<?php echo esc_attr( $job['id'] ); ?>,'<?php echo esc_url( $job['image_url'] ); ?>')">💾 Media</button>
                            <?php else : ?>
                            <span style="font-size:10px;color:#166534;">✅ Saved</span>
                            <?php endif; ?>
                            <button onclick="btiShareImage('<?php echo esc_url( $job['image_url'] ); ?>')">🔗 Share</button>
                        </div>
                        <?php endif; ?>
                        <?php if ( $job['status'] === 'failed' && ! empty( $job['error_message'] ) ) : ?>
                        <div style="font-size:10px;color:#991b1b;margin-top:4px;"><?php echo esc_html( mb_strimwidth( $job['error_message'], 0, 100, '...' ) ); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ═══════════ TAB 3: CHAT ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'chat' ? 'active' : ''; ?>" id="tab-chat">

    <div class="bti-hero">
        <div class="bti-hero-icon">💬</div>
        <h2>Chat AI — Tạo ảnh</h2>
        <p>Gửi lệnh qua chat để tạo ảnh AI</p>
    </div>

    <div style="padding:12px;">
        <!-- Primary -->
        <div class="bti-cmd primary" data-msg="Tạo ảnh AI từ prompt">
            <div class="bti-cmd-icon">🎨</div>
            <div class="bti-cmd-text">
                <h4>Tạo ảnh AI</h4>
                <p>Mô tả prompt → AI tạo ảnh chuyên nghiệp (FLUX.2/Gemini/GPT-5)</p>
            </div>
        </div>
        <!-- Secondary -->
        <div class="bti-cmd" data-msg="Tạo ảnh sản phẩm cho bán hàng">
            <div class="bti-cmd-icon">📦</div>
            <div class="bti-cmd-text">
                <h4>Ảnh sản phẩm</h4>
                <p>Tạo ảnh sản phẩm nền trắng, lifestyle, studio cho e-commerce</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Xem danh sách ảnh đã tạo gần đây">
            <div class="bti-cmd-icon">🖼️</div>
            <div class="bti-cmd-text">
                <h4>Xem ảnh đã tạo</h4>
                <p>Liệt kê các ảnh AI gần đây với link xem và tải</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh chân dung nghệ thuật, phong cách cinematic">
            <div class="bti-cmd-icon">👤</div>
            <div class="bti-cmd-text">
                <h4>Chân dung nghệ thuật</h4>
                <p>Ảnh portrait phong cách cinematic, studio, vintage</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh phong cảnh hoàng hôn bãi biển Việt Nam">
            <div class="bti-cmd-icon">🌄</div>
            <div class="bti-cmd-text">
                <h4>Phong cảnh</h4>
                <p>Chụp bãi biển, núi non, phố cổ, ruộng bậc thang</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh thumbnail YouTube bắt mắt">
            <div class="bti-cmd-icon">📱</div>
            <div class="bti-cmd-text">
                <h4>Social Media</h4>
                <p>Thumbnail YouTube, Instagram Story, Facebook Post</p>
            </div>
        </div>

        <div class="bti-tips">
            <h4 style="font-size:13px;font-weight:700;margin-bottom:2px;">💡 Gợi ý prompt</h4>
            <div class="bti-tip" data-msg="Tạo ảnh cô gái áo dài đi trong rừng tre, phong cách chụp film Kodak Portra">💡 "Cô gái áo dài đi trong rừng tre, phong cách Kodak Portra"</div>
            <div class="bti-tip" data-msg="Tạo ảnh sản phẩm túi xách da trên nền đá cẩm thạch, studio lighting">💡 "Sản phẩm túi xách da trên nền đá cẩm thạch, studio"</div>
            <div class="bti-tip" data-msg="Tạo ảnh thiết kế nội thất phòng khách Scandinavian, tông gỗ ấm">💡 "Nội thất phòng khách Scandinavian, tông gỗ ấm"</div>
            <div class="bti-tip" data-msg="Tạo ảnh game character chiến binh fantasy, concept art chất lượng cao">💡 "Game character chiến binh fantasy, concept art"</div>
            <div class="bti-tip" data-msg="Tạo ảnh flat lay món ăn Việt Nam, food photography chuyên nghiệp">💡 "Flat lay món ăn Việt Nam, food photography"</div>
        </div>
    </div>

</div>

<!-- ═══════════ TAB 4: CÀI ĐẶT ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">

    <div class="bti-hero">
        <div class="bti-hero-icon">⚙️</div>
        <h2>Cài đặt</h2>
        <p>API, model mặc định và tùy chỉnh</p>
    </div>

    <div class="bti-card">
        <?php if ( $is_admin ) : ?>
        <div class="bti-settings-group">
            <h4>🔑 API Configuration (Admin only)</h4>
            <div class="bti-settings-row">
                <label>OpenRouter API Key (FLUX.2 / Gemini / Seedream / GPT-5) — hoặc dùng key OpenRouter chung</label>
                <input type="password" id="bti-cfg-api-key" value="<?php echo esc_attr( $cfg_api_key ); ?>" placeholder="sk-or-...">
            </div>
            <div class="bti-settings-row">
                <label>API Endpoint</label>
                <input type="url" id="bti-cfg-endpoint" value="<?php echo esc_attr( $cfg_endpoint ); ?>" placeholder="https://openrouter.ai/api/v1">
            </div>
            <div class="bti-settings-row">
                <label>OpenAI API Key (GPT-Image trực tiếp, tùy chọn)</label>
                <input type="password" id="bti-cfg-openai" value="<?php echo esc_attr( $cfg_openai ); ?>" placeholder="sk-...">
            </div>
        </div>
        <?php endif; ?>

        <div class="bti-settings-group">
            <h4>🎛️ Mặc định tạo ảnh</h4>
            <div class="bti-settings-row">
                <label>Model mặc định</label>
                <select id="bti-cfg-model">
                    <option value="flux-pro" <?php selected( $cfg_model, 'flux-pro' ); ?>>🔥 FLUX.2 Pro (chất lượng cao)</option>
                    <option value="flux-flex" <?php selected( $cfg_model, 'flux-flex' ); ?>>⚡ FLUX.2 Flex (linh hoạt)</option>
                    <option value="flux-max" <?php selected( $cfg_model, 'flux-max' ); ?>>💎 FLUX.2 Max (premium)</option>
                    <option value="flux-klein" <?php selected( $cfg_model, 'flux-klein' ); ?>>⚡ FLUX.2 Klein (nhanh)</option>
                    <option value="gemini-image" <?php selected( $cfg_model, 'gemini-image' ); ?>>✨ Gemini Flash Image</option>
                    <option value="gemini-pro" <?php selected( $cfg_model, 'gemini-pro' ); ?>>✨ Gemini Pro Image</option>
                    <option value="seedream" <?php selected( $cfg_model, 'seedream' ); ?>>🎭 Seedream 4.5</option>
                    <option value="gpt-image" <?php selected( $cfg_model, 'gpt-image' ); ?>>🧠 GPT-5 Image</option>
                    <option value="gpt-image-mini" <?php selected( $cfg_model, 'gpt-image-mini' ); ?>>🧠 GPT-5 Image Mini</option>
                </select>
            </div>
            <div class="bti-settings-row">
                <label>Kích thước mặc định</label>
                <select id="bti-cfg-size">
                    <option value="1024x1024" <?php selected( $cfg_size, '1024x1024' ); ?>>1:1 Vuông (1024×1024)</option>
                    <option value="1024x1536" <?php selected( $cfg_size, '1024x1536' ); ?>>2:3 Dọc (1024×1536)</option>
                    <option value="1536x1024" <?php selected( $cfg_size, '1536x1024' ); ?>>3:2 Ngang (1536×1024)</option>
                    <option value="768x1344" <?php selected( $cfg_size, '768x1344' ); ?>>9:16 Story (768×1344)</option>
                    <option value="1344x768" <?php selected( $cfg_size, '1344x768' ); ?>>16:9 Landscape (1344×768)</option>
                </select>
            </div>
        </div>

        <button class="bti-btn bti-btn-primary" onclick="btiSaveSettings()">💾 Lưu cài đặt</button>
        <div class="bti-status" id="bti-settings-status"></div>

        <div class="bti-settings-info">
            ℹ️ Plugin: BizCity Tool Image v<?php echo esc_html( BZTIMG_VERSION ); ?><br>
            Models: FLUX.2 Pro, Flex, Max, Klein · Gemini Flash/Pro · Seedream 4.5 · GPT-5 Image<br>
            Gateway: OpenRouter<br>
            Ảnh đã tạo: <?php echo esc_html( $stats['total'] ); ?>
        </div>
    </div>

</div>

<!-- ═══════════ TAB 5: EDITOR ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'editor' ? 'active' : ''; ?>" id="tab-editor">
    <div id="bti-editor-wrapper">
        <?php
        $editor_nonce = wp_create_nonce( 'wp_rest' );
        $editor_params = array(
            'restUrl'   => rest_url( 'bztool-image/v1/' ),
            'nonce'     => $editor_nonce,
            'userId'    => $user_id,
            'siteUrl'   => home_url(),
            'logoUrl'   => 'https://media.bizcity.vn/uploads/sites/1258/2026/04/bizcanva.png',
            'pluginUrl' => BZTIMG_URL . 'design-editor-build/',
        );
        $editor_base_url = BZTIMG_URL . 'design-editor-build/index.html';
        ?>
        <!-- Write nonce to localStorage BEFORE iframe loads — same origin shares localStorage -->
        <script>
        try{localStorage.setItem('bztimg_nonce','<?php echo esc_js( $editor_nonce ); ?>');}catch(e){}
        // Build iframe URL with projectId from localStorage (persist across F5)
        (function(){
            var params = <?php echo wp_json_encode( $editor_params ); ?>;
            var pid = localStorage.getItem('bztimg_projectId');
            if (pid) params.projectId = pid;
            var qs = Object.keys(params).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(params[k]); }).join('&');
            var iframe = document.getElementById('bti-editor-frame');
            if (iframe) iframe.src = <?php echo wp_json_encode( $editor_base_url ); ?> + '?' + qs;

            // Listen for project save events from editor iframe
            window.addEventListener('message', function(ev){
                if (ev.data && ev.data.type === 'bztimg:saved' && ev.data.payload && ev.data.payload.id) {
                    localStorage.setItem('bztimg_projectId', ev.data.payload.id);
                }
                if (ev.data && ev.data.type === 'bztimg:removed') {
                    localStorage.removeItem('bztimg_projectId');
                }
            });
        })();
        </script>
        <iframe
            id="bti-editor-frame"
            src="about:blank"
            style="width:100%;height:100%;border:0;display:block;"
            allow="clipboard-read; clipboard-write"
        ></iframe>
    </div>

    <?php /* Phase 3.7 — AI Image Dialog (frontend editor) */
    $dialog_partial = BZTIMG_DIR . 'views/partial-ai-image-dialog.php';
    if ( file_exists( $dialog_partial ) ) {
        include $dialog_partial;
    }
    ?>
    <link rel="stylesheet" href="<?php echo esc_url( BZTIMG_URL . 'assets/ai-image-dialog.css' ); ?>?v=<?php echo esc_attr( BZTIMG_VERSION ); ?>">
    <script>
    var BZTIMG_AI = {
        restUrl:    <?php echo wp_json_encode( rest_url( 'image-editor/v1' ) ); ?>,
        toolUrl:    <?php echo wp_json_encode( rest_url( 'bztool-image/v1' ) ); ?>,
        nonce:      <?php echo wp_json_encode( $editor_nonce ); ?>,
        ajaxUrl:    <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
        ajaxNonce:  <?php echo wp_json_encode( wp_create_nonce( 'bztimg_nonce' ) ); ?>,
        editorId:   'bti-editor-frame',
        hasWpMedia: false
    };
    </script>
    <script src="<?php echo esc_url( BZTIMG_URL . 'assets/ai-image-dialog.js' ); ?>?v=<?php echo esc_attr( BZTIMG_VERSION ); ?>"></script>
</div>

<!-- ═══════════ BOTTOM NAV ═══════════ -->
<nav class="bti-nav">
    <button class="bti-nav-item <?php echo $active_tab === 'templates' ? 'active' : ''; ?>" data-tab="templates">
        <span class="bti-nav-icon">📸</span>
        <span>Sản phẩm</span>
    </button>
    <a class="bti-nav-item" href="/profile-studio/" style="text-decoration:none;">
        <span class="bti-nav-icon">🎭</span>
        <span>Chân dung</span>
    </a>
    <button class="bti-nav-item <?php echo $active_tab === 'create' ? 'active' : ''; ?>" data-tab="create">
        <span class="bti-nav-icon">🎨</span>
        <span>Tạo ảnh</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'monitor' ? 'active' : ''; ?>" data-tab="monitor">
        <span class="bti-nav-icon">📊</span>
        <span>Lịch sử</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'chat' ? 'active' : ''; ?>" data-tab="chat">
        <span class="bti-nav-icon">💬</span>
        <span>Chat</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'editor' ? 'active' : ''; ?>" data-tab="editor">
        <span class="bti-nav-icon">🖌️</span>
        <span>Editor</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
        <span class="bti-nav-icon">⚙️</span>
        <span>Cài đặt</span>
    </button>
</nav>

</div><!-- .bti-app -->

<script>
/* ═══════════════════════════════════════════════
   GLOBAL CONFIG
   ═══════════════════════════════════════════════ */
var BTI = {
    ajax: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
    nonce: '<?php echo esc_attr( wp_create_nonce( 'bztimg_nonce' ) ); ?>',
    restUrl: '<?php echo esc_url( rest_url( 'bztool-image/v1/' ) ); ?>',
    restNonce: '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>',
    photoUrl: '',
    editorBase: '<?php echo esc_url( BZTIMG_URL . 'assets/editor/' ); ?>',
};

/* ═══════════════════════════════════════════════
   TAB NAVIGATION
   ═══════════════════════════════════════════════ */
document.querySelectorAll('.bti-nav-item').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tab = this.dataset.tab;
        document.querySelectorAll('.bti-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.bti-nav-item').forEach(function(b) { b.classList.remove('active'); });
        document.getElementById('tab-' + tab).classList.add('active');
        this.classList.add('active');
        if (tab === 'monitor') btiPollJobs();
        if (tab === 'templates' && !window._tplLoaded) btiTplLoad();
        if (tab === 'editor') {
            document.querySelector('.bti-nav').style.display = 'none';
            document.querySelector('.bti-app').style.paddingBottom = '0';
        } else {
            document.querySelector('.bti-nav').style.display = 'flex';
            document.querySelector('.bti-app').style.paddingBottom = '72px';
        }
    });
});

/* Auto-load if editor tab is active on page load */
if (document.getElementById('tab-editor') && document.getElementById('tab-editor').classList.contains('active')) {
    document.querySelector('.bti-nav').style.display = 'none';
    document.querySelector('.bti-app').style.paddingBottom = '0';
}

/* ═══════════════════════════════════════════════
   EDITOR IFRAME — send auth via postMessage
   ═══════════════════════════════════════════════ */
window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.type === 'bztimg:ready') {
        var frame = document.getElementById('bti-editor-frame');
        if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage({
                type: 'bztimg:init',
                payload: {
                    restUrl: BTI.restUrl,
                    nonce:   BTI.restNonce,
                    userId:  <?php echo (int) $user_id; ?>,
                    siteUrl: '<?php echo esc_js( home_url() ); ?>',
                    projectId: null
                }
            }, '*');
        }
    }
});

/* ═══════════════════════════════════════════════
   PHOTO UPLOAD
   ═══════════════════════════════════════════════ */
document.getElementById('bti-photo-input').addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (!file) return;

    // Preview
    var reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('bti-photo-img').src = ev.target.result;
        document.getElementById('bti-photo-preview').style.display = 'block';
        document.getElementById('bti-photo-placeholder').style.display = 'none';
    };
    reader.readAsDataURL(file);

    // Upload
    var fd = new FormData();
    fd.append('action', 'bztimg_upload_photo');
    fd.append('nonce', BTI.nonce);
    fd.append('photo', file);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                BTI.photoUrl = res.data.url;
            }
        });
});

/* ═══════════════════════════════════════════════
   GENERATE IMAGE
   ═══════════════════════════════════════════════ */
function btiGenerate() {
    var prompt = document.getElementById('bti-prompt').value.trim();
    if (!prompt && !BTI.photoUrl) {
        btiShowStatus('bti-create-status', 'Vui lòng nhập mô tả ảnh hoặc upload ảnh tham chiếu.', 'error');
        return;
    }

    var model = document.querySelector('input[name="model"]:checked');
    var size  = document.querySelector('input[name="size"]:checked');
    var style = document.querySelector('input[name="style"]:checked');

    var btn = document.getElementById('bti-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="bti-spinner"></span> Đang tạo ảnh...';
    btiShowStatus('bti-create-status', '⏳ Đang gửi yêu cầu tạo ảnh...', 'loading');

    var fd = new FormData();
    fd.append('action', 'bztimg_generate');
    fd.append('nonce', BTI.nonce);
    fd.append('prompt', prompt);
    fd.append('image_url', BTI.photoUrl || '');
    fd.append('model', model ? model.value : 'flux-pro');
    fd.append('size', size ? size.value : '1024x1024');
    fd.append('style', style ? style.value : 'auto');

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = '🎨 Tạo ảnh';

            if (res.success && res.data) {
                var d = res.data.data || res.data;
                btiShowStatus('bti-create-status', '✅ Tạo ảnh thành công!', 'success');

                // Show result
                var imgUrl = d.image_url || d.url || '';
                if (imgUrl) {
                    document.getElementById('bti-result-img').src = imgUrl;
                    document.getElementById('bti-result-info').innerHTML =
                        '<strong>' + (d.model || '') + '</strong> · ' + (d.size || '') + ' · Job #' + (d.job_id || '');
                    document.getElementById('bti-result-actions').innerHTML =
                        '<a href="' + imgUrl + '" target="_blank" class="bti-btn-secondary">👁️ Xem</a>' +
                        '<a href="' + imgUrl + '" download class="bti-btn-secondary">⬇️ Tải về</a>' +
                        '<button class="bti-btn-secondary" onclick="btiShareImage(\'' + imgUrl + '\')">🔗 Share</button>';
                    document.getElementById('bti-result').classList.add('show');
                }
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : 'Lỗi tạo ảnh.';
                btiShowStatus('bti-create-status', msg, 'error');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = '🎨 Tạo ảnh';
            btiShowStatus('bti-create-status', 'Lỗi kết nối: ' + err.message, 'error');
        });
}

/* ═══════════════════════════════════════════════
   POLL JOBS (Monitor tab)
   ═══════════════════════════════════════════════ */
function btiPollJobs() {
    var fd = new FormData();
    fd.append('action', 'bztimg_poll_jobs');
    fd.append('nonce', BTI.nonce);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) return;
            var d = res.data;

            // Update stats
            if (d.stats) {
                var el;
                el = document.getElementById('ms-total');  if(el) el.textContent = d.stats.total;
                el = document.getElementById('ms-done');   if(el) el.textContent = d.stats.done;
                el = document.getElementById('ms-active'); if(el) el.textContent = d.stats.active;
            }

            // Render jobs
            var grid = document.getElementById('bti-job-grid');
            if (!d.jobs || d.jobs.length === 0) {
                grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;padding:30px;color:#9ca3af;font-size:13px;">📭 Chưa tạo ảnh nào.</p>';
                return;
            }

            var html = '';
            d.jobs.forEach(function(job) {
                var hasImg = job.status === 'completed' && job.image_url;
                html += '<div class="bti-job" data-job-id="' + job.id + '">';
                if (hasImg) {
                    html += '<img class="bti-job-img" src="' + job.image_url + '" alt="" loading="lazy">';
                } else {
                    html += '<div class="bti-job-img-placeholder">' + (job.status === 'processing' ? '⏳' : '❌') + '</div>';
                }
                html += '<div class="bti-job-body">';
                html += '<div class="bti-job-prompt">' + escHtml(job.prompt || '').substring(0, 80) + '</div>';
                html += '<div class="bti-job-meta">';
                html += '<span class="bti-job-status st-' + job.status + '">' + ucfirst(job.status) + '</span>';
                html += '<span>' + escHtml(job.model) + '</span>';
                html += '<span>' + escHtml(job.size) + '</span>';
                html += '</div>';
                if (hasImg) {
                    html += '<div class="bti-job-actions">';
                    html += '<a href="' + job.image_url + '" target="_blank">👁️ Xem</a>';
                    html += '<a href="' + job.image_url + '" download>⬇️ Tải</a>';
                    if (!job.attachment_id || job.attachment_id == 0) {
                        html += '<button onclick="btiUploadMedia(' + job.id + ',\'' + job.image_url + '\')">💾 Media</button>';
                    } else {
                        html += '<span style="font-size:10px;color:#166534;">✅ Saved</span>';
                    }
                    html += '<button onclick="btiShareImage(\'' + job.image_url + '\')">🔗 Share</button>';
                    html += '</div>';
                }
                if (job.status === 'failed' && job.error_message) {
                    html += '<div style="font-size:10px;color:#991b1b;margin-top:4px;">' + escHtml(job.error_message).substring(0, 100) + '</div>';
                }
                html += '</div></div>';
            });
            grid.innerHTML = html;
        });
}

/* ═══════════════════════════════════════════════
   UPLOAD TO MEDIA
   ═══════════════════════════════════════════════ */
function btiUploadMedia(jobId, imageUrl) {
    var fd = new FormData();
    fd.append('action', 'bztimg_upload_to_media');
    fd.append('nonce', BTI.nonce);
    fd.append('job_id', jobId);
    fd.append('image_url', imageUrl);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                btiPollJobs(); // Refresh
            } else {
                alert(res.data && res.data.message ? res.data.message : 'Lỗi upload.');
            }
        });
}

/* ═══════════════════════════════════════════════
   SHARE
   ═══════════════════════════════════════════════ */
function btiShareImage(url) {
    if (navigator.share) {
        navigator.share({ title: 'AI Generated Image', url: url });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            alert('Đã copy link ảnh!');
        });
    } else {
        prompt('Copy link:', url);
    }
}

/* ═══════════════════════════════════════════════
   SAVE SETTINGS
   ═══════════════════════════════════════════════ */
function btiSaveSettings() {
    var fd = new FormData();
    fd.append('action', 'bztimg_save_settings');
    fd.append('nonce', BTI.nonce);
    fd.append('default_model', document.getElementById('bti-cfg-model').value);
    fd.append('default_size', document.getElementById('bti-cfg-size').value);

    var apiKeyEl = document.getElementById('bti-cfg-api-key');
    if (apiKeyEl) fd.append('api_key', apiKeyEl.value);
    var endpointEl = document.getElementById('bti-cfg-endpoint');
    if (endpointEl) fd.append('api_endpoint', endpointEl.value);
    var openaiEl = document.getElementById('bti-cfg-openai');
    if (openaiEl) fd.append('openai_key', openaiEl.value);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                btiShowStatus('bti-settings-status', '✅ Đã lưu cài đặt!', 'success');
            } else {
                btiShowStatus('bti-settings-status', '❌ Lỗi lưu cài đặt.', 'error');
            }
        });
}

/* ═══════════════════════════════════════════════
   PROMPT LIBRARY
   ═══════════════════════════════════════════════ */
document.querySelectorAll('.bti-lib-cat').forEach(function(cat) {
    cat.addEventListener('click', function() {
        document.querySelectorAll('.bti-lib-cat').forEach(function(c) { c.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.bti-lib-grid').forEach(function(g) { g.style.display = 'none'; });
        var grid = document.getElementById('lib-' + this.dataset.cat);
        if (grid) grid.style.display = 'grid';
    });
});

document.querySelectorAll('.bti-lib-item').forEach(function(item) {
    item.addEventListener('click', function() {
        document.getElementById('bti-prompt').value = this.dataset.prompt;
        document.getElementById('bti-prompt').focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});

/* ═══════════════════════════════════════════════
   POSTMESSAGE TO PARENT (Chat tab)
   ═══════════════════════════════════════════════ */
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var msg = this.dataset.msg;
        if (!msg) return;

        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'bizcity_agent_command',
                message: msg,
                source: 'tool-image'
            }, '*');
        } else {
            // Standalone — copy to clipboard
            if (navigator.clipboard) {
                navigator.clipboard.writeText(msg);
                alert('Đã copy: ' + msg);
            }
        }
    });
});

/* ═══════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════ */
function btiShowStatus(id, msg, type) {
    var el = document.getElementById(id);
    if (!el) return;
    el.className = 'bti-status ' + type;
    el.textContent = msg;
    if (type === 'success' || type === 'error') {
        setTimeout(function() { el.className = 'bti-status'; }, 5000);
    }
}

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s || ''));
    return d.innerHTML;
}

function ucfirst(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

/* ═══════════════════════════════════════════════
   TEMPLATE BROWSER (Phase 3)
   ═══════════════════════════════════════════════ */
var _tplPage = 1;
var _tplCategory = '';
var _tplSearch = '';
var _tplTotal = 0;
window._tplLoaded = false;

function btiTplLoad(append) {
    if (!append) {
        _tplPage = 1;
        var grid = document.getElementById('bti-tpl-grid');
        grid.style.display = ''; /* restore CSS grid */
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af;"><span class="bti-spinner"></span> Đang tải...</div>';
        window._formContainer = null;
    }

    var url = BTI.restUrl + 'templates?per_page=12&page=' + _tplPage + '&status=active';
    if (_tplCategory) url += '&category=' + encodeURIComponent(_tplCategory);
    if (_tplSearch) url += '&search=' + encodeURIComponent(_tplSearch);

    fetch(url, { headers: { 'X-WP-Nonce': BTI.restNonce } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            window._tplLoaded = true;
            var templates = data.templates || [];
            _tplTotal = data.total || 0;

            if (!append) {
                document.getElementById('bti-tpl-grid').innerHTML = '';
            }

            if (templates.length === 0 && !append) {
                document.getElementById('bti-tpl-grid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af;">Chưa có template nào.</div>';
                document.getElementById('bti-tpl-loadmore').style.display = 'none';
                return;
            }

            var grid = document.getElementById('bti-tpl-grid');
            templates.forEach(function(tpl) {
                var card = document.createElement('div');
                card.className = 'bti-tpl-card';
                card.onclick = function() { btiTplOpenModal(tpl); };

                var imgHtml = tpl.thumbnail_url
                    ? '<img class="bti-tpl-card-img" src="' + escHtml(tpl.thumbnail_url) + '" alt="" loading="lazy" />'
                    : '<div class="bti-tpl-card-img-ph">🎨</div>';

                var badgeHtml = tpl.badge_text
                    ? '<div class="bti-tpl-badge" style="background:' + escHtml(tpl.badge_color || '#3b82f6') + ';">' + escHtml(tpl.badge_text) + '</div>'
                    : '';

                var featuredHtml = tpl.is_featured == 1 ? '<div class="bti-tpl-featured">⭐</div>' : '';

                card.innerHTML = imgHtml + badgeHtml + featuredHtml +
                    '<div class="bti-tpl-card-body">' +
                        '<h4>' + escHtml(tpl.title) + '</h4>' +
                        '<p>' + escHtml(tpl.description || tpl.recommended_model) + '</p>' +
                    '</div>';

                grid.appendChild(card);
            });

            // Show/hide load more
            var loaded = grid.querySelectorAll('.bti-tpl-card').length;
            var loadMoreEl = document.getElementById('bti-tpl-loadmore');
            loadMoreEl.style.display = loaded < _tplTotal ? 'block' : 'none';
            var loadMoreBtn = loadMoreEl.querySelector('button');
            if (loadMoreBtn) { loadMoreBtn.disabled = false; loadMoreBtn.innerHTML = 'Xem thêm'; }
        })
        .catch(function(err) {
            console.error('Template load error:', err);
            document.getElementById('bti-tpl-grid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#ef4444;">Lỗi tải templates.</div>';
        });
}

function btiTplLoadMore() {
    var btn = document.querySelector('#bti-tpl-loadmore button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="bti-spinner"></span> Đang tải...'; }
    _tplPage++;
    btiTplLoad(true);
}

/* ── Inline Template Form (no modal) ── */
function btiTplTryInline(catSlug) {
    var grid = document.getElementById('bti-tpl-grid');
    grid.style.display = 'block';
    grid.innerHTML = '<div style="text-align:center;padding:40px;color:#9ca3af;"><span class="bti-spinner"></span> Đang tải...</div>';
    document.getElementById('bti-tpl-loadmore').style.display = 'none';

    fetch(BTI.restUrl + 'templates?category=' + encodeURIComponent(catSlug) + '&subcategory=product&status=active&per_page=1', {
        headers: { 'X-WP-Nonce': BTI.restNonce }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var tpl = data.templates && data.templates[0];
        if (tpl) {
            if (typeof tpl.form_fields === 'string') {
                try { tpl.form_fields = JSON.parse(tpl.form_fields); } catch(e) { tpl.form_fields = []; }
            }
            var hasCreationMode = Array.isArray(tpl.form_fields) && tpl.form_fields.some(function(f) { return f.slug === 'creation_mode'; });
            if (hasCreationMode) {
                btiTplRenderInline(tpl);
                return;
            }
        }
        /* Fallback: normal template grid */
        btiTplLoad();
    })
    .catch(function() { btiTplLoad(); });
}

function btiTplRenderInline(tpl) {
    var grid = document.getElementById('bti-tpl-grid');
    document.getElementById('bti-tpl-loadmore').style.display = 'none';
    var fields = Array.isArray(tpl.form_fields) ? tpl.form_fields : [];

    /* Classify fields: gallery fields → right panel, rest → left panel.
       Two-pass: model_picker always wins the right panel over card_radio. */
    var rightField = null;
    /* Pass 1: model_picker has highest priority for right panel */
    fields.forEach(function(f) { if (!rightField && f.type === 'model_picker') rightField = f; });
    /* Pass 2: if no model_picker, use first large card_radio */
    if (!rightField) {
        fields.forEach(function(f) {
            if (!rightField && f.type === 'card_radio' && f.slug !== 'creation_mode' && (f.options || []).length >= 4) rightField = f;
        });
    }
    var leftFields = fields.filter(function(f) { return f !== rightField; });

    /* ── LEFT panel: form controls ── */
    var leftHtml = '<h3 style="margin:0 0 4px;font-size:16px;font-weight:700;">' + escHtml(tpl.title) + '</h3>';
    leftHtml += '<p style="font-size:12px;color:#6b7280;margin:0 0 14px;">' + escHtml(tpl.description || '') + '</p>';

    leftFields.forEach(function(f) {
        if (f.type === 'heading') {
            leftHtml += '<h4 style="font-size:13px;font-weight:700;margin:10px 0 4px;">' + escHtml(f.label) + '</h4>';
            return;
        }
        var visAttr = '';
        if (f.visibility && f.visibility.creation_mode) {
            visAttr = ' data-vis-mode="' + escHtml(f.visibility.creation_mode) + '"';
        }
        var defaultVal = f.default || '';
        var inputHtml = '';
        switch (f.type) {
            case 'card_radio':
                var cards = '';
                (f.options || []).forEach(function(o, i) {
                    var isSel = defaultVal ? (o.value === defaultVal) : (o.recommended || i === 0);
                    cards += '<div class="card-radio' + (isSel ? ' selected' : '') + '" data-value="' + escHtml(o.value) + '" onclick="btiTplSelectCard(this,\'' + escHtml(f.slug) + '\')">' +
                        (o.icon ? '<div class="cr-icon">' + o.icon + '</div>' : '') +
                        '<div>' + escHtml(o.label) +
                        (o.description ? '<br><small style="color:#9ca3af;font-size:10px;">' + escHtml(o.description) + '</small>' : '') +
                        '</div></div>';
                });
                inputHtml = '<div class="card-radio-group" data-slug="' + escHtml(f.slug) + '">' + cards + '</div>';
                break;
            case 'image_upload':
                inputHtml = '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:16px;text-align:center;cursor:pointer;" onclick="btiTplUploadImage(this)" data-slug="' + escHtml(f.slug) + '">' +
                    '<span style="font-size:24px;">📷</span><br><small>' + escHtml(f.help || 'Click để upload ảnh') + '</small>' +
                    '<input type="hidden" class="tpl-img-url" /></div>';
                break;
            case 'textarea':
                inputHtml = '<textarea data-slug="' + escHtml(f.slug) + '" rows="2" placeholder="' + escHtml(f.placeholder || '') + '"></textarea>';
                break;
            case 'size_picker':
                var spPills = '';
                (f.options || []).forEach(function(o) {
                    var sel = o.recommended ? ' selected' : '';
                    spPills += '<span class="bti-tpl-size-pill' + sel + '" data-size="' + escHtml(o.value) + '" onclick="btiTplSelectSize(this)">' + (o.icon ? o.icon + ' ' : '') + escHtml(o.label) + '</span>';
                });
                inputHtml = '<div class="bti-tpl-size-pills" data-slug="' + escHtml(f.slug) + '">' + spPills + '</div>';
                break;
            case 'multi_reference_images':
                var riHtml = '';
                (f.image_roles || []).forEach(function(role) {
                    riHtml += '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:12px;text-align:center;cursor:pointer;flex:1;min-width:100px;" onclick="btiTplUploadImage(this)" data-slug="ref_' + escHtml(role.slug) + '">' +
                        '<span style="font-size:18px;">' + (role.icon || '📷') + '</span><br><small style="font-size:10px;">' + escHtml(role.label) + '</small>' +
                        '<input type="hidden" class="tpl-img-url" /></div>';
                });
                inputHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + riHtml + '</div>';
                break;
            default: /* text, number */
                var inputType = f.type === 'number' ? 'number' : 'text';
                inputHtml = '<input type="' + inputType + '" data-slug="' + escHtml(f.slug) + '" placeholder="' + escHtml(f.placeholder || '') + '" />';
        }
        var reqMark = f.required ? ' <span style="color:#ef4444;">*</span>' : '';
        var helpHtml = (f.help && f.type !== 'image_upload') ? '<small style="color:#9ca3af;display:block;margin-top:2px;">' + escHtml(f.help) + '</small>' : '';
        leftHtml += '<div class="bti-tpl-form-field"' + visAttr + '><label>' + escHtml(f.label) + reqMark + '</label>' + inputHtml + helpHtml + '</div>';
    });

    /* Default size pills if no size_picker */
    var hasSizePicker = fields.some(function(f) { return f.type === 'size_picker'; });
    if (!hasSizePicker) {
        var sizes = [['1024x1024','1:1 Vuông'],['1024x1536','2:3 Dọc'],['1536x1024','3:2 Ngang'],['768x1344','9:16 Story']];
        var sp = '';
        sizes.forEach(function(s) {
            var sel = s[0] === (tpl.recommended_size || '1024x1024') ? ' selected' : '';
            sp += '<span class="bti-tpl-size-pill' + sel + '" data-size="' + s[0] + '" onclick="btiTplSelectSize(this)">' + s[1] + '</span>';
        });
        leftHtml += '<div class="bti-tpl-form-field"><label>Kích thước</label><div class="bti-tpl-size-pills">' + sp + '</div></div>';
    }

    leftHtml += '<button class="bti-btn bti-btn-primary" style="width:100%;margin-top:12px;" onclick="btiTplGenerate(' + tpl.id + ')" id="bti-tpl-gen-btn">✨ Tạo Ảnh</button>';
    leftHtml += '<div id="bti-tpl-gen-status" class="bti-status" style="margin-top:8px;"></div>';
    leftHtml += '<div id="bti-tpl-gen-result" class="bti-result" style="margin-top:12px;"></div>';

    /* ── RIGHT panel: gallery / visual selector ── */
    var rightHtml = '';
    var _inlineModelSlug = null;
    var _inlineModelSelectedId = '';
    if (rightField) {
        rightHtml += '<h4 style="margin:0 0 10px;font-size:14px;font-weight:600;">' + escHtml(rightField.label) + '</h4>';
        if (rightField.help) rightHtml += '<p style="font-size:11px;color:#9ca3af;margin:0 0 8px;">' + escHtml(rightField.help) + '</p>';

        if (rightField.type === 'model_picker') {
            var preModelId = window._preSelectedModel ? window._preSelectedModel.id : '';
            window._preSelectedModel = null;
            _inlineModelSlug = rightField.slug;
            _inlineModelSelectedId = preModelId;
            rightHtml += '<input type="hidden" data-slug="' + escHtml(rightField.slug) + '" value="' + escHtml(preModelId) + '" />';
            rightHtml += '<div id="bti-model-gallery-' + escHtml(rightField.slug) + '" class="bti-inline-gallery-grid">' +
                '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af;"><span class="bti-spinner"></span> Đang tải mẫu người...</div></div>';
            if (rightField.allow_custom_upload) {
                rightHtml += '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:14px;text-align:center;cursor:pointer;margin-top:10px;" onclick="btiTplUploadImage(this)" data-slug="' + escHtml(rightField.slug) + '_custom">' +
                    '<span style="font-size:20px;">👤</span><br><small>' + escHtml(rightField.custom_upload_label || 'Upload ảnh mẫu riêng') + '</small>' +
                    '<input type="hidden" class="tpl-img-url" /></div>';
            }
        } else if (rightField.type === 'card_radio') {
            var defaultVal = rightField.default || '';
            rightHtml += '<div class="bti-inline-gallery-grid card-radio-group" data-slug="' + escHtml(rightField.slug) + '">';
            (rightField.options || []).forEach(function(o, i) {
                var isSel = defaultVal ? (o.value === defaultVal) : (o.recommended || i === 0);
                if (o.preview_url) {
                    /* Image-backed card: show thumbnail like model gallery */
                    rightHtml += '<div class="bti-inline-gallery-card card-radio' + (isSel ? ' selected' : '') + '" data-value="' + escHtml(o.value) + '" onclick="btiTplSelectCard(this,\'' + escHtml(rightField.slug) + '\')">' +
                        '<img src="' + escHtml(o.preview_url) + '" alt="" loading="lazy" />' +
                        '<div class="card-label">' + (isSel ? '✓ ' : '') + escHtml(o.label) + '</div>' +
                        '</div>';
                } else {
                    /* Icon-based card: existing rendering */
                    rightHtml += '<div class="bti-inline-gallery-card card-radio' + (isSel ? ' selected' : '') + '" data-value="' + escHtml(o.value) + '" onclick="btiTplSelectCard(this,\'' + escHtml(rightField.slug) + '\')">' +
                        '<div style="padding:16px 10px;text-align:center;">' +
                        (o.icon ? '<div style="font-size:28px;margin-bottom:4px;">' + o.icon + '</div>' : '') +
                        '<div class="card-label" style="font-size:12px;font-weight:600;">' + escHtml(o.label) + '</div>' +
                        (o.description ? '<div style="font-size:10px;color:#9ca3af;margin-top:2px;line-height:1.2;">' + escHtml(o.description) + '</div>' : '') +
                        '</div></div>';
                }
            });
            rightHtml += '</div>';
        }
    }

    /* ── Render 2-column layout ── */
    grid.style.display = 'block';
    grid.innerHTML = '<div class="bti-tpl-inline-form" id="bti-tpl-inline-body">' +
        '<div class="bti-inline-left">' + leftHtml + '</div>' +
        (rightHtml ? '<div class="bti-inline-right">' + rightHtml + '</div>' : '') +
    '</div>';

    window._currentTpl = tpl;
    window._formContainer = document.getElementById('bti-tpl-inline-body');

    /* Load model gallery async */
    if (_inlineModelSlug) {
        (function(slug, selId) {
            setTimeout(function() { btiLoadInlineModelGallery(slug, selId); }, 50);
        })(_inlineModelSlug, _inlineModelSelectedId);
    }

    btiTplToggleVisibility();
}

function btiLoadInlineModelGallery(fieldSlug, selectedId) {
    var container = document.getElementById('bti-model-gallery-' + fieldSlug);
    if (!container) return;
    fetch(BTI.restUrl + 'templates?subcategory=model&status=active&per_page=50', {
        headers: { 'X-WP-Nonce': BTI.restNonce }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var models = data.templates || [];
        if (!models.length) { container.innerHTML = '<small style="color:#9ca3af;">Chưa có mẫu người nào.</small>'; return; }
        container.innerHTML = '';
        models.forEach(function(m) {
            var isSel = m.slug === selectedId;
            var card = document.createElement('div');
            card.className = 'bti-inline-gallery-card' + (isSel ? ' selected' : '');
            card.dataset.modelSlug = m.slug;
            card.innerHTML = (m.thumbnail_url
                ? '<img src="' + escHtml(m.thumbnail_url) + '" alt="" loading="lazy" />'
                : '<div style="width:100%;aspect-ratio:1;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:32px;">👤</div>') +
                '<div class="card-label">' + (isSel ? '✓ ' : '') + escHtml(m.title) + '</div>';
            card.onclick = function() { btiSelectInlineModel(fieldSlug, m.slug, container); };
            container.appendChild(card);
        });
    })
    .catch(function() { container.innerHTML = '<small style="color:#ef4444;">Lỗi tải mẫu người.</small>'; });
}

function btiSelectInlineModel(fieldSlug, modelSlug, gallery) {
    var container = window._formContainer || document.getElementById('bti-tpl-inline-body');
    var hiddenInput = container.querySelector('input[data-slug="' + fieldSlug + '"]');
    if (hiddenInput) hiddenInput.value = modelSlug;
    gallery.querySelectorAll('.bti-inline-gallery-card').forEach(function(card) {
        var isSel = card.dataset.modelSlug === modelSlug;
        card.classList.toggle('selected', isSel);
        var label = card.querySelector('.card-label');
        if (label) {
            var name = label.textContent.replace(/^✓\s*/, '');
            label.textContent = (isSel ? '✓ ' : '') + name;
        }
    });
}

/* Category filter tabs */
document.querySelectorAll('.bti-tpl-cat').forEach(function(el) {
    el.addEventListener('click', function() {
        document.querySelectorAll('.bti-tpl-cat').forEach(function(c) { c.classList.remove('active'); });
        this.classList.add('active');
        _tplCategory = this.dataset.category === 'all' ? '' : this.dataset.category;
        _tplSearch = '';
        if (_tplCategory) {
            btiTplTryInline(_tplCategory);
        } else {
            btiTplLoad();
        }
    });
});



/* Auto-load templates on page load if tab is active */
if (document.getElementById('tab-templates') && document.getElementById('tab-templates').classList.contains('active')) {
    btiTplLoad();
}

/* ── Template Detail Modal ── */
function btiTplOpenModal(tpl) {
    var modal = document.getElementById('bti-tpl-modal');
    var body = document.getElementById('bti-tpl-modal-body');

    /* ── Parse form_fields if still a JSON string ── */
    if (typeof tpl.form_fields === 'string') {
        try { tpl.form_fields = JSON.parse(tpl.form_fields); } catch(e) { tpl.form_fields = []; }
    }

    /* ── Child row (model/clothing/accessory) → redirect to parent template ── */
    var ff = tpl.form_fields;
    var isChildRow = (!Array.isArray(ff) && ff && ff.parent_slug)
        || tpl.subcategory === 'model' || tpl.subcategory === 'clothing' || tpl.subcategory === 'accessory';
    if (isChildRow) {
        var parentSlug = (ff && !Array.isArray(ff)) ? (ff.parent_slug || '') : '';

        body.innerHTML = '<div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>' +
            '<div style="text-align:center;padding:60px 20px;"><span class="bti-spinner"></span><br><small style="color:#9ca3af;">Đang tải kịch bản...</small></div>';
        modal.classList.add('open');

        /* Pre-select info based on child type */
        var childSub = tpl.subcategory || '';
        if (childSub === 'model' || (ff && ff.model_description)) {
            window._preSelectedModel = {
                id: tpl.slug,
                name: tpl.title,
                thumbnail: tpl.thumbnail_url || '',
                description: tpl.description || tpl.prompt_template || ''
            };
        }
        if (childSub === 'clothing' || childSub === 'accessory' || (ff && (ff.clothing_name || ff.accessory_name))) {
            window._preSelectedItem = {
                id: tpl.slug,
                name: tpl.title,
                thumbnail: tpl.thumbnail_url || '',
                description: tpl.description || tpl.title || '',
                subcategory: childSub,
                clothing_category: (ff && ff.clothing_category) || '',
                accessory_type: (ff && ff.accessory_type) || ''
            };
        }

        var fetchUrl = parentSlug
            ? BTI.restUrl + 'templates?slug=' + encodeURIComponent(parentSlug) + '&status=active&per_page=1'
            : BTI.restUrl + 'templates?category_id=' + tpl.category_id + '&subcategory=product&status=active&per_page=1';

        fetch(fetchUrl, { headers: { 'X-WP-Nonce': BTI.restNonce } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var parent = data.templates && data.templates[0];
                if (parent) {
                    if (typeof parent.form_fields === 'string') {
                        try { parent.form_fields = JSON.parse(parent.form_fields); } catch(e) { parent.form_fields = []; }
                    }
                    btiTplOpenModal(parent);
                } else {
                    body.innerHTML = '<div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>' +
                        '<div style="text-align:center;padding:60px;color:#ef4444;">Không tìm thấy kịch bản gốc.</div>';
                }
            })
            .catch(function() {
                body.innerHTML = '<div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>' +
                    '<div style="text-align:center;padding:60px;color:#ef4444;">Lỗi kết nối.</div>';
            });
        return;
    }

    /* ── Old template without creation_mode → redirect to category parent ── */
    var hasCreationMode = Array.isArray(ff) && ff.some(function(f) { return f.slug === 'creation_mode'; });
    if (!hasCreationMode && tpl.category_id && !tpl._noRedirect) {
        body.innerHTML = '<div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>' +
            '<div style="text-align:center;padding:60px 20px;"><span class="bti-spinner"></span><br><small style="color:#9ca3af;">Đang tải kịch bản...</small></div>';
        modal.classList.add('open');

        fetch(BTI.restUrl + 'templates?category_id=' + tpl.category_id + '&subcategory=product&status=active&per_page=1', {
            headers: { 'X-WP-Nonce': BTI.restNonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var parent = data.templates && data.templates[0];
            if (parent) {
                if (typeof parent.form_fields === 'string') {
                    try { parent.form_fields = JSON.parse(parent.form_fields); } catch(e) { parent.form_fields = []; }
                }
                btiTplOpenModal(parent);
            } else {
                /* No parent found — show old template as-is */
                tpl._noRedirect = true;
                btiTplOpenModal(tpl);
            }
        })
        .catch(function() {
            tpl._noRedirect = true;
            btiTplOpenModal(tpl);
        });
        return;
    }

    /* ── Build modal for regular template ── */
    var imgHtml = tpl.thumbnail_url
        ? '<img src="' + escHtml(tpl.thumbnail_url) + '" style="width:100px;height:100px;border-radius:10px;object-fit:cover;" />'
        : '<div style="width:100px;height:100px;border-radius:10px;background:#f5f3ff;display:flex;align-items:center;justify-content:center;font-size:40px;">🎨</div>';

    var fields = Array.isArray(tpl.form_fields) ? tpl.form_fields : [];
    var hasSizePicker = fields.some(function(f) { return f.type === 'size_picker'; });
    var fieldsHtml = '';
    fields.forEach(function(f) {
        if (f.type === 'heading') {
            fieldsHtml += '<div class="bti-tpl-form-field"><h4 style="font-size:14px;font-weight:700;margin:8px 0 4px;">' + escHtml(f.label) + '</h4></div>';
            return;
        }

        var visAttr = '';
        if (f.visibility && f.visibility.creation_mode) {
            visAttr = ' data-vis-mode="' + escHtml(f.visibility.creation_mode) + '"';
        }

        var inputHtml = '';
        var defaultVal = f.default || '';
        switch (f.type) {
            case 'textarea':
                inputHtml = '<textarea data-slug="' + escHtml(f.slug) + '" rows="3" placeholder="' + escHtml(f.placeholder || '') + '"></textarea>';
                break;
            case 'select':
                var opts = '<option value="">-- Chọn --</option>';
                (f.options || []).forEach(function(o) { opts += '<option value="' + escHtml(o.value) + '">' + escHtml(o.label) + '</option>'; });
                inputHtml = '<select data-slug="' + escHtml(f.slug) + '">' + opts + '</select>';
                break;
            case 'card_radio':
                var cards = '';
                (f.options || []).forEach(function(o, i) {
                    var isSelected = defaultVal ? (o.value === defaultVal) : (o.recommended || i === 0);
                    cards += '<div class="card-radio' + (isSelected ? ' selected' : '') + '" data-value="' + escHtml(o.value) + '" onclick="btiTplSelectCard(this,\'' + escHtml(f.slug) + '\')">' +
                        (o.icon ? '<div class="cr-icon">' + o.icon + '</div>' : '') +
                        '<div>' + escHtml(o.label) +
                        (o.description ? '<br><small style="color:#9ca3af;font-size:10px;line-height:1.3;">' + escHtml(o.description) + '</small>' : '') +
                        '</div></div>';
                });
                inputHtml = '<div class="card-radio-group" data-slug="' + escHtml(f.slug) + '">' + cards + '</div>';
                break;
            case 'image_upload':
                inputHtml = '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:16px;text-align:center;cursor:pointer;" onclick="btiTplUploadImage(this)" data-slug="' + escHtml(f.slug) + '">' +
                    '<span style="font-size:24px;">📷</span><br><small>' + escHtml(f.help || 'Click để upload ảnh') + '</small>' +
                    '<input type="hidden" class="tpl-img-url" />' +
                    '</div>';
                break;
            case 'model_picker':
                var preModel = window._preSelectedModel;
                var preModelId = preModel ? preModel.id : '';
                window._preSelectedModel = null;
                var mpSlug = escHtml(f.slug);
                var mpHtml = '<input type="hidden" data-slug="' + mpSlug + '" value="' + escHtml(preModelId) + '" />';
                // Gallery container — will be populated async
                mpHtml += '<div id="bti-model-gallery-' + mpSlug + '" class="bti-model-gallery" ' +
                    'style="display:flex;gap:10px;overflow-x:auto;padding:4px 0 8px;scroll-snap-type:x mandatory;">' +
                    '<div style="padding:20px;color:#9ca3af;"><span class="bti-spinner"></span> Đang tải mẫu người...</div>' +
                '</div>';
                // Custom upload
                if (f.allow_custom_upload) {
                    mpHtml += '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:14px;text-align:center;cursor:pointer;margin-top:8px;" onclick="btiTplUploadImage(this)" data-slug="' + mpSlug + '_custom">' +
                        '<span style="font-size:20px;">👤</span><br><small>' + escHtml(f.custom_upload_label || 'Upload ảnh mẫu riêng') + '</small>' +
                        '<input type="hidden" class="tpl-img-url" />' +
                    '</div>';
                }
                inputHtml = mpHtml;
                // Load gallery after DOM render
                (function(slug, selectedId) {
                    setTimeout(function() { btiLoadModelGallery(slug, selectedId); }, 50);
                })(f.slug, preModelId);
                break;
            case 'size_picker':
                var spPills = '';
                (f.options || []).forEach(function(o) {
                    var sel = o.recommended ? ' selected' : '';
                    spPills += '<span class="bti-tpl-size-pill' + sel + '" data-size="' + escHtml(o.value) + '" onclick="btiTplSelectSize(this)">' +
                        (o.icon ? o.icon + ' ' : '') + escHtml(o.label) + '</span>';
                });
                inputHtml = '<div class="bti-tpl-size-pills" data-slug="' + escHtml(f.slug) + '">' + spPills + '</div>';
                break;
            case 'multi_reference_images':
                var roles = f.image_roles || [];
                var riHtml = '';
                roles.forEach(function(role) {
                    riHtml += '<div style="border:2px dashed #d1d5db;border-radius:10px;padding:12px;text-align:center;cursor:pointer;flex:1;min-width:100px;" onclick="btiTplUploadImage(this)" data-slug="ref_' + escHtml(role.slug) + '">' +
                        '<span style="font-size:18px;">' + (role.icon || '📷') + '</span><br>' +
                        '<small style="font-size:10px;">' + escHtml(role.label) + '</small>' +
                        '<input type="hidden" class="tpl-img-url" />' +
                    '</div>';
                });
                inputHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + riHtml + '</div>';
                break;
            case 'color_picker':
                inputHtml = '<input type="color" data-slug="' + escHtml(f.slug) + '" value="' + escHtml(f.default || '#ffffff') + '" style="width:60px;height:36px;border:none;cursor:pointer;" />';
                break;
            case 'number':
                inputHtml = '<input type="number" data-slug="' + escHtml(f.slug) + '" placeholder="' + escHtml(f.placeholder || '') + '" />';
                break;
            case 'quick_suggest':
                var pills = '';
                (f.options || []).forEach(function(o) {
                    pills += '<span class="bti-pill" style="cursor:pointer;" onclick="btiTplQuickSuggest(this,\'' + escHtml(f.slug) + '\')" data-value="' + escHtml(o.value) + '">' + escHtml(o.label) + '</span>';
                });
                inputHtml = '<input type="hidden" data-slug="' + escHtml(f.slug) + '" />' +
                    '<div class="bti-pill-row" data-qs-slug="' + escHtml(f.slug) + '">' + pills + '</div>';
                break;
            default: // text
                var textVal = '';
                var preItem = window._preSelectedItem;
                if (preItem && (f.slug === 'clothing_description' || f.slug === 'accessory_description')) {
                    textVal = preItem.description || preItem.name || '';
                }
                inputHtml = '<input type="text" data-slug="' + escHtml(f.slug) + '" placeholder="' + escHtml(f.placeholder || '') + '" value="' + escHtml(textVal) + '" />';
        }

        var requiredMark = f.required ? ' <span style="color:#ef4444;">*</span>' : '';
        var helpHtml = (f.help && f.type !== 'image_upload') ? '<small style="color:#9ca3af;display:block;margin-top:2px;">' + escHtml(f.help) + '</small>' : '';
        fieldsHtml += '<div class="bti-tpl-form-field"' + visAttr + '><label>' + escHtml(f.label) + requiredMark + '</label>' + inputHtml + helpHtml + '</div>';
    });
    window._preSelectedItem = null; // Clear after rendering

    /* Size pills — only if no size_picker field in template */
    var sizePillsHtml = '';
    if (!hasSizePicker) {
        var sizes = ['1024x1024', '1024x1792', '1792x1024', '768x1024', '1024x768'];
        var sizeLabels = {'1024x1024':'1:1','1024x1792':'9:16','1792x1024':'16:9','768x1024':'3:4','1024x768':'4:3'};
        var sizePills = '';
        sizes.forEach(function(s) {
            var sel = s === tpl.recommended_size ? ' selected' : '';
            sizePills += '<span class="bti-tpl-size-pill' + sel + '" data-size="' + s + '" onclick="btiTplSelectSize(this)">' + (sizeLabels[s] || s) + '</span>';
        });
        sizePillsHtml = '<div><label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Kích thước</label><div class="bti-tpl-size-pills">' + sizePills + '</div></div>';
    }

    body.innerHTML =
        '<div class="bti-tpl-modal-close"><span onclick="btiTplCloseModal()"></span></div>' +
        '<div class="bti-tpl-modal-header">' + imgHtml +
            '<div class="info"><h3>' + escHtml(tpl.title) + '</h3><p>' + escHtml(tpl.description || '') + '</p>' +
            '<small style="color:#9ca3af;">Model: ' + escHtml(tpl.recommended_model) + ' · Style: ' + escHtml(tpl.style || 'auto') + '</small></div>' +
        '</div>' +
        (fieldsHtml ? '<div class="bti-tpl-form-fields">' + fieldsHtml + '</div>' : '') +
        sizePillsHtml +
        '<div class="bti-tpl-prompt-preview" id="bti-tpl-prompt-preview">' + escHtml(tpl.prompt_template) + '</div>' +
        '<div id="bti-tpl-gen-status" class="bti-status"></div>' +
        '<div id="bti-tpl-gen-result" class="bti-result"></div>' +
        '<div style="display:flex;gap:8px;margin-top:12px;">' +
            '<button class="bti-btn bti-btn-primary" onclick="btiTplGenerate(' + tpl.id + ')" id="bti-tpl-gen-btn">✨ Tạo Ảnh</button>' +
        '</div>';

    modal.classList.add('open');
    window._currentTpl = tpl;
    window._formContainer = document.getElementById('bti-tpl-modal-body');
    btiTplToggleVisibility();
}

function btiTplCloseModal() {
    document.getElementById('bti-tpl-modal').classList.remove('open');
}

function btiTplSelectCard(el, fieldSlug) {
    el.parentElement.querySelectorAll('.card-radio, .bti-inline-gallery-card').forEach(function(c) { c.classList.remove('selected'); });
    el.classList.add('selected');
    if (fieldSlug === 'creation_mode') btiTplToggleVisibility();
}

function btiTplSelectSize(el) {
    el.parentElement.querySelectorAll('.bti-tpl-size-pill').forEach(function(c) { c.classList.remove('selected'); });
    el.classList.add('selected');
}

function btiTplToggleVisibility() {
    var modalBody = window._formContainer || document.getElementById('bti-tpl-modal-body');
    if (!modalBody) return;
    var modeGroup = modalBody.querySelector('.card-radio-group[data-slug="creation_mode"]');
    if (!modeGroup) return;
    var selected = modeGroup.querySelector('.card-radio.selected');
    var mode = selected ? selected.dataset.value : 'composite';
    modalBody.querySelectorAll('[data-vis-mode]').forEach(function(el) {
        el.style.display = el.dataset.visMode === mode ? '' : 'none';
    });
}

function btiTplQuickSuggest(el, slug) {
    // Find the textarea or input for this slug and append
    var _c = window._formContainer || document.getElementById('bti-tpl-modal-body');
    var input = _c ? _c.querySelector('[data-slug="' + slug + '"]') : null;
    if (!input) {
        input = _c ? _c.querySelector('textarea[data-slug="' + slug + '"], input[data-slug="' + slug + '"]') : null;
    }
    if (input && input.tagName) {
        var existing = input.value || '';
        input.value = existing ? existing + ', ' + el.dataset.value : el.dataset.value;
    }
}

function btiTplUploadImage(zone) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function() {
        if (!this.files[0]) return;
        var fd = new FormData();
        fd.append('action', 'bztimg_upload_photo');
        fd.append('_ajax_nonce', BTI.nonce);
        fd.append('photo', this.files[0]);

        zone.innerHTML = '<span class="bti-spinner"></span> Uploading...';

        fetch(BTI.ajax, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data.url) {
                    zone.innerHTML = '<img src="' + res.data.url + '" style="max-width:100%;max-height:120px;border-radius:8px;" />';
                    zone.querySelector('.tpl-img-url') || (function() {
                        var h = document.createElement('input');
                        h.type = 'hidden';
                        h.className = 'tpl-img-url';
                        h.value = res.data.url;
                        zone.appendChild(h);
                    })();
                    if (zone.querySelector('.tpl-img-url')) zone.querySelector('.tpl-img-url').value = res.data.url;
                } else {
                    zone.innerHTML = '<span style="color:#ef4444;">Upload lỗi</span>';
                }
            })
            .catch(function() {
                zone.innerHTML = '<span style="color:#ef4444;">Upload lỗi</span>';
            });
    };
    input.click();
}

function btiLoadModelGallery(fieldSlug, selectedId) {
    var container = document.getElementById('bti-model-gallery-' + fieldSlug);
    if (!container) return;
    fetch(BTI.restUrl + 'templates?subcategory=model&status=active&per_page=50', {
        headers: { 'X-WP-Nonce': BTI.restNonce }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var models = data.templates || [];
        if (!models.length) {
            container.innerHTML = '<small style="color:#9ca3af;">Chưa có mẫu người nào.</small>';
            return;
        }
        container.innerHTML = '';
        models.forEach(function(m) {
            var isSel = m.slug === selectedId;
            var card = document.createElement('div');
            card.className = 'bti-model-card' + (isSel ? ' selected' : '');
            card.dataset.modelSlug = m.slug;
            card.style.cssText = 'min-width:100px;max-width:110px;flex-shrink:0;border-radius:12px;padding:8px;text-align:center;cursor:pointer;' +
                'border:2px solid ' + (isSel ? '#22c55e' : '#e5e7eb') + ';' +
                'background:' + (isSel ? '#f0fdf4' : '#fff') + ';scroll-snap-align:start;transition:border-color .2s,background .2s;';
            card.innerHTML = (m.thumbnail_url
                ? '<img src="' + escHtml(m.thumbnail_url) + '" style="width:64px;height:64px;border-radius:8px;object-fit:cover;margin:0 auto 6px;" />'
                : '<div style="width:64px;height:64px;border-radius:8px;background:#f3f4f6;margin:0 auto 6px;display:flex;align-items:center;justify-content:center;font-size:24px;">👤</div>') +
                '<div style="font-size:11px;font-weight:' + (isSel ? '700' : '500') + ';color:' + (isSel ? '#16a34a' : '#374151') + ';line-height:1.3;word-break:break-word;">' +
                    (isSel ? '✓ ' : '') + escHtml(m.title) +
                '</div>';
            card.onclick = function() { btiSelectModel(fieldSlug, m.slug, container); };
            container.appendChild(card);
        });
    })
    .catch(function() {
        container.innerHTML = '<small style="color:#ef4444;">Lỗi tải mẫu người.</small>';
    });
}

function btiSelectModel(fieldSlug, modelSlug, gallery) {
    // Update hidden input
    var container = window._formContainer || document.getElementById('bti-tpl-modal-body');
    var hiddenInput = container.querySelector('input[data-slug="' + fieldSlug + '"]');
    if (hiddenInput) hiddenInput.value = modelSlug;
    // Visual toggle
    gallery.querySelectorAll('.bti-model-card').forEach(function(card) {
        var isSel = card.dataset.modelSlug === modelSlug;
        card.classList.toggle('selected', isSel);
        card.style.borderColor = isSel ? '#22c55e' : '#e5e7eb';
        card.style.background = isSel ? '#f0fdf4' : '#fff';
        // Update text style
        var textDiv = card.querySelector('div:last-child');
        if (textDiv) {
            var name = textDiv.textContent.replace(/^✓\s*/, '');
            textDiv.style.fontWeight = isSel ? '700' : '500';
            textDiv.style.color = isSel ? '#16a34a' : '#374151';
            textDiv.textContent = (isSel ? '✓ ' : '') + name;
        }
    });
    // Clear custom upload if a preset is selected
    var customZone = container.querySelector('[data-slug="' + fieldSlug + '_custom"] .tpl-img-url');
    if (customZone) customZone.value = '';
}

function btiTplGenerate(templateId) {
    var btn = document.getElementById('bti-tpl-gen-btn');
    var status = document.getElementById('bti-tpl-gen-status');
    var result = document.getElementById('bti-tpl-gen-result');

    btn.disabled = true;
    btn.innerHTML = '<span class="bti-spinner"></span> Đang tạo...';
    status.className = 'bti-status loading';
    status.style.display = 'block';
    status.textContent = '⏳ Đang tạo ảnh từ template...';
    result.className = 'bti-result';
    result.innerHTML = '';

    // Collect form data
    var formData = {};
    var modalBody = window._formContainer || document.getElementById('bti-tpl-modal-body');

    // Text/textarea/number/color inputs
    modalBody.querySelectorAll('input[data-slug], textarea[data-slug], select[data-slug]').forEach(function(el) {
        formData[el.dataset.slug] = el.value;
    });

    // Card radios
    modalBody.querySelectorAll('.card-radio-group[data-slug]').forEach(function(group) {
        var selected = group.querySelector('.card-radio.selected');
        if (selected) formData[group.dataset.slug] = selected.dataset.value;
    });

    // Image uploads
    modalBody.querySelectorAll('[data-slug] .tpl-img-url').forEach(function(input) {
        var slug = input.closest('[data-slug]').dataset.slug;
        if (input.value) formData[slug] = input.value;
    });

    // Size picker fields
    modalBody.querySelectorAll('.bti-tpl-size-pills[data-slug]').forEach(function(group) {
        var selected = group.querySelector('.bti-tpl-size-pill.selected');
        if (selected) formData[group.dataset.slug] = selected.dataset.size;
    });

    // Merge model_picker custom upload into main slug
    var _tplFields = window._currentTpl ? window._currentTpl.form_fields : [];
    if (Array.isArray(_tplFields)) {
        _tplFields.forEach(function(f) {
            if (f.type === 'model_picker' && formData[f.slug + '_custom'] && !formData[f.slug]) {
                formData[f.slug] = formData[f.slug + '_custom'];
            }
            delete formData[f.slug + '_custom'];
        });
    }

    // Validate required fields
    var tpl = window._currentTpl;
    if (tpl && tpl.form_fields) {
        var missing = [];
        tpl.form_fields.forEach(function(f) {
            if (f.required && !formData[f.slug]) missing.push(f.label);
        });
        if (missing.length) {
            status.className = 'bti-status error';
            status.style.display = 'block';
            status.textContent = '⚠️ Vui lòng điền: ' + missing.join(', ');
            btn.disabled = false;
            btn.innerHTML = '✨ Tạo Ảnh';
            return;
        }
    }

    // Get selected size
    var sizeEl = modalBody.querySelector('.bti-tpl-size-pill.selected');
    var size = sizeEl ? sizeEl.dataset.size : '';

    var payload = { form_data: formData };
    if (size) payload.size = size;

    fetch(BTI.restUrl + 'templates/' + templateId + '/generate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': BTI.restNonce,
        },
        body: JSON.stringify(payload),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '✨ Tạo Ảnh';

        if (data.image_url) {
            status.className = 'bti-status success';
            status.textContent = '✅ Ảnh đã tạo thành công!';
            result.className = 'bti-result show';
            result.innerHTML = '<img src="' + escHtml(data.image_url) + '" style="width:100%;border-radius:12px;" />' +
                '<div class="bti-result-actions" style="padding:12px;">' +
                    '<a href="' + escHtml(data.image_url) + '" target="_blank" class="bti-btn-secondary">🔗 Mở</a>' +
                    '<button class="bti-btn-secondary" onclick="btiShareImage(\'' + escHtml(data.image_url) + '\')">📤 Chia sẻ</button>' +
                '</div>';
        } else if (data.code || data.message) {
            status.className = 'bti-status error';
            status.textContent = '❌ ' + (data.message || 'Lỗi không xác định');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '✨ Tạo Ảnh';
        status.className = 'bti-status error';
        status.textContent = '❌ Lỗi kết nối: ' + err.message;
    });
}

/* Close modal on backdrop click */
document.getElementById('bti-tpl-modal').addEventListener('click', function(e) {
    if (e.target === this) btiTplCloseModal();
});

/* Close modal on Escape key */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') btiTplCloseModal();
});

</script>
</body>
</html>
