<?php
if(!defined('ABSPATH')) exit;
/*
|--------------------------------------------------------------------------
| Xử lý tạo đơn hàng WooCommerce từ AI qua Telegram 
|--------------------------------------------------------------------------
*/
function biz_create_order($message, $chat_id) {
    return twf_handle_create_order_ai_flow($message, $chat_id);
}

function twf_handle_create_order_ai_flow($message, $chat_id) {
    global $wpdb, $woocommerce;
    $tmd_pos_order = $wpdb->prefix . "tmd_pos_order";
    $tmd_pos_order_products = $wpdb->prefix . "tmd_pos_order_products";

    // 1. Lấy dữ liệu đầu vào từ message hoặc AI
    $api_key    = get_option('twf_openai_api_key');
    $user_input = $message['text'] ?? $message['caption'];
    $ai_data    = twf_parse_order_info_ai($api_key, $user_input);
    #back_trace('NOTICE', 'twf_handle_create_order_ai_flow: '.print_r($ai_data,true));
    if (empty($ai_data['products'])) {
        twf_telegram_send_message($chat_id, "Không nhận diện được sản phẩm để tạo đơn.");
        return;
    }

    // 2. Tìm sản phẩm, chuẩn hóa cart_items, tính subtotal
    $subtotal = 0;
    $cart_items = [];
    foreach ($ai_data['products'] as $product_item) {
        $product = null;
        $qty     = absint($product_item['qty']);
        $identity = trim($product_item['identity']);

        // Ưu tiên tìm theo ID, nếu không thì theo tên
        if (is_numeric($identity)) {
            $product = wc_get_product($identity);
        } else {
            $args = [
                'post_type' => 'product',
                'posts_per_page' => 3,
                's' => $identity,
                'post_status' => 'publish',
            ];
            $q = new WP_Query($args);
            foreach ($q->posts as $p) {
                if (strpos(vn_remove_accents(mb_strtolower($p->post_title, 'UTF-8')), vn_remove_accents(mb_strtolower($identity, 'UTF-8'))) !== false) {
                    $product = wc_get_product($p->ID);
                    break;
                }
            }
            if (!$product && !empty($q->posts)) {
                $product = wc_get_product($q->posts[0]->ID);
            }
        }
        if ($product) {
            $cart_items[] = [
                'product_id'   => $product->get_id(),
                'product_qty'  => $qty,
                'product_name' => $product->get_name(),
                'product_cost' => $product_item['price'] ?? $product->get_price(),
                'product_tax'  => 0,
                'currency'     => get_woocommerce_currency(),
            ];
            $price = $product->get_price();
            $subtotal += $qty * $price;
        }
    }

    // 3. Tính các khoản phí khác, tổng tiền, chuẩn hóa số điện thoại KH
    $shipping  = $ai_data['shipping_cost'] ?? 0;
    $discount  = $ai_data['discount'] ?? 0;
	
    $total     = $subtotal + $shipping - $discount;
    $customer_phone = !empty($ai_data['customer']['phone']) ? str_replace(' ','',$ai_data['customer']['phone']) : '';

    // 4. Tạo đơn WooCommerce và POS
    if (!function_exists('wc_create_order')) {
        twf_telegram_send_message($chat_id, "WooCommerce không được kích hoạt.");
        return;
    }
    $order = wc_create_order();
    $pos_order_id = $order->get_id();
	

    // 4.1. Lấy thông tin phương thức thanh toán Techcombank (nếu có)
    $payment_id = '';
    $account_number = $account_name = $bank_name = '';
    if (class_exists('TTCKPayment') && function_exists('WC')) {
        $gateways = WC()->payment_gateways->payment_gateways();
        foreach ($gateways as $id => $gateway) {
            if (strpos($id, 'ttck_') === 0 && $gateway->enabled === 'yes') {
                $bank_id = str_replace('ttck_up_', '', $id);
                $payment_id = $id;
            }
        }
        if (!empty($bank_id)) {
            $plugin_settings = TTCKPayment::get_settings();
            $accounts = $plugin_settings['bank_transfer_accounts'][$bank_id] ?? [];
            if (!empty($accounts[0])) {
                $account_number = $accounts[0]['account_number'] ?? '';
                $account_name   = $accounts[0]['account_name'] ?? '';
                $bank_name      = $accounts[0]['bank_name'] ?? '';
            }
        }
    }

    // 5. Chuẩn hóa dữ liệu mảng order cho POS
    $pos_order_data = [
        'shop_customer'        => 0,
        'biz_customer_phone'   => $customer_phone,
        'biz_customer_name'    => $ai_data['customer']['name'] ?? '',
        'biz_customer_address' => $ai_data['customer']['address'] ?? '',
        'biz_customer_from'    => '',
        'payment_method'       => $ai_data['payment_id'] ?? ($ai_data['payment_method'] ?? 'cod'),
        'payment_id'           => $payment_id,
        'order_status'         => $ai_data['order_status'] ?? 'wc-pending',
        '_subtotal'            => $subtotal,
        'order_total'          => $total,
        'shipping_cost'        => $shipping,
        'discount'             => $discount,
        'paid_amount'          => $ai_data['paid_amount'] ?? $total,
        'change'               => $ai_data['change'] ?? 0,
        'order_note'           => $ai_data['order_note'] ?? '',
        'wt_ship_total'        => $ai_data['wt_ship_total'] ?? ($subtotal + $shipping),
        'wt_dis_total'         => $ai_data['wt_dis_total'] ?? $total,
        'tax_total'            => $ai_data['tax_total'] ?? 0.0,
        'cashier'              => $ai_data['cashier'] ?? '',
        'cashier_id'           => $ai_data['cashier_id'] ?? get_current_user_id(),
        'existing_customer'    => $ai_data['existing_customer'] ?? 0,
    ];
	if(function_exists('tmd_pos_order_creat_customer')):
		$customer_id = tmd_pos_order_creat_customer($pos_order_data);
		back_trace('NOTICE', 'Thông tin đơn hàng: ' . print_r($pos_order_data, true));
		$wpdb->insert($tmd_pos_order, [
			'customer_id' => $customer_id,
			'order_meta'  => $pos_order_id,
			'order_value' => wp_json_encode($pos_order_data)
		]);
	endif;

    // 6. Thêm SP vào đơn Woo/POS
    $msg_product = '';
    foreach ($cart_items as $i => $item) {
        $product = wc_get_product($item['product_id']);
        // Lưu POS chi tiết
        $wpdb->insert($tmd_pos_order_products, [
            'order_id'     => $pos_order_id,
            'product_id'   => $item['product_id'],
            'product_qty'  => $item['product_qty'],
            'product_name' => $item['product_name'],
            'product_cost' => $item['product_cost'],
            'product_tax'  => $item['product_tax'],
            'currency'     => $item['currency'],
        ]);

        if ($product) {
            $order->add_product($product, $item['product_qty']);
            // Trừ tồn kho
            $product->set_stock_quantity($product->get_stock_quantity() - $item['product_qty']);
            $product->save();
            $order->set_total( $item['product_qty'] * $product->get_price() );
        }
        $msg_product .= ($i+1) . ') ' . $item['product_name'] . ' x ' . $item['product_qty'] . "\n";
    }

	if(function_exists('tmd_pos_get_customer_by_id')):
		// 7. Gán địa chỉ, ghi chú, chủ đơn cho Woo
		$get_customer = tmd_pos_get_customer_by_id($customer_id);
		$billing_address = [
			'first_name' => $get_customer->customer_name,
			'phone'      => $get_customer->customer_phone,
			'address_1'  => $get_customer->customer_address_1,
			'email'      => $get_customer->customer_email,
		];
	endif;
    if (empty($billing_address['first_name'])) {
        $billing_address = ['first_name' => 'AI Ra đơn', 'last_name' =>'BizGPT POS'];
    }
    $order->set_address($billing_address, 'billing');
    $order->set_address($billing_address, 'shipping');
    $order->set_customer_id($customer_id);
    $order->set_created_via('programatically');
    $order_note   = sanitize_textarea_field($pos_order_data['order_note']);
    $order->add_order_note($order_note);
    $order->set_customer_note($order_note);

    // 8. Gán cổng thanh toán phù hợp
    $payment_gateways = WC()->payment_gateways->payment_gateways();
    $payment_id = $pos_order_data['payment_id'];
    if ($ai_data['payment_method'] == 'cod') $payment_id = 'cod';
    if (isset($payment_gateways[$payment_id])) {
        $order->set_payment_method($payment_gateways[$payment_id]);
    }

    // 9. Áp dụng coupon nếu có
    if (!empty($ai_data['coupon_code'])) {
        $order->apply_coupon($ai_data['coupon_code']);
    }
	
	//order validation
    $tmd_order       = wc_get_order( $pos_order_id );
    $pos_order_value = $tmd_order->get_total();
    // if (!empty($tmd_change)) {
    //     add_post_meta($order_id, '_tmd_change', $tmd_change, true);
    //     update_post_meta($pos_order_id, '_tmd_change', $tmd_change);
    // }

    if(!empty($discount)):
        // add discount ammount to order total
        update_post_meta($pos_order_id,'_order_total', $pos_order_value - $discount ); 
    endif;

    // 10. Hoàn tất đơn hàng: tính total, status, save
    $order->calculate_totals();
    $order_status = sanitize_text_field($pos_order_data['order_status']);
    $order->update_status($order_status);
    $order->save();
	
	
	

    // 11. Thông báo + gửi QR code chuyển khoản qua Telegram
    $order_total = $order->get_total();
	if(!$order_total) $total = $order_total = get_post_meta( $pos_order_id, '_order_total', true );
    $qr_code_url = get_home_url() . '/vietqr/?order_id=' . $pos_order_id . '&get_amount=' . $order_total.'&type=image.jpg';
    $order_link  = get_home_url() . '/pos-screen-print/?order_id=' . $pos_order_id.'  ';
	
	#$msg_product .= '\nSubtotal: '.$subtotal.'';
	#$msg_product .= '\nDiscount: '.$discount.'';
	#$msg_product .= '\nTổng: '.$total.'';
	
    $notif_msg = <<<HTML
		<b>💵 Đơn hàng đã tạo!</b>
		<a href="{$order_link}">👉 <b>Xem hóa đơn / Mở bill</b></a>
		
		🛒 <b>Sản phẩm:</b> $msg_product
		<b>Subtotal:</b> {$subtotal}
		<b>Discount:</b> {$discount}
		<b>Tổng:</b> {$total}
		
		👤 <b>Khách chuyển khoản:</b>
		🔢 <b>STK:</b> <code>{$account_number}</code>
		👤 <b>Tên tk:</b> <code>{$account_name}</code>
		🏦 <b>Ngân hàng:</b> <code>{$bank_name}</code>
		HTML;

    #if(empty(get_zalo_option($chat_id))):
		twf_telegram_send_message($chat_id, $notif_msg, 'HTML');
    	twf_telegram_send_photo($chat_id, $qr_code_url);
	#else:	
	#	send_zalo_botbanhang($notif_msg, $chat_id);
	#	send_zalo_botbanhang($qr_code_url, $chat_id, 'image');
		#twf_telegram_send_photo('zalo_' . $chat_id, $qr_code_url);
	#endif;	
	#send_zalo_botbanhang($qr_code_url, $chat_id, 'image');
	#twf_telegram_send_photo('zalo_' . $chat_id, 'https://img.tripi.vn/cdn-cgi/image/width=700,height=700/https://gcs.tripi.vn/public-tripi/tripi-feed/img/482784oup/anh-mo-ta.png');
	$msg = sanitize_text_field('Đơn hàng ID '.$order->get_id().' vừa được SĐT '.$billing_phone.' ('.$user_id.') tạo vào lúc '.$current_time.' với tổng giá trị tiền '.$order_total.' vnd: ' . implode(', ', $product_details));	
		#WPC_PendingEmail::wp_kpoint($msg, $billing_phone, 'order_creat', $order->get_id());
	WPC_PendingEmail::wp_kpoint($msg, '', 'send_zalo', $order->get_id());
	#WPC_PendingEmail::wp_kpoint($notif_msg, '', 'send_doctor_admin', time());
    // 12. Gửi thông báo Zalo (nếu có)
    /*global $pmfacebookbot_settings;
    $zaloToken = $pmfacebookbot_settings['zalotoken'];
    $userTokenArray = explode(';', $zaloToken);
    
    $msg_post = 'PM Bán hàng vừa ra đơn '.$pos_order_id.', tổng bill: '.$total.': \n'.$msg_product.'\n';
    $msg_post .= 'Số tiền'.$order_total;
    foreach($userTokenArray as $v){
        WPC_PendingEmail::wp_kpoint($notif_msg, '0931576886', 'send_kpoint_admin', $pos_order_id);
        WPC_PendingEmail::wp_kpoint($notif_msg, '', 'send_doctor_admin', time()+2);
    }*/
}


