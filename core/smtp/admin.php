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
		const NONCE_KEY  = 'bizcity_smtp_save';
		const NONCE_TEST = 'bizcity_smtp_test';

		public static function register(): void {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
			add_action( 'admin_post_bizcity_smtp_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_bizcity_smtp_test', array( __CLASS__, 'handle_test' ) );
		}

		public static function add_menu(): void {
			add_menu_page(
				__( 'BizCity SMTP', 'bizcity-twin-ai' ),
				__( 'BizCity SMTP', 'bizcity-twin-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' ),
				'dashicons-email-alt',
				81
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
					<?php esc_html_e( 'BizCity SMTP — Cấu hình email gửi đi', 'bizcity-twin-ai' ); ?></h1>

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
			</div>
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
		}
	}

	BizCity_SMTP_Admin::register();
}
