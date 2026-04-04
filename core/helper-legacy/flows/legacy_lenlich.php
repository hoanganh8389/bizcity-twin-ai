<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */



function twf_parse_schedule_post_ai($user_input) {
	if (!function_exists('chatbot_chatgpt_call_omni_tele')) {
		return [];
	}
	$api_key = get_option('twf_openai_api_key');
    $prompt = twf_prompt_schedule_post_ai($user_input);
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
	
    $json = trim($json);
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $data = json_decode($json, true);
    return $data ?: [];
}


function twf_prompt_schedule_post_ai($user_request) {
	$now =  date('Y-m-d H:i');
	date_default_timezone_set('Asia/Ho_Chi_Minh');
	 $current_time = date('Y-m-d H:i');
    $timezone = 'Asia/Ho_Chi_Minh';
    return <<<EOT
Giờ hiện tại là: {$current_time} (múi giờ: {$timezone}).
Hãy dựa vào câu yêu cầu lên lịch, ví dụ: "Đăng bài sau 10 phút nữa", "Hẹn đăng bài sau 1 tiếng nữa", hoặc "Lên lịch đăng bài vào ngày mai lúc 8 giờ sáng", để tính chính xác giá trị thời gian thực hiện.
Hãy trả về trường "post_datetime" là chuỗi thời gian đầy đủ, định dạng YYYY-mm-dd HH:ii.


Hãy phân tích yêu cầu của người dùng và trả về duy nhất một JSON với cấu trúc:
{
  "post_title": "...",
  "post_content": "...",
  "post_image_url": "...",
  "post_datetime": "YYYY-mm-dd HH:ii",
  "post_category": "..."
}
- Nếu thiếu thông tin, để trống.
- Lưu ý post_datetime phải đúng format "YYYY-mm-dd HH:ii".
- Nếu không có ảnh, "post_image_url" để rỗng.
- Chỉ trả về JSON, không chú thích!

Ví dụ:
Yêu cầu: "Đăng bài giới thiệu sản phẩm ABC vào 15h ngày 25/04/2025 kèm ảnh này: https://...."

Kết quả:
{
  "post_title": "Giới thiệu sản phẩm ABC",
  "post_content": "Nội dung bài đăng ...",
  "post_image_url": "https://....",
  "post_datetime": "2025-04-25 15:00",
  "post_category": ""
}

Yêu cầu người dùng: "$user_request"
EOT;
}

function twf_schedule_wp_post($schedule_info, $chat_id='') {
    // Chuẩn bị dữ liệu post
    $postarr = [
        'post_title'   => $schedule_info['post_title'],
        'post_content' => $schedule_info['post_content'],
        'post_status'  => 'future',
        'post_type'    => 'post',
        'post_date'    => $schedule_info['post_datetime'], // Định dạng Y-m-d H:i:s
        //'post_category' => array(),
    ];

    // Đăng post với lịch
    $post_id = wp_insert_post($postarr);
	
	// 2. Sinh ảnh đại diện tự động (nếu bạn có AI sinh ảnh/gán mặc định)
    $image_url = twf_generate_image_url($postarr['post_title']);

    // 3. Nếu có image_url thì tải ảnh về và gán featured image
    if ($post_id && !empty($image_url)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $tmp = download_url($image_url);

        if(!is_wp_error($tmp)) {
            $file = [
                'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
                'type'     => 'image/jpeg', // Bạn có thể dùng hàm getimagesize để xác định đúng mime nếu cần
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
    }
	twf_telegram_send_message($chat_id, "Bài dự kiến sẽ đăng: ".get_permalink($post_id));

    return $post_id;
}

function twf_upload_image_from_url($image_url, $post_id = 0) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    // Tải file về
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) return false;
    $file_array = [
        'name'     => basename($image_url),
        'tmp_name' => $tmp
    ];
    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return false;
    }
    return $attachment_id;
}