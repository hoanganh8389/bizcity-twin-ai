# INTENT-SKELETON — Mẫu Đặc Tả Skill Cho Plugin

> **Cách dùng**: Copy file này vào `bizcity-{slug}/INTENT-SKELETON.md`, sau đó điền thông tin.
> Đây là "hợp đồng" giữa plugin và Intent Engine.
> Sau khi điền → implement vào `includes/class-intent-provider.php`.

---

## Plugin Info

| Field | Value |
|-------|-------|
| Plugin Name | BizCity {Name} |
| Plugin Slug | `bizcity-{slug}` |
| Provider ID | `{slug}` |
| Provider Class | `BizCity_{Name}_Intent_Provider` |
| Prefix | `bz{prefix}_` |
| Version | 1.0.0 |

---

## Goals

### Goal 1: `{goal_id}`

**Mô tả**: {Mô tả ngắn mục tiêu — ví dụ: Bốc bài Tarot theo chủ đề}

**Needles (regex):**
```
/{keyword1}|{keyword2}|{keyword3}|{keyword4}/ui
```

**Test messages (ít nhất 10 câu):**
- ✅ "Tôi muốn {action}" → detect
- ✅ "{keyword}" → detect
- ✅ "{variation tiếng Việt không dấu}" → detect
- ❌ "Hôm nay trời đẹp" → NOT detect
- ❌ "{Câu dễ gây false positive}" → NOT detect

**Required Slots:**

| Slot | Type | Prompt | Choices / Validation |
|------|------|--------|---------------------|
| `slot_1` | choice | "Bạn muốn chọn gì?" | `a` = "Option A", `b` = "Option B" |
| `slot_2` | text | "Nhập câu hỏi?" | min 3 ký tự |

**Optional Slots:**

| Slot | Type | Prompt | Default |
|------|------|--------|---------|
| `slot_3` | image | "Gửi hình (không bắt buộc)" | — |

**Slot Order:** `slot_1` → `slot_2` → `slot_3`

**Tool:** `{slug}_{action}` (hoặc `null` nếu chỉ AI compose)

**AI Compose:** ✅ có / ❌ không

---

### Goal 2: `{goal_id_2}`

_(Copy block Goal 1 cho mỗi goal thêm)_

---

## Tools

### Tool: `{slug}_{action}`

**Mô tả:** {Chức năng — ví dụ: Tạo link bốc bài + thông tin spread}

**Input Fields (JSON Schema):**

| Field | Required | Type | Từ Pipeline | Mô tả |
|-------|----------|------|-------------|-------|
| `slot_1` | ✅ | text | `$slots.slot_1` | Dữ liệu chính |
| `slot_2` | ❌ | number | `$step[N].data.id` | Có thể nhận từ step trước |
| `slot_3` | ❌ | image | `$step[N].data.image_url` | URL ảnh từ step trước |

**Input JSON Schema:**
```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "type": "object",
    "required": ["slot_1"],
    "properties": {
        "slot_1": {
            "type": "string",
            "description": "Dữ liệu chính",
            "minLength": 1
        },
        "slot_2": {
            "type": "integer",
            "description": "Tùy chọn"
        },
        "slot_3": {
            "type": "string",
            "format": "uri",
            "description": "URL ảnh (không bắt buộc)"
        }
    }
}
```

---

**Output Contract:**

Tất cả tool **BẮT BUỘC** trả về đúng envelope sau:

```php
[
    'success'        => bool,     // BẮT BUỘC — Thao tác có thành công?
    'complete'       => bool,     // BẮT BUỘC — Goal đã hoàn tất? (false = async/cần thêm bước)
    'message'        => string,   // BẮT BUỘC — Mô tả kết quả cho user (hiển thị trong chat)
    'data'           => array,    // BẮT BUỘC — Structured output (xem Data Convention bên dưới)
    'missing_fields' => array,    // Tùy chọn — Các field còn thiếu → Engine hỏi user
]
```

**Output `data` Convention (Pipeline-compatible):**

> ⚠️ Để tool có thể tham gia pipeline (multi-step chaining), `data` **PHẢI** tuân thủ convention sau.
> Pipeline resolver sẽ dùng các key này để tự động mapping `$step[N].data.field` giữa các bước.

