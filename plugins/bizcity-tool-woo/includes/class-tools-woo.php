<?php
/**
 * BizCity Tool Woo — Self-contained Tool Callbacks
 *
 * Kiến trúc "Developer-packaged Pipeline":
 *
 *   1. Intent Provider khai báo goal_patterns + required_slots
 *      → Intent Engine nhận diện goal → Planner hỏi user nếu thiếu fields
 *      → Khi đủ slots → call_tool
 *
 *   2. Tool callback (hàm này) thực thi toàn bộ pipeline nội bộ:
 *      AI parse → Thao tác WooCommerce → Trả kết quả
 *      Mỗi step được track qua BizCity_Job_Trace → SSE status events
 *
 *   3. KHÔNG cần executor/preflight/planner pipeline bên ngoài.
 *      Developer đóng gói sẵn toàn bộ logic.
 *
 * Requires:
 *   - WooCommerce ≥ 7.0
 *   - bizcity-openrouter (AI gateway)
 *   - bizcity-admin-hook (legacy helper functions)
 *
 * @package BizCity_Tool_Woo
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Woo {

    /* ══════════════════════════════════════════════════════════
     *  1. create_product — Tạo sản phẩm WooCommerce
     * ══════════════════════════════════════════════════════════
     *
     * Flow:
     *   T1: AI phân tích mô tả sản phẩm (title, price, category, description)
     *   T2: Upload ảnh sản phẩm (skip nếu không có)
     *   T3: Tạo sản phẩm WooCommerce (wp_insert_post + WC meta)
     *
     * @param array $slots {
     *   topic      - Mô tả sản phẩm
     *   message    - Raw input (fallback)
     *   image_url  - URL ảnh sản phẩm (optional)
     *   session_id - Chat session (auto-injected)
     *   chat_id    - Telegram chat ID
     * }
     * @return array Tool Output Envelope
     */
    public static function create_product( array $slots ): array {
        $text       = self::extract_text( $slots );
        $image_url  = $slots['image_url'] ?? '';
        $session_id = $slots['session_id'] ?? '';
        $meta       = $slots['_meta']       ?? [];
        $ai_context = $meta['_context']     ?? '';

        // Normalize image_url — user may type "bỏ qua", "ko", "không" instead of a URL
        $skip_words = [ 'bỏ qua', 'bo qua', 'skip', 'không', 'ko', 'khong', 'no', 'tự tạo', 'auto' ];
        if ( $image_url && in_array( mb_strtolower( trim( $image_url ), 'UTF-8' ), $skip_words, true ) ) {
            $image_url = '';
        }
        // Also handle arrays (planner may store as [url1, url2])
        if ( is_array( $image_url ) ) {
            $image_url = $image_url[0] ?? '';
        }
        // Validate it looks like a URL
        if ( $image_url && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
            $image_url = '';
        }
        $chat_id    = $slots['chat_id']   ?? '';

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Cần mô tả sản phẩm để tạo (tên, giá, mô tả...).',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (bizcity-openrouter) chưa được load.', 'data' => [],
            ];
        }

        // ── Start Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $has_image = ! empty( $image_url );
            $steps = [ 'T1' => 'AI phân tích sản phẩm' ];
            if ( $has_image ) $steps['T2'] = 'Upload ảnh sản phẩm';
            $steps['T3'] = 'Tạo sản phẩm WooCommerce';
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'create_product', $steps );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI phân tích sản phẩm
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        $prompt = "Phân tích mô tả sản phẩm sau và trích xuất thông tin:\n\n"
                . "\"{$text}\"\n\n"
                . "Trả về JSON:\n"
                . "{\n"
                . "  \"title\": \"Tên sản phẩm\",\n"
                . "  \"price\": 0,\n"
                . "  \"sale_price\": 0,\n"
                . "  \"category\": \"Danh mục\",\n"
                . "  \"description\": \"Mô tả chi tiết HTML\",\n"
                . "  \"short_description\": \"Mô tả ngắn\",\n"
                . "  \"sku\": \"Mã SP (nếu có)\"\n"
                . "}";

        $sys_prompt = 'Bạn là trợ lý bán hàng WooCommerce. Chỉ trả JSON, không giải thích. Giá là số nguyên VNĐ (không có đơn vị). Nếu thiếu thông tin, để trống.';
        if ( $ai_context ) {
            $sys_prompt .= "\n\n" . $ai_context;
        }
        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_prompt ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [ 'temperature' => 0.3, 'max_tokens' => 1000 ] );

        $raw    = $ai_result['message'] ?? '';
        $parsed = self::parse_json_response( $raw );

        $title       = $parsed['title']             ?? wp_trim_words( $text, 8 );
        $price       = floatval( $parsed['price']   ?? 0 );
        $sale_price  = floatval( $parsed['sale_price'] ?? 0 );
        $category    = $parsed['category']          ?? '';
        $description = $parsed['description']       ?? $text;
        $short_desc  = $parsed['short_description'] ?? '';
        $sku         = $parsed['sku']               ?? '';

        if ( empty( $title ) ) {
            if ( $trace ) $trace->fail( 'AI không trích xuất được tên sản phẩm' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không nhận diện được tên sản phẩm. Thử mô tả rõ hơn.', 'data' => [],
            ];
        }

        if ( $trace ) $trace->step( 'T1', 'done', [ 'title' => $title, 'price' => $price ] );

        // ══════════════════════════════════════════════════════
        //  T2: Upload ảnh sản phẩm (optional)
        // ══════════════════════════════════════════════════════
        $attachment_id = 0;
        if ( ! empty( $image_url ) ) {
            if ( $trace ) $trace->step( 'T2', 'running' );

            if ( function_exists( 'twf_upload_image_to_media_library' ) ) {
                $attachment_id = twf_upload_image_to_media_library( $image_url, sanitize_title( $title ) );
            } else {
                // Fallback: media_sideload_image
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attachment_id = media_sideload_image( $image_url, 0, $title, 'id' );
                if ( is_wp_error( $attachment_id ) ) $attachment_id = 0;
            }

            if ( $trace ) $trace->step( 'T2', $attachment_id ? 'done' : 'skipped', [ 'attachment_id' => $attachment_id ] );
        }

        // ══════════════════════════════════════════════════════
        //  T3: Tạo sản phẩm WooCommerce
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T3', 'running' );

        $product_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $description,
            'post_excerpt'  => $short_desc,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ] );

        if ( ! $product_id || is_wp_error( $product_id ) ) {
            if ( $trace ) $trace->fail( 'wp_insert_post thất bại' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không tạo được sản phẩm. Lỗi WordPress.', 'data' => [],
            ];
        }

        // WooCommerce meta
        wp_set_object_terms( $product_id, 'simple', 'product_type' );
        update_post_meta( $product_id, '_visibility', 'visible' );
        update_post_meta( $product_id, '_stock_status', 'instock' );

        if ( $price > 0 ) {
            update_post_meta( $product_id, '_regular_price', $price );
            update_post_meta( $product_id, '_price', $sale_price > 0 ? $sale_price : $price );
        }
        if ( $sale_price > 0 && $sale_price < $price ) {
            update_post_meta( $product_id, '_sale_price', $sale_price );
        }
        if ( $sku ) {
            update_post_meta( $product_id, '_sku', $sku );
        }

        // Category
        if ( $category ) {
            $term = term_exists( $category, 'product_cat' );
            if ( ! $term ) {
                $term = wp_insert_term( $category, 'product_cat' );
            }
            if ( $term && ! is_wp_error( $term ) ) {
                $term_id = is_array( $term ) ? $term['term_id'] : $term;
                wp_set_object_terms( $product_id, intval( $term_id ), 'product_cat' );
            }
        }

        // Featured image
        if ( $attachment_id ) {
            set_post_thumbnail( $product_id, $attachment_id );
        }

        $product_url = get_permalink( $product_id );
        $edit_url    = admin_url( "post.php?post={$product_id}&action=edit" );
        $price_text  = $price > 0 ? number_format( $price, 0, ',', '.' ) . 'đ' : 'Chưa set giá';

        if ( $trace ) {
            $trace->step( 'T3', 'done', [ 'product_id' => $product_id ] );
            $trace->complete( [ 'product_id' => $product_id, 'product_url' => $product_url ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo sản phẩm: **{$title}**\n💰 Giá: {$price_text}\n🔗 Xem: {$product_url}\n✏️ Sửa: {$edit_url}",
            'data'     => [
                'id'      => $product_id,
                'type'    => 'product',
                'content' => $product_url,
                'meta'    => [
                    'product_id'  => $product_id,
                    'product_url' => $product_url,
                    'title'       => $title,
                    'price'       => $price,
                    'sale_price'  => $sale_price,
                    'category'    => $category,
                ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  2. edit_product — Sửa sản phẩm WooCommerce
     * ══════════════════════════════════════════════════════════
     *
     * Flow:
     *   T1: AI phân tích yêu cầu sửa (tìm SP nào, sửa gì)
     *   T2: Tìm & cập nhật sản phẩm
     *
     * @param array $slots {
     *   topic      - Yêu cầu sửa sản phẩm
     *   message    - Raw input (fallback)
     *   session_id - Chat session
     *   chat_id    - Telegram chat ID
     * }
     * @return array Tool Output Envelope
     */
    public static function edit_product( array $slots ): array {
        $text       = self::extract_text( $slots );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Cần mô tả sản phẩm cần sửa và nội dung thay đổi.',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (bizcity-openrouter) chưa được load.', 'data' => [],
            ];
        }

        // ── Start Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'edit_product', [
                'T1' => 'AI phân tích yêu cầu sửa',
                'T2' => 'Cập nhật sản phẩm',
            ] );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI phân tích yêu cầu sửa
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        $prompt = "Phân tích yêu cầu sửa sản phẩm WooCommerce sau:\n\n"
                . "\"{$text}\"\n\n"
                . "Trả về JSON:\n"
                . "{\n"
                . "  \"find_by\": \"id hoặc name\",\n"
                . "  \"identity\": \"ID số hoặc tên sản phẩm\",\n"
                . "  \"update\": {\n"
                . "    \"title\": \"Tên mới (nếu sửa)\",\n"
                . "    \"price\": 0,\n"
                . "    \"sale_price\": 0,\n"
                . "    \"description\": \"Mô tả mới\",\n"
                . "    \"category\": \"Danh mục mới\",\n"
                . "    \"stock_quantity\": 0\n"
                . "  }\n"
                . "}\n"
                . "Chỉ đưa các trường cần sửa vào update. Bỏ trường không sửa.";

        $sys_edit = 'Bạn là trợ lý WooCommerce. Chỉ trả JSON. Giá là số VNĐ. find_by = "id" nếu có số ID, "name" nếu chỉ có tên.';
        if ( $ai_context ) {
            $sys_edit .= "\n\n" . $ai_context;
        }
        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_edit ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [ 'temperature' => 0.2, 'max_tokens' => 800 ] );

        $raw    = $ai_result['message'] ?? '';
        $parsed = self::parse_json_response( $raw );

        $find_by  = $parsed['find_by']  ?? 'name';
        $identity = $parsed['identity'] ?? '';
        $updates  = $parsed['update']   ?? [];

        if ( empty( $identity ) ) {
            if ( $trace ) $trace->fail( 'Không xác định được sản phẩm' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không xác định được sản phẩm cần sửa. Gõ ID hoặc tên rõ hơn.', 'data' => [],
            ];
        }

        if ( $trace ) $trace->step( 'T1', 'done', [ 'find_by' => $find_by, 'identity' => $identity ] );

        // ══════════════════════════════════════════════════════
        //  T2: Tìm & cập nhật sản phẩm
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T2', 'running' );

        $product_id = self::find_product( $find_by, $identity );

        if ( ! $product_id ) {
            if ( $trace ) $trace->fail( "Không tìm thấy SP: {$identity}" );
            return [
                'success' => false, 'complete' => false,
                'message' => "❌ Không tìm thấy sản phẩm: {$identity}", 'data' => [],
            ];
        }

        $changed = [];

        // Title
        if ( ! empty( $updates['title'] ) ) {
            wp_update_post( [ 'ID' => $product_id, 'post_title' => $updates['title'] ] );
            $changed[] = 'tên';
        }

        // Description
        if ( ! empty( $updates['description'] ) ) {
            wp_update_post( [ 'ID' => $product_id, 'post_content' => $updates['description'] ] );
            $changed[] = 'mô tả';
        }

        // Price
        if ( isset( $updates['price'] ) && $updates['price'] > 0 ) {
            update_post_meta( $product_id, '_regular_price', floatval( $updates['price'] ) );
            update_post_meta( $product_id, '_price', floatval( $updates['price'] ) );
            $changed[] = 'giá';
        }

        // Sale price
        if ( isset( $updates['sale_price'] ) && $updates['sale_price'] > 0 ) {
            update_post_meta( $product_id, '_sale_price', floatval( $updates['sale_price'] ) );
            update_post_meta( $product_id, '_price', floatval( $updates['sale_price'] ) );
            $changed[] = 'giá khuyến mãi';
        }

        // Stock
        if ( isset( $updates['stock_quantity'] ) && $updates['stock_quantity'] >= 0 ) {
            update_post_meta( $product_id, '_manage_stock', 'yes' );
            update_post_meta( $product_id, '_stock', intval( $updates['stock_quantity'] ) );
            $changed[] = 'tồn kho';
        }

        // Category
        if ( ! empty( $updates['category'] ) ) {
            $term = term_exists( $updates['category'], 'product_cat' );
            if ( ! $term ) $term = wp_insert_term( $updates['category'], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_id = is_array( $term ) ? $term['term_id'] : $term;
                wp_set_object_terms( $product_id, intval( $term_id ), 'product_cat' );
                $changed[] = 'danh mục';
            }
        }

        $product_title = get_the_title( $product_id );
        $changed_text  = ! empty( $changed ) ? implode( ', ', $changed ) : 'không có thay đổi';

        if ( $trace ) {
            $trace->step( 'T2', 'done', [ 'product_id' => $product_id, 'changed' => $changed ] );
            $trace->complete( [ 'product_id' => $product_id ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã cập nhật sản phẩm **{$product_title}** (#{$product_id})\n📝 Thay đổi: {$changed_text}",
            'data'     => [
                'id'   => $product_id,
                'type' => 'product',
                'meta' => [ 'product_id' => $product_id, 'changed' => $changed ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  3. create_order — Tạo đơn hàng WooCommerce
     * ══════════════════════════════════════════════════════════
     *
     * Delegates to legacy twf_handle_create_order_ai_flow() which handles
     * complex POS integration, payment methods, coupon, Telegram notification.
     *
     * Flow:
     *   T1: AI phân tích đơn hàng
     *   T2: Tạo đơn WooCommerce
     *
     * @param array $slots {
     *   topic      - Mô tả đơn hàng
     *   message    - Raw input (fallback for legacy function)
     *   session_id - Chat session
     *   chat_id    - Telegram chat ID
     * }
     * @return array Tool Output Envelope
     */
    public static function create_order( array $slots ): array {
        $text       = self::extract_text( $slots );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';
        $meta       = $slots['_meta']      ?? [];
        $ai_context = $meta['_context']    ?? '';

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Cần mô tả đơn hàng (khách hàng, sản phẩm, SĐT, địa chỉ).',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        // ── Start Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'create_order', [
                'T1' => 'AI phân tích đơn hàng',
                'T2' => 'Tạo đơn WooCommerce',
            ] );
        }

        // ══════════════════════════════════════════════════════
        //  Delegate to legacy flow (battle-tested, POS + payment + Telegram)
        // ══════════════════════════════════════════════════════
        if ( function_exists( 'twf_handle_create_order_ai_flow' ) ) {
            if ( $trace ) $trace->step( 'T1', 'running' );

            // Build message array compatible with legacy function
            $message = is_array( $slots['message'] ?? null )
                ? $slots['message']
                : [ 'text' => $text, 'chat' => [ 'id' => $chat_id ] ];

            if ( $trace ) $trace->step( 'T1', 'done' );
            if ( $trace ) $trace->step( 'T2', 'running' );

            ob_start();
            twf_handle_create_order_ai_flow( $message, $chat_id );
            ob_end_clean();

            // Try to find the order just created
            $recent_orders = wc_get_orders( [
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
            ] );

            $order_id = ! empty( $recent_orders ) ? $recent_orders[0]->get_id() : 0;

            if ( $trace ) {
                $trace->step( 'T2', 'done', [ 'order_id' => $order_id ] );
                $trace->complete( [ 'order_id' => $order_id ] );
            }

            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                $total = $order ? number_format( $order->get_total(), 0, ',', '.' ) . 'đ' : '';
                return [
                    'success'  => true,
                    'complete' => true,
                    'message'  => "✅ Đã tạo đơn hàng #{$order_id}" . ( $total ? " — {$total}" : '' ),
                    'data'     => [
                        'id'   => $order_id,
                        'type' => 'order',
                        'meta' => [ 'order_id' => $order_id, 'total' => $order ? $order->get_total() : 0 ],
                    ],
                ];
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => '✅ Đã gửi yêu cầu tạo đơn hàng.',
                'data'     => [ 'type' => 'order' ],
            ];
        }

        // ── Fallback: tạo đơn trực tiếp nếu legacy flow không có ──
        if ( ! function_exists( 'bizcity_openrouter_chat' ) || ! function_exists( 'wc_create_order' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module tạo đơn hàng chưa sẵn sàng (cần WooCommerce + AI).', 'data' => [],
            ];
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        $prompt = "Phân tích yêu cầu tạo đơn hàng:\n\n\"{$text}\"\n\n"
                . "Trả về JSON:\n"
                . "{\n"
                . "  \"customer\": { \"name\": \"\", \"phone\": \"\", \"email\": \"\", \"address\": \"\" },\n"
                . "  \"products\": [ { \"name\": \"\", \"qty\": 1, \"price\": 0 } ],\n"
                . "  \"note\": \"\"\n"
                . "}";

        $sys_order = 'Bạn là trợ lý bán hàng. Chỉ trả JSON. Giá VNĐ số nguyên.';
        if ( $ai_context ) {
            $sys_order .= "\n\n" . $ai_context;
        }
        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_order ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [ 'temperature' => 0.2, 'max_tokens' => 1000 ] );

        $parsed = self::parse_json_response( $ai_result['message'] ?? '' );
        $customer = $parsed['customer'] ?? [];
        $products = $parsed['products'] ?? [];

        if ( $trace ) $trace->step( 'T1', 'done', [ 'products_count' => count( $products ) ] );
        if ( $trace ) $trace->step( 'T2', 'running' );

        $order = wc_create_order();
        if ( is_wp_error( $order ) ) {
            if ( $trace ) $trace->fail( 'wc_create_order thất bại' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không tạo được đơn hàng.', 'data' => [],
            ];
        }

        // Add products
        foreach ( $products as $item ) {
            $pid = self::find_product( 'name', $item['name'] ?? '' );
            $qty = intval( $item['qty'] ?? 1 );
            if ( $pid ) {
                $product = wc_get_product( $pid );
                if ( $product ) $order->add_product( $product, $qty );
            }
        }

        // Billing
        if ( ! empty( $customer['name'] ) )    $order->set_billing_first_name( $customer['name'] );
        if ( ! empty( $customer['phone'] ) )   $order->set_billing_phone( $customer['phone'] );
        if ( ! empty( $customer['email'] ) )   $order->set_billing_email( $customer['email'] );
        if ( ! empty( $customer['address'] ) ) $order->set_billing_address_1( $customer['address'] );

        $order->calculate_totals();
        $order->save();

        $order_id = $order->get_id();
        $total    = number_format( $order->get_total(), 0, ',', '.' ) . 'đ';

        if ( $trace ) {
            $trace->step( 'T2', 'done', [ 'order_id' => $order_id ] );
            $trace->complete( [ 'order_id' => $order_id ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo đơn hàng #{$order_id} — {$total}",
            'data'     => [
                'id'   => $order_id,
                'type' => 'order',
                'meta' => [ 'order_id' => $order_id, 'total' => $order->get_total() ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  4. order_stats — Thống kê đơn hàng / doanh thu
     * ══════════════════════════════════════════════════════════
     *
     * @param array $slots {
     *   so_ngay   - Số ngày (default 7)
     *   from_date - Từ ngày YYYY-MM-DD
     *   to_date   - Đến ngày YYYY-MM-DD
     *   session_id, chat_id
     * }
     * @return array Tool Output Envelope
     */
    public static function order_stats( array $slots ): array {
        $so_ngay   = intval( $slots['so_ngay']   ?? 7 );
        $to_date   = $slots['to_date']   ?? current_time( 'Y-m-d' );
        $from_date = $slots['from_date'] ?? date( 'Y-m-d', strtotime( $to_date . ' -' . ( $so_ngay - 1 ) . ' days' ) );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        // ── Start Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'order_stats', [
                'T1' => 'Truy vấn dữ liệu thống kê',
            ] );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        if ( ! function_exists( 'twf_get_order_stats_range' ) ) {
            if ( $trace ) $trace->fail( 'Module thống kê chưa load' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module thống kê chưa được load (bizcity-admin-hook).', 'data' => [],
            ];
        }

        $stats = twf_get_order_stats_range( $from_date, $to_date );

        $total_orders = $stats['total_orders'] ?? 0;
        $total_amount = $stats['total_amount'] ?? 0;
        $best_selling = $stats['best_selling'] ?? [];

        // Format top products
        $top_products = '';
        $i = 1;
        foreach ( array_slice( $best_selling, 0, 5, true ) as $product_id => $qty ) {
            $name = get_the_title( $product_id ) ?: "SP #{$product_id}";
            $top_products .= "{$i}. {$name}: {$qty} cái\n";
            $i++;
        }

        $msg = "📊 **Báo cáo doanh thu** ({$from_date} → {$to_date})\n"
             . "📦 Tổng đơn: {$total_orders}\n"
             . "💰 Doanh thu: " . number_format( $total_amount, 0, ',', '.' ) . "đ\n"
             . ( $top_products ? "\n🏆 **Top sản phẩm:**\n{$top_products}" : '' );

        if ( $trace ) {
            $trace->step( 'T1', 'done', [ 'total_orders' => $total_orders ] );
            $trace->complete( [ 'total_orders' => $total_orders, 'total_amount' => $total_amount ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'id'      => null,
                'type'    => 'data',
                'content' => $msg,
                'meta'    => [
                    'total_orders' => $total_orders,
                    'total_amount' => $total_amount,
                    'from_date'    => $from_date,
                    'to_date'      => $to_date,
                    'best_selling' => array_slice( $best_selling, 0, 10, true ),
                ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  5. product_stats — Top sản phẩm bán chạy
     * ══════════════════════════════════════════════════════════
     *
     * @param array $slots { so_ngay (default 3), session_id, chat_id }
     * @return array Tool Output Envelope
     */
    public static function product_stats( array $slots ): array {
        $so_ngay    = intval( $slots['so_ngay'] ?? 3 );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'product_stats', [
                'T1' => 'Truy vấn top sản phẩm',
            ] );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        // Direct SQL query for product sales (similar to twf_bao_cao_top_product)
        global $wpdb;
        $date_start = date( 'Y-m-d', strtotime( "-{$so_ngay} days" ) );
        $date_end   = current_time( 'Y-m-d' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT oi.order_item_name AS product_name,
                    SUM( oim.meta_value ) AS total_qty,
                    SUM( oim2.meta_value ) AS total_revenue
             FROM {$wpdb->prefix}woocommerce_order_items AS oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_line_total'
             INNER JOIN {$wpdb->posts} AS p ON oi.order_id = p.ID
             WHERE oi.order_item_type = 'line_item'
               AND p.post_type = 'shop_order'
               AND p.post_status IN ('wc-completed', 'wc-processing')
               AND p.post_date >= %s AND p.post_date <= %s
             GROUP BY oi.order_item_name
             ORDER BY total_qty DESC
             LIMIT 10",
            $date_start . ' 00:00:00',
            $date_end . ' 23:59:59'
        ) );

        $msg = "🏆 **Top sản phẩm bán chạy** ({$so_ngay} ngày gần nhất)\n\n";
        if ( empty( $results ) ) {
            $msg .= "ℹ️ Chưa có dữ liệu bán hàng trong khoảng thời gian này.";
        } else {
            foreach ( $results as $i => $row ) {
                $rank    = $i + 1;
                $qty     = intval( $row->total_qty );
                $revenue = number_format( floatval( $row->total_revenue ), 0, ',', '.' );
                $msg    .= "{$rank}. {$row->product_name} — {$qty} cái — {$revenue}đ\n";
            }
        }

        if ( $trace ) {
            $trace->step( 'T1', 'done', [ 'count' => count( $results ) ] );
            $trace->complete( [ 'top_count' => count( $results ) ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'id'      => null,
                'type'    => 'data',
                'content' => $msg,
                'meta'    => [ 'so_ngay' => $so_ngay, 'top_products' => $results ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  6. customer_stats — Top khách hàng
     * ══════════════════════════════════════════════════════════
     *
     * @param array $slots { so_ngay (default 3), session_id, chat_id }
     * @return array Tool Output Envelope
     */
    public static function customer_stats( array $slots ): array {
        $so_ngay    = intval( $slots['so_ngay'] ?? 3 );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'customer_stats', [
                'T1' => 'Truy vấn top khách hàng',
            ] );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        global $wpdb;
        $date_start = date( 'Y-m-d', strtotime( "-{$so_ngay} days" ) );
        $date_end   = current_time( 'Y-m-d' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm_name.meta_value AS customer_name,
                    pm_phone.meta_value AS phone,
                    COUNT( DISTINCT p.ID ) AS order_count,
                    SUM( pm_total.meta_value ) AS total_spent
             FROM {$wpdb->posts} AS p
             LEFT JOIN {$wpdb->postmeta} AS pm_name ON p.ID = pm_name.post_id AND pm_name.meta_key = '_billing_first_name'
             LEFT JOIN {$wpdb->postmeta} AS pm_phone ON p.ID = pm_phone.post_id AND pm_phone.meta_key = '_billing_phone'
             LEFT JOIN {$wpdb->postmeta} AS pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
             WHERE p.post_type = 'shop_order'
               AND p.post_status IN ('wc-completed', 'wc-processing')
               AND p.post_date >= %s AND p.post_date <= %s
             GROUP BY pm_phone.meta_value
             ORDER BY total_spent DESC
             LIMIT 10",
            $date_start . ' 00:00:00',
            $date_end . ' 23:59:59'
        ) );

        $msg = "👥 **Top khách hàng** ({$so_ngay} ngày gần nhất)\n\n";
        if ( empty( $results ) ) {
            $msg .= "ℹ️ Chưa có dữ liệu khách hàng trong khoảng thời gian này.";
        } else {
            foreach ( $results as $i => $row ) {
                $rank  = $i + 1;
                $name  = $row->customer_name ?: 'Không tên';
                $phone = $row->phone ?: 'N/A';
                $count = intval( $row->order_count );
                $spent = number_format( floatval( $row->total_spent ), 0, ',', '.' );
                $msg  .= "{$rank}. {$name} ({$phone}) — {$count} đơn — {$spent}đ\n";
            }
        }

        if ( $trace ) {
            $trace->step( 'T1', 'done', [ 'count' => count( $results ) ] );
            $trace->complete( [ 'top_count' => count( $results ) ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'id'      => null,
                'type'    => 'data',
                'content' => $msg,
                'meta'    => [ 'so_ngay' => $so_ngay, 'top_customers' => $results ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  7. find_customer — Tra cứu khách hàng theo SĐT
     * ══════════════════════════════════════════════════════════
     *
     * @param array $slots { phone, session_id, chat_id }
     * @return array Tool Output Envelope
     */
    public static function find_customer( array $slots ): array {
        $phone      = $slots['phone']      ?? '';
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        if ( empty( $phone ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Vui lòng cung cấp số điện thoại khách hàng.',
                'data'           => [],
                'missing_fields' => [ 'phone' ],
            ];
        }

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'find_customer', [
                'T1' => 'Tìm đơn hàng theo SĐT',
            ] );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        // Query orders by billing phone
        global $wpdb;
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_billing_phone' AND meta_value LIKE %s
             ORDER BY post_id DESC LIMIT 10",
            '%' . $wpdb->esc_like( $phone ) . '%'
        ) );

        if ( empty( $order_ids ) ) {
            if ( $trace ) {
                $trace->step( 'T1', 'done', [ 'found' => false ] );
                $trace->complete();
            }
            return [
                'success'  => true,
                'complete' => true,
                'message'  => "ℹ️ Không tìm thấy khách hàng với SĐT: {$phone}",
                'data'     => [ 'type' => 'data', 'meta' => [ 'phone' => $phone, 'found' => false ] ],
            ];
        }

        // Get customer info from most recent order
        $latest_order = wc_get_order( $order_ids[0] );
        $customer = [
            'name'    => $latest_order ? $latest_order->get_formatted_billing_full_name() : '',
            'phone'   => $latest_order ? $latest_order->get_billing_phone() : $phone,
            'email'   => $latest_order ? $latest_order->get_billing_email() : '',
            'address' => $latest_order ? $latest_order->get_billing_address_1() : '',
        ];

        // Build order history
        $order_lines = '';
        foreach ( array_slice( $order_ids, 0, 5 ) as $oid ) {
            $o = wc_get_order( $oid );
            if ( ! $o ) continue;
            $status = wc_get_order_status_name( $o->get_status() );
            $total  = number_format( $o->get_total(), 0, ',', '.' );
            $date   = $o->get_date_created() ? $o->get_date_created()->format( 'd/m/Y' ) : '';
            $order_lines .= "  #{$oid} — {$status} — {$total}đ — {$date}\n";
        }

        $msg = "👤 **Khách hàng:** {$customer['name']}\n"
             . "📞 SĐT: {$customer['phone']}\n"
             . ( $customer['email'] ? "📧 Email: {$customer['email']}\n" : '' )
             . ( $customer['address'] ? "📍 Địa chỉ: {$customer['address']}\n" : '' )
             . "\n📦 **Lịch sử đơn hàng** (" . count( $order_ids ) . " đơn):\n"
             . $order_lines;

        if ( $trace ) {
            $trace->step( 'T1', 'done', [ 'found' => true, 'order_count' => count( $order_ids ) ] );
            $trace->complete( [ 'customer' => $customer ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'id'   => $order_ids[0],
                'type' => 'data',
                'meta' => [
                    'customer'  => $customer,
                    'order_ids' => $order_ids,
                ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  8. inventory_report — Báo cáo xuất nhập tồn kho
     * ══════════════════════════════════════════════════════════
     *
     * @param array $slots { from_date, to_date, session_id, chat_id }
     * @return array Tool Output Envelope
     */
    public static function inventory_report( array $slots ): array {
        $from_date  = $slots['from_date'] ?? date( 'Y-m-01' );
        $to_date    = $slots['to_date']   ?? current_time( 'Y-m-d' );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'inventory_report', [
                'T1' => 'Truy vấn dữ liệu kho',
            ] );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        // Try legacy function first
        if ( function_exists( 'twf_bao_cao_xuat_nhap_ton_kho' ) ) {
            ob_start();
            twf_bao_cao_xuat_nhap_ton_kho( $chat_id, $from_date, $to_date );
            $output = ob_get_clean();

            if ( $trace ) {
                $trace->step( 'T1', 'done' );
                $trace->complete();
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => "✅ Đã xuất báo cáo kho ({$from_date} → {$to_date}).",
                'data'     => [
                    'id'      => null,
                    'type'    => 'data',
                    'content' => $output ?: "Báo cáo xuất nhập tồn kho ({$from_date} → {$to_date})",
                    'meta'    => [ 'from_date' => $from_date, 'to_date' => $to_date ],
                ],
            ];
        }

        // Fallback: basic stock overview from WooCommerce
        global $wpdb;
        $products = $wpdb->get_results(
            "SELECT p.ID, p.post_title, pm.meta_value AS stock
             FROM {$wpdb->posts} AS p
             INNER JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id AND pm.meta_key = '_stock'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND pm.meta_value IS NOT NULL AND pm.meta_value != ''
             ORDER BY CAST(pm.meta_value AS SIGNED) ASC
             LIMIT 20"
        );

        $msg = "📦 **Tồn kho hiện tại** (top 20 SP tồn thấp nhất)\n\n";
        foreach ( $products as $row ) {
            $stock = intval( $row->stock );
            $icon  = $stock <= 0 ? '🔴' : ( $stock <= 5 ? '🟡' : '🟢' );
            $msg  .= "{$icon} {$row->post_title}: {$stock}\n";
        }

        if ( $trace ) {
            $trace->step( 'T1', 'done', [ 'count' => count( $products ) ] );
            $trace->complete();
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'id'      => null,
                'type'    => 'data',
                'content' => $msg,
                'meta'    => [ 'from_date' => $from_date, 'to_date' => $to_date ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  9. warehouse_receipt — Tạo phiếu nhập kho
     * ══════════════════════════════════════════════════════════
     *
     * Flow:
     *   T1: AI phân tích phiếu nhập
     *   T2: Tạo phiếu nhập kho
     *
     * @param array $slots {
     *   topic      - Mô tả phiếu nhập (tên SP, SL, giá mua)
     *   message    - Raw input (fallback)
     *   session_id, chat_id
     * }
     * @return array Tool Output Envelope
     */
    public static function warehouse_receipt( array $slots ): array {
        $text       = self::extract_text( $slots );
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';
        $meta       = $slots['_meta']      ?? [];
        $ai_context = $meta['_context']    ?? '';

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Vui lòng mô tả phiếu nhập kho: tên sản phẩm, số lượng, giá mua.',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'warehouse_receipt', [
                'T1' => 'AI phân tích phiếu nhập',
                'T2' => 'Tạo phiếu nhập kho',
            ] );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI phân tích hoặc legacy parse
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        if ( function_exists( 'twf_parse_phieu_nhap_kho_ai' ) ) {
            $data = twf_parse_phieu_nhap_kho_ai( $text );
        } elseif ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $prompt = "Phân tích phiếu nhập kho:\n\n\"{$text}\"\n\n"
                    . "JSON: { \"product_name\": \"\", \"product_id\": 0, \"qty\": 0, \"buy_price\": 0, \"note\": \"\" }";
            $sys_warehouse = 'Trợ lý kho hàng. Chỉ trả JSON. product_id=0 nếu không biết.';
            if ( $ai_context ) {
                $sys_warehouse .= "\n\n" . $ai_context;
            }
            $ai_result = bizcity_openrouter_chat( [
                [ 'role' => 'system', 'content' => $sys_warehouse ],
                [ 'role' => 'user',   'content' => $prompt ],
            ], [ 'temperature' => 0.2 ] );
            $data = self::parse_json_response( $ai_result['message'] ?? '' );
        } else {
            if ( $trace ) $trace->fail( 'Không có module AI' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI chưa sẵn sàng.', 'data' => [],
            ];
        }

        $product_id   = intval( $data['product_id']   ?? 0 );
        $product_name = $data['product_name'] ?? '';
        $qty          = intval( $data['qty']           ?? 0 );
        $buy_price    = floatval( $data['buy_price']   ?? 0 );

        if ( $trace ) $trace->step( 'T1', 'done', [ 'product_name' => $product_name, 'qty' => $qty ] );

        // ══════════════════════════════════════════════════════
        //  T2: Tạo phiếu nhập kho
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T2', 'running' );

        if ( function_exists( 'twf_phieu_nhap_kho_from_telegram' ) ) {
            ob_start();
            twf_phieu_nhap_kho_from_telegram( $chat_id, $data );
            ob_end_clean();

            if ( $trace ) {
                $trace->step( 'T2', 'done' );
                $trace->complete( [ 'product_id' => $product_id ] );
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => "✅ Đã tạo phiếu nhập kho: {$product_name} × {$qty}"
                            . ( $buy_price ? ' — ' . number_format( $buy_price, 0, ',', '.' ) . 'đ/cái' : '' ),
                'data'     => [
                    'id'   => $product_id ?: null,
                    'type' => 'data',
                    'meta' => [
                        'product_id'   => $product_id,
                        'product_name' => $product_name,
                        'qty'          => $qty,
                        'buy_price'    => $buy_price,
                    ],
                ],
            ];
        }

        // Fallback: update stock directly if no legacy function
        if ( $product_id && $qty > 0 ) {
            $current = intval( get_post_meta( $product_id, '_stock', true ) );
            update_post_meta( $product_id, '_manage_stock', 'yes' );
            update_post_meta( $product_id, '_stock', $current + $qty );
            update_post_meta( $product_id, '_stock_status', 'instock' );
        }

        if ( $trace ) {
            $trace->step( 'T2', 'done' );
            $trace->complete( [ 'product_id' => $product_id ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã nhập kho" . ( $product_name ? ": {$product_name} × {$qty}" : '' ),
            'data'     => [
                'id'   => $product_id ?: null,
                'type' => 'data',
                'meta' => [ 'product_id' => $product_id, 'qty' => $qty, 'buy_price' => $buy_price ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  Private Helpers
     * ══════════════════════════════════════════════════════════ */

    /**
     * Extract plain text from $slots — handles message array, topic, or string.
     */
    private static function extract_text( array $slots ): string {
        // Priority: topic → message text → message caption → raw string
        if ( ! empty( $slots['topic'] ) ) {
            return is_string( $slots['topic'] ) ? trim( $slots['topic'] ) : '';
        }

        $msg = $slots['message'] ?? '';
        if ( is_array( $msg ) ) {
            return trim( $msg['text'] ?? $msg['caption'] ?? '' );
        }
        return is_string( $msg ) ? trim( $msg ) : '';
    }

    /**
     * Find WooCommerce product by ID or name search.
     *
     * @param string $find_by  'id' or 'name'
     * @param string $identity  Product ID or search term
     * @return int  Product ID or 0 if not found
     */
    private static function find_product( string $find_by, string $identity ): int {
        if ( empty( $identity ) ) return 0;

        // Try direct ID first
        if ( $find_by === 'id' || is_numeric( $identity ) ) {
            $pid = intval( $identity );
            if ( $pid > 0 && get_post_type( $pid ) === 'product' ) {
                return $pid;
            }
        }

        // Search by name
        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
               AND post_title LIKE %s
             ORDER BY ID DESC LIMIT 1",
            '%' . $wpdb->esc_like( $identity ) . '%'
        ) );

        return intval( $product_id );
    }

    /**
     * Parse JSON from AI response — handles markdown code blocks.
     *
     * @param string $raw  Raw AI output (may contain ```json ... ```)
     * @return array  Parsed array or empty array on failure
     */
    private static function parse_json_response( string $raw ): array {
        if ( empty( $raw ) ) return [];

        // Strip markdown code fences
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $raw = preg_replace( '/\s*```$/', '', $raw );

        // Try JSON decode
        $decoded = json_decode( trim( $raw ), true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        // Try to extract JSON object from text
        if ( preg_match( '/\{[\s\S]*\}/m', $raw, $m ) ) {
            $decoded = json_decode( $m[0], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
        }

        error_log( '[BizCity Tool Woo] JSON parse failed: ' . substr( $raw, 0, 200 ) );
        return [];
    }
}
