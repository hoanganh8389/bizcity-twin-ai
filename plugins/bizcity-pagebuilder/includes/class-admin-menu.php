<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Admin_Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_post_bzpb_toggle_template',  [ __CLASS__, 'handle_toggle_template' ] );
		add_action( 'admin_post_bzpb_delete_project',   [ __CLASS__, 'handle_delete_project' ] );
	}

	public static function register_menu(): void {
		// Main submenu — links to the Page Builder SPA
		add_submenu_page(
			'bizcity-twin-plugins',
			__( 'Page Builder', 'bizcity-pagebuilder' ),
			__( '🌐 Page Builder', 'bizcity-pagebuilder' ),
			'read',
			'bizcity-pagebuilder',
			[ __CLASS__, 'render_page' ]
		);

		// Templates manager submenu
		add_submenu_page(
			'bizcity-twin-plugins',
			__( 'Templates mẫu', 'bizcity-pagebuilder' ),
			__( '📋 Templates mẫu', 'bizcity-pagebuilder' ),
			'manage_options',
			'bzpb-templates',
			[ __CLASS__, 'render_templates_page' ]
		);
	}

	/* ─── Page Builder link page ─── */

	public static function render_page(): void {
		$tool_url = home_url( '/tool-pagebuilder/' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity Page Builder', 'bizcity-pagebuilder' ); ?></h1>
			<p><?php esc_html_e( 'AI tạo website trực quan — drag & drop, 19 block types, 10 theme presets.', 'bizcity-pagebuilder' ); ?></p>
			<a href="<?php echo esc_url( $tool_url ); ?>" class="button button-primary button-hero" target="_blank">
				<?php esc_html_e( 'Mở Page Builder', 'bizcity-pagebuilder' ); ?> →
			</a>
		</div>
		<?php
	}

	/* ─── Templates admin page ─── */

	public static function render_templates_page(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';

		if ( isset( $_GET['toggled'] ) ) {
			$label = absint( $_GET['toggled'] ) ? 'Đã đánh dấu là template.' : 'Đã bỏ đánh dấu template.';
			echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $label ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>✅ Đã xóa project.</p></div>';
		}

		$filter = ( isset( $_GET['filter'] ) && $_GET['filter'] === 'all' ) ? 'all' : 'templates';
		// phpcs:ignore WordPress.DB.PreparedSQL -- no user input in query
		$where  = $filter === 'all' ? '' : ' WHERE is_template = 1';
		$rows   = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY updated_at DESC" ); // phpcs:ignore

		$tool_url = home_url( '/tool-pagebuilder/' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">📋 Templates mẫu</h1>
			<a href="<?php echo esc_url( $tool_url ); ?>" class="page-title-action" target="_blank">+ Tạo project mới</a>

			<ul class="subsubsub" style="margin:8px 0 16px;">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bzpb-templates&filter=templates' ) ); ?>"
					   <?php if ( $filter !== 'all' ) echo 'class="current"'; ?>>Chỉ templates</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bzpb-templates&filter=all' ) ); ?>"
					   <?php if ( $filter === 'all' ) echo 'class="current"'; ?>>Tất cả projects</a>
				</li>
			</ul>

			<table class="wp-list-table widefat fixed striped" style="margin-top:0;">
				<thead>
					<tr>
						<th style="width:80px;">Thumbnail</th>
						<th>Tên project</th>
						<th style="width:130px;">Trạng thái</th>
						<th style="width:110px;">Template</th>
						<th style="width:155px;">Cập nhật</th>
						<th style="width:220px;">Thao tác</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="6" style="text-align:center;padding:40px;color:#666;">
							<?php if ( $filter === 'all' ) : ?>
								Chưa có project nào. <a href="<?php echo esc_url( $tool_url ); ?>" target="_blank">Tạo ngay →</a>
							<?php else : ?>
								Chưa có template nào. Mở Page Builder, tạo project rồi nhấn <strong>"⊕ Đặt template"</strong> bên dưới.
							<?php endif; ?>
						</td>
					</tr>
				<?php else : foreach ( $rows as $row ) :
					$is_tpl     = ! empty( $row->is_template );
					$edit_url   = add_query_arg( 'id', $row->id, $tool_url );
					$toggle_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=bzpb_toggle_template&id=' . $row->id . '&current=' . (int) $is_tpl ),
						'bzpb-toggle-' . $row->id
					);
					$delete_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=bzpb_delete_project&id=' . $row->id ),
						'bzpb-delete-project-' . $row->id
					);
					?>
					<tr>
						<td>
							<?php if ( ! empty( $row->thumbnail_url ) ) : ?>
								<img src="<?php echo esc_url( $row->thumbnail_url ); ?>"
								     style="width:72px;height:48px;object-fit:cover;border-radius:4px;border:1px solid #ddd;">
							<?php else : ?>
								<div style="width:72px;height:48px;background:#f0f0f0;border-radius:4px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:20px;">🌐</div>
							<?php endif; ?>
						</td>
						<td>
							<strong>
								<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank">
									<?php echo esc_html( $row->title ?: '(Không tên)' ); ?>
								</a>
							</strong>
							<br><small style="color:#999;">ID: <?php echo absint( $row->id ); ?></small>
						</td>
						<td>
							<?php $s = $row->status ?? 'draft'; ?>
							<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;
								<?php echo $s === 'published' ? 'background:#d1fae5;color:#065f46' : 'background:#fef3c7;color:#92400e'; ?>">
								<?php echo $s === 'published' ? 'Đã xuất bản' : 'Nháp'; ?>
							</span>
						</td>
						<td>
							<?php if ( $is_tpl ) : ?>
								<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;">✓ Template</span>
							<?php else : ?>
								<span style="color:#aaa;font-size:12px;">—</span>
							<?php endif; ?>
						</td>
						<td style="color:#666;font-size:12px;">
							<?php echo esc_html( date_i18n( 'H:i - d/m/Y', strtotime( $row->updated_at ) ) ); ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" target="_blank">✏️ Mở</a>
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
								<?php echo $is_tpl ? '⊖ Bỏ template' : '⊕ Đặt template'; ?>
							</a>
							<a href="<?php echo esc_url( $delete_url ); ?>"
							   class="button button-small"
							   style="color:#b91c1c;"
							   onclick="return confirm('Xóa project này? Hành động này không thể hoàn tác.')">🗑</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ─── Action handlers ─── */

	public static function handle_toggle_template(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'bzpb-toggle-' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$current = absint( $_GET['current'] ?? 0 );
		$new_val = $current ? 0 : 1;
		$wpdb->update( $wpdb->prefix . 'bzpb_projects', [ 'is_template' => $new_val ], [ 'id' => $id ] );

		wp_safe_redirect( admin_url( 'admin.php?page=bzpb-templates&toggled=' . $new_val ) );
		exit;
	}

	public static function handle_delete_project(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'bzpb-delete-project-' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bzpb_projects', [ 'id' => $id ] );
		// Clean up orphaned WP page meta if any
		$wpdb->delete( $wpdb->prefix . 'bzpb_generations', [ 'project_id' => $id ] );

		wp_safe_redirect( admin_url( 'admin.php?page=bzpb-templates&deleted=1&filter=all' ) );
		exit;
	}
}