```php
'data' => [
    // ── Identification (BẮT BUỘC ít nhất 1) ──
    'id'        => mixed,    // ID chính của resource đã tạo (post_id, script_id, order_id...)
    'type'      => string,   // Loại output: 'article' | 'image' | 'video' | 'product' | 'post' | 'link' | 'data'

    // ── Content (tùy loại tool) ──
    'title'     => string,   // Tiêu đề resource
    'content'   => string,   // Nội dung chính (HTML/text) — KEY CHO PIPELINE CHAINING
    'excerpt'   => string,   // Tóm tắt ngắn

    // ── Media (nếu có) ──
    'url'       => string,   // URL chính (permalink, download link, page link)
    'image_url' => string,   // URL ảnh — KEY CHO PIPELINE CHAINING
    'video_url' => string,   // URL video

    // ── Platform (nếu liên quan đến xuất bản) ──
    'edit_url'  => string,   // URL chỉnh sửa trong admin
    'platform'  => string,   // 'wordpress' | 'facebook' | 'zalo' | 'telegram'

    // ── Extended ──
    'meta'      => array,    // Dữ liệu mở rộng tùy plugin (không chuẩn hóa)
]
```

**Output JSON Schema:**
```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "type": "object",
    "required": ["success", "complete", "message", "data"],
    "properties": {
        "success":        { "type": "boolean" },
        "complete":       { "type": "boolean" },
        "message":        { "type": "string" },
        "missing_fields": { "type": "array", "items": { "type": "string" } },
        "data": {
            "type": "object",
            "properties": {
                "id":        { "description": "ID chính của resource" },
                "type":      { "type": "string", "enum": ["article", "image", "video", "product", "post", "link", "data"] },
                "title":     { "type": "string" },
                "content":   { "type": "string" },
                "excerpt":   { "type": "string" },
                "url":       { "type": "string", "format": "uri" },
                "image_url": { "type": "string", "format": "uri" },
                "video_url": { "type": "string", "format": "uri" },
                "edit_url":  { "type": "string", "format": "uri" },
                "platform":  { "type": "string" },
                "meta":      { "type": "object" }
            }
        }
    }
}
```

---

**Output mẫu (điền cho tool cụ thể):**
```php
[
    'success'  => true,
    'complete' => true,
    'message'  => '🔮 Kết quả: ...',
    'data'     => [
        'type'      => '{article|image|video|product|post|link|data}',
        'id'        => $created_id,
        'title'     => '{tiêu đề}',
        'content'   => '{nội dung chính}',
        'url'       => 'https://example.com/page/?token=abc',
        'image_url' => 'https://example.com/image.jpg',
        'meta'      => [
            '{custom_key}' => '{custom_value}',
        ],
    ],
]
```

---

**Pipeline Input Mapping — Tool này nhận data từ step trước thế nào:**

> Điền nếu tool có thể là step giữa/cuối trong pipeline.

| Input field | Nhận từ | Reference |
|-------------|---------|-----------|
| `slot_1` | User trực tiếp | `$slots.slot_1` |
| `slot_2` | Output step trước | `$step[N].data.id` |
| `slot_3` | Output step trước | `$step[N].data.image_url` |

**Pipeline Output Mapping — Step sau dùng output của tool này thế nào:**

> Điền để làm rõ tool này cung cấp gì cho downstream.

| Output field | Ý nghĩa | Reference cho step sau |
|-------------|----------|----------------------|
| `data.id` | ID resource đã tạo | `$step[N].data.id` |
| `data.title` | Tiêu đề | `$step[N].data.title` |
| `data.content` | Nội dung chính | `$step[N].data.content` |
| `data.url` | Permalink | `$step[N].data.url` |
| `data.image_url` | URL ảnh | `$step[N].data.image_url` |

---

**Legacy function (nếu wrap):** `bz{prefix}_{function}()`

**Complete = true khi:** {điều kiện — ví dụ: link đã tạo thành công}
**Complete = false khi:** {điều kiện — ví dụ: cần user xác nhận tiếp}

---

