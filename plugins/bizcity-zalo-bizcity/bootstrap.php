<?php
/**
 * BizCity Admin Hook Zalo — Standalone Bootstrap
 *
 * Muc dich: chay trong twin-ai bundled plugin de nhan Zalo webhook /bizhook/
 * va thong bao cho chu site. Standalone hoan toan — khong require cac file
 * shortcode_login / functions / gateway-functions vi cac function do da
 * duoc tich hop vao core/channel-gateway/legacy/ cua bizcity-twin-ai.
 *
 * [2026-07-26 Johnny Chu] R-GW-8 — sync bundled bootstrap with mu-plugin-safe
 * standalone mode to avoid function redeclare when legacy helpers are already
 * loaded by core/channel-gateway.
 */
defined( 'ABSPATH' ) or die( 'OOPS...' );

// [2026-08-14 Johnny Chu] R-CH-UNI — the MU and bundled cutover copies must
// share one runtime owner when both files are present during deployment.
if ( defined( 'BIZCITY_ADMIN_HOOK_ZALO_BOOTSTRAPPED' ) ) {
    return;
}
define( 'BIZCITY_ADMIN_HOOK_ZALO_BOOTSTRAPPED', true );

if ( ! defined( 'BIZCITY_ADMIN_ZALO_DIR' ) ) {
    define( 'BIZCITY_ADMIN_ZALO_DIR', __DIR__ . '/includes/' );
}

// [2026-08-13 Johnny Chu] HOTFIX-ZALOBOT-COMMAND-ROUTER-BOOT — this legacy-compatible bundled adapter still bridges Zalo Bot into the canonical channel bus, so it must load the canonical linker and /link consumer when the standalone Zalo Bot entrypoint is not the active loader.
$_bizcity_zalobot_user_linker = dirname( __DIR__ ) . '/bizcity-zalo-bot/includes/class-user-linker.php';
if ( ! class_exists( 'BizCity_Zalobot_User_Linker', false ) && file_exists( $_bizcity_zalobot_user_linker ) ) {
    require_once $_bizcity_zalobot_user_linker;
}
unset( $_bizcity_zalobot_user_linker );
$_bizcity_zalobot_command_router = dirname( __DIR__ ) . '/bizcity-zalo-bot/includes/class-command-router.php';
if ( ! class_exists( 'BizCity_Zalobot_Command_Router', false ) && file_exists( $_bizcity_zalobot_command_router ) ) {
    require_once $_bizcity_zalobot_command_router;
}
unset( $_bizcity_zalobot_command_router );
add_action( 'plugins_loaded', static function () {
    if ( class_exists( 'BizCity_Zalobot_Command_Router' )
        && false === has_action( 'bizcity_channel_normalized', array( 'BizCity_Zalobot_Command_Router', 'handle_normalized' ) ) ) {
        BizCity_Zalobot_Command_Router::boot();
    }
}, 1 );
// Buffer raw input cực sớm (chỉ 1 lần)
if (!isset($GLOBALS['BIZCITY_RAW_INPUT'])) {
    $GLOBALS['BIZCITY_RAW_INPUT'] = file_get_contents('php://input');
}

if (!function_exists('bizcity_get_raw_input')) {
    function bizcity_get_raw_input(): string {
        return (string)($GLOBALS['BIZCITY_RAW_INPUT'] ?? '');
    }
}

/**
 * Rewrite: /bizhook/
 */
function bizgpt_register_webhook_rewrite() {
    add_rewrite_tag('%bizhook%', '([0-1])');

    // ✅ đúng endpoint hiện tại
    add_rewrite_rule('^bizhook/?$', 'index.php?bizhook=1', 'top');

    // ✅ alias cho trường hợp anh gõ nhầm
    add_rewrite_rule('^bizhook/?$', 'index.php?bizhook=1', 'top');
}

add_action('init', 'bizgpt_register_webhook_rewrite', 1);

add_filter('query_vars', function($vars){
    $vars[] = 'bizhook';
    return $vars;
});

/**
 * Activate: flush rewrite + create tables
 */
register_activation_hook(__FILE__, function () {
    bizgpt_register_webhook_rewrite();
    flush_rewrite_rules();
    bizgpt_create_global_user_admin_table();
    bizgpt_create_global_inbox_admin_table();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/**
 * Tables
 */
function bizgpt_create_global_user_admin_table() {
    global $wpdb;
    $table = $wpdb->base_prefix . 'global_user_admin';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        blog_id BIGINT UNSIGNED NOT NULL,
        client_id VARCHAR(50) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        user_slave_id BIGINT UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        user_level VARCHAR(50) DEFAULT 'editor',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_admin (blog_id, client_id, user_id)
    ) {$charset_collate};";

    $wpdb->query($sql);
}

function bizgpt_create_global_inbox_admin_table() {
    global $wpdb;
    $tbl = $wpdb->base_prefix . 'global_inbox_admin';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$tbl} (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(32),
        client_name VARCHAR(255),
        platform_type VARCHAR(20),
        page_id VARCHAR(40),
        message_id VARCHAR(64),
        message_text TEXT,
        message_type VARCHAR(10),
        created_at DATETIME,
        blog_id INT DEFAULT 0,
        flow_id INT DEFAULT 0,
        reminded_at DATETIME NULL DEFAULT NULL,
        reminder_msg_id INT DEFAULT 0,
        meta LONGTEXT
    ) {$charset} ENGINE=InnoDB;";

    $wpdb->query($sql);
}

/**
 * Webhook Handler: /bizhook/
 */
add_action('template_redirect', function () {
    if ((int) get_query_var('bizhook') !== 1) return;

    // Chỉ nhận POST để rõ ràng
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $log = WP_CONTENT_DIR . '/mu-plugins/logs/bizhook-raw.log';
    if (!file_exists(dirname($log))) {
        wp_mkdir_p(dirname($log));
    }

    // ✅ ĐỌC RAW TỪ BUFFER (không đọc php://input ở đây nữa)
    $raw = function_exists('bizcity_get_raw_input') ? bizcity_get_raw_input() : '';

    // log đủ thông tin để debug
    $meta = [
        'time'   => gmdate('c'),
        'uri'    => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ctype'  => $_SERVER['CONTENT_TYPE'] ?? '',
        'len'    => strlen($raw),
    ];

    file_put_contents($log, "==".json_encode($meta, JSON_UNESCAPED_SLASHES). "==\n" . $raw . "\n\n", FILE_APPEND);

    $data = json_decode($raw, true);

    status_header(200);
    header('Content-Type: application/json; charset=utf-8');

    if (!is_array($data)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid JSON',
            'json_error' => json_last_error_msg(),
            'raw_len' => strlen($raw),
        ]);
        exit;
    }

    // ✅ Log vào bizcity_zalo_bot_logs với bot_id = 9999
    #back_trace('NOTICE', ''.'Logging webhook to bizcity_zalo_bot_logs (bot_id=9999)');
    
    #back_trace('SUCCESS', 'Webhook logged to bizcity_zalo_bot_logs with ID ' . $wpdb->insert_id);

    // ✅ gọi handler chính của anh
    bizgpt_webhook_webchat_handler($data);

    echo json_encode(['ok' => true]);
    exit;

}, 0);


