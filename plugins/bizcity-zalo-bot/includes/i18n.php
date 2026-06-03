<?php
/**
 * Internationalization - Vietnamese translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate function for Zalo Bot plugin
 */
function bzb_t( $text ) {
	$translations = array(
		// Common
		'Permission denied' => 'Không có quyền truy cập',
		'Bot not found' => 'Không tìm thấy bot',
		
		// Bot management
		'Bot saved successfully' => 'Lưu bot thành công',
		'Bot deleted' => 'Đã xóa bot',
		'Bot connection successful! Webhook info retrieved.' => 'Kết nối bot thành công! Đã lấy thông tin webhook.',
		
		// Webhook
		'Webhook secret must be at least 8 characters long' => 'Mã bảo mật webhook phải có ít nhất 8 ký tự',
		'Webhook secret must be less than 64 characters' => 'Mã bảo mật webhook phải ít hơn 64 ký tự',
		'Webhook set successfully' => 'Cài đặt webhook thành công',
		
		// Listener
		'Listening started. Send a message to your Zalo bot now.' => 'Bắt đầu lắng nghe. Hãy gửi tin nhắn đến bot Zalo của bạn.',
		'Listener expired' => 'Hết thời gian lắng nghe',
		'Webhook received!' => 'Đã nhận webhook!',
		'Waiting for webhook...' => 'Đang chờ webhook...',
		'Listener stopped' => 'Đã dừng lắng nghe',
		
		// Listener page
		'Webhook Listener' => 'Nghe Webhook',
		'Test your Zalo Bot webhooks in real-time. Start listening, then send a message or image to your bot.' => 'Kiểm tra webhook Zalo Bot theo thời gian thực. Bắt đầu lắng nghe, sau đó gửi tin nhắn hoặc hình ảnh đến bot của bạn.',
		'No active bots found. Please <a href="%s">add a bot</a> first.' => 'Chưa có bot nào hoạt động. Vui lòng <a href="%s">thêm bot</a> trước.',
		'Listener Settings' => 'Cài đặt Listener',
		'Select Bot' => 'Chọn Bot',
		'-- Choose a bot --' => '-- Chọn một bot --',
		'Choose which bot to listen for webhooks from.' => 'Chọn bot để lắng nghe webhook.',
		'Bot Information' => 'Thông tin Bot',
		'Start Listening' => 'Bắt đầu nghe',
		'Stop Listening' => 'Dừng lắng nghe',
		'How to Test' => 'Cách kiểm tra',
		'Select a bot from the dropdown above' => 'Chọn bot từ dropdown phía trên',
		'Click "Start Listening" button' => 'Nhấn nút "Bắt đầu nghe"',
		'Open Zalo app and send a message to your bot:' => 'Mở app Zalo và gửi tin nhắn đến bot của bạn:',
		'Text message:' => 'Tin nhắn text:',
		'Type any text like "Hello bot"' => 'Gõ bất kỳ text nào như "Xin chào bot"',
		'Image message:' => 'Tin nhắn hình ảnh:',
		'Send any photo or image' => 'Gửi bất kỳ ảnh hoặc hình ảnh nào',
		'The webhook data will appear below automatically' => 'Dữ liệu webhook sẽ hiển thị tự động bên dưới',
		'Note:' => 'Lưu ý:',
		'Listening will automatically stop after 5 minutes or when webhook is received.' => 'Lắng nghe sẽ tự động dừng sau 5 phút hoặc khi nhận được webhook.',
		'Webhook Data Received' => 'Đã nhận dữ liệu Webhook',
		
		// Logs page
		'Zalo Bot Logs' => 'Nhật ký Zalo Bot',
		'All Bots' => 'Tất cả Bot',
		'Logs' => 'Nhật ký',
		'Time' => 'Thời gian',
		'Bot' => 'Bot',
		'Event' => 'Sự kiện',
		'User ID' => 'ID người dùng',
		'Data' => 'Dữ liệu',
		'View' => 'Xem',
		'No logs found.' => 'Không tìm thấy nhật ký.',
	);
	
	return isset( $translations[$text] ) ? $translations[$text] : $text;
}
