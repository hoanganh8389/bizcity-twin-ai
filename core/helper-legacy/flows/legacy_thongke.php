<?php

function twf_get_order_stats_range($date_start, $date_end) {
    $args = [
        'post_type'      => 'shop_order',
        'post_status'    => [ 
            'wc-pending',    // Chờ thanh toán
            'wc-processing', // Đang xử lý
            'wc-on-hold',    // Tạm giữ
            'wc-completed',  // Đã hoàn tất
            'wc-cancelled',  // Đã huỷ (bổ sung)
        ],
        'posts_per_page' => -1,
        'date_query'     => [
            [
                'after'     => $date_start . ' 00:00:00',
                'before'    => $date_end . ' 23:59:59',
                'inclusive' => true,
            ],
        ],
        'fields' => 'ids',
    ];
    $orders = get_posts($args);

    $total_orders    = 0;
    $total_amount    = 0;
    $total_by_status = [];
    $amount_by_status= []; // Có thể bổ sung nếu cần thống kê tổng tiền theo trạng thái
    $product_counter = [];

    if (!function_exists('wc_get_order')) {
        return [];
    }

    foreach ($orders as $order_id) {
        $order  = wc_get_order($order_id);
        if (!$order) continue;
        $status = $order->get_status();
        $total_orders++;
        $total_by_status[$status] = ($total_by_status[$status] ?? 0) + 1;
        $order_total = $order->get_total();
        $total_amount += $order_total;
        $amount_by_status[$status] = ($amount_by_status[$status] ?? 0) + $order_total;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($product_id) {
                $product_counter[$product_id] = ($product_counter[$product_id] ?? 0) + $item->get_quantity();
            }
        }
    }

    arsort($product_counter);
    $best_selling = [];
    foreach ($product_counter as $pid => $qty) {
        $product = wc_get_product($pid);
        if ($product) {
            $best_selling[] = [
                'product_id'   => $pid,
                'product_name' => $product->get_name(),
                'quantity'     => $qty
            ];
        }
    }

    return [
        'date_start'      => $date_start,
        'date_end'        => $date_end,
        'total_orders'    => $total_orders,
        'total_amount'    => $total_amount,
        'total_by_status' => $total_by_status,
        'amount_by_status'=> $amount_by_status,     // <- Nếu cần thống kê tiền theo trạng thái
        'best_selling'    => $best_selling,
    ];
}
//Hàm lấy thống kê hôm nay
function twf_get_today_order_stats() {
    $date = date('Y-m-d');
    return twf_get_order_stats_range($date, $date);
}
//Hàm format chuỗi báo cáo (range tuỳ ý)
function twf_format_range_report($stats, $is_today = false) {
    $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');

    if ($is_today) {
        $msg  = "📊 *BÁO CÁO ĐƠN HÀNG HÔM NAY (".date('d/m/Y', strtotime($stats['date_start'])).")*\n";
    } else {
        $msg  = "📊 *BÁO CÁO ĐƠN HÀNG Woo từ " . date('d/m/Y', strtotime($stats['date_start'])) . " đến " . date('d/m/Y', strtotime($stats['date_end'])) . "*\n";
    }

    $msg .= "Số đơn: *" . $stats['total_orders'] . "*\n";
    $msg .= "Tổng doanh thu: *". number_format($stats['total_amount']) . " $currency*\n";
    $msg .= "Chi tiết trạng thái:\n";
    foreach ($stats['total_by_status'] as $stt => $count) {
        $msg .= "- " . ucfirst($stt) . ": $count\n";
    }
    if (count($stats['best_selling'])) {
        $msg .= "Sản phẩm bán chạy:\n";
        foreach (array_slice($stats['best_selling'], 0, 3) as $sp) {
            $msg .= "- " . $sp['product_name'] . " (SL: " . $sp['quantity'] . ")\n";
        }
    }
    return $msg;
}

//Báo cáo 10 ngày gần nhất (mỗi ngày 1 dòng)
function twf_report_last_10_days() {
    $result = [];
    $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
    for ($i = 9; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats = twf_get_order_stats_range($date, $date);
        $result[] = date('d/m', strtotime($date)).": ".$stats['total_orders']." đơn, ".number_format($stats['total_amount'])." $currency";
    }
    return "📅 *BÁO CÁO 10 NGÀY GẦN NHẤT:*\n" . implode("\n", $result);
}

