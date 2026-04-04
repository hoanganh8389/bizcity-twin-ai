<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;
// file content
function biz_create_content($message, $chat_id, $title='', $image_url='', $arr= array()) {
    return twf_handle_post_request($message, $chat_id, $title, $image_url, $arr);
}

// Xử lý hình ảnh upload
function twf_handle_image_flow($message, $chat_id) {
    // Toàn bộ code xử lý image flow ở đây.
	back_trace('NOTICE', 'Step3: Flow image ');
	$photo = end($message['photo']);
	$file_id = $photo['file_id'];
	$image_url = twf_telegram_get_file_url($file_id);
	$vision_desc = twf_openai_vision_analyze($image_url);
	$api_key = get_option('twf_openai_api_key');
	$post_content = chatbot_chatgpt_call_omni_tele($api_key, $vision_desc);
	$post_title = twf_generate_title_from_content($post_content);
	$post_id = twf_wp_create_post($post_title, $post_content, $image_url);
	
	return;
}

// Xử lý viết bài nếu có "viết bài"
/*
function twf_handle_post_request($message, $chat_id) {
    // Toàn bộ code xử lý yêu cầu viết bài ở đây.
	back_trace('NOTICE', ' step3: Flow text ');
	$text = $message['text'];
	
	$api_key = get_option('twf_openai_api_key');
	$post_content = chatbot_chatgpt_call_omni_tele($api_key, $text);
	#back_trace('NOTICE', ' step3.1: Flow content '.$post_content);
	$post_title = twf_generate_title_from_content($post_content);
	$image_url = twf_generate_image_url($post_title);
	#back_trace('NOTICE', ' step3.3: Flow image_url '.$image_url);
	 
	$post_id = twf_wp_create_post($post_title, $post_content, $image_url);
	twf_telegram_send_message($chat_id, "Bài đã đăng: ".get_permalink($post_id));
	return;
}*/
function ai_generate_content($text) {
    $api_key = get_option('twf_openai_api_key');
     $response  = chatbot_chatgpt_call_omni_tele($api_key, $text);
    #back_trace('NOTICE', 'STEP3.2: response: '.$response);
    // Parse kết quả trả về thành JSON/mảng
	$fields = twf_parse_post_fields_from_ai($response);
    return $fields;
}
function twf_handle_post_request($message, $chat_id, $title='', $image_url='', $arr= array()) {
    #back_trace('NOTICE', 'STEP3: Flow post hoặc lên lịch');
    if($message['caption']) $text = $message['caption'];
	else $text = $message['text'];

	
	$prompt = 'Hãy viết một bài blog hoàn chỉnh bằng tiếng Việt, dạng văn xuôi, ít nhất 700 từ, chia đoạn rõ ràng theo ý tưởng dưới đây, văn phong nhẹ nhàng và chuyên nghiệp, thân thiện người đọc. 

	- Không sử dụng các ký hiệu markdown như ##, ###, *, -, — cho list, heading, tiêu đề phụ hoặc các phần bôi đậm/in nghiêng.
	- Thay vào đó, sử dụng các thẻ HTML như <b>, <strong>, <em>, <mark> để bôi đậm, in nghiêng, HIGHLIGHT các thông tin cần nhấn mạnh và giúp bài viết đẹp, hiện đại, dễ đọc trên web.
	- Heading, tiêu đề phụ hãy đặt bằng các thẻ <b> hoặc <strong> và xuống dòng rõ ràng, KHÔNG dùng ký hiệu đầu dòng.
	- Nếu muốn tạo nhóm ý, trình bày thành đoạn riêng biệt, dùng văn bản thông thường, có thể nhấn mạnh hoặc in nghiêng từng ý bằng <em> hoặc <strong>, KHÔNG dùng dấu chấm đầu dòng/bullet hoặc dash.
	- Cuối bài nên có lời kêu gọi hành động (“call to action”).
	- Tuyệt đối không chèn bất cứ đoạn mã code, không có chú thích thêm ngoài bài viết, không dùng markdown.
	
	Hãy trả về đúng JSON như sau:
	{
	  "title": "Tiêu đề bài viết ngắn gọn, sáng tạo",
	  "content": "Nội dung bài viết (có thể sử dụng HTML)"
	}
	
	Nội dung/yêu cầu chủ đề: ';
	$title = $arr['info']['title'];
	$content = $arr['info']['content'];
	$text = $prompt . $title. ': ' .$content;	
    $api_key = get_option('twf_openai_api_key');

    // Nếu gửi kèm ảnh từ Telegram
    if (isset($message['photo'])) {
        back_trace('NOTICE', 'Có ảnh từ Telegram, upload vào Media...');
        // Lấy file lớn nhất/tốt nhất
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        $tg_image_url = twf_telegram_get_file_url($file_id);

        // Đẩy ảnh lên Media của WP
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $tmp = download_url($tg_image_url);
        if(!is_wp_error($tmp)) {
            $file = [
                'name'     => basename(parse_url($tg_image_url, PHP_URL_PATH)),
                'type'     => 'image/jpeg', // Hoặc khéo léo getimagesize nếu muốn
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => filesize($tmp),
            ];
            $attach_id = media_handle_sideload($file, 0); // 0 = không liên kết post trước, lát nữa sẽ set thumbnail sau
            if(!is_wp_error($attach_id)) {
                $image_url = wp_get_attachment_url($attach_id); // Dùng làm thumbnail
            }
            @unlink($tmp);
        }
    }

    // --- ĐĂNG BÀI NGAY ---
    #back_trace('NOTICE', 'STEP3.1: prompt: '.$text);
    #$response  = chatbot_chatgpt_call_omni_tele($api_key, $text);
    #back_trace('NOTICE', 'STEP3.2: response: '.$response);
    // Parse kết quả trả về thành JSON/mảng
	$fields = ai_generate_content($text);
    back_trace('NOTICE', 'STEP3.3: fields: '.print_r($fields, true));
    $post_title   = $fields['title'];
    $post_content = $fields['content'];

    // Nếu không có ảnh đến từ Telegram, mới dùng AI để tạo ảnh
    back_trace('NOTICE', 'STEP3.4: image_url trước khi tạo: '.$image_url);


    if (!twf_is_valid_image_url($image_url)) {
        $image_url = twf_generate_image_url(
            $post_title.' — cinematic, soft natural light, clean background, ultra-detailed'
        );
        back_trace('NOTICE', 'STEP3.4: image_url (AI generate): '.$image_url);
    }

    $post_id = twf_wp_create_post($post_title, $post_content, $image_url, $chat_id);

    twf_telegram_send_message($chat_id, 
		"✅ Bài đã đăng: " . get_permalink($post_id) . 
		"\n✏️ Link sửa bài: " . admin_url("post.php?post={$post_id}&action=edit")
	);
    return $post_id ;
}

