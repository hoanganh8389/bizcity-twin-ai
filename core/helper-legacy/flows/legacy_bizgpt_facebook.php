<?php
/**
 * @deprecated 2025-07 Use plugin bizcity-tool-facebook instead.
 * This file is kept for backward compatibility and will be removed in a future release.
 */
if(!defined('ABSPATH')) exit;

function biz_create_facebook($chat_id, $message, $data = array()) {
    return twf_handle_facebook_request($chat_id, $message, $data);
}

add_action('rest_api_init', function () {
    register_rest_field('biz_facebook', 'plain_title', [
        'get_callback' => function ($post_arr) {
            $title = get_the_title($post_arr['id']);
            return twf_clean_plain_text($title);
        },
        'schema' => ['description' => 'Tiêu đề sạch cho Facebook/Zapier', 'type' => 'string']
    ]);

    register_rest_field('biz_facebook', 'plain_content', [
        'get_callback' => function ($post_arr) {
            $content = get_post_field('post_content', $post_arr['id']);
            return twf_clean_plain_text($content);
        },
        'schema' => ['description' => 'Nội dung sạch cho Facebook/Zapier', 'type' => 'string']
    ]);
});
// 1. Đăng ký post-type Biz-Facebook
add_action('init', function() {

    $labels = array(
        'name'               => 'Bài viết do AI tạo',
        'singular_name'      => 'DS bài đăng FB AI tạo',
        'menu_name'          => 'DS bài đăng FB AI tạo',
        'add_new'            => 'Thêm bài',
        'add_new_item'       => 'Thêm bài Facebook mới',
        'edit_item'          => 'Chỉnh sửa bài',
        'new_item'           => 'Bài mới',
        'view_item'          => 'Xem bài',
        'view_items'         => 'Xem các bài',
        'search_items'       => 'Tìm bài Facebook',
        'not_found'          => 'Không tìm thấy.',
        'not_found_in_trash' => 'Không có bài nào trong thùng rác.',
    );

    $args = array(
        'labels' => $labels,

        /* ===== PUBLIC ===== */
        'public'              => true,
        'publicly_queryable'  => true, // BẮT BUỘC để truy cập link ngoài
        'exclude_from_search' => false,
        'show_ui'             => true,
        'show_in_nav_menus'   => true,

        /* ===== PERMALINK ===== */
        'has_archive' => true,
        'rewrite'     => array(
            'slug'       => 'biz-facebook',
            'with_front' => false,
        ),

        /* ===== GUTENBERG + REST ===== */
        'show_in_rest' => true,

        /* ===== QUYỀN & CHỨC NĂNG ===== */
        'supports' => array(
            'title',
            'editor',
            'thumbnail',
            'author',
            'excerpt'
        ),

        /* ===== MENU ===== */
        'show_in_menu' => 'bizcity-facebook-bots',
        'menu_icon'    => 'dashicons-facebook-alt',

    );

    register_post_type('biz_facebook', $args);
});

