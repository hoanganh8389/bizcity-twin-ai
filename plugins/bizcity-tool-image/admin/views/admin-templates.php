<?php
/**
 * Admin Templates Page — List / Add / Edit image templates.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$action      = sanitize_text_field( $_GET['action'] ?? 'list' );
$template_id = absint( $_GET['template_id'] ?? 0 );
$categories  = BizCity_Template_Category_Manager::get_all( array( 'status' => 'active' ) );

/* Handle form save via POST */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['bztimg_tpl_nonce'] ) ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bztimg_tpl_nonce'] ) ), 'bztimg_save_template' ) ) {
        wp_die( 'Security check failed.' );
    }

    $post_data = array(
        'title'             => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
        'slug'              => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
        'category_id'       => absint( $_POST['category_id'] ?? 0 ),
        'subcategory'       => sanitize_text_field( wp_unslash( $_POST['subcategory'] ?? '' ) ),
        'description'       => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
        'thumbnail_url'     => esc_url_raw( wp_unslash( $_POST['thumbnail_url'] ?? '' ) ),
        'prompt_template'   => wp_kses_post( wp_unslash( $_POST['prompt_template'] ?? '' ) ),
        'negative_prompt'   => wp_kses_post( wp_unslash( $_POST['negative_prompt'] ?? '' ) ),
        'form_fields'       => wp_unslash( $_POST['form_fields_json'] ?? '[]' ),
        'recommended_model' => sanitize_text_field( wp_unslash( $_POST['recommended_model'] ?? 'flux-pro' ) ),
        'recommended_size'  => sanitize_text_field( wp_unslash( $_POST['recommended_size'] ?? '1024x1024' ) ),
        'style'             => sanitize_text_field( wp_unslash( $_POST['style'] ?? 'auto' ) ),
        'num_outputs'       => absint( $_POST['num_outputs'] ?? 1 ),
        'tags'              => sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) ),
        'badge_text'        => sanitize_text_field( wp_unslash( $_POST['badge_text'] ?? '' ) ),
        'badge_color'       => empty( $_POST['badge_text'] ) ? '' : ( sanitize_hex_color( wp_unslash( $_POST['badge_color'] ?? '' ) ) ?: '' ),
        'is_featured'       => ! empty( $_POST['is_featured'] ),
        'sort_order'        => intval( $_POST['sort_order'] ?? 0 ),
        'status'            => in_array( ( $_POST['status'] ?? 'active' ), array( 'active', 'draft' ), true ) ? $_POST['status'] : 'active',
    );

    if ( $template_id ) {
        $result = BizCity_Template_Manager::update( $template_id, $post_data );
        $msg    = is_wp_error( $result ) ? 'error' : 'updated';
    } else {
        $result = BizCity_Template_Manager::insert( $post_data );
        $msg    = is_wp_error( $result ) ? 'error' : 'created';
        if ( ! is_wp_error( $result ) ) {
            $template_id = $result;
        }
    }

    if ( $msg !== 'error' ) {
        $redirect = admin_url( 'admin.php?page=bztimg-templates&message=' . $msg );
        echo '<script>window.location="' . esc_url( $redirect ) . '";</script>';
        return;
    }
}

/* Handle delete */
if ( $action === 'delete' && $template_id ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'bztimg_delete_' . $template_id ) ) {
        wp_die( 'Security check failed.' );
    }
    BizCity_Template_Manager::delete( $template_id );
    echo '<script>window.location="' . esc_url( admin_url( 'admin.php?page=bztimg-templates&message=deleted' ) ) . '";</script>';
    return;
}

/* Handle duplicate */
if ( $action === 'duplicate' && $template_id ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'bztimg_duplicate_' . $template_id ) ) {
        wp_die( 'Security check failed.' );
    }
    BizCity_Template_Manager::duplicate( $template_id );
    echo '<script>window.location="' . esc_url( admin_url( 'admin.php?page=bztimg-templates&message=duplicated' ) ) . '";</script>';
    return;
}

/* Messages */
$messages = array(
    'created'    => '✅ Template đã được tạo.',
    'updated'    => '✅ Template đã được cập nhật.',
    'deleted'    => '🗑️ Template đã xóa.',
    'duplicated' => '📋 Template đã nhân bản.',
    'error'      => '❌ Có lỗi xảy ra.',
);
$msg_key = sanitize_text_field( $_GET['message'] ?? '' );
?>

<div class="wrap bztimg-templates-wrap">

