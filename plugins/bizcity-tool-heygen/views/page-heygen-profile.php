<?php
/**
 * BizCity Tool HeyGen — Profile View (Type B: 4-Tab)
 *
 * Tab 1: Tạo (Create) — chọn nhân vật + nhập script → AJAX tạo video
 * Tab 2: Monitor (Lịch sử) — live console + job tracking
 * Tab 3: Chat (Guided Commands) — postMessage → parent
 * Tab 4: Cài đặt (Settings) — API + defaults + character management
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id  = get_current_user_id();
$is_admin = current_user_can( 'manage_options' );

// Get active characters
$characters = BizCity_Tool_HeyGen_Database::get_active_characters();

// Get recent jobs (PHP initial render)
global $wpdb;
$jobs_table  = BizCity_Tool_HeyGen_Database::get_table_name( 'jobs' );
$chars_table = BizCity_Tool_HeyGen_Database::get_table_name( 'characters' );
$has_table   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;

$jobs = [];
$stats = [ 'total' => 0, 'done' => 0, 'active' => 0 ];

if ( $has_table && $user_id ) {
    $jobs = $wpdb->get_results( $wpdb->prepare(
        "SELECT j.id, j.character_id, j.script, j.status, j.progress, j.video_url, j.media_url,
                j.attachment_id, j.mode, j.checkpoints, j.error_message, j.created_at, j.updated_at,
                c.name AS character_name
         FROM {$jobs_table} j
         LEFT JOIN {$chars_table} c ON j.character_id = c.id
         WHERE j.created_by = %d
         ORDER BY j.created_at DESC LIMIT 20",
        $user_id
    ), ARRAY_A );

    foreach ( $jobs as &$j ) {
        $j['checkpoints'] = ! empty( $j['checkpoints'] ) ? json_decode( $j['checkpoints'], true ) : [];
    }
    unset( $j );

    $stats['total']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d", $user_id ) );
    $stats['done']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status = 'completed'", $user_id ) );
    $stats['active'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status IN ('queued','processing')", $user_id ) );
}

// Settings
$api_key       = get_option( 'bizcity_tool_heygen_api_key', '' );
$endpoint      = get_option( 'bizcity_tool_heygen_endpoint', 'https://api.heygen.com' );
$default_mode  = get_option( 'bizcity_tool_heygen_default_mode', 'text' );

// All characters (for Characters tab, includes inactive)
$all_characters = BizCity_Tool_HeyGen_Database::get_all_characters();

// Current tab
$current_tab = sanitize_text_field( $_GET['tab'] ?? 'create' );
$allowed_tabs = [ 'create', 'characters', 'monitor', 'chat', 'settings' ];
if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
    $current_tab = 'create';
}

$nonce = wp_create_nonce( 'bthg_nonce' );
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Video Avatar by HeyGen</title>
    <style>
        :root {
            --brand-color: #7c3aed;
            --brand-light: #ede9fe;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg); color: var(--text);
            padding-bottom: 72px; /* bottom nav space */
            -webkit-font-smoothing: antialiased;
        }

        /* ── Hero ── */
        .bthg-hero {
            background: linear-gradient(135deg, #7c3aed 0%, #a78bfa 50%, #c4b5fd 100%);
            color: #fff; padding: 24px 16px; text-align: center;
            border-radius: 0 0 20px 20px;
        }
        .bthg-hero-icon { width: 56px; height: 56px; border-radius: 14px; margin-bottom: 8px; background: rgba(255,255,255,.2); display: inline-flex; align-items: center; justify-content: center; font-size: 28px; }
        .bthg-hero h1 { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .bthg-hero p { font-size: 12px; opacity: .85; }
        .bthg-stats { display: flex; justify-content: center; gap: 20px; margin-top: 12px; }
        .bthg-stat { text-align: center; }
        .bthg-stat-val { display: block; font-size: 20px; font-weight: 700; }
        .bthg-stat-lbl { font-size: 10px; opacity: .8; }

        /* ── Tabs ── */
        .bthg-tab { display: none; padding: 16px; }
        .bthg-tab.active { display: block; }

        /* ── Cards ── */
        .bthg-card {
            background: var(--card-bg); border-radius: 12px;
            border: 1px solid var(--border); padding: 16px;
            margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .bthg-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 8px; }

        /* ── Form elements ── */
        .bthg-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }
        .bthg-input, .bthg-textarea, .bthg-select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 14px; background: var(--card-bg);
            transition: border-color .2s;
        }
        .bthg-input:focus, .bthg-textarea:focus, .bthg-select:focus {
            outline: none; border-color: var(--brand-color);
            box-shadow: 0 0 0 3px rgba(124,58,237,.12);
        }
        .bthg-textarea { resize: vertical; min-height: 80px; font-family: inherit; }
        .bthg-field { margin-bottom: 12px; }

        /* ── Pills ── */
        .bthg-pills { display: flex; flex-wrap: wrap; gap: 6px; }
        .bthg-pill {
            padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
            border: 1px solid var(--border); background: var(--card-bg); cursor: pointer;
            transition: all .2s;
        }
        .bthg-pill.active { background: var(--brand-color); color: #fff; border-color: var(--brand-color); }
        .bthg-pill:hover { border-color: var(--brand-color); }

        /* ── Button ── */
        .bthg-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 600;
            border: none; cursor: pointer; transition: all .2s; width: 100%;
        }
        .bthg-btn-primary { background: var(--brand-color); color: #fff; }
        .bthg-btn-primary:hover { background: #6d28d9; }
        .bthg-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
        .bthg-btn-sm { padding: 6px 12px; font-size: 11px; width: auto; border-radius: 6px; }
        .bthg-btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .bthg-btn-outline:hover { border-color: var(--brand-color); color: var(--brand-color); }
        .bthg-btn-danger { background: var(--danger); color: #fff; }

        /* ── Console ── */
        .bthg-console {
            background: #0f172a; color: #a5f3fc; border-radius: 8px;
            padding: 10px 12px; font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 11px; line-height: 1.6; max-height: 150px;
            overflow-y: auto; margin-bottom: 12px;
        }
        .bthg-console .log-time { color: #6b7280; }
        .bthg-console .log-ok { color: #4ade80; }
        .bthg-console .log-err { color: #f87171; }
        .bthg-console .log-warn { color: #fbbf24; }

        /* ── Job cards ── */
        .bthg-job {
            background: var(--card-bg); border: 1px solid var(--border);
            border-radius: 10px; padding: 12px; margin-bottom: 10px;
        }
        .bthg-job-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
        .bthg-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700; text-transform: uppercase;
        }
        .bthg-badge-completed { background: #dcfce7; color: #166534; }
        .bthg-badge-processing { background: #fef3c7; color: #92400e; }
        .bthg-badge-queued { background: #e0e7ff; color: #3730a3; }
        .bthg-badge-failed { background: #fee2e2; color: #991b1b; }
        .bthg-badge-mode { background: var(--brand-light); color: var(--brand-color); }
        .bthg-job-script { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .bthg-job-meta { font-size: 11px; color: var(--text-muted); margin-bottom: 6px; }

        /* ── Pipeline steps ── */
        .bthg-steps { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .bthg-step { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #64748b; }
        .bthg-step.done { background: #dcfce7; color: #166534; }
        .bthg-step.active { background: #fef3c7; color: #92400e; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .6; } }

        /* ── Action buttons row ── */
        .bthg-actions { display: flex; gap: 6px; flex-wrap: wrap; }

        /* ── Progress bar ── */
        .bthg-progress { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 8px; }
        .bthg-progress-bar { height: 100%; background: var(--brand-color); border-radius: 3px; transition: width .5s; }

        /* ── Command card (Chat tab) ── */
        .bthg-cmd {
            display: flex; align-items: flex-start; gap: 12px;
            background: var(--card-bg); border: 1px solid var(--border);
            border-radius: 12px; padding: 14px; margin-bottom: 8px;
            cursor: pointer; transition: all .2s;
        }
        .bthg-cmd:hover { border-color: #c7d2fe; box-shadow: 0 4px 16px rgba(124,58,237,.1); transform: translateY(-1px); }
        .bthg-cmd:active { transform: scale(.97); }
        .bthg-cmd-primary { border-color: #c7d2fe; background: linear-gradient(135deg, #fefefe, #f5f3ff); }
        .bthg-cmd-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; background: var(--brand-light); }
        .bthg-cmd-body { flex: 1; }
        .bthg-cmd-label { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .bthg-cmd-desc { font-size: 11px; color: var(--text-muted); }

        /* ── Tips ── */
        .bthg-tip {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; background: #fffbeb; border: 1px solid #fef3c7;
            border-radius: 8px; font-size: 12px; color: #92400e;
            cursor: pointer; margin-bottom: 6px; transition: background .2s;
        }
        .bthg-tip:hover { background: #fef9c3; }

        /* ── Section heading ── */
        .bthg-section { font-size: 14px; font-weight: 600; margin: 16px 0 8px; color: var(--text); }

        /* ── Character list ── */
        .bthg-char-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px; border: 1px solid var(--border); border-radius: 8px;
            margin-bottom: 8px; background: var(--card-bg); cursor: pointer;
            transition: all .2s;
        }
        .bthg-char-item:hover { border-color: #c7d2fe; box-shadow: 0 2px 8px rgba(124,58,237,.08); }
        .bthg-char-item.inactive { opacity: .6; }
        .bthg-char-avatar { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; overflow: hidden; }
        .bthg-char-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .bthg-char-info { flex: 1; min-width: 0; }
        .bthg-char-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bthg-char-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .bthg-char-status { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .bthg-char-status.on { background: var(--success); }
        .bthg-char-status.off { background: var(--danger); }

        /* ── Bottom Nav ── */
        .bthg-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            display: flex; background: #fff; border-top: 1px solid var(--border);
            z-index: 100; padding: 6px 0 env(safe-area-inset-bottom, 4px);
        }
        .bthg-nav-item {
            flex: 1; display: flex; flex-direction: column; align-items: center;
            gap: 2px; padding: 6px 4px; text-decoration: none; color: var(--text-muted);
            font-size: 10px; font-weight: 600; transition: color .2s;
        }
        .bthg-nav-item.active { color: var(--brand-color); }
        .bthg-nav-icon { font-size: 20px; }

        /* ── Alerts ── */
        .bthg-alert { padding: 10px 12px; border-radius: 8px; font-size: 12px; margin-bottom: 12px; }
        .bthg-alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .bthg-alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .bthg-alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        /* ── Training panels ── */
        .bthg-train-panel { transition: all .2s; }
        .bthg-train-panel:hover { box-shadow: 0 2px 12px rgba(0,0,0,.06); }

        /* ── Utilities ── */
        .hidden { display: none !important; }
        .mt-8 { margin-top: 8px; }
        .mt-12 { margin-top: 12px; }
        .text-center { text-align: center; }
    </style>
</head>
<body>

<!-- ══════════════ HERO ══════════════ -->
<div class="bthg-hero">
    <div class="bthg-hero-icon">🎭</div>
    <h1>Video Avatar by HeyGen</h1>
    <p>Tạo video lipsync từ nhân vật AI + lời thoại</p>
    <div class="bthg-stats">
        <div class="bthg-stat">
            <span class="bthg-stat-val" id="stat-total"><?php echo esc_html( $stats['total'] ); ?></span>
            <span class="bthg-stat-lbl">Video</span>
        </div>
        <div class="bthg-stat">
            <span class="bthg-stat-val" id="stat-done"><?php echo esc_html( $stats['done'] ); ?></span>
            <span class="bthg-stat-lbl">Hoàn thành</span>
        </div>
        <div class="bthg-stat">
            <span class="bthg-stat-val" id="stat-active"><?php echo esc_html( $stats['active'] ); ?></span>
            <span class="bthg-stat-lbl">Đang chạy</span>
        </div>
    </div>
</div>

<!-- ══════════════ TAB 1: CREATE ══════════════ -->
<div class="bthg-tab <?php echo $current_tab === 'create' ? 'active' : ''; ?>" id="tab-create">
    <div class="bthg-card">
        <h3>🎬 Tạo Video Lipsync</h3>

        <!-- Step 1: Chọn nhân vật -->
        <div class="bthg-field">
            <label class="bthg-label">👤 Chọn nhân vật</label>
            <select class="bthg-select" id="create-character">
                <?php if ( empty( $characters ) ) : ?>
                    <option value="">— Chưa có nhân vật (tạo trong Cài đặt) —</option>
                <?php else : ?>
                    <?php foreach ( $characters as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>"
                                data-voice="<?php echo esc_attr( $c->voice_id ?: '' ); ?>"
                                data-img="<?php echo esc_attr( $c->image_url ?: '' ); ?>">
                            <?php echo esc_html( $c->name ); ?>
                            <?php if ( empty( $c->voice_id ) ) echo ' (chưa clone voice)'; ?>
                            <?php if ( empty( $c->avatar_id ) && empty( $c->image_url ) ) echo ' (chưa có ảnh)'; ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <!-- Step 2: Chọn chế độ -->
        <div class="bthg-field">
            <label class="bthg-label">🎙️ Chế độ lời thoại</label>
            <div class="bthg-pills" id="create-mode">
                <span class="bthg-pill active" data-val="text" onclick="bthgSwitchMode('text')">📝 Text → TTS</span>
                <span class="bthg-pill" data-val="audio" onclick="bthgSwitchMode('audio')">🎵 Upload Audio</span>
            </div>
        </div>

        <!-- Step 3a: TTS mode — nhập lời thoại -->
        <div id="mode-text-panel">
            <div class="bthg-field">
                <label class="bthg-label">📝 Lời thoại / Script</label>
                <textarea class="bthg-textarea" id="create-script" rows="4"
                          placeholder="Nhập lời thoại cho nhân vật AI...&#10;Ví dụ: Xin chào mọi người, hôm nay mình sẽ giới thiệu..."></textarea>
            </div>
            <div class="bthg-alert bthg-alert-info" style="font-size:11px;" id="tts-voice-hint">
                💡 Voice sẽ được lấy từ nhân vật đã chọn (voice_id). Nếu chưa clone voice, vào tab Nhân vật để clone trước.
            </div>
        </div>

        <!-- Step 3b: Audio mode — upload file -->
        <div id="mode-audio-panel" class="hidden">
            <div class="bthg-field">
                <label class="bthg-label">🎵 Upload file âm thanh</label>
                <input type="file" class="bthg-input" id="create-audio-file" accept="audio/*,.mp3,.wav,.m4a,.ogg,.webm">
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    Hỗ trợ: MP3, WAV, M4A, OGG, WebM (tối đa 50MB)
                </div>
            </div>
            <div class="bthg-field">
                <label class="bthg-label">📝 Script (tùy chọn — để lưu làm mô tả)</label>
                <textarea class="bthg-textarea" id="create-script-audio" rows="2"
                          placeholder="Mô tả nội dung audio (tùy chọn)..."></textarea>
            </div>
            <div class="bthg-alert bthg-alert-info" style="font-size:11px;">
                🎧 Audio sẽ upload lên server → gửi URL đến HeyGen để tạo lipsync. Nhân vật sẽ nói chính xác theo file audio.
            </div>
        </div>

        <!-- Free plan warning -->
        <div class="bthg-alert" style="background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; font-size:11px; margin-top:8px;">
            ⚠️ <strong>Gói miễn phí:</strong> Video tối đa <strong>1 phút</strong>, 720p. Audio/script dài hơn 1 phút sẽ bị lỗi. Nâng cấp tài khoản HeyGen để bỏ giới hạn.
        </div>

        <button class="bthg-btn bthg-btn-primary" id="btn-create" onclick="bthgCreateVideo()" style="margin-top:12px;">
            🚀 Tạo Video
        </button>

        <div id="create-result" class="hidden mt-12"></div>
    </div>

    <!-- Inline job preview (after submit) -->
    <div id="create-job-preview" class="hidden">
        <div class="bthg-card" style="border-color:var(--brand-color); border-width:2px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <span class="bthg-badge bthg-badge-processing" id="preview-status">⏳ Đang xử lý</span>
                <span style="font-size:12px; font-weight:600;" id="preview-char-name"></span>
            </div>
            <div class="bthg-progress"><div class="bthg-progress-bar" id="preview-progress" style="width:5%"></div></div>
            <div style="font-size:11px; color:var(--text-muted);" id="preview-script-text"></div>
        </div>
    </div>

    <p class="bthg-section">💡 Gợi ý</p>
    <div class="bthg-tip" data-msg="Tạo video chào buổi sáng">💡 "Tạo video chào buổi sáng"</div>
    <div class="bthg-tip" data-msg="Video giới thiệu sản phẩm mới">💡 "Video giới thiệu sản phẩm mới"</div>
    <div class="bthg-tip" data-msg="Video CTA kêu gọi follow kênh">💡 "Video CTA kêu gọi follow kênh"</div>
</div>

<!-- ══════════════ TAB 2: MONITOR ══════════════ -->
<div class="bthg-tab <?php echo $current_tab === 'monitor' ? 'active' : ''; ?>" id="tab-monitor">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <p class="bthg-section" style="margin:0;">📊 Monitor</p>
        <button class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgPollJobs()">🔄 Làm mới</button>
    </div>

    <!-- Stats badges -->
    <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
        <span class="bthg-badge bthg-badge-mode" id="badge-total">🎬 <?php echo esc_html( $stats['total'] ); ?></span>
        <span class="bthg-badge bthg-badge-completed" id="badge-done">✅ <?php echo esc_html( $stats['done'] ); ?></span>
        <span class="bthg-badge bthg-badge-processing" id="badge-active">⏳ <?php echo esc_html( $stats['active'] ); ?> đang chạy</span>
    </div>

    <!-- Live console -->
    <div class="bthg-console" id="console">
        <div><span class="log-time">[<?php echo esc_html( current_time( 'H:i:s' ) ); ?>]</span> Monitor ready. <span class="log-ok">ON</span></div>
    </div>

    <!-- Job list (PHP initial render + JS update) -->
    <div id="job-list">
        <?php if ( empty( $jobs ) ) : ?>
            <div class="bthg-card text-center" style="color:var(--text-muted); font-size:13px;">
                Chưa có video nào. Hãy tạo video mới! 🎬
            </div>
        <?php else : ?>
            <?php foreach ( $jobs as $j ) :
                $cp = $j['checkpoints'] ?: [];
                $status_class = 'bthg-badge-' . $j['status'];
                $status_label = [
                    'draft' => 'Nháp', 'queued' => 'Đang đợi',
                    'processing' => 'Đang xử lý', 'completed' => 'Hoàn thành', 'failed' => 'Thất bại',
                ][ $j['status'] ] ?? $j['status'];
            ?>
                <div class="bthg-job" data-job-id="<?php echo esc_attr( $j['id'] ); ?>">
                    <div class="bthg-job-header">
                        <span class="bthg-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                        <?php if ( ! empty( $j['character_name'] ) ) : ?>
                            <span class="bthg-badge bthg-badge-mode">👤 <?php echo esc_html( $j['character_name'] ); ?></span>
                        <?php endif; ?>
                        <span style="font-size:10px; color:var(--text-muted); margin-left:auto;">#<?php echo esc_html( $j['id'] ); ?></span>
                    </div>
                    <div class="bthg-job-script"><?php echo esc_html( mb_strimwidth( $j['script'] ?: 'N/A', 0, 120, '...' ) ); ?></div>
                    <div class="bthg-job-meta">
                        🎙️ <?php echo esc_html( $j['mode'] ); ?> | #<?php echo esc_html( $j['id'] ); ?> | <?php echo esc_html( $j['created_at'] ); ?>
                    </div>

                    <!-- Pipeline steps -->
                    <div class="bthg-steps">
                        <?php
                        $steps = [
                            'video_submitted' => 'Submitted',
                            'video_completed' => 'HeyGen Done',
                            'media_uploaded'  => 'Media Upload',
                        ];
                        foreach ( $steps as $step_key => $step_label ) :
                            $is_done = isset( $cp[ $step_key ] );
                            $is_active = false;
                            if ( ! $is_done && $j['status'] === 'processing' ) {
                                // Check if previous step is done
                                $keys = array_keys( $steps );
                                $idx = array_search( $step_key, $keys, true );
                                if ( $idx === 0 || ( $idx > 0 && isset( $cp[ $keys[ $idx - 1 ] ] ) ) ) {
                                    $is_active = true;
                                }
                            }
                            $cls = $is_done ? 'done' : ( $is_active ? 'active' : '' );
                            $icon = $is_done ? '✅' : ( $is_active ? '⏳' : '⭕' );
                        ?>
                            <span class="bthg-step <?php echo esc_attr( $cls ); ?>"><?php echo $icon . ' ' . esc_html( $step_label ); ?></span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Progress bar for active jobs -->
                    <?php if ( in_array( $j['status'], [ 'queued', 'processing' ], true ) ) : ?>
                        <div class="bthg-progress">
                            <div class="bthg-progress-bar" style="width:<?php echo intval( $j['progress'] ); ?>%"></div>
                        </div>
                    <?php endif; ?>

                    <!-- Action buttons for completed jobs -->
                    <?php if ( $j['status'] === 'completed' ) : ?>
                        <div class="bthg-actions">
                            <?php if ( empty( $j['media_url'] ) && ! empty( $j['video_url'] ) ) : ?>
                                <button class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgUploadToMedia(<?php echo intval( $j['id'] ); ?>)">✅ Upload Media</button>
                            <?php endif; ?>
                            <?php
                            $link = $j['media_url'] ?: $j['video_url'];
                            if ( $link ) : ?>
                                <button class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgCopyLink('<?php echo esc_url( $link ); ?>')">🔗 Copy</button>
                                <a href="<?php echo esc_url( $link ); ?>" target="_blank" class="bthg-btn bthg-btn-sm bthg-btn-outline">▶ Xem</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Error message -->
                    <?php if ( $j['status'] === 'failed' && ! empty( $j['error_message'] ) ) : ?>
                        <div class="bthg-alert bthg-alert-error mt-8">
                            ❌ <?php echo esc_html( $j['error_message'] ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════ TAB 3: CHARACTERS ══════════════ -->
<div class="bthg-tab <?php echo $current_tab === 'characters' ? 'active' : ''; ?>" id="tab-characters">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <p class="bthg-section" style="margin:0;">👥 Nhân vật AI</p>
        <?php if ( $is_admin ) : ?>
            <button class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgToggleCharForm()">➕ Thêm</button>
        <?php endif; ?>
    </div>

    <!-- Character form (hidden by default) -->
    <?php if ( $is_admin ) : ?>
    <div id="char-form" class="hidden">
        <input type="hidden" id="char-edit-id" value="0">

        <!-- ─── Section 1: Thông tin cơ bản ─── -->
        <div class="bthg-card" style="border: 2px solid var(--brand-color); margin-bottom:12px;">
            <h3 id="char-form-title" style="display:flex; align-items:center; gap:8px;">
                <span style="width:28px; height:28px; border-radius:8px; background:var(--brand-light); display:inline-flex; align-items:center; justify-content:center; font-size:14px;">👤</span>
                ➕ Thêm nhân vật mới
            </h3>
            <div class="bthg-field">
                <label class="bthg-label">Tên nhân vật *</label>
                <input type="text" class="bthg-input" id="char-name" placeholder="Ví dụ: MC Bán hàng">
            </div>
            <div class="bthg-field">
                <label class="bthg-label">Slug (tự tạo từ tên)</label>
                <input type="text" class="bthg-input" id="char-slug" placeholder="mc-ban-hang" style="font-size:12px; color:var(--text-muted);" readonly>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="bthg-field">
                    <label class="bthg-label">Ngôn ngữ</label>
                    <select class="bthg-select" id="char-lang">
                        <option value="vi">Tiếng Việt</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div class="bthg-field">
                    <label class="bthg-label">Trạng thái</label>
                    <select class="bthg-select" id="char-status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="bthg-field">
                <label class="bthg-label">Mô tả</label>
                <input type="text" class="bthg-input" id="char-desc" placeholder="Nữ MC nhẹ nhàng, truyền cảm">
            </div>
            <div class="bthg-field">
                <label class="bthg-label">Prompt tính cách</label>
                <textarea class="bthg-textarea" id="char-persona" rows="2" placeholder="Nữ MC nhẹ nhàng, truyền cảm, nói chậm, gần gũi..."></textarea>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="bthg-field">
                    <label class="bthg-label">Phong cách</label>
                    <input type="text" class="bthg-input" id="char-tone" placeholder="Nhẹ nhàng / Mạnh mẽ">
                </div>
                <div class="bthg-field">
                    <label class="bthg-label">CTA mặc định</label>
                    <input type="text" class="bthg-input" id="char-cta" placeholder="Đăng ký ngay!">
                </div>
            </div>
            <div style="display:flex; gap:8px; margin-top:4px;">
                <button class="bthg-btn bthg-btn-primary" onclick="bthgSaveCharacter()" style="flex:1;">💾 Lưu nhân vật</button>
                <button class="bthg-btn bthg-btn-outline" onclick="bthgToggleCharForm()" style="flex:1;">Hủy</button>
            </div>
        </div>

        <!-- ═══ TRAINING CENTER (3 panels, shown only when editing) ═══ -->
        <div id="char-training-center" class="hidden">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                <span style="font-size:16px;">🎯</span>
                <h3 style="font-size:14px; font-weight:700; margin:0;">Training Center</h3>
                <span style="font-size:11px; color:var(--text-muted); margin-left:auto;">Nhân vật: <strong id="training-char-name">—</strong></span>
            </div>

            <!-- Training overview badges -->
            <div id="training-overview" style="display:flex; gap:6px; margin-bottom:12px; flex-wrap:wrap;">
                <span class="bthg-badge" id="badge-photo-train" style="font-size:11px;">📸 Ảnh: <span>—</span></span>
                <span class="bthg-badge" id="badge-video-train" style="font-size:11px;">🎬 Video: <span>—</span></span>
                <span class="bthg-badge" id="badge-voice-train" style="font-size:11px;">🎙️ Giọng: <span>—</span></span>
            </div>

            <!-- ─── Panel 1: Training theo ảnh (Photo Avatar) ─── -->
            <div class="bthg-card bthg-train-panel" style="border-left:3px solid #8b5cf6; margin-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;" onclick="bthgTogglePanel('photo')">
                    <span style="width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,#ede9fe,#f5f3ff); display:flex; align-items:center; justify-content:center; font-size:16px;">📸</span>
                    <div style="flex:1;">
                        <div style="font-size:13px; font-weight:600;">Training theo ảnh</div>
                        <div style="font-size:11px; color:var(--text-muted);">Upload ảnh chân dung → HeyGen tạo Photo Avatar</div>
                    </div>
                    <span id="photo-train-badge" class="bthg-badge" style="font-size:10px;">⭕ Chưa train</span>
                </div>
                <div id="panel-photo" style="display:none;">
                    <!-- Pipeline -->
                    <div class="bthg-steps" id="photo-pipeline" style="margin-bottom:10px;">
                        <span class="bthg-step" id="photo-step-upload">① Upload ảnh</span>
                        <span class="bthg-step" id="photo-step-push">② Đẩy lên HeyGen</span>
                        <span class="bthg-step" id="photo-step-train">③ Training</span>
                        <span class="bthg-step" id="photo-step-done">④ Hoàn tất</span>
                    </div>
                    <div class="bthg-field">
                        <label class="bthg-label">Ảnh đại diện (URL hoặc upload)</label>
                        <input type="url" class="bthg-input" id="char-image-url" placeholder="https://... hoặc upload file bên dưới">
                        <input type="file" id="char-avatar-file" accept="image/*" class="mt-8" style="font-size:12px;">
                    </div>
                    <div class="bthg-field">
                        <label class="bthg-label">Talking Photo ID (tự động gán sau training)</label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" class="bthg-input" id="char-avatar-id" placeholder="Tự động gán sau khi train" style="flex:1;">
                            <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgFetchTalkingPhotos()" title="Lấy danh sách từ HeyGen">📥 HeyGen</button>
                        </div>
                        <div id="char-tp-list" class="hidden" style="margin-top:8px; max-height:200px; overflow-y:auto; border:1px solid var(--border-color); border-radius:8px; padding:6px; font-size:12px;"></div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgPushPhotoToHeyGen()" id="btn-push-heygen" style="flex:none;">🚀 Bắt đầu Training ảnh</button>
                        <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgSaveCharacter()" style="flex:none;">💾 Lưu</button>
                    </div>
                    <div id="char-push-status" class="hidden" style="margin-top:8px; padding:8px 10px; border-radius:8px; font-size:12px; background:var(--bg-muted);"></div>
                </div>
            </div>

            <!-- ─── Panel 2: Training theo video (Video Avatar) ─── -->
            <div class="bthg-card bthg-train-panel" style="border-left:3px solid #3b82f6; margin-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;" onclick="bthgTogglePanel('video')">
                    <span style="width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,#dbeafe,#eff6ff); display:flex; align-items:center; justify-content:center; font-size:16px;">🎬</span>
                    <div style="flex:1;">
                        <div style="font-size:13px; font-weight:600;">Video Avatar</div>
                        <div style="font-size:11px; color:var(--text-muted);">Tạo Video Avatar trên HeyGen → dán ID vào đây</div>
                    </div>
                    <span id="video-train-badge" class="bthg-badge" style="font-size:10px;">⭕ Chưa có</span>
                </div>
                <div id="panel-video" style="display:none;">
                    <div class="bthg-steps" id="video-pipeline" style="margin-bottom:10px;">
                        <span class="bthg-step" id="video-step-upload">① Tạo trên HeyGen</span>
                        <span class="bthg-step" id="video-step-push">② Copy ID</span>
                        <span class="bthg-step" id="video-step-train">③ Dán vào đây</span>
                        <span class="bthg-step" id="video-step-done">④ Hoàn tất</span>
                    </div>
                    <div class="bthg-field">
                        <label class="bthg-label">Video Avatar ID</label>
                        <p style="margin:0 0 6px; font-size:11px; color:var(--text-muted);">
                            Vào <a href="https://app.heygen.com/avatars" target="_blank" style="color:#3b82f6; font-weight:600;">app.heygen.com/avatars</a>
                            → tạo Video Avatar → copy Avatar ID → dán vào đây.
                        </p>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" class="bthg-input" id="char-video-avatar-id" placeholder="Dán Video Avatar ID từ HeyGen..." style="flex:1;">
                            <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgSaveVideoAvatarId()" style="flex:none;">💾 Lưu ID</button>
                        </div>
                    </div>
                    <div id="char-video-status" class="hidden" style="margin-top:8px; padding:8px 10px; border-radius:8px; font-size:12px; background:var(--bg-muted);"></div>
                </div>
            </div>

            <!-- ─── Panel 3: Training giọng (Voice Clone) ─── -->
            <div class="bthg-card bthg-train-panel" style="border-left:3px solid #10b981; margin-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;" onclick="bthgTogglePanel('voice')">
                    <span style="width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,#d1fae5,#ecfdf5); display:flex; align-items:center; justify-content:center; font-size:16px;">🎙️</span>
                    <div style="flex:1;">
                        <div style="font-size:13px; font-weight:600;">Training giọng nói</div>
                        <div style="font-size:11px; color:var(--text-muted);">Upload audio mẫu → HeyGen clone giọng nói</div>
                    </div>
                    <span id="voice-train-badge" class="bthg-badge" style="font-size:10px;">⭕ Chưa train</span>
                </div>
                <div id="panel-voice" style="display:none;">
                    <!-- Pipeline -->
                    <div class="bthg-steps" id="voice-pipeline" style="margin-bottom:10px;">
                        <span class="bthg-step" id="voice-step-upload">① Upload audio</span>
                        <span class="bthg-step" id="voice-step-clone">② Clone giọng</span>
                        <span class="bthg-step" id="voice-step-done">③ Hoàn tất</span>
                    </div>
                    <div class="bthg-field">
                        <label class="bthg-label">File âm thanh mẫu (.mp3, .m4a, .wav)</label>
                        <input type="file" id="char-voice-file" accept="audio/*" style="font-size:12px;">
                        <div id="char-voice-info" class="mt-8" style="font-size:11px; color:var(--text-muted);"></div>
                        <p style="margin:4px 0 0; font-size:11px; color:var(--text-muted);">🎤 Yêu cầu: Đoạn nói tự nhiên 30s-3 phút, ít tạp âm, nói rõ ràng.</p>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgStartVoiceClone()" id="btn-clone-voice" style="flex:none; background:#10b981;">🚀 Bắt đầu Clone giọng</button>
                        <button type="button" class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgSaveCharacter()" style="flex:none;">💾 Lưu</button>
                    </div>
                    <div id="char-voice-status" class="hidden" style="margin-top:8px; padding:8px 10px; border-radius:8px; font-size:12px; background:var(--bg-muted);"></div>
                </div>
            </div>

        </div><!-- /char-training-center -->
    </div>
    <?php endif; ?>

    <!-- Character listing -->
    <div id="character-list">
        <?php if ( empty( $all_characters ) ) : ?>
            <div class="bthg-card text-center">
                <p style="font-size:13px; color:var(--text-muted); padding:20px 0;">
                    👤 Chưa có nhân vật nào.<br>
                    <?php if ( $is_admin ) : ?>Bấm <strong>➕ Thêm</strong> để tạo nhân vật mới.<?php endif; ?>
                </p>
            </div>
        <?php else : ?>
            <?php foreach ( $all_characters as $c ) :
                $is_inactive = ( $c->status === 'inactive' );
                $has_voice = ! empty( $c->voice_id );
                $has_avatar = ! empty( $c->avatar_id ) || ! empty( $c->image_url );
                $c_meta = json_decode( $c->metadata ?? '{}', true ) ?: [];
                $photo_status = $c_meta['heygen_push_status'] ?? '';
                $video_status = $c_meta['heygen_video_push_status'] ?? '';
                $voice_clone  = $c->voice_clone_status ?? 'none';
            ?>
                <div class="bthg-char-item <?php echo $is_inactive ? 'inactive' : ''; ?>" data-char-id="<?php echo esc_attr( $c->id ); ?>" onclick="bthgViewCharacter(<?php echo intval( $c->id ); ?>)">
                    <div class="bthg-char-avatar">
                        <?php if ( $c->image_url ) : ?>
                            <img src="<?php echo esc_url( $c->image_url ); ?>" alt="<?php echo esc_attr( $c->name ); ?>">
                        <?php else : ?>
                            👤
                        <?php endif; ?>
                    </div>
                    <div class="bthg-char-info">
                        <div class="bthg-char-name">
                            <span class="bthg-char-status <?php echo $is_inactive ? 'off' : 'on'; ?>"></span>
                            <?php echo esc_html( $c->name ); ?>
                        </div>
                        <div class="bthg-char-meta">
                            📸 <?php
                                if ( $photo_status === 'ready' ) echo '<span style="color:var(--success)" title="Photo Avatar OK">✅</span>';
                                elseif ( $photo_status === 'training' ) echo '<span style="color:var(--warning)" title="Đang training ảnh">⏳</span>';
                                elseif ( $has_avatar ) echo '<span style="color:var(--info)" title="Có ảnh, chưa push">🔵</span>';
                                else echo '<span style="color:var(--text-muted)" title="Chưa có ảnh">—</span>';
                            ?>
                            🎬 <?php
                                if ( $video_status === 'ready' ) echo '<span style="color:var(--success)" title="Video Avatar OK">✅</span>';
                                elseif ( $video_status === 'training' ) echo '<span style="color:var(--warning)" title="Đang training video">⏳</span>';
                                else echo '<span style="color:var(--text-muted)" title="Chưa có video">—</span>';
                            ?>
                            🎙️ <?php
                                if ( $voice_clone === 'cloned' && ! empty( $c->voice_id ) ) echo '<span style="color:var(--success)" title="Voice cloned">✅</span>';
                                elseif ( $voice_clone === 'cloning' ) echo '<span style="color:var(--warning)" title="Đang clone">⏳</span>';
                                elseif ( ! empty( $c->voice_sample_url ) ) echo '<span style="color:var(--info)" title="Có voice, chưa clone">🔵</span>';
                                else echo '<span style="color:var(--text-muted)" title="Chưa có voice">—</span>';
                            ?>
                            · <?php echo esc_html( $c->language ); ?>
                            · <?php echo esc_html( $c->slug ); ?>
                        </div>
                    </div>
                    <?php if ( $is_admin ) : ?>
                    <div style="display:flex; gap:4px;" onclick="event.stopPropagation();">
                        <?php if ( $c->voice_sample_url && $c->voice_clone_status !== 'cloned' ) : ?>
                            <button class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgCloneVoice(<?php echo intval( $c->id ); ?>)">🎙️</button>
                        <?php endif; ?>
                        <button class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgEditCharacter(<?php echo intval( $c->id ); ?>)">✏️</button>
                        <button class="bthg-btn bthg-btn-sm bthg-btn-danger" onclick="bthgDeleteCharacter(<?php echo intval( $c->id ); ?>)">🗑️</button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Character detail panel (shown on click) -->
    <div id="char-detail" class="bthg-card hidden" style="border:2px solid var(--info);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <h3 id="char-detail-title">Chi tiết nhân vật</h3>
            <button class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="document.getElementById('char-detail').classList.add('hidden');">✕</button>
        </div>
        <div id="char-detail-body" style="font-size:12px; line-height:1.8;"></div>
    </div>
</div>

<!-- ══════════════ TAB 4: CHAT ══════════════ -->
<div class="bthg-tab <?php echo $current_tab === 'chat' ? 'active' : ''; ?>" id="tab-chat">
    <p class="bthg-section">💬 Gửi lệnh qua Chat</p>

    <div class="bthg-cmd bthg-cmd-primary" data-msg="Tạo video lipsync từ nhân vật AI">
        <div class="bthg-cmd-icon">🎬</div>
        <div class="bthg-cmd-body">
            <div class="bthg-cmd-label">Tạo video lipsync</div>
            <div class="bthg-cmd-desc">Chọn nhân vật AI + nhập lời thoại → video lipsync tự động</div>
        </div>
    </div>

    <div class="bthg-cmd" data-msg="Danh sách nhân vật AI">
        <div class="bthg-cmd-icon">👥</div>
        <div class="bthg-cmd-body">
            <div class="bthg-cmd-label">Danh sách nhân vật</div>
            <div class="bthg-cmd-desc">Xem các nhân vật AI đã cấu hình</div>
        </div>
    </div>

    <div class="bthg-cmd" data-msg="Kiểm tra trạng thái video">
        <div class="bthg-cmd-icon">📊</div>
        <div class="bthg-cmd-body">
            <div class="bthg-cmd-label">Kiểm tra trạng thái</div>
            <div class="bthg-cmd-desc">Xem tiến trình video đang xử lý</div>
        </div>
    </div>

    <p class="bthg-section mt-12">💡 Gợi ý prompt</p>
    <div class="bthg-tip" data-msg="Tạo video lipsync chào buổi sáng với nhân vật MC">💡 "Tạo video lipsync chào buổi sáng với nhân vật MC"</div>
    <div class="bthg-tip" data-msg="Video giới thiệu sản phẩm bằng AI avatar">💡 "Video giới thiệu sản phẩm bằng AI avatar"</div>
    <div class="bthg-tip" data-msg="Video CTA bán hàng với giọng nữ nhẹ nhàng">💡 "Video CTA bán hàng với giọng nữ nhẹ nhàng"</div>
</div>

<!-- ══════════════ TAB 5: SETTINGS ══════════════ -->
<div class="bthg-tab <?php echo $current_tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">
    <?php if ( $is_admin ) : ?>
    <!-- Admin: API Configuration -->
    <div class="bthg-card">
        <h3>🔑 API Configuration (Admin)</h3>
        <div class="bthg-field">
            <label class="bthg-label">API Key</label>
            <input type="password" class="bthg-input" id="set-api-key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="Nhập HeyGen API Key">
        </div>
        <div class="bthg-field">
            <label class="bthg-label">Endpoint</label>
            <input type="url" class="bthg-input" id="set-endpoint" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="https://api.heygen.com">
        </div>
    </div>
    <?php endif; ?>

    <!-- Default Settings (all users) -->
    <div class="bthg-card">
        <h3>🎛 Mặc định tạo video</h3>
        <div class="bthg-field">
            <label class="bthg-label">Chế độ mặc định</label>
            <div class="bthg-pills" id="set-mode">
                <span class="bthg-pill <?php echo $default_mode === 'text' ? 'active' : ''; ?>" data-val="text">Text → TTS</span>
                <span class="bthg-pill <?php echo $default_mode === 'audio' ? 'active' : ''; ?>" data-val="audio">Audio Upload</span>
            </div>
        </div>

        <button class="bthg-btn bthg-btn-primary mt-12" onclick="bthgSaveSettings()">💾 Lưu cài đặt</button>
        <div id="settings-result" class="hidden mt-8"></div>
    </div>

    <!-- Info block -->
    <div class="bthg-card">
        <h3>ℹ️ Thông tin</h3>
        <p style="font-size:12px; color:var(--text-muted); line-height:1.6;">
            Plugin: BizCity Tool HeyGen v1.0.0<br>
            Gateway: HeyGen API v2<br>
            Engine: bizcity-intent v4.4<br>
            Nhân vật: <?php echo count( $characters ); ?> active
        </p>
    </div>
</div>

<!-- ══════════════ BOTTOM NAV ══════════════ -->
<nav class="bthg-nav">
    <a href="?tab=create" class="bthg-nav-item <?php echo $current_tab === 'create' ? 'active' : ''; ?>" data-tab="create">
        <span class="bthg-nav-icon">🎬</span> Tạo
    </a>
    <a href="?tab=characters" class="bthg-nav-item <?php echo $current_tab === 'characters' ? 'active' : ''; ?>" data-tab="characters">
        <span class="bthg-nav-icon">👥</span> Nhân vật
    </a>
    <a href="?tab=monitor" class="bthg-nav-item <?php echo $current_tab === 'monitor' ? 'active' : ''; ?>" data-tab="monitor">
        <span class="bthg-nav-icon">📊</span> Monitor
    </a>
    <a href="?tab=chat" class="bthg-nav-item <?php echo $current_tab === 'chat' ? 'active' : ''; ?>" data-tab="chat">
        <span class="bthg-nav-icon">💬</span> Chat
    </a>
    <a href="?tab=settings" class="bthg-nav-item <?php echo $current_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
        <span class="bthg-nav-icon">⚙️</span> Cài đặt
    </a>
</nav>

<script>
/* ══════════════════════════════════════════════
 *  Config & State
 * ══════════════════════════════════════════════ */
var BTHG = {
    nonce: '<?php echo esc_js( $nonce ); ?>',
    ajax: '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>',
    pollTimer: null,
    prevJobStates: {},
    isAdmin: <?php echo $is_admin ? 'true' : 'false'; ?>,
};

/* ── Tab navigation (no page reload) ── */
document.querySelectorAll('.bthg-nav-item').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var tab = this.getAttribute('data-tab');

        document.querySelectorAll('.bthg-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.bthg-nav-item').forEach(function(n) { n.classList.remove('active'); });

        var el = document.getElementById('tab-' + tab);
        if (el) el.classList.add('active');
        this.classList.add('active');

        history.replaceState(null, '', '?tab=' + tab);

        if (tab === 'monitor') bthgPollJobs();
    });
});

/* ── Pill selection ── */
document.querySelectorAll('.bthg-pills').forEach(function(pills) {
    pills.querySelectorAll('.bthg-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            pills.querySelectorAll('.bthg-pill').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
        });
    });
});

/* ── postMessage to parent (chat tab + tips) ── */
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        var msg = this.getAttribute('data-msg');
        if (!msg) return;

        if (this.classList.contains('bthg-cmd') || this.classList.contains('bthg-tip')) {
            e.preventDefault();
            try {
                window.parent.postMessage({
                    type: 'bizcity_agent_command',
                    text: msg,
                    source: 'bizcity-tool-heygen'
                }, '*');
            } catch(err) {
                console.error('postMessage failed:', err);
            }
        }
    });
});

/* ══════════════════════════════════════════════
 *  Console Logger
 * ══════════════════════════════════════════════ */
function logConsole(text, type) {
    var c = document.getElementById('console');
    if (!c) return;
    var now = new Date();
    var ts = [now.getHours(), now.getMinutes(), now.getSeconds()].map(function(n) { return String(n).padStart(2, '0'); }).join(':');
    var cls = type === 'ok' ? 'log-ok' : (type === 'err' ? 'log-err' : (type === 'warn' ? 'log-warn' : ''));
    var line = document.createElement('div');
    line.innerHTML = '<span class="log-time">[' + ts + ']</span> <span class="' + cls + '">' + text + '</span>';
    c.appendChild(line);
    // Keep max 50 lines
    while (c.children.length > 50) c.removeChild(c.firstChild);
    c.scrollTop = c.scrollHeight;
}

/* ══════════════════════════════════════════════
 *  Mode Switch (Text ↔ Audio)
 * ══════════════════════════════════════════════ */
function bthgSwitchMode(mode) {
    var textPanel = document.getElementById('mode-text-panel');
    var audioPanel = document.getElementById('mode-audio-panel');
    document.querySelectorAll('#create-mode .bthg-pill').forEach(function(p) { p.classList.remove('active'); });
    document.querySelector('#create-mode .bthg-pill[data-val="' + mode + '"]').classList.add('active');

    if (mode === 'audio') {
        textPanel.classList.add('hidden');
        audioPanel.classList.remove('hidden');
    } else {
        textPanel.classList.remove('hidden');
        audioPanel.classList.add('hidden');
    }
}

/* ══════════════════════════════════════════════
 *  Create Video
 * ══════════════════════════════════════════════ */
function bthgCreateVideo() {
    var btn = document.getElementById('btn-create');
    var charId = document.getElementById('create-character').value;
    var modeEl = document.querySelector('#create-mode .bthg-pill.active');
    var mode = modeEl ? modeEl.getAttribute('data-val') : 'text';

    var script = '';
    var audioFile = null;

    if (mode === 'text') {
        script = document.getElementById('create-script').value.trim();
        if (!script) {
            bthgShowResult('create-result', 'Vui lòng nhập lời thoại.', 'error');
            return;
        }
    } else {
        audioFile = document.getElementById('create-audio-file').files[0];
        if (!audioFile) {
            bthgShowResult('create-result', 'Vui lòng chọn file âm thanh.', 'error');
            return;
        }
        script = document.getElementById('create-script-audio').value.trim() || '[Audio upload]';
    }

    btn.disabled = true;
    btn.textContent = '⏳ Đang gửi...';

    if (mode === 'audio' && audioFile) {
        // Step 1: Upload audio file to WP Media first
        var uploadFd = new FormData();
        uploadFd.append('action', 'bthg_upload_audio_for_video');
        uploadFd.append('nonce', BTHG.nonce);
        uploadFd.append('audio_file', audioFile);

        btn.textContent = '⏳ Upload audio...';

        fetch(BTHG.ajax, { method: 'POST', body: uploadFd })
            .then(function(r) { return r.json(); })
            .then(function(uploadRes) {
                if (!uploadRes.success) {
                    btn.disabled = false;
                    btn.textContent = '🚀 Tạo Video';
                    bthgShowResult('create-result', uploadRes.data.message || 'Lỗi upload audio.', 'error');
                    return;
                }

                // Step 2: Send create video with audio_url
                btn.textContent = '⏳ Tạo video...';
                var fd = new FormData();
                fd.append('action', 'bthg_create_video');
                fd.append('nonce', BTHG.nonce);
                fd.append('character_id', charId);
                fd.append('script', script);
                fd.append('mode', 'audio');
                fd.append('audio_url', uploadRes.data.url);

                return fetch(BTHG.ajax, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        bthgHandleCreateResult(res, btn, script);
                    });
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '🚀 Tạo Video';
                bthgShowResult('create-result', 'Lỗi kết nối: ' + err.message, 'error');
            });
    } else {
        // Text mode: send directly
        var fd = new FormData();
        fd.append('action', 'bthg_create_video');
        fd.append('nonce', BTHG.nonce);
        fd.append('character_id', charId);
        fd.append('script', script);
        fd.append('mode', 'text');

        fetch(BTHG.ajax, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                bthgHandleCreateResult(res, btn, script);
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '🚀 Tạo Video';
                bthgShowResult('create-result', 'Lỗi kết nối: ' + err.message, 'error');
            });
    }
}

function bthgHandleCreateResult(res, btn, script) {
    btn.disabled = false;
    btn.textContent = '🚀 Tạo Video';

    if (res.success) {
        bthgShowResult('create-result', res.data.message || 'Đã gửi yêu cầu tạo video!', 'success');
        document.getElementById('create-script').value = '';
        document.getElementById('create-script-audio').value = '';
        var audioInput = document.getElementById('create-audio-file');
        if (audioInput) audioInput.value = '';

        var jobId = res.data.data ? res.data.data.job_id : '?';
        logConsole('Job #' + jobId + ' created', 'ok');

        // Show inline preview
        var charSelect = document.getElementById('create-character');
        var charName = charSelect.options[charSelect.selectedIndex] ? charSelect.options[charSelect.selectedIndex].text : '';
        var preview = document.getElementById('create-job-preview');
        document.getElementById('preview-char-name').textContent = charName;
        document.getElementById('preview-script-text').textContent = script.substring(0, 120);
        document.getElementById('preview-progress').style.width = '5%';
        document.getElementById('preview-status').textContent = '⏳ Đang xử lý';
        document.getElementById('preview-status').className = 'bthg-badge bthg-badge-processing';
        preview.classList.remove('hidden');

        bthgStartPoll();
    } else {
        bthgShowResult('create-result', res.data.message || 'Lỗi tạo video.', 'error');
    }
}

/* ══════════════════════════════════════════════
 *  Poll Jobs (Monitor)
 * ══════════════════════════════════════════════ */
function bthgPollJobs() {
    var fd = new FormData();
    fd.append('action', 'bthg_poll_jobs');
    fd.append('nonce', BTHG.nonce);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) return;

            var data = res.data;
            // Update stats
            document.getElementById('stat-total').textContent = data.stats.total;
            document.getElementById('stat-done').textContent = data.stats.done;
            document.getElementById('stat-active').textContent = data.stats.active;
            document.getElementById('badge-total').textContent = '🎬 ' + data.stats.total;
            document.getElementById('badge-done').textContent = '✅ ' + data.stats.done;
            document.getElementById('badge-active').textContent = '⏳ ' + data.stats.active + ' đang chạy';

            // Detect changes
            bthgDetectChanges(data.jobs);

            // Render jobs
            bthgRenderJobs(data.jobs);

            // Manage poll timer
            bthgManagePollTimer(data.stats.active > 0);
        })
        .catch(function(err) {
            logConsole('Poll error: ' + err.message, 'err');
        });
}

function bthgDetectChanges(jobs) {
    jobs.forEach(function(j) {
        var prev = BTHG.prevJobStates[j.id];
        if (prev && prev !== j.status) {
            logConsole('Job #' + j.id + ': ' + prev + ' → ' + j.status, j.status === 'completed' ? 'ok' : (j.status === 'failed' ? 'err' : 'warn'));
        }
        BTHG.prevJobStates[j.id] = j.status;
    });
}

function bthgRenderJobs(jobs) {
    var container = document.getElementById('job-list');
    if (!jobs.length) {
        container.innerHTML = '<div class="bthg-card text-center" style="color:var(--text-muted);font-size:13px;">Chưa có video nào. Hãy tạo video mới! 🎬</div>';
        return;
    }

    var html = '';
    jobs.forEach(function(j) {
        var cp = j.checkpoints || {};
        var statusMap = { draft: 'Nháp', queued: 'Đang đợi', processing: 'Đang xử lý', completed: 'Hoàn thành', failed: 'Thất bại' };
        var statusLabel = statusMap[j.status] || j.status;

        html += '<div class="bthg-job" data-job-id="' + j.id + '">';
        html += '<div class="bthg-job-header">';
        html += '<span class="bthg-badge bthg-badge-' + j.status + '">' + statusLabel + '</span>';
        if (j.character_name) html += '<span class="bthg-badge bthg-badge-mode">👤 ' + escHtml(j.character_name) + '</span>';
        html += '<span style="font-size:10px;color:var(--text-muted);margin-left:auto;">#' + j.id + '</span>';
        html += '</div>';

        var script = j.script || 'N/A';
        if (script.length > 120) script = script.substring(0, 120) + '...';
        html += '<div class="bthg-job-script">' + escHtml(script) + '</div>';
        html += '<div class="bthg-job-meta">🎙️ ' + escHtml(j.mode || 'text') + ' | #' + j.id + ' | ' + escHtml(j.created_at) + '</div>';

        // Pipeline steps
        html += '<div class="bthg-steps">';
        html += pipeStep('Submitted', cp.video_submitted ? 'done' : (j.status !== 'draft' ? 'active' : ''));
        html += pipeStep('HeyGen Done', cp.video_completed ? 'done' : (cp.video_submitted && !cp.video_completed && j.status === 'processing' ? 'active' : ''));
        html += pipeStep('Media Upload', cp.media_uploaded ? 'done' : (cp.video_completed && !cp.media_uploaded ? 'active' : ''));
        html += '</div>';

        // Progress bar
        if (j.status === 'queued' || j.status === 'processing') {
            html += '<div class="bthg-progress"><div class="bthg-progress-bar" style="width:' + (j.progress || 0) + '%"></div></div>';
        }

        // Action buttons — show for completed jobs OR stale processing with checkpoints done
        var isEffectivelyDone = (j.status === 'completed') || (j.checkpoints.video_completed && (j.media_url || j.checkpoints.media_uploaded));
        if (isEffectivelyDone) {
            if (j.status !== 'completed') {
                html += '<div class="bthg-alert bthg-alert-success mt-8">✅ Video đã hoàn thành (đang đồng bộ trạng thái...)</div>';
            }
            html += '<div class="bthg-actions">';
            if (!j.media_url && j.video_url) {
                html += '<button class="bthg-btn bthg-btn-sm bthg-btn-primary" onclick="bthgUploadToMedia(' + j.id + ')">✅ Upload Media</button>';
            }
            var link = j.media_url || j.video_url;
            if (link) {
                html += '<a href="' + escAttr(link) + '" download class="bthg-btn bthg-btn-sm bthg-btn-primary">📥 Tải video</a>';
                html += '<a href="' + escAttr(link) + '" target="_blank" class="bthg-btn bthg-btn-sm bthg-btn-outline">▶ Xem video</a>';
                html += '<button class="bthg-btn bthg-btn-sm bthg-btn-outline" onclick="bthgCopyLink(\'' + escAttr(link) + '\')">🔗 Copy link</button>';
            }
            html += '</div>';
        }

        // Error
        if (j.status === 'failed' && j.error_message) {
            html += '<div class="bthg-alert bthg-alert-error mt-8">❌ ' + escHtml(j.error_message) + '</div>';
        }

        html += '</div>';
    });

    container.innerHTML = html;
}

function pipeStep(label, state) {
    if (state === 'done') return '<span class="bthg-step done">✅ ' + label + '</span>';
    if (state === 'active') return '<span class="bthg-step active">⏳ ' + label + '</span>';
    return '<span class="bthg-step">⭕ ' + label + '</span>';
}

function bthgManagePollTimer(hasActive) {
    if (hasActive && !BTHG.pollTimer) {
        BTHG.pollTimer = setInterval(bthgPollJobs, 10000);
        logConsole('Auto-poll started (10s)', 'ok');
    } else if (!hasActive && BTHG.pollTimer) {
        clearInterval(BTHG.pollTimer);
        BTHG.pollTimer = null;
        logConsole('Auto-poll stopped (no active jobs)', 'warn');
    }
}

function bthgStartPoll() {
    if (!BTHG.pollTimer) {
        BTHG.pollTimer = setInterval(bthgPollJobs, 10000);
    }
    bthgPollJobs();
}

/* ══════════════════════════════════════════════
 *  Upload to Media (Manual)
 * ══════════════════════════════════════════════ */
function bthgUploadToMedia(jobId) {
    logConsole('Uploading job #' + jobId + ' to Media Library...', 'warn');
    var fd = new FormData();
    fd.append('action', 'bthg_upload_to_media');
    fd.append('nonce', BTHG.nonce);
    fd.append('job_id', jobId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                logConsole('Job #' + jobId + ': Media uploaded! ' + (res.data.media_url || ''), 'ok');
                bthgPollJobs();
            } else {
                logConsole('Job #' + jobId + ' upload error: ' + (res.data.message || 'Unknown'), 'err');
            }
        });
}

function bthgCopyLink(url) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            logConsole('Link copied!', 'ok');
        });
    }
}

