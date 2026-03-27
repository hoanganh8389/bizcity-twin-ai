<?php
/**
 * AJAX endpoints — admin + public.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   ADMIN ENDPOINTS
   ═══════════════════════════════════════════════ */

add_action( 'wp_ajax_bz{prefix}_import', 'bz{prefix}_ajax_import' );
function bz{prefix}_ajax_import() {
    check_ajax_referer( 'bz{prefix}_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Không có quyền.' ) );
    }

    // TODO: Import logic
    wp_send_json_success( array( 'imported' => 0 ) );
}

/* ═══════════════════════════════════════════════
   PUBLIC ENDPOINTS
   ═══════════════════════════════════════════════ */

// ── Get items ──
add_action( 'wp_ajax_bz{prefix}_get_items',        'bz{prefix}_ajax_get_items' );
add_action( 'wp_ajax_nopriv_bz{prefix}_get_items', 'bz{prefix}_ajax_get_items' );

function bz{prefix}_ajax_get_items() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    global $wpdb;
    $t     = bz{prefix}_tables();
    $items = $wpdb->get_results(
        "SELECT id, slug, name_vi, name_en, category, image_url, sort_order FROM {$t['items']} ORDER BY sort_order",
        ARRAY_A
    );

    wp_send_json_success( $items );
}

// ── Get detail ──
add_action( 'wp_ajax_bz{prefix}_get_detail',        'bz{prefix}_ajax_get_detail' );
add_action( 'wp_ajax_nopriv_bz{prefix}_get_detail', 'bz{prefix}_ajax_get_detail' );

function bz{prefix}_ajax_get_detail() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $slug = sanitize_text_field( $_POST['slug'] ?? '' );
    if ( empty( $slug ) ) {
        wp_send_json_error( array( 'message' => 'Thiếu slug.' ) );
    }

    global $wpdb;
    $t    = bz{prefix}_tables();
    $item = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['items']} WHERE slug = %s",
        $slug
    ), ARRAY_A );

    if ( ! $item ) {
        wp_send_json_error( array( 'message' => 'Không tìm thấy.' ) );
    }

    wp_send_json_success( $item );
}

// ── Save result (history) ──
add_action( 'wp_ajax_bz{prefix}_save_result',        'bz{prefix}_ajax_save_result' );
add_action( 'wp_ajax_nopriv_bz{prefix}_save_result', 'bz{prefix}_ajax_save_result' );

function bz{prefix}_ajax_save_result() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $topic   = sanitize_text_field( $_POST['topic'] ?? '' );
    $result  = wp_kses_post( $_POST['result'] ?? '' );
    $token   = sanitize_text_field( $_POST['token'] ?? '' );

    $user_id   = get_current_user_id();
    $client_id = '';
    $platform  = 'WEBCHAT';
    $session   = '';

    // Parse token context if available
    if ( $token && function_exists( 'bz{prefix}_validate_token' ) ) {
        $ctx = bz{prefix}_validate_token( $token );
        if ( $ctx ) {
            $client_id = $ctx['client_id'] ?? '';
            $platform  = $ctx['platform'] ?? 'WEBCHAT';
            $session   = $ctx['chat_id'] ?? '';
            if ( ! $user_id && ! empty( $ctx['user_id'] ) ) {
                $user_id = (int) $ctx['user_id'];
            }
        }
    }

    global $wpdb;
    $t = bz{prefix}_tables();
    $wpdb->insert( $t['history'], array(
        'user_id'     => $user_id ?: null,
        'client_id'   => $client_id,
        'platform'    => $platform,
        'session_id'  => $session,
        'topic'       => $topic,
        'result_json' => $result,
    ) );

    wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
}

// ── AI interpret ──
add_action( 'wp_ajax_bz{prefix}_ai_interpret',        'bz{prefix}_ajax_ai_interpret' );
add_action( 'wp_ajax_nopriv_bz{prefix}_ai_interpret', 'bz{prefix}_ajax_ai_interpret' );

function bz{prefix}_ajax_ai_interpret() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $topic  = sanitize_text_field( $_POST['topic'] ?? '' );
    $data   = wp_kses_post( $_POST['data'] ?? '' );
    $prompt = sanitize_text_field( $_POST['prompt'] ?? '' );

    if ( empty( $data ) ) {
        wp_send_json_error( array( 'message' => 'Thiếu dữ liệu.' ) );
    }

    // Build system + user messages for AI
    $system = "Bạn là chuyên gia {domain}. Hãy phân tích kết quả sau:\n\n" . $data;
    if ( $topic ) {
        $system .= "\n\nChủ đề: " . $topic;
    }

    // Call OpenRouter API if available
    if ( class_exists( 'BizCity_OpenRouter_API' ) ) {
        $api = new BizCity_OpenRouter_API();
        $messages = array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $prompt ?: 'Hãy phân tích chi tiết.' ),
        );
        $result = $api->chat( $messages );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'ai_reply' => $result ) );
    }

    wp_send_json_error( array( 'message' => 'AI API chưa sẵn sàng.' ) );
}

// ── Push result to chat ──
add_action( 'wp_ajax_bz{prefix}_push_result',        'bz{prefix}_ajax_push_result' );
add_action( 'wp_ajax_nopriv_bz{prefix}_push_result', 'bz{prefix}_ajax_push_result' );

function bz{prefix}_ajax_push_result() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $token    = sanitize_text_field( $_POST['token'] ?? '' );
    $ai_reply = wp_kses_post( $_POST['ai_reply'] ?? '' );

    if ( empty( $token ) || empty( $ai_reply ) ) {
        wp_send_json_error( array( 'message' => 'Thiếu token hoặc nội dung.' ) );
    }

    $ctx = bz{prefix}_validate_token( $token );
    if ( ! $ctx ) {
        wp_send_json_error( array( 'message' => 'Token hết hạn hoặc không hợp lệ.' ) );
    }

    // Send message back to chat platform
    bz{prefix}_send_long_message(
        $ctx['chat_id']   ?? '',
        $ctx['client_id'] ?? '',
        $ctx['platform']  ?? '',
        $ai_reply
    );

    wp_send_json_success( array(
        'sent'     => true,
        'chat_id'  => $ctx['chat_id'] ?? '',
        'platform' => $ctx['platform'] ?? '',
    ) );
}
