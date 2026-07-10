# BizCity Intent — Plugin Skeleton Note (v3.0.0)

> Tài liệu mô tả cách mỗi plugin tương tác với **Intent Engine** (`bizcity-intent`).
> Mỗi plugin cần có file `INTENT-SKELETON.md` tương tự để đảm bảo nhất quán.

---

## Plugin: `bizcity-intent`

**Vai trò:** Core intent engine — nhận diện ý định, lên kế hoạch, gọi tool, quản lý hội thoại, **xây dựng 6-layer dual context** (ngắn hạn + dài hạn).

### 1. Intents (Goals) — đăng ký trong Router

| Goal              | Patterns (từ khóa)                           | Description                  |
|-------------------|----------------------------------------------|------------------------------|
| `create_product`  | tạo sản phẩm, đăng sp, thêm hàng            | Tạo sản phẩm WooCommerce    |
| `write_article`   | viết bài, đăng bài, tạo blog                | Tạo bài viết WordPress      |
| `post_facebook`   | đăng fb, share facebook                      | Đăng bài lên Facebook       |
| `create_video`    | tạo video, làm clip                          | Tạo video bằng Kling AI     |
| `set_reminder`    | nhắc việc, tạo task, lịch hẹn               | Tạo nhắc việc / biztask     |
| `generate_report` | báo cáo, doanh thu, report                   | Xem báo cáo kinh doanh      |
| `create_order`    | tạo đơn, đặt hàng, order                    | Tạo đơn hàng WooCommerce    |
| `edit_product`    | sửa sản phẩm, cập nhật giá, đổi tên sp      | Sửa thông tin sản phẩm      |
| `list_orders`     | xem đơn hàng, danh sách order                | Liệt kê đơn hàng            |
| `find_customer`   | tìm khách, tra cứu KH, check phone          | Tìm khách hàng theo SĐT/tên |
| `customer_stats`  | thống kê khách, top customers                | Top khách hàng               |
| `product_stats`   | hàng bán chạy, top sản phẩm                 | Top sản phẩm bán chạy       |
| `inventory_report`| xuất nhập tồn, XNT, tồn kho                 | Báo cáo xuất nhập tồn       |
| `inventory_journal`| nhật ký xuất nhập, log kho                  | Nhật ký xuất nhập kho        |
| `warehouse_receipt`| nhập kho, phiếu nhập                        | Tạo phiếu nhập kho           |
| `help_guide`      | hướng dẫn, help, trợ giúp                    | Xem hướng dẫn sử dụng       |

### 2. Tools — đăng ký trong Tools class

| Tool Name          | Required Slots                       | Legacy Function              | `complete` |
|--------------------|--------------------------------------|-------------------------------|:----------:|
| `create_product`   | `name`                               | `twf_handle_product_post_flow` |    ✅      |
| `write_article`    | `topic`                              | `ai_generate_content` + `twf_wp_create_post` |    ✅      |
| `post_facebook`    | `content`                            | `twf_handle_facebook_multi_page_post` |    ✅      |
| `create_video`     | `content`                            | `BizCity_Video_Kling_Database::save_script` |    ✅      |
| `set_reminder`     | `what`                               | `twf_create_biztask_from_ai`  |    ✅      |
| `generate_report`  | _(none)_                             | _(direct query)_              |    ✅      |
| `create_order`     | `customer_name`                      | `twf_handle_create_order_ai_flow` |    ✅      |
| `edit_product`     | `product_id`, `field`, `new_value`   | `twf_handle_edit_product_flow`|    ✅      |
| `list_orders`      | _(optional: so_ngay, from_date)_     | `twf_telegram_order_list_report2` |    ✅      |
| `find_customer`    | `search_term` or `phone`             | `twf_handle_find_customer_order_by_phone` |    ✅      |
| `customer_stats`   | _(optional: so_ngay)_                | `twf_bao_cao_top_customers`   |    ✅      |
| `product_stats`    | _(optional: so_ngay)_                | `twf_bao_cao_top_product`     |    ✅      |
| `inventory_report` | _(optional: from_date, to_date)_     | `twf_bao_cao_xuat_nhap_ton`  |    ✅      |
| `inventory_journal`| _(optional: from_date, to_date)_     | `twf_bao_cao_nhat_ky_xuat_nhap` |    ✅      |
| `warehouse_receipt`| `content`                            | `twf_parse_phieu_nhap_kho_ai`|    ✅      |
| `help_guide`       | _(optional: topic)_                  | `twf_ai_telegram_help_content`|    ✅      |

