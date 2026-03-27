<?php
/**
 * BizCity Tarot – Chat Gateway Integration
 *
 * Mở rộng bizcity-knowledge / Chat Gateway:
 *   1. Nhận diện intent bói bài trong tin nhắn ADMINCHAT / Zalo Bot / Zalo Personal
 *      → sinh URL bảo mật (token 48h) → gửi link cho người dùng
 *   2. Sau khi bốc bài xong, JS frontend gọi AJAX `bct_push_reading`
 *      → máy chủ gửi kết quả luận giải qua kênh tin nhắn ban đầu
 *   3. Tự động chia messages > 2 000 ký tự thành nhiều tin nhắn nhỏ hơn
 *
 * Hook điểm: `bizcity_unified_message_intent` (filter, added in bootstrap.php)
 *
 * @package BizCity_Tarot
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * 1. INTENT DETECTION
 * ------------------------------------------------------------- */

/**
 * Trích xuất cụm chủ đề từ câu nhắn gốc bằng cách bỏ phần prefix intent.
 * VD: "bốc bài tarot về công việc của tôi hôm nay" → "công việc của tôi hôm nay"
 *     "xin kết nối năng lượng vũ trụ cho biết hôm nay thế nào" → giữ nguyên (không strip)
 */
function bct_extract_topic_phrase( string $text ): string {
    $text = trim( $text );
    if ( empty( $text ) ) return '';

    // Các prefix intent cần gỡ bỏ (regex, không phân biệt hoa thường)
    $prefixes = [
        '(?:xin\s+)?bốc\s+bài\s+(?:tarot\s+)?(?:cho\s+tôi\s+)?(?:về\s+)?',
        '(?:xin\s+)?rút\s+bài\s+(?:tarot\s+)?(?:cho\s+tôi\s+)?(?:về\s+)?',
        '(?:xin\s+)?trải\s+bài\s+(?:tarot\s+)?(?:cho\s+tôi\s+)?(?:về\s+)?',
        '(?:xin\s+)?xem\s+bài\s+(?:tarot\s+)?(?:cho\s+tôi\s+)?(?:về\s+)?',
        '(?:xin\s+)?bói\s+(?:bài\s+)?(?:tarot\s+)?(?:cho\s+tôi\s+)?(?:về\s+)?',
        'tarot\s+(?:về\s+)?',
        '(?:cho\s+tôi\s+)?(?:xem\s+)?gieo\s+quẻ\s+(?:về\s+)?',
    ];

    $lower = mb_strtolower( $text, 'UTF-8' );
    foreach ( $prefixes as $pattern ) {
        if ( preg_match( '/^' . $pattern . '/ui', $lower, $m ) ) {
            $stripped = mb_substr( $text, mb_strlen( $m[0], 'UTF-8' ), null, 'UTF-8' );
            $stripped = trim( $stripped );
            if ( mb_strlen( $stripped, 'UTF-8' ) >= 3 ) {
                // Viết hoa chữ cái đầu
                return mb_strtoupper( mb_substr( $stripped, 0, 1, 'UTF-8' ), 'UTF-8' )
                     . mb_substr( $stripped, 1, null, 'UTF-8' );
            }
        }
    }

    // Không strip được → trả về nguyên văn (cắt tối đa 80 ký tự)
    return mb_strlen( $text, 'UTF-8' ) > 80
        ? mb_substr( $text, 0, 78, 'UTF-8' ) . '…'
        : $text;
}

/**
 * Kiểm tra xem text có chứa yêu cầu bói bài không.
 */
function bct_is_tarot_intent( string $text ): bool {
    $keywords = [
        'bói bài', 'bói tarot', 'bốc bài', 'rút bài', 'gieo quẻ',
        'tarot', 'xem bài', 'trải bài', 'xem tarot', 'bói tử vi tarot',
        'muốn bói', 'cho xem bài',
    ];
    $lower = mb_strtolower( $text, 'UTF-8' );
    foreach ( $keywords as $kw ) {
        if ( mb_strpos( $lower, $kw ) !== false ) {
            return true;
        }
    }
    return false;
}

