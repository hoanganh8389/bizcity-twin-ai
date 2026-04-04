<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if(!defined('ABSPATH')) exit;
// 1. Đăng ký Custom Post Type: bizgpt_doc

function biz_create_doc($chat_id, $message, $file_url='', $data = array()) {
    return bizgpt_handle_uploaded_file_to_doc($chat_id, $message, $file_url, $data);
}

add_action('init', function() {
    $labels = array(
        'name' => 'Tài liệu BizGPT',
        'singular_name' => 'Tài liệu ghi nhớ',
        'menu_name' => 'Tài liệu lưu trữ qua Zalo',
        'add_new' => 'Thêm tài liệu',
        'add_new_item' => 'Thêm tài liệu mới',
        'edit_item' => 'Chỉnh sửa tài liệu',
        'new_item' => 'Tài liệu mới',
        'view_item' => 'Xem tài liệu',
        'search_items' => 'Tìm tài liệu',
        'not_found' => 'Không tìm thấy tài liệu.',
        'not_found_in_trash' => 'Không có tài liệu nào trong thùng rác.'
    );
    $args = array(
        'labels' => $labels,
        'public' => true,
        'has_archive' => true,
        'rewrite' => array('slug' => 'bizgpt-doc'),
        'supports' => array('title', 'editor', 'thumbnail', 'custom-fields', 'author'),
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-media-document',
        'taxonomies' => array('bizgpt_doc_category', 'bizgpt_doc_tag'),
		'show_in_menu' => 'bizlife_dashboard' // <- �ua v�o menu cha
    );
    register_post_type('bizgpt_doc', $args);
});
add_action('init', function() {
    $labels = array(
        'name' => 'Chủ đề tài liệu',
        'singular_name' => 'Chủ đề',
        'search_items' => 'Tìm chủ đề',
        'all_items' => 'Tất cả chủ đề',
        'edit_item' => 'Chỉnh sửa chủ đề',
        'update_item' => 'Cập nhật chủ đề',
        'add_new_item' => 'Thêm chủ đề',
        'new_item_name' => 'Chủ đề mới',
        'menu_name' => 'Chủ đề'
    );
    $args = array(
        'hierarchical' => true,
        'labels' => $labels,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => array('slug' => 'bizgpt-doc-category'),
		
    );
    register_taxonomy('bizgpt_doc_category', array('bizgpt_doc'), $args);
});
add_action('init', function() {
    $labels = array(
        'name' => 'Thẻ tài liệu',
        'singular_name' => 'Thẻ',
        'search_items' => 'Tìm thẻ',
        'all_items' => 'Tất cả thẻ',
        'edit_item' => 'Chỉnh sửa thẻ',
        'update_item' => 'Cập nhật thẻ',
        'add_new_item' => 'Thêm thẻ',
        'new_item_name' => 'Thẻ mới',
        'menu_name' => 'Thẻ'
    );
    $args = array(
        'hierarchical' => false,
        'labels' => $labels,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => array('slug' => 'bizgpt-doc-tag')
    );
    register_taxonomy('bizgpt_doc_tag', array('bizgpt_doc'), $args);
});


