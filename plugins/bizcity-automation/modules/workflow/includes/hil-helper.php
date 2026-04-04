<?php
if (!defined('ABSPATH')) exit;

/**
 * Human-in-the-Loop (HIL) Helper Functions
 * Để xử lý user confirmation trong workflow
 */

/**
 * Update HIL state khi user trả lời
 * 
 * @param string $chat_id Chat ID của user
 * @param string $user_response Text response từ user
 * @param int $blog_id Blog ID (multisite)
 * @return bool True nếu update thành công
 */
function waic_hil_update_response($chat_id, $user_response, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $hil_state_key = 'waic_hil_' . $blog_id . '_' . $chat_id;
    $hil_state = get_transient($hil_state_key);
    
    if ($hil_state === false || $hil_state['status'] !== 'waiting') {
        error_log('[HIL] No waiting state found for chat_id: ' . $chat_id);
        return false;
    }
    
    // Normalize response để so sánh
    $response_lower = mb_strtolower(trim($user_response), 'UTF-8');
    
    // ── Slot-fill HIL (from it_call_tool Phase 1.1) ──
    // For slot-fill type, we don't do YES/NO keyword matching.
    // Instead, check for skip/cancel keywords and store raw response.
    if ( isset( $hil_state['type'] ) && $hil_state['type'] === 'slot_fill' ) {
        $skip_keywords = array( 'bỏ qua', 'skip', 'cancel', 'huỷ', 'hủy', 'thôi', 'dừng', 'từ chối' );
        $is_skip = false;
        foreach ( $skip_keywords as $keyword ) {
            if ( strpos( $response_lower, $keyword ) !== false ) {
                $is_skip = true;
                break;
            }
        }

        $hil_state['status']       = $is_skip ? 'rejected' : 'responded';
        $hil_state['response']     = $user_response;
        $hil_state['responded_at'] = WaicUtils::getTimestamp();

        set_transient( $hil_state_key, $hil_state, 300 );
        error_log( '[HIL] Slot-fill response for chat_id ' . $chat_id . ': ' . ( $is_skip ? 'SKIP' : 'RESPONDED' ) );

        // Trigger resume — action block uses 'output-right' (single output, not branching)
        $is_confirmed   = ! $is_skip;
        $sourceHandle   = 'output-right'; // Action blocks always use output-right
        goto trigger_resume;
    }

    // Danh sách từ khóa YES
    $yes_keywords = array('yes', 'có', 'đúng', 'ok', 'được', 'oke', 'đồng ý', 'confirm', 'xác nhận');
    
    // Danh sách từ khóa NO
    $no_keywords = array('no', 'không', 'sai', 'thôi', 'cancel', 'huỷ', 'hủy', 'từ chối');
    
    $is_confirmed = false;
    
    // Check YES keywords
    foreach ($yes_keywords as $keyword) {
        if (strpos($response_lower, $keyword) !== false) {
            $is_confirmed = true;
            break;
        }
    }
    
    // Check NO keywords (ưu tiên cao hơn YES nếu có cả 2)
    foreach ($no_keywords as $keyword) {
        if (strpos($response_lower, $keyword) !== false) {
            $is_confirmed = false;
            break;
        }
    }
    
    // Update HIL state
    $hil_state['status'] = $is_confirmed ? 'confirmed' : 'rejected';
    $hil_state['response'] = $user_response;
    $hil_state['responded_at'] = WaicUtils::getTimestamp();
    
    set_transient($hil_state_key, $hil_state, 300); // 5 phút cho workflow kịp resume
    
    error_log('[HIL] Updated state for chat_id ' . $chat_id . ': ' . ($is_confirmed ? 'CONFIRMED' : 'REJECTED'));
    
    // Default sourceHandle for confirm/reject (logic blocks)
    $sourceHandle = $is_confirmed ? 'output-then' : 'output-else';

    // ⭐ Trigger workflow resume NGAY LẬP TỨC
    trigger_resume:
    if (!empty($hil_state['run_id'])) {
        $run_id = $hil_state['run_id'];
        error_log('[HIL] Triggering immediate resume for run_id: ' . $run_id);
        
        // Check if this is test mode or production mode
        $exec_state = get_transient($run_id);
        
        if ($exec_state !== false && isset($exec_state['mode']) && $exec_state['mode'] === 'test') {
            // ===== TEST MODE (AJAX realtime) =====
            error_log('[HIL] Test mode detected, using execute API');
            
            // ⭐ Update last_sourceHandle và node_status vào execution state
            // $sourceHandle is set above: 'output-right' for slot-fill, 'output-then'/'output-else' for confirm
            $exec_state['last_sourceHandle'] = $sourceHandle;
            
            // Update confirm node status to success
            if (!empty($hil_state['node_id'])) {
                $confirm_node_id = $hil_state['node_id'];
                if (!isset($exec_state['node_status'])) {
                    $exec_state['node_status'] = [];
                }
                $exec_state['node_status'][$confirm_node_id] = 'success';
                error_log('[HIL] Updated node status for ' . $confirm_node_id . ' to success');
            }
            
            set_transient($run_id, $exec_state, 3600);
            error_log('[HIL] Updated test execution state with sourceHandle: ' . $sourceHandle);
            
            // Set flag để poll handler biết cần check ngay
            $resume_key = 'waic_hil_resume_' . $run_id;
            set_transient($resume_key, time(), 60);
            
            // Gọi trực tiếp resume function
            if (function_exists('waic_execute_workflow_resume')) {
                waic_execute_workflow_resume($run_id);
            }
            
        } else {
            // ===== PRODUCTION MODE (cron-based) =====
            error_log('[HIL] Production mode detected, updating flowrun DB');
            
            global $wpdb;
            $run_id_int = (int) $run_id;
            
            // Get current run from DB
            $tbl_runs = $wpdb->prefix . WAIC_DB_PREF . 'flowruns';
            $run = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tbl_runs} WHERE id = %d",
                $run_id_int
            ), ARRAY_A);
            
            if ($run && (int)$run['status'] === 1 && (int)$run['waiting'] > 0) {
                error_log('[HIL] Found waiting production run, clearing waiting flag');
                
                // ⭐ CRITICAL: Lưu sourceHandle vào flowrun để doFlowRun() biết nhánh nào
                // $sourceHandle is set above: 'output-right' for slot-fill, 'output-then'/'output-else' for confirm
                
                // Get current flowdata
                $flowdata = json_decode($run['flowdata'], true);
                if (!is_array($flowdata)) {
                    $flowdata = [];
                }
                
                // Save HIL decision to flowdata
                $flowdata['hil_sourceHandle'] = $sourceHandle;
                $flowdata['hil_confirmed'] = $is_confirmed;
                
                // Update DB: clear waiting + save flowdata
                $wpdb->update(
                    $tbl_runs,
                    [
                        'waiting' => 0, // Clear waiting để cron pick up
                        'flowdata' => json_encode($flowdata),
                    ],
                    ['id' => $run_id_int],
                    ['%d', '%s'],
                    ['%d']
                );
                
                error_log('[HIL] Cleared waiting flag and saved sourceHandle: ' . $sourceHandle);
                
                // ⚠️ KHÔNG clear HIL state ngay - để workflow đọc status confirmed/rejected
                // HIL state sẽ được un_confirm.php clear sau khi đọc
                // delete_transient($hil_state_key); // REMOVED
                
                // Trigger cron to process immediately
                if (function_exists('spawn_cron')) {
                    spawn_cron();
                    error_log('[HIL] Triggered spawn_cron()');
                }
            } else {
                error_log('[HIL] Production run not found or not waiting: ' . ($run ? json_encode($run) : 'NULL'));
            }
        }
    }
    
    return true;
}