/**
 * Cố gắng khớp câu nhắn gốc của user với topic + câu hỏi có sẵn.
 * Trả về ['topic' => '...', 'question' => '...'] — rỗng nếu không khớp.
 */
function bct_match_topic_question( string $message ): array {
    if ( empty( $message ) ) return [ 'topic' => '', 'question' => '' ];
    $lower  = mb_strtolower( $message, 'UTF-8' );
    $topics = bct_get_topics();

    // Bảng từ khoá → giá trị topic
    $map = [
        'Tài chính'              => [ 'tiền', 'tài chính', 'thu nhập', 'kiếm tiền', 'tài sản', 'đầu tư', 'tiết kiệm' ],
        'Công việc'              => [ 'công việc', 'xin việc', 'việc làm', 'văn phòng', 'đồng nghiệp', 'sếp', 'thăng chức', 'thăng tiến' ],
        'Khởi nghiệp'            => [ 'khởi nghiệp', 'startup', 'kinh doanh', 'mở cửa hàng', 'ý tưởng kinh doanh' ],
        'Đối tác kinh doanh'     => [ 'đối tác', 'hợp tác', 'partnership', 'hợp đồng' ],
        'Học tập'                => [ 'học', 'thi', 'học tập', 'bằng cấp', 'trường', 'kiểm tra', 'du học' ],
        'Sức khỏe'               => [ 'sức khỏe', 'bệnh', 'khỏe mạnh', 'thể chất', 'tinh thần', 'năng lượng' ],
        'Định hướng bản thân'    => [ 'bản thân', 'cuộc đời', 'vũ trụ', 'ngày hôm nay', 'hôm nay', 'tương lai', 'định hướng', 'kết nối năng lượng', 'thông điệp' ],
        'Gia đình'               => [ 'gia đình', 'bố', 'mẹ', 'cha', 'ba', 'anh', 'chị', 'em', 'con cái' ],
        'Mối quan hệ hiện tại'   => [ 'người yêu', 'bạn trai', 'bạn gái', 'mối quan hệ hiện tại' ],
        'Tình cảm của người yêu cũ' => [ 'người yêu cũ', 'ex ', 'bạn trai cũ', 'bạn gái cũ' ],
        'Crush'                  => [ 'crush', 'người thích', 'đang thích' ],
        'Độc thân'               => [ 'độc thân', 'chưa có người yêu', 'tìm người yêu', 'bao giờ có người yêu' ],
        'Chia tay'               => [ 'chia tay', 'ly hôn', 'chia ly', 'rời xa' ],
    ];

    foreach ( $map as $topic_value => $keywords ) {
        foreach ( $keywords as $kw ) {
            if ( mb_strpos( $lower, mb_strtolower( $kw, 'UTF-8' ) ) !== false ) {
                // Tìm topic trong danh sách chính thức
                foreach ( $topics as $t ) {
                    if ( $t['value'] !== $topic_value ) continue;
                    // Thử khớp câu hỏi
                    $best_q = '';
                    foreach ( $t['questions'] as $q ) {
                        if ( mb_strpos( $lower, mb_strtolower( mb_substr( $q, 0, 15, 'UTF-8' ), 'UTF-8' ) ) !== false ) {
                            $best_q = $q;
                            break;
                        }
                    }
                    return [ 'topic' => $topic_value, 'question' => $best_q ];
                }
            }
        }
    }

    return [ 'topic' => '', 'question' => '' ];
}

/* ---------------------------------------------------------------
 * 2. SECURE TOKEN  (48h transient, hmac signature)
 * ------------------------------------------------------------- */

/**
 * Sinh token HMAC-SHA256 (16 hex chars) từ chat_id + user + date.
 * Token được lưu vào transient 48h và kèm theo payload để validate.
 *
 * @return string  token
 */
