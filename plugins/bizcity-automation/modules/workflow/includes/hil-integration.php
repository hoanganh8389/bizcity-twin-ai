<?php
/**
 * Example: Integrate HIL với Zalo Webhook
 * 
 * Khi user gửi tin nhắn vào Zalo, webhook sẽ gọi hàm này
 * để update HIL state và resume workflow
 */

// Hook vào Zalo message received
add_action('zalo_message_received', 'waic_hil_handle_zalo_message', 10, 2);

function waic_hil_handle_zalo_message($chat_id, $message_text) {
    error_log('[HIL] Zalo message from ' . $chat_id . ': ' . $message_text);
    
    // Check xem có HIL state đang chờ không
    $hil_state = waic_hil_get_state($chat_id);
    
    if ($hil_state !== false && $hil_state['status'] === 'waiting') {
        error_log('[HIL] Found waiting state, processing response...');
        
        // Update HIL state based on user response
        $updated = waic_hil_update_response($chat_id, $message_text);
        
        if ($updated) {
            // Xác định confirmed hay rejected
            $new_state = waic_hil_get_state($chat_id);
            
            if ($new_state['status'] === 'confirmed') {
                // Send success message
                if (function_exists('biz_send_message')) {
                    biz_send_message($chat_id, "✅ Đã xác nhận! Workflow sẽ tiếp tục...");
                }
            } elseif ($new_state['status'] === 'rejected') {
                // Send rejection message
                if (function_exists('biz_send_message')) {
                    biz_send_message($chat_id, "❌ Đã huỷ! Workflow sẽ dừng lại.");
                }
            }
        }
    }
}

/**
 * Example sử dụng trong chatbot handler
 */
function example_chatbot_integration() {
    // Trong file xử lý webhook Zalo của bạn:
    
    /*
    $chat_id = $webhook_data['sender_id'];
    $message = $webhook_data['message'];
    
    // Trigger action để HIL có thể handle
    do_action('zalo_message_received', $chat_id, $message);
    
    // Hoặc gọi trực tiếp:
    if (function_exists('waic_hil_update_response')) {
        waic_hil_update_response($chat_id, $message);
    }
    */
}

/**
 * REST API endpoint để test HIL (optional)
 */
add_action('rest_api_init', function() {
    register_rest_route('waic/v1', '/hil/respond', array(
        'methods' => 'POST',
        'callback' => 'waic_hil_api_respond',
        'permission_callback' => '__return_true',
    ));
});

function waic_hil_api_respond($request) {
    $chat_id = $request->get_param('chat_id');
    $response = $request->get_param('response');
    
    if (empty($chat_id) || empty($response)) {
        return new WP_Error('missing_params', 'chat_id and response are required', array('status' => 400));
    }
    
    $updated = waic_hil_update_response($chat_id, $response);
    
    if ($updated) {
        return array(
            'success' => true,
            'message' => 'HIL response recorded',
            'state' => waic_hil_get_state($chat_id),
        );
    }
    
    return new WP_Error('update_failed', 'Failed to update HIL state', array('status' => 500));
}
