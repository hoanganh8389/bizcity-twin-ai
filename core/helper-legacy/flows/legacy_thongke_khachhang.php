<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */


//Hàm thống kê khách hàng theo doanh số/ngày
function twf_bao_cao_top_customers($chat_id, $date = '', $days = 3) {
    global $wpdb;

    // 1. Xác định khoảng thời gian
    if ($date) {
        $to_date = date('Y-m-d', strtotime($date));
        $from_date = date('Y-m-d', strtotime($to_date . ' -' . ($days-1) . ' days'));
    } else {
        $to_date = current_time('Y-m-d'); // ngày hôm nay
        $from_date = date('Y-m-d', strtotime($to_date . ' -' . ($days-1) . ' days'));
    }

    // 2. Lấy tất cả đơn hàng đã hoàn thành/trong khoảng thời gian
    $order_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ('wc-completed','wc-processing','wc-pending') 
            AND post_date >= %s AND post_date <= %s",
        $from_date . ' 00:00:00',
        $to_date . ' 23:59:59'
    ));

    if (empty($order_ids)) {
        twf_telegram_send_message($chat_id, "Không có đơn hàng nào để thống kê khách hàng từ $from_date đến $to_date.");
        return;
    }

    if (!function_exists('wc_get_order')) {
        twf_telegram_send_message($chat_id, "WooCommerce không được kích hoạt.");
        return;
    }

    // 3. Gom số liệu theo khách hàng
    $customers = []; // key = phone/email, value = [name, phone, email, so_don, tong_tien]
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        // Lấy căn cứ nhận diện
        $phone  = $order->get_billing_phone();
        $email  = $order->get_billing_email();
        $name   = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $key    = $phone ? $phone : $email;
		$status = $order->get_status(); // ví dụ 'pending', 'completed', 'processing'

        if(!$key) continue;
        if(!isset($customers[$key])){
            $customers[$key] = [
                'name'      => $name,
                'phone'     => $phone,
                'email'     => $email,
                'so_don'    => 0,
                'tong_tien' => 0,
				'statuses'  => []
            ];
        }
        $customers[$key]['so_don']++;
        $customers[$key]['tong_tien'] += floatval($order->get_total());
		// Đếm trạng thái: bạn có thể đếm tổng hoặc dùng trạng thái cuối cùng
    	$customers[$key]['statuses'][$status] = ($customers[$key]['statuses'][$status] ?? 0) + 1;
    }

    if (empty($customers)) {
        twf_telegram_send_message($chat_id, "Không có khách hàng nào trong khoảng thời gian này.");
        return;
    }

    // 4. Sắp xếp giảm dần theo doanh số
    uasort($customers, function($a, $b){
        return $b['tong_tien'] <=> $a['tong_tien'];
    });

    // 5. Soạn báo cáo text
    $msg = "📊 <b>Thống kê khách hàng theo doanh số</b>\n";
	$msg .= "<i>Từ {$from_date} đến {$to_date}</i>";
	$msg .= "\n<pre>\n";
	$msg .= str_pad("KH", 20) . str_pad("SĐT", 13) . str_pad("Email", 24) . str_pad("Số đơn", 7) . str_pad("Doanh số", 12) . str_pad("Trạng thái", 15) ."\n";
	$msg .= str_repeat('-', 76) . "\n";
	foreach ($customers as $kh) {
		$msg .= str_pad($kh['name'], 20)
			. str_pad($kh['phone'], 13)
			. str_pad($kh['email'], 24)
			. str_pad($kh['so_don'], 7)
			. str_pad(number_format($kh['tong_tien'],0,',','.'), 12)
			.  str_pad(implode(',', $status_display), 15)
			. "\n";
	}
	$msg .= "</pre>";
	twf_telegram_send_message($chat_id, $msg, 'HTML');

    // 6. Xuất file CSV
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/top_customers_' . time() . '.csv';
    twf_export_top_customers_to_csv($customers, $from_date, $to_date, $file_path);

    $file_url  = $upload_dir['baseurl'] . '/top_customers_' . time() . '.csv';
    $caption = "Em gửi sếp file danh sách khách hàng chi tiết từ $from_date đến $to_date (Excel CSV).".$file_url;
    twf_telegram_send_message($chat_id, $caption);
    twf_send_telegram_document($chat_id, $file_path, $caption);
    // unlink hoặc giữ lại file tuỳ ý
    @unlink($file_path);
}

//Hàm xuất CSV chuẩn UTF-8 BOM
function twf_export_top_customers_to_csv($customers, $from_date, $to_date, $file_path) {
    $f = fopen($file_path, 'w+');
    // BOM UTF-8 cho Excel
    fwrite($f, "\xEF\xBB\xBF");

    // Header
    fputcsv($f, ['Tên KH', 'SĐT', 'Email', 'Số đơn', 'Doanh số', 'Trạng thái', "Từ ngày", "Đến ngày"]);

	foreach ($customers as $kh) {
		$status_display = [];
		foreach ($kh['statuses'] as $st => $cnt) {
			$status_display[] = "{$st}:{$cnt}";
		}
		fputcsv($f, [
			$kh['name'],
			$kh['phone'],
			$kh['email'],
			$kh['so_don'],
			$kh['tong_tien'],
			implode(', ', $status_display),
			$from_date,
			$to_date
		]);
	}
    fclose($f);
}

function twf_parse_thong_ke_khach_hang_ai($user_input) {
    // Có thể tái sử dụng hàm prompt thời gian ở trên, chỉ đổi type nếu muốn!
     $now = date('Y-m-d'); // hoặc dùng current_time('Y-m-d') nếu muốn theo WP timezone
    return "Phân tích nội dung báo cáo sau: \"{$user_input}\".
	Trả về JSON với các trường sau:
	{
	  \"ngay\": \"YYYY-mm-dd\" (nếu yêu cầu cho 1 ngày cụ thể, vd 2024-06-10, nếu không thì để trống),
	  \"so_ngay\": <số nguyên> (nếu yêu cầu cho X ngày gần nhất, ví dụ: 3, 7... Nếu không có thì mặc định là 3),
	  \"type\": \"top_customer\" (luôn là top_customer cho báo cáo sản phẩm bán chạy)
	}
	Lấy ngày hiện tại là: {$now}. Không giải thích, chỉ trả về JSON đúng định dạng.";
}