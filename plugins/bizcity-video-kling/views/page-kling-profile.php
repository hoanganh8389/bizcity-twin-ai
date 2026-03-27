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
$allowed_tabs = [ 'create', 'monitor', 'chat', 'settings' ];
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
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f9fafb;font-family:system-ui,-apple-system,sans-serif;color:#1a1a2e;line-height:1.5;min-height:100vh;}

/* ── App Container ── */
.bvk-app{max-width:100%;padding:0 0 72px;position:relative;}

/* ── Bottom Nav ── */
.bvk-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:#fff;border-top:1px solid #e5e7eb;z-index:100;padding:6px 0 env(safe-area-inset-bottom, 4px);}
.bvk-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;text-decoration:none;color:#9ca3af;font-size:10px;font-weight:600;transition:color .2s;}
.bvk-nav-item.active{color:#f97316;}
.bvk-nav-icon{font-size:20px;}

/* ── Tab Content ── */
.bvk-tab{display:none;}
.bvk-tab.active{display:block;}

/* ── Hero ── */
.bvk-hero{background:linear-gradient(135deg,#f59e0b 0%,#f97316 50%,#ef4444 100%);padding:24px 16px;color:#fff;position:relative;overflow:hidden;}
.bvk-hero::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:rgba(255,255,255,0.08);border-radius:50%;}
.bvk-hero-icon{font-size:36px;margin-bottom:4px;}
.bvk-hero h2{font-size:20px;font-weight:800;margin-bottom:2px;}
.bvk-hero p{opacity:0.9;font-size:12px;}
.bvk-hero-stats{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;}
.bvk-hero-stat{background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;}

/* ── Card ── */
.bvk-card{background:#fff;border-radius:16px;padding:20px 16px;margin:12px 12px 0;box-shadow:0 1px 4px rgba(0,0,0,0.04);}
.bvk-card h3{font-size:16px;font-weight:700;margin-bottom:12px;}

/* ── Photo Zone ── */
.bvk-photo-zone{display:block;border:2px dashed #d1d5db;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px;position:relative;overflow:hidden;}
.bvk-photo-zone:hover{border-color:#f97316;}
.bvk-photo-placeholder{color:#9ca3af;}
.bvk-photo-placeholder span{font-size:40px;display:block;margin-bottom:4px;}
.bvk-photo-placeholder p{font-size:13px;margin-bottom:2px;}
.bvk-photo-placeholder small{font-size:11px;color:#d1d5db;}
#bvk-photo-preview{display:none;}
#bvk-photo-preview img{max-width:100%;max-height:200px;border-radius:8px;object-fit:contain;}

/* ── Fields ── */
.bvk-field{margin-bottom:12px;}
.bvk-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
.bvk-field textarea,.bvk-field input,.bvk-field select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#1a1a2e;}
.bvk-field textarea:focus,.bvk-field input:focus,.bvk-field select:focus{outline:none;border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,0.1);}
.bvk-field-row{display:flex;gap:8px;}
.bvk-field-row .bvk-field{flex:1;}

/* ── Pills ── */
.bvk-pill-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bvk-pill{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;background:#fff;}
.bvk-pill:has(input:checked){border-color:#f97316;background:#fff7ed;color:#f97316;}
.bvk-pill input{display:none;}

/* ── Buttons ── */
.bvk-btn-row{display:flex;gap:8px;margin-top:4px;}
.bvk-btn{flex:1;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
.bvk-btn-primary{background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;}
.bvk-btn-primary:hover{opacity:0.9;transform:translateY(-1px);}
.bvk-btn-primary:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.bvk-btn-secondary{background:#fff;border:1.5px solid #e5e7eb;color:#374151;}
.bvk-btn-secondary:hover{border-color:#f97316;color:#f97316;}

/* ── Status Message ── */
.bvk-status{margin-top:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600;display:none;}
.bvk-status.success{display:block;background:#dcfce7;color:#166534;}
.bvk-status.error{display:block;background:#fef2f2;color:#991b1b;}
.bvk-status.loading{display:block;background:#dbeafe;color:#1e40af;}

/* ── Result Card ── */
.bvk-result{display:none;margin-top:12px;padding:14px;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;font-size:13px;line-height:1.6;}
.bvk-result.show{display:block;}
.bvk-result h4{font-size:14px;font-weight:700;margin-bottom:6px;color:#166534;}

/* ── Monitor ── */
.bvk-section{padding:12px;}
.bvk-section-title{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
.bvk-job-list{display:flex;flex-direction:column;gap:8px;}
.bvk-job{padding:12px;border:1px solid #f3f4f6;border-radius:12px;background:#fff;}
.bvk-job-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;}
.bvk-job-status{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;}
.st-queued{background:#f3f4f6;color:#6b7280;}
.st-processing{background:#dbeafe;color:#1e40af;}
.st-completed{background:#dcfce7;color:#166534;}
.st-failed{background:#fef2f2;color:#991b1b;}
.bvk-job-prompt{font-size:12px;color:#374151;line-height:1.4;margin-bottom:4px;}
.bvk-job-meta{display:flex;gap:8px;font-size:11px;color:#9ca3af;flex-wrap:wrap;align-items:center;}
.bvk-job-meta a{color:#f59e0b;font-weight:600;text-decoration:none;}
.bvk-progress{height:4px;background:#f3f4f6;border-radius:2px;overflow:hidden;margin-top:6px;}
.bvk-progress-bar{height:100%;border-radius:2px;background:linear-gradient(90deg,#f59e0b,#f97316);transition:width .5s ease;}

/* ── Chat Commands ── */
.bvk-commands{display:flex;flex-direction:column;gap:10px;padding:0 12px;}
.bvk-cmd{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;cursor:pointer;transition:all .2s;text-align:left;width:100%;}
.bvk-cmd:hover{border-color:#f97316;box-shadow:0 2px 12px rgba(249,115,22,.12);}
.bvk-cmd.primary{border:2px solid #f97316;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);}
.bvk-cmd-icon{font-size:26px;flex-shrink:0;width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:rgba(249,115,22,0.08);border-radius:10px;}
.bvk-cmd-text h4{font-size:13px;font-weight:700;color:#1a1a2e;margin-bottom:1px;}
.bvk-cmd-text p{font-size:11px;color:#6b7280;}
.bvk-tips{margin:14px 12px 0;padding:12px;background:#fff;border-radius:12px;}
.bvk-tips h4{font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;}
.bvk-tip{padding:8px 12px;background:#f9fafb;border-radius:8px;margin-bottom:4px;font-size:11px;color:#4b5563;cursor:pointer;transition:background .2s;}
.bvk-tip:hover{background:#fef3c7;}

/* ── Login ── */
.bvk-login{text-align:center;padding:80px 20px;}
.bvk-login-icon{font-size:48px;margin-bottom:12px;}
.bvk-login h2{font-size:22px;margin-bottom:6px;}
.bvk-login p{color:#6b7280;font-size:14px;}

/* ── Empty ── */
.bvk-empty{text-align:center;padding:32px 16px;color:#9ca3af;font-size:13px;}
.bvk-empty-icon{font-size:36px;margin-bottom:8px;}

/* ── Upload indicator ── */
.bvk-upload-bar{height:3px;background:#f3f4f6;border-radius:2px;margin-top:6px;overflow:hidden;display:none;}
.bvk-upload-bar.active{display:block;}
.bvk-upload-bar-fill{height:100%;background:linear-gradient(90deg,#f59e0b,#f97316);width:0;transition:width .3s;}

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
.bvk-job-act{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid #e5e7eb;background:#fff;color:#374151;transition:all .2s;font-family:inherit;}
.bvk-job-act:hover{border-color:#f97316;color:#f97316;}
.bvk-job-act:disabled{opacity:0.4;cursor:not-allowed;}
.bvk-job-act.done{background:#dcfce7;border-color:#86efac;color:#166534;cursor:default;}

/* ── Pipeline Steps ── */
.bvk-pipeline{display:flex;gap:4px;margin-top:8px;flex-wrap:wrap;}
.bvk-step{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#f3f4f6;color:#9ca3af;}
.bvk-step.done{background:#dcfce7;color:#166534;}
.bvk-step.active{background:#dbeafe;color:#1e40af;animation:bvk-pulse 1.5s infinite;}
@keyframes bvk-pulse{0%,100%{opacity:1;}50%{opacity:0.6;}}

/* ── Monitor Header ── */
.bvk-monitor-bar{display:flex;gap:8px;padding:8px 12px;align-items:center;flex-wrap:wrap;}
.bvk-monitor-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;}
.bvk-badge-active{background:#dbeafe;color:#1e40af;}
.bvk-badge-done{background:#dcfce7;color:#166534;}
.bvk-badge-total{background:#f3f4f6;color:#6b7280;}
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
     TAB 1: CREATE VIDEO (Direct Form)
     ═══════════════════════════════════════════════ -->
<div class="bvk-tab<?php echo $active_tab === 'create' ? ' active' : ''; ?>" id="bvk-tab-create">

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
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="5"<?php checked( $cfg_duration, 5 ); ?>> 5s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="10"<?php checked( $cfg_duration, 10 ); ?>> 10s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="15"<?php checked( $cfg_duration, 15 ); ?>> 15s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="20"<?php checked( $cfg_duration, 20 ); ?>> 20s</label>
                <label class="bvk-pill"><input type="radio" name="bvk_duration" value="30"<?php checked( $cfg_duration, 30 ); ?>> 30s</label>
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
        <button type="button" class="bvk-btn-sm" id="bvk-btn-refresh" style="margin-left:auto;padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;color:#374151;"><?php echo "\xF0\x9F\x94\x84 L\xC3\xA0m m\xE1\xBB\x9Bi"; ?></button>
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

    <?php if ( $is_admin_user ): ?>
    <!-- API Configuration (admin only) -->
    <div class="bvk-card">
        <h3><?php echo "\xF0\x9F\x94\x91 API Configuration"; ?></h3>

        <div class="bvk-field">
            <label>PiAPI API Key</label>
            <input type="password" id="bvk-cfg-api-key" value="<?php echo esc_attr( $cfg_api_key ); ?>" placeholder="pk-xxxxxxxxxxxxxxxx">
            <small style="font-size:10px;color:#9ca3af;margin-top:2px;display:block;"><?php echo "L\xE1\xBA\xA5y API key t\xE1\xBA\xA1i <a href='https://piapi.ai' target='_blank' style='color:#6366f1;'>piapi.ai</a>"; ?></small>
        </div>

        <div class="bvk-field">
            <label>API Endpoint</label>
            <input type="url" id="bvk-cfg-endpoint" value="<?php echo esc_attr( $cfg_endpoint ); ?>" placeholder="https://api.piapi.ai/api/v1">
        </div>
    </div>
    <?php endif; ?>

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

    <!-- Info -->
    <div class="bvk-card" style="background:#f8fafc;border:1px dashed #e2e8f0;">
        <h3 style="font-size:13px;"><?php echo "\xE2\x84\xB9 Th\xC3\xB4ng tin"; ?></h3>
        <div style="font-size:11px;color:#6b7280;line-height:1.7;">
            <div><?php echo "\xF0\x9F\x94\xA7 Plugin: BizCity Video Kling v" . BIZCITY_VIDEO_KLING_VERSION; ?></div>
            <div><?php echo "\xF0\x9F\x8C\x90 Gateway: PiAPI (piapi.ai)"; ?></div>
            <div><?php echo "\xF0\x9F\x8E\xAC Engine: Kling AI (Image-to-Video)"; ?></div>
            <div><?php echo "\xF0\x9F\x8E\x99 TTS: OpenAI Text-to-Speech"; ?></div>
            <div><?php echo "\xF0\x9F\x8E\xAC FFmpeg: Video concat + audio merge"; ?></div>
            <?php if ( $stats ): ?>
            <div style="margin-top:6px;padding-top:6px;border-top:1px solid #e2e8f0;">
                <?php echo "\xF0\x9F\x93\x8A T\xE1\xBB\x95ng: {$stats['total']} video | \xE2\x9C\x85 {$stats['done']} xong | \xE2\x8F\xB3 {$stats['active']} \xC4\x91ang ch\xE1\xBA\xA1y"; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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

    var uploadedPhotoUrl = '';
    var isSubmitting = false;

    /* ── Tab Navigation ── */
    document.querySelectorAll('.bvk-nav-item').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.bvk-nav-item').forEach(function(l){ l.classList.remove('active'); });
            document.querySelectorAll('.bvk-tab').forEach(function(t){ t.classList.remove('active'); });
            link.classList.add('active');
            var tab = document.getElementById('bvk-tab-' + link.getAttribute('data-tab'));
            if (tab) tab.classList.add('active');
        });
    });

    /* ── Photo Upload ── */
    var photoInput = document.getElementById('bvk-photo-input');
    if (photoInput) {
        photoInput.addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;

            // Preview immediately
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('bvk-photo-img').src = e.target.result;
                document.getElementById('bvk-photo-preview').style.display = 'block';
                document.getElementById('bvk-photo-placeholder').style.display = 'none';
            };
            reader.readAsDataURL(file);

            // Upload to WP Media
            var fd = new FormData();
            fd.append('action', 'bvk_upload_photo');
            fd.append('nonce', BVK.nonce);
            fd.append('photo', file);

            var bar = document.getElementById('bvk-upload-bar');
            var fill = document.getElementById('bvk-upload-fill');
            bar.classList.add('active');
            fill.style.width = '30%';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                fill.style.width = '100%';
                setTimeout(function(){ bar.classList.remove('active'); fill.style.width = '0'; }, 800);
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        uploadedPhotoUrl = res.data.url;
                    } else {
                        showStatus(res.data && res.data.message ? res.data.message : 'Upload failed', 'error');
                    }
                } catch(e) {
                    showStatus('Upload error', 'error');
                }
            };
            xhr.onerror = function() {
                bar.classList.remove('active');
                showStatus('Network error', 'error');
            };
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    fill.style.width = Math.round((e.loaded / e.total) * 90) + '%';
                }
            };
            xhr.send(fd);
        });
    }

    /* ── Create Video ── */
    var btnCreate = document.getElementById('bvk-btn-create');
    if (btnCreate) {
        btnCreate.addEventListener('click', function() {
            if (isSubmitting) return;

            var prompt    = (document.getElementById('bvk-prompt').value || '').trim();
            var voiceover = (document.getElementById('bvk-voiceover').value || '').trim();
            var model     = document.getElementById('bvk-model').value;
            var duration  = document.querySelector('input[name="bvk_duration"]:checked');
            var ratio     = document.querySelector('input[name="bvk_ratio"]:checked');

            if (!prompt && !uploadedPhotoUrl) {
                showStatus('Vui long nhap mo ta hoac chon anh de tao video.', 'error');
                return;
            }

            isSubmitting = true;
            btnCreate.disabled = true;
            showStatus('Dang gui yeu cau tao video...', 'loading');

            var fd = new FormData();
            fd.append('action', 'bvk_create_video');
            fd.append('nonce', BVK.nonce);
            fd.append('prompt', prompt);
            fd.append('image_url', uploadedPhotoUrl);
            fd.append('duration', duration ? duration.value : '10');
            fd.append('aspect_ratio', ratio ? ratio.value : '9:16');
            fd.append('voiceover_text', voiceover);
            fd.append('model', model);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', BVK.ajax_url, true);
            xhr.onload = function() {
                isSubmitting = false;
                btnCreate.disabled = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        showStatus('', '');
                        var result = document.getElementById('bvk-result');
                        var body = document.getElementById('bvk-result-body');
                        body.innerHTML = (res.data.message || 'Video dang duoc xu ly!').replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                        result.classList.add('show');
                        // Reset form
                        document.getElementById('bvk-prompt').value = '';
                        document.getElementById('bvk-voiceover').value = '';
                        uploadedPhotoUrl = '';
                        document.getElementById('bvk-photo-preview').style.display = 'none';
                        document.getElementById('bvk-photo-placeholder').style.display = '';
                        document.getElementById('bvk-photo-url').value = '';
                    } else {
                        showStatus(res.data && res.data.message ? res.data.message : 'Loi tao video', 'error');
                    }
                } catch(e) {
                    showStatus('Server error: ' + e.message, 'error');
                }
            };
            xhr.onerror = function() {
                isSubmitting = false;
                btnCreate.disabled = false;
                showStatus('Network error', 'error');
            };
            xhr.send(fd);
        });
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
        html += '<button type="button" class="bvk-btn-sm" id="bvk-btn-refresh" style="margin-left:auto;padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;color:#374151;">\uD83D\uDD04 Lam moi</button>';
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

        var html = '';
        jobs.forEach(function(job) {
            var videoUrl = job.media_url || job.video_url || '';
            var prompt = job.prompt || 'No prompt';
            if (prompt.length > 100) prompt = prompt.substring(0, 100) + '...';
            var modelLabel = modelLabels[job.model] || job.model || 'Kling';
            var cp = job.checkpoints || {};

            html += '<div class="bvk-job" data-job-id="' + job.id + '" data-status="' + job.status + '">';

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
</body>
</html>