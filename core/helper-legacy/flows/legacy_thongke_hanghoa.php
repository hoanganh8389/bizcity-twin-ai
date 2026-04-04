<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Báo cáo Top Product (Telegram) - Clean UTF-8 / tránh BOM lỗi header
 *
 * Lưu ý:
 * - File PHP này phải lưu UTF-8 *KHÔNG BOM* để tránh lỗi header/output.
 * - BOM chỉ nên ghi vào CSV (để Excel nhận UTF-8), KHÔNG được có BOM ở file PHP.
 */

if (!defined('ABSPATH')) exit;

function twf_bao_cao_top_product($chat_id, $date = '', $days = 3) {
    global $wpdb;

    $days = max(1, (int)$days);

    // 1) Xác định khoảng thời gian
    if (!empty($date)) {
        $to_date   = date('Y-m-d', strtotime($date));
        $from_date = date('Y-m-d', strtotime($to_date . ' -' . ($days - 1) . ' days'));
    } else {
        $to_date   = current_time('Y-m-d'); // theo timezone WP
        $from_date = date('Y-m-d', strtotime($to_date . ' -' . ($days - 1) . ' days'));
    }

    if (!function_exists('wc_get_order')) {
        twf_telegram_send_message($chat_id, "WooCommerce không được kích hoạt.");
        return;
    }

    // 2) Khởi tạo
    $stats  = []; // [Y-m-d => [product_id => ['name'=>..., 'qty'=>...]]]
    $labels = []; // [product_id => name]

    // 3) Query từng ngày
    for ($i = 0; $i < $days; $i++) {
        $curr = date('Y-m-d', strtotime($from_date . " +{$i} days"));

        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
               AND post_status IN ('wc-completed','wc-processing')
               AND post_date >= %s
               AND post_date <= %s",
            $curr . ' 00:00:00',
            $curr . ' 23:59:59'
        ));

        if (empty($order_ids)) {
            continue;
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            foreach ($order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) continue;

                $pid  = (int)$item->get_product_id();
                $name = (string)$item->get_name();
                $qty  = (float)$item->get_quantity();

                if ($pid <= 0) continue;

                if (!isset($stats[$curr])) $stats[$curr] = [];
                if (!isset($stats[$curr][$pid])) {
                    $stats[$curr][$pid] = [
                        'name' => $name,
                        'qty'  => 0,
                    ];
                }

                $stats[$curr][$pid]['qty'] += $qty;
                $labels[$pid] = $name;
            }
        }
    }

    if (empty($labels)) {
        twf_telegram_send_message(
            $chat_id,
            "Không có sản phẩm nào được bán ra trong khoảng thời gian này ({$from_date} đến {$to_date})."
        );
        return false;
    }

    // 4) Xuất bảng thống kê (text)
    $msg  = "📈 *Thống kê sản phẩm bán từng ngày*\n";
    $msg .= "Từ {$from_date} đến {$to_date}\n\n";

    // Header dòng cột
    $msg .= str_pad('Sản phẩm', 30) . ' ';
    for ($i = 0; $i < $days; $i++) {
        $curr_label = date('d/m', strtotime($from_date . " +{$i} days"));
        $msg .= str_pad($curr_label, 8, ' ', STR_PAD_BOTH) . ' ';
    }
    $msg .= "\n";

    // Rows
    foreach ($labels as $pid => $name) {
        $msg .= str_pad($name, 30);
        for ($i = 0; $i < $days; $i++) {
            $curr = date('Y-m-d', strtotime($from_date . " +{$i} days"));
            $sl   = isset($stats[$curr][$pid]['qty']) ? $stats[$curr][$pid]['qty'] : 0;
            $msg .= str_pad((string)$sl, 8, ' ', STR_PAD_BOTH);
        }
        $msg .= "\n";
    }

    twf_telegram_send_message($chat_id, $msg);

    // 5) Export CSV + gửi file
    $upload_dir = wp_upload_dir();

    // Dùng 1 timestamp duy nhất để file_path và file_url khớp nhau (tránh lệch do time() gọi 2 lần)
    $ts       = time();
    $filename = 'top_product_' . $ts . '.csv';

    $file_path = trailingslashit($upload_dir['basedir']) . $filename;
    $file_url  = trailingslashit($upload_dir['baseurl']) . $filename;

    twf_export_top_product_to_csv($stats, $labels, $from_date, $days, $file_path);

    $caption = "Em tổng hợp ra file excel rồi đây ạ: " . $file_url;
    twf_telegram_send_message($chat_id, $caption);

    if (function_exists('back_trace')) {
        back_trace('NOTICE', 'twf_export_top_product_to_csv ' . $caption);
    }

    if (function_exists('twf_send_telegram_document')) {
        twf_send_telegram_document($chat_id, $file_path, $caption);
    }

    @unlink($file_path);

    return ['stats' => $stats, 'labels' => $labels];
}