## Context

### `build_context($goal, $slots, $user_id, $conversation)`

**Mô tả:** Dữ liệu chuyên môn inject vào system prompt cho AI compose.

**Nội dung trả về (string):**
```
=== DỮ LIỆU {NAME} ===
{Danh sách items từ DB, matched topic, spread info, ...}

=== LỊCH SỬ USER ===
{Số lần sử dụng, lần cuối, kết quả trước, ...}

=== CHỦ ĐỀ ===
{Topic đã chọn, câu hỏi liên quan}
```

**Data sources:**
- [ ] `bz{prefix}_items` table → item/card/stone data
- [ ] `bz{prefix}_history` table → user history
- [ ] `bz{prefix}_get_topics()` → topic matching
- [ ] WP user meta → user preferences
- [ ] External API → {mô tả nếu có}

---

### `get_system_instructions($goal)`

**Mô tả:** Hướng dẫn hành vi AI khi compose cho goal này.

**Nội dung trả về (string):**
```
Bạn là chuyên gia {domain}.
Khi trả lời về {goal}, hãy:
1. {Quy tắc 1}
2. {Quy tắc 2}
3. {Quy tắc 3}
4. {Quy tắc 4}
5. {Quy tắc 5}
Giọng văn: {ấm áp / bí ẩn / chuyên nghiệp / vui vẻ}
Ngôn ngữ: {Tiếng Việt, có thể dùng emoji}
```

---

## Chat Integration

### Intent Detection

| Hook | Priority | Platform |
|------|----------|----------|
| `bizcity_unified_message_intent` | 10 | Zalo, Telegram, Bot |
| `bizcity_chat_pre_ai_response` | 10 | Webchat, Admin Chat |

**Keyword function:** `bz{prefix}_is_intent($message)` → bool

### ⚠️ BẮT BUỘC: Sử dụng Enriched Context trong Intent Filter

Filter `bizcity_unified_message_intent` cung cấp **enriched context** chứa ngữ cảnh hội thoại gần đây. Plugin **BẮT BUỘC** sử dụng để đảm bảo AI hiểu đúng ngữ cảnh.

**Context keys có sẵn trong `$ctx`:**

| Key | Type | Mô tả |
|-----|------|--------|
| `message` | string | Tin nhắn hiện tại |
| `chat_id` | string | ID cuộc hội thoại |
| `wp_user_id` | int | WP User ID (0 nếu chưa login) |
| `client_id` | string | Client ID (Zalo/Telegram) |
| `platform` | string | ZALO_PERSONAL, ZALO_BOT, FACEBOOK, ... |
| `blog_id` | int | Blog ID (multisite) |
| `session_id` | string | Chat session ID |
| `attachment_url` | string | URL attachment gốc |
| `attachment_type` | string | image, file, sticker, ... |
| **`image_url`** | string | **URL ảnh đã xử lý (từ transient nếu có)** |
| **`recent_context`** | string | **Ngữ cảnh hội thoại gần đây (30 phút / 100 tin nhắn)** |

> `recent_context` được build bởi `bizgpt_build_cross_path_context()` cho Zalo/Bot/FB.
> Webchat có riêng session context qua 6-Layer Dual Context Chain.

### Pattern: Xử lý native (KHUYẾN KHÍCH)

Thay vì chỉ gửi link, plugin nên **xử lý trực tiếp** trên Zalo/Bot khi có thể:

**Flow Native:**
1. Detect keyword → `bz{prefix}_is_intent()`
2. Classify sub-intent (stats / action / suggest / ...)
3. Sử dụng `$ctx['recent_context']` để hiểu ngữ cảnh
4. Xử lý trực tiếp (query DB, gọi AI, ...)
5. Gửi kết quả trực tiếp qua `biz_send_message()`

**Flow Link (fallback khi cần frontend):**
1. Detect keyword → `bz{prefix}_is_intent()`
2. Create secure token → `bz{prefix}_create_token()`
3. Build link → `bz{prefix}_build_link()`
4. Send link to chat platform
5. User interact on frontend → Complete → Push result back

### Pattern: Pending Follow-up (Multi-turn trên Zalo/Bot)