// 2. Hàm tạo post từ dữ liệu Telegram/AI (gọi từ webhook hoặc shortcode)
function twf_handle_facebook_request($chat_id, $message, $data = array()) {
    $caption = $message['caption'] ?? '';
    $image_url = $data['image_url'] ?? '';
    $ai_title = '';
    $ai_content = '';
    #back_trace('INFO', 'Handling Facebook message: ' . print_r($message, true));
    
    // Validate image_url - chỉ chấp nhận nếu có đuôi ảnh hợp lệ
    if ( ! empty( $image_url ) ) {
        $parsed_url = parse_url( $image_url, PHP_URL_PATH );
        $ext = strtolower( pathinfo( $parsed_url, PATHINFO_EXTENSION ) );
        $valid_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
        if ( ! in_array( $ext, $valid_extensions ) ) {
            back_trace('INFO', 'Invalid image_url (no valid extension): ' . $image_url);
            $image_url = ''; // Reset nếu không phải URL ảnh hợp lệ
        }
    }
    
    // Nếu có ảnh Telegram, upload vào Media
    if (!empty($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        $image_url = function_exists('twf_telegram_get_file_url') ? twf_telegram_get_file_url($file_id) : '';
    }

    // Nếu không có ảnh: Tạo ảnh bằng AI dựa trên chủ đề/caption
    if (empty($image_url) && function_exists('twf_generate_image_url')) {
        $prompt = $caption ?: ($message['text'] ?? 'Ảnh minh họa kinh doanh Facebook');
        $image_url = twf_generate_image_url($prompt);
    }

    // 3. Sinh title, content bằng AI
    $api_key = get_option('twf_openai_api_key');
    $text = $caption ?: ($message['text'] ?? '');

    // Yêu cầu AI sinh title ngắn, nội dung max 300 chữ (HTML, văn phong thu hút)
    $ai_prompt = "
Bạn là chuyên gia nội dung Facebook Marketing. Viết một bài post Facebook tiếng Việt, hấp dẫn, không quá 300 chữ, chủ đề:\n$text
- Sinh tiêu đề ngắn gọn, sáng tạo (max 90 ký tự).
- Nội dung thân thiện, giàu cảm xúc, có lời kêu gọi hành động ở cuối.
- Có 5 hashtag tóm tắt ở dưới cùng.
- Trả về đúng JSON:
{
  \"title\": \"...\",
  \"content\": \"...\" // HTML hoặc plain text đều được
}
";
    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => $ai_prompt
            ]]
        ]),
        'timeout' => 80,
    ];
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $json = $body['choices'][0]['message']['content'] ?? '';
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $parsed = json_decode($json, true);

    // Sau khi lấy $ai_title, $ai_content từ AI JSON
	$ai_title   = $parsed['title'] ?? 'Bài Facebook mới';
	$ai_content = $parsed['content'] ?? $text;
	
	// Làm sạch cho Zapier, Telegram...
	$plain_title   = twf_clean_plain_text($ai_title);
	$plain_content = twf_clean_plain_text($ai_content);
	
	// 4. Lưu vào post-type biz_facebook (giữ nguyên content gốc để hiển thị đẹp trên web)
	$post_id = wp_insert_post([
		'post_title'   => $plain_title,
		'post_content' => $plain_content, // hoặc $plain_content nếu chỉ muốn plain text trên site
		'post_type'    => 'biz_facebook',
		'post_status'  => 'publish',
		'post_author'  => get_current_user_id(),
	]);
    // Đính kèm ảnh đại diện nếu có
    if ($post_id && $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $tmp = download_url($image_url);
        if (!is_wp_error($tmp)) {
            $file = [
                'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
                'type'     => 'image/jpeg',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => filesize($tmp),
            ];
            $attach_id = media_handle_sideload($file, $post_id);
            if (!is_wp_error($attach_id)) set_post_thumbnail($post_id, $attach_id);
            @unlink($tmp);
        }
    }

    /* 5. Tự động đăng lên Fanpage Facebook (option)
    if ($post_id && function_exists('bizfb_post_to_facebook')) {
        $fb_link = bizfb_post_to_facebook($post_id);
    }*/
	if ($post_id && function_exists('fb_send_post')) {
		$fb_links = fb_send_post($plain_title, $plain_content, $image_url);
        #fb_send_post($ai_title, wp_strip_all_tags($ai_content), $image_url);
    }

    // Trả về permalink bài viết
    // Trả về permalink bài viết hoặc gửi notification
		if ($post_id) {
			if (function_exists('twf_telegram_send_message')) {
				// Nếu muốn gửi bản đẹp trên web: dùng $ai_content (HTML)
				// Nếu gửi cho Zapier, Telegram,... dùng plain text
				$msg = 
				    "✅ Đã đăng bài Facebook:\n" . get_permalink($post_id) .
					"\n\n<b>Link Facebook Page:</b>\n" . implode("\n", $fb_links) .
					"\n\n<b>Linh sửa:</b> " . admin_url("post.php?post={$post_id}&action=edit").
					"\n\n<b>Tiêu đề:</b> " . $plain_title .
					"\n\n<b>Nội dung:</b>\n" . $plain_content;
				twf_telegram_send_message($chat_id, $msg);
			}
		}
    return $post_id;
}

// 6. Hàm đăng lên Fanpage Facebook (cần page_access_token đã lưu ở option, hoặc cấu hình app)
// Bạn cần tạo app Facebook, lấy page_access_token và lưu vào option `bizfb_page_access_token`
function bizfb_post_to_facebook($post_id) {
    $page_access_token = get_option('twf_facebook_access_token');
    $page_id = get_option('twf_facebook_page_id');
    if (!$page_access_token || !$page_id) return false;

    $post = get_post($post_id);
    $link = get_permalink($post_id);
    $title = $post->post_title;
    $content = wp_strip_all_tags($post->post_content);
    $img_url = get_the_post_thumbnail_url($post_id, 'full');

    $endpoint = "https://graph.facebook.com/{$page_id}/photos";
    $args = [
        'body' => [
            'caption' => "$title\n\n$content\n\nXem thêm: $link",
            'url' => $img_url,
            'access_token' => $page_access_token
        ]
    ];
    $response = wp_remote_post($endpoint, $args);

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Nếu đăng thành công sẽ có post_id
    if (!empty($data['post_id'])) {
        // Tạo link bài post Facebook
        $fb_post_link = "https://www.facebook.com/{$page_id}/posts/{$data['post_id']}";
        return $fb_post_link;
    }
    return false;
}

// 7. Thêm shortcode để test tạo bài nhanh từ dashboard: [bizfb_test title="..." image_url="..."]
add_shortcode('bizfb_test', function($atts){
    $a = shortcode_atts([
        'title' => '',
        'image_url' => '',
        'text' => 'Bài test Facebook AI',
    ], $atts);

    $message = [
        'caption' => $a['text'],
        'photo' => $a['image_url'] ? [['file_id' => $a['image_url']]] : []
    ];
    $data = ['image_url' => $a['image_url']];
    $post_id = twf_handle_facebook_request(get_current_user_id(), $message, $data);
    return $post_id ? '<a href="'.get_permalink($post_id).'" target="_blank">Xem bài vừa đăng</a>' : 'Không tạo được bài!';
});