function bct_create_chat_token( string $chat_id, int $wp_user_id, string $client_id, string $platform = '', int $blog_id = 0, string $user_message = '' ): string {
    $date    = gmdate( 'Y-m-d' );
    $secret  = wp_salt( 'nonce' );
    $payload = $chat_id . '|' . $wp_user_id . '|' . $client_id . '|' . $date;
    $token   = substr( hash_hmac( 'sha256', $payload, $secret ), 0, 20 );

    // Dùng site_transient để token hoạt động xuyên suốt mọi blog trong multisite
    set_site_transient( 'bct_tok_' . $token, [
        'chat_id'      => $chat_id,
        'wp_user_id'   => $wp_user_id,
        'client_id'    => $client_id,
        'platform'     => $platform,
        'blog_id'      => $blog_id ?: get_current_blog_id(),
        'user_message' => $user_message,   // câu hỏi gốc user gõ trong chat
        'created_at'   => time(),
    ], 48 * HOUR_IN_SECONDS );

    return $token;
}

/**
 * Validate token. Returns payload array hoặc null nếu hết hạn.
 */
function bct_validate_chat_token( string $token ): ?array {
    if ( ! preg_match( '/^[0-9a-f]{20}$/', $token ) ) return null;
    // site_transient: hoạt động xuyên suốt mọi blog trong multisite
    $data = get_site_transient( 'bct_tok_' . $token );
    return is_array( $data ) ? $data : null;
}

/* ---------------------------------------------------------------
 * 3. TAROT PAGE URL + AUTO-CREATE
 * ------------------------------------------------------------- */

/**
 * Tìm page tarot theo thứ tự uu tiên:
 *   1. Option override thủ công (bct_tarot_page_url)
 *   2. Page có shortcode [bizcity_tarot
 *   3. Page dùng template bct-tarot-landing
 *   4. Fallback: /tarot/
 */
function bct_get_tarot_page_url(): string {
    // 1. Override thủ công
    $custom = get_option( 'bct_tarot_page_url', '' );
    if ( $custom ) return trim( $custom );

    global $wpdb;

    // 2. Tìm trang có shortcode
    $pid = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_status = 'publish'
            AND post_type   = 'page'
            AND post_content LIKE '%bizcity_tarot%'
          ORDER BY ID ASC
          LIMIT 1"
    );
    if ( $pid ) return (string) get_permalink( $pid );

    // 3. Tìm trang dùng page template bct-tarot-landing
    $pid = $wpdb->get_var(
        "SELECT p.ID FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
          WHERE p.post_status = 'publish'
            AND p.post_type   = 'page'
            AND pm.meta_key   = '_wp_page_template'
            AND pm.meta_value = 'bct-tarot-landing'
          ORDER BY p.ID ASC
          LIMIT 1"
    );
    if ( $pid ) return (string) get_permalink( $pid );

    return home_url( '/tarot/' );
}

/**
 * Kiểm tra có trang tarot chưa (shortcode hoặc template).
 * @return int|false  Post ID nếu có, false nếu chưa.
 */
function bct_find_tarot_page_id() {
    global $wpdb;

    $pid = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_status = 'publish'
            AND post_type   = 'page'
            AND post_content LIKE '%bizcity_tarot%'
          ORDER BY ID ASC LIMIT 1"
    );
    if ( $pid ) return (int) $pid;

    $pid = $wpdb->get_var(
        "SELECT p.ID FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
          WHERE p.post_status = 'publish'
            AND p.post_type   = 'page'
            AND pm.meta_key   = '_wp_page_template'
            AND pm.meta_value = 'bct-tarot-landing'
          ORDER BY p.ID ASC LIMIT 1"
    );
    return $pid ? (int) $pid : false;
}

/**
 * Tự động tạo trang tarot nếu chưa có.
 * - Chạy 1 lần khi kích hoạt plugin và mỗi lần admin_init nếu option chưa set.
 * - Lưu permalink vào option bct_tarot_page_url.
 * - Hiển thị admin notice thông báo.
 *
 * @return int|false  ID trang mới tạo, hoặc false nếu đã tồn tại / lỗi.
 */