function twf_handle_ai_report_request($ai_data, $chat_id) {
    // Mặc định là báo cáo hôm nay
    $type = $ai_data['type'] ?? 'today';

    switch ($type) {
        case 'daily':
            // Nếu AI yêu cầu “báo cáo N ngày gần nhất” thì tự xử lý số lượng (dưới 1 là 1)
            $num_days = !empty($ai_data['days']) ? intval($ai_data['days']) : 1;
            $num_days = max(1, $num_days);
            // Nếu = 1 thì như báo cáo hôm nay
            if ($num_days == 1) {
                $today_stats = twf_get_today_order_stats();
                $msg = twf_format_range_report($today_stats, true);
                twf_telegram_send_message($chat_id, $msg);
            } else {
                // Custom báo cáo nhiều ngày mỗi ngày một line
                $result = [];
                $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
                for ($i = $num_days - 1; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $stats = twf_get_order_stats_range($date, $date);
                    $result[] = date('d/m', strtotime($date)).": ".$stats['total_orders']." đơn, ".number_format($stats['total_amount'])." $currency";
                }
                $msg = "📅 *BÁO CÁO $num_days NGÀY GẦN NHẤT:*\n" . implode("\n", $result);
                twf_telegram_send_message($chat_id, $msg);
            }
            break;

        case 'weekly':
            // Nếu AI yêu cầu tuần hiện tại
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end   = date('Y-m-d', strtotime('sunday this week'));
            $week_stats = twf_get_order_stats_range($week_start, $week_end);
            $msg = twf_format_range_report($week_stats, false);
            twf_telegram_send_message($chat_id, $msg);
            break;

        case 'monthly':
            // Nếu AI trả về số tháng gần nhất
            $num_months = !empty($ai_data['months']) ? intval($ai_data['months']) : 1;
            $num_months = max(1, $num_months);
            if ($num_months == 1) {
                $month_start = date('Y-m-01');
                $month_end   = date('Y-m-t');
                $month_stats = twf_get_order_stats_range($month_start, $month_end);
                $msg = twf_format_range_report($month_stats, false);
                twf_telegram_send_message($chat_id, $msg);
            } else {
                $result = [];
                $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
                for ($m = $num_months-1; $m >= 0; $m--) {
                    $month_time = strtotime("-$m months");
                    $start = date('Y-m-01', $month_time);
                    $end = date('Y-m-t', $month_time);
                    $stats = twf_get_order_stats_range($start, $end);
                    $result[] = date('m/Y', $month_time).": ".$stats['total_orders']." đơn, ".number_format($stats['total_amount'])." $currency";
                }
                $msg = "📅 *BÁO CÁO $num_months THÁNG GẦN NHẤT:*\n" . implode("\n", $result);
                twf_telegram_send_message($chat_id, $msg);
            }
            break;

        case 'today':
        default:
            // Báo cáo hôm nay - fallback
            $today_stats = twf_get_today_order_stats();
            $msg = twf_format_range_report($today_stats, true);
            twf_telegram_send_message($chat_id, $msg);
            break;
    }
}



/**
 * Hàm nhận JSON AI (type: daily/weekly/monthly...), tự động gọi function thống kê.
 * Trả về chuỗi báo cáo (đã format), có thể gửi telegram hoặc trả API.
 */
