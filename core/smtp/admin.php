<?php
/**
 * BizCity SMTP — Admin Settings Page
 *
 * Provides a standalone admin page (`?page=bizcity-smtp-settings`) so
 * SMTP credentials can be managed via UI instead of `wp-config.php`
 * constants. Values are written to option `bizcity_smtp_settings`,
 * which `BizCity_SMTP::resolve_config()` reads on every request.
 *
 * If a `BIZCITY_SMTP_*` constant is defined in `wp-config.php` it
 * still wins — the corresponding form field is rendered read-only
 * and tagged `(constant override)` so admins know what is in effect.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\SMTP\Admin
 * @since      1.3.9 (2026-05-12)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_SMTP_Admin' ) ) {

	final class BizCity_SMTP_Admin {

		const OPTION_KEY = 'bizcity_smtp_settings';
		const PAGE_SLUG  = 'bizcity-smtp-settings';
		const NONCE_KEY   = 'bizcity_smtp_save';
		const NONCE_TEST  = 'bizcity_smtp_test';
		// [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — advanced manual check action constant.
		const NONCE_CHECK = 'bizcity_smtp_check';

		public static function register(): void {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
			add_action( 'admin_post_bizcity_smtp_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_bizcity_smtp_test',  array( __CLASS__, 'handle_test' ) );
			// [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — advanced manual check handler.
			add_action( 'admin_post_bizcity_smtp_check', array( __CLASS__, 'handle_check' ) );
		}

		public static function add_menu(): void {
			// Phase G (2026-05-19) — moved from top-level menu to submenu of
			// Đào tạo kết nối (bizchat-gateway). Slug preserved.
			// [2026-06-10 Johnny Chu] R-STAMP — renamed SMTP → Twin SMTP for Twin prefix convention.
			add_submenu_page(
				'bizchat-gateway',
				__( 'Twin SMTP — Cấu hình email', 'bizcity-twin-ai' ),
				__( '✉️ Twin SMTP', 'bizcity-twin-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/** @return array<string,mixed> */
		private static function get_options(): array {
			$opt = get_option( self::OPTION_KEY, array() );
			return is_array( $opt ) ? $opt : array();
		}

		/**
		 * Resolve effective value for a field (constant > option > default).
		 *
		 * @return array{value:mixed,locked:bool}
		 */
		private static function effective( string $key, $default = '' ): array {
			$const = 'BIZCITY_SMTP_' . strtoupper( $key );
			if ( defined( $const ) ) {
				return array( 'value' => constant( $const ), 'locked' => true );
			}
			$opt = self::get_options();
			return array( 'value' => $opt[ $key ] ?? $default, 'locked' => false );
		}

		public static function handle_save(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Bạn không có quyền.', 'bizcity-twin-ai' ) );
			}
			check_admin_referer( self::NONCE_KEY );

			$old = self::get_options();
			$new = array(
				'host'      => isset( $_POST['host'] )      ? sanitize_text_field( wp_unslash( $_POST['host'] ) )      : '',
				'port'      => isset( $_POST['port'] )      ? (int) $_POST['port']                                     : 587,
				'user'      => isset( $_POST['user'] )      ? sanitize_text_field( wp_unslash( $_POST['user'] ) )      : '',
				'from'      => isset( $_POST['from'] )      ? sanitize_email( wp_unslash( $_POST['from'] ) )           : '',
				'from_name' => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
				'secure'    => isset( $_POST['secure'] )    ? sanitize_text_field( wp_unslash( $_POST['secure'] ) )    : 'tls',
				'auth'      => ! empty( $_POST['auth'] ) ? 1 : 0,
			);
			// Password: keep existing if blank submitted (don't wipe).
			$pass_in = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : '';
			$new['pass'] = $pass_in !== '' ? $pass_in : (string) ( $old['pass'] ?? '' );

			update_option( self::OPTION_KEY, $new, false );

			$redirect = add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'updated' => 1 ),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		public static function handle_test(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Bạn không có quyền.', 'bizcity-twin-ai' ) );
			}
			check_admin_referer( self::NONCE_TEST );

			$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
			if ( ! $to ) {
				$to = wp_get_current_user()->user_email;
			}

			$cfg = method_exists( 'BizCity_SMTP', 'resolve_config' ) ? BizCity_SMTP::resolve_config() : null;
			if ( $cfg === null ) {
				$redirect = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tested' => 'unconfigured' ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			$err  = '';
			$ok   = false;
			$catch = static function ( \WP_Error $e ) use ( &$err ) { $err = $e->get_error_message(); };
			add_action( 'wp_mail_failed', $catch );
			$ok = wp_mail(
				$to,
				'[BizCity SMTP] Test email — ' . current_time( 'Y-m-d H:i:s' ),
				"Đây là email test gửi từ BizCity SMTP.\n\nHost: {$cfg['host']}\nPort: {$cfg['port']}\nFrom: {$cfg['from']} <{$cfg['from_name']}>\n",
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
			remove_action( 'wp_mail_failed', $catch );

			$args = array( 'page' => self::PAGE_SLUG, 'tested' => $ok ? 'ok' : 'fail', 'to' => rawurlencode( $to ) );
			if ( $err ) {
				$args['err'] = rawurlencode( substr( $err, 0, 240 ) );
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		// [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — advanced manual check: presets + order/user/pw-reset simulations.
		public static function handle_check(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Bạn không có quyền.', 'bizcity-twin-ai' ) );
			}
			check_admin_referer( self::NONCE_CHECK );

			$to   = isset( $_POST['check_to'] )   ? sanitize_email( wp_unslash( $_POST['check_to'] ) )  : '';
			$type = isset( $_POST['check_type'] ) ? sanitize_key( wp_unslash( $_POST['check_type'] ) )  : 'simple';
			if ( ! $to ) {
				$to = wp_get_current_user()->user_email;
			}

			switch ( $type ) {
				case 'woo_order':
					$result = self::_check_woo_order( $to );
					break;
				case 'wp_new_user':
					$result = self::_check_wp_new_user( $to );
					break;
				case 'wp_pw_reset':
					$result = self::_check_wp_pw_reset( $to );
					break;
				case 'html_rich':
					$result = self::_check_html_rich( $to );
					break;
				default:
					$result = self::_check_simple( $to );
			}

			$args = array(
				'page'       => self::PAGE_SLUG,
				'checked'    => $result['ok'] ? 'ok' : 'fail',
				'check_type' => $type,
				'to'         => rawurlencode( $to ),
			);
			if ( ! empty( $result['error'] ) ) {
				$args['err'] = rawurlencode( substr( $result['error'], 0, 240 ) );
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		/** Internal: send a check email and return {ok, error}. */
		private static function _send_check_mail( string $to, string $subject, string $body, string $content_type = 'text/plain' ): array {
			$err = '';
			$cb  = static function ( $e ) use ( &$err ) {
				if ( $e instanceof WP_Error ) {
					$err = $e->get_error_message();
				}
			};
			add_action( 'wp_mail_failed', $cb );
			$ok = wp_mail(
				$to,
				$subject,
				$body,
				array( 'Content-Type: ' . $content_type . '; charset=UTF-8' )
			);
			remove_action( 'wp_mail_failed', $cb );
			return array( 'ok' => (bool) $ok, 'error' => $err );
		}

		private static function _check_simple( string $to ): array {
			$cfg  = method_exists( 'BizCity_SMTP', 'resolve_config' ) ? BizCity_SMTP::resolve_config() : null;
			$info = $cfg ? sprintf( "Host: %s\nPort: %d\nFrom: %s", $cfg['host'], $cfg['port'], $cfg['from'] ) : '(chưa cấu hình)';
			return self::_send_check_mail(
				$to,
				'[BizCity Twin SMTP] Test đơn giản — ' . current_time( 'Y-m-d H:i:s' ),
				"Email test văn bản đơn giản.\n\n" . $info . "\n\nSite: " . home_url()
			);
		}

		private static function _check_html_rich( string $to ): array {
			return self::_send_check_mail(
				$to,
				'[TEST] ' . get_bloginfo( 'name' ) . ' — HTML Template',
				self::_build_notification_html(
					get_bloginfo( 'name' ),
					'&#x1F3A8; Email HTML Template',
					'<p>&#x0110;&#xE2;y l&#xE0; email HTML m&#x1EAB;u &#x111;&#x1EC3; ki&#x1EC3;m tra SMTP rendering.</p>'
					. '<p>N&#x1EBF;u b&#x1EA1;n &#x111;&#x1ECD;c &#x111;&#x01B0;&#x1EE3;c email n&#xE0;y v&#x1EDB;i &#x111;&#x1EA7;y &#x111;&#x1EE7; &#x111;&#x1ECB;nh d&#x1EA1;ng m&#xE0;u s&#x1EAF;c, c&#x1EA5;u h&#xEC;nh SMTP &#x111;ang ho&#x1EA1;t &#x111;&#x1ED9;ng ch&#xED;nh x&#xE1;c.</p>',
					array(
						array( 'label' => 'Lo&#x1EA1;i test', 'value' => 'HTML Rich Template' ),
						array( 'label' => 'G&#x1EED;i l&#xFA;c',   'value' => current_time( 'Y-m-d H:i:s' ) ),
						array( 'label' => 'Site URL',         'value' => home_url() ),
					)
				),
				'text/html'
			);
		}

		private static function _check_woo_order( string $to ): array {
			$order_label = 'TEST-001';
			$customer    = wp_get_current_user()->display_name ? wp_get_current_user()->display_name : 'Kh&#xE1;ch h&#xE0;ng';
			$total       = '350.000 &#x20AB;';
			$status      = '&#x110;ang x&#x1EED; l&#xFD;';
			$payment     = 'Thanh to&#xE1;n khi nh&#x1EAD;n h&#xE0;ng (COD)';
			$address     = 'S&#x1ED1; 1 &#x110;&#x01B0;&#x1EDD;ng ABC, Qu&#x1EAD;n 1, TP.HCM';
			$items_html  = '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee;">S&#x1EA3;n ph&#x1EA9;m m&#x1EAB;u A &times; 2</td><td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">200.000 &#x20AB;</td></tr>'
				           . '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee;">D&#x1ECB;ch v&#x1EE5; m&#x1EAB;u B &times; 1</td><td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">150.000 &#x20AB;</td></tr>';

			if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
				if ( ! empty( $orders ) && is_a( $orders[0], 'WC_Order' ) ) {
					$woo_order   = $orders[0];
					$order_label = '#' . $woo_order->get_order_number();
					$fn          = $woo_order->get_billing_first_name();
					$ln          = $woo_order->get_billing_last_name();
					$customer    = trim( $fn . ' ' . $ln ) ? trim( $fn . ' ' . $ln ) : $customer;
					$total       = $woo_order->get_formatted_order_total();
					$status      = wc_get_order_status_name( $woo_order->get_status() );
					$payment     = $woo_order->get_payment_method_title();
					$address     = $woo_order->get_formatted_billing_address();
					$items_html  = '';
					foreach ( $woo_order->get_items() as $item ) {
						$items_html .= '<tr>'
							. '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . esc_html( $item->get_name() ) . ' &times; ' . (int) $item->get_quantity() . '</td>'
							. '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">' . wc_price( $item->get_total() ) . '</td>'
							. '</tr>';
					}
				}
			}

			$order_table = '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
				. '<thead><tr>'
				. '<th style="padding:8px 10px;background:#f5f5f5;text-align:left;font-size:13px;">S&#x1EA3;n ph&#x1EA9;m</th>'
				. '<th style="padding:8px 10px;background:#f5f5f5;text-align:right;font-size:13px;">Th&#xE0;nh ti&#x1EC1;n</th>'
				. '</tr></thead>'
				. '<tbody>' . $items_html . '</tbody>'
				. '<tfoot><tr>'
				. '<td style="padding:10px;font-weight:bold;">T&#x1ED5;ng c&#x1ED9;ng</td>'
				. '<td style="padding:10px;text-align:right;font-weight:bold;">' . esc_html( is_string( $total ) ? $total : '&#x2014;' ) . '</td>'
				. '</tr></tfoot></table>';

			$body = self::_build_notification_html(
				get_bloginfo( 'name' ),
				'&#x2705; &#x110;&#x01A1;n h&#xE0;ng ' . esc_html( $order_label ) . ' &#x111;&#xE3; &#x111;&#x01B0;&#x1EE3;c &#x111;&#x1EB7;t th&#xE0;nh c&#xF4;ng!',
				'<p>Xin ch&#xE0;o <strong>' . esc_html( $customer ) . '</strong>,</p>'
				. '<p>Ch&#xFA;ng t&#xF4;i &#x111;&#xE3; nh&#x1EAD;n &#x111;&#x01B0;&#x1EE3;c &#x111;&#x01A1;n h&#xE0;ng c&#x1EE7;a b&#x1EA1;n v&#xE0; &#x111;ang ti&#x1EBF;n h&#xE0;nh x&#x1EED; l&#xFD;.</p>'
				. $order_table,
				array(
					array( 'label' => 'M&#xE3; &#x111;&#x01A1;n h&#xE0;ng',   'value' => $order_label ),
					array( 'label' => 'Tr&#x1EA1;ng th&#xE1;i',              'value' => $status ),
					array( 'label' => 'Ph&#x01B0;&#x01A1;ng th&#x1EE9;c TT', 'value' => $payment ),
					array( 'label' => '&#x110;&#x1ECB;a ch&#x1EC9; giao h&#xE0;ng', 'value' => $address ),
				)
			);
			return self::_send_check_mail(
				$to,
				'[TEST] ' . get_bloginfo( 'name' ) . ' &#x2014; X&#xE1;c nh&#x1EAD;n &#x111;&#x01A1;n h&#xE0;ng ' . esc_html( $order_label ),
				$body,
				'text/html'
			);
		}

		private static function _check_wp_new_user( string $to ): array {
			$site = get_bloginfo( 'name' );
			$body = self::_build_notification_html(
				$site,
				'&#x1F464; T&#xE0;i kho&#x1EA3;n m&#x1EDB;i &#x111;&#xE3; &#x111;&#x01B0;&#x1EE3;c t&#x1EA1;o',
				'<p>Xin ch&#xE0;o,</p><p>T&#xE0;i kho&#x1EA3;n c&#x1EE7;a b&#x1EA1;n tr&#xEA;n <strong>' . esc_html( $site ) . '</strong> &#x111;&#xE3; &#x111;&#x01B0;&#x1EE3;c t&#x1EA1;o th&#xE0;nh c&#xF4;ng.</p>'
				. '<p style="color:#999;font-size:12px;">[&#x110;&#xE2;y l&#xE0; email ki&#x1EC3;m tra &#x2014; kh&#xF4;ng ph&#x1EA3;i t&#xE0;i kho&#x1EA3;n th&#x1EF1;c]</p>',
				array(
					array( 'label' => 'T&#xEA;n &#x111;&#x103;ng nh&#x1EAD;p', 'value' => 'testuser_' . current_time( 'His' ) ),
					array( 'label' => 'Email',                                   'value' => $to ),
					array( 'label' => 'Site',                                    'value' => home_url() ),
					array( 'label' => 'Th&#x1EDD;i gian',                       'value' => current_time( 'Y-m-d H:i:s' ) ),
				)
			);
			return self::_send_check_mail(
				$to,
				'[TEST] ' . $site . ' &#x2014; T&#xE0;i kho&#x1EA3;n m&#x1EDB;i &#x111;&#xE3; &#x111;&#x01B0;&#x1EE3;c t&#x1EA1;o',
				$body,
				'text/html'
			);
		}

		private static function _check_wp_pw_reset( string $to ): array {
			$site = get_bloginfo( 'name' );
			$body = self::_build_notification_html(
				$site,
				'&#x1F511; Y&#xEA;u c&#x1EA7;u &#x111;&#x1EB7;t l&#x1EA1;i m&#x1EAD;t kh&#x1EA9;u',
				'<p>Xin ch&#xE0;o,</p><p>Ch&#xFA;ng t&#xF4;i nh&#x1EAD;n &#x111;&#x01B0;&#x1EE3;c y&#xEA;u c&#x1EA7;u &#x111;&#x1EB7;t l&#x1EA1;i m&#x1EAD;t kh&#x1EA9;u cho t&#xE0;i kho&#x1EA3;n li&#xEA;n k&#x1EBF;t v&#x1EDB;i email n&#xE0;y.</p>'
				. '<p style="text-align:center;margin:24px 0;"><a href="#" style="display:inline-block;background:#2271b1;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-weight:bold;">&#x110;&#x1EB7;t l&#x1EA1;i m&#x1EAD;t kh&#x1EA9;u</a></p>'
				. '<p style="color:#999;font-size:12px;">[&#x110;&#xE2;y l&#xE0; email ki&#x1EC3;m tra &#x2014; link kh&#xF4;ng ho&#x1EA1;t &#x111;&#x1ED9;ng]</p>',
				array(
					array( 'label' => 'Email y&#xEA;u c&#x1EA7;u', 'value' => $to ),
					array( 'label' => 'Hi&#x1EC7;u l&#x1EF1;c',    'value' => '24 gi&#x1EDD; (gi&#x1EA3; l&#x1EAD;p)' ),
					array( 'label' => 'Th&#x1EDD;i gian',          'value' => current_time( 'Y-m-d H:i:s' ) ),
				)
			);
			return self::_send_check_mail(
				$to,
				'[TEST] ' . $site . ' &#x2014; &#x110;&#x1EB7;t l&#x1EA1;i m&#x1EAD;t kh&#x1EA9;u',
				$body,
				'text/html'
			);
		}

		/**
		 * Build a standard notification HTML email template.
		 *
		 * @param string $site_name  Site name for header.
		 * @param string $title      Email headline (may contain basic HTML entities).
		 * @param string $body_html  Main body HTML.
		 * @param array  $meta_rows  Key-value rows table at bottom.
		 * @return string            Full HTML email.
		 */
		private static function _build_notification_html( string $site_name, string $title, string $body_html, array $meta_rows = array() ): string {
			$meta_table = '';
			if ( ! empty( $meta_rows ) ) {
				$meta_table = '<table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:13px;">';
				foreach ( $meta_rows as $row ) {
					$meta_table .= '<tr>'
						. '<td style="padding:6px 10px;background:#f5f5f5;font-weight:600;width:38%;vertical-align:top;">' . esc_html( $row['label'] ) . '</td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . wp_kses_post( $row['value'] ) . '</td>'
						. '</tr>';
				}
				$meta_table .= '</table>';
			}
			return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
				. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:Arial,Helvetica,sans-serif;">'
				. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:40px 20px;">'
				. '<tr><td align="center">'
				. '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.12);">'
				. '<tr><td style="background:#2271b1;padding:20px 30px;">'
				. '<p style="margin:0;color:#fff;font-size:20px;font-weight:bold;letter-spacing:.3px;">' . esc_html( $site_name ) . '</p>'
				. '</td></tr>'
				. '<tr><td style="padding:28px 30px;">'
				. '<h2 style="margin:0 0 16px;font-size:22px;color:#1d2327;line-height:1.3;">' . wp_kses( $title, array( 'strong' => array(), 'em' => array() ) ) . '</h2>'
				. $body_html
				. $meta_table
				. '<p style="margin:28px 0 0;padding-top:16px;border-top:1px solid #f0f0f1;font-size:11px;color:#aaa;">Email g&#x1EED;i b&#x1EDF;i <strong>' . esc_html( $site_name ) . '</strong> qua Twin SMTP. &#x110;&#xE2;y l&#xE0; email ki&#x1EC3;m tra.</p>'
				. '</td></tr>'
				. '</table>'
				. '</td></tr>'
				. '</table>'
				. '</body></html>';
		}

		public static function render_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) { return; }

			$host      = self::effective( 'host', '' );
			$port      = self::effective( 'port', 587 );
			$user      = self::effective( 'user', '' );
			$pass      = self::effective( 'pass', '' );
			$from      = self::effective( 'from', '' );
			$from_name = self::effective( 'from_name', get_bloginfo( 'name' ) );
			$secure    = self::effective( 'secure', 'tls' );
			$auth      = self::effective( 'auth', true );

			$cfg_active = method_exists( 'BizCity_SMTP', 'resolve_config' ) ? BizCity_SMTP::resolve_config() : null;

			?>
			<div class="wrap">
				<h1><span class="dashicons dashicons-email-alt" style="font-size:30px;width:30px;height:30px;"></span>
					<?php esc_html_e( 'Twin SMTP — Cấu hình email gửi đi', 'bizcity-twin-ai' ); ?></h1>

				<?php self::render_notices(); ?>

				<div class="notice notice-info inline" style="padding:14px 16px;margin-bottom:20px;">
					<h3 style="margin:0 0 10px;font-size:14px;">📌 <?php esc_html_e( 'Hướng dẫn lấy Gmail SMTP (App Password)', 'bizcity-twin-ai' ); ?></h3>
					<ol style="margin:0 0 8px;padding-left:20px;line-height:1.8;">
						<li><?php esc_html_e( 'Đăng nhập tài khoản Gmail muốn dùng để gửi mail.', 'bizcity-twin-ai' ); ?></li>
						<li><?php
							echo wp_kses(
								__( 'Vào <strong>Tài khoản Google</strong> → <strong>Bảo mật</strong> → bật <strong>Xác minh 2 bước</strong> (bắt buộc phải bật trước).', 'bizcity-twin-ai' ),
								array( 'strong' => array() )
							);
						?></li>
						<li><?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link */
									__( 'Truy cập <a href="%s" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a> → chọn app <strong>"Mail"</strong>, thiết bị <strong>"Máy tính khác"</strong> → nhấn <strong>Tạo</strong>.', 'bizcity-twin-ai' ),
									'https://myaccount.google.com/apppasswords'
								),
								array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array() )
							);
						?></li>
						<li><?php esc_html_e( 'Google hiển thị một chuỗi 16 ký tự (VD: abcd efgh ijkl mnop) — chép toàn bộ, bỏ dấu cách, dán vào ô "Password / App password" bên dưới.', 'bizcity-twin-ai' ); ?></li>
						<li><?php
							echo wp_kses(
								__( 'Điền form bên dưới với: <strong>Host</strong> = <code>smtp.gmail.com</code>, <strong>Port</strong> = <code>587</code>, <strong>Mã hoá</strong> = TLS, <strong>Username & From email</strong> = địa chỉ Gmail của bạn.', 'bizcity-twin-ai' ),
								array( 'strong' => array(), 'code' => array() )
							);
						?></li>
					</ol>
					<p style="margin:6px 0 0;font-size:12px;color:#666;">
						💡 <?php
							echo wp_kses(
								__( '<strong>Lưu ý:</strong> App Password chỉ hiển thị <u>một lần duy nhất</u>. Nếu quên, hãy xoá và tạo lại mật khẩu ứng dụng mới.', 'bizcity-twin-ai' ),
								array( 'strong' => array(), 'u' => array() )
							);
						?>
					</p>
				</div>

				<?php // [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — Quick presets for common SMTP providers. ?>
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:3px;padding:14px 18px;margin:20px 0;">
					<h3 style="margin:0 0 8px;font-size:14px;color:#1d2327;">&#x26A1; <?php esc_html_e( 'C&#x1EA5;u h&#xEC;nh nhanh (Quick Presets)', 'bizcity-twin-ai' ); ?></h3>
					<p style="margin:0 0 10px;font-size:13px;color:#646970;"><?php esc_html_e( 'Nh&#x1EA5;n &#x111;&#x1EC3; t&#x1EF1; &#x111;&#x1ED9;ng &#x111;i&#x1EC1;n Host / Port / M&#xE3; ho&#xE1; theo nh&#xE0; cung c&#x1EA5;p:', 'bizcity-twin-ai' ); ?></p>
					<div id="bzs-presets" style="display:flex;flex-wrap:wrap;gap:8px;">
						<button type="button" class="button" data-preset="gmail">&#x1F4E7; Gmail</button>
						<button type="button" class="button" data-preset="outlook">&#x1FA9F; Outlook / Hotmail</button>
						<button type="button" class="button" data-preset="office365">&#x1F3E2; Office 365</button>
						<button type="button" class="button" data-preset="yahoo">Y! Yahoo Mail</button>
						<button type="button" class="button" data-preset="brevo">Brevo</button>
						<button type="button" class="button" data-preset="mailgun">Mailgun</button>
						<button type="button" class="button" data-preset="zoho">Zoho Mail</button>
						<button type="button" class="button" data-preset="ionos">IONOS</button>
						<button type="button" class="button" data-preset="sparkpost">SparkPost</button>
						<button type="button" class="button" data-preset="sendgrid">SendGrid</button>
					</div>
				</div>

				<?php // [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — Quick presets for common SMTP providers. ?>
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:3px;padding:14px 18px;margin:20px 0;">
					<h3 style="margin:0 0 8px;font-size:14px;color:#1d2327;">&#x26A1; <?php esc_html_e( 'Cấu hình nhanh (Quick Presets)', 'bizcity-twin-ai' ); ?></h3>
					<p style="margin:0 0 10px;font-size:13px;color:#646970;"><?php esc_html_e( 'Nhấn để tự động điền Host / Port / Mã hoá theo nhà cung cấp:', 'bizcity-twin-ai' ); ?></p>
					<div id="bzs-presets" style="display:flex;flex-wrap:wrap;gap:8px;">
						<button type="button" class="button" data-preset="gmail">&#x1F4E7; Gmail</button>
						<button type="button" class="button" data-preset="outlook">&#x1FA9F; Outlook / Hotmail</button>
						<button type="button" class="button" data-preset="office365">&#x1F3E2; Office 365</button>
						<button type="button" class="button" data-preset="yahoo">Y! Yahoo Mail</button>
						<button type="button" class="button" data-preset="brevo">Brevo</button>
						<button type="button" class="button" data-preset="mailgun">Mailgun</button>
						<button type="button" class="button" data-preset="zoho">Zoho Mail</button>
						<button type="button" class="button" data-preset="ionos">IONOS</button>
						<button type="button" class="button" data-preset="sparkpost">SparkPost</button>
						<button type="button" class="button" data-preset="sendgrid">SendGrid</button>
					</div>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
					<input type="hidden" name="action" value="bizcity_smtp_save" />
					<?php wp_nonce_field( self::NONCE_KEY ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="bzs-host"><?php esc_html_e( 'SMTP Host', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php self::field_input( 'host', 'bzs-host', 'text', $host, 'smtp.gmail.com', 'regular-text' ); ?>
									<p class="description"><?php esc_html_e( 'VD: smtp.gmail.com, smtp.sendgrid.net, smtp.mailgun.org…', 'bizcity-twin-ai' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-port"><?php esc_html_e( 'SMTP Port', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php self::field_input( 'port', 'bzs-port', 'number', $port, '587', 'small-text' ); ?>
									<p class="description"><?php esc_html_e( '587 (TLS) · 465 (SSL) · 25 (none, không khuyến nghị).', 'bizcity-twin-ai' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-secure"><?php esc_html_e( 'Mã hoá', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php
									$sec = (string) $secure['value'];
									if ( $secure['locked'] ) {
										echo '<input type="text" disabled value="' . esc_attr( $sec ) . '" class="regular-text" /> <em>(constant)</em>';
									} else {
										?>
										<select name="secure" id="bzs-secure">
											<option value="tls" <?php selected( $sec, 'tls' ); ?>>TLS</option>
											<option value="ssl" <?php selected( $sec, 'ssl' ); ?>>SSL</option>
											<option value=""    <?php selected( $sec, '' );    ?>><?php esc_html_e( 'Không (none)', 'bizcity-twin-ai' ); ?></option>
										</select>
										<?php
									}
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Yêu cầu auth', 'bizcity-twin-ai' ); ?></th>
								<td>
									<?php if ( $auth['locked'] ) : ?>
										<em>(constant)</em> <?php echo $auth['value'] ? __( 'Bật', 'bizcity-twin-ai' ) : __( 'Tắt', 'bizcity-twin-ai' ); ?>
									<?php else : ?>
										<label><input type="checkbox" name="auth" value="1" <?php checked( (bool) $auth['value'] ); ?> />
											<?php esc_html_e( 'Bật SMTP authentication', 'bizcity-twin-ai' ); ?></label>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-user"><?php esc_html_e( 'Username', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php self::field_input( 'user', 'bzs-user', 'text', $user, 'you@gmail.com', 'regular-text' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-pass"><?php esc_html_e( 'Password / App password', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php
									if ( $pass['locked'] ) {
										echo '<input type="password" disabled value="********" class="regular-text" /> <em>(constant)</em>';
									} else {
										$has_existing = ! empty( $pass['value'] );
										?>
										<input type="password" id="bzs-pass" name="pass" value="" autocomplete="new-password" class="regular-text"
											placeholder="<?php echo $has_existing ? esc_attr__( '(giữ nguyên — nhập để đổi)', 'bizcity-twin-ai' ) : ''; ?>" />
										<p class="description">
											<?php esc_html_e( 'Với Gmail dùng "App Password" 16 ký tự (không phải mật khẩu Google chính). Để trống nếu không muốn đổi.', 'bizcity-twin-ai' ); ?>
										</p>
										<?php
									}
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-from"><?php esc_html_e( 'From email', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php self::field_input( 'from', 'bzs-from', 'email', $from, 'noreply@example.com', 'regular-text' ); ?>
									<p class="description"><?php esc_html_e( 'Địa chỉ "From:" của email gửi đi (thường trùng username).', 'bizcity-twin-ai' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bzs-from-name"><?php esc_html_e( 'From name', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<?php self::field_input( 'from_name', 'bzs-from-name', 'text', $from_name, get_bloginfo( 'name' ), 'regular-text' ); ?>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Lưu cấu hình SMTP', 'bizcity-twin-ai' ) ); ?>
				</form>

				<hr style="margin:30px 0;" />

				<h2><?php esc_html_e( 'Gửi thử email', 'bizcity-twin-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bizcity_smtp_test" />
					<?php wp_nonce_field( self::NONCE_TEST ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bzs-test-to"><?php esc_html_e( 'Gửi tới', 'bizcity-twin-ai' ); ?></label></th>
							<td>
								<input type="email" id="bzs-test-to" name="test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
								<?php submit_button( __( 'Gửi test', 'bizcity-twin-ai' ), 'secondary', 'submit_test', false ); ?>
							</td>
						</tr>
					</table>
				</form>

			<hr style="margin:30px 0;" />

			<?php // [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — Advanced manual check section. ?>
			<h2><?php esc_html_e( 'Kiểm tra nâng cao', 'bizcity-twin-ai' ); ?></h2>
			<p style="color:#646970;"><?php esc_html_e( 'Gử i email mẫu theo loại nội dung thực tế — kiểm tra toàn bộ pipeline SMTP (kết nối + render HTML + WooCommerce / WordPress flows).', 'bizcity-twin-ai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bizcity_smtp_check" />
				<?php wp_nonce_field( self::NONCE_CHECK ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzs-check-type"><?php esc_html_e( 'Loại kiểm tra', 'bizcity-twin-ai' ); ?></label></th>
						<td>
							<select id="bzs-check-type" name="check_type">
								<option value="simple"><?php esc_html_e( '📝 Email văn bản đơn giản (text/plain) — kết nối cơ bản', 'bizcity-twin-ai' ); ?></option>
								<option value="html_rich"><?php esc_html_e( '🎨 Email HTML đầy đủ (template BizCity)', 'bizcity-twin-ai' ); ?></option>
								<option value="woo_order"><?php esc_html_e( '🛒 Thông báo đơn hàng thành công (WooCommerce)', 'bizcity-twin-ai' ); ?></option>
								<option value="wp_new_user"><?php esc_html_e( '👤 Đăng ký tài khoản mới (WordPress)', 'bizcity-twin-ai' ); ?></option>
								<option value="wp_pw_reset"><?php esc_html_e( '🔑 Đặt lại mật khẩu (WordPress)', 'bizcity-twin-ai' ); ?></option>
							</select>
							<p id="bzs-check-desc" class="description" style="margin-top:6px;"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzs-check-to"><?php esc_html_e( 'Gử i tới', 'bizcity-twin-ai' ); ?></label></th>
						<td>
							<input type="email" id="bzs-check-to" name="check_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
							<?php submit_button( __( '▶ Chạy kiểm tra', 'bizcity-twin-ai' ), 'primary', 'submit_check', false ); ?>
						</td>
					</tr>
				</table>
			</form>

			<hr style="margin:30px 0;" />

			<script>
			(function(){
				var PRESETS = {
					gmail:     { host: 'smtp.gmail.com',            port: 587, secure: 'tls', note: 'Gmail — dùng App Password 16 ký tự. Bắt buộc bật Xác minh 2 bước trước.' },
					outlook:   { host: 'smtp-mail.outlook.com',     port: 587, secure: 'tls', note: 'Outlook / Hotmail — Username là địa chỉ email đầy đủ.' },
					office365: { host: 'smtp.office365.com',        port: 587, secure: 'tls', note: 'Office 365 Business — cần Modern Auth nếu tổ chức bật Conditional Access.' },
					yahoo:     { host: 'smtp.mail.yahoo.com',       port: 587, secure: 'tls', note: 'Yahoo Mail — bật App Password tại myaccount.yahoo.com > Bảo mật.' },
					brevo:     { host: 'smtp-relay.brevo.com',      port: 587, secure: 'tls', note: 'Brevo (Sendinblue) — Username: email, Password: SMTP API key từ dashboard Brevo.' },
					mailgun:   { host: 'smtp.mailgun.org',          port: 587, secure: 'tls', note: 'Mailgun — Username: postmaster@domain, Password: SMTP API key.' },
					zoho:      { host: 'smtp.zoho.com',             port: 587, secure: 'tls', note: 'Zoho Mail — bật App-specific password tại accounts.zoho.com > Security.' },
					ionos:     { host: 'smtp.ionos.com',            port: 587, secure: 'tls', note: 'IONOS (1&1) — hoặc Port 465 SSL.' },
					sparkpost: { host: 'smtp.sparkpostmail.com',    port: 587, secure: 'tls', note: 'SparkPost — Username: SMTP_Injection, Password: API key.' },
					sendgrid:  { host: 'smtp.sendgrid.net',         port: 587, secure: 'tls', note: 'SendGrid — Username: apikey (literal), Password: API key.' }
				};
				var CHECK_DESCRIPTIONS = {
					simple:      'Gử i email text/plain — kiểm tra kết nối SMTP cơ bản nhất.',
					html_rich:   'Gử i HTML template đầy đủ với layout BizCity — kiểm tra khả năng render HTML.',
					woo_order:   'Mô phỏng email xác nhận đơn hàng WooCommerce. Nếu site có đơn hàng thực, sẽ lấy thông tin đơn mới nhất.',
					wp_new_user: 'Mô phỏng email chào mừng khi đăng ký tài khoản WordPress mới.',
					wp_pw_reset: 'Mô phỏng email đặt lại mật khẩu WordPress (link giả lập, không hoạt động).'
				};
				var presetBox = document.getElementById('bzs-presets');
				if (presetBox) {
					presetBox.addEventListener('click', function(e) {
						var btn = e.target.closest ? e.target.closest('[data-preset]') : (e.target.getAttribute && e.target.getAttribute('data-preset') ? e.target : null);
						if (!btn) return;
						var p = PRESETS[btn.getAttribute('data-preset')];
						if (!p) return;
						var hostEl   = document.getElementById('bzs-host');
						var portEl   = document.getElementById('bzs-port');
						var secureEl = document.getElementById('bzs-secure');
						var authEl   = document.querySelector('input[name="auth"]');
						if (hostEl)   hostEl.value   = p.host;
						if (portEl)   portEl.value   = p.port;
						if (secureEl) secureEl.value = p.secure;
						if (authEl)   authEl.checked = true;
						var note = document.getElementById('bzs-preset-note');
						if (!note) {
							note = document.createElement('p');
							note.id = 'bzs-preset-note';
							note.style.cssText = 'margin:8px 0 0;padding:8px 12px;background:#d7eef5;border-left:3px solid #2271b1;font-size:13px;color:#1d2327;';
							presetBox.parentNode.appendChild(note);
						}
						note.textContent = '💡 ' + p.note;
					});
				}
				var checkType = document.getElementById('bzs-check-type');
				var checkDesc = document.getElementById('bzs-check-desc');
				if (checkType && checkDesc) {
					function updateDesc() { checkDesc.textContent = CHECK_DESCRIPTIONS[checkType.value] || ''; }
					checkType.addEventListener('change', updateDesc);
					updateDesc();
				}
			})();
			</script>

			</div><!-- /.wrap -->
			<?php
		}

		/** Render text/number/email input honouring constant-locked state. */
		private static function field_input( string $name, string $id, string $type, array $eff, string $placeholder, string $class ): void {
			$value = (string) $eff['value'];
			if ( $eff['locked'] ) {
				printf(
					'<input type="%1$s" id="%2$s" disabled value="%3$s" class="%4$s" /> <em>(constant)</em>',
					esc_attr( $type ), esc_attr( $id ), esc_attr( $value ), esc_attr( $class )
				);
				return;
			}
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s" class="%6$s" />',
				esc_attr( $type ), esc_attr( $id ), esc_attr( $name ),
				esc_attr( $value ), esc_attr( $placeholder ), esc_attr( $class )
			);
		}

		private static function render_notices(): void {
			if ( ! empty( $_GET['updated'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'Đã lưu cấu hình SMTP.', 'bizcity-twin-ai' ) . '</p></div>';
			}
			if ( ! empty( $_GET['tested'] ) ) {
				$result = sanitize_key( (string) $_GET['tested'] );
				$to     = isset( $_GET['to'] ) ? sanitize_email( rawurldecode( (string) $_GET['to'] ) ) : '';
				$err    = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( (string) $_GET['err'] ) ) : '';
				if ( $result === 'ok' ) {
					echo '<div class="notice notice-success is-dismissible"><p>✓ ' .
						sprintf( esc_html__( 'Đã gửi email test tới %s. Hãy kiểm tra hộp thư.', 'bizcity-twin-ai' ), '<code>' . esc_html( $to ) . '</code>' ) .
						'</p></div>';
				} elseif ( $result === 'unconfigured' ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' .
						esc_html__( 'Chưa cấu hình đầy đủ — không thể gửi test.', 'bizcity-twin-ai' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>✗ ' .
						esc_html__( 'Gửi thất bại.', 'bizcity-twin-ai' );
					if ( $err ) {
						echo ' <code>' . esc_html( $err ) . '</code>';
					}
					echo '</p></div>';
				}
			}
			// [2026-06-11 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — advanced check result notice.
			if ( ! empty( $_GET['checked'] ) ) {
				$chk_result = sanitize_key( (string) $_GET['checked'] );
				$chk_to     = isset( $_GET['to'] )         ? sanitize_email( rawurldecode( (string) $_GET['to'] ) )         : '';
				$chk_type   = isset( $_GET['check_type'] ) ? sanitize_key( rawurldecode( (string) $_GET['check_type'] ) )   : 'simple';
				$chk_err    = isset( $_GET['err'] )        ? sanitize_text_field( rawurldecode( (string) $_GET['err'] ) )   : '';
				$type_labels = array(
					'simple'      => 'email đơn giản',
					'html_rich'   => 'HTML template',
					'woo_order'   => 'thông báo đơn hàng',
					'wp_new_user' => 'đăng ký tài khoản',
					'wp_pw_reset' => 'đặt lại mật khẩu',
				);
				$chk_label = isset( $type_labels[ $chk_type ] ) ? $type_labels[ $chk_type ] : $chk_type;
				if ( $chk_result === 'ok' ) {
					echo '<div class="notice notice-success is-dismissible"><p>✅ '
						. sprintf(
							/* translators: %1$s: check type label, %2$s: email address */
							esc_html__( 'Kiểm tra "%1$s" thành công — email đã gửi tới %2$s. Hãy kiểm tra hộp thư.', 'bizcity-twin-ai' ),
							esc_html( $chk_label ),
							'<code>' . esc_html( $chk_to ) . '</code>'
						)
						. '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>❌ '
						. sprintf(
							/* translators: %s: check type label */
							esc_html__( 'Kiểm tra "%s" thất bại.', 'bizcity-twin-ai' ),
							esc_html( $chk_label )
						);
					if ( $chk_err ) {
						echo ' <code>' . esc_html( $chk_err ) . '</code>';
					}
					echo '</p></div>';
				}
			}
		}
	}

	BizCity_SMTP_Admin::register();
}
