<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;
// 1. Đăng ký post type 'biztask'
add_action('init', function() {

    if ( ! is_admin() ) return;

    $labels = array(
        'name'               => 'BizGPT Task',
        'singular_name'      => 'Biz Task',
        'menu_name'          => 'Nhắc việc của tôi',
        'name_admin_bar'     => 'Biz Task',
        'add_new'            => 'Thêm nhắc việc',
        'add_new_item'       => 'Thêm nhắc việc mới',
        'new_item'           => 'Nhắc việc mới',
        'edit_item'          => 'Chỉnh sửa nhắc việc',
        'view_item'          => 'Xem nhắc việc',
        'all_items'          => 'Tất cả nhắc việc qua Zalo',
        'search_items'       => 'Tìm nhắc việc',
        'not_found'          => 'Không tìm thấy nhắc việc nào.',
        'not_found_in_trash' => 'Không có nhắc việc nào trong thùng rác.'
    );

    $args = array(
        'labels'        => $labels,
        'public'        => true,
        'has_archive'   => true,
        'rewrite'       => array('slug' => 'biztask'),
        'supports'      => array('title', 'editor', 'thumbnail', 'custom-fields', 'author'),
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-yes',
        'show_in_menu' => 'bizlife_dashboard', // ✅ đúng chỗ
        'capability_type' => 'post',
        'taxonomies'    => array('biztask_category')
    );

    register_post_type('biztask', $args);
}, 20); // ⚠️ priority > menu cha


// 2. Đăng ký taxonomy phân loại công việc
add_action('init', function() {
    $labels = array(
        'name' => 'Loại nhắc việc',
        'singular_name' => 'Phân loại',
        'search_items' => 'Tìm phân loại',
        'all_items' => 'Tất cả phân loại',
        'edit_item' => 'Chỉnh sửa phân loại',
        'update_item' => 'Cập nhật phân loại',
        'add_new_item' => 'Thêm phân loại',
        'new_item_name' => 'Phân loại mới',
        'menu_name' => 'Phân loại'
    );
    $args = array(
        'hierarchical' => true,
        'labels' => $labels,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => array('slug' => 'biztask-category')
    );
    register_taxonomy('biztask_category', array('biztask'), $args);
});

// 3. Meta box nhắc lịch + lặp lại + OCR
add_action('add_meta_boxes', function() {
    add_meta_box('biztask_reminder_box', 'Cài đặt nhắc việc & lặp lại', function($post) {
        $reminder_time = get_post_meta($post->ID, '_reminder_time', true);
        $repeat_daily = get_post_meta($post->ID, '_repeat_daily', true);
        echo '<label for="reminder_time">Nhắc lúc (YYYY-MM-DD HH:MM):</label><br>'; 
        echo '<input type="text" name="reminder_time" value="'.esc_attr($reminder_time).'" style="width:100%"><br><br>';
        echo '<label><input type="checkbox" name="repeat_daily" value="1" '.checked($repeat_daily, '1', false).'> Nhắc lặp lại hàng ngày</label>';
    }, 'biztask', 'side');
});

add_action('save_post', function($post_id) {
    if (isset($_POST['reminder_time'])) {
        update_post_meta($post_id, '_reminder_time', sanitize_text_field($_POST['reminder_time']));
    }
    update_post_meta($post_id, '_repeat_daily', isset($_POST['repeat_daily']) ? '1' : '0');
});

