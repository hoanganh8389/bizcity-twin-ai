<?php
/**
 * BizCity Tool Image — Profile Studio (Frontend)
 *
 * Face-swap & style-copy page at /profile-studio/
 * Tabs: Template Nhanh | Tùy Chỉnh Nâng Cao | Prompt Tự Do | Sao Chép Phong Cách | Gallery
 *
 * @package BizCity_Tool_Image
 * @since   3.8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id   = get_current_user_id();
$credits   = (int) get_user_meta( $user_id, 'bztimg_credits', true ) ?: 100;
$ajax_url  = admin_url( 'admin-ajax.php' );
$gen_nonce = wp_create_nonce( 'bztimg_nonce' );
$canva_base = home_url( '/canva/' );
$canva_qs   = http_build_query( array(
    'restUrl'   => rest_url( 'bztool-image/v1/' ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'userId'    => $user_id,
    'siteUrl'   => home_url(),
    'pluginUrl' => defined( 'BZTIMG_URL' ) ? BZTIMG_URL . 'design-editor-build/' : '',
) );
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'template-quick';
$allowed_tabs = [ 'template-quick', 'advanced', 'free-prompt', 'style-copy', 'gallery' ];
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) $active_tab = 'template-quick';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile Studio — BizCity Image AI</title>
<style>
:root {
  --bg:         #ffffff;
  --bg-card:    #ffffff;
  --bg-muted:   #f8fafc;
  --border:     #e2e8f0;
  --primary:    #7c3aed;
  --primary-10: rgba(124,58,237,.10);
  --primary-fg: #ffffff;
  --muted-fg:   #64748b;
  --fg:         #0f172a;
  --ring:       rgba(124,58,237,.3);
  --radius:     .5rem;
  --shadow-sm:  0 1px 3px rgba(0,0,0,.08);
  --shadow-md:  0 4px 12px rgba(0,0,0,.10);
  --purple-600: #9333ea;
  --pink-600:   #db2777;
  --success:    #16a34a;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg-muted);color:var(--fg);min-height:100vh;line-height:1.5;}

/* ═══ Header ═══ */
.ps-header{height:56px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 20px;background:var(--bg);flex-shrink:0;gap:12px;position:sticky;top:0;z-index:50;}
.ps-header-brand{display:flex;align-items:center;gap:8px;}
.ps-header-brand svg{color:var(--primary);}
.ps-header-brand h1{font-size:1rem;font-weight:700;white-space:nowrap;}
.ps-header-right{display:flex;align-items:center;gap:8px;}
.ps-credits-badge{display:flex;align-items:center;gap:5px;padding:4px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px;font-weight:600;}
.ps-credits-badge svg{color:var(--primary);}
.ps-icon-btn{width:32px;height:32px;border:1px solid var(--border);background:var(--bg);border-radius:var(--radius);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--fg);transition:all .15s;text-decoration:none;}
.ps-icon-btn:hover{background:var(--bg-muted);}

/* ═══ Tool Tabs Bar ═══ */
.ps-toolbar{border-bottom:1px solid var(--border);background:var(--bg);padding:0 20px;position:sticky;top:56px;z-index:40;}
.ps-toolbar-inner{display:flex;align-items:center;gap:4px;overflow-x:auto;padding:8px 0;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.ps-toolbar-inner::-webkit-scrollbar{height:0;}
.ps-tool-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--radius);font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:var(--bg);color:var(--fg);white-space:nowrap;transition:all .15s;flex-shrink:0;}
.ps-tool-btn:hover{background:var(--bg-muted);}
.ps-tool-btn.active{background:var(--primary);color:var(--primary-fg);border-color:var(--primary);}
.ps-badge-new{display:inline-flex;align-items:center;border-radius:999px;font-size:9px;font-weight:700;padding:1px 5px;margin-left:2px;background:#ede9fe;color:#6d28d9;}

/* ═══ Container ═══ */
.pf-container{max-width:1200px;margin:0 auto;padding:24px 20px 120px;}

/* ═══ Hero ═══ */
.pf-hero{text-align:center;margin-bottom:24px;}
.pf-hero-badge{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(219,39,119,.1));padding:6px 14px;border-radius:999px;margin-bottom:12px;font-size:13px;font-weight:600;color:var(--purple-600);}
.pf-hero h1{font-size:1.5rem;font-weight:800;margin-bottom:6px;}
.pf-hero p{font-size:14px;color:var(--muted-fg);max-width:600px;margin:0 auto;}

/* ═══ Tab Panels ═══ */
.pf-tab-panel{display:none;}
.pf-tab-panel.active{display:block;}

/* ═══ Two-Column Upload ═══ */
.pf-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:768px){.pf-grid-2{grid-template-columns:1fr;}}

.pf-upload-section{margin-bottom:16px;}
.pf-section-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.pf-step-num{width:28px;height:28px;border-radius:50%;background:var(--primary-10);color:var(--primary);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pf-section-header h3{font-size:15px;font-weight:700;}

/* Card */
.pf-card{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:20px;box-shadow:var(--shadow-sm);}

/* Upload zone */
.pf-dropzone{border:2px dashed var(--border);border-radius:10px;padding:32px 16px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.pf-dropzone:hover,.pf-dropzone.dragover{border-color:var(--primary);background:var(--primary-10);}
.pf-dropzone input[type=file]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;z-index:2;}
.pf-dz-icon{width:48px;height:48px;border-radius:50%;background:var(--primary-10);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.pf-dz-title{font-size:14px;font-weight:600;margin-bottom:3px;}
.pf-dz-sub{font-size:12px;color:var(--muted-fg);}

/* Image preview */
.pf-preview{display:none;position:relative;border-radius:10px;overflow:hidden;background:var(--bg-muted);text-align:center;}
.pf-preview.show{display:block;}
.pf-preview img{max-width:100%;max-height:280px;object-fit:contain;border-radius:8px;}
.pf-preview-info{font-size:11px;color:var(--muted-fg);margin-top:6px;}
.pf-remove-btn{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;z-index:3;}

/* Template grid */
.pf-tpl-filters{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;}
.pf-filter-pill{display:inline-flex;align-items:center;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:var(--bg);transition:all .15s;}
.pf-filter-pill.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.pf-tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;}
.pf-tpl-card{border:2px solid var(--border);border-radius:8px;overflow:hidden;cursor:pointer;transition:all .15s;position:relative;}
.pf-tpl-card:hover{border-color:var(--primary);box-shadow:var(--shadow-sm);}
.pf-tpl-card.selected{border-color:var(--primary);box-shadow:0 0 0 2px var(--primary);}
.pf-tpl-card img{width:100%;aspect-ratio:3/4;object-fit:cover;display:block;}
.pf-tpl-card-label{padding:4px 8px;font-size:11px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:var(--bg);}

/* Face lock */
.pf-face-lock{border:2px solid rgba(124,58,237,.2);background:rgba(124,58,237,.03);border-radius:10px;padding:14px 16px;margin:16px 0;}
.pf-face-lock-row{display:flex;align-items:center;justify-content:space-between;}
.pf-face-lock-label{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;}
.pf-face-lock-desc{font-size:12px;color:var(--muted-fg);margin-top:4px;}
.pf-switch{position:relative;display:inline-flex;height:24px;width:44px;cursor:pointer;align-items:center;border-radius:999px;transition:background .2s;background:var(--border);}
.pf-switch input{clip:rect(0 0 0 0);height:1px;overflow:hidden;position:absolute;white-space:nowrap;width:1px;}
.pf-switch-thumb{pointer-events:none;display:block;height:20px;width:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.2);transition:transform .2s;}
.pf-switch.checked{background:var(--primary);}
.pf-switch.checked .pf-switch-thumb{transform:translateX(20px);}