<?php if ( isset( $messages[ $msg_key ] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $msg_key ] ); ?></p></div>
<?php endif; ?>

<?php if ( $action === 'add' || $action === 'edit' ) : ?>
    <?php
    $tpl = $template_id ? BizCity_Template_Manager::get_by_id( $template_id ) : null;
    $is_edit = (bool) $tpl;
    ?>
    <h1><?php echo $is_edit ? '✏️ Chỉnh sửa Template' : '➕ Thêm Template mới'; ?></h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-templates' ) ); ?>" class="page-title-action">← Quay lại</a>

    <form method="post" class="bztimg-tpl-form" style="margin-top:16px;">
        <?php wp_nonce_field( 'bztimg_save_template', 'bztimg_tpl_nonce' ); ?>
        <input type="hidden" name="template_id" value="<?php echo esc_attr( $template_id ); ?>" />

        <div class="bztimg-form-grid">
            <!-- Left column: Main content -->
            <div class="bztimg-form-main">
                <div class="bztimg-card">
                    <h3>📝 Thông tin cơ bản</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="title">Tiêu đề *</label></th>
                            <td><input type="text" id="title" name="title" class="regular-text" required value="<?php echo esc_attr( $tpl['title'] ?? '' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="slug">Slug</label></th>
                            <td><input type="text" id="slug" name="slug" class="regular-text" value="<?php echo esc_attr( $tpl['slug'] ?? '' ); ?>" placeholder="auto-generated" /></td>
                        </tr>
                        <tr>
                            <th><label for="category_id">Danh mục</label></th>
                            <td>
                                <select id="category_id" name="category_id">
                                    <option value="0">— Chọn danh mục —</option>
                                    <?php foreach ( $categories as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat['id'] ); ?>" <?php selected( $tpl['category_id'] ?? 0, $cat['id'] ); ?>>
                                            <?php echo esc_html( $cat['icon_emoji'] . ' ' . $cat['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="description">Mô tả</label></th>
                            <td><textarea id="description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $tpl['description'] ?? '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="tags">Tags</label></th>
                            <td><input type="text" id="tags" name="tags" class="regular-text" value="<?php echo esc_attr( $tpl['tags'] ?? '' ); ?>" placeholder="tag1,tag2,tag3" /></td>
                        </tr>
                    </table>
                </div>

                <div class="bztimg-card">
                    <h3>🤖 Prompt Template</h3>
                    <p class="description">Sử dụng <code>{{field_slug}}</code> cho biến thay thế từ form fields.</p>
                    <table class="form-table">
                        <tr>
                            <th><label for="prompt_template">Prompt *</label></th>
                            <td><textarea id="prompt_template" name="prompt_template" rows="6" class="large-text" required><?php echo esc_textarea( $tpl['prompt_template'] ?? '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="negative_prompt">Negative Prompt</label></th>
                            <td><textarea id="negative_prompt" name="negative_prompt" rows="3" class="large-text"><?php echo esc_textarea( $tpl['negative_prompt'] ?? '' ); ?></textarea></td>
                        </tr>
                    </table>
                </div>

                <div class="bztimg-card">
                    <h3>📋 Form Fields Builder</h3>
                    <p class="description" style="margin-bottom:12px;">
                        Xây dựng form các trường user cần điền. Slug phải trùng với <code>{{slug}}</code> trong prompt template.
                    </p>
                    <div id="bztimg-form-builder"></div>
                    <input type="hidden" id="form_fields_json" name="form_fields_json" value="<?php echo esc_attr( wp_json_encode( $tpl['form_fields'] ?? array() ) ); ?>" />
                </div>
            </div>

            <!-- Right column: Settings -->
            <div class="bztimg-form-sidebar">
                <div class="bztimg-card">
                    <h3>📸 Thumbnail</h3>
                    <div id="bztimg-thumbnail-preview">
                        <?php if ( ! empty( $tpl['thumbnail_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $tpl['thumbnail_url'] ); ?>" style="max-width:100%;border-radius:8px;" />
                        <?php endif; ?>
                    </div>
                    <input type="hidden" id="thumbnail_url" name="thumbnail_url" value="<?php echo esc_attr( $tpl['thumbnail_url'] ?? '' ); ?>" />
                    <button type="button" class="button" id="bztimg-upload-thumbnail" style="margin-top:8px;">📷 Chọn ảnh</button>
                </div>

                <div class="bztimg-card">
                    <h3>⚙️ Cài đặt AI</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="recommended_model">Model</label></th>
                            <td>
                                <select id="recommended_model" name="recommended_model">
                                    <?php
                                    $models = class_exists( 'BizCity_Tool_Image' ) ? array_keys( BizCity_Tool_Image::MODELS ) : array( 'flux-pro' );
                                    foreach ( $models as $m ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $tpl['recommended_model'] ?? 'flux-pro', $m ); ?>><?php echo esc_html( $m ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="recommended_size">Size</label></th>
                            <td>
                                <select id="recommended_size" name="recommended_size">
                                    <?php
                                    $sizes = array( '1024x1024', '1024x1792', '1792x1024', '768x1024', '1024x768' );
                                    foreach ( $sizes as $s ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $tpl['recommended_size'] ?? '1024x1024', $s ); ?>><?php echo esc_html( $s ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="style">Style</label></th>
                            <td>
                                <select id="style" name="style">
                                    <?php
                                    $styles = array( 'auto', 'photorealistic', 'artistic', 'anime', 'illustration', 'sketch' );
                                    foreach ( $styles as $st ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $tpl['style'] ?? 'auto', $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="num_outputs">Số ảnh</label></th>
                            <td><input type="number" id="num_outputs" name="num_outputs" min="1" max="8" value="<?php echo esc_attr( $tpl['num_outputs'] ?? 1 ); ?>" /></td>
                        </tr>
                    </table>
                </div>

                <div class="bztimg-card">
                    <h3>🏷️ Hiển thị</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="subcategory">Loại</label></th>
                            <td>
                                <select id="subcategory" name="subcategory">
                                    <option value="" <?php selected( $tpl['subcategory'] ?? '', '' ); ?>>— Không —</option>
                                    <option value="product" <?php selected( $tpl['subcategory'] ?? '', 'product' ); ?>>📦 Product (Parent)</option>
                                    <option value="model" <?php selected( $tpl['subcategory'] ?? '', 'model' ); ?>>👤 Model</option>
                                    <option value="clothing" <?php selected( $tpl['subcategory'] ?? '', 'clothing' ); ?>>👔 Clothing</option>
                                    <option value="accessory" <?php selected( $tpl['subcategory'] ?? '', 'accessory' ); ?>>💍 Accessory</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="status">Trạng thái</label></th>
                            <td>
                                <select id="status" name="status">
                                    <option value="active" <?php selected( $tpl['status'] ?? 'active', 'active' ); ?>>🟢 Active</option>
                                    <option value="draft" <?php selected( $tpl['status'] ?? '', 'draft' ); ?>>📝 Draft</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="is_featured">Nổi bật</label></th>
                            <td><label><input type="checkbox" id="is_featured" name="is_featured" value="1" <?php checked( $tpl['is_featured'] ?? 0 ); ?>> Hiển thị nổi bật</label></td>
                        </tr>
                        <tr>
                            <th><label for="sort_order">Thứ tự</label></th>
                            <td><input type="number" id="sort_order" name="sort_order" value="<?php echo esc_attr( $tpl['sort_order'] ?? 0 ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="badge_text">Badge</label></th>
                            <td>
                                <input type="text" id="badge_text" name="badge_text" value="<?php echo esc_attr( $tpl['badge_text'] ?? '' ); ?>" placeholder="Hot, New, Beta..." />
                                <input type="color" id="badge_color" name="badge_color" value="<?php echo esc_attr( ( $tpl['badge_color'] ?? '' ) ?: '#3b82f6' ); ?>" style="vertical-align:middle;<?php echo empty( $tpl['badge_text'] ) ? 'display:none;' : ''; ?>" />
                                <script>document.getElementById('badge_text').addEventListener('input', function() { document.getElementById('badge_color').style.display = this.value ? '' : 'none'; });</script>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="bztimg-card" style="text-align:center;">
                    <button type="submit" class="button button-primary button-large" style="width:100%;">💾 Lưu Template</button>
                </div>
            </div>
        </div>
    </form>

    <?php /* ── Child Templates Gallery ── */
    if ( $is_edit && ! empty( $tpl['category_id'] ) && ( $tpl['subcategory'] ?? '' ) === 'product' ) :
        $children = BizCity_Template_Manager::get_all( array(
            'category_id' => $tpl['category_id'],
            'status'      => 'active',
            'per_page'    => 100,
        ) );
        $children = array_filter( $children, function( $c ) use ( $tpl ) {
            return (int) $c['id'] !== (int) $tpl['id'] && ( $c['subcategory'] ?? '' ) !== 'product';
        } );
        if ( ! empty( $children ) ) :
            $groups = array();
            foreach ( $children as $c ) {
                $sub = $c['subcategory'] ?: 'other';
                $groups[ $sub ][] = $c;
            }
            $sub_labels = array(
                'model'     => '👤 Người mẫu AI',
                'clothing'  => '👔 Trang phục',
                'accessory' => '💍 Phụ kiện',
                'other'     => '📦 Khác',
            );
    ?>
    <div class="bztimg-child-gallery" style="margin-top:24px;">
        <?php foreach ( $groups as $sub_key => $items ) : ?>
            <div class="bztimg-card">
                <h3><?php echo esc_html( $sub_labels[ $sub_key ] ?? ucfirst( $sub_key ) ); ?>
                    <span style="color:#6b7280;font-weight:normal;">(<?php echo count( $items ); ?>)</span>
                </h3>
                <div class="bztimg-child-grid">
                    <?php foreach ( $items as $child ) :
                        $child_edit = admin_url( 'admin.php?page=bztimg-templates&action=edit&template_id=' . $child['id'] );
                    ?>
                    <a href="<?php echo esc_url( $child_edit ); ?>" class="bztimg-child-card">
                        <?php if ( $child['thumbnail_url'] ) : ?>
                            <img src="<?php echo esc_url( $child['thumbnail_url'] ); ?>" alt="" />
                        <?php else : ?>
                            <span class="bztimg-child-placeholder">📸</span>
                        <?php endif; ?>
                        <span class="bztimg-child-title"><?php echo esc_html( $child['title'] ); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; endif; ?>

<?php else : ?>
    <?php /* ── LIST VIEW ── */ ?>
    <h1>
        🎨 Image Templates
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-templates&action=add' ) ); ?>" class="page-title-action">➕ Thêm mới</a>
    </h1>

    <?php
    $filter_cat    = sanitize_text_field( $_GET['filter_category'] ?? '' );
    $filter_status = sanitize_text_field( $_GET['filter_status'] ?? '' );
    $search        = sanitize_text_field( $_GET['s'] ?? '' );
    $current_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );

    $list_args = array(
        'per_page' => 20,
        'page'     => $current_page,
    );
    if ( $filter_cat )    $list_args['category_id'] = absint( $filter_cat );
    if ( $filter_status ) $list_args['status']      = $filter_status;
    if ( ! empty( $_GET['filter_subcategory'] ) ) $list_args['subcategory'] = sanitize_text_field( $_GET['filter_subcategory'] );
    if ( $search )        $list_args['search']       = $search;

    $templates   = BizCity_Template_Manager::get_all( $list_args );
    $total_count = BizCity_Template_Manager::count( $list_args );
    $total_pages = ceil( $total_count / 20 );
    ?>

    <!-- Filters -->
    <form method="get" class="bztimg-filters" style="display:flex;gap:8px;align-items:center;margin:16px 0;">
        <input type="hidden" name="page" value="bztimg-templates" />
        <select name="filter_category">
            <option value="">Tất cả danh mục</option>
            <?php foreach ( $categories as $cat ) : ?>
                <option value="<?php echo esc_attr( $cat['id'] ); ?>" <?php selected( $filter_cat, $cat['id'] ); ?>>
                    <?php echo esc_html( $cat['icon_emoji'] . ' ' . $cat['name'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="filter_status">
            <option value="">Tất cả trạng thái</option>
            <option value="active" <?php selected( $filter_status, 'active' ); ?>>🟢 Active</option>
            <option value="draft" <?php selected( $filter_status, 'draft' ); ?>>📝 Draft</option>
        </select>
        <?php $filter_sub = sanitize_text_field( $_GET['filter_subcategory'] ?? '' ); ?>
        <select name="filter_subcategory">
            <option value="">Tất cả loại</option>
            <option value="product" <?php selected( $filter_sub, 'product' ); ?>>📦 Product</option>
            <option value="model" <?php selected( $filter_sub, 'model' ); ?>>👤 Model</option>
            <option value="clothing" <?php selected( $filter_sub, 'clothing' ); ?>>👔 Clothing</option>
            <option value="accessory" <?php selected( $filter_sub, 'accessory' ); ?>>💍 Accessory</option>
        </select>
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm kiếm..." />
        <button type="submit" class="button">🔍 Lọc</button>
    </form>

    <!-- Import/Export -->
    <div style="margin-bottom:16px;display:flex;gap:8px;">
        <a href="<?php echo esc_url( rest_url( 'bztool-image/v1/templates/export' ) ); ?>" class="button" target="_blank">📦 Export JSON</a>
        <button type="button" class="button" id="bztimg-import-btn">📥 Import JSON</button>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:60px">Ảnh</th>
                <th>Tiêu đề</th>
                <th>Danh mục</th>
                <th>Loại</th>
                <th>Model</th>
                <th>Trạng thái</th>
                <th style="width:80px">Lượt dùng</th>
                <th style="width:180px">Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $templates ) ) : ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;">Chưa có template nào. <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-templates&action=add' ) ); ?>">Tạo template đầu tiên →</a></td></tr>
            <?php else : ?>
                <?php foreach ( $templates as $tpl ) :
                    $cat = BizCity_Template_Category_Manager::get_by_id( $tpl['category_id'] );
                    $edit_url = admin_url( 'admin.php?page=bztimg-templates&action=edit&template_id=' . $tpl['id'] );
                    $delete_url = wp_nonce_url( admin_url( 'admin.php?page=bztimg-templates&action=delete&template_id=' . $tpl['id'] ), 'bztimg_delete_' . $tpl['id'] );
                    $dup_url = wp_nonce_url( admin_url( 'admin.php?page=bztimg-templates&action=duplicate&template_id=' . $tpl['id'] ), 'bztimg_duplicate_' . $tpl['id'] );
                ?>
                <tr>
                    <td>
                        <?php if ( $tpl['thumbnail_url'] ) : ?>
                            <img src="<?php echo esc_url( $tpl['thumbnail_url'] ); ?>" style="width:50px;height:50px;object-fit:cover;border-radius:6px;" />
                        <?php else : ?>
                            <span style="display:inline-block;width:50px;height:50px;background:#f3f4f6;border-radius:6px;line-height:50px;text-align:center;">📸</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $tpl['title'] ); ?></a></strong>
                        <?php if ( $tpl['badge_text'] ) : ?>
                            <span style="background:<?php echo esc_attr( $tpl['badge_color'] ?: '#3b82f6' ); ?>;color:#fff;padding:1px 6px;border-radius:4px;font-size:11px;"><?php echo esc_html( $tpl['badge_text'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $tpl['is_featured'] ) echo ' ⭐'; ?>
                        <br><small style="color:#6b7280;"><?php echo esc_html( $tpl['slug'] ); ?></small>
                    </td>
                    <td><?php echo esc_html( $cat ? $cat['icon_emoji'] . ' ' . $cat['name'] : '—' ); ?></td>
                    <td>
                        <?php
                        $sub_icons = array( 'product' => '📦', 'model' => '👤', 'clothing' => '👔', 'accessory' => '💍' );
                        $sub_val = $tpl['subcategory'] ?? '';
                        echo $sub_val ? esc_html( ( $sub_icons[ $sub_val ] ?? '' ) . ' ' . ucfirst( $sub_val ) ) : '<span style="color:#9ca3af;">—</span>';
                        ?>
                    </td>
                    <td><code><?php echo esc_html( $tpl['recommended_model'] ); ?></code></td>
                    <td>
                        <?php if ( $tpl['status'] === 'active' ) : ?>
                            <span style="color:#059669;">🟢 Active</span>
                        <?php else : ?>
                            <span style="color:#9ca3af;">📝 Draft</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?php echo esc_html( $tpl['use_count'] ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️</a>
                        <a href="<?php echo esc_url( $dup_url ); ?>" class="button button-small" title="Nhân bản">📋</a>
                        <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('Xóa template này?');" title="Xóa">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $current_page,
                    'total'   => $total_pages,
                ) ) );
                ?>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

</div>

<!-- Import Modal -->
<div id="bztimg-import-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);justify-content:center;align-items:center;">
    <div style="background:#fff;padding:24px;border-radius:12px;max-width:500px;width:90%;">
        <h3>📥 Import Templates</h3>
        <textarea id="bztimg-import-json" rows="10" style="width:100%;font-family:monospace;font-size:12px;" placeholder="Paste JSON data here..."></textarea>
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="button" onclick="document.getElementById('bztimg-import-modal').style.display='none'">Hủy</button>
            <button type="button" class="button button-primary" id="bztimg-import-submit">Import</button>
        </div>
    </div>
</div>
