<?php
/**
 * BizCity Tool Woo — Agent Profile & Guided Commands
 *
 * Displayed inside the Touch Bar iframe when user clicks the Tool Woo icon.
 * Renders: agent icon, description, and clickable guided command buttons
 * that send messages to the parent chat via postMessage.
 *
 * 9 workflows — mỗi workflow là 1 goal thật, có callback thực thi:
 *   create_product, edit_product, create_order, order_stats,
 *   product_stats, customer_stats, find_customer,
 *   inventory_report, warehouse_receipt
 *
 * @package BizCity_Tool_Woo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$icon_url = BZTOOL_WOO_URL . 'assets/icon.png';

/* ── Workflows — 9 goals thật có callback ── */
$workflows = [
    [
        'icon'  => '🛍️',
        'label' => 'Tạo sản phẩm',
        'desc'  => 'AI phân tích mô tả → tạo sản phẩm WooCommerce + giá + ảnh',
        'msg'   => 'Tạo sản phẩm áo thun cotton trắng giá 150k',
        'tags'  => [ 'AI parse', 'Ảnh SP', 'Auto create' ],
    ],
    [
        'icon'  => '✏️',
        'label' => 'Sửa sản phẩm',
        'desc'  => 'Sửa giá, tên, mô tả, danh mục sản phẩm bằng lệnh chat',
        'msg'   => 'Sửa giá sản phẩm áo thun trắng thành 200k',
        'tags'  => [ 'Edit', 'Giá', 'Mô tả' ],
    ],
    [
        'icon'  => '📦',
        'label' => 'Tạo đơn hàng',
        'desc'  => 'Tạo đơn hàng mới — AI tự phân tích khách hàng, SP, thanh toán',
        'msg'   => 'Tạo đơn hàng cho Nguyễn Văn A, 2 áo thun trắng, SĐT 0901234567',
        'tags'  => [ 'AI parse', 'POS', 'Thanh toán' ],
    ],
    [
        'icon'  => '📊',
        'label' => 'Thống kê doanh thu',
        'desc'  => 'Báo cáo tổng đơn, doanh thu, top sản phẩm theo khoảng thời gian',
        'msg'   => 'Thống kê doanh thu 7 ngày gần nhất',
        'tags'  => [ 'Doanh thu', 'Top SP', 'Chart' ],
    ],
    [
        'icon'  => '🏆',
        'label' => 'Top sản phẩm',
        'desc'  => 'Xem sản phẩm bán chạy nhất theo số lượng và doanh thu',
        'msg'   => 'Top sản phẩm bán chạy tuần này',
        'tags'  => [ 'Best seller', 'Ranking' ],
    ],
    [
        'icon'  => '👥',
        'label' => 'Top khách hàng',
        'desc'  => 'Thống kê khách hàng mua nhiều nhất, chi tiêu cao nhất',
        'msg'   => 'Top khách hàng tháng này',
        'tags'  => [ 'VIP', 'Doanh số KH' ],
    ],
    [
        'icon'  => '🔍',
        'label' => 'Tra cứu khách hàng',
        'desc'  => 'Tìm khách hàng theo SĐT, xem lịch sử đơn hàng',
        'msg'   => 'Tìm khách hàng 0901234567',
        'tags'  => [ 'SĐT', 'Lịch sử đơn' ],
    ],
    [
        'icon'  => '📋',
        'label' => 'Báo cáo kho',
        'desc'  => 'Xuất nhập tồn kho, sản phẩm tồn thấp, cần nhập thêm',
        'msg'   => 'Báo cáo tồn kho tháng này',
        'tags'  => [ 'XNT', 'Tồn kho' ],
    ],
    [
        'icon'  => '📥',
        'label' => 'Nhập kho',
        'desc'  => 'AI phân tích mô tả → tạo phiếu nhập kho tự động',
        'msg'   => 'Nhập kho 50 áo thun trắng giá mua 80k/cái',
        'tags'  => [ 'Phiếu nhập', 'AI parse' ],
    ],
];

