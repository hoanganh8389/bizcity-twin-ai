<?php
/**
 * Chat integration — intent detection, secure tokens, push results.
 *
 * Enriched context keys available in $ctx:
 *   - image_url:       URL ảnh đã xử lý (từ transient hoặc attachment)
 *   - recent_context:  Ngữ cảnh hội thoại gần đây (30 phút / 100 tin nhắn)
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   INTENT DETECTION — Keywords
   ═══════════════════════════════════════════════ */

/**
 * Check if message matches this plugin's intent.
 *
 * @param string $message
 * @return bool
 */
function bz{prefix}_is_intent( $message ) {
    // CUSTOMIZE: Add your keywords here
    return (bool) preg_match(
        '/{keyword1}|{keyword2}|{keyword3}|{keyword4}/ui',
        $message
    );
}

/* ═══════════════════════════════════════════════
   SECURE TOKEN SYSTEM
   ═══════════════════════════════════════════════ */

function bz{prefix}_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $payload = compact( 'chat_id', 'user_id', 'client_id', 'platform', 'blog_id', 'message' );
    $payload['created_at'] = time();
    $token = substr( hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) ), 0, 20 );
    set_site_transient( 'bz{prefix}_token_' . $token, $payload, 48 * HOUR_IN_SECONDS );
    return $token;
}

function bz{prefix}_validate_token( $token ) {
    $token = preg_replace( '/[^a-f0-9]/', '', $token );
    if ( empty( $token ) ) return null;
    return get_site_transient( 'bz{prefix}_token_' . $token ) ?: null;
}

function bz{prefix}_build_link( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $token = bz{prefix}_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message );
    $url   = bz{prefix}_get_page_url();
    return add_query_arg( 'bz{prefix}_token', $token, $url );
}

/**
 * Get the published page URL containing the shortcode.
 *
 * @return string
 */
function bz{prefix}_get_page_url() {
    static $url = null;
    if ( $url !== null ) return $url;

    global $wpdb;
    $page_id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%[bizcity_{slug}]%' AND post_status = 'publish' LIMIT 1" );
    $url = $page_id ? get_permalink( $page_id ) : get_site_url();
    return $url;
}

/* ═══════════════════════════════════════════════
   INTENT FILTER — Zalo / Bot platforms
   Uses enriched context: image_url, recent_context.
   ═══════════════════════════════════════════════ */

add_filter( 'bizcity_unified_message_intent', 'bz{prefix}_intent_filter', 10, 2 );
function bz{prefix}_intent_filter( $handled, $ctx ) {
    if ( $handled ) return $handled;

    $message   = $ctx['message'] ?? '';
    $user_id   = (int) ( $ctx['wp_user_id'] ?? 0 );
    $chat_id   = $ctx['chat_id'] ?? '';
    $platform  = $ctx['platform'] ?? '';
    $recent    = $ctx['recent_context'] ?? '';  // ✨ Enriched: recent conversation context
    $image_url = $ctx['image_url'] ?? '';       // ✨ Enriched: image from transient
    $client_id = $ctx['client_id'] ?? '';

    // ── 1. Check pending follow-up TRƯỚC keyword check ──
    // User may reply with data that doesn't match keywords (e.g. "cơm gà")
    if ( $chat_id ) {
        $pending_key = 'bz{prefix}_pending_' . md5( $chat_id );
        $pending     = get_site_transient( $pending_key );
        if ( $pending && ! empty( $message ) ) {
            delete_site_transient( $pending_key );
            // CUSTOMIZE: Process the follow-up input
            // $reply = bz{prefix}_process_followup( $user_id, $message, $pending, $recent );
            // bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
            // return true;
        }
    }

    // ── 2. Keyword check ──
    if ( ! bz{prefix}_is_intent( $message ) ) return $handled;

    // ── 3. Process natively (RECOMMENDED) ──
    // CUSTOMIZE: Choose between native processing or link redirect

    // OPTION A: Native processing (recommended for most plugins)
    // $sub = bz{prefix}_classify_sub_intent( $message );
    // switch ( $sub ) {
    //     case 'action':
    //         $reply = bz{prefix}_process_action( $user_id, $message, $platform, $recent );
    //         bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
    //         return true;
    //     case 'query':
    //         $reply = bz{prefix}_process_query( $user_id, $message, $recent );
    //         bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
    //         return true;
    //     default:
    //         // Need more info → ask naturally (use LLM)
    //         $ask = bz{prefix}_compose_natural_ask( $user_id, $message, $recent );
    //         set_site_transient( 'bz{prefix}_pending_' . md5($chat_id), 'awaiting_input', 10 * MINUTE_IN_SECONDS );
    //         biz_send_message( $chat_id, $ask );
    //         return true;
    // }

    // OPTION B: Link redirect (for plugins that need frontend interaction)
    $link = bz{prefix}_build_link(
        $chat_id, $user_id, $client_id, $platform,
        $ctx['blog_id'] ?? 0, $message
    );

    if ( function_exists( 'biz_send_message' ) ) {
        biz_send_message( $chat_id, "🔮 Truy cập link:\n{$link}" );
    }

    return true;
}

