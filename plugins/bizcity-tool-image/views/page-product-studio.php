<?php
/**
 * BizCity Tool Image — Product Studio V5
 *
 * Three-flow architecture:
 *  1. Product image (user upload) + Model from library (DB via REST)  → composite
 *  2. Product image + User uploads own model image                    → composite
 *  3. Product image + Generate AI character from description          → composite
 *
 * Route: /tool-image/product-studio/
 *
 * @package BizCity_Tool_Image
 * @since   2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();
$active_tool  = isset( $_GET['tool'] ) ? sanitize_key( $_GET['tool'] ) : 'on-hand';
$allowed      = [ 'on-hand', 'apparel-tryon', 'background', 'concept', 'ai-model', 'mockup', 'packaging' ];
if ( ! in_array( $active_tool, $allowed, true ) ) $active_tool = 'on-hand';

// Tool → category_slug map for model library API calls
$tool_category_map = [
    'on-hand'       => 'on-hand',
    'apparel-tryon' => 'apparel-tryon',
    'background'    => 'background',
    'concept'       => 'concepts',
    'ai-model'      => 'ai-model',
];
$model_category = $tool_category_map[ $active_tool ] ?? '';

$credits   = (int) get_user_meta( $user_id, 'bztimg_credits', true ) ?: 100;
$api_base  = rest_url( 'bztool-image/v1' );
$api_nonce = wp_create_nonce( 'wp_rest' );
$ajax_url  = admin_url( 'admin-ajax.php' );
$gen_nonce = wp_create_nonce( 'bztimg_nonce' );
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Studio — BizCity Image AI</title>
<style>
:root {
  --bg:         #ffffff;
  --bg-card:    #ffffff;
  --bg-muted:   #f1f5f9;
  --border:     #e2e8f0;
  --primary:    #7c3aed;
  --primary-10: rgba(124,58,237,.10);
  --primary-fg: #ffffff;
  --accent:     #f8fafc;
  --muted-fg:   #64748b;
  --fg:         #0f172a;
  --destructive:#ef4444;
  --ring:       rgba(124,58,237,.3);
  --radius:     .5rem;
  --shadow-sm:  0 1px 3px rgba(0,0,0,.08);
  --shadow-md:  0 4px 12px rgba(0,0,0,.10);
  --shadow-lg:  0 8px 24px rgba(124,58,237,.14);
  --success:    #16a34a;
  --success-bg: #dcfce7;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg-muted);color:var(--fg);min-height:100vh;line-height:1.5;}

/* Layout */
.ps-wrap{min-height:100vh;background:var(--bg);display:flex;flex-direction:column;}

/* Header */
.ps-header{height:56px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 20px;background:var(--bg);flex-shrink:0;gap:12px;position:sticky;top:0;z-index:50;}
.ps-header-brand{display:flex;align-items:center;gap:8px;}
.ps-header-brand svg{color:var(--primary);}
.ps-header-brand h1{font-size:1rem;font-weight:700;white-space:nowrap;}
.ps-header-badge{font-size:10px;background:var(--primary-10);color:var(--primary);padding:2px 8px;border-radius:999px;font-weight:600;}
.ps-header-right{display:flex;align-items:center;gap:8px;}
.ps-credits-badge{display:flex;align-items:center;gap:5px;padding:4px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px;font-weight:600;}
.ps-credits-badge svg{color:var(--primary);}
.ps-icon-btn{width:32px;height:32px;border:1px solid var(--border);background:var(--bg);border-radius:var(--radius);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--fg);transition:all .15s;}
.ps-icon-btn:hover{background:var(--bg-muted);}