//xác định User hoặc Guest (session):
// Trước dùng để log event trong bizgpt event
if(!function_exists('bizgpt_get_webchat_identity')) {
    function bizgpt_get_webchat_identity() {
        if(!session_id()) :
            add_action('init', function() {
                if (session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
            }, 1);
        
        endif;
        if (is_user_logged_in()) {
            return [
                'user_id'    => get_current_user_id(),
                'session_id' => session_id()
            ];
        } else {
        
            return [
                'user_id'    => null,
                'session_id' => session_id()
            ];
        }
    }
}
if(!function_exists('bizgpt_get_client_id_from_transient')) {
    function bizgpt_get_client_id_from_transient($blog_id) {
        $client_id = get_transient('zalo_client_id_by_blog_id_' . $blog_id);
        return $client_id ? (string)$client_id : '';
    }
}

//Handler cho Facebook Messenger
function bizgpt_webhook_webchat_handler($data){
    $api_key             = get_option('twf_openai_api_key');
	$platform_type       = $data['platform_type']  ?? '';
	$event               = $data['event']          ?? '';
	$page_id             = $data['page_id']        ?? '';
	$client_id           = $data['client_id']      ?? '';
	$client_name         = $data['client_name']    ?? '';
	$conversation        = $data['conversation']   ?? [];
	$last_message        = sanitize_text_field($conversation['last_message'] ?? '');
	$message             = $data['message']        ?? [];
	$message_type        = $conversation['last_message_type'] ?? '';
	$message_attachments = $message['message_attachments'] ?? [];
	$message_attachments_url = $message_attachments[0]['payload']['url'] ?? '';
	$comment             = $data['comment']        ?? [];
	$comment_id          = $comment['comment_id']  ?? '';
	$comment_message     = sanitize_text_field($comment['message'] ?? '');
	
	$message_id    = $message['message_id'] ?? '';
	// Default/fallback values
	$user_id    = $client_id;
	$session_id = $client_id;
	$action     = $_REQUEST['action'] ?? '';
	$mid        = sanitize_text_field($_REQUEST['mid'] ?? '');
	//
	
	// Lọc sớm tránh xử lý lặp
    if ($event !== 'message.create') return false;
    if ($message_type !== 'client') return false;
    if (empty($message_id)) return false;

    $lock_key = 'bizgpt_msg_lock_' . $message_id;
    if (get_transient($lock_key)) return;
    set_transient($lock_key, true, 120); // Lock 60s

    
    // Setup context cho AI
    $client_context = $client_name ? "Xin chào, tôi tên là: $client_name. Tôi muốn hỏi bạn vài điều. " : '';
	
	// Chuyển giọng nói sang text
	// Zalo hoặc Facebook voice message xử lý
	// Phân tích voice từ message_attachments (ZALO/FACEBOOK)
	
    $response = [];

   
	
	switch ($platform_type):
		
		
		case 'ZALO_PERSONAL':
			// log vào globaldb các hoạt động quản trị.
			bizgpt_log_inbox_admin_msg($data);
			if ( $last_message && $message_type === 'client' ) {
				bizgpt_process_unified_message([
					'platform'       => 'ZALO_PERSONAL',
					'client_id'      => (string)$client_id,
					'message_text'   => (string)$last_message,
					'message_id'     => (string)$message_id,
					'attachment_url' => (string)$message_attachments_url,
					'display_name'   => (string)$client_name,
					'raw_data'       => is_array($data) ? $data : [],
					'fire_waic'      => true,
				]);
			}
		break;

		case 'ADMINCHAT':
			// Admin chat qua bizhook: user đã đăng nhập → get_current_blog_id() / get_current_user_id()
			// Quy ước: client_id mang session_id của webchat session
			if ( $last_message && $message_type === 'client' ) {
				bizgpt_process_unified_message([
					'platform'       => 'ADMINCHAT',
					'session_id'     => (string)$client_id,
					'client_id'      => (string)$client_id,
					'message_text'   => (string)$last_message,
					'message_id'     => (string)$message_id,
					'attachment_url' => (string)$message_attachments_url,
					'display_name'   => (string)$client_name,
					'raw_data'       => is_array($data) ? $data : [],
					'fire_waic'      => true,
				]);
			}
		break;

	endswitch;
    // Trả về 200 để tránh vòng lặp web hook
    http_response_code(200);
    exit;

}

function twf_extract_domain_from_text($text) {
    // Regex mới: bắt cả subdomain nếu có
    $pattern = '/(?:https?:\/\/)?((?:[a-zA-Z0-9\-]+\.)+[a-zA-Z]{2,})(?:\/\S*)?/i';

    if (preg_match($pattern, $text, $matches)) {
        return strtolower($matches[1]); // Trả về đầy đủ: demoai.babyhub.vn
    }

    return false;
}
function twf_classify_attachment($url) {
    
   # $url = $attachment['payload']['url'];
    if (empty($url)) {
        return 'unknown';
    }

    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

    // Check known audio formats
    $audio_exts = ['aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga'];
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    if (in_array($extension, $audio_exts)) {
        error_log('[twf_classify_attachment] ✅ Audio detected by extension: ' . $extension);
        return 'audio';
    }

    if (in_array($extension, $image_exts)) {
        error_log('[twf_classify_attachment] ✅ Image detected by extension: ' . $extension);
        return 'image';
    }

    // Fallback checks for images without clear extension
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    $full_url_lower = strtolower($url);

    // 1. Zalo CDN image URLs (zdn.vn) with image format in path
    if (strpos($host, 'zdn.vn') !== false) {
        if (preg_match('#/(jpg|jpeg|png|gif|webp|bmp)/#i', $path)) {
            error_log('[twf_classify_attachment] ✅ Image detected by Zalo CDN path pattern');
            return 'image';
        }
    }

    // 2. Check for STICKER patterns first (before generic image indicators)
    // Stickers should NOT be treated as real images (no transient save, no "waiting" state)
    $sticker_indicators = [ '/sticker/', '/animated/', '/gif/' ];
    foreach ( $sticker_indicators as $indicator ) {
        if ( strpos( $full_url_lower, $indicator ) !== false ) {
            error_log( '[twf_classify_attachment] 🎭 Sticker detected by indicator: ' . $indicator );
            return 'sticker';
        }
    }
    // Zalo animated GIF/sticker patterns (stc-zaloprofile.zdn.vn)
    if ( strpos( $host, 'stc-' ) !== false && strpos( $host, 'zdn.vn' ) !== false ) {
        error_log( '[twf_classify_attachment] 🎭 Sticker detected by Zalo sticker CDN' );
        return 'sticker';
    }

    // 3. Check for common image indicators in URL (real photos, not stickers)
    // - Query params: format=gif, type=image, mime=image/gif, etc.
    // - Path segments: /images/, /photos/, /picture/
    $image_indicators = [
        'format=gif', 'format=png', 'format=jpg', 'format=jpeg', 'format=webp',
        'type=image', 'mime=image', 'content_type=image',
        '/images/', '/photos/', '/picture/',
        'image_url=', 'photo_url=', 'img=',
    ];
    
    foreach ($image_indicators as $indicator) {
        if (strpos($full_url_lower, $indicator) !== false) {
            error_log('[twf_classify_attachment] ✅ Image detected by indicator: ' . $indicator);
            return 'image';
        }
    }

    // 4. Check for image file pattern at end of path (before query string)
    // Example: /abc123.gif?v=123 or /file_image_animated
    if (preg_match('#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url)) {
        error_log('[twf_classify_attachment] ✅ Image detected by extension in URL');
        return 'image';
    }

    error_log('[twf_classify_attachment] ⚠️ Unknown attachment type - URL: ' . mb_substr($url, 0, 100));
    return 'unknown';
}
function twf_append_image_context_if_needed($client_id, $text) {
    $transient_key = "bizgpt_image_" . md5($client_id);
    $data = get_transient($transient_key);

    if ($data && !empty($data['image_url'])) {
        // Gắn link ảnh vào cuối prompt
        $image_url = $data['image_url'];
		$content = $data['data'];
        $text .= "\nẢnh liên quan: {$image_url}";
		//$text .= "\nNội dung mô tả: {$content}";
        // Xoá sau khi dùng
        delete_transient($transient_key);
    }

    return $text;
}
function twf_handle_image_attachment($client_id, $image_url) {
    /* 1. Tải ảnh về và upload lên WordPress
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return false;
    }

    $file_array = [
        'name'     => basename($image_url),
        'tmp_name' => $tmp,
    ];

    $id = media_handle_sideload($file_array, 0);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return false;
    }

    $url = wp_get_attachment_url($id);*/
	/* $api_key             = get_option('twf_openai_api_key');
	$img_response = send_chatbot_chatgpt_upload_files($image_url, $api_key);
	#back_trace('NOTICE', 'twf_handle_image_attachment: '.$img_response);
	$file_id = $img_response['id'] ??'';
	$file_type ='vision';
	$file_ids[] = $file_id;
	$file_ids[$file_id] = $file_type;
	#back_trace( 'SUCCESS', '$file_id: '.$file_id);
	$assistant_id='asst_O85cidOL5HdRvUaSOETinlEE';
	$user_id    = $client_id;
	$session_id = $client_id;
		// Send message to Custom GPT API - Ver 1.6.7
	$message = 'Ảnh gì đây: '.$file_id;	
	$response = chatbot_chatgpt_custom_gpt_call_api($api_key, $client_context.$message, $assistant_id, $thread_id, $session_id, $user_id, $page_id, $file_ids);
	*/
	#$data = send_zalo_botbanhang($response, $client_id);
	$url = $image_url;

    // 2. Lưu vào transient kèm thông tin nhận dạng
    $transient_key = "bizgpt_image_" . md5($client_id);
    set_transient($transient_key, [
        'image_url' => $url,
		'data' => $response,
        'created_at' => time()
    ], 60 * 15); // 15 phút

    // 3. Nhắn tin phản hồi cho khách
    $msg = "✅ Em đã nhận được ảnh. Sếp muốn em làm gì với ảnh này? "; //(ví dụ: Viết bài về chủ đề gì đó ví dụ nội dung mô tả: ".$response.")
    send_zalo_botbanhang($msg, $client_id);

    return true;
}
function twf_get_transcript_from_voice_url_google($voice_url, $google_api_key) {
    back_trace('NOTICE', "🎙️ Voice file URL: $voice_url");

    // 1. Tải file về local
    $tmp_file = download_url_to_tempfile($voice_url);
    if (!$tmp_file) {
        back_trace('WARNING', "Không tải được file từ URL: $voice_url");
        return false;
    }

    // 2. Convert AAC → WAV (LINEAR16)
    $converted_file = convert_aac_to_wav_local($tmp_file);
    if (!$converted_file) {
        back_trace('WARNING', "Không convert được file $tmp_file sang WAV");
        @unlink($tmp_file);
        return false;
    }

    // 3. Gửi tới Google Speech
    $transcript = twf_google_speech_to_text($converted_file, $google_api_key);

    // 4. Cleanup file tạm
    @unlink($tmp_file);
    @unlink($converted_file);

    return $transcript ?: false;
}

/* ═══════════════════════════════════════════════════════════
 * CROSS-PATH CONTEXT BUILDER
 *
 * Builds conversation context for Zalo/Bot/FB paths that don't have
 * webchat sessions. Uses:
 *   1. User Memory (explicit + extracted memories)
 *   2. Recent messages from bizcity_webchat_messages (last N minutes or M messages)
 *   3. Profile context from BizCity_Profile_Context
 *
 * This ensures AI has continuity across Zalo/Bot conversations.
 * ═══════════════════════════════════════════════════════════ */

function bizgpt_build_cross_path_context( $chat_id, $user_id = 0, $minutes = 30, $max_messages = 100 ) {
    global $wpdb;
    $parts = [];

    // ── 1. User Memory (long-term explicit + extracted) ──
    if ( $user_id && class_exists( 'BizCity_User_Memory' ) ) {
        $memory_instance = BizCity_User_Memory::instance();
        if ( method_exists( $memory_instance, 'get_memories_for_context' ) ) {
            $memories = $memory_instance->get_memories_for_context( $user_id, 10 );
            if ( ! empty( $memories ) ) {
                $parts[] = "## 🧠 Ký ức về người dùng:\n" . $memories;
            }
        }
    }

    // ── 2. Profile Context (from BizCoach Map / provider profiles) ──
    if ( $user_id && class_exists( 'BizCity_Profile_Context' ) ) {
        $profile_ctx = BizCity_Profile_Context::instance()->build_user_context( $user_id, '', 'ADMINCHAT', [
            'include_astro'   => false,
            'include_answers' => false,
            'include_gen'     => false,
        ] );
        if ( ! empty( $profile_ctx ) ) {
            $parts[] = $profile_ctx;
        }
    }

    // ── 3. Recent messages from webchat DB (cross-platform, by chat_id or user_id) ──
    $tbl_msg  = $wpdb->prefix . 'bizcity_webchat_messages';
    $tbl_conv = $wpdb->prefix . 'bizcity_webchat_conversations';

    // Check tables exist
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_msg}'" ) === $tbl_msg ) {
        $cutoff = date( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );

        // Strategy: query by user_id (more reliable across chat_ids and sessions)
        $messages = [];
        if ( $user_id ) {
            // Get conversation IDs for this user
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_conv}'" ) === $tbl_conv ) {
                $messages = $wpdb->get_results( $wpdb->prepare(
                    "SELECT m.message_from, m.message_text, m.created_at
                     FROM {$tbl_msg} m
                     JOIN {$tbl_conv} c ON m.conversation_id = c.id
                     WHERE c.user_id = %d
                       AND m.created_at >= %s
                     ORDER BY m.created_at DESC
                     LIMIT %d",
                    $user_id, $cutoff, $max_messages
                ) );
            }
        }

        if ( ! empty( $messages ) ) {
            // Reverse to chronological order
            $messages = array_reverse( $messages );
            $lines = [];
            foreach ( $messages as $msg ) {
                $role = ( $msg->message_from === 'user' ) ? 'User' : 'Bot';
                $text = trim( $msg->message_text );
                if ( mb_strlen( $text ) > 200 ) {
                    $text = mb_substr( $text, 0, 197 ) . '...';
                }
                $time = substr( $msg->created_at, 11, 5 ); // HH:MM
                $lines[] = "  [{$time}] {$role}: {$text}";
            }
            $parts[] = "## 💬 Lịch sử chat gần đây ({$minutes} phút):\n" . implode( "\n", $lines );
        }
    }

    return implode( "\n\n", array_filter( $parts ) );
}

