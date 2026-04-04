<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */


function twf_handle_find_customer_order_by_phone($chat_id, $phone) {
    if (!function_exists('wc_get_order')) {
        twf_telegram_send_message($chat_id, "WooCommerce không được kích hoạt.");
        return;
    }
    global $wpdb;
	
    // Lấy post_id đơn hàng có billing_phone giống sdt tìm
    $order_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_phone' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 3",
        '%' . $wpdb->esc_like($phone) . '%' 
    ));

    if (empty($order_ids)) {
        twf_telegram_send_message($chat_id, "❌ Không tìm thấy đơn hàng nào gắn với SDT: $phone");
        return;
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        $info = [];
        $info[] = "Đơn hàng: #" . $order->get_order_number();
        $info[] = "Khách: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name();
        $info[] = "SĐT: " . $order->get_billing_phone();
        $info[] = "Email: " . $order->get_billing_email();
        $info[] = "Địa chỉ: " . $order->get_billing_address_1();
        $info[] = "Ngày đặt: " . $order->get_date_created()->date('d/m/Y H:i');
        $info[] = "Trạng thái: " . wc_get_order_status_name($order->get_status());
        $info[] = "Tổng tiền: " . wp_strip_all_tags(wc_price($order->get_total()));

        $products = [];
        foreach ($order->get_items() as $item) {
            $products[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $info[] = "SP: " . implode(', ', $products);

        twf_telegram_send_message($chat_id, implode("\n", $info));
    }
	twf_prompt_create_order_from_customer($chat_id, $wpdb->esc_like($phone));
	/*twf_telegram_send_message(
    $chat_id,
    "Bạn có muốn tiếp tục tạo đơn hàng cho khách hàng với SĐT: {$order->get_billing_phone()} không? 
	Nếu đồng ý, trả lời: 'tạo đơn hàng cho SĐT {$order->get_billing_phone()}' hoặc bấm: /taodonmoi_{$order->get_billing_phone()}"
	);*/
}

function twf_prompt_create_order_from_customer($chat_id, $phone) {
    // Lấy thông tin khách hàng mới nhất theo sđt từ bảng đơn hàng Woo
    global $wpdb;
	$phone = str_replace(' ', $phone);
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_phone' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
        '%' . $wpdb->esc_like($phone) . '%'
    ));
    if (!$order_id) {
        twf_telegram_send_message($chat_id, "Không tìm thấy khách hàng nào với SĐT: $phone để tạo đơn mới.");
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        twf_telegram_send_message($chat_id, "Không lấy được thông tin đơn hàng!");
        return;
    }
    // Chuẩn bị dữ liệu prompt (cho AI hoặc cho flow tạo đơn sẵn có)
    $customer_info = [
        'fullname'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'sdt'       => $order->get_billing_phone(),
        'email'     => $order->get_billing_email(),
        'address'   => $order->get_billing_address_1()
    ];
	// Lưu vào session, hoặc transient để biết lần tiếp user chat là với sdt này
    set_transient('twf_neworder_' . $chat_id, [
        'sdt' => $order->get_billing_phone(),
        'info' => $customer_info // tên, email, địa chỉ,...
    ], 600);
	
	
    $prompt = "Tạo đơn hàng mới cho khách hàng với thông tin:\n";
    foreach($customer_info as $k => $v) $prompt .= "$k: $v\n";
    $prompt .= "Mời bạn gửi tên sản phẩm và số lượng cần đặt tiếp. Ví dụ: \"Bún chay x2, nước ép x1\" hoặc 'thêm sản phẩm...'";

    twf_telegram_send_message($chat_id, $prompt);

    // Ở step tiếp theo, user chỉ cần gửi sp thì bạn lấy lại info khách để auto fill cho quá trình tạo đơn
}

function twf_prompt_get_customer_info_from_transient($chat_id, $message,$ai_result=''){
	
	// Kiểm tra nếu đã có transient sdt đang chờ tạo đơn
	$neworder = get_transient('twf_neworder_' . $chat_id);
	$sdt = $neworder['sdt']??'';
	$address = $neworder['info']['address']??'';
	$fullname = $neworder['info']['fullname']??'';
	$email = $neworder['info']['email']??'';
	$info = $ai_result['info']??'';
	#back_trace('NOTICE', 'twf_prompt_get_customer_info_from_transient: '.print_r($neworder,true) );
	if ($neworder && isset($neworder['sdt'])) {
		// $message['text'] bây giờ chỉ còn là danh mục sản phẩm/số lượng
		$product_input = $message['text']; // VD: "bún chay x2, nước ép x1"
		$prompt['text'] = 'Tạo đơn hàng cho khách '.$sdt.', email '.$email.', tên '.$fullname.', địa chỉ '.$address.', thanh toán:chuyển khoản, '.$info.'';
		// Gọi lại hàm cũ bạn có:
		#back_trace('NOTICE', 'product_input: '.$prompt );
		return twf_handle_create_order_ai_flow($prompt, $chat_id);
	
		// Xóa transient sau khi tạo đơn
		delete_transient('twf_neworder_' . $chat_id);
		return;
	}	
}