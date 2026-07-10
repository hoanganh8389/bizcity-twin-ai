<?php
/**
 * Bizcity Twin AI — Membership_Emails
 *
 * [2026-07-17 Johnny Chu] PHASE-D G-1 — Email notifications for membership
 * lifecycle events: payment receipt, plan activation, expiry warning,
 * plan expired, refund confirmation, and admin new-payment alert.
 *
 * All emails use wp_mail() with HTML body. Templates are inline strings —
 * no external template files needed. Subject / body are translatable.
 *
 * Hooks called by:
 *   - BizCity_Membership_PayPal_Gateway::fulfill_order()  → action 'bizcity_membership_payment_completed'
 *   - BizCity_Membership_Cron::run_sweep()                → action 'bizcity_membership_plan_expired'
 *   - BizCity_Membership_Cron::send_expiry_warnings()     → action 'bizcity_membership_expiry_warning'
 *   - BizCity_Membership_REST::me_cancel()                → action 'bizcity_membership_plan_cancelled'
 *   - BizCity_Membership_Payments::mark_refunded()        → action 'bizcity_membership_payment_refunded'
 *
 * Options used:
 *   bizcity_membership_email_from_name   — "From" name (default: site name)
 *   bizcity_membership_email_from_email  — "From" email (default: admin email)
 *   bizcity_membership_email_notify_admin — 1|0 send admin alert on new payment (default: 1)
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-07-17
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Emails {

	/** @var BizCity_Membership_Emails|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire all hooks. Called from bootstrap.php once.
	 *
	 * @return void
	 */
	public static function init() {
		$self = self::instance();
		add_action( 'bizcity_membership_payment_completed', array( $self, 'on_payment_completed' ), 10, 2 );
		add_action( 'bizcity_membership_plan_expired',      array( $self, 'on_plan_expired' ),      10, 1 );
		add_action( 'bizcity_membership_expiry_warning',    array( $self, 'on_expiry_warning' ),    10, 2 );
		add_action( 'bizcity_membership_plan_cancelled',    array( $self, 'on_plan_cancelled' ),    10, 1 );
		add_action( 'bizcity_membership_payment_refunded',  array( $self, 'on_payment_refunded' ),  10, 1 );
	}

	/* ── Hook handlers ──────────────────────────────────────────────────── */

	/**
	 * Payment completed: send receipt to member + admin alert.
	 *
	 * @param int   $user_id
	 * @param array $payment  Row from bizcity_member_payments.
	 * @return void
	 */
	public function on_payment_completed( $user_id, $payment ) {
		$this->send_payment_receipt( (int) $user_id, (array) $payment );
		if ( (int) get_option( 'bizcity_membership_email_notify_admin', 1 ) ) {
			$this->send_admin_new_payment( (int) $user_id, (array) $payment );
		}
	}

	/**
	 * Plan expired: notify member.
	 *
	 * @param int $user_id
	 * @return void
	 */
	public function on_plan_expired( $user_id ) {
		$this->send_plan_expired( (int) $user_id );
	}

	/**
	 * Expiry warning: N days before expiry.
	 *
	 * @param int $user_id
	 * @param int $days_left
	 * @return void
	 */
	public function on_expiry_warning( $user_id, $days_left ) {
		$this->send_expiry_warning( (int) $user_id, (int) $days_left );
	}

	/**
	 * Plan cancelled by member.
	 *
	 * @param int $user_id
	 * @return void
	 */
	public function on_plan_cancelled( $user_id ) {
		$this->send_plan_cancelled( (int) $user_id );
	}

	/**
	 * Payment refunded.
	 *
	 * @param array $payment  Row from bizcity_member_payments.
	 * @return void
	 */
	public function on_payment_refunded( $payment ) {
		$uid = isset( $payment['user_id'] ) ? (int) $payment['user_id'] : 0;
		if ( $uid > 0 ) {
			$this->send_refund_confirmation( $uid, (array) $payment );
		}
	}

	/* ── Email senders ──────────────────────────────────────────────────── */

	/**
	 * Receipt email after successful payment.
	 *
	 * @param int   $user_id
	 * @param array $payment
	 * @return bool
	 */
	public function send_payment_receipt( $user_id, $payment ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$plan_label  = $this->plan_label( isset( $payment['plan_slug'] ) ? (string) $payment['plan_slug'] : '' );
		$amount      = isset( $payment['amount'] )       ? number_format( (float) $payment['amount'], 2 ) : '0.00';
		$currency    = isset( $payment['currency'] )     ? strtoupper( (string) $payment['currency'] ) : 'USD';
		$txn_id      = isset( $payment['transaction_id'] ) ? (string) $payment['transaction_id'] : '';
		$paid_at     = isset( $payment['paid_at'] )      ? (string) $payment['paid_at'] : gmdate( 'Y-m-d H:i:s' );
		$paid_at_vn  = $this->format_datetime_vn( $paid_at );

		$subject = sprintf( '[%s] Xác nhận thanh toán — %s', get_bloginfo( 'name' ), $plan_label );

		$body = $this->wrap_html(
			sprintf( 'Xin chào <strong>%s</strong>,', esc_html( $user->display_name ) ),
			'<p>Cảm ơn bạn đã đăng ký gói dịch vụ. Dưới đây là chi tiết giao dịch:</p>' .
			$this->table_rows( array(
				'Gói dịch vụ'  => $plan_label,
				'Số tiền'       => $amount . ' ' . $currency,
				'Mã giao dịch'  => '<code>' . esc_html( $txn_id ) . '</code>',
				'Thời gian'     => $paid_at_vn,
				'Trạng thái'    => '<span style="color:#16a34a;font-weight:600">✅ Thành công</span>',
			) ) .
			'<p>Tài khoản của bạn đã được kích hoạt. Bạn có thể đăng nhập và sử dụng ngay.</p>' .
			$this->btn( home_url(), 'Vào tài khoản ngay' )
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/**
	 * Admin alert when a new payment arrives.
	 *
	 * @param int   $user_id
	 * @param array $payment
	 * @return bool
	 */
	public function send_admin_new_payment( $user_id, $payment ) {
		$user       = get_userdata( (int) $user_id );
		$name       = $user ? $user->display_name : "(uid $user_id)";
		$email      = $user ? $user->user_email    : '';
		$plan_label = $this->plan_label( isset( $payment['plan_slug'] ) ? (string) $payment['plan_slug'] : '' );
		$amount     = isset( $payment['amount'] ) ? number_format( (float) $payment['amount'], 2 ) : '0.00';
		$currency   = isset( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : 'USD';
		$txn_id     = isset( $payment['transaction_id'] ) ? (string) $payment['transaction_id'] : '';

		$subject = sprintf( '[%s] 💰 Thanh toán mới — %s · %s %s', get_bloginfo( 'name' ), $plan_label, $amount, $currency );

		$admin_url  = admin_url( 'admin.php?page=bizcity-membership&tab=payments' );
		$body = $this->wrap_html(
			'Thông báo thanh toán mới',
			'<p>Một thành viên vừa hoàn tất thanh toán:</p>' .
			$this->table_rows( array(
				'Tên'          => esc_html( $name ),
				'Email'        => esc_html( $email ),
				'Gói'          => $plan_label,
				'Số tiền'      => $amount . ' ' . $currency,
				'Mã giao dịch' => '<code>' . esc_html( $txn_id ) . '</code>',
			) ) .
			$this->btn( $admin_url, 'Xem trong admin' )
		);

		return $this->send( get_option( 'admin_email' ), $subject, $body );
	}

	/**
	 * Expiry warning email (N days before plan expires).
	 *
	 * @param int $user_id
	 * @param int $days_left
	 * @return bool
	 */
	public function send_expiry_warning( $user_id, $days_left ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$mc         = class_exists( 'BizCity_User_Meta_Cache' );
		$plan_slug  = $mc ? (string) BizCity_User_Meta_Cache::get( $user_id, 'bizcity_member_plan', '' )      : (string) get_user_meta( $user_id, 'bizcity_member_plan', true );
		$plan_label = $this->plan_label( $plan_slug );
		$until      = $mc ? (string) BizCity_User_Meta_Cache::get( $user_id, 'bizcity_member_valid_until', '' ) : (string) get_user_meta( $user_id, 'bizcity_member_valid_until', true );
		$until_vn   = $until ? $this->format_date_vn( $until ) : '—';

		$subject = sprintf( '[%s] Gói %s sắp hết hạn sau %d ngày', get_bloginfo( 'name' ), $plan_label, (int) $days_left );

		$body = $this->wrap_html(
			sprintf( 'Xin chào <strong>%s</strong>,', esc_html( $user->display_name ) ),
			sprintf(
				'<p>Gói dịch vụ <strong>%s</strong> của bạn sẽ <strong>hết hạn sau %d ngày</strong> (vào ngày %s).</p>',
				esc_html( $plan_label ),
				(int) $days_left,
				esc_html( $until_vn )
			) .
			'<p>Để tiếp tục sử dụng không gián đoạn, vui lòng gia hạn trước ngày hết hạn.</p>' .
			$this->btn( home_url( '/#/pricing' ), 'Gia hạn ngay' )
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/**
	 * Plan expired — downgraded to free.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function send_plan_expired( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$subject = sprintf( '[%s] Gói của bạn đã hết hạn', get_bloginfo( 'name' ) );

		$body = $this->wrap_html(
			sprintf( 'Xin chào <strong>%s</strong>,', esc_html( $user->display_name ) ),
			'<p>Gói dịch vụ của bạn đã hết hạn và tài khoản đã được chuyển về gói miễn phí.</p>' .
			'<p>Bạn vẫn có thể truy cập các tính năng cơ bản. Để khôi phục gói cao cấp, hãy đăng ký lại.</p>' .
			$this->btn( home_url( '/#/pricing' ), 'Đăng ký gói mới' )
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/**
	 * Plan cancelled by member request.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public function send_plan_cancelled( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$subject = sprintf( '[%s] Xác nhận huỷ gói dịch vụ', get_bloginfo( 'name' ) );

		$body = $this->wrap_html(
			sprintf( 'Xin chào <strong>%s</strong>,', esc_html( $user->display_name ) ),
			'<p>Chúng tôi đã xác nhận yêu cầu huỷ gói dịch vụ của bạn.</p>' .
			'<p>Tài khoản sẽ vẫn ở trạng thái hoạt động cho đến cuối chu kỳ thanh toán hiện tại, sau đó tự động chuyển về gói miễn phí.</p>' .
			'<p>Nếu đây là nhầm lẫn, hãy liên hệ với chúng tôi ngay.</p>' .
			$this->btn( home_url( '/#/pricing' ), 'Đăng ký lại' )
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/**
	 * Refund confirmation to member.
	 *
	 * @param int   $user_id
	 * @param array $payment
	 * @return bool
	 */
	public function send_refund_confirmation( $user_id, $payment ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}

		$plan_label = $this->plan_label( isset( $payment['plan_slug'] ) ? (string) $payment['plan_slug'] : '' );
		$amount     = isset( $payment['amount'] ) ? number_format( (float) $payment['amount'], 2 ) : '0.00';
		$currency   = isset( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : 'USD';
		$txn_id     = isset( $payment['transaction_id'] ) ? (string) $payment['transaction_id'] : '';

		$subject = sprintf( '[%s] Xác nhận hoàn tiền — %s', get_bloginfo( 'name' ), $plan_label );

		$body = $this->wrap_html(
			sprintf( 'Xin chào <strong>%s</strong>,', esc_html( $user->display_name ) ),
			'<p>Yêu cầu hoàn tiền của bạn đã được xử lý thành công:</p>' .
			$this->table_rows( array(
				'Gói'          => $plan_label,
				'Số tiền hoàn' => $amount . ' ' . $currency,
				'Mã giao dịch' => '<code>' . esc_html( $txn_id ) . '</code>',
				'Trạng thái'   => '<span style="color:#2563eb;font-weight:600">Đã hoàn tiền</span>',
			) ) .
			'<p>Tiền sẽ được hoàn về tài khoản PayPal của bạn trong vòng 3–5 ngày làm việc.</p>'
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/* ── HTML invoice / receipt ─────────────────────────────────────────── */

	/**
	 * Generate a printable HTML invoice for a single payment.
	 *
	 * @param int   $user_id
	 * @param array $payment  Row from bizcity_member_payments.
	 * @return string  Full HTML document ready to open in browser / print.
	 */
	public function render_invoice_html( $user_id, $payment ) {
		$user       = get_userdata( (int) $user_id );
		$name       = $user ? $user->display_name : 'Khách';
		$email      = $user ? $user->user_email   : '';
		$plan_label = $this->plan_label( isset( $payment['plan_slug'] ) ? (string) $payment['plan_slug'] : '' );
		$amount     = isset( $payment['amount'] )          ? number_format( (float) $payment['amount'], 2 ) : '0.00';
		$currency   = isset( $payment['currency'] )        ? strtoupper( (string) $payment['currency'] ) : 'USD';
		$txn_id     = isset( $payment['transaction_id'] )  ? (string) $payment['transaction_id'] : '';
		$paid_at    = isset( $payment['paid_at'] )         ? $this->format_datetime_vn( (string) $payment['paid_at'] ) : '—';
		$status     = isset( $payment['status'] )          ? ucfirst( (string) $payment['status'] ) : 'completed';
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();

		ob_start();
		?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hóa đơn — <?php echo esc_html( $txn_id ); ?></title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 32px; color: #1f2937; background: #fff; }
  .invoice-box { max-width: 640px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; padding: 40px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 2px solid #e5e7eb; padding-bottom: 24px; }
  .logo { font-size: 1.5rem; font-weight: 800; color: #6d28d9; }
  .invoice-title { font-size: 1rem; color: #6b7280; text-align: right; }
  .invoice-title .txn { font-size: 0.75rem; font-family: monospace; color: #374151; margin-top: 4px; word-break: break-all; }
  table { width: 100%; border-collapse: collapse; margin: 24px 0; }
  th { text-align: left; padding: 10px 12px; background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
  td { padding: 10px 12px; font-size: 0.875rem; border-bottom: 1px solid #f3f4f6; }
  .amount-row td { font-size: 1.25rem; font-weight: 700; color: #111827; }
  .status-ok { color: #16a34a; font-weight: 600; }
  .footer { margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 24px; font-size: 0.75rem; color: #9ca3af; text-align: center; }
  @media print { body { padding: 0; } .invoice-box { border: none; box-shadow: none; } }
</style>
</head>
<body>
<div class="invoice-box">
  <div class="header">
    <div>
      <div class="logo"><?php echo esc_html( $site_name ); ?></div>
      <div style="font-size:0.8rem;color:#6b7280;margin-top:4px"><a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_url( $site_url ); ?></a></div>
    </div>
    <div class="invoice-title">
      <div><strong>HÓA ĐƠN THANH TOÁN</strong></div>
      <div class="txn"><?php echo esc_html( $txn_id ); ?></div>
      <div style="margin-top:6px;font-size:0.75rem;color:#6b7280"><?php echo esc_html( $paid_at ); ?></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Thông tin</th>
        <th>Chi tiết</th>
      </tr>
    </thead>
    <tbody>
      <tr><td>Khách hàng</td><td><strong><?php echo esc_html( $name ); ?></strong></td></tr>
      <tr><td>Email</td><td><?php echo esc_html( $email ); ?></td></tr>
      <tr><td>Gói dịch vụ</td><td><?php echo esc_html( $plan_label ); ?></td></tr>
      <tr><td>Trạng thái</td><td><span class="status-ok"><?php echo esc_html( $status ); ?></span></td></tr>
      <tr class="amount-row"><td>Số tiền</td><td><?php echo esc_html( $amount . ' ' . $currency ); ?></td></tr>
    </tbody>
  </table>

  <div class="footer">
    Cảm ơn bạn đã tin tưởng và sử dụng dịch vụ của <?php echo esc_html( $site_name ); ?>.<br>
    Hóa đơn này được tạo tự động và có giá trị như xác nhận giao dịch.
  </div>
</div>
<script>if(window.location.hash==='#print'){window.print();}</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/* ── Internal helpers ───────────────────────────────────────────────── */

	/**
	 * Send an email via wp_mail() with HTML content-type.
	 *
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @return bool
	 */
	private function send( $to, $subject, $body ) {
		$from_name  = (string) get_option( 'bizcity_membership_email_from_name',  get_bloginfo( 'name' ) );
		$from_email = (string) get_option( 'bizcity_membership_email_from_email', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Wrap body fragment in a minimal responsive email shell.
	 *
	 * @param string $headline
	 * @param string $content
	 * @return string
	 */
	private function wrap_html( $headline, $content ) {
		$site = get_bloginfo( 'name' );
		return '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px">'
			. '<tr><td align="center">'
			. '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;width:100%">'
			. '<tr><td style="background:#6d28d9;padding:24px 32px">'
			. '<span style="color:#fff;font-size:1.25rem;font-weight:800">' . esc_html( $site ) . '</span>'
			. '</td></tr>'
			. '<tr><td style="padding:32px">'
			. '<p style="font-size:1rem;font-weight:600;color:#111827;margin:0 0 16px">' . $headline . '</p>'
			. $content
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px;background:#f9fafb;color:#9ca3af;font-size:0.75rem;border-top:1px solid #e5e7eb">'
			. esc_html( $site ) . ' · <a href="' . esc_url( home_url() ) . '" style="color:#6d28d9">' . esc_url( home_url() ) . '</a>'
			. '</td></tr>'
			. '</table></td></tr></table>'
			. '</body></html>';
	}

	/**
	 * Build a 2-column table of key-value rows.
	 *
	 * @param array $rows  key => html_value
	 * @return string
	 */
	private function table_rows( $rows ) {
		$html = '<table style="width:100%;border-collapse:collapse;margin:16px 0">'
			. '<thead><tr>'
			. '<th style="text-align:left;padding:8px 12px;background:#f9fafb;font-size:0.75rem;color:#6b7280;border-bottom:1px solid #e5e7eb">Nội dung</th>'
			. '<th style="text-align:left;padding:8px 12px;background:#f9fafb;font-size:0.75rem;color:#6b7280;border-bottom:1px solid #e5e7eb">Chi tiết</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $k => $v ) {
			$html .= '<tr>'
				. '<td style="padding:8px 12px;font-size:0.875rem;color:#6b7280;border-bottom:1px solid #f3f4f6">' . esc_html( $k ) . '</td>'
				. '<td style="padding:8px 12px;font-size:0.875rem;color:#111827;border-bottom:1px solid #f3f4f6">' . $v . '</td>'
				. '</tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * CTA button HTML.
	 *
	 * @param string $url
	 * @param string $label
	 * @return string
	 */
	private function btn( $url, $label ) {
		return '<p style="margin:24px 0 0">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#6d28d9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:0.875rem;font-weight:600">'
			. esc_html( $label )
			. '</a></p>';
	}

	/**
	 * Resolve plan slug → human label.
	 *
	 * @param string $slug
	 * @return string
	 */
	private function plan_label( $slug ) {
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$plan = BizCity_Membership_Plan_Registry::instance()->get( $slug );
			if ( $plan && isset( $plan['label'] ) ) {
				return (string) $plan['label'];
			}
		}
		return $slug !== '' ? ucfirst( $slug ) : 'Không xác định';
	}

	/**
	 * Format a datetime string to Vietnamese readable format.
	 *
	 * @param string $dt  Y-m-d H:i:s or Y-m-d
	 * @return string
	 */
	private function format_datetime_vn( $dt ) {
		$ts = strtotime( $dt );
		if ( ! $ts ) {
			return $dt;
		}
		return gmdate( 'H:i · d/m/Y', $ts );
	}

	/**
	 * Format a date string to Vietnamese readable format.
	 *
	 * @param string $d  Y-m-d
	 * @return string
	 */
	private function format_date_vn( $d ) {
		$ts = strtotime( $d );
		if ( ! $ts ) {
			return $d;
		}
		return gmdate( 'd/m/Y', $ts );
	}
}
