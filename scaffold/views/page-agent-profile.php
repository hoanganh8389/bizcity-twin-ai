<?php
/**
 * Profile View — BizCity Tool Sample
 *
 * PILLAR 1: Trang /{slug}/ hiện trong Touch Bar iframe.
 * Hero + Guided Commands + Quick Tips.
 * Click → postMessage() gửi prompt vào parent chat.
 *
 * @see PLUGIN-STANDARD.md §3 — Trụ cột 1: Profile View
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Plugin Info ── */
$plugin_name = 'BizCity Tool — Sample';

/* ── Stats (query từ DB nếu cần) ── */
$stat_created = 0;  // TODO: count từ DB
$stat_edited  = 0;  // TODO: count từ DB
$stat_total   = 0;  // TODO: count từ DB

/* ══════════════════════════════════════════════════════════════
 *  GUIDED COMMANDS — Primary đứng đầu, Secondary tiếp theo
 * ══════════════════════════════════════════════════════════════ */
$commands = [
    /* ── PRIMARY TOOL — nổi bật nhất, đứng đầu ── */
    [
        'icon'    => '🔧',
        'label'   => 'Tạo Sample',
        'desc'    => 'AI phân tích mô tả → xử lý → tạo sample mới',
        'tool'    => '{slug}_start_reading',
        'msg'     => 'Tạo sample mô tả sản phẩm organic',
        'tags'    => [ 'AI tạo', 'Auto' ],
        'primary' => true,
    ],
    /* ── SECONDARY TOOLS ── */
    [
        'icon'  => '✏️',
        'label' => 'Sửa Sample',
        'desc'  => 'Cập nhật / chỉnh sửa sample đã có',
        'tool'  => '{slug}_send_link',
        'msg'   => 'Sửa sample #123 cho chuyên nghiệp hơn',
        'tags'  => [ 'Edit', 'Cập nhật' ],
    ],
];

