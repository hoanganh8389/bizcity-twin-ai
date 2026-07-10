<?php
/**
 * BizCity CRM — Order Public Tracking Template (Phase 0.38 W3.4).
 *
 * Loaded by BizCity_CRM_Order_Public_Controller::handle_request().
 * Available variables: $order (WC_Order), $token (string).
 *
 * This is a self-contained PHP template — no React SPA (Q4 locked).
 * Minimal inline CSS + vanilla JS for CSAT form submission.
 *
 * @since PHASE-0.38.W3.4 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.38.W3.4 — public tracking page template

defined( 'ABSPATH' ) || exit;

/* Ensure $order is available (controller passes it via include scope). */
if ( ! isset( $order ) || ! $order instanceof WC_Abstract_Order ) {
	wp_die( 'Đơn hàng không hợp lệ.', '', array( 'response' => 404 ) );
}
if ( ! isset( $token ) ) {
	$token = '';
}

$order_number    = $order->get_order_number();
$status          = $order->get_status();
$date            = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '';
$tracking_number = (string) $order->get_meta( '_tracking_number',   true );
$provider        = (string) $order->get_meta( '_tracking_provider', true );
$store_name      = get_bloginfo( 'name' );
$store_url       = home_url();

$status_labels = array(
	'pending'    => array( 'label' => 'Chờ thanh toán', 'color' => '#f59e0b', 'icon' => '⏳' ),
	'processing' => array( 'label' => 'Đang xử lý',     'color' => '#3b82f6', 'icon' => '⚙️' ),
	'on-hold'    => array( 'label' => 'Tạm giữ',         'color' => '#6b7280', 'icon' => '⏸️' ),
	'completed'  => array( 'label' => 'Hoàn thành',      'color' => '#16a34a', 'icon' => '✅' ),
	'cancelled'  => array( 'label' => 'Đã hủy',          'color' => '#ef4444', 'icon' => '❌' ),
	'refunded'   => array( 'label' => 'Đã hoàn tiền',    'color' => '#8b5cf6', 'icon' => '↩️' ),
	'failed'     => array( 'label' => 'Thất bại',         'color' => '#ef4444', 'icon' => '💔' ),
);
$status_info  = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : array( 'label' => ucfirst( $status ), 'color' => '#6b7280', 'icon' => '📦' );
$rest_root    = esc_url( rest_url( 'bizcity-channel/v1' ) );

// Check if customer already submitted CSAT.
$has_csat = false;
global $wpdb;
$csat_tbl = $wpdb->prefix . 'bizcity_crm_order_csat';
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$csat_tbl}'" ) === $csat_tbl ) {
	$has_csat = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM `{$csat_tbl}` WHERE order_id = %d",
		(int) $order->get_id()
	) ) > 0;
}

