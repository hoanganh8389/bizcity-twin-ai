<?php
/**
 * Admin: Video Effects Template Management
 * 
 * CRUD for video effect templates — admin creates effects, users use them on frontend.
 * Pattern: AIVA video-studio effect gallery.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$action    = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
$effect_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
$nonce     = wp_create_nonce( 'bizcity_kling_effects' );

// Handle form save
if ( isset( $_POST['bvk_save_effect'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bizcity_kling_effects' ) ) {
    $save_data = [
        'title'             => sanitize_text_field( $_POST['title'] ?? '' ),
        'slug'              => sanitize_title( $_POST['slug'] ?? $_POST['title'] ?? '' ),
        'description'       => sanitize_textarea_field( $_POST['description'] ?? '' ),
        'category'          => sanitize_text_field( $_POST['category'] ?? 'general' ),
        'thumbnail_url'     => esc_url_raw( $_POST['thumbnail_url'] ?? '' ),
        'preview_video_url' => esc_url_raw( $_POST['preview_video_url'] ?? '' ),
        'prompt_template'   => wp_kses_post( $_POST['prompt_template'] ?? '' ),
        'num_images'        => max( 1, intval( $_POST['num_images'] ?? 1 ) ),
        'model'             => sanitize_text_field( $_POST['model'] ?? '2.6|pro' ),
        'duration'          => intval( $_POST['duration'] ?? 5 ),
        'aspect_ratio'      => sanitize_text_field( $_POST['aspect_ratio'] ?? '9:16' ),
        'badge'             => sanitize_text_field( $_POST['badge'] ?? '' ),
        'badge_color'       => sanitize_hex_color( $_POST['badge_color'] ?? '#3b82f6' ) ?: '#3b82f6',
        'is_featured'       => isset( $_POST['is_featured'] ) ? 1 : 0,
        'sort_order'        => intval( $_POST['sort_order'] ?? 0 ),
        'status'            => sanitize_text_field( $_POST['status'] ?? 'active' ),
    ];

    if ( $effect_id ) {
        BizCity_Video_Kling_Database::update_video_effect( $effect_id, $save_data );
        $message = 'Đã cập nhật effect thành công.';
    } else {
        $effect_id = BizCity_Video_Kling_Database::create_video_effect( $save_data );
        $message   = 'Đã tạo effect mới thành công.';
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    $action = 'edit';
}

// Handle delete
if ( $action === 'delete' && $effect_id && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bizcity_kling_effects' ) ) {
    BizCity_Video_Kling_Database::delete_video_effect( $effect_id );
    echo '<div class="notice notice-success is-dismissible"><p>Đã xóa effect.</p></div>';
    $action = 'list';
    $effect_id = 0;
}
?>

<div class="wrap bizcity-kling-wrap">
<?php if ( $action === 'list' ): ?>
    <!-- ═══ LIST VIEW ═══ -->
    <h1>
        <?php _e( 'Video Effects', 'bizcity-video-kling' ); ?>
        <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects&action=new' ); ?>" class="page-title-action">
            <?php _e( '+ Thêm Effect', 'bizcity-video-kling' ); ?>
        </a>
    </h1>

    <?php
    $effects = BizCity_Video_Kling_Database::get_video_effects( [ 'status' => '', 'limit' => 100 ] );
    ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:60px">ID</th>
                <th style="width:80px">Ảnh</th>
                <th>Tên Effect</th>
                <th style="width:100px">Category</th>
                <th style="width:60px">Ảnh cần</th>
                <th style="width:80px">Model</th>
                <th style="width:60px">Badge</th>
                <th style="width:70px">Views</th>
                <th style="width:70px">Uses</th>
                <th style="width:80px">Status</th>
                <th style="width:140px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $effects ) ): ?>
            <tr><td colspan="11" style="text-align:center;padding:30px;color:#999;">
                Chưa có effect nào. <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects&action=new' ); ?>">Tạo effect đầu tiên</a>
            </td></tr>
        <?php else: foreach ( $effects as $eff ): ?>
            <tr>
                <td><?php echo $eff->id; ?></td>
                <td>
                    <?php if ( $eff->thumbnail_url ): ?>
                        <img src="<?php echo esc_url( $eff->thumbnail_url ); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;">
                    <?php else: ?>
                        <span style="display:inline-block;width:60px;height:60px;background:#f3f4f6;border-radius:8px;text-align:center;line-height:60px;font-size:24px;">🎬</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects&action=edit&id=' . $eff->id ); ?>"><?php echo esc_html( $eff->title ); ?></a></strong>
                    <br><code style="font-size:11px;color:#999;"><?php echo esc_html( $eff->slug ); ?></code>
                </td>
                <td><span style="padding:2px 8px;background:#f3f4f6;border-radius:4px;font-size:12px;"><?php echo esc_html( $eff->category ); ?></span></td>
                <td style="text-align:center"><?php echo $eff->num_images; ?></td>
                <td><code style="font-size:11px;"><?php echo esc_html( $eff->model ); ?></code></td>
                <td>
                    <?php if ( $eff->badge ): ?>
                        <span style="display:inline-block;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;color:#fff;background:<?php echo esc_attr( $eff->badge_color ); ?>">
                            <?php echo esc_html( $eff->badge ); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center"><?php echo number_format( $eff->view_count ); ?></td>
                <td style="text-align:center"><?php echo number_format( $eff->use_count ); ?></td>
                <td>
                    <?php if ( $eff->status === 'active' ): ?>
                        <span style="color:#166534;font-weight:600;">● Active</span>
                    <?php else: ?>
                        <span style="color:#999;">● <?php echo esc_html( ucfirst( $eff->status ) ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects&action=edit&id=' . $eff->id ); ?>" class="button button-small">Sửa</a>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=bizcity-kling-effects&action=delete&id=' . $eff->id ), 'bizcity_kling_effects' ); ?>" 
                       class="button button-small" style="color:#dc2626;" 
                       onclick="return confirm('Xóa effect này?')">Xóa</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

<?php else: ?>
    <!-- ═══ ADD/EDIT FORM ═══ -->
    <?php
    $effect = $effect_id ? BizCity_Video_Kling_Database::get_video_effect( $effect_id ) : null;
    $is_edit = ! empty( $effect );
    ?>

    <h1>
        <?php echo $is_edit ? 'Sửa Effect: ' . esc_html( $effect->title ) : 'Tạo Effect Mới'; ?>
        <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects' ); ?>" class="page-title-action">← Danh sách</a>
    </h1>

    <form method="post" style="max-width:900px;">
        <input type="hidden" name="_wpnonce" value="<?php echo $nonce; ?>">
        <input type="hidden" name="bvk_save_effect" value="1">

        <table class="form-table">
            <tr>
                <th><label for="title">Tên Effect *</label></th>
                <td><input type="text" id="title" name="title" class="regular-text" required
                           value="<?php echo esc_attr( $effect->title ?? '' ); ?>"
                           placeholder="VD: Hóa Thân Hoạt Hình"></td>
            </tr>
            <tr>
                <th><label for="slug">Slug</label></th>
                <td><input type="text" id="slug" name="slug" class="regular-text"
                           value="<?php echo esc_attr( $effect->slug ?? '' ); ?>"
                           placeholder="auto-generated từ tên">
                    <p class="description">Để trống sẽ tự tạo từ tên.</p>
                </td>
            </tr>
            <tr>
                <th><label for="description">Mô tả</label></th>
                <td><textarea id="description" name="description" rows="3" class="large-text"
                              placeholder="Mô tả ngắn về hiệu ứng..."><?php echo esc_textarea( $effect->description ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="category">Category</label></th>
                <td>
                    <input type="text" id="category" name="category" class="regular-text"
                           value="<?php echo esc_attr( $effect->category ?? 'general' ); ?>"
                           placeholder="VD: Baby, Lãng mạn, Vui, Cinematic"
                           list="bvk-categories">
                    <datalist id="bvk-categories">
                        <option value="Baby">
                        <option value="Lãng mạn">
                        <option value="Vui">
                        <option value="Cinematic">
                        <option value="Marketing">
                        <option value="general">
                    </datalist>
                </td>
            </tr>
            <tr>
                <th><label for="thumbnail_url">Ảnh Thumbnail</label></th>
                <td>
                    <input type="text" id="thumbnail_url" name="thumbnail_url" class="regular-text"
                           value="<?php echo esc_url( $effect->thumbnail_url ?? '' ); ?>"
                           placeholder="URL ảnh thumbnail">
                    <button type="button" class="button bvk-media-btn" data-target="thumbnail_url">📷 Chọn ảnh</button>
                    <?php if ( ! empty( $effect->thumbnail_url ) ): ?>
                        <br><img src="<?php echo esc_url( $effect->thumbnail_url ); ?>" style="max-width:200px;margin-top:8px;border-radius:8px;">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="preview_video_url">Video Preview</label></th>
                <td>
                    <input type="text" id="preview_video_url" name="preview_video_url" class="regular-text"
                           value="<?php echo esc_url( $effect->preview_video_url ?? '' ); ?>"
                           placeholder="URL video preview (mp4)">
                    <button type="button" class="button bvk-media-btn" data-target="preview_video_url">🎬 Chọn video</button>
                </td>
            </tr>
            <tr>
                <th><label for="prompt_template">Prompt Template *</label></th>
                <td>
                    <textarea id="prompt_template" name="prompt_template" rows="5" class="large-text code" required
                              placeholder="The character in {{image_1}} transforms into an animated cartoon style..."><?php echo esc_textarea( $effect->prompt_template ?? '' ); ?></textarea>
                    <p class="description">
                        Sử dụng <code>{{image_1}}</code>, <code>{{image_2}}</code>... cho vị trí ảnh.
                        Với effect 1 ảnh, dùng <code>{{image_1}}</code>. Đa cảnh: <code>{{image_1}}</code> cho cảnh 1, <code>{{image_2}}</code> cho cảnh 2...
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="num_images">Số ảnh cần</label></th>
                <td>
                    <input type="number" id="num_images" name="num_images" min="1" max="10" style="width:80px"
                           value="<?php echo intval( $effect->num_images ?? 1 ); ?>">
                    <p class="description">Bao nhiêu ảnh user cần upload? VD: 1 cho ảnh đơn, 2 cho ghép/so sánh.</p>
                </td>
            </tr>
            <tr>
                <th><label for="model">Model</label></th>
                <td>
                    <select id="model" name="model">
                        <optgroup label="Kling AI">
                            <option value="2.6|pro" <?php selected( $effect->model ?? '2.6|pro', '2.6|pro' ); ?>>Kling v2.6 Pro</option>
                            <option value="2.6|std" <?php selected( $effect->model ?? '', '2.6|std' ); ?>>Kling v2.6 Standard</option>
                            <option value="2.5|pro" <?php selected( $effect->model ?? '', '2.5|pro' ); ?>>Kling v2.5 Pro</option>
                            <option value="1.6|pro" <?php selected( $effect->model ?? '', '1.6|pro' ); ?>>Kling v1.6 Pro</option>
                        </optgroup>
                        <optgroup label="SeeDance">
                            <option value="seedance:1.0" <?php selected( $effect->model ?? '', 'seedance:1.0' ); ?>>SeeDance v1.0</option>
                        </optgroup>
                        <optgroup label="Sora (OpenAI)">
                            <option value="sora:v1" <?php selected( $effect->model ?? '', 'sora:v1' ); ?>>Sora v1</option>
                        </optgroup>
                        <optgroup label="Veo (Google)">
                            <option value="veo:3" <?php selected( $effect->model ?? '', 'veo:3' ); ?>>Veo 3</option>
                        </optgroup>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Duration & Ratio</th>
                <td>
                    <select name="duration" style="width:100px">
                        <?php foreach ( [5, 10, 15, 20, 30] as $d ): ?>
                            <option value="<?php echo $d; ?>" <?php selected( $effect->duration ?? 5, $d ); ?>><?php echo $d; ?>s</option>
                        <?php endforeach; ?>
                    </select>
                    <select name="aspect_ratio" style="width:100px">
                        <option value="9:16" <?php selected( $effect->aspect_ratio ?? '9:16', '9:16' ); ?>>9:16 Dọc</option>
                        <option value="16:9" <?php selected( $effect->aspect_ratio ?? '', '16:9' ); ?>>16:9 Ngang</option>
                        <option value="1:1" <?php selected( $effect->aspect_ratio ?? '', '1:1' ); ?>>1:1 Vuông</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Badge</th>
                <td>
                    <input type="text" name="badge" style="width:100px" 
                           value="<?php echo esc_attr( $effect->badge ?? '' ); ?>"
                           placeholder="Hot, New...">
                    <input type="color" name="badge_color" style="width:50px;height:30px;vertical-align:middle" 
                           value="<?php echo esc_attr( $effect->badge_color ?? '#3b82f6' ); ?>">
                </td>
            </tr>
            <tr>
                <th>Options</th>
                <td>
                    <label><input type="checkbox" name="is_featured" <?php checked( $effect->is_featured ?? 0, 1 ); ?>> Featured Effect</label>
                    &nbsp;&nbsp;
                    <label>Sort order: <input type="number" name="sort_order" style="width:60px" 
                                              value="<?php echo intval( $effect->sort_order ?? 0 ); ?>"></label>
                    &nbsp;&nbsp;
                    <select name="status">
                        <option value="active" <?php selected( $effect->status ?? 'active', 'active' ); ?>>Active</option>
                        <option value="draft" <?php selected( $effect->status ?? '', 'draft' ); ?>>Draft</option>
                    </select>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php echo $is_edit ? 'Cập nhật Effect' : 'Tạo Effect'; ?>">
            <a href="<?php echo admin_url( 'admin.php?page=bizcity-kling-effects' ); ?>" class="button">Hủy</a>
        </p>
    </form>

    <script>
    jQuery(function($){
        // WP Media picker for thumbnail/video
        $('.bvk-media-btn').on('click', function(e){
            e.preventDefault();
            var target = $(this).data('target');
            var frame = wp.media({ multiple: false });
            frame.on('select', function(){
                var url = frame.state().get('selection').first().toJSON().url;
                $('#' + target).val(url);
            });
            frame.open();
        });
    });
    </script>

<?php endif; ?>
</div>