function bct_maybe_create_tarot_page() {
    // Đã có trang → không làm gì
    $existing = bct_find_tarot_page_id();
    if ( $existing ) {
        // Đảm bảo option được set nếu chưa
        if ( ! get_option( 'bct_tarot_page_url' ) ) {
            update_option( 'bct_tarot_page_url', get_permalink( $existing ) );
        }
        update_option( 'bct_tarot_page_checked', '1' );
        return false;
    }

    // Tạo trang mới
    $page_id = wp_insert_post( [
        'post_title'   => 'Bói Bài Tarot',
        'post_name'    => 'tarot',
        'post_content' => '[bizcity_tarot_landing]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => get_current_user_id() ?: 1,
        'meta_input'   => [
            '_wp_page_template' => 'default',
        ],
    ] );

    if ( is_wp_error( $page_id ) || ! $page_id ) {
        return false;
    }

    $url = get_permalink( $page_id );
    update_option( 'bct_tarot_page_url', $url );
    update_option( 'bct_tarot_page_checked', '1' );

    // Lưu thông báo để hiển thị lần sau admin load
    set_transient( 'bct_page_created_notice', [
        'id'  => $page_id,
        'url' => $url,
    ], 60 );

    return $page_id;
}

// Chạy 1 lần trên admin_init nếu chưa check
add_action( 'admin_init', function () {
    if ( get_option( 'bct_tarot_page_checked' ) ) return;
    bct_maybe_create_tarot_page();
} );

// Hiển thị admin notice sau khi tạo trang
add_action( 'admin_notices', function () {
    $notice = get_transient( 'bct_page_created_notice' );
    if ( ! $notice ) return;
    delete_transient( 'bct_page_created_notice' );
    ?>
    <div class="notice notice-success is-dismissible">
        <p>
            🃏 <strong>BizCity Tarot:</strong>
            Đã tự động tạo trang bói bài
            <strong><a href="<?php echo esc_url( $notice['url'] ); ?>" target="_blank">
                <?php echo esc_html( $notice['url'] ); ?>
            </a></strong>
            &mdash; <a href="<?php echo esc_url( get_edit_post_link( $notice['id'] ) ); ?>">Chỉnh sửa trang</a>
        </p>
    </div>
    <?php
} );

/**
 * Sinh đầy đủ URL bốc bài có gắn token bảo mật.
 */
function bct_build_tarot_link( string $chat_id, int $wp_user_id, string $client_id, string $platform = '', int $blog_id = 0, string $user_message = '' ): string {
    $token = bct_create_chat_token( $chat_id, $wp_user_id, $client_id, $platform, $blog_id, $user_message );
    return add_query_arg( 'bct_token', $token, bct_get_tarot_page_url() );
}

/* ---------------------------------------------------------------
 * 4. HOOK: intercept intent → send tarot link
 * ------------------------------------------------------------- */

/**
 * Hook vào bizcity_unified_message_intent (Zalo / ADMINCHAT unified flow).
 */
add_filter( 'bizcity_unified_message_intent', 'bct_intent_filter', 10, 2 );

function bct_intent_filter( $handled, array $ctx ): bool {
    if ( $handled ) return true;

    $text = (string) ( $ctx['message'] ?? '' );
    if ( ! bct_is_tarot_intent( $text ) ) return false;

    // QUAN TRỌNG: Nếu user đang gửi ảnh attachment (chưa có prompt mô tả)
    // → return false để step 7 trong unified pipeline xử lý (lưu transient + hỏi user muốn làm gì)
    // → tránh gửi link tarot ngay lập tức khi chưa có context đầy đủ
    $attachment_type = (string) ( $ctx['attachment_type'] ?? '' );
    if ( $attachment_type === 'image' ) {
        error_log( '[TAROT INTENT] Skipping - image attachment detected, let step 7 handle it first' );
        return false; // Để unified pipeline xử lý attachment trước
    }

    $chat_id    = (string) ( $ctx['chat_id']    ?? '' );
    $client_id  = (string) ( $ctx['client_id']  ?? '' );
    $wp_user_id = (int)    ( $ctx['wp_user_id'] ?? 0 );
    $platform   = (string) ( $ctx['platform']   ?? '' );
    $blog_id    = (int)    ( $ctx['blog_id']    ?? 0 );

    if ( ! $chat_id ) return false;

    $link  = bct_build_tarot_link( $chat_id, $wp_user_id, $client_id, $platform, $blog_id, $text );
    $phrase = bct_extract_topic_phrase( $text );

    $msg  = "🔮 *Bói Bài Tarot Online*\n";
    $msg .= "Bạn muốn tìm hiểu \"" . $phrase . "\", hãy:\n";
    $msg .= "Tập trung tâm trí, nghĩ đến câu hỏi bạn muốn hỏi, rồi nhấn vào link bên dưới để bốc bài:\n\n";
    $msg .= $link . "\n\n";
    $msg .= "✨ Sau khi bốc xong, tôi sẽ luận giải kết quả và gửi lại qua đây cho bạn.";

    if ( function_exists( 'biz_send_message' ) ) {
        biz_send_message( $chat_id, $msg );
    }

    return true;
}

