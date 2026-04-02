<?php
/**
 * BizCity Scheduler — Public Agent Page
 *
 * Route: /scheduler/
 * 4-tab bottom navigation: Lịch hôm nay | Tạo mới | Google | Cài đặt
 *
 * Displayed inside chat iframe or standalone. Uses postMessage + REST API.
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-02
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$user_id      = get_current_user_id();

/* ── Google status ── */
$google        = BizCity_Scheduler_Google::instance();
$google_status = $google->get_connection_status();
$google_conn   = ! empty( $google_status['connected'] );
$is_admin      = current_user_can( 'manage_options' );

/* ── REST config ── */
$rest_url   = esc_url_raw( rest_url( 'bizcity-scheduler/v1' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

/* ── Quick-add prompts ── */
$prompts = [
    [ 'cat' => 'Tạo lịch',   'icon' => '📅', 'prompts' => [
        'Họp team lúc 3h chiều mai',
        'Gọi điện khách hàng 10h sáng thứ 2',
        'Deadline báo cáo thứ 6 tuần này',
        'Nhắc uống thuốc 8h sáng mỗi ngày',
    ]],
    [ 'cat' => 'Xem lịch',   'icon' => '📋', 'prompts' => [
        'Xem lịch hôm nay',
        'Lịch tuần này có gì?',
        'Tìm thời gian trống chiều mai',
        'Tóm tắt agenda hôm nay',
    ]],
    [ 'cat' => 'Quản lý',    'icon' => '⚡', 'prompts' => [
        'Đánh dấu xong cuộc họp sáng',
        'Hủy lịch họp ngày mai',
        'Dời lịch họp sang 4h chiều',
        'Sync Google Calendar',
    ]],
];

/* ── Workflows (feature cards) ── */
$workflows = [
    [ 'icon' => '📋', 'label' => 'Xem lịch hôm nay',   'desc' => 'Xem tất cả sự kiện và nhắc nhở hôm nay', 'msg' => 'Xem lịch hôm nay',              'tool' => 'scheduler_get_today_agenda', 'tags' => ['Lịch','Hôm nay'] ],
    [ 'icon' => '📅', 'label' => 'Tạo sự kiện',         'desc' => 'Thêm sự kiện mới vào lịch',               'msg' => 'Tạo sự kiện họp team lúc 10h sáng mai', 'tool' => 'scheduler_create_event',     'tags' => ['Lịch','Tạo'] ],
    [ 'icon' => '🔍', 'label' => 'Tìm thời gian trống', 'desc' => 'Tìm slot trống để hẹn lịch',              'msg' => 'Tìm thời gian trống chiều mai', 'tool' => 'scheduler_find_free_slots',  'tags' => ['Lịch','Trống'] ],
    [ 'icon' => '✅', 'label' => 'Đánh dấu hoàn thành', 'desc' => 'Đánh dấu sự kiện đã xong',                'msg' => 'Đánh dấu xong cuộc họp sáng',  'tool' => 'scheduler_mark_done',        'tags' => ['Lịch','Done'] ],
    [ 'icon' => '🔄', 'label' => 'Dời lịch',            'desc' => 'Thay đổi thời gian sự kiện',              'msg' => 'Dời lịch họp sang 4h chiều',   'tool' => 'scheduler_update_event',     'tags' => ['Lịch','Sửa'] ],
    [ 'icon' => '🔗', 'label' => 'Sync Google',          'desc' => 'Đồng bộ 2 chiều với Google Calendar',     'msg' => 'Sync Google Calendar',          'tool' => 'scheduler_sync_google',      'tags' => ['Google','Sync'] ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scheduler – Lịch & Nhắc việc</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f8fafc;color:#1f2937;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
    padding-bottom:64px;
}

/* ══ Bottom Tab Bar ══ */
.sch-bottom-nav{
    position:fixed;bottom:0;left:0;right:0;
    height:56px;
    background:#fff;
    border-top:1px solid #e5e7eb;
    display:flex;
    z-index:100;
    box-shadow:0 -2px 10px rgba(0,0,0,.06);
}
.sch-nav-item{
    flex:1;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:2px;
    font-size:10px;font-weight:500;color:#9ca3af;
    cursor:pointer;transition:color .15s;
    -webkit-tap-highlight-color:transparent;
    text-decoration:none;
    border:none;background:none;
    position:relative;
}
.sch-nav-item.active{color:#4d6bfe;font-weight:600}
.sch-nav-item.active::before{
    content:'';position:absolute;top:0;left:20%;right:20%;
    height:2px;background:#4d6bfe;border-radius:0 0 2px 2px;
}
.sch-nav-icon{font-size:20px;line-height:1}

/* ══ Tab Content ══ */
.sch-tab{display:none;padding:16px 16px 24px;max-width:100%;margin:0 auto}
.sch-tab.active{display:block}

/* ══ Hero Card ══ */
.sch-hero{
    background:linear-gradient(135deg,#4d6bfe 0%,#7c5cfc 50%,#a78bfa 100%);
    border-radius:16px;padding:18px 16px 14px;
    text-align:center;color:#fff;
    box-shadow:0 6px 24px rgba(77,107,254,.2);
    margin-bottom:16px;position:relative;overflow:hidden;
}
.sch-hero::before{content:'';position:absolute;top:-40%;right:-30%;width:160px;height:160px;background:rgba(255,255,255,.06);border-radius:50%}
.sch-hero-row{display:flex;align-items:center;gap:12px;text-align:left}
.sch-hero-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:28px;background:rgba(255,255,255,.15);flex-shrink:0}
.sch-hero-info{flex:1;min-width:0}
.sch-hero-name{font-size:16px;font-weight:700}
.sch-hero-desc{font-size:11px;opacity:.8;line-height:1.4;margin-top:2px}

/* ══ Connection ══ */
.sch-conn{
    display:inline-flex;align-items:center;gap:5px;
    font-size:11px;margin-top:6px;
    padding:3px 10px;border-radius:12px;
    background:rgba(255,255,255,.15);
}
.sch-conn-dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.sch-conn.ok .sch-conn-dot{background:#34d399}
.sch-conn.no .sch-conn-dot{background:#fbbf24}

/* ══ Section ══ */
.sch-sec{margin:18px 0 8px;display:flex;align-items:center;gap:6px}
.sch-sec-t{font-size:14px;font-weight:700;color:#1f2937}
.sch-sec-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* ══ Command Cards ══ */
.sch-cmds{display:flex;flex-direction:column;gap:8px}
.sch-cmd{
    display:flex;align-items:flex-start;gap:12px;
    background:#fff;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid #e5e7eb;
    cursor:pointer;transition:all .15s;
    text-decoration:none;color:inherit;-webkit-tap-highlight-color:transparent;
}
.sch-cmd:hover{border-color:#a5b4fc;box-shadow:0 3px 12px rgba(77,107,254,.1);transform:translateY(-1px)}
.sch-cmd:active{transform:scale(.98)}
.sch-cmd-icon{
    width:40px;height:40px;border-radius:10px;
    background:linear-gradient(135deg,#eef2ff,#e0e7ff);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.sch-cmd-body{flex:1;min-width:0}
.sch-cmd-label{font-size:13px;font-weight:600;color:#1f2937;margin-bottom:2px}
.sch-cmd-desc{font-size:11px;color:#6b7280;line-height:1.3}
.sch-cmd-tags{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap}
.sch-cmd-tag{font-size:9px;font-weight:600;padding:1px 7px;border-radius:5px;background:#eef2ff;color:#6366f1}
.sch-cmd-arrow{width:22px;height:22px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:11px;color:#9ca3af;align-self:center;flex-shrink:0}

/* ══ Today's Events ══ */
.sch-events{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.sch-event{
    display:flex;align-items:center;gap:10px;
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #e5e7eb;transition:all .12s;
}
.sch-event:hover{border-color:#a5b4fc}
.sch-event-time{
    font-size:12px;font-weight:700;color:#4d6bfe;
    min-width:48px;text-align:center;
}
.sch-event-body{flex:1;min-width:0}
.sch-event-title{font-size:13px;font-weight:600;color:#1f2937}
.sch-event-title--done{text-decoration:line-through;color:#9ca3af}
.sch-event-meta{font-size:10px;color:#9ca3af;margin-top:1px}
.sch-event-badges{display:flex;gap:3px}
.sch-event-badge{font-size:12px}
.sch-event-actions{display:flex;gap:4px}
.sch-event-btn{
    width:28px;height:28px;border-radius:6px;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;border:none;background:#f3f4f6;
    cursor:pointer;transition:all .12s;
}
.sch-event-btn:hover{background:#e5e7eb}
.sch-event-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px}
.sch-event-loading{text-align:center;padding:20px;color:#9ca3af;font-size:13px}

/* ══ Quick Add Form ══ */
.sch-quick-form{
    display:flex;gap:8px;margin-bottom:16px;
}
.sch-quick-input{
    flex:1;padding:10px 14px;
    border:1px solid #d1d5db;border-radius:10px;
    font-size:14px;outline:none;transition:border-color .15s;
}
.sch-quick-input:focus{border-color:#4d6bfe;box-shadow:0 0 0 2px rgba(77,107,254,.15)}
.sch-quick-btn{
    padding:10px 18px;
    background:#4d6bfe;color:#fff;border:none;
    border-radius:10px;font-weight:600;font-size:13px;
    cursor:pointer;transition:all .15s;white-space:nowrap;
}
.sch-quick-btn:hover{background:#3b55d9}
.sch-quick-btn:disabled{opacity:.5;cursor:not-allowed}

/* ══ Prompt Tab ══ */
.sch-prompt-cat{margin-bottom:16px}
.sch-prompt-cat-hd{font-size:13px;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.sch-prompt-list{display:flex;flex-direction:column;gap:5px}
.sch-prompt-item{
    display:flex;align-items:center;gap:8px;
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #f3f4f6;cursor:pointer;
    transition:all .12s;font-size:13px;color:#374151;
}
.sch-prompt-item:hover{border-color:#a5b4fc;background:#eef2ff}
.sch-prompt-item:active{transform:scale(.98)}
.sch-prompt-send{
    width:28px;height:28px;border-radius:8px;
    background:#eef2ff;color:#6366f1;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;
}

/* ══ Google Tab ══ */
.sch-google-card{
    background:#fff;border-radius:12px;padding:14px 16px;
    border:1px solid #e5e7eb;margin-bottom:10px;
}
.sch-google-card h3{font-size:14px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.sch-google-status{
    display:flex;align-items:center;gap:8px;
    padding:10px 14px;border-radius:10px;
    font-size:13px;font-weight:500;margin-bottom:12px;
}
.sch-google-status.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.sch-google-status.no{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.sch-google-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.sch-ggl-btn{
    padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;
    border:none;cursor:pointer;transition:all .12s;
}
.sch-ggl-btn--primary{background:#4d6bfe;color:#fff}
.sch-ggl-btn--primary:hover{background:#3b55d9}
.sch-ggl-btn--secondary{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
.sch-ggl-btn--secondary:hover{background:#e5e7eb}
.sch-ggl-btn:disabled{opacity:.5;cursor:not-allowed}
.sch-ggl-msg{padding:8px 12px;border-radius:8px;font-size:12px;margin-top:8px}
.sch-ggl-msg--ok{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.sch-ggl-msg--err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}

/* ══ Create Form ══ */
.sch-form{
    background:#fff;border-radius:12px;padding:16px;
    border:1px solid #e5e7eb;
}
.sch-form-field{margin-bottom:12px}
.sch-form-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px}
.sch-form-input{
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;outline:none;transition:border-color .15s;
}
.sch-form-input:focus{border-color:#4d6bfe;box-shadow:0 0 0 2px rgba(77,107,254,.15)}
textarea.sch-form-input{min-height:60px;resize:vertical}
.sch-form-row{display:flex;gap:8px}
.sch-form-row .sch-form-field{flex:1}
.sch-form-check{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
.sch-form-check input{width:16px;height:16px}
.sch-form-submit{
    width:100%;padding:11px;margin-top:4px;
    background:#4d6bfe;color:#fff;border:none;
    border-radius:10px;font-weight:600;font-size:14px;
    cursor:pointer;transition:all .15s;
}
.sch-form-submit:hover{background:#3b55d9}
.sch-form-submit:disabled{opacity:.5;cursor:not-allowed}
.sch-form-result{padding:10px 12px;border-radius:8px;font-size:12px;margin-top:10px;display:none}
.sch-form-result.ok{display:block;background:#dcfce7;color:#166534}
.sch-form-result.err{display:block;background:#fef2f2;color:#b91c1c}

/* ══ Login ══ */
.sch-login{text-align:center;padding:60px 20px;color:#6b7280;font-size:14px;line-height:1.6}
.sch-login a{color:#4d6bfe;text-decoration:underline}
</style>
</head>
<body>

<!-- ════ Hero ════ -->
<div style="padding:16px 16px 0">
    <div class="sch-hero">
        <div class="sch-hero-row">
            <div class="sch-hero-icon">📅</div>
            <div class="sch-hero-info">
                <div class="sch-hero-name">Scheduler — Lịch & Nhắc việc</div>
                <div class="sch-hero-desc">Quản lý lịch hẹn, nhắc nhở, đồng bộ Google Calendar qua chat AI</div>
                <?php if ( $is_logged_in ) : ?>
                    <div class="sch-conn <?php echo $google_conn ? 'ok' : 'no'; ?>">
                        <span class="sch-conn-dot"></span>
                        Google: <?php echo $google_conn ? 'Đã kết nối' : 'Chưa kết nối'; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ( ! $is_logged_in ) : ?>
    <div class="sch-login">
        Vui lòng <a href="<?php echo esc_url( wp_login_url( home_url( '/scheduler/' ) ) ); ?>">đăng nhập</a> để sử dụng Scheduler.
    </div>
<?php else : ?>

<!-- ═══════════════════════════════════════════════════════════
     TAB 1: Lịch hôm nay
     ═══════════════════════════════════════════════════════════ -->
<div class="sch-tab active" id="tab-today">
    <div class="sch-sec">
        <span class="sch-sec-t">📋 Lịch hôm nay</span>
    </div>
    <div class="sch-sec-sub" id="sch-today-date"></div>

    <!-- Quick Add -->
    <div class="sch-quick-form" style="margin-top:10px;">
        <input class="sch-quick-input" id="sch-quick-input" type="text" placeholder='Thêm nhanh: "Họp team lúc 3h chiều"'>
        <button class="sch-quick-btn" id="sch-quick-btn">⚡ Thêm</button>
    </div>

    <!-- Events list -->
    <div id="sch-today-events">
        <div class="sch-event-loading">⏳ Đang tải...</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 2: Tính năng & Prompt
     ═══════════════════════════════════════════════════════════ -->
<div class="sch-tab" id="tab-features">
    <div class="sch-sec">
        <span class="sch-sec-t">⚡ Tính năng</span>
    </div>
    <div class="sch-sec-sub">Bấm để gửi lệnh vào chat</div>
    <div class="sch-cmds" style="margin-top:10px">
        <?php foreach ( $workflows as $w ) : ?>
        <a class="sch-cmd" href="#" data-msg="<?php echo esc_attr( $w['msg'] ); ?>" data-tool="<?php echo esc_attr( $w['tool'] ); ?>">
            <div class="sch-cmd-icon"><?php echo $w['icon']; ?></div>
            <div class="sch-cmd-body">
                <div class="sch-cmd-label"><?php echo esc_html( $w['label'] ); ?></div>
                <div class="sch-cmd-desc"><?php echo esc_html( $w['desc'] ); ?></div>
                <div class="sch-cmd-tags">
                    <?php foreach ( $w['tags'] as $tag ) : ?>
                        <span class="sch-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sch-cmd-arrow">›</div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Prompt presets -->
    <div class="sch-sec" style="margin-top:24px">
        <span class="sch-sec-t">💬 Prompt gợi ý</span>
    </div>
    <?php foreach ( $prompts as $cat ) : ?>
    <div class="sch-prompt-cat">
        <div class="sch-prompt-cat-hd"><?php echo $cat['icon']; ?> <?php echo esc_html( $cat['cat'] ); ?></div>
        <div class="sch-prompt-list">
            <?php foreach ( $cat['prompts'] as $p ) : ?>
            <div class="sch-prompt-item" data-msg="<?php echo esc_attr( $p ); ?>">
                <span style="flex:1"><?php echo esc_html( $p ); ?></span>
                <span class="sch-prompt-send">▶</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 3: Tạo sự kiện
     ═══════════════════════════════════════════════════════════ -->
<div class="sch-tab" id="tab-create">
    <div class="sch-sec">
        <span class="sch-sec-t">📅 Tạo sự kiện mới</span>
    </div>
    <div class="sch-form" style="margin-top:10px">
        <div class="sch-form-field">
            <label class="sch-form-label">Tiêu đề *</label>
            <input class="sch-form-input" id="sch-evt-title" type="text" placeholder="VD: Họp team marketing">
        </div>
        <div class="sch-form-row">
            <div class="sch-form-field">
                <label class="sch-form-label">Ngày</label>
                <input class="sch-form-input" id="sch-evt-date" type="date">
            </div>
            <div class="sch-form-field">
                <label class="sch-form-check">
                    <input type="checkbox" id="sch-evt-allday">
                    Cả ngày
                </label>
            </div>
        </div>
        <div class="sch-form-row" id="sch-time-row">
            <div class="sch-form-field">
                <label class="sch-form-label">Bắt đầu</label>
                <input class="sch-form-input" id="sch-evt-start" type="time" value="09:00">
            </div>
            <div class="sch-form-field">
                <label class="sch-form-label">Kết thúc</label>
                <input class="sch-form-input" id="sch-evt-end" type="time" value="10:00">
            </div>
        </div>
        <div class="sch-form-field">
            <label class="sch-form-label">Nhắc trước</label>
            <select class="sch-form-input" id="sch-evt-reminder">
                <option value="0">Không nhắc</option>
                <option value="5">5 phút</option>
                <option value="15" selected>15 phút</option>
                <option value="30">30 phút</option>
                <option value="60">1 giờ</option>
                <option value="1440">1 ngày</option>
            </select>
        </div>
        <div class="sch-form-field">
            <label class="sch-form-label">Ghi chú</label>
            <textarea class="sch-form-input" id="sch-evt-desc" rows="2" placeholder="Mô tả chi tiết (tùy chọn)"></textarea>
        </div>
        <button class="sch-form-submit" id="sch-evt-submit">📅 Tạo sự kiện</button>
        <div class="sch-form-result" id="sch-evt-result"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 4: Google & Cài đặt
     ═══════════════════════════════════════════════════════════ -->
<div class="sch-tab" id="tab-settings">
    <div class="sch-sec">
        <span class="sch-sec-t">🔗 Google Calendar</span>
    </div>

    <div class="sch-google-card">
        <div class="sch-google-status <?php echo $google_conn ? 'ok' : 'no'; ?>">
            <?php echo $google_conn ? '✅ Đã kết nối Google Calendar' : '⚠️ Chưa kết nối Google Calendar'; ?>
        </div>

        <?php if ( $google_conn ) : ?>
            <p style="font-size:12px;color:#6b7280;margin-bottom:8px;">
                Lịch đang đồng bộ 2 chiều. Sự kiện tạo trên BizCity sẽ tự động lên Google và ngược lại.
            </p>
            <div class="sch-google-actions">
                <button class="sch-ggl-btn sch-ggl-btn--primary" id="sch-ggl-sync">🔄 Sync ngay</button>
                <?php if ( $is_admin ) : ?>
                    <button class="sch-ggl-btn sch-ggl-btn--secondary" id="sch-ggl-disconnect">Ngắt kết nối</button>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <p style="font-size:12px;color:#6b7280;margin-bottom:8px;">
                Kết nối Google Calendar để đồng bộ sự kiện 2 chiều.
                <?php if ( ! $is_admin ) : ?>
                    Liên hệ Admin để cấu hình kết nối.
                <?php endif; ?>
            </p>
            <?php if ( $is_admin ) : ?>
                <div class="sch-google-actions">
                    <a class="sch-ggl-btn sch-ggl-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-scheduler' ) ); ?>">
                        ⚙️ Cấu hình trong Admin
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="sch-ggl-msg" id="sch-ggl-msg" style="display:none"></div>
    </div>

    <!-- Stats -->
    <div class="sch-google-card">
        <h3>📊 Thống kê</h3>
        <div id="sch-stats">
            <p style="font-size:12px;color:#9ca3af;">Đang tải...</p>
        </div>
    </div>
</div>

<?php endif; /* is_logged_in */ ?>

<!-- ════ Bottom Navigation ════ -->
<?php if ( $is_logged_in ) : ?>
<nav class="sch-bottom-nav">
    <button class="sch-nav-item active" data-tab="tab-today">
        <span class="sch-nav-icon">📋</span>
        <span>Hôm nay</span>
    </button>
    <button class="sch-nav-item" data-tab="tab-features">
        <span class="sch-nav-icon">⚡</span>
        <span>Tính năng</span>
    </button>
    <button class="sch-nav-item" data-tab="tab-create">
        <span class="sch-nav-icon">📅</span>
        <span>Tạo mới</span>
    </button>
    <button class="sch-nav-item" data-tab="tab-settings">
        <span class="sch-nav-icon">⚙️</span>
        <span>Cài đặt</span>
    </button>
</nav>
<?php endif; ?>

<script>
(function(){
    'use strict';
    var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
    var NONCE    = <?php echo wp_json_encode( $rest_nonce ); ?>;
    var isIframe = window.parent && window.parent !== window;

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
    }

    /* ── Send message to parent chat ── */
    function sendMsg(msg, toolName) {
        if (!msg) return;
        var slashMsg = toolName ? ('/' + toolName + ' ' + msg) : msg;
        if (isIframe) {
            window.parent.postMessage({
                type: 'bizcity-chat-command',
                action: 'send_message',
                tool: toolName || '',
                text: slashMsg
            }, '*');
        } else {
            window.location.href = <?php echo wp_json_encode( home_url( '/' ) ); ?> + '?bizcity_chat_msg=' + encodeURIComponent(slashMsg);
        }
    }

    function flash(el) {
        el.style.transform = 'scale(0.96)';
        el.style.opacity = '0.7';
        setTimeout(function(){ el.style.transform = ''; el.style.opacity = ''; }, 200);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatTime(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
    }

    /* ── Tab switching ── */
    var navItems = document.querySelectorAll('.sch-nav-item');
    var tabs = document.querySelectorAll('.sch-tab');

    navItems.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            navItems.forEach(function(b){ b.classList.remove('active'); });
            tabs.forEach(function(t){ t.classList.remove('active'); });
            this.classList.add('active');
            var tabEl = document.getElementById(target);
            if (tabEl) tabEl.classList.add('active');

            if (target === 'tab-today' && !todayLoaded) loadToday();
        });
    });

    /* ── Command / Prompt click handlers ── */
    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            flash(this);
            sendMsg(this.getAttribute('data-msg'), this.getAttribute('data-tool') || '');
        });
    });

    /* ── Today date label ── */
    var todayLabel = document.getElementById('sch-today-date');
    if (todayLabel) {
        var now = new Date();
        var days = ['Chủ nhật','Thứ 2','Thứ 3','Thứ 4','Thứ 5','Thứ 6','Thứ 7'];
        todayLabel.textContent = days[now.getDay()] + ', ' + now.getDate() + '/' + (now.getMonth()+1) + '/' + now.getFullYear();
    }

    /* ── Default date for create form ── */
    var dateInput = document.getElementById('sch-evt-date');
    if (dateInput) {
        var today = new Date();
        dateInput.value = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');
    }

    /* ── All-day toggle ── */
    var allDayCheck = document.getElementById('sch-evt-allday');
    var timeRow = document.getElementById('sch-time-row');
    if (allDayCheck && timeRow) {
        allDayCheck.addEventListener('change', function() {
            timeRow.style.display = this.checked ? 'none' : '';
        });
    }

    /* ── Load today's events ── */
    var todayLoaded = false;

    function loadToday() {
        var container = document.getElementById('sch-today-events');
        if (!container) return;
        container.innerHTML = '<div class="sch-event-loading">⏳ Đang tải...</div>';

        fetch(REST_URL + '/today', { headers: headers() })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            todayLoaded = true;
            var events = data.events || [];
            if (events.length === 0) {
                container.innerHTML = '<div class="sch-event-empty">🎉 Hôm nay không có sự kiện nào.<br>Dùng Quick Add để thêm nhanh!</div>';
                return;
            }
            var html = '<div class="sch-events">';
            events.forEach(function(ev) {
                var isDone = ev.status === 'done';
                var isAI = (ev.source || '').indexOf('ai_') === 0;
                var isGoogle = !!ev.google_event_id;
                var time = ev.all_day == 1 ? 'Cả ngày' : formatTime(ev.start_at);

                html += '<div class="sch-event">';
                html += '<div class="sch-event-time">' + escHtml(time) + '</div>';
                html += '<div class="sch-event-body">';
                html += '<div class="sch-event-title' + (isDone ? ' sch-event-title--done' : '') + '">' + escHtml(ev.title) + '</div>';
                html += '<div class="sch-event-meta">';
                html += '<span class="sch-event-badges">';
                if (isAI) html += '<span class="sch-event-badge" title="AI">🤖</span>';
                if (isGoogle) html += '<span class="sch-event-badge" title="Google">📅</span>';
                if (ev.reminder_min > 0 && !ev.reminder_sent) html += '<span class="sch-event-badge" title="Nhắc">🔔</span>';
                html += '</span>';
                html += '</div></div>';
                html += '<div class="sch-event-actions">';
                html += '<button class="sch-event-btn" data-action="toggle" data-id="' + ev.id + '" data-status="' + (ev.status || 'active') + '" title="' + (isDone ? 'Chưa xong' : 'Hoàn thành') + '">' + (isDone ? '✅' : '⬜') + '</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;

            // Toggle done
            container.querySelectorAll('[data-action="toggle"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    var st = this.getAttribute('data-status');
                    var newSt = st === 'done' ? 'active' : 'done';
                    fetch(REST_URL + '/events/' + id, {
                        method: 'PATCH',
                        headers: headers(),
                        body: JSON.stringify({ status: newSt })
                    }).then(function() { loadToday(); });
                });
            });

            // Load stats
            loadStats(events);
        })
        .catch(function() {
            container.innerHTML = '<div class="sch-event-empty" style="color:#dc2626">Không thể tải sự kiện.</div>';
        });
    }

    function loadStats(events) {
        var container = document.getElementById('sch-stats');
        if (!container) return;
        var total = events ? events.length : 0;
        var done = events ? events.filter(function(e){ return e.status === 'done'; }).length : 0;
        var ai = events ? events.filter(function(e){ return (e.source || '').indexOf('ai_') === 0; }).length : 0;
        container.innerHTML =
            '<div style="display:flex;gap:12px;flex-wrap:wrap;">' +
            '<div style="flex:1;min-width:80px;background:#eef2ff;border-radius:8px;padding:10px;text-align:center;">' +
                '<div style="font-size:20px;font-weight:700;color:#4d6bfe;">' + total + '</div>' +
                '<div style="font-size:10px;color:#6b7280;">Hôm nay</div>' +
            '</div>' +
            '<div style="flex:1;min-width:80px;background:#dcfce7;border-radius:8px;padding:10px;text-align:center;">' +
                '<div style="font-size:20px;font-weight:700;color:#16a34a;">' + done + '</div>' +
                '<div style="font-size:10px;color:#6b7280;">Hoàn thành</div>' +
            '</div>' +
            '<div style="flex:1;min-width:80px;background:#fef3c7;border-radius:8px;padding:10px;text-align:center;">' +
                '<div style="font-size:20px;font-weight:700;color:#d97706;">' + ai + '</div>' +
                '<div style="font-size:10px;color:#6b7280;">AI tạo</div>' +
            '</div>' +
            '</div>';
    }

    // Load on init
    loadToday();

    /* ── Quick Add ── */
    var quickInput = document.getElementById('sch-quick-input');
    var quickBtn = document.getElementById('sch-quick-btn');
    if (quickBtn && quickInput) {
        function doQuickAdd() {
            var text = quickInput.value.trim();
            if (!text) return;
            quickBtn.disabled = true;
            quickBtn.textContent = '⏳...';
            fetch(REST_URL + '/events/quick', {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({ text: text })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                quickBtn.disabled = false;
                quickBtn.textContent = '⚡ Thêm';
                if (data.error) { alert('Lỗi: ' + data.error); return; }
                quickInput.value = '';
                loadToday();
            })
            .catch(function() {
                quickBtn.disabled = false;
                quickBtn.textContent = '⚡ Thêm';
                alert('Lỗi kết nối server.');
            });
        }
        quickBtn.addEventListener('click', doQuickAdd);
        quickInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doQuickAdd();
        });
    }

    /* ── Create Event Form ── */
    var submitBtn = document.getElementById('sch-evt-submit');
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var title = document.getElementById('sch-evt-title').value.trim();
            if (!title) { alert('Nhập tiêu đề!'); return; }

            var date = document.getElementById('sch-evt-date').value;
            var allDay = document.getElementById('sch-evt-allday').checked;
            var startTime = document.getElementById('sch-evt-start').value || '09:00';
            var endTime = document.getElementById('sch-evt-end').value || '10:00';
            var reminder = parseInt(document.getElementById('sch-evt-reminder').value) || 0;
            var desc = document.getElementById('sch-evt-desc').value.trim();

            var startAt = allDay ? (date + ' 00:00:00') : (date + ' ' + startTime + ':00');
            var endAt = allDay ? (date + ' 23:59:59') : (date + ' ' + endTime + ':00');

            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Đang tạo...';

            var resultEl = document.getElementById('sch-evt-result');

            fetch(REST_URL + '/events', {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify({
                    title: title,
                    description: desc || undefined,
                    start_at: startAt,
                    end_at: endAt,
                    all_day: allDay ? 1 : 0,
                    reminder_min: reminder,
                    source: 'user'
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                submitBtn.disabled = false;
                submitBtn.textContent = '📅 Tạo sự kiện';
                if (data.error) {
                    resultEl.className = 'sch-form-result err';
                    resultEl.textContent = '❌ ' + data.error;
                    return;
                }
                resultEl.className = 'sch-form-result ok';
                resultEl.textContent = '✅ Đã tạo sự kiện "' + (data.event ? data.event.title : title) + '"';
                // Reset form
                document.getElementById('sch-evt-title').value = '';
                document.getElementById('sch-evt-desc').value = '';
                todayLoaded = false; // reload next time
            })
            .catch(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = '📅 Tạo sự kiện';
                resultEl.className = 'sch-form-result err';
                resultEl.textContent = '❌ Lỗi kết nối server.';
            });
        });
    }

    /* ── Google Sync ── */
    var syncBtn = document.getElementById('sch-ggl-sync');
    if (syncBtn) {
        syncBtn.addEventListener('click', function() {
            syncBtn.disabled = true;
            syncBtn.textContent = '⏳ Đang sync...';
            var msgEl = document.getElementById('sch-ggl-msg');

            fetch(REST_URL + '/google/sync', {
                method: 'POST',
                headers: headers()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                syncBtn.disabled = false;
                syncBtn.textContent = '🔄 Sync ngay';
                if (data.error) {
                    msgEl.className = 'sch-ggl-msg sch-ggl-msg--err';
                    msgEl.textContent = '❌ ' + data.error;
                    msgEl.style.display = '';
                    return;
                }
                msgEl.className = 'sch-ggl-msg sch-ggl-msg--ok';
                msgEl.textContent = '✅ Đã đồng bộ ' + (data.synced || 0) + ' sự kiện từ Google.';
                msgEl.style.display = '';
                todayLoaded = false;
                loadToday();
            })
            .catch(function() {
                syncBtn.disabled = false;
                syncBtn.textContent = '🔄 Sync ngay';
                msgEl.className = 'sch-ggl-msg sch-ggl-msg--err';
                msgEl.textContent = '❌ Lỗi kết nối server.';
                msgEl.style.display = '';
            });
        });
    }

    /* ── Google Disconnect ── */
    var disconnectBtn = document.getElementById('sch-ggl-disconnect');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function() {
            if (!confirm('Ngắt kết nối Google Calendar?')) return;
            disconnectBtn.disabled = true;
            var msgEl = document.getElementById('sch-ggl-msg');

            fetch(REST_URL + '/google/disconnect', {
                method: 'POST',
                headers: headers()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    disconnectBtn.disabled = false;
                    msgEl.className = 'sch-ggl-msg sch-ggl-msg--err';
                    msgEl.textContent = '❌ ' + data.error;
                    msgEl.style.display = '';
                    return;
                }
                msgEl.className = 'sch-ggl-msg sch-ggl-msg--ok';
                msgEl.textContent = '✅ Đã ngắt kết nối.';
                msgEl.style.display = '';
                setTimeout(function(){ location.reload(); }, 1500);
            })
            .catch(function() {
                disconnectBtn.disabled = false;
                msgEl.className = 'sch-ggl-msg sch-ggl-msg--err';
                msgEl.textContent = '❌ Lỗi kết nối server.';
                msgEl.style.display = '';
            });
        });
    }
})();
</script>
</body>
</html>