function twf_is_valid_image_url($url) {
    if (empty($url) || !is_string($url)) return false;

    // Kiểm tra URL hợp lệ
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Check 1: đuôi file trong path (e.g. /images/photo.jpg)
    $path = parse_url($url, PHP_URL_PATH);
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, $allowed, true)) return true;

    // Check 2: đuôi file xuất hiện trong query string
    // Handles Next.js (?url=...photo.png), CDN (?file=img.webp), etc.
    $query = parse_url($url, PHP_URL_QUERY);
    if ($query) {
        foreach ($allowed as $e) {
            if (stripos($query, '.' . $e) !== false) return true;
        }
    }

    return false;
}

// Xử lý trả lời chat thông thường — route qua BizCity_Chat_Gateway (profile + knowledge + transit)
function twf_handle_chat_flow($message, $chat_id) {
    $user_text = $message['text'] ?? '';
    if (!$chat_id || empty($user_text)) return;

    // ── Detect platform từ chat_id ──
    // Thứ tự check quan trọng: zalobot_ TRƯỚC zalo_ (vì zalobot_ không match zalo_)
    $is_zalobot  = (strpos((string) $chat_id, 'zalobot_') === 0);
    $is_zalo     = !$is_zalobot && (strpos((string) $chat_id, 'zalo_') === 0);
    $is_adminchat = (strpos((string) $chat_id, 'adminchat_') === 0);
    // Nếu chat_id là số thuần (Zalo client_id qua twf_process_flow_from_params), check thêm
    if (!$is_zalo && !$is_zalobot && is_numeric($chat_id) && strlen((string) $chat_id) > 10) {
        // Zalo client_id thường >10 chữ số, Telegram chat_id thường <10 chữ số
        $is_zalo = true;
    }

    // ── Thử route qua Chat Gateway (nhất quán với ADMINCHAT) ──
    if (class_exists('BizCity_Chat_Gateway')) {
        // Resolve WP user_id từ Telegram/Zalo chat_id
        $wp_user_id = get_current_user_id(); // Có thể đã được set ở Zalo bootstrap
        if (!$wp_user_id && function_exists('twf_get_user_id_by_chat_id')) {
            $wp_user_id = (int) twf_get_user_id_by_chat_id($chat_id);
        }

        // Set WP user context để gateway có thể dùng get_current_user_id()
        $old_user_id = get_current_user_id();
        if ($wp_user_id && $wp_user_id !== $old_user_id) {
            wp_set_current_user($wp_user_id);
        }

        if ($is_zalobot) {
            $detected_platform_label = 'ZALO_BOT';
        } elseif ($is_zalo) {
            $detected_platform_label = 'ZALO';
        } elseif ($is_adminchat) {
            $detected_platform_label = 'ADMINCHAT';
        } else {
            $detected_platform_label = 'TELEGRAM';
        }
        error_log(sprintf('[twf_handle_chat_flow] chat_id=%s, platform=%s, wp_user_id=%d', $chat_id, $detected_platform_label, $wp_user_id));

        // Lấy character mặc định
        $gateway = BizCity_Chat_Gateway::instance();
        $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
        if (!$character_id) {
            $opts = get_option('pmfacebook_options', []);
            $character_id = isset($opts['default_character_id']) ? intval($opts['default_character_id']) : 0;
        }
        if (!$character_id && class_exists('BizCity_Knowledge_Database')) {
            $chars = BizCity_Knowledge_Database::instance()->get_characters(['status' => 'active', 'limit' => 1]);
            if (!empty($chars)) $character_id = $chars[0]->id;
        }

        // Session ID & platform: detect từ nguồn gốc
        if ($is_zalobot) {
            // zalobot_{bot_id}_{user_id} → session riêng cho từng bot
            $session_id    = 'zalobot_' . get_current_blog_id() . '_' . $chat_id;
            $platform_type = 'ZALO_BOT';
        } elseif ($is_zalo) {
            $zalo_id = str_replace('zalo_', '', (string) $chat_id);
            $session_id    = 'zalo_' . get_current_blog_id() . '_' . $zalo_id;
            $platform_type = 'ZALO_PERSONAL';
        } elseif ($is_adminchat) {
            $session_id    = (string) $chat_id; // adminchat_ đã có blog context
            $platform_type = 'ADMINCHAT';
        } else {
            $session_id    = 'telegram_' . get_current_blog_id() . '_' . $chat_id;
            $platform_type = 'TELEGRAM';
        }
        back_trace('NOTICE', "Routing chat_id={$chat_id} with wp_user_id={$wp_user_id} to gateway with session_id={$session_id} and platform_type={$platform_type}");
        // Log tin nhắn user vào DB (để gateway load history cho các lần chat tiếp)
        twf_gateway_log_message($session_id, $wp_user_id, $user_text, 'user', $platform_type);

        // ── Gửi "typing" indicator để user thấy bot đang xử lý ──
        if ( $platform_type === 'TELEGRAM' ) {
            $tg_token = get_option( 'twf_bot_token' );
            if ( $tg_token ) {
                wp_remote_post( "https://api.telegram.org/bot{$tg_token}/sendChatAction", [
                    'body'    => [ 'chat_id' => $chat_id, 'action' => 'typing' ],
                    'timeout' => 5,
                ] );
            }
        }

        try {
            // Vision image: truyền từ unified pipeline qua global (ảnh Zalo/Telegram)
            $vision_images = [];
            $pending_vision = isset( $GLOBALS['bizgpt_pending_vision_url'] ) ? (string) $GLOBALS['bizgpt_pending_vision_url'] : '';
            error_log( '[twf_handle_chat_flow] bizgpt_pending_vision_url=' . ( $pending_vision ?: '(empty)' ) );
            if ( ! empty( $pending_vision ) ) {
                $vision_images = [ $pending_vision ];
                unset( $GLOBALS['bizgpt_pending_vision_url'] ); // consume once
            }
            error_log( '[twf_handle_chat_flow] vision_images count=' . count( $vision_images ) );

            /* ── Streaming approach: gửi từng chunk khi AI đang generate ──
             *
             * Thay vì chờ toàn bộ reply rồi gửi 1 lần (user đợi 5-15s),
             * dùng OpenRouter streaming → gửi mỗi ~120 từ.
             *
             * Nếu streaming không sẵn sàng → fallback get_ai_response() + split.
             */
            $reply = '';
            $reply_data = [];

            // Prepare LLM call (build context + messages — same pipeline as get_ai_response)
            // ── Resolve channel role before prepare_llm_call ──
            if ( class_exists( 'BizCity_Channel_Role' ) ) {
                // Prefer role already resolved by bizgpt_process_unified_message
                if ( ! empty( $GLOBALS['bizcity_channel_role'] ) ) {
                    $ch_role = $GLOBALS['bizcity_channel_role'];
                } else {
                    $bot_id_for_role = null;
                    if ( $is_zalobot && preg_match( '/^zalobot_(\d+)_/', $chat_id, $rm ) ) {
                        $bot_id_for_role = (int) $rm[1];
                    }
                    $ch_role = BizCity_Channel_Role::resolve( $platform_type, $bot_id_for_role, $wp_user_id );
                }
                $gateway->current_channel_role = $ch_role['definition'] ?? [];
                // Override KCI if role locks it
                if ( ! empty( $ch_role['definition']['kci_locked'] ) ) {
                    $gateway->current_kci_ratio = (int) ( $ch_role['definition']['kci_ratio'] ?? 100 );
                }
            }

            $prepared = $gateway->prepare_llm_call( $character_id, $user_text, $vision_images, $session_id, '[]', $wp_user_id, $platform_type );

            if ( isset( $prepared['error'] ) ) {
                $reply = $prepared['error']['message'] ?? 'Xin lỗi, có lỗi xảy ra.';
                twf_telegram_send_message( $chat_id, $reply );
                if ( $old_user_id !== $wp_user_id ) wp_set_current_user( $old_user_id );
                return;
            }

            $llm_character = $prepared['character'];
            $llm_messages  = $prepared['messages'];
            $result_base   = $prepared['result_base'];

            // ── Try streaming (real-time chunked delivery) ──
            if ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
                $chunk_words     = 120; // words per chunk
                $stream_sent_pos = 0;
                $stream_chat_id  = $chat_id;

                $model_opts = [
                    'purpose'     => 'chat',
                    'max_tokens'  => 3000,
                    'temperature' => ( $llm_character && isset( $llm_character->creativity_level ) )
                        ? floatval( $llm_character->creativity_level ) : 0.7,
                ];
                if ( $llm_character && ! empty( $llm_character->model_id ) ) {
                    $model_opts['model'] = $llm_character->model_id;
                }

                $stream_result = bizcity_openrouter_chat_stream(
                    $llm_messages,
                    $model_opts,
                    function ( $delta, $full_text ) use ( $stream_chat_id, &$stream_sent_pos, $chunk_words ) {
                        $unsent     = mb_substr( $full_text, $stream_sent_pos, null, 'UTF-8' );
                        $word_count = preg_match_all( '/\S+/u', $unsent );
                        if ( $word_count >= $chunk_words ) {
                            $boundary = function_exists( 'twf_stream_find_chunk_boundary' )
                                ? twf_stream_find_chunk_boundary( $unsent, $chunk_words )
                                : $unsent;
                            if ( ! empty( trim( $boundary ) ) ) {
                                twf_telegram_send_message( $stream_chat_id, trim( $boundary ) );
                                $stream_sent_pos += mb_strlen( $boundary, 'UTF-8' );
                            }
                        }
                    }
                );

                $reply = $stream_result['message'] ?? '';

                // Send remaining text that wasn't chunked yet
                $remaining = trim( mb_substr( $reply, $stream_sent_pos, null, 'UTF-8' ) );
                if ( ! empty( $remaining ) ) {
                    twf_telegram_send_message( $chat_id, $remaining );
                }

                $reply_data = [
                    'message'        => $reply,
                    'character_name' => $result_base['character_name'] ?? 'AI Assistant',
                    'provider'       => $stream_result['provider'] ?? 'openrouter',
                    'model'          => $stream_result['model'] ?? '',
                ];
            } else {
                // ── Fallback: synchronous (get full reply then send) ──
                $reply_data = $gateway->get_ai_response( $character_id, $user_text, $vision_images, $session_id, '[]', $wp_user_id, $platform_type );
                $reply = $reply_data['message'] ?? '';
                if ( $reply ) {
                    twf_telegram_send_message( $chat_id, $reply );
                }
            }

            if ($reply) {
                back_trace('NOTICE', "Gateway trả lời: " . $reply);
                // Log bot reply to webchat_messages (central history store)
                $bot_name = $reply_data['character_name'] ?? 'AI Assistant';
                twf_gateway_log_message($session_id, 0, $reply, 'bot', $platform_type, $bot_name, [
                    'provider'     => $reply_data['provider'] ?? '',
                    'model'        => $reply_data['model'] ?? '',
                    'character_id' => $character_id,
                ]);

                // ── Fire unified action for Bot Agent global logger ──
                do_action('bizcity_chat_message_processed', [
                    'platform_type' => $platform_type,
                    'session_id'    => $session_id,
                    'character_id'  => $character_id,
                    'user_id'       => $wp_user_id,
                    'user_message'  => $user_text,
                    'bot_reply'     => $reply,
                    'images'        => $vision_images,
                    'provider'      => $reply_data['provider'] ?? '',
                    'model'         => $reply_data['model'] ?? '',
                ]);

                // Also log bot reply to bizcity_zalo_bot_logs (for memory analysis admin page)
                if (($is_zalobot || $is_zalo) && class_exists('BizCity_Zalo_Bot_Database')) {
                    $log_bot_id  = 0;
                    $log_client  = '';
                    if ($is_zalobot && preg_match('/^zalobot_(\d+)_(.+)$/', (string) $chat_id, $cm)) {
                        $log_bot_id = (int) $cm[1];
                        $log_client = (string) $cm[2];
                    } elseif ($is_zalo) {
                        $log_bot_id = 9999; // placeholder for ZALO_PERSONAL
                        $log_client = is_numeric($chat_id) ? (string) $chat_id : preg_replace('/^zalo_/', '', (string) $chat_id);
                    }
                    if ($log_client) {
                        BizCity_Zalo_Bot_Database::instance()->log_event(
                            $log_bot_id,
                            'bot.reply',
                            ['reply' => $reply, 'character_id' => $character_id, 'model' => $reply_data['model'] ?? ''],
                            $log_client,
                            '',
                            $bot_name,
                            $reply
                        );
                    }
                }

                // Restore user context
                if ($old_user_id !== $wp_user_id) wp_set_current_user($old_user_id);
                return;
            }
        } catch (Exception $e) {
            error_log('[twf_handle_chat_flow] Gateway error: ' . $e->getMessage());
        }

        // Restore user context
        if ($old_user_id !== $wp_user_id) wp_set_current_user($old_user_id);
    }
    back_trace('NOTICE', 'Gateway không trả được phản hồi, fallback sang OpenAI trực tiếp');
    // ── Fallback: gọi OpenAI trực tiếp (khi Gateway chưa sẵn sàng) ──
    $api_key = get_option('twf_openai_api_key');
    $reply = function_exists('bizgpt_chatbot_tele_ai_response')
        ? bizgpt_chatbot_tele_ai_response($api_key, 'Hãy xưng hô bạn là chúng em và người hỏi là sếp. Bạn là nhân viên AI có nhiệm vụ hỗ trợ sếp làm việc. Hãy nói Dạ, chào sếp. ' . $user_text)
        : '';
    twf_telegram_send_message($chat_id, $reply ?: 'Chào sếp! sếp cần em giúp gì ạ?');
}

