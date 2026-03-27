<?php
/**
 * BizCoach Map — Mobile Agent Profile with Tabs
 *
 * 3 tabs:
 *   1. ⚡ Shortcuts: Guided command buttons (astro prompts)
 *   2. 💬 Hỏi AI: Direct AI Q&A interface (inline chat)
 *   3. 🌟 Hồ sơ: Profile status + quick actions
 *
 * Loaded inside Touch Bar iframe or standalone on mobile.
 * Uses postMessage to communicate with parent chat.
 *
 * @package BizCoach_Map
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();
$icon_url     = BCCM_URL . 'assets/icon/horoscope.png';

/* ── User data ── */
$user_name    = '';
$has_profile  = false;
$has_chart    = false;
$has_transit  = false;
$coachee_id   = 0;

if ( $is_logged_in ) {
    global $wpdb;
    $t       = bccm_tables();
    $t_astro = $wpdb->prefix . 'bccm_astro';

    $coachee    = function_exists( 'bccm_get_or_create_user_coachee' )
        ? bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' )
        : null;
    $coachee_id = $coachee ? (int) $coachee['id'] : 0;
    $user_name  = $coachee['full_name'] ?? wp_get_current_user()->display_name;
    $has_profile = ! empty( $coachee['full_name'] ) && ! empty( $coachee['dob'] );

    $astro_w = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, summary, birth_time FROM $t_astro WHERE user_id=%d AND chart_type='western' LIMIT 1", $user_id
    ), ARRAY_A );
    $has_chart   = ! empty( $astro_w['summary'] );
    $has_transit = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}bccm_transit_snapshots WHERE user_id=%d AND target_date >= CURDATE()", $user_id
    ) );
}

/* ── Shortcut Commands ── */
$shortcuts = [
    [
        'icon'  => '🌟',
        'label' => 'Tạo bản đồ sao',
        'desc'  => 'Tạo bản đồ Natal Chart hoàn chỉnh từ ngày sinh & giờ sinh',
        'msg'   => 'Tạo bản đồ sao cho tôi',
        'tool'  => 'create_natal_chart',
        'tags'  => [ 'Natal', 'Western', 'Vedic' ],
    ],
    [
        'icon'  => '🔄',
        'label' => 'Xem vận hạn Transit',
        'desc'  => 'Tạo bản đồ vận hạn dựa trên transit hành tinh hiện tại',
        'msg'   => 'Tạo bản đồ vận hạn transit cho tôi',
        'tool'  => 'create_transit_map',
        'tags'  => [ 'Transit', 'Vận hạn', 'Hành tinh' ],
    ],
    [
        'icon'  => '📅',
        'label' => 'Dự báo hôm nay',
        'desc'  => 'Xem vận mệnh tổng quan cho ngày hôm nay',
        'msg'   => 'Xem hôm nay tôi thế nào?',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Hôm nay', 'Tổng quan', 'Vận mệnh' ],
    ],
    [
        'icon'  => '📆',
        'label' => 'Dự báo tuần tới',
        'desc'  => 'Xem vận mệnh chi tiết cho tuần tới',
        'msg'   => 'Xem vận hạn tuần tới cho tôi',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Tuần tới', 'Chi tiết', 'Transit' ],
    ],
    [
        'icon'  => '💕',
        'label' => 'Xem tương hợp',
        'desc'  => 'So sánh bản đồ sao với người khác — tình duyên, đối tác',
        'msg'   => 'Tôi muốn xem tôi với người kia có hợp không?',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Synastry', 'Tương hợp', 'Tình duyên' ],
    ],
    [
        'icon'  => '💼',
        'label' => 'Vận sự nghiệp',
        'desc'  => 'Phân tích chiêm tinh cho sự nghiệp & tài chính',
        'msg'   => 'Phân tích vận sự nghiệp và tài chính tháng này cho tôi',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Sự nghiệp', 'Tài chính', 'MC/IC' ],
    ],
    [
        'icon'  => '❤️',
        'label' => 'Vận tình cảm',
        'desc'  => 'Xem vận tình cảm dựa trên Kim Tinh, cung 7, cung 5',
        'msg'   => 'Phân tích vận tình cảm cho tôi',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Tình cảm', 'Venus', 'Cung 7' ],
    ],
    [
        'icon'  => '🏥',
        'label' => 'Vận sức khỏe',
        'desc'  => 'Phân tích sức khỏe chiêm tinh — cung 6, cung 12',
        'msg'   => 'Xem vận sức khỏe của tôi thế nào?',
        'tool'  => 'bizcoach_consult',
        'tags'  => [ 'Sức khỏe', 'Cung 6', 'Cung 12' ],
    ],
];

