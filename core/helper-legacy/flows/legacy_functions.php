<?php


function twf_telegram_get_file_url($file_id) {
    $bot_token = get_option('twf_bot_token');
    if (!$bot_token) return false;
    // Lấy đường dẫn file Telegram
    $get_file_url = "https://api.telegram.org/bot{$bot_token}/getFile?file_id=$file_id";
    $response = wp_remote_get($get_file_url);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['result']['file_path'])) return false;
    $file_path = $body['result']['file_path'];
    // Trả về link tải file
    return "https://api.telegram.org/file/bot{$bot_token}/$file_path";
}


function twf_openai_speech_to_text($voice_url) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return false;

    // Tải file âm thanh về tạm thời
    $voice_data = wp_remote_get($voice_url);
    if (is_wp_error($voice_data)) return false;

    $tmpfname = tempnam(sys_get_temp_dir(), "tgvoice");
    file_put_contents($tmpfname, wp_remote_retrieve_body($voice_data));

    $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
    $boundary = wp_generate_password(24, false);

    $filename = basename($tmpfname) . ".ogg";
    $multipart_body = "--$boundary\r\n";
    $multipart_body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
    $multipart_body .= "Content-Type: audio/ogg\r\n\r\n";
    $multipart_body .= file_get_contents($tmpfname) . "\r\n";
    $multipart_body .= "--$boundary\r\n";
    $multipart_body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $multipart_body .= "whisper-1\r\n";
    $multipart_body .= "--$boundary--\r\n";

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'content-type' => 'multipart/form-data; boundary=' . $boundary
        ],
        'body' => $multipart_body,
        'timeout' => 80
    ];

    $response = wp_remote_post($endpoint, $args);
    unlink($tmpfname);

    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['text'] ?? false;
}


/**
 * Lưu ảnh base64 vào Media, trả về ['attach_id'=>int,'url'=>string] hoặc WP_Error.
 */
function twf_save_base64_image_to_media($b64, $filename = 'ai-image.png') {
    $bin = base64_decode($b64);
    if (!$bin) return new WP_Error('b64_decode_failed', 'Cannot decode base64 image');

    require_once(ABSPATH.'wp-admin/includes/image.php');
    require_once(ABSPATH.'wp-admin/includes/file.php');
    require_once(ABSPATH.'wp-admin/includes/media.php');

    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error'])) {
        return new WP_Error('upload_dir_error', $upload_dir['error']);
    }

    // Dùng .png vì đa phần API trả PNG
    $target = trailingslashit($upload_dir['path']).sanitize_file_name($filename);
    if (file_put_contents($target, $bin) === false) {
        return new WP_Error('write_failed', 'Cannot write image file');
    }

    $filetype  = wp_check_filetype($target, null);
    $file_arr = [
        'name'     => basename($target),
        'type'     => $filetype['type'] ?: 'image/png',
        'tmp_name' => $target,
        'error'    => 0,
        'size'     => filesize($target),
    ];

    $attach_id = media_handle_sideload($file_arr, 0);
    @unlink($target);

    if (is_wp_error($attach_id)) return $attach_id;

    return ['attach_id'=>$attach_id, 'url'=>wp_get_attachment_url($attach_id)];
}

/**
 * Tạo ảnh bằng OpenAI. Trả về URL (ưu tiên lưu Media nếu response là b64_json).
 * Nếu thất bại: return false.
 */