/**
 * Log message to bizcity_webchat_messages (helper cho Telegram flow)
 * Mirrors BizCity_Chat_Gateway::log_message() nhưng public static
 */
function twf_gateway_log_message($session_id, $user_id, $text, $from = 'user', $platform_type = 'TELEGRAM', $client_name = '', $meta = []) {
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_webchat_messages';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return;

    if (!$client_name) {
        if ($from === 'user' && $user_id) {
            $u = get_userdata($user_id);
            $client_name = $u ? ($u->display_name ?: $u->user_login) : 'Telegram User';
        } else {
            $client_name = ($from === 'bot') ? 'AI Assistant' : 'Telegram User';
        }
    }

    $wpdb->insert($table, [
        'session_id'    => $session_id,
        'user_id'       => $user_id,
        'client_name'   => $client_name,
        'message_id'    => uniqid('tg_'),
        'message_text'  => $text,
        'message_from'  => $from,
        'message_type'  => 'text',
        'platform_type' => $platform_type,
        'meta'          => !empty($meta) ? wp_json_encode($meta) : null,
        'created_at'    => current_time('mysql'),
    ]);

    // Fire hook for global logger (bizcity-bot-agent)
    do_action('bizcity_webchat_message_saved', [
        'session_id'    => $session_id,
        'user_id'       => $user_id,
        'client_name'   => $client_name,
        'message_text'  => $text,
        'message_from'  => $from,
        'message_type'  => 'text',
        'platform_type' => $platform_type,
        'meta'          => $meta,
        'blog_id'       => get_current_blog_id(),
    ]);
}