/* ══════════════════════════════════════════════
 *  Settings
 * ══════════════════════════════════════════════ */
function bthgSaveSettings() {
    var fd = new FormData();
    fd.append('action', 'bthg_save_settings');
    fd.append('nonce', BTHG.nonce);

    if (BTHG.isAdmin) {
        var apiKey = document.getElementById('set-api-key');
        var endpoint = document.getElementById('set-endpoint');
        if (apiKey) fd.append('api_key', apiKey.value);
        if (endpoint) fd.append('endpoint', endpoint.value);
    }

    var modeEl = document.querySelector('#set-mode .bthg-pill.active');
    if (modeEl) fd.append('default_mode', modeEl.getAttribute('data-val'));

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            bthgShowResult('settings-result', res.success ? 'Đã lưu cài đặt!' : (res.data.message || 'Lỗi'), res.success ? 'success' : 'error');
        });
}

/* ══════════════════════════════════════════════
 *  Character Management
 * ══════════════════════════════════════════════ */
function bthgToggleCharForm() {
    var form = document.getElementById('char-form');
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth' });
    } else {
        form.classList.add('hidden');
        bthgResetCharForm();
    }
}

function bthgResetCharForm() {
    document.getElementById('char-edit-id').value = '0';
    document.getElementById('char-form-title').innerHTML = '<span style="width:28px;height:28px;border-radius:8px;background:var(--brand-light);display:inline-flex;align-items:center;justify-content:center;font-size:14px;">👤</span> ➕ Thêm nhân vật mới';
    document.getElementById('char-name').value = '';
    document.getElementById('char-slug').value = '';
    document.getElementById('char-desc').value = '';
    document.getElementById('char-persona').value = '';
    document.getElementById('char-tone').value = '';
    document.getElementById('char-avatar-id').value = '';
    document.getElementById('char-image-url').value = '';
    document.getElementById('char-cta').value = '';
    document.getElementById('char-lang').value = 'vi';
    document.getElementById('char-status').value = 'active';
    // Hide training center when adding new
    document.getElementById('char-training-center').classList.add('hidden');
    // Reset voice info
    var vi = document.getElementById('char-voice-info');
    if (vi) vi.innerHTML = '';
    // Reset push status
    var ps = document.getElementById('char-push-status');
    if (ps) { ps.classList.add('hidden'); ps.innerHTML = ''; }
    var bp = document.getElementById('btn-push-heygen');
    if (bp) { bp.disabled = false; bp.textContent = '🚀 Bắt đầu Training ảnh'; }
    // Reset video
    var vs = document.getElementById('char-video-status');
    if (vs) { vs.classList.add('hidden'); vs.innerHTML = ''; }
    var bv = document.getElementById('btn-push-video');
    if (bv) { bv.disabled = false; bv.textContent = '🚀 Bắt đầu Training video'; }
    var vid = document.getElementById('char-video-url');
    if (vid) vid.value = '';
    var vaid = document.getElementById('char-video-avatar-id');
    if (vaid) vaid.value = '';
    // Reset voice
    var vcs = document.getElementById('char-voice-status');
    if (vcs) { vcs.classList.add('hidden'); vcs.innerHTML = ''; }
    var bcv = document.getElementById('btn-clone-voice');
    if (bcv) { bcv.disabled = false; bcv.textContent = '🚀 Bắt đầu Clone giọng'; }
    // Kill timers
    if (_pushTrainTimer) { clearTimeout(_pushTrainTimer); _pushTrainTimer = null; }
    if (_videoTrainTimer) { clearTimeout(_videoTrainTimer); _videoTrainTimer = null; }
}