// Payment URL (if order needs payment).
$payment_url = $order->needs_payment() ? $order->get_checkout_payment_url() : '';

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Theo dõi đơn hàng #<?php echo esc_html( $order_number ); ?> — <?php echo esc_html( $store_name ); ?></title>
<meta name="robots" content="noindex,nofollow">
<?php wp_head(); ?>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;margin:0;padding:16px;color:#1f2937}
.biz-track{max-width:600px;margin:0 auto}
.biz-card{background:#fff;border-radius:12px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.biz-header{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.biz-store{font-size:13px;color:#6b7280}
.biz-order-num{font-size:22px;font-weight:700}
.biz-status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:99px;font-size:13px;font-weight:600;color:#fff;margin-top:8px}
.biz-meta{font-size:13px;color:#6b7280;margin-top:8px}
.biz-section-title{font-size:13px;font-weight:600;color:#374151;margin:0 0 10px}
.biz-item{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:14px}
.biz-item:last-child{border-bottom:none}
.biz-tracking-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px}
.biz-tracking-num{font-size:20px;font-weight:700;letter-spacing:1px;color:#15803d}
.biz-provider{font-size:12px;color:#6b7280;margin-top:4px}
.biz-payment-btn{display:block;background:#2563eb;color:#fff;text-align:center;padding:14px;border-radius:8px;font-size:16px;font-weight:600;text-decoration:none}
.biz-payment-btn:hover{background:#1d4ed8}
.biz-stars{display:flex;gap:8px;margin:12px 0;font-size:32px;cursor:pointer}
.biz-star{transition:.1s}
.biz-star:hover,.biz-star.active{filter:none;opacity:1}
.biz-star{opacity:.3}
.biz-csat-comment{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin:8px 0;resize:vertical}
.biz-csat-btn{background:#16a34a;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;width:100%}
.biz-csat-btn:disabled{opacity:.5;cursor:not-allowed}
.biz-msg{padding:12px;border-radius:8px;font-size:14px;margin-top:8px}
.biz-msg.ok{background:#f0fdf4;color:#15803d}
.biz-msg.err{background:#fef2f2;color:#b91c1c}
</style>
</head>
<body>
<div class="biz-track">

	<!-- Order header -->
	<div class="biz-card">
		<div class="biz-store"><a href="<?php echo esc_url( $store_url ); ?>"><?php echo esc_html( $store_name ); ?></a></div>
		<div class="biz-order-num">Đơn hàng #<?php echo esc_html( $order_number ); ?></div>
		<span class="biz-status-badge" style="background:<?php echo esc_attr( $status_info['color'] ); ?>">
			<?php echo esc_html( $status_info['icon'] . ' ' . $status_info['label'] ); ?>
		</span>
		<?php if ( $date ) { ?>
		<div class="biz-meta">Đặt lúc <?php echo esc_html( $date ); ?></div>
		<?php } ?>
	</div>

	<!-- Items -->
	<div class="biz-card">
		<div class="biz-section-title">Sản phẩm</div>
		<?php foreach ( $order->get_items() as $item ) { ?>
		<div class="biz-item">
			<span><?php echo esc_html( $item->get_name() ); ?></span>
			<span>×<?php echo (int) $item->get_quantity(); ?></span>
		</div>
		<?php } ?>
	</div>

	<!-- Tracking number (if available) -->
	<?php if ( $tracking_number !== '' ) { ?>
	<div class="biz-card">
		<div class="biz-section-title">Thông tin vận chuyển</div>
		<div class="biz-tracking-box">
			<div style="font-size:12px;color:#6b7280;margin-bottom:4px">Mã vận đơn</div>
			<div class="biz-tracking-num"><?php echo esc_html( $tracking_number ); ?></div>
			<?php if ( $provider !== '' ) { ?>
			<div class="biz-provider"><?php echo esc_html( $provider ); ?></div>
			<?php } ?>
		</div>
	</div>
	<?php } ?>

	<!-- Payment button (if pending) -->
	<?php if ( $payment_url !== '' ) { ?>
	<div class="biz-card">
		<div class="biz-section-title">Thanh toán</div>
		<a href="<?php echo esc_url( $payment_url ); ?>" class="biz-payment-btn">💳 Thanh toán ngay</a>
	</div>
	<?php } ?>

	<!-- CSAT -->
	<?php if ( $status === 'completed' && ! $has_csat ) { ?>
	<div class="biz-card" id="biz-csat-card">
		<div class="biz-section-title">⭐ Đánh giá trải nghiệm mua hàng</div>
		<p style="font-size:14px;color:#6b7280;margin:0 0 4px">Cảm nhận của bạn về đơn hàng này?</p>
		<div class="biz-stars" id="biz-stars">
			<span class="biz-star" data-val="1">⭐</span>
			<span class="biz-star" data-val="2">⭐</span>
			<span class="biz-star" data-val="3">⭐</span>
			<span class="biz-star" data-val="4">⭐</span>
			<span class="biz-star" data-val="5">⭐</span>
		</div>
		<textarea class="biz-csat-comment" id="biz-csat-comment" rows="3" placeholder="Góp ý thêm (không bắt buộc)..."></textarea>
		<button class="biz-csat-btn" id="biz-csat-btn" disabled>Gửi đánh giá</button>
		<div id="biz-csat-msg" style="display:none"></div>
	</div>
	<?php } elseif ( $has_csat ) { ?>
	<div class="biz-card">
		<p style="color:#16a34a;font-size:14px;margin:0">✅ Bạn đã gửi đánh giá cho đơn hàng này. Cảm ơn!</p>
	</div>
	<?php } ?>

</div><!-- .biz-track -->
<?php wp_footer(); ?>
<script>
(function(){
	var stars = document.querySelectorAll('.biz-star');
	var btn   = document.getElementById('biz-csat-btn');
	var msg   = document.getElementById('biz-csat-msg');
	var chosen = 0;
	if (!stars.length) return;
	stars.forEach(function(s){
		s.addEventListener('click', function(){
			chosen = parseInt(s.getAttribute('data-val'), 10);
			stars.forEach(function(x){ x.classList.toggle('active', parseInt(x.getAttribute('data-val'),10) <= chosen); });
			if (btn) btn.disabled = false;
		});
	});
	if (btn) btn.addEventListener('click', function(){
		if (!chosen) return;
		btn.disabled = true;
		var comment = document.getElementById('biz-csat-comment') ? document.getElementById('biz-csat-comment').value : '';
		fetch('<?php echo esc_js( $rest_root ); ?>/order-tracking/<?php echo esc_js( $token ); ?>/csat', {
			method: 'POST',
			headers: {'Content-Type':'application/json'},
			body: JSON.stringify({rating: chosen, comment: comment})
		}).then(function(r){ return r.json(); }).then(function(d){
			if (msg) {
				msg.style.display = 'block';
				msg.className = 'biz-msg ' + (d.ok ? 'ok' : 'err');
				msg.textContent = d.message || (d.ok ? 'Cảm ơn bạn!' : 'Có lỗi xảy ra.');
			}
			if (d.ok && btn) btn.style.display = 'none';
		}).catch(function(){
			if (msg) { msg.style.display='block'; msg.className='biz-msg err'; msg.textContent='Kết nối thất bại, vui lòng thử lại.'; }
			if (btn) btn.disabled = false;
		});
	});
})();
</script>
</body>
</html>