function twf_wp_create_post($post_title, $post_content, $image_url = '', $chat_id = null) {
    $postarr = [
        'post_title'   => wp_strip_all_tags($post_title),
        'post_content' => $post_content,
        'post_status'  => 'publish',
        'post_author'  => 1 // Hoặc theo user quản trị
    ];
    $post_id = wp_insert_post($postarr);

    if ($post_id && !empty($image_url)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Auto-detect MIME type from URL extension
        $url_path  = parse_url( $image_url, PHP_URL_PATH );
        $ext       = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );

        // Fallback: extract extension from query string (e.g. Next.js ?url=...photo.png)
        if ( empty( $ext ) ) {
            $url_query = parse_url( $image_url, PHP_URL_QUERY );
            if ( $url_query && preg_match( '/\.(jpg|jpeg|png|gif|webp)/i', $url_query, $m ) ) {
                $ext = strtolower( $m[1] );
            }
        }

        $mime_map  = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ];
        $mime_type = $mime_map[ $ext ] ?? 'image/jpeg';
        $filename  = basename( $url_path );
        if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
            $filename = 'image-' . time() . ( $ext ? '.' . $ext : '.jpg' );
            if ( empty( $ext ) ) $mime_type = 'image/jpeg';
        }

        // Try WordPress's download_url first
        $tmp = download_url( $image_url, 30 );

        // Fallback: wp_remote_get with browser User-Agent (for CDN/hotlink-protected URLs)
        if ( is_wp_error( $tmp ) ) {
            back_trace( 'WARNING', 'twf_wp_create_post: download_url failed – ' . $tmp->get_error_message() . ' – trying fallback for: ' . $image_url );
            $response = wp_remote_get( $image_url, [
                'timeout'    => 30,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'headers'    => [ 'Referer' => home_url( '/' ) ],
            ] );
            if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
                $tmp = wp_tempnam( $filename );
                file_put_contents( $tmp, wp_remote_retrieve_body( $response ) );
            } else {
                back_trace( 'ERROR', 'twf_wp_create_post: fallback wp_remote_get also failed for: ' . $image_url );
                $tmp = null;
            }
        }

        if ( $tmp && file_exists( $tmp ) ) {
            $file = [
                'name'     => $filename,
                'type'     => $mime_type,
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => filesize( $tmp ),
            ];
            $attach_id = media_handle_sideload( $file, $post_id );

            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
            } else {
                back_trace( 'ERROR', 'twf_wp_create_post: media_handle_sideload failed – ' . $attach_id->get_error_message() );
            }
            @unlink( $tmp );
        }
    }
    #if($chat_id) twf_telegram_send_message($chat_id, "Bài đã đăng: ".get_permalink($post_id));
    $id_fb = twf_post_to_facebook($post_title, get_permalink($post_id), $image_url, $post_content);
    return $post_id;
}



