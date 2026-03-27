<?php
/**
 * Chat integration — intent detection, secure tokens, push results.
 *
 * @package BizCity_Calo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   INTENT DETECTION — Keywords
   ═══════════════════════════════════════════════ */

function bzcalo_is_intent( $message ) {
    return (bool) preg_match(
        '/calo|calorie|b[ữứ]a\s*[aă]n|ghi\s*b[ữứ]a|nh[ậa]t\s*k[ýy]\s*[aă]n|dinh\s*d[ưu][ỡo]ng|meal|th[ựu]c\s*[đd][ơo]n|gi[ảa]m\s*c[âa]n|t[aă]ng\s*c[âa]n'
        . '|ch[ụu]p\s*[ảa]nh\s*b[ữứ]a|kcal|protein|carb|b[éeế]o|[aă]n\s*g[ìi]'
        . '|c[âa]n\s*n[ặa]ng|chi[ềe]u\s*cao|BMI|BMR|TDEE|s[ứu]c\s*kh[ỏo][eẻ]'
        . '|[aă]n\s*s[áa]ng|[aă]n\s*tr[ưu]a|[aă]n\s*t[ốo]i|v[ừư]a\s*[aă]n|t[ôo]i\s*[aă]n|[đd][ãa]\s*[aă]n'
        . '|g[ầa]y|th[ừư]a\s*c[âa]n|thi[ếe]u\s*c[âa]n'
        . '|[aă]n\s*nh[ưu]|[aă]n\s*c[ơo]m|[aă]n\s*n[àa]y|anh\s*[aă]n|em\s*[aă]n|m[ìi]nh\s*[aă]n'
        . '|ng[ưu][ờo]i.*[aă]n|[aă]n.*m[óo]n|m[óo]n\s*[aă]n'
        . '|kh[ẩa]u\s*ph[ầa]n|ch[ếe]\s*[đd][ộo]\s*[aă]n|th[ốo]ng\s*k[êe]\s*calo|n[êe]n\s*[aă]n/ui',
        $message
    );
}

/**
 * Check if context has image attachments
 * Checks multiple possible keys across webchat, zalo, gateway, admin-chat
 */
function bzcalo_has_image( $ctx ) {
    // Webchat / AdminChat: $ctx['images']
    if ( ! empty( $ctx['images'] ) && is_array( $ctx['images'] ) ) return true;
    // Zalo / Gateway: $ctx['attachment_type']
    if ( ! empty( $ctx['attachment_type'] ) && $ctx['attachment_type'] === 'image' ) return true;
    if ( ! empty( $ctx['image_url'] ) ) return true;
    // Additional fallback keys
    if ( ! empty( $ctx['image'] ) ) return true;
    if ( ! empty( $ctx['photo_url'] ) ) return true;
    if ( ! empty( $ctx['attachment_url'] ) && stripos( $ctx['attachment_url'], '.jpg' ) !== false ) return true;
    if ( ! empty( $ctx['attachment_url'] ) && stripos( $ctx['attachment_url'], '.png' ) !== false ) return true;
    if ( ! empty( $ctx['attachment_url'] ) && stripos( $ctx['attachment_url'], '.webp' ) !== false ) return true;
    if ( ! empty( $ctx['attachments'] ) && is_array( $ctx['attachments'] ) ) {
        foreach ( $ctx['attachments'] as $att ) {
            if ( isset( $att['type'] ) && $att['type'] === 'image' ) return true;
            if ( isset( $att['mime_type'] ) && strpos( $att['mime_type'], 'image/' ) === 0 ) return true;
        }
    }
    if ( ! empty( $ctx['files'] ) && is_array( $ctx['files'] ) ) {
        foreach ( $ctx['files'] as $f ) {
            if ( isset( $f['type'] ) && strpos( $f['type'], 'image/' ) === 0 ) return true;
        }
    }
    // Check message for inline base64 images
    $msg = $ctx['message'] ?? '';
    if ( preg_match( '/data:image\/[a-z]+;base64,/i', $msg ) ) return true;
    return false;
}

/**
 * Extract first image URL/data from context (checks all possible keys)
 */
function bzcalo_extract_image( $ctx ) {
    if ( ! empty( $ctx['images'] ) && is_array( $ctx['images'] ) ) {
        $first = $ctx['images'][0];
        return is_string( $first ) ? $first : ( $first['url'] ?? $first['data'] ?? '' );
    }
    if ( ! empty( $ctx['image_url'] ) ) return $ctx['image_url'];
    if ( ! empty( $ctx['image'] ) ) return $ctx['image'];
    if ( ! empty( $ctx['photo_url'] ) ) return $ctx['photo_url'];
    if ( ! empty( $ctx['attachment_url'] ) && ( $ctx['attachment_type'] ?? '' ) === 'image' ) return $ctx['attachment_url'];
    // Check attachments array
    if ( ! empty( $ctx['attachments'] ) && is_array( $ctx['attachments'] ) ) {
        foreach ( $ctx['attachments'] as $att ) {
            if ( ( isset( $att['type'] ) && $att['type'] === 'image' ) || ( isset( $att['mime_type'] ) && strpos( $att['mime_type'], 'image/' ) === 0 ) ) {
                return $att['url'] ?? $att['src'] ?? $att['payload'] ?? '';
            }
        }
    }
    // Check files array
    if ( ! empty( $ctx['files'] ) && is_array( $ctx['files'] ) ) {
        foreach ( $ctx['files'] as $f ) {
            if ( isset( $f['type'] ) && strpos( $f['type'], 'image/' ) === 0 ) {
                return $f['url'] ?? $f['data'] ?? '';
            }
        }
    }
    return '';
}

