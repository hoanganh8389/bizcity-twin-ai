<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * AJAX: Ask Gemini (from admin Ask page)
 * ================================================================ */
add_action( 'wp_ajax_bzgk_ask', 'bzgk_ajax_ask' );
function bzgk_ajax_ask() {
    check_ajax_referer( 'bzgk_nonce', 'nonce' );

    $user_id  = get_current_user_id();
    $question = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );

    if ( empty( $question ) ) {
        wp_send_json_error( [ 'message' => 'Câu hỏi trống.' ] );
    }

    $gemini = BizCity_Gemini_Knowledge::instance();
    $result = $gemini->ask( $question, [
        'user_id' => $user_id,
    ] );

    if ( $result['success'] ) {
        wp_send_json_success( [
            'answer'       => $result['answer'],
            'model'        => $result['model'],
            'tokens'       => $result['usage'],
        ] );
    } else {
        wp_send_json_error( [
            'message' => $result['error'] ?: 'Gemini không phản hồi. Thử lại sau.',
        ] );
    }
}

/* ================================================================
 * AJAX: Ask Gemini (public — from shortcode)
 * ================================================================ */
add_action( 'wp_ajax_bzgk_public_ask', 'bzgk_ajax_public_ask' );
function bzgk_ajax_public_ask() {
    check_ajax_referer( 'bzgk_pub_nonce', 'nonce' );

    $user_id  = get_current_user_id();
    $question = sanitize_text_field( wp_unslash( $_POST['question'] ?? '' ) );

    if ( empty( $question ) ) {
        wp_send_json_error( [ 'message' => 'Câu hỏi trống.' ] );
    }

    $gemini = BizCity_Gemini_Knowledge::instance();
    $result = $gemini->ask( $question, [
        'user_id' => $user_id,
    ] );

    if ( $result['success'] ) {
        wp_send_json_success( [
            'answer' => $result['answer'],
            'model'  => $result['model'],
        ] );
    } else {
        wp_send_json_error( [
            'message' => $result['error'] ?: 'Không thể trả lời lúc này.',
        ] );
    }
}

/* ================================================================
 * AJAX: Save Bookmark
 * ================================================================ */
add_action( 'wp_ajax_bzgk_save_bookmark', 'bzgk_ajax_save_bookmark' );
function bzgk_ajax_save_bookmark() {
    check_ajax_referer( 'bzgk_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( [ 'message' => 'Chưa đăng nhập.' ] );

    global $wpdb;
    $t = bzgk_tables();

    $query  = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
    $answer = wp_kses_post( wp_unslash( $_POST['answer'] ?? '' ) );
    $model  = sanitize_text_field( $_POST['model'] ?? '' );
    $tags   = sanitize_text_field( $_POST['tags'] ?? '' );

    if ( empty( $query ) || empty( $answer ) ) {
        wp_send_json_error( [ 'message' => 'Dữ liệu bookmark trống.' ] );
    }

    $wpdb->insert( $t['bookmarks'], [
        'user_id'     => $user_id,
        'query_text'  => mb_substr( $query, 0, 500 ),
        'answer_text' => $answer,
        'model_used'  => $model,
        'tags'        => $tags,
        'created_at'  => current_time( 'mysql' ),
    ] );

    wp_send_json_success( [
        'message' => 'Đã bookmark!',
        'id'      => $wpdb->insert_id,
    ] );
}

/* ================================================================
 * AJAX: Delete Bookmark
 * ================================================================ */
add_action( 'wp_ajax_bzgk_delete_bookmark', 'bzgk_ajax_delete_bookmark' );
function bzgk_ajax_delete_bookmark() {
    check_ajax_referer( 'bzgk_nonce', 'nonce' );

    $user_id = get_current_user_id();
    $id      = intval( $_POST['id'] ?? 0 );

    if ( ! $user_id || ! $id ) {
        wp_send_json_error( [ 'message' => 'Thiếu dữ liệu.' ] );
    }

    global $wpdb;
    $t = bzgk_tables();

    $wpdb->delete( $t['bookmarks'], [
        'id'      => $id,
        'user_id' => $user_id,
    ] );

    wp_send_json_success( [ 'message' => 'Đã xóa.' ] );
}

/* ================================================================
 * AJAX: Get Settings (for admin)
 * ================================================================ */
add_action( 'wp_ajax_bzgk_get_settings', 'bzgk_ajax_get_settings' );
function bzgk_ajax_get_settings() {
    check_ajax_referer( 'bzgk_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }
    $gemini = BizCity_Gemini_Knowledge::instance();
    wp_send_json_success( $gemini->get_settings() );
}
