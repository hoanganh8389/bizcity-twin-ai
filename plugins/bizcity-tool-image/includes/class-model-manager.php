<?php
/**
 * BizCity Tool Image — Character Model Manager
 *
 * Manages the preset model library for Character Studio.
 * Uses Custom Post Type `bztimg_model` with post meta for structured data.
 *
 * Features:
 *  - CPT registration with admin columns
 *  - Meta box for model attributes (gender, age group, suitable_for)
 *  - REST endpoint for frontend model picker
 *  - Helper methods: get_models(), get_model()
 *  - Seeder for 10 default models
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Model_Manager {

    const CPT         = 'bztimg_model';
    const NONCE_KEY   = 'bztimg_model_meta_nonce';
    const REST_NAMESPACE = 'bztool-image/v1';

    /* ── Allowed values ── */
    const GENDERS    = [ 'male', 'female', 'unisex' ];
    const AGE_GROUPS = [ 'teen', 'young-adult', 'adult', 'senior' ];
    const SUITABLE   = [ 'apparel', 'accessory', 'faceswap' ];

    /**
     * Boot: register CPT, meta boxes, admin columns, REST route.
     */
    public static function boot(): void {
        add_action( 'init',                [ __CLASS__, 'register_cpt' ] );
        add_action( 'add_meta_boxes',      [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'rest_api_init',       [ __CLASS__, 'register_rest_routes' ] );
        add_filter( 'manage_' . self::CPT . '_posts_columns',       [ __CLASS__, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'admin_column_content' ], 10, 2 );
    }

    /* ================================================================
     *  CPT Registration
     * ================================================================ */

    public static function register_cpt(): void {
        register_post_type( self::CPT, [
            'labels' => [
                'name'               => 'Mẫu Người',
                'singular_name'      => 'Mẫu Người',
                'add_new'            => 'Thêm Mẫu',
                'add_new_item'       => 'Thêm Mẫu Người Mới',
                'edit_item'          => 'Sửa Mẫu Người',
                'new_item'           => 'Mẫu Người Mới',
                'view_item'          => 'Xem Mẫu Người',
                'search_items'       => 'Tìm Mẫu Người',
                'not_found'          => 'Không tìm thấy mẫu người nào.',
                'not_found_in_trash' => 'Không có mẫu người trong thùng rác.',
                'all_items'          => 'Quản lý Mẫu Người',
                'menu_name'          => 'Mẫu Người',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'bztimg-dashboard', // As submenu of Image AI
            'menu_icon'           => 'dashicons-groups',
            'supports'            => [ 'title', 'thumbnail' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false, // We register custom REST instead
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );
    }

    /* ================================================================
     *  Meta Box
     * ================================================================ */

    public static function add_meta_boxes(): void {
        add_meta_box(
            'bztimg_model_attributes',
            '🧑 Thuộc tính Mẫu Người',
            [ __CLASS__, 'render_meta_box' ],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( self::NONCE_KEY, self::NONCE_KEY );

        $gender      = get_post_meta( $post->ID, '_model_gender', true ) ?: 'female';
        $age_group   = get_post_meta( $post->ID, '_model_age_group', true ) ?: 'young-adult';
        $suitable    = get_post_meta( $post->ID, '_model_suitable_for', true );
        $sort_order  = get_post_meta( $post->ID, '_model_sort_order', true ) ?: 0;
        $image_url   = get_post_meta( $post->ID, '_model_image_url', true ) ?: '';
        $attach_id   = get_post_meta( $post->ID, '_model_media_attch_id', true ) ?: 0;

        if ( ! is_array( $suitable ) ) {
            $suitable = ! empty( $suitable ) ? json_decode( $suitable, true ) : self::SUITABLE;
        }
        ?>
        <table class="form-table" style="max-width:700px;">
            <tr>
                <th><label for="bztm-gender">Giới tính</label></th>
                <td>
                    <select id="bztm-gender" name="_model_gender">
                        <option value="female" <?php selected( $gender, 'female' ); ?>>👩 Nữ</option>
                        <option value="male"   <?php selected( $gender, 'male'   ); ?>>👨 Nam</option>
                        <option value="unisex" <?php selected( $gender, 'unisex' ); ?>>⚧ Unisex</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="bztm-age">Nhóm tuổi</label></th>
                <td>
                    <select id="bztm-age" name="_model_age_group">
                        <option value="teen"        <?php selected( $age_group, 'teen' ); ?>>🧑 Teen (16-19)</option>
                        <option value="young-adult"  <?php selected( $age_group, 'young-adult' ); ?>>👤 Young Adult (20-30)</option>
                        <option value="adult"        <?php selected( $age_group, 'adult' ); ?>>🧔 Adult (30-50)</option>
                        <option value="senior"       <?php selected( $age_group, 'senior' ); ?>>👴 Senior (50+)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Phù hợp cho</th>
                <td>
                    <label><input type="checkbox" name="_model_suitable_for[]" value="apparel"   <?php checked( in_array( 'apparel',   $suitable, true ) ); ?> /> 👕 Thử quần áo</label><br>
                    <label><input type="checkbox" name="_model_suitable_for[]" value="accessory" <?php checked( in_array( 'accessory', $suitable, true ) ); ?> /> 🕶️ Thử phụ kiện</label><br>
                    <label><input type="checkbox" name="_model_suitable_for[]" value="faceswap"  <?php checked( in_array( 'faceswap',  $suitable, true ) ); ?> /> 🧑 Đổi khuôn mặt</label>
                </td>
            </tr>
            <tr>
                <th><label for="bztm-sort">Thứ tự sắp xếp</label></th>
                <td>
                    <input type="number" id="bztm-sort" name="_model_sort_order"
                           value="<?php echo esc_attr( intval( $sort_order ) ); ?>"
                           min="0" max="999" class="small-text" />
                    <p class="description">Số nhỏ hơn hiển thị trước.</p>
                </td>
            </tr>
            <tr>
                <th><label>Ảnh toàn thân (3:4)</label></th>
                <td>
                    <input type="hidden" id="bztm-attach-id" name="_model_media_attch_id" value="<?php echo esc_attr( intval( $attach_id ) ); ?>" />
                    <input type="text" id="bztm-image-url" name="_model_image_url"
                           value="<?php echo esc_url( $image_url ); ?>"
                           class="regular-text" style="width:100%;" readonly />
                    <p>
                        <button type="button" class="button" id="bztm-upload-btn">📷 Chọn ảnh</button>
                        <button type="button" class="button" id="bztm-remove-btn" <?php echo empty( $image_url ) ? 'style="display:none"' : ''; ?>>❌ Xóa</button>
                    </p>
                    <?php if ( $image_url ): ?>
                        <div id="bztm-preview" style="margin-top:8px;">
                            <img src="<?php echo esc_url( $image_url ); ?>" style="max-height:200px;border-radius:8px;border:1px solid #e5e7eb;" />
                        </div>
                    <?php else: ?>
                        <div id="bztm-preview" style="margin-top:8px;"></div>
                    <?php endif; ?>
                    <p class="description">Ảnh toàn thân tỉ lệ 3:4 (khuyến nghị 768×1024 trở lên). Dùng Featured Image hoặc URL trực tiếp.</p>
                </td>
            </tr>
        </table>

        <script>
        jQuery(function($){
            var frame;
            $('#bztm-upload-btn').on('click', function(e){
                e.preventDefault();
                if ( frame ) { frame.open(); return; }
                frame = wp.media({ title: 'Chọn ảnh mẫu người', button: { text: 'Chọn' }, multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#bztm-attach-id').val(att.id);
                    $('#bztm-image-url').val(att.url);
                    $('#bztm-preview').html('<img src="'+att.url+'" style="max-height:200px;border-radius:8px;border:1px solid #e5e7eb;" />');
                    $('#bztm-remove-btn').show();
                });
                frame.open();
            });
            $('#bztm-remove-btn').on('click', function(){
                $('#bztm-attach-id').val('');
                $('#bztm-image-url').val('');
                $('#bztm-preview').empty();
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    public static function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST[ self::NONCE_KEY ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_KEY ], self::NONCE_KEY ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Gender
        $gender = sanitize_text_field( $_POST['_model_gender'] ?? 'female' );
        if ( ! in_array( $gender, self::GENDERS, true ) ) $gender = 'female';
        update_post_meta( $post_id, '_model_gender', $gender );

        // Age group
        $age = sanitize_text_field( $_POST['_model_age_group'] ?? 'young-adult' );
        if ( ! in_array( $age, self::AGE_GROUPS, true ) ) $age = 'young-adult';
        update_post_meta( $post_id, '_model_age_group', $age );

        // Suitable for
        $suitable_raw = array_map( 'sanitize_text_field', (array) ( $_POST['_model_suitable_for'] ?? [] ) );
        $suitable     = array_values( array_intersect( $suitable_raw, self::SUITABLE ) );
        update_post_meta( $post_id, '_model_suitable_for', wp_json_encode( $suitable ) );

        // Sort order
        update_post_meta( $post_id, '_model_sort_order', intval( $_POST['_model_sort_order'] ?? 0 ) );

        // Image URL
        $image_url = esc_url_raw( $_POST['_model_image_url'] ?? '' );
        update_post_meta( $post_id, '_model_image_url', $image_url );

        // Attachment ID
        update_post_meta( $post_id, '_model_media_attch_id', intval( $_POST['_model_media_attch_id'] ?? 0 ) );

        // Auto-generate thumbnail from attachment
        $attach_id = intval( $_POST['_model_media_attch_id'] ?? 0 );
        if ( $attach_id ) {
            $thumb = wp_get_attachment_image_url( $attach_id, 'medium' );
            update_post_meta( $post_id, '_model_thumb_url', esc_url_raw( $thumb ?: $image_url ) );
        }
    }

    /* ================================================================
     *  Admin Columns
     * ================================================================ */

    public static function admin_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['bztm_thumb']    = 'Ảnh';
                $new['bztm_gender']   = 'Giới tính';
                $new['bztm_age']      = 'Tuổi';
                $new['bztm_suitable'] = 'Phù hợp';
                $new['bztm_order']    = 'Thứ tự';
            }
        }
        return $new;
    }

    public static function admin_column_content( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'bztm_thumb':
                $url = get_post_meta( $post_id, '_model_thumb_url', true )
                    ?: get_post_meta( $post_id, '_model_image_url', true );
                if ( $url ) {
                    echo '<img src="' . esc_url( $url ) . '" style="width:40px;height:53px;object-fit:cover;border-radius:4px;" />';
                } else {
                    echo '—';
                }
                break;
            case 'bztm_gender':
                $g = get_post_meta( $post_id, '_model_gender', true );
                $map = [ 'female' => '👩 Nữ', 'male' => '👨 Nam', 'unisex' => '⚧ Unisex' ];
                echo esc_html( $map[ $g ] ?? $g );
                break;
            case 'bztm_age':
                echo esc_html( get_post_meta( $post_id, '_model_age_group', true ) ?: '—' );
                break;
            case 'bztm_suitable':
                $s = get_post_meta( $post_id, '_model_suitable_for', true );
                $arr = is_string( $s ) ? json_decode( $s, true ) : $s;
                echo is_array( $arr ) ? esc_html( implode( ', ', $arr ) ) : '—';
                break;
            case 'bztm_order':
                echo intval( get_post_meta( $post_id, '_model_sort_order', true ) );
                break;
        }
    }

    /* ================================================================
     *  REST API — Public model picker
     * ================================================================ */

    public static function register_rest_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/character-models', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_get_models' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_get_models( WP_REST_Request $request ): WP_REST_Response {
        $gender  = sanitize_text_field( $request->get_param( 'gender' ) ?? '' );
        $for     = sanitize_text_field( $request->get_param( 'suitable_for' ) ?? '' );
        $models  = self::get_models( $gender, $for );

        return new WP_REST_Response( [
            'success' => true,
            'count'   => count( $models ),
            'models'  => $models,
        ] );
    }

    /* ================================================================
     *  Helpers — Used by Character Studio
     * ================================================================ */

    /**
     * Get published models, optionally filtered.
     *
     * @param string $gender       Filter by gender: male|female|unisex. Empty = all.
     * @param string $suitable_for Filter by purpose: apparel|accessory|faceswap. Empty = all.
     * @return array
     */
    public static function get_models( string $gender = '', string $suitable_for = '' ): array {
        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_model_sort_order',
            'order'          => 'ASC',
        ];

        $meta_query = [];

        if ( ! empty( $gender ) && in_array( $gender, self::GENDERS, true ) ) {
            $meta_query[] = [
                'key'     => '_model_gender',
                'value'   => $gender,
                'compare' => '=',
            ];
        }

        if ( ! empty( $suitable_for ) && in_array( $suitable_for, self::SUITABLE, true ) ) {
            $meta_query[] = [
                'key'     => '_model_suitable_for',
                'value'   => $suitable_for,
                'compare' => 'LIKE',
            ];
        }

        if ( ! empty( $meta_query ) ) {
            $meta_query['relation'] = 'AND';
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query( $args );
        $out   = [];

        foreach ( $query->posts as $p ) {
            $suitable_raw = get_post_meta( $p->ID, '_model_suitable_for', true );
            $suitable_arr = is_string( $suitable_raw ) ? json_decode( $suitable_raw, true ) : $suitable_raw;

            $out[] = [
                'id'           => $p->ID,
                'name'         => $p->post_title,
                'gender'       => get_post_meta( $p->ID, '_model_gender', true ) ?: 'female',
                'age_group'    => get_post_meta( $p->ID, '_model_age_group', true ) ?: 'young-adult',
                'image_url'    => get_post_meta( $p->ID, '_model_image_url', true ) ?: '',
                'thumb_url'    => get_post_meta( $p->ID, '_model_thumb_url', true ) ?: '',
                'suitable_for' => is_array( $suitable_arr ) ? $suitable_arr : self::SUITABLE,
                'sort_order'   => intval( get_post_meta( $p->ID, '_model_sort_order', true ) ),
            ];
        }

        return $out;
    }

    /**
     * Get a single model by ID.
     */
    public static function get_model( int $id ): ?array {
        $p = get_post( $id );
        if ( ! $p || $p->post_type !== self::CPT ) {
            return null;
        }

        $suitable_raw = get_post_meta( $id, '_model_suitable_for', true );
        $suitable_arr = is_string( $suitable_raw ) ? json_decode( $suitable_raw, true ) : $suitable_raw;

        return [
            'id'           => $id,
            'name'         => $p->post_title,
            'gender'       => get_post_meta( $id, '_model_gender', true ) ?: 'female',
            'age_group'    => get_post_meta( $id, '_model_age_group', true ) ?: 'young-adult',
            'image_url'    => get_post_meta( $id, '_model_image_url', true ) ?: '',
            'thumb_url'    => get_post_meta( $id, '_model_thumb_url', true ) ?: '',
            'suitable_for' => is_array( $suitable_arr ) ? $suitable_arr : self::SUITABLE,
            'sort_order'   => intval( get_post_meta( $id, '_model_sort_order', true ) ),
        ];
    }

    /* ================================================================
     *  Seeder — Insert 10 default models on activation
     * ================================================================ */

    /**
     * Seed default models if none exist. Called on plugin activation.
     */
    public static function seed_defaults(): void {
        // Only seed if no models exist yet
        $existing = wp_count_posts( self::CPT );
        if ( ( $existing->publish ?? 0 ) + ( $existing->draft ?? 0 ) > 0 ) {
            return;
        }

        $defaults = [
            [ 'title' => 'Mẫu nữ 1', 'gender' => 'female', 'age' => 'young-adult', 'order' => 1  ],
            [ 'title' => 'Mẫu nữ 2', 'gender' => 'female', 'age' => 'young-adult', 'order' => 2  ],
            [ 'title' => 'Mẫu nữ 3', 'gender' => 'female', 'age' => 'adult',       'order' => 3  ],
            [ 'title' => 'Mẫu nữ 4', 'gender' => 'female', 'age' => 'teen',        'order' => 4  ],
            [ 'title' => 'Mẫu nữ 5', 'gender' => 'female', 'age' => 'young-adult', 'order' => 5  ],
            [ 'title' => 'Mẫu nam 1', 'gender' => 'male',   'age' => 'young-adult', 'order' => 6  ],
            [ 'title' => 'Mẫu nam 2', 'gender' => 'male',   'age' => 'young-adult', 'order' => 7  ],
            [ 'title' => 'Mẫu nam 3', 'gender' => 'male',   'age' => 'adult',       'order' => 8  ],
            [ 'title' => 'Mẫu nam 4', 'gender' => 'male',   'age' => 'teen',        'order' => 9  ],
            [ 'title' => 'Mẫu nam 5', 'gender' => 'male',   'age' => 'young-adult', 'order' => 10 ],
        ];

        foreach ( $defaults as $m ) {
            $post_id = wp_insert_post( [
                'post_type'   => self::CPT,
                'post_title'  => $m['title'],
                'post_status' => 'draft', // Draft until admin uploads actual photos
            ] );

            if ( ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, '_model_gender',         $m['gender'] );
                update_post_meta( $post_id, '_model_age_group',      $m['age'] );
                update_post_meta( $post_id, '_model_suitable_for',   wp_json_encode( self::SUITABLE ) );
                update_post_meta( $post_id, '_model_sort_order',     $m['order'] );
                update_post_meta( $post_id, '_model_image_url',      '' );
                update_post_meta( $post_id, '_model_thumb_url',      '' );
                update_post_meta( $post_id, '_model_media_attch_id', 0 );
            }
        }
    }
}
