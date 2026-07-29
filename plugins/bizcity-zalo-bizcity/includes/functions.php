<?php

defined( 'ABSPATH' ) || exit;

// [2026-07-26 Johnny Chu] HOTFIX — this legacy file can be loaded alongside
// core/channel-gateway/legacy helpers during cutover; bail early if canonical
// functions are already present to prevent fatal "Cannot redeclare ...".
if ( function_exists( 'bizgpt_log_inbox_admin_msg' ) ) {
    return;
}

// ===============
// 4. Hook log inbox từ webhook của bạn
// ===============
function bizgpt_log_inbox_admin_msg($data = []) {
    global $globaldb;
    $tbl = 'global_inbox_admin';
    $conversation = $data['conversation'] ?? [];
    $comment = $data['comment'] ?? [];
    $message = $data['message'] ?? [];
    $message_attachments = $message['message_attachments'] ?? [];
    
    $row = [
        'client_id'      => $data['client_id']           ?? '',
        'client_name'    => $conversation['client_name']         ?? '',
        'platform_type'  => $data['platform_type']       ?? '',
        'page_id'        => $data['page_id']             ?? '',
		'blog_id'    	 => get_current_blog_id()         ?? '',
        'message_id'     => $message['message_id']       ?? '',
        'message_text'   => sanitize_text_field(
                                // Ưu tiên message_text, nếu không có lấy last_message hoặc comment_message
                                $message['message_text'] ??
                                ($conversation['last_message'] ?? ($comment['message'] ?? ''))
                            ),
        'message_type'   => $message['message_type']     ?? ($conversation['last_message_type'] ?? ''),
        'created_at'     => current_time('mysql'),
        'meta'           => json_encode($data)
    ];
    // Tránh các trường quá dài với varchar!
    $row['client_id']     = substr($row['client_id'], 0, 32);
    $row['client_name']   = substr($row['client_name'], 0, 255);
    $row['platform_type'] = substr($row['platform_type'], 0, 20);
    $row['page_id']       = substr($row['page_id'], 0, 40);
    $row['message_id']    = substr($row['message_id'], 0, 64);

    $globaldb->insert($tbl, $row);
}
// ===============
// 4. Hook log inbox từ webhook của bạn
// ===============
function bizgpt_log_inbox_msg($data = []) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'bizgpt_inbox';

    $conversation = $data['conversation'] ?? [];
    $comment = $data['comment'] ?? [];
    $message = $data['message'] ?? [];
    $message_attachments = $message['message_attachments'] ?? [];
    

    $message_id = $message['message_id'] ?? '';
    // Kiểm tra đã tồn tại message_id này chưa
    if (!empty($message_id)) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tbl WHERE message_id = %s", $message_id
        ));
        if ($exists) wp_die(); // Đã có rồi thì không insert nữa
    } 
    // Nếu không có message_id, kiểm tra trùng lặp bằng message_text (msg_url)
    $msg_url = $conversation['msg_url'] ?? '';
    if (!empty($msg_url)) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tbl WHERE message_text LIKE %s", '%' . $wpdb->esc_like($msg_url) . '%'
        ));
        if ($exists) wp_die(); // Đã có rồi thì không insert nữa
    }
       
    

    
    // Nếu không có msg_url thì lấy từ message_text hoặc last_message
    $message_text = sanitize_text_field(
        // Ưu tiên message_text, nếu không có lấy last_message hoặc comment_message
        $message['message_text'] ??
        ($conversation['last_message'] ?? ($comment['message'] ?? ''))
    );
    if(empty($message_text)) return; // Không có nội dung thì không lưu
    $row = [
        'client_id'      => $data['client_id']           ?? '',
        'client_name'    => $conversation['client_name']         ?? '',
        'platform_type'  => $data['platform_type']       ?? '',
        'page_id'        => $data['page_id']             ?? '',
		//'blog_id'    	 => get_current_blog_id()         ?? '',
        'message_id'     => $message['message_id']       ?? '',
        'message_text'   => $message_text,
        'message_type'   => $message['message_type']     ?? ($conversation['last_message_type'] ?? ''),
        'created_at'     => current_time('mysql'),
        'meta'           => json_encode($data)
    ];
    // Tránh các trường quá dài với varchar!
    $row['client_id']     = substr($row['client_id'], 0, 32);
    $row['client_name']   = substr($row['client_name'], 0, 255);
    $row['platform_type'] = substr($row['platform_type'], 0, 20);
    $row['page_id']       = substr($row['page_id'], 0, 40);
    $row['message_id']    = substr($row['message_id'], 0, 64);

    $wpdb->insert($tbl, $row);
}


