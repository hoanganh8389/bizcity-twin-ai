<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Google_Hub_Page — Admin renderer for the unified Google Hub page.
 *
 * Mounts under:
 *   admin.php?page=bizchat-gateway&group=integrations&sub=google-hub
 *
 * Replaces the per-site OAuth Client ID/Secret form once shipped in:
 *   - Scheduler React app (core/scheduler/app/src/SchedulerApp.tsx)
 *   - Various tool integrations
 *
 * Single canonical CTA:
 *   "Kết nối Google qua bizcity.vn" → BizCity_Google_Hub::get_connect_url()
 *
 * @since 2026-06-04
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Google_Hub_Page {

	/**
	 * Render callback registered via channel-menu-migrate.
	 */
	public static function render() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( __( 'Bạn không có quyền truy cập trang này.', 'bizcity-twin-ai' ) );
		}
		if ( ! class_exists( 'BizCity_Google_Hub', false ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'BizCity_Google_Hub helper chưa được nạp. Kiểm tra core/bizcity-llm/bootstrap.php.', 'bizcity-twin-ai' )
				. '</p></div>';
			return;
		}

		$status     = BizCity_Google_Hub::get_status();
		$hub_domain = $status['hub_domain'];
		$path       = $status['path'];

		$primary_url    = BizCity_Google_Hub::get_connect_url( [ 'scopes' => 'profile' ] );
		$disconnect_url = BizCity_Google_Hub::get_disconnect_url();

		// Inline notice from hub callback (?bzgoogle_connected=1&google_email=…)
		$flash = '';
		if ( ! empty( $_GET['bzgoogle_connected'] ) ) {
			$flash = sprintf(
				/* translators: %s = google email */
				__( '✅ Đã kết nối Google: %s', 'bizcity-twin-ai' ),
				esc_html( sanitize_email( wp_unslash( $_GET['google_email'] ?? '' ) ) )
			);
		} elseif ( ! empty( $_GET['bzgoogle_error'] ) ) {
			$flash = sprintf(
				__( '❌ Kết nối Google thất bại: %s', 'bizcity-twin-ai' ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['bzgoogle_error'] ) ) )
			);
		}
		?>
		<div class="wrap bizcity-google-hub" style="max-width:960px;">
			<h1 style="display:flex;align-items:center;gap:8px;">
				🔗 <?php esc_html_e( 'Google Hub', 'bizcity-twin-ai' ); ?>
				<span class="bzgh-badge" style="font-size:12px;padding:2px 8px;border-radius:10px;background:#e6f4ff;color:#0073aa;">
					<?php echo esc_html( $hub_domain ); ?>
				</span>
			</h1>

			<?php if ( $flash ) : ?>
				<div class="notice notice-info is-dismissible" style="margin-top:10px;">
					<p><?php echo wp_kses_post( $flash ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description" style="font-size:13px;">
				<?php
				printf(
					/* translators: %s = hub domain */
					esc_html__( 'Mọi công cụ Google (Scheduler, Gmail, Drive, Sheets, Docs, Slides, Calendar, Contacts) dùng chung 1 tài khoản kết nối qua hub %s. Bạn không cần tự cấu hình OAuth Client ID / Secret cho từng site.', 'bizcity-twin-ai' ),
					'<code>' . esc_html( $hub_domain ) . '</code>'
				);
				?>
			</p>

			<?php if ( $path === 'none' ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Chưa có đường kết nối Google nào khả dụng. Vui lòng cài plugin "Bizcity Tool Google" hoặc cấu hình API key gateway BizCity.', 'bizcity-twin-ai' ); ?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>

			<!-- ── Master account card ─────────────────────────── -->
			<div class="bzgh-card" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-top:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Tài khoản chính', 'bizcity-twin-ai' ); ?></h2>

				<?php if ( $status['connected'] ) : ?>
					<p style="font-size:15px;margin:6px 0;">
						✅ <strong><?php echo esc_html( $status['email'] ); ?></strong>
					</p>
					<p class="description"><?php esc_html_e( 'Bạn có thể thêm scope cho từng dịch vụ phía dưới (incremental authorization).', 'bizcity-twin-ai' ); ?></p>

					<?php if ( ! is_wp_error( $primary_url ) ) : ?>
						<a class="button" href="<?php echo esc_url( $primary_url ); ?>">
							<?php esc_html_e( '🔄 Kết nối lại / Đổi tài khoản', 'bizcity-twin-ai' ); ?>
						</a>
					<?php endif; ?>

					<?php if ( $disconnect_url ) : ?>
						<a class="button button-link-delete" style="margin-left:8px;" href="<?php echo esc_url( $disconnect_url ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( 'Ngắt kết nối Google?', 'bizcity-twin-ai' ) ); ?>');">
							<?php esc_html_e( 'Ngắt kết nối', 'bizcity-twin-ai' ); ?>
						</a>
					<?php endif; ?>

				<?php else : ?>
					<p><?php esc_html_e( 'Chưa kết nối tài khoản Google nào.', 'bizcity-twin-ai' ); ?></p>
					<?php if ( is_wp_error( $primary_url ) ) : ?>
						<div class="notice notice-error inline"><p><?php echo esc_html( $primary_url->get_error_message() ); ?></p></div>
					<?php else : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( $primary_url ); ?>">
							<?php
							/* translators: %s = hub domain */
							printf( esc_html__( '🔐 Kết nối Google qua %s', 'bizcity-twin-ai' ), esc_html( $hub_domain ) );
							?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- ── Per-service grid ────────────────────────────── -->
			<h2 style="margin-top:32px;"><?php esc_html_e( 'Dịch vụ Google', 'bizcity-twin-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Mỗi dịch vụ có scope riêng. Bấm "Bật quyền" để thêm scope cho tài khoản đã kết nối ở trên.', 'bizcity-twin-ai' ); ?>
			</p>

			<div class="bzgh-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-top:14px;">
				<?php
				foreach ( BizCity_Google_Hub::SERVICES as $svc => $meta ) :
					$granted = ! empty( $status['services'][ $svc ] );
					$svc_url = BizCity_Google_Hub::get_service_connect_url( $svc );
					$svc_err = is_wp_error( $svc_url ) ? $svc_url->get_error_message() : '';
					?>
					<div class="bzgh-svc-card" style="background:#fff;border:1px solid #e2e2e2;border-radius:8px;padding:14px;">
						<div style="font-size:18px;margin-bottom:6px;">
							<?php echo esc_html( $meta['icon'] ); ?>
							<strong><?php echo esc_html( $meta['label'] ); ?></strong>
							<?php if ( $granted ) : ?>
								<span style="float:right;color:#46b450;font-size:12px;">✓ <?php esc_html_e( 'Đã bật', 'bizcity-twin-ai' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="description" style="min-height:36px;font-size:12px;color:#666;">
							<?php echo esc_html( $meta['desc'] ); ?>
						</p>
						<?php if ( $svc_err ) : ?>
							<p style="color:#a00;font-size:12px;"><?php echo esc_html( $svc_err ); ?></p>
						<?php elseif ( $granted ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $svc_url ); ?>">
								<?php esc_html_e( 'Cấp lại quyền', 'bizcity-twin-ai' ); ?>
							</a>
						<?php else : ?>
							<a class="button button-primary" href="<?php echo esc_url( $svc_url ); ?>">
								<?php esc_html_e( 'Bật quyền', 'bizcity-twin-ai' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- ── Developer info ──────────────────────────────── -->
			<details style="margin-top:32px;">
				<summary style="cursor:pointer;font-weight:600;">
					<?php esc_html_e( '🛠 Dành cho dev — gọi từ code', 'bizcity-twin-ai' ); ?>
				</summary>
				<div style="background:#f6f7f7;padding:14px;border-radius:6px;margin-top:8px;">
					<p><?php esc_html_e( 'Mọi tool muốn xin quyền Google chỉ cần gọi:', 'bizcity-twin-ai' ); ?></p>
<pre style="background:#1d1f21;color:#c5c8c6;padding:12px;border-radius:4px;overflow:auto;">
$url = BizCity_Google_Hub::get_service_connect_url( 'gmail',
    admin_url( 'admin.php?page=my-tool' ) );
if ( ! is_wp_error( $url ) ) {
    wp_safe_redirect( $url ); exit;
}</pre>
					<p style="font-size:12px;color:#666;">
						<?php
						printf(
							/* translators: %s = path resolution mode */
							esc_html__( 'Đường đi hiện tại: %s', 'bizcity-twin-ai' ),
							'<code>' . esc_html( $path ) . '</code>'
						);
						?>
					</p>
				</div>
			</details>
		</div>
		<?php
	}
}