/* ═══════════════════════════════════════════════
   SECURE TOKEN SYSTEM
   ═══════════════════════════════════════════════ */

function bzcalo_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $payload = compact( 'chat_id', 'user_id', 'client_id', 'platform', 'blog_id', 'message' );
    $payload['created_at'] = time();
    $token = substr( hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) ), 0, 20 );
    set_site_transient( 'bzcalo_token_' . $token, $payload, 48 * HOUR_IN_SECONDS );
    return $token;
}

function bzcalo_validate_token( $token ) {
    $token = preg_replace( '/[^a-f0-9]/', '', $token );
    if ( empty( $token ) ) return null;
    return get_site_transient( 'bzcalo_token_' . $token ) ?: null;
}

function bzcalo_build_link( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $token = bzcalo_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message );
    $url   = bzcalo_get_page_url();
    return add_query_arg( 'bzcalo_token', $token, $url );
}

function bzcalo_get_page_url() {
    static $url = null;
    if ( $url !== null ) return $url;

    global $wpdb;
    $page_id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%[bizcity_calo]%' AND post_status = 'publish' LIMIT 1" );
    $url = $page_id ? get_permalink( $page_id ) : site_url( '/calo/' );
    return $url;
}

/* ═══════════════════════════════════════════════
   INTENT FILTER — Zalo / Bot / FB platforms
   Processes calo intents NATIVELY (no link redirects).
   Uses enriched context: image_url, recent_context.
   ═══════════════════════════════════════════════ */

add_filter( 'bizcity_unified_message_intent', 'bzcalo_intent_filter', 10, 2 );
function bzcalo_intent_filter( $handled, $ctx ) {
    if ( $handled ) return $handled;

    $message   = $ctx['message'] ?? '';
    $has_image = bzcalo_has_image( $ctx );
    $is_intent = bzcalo_is_intent( $message );

    $user_id   = (int) ( $ctx['wp_user_id'] ?? 0 );
    $chat_id   = $ctx['chat_id'] ?? '';
    $platform  = $ctx['platform'] ?? '';
    $recent    = $ctx['recent_context'] ?? '';
    $client_id = $ctx['client_id'] ?? '';

    // Build pending key unconditionally (used later for follow-up tracking)
    $pending_key = $chat_id ? 'bzcalo_pending_' . md5( $chat_id ) : '';

    // ── Check pending follow-up FIRST (before keyword check) ──
    // User was asked "ăn gì?" and replied with meal description (e.g. "cơm gà")
    if ( $chat_id && $user_id ) {
        $pending     = $pending_key ? get_site_transient( $pending_key ) : false;
        if ( $pending === 'awaiting_meal_description' && ! empty( $message ) ) {
            delete_site_transient( $pending_key );
            $reply = bzcalo_zalo_log_meal( $user_id, $message, $platform, $recent );
            bzcalo_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;
        }
    }

    if ( ! $is_intent && ! $has_image ) return $handled;

    // ── Case 1: Image — direct photo analysis ──
    if ( $has_image ) {
        $image_url = bzcalo_extract_image( $ctx );
        if ( $image_url && $user_id && $chat_id ) {
            // Include recent context + message as text hint for Vision AI
            $text_hint = $message;
            if ( $recent ) {
                $text_hint .= "\n\n[Ngữ cảnh hội thoại gần đây]:\n" . mb_substr( $recent, 0, 500 );
            }
            $result = bzcalo_chat_analyze_photo( $image_url, $user_id, $text_hint );
            if ( $result && ! empty( $result['message'] ) ) {
                bzcalo_send_long_message( $chat_id, $client_id, $platform, $result['message'] );
                return true;
            }
        }
        // Fallback: link (rare — only if Vision fails and no user)
        $link = bzcalo_build_link( $chat_id, $user_id, $client_id, $platform, $ctx['blog_id'] ?? 0, $message );
        if ( function_exists( 'biz_send_message' ) ) {
            biz_send_message( $chat_id, "📸 Nhận được ảnh!\n🔗 Mở Calo AI để phân tích chi tiết:\n{$link}" );
        }
        return true;
    }

    // ── Case 2: Text intent — process natively ──
    if ( ! $user_id ) {
        // No user → fallback link (can't log without user)
        $link = bzcalo_build_link( $chat_id, 0, $client_id, $platform, $ctx['blog_id'] ?? 0, $message );
        if ( function_exists( 'biz_send_message' ) ) {
            biz_send_message( $chat_id, "🍽️ Đăng nhập để sử dụng Nhật ký Calo:\n{$link}" );
        }
        return true;
    }

    // Classify sub-intent from text
    $sub = bzcalo_classify_sub_intent( $message );

    switch ( $sub ) {
        case 'stats':
            $reply = bzcalo_zalo_daily_stats( $user_id );
            bzcalo_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'report':
            $reply = bzcalo_zalo_report( $user_id, $message );
            bzcalo_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'suggest':
            $reply = bzcalo_zalo_suggest( $user_id, $message, $recent );
            bzcalo_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'log_with_desc':
            // Message contains a meal description → log directly
            $reply = bzcalo_zalo_log_meal( $user_id, $message, $platform, $recent );
            bzcalo_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'log_no_desc':
        default:
            // Vague intent — ask what they ate using LLM for natural prompt
            $ask = bzcalo_compose_natural_ask( $user_id, $message, $recent );
            if ( $pending_key ) {
                set_site_transient( $pending_key, 'awaiting_meal_description', 10 * MINUTE_IN_SECONDS );
            }
            if ( function_exists( 'biz_send_message' ) ) {
                biz_send_message( $chat_id, $ask );
            }
            return true;
    }
}