function twf_handle_ai_json_report($ai_json) {
    // Giải mã dữ liệu từ AI (phải là array, không phải string thô)
    if (is_string($ai_json)) $ai_data = json_decode($ai_json, true);
    else $ai_data = $ai_json;

    if (!$ai_data || !isset($ai_data['type'])) {
        // Nếu dữ liệu không đủ, fallback về hôm nay
        $stats = twf_get_today_order_stats();
        return twf_format_range_report($stats, true);
    }

    switch ($ai_data['type']) {
			case 'daily':
				$num_days = isset($ai_data['days']) ? max(1, intval($ai_data['days'])) : 1;
				$currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
				$label_map = [
					'pending' => 'Chờ thanh toán',
					'processing' => 'Đang xử lý',
					'on-hold'   => 'Tạm giữ',
					'completed' => 'Đã hoàn tất',
					'cancelled' => 'Đã hủy bỏ',
				];
				if ($num_days == 1) {
					$stats = twf_get_today_order_stats();
					$msg = "📊 <b>BÁO CÁO ĐƠN HÀNG HÔM NAY (".date('d/m/Y', strtotime($stats['date_start'])).")</b>\n";
					$msg .= "<pre>";
					$msg .= "Tổng đơn:     ".str_pad($stats['total_orders'],7)."\n";
					$msg .= "Doanh số:     ".number_format($stats['total_amount'])." $currency\n";
					$msg .= "---------------------\n";
					foreach($label_map as $stt => $label){
						$cnt = $stats['total_by_status'][$stt] ?? 0;
						$amt = $stats['amount_by_status'][$stt] ?? 0;
						$msg .= "$label:    ".str_pad($cnt, 4)." đơn, ".number_format($amt)." $currency\n";
					}
					$msg .= "</pre>";
					return $msg;
				} else {
					// Nhiều ngày: Mỗi ngày 1 block chi tiết
					$msg = "📅 <b>BÁO CÁO $num_days NGÀY GẦN NHẤT (chi tiết):</b>\n";
					for ($i = $num_days - 1; $i >= 0; $i--) {
						$date = date('Y-m-d', strtotime("-$i days"));
						$label = date('d/m', strtotime($date));
						$s = twf_get_order_stats_range($date, $date);
						$msg .= "\n<b>Ngày $label</b>:\n<pre>";
						$msg .= "Tổng đơn:   ".str_pad($s['total_orders'],4)."\n";
						$msg .= "Doanh số:   ".number_format($s['total_amount'])." $currency\n";
						foreach($label_map as $stt => $labelStt){
							$cnt = $s['total_by_status'][$stt] ?? 0;
							$amt = $s['amount_by_status'][$stt] ?? 0;
							if($cnt > 0 || $amt > 0) {
								$msg .= "$labelStt: ".str_pad($cnt,3)." đơn, ".number_format($amt)." $currency\n";
							}
						}
						$msg .= "</pre>";
					}
					return $msg;
				}
			case 'weekly':
				$week_start = date('Y-m-d', strtotime('monday this week'));
				$week_end   = date('Y-m-d', strtotime('sunday this week'));
				$stats = twf_get_order_stats_range($week_start, $week_end);
				$currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
				$label_map = [
					'pending' => 'Chờ thanh toán',
					'processing' => 'Đang xử lý',
					'on-hold'   => 'Tạm giữ',
					'completed' => 'Đã hoàn tất',
					'cancelled' => 'Đã hủy bỏ',
				];
				$msg = "📈 <b>BÁO CÁO ĐƠN HÀNG TUẦN NÀY</b>\n";
				$msg .= "<i>Từ ".date('d/m/Y', strtotime($week_start))." đến ".date('d/m/Y', strtotime($week_end))."</i>\n<pre>";
				$msg .= "Tổng đơn:     ".str_pad($stats['total_orders'],7)."\n";
				$msg .= "Doanh số:     ".number_format($stats['total_amount'])." $currency\n";
				$msg .= "---------------------\n";
				foreach($label_map as $stt => $label){
					$cnt = $stats['total_by_status'][$stt] ?? 0;
					$amt = $stats['amount_by_status'][$stt] ?? 0;
					$msg .= "$label:    ".str_pad($cnt, 4)." đơn, ".number_format($amt)." $currency\n";
				}
				$msg .= "</pre>";
				return $msg;
			case 'monthly':
				// Nếu có 'month' và 'year', lấy chính xác tháng đó
					if (isset($ai_data['month']) && isset($ai_data['year'])) {
						$month = intval($ai_data['month']);
						$year = intval($ai_data['year']);
						$month_start = date('Y-m-01', strtotime("$year-$month-01"));
						$month_end   = date('Y-m-t', strtotime("$year-$month-01"));
						$stats = twf_get_order_stats_range($month_start, $month_end);
						$currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
						$msg = "📊 <b>BÁO CÁO ĐƠN HÀNG THÁNG $month/$year</b>\n";
						$msg .= "<i>Từ ".date('d/m/Y', strtotime($month_start))." đến ".date('d/m/Y', strtotime($month_end))."</i>\n";
						$msg .= "<pre>";
						$msg .= "Tổng đơn:     ".str_pad($stats['total_orders'],7)."\n";
						$msg .= "Doanh số:     ".number_format($stats['total_amount'])." $currency\n";
						$msg .= "---------------------\n";
						// Mapping status => label bạn muốn
						$label_map = [
						  'pending' => 'Chờ thanh toán',
						  'processing' => 'Đang xử lý',
						  'on-hold'   => 'Tạm giữ',
						  'completed' => 'Đã hoàn tất',
						  'cancelled' => 'Đã hủy bỏ',
						];
						foreach($label_map as $stt => $label){
							$cnt = $stats['total_by_status'][$stt] ?? 0;
							$amt = $stats['amount_by_status'][$stt] ?? 0;
							$msg .= "$label:    ".str_pad($cnt, 4)." đơn. Doanh số ".number_format($amt)." $currency\n";
						}
						$msg .= "</pre>";
						return $msg;
					}
					// Nếu là dạng cũ "months" => giữ nguyên logic báo cáo các tháng gần nhất
					$num_months = isset($ai_data['months']) ? max(1, intval($ai_data['months'])) : 1;
					$currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
					if ($num_months == 1) {
						$month_start = date('Y-m-01');
						$month_end   = date('Y-m-t');
						$stats = twf_get_order_stats_range($month_start, $month_end);
						$msg = "📊 <b>BÁO CÁO ĐƠN HÀNG THÁNG NÀY</b>\n";
						$msg .= "<i>Từ ".date('d/m/Y', strtotime($month_start))." đến ".date('d/m/Y', strtotime($month_end))."</i>\n";
						$msg .= "<pre>";
						$msg .= "Tổng đơn:     ".str_pad($stats['total_orders'],7)."\n";
						$msg .= "Doanh số:     ".number_format($stats['total_amount'])." $currency\n";
						$msg .= "---------------------\n";
						$msg .= "Đã giao:      ".str_pad($stats['orders_completed'] ?? 0,7)."\n";
						$msg .= "Chờ xử lý:    ".str_pad($stats['orders_processing'] ?? 0,7)."\n";
						$msg .= "</pre>";
						return $msg;
					} else {
					$result = [];
					for ($m = $num_months - 1; $m >= 0; $m--) {
						$month_time = strtotime("-$m months");
						$start = date('Y-m-01', $month_time);
						$end = date('Y-m-t', $month_time);
						$s = twf_get_order_stats_range($start, $end);
						$result[] = [
							'label'  => date('m/Y', $month_time),
							'orders' => $s['total_orders'],
							'amount' => number_format($s['total_amount'])
						];
					}
					$msg = "📊 <b>BÁO CÁO $num_months THÁNG GẦN NHẤT:</b>\n<pre>";
					$msg .= str_pad('Tháng', 7) . "  Đơn hàng   Doanh số\n";
					$msg .= str_repeat('-', 30) . "\n";
					foreach($result as $r){
						$msg .= str_pad($r['label'], 7) . "   ";
						$msg .= str_pad($r['orders'], 9, ' ', STR_PAD_LEFT) . "   ";
						$msg .= str_pad($r['amount'].' '.$currency, 12, ' ', STR_PAD_LEFT) . "\n";
					}
					$msg .= "</pre>";
					return $msg;
				}
			default:
            // Fallback cho loại chưa xác định => báo cáo hôm nay
            $stats = twf_get_today_order_stats();
            return twf_format_range_report($stats, true);
    }
}


