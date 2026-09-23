# Hướng Dẫn Sử Dụng WebChat — BizCity Twin AI

> **Phiên bản:** 2.x | **Cập nhật:** 2026-07-02
> **Module:** `modules/webchat` | **Bootstrap:** `bootstrap.php`

---

## 1. Tổng Quan

WebChat có **2 chế độ hiển thị**:

| Chế độ | Mô tả | Kích hoạt bằng |
|---|---|---|
| **Float Widget** | Nút chat nổi ở góc màn hình (mọi trang) | Cấu hình trong admin panel |
| **Embed** | Chat nhúng trực tiếp vào trang/bài viết | Shortcode |

---

## 2. Shortcodes Hiện Có

### 2.1 Chat Nhúng (Embed) — giao diện ChatGPT-style

```
[bizcity_webchat]
[webchat]
[bizcity_chat]
[chatbot]
```

**Tham số tùy chọn:**

```
[bizcity_webchat character_id="5" width="100%" height="700px"]
[webchat style="embed" character_id="3"]
```

| Tham số | Mặc định | Mô tả |
|---|---|---|
| `character_id` | _(nhân vật đầu tiên)_ | ID nhân vật AI sẽ trả lời |
| `width` | `100%` | Chiều rộng khung chat |
| `height` | `700px` | Chiều cao khung chat |
| `style` | `embed` | Kiểu hiển thị: `embed` hoặc `float` |

### 2.2 Timeline Hội Thoại

```
[bizcity_webchat_timeline]
```

Hiển thị lịch sử hội thoại của người dùng hiện tại.

---

## 3. Float Widget — Chat Nổi Góc Màn Hình

### 3.1 Kích Hoạt / Tắt

Vào **BizCity → Channel Gateway → WebChat → Guru & Chế độ**:

- **Bật/tắt float widget** tổng thể
- Chọn vị trí: `bottom-right` (mặc định), `bottom-left`
- Chọn nhân vật AI phụ trách

### 3.2 Cấu Hình Trong Code (WP Options)

| Option Key | Mặc định | Mô tả |
|---|---|---|
| `bizcity_webchat_widget_enabled` | `true` | Bật/tắt widget |
| `bizcity_webchat_widget_position` | `bottom-right` | Vị trí nút float |
| `bizcity_webchat_primary_color` | `#3182f6` | Màu chủ đạo |
| `bizcity_webchat_bot_name` | `BizChat AI` | Tên hiển thị |
| `bizcity_webchat_bot_avatar` | _(trống)_ | URL ảnh đại diện |
| `bizcity_webchat_welcome` | `Xin chào! Tôi có thể giúp gì...` | Lời chào đầu |
| `bizcity_webchat_placeholder` | `Nhập tin nhắn...` | Placeholder input |
| `bizcity_webchat_show_mobile` | `true` | Hiện trên mobile |
| `bizcity_webchat_auto_open` | `0` | Tự mở sau X giây (0 = tắt) |
| `bizcity_webchat_excluded_pages` | `[]` | Danh sách page ID ẩn widget |

### 3.3 Inject Thủ Công Vào Chân Trang (Footer)

Widget float tự inject qua hook `wp_footer`. Nếu cần inject có điều kiện:

```php
// Trong functions.php hoặc mu-plugin
add_filter( 'bizcity_webchat_should_display', function( $show ) {
    // Chỉ hiện trên trang chủ và trang sản phẩm
    if ( is_front_page() || is_singular('product') ) {
        return true;
    }
    return false;
} );
```

### 3.4 Tùy Chỉnh CSS Float Button

```css
/* Thay đổi màu nút float */
#bizchat-float-btn {
    background: #your-color !important;
}

/* Thay đổi vị trí */
#bizchat-float-btn {
    bottom: 100px !important; /* cách chân trang 100px */
    right: 30px !important;
}
```

---

## 4. Đào Tạo Kiến Thức Cho WebChat

WebChat sử dụng **Knowledge Graph (KG)** và **nhân vật AI (Character)** để trả lời.

### 4.1 Thêm Kiến Thức Qua KG Hub

