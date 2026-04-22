<?php
/**
 * BizCity Tool Image — Admin Profile Templates
 *
 * CRUD management for profile studio templates (face-swap style references).
 * Categories: all, man, woman, professional, creative
 *
 * @package BizCity_Tool_Image
 * @since   3.8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

global $wpdb;
$table = $wpdb->prefix . 'bztimg_profile_templates';

/* ── Handle form submissions ── */
$message = '';
$error   = '';

// Create table if not exists
BizCity_Profile_Studio_Page::create_tables();

// DELETE
if ( isset( $_GET['delete'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bztimg_delete_profile_tpl' ) ) {
    $del_id = absint( $_GET['delete'] );
    $wpdb->delete( $table, array( 'id' => $del_id ), array( '%d' ) );
    $message = 'Template đã được xóa.';
}

// AUTOSEED — one-click import from seed file
if ( isset( $_GET['autoseed'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bztimg_autoseed_profile' ) ) {
    $seed_path = BZTIMG_DIR . 'data/profile-templates-seed.json';
    if ( ! file_exists( $seed_path ) ) {
        $error = 'File seed không tồn tại: data/profile-templates-seed.json';
    } else {
        $items = json_decode( file_get_contents( $seed_path ), true );
        if ( ! is_array( $items ) ) {
            $error = 'JSON không hợp lệ.';
        } else {
            $imported = 0;
            $skipped  = 0;
            foreach ( $items as $idx => $item ) {
                $title = sanitize_text_field( $item['title'] ?? '' );
                $thumb = esc_url_raw( $item['thumbnail_url'] ?? '' );
                if ( ! $title || ! $thumb ) continue;

                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE title = %s AND thumbnail_url = %s",
                    $title, $thumb
                ) );
                if ( $exists ) { $skipped++; continue; }

                $wpdb->insert( $table, array(
                    'title'         => $title,
                    'thumbnail_url' => $thumb,
                    'reference_url' => esc_url_raw( $item['reference_url'] ?? '' ) ?: $thumb,
                    'category'      => sanitize_key( $item['category'] ?? 'all' ),
                    'style_prompt'  => sanitize_textarea_field( $item['style_prompt'] ?? '' ),
                    'sort_order'    => intval( $item['sort_order'] ?? $idx ),
                    'status'        => in_array( $item['status'] ?? '', array( 'active', 'draft' ), true ) ? $item['status'] : 'active',
                ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
                $imported++;
            }
            $message = "Auto-seed thành công: {$imported} templates mới.";
            if ( $skipped ) $message .= " Bỏ qua {$skipped} đã tồn tại.";
        }
    }
}

// IMPORT JSON (upload file)
if ( isset( $_POST['bztimg_import_profile_tpl'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bztimg_import_profile_tpl' ) ) {
    $json_raw = '';
    if ( ! empty( $_FILES['import_file']['tmp_name'] ) ) {
        $json_raw = file_get_contents( $_FILES['import_file']['tmp_name'] );
    } else {
        $error = 'Vui lòng chọn file JSON để upload.';
    }

    if ( $json_raw && ! $error ) {
        $items = json_decode( $json_raw, true );
        if ( ! is_array( $items ) ) {
            $error = 'JSON không hợp lệ.';
        } else {
            $skip_existing = ! empty( $_POST['skip_existing'] );
            $imported = 0;
            $skipped  = 0;
            foreach ( $items as $idx => $item ) {
                $title = sanitize_text_field( $item['title'] ?? '' );
                $thumb = esc_url_raw( $item['thumbnail_url'] ?? '' );
                if ( ! $title || ! $thumb ) continue;

                if ( $skip_existing ) {
                    $exists = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE title = %s AND thumbnail_url = %s",
                        $title, $thumb
                    ) );
                    if ( $exists ) { $skipped++; continue; }
                }

                $wpdb->insert( $table, array(
                    'title'         => $title,
                    'thumbnail_url' => $thumb,
                    'reference_url' => esc_url_raw( $item['reference_url'] ?? '' ) ?: $thumb,
                    'category'      => sanitize_key( $item['category'] ?? 'all' ),
                    'style_prompt'  => sanitize_textarea_field( $item['style_prompt'] ?? '' ),
                    'sort_order'    => intval( $item['sort_order'] ?? $idx ),
                    'status'        => in_array( $item['status'] ?? '', array( 'active', 'draft' ), true ) ? $item['status'] : 'active',
                ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
                $imported++;
            }
            $message = "Import thành công: {$imported} templates.";
            if ( $skipped ) $message .= " Bỏ qua {$skipped} đã tồn tại.";
        }
    }
}

// SAVE (create or update)
if ( isset( $_POST['bztimg_save_profile_tpl'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bztimg_save_profile_tpl' ) ) {
    $id            = absint( $_POST['tpl_id'] ?? 0 );
    $title         = sanitize_text_field( $_POST['tpl_title'] ?? '' );
    $thumbnail_url = esc_url_raw( $_POST['tpl_thumbnail_url'] ?? '' );
    $reference_url = esc_url_raw( $_POST['tpl_reference_url'] ?? '' );
    $category      = sanitize_key( $_POST['tpl_category'] ?? 'all' );
    $style_prompt  = sanitize_textarea_field( $_POST['tpl_style_prompt'] ?? '' );
    $sort_order    = intval( $_POST['tpl_sort_order'] ?? 0 );
    $status        = in_array( $_POST['tpl_status'] ?? '', array( 'active', 'draft' ), true ) ? $_POST['tpl_status'] : 'active';

    if ( empty( $title ) || empty( $thumbnail_url ) ) {
        $error = 'Tiêu đề và ảnh thumbnail là bắt buộc.';
    } else {
        $data = array(
            'title'         => $title,
            'thumbnail_url' => $thumbnail_url,
            'reference_url' => $reference_url ?: $thumbnail_url,
            'category'      => $category,
            'style_prompt'  => $style_prompt,
            'sort_order'    => $sort_order,
            'status'        => $status,
        );
        $formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

        if ( $id ) {
            $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
            $message = 'Template đã được cập nhật.';
        } else {
            $wpdb->insert( $table, $data, $formats );
            $message = 'Template mới đã được tạo.';
        }
    }
}

/* ── Edit mode ── */
$edit_tpl = null;
if ( isset( $_GET['edit'] ) ) {
    $edit_id = absint( $_GET['edit'] );
    $edit_tpl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A );
}

/* ── List templates ── */
$filter_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';
$where = '';
$params = array();
if ( $filter_cat && $filter_cat !== 'all' ) {
    $where = "WHERE category = %s";
    $params[] = $filter_cat;
}

if ( ! empty( $params ) ) {
    $templates = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id DESC",
        ...$params
    ), ARRAY_A );
} else {
    $templates = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY sort_order ASC, id DESC",
        ARRAY_A
    );
}

$categories = array(
    'all'          => 'Tất cả',
    'man'          => 'Đàn ông',
    'woman'        => 'Phụ nữ',
    'professional' => 'Chuyên nghiệp',
    'creative'     => 'Sáng tạo',
);
?>
<style>
.bztimg-pf-form label.bztimg-lbl { display:block; font-weight:600; font-size:13px; margin:12px 0 4px; }
.bztimg-pf-form label.bztimg-lbl:first-child { margin-top:0; }
.bztimg-pf-form input[type=text],
.bztimg-pf-form input[type=url],
.bztimg-pf-form input[type=number],
.bztimg-pf-form select,
.bztimg-pf-form textarea { width:100%; box-sizing:border-box; }
.bztimg-pf-form textarea { min-height:60px; }
.bztimg-pf-form .bztimg-row-inline { display:flex; gap:8px; align-items:center; }
.bztimg-pf-form .bztimg-row-inline input { flex:1; }
.bztimg-pf-form .bztimg-row-inline .button { flex-shrink:0; white-space:nowrap; }
.bztimg-card { background:#fff; padding:20px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:16px; }
.bztimg-card h2 { margin-top:0; font-size:15px; }
</style>
<div class="wrap">
    <h1>🎨 Profile Studio — Templates</h1>
    <p class="description">Quản lý templates cho tính năng face-swap / sao chép phong cách tại <code>/profile-studio/</code></p>

    <?php if ( $message ): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error ): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;gap:24px;margin-top:20px;">
        <!-- LEFT: Form + Import -->
        <div style="width:400px;flex-shrink:0;">

            <!-- Auto Seed -->
            <div class="bztimg-card" style="background:linear-gradient(135deg,#f0fdf4,#ecfeff);border-color:#86efac;">
                <h2>🌱 Auto Seed</h2>
                <p style="margin:0 0 12px;font-size:13px;color:#374151;">Nạp nhanh <?php
                    $seed_file = BZTIMG_DIR . 'data/profile-templates-seed.json';
                    $seed_count = 0;
                    if ( file_exists( $seed_file ) ) {
                        $seed_data = json_decode( file_get_contents( $seed_file ), true );
                        $seed_count = is_array( $seed_data ) ? count( $seed_data ) : 0;
                    }
                    echo '<strong>' . $seed_count . '</strong>';
                ?> templates có sẵn (bỏ qua trùng lặp).</p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bztimg-profile-templates&autoseed=1' ), 'bztimg_autoseed_profile' ) ); ?>"
                   class="button button-primary"
                   onclick="return confirm('Nạp tất cả templates từ file seed?')"
                   style="background:#16a34a;border-color:#16a34a;">🌱 Auto Seed Templates</a>
            </div>

            <!-- Add/Edit Form -->
            <div class="bztimg-card">
                <h2><?php echo $edit_tpl ? '✏️ Sửa Template' : '➕ Thêm Template Mới'; ?></h2>
                <form method="post" class="bztimg-pf-form">
                    <?php wp_nonce_field( 'bztimg_save_profile_tpl' ); ?>
                    <input type="hidden" name="tpl_id" value="<?php echo esc_attr( $edit_tpl['id'] ?? '' ); ?>">

                    <label class="bztimg-lbl" for="tpl_title">Tiêu đề *</label>
                    <input type="text" id="tpl_title" name="tpl_title" value="<?php echo esc_attr( $edit_tpl['title'] ?? '' ); ?>" required>

                    <label class="bztimg-lbl" for="tpl_category">Danh mục</label>
                    <select id="tpl_category" name="tpl_category">
                        <?php foreach ( $categories as $key => $label ): ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $edit_tpl['category'] ?? 'all', $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="bztimg-lbl" for="tpl_thumbnail_url">Thumbnail URL *</label>
                    <div class="bztimg-row-inline">
                        <input type="url" id="tpl_thumbnail_url" name="tpl_thumbnail_url" value="<?php echo esc_attr( $edit_tpl['thumbnail_url'] ?? '' ); ?>" required>
                        <button type="button" class="button bztimg-media-pick" data-target="tpl_thumbnail_url">📷</button>
                    </div>
                    <div id="tpl_thumbnail_url_preview" style="margin-top:6px;">
                        <?php if ( ! empty( $edit_tpl['thumbnail_url'] ) ): ?>
                            <img src="<?php echo esc_url( $edit_tpl['thumbnail_url'] ); ?>" style="max-width:120px;border-radius:6px;">
                        <?php endif; ?>
                    </div>

                    <label class="bztimg-lbl" for="tpl_reference_url">Reference URL</label>
                    <div class="bztimg-row-inline">
                        <input type="url" id="tpl_reference_url" name="tpl_reference_url" value="<?php echo esc_attr( $edit_tpl['reference_url'] ?? '' ); ?>">
                        <button type="button" class="button bztimg-media-pick" data-target="tpl_reference_url">📷</button>
                    </div>
                    <p class="description" style="margin-top:2px;">Ảnh gốc cho AI. Trống = dùng thumbnail.</p>

                    <label class="bztimg-lbl" for="tpl_style_prompt">Style Prompt</label>
                    <textarea id="tpl_style_prompt" name="tpl_style_prompt" rows="3"><?php echo esc_textarea( $edit_tpl['style_prompt'] ?? '' ); ?></textarea>

                    <div style="display:flex;gap:16px;margin-top:12px;">
                        <div style="flex:1;">
                            <label class="bztimg-lbl" for="tpl_sort_order">Thứ tự</label>
                            <input type="number" id="tpl_sort_order" name="tpl_sort_order" value="<?php echo esc_attr( $edit_tpl['sort_order'] ?? 0 ); ?>" style="width:80px;">
                        </div>
                        <div style="flex:1;">
                            <label class="bztimg-lbl" for="tpl_status">Trạng thái</label>
                            <select id="tpl_status" name="tpl_status">
                                <option value="active" <?php selected( $edit_tpl['status'] ?? 'active', 'active' ); ?>>Active</option>
                                <option value="draft" <?php selected( $edit_tpl['status'] ?? '', 'draft' ); ?>>Draft</option>
                            </select>
                        </div>
                    </div>

                    <p style="margin-top:16px;">
                        <button type="submit" name="bztimg_save_profile_tpl" class="button button-primary">💾 Lưu Template</button>
                        <?php if ( $edit_tpl ): ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-profile-templates' ) ); ?>" class="button">Hủy</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- Import JSON Upload -->
            <div class="bztimg-card">
                <h2>📥 Import từ file JSON</h2>
                <form method="post" enctype="multipart/form-data" class="bztimg-pf-form">
                    <?php wp_nonce_field( 'bztimg_import_profile_tpl' ); ?>
                    <input type="file" name="import_file" accept=".json" style="margin-bottom:8px;">
                    <label style="display:block;margin:6px 0;">
                        <input type="checkbox" name="skip_existing" value="1" checked> Bỏ qua trùng lặp
                    </label>
                    <button type="submit" name="bztimg_import_profile_tpl" class="button" onclick="return confirm('Import templates từ file JSON?')">📥 Import</button>
                </form>
            </div>
        </div>

        <!-- RIGHT: List -->
        <div style="flex:1;min-width:0;">
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                    <h2 style="margin:0;">📋 Danh sách Templates (<?php echo count( $templates ?: array() ); ?>)</h2>
                    <div>
                        <?php foreach ( array_merge( array( '' => 'Tất cả' ), $categories ) as $key => $label ): ?>
                            <?php if ( $key === 'all' ) continue; ?>
                            <a href="<?php echo esc_url( add_query_arg( 'cat', $key, admin_url( 'admin.php?page=bztimg-profile-templates' ) ) ); ?>"
                               class="button <?php echo $filter_cat === $key ? 'button-primary' : ''; ?>"
                               style="font-size:12px;padding:2px 8px;min-height:28px;">
                                <?php echo esc_html( $label ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ( empty( $templates ) ): ?>
                    <p style="text-align:center;color:#64748b;padding:32px 0;">Chưa có template nào. Hãy thêm template mới ở form bên trái.</p>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
                        <?php foreach ( $templates as $tpl ): ?>
                            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;<?php echo ($tpl['status'] === 'draft') ? 'opacity:.5;' : ''; ?>">
                                <img src="<?php echo esc_url( $tpl['thumbnail_url'] ); ?>" style="width:100%;aspect-ratio:3/4;object-fit:cover;display:block;background:#f1f5f9;" loading="lazy">
                                <div style="padding:8px 10px;">
                                    <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $tpl['title'] ); ?></div>
                                    <div style="font-size:11px;color:#64748b;margin-top:2px;">
                                        <?php echo esc_html( $categories[ $tpl['category'] ] ?? $tpl['category'] ); ?>
                                        • <?php echo esc_html( $tpl['status'] ); ?>
                                        • #<?php echo esc_html( $tpl['sort_order'] ); ?>
                                    </div>
                                    <div style="display:flex;gap:4px;margin-top:6px;">
                                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'bztimg-profile-templates', 'edit' => $tpl['id'] ), admin_url( 'admin.php' ) ) ); ?>" class="button" style="font-size:11px;padding:2px 8px;min-height:24px;">✏️ Sửa</a>
                                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'bztimg-profile-templates', 'delete' => $tpl['id'] ), admin_url( 'admin.php' ) ), 'bztimg_delete_profile_tpl' ) ); ?>" class="button" style="font-size:11px;padding:2px 8px;min-height:24px;color:#ef4444;" onclick="return confirm('Xóa template này?')">🗑️ Xóa</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($){
    /* Media Library picker */
    $('.bztimg-media-pick').on('click', function(e){
        e.preventDefault();
        var targetId = $(this).data('target');
        var frame = wp.media({
            title: 'Chọn ảnh template',
            button: { text: 'Chọn ảnh' },
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function(){
            var attachment = frame.state().get('selection').first().toJSON();
            $('#' + targetId).val(attachment.url);
            // Update preview
            var previewId = targetId + '_preview';
            $('#' + previewId).html('<img src="' + attachment.url + '" style="max-width:150px;border-radius:6px;">');
        });
        frame.open();
    });
});
</script>
