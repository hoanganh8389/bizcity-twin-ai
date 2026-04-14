<?php
/**
 * Admin Categories Page — Manage image template categories.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Handle form save */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['bztimg_cat_nonce'] ) ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bztimg_cat_nonce'] ) ), 'bztimg_save_category' ) ) {
        wp_die( 'Security check failed.' );
    }

    $cat_data = array(
        'slug'        => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
        'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'description' => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
        'icon_emoji'  => sanitize_text_field( wp_unslash( $_POST['icon_emoji'] ?? '' ) ),
        'icon_url'    => esc_url_raw( wp_unslash( $_POST['icon_url'] ?? '' ) ),
        'sort_order'  => intval( $_POST['sort_order'] ?? 0 ),
        'status'      => in_array( ( $_POST['status'] ?? 'active' ), array( 'active', 'draft' ), true ) ? $_POST['status'] : 'active',
    );

    $cat_id = absint( $_POST['category_id'] ?? 0 );
    if ( $cat_id ) {
        $result = BizCity_Template_Category_Manager::update( $cat_id, $cat_data );
    } else {
        $result = BizCity_Template_Category_Manager::insert( $cat_data );
    }

    if ( is_wp_error( $result ) ) {
        $msg = 'error&error_msg=' . urlencode( $result->get_error_message() );
    } else {
        $msg = 'saved';
    }

    echo '<script>window.location="' . esc_url( admin_url( 'admin.php?page=bztimg-categories&message=' . $msg ) ) . '";</script>';
    return;
}

/* Handle delete */
$action = sanitize_text_field( $_GET['action'] ?? '' );
$cat_id = absint( $_GET['cat_id'] ?? 0 );
if ( $action === 'delete' && $cat_id ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'bztimg_delete_cat_' . $cat_id ) ) {
        wp_die( 'Security check failed.' );
    }
    BizCity_Template_Category_Manager::delete( $cat_id );
    echo '<script>window.location="' . esc_url( admin_url( 'admin.php?page=bztimg-categories&message=deleted' ) ) . '";</script>';
    return;
}

$categories = BizCity_Template_Category_Manager::get_all();
$edit_cat   = null;
if ( $action === 'edit' && $cat_id ) {
    $edit_cat = BizCity_Template_Category_Manager::get_by_id( $cat_id );
}

$msg_key  = sanitize_text_field( $_GET['message'] ?? '' );
$messages = array(
    'saved'   => '✅ Danh mục đã được lưu.',
    'deleted' => '🗑️ Danh mục đã xóa.',
    'error'   => '❌ ' . sanitize_text_field( $_GET['error_msg'] ?? 'Lỗi không xác định.' ),
);
?>

<div class="wrap bztimg-categories-wrap">
    <h1>📁 Template Categories</h1>

    <?php if ( isset( $messages[ $msg_key ] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $msg_key ] ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;gap:24px;margin-top:16px;">
        <!-- Add/Edit Form -->
        <div style="flex:0 0 350px;">
            <div class="bztimg-card">
                <h3><?php echo $edit_cat ? '✏️ Sửa danh mục' : '➕ Thêm danh mục'; ?></h3>
                <form method="post">
                    <?php wp_nonce_field( 'bztimg_save_category', 'bztimg_cat_nonce' ); ?>
                    <input type="hidden" name="category_id" value="<?php echo esc_attr( $edit_cat['id'] ?? 0 ); ?>" />

                    <p>
                        <label><strong>Tên *</strong></label><br/>
                        <input type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $edit_cat['name'] ?? '' ); ?>" />
                    </p>
                    <p>
                        <label><strong>Slug *</strong></label><br/>
                        <input type="text" name="slug" class="regular-text" required value="<?php echo esc_attr( $edit_cat['slug'] ?? '' ); ?>" <?php echo $edit_cat ? 'readonly' : ''; ?> />
                    </p>
                    <p>
                        <label><strong>Emoji Icon</strong></label><br/>
                        <input type="text" name="icon_emoji" style="width:60px;" value="<?php echo esc_attr( $edit_cat['icon_emoji'] ?? '' ); ?>" placeholder="🎨" />
                    </p>
                    <p>
                        <label><strong>Mô tả</strong></label><br/>
                        <textarea name="description" rows="2" class="large-text"><?php echo esc_textarea( $edit_cat['description'] ?? '' ); ?></textarea>
                    </p>
                    <p>
                        <label><strong>Thứ tự</strong></label><br/>
                        <input type="number" name="sort_order" value="<?php echo esc_attr( $edit_cat['sort_order'] ?? 0 ); ?>" style="width:80px;" />
                    </p>
                    <p>
                        <label><strong>Trạng thái</strong></label><br/>
                        <select name="status">
                            <option value="active" <?php selected( $edit_cat['status'] ?? 'active', 'active' ); ?>>🟢 Active</option>
                            <option value="draft" <?php selected( $edit_cat['status'] ?? '', 'draft' ); ?>>📝 Draft</option>
                        </select>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">💾 Lưu</button>
                        <?php if ( $edit_cat ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-categories' ) ); ?>" class="button">Hủy</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>

        <!-- List -->
        <div style="flex:1;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:40px">Icon</th>
                        <th>Tên</th>
                        <th>Slug</th>
                        <th style="width:80px">Templates</th>
                        <th>Trạng thái</th>
                        <th style="width:120px">Thao tác</th>
                    </tr>
                </thead>
                <tbody id="bztimg-cat-sortable">
                    <?php foreach ( $categories as $cat ) :
                        $tpl_count  = BizCity_Template_Category_Manager::count_templates( $cat['id'] );
                        $edit_url   = admin_url( 'admin.php?page=bztimg-categories&action=edit&cat_id=' . $cat['id'] );
                        $delete_url = wp_nonce_url( admin_url( 'admin.php?page=bztimg-categories&action=delete&cat_id=' . $cat['id'] ), 'bztimg_delete_cat_' . $cat['id'] );
                    ?>
                    <tr data-id="<?php echo esc_attr( $cat['id'] ); ?>">
                        <td><?php echo esc_html( $cat['sort_order'] ); ?></td>
                        <td style="font-size:20px;"><?php echo esc_html( $cat['icon_emoji'] ); ?></td>
                        <td><strong><?php echo esc_html( $cat['name'] ); ?></strong></td>
                        <td><code><?php echo esc_html( $cat['slug'] ); ?></code></td>
                        <td style="text-align:center;"><?php echo esc_html( $tpl_count ); ?></td>
                        <td>
                            <?php if ( $cat['status'] === 'active' ) : ?>
                                <span style="color:#059669;">🟢 Active</span>
                            <?php else : ?>
                                <span style="color:#9ca3af;">📝 Draft</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️</a>
                            <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('Xóa danh mục này?');">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