// Hiern thị thêm các cột trong danh sách
add_filter('manage_biztask_posts_columns', 'biztask_add_custom_columns');
function biztask_add_custom_columns($columns) {
    $columns['reminder_time'] = '⏰ Nhắc lúc';
    $columns['repeat_daily'] = '🔁 Lặp lại';
    $columns['task_image'] = '🖼️ Hình ảnh';
    return $columns;
}
// Danh sách công việc
add_action('manage_biztask_posts_custom_column', 'biztask_render_custom_columns', 10, 2);
function biztask_render_custom_columns($column, $post_id) {
    switch ($column) {
        case 'reminder_time':
            $time = get_post_meta($post_id, '_reminder_time', true);
            echo $time ? esc_html(date('H:i d/m/Y', strtotime($time))) : '—';
            break;

        case 'repeat_daily':
            $repeat = get_post_meta($post_id, '_repeat_daily', true);
            echo $repeat ? '✅ Hằng ngày' : '—';
            break;

        case 'task_image':
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                $thumb_url = wp_get_attachment_image_src($thumbnail_id, 'thumbnail')[0];
                echo "<img src='" . esc_url($thumb_url) . "' width='60' height='60' style='object-fit:cover;border-radius:6px'>";
            } else {
                echo '—';
            }
            break;
    }
}
add_filter('manage_edit-biztask_sortable_columns', function($columns){
    $columns['reminder_time'] = 'reminder_time';
    return $columns;
});

add_action('pre_get_posts', function($query){
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('orderby') === 'reminder_time') {
        $query->set('meta_key', '_reminder_time');
        $query->set('orderby', 'meta_value');
    }
});

function biz_create_task($chat_id, $message, $arr, $platform) {
    return twf_create_biztask_from_ai($chat_id, $message, $arr, $platform);
}


function twf_create_biztask_from_ai($chat_id, $message, $arr, $platform) {
    $title = '';
    $content = '';
    $category = 'khac';
    $remind_at = '';
    $repeat_daily = false;

    // 🖼 OCR nếu có ảnh
    if (!empty($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        $image_url = twf_telegram_get_file_url($file_id);
        if ($image_url) {
            $ocr = twf_openai_vision_analyze($image_url);
            if ($ocr) {
                $content .= "\n\n📌 Nội dung từ ảnh:\n" . $ocr;
            }
        }
    }

    // 🧠 Phân tích AI
    $prompt = twf_parse_nhac_viec_prompt($user_text . "\n" . $message);
    #back_trace('NOTICE', 'Prompt AI Nhắc việc: ' . $prompt);

    $api_key = get_option('twf_openai_api_key');
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);

    // Parse JSON an toàn
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $parsed = json_decode($json, true);
    #back_trace('NOTICE', 'Parsed AI JSON: ' . print_r($parsed, true));

    if (is_array($parsed)) {
        $title = $parsed['title'] ?? $title;
        $content = $parsed['content'] ?? $content;
        $category = $parsed['category'] ?? $category;
        $remind_at = $parsed['remind_at'] ?? '';
        $repeat_daily = !empty($parsed['repeat_daily']) && $parsed['repeat_daily'] == true;
    }

    if (!$title) $title = 'Ghi chú mới từ Telegram';

    // 📝 Tạo post
    $post_id = wp_insert_post([
        'post_title'   => wp_strip_all_tags($title),
        'post_content' => trim($content),
        'post_type'    => 'biztask',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
    ]);

    if (!is_wp_error($post_id)) {
        wp_set_post_terms($post_id, [$category], 'biztask_category');
        if ($remind_at) update_post_meta($post_id, '_reminder_time', $remind_at);
        if ($repeat_daily) update_post_meta($post_id, '_repeat_daily', 1);
		if($platform=='zalo') update_post_meta($post_id, '_zalo_client_id', $chat_id);
		else update_post_meta($post_id, '_telegram_chat_id', $chat_id);

        twf_telegram_send_message($chat_id, "✅ Đã tạo công việc!\n📝 $title\n📅 Nhắc lúc: $remind_at\n🔁 Lặp lại: " . ($repeat_daily ? 'Có' : 'Không'));
        return $post_id;
    } else {
        twf_telegram_send_message($chat_id, "❌ Lỗi khi lưu công việc. Vui lòng thử lại.");
        return false;
    }
}
function twf_parse_nhac_viec_prompt($user_text) {
    $now = current_time('H:i d/m/Y'); // giờ tại VN theo WP config
    return "
⏰ Thời gian hiện tại tại Việt Nam là $now (GMT+7)

Phân tích câu sau và trả về duy nhất 1 JSON:

{
  \"title\": \"Tiêu đề công việc\",
  \"content\": \"Nội dung ghi chú\",
  \"category\": \"gia_dinh | van_phong | du_an | khac\",
  \"remind_at\": \"YYYY-MM-DD HH:MM\",  // theo giờ Việt Nam
  \"repeat_daily\": true | false
}

📌 Gợi ý nhận dạng:
- Các cụm như: “hàng ngày”, “ngày nào cũng”, “mỗi ngày” → repeat_daily = true
- Các cụm như: “30 phút nữa”, “8h sáng”, “14h chiều mai” → tự động tính remind_at theo giờ Việt Nam

🛑 Không giải thích. Chỉ trả về JSON.
Câu lệnh: \"$user_text\"
";
}