### 3. Completion Contract

Mỗi tool **BẮT BUỘC** trả về `'complete' => true` khi công việc đã thực sự được thực thi.

```php
return [
    'success'  => true,           // Tool chạy OK?
    'complete' => true,           // ✅ Goal đã hoàn thành → conversation COMPLETED
    'message'  => '✅ Đã xong.', // Thông báo cho user
    'data'     => [],             // Dữ liệu bổ sung (post_id, url, etc.)
];
```

**Chỉ trả `'complete' => false`** khi:
- Tool async (ví dụ: queue video generation, gỡ lỗi từ xa)
- Cần user xác nhận tiếp (multi-step wizard)

**Mặc định:** `success === true` → `complete === true` (engine tự suy luận).

### 4. Filters & Actions

| Hook                              | Type     | Description                              |
|-------------------------------------|----------|------------------------------------------|
| `bizcity_intent_tool_complete`     | filter   | Override completion logic per tool        |
| `bizcity_intent_classify`          | filter   | Override intent classification            |
| `bizcity_intent_plan`             | filter   | Override plan generation                  |
| `bizcity_intent_tools`            | filter   | Register custom tools                     |
| `bizcity_intent_tool_{name}`      | action   | Fired after tool executes                 |
| `bizcity_intent_router_patterns`  | filter   | Add custom regex goal patterns            |
| `bizcity_intent_planner_plans`    | filter   | Add custom execution plans                |

### 5. Pipeline Flow

```
User Message → intercept_chat()
  → Router::classify()          [goal, confidence, action]
  → Planner::plan()             [tool_name, slots, ai_compose]
  → Engine::process()
    ├─ ask_user        → Ask for missing slots
    ├─ call_tool       → Build _meta → Tools::execute() → complete? Y→COMPLETED, N→compose
    ├─ compose_answer  → Let AI elaborate
    └─ passthrough     → Not an intent → normal AI
```

### 5b. Tool Input Meta — `_meta` Schema (v3.5.1)

Engine inject `$slots['_meta']` tự động **trước khi gọi** `Tools::execute()`.
Mọi tool callback nhận được ngữ cảnh thực thi chuẩn hóa.

```
┌─────────────────────────────────────────────────┐
│  Engine — call_tool case                         │
│                                                   │
│  $tool_slots['_meta'] = [                        │
│      '_context'     → build_tool_context(1200)   │
│      'conv_id'      → intent conversation UUID   │
│      'goal'         → goal identifier            │
│      'goal_label'   → human label                │
│      'character_id' → AI character binding        │
│      'channel'      → webchat|adminchat|tg|zalo  │
│      'message_id'   → original message ID        │
│      'user_display' → user display name          │
│      'blog_id'      → multisite blog ID          │
│  ]                                                │
│          ↓                                        │
│  Tools::execute( $tool_name, $tool_slots )       │
│          ↓                                        │
│  Callback nhận full $slots kèm _meta             │
└─────────────────────────────────────────────────┘
```

**`_context` chứa gì?** Dual Context layers 2→5 dạng compact text (max 1200 chars):
- Layer 2: Intent Conversation (goal, slots, rolling summary)
- Layer 3: Webchat Session (12 recent messages)
- Layer 4: Cross-Session (5 recent session titles)
- Layer 5: Project (project name, character)

**3 Patterns sử dụng trong tool callback:**

| Pattern | Trường hợp | Cách inject |
|---------|-----------|-------------|
| **A** | `bizcity_openrouter_chat()` | `$sys .= "\n\n" . $ai_context;` |
| **B** | Legacy function (`ai_generate_content()`) | Prepend `[Ngữ cảnh]\n...\n\n[Yêu cầu]\n...` |
| **C** | Data-only (DB query) | `_meta` có sẵn nhưng không cần inject |