// 4. Main ZALO handler
function bizgpt_webhook_admin_handler($data){
    $platform_type = $data['platform_type'] ?? '';
    $client_id     = $data['client_id']     ?? '';
    $page_id       = $data['page_id']       ?? '';
    $event         = $data['event']         ?? '';
    $message       = $data['message']       ?? [];
    $message_type  = $message['message_type'] ?? '';
    $last_message  = sanitize_text_field($message['message_text'] ?? '');

    if ($event !== 'message.create' || $message_type !== 'client' || !$last_message) return;
    if ($platform_type === 'ZALO_PERSONAL') {
        $blog_info = get_blog_info_by_zalo_id($page_id);
        if (!isset($blog_info->blog_id)) return;
        if ($blog_info->blog_id !== '583') switch_to_blog($blog_info->blog_id);
        $bot_setup = wp_parse_args(get_option('pmfacebook_options'));
        if ($bot_setup['using_ai'] !== '1') return;

        $replies = bizgpt_chatbot_run_admin_flows($last_message);
        foreach ((array)$replies as $reply) {
            twf_telegram_send_message('zalo_' . $client_id, $reply);
        }

        if ($blog_info->blog_id !== '583') restore_current_blog();
    }
}

/**
 * Fire AIWU trigger: waic_twf_process_flow
 * - Ensure AIWU hooked flows are registered in current blog context
 * - Log listeners count for debugging
 */