/* Toolbar */
.ps-toolbar{border-bottom:1px solid var(--border);background:var(--bg);padding:0 20px;position:sticky;top:56px;z-index:40;}
.ps-toolbar-inner{display:flex;align-items:center;gap:4px;overflow-x:auto;padding:8px 0;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.ps-toolbar-inner::-webkit-scrollbar{height:0;}
.ps-tool-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--radius);font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:var(--bg);color:var(--fg);white-space:nowrap;transition:all .15s;text-decoration:none;flex-shrink:0;}
.ps-tool-btn:hover{background:var(--bg-muted);}
.ps-tool-btn.active{background:var(--primary);color:var(--primary-fg);border-color:var(--primary);}
.ps-tool-btn.disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}
.ps-badge{display:inline-flex;align-items:center;border-radius:999px;font-size:9px;font-weight:700;padding:1px 5px;margin-left:2px;}
.ps-badge-new{background:#ede9fe;color:#6d28d9;}
.ps-badge-soon{background:var(--bg-muted);color:var(--muted-fg);border:1px solid var(--border);}

/* Body */
.ps-body{display:flex;flex:1;overflow:hidden;height:calc(100vh - 105px);}

/* LEFT PANEL */
.ps-left{width:380px;min-width:320px;border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;flex-shrink:0;}

/* Steps indicator */
.ps-steps{display:flex;align-items:center;gap:0;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-muted);}
.ps-step{display:flex;align-items:center;gap:6px;flex:1;cursor:pointer;}
.ps-step-num{width:22px;height:22px;border-radius:50%;background:var(--border);color:var(--muted-fg);font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
.ps-step.active .ps-step-num{background:var(--primary);color:#fff;}
.ps-step.done .ps-step-num{background:var(--success);color:#fff;}
.ps-step-label{font-size:11px;font-weight:500;color:var(--muted-fg);}
.ps-step.active .ps-step-label{color:var(--fg);font-weight:600;}
.ps-step-sep{width:20px;height:1px;background:var(--border);flex-shrink:0;}

/* Step panels */
.ps-step-panel{display:none;flex-direction:column;overflow-y:auto;flex:1;}
.ps-step-panel.active{display:flex;}

/* Step 1: Product upload */
.ps-upload-area{padding:16px;}
.ps-dropzone{border:2px dashed var(--border);border-radius:12px;padding:28px 16px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.ps-dropzone:hover,.ps-dropzone.dragover{border-color:var(--primary);background:var(--primary-10);}
.ps-dropzone input[type=file]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;z-index:2;}
.ps-dz-icon{width:52px;height:52px;border-radius:50%;background:var(--primary-10);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;}
.ps-dz-title{font-size:14px;font-weight:700;margin-bottom:3px;}
.ps-dz-sub{font-size:12px;color:var(--muted-fg);}
.ps-dz-hint{font-size:10px;color:var(--muted-fg);margin-top:6px;}

/* Product preview */
.ps-product-preview{display:none;}
.ps-product-preview.show{display:block;}
.ps-product-img-wrap{position:relative;border:1px solid var(--border);border-radius:10px;overflow:hidden;background:#f8f8f8;aspect-ratio:1;display:flex;align-items:center;justify-content:center;}
.ps-product-img-wrap img{max-width:100%;max-height:180px;object-fit:contain;}
.ps-remove-img{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.5);color:#fff;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;z-index:3;}

/* Auto remove bg — KEY FEATURE */
.ps-rmbg-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:var(--radius);border:1px solid var(--border);background:var(--bg-muted);margin-top:10px;}
.ps-rmbg-left{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;}
.ps-rmbg-left svg{color:var(--primary);}
.ps-switch{position:relative;display:inline-flex;height:24px;width:44px;cursor:pointer;align-items:center;border-radius:999px;border:2px solid transparent;transition:background .2s;background:var(--border);}
.ps-switch input{clip:rect(0 0 0 0);height:1px;overflow:hidden;position:absolute;white-space:nowrap;width:1px;}
.ps-switch-thumb{pointer-events:none;display:block;height:20px;width:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.2);transition:transform .2s;}
.ps-switch[data-state=checked]{background:var(--primary);}
.ps-switch[data-state=checked] .ps-switch-thumb{transform:translateX(20px);}
.ps-rmbg-status{font-size:11px;color:var(--success);font-weight:500;display:none;margin-top:5px;}
.ps-rmbg-status.show{display:block;}

.ps-btn-next{width:100%;padding:10px;border:none;border-radius:var(--radius);background:var(--primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer;margin-top:12px;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .15s;font-family:inherit;}
.ps-btn-next:hover{opacity:.9;}
.ps-btn-next:disabled{opacity:.5;cursor:not-allowed;}
.ps-btn-secondary{width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--fg);font-size:13px;font-weight:500;cursor:pointer;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .15s;font-family:inherit;}
.ps-btn-secondary:hover{background:var(--bg-muted);}

/* Step 2: Model selection */
.ps-model-area{padding:12px 16px;flex:1;display:flex;flex-direction:column;overflow:hidden;}
.ps-sub-tabs{display:grid;grid-template-columns:repeat(3,1fr);background:var(--bg-muted);border-radius:var(--radius);padding:3px;margin-bottom:12px;flex-shrink:0;}
.ps-sub-tab{padding:6px;text-align:center;border-radius:calc(var(--radius) - 2px);font-size:11px;font-weight:500;cursor:pointer;color:var(--muted-fg);display:flex;align-items:center;justify-content:center;gap:3px;transition:all .15s;}
.ps-sub-tab.active{background:var(--bg);color:var(--fg);box-shadow:var(--shadow-sm);font-weight:600;}

/* Library tab */
.ps-model-lib{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.ps-model-lib-toolbar{display:flex;align-items:center;gap:6px;margin-bottom:8px;flex-shrink:0;flex-wrap:wrap;}
.ps-selection-info{display:flex;align-items:center;gap:6px;flex:1;}
.ps-sel-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;}
.ps-sel-badge.ok{background:var(--bg-muted);color:var(--fg);border:1px solid var(--border);}
.ps-sel-badge.full{background:var(--destructive);color:#fff;}
.ps-filter-pills{display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0;}
.ps-filter-pill{display:inline-flex;align-items:center;border-radius:999px;font-size:10px;font-weight:600;padding:3px 9px;cursor:pointer;border:1px solid var(--border);background:var(--bg);transition:all .15s;}
.ps-filter-pill.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.ps-search{position:relative;margin-bottom:8px;flex-shrink:0;}
.ps-search input{width:100%;padding:7px 10px 7px 30px;border:1px solid var(--border);border-radius:var(--radius);font-size:12px;background:var(--bg);}
.ps-search input:focus{outline:none;border-color:var(--primary);}
.ps-search-icon{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted-fg);pointer-events:none;}
.ps-model-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;overflow-y:auto;flex:1;padding-bottom:4px;}
.ps-model-card{border:1.5px solid var(--border);border-radius:var(--radius);background:var(--bg);overflow:hidden;cursor:pointer;transition:border-color .15s,box-shadow .15s;position:relative;}
.ps-model-card:hover{border-color:var(--primary);box-shadow:var(--shadow-sm);}
.ps-model-card.selected{border-color:var(--primary);box-shadow:0 0 0 2px var(--primary);}
.ps-model-card.disabled-card{opacity:.4;cursor:not-allowed;pointer-events:none;}
.ps-model-img{width:100%;aspect-ratio:4/5;object-fit:cover;display:block;background:var(--bg-muted);}
.ps-model-body{padding:6px 8px;}
.ps-model-body h4{font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ps-model-body p{font-size:9px;color:var(--muted-fg);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ps-model-check{position:absolute;top:6px;right:6px;background:rgba(255,255,255,.9);backdrop-filter:blur(4px);border-radius:5px;padding:3px;}
.ps-checkbox{width:14px;height:14px;border:1.5px solid var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.ps-model-card.selected .ps-checkbox{background:var(--primary);border-color:var(--primary);}
.ps-model-loading{display:flex;align-items:center;justify-content:center;gap:8px;padding:32px;color:var(--muted-fg);font-size:13px;grid-column:1/-1;}
.ps-spinner-sm{width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .6s linear infinite;}

/* AI create tab */
.ps-ai-tab{padding:8px 0;}
.ps-ai-intro{text-align:center;padding:16px;color:var(--muted-fg);}
.ps-ai-intro .ai-icon{font-size:36px;margin-bottom:8px;}
.ps-ai-intro p{font-size:12px;}

/* Upload model tab */
.ps-upload-model-tab{padding:8px 0;}
.ps-upload-model-dropzone{border:2px dashed var(--border);border-radius:10px;padding:20px 12px;text-align:center;cursor:pointer;position:relative;transition:all .2s;}
.ps-upload-model-dropzone:hover{border-color:var(--primary);background:var(--primary-10);}
.ps-upload-model-dropzone input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;}
.ps-model-upload-preview{display:none;margin-top:10px;}
.ps-model-upload-preview.show{display:block;}

/* RIGHT PANEL */
.ps-right{flex:1;overflow-y:auto;padding:20px;background:var(--bg-muted);display:flex;flex-direction:column;gap:12px;min-width:0;}

/* Tool label */
.ps-tool-label{display:flex;align-items:center;gap:8px;margin-bottom:4px;}
.ps-tool-label h2{font-size:15px;font-weight:700;}
.ps-tool-badge{font-size:10px;background:var(--primary-10);color:var(--primary);padding:2px 8px;border-radius:999px;font-weight:600;}

/* Settings card */
.ps-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;}
.ps-card-title{font-size:11px;font-weight:700;color:var(--muted-fg);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;}

.ps-field{margin-bottom:12px;}
.ps-field:last-child{margin-bottom:0;}
.ps-field label{display:block;font-size:11px;font-weight:600;color:var(--muted-fg);margin-bottom:5px;}
.ps-field input,.ps-field textarea,.ps-field select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius);font-size:13px;background:var(--bg);font-family:inherit;}
.ps-field input:focus,.ps-field textarea:focus,.ps-field select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--ring);}
.ps-field textarea{resize:vertical;min-height:60px;}

/* Card radio grid */
.ps-cr-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.ps-cr-grid.col2{grid-template-columns:repeat(2,1fr);}
.ps-cr{padding:8px 6px;border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:all .15s;text-align:center;}
.ps-cr:hover{border-color:var(--primary);background:var(--primary-10);}
.ps-cr.sel{border-color:var(--primary);background:var(--primary-10);color:var(--primary);}
.ps-cr .ic{font-size:18px;margin-bottom:2px;}
.ps-cr .lb{font-size:10px;font-weight:600;line-height:1.2;}

/* Size pills */
.ps-size-pills{display:flex;gap:6px;flex-wrap:wrap;}
.ps-size-pill{padding:5px 12px;border:1.5px solid var(--border);border-radius:7px;font-size:11px;cursor:pointer;font-weight:500;transition:all .15s;display:flex;align-items:center;gap:4px;}
.ps-size-pill.sel{border-color:var(--primary);background:var(--primary-10);color:var(--primary);}

/* Generate CTA */
.ps-gen-cta{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;}
.ps-gen-summary{font-size:12px;color:var(--muted-fg);margin-bottom:12px;line-height:1.6;}
.ps-gen-btn{width:100%;padding:13px;border:none;border-radius:var(--radius);background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;font-family:inherit;}
.ps-gen-btn:hover:not(:disabled){opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-lg);}
.ps-gen-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.ps-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:none;}
.ps-gen-btn.loading .ps-spinner{display:block;}
@keyframes spin{to{transform:rotate(360deg)}}