/* ── Sub-intent classifier ── */
function bzcalo_classify_sub_intent( $message ) {
    $msg = mb_strtolower( $message );

    // Stats: "bao nhiêu calo", "thống kê", "tổng calo", "BMI", etc.
    if ( preg_match( '/th[ốo]ng\s*k[êe]|bao\s*nhi[êe]u\s*calo|t[ổo]ng\s*calo|calo\s*h[ôo]m\s*nay|BMI|BMR|TDEE|c[âa]n\s*n[ặa]ng|chi[ềe]u\s*cao/ui', $msg ) ) {
        return 'stats';
    }

    // Report: "báo cáo", "biểu đồ", "tuần này", "review"
    if ( preg_match( '/bi[ểe]u\s*[đd][ồo]|b[áa]o\s*c[áa]o|ti[ếe]n\s*tr[ìi]nh|l[ịi]ch\s*s[ửư]\s*[aă]n|review/ui', $msg ) ) {
        return 'report';
    }

    // Suggest: "nên ăn gì", "gợi ý", "tư vấn", "ăn gì hôm nay"
    if ( preg_match( '/n[êe]n\s*[aă]n|g[ợo]i\s*[ýy]|t[ưu]\s*v[ấa]n\s*dinh|[aă]n\s*g[ìi]\s*(h[ôo]m\s*nay|t[ốo]i|tr[ưu]a|s[áa]ng)|th[ựu]c\s*[đd][ơo]n|gi[ảa]m\s*c[âa]n|t[aă]ng\s*c[âa]n|[aă]n\s*ki[êe]ng/ui', $msg ) ) {
        return 'suggest';
    }

    // Log with description: contains food-like words after the intent trigger
    // e.g. "mình vừa ăn cơm gà", "ăn trưa 1 tô phở bò"
    if ( preg_match( '/(?:v[ừư]a\s*[aă]n|[đd][ãa]\s*[aă]n|t[ôo]i\s*[aă]n|m[ìi]nh\s*[aă]n|em\s*[aă]n|anh\s*[aă]n|[aă]n\s*(?:s[áa]ng|tr[ưu]a|t[ốo]i|v[ặa]t))\s+.{3,}/ui', $msg ) ) {
        return 'log_with_desc';
    }
    // Also match: "ghi bữa ăn: cơm gà", "bữa trưa: phở bò"
    if ( preg_match( '/(?:ghi\s*b[ữứ]a|b[ữứ]a\s*(?:s[áa]ng|tr[ưu]a|t[ốo]i|[aă]n))\s*[:：\-]\s*.{3,}/ui', $msg ) ) {
        return 'log_with_desc';
    }
    // Specific food mentions (likely describing a meal)
    if ( preg_match( '/(?:ph[ởo]|c[ơo]m|b[úu]n|m[ìi]|ch[áa]o|b[áa]nh\s*m[ìi]|x[ôo]i|h[ủu]\s*ti[ếe]u|b[áa]nh\s*cu[ốo]n|g[ỏo]i\s*cu[ốo]n|tr[ứu]ng|s[ữư]a|c[àa]\s*ph[êe]|n[ưu][ớo]c|tr[àa]|bia|r[ưu][ợo]u)/ui', $msg )
        && bzcalo_is_intent( $message ) ) {
        return 'log_with_desc';
    }

    // Bare intent: "ghi bữa ăn", "nhật ký ăn"
    return 'log_no_desc';
}

/* ═══════════════════════════════════════════════
   ZALO/BOT NATIVE PROCESSING HELPERS
   Process calo operations with explicit user_id
   (Intent Engine tools use get_current_user_id()
    which doesn't work on Zalo/Bot paths)
   ═══════════════════════════════════════════════ */

/**
 * Log a meal from text description on Zalo/Bot
 */