function bizcity_aiwu_fire_twf_process_flow(array $trigger, array $raw = [], string $hookName = 'waic_twf_process_flow') {
    // During AJAX requests, use output buffering to prevent stray output
    // from corrupting the JSON response (e.g. admin chat, webchat send).
    $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
    if ($is_ajax) {
        ob_start();
    }
    
    $waicLoaded = (class_exists('WaicFrame') && is_callable(['WaicFrame', '_']));
    if (!$waicLoaded) {
        error_log('[TWF][AIWU] WaicFrame not loaded (AIWU plugin inactive for this request/blog?)');
    }

    // Boot hooked flows per-blog (tránh trường hợp switch blog nhưng bị "booted" sai)
    static $bootedBlogs = [];
    $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

    if ($waicLoaded && empty($bootedBlogs[$blogId])) {
        $bootedBlogs[$blogId] = true;
        try {
            $wfModel = WaicFrame::_()->getModule('workflow')->getModel('workflow');
            if ($wfModel && method_exists($wfModel, 'doHookedFlows')) {
                $wfModel->doHookedFlows();
            }
        } catch (\Throwable $e) {
            error_log('[TWF][AIWU] doHookedFlows error: ' . $e->getMessage());
        }
    }

    global $wpdb;
    $wfTable = $wpdb->prefix . 'waic_workflows';

    // DEBUG: table + columns + sample rows (DISABLED - causes performance issues)
    // Only enable when actively debugging workflow issues
    $debug_mode = defined('BIZCITY_WORKFLOW_DEBUG') && BIZCITY_WORKFLOW_DEBUG;
    
    if ($waicLoaded && $debug_mode) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wfTable));
        if ($exists === $wfTable) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wfTable}");
            error_log('[TWF][AIWU] workflows_table=1 cols=' . implode(',', (array)$cols));

            // lấy 2 rows mới nhất để xem status/active/trigger json đang là gì
            $rows = $wpdb->get_results("SELECT id, status FROM {$wfTable} ORDER BY id DESC LIMIT 2", ARRAY_A);
            error_log('[TWF][AIWU] workflows_sample=' . wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            error_log('[TWF][AIWU] workflows_table=0');
        }
    }

    $listeners = function_exists('has_action') ? (int) has_action($hookName) : -1;
    
    // Only log if debug mode or no listeners found
    if ($debug_mode || $listeners <= 0) {
        error_log('[TWF][AIWU] firing ' . $hookName . ' | blog_id=' . $blogId . ' listeners=' . $listeners);
    }

    // [2026-07-26 Johnny Chu] HOTFIX — core automation matcher does NOT
    // subscribe `waic_twf_process_flow`; it listens canonical
    // `bizcity_channel_message_received` / `bizcity_channel_normalized`.
    // Legacy /bizhook path only fired AIWU before, so matcher missed inbound
    // completely when WaicFrame was inactive (or trigger_key was not a string).
    // Bridge the trigger payload directly into channel_message bus.
    if ( is_array( $trigger ) ) {
        $platform_key = strtolower( (string) ( $trigger['platform'] ?? $trigger['twf_platform'] ?? '' ) );
        $platform_norm = strtoupper( $platform_key );
        if ( $platform_key === 'zalo' ) {
            $platform_norm = 'ZALO_PERSONAL';
        } elseif ( $platform_key === 'zalo_bot' ) {
            $platform_norm = 'ZALO_BOT';
        } elseif ( $platform_key === 'webchat' ) {
            $platform_norm = 'WEBCHAT';
        } elseif ( $platform_key === 'adminchat' ) {
            $platform_norm = 'ADMINCHAT';
        }

        $text      = (string) ( $trigger['text'] ?? $trigger['twf_text'] ?? '' );
        $chat_id   = (string) ( $trigger['chat_id'] ?? $trigger['twf_chat_id'] ?? '' );
        $sender_id = (string) ( $trigger['client_id'] ?? $trigger['twf_client_id'] ?? '' );
        $mid       = (string) ( $trigger['message_id'] ?? '' );

        static $bridged_once = array();
        $bridge_key = $platform_norm . '|' . $mid . '|' . $chat_id . '|' . md5( $text );
        if ( ! isset( $bridged_once[ $bridge_key ] ) ) {
            $bridged_once[ $bridge_key ] = true;

            do_action( 'bizcity_channel_message_received', array(
                'platform'           => $platform_norm,
                'event_subtype'      => '',
                'message'            => $text,
                'text'               => $text,
                'raw_text'           => $text,
                'message_text_clean' => $text,
                'instance_id'        => (string) ( $trigger['bot_id'] ?? $trigger['conversation_id'] ?? '' ),
                'account_id'         => (string) ( $trigger['bot_id'] ?? $trigger['conversation_id'] ?? '' ),
                'sender_id'          => $sender_id,
                'user_id'            => $sender_id,
                'wp_user_id'         => (int) ( $trigger['wp_user_id'] ?? 0 ),
                'character_id'       => 0,
                'chat_id'            => $chat_id,
                'conversation_chat_id' => $chat_id,
                'provider_chat_id'   => $sender_id,
                'provider_chat_type' => '',
                'chat_kind'          => 'private',
                'mention_detected'   => false,
                'reply_to_bot_message' => false,
                'mid'                => $mid,
                'message_id'         => $mid,
                'media_url'          => (string) ( $trigger['image_url'] ?? $trigger['attachment_url'] ?? '' ),
                'media_kind'         => (string) ( $trigger['attachment_type'] ?? '' ),
                'channel_role'       => 'USER',
                'raw'                => is_array( $raw ) ? $raw : $trigger,
                '_source'            => 'bizcity-zalo-bizcity.aiwu-bridge',
            ) );
        }
    }

    // Set global trigger for needSkip HIL bypass
    global $waic_current_trigger;
    $waic_current_trigger = $trigger;

    do_action($hookName, $trigger, $raw);

    // Clean up output buffer if we started one (AJAX protection)
    if ($is_ajax) {
        ob_end_clean();
    }

    return $listeners > 0;
}

/* ═══════════════════════════════════════════════════════════
 * UNIFIED MESSAGE PROCESSING PIPELINE
 *
 * Xử lý tin nhắn chung cho tất cả 3 luồng:
 *   ZALO_PERSONAL : Zalo hotline số chung → resolve blog từ global_user_admin
 *   ZALO_BOT      : Zalo Bot riêng mỗi domain → blog từ source_blog_id / wp_blogs
 *   ADMINCHAT     : Web chat, user đã đăng nhập → dùng current blog / user trực tiếp
 *
 * Pipeline (giống nhau cho cả 3, chỉ khác ở Step 1):
 *   1  Resolve blog_id theo platform
 *   2  switch_to_blog
 *   3  Resolve wp_user_id
 *   4  Build canonical chat_id  (zalo_{id} | zalobot_{bot}_{id} | adminchat_{session})
 *   5  HIL check (Human-in-the-Loop waiting state)
 *   6  Log / misc transients
 *   7  Attachment handling (image lưu transient → return; audio → transcript)
 *   8  Image context: twf_append_image_context_if_needed + parse text_image_url
 *   9  Admin flows: bizgpt_chatbot_run_admin_flows (twf_process_flow_from_params)
 *  10  Build $twf_trigger payload
 *  11  Fire WAIC workflow (bỏ qua nếu $fire_waic=false, tức đang trong hook rồi)
 * ═══════════════════════════════════════════════════════════ */

