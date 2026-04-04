<?php
/**
 * BizGPT Tool Google — Agent Profile with 4-tab bottom navigation.
 *
 * Tabs:
 *   1. Tính năng — Guided command cards (Gmail, Calendar, Drive, Contacts)
 *   2. Prompt    — Custom prompt templates and presets
 *   3. Lịch sử  — Command execution history (from usage logs via REST)
 *   4. Cài đặt  — Google connection, scopes, account management
 *
 * Displayed inside chat iframe or standalone. Uses postMessage to send
 * commands to parent chat window.
 *
 * @package BizGPT_Tool_Google
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$user_id      = get_current_user_id();
$blog_id      = get_current_blog_id();
$icon_url     = BZGOOGLE_URL . 'assets/google.png';

/* ── Connection status ── */
$has_token    = $is_logged_in && BZGoogle_Token_Store::has_valid_token( $blog_id, $user_id );
$connect_url  = $is_logged_in ? BZGoogle_Google_OAuth::get_connect_url( [ 'return_url' => home_url( '/tool-google/' ) ] ) : '';
$hub_domain   = BZGoogle_Google_OAuth::get_hub_domain();
$is_hub       = BZGoogle_Google_OAuth::is_hub();

// Debug: log connection check (remove after fixing)
error_log( sprintf(
    '[BZGoogle page-google] is_logged_in=%s, user_id=%d, blog_id=%d, has_token=%s, is_hub=%s, table=%s',
    $is_logged_in ? 'YES' : 'NO', $user_id, $blog_id,
    $has_token ? 'YES' : 'NO', $is_hub ? 'YES' : 'NO',
    BZGoogle_Installer::table_accounts()
) );

/* ── Scope status per service ── */
$services = [
    'gmail'    => [ 'label' => 'Gmail',    'icon' => '📧' ],
    'calendar' => [ 'label' => 'Calendar', 'icon' => '📅' ],
    'drive'    => [ 'label' => 'Drive',    'icon' => '📁' ],
    'contacts' => [ 'label' => 'Contacts', 'icon' => '👥' ],
];
$scope_status = [];
if ( $has_token ) {
    foreach ( $services as $svc => $info ) {
        $scope_status[ $svc ] = BZGoogle_Google_OAuth::has_scope( $blog_id, $user_id, $svc );
    }
}

/* ── Accounts (for settings tab) ── */
$accounts = $is_logged_in ? BZGoogle_Token_Store::get_accounts( $blog_id, $user_id ) : [];

/* ── Workflows — 7 goals ── */
$workflows = [
    [ 'icon' => '📨', 'label' => 'Đọc email',      'desc' => 'Xem email mới nhất trong Gmail',            'msg' => 'Đọc email mới nhất',                          'tool' => 'gmail_list_messages',   'tags' => ['Gmail','Inbox'] ],
    [ 'icon' => '✉️', 'label' => 'Gửi email',       'desc' => 'Soạn và gửi email qua Gmail',               'msg' => 'Gửi email cho support@example.com tiêu đề Xin chào', 'tool' => 'gmail_send_message',    'tags' => ['Gmail','Send'] ],
    [ 'icon' => '📋', 'label' => 'Tóm tắt inbox',   'desc' => 'AI đọc và tóm tắt hộp thư Gmail',          'msg' => 'Tóm tắt email của tôi',                       'tool' => 'gmail_summarize_inbox', 'tags' => ['Gmail','AI'] ],
    [ 'icon' => '📅', 'label' => 'Xem lịch',        'desc' => 'Xem sự kiện sắp tới trong Calendar',       'msg' => 'Xem lịch hôm nay',                            'tool' => 'calendar_list_events',  'tags' => ['Calendar'] ],
    [ 'icon' => '🗓️', 'label' => 'Tạo sự kiện',     'desc' => 'Tạo sự kiện mới trong Calendar',           'msg' => 'Tạo sự kiện họp team lúc 10h sáng mai',       'tool' => 'calendar_create_event', 'tags' => ['Calendar'] ],
    [ 'icon' => '📁', 'label' => 'Xem file Drive',   'desc' => 'Xem danh sách file trong Google Drive',    'msg' => 'Xem file trong Drive',                         'tool' => 'drive_list_files',      'tags' => ['Drive'] ],
    [ 'icon' => '👥', 'label' => 'Xem danh bạ',      'desc' => 'Xem liên hệ trong Google Contacts',        'msg' => 'Xem danh bạ Google',                           'tool' => 'contacts_list',         'tags' => ['Contacts'] ],
];

