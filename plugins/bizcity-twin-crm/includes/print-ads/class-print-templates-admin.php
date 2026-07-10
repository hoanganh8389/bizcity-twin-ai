<?php
/**
 * BizCity CRM — Print Templates admin sub-screen (M-PA.W1).
 *
 * Lists the print-ads template library with two action buttons:
 *   • 🌱 Auto Seed       — reload data/print-templates-seed.json (force-reimport on demand)
 *   • 🔄 Sync from BizCity — placeholder for M-PA.W4 remote pull
 *
 * @package BizCity_Twin_CRM
 * @since   0.32.3 (M-PA.W1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Print_Templates_Admin', false ) ) { return; }

final class BizCity_CRM_Print_Templates_Admin {

	const SLUG = 'bizcity-crm-print-templates';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 65 );
		add_action( 'admin_post_bzcrm_print_templates_seed', array( __CLASS__, 'handle_seed' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'bizcity-crm',
			'Print Templates',
			'🖼️ Print Templates',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_seed(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		check_admin_referer( 'bzcrm_print_templates_seed' );

		$force = ! empty( $_POST['force'] );

		require_once BIZCITY_CRM_DIR . '/includes/print-ads/class-print-templates-installer.php';
		// Ensure tables exist first (idempotent).
		BizCity_CRM_Print_Templates_Installer::install();

		require_once BIZCITY_CRM_DIR . '/includes/print-ads/seed-print-templates.php';
		$res = bzcrm_seed_print_templates( $force );

		$msg = sprintf(
			'Seed xong: imported=%d, updated=%d, skipped=%d, errors=%d',
			(int) $res['imported'], (int) $res['updated'], (int) $res['skipped'], count( $res['errors'] )
		);
		set_transient( 'bzcrm_print_templates_admin_notice', array(
			'type'    => empty( $res['errors'] ) ? 'success' : 'warning',
			'message' => $msg,
			'errors'  => $res['errors'],
		), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		global $wpdb;

		$tbl = BizCity_CRM_Print_Templates_Installer::tbl_templates();
		// Defensive: ensure tables (first-run admin visit).
		BizCity_CRM_Print_Templates_Installer::install();

		$rows = $wpdb->get_results(
			"SELECT id, slug, source, template_type, title, target_aspect, recommended_model, status, sort_order
			 FROM {$tbl}
			 ORDER BY template_type ASC, sort_order ASC, id ASC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$notice = get_transient( 'bzcrm_print_templates_admin_notice' );
		delete_transient( 'bzcrm_print_templates_admin_notice' );

		$counts_by_type = array();
		foreach ( $rows as $r ) {
			$t = (string) ( $r['template_type'] ?? '' );
			$counts_by_type[ $t ] = ( $counts_by_type[ $t ] ?? 0 ) + 1;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">🖼️ Print Templates</h1>
			<p class="description">
				Thư viện template ảnh quảng cáo cho Campaign. v1 ship 12 template local;
				M-PA.W4 sẽ pull thêm từ <code>bizcity.vn/wp-json/bizcity/v1/print-templates</code>.
			</p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
					<?php if ( ! empty( $notice['errors'] ) ) : ?>
						<ul style="margin-left:20px;list-style:disc;">
							<?php foreach ( $notice['errors'] as $e ) : ?>
								<li><?php echo esc_html( (string) $e ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p style="margin:14px 0;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'bzcrm_print_templates_seed' ); ?>
					<input type="hidden" name="action" value="bzcrm_print_templates_seed" />
					<button type="submit" class="button button-primary">🌱 Auto Seed (skip existing)</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;"
				      onsubmit="return confirm('Force re-import sẽ GHI ĐÈ tất cả row local_seed theo slug. Tiếp tục?');">
					<?php wp_nonce_field( 'bzcrm_print_templates_seed' ); ?>
					<input type="hidden" name="action" value="bzcrm_print_templates_seed" />
					<input type="hidden" name="force" value="1" />
					<button type="submit" class="button">♻️ Force Re-import</button>
				</form>

				<button type="button" class="button" disabled title="M-PA.W4">🔄 Sync from BizCity (W4)</button>
			</p>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0;">
				<?php foreach ( $counts_by_type as $type => $count ) : ?>
					<span style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:14px;padding:4px 12px;font-size:12px;">
						<strong><?php echo esc_html( (string) $type ); ?>:</strong> <?php echo (int) $count; ?>
					</span>
				<?php endforeach; ?>
				<span style="background:#dcfce7;border:1px solid #16a34a;border-radius:14px;padding:4px 12px;font-size:12px;">
					<strong>Tổng:</strong> <?php echo (int) count( $rows ); ?>
				</span>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:50px;">ID</th>
						<th>Slug</th>
						<th>Type</th>
						<th>Title</th>
						<th style="width:80px;">Aspect</th>
						<th style="width:110px;">Model</th>
						<th style="width:100px;">Source</th>
						<th style="width:70px;">Status</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><em>Chưa có template. Bấm 🌱 Auto Seed để import 12 template mặc định.</em></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo (int) $r['id']; ?></td>
								<td><code><?php echo esc_html( (string) $r['slug'] ); ?></code></td>
								<td><span class="bzcrm-badge"><?php echo esc_html( (string) $r['template_type'] ); ?></span></td>
								<td><?php echo esc_html( (string) $r['title'] ); ?></td>
								<td><?php echo esc_html( (string) $r['target_aspect'] ); ?></td>
								<td><?php echo esc_html( (string) $r['recommended_model'] ); ?></td>
								<td><?php echo esc_html( (string) $r['source'] ); ?></td>
								<td><?php echo esc_html( (string) $r['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<style>
				.bzcrm-badge {
					display:inline-block;padding:2px 8px;border-radius:10px;
					background:#e0e7ff;color:#3730a3;font-size:11px;font-weight:600;
				}
			</style>
		</div>
		<?php
	}
}
