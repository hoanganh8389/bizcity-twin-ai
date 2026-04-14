<?php
/**
 * Admin Categories — list + add/edit form.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Handle form submit ── */
if ( isset( $_POST['bzcc_cat_nonce'] ) && wp_verify_nonce( $_POST['bzcc_cat_nonce'], 'bzcc_save_category' ) ) {
	$cat_data = [
		'slug'        => sanitize_title( $_POST['slug'] ?? '' ),
		'title'       => sanitize_text_field( $_POST['title'] ?? '' ),
		'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
		'icon_emoji'  => sanitize_text_field( $_POST['icon_emoji'] ?? '' ),
		'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
		'status'      => sanitize_key( $_POST['status'] ?? 'active' ),
	];

	$edit_id = absint( $_POST['edit_id'] ?? 0 );

	if ( $edit_id ) {
		BZCC_Category_Manager::update( $edit_id, $cat_data );
		$msg = 'Đã cập nhật danh mục.';
	} else {
		BZCC_Category_Manager::insert( $cat_data );
		$msg = 'Đã tạo danh mục mới.';
	}
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

/* ── Handle delete ── */
if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] )
	&& $_GET['action'] === 'delete'
	&& wp_verify_nonce( $_GET['_wpnonce'], 'bzcc_delete_cat_' . absint( $_GET['id'] ) )
) {
	BZCC_Category_Manager::delete( absint( $_GET['id'] ) );
	echo '<div class="notice notice-warning is-dismissible"><p>Đã xóa danh mục.</p></div>';
}

/* ── Load edit ── */
$editing = null;
if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
	$editing = BZCC_Category_Manager::get_by_id( absint( $_GET['id'] ) );
}

$categories = BZCC_Category_Manager::get_all();
$page_slug  = BZCC_Admin_Menu::MENU_SLUG . '-categories';
?>
<div class="wrap bzcc-wrap">
	<h1>📂 Quản lý Danh mục</h1>

	<!-- Form -->
	<div class="bzcc-form-card">
		<h2><?php echo $editing ? 'Sửa danh mục' : 'Thêm danh mục mới'; ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'bzcc_save_category', 'bzcc_cat_nonce' ); ?>
			<input type="hidden" name="edit_id" value="<?php echo $editing ? (int) $editing->id : 0; ?>">

			<table class="form-table">
				<tr>
					<th><label for="title">Tên danh mục</label></th>
					<td><input type="text" name="title" id="title" class="regular-text" required
						value="<?php echo esc_attr( $editing->title ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="slug">Slug</label></th>
					<td><input type="text" name="slug" id="slug" class="regular-text" required
						value="<?php echo esc_attr( $editing->slug ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="description">Mô tả</label></th>
					<td><textarea name="description" id="description" rows="3" class="large-text"><?php
						echo esc_textarea( $editing->description ?? '' );
					?></textarea></td>
				</tr>
				<tr>
					<th><label for="icon_emoji">Icon Emoji</label></th>
					<td>
						<div class="bzcc-emoji-picker" data-target="icon_emoji">
							<button type="button" class="bzcc-emoji-picker__trigger">
								<span class="bzcc-emoji-picker__preview"><?php echo esc_html( $editing->icon_emoji ?? '' ); ?></span>
								<span class="bzcc-emoji-picker__placeholder">Chọn icon</span>
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
							</button>
							<div class="bzcc-emoji-picker__dropdown"></div>
							<input type="hidden" name="icon_emoji" id="icon_emoji" value="<?php echo esc_attr( $editing->icon_emoji ?? '' ); ?>">
						</div>
					</td>
				</tr>
				<tr>
					<th><label for="sort_order">Thứ tự sắp xếp</label></th>
					<td><input type="number" name="sort_order" id="sort_order" class="small-text"
						value="<?php echo (int) ( $editing->sort_order ?? 0 ); ?>"></td>
				</tr>
				<tr>
					<th><label for="status">Trạng thái</label></th>
					<td>
						<select name="status" id="status">
							<option value="active" <?php selected( $editing->status ?? 'active', 'active' ); ?>>Active</option>
							<option value="draft" <?php selected( $editing->status ?? '', 'draft' ); ?>>Draft</option>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( $editing ? 'Cập nhật' : 'Tạo mới' ); ?>
		</form>
	</div>

	<!-- List -->
	<h2>Danh sách danh mục</h2>
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th>ID</th>
				<th>Icon</th>
				<th>Tên</th>
				<th>Slug</th>
				<th>Templates</th>
				<th>Trạng thái</th>
				<th>Hành động</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $categories ) ) : ?>
				<tr><td colspan="7">Chưa có danh mục.</td></tr>
			<?php else : ?>
				<?php foreach ( $categories as $cat ) : ?>
					<tr>
						<td><?php echo (int) $cat->id; ?></td>
						<td><?php echo esc_html( $cat->icon_emoji ); ?></td>
						<td><strong><?php echo esc_html( $cat->title ); ?></strong></td>
						<td><code><?php echo esc_html( $cat->slug ); ?></code></td>
						<td><?php echo (int) $cat->tool_count; ?></td>
						<td><?php echo esc_html( $cat->status ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( "admin.php?page={$page_slug}&action=edit&id={$cat->id}" ) ); ?>">Sửa</a>
							|
							<a href="<?php echo esc_url( wp_nonce_url(
								admin_url( "admin.php?page={$page_slug}&action=delete&id={$cat->id}" ),
								'bzcc_delete_cat_' . $cat->id
							) ); ?>" onclick="return confirm('Xóa danh mục này?');" class="delete">Xóa</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