**Mẫu chuẩn:**
```php
public static function my_tool( array $slots ): array {
    $meta       = $slots['_meta']    ?? [];
    $ai_context = $meta['_context']  ?? '';
    // ... validate, trace ...
    $sys = 'Role description. Chỉ trả JSON.';
    if ( $ai_context ) {
        $sys .= "\n\n" . $ai_context;
    }
    $result = bizcity_openrouter_chat([...]);
    // ... parse, return Envelope ...
}
```

**validate_inputs()** không kiểm tra `_meta` (không nằm trong `input_fields` schema).

### 6. Cách thêm Intent/Tool mới

1. **Router:** Thêm pattern vào `goal_patterns` của `class-intent-router.php`
2. **Planner:** Thêm plan vào `plans` của `class-intent-planner.php`
3. **Tools:** Đăng ký + triển khai method `builtin_xxx()` trong `class-intent-tools.php`
4. **Hoặc dùng filter:** `bizcity_intent_tools`, `bizcity_intent_router_patterns`, `bizcity_intent_planner_plans`

### 7. Testing Checklist

- [ ] Intent được nhận diện đúng (check Router logs)
- [ ] Slots được trích xuất hoặc hỏi user
- [ ] Tool thực sự chạy (gọi legacy function, tạo dữ liệu)
- [ ] `complete => true` được trả về
- [ ] Conversation đạt trạng thái `COMPLETED`
- [ ] Không có loop (gửi tin tiếp → không lặp lại tool)
- [ ] Logs hiện đúng trong Monitor
- [ ] **`provider_profile_build` > 0 chars** (check debug panel)
- [ ] **Mode = `execution`** khi hỏi keywords chuyên môn
- [ ] **`_meta._context` non-empty** khi tool callback fires (check logs)
- [ ] **Tool AI call includes context** — system prompt chứa `# NGỮ CẢNH HỘI THOẠI`
- [ ] **Test ≥10 câu thực tế** (action + question + broad keywords)

#### 7.0.1 ⚠️ Bắt Buộc: Cross-Path Context (Lesson Learned v3.1)

> **Bối cảnh:** Plugin `bizcity-agent-calo` v1.0 trên Zalo chỉ gửi link, không xử lý trực tiếp.
> AI không có ngữ cảnh hội thoại → câu hỏi follow-up bị miss, sticker bị xử lý sai.
> **Root cause:** Intent filter không sử dụng `recent_context` và `image_url` từ enriched context.

**Enriched context keys (có sẵn trong `$ctx`):**
- `$ctx['image_url']` — URL ảnh đã xử lý (từ transient nếu user gửi ảnh trước)
- `$ctx['recent_context']` — Lịch sử chat gần đây (30 phút / 100 tin nhắn) + user memory + profile

**Checklist Cross-Path Context:**

```
□ Intent Filter sử dụng $ctx['recent_context'] khi gọi AI
□ Intent Filter sử dụng $ctx['image_url'] khi xử lý ảnh
□ Pending follow-up check TRƯỚC keyword check trong filter
□ Native processing cho Zalo/Bot (không chỉ gửi link)
□ Tool helpers nhận explicit user_id (KHÔNG dùng get_current_user_id())
□ AI prompts include recent_context để hiểu ngữ cảnh hội thoại
□ Multi-turn trên Zalo hoạt động (set_site_transient → check → delete)
□ Test: Zalo → keyword → kết quả trực tiếp
□ Test: Zalo → gửi ảnh → phân tích trực tiếp
□ Test: Zalo → multi-turn (hỏi-đáp) → pending transient
□ Test: Webchat → Intent Engine xử lý qua Provider
```

---

### 7.1 ⚠️ Bắt Buộc: 3-Tầng Intent Routing (Lesson Learned v1.1)

> **Bối cảnh:** Plugin `bizcity-agent-calo` v1.0.0 gặp bug: câu hỏi "cân nặng tôi bao nhiêu"
> KHÔNG route tới plugin. Debug cho thấy `provider_profile_build: calo — (0 chars)`.
> **Root cause:** 3 lỗi cùng lúc — thiếu mode patterns, goal patterns quá hẹp, không override `get_profile_context()`.

#### Tầng 1: Mode Classifier — Hook `bizcity_mode_execution_patterns`

Message đi qua Mode Classifier trước. 6 modes: `emotion`, `reflection`, `knowledge`, `planning`, `coding`, **`execution`**.
**CHỈ `execution` mode mới đến Intent Router.**