function twf_clean_plain_text($html_content) {
    $text = html_entity_decode($html_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_ireplace(['<p>', '</p>', '<br>', '<br/>', '<br />'], "\n\n", $text);
    $text = wp_strip_all_tags($text);
    return trim(preg_replace("/[\r\n]{3,}/", "\n\n", $text));
}

function twf_handle_facebook_multi_page_post($chat_id, $message, $data = array()) {
    $caption    = $message['caption'] ?? '';
    $image_url  = $data['image_url'] ?? '';
    $ai_title   = '';
    $ai_content = '';

    // Validate image_url - chỉ chấp nhận nếu có đuôi ảnh hợp lệ
    if ( ! empty( $image_url ) ) {
        $parsed_url = parse_url( $image_url, PHP_URL_PATH );
        $ext = strtolower( pathinfo( $parsed_url, PATHINFO_EXTENSION ) );
        $valid_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
        if ( ! in_array( $ext, $valid_extensions ) ) {
            back_trace('INFO', 'Invalid image_url (no valid extension): ' . $image_url);
            $image_url = ''; // Reset nếu không phải URL ảnh hợp lệ
        }
    }

    // 1. Lấy ảnh Telegram nếu có
    if (!empty($message['photo'])) {
        $photo    = end($message['photo']);
        $file_id  = $photo['file_id'];
        $image_url = function_exists('twf_telegram_get_file_url') ? twf_telegram_get_file_url($file_id) : '';
    }

    // 2. Nếu không có ảnh, tạo ảnh bằng AI dựa trên chủ đề/caption
    if (empty($image_url) && function_exists('twf_generate_image_url')) {
        $prompt    = $caption ?: ($message['text'] ?? 'Ảnh minh họa Facebook');
        $image_url = twf_generate_image_url($prompt);
    }

    // 3. Gọi AI tạo title + content dạng JSON
    $api_key = get_option('twf_openai_api_key');
    $text    = $caption ?: ($message['text'] ?? '');

    $ai_prompt = "
Bạn là chuyên gia nội dung Facebook Marketing. Viết một bài post Facebook tiếng Việt, hấp dẫn, không quá 300 chữ, chủ đề:\n$text

- Sinh tiêu đề ngắn gọn, sáng tạo (max 90 ký tự).
- Nội dung thân thiện, giàu cảm xúc, có lời kêu gọi hành động ở cuối.
- Trả về đúng JSON:
{
  \"title\": \"...\",
  \"content\": \"...\" // HTML hoặc plain text đều được
}
";

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => $ai_prompt
            ]]
        ]),
        'timeout' => 80,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    $body     = json_decode(wp_remote_retrieve_body($response), true);
    $json     = $body['choices'][0]['message']['content'] ?? '';
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $parsed   = json_decode($json, true);

    $ai_title   = $parsed['title'] ?? 'Bài Facebook mới';
    $ai_content = $parsed['content'] ?? $text;

    // 4. Tạo post trong WP
    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($ai_title),
        'post_content' => $ai_content,
        'post_type'    => 'biz_facebook',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
    ]);

    // 5. Upload ảnh đại diện nếu có
    if ($post_id && $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $tmp = download_url($image_url);
        if (!is_wp_error($tmp)) {
            $file = [
                'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
                'type'     => 'image/jpeg',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => filesize($tmp),
            ];
            $attach_id = media_handle_sideload($file, $post_id);
            if (!is_wp_error($attach_id)) {
                set_post_thumbnail($post_id, $attach_id);
            }
            @unlink($tmp);
        }
    }

    // 6. Đăng lên Facebook Page đã chọn (dùng đa page)
    if ($post_id && function_exists('fb_send_post')) {
		$fb_links = fb_send_post($ai_title, wp_strip_all_tags($ai_content), $image_url);
        #fb_send_post($ai_title, wp_strip_all_tags($ai_content), $image_url);
    }

    // 7. Thông báo qua Telegram
    if ($post_id && function_exists('twf_telegram_send_message')) {
        #$link = get_permalink($post_id);
        #$msg = "✅ <b>Đã đăng bài Facebook:</b>\n<a href='$link'>$ai_title</a>";
		$msg = 
		"✅ Đã đăng bài Facebook:\n" . get_permalink($post_id) .
		"\n\n<b>Link Facebook Page:</b>\n" . implode("\n", $fb_links) .
		"\n\n<b>Linh sửa:</b> " . admin_url("post.php?post={$post_id}&action=edit") .
		"\n\n<b>Tiêu đề:</b> " . $ai_title .
		"\n\n<b>Nội dung:</b>\n" . $plain_content;
        twf_telegram_send_message($chat_id, $msg);
    }

    return $post_id;
}