function twf_generate_title_from_content($content) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return 'Bài đăng từ Telegram AI';

    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $messages = [
        [
            'role' => 'system',
            'content' => 'Bạn là AI chuyên tạo tiêu đề hay, súc tích, hấp dẫn cho bài viết blog tiếng Việt.'
        ],
        [
            'role' => 'user',
            'content' => 'Tạo 1 tiêu đề duy nhất, ngắn, hấp dẫn tóm tắt nội dung sau thành 8 đến 10 từ tiếng Việt, không vượt quá 90 ký tự. Chỉ trả về tiêu đề, không có gì khác:' . "\n\n" . $content
        ]
    ];
    $payload = [
        'model' => 'gpt-4.1-nano',
        'messages' => $messages,
        'max_tokens' => 40
    ];
    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($payload)
    ];
    $response = wp_remote_post($endpoint, $args);
    if (is_wp_error($response)) return 'Bài đăng từ Telegram AI';
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return trim($body['choices'][0]['message']['content'] ?? 'Bài đăng từ Telegram AI');
}


//1. Hàm đăng status hoặc ảnh lên Fanpage Facebook
function twf_post_to_facebook($msg, $link = '', $image_url = '', $post_content = '') {
    $facebook_access_token = get_option('twf_facebook_access_token');
    $facebook_page_id      = get_option('twf_facebook_page_id');
    if (empty($facebook_access_token) || empty($facebook_page_id)) {
        return false;
    }

    // Lấy 50-200 ký tự đầu tiên (giữ không cắt chữ)
    $short_content = '';
    if (!empty($post_content)) {
        $short_content = wp_strip_all_tags($post_content); // loại bỏ thẻ HTML
		$short_content = mb_substr($short_content, 0, 250);
		// Cắt đến dấu cách cuối cùng để không vỡ từ
		$short_content = mb_substr($short_content, 0, mb_strrpos($short_content, ' '));
		$short_content .= '...';
        
        // Nếu ngắn hơn 50 ký tự vẫn giữ nguyên
    }

    // Ghép đoạn mô tả + link
    #$msg_final = trim($msg . "\n\n" . $short_content . "\n\n" . $link);
	$msg_final = trim($short_content . "\n\n" . $link);
    if ($image_url) {
        // Đăng ảnh kèm caption
        $endpoint = "https://graph.facebook.com/{$facebook_page_id}/photos";
        $post_args = array(
            'caption'      => $msg_final,
            'url'          => $image_url,
            'access_token' => $facebook_access_token,
        );
    } else {
        // Đăng status có link hoặc không có ảnh
        $endpoint = "https://graph.facebook.com/{$facebook_page_id}/feed";
        $post_args = array(
            'message'      => $msg_final,
            'access_token' => $facebook_access_token,
        );
    }

    $response = wp_remote_post($endpoint, array(
        'body' => $post_args,
        'timeout' => 30,
    ));
    if (is_wp_error($response)) {
        return false;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['id']) ? $body['id'] : false;
}