function bzcalo_zalo_log_meal( $user_id, $meal_description, $platform = '', $recent_ctx = '' ) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) || ! function_exists( 'bzcalo_tables' ) ) {
        return '⚠️ Hệ thống Calo chưa sẵn sàng.';
    }

    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    // Auto-detect meal type
    $hour = (int) current_time( 'G' );
    $desc_lower = mb_strtolower( $meal_description );
    if ( preg_match( '/b[ữứ]a\s*s[áa]ng|[aă]n\s*s[áa]ng|sáng\s*nay/ui', $desc_lower ) ) $meal_type = 'breakfast';
    elseif ( preg_match( '/b[ữứ]a\s*tr[ưu]a|[aă]n\s*tr[ưu]a|trưa\s*nay/ui', $desc_lower ) ) $meal_type = 'lunch';
    elseif ( preg_match( '/b[ữứ]a\s*t[ốo]i|[aă]n\s*t[ốo]i|t[ốo]i\s*nay/ui', $desc_lower ) ) $meal_type = 'dinner';
    elseif ( preg_match( '/[aă]n\s*v[ặa]t|snack/ui', $desc_lower ) ) $meal_type = 'snack';
    elseif ( $hour < 10 ) $meal_type = 'breakfast';
    elseif ( $hour < 14 ) $meal_type = 'lunch';
    elseif ( $hour < 17 ) $meal_type = 'snack';
    else $meal_type = 'dinner';

    // AI estimate calo from text
    $system = "Bạn là chuyên gia dinh dưỡng. Phân tích bữa ăn và ước tính calo.\n"
            . "Nếu người dùng nói cho bao nhiêu người ăn, hãy NHÂN khẩu phần theo số người.\n"
            . "CHỈ trả về JSON hợp lệ (RFC8259), tiếng Việt:\n"
            . "{\n"
            . "  \"items\": [{\"name\": \"tên món\", \"serving\": \"khẩu phần\", \"calories\": 0, \"protein\": 0, \"carbs\": 0, \"fat\": 0, \"fiber\": 0}],\n"
            . "  \"total_calories\": 0, \"total_protein\": 0, \"total_carbs\": 0, \"total_fat\": 0, \"total_fiber\": 0,\n"
            . "  \"description\": \"mô tả ngắn bữa ăn\",\n"
            . "  \"health_note\": \"nhận xét dinh dưỡng ngắn gọn\"\n"
            . "}";

    $user_prompt = "Phân tích bữa ăn: {$meal_description}";
    if ( $recent_ctx ) {
        $user_prompt .= "\n\n[Ngữ cảnh hội thoại]:\n" . mb_substr( $recent_ctx, 0, 400 );
    }

    $ai = bizcity_openrouter_chat( [
        [ 'role' => 'system', 'content' => $system ],
        [ 'role' => 'user',   'content' => $user_prompt ],
    ], [ 'purpose' => 'chat' ] );

    $ai_data = null;
    if ( $ai['success'] && ! empty( $ai['message'] ) ) {
        $raw = trim( $ai['message'] );
        if ( preg_match( '/^```(?:json)?\s*/i', $raw ) ) {
            $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
            $raw = preg_replace( '/```\s*$/', '', $raw );
        }
        $ai_data = json_decode( trim( $raw ), true );
    }

    // Fallback estimate if AI fails
    if ( ! is_array( $ai_data ) || empty( $ai_data['items'] ) ) {
        $ai_data = [
            'items'          => [ [ 'name' => $meal_description, 'serving' => '1 phần', 'calories' => 400, 'protein' => 15, 'carbs' => 50, 'fat' => 12 ] ],
            'total_calories' => 400, 'total_protein' => 15, 'total_carbs' => 50, 'total_fat' => 12, 'total_fiber' => 2,
            'description'    => $meal_description,
            'health_note'    => '',
        ];
    }

    // Save to DB
    global $wpdb;
    $t    = bzcalo_tables();
    $date = current_time( 'Y-m-d' );

    $wpdb->insert( $t['meals'], [
        'user_id'        => $user_id,
        'meal_type'      => $meal_type,
        'meal_date'      => $date,
        'meal_time'      => current_time( 'H:i:s' ),
        'description'    => sanitize_text_field( $ai_data['description'] ?? $meal_description ),
        'photo_url'      => '',
        'ai_analysis'    => wp_json_encode( $ai_data, JSON_UNESCAPED_UNICODE ),
        'items_json'     => wp_json_encode( $ai_data['items'] ?? [], JSON_UNESCAPED_UNICODE ),
        'total_calories' => $ai_data['total_calories'] ?? 0,
        'total_protein'  => $ai_data['total_protein'] ?? 0,
        'total_carbs'    => $ai_data['total_carbs'] ?? 0,
        'total_fat'      => $ai_data['total_fat'] ?? 0,
        'total_fiber'    => $ai_data['total_fiber'] ?? 0,
        'source'         => 'chat',
        'platform'       => $platform ?: 'ZALO',
    ] );
    bzcalo_recalc_daily_stats( $user_id, $date );

    // Build response
    $today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    $cal_today  = (float) ( $today['total_calories'] ?? 0 );
    $remaining  = max( 0, $target - $cal_today );
    $meal_labels = [ 'breakfast' => '🌅 Sáng', 'lunch' => '☀️ Trưa', 'dinner' => '🌙 Tối', 'snack' => '🍪 Ăn vặt' ];
    $type_label  = $meal_labels[ $meal_type ] ?? $meal_type;

    $items_text = '';
    foreach ( ( $ai_data['items'] ?? [] ) as $item ) {
        $items_text .= "  • " . ( $item['name'] ?? '?' ) . " — " . ( $item['calories'] ?? 0 ) . " kcal\n";
    }

    $msg = "✅ Đã ghi bữa {$type_label}!\n\n"
         . "🍽️ " . ( $ai_data['description'] ?? $meal_description ) . "\n\n"
         . "📋 Chi tiết:\n{$items_text}\n"
         . "🔥 Calo bữa này: " . round( $ai_data['total_calories'] ?? 0 ) . " kcal\n"
         . "🥩 P: " . round( $ai_data['total_protein'] ?? 0 ) . "g | "
         . "🍞 C: " . round( $ai_data['total_carbs'] ?? 0 ) . "g | "
         . "🧈 F: " . round( $ai_data['total_fat'] ?? 0 ) . "g\n\n"
         . "📊 Tổng hôm nay: " . round( $cal_today ) . " / {$target} kcal\n"
         . "🎯 Còn lại: {$remaining} kcal";

    if ( ! empty( $ai_data['health_note'] ) ) {
        $msg .= "\n\n💡 " . $ai_data['health_note'];
    }

    return $msg;
}