// Ngôn ngữ nhắc tự nhiên
function twf_generate_friendly_reminder($context) {
    $api_key = get_option('twf_openai_api_key');
    if (!$api_key) return "Đừng quên nhé! 🕒";

    $prompt = "Bạn là trợ lý nhắc việc thân thiện. Viết một lời nhắc nhẹ nhàng bằng tiếng Việt cho nội dung: \"$context\". Giữ văn phong lịch sự, không quá dài.";

    $response = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
    return wp_strip_all_tags($response);
}

function twf_check_and_remind_biztask() {
    $now = current_time('mysql');
    $current_time = strtotime($now);
    $args = [
        'post_type'      => 'biztask',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => '_reminder_time',
                'value'   => $now,
                'compare' => '<=',
                'type'    => 'DATETIME',
            ],
            [
                'key'     => '_reminded',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ];
    $query = new WP_Query($args);

    foreach ($query->posts as $post) {
        $post_id = $post->ID;
        $user_id = $post->post_author;
		$chat_id = get_post_meta($post_id, '_telegram_chat_id', true);
		$zalo_id = get_post_meta($post_id, '_zalo_client_id', true);
        #$chat_id = twf_get_chat_id_by_user_id($user_id); // bạn cần định nghĩa hàm này
        
        if (!$chat_id && !$zalo_id) continue;

        $title = get_the_title($post_id);
        $link = get_permalink($post_id);
        $context = "Bạn sắp đến giờ: $title\nThời gian: $now";
        $reminder_text = twf_generate_friendly_reminder($context);

        $reminder_msg = "🔔 <b>Nhắc việc:</b>\n<b>$title</b>\n\n🗨️ $reminder_text\n👉 <a href=\"$link\">Xem chi tiết</a>";
		
        if($chat_id) twf_telegram_send_message($chat_id, $reminder_msg, 'HTML');
		if($zalo_id) send_zalo_botbanhang($reminder_msg, $zalo_id);
        // Gắn mốc đã nhắc để không bị trùng
        update_post_meta($post_id, '_reminded', current_time('mysql'));

        // Nếu có repeat hàng ngày → đặt lại reminder cho ngày mai
        if (get_post_meta($post_id, '_repeat_daily', true)) {
            $remind_time = get_post_meta($post_id, '_reminder_time', true);
            $next_day = date('Y-m-d H:i:s', strtotime($remind_time . ' +1 day'));
            update_post_meta($post_id, '_reminder_time', $next_day);
            delete_post_meta($post_id, '_reminded'); // reset để nhắc lại
        }
    }
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => __('Every Minute')
    ];
    return $schedules;
});

if (!wp_next_scheduled('twf_check_biztask_reminder')) {
    wp_schedule_event(time(), 'every_minute', 'twf_check_biztask_reminder');
}

add_action('twf_check_biztask_reminder', 'twf_check_and_remind_biztask');
function twf_get_chat_id_by_user_id($user_id) {
    return get_user_meta($user_id, 'telegram_chat_id', true);
}