❌ **Sai:** Không hook → "cân nặng tôi bao nhiêu" bị phân loại `knowledge` → KHÔNG đến router.

✅ **Đúng:** Plugin **BẮT BUỘC** hook execution patterns trong bootstrap:

```php
// bootstrap.php hoặc main plugin file
add_filter( 'bizcity_mode_execution_patterns', function( $patterns ) {
    $patterns[] = '/keyword_chuyên_môn_1|keyword_2|keyword_3/ui';
    $patterns[] = '/keyword_4|keyword_5/ui';
    return $patterns;
} );
```

**Nguyên tắc:**
- Mỗi keyword chuyên môn PHẢI có trong execution patterns
- Regex phải cover biến thể dấu tiếng Việt: `c[âa]n\s*n[ặa]ng`, `gi[ảa]m\s*c[âa]n`
- Không cần quá chính xác — nếu match → mode = execution → router sẽ phân loại chi tiết

#### Tầng 2: Intent Router — `get_goal_patterns()` phải RỘNG

Router dùng regex từ `get_goal_patterns()` để match message → goal. Nếu regex miss → LLM fallback (chậm, tốn token).

❌ **Sai:** Chỉ match action: "ghi bữa ăn", "chụp ảnh bữa" (5 patterns hẹp)

✅ **Đúng:** Cover 3 loại message:

| Loại | Ví dụ | Tại sao cần |
|---|---|---|
| Action trực tiếp | "ghi bữa ăn", "bốc bài tarot" | User biết chính xác |
| Câu hỏi liên quan | "cân nặng tôi bao nhiêu", "hôm nay ăn gì" | Hỏi về data thuộc domain |
| Keywords broad | "dinh dưỡng", "protein", "giảm cân", "béo" | Thuộc lĩnh vực plugin |

**Regex tips:**
```php
// ❌ Quá hẹp — chỉ match khi user gõ chính xác
'/ghi\s*bữa\s*ăn|chụp\s*ảnh\s*bữa/ui'

// ✅ Rộng — cover nhiều cách diễn đạt
'/ghi\s*b[ữứ]a|v[ừưa]\s*[aă]n|t[ôo]i\s*[aă]n|[aă]n\s*s[áa]ng|b[ữứ]a\s*s[áa]ng/ui'
```

#### Tầng 3: Profile Context — Override `get_profile_context($user_id)`

Engine gọi `get_profile_context($user_id)` cho MỌI provider registered → inject vào system prompt dưới mục `### 👤 Hồ sơ người dùng (Provider Name):`.

❌ **Sai:** Không override → base class trả `['complete'=>false, 'context'=>'', 'fallback'=>'']` → **0 chars** → AI không biết gì về user

✅ **Đúng:** Override và trả profile + today stats:

```php
public function get_profile_context( $user_id ) {
    if ( ! $user_id ) {
        return [ 'complete' => false, 'context' => '', 'fallback' => 'Hướng dẫn đăng nhập.' ];
    }

    $profile = load_user_profile( $user_id );
    $complete = has_essential_data( $profile );  // height, weight, etc.

    $ctx_parts = [];
    $ctx_parts[] = "Tên: {$profile['name']}";
    $ctx_parts[] = "Chiều cao: {$profile['height']}cm | Cân nặng: {$profile['weight']}kg";
    $ctx_parts[] = "Mục tiêu: {$profile['goal']}";
    // Stats hôm nay
    $today = get_today_stats( $user_id );
    if ( $today ) {
        $ctx_parts[] = "Hôm nay: {$today['calories']}/{$profile['target']} kcal";
    }

    if ( ! $complete ) {
        return [
            'complete' => false,
            'context'  => implode( "\n", $ctx_parts ),  // Vẫn trả partial!
            'fallback' => "Thiếu data. Hướng user đến {$url} để cập nhật.",
        ];
    }
    return [ 'complete' => true, 'context' => implode( "\n", $ctx_parts ), 'fallback' => '' ];
}
```

**Context nên chứa (theo domain):**
- Thông tin cá nhân: tên, tuổi, giới tính
- Dữ liệu chuyên môn: cân nặng, cung hoàng đạo, số bài tarot đã bốc…
- Trạng thái hiện tại: stats hôm nay, lần dùng cuối
- Link hồ sơ: để AI hướng user cập nhật nếu thiếu