/**
 * Get daily stats summary for Zalo/Bot
 */
function bzcalo_zalo_daily_stats( $user_id ) {
    if ( ! function_exists( 'bzcalo_tables' ) ) return '⚠️ Hệ thống Calo chưa sẵn sàng.';

    global $wpdb;
    $t       = bzcalo_tables();
    $date    = current_time( 'Y-m-d' );
    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    $today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    if ( ! $today || (float) ( $today['total_calories'] ?? 0 ) == 0 ) {
        return "📊 Thống kê hôm nay ({$date}):\n\n"
             . "Chưa ghi bữa ăn nào.\n"
             . "🎯 Mục tiêu: {$target} kcal/ngày\n\n"
             . "💡 Gửi tin nhắn mô tả bữa ăn hoặc gửi ảnh để ghi nhận!";
    }

    $cal       = round( (float) $today['total_calories'] );
    $remaining = max( 0, $target - $cal );
    $pct       = $target > 0 ? round( $cal / $target * 100 ) : 0;

    // Get meals list
    $meals = $wpdb->get_results( $wpdb->prepare(
        "SELECT meal_type, description, total_calories, meal_time FROM {$t['meals']}
         WHERE user_id = %d AND meal_date = %s ORDER BY meal_time ASC",
        $user_id, $date
    ), ARRAY_A );

    $meal_labels = [ 'breakfast' => '🌅', 'lunch' => '☀️', 'dinner' => '🌙', 'snack' => '🍪' ];
    $meals_text  = '';
    foreach ( $meals as $m ) {
        $icon = $meal_labels[ $m['meal_type'] ] ?? '🍽️';
        $time = substr( $m['meal_time'], 0, 5 );
        $meals_text .= "  {$icon} {$time} — {$m['description']} ({$m['total_calories']} kcal)\n";
    }

    return "📊 Thống kê hôm nay ({$date}):\n\n"
         . "{$meals_text}\n"
         . "🔥 Tổng: {$cal} / {$target} kcal ({$pct}%)\n"
         . "🥩 Protein: {$today['total_protein']}g | 🍞 Carbs: {$today['total_carbs']}g | 🧈 Fat: {$today['total_fat']}g\n"
         . "🎯 Còn lại: {$remaining} kcal\n"
         . ( (int) $today['meals_count'] > 0 ? "🍽️ Đã ghi: {$today['meals_count']} bữa" : '' );
}

/**
 * Weekly/monthly report for Zalo/Bot
 */
function bzcalo_zalo_report( $user_id, $message ) {
    if ( ! function_exists( 'bzcalo_tables' ) ) return '⚠️ Hệ thống Calo chưa sẵn sàng.';

    $days = preg_match( '/30|th[áa]ng/ui', $message ) ? 30 : 7;

    global $wpdb;
    $t     = bzcalo_tables();
    $since = date( 'Y-m-d', strtotime( "-{$days} days" ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT stat_date, total_calories, total_protein, total_carbs, total_fat, meals_count
         FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date >= %s ORDER BY stat_date ASC",
        $user_id, $since
    ), ARRAY_A );

    if ( empty( $rows ) ) {
        return "📈 Báo cáo {$days} ngày qua:\n\nChưa có dữ liệu. Hãy bắt đầu ghi bữa ăn!";
    }

    $total_cal = 0; $total_days = count( $rows );
    $lines = [];
    foreach ( $rows as $r ) {
        $total_cal += (float) $r['total_calories'];
        $cal = round( (float) $r['total_calories'] );
        $lines[] = "  {$r['stat_date']}: {$cal} kcal ({$r['meals_count']} bữa)";
    }

    $avg = $total_days > 0 ? round( $total_cal / $total_days ) : 0;
    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    return "📈 Báo cáo {$days} ngày qua:\n\n"
         . implode( "\n", $lines ) . "\n\n"
         . "📊 Trung bình: {$avg} kcal/ngày (mục tiêu: {$target})\n"
         . "📅 Số ngày ghi: {$total_days}/{$days}";
}