/* ═══════════════════════════════════════════════
   INTENT FILTER — Webchat / Admin Chat
   Text intents handled by Intent Engine via Provider.
   Only handle special cases here (images, files, etc.)
   ═══════════════════════════════════════════════ */

add_filter( 'bizcity_chat_pre_ai_response', 'bz{prefix}_webchat_filter', 10, 2 );
function bz{prefix}_webchat_filter( $pre_reply, $ctx ) {
    if ( $pre_reply ) return $pre_reply;

    $message = isset( $ctx['message'] ) ? $ctx['message'] : '';

    // TEXT-ONLY: Do NOT intercept here.
    // The Intent Engine (priority 5) handles text via goal patterns → slot-fill → tool → ai_compose flow.
    // Only catch special cases (images, files) that the engine didn't handle.
    if ( ! bz{prefix}_has_special_case( $ctx ) ) return $pre_reply;

    // CUSTOMIZE: Handle special cases (images, attachments, etc.)
    // $result = bz{prefix}_process_special( $ctx );
    // if ( $result ) return [ 'message' => $result ];

    // Fallback: link
    $link = bz{prefix}_build_link(
        isset( $ctx['session_id'] ) ? $ctx['session_id'] : '',
        isset( $ctx['user_id'] )    ? $ctx['user_id']    : 0,
        '', '', 0, $message
    );

    return array( 'message' => "🔮 Truy cập link:\n{$link}" );
}

/**
 * Check if context has special cases that need handling outside Intent Engine.
 * CUSTOMIZE: Add your conditions (images, specific attachments, etc.)
 */
function bz{prefix}_has_special_case( $ctx ) {
    // Example: check for images
    if ( ! empty( $ctx['image_url'] ) ) return true;
    if ( ! empty( $ctx['images'] ) && is_array( $ctx['images'] ) ) return true;
    return false;
}

/* ═══════════════════════════════════════════════
   NATIVE PROCESSING HELPERS (for Zalo/Bot)
   Uncomment and customize these when using OPTION A.
   ═══════════════════════════════════════════════ */

// /**
//  * Classify sub-intent from message text
//  */
// function bz{prefix}_classify_sub_intent( $message ) {
//     $msg = mb_strtolower( $message );
//     if ( preg_match( '/thống kê|bao nhiêu|tổng|stats/ui', $msg ) ) return 'query';
//     if ( preg_match( '/{action_keywords}/ui', $msg ) ) return 'action';
//     return 'needs_input';
// }