/**
 * Unified message processing entry point.
 *
 * @param array $ctx {
 *   platform        string   'ZALO_PERSONAL' | 'ZALO_BOT' | 'ADMINCHAT'
 *   client_id       string   Zalo personal/bot user ID (raw, no prefix)
 *   bot_id          int      Zalo Bot only
 *   session_id      string   ADMINCHAT only (mapped to adminchat_{session})
 *   message_text    string   Raw text from user
 *   message_id      string   For dedup lock
 *   attachment_url  string   File/image/audio URL
 *   display_name    string   User display name
 *   source_blog_id  int      Zalo Bot: pre-resolved blog_id from webhook domain
 *   wp_user_id      int      Pre-resolved WP user (optional, looked up if 0)
 *   raw_data        array    Original webhook payload
 *   fire_waic       bool     true = fire waic_twf_process_flow at end
 *                            false = already inside a waic hook (avoid recursion)
 * }
 */
function bizgpt_process_unified_message( array $ctx ): void {
    $platform       = (string)( $ctx['platform']        ?? '' );
    $client_id      = (string)( $ctx['client_id']       ?? '' );
    $bot_id         = (int)(   $ctx['bot_id']            ?? 0  );
    $session_id     = (string)( $ctx['session_id']      ?? '' );
    $message_text   = (string)( $ctx['message_text']    ?? '' );
    $message_id     = (string)( $ctx['message_id']      ?? '' );
    $attachment_url = (string)( $ctx['attachment_url']  ?? '' );
    $display_name   = (string)( $ctx['display_name']    ?? '' );
    $source_blog_id = (int)(   $ctx['source_blog_id']   ?? 0  );
    $wp_user_id     = (int)(   $ctx['wp_user_id']       ?? 0  );
    $raw_data       = is_array( $ctx['raw_data'] ?? null ) ? $ctx['raw_data'] : [];
    $fire_waic      = isset( $ctx['fire_waic'] ) ? (bool) $ctx['fire_waic'] : true;

    // ── STEP 1 : Resolve blog_id ─────────────────────────────────────────────
    switch ( $platform ) {

        case 'ZALO_PERSONAL':
            // Blog tương ứng lần đăng nhập cuối: client_id → global_user_admin → blog_id
            $blog_id = (int) get_zalo_option( $client_id );
            if ( ! $blog_id ) {
                // Chưa kết nối web nào → hướng dẫn đăng nhập
                $domain = twf_extract_domain_from_text( $message_text );
                if ( ! $domain ) {
                    send_zalo_botbanhang(
                        'Bạn chưa kết nối với web nào để có thể quản trị. Hãy nhắn địa chỉ web bạn muốn quản trị.',
                        $client_id
                    );
                    return;
                }
                $domain = strtolower( trim( $domain ) );
                if ( filter_var( $domain, FILTER_VALIDATE_URL ) ) {
                    $domain = parse_url( $domain, PHP_URL_HOST ) ?? $domain;
                }
                if ( strpos( $domain, '.' ) === false ) {
                    send_zalo_botbanhang( "⚠️ Domain không hợp lệ. Ví dụ: demoai.babyhub.vn", $client_id );
                    return;
                }
				$enc       = class_exists( 'BizCity_CG_Flow_Ref_Codec' ) ? BizCity_CG_Flow_Ref_Codec::encode( (int) $client_id, 'vietqr' ) : '';
                $login_url = 'https://' . $domain . '/telegram-login/?zid=' . $enc;
                send_zalo_botbanhang( "Dạ. Sếp hãy nhấn vào link bên dưới để xác nhận quyền quản trị:\n$login_url", $client_id );
                return;
            }
            break;

        case 'ZALO_BOT':
            // Mỗi Zalo Bot gắn với 1 domain → blog_id đã được xác định từ domain webhook
            $blog_id = $source_blog_id ?: (int) bizcity_zalobot_resolve_blog_id( $bot_id );
            if ( ! $blog_id ) {
                error_log( '[UNIFIED][ZALO_BOT] No blog for bot #' . $bot_id . ', fallback to current blog' );
                $blog_id = get_current_blog_id();
            }
            break;

        case 'ADMINCHAT':
            // User đã đăng nhập → dùng blog hiện tại, không cần switch
            $blog_id = get_current_blog_id();
            break;

        default:
            error_log( '[UNIFIED] Unknown platform: ' . $platform );
            return;
    }

    // ── STEP 2 : Switch blog ─────────────────────────────────────────────────
    $switched = false;
    if ( is_multisite() && $blog_id && $blog_id !== get_current_blog_id() ) {
        switch_to_blog( $blog_id );
        $switched = true;
    }

    // ── STEP 3 : Resolve WP user_id ─────────────────────────────────────────
    if ( ! $wp_user_id ) {
        if ( $platform === 'ADMINCHAT' ) {
            $wp_user_id = get_current_user_id();
        } else {
            global $globaldb;
            if ( isset( $globaldb ) && $globaldb ) {
                $_row = $globaldb->get_row( $globaldb->prepare(
                    "SELECT user_id, user_slave_id FROM global_user_admin
                      WHERE client_id = %s AND blog_id = %d
                      ORDER BY updated_at DESC LIMIT 1",
                    $client_id, $blog_id
                ) );
                if ( ! $_row ) {
                    $_row = $globaldb->get_row( $globaldb->prepare(
                        "SELECT user_id, user_slave_id FROM global_user_admin
                          WHERE client_id = %s
                          ORDER BY updated_at DESC LIMIT 1",
                        $client_id
                    ) );
                }
                if ( $_row ) {
                    $wp_user_id = ! empty( $_row->user_slave_id )
                        ? (int) $_row->user_slave_id
                        : (int) $_row->user_id;
                }
            }
            if ( ! $wp_user_id && function_exists( 'twf_get_user_id_by_chat_id' ) ) {
                $wp_user_id = (int) twf_get_user_id_by_chat_id( $client_id );
            }
        }
    }

    if ( $wp_user_id ) {
        wp_set_current_user( $wp_user_id );
        error_log( '[UNIFIED][' . $platform . '] ✅ Set WP user_id=' . $wp_user_id );
    } else {
        error_log( '[UNIFIED][' . $platform . '] ⚠️ Could not resolve WP user_id for client_id=' . $client_id );
    }

    // ── STEP 3.5 : Resolve channel role early ───────────────────────────────
    // Store in global so twf_handle_chat_flow can pick it up
    if ( class_exists( 'BizCity_Channel_Role' ) ) {
        $GLOBALS['bizcity_channel_role'] = BizCity_Channel_Role::resolve( $platform, $bot_id ?: null, $wp_user_id );
        error_log( '[UNIFIED][' . $platform . '] Channel role → ' . ( $GLOBALS['bizcity_channel_role']['slug'] ?? 'none' ) );
    }

    // ── STEP 4 : Build canonical chat_id ────────────────────────────────────
    switch ( $platform ) {
        case 'ZALO_PERSONAL': $chat_id = 'zalo_' . $client_id;                           break;
        case 'ZALO_BOT':      $chat_id = 'zalobot_' . $bot_id . '_' . $client_id;        break;
        case 'ADMINCHAT':     $chat_id = 'adminchat_' . ( $session_id ?: $client_id );   break;
        default:              $chat_id = $client_id;
    }

    // ── STEP 5 : HIL (Human-in-the-Loop) check ──────────────────────────────
    if ( function_exists( 'waic_hil_get_state' ) && function_exists( 'waic_hil_update_response' ) ) {
        // ZALO_PERSONAL legacy: HIL keyed by bare client_id; others use full chat_id
        $hil_id    = ( $platform === 'ZALO_PERSONAL' ) ? $client_id : $chat_id;
        $hil_state = waic_hil_get_state( $hil_id, $blog_id );

        if ( $hil_state !== false && ( $hil_state['status'] ?? '' ) === 'waiting' ) {
            error_log( '[UNIFIED][HIL] Waiting state for ' . $hil_id );
            $updated = waic_hil_update_response( $hil_id, $message_text, $blog_id );
            if ( $updated ) {
                $new_state = waic_hil_get_state( $hil_id, $blog_id );
                if ( ( $new_state['status'] ?? '' ) === 'confirmed' ) {
                    biz_send_message( $chat_id, '✅ Đã xác nhận! Workflow sẽ tiếp tục...' );
                } elseif ( ( $new_state['status'] ?? '' ) === 'rejected' ) {
                    biz_send_message( $chat_id, '❌ Đã huỷ! Workflow sẽ dừng lại.' );
                }
                if ( $switched ) restore_current_blog();
                return;
            }
        }
    }

    // ── STEP 6 : Logging / misc transients ──────────────────────────────────
    if ( $platform === 'ZALO_PERSONAL' ) {
        // Cho phép các flow khác (video job…) biết client_id hiện tại của blog
        set_transient( 'zalo_client_id_by_blog_id_' . $blog_id, $client_id, 60 * 2 );
    }

    if ( class_exists( 'BizCity_Zalo_Bot_Database' ) && $platform !== 'ADMINCHAT' ) {
        $log_bot_id = ( $platform === 'ZALO_BOT' ) ? $bot_id : 9999;
        BizCity_Zalo_Bot_Database::instance()->log_event(
            $log_bot_id,
            $raw_data['event'] ?? 'webhook.received',
            $raw_data,
            $client_id,
            $message_id,
            $display_name,
            $message_text
        );
    }

    // ── STEP 7 : Attachment handling ─────────────────────────────────────────
    $context_id = $client_id ?: $session_id; // key cho transient image
    $file_type  = twf_classify_attachment( $attachment_url );

    switch ( $file_type ) {
        case 'sticker':
            // Sticker → phản hồi kiểu reflection, KHÔNG lưu transient, KHÔNG chờ prompt tiếp
            error_log( '[UNIFIED] 🎭 Sticker detected → reflection mode (no keep-conversation)' );
            $sticker_replies = [
                '😄', '👍', '🥰 Cute ghê!', '😊', 'Hehe 😂', '❤️',
                '😆 Vui quá!', '🤗', '👌', '✨', '💯',
            ];
            $random_reply = $sticker_replies[ array_rand( $sticker_replies ) ];
            biz_send_message( $chat_id, $random_reply );
            if ( $switched ) restore_current_blog();
            return;

        case 'image':
            // Lưu ảnh transient, chờ tin nhắn tiếp theo mô tả yêu cầu
            // (Inline thay vì gọi twf_handle_image_attachment để dùng đúng API theo platform)
            $img_transient_key = 'bizgpt_image_' . md5( $context_id );
            set_transient( $img_transient_key, [
                'image_url'  => $attachment_url,
                'client_id'  => $context_id,
                'platform'   => $platform,
                'chat_id'    => $chat_id,
                'created_at' => time(),
            ], 60 * 15 );
            // Dùng biz_send_message để route đúng platform (Zalo Bot / Zalo Personal / Telegram)
            biz_send_message( $chat_id, '✅ Em đã nhận được ảnh. Sếp muốn em làm gì với ảnh này?' );
            if ( $switched ) restore_current_blog();
            return;

        case 'audio':
            $api_gg_key  = get_option( 'bizcity_google_speech_api_key', 'AIzaSyAgnuicZKh3wZjqpj--w0T_Nrqi-5_X-gQ' );
            $transcript  = twf_get_transcript_from_voice_url_google( $attachment_url, $api_gg_key );
            if ( ! empty( $transcript ) ) {
                $message_text = $transcript;
                biz_send_message( $chat_id, "🎙️ Em vừa nghe xong, ý sếp là:\n\n\"$transcript\"\n\nĐể em xử lý tiếp ạ." );
            }
            break;
    }

    // ── STEP 8 : Image context (transient append + parse URL) ───────────────
    // Lấy URL gốc từ transient TRƯỚC khi append (giữ URL sạch cho trigger)
    $context_image_url = '';
    $img_transient     = get_transient( 'bizgpt_image_' . md5( $context_id ) );
    if ( $img_transient && ! empty( $img_transient['image_url'] ) ) {
        $context_image_url = (string) $img_transient['image_url'];
    }

    // Append vào text rồi parse "Ảnh liên quan: URL" ra thành biến riêng
    $message_text  = twf_append_image_context_if_needed( $context_id, $message_text );
    $text_image_url = '';
    $clean_message  = $message_text;
    if ( preg_match( '/(?:Ảnh\s+liên\s+quan|Image|Hình\s+ảnh)\s*:\s*(https?:\/\/[^\s\n\r]+)/ui', $message_text, $img_m ) ) {
        $text_image_url = trim( $img_m[1] );
        $clean_message  = trim( preg_replace(
            '/(?:Ảnh\s+liên\s+quan|Image|Hình\s+ảnh)\s*:\s*https?:\/\/[^\s\n\r]+[\n\r]*/ui',
            '',
            $message_text
        ) );
        error_log( '[UNIFIED] Parsed image URL from text: ' . $text_image_url );
    }

    // Ưu tiên: URL trong text > URL từ transient context > URL từ attachment
    $final_image_url = ! empty( $text_image_url )
        ? $text_image_url
        : ( ! empty( $context_image_url )
            ? $context_image_url
            : ( $file_type === 'image' ? $attachment_url : '' ) );

    // ── STEP 9 : Admin flows (twf_process_flow_from_params) ────────────────
    switch ( $platform ) {
        case 'ZALO_BOT':      $twf_platform = 'zalo_bot'; break;
        case 'ADMINCHAT':     $twf_platform = 'webchat';  break;
        case 'ZALO_PERSONAL':
        default:              $twf_platform = 'zalo';     break;
    }

    // ── INTENT FILTER: cho phép plugin mở rộng chặn xử lý tin nhắn sớm ─────
    // Trả về true = đã xử lý, skip toàn bộ admin flows + Chat Gateway
    // QUAN TRỌNG: filter cần check attachment_type - nếu là 'image' thì nên return false
    //             để step 7 xử lý (lưu transient + return), tránh xử lý sớm khi chưa có text prompt

    // ── Build recent messages context for cross-path continuity ──
    // Zalo/Bot paths don't have webchat sessions, so we build context from:
    // 1. Recent messages in last 30 minutes (or up to 100 messages) from bizcity_webchat_messages
    // 2. User memory and profile context
    $recent_context = '';
    if ( in_array( $platform, [ 'ZALO_PERSONAL', 'ZALO_BOT', 'FACEBOOK' ], true ) ) {
        $recent_context = bizgpt_build_cross_path_context( $chat_id, $wp_user_id, 30, 100 );
    }

    $intent_handled = apply_filters( 'bizcity_unified_message_intent', false, [
        'message'         => $clean_message,
        'chat_id'         => $chat_id,
        'client_id'       => $client_id,
        'wp_user_id'      => $wp_user_id,
        'platform'        => $platform,
        'blog_id'         => $blog_id,
        'session_id'      => $session_id,
        'attachment_url'  => $attachment_url,
        'attachment_type' => $file_type,
        'image_url'       => $final_image_url,
        'recent_context'  => $recent_context,
    ] );
    if ( $intent_handled ) {
        if ( $switched ) restore_current_blog();
        return;
    }

    // [2026-08-13 Johnny Chu] HOTFIX-ZALOBOT-LEGACY-RETIRE — Zalo Bot is owned by the canonical UCL/Automation/Command Router path; never enter twf_process_flow_from_params or re-fire WAIC legacy from this adapter.
    if ( $platform === 'ZALO_BOT' ) {
        error_log( '[ZALO_BOT] Skip legacy twf_process_flow_from_params; canonical channel path already dispatched.' );
        if ( $switched ) restore_current_blog();
        return;
    }

    // ── FALLBACK: nếu filter chưa có handler (plugin chưa load trên blog này)
    //    → thử load bizcity-tarot integration trực tiếp từ disk rồi retry ──
    if ( ! $intent_handled ) {
        $tarot_integration = WP_CONTENT_DIR . '/plugins/bizcity-tarot/includes/integration-chat.php';
        if ( ! function_exists( 'bct_is_tarot_intent' ) && file_exists( $tarot_integration ) ) {
            // Load các hàm helper của tarot plugin nếu chưa có
            $bct_tables_file = WP_CONTENT_DIR . '/plugins/bizcity-tarot/includes/install.php';
            if ( ! function_exists( 'bct_tables' ) && file_exists( $bct_tables_file ) ) {
                require_once $bct_tables_file;
            }
            if ( ! defined( 'BCT_DIR' ) ) {
                define( 'BCT_DIR', WP_CONTENT_DIR . '/plugins/bizcity-tarot/' );
            }
            require_once $tarot_integration;
        }
        // Retry filter (hook bct_intent_filter vừa được đăng ký)
        if ( function_exists( 'bct_is_tarot_intent' ) && bct_is_tarot_intent( $clean_message ) ) {
            $intent_handled = apply_filters( 'bizcity_unified_message_intent', false, [
                'message'         => $clean_message,
                'chat_id'         => $chat_id,
                'client_id'       => $client_id,
                'wp_user_id'      => $wp_user_id,
                'platform'        => $platform,
                'blog_id'         => $blog_id,
                'session_id'      => $session_id,
                'attachment_url'  => $attachment_url,
                'attachment_type' => $file_type,
                'image_url'       => $final_image_url,
                'recent_context'  => $recent_context,
            ] );
            if ( $intent_handled ) {
                if ( $switched ) restore_current_blog();
                return;
            }
        }
    }

    // Phản hồi tức thì: báo bot đã nhận (Zalo Personal trả domain + "dạ")
    if ( $platform === 'ZALO_PERSONAL' ) {
        $blog_info = get_blog_details( $blog_id );
        if ( $blog_info ) {
            send_zalo_botbanhang( $blog_info->domain . ' AI: ...', $client_id );
        }
    }

    // Chạy các flow quản trị theo thứ tự case (khác → twf_handle_chat_flow)
    // Truyền vision image qua global để twf_handle_chat_flow đẩy vào get_ai_response
    if ( ! empty( $final_image_url ) ) {
        $GLOBALS['bizgpt_pending_vision_url'] = $final_image_url;
        error_log( '[UNIFIED] Set bizgpt_pending_vision_url=' . $final_image_url );
    } else {
        error_log( '[UNIFIED] No final_image_url to set as pending vision' );
    }
    $GLOBALS['twf_chat_msg_batch'] = [];
    $replies = bizgpt_chatbot_run_admin_flows( $clean_message, $chat_id, $twf_platform );
    foreach ( (array) $replies as $reply ) {
        if ( ! empty( $reply ) ) {
            biz_send_message( $chat_id, $reply );
        }
    }

    // ── STEP 10 : Build $twf_trigger payload ────────────────────────────────
    $twf_trigger = [
        'platform'        => $twf_platform,
        'client_id'       => $client_id,
        'chat_id'         => $chat_id,
        'text'            => $clean_message,
        'raw'             => $raw_data,
        'attachment_url'  => $attachment_url,
        'attachment_type' => $file_type,
        'image_url'       => $final_image_url,
        'audio_url'       => ( $file_type === 'audio' ) ? $attachment_url : '',
        'twf_platform'    => $twf_platform,
        'twf_client_id'   => $client_id,
        'twf_chat_id'     => $chat_id,
        'twf_text'        => $clean_message,
        'message_id'      => $message_id,
        'blog_id'         => $blog_id,
        'wp_user_id'      => $wp_user_id,
        'bot_id'          => $bot_id,
        'session_id'      => $session_id,
        'source_blog_id'  => $blog_id,
        'display_name'    => $display_name,
    ];

    // ── STEP 11 : Fire WAIC workflow trigger ─────────────────────────────────
    // fire_waic=false khi hàm được gọi từ bên trong waic_twf_process_flow (tránh loop)
    if ( $fire_waic ) {
        if ( $file_type === 'image' ) {
            bizcity_aiwu_fire_twf_process_flow( $twf_trigger, $raw_data, 'waic_twf_process_flow_image_received' );
        } else {
            bizcity_aiwu_fire_twf_process_flow( $twf_trigger, $raw_data );
        }
    }

    if ( $switched ) restore_current_blog();
}