/* ── QUICK TIPS ── */
$tips = [
    [ 'icon' => '💡', 'tool' => '{slug}_start_reading', 'msg' => 'Tạo sample giới thiệu công ty', 'text' => '"Tạo sample giới thiệu công ty"' ],
    [ 'icon' => '💡', 'tool' => '{slug}_send_link',     'msg' => 'Sửa sample mới nhất',           'text' => '"Sửa sample mới nhất"' ],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?php echo esc_html( $plugin_name ); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f8fafc; -webkit-text-size-adjust: 100%; }

        /* ── Container ── */
        .tc-profile { max-width: 100%; padding: 20px 16px 32px; font-family: system-ui, -apple-system, sans-serif; }

        /* ── Hero Card ── */
        .tc-hero {
            background: linear-gradient(135deg, var(--hero-from, #4f46e5) 0%, var(--hero-via, #7c3aed) 50%, var(--hero-to, #a78bfa) 100%);
            border-radius: 20px; padding: 28px 20px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,.15);
            text-align: center; color: #fff;
            position: relative; overflow: hidden;
        }
        .tc-hero::before {
            content: ''; position: absolute; width: 200px; height: 200px;
            background: rgba(255,255,255,.08); border-radius: 50%;
            top: -60px; right: -40px;
        }
        .tc-hero-icon { width: 72px; height: 72px; border-radius: 18px; margin-bottom: 12px; }
        .tc-hero-name { font-size: 20px; font-weight: 700; margin: 0 0 6px; }
        .tc-hero-desc { font-size: 13px; opacity: .85; margin-bottom: 16px; }
        .tc-hero-stats { display: flex; justify-content: center; gap: 24px; }
        .tc-stat { text-align: center; }
        .tc-stat-val { display: block; font-size: 22px; font-weight: 700; }
        .tc-stat-lbl { font-size: 11px; opacity: .8; }

        /* ── Section heading ── */
        .tc-section { font-size: 15px; font-weight: 600; margin: 24px 0 12px; color: #374151; }

        /* ── Command Cards ── */
        .tc-cmd {
            display: flex; align-items: flex-start; gap: 14px;
            background: #fff; border-radius: 14px; padding: 14px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            border: 1px solid #e5e7eb;
            cursor: pointer; transition: all .2s ease;
            margin-bottom: 10px;
        }
        .tc-cmd:hover { border-color: #c7d2fe; box-shadow: 0 4px 16px rgba(99,102,241,.12); transform: translateY(-1px); }
        .tc-cmd:active { transform: scale(.97); }
        .tc-cmd-primary { border-color: #c7d2fe; background: linear-gradient(135deg, #fefefe, #f5f3ff); }
        .tc-cmd-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .tc-cmd-body { flex: 1; min-width: 0; }
        .tc-cmd-label { font-size: 14px; font-weight: 600; color: #1f2937; margin-bottom: 2px; }
        .tc-cmd-desc { font-size: 12px; color: #6b7280; line-height: 1.4; margin-bottom: 6px; }
        .tc-cmd-tags { display: flex; flex-wrap: wrap; gap: 4px; }
        .tc-cmd-tag {
            font-size: 10px; font-weight: 500;
            padding: 2px 7px; border-radius: 6px;
            background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;
        }

        /* ── Quick Tips ── */
        .tc-tip {
            display: flex; align-items: center; gap: 10px;
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 10px; padding: 10px 14px;
            cursor: pointer; font-size: 13px; color: #92400e;
            margin-bottom: 8px; transition: all .2s;
        }
        .tc-tip:hover { background: #fef3c7; }
    </style>
</head>
<body>
<div class="tc-profile">

    <!-- ══ HERO ══ -->
    <div class="tc-hero">
        <img class="tc-hero-icon"
             src="<?php echo esc_url( BZTOOL_SAMPLE_URL . 'assets/icon.png' ); ?>"
             width="72" height="72" alt="">
        <h1 class="tc-hero-name"><?php echo esc_html( $plugin_name ); ?></h1>
        <p class="tc-hero-desc">Mô tả ngắn plugin — AI xử lý tự động</p>
        <div class="tc-hero-stats">
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_created; ?></span>
                <span class="tc-stat-lbl">Đã tạo</span>
            </div>
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_edited; ?></span>
                <span class="tc-stat-lbl">Đã sửa</span>
            </div>
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_total; ?></span>
                <span class="tc-stat-lbl">Tổng cộng</span>
            </div>
        </div>
    </div>

    <!-- ══ GUIDED COMMANDS ══ -->
    <h2 class="tc-section">🚀 Bắt đầu</h2>

    <?php foreach ( $commands as $cmd ) : ?>
    <div class="tc-cmd<?php echo ! empty( $cmd['primary'] ) ? ' tc-cmd-primary' : ''; ?>"
            data-msg="<?php echo esc_attr( $cmd['msg'] ); ?>"
            data-tool="<?php echo esc_attr( $cmd['tool'] ); ?>">
        <div class="tc-cmd-icon"><?php echo $cmd['icon']; ?></div>
        <div class="tc-cmd-body">
            <div class="tc-cmd-label"><?php echo esc_html( $cmd['label'] ); ?></div>
            <div class="tc-cmd-desc"><?php echo esc_html( $cmd['desc'] ); ?></div>
            <div class="tc-cmd-tags">
                <?php foreach ( $cmd['tags'] as $tag ) : ?>
                <span class="tc-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ══ QUICK TIPS ══ -->
    <h2 class="tc-section">💡 Mẹo</h2>
    <?php foreach ( $tips as $tip ) : ?>
    <div class="tc-tip" data-msg="<?php echo esc_attr( $tip['msg'] ); ?>" data-tool="<?php echo esc_attr( $tip['tool'] ); ?>">
        <span class="tc-tip-icon"><?php echo $tip['icon']; ?></span>
        <span class="tc-tip-text"><?php echo esc_html( $tip['text'] ); ?></span>
    </div>
    <?php endforeach; ?>

</div>

<!-- ══ POSTMESSAGE TO PARENT ══ -->
<script>
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var msg = this.getAttribute('data-msg');
        var toolName = this.getAttribute('data-tool') || '';
        var slashMsg = (msg || '').trim();
        if (slashMsg && toolName && slashMsg.indexOf('/') !== 0) {
            slashMsg = '/' + toolName + ' ' + slashMsg;
        }

        /* Visual feedback */
        this.style.transform = 'scale(0.96)';
        this.style.opacity = '0.7';
        var self = this;
        setTimeout(function() { self.style.transform = ''; self.style.opacity = ''; }, 300);

        /* Send to parent (Dashboard / Webchat iframe) */
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type:   'bizcity_agent_command',
                source: 'bizcity-tool-sample',
                plugin_slug: 'bizcity-tool-sample',
                tool_name: toolName,
                text:   slashMsg || msg
            }, '*');
        }
    });
});
</script>
</body>
</html>
