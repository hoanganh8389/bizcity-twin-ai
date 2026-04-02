<?php
/**
 * BizCity Skills — Public Agent Page
 *
 * Route: /skills/
 * 4-tab bottom navigation: Kỹ năng | Danh mục | Tìm kiếm | Cài đặt
 *
 * Displayed inside chat iframe or standalone. Uses postMessage + REST API.
 *
 * @package  BizCity_Skills
 * @since    2026-04-03
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$user_id      = get_current_user_id();
$is_admin     = current_user_can( 'manage_options' );

/* ── REST config ── */
$rest_url   = esc_url_raw( rest_url( 'bizcity-skill/v1' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );

/* ── Default categories ── */
$categories = [
    [ 'slug' => 'content', 'icon' => '📝', 'label' => 'Nội dung',  'desc' => 'Kỹ năng viết, tạo content, biên tập' ],
    [ 'slug' => 'tools',   'icon' => '🔧', 'label' => 'Công cụ',   'desc' => 'Tích hợp tools, automation, workflow' ],
    [ 'slug' => 'note',    'icon' => '📒', 'label' => 'Ghi chú',   'desc' => 'Nhật ký, note, tóm tắt, recap' ],
    [ 'slug' => 'nhat-ky', 'icon' => '📖', 'label' => 'Nhật ký',   'desc' => 'Nhật ký cá nhân, reflection, daily log' ],
];

/* ── Example workflows ── */
$workflows = [
    [ 'icon' => '📝', 'label' => 'Viết bài blog',      'desc' => 'Tạo bài blog chuẩn SEO với outline + nội dung',    'msg' => 'Viết bài blog',                'tags' => ['Content','Blog'] ],
    [ 'icon' => '💬', 'label' => 'Tóm tắt cuộc họp',   'desc' => 'Tóm tắt nhanh nội dung cuộc họp quan trọng',       'msg' => 'Tóm tắt cuộc họp',             'tags' => ['Note','Meeting'] ],
    [ 'icon' => '🔧', 'label' => 'Phân tích dữ liệu',  'desc' => 'Phân tích số liệu, báo cáo, thống kê nhanh',       'msg' => 'Phân tích dữ liệu',            'tags' => ['Tools','Data'] ],
    [ 'icon' => '📧', 'label' => 'Soạn email chuyên nghiệp', 'desc' => 'Viết email chuẩn mực cho công việc',           'msg' => 'Soạn email chuyên nghiệp',     'tags' => ['Content','Email'] ],
    [ 'icon' => '📋', 'label' => 'Lên kế hoạch dự án',  'desc' => 'Tạo project plan với timeline và milestones',       'msg' => 'Lên kế hoạch dự án',           'tags' => ['Tools','Planning'] ],
    [ 'icon' => '📖', 'label' => 'Viết nhật ký hôm nay','desc' => 'Ghi lại ngày hôm nay: công việc, suy nghĩ, cảm xúc','msg' => 'Viết nhật ký hôm nay',        'tags' => ['Nhật ký','Daily'] ],
];

/* ── Prompts gợi ý ── */
$prompts = [
    [ 'cat' => 'Nội dung', 'icon' => '📝', 'prompts' => [
        'Viết bài giới thiệu sản phẩm mới',
        'Tạo outline cho bài blog SEO',
        'Soạn caption social media hấp dẫn',
        'Viết email follow-up khách hàng',
    ]],
    [ 'cat' => 'Ghi chú',  'icon' => '📒', 'prompts' => [
        'Tóm tắt cuộc họp hôm nay',
        'Ghi note ý tưởng mới',
        'Recap tuần này',
        'Viết nhật ký hôm nay',
    ]],
    [ 'cat' => 'Công cụ',  'icon' => '🔧', 'prompts' => [
        'Phân tích file dữ liệu',
        'So sánh 2 phương án',
        'Lên kế hoạch sprint mới',
        'Tạo checklist công việc',
    ]],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Skills – Kỹ năng AI</title>
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
.sk-bottom-nav{
    position:fixed;bottom:0;left:0;right:0;
    height:56px;
    background:#fff;
    border-top:1px solid #e5e7eb;
    display:flex;
    z-index:100;
    box-shadow:0 -2px 10px rgba(0,0,0,.06);
}
.sk-nav-item{
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
.sk-nav-item.active{color:#4d6bfe;font-weight:600}
.sk-nav-item.active::before{
    content:'';position:absolute;top:0;left:20%;right:20%;
    height:2px;background:#4d6bfe;border-radius:0 0 2px 2px;
}
.sk-nav-icon{font-size:20px;line-height:1}

/* ══ Tab Content ══ */
.sk-tab{display:none;padding:16px 16px 24px;max-width:100%;margin:0 auto}
.sk-tab.active{display:block}

/* ══ Hero Card ══ */
.sk-hero{
    background:linear-gradient(135deg,#10b981 0%,#059669 50%,#047857 100%);
    border-radius:16px;padding:18px 16px 14px;
    text-align:center;color:#fff;
    box-shadow:0 6px 24px rgba(16,185,129,.2);
    margin-bottom:16px;position:relative;overflow:hidden;
}
.sk-hero::before{content:'';position:absolute;top:-40%;right:-30%;width:160px;height:160px;background:rgba(255,255,255,.06);border-radius:50%}
.sk-hero-row{display:flex;align-items:center;gap:12px;text-align:left}
.sk-hero-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:28px;background:rgba(255,255,255,.15);flex-shrink:0}
.sk-hero-info{flex:1;min-width:0}
.sk-hero-name{font-size:16px;font-weight:700}
.sk-hero-desc{font-size:11px;opacity:.8;line-height:1.4;margin-top:2px}
.sk-hero-stat{display:inline-flex;align-items:center;gap:5px;font-size:11px;margin-top:6px;padding:3px 10px;border-radius:12px;background:rgba(255,255,255,.15)}

/* ══ Section ══ */
.sk-sec{margin:18px 0 8px;display:flex;align-items:center;gap:6px}
.sk-sec-t{font-size:14px;font-weight:700;color:#1f2937}
.sk-sec-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* ══ Skill Cards ══ */
.sk-cards{display:flex;flex-direction:column;gap:8px}
.sk-card{
    display:flex;align-items:flex-start;gap:12px;
    background:#fff;border-radius:12px;padding:12px 14px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid #e5e7eb;
    cursor:pointer;transition:all .15s;
    text-decoration:none;color:inherit;-webkit-tap-highlight-color:transparent;
}
.sk-card:hover{border-color:#6ee7b7;box-shadow:0 3px 12px rgba(16,185,129,.1);transform:translateY(-1px)}
.sk-card:active{transform:scale(.98)}
.sk-card-icon{
    width:40px;height:40px;border-radius:10px;
    background:linear-gradient(135deg,#ecfdf5,#d1fae5);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.sk-card-body{flex:1;min-width:0}
.sk-card-label{font-size:13px;font-weight:600;color:#1f2937;margin-bottom:2px}
.sk-card-desc{font-size:11px;color:#6b7280;line-height:1.3}
.sk-card-tags{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap}
.sk-card-tag{font-size:9px;font-weight:600;padding:1px 7px;border-radius:5px;background:#ecfdf5;color:#059669}
.sk-card-arrow{width:22px;height:22px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:11px;color:#9ca3af;align-self:center;flex-shrink:0}

/* ══ Category Grid ══ */
.sk-cat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
.sk-cat-card{
    background:#fff;border-radius:12px;padding:14px;
    border:1px solid #e5e7eb;cursor:pointer;
    transition:all .15s;text-align:center;
}
.sk-cat-card:hover{border-color:#6ee7b7;box-shadow:0 3px 12px rgba(16,185,129,.1)}
.sk-cat-card:active{transform:scale(.97)}
.sk-cat-icon{font-size:28px;margin-bottom:6px}
.sk-cat-label{font-size:13px;font-weight:700;color:#1f2937}
.sk-cat-desc{font-size:10px;color:#9ca3af;margin-top:2px;line-height:1.3}
.sk-cat-count{font-size:10px;color:#059669;font-weight:600;margin-top:4px}

/* ══ Skill List (in category) ══ */
.sk-skill-list{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.sk-skill-item{
    display:flex;align-items:center;gap:10px;
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #e5e7eb;transition:all .12s;
    cursor:pointer;
}
.sk-skill-item:hover{border-color:#6ee7b7}
.sk-skill-title{font-size:13px;font-weight:600;color:#1f2937;flex:1;min-width:0}
.sk-skill-title span{display:block;font-size:10px;font-weight:400;color:#9ca3af;margin-top:1px}
.sk-skill-arrow{font-size:11px;color:#9ca3af}

/* ══ Search ══ */
.sk-search-box{
    display:flex;gap:8px;margin-bottom:16px;
}
.sk-search-input{
    flex:1;padding:10px 14px;
    border:1px solid #d1d5db;border-radius:10px;
    font-size:14px;outline:none;transition:border-color .15s;
}
.sk-search-input:focus{border-color:#10b981;box-shadow:0 0 0 2px rgba(16,185,129,.15)}
.sk-search-btn{
    padding:10px 18px;
    background:#10b981;color:#fff;border:none;
    border-radius:10px;font-weight:600;font-size:13px;
    cursor:pointer;transition:all .15s;white-space:nowrap;
}
.sk-search-btn:hover{background:#059669}
.sk-search-btn:disabled{opacity:.5;cursor:not-allowed}
.sk-search-results{display:flex;flex-direction:column;gap:6px;margin-top:10px}

/* ══ Prompt Tab ══ */
.sk-prompt-cat{margin-bottom:16px}
.sk-prompt-cat-hd{font-size:13px;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.sk-prompt-list{display:flex;flex-direction:column;gap:5px}
.sk-prompt-item{
    display:flex;align-items:center;gap:8px;
    background:#fff;border-radius:10px;padding:10px 12px;
    border:1px solid #f3f4f6;cursor:pointer;
    transition:all .12s;font-size:13px;color:#374151;
}
.sk-prompt-item:hover{border-color:#6ee7b7;background:#ecfdf5}
.sk-prompt-item:active{transform:scale(.98)}
.sk-prompt-send{
    width:28px;height:28px;border-radius:8px;
    background:#ecfdf5;color:#059669;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;
}

/* ══ Settings card ══ */
.sk-settings-card{
    background:#fff;border-radius:12px;padding:14px 16px;
    border:1px solid #e5e7eb;margin-bottom:10px;
}
.sk-settings-card h3{font-size:14px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}

/* ══ Login ══ */
.sk-login{text-align:center;padding:60px 20px;color:#6b7280;font-size:14px;line-height:1.6}
.sk-login a{color:#10b981;text-decoration:underline}

/* ══ Category back button ══ */
.sk-back{
    display:inline-flex;align-items:center;gap:4px;
    font-size:12px;color:#059669;font-weight:600;
    cursor:pointer;margin-bottom:8px;
    background:none;border:none;
}
.sk-back:hover{text-decoration:underline}

/* ══ Empty ══ */
.sk-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px}
.sk-loading{text-align:center;padding:20px;color:#9ca3af;font-size:13px}
</style>
</head>
<body>

<!-- ════ Hero ════ -->
<div style="padding:16px 16px 0">
    <div class="sk-hero">
        <div class="sk-hero-row">
            <div class="sk-hero-icon">⚡</div>
            <div class="sk-hero-info">
                <div class="sk-hero-name">Skills – Kỹ năng AI</div>
                <div class="sk-hero-desc">Thư viện kỹ năng plug & play — mỗi skill là một lệnh chuyên biệt cho AI</div>
                <?php if ( $is_logged_in ) : ?>
                    <div class="sk-hero-stat">
                        <span id="sk-total-count">⏳</span> kỹ năng
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ( ! $is_logged_in ) : ?>
    <div class="sk-login">
        Vui lòng <a href="<?php echo esc_url( wp_login_url( home_url( '/skills/' ) ) ); ?>">đăng nhập</a> để sử dụng Skills.
    </div>
<?php else : ?>

<!-- ═══════════════════════════════════════════════════════════
     TAB 1: Kỹ năng (featured + all)
     ═══════════════════════════════════════════════════════════ -->
<div class="sk-tab active" id="tab-skills">
    <div class="sk-sec">
        <span class="sk-sec-t">⚡ Tính năng</span>
    </div>
    <div class="sk-sec-sub">Bấm để gửi lệnh vào chat</div>
    <div class="sk-cards" style="margin-top:10px">
        <?php foreach ( $workflows as $w ) : ?>
        <a class="sk-card" href="#" data-msg="<?php echo esc_attr( $w['msg'] ); ?>">
            <div class="sk-card-icon"><?php echo $w['icon']; ?></div>
            <div class="sk-card-body">
                <div class="sk-card-label"><?php echo esc_html( $w['label'] ); ?></div>
                <div class="sk-card-desc"><?php echo esc_html( $w['desc'] ); ?></div>
                <div class="sk-card-tags">
                    <?php foreach ( $w['tags'] as $tag ) : ?>
                        <span class="sk-card-tag"><?php echo esc_html( $tag ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sk-card-arrow">›</div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Prompt gợi ý -->
    <div class="sk-sec" style="margin-top:24px">
        <span class="sk-sec-t">💬 Prompt gợi ý</span>
    </div>
    <?php foreach ( $prompts as $cat ) : ?>
    <div class="sk-prompt-cat">
        <div class="sk-prompt-cat-hd"><?php echo $cat['icon']; ?> <?php echo esc_html( $cat['cat'] ); ?></div>
        <div class="sk-prompt-list">
            <?php foreach ( $cat['prompts'] as $p ) : ?>
            <div class="sk-prompt-item" data-msg="<?php echo esc_attr( $p ); ?>">
                <span style="flex:1"><?php echo esc_html( $p ); ?></span>
                <span class="sk-prompt-send">▶</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 2: Danh mục
     ═══════════════════════════════════════════════════════════ -->
<div class="sk-tab" id="tab-categories">
    <div class="sk-sec">
        <span class="sk-sec-t">📂 Danh mục kỹ năng</span>
    </div>
    <div class="sk-sec-sub">Chọn danh mục để xem kỹ năng</div>

    <!-- Category grid (default view) -->
    <div id="sk-cat-grid" class="sk-cat-grid">
        <?php foreach ( $categories as $cat ) : ?>
        <div class="sk-cat-card" data-cat="<?php echo esc_attr( $cat['slug'] ); ?>">
            <div class="sk-cat-icon"><?php echo $cat['icon']; ?></div>
            <div class="sk-cat-label"><?php echo esc_html( $cat['label'] ); ?></div>
            <div class="sk-cat-desc"><?php echo esc_html( $cat['desc'] ); ?></div>
            <div class="sk-cat-count" data-cat-count="<?php echo esc_attr( $cat['slug'] ); ?>">—</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Category detail (shown after click) -->
    <div id="sk-cat-detail" style="display:none">
        <button class="sk-back" id="sk-cat-back">‹ Quay lại</button>
        <div class="sk-sec">
            <span class="sk-sec-t" id="sk-cat-detail-title"></span>
        </div>
        <div id="sk-cat-detail-list" class="sk-skill-list"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 3: Tìm kiếm
     ═══════════════════════════════════════════════════════════ -->
<div class="sk-tab" id="tab-search">
    <div class="sk-sec">
        <span class="sk-sec-t">🔍 Tìm kỹ năng</span>
    </div>
    <div class="sk-search-box" style="margin-top:10px">
        <input class="sk-search-input" id="sk-search-input" type="text" placeholder="Tìm theo tên, mô tả, category...">
        <button class="sk-search-btn" id="sk-search-btn">🔍 Tìm</button>
    </div>
    <div id="sk-search-results"></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 4: Cài đặt
     ═══════════════════════════════════════════════════════════ -->
<div class="sk-tab" id="tab-settings">
    <div class="sk-sec">
        <span class="sk-sec-t">⚙️ Cài đặt</span>
    </div>

    <div class="sk-settings-card">
        <h3>📊 Thống kê</h3>
        <div id="sk-stats">
            <p style="font-size:12px;color:#9ca3af;">Đang tải...</p>
        </div>
    </div>

    <?php if ( $is_admin ) : ?>
    <div class="sk-settings-card">
        <h3>🔧 Quản trị</h3>
        <p style="font-size:12px;color:#6b7280;margin-bottom:8px;">Quản lý skill library qua trang Admin.</p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-skills' ) ); ?>"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#10b981;color:#fff;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;">
            ⚙️ Mở Skills Admin
        </a>
    </div>
    <?php endif; ?>

    <?php echo do_shortcode( '[lsft_horizontal_flags]' ); ?>
</div>

<?php endif; /* is_logged_in */ ?>

<!-- ════ Bottom Navigation ════ -->
<?php if ( $is_logged_in ) : ?>
<nav class="sk-bottom-nav">
    <button class="sk-nav-item active" data-tab="tab-skills">
        <span class="sk-nav-icon">⚡</span>
        <span>Kỹ năng</span>
    </button>
    <button class="sk-nav-item" data-tab="tab-categories">
        <span class="sk-nav-icon">📂</span>
        <span>Danh mục</span>
    </button>
    <button class="sk-nav-item" data-tab="tab-search">
        <span class="sk-nav-icon">🔍</span>
        <span>Tìm kiếm</span>
    </button>
    <button class="sk-nav-item" data-tab="tab-settings">
        <span class="sk-nav-icon">⚙️</span>
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
    var allSkills = [];

    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
    }

    /* ── Send message to parent chat ── */
    function sendMsg(msg) {
        if (!msg) return;
        if (isIframe) {
            window.parent.postMessage({
                type: 'bizcity-chat-command',
                action: 'send_message',
                tool: '',
                text: msg
            }, '*');
        } else {
            window.location.href = <?php echo wp_json_encode( home_url( '/' ) ); ?> + '?bizcity_chat_msg=' + encodeURIComponent(msg);
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

    /* ── Tab switching ── */
    var navItems = document.querySelectorAll('.sk-nav-item');
    var tabs = document.querySelectorAll('.sk-tab');

    navItems.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            navItems.forEach(function(b){ b.classList.remove('active'); });
            tabs.forEach(function(t){ t.classList.remove('active'); });
            this.classList.add('active');
            var tabEl = document.getElementById(target);
            if (tabEl) tabEl.classList.add('active');
        });
    });

    /* ── Click handlers for cards & prompts ── */
    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            flash(this);
            sendMsg(this.getAttribute('data-msg'));
        });
    });

    /* ── Load skills catalog ── */
    function loadCatalog() {
        fetch(REST_URL + '/catalog', { headers: headers() })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            allSkills = data.skills || [];
            var total = data.total || 0;

            // Update hero count
            var countEl = document.getElementById('sk-total-count');
            if (countEl) countEl.textContent = total;

            // Update category counts
            var catCounts = {};
            allSkills.forEach(function(s) {
                var cat = s.category || 'uncategorized';
                catCounts[cat] = (catCounts[cat] || 0) + 1;
            });
            document.querySelectorAll('[data-cat-count]').forEach(function(el) {
                var slug = el.getAttribute('data-cat-count');
                el.textContent = (catCounts[slug] || 0) + ' kỹ năng';
            });

            // Load stats
            loadStats(allSkills, catCounts);
        })
        .catch(function() {
            var countEl = document.getElementById('sk-total-count');
            if (countEl) countEl.textContent = '—';
        });
    }

    loadCatalog();

    /* ── Category click → show skills ── */
    document.querySelectorAll('.sk-cat-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var cat = this.getAttribute('data-cat');
            showCategorySkills(cat, this.querySelector('.sk-cat-label').textContent);
        });
    });

    function showCategorySkills(catSlug, catLabel) {
        var grid = document.getElementById('sk-cat-grid');
        var detail = document.getElementById('sk-cat-detail');
        var titleEl = document.getElementById('sk-cat-detail-title');
        var listEl = document.getElementById('sk-cat-detail-list');

        grid.style.display = 'none';
        detail.style.display = '';
        titleEl.textContent = catLabel;

        var filtered = allSkills.filter(function(s) { return s.category === catSlug; });
        if (filtered.length === 0) {
            listEl.innerHTML = '<div class="sk-empty">Chưa có kỹ năng nào trong danh mục này.</div>';
            return;
        }

        var html = '';
        filtered.forEach(function(s) {
            html += '<div class="sk-skill-item" data-msg="Sử dụng skill ' + escHtml(s.title) + '">';
            html += '<div class="sk-skill-title">' + escHtml(s.title);
            if (s.description) html += '<span>' + escHtml(s.description.substring(0, 60)) + '</span>';
            html += '</div>';
            html += '<span class="sk-skill-arrow">›</span>';
            html += '</div>';
        });
        listEl.innerHTML = html;

        // Attach click handlers
        listEl.querySelectorAll('[data-msg]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                flash(this);
                sendMsg(this.getAttribute('data-msg'));
            });
        });
    }

    document.getElementById('sk-cat-back').addEventListener('click', function() {
        document.getElementById('sk-cat-grid').style.display = '';
        document.getElementById('sk-cat-detail').style.display = 'none';
    });

    /* ── Search ── */
    var searchInput = document.getElementById('sk-search-input');
    var searchBtn = document.getElementById('sk-search-btn');
    var searchResults = document.getElementById('sk-search-results');

    function doSearch() {
        var q = (searchInput.value || '').trim().toLowerCase();
        if (!q) { searchResults.innerHTML = ''; return; }

        var matched = allSkills.filter(function(s) {
            return (s.title || '').toLowerCase().indexOf(q) !== -1 ||
                   (s.description || '').toLowerCase().indexOf(q) !== -1 ||
                   (s.category || '').toLowerCase().indexOf(q) !== -1;
        });

        if (matched.length === 0) {
            searchResults.innerHTML = '<div class="sk-empty">Không tìm thấy kỹ năng phù hợp.</div>';
            return;
        }

        var html = '<div class="sk-skill-list">';
        matched.forEach(function(s) {
            html += '<div class="sk-skill-item" data-msg="Sử dụng skill ' + escHtml(s.title) + '">';
            html += '<div class="sk-skill-title">' + escHtml(s.title);
            if (s.description) html += '<span>' + escHtml(s.description.substring(0, 60)) + '</span>';
            html += '</div>';
            html += '<span class="sk-card-tag">' + escHtml(s.category) + '</span>';
            html += '<span class="sk-skill-arrow">›</span>';
            html += '</div>';
        });
        html += '</div>';
        searchResults.innerHTML = html;

        // Attach click handlers
        searchResults.querySelectorAll('[data-msg]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                flash(this);
                sendMsg(this.getAttribute('data-msg'));
            });
        });
    }

    if (searchBtn) searchBtn.addEventListener('click', doSearch);
    if (searchInput) searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') doSearch();
    });

    /* ── Stats ── */
    function loadStats(skills, catCounts) {
        var container = document.getElementById('sk-stats');
        if (!container) return;
        var total = skills.length;
        var cats = Object.keys(catCounts).length;

        container.innerHTML =
            '<div style="display:flex;gap:12px;flex-wrap:wrap;">' +
            '<div style="flex:1;min-width:80px;background:#ecfdf5;border-radius:8px;padding:10px;text-align:center;">' +
                '<div style="font-size:20px;font-weight:700;color:#10b981;">' + total + '</div>' +
                '<div style="font-size:10px;color:#6b7280;">Tổng kỹ năng</div>' +
            '</div>' +
            '<div style="flex:1;min-width:80px;background:#e0f2fe;border-radius:8px;padding:10px;text-align:center;">' +
                '<div style="font-size:20px;font-weight:700;color:#0284c7;">' + cats + '</div>' +
                '<div style="font-size:10px;color:#6b7280;">Danh mục</div>' +
            '</div>' +
            '</div>';
    }
})();
</script>
</body>
</html>