/* ── Prompt presets ── */
$prompt_presets = [
    [ 'cat' => 'Gmail',    'icon' => '📧', 'prompts' => [
        'Đọc 5 email mới nhất',
        'Tìm email từ @google.com',
        'Tóm tắt email chưa đọc hôm nay',
        'Gửi email cho {email} nội dung {nội dung}',
    ]],
    [ 'cat' => 'Calendar', 'icon' => '📅', 'prompts' => [
        'Xem lịch hôm nay',
        'Lịch tuần này có gì?',
        'Tạo sự kiện họp lúc 3h chiều mai',
        'Tạo nhắc nhở đi khám bác sĩ ngày 20',
    ]],
    [ 'cat' => 'Drive',    'icon' => '📁', 'prompts' => [
        'Xem file trong Drive',
        'Tìm file báo cáo tháng 3',
        'Liệt kê file chia sẻ với tôi',
    ]],
    [ 'cat' => 'Contacts', 'icon' => '👥', 'prompts' => [
        'Xem danh bạ Google',
        'Tìm liên hệ tên Nguyễn',
        'Xem 10 liên hệ gần đây',
    ]],
];

/* ── REST config for JS ── */
$rest_url  = rest_url( 'bizgpt-google/v1' );
$rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tool Google – Agent</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f8fafc;color:#1f2937;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
    padding-bottom:64px; /* space for bottom nav */
}