/**
 * Suggest meal via LLM for Zalo/Bot
 */
function bzcalo_zalo_suggest( $user_id, $message, $recent_ctx = '' ) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        return '⚠️ AI chưa sẵn sàng. Vui lòng thử lại sau.';
    }

    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );
    $goal    = $profile['goal'] ?? 'maintain';

    // Get today's intake so far
    global $wpdb;
    $t    = bzcalo_tables();
    $date = current_time( 'Y-m-d' );
    $today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    $eaten = round( (float) ( $today['total_calories'] ?? 0 ) );
    $remaining = max( 0, $target - $eaten );

    $goal_labels = [ 'lose' => 'Giảm cân', 'gain' => 'Tăng cân', 'maintain' => 'Duy trì' ];
    $goal_label  = isset( $goal_labels[ $goal ] ) ? $goal_labels[ $goal ] : 'Duy trì';
    $system = "Bạn là chuyên gia dinh dưỡng Việt Nam. Hãy gợi ý bữa ăn phù hợp.\n"
            . "Thông tin người dùng: Mục tiêu {$goal_label}, {$target} kcal/ngày.\n"
            . "Đã ăn hôm nay: {$eaten} kcal. Còn lại: {$remaining} kcal.\n"
            . ( ! empty( $profile['allergies'] ) ? "Dị ứng: {$profile['allergies']}\n" : '' )
            . "Trả lời ngắn gọn, gợi ý 2-3 món cụ thể với ước tính calo.";

    $user_prompt = $message;
    if ( $recent_ctx ) {
        $user_prompt .= "\n\n[Ngữ cảnh hội thoại]:\n" . mb_substr( $recent_ctx, 0, 400 );
    }

    $ai = bizcity_openrouter_chat( [
        [ 'role' => 'system', 'content' => $system ],
        [ 'role' => 'user',   'content' => $user_prompt ],
    ], [ 'purpose' => 'chat', 'max_tokens' => 500 ] );

    if ( $ai['success'] && ! empty( $ai['message'] ) ) {
        return "🍽️ Gợi ý bữa ăn:\n\n" . trim( $ai['message'] );
    }

    return "🍽️ Gợi ý nhanh:\nBạn còn {$remaining} kcal hôm nay.\n"
         . "• Cơm gà xối mỡ (~550 kcal)\n"
         . "• Salad cá hồi (~400 kcal)\n"
         . "• Phở bò (~450 kcal)";
}

/**
 * Compose a natural question asking what the user ate (instead of rigid template)
 */
function bzcalo_compose_natural_ask( $user_id, $message, $recent_ctx = '' ) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        return '🍽️ Bạn ăn gì vậy? Mô tả bữa ăn nhé (ví dụ: "1 tô phở bò và 1 ly trà đá")';
    }

    $profile = bzcalo_get_or_create_profile( $user_id );
    $name    = $profile['full_name'] ?: '';

    $system = "Bạn là CaloCoach — trợ lý dinh dưỡng thân thiện, gần gũi.\n"
            . "Người dùng vừa thể hiện ý định ghi bữa ăn nhưng chưa nói cụ thể ăn gì.\n"
            . "Hãy viết 1 câu tự nhiên (1-2 dòng) hỏi họ ăn gì, dùng giọng thân mật.\n"
            . "Có thể dùng emoji nhẹ. Đừng dùng markdown. Gợi ý format (ví dụ: '1 tô phở, 1 ly trà đá').";

    $user_prompt = "Tin nhắn người dùng: \"{$message}\"";
    if ( $name ) $user_prompt .= "\nTên: {$name}";
    if ( $recent_ctx ) {
        $user_prompt .= "\n\n[Hội thoại gần đây]:\n" . mb_substr( $recent_ctx, 0, 300 );
    }

    $ai = bizcity_openrouter_chat( [
        [ 'role' => 'system', 'content' => $system ],
        [ 'role' => 'user',   'content' => $user_prompt ],
    ], [ 'model' => 'google/gemini-2.0-flash-lite-001', 'purpose' => 'chat', 'max_tokens' => 150 ] );

    if ( $ai['success'] && ! empty( $ai['message'] ) ) {
        return trim( $ai['message'] );
    }

    // Fallback
    $greet = $name ? "{$name} ơi, " : '';
    return "{$greet}🍽️ Bạn vừa ăn gì vậy? Nói cho mình nghe nhé (ví dụ: \"cơm gà, canh rau\")";
}

/* ═══════════════════════════════════════════════
   INTENT FILTER — Webchat / Admin Chat
   Priority 10 — runs AFTER Intent Engine (priority 5).
   Only handles IMAGES that the engine missed.
   Text-only intents are handled by the Intent Engine via
   goal patterns → slot-fill → tool → ai_compose flow.
   ═══════════════════════════════════════════════ */