function twf_list_latest_posts($post_type = 'post', $limit = 10) {
    $posts = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if (empty($posts)) {
        $output .= "Không tìm thấy bài viết nào.";
    } else {
        foreach ($posts as $p) {
            $title = esc_html($p->post_title);
            $view_link = get_permalink($p->ID);
            $edit_link = admin_url("post.php?post={$p->ID}&action=edit");
            $output .= "📝 <b>{$title}</b>\n";
            $output .= "📎 <a href=\"{$view_link}\">Xem</a> | ✏️ <a href=\"{$edit_link}\">Sửa</a>\n\n";
        }
    }

    // Link mở quản trị tìm kiếm toàn bộ bài viết theo từ khóa
    $admin_search_url = admin_url("edit.php?post_type={$post_type}&s=" . urlencode($keyword));
    $output .= "📚 <a href=\"{$admin_search_url}\">Xem tất cả bài viết trong quản trị</a>";

    return $output;
}

function twf_search_posts_with_keyword($keyword, $post_type = 'post', $limit = 10) {
    if (empty($keyword)) return "❗ Vui lòng nhập từ khóa để tìm.";

    $posts = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        's'              => $keyword,
        'posts_per_page' => $limit,
    ]);

    $output = "🔎 Kết quả tìm kiếm với từ khóa: <b>{$keyword}</b>\n\n";

    if (empty($posts)) {
        $output .= "Không tìm thấy bài viết nào.";
    } else {
        foreach ($posts as $p) {
            $title = esc_html($p->post_title);
            $view_link = get_permalink($p->ID);
            $edit_link = admin_url("post.php?post={$p->ID}&action=edit");
            $output .= "📝 <b>{$title}</b>\n";
            $output .= "📎 <a href=\"{$view_link}\">Xem</a> | ✏️ <a href=\"{$edit_link}\">Sửa</a>\n\n";
        }
    }

    // Link mở quản trị tìm kiếm toàn bộ bài viết theo từ khóa
    $admin_search_url = admin_url("edit.php?post_type={$post_type}&s=" . urlencode($keyword));
    $output .= "📚 <a href=\"{$admin_search_url}\">Xem tất cả bài viết chứa từ khóa trong quản trị</a>";

    return $output;
}