Khi plugin cần hỏi thêm thông tin trước khi xử lý:

```php
// Lưu trạng thái chờ
$pending_key = 'bz{prefix}_pending_' . md5( $chat_id );
set_site_transient( $pending_key, 'awaiting_input', 10 * MINUTE_IN_SECONDS );

// Ở đầu filter — check pending TRƯỚC keyword check
$pending = get_site_transient( $pending_key );
if ( $pending === 'awaiting_input' && ! empty( $message ) ) {
    delete_site_transient( $pending_key );
    // Xử lý message như input cho bước trước
    return true;
}
```

> ⚠️ Check pending **TRƯỚC** `is_intent()` — vì reply của user (VD: "cơm gà") có thể không match keywords.

### Push Result

| Endpoint | AJAX Action |
|----------|-------------|
| Push AI result to chat | `bz{prefix}_push_result` |

---

## DB Tables

### Table: `{wp_prefix}bz{prefix}_items`

| Column | Type | Key | Mô tả |
|--------|------|-----|-------|
| id | BIGINT UNSIGNED | PK | Auto increment |
| slug | VARCHAR(80) | UNIQUE | URL-safe identifier |
| name_vi | VARCHAR(255) | | Tên tiếng Việt |
| name_en | VARCHAR(255) | | Tên tiếng Anh |
| category | VARCHAR(50) | INDEX | Phân loại |
| data_json | LONGTEXT | | Structured data |
| image_url | VARCHAR(500) | | Image URL |
| sort_order | SMALLINT | | Display order |
| created_at | DATETIME | | Auto timestamp |
| updated_at | DATETIME | | Auto update |

### Table: `{wp_prefix}bz{prefix}_history`

| Column | Type | Key | Mô tả |
|--------|------|-----|-------|
| id | BIGINT UNSIGNED | PK | Auto increment |
| user_id | BIGINT UNSIGNED | INDEX | WP user ID |
| client_id | VARCHAR(100) | INDEX | Zalo/Telegram ID |
| platform | VARCHAR(30) | | WEBCHAT / ADMINCHAT / ZALO_BOT |
| session_id | VARCHAR(64) | | Chat session ID |
| topic | VARCHAR(255) | | Chủ đề đã chọn |
| result_json | LONGTEXT | | Kết quả chi tiết |
| created_at | DATETIME | INDEX | Timestamp |

**Seed data mặc định:** {Mô tả — ví dụ: 78 lá bài Tarot, 25 viên rune, 12 cung hoàng đạo}

---

## Shortcode

| Attribute | Type | Default | Mô tả |
|-----------|------|---------|-------|
| `items_count` | int | 3 | Số items chọn |
| `show_topics` | 0\|1 | 1 | Hiện topic selector |
| `show_questions` | 0\|1 | 1 | Hiện gợi ý câu hỏi |

**Usage:** `[bizcity_{slug} items_count="3" show_topics="1"]`

**JS Localize (`BZ{PREFIX}_PUB`):**

| Key | Mô tả |
|-----|-------|
| ajax_url | WP AJAX endpoint |
| nonce | Public nonce |
| items_count | Items to pick |
| is_logged | User logged in? |
| token | Chat context token |
| has_chat_ctx | Opened from chat? |

---

## Dependencies

| Plugin | Required | Mô tả |
|--------|----------|-------|
| bizcity-intent | ❌ optional | Intent Engine — Provider chỉ load khi có |
| bizcity-knowledge | ❌ optional | Chat Gateway — bizcity_chat_pre_ai_response |
| bizcity-openrouter | ❌ optional | AI API — cho AJAX ai_interpret |
| bizcity-bot-webchat | ❌ optional | Admin dashboard hiển thị conversations |

---

## Implementation Checklist

- [ ] Phase 1: `install.php` → DB tables + seed
- [ ] Phase 2: `topics.php` → Data registry
- [ ] Phase 3: `admin-menu.php` → Admin UI
- [ ] Phase 4: `shortcode.php` → Frontend
- [ ] Phase 5: `ajax.php` → AJAX endpoints
- [ ] Phase 6: `integration-chat.php` → Chat hooks
- [ ] Phase 7: `class-intent-provider.php` → AI Skills
- [ ] Phase 8: Test end-to-end flow
- [ ] Phase 9: Pipeline compatibility (xem checklist bên dưới)

