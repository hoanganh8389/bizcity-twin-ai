<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * AJAX: Save / Update Profile
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_save_profile', 'bzcalo_ajax_save_profile' );
function bzcalo_ajax_save_profile() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    global $wpdb;
    $t = bzcalo_tables();

    $profile = bzcalo_get_or_create_profile( $user_id );
    $data = array(
        'full_name'       => sanitize_text_field( $_POST['full_name'] ?? $profile['full_name'] ),
        'gender'          => in_array( $_POST['gender'] ?? '', array( 'male', 'female', 'other' ) ) ? $_POST['gender'] : $profile['gender'],
        'dob'             => sanitize_text_field( $_POST['dob'] ?? '' ) ?: $profile['dob'],
        'height_cm'       => is_numeric( $_POST['height_cm'] ?? '' ) ? (float) $_POST['height_cm'] : $profile['height_cm'],
        'weight_kg'       => is_numeric( $_POST['weight_kg'] ?? '' ) ? (float) $_POST['weight_kg'] : $profile['weight_kg'],
        'target_weight'   => is_numeric( $_POST['target_weight'] ?? '' ) ? (float) $_POST['target_weight'] : $profile['target_weight'],
        'activity_level'  => sanitize_text_field( $_POST['activity_level'] ?? $profile['activity_level'] ),
        'goal'            => sanitize_text_field( $_POST['goal'] ?? $profile['goal'] ),
        'allergies'       => sanitize_textarea_field( $_POST['allergies'] ?? '' ),
        'medical_notes'   => sanitize_textarea_field( $_POST['medical_notes'] ?? '' ),
        'updated_at'      => current_time( 'mysql' ),
    );

    // Auto-calc daily calo target from BMR
    $temp_profile = array_merge( $profile, $data );
    $data['daily_calo_target'] = bzcalo_calc_bmr( $temp_profile );

    // Goal adjustments
    if ( $data['goal'] === 'lose' ) $data['daily_calo_target'] -= 300;
    if ( $data['goal'] === 'gain' ) $data['daily_calo_target'] += 300;

    $wpdb->update( $t['profiles'], $data, array( 'user_id' => $user_id ) );

    wp_send_json_success( array(
        'message'          => 'Đã lưu hồ sơ!',
        'daily_calo_target'=> $data['daily_calo_target'],
    ) );
}