function ai_generate_image_id($prompt, $post_id = 0) {
    $image_url  = twf_generate_image_url($prompt);
     if (!empty($image_url)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $tmp = download_url($image_url);

        if(!is_wp_error($tmp)) {
            $file = [
                'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
                'type'     => 'image/jpeg',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => filesize($tmp),
            ];
            $attach_id = media_handle_sideload($file, $post_id);

            if(!is_wp_error($attach_id)) {
                set_post_thumbnail($post_id, $attach_id);
            }
            @unlink($tmp);
        }
        return $attach_id;
    }
}
function biz_generate_image($prompt) {
    return twf_generate_image_url($prompt);
}
function twf_generate_image_url($prompt) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return false;

    $endpoint = "https://api.openai.com/v1/images/generations";
    $data = [
        "model"  => "gpt-image-1",
        "prompt" => $prompt,
        "n"      => 1,
        "size"   => "1024x1024"
        // Không gửi response_format/style/quality để tránh 400
    ];
    $args = [
        'headers' => [
            'Authorization' => 'Bearer '.$api_key,
            'Content-Type'  => 'application/json'
        ],
        'body'    => wp_json_encode($data),
        'timeout' => 80
    ];

    $res  = wp_remote_post($endpoint, $args);
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    back_trace('NOTICE', 'twf_generate_image_url: HTTP '.$code.' | body='.substr($body,0,200));

    if (is_wp_error($res) || $code !== 200) return false;

    $json = json_decode($body, true);
    if (empty($json['data'][0])) return false;

    // 1) Nếu API trả URL (một số cấu hình có thể như vậy)
    if (!empty($json['data'][0]['url'])) {
        return $json['data'][0]['url'];
    }

    // 2) Mặc định hiện tại đang trả b64_json → lưu vào Media rồi trả URL
    if (!empty($json['data'][0]['b64_json'])) {
        $saved = twf_save_base64_image_to_media(
            $json['data'][0]['b64_json'],
            'ai-image-'.time().'.png'
        );
        if (!is_wp_error($saved)) {
            return $saved['url'];
        }
    }

    return false;
}


function twf_perplexity_search_get_content($text, $n = 5) {
    $api_key = get_option('twf_perplexity_api_key');
    if (!$api_key) return false;
    // Đây là ví dụ template. Hãy thay thế endpoint, payload theo tài liệu Perplexity chính xác của bạn!
    $endpoint = "https://api.perplexity.ai/api/v1/search"; // ĐIỀN endpoint thật nếu khác
    $payload = [
        "query" => $text,
        "num_results" => $n,
    ];
    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($payload)
    ];
    $response = wp_remote_post($endpoint, $args);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Tùy cấu trúc trả về của Perplexity, map lại phù hợp!
    if (isset($body['results']) && is_array($body['results'])) {
        $content = "";
        foreach ($body['results'] as $item) {
            if (isset($item['content'])) { // hoặc ['snippet'] tùy api
                $content .= $item['content'] . "\n---\n";
            }
        }
        return trim($content);
    }
    return false;
}


/* =====================================================================
 * STREAMING CHUNKED REPLY — gửi AI response từng đoạn 100-200 từ
 *
 * Thay thế pattern cũ:
 *   $reply = chatbot_chatgpt_call_omni_tele($key, $prompt);
 *   biz_send_message($chat_id, $reply);
 *
 * Pattern mới:
 *   $reply = twf_ai_stream_reply($chat_id, $system, $user_msg);
 *
 * @param  string|int $chat_id     Telegram chat_id hoặc Zalo user_id.
 * @param  string     $system      System prompt.
 * @param  string     $user_msg    User message.
 * @param  array      $opts        Options: chunk_words (default 150), max_tokens, temperature, purpose.
 * @return string     Full accumulated response text.
 * ===================================================================== */