/* Status bar */
.ps-status{padding:10px 14px;border-radius:var(--radius);font-size:12px;font-weight:500;display:none;align-items:center;gap:8px;}
.ps-status.show{display:flex;}
.ps-status.info{background:#ede9fe;color:#5b21b6;}
.ps-status.success{background:var(--success-bg);color:#166534;}
.ps-status.error{background:#fef2f2;color:#991b1b;}
.ps-status.warning{background:#fefce8;color:#854d0e;}

/* Result grid */
.ps-results-title{font-size:13px;font-weight:700;margin-bottom:10px;}
.ps-result-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ps-result-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.ps-result-card img{width:100%;display:block;border-bottom:1px solid var(--border);}
.ps-result-placeholder{width:100%;aspect-ratio:3/4;background:var(--bg-muted);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;font-size:12px;color:var(--muted-fg);}
.ps-result-actions{display:flex;gap:4px;padding:8px;flex-wrap:wrap;}
.ps-result-btn{flex:1;min-width:50px;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:10px;font-weight:600;cursor:pointer;background:var(--bg);transition:all .15s;text-align:center;text-decoration:none;color:var(--fg);}
.ps-result-btn:hover{border-color:var(--primary);color:var(--primary);}
.ps-result-model-name{font-size:10px;color:var(--muted-fg);padding:0 8px 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:999px;}

/* Responsive */
@media(max-width:900px){
  .ps-body{flex-direction:column;height:auto;}
  .ps-left{width:100%;min-width:0;height:auto;border-right:none;border-bottom:1px solid var(--border);}
  .ps-right{min-height:50vh;}
  .ps-step-panel.active{min-height:300px;}
}

/* Reference images slots */
.ps-ref-slots{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;}
.ps-ref-slot{display:flex;flex-direction:column;gap:4px;}
.ps-ref-slot-label{font-size:10px;font-weight:600;color:var(--muted-fg);text-align:center;}
.ps-ref-slot-upload{border:1.5px dashed var(--border);border-radius:var(--radius);padding:8px 4px;text-align:center;cursor:pointer;background:var(--bg-muted);min-height:72px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:3px;transition:all .15s;position:relative;overflow:hidden;}
.ps-ref-slot-upload:hover{border-color:var(--primary);background:var(--primary-10);}
.ps-ref-slot-upload.has-image{border-style:solid;border-color:var(--primary);padding:0;}
.ps-ref-slot-ph{font-size:18px;line-height:1;}
.ps-ref-slot-ph-txt{font-size:9px;color:var(--muted-fg);}
.ps-ref-slot-img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:calc(var(--radius) - 2px);display:block;}
.ps-ref-slot-rm{display:none;width:100%;padding:3px;font-size:10px;color:var(--destructive);background:rgba(239,68,68,.08);border:none;border-top:1px solid rgba(239,68,68,.15);cursor:pointer;font-family:inherit;border-radius:0 0 calc(var(--radius)-2px) calc(var(--radius)-2px);transition:background .15s;}
.ps-ref-slot-rm:hover{background:rgba(239,68,68,.15);}
.ps-ref-slot-rm.show{display:block;}
.ps-ref-hint{font-size:10px;color:var(--muted-fg);margin-top:4px;line-height:1.4;}
</style>
</head>
<body>
<div class="ps-wrap">

<!-- HEADER -->
<header class="ps-header">
  <div class="ps-header-brand">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle cx="4" cy="20" r="2"></circle></svg>
    <h1>Product Studio</h1>
    <span class="ps-header-badge">V5 Beta</span>
  </div>
  <div class="ps-header-right">
    <div class="ps-credits-badge">
      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
      <span id="ps-credits"><?php echo esc_html( $credits ); ?></span> credits
    </div>
    <button class="ps-icon-btn" title="Lịch sử">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path></svg>
    </button>
  </div>
</header>

<!-- TOOLBAR -->
<div class="ps-toolbar">
  <div class="ps-toolbar-inner">
    <a href="?tool=on-hand" class="ps-tool-btn <?php echo $active_tool === 'on-hand' ? 'active' : ''; ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2"></path><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2"></path><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8"></path><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"></path></svg>
      Tr&#234;n tay s&#7843;n ph&#7849;m
    </a>
    <a href="?tool=apparel-tryon" class="ps-tool-btn <?php echo $active_tool === 'apparel-tryon' ? 'active' : ''; ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.57a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.57a2 2 0 0 0-1.34-2.23z"></path></svg>
      Th&#7917; &#273;&#7891; AI
    </a>
    <a href="?tool=background" class="ps-tool-btn <?php echo $active_tool === 'background' ? 'active' : ''; ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path></svg>
      N&#7873;n s&#7843;n ph&#7849;m
    </a>
    <a href="?tool=concept" class="ps-tool-btn <?php echo $active_tool === 'concept' ? 'active' : ''; ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle cx="4" cy="20" r="2"></circle></svg>
      AI Concept<span class="ps-badge ps-badge-new">New</span>
    </a>
    <a href="?tool=ai-model" class="ps-tool-btn <?php echo $active_tool === 'ai-model' ? 'active' : ''; ?>">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
      AI Model<span class="ps-badge ps-badge-new">New</span>
    </a>
    <span class="ps-tool-btn disabled">
      Mockup<span class="ps-badge ps-badge-soon">S&#7855;p c&#243;</span>
    </span>
    <span class="ps-tool-btn disabled">
      Bao b&#236;<span class="ps-badge ps-badge-soon">S&#7855;p c&#243;</span>
    </span>
  </div>
</div>

<!-- BODY -->
<div class="ps-body">

  <!-- LEFT PANEL -->
  <div class="ps-left">

    <!-- Steps indicator -->
    <div class="ps-steps">
      <div class="ps-step active" id="ps-step-1-ind" onclick="psGoToStep(1)">
        <div class="ps-step-num">1</div>
        <span class="ps-step-label">S&#7843;n ph&#7849;m</span>
      </div>
      <div class="ps-step-sep"></div>
      <div class="ps-step" id="ps-step-2-ind" onclick="psGoToStep(2)">
        <div class="ps-step-num">2</div>
        <span class="ps-step-label">Ng&#432;&#7901;i m&#7851;u</span>
      </div>
      <div class="ps-step-sep"></div>
      <div class="ps-step" id="ps-step-3-ind" onclick="psGoToStep(3)">
        <div class="ps-step-num">3</div>
        <span class="ps-step-label">Thi&#7871;t l&#7853;p</span>
      </div>
    </div>

    <!-- STEP 1: Product upload -->
    <div class="ps-step-panel active" id="ps-panel-1">
      <div class="ps-upload-area">
        <p style="font-size:12px;color:var(--muted-fg);margin-bottom:10px;">Upload &#7843;nh s&#7843;n ph&#7849;m &#273;&#7875; &#273;&#7863;t l&#234;n tay model</p>
        <div class="ps-dropzone" id="ps-dropzone" ondrop="psDrop(event)" ondragover="psDragOver(event)" ondragleave="psDragLeave(event)">
          <input type="file" accept="image/*" id="ps-file-input" onchange="psOnFileSelect(event)">
          <div id="ps-dz-placeholder">
            <div class="ps-dz-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary)"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg>
            </div>
            <p class="ps-dz-title">K&#233;o th&#7843; &#7843;nh v&#224;o &#273;&#226;y</p>
            <p class="ps-dz-sub">ho&#7863;c click &#273;&#7875; ch&#7885;n file</p>
            <p class="ps-dz-hint">JPG, PNG, WEBP &bull; T&#7889;i &#273;a 20MB</p>
          </div>
          <div id="ps-product-preview" class="ps-product-preview">
            <div class="ps-product-img-wrap">
              <img id="ps-product-img" src="" alt="S&#7843;n ph&#7849;m">
              <button type="button" class="ps-remove-img" onclick="psRemoveProduct(event)" title="X&#243;a">&#x2715;</button>
            </div>
            <p style="font-size:10px;color:var(--muted-fg);margin-top:4px;text-align:center;">Nh&#7845;n &#273;&#7875; &#273;&#7893;i &#7843;nh</p>
          </div>
        </div>

        <!-- AUTO REMOVE BACKGROUND — KEY FEATURE -->
        <div class="ps-rmbg-row">
          <div class="ps-rmbg-left">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 21H8a2 2 0 0 1-1.42-.587l-3.994-3.999a2 2 0 0 1 0-2.828l10-10a2 2 0 0 1 2.829 0l5.999 6a2 2 0 0 1 0 2.828L12.834 21"></path><path d="m5.082 11.09 8.828 8.828"></path></svg>
            <label for="ps-rmbg-btn" style="cursor:pointer;">T&#7921; &#273;&#7897;ng x&#243;a n&#7873;n</label>
          </div>
          <button type="button" role="switch" id="ps-rmbg-btn" aria-checked="false"
                  data-state="unchecked" class="ps-switch" onclick="psToggleRmbg(this)">
            <span class="ps-switch-thumb"></span>
          </button>
        </div>
        <p class="ps-rmbg-status" id="ps-rmbg-status">&#10003; &#272;&#227; x&#243;a n&#7873;n s&#7843;n ph&#7849;m</p>

        <button class="ps-btn-next" id="ps-btn-to-step2" onclick="psGoToStep(2)" disabled>
          Ti&#7871;p theo &#8212; Ch&#7885;n ng&#432;&#7901;i m&#7851;u
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
        </button>
        <button class="ps-btn-secondary" onclick="psGoToStep(2)">B&#7887; qua &#8212; Ch&#7885;n m&#7851;u tr&#432;&#7899;c</button>
      </div>
    </div>

    <!-- STEP 2: Model selection -->
    <div class="ps-step-panel" id="ps-panel-2">
      <div class="ps-model-area">
        <div class="ps-sub-tabs">
          <div class="ps-sub-tab active" id="ps-subtab-lib" onclick="psSwitchModelTab('lib')">
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect></svg>
            Th&#432; vi&#7879;n
          </div>
          <div class="ps-sub-tab" id="ps-subtab-ai" onclick="psSwitchModelTab('ai')">
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path></svg>
            T&#7841;o AI
          </div>
          <div class="ps-sub-tab" id="ps-subtab-upload" onclick="psSwitchModelTab('upload')">
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg>
            Upload
          </div>
        </div>

        <!-- Library tab content -->
        <div id="ps-tab-lib-content" class="ps-model-lib">
          <div class="ps-model-lib-toolbar">
            <div class="ps-selection-info">
              <span class="ps-sel-badge ok" id="ps-sel-badge">0/5</span>
              <span style="font-size:11px;color:var(--muted-fg);" id="ps-model-count">&#273;ang t&#7843;i...</span>
            </div>
          </div>
          <div class="ps-filter-pills" id="ps-filter-pills" style="margin-bottom:8px;">
            <span class="ps-filter-pill active" data-filter="all" onclick="psFilter(this,'all')">T&#7845;t c&#7843;</span>
            <span class="ps-filter-pill" data-filter="fashion" onclick="psFilter(this,'fashion')">Fashion</span>
            <span class="ps-filter-pill" data-filter="food" onclick="psFilter(this,'food')">Food</span>
            <span class="ps-filter-pill" data-filter="tech" onclick="psFilter(this,'tech')">Tech</span>
            <span class="ps-filter-pill" data-filter="beauty" onclick="psFilter(this,'beauty')">Beauty</span>
          </div>
          <div class="ps-search">
            <svg class="ps-search-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            <input type="text" id="ps-model-search" placeholder="T&#236;m ki&#7871;m m&#7851;u..." oninput="psSearch(this.value)">
          </div>
          <div class="ps-model-grid" id="ps-model-grid">
            <div class="ps-model-loading"><div class="ps-spinner-sm"></div> &#272;ang t&#7843;i th&#432; vi&#7879;n m&#7851;u...</div>
          </div>
        </div>

        <!-- AI create tab content -->
        <div id="ps-tab-ai-content" style="display:none;" class="ps-ai-tab">
          <div class="ps-ai-intro">
            <div class="ai-icon">&#10024;</div>
            <p style="font-weight:600;margin-bottom:4px;">T&#7841;o m&#7851;u ng&#432;&#7901;i AI tu&#7923; ch&#7881;nh</p>
            <p>M&#244; t&#7843; &#273;&#7863;c &#273;i&#7875;m &#273;&#7875; AI t&#7841;o m&#7851;u ph&#249; h&#7907;p</p>
          </div>
          <div class="ps-field">
            <label>M&#244; t&#7843; m&#7851;u ng&#432;&#7901;i</label>
            <textarea id="ps-ai-desc" placeholder="VD: C&#244; g&#225;i Vi&#7879;t Nam 25 tu&#7893;i, t&#243;c d&#224;i &#273;en, phong c&#225;ch n&#259;ng &#273;&#7897;ng..." rows="3"></textarea>
          </div>
          <div class="ps-cr-grid col2" id="ps-ai-gender" style="margin-bottom:10px;">
            <div class="ps-cr sel" data-value="female" onclick="psSelectCr(this,'ps-ai-gender')"><div class="ic">&#128105;</div><div class="lb">N&#7919;</div></div>
            <div class="ps-cr" data-value="male" onclick="psSelectCr(this,'ps-ai-gender')"><div class="ic">&#128104;</div><div class="lb">Nam</div></div>
          </div>
          <button class="ps-btn-next" onclick="psGenerateAIModel()">&#10024; T&#7841;o m&#7851;u AI</button>
          <div id="ps-ai-model-result" style="display:none;margin-top:10px;"></div>
        </div>

        <!-- Upload model tab content -->
        <div id="ps-tab-upload-content" style="display:none;" class="ps-upload-model-tab">
          <p style="font-size:11px;color:var(--muted-fg);margin-bottom:10px;">Upload &#7843;nh ng&#432;&#7901;i m&#7851;u c&#7911;a b&#7841;n</p>
          <div class="ps-upload-model-dropzone">
            <input type="file" accept="image/*" id="ps-model-upload-input" onchange="psOnModelUpload(event)">
            <div id="ps-model-upload-placeholder">
              <div style="font-size:32px;margin-bottom:8px;">&#128100;</div>
              <p style="font-size:12px;font-weight:600;">Upload &#7843;nh ng&#432;&#7901;i m&#7851;u</p>
              <p style="font-size:11px;color:var(--muted-fg);">JPG, PNG, WEBP</p>
            </div>
          </div>
          <div class="ps-model-upload-preview" id="ps-model-upload-preview">
            <img id="ps-model-uploaded-img" src="" alt="" style="width:100%;border-radius:8px;border:1px solid var(--border);">
            <p style="font-size:10px;color:var(--success);margin-top:4px;">&#10003; &#272;&#227; ch&#7885;n &#7843;nh ng&#432;&#7901;i m&#7851;u</p>
          </div>
        </div>

        <!-- Step 2 next -->
        <div style="padding:10px 0 4px;flex-shrink:0;">
          <button class="ps-btn-next" onclick="psGoToStep(3)">
            Ti&#7871;p theo &#8212; Thi&#7871;t l&#7853;p
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- STEP 3: Summary -->
    <div class="ps-step-panel" id="ps-panel-3">
      <div class="ps-gen-area">
        <p style="font-size:12px;color:var(--muted-fg);margin-bottom:12px;">Xem l&#7841;i l&#7921;a ch&#7885;n tr&#432;&#7899;c khi t&#7841;o &#7843;nh</p>
        <div style="background:var(--bg-muted);border-radius:var(--radius);padding:12px;font-size:12px;line-height:2;" id="ps-summary-panel">
          <div>&#128230; S&#7843;n ph&#7849;m: <strong id="ps-sum-product">Ch&#432;a nh&#7853;p</strong></div>
          <div>&#128100; M&#7851;u ng&#432;&#7901;i: <strong id="ps-sum-models">Ch&#432;a ch&#7885;n</strong></div>
          <div>&#129306; T&#432; th&#7871;: <strong id="ps-sum-pose">C&#7847;m t&#7921; nhi&#234;n</strong></div>
          <div>&#127750; B&#7889;i c&#7843;nh: <strong id="ps-sum-scene">Studio tr&#7855;ng</strong></div>
          <div>&#128208; K&#237;ch th&#432;&#7899;c: <strong id="ps-sum-size">1024&#215;1536</strong></div>
        </div>
        <button class="ps-btn-secondary" onclick="psGoToStep(1)" style="margin-top:10px;">&#8592; Ch&#7881;nh l&#7841;i</button>
      </div>
    </div>

  </div><!-- /.ps-left -->

  <!-- RIGHT PANEL -->
  <div class="ps-right">

    <div class="ps-tool-label">
      <h2><?php
        $tool_labels = [
          'on-hand'       => '&#129306; Tr&#234;n tay s&#7843;n ph&#7849;m',
          'apparel-tryon' => '&#128085; Th&#7917; &#273;&#7891; AI',
          'background'    => '&#128444;&#65039; N&#7873;n s&#7843;n ph&#7849;m',
          'concept'       => '&#128161; AI Concept',
          'ai-model'      => '&#128100; AI Model Studio',
        ];
        echo $tool_labels[ $active_tool ] ?? 'Product Studio';
      ?></h2>
      <span class="ps-tool-badge">AI Generate</span>
    </div>

    <!-- Product info -->
    <div class="ps-card">
      <div class="ps-card-title">Th&#244;ng tin s&#7843;n ph&#7849;m</div>
      <div class="ps-field">
        <label>M&#244; t&#7843; s&#7843;n ph&#7849;m <span style="color:var(--destructive)">*</span></label>
        <input type="text" id="ps-product-desc" placeholder="VD: &#225;o ph&#244;ng tr&#7855;ng in logo BizCity, t&#250;i da &#273;en cao c&#7845;p..." oninput="psUpdateSummary()">
      </div>
      <div class="ps-field" style="margin-bottom:0;">
        <label>Lo&#7841;i s&#7843;n ph&#7849;m</label>
        <div class="ps-cr-grid" id="ps-product-cat">
          <div class="ps-cr sel" data-value="fashion" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#128247;</div><div class="lb">Th&#7901;i trang</div></div>
          <div class="ps-cr" data-value="beauty" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#128132;</div><div class="lb">L&#224;m &#273;&#7865;p</div></div>
          <div class="ps-cr" data-value="food" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#127836;</div><div class="lb">Th&#7921;c ph&#7849;m</div></div>
          <div class="ps-cr" data-value="tech" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#128241;</div><div class="lb">C&#244;ng ngh&#7879;</div></div>
          <div class="ps-cr" data-value="jewelry" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#128141;</div><div class="lb">Trang s&#7913;c</div></div>
          <div class="ps-cr" data-value="lifestyle" onclick="psSelectCr(this,'ps-product-cat')"><div class="ic">&#127873;</div><div class="lb">Lifestyle</div></div>
        </div>
      </div>
    </div>

    <!-- Pose & Scene -->
    <div class="ps-card">
      <div class="ps-card-title">T&#432; th&#7871; &amp; B&#7889;i c&#7843;nh</div>
      <div class="ps-field">
        <label>T&#432; th&#7871; c&#7847;m / D&#225;ng</label>
        <div class="ps-cr-grid" id="ps-pose">
          <div class="ps-cr sel" data-value="natural_hold" data-hint="holding naturally relaxed" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#129306;</div><div class="lb">T&#7921; nhi&#234;n</div></div>
          <div class="ps-cr" data-value="showcase" data-hint="presenting product toward camera" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#127908;</div><div class="lb">Gi&#7899;i thi&#7879;u</div></div>
          <div class="ps-cr" data-value="unboxing" data-hint="unboxing enthusiastically" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#128230;</div><div class="lb">M&#7903; h&#7897;p</div></div>
          <div class="ps-cr" data-value="wearing" data-hint="actively using or wearing" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#10024;</div><div class="lb">&#272;ang d&#249;ng</div></div>
          <div class="ps-cr" data-value="close_up" data-hint="close-up on hands holding product" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#128269;</div><div class="lb">C&#7853;n tay</div></div>
          <div class="ps-cr" data-value="selfie" data-hint="selfie style holding product" onclick="psSelectCr(this,'ps-pose');psUpdateSummary()"><div class="ic">&#129331;</div><div class="lb">Selfie</div></div>
        </div>
      </div>
      <div class="ps-field" style="margin-bottom:0;">
        <label>B&#7889;i c&#7843;nh</label>
        <div class="ps-cr-grid" id="ps-scene">
          <div class="ps-cr sel" data-value="white_studio" data-hint="clean white studio background, professional lighting" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#11036;</div><div class="lb">Studio tr&#7855;ng</div></div>
          <div class="ps-cr" data-value="lifestyle_home" data-hint="cozy home environment, natural daylight" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#127968;</div><div class="lb">T&#7841;i nh&#224;</div></div>
          <div class="ps-cr" data-value="outdoor" data-hint="outdoor, natural greenery, golden hour" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#127807;</div><div class="lb">Ngo&#7841;i c&#7843;nh</div></div>
          <div class="ps-cr" data-value="cafe" data-hint="warm coffee shop ambiance, wooden table" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#9749;</div><div class="lb">C&#224; ph&#234;</div></div>
          <div class="ps-cr" data-value="street" data-hint="trendy street, urban fashion environment" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#127961;&#65039;</div><div class="lb">Ph&#7889;</div></div>
          <div class="ps-cr" data-value="luxury" data-hint="luxury interior, marble, elegant decor" onclick="psSelectCr(this,'ps-scene');psUpdateSummary()"><div class="ic">&#10024;</div><div class="lb">Sang tr&#7885;ng</div></div>
        </div>
      </div>
    </div>

    <!-- Output settings -->
    <div class="ps-card">
      <div class="ps-card-title">T&#7881; l&#7879; &amp; &#272;&#7897; ph&#226;n gi&#7843;i</div>
      <div class="ps-size-pills" id="ps-sizes">
        <span class="ps-size-pill sel" data-value="1024x1536" onclick="psSelectSize(this);psUpdateSummary()">&#128241; 2:3 D&#7885;c</span>
        <span class="ps-size-pill" data-value="1024x1024" onclick="psSelectSize(this);psUpdateSummary()">&#11035; 1:1 Vu&#244;ng</span>
        <span class="ps-size-pill" data-value="768x1344" onclick="psSelectSize(this);psUpdateSummary()">&#128242; 9:16 Story</span>
        <span class="ps-size-pill" data-value="1536x1024" onclick="psSelectSize(this);psUpdateSummary()">&#128438;&#65039; 3:2 Ngang</span>
      </div>
    </div>

    <!-- Custom detail -->
    <div class="ps-card">
      <div class="ps-card-title">Chi ti&#7871;t b&#7893; sung (tu&#7ef3; ch&#7885;n)</div>
      <div class="ps-field" style="margin-bottom:0;">
        <textarea id="ps-custom-detail" rows="2" placeholder="VD: hi&#7879;u &#7913;ng &#225;nh s&#225;ng v&#224;ng, n&#7873;n blur bokeh, t&#244;ng m&#224;u pastel..."></textarea>
      </div>
    </div>

    <!-- Reference images -->
    <div class="ps-card">
      <div class="ps-card-title">&#128444;&#65039; &#7842;nh tham kh&#7843;o (tu&#7ef3; ch&#7885;n)</div>
      <p class="ps-ref-hint">Gh&#233;p nhi&#7873;u &#7843;nh tham kh&#7843;o &#8212; AI s&#7869; k&#7871;t h&#7907;p m&#7851;u ng&#432;&#7901;i, phong c&#225;ch &amp; b&#7889;i c&#7843;nh</p>
      <div class="ps-ref-slots">

        <!-- Slot 1: Model reference -->
        <div class="ps-ref-slot">
          <div class="ps-ref-slot-label">&#128100; M&#7851;u ng&#432;&#7901;i</div>
          <div class="ps-ref-slot-upload" id="ps-ref-model-zone" onclick="document.getElementById('ps-ref-model-input').click()">
            <input type="file" accept="image/*" id="ps-ref-model-input" style="display:none;" onchange="psOnRefUpload('model_ref',event)">
            <div id="ps-ref-model-ph"><div class="ps-ref-slot-ph">&#128100;</div><div class="ps-ref-slot-ph-txt">+ Th&#234;m &#7843;nh</div></div>
            <img id="ps-ref-model-img" src="" alt="" class="ps-ref-slot-img" style="display:none;">
          </div>
          <button class="ps-ref-slot-rm" id="ps-ref-model-rm" onclick="psRemoveRef('model_ref')">&#10005; X&#243;a</button>
        </div>

        <!-- Slot 2: Style reference -->
        <div class="ps-ref-slot">
          <div class="ps-ref-slot-label">&#128248; Phong c&#225;ch</div>
          <div class="ps-ref-slot-upload" id="ps-ref-style-zone" onclick="document.getElementById('ps-ref-style-input').click()">
            <input type="file" accept="image/*" id="ps-ref-style-input" style="display:none;" onchange="psOnRefUpload('style_ref',event)">
            <div id="ps-ref-style-ph"><div class="ps-ref-slot-ph">&#128248;</div><div class="ps-ref-slot-ph-txt">+ Th&#234;m &#7843;nh</div></div>
            <img id="ps-ref-style-img" src="" alt="" class="ps-ref-slot-img" style="display:none;">
          </div>
          <button class="ps-ref-slot-rm" id="ps-ref-style-rm" onclick="psRemoveRef('style_ref')">&#10005; X&#243;a</button>
        </div>

        <!-- Slot 3: Scene reference -->
        <div class="ps-ref-slot">
          <div class="ps-ref-slot-label">&#127750; B&#7889;i c&#7843;nh</div>
          <div class="ps-ref-slot-upload" id="ps-ref-scene-zone" onclick="document.getElementById('ps-ref-scene-input').click()">
            <input type="file" accept="image/*" id="ps-ref-scene-input" style="display:none;" onchange="psOnRefUpload('scene_ref',event)">
            <div id="ps-ref-scene-ph"><div class="ps-ref-slot-ph">&#127750;</div><div class="ps-ref-slot-ph-txt">+ Th&#234;m &#7843;nh</div></div>
            <img id="ps-ref-scene-img" src="" alt="" class="ps-ref-slot-img" style="display:none;">
          </div>
          <button class="ps-ref-slot-rm" id="ps-ref-scene-rm" onclick="psRemoveRef('scene_ref')">&#10005; X&#243;a</button>
        </div>

      </div>
    </div>

    <!-- Generate CTA -->
    <div class="ps-gen-cta">
      <div class="ps-gen-summary" id="ps-gen-summary">
        Ch&#7885;n &#7843;nh s&#7843;n ph&#7849;m v&#224; &#237;t nh&#7845;t 1 ng&#432;&#7901;i m&#7851;u &#273;&#7875; b&#7855;t &#273;&#7847;u t&#7841;o &#7843;nh.
      </div>
      <div class="ps-status" id="ps-status"></div>
      <button class="ps-gen-btn" id="ps-gen-btn" onclick="psGenerate()">
        <div class="ps-spinner" id="ps-spinner"></div>
        <span id="ps-gen-text">&#10728; T&#7841;o &#7843;nh ngay</span>
      </button>
    </div>

    <!-- Results -->
    <div id="ps-results"></div>

  </div><!-- /.ps-right -->

</div><!-- /.ps-body -->
</div><!-- /.ps-wrap -->

<script>
var PS = {
  apiBase:        '<?php echo esc_js( rtrim( $api_base, '/' ) ); ?>',
  ajaxUrl:        '<?php echo esc_js( $ajax_url ); ?>',
  nonce:          '<?php echo esc_js( $api_nonce ); ?>',
  genNonce:       '<?php echo esc_js( $gen_nonce ); ?>',
  toolCategory:   '<?php echo esc_js( $model_category ); ?>',
  activeTool:     '<?php echo esc_js( $active_tool ); ?>',
  maxModels:      5,
  currentStep:    1,
  productB64:     null,
  productFile:    null,
  productCleanB64:null,
  autoRmbg:       false,
  modelTab:       'lib',
  selectedModels: [],
  aiModelB64:     null,
  uploadedModelB64:null,
  referenceImages: {},
  allModels:      []
};

/* Step navigation */
function psGoToStep(n) {
  PS.currentStep = n;
  [1,2,3].forEach(function(i) {
    document.getElementById('ps-panel-' + i).classList.toggle('active', i === n);
    var ind = document.getElementById('ps-step-' + i + '-ind');
    ind.classList.toggle('active', i === n);
    ind.classList.toggle('done', i < n);
  });
  if (n === 3) psUpdateSummary();
}

/* Product image */
function psOnFileSelect(e) { var f = e.target.files[0]; if (f) psHandleProductFile(f); }
function psDrop(e) {
  e.preventDefault();
  document.getElementById('ps-dropzone').classList.remove('dragover');
  var f = e.dataTransfer.files[0];
  if (f && f.type.startsWith('image/')) psHandleProductFile(f);
}
function psDragOver(e) { e.preventDefault(); document.getElementById('ps-dropzone').classList.add('dragover'); }
function psDragLeave()  { document.getElementById('ps-dropzone').classList.remove('dragover'); }

function psHandleProductFile(file) {
  if (file.size > 20 * 1024 * 1024) { psShowStatus('Anh qua 20MB', 'error'); return; }
  PS.productFile = file;
  PS.productCleanB64 = null;
  var reader = new FileReader();
  reader.onload = function(ev) {
    PS.productB64 = ev.target.result;
    document.getElementById('ps-product-img').src = ev.target.result;
    document.getElementById('ps-dz-placeholder').style.display = 'none';
    var prev = document.getElementById('ps-product-preview');
    prev.style.display = 'block'; prev.classList.add('show');
    document.getElementById('ps-btn-to-step2').disabled = false;
    if (PS.autoRmbg) psDoRemoveBg();
  };
  reader.readAsDataURL(file);
}

function psRemoveProduct(e) {
  e.stopPropagation();
  PS.productB64 = PS.productFile = PS.productCleanB64 = null;
  document.getElementById('ps-product-img').src = '';
  document.getElementById('ps-dz-placeholder').style.display = '';
  var prev = document.getElementById('ps-product-preview');
  prev.style.display = 'none'; prev.classList.remove('show');
  document.getElementById('ps-file-input').value = '';
  document.getElementById('ps-btn-to-step2').disabled = true;
  document.getElementById('ps-rmbg-status').classList.remove('show');
}

/* Auto remove bg */
function psToggleRmbg(btn) {
  var checked = btn.getAttribute('data-state') === 'checked';
  btn.setAttribute('data-state', checked ? 'unchecked' : 'checked');
  btn.setAttribute('aria-checked', !checked);
  PS.autoRmbg = !checked;
  if (PS.autoRmbg && PS.productB64) psDoRemoveBg();
  if (!PS.autoRmbg) {
    PS.productCleanB64 = null;
    document.getElementById('ps-rmbg-status').classList.remove('show');
    if (PS.productB64) document.getElementById('ps-product-img').src = PS.productB64;
  }
}

function psDoRemoveBg() {
  if (!PS.productB64) return;
  var status = document.getElementById('ps-rmbg-status');
  status.textContent = 'Dang xoa nen...';
  status.style.color = 'var(--muted-fg)';
  status.classList.add('show');
  var fd = new FormData();
  fd.append('action', 'bztimg_remove_bg');
  fd.append('nonce', PS.genNonce);
  fd.append('image_b64', PS.productB64);
  fetch(PS.ajaxUrl, { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(res) {
      if (res.success && res.data && res.data.image_b64) {
        PS.productCleanB64 = res.data.image_b64;
        document.getElementById('ps-product-img').src = res.data.image_b64;
        status.textContent = 'Da xoa nen san pham';
        status.style.color = 'var(--success)';
      } else {
        status.textContent = 'Xoa nen chua kha dung, dung anh goc';
        status.style.color = 'var(--muted-fg)';
      }
    })
    .catch(function() { status.textContent = 'Khong the xoa nen luc nay'; status.style.color = 'var(--muted-fg)'; });
}

/* Load models from REST API */
function psLoadModels() {
  var grid = document.getElementById('ps-model-grid');
  grid.innerHTML = '<div class="ps-model-loading"><div class="ps-spinner-sm"></div> Dang tai...</div>';
  var cat = PS.toolCategory || 'on-hand';
  var url = PS.apiBase + '/templates?category=' + encodeURIComponent(cat) + '&subcategory=model&per_page=50&status=active';
  fetch(url, { headers:{ 'X-WP-Nonce': PS.nonce } })
    .then(function(r){ return r.json(); })
    .then(function(json) {
      var models = json.templates || [];
      PS.allModels = models;
      document.getElementById('ps-model-count').textContent = models.length + ' mau';
      if (models.length === 0) {
        grid.innerHTML = '<div class="ps-model-loading" style="color:var(--muted-fg);grid-column:1/-1;">Chua co mau trong thu vien.</div>';
        return;
      }
      psRenderModels(models);
    })
    .catch(function(err) {
      grid.innerHTML = '<div class="ps-model-loading" style="color:var(--destructive);">Loi tai thu vien: ' + err.message + '</div>';
    });
}

function psRenderModels(models) {
  var grid = document.getElementById('ps-model-grid');
  var filterEl = document.querySelector('.ps-filter-pill.active');
  var filter = filterEl ? filterEl.getAttribute('data-filter') : 'all';
  var q = (document.getElementById('ps-model-search') ? document.getElementById('ps-model-search').value : '').toLowerCase();
  var filtered = models.filter(function(m) {
    var matchFilter = filter === 'all' || (m.tags || '').indexOf(filter) > -1;
    var matchSearch = !q || (m.title || '').toLowerCase().indexOf(q) > -1 || (m.tags || '').toLowerCase().indexOf(q) > -1;
    return matchFilter && matchSearch;
  });
  document.getElementById('ps-model-count').textContent = filtered.length + ' mau';
  if (filtered.length === 0) {
    grid.innerHTML = '<div class="ps-model-loading" style="color:var(--muted-fg);grid-column:1/-1;">Khong co mau phu hop</div>';
    return;
  }
  grid.innerHTML = filtered.map(function(m) {
    var isSelected = PS.selectedModels.some(function(s){ return s.id == m.id; });
    var isFull     = !isSelected && PS.selectedModels.length >= PS.maxModels;
    var ff = m.form_fields || {};
    var desc = (typeof ff === 'object' && !Array.isArray(ff)) ? (ff.model_description || m.description || '') : (m.description || '');
    var tagStr = (m.tags || '').split(',').slice(0,2).join(' · ');
    var checkIcon = isSelected ? '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg>' : '';
    return '<div class="ps-model-card' + (isSelected?' selected':'') + (isFull?' disabled-card':'') + '" data-id="' + psEsc(String(m.id)) + '" data-title="' + psEsc(m.title) + '" data-desc="' + psEsc(desc) + '" data-thumb="' + psEsc(m.thumbnail_url||'') + '" data-tags="' + psEsc(m.tags||'') + '" onclick="psToggleModel(this)">'
      + '<img class="ps-model-img" src="' + psEsc(m.thumbnail_url||'') + '" alt="' + psEsc(m.title) + '" loading="lazy" onerror="this.style.background=\'var(--bg-muted)\';this.src=\'\';">'
      + '<div class="ps-model-check"><div class="ps-checkbox">' + checkIcon + '</div></div>'
      + '<div class="ps-model-body"><h4>' + psEsc(m.title) + '</h4><p>' + psEsc(tagStr) + '</p></div>'
      + '</div>';
  }).join('');
}

function psToggleModel(card) {
  var id    = card.getAttribute('data-id');
  var title = card.getAttribute('data-title');
  var desc  = card.getAttribute('data-desc');
  var thumb = card.getAttribute('data-thumb');
  var idx   = -1;
  PS.selectedModels.forEach(function(m,i){ if(m.id == id) idx = i; });
  if (idx > -1) {
    PS.selectedModels.splice(idx, 1);
  } else {
    if (PS.selectedModels.length >= PS.maxModels) { psShowStatus('Toi da ' + PS.maxModels + ' mau', 'error'); return; }
    PS.selectedModels.push({ id:id, title:title, desc:desc, thumb:thumb });
  }
  psUpdateSelBadge();
  psRenderModels(PS.allModels);
  psUpdateSummary();
}

function psUpdateSelBadge() {
  var n = PS.selectedModels.length;
  var el = document.getElementById('ps-sel-badge');
  el.textContent = n + '/' + PS.maxModels;
  el.className = 'ps-sel-badge ' + (n >= PS.maxModels ? 'full' : 'ok');
}

function psFilter(pill, filter) {
  document.querySelectorAll('.ps-filter-pill').forEach(function(p){ p.classList.remove('active'); });
  pill.classList.add('active');
  psRenderModels(PS.allModels);
}

function psSearch(q) { psRenderModels(PS.allModels); }

/* Model tab switching */
function psSwitchModelTab(tab) {
  PS.modelTab = tab;
  ['lib','ai','upload'].forEach(function(t) {
    var st = document.getElementById('ps-subtab-' + t);
    var ct = document.getElementById('ps-tab-' + t + '-content');
    if (st) st.classList.toggle('active', t === tab);
    if (ct) ct.style.display = t === tab ? '' : 'none';
  });
}

/* AI model generation */
function psGenerateAIModel() {
  var desc = document.getElementById('ps-ai-desc').value.trim();
  if (!desc) { psShowStatus('Nhap mo ta mau nguoi', 'error'); return; }
  var resultEl = document.getElementById('ps-ai-model-result');
  resultEl.style.display = 'block';
  resultEl.innerHTML = '<div class="ps-model-loading"><div class="ps-spinner-sm"></div> Dang tao mau AI...</div>';
  var genderEl = document.querySelector('#ps-ai-gender .ps-cr.sel');
  var gender = genderEl ? genderEl.getAttribute('data-value') : 'female';
  fetch(PS.apiBase + '/generate', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-WP-Nonce':PS.nonce},
    body: JSON.stringify({ prompt: desc + ', professional model photo, white background, photorealistic', model:'flux-pro', size:'1024x1536', style:'photorealistic' })
  }).then(function(r){ return r.json(); }).then(function(res) {
    var url = (res && (res.image_url || (res.data && res.data.image_url))) || '';
    if (url) {
      PS.aiModelB64 = url;
      PS.selectedModels = [{ id:'ai-generated', title:'AI Model (custom)', desc:desc, thumb:url }];
      psUpdateSelBadge();
      resultEl.innerHTML = '<img src="' + url + '" style="width:100%;border-radius:8px;border:1px solid var(--border);" alt="AI Model"><p style="font-size:10px;color:var(--success);margin-top:4px;">Mau AI da tao xong</p>';
    } else {
      resultEl.innerHTML = '<p style="color:var(--destructive);font-size:12px;">Loi tao mau: ' + ((res && res.message) || 'Unknown') + '</p>';
    }
  }).catch(function(err){ resultEl.innerHTML = '<p style="color:var(--destructive);font-size:12px;">Loi: ' + err.message + '</p>'; });
}

/* Upload own model */
function psOnModelUpload(e) {
  var f = e.target.files[0]; if (!f) return;
  var reader = new FileReader();
  reader.onload = function(ev) {
    PS.uploadedModelB64 = ev.target.result;
    PS.selectedModels = [{ id:'uploaded-model', title:'Mau da upload', desc:'User uploaded model', thumb:ev.target.result }];
    psUpdateSelBadge();
    document.getElementById('ps-model-upload-placeholder').style.display = 'none';
    var prev = document.getElementById('ps-model-upload-preview');
    prev.classList.add('show');
    document.getElementById('ps-model-uploaded-img').src = ev.target.result;
  };
  reader.readAsDataURL(f);
}

/* Reference image slots */
function psOnRefUpload(slot, e) {
  var f = e.target.files[0]; if (!f) return;
  var reader = new FileReader();
  reader.onload = function(ev) {
    PS.referenceImages[slot] = ev.target.result;
    var key = slot.replace('_ref', '');
    var ph   = document.getElementById('ps-ref-' + key + '-ph');
    var img  = document.getElementById('ps-ref-' + key + '-img');
    var zone = document.getElementById('ps-ref-' + key + '-zone');
    var rm   = document.getElementById('ps-ref-' + key + '-rm');
    if (ph)   ph.style.display  = 'none';
    if (img)  { img.src = ev.target.result; img.style.display = 'block'; }
    if (zone) zone.classList.add('has-image');
    if (rm)   rm.classList.add('show');
  };
  reader.readAsDataURL(f);
}

function psRemoveRef(slot) {
  delete PS.referenceImages[slot];
  var key = slot.replace('_ref', '');
  var ph   = document.getElementById('ps-ref-' + key + '-ph');
  var img  = document.getElementById('ps-ref-' + key + '-img');
  var zone = document.getElementById('ps-ref-' + key + '-zone');
  var rm   = document.getElementById('ps-ref-' + key + '-rm');
  var inp  = document.getElementById('ps-ref-' + key + '-input');
  if (ph)   ph.style.display  = '';
  if (img)  { img.src = ''; img.style.display = 'none'; }
  if (zone) zone.classList.remove('has-image');
  if (rm)   rm.classList.remove('show');
  if (inp)  inp.value = '';
}

/* Card radio */
function psSelectCr(card, groupId) {
  document.querySelectorAll('#' + groupId + ' .ps-cr').forEach(function(c){ c.classList.remove('sel'); });
  card.classList.add('sel');
}

/* Size pills */
function psSelectSize(pill) {
  document.querySelectorAll('#ps-sizes .ps-size-pill').forEach(function(p){ p.classList.remove('sel'); });
  pill.classList.add('sel');
}

/* Update summary */
function psUpdateSummary() {
  var desc    = document.getElementById('ps-product-desc').value.trim() || 'Chua nhap';
  var models  = PS.selectedModels.length ? PS.selectedModels.map(function(m){ return m.title; }).join(', ') : 'Chua chon';
  var poseLb  = document.querySelector('#ps-pose .ps-cr.sel .lb'); var pose = poseLb ? poseLb.textContent : 'Tu nhien';
  var sceneLb = document.querySelector('#ps-scene .ps-cr.sel .lb'); var scene = sceneLb ? sceneLb.textContent : 'Studio trang';
  var sizeEl  = document.querySelector('#ps-sizes .ps-size-pill.sel'); var size = sizeEl ? sizeEl.getAttribute('data-value') : '1024x1536';
  document.getElementById('ps-sum-product').textContent = desc;
  document.getElementById('ps-sum-models').textContent  = models;
  document.getElementById('ps-sum-pose').textContent    = pose;
  document.getElementById('ps-sum-scene').textContent   = scene;
  document.getElementById('ps-sum-size').textContent    = size.replace('x', '\xd7');
  var detail = document.getElementById('ps-product-desc').value.trim();
  document.getElementById('ps-gen-summary').textContent = (PS.selectedModels.length > 0 && detail)
    ? PS.selectedModels.length + ' mau \u00b7 ' + size + ' \u00b7 ' + pose
    : 'Vui long chon it nhat 1 mau nguoi va nhap mo ta san pham';
}

/* Generate */
function psGenerate() {
  var productDesc = document.getElementById('ps-product-desc').value.trim();
  if (!productDesc) { psShowStatus('Nhap mo ta san pham truoc', 'error'); return; }
  if (PS.selectedModels.length === 0) { psShowStatus('Chon it nhat 1 mau nguoi', 'error'); return; }

  var poseCard  = document.querySelector('#ps-pose .ps-cr.sel');
  var sceneCard = document.querySelector('#ps-scene .ps-cr.sel');
  var sizeEl    = document.querySelector('#ps-sizes .ps-size-pill.sel');
  var poseHint  = poseCard  ? (poseCard.getAttribute('data-hint')  || 'holding naturally') : 'holding naturally';
  var sceneHint = sceneCard ? (sceneCard.getAttribute('data-hint') || 'clean white studio background') : 'clean white studio background';
  var size      = sizeEl    ? sizeEl.getAttribute('data-value')    : '1024x1536';
  var custom    = document.getElementById('ps-custom-detail').value.trim();
  var refImage  = PS.productCleanB64 || PS.productB64 || null;

  // Uploaded model photo → include as model_ref_image
  var uploadedModel = null;
  if (PS.uploadedModelB64 && PS.selectedModels.some(function(m){ return m.id === 'uploaded-model'; })) {
    uploadedModel = PS.uploadedModelB64;
  }

  // Extra reference images (model face, style, scene)
  var extraRefs = Object.values(PS.referenceImages);

  var hasAnyRef = refImage || uploadedModel || extraRefs.length > 0;
  var genModel  = hasAnyRef ? 'flux-kontext' : 'flux-pro';

  var btn = document.getElementById('ps-gen-btn');
  var spinner = document.getElementById('ps-spinner');
  var btnText = document.getElementById('ps-gen-text');
  btn.disabled = true; btn.classList.add('loading');
  spinner.style.display = 'block';
  btnText.textContent = 'Dang tao anh...';
  psShowStatus('AI dang xu ly ' + PS.selectedModels.length + ' anh...', 'info');

  var jobs = PS.selectedModels.map(function(mObj) {
    var prompt = (mObj.desc || 'a model') + ' is naturally holding ' + productDesc + ' in hand, ' + poseHint + ', ' + sceneHint + ', photorealistic, 8K, product photography, natural lighting, detailed hands, sharp focus on product' + (custom ? ', ' + custom : '');
    var body = { prompt:prompt, model:genModel, size:size, style:'photorealistic' };
    if (refImage) body.ref_image = refImage;
    if (uploadedModel) body.model_ref_image = uploadedModel;
    if (extraRefs.length) body.extra_ref_images = extraRefs;
    return fetch(PS.apiBase + '/generate', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-WP-Nonce':PS.nonce},
      body:JSON.stringify(body)
    }).then(function(r){ return r.json(); }).then(function(json){ return { model:mObj, result:json, ok:true }; })
      .catch(function(err){ return { model:mObj, result:{ message:err.message }, ok:false }; });
  });

  Promise.all(jobs).then(function(results) {
    var resultsEl = document.getElementById('ps-results');
    resultsEl.innerHTML = '<p class="ps-results-title">Ket qua (' + results.length + ' anh)</p>';
    var grid = document.createElement('div');
    grid.className = 'ps-result-grid';
    results.forEach(function(item) {
      var mObj = item.model, result = item.result, ok = item.ok;
      var imgUrl = result ? (result.image_url || (result.data && result.data.image_url) || '') : '';
      var card = document.createElement('div');
      card.className = 'ps-result-card';
      if (imgUrl) {
        card.innerHTML = '<img src="' + imgUrl + '" alt="' + psEsc(mObj.title) + '" loading="lazy">'
          + '<div class="ps-result-model-name">' + psEsc(mObj.title) + '</div>'
          + '<div class="ps-result-actions">'
          + '<a href="' + imgUrl + '" download class="ps-result-btn">&#8595; Tai</a>'
          + '<button class="ps-result-btn" onclick="psReuse(\'' + imgUrl + '\',\'' + psEsc(mObj.title) + '\')">&#9851; Dung lai</button>'
          + '<button class="ps-result-btn" onclick="psEdit(\'' + imgUrl + '\')">&#9998; Sua</button>'
          + '</div>';
      } else {
        card.innerHTML = '<div class="ps-result-placeholder"><div style="font-size:28px;">&#9888;&#65039;</div><div>' + psEsc((result && result.message) || 'Loi tao anh') + '</div></div>'
          + '<div class="ps-result-model-name">' + psEsc(mObj.title) + '</div>';
      }
      grid.appendChild(card);
    });
    resultsEl.appendChild(grid);
    var success = results.filter(function(r){ return r.ok && r.result && (r.result.image_url || (r.result.data && r.result.data.image_url)); }).length;
    psShowStatus('Tao xong ' + success + '/' + results.length + ' anh!', 'success');
  }).finally(function() {
    btn.disabled = false; btn.classList.remove('loading');
    spinner.style.display = 'none';
    btnText.textContent = '&#10728; Tao anh ngay';
  });
}

/* Utilities */
function psShowStatus(msg, type) {
  var el = document.getElementById('ps-status');
  el.textContent = msg;
  el.className = 'ps-status show ' + type;
  if (type !== 'info') setTimeout(function(){ el.classList.remove('show'); }, 5000);
}

function psEdit(url) { window.location.href = '<?php echo esc_js( home_url("tool-image/?tab=editor&src=") ); ?>' + encodeURIComponent(url); }
function psReuse(url, name) { psShowStatus('Da luu "' + name + '" de dung lai', 'success'); }

function psEsc(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* Init */
document.addEventListener('DOMContentLoaded', function() {
  psLoadModels();
  psUpdateSummary();
});
</script>
</body>
</html>