function twf_telegram_order_list_report($chat_id, $from_date, $to_date) {
    global $wpdb;
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_date FROM {$wpdb->posts} 
         WHERE post_type='shop_order' 
         AND post_date >= %s AND post_date <= %s 
         ORDER BY post_date DESC LIMIT 100",
        "$from_date 00:00:00", "$to_date 23:59:59"
    ));

    if (empty($posts)) {
        twf_telegram_send_message($chat_id, "Không có đơn hàng nào trong khoảng các ngày từ $from_date đến $to_date.", 'HTML');
        return;
    }
    
    // 1. Soạn tin nhắn như trước
    $msg = "📄 <b>Danh sách đơn hàng từ ".date('d/m/Y', strtotime($from_date))." đến ".date('d/m/Y', strtotime($to_date))."</b>\n<pre>";
    $msg .= str_pad('Mã', 7) . str_pad('Ngày', 12) . str_pad('Khách', 15) . str_pad('Tổng', 12);
    $msg .= " In\n";
    $msg .= str_repeat("-", 53)."\n";

    // 2. Chuẩn bị mảng log để xuất file
    $csv_rows = [];
    $csv_rows[] = ['Mã đơn', 'Ngày', 'Tên khách', 'SĐT', 'Tổng tiền', 'Trạng thái', 'Link in đơn'];
    foreach ($posts as $p) {
        $order = wc_get_order($p->ID);
        if (!$order) continue;

        $customer = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $total = number_format($order->get_total()).' '.get_woocommerce_currency_symbol();
        $phone = $order->get_billing_phone();
        $date = date('d/m H:i', strtotime($p->post_date));
        $order_id = $order->get_order_number();
        $status = wc_get_order_status_name($order->get_status());
        $print_url = home_url('/pos-screen-print/?order_id='.$p->ID);

        // Thêm vào msg
        $msg .= str_pad('#'.$order_id, 7)
            .str_pad($date, 12)
            .str_pad(mb_substr($customer,0,14),15)
            .str_pad($total, 12)
            ."</pre>\n"
            .'<a href="'.$print_url.'">🖨️ In đơn</a>'."\n<pre>";

        // Thêm vào CSV
        $csv_rows[] = [
            '#'.$order_id,
            $date,
            $customer,
            $phone,
            $total,
            $status,
            $print_url
        ];
    }
    $msg .= '</pre>';
    twf_telegram_send_message($chat_id, $msg, 'HTML');

    // 3. Xuất file CSV
    $upload_dir = wp_upload_dir();
    $file_name = 'ds_donhang_' . time() . '.csv';
    $file_path = $upload_dir['basedir'] . '/' . $file_name;
    $file_url  = $upload_dir['baseurl'] . '/' . $file_name;

    $f = fopen($file_path, 'w+');
    fwrite($f, "\xEF\xBB\xBF"); // BOM utf-8
    foreach($csv_rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);

    // Gửi link file CSV và gửi file qua Telegram
    twf_telegram_send_message($chat_id, "Tải file đơn hàng (CSV): $file_url");
    twf_send_telegram_document($chat_id, $file_path, " File danh sách đơn hàng từ $from_date đến $to_date");
}