/* ── Toggle training panels (accordion) ── */
function bthgTogglePanel(panel) {
    var el = document.getElementById('panel-' + panel);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
}

/* ── Update training pipeline steps ── */
function bthgUpdatePipeline(type, steps) {
    // steps = { upload:'done', push:'active', train:'', done:'' }
    Object.keys(steps).forEach(function(k) {
        var el = document.getElementById(type + '-step-' + k);
        if (!el) return;
        el.className = 'bthg-step' + (steps[k] === 'done' ? ' done' : (steps[k] === 'active' ? ' active' : ''));
    });
}

/* ── Update training badge ── */
function bthgUpdateTrainBadge(type, status) {
    var map = {
        'ready': { text: '✅ Đã train', bg: '#dcfce7', color: '#166534' },
        'training': { text: '⏳ Đang train', bg: '#fef3c7', color: '#92400e' },
        'failed': { text: '❌ Thất bại', bg: '#fee2e2', color: '#991b1b' },
        'none': { text: '⭕ Chưa train', bg: '#f1f5f9', color: '#64748b' },
    };
    var s = map[status] || map['none'];
    // Panel badge (e.g. photo-train-badge)
    var el = document.getElementById(type + '-train-badge');
    if (el) {
        el.textContent = s.text;
        el.style.background = s.bg;
        el.style.color = s.color;
    }
    // Overview badge (e.g. badge-photo-train)
    var ov = document.getElementById('badge-' + type + '-train');
    if (ov) {
        var labels = { photo:'📸 Ảnh', video:'🎬 Video', voice:'🎙️ Giọng' };
        ov.innerHTML = (labels[type] || type) + ': <span>' + s.text + '</span>';
        ov.style.background = s.bg;
        ov.style.color = s.color;
    }
}

