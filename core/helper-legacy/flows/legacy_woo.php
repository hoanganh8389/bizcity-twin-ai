<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

function biz_create_product($message, $chat_id, $arr = [], $image_url = '') {
    return twf_handle_product_post_flow($message, $chat_id, $arr, $image_url);
}

//Hàm xử lý đăng sản phẩm (ví dụ đơn giản)
function twf_handle_product_post_flow($message, $chat_id, $arr = [], $image_url = '') {
    $api_key = get_option('twf_openai_api_key');
    $user_input = isset($message['text']) ? $message['text'] : $message['caption'];
    
    // Gọi AI trích xuất thông tin cấu trúc
    $info = twf_parse_product_info_ai($api_key, $user_input);

	back_trace('NOTICE', 'twf_handle_product_post_flow: '.print_r($info,true));
    $name = $info['title'] ?? $arr['title'];
    $price = $info['price'] ?? '';
    $sale_price = $info['sale_price'] ?? '';
    $desc = $info['description'] ?? '';
    $cat_name = $info['category'] ?? '';

    if (empty($name) || empty($price)) {
        twf_telegram_send_message($chat_id, "Không tìm đủ thông tin tên hoặc giá sản phẩm. Vui lòng gửi mô tả đầy đủ hơn!");
        return;
    }

    // XỬ LÝ ẢNH
    #$image_url = null;
    if (isset($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        $image_url = twf_telegram_get_file_url($file_id);
        $attachment_id = twf_upload_image_to_media_library($image_url, $name);
    }
	
	if(empty($image_url)) {
        $image_url = twf_generate_image_url($post_title);
    }
	
	$attachment_id = twf_upload_image_to_media_library($image_url, $title);

    // Chèn sản phẩm (post type product)
    $post_id = wp_insert_post([
        'post_title'    => $name,
        'post_content'  => $desc,
        'post_status'   => 'publish',
        'post_type'     => 'product',
    ]);

    // Lưu giá
    update_post_meta($post_id, '_regular_price', $price);
    update_post_meta($post_id, '_price', $sale_price ?: $price);
    if ($sale_price) update_post_meta($post_id, '_sale_price', $sale_price);
    
    // Nếu có ảnh: set thumbnail sản phẩm
    if ($attachment_id) {
        set_post_thumbnail($post_id, $attachment_id);
    }

    // Gán danh mục nếu có
    if (!empty($cat_name)) {
        $term = get_term_by('name', $cat_name, 'product_cat');
        if ($term) {
            wp_set_object_terms($post_id, [$term->term_id], 'product_cat');
        }
    }

    #$permalink = get_permalink($post_id);
    twf_telegram_send_message($chat_id, 
		"✅ Sản phẩm đã đăng: " . get_permalink($post_id) . 
		"\n✏️ Link sửa: " . admin_url("post.php?post={$post_id}&action=edit")
	);
}

//Hàm flow thực thi sửa thông tin sản phẩm
function twf_handle_edit_product_flow($message, $chat_id) {
    $api_key = get_option('twf_openai_api_key');
    $user_input = $message['text'] ?? $message['caption'];

    $info = twf_parse_edit_product_info_ai($api_key, $user_input);

    if (empty($info['identity'])) {
        twf_telegram_send_message($chat_id, "Không tìm được thông tin sản phẩm (cần mã hoặc tên sản phẩm).");
        return;
    }
	back_trace('NOTICE', print_r($info,true));
    // Chuẩn hóa mảng danh tính sản phẩm
	// Giả sử $info['identity'] = "áo đỏ, quần jean";
		// Tách các tên sp/mã bằng dấu phẩy, chấm phẩy hoặc dấu |
		$identities = preg_split('/[,;\|]/', $info['identity']);
		$identities = array_map('trim', $identities);
		
		$product_ids = [];
		
		foreach ($identities as $identity) {
			if (isset($info['find_by']) && $info['find_by'] === 'id' && is_numeric($identity)) {
				$product_ids[] = (int)$identity;
			} else {
				$args = [
					'post_type' => 'product',
					'posts_per_page' => 5,
					's' => $identity, // sử dụng search
					'post_status' => 'publish',
					'orderby' => 'date',
					'order' => 'DESC'
				];
				$query = new WP_Query($args);
				foreach ($query->posts as $post) {
					// So khớp tuyệt đối nếu muốn
					#if (mb_strtolower($post->post_title, 'UTF-8') === mb_strtolower($identity, 'UTF-8')) {
						$product_ids[] = $post->ID;
					#}
				}
				// Nếu muốn lấy cả các sản phẩm gần đúng cũng được, tuỳ nhu cầu
			}
		}
		
		$product_ids = array_filter($product_ids); // loại bỏ id = 0, null


		back_trace('NOTICE', 'Tìm theo mảng:'.print_r($product_ids, true) );
		if (empty($product_ids)) {
			twf_telegram_send_message($chat_id, "Không tìm thấy sản phẩm phù hợp theo slug.");
			return;
		}

	$product_id = $product_ids[0];
    $update_fields = $info['update']?? [];
    $product_data  = [
        'ID' => $product_id,
    ];
	
	back_trace('NOTICE', 'Noi dung sua:'.$product_id.' '.print_r($update_fields, true) );
    if (!empty($update_fields['title'])) $product_data['post_title'] = $update_fields['title'];
    if (!empty($update_fields['description'])) $product_data['post_content'] = $update_fields['description'];

    // Cập nhật dữ liệu chuẩn sản phẩm
    if (count($product_data) > 1) wp_update_post($product_data);

    if (!empty($update_fields['price'])) update_post_meta($product_id, '_regular_price', $update_fields['price']);
    if (!empty($update_fields['sale_price'])) update_post_meta($product_id, '_sale_price', $update_fields['sale_price']);
    if (!empty($update_fields['sale_price'])) update_post_meta($product_id, '_price', $update_fields['sale_price']);
    else if (!empty($update_fields['price'])) update_post_meta($product_id, '_price', $update_fields['price']);

    // Gán danh mục mới nếu có
    if (!empty($update_fields['category'])) {
        $term = get_term_by('name', $update_fields['category'], 'product_cat');
        if ($term) {
            wp_set_object_terms($product_id, [$term->term_id], 'product_cat');
        }
    }

    // Có thể upload ảnh nếu đi kèm (giống flow đăng sản phẩm)
    if (isset($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        $image_url = twf_telegram_get_file_url($file_id);
        $attachment_id = twf_upload_image_to_media_library($image_url, $update_fields['title'] ?? '');
        if ($attachment_id) set_post_thumbnail($product_id, $attachment_id);
    }

    $permalink = get_permalink($product_id);
    twf_telegram_send_message($chat_id, "Sản phẩm đã được cập nhật thành công: $permalink");
}

function twf_upload_image_to_media_library($image_url, $name = '') {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return 0;
    }
    $file_array = [
        'name'     => basename($image_url),
        'tmp_name' => $tmp
    ];
    $id = media_handle_sideload($file_array, 0, $name);
    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        return 0;
    }
    return $id;
}
//Hàm gọi AI để phân tích text đăbf sản phẩm
function twf_parse_product_info_ai($api_key, $user_input) {
    $prompt = "Phân tích đoạn văn sau để trích xuất thông tin sản phẩm cho đăng lên WooCommerce (nếu thiếu trường thì để trống): 
Đầu ra trả về định dạng JSON với các trường: 
{
  \"title\": \"Tên sản phẩm\",
  \"price\": \"Giá bán (nếu ký kiệu 'k' ví dụ 30k thì chuyển thành 30000)\",
  \"sale_price\": \"Giá khuyến mại (nếu ký kiệu 'k' ví dụ 30k thì chuyển thành 30000)\",
  \"category\": \"Tên danh mục\",
  \"description\": \"Mô tả sản phẩm\" 
}
Đoạn mô tả đây: 
#####
$user_input
#####
Lưu ý: Chỉ trả về một đối tượng JSON không thêm giải thích hoặc ký tự nào khác.";
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
    // Lọc JSON từ đoạn trả về nếu AI có lỡ trả dư ký tự
    $json = trim($json);
	
    if (($pos = strpos($json, '{')) !== false) {
        $json = substr($json, $pos);
    }
    if (($pos = strrpos($json, '}')) !== false) {
        $json = substr($json, 0, $pos + 1);
    }
    $data = json_decode($json, true);
    return $data ?: [];
}
//Hàm gọi AI để phân tích text sửa sản phẩm
function twf_parse_edit_product_info_ai($api_key, $user_input) {
    $prompt = "Phân tích đoạn văn sau để xác định sửa thông tin sản phẩm trong WooCommerce. Các trường cần:
{
  \"find_by\": \"id\" hoặc \"name\", // phân biệt người dùng nhập mã số hay nhập tên,
  \"identity\": \"(mã sản phẩm hoặc tên sản phẩm)\", 
  \"update\": {
      \"title\": \"Tên mới (nếu có)\",
      \"price\": \"Giá mới (nếu có) (nếu ký kiệu 'k' ví dụ 30k thì chuyển thành 30000)\",
      \"sale_price\": \"Giá khuyến mại mới (nếu có) (nếu ký kiệu 'k' ví dụ 30k thì chuyển thành 30000)\",
      \"category\": \"Tên danh mục mới (nếu có)\",
      \"description\": \"Mô tả mới (nếu có)\"
  }
}
Đoạn thông tin đây:
#####
$user_input
#####
Nếu price hoặc sale_price có ký tự k (thay cho 1000) thì hãy trả về dạng số. Ví dụ 89k thì trả về là 89000. Chỉ trả về một đối tượng JSON duy nhất.";
    
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
    $json = trim($json);
    if (($pos = strpos($json, '{')) !== false) {
        $json = substr($json, $pos);
    }
    if (($pos = strrpos($json, '}')) !== false) {
        $json = substr($json, 0, $pos + 1);
    }
    $data = json_decode($json, true);
    return $data ?: [];
}