/* ── Quick tips — gợi ý câu lệnh cho từng tình huống ── */
$tips = [
    [ 'icon' => '💡', 'text' => 'Tạo sản phẩm bánh mì chả lụa giá 25k' ],
    [ 'icon' => '💡', 'text' => 'Sửa giá SP #123 thành 300k, giảm còn 250k' ],
    [ 'icon' => '💡', 'text' => 'Tạo đơn hàng cho chị Lan, 3 bánh mì, SĐT 090xxx' ],
    [ 'icon' => '💡', 'text' => 'Thống kê doanh thu tuần này' ],
    [ 'icon' => '💡', 'text' => 'Tìm khách hàng 0987654321' ],
    [ 'icon' => '💡', 'text' => 'Nhập kho 100 hộp trà xanh giá mua 15k' ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BizCity Tool Woo – Agent</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f8fafc;
    color:#1f2937;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
}
.tc-profile{
    max-width:100%;
    margin:0 auto;
    padding:20px 16px 32px;
}

/* ── Hero Card ── */
.tc-hero{
    background:linear-gradient(135deg,#059669 0%,#10b981 50%,#34d399 100%);
    border-radius:20px;
    padding:28px 20px 20px;
    text-align:center;
    color:#fff;
    box-shadow:0 8px 30px rgba(5,150,105,.25);
    position:relative;
    overflow:hidden;
}
.tc-hero::before{
    content:'';position:absolute;top:-40%;right:-30%;
    width:200px;height:200px;
    background:rgba(255,255,255,.08);border-radius:50%;
}
.tc-hero-icon{
    width:72px;height:72px;border-radius:18px;
    border:3px solid rgba(255,255,255,.4);
    box-shadow:0 4px 16px rgba(0,0,0,.15);
    margin:0 auto 12px;display:block;
    object-fit:cover;background:#fff;
}
.tc-hero-name{font-size:20px;font-weight:700;margin-bottom:6px}
.tc-hero-desc{font-size:13px;opacity:.85;line-height:1.5;max-width:340px;margin:0 auto}
.tc-hero-stats{
    display:flex;justify-content:center;gap:20px;
    margin-top:14px;font-size:12px;opacity:.8;
}
.tc-hero-stats span{display:flex;align-items:center;gap:4px}

/* ── Section ── */
.tc-section{margin:22px 0 12px}
.tc-section-title{
    font-size:15px;font-weight:700;color:#1f2937;
    display:flex;align-items:center;gap:6px;
}
.tc-section-sub{font-size:12px;color:#9ca3af;margin-top:2px}

/* ── Command Cards ── */
.tc-cmds{display:flex;flex-direction:column;gap:10px}
.tc-cmd{
    display:flex;align-items:flex-start;gap:14px;
    background:#fff;border-radius:14px;padding:14px 16px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border:1px solid #e5e7eb;
    cursor:pointer;transition:all .2s ease;
    text-decoration:none;color:inherit;
    -webkit-tap-highlight-color:transparent;
}
.tc-cmd:hover{
    border-color:#a7f3d0;
    box-shadow:0 4px 16px rgba(16,185,129,.12);
    transform:translateY(-1px);
}
.tc-cmd:active{transform:scale(.98);box-shadow:0 1px 4px rgba(0,0,0,.08)}
.tc-cmd-icon{
    width:44px;height:44px;border-radius:12px;
    background:linear-gradient(135deg,#ecfdf5,#d1fae5);
    display:flex;align-items:center;justify-content:center;
    font-size:22px;flex-shrink:0;margin-top:2px;
}
.tc-cmd-body{flex:1;min-width:0}
.tc-cmd-label{font-size:14px;font-weight:600;color:#1f2937}
.tc-cmd-desc{font-size:12px;color:#6b7280;margin-top:2px;line-height:1.4}
.tc-cmd-tags{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap}
.tc-cmd-tag{
    font-size:10px;font-weight:500;
    padding:2px 7px;border-radius:6px;
    background:#ecfdf5;color:#059669;
    border:1px solid #a7f3d0;
}
.tc-cmd-arrow{color:#a7f3d0;font-size:18px;flex-shrink:0;transition:color .2s;margin-top:4px}
.tc-cmd:hover .tc-cmd-arrow{color:#059669}

/* ── Quick Tips ── */
.tc-tips{display:flex;flex-direction:column;gap:6px}
.tc-tip{
    display:flex;align-items:center;gap:10px;
    background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
    padding:10px 14px;cursor:pointer;transition:all .2s;
    font-size:13px;color:#92400e;
    -webkit-tap-highlight-color:transparent;
}
.tc-tip:hover{background:#fef3c7;border-color:#fbbf24}
.tc-tip:active{transform:scale(.98)}
.tc-tip-icon{font-size:16px;flex-shrink:0}
.tc-tip-text{flex:1;line-height:1.3}

/* ── Badge ── */
.tc-badge{
    display:inline-block;font-size:10px;font-weight:600;
    padding:2px 8px;border-radius:6px;
    background:#ecfdf5;color:#059669;
    margin-left:6px;vertical-align:middle;
}

/* ── Login ── */
.tc-login{text-align:center;padding:48px 20px}
.tc-login .icon{font-size:48px;margin-bottom:16px}
.tc-login h2{font-size:20px;color:#1f2937;margin-bottom:8px}
.tc-login p{color:#6b7280;margin-bottom:20px;font-size:14px}
.tc-login-btn{
    display:inline-block;padding:12px 32px;
    background:linear-gradient(135deg,#059669,#10b981);
    color:#fff;border-radius:12px;text-decoration:none;
    font-weight:600;font-size:15px;transition:opacity .2s;
}
.tc-login-btn:hover{opacity:.9}

/* ── Footer ── */
.tc-footer{
    text-align:center;margin-top:24px;padding-top:16px;
    border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;
}
</style>
</head>
<body>

<div class="tc-profile">

    <!-- Hero Card -->
    <div class="tc-hero">
        <img src="<?php echo esc_url( $icon_url ); ?>" alt="Tool Woo" class="tc-hero-icon"
             onerror="this.style.display='none'">
        <div class="tc-hero-name">Tool WooCommerce</div>
        <div class="tc-hero-desc">
            Quản lý cửa hàng WooCommerce qua chat AI.<br>
            Tạo SP → Đơn hàng → Thống kê → Kho hàng — tất cả tự động.
        </div>
        <div class="tc-hero-stats">
            <span>⚡ <?php echo count( $workflows ); ?> quy trình</span>
            <span>🛒 WooCommerce</span>
            <span>🤖 AI-powered</span>
        </div>
    </div>

    <?php if ( ! is_user_logged_in() ): ?>
    <div class="tc-login">
        <div class="icon">🔐</div>
        <h2>Đăng nhập để bắt đầu</h2>
        <p>Đăng nhập để sử dụng bộ công cụ AI quản lý cửa hàng.</p>
        <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-woo/?bizcity_iframe=1' ) ) ); ?>" class="tc-login-btn">Đăng nhập</a>
    </div>
    <?php endif; ?>

    <!-- ══ Workflows ══ -->
    <div class="tc-section">
        <div class="tc-section-title">⚡ Chạm để bắt đầu <span class="tc-badge"><?php echo count( $workflows ); ?> workflows</span></div>
        <div class="tc-section-sub">AI thực hiện trọn vẹn từ A → Z, bạn chỉ cần nhấn</div>
    </div>

    <div class="tc-cmds">
        <?php foreach ( $workflows as $cmd ): ?>
        <div class="tc-cmd" data-msg="<?php echo esc_attr( $cmd['msg'] ); ?>">
            <div class="tc-cmd-icon"><?php echo $cmd['icon']; ?></div>
            <div class="tc-cmd-body">
                <div class="tc-cmd-label"><?php echo esc_html( $cmd['label'] ); ?></div>
                <div class="tc-cmd-desc"><?php echo esc_html( $cmd['desc'] ); ?></div>
                <?php if ( ! empty( $cmd['tags'] ) ): ?>
                <div class="tc-cmd-tags">
                    <?php foreach ( $cmd['tags'] as $tag ): ?>
                    <span class="tc-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="tc-cmd-arrow">→</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ══ Quick Tips ══ -->
    <div class="tc-section">
        <div class="tc-section-title">💬 Thử nói thế này</div>
        <div class="tc-section-sub">Gợi ý câu lệnh — chạm để gửi trực tiếp</div>
    </div>

    <div class="tc-tips">
        <?php foreach ( $tips as $tip ): ?>
        <div class="tc-tip" data-msg="<?php echo esc_attr( $tip['text'] ); ?>">
            <span class="tc-tip-icon"><?php echo $tip['icon']; ?></span>
            <span class="tc-tip-text"><?php echo esc_html( $tip['text'] ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Footer -->
    <div class="tc-footer">
        Tool WooCommerce v<?php echo esc_html( BZTOOL_WOO_VERSION ); ?>
        · <?php echo count( $workflows ); ?> workflows · AI-powered
    </div>

</div><!-- /.tc-profile -->

<script>
(function() {
    'use strict';

    /* ── Send command to parent chat via postMessage ── */
    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var msg = this.getAttribute('data-msg');
            if (!msg) return;

            // Visual feedback
            this.style.transform = 'scale(0.96)';
            this.style.opacity = '0.7';
            var self = this;
            setTimeout(function() {
                self.style.transform = '';
                self.style.opacity = '';
            }, 200);

            // Send to parent window (dashboard)
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type:   'bizcity_agent_command',
                    source: 'bizcity-tool-woo',
                    text:   msg
                }, '*');
            }
        });
    });
})();
</script>

</body>
</html>