function twf_telegram_order_list_report2($chat_id, $from_date, $to_date, $statuses = ['wc-completed','wc-processing','wc-pending','wc-on-hold']) {
    global $wpdb;
    $status_sql = '';
    if (!empty($statuses)) {
        $sts = array_map(function($s){return "'$s'";}, $statuses);
        $status_sql = "AND post_status IN (" . implode(',', $sts) . ")";
    }

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_date FROM {$wpdb->posts}
         WHERE post_type='shop_order'
         $status_sql
         AND post_date >= %s AND post_date <= %s
         ORDER BY post_date DESC LIMIT 100",
        "$from_date 00:00:00", "$to_date 23:59:59"
    ));

    if (empty($posts)) {
        twf_telegram_send_message($chat_id, "Không có đơn hàng nào trong khoảng các ngày từ $from_date đến $to_date.", 'HTML');
        return;
    }

    // Soạn tin nhắn Telegram như cũ (bảng/HTML nếu muốn)
    $msg = "📄 <b>Danh sách đơn hàng từ ".date('d/m/Y', strtotime($from_date))." đến ".date('d/m/Y', strtotime($to_date))."</b>\n";
    $msg .= "<pre>";
    $msg .= str_pad('Mã', 7) . str_pad('Ngày', 12) . str_pad('Khách', 15) . str_pad('Tổng', 12). str_pad('Trạng thái', 12) . "\n";
    $msg .= str_repeat("-", 46) . "\n";

    // Chuẩn bị mảng xuất CSV
    $csv_rows = [];
    $csv_rows[] = ['Mã đơn', 'Ngày', 'Tên khách', 'SĐT', 'Tổng tiền', 'Trạng thái'];

    foreach ($posts as $p) {
        $order = wc_get_order($p->ID);
        if (!$order) continue;
        $customer = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $customer = htmlspecialchars(mb_substr($customer,0,14), ENT_QUOTES, 'UTF-8');
        $total = number_format($order->get_total()).' '.get_woocommerce_currency_symbol();
        $date = date('d/m H:i', strtotime($p->post_date));
        $order_id = $order->get_order_number();
        $status = wc_get_order_status_name($order->get_status());
        $msg .= str_pad('#'.$order_id, 7)
            .str_pad($date, 12)
            .str_pad($customer, 15).' '
            .str_pad($total, 12)
			.str_pad($status, 12)
            ."\n";

        // Thêm dòng vào CSV
        $csv_rows[] = [
            '#'.$order_id,
            $date,
            $customer,
            $order->get_billing_phone(),
            $total,
            $status
        ];
    }
    $msg .= "</pre>";
    twf_telegram_send_message($chat_id, $msg, 'HTML');

    // 3. Xuất file CSV
    $upload_dir = wp_upload_dir();
    $file_name = 'ds_donhang_' . time() . '.csv';
    $file_path = $upload_dir['basedir'] . '/' . $file_name;
    $file_url  = $upload_dir['baseurl'] . '/' . $file_name;

    $f = fopen($file_path, 'w+');
    fwrite($f, "\xEF\xBB\xBF"); // BOM utf-8
    foreach($csv_rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);

    // Gửi link file CSV và gửi file qua Telegram
    twf_telegram_send_message($chat_id, "Tải file đơn hàng (CSV): $file_url");
    twf_send_telegram_document('zalo_'.$chat_id, $file_path, " File danh sách đơn hàng từ $from_date đến $to_date");
	send_zalo_botbanhang( $file_url,$chat_id, 'file');
}

