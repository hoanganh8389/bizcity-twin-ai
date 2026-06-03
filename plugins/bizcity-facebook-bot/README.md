# BizCity Facebook Bot

A WordPress MU-Plugin for Facebook Messenger integration with webhook listener and workflow automation support.

## Features

- **Multi-Page Support**: Connect and manage multiple Facebook Fanpages
- **Messenger Integration**: Receive and send messages via Facebook Messenger API
- **Webhook Listener**: Test webhook in real-time with built-in listener
- **Workflow Automation**: Triggers and actions for BizCity Automation
- **Comment Reply**: Auto-reply to Facebook comments with AI
- **Admin Dashboard**: Easy-to-use admin interface for bot management

## Installation

1. Copy the `bizcity-facebook-bot` folder to `wp-content/mu-plugins/`
2. Include `bootstrap.php` in your mu-plugin loader or create a loader file

## Configuration

### Facebook App Setup

1. Go to [Facebook Developers](https://developers.facebook.com/apps)
2. Create a new app or use existing one
3. Enable Facebook Login and Messenger products
4. Configure OAuth redirect URI: `https://yourdomain.com/?fb_callback=1`
5. Configure Webhook URL: `https://yourdomain.com/?fbhook=1`
6. Verify Token: `bizgpt`

### Required Permissions

- `pages_show_list`
- `pages_manage_posts`
- `pages_messaging`
- `pages_read_engagement`
- `pages_read_user_content`

## Webhook Events

The plugin handles these Facebook webhook events:

- `messaging` - Messages from Messenger
- `feed` - Comments on posts

## Workflow Integration

### Triggers

- **Nhận tin nhắn Facebook**: Triggered when text message received
- **Nhận ảnh Facebook**: Triggered when image received

### Actions

- **Gửi tin nhắn Facebook**: Send text message to user
- **Gửi hình ảnh Facebook**: Send image to user

## Directory Structure

```
bizcity-facebook-bot/
├── bootstrap.php           # Main plugin file
├── index.php               # Security file
├── README.md               # Documentation
├── .htaccess               # Security rules
├── assets/
│   ├── css/
│   │   └── admin.css       # Admin styles
│   └── js/
│       └── admin.js        # Admin scripts
├── includes/
│   ├── class-database.php          # Database manager
│   ├── class-admin-menu.php        # Admin menu & pages
│   ├── class-rest-api.php          # REST API endpoints
│   └── class-webhook-handler.php   # Webhook processor
├── lib/
│   ├── class-facebook-bot-api.php  # Facebook API client
│   └── functions.php               # Helper functions
├── templates/                       # HTML templates
└── triggers/                        # Placeholder
```

## API Reference

### BizCity_Facebook_Bot_API

```php
$api = new BizCity_Facebook_Bot_API($page_access_token);

// Send text message
$api->send_message($user_id, $text);

// Send image
$api->send_photo($user_id, $image_url, $caption);

// Get user profile
$api->get_user_profile($user_id);
```

## Changelog

### 1.0.0
- Initial release
- Facebook Messenger integration
- Webhook listener
- Workflow automation triggers and actions
- Admin dashboard

## License

GPL v2 or later

## Author

BizCity Team - [https://bizcity.vn](https://bizcity.vn)
