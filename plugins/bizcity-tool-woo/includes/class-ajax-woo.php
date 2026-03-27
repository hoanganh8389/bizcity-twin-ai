<?php
/**
 * BizCity Tool WooCommerce — AJAX Handlers
 *
 * Two-step flow: Generate Preview → Publish Product.
 * Plus: prompt history, WP/Woo connection settings, file upload.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Ajax_Woo {

    public static function init() {
        add_action( 'wp_ajax_bztw_generate_preview',  array( __CLASS__, 'handle_generate_preview' ) );
        add_action( 'wp_ajax_bztw_publish_product',    array( __CLASS__, 'handle_publish_product' ) );
        add_action( 'wp_ajax_bztw_poll_history',       array( __CLASS__, 'handle_poll_history' ) );
        add_action( 'wp_ajax_bztw_save_wp_settings',   array( __CLASS__, 'handle_save_wp_settings' ) );
        add_action( 'wp_ajax_bztw_upload_image',       array( __CLASS__, 'handle_upload_image' ) );
        add_action( 'wp_ajax_bztw_rerun_prompt',       array( __CLASS__, 'handle_rerun_prompt' ) );
    }

    /* ══════════════════════════════════════════════════════
     *  Generate Preview (AI only, no product creation)
     * ══════════════════════════════════════════════════════ */
    public static function handle_generate_preview() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );

        if ( empty( $topic ) ) wp_send_json_error( 'Vui lòng mô tả sản phẩm.' );

        $ai_title = '';
        $ai_desc  = '';
        $ai_price = '';
        $ai_cat   = '';

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $prompt = "Phân tích mô tả sản phẩm WooCommerce từ yêu cầu sau:\n{$topic}\n\n"
                . "Trả về JSON: {\"title\":\"Tên sản phẩm\",\"description\":\"Mô tả HTML chi tiết\",\"price\":\"giá (số)\",\"category\":\"danh mục\"}";

            $result = bizcity_openrouter_chat( [
                [ 'role' => 'system', 'content' => 'Bạn là chuyên gia e-commerce. Chỉ trả JSON.' ],
                [ 'role' => 'user',   'content' => $prompt ],
            ], [ 'temperature' => 0.6, 'max_tokens' => 2000 ] );

            $raw    = $result['message'] ?? '';
            $parsed = json_decode( preg_replace( '/^```json\s*|```$/m', '', trim( $raw ) ), true );
            if ( is_array( $parsed ) ) {
                $ai_title = $parsed['title']       ?? '';
                $ai_desc  = $parsed['description'] ?? '';
                $ai_price = $parsed['price']       ?? '';
                $ai_cat   = $parsed['category']    ?? '';
            }
        }

        // Generate image if not provided
        if ( empty( $image_url ) && function_exists( 'twf_generate_image_url' ) ) {
            $image_url = twf_generate_image_url( $ai_title ?: $topic );
        }

        if ( empty( $ai_title ) ) {
            wp_send_json_error( 'AI không phân tích được sản phẩm. Vui lòng mô tả rõ hơn.' );
        }

        wp_send_json_success( array(
            'title'       => $ai_title,
            'description' => $ai_desc,
            'price'       => $ai_price,
            'category'    => $ai_cat,
            'image_url'   => $image_url,
        ) );
    }

    /* ══════════════════════════════════════════════════════
     *  Publish Product (from previewed data)
     * ══════════════════════════════════════════════════════ */
    public static function handle_publish_product() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $title     = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $desc      = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
        $price     = sanitize_text_field( $_POST['price'] ?? '' );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $category  = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );

        if ( empty( $title ) ) wp_send_json_error( 'Thiếu tên sản phẩm.' );

        // Check WP/Woo connection settings
        $wp_settings = self::get_wp_settings( $user_id );

        if ( $wp_settings && ! empty( $wp_settings['site_url'] ) ) {
            $result = self::publish_to_external_woo( $wp_settings, $title, $desc, $price, $image_url, $category );
        } else {
            $result = self::publish_to_local_woo( $user_id, $title, $desc, $price, $image_url, $category );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( $result['message'] );
        }

        // Save to prompt history
        BizCity_Woo_Post_Type::save_history(
            $user_id,
            'create_product',
            $topic ?: $title,
            $title,
            $desc,
            $result['product_id'] ?? null,
            $result['url'] ?? '',
            $image_url
        );

        wp_send_json_success( $result );
    }

    /**
     * Publish to local WooCommerce.
     */
    private static function publish_to_local_woo( int $user_id, string $title, string $desc, string $price, string $image_url, string $category ): array {
        if ( ! class_exists( 'WC_Product' ) ) {
            return [ 'success' => false, 'message' => 'WooCommerce chưa được kích hoạt.' ];
        }

        $product = new \WC_Product_Simple();
        $product->set_name( $title );
        $product->set_description( $desc );
        $product->set_short_description( wp_trim_words( wp_strip_all_tags( $desc ), 20 ) );
        if ( $price ) $product->set_regular_price( $price );
        $product->set_status( 'publish' );
        $product_id = $product->save();

        if ( ! $product_id ) {
            return [ 'success' => false, 'message' => 'Lỗi tạo sản phẩm WooCommerce.' ];
        }

        // Category
        if ( $category ) {
            $term = term_exists( $category, 'product_cat' );
            if ( ! $term ) $term = wp_insert_term( $category, 'product_cat' );
            if ( ! is_wp_error( $term ) ) {
                $term_id = is_array( $term ) ? $term['term_id'] : $term;
                wp_set_object_terms( $product_id, (int) $term_id, 'product_cat' );
            }
        }

        // Image
        if ( $image_url ) {
            self::attach_product_image( $product_id, $image_url );
        }

        return [
            'success'    => true,
            'product_id' => $product_id,
            'url'        => get_permalink( $product_id ),
            'edit_url'   => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
            'message'    => 'Tạo sản phẩm thành công!',
        ];
    }

    /**
     * Publish to external WooCommerce via REST API.
     */
    private static function publish_to_external_woo( array $settings, string $title, string $desc, string $price, string $image_url, string $category ): array {
        $site_url = rtrim( $settings['site_url'], '/' );
        $ck       = $settings['consumer_key'] ?? $settings['username'] ?? '';
        $cs       = $settings['consumer_secret'] ?? $settings['app_password'] ?? '';

        if ( empty( $site_url ) || empty( $ck ) || empty( $cs ) ) {
            return [ 'success' => false, 'message' => 'Thiếu thông tin kết nối WooCommerce.' ];
        }

        $endpoint = $site_url . '/wp-json/wc/v3/products';

        $body = array(
            'name'              => $title,
            'description'       => $desc,
            'short_description' => wp_trim_words( wp_strip_all_tags( $desc ), 20 ),
            'regular_price'     => (string) $price,
            'status'            => 'publish',
        );

        if ( $image_url ) {
            $body['images'] = array( array( 'src' => $image_url ) );
        }

        if ( $category ) {
            $body['categories'] = array( array( 'name' => $category ) );
        }

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $ck . ':' . $cs ),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Lỗi kết nối: ' . $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 201 && $code !== 200 ) {
            $msg = $result['message'] ?? "HTTP {$code}";
            return [ 'success' => false, 'message' => 'Lỗi từ WooCommerce: ' . $msg ];
        }

        return [
            'success'    => true,
            'product_id' => $result['id'] ?? null,
            'url'        => $result['permalink'] ?? '',
            'message'    => 'Tạo sản phẩm thành công trên ' . $site_url,
        ];
    }

    /**
     * Get WP/Woo connection settings. Admin auto-uses local.
     */
    private static function get_wp_settings( int $user_id ): ?array {
        if ( current_user_can( 'manage_options' ) ) {
            $force = get_user_meta( $user_id, 'bztw_force_external_wp', true );
            if ( ! $force ) return null;
        }

        $site_url = get_user_meta( $user_id, 'bztw_wp_site_url', true );
        if ( empty( $site_url ) ) return null;

        return array(
            'site_url'        => $site_url,
            'consumer_key'    => get_user_meta( $user_id, 'bztw_wc_consumer_key', true ),
            'consumer_secret' => get_user_meta( $user_id, 'bztw_wc_consumer_secret', true ),
        );
    }

    /* ══════════════════════════════════════════════════════
     *  Prompt History
     * ══════════════════════════════════════════════════════ */
    public static function handle_poll_history() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $items = BizCity_Woo_Post_Type::get_history( $user_id, 30 );

        wp_send_json_success( array( 'items' => $items ) );
    }

    public static function handle_rerun_prompt() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $history_id = (int) ( $_POST['history_id'] ?? 0 );
        if ( ! $history_id ) wp_send_json_error( 'Thiếu history_id.' );

        $row = BizCity_Woo_Post_Type::get_entry( $history_id, $user_id );

        if ( ! $row ) wp_send_json_error( 'Không tìm thấy prompt.' );

        wp_send_json_success( array( 'prompt' => $row->prompt, 'goal' => $row->goal ) );
    }

    /* ══════════════════════════════════════════════════════
     *  WP/Woo Connection Settings
     * ══════════════════════════════════════════════════════ */
    public static function handle_save_wp_settings() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
        $ck       = sanitize_text_field( wp_unslash( $_POST['consumer_key'] ?? '' ) );
        $cs       = sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ?? '' ) );

        if ( empty( $site_url ) ) {
            delete_user_meta( $user_id, 'bztw_wp_site_url' );
            delete_user_meta( $user_id, 'bztw_wc_consumer_key' );
            delete_user_meta( $user_id, 'bztw_wc_consumer_secret' );
            delete_user_meta( $user_id, 'bztw_force_external_wp' );
            wp_send_json_success( array( 'message' => 'Đã chuyển về WooCommerce nội bộ.' ) );
        }

        if ( empty( $ck ) || empty( $cs ) ) {
            wp_send_json_error( 'Cần nhập Consumer Key và Consumer Secret.' );
        }

        // Test connection
        $test_url = rtrim( $site_url, '/' ) . '/wp-json/wc/v3/products?per_page=1';
        $response = wp_remote_get( $test_url, array(
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $ck . ':' . $cs ) ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Không kết nối được: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 401 || $code === 403 ) {
            wp_send_json_error( 'Sai Consumer Key/Secret (HTTP ' . $code . ').' );
        }
        if ( $code !== 200 ) {
            wp_send_json_error( 'WooCommerce trả về HTTP ' . $code . '. Kiểm tra lại URL.' );
        }

        update_user_meta( $user_id, 'bztw_wp_site_url', $site_url );
        update_user_meta( $user_id, 'bztw_wc_consumer_key', $ck );
        update_user_meta( $user_id, 'bztw_wc_consumer_secret', $cs );
        update_user_meta( $user_id, 'bztw_force_external_wp', '1' );

        wp_send_json_success( array( 'message' => 'Kết nối thành công! Sản phẩm sẽ được tạo trên ' . $site_url ) );
    }

    /* ══════════════════════════════════════════════════════
     *  File Upload
     * ══════════════════════════════════════════════════════ */
    public static function handle_upload_image() {
        check_ajax_referer( 'bztw_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        if ( empty( $_FILES['image'] ) ) wp_send_json_error( 'Không có file.' );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_handle_upload( 'image', 0 );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( 'Lỗi upload: ' . $attach_id->get_error_message() );
        }

        wp_send_json_success( array( 'url' => wp_get_attachment_url( $attach_id ), 'attach_id' => $attach_id ) );
    }

    private static function attach_product_image( int $product_id, string $url ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attach_id = media_sideload_image( $url, $product_id, '', 'id' );
        if ( ! is_wp_error( $attach_id ) ) {
            set_post_thumbnail( $product_id, $attach_id );
        }
    }
}