/**
 * Hook vào bizcity_chat_pre_ai_response (WEBCHAT Chat Gateway).
 * Trả link tarot thay vì để AI trả lời khi user hỏi về bói bài.
 */
add_filter( 'bizcity_chat_pre_ai_response', 'bct_webchat_intent_filter', 10, 2 );

function bct_webchat_intent_filter( $pre_reply, array $ctx ) {
    if ( $pre_reply !== null ) return $pre_reply; // đã có plugin khác xử lý

    $text = (string) ( $ctx['message'] ?? '' );
    if ( ! bct_is_tarot_intent( $text ) ) return null;

    $user_id   = (int) ( $ctx['user_id'] ?? 0 );
    $session   = (string) ( $ctx['session_id'] ?? '' );
    $chat_id   = 'adminchat_' . ( $session ?: ( $user_id ? 'u' . $user_id : uniqid( 'wc_' ) ) );
    $client_id = (string) $user_id;

    $link   = bct_build_tarot_link( $chat_id, $user_id, $client_id, 'WEBCHAT', get_current_blog_id(), $text );
    $phrase = bct_extract_topic_phrase( $text );

    $msg  = "🔮 **Bói Bài Tarot Online**\n";
    $msg .= "Bạn muốn tìm hiểu \"" . $phrase . "\", hãy:\n";
    $msg .= "Nhấn vào link bên dưới để bắt đầu bốc bài — hãy tập trung tâm trí vào câu hỏi của bạn:\n\n";
    $msg .= $link . "\n\n";
    $msg .= "✨ Sau khi hoàn thành, kết quả luận giải sẽ được gửi về đây cho bạn.";

    return [ 'message' => $msg ];
}

/* ---------------------------------------------------------------
 * 5. SHORTCODE helper: validate token khi trang load
 *    (được gọi từ shortcode.php)
 * ------------------------------------------------------------- */

/**
 * Đọc token từ URL → trả về payload hoặc null.
 * Shortcode dùng để inject chat_id/client_id vào BCT_PUB.
 */
function bct_get_current_chat_context(): ?array {
    $token = sanitize_text_field( wp_unslash( $_GET['bct_token'] ?? '' ) );
    if ( ! $token ) return null;
    return bct_validate_chat_token( $token );
}