/**
 * Lưu run_id vào HIL state để biết workflow nào đang chờ
 * (Gọi từ un_confirm.php khi tạo waiting state)
 * 
 * @param string $chat_id Chat ID
 * @param int $run_id Workflow run ID
 * @param int $blog_id Blog ID
 */
function waic_hil_set_run_id($chat_id, $run_id, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $hil_state_key = 'waic_hil_' . $blog_id . '_' . $chat_id;
    $hil_state = get_transient($hil_state_key);
    
    if ($hil_state !== false) {
        $hil_state['run_id'] = $run_id;
        $ttl = max(60, $hil_state['timeout_at'] - WaicUtils::getTimestamp() + 300);
        set_transient($hil_state_key, $hil_state, $ttl);
        error_log('[HIL] Set run_id ' . $run_id . ' for chat_id ' . $chat_id);
    }
}

/**
 * Get HIL state (để debug)
 */
function waic_hil_get_state($chat_id, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $hil_state_key = 'waic_hil_' . $blog_id . '_' . $chat_id;
    return get_transient($hil_state_key);
}

/**
 * Clear HIL state (để cleanup)
 */
function waic_hil_clear_state($chat_id, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $hil_state_key = 'waic_hil_' . $blog_id . '_' . $chat_id;
    delete_transient($hil_state_key);
    error_log('[HIL] Cleared state for chat_id ' . $chat_id);
}