function twf_parse_order_info_ai($api_key, $user_input) {
    $prompt = "Phân tích đoạn văn sau ra dữ liệu đơn hàng WooCommerce. Trả về theo chuẩn JSON như sau:
{
  \"customer\": {
      \"name\": \"Tên khách hàng\",
      \"phone\": \"SĐT khách\",
      \"email\": \"Email (nếu có)\",
      \"address\": \"Địa chỉ giao\"
  },
  \"products\": [
    {
      \"identity\": \"Tên hoặc mã sản phẩm\",
      \"qty\": \"Số lượng (nếu gặp trường hợp 1 suất hoặc 1 set thì là 1)\",
      \"price\": giá bán (có thể bỏ trống)
    }
  ],
  \"payment_method\": \"chuyển khoản hoặc tiền mặt thì là COD\",
  \"shipping_cost\": số tiền ship (nếu có),
  \"discount\": số tiền giảm cho khách dạng number (nếu ký kiệu 'k' ví dụ 30k thì chuyển thành 30000),
  \"coupon_code\": \"mã coupon\",
  \"order_note\": \"ghi chú, nếu có\"
}
ĐOẠN ĐẦU VÀO:
-----
$user_input
-----
Chỉ trả lời một JSON duy nhất.";
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
    $json = trim($json);
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $data = json_decode($json, true);
    return $data ?: [];
}

