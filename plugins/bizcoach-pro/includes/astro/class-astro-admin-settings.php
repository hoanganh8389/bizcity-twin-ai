<?php
/**
 * BizCoach Pro — Astro Gateway Admin Settings (DEPRECATED 2026-05-17)
 *
 *
 *     admin.php?page=bizcity-twinchat-settings
 *
 * This page is kept for backward compatibility with old admin bookmarks and
 * legacy links; it now renders a notice + redirect button instead of a form.
 * The legacy POST handlers (`bcpro_astro_save_gateway`, `bcpro_astro_test_gateway`)
 * are retained so any in-flight scripted calls won't 404 — but UI no longer
 * exposes them.
 *
 * @package BizCoach_Pro
 * @since   0.3.0 (PHASE-0.2 Sprint G.5)
 * @deprecated 0.4.0 — use BizCity_TwinChat_Settings_Page instead.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Admin_Settings' ) ) { return; }

final class BizCoach_Pro_Astro_Admin_Settings {

	const PAGE_SLUG = 'bcpro-astro-gateway';

	public static function init(): void {
		add_action( 'admin_menu',                          array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bcpro_astro_save_gateway', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bcpro_astro_test_gateway', array( __CLASS__, 'handle_test' ) );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'BCPRO Astro Gateway', 'bizcoach-pro' ),
			__( 'BCPRO Astro Gateway', 'bizcoach-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Legacy save handler — kept for backward compatibility without accepting
	 * or persisting credentials outside the canonical TwinChat settings page.
	 */
	public static function handle_save(): void {
		// [2026-07-30 Johnny Chu] R-1API-AUTH — legacy endpoint must not write raw gateway credentials.
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
		check_admin_referer( 'bcpro_astro_save_gateway' );
		$redirect = add_query_arg( array( 'bcpro_legacy_settings' => 'deprecated' ), self::canonical_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
		check_admin_referer( 'bcpro_astro_test_gateway' );

		// Legacy test still runs against the resolved gateway, but routes the
		// admin to the canonical page afterwards.
		BizCoach_Pro_Astro_Client::quota();

		wp_safe_redirect( self::canonical_url() );
		exit;
	}

	/** Absolute URL of the canonical unified settings page (R-1API-9). */
	private static function canonical_url(): string {
		return admin_url( 'admin.php?page=bizcity-twinchat-settings' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$canon = self::canonical_url();
		// [2026-07-30 Johnny Chu] R-1API-AUTH — deprecated settings page reads readiness only through the canonical client boundary.
		$canon_ready = class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->is_ready();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BCPRO Astro Gateway', 'bizcoach-pro' ); ?>
				<span style="font-size:13px;font-weight:normal;color:#d63638;">
					— <?php esc_html_e( 'DEPRECATED', 'bizcoach-pro' ); ?>
				</span>
			</h1>

			<div class="notice notice-warning">
				<p>
					⚠️ <strong><?php esc_html_e( 'Trang này đã chuyển.', 'bizcoach-pro' ); ?></strong><br>
					<?php esc_html_e( 'Theo tiêu chuẩn R-1API ("1 API key dùng chung cho mọi plugin BizCity"), cấu hình gateway nay nằm tập trung tại trang TwinChat → ⚙ Settings.', 'bizcoach-pro' ); ?>
				</p>
				<p>
					<a class="button button-primary button-large" href="<?php echo esc_url( $canon ); ?>">
						→ <?php esc_html_e( 'Mở trang BizCity API & Gateway', 'bizcoach-pro' ); ?>
					</a>
				</p>
			</div>

			<table class="widefat striped" style="max-width:720px;margin-top:16px;">
				<thead><tr>
					<th><?php esc_html_e( 'Option', 'bizcoach-pro' ); ?></th>
					<th><?php esc_html_e( 'Trạng thái', 'bizcoach-pro' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><code>bizcity_llm_api_key</code> <small>(canonical R-1API-2)</small></td>
						<td><?php echo $canon_ready ? '✅ ' . esc_html__( 'đã cấu hình', 'bizcoach-pro' ) : '<span style="color:#d63638;">❌ ' . esc_html__( 'trống', 'bizcoach-pro' ) . '</span>'; ?></td>
					</tr>
				</tbody>
			</table>

			<p class="description" style="margin-top:16px;max-width:720px;">
				<?php esc_html_e( 'Trạng thái được đọc qua BizCity LLM Client; cấu hình gateway nằm tập trung tại trang TwinChat Settings.', 'bizcoach-pro' ); ?>
			</p>
		</div>
		<?php
	}
}