// [2026-08-14 Johnny Chu] R-CH-UNI — retire the legacy Zalo Bot reply listener.
// Zalo Bot inbound now has one owner: UCL -> Automation Matcher -> TwinBrain.

/**
 * Parse zalobot_ prefix chat_id
 * Format: zalobot_{bot_id}_{zalo_user_id}
 *
 * @param string $chat_id
 * @return array|false ['bot_id' => int, 'zalo_user_id' => string]
 */
function bizcity_parse_zalobot_chat_id($chat_id) {
    if (strpos($chat_id, 'zalobot_') !== 0) {
        return false;
    }

    // Remove prefix
    $rest = substr($chat_id, 8); // After 'zalobot_'

    // Split by first underscore
    $pos = strpos($rest, '_');
    if ($pos === false) {
        return false;
    }

    $bot_id = intval(substr($rest, 0, $pos));
    $zalo_user_id = substr($rest, $pos + 1);

    if ($bot_id <= 0 || empty($zalo_user_id)) {
        return false;
    }

    return [
        'bot_id' => $bot_id,
        'zalo_user_id' => $zalo_user_id,
    ];
}

/**
 * Resolve blog_id từ bot assignment
 *
 * @param int $bot_id
 * @return int blog_id or 0
 */
function bizcity_zalobot_resolve_blog_id($bot_id) {
    global $wpdb;

    // Priority 1: Check cached source_blog_id from webhook handler
    $cached_blog_id = get_transient( 'zalobot_source_blog_' . $bot_id );
    if ( $cached_blog_id ) {
        error_log( sprintf( '[ZALO_BOT] 🎯 Using cached source_blog_id=%d for bot #%d', $cached_blog_id, $bot_id ) );
        return (int) $cached_blog_id;
    }

    // Priority 2: Try to get from bot's assigned user → user's primary blog
    if (class_exists('BizCity_Zalo_Bot_Dashboard')) {
        $wp_user_id = BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot((int)$bot_id);
        if ($wp_user_id) {
            // Get user's primary blog
            $primary_blog = get_user_meta($wp_user_id, 'primary_blog', true);
            if ($primary_blog) {
                return (int)$primary_blog;
            }
        }
    }

    // Fallback: get from bizcity_zalo_bots table blog_id column if exists
    $table_bots = $wpdb->prefix . 'bizcity_zalo_bots';
    $blog_id = $wpdb->get_var($wpdb->prepare(
        "SELECT blog_id FROM {$table_bots} WHERE id = %d",
        $bot_id
    ));

    return $blog_id ? (int)$blog_id : 0;
}