function twf_parse_thong_ke_info_ai($user_input) {
	$api_key = get_option('twf_openai_api_key');
    $prompt = twf_prompt_daily_report_ai($user_input);
    $json = chatbot_chatgpt_call_omni_tele($api_key, $prompt);
	
    $json = trim($json);
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);
    $data = json_decode($json, true);
    return $data ?: [];
}


function twf_prompt_daily_report_ai($user_request) {
    $now = date('h:i:s d/m/Y');
    return <<<EOT
Bạn là trợ lý báo cáo thống kê đơn hàng cho hệ thống bán hàng.

Nhiệm vụ của bạn:
- Đọc yêu cầu báo cáo của người dùng: "$user_request".
- Nếu trong câu hỏi có nhắc tới số/tháng cụ thể (ví dụ: "doanh số tháng 3", "báo cáo tháng 4", "tháng 11/2022"), hãy xác định đúng tháng và năm. Nếu chỉ nói "tháng này" thì lấy tháng hiện tại.
- Trả về **duy nhất một đoạn JSON** đúng 1 trong các mẫu sau (KHÔNG giải thích gì thêm):

1. **Nếu yêu cầu về 1 tháng nào, trả về đúng đoạn này:**
{ 
  "type": "monthly", 
  "month": Số_tháng (1-12), 
  "year": Năm (4 số, ví dụ: 2023)
}

2. Nếu họ muốn báo cáo nhiều tháng gần nhất (ví dụ “3 tháng gần nhất”): trả về:
{ 
  "type": "monthly_range", 
  "from_month": Số_tháng_bắt_đầu, 
  "from_year": Năm_bắt_đầu, 
  "to_month": Số_tháng_kết_thúc, 
  "to_year": Năm_kết_thúc
}

3. Nếu họ hỏi về ngày hoặc nhiều ngày:
{ 
  "type": "daily", 
  "days": Số_ngày
}

4. Nếu họ hỏi về tuần:
{ 
  "type": "weekly" 
}

**Yêu cầu quan trọng:**
- Nếu văn bản không rõ tháng nào, mặc định là tháng hiện tại ($now).
- "Doanh số tháng 3" mặc định lấy năm hiện tại, trừ khi user rõ ràng hỏi tháng 3/2024/2023... thì dùng đúng năm đó.
- KHÔNG kèm bất kỳ giải thích/văn bản ngoài JSON nào!

**Ví dụ:**
- "Báo cáo tháng 3" => { "type": "monthly", "month": 3, "year": 2024 }
- "Doanh số tháng 4/2023" => { "type": "monthly", "month": 4, "year": 2023 }
- "Báo cáo 2 ngày gần nhất" => { "type": "daily", "days": 2 }
- "3 tháng gần nhất" => { "type": "monthly_range", "from_month": 3, "from_year": 2024, "to_month": 5, "to_year": 2024 }
- "Báo cáo tuần này" => { "type": "weekly" }

Yêu cầu người dùng: "$user_request"

Chỉ trả về 1 JSON đúng cú pháp!

EOT;
}