1. Vào **BizCity → Knowledge Graph**
2. Thêm nguồn kiến thức: tài liệu PDF, URL, văn bản thủ công
3. KG tự động phân mảnh và index nội dung
4. WebChat sẽ tự tra cứu KG khi nhận câu hỏi liên quan

### 4.2 Cấu Hình Nhân Vật AI (Character)

1. Vào **BizCity → TwinBrain → Nhân vật**
2. Tạo nhân vật mới với:
   - **Tên hiển thị** và **ảnh đại diện**
   - **System prompt** — định nghĩa tính cách, lĩnh vực chuyên môn
   - **Model AI** — `gpt-4o`, `gpt-4o-mini`, `claude-3`, `gemini-pro`...
   - **Nguồn kiến thức** — chọn KG source liên kết
3. Liên kết nhân vật với WebChat:
   - Dùng `character_id` trong shortcode, hoặc
   - Chọn nhân vật mặc định trong **WebChat → Cấu hình**

### 4.3 Custom System Prompt Cho WebChat

```php
// Inject thêm context vào system prompt của webchat
add_filter( 'bizcity_chat_system_prompt', function( $prompt, $context ) {
    if ( isset($context['platform']) && $context['platform'] === 'WEBCHAT' ) {
        $prompt .= "\n\nBạn đang hỗ trợ khách hàng trên website " . get_bloginfo('name') . ". ";
        $prompt .= "Hãy trả lời lịch sự và chuyên nghiệp bằng tiếng Việt.";
    }
    return $prompt;
}, 20, 2 );
```

---

## 5. Tích Hợp CRM Inbox

Tin nhắn từ WebChat tự động vào **CRM Inbox** (Zone 1 — Kênh Khách Hàng).

- Mỗi visitor = 1 session (`guest_sid` cho khách, `user_id` cho thành viên)
- NV có thể chuyển tiếp hội thoại từ AI → xử lý thủ công trong Inbox

---

## 6. Ví Dụ Thực Tế

### Nhúng chat vào trang "Tư vấn sản phẩm"

```
[bizcity_webchat character_id="2" height="600px" width="100%"]
```

### Float widget chỉ xuất hiện trên trang blog

```php
add_filter( 'bizcity_webchat_should_display', function( $show ) {
    return is_singular('post') || is_home();
} );
```

### Tắt float widget trên trang thanh toán

```php
add_filter( 'bizcity_webchat_should_display', function( $show ) {
    if ( function_exists('is_checkout') && is_checkout() ) return false;
    if ( is_page('thanh-toan') ) return false;
    return $show;
} );
```

---

## 7. Webhook Logs & Debug

- **Webhook logs:** BizCity → Channel Gateway → WebChat → Webhook logs
- **File log:** `wp-content/uploads/sites/{blog_id}/bizcity-channel-logs/webchat/YYYY-MM-DD.jsonl`
- **Lỗi thường gặp:**

| Lỗi | Nguyên nhân | Xử lý |
|---|---|---|
| Widget không hiện | `bizcity_webchat_widget_enabled = false` | Bật trong Cấu hình |
| AI không trả lời | API key chưa cấu hình | BizCity → Cài đặt → API Key |
| Mất kết nối | `bridge_not_configured` | Cấu hình bridge URL + token |
| Float button bị ẩn bởi CSS theme | Conflict z-index | Thêm `z-index: 99999` cho `#bizchat-float-btn` |

---

## 8. Files Quan Trọng

```
modules/webchat/
├── bootstrap.php                    ← Entry point, shortcodes, wp_footer hook
├── includes/
│   ├── class-webchat-widget.php     ← Widget config & display logic
│   ├── class-chatbot-shortcode.php  ← [webchat], [chatbot], [bizcity_chat]
│   └── class-webchat-api.php        ← REST endpoints
├── templates/
│   ├── widget-float.php             ← Template nút float góc màn hình
│   └── widget-embed.php             ← Template nhúng shortcode
└── docs/
    └── WEBCHAT-GUIDE.md             ← File này
```