/* ---------------------------------------------------------------
 * 6. AJAX: bct_push_reading
 *    Sau khi bốc bài xong + AI luận giải, JS gọi endpoint này
 *    để gửi kết quả qua tin nhắn.
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_push_reading',        'bct_ajax_push_reading' );
add_action( 'wp_ajax_nopriv_bct_push_reading', 'bct_ajax_push_reading' );

function bct_ajax_push_reading(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    $token     = sanitize_text_field( wp_unslash( $_POST['bct_token']   ?? '' ) );
    $ai_reply  = wp_strip_all_tags( wp_unslash( $_POST['ai_reply']      ?? '' ) );
    $topic     = sanitize_text_field( wp_unslash( $_POST['topic']       ?? '' ) );
    $question  = sanitize_text_field( wp_unslash( $_POST['question']    ?? '' ) );
    $cards_txt = sanitize_textarea_field( wp_unslash( $_POST['cards_text'] ?? '' ) );

    if ( ! $token ) {
        wp_send_json_error( 'no_token' );
    }

    $payload = bct_validate_chat_token( $token );
    if ( ! $payload ) {
        wp_send_json_error( 'token_expired' );
    }

    if ( ! $ai_reply ) {
        wp_send_json_error( 'no_reply' );
    }

    $chat_id   = (string) ( $payload['chat_id']   ?? '' );
    $client_id = (string) ( $payload['client_id'] ?? '' );
    $platform  = (string) ( $payload['platform']  ?? '' );
    $blog_id   = (int)    ( $payload['blog_id']   ?? 0 );

    // ── Build message ──────────────────────────────────────────
    $lines = [ '🔮 *Kết quả bói bài Tarot*' ];

    if ( $topic )     $lines[] = "📌 Chủ đề: {$topic}";
    if ( $question )  $lines[] = "❓ Câu hỏi: {$question}";

    if ( $cards_txt ) {
        $lines[] = '';
        $lines[] = '🃏 Các lá bài:';
        $lines[] = $cards_txt;
    }

    $lines[] = '';
    $lines[] = '━━━━━━━━━━━━━━━━━━';
    $lines[] = '🤖 Luận giải:';
    $lines[] = '';
    $lines[] = $ai_reply;

    $full_msg = implode( "\n", $lines );

    // ── Switch to correct blog (multisite) + split & send ─────
    $switched = false;
    if ( is_multisite() && $blog_id && $blog_id !== get_current_blog_id() ) {
        switch_to_blog( $blog_id );
        $switched = true;
    }

    bct_send_long_message_platform( $chat_id, $client_id, $platform, $full_msg );

    if ( $switched ) restore_current_blog();

    wp_send_json_success( [ 'sent' => true, 'chat_id' => $chat_id, 'platform' => $platform ] );
}

/**
 * Gửi một tin nhắn đến đúng platform, tự chia nhỏ nếu > $max_len ký tự.
 *
 * @param string $chat_id    Canonical chat_id (zalo_xxx / zalobot_bot_xxx / adminchat_xxx)
 * @param string $client_id  Bare Zalo user ID (dùng cho ZALO_PERSONAL)
 * @param string $platform   'ZALO_PERSONAL' | 'ZALO_BOT' | 'ADMINCHAT' | 'WEBCHAT' | ''
 * @param string $text       Nội dung cần gửi
 * @param int    $max_len    Giới hạn ký tự mỗi chunk
 */
function bct_send_long_message_platform(
    string $chat_id,
    string $client_id,
    string $platform,
    string $text,
    int    $max_len = 2000
): void {
    $chunks = bct_split_message( $text, $max_len );
    $total  = count( $chunks );

    foreach ( $chunks as $i => $chunk ) {
        $chunk = trim( $chunk );
        if ( ! $chunk ) continue;

        $prefix = ( $total > 1 ) ? '(' . ( $i + 1 ) . '/' . $total . ') ' : '';
        $msg    = $prefix . $chunk;

        bct_dispatch_message( $platform, $chat_id, $client_id, $msg );

        if ( $i < $total - 1 ) {
            usleep( 150000 ); // 150 ms giữa các tin
        }
    }
}

/**
 * Gửi một tin nhắn đến đúng platform.
 * - ZALO_PERSONAL : send_zalo_botbanhang($msg, $client_id)
 * - ADMINCHAT / WEBCHAT : INSERT trực tiếp vào DB (bypass gateway auto-detect
 *   vì wcs_ prefix không được nhận diện bởi bizcity_gateway_detect_platform)
 * - ZALO_BOT / mặc định : biz_send_message($chat_id)
 */