function twf_map_payment_id($ai_payment_method, $tmd_pos_payment_gateways) {
    $ai_payment_method = mb_strtolower(trim($ai_payment_method));
    foreach ($tmd_pos_payment_gateways as $payment_gateway_id => $payment_title) {
        // So sánh chuỗi (giảm dấu, lowercase, loại bỏ khoảng trắng...)
        $title_comp = mb_strtolower(trim($payment_title));
        if ($ai_payment_method === $title_comp) return $payment_gateway_id;

        // Nếu AI trả về dạng rút gọn
        if (
            (strpos($ai_payment_method, 'techcombank') !== false && strpos($title_comp, 'techcombank') !== false) ||

            (strpos($ai_payment_method, 'tpbank') !== false && strpos($title_comp, 'tpbank') !== false) ||
            (strpos($ai_payment_method, 'tiền mặt') !== false && strpos($title_comp, 'tiền mặt') !== false) ||
            (strpos($ai_payment_method, 'cod') !== false && strpos($payment_gateway_id, 'cod') !== false)
        ) return $payment_gateway_id;

        // Liên quan đến "chuyển khoản" thì lấy cái nào là bank
        if (
            (strpos($ai_payment_method, 'chuyển khoản') !== false || strpos($ai_payment_method, 'bank') !== false)
            && (strpos($title_comp, 'tpbank') !== false || strpos($title_comp, 'techcombank') !== false)
        ) return $payment_gateway_id;
    }
    // Không map được, trả về mặc định "cod"
    return 'cod';
}