/* Prompt area */
.pf-prompt-area{margin-top:16px;}
.pf-prompt-area label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;}
.pf-prompt-area textarea{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:10px;font-size:13px;font-family:inherit;resize:vertical;min-height:80px;}
.pf-prompt-area textarea:focus{outline:none;border-color:var(--primary);}

/* Strength slider */
.pf-strength{margin-top:16px;}
.pf-strength label{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin-bottom:8px;}
.pf-strength input[type=range]{width:100%;accent-color:var(--primary);}
.pf-strength-labels{display:flex;justify-content:space-between;font-size:11px;color:var(--muted-fg);margin-top:4px;}

/* Gallery grid */
.pf-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
.pf-gallery-item{border-radius:8px;overflow:hidden;border:1px solid var(--border);background:var(--bg);}
.pf-gallery-item img{width:100%;aspect-ratio:1;object-fit:cover;display:block;}
.pf-gallery-item-info{padding:6px 10px;font-size:11px;color:var(--muted-fg);}

/* Empty state */
.pf-empty{text-align:center;padding:48px 20px;color:var(--muted-fg);}
.pf-empty-icon{font-size:40px;margin-bottom:12px;}
.pf-empty p{font-size:14px;}

/* ═══ Sticky Bottom Bar ═══ */
.pf-bottom-bar{position:fixed;bottom:0;left:0;right:0;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border-top:1px solid var(--border);z-index:50;padding:12px 20px;box-shadow:0 -2px 12px rgba(0,0,0,.06);}
.pf-bottom-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.pf-count-group{display:flex;align-items:center;gap:12px;}
.pf-count-group span{font-size:13px;font-weight:500;}
.pf-count-radios{display:flex;gap:10px;}
.pf-count-radios label{display:flex;align-items:center;gap:4px;font-size:13px;font-weight:600;cursor:pointer;}
.pf-count-radios input[type=radio]{accent-color:var(--primary);}
.pf-btn-generate{display:inline-flex;align-items:center;gap:6px;padding:10px 28px;border:none;border-radius:var(--radius);background:linear-gradient(135deg,var(--purple-600),var(--pink-600));color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;min-width:180px;justify-content:center;}
.pf-btn-generate:hover{opacity:.9;}
.pf-btn-generate:disabled{opacity:.5;cursor:not-allowed;}
.pf-btn-generate svg{width:16px;height:16px;}

/* Spinner */
.pf-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:none;}
@keyframes spin{to{transform:rotate(360deg);}}