// 4. Override gửi tin nhắn nếu chat_id là zalo_*

add_filter('twf_telegram_send_photo_override', function($default, $chat_id, $photo_url, $caption = '', $extra = []) {
    // Nếu là chat_id zalo
    if (strpos($chat_id, 'zalo_') === 0) {
        $client_id = str_replace('zalo_', '', $chat_id);

        // Gửi ảnh qua Bot Bán Hàng
        $send = send_zalo_botbanhang($photo_url, $client_id, 'image');

        // Nếu có caption thì gửi thêm caption là tin nhắn văn bản
        if (!empty($caption)) {
            send_zalo_botbanhang($caption, $client_id, 'text');
        }

        return $send ? ['status' => 'sent', 'platform' => 'zalo'] : false;
    }

    return false; // fallback về Telegram
}, 12, 5);
add_filter('twf_send_telegram_document_override', function($default, $chat_id, $photo_url, $caption = '', $extra = []) {
    // Nếu là chat_id zalo
    if (strpos($chat_id, 'zalo_') === 0) {
        $client_id = str_replace('zalo_', '', $chat_id);

        // Gửi ảnh qua Bot Bán Hàng
        $send = send_zalo_botbanhang($photo_url, $client_id, 'file');

        // Nếu có caption thì gửi thêm caption là tin nhắn văn bản
        if (!empty($caption)) {
            send_zalo_botbanhang($caption, $client_id, 'text');
        }

        return $send ? ['status' => 'sent', 'platform' => 'zalo'] : false;
    }

    return false; // fallback về Telegram
}, 12, 5);
add_filter('twf_send_message_override', function($default, $chat_id, $text, $parse_mode, $reply_markup){
    // Nếu filter trước đó (priority thấp hơn) đã xử lý → pass through
    if ( $default !== false ) return $default;

    if (strpos($chat_id, 'zalo_') === 0) {
        $client_id = str_replace('zalo_', '', $chat_id);
        $data = send_zalo_botbanhang($text, $client_id);
        return $data ? ['status' => 'sent', 'platform' => 'zalo'] : false;
    }
    return false;
}, 10, 5);
// 5. Hàm xử lý admin flows
function bizgpt_chatbot_run_admin_flows($question, $client_id='', $platform='zalo') {
    // [2026-08-13 Johnny Chu] HOTFIX-ZALOBOT-LEGACY-RETIRE — defensive guard for direct callers; Zalo Bot must not invoke the legacy LLM classifier.
    if ( strtoupper( (string) $platform ) === 'ZALO_BOT' ) {
        return array();
    }
    #$chat_id = get_current_user_id() ?: ('admin_' . uniqid());
	$chat_id = $client_id;
    $params = [
        'message' => [
            'chat' => ['id' => $chat_id],
            'text' => $question
        ],
        'from_admin_chat' => true
    ];

    ob_start();
    $reply = '';
    add_filter('twf_telegram_send_message_response', function($msg, $cid) use(&$reply, $chat_id) {
        if ($cid == $chat_id) $reply = $msg;
        return $msg;
    }, 10, 2);

    if (function_exists('twf_process_flow_from_params')) {
        $GLOBALS['twf_chat_msg_batch'] = [];
        twf_process_flow_from_params($params, $client_id, $platform);
        $batch = $GLOBALS['twf_chat_msg_batch'];
        $reply = [];
        foreach ($batch as $m) {
            if ($m['chat_id'] == $chat_id) $reply[] = $m['msg'];
        }
    } else {
        $reply = "[Chưa tích hợp core flow AI]";
    }

    remove_all_filters('twf_telegram_send_message_response');
    ob_end_clean();
    if($reply) return $reply;
}



