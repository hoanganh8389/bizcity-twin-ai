<?php
/**
 * BizCity Video Kling - Agent Profile View: /kling-video/
 *
 * PILLAR 1: Profile View — Standalone form + Monitor + Chat fallback
 * Tab 1 (Tao video): Upload anh + prompt + params -> AJAX -> create video directly
 * Tab 2 (Monitor): Live job polling via AJAX
 * Tab 3 (Chat): Guided commands -> postMessage() to parent chat (backup)
 *
 * Pattern follows bizcity-agent-calo page: direct AJAX form, no chat dependency.
 *
 * @package BizCity_Video_Kling
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();

// Get stats
$stats       = null;
$recent_jobs = [];
if ( $is_logged_in && class_exists( 'BizCity_Video_Kling_Database' ) ) {
    global $wpdb;
    $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
    $has_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;

    if ( $has_table ) {
        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d", $user_id ) );
        $done   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status = 'completed'", $user_id ) );
        $active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status IN ('queued','processing')", $user_id ) );
        $stats  = compact( 'total', 'done', 'active' );

        $recent_jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, status, progress, video_url, media_url, attachment_id, model, duration, aspect_ratio, checkpoints, error_message, created_at
             FROM {$jobs_table} WHERE created_by = %d ORDER BY created_at DESC LIMIT 20",
            $user_id
        ), ARRAY_A );

        // Parse checkpoints JSON
        foreach ( $recent_jobs as &$_rj ) {
            $_rj['checkpoints'] = ! empty( $_rj['checkpoints'] ) ? json_decode( $_rj['checkpoints'], true ) : [];
        }
        unset( $_rj );
    }
}

// Active tab from URL
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'create';
// BC: map old slugs to new ones
if ( $active_tab === 'workflow' ) $active_tab = 'canva';
$allowed_tabs = [ 'create', 'canva', 'editor', 'monitor', 'chat', 'settings', 'studio', 'generate' ];
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) $active_tab = 'create';

// Load current settings (for Settings tab)
$cfg_api_key  = get_option( 'bizcity_video_kling_api_key', '' );
$cfg_endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
$cfg_model    = get_option( 'bizcity_video_kling_default_model', '2.6|pro' );
$cfg_duration = get_option( 'bizcity_video_kling_default_duration', 5 );
$cfg_ratio    = get_option( 'bizcity_video_kling_default_aspect_ratio', '9:16' );
$is_admin_user = current_user_can( 'manage_options' );
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Kling - AI Agent</title>
<link rel="stylesheet" href="<?php echo esc_url( BIZCITY_VIDEO_KLING_URL . 'assets/video-studio.css?v=' . BIZCITY_VIDEO_KLING_ASSETS_VERSION ); ?>">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0d1117;font-family:system-ui,-apple-system,sans-serif;color:#e6edf3;line-height:1.5;min-height:100vh;}

/* ── App Container ── */
.bvk-app{max-width:100%;padding:0 0 72px;position:relative;}