function bct_dispatch_message( string $platform, string $chat_id, string $client_id, string $text ): void {
    if ( $platform === 'ZALO_PERSONAL' ) {
        // Gửi trực tiếp qua Zalo Personal API với bare client_id
        if ( function_exists( 'send_zalo_botbanhang' ) && $client_id ) {
            $fmt = function_exists( 'bizgpt_zalo_format' ) ? bizgpt_zalo_format( $text ) : $text;
            send_zalo_botbanhang( $fmt, $client_id, 'text' );
        } elseif ( function_exists( 'biz_send_message' ) ) {
            biz_send_message( $chat_id, $text );
        }
        return;
    }

    // ADMINCHAT / WEBCHAT: INSERT trực tiếp vào bizcity_webchat_messages
    // Bypass biz_send_message → gateway auto-detect vì wcs_ prefix không match adminchat_
    if ( ( $platform === 'ADMINCHAT' || $platform === 'WEBCHAT' ) && class_exists( 'BizCity_WebChat_Database' ) ) {
        BizCity_WebChat_Database::instance()->log_message( array(
            'session_id'    => $chat_id,
            'user_id'       => 0,
            'client_name'   => 'AI Bot',
            'message_id'    => uniqid( 'gw_tarot_' ),
            'message_text'  => $text,
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform,
        ) );
        return;
    }

    // ZALO_BOT, other platforms: route via biz_send_message
    if ( function_exists( 'biz_send_message' ) ) {
        $fmt = ( strpos( $platform, 'ZALO' ) !== false && function_exists( 'bizgpt_zalo_format' ) )
            ? bizgpt_zalo_format( $text )
            : $text;
        biz_send_message( $chat_id, $fmt );
    }
}

/* ---------------------------------------------------------------
 * 7. MESSAGE SPLITTER  (< 2 000 chars per chunk)
 * ------------------------------------------------------------- */

/**
 * Gửi $text qua $chat_id; tự chia nhỏ nếu > $max_len ký tự.
 * Ưu tiên cắt tại đoạn văn (double newline) → dòng đơn → câu → hard cut.
 */
function bct_send_long_message( string $chat_id, string $text, int $max_len = 2000 ): void {
    if ( ! function_exists( 'biz_send_message' ) ) return;

    $chunks = bct_split_message( $text, $max_len );
    $total  = count( $chunks );

    foreach ( $chunks as $i => $chunk ) {
        $chunk = trim( $chunk );
        if ( ! $chunk ) continue;

        // Thêm chỉ số phần nếu chia thành nhiều tin (phần 1/3, 2/3, …)
        $prefix = ( $total > 1 ) ? '(' . ( $i + 1 ) . '/' . $total . ') ' : '';
        biz_send_message( $chat_id, $prefix . $chunk );

        if ( $i < $total - 1 ) {
            usleep( 150000 ); // 150 ms giữa các tin để tránh rate-limit
        }
    }
}

/**
 * Chia văn bản thành các mảng chunk <= $max_len ký tự.
 * Thứ tự ưu tiên cắt: đoạn văn → dòng → câu → cắt cứng.
 */
function bct_split_message( string $text, int $max_len = 2000 ): array {
    if ( mb_strlen( $text, 'UTF-8' ) <= $max_len ) {
        return [ $text ];
    }

    $chunks  = [];
    $current = '';

    // Tách theo đoạn văn (≥2 newline)
    $paragraphs = preg_split( '/\n{2,}/', $text );

    foreach ( $paragraphs as $para ) {
        $candidate = $current ? $current . "\n\n" . $para : $para;

        if ( mb_strlen( $candidate, 'UTF-8' ) <= $max_len ) {
            $current = $candidate;
            continue;
        }

        // Flush current, start new
        if ( $current !== '' ) {
            $chunks[] = $current;
            $current  = '';
        }

        // Para vừa, đưa thẳng vào current
        if ( mb_strlen( $para, 'UTF-8' ) <= $max_len ) {
            $current = $para;
            continue;
        }

        // Para quá dài → cắt theo dòng đơn
        $lines = explode( "\n", $para );
        foreach ( $lines as $line ) {
            $candidate = $current ? $current . "\n" . $line : $line;
            if ( mb_strlen( $candidate, 'UTF-8' ) <= $max_len ) {
                $current = $candidate;
            } else {
                if ( $current !== '' ) { $chunks[] = $current; $current = ''; }
                // Line vừa
                if ( mb_strlen( $line, 'UTF-8' ) <= $max_len ) {
                    $current = $line;
                } else {
                    // Cắt cứng theo $max_len
                    while ( mb_strlen( $line, 'UTF-8' ) > $max_len ) {
                        $chunks[] = mb_substr( $line, 0, $max_len, 'UTF-8' );
                        $line     = mb_substr( $line, $max_len, null, 'UTF-8' );
                    }
                    $current = $line;
                }
            }
        }
    }

    if ( $current !== '' ) {
        $chunks[] = $current;
    }

    return $chunks;
}

