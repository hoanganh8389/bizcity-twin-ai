# BizCity Zalo Bot Integration

Official Zalo Bot API integration for WordPress with webhook support.

## Cấu trúc thư mục

```
mu-plugins/
├── bizcity-zalo-bot.php           # Main loader file
└── bizcity-zalo-bot/
    ├── bootstrap.php              # Plugin initialization
    ├── includes/                  # Core classes
    │   ├── class-database.php
    │   ├── class-webhook-handler.php
    │   ├── class-admin-menu.php
    │   └── class-rest-api.php
    ├── lib/                       # Libraries
    │   ├── class-zalo-bot-api.php
    │   └── functions.php
    └── assets/                    # CSS/JS files
        ├── css/
        │   └── admin.css
        └── js/
            └── admin.js
```

## Cài đặt

1. Copy file `bizcity-zalo-bot.php` vào thư mục `wp-content/mu-plugins/`
2. Copy folder `bizcity-zalo-bot/` vào `wp-content/mu-plugins/`
3. Plugin sẽ tự động kích hoạt và tạo database tables
4. **Lưu ý**: Nếu gặp lỗi database, truy cập Admin một lần để tự động tạo tables

### Khắc phục lỗi Database

Nếu gặp lỗi "Table doesn't exist":
- Truy cập WordPress Admin Dashboard
- Plugin sẽ tự động kiểm tra và tạo tables
- Hoặc gọi trực tiếp: `BizCity_Zalo_Bot_Database::activate()`

## Cấu hình Zalo Bot

1. Truy cập https://zalo.me/s/botcreator
2. Tạo bot mới hoặc chọn bot có sẵn
3. Lấy Access Token từ Bot Settings
4. Copy token vào WordPress Admin

## Sử dụng

### Admin UI

Truy cập **Zalo Bots** trong WordPress Admin menu:
- **All Bots**: Quản lý danh sách bot
- **Logs**: Xem webhook logs

### Webhook URL

Sau khi tạo bot, copy Webhook URL và paste vào Zalo Bot Creator:

**Option 1: Original Webhook** (Simple, per-bot)
```
https://yourdomain.com/bizcity/zalo-bot/webhook/{bot_id}
```

**Option 2: ZaloHook** (Encrypted, multi-bot support)
```
https://yourdomain.com/zalohook/
```
- Sử dụng Secret Token được generate tự động theo blog_id
- Hỗ trợ mã hóa/giải mã dữ liệu
- Phù hợp cho môi trường multisite

### Helper Functions

```php
// Send text message
bizcity_send_zalo_message( $bot_id, $user_id, 'Hello world!', 'text' );

// Send image
bizcity_send_zalo_message( $bot_id, $user_id, 'https://example.com/image.jpg', 'image' );

// Get user profile
$profile = bizcity_get_zalo_user_profile( $bot_id, $user_id );

// Get webhook URL
$webhook_url = bizcity_get_zalo_webhook_url( $bot_id );
```

### WordPress Actions

Plugin fires các action hooks sau:

```php
// Khi nhận tin nhắn
do_action( 'bizcity_zalo_message_received', $message_data );
do_action( 'bizcity_zalo_bot_message_received', $message_data );

// Khi user follow/unfollow
do_action( 'bizcity_zalo_bot_user_follow', $bot, $data );
do_action( 'bizcity_zalo_bot_user_unfollow', $bot, $data );

// Generic webhook event
do_action( 'bizcity_zalo_bot_webhook_event', $bot, $event_name, $data );
```

### REST API Endpoints

```
POST /wp-json/bizcity/v1/zalo-bot/send-message
GET  /wp-json/bizcity/v1/zalo-bot/info/{bot_id}
GET  /wp-json/bizcity/v1/zalo-bot/user/{user_id}
```

## Tích hợp với BizCity Automation

Plugin tự động fire action `bizcity_zalo_message_received` để trigger workflow trong BizCity Automation.

Message data format:
```php
array(
    'bot_id' => '1',
    'bot_name' => 'My Bot',
    'from_user_id' => '1234567890',
    'message_type' => 'text', // text|image|file
    'message_text' => 'Hello',
    'message_time' => '2026-01-26 10:30:00',
    'image_url' => '',
    'file_url' => '',
    'file_name' => '',
    'raw' => [...] // Original webhook data
)
```

## Database Tables

### wp_bizcity_zalo_bots
Lưu thông tin bot và credentials

### wp_bizcity_zalo_bot_logs
Lưu webhook events để debug

## Yêu cầu hệ thống

- WordPress 5.0+
- PHP 7.4+
- cURL extension

## License

GPL v2 or later