function bthgViewCharacter(charId) {
    var fd = new FormData();
    fd.append('action', 'bthg_get_character');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) return;
            var c = res.data.character;
            var meta = {};
            try { meta = JSON.parse(c.metadata || '{}'); } catch(e) {}
            var panel = document.getElementById('char-detail');
            document.getElementById('char-detail-title').textContent = '👤 ' + c.name;

            var photoS = meta.heygen_push_status || '';
            var videoS = meta.heygen_video_push_status || '';
            var voiceS = c.voice_clone_status || 'none';

            function trainBadge(status, label) {
                var map = {
                    'ready':   { t:'✅', bg:'#dcfce7', c:'#166534' },
                    'cloned':  { t:'✅', bg:'#dcfce7', c:'#166534' },
                    'training':{ t:'⏳', bg:'#fef3c7', c:'#92400e' },
                    'cloning': { t:'⏳', bg:'#fef3c7', c:'#92400e' },
                    'failed':  { t:'❌', bg:'#fee2e2', c:'#991b1b' },
                };
                var s = map[status] || { t:'—', bg:'#f1f5f9', c:'#64748b' };
                return '<span style="display:inline-block;padding:2px 8px;border-radius:8px;font-size:11px;background:'+s.bg+';color:'+s.c+';">'+label+' '+s.t+'</span>';
            }

            var html = '';
            if (c.image_url) html += '<div style="text-align:center;margin-bottom:8px;"><img src="' + escAttr(c.image_url) + '" style="width:80px;height:80px;border-radius:12px;object-fit:cover;"></div>';

            // Training status badges
            html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">';
            html += trainBadge(photoS, '📸 Ảnh');
            html += trainBadge(videoS, '🎬 Video');
            html += trainBadge(voiceS, '🎙️ Giọng');
            html += '</div>';

            html += '<div><strong>Slug:</strong> ' + escHtml(c.slug) + '</div>';
            html += '<div><strong>Mô tả:</strong> ' + escHtml(c.description || '—') + '</div>';
            html += '<div><strong>Persona:</strong> ' + escHtml(c.persona_prompt || '—') + '</div>';
            html += '<div><strong>Tone:</strong> ' + escHtml(c.tone_of_voice || '—') + '</div>';
            html += '<div><strong>Ngôn ngữ:</strong> ' + escHtml(c.language) + '</div>';
            html += '<div><strong>Status:</strong> ' + escHtml(c.status || 'active') + '</div>';
            html += '<div><strong>CTA:</strong> ' + escHtml(c.default_cta || '—') + '</div>';
            html += '<hr style="border:0;border-top:1px solid var(--border);margin:8px 0;">';
            html += '<div><strong>📸 Avatar ID:</strong> ' + (c.avatar_id ? escHtml(c.avatar_id) : '<span style="color:var(--text-muted)">—</span>') + '</div>';
            if (meta.heygen_video_avatar_id) html += '<div><strong>🎬 Video Avatar ID:</strong> ' + escHtml(meta.heygen_video_avatar_id) + '</div>';
            html += '<div><strong>🎙️ Voice ID:</strong> ' + (c.voice_id ? '<span style="color:var(--success)">' + escHtml(c.voice_id) + '</span>' : '<span style="color:var(--text-muted)">Chưa clone</span>') + '</div>';
            if (c.voice_sample_url) html += '<div><strong>Voice Sample:</strong> <a href="' + escAttr(c.voice_sample_url) + '" target="_blank" style="color:var(--brand-color);">🔊 Nghe</a></div>';
            if (c.image_url) html += '<div><strong>Image:</strong> <a href="' + escAttr(c.image_url) + '" target="_blank" style="color:var(--brand-color);">🖼️ Xem</a></div>';

            document.getElementById('char-detail-body').innerHTML = html;
            panel.classList.remove('hidden');
            panel.scrollIntoView({ behavior: 'smooth' });
        });
}