/* ══ Bottom Tab Bar ══ */
.tg-bottom-nav{
    position:fixed;bottom:0;left:0;right:0;
    height:56px;
    background:#fff;
    border-top:1px solid #e5e7eb;
    display:flex;
    z-index:100;
    box-shadow:0 -2px 10px rgba(0,0,0,.06);
}
.tg-nav-item{
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
.tg-nav-item.active{color:#1a73e8;font-weight:600}
.tg-nav-item.active::before{
    content:'';position:absolute;top:0;left:20%;right:20%;
    height:2px;background:#1a73e8;border-radius:0 0 2px 2px;
}
.tg-nav-icon{font-size:20px;line-height:1}

/* ══ Tab Content ══ */
.tg-tab{display:none;padding:16px 16px 24px;max-width:100%;margin:0 auto}
.tg-tab.active{display:block}

/* ══ Hero Card (compact) ══ */
.tg-hero{
    background:linear-gradient(135deg,#1a73e8 0%,#4285f4 50%,#669df6 100%);
    border-radius:16px;padding:18px 16px 14px;
    text-align:center;color:#fff;
    box-shadow:0 6px 24px rgba(26,115,232,.2);
    margin-bottom:16px;position:relative;overflow:hidden;
}
.tg-hero::before{content:'';position:absolute;top:-40%;right:-30%;width:160px;height:160px;background:rgba(255,255,255,.06);border-radius:50%}
.tg-hero-row{display:flex;align-items:center;gap:12px;text-align:left}
.tg-hero-icon{width:48px;height:48px;border-radius:12px;border:2px solid rgba(255,255,255,.35);box-shadow:0 2px 8px rgba(0,0,0,.1);object-fit:cover;background:#fff;flex-shrink:0}
.tg-hero-info{flex:1;min-width:0}
.tg-hero-name{font-size:16px;font-weight:700}
.tg-hero-desc{font-size:11px;opacity:.8;line-height:1.4;margin-top:2px}

/* ══ Connection Status (inline) ══ */
.tg-conn-inline{
    display:inline-flex;align-items:center;gap:5px;
    font-size:11px;margin-top:6px;
    padding:3px 10px;border-radius:12px;
    background:rgba(255,255,255,.15);
}
.tg-conn-dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.tg-conn-inline.ok .tg-conn-dot{background:#34d399}
.tg-conn-inline.no .tg-conn-dot{background:#fbbf24}

/* ══ Section titles ══ */
.tg-sec{margin:18px 0 8px;display:flex;align-items:center;gap:6px}
.tg-sec-t{font-size:14px;font-weight:700;color:#1f2937}
.tg-sec-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* ══ Command Cards ══ */
.tg-cmds{display:flex;flex-direction:column;gap:8px}
.tg-cmd{
    display:flex;align-items:flex-start;gap:12px;
    background:#fff;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid #e5e7eb;
    cursor:pointer;transition:all .15s;
    text-decoration:none;color:inherit;-webkit-tap-highlight-color:transparent;
}
.tg-cmd:hover{border-color:#93c5fd;box-shadow:0 3px 12px rgba(66,133,244,.1);transform:translateY(-1px)}
.tg-cmd:active{transform:scale(.98)}
.tg-cmd-icon{
    width:40px;height:40px;border-radius:10px;
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.tg-cmd-body{flex:1;min-width:0}
.tg-cmd-label{font-size:13px;font-weight:600;color:#1f2937;margin-bottom:2px}
.tg-cmd-desc{font-size:11px;color:#6b7280;line-height:1.3}
.tg-cmd-tags{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap}
.tg-cmd-tag{font-size:9px;font-weight:600;padding:1px 7px;border-radius:5px;background:#eff6ff;color:#3b82f6}
.tg-cmd-arrow{width:22px;height:22px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:11px;color:#9ca3af;align-self:center;flex-shrink:0}

/* ══ Prompt Tab ══ */
.tg-prompt-cat{margin-bottom:16px}
.tg-prompt-cat-hd{font-size:13px;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.tg-prompt-list{display:flex;flex-direction:column;gap:5px}
.tg-prompt-item{
    display:flex;align-items:center;gap:8px;
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #f3f4f6;cursor:pointer;
    transition:all .12s;font-size:13px;color:#374151;
}
.tg-prompt-item:hover{border-color:#93c5fd;background:#eff6ff}
.tg-prompt-item:active{transform:scale(.98)}
.tg-prompt-send{
    width:28px;height:28px;border-radius:8px;
    background:#eff6ff;color:#3b82f6;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;
}

/* ══ History Tab ══ */
.tg-history-list{display:flex;flex-direction:column;gap:6px}
.tg-history-item{
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #f3f4f6;
    display:flex;align-items:flex-start;gap:10px;
}
.tg-history-svc{
    font-size:18px;width:32px;height:32px;
    border-radius:8px;background:#f3f4f6;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.tg-history-body{flex:1;min-width:0}
.tg-history-action{font-size:12px;font-weight:600;color:#1f2937}
.tg-history-summary{font-size:11px;color:#6b7280;margin-top:1px;word-break:break-word}
.tg-history-meta{display:flex;gap:8px;margin-top:4px;font-size:10px;color:#9ca3af}
.tg-history-badge{
    font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;
}
.tg-history-badge.ok{background:#dcfce7;color:#16a34a}
.tg-history-badge.err{background:#fef2f2;color:#dc2626}
.tg-history-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px}
.tg-history-load{text-align:center;padding:12px}
.tg-more-btn{
    display:inline-block;margin:12px auto;padding:8px 20px;
    font-size:12px;font-weight:600;color:#3b82f6;
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
    cursor:pointer;transition:all .12s;
}
.tg-more-btn:hover{background:#dbeafe}

/* ══ Settings Tab ══ */
.tg-setting-card{
    background:#fff;border-radius:12px;padding:14px 16px;
    border:1px solid #e5e7eb;margin-bottom:10px;
}
.tg-setting-card h3{font-size:14px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.tg-account-row{
    display:flex;align-items:center;gap:10px;
    padding:8px 0;border-bottom:1px solid #f3f4f6;
}
.tg-account-row:last-child{border-bottom:none}
.tg-account-email{font-size:13px;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tg-account-status{font-size:10px;font-weight:600;padding:2px 8px;border-radius:4px}
.tg-account-status.active{background:#dcfce7;color:#16a34a}
.tg-account-status.inactive{background:#fef2f2;color:#dc2626}
.tg-scope-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.tg-scope-chip{
    display:inline-flex;align-items:center;gap:4px;
    font-size:11px;padding:4px 10px;border-radius:8px;
}
.tg-scope-chip.on{background:#dcfce7;color:#16a34a}
.tg-scope-chip.off{background:#fef9c3;color:#a16207;cursor:pointer}
.tg-scope-chip.off:hover{background:#fef08a}

.tg-connect-btn{
    display:inline-flex;align-items:center;gap:6px;
    margin-top:10px;
    background:linear-gradient(135deg,#1a73e8,#4285f4);color:#fff;
    font-size:13px;font-weight:600;
    padding:10px 20px;border-radius:10px;
    text-decoration:none;
    box-shadow:0 2px 8px rgba(26,115,232,.25);
    transition:all .15s;
}
.tg-connect-btn:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(26,115,232,.3)}

.tg-hub-info{
    font-size:12px;color:#6b7280;
    background:#f8fafc;border-radius:8px;
    padding:10px 12px;margin-top:8px;
    border:1px solid #e5e7eb;
}

/* ══ Login prompt ══ */
.tg-login{text-align:center;padding:60px 20px;color:#6b7280;font-size:14px;line-height:1.6}
.tg-login a{color:#4285f4;text-decoration:underline}
</style>
</head>
<body>

<!-- ════ Hero (always visible) ════ -->
<div style="padding:16px 16px 0">
    <div class="tg-hero">
        <div class="tg-hero-row">
            <img class="tg-hero-icon" src="<?php echo esc_url( $icon_url ); ?>" alt="Google">
            <div class="tg-hero-info">
                <div class="tg-hero-name">Tool — Google</div>
                <div class="tg-hero-desc">Gmail · Calendar · Drive · Contacts — tất cả qua chat AI</div>
                <?php if ( $is_logged_in ) : ?>
                    <div class="tg-conn-inline <?php echo $has_token ? 'ok' : 'no'; ?>">
                        <span class="tg-conn-dot"></span>
                        <?php echo $has_token ? 'Đã kết nối' : 'Chưa kết nối'; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ( ! $is_logged_in ) : ?>
    <div class="tg-login">
        Vui lòng <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-google/' ) ) ); ?>">đăng nhập</a> để sử dụng Tool Google.
    </div>
<?php else : ?>

<!-- ═══════════════════════════════════════════════════════
     TAB 1: Tính năng
     ═══════════════════════════════════════════════════════ -->
<div class="tg-tab active" id="tab-features">
    <div class="tg-sec">
        <span class="tg-sec-t">⚡ Tính năng</span>
    </div>
    <div class="tg-sec-sub">Bấm để gửi lệnh vào chat</div>
    <div class="tg-cmds" style="margin-top:10px">
        <?php foreach ( $workflows as $w ) : ?>
        <a class="tg-cmd" href="#" data-msg="<?php echo esc_attr( $w['msg'] ); ?>" data-tool="<?php echo esc_attr( $w['tool'] ); ?>">
            <div class="tg-cmd-icon"><?php echo $w['icon']; ?></div>
            <div class="tg-cmd-body">
                <div class="tg-cmd-label"><?php echo esc_html( $w['label'] ); ?></div>
                <div class="tg-cmd-desc"><?php echo esc_html( $w['desc'] ); ?></div>
                <div class="tg-cmd-tags">
                    <?php foreach ( $w['tags'] as $tag ) : ?>
                        <span class="tg-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tg-cmd-arrow">›</div>
        </a>
        <?php endforeach; ?>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════
     TAB 3: Lịch sử
     ═══════════════════════════════════════════════════════ -->
<div class="tg-tab" id="tab-history">
    <div class="tg-sec">
        <span class="tg-sec-t">📋 Lịch sử lệnh</span>
    </div>
    <div class="tg-sec-sub">Các lệnh Google đã thực thi</div>
    <div id="tg-history-container" style="margin-top:10px">
        <div class="tg-history-load">⏳ Đang tải...</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     TAB 4: Cài đặt
     ═══════════════════════════════════════════════════════ -->
<div class="tg-tab" id="tab-settings">
    <div class="tg-sec">
        <span class="tg-sec-t">⚙️ Cài đặt Google</span>
    </div>

    <!-- Connection status -->
    <div class="tg-setting-card">
        <h3>🔗 Kết nối tài khoản</h3>
        <?php if ( ! empty( $accounts ) ) : ?>
            <?php foreach ( $accounts as $acc ) : ?>
                <div class="tg-account-row">
                    <span class="tg-account-email"><?php echo esc_html( $acc->google_email ); ?></span>
                    <span class="tg-account-status <?php echo $acc->status === 'active' ? 'active' : 'inactive'; ?>"><?php echo esc_html( $acc->status ); ?></span>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p style="font-size:13px;color:#9ca3af;">Chưa kết nối tài khoản Google nào.</p>
        <?php endif; ?>

        <a class="tg-connect-btn" href="<?php echo esc_url( $connect_url ); ?>" target="_blank" rel="noopener">
            🔗 <?php echo $has_token ? 'Cập nhật kết nối' : 'Kết nối Google'; ?>
        </a>
    </div>

    <!-- Scopes -->
    <?php if ( $has_token ) : ?>
    <div class="tg-setting-card">
        <h3>🔑 Quyền truy cập (Scopes)</h3>
        <div class="tg-scope-chips">
            <?php foreach ( $services as $svc => $info ) :
                $active = ! empty( $scope_status[ $svc ] );
                $upgrade_url = ! $active ? BZGoogle_Google_OAuth::get_scope_upgrade_url( $svc, home_url( '/tool-google/' ) ) : '';
            ?>
                <?php if ( $active ) : ?>
                    <span class="tg-scope-chip on"><?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?> ✓</span>
                <?php else : ?>
                    <a class="tg-scope-chip off" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener">
                        <?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?> — Bật
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hub info for client sites -->
    <?php if ( ! $is_hub ) : ?>
    <div class="tg-setting-card">
        <h3>🌐 OAuth Hub</h3>
        <div class="tg-hub-info">
            Kết nối qua <strong><?php echo esc_html( $hub_domain ); ?></strong>.
            Khi bấm "Kết nối Google", bạn sẽ được chuyển sang Hub (tab mới) để xác thực với Google, sau đó tự động quay về.
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; /* is_logged_in */ ?>

<!-- ════ Bottom Navigation ════ -->
<?php if ( $is_logged_in ) : ?>
<nav class="tg-bottom-nav">
    <button class="tg-nav-item active" data-tab="tab-features">
        <span class="tg-nav-icon">⚡</span>
        <span>Tính năng</span>
    </button>
    <button class="tg-nav-item" data-tab="tab-history">
        <span class="tg-nav-icon">📋</span>
        <span>Lịch sử</span>
    </button>
    <button class="tg-nav-item" data-tab="tab-settings">
        <span class="tg-nav-icon">⚙️</span>
        <span>Cài đặt</span>
    </button>
</nav>
<?php endif; ?>

<script>
(function(){
    'use strict';
    var isIframe = window.parent && window.parent !== window;

    function buildSlashMessage(msg, toolName) {
        var base = (msg || '').trim();
        var tool = (toolName || '').trim();
        if (!base || !tool) return base;
        if (base.indexOf('/') === 0) return base;
        return '/' + tool + ' ' + base;
    }

    function inferToolFromMessage(msg) {
        var text = (msg || '').trim();
        if (text.indexOf('/') !== 0) return '';
        var firstToken = text.split(/\s+/)[0] || '';
        return firstToken.replace(/^\//, '');
    }

    /* ── Send message to parent chat ── */
    function sendMsg(msg, toolName) {
        if (!msg) return;
        var slashMsg = buildSlashMessage(msg, toolName);
        var resolvedTool = (toolName || '').trim() || inferToolFromMessage(slashMsg || msg);
        if (isIframe) {
            window.parent.postMessage({
                type:   'bizcity_agent_command',
                source: 'bizgpt-tool-google',
                plugin_slug: 'bizgpt-tool-google',
                tool_name: resolvedTool,
                text:   slashMsg || msg,
                auto_send: false
            }, '*');
        } else {
            window.location.href = <?php echo wp_json_encode( home_url( '/' ) ); ?> + '?bizcity_chat_msg=' + encodeURIComponent(slashMsg || msg);
        }
    }

    /* ── Visual feedback on click ── */
    function flash(el) {
        el.style.transform = 'scale(0.96)';
        el.style.opacity = '0.7';
        setTimeout(function(){ el.style.transform = ''; el.style.opacity = ''; }, 200);
    }

    /* ── Command / Prompt click handlers ── */
    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            flash(this);
            sendMsg(this.getAttribute('data-msg'), this.getAttribute('data-tool') || '');
        });
    });

    /* ── Custom prompt ── */
    var customInput = document.getElementById('tg-custom-prompt');
    var customBtn   = document.getElementById('tg-send-custom');
    if (customBtn) {
        customBtn.addEventListener('click', function() {
            var msg = customInput ? customInput.value.trim() : '';
            if (msg) { sendMsg(msg); customInput.value = ''; }
        });
    }
    if (customInput) {
        customInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { customBtn && customBtn.click(); }
        });
    }

    /* ── Tab switching ── */
    var navItems = document.querySelectorAll('.tg-nav-item');
    var tabs = document.querySelectorAll('.tg-tab');
    var historyLoaded = false;

    navItems.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            navItems.forEach(function(b){ b.classList.remove('active'); });
            tabs.forEach(function(t){ t.classList.remove('active'); });
            this.classList.add('active');
            var el = document.getElementById(target);
            if (el) el.classList.add('active');

            // Lazy-load history
            if (target === 'tab-history' && !historyLoaded) {
                historyLoaded = true;
                loadHistory();
            }
        });
    });

    /* ── History loading via REST ── */
    var historyOffset = 0;
    var historyLimit  = 30;
    var svcIcons = { gmail: '📧', calendar: '📅', drive: '📁', contacts: '👥', oauth: '🔗' };

    function loadHistory(append) {
        var container = document.getElementById('tg-history-container');
        if (!append) { container.innerHTML = '<div class="tg-history-load">⏳ Đang tải...</div>'; historyOffset = 0; }

        fetch(<?php echo wp_json_encode( $rest_url ); ?> + '/history?limit=' + historyLimit + '&offset=' + historyOffset, {
            headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $rest_nonce ); ?> }
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!append) container.innerHTML = '';

            if (!data.items || data.items.length === 0) {
                if (!append) container.innerHTML = '<div class="tg-history-empty">Chưa có lịch sử lệnh nào.</div>';
                return;
            }

            var list = container.querySelector('.tg-history-list');
            if (!list) {
                list = document.createElement('div');
                list.className = 'tg-history-list';
                container.innerHTML = '';
                container.appendChild(list);
            }

            data.items.forEach(function(item) {
                var icon = svcIcons[item.service] || '🔧';
                var badgeCls = item.response_status === 'success' ? 'ok' : 'err';
                var badgeText = item.response_status === 'success' ? '✓' : '✗';
                var dt = item.created_at || '';

                var div = document.createElement('div');
                div.className = 'tg-history-item';
                div.innerHTML =
                    '<div class="tg-history-svc">' + icon + '</div>' +
                    '<div class="tg-history-body">' +
                        '<div class="tg-history-action">' + escHtml(item.service + ' / ' + item.action) + '</div>' +
                        (item.request_summary ? '<div class="tg-history-summary">' + escHtml(item.request_summary) + '</div>' : '') +
                        '<div class="tg-history-meta">' +
                            '<span class="tg-history-badge ' + badgeCls + '">' + badgeText + '</span>' +
                            '<span>' + escHtml(dt) + '</span>' +
                        '</div>' +
                    '</div>';
                list.appendChild(div);
            });

            historyOffset += data.items.length;

            // Remove old "load more"
            var oldBtn = container.querySelector('.tg-more-btn');
            if (oldBtn) oldBtn.remove();

            if (historyOffset < data.total) {
                var btn = document.createElement('div');
                btn.className = 'tg-more-btn';
                btn.textContent = 'Tải thêm (' + (data.total - historyOffset) + ' còn lại)';
                btn.onclick = function(){ loadHistory(true); };
                container.appendChild(btn);
            }
        })
        .catch(function() {
            container.innerHTML = '<div class="tg-history-empty" style="color:#dc2626">Không thể tải lịch sử.</div>';
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
})();
</script>
</body>
</html>