add_filter( 'bizcity_chat_pre_ai_response', 'bzcalo_webchat_filter', 10, 2 );
function bzcalo_webchat_filter( $pre_reply, $ctx ) {
    if ( $pre_reply ) return $pre_reply;

    $message   = isset( $ctx['message'] ) ? $ctx['message'] : '';
    $has_image = bzcalo_has_image( $ctx );

    // TEXT-ONLY: Do NOT intercept here.
    // The Intent Engine (priority 5) handles text via calo_suggest/calo_log_meal/etc.
    // If engine sets compose_answer, the Chat Gateway calls OpenRouter with enriched prompt.
    // If we intercept here, we'd short-circuit that AI-compose flow with a dumb link.
    if ( ! $has_image ) return $pre_reply;

    // IMAGE CASES: Catch images that the Intent Engine didn't handle.
    // (Engine may not detect images if no goal pattern matched the text.)
    $image_url = bzcalo_extract_image( $ctx );
    $user_id   = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id();

    if ( $image_url && $user_id ) {
        // Always analyze images — Vision AI determines if it's food
        $result = bzcalo_chat_analyze_photo( $image_url, $user_id, $message );
        if ( $result && ! empty( $result['message'] ) ) {
            return array( 'message' => $result['message'] );
        }
    }

    // If image but no user / extraction failed → let gateway handle
    if ( ! $image_url || ! $user_id ) {
        return $pre_reply;
    }

    // Fallback: link to Calo page
    $link = bzcalo_build_link(
        isset( $ctx['session_id'] ) ? $ctx['session_id'] : '',
        $user_id, '', '', 0, $message
    );
    return array( 'message' => "📸 Tôi đã nhận ảnh bữa ăn!\n🔗 Mở Calo AI để phân tích chi tiết:\n{$link}" );
}

/**
 * Analyze photo directly in chat context (no browser required)
 */
function bzcalo_chat_analyze_photo( $image_url, $user_id, $text_context = '' ) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) return null;

    $profile = bzcalo_get_or_create_profile( $user_id );
    $target  = (int) ( $profile['daily_calo_target'] ?? 2000 );

    // Determine meal type from time
    $hour = (int) current_time( 'G' );
    if ( $hour < 10 ) $meal_type = 'breakfast';
    elseif ( $hour < 14 ) $meal_type = 'lunch';
    elseif ( $hour < 17 ) $meal_type = 'snack';
    else $meal_type = 'dinner';

    // AI Vision analysis
    $system = "Bạn là chuyên gia dinh dưỡng. Phân tích ảnh bữa ăn và ước tính calo.\n"
            . "Nếu ảnh KHÔNG chứa thức ăn, trả về JSON: {\"is_food\": false, \"message\": \"Ảnh không chứa thức ăn.\"}\n"
            . "Nếu người dùng nói cho bao nhiêu người ăn, hãy NHÂN khẩu phần theo số người.\n"
            . "Nếu ảnh CÓ thức ăn, CHỈ trả về JSON hợp lệ (RFC8259), tiếng Việt:\n"
            . "{\n"
            . "  \"is_food\": true,\n"
            . "  \"items\": [{\"name\": \"tên món\", \"serving\": \"khẩu phần\", \"calories\": 0, \"protein\": 0, \"carbs\": 0, \"fat\": 0}],\n"
            . "  \"total_calories\": 0, \"total_protein\": 0, \"total_carbs\": 0, \"total_fat\": 0, \"total_fiber\": 0,\n"
            . "  \"description\": \"mô tả bữa ăn trong ảnh\",\n"
            . "  \"health_note\": \"nhận xét\"\n"
            . "}";

    $user_content = array(
        array( 'type' => 'text', 'text' => 'Phân tích ảnh bữa ăn này:' . ( $text_context ? " Ghi chú: {$text_context}" : '' ) ),
        array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url ) ),
    );

    $ai = bizcity_openrouter_chat( array(
        array( 'role' => 'system', 'content' => $system ),
        array( 'role' => 'user',   'content' => $user_content ),
    ), array( 'model' => 'google/gemini-2.0-flash-001', 'purpose' => 'vision' ) );

    if ( ! $ai['success'] || empty( $ai['message'] ) ) return null;

    // Parse response
    $raw = trim( $ai['message'] );
    if ( preg_match( '/^```(?:json)?\s*/i', $raw ) ) {
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = preg_replace( '/```\s*$/', '', $raw );
    }
    $ai_data = json_decode( trim( $raw ), true );
    if ( ! is_array( $ai_data ) ) return null;

    // If AI determined this is NOT a food image, return null to let gateway handle
    if ( isset( $ai_data['is_food'] ) && ! $ai_data['is_food'] ) {
        return null;
    }

    // Save to DB
    global $wpdb;
    $t    = bzcalo_tables();
    $date = current_time( 'Y-m-d' );

    $wpdb->insert( $t['meals'], array(
        'user_id'        => $user_id,
        'meal_type'      => $meal_type,
        'meal_date'      => $date,
        'meal_time'      => current_time( 'H:i:s' ),
        'description'    => sanitize_text_field( $ai_data['description'] ?? 'Bữa ăn từ chat' ),
        'photo_url'      => esc_url_raw( $image_url ),
        'ai_analysis'    => wp_json_encode( $ai_data, JSON_UNESCAPED_UNICODE ),
        'items_json'     => wp_json_encode( $ai_data['items'] ?? array(), JSON_UNESCAPED_UNICODE ),
        'total_calories' => $ai_data['total_calories'] ?? 0,
        'total_protein'  => $ai_data['total_protein'] ?? 0,
        'total_carbs'    => $ai_data['total_carbs'] ?? 0,
        'total_fat'      => $ai_data['total_fat'] ?? 0,
        'total_fiber'    => $ai_data['total_fiber'] ?? 0,
        'source'         => 'photo',
        'platform'       => 'WEBCHAT',
    ) );
    bzcalo_recalc_daily_stats( $user_id, $date );

    // Build response message
    $today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    $cal_today  = (float) ( $today['total_calories'] ?? 0 );
    $remaining  = max( 0, $target - $cal_today );
    $meal_types = array( 'breakfast' => '🌅 Sáng', 'lunch' => '☀️ Trưa', 'dinner' => '🌙 Tối', 'snack' => '🍪 Ăn vặt' );
    $type_label = $meal_types[ $meal_type ] ?? $meal_type;

    $items_text = '';
    if ( ! empty( $ai_data['items'] ) ) {
        foreach ( $ai_data['items'] as $item ) {
            $items_text .= "  • " . ( $item['name'] ?? '?' ) . " — " . ( $item['calories'] ?? 0 ) . " kcal\n";
        }
    }

    $msg = "📸 Đã phân tích & ghi bữa {$type_label}!\n\n"
         . "🍽️ " . ( $ai_data['description'] ?? '' ) . "\n\n"
         . "📋 Nhận diện:\n{$items_text}\n"
         . "🔥 Calo bữa này: " . round( $ai_data['total_calories'] ?? 0 ) . " kcal\n"
         . "🥩 P: " . round( $ai_data['total_protein'] ?? 0 ) . "g | "
         . "🍞 C: " . round( $ai_data['total_carbs'] ?? 0 ) . "g | "
         . "🧈 F: " . round( $ai_data['total_fat'] ?? 0 ) . "g\n\n"
         . "📊 Tổng hôm nay: " . round( $cal_today ) . " / {$target} kcal\n"
         . "🎯 Còn lại: {$remaining} kcal";

    if ( ! empty( $ai_data['health_note'] ) ) {
        $msg .= "\n\n💡 " . $ai_data['health_note'];
    }

    return array(
        'success' => true,
        'message' => $msg,
        'data'    => $ai_data,
    );
}