#### 📋 Checklist 3-Tầng (Copy vào mỗi plugin)

```
□ Tầng 1: Mode Execution Patterns
  □ Hook bizcity_mode_execution_patterns trong bootstrap
  □ Cover TẤT CẢ keywords chuyên môn
  □ Debug: hỏi câu tổng quát → mode = execution

□ Tầng 2: Goal Patterns
  □ Cover: action + question + broad keywords
  □ Hỗ trợ biến thể dấu tiếng Việt
  □ ≥10 test messages (5 positive + 5 negative)
  □ LLM fallback tồn tại nhưng KHÔNG nên dựa vào

□ Tầng 3: Profile Context
  □ Override get_profile_context(), KHÔNG dùng default
  □ Trả: complete + context + fallback
  □ Context chứa profile + stats hiện tại
  □ Debug: provider_profile_build > 0 chars
```

### 8. 6-Layer Dual Context Chain (v3.0.0)

Context được xây dựng theo 6 lớp ưu tiên, kết hợp ngữ cảnh ngắn hạn (phiên hiện tại) + dài hạn (các phiên trước).
Lớp cao hơn override lớp thấp hơn:

```
Priority 1 (Highest): User Memory          — explicit + extracted memories (pri 99)
Priority 2:           Intent Conversation   — current sub-task: goal, slots, summary (pri 90)
Priority 3:           Webchat Session       — SHORT-TERM: recent messages in current wcs_* session (pri 90)
Priority 4:           Cross-Session         — LONG-TERM: recent session titles/summaries (pri 90)
Priority 5:           Project               — project-level context from webchat sessions (pri 90)
Priority 6 (Base):    Plugin Context        — character, profile, knowledge, providers
```

**Architecture:** Each chat session = 1 `webchat_conversation` (wcs_* session_id). 
Within each session, Intent Engine detects smaller `intent_conversations` (sub-tasks).

**Responsible class:** `BizCity_Context_Builder` (`class-context-builder.php`)

| Layer | Filter Priority | Data Source |
|:---:|:---:|---|
| 1 | 99 | `BizCity_User_Memory::inject_memory_into_system_prompt()` |
| 2,3,4,5 | 90 | `BizCity_Context_Builder::inject_context_layers()` |
| 6 | base + 45 + 50 | `prepare_llm_call()` + Pipeline + Provider |

### 9. Webchat Sessions + Projects

Sidebar hiển thị webchat sessions (thay thế intent conversations). Sessions có thể drag & drop vào projects.

**Webchat Session AJAX:**

| AJAX Action | Method | Description |
|---|---|---|
| `bizcity_webchat_sessions` | `ajax_webchat_sessions()` | List sessions for user |
| `bizcity_webchat_session_create` | `ajax_webchat_session_create()` | Create new wcs_* session |
| `bizcity_webchat_session_rename` | `ajax_webchat_session_rename()` | Rename session title |
| `bizcity_webchat_session_delete` | `ajax_webchat_session_delete()` | Delete session + messages |
| `bizcity_webchat_session_messages` | `ajax_webchat_session_messages()` | Get messages by session PK |
| `bizcity_webchat_session_move` | `ajax_webchat_session_move()` | Move session to project (drag & drop) |
| `bizcity_webchat_close_all` | `ajax_webchat_close_all()` | Archive all active sessions |

**Project AJAX:**

| AJAX Action | Method | Description |
|---|---|---|
| `bizcity_project_list` | `ajax_project_list()` | List all projects + session_count |
| `bizcity_project_create` | `ajax_project_create()` | Create project (name, icon) |
| `bizcity_project_rename` | `ajax_project_rename()` | Rename project |
| `bizcity_project_delete` | `ajax_project_delete()` | Delete project (sessions → unassigned) |

**Storage:** `user_meta` key `bizcity_projects` (JSON array) + `title`/`project_id` columns on `bizcity_webchat_conversations`.

**Auto-title:** First user message → LLM (gemini-2.0-flash-lite-001) → max 8 từ tiếng Việt → `update_session_title()`.

### 10. ⚠️ Unified Image Upload Flow (Lesson Learned v3.2)