/* ---------------------------------------------------------------
 * 8. ADMIN SETTINGS: thêm field "URL trang Tarot" vào Settings tab
 * ------------------------------------------------------------- */
add_action( 'bct_settings_fields', 'bct_integration_settings_fields' );

function bct_integration_settings_fields(): void {
    // Xử lý nút "Tạo lại trang" (submit riêng từ form settings)
    if ( isset( $_POST['bct_recreate_page'] ) && check_admin_referer( 'bct_settings' ) ) {
        delete_option( 'bct_tarot_page_checked' );
        delete_option( 'bct_tarot_page_url' );
        $new_id = bct_maybe_create_tarot_page();
        if ( $new_id ) {
            echo '<tr><td colspan="2"><div class="notice notice-success inline"><p>✅ Đã tạo trang tarot mới: <a href="' . esc_url( get_permalink( $new_id ) ) . '" target="_blank">' . esc_html( get_permalink( $new_id ) ) . '</a></p></div></td></tr>';
        } else {
            $pid = bct_find_tarot_page_id();
            $msg = $pid
                ? '⚠️ Đã có trang tarot (ID #' . $pid . '), không tạo thêm.'
                : '❌ Không thể tạo trang. Vui lòng tạo thủ công.';
            echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>' . $msg . '</p></div></td></tr>';
        }
    }

    $val     = esc_attr( get_option( 'bct_tarot_page_url', '' ) );
    $detect  = bct_get_tarot_page_url();
    $page_id = bct_find_tarot_page_id();
    ?>
    <tr>
        <th scope="row">
            <label for="bct_tarot_page_url">URL Trang Tarot</label>
        </th>
        <td>
            <input type="url" id="bct_tarot_page_url" name="bct_tarot_page_url"
                   value="<?php echo $val; ?>" class="regular-text"
                   placeholder="<?php echo esc_attr( $detect ); ?>">

            <p class="description">
                URL trang chứa shortcode <code>[bizcity_tarot]</code> — dùng để gửi link
                bốc bài qua Zalo / ADMINCHAT. Để trống để tự phát hiện.
            </p>

            <?php if ( $page_id ) : ?>
                <p class="description" style="margin-top:6px">
                    ✅ Trang hiện tại:
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank">
                        <?php echo esc_html( get_the_title( $page_id ) ); ?>
                    </a>
                    (ID #<?php echo (int) $page_id; ?>)
                    &mdash;
                    <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">Chỉnh sửa</a>
                </p>
            <?php else : ?>
                <p class="description" style="margin-top:6px;color:#d63638">
                    ⚠️ Chưa tìm thấy trang tarot.
                    URL được dùng khi gửi link: <strong><?php echo esc_html( $detect ); ?></strong>
                </p>
            <?php endif; ?>

            <p style="margin-top:10px">
                <button type="submit" name="bct_recreate_page" value="1"
                        class="button button-secondary"
                        onclick="return confirm('Tạo mới trang Tarot? Nếu đã có trang thì sẽ giữ nguyên và không tạo thêm.')">
                    🪄 <?php echo $page_id ? 'Kiểm tra / Tạo lại trang' : 'Tự động tạo trang Tarot'; ?>
                </button>
            </p>
        </td>
    </tr>
    <?php
}

// Note: bct_tarot_page_url is saved via the custom settings form in admin-cards.php (bct_page_settings)