function bthgEditCharacter(charId) {
    var fd = new FormData();
    fd.append('action', 'bthg_get_character');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) { alert(res.data.message || 'Lỗi tải nhân vật.'); return; }
            var c = res.data.character;
            var meta = {};
            try { meta = JSON.parse(c.metadata || '{}'); } catch(e) {}

            // ── Basic info ──
            document.getElementById('char-edit-id').value = c.id;
            document.getElementById('char-form-title').innerHTML = '<span style="width:28px;height:28px;border-radius:8px;background:var(--brand-light);display:inline-flex;align-items:center;justify-content:center;font-size:14px;">✏️</span> Sửa: ' + escHtml(c.name);
            document.getElementById('char-name').value = c.name || '';
            document.getElementById('char-slug').value = c.slug || '';
            document.getElementById('char-desc').value = c.description || '';
            document.getElementById('char-persona').value = c.persona_prompt || '';
            document.getElementById('char-tone').value = c.tone_of_voice || '';
            document.getElementById('char-avatar-id').value = c.avatar_id || '';
            document.getElementById('char-image-url').value = c.image_url || '';
            document.getElementById('char-cta').value = c.default_cta || '';
            document.getElementById('char-lang').value = c.language || 'vi';
            document.getElementById('char-status').value = c.status || 'active';

            // ── Show Training Center ──
            var tc = document.getElementById('char-training-center');
            tc.classList.remove('hidden');
            document.getElementById('training-char-name').textContent = c.name || '—';

            // ── Panel 1: Photo Avatar status ──
            var ps = meta.heygen_push_status || '';
            var gid = meta.heygen_group_id || '';
            var pushStatusEl = document.getElementById('char-push-status');
            var btnPush = document.getElementById('btn-push-heygen');
            var hasImage = !!(c.image_url);

            if (ps === 'ready') {
                bthgUpdateTrainBadge('photo', 'ready');
                bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'done', done:'done' });
                pushStatusEl.classList.remove('hidden');
                pushStatusEl.innerHTML = '✅ Training xong! Avatar ID: <code>' + escHtml(gid || c.avatar_id) + '</code>';
                pushStatusEl.style.background = 'rgba(0,200,0,0.08)';
                if (gid) document.getElementById('char-avatar-id').value = gid;
                document.getElementById('panel-photo').style.display = 'none';
            } else if (ps === 'training') {
                bthgUpdateTrainBadge('photo', 'training');
                bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'active', done:'' });
                pushStatusEl.classList.remove('hidden');
                pushStatusEl.innerHTML = '⏳ Đang training... <code>' + escHtml(gid) + '</code>';
                pushStatusEl.style.background = 'rgba(255,165,0,0.08)';
                document.getElementById('panel-photo').style.display = 'block';
                bthgPollTraining(c.id, gid);
            } else if (ps === 'train_failed') {
                bthgUpdateTrainBadge('photo', 'failed');
                bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'', done:'' });
                pushStatusEl.classList.remove('hidden');
                pushStatusEl.innerHTML = '❌ Thất bại: ' + escHtml(meta.heygen_push_error || 'Unknown');
                pushStatusEl.style.background = 'rgba(255,0,0,0.08)';
                document.getElementById('panel-photo').style.display = 'block';
            } else {
                bthgUpdateTrainBadge('photo', 'none');
                bthgUpdatePipeline('photo', {
                    upload: hasImage ? 'done' : '',
                    push: '', train: '', done: ''
                });
                pushStatusEl.classList.add('hidden');
                document.getElementById('panel-photo').style.display = 'none';
            }

            // ── Panel 2: Video Avatar status ──
            var videoStatus = meta.heygen_video_push_status || '';
            var videoGroupId = meta.heygen_video_group_id || '';
            var videoAvatarId = meta.heygen_video_avatar_id || '';
            var videoUrl = meta.heygen_video_training_url || '';
            var videoStatusEl = document.getElementById('char-video-status');
            if (videoUrl) document.getElementById('char-video-url').value = videoUrl;
            if (videoAvatarId) document.getElementById('char-video-avatar-id').value = videoAvatarId;

            if (videoStatus === 'ready') {
                bthgUpdateTrainBadge('video', 'ready');
                bthgUpdatePipeline('video', { upload:'done', push:'done', train:'done', done:'done' });
                videoStatusEl.classList.remove('hidden');
                videoStatusEl.innerHTML = '✅ Training xong! Video Avatar ID: <code>' + escHtml(videoAvatarId) + '</code>';
                videoStatusEl.style.background = 'rgba(0,200,0,0.08)';
                document.getElementById('panel-video').style.display = 'none';
            } else if (videoStatus === 'training') {
                bthgUpdateTrainBadge('video', 'training');
                bthgUpdatePipeline('video', { upload:'done', push:'done', train:'active', done:'' });
                videoStatusEl.classList.remove('hidden');
                videoStatusEl.innerHTML = '⏳ Đang training video avatar...';
                videoStatusEl.style.background = 'rgba(255,165,0,0.08)';
                document.getElementById('panel-video').style.display = 'block';
                bthgPollVideoTraining(c.id, videoGroupId);
            } else {
                bthgUpdateTrainBadge('video', 'none');
                bthgUpdatePipeline('video', { upload: videoUrl ? 'done' : '', push:'', train:'', done:'' });
                videoStatusEl.classList.add('hidden');
                document.getElementById('panel-video').style.display = 'none';
            }

            // ── Panel 3: Voice Clone status ──
            var vi = document.getElementById('char-voice-info');
            var voiceStatusEl = document.getElementById('char-voice-status');
            var vcs = c.voice_clone_status || 'none';

            if (vi) {
                if (c.voice_sample_url) {
                    vi.innerHTML = '🔊 File hiện tại: <a href="' + escAttr(c.voice_sample_url) + '" target="_blank" style="color:var(--brand-color);">Nghe</a>';
                    if (c.voice_id) vi.innerHTML += ' · Voice ID: <strong>' + escHtml(c.voice_id) + '</strong>';
                } else {
                    vi.innerHTML = '';
                }
            }

            if (vcs === 'cloned' && c.voice_id) {
                bthgUpdateTrainBadge('voice', 'ready');
                bthgUpdatePipeline('voice', { upload:'done', clone:'done', done:'done' });
                voiceStatusEl.classList.remove('hidden');
                voiceStatusEl.innerHTML = '✅ Clone xong! Voice ID: <code>' + escHtml(c.voice_id) + '</code>';
                voiceStatusEl.style.background = 'rgba(0,200,0,0.08)';
                document.getElementById('panel-voice').style.display = 'none';
            } else if (vcs === 'cloning') {
                bthgUpdateTrainBadge('voice', 'training');
                bthgUpdatePipeline('voice', { upload:'done', clone:'active', done:'' });
                voiceStatusEl.classList.remove('hidden');
                voiceStatusEl.innerHTML = '⏳ Đang clone giọng...';
                voiceStatusEl.style.background = 'rgba(255,165,0,0.08)';
                document.getElementById('panel-voice').style.display = 'block';
            } else if (vcs === 'failed') {
                bthgUpdateTrainBadge('voice', 'failed');
                bthgUpdatePipeline('voice', { upload: c.voice_sample_url ? 'done' : '', clone:'', done:'' });
                voiceStatusEl.classList.remove('hidden');
                voiceStatusEl.innerHTML = '❌ Clone thất bại: ' + escHtml(meta.clone_error || 'Unknown');
                voiceStatusEl.style.background = 'rgba(255,0,0,0.08)';
                document.getElementById('panel-voice').style.display = 'block';
            } else {
                bthgUpdateTrainBadge('voice', 'none');
                bthgUpdatePipeline('voice', { upload: c.voice_sample_url ? 'done' : '', clone:'', done:'' });
                voiceStatusEl.classList.add('hidden');
                document.getElementById('panel-voice').style.display = 'none';
            }

            // ── Show form ──
            var form = document.getElementById('char-form');
            form.classList.remove('hidden');
            form.scrollIntoView({ behavior: 'smooth' });
        });
}