function twf_ai_stream_reply( $chat_id, $system, $user_msg, $opts = [] ) {
    $chunk_words = isset( $opts['chunk_words'] ) ? intval( $opts['chunk_words'] ) : 150;
    unset( $opts['chunk_words'] );

    $messages = [
        [ 'role' => 'system', 'content' => $system ],
        [ 'role' => 'user',   'content' => $user_msg ],
    ];

    $stream_opts = array_merge( [
        'purpose'     => 'chat',
        'max_tokens'  => 1200,
        'temperature' => 0.8,
    ], $opts );

    // ── Fallback: nếu streaming không sẵn sàng, gửi toàn bộ 1 lần ──
    if ( ! function_exists( 'bizcity_openrouter_chat_stream' ) ) {
        $full_text = '';
        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $result    = bizcity_openrouter_chat( $messages, $stream_opts );
            $full_text = ! empty( $result['success'] ) ? $result['message'] : 'Lỗi AI';
        } else {
            $full_text = chatbot_chatgpt_call_omni_tele( null, "SYSTEM:\n{$system}\n\nUSER:\n{$user_msg}" );
        }
        biz_send_message( $chat_id, $full_text );
        return $full_text;
    }

    // ── Streaming: tích luỹ token, gửi chunk khi đủ word count ──
    $sent_pos = 0;

    $result = bizcity_openrouter_chat_stream( $messages, $stream_opts,
        function ( $delta, $accumulated ) use ( $chat_id, $chunk_words, &$sent_pos ) {
            $unsent     = mb_substr( $accumulated, $sent_pos, null, 'UTF-8' );
            $word_count = count( preg_split( '/\s+/u', trim( $unsent ), -1, PREG_SPLIT_NO_EMPTY ) );

            if ( $word_count >= $chunk_words ) {
                $chunk = twf_stream_find_chunk_boundary( $unsent, $chunk_words );
                if ( ! empty( trim( $chunk ) ) ) {
                    biz_send_message( $chat_id, trim( $chunk ) );
                    $sent_pos += mb_strlen( $chunk, 'UTF-8' );
                }
            }
        }
    );

    $full_text = isset( $result['message'] ) ? $result['message'] : '';

    // ── Gửi phần còn lại chưa gửi ──
    $remaining = mb_substr( $full_text, $sent_pos, null, 'UTF-8' );
    if ( ! empty( trim( $remaining ) ) ) {
        biz_send_message( $chat_id, trim( $remaining ) );
    }

    return $full_text;
}

/**
 * Tìm ranh giới chunk gần target_words.
 * Ưu tiên cắt ở cuối câu (.!?), ngắt đoạn (\n), hoặc tối đa +30 từ.
 */
function twf_stream_find_chunk_boundary( $text, $target_words ) {
    $tokens = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
    $result = '';
    $count  = 0;

    for ( $i = 0, $len = count( $tokens ); $i < $len; $i++ ) {
        $result .= $tokens[ $i ];
        if ( trim( $tokens[ $i ] ) !== '' ) {
            $count++;
        }
        // Sau khi đủ target, tìm ranh giới câu
        if ( $count >= $target_words && preg_match( '/[.!?。\n]\s*$/u', $result ) ) {
            break;
        }
        // Hard cap: target + 30 từ
        if ( $count >= $target_words + 30 ) {
            break;
        }
    }

    return $result;
}


/* =====================================================================
 * VISION ANALYZE — qua OpenRouter (thay vì direct OpenAI)
 * ===================================================================== */
function twf_openai_vision_analyze($image_url) {
    // ── OpenRouter gateway ──
    if ( function_exists( 'bizcity_openrouter_chat' ) ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Bạn là một trợ lý AI mô tả và phân tích nội dung hình ảnh. Hãy tóm tắt hình ảnh này.',
            ],
            [
                'role'    => 'user',
                'content' => [
                    [ 'type' => 'text',      'text' => 'Phân tích bức ảnh này và đưa ra nhận xét chi tiết:' ],
                    [ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url ] ],
                ],
            ],
        ];
        $result = bizcity_openrouter_chat( $messages, [
            'purpose'    => 'vision',
            'max_tokens' => 800,
        ] );
        return ! empty( $result['success'] ) ? $result['message'] : false;
    }

    // ── Legacy fallback: direct OpenAI ──
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return false;

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode([
            'model'      => 'gpt-4o',
            'messages'   => [
                [ 'role' => 'system', 'content' => 'Bạn là một trợ lý AI mô tả và phân tích nội dung hình ảnh. Hãy tóm tắt hình ảnh này.' ],
                [ 'role' => 'user', 'content' => [
                    [ 'type' => 'text', 'text' => 'Phân tích bức ảnh này và đưa ra nhận xét chi tiết:' ],
                    [ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url ] ],
                ]],
            ],
            'max_tokens' => 800,
        ]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['choices'][0]['message']['content'] ?? false;
}