// 3. Hàm check quyền client_id
function bizgpt_check_zalo_admin_permission($client_id, $blog_id = null) {
    global $globaldb;

    if (empty($client_id)) return false;

    $blog_id = $blog_id ?: get_current_blog_id();
    $cache_key = "bizgpt_zalo_admin_{$client_id}_{$blog_id}";
    $cache_group = 'bizgpt_admin_permission';

    // Lấy từ cache nếu có
   # $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached !== false) {
        return $cached;
    }

    // Truy vấn CSDL nếu chưa có cache
    $result = $globaldb->get_var($globaldb->prepare(
        "SELECT blog_id FROM global_user_admin WHERE client_id = %s ORDER BY updated_at DESC",
        $client_id
    ));
    #update_zalo_option($client_id,(int)$result); // lưu 12 giờ

    // Lưu vào cache (150 phút)
    #wp_cache_set($cache_key, $result, $cache_group, 150 * MINUTE_IN_SECONDS);

    return $result;
}
//✅ 2. Hàm gửi prompt chọn website quản trị nếu có nhiều
function bizgpt_prompt_select_site_to_admin($client_id) {
    $sites = bizgpt_get_admin_sites_by_client($client_id);

    if (empty($sites)) {
        return twf_telegram_send_message('zalo_' . $client_id, "Bạn chưa được cấp quyền quản trị website nào.");
    }

    if (count($sites) === 1) {
        return $sites[0]->blog_id;
    }

    $msg = "Bạn đang quản trị ".count($sites)." website. Vui lòng nhắn cho tôi tên miền để chọn:\n\n";
    foreach ($sites as $site) {
        $msg .= "- " . $site->domain . "\n";
    }
    $msg .= "\nVí dụ: Tôi muốn quản trị web `chaychualanh.com`";

    return twf_telegram_send_message('zalo_' . $client_id, $msg);
}
// 3. Hàm tìm blog_id theo domain người dùng nhắn

