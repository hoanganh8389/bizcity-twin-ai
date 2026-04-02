<?php


function twf_parse_phieu_nhap_kho_ai($user_text) {
    $api_key = get_option('twf_openai_api_key');
    $prompt = twf_prompt_parse_phieu_nhap_kho($user_text);

    $resp = chatbot_chatgpt_call_omni_tele($api_key, $prompt);

    // Lấy đúng phần JSON
    $json = trim($resp);
    if (($p = strpos($json, '{')) !== false) $json = substr($json, $p);
    if (($p = strrpos($json, '}')) !== false) $json = substr($json, 0, $p+1);

    $data = json_decode($json, true);

    // Chuẩn hóa mảng trả về cho function tạo phiếu
    return [
        'product_id'     => (isset($data['product']) && is_numeric($data['product'])) ? intval($data['product']) : 0,
        'qty'            => isset($data['qty']) ? intval($data['qty']) : 1,
        'buy_price'      => isset($data['buy_price']) ? floatval($data['buy_price']) : 0,
        'note'           => !empty($data['note']) ? trim($data['note']) : '',
        'product_search' => (!isset($data['product']) || is_numeric($data['product'])) ? '' : trim($data['product']),
    ];
}

function twf_prompt_parse_phieu_nhap_kho($user_text) {
    return "Phân tích câu sau để tạo phiếu nhập kho cho hệ thống WooCommerce. 
Hãy trả về một object JSON duy nhất với các trường:
{
  \"product\": \"Tên hoặc ID sản phẩm (nếu chỉ là số, coi là ID; nếu là text, giữ nguyên, hệ thống sẽ tự dò theo tên hoặc SKU)\",
  \"qty\": \"Số lượng nhập\",
  \"buy_price\": \"Giá mua sản phẩm (đồng, mỗi sản phẩm)\",
  \"note\": \"Ghi chú (nếu có)\"
}
Chỉ trả về JSON, không kèm bất kỳ giải thích nào khác.

Câu lệnh người dùng: \"$user_text\"
";
}

function twf_phieu_nhap_kho_from_telegram($chat_id, $data) {
    $product_id = $data['product_id'];
    // Nếu chưa có product_id, tìm kiếm theo tên
    if(!$product_id && !empty($data['product_search'])){
        $products = wc_get_products(['limit'=>1, 's'=>$data['product_search']]);
        $product_id = isset($products[0]) ? $products[0]->get_id() : 0;
    }
    if(!$product_id) {
        twf_telegram_send_message($chat_id, "Không tìm thấy sản phẩm phù hợp để nhập kho. Vui lòng nhập cú pháp đúng: tạo phiếu nhập kho [sản phẩm hoặc ID], số lượng [x], giá mua [y], ghi chú ...");
        return;
    }
    $qty = !empty($data['qty']) ? intval($data['qty']) : 1;
    $buy_price = isset($data['buy_price']) ? floatval($data['buy_price']) : 0;
    $note = $data['note'] ?? '';

    $product = wc_get_product($product_id);
    if (!$product || !$product->managing_stock()) {
        twf_telegram_send_message($chat_id, "Sản phẩm không tồn tại hoặc chưa bật quản lý tồn kho.");
        return;
    }
    $old_qty = $product->get_stock_quantity();
    $new_qty = $old_qty + $qty;
    $product->set_stock_quantity($new_qty);
    $product->save();

    // Lưu log
    twf_stock_log_add($product_id, 'import', $qty, $old_qty, $new_qty, $note, null, $buy_price);

    $reply = "✅ Đã nhập {$qty} sản phẩm ". $product->get_name() ."\n";
    if ($buy_price) $reply .= "Giá mua: ".number_format($buy_price,0,',','.')."\n";
    $reply .= "Tồn kho mới: {$new_qty}\n";
    if ($note) $reply .= "Ghi chú: ".$note;
    twf_telegram_send_message($chat_id, $reply);
}

function twf_bao_cao_xuat_nhap_ton_kho($chat_id, $from_date = '', $to_date = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'twf_stock_log';

    // Nếu chưa có filter thì mặc định lấy 7 ngày gần nhất
    if (!$to_date)     $to_date = date('Y-m-d');
    if (!$from_date)   $from_date = date('Y-m-d', strtotime("$to_date -6 days"));

    $where = $wpdb->prepare("created_at >= %s AND created_at <= %s", $from_date.' 00:00:00', $to_date.' 23:59:59');
    $sql = "SELECT * FROM $table WHERE $where";
    $result = $wpdb->get_results($sql);

    // Gom số liệu nhập-xuất từng sản phẩm
    $stat = [];
    foreach ($result as $row) {
        $pid = $row->product_id;
        if (!isset($stat[$pid])) $stat[$pid] = ['nhap'=>0, 'xuat'=>0];
        if ($row->action === 'import') $stat[$pid]['nhap'] += $row->qty;
        if ($row->action === 'export') $stat[$pid]['xuat'] += $row->qty;
    }

    // Tính tồn kho cuối kỳ theo Woo (thực tế)
    $msg = "📦 <b>Báo cáo xuất nhập tồn kho</b>\n";
	$msg .= "<i>Từ $from_date đến $to_date</i>\n\n";
	$msg .= "<pre>".str_pad("Tên SP",21)."Nhập     Xuất     Tồn log   Tồn Woo\n";
	$msg .= str_repeat("-",59)."\n";
	foreach($stat as $pid=>$data) {
		$p = wc_get_product($pid);
		if (!$p) continue;
		$stock_now = $p->get_stock_quantity();
		$stock_log = $data['nhap'] - $data['xuat'];
		$msg .= str_pad(mb_substr($p->get_name(),0,20), 21)
			.str_pad($data['nhap'],7,' ',STR_PAD_LEFT)
			.str_pad($data['xuat'],9,' ',STR_PAD_LEFT)
			.str_pad($stock_log,8,' ',STR_PAD_LEFT)
			.str_pad($stock_now,9,' ',STR_PAD_LEFT)
			."\n";
	}
	$msg .= "</pre>";
	twf_telegram_send_message($chat_id, $msg, 'HTML');

    // Xuất CSV
    $upload_dir = wp_upload_dir();
    $file_name = 'export_stock_' . time() . '.csv';
    $file_path = $upload_dir['basedir'] . '/' . $file_name;
    $file_url  = $upload_dir['baseurl'] . '/' . $file_name;

    $f = fopen($file_path, 'w+');
    fwrite($f, "\xEF\xBB\xBF"); // BOM utf-8
    fputcsv($f, ['Mã SP', 'Tên SP', 'Nhập', 'Xuất', 'Tồn theo log', 'Tồn Woo']);
	foreach($stat as $pid=>$data){
		$p = wc_get_product($pid);
		if (!$p) continue;
		$stock_log = $data['nhap'] - $data['xuat'];
		fputcsv($f, [
			$pid,
			$p->get_name(),
			$data['nhap'],
			$data['xuat'],
			$stock_log,
			$p->get_stock_quantity()
		]);
	}
    fclose($f);

    // Gửi link file CSV cho user (nếu muốn gửi file, gọi hàm gửi file)
    twf_telegram_send_message($chat_id, "Tải file báo cáo xuất nhập tồn dưới dạng CSV");
    twf_send_telegram_document($chat_id, $file_path, "Báo cáo xuất nhập tồn kho");
	//
	@unlink($file_path);
}