---

## Pipeline I/O Compatibility Checklist

> Đảm bảo tool có thể tham gia pipeline multi-step chaining.
> Xem [PIPELINE-ARCHITECTURE.md](PIPELINE-ARCHITECTURE.md) để hiểu chi tiết kiến trúc.

**Output Contract:**
```
□ Tool trả đúng envelope: { success, complete, message, data, missing_fields }
□ data.type có giá trị (article | image | video | product | post | link | data)
□ data.id chứa ID chính của resource đã tạo
□ data.content chứa nội dung chính (nếu tool tạo content)
□ data.image_url chứa URL ảnh (nếu tool tạo/liên quan ảnh)
□ data.url chứa permalink/link chính (nếu tool tạo resource có URL)
□ Khi async: complete=false + data chứa poll_endpoint + poll_params
□ Khi thiếu input: missing_fields liệt kê field names cần bổ sung
```

**Input Compatibility:**
```
□ Tool nhận input qua $slots array (không phụ thuộc global state)
□ Tất cả required fields được khai báo trong input_fields schema
□ Optional fields có default value hợp lý
□ Tool hoạt động đúng khi nhận input từ pipeline resolver (string values)
□ Tool không phụ thuộc get_current_user_id() — nhận user_id qua $slots
```

**Pipeline Template (nếu tool là step trong pipeline):**
```
□ Khai báo Pipeline Input Mapping (tool nhận data từ step trước thế nào)
□ Khai báo Pipeline Output Mapping (step sau dùng output thế nào)
□ Đăng ký pipeline template qua hook bizcity_pipeline_register_templates (tùy chọn)
□ Test: Chạy tool standalone → output đúng convention
□ Test: Chạy tool trong pipeline → input resolve đúng từ $step[N].data.field
□ Test: Tool fail gracefully → { success: false, message: '...' } (không throw exception)
```

---

## Cross-Path Context Checklist

> Đảm bảo plugin hoạt động tốt trên MỌI nền tảng (Webchat, Zalo Personal, Zalo Bot, FB Messenger).

```
□ Intent Filter sử dụng $ctx['recent_context'] khi gọi AI
□ Intent Filter sử dụng $ctx['image_url'] khi xử lý ảnh
□ Pending follow-up check TRƯỚC keyword check
□ Native processing (không chỉ gửi link) cho Zalo/Bot
□ Tool callbacks nhận explicit user_id (không dùng get_current_user_id())
□ AI prompts include recent_context để hiểu ngữ cảnh hội thoại
□ Test: Zalo → gửi keyword → nhận kết quả trực tiếp (không phải link)
□ Test: Zalo → gửi ảnh → phân tích trực tiếp
□ Test: Zalo → multi-turn (hỏi-đáp) → pending transient hoạt động
□ Test: Webchat → Intent Engine xử lý qua Provider (không qua filter)
```

---

## Image Upload Flow Checklist

> ⚠️ **BẮT BUỘC** nếu plugin xử lý ảnh (slot type: `image`).
> Tất cả ảnh PHẢI đi qua WordPress Media Library để có URL chuẩn, persistent.
> Xem [INTENT-SKELETON.md](INTENT-SKELETON.md) mục 10 để hiểu chi tiết kiến trúc.

**Slot type image:**
```php
// Trong get_goals()
'slots' => [
    [
        'id'       => 'photo_url',
        'type'     => 'image',           // ← Khai báo là image
        'prompt'   => 'Gửi ảnh để phân tích',
        'required' => true,
    ],
],
```

**Tool nhận image_url:**
```php
// Tool nhận URL từ Media Library (KHÔNG phải base64)
public function execute_photo_meal( $slots ) {
    $photo_url = $slots['photo_url'] ?? '';  // https://domain/wp-content/uploads/...
    
    // ✅ URL này:
    // - Persistent (không mất sau refresh)
    // - Có thể fetch từ AI APIs
    // - Có attachment_id trong Media Library
    
    // Sử dụng URL để gọi AI Vision, lưu DB, etc.
}
```