function twf_openai_speech_to_text_for_zalo($voice_url) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return false;

    // Tải file âm thanh về tạm thời
    $voice_data = wp_remote_get($voice_url);
    if (is_wp_error($voice_data)) return false;

    $tmpfname = tempnam(sys_get_temp_dir(), "voice_");
    file_put_contents($tmpfname, wp_remote_retrieve_body($voice_data));

    // Xác định mime-type từ URL hoặc default là audio/aac
    $mime_type = 'audio/aac';
    $ext = pathinfo(parse_url($voice_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    if ($ext === 'm4a') $mime_type = 'audio/m4a';
    elseif ($ext === 'mp3') $mime_type = 'audio/mpeg';
    elseif ($ext === 'ogg') $mime_type = 'audio/ogg';

    $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
    $boundary = wp_generate_password(24, false);

    $filename = basename($tmpfname) . "." . $ext;
    $multipart_body = "--$boundary\r\n";
    $multipart_body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
    $multipart_body .= "Content-Type: {$mime_type}\r\n\r\n";
    $multipart_body .= file_get_contents($tmpfname) . "\r\n";
    $multipart_body .= "--$boundary\r\n";
    $multipart_body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $multipart_body .= "whisper-1\r\n";
    $multipart_body .= "--$boundary--\r\n";

    unlink($tmpfname);

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body'    => $multipart_body,
        'timeout' => 80
    ];

    $response = wp_remote_post($endpoint, $args);
	
    if (is_wp_error($response)) {
    back_trace('WARNING', 'OpenAI Whisper HTTP error: ' . $response->get_error_message());
		return false;
	}
	
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	if (isset($body['error'])) {
		back_trace('WARNING', 'OpenAI Whisper API error: ' . print_r($body['error'], true));
		return false;
	}
	
	return $body['text'] ?? false;
}

function download_url_to_tempfile($url) {
    $res = wp_remote_get($url);
    if (is_wp_error($res)) return false;

    $tmpfile = tempnam(sys_get_temp_dir(), 'voice_');
    file_put_contents($tmpfile, wp_remote_retrieve_body($res));
    return $tmpfile;
}

function convert_aac_to_wav_local($input_file) {
    $output_file = $input_file . '.wav';
    if (!function_exists('proc_open')) return false;

    $cmd = "ffmpeg -y -i " . escapeshellarg($input_file) .
           " -acodec pcm_s16le -ac 1 -ar 16000 " . escapeshellarg($output_file) . " 2>&1";

    $descriptor = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
    $process = proc_open($cmd, $descriptor, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        return file_exists($output_file) ? $output_file : false;
    }

    return false;
}

function twf_google_speech_to_text($file_path,$api_key, $language_code = 'vi-VN') {
	#apikey = AIzaSyAgnuicZKh3wZjqpj--w0T_Nrqi-5_X-gQ
    if (!file_exists($file_path)) return false;

    $audio_content = base64_encode(file_get_contents($file_path));
    $postData = [
        'config' => [
            'encoding' => 'LINEAR16', // Nếu dùng FLAC/WAV, điều chỉnh theo
            'sampleRateHertz' => 16000, // Thường 16000Hz cho giọng nói, chỉnh theo file thật
            'languageCode' => $language_code
        ],
        'audio' => [
            'content' => $audio_content
        ]
    ];

    $response = wp_remote_post('https://speech.googleapis.com/v1/speech:recognize?key=' . $api_key, [
        'method'  => 'POST',
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($postData),
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
	back_trace('NOTICE', 'twf_google_speech_to_text: '.print_r($body,true));
    return $body['results'][0]['alternatives'][0]['transcript'] ?? false;
}