function chatbot_chatgpt_call_omni_tele($api_key, $message, $is_global = false, $id_kich_ban = false, $id_from_global = false, $kich_ban_lang = false) {
    if ( empty( trim( $message ) ) ) {
        return '⚠️ Bạn chưa nhập chủ đề hoặc yêu cầu cần viết. Vui lòng nhập chi tiết!';
    }

    $system = $is_global
        ? 'Bạn là AI soạn thảo bài blog chuẩn SEO chuyên nghiệp, sáng tạo, tiếng Việt giàu cảm xúc.'
        : 'Bạn là AI soạn thảo blog chuyên nghiệp, tối ưu cho SEO, nội dung sáng tạo, tiếng Việt.';

    // ── OpenRouter gateway (ưu tiên) ──
    if ( function_exists( 'bizcity_openrouter_chat' ) ) {
        $messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $message ],
        ];
        $result = bizcity_openrouter_chat( $messages, [
            'purpose'     => 'chat',
            'max_tokens'  => 1200,
            'temperature' => 0.8,
        ] );
        return ! empty( $result['success'] ) ? ( $result['message'] ?? '' ) : 'Lỗi GPT';
    }

    // ── Legacy fallback: direct OpenAI ──
    if (!$api_key) $api_key = get_option('twf_openai_api_key');
    if (!$api_key && defined('OPEN_AI_API')) $api_key = OPEN_AI_API;

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode([
            'model'       => 'gpt-4o',
            'messages'    => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $message ],
            ],
            'max_tokens'  => 1200,
            'temperature' => 0.8,
        ]),
        'timeout' => 50,
    ]);
    if (is_wp_error($response)) return 'Lỗi GPT';
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['choices'][0]['message']['content'] ?? '';
}
function chatbot_chatgpt_call_omni_bizcoach_map($api_key, $message, $is_global = false, $id_kich_ban = false, $id_from_global = false, $kich_ban_lang = false) {
    if ( empty( trim( $message ) ) ) {
        return '⚠️ Bạn chưa nhập chủ đề hoặc yêu cầu cần viết. Vui lòng nhập chi tiết!';
    }

    $system = 'Bạn là trợ lý AI hỗ trợ quản trị, đăng bài Facebook, tạo đơn hàng, viết bài, nhắc việc, và tối ưu hóa công việc kinh doanh. Hãy trả lời chuyên nghiệp, sáng tạo, dễ hiểu, và luôn hỗ trợ người dùng hoàn thành các tác vụ một cách hiệu quả.';

    // ── OpenRouter gateway (ưu tiên) ──
    if ( function_exists( 'bizcity_openrouter_chat' ) ) {
        $messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $message ],
        ];
        $result = bizcity_openrouter_chat( $messages, [
            'purpose'     => 'chat',
            'max_tokens'  => 12200,
            'temperature' => 0.8,
        ] );
        return ! empty( $result['success'] ) ? ( $result['message'] ?? '' ) : 'Lỗi GPT';
    }

    // ── Legacy fallback: BizGPT proxy ──
    $proxy_token = get_option('bizgpt_api');
    $response = wp_remote_post('https://bizgpt.vn/wp-json/bizgpt/chat', [
        'headers' => [
            'Authorization' => 'Bearer ' . $proxy_token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode([
            'model'       => 'biz-4.1-nano',
            'messages'    => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $message ],
            ],
            'max_tokens'  => 12200,
            'temperature' => 0.8,
        ]),
        'timeout' => 180,
    ]);
    if (is_wp_error($response)) return 'Lỗi GPT';
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['choices'][0]['message']['content'] ?? '';
}
function vn_remove_accents($str) {
    $accents_arr = [
        'a'=>'á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ',
        'd'=>'đ',
        'e'=>'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
        'i'=>'í|ì|ỉ|ĩ|ị',
        'o'=>'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
        'u'=>'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
        'y'=>'ý|ỳ|ỷ|ỹ|ỵ',
        'A'=>'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ằ|Ẳ|Ẵ|Ặ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ',
        'D'=>'Đ',
        'E'=>'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ',
        'I'=>'Í|Ì|Ỉ|Ĩ|Ị',
        'O'=>'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ',
        'U'=>'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự',
        'Y'=>'Ý|Ỳ|Ỷ|Ỹ|Ỵ',
    ];
    foreach ($accents_arr as $non_accent=>$accent) {
        $str = preg_replace("/($accent)/i", $non_accent, $str);
    }
    return $str;
}
function biz_send_message($chat_id, $text, $parse_mode = 'HTML', $reply_markup = null) {
    return twf_telegram_send_message($chat_id, $text, $parse_mode, $reply_markup);
}
// Khởi tạo array để gom toàn bộ message gửi ra trong 1 request
$GLOBALS['twf_chat_msg_batch'] = [];	
// Gửi tin nhắn text
function twf_telegram_send_message($chat_id, $text, $parse_mode = 'HTML', $reply_markup = null) {
    // Log trả lời bot
    if (function_exists('bizgpt_log_chat_message')) bizgpt_log_chat_message($chat_id, $text, 'bot','', 'telegram');
    // 🔒 Nếu user thuộc blog hiện tại dùng Zalo → override gửi Zalo
    if ($client_id = twf_check_client_use_zalo($chat_id)) {
        return send_zalo_botbanhang(bizgpt_zalo_format($text), $client_id, 'text');
    }

    $override = apply_filters('twf_send_message_override', false, $chat_id, $text, $parse_mode, $reply_markup);
    if ($override !== false) return $override;
    // Bổ sung cho BizGPT_send_chat_message
    if(is_user_logged_in()) $user_id = get_current_user_id(); else $user_id = null;
    $session_id = isset($_SESSION['chat_session_id']) ? $_SESSION['chat_session_id'] : null;
    // Mọi logic gửi "giả" cho web
    $GLOBALS['twf_chat_msg_batch'][] = [
        'chat_id' => $chat_id,
        'msg'     => $text
    ];
    // Cho phép override bằng filter (Zalo, Messenger...)
    
     // Cho phép các flow khác lấy được message trả lời (không chỉ gửi Telegram)
    $text = apply_filters('twf_telegram_send_message_response', $text, $chat_id);
    
    $token = get_option('twf_bot_token');
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    // Remove any accidental whitespace at both ends
    $text = trim($text);
    if(!$text) return;
    back_trace('NOTICE', 'check connection telegram: '.$text);
    // IMPORTANT: Telegram chỉ chấp nhận 4096 ký tự cho mỗi message
    if (mb_strlen($text, 'UTF-8') > 4000) {
        $text = mb_substr($text, 0, 3990, 'UTF-8') . "…";
    }

    $payload = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => $parse_mode, // HTML hoặc Markdown
        'disable_web_page_preview' => true
    ];
    if ($reply_markup) {
        $payload['reply_markup'] = json_encode($reply_markup);
    }
    // Nếu text chứa các ký tự không hợp lệ với HTML/Markdown, nên filter

    $response = wp_remote_post($url, [
        'body' => $payload,
        'timeout' => 12
    ]);

    // Debug - log lỗi nếu có
    if(is_wp_error($response)) {
       # back_trace('NOTICE', 'Send telegram fail: '.$response->get_error_message());
        return false;
    }
    $json = json_decode(wp_remote_retrieve_body($response), 1);
    if(!$json || empty($json['ok'])) {
        #back_trace('NOTICE','Send telegram ok: ' . print_r($json,1));
        return false;
    }
    return $json;
}
function bizgpt_zalo_format($text) {
    // 1. Xoá LaTeX \[ \] và \( \)
    $text = preg_replace(['/\\\\\[/','/\\\\\]/','/\\\\\(/','/\\\\\)/'], '', $text);

    // 2. Xoá markdown tiêu đề ## ###
    $text = preg_replace('/^#{1,6}\s*/m', '', $text);

    // 3. Bỏ **bold** và __bold__
    $text = str_replace(['**', '__'], '', $text);

    // 4. Thay \text{...} => ...
    $text = preg_replace('/\\\\text\{(.*?)\}/', '$1', $text);

    // 5. Thay \left( và \right) => ( )
    $text = str_replace(['\\left', '\\right'], '', $text);

    // 6. Thay \\times => *
    $text = str_replace('\\times', '*', $text);

    // 7. Thay \\sqrt{X} => √(X)
    $text = preg_replace('/\\\\sqrt\{(.*?)\}/', '√($1)', $text);

    // 8. Xóa dư dấu `\\`
    $text = str_replace('\\\\', '', $text);

    // 9. Gộp dòng trắng thừa
    $text = preg_replace("/\n{2,}/", "\n", $text);

    return trim($text);
}
// Gửi hình ảnh
function twf_telegram_send_photo($chat_id, $photo_url, $caption = '', $extra = array()) {
    // Nếu vẫn muốn hỗ trợ log history dạng text:
    if (function_exists('bizgpt_log_chat_message')) {
        // Log ra cho chat web
        $msg = '<img src="'.$photo_url.'" class="bizgpt-photo-msg" style="max-width:220px;max-height:160px;border-radius:8px;display:block;margin:6px auto;">';
        if($caption) $msg .= '<div style="margin-top:4px">'.esc_html($caption).'</div>';
        $GLOBALS['twf_chat_msg_batch'][] = [
            'chat_id' => $chat_id,
            'msg'     => $msg
        ];
        
        bizgpt_log_chat_message($chat_id, $msg, 'bot','', 'telegram');
    }
    // 🔒 Nếu user thuộc blog hiện tại dùng Zalo → override gửi Zalo
    if ($client_id = twf_check_client_use_zalo($chat_id)) {
        back_trace('NOTICE', 'twf_telegram_send_photo: '.$photo_url);
        return send_zalo_botbanhang($photo_url, $client_id, 'image');
    }
    
    
    // Check override trước (Zalo chẳng hạn)
    $override = apply_filters('twf_telegram_send_photo_override', false, $chat_id, $photo_url, $caption, $extra);
    if ($override !== false) return $override;
    // Bổ sung cho BizGPT_send_chat_message
    if(is_user_logged_in()) $user_id = get_current_user_id(); else $user_id = null;
    $session_id = isset($_SESSION['chat_session_id']) ? $_SESSION['chat_session_id'] : null;
    //
    $bot_token = get_option('twf_bot_token');
    $url = "https://api.telegram.org/bot$bot_token/sendPhoto";
    $body = [
        'chat_id' => $chat_id,
        'photo' => $photo_url,
        // ----- Caption HTML -----
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    // Thêm các tham số bổ sung khác nếu có (VD: reply_markup)
    if (!empty($extra)) {
        $body = array_merge($body, $extra);
    }

    $args = [
        'body' => $body
    ];
    
    
    
    // Gọi filter để chat web bắt được tin này
    do_action('twf_telegram_send_photo_response', $photo_url, $caption, $chat_id);

    
    return wp_remote_post($url, $args);
}
function twf_telegram_send_audio($chat_id, $audio_url, $caption = '', $extra = array(), $file_type = 'audio') {
    // Nếu vẫn muốn hỗ trợ log history dạng text:
    if (function_exists('bizgpt_log_chat_message')) {
        // Log ra cho chat web
        $msg = '<audio src="'.$audio_url.'" class="bizgpt-audio-msg" controls style="display:block;margin:6px auto;"></audio>';
        if($caption) $msg .= '<div style="margin-top:4px">'.esc_html($caption).'</div>';
        $GLOBALS['twf_chat_msg_batch'][] = [
            'chat_id' => $chat_id,
            'msg'     => $msg
        ];
        
        bizgpt_log_chat_message($chat_id, $msg, 'bot','', 'telegram');
    }
    // 🔒 Nếu user thuộc blog hiện tại dùng Zalo → override gửi Zalo
    if ($client_id = twf_check_client_use_zalo($chat_id)) {
        back_trace('NOTICE', 'twf_telegram_send_audio: '.$audio_url);
        return send_zalo_botbanhang($audio_url, $client_id, $file_type);
    }
    
    
    // Check override trước (Zalo chẳng hạn)
    $override = apply_filters('twf_telegram_send_audio_override', false, $chat_id, $audio_url, $caption, $extra);
    if ($override !== false) return $override;
    // Bổ sung cho BizGPT_send_chat_message
    if(is_user_logged_in()) $user_id = get_current_user_id(); else $user_id = null;
    $session_id = isset($_SESSION['chat_session_id']) ? $_SESSION['chat_session_id'] : null;
    //
    $bot_token = get_option('twf_bot_token');
    $url = "https://api.telegram.org/bot$bot_token/sendPhoto";
    $body = [
        'chat_id' => $chat_id,
        'photo' => $photo_url,
        // ----- Caption HTML -----
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    // Thêm các tham số bổ sung khác nếu có (VD: reply_markup)
    if (!empty($extra)) {
        $body = array_merge($body, $extra);
    }

    $args = [
        'body' => $body
    ];
    
    
    
    // Gọi filter để chat web bắt được tin này
    do_action('twf_telegram_send_photo_response', $photo_url, $caption, $chat_id);

    
    return wp_remote_post($url, $args);
}

function twf_send_telegram_document($chat_id, $file_any, $caption = '') {
    // 🔒 Nếu user thuộc blog hiện tại dùng Zalo → override gửi Zalo
    if ($client_id = twf_check_client_use_zalo($chat_id)) {
        return send_zalo_botbanhang($file_any, $client_id, 'file');
    }

    // Check override trước (Zalo chẳng hạn)
    $override = apply_filters('twf_send_telegram_document_override', false, $chat_id, $photo_url, $caption, $extra);
    if ($override !== false) return $override;
    // Bổ sung cho BizGPT_send_chat_message
    if(is_user_logged_in()) $user_id = get_current_user_id(); else $user_id = null;
    $session_id = isset($_SESSION['chat_session_id']) ? $_SESSION['chat_session_id'] : null;
    //
    $bot_token = get_option('twf_bot_token');
    $url = "https://api.telegram.org/bot{$bot_token}/sendDocument";
    if (strpos($file_any, 'http') === true) {
        // Nếu truyền vào là URL, tải về rồi gửi
        $tmp = download_url($file_any);
        if (is_wp_error($tmp)) {
            twf_telegram_send_message($chat_id, "Không thể tải file về server.");
            return false;
        }
        $file_path = $tmp;
        $remove_after = true;
        
    } else {
        $file_path = $file_any;
        $remove_after = false;
    }
    /// Xử lý cho chat qua web/
    if (function_exists('bizgpt_log_chat_message')) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir']; // '/home/vibeyeuc/huongnguyen.vibeyeu.com.vn/wp-content/uploads'
        $base_url = $upload_dir['baseurl']; // 'https://bizgpt.vn/wp-content/uploads'
        
        $file_url = str_replace($base_dir, $base_url, $file_path);
        $msg = '<a href="'.esc_url($file_url).'" download class="bizgpt-file-link">📄 '.basename($file_url).'</a>';
        if($caption) $msg .= '<div style="margin-top:4px">'.esc_html($caption).'</div>';
        $GLOBALS['twf_chat_msg_batch'][] = [
            'chat_id' => $chat_id,
            'msg'     => $msg
        ];
        bizgpt_log_chat_message($chat_id, $msg, 'bot','', 'telegram');
    }
    ///
    
    $post_fields = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile($file_path, mime_content_type($file_path), basename($file_path))
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $result = curl_exec($ch);
    curl_close($ch);

    // Clean up nếu là file tạm
    if ($remove_after) @unlink($file_path);

    return $result;
}

//Hàm mã hóa (encode) chat_id (gắn slug)


function twf_ai_telegram_help_content($topic = 'tat_ca') {
    // Các nhóm chủ đề và hướng dẫn đi kèm
    $help_topics = [
        'ban_hang' => [
            'icon' => '📦',
            'title' => 'Hướng dẫn các lệnh bán hàng, tạo đơn',
            'commands' => [
                'Tạo đơn hàng: [Tên KH] | [SĐT] | [Sản phẩm]',
                'Báo cáo doanh số tuần này',
                'Danh sách đơn hàng tháng này',
                'Liệt kê đơn hàng đã hủy tháng 2',
                'Danh sách đơn hoàn tất tuần trước',
                'Xuất file đơn hàng 7 ngày gần nhất'
            ]
        ],
        'don_hang' => [
            'icon' => '📦',
            'title' => 'Hướng dẫn các lệnh đơn hàng',
            'commands' => [
                'Danh sách đơn hàng tháng này',
                'Liệt kê đơn hàng đã hủy tháng 2',
                'Danh sách đơn hoàn tất tuần trước',
                'Xuất file đơn hàng 7 ngày gần nhất'
            ]
        ],
        'viet_bai' => [
            'icon' => '📝',
            'title' => 'Hướng dẫn các lệnh viết bài & tạo content',
            'commands' => [
                'Viết bài về [chủ đề]',
                'Lên lịch đăng bài: [Nội dung]',
                'Viết caption cho ảnh [Chủ đề]',
                'Chụp ảnh/Đăng sản phẩm với ảnh, mô tả...',
                'Tạo podcast/chủ đề video về...'
            ]
        ],
        'bai_viet' => [
            'icon' => '📝',
            'title' => 'Hướng dẫn các lệnh viết bài & tạo content',
            'commands' => [
                'Viết bài về [chủ đề]',
                'Lên lịch đăng bài: [Nội dung]',
                'Viết caption cho ảnh [Chủ đề]',
                'Chụp ảnh/Đăng sản phẩm với ảnh, mô tả...',
                'Tạo podcast/chủ đề video về...'
            ]
        ],
        'bao_cao' => [
            'icon' => '📈',
            'title' => 'Hướng dẫn các lệnh báo cáo, thống kê',
            'commands' => [
                'Báo cáo doanh số tháng này',
                'Báo cáo doanh số tháng 3/2023',
                'Thống kê nhập xuất tồn theo tuần',
                'Thống kê đơn hàng đã hoàn tất',
                'Tìm khách hàng mua nhiều nhất tháng 4',
                'Thống kê sản phẩm bán chạy tuần qua',
                'Xuất file báo cáo bán hàng'
            ]
        ],
        'thong_ke' => [
            'icon' => '📈',
            'title' => 'Hướng dẫn các lệnh báo cáo, thống kê',
            'commands' => [
                'Báo cáo doanh số tháng này',
                'Báo cáo doanh số tháng 3/2023',
                'Thống kê nhập xuất tồn theo tuần',
                'Thống kê đơn hàng đã hoàn tất',
                'Tìm khách hàng mua nhiều nhất tháng 4',
                'Thống kê sản phẩm bán chạy tuần qua',
                'Xuất file báo cáo bán hàng'
            ]
        ],
        'san_pham' => [
            'icon' => '🛒',
            'title' => 'Các lệnh về sản phẩm',
            'commands' => [
                'Đăng sản phẩm: ...',
                'Thêm sản phẩm mới: ...',
                'Sửa sản phẩm: ...',
                'Danh sách sản phẩm bán chạy'
            ]
        ],
        'video' => [
            'icon' => '🎬',
            'title' => 'Các lệnh tạo video tự động',
            'commands' => [
                'Tạo video về [chủ đề]',
                'Tạo podcast/clip về [chủ đề]'
            ]
        ],
        // Thêm chủ đề khác nếu cần
    ];

    // Nếu có topic hợp lệ thì chỉ trả về hướng dẫn chủ đề đó
    if (isset($help_topics[$topic])) {
        $data = $help_topics[$topic];
        $output  = "{$data['icon']} *{$data['title']}*\n";
        foreach ($data['commands'] as $cmd) {
            $output .= "- `$cmd`\n";
        }
        return $output;
    }

    // Nếu topic không có trong danh sách hoặc là 'tat_ca' thì tổng hợp các chủ đề chính
    $output = "🎉 *Hướng dẫn tổng hợp các nhóm lệnh phổ biến:*\n\n";
    foreach ($help_topics as $data) {
        $output .= "{$data['icon']} *{$data['title']}*\n";
        foreach ($data['commands'] as $cmd) {
            $output .= "  - `$cmd`\n";
        }
        $output .= "\n";
    }
    $output .= "*Bạn có thể gõ:* _hướng dẫn [chủ đề]_ để nhận chi tiết từng nhóm!\n";
    $output .= "\nVD: `hướng dẫn bán_hàng` | `hướng dẫn viet_bai` | `hướng dẫn bao_cao`\n";

    return $output;
}