function bizgpt_find_blog_id_by_domain($client_id, $domain) {
    global $globaldb;
    return $globaldb->get_var($globaldb->prepare("
        SELECT blog_id FROM global_user_admin 
        WHERE client_id = %s AND domain LIKE %s
        LIMIT 1
    ", $client_id, '%' . $domain . '%'));
}
function bizgpt_get_admin_sites_by_client($client_id) {
    global $globaldb;
    $results = $globaldb->get_results($globaldb->prepare("
        SELECT blog_id, domain FROM global_user_admin 
        WHERE client_id = %s 
        ORDER BY updated_at DESC
    ", $client_id));
    return $results;
}
// Cập nhật mỗi khi thực hiện 1 yêu cầu:
function twf_update_client_login_time($client_id) {
    global $globaldb;

    if (empty($client_id)) return;

    $table = 'global_user_admin'; // Đổi tên nếu khác

    $globaldb->update(
        $table,
        [ 'updated_at' => current_time('mysql') ],
        [ 'client_id' => $client_id ]
    );
}

// 4. Dùng trong flow nếu cần chỉnh xác
#if (!bizgpt_check_zalo_admin_permission($client_id)) {
    #twf_telegram_send_message('zalo_' . $client_id, 'Bạn chưa xác nhận quyền quản trị trang này.');
   # return;
#}

// Sử dụng trong tele.php để check quyền
function bizgpt_generate_zalo_admin_login_url($client_id, $domain) {
    global $globaldb;

    if (empty($client_id) || empty($domain)) return false;

    // Chuẩn hoá domain (xoá http:// hoặc https://)
    $domain_clean = preg_replace('#^https?://(www\.)?#', '', rtrim($domain, '/'));

    // 1. Kiểm tra trong bảng global_user_admin
    $row = $globaldb->get_row($globaldb->prepare("
        SELECT blog_id, updated_at 
        FROM global_user_admin 
        WHERE client_id = %s AND REPLACE(domain, 'https://', '') = %s 
        ORDER BY updated_at DESC
        LIMIT 1
    ", $client_id, $domain_clean));
	back_trace('NOTICE', 'bizgpt_generate_zalo_admin_login_url: '.print_r($row,true));
    if ($row && !empty($row->blog_id)) {
        // 2. Nếu có rồi → lưu blog_id vào transient (giống session đăng nhập)
        update_zalo_option($client_id, (int)$row->blog_id); // lưu 12 giờ
        return (int)$row->blog_id;
    } else {
        // 3. Nếu chưa có → tạo link để đăng nhập xác thực
        return false;
    }
}

// Danh sách web theo client_id
function twf_list_sites_by_client_id($client_id) {
    global $globaldb;

    if (empty($client_id)) return "Không tìm thấy client_id.";

    $results = $globaldb->get_results($globaldb->prepare(
        "SELECT domain, blog_id FROM global_user_admin
         WHERE client_id = %s
         ORDER BY updated_at DESC",
        $client_id
    ));

    if (empty($results)) {
        return "Sếp chưa được cấp quyền quản trị bất kỳ website nào.";
    }

    $msg = "🧭 Sếp đang quản trị các website sau:\n\n";
    foreach ($results as $row) {
        $msg .= "🌐 {$row->domain}\n";
    }

    return $msg;
}
function biz_get_zalo_admin_id($blog_id, $force_refresh = false) {
    return twf_list_client_ids_by_blog_id($blog_id, $force_refresh);
}
// check web này có dùng zalo AI ko, dùng trong pending để gửi thông báo
function twf_list_client_ids_by_blog_id($blog_id, $force_refresh = false) {
    if (empty($blog_id)) $blog_id = get_current_blog_id();

    $cache_key   = "zalo_list_client_ids_blog_{$blog_id}";
    $cache_group = "zalo_list_client_ids_blog";
    #back_trace('NOTICE', 'twf_list_client_ids_by_blog_id: '.$blog_id);

    // 1. Kiểm tra cache trước
    if (!$force_refresh) {
        $cached = wp_cache_get($cache_key, $cache_group);
        if ($cached !== false && is_array($cached)) return $cached;

        $transient = get_transient($cache_key);
        if ($transient !== false && is_array($transient)) {
            wp_cache_set($cache_key, $transient, $cache_group, 10 * MINUTE_IN_SECONDS);
            return $transient;
        }
    }

    // 2. Truy vấn DB
    global $globaldb;
    $results = $globaldb->get_col($globaldb->prepare("
        SELECT DISTINCT client_id FROM global_user_admin
        WHERE blog_id = %d
        ORDER BY updated_at DESC
    ", $blog_id));

    // 3. Lưu cache
    $client_ids = is_array($results) ? $results : [];
    #back_trace('NOTICE', 'twf_list_client_ids_by_blog_id: '.print_r($client_ids, true));
    set_transient($cache_key, $client_ids, 12 * HOUR_IN_SECONDS);
    wp_cache_set($cache_key, $client_ids, $cache_group, 10 * MINUTE_IN_SECONDS);

    return $client_ids;
}
/**
 * Resolve WP user_id from any chat_id (Telegram, Zalo, or prefixed)
 *
 * Lookup order:
 *   1. global_user_admin table (Zalo admin mapping — most reliable)
 *   2. user_meta 'zalo_client_id_{blog_id}' (Zalo mapped via login)
 *   3. user_meta 'telegram_chat_id' (Telegram mapped via login)
 *
 * @param string|int $chat_id  Raw chat ID, may have 'zalo_' prefix
 * @return int  WP user_id or 0 if not found
 */
if (!function_exists('twf_get_user_id_by_chat_id')) {
    function twf_get_user_id_by_chat_id($chat_id) {
        global $wpdb, $globaldb;

        if (empty($chat_id)) return 0;

        $chat_id_clean = (string) $chat_id;

        // Strip 'zalo_' prefix if present
        $is_zalo = false;
        if (strpos($chat_id_clean, 'zalo_') === 0) {
            $chat_id_clean = substr($chat_id_clean, 5);
            $is_zalo = true;
        }

        $blog_id = get_current_blog_id();
        error_log(sprintf('[twf_get_user_id_by_chat_id] chat_id=%s, clean=%s, blog_id=%d, globaldb=%s',
            $chat_id, $chat_id_clean, $blog_id, ($globaldb ? 'OK' : 'NULL')));

        // ── Strategy 1: global_user_admin table (Zalo admin mapping — ưu tiên nhất) ──
        if ($globaldb) {
            $row = $globaldb->get_row($globaldb->prepare(
                "SELECT user_id, user_slave_id FROM global_user_admin WHERE client_id = %s AND blog_id = %d ORDER BY updated_at DESC LIMIT 1",
                $chat_id_clean, $blog_id
            ));
            error_log(sprintf('[twf_get_user_id_by_chat_id] Strategy 1 (global_user_admin + blog_id=%d): %s',
                $blog_id, ($row ? "user_id={$row->user_id}, user_slave_id={$row->user_slave_id}" : 'NOT FOUND')));

            if ($row) {
                $uid = !empty($row->user_slave_id) ? (int) $row->user_slave_id : (int) $row->user_id;
                if ($uid) return $uid;
            }

            // Fallback: query global_user_admin KHÔNG filter blog_id (cho trường hợp blog_id mismatch)
            if (!$row) {
                $row2 = $globaldb->get_row($globaldb->prepare(
                    "SELECT user_id, user_slave_id, blog_id FROM global_user_admin WHERE client_id = %s ORDER BY updated_at DESC LIMIT 1",
                    $chat_id_clean
                ));
                error_log(sprintf('[twf_get_user_id_by_chat_id] Strategy 1b (global_user_admin no blog filter): %s',
                    ($row2 ? "user_id={$row2->user_id}, blog_id={$row2->blog_id}" : 'NOT FOUND')));
                if ($row2) {
                    $uid = !empty($row2->user_slave_id) ? (int) $row2->user_slave_id : (int) $row2->user_id;
                    if ($uid) return $uid;
                }
            }
        } else {
            error_log('[twf_get_user_id_by_chat_id] ⚠️ $globaldb is not available!');
        }

        // ── Strategy 2: Zalo — lookup user_meta 'zalo_client_id_{blog_id}' ──
        $meta_key = 'zalo_client_id_' . $blog_id;
        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key, $chat_id_clean
        ));
        error_log(sprintf('[twf_get_user_id_by_chat_id] Strategy 2 (usermeta %s): %s', $meta_key, ($user_id ? $user_id : 'NOT FOUND')));
        if ($user_id) return $user_id;

        // ── Strategy 3: Telegram — lookup user_meta 'telegram_chat_id' ──
        if (!$is_zalo) {
            $user_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'telegram_chat_id' AND meta_value = %s LIMIT 1",
                $chat_id_clean
            ));
            if ($user_id) return $user_id;
        }

        return 0;
    }
}

// check client này này có dùng zalo AI ko, force trong function gửi tin nhắn tele
function twf_check_client_use_zalo($chat_id, $force_refresh = false) {
   # if (strpos($chat_id, 'zalo_') !== 0) return false;

    $client_id = str_replace('zalo_', '', $chat_id);
    $blog_id = get_current_blog_id();
    $cache_key = "zalo_chatid_{$client_id}_{$blog_id}";
    $cache_group = 'zalo_chat_check';

    // 1. Nếu không ép refresh → kiểm tra từ cache (RAM hoặc Transient)
    if (!$force_refresh) {
        $cached = wp_cache_get($cache_key, $cache_group);
        if ($cached !== false) return $cached;

        $transient = get_transient($cache_key);
        if ($transient !== false) {
            wp_cache_set($cache_key, $transient, $cache_group, 10 * MINUTE_IN_SECONDS);
            return $transient;
        }
    }

    // 2. Truy vấn database
    global $globaldb;
    $exists = $globaldb->get_var($globaldb->prepare("
        SELECT id FROM global_user_admin
        WHERE blog_id = %d AND client_id = %s
        LIMIT 1
    ", $blog_id, $client_id));

    // 3. Lưu cache lại
    $result = $exists ? $client_id : false;
    wp_cache_set($cache_key, $result, $cache_group, 10 * MINUTE_IN_SECONDS);
    set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);

    return $result;
}



// Tìm sản phẩm qua WP_Query (dựa title)
function fbm_search_product($keyword) {
    $args = [
        'post_type' => 'product',
        's' => $keyword,
        'posts_per_page' => 3,
    ];
    return new WP_Query($args);
}



// Example usage
if(!function_exists('sanitize_text_with_line_breaks')):
	function sanitize_text_with_line_breaks($input) {
		// Thay thế <br> hoặc <br /> bằng \n
		$input = convertAnchorTags($input);
		$input = cleanAndFormatText($input);
		$input = str_replace('"', '', $input);
		$input = str_replace(PHP_EOL, '\n', $input);
		
		// Loại bỏ tất cả các thẻ HTML khác
		
			
		#$sanitized_text = strip_tags($input_with_line_breaks);
		
		return $input;
	}
endif;
function has_image_extension($url) {
    return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url);
}



function send_chatbot_chatgpt_upload_files($file_url='', $api_key) {
    global $session_id, $user_id, $chatbot_chatgpt_plugin_dir_path, $chatbot_chatgpt_fixed_literal_messages;

    $uploads_dir = $chatbot_chatgpt_plugin_dir_path . 'uploads/';
    if (!file_exists($uploads_dir) && !wp_mkdir_p($uploads_dir)) {
        $error_message = !empty($chatbot_chatgpt_fixed_literal_messages[2])
            ? $chatbot_chatgpt_fixed_literal_messages[2] 
            : 'Oops! File upload failed.';
        http_response_code(500);
        return ['status' => 'error', 'message' => $error_message];
    }

    $responses = [];
    
    if (!empty($file_url)) {
        $file_url = esc_url_raw($file_url);
        $file_data = file_get_contents($file_url);

        if ($file_data === false) {
            return ['status' => 'error', 'message' => 'Unable to retrieve file from URL.'];
        }

        $file_extension = pathinfo(parse_url($file_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        $newFileName = generate_random_string() . '.' . $file_extension;
        $file_path = $uploads_dir . $newFileName;

        if (!file_put_contents($file_path, $file_data)) {
            return ['status' => 'error', 'message' => 'Error saving file from URL.'];
        }

        $file_mime_type = mime_content_type($file_path);
        $purpose = (strpos($file_mime_type, 'image/') === 0) ? 'vision' : 'assistants';

        // Upload code as per your existing logic
        $api_url = get_files_api_url();
		
         $api_key = chatbot_chatgpt_decrypt_api_key($api_key);
        
        $filename = basename($file_path);
        $boundary = wp_generate_password(24);

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
        $body .= "{$purpose}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$file_mime_type}\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $args = [
            'method'    => 'POST',
            'headers'   => [
                'Authorization'  => 'Bearer ' . trim($api_key),
                'Content-Type'   => 'multipart/form-data; boundary=' . $boundary
            ],
            'body'      => $body,
            'timeout'   => 30,
        ];

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            unlink($file_path);
            return ['status' => 'error', 'message' => 'API Error: ' . $response->get_error_message()];
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $responseData = json_decode($response_body, true);
		#print_r($responseData);
        if ($http_status != 200 || isset($responseData['error'])) {
            $errorMessage = $responseData['error']['message'] ?? 'Unknown error occurred.';
            unlink($file_path);
            return ['status' => 'error', 'message' => $errorMessage];
        }

        $responses[] = [
            'status' => 'success',
            'id' => $responseData['id'],
            'message' => 'File uploaded successfully from URL.'
        ];

        unlink($file_path);
    }
	return $responseData;
    if (isset($_FILES['file']['name']) && is_array($_FILES['file']['name'])) {
        // Existing file upload logic
    } else {
        $default_message = 'Oops! Please select a file to upload.';
        $error_message = isset($chatbot_chatgpt_fixed_literal_messages[5]) 
            ? $chatbot_chatgpt_fixed_literal_messages[5] 
            : $default_message;
        $responses[] = ['status' => 'error', 'message' => $error_message];
    }

    return $responses;
}

/**
 * OAuth SSO: Lưu client_id (từ zid) vào global_user_admin sau khi đăng nhập thành công
 * Hook vào wpoc_user_created và wpoc_user_login để xử lý cả user mới và user cũ
 */
function bizcity_save_oauth_zalo_client_mapping( $user_info, $user_id ) {
    $zid = '';
    
    // Method 1: Kiểm tra redirect_to từ cookie để lấy zid
    $redirect_url = '';
    
    if ( isset( $_COOKIE['wposso_redirect_to'] ) && ! empty( $_COOKIE['wposso_redirect_to'] ) ) {
        $redirect_url = sanitize_text_field( $_COOKIE['wposso_redirect_to'] );
    }
    
    // Nếu không có cookie, thử lấy từ $_GET redirect_to
    if ( empty( $redirect_url ) && isset( $_GET['redirect_to'] ) ) {
        $redirect_url = sanitize_text_field( $_GET['redirect_to'] );
    }
    
    // Parse URL để lấy zid parameter
    if ( ! empty( $redirect_url ) ) {
        $parsed = wp_parse_url( $redirect_url );
        if ( ! empty( $parsed['query'] ) ) {
            parse_str( $parsed['query'], $params );
            $zid = $params['zid'] ?? '';
        }
    }
    
    // Method 2: Fallback - Check transient (set by shortcode before OAuth redirect)
    if ( empty( $zid ) ) {
        $transient_key = 'zalo_login_zid_' . md5( $_SERVER['REMOTE_ADDR'] . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        $transient_zid = get_transient( $transient_key );
        if ( $transient_zid ) {
            $zid = $transient_zid;
            delete_transient( $transient_key );
            error_log( '[OAuth Zalo] Got zid from transient: ' . $zid );
        }
    }
    
    if ( empty( $zid ) ) {
        error_log( '[OAuth Zalo] No zid found in cookie, GET, or transient' );
        return;
    }
    
    // Decrypt zid để lấy client_id
    if ( ! function_exists( 'twf_decrypt_chat_id' ) ) {
        return;
    }
    
    $client_id = twf_decrypt_chat_id( $zid, 'vietqr' );
    if ( ! $client_id ) {
        error_log( '[OAuth Zalo] Failed to decrypt zid: ' . $zid );
        return;
    }
    
    // Lưu vào global_user_admin
    global $globaldb;
    if ( ! isset( $globaldb ) || ! $globaldb ) {
        error_log( '[OAuth Zalo] globaldb not available' );
        return;
    }
    
    $blog_id = get_current_blog_id();
    $domain = get_home_url();
    
    // Check if record already exists
    $existing = $globaldb->get_row( $globaldb->prepare(
        "SELECT id FROM global_user_admin WHERE blog_id = %d AND client_id = %s",
        $blog_id, $client_id
    ) );
    
    if ( $existing ) {
        // Update existing record
        $globaldb->update(
            'global_user_admin',
            [
                'user_id'      => $user_id,
                'user_slave_id' => $user_id,
                'domain'       => $domain,
                'updated_at'   => current_time( 'mysql' )
            ],
            [ 'id' => $existing->id ]
        );
        error_log( '[OAuth Zalo] Updated global_user_admin: blog_id=' . $blog_id . ', client_id=' . $client_id . ', user_id=' . $user_id );
    } else {
        // Insert new record
        $globaldb->insert( 'global_user_admin', [
            'blog_id'      => $blog_id,
            'client_id'    => $client_id,
            'user_id'      => $user_id,
            'user_slave_id' => $user_id,
            'domain'       => $domain,
            'user_level'   => 'administrator',
            'created_at'   => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' )
        ] );
        error_log( '[OAuth Zalo] Inserted global_user_admin: blog_id=' . $blog_id . ', client_id=' . $client_id . ', user_id=' . $user_id );
    }
    
    // Also update user meta
    update_user_meta( $user_id, 'zalo_client_id_' . $blog_id, $client_id );
}

// Hook vào cả user_created và user_login của OAuth client
add_action( 'wpoc_user_created', 'bizcity_save_oauth_zalo_client_mapping', 10, 2 );
add_action( 'wpoc_user_login', 'bizcity_save_oauth_zalo_client_mapping', 10, 2 );