/* ═══════════════════════════════════════════════
   PUSH RESULT BACK TO CHAT
   ═══════════════════════════════════════════════ */

add_action( 'wp_ajax_bzcalo_push_result',        'bzcalo_ajax_push_result' );
add_action( 'wp_ajax_nopriv_bzcalo_push_result', 'bzcalo_ajax_push_result' );

function bzcalo_ajax_push_result() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );

    $token    = sanitize_text_field( $_POST['token'] ?? '' );
    $ai_reply = wp_kses_post( $_POST['ai_reply'] ?? '' );

    $ctx = bzcalo_validate_token( $token );
    if ( ! $ctx ) {
        wp_send_json_error( array( 'message' => 'Token hết hạn' ) );
    }

    bzcalo_send_long_message( $ctx['chat_id'], $ctx['client_id'], $ctx['platform'], $ai_reply );

    wp_send_json_success( array(
        'sent'     => true,
        'chat_id'  => $ctx['chat_id'],
        'platform' => $ctx['platform'],
    ) );
}

/* ═══════════════════════════════════════════════
   SEND LONG MESSAGE (split for chat platforms)
   ═══════════════════════════════════════════════ */

function bzcalo_send_long_message( $chat_id, $client_id, $platform, $text, $max_len = 2000 ) {
    if ( empty( $text ) || empty( $chat_id ) ) return;

    $chunks = bzcalo_split_message( $text, $max_len );
    foreach ( $chunks as $chunk ) {
        if ( function_exists( 'biz_send_message' ) ) {
            biz_send_message( $chat_id, $chunk );
        }
        if ( count( $chunks ) > 1 ) {
            usleep( 300000 );
        }
    }
}

function bzcalo_split_message( $text, $max_len = 2000 ) {
    if ( mb_strlen( $text ) <= $max_len ) return array( $text );

    $chunks    = array();
    $remaining = $text;

    while ( mb_strlen( $remaining ) > 0 ) {
        if ( mb_strlen( $remaining ) <= $max_len ) {
            $chunks[] = $remaining;
            break;
        }
        $cut     = mb_substr( $remaining, 0, $max_len );
        $last_nl = mb_strrpos( $cut, "\n\n" );
        if ( $last_nl !== false && $last_nl > $max_len * 0.3 ) {
            $chunks[]  = mb_substr( $remaining, 0, $last_nl );
            $remaining = mb_substr( $remaining, $last_nl + 2 );
        } else {
            $chunks[]  = $cut;
            $remaining = mb_substr( $remaining, $max_len );
        }
    }

    return $chunks;
}