/* Results area */
.pf-results{margin-top:24px;}
.pf-results-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;}
.pf-result-card{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--bg);box-shadow:var(--shadow-sm);}
.pf-result-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block;}
.pf-result-actions{padding:8px 12px;display:flex;gap:6px;flex-wrap:wrap;}
.pf-result-btn{flex:1;padding:6px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);font-size:11px;font-weight:500;cursor:pointer;text-align:center;transition:all .15s;}
.pf-result-btn:hover{background:var(--bg-muted);}
.pf-result-btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);}
.pf-result-btn.save-media{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
.pf-result-btn.save-media:hover{background:#dbeafe;}
.pf-result-btn.save-media.saved{background:#dcfce7;color:#15803d;border-color:#bbf7d0;pointer-events:none;}
.pf-result-btn.canva{background:#faf5ff;color:#7c3aed;border-color:#e9d5ff;}
.pf-result-btn.canva:hover{background:#f3e8ff;}

/* Loading placeholder */
.pf-loading-card{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--bg);}
.pf-loading-shimmer{width:100%;aspect-ratio:1;background:linear-gradient(90deg,var(--bg-muted) 25%,#e2e8f0 50%,var(--bg-muted) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ═══ Toast / Dialog notification ═══ */
.pf-toast-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s;}
.pf-toast-overlay.show{opacity:1;pointer-events:auto;}
.pf-toast-box{background:#fff;border-radius:16px;padding:28px 32px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:scale(.9);transition:transform .25s;}
.pf-toast-overlay.show .pf-toast-box{transform:scale(1);}
.pf-toast-icon{font-size:48px;margin-bottom:12px;}
.pf-toast-title{font-size:17px;font-weight:700;margin-bottom:6px;}
.pf-toast-msg{font-size:13px;color:var(--muted-fg);margin-bottom:18px;line-height:1.5;}
.pf-toast-btn{padding:10px 32px;border:none;border-radius:var(--radius);background:var(--primary);color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;}
.pf-toast-btn:hover{opacity:.9;}

/* ═══ Results area (compact, below upload) ═══ */
.pf-results{margin-top:16px;}
.pf-results-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;}

/* ═══ Job History ═══ */
.pf-job-history{margin-bottom:28px;}
.pf-job-section-title{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.pf-job-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;}
.pf-job-card{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;overflow:hidden;box-shadow:var(--shadow-sm);transition:box-shadow .15s;}
.pf-job-card:hover{box-shadow:var(--shadow-md);}
.pf-job-thumb{width:100%;aspect-ratio:1;object-fit:cover;display:block;background:var(--bg-muted);}
.pf-job-thumb-placeholder{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:var(--bg-muted);color:var(--muted-fg);font-size:36px;}
.pf-job-body{padding:10px 12px;}
.pf-job-meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
.pf-job-id{font-size:12px;font-weight:600;color:var(--muted-fg);}
.pf-job-date{font-size:11px;color:var(--muted-fg);}
.pf-job-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;}
.pf-job-badge.completed{background:#dcfce7;color:#15803d;}
.pf-job-badge.pending{background:#fef9c3;color:#a16207;}
.pf-job-badge.failed{background:#fee2e2;color:#dc2626;}
.pf-job-badge.processing{background:#e0e7ff;color:#4338ca;}
.pf-job-prompt{font-size:12px;color:var(--muted-fg);margin-top:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.pf-job-actions{display:flex;gap:6px;margin-top:8px;}
.pf-job-btn{flex:1;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);font-size:11px;font-weight:500;cursor:pointer;text-align:center;transition:all .15s;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:4px;}
.pf-job-btn:hover{background:var(--bg-muted);}
.pf-job-btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);}
.pf-job-btn.primary:hover{opacity:.9;}
.pf-job-btn.retry{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.pf-job-btn.retry:hover{background:#fde68a;}
.pf-job-btn:disabled{opacity:.5;cursor:not-allowed;}
.pf-job-error{font-size:11px;color:#dc2626;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pf-job-load-more{display:block;margin:16px auto 0;padding:8px 24px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);font-size:13px;cursor:pointer;font-family:inherit;}
.pf-job-load-more:hover{background:var(--bg-muted);}
</style>
</head>
<body>

<!-- Header -->
<header class="ps-header">
  <div class="ps-header-brand">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/></svg>
    <h1>Profile Studio</h1>
  </div>
  <div class="ps-header-right">
    <div class="ps-credits-badge">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/></svg>
      <span id="pf-credits"><?php echo esc_html( $credits ); ?></span> credits
    </div>
    <a href="<?php echo esc_url( home_url( '/tool-image/' ) ); ?>" class="ps-icon-btn" title="Image Studio">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </a>
  </div>
</header>

<!-- Tool Tabs -->
<div class="ps-toolbar">
  <div class="ps-toolbar-inner">
    <span style="font-size:12px;font-weight:500;color:var(--muted-fg);margin-right:6px;flex-shrink:0;">Công cụ:</span>
    <button class="ps-tool-btn <?php echo $active_tab === 'template-quick' ? 'active':''; ?>" data-tab="template-quick">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
      Template Nhanh
    </button>
    <button class="ps-tool-btn <?php echo $active_tab === 'advanced' ? 'active':''; ?>" data-tab="advanced">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 8h4"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M17 16h4"/><path d="M19 12V3"/><path d="M19 21v-5"/><path d="M3 14h4"/><path d="M5 10V3"/><path d="M5 21v-7"/></svg>
      Tùy Chỉnh Nâng Cao
    </button>
    <button class="ps-tool-btn <?php echo $active_tab === 'free-prompt' ? 'active':''; ?>" data-tab="free-prompt">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
      Prompt Tự Do
    </button>
    <button class="ps-tool-btn <?php echo $active_tab === 'style-copy' ? 'active':''; ?>" data-tab="style-copy">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg>
      Sao Chép Phong Cách
      <span class="ps-badge-new">NEW</span>
    </button>
    <button class="ps-tool-btn <?php echo $active_tab === 'gallery' ? 'active':''; ?>" data-tab="gallery">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 11-1.296-1.296a2.4 2.4 0 0 0-3.408 0L11 16"/><path d="M4 8a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2"/><circle cx="13" cy="7" r="1" fill="currentColor"/><rect x="8" y="2" width="14" height="14" rx="2"/></svg>
      Gallery
    </button>
  </div>
</div>

<div class="pf-container">

  <!-- Hero -->
  <div class="pf-hero">
    <div class="pf-hero-badge">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>
      Profile Studio
    </div>
    <h1>🎨 Chân Dung Profile AI</h1>
    <p>Tải ảnh của bạn lên, chọn phong cách mẫu → AI tạo ảnh chân dung chuyên nghiệp giữ nguyên khuôn mặt</p>
  </div>

  <!-- ═══════════════ TAB: Template Nhanh ═══════════════ -->
  <div class="pf-tab-panel <?php echo $active_tab === 'template-quick' ? 'active':''; ?>" data-panel="template-quick">
    <div class="pf-grid-2">
      <!-- Left: User Photo -->
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header">
            <div class="pf-step-num">1</div>
            <h3>📸 Ảnh Của Bạn</h3>
          </div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-source-drop">
              <div class="pf-dz-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>
              </div>
              <div class="pf-dz-title">Tải ảnh khuôn mặt</div>
              <div class="pf-dz-sub">Kéo thả hoặc click để chọn • JPG, PNG</div>
              <input type="file" accept="image/*" id="pf-source-input">
            </div>
            <div class="pf-preview" id="pf-source-preview">
              <button class="pf-remove-btn" id="pf-source-remove">✕</button>
              <img id="pf-source-img" src="" alt="User photo">
              <div class="pf-preview-info" id="pf-source-info">Khuôn mặt từ ảnh này sẽ được giữ nguyên 100%</div>
            </div>
          </div>
          <!-- Results moved here: right below user photo -->
          <div class="pf-results" id="pf-results" style="display:none;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">✨ Kết quả</h3>
            <div class="pf-results-grid" id="pf-results-grid"></div>
          </div>
        </div>
      </div>

      <!-- Right: Template Selection -->
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header">
            <div class="pf-step-num" style="background:rgba(147,51,234,.1);color:var(--purple-600);">2</div>
            <h3>🎨 Chọn Template</h3>
          </div>
          <div class="pf-card">
            <div class="pf-tpl-filters">
              <button class="pf-filter-pill active" data-category="all">Tất cả</button>
              <button class="pf-filter-pill" data-category="man">Đàn ông</button>
              <button class="pf-filter-pill" data-category="woman">Phụ nữ</button>
              <button class="pf-filter-pill" data-category="professional">Chuyên nghiệp</button>
              <button class="pf-filter-pill" data-category="creative">Sáng tạo</button>
            </div>
            <div class="pf-tpl-grid" id="pf-tpl-grid">
              <div class="pf-empty">
                <div class="pf-empty-icon">📷</div>
                <p>Đang tải templates...</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Face Lock -->
    <div class="pf-face-lock">
      <div class="pf-face-lock-row">
        <div class="pf-face-lock-label">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Khóa Khuôn Mặt
        </div>
        <label class="pf-switch checked" id="pf-face-lock-switch">
          <input type="checkbox" checked id="pf-face-lock">
          <span class="pf-switch-thumb"></span>
        </label>
      </div>
      <div class="pf-face-lock-desc">🔒 Two-pass: Giữ 100% khuôn mặt gốc, không thay đổi đặc trưng</div>
    </div>

  </div>

  <!-- ═══════════════ TAB: Tùy Chỉnh Nâng Cao ═══════════════ -->
  <div class="pf-tab-panel <?php echo $active_tab === 'advanced' ? 'active':''; ?>" data-panel="advanced">
    <div class="pf-grid-2">
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num">1</div><h3>📸 Ảnh Của Bạn</h3></div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-adv-source-drop">
              <div class="pf-dz-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg></div>
              <div class="pf-dz-title">Tải ảnh khuôn mặt</div>
              <div class="pf-dz-sub">JPG, PNG</div>
              <input type="file" accept="image/*" id="pf-adv-source-input">
            </div>
            <div class="pf-preview" id="pf-adv-source-preview">
              <button class="pf-remove-btn" data-target="pf-adv-source">✕</button>
              <img id="pf-adv-source-img" src="" alt="User photo">
            </div>
          </div>
          <div class="pf-results" id="pf-adv-results" style="display:none;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">✨ Kết quả</h3>
            <div class="pf-results-grid" id="pf-adv-results-grid"></div>
          </div>
        </div>
      </div>
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num" style="background:rgba(147,51,234,.1);color:var(--purple-600);">2</div><h3>🎨 Ảnh Phong Cách Mẫu</h3></div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-adv-ref-drop">
              <div class="pf-dz-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></div>
              <div class="pf-dz-title">Tải ảnh phong cách mẫu</div>
              <div class="pf-dz-sub">Chỉ style được copy (outfit/ánh sáng), không phải khuôn mặt</div>
              <input type="file" accept="image/*" id="pf-adv-ref-input">
            </div>
            <div class="pf-preview" id="pf-adv-ref-preview">
              <button class="pf-remove-btn" data-target="pf-adv-ref">✕</button>
              <img id="pf-adv-ref-img" src="" alt="Style reference">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Strength slider -->
    <div class="pf-card" style="margin-top:16px;">
      <div class="pf-strength">
        <label>
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
          Cường Độ Sao Chép: <span id="pf-strength-val" style="color:var(--primary);font-size:15px;">70%</span>
        </label>
        <input type="range" id="pf-strength" min="30" max="100" value="70" step="5">
        <div class="pf-strength-labels">
          <span>Nhẹ nhàng</span><span>Cân bằng</span><span>Mạnh mẽ</span>
        </div>
      </div>
    </div>

    <!-- Face Lock -->
    <div class="pf-face-lock">
      <div class="pf-face-lock-row">
        <div class="pf-face-lock-label">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Khóa Khuôn Mặt
        </div>
        <label class="pf-switch checked" id="pf-adv-face-lock-switch">
          <input type="checkbox" checked id="pf-adv-face-lock">
          <span class="pf-switch-thumb"></span>
        </label>
      </div>
      <div class="pf-face-lock-desc">🔒 Two-pass: Giữ 100% khuôn mặt gốc</div>
    </div>
  </div>

  <!-- ═══════════════ TAB: Prompt Tự Do ═══════════════ -->
  <div class="pf-tab-panel <?php echo $active_tab === 'free-prompt' ? 'active':''; ?>" data-panel="free-prompt">
    <div class="pf-grid-2">
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num">1</div><h3>📸 Ảnh Của Bạn</h3></div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-fp-source-drop">
              <div class="pf-dz-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg></div>
              <div class="pf-dz-title">Tải ảnh khuôn mặt</div>
              <div class="pf-dz-sub">JPG, PNG</div>
              <input type="file" accept="image/*" id="pf-fp-source-input">
            </div>
            <div class="pf-preview" id="pf-fp-source-preview">
              <button class="pf-remove-btn" data-target="pf-fp-source">✕</button>
              <img id="pf-fp-source-img" src="" alt="User photo">
            </div>
          </div>
          <div class="pf-results" id="pf-fp-results" style="display:none;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">✨ Kết quả</h3>
            <div class="pf-results-grid" id="pf-fp-results-grid"></div>
          </div>
        </div>
      </div>
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num" style="background:rgba(147,51,234,.1);color:var(--purple-600);">2</div><h3>✍️ Mô tả phong cách</h3></div>
          <div class="pf-card">
            <div class="pf-prompt-area">
              <label>Prompt mô tả phong cách bạn muốn:</label>
              <textarea id="pf-fp-prompt" placeholder="Ví dụ: Professional headshot, studio lighting, dark blue suit, warm smile, blurred office background, corporate portrait..."></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="pf-face-lock">
      <div class="pf-face-lock-row">
        <div class="pf-face-lock-label">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Khóa Khuôn Mặt
        </div>
        <label class="pf-switch checked" id="pf-fp-face-lock-switch">
          <input type="checkbox" checked id="pf-fp-face-lock">
          <span class="pf-switch-thumb"></span>
        </label>
      </div>
      <div class="pf-face-lock-desc">🔒 Two-pass: Giữ 100% khuôn mặt gốc</div>
    </div>
  </div>

  <!-- ═══════════════ TAB: Sao Chép Phong Cách ═══════════════ -->
  <div class="pf-tab-panel <?php echo $active_tab === 'style-copy' ? 'active':''; ?>" data-panel="style-copy">
    <div class="pf-hero" style="margin-bottom:20px;">
      <h1 style="font-size:1.25rem;">✨ Lấy Bất Kỳ Ảnh Nào Làm Mẫu → Tạo Ảnh Của Bạn Y Hệt</h1>
      <p>Thấy ảnh đẹp? Úm ba la 🪄 tạo ảnh của bạn với phong cách y chang — màu sắc, ánh sáng, mood</p>
    </div>
    <div class="pf-grid-2">
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num">1</div><h3>📸 Ảnh Của Bạn</h3></div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-sc-source-drop">
              <div class="pf-dz-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg></div>
              <div class="pf-dz-title">Tải ảnh khuôn mặt</div>
              <div class="pf-dz-sub">Khuôn mặt từ ảnh này sẽ được giữ nguyên 100%</div>
              <input type="file" accept="image/*" id="pf-sc-source-input">
            </div>
            <div class="pf-preview" id="pf-sc-source-preview">
              <button class="pf-remove-btn" data-target="pf-sc-source">✕</button>
              <img id="pf-sc-source-img" src="" alt="User photo">
            </div>
          </div>
          <div class="pf-results" id="pf-sc-results" style="display:none;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">✨ Kết quả</h3>
            <div class="pf-results-grid" id="pf-sc-results-grid"></div>
          </div>
        </div>
      </div>
      <div>
        <div class="pf-upload-section">
          <div class="pf-section-header"><div class="pf-step-num" style="background:rgba(147,51,234,.1);color:var(--purple-600);">2</div><h3>🎨 Ảnh Phong Cách Mẫu</h3></div>
          <div class="pf-card">
            <div class="pf-dropzone" id="pf-sc-ref-drop">
              <div class="pf-dz-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></div>
              <div class="pf-dz-title">Tải ảnh phong cách mẫu</div>
              <div class="pf-dz-sub">Chỉ style được copy (outfit/ánh sáng), không phải khuôn mặt</div>
              <input type="file" accept="image/*" id="pf-sc-ref-input">
            </div>
            <div class="pf-preview" id="pf-sc-ref-preview">
              <button class="pf-remove-btn" data-target="pf-sc-ref">✕</button>
              <img id="pf-sc-ref-img" src="" alt="Style reference">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="pf-face-lock">
      <div class="pf-face-lock-row">
        <div class="pf-face-lock-label">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Khóa Khuôn Mặt
        </div>
        <label class="pf-switch checked" id="pf-sc-face-lock-switch">
          <input type="checkbox" checked id="pf-sc-face-lock">
          <span class="pf-switch-thumb"></span>
        </label>
      </div>
      <div class="pf-face-lock-desc">🔒 Two-pass: Giữ 100% khuôn mặt gốc, không thay đổi đặc trưng</div>
    </div>
  </div>

  <!-- ═══════════════ TAB: Gallery ═══════════════ -->
  <div class="pf-tab-panel <?php echo $active_tab === 'gallery' ? 'active':''; ?>" data-panel="gallery">

    <!-- Job History -->
    <div class="pf-job-history">
      <div class="pf-job-section-title">📋 Lịch Sử Job</div>
      <div class="pf-job-list" id="pf-job-list">
        <div class="pf-empty"><div class="pf-empty-icon">⏳</div><p>Đang tải...</p></div>
      </div>
      <button class="pf-job-load-more" id="pf-job-load-more" style="display:none;">Xem thêm...</button>
    </div>

    <!-- Completed Gallery -->
    <div class="pf-job-section-title">🖼️ Ảnh Đã Tạo</div>
    <div class="pf-gallery-grid" id="pf-gallery-grid">
      <div class="pf-empty">
        <div class="pf-empty-icon">🖼️</div>
        <p>Đang tải ảnh...</p>
      </div>
    </div>
  </div>

</div><!-- .pf-container -->

<!-- Sticky Bottom Bar -->
<div class="pf-bottom-bar" id="pf-bottom-bar">
  <div class="pf-bottom-inner" style="justify-content:flex-end;">
    <button class="pf-btn-generate" id="pf-btn-generate">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg>
      <span id="pf-btn-text">Tạo Ảnh ✨</span>
      <span class="pf-spinner" id="pf-spinner"></span>
    </button>
  </div>
</div>

<script>
(function(){
  'use strict';

  /* ═══ Config ═══ */
  var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
  var NONCE    = <?php echo wp_json_encode( $gen_nonce ); ?>;
  var CANVA_BASE = <?php echo wp_json_encode( $canva_base . '?' . $canva_qs ); ?>;
  var POLL_INTERVAL = 5000; // 5 seconds
  var POLL_MAX = 60;        // max 60 polls = 5 min timeout

  /* ═══ State ═══ */
  var state = {
    activeTab: <?php echo wp_json_encode( $active_tab ); ?>,
    sourceUrl: {},
    referenceUrl: {},
    selectedTemplate: null,
    generating: false,
    pollTimers: {}  // per-tab polling timers
  };

  /* ═══ Tab switching ═══ */
  document.querySelectorAll('.ps-tool-btn[data-tab]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tab = this.dataset.tab;
      document.querySelectorAll('.ps-tool-btn[data-tab]').forEach(function(b){ b.classList.remove('active'); });
      this.classList.add('active');
      document.querySelectorAll('.pf-tab-panel').forEach(function(p){ p.classList.remove('active'); });
      var panel = document.querySelector('[data-panel="'+tab+'"]');
      if(panel) panel.classList.add('active');
      state.activeTab = tab;

      // Hide bottom bar on gallery
      document.getElementById('pf-bottom-bar').style.display = tab === 'gallery' ? 'none' : '';

      // Load gallery
      if(tab === 'gallery') { loadJobHistory(false); loadGallery(); }
    });
  });

  // Hide bottom bar on gallery tab + load data
  if(state.activeTab === 'gallery') {
    document.getElementById('pf-bottom-bar').style.display = 'none';
    loadJobHistory(false);
    loadGallery();
  }

  /* ═══ Face lock toggle ═══ */
  document.querySelectorAll('.pf-switch').forEach(function(sw){
    sw.addEventListener('click', function(){
      var cb = this.querySelector('input[type=checkbox]');
      cb.checked = !cb.checked;
      this.classList.toggle('checked', cb.checked);
    });
  });

  /* ═══ File Upload Helper ═══ */
  function setupUpload(inputId, previewId, imgId, stateKey, tabKey) {
    var input = document.getElementById(inputId);
    if(!input) return;
    input.addEventListener('change', function(){
      if(!this.files || !this.files[0]) return;
      var file = this.files[0];
      if(file.size > 20 * 1024 * 1024){ alert('File quá lớn (max 20MB)'); return; }

      var reader = new FileReader();
      reader.onload = function(e){
        var preview = document.getElementById(previewId);
        var img = document.getElementById(imgId);
        img.src = e.target.result;
        preview.classList.add('show');

        // Hide dropzone
        var drop = input.closest('.pf-dropzone');
        if(drop) drop.style.display = 'none';
      };
      reader.readAsDataURL(file);

      // Upload via AJAX
      var fd = new FormData();
      fd.append('action', 'bztimg_profile_upload_image');
      fd.append('nonce', NONCE);
      fd.append('image', file);

      fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if(resp.success && resp.data && resp.data.url) {
            if(!state[stateKey]) state[stateKey] = {};
            state[stateKey][tabKey] = resp.data.url;
          }
        });
    });
  }

  /* Setup all upload zones */
  setupUpload('pf-source-input',     'pf-source-preview',     'pf-source-img',     'sourceUrl',    'template-quick');
  setupUpload('pf-adv-source-input', 'pf-adv-source-preview', 'pf-adv-source-img', 'sourceUrl',    'advanced');
  setupUpload('pf-adv-ref-input',    'pf-adv-ref-preview',    'pf-adv-ref-img',    'referenceUrl', 'advanced');
  setupUpload('pf-fp-source-input',  'pf-fp-source-preview',  'pf-fp-source-img',  'sourceUrl',    'free-prompt');
  setupUpload('pf-sc-source-input',  'pf-sc-source-preview',  'pf-sc-source-img',  'sourceUrl',    'style-copy');
  setupUpload('pf-sc-ref-input',     'pf-sc-ref-preview',     'pf-sc-ref-img',     'referenceUrl', 'style-copy');

  /* ═══ Remove buttons ═══ */
  document.getElementById('pf-source-remove').addEventListener('click', function(){
    document.getElementById('pf-source-preview').classList.remove('show');
    document.getElementById('pf-source-drop').style.display = '';
    document.getElementById('pf-source-input').value = '';
    delete state.sourceUrl['template-quick'];
  });

  document.querySelectorAll('.pf-remove-btn[data-target]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var target = this.dataset.target;
      var preview = document.getElementById(target + '-preview');
      var drop = preview.previousElementSibling;
      preview.classList.remove('show');
      if(drop) drop.style.display = '';
      var input = document.getElementById(target + '-input');
      if(input) input.value = '';
    });
  });

  /* ═══ Template Loading ═══ */
  function loadTemplates(category) {
    var grid = document.getElementById('pf-tpl-grid');
    grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">⏳</div><p>Đang tải...</p></div>';

    var url = AJAX_URL + '?action=bztimg_profile_get_templates&nonce=' + NONCE + '&category=' + encodeURIComponent(category || 'all');
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if(!resp.success || !resp.data.templates.length) {
          grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">📷</div><p>Chưa có template nào. Admin hãy thêm template trong WP Admin.</p></div>';
          return;
        }
        grid.innerHTML = '';
        resp.data.templates.forEach(function(tpl){
          var card = document.createElement('div');
          card.className = 'pf-tpl-card';
          card.dataset.id = tpl.id;
          card.dataset.url = tpl.reference_url || tpl.thumbnail_url;
          card.innerHTML = '<img src="' + escHtml(tpl.thumbnail_url) + '" alt="' + escHtml(tpl.title) + '" loading="lazy"><div class="pf-tpl-card-label">' + escHtml(tpl.title) + '</div>';
          card.addEventListener('click', function(){
            document.querySelectorAll('.pf-tpl-card.selected').forEach(function(c){ c.classList.remove('selected'); });
            this.classList.add('selected');
            state.selectedTemplate = { id: tpl.id, url: this.dataset.url };
            state.referenceUrl['template-quick'] = this.dataset.url;
          });
          grid.appendChild(card);
        });
      })
      .catch(function(){
        grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">❌</div><p>Lỗi tải templates</p></div>';
      });
  }

  /* Filter pills */
  document.querySelectorAll('.pf-filter-pill').forEach(function(pill){
    pill.addEventListener('click', function(){
      document.querySelectorAll('.pf-filter-pill').forEach(function(p){ p.classList.remove('active'); });
      this.classList.add('active');
      loadTemplates(this.dataset.category);
    });
  });

  /* Load initial templates */
  loadTemplates('all');



  /* ═══ Strength slider ═══ */
  var strengthSlider = document.getElementById('pf-strength');
  if(strengthSlider) {
    strengthSlider.addEventListener('input', function(){
      document.getElementById('pf-strength-val').textContent = this.value + '%';
    });
  }

  /* ═══ Generate Button ═══ */
  document.getElementById('pf-btn-generate').addEventListener('click', function(){
    if(state.generating) return;

    var tab = state.activeTab;
    var source = state.sourceUrl[tab];
    var reference = state.referenceUrl[tab];
    var count = 1;

    if(!source) {
      alert('Vui lòng tải lên ảnh của bạn trước.');
      return;
    }
    if(tab !== 'free-prompt' && !reference) {
      alert('Vui lòng chọn ảnh phong cách mẫu.');
      return;
    }

    var faceLockId = {
      'template-quick': 'pf-face-lock',
      'advanced': 'pf-adv-face-lock',
      'free-prompt': 'pf-fp-face-lock',
      'style-copy': 'pf-sc-face-lock'
    }[tab];
    var faceLock = document.getElementById(faceLockId) ? document.getElementById(faceLockId).checked : true;

    var prompt = '';
    if(tab === 'free-prompt') {
      prompt = (document.getElementById('pf-fp-prompt') || {}).value || '';
      if(!prompt.trim()) {
        alert('Vui lòng nhập mô tả phong cách.');
        return;
      }
    }

    state.generating = true;
    var btn = document.getElementById('pf-btn-generate');
    var spinner = document.getElementById('pf-spinner');
    var btnText = document.getElementById('pf-btn-text');
    btn.disabled = true;
    spinner.style.display = 'inline-block';
    btnText.textContent = 'Đang tạo...';

    // Show loading cards in results
    var resultsMap = {
      'template-quick': 'pf-results',
      'advanced': 'pf-adv-results',
      'free-prompt': 'pf-fp-results',
      'style-copy': 'pf-sc-results'
    };
    var gridMap = {
      'template-quick': 'pf-results-grid',
      'advanced': 'pf-adv-results-grid',
      'free-prompt': 'pf-fp-results-grid',
      'style-copy': 'pf-sc-results-grid'
    };
    var resultsEl = document.getElementById(resultsMap[tab]);
    var gridEl = document.getElementById(gridMap[tab]);
    if(resultsEl) resultsEl.style.display = '';
    if(gridEl) {
      gridEl.innerHTML = '';
      for(var i = 0; i < count; i++) {
        gridEl.innerHTML += '<div class="pf-loading-card"><div class="pf-loading-shimmer"></div></div>';
      }
    }

    var fd = new FormData();
    fd.append('action', 'bztimg_profile_face_swap');
    fd.append('nonce', NONCE);
    fd.append('source_url', source);
    fd.append('reference_url', reference || '');
    fd.append('face_lock', faceLock ? '1' : '0');
    fd.append('count', count);
    fd.append('tool', tab);
    fd.append('prompt', prompt);

    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        state.generating = false;
        btn.disabled = false;
        spinner.style.display = 'none';
        btnText.textContent = 'Tạo Ảnh ✨';

        if(!resp.success) {
          alert(resp.data && resp.data.message ? resp.data.message : 'Có lỗi xảy ra.');
          if(gridEl) gridEl.innerHTML = '';
          if(resultsEl) resultsEl.style.display = 'none';
          return;
        }

        // Show pending job shimmer cards with job IDs
        var jobIds = [];
        if(gridEl && resp.data.jobs) {
          gridEl.innerHTML = '';
          resp.data.jobs.forEach(function(job){
            jobIds.push(job.job_id);
            gridEl.innerHTML += '<div class="pf-loading-card" data-job="'+job.job_id+'">'
              + '<div class="pf-loading-shimmer"></div>'
              + '<div style="padding:8px;text-align:center;font-size:11px;color:var(--muted-fg);">Job #'+job.job_id+' — Đang xử lý...</div>'
              + '</div>';
          });
        }

        // Show toast dialog
        showToast(
          '✨',
          'Đã tạo lệnh thành công!',
          'Hệ thống đang xử lý ảnh. Kết quả sẽ hiển thị ngay bên dưới ảnh upload khi hoàn tất. Vui lòng chờ trong giây lát...'
        );

        // Scroll results into view
        if(resultsEl) {
          setTimeout(function(){
            resultsEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }, 400);
        }

        // Start polling for job status
        if(jobIds.length) {
          startPolling(tab, jobIds, gridEl);
        }
      })
      .catch(function(){
        state.generating = false;
        btn.disabled = false;
        spinner.style.display = 'none';
        btnText.textContent = 'Tạo Ảnh ✨';
        alert('Lỗi kết nối. Vui lòng thử lại.');
      });
  });

  /* ═══ Toast Dialog ═══ */
  function showToast(icon, title, msg) {
    var overlay = document.getElementById('pf-toast-overlay');
    document.getElementById('pf-toast-icon').textContent = icon;
    document.getElementById('pf-toast-title').textContent = title;
    document.getElementById('pf-toast-msg').textContent = msg;
    overlay.classList.add('show');
  }
  document.getElementById('pf-toast-close').addEventListener('click', function(){
    document.getElementById('pf-toast-overlay').classList.remove('show');
  });

  /* ═══ Job Status Polling ═══ */
  function startPolling(tab, jobIds, gridEl) {
    // Clear previous timer for this tab
    if(state.pollTimers[tab]) {
      clearInterval(state.pollTimers[tab]);
    }

    var pollCount = 0;
    var pendingIds = jobIds.slice(); // copy

    state.pollTimers[tab] = setInterval(function(){
      pollCount++;
      if(pollCount > POLL_MAX || pendingIds.length === 0) {
        clearInterval(state.pollTimers[tab]);
        delete state.pollTimers[tab];
        // Mark remaining as timed-out
        pendingIds.forEach(function(jid){
          var card = gridEl.querySelector('[data-job="'+jid+'"]');
          if(card) {
            card.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted-fg);font-size:12px;">'
              + '⏱️ Job #' + jid + '<br>Đang chờ xử lý...<br><small>Hệ thống sẽ tự hoàn tất. Kiểm tra lại tại Gallery.</small></div>';
          }
        });
        return;
      }

      // Call AJAX to check status
      var url = AJAX_URL + '?action=bztimg_profile_check_jobs&nonce=' + NONCE + '&job_ids=' + encodeURIComponent(pendingIds.join(','));
      fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if(!resp.success || !resp.data.jobs) return;

          resp.data.jobs.forEach(function(job){
            var card = gridEl.querySelector('[data-job="'+job.job_id+'"]');
            if(!card) return;

            if(job.status === 'completed' && job.image_url) {
              // Replace shimmer with actual image
              card.className = 'pf-result-card';
              card.innerHTML = '<img src="'+escHtml(job.image_url)+'" alt="Generated" loading="lazy">'
                + '<div class="pf-result-actions">'
                + '<a class="pf-result-btn primary" href="'+escHtml(job.image_url)+'" download target="_blank">⬇️ Tải về</a>'
                + '<button class="pf-result-btn" onclick="window.open(\''+escHtml(job.image_url)+'\')">🔍 Xem</button>'
                + '<button class="pf-result-btn save-media" onclick="pfSaveToMedia('+job.job_id+',\''+escHtml(job.image_url)+'\',this)">💾 Lưu Media</button>'
                + '<a class="pf-result-btn canva" href="'+escHtml(CANVA_BASE)+'&imageUrl='+encodeURIComponent(job.image_url)+'" target="_blank">🎨 Hậu kỳ</a>'
                + '</div>';
              // Remove from pending list
              var idx = pendingIds.indexOf(job.job_id);
              if(idx > -1) pendingIds.splice(idx, 1);
            } else if(job.status === 'failed') {
              card.innerHTML = '<div style="padding:24px;text-align:center;color:#dc2626;font-size:12px;">'
                + '❌ Job #' + job.job_id + '<br>' + escHtml(job.error || 'Lỗi xử lý') + '</div>';
              var idx2 = pendingIds.indexOf(job.job_id);
              if(idx2 > -1) pendingIds.splice(idx2, 1);
            }
            // still pending → keep shimmer
          });

          // All done → stop polling
          if(pendingIds.length === 0) {
            clearInterval(state.pollTimers[tab]);
            delete state.pollTimers[tab];
          }
        })
        .catch(function(){ /* silent, retry on next interval */ });
    }, POLL_INTERVAL);
  }

  /* ═══ Gallery ═══ */
  var jobHistoryPage = 1;
  var jobHistoryTotal = 0;

  function loadJobHistory(append) {
    var list = document.getElementById('pf-job-list');
    var moreBtn = document.getElementById('pf-job-load-more');

    if(!append) {
      jobHistoryPage = 1;
      list.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">⏳</div><p>Đang tải...</p></div>';
    }

    var url = AJAX_URL + '?action=bztimg_profile_job_history&nonce=' + NONCE + '&page=' + jobHistoryPage;
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if(!resp.success) {
          if(!append) list.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">❌</div><p>Lỗi tải lịch sử</p></div>';
          return;
        }

        jobHistoryTotal = resp.data.total;
        var jobs = resp.data.jobs || [];

        if(!append) list.innerHTML = '';

        if(!jobs.length && !append) {
          list.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">📋</div><p>Chưa có job nào. Hãy tạo ảnh đầu tiên!</p></div>';
          moreBtn.style.display = 'none';
          return;
        }

        jobs.forEach(function(job){
          list.appendChild(buildJobCard(job));
        });

        // Show/hide load more
        var shown = list.querySelectorAll('.pf-job-card').length;
        moreBtn.style.display = shown < jobHistoryTotal ? '' : 'none';

        // Auto-poll pending/processing jobs
        var pendingIds = [];
        jobs.forEach(function(j){
          if(j.status === 'pending' || j.status === 'processing') pendingIds.push(parseInt(j.id));
        });
        if(pendingIds.length) {
          startJobHistoryPolling(pendingIds);
        }
      })
      .catch(function(){
        if(!append) list.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">❌</div><p>Lỗi kết nối</p></div>';
      });
  }

  function buildJobCard(job) {
    var card = document.createElement('div');
    card.className = 'pf-job-card';
    card.dataset.jobId = job.id;

    var statusLabels = { 'completed': '✅ Hoàn tất', 'pending': '⏳ Đang chờ', 'failed': '❌ Lỗi', 'processing': '⚙️ Đang xử lý' };
    var statusClass = job.status || 'pending';
    var statusLabel = statusLabels[statusClass] || ('⏳ ' + job.status);

    // Thumbnail: completed image, or ref_image fallback, or placeholder
    var thumbHtml;
    if(job.status === 'completed' && job.image_url) {
      thumbHtml = '<img class="pf-job-thumb" src="'+escHtml(job.image_url)+'" alt="Result" loading="lazy">';
    } else if(job.ref_image) {
      thumbHtml = '<img class="pf-job-thumb" src="'+escHtml(job.ref_image)+'" alt="Source" loading="lazy" style="opacity:.6;filter:grayscale(30%);">';
    } else {
      thumbHtml = '<div class="pf-job-thumb-placeholder">🖼️</div>';
    }

    // Prompt (truncated)
    var promptText = job.prompt || '';
    if(promptText.length > 80) promptText = promptText.substring(0, 80) + '…';

    // Date formatting
    var dateStr = job.created_at || '';
    if(dateStr) {
      try {
        var d = new Date(dateStr.replace(' ','T'));
        dateStr = d.toLocaleDateString('vi-VN') + ' ' + d.toLocaleTimeString('vi-VN',{hour:'2-digit',minute:'2-digit'});
      } catch(e){}
    }

    var actionsHtml = '';
    if(job.status === 'completed' && job.image_url) {
      actionsHtml = '<a class="pf-job-btn primary" href="'+escHtml(job.image_url)+'" download target="_blank">⬇️ Tải</a>'
        + '<button class="pf-job-btn" onclick="window.open(\''+escHtml(job.image_url)+'\')">🔍 Xem</button>'
        + '<button class="pf-job-btn save-media" onclick="pfSaveToMedia('+job.id+',\''+escHtml(job.image_url)+'\',this)">💾 Lưu Media</button>'
        + '<a class="pf-job-btn canva" href="'+escHtml(CANVA_BASE)+'&imageUrl='+encodeURIComponent(job.image_url)+'" target="_blank">🎨 Hậu kỳ</a>';
    } else if(job.status === 'pending' || job.status === 'failed') {
      actionsHtml = '<button class="pf-job-btn retry" onclick="retryJob('+job.id+',this)">🔄 Thử lại</button>';
    } else if(job.status === 'processing') {
      actionsHtml = '<button class="pf-job-btn" disabled>⚙️ Đang xử lý...</button>';
    }

    var errorHtml = '';
    if(job.status === 'failed' && job.error_message) {
      errorHtml = '<div class="pf-job-error" title="'+escHtml(job.error_message)+'">'+escHtml(job.error_message)+'</div>';
    }

    card.innerHTML = thumbHtml
      + '<div class="pf-job-body">'
      + '<div class="pf-job-meta">'
        + '<span class="pf-job-id">#' + job.id + '</span>'
        + '<span class="pf-job-badge ' + escHtml(statusClass) + '">' + statusLabel + '</span>'
      + '</div>'
      + '<div class="pf-job-date">' + escHtml(dateStr) + '</div>'
      + (promptText ? '<div class="pf-job-prompt" title="'+escHtml(job.prompt || '')+'">' + escHtml(promptText) + '</div>' : '')
      + errorHtml
      + '<div class="pf-job-actions">' + actionsHtml + '</div>'
      + '</div>';

    return card;
  }

  /* ═══ Retry Job ═══ */
  window.retryJob = function(jobId, btnEl) {
    if(btnEl) { btnEl.disabled = true; btnEl.textContent = '⏳ Đang gửi...'; }

    var fd = new FormData();
    fd.append('action', 'bztimg_profile_retry_job');
    fd.append('nonce', NONCE);
    fd.append('job_id', jobId);

    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if(resp.success) {
          showToast('🔄', 'Đã gửi lại lệnh!', 'Job #' + jobId + ' đang được xử lý lại. Vui lòng chờ...');
          // Update the card in-place
          var card = document.querySelector('.pf-job-card[data-job-id="'+jobId+'"]');
          if(card) {
            var badge = card.querySelector('.pf-job-badge');
            if(badge) { badge.className = 'pf-job-badge pending'; badge.textContent = '⏳ Đang chờ'; }
            var acts = card.querySelector('.pf-job-actions');
            if(acts) acts.innerHTML = '<button class="pf-job-btn" disabled>⏳ Đang chờ xử lý...</button>';
            var errEl = card.querySelector('.pf-job-error');
            if(errEl) errEl.remove();
          }
          // Start polling for this retried job
          startJobHistoryPolling([jobId]);
        } else {
          alert(resp.data && resp.data.message ? resp.data.message : 'Lỗi retry.');
          if(btnEl) { btnEl.disabled = false; btnEl.textContent = '🔄 Thử lại'; }
        }
      })
      .catch(function(){
        alert('Lỗi kết nối.');
        if(btnEl) { btnEl.disabled = false; btnEl.textContent = '🔄 Thử lại'; }
      });
  };

  /* ═══ Save to Media ═══ */
  window.pfSaveToMedia = function(jobId, imageUrl, btnEl) {
    if(btnEl) { btnEl.disabled = true; btnEl.textContent = '⏳ Đang lưu...'; }
    var fd = new FormData();
    fd.append('action', 'bztimg_upload_to_media');
    fd.append('nonce', NONCE);
    fd.append('job_id', jobId);
    fd.append('image_url', imageUrl);
    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if(resp.success) {
          if(btnEl) { btnEl.className = 'pf-job-btn save-media saved'; btnEl.textContent = '✅ Đã lưu'; }
          showToast('💾', 'Đã lưu!', 'Ảnh đã được lưu vào Media Library.');
        } else {
          alert(resp.data && resp.data.message ? resp.data.message : 'Lỗi lưu media.');
          if(btnEl) { btnEl.disabled = false; btnEl.textContent = '💾 Lưu Media'; }
        }
      })
      .catch(function(){
        alert('Lỗi kết nối.');
        if(btnEl) { btnEl.disabled = false; btnEl.textContent = '💾 Lưu Media'; }
      });
  };

  /* ═══ Job History Auto-Polling ═══ */
  var jhPollTimer = null;
  var jhPollCount = 0;
  var jhPendingIds = [];

  function startJobHistoryPolling(newIds) {
    // Merge new IDs with existing pending
    newIds.forEach(function(id){
      if(jhPendingIds.indexOf(id) === -1) jhPendingIds.push(id);
    });
    if(!jhPendingIds.length) return;

    // Already running? Let existing timer handle it
    if(jhPollTimer) return;

    jhPollCount = 0;
    jhPollTimer = setInterval(function(){
      jhPollCount++;
      if(jhPollCount > POLL_MAX || !jhPendingIds.length) {
        clearInterval(jhPollTimer);
        jhPollTimer = null;
        return;
      }

      var url = AJAX_URL + '?action=bztimg_profile_check_jobs&nonce=' + NONCE + '&job_ids=' + encodeURIComponent(jhPendingIds.join(','));
      fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if(!resp.success || !resp.data.jobs) return;

          resp.data.jobs.forEach(function(job){
            var card = document.querySelector('.pf-job-card[data-job-id="'+job.job_id+'"]');
            if(!card) return;

            if(job.status === 'completed' && job.image_url) {
              // Update thumbnail
              var thumb = card.querySelector('.pf-job-thumb');
              if(thumb) {
                thumb.src = job.image_url;
                thumb.alt = 'Result';
                thumb.style.opacity = '';
                thumb.style.filter = '';
              } else {
                var placeholder = card.querySelector('.pf-job-thumb-placeholder');
                if(placeholder) {
                  var img = document.createElement('img');
                  img.className = 'pf-job-thumb';
                  img.src = job.image_url;
                  img.alt = 'Result';
                  img.loading = 'lazy';
                  placeholder.parentNode.replaceChild(img, placeholder);
                }
              }
              // Update badge
              var badge = card.querySelector('.pf-job-badge');
              if(badge) { badge.className = 'pf-job-badge completed'; badge.textContent = '✅ Hoàn tất'; }
              // Update actions
              var acts = card.querySelector('.pf-job-actions');
              if(acts) {
                acts.innerHTML = '<a class="pf-job-btn primary" href="'+escHtml(job.image_url)+'" download target="_blank">⬇️ Tải</a>'
                  + '<button class="pf-job-btn" onclick="window.open(\''+escHtml(job.image_url)+'\')">🔍 Xem</button>'
                  + '<button class="pf-job-btn save-media" onclick="pfSaveToMedia('+job.job_id+',\''+escHtml(job.image_url)+'\',this)">💾 Lưu Media</button>'
                  + '<a class="pf-job-btn canva" href="'+escHtml(CANVA_BASE)+'&imageUrl='+encodeURIComponent(job.image_url)+'" target="_blank">🎨 Hậu kỳ</a>';
              }
              // Remove from pending
              var idx = jhPendingIds.indexOf(job.job_id);
              if(idx > -1) jhPendingIds.splice(idx, 1);

            } else if(job.status === 'failed') {
              var badge2 = card.querySelector('.pf-job-badge');
              if(badge2) { badge2.className = 'pf-job-badge failed'; badge2.textContent = '❌ Lỗi'; }
              var acts2 = card.querySelector('.pf-job-actions');
              if(acts2) acts2.innerHTML = '<button class="pf-job-btn retry" onclick="retryJob('+job.job_id+',this)">🔄 Thử lại</button>';
              // Show error
              var body = card.querySelector('.pf-job-body');
              if(body && !card.querySelector('.pf-job-error') && job.error) {
                var errDiv = document.createElement('div');
                errDiv.className = 'pf-job-error';
                errDiv.title = job.error;
                errDiv.textContent = job.error;
                body.querySelector('.pf-job-actions').before(errDiv);
              }
              var idx2 = jhPendingIds.indexOf(job.job_id);
              if(idx2 > -1) jhPendingIds.splice(idx2, 1);
            }
            // still pending/processing → keep polling
          });

          if(!jhPendingIds.length) {
            clearInterval(jhPollTimer);
            jhPollTimer = null;
          }
        })
        .catch(function(){ /* silent retry */ });
    }, POLL_INTERVAL);
  }

  /* ═══ Load More Jobs ═══ */
  document.getElementById('pf-job-load-more').addEventListener('click', function(){
    jobHistoryPage++;
    loadJobHistory(true);
  });

  function loadGallery() {
    var grid = document.getElementById('pf-gallery-grid');
    grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">⏳</div><p>Đang tải...</p></div>';

    var url = AJAX_URL + '?action=bztimg_profile_gallery&nonce=' + NONCE;
    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if(!resp.success || !resp.data.images.length) {
          grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">🖼️</div><p>Chưa có ảnh nào. Hãy tạo ảnh đầu tiên!</p></div>';
          return;
        }
        grid.innerHTML = '';
        resp.data.images.forEach(function(img){
          var item = document.createElement('div');
          item.className = 'pf-gallery-item';
          item.innerHTML = '<img src="'+escHtml(img.image_url)+'" loading="lazy"><div class="pf-gallery-item-info">'+escHtml(img.created_at)+'</div>';
          grid.appendChild(item);
        });
      })
      .catch(function(){
        grid.innerHTML = '<div class="pf-empty"><div class="pf-empty-icon">❌</div><p>Lỗi tải gallery</p></div>';
      });
  }

  /* ═══ Helpers ═══ */
  function escHtml(s) {
    if(!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }

})();
</script>
<!-- Toast Dialog -->
<div class="pf-toast-overlay" id="pf-toast-overlay">
  <div class="pf-toast-box">
    <div class="pf-toast-icon" id="pf-toast-icon">✨</div>
    <div class="pf-toast-title" id="pf-toast-title"></div>
    <div class="pf-toast-msg" id="pf-toast-msg"></div>
    <button class="pf-toast-btn" id="pf-toast-close">Đã hiểu 👍</button>
  </div>
</div>

</body>
</html>