/* ================================================================
 * AJAX: Get Profile
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_get_profile', 'bzcalo_ajax_get_profile' );
function bzcalo_ajax_get_profile() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );
    $profile = bzcalo_get_or_create_profile( $user_id );
    wp_send_json_success( $profile );
}

/* ================================================================
 * AJAX: Log Meal (manual / photo)
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_log_meal', 'bzcalo_ajax_log_meal' );
function bzcalo_ajax_log_meal() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    global $wpdb;
    $t = bzcalo_tables();

    $meal_type = sanitize_text_field( $_POST['meal_type'] ?? 'lunch' );
    $desc      = sanitize_text_field( $_POST['description'] ?? '' );
    $photo_url = esc_url_raw( $_POST['photo_url'] ?? '' );
    $items     = json_decode( wp_unslash( $_POST['items_json'] ?? '[]' ), true );
    $date      = sanitize_text_field( $_POST['meal_date'] ?? '' ) ?: current_time( 'Y-m-d' );
    $time      = sanitize_text_field( $_POST['meal_time'] ?? '' ) ?: current_time( 'H:i:s' );

    // Calculate totals from items
    $totals = array( 'cal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'fib' => 0 );
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            $totals['cal']  += (float) ( $item['calories'] ?? 0 );
            $totals['pro']  += (float) ( $item['protein'] ?? 0 );
            $totals['carb'] += (float) ( $item['carbs'] ?? 0 );
            $totals['fat']  += (float) ( $item['fat'] ?? 0 );
            $totals['fib']  += (float) ( $item['fiber'] ?? 0 );
        }
    }

    // Allow manual override
    if ( ! empty( $_POST['total_calories'] ) ) $totals['cal'] = (float) $_POST['total_calories'];

    $wpdb->insert( $t['meals'], array(
        'user_id'        => $user_id,
        'meal_type'      => $meal_type,
        'meal_date'      => $date,
        'meal_time'      => $time,
        'description'    => $desc,
        'photo_url'      => $photo_url,
        'items_json'     => wp_json_encode( $items, JSON_UNESCAPED_UNICODE ),
        'total_calories' => $totals['cal'],
        'total_protein'  => $totals['pro'],
        'total_carbs'    => $totals['carb'],
        'total_fat'      => $totals['fat'],
        'total_fiber'    => $totals['fib'],
        'source'         => $photo_url ? 'photo' : 'manual',
        'platform'       => 'WEBCHAT',
    ) );

    $meal_id = $wpdb->insert_id;
    bzcalo_recalc_daily_stats( $user_id, $date );

    wp_send_json_success( array(
        'message' => 'Đã ghi bữa ăn!',
        'meal_id' => $meal_id,
        'totals'  => $totals,
    ) );
}

/* ================================================================
 * AJAX: Upload photo (WP Media) 
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_upload_photo', 'bzcalo_ajax_upload_photo' );
function bzcalo_ajax_upload_photo() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    if ( empty( $_FILES['photo'] ) ) {
        wp_send_json_error( array( 'message' => 'Không có ảnh.' ) );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attach_id = media_handle_upload( 'photo', 0 );
    if ( is_wp_error( $attach_id ) ) {
        wp_send_json_error( array( 'message' => $attach_id->get_error_message() ) );
    }

    wp_send_json_success( array(
        'attachment_id' => $attach_id,
        'url'           => wp_get_attachment_url( $attach_id ),
    ) );
}

/* ================================================================
 * AJAX: AI Analyze photo
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_ai_analyze_photo', 'bzcalo_ajax_ai_analyze_photo' );
function bzcalo_ajax_ai_analyze_photo() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $url = esc_url_raw( $_POST['photo_url'] ?? '' );
    if ( empty( $url ) ) wp_send_json_error( array( 'message' => 'Thiếu URL ảnh.' ) );

    $system = "Bạn là chuyên gia dinh dưỡng. Phân tích ảnh bữa ăn.\n"
            . "CHỈ trả về JSON:\n"
            . "{\"items\":[{\"name\":\"...\",\"serving\":\"...\",\"calories\":0,\"protein\":0,\"carbs\":0,\"fat\":0,\"fiber\":0}],"
            . "\"total_calories\":0,\"total_protein\":0,\"total_carbs\":0,\"total_fat\":0,\"total_fiber\":0,"
            . "\"description\":\"...\",\"health_note\":\"...\"}";

    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        wp_send_json_error( array( 'message' => 'AI API chưa sẵn sàng (OpenRouter chưa kích hoạt).' ) );
    }

    $ai = bizcity_openrouter_chat( array(
        array( 'role' => 'system', 'content' => $system ),
        array( 'role' => 'user', 'content' => array(
            array( 'type' => 'text', 'text' => 'Phân tích bữa ăn trong ảnh:' ),
            array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) ),
        ) ),
    ), array( 'model' => 'google/gemini-2.0-flash-001', 'purpose' => 'vision' ) );

    if ( ! $ai['success'] ) wp_send_json_error( array( 'message' => $ai['error'] ?: 'AI không phản hồi.' ) );

    // Parse JSON from AI
    $s = trim( $ai['message'] );
    $s = preg_replace( '/^```(?:json)?\s*/i', '', $s );
    $s = preg_replace( '/```\s*$/', '', $s );
    $data = json_decode( trim( $s ), true );

    if ( ! $data ) wp_send_json_error( array( 'message' => 'Không thể parse kết quả AI.', 'raw' => $result ) );

    wp_send_json_success( $data );
}

/* ================================================================
 * AJAX: AI Analyze text description
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_ai_analyze_text', 'bzcalo_ajax_ai_analyze_text' );
function bzcalo_ajax_ai_analyze_text() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $desc = sanitize_text_field( $_POST['description'] ?? '' );
    if ( empty( $desc ) ) wp_send_json_error( array( 'message' => 'Thiếu mô tả.' ) );

    $system = "Bạn là chuyên gia dinh dưỡng. Ước tính calo từ mô tả bữa ăn.\n"
            . "CHỈ trả về JSON:\n"
            . "{\"items\":[{\"name\":\"...\",\"serving\":\"...\",\"calories\":0,\"protein\":0,\"carbs\":0,\"fat\":0,\"fiber\":0}],"
            . "\"total_calories\":0,\"total_protein\":0,\"total_carbs\":0,\"total_fat\":0,\"total_fiber\":0,"
            . "\"description\":\"...\",\"health_note\":\"...\"}";

    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        wp_send_json_error( array( 'message' => 'AI API chưa sẵn sàng (OpenRouter chưa kích hoạt).' ) );
    }

    $ai = bizcity_openrouter_chat( array(
        array( 'role' => 'system', 'content' => $system ),
        array( 'role' => 'user',   'content' => "Phân tích bữa ăn: {$desc}" ),
    ), array( 'purpose' => 'chat' ) );

    if ( ! $ai['success'] ) wp_send_json_error( array( 'message' => $ai['error'] ?: 'AI không phản hồi.' ) );

    $s = trim( $ai['message'] );
    $s = preg_replace( '/^```(?:json)?\s*/i', '', $s );
    $s = preg_replace( '/```\s*$/', '', $s );
    $data = json_decode( trim( $s ), true );

    if ( ! $data ) wp_send_json_error( array( 'message' => 'Không thể parse kết quả AI.' ) );

    wp_send_json_success( $data );
}