> **Bối cảnh:** Plugin `bizcity-agent-calo` v1.0 nhận ảnh từ webchat dưới dạng base64 data URL.
> Khi gửi URL cho AI hoặc plugin khác → fail vì base64 URL không persistent, quá dài cho API.
> **Root cause:** Ảnh KHÔNG được upload lên WordPress Media Library → không có URL chuẩn.

**⚠️ QUY TẮC BẮT BUỘC:** Tất cả ảnh từ frontend (base64, blob, file upload) PHẢI được lưu vào WordPress Media Library **TRƯỚC** khi đưa vào Intent Engine hoặc plugins.

#### 10.1 Architecture: Unified Image Flow

```
Frontend (Webchat/Admin/Mobile)
    ↓ base64 / file
AJAX Handler (bizcity_webchat_send / chat_gateway_send / ...)
    ↓ bizcity_convert_images_to_media_urls()
WordPress Media Library
    ↓ https://domain/wp-content/uploads/2026/03/image.jpg
Intent Engine / Plugins
```

#### 10.2 Helper Functions (bizcity-bot-webchat/bootstrap.php)

| Function | Input | Output | Mô tả |
|----------|-------|--------|-------|
| `bizcity_upload_to_media_library($file)` | `$_FILES` array | `['url'=>..., 'attachment_id'=>...]` | Upload file từ HTML form |
| `bizcity_save_base64_to_media($base64, $filename)` | data URL string, filename | `attachment_id` hoặc `WP_Error` | Lưu base64 vào Media |
| `bizcity_convert_images_to_media_urls($images)` | array of base64/URLs | array of Media URLs | Batch convert, skip URLs đã valid |

#### 10.3 Sử dụng trong AJAX Handlers

Mỗi AJAX handler nhận `images` từ frontend PHẢI convert trước khi xử lý:

```php
// Nhận images array từ request
$images = isset( $_POST['images'] ) ? $_POST['images'] : [];

// Convert base64 → Media URL (skip nếu đã là URL valid)
if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
    $images = bizcity_convert_images_to_media_urls( $images );
}

// Đưa vào Intent Engine (images giờ là array URLs từ Media Library)
$result = BizCity_Intent_Engine::process( $message, $user_id, $session_id, [
    'images' => $images,
] );
```

#### 10.4 Plugin nhận Image URL

Plugin/Tool nhận `image_url` từ slots LUÔN là URL chuẩn từ Media Library:

```php
// ✅ ĐÚNG: Expect Media URL
$image_url = $slots['photo_url'] ?? '';  // https://domain/wp-content/uploads/...

// ❌ SAI: Không nhận base64 trực tiếp
// $image_url sẽ KHÔNG bao giờ là "data:image/jpeg;base64,..."
```

#### 10.5 Checklist Image Upload Flow

```
□ AJAX handler convert images[] trước khi process
□ Sử dụng bizcity_convert_images_to_media_urls() cho batch
□ Sử dụng bizcity_save_base64_to_media() cho single image
□ Sử dụng bizcity_upload_to_media_library() cho file upload
□ Plugin/Tool expect Media URL (https://...), không expect base64
□ Tool output trả data.image_url là URL từ Media Library
□ Cleanup: Xem xét xóa attachments không dùng (optional)
□ Test: Webchat → gửi ảnh → kiểm tra Media Library có attachment mới
□ Test: Plugin nhận image_url → fetch được ảnh → xử lý OK
□ Test: Image URL persist sau reload, hoạt động với AI APIs
```

**Already implemented in:**
- `bizcity_intent_stream` (class-intent-stream.php) — SSE streaming
- `bizcity_chat_gateway_send` (class-chat-gateway.php) — Unified chat send
- `bizcity_chat_gateway_stream` (class-chat-gateway.php) — Unified chat stream  
- `bizcity_admin_chat_send` (class-admin-chat.php) — Admin dashboard chat
---

### 11. ⚙️ tool_step Logging Standard (Phase 10.1 Execution Monitor)

> **Bắt buộc** cho mọi tool function có từ 2 bước xử lý trở lên.
> Mục đích: hiển thị tiến trình real-time trong **Working Panel** + **Execution Log Console**.

#### 11.1 Tại sao cần tool_step?

