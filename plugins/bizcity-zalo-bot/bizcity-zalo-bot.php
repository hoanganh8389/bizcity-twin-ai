<?php
/**
 * Plugin Name:       BizCity Zalo Bot Integration
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-zalo-bot
 * Description:       Tích hợp Zalo Official Account (OA) Bot API với webhook listener, gateway bridge sang Twin AI và workflow automation. Nhận tin nhắn từ Zalo → xử lý qua Intent Engine → phản hồi tự động.
 * Short Description: Kết nối Zalo OA Bot với BizCity Twin AI — nhận & trả lời tin nhắn Zalo tự động.
 * Quick View:        💬 Zalo OA → Webhook → Twin AI → Trả lời tự động
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * License:           GPL v2 or later
 * Text Domain:       bizcity-zalo-bot
 * Role:              agent
 * Gateway:           true
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/zalo-icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/9dd8b28c14c9fd97a4d81.png
 * Template Page:     zalo-bot
 * Plan:              free
 * Category:          messaging, automation, zalo, chatbot
 * Tags:              zalo, bot, OA, webhook, automation, chatbot, messaging, zalo official account
 *
 * === Giới thiệu ===
 * BizCity Zalo Bot Integration kết nối Zalo Official Account (OA) với hệ thống
 * Twin AI, cho phép nhận tin nhắn từ khách hàng qua Zalo và tự động xử lý
 * thông qua Intent Engine — phản hồi ngay lập tức hoặc chuyển tiếp đến
 * workflow automation.
 *
 * === Tính năng chính ===
 * • Webhook listener nhận sự kiện từ Zalo OA (tin nhắn, follow, unfollow)
 * • Gateway Bridge chuyển tin nhắn Zalo → Twin AI Chat Gateway xử lý
 * • Quản lý nhiều Zalo Bot cùng lúc (multi-bot)
 * • Lưu trữ conversation memory theo user Zalo
 * • Admin Dashboard quản lý bot, xem logs webhook
 * • REST API cho tích hợp bên ngoài
 * • Tự động tạo database tables khi kích hoạt
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core (bizcity-twin-ai)
 * • Zalo Bot Token (tạo bot tại OA "Zalo Bot Manager" trên Zalo, xem https://bot.zapps.me/docs/create-bot/)
 * • HTTPS endpoint (Zalo yêu cầu webhook URL phải là HTTPS)
 *
 * === Hướng dẫn kích hoạt ===
 * 1. Kích hoạt plugin trong WordPress Admin
 * 2. Tạo Bot trên Zalo: Mở Zalo → tìm OA "Zalo Bot Manager" → chọn "Tạo bot"
 *    trong menu chat → nhập tên bot (bắt buộc bắt đầu bằng "Bot", VD: Bot MyShop)
 *    → nhấn Tạo Bot → nhận Bot Token qua tin nhắn Zalo
 * 3. Truy cập menu Zalo Bots trong WP Admin → thêm bot mới với Bot Token
 * 4. Plugin tự gọi API setWebhook (POST https://bot-api.zaloplatforms.com/bot{TOKEN}/setWebhook)
 *    để đăng ký Webhook URL + secret_token xác thực
 * 5. Bot tự động nhận tin nhắn từ Zalo và trả lời qua Twin AI
 *
 * === Tài liệu tham khảo ===
 * • Zalo Bot Platform Docs: https://bot.zapps.me/docs
 * • Tạo Bot: https://bot.zapps.me/docs/create-bot/
 * • API setWebhook: https://bot.zapps.me/docs/apis/setWebhook/
 * • API sendMessage: https://bot.zapps.me/docs/apis/sendMessage/
 * • API getUpdates (long polling): https://bot.zapps.me/docs/apis/getUpdates/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'BIZCITY_ZALO_BOT_VERSION', '1.0.0' );
define( 'BIZCITY_ZALO_BOT_FILE', __FILE__ );
define( 'BIZCITY_ZALO_BOT_DIR', dirname( __FILE__ ) );
define( 'BIZCITY_ZALO_BOT_URL', plugins_url( '', __FILE__ ) );

// Load the bootstrap file
require_once BIZCITY_ZALO_BOT_DIR . '/bootstrap.php';