function bthgSaveCharacter() {
    var fd = new FormData();
    fd.append('action', 'bthg_save_character');
    fd.append('nonce', BTHG.nonce);

    var editId = document.getElementById('char-edit-id').value;
    if (editId && editId !== '0') fd.append('character_id', editId);

    fd.append('name', document.getElementById('char-name').value);
    fd.append('slug', document.getElementById('char-slug').value);
    fd.append('description', document.getElementById('char-desc').value);
    fd.append('persona_prompt', document.getElementById('char-persona').value);
    fd.append('tone_of_voice', document.getElementById('char-tone').value);
    fd.append('language', document.getElementById('char-lang').value);
    fd.append('status', document.getElementById('char-status').value);
    fd.append('avatar_id', document.getElementById('char-avatar-id').value);
    fd.append('image_url', document.getElementById('char-image-url').value);
    fd.append('default_cta', document.getElementById('char-cta').value);

    // Handle voice file upload first if present
    var voiceFile = document.getElementById('char-voice-file').files[0];
    var avatarFile = document.getElementById('char-avatar-file').files[0];

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                var charId = res.data.character_id;
                var chain = Promise.resolve();

                // Upload voice if file selected
                if (voiceFile) {
                    chain = chain.then(function() {
                        var vfd = new FormData();
                        vfd.append('action', 'bthg_upload_voice');
                        vfd.append('nonce', BTHG.nonce);
                        vfd.append('character_id', charId);
                        vfd.append('voice_file', voiceFile);
                        return fetch(BTHG.ajax, { method: 'POST', body: vfd }).then(function(r) { return r.json(); });
                    });
                }

                // Upload avatar image if file selected
                if (avatarFile) {
                    chain = chain.then(function() {
                        var afd = new FormData();
                        afd.append('action', 'bthg_upload_avatar');
                        afd.append('nonce', BTHG.nonce);
                        afd.append('character_id', charId);
                        afd.append('avatar_file', avatarFile);
                        return fetch(BTHG.ajax, { method: 'POST', body: afd }).then(function(r) { return r.json(); });
                    });
                }

                chain.then(function() {
                    alert(res.data.message || 'Đã lưu nhân vật!');
                    location.reload();
                });
            } else {
                alert(res.data.message || 'Lỗi lưu nhân vật.');
            }
        });
}