Khi 1 tool chạy nhiều bước (AI generate → create post → cross-post Facebook…), không có cách nào biết
hệ thống đang ở bước nào khi debug. `tool_step` giải quyết bằng cách log từng sub-step vào
`BizCity_Execution_Logger`, hiển thị trực tiếp trên Working Panel và Execution Log Console.

#### 11.2 API chuẩn

```php
// Tại đầu mỗi bước:
if ( class_exists( 'BizCity_Execution_Logger' ) ) {
    BizCity_Execution_Logger::log( 'tool_step', [
        'tool_name' => 'my_tool_name',   // tên tool (snake_case)
        'sub_step'  => '1/3 step_label', // format "N/Total label"
        'status'    => 'running',        // running | success | error | skipped
    ] );
}

// Khi bước thành công:
if ( class_exists( 'BizCity_Execution_Logger' ) ) {
    BizCity_Execution_Logger::log( 'tool_step', [
        'tool_name'   => 'my_tool_name',
        'sub_step'    => '1/3 step_label',
        'status'      => 'success',
        'title'       => $result_title,    // optional: tiêu đề / tên kết quả
        'post_id'     => $post_id,         // optional: WP post ID nếu có
        'url'         => get_permalink($post_id), // optional
        'content_len' => mb_strlen($content),      // optional: length nếu là content
    ] );
}

// Khi bước thất bại:
if ( class_exists( 'BizCity_Execution_Logger' ) ) {
    BizCity_Execution_Logger::log( 'tool_step', [
        'tool_name' => 'my_tool_name',
        'sub_step'  => '1/3 step_label',
        'status'    => 'error',
        'message'   => 'Mô tả lỗi ngắn gọn',
    ] );
    // Và log lỗi thêm nếu critical:
    BizCity_Execution_Logger::error( 'tool_error', 'my_tool: step_label failed', [
        'tool' => 'my_tool_name',
    ] );
}

// Khi bước bị skip (optional, không cần thiết):
BizCity_Execution_Logger::log( 'tool_step', [
    'tool_name' => 'my_tool_name',
    'sub_step'  => '2/3 step_label',
    'status'    => 'skipped',
    'message'   => 'Lý do skip',  // optional
] );
```

#### 11.3 Data Keys chuẩn

| Key | Type | Bắt buộc | Mô tả |
|-----|------|:---:|-------|
| `tool_name` | string | ✅ | Tên tool snake_case — `write_article`, `create_product`… |
| `sub_step` | string | ✅ | Format `"N/Total label"` — `"1/3 ai_generate_content"` |
| `status` | string | ✅ | `running` / `success` / `error` / `skipped` |
| `title` | string | — | Tiêu đề / tên kết quả của bước |
| `message` | string | — | Mô tả lỗi (khi `status=error`) hoặc ghi chú |
| `post_id` | int | — | WordPress post ID nếu bước tạo/cập nhật post |
| `url` | string | — | URL xem kết quả |
| `content_len` | int | — | Số ký tự nội dung được tạo ra |

#### 11.4 Quy ước sub_step naming

```
"1/3 ai_generate_content"   ← N/Total + tên chức năng chính (snake_case)
"2/3 generate_image"
"3/3 create_wp_post"

"1/1 product_post_flow"     ← single-step tool
"1/2 save_script"
"2/2 queue_generation"
```

#### 11.5 Checklist Logging cho Tool mới

```
□ Tool có ≥ 2 bước riêng biệt → bắt buộc log tool_step
□ Log 'running' trước khi gọi external function / API
□ Log 'success' với các data keys liên quan sau khi thành công
□ Log 'error' với message khi fail — luôn kèm BizCity_Execution_Logger::error()
□ Wrap trong class_exists('BizCity_Execution_Logger') guard
□ sub_step format: "N/Total label" (N = 1-based index)
□ Không log sensitive data (tokens, passwords) vào tool_step
□ Test: Gửi lệnh → Working Panel hiển thị sub-steps đúng
```

#### 11.6 Reference Implementations

Xem mẫu đầy đủ trong:
- `bizcity-intent/includes/class-intent-tools.php` → `builtin_write_article()` (3 steps)
- `bizcity-intent/includes/class-intent-tools.php` → `builtin_create_video()` (2 steps)
- `bizcity-intent/includes/class-intent-tools.php` → `builtin_create_product()` (1 step)