/* ── Quick prompts for AI tab ── */
$quick_prompts = [
    [ 'icon' => '🌟', 'text' => 'Hôm nay tôi thế nào?', 'tool' => 'bizcoach_consult' ],
    [ 'icon' => '💕', 'text' => 'Vận tình cảm tuần này ra sao?', 'tool' => 'bizcoach_consult' ],
    [ 'icon' => '💼', 'text' => 'Thời điểm này có nên đầu tư không?', 'tool' => 'bizcoach_consult' ],
    [ 'icon' => '🔄', 'text' => 'Sao nào đang ảnh hưởng tôi nhiều nhất?', 'tool' => 'bizcoach_consult' ],
    [ 'icon' => '🌙', 'text' => 'Trăng tròn sắp tới ảnh hưởng thế nào?', 'tool' => 'bizcoach_consult' ],
    [ 'icon' => '📊', 'text' => 'Tóm tắt bản đồ sao của tôi', 'tool' => 'bizcoach_consult' ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>BizCoach Chiêm tinh – Agent</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --c-primary:#6366f1;--c-primary-light:#eef2ff;--c-primary-dark:#4338ca;
    --c-accent:#8b5cf6;--c-bg:#f8fafc;--c-card:#fff;
    --c-text:#1f2937;--c-muted:#6b7280;--c-border:#e5e7eb;
}
html{font-size:14px}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:var(--c-bg);color:var(--c-text);
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;min-height:100vh;
    display:flex;flex-direction:column;
}