function bthgDeleteCharacter(charId) {
    if (!confirm('Xóa nhân vật này? Không thể hoàn tác.')) return;

    var fd = new FormData();
    fd.append('action', 'bthg_delete_character');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) location.reload();
            else alert(res.data.message || 'Lỗi xóa nhân vật.');
        });
}

function bthgCloneVoice(charId) {
    if (!charId) charId = document.getElementById('char-edit-id').value;
    if (!charId || charId === '0') {
        alert('Vui lòng lưu nhân vật trước khi clone voice.');
        return;
    }

    var btn = document.getElementById('btn-clone-voice');
    var statusEl = document.getElementById('char-voice-status');

    btn.disabled = true;
    btn.textContent = '⏳ Đang clone...';
    statusEl.classList.remove('hidden');
    statusEl.innerHTML = '⏳ Đang gửi yêu cầu clone voice...';
    statusEl.style.background = 'rgba(255,165,0,0.08)';
    bthgUpdatePipeline('voice', { upload:'done', clone:'active', done:'' });
    bthgUpdateTrainBadge('voice', 'training');

    logConsole('Cloning voice for character #' + charId + '...', 'warn');

    var fd = new FormData();
    fd.append('action', 'bthg_clone_voice');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                var voiceId = res.data.voice_id || '';
                btn.disabled = false;
                btn.textContent = '✅ Hoàn tất!';
                statusEl.innerHTML = '✅ Clone xong! Voice ID: <code>' + escHtml(voiceId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('voice', { upload:'done', clone:'done', done:'done' });
                bthgUpdateTrainBadge('voice', 'ready');
                logConsole('Voice cloned! ID: ' + voiceId, 'ok');
                setTimeout(function() { btn.textContent = '🎙️ Clone giọng'; }, 3000);
            } else {
                btn.disabled = false;
                btn.textContent = '🎙️ Clone giọng';
                statusEl.innerHTML = '❌ Clone thất bại: ' + escHtml(res.data.message || 'Unknown');
                statusEl.style.background = 'rgba(255,0,0,0.08)';
                bthgUpdateTrainBadge('voice', 'failed');
                logConsole('Clone failed: ' + (res.data.message || 'Unknown'), 'err');
            }
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = '🎙️ Clone giọng';
            statusEl.innerHTML = '❌ Lỗi: ' + escHtml(e.message);
            statusEl.style.background = 'rgba(255,0,0,0.08)';
            bthgUpdateTrainBadge('voice', 'failed');
        });
}

/* ══════════════════════════════════════════════
 *  Start Voice Clone — Upload file first if selected, then clone
 * ══════════════════════════════════════════════ */
function bthgStartVoiceClone() {
    var charId = document.getElementById('char-edit-id').value;
    if (!charId || charId === '0') {
        alert('Vui lòng lưu nhân vật trước.');
        return;
    }

    var fileEl = document.getElementById('char-voice-file');
    var hasFile = fileEl && fileEl.files && fileEl.files.length > 0;

    if (hasFile) {
        // Upload voice file first via bthg_upload_voice, then clone
        var btn = document.getElementById('btn-clone-voice');
        var statusEl = document.getElementById('char-voice-status');
        btn.disabled = true;
        btn.textContent = '⏳ Upload file...';
        statusEl.classList.remove('hidden');
        statusEl.innerHTML = '⏳ Đang upload file giọng nói...';
        statusEl.style.background = 'var(--bg-muted)';
        bthgUpdatePipeline('voice', { upload:'active', clone:'', done:'' });

        var fd = new FormData();
        fd.append('action', 'bthg_upload_voice');
        fd.append('nonce', BTHG.nonce);
        fd.append('character_id', charId);
        fd.append('voice_file', fileEl.files[0]);

        fetch(BTHG.ajax, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) {
                    btn.disabled = false;
                    btn.textContent = '🎙️ Clone giọng';
                    statusEl.innerHTML = '❌ Upload thất bại: ' + escHtml(res.data.message || 'Unknown');
                    statusEl.style.background = 'rgba(255,0,0,0.08)';
                    bthgUpdateTrainBadge('voice', 'failed');
                    return;
                }
                bthgUpdatePipeline('voice', { upload:'done', clone:'', done:'' });
                // Now clone
                bthgCloneVoice(charId);
            })
            .catch(function(e) {
                btn.disabled = false;
                btn.textContent = '🎙️ Clone giọng';
                statusEl.innerHTML = '❌ Lỗi: ' + escHtml(e.message);
                statusEl.style.background = 'rgba(255,0,0,0.08)';
            });
    } else {
        // No file selected — clone from existing voice_sample_url
        bthgCloneVoice(charId);
    }
}

/* ══════════════════════════════════════════════
 *  Fetch HeyGen Talking Photos
 * ══════════════════════════════════════════════ */
function bthgFetchTalkingPhotos() {
    var listEl = document.getElementById('char-tp-list');
    listEl.classList.remove('hidden');
    listEl.innerHTML = '<div style="padding:8px; color:var(--text-muted);">⏳ Đang lấy từ HeyGen...</div>';

    var fd = new FormData();
    fd.append('action', 'bthg_list_talking_photos');
    fd.append('nonce', BTHG.nonce);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                listEl.innerHTML = '<div style="padding:8px; color:red;">❌ ' + escHtml(res.data.message || 'Lỗi') + '</div>';
                return;
            }
            var photos = res.data.photos || [];
            if (!photos.length) {
                listEl.innerHTML = '<div style="padding:8px; color:var(--text-muted);">Chưa có Avatar nào trên HeyGen.<br>Vào <a href="https://app.heygen.com/avatars" target="_blank" style="color:var(--brand-color);">HeyGen Avatar</a> để upload ảnh trước.</div>';
                return;
            }
            var html = '';
            photos.forEach(function(p) {
                var tpId = p.talking_photo_id || p.avatar_id || '';
                var name = p.avatar_name || p.name || tpId;
                var thumb = p.preview_image_url || '';
                var pType = p.type || '';
                var pStatus = (p.status || '').toLowerCase();
                var typeLabel = '';
                var borderLeft = '';

                if (pType === 'photo_avatar') {
                    typeLabel = '📸 Uploaded';
                    borderLeft = 'border-left:3px solid var(--brand-color);';
                    if (pStatus === 'pending' || pStatus === 'training') {
                        typeLabel += ' ⏳';
                    }
                } else if (pType === 'talking_photo') {
                    typeLabel = '🖼️ Photo';
                } else if (pType === 'avatar') {
                    typeLabel = '👤 Avatar';
                } else {
                    typeLabel = pType;
                }

                html += '<div style="display:flex; gap:8px; align-items:center; padding:6px; cursor:pointer; border-bottom:1px solid var(--border-color);' + borderLeft + '" onclick="bthgSelectTalkingPhoto(\'' + escAttr(tpId) + '\')" title="ID: ' + escAttr(tpId) + '">';
                if (thumb) html += '<img src="' + escAttr(thumb) + '" style="width:40px; height:40px; border-radius:6px; object-fit:cover;">';
                html += '<div style="flex:1;"><strong>' + escHtml(name) + '</strong>';
                if (typeLabel) html += ' <span style="font-size:10px; background:var(--bg-muted); padding:1px 5px; border-radius:4px;">' + typeLabel + '</span>';
                html += '<br><span style="font-size:10px; color:var(--text-muted);">' + escHtml(tpId) + '</span></div>';
                html += '</div>';
            });
            listEl.innerHTML = html;
        })
        .catch(function(e) {
            listEl.innerHTML = '<div style="padding:8px; color:red;">❌ ' + escHtml(e.message) + '</div>';
        });
}

function bthgSelectTalkingPhoto(tpId) {
    document.getElementById('char-avatar-id').value = tpId;
    document.getElementById('char-tp-list').classList.add('hidden');
    logConsole('Selected Talking Photo: ' + tpId, 'ok');
}

/* ══════════════════════════════════════════════
 *  Push Photo to HeyGen — Upload → Group → Train → Poll
 * ══════════════════════════════════════════════ */
var _pushTrainTimer = null;