function twf_parse_post_fields_from_ai($response) {
    if (!is_string($response)) $response = strval($response);

    // 1) Chuẩn hoá nhẹ: bỏ code fence, ngoặc kép cong, trim
    $clean = trim($response);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/```$/', '', $clean);
    $clean = str_replace(['“','”'], '"', $clean);

    // 2) Thử decode trực tiếp
    $parsed = json_decode($clean, true);
    if (is_array($parsed) && (isset($parsed['title']) || isset($parsed['content']))) {
        return [
            'title'   => $parsed['title']   ?? 'Bài viết mới',
            'content' => twf_clean_post_content($parsed['content'] ?? '')
        ];
    }

    // 3) Cắt khối JSON đầu tiên (đơn giản) rồi decode lại
    if (preg_match('/\{[\s\S]*\}/', $clean, $m)) {
        $json = $m[0];
        $parsed = json_decode($json, true);
        if (is_array($parsed) && (isset($parsed['title']) || isset($parsed['content']))) {
            return [
                'title'   => $parsed['title']   ?? 'Bài viết mới',
                'content' => twf_clean_post_content($parsed['content'] ?? '')
            ];
        }
    }

    // 4) Regex fallback: extract title and content từ JSON-like structure
    //    Xử lý trường hợp AI trả JSON với content multi-line (json_decode fail)
    $title = 'Bài viết mới';
    $content = '';
    
    // Extract title: "title": "..."
    if (preg_match('/"title"\s*:\s*"([^"]+)"/u', $clean, $tm)) {
        $title = trim($tm[1]);
    }
    
    // Extract content: "content": "..." hoặc "content": " ... (đến cuối hoặc cuối JSON)
    // Pattern: tìm "content": " rồi lấy mọi thứ sau đó
    if (preg_match('/"content"\s*:\s*"(.*)$/su', $clean, $cm)) {
        $content = $cm[1];
        // Remove trailing " hoặc "} nếu có
        $content = preg_replace('/"\s*\}?\s*$/', '', $content);
        // Unescape JSON escapes
        $content = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $content);
    }
    
    if (!empty($content)) {
        return [
            'title'   => $title,
            'content' => twf_clean_post_content($content)
        ];
    }

    // 5) Final fallback: loại JSON wrapper ra khỏi nội dung
    $fallback = $clean;
    // Remove JSON prefix like {"title": "...", "content": "
    $fallback = preg_replace('/^\s*\{\s*"title"\s*:\s*"[^"]*"\s*,\s*"content"\s*:\s*"\s*/u', '', $fallback);
    // Remove trailing "}
    $fallback = preg_replace('/"\s*\}\s*$/u', '', $fallback);

    return [
        'title'   => $title,
        'content' => twf_clean_post_content($fallback ?: $clean)
    ];
}

/**
 * Clean post content:
 * - Convert literal \n to real newlines
 * - Remove leading/trailing "nn" artifacts from AI
 * - Normalize multiple newlines
 */
function twf_clean_post_content($content) {
    if (empty($content)) return '';
    
    // Convert literal \n (backslash + n) to real newlines
    $content = str_replace(['\\n\\n', '\\n'], ["\n\n", "\n"], $content);
    
    // Remove standalone "nn" that appears at start/end or between HTML tags
    // AI sometimes outputs "nn" instead of "\n\n"
    $content = preg_replace('/^nn+/u', '', $content);
    $content = preg_replace('/nn+$/u', '', $content);
    $content = preg_replace('/(>)\s*nn\s*(<)/u', "$1\n\n$2", $content);
    $content = preg_replace('/nn\s*(<[hH][1-6]>)/u', "\n\n$1", $content);
    $content = preg_replace('/(<\/[hH][1-6]>)\s*nn/u', "$1\n\n", $content);
    
    // Normalize multiple newlines (max 2)
    $content = preg_replace("/\n{3,}/", "\n\n", $content);
    
    return trim($content);
}