**Checklist:**
```
□ Slot type = 'image' được khai báo đúng trong get_goals()
□ Tool expect Media URL (https://...), KHÔNG expect base64
□ Skip phrases xử lý đúng (VD: "không cần ảnh", "tự tạo")
□ Tool output trả data.image_url nếu tạo ảnh
□ Test: User gửi ảnh → slot nhận URL từ Media Library
□ Test: Tool fetch URL → download được ảnh
□ Test: URL hoạt động với AI Vision APIs (OpenAI, Gemini, Claude)
```

**Helper functions (đã có sẵn trong bizcity-bot-webchat):**
- `bizcity_convert_images_to_media_urls($images)` — Batch convert base64 → Media URL
- `bizcity_save_base64_to_media($base64, $filename)` — Single base64 → attachment_id
- `bizcity_upload_to_media_library($file)` — $_FILES → Media URL

---

## Execution Logging — tool_step Standard (Phase 10.1)

> **Bắt buộc** cho mọi tool có ≥ 2 bước xử lý.
> Xem API đầy đủ tại [INTENT-SKELETON.md](INTENT-SKELETON.md) mục 11.

### Mẫu nhanh

```php
public function execute_my_goal( $slots ) {

    // Bước 1
    if ( class_exists( 'BizCity_Execution_Logger' ) ) {
        BizCity_Execution_Logger::log( 'tool_step', [
            'tool_name' => 'my_goal',
            'sub_step'  => '1/2 fetch_data',
            'status'    => 'running',
        ] );
    }
    $data = some_api_call( $slots );
    if ( class_exists( 'BizCity_Execution_Logger' ) ) {
        BizCity_Execution_Logger::log( 'tool_step', [
            'tool_name' => 'my_goal', 'sub_step' => '1/2 fetch_data',
            'status'    => $data ? 'success' : 'error',
            'message'   => $data ? null : 'API trả về rỗng',
        ] );
    }
    if ( ! $data ) {
        return [ 'success' => false, 'message' => '...' ];
    }

    // Bước 2
    if ( class_exists( 'BizCity_Execution_Logger' ) ) {
        BizCity_Execution_Logger::log( 'tool_step', [
            'tool_name' => 'my_goal', 'sub_step' => '2/2 save_result', 'status' => 'running',
        ] );
    }
    $post_id = create_wp_post( $data );
    if ( class_exists( 'BizCity_Execution_Logger' ) ) {
        BizCity_Execution_Logger::log( 'tool_step', [
            'tool_name' => 'my_goal', 'sub_step' => '2/2 save_result',
            'status'    => $post_id ? 'success' : 'error',
            'post_id'   => $post_id ?: null,
            'url'       => $post_id ? get_permalink( $post_id ) : null,
        ] );
    }

    return [
        'success'  => (bool) $post_id,
        'complete' => true,
        'message'  => $post_id ? "✅ Done! " . get_permalink( $post_id ) : "❌ Failed.",
        'data'     => [ 'type' => 'my_type', 'id' => $post_id ],
    ];
}
```

### Checklist tool_step

```
□ Tool có ≥ 2 bước → log tool_step
□ Log 'running' TRƯỚC khi gọi API / external function
□ Log 'success' SAU khi thành công, kèm data keys liên quan
□ Log 'error' + BizCity_Execution_Logger::error() khi fail
□ Wrap trong class_exists('BizCity_Execution_Logger') guard
□ sub_step format: "N/Total label" (1-based)
□ Không log sensitive data (tokens, passwords)
□ Test: Working Panel hiển thị sub-steps khi tool chạy
```

---

> **Sau khi điền xong** → review → implement theo [PLUGIN-STANDARD.md](../bizcity-intent/PLUGIN-STANDARD.md)
> Pipeline I/O → xem [PIPELINE-ARCHITECTURE.md](PIPELINE-ARCHITECTURE.md) mục 3 (Convention) + mục 6.3 (Resolver)
> Execution Logging → xem [INTENT-SKELETON.md](INTENT-SKELETON.md) mục 11 (tool_step Standard)