/* ── Hero ── */
.bc-hero{
    background:linear-gradient(135deg,#312e81 0%,#6366f1 50%,#a78bfa 100%);
    padding:24px 16px 16px;text-align:center;color:#fff;
    position:relative;overflow:hidden;
}
.bc-hero::before{
    content:'';position:absolute;top:-50%;right:-30%;
    width:200px;height:200px;background:rgba(255,255,255,.06);border-radius:50%;
}
.bc-hero-icon{
    width:64px;height:64px;border-radius:16px;
    border:3px solid rgba(255,255,255,.3);box-shadow:0 4px 12px rgba(0,0,0,.2);
    margin:0 auto 10px;display:block;object-fit:cover;background:#fff;
}
.bc-hero-name{font-size:18px;font-weight:700;margin-bottom:4px}
.bc-hero-desc{font-size:12px;opacity:.8;line-height:1.4}
.bc-hero-badges{display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap}
.bc-badge{
    font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;
    display:inline-flex;align-items:center;gap:3px;
}
.bc-badge-ok{background:rgba(74,222,128,.25);color:#bbf7d0}
.bc-badge-warn{background:rgba(251,191,36,.25);color:#fde68a}

/* ── Tabs ── */
.bc-tabs{
    display:flex;background:#fff;border-bottom:2px solid var(--c-border);
    position:sticky;top:0;z-index:10;
}
.bc-tab{
    flex:1;text-align:center;padding:12px 4px 10px;
    font-size:12px;font-weight:600;color:var(--c-muted);
    cursor:pointer;border-bottom:3px solid transparent;
    transition:all .2s;-webkit-tap-highlight-color:transparent;
    background:none;border-top:none;border-left:none;border-right:none;
}
.bc-tab.active{color:var(--c-primary);border-bottom-color:var(--c-primary)}
.bc-tab:hover{color:var(--c-primary-dark)}

/* ── Tab Panels ── */
.bc-panel{display:none;padding:16px;animation:fadeIn .25s ease}
.bc-panel.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── Command Cards ── */
.bc-cmds{display:flex;flex-direction:column;gap:10px}
.bc-cmd{
    display:flex;align-items:flex-start;gap:12px;
    background:var(--c-card);border-radius:14px;padding:14px;
    box-shadow:0 2px 8px rgba(0,0,0,.05);border:1px solid var(--c-border);
    cursor:pointer;transition:all .2s;
    -webkit-tap-highlight-color:transparent;
}
.bc-cmd:hover{border-color:#c7d2fe;box-shadow:0 4px 16px rgba(99,102,241,.1);transform:translateY(-1px)}
.bc-cmd:active{transform:scale(.98)}
.bc-cmd-icon{
    width:42px;height:42px;border-radius:12px;
    background:linear-gradient(135deg,var(--c-primary-light),#e0e7ff);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.bc-cmd-body{flex:1;min-width:0}
.bc-cmd-label{font-size:13px;font-weight:600}
.bc-cmd-desc{font-size:11px;color:var(--c-muted);margin-top:2px;line-height:1.4}
.bc-cmd-tags{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap}
.bc-cmd-tag{
    font-size:9px;font-weight:500;padding:2px 6px;border-radius:6px;
    background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;
}
.bc-cmd-arrow{color:#c7d2fe;font-size:16px;flex-shrink:0;margin-top:4px;transition:color .2s}
.bc-cmd:hover .bc-cmd-arrow{color:var(--c-primary)}

/* ── AI Chat Panel ── */
.bc-chat-area{
    display:flex;flex-direction:column;height:calc(100vh - 200px);min-height:400px;
}
.bc-chat-messages{
    flex:1;overflow-y:auto;padding:12px 0;
    display:flex;flex-direction:column;gap:10px;
}
.bc-chat-welcome{text-align:center;padding:24px 16px;color:var(--c-muted)}
.bc-chat-welcome .icon{font-size:40px;margin-bottom:8px}
.bc-chat-welcome h3{font-size:16px;color:var(--c-text);margin-bottom:6px}
.bc-chat-welcome p{font-size:12px;line-height:1.5}

.bc-chat-msg{max-width:85%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5;word-break:break-word}
.bc-chat-msg.user{align-self:flex-end;background:var(--c-primary);color:#fff;border-bottom-right-radius:4px}
.bc-chat-msg.bot{align-self:flex-start;background:var(--c-card);border:1px solid var(--c-border);border-bottom-left-radius:4px}
.bc-chat-msg.bot .md-content{white-space:pre-wrap}
.bc-chat-typing{align-self:flex-start;background:var(--c-card);border:1px solid var(--c-border);border-radius:14px;padding:10px 14px;font-size:13px;color:var(--c-muted)}
.bc-chat-typing::after{content:'...';animation:dots 1.5s steps(3,end) infinite}
@keyframes dots{0%{content:'.'}33%{content:'..'}66%{content:'...'}}

.bc-quick-prompts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.bc-quick-prompt{
    font-size:12px;padding:6px 12px;border-radius:20px;
    background:var(--c-primary-light);color:var(--c-primary);
    border:1px solid #c7d2fe;cursor:pointer;
    transition:all .15s;-webkit-tap-highlight-color:transparent;
}
.bc-quick-prompt:hover{background:#c7d2fe}
.bc-quick-prompt:active{transform:scale(.96)}

.bc-chat-input-wrap{
    display:flex;gap:8px;padding:12px 0 0;
    border-top:1px solid var(--c-border);margin-top:auto;
}
.bc-chat-input{
    flex:1;padding:10px 14px;border:1px solid var(--c-border);border-radius:12px;
    font-size:14px;outline:none;resize:none;min-height:42px;max-height:120px;
    font-family:inherit;
}
.bc-chat-input:focus{border-color:var(--c-primary);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.bc-chat-send{
    width:42px;height:42px;border-radius:12px;border:none;
    background:var(--c-primary);color:#fff;font-size:18px;
    cursor:pointer;display:flex;align-items:center;justify-content:center;
    transition:background .2s;flex-shrink:0;
}
.bc-chat-send:hover{background:var(--c-primary-dark)}
.bc-chat-send:disabled{background:#d1d5db;cursor:not-allowed}

/* ── Profile Status ── */
.bc-status-card{
    background:var(--c-card);border:1px solid var(--c-border);border-radius:14px;
    padding:16px;margin-bottom:12px;
}
.bc-status-card h3{font-size:14px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.bc-status-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:13px}
.bc-status-label{color:var(--c-muted)}
.bc-status-val{font-weight:600}
.bc-status-ok{color:#16a34a}
.bc-status-warn{color:#d97706}
.bc-status-err{color:#dc2626}

.bc-action-btn{
    display:block;width:100%;padding:12px;border-radius:12px;border:none;
    font-size:14px;font-weight:600;cursor:pointer;text-align:center;
    margin-bottom:8px;transition:all .2s;
    -webkit-tap-highlight-color:transparent;
}
.bc-action-primary{background:linear-gradient(135deg,var(--c-primary),var(--c-accent));color:#fff}
.bc-action-primary:hover{opacity:.9}
.bc-action-secondary{background:var(--c-primary-light);color:var(--c-primary);border:1px solid #c7d2fe}
.bc-action-secondary:hover{background:#e0e7ff}

/* ── Section Title ── */
.bc-section-title{
    font-size:14px;font-weight:700;color:var(--c-text);
    margin:16px 0 8px;display:flex;align-items:center;gap:6px;
}
.bc-section-sub{font-size:11px;color:var(--c-muted);margin-bottom:10px}

/* ── Login ── */
.bc-login{text-align:center;padding:48px 20px}
.bc-login .icon{font-size:48px;margin-bottom:16px}
.bc-login h2{font-size:18px;margin-bottom:8px}
.bc-login p{color:var(--c-muted);font-size:13px;margin-bottom:20px}
.bc-login-btn{
    display:inline-block;padding:12px 28px;
    background:linear-gradient(135deg,var(--c-primary),var(--c-accent));
    color:#fff;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;
}

/* ── Footer ── */
.bc-footer{text-align:center;padding:16px;font-size:10px;color:#9ca3af;border-top:1px solid var(--c-border)}

@media(max-width:640px){
    .bc-hero{padding:20px 12px 14px}
    .bc-panel{padding:12px}
    .bc-chat-area{height:calc(100vh - 180px);min-height:350px}
}
</style>
</head>
<body>

<!-- Hero -->
<div class="bc-hero">
    <img src="<?php echo esc_url( $icon_url ); ?>" alt="Chiêm tinh" class="bc-hero-icon"
         onerror="this.outerHTML='<div style=\'width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 10px\'>🔮</div>'">
    <div class="bc-hero-name">Trợ lý Chiêm tinh AI</div>
    <div class="bc-hero-desc">
        Bản đồ sao · Transit · Vận mệnh · Tương hợp<br>
        Phân tích chiêu tinh cá nhân bằng AI chuyên sâu
    </div>
    <?php if ( $is_logged_in ): ?>
    <div class="bc-hero-badges">
        <span class="bc-badge <?php echo $has_profile ? 'bc-badge-ok' : 'bc-badge-warn'; ?>">
            <?php echo $has_profile ? '✅ Hồ sơ' : '⚠️ Chưa khai báo'; ?>
        </span>
        <span class="bc-badge <?php echo $has_chart ? 'bc-badge-ok' : 'bc-badge-warn'; ?>">
            <?php echo $has_chart ? '🌟 Natal Chart' : '⚠️ Chưa có chart'; ?>
        </span>
        <span class="bc-badge <?php echo $has_transit ? 'bc-badge-ok' : 'bc-badge-warn'; ?>">
            <?php echo $has_transit ? '🔄 Transit' : '⚠️ Chưa có transit'; ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<?php if ( ! $is_logged_in ): ?>
<div class="bc-login">
    <div class="icon">🔐</div>
    <h2>Đăng nhập để bắt đầu</h2>
    <p>Đăng nhập để sử dụng trợ lý chiêm tinh AI và tạo bản đồ sao.</p>
    <a href="<?php echo esc_url( wp_login_url( home_url( '/chiem-tinh-profile/?bizcity_iframe=1' ) ) ); ?>" class="bc-login-btn">Đăng nhập</a>
</div>
<?php else: ?>

<!-- Tabs -->
<div class="bc-tabs">
    <button class="bc-tab active" data-tab="shortcuts">⚡ Shortcuts</button>
    <button class="bc-tab" data-tab="chat">💬 Hỏi AI</button>
    <button class="bc-tab" data-tab="profile">🌟 Hồ sơ</button>
</div>

<!-- Panel: Shortcuts -->
<div class="bc-panel active" id="panel-shortcuts">
    <div class="bc-section-title">⚡ Chạm để gửi lệnh</div>
    <div class="bc-section-sub">AI thực hiện phân tích chiêm tinh + tạo bản đồ từ A→Z</div>

    <div class="bc-cmds">
        <?php foreach ( $shortcuts as $cmd ): ?>
        <div class="bc-cmd" data-msg="<?php echo esc_attr( $cmd['msg'] ); ?>" data-tool="<?php echo esc_attr( $cmd['tool'] ); ?>">
            <div class="bc-cmd-icon"><?php echo $cmd['icon']; ?></div>
            <div class="bc-cmd-body">
                <div class="bc-cmd-label"><?php echo esc_html( $cmd['label'] ); ?></div>
                <div class="bc-cmd-desc"><?php echo esc_html( $cmd['desc'] ); ?></div>
                <div class="bc-cmd-tags">
                    <?php foreach ( $cmd['tags'] as $tag ): ?>
                    <span class="bc-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bc-cmd-arrow">→</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Panel: AI Chat -->
<div class="bc-panel" id="panel-chat">
    <div class="bc-chat-area">
        <div class="bc-chat-messages" id="chatMessages">
            <div class="bc-chat-welcome">
                <div class="icon">🔮</div>
                <h3>Hỏi AI Chiêm tinh</h3>
                <p>Hỏi bất kỳ điều gì về chiêm tinh, vận mệnh, transit, tương hợp...</p>
            </div>
            <div class="bc-quick-prompts">
                <?php foreach ( $quick_prompts as $qp ): ?>
                <span class="bc-quick-prompt" data-msg="<?php echo esc_attr( $qp['text'] ); ?>" data-tool="<?php echo esc_attr( $qp['tool'] ); ?>">
                    <?php echo $qp['icon'] . ' ' . esc_html( $qp['text'] ); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bc-chat-input-wrap">
            <textarea id="chatInput" class="bc-chat-input" placeholder="Hỏi chiêm tinh AI..." rows="1"></textarea>
            <button id="chatSend" class="bc-chat-send" title="Gửi">➤</button>
        </div>
    </div>
</div>

<!-- Panel: Profile -->
<div class="bc-panel" id="panel-profile">
    <div class="bc-status-card">
        <h3>📋 Trạng thái hồ sơ</h3>
        <div class="bc-status-row">
            <span class="bc-status-label">Tên</span>
            <span class="bc-status-val"><?php echo esc_html( $user_name ?: '—' ); ?></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Hồ sơ cá nhân</span>
            <span class="bc-status-val <?php echo $has_profile ? 'bc-status-ok' : 'bc-status-warn'; ?>">
                <?php echo $has_profile ? '✅ Đầy đủ' : '⚠️ Thiếu thông tin'; ?>
            </span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Bản đồ sao Western</span>
            <span class="bc-status-val <?php echo $has_chart ? 'bc-status-ok' : 'bc-status-err'; ?>">
                <?php echo $has_chart ? '✅ Đã tạo' : '❌ Chưa có'; ?>
            </span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Transit hiện tại</span>
            <span class="bc-status-val <?php echo $has_transit ? 'bc-status-ok' : 'bc-status-warn'; ?>">
                <?php echo $has_transit ? '✅ Có dữ liệu' : '⚠️ Chưa fetch'; ?>
            </span>
        </div>
    </div>

    <?php if ( ! $has_profile ): ?>
    <button class="bc-action-btn bc-action-primary" onclick="window.location.href='<?php echo esc_url( home_url( '/chiem-tinh-profile/' ) ); ?>'">
        📝 Khai báo hồ sơ ngay
    </button>
    <?php endif; ?>

    <?php if ( ! $has_chart ): ?>
    <button class="bc-action-btn bc-action-primary" data-msg="Tạo bản đồ sao cho tôi" data-tool="create_natal_chart">
        🌟 Tạo bản đồ sao
    </button>
    <?php endif; ?>

    <?php if ( $has_chart && ! $has_transit ): ?>
    <button class="bc-action-btn bc-action-secondary" data-msg="Tạo bản đồ vận hạn transit cho tôi" data-tool="create_transit_map">
        🔄 Tạo Transit
    </button>
    <?php endif; ?>

    <button class="bc-action-btn bc-action-secondary" data-msg="Tóm tắt bản đồ sao của tôi">
        📊 Xem tóm tắt chart
    </button>

    <button class="bc-action-btn bc-action-secondary" onclick="window.location.href='<?php echo esc_url( home_url( '/chiem-tinh-profile/' ) ); ?>'">
        ⚙️ Chỉnh sửa hồ sơ chiêm tinh
    </button>
</div>

<?php endif; // is_logged_in ?>

<!-- Footer -->
<div class="bc-footer">
    Agent Chiêm tinh v<?php echo esc_html( BCCM_VERSION ); ?> · AI-powered astrology
</div>

<script>
(function() {
    'use strict';

    /* ── Tab switching ── */
    var tabs   = document.querySelectorAll('.bc-tab');
    var panels = document.querySelectorAll('.bc-panel');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            var panel = document.getElementById('panel-' + target);
            if (panel) panel.classList.add('active');
        });
    });

    /* ── Send command to parent chat via postMessage ── */
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

    function sendToParent(msg, toolName) {
        if (!msg) return;
        var slashMsg = buildSlashMessage(msg, toolName);
        var resolvedTool = (toolName || '').trim() || inferToolFromMessage(slashMsg || msg);
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type:   'bizcity_agent_command',
                source: 'bizcoach-map',
                plugin_slug: 'bizcoach-map',
                tool_name: resolvedTool,
                text:   slashMsg || msg
            }, '*');
        }
    }

    /* ── Shortcut clicks → send to parent ── */
    document.querySelectorAll('.bc-cmd[data-msg], .bc-action-btn[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var msg = this.getAttribute('data-msg');
            var toolName = this.getAttribute('data-tool') || '';
            sendToParent(msg, toolName);

            this.style.transform = 'scale(0.96)';
            this.style.opacity = '0.7';
            var self = this;
            setTimeout(function() { self.style.transform = ''; self.style.opacity = ''; }, 200);
        });
    });

    /* ── AI Chat (inline direct) ── */
    var chatMessages = document.getElementById('chatMessages');
    var chatInput    = document.getElementById('chatInput');
    var chatSend     = document.getElementById('chatSend');
    var isStreaming   = false;

    if (!chatInput || !chatSend) return;

    var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    var NONCE    = '<?php echo esc_js( wp_create_nonce( 'bizcity_webchat_nonce' ) ); ?>';

    /* Quick prompts */
    document.querySelectorAll('.bc-quick-prompt[data-msg]').forEach(function(el) {
        el.addEventListener('click', function() {
            var msg = this.getAttribute('data-msg');
            var toolName = this.getAttribute('data-tool') || '';
            chatInput.value = buildSlashMessage(msg, toolName);
            doSendChat();
        });
    });

    chatSend.addEventListener('click', doSendChat);
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSendChat(); }
    });

    /* Auto-resize textarea */
    chatInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    function appendMsg(role, html) {
        /* Remove welcome if first message */
        var welcome = chatMessages.querySelector('.bc-chat-welcome');
        if (welcome) welcome.remove();
        var prompts = chatMessages.querySelector('.bc-quick-prompts');
        if (prompts) prompts.remove();

        var div = document.createElement('div');
        div.className = 'bc-chat-msg ' + role;
        if (role === 'bot') {
            var md = document.createElement('div');
            md.className = 'md-content';
            md.innerHTML = html;
            div.appendChild(md);
        } else {
            div.textContent = html;
        }
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return div;
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'bc-chat-typing';
        div.id = 'typingIndicator';
        div.textContent = 'Đang phân tích';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function hideTyping() {
        var el = document.getElementById('typingIndicator');
        if (el) el.remove();
    }

    function doSendChat() {
        var msg = (chatInput.value || '').trim();
        if (!msg || isStreaming) return;

        appendMsg('user', msg);
        chatInput.value = '';
        chatInput.style.height = 'auto';
        showTyping();
        isStreaming = true;
        chatSend.disabled = true;

        /* Also notify parent chat */
        sendToParent(msg);

        /* SSE stream to get AI response directly */
        var streamUrl = AJAX_URL + '?action=bizcity_chat_stream'
            + '&_ajax_nonce=' + encodeURIComponent(NONCE)
            + '&message=' + encodeURIComponent(msg)
            + '&session_id='
            + '&stream=1';

        var botDiv = null;
        var botText = '';

        fetch(streamUrl, { credentials: 'same-origin' }).then(function(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();

            function pump() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        hideTyping();
                        isStreaming = false;
                        chatSend.disabled = false;
                        return;
                    }

                    var chunk = decoder.decode(result.value, { stream: true });
                    var lines = chunk.split('\n');

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (!line.startsWith('data:')) continue;
                        var data = line.substring(5).trim();
                        if (data === '[DONE]') continue;

                        try {
                            var json = JSON.parse(data);
                            if (json.type === 'token' || json.type === 'content') {
                                hideTyping();
                                if (!botDiv) botDiv = appendMsg('bot', '');
                                botText += (json.content || json.text || '');
                                botDiv.querySelector('.md-content').innerHTML = formatMarkdown(botText);
                                chatMessages.scrollTop = chatMessages.scrollHeight;
                            } else if (json.type === 'error') {
                                hideTyping();
                                appendMsg('bot', '❌ ' + (json.message || 'Lỗi không xác định'));
                            }
                        } catch(ex) {
                            /* ignore non-JSON lines */
                        }
                    }

                    return pump();
                });
            }

            return pump();
        }).catch(function(err) {
            hideTyping();
            isStreaming = false;
            chatSend.disabled = false;
            appendMsg('bot', '❌ Lỗi kết nối: ' + err.message);
        });
    }

    /* Simple markdown → HTML */
    function formatMarkdown(text) {
        return text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code style="background:#f1f5f9;padding:1px 4px;border-radius:4px;font-size:12px;">$1</code>')
            .replace(/\n/g, '<br>');
    }

})();
</script>

</body>
</html>