function bthgPushPhotoToHeyGen() {
    var charId = document.getElementById('char-edit-id').value;
    if (!charId || charId === '0') {
        alert('Vui lòng lưu nhân vật trước khi đẩy ảnh lên HeyGen.');
        return;
    }

    var imageUrl = document.getElementById('char-image-url').value;
    if (!imageUrl) {
        alert('Nhân vật chưa có ảnh đại diện. Upload ảnh trước.');
        return;
    }

    var btn = document.getElementById('btn-push-heygen');
    var statusEl = document.getElementById('char-push-status');

    btn.disabled = true;
    btn.textContent = '⏳ Đang upload...';
    statusEl.classList.remove('hidden');
    statusEl.innerHTML = '⏳ <strong>Bước 1/3:</strong> Upload ảnh lên HeyGen...';
    statusEl.style.background = 'var(--bg-muted)';
    bthgUpdatePipeline('photo', { upload:'active', push:'', train:'', done:'' });
    bthgUpdateTrainBadge('photo', 'training');

    logConsole('Push photo to HeyGen: character_id=' + charId, 'info');

    var fd = new FormData();
    fd.append('action', 'bthg_push_photo_heygen');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = '🚀 Đẩy ảnh lên HeyGen';
                var stepInfo = res.data.step ? ' [step: ' + res.data.step + ']' : '';
                statusEl.innerHTML = '❌ ' + escHtml(res.data.message || 'Lỗi đẩy ảnh') + '<br><small style="color:var(--text-muted);">' + escHtml(stepInfo) + '</small>';
                statusEl.style.background = 'rgba(255,0,0,0.08)';
                bthgUpdateTrainBadge('photo', 'failed');
                logConsole('Push photo failed' + stepInfo + ': ' + (res.data.message || 'Unknown'), 'error');
                return;
            }

            var groupId = res.data.group_id || '';
            var avatarId = res.data.avatar_id || '';
            var pushStatus = res.data.status || 'training';

            logConsole('Push OK → group_id=' + groupId + ' avatar_id=' + avatarId + ' status=' + pushStatus, 'ok');

            // Handle already_ready (duplicate detection)
            if (pushStatus === 'already_ready') {
                btn.disabled = false;
                btn.textContent = '✅ Đã có sẵn!';
                statusEl.innerHTML = '✅ Ảnh đã được push trước đó! Avatar ID: <code>' + escHtml(avatarId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'done', done:'done' });
                bthgUpdateTrainBadge('photo', 'ready');
                if (avatarId) {
                    document.getElementById('char-avatar-id').value = avatarId;
                    bthgSaveCharacter();
                }
                setTimeout(function() { btn.textContent = '🚀 Đẩy ảnh lên HeyGen'; }, 3000);
                return;
            }

            bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'active', done:'' });
            statusEl.innerHTML = '⏳ <strong>Bước 2/3:</strong> Đang training avatar... (có thể mất 1-5 phút)';
            statusEl.style.background = 'rgba(255,165,0,0.08)';
            btn.textContent = '⏳ Đang training...';

            if (avatarId) {
                document.getElementById('char-avatar-id').value = avatarId;
            }

            bthgPollTraining(charId, groupId);
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = '🚀 Đẩy ảnh lên HeyGen';
            statusEl.innerHTML = '❌ Lỗi: ' + escHtml(e.message);
            statusEl.style.background = 'rgba(255,0,0,0.08)';
            bthgUpdateTrainBadge('photo', 'failed');
        });
}

function bthgPollTraining(charId, groupId) {
    if (_pushTrainTimer) clearTimeout(_pushTrainTimer);

    var fd = new FormData();
    fd.append('action', 'bthg_poll_training');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);
    fd.append('group_id', groupId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var btn = document.getElementById('btn-push-heygen');
            var statusEl = document.getElementById('char-push-status');

            if (!res.success) {
                logConsole('Poll training error: ' + (res.data.message || 'Unknown'), 'error');
                _pushTrainTimer = setTimeout(function() { bthgPollTraining(charId, groupId); }, 15000);
                return;
            }

            var status = (res.data.status || '').toLowerCase();
            var avatarId = res.data.avatar_id || '';

            logConsole('Training status: ' + status + (avatarId ? ' avatar_id=' + avatarId : ''), 'info');

            if (status === 'ready') {
                btn.disabled = false;
                btn.textContent = '✅ Hoàn tất!';
                statusEl.innerHTML = '✅ Training xong! Avatar ID: <code>' + escHtml(avatarId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('photo', { upload:'done', push:'done', train:'done', done:'done' });
                bthgUpdateTrainBadge('photo', 'ready');

                if (avatarId) {
                    document.getElementById('char-avatar-id').value = avatarId;
                    bthgSaveCharacter();
                }

                logConsole('Photo Avatar training complete! avatar_id=' + avatarId, 'ok');
                setTimeout(function() { btn.textContent = '🚀 Đẩy ảnh lên HeyGen'; }, 3000);
            } else {
                _pushTrainTimer = setTimeout(function() { bthgPollTraining(charId, groupId); }, 10000);
            }
        })
        .catch(function(e) {
            logConsole('Poll training fetch error: ' + e.message, 'error');
            _pushTrainTimer = setTimeout(function() { bthgPollTraining(charId, groupId); }, 15000);
        });
}

/* ══════════════════════════════════════════════
 *  Save Video Avatar ID manually (from HeyGen dashboard)
 * ══════════════════════════════════════════════ */
function bthgSaveVideoAvatarId() {
    var charId = document.getElementById('char-edit-id').value;
    if (!charId || charId === '0') {
        alert('Vui lòng lưu nhân vật trước.');
        return;
    }
    var avatarId = document.getElementById('char-video-avatar-id').value.trim();
    if (!avatarId) {
        alert('Vui lòng nhập Video Avatar ID.');
        return;
    }

    var statusEl = document.getElementById('char-video-status');
    statusEl.classList.remove('hidden');
    statusEl.innerHTML = '⏳ Đang lưu Video Avatar ID...';
    statusEl.style.background = 'var(--bg-muted)';

    // Save character with the video avatar ID in metadata
    var fd = new FormData();
    fd.append('action', 'bthg_save_video_avatar_id');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);
    fd.append('video_avatar_id', avatarId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                statusEl.innerHTML = '✅ Đã lưu Video Avatar ID: <code>' + escHtml(avatarId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('video', { upload:'done', push:'done', train:'done', done:'done' });
                bthgUpdateTrainBadge('video', 'ready');
            } else {
                statusEl.innerHTML = '❌ Lỗi: ' + escHtml(res.data.message || 'Unknown');
                statusEl.style.background = 'rgba(255,0,0,0.08)';
            }
        })
        .catch(function(e) {
            statusEl.innerHTML = '❌ Lỗi: ' + escHtml(e.message);
            statusEl.style.background = 'rgba(255,0,0,0.08)';
        });
}

/* ══════════════════════════════════════════════
 *  Push Video to HeyGen — Upload → Create Video Avatar → Train → Poll
 * ══════════════════════════════════════════════ */
var _videoTrainTimer = null;

function bthgPushVideoToHeyGen() {
    var charId = document.getElementById('char-edit-id').value;
    if (!charId || charId === '0') {
        alert('Vui lòng lưu nhân vật trước.');
        return;
    }

    var videoUrl = document.getElementById('char-video-url').value.trim();
    var videoFileEl = document.getElementById('char-video-file');
    var hasFile = videoFileEl && videoFileEl.files && videoFileEl.files.length > 0;

    if (!videoUrl && !hasFile) {
        alert('Vui lòng nhập URL video hoặc chọn file video.');
        return;
    }

    var btn = document.getElementById('btn-push-video');
    var statusEl = document.getElementById('char-video-status');

    btn.disabled = true;
    btn.textContent = '⏳ Đang upload...';
    statusEl.classList.remove('hidden');
    statusEl.innerHTML = '⏳ <strong>Bước 1/3:</strong> Upload video lên HeyGen...';
    statusEl.style.background = 'var(--bg-muted)';
    bthgUpdatePipeline('video', { upload:'active', push:'', train:'', done:'' });
    bthgUpdateTrainBadge('video', 'training');

    logConsole('Push video to HeyGen: character_id=' + charId, 'info');

    var fd = new FormData();
    fd.append('action', 'bthg_push_video_heygen');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);
    if (hasFile) {
        fd.append('video_file', videoFileEl.files[0]);
    } else {
        fd.append('video_url', videoUrl);
    }

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = '🚀 Đẩy video lên HeyGen';
                statusEl.innerHTML = '❌ ' + escHtml(res.data.message || 'Lỗi đẩy video');
                statusEl.style.background = 'rgba(255,0,0,0.08)';
                bthgUpdateTrainBadge('video', 'failed');
                return;
            }

            var avatarId = res.data.avatar_id || '';
            var status = res.data.status || 'training';

            logConsole('Video push OK → avatar_id=' + avatarId + ' status=' + status, 'ok');

            if (status === 'already_ready') {
                btn.disabled = false;
                btn.textContent = '✅ Đã có sẵn!';
                statusEl.innerHTML = '✅ Video Avatar đã train xong! ID: <code>' + escHtml(avatarId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('video', { upload:'done', push:'done', train:'done', done:'done' });
                bthgUpdateTrainBadge('video', 'ready');
                if (avatarId) document.getElementById('char-video-avatar-id').value = avatarId;
                setTimeout(function() { btn.textContent = '🚀 Đẩy video lên HeyGen'; }, 3000);
                return;
            }

            bthgUpdatePipeline('video', { upload:'done', push:'done', train:'active', done:'' });
            statusEl.innerHTML = '⏳ <strong>Bước 2/3:</strong> Đang training video avatar... (có thể mất 5-20 phút)';
            statusEl.style.background = 'rgba(255,165,0,0.08)';
            btn.textContent = '⏳ Đang training...';

            if (avatarId) document.getElementById('char-video-avatar-id').value = avatarId;

            bthgPollVideoTraining(charId, avatarId);
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = '🚀 Đẩy video lên HeyGen';
            statusEl.innerHTML = '❌ Lỗi: ' + escHtml(e.message);
            statusEl.style.background = 'rgba(255,0,0,0.08)';
            bthgUpdateTrainBadge('video', 'failed');
        });
}

function bthgPollVideoTraining(charId, avatarId) {
    if (_videoTrainTimer) clearTimeout(_videoTrainTimer);

    var fd = new FormData();
    fd.append('action', 'bthg_poll_video_training');
    fd.append('nonce', BTHG.nonce);
    fd.append('character_id', charId);
    fd.append('avatar_id', avatarId);

    fetch(BTHG.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var btn = document.getElementById('btn-push-video');
            var statusEl = document.getElementById('char-video-status');

            if (!res.success) {
                logConsole('Poll video training error: ' + (res.data.message || 'Unknown'), 'error');
                _videoTrainTimer = setTimeout(function() { bthgPollVideoTraining(charId, avatarId); }, 15000);
                return;
            }

            var status = (res.data.status || '').toLowerCase();
            logConsole('Video training status: ' + status, 'info');

            if (status === 'ready' || status === 'completed') {
                btn.disabled = false;
                btn.textContent = '✅ Hoàn tất!';
                statusEl.innerHTML = '✅ Video Avatar training xong! ID: <code>' + escHtml(avatarId) + '</code>';
                statusEl.style.background = 'rgba(0,200,0,0.08)';
                bthgUpdatePipeline('video', { upload:'done', push:'done', train:'done', done:'done' });
                bthgUpdateTrainBadge('video', 'ready');
                bthgSaveCharacter();
                logConsole('Video Avatar training complete! avatar_id=' + avatarId, 'ok');
                setTimeout(function() { btn.textContent = '🚀 Đẩy video lên HeyGen'; }, 3000);
            } else if (status === 'failed') {
                btn.disabled = false;
                btn.textContent = '🚀 Đẩy video lên HeyGen';
                statusEl.innerHTML = '❌ Training thất bại: ' + escHtml(res.data.error || 'Unknown');
                statusEl.style.background = 'rgba(255,0,0,0.08)';
                bthgUpdateTrainBadge('video', 'failed');
            } else {
                _videoTrainTimer = setTimeout(function() { bthgPollVideoTraining(charId, avatarId); }, 15000);
            }
        })
        .catch(function(e) {
            logConsole('Poll video training fetch error: ' + e.message, 'error');
            _videoTrainTimer = setTimeout(function() { bthgPollVideoTraining(charId, avatarId); }, 15000);
        });
}

/* ══════════════════════════════════════════════
 *  Utilities
 * ══════════════════════════════════════════════ */
function bthgShowResult(id, msg, type) {
    var el = document.getElementById(id);
    if (!el) return;
    var cls = type === 'success' ? 'bthg-alert-success' : (type === 'error' ? 'bthg-alert-error' : 'bthg-alert-info');
    el.className = 'bthg-alert ' + cls;
    el.textContent = msg;
    el.classList.remove('hidden');
    setTimeout(function() { el.classList.add('hidden'); }, 5000);
}

function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ── Init: start poll if there are active jobs ── */
(function() {
    var active = parseInt(document.getElementById('stat-active').textContent) || 0;
    if (active > 0) {
        BTHG.pollTimer = setInterval(bthgPollJobs, 10000);
        logConsole('Active jobs detected (' + active + '), auto-poll started', 'ok');
    }

    // Initialize prevJobStates from PHP-rendered jobs
    document.querySelectorAll('.bthg-job[data-job-id]').forEach(function(el) {
        var badge = el.querySelector('.bthg-badge');
        if (badge) {
            var cls = badge.className;
            var status = 'draft';
            if (cls.indexOf('completed') > -1) status = 'completed';
            else if (cls.indexOf('processing') > -1) status = 'processing';
            else if (cls.indexOf('queued') > -1) status = 'queued';
            else if (cls.indexOf('failed') > -1) status = 'failed';
            BTHG.prevJobStates[el.getAttribute('data-job-id')] = status;
        }
    });
})();
</script>
</body>
</html>