/* ── Bottom Nav (dark) ── */
.bvk-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:#161b22;border-top:1px solid #30363d;z-index:100;padding:6px 0 env(safe-area-inset-bottom, 4px);}
.bvk-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;text-decoration:none;color:#8b949e;font-size:10px;font-weight:600;transition:color .2s;}
.bvk-nav-item.active{color:#58a6ff;}
.bvk-nav-icon{font-size:20px;}

/* ── Tab Content ── */
.bvk-tab{display:none;}
.bvk-tab.active{display:block;}

/* ── AIVA Two-Panel (critical inline) ── */
.bvk-aiva{display:grid;grid-template-columns:400px 1fr;min-height:calc(100vh - 60px);background:#0d1117;color:#e6edf3;}
.bvk-aiva-form{background:#161b22;border-right:1px solid #30363d;padding:20px;overflow-y:auto;max-height:calc(100vh - 60px);}
.bvk-aiva-results{padding:40px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;overflow-y:auto;max-height:calc(100vh - 60px);}
.bvk-aiva-header{display:flex;align-items:center;gap:10px;margin-bottom:20px;}
.bvk-aiva-header__title{font-size:16px;font-weight:700;color:#e6edf3;margin:0;}
.bvk-aiva-modes{display:flex;gap:2px;background:#21262d;border-radius:10px;padding:3px;margin-bottom:20px;}
.bvk-aiva-mode{flex:1;padding:10px;border:none;border-radius:8px;background:transparent;color:#8b949e;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;}
.bvk-aiva-mode.active{background:#30363d;color:#e6edf3;}
.bvk-aiva-group{margin-bottom:18px;}
.bvk-aiva-group__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.bvk-aiva-label{display:block;font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:8px;}
.bvk-aiva-group__head .bvk-aiva-label{margin-bottom:0;}
.bvk-aiva-select{width:100%;padding:10px 12px;background:#21262d;border:1px solid #30363d;border-radius:8px;color:#e6edf3;font-size:13px;font-family:inherit;appearance:auto;}
.bvk-aiva-select:focus{outline:none;border-color:#58a6ff;}
.bvk-aiva-select optgroup{background:#21262d;color:#8b949e;}
.bvk-aiva-select option{background:#21262d;color:#e6edf3;}
.bvk-aiva-pills{display:flex;gap:8px;flex-wrap:wrap;}
.bvk-aiva-pill{padding:8px 16px;background:#21262d;border:1px solid #30363d;border-radius:8px;color:#8b949e;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;}
.bvk-aiva-pill input{display:none;}
.bvk-aiva-pill:has(input:checked){background:#1f6feb;border-color:#1f6feb;color:#fff;}
.bvk-aiva-add-scene{background:none;border:none;color:#58a6ff;font-size:13px;font-weight:600;cursor:pointer;padding:0;font-family:inherit;}
.bvk-aiva-dropzone{display:block;border:2px dashed #30363d;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;min-height:100px;background:#0d1117;}
.bvk-aiva-dropzone:hover,.bvk-aiva-dropzone.dragover{border-color:#58a6ff;background:rgba(31,111,235,.08);}
.bvk-aiva-scene-placeholder{color:#8b949e;}
.bvk-aiva-scene-placeholder span{font-size:28px;display:block;margin-bottom:6px;}
.bvk-aiva-scene-placeholder p{font-size:12px;margin:0 0 4px;}
.bvk-aiva-scene-placeholder small{font-size:10px;color:#484f58;display:block;max-width:280px;margin:0 auto;}
.bvk-aiva-scene-preview{position:relative;display:inline-block;}
.bvk-aiva-scene-preview img{max-height:120px;max-width:100%;border-radius:8px;object-fit:contain;}
.bvk-aiva-scene-clear{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.7);color:#f85149;border:none;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.bvk-aiva-scene-progress{height:2px;background:#21262d;border-radius:1px;overflow:hidden;margin-top:6px;display:none;}
.bvk-aiva-scene-progress.active{display:block;}
.bvk-aiva-scene-progress-bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);width:0;transition:width .3s;}
.bvk-aiva-scene{margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #21262d;}
.bvk-aiva-scene:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.bvk-aiva-scene__header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.bvk-aiva-scene__label{font-size:12px;font-weight:700;color:#8b949e;text-transform:uppercase;letter-spacing:.5px;}
.bvk-aiva-scene__remove{background:none;border:none;color:#f85149;font-size:14px;cursor:pointer;padding:2px 6px;border-radius:4px;}
.bvk-aiva-scene-prompt-wrap{margin-top:10px;}
.bvk-aiva-prompt-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.bvk-aiva-prompt-label{font-size:12px;font-weight:600;color:#8b949e;}
.bvk-aiva-textarea{width:100%;padding:12px;background:#0d1117;border:1px solid #30363d;border-radius:10px;color:#e6edf3;font-size:13px;font-family:inherit;resize:vertical;min-height:100px;box-sizing:border-box;}
.bvk-aiva-textarea:focus{outline:none;border-color:#58a6ff;}
.bvk-aiva-textarea::placeholder{color:#484f58;}
.bvk-aiva-prompt-foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px;}
.bvk-aiva-optimize{background:none;border:none;color:#58a6ff;font-size:12px;font-weight:600;cursor:pointer;padding:0;display:flex;align-items:center;gap:4px;font-family:inherit;}
.bvk-aiva-charcount{font-size:11px;color:#484f58;}
.bvk-aiva-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:#8b949e;}
.bvk-aiva-switch input{display:none;}
.bvk-aiva-switch__track{width:36px;height:20px;background:#21262d;border-radius:10px;position:relative;transition:background .2s;flex-shrink:0;}
.bvk-aiva-switch input:checked+.bvk-aiva-switch__track{background:#1f6feb;}
.bvk-aiva-switch__track::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;}
.bvk-aiva-switch input:checked+.bvk-aiva-switch__track::after{transform:translateX(16px);}
.bvk-aiva-cta{margin-top:20px;padding-top:16px;border-top:1px solid #21262d;}
.bvk-aiva-create-btn{width:100%;padding:14px;background:linear-gradient(135deg,#1f6feb,#58a6ff);border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.bvk-aiva-create-btn:hover{opacity:0.9;}
.bvk-aiva-create-btn:disabled{opacity:0.4;cursor:not-allowed;}
.bvk-aiva-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;min-height:400px;color:#8b949e;}
.bvk-aiva-empty__icon{width:80px;height:80px;background:#21262d;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:20px;}
.bvk-aiva-empty h3{font-size:18px;font-weight:700;color:#e6edf3;margin:0 0 8px;}
.bvk-aiva-empty p{font-size:14px;color:#8b949e;margin:0;}
.bvk-aiva-jobs{width:100%;max-width:700px;display:flex;flex-direction:column;gap:12px;}
.bvk-aiva-job{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:14px;}
.bvk-aiva-job .bvk-job-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.bvk-aiva-job .bvk-job-status{padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;}
.bvk-aiva-job .st-queued{background:#21262d;color:#8b949e;}
.bvk-aiva-job .st-processing{background:rgba(31,111,235,.15);color:#58a6ff;}
.bvk-aiva-job .st-completed{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-aiva-job .st-failed{background:rgba(248,81,73,.15);color:#f85149;}
.bvk-aiva-job .bvk-job-prompt{font-size:12px;color:#8b949e;margin-bottom:6px;}
.bvk-aiva-job .bvk-job-meta{font-size:11px;color:#484f58;display:flex;gap:8px;flex-wrap:wrap;}
.bvk-aiva-job .bvk-job-meta a{color:#58a6ff;text-decoration:none;font-weight:600;}
.bvk-aiva-job .bvk-progress{height:3px;background:#21262d;border-radius:2px;margin-top:8px;}
.bvk-aiva-job .bvk-progress-bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);border-radius:2px;transition:width .5s;}
.bvk-aiva-job .bvk-job-actions{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.bvk-aiva-job .bvk-job-act{padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;border:1px solid #30363d;background:#21262d;color:#8b949e;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;}
.bvk-aiva-job .bvk-job-act:hover{border-color:#58a6ff;color:#58a6ff;}
.bvk-aiva-job .bvk-job-act.done{background:rgba(63,185,80,.15);border-color:rgba(63,185,80,.3);color:#3fb950;cursor:default;}
.bvk-aiva .bvk-status{border-radius:8px;font-size:12px;font-weight:600;}
.bvk-aiva .bvk-status.success{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-aiva .bvk-status.error{background:rgba(248,81,73,.15);color:#f85149;}
.bvk-aiva .bvk-status.loading{background:rgba(31,111,235,.15);color:#58a6ff;}
@media(max-width:768px){.bvk-aiva{grid-template-columns:1fr;}.bvk-aiva-form{max-height:none;border-right:none;border-bottom:1px solid #30363d;}.bvk-aiva-results{max-height:none;min-height:300px;padding:20px;}}

/* ── Hero ── */
.bvk-hero{background:linear-gradient(135deg,#1f6feb 0%,#388bfd 50%,#58a6ff 100%);padding:24px 16px;color:#fff;position:relative;overflow:hidden;}
.bvk-hero::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:rgba(255,255,255,0.06);border-radius:50%;}
.bvk-hero-icon{font-size:36px;margin-bottom:4px;}
.bvk-hero h2{font-size:20px;font-weight:800;margin-bottom:2px;}
.bvk-hero p{opacity:0.9;font-size:12px;}
.bvk-hero-stats{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;}
.bvk-hero-stat{background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;}

/* ── Card ── */
.bvk-card{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:20px 16px;margin:12px 12px 0;box-shadow:none;}
.bvk-card h3{font-size:16px;font-weight:700;margin-bottom:12px;color:#e6edf3;}

/* ── Photo Zone ── */
.bvk-photo-zone{display:block;border:2px dashed #30363d;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px;position:relative;overflow:hidden;background:#0d1117;}
.bvk-photo-zone:hover{border-color:#58a6ff;}
.bvk-photo-placeholder{color:#8b949e;}
.bvk-photo-placeholder span{font-size:40px;display:block;margin-bottom:4px;}
.bvk-photo-placeholder p{font-size:13px;margin-bottom:2px;}
.bvk-photo-placeholder small{font-size:11px;color:#d1d5db;}
#bvk-photo-preview{display:none;}
#bvk-photo-preview img{max-width:100%;max-height:200px;border-radius:8px;object-fit:contain;}

/* ── Fields ── */
.bvk-field{margin-bottom:12px;}
.bvk-field label{display:block;font-size:12px;font-weight:600;color:#e6edf3;margin-bottom:4px;}
.bvk-field textarea,.bvk-field input,.bvk-field select{width:100%;padding:10px 12px;border:1px solid #30363d;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;background:#21262d;color:#e6edf3;}
.bvk-field textarea:focus,.bvk-field input:focus,.bvk-field select:focus{outline:none;border-color:#58a6ff;box-shadow:0 0 0 3px rgba(31,111,235,0.15);}
.bvk-field-row{display:flex;gap:8px;}
.bvk-field-row .bvk-field{flex:1;}

/* ── Pills ── */
.bvk-pill-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bvk-pill{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #30363d;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;background:#21262d;color:#8b949e;}
.bvk-pill:has(input:checked){border-color:#1f6feb;background:#1f6feb;color:#fff;}
.bvk-pill input{display:none;}

/* ── Buttons ── */
.bvk-btn-row{display:flex;gap:8px;margin-top:4px;}
.bvk-btn{flex:1;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
.bvk-btn-primary{background:linear-gradient(135deg,#1f6feb,#58a6ff);color:#fff;}
.bvk-btn-primary:hover{opacity:0.9;transform:translateY(-1px);}
.bvk-btn-primary:disabled{opacity:0.4;cursor:not-allowed;transform:none;}
.bvk-btn-secondary{background:#21262d;border:1.5px solid #30363d;color:#8b949e;}
.bvk-btn-secondary:hover{border-color:#58a6ff;color:#58a6ff;}

/* ── Status Message ── */
.bvk-status{margin-top:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600;display:none;}
.bvk-status.success{display:block;background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-status.error{display:block;background:rgba(248,81,73,.15);color:#f85149;}
.bvk-status.loading{display:block;background:rgba(31,111,235,.15);color:#58a6ff;}

/* ── Result Card ── */
.bvk-result{display:none;margin-top:12px;padding:14px;background:rgba(63,185,80,.1);border:1px solid rgba(63,185,80,.3);border-radius:12px;font-size:13px;line-height:1.6;color:#e6edf3;}
.bvk-result.show{display:block;}
.bvk-result h4{font-size:14px;font-weight:700;margin-bottom:6px;color:#3fb950;}

/* ── Monitor ── */
.bvk-section{padding:12px;}
.bvk-section-title{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
.bvk-job-list{display:flex;flex-direction:column;gap:8px;}
.bvk-job{padding:12px;border:1px solid #30363d;border-radius:12px;background:#161b22;}
.bvk-job-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;}
.bvk-job-status{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;}
.st-queued{background:#21262d;color:#8b949e;}
.st-processing{background:rgba(31,111,235,.15);color:#58a6ff;}
.st-completed{background:rgba(63,185,80,.15);color:#3fb950;}
.st-failed{background:rgba(248,81,73,.15);color:#f85149;}
.bvk-job-prompt{font-size:12px;color:#8b949e;line-height:1.4;margin-bottom:4px;}
.bvk-job-meta{display:flex;gap:8px;font-size:11px;color:#484f58;flex-wrap:wrap;align-items:center;}
.bvk-job-meta a{color:#58a6ff;font-weight:600;text-decoration:none;}
.bvk-progress{height:4px;background:#21262d;border-radius:2px;overflow:hidden;margin-top:6px;}
.bvk-progress-bar{height:100%;border-radius:2px;background:linear-gradient(90deg,#1f6feb,#58a6ff);transition:width .5s ease;}

/* ── Chat Commands ── */
.bvk-commands{display:flex;flex-direction:column;gap:10px;padding:0 12px;}
.bvk-cmd{display:flex;align-items:center;gap:14px;background:#161b22;border:1px solid #30363d;border-radius:14px;padding:16px;cursor:pointer;transition:all .2s;text-align:left;width:100%;}
.bvk-cmd:hover{border-color:#58a6ff;box-shadow:0 2px 12px rgba(31,111,235,.12);}
.bvk-cmd.primary{border:2px solid #1f6feb;background:linear-gradient(135deg,#161b22 0%,#0d1117 100%);}
.bvk-cmd-icon{font-size:26px;flex-shrink:0;width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:rgba(31,111,235,0.1);border-radius:10px;}
.bvk-cmd-text h4{font-size:13px;font-weight:700;color:#e6edf3;margin-bottom:1px;}
.bvk-cmd-text p{font-size:11px;color:#8b949e;}
.bvk-tips{margin:14px 12px 0;padding:12px;background:#161b22;border:1px solid #30363d;border-radius:12px;}
.bvk-tips h4{font-size:12px;font-weight:700;color:#e6edf3;margin-bottom:6px;}
.bvk-tip{padding:8px 12px;background:#21262d;border-radius:8px;margin-bottom:4px;font-size:11px;color:#8b949e;cursor:pointer;transition:background .2s;}
.bvk-tip:hover{background:#30363d;}

/* ── Login ── */
.bvk-login{text-align:center;padding:80px 20px;}
.bvk-login-icon{font-size:48px;margin-bottom:12px;}
.bvk-login h2{font-size:22px;margin-bottom:6px;color:#e6edf3;}
.bvk-login p{color:#8b949e;font-size:14px;}

/* ── Empty ── */
.bvk-empty{text-align:center;padding:32px 16px;color:#8b949e;font-size:13px;}
.bvk-empty-icon{font-size:36px;margin-bottom:8px;}

/* ── Upload indicator ── */
.bvk-upload-bar{height:3px;background:#21262d;border-radius:2px;margin-top:6px;overflow:hidden;display:none;}
.bvk-upload-bar.active{display:block;}
.bvk-upload-bar-fill{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);width:0;transition:width .3s;}

/* ── Console Log ── */
.bvk-console{background:#0f172a;border-radius:12px;margin:12px;padding:12px;max-height:200px;overflow-y:auto;font-family:'Cascadia Code','Fira Code',monospace;font-size:11px;line-height:1.7;color:#94a3b8;}
.bvk-console::-webkit-scrollbar{width:4px;}
.bvk-console::-webkit-scrollbar-thumb{background:#334155;border-radius:2px;}
.bvk-log-line{display:block;}
.bvk-log-time{color:#64748b;}
.bvk-log-ok{color:#4ade80;}
.bvk-log-warn{color:#fbbf24;}
.bvk-log-err{color:#f87171;}
.bvk-log-info{color:#60a5fa;}

/* ── Job Actions ── */
.bvk-job-actions{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.bvk-job-act{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid #30363d;background:#21262d;color:#8b949e;transition:all .2s;font-family:inherit;}
.bvk-job-act:hover{border-color:#58a6ff;color:#58a6ff;}
.bvk-job-act:disabled{opacity:0.4;cursor:not-allowed;}
.bvk-job-act.done{background:rgba(63,185,80,.15);border-color:rgba(63,185,80,.3);color:#3fb950;cursor:default;}

/* ── Pipeline Steps ── */
.bvk-pipeline{display:flex;gap:4px;margin-top:8px;flex-wrap:wrap;}
.bvk-step{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#21262d;color:#8b949e;}
.bvk-step.done{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-step.active{background:rgba(31,111,235,.15);color:#58a6ff;animation:bvk-pulse 1.5s infinite;}
@keyframes bvk-pulse{0%,100%{opacity:1;}50%{opacity:0.6;}}

/* ── Monitor Header ── */
.bvk-monitor-bar{display:flex;gap:8px;padding:8px 12px;align-items:center;flex-wrap:wrap;}
.bvk-monitor-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;}
.bvk-badge-active{background:rgba(31,111,235,.15);color:#58a6ff;}
.bvk-badge-done{background:rgba(63,185,80,.15);color:#3fb950;}
.bvk-badge-total{background:#21262d;color:#8b949e;}
</style>
</head>
<body>

<div class="bvk-app">

<?php if ( ! $is_logged_in ): ?>
<div class="bvk-login">
    <div class="bvk-login-icon"><?php echo "\xF0\x9F\x8E\xAC"; ?></div>
    <h2>Video B-Roll AI</h2>
    <p><?php echo "\xC4\x90\xC4\x83ng nh\xE1\xBA\xADp \xC4\x91\xE1\xBB\x83 t\xE1\xBA\xA1o video TikTok/Reels b\xE1\xBA\xB1ng AI."; ?></p>
</div>
<?php else: ?>

<!-- ═══ NAV (bottom bar) ═══ -->
<nav class="bvk-nav">
    <a href="?tab=create" class="bvk-nav-item<?php echo $active_tab === 'create' ? ' active' : ''; ?>" data-tab="create">
        <span class="bvk-nav-icon"><?php echo "\xF0\x9F\x8E\xAC"; ?></span><span><?php echo "T\xE1\xBA\xA1o video"; ?></span>
    </a>
    <a href="?tab=canva" class="bvk-nav-item<?php echo $active_tab === 'canva' ? ' active' : ''; ?>" data-tab="canva">
        <span class="bvk-nav-icon"><?php echo "\xF0\x9F\x8E\xA8"; ?></span><span>Canva</span>
    </a>
    <a href="?tab=editor" class="bvk-nav-item<?php echo $active_tab === 'editor' ? ' active' : ''; ?>" data-tab="editor">
        <span class="bvk-nav-icon"><?php echo "\xF0\x9F\x8E\x9E"; ?></span><span><?php echo "H\xE1\xBA\xADu k\xE1\xBB\xB3"; ?></span>
    </a>
    <a href="?tab=monitor" class="bvk-nav-item<?php echo $active_tab === 'monitor' ? ' active' : ''; ?>" data-tab="monitor">
        <span class="bvk-nav-icon"><?php echo "\xF0\x9F\x93\x8A"; ?></span><span>Monitor</span>
    </a>
    <a href="?tab=chat" class="bvk-nav-item<?php echo $active_tab === 'chat' ? ' active' : ''; ?>" data-tab="chat">
        <span class="bvk-nav-icon"><?php echo "\xF0\x9F\x92\xAC"; ?></span><span>Chat</span>
    </a>
    <a href="?tab=settings" class="bvk-nav-item<?php echo $active_tab === 'settings' ? ' active' : ''; ?>" data-tab="settings">
        <span class="bvk-nav-icon"><?php echo "\xE2\x9A\x99"; ?></span><span><?php echo "C\xC3\xA0i \xC4\x91\xE1\xBA\xB7t"; ?></span>
    </a>
</nav>

<!-- ═══════════════════════════════════════════════
     TAB 1: CREATE VIDEO (Legacy — hidden)
     ═══════════════════════════════════════════════ -->
<div style="display:none!important" id="bvk-tab-create-legacy">

    <!-- Hero -->
    <div class="bvk-hero">
        <div class="bvk-hero-icon"><?php echo "\xF0\x9F\x8E\xAC"; ?></div>
        <h2>Video nền B-roll AI</h2>
        <p><?php echo "T\xE1\xBA\xA1o video TikTok/Reels t\xE1\xBB\xAB \xE1\xBA\xA3nh + prompt b\xE1\xBA\xB1ng Kling, Veo3, Sora, SeeDance AI"; ?></p>
        <?php if ( $stats ): ?>
        <div class="bvk-hero-stats">
            <div class="bvk-hero-stat"><?php echo "\xF0\x9F\x8E\xAC " . $stats['total'] . ' video'; ?></div>
            <div class="bvk-hero-stat"><?php echo "\xE2\x9C\x85 " . $stats['done'] . " ho\xC3\xA0n th\xC3\xA0nh"; ?></div>
            <?php if ( $stats['active'] > 0 ): ?>
            <div class="bvk-hero-stat"><?php echo "\xE2\x8F\xB3 " . $stats['active'] . " \xC4\x91ang x\xE1\xBB\xAD l\xC3\xBD"; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Create Form -->
    <div class="bvk-card">
        <h3><?php echo "\xF0\x9F\x8E\xAC T\xE1\xBA\xA1o video m\xE1\xBB\x9Bi"; ?></h3>

        <!-- Hidden file input -->
        <input type="file" id="bvk-photo-input" accept="image/*" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0">

        <!-- Photo upload zone -->
        <label class="bvk-photo-zone" id="bvk-photo-zone" for="bvk-photo-input">
            <div id="bvk-photo-preview">
                <img id="bvk-photo-img" alt="">
            </div>
            <div class="bvk-photo-placeholder" id="bvk-photo-placeholder">
                <span><?php echo "\xF0\x9F\x93\xB7"; ?></span>
                <p><?php echo "Ch\xE1\xBB\x8Dn \xE1\xBA\xA3nh g\xE1\xBB\x91c \xC4\x91\xE1\xBB\x83 t\xE1\xBA\xA1o video"; ?></p>
                <small><?php echo "AI s\xE1\xBA\xBD bi\xE1\xBA\xBFn \xE1\xBA\xA3nh th\xC3\xA0nh video \xC4\x91\xE1\xBB\x99ng"; ?></small>
            </div>
        </label>
        <div class="bvk-upload-bar" id="bvk-upload-bar"><div class="bvk-upload-bar-fill" id="bvk-upload-fill"></div></div>

        <input type="hidden" id="bvk-photo-url" value="">

        <!-- Prompt -->
        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\x93\x9D M\xC3\xB4 t\xE1\xBA\xA3 n\xE1\xBB\x99i dung video"; ?></label>
            <textarea id="bvk-prompt" rows="3" placeholder="<?php echo "V\xC3\xAD d\xE1\xBB\xA5: C\xC3\xB4 g\xC3\xA1i \xC4\x91i d\xE1\xBA\xA1o tr\xC3\xAAn b\xC3\xA3i bi\xE1\xBB\x83n ho\xC3\xA0ng h\xC3\xB4n, g\xC3\xB3 th\xE1\xBB\x95i nh\xE1\xBA\xB9 qua t\xC3\xB3c..."; ?>"></textarea>
        </div>

        <!-- Duration pills -->
        <div class="bvk-field">
            <label><?php echo "\xE2\x8F\xB1 Th\xE1\xBB\x9Di l\xC6\xB0\xE1\xBB\xA3ng"; ?></label>
            <div class="bvk-pill-row">
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="5"<?php checked( $cfg_duration ?: 10, 5 ); ?>> 5s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="10"<?php checked( $cfg_duration ?: 10, 10 ); ?>> 10s</label>
            </div>
        </div>

        <!-- Aspect ratio pills -->
        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\x93\x90 T\xE1\xBB\x89 l\xE1\xBB\x87 khung h\xC3\xACnh"; ?></label>
            <div class="bvk-pill-row">
                <label class="bvk-pill"><input type="radio" name="bvk_ratio" value="9:16"<?php checked( $cfg_ratio, '9:16' ); ?>> <?php echo "\xF0\x9F\x93\xB1 D\xE1\xBB\x8Dc TikTok"; ?></label>
                <label class="bvk-pill"><input type="radio" name="bvk_ratio" value="16:9"<?php checked( $cfg_ratio, '16:9' ); ?>> <?php echo "\xF0\x9F\x96\xA5 Ngang YouTube"; ?></label>
                <label class="bvk-pill"><input type="radio" name="bvk_ratio" value="1:1"<?php checked( $cfg_ratio, '1:1' ); ?>> <?php echo "\xE2\xAC\x9C Vu\xC3\xB4ng"; ?></label>
            </div>
        </div>

        <!-- Voiceover (optional) -->
        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\x8E\x99 L\xE1\xBB\x9Di tho\xE1\xBA\xA1i / Voiceover (tu\xE1\xBB\xB3 ch\xE1\xBB\x8Dn)"; ?></label>
            <textarea id="bvk-voiceover" rows="2" placeholder="<?php echo "\xC4\x90\xE1\xBB\x83 tr\xE1\xBB\x91ng n\xE1\xBA\xBFu kh\xC3\xB4ng c\xE1\xBA\xA7n. N\xE1\xBA\xBFu c\xC3\xB3, AI s\xE1\xBA\xBD t\xE1\xBA\xA1o TTS v\xC3\xA0 gh\xC3\xA9p v\xC3\xA0o video."; ?>"></textarea>
        </div>

        <!-- Model select -->
        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\xA4\x96 Model"; ?></label>
            <select id="bvk-model">
                <optgroup label="Kling AI">
                    <option value="2.6|pro"<?php selected( $cfg_model, '2.6|pro' ); ?>>Kling v2.6 Pro (Recommended)</option>
                    <option value="2.6|std"<?php selected( $cfg_model, '2.6|std' ); ?>>Kling v2.6 Standard</option>
                    <option value="2.5|pro"<?php selected( $cfg_model, '2.5|pro' ); ?>>Kling v2.5 Pro</option>
                    <option value="1.6|pro"<?php selected( $cfg_model, '1.6|pro' ); ?>>Kling v1.6 Pro</option>
                </optgroup>
                <optgroup label="SeeDance">
                    <option value="seedance:1.0"<?php selected( $cfg_model, 'seedance:1.0' ); ?>>SeeDance v1.0</option>
                </optgroup>
                <optgroup label="Sora (OpenAI)">
                    <option value="sora:v1"<?php selected( $cfg_model, 'sora:v1' ); ?>>Sora v1</option>
                </optgroup>
                <optgroup label="Veo (Google)">
                    <option value="veo:3"<?php selected( $cfg_model, 'veo:3' ); ?>>Veo 3</option>
                </optgroup>
            </select>
        </div>

        <!-- Action buttons -->
        <div class="bvk-btn-row">
            <button type="button" id="bvk-btn-create" class="bvk-btn bvk-btn-primary"><?php echo "\xF0\x9F\x9A\x80 T\xE1\xBA\xA1o Video"; ?></button>
        </div>

        <div id="bvk-status" class="bvk-status"></div>

        <!-- Result after creation -->
        <div id="bvk-result" class="bvk-result">
            <h4><?php echo "\xE2\x9C\x85 \xC4\x90\xC3\xA3 g\xE1\xBB\xADi y\xC3\xAAu c\xE1\xBA\xA7u!"; ?></h4>
            <div id="bvk-result-body"></div>
        </div>
    </div>

    <!-- Quick tips -->
    <div class="bvk-tips" style="margin:12px;">
        <h4><?php echo "\xF0\x9F\x92\xA1 M\xE1\xBA\xB9o t\xE1\xBA\xA1o video hay"; ?></h4>
        <div class="bvk-tip"><?php echo "\xF0\x9F\x93\xB7 \xE1\xBA\xA2nh ch\xC3\xA2n dung r\xC3\xB5 n\xC3\xA9t cho video \xC4\x91\xE1\xBA\xB9p nh\xE1\xBA\xA5t"; ?></div>
        <div class="bvk-tip"><?php echo "\xE2\x9C\x8D Prompt c\xC3\xA0ng chi ti\xE1\xBA\xBFt, video c\xC3\xA0ng ch\xC3\xADnh x\xC3\xA1c"; ?></div>
        <div class="bvk-tip"><?php echo "\xE2\x8F\xB1 10s l\xC3\xA0 th\xE1\xBB\x9Di l\xC6\xB0\xE1\xBB\xA3ng l\xC3\xBD t\xC6\xB0\xE1\xBB\x9Fng cho TikTok/Reels"; ?></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 1: CREATE VIDEO (AIVA Two-Panel Layout)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'create' ? ' active' : ''; ?>" id="bvk-tab-create">
    <?php include BIZCITY_VIDEO_KLING_DIR . 'views/page-kling-create.php'; ?>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 2: MONITOR (Live Console + Job Tracking)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'monitor' ? ' active' : ''; ?>" id="bvk-tab-monitor">
    <div class="bvk-hero" style="padding:16px;">
        <h2><?php echo "\xF0\x9F\x93\x8A Gi\xC3\xA1m s\xC3\xA1t video"; ?></h2>
        <p><?php echo "Live console — T\xE1\xBB\xB1 \xC4\x91\xE1\xBB\x99ng qu\xC3\xA9t tr\xE1\xBA\xA1ng th\xC3\xA1i, fetch video, upload Media"; ?></p>
    </div>

    <!-- Stats bar -->
    <div class="bvk-monitor-bar" id="bvk-stats-bar">
        <?php if ( $stats ): ?>
        <span class="bvk-monitor-badge bvk-badge-total"><?php echo "\xF0\x9F\x8E\xAC " . $stats['total']; ?></span>
        <span class="bvk-monitor-badge bvk-badge-done"><?php echo "\xE2\x9C\x85 " . $stats['done']; ?></span>
        <?php if ( $stats['active'] > 0 ): ?>
        <span class="bvk-monitor-badge bvk-badge-active"><?php echo "\xE2\x8F\xB3 " . $stats['active'] . " \xC4\x91ang ch\xE1\xBA\xA1y"; ?></span>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="bvk-btn-sm" id="bvk-btn-refresh" style="margin-left:auto;padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:1px solid #30363d;background:#21262d;cursor:pointer;color:#8b949e;"><?php echo "\xF0\x9F\x94\x84 L\xC3\xA0m m\xE1\xBB\x9Bi"; ?></button>
    </div>

    <!-- Live Console -->
    <div class="bvk-console" id="bvk-console">
        <span class="bvk-log-line"><span class="bvk-log-time">[<?php echo current_time( 'H:i:s' ); ?>]</span> <span class="bvk-log-info">Monitor ready. Auto-poll <?php echo $stats && $stats['active'] > 0 ? 'ON' : 'OFF'; ?>.</span></span>
    </div>

    <!-- Job List -->
    <div class="bvk-section">
        <div id="bvk-job-list" class="bvk-job-list">
        <?php if ( empty( $recent_jobs ) ): ?>
            <div class="bvk-empty">
                <div class="bvk-empty-icon"><?php echo "\xF0\x9F\x8E\xAC"; ?></div>
                <p><?php echo "Ch\xC6\xB0\x61 c\xC3\xB3 video n\xC3\xA0o. H\xC3\xA3y t\xE1\xBA\xA1o video m\xE1\xBB\x9Bi!"; ?></p>
            </div>
        <?php else: ?>
            <?php foreach ( $recent_jobs as $job ):
                $st_class  = 'st-' . esc_attr( $job['status'] );
                $st_labels = [ 'draft' => "Nháp", 'queued' => "Đang chờ", 'processing' => "Đang xử lý", 'completed' => "Hoàn thành", 'failed' => "Lỗi" ];
                $video_url = $job['media_url'] ?: $job['video_url'];
                $cp        = $job['checkpoints'] ?: [];
                $model_labels = [
                    '2.6|pro' => 'Kling 2.6 Pro', '2.6|std' => 'Kling 2.6 Std', '2.5|pro' => 'Kling 2.5 Pro', '1.6|pro' => 'Kling 1.6 Pro',
                    'seedance:1.0' => 'SeeDance', 'sora:v1' => 'Sora', 'veo:3' => 'Veo 3',
                ];
                $model_label = $model_labels[ $job['model'] ?? '' ] ?? ( $job['model'] ?: 'Kling' );
            ?>
            <div class="bvk-job" data-job-id="<?php echo (int) $job['id']; ?>" data-status="<?php echo esc_attr( $job['status'] ); ?>">
                <div class="bvk-job-top">
                    <span class="bvk-job-status <?php echo $st_class; ?>"><?php echo $st_labels[ $job['status'] ] ?? $job['status']; ?></span>
                    <span style="font-size:10px;color:#6366f1;font-weight:600;background:#eef2ff;padding:1px 6px;border-radius:4px;"><?php echo esc_html( $model_label ); ?></span>
                    <span style="font-size:11px;color:#9ca3af;margin-left:auto;"><?php echo esc_html( $job['created_at'] ); ?></span>
                </div>
                <div class="bvk-job-prompt"><?php echo esc_html( mb_strimwidth( $job['prompt'] ?: 'No prompt', 0, 100, '...' ) ); ?></div>
                <div class="bvk-job-meta">
                    <span><?php echo "⏱ " . esc_html( $job['duration'] ) . 's'; ?></span>
                    <span><?php echo "📐 " . esc_html( $job['aspect_ratio'] ); ?></span>
                    <span>#<?php echo (int) $job['id']; ?></span>
                    <?php if ( $video_url && $job['status'] === 'completed' ): ?>
                    <a href="<?php echo esc_url( $video_url ); ?>" target="_blank">▶ Xem video</a>
                    <?php endif; ?>
                </div>
                <?php if ( $job['status'] !== 'draft' ): ?>
                <div class="bvk-pipeline">
                    <span class="bvk-step done">✅ Submitted</span>
                    <?php
                    $is_done = $job['status'] === 'completed' || ! empty( $cp['video_completed'] );
                    if ( $job['status'] === 'processing' && ! $is_done ): ?>
                    <span class="bvk-step active">⏳ API Processing</span>
                    <?php elseif ( $is_done ): ?>
                    <span class="bvk-step done">✅ API Processing</span>
                    <?php else: ?>
                    <span class="bvk-step">⭕ API Processing</span>
                    <?php endif; ?>
                    <span class="bvk-step<?php echo ( ! empty( $cp['video_completed'] ) || ! empty( $cp['video_fetched'] ) ) ? ' done' : ''; ?>"><?php echo ( ! empty( $cp['video_completed'] ) || ! empty( $cp['video_fetched'] ) ) ? '✅' : '⭕'; ?> Video Fetched</span>
                    <span class="bvk-step<?php echo ( ! empty( $cp['video_fetched'] ) || ! empty( $cp['manual_media_upload'] ) || ! empty( $job['media_url'] ) ) ? ' done' : ''; ?>"><?php echo ( ! empty( $cp['video_fetched'] ) || ! empty( $cp['manual_media_upload'] ) || ! empty( $job['media_url'] ) ) ? '✅' : '⭕'; ?> Media Upload</span>
                    <?php if ( ! empty( $cp['tts_generated'] ) ): ?>
                    <span class="bvk-step done">✅ TTS</span>
                    <?php endif; ?>
                    <?php if ( ! empty( $cp['audio_merged'] ) ): ?>
                    <span class="bvk-step done">✅ FFmpeg</span>
                    <?php endif; ?>
                    <span class="bvk-step<?php echo $job['status'] === 'completed' ? ' done' : ''; ?>"><?php echo $job['status'] === 'completed' ? '✅' : '⭕'; ?> Done</span>
                </div>
                <?php endif; ?>
                <?php if ( in_array( $job['status'], [ 'queued', 'processing' ], true ) ): ?>
                <div class="bvk-progress"><div class="bvk-progress-bar" style="width:<?php echo (int) $job['progress']; ?>%"></div></div>
                <?php endif; ?>
                <?php if ( $job['status'] === 'completed' ): ?>
                <div class="bvk-job-actions">
                    <?php if ( ! empty( $job['media_url'] ) ): ?>
                    <button type="button" class="bvk-job-act done" disabled>✅ Media</button>
                    <?php elseif ( ! empty( $job['video_url'] ) ): ?>
                    <button type="button" class="bvk-job-act" onclick="bvkUploadToMedia(<?php echo (int) $job['id']; ?>, this)">📥 Upload Media</button>
                    <?php endif; ?>
                    <?php if ( $video_url ): ?>
                    <a href="<?php echo esc_url( $video_url ); ?>" download class="bvk-job-act" style="text-decoration:none;">📥 Tải về</a>
                    <a href="<?php echo esc_url( $video_url ); ?>" target="_blank" class="bvk-job-act" style="text-decoration:none;">▶ Xem</a>
                    <button type="button" class="bvk-job-act" onclick="bvkCopyLink('<?php echo esc_js( $video_url ); ?>', this)">🔗 Copy Link</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ( $job['status'] === 'failed' && ! empty( $job['error_message'] ) ): ?>
                <div style="font-size:11px;color:#991b1b;margin-top:6px;">💡 <?php echo esc_html( $job['error_message'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 3: CHAT (Guided Commands → postMessage)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'chat' ? ' active' : ''; ?>" id="bvk-tab-chat">
    <div class="bvk-hero" style="padding:16px;">
        <h2><?php echo "\xF0\x9F\x92\xAC G\xE1\xBB\xADi l\xE1\xBB\x87nh qua Chat"; ?></h2>
        <p><?php echo "Ch\xE1\xBB\x8Dn l\xE1\xBB\x87nh \xC4\x91\xE1\xBB\x83 g\xE1\xBB\xADi v\xE1\xBA\xA0o khung chat v\xE1\xBB\x9Bi AI assistant"; ?></p>
    </div>

    <div style="padding-top:12px;">
        <div class="bvk-commands">
            <button type="button" class="bvk-cmd primary" data-msg="<?php echo "T\xE1\xBA\xA1o video t\xE1\xBB\xAB \xE1\xBA\xA3nh"; ?>">
                <div class="bvk-cmd-icon"><?php echo "\xF0\x9F\x8E\xAC"; ?></div>
                <div class="bvk-cmd-text">
                    <h4><?php echo "T\xE1\xBA\xA1o video t\xE1\xBB\xAB \xE1\xBA\xA3nh"; ?></h4>
                    <p><?php echo "G\xE1\xBB\xADi \xE1\xBA\xA3nh + m\xC3\xB4 t\xE1\xBA\xA3 \xE2\x86\x92 AI t\xE1\xBA\xA1o video"; ?></p>
                </div>
            </button>
            <button type="button" class="bvk-cmd" data-msg="<?php echo "Ki\xE1\xBB\x83m tra tr\xE1\xBA\xA1ng th\xC3\xA1i video"; ?>">
                <div class="bvk-cmd-icon"><?php echo "\xF0\x9F\x93\x8A"; ?></div>
                <div class="bvk-cmd-text">
                    <h4><?php echo "Ki\xE1\xBB\x83m tra tr\xE1\xBA\xA1ng th\xC3\xA1i"; ?></h4>
                    <p><?php echo "Xem ti\xE1\xBA\xBFn tr\xC3\xACnh video \xC4\x91ang x\xE1\xBB\xAD l\xC3\xBD"; ?></p>
                </div>
            </button>
            <button type="button" class="bvk-cmd" data-msg="<?php echo "Danh s\xC3\xA1ch video c\xE1\xBB\xA7a t\xC3\xB4i"; ?>">
                <div class="bvk-cmd-icon"><?php echo "\xF0\x9F\x93\xB9"; ?></div>
                <div class="bvk-cmd-text">
                    <h4><?php echo "Xem danh s\xC3\xA1ch video"; ?></h4>
                    <p><?php echo "Li\xE1\xBB\x87t k\xC3\xAA video \xC4\x91\xC3\xA3 t\xE1\xBA\xA1o g\xE1\xBA\xA7n \xC4\x91\xC3\xA2y"; ?></p>
                </div>
            </button>
        </div>

        <div class="bvk-tips">
            <h4><?php echo "\xF0\x9F\x92\xA1 G\xE1\xBB\xA3i \xC3\xBD prompt"; ?></h4>
            <div class="bvk-tip" data-msg="<?php echo "T\xE1\xBA\xA1o video TikTok t\xE1\xBB\xAB \xE1\xBA\xA3nh c\xC3\xB4 g\xC3\xA1i \xC4\x91i d\xE1\xBA\xA1o b\xC3\xA3i bi\xE1\xBB\x83n, 10s"; ?>"><?php echo "\xF0\x9F\x92\xA1 \"Video c\xC3\xB4 g\xC3\xA1i \xC4\x91i d\xE1\xBA\xA1o b\xC3\xA3i bi\xE1\xBB\x83n, 10s\""; ?></div>
            <div class="bvk-tip" data-msg="<?php echo "T\xE1\xBA\xA1o video qu\xE1\xBA\xA3ng c\xC3\xA1o s\xE1\xBA\xA3n ph\xE1\xBA\xA9m th\xE1\xBB\x9Di trang, d\xE1\xBB\x8Dc 15s"; ?>"><?php echo "\xF0\x9F\x92\xA1 \"Qu\xE1\xBA\xA3ng c\xC3\xA1o th\xE1\xBB\x9Di trang, 15s\""; ?></div>
            <div class="bvk-tip" data-msg="<?php echo "T\xE1\xBA\xA1o video m\xC3\xB3n \xC4\x83n zoom h\xE1\xBA\xA5p d\xE1\xBA\xABn, d\xE1\xBB\x8Dc TikTok"; ?>"><?php echo "\xF0\x9F\x92\xA1 \"M\xC3\xB3n \xC4\x83n zoom h\xE1\xBA\xA5p d\xE1\xBA\xABn, d\xE1\xBB\x8Dc TikTok\""; ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 4: SETTINGS (API Config + Defaults)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'settings' ? ' active' : ''; ?>" id="bvk-tab-settings">
    <div class="bvk-hero" style="padding:16px;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);">
        <h2><?php echo "\xE2\x9A\x99 C\xC3\xA0i \xC4\x91\xE1\xBA\xB7t"; ?></h2>
        <p><?php echo "C\xE1\xBA\xA5u h\xC3\xACnh API v\xC3\xA0 th\xC3\xB4ng s\xE1\xBB\x91 m\xE1\xBA\xB7c \xC4\x91\xE1\xBB\x8Bnh"; ?></p>
    </div>

    <!-- Default Settings (all users) -->
    <div class="bvk-card">
        <h3><?php echo "\xF0\x9F\x8E\x9B M\xE1\xBA\xB7c \xC4\x91\xE1\xBB\x8Bnh t\xE1\xBA\xA1o video"; ?></h3>

        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\xA4\x96 Model m\xE1\xBA\xB7c \xC4\x91\xE1\xBB\x8Bnh"; ?></label>
            <select id="bvk-cfg-model">
                <optgroup label="Kling AI">
                    <option value="2.6|pro"<?php selected( $cfg_model, '2.6|pro' ); ?>>Kling v2.6 Pro (Recommended)</option>
                    <option value="2.6|std"<?php selected( $cfg_model, '2.6|std' ); ?>>Kling v2.6 Standard</option>
                    <option value="2.5|pro"<?php selected( $cfg_model, '2.5|pro' ); ?>>Kling v2.5 Pro</option>
                    <option value="1.6|pro"<?php selected( $cfg_model, '1.6|pro' ); ?>>Kling v1.6 Pro</option>
                </optgroup>
                <optgroup label="SeeDance">
                    <option value="seedance:1.0"<?php selected( $cfg_model, 'seedance:1.0' ); ?>>SeeDance v1.0</option>
                </optgroup>
                <optgroup label="Sora (OpenAI)">
                    <option value="sora:v1"<?php selected( $cfg_model, 'sora:v1' ); ?>>Sora v1</option>
                </optgroup>
                <optgroup label="Veo (Google)">
                    <option value="veo:3"<?php selected( $cfg_model, 'veo:3' ); ?>>Veo 3</option>
                </optgroup>
            </select>
        </div>

        <div class="bvk-field">
            <label><?php echo "\xE2\x8F\xB1 Th\xE1\xBB\x9Di l\xC6\xB0\xE1\xBB\xA3ng m\xE1\xBA\xB7c \xC4\x91\xE1\xBB\x8Bnh (gi\xC3\xA2y)"; ?></label>
            <div class="bvk-pill-row">
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_duration" value="5"<?php checked( $cfg_duration, 5 ); ?>> 5s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_duration" value="10"<?php checked( $cfg_duration, 10 ); ?>> 10s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_duration" value="15"<?php checked( $cfg_duration, 15 ); ?>> 15s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_duration" value="20"<?php checked( $cfg_duration, 20 ); ?>> 20s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_duration" value="30"<?php checked( $cfg_duration, 30 ); ?>> 30s</label>
            </div>
        </div>

        <div class="bvk-field">
            <label><?php echo "\xF0\x9F\x93\x90 T\xE1\xBB\x89 l\xE1\xBB\x87 khung h\xC3\xACnh m\xE1\xBA\xB7c \xC4\x91\xE1\xBB\x8Bnh"; ?></label>
            <div class="bvk-pill-row">
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_ratio" value="9:16"<?php checked( $cfg_ratio, '9:16' ); ?>> <?php echo "\xF0\x9F\x93\xB1 D\xE1\xBB\x8Dc TikTok"; ?></label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_ratio" value="16:9"<?php checked( $cfg_ratio, '16:9' ); ?>> <?php echo "\xF0\x9F\x96\xA5 Ngang YouTube"; ?></label>
                <label class="bvk-pill"><input type="radio" name="bvk_cfg_ratio" value="1:1"<?php checked( $cfg_ratio, '1:1' ); ?>> <?php echo "\xE2\xAC\x9C Vu\xC3\xB4ng"; ?></label>
            </div>
        </div>
    </div>

    <!-- Save button -->
    <div style="padding:12px;">
        <button type="button" id="bvk-btn-save-settings" class="bvk-btn bvk-btn-primary" style="width:100%;"><?php echo "\xF0\x9F\x92\xBE L\xC6\xB0u c\xC3\xA0i \xC4\x91\xE1\xBA\xB7t"; ?></button>
        <div id="bvk-settings-status" class="bvk-status"></div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════
     TAB 5: STUDIO (Effect Gallery)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'studio' ? ' active' : ''; ?>" id="bvk-tab-studio">
    <?php include BIZCITY_VIDEO_KLING_DIR . 'views/page-kling-studio.php'; ?>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 6: GENERATE (Multi-scene from effect)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'generate' ? ' active' : ''; ?>" id="bvk-tab-generate">
    <?php include BIZCITY_VIDEO_KLING_DIR . 'views/page-kling-generate.php'; ?>
</div>

<!-- ═══════════════════════════════════════════════
     TAB 7: VIDEO EDITOR (React Remotion)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'editor' ? ' active' : ''; ?>" id="bvk-tab-editor" style="height:calc(100vh - 56px);">
    <iframe
        id="bvk-editor-frame"
        src="<?php echo esc_url( BIZCITY_VIDEO_KLING_URL . 'assets/video-editor-loader.php' ); ?>"
        style="width:100%;height:100%;border:none;display:block;"
        allow="autoplay; fullscreen"
        loading="lazy"
    ></iframe>
</div>

<!-- ═══════════════════════════════════════════════
     TAB: CANVA (TwitCanva Video Workflow)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'canva' ? ' active' : ''; ?>" id="bvk-tab-canva" style="height:calc(100vh - 56px);display:flex;flex-direction:column;">
    <!-- Canva → Hậu kỳ bridge toolbar -->
    <div id="bvk-canva-toolbar" style="display:flex;align-items:center;gap:8px;padding:6px 12px;background:#161b22;border-bottom:1px solid #30363d;flex-shrink:0;">
        <span style="font-size:12px;color:#8b949e;">Canva Video:</span>
        <button type="button" id="bvk-wf-send-editor" onclick="bvkCanvaSendToEditor()" style="font-size:12px;padding:4px 12px;background:#1f6feb;color:#fff;border:1px solid #388bfd;border-radius:4px;cursor:pointer;">
            <?php echo "\xF0\x9F\x8E\x9E"; ?> <?php echo "G\xE1\xBB\xADi sang H\xE1\xBA\xADu k\xE1\xBB\xB3"; ?>
        </button>
        <span id="bvk-wf-bridge-status" style="font-size:11px;color:#8b949e;"></span>
    </div>
    <?php
    $tc_cfg = array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'bvk_nonce' ),
    );
    $tc_hash = '#wp=' . strtr( base64_encode( wp_json_encode( $tc_cfg ) ), '+/', '-_' );
    $tc_src  = BIZCITY_VIDEO_KLING_URL . 'twitcanva-dist/index.html' . $tc_hash;
    ?>
    <iframe
        id="bvk-canva-frame"
        src="<?php echo esc_url( $tc_src ); ?>"
        style="width:100%;flex:1;border:none;display:block;"
        allow="clipboard-write; clipboard-read; autoplay; fullscreen"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads"
        loading="lazy"
    ></iframe>
</div>
<script>
(function(){
    var iframe = document.getElementById('bvk-canva-frame');
    var cfg = {
        ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
        nonce:   <?php echo wp_json_encode( wp_create_nonce( 'bvk_nonce' ) ); ?>
    };
    iframe.addEventListener('load', function() {
        try {
            iframe.contentWindow.__tcWp = cfg;
        } catch(e) {
            iframe.contentWindow.postMessage({ type: '__tcWp', payload: cfg }, '*');
        }
    });
})();
</script>

<?php endif; // is_logged_in ?>

</div><!-- /.bvk-app -->

<!-- SCRIPTS -->
<script>
(function(){
    'use strict';

    var BVK = {
        ajax_url: '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>',
        nonce:    '<?php echo esc_js( wp_create_nonce( "bvk_nonce" ) ); ?>'
    };

    var isSubmitting = false;

    /* ── Tab Navigation (with URL persistence) ── */
    document.querySelectorAll('.bvk-nav-item').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var tabName = link.getAttribute('data-tab');
            document.querySelectorAll('.bvk-nav-item').forEach(function(l){ l.classList.remove('active'); });
            document.querySelectorAll('.bvk-tab').forEach(function(t){ t.classList.remove('active'); });
            link.classList.add('active');
            var tab = document.getElementById('bvk-tab-' + tabName);
            if (tab) tab.classList.add('active');
            // Update URL so F5 preserves active tab
            var url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            history.replaceState(null, '', url);
        });
    });

    /* ── AIVA Create: Multi-scene management ── */
    var createScenes = document.getElementById('bvk-create-scenes');
    var createAddBtn = document.getElementById('bvk-create-add-scene');

    function getCreateSceneCount() {
        return createScenes ? createScenes.querySelectorAll('.bvk-aiva-scene').length : 0;
    }

    function createNewScene(num) {
        var div = document.createElement('div');
        div.className = 'bvk-aiva-scene';
        div.dataset.scene = num;
        div.innerHTML =
            '<div class="bvk-aiva-scene__header">' +
                '<span class="bvk-aiva-scene__label">Cảnh ' + num + '</span>' +
                '<button type="button" class="bvk-aiva-scene__remove" title="Xóa cảnh">✖</button>' +
            '</div>' +
            '<label class="bvk-aiva-dropzone" data-scene="' + num + '">' +
                '<input type="file" accept="image/*" class="bvk-aiva-scene-file" data-scene="' + num + '" style="display:none">' +
                '<div class="bvk-aiva-scene-preview" style="display:none"><img src="" alt=""><button type="button" class="bvk-aiva-scene-clear" title="Xóa ảnh">✕</button></div>' +
                '<div class="bvk-aiva-scene-placeholder"><span>📤</span><p>Click để tải lên ảnh</p><small>JPG/PNG/WEBP tối đa 10MB</small></div>' +
            '</label>' +
            '<input type="hidden" class="bvk-aiva-scene-url" data-scene="' + num + '" value="">' +
            '<div class="bvk-aiva-scene-progress"><div class="bvk-aiva-scene-progress-bar"></div></div>';
        return div;
    }

    if (createAddBtn && createScenes) {
        createAddBtn.addEventListener('click', function() {
            if (getCreateSceneCount() >= 3) return;
            createScenes.appendChild(createNewScene(getCreateSceneCount() + 1));
            updateAddSceneBtn();
            updateCreateBtn();
        });
    }

    if (createScenes) {
        createScenes.addEventListener('change', function(e) {
            if (e.target.classList.contains('bvk-aiva-scene-file')) {
                aivaUploadScene(e.target.closest('.bvk-aiva-scene'), e.target.files[0]);
            }
        });
        createScenes.addEventListener('click', function(e) {
            if (e.target.classList.contains('bvk-aiva-scene__remove')) {
                var scene = e.target.closest('.bvk-aiva-scene');
                if (scene && getCreateSceneCount() > 1) {
                    scene.remove();
                    renumberCreateScenes();
                    updateAddSceneBtn();
                    updateCreateBtn();
                }
            }
            if (e.target.classList.contains('bvk-aiva-scene-clear')) {
                e.preventDefault();
                e.stopPropagation();
                aivaClearScene(e.target.closest('.bvk-aiva-scene'));
                updateCreateBtn();
            }
        });
        createScenes.addEventListener('dragover', function(e) {
            var dz = e.target.closest('.bvk-aiva-dropzone');
            if (dz) { e.preventDefault(); dz.classList.add('dragover'); }
        });
        createScenes.addEventListener('dragleave', function(e) {
            var dz = e.target.closest('.bvk-aiva-dropzone');
            if (dz) dz.classList.remove('dragover');
        });
        createScenes.addEventListener('drop', function(e) {
            var dz = e.target.closest('.bvk-aiva-dropzone');
            if (!dz) return;
            e.preventDefault();
            dz.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) aivaUploadScene(dz.closest('.bvk-aiva-scene'), e.dataTransfer.files[0]);
        });
    }

    function aivaUploadScene(scene, file) {
        if (!file || !scene) return;
        var preview = scene.querySelector('.bvk-aiva-scene-preview');
        var placeholder = scene.querySelector('.bvk-aiva-scene-placeholder');
        var urlInput = scene.querySelector('.bvk-aiva-scene-url');
        var progressWrap = scene.querySelector('.bvk-aiva-scene-progress');
        var progressBar = scene.querySelector('.bvk-aiva-scene-progress-bar');
        var reader = new FileReader();
        reader.onload = function(ev) {
            preview.querySelector('img').src = ev.target.result;
            preview.style.display = '';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
        var fd = new FormData();
        fd.append('action', 'bvk_upload_photo');
        fd.append('nonce', BVK.nonce);
        fd.append('photo', file);
        progressWrap.classList.add('active');
        progressBar.style.width = '30%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.upload.onprogress = function(ev) { if (ev.lengthComputable) progressBar.style.width = Math.round((ev.loaded / ev.total) * 90) + '%'; };
        xhr.onload = function() {
            progressBar.style.width = '100%';
            setTimeout(function() { progressWrap.classList.remove('active'); progressBar.style.width = '0'; }, 600);
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.data && res.data.url) { urlInput.value = res.data.url; updateCreateBtn(); }
                else { alert('Upload th\u1EA5t b\u1EA1i: ' + (res.data && res.data.message || 'L\u1ED7i')); aivaClearScene(scene); }
            } catch(err) { alert('Upload th\u1EA5t b\u1EA1i.'); aivaClearScene(scene); }
        };
        xhr.onerror = function() { progressWrap.classList.remove('active'); alert('L\u1ED7i k\u1EBFt n\u1ED1i.'); aivaClearScene(scene); };
        xhr.send(fd);
    }

    function aivaClearScene(scene) {
        var preview = scene.querySelector('.bvk-aiva-scene-preview');
        var placeholder = scene.querySelector('.bvk-aiva-scene-placeholder');
        var urlInput = scene.querySelector('.bvk-aiva-scene-url');
        var fileInput = scene.querySelector('.bvk-aiva-scene-file');
        var promptTA = scene.querySelector('.bvk-aiva-scene-prompt');
        var charcount = scene.querySelector('.bvk-aiva-charcount');
        if (preview) { preview.style.display = 'none'; preview.querySelector('img').src = ''; }
        if (placeholder) placeholder.style.display = '';
        if (urlInput) urlInput.value = '';
        if (fileInput) fileInput.value = '';
        if (promptTA) promptTA.value = '';
        if (charcount) charcount.textContent = '0/2000';
    }

    function renumberCreateScenes() {
        if (!createScenes) return;
        createScenes.querySelectorAll('.bvk-aiva-scene').forEach(function(s, i) {
            s.dataset.scene = i + 1;
            var lbl = s.querySelector('.bvk-aiva-scene__label');
            if (lbl) lbl.textContent = 'C\u1EA3nh ' + (i + 1);
        });
    }

    /* ── Per-scene charcount + Optimize + Mode Tabs ── */
    if (createScenes) {
        createScenes.addEventListener('input', function(e) {
            if (e.target.classList.contains('bvk-aiva-scene-prompt')) {
                var foot = e.target.closest('.bvk-aiva-scene-prompt-wrap');
                if (foot) {
                    var cc = foot.querySelector('.bvk-aiva-charcount');
                    if (cc) cc.textContent = e.target.value.length + '/2000';
                }
            }
        });
        createScenes.addEventListener('click', function(e) {
            if (!e.target.classList.contains('bvk-aiva-optimize')) return;
            var wrap = e.target.closest('.bvk-aiva-scene-prompt-wrap');
            if (!wrap) return;
            var ta = wrap.querySelector('.bvk-aiva-scene-prompt');
            if (!ta) return;
            var btn = e.target;
            var origText = btn.textContent;
            btn.textContent = '\u23f3 \u0110ang t\u1ed1i \u01b0u...';
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'bvk_optimize_prompt');
            fd.append('nonce', BVK.nonce);
            fd.append('prompt', ta.value);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                btn.textContent = origText;
                btn.disabled = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data.prompt) {
                        ta.value = res.data.prompt;
                        var cc = wrap.querySelector('.bvk-aiva-charcount');
                        if (cc) cc.textContent = ta.value.length + '/2000';
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'T\u1ed1i \u01b0u th\u1ea5t b\u1ea1i.');
                    }
                } catch(err) { alert('Server error.'); }
            };
            xhr.onerror = function() { btn.textContent = origText; btn.disabled = false; alert('L\u1ed7i k\u1ebft n\u1ed1i.'); };
            xhr.send(fd);
        });
    }
    document.querySelectorAll('.bvk-aiva-mode').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.bvk-aiva-mode').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
        });
    });

    /* ── Create Button State ── */
    var btnCreateVideo = document.getElementById('bvk-btn-create-video');
    function updateCreateBtn() {
        if (!btnCreateVideo || !createScenes) return;
        var hasAny = false;
        createScenes.querySelectorAll('.bvk-aiva-scene-url').forEach(function(u) { if (u.value) hasAny = true; });
        btnCreateVideo.disabled = !hasAny;
    }
    function updateAddSceneBtn() {
        if (!createAddBtn) return;
        createAddBtn.style.display = getCreateSceneCount() >= 3 ? 'none' : '';
    }

    /* ── Create Video Submit (multi-scene) ── */
    if (btnCreateVideo) {
        btnCreateVideo.addEventListener('click', function() {
            if (isSubmitting || btnCreateVideo.disabled) return;
            var model = document.getElementById('bvk-create-model').value;
            var duration = document.querySelector('#bvk-tab-create input[name="bvk_duration"]:checked');
            var ratio = document.querySelector('#bvk-tab-create input[name="bvk_ratio"]:checked');

            // Collect filled scenes
            var jobs = [];
            createScenes.querySelectorAll('.bvk-aiva-scene').forEach(function(scene) {
                var url = scene.querySelector('.bvk-aiva-scene-url');
                var promptEl = scene.querySelector('.bvk-aiva-scene-prompt');
                if (url && url.value) {
                    jobs.push({ url: url.value, prompt: (promptEl ? promptEl.value.trim() : ''), scene: scene });
                }
            });
            if (jobs.length === 0) { showCreateStatus('Vui l\u00F2ng upload \u00EDt nh\u1EA5t 1 \u1EA3nh.', 'error'); return; }

            isSubmitting = true;
            btnCreateVideo.disabled = true;
            btnCreateVideo.innerHTML = '<span>\u23F3</span> \u0110ang g\u1EEDi ' + jobs.length + ' c\u1EA3nh...';
            showCreateStatus('\u0110ang g\u1EEDi ' + jobs.length + ' c\u1EA3nh...', 'loading');

            var completed = 0, succeeded = 0;
            jobs.forEach(function(job) {
                var fd = new FormData();
                fd.append('action', 'bvk_create_video');
                fd.append('nonce', BVK.nonce);
                fd.append('prompt', job.prompt);
                fd.append('image_url', job.url);
                fd.append('duration', duration ? duration.value : '10');
                fd.append('aspect_ratio', ratio ? ratio.value : '9:16');
                fd.append('model', model);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', BVK.ajax_url, true);
                xhr.onload = function() {
                    completed++;
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            succeeded++;
                            var emptyEl = document.getElementById('bvk-results-empty');
                            if (emptyEl) emptyEl.style.display = 'none';
                            var jobsList = document.getElementById('bvk-create-jobs');
                            if (!jobsList) {
                                jobsList = document.createElement('div');
                                jobsList.id = 'bvk-create-jobs';
                                jobsList.className = 'bvk-aiva-jobs';
                                var rp = document.querySelector('.bvk-aiva-results');
                                if (rp) rp.appendChild(jobsList);
                            }
                            jobsList.insertAdjacentHTML('afterbegin',
                                '<div class="bvk-aiva-job" data-status="queued"><div class="bvk-job-top">' +
                                '<span class="bvk-job-status st-queued">\u0110ang ch\u1EDD</span>' +
                                '<span style="font-size:10px;color:#58a6ff;font-weight:600;background:rgba(31,111,235,.15);padding:1px 6px;border-radius:4px;">' + escHtml(model) + '</span></div>' +
                                '<div class="bvk-job-prompt">' + escHtml(job.prompt.substring(0, 100) || '(no prompt)') + '</div>' +
                                '<div class="bvk-progress"><div class="bvk-progress-bar" style="width:10%"></div></div></div>');
                        }
                    } catch(e) {}
                    if (completed === jobs.length) {
                        isSubmitting = false;
                        btnCreateVideo.innerHTML = '<span>\u25B6</span> T\u1EA1o video';
                        if (succeeded > 0) {
                            showCreateStatus('\u0110\u00E3 g\u1EEDi ' + succeeded + '/' + jobs.length + ' c\u1EA3nh th\u00E0nh c\u00F4ng! Theo d\u00F5i t\u1EA1i tab Monitor.', 'success');
                            if (!pollTimer) { pollTimer = setInterval(pollJobs, 10000); }
                            createScenes.querySelectorAll('.bvk-aiva-scene').forEach(function(s) { aivaClearScene(s); });
                        } else {
                            showCreateStatus('G\u1EEDi th\u1EA5t b\u1EA1i, vui l\u00F2ng th\u1EED l\u1EA1i.', 'error');
                        }
                        updateCreateBtn();
                    }
                };
                xhr.onerror = function() {
                    completed++;
                    if (completed === jobs.length) {
                        isSubmitting = false;
                        btnCreateVideo.innerHTML = '<span>\u25B6</span> T\u1EA1o video';
                        showCreateStatus('L\u1ED7i k\u1EBFt n\u1ED1i', 'error');
                        updateCreateBtn();
                    }
                };
                xhr.send(fd);
            });
        });
    }
    updateCreateBtn();

    function showCreateStatus(msg, type) {
        var el = document.getElementById('bvk-create-status');
        if (!el) return;
        el.className = 'bvk-status';
        if (msg && type) { el.textContent = msg; el.classList.add(type); }
    }

    /* ── Refresh Monitor ── */
    var btnRefresh = document.getElementById('bvk-btn-refresh');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function() {
            logConsole('Manual refresh...', 'info');
            pollJobs();
        });
    }

    var pollTimer = null;
    var prevJobStates = {}; // Track state changes for console log

    function pollJobs() {
        var fd = new FormData();
        fd.append('action', 'bvk_poll_jobs');
        fd.append('nonce', BVK.nonce);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    renderJobs(res.data.jobs, res.data.stats);
                    updateStatsBar(res.data.stats);
                    detectChanges(res.data.jobs);
                    // Auto-start/stop polling based on active jobs
                    managePollTimer(res.data.stats.active || 0);
                }
            } catch(e) {
                logConsole('Poll parse error: ' + e.message, 'err');
            }
        };
        xhr.onerror = function() { logConsole('Network error during poll', 'err'); };
        xhr.send(fd);
    }

    function managePollTimer(activeCount) {
        if (activeCount > 0 && !pollTimer) {
            pollTimer = setInterval(pollJobs, 10000);
            logConsole('Auto-poll ON (' + activeCount + ' active)', 'ok');
        } else if (activeCount === 0 && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
            logConsole('Auto-poll OFF — no active jobs', 'info');
        }
    }

    function detectChanges(jobs) {
        jobs.forEach(function(job) {
            var prev = prevJobStates[job.id];
            if (!prev) {
                // First time seeing this job
                if (job.status === 'queued' || job.status === 'processing') {
                    logConsole('Job #' + job.id + ': ' + job.status + ' (' + (job.progress||0) + '%)', 'warn');
                }
            } else {
                // Detect status change
                if (prev.status !== job.status) {
                    if (job.status === 'completed') {
                        logConsole('Job #' + job.id + ': COMPLETED! ' + (job.media_url ? 'Media ready' : 'Video fetched'), 'ok');
                        // Auto-insert into React video editor
                        var completedUrl = job.media_url || job.video_url;
                        if (completedUrl) {
                            var editorFrame = document.getElementById('bvk-editor-frame');
                            if (editorFrame && editorFrame.contentWindow) {
                                editorFrame.contentWindow.postMessage({
                                    type: 'BVK_INSERT_VIDEO',
                                    src: completedUrl,
                                    jobId: job.id,
                                    prompt: job.prompt || ''
                                }, '*');
                                logConsole('Job #' + job.id + ': sent to Editor', 'ok');
                            }
                        }
                    } else if (job.status === 'failed') {
                        logConsole('Job #' + job.id + ': FAILED — ' + (job.error_message || 'Unknown'), 'err');
                    } else {
                        logConsole('Job #' + job.id + ': ' + prev.status + ' -> ' + job.status, 'info');
                    }
                } else if (prev.progress !== job.progress && (job.status === 'processing' || job.status === 'queued')) {
                    logConsole('Job #' + job.id + ': ' + job.status + ' (' + (job.progress||0) + '%)', 'warn');
                }
                // Detect media_url appearance
                if (!prev.media_url && job.media_url) {
                    logConsole('Job #' + job.id + ': Uploaded to Media Library', 'ok');
                }
            }
            prevJobStates[job.id] = { status: job.status, progress: job.progress, media_url: job.media_url };
        });
    }

    function updateStatsBar(stats) {
        var bar = document.getElementById('bvk-stats-bar');
        if (!bar) return;
        var html = '<span class="bvk-monitor-badge bvk-badge-total">\uD83C\uDFAC ' + stats.total + '</span>';
        html += '<span class="bvk-monitor-badge bvk-badge-done">\u2705 ' + stats.done + '</span>';
        if (stats.active > 0) {
            html += '<span class="bvk-monitor-badge bvk-badge-active">\u23F3 ' + stats.active + ' dang chay</span>';
        }
        html += '<button type="button" class="bvk-btn-sm" id="bvk-btn-refresh" style="margin-left:auto;padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:1px solid #30363d;background:#21262d;cursor:pointer;color:#8b949e;">\uD83D\uDD04 Lam moi</button>';
        bar.innerHTML = html;
        // Re-bind refresh button
        var btn = document.getElementById('bvk-btn-refresh');
        if (btn) btn.addEventListener('click', function() { logConsole('Manual refresh...', 'info'); pollJobs(); });
    }

    function renderJobs(jobs, stats) {
        var list = document.getElementById('bvk-job-list');
        if (!list) return;

        if (!jobs || jobs.length === 0) {
            list.innerHTML = '<div class="bvk-empty"><div class="bvk-empty-icon">\uD83C\uDFAC</div><p>Chua co video nao.</p></div>';
            return;
        }

        var stLabels = { draft:'Nhap', queued:'Dang cho', processing:'Dang xu ly', completed:'Hoan thanh', failed:'Loi' };
        var modelLabels = {
            '2.6|pro':'Kling 2.6 Pro','2.6|std':'Kling 2.6 Std','2.5|pro':'Kling 2.5 Pro','1.6|pro':'Kling 1.6 Pro',
            'seedance:1.0':'SeeDance','sora:v1':'Sora','veo:3':'Veo 3'
        };

        var html = '<div id="bvk-multi-select-bar" style="display:none;position:sticky;top:0;z-index:10;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:8px 12px;margin-bottom:8px;display:none;align-items:center;gap:8px;">' +
            '<span id="bvk-select-count" style="font-size:12px;color:#8b949e;flex:1;">0 đã chọn</span>' +
            '<button type="button" onclick="bvkSendSelectedToEditor()" style="background:#1f6feb;color:#fff;border:1px solid #388bfd;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">🎞️ Mở trong Editor</button>' +
            '<button type="button" onclick="bvkClearSelection()" style="background:#21262d;color:#8b949e;border:1px solid #30363d;border-radius:6px;padding:5px 10px;font-size:12px;cursor:pointer;">✕</button>' +
            '</div>';
        jobs.forEach(function(job) {
            var videoUrl = job.media_url || job.video_url || '';
            var prompt = job.prompt || 'No prompt';
            if (prompt.length > 100) prompt = prompt.substring(0, 100) + '...';
            var modelLabel = modelLabels[job.model] || job.model || 'Kling';
            var cp = job.checkpoints || {};

            html += '<div class="bvk-job" data-job-id="' + job.id + '" data-status="' + job.status + '" data-video-url="' + escAttr(videoUrl) + '">';

            // Top bar: status + model + time
            html += '<div class="bvk-job-top">';
            html += '<span class="bvk-job-status st-' + job.status + '">' + (stLabels[job.status] || job.status) + '</span>';
            html += '<span style="font-size:10px;color:#6366f1;font-weight:600;background:#eef2ff;padding:1px 6px;border-radius:4px;">' + escHtml(modelLabel) + '</span>';
            html += '<span style="font-size:11px;color:#9ca3af;margin-left:auto;">' + (job.created_at || '') + '</span>';
            html += '</div>';

            // Prompt
            html += '<div class="bvk-job-prompt">' + escHtml(prompt) + '</div>';

            // Meta
            html += '<div class="bvk-job-meta">';
            html += '<span>\u23F1 ' + job.duration + 's</span>';
            html += '<span>\uD83D\uDCD0 ' + job.aspect_ratio + '</span>';
            html += '<span>#' + job.id + '</span>';
            if (videoUrl && job.status === 'completed') {
                html += '<a href="' + escAttr(videoUrl) + '" target="_blank">\u25B6 Xem video</a>';
            }
            html += '</div>';

            // Pipeline steps
            if (job.status !== 'draft') {
                html += '<div class="bvk-pipeline">';
                html += pipeStep('Submitted', true);
                html += pipeStep('API Processing', job.status === 'processing' ? 'active' : (job.status === 'completed' || cp.video_completed));
                html += pipeStep('Video Fetched', cp.video_completed || cp.video_fetched);
                html += pipeStep('Media Upload', cp.video_fetched || cp.manual_media_upload || !!job.media_url);
                if (cp.tts_generated) html += pipeStep('TTS', true);
                if (cp.audio_merged) html += pipeStep('FFmpeg', true);
                html += pipeStep('Done', job.status === 'completed');
                html += '</div>';
            }

            // Progress bar (for active jobs)
            if (job.status === 'queued' || job.status === 'processing') {
                html += '<div class="bvk-progress"><div class="bvk-progress-bar" style="width:' + (parseInt(job.progress)||0) + '%"></div></div>';
            }

            // Action buttons (for completed jobs)
            if (job.status === 'completed') {
                html += '<div class="bvk-job-actions">';
                // Checkbox for multi-select
                if (videoUrl) {
                    html += '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;margin-right:4px;"><input type="checkbox" class="bvk-job-select" data-url="' + escAttr(videoUrl) + '"> <span style="font-size:11px;color:#8b949e;">Chọn</span></label>';
                }
                // Upload to Media button
                if (job.media_url) {
                    html += '<button type="button" class="bvk-job-act done" disabled>\u2705 Media</button>';
                } else if (job.video_url) {
                    html += '<button type="button" class="bvk-job-act" onclick="bvkUploadToMedia(' + job.id + ', this)">\uD83D\uDCE5 Upload Media</button>';
                }
                // Copy link button
                if (videoUrl) {
                    html += '<a href="' + escAttr(videoUrl) + '" download class="bvk-job-act" style="text-decoration:none;">\uD83D\uDCE5 T\u1EA3i v\u1EC1</a>';
                }
                // View in new tab
                if (videoUrl) {
                    html += '<a href="' + escAttr(videoUrl) + '" target="_blank" class="bvk-job-act" style="text-decoration:none;">\u25B6 Xem</a>';
                }
                // Copy link
                if (videoUrl) {
                    html += '<button type="button" class="bvk-job-act" onclick="bvkCopyLink(\'' + escAttr(videoUrl) + '\', this)">\uD83D\uDD17 Copy Link</button>';
                }
                // Open in Editor button
                if (videoUrl) {
                    html += '<button type="button" class="bvk-job-act bvk-job-act-editor" style="background:#1f6feb;color:#fff;border-color:#388bfd;" onclick="bvkSendToEditor([' + JSON.stringify(videoUrl) + '])">\uD83C\uDF9E\uFE0F Editor</button>';
                }
                html += '</div>';
            }

            html += '</div>';
        });
        list.innerHTML = html;
    }

    function pipeStep(label, state) {
        if (state === 'active') return '<span class="bvk-step active">\u23F3 ' + label + '</span>';
        if (state) return '<span class="bvk-step done">\u2705 ' + label + '</span>';
        return '<span class="bvk-step">\u2B55 ' + label + '</span>';
    }

    /* ── Upload to Media (AJAX) ── */
    window.bvkUploadToMedia = function(jobId, btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.textContent = '\u23F3 Uploading...';
        logConsole('Job #' + jobId + ': Uploading to Media Library...', 'info');

        var fd = new FormData();
        fd.append('action', 'bvk_upload_to_media');
        fd.append('nonce', BVK.nonce);
        fd.append('job_id', jobId);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BVK.ajax_url, true);
        xhr.onload = function() {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    btn.className = 'bvk-job-act done';
                    btn.textContent = '\u2705 Media';
                    logConsole('Job #' + jobId + ': ' + (res.data.duplicate ? 'Already in Media' : 'Uploaded!') + ' — ' + res.data.media_url, 'ok');
                    // Refresh to update links
                    setTimeout(pollJobs, 500);
                } else {
                    btn.disabled = false;
                    btn.textContent = '\uD83D\uDCE5 Upload Media';
                    logConsole('Job #' + jobId + ': Upload failed — ' + (res.data && res.data.message || 'Error'), 'err');
                }
            } catch(e) {
                btn.disabled = false;
                btn.textContent = '\uD83D\uDCE5 Upload Media';
                logConsole('Job #' + jobId + ': Parse error', 'err');
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = '\uD83D\uDCE5 Upload Media';
            logConsole('Job #' + jobId + ': Network error', 'err');
        };
        xhr.send(fd);
    };

    /* ── Copy Link ── */
    window.bvkCopyLink = function(url, btn) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
                var orig = btn.textContent;
                btn.textContent = '\u2705 Copied!';
                btn.className = 'bvk-job-act done';
                setTimeout(function() { btn.textContent = orig; btn.className = 'bvk-job-act'; }, 2000);
            });
        } else {
            // Fallback
            var ta = document.createElement('textarea');
            ta.value = url;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            var orig = btn.textContent;
            btn.textContent = '\u2705 Copied!';
            setTimeout(function() { btn.textContent = orig; }, 2000);
        }
        logConsole('Copied: ' + url, 'ok');
    };

    /* ── Switch to tab helper ── */
    function bvkSwitchTab(tabName) {
        document.querySelectorAll('.bvk-nav-item').forEach(function(l){ l.classList.remove('active'); });
        document.querySelectorAll('.bvk-tab').forEach(function(t){ t.classList.remove('active'); });
        var navItem = document.querySelector('.bvk-nav-item[data-tab="' + tabName + '"]');
        if (navItem) navItem.classList.add('active');
        var tabEl = document.getElementById('bvk-tab-' + tabName);
        if (tabEl) tabEl.classList.add('active');
    }

    /* ── Open video(s) in Editor ── */
    window.bvkSendToEditor = function(urls) {
        if (!urls || !urls.length) return;
        bvkSwitchTab('editor');
        var frame = document.getElementById('bvk-editor-frame');
        if (!frame || !frame.contentWindow) return;
        // Post each video sequentially with a small delay
        urls.forEach(function(url, i) {
            setTimeout(function() {
                frame.contentWindow.postMessage({ type: 'BVK_INSERT_VIDEO', src: url }, '*');
                logConsole('Editor \u2190 ' + url, 'ok');
            }, i * 150);
        });
    };

    /* ── TwitCanva → Editor Bridge ── */
    // Listen for messages from TwitCanva iframe
    window.addEventListener('message', function(e) {
        if (!e.data || typeof e.data !== 'object') return;
        // Forward TC_SEND_TO_EDITOR from TwitCanva to Editor
        if (e.data.type === 'TC_SEND_TO_EDITOR') {
            var urls = e.data.urls || (e.data.url ? [e.data.url] : []);
            if (urls.length > 0) {
                bvkSendToEditor(urls);
                logConsole('Canva → Hậu kỳ: ' + urls.length + ' video(s)', 'ok');
            }
        }
    });

    // Canva toolbar: fetch latest completed job and send to editor
    window.bvkCanvaSendToEditor = function() {
        var statusEl = document.getElementById('bvk-wf-bridge-status');
        if (statusEl) statusEl.textContent = 'Đang tìm video...';

        var fd = new FormData();
        fd.append('action', 'bvk_poll_jobs');
        fd.append('nonce', BVK.nonce);

        fetch(BVK.ajax_url, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success || !res.data || !res.data.jobs) {
                    if (statusEl) statusEl.textContent = 'Không tìm thấy job nào.';
                    return;
                }
                // Find the latest completed job with a video URL
                var jobs = res.data.jobs;
                var videoUrl = null;
                for (var i = 0; i < jobs.length; i++) {
                    var j = jobs[i];
                    if ((j.status === 'done' || j.status === 'completed') && j.video_url) {
                        videoUrl = j.video_url;
                        break;
                    }
                }
                if (videoUrl) {
                    bvkSendToEditor([videoUrl]);
                    if (statusEl) statusEl.textContent = '✅ Đã gửi sang Editor!';
                    setTimeout(function() { if (statusEl) statusEl.textContent = ''; }, 3000);
                } else {
                    if (statusEl) statusEl.textContent = 'Chưa có video hoàn thành.';
                    setTimeout(function() { if (statusEl) statusEl.textContent = ''; }, 3000);
                }
            })
            .catch(function(err) {
                if (statusEl) statusEl.textContent = 'Lỗi: ' + err.message;
            });
    };

    /* ── Multi-select: send all checked videos to Editor ── */
    window.bvkSendSelectedToEditor = function() {
        var checked = document.querySelectorAll('.bvk-job-select:checked');
        var urls = [];
        checked.forEach(function(cb) { if (cb.dataset.url) urls.push(cb.dataset.url); });
        if (!urls.length) return;
        bvkSendToEditor(urls);
        bvkClearSelection();
    };

    /* ── Clear multi-select ── */
    window.bvkClearSelection = function() {
        document.querySelectorAll('.bvk-job-select').forEach(function(cb){ cb.checked = false; });
        var bar = document.getElementById('bvk-multi-select-bar');
        if (bar) bar.style.display = 'none';
    };

    /* ── Update multi-select bar on checkbox change ── */
    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('bvk-job-select')) return;
        var checked = document.querySelectorAll('.bvk-job-select:checked');
        var bar = document.getElementById('bvk-multi-select-bar');
        if (!bar) return;
        if (checked.length > 0) {
            bar.style.display = 'flex';
            var countEl = document.getElementById('bvk-multi-select-count') || document.getElementById('bvk-select-count');
            if (countEl) countEl.textContent = checked.length + ' video đã chọn';
        } else {
            bar.style.display = 'none';
        }
    });

    /* ── Console Logger ── */
    function logConsole(msg, type) {
        var con = document.getElementById('bvk-console');
        if (!con) return;
        var now = new Date();
        var ts = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        var cls = 'bvk-log-info';
        if (type === 'ok') cls = 'bvk-log-ok';
        else if (type === 'warn') cls = 'bvk-log-warn';
        else if (type === 'err') cls = 'bvk-log-err';
        var line = document.createElement('span');
        line.className = 'bvk-log-line';
        line.innerHTML = '<span class="bvk-log-time">[' + ts + ']</span> <span class="' + cls + '">' + escHtml(msg) + '</span>';
        con.appendChild(line);
        con.scrollTop = con.scrollHeight;
        // Keep max 50 lines
        while (con.children.length > 50) con.removeChild(con.firstChild);
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    /* ── Auto-poll on page load ── */
    var hasActive = document.querySelectorAll('.bvk-job[data-status="queued"], .bvk-job[data-status="processing"]').length > 0;
    if (hasActive) {
        pollTimer = setInterval(pollJobs, 10000);
        logConsole('Auto-poll started (10s interval)', 'ok');
    }

    /* ── Chat Commands → postMessage ── */
    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var msg = this.getAttribute('data-msg');
            if (!msg) return;

            this.style.transform = 'scale(0.96)';
            this.style.opacity = '0.7';
            var self = this;
            setTimeout(function() {
                self.style.transform = '';
                self.style.opacity = '';
            }, 200);

            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type:   'bizcity_agent_command',
                    source: 'bizcity-video-kling',
                    text:   msg
                }, '*');
            }
        });
    });

    /* ── Save Settings ── */
    var btnSaveSettings = document.getElementById('bvk-btn-save-settings');
    if (btnSaveSettings) {
        btnSaveSettings.addEventListener('click', function() {
            var fd = new FormData();
            fd.append('action', 'bvk_save_settings');
            fd.append('nonce', BVK.nonce);

            // API fields (may not exist if not admin)
            var apiKey = document.getElementById('bvk-cfg-api-key');
            var endpoint = document.getElementById('bvk-cfg-endpoint');
            if (apiKey) fd.append('api_key', apiKey.value);
            if (endpoint) fd.append('endpoint', endpoint.value);

            // Default fields
            var model = document.getElementById('bvk-cfg-model');
            if (model) fd.append('default_model', model.value);
            var dur = document.querySelector('input[name="bvk_cfg_duration"]:checked');
            if (dur) fd.append('default_duration', dur.value);
            var ratio = document.querySelector('input[name="bvk_cfg_ratio"]:checked');
            if (ratio) fd.append('default_aspect_ratio', ratio.value);

            btnSaveSettings.disabled = true;
            showSettingsStatus('Dang luu...', 'loading');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                btnSaveSettings.disabled = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        showSettingsStatus('Da luu cai dat thanh cong!', 'success');
                    } else {
                        showSettingsStatus(res.data && res.data.message ? res.data.message : 'Loi luu cai dat', 'error');
                    }
                } catch(e) {
                    showSettingsStatus('Server error', 'error');
                }
            };
            xhr.onerror = function() {
                btnSaveSettings.disabled = false;
                showSettingsStatus('Network error', 'error');
            };
            xhr.send(fd);
        });
    }

    /* ── Helpers ── */
    function showStatus(msg, type) {
        var el = document.getElementById('bvk-status');
        if (!el) return;
        el.className = 'bvk-status';
        if (msg && type) {
            el.textContent = msg;
            el.classList.add(type);
        }
    }

    function showSettingsStatus(msg, type) {
        var el = document.getElementById('bvk-settings-status');
        if (!el) return;
        el.className = 'bvk-status';
        if (msg && type) {
            el.textContent = msg;
            el.classList.add(type);
        }
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
    function escAttr(str) {
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

})();
</script>
<script>
    // Provide config for video-studio.js
    var bvk_studio = {
        ajax_url: '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>',
        nonce:    '<?php echo esc_js( wp_create_nonce( "bvk_nonce" ) ); ?>'
    };
</script>
<script src="<?php echo esc_url( BIZCITY_VIDEO_KLING_URL . 'assets/video-studio.js?v=' . BIZCITY_VIDEO_KLING_ASSETS_VERSION ); ?>"></script>
</body>
</html>