function twf_bao_cao_nhat_ky_xuat_nhap($chat_id, $from_date = '', $to_date = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'twf_stock_log';

    // Xác định filter ngày
    if (!$to_date)     $to_date = date('Y-m-d');
    if (!$from_date)   $from_date = date('Y-m-d', strtotime("$to_date -6 days"));
    $where = $wpdb->prepare("created_at >= %s AND created_at <= %s", $from_date.' 00:00:00', $to_date.' 23:59:59');
    $sql = "SELECT * FROM $table WHERE $where ORDER BY created_at DESC";
    $result = $wpdb->get_results($sql);

    // Soạn báo cáo tin nhắn nhật ký (tối đa 20 dòng đầu)
    $msg = "📝 <b>Nhật ký xuất nhập kho</b>\n";
    $msg .= "<i>Từ $from_date đến $to_date</i>\n\n";
    $msg .= "<pre>".str_pad("Thời gian",17)." ".str_pad("SP",15)." ".str_pad("SKU",7)." ".str_pad("Loại",7)." ".str_pad("SL",5)." ".str_pad("Giá mua",8)." ".str_pad("Trạng thái",10)." Note\n";
    $msg .= str_repeat("-",80)."\n";
    $count = 0;
    foreach($result as $row){
        $p = wc_get_product($row->product_id);
        if (!$p) continue;
        $status = $row->action == 'import' ? 'Nhập' : 'Xuất';
        $user = $row->user_id ? get_userdata($row->user_id) : null;

        $msg .= str_pad(date('d/m H:i', strtotime($row->created_at)), 17)
            .str_pad(mb_substr($p->get_name(), 0, 14), 15)
            .str_pad($p->get_sku(), 7)
            .str_pad($status, 7)
            .str_pad($row->qty, 5)
            .str_pad(isset($row->buy_price)?number_format($row->buy_price,0,',','.'): '',8)
            .str_pad($row->new_qty, 10)
            .mb_substr($row->note, 0, 30)
            ."\n";
        if (++$count>=20) break; // Telegram dài quá sẽ bị cắt
    }
    $msg .= "</pre>";
    if ($count==0) $msg .= "(Không có giao dịch nào trong khoảng thời gian này)";
    twf_telegram_send_message($chat_id, $msg, 'HTML');

    // --- Export CSV ---
    $upload_dir = wp_upload_dir();
    $file_name = 'log_xuat_nhap_' . time() . '.csv';
    $file_path = $upload_dir['basedir'] . '/' . $file_name;
    $file_url  = $upload_dir['baseurl'] . '/' . $file_name;
    $f = fopen($file_path, 'w+');
    fwrite($f, "\xEF\xBB\xBF"); // BOM
    fputcsv($f, ['Thời gian', 'Mã SP', 'Tên SP', 'SKU', 'Loại', 'Số lượng', 'Giá mua', 'Tồn cũ', 'Tồn mới', 'Trạng thái', 'User', 'Ghi chú']);
    foreach($result as $row){
        $p = wc_get_product($row->product_id);
        $user = $row->user_id ? get_userdata($row->user_id) : null;
        fputcsv($f, [
            $row->created_at,
            $row->product_id,
            $p?$p->get_name():'',
            $p?$p->get_sku():'',
            $row->action == 'import' ? 'Nhập' : 'Xuất',
            $row->qty,
            isset($row->buy_price)?$row->buy_price:'',
            $row->old_qty,
            $row->new_qty,
            ($row->action == 'import' ? 'Cộng kho' : 'Trừ kho'),
            $user?$user->display_name:'',
            $row->note
        ]);
    }
    fclose($f);

    // --- Gửi file CSV qua Telegram
	twf_telegram_send_message($chat_id, "Tải file nhật ký xuất nhập kho ($from_date đến $to_date)");
    twf_send_telegram_document($chat_id, $file_path, "File nhật ký xuất nhập kho ($from_date đến $to_date)");
	@unlink($file_path);
}