// /**
//  * Process an action with recent context
//  * NOTE: Use explicit $user_id, NOT get_current_user_id() (doesn't work on Zalo)
//  */
// function bz{prefix}_process_action( $user_id, $message, $platform, $recent_ctx = '' ) {
//     if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
//         return '⚠️ AI chưa sẵn sàng.';
//     }
//
//     $user_prompt = "Xử lý: {$message}";
//     // ✨ LUÔN include recent_context khi gọi AI
//     if ( $recent_ctx ) {
//         $user_prompt .= "\n\n[Ngữ cảnh hội thoại]:\n" . mb_substr( $recent_ctx, 0, 500 );
//     }
//
//     $ai = bizcity_openrouter_chat( [
//         [ 'role' => 'system', 'content' => $system_prompt ],
//         [ 'role' => 'user',   'content' => $user_prompt ],
//     ], [ 'purpose' => 'chat' ] );
//
//     return $ai['success'] ? trim( $ai['message'] ) : '⚠️ Lỗi xử lý.';
// }

// /**
//  * Compose natural question using LLM (instead of rigid template)
//  */
// function bz{prefix}_compose_natural_ask( $user_id, $message, $recent_ctx = '' ) {
//     if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
//         return 'Bạn cần thêm thông tin gì?';
//     }
//     $system = "Viết 1 câu tự nhiên (1-2 dòng) hỏi user thêm thông tin. Giọng thân mật.";
//     $user_prompt = "Tin nhắn: \"{$message}\"";
//     if ( $recent_ctx ) {
//         $user_prompt .= "\n\n[Hội thoại gần đây]:\n" . mb_substr( $recent_ctx, 0, 300 );
//     }
//     $ai = bizcity_openrouter_chat( [
//         [ 'role' => 'system', 'content' => $system ],
//         [ 'role' => 'user',   'content' => $user_prompt ],
//     ], [ 'model' => 'google/gemini-2.0-flash-lite-001', 'purpose' => 'chat', 'max_tokens' => 150 ] );
//     return $ai['success'] ? trim( $ai['message'] ) : 'Bạn cần thêm thông tin gì?';
// }

/* ═══════════════════════════════════════════════
   SEND LONG MESSAGE (split for chat platforms)
   ═══════════════════════════════════════════════ */

function bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $text, $max_len = 2000 ) {
    if ( empty( $text ) || empty( $chat_id ) ) return;

    $chunks = bz{prefix}_split_message( $text, $max_len );
    foreach ( $chunks as $chunk ) {
        if ( function_exists( 'biz_send_message' ) ) {
            biz_send_message( $chat_id, $chunk );
        }
        if ( count( $chunks ) > 1 ) {
            usleep( 300000 ); // 300ms delay between chunks
        }
    }
}

function bz{prefix}_split_message( $text, $max_len = 2000 ) {
    if ( mb_strlen( $text ) <= $max_len ) return array( $text );

    $chunks    = array();
    $remaining = $text;

    while ( mb_strlen( $remaining ) > 0 ) {
        if ( mb_strlen( $remaining ) <= $max_len ) {
            $chunks[] = $remaining;
            break;
        }

        $cut = mb_substr( $remaining, 0, $max_len );
        // Try to cut at paragraph boundary
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

/* ═══════════════════════════════════════════════
   PUSH RESULT BACK TO CHAT
   ═══════════════════════════════════════════════ */

add_action( 'wp_ajax_bz{prefix}_push_result',        'bz{prefix}_ajax_push_result' );
add_action( 'wp_ajax_nopriv_bz{prefix}_push_result', 'bz{prefix}_ajax_push_result' );

function bz{prefix}_ajax_push_result() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $token    = sanitize_text_field( $_POST['token'] ?? '' );
    $ai_reply = wp_kses_post( $_POST['ai_reply'] ?? '' );

    $ctx = bz{prefix}_validate_token( $token );
    if ( ! $ctx ) {
        wp_send_json_error( array( 'message' => 'Token hết hạn' ) );
    }

    bz{prefix}_send_long_message( $ctx['chat_id'], $ctx['client_id'], $ctx['platform'], $ai_reply );

    wp_send_json_success( array(
        'sent'     => true,
        'chat_id'  => $ctx['chat_id'],
        'platform' => $ctx['platform'],
    ) );
}