// 4. Hàm tạo bài viết từ ảnh telegram (ảnh + caption => biztask)
function bizgpt_handle_uploaded_file_to_doc($chat_id, $message, $file_url='', $data = array()) {
    $caption = $message['caption'] ?? '';
    $ocr_text = '';
    $attach_id = 0;
    $file_url = '';
    $filetype = '';

    // Lấy ảnh lớn nhất hoặc file đầu tiên (ảnh hoặc document)
    if (!empty($message['photo'])) {
        $file = end($message['photo']);
        $file_id = $file['file_id'];
        $file_url = twf_telegram_get_file_url($file_id);
    } elseif (!empty($message['document'])) {
        $file_id = $message['document']['file_id'];
        $file_url = twf_telegram_get_file_url($file_id);
    } 
	

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php'; // BẮT BUỘC nếu dùng media_handle_sideload
	$file_url = @$data['image_url'];
	back_trace('NOTICE', 'bizgpt_handle_uploaded_file_to_doc: '.$file_url );
    $tmp = download_url($file_url);
    if (!is_wp_error($tmp)) {
        $filetype = wp_check_filetype($file_url);
        $file = [
            'name'     => basename($file_url),
            'type'     => $filetype['type'],
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize($tmp),
        ];

        $attach_id = media_handle_sideload($file, 0);
        if (!is_wp_error($attach_id)) {
            $file_url = wp_get_attachment_url($attach_id);
        }
        @unlink($tmp);
    }
	

    // OCR nếu là ảnh
		if (preg_match('/image\//', $filetype['type'])) {
			$ocr_text = twf_openai_vision_analyze($file_url);
		}
		
		// Nếu là PDF/Word/Excel → dùng hàm mô phỏng AI trích xuất
		if (preg_match('/pdf|word|officedocument|excel/', $filetype['type'])) {
			$document_json = twf_extract_text_from_document($file_url); // Trả về JSON giả lập
			if (is_string($document_json)) {
				if (($pos = strpos($document_json, '{')) !== false) $document_json = substr($document_json, $pos);
				if (($pos = strrpos($document_json, '}')) !== false) $document_json = substr($document_json, 0, $pos + 1);
				$document_json = json_decode($document_json, true);
			} else {
				$document_json = [];
			}
		
			$ocr_text = $document_json['summary'] ?? '';
			$tags     = $document_json['tags'] ?? [];
			$category = $document_json['category'] ?? '';
			$doc_title_guess = $document_json['title'] ?? '';
		}
			
		// Nếu có OCR_text thì đưa vào AI tóm tắt + sinh tiêu đề/tags/category nếu thiếu
		#$ai_prompt = "Tóm tắt tài liệu sau, tạo tiêu đề, tags, và phân loại (gia_dinh, ca_nhan, cong_viec, khac):\n" . $ocr_text;
		#back_trace('NOTICE', 'bizgpt_handle_uploaded_file_to_doc: '.$ai_prompt );
		#$api_key = get_option('twf_openai_api_key');
		#$json = chatbot_chatgpt_call_omni_tele($api_key, $ai_prompt);
		
		#if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
		#if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
		#$parsed = json_decode($json, true);
		#$title  = $data['title'];	
		$summary  = $data['content'] ?? $summary;
		$tags     = !empty($data['tags']) ? $data['tags'] : ($tags ?? []);
		$category = !empty($data['category']) ? $data['category'] : ($data ?? 'ca_nhan');
		
		// Ưu tiên caption, sau đó AI title, rồi mới tới doc title
		$title = $data['title'] ?? ($title ?: bizgpt_title_from_summary($data['summary'] ?? ''));
		back_trace('NOTICE', 'bizgpt_handle_uploaded_file_to_doc: '.print_r($data,true) );
    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($title),
        'post_content' => $summary,
        'post_type'    => 'bizgpt_doc',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
    ]);

    if (!is_wp_error($post_id)) {
        if (!empty($tags)) wp_set_post_terms($post_id, $tags, 'bizgpt_doc_tag');
        wp_set_post_terms($post_id, [$category], 'bizgpt_doc_category');
        if ($attach_id) set_post_thumbnail($post_id, $attach_id);
        twf_telegram_send_message($chat_id, "✅ Đã lưu tài liệu tên '$title' thành công. \n ✅ Từ khóa ghi nhớ: ".$tags." \n✅ Nội dung: ".$summary);
    } else {
        twf_telegram_send_message($chat_id, "❌ Lỗi khi lưu tài liệu. Vui lòng thử lại.");
    }

    return $post_id;
}

function bizgpt_title_from_summary($summary, $max_words = 10) {
    if (!$summary) return 'Tài liệu mới';
    $title = wp_trim_words($summary, $max_words, '...');
    return wp_strip_all_tags($title);
}

function bizgpt_extract_title_from_json($parsed, $fallback = '') {
    // Trường hợp phổ biến
    if (!empty($parsed['title'])) return $parsed['title'];

    // Một số cấu trúc lồng khác thường gặp
    if (!empty($parsed['meta']['title'])) return $parsed['meta']['title'];
    if (!empty($parsed['document']['title'])) return $parsed['document']['title'];
    if (!empty($parsed['data']['title'])) return $parsed['data']['title'];

    // Trường hợp lồng nhiều cấp bất kỳ
    foreach ($parsed as $key => $val) {
        if (is_array($val)) {
            if (!empty($val['title'])) return $val['title'];
        }
    }

    // Fallback
    return $fallback;
}

function twf_extract_text_from_document($file_url) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key || !$file_url) return [];

    $file_name = basename($file_url);
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $prompt = "
Giả sử bạn là một trợ lý AI có khả năng đọc hiểu file có tên \"$file_name\" (có thể là PDF, Word, Excel).

Hãy phân tích và trả về JSON sau:
{
  \"title\": \"Tiêu đề mô tả file rõ ràng\",
  \"summary\": \"Nội dung chính hoặc đoạn trích từ file\",
  \"tags\": [\"tag1\", \"tag2\"],
  \"category\": \"cong_viec | gia_dinh | ca_nhan | khac\"
}

⚠️ Không có quyền đọc nội dung file thật, nhưng hãy đoán nội dung dựa trên tên file, định dạng và ngữ cảnh.

Chỉ trả về đúng JSON, không được giải thích.
";

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => $prompt
            ]]
        ]),
        'timeout' => 80,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    if (is_wp_error($response)) return [];

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $text = $body['choices'][0]['message']['content'] ?? '';

    // Parse JSON an toàn
    if (($pos = strpos($text, '{')) !== false) $text = substr($text, $pos);
    if (($pos = strrpos($text, '}')) !== false) $text = substr($text, 0, $pos + 1);
    $parsed = json_decode($text, true);

    return is_array($parsed) ? $parsed : [];
}