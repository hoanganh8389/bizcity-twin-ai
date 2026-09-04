# BizCity Facebook Bot — Kiến trúc & Lộ trình phát triển

> **Plugin**: `bizcity-facebook-bot` (mu-plugin)
> **Companion**: `bizcity-tool-facebook` (marketplace plugin)
> **Version hiện tại**: 1.0.0  
> **Cập nhật**: 2026-03-16

---

## Mục lục

1. [Tổng quan hệ thống](#1-tổng-quan-hệ-thống)
2. [Kiến trúc 2 lớp](#2-kiến-trúc-2-lớp)
3. [Luồng dữ liệu chính](#3-luồng-dữ-liệu-chính)
4. [Database Schema](#4-database-schema)
5. [Class Map & File Structure](#5-class-map--file-structure)
6. [Phase & Roadmap](#6-phase--roadmap)
7. [Trạng thái hiện tại](#7-trạng-thái-hiện-tại)

---

## 1. Tổng quan hệ thống

BizCity Facebook Bot là hạ tầng trung tâm xử lý **toàn bộ tương tác Facebook** cho hệ thống WordPress Multisite BizCity:

```
┌───────────────────────── Facebook Platform ─────────────────────────┐
│  OAuth Dialog  ·  Graph API v18.0  ·  Webhook Events  ·  Messenger │
└────────┬───────────────────┬───────────────────┬────────────────────┘
         │                   │                   │
    ① OAuth Flow      ② Webhook POST       ③ Graph API calls
         │                   │                   │
┌────────▼───────────────────▼───────────────────▼────────────────────┐
│              bizcity-facebook-bot (mu-plugin)                       │
│  ┌──────────────┐ ┌──────────────────┐ ┌─────────────────────────┐ │
│  │ OAuth Class  │ │ Central Webhook  │ │ Facebook Bot API Client │ │
│  │ (callback    │ │ /facehook/       │ │ (send_message,          │ │
│  │  on main     │ │ page_id → blog_id│ │  send_photo, etc.)      │ │
│  │  site)       │ │ → switch_to_blog │ │                         │ │
│  └──────┬───────┘ └────────┬─────────┘ └────────────▲────────────┘ │
│         │                  │                        │              │
│  ┌──────▼──────────────────▼────────────────────────┼────────────┐ │
│  │        Global DB: wp_bizcity_facebook_page_routes             │ │
│  │        page_id → blog_id, access_token, page_name             │ │
│  │        (Khai báo trong db.php → luôn query Global DB)         │ │
│  └───────────────────────────────────────────────────────────────┘ │
│                                                                    │
│  ┌────────────────┐  ┌─────────────────────┐  ┌────────────────┐  │
│  │ Network Admin  │  │ Legacy Functions     │  │ REST API       │  │
│  │ Facebook       │  │ (poster, connect,    │  │ /v1/send-msg   │  │
│  │ Central UI     │  │  messenger hooks)    │  │ /v1/send-photo │  │
│  └────────────────┘  └─────────────────────┘  └────────────────┘  │
└────────────────────────────────────────────────────────────────────┘
         │                    │
         │     ┌──────────────▼──────────────────────────┐
         │     │   bizcity-tool-facebook (plugin)        │
         │     │   • Intent Provider (AI chat → FB post) │
         │     │   • Profile View /tool-facebook/        │
         │     │   • Tool Callbacks (AI content gen)     │
         │     │   • AJAX + Chat Integration             │
         └─────▶   • OAuth button UI trên subsite       │
               └─────────────────────────────────────────┘
```

### Nguyên tắc thiết kế

| # | Nguyên tắc | Giải thích |
|---|-----------|------------|
| 1 | **Single Webhook** | Toàn bộ multisite chỉ dùng 1 URL: `{hub_site}/facehook/` |
| 2 | **Global Route Table** | `wp_bizcity_facebook_page_routes` nằm trên Global DB, khai báo trong `db.php` sharding router |
| 3 | **Central OAuth** | Chỉ 1 redirect_uri (Hub Site) → chỉ cần đăng ký 1 domain trên Facebook App |
| 4 | **Hub Site** | 1 subsite được chỉ định làm Facebook gateway (webhook + OAuth callback). Mặc định: main site. Config: `bizcity_fb_hub_blog_id` |
| 5 | **Network-wide Config** | App ID, App Secret, Verify Token, Hub Blog ID lưu `site_option` (dùng chung toàn mạng) |
| 6 | **Subsite Autonomy** | Mỗi subsite tự quản lý Pages đã kết nối, tự đăng bài qua Intent/Tool |
| 7 | **mu-plugin = Infra, plugin = UX** | `bizcity-facebook-bot` lo hạ tầng; `bizcity-tool-facebook` lo trải nghiệm người dùng |

---

## 2. Kiến trúc 2 lớp

### Lớp 1: `bizcity-facebook-bot` (mu-plugin — Hạ tầng)

Chạy sớm nhất, có mặt trên **mọi site** trong multisite.

| Module | Class | Vai trò |
|--------|-------|---------|
| **Central Webhook** | `BizCity_Facebook_Central_Webhook` | Router `/facehook/` — nhận webhook từ Facebook, lookup `page_id` → `blog_id`, dispatch |
| **OAuth** | `BizCity_Facebook_OAuth` | Central OAuth flow — `?biz_fb_oauth=start` / `callback` trên main site |
| **Network Admin** | `BizCity_Network_Admin_Facebook` | Super Admin UI: quản lý App config + Page Routes CRUD |
| **Database** | `BizCity_Facebook_Bot_Database` | Schema management: `bizcity_facebook_bots`, `bizcity_facebook_messages`, `bizcity_facebook_conversations`, `bizcity_facebook_auto_replies` |
| **Webhook Handler** | `BizCity_Facebook_Bot_Webhook_Handler` | Xử lý `?fbhook=1` (legacy per-site webhook) |
| **REST API** | `BizCity_Facebook_Bot_REST_API` | REST endpoints: send-message, send-photo, get-bots, etc. |
| **Bot API Client** | `BizCity_Facebook_Bot_API` | Facebook Graph API wrapper: send_message, send_photo, get_user_profile |
| **Legacy** | `lib/legacy-poster.php`, `lib/functions.php` | Hàm cũ: OAuth cũ, auto-reply comment, poster |

### Lớp 2: `bizcity-tool-facebook` (plugin — Marketplace Tool)

Cài trên subsite nào muốn dùng tính năng đăng bài Facebook AI.

| Module | Class / File | Vai trò |
|--------|-------------|---------|
| **Intent Provider** | `BizCity_Tool_Facebook_Intent_Provider` | Detect "đăng facebook" → plan 3-step → gọi tool |
| **Tool Callbacks** | `BizCity_Tool_Facebook` | `create_facebook_post`, `post_facebook`, `list_facebook_posts` |
| **Profile View** | `page-facebook-profile.php` | Frontend `/tool-facebook/` — 4 tabs: Create, Monitor, Pages, Settings |
| **AJAX Handlers** | `BizCity_Ajax_Facebook` | Generate post, poll jobs, connect/disconnect page |
| **Chat Integration** | `integration-chat.php` | Push kết quả đăng bài về webchat |
| **Admin Menu** | `admin-menu.php` | Dashboard thống kê trong wp-admin |

---

## 3. Luồng dữ liệu chính

### 3.1 Luồng OAuth — Kết nối Fanpage

> **Hub Site** là subsite được chỉ định làm gateway (VD: bizcity.vn, blog_id 1065).
> Config: Network Admin → Facebook Central → Hub Site.
> Mặc định: main site (vibeyeu.vn) nếu chưa cấu hình.

```
Subsite Admin                    Hub Site (bizcity.vn)          Facebook
    │                              │                              │
    │ ① Click "Đăng nhập FB"      │                              │
    │ → subsite/?biz_fb_oauth=start│                              │
    │ (runs on subsite, reads      │                              │
    │  blog_id from current site)  │                              │
    │                              │                              │
    │ ② Build state = HMAC(       │                              │
    │    blog_id|user_id|time)    │                              │
    │    redirect_uri = hub_site/ │                              │
    │                              │                              │
    │         ③ 302 → Facebook OAuth Dialog                      │
    │◀─────────────────────────────────────────────────────────── │
    │                              │                              │
    │ ④ User chọn Pages, cấp quyền│                              │
    │──────────────────────────────────────────────────────────── ▶
    │                              │                              │
    │                              │ ⑤ GET callback?code=X&state= │
    │                              │◀─────────────────────────────│
    │                              │                              │
    │                              │ ⑥ Exchange code → user token │
    │                              │──────────────────────────────▶
    │                              │   Exchange → long-lived token │
    │                              │◀──────────────────────────────│
    │                              │                              │
    │                              │ ⑦ GET /me/accounts → pages[] │
    │                              │──────────────────────────────▶
    │                              │◀──────────────────────────────│
    │                              │                              │
    │                              │ ⑧ Save pages to blog option  │
    │                              │   Register routes in global   │
    │                              │   table (page_id → blog_id)   │
    │                              │                              │
    │ ⑨ 302 back to subsite      │                              │
    │   ?biz_fb_oauth_status=ok   │                              │
    │◀──────────────────────────── │                              │
```

**State HMAC**: `base64( blog_id | user_id | timestamp )` + signature bằng `AUTH_KEY`.  
**Long-lived token**: Exchange short-lived (2h) → long-lived (60 ngày) tự động.

### 3.2 Luồng Webhook — Nhận tin nhắn / bình luận

```
Facebook                     Hub Site (bizcity.vn)             Subsite
    │                            │                                │
    │ POST /facehook/           │                                │
    │ body: { entry, object }   │                                │
    │──────────────────────────▶ │                                │
    │                            │                                │
    │                   ① Parse page_id từ body                  │
    │                   ② Lookup global table:                   │
    │                      page_id → blog_id                     │
    │                            │                                │
    │                   ③ switch_to_blog( blog_id )              │
    │                            │───────────────────────────────▶│
    │                            │                                │
    │                            │  ④ do_action('bizcity_fb_webhook_*') │
    │                            │  ⑤ Xử lý: auto-reply, log,   │
    │                            │     AI response, v.v.          │
    │                            │                                │
    │                   ⑥ restore_current_blog()                 │
    │         200 OK             │                                │
    │◀────────────────────────── │                                │
```

### 3.3 Luồng Đăng bài AI (Intent → Tool → Facebook)

```
User Chat                  bizcity-intent             bizcity-tool-facebook        Facebook
    │                          │                            │                          │
    │ "đăng bài FB về..."     │                            │                          │
    │─────────────────────────▶│                            │                          │
    │                          │ detect goal:               │                          │
    │                          │ create_facebook_post       │                          │
    │                          │                            │                          │
    │ "Chủ đề gì?"           │ slot: topic (required)     │                          │
    │◀─────────────────────────│                            │                          │
    │                          │                            │                          │
    │ "Marketing sản phẩm X"  │                            │                          │
    │─────────────────────────▶│                            │                          │
    │                          │ all slots filled →         │                          │
    │                          │ call tool callback         │                          │
    │                          │───────────────────────────▶│                          │
    │                          │                            │                          │
    │                          │                 ① AI generate content (OpenRouter/GPT) │
    │                          │                 ② Save wp post (biz_facebook)          │
    │                          │                 ③ Attach image (AI gen or provided)    │
    │                          │                 ④ POST to Facebook Graph API           │
    │                          │                            │─────────────────────────▶│
    │                          │                            │◀─────────────────────────│
    │                          │                            │                          │
    │                          │◀───────────────────────────│                          │
    │ "✅ Đã đăng thành công!" │                            │                          │
    │◀─────────────────────────│                            │                          │
    │                          │                            │                          │
    │ (webchat notification)   │◀──── bztfb_notify_chat()  │                          │
    │◀─────────────────────────│                            │                          │
```

---

## 4. Database Schema

### Global DB (khai báo trong `db.php` sharding router)

#### `wp_bizcity_facebook_page_routes`
Central routing table — map Facebook Page ID → Blog ID.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) PK | Auto increment |
| `page_id` | varchar(100) UNIQUE | Facebook Page ID |
| `blog_id` | bigint(20) | WordPress blog/site ID |
| `page_name` | varchar(255) | Tên Fanpage |
| `access_token` | text | Page Access Token (long-lived) |
| `category` | varchar(255) | Danh mục Fanpage |
| `status` | varchar(20) | `active` / `inactive` |
| `created_at` | datetime | Thời gian đăng ký |
| `updated_at` | datetime | Cập nhật cuối |

### Per-site (shard DB, prefix = `wp_{blog_id}_`)

#### `{prefix}bizcity_facebook_bots`
Quản lý bot config trên mỗi site.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) PK | Auto increment |
| `bot_name` | varchar(255) | Tên bot |
| `page_id` | varchar(100) | Facebook Page ID |
| `page_access_token` | text | Page Access Token |
| `app_id` | varchar(100) | App ID (legacy, per-site) |
| `app_secret` | varchar(255) | App Secret (legacy) |
| `verify_token` | varchar(100) | Default: `bizgpt` |
| `ai_enabled` | tinyint(1) | Bật AI auto-reply |
| `status` | varchar(20) | `active` / `inactive` |

#### `{prefix}bizcity_facebook_messages`
Log tin nhắn Messenger.

#### `{prefix}bizcity_facebook_conversations`
Quản lý cuộc hội thoại.

#### `{prefix}bizcity_facebook_auto_replies`
Cấu hình auto-reply rules.

#### `{prefix}bztfb_jobs`
Job queue cho AI đăng bài (thuộc `bizcity-tool-facebook`).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) PK | Auto increment |
| `user_id` | bigint(20) | WordPress user ID |
| `session_id` | varchar(255) | Chat session ID |
| `topic` | text | Chủ đề bài viết |
| `image_url` | text | URL ảnh |
| `page_ids` | text JSON | Danh sách Page đăng |
| `status` | varchar(20) | `pending` / `generating` / `completed` / `failed` |
| `ai_title` | text | Tiêu đề AI tạo |
| `ai_content` | longtext | Nội dung AI tạo |
| `wp_post_id` | bigint(20) | WordPress post ID |
| `fb_post_ids` | text JSON | Facebook post IDs |
| `error_message` | text | Lỗi nếu failed |
| `created_at` | datetime | |
| `completed_at` | datetime | |

### Network Options (`wp_sitemeta`)

| Option Key | Mô tả |
|-----------|--------|
| `bizcity_fb_hub_blog_id` | Blog ID của Hub Site (gateway Facebook). 0 = main site |
| `bizcity_fb_app_id` | Facebook App ID (dùng chung) |
| `bizcity_fb_app_secret` | Facebook App Secret (dùng chung) |
| `bizcity_fb_verify_token` | Webhook verify token (default: `bizgpt`) |
| `bizcity_fb_route_table_version` | Schema version cho route table |

### Per-site Options (`wp_options`)

| Option Key | Mô tả |
|-----------|--------|
| `fb_pages_connected` | Array các Page đã kết nối `[{id, name, access_token, category}]` |
| `bztfb_ai_model` | Model AI dùng cho content gen (default: `gpt-4o`) |

---

## 5. Class Map & File Structure

```
mu-plugins/bizcity-facebook-bot/
├── bootstrap.php                          # Plugin init, includes, hooks
├── README.md                              # Docs cũ
├── ARCHITECTURE.md                        # ← File này
├── index.php                              # Security
├── .htaccess                              # Security
│
├── includes/
│   ├── class-central-webhook.php          # BizCity_Facebook_Central_Webhook
│   │   ├── register_rewrite_rules()       #   /facehook/ rewrite
│   │   ├── handle_request()               #   Parse & dispatch webhook
│   │   ├── get_route( page_id )           #   Lookup global route table
│   │   ├── register_route()               #   static — đăng ký page → blog
│   │   ├── unregister_route()             #   static — xoá route
│   │   ├── get_all_routes()               #   static — tất cả routes
│   │   ├── get_routes_by_blog()           #   static — routes theo blog_id
│   │   ├── get_hub_blog_id()              #   static — Hub Site blog ID
│   │   ├── get_hub_site_url()             #   static — Hub Site URL
│   │   ├── get_webhook_url()              #   static — Webhook URL trên Hub
│   │   └── maybe_create_route_table()     #   Tạo bảng nếu chưa có
│   │
│   ├── class-facebook-oauth.php           # BizCity_Facebook_OAuth
│   │   ├── handle_start()                 #   Redirect → Facebook OAuth
│   │   ├── handle_callback()              #   Exchange code → token → pages
│   │   ├── get_oauth_url()                #   static — URL cho button
│   │   ├── build_state()                  #   HMAC-signed state param
│   │   └── verify_state()                 #   Kiểm tra state khi callback
│   │
│   ├── class-network-admin-facebook.php   # BizCity_Network_Admin_Facebook
│   │   ├── render_page()                  #   Network Admin UI
│   │   ├── ajax_save_route()              #   AJAX: thêm/sửa route
│   │   ├── ajax_delete_route()            #   AJAX: xoá route
│   │   └── ajax_save_app_settings()       #   AJAX: lưu App config
│   │
│   ├── class-database.php                 # BizCity_Facebook_Bot_Database
│   │   ├── activate()                     #   Tạo tables (per-site)
│   │   └── run_migrations()               #   Schema migration
│   │
│   ├── class-migration.php                # Database migration helper
│   ├── class-admin-menu.php               # WP-Admin menu (per-site bot config)
│   ├── class-rest-api.php                 # REST API endpoints
│   ├── class-webhook-handler.php          # Legacy ?fbhook=1 handler (per-site)
│   └── index.php
│
├── lib/
│   ├── class-facebook-bot-api.php         # BizCity_Facebook_Bot_API
│   │   ├── send_message()                 #   Send Messenger text
│   │   ├── send_photo()                   #   Send Messenger photo
│   │   └── get_user_profile()             #   Get user info
│   │
│   ├── functions.php                      # Helper functions
│   ├── legacy-poster.php                  # Legacy: OAuth cũ, poster, connect page
│   ├── legacy-functions.php               # Legacy: auto-reply comment
│   ├── legacy-old-functions.php           # Legacy: very old functions
│   └── index.php
│
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
│
└── logs/                                  # Webhook debug logs

plugins/bizcity-tool-facebook/
├── bizcity-tool-facebook.php              # Plugin header + post type + autoload
├── SYSTEM-LOG.md                          # Changelog
├── index.php
│
├── includes/
│   ├── class-intent-provider.php          # BizCity_Tool_Facebook_Intent_Provider
│   │   ├── get_goal_patterns()            #   Regex → goal mapping
│   │   ├── get_plans()                    #   Slot definitions per goal
│   │   ├── get_tools()                    #   Tool name → callback
│   │   ├── detect_tone()                  #   Auto-detect writing tone
│   │   └── build_context()                #   System prompt context
│   │
│   ├── class-tools-facebook.php           # BizCity_Tool_Facebook
│   │   ├── create_facebook_post()         #   AI gen + post to FB
│   │   ├── post_facebook()                #   Pipeline: post pre-made content
│   │   └── list_facebook_posts()          #   List biz_facebook posts
│   │
│   ├── class-ajax-facebook.php            # BizCity_Ajax_Facebook
│   │   ├── handle_generate_post()         #   AJAX: tạo bài
│   │   ├── handle_poll_jobs()             #   AJAX: kiểm tra jobs
│   │   ├── handle_connect_page()          #   AJAX: kết nối page (manual token)
│   │   ├── handle_disconnect_page()       #   AJAX: ngắt kết nối page
│   │   └── handle_register_route()        #   AJAX: đăng ký route
│   │
│   ├── admin-menu.php                     # Dashboard stats trong wp-admin
│   ├── integration-chat.php               # bztfb_notify_chat() → webchat
│   ├── install.php                        # Tạo bztfb_jobs table
│   └── index.php
│
├── views/
│   └── page-facebook-profile.php          # Frontend /tool-facebook/ (4-tab UI)
│
└── assets/
    ├── admin.css
    ├── admin.js
    └── icon.png
```

---

## 6. Phase & Roadmap

### Phase 0 — Foundation ✅ (Hoàn thành)

> Hạ tầng cơ bản: webhook, bot API, database, admin.

| Task | Trạng thái | Ghi chú |
|------|-----------|---------|
| Bootstrap + plugin structure | ✅ | mu-plugin loader |
| Database schema (bots, messages, conversations) | ✅ | Per-site tables |
| Facebook Bot API client | ✅ | Graph API v18.0 |
| Webhook handler `?fbhook=1` | ✅ | Per-site, legacy |
| REST API endpoints | ✅ | send-message, send-photo |
| Admin menu (per-site bot config) | ✅ | WP-Admin |
| Legacy functions migration | ✅ | Từ bizcity-admin-hook |

---

### Phase 1 — Central Webhook & Multisite ✅ (Hoàn thành)

> Single webhook cho toàn bộ multisite, global route table.

| Task | Trạng thái | Ghi chú |
|------|-----------|---------|
| Central Webhook `/facehook/` router | ✅ | Rewrite rule + query param fallback |
| Global route table `wp_bizcity_facebook_page_routes` | ✅ | Khai báo trong db.php |
| Route CRUD (register/unregister/get) | ✅ | Static methods, sử dụng `base_prefix` |
| Webhook verify token từ `site_option` | ✅ | Network-wide config |
| Remove `switch_to_blog` khỏi route queries | ✅ | Global DB routing tự động |

---

### Phase 2 — Network Admin & OAuth ✅ (Hoàn thành)

> Super Admin quản lý tập trung, OAuth 1-click cho subsite admin.

| Task | Trạng thái | Ghi chú |
|------|-----------|---------|
| Network Admin UI "Facebook Central" | ✅ | App config + Routes CRUD |
| Central OAuth flow (HMAC state) | ✅ | Main site callback only |
| Long-lived token exchange (60 ngày) | ✅ | Tự động trong callback |
| Auto-register routes sau OAuth | ✅ | Mỗi Page → 1 route |
| OAuth button trên subsite profile view | ✅ | `/tool-facebook/` Pages tab |
| OAuth status messages trên legacy connect page | ✅ | Success/error notices |

---

### Phase 3 — Tool Plugin & AI Posting ✅ (Hoàn thành)

> `bizcity-tool-facebook` — marketplace plugin cho AI đăng bài.

| Task | Trạng thái | Ghi chú |
|------|-----------|---------|
| Plugin scaffold (3-pillar) | ✅ | |
| Intent Provider (goal detection, tone analysis) | ✅ | 2 goals, 3 tools |
| Tool callbacks (AI content gen + FB post) | ✅ | OpenRouter / GPT |
| Post type `biz_facebook` | ✅ | Extracted from legacy |
| Profile View `/tool-facebook/` | ✅ | 4-tab UI |
| AJAX handlers (generate, poll, connect/disconnect) | ✅ | |
| Chat integration (notify webchat) | ✅ | |
| Admin dashboard (stats, recent jobs) | ✅ | |

---

### Phase 4 — Messenger AI Chatbot 🔜 (Tiếp theo)

> AI tự động trả lời tin nhắn Messenger, tích hợp intent system.

| Task | Trạng thái | Ưu tiên | Ghi chú |
|------|-----------|---------|---------|
| Messenger webhook integration với intent system | ⬜ | P0 | `bizcity_fb_webhook_message` → route to intent |
| AI auto-reply cho Messenger (sử dụng OpenRouter) | ⬜ | P0 | Context: page info + conversation history |
| Conversation tracking (sender_id → session) | ⬜ | P1 | Map FB user → bizcity session |
| Persistent menu & Get Started button | ⬜ | P1 | Messenger Platform webhooks |
| Quick Replies & Buttons template | ⬜ | P2 | Structured messages |
| Handover Protocol (AI → human) | ⬜ | P2 | Chuyển cho nhân viên khi cần |
| Typing indicator & read receipts | ⬜ | P3 | UX enhancement |

**Kiến trúc dự kiến:**
```
Facebook Messenger → /facehook/ → route to blog
  → BizCity_FB_Messenger_Handler::handle_message()
    → BizCity_Intent_Engine::process( message, context: 'facebook_messenger' )
      → AI response via OpenRouter
    → BizCity_Facebook_Bot_API::send_message( response )
```

---

### Phase 5 — Comment AI & Engagement 🔜

> AI tự động trả lời comment trên Fanpage, tăng engagement.

| Task | Trạng thái | Ưu tiên | Ghi chú |
|------|-----------|---------|---------|
| Comment webhook handler (`feed` event) | ⬜ | P0 | Parse comment_id, message, post_id |
| AI auto-reply comment (context-aware) | ⬜ | P0 | Dựa trên nội dung bài gốc |
| Comment filter rules (skip spam, only first-level) | ⬜ | P1 | Tránh reply loop |
| Comment sentiment analysis | ⬜ | P2 | Positive/negative → tone phù hợp |
| Comment templates (CTA, link, promo) | ⬜ | P2 | Kết hợp AI + template |
| Comment analytics dashboard | ⬜ | P3 | Thống kê reply rate, sentiment |

---

### Phase 6 — Scheduling & Content Calendar 📅

> Lịch đăng bài, hàng loạt, thời điểm tối ưu.

| Task | Trạng thái | Ưu tiên | Ghi chú |
|------|-----------|---------|---------|
| Schedule post (chọn ngày giờ đăng) | ⬜ | P0 | WP Cron + bztfb_jobs.scheduled_at |
| Content calendar UI (drag & drop) | ⬜ | P1 | Profile view tab mới |
| Batch posting (1 topic → nhiều bài) | ⬜ | P1 | Series content |
| Optimal time suggestion (AI) | ⬜ | P2 | Dựa trên analytics |
| Recurring posts (weekly, monthly) | ⬜ | P3 | Template-based |

---

### Phase 7 — Analytics & Insights 📊

> Theo dõi hiệu quả, insights từ Facebook, dashboard nâng cao.

| Task | Trạng thái | Ưu tiên | Ghi chú |
|------|-----------|---------|---------|
| Fetch post insights (reach, engagement) | ⬜ | P0 | Graph API `/post_id/insights` |
| Dashboard analytics (chart.js) | ⬜ | P1 | Trend, comparison |
| A/B testing (2 nội dung → so sánh) | ⬜ | P2 | AI gen 2 variants |
| Weekly report (email / webchat) | ⬜ | P2 | Scheduled delivery |
| AI content optimization suggestions | ⬜ | P3 | Dựa trên data → recommend |

---

### Phase 8 — Advanced Features 🚀

> Token management, multi-app, Instagram, v.v.

| Task | Trạng thái | Ưu tiên | Ghi chú |
|------|-----------|---------|---------|
| Token refresh automation | ⬜ | P0 | Cron check 60-day expiry, auto-refresh |
| Token health monitoring | ⬜ | P0 | Daily check, alert khi gần hết hạn |
| Multi-App support | ⬜ | P2 | Nhiều Facebook App cho nhiều nhóm site |
| Instagram integration | ⬜ | P2 | Reuse Graph API, thêm IG endpoints |
| Reels / Stories posting | ⬜ | P3 | Video content |
| Lead generation (forms) | ⬜ | P3 | FB Lead Ads → WP |

---

## 7. Trạng thái hiện tại

### Hoàn thành (Phase 0–3)

```
✅ Central Webhook /facehook/ — single endpoint cho multisite
✅ Global route table — db.php sharding, base_prefix queries
✅ Network Admin UI — App config + route CRUD
✅ Central OAuth — HMAC state, main-site callback, long-lived token
✅ Tool plugin — Intent Provider + 3 tools + 4-tab profile view
✅ AI content generation — OpenRouter/GPT → auto-post
✅ Chat integration — Notify webchat on job complete
```

### Đang phát triển / Ưu tiên cao nhất

```
🔜 Phase 4: Messenger AI Chatbot — auto-reply tin nhắn
🔜 Phase 5: Comment AI — auto-reply bình luận
🔜 Phase 8 (P0): Token refresh automation — tránh token hết hạn
```

### Tech Debt & Cải thiện

| Item | Mức độ | Ghi chú |
|------|--------|---------|
| Legacy code trong `lib/` | Trung bình | Dần migrate sang class-based |
| `?fbhook=1` handler cũ | Thấp | Giữ cho backward-compat, prefer `/facehook/` |
| Per-site App ID/Secret | Thấp | Vẫn hoạt động, prefer network-wide |
| Error handling trong webhook | Trung bình | Cần retry queue cho failed deliveries |
| Unit tests | Cao | Chưa có — cần viết cho OAuth, webhook router |
| Rate limiting | Trung bình | Facebook API rate limits cần handle |

---

## Appendix: Hooks & Filters

### Actions (do_action)

| Hook | Nơi fire | Params |
|------|---------|--------|
| `bizcity_fb_webhook_message` | Central Webhook | `$page_id, $sender_id, $message, $blog_id` |
| `bizcity_fb_webhook_comment` | Central Webhook | `$page_id, $comment_data, $blog_id` |
| `bizcity_fb_page_connected` | OAuth callback | `$page_id, $blog_id, $page_data` |
| `bizcity_fb_page_disconnected` | AJAX disconnect | `$page_id, $blog_id` |
| `bizcity_fb_post_published` | Tool callback | `$wp_post_id, $fb_post_ids, $page_ids` |

### Filters (apply_filters)

| Hook | Nơi dùng | Params |
|------|---------|--------|
| `bizcity_fb_ai_system_prompt` | Tool: create_facebook_post | `$prompt, $tone, $topic` |
| `bizcity_fb_post_content` | Tool: before posting | `$content, $wp_post_id` |
| `bizcity_fb_oauth_scopes` | OAuth start | `$scopes` |
| `bizcity_fb_webhook_response` | Webhook handler | `$response, $event_type` |