function twf_parse_thong_ke_hang_hoa_info_ai($user_input) {
    $api_key = get_option('twf_openai_api_key');
    $prompt  = twf_ai_parse_time_prompt($user_input);

    $ai_resp = chatbot_chatgpt_call_omni_tele($api_key, $prompt);

    // Lấy đúng phần JSON nếu AI trả lẫn văn bản
    $json = trim((string)$ai_resp);
    if (($pos = strpos($json, '{')) !== false) $json = substr($json, $pos);
    if (($pos = strrpos($json, '}')) !== false) $json = substr($json, 0, $pos + 1);

    $info = json_decode($json, true);
    if (!is_array($info)) {
        $info = [
            'ngay'    => '',
            'so_ngay' => 3,
            'type'    => 'top_product',
        ];
    }

    // normalize
    if (!isset($info['so_ngay'])) $info['so_ngay'] = 3;
    $info['so_ngay'] = max(1, (int)$info['so_ngay']);
    if (!isset($info['ngay'])) $info['ngay'] = '';
    if (!isset($info['type'])) $info['type'] = 'top_product';

    return $info;
}

function twf_ai_parse_time_prompt($user_input) {
    $now = current_time('Y-m-d'); // theo timezone WP
    $user_input = (string)$user_input;

    return "Phân tích nội dung báo cáo sau: \"{$user_input}\".
Trả về JSON với các trường sau:
{
  \"ngay\": \"YYYY-mm-dd\" (nếu yêu cầu cho 1 ngày cụ thể, vd 2024-06-10, nếu không thì để trống),
  \"so_ngay\": <số nguyên> (nếu yêu cầu cho X ngày gần nhất, ví dụ: 3, 7... Nếu không có thì mặc định là 3),
  \"type\": \"top_product\" (luôn là top_product cho báo cáo sản phẩm bán chạy)
}
Lấy ngày hiện tại là: {$now}. Không giải thích, chỉ trả về JSON đúng định dạng.";
}

/**
 * Export CSV (UTF-8 for Excel):
 * - File PHP: UTF-8 NO BOM
 * - CSV: ghi BOM UTF-8 để Excel nhận diện tiếng Việt
 */
function twf_export_top_product_to_csv($stats, $labels, $from_date, $days, $file_path) {
    $days = max(1, (int)$days);

    $f = fopen($file_path, 'w+');
    if (!$f) return false;

    // BOM UTF-8 (chỉ cho CSV)
    fwrite($f, "\xEF\xBB\xBF");

    // Header
    $header = ['Sản phẩm'];
    for ($i = 0; $i < $days; $i++) {
        $curr_label = date('d/m', strtotime($from_date . " +{$i} days"));
        $header[] = $curr_label;
    }
    fputcsv($f, $header);

    // Data
    foreach ($labels as $pid => $name) {
        $row = [$name];
        for ($i = 0; $i < $days; $i++) {
            $curr = date('Y-m-d', strtotime($from_date . " +{$i} days"));
            $sl   = isset($stats[$curr][$pid]['qty']) ? $stats[$curr][$pid]['qty'] : 0;
            $row[] = $sl;
        }
        fputcsv($f, $row);
    }

    fclose($f);
    return true;
}
