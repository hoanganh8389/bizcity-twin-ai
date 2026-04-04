<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Legacy Flow Router — twf_process_flow_from_params().
 *
 * The main AI switch/case router that processes Telegram/Zalo messages
 * through BizCity_AdminHook_AI classification and dispatches to flow handlers.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/bootstrap.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'twf_process_flow_from_params' ) ) :
/**
 * Process incoming message through AI classification and dispatch to the
 * appropriate flow handler.
 *
 * @param array  $params    Message payload (must contain 'message' key).
 * @param string $client_id Platform-specific user ID.
 * @param string $platform  Channel identifier (telegram, zalo, adminchat …).
 * @return mixed
 */
function twf_process_flow_from_params($params, $client_id='', $platform='') {
	$message = $params['message'];
	$chat_id = $message['chat']['id'] ?? $client_id;
	$platform = $platform ?? 'telegram';
	$api_key = get_option('twf_openai_api_key');

	// Voice → text transcription
	if (isset($message['voice'])) {
		back_trace('NOTICE', 'Step 3: Flow voice');
		$file_id = $message['voice']['file_id'];
		$voice_url = twf_telegram_get_file_url($file_id);
		$transcript = twf_openai_speech_to_text($voice_url);
		back_trace('NOTICE', 'step3.1: Flow transcript '.$transcript);
		twf_telegram_send_message($chat_id, "Dạ sếp, ý sếp có phải là: $transcript? Sếp để em xử lý tiếp.");
		$message['text'] = $transcript;
	}

	// Normalize text & caption to lowercase
	if(isset($message['caption'])) $message['caption'] = mb_strtolower($message['caption'], 'UTF-8');
	if(isset($message['text'])) $message['text'] = mb_strtolower($message['text'], 'UTF-8');

	if($message['caption']) $user_text = $message['caption'];
	else $user_text = $message['text'];

	back_trace('NOTICE', 'ai_result user_text: ' . $user_text);
	back_trace('NOTICE', 'ai_result client_id: ' . $client_id);
	$prompt = BizCity_AdminHook_AI::detectMessageTypePrompt($user_text);
	$ai_result = BizCity_AdminHook_AI::teleAiResponse($api_key, $prompt);
	back_trace('NOTICE', 'ai_result json: ' . $ai_result);

	// Parse AI response JSON
	$json = trim($ai_result);
	if (($pos = strpos($json, '{')) !== false) {
		$json = substr($json, $pos);
	}
	if (($pos = strrpos($json, '}')) !== false) {
		$json = substr($json, 0, $pos + 1);
	}
	$arr = json_decode($json, true);
	$type = $arr['type'] ?? 'khac';

	// Fix non-standard JSON in info field (single quotes → double quotes)
	if (isset($arr['info']) && is_string($arr['info'])) {
		$info_raw = $arr['info'];
		$info_fixed = preg_replace("/'/", '"', $info_raw);
		$info_arr = json_decode($info_fixed, true);
		if (is_array($info_arr)) {
			$arr['info'] = $info_arr;
		} else {
			back_trace('WARNING', 'Không thể decode lại info: ' . $info_fixed);
			$arr['info'] = [];
		}
	}

	back_trace('NOTICE', 'ai_result POST: ' . print_r($arr,true));

	// Dispatch to flow handlers
	switch($type) {
		case 'tao_san_pham':
			$image_url = $arr['info']['image_url'];
			$title = $arr['info']['title'];
			twf_telegram_send_photo($chat_id, 'https://bizgpt.vn/wp-content/uploads/doraemon/please.gif', '');

			$msg = "Em vừa nhận được nhiệm vụ đăng sản phẩm. Em làm việc ngay đây ạ.";
			if(empty(get_zalo_option($chat_id))):
				twf_telegram_send_message($chat_id, $msg);
			else:
				send_zalo_botbanhang($msg, $chat_id);
			endif;
			return twf_handle_product_post_flow($message, $chat_id, $arr, $image_url);
			break;

		case 'sua_san_pham':
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ sửa sản phẩm. Em làm việc ngay đây ạ.");
			return twf_handle_edit_product_flow($message, $chat_id);
			break;

		case 'bao_cao':
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ tổng hợp báo cáo, thống kê hôm nay ạ. Em làm việc ngay đây ạ.");
			$ai_array = twf_parse_thong_ke_info_ai($message['text']);
			$ai_json  = json_encode($ai_array);
			back_trace('NOTICE', 'THỐNG KÊ AI DATA: ' . $ai_json);
			$bao_cao = twf_handle_ai_json_report($ai_json);
			return twf_telegram_send_message($chat_id, $bao_cao);
			break;

		case 'thong_ke_hang_hoa':
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ thống kê doanh số theo hàng hóa ạ. Em làm việc ngay đây ạ.");
			$ai_array = twf_parse_thong_ke_hang_hoa_info_ai($message['text']);
			$ngay    = $ai_array['ngay'] ?? '';
			$so_ngay = $ai_array['so_ngay'] ?? 3;
			$result = twf_bao_cao_top_product($chat_id, $ngay, $so_ngay);
			return;
			break;

		case 'xnt':
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ báo cáo xuất nhập tồn ạ. Em làm việc ngay đây ạ.");
			$ai_array = twf_parse_thong_ke_hang_hoa_info_ai($message['text']);
			$from_date = $ai_array['from_date'] ?? '';
			$to_date = $ai_array['to_date'] ?? '';
			$so_ngay = $ai_array['so_ngay'] ?? 3;
			if ($to_date && $from_date == '') {
				$from_date = date('Y-m-d', strtotime("$to_date -".($so_ngay-1)." days"));
			}
			return twf_bao_cao_xuat_nhap_ton_kho($chat_id, $from_date, $to_date);
			break;

		case 'nhat_ky_xnt':
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ báo cáo nhật ký hoạt động xuất và nhập ạ. Em làm việc ngay đây ạ.");
			$ai_array = twf_parse_thong_ke_hang_hoa_info_ai($message['text']);
			$from_date = $ai_array['from_date'] ?? '';
			$to_date = $ai_array['to_date'] ?? '';
			$so_ngay = $ai_array['so_ngay'] ?? 7;

			if ($to_date && !$from_date) $from_date = date('Y-m-d', strtotime("$to_date -".($so_ngay-1)." days"));
			elseif (!$to_date && $from_date) $to_date = date('Y-m-d', strtotime("$from_date +".($so_ngay-1)." days"));
			elseif (!$to_date && !$from_date) {
				$to_date = date('Y-m-d');
				$from_date = date('Y-m-d', strtotime("$to_date -".($so_ngay-1)." days"));
			}

			return twf_bao_cao_nhat_ky_xuat_nhap($chat_id, $from_date, $to_date);
			break;

		case 'nhap_kho':
			twf_telegram_send_message($chat_id, "Sếp giao em làm phiếu nhập kho ạ? Em làm việc ngay đây ạ.");
			$info = twf_parse_phieu_nhap_kho_ai($message['text']);
			return twf_phieu_nhap_kho_from_telegram($chat_id, $info);
			break;

		case 'thong_ke_khach_hang':
			twf_telegram_send_message($chat_id, "Thống kê khách hàng hả sếp? Em làm việc ngay đây ạ.");
			$ai_array = twf_parse_thong_ke_khach_hang_ai($message['text']);
			$ngay    = $ai_array['ngay'] ?? '';
			$so_ngay = $ai_array['so_ngay'] ?? 3;
			$result = twf_bao_cao_top_customers($chat_id, $ngay, $so_ngay);
			return;
			break;

		case 'danh_sach_don_hang':
			$statuses = $arr['status'] ?? ['completed','processing','pending','on-hold','cancelled','refunded'];
			$statuses = array_map(function($s){
				switch (strtolower($s)) {
					case 'completed': case 'đã hoàn tất': return 'wc-completed';
					case 'processing': case 'đang xử lý': return 'wc-processing';
					case 'pending': case 'chờ thanh toán': return 'wc-pending';
					case 'on-hold': case 'tạm giữ': return 'wc-on-hold';
					case 'cancelled': case 'hủy': case 'huỷ': return 'wc-cancelled';
					case 'refunded': case 'trả lại': return 'wc-refunded';
					default: return strtolower($s);
				}
			}, $statuses);

			if (!empty($arr['from_date']) && !empty($arr['to_date'])) {
				$from_date = $arr['from_date'];
				$to_date = $arr['to_date'];
			} elseif (!empty($arr['month']) && !empty($arr['year'])) {
				$from_date = $arr['year'] . '-' . str_pad($arr['month'], 2, '0', STR_PAD_LEFT) . '-01';
				$to_date = date('Y-m-t', strtotime($from_date));
			} elseif (!empty($arr['so_ngay'])) {
				$so_ngay = intval($arr['so_ngay']);
				$to_date = date('Y-m-d');
				$from_date = date('Y-m-d', strtotime("$to_date -".($so_ngay-1)." days"));
			} elseif (!empty($arr['so_tuan'])) {
				$so_tuan = intval($arr['so_tuan']);
				$from_date = date('Y-m-d', strtotime('monday -'.($so_tuan-1).' week'));
				$to_date = date('Y-m-d', strtotime('sunday this week'));
			} else {
				$to_date = date('Y-m-d');
				$from_date = date('Y-m-d', strtotime("$to_date -6 days"));
			}
			if($statuses) $tele_msg = " trạng thái: ".implode(', ', $statuses);
			twf_telegram_send_message($chat_id, "Danh sách đơn hàng $tele_msg, Từ $from_date đến $to_date");
			return twf_telegram_order_list_report2($chat_id, $from_date, $to_date, $statuses);
			break;

		case 'tim_khach_hang':
			$phone = $arr['info'];
			twf_telegram_send_message($chat_id, "Sếp muốn tìm khách sdt: {$phone}. Em làm việc ngay đây ạ.");
			if($phone) return twf_handle_find_customer_order_by_phone($chat_id, $phone);
			break;

		case 'ban_them_hang':
			$neworder = get_transient('twf_neworder_' . $chat_id);
			$sdt = $neworder['sdt']??'';
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ bán thêm hàng cho khách {$sdt} ạ. Em làm việc ngay đây ạ.");
			return twf_prompt_get_customer_info_from_transient($chat_id, $message, $arr);
			break;

		case 'tao_don_hang':
			$msg = "Em vừa nhận được nhiệm vụ tạo đơn ạ. Em làm việc ngay đây ạ.";
			twf_telegram_send_message($chat_id, $msg);
			return twf_handle_create_order_ai_flow($message, $chat_id);
			break;

		case 'dang_facebook':
			// Fall-through to dang_facebook_tat_ca
		case 'dang_facebook_tat_ca':
			$image_url = $arr['info']['image_url'];
			$title = $arr['info']['title'];
			twf_telegram_send_photo($chat_id, 'https://bizgpt.vn/wp-content/uploads/doraemon/cantwait.gif', '');
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ đăng bài lên tất cả facebook đang quản lý. Em làm việc ngay đây ạ.");
			return twf_handle_facebook_multi_page_post($chat_id, $message, $arr['info']);
			break;

		case 'viet_bai':
			$image_url = $arr['info']['image_url'];
			$title = $arr['info']['title'];
			twf_telegram_send_message($chat_id, "Em vừa nhận được nhiệm vụ đăng bài. Em làm việc ngay đây ạ.");
			return twf_handle_post_request($message, $chat_id, $title, $image_url, $arr);
			break;

		case 'nhac_viec':
			twf_telegram_send_message($chat_id, "Dạ sếp, em đã ghi nhớ công việc này rồi ạ!");
			twf_telegram_send_photo($chat_id, 'https://bizgpt.vn/wp-content/uploads/doraemon/please.gif', '');
			return twf_create_biztask_from_ai($chat_id, $user_text, $arr, $platform);
			break;

		case 'hdsd':
			$topic = $arr['topic'] ?? 'tat_ca';
			$msg = twf_ai_telegram_help_content($topic);
			twf_telegram_send_message($chat_id, $msg, 'Markdown');
			return;
			break;

		case 'yeu_cau_quan_tri':
			$domain = $arr['info']['domain'] ?? '';
			if(!$domain) $domain = twf_extract_domain_from_text($user_text);
			if (!$domain) {
				twf_telegram_send_message($chat_id, "Sếp vui lòng nói rõ tên miền cần quản trị ạ.");
				return;
			}
			$check_login_blog_id = bizgpt_generate_zalo_admin_login_url($chat_id, $domain);
			if($check_login_blog_id):
				return twf_telegram_send_message($chat_id, "Em đã đăng nhập $domain rồi ạ. Mời sếp hãy giao việc cho em ạ.");
				twf_telegram_send_photo($chat_id, 'https://bizgpt.vn/wp-content/uploads/doraemon/dang-nhap.gif', '!');
			else:
				$domain_clean = preg_replace('#^https?://(www\.)?#', '', rtrim($domain, '/'));
				$enc = twf_encrypt_chat_id($client_id, 'vietqr');
				$login_url = 'https://' . $domain_clean . '/telegram-login/?zid=' . $enc;
				twf_telegram_send_photo($chat_id, 'https://bizgpt.vn/wp-content/uploads/doraemon/please.gif', '');
				return twf_telegram_send_message($chat_id, "Sếp hãy nhấn vào link bên dưới để xác nhận quyền quản trị: $login_url");
			endif;
			break;

		case 'quen_mat_khau':
			$domain = $arr['info']['domain'] ?? '';
			if($domain):
				$blog_id = get_current_blog_id();
				$blog_info = get_blog_details( $blog_id );
				$zid = twf_encrypt_chat_id($client_id);
				$domain =$blog_info->domain;
			endif;
			$link = 'https://'.$domain.'/my-account/?client_id='.$zid;
			return twf_telegram_send_message($chat_id, "Nếu sếp quên mật khẩu, sếp hãy vào đường link ở dưới, sau đó nhấn sdt của sếp vào. Em sẽ gửi đường link reset mật khẩu theo đúng số điện thoại của sếp qua Zalo để sếp reset, sếp nhé. Sếp nhớ đừng để chế độ chặn người lạ là được. : $link");
			break;

		case 'tao_tai_khoan':
			$domain = $arr['info']['domain'] ?? '';
			if($domain):
				$blog_id = get_current_blog_id();
				$blog_info = get_blog_details( $blog_id );
				$zid = twf_encrypt_chat_id($client_id);
				$domain =$blog_info->domain;
			endif;
			$link = 'https://'.$domain.'/my-account/?client_id='.$zid;
			return twf_telegram_send_message($chat_id, "Cảm ơn bạn đã quan tâm. Để đăng ký xin vui lòng đăng ký theo link để bảo đảm an toàn thông tin mật khẩu : $link");
			break;

		case 'blog_nao':
			$domain = $arr['info']['domain'] ?? '';
			$blog_id = get_zalo_option($client_id);
			$blog_info = get_blog_details( $blog_id );
			return twf_telegram_send_message($chat_id, "Em đang ở trang: $blog_info->domain sếp ạ");
			break;

		case 'len_lich_facebook_ai':
			$chu_de  = $arr['info']['chu_de'] ?? '';
			$hours   = $arr['info']['hours'] ?? ['8:00'];
			$weekdays = $arr['info']['weekdays'] ?? ['mon','tue','wed','thu','fri','sat','sun'];
			if (is_string($hours)) {
				$hours = array_map('trim', explode(',', $hours));
			}
			if (is_string($weekdays)) {
				$weekdays = array_map('trim', explode(',', $weekdays));
			}
			if ($chu_de && $hours) {
				bizgpt_add_facebook_schedule_ai($chu_de, $hours, $weekdays);
				$msg = "Đã lên lịch đăng bài Facebook tự động với chủ đề: *$chu_de* vào các khung giờ: " . implode(', ', $hours) . " vào các ngày: " . implode(', ', $weekdays) . ". Nội dung và ảnh sẽ được AI tạo tự động.";
			} else {
				$msg = "Thiếu thông tin chủ đề hoặc giờ đăng. Hãy nhập lại!";
			}
			twf_telegram_send_message($chat_id, $msg);
			return;
			break;

		case 'khac':
		default:
			return twf_handle_chat_flow($message, $chat_id);
			break;
	}

	// Fallback
	twf_telegram_send_message($chat_id, "Xin lỗi sếp, em chưa hiểu được lệnh để xử lý dạng tin nhắn này.");
}
endif; // function_exists guard