/* ================================================================
 * AJAX: Get today stats
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_get_today', 'bzcalo_ajax_get_today' );
function bzcalo_ajax_get_today() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    global $wpdb;
    $t = bzcalo_tables();
    $date = sanitize_text_field( $_POST['date'] ?? '' ) ?: current_time( 'Y-m-d' );

    $profile = bzcalo_get_or_create_profile( $user_id );
    $stats   = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ), ARRAY_A );

    $meals = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['meals']} WHERE user_id = %d AND meal_date = %s ORDER BY meal_time ASC",
        $user_id, $date
    ), ARRAY_A );

    // Week stats for calendar strip (last 7 days)
    $week_stats = $wpdb->get_results( $wpdb->prepare(
        "SELECT stat_date, total_calories, meals_count
         FROM {$t['daily_stats']}
         WHERE user_id = %d AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         ORDER BY stat_date ASC",
        $user_id
    ), ARRAY_A );

    wp_send_json_success( array(
        'date'       => $date,
        'profile'    => $profile,
        'stats'      => $stats ?: array( 'total_calories' => 0, 'total_protein' => 0, 'total_carbs' => 0, 'total_fat' => 0, 'meals_count' => 0 ),
        'meals'      => $meals,
        'target'     => (int) ( $profile['daily_calo_target'] ?? 2000 ),
        'week_stats' => $week_stats,
    ) );
}

/* ================================================================
 * AJAX: Get weekly chart data
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_get_chart', 'bzcalo_ajax_get_chart' );
function bzcalo_ajax_get_chart() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    $days = (int) ( $_POST['days'] ?? 7 );
    if ( $days < 1 || $days > 90 ) $days = 7;

    global $wpdb;
    $t = bzcalo_tables();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT stat_date, total_calories, total_protein, total_carbs, total_fat, total_fiber, meals_count, water_ml, weight_kg
         FROM {$t['daily_stats']}
         WHERE user_id = %d AND stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
         ORDER BY stat_date ASC",
        $user_id, $days
    ), ARRAY_A );

    $profile = bzcalo_get_or_create_profile( $user_id );

    wp_send_json_success( array(
        'days'    => $days,
        'data'    => $rows,
        'target'  => (int) ( $profile['daily_calo_target'] ?? 2000 ),
    ) );
}

/* ================================================================
 * AJAX: Delete meal
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_delete_meal', 'bzcalo_ajax_delete_meal' );
function bzcalo_ajax_delete_meal() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    $meal_id = (int) ( $_POST['meal_id'] ?? 0 );
    if ( ! $meal_id ) wp_send_json_error( array( 'message' => 'Thiếu meal_id.' ) );

    global $wpdb;
    $t = bzcalo_tables();

    // Verify ownership
    $meal = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['meals']} WHERE id = %d AND user_id = %d",
        $meal_id, $user_id
    ), ARRAY_A );

    if ( ! $meal ) wp_send_json_error( array( 'message' => 'Không tìm thấy bữa ăn.' ) );

    $wpdb->delete( $t['meals'], array( 'id' => $meal_id ) );
    bzcalo_recalc_daily_stats( $user_id, $meal['meal_date'] );

    wp_send_json_success( array( 'message' => 'Đã xóa.' ) );
}

/* ================================================================
 * AJAX: Admin — get all users list
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_admin_users', 'bzcalo_ajax_admin_users' );
function bzcalo_ajax_admin_users() {
    check_ajax_referer( 'bzcalo_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Không có quyền.' ) );

    global $wpdb;
    $t = bzcalo_tables();

    $rows = $wpdb->get_results(
        "SELECT p.*, 
                (SELECT COUNT(*) FROM {$t['meals']} m WHERE m.user_id = p.user_id) as total_meals,
                (SELECT COALESCE(SUM(total_calories),0) FROM {$t['daily_stats']} d WHERE d.user_id = p.user_id AND d.stat_date = CURDATE()) as today_cal
         FROM {$t['profiles']} p
         ORDER BY p.updated_at DESC",
        ARRAY_A
    );

    wp_send_json_success( $rows );
}

/* ================================================================
 * AJAX: Search foods
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_search_foods',        'bzcalo_ajax_search_foods' );
add_action( 'wp_ajax_nopriv_bzcalo_search_foods', 'bzcalo_ajax_search_foods' );
function bzcalo_ajax_search_foods() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $q = sanitize_text_field( $_POST['q'] ?? '' );
    if ( mb_strlen( $q ) < 1 ) wp_send_json_success( array() );

    global $wpdb;
    $t    = bzcalo_tables();
    $like = '%' . $wpdb->esc_like( $q ) . '%';

    $foods = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t['foods']} WHERE name_vi LIKE %s OR name_en LIKE %s ORDER BY name_vi LIMIT 20",
        $like, $like
    ), ARRAY_A );

    wp_send_json_success( $foods );
}

/* ================================================================
 * AJAX: Log Weight
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_log_weight', 'bzcalo_ajax_log_weight' );
function bzcalo_ajax_log_weight() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    $weight = (float) ( $_POST['weight_kg'] ?? 0 );
    $date   = sanitize_text_field( $_POST['date'] ?? '' ) ?: current_time( 'Y-m-d' );
    $note   = sanitize_text_field( $_POST['note'] ?? '' );

    if ( $weight < 20 || $weight > 300 ) {
        wp_send_json_error( array( 'message' => 'Cân nặng không hợp lệ.' ) );
    }

    global $wpdb;
    $t = bzcalo_tables();

    // Upsert weight into daily_stats
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$t['daily_stats']} (user_id, stat_date, weight_kg, note)
         VALUES (%d, %s, %f, %s)
         ON DUPLICATE KEY UPDATE weight_kg = VALUES(weight_kg), note = COALESCE(VALUES(note), note), updated_at = NOW()",
        $user_id, $date, $weight, $note
    ) );

    // Update profile current weight
    $wpdb->update( $t['profiles'], array( 'weight_kg' => $weight, 'updated_at' => current_time( 'mysql' ) ), array( 'user_id' => $user_id ) );

    // Recalc daily calo target with new weight
    $profile = bzcalo_get_or_create_profile( $user_id );
    $new_target = bzcalo_calc_bmr( $profile );
    if ( $profile['goal'] === 'lose' ) $new_target -= 300;
    if ( $profile['goal'] === 'gain' ) $new_target += 300;
    $wpdb->update( $t['profiles'], array( 'daily_calo_target' => $new_target ), array( 'user_id' => $user_id ) );

    wp_send_json_success( array(
        'message'          => 'Đã lưu cân nặng!',
        'weight_kg'        => $weight,
        'daily_calo_target' => $new_target,
    ) );
}

/* ================================================================
 * AJAX: Get Weight History
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_get_weight_history', 'bzcalo_ajax_get_weight_history' );
function bzcalo_ajax_get_weight_history() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    $days = (int) ( $_POST['days'] ?? 30 );
    if ( $days < 1 || $days > 365 ) $days = 30;

    global $wpdb;
    $t = bzcalo_tables();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT stat_date, weight_kg, note
         FROM {$t['daily_stats']}
         WHERE user_id = %d AND weight_kg IS NOT NULL AND weight_kg > 0
           AND stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
         ORDER BY stat_date ASC",
        $user_id, $days
    ), ARRAY_A );

    wp_send_json_success( $rows );
}

/* ================================================================
 * AJAX: Delete Weight Entry
 * ================================================================ */
add_action( 'wp_ajax_bzcalo_delete_weight', 'bzcalo_ajax_delete_weight' );
function bzcalo_ajax_delete_weight() {
    check_ajax_referer( 'bzcalo_pub_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( array( 'message' => 'Chưa đăng nhập.' ) );

    $date = sanitize_text_field( $_POST['date'] ?? '' );
    if ( ! $date ) wp_send_json_error( array( 'message' => 'Thiếu ngày.' ) );

    global $wpdb;
    $t = bzcalo_tables();

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$t['daily_stats']} SET weight_kg = NULL WHERE user_id = %d AND stat_date = %s",
        $user_id, $date
    ) );

    wp_send_json_success( array( 'message' => 'Đã xóa.' ) );
}
