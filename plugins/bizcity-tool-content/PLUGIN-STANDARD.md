# BizCity Intent Plugin — Chuẩn phát triển

> Tài liệu chuẩn để xây dựng plugin tích hợp BizCity Intent Engine.
> Lấy `bizcity-tool-content` làm plugin mẫu.

> Chuẩn hợp nhất mới: xem `PLUGIN-TWIN-STANDARD.md` ở root `bizcity-twin-ai/`.
> Tài liệu này tập trung vào plugin-level implementation. Các quy chuẩn cross-module (slash/mention payload, studio/automation capability, SSE contract, gateway contract) phải tuân theo chuẩn hợp nhất.

---

## 1. Kiến trúc tổng quan

```
User chat → Router (regex match) → Planner (slot gathering)
         → Engine (call_tool) → Tool Callback (self-contained pipeline)
         → Tool Output Envelope → SSE → Frontend
```

### Luồng xử lý chi tiết

| Bước | Component | Mô tả |
|------|-----------|-------|
| 1 | **Router** | Match `goal_patterns` regex với tin nhắn user → xác định goal |
| 2 | **Planner** | Đọc `plans[goal].required_slots` → hỏi user nếu thiếu field |
| 3 | **Engine** | Khi đủ slots → gọi `tools[tool_name].callback` |
| 4 | **Tool Callback** | Thực thi pipeline nội bộ (AI → xử lý → output) |
| 5 | **Job Trace** | Track từng step, fire SSE events cho frontend |
| 6 | **Output** | Trả `Tool Output Envelope` → Engine → SSE → User |

### Nguyên tắc "Developer-packaged Pipeline"

- **Tool callback tự xử lý toàn bộ** — không cần executor / preflight bên ngoài
- **Mỗi tool = 1 pipeline khép kín** — nhận slots, xử lý N bước, trả kết quả
- **Job Trace** theo dõi từng bước → SSE real-time cho frontend

---

## 2. Cấu trúc thư mục

```
bizcity-tool-{name}/
├── bizcity-tool-{name}.php      ← Bootstrap: header, requires, register provider
├── index.php                     ← Security: <?php // Silence is golden.
├── includes/
│   ├── class-tools-{name}.php   ← Tool callbacks (static methods)
│   ├── class-job-trace.php      ← Step tracker (copy từ template, ít sửa)
│   └── index.php
├── views/
│   └── page-agent-profile.php   ← Touch Bar iframe (agent card + guided commands)
├── assets/
│   └── icon.png                 ← Agent icon (256x256, PNG)
├── PLUGIN-STANDARD.md           ← Tài liệu này
└── README.md                    ← Mô tả plugin cho marketplace
```

---

## 3. Bootstrap file (bizcity-tool-{name}.php)

### 3.1 Plugin Header

```php
<?php
/**
 * Plugin Name:       BizCity Tool — {Tên tool}
 * Description:       Mô tả ngắn 1-2 dòng.
 * Short Description: Dùng trong marketplace listing.
 * Quick View:        ✍️ Icon + mô tả siêu ngắn cho Touch Bar
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-{name}
 * Role:              agent
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Template Page:     tool-{name}
 * Category:          content, blog, ...
 * Tags:              tag1, tag2, ...
 */
```

**Các header đặc biệt BizCity:**

| Header | Mô tả |
|--------|-------|
| `Role` | Luôn = `agent` cho tool plugin |
| `Credit` | Số credit/lần gọi (0 = miễn phí) |
| `Price` | Giá mua plugin (0 = miễn phí) |
| `Icon Path` | Đường dẫn tương đối tới icon |
| `Template Page` | Slug cho rewrite rule (Touch Bar iframe) |
| `Quick View` | Dòng ngắn hiện trong Touch Bar |
| `Short Description` | Dùng trong marketplace card |

### 3.2 Constants + Requires

```php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BZTOOL_{NAME}_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_{NAME}_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_{NAME}_VERSION', '1.0.0' );
define( 'BZTOOL_{NAME}_SLUG',    'bizcity-tool-{name}' );

require_once BZTOOL_{NAME}_DIR . 'includes/class-job-trace.php';
require_once BZTOOL_{NAME}_DIR . 'includes/class-tools-{name}.php';
```

### 3.3 Register Intent Provider

```php
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-{name}',
        'name' => 'BizCity Tool — {Tên tool}',

        /* ── Goal patterns (Router) ──
         * ORDER MATTERS: specific patterns first!
         * Key = regex, Value = goal config
         */
        'patterns' => [
            '/viết lại|rewrite/ui' => [
                'goal'    => 'rewrite_article',
                'label'   => 'Viết lại bài viết',
                'extract' => [ 'post_id', 'instruction' ],
            ],
            '/viết bài|đăng bài/ui' => [
                'goal'    => 'write_article',
                'label'   => 'Viết bài đăng web',
                'extract' => [ 'topic', 'image_url' ],
            ],
        ],

        /* ── Plans (slot gathering) ── */
        'plans' => [
            'write_article' => [
                'required_slots' => [
                    'topic' => [
                        'type'   => 'text',
                        'prompt' => 'Bạn muốn viết bài về chủ đề gì?',
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [
                        'type'    => 'image',
                        'prompt'  => 'Bạn có ảnh bìa không? Gõ "bỏ qua" để AI tự tạo.',
                        'default' => '',
                    ],
                ],
                'tool'       => 'write_article',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url' ],
            ],
        ],

        /* ── Tools (callbacks) ── */
        'tools' => [
            'write_article' => [
                'label'    => 'Viết & đăng bài WordPress',
                'callback' => [ 'BizCity_Tool_{Name}', 'write_article' ],
            ],
        ],

        /* ── Context (optional) ── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: Tool {Name}\n"
                . "Mục tiêu: viết bài\n";
        },
    ] );
} );
```

### 3.4 Template Page (rewrite rule cho Touch Bar iframe)

```php
add_action( 'init', function() {
    add_rewrite_rule( '^tool-{name}/?$', 'index.php?bizcity_agent_page=tool-{name}', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-{name}' ) {
        include BZTOOL_{NAME}_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
```

---

## 4. Provider Config — Chi tiết từng section

### 4.1 `patterns` — Goal Pattern Matching

Router dùng `preg_match` lần lượt từ trên xuống. **Pattern cụ thể phải đặt trước pattern chung.**

```php
'patterns' => [
    // Cụ thể TRƯỚC
    '/viết lại|rewrite/ui'  => [ 'goal' => 'rewrite', ... ],
    '/viết bài SEO/ui'      => [ 'goal' => 'write_seo', ... ],
    // Chung SAU
    '/viết bài|đăng bài/ui' => [ 'goal' => 'write', ... ],
],
```

Mỗi pattern value:

| Key | Type | Mô tả |
|-----|------|-------|
| `goal` | string | Tên goal (map sang plans + tools) |
| `label` | string | Label hiện cho user |
| `extract` | array | Các field AI cố gắng trích xuất từ tin nhắn |

### 4.2 `plans` — Slot Gathering

Planner hỏi user từng slot theo `slot_order`. Engine auto-fill slot `message` từ raw input (nên dùng `topic` thay vì `message` cho required slot).

```php
'plans' => [
    'goal_name' => [
        'required_slots' => [
            'field_name' => [
                'type'   => 'text',        // text | choice | image
                'prompt' => 'Câu hỏi:',   // Planner sẽ hỏi user câu này
            ],
        ],
        'optional_slots' => [
            'field_name' => [
                'type'    => 'choice',
                'choices' => [ 'opt1', 'opt2' ],
                'default' => 'opt1',       // Giá trị mặc định nếu user bỏ qua
            ],
        ],
        'tool'       => 'tool_name',       // Map sang tools[tool_name]
        'ai_compose' => false,             // true = AI tự compose output
        'slot_order' => [ 'field1', 'field2' ], // Thứ tự hỏi
    ],
],
```

**Slot types:**

| Type | Mô tả | Ví dụ |
|------|--------|-------|
| `text` | Free text input | Chủ đề bài viết |
| `choice` | Chọn từ danh sách | Tone: casual / professional |
| `image` | URL ảnh hoặc upload | Ảnh bìa bài viết |

**⚠️ LƯU Ý QUAN TRỌNG:**
- Engine auto-fill `message` slot từ raw text user → **dùng `topic` cho required slot** (tránh bị auto-fill sai)
- `prompt` phải thân thiện, viết bằng tiếng Việt
- `default` cho phép user bỏ qua mà không bị block
- `slot_order` quyết định thứ tự hỏi (required trước, optional sau)

### 4.3 `tools` — Tool Registration

```php
'tools' => [
    'tool_name' => [
        'label'    => 'Mô tả tool',
        'callback' => [ 'ClassName', 'static_method' ],
    ],
],
```

**⚠️ Registry gọi `is_callable($callback)` trước khi đăng ký.** Nếu class/method chưa được require → tool không đăng ký → user nhận lỗi "tool không tìm thấy".

---

## 5. Tool Callback — Chuẩn viết

### 5.1 Signature

```php
class BizCity_Tool_{Name} {

    public static function tool_name( array $slots ): array {
        // 1. Extract _meta (ALWAYS first)
        $meta       = $slots['_meta']    ?? [];
        $ai_context = $meta['_context']  ?? '';
        // 2. Validate input
        // 3. Start Job Trace
        // 4. Execute steps (T1, T2, ...) — inject $ai_context into AI calls
        // 5. Return Tool Output Envelope
    }
}
```

### 5.2 Input — `$slots` array

Engine inject tự động:

| Key | Mô tả |
|-----|-------|
| `session_id` | ID session chat |
| `chat_id` | Telegram chat ID (nếu từ Telegram) |
| `user_id` | WordPress user ID |
| `_meta` | **Execution context** (xem 5.2b) — chứa dual context 6 lớp + metadata |
| + all required/optional slots từ plan |

### 5.2b `_meta` — Execution Context (v3.5.1)

Engine tự động inject `$slots['_meta']` cho **mọi tool callback**. Tool PHẢI extract `_meta` ngay đầu hàm.

```php
$meta       = $slots['_meta']    ?? [];
$ai_context = $meta['_context']  ?? '';
```

**Schema đầy đủ:**

| Key | Type | Mô tả |
|-----|------|-------|
| `_context` | string | 6-layer dual context (layers 2-5, max 1200 chars). Dùng append vào system prompt AI. |
| `conv_id` | string | Intent conversation UUID (sub-task hiện tại) |
| `goal` | string | Goal identifier: `create_product`, `write_article`, ... |
| `goal_label` | string | Human label: `Tạo sản phẩm`, `Viết bài`, ... |
| `character_id` | string | AI character binding |
| `channel` | string | `webchat` \| `adminchat` \| `telegram` \| `zalo` |
| `message_id` | string | ID tin nhắn gốc |
| `user_display` | string | Tên hiển thị user |
| `blog_id` | int | Multisite blog ID |

**3 Patterns sử dụng `_meta`:**

| Pattern | Khi nào | Cách inject |
|---------|---------|-------------|
| **A: OpenRouter** | Tool gọi `bizcity_openrouter_chat()` | Append `$ai_context` vào system prompt |
| **B: Legacy function** | Tool gọi `ai_generate_content()` / function cũ | Prepend context vào input text |
| **C: Data-only** | Tool query DB, không gọi AI | `_meta` có sẵn, dùng nếu cần |

**⚠️ Quy tắc:**
- LUÔN extract `$slots['_meta'] ?? []` — phòng edge case engine chưa inject
- CHỈ append `$ai_context` khi non-empty (`if ( $ai_context )` guard)
- KHÔNG sửa/xóa `_meta` khỏi `$slots`
- KHÔNG trả `_meta` trong output envelope
- `validate_inputs()` KHÔNG kiểm tra `_meta` (không nằm trong `input_fields`)

### 5.3 Output — Tool Output Envelope

```php
return [
    'success'  => true,           // bool: tool chạy thành công?
    'complete' => true,           // bool: pipeline hoàn tất?
    'message'  => '✅ Kết quả',  // string: message hiện cho user (Markdown OK)
    'data'     => [               // array: structured data cho pipeline chain
        'id'        => 123,
        'type'      => 'article',
        'title'     => 'Tiêu đề',
        'url'       => 'https://...',
        'edit_url'  => 'https://...',
        'image_url' => 'https://...',
        'platform'  => 'wordpress',
        'trace_id'  => 'jt_...',
        'meta'      => [],
    ],
];
```

**Error output:**

```php
return [
    'success'        => false,
    'complete'       => false,
    'message'        => '❌ Lỗi gì đó',
    'data'           => [],
    'missing_fields' => [ 'field_name' ],  // optional: Planner sẽ hỏi lại
];
```

**Quy tắc:**
- `message` dùng emoji + Markdown (**bold**, [link](url))
- `data.type` chuẩn hóa: `article`, `image`, `video`, `schedule`, ...
- `data.url` + `data.edit_url` luôn có khi tạo post
- `missing_fields` → Planner tự hỏi lại user (không cần xử lý thêm)

### 5.4 Job Trace Pattern

```php
// Start trace
$trace = null;
if ( class_exists( 'BizCity_Job_Trace' ) ) {
    $trace = BizCity_Job_Trace::start(
        $session_id ?: $chat_id ?: 'cli',
        'tool_name',
        [
            'T1' => 'Bước 1: Mô tả',
            'T2' => 'Bước 2: Mô tả',
            'T3' => 'Bước 3: Mô tả',
        ]
    );
}

// Each step
if ( $trace ) $trace->step( 'T1', 'running' );
// ... do work ...
if ( $trace ) $trace->step( 'T1', 'done', [ 'key' => 'value' ] );

// On failure
if ( $trace ) $trace->fail( 'Error message' );

// On success
if ( $trace ) $trace->complete( [ 'post_id' => $id ] );
```

**Step statuses:** `pending` → `running` → `done` | `failed` | `skipped`

`running` status fires `do_action('bizcity_intent_status')` → SSE → frontend typing indicator.

### 5.5 Dependency Guard Pattern

Luôn check dependencies trước khi chạy:

```php
if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
    return [
        'success' => false, 'complete' => false,
        'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.',
        'data'    => [],
    ];
}
```

### 5.6 Helper: extract_text()

Pattern chuẩn để lấy text từ nhiều nguồn input:

```php
private static function extract_text( array $slots ): string {
    foreach ( [ 'topic', 'message', 'content' ] as $key ) {
        $val = $slots[ $key ] ?? '';
        if ( is_string( $val ) && $val !== '' ) return trim( $val );
        if ( is_array( $val ) ) {
            $text = $val['text'] ?? $val['caption'] ?? '';
            if ( $text ) return trim( $text );
        }
    }
    return '';
}
```

### 5.7 Helper: find_post()

Pattern tìm bài viết từ user input linh hoạt:

```php
private static function find_post( string $ref ): ?\WP_Post {
    $ref = trim( $ref );
    if ( empty( $ref ) ) return null;

    // 1. Numeric ID
    if ( is_numeric( $ref ) ) {
        $post = get_post( (int) $ref );
        if ( $post && $post->post_type === 'post' ) return $post;
    }

    // 2. Slug
    $by_slug = get_page_by_path( $ref, OBJECT, 'post' );
    if ( $by_slug ) return $by_slug;

    // 3. Keyword search
    $found = get_posts( [
        'post_type' => 'post', 'post_status' => [ 'publish', 'draft' ],
        's' => $ref, 'posts_per_page' => 1,
    ] );
    if ( ! empty( $found ) ) return $found[0];

    // 4. "mới nhất" / "latest"
    if ( preg_match( '/mới nhất|latest|cuối/ui', $ref ) ) {
        $latest = get_posts( [ 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
        if ( ! empty( $latest ) ) return $latest[0];
    }

    return null;
}
```

### 5.8 Helper: parse_json_response()

Pattern parse JSON từ AI response (xử lý code fences, partial JSON):

```php
private static function parse_json_response( string $raw ): array {
    if ( empty( $raw ) ) return [];

    $clean = trim( $raw );
    $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
    $clean = preg_replace( '/```\s*$/', '', $clean );

    $parsed = json_decode( $clean, true );
    if ( is_array( $parsed ) ) return $parsed;

    if ( preg_match( '/\{[\s\S]*\}/', $clean, $m ) ) {
        $parsed = json_decode( $m[0], true );
        if ( is_array( $parsed ) ) return $parsed;
    }

    return [];
}
```

---

## 6. View — page-agent-profile.php

Touch Bar iframe hiện khi user click icon plugin.

### 6.1 Cấu trúc HTML

```
┌─────────────────────────┐
│  🎨 Hero Card           │  ← Icon, tên, mô tả, stats
├─────────────────────────┤
│  ⚡ Workflows            │  ← Command cards (data-msg)
│  ┌─[✍️ Viết bài blog──]┐│
│  ├─[🔍 Viết SEO───────]┤│
│  └─[📅 Lên lịch───────]┘│
├─────────────────────────┤
│  💬 Quick Tips           │  ← Gợi ý câu lệnh
│  💡 "Viết bài về..."    │
├─────────────────────────┤
│  Footer                  │
└─────────────────────────┘
```

### 6.2 JS — postMessage Pattern

```js
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var msg = this.getAttribute('data-msg');
        if (!msg) return;
        var tool = this.getAttribute('data-tool') || '';
        if (tool && msg.indexOf('/') !== 0) msg = '/' + tool + ' ' + msg;
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type:   'bizcity_agent_command',
                source: 'bizcity-tool-{name}',
                plugin_slug: 'bizcity-tool-{name}',
                tool_name: tool,
                text:   msg
            }, '*');
        }
    });
});
```

Quy tắc bắt buộc:

- Tất cả shortcut click phải tạo prompt có slash.
- Card/tip nên khai báo `data-tool` để receiver set đúng tool badge.
- Payload phải có `plugin_slug` và `tool_name`.

---

## 7. AI Integration Patterns

### 7.1 OpenRouter chat — Context-Aware (Pattern A)

```php
// Extract _meta from slots (ALWAYS do this at callback start)
$meta       = $slots['_meta']    ?? [];
$ai_context = $meta['_context']  ?? '';

// Build system prompt with context
$sys = 'Bạn là chuyên gia [domain]. Chỉ trả JSON.';
if ( $ai_context ) {
    $sys .= "\n\n" . $ai_context;
}

$result = bizcity_openrouter_chat( [
    [ 'role' => 'system', 'content' => $sys ],
    [ 'role' => 'user',   'content' => $prompt ],
], [
    'temperature' => 0.7,
    'max_tokens'  => 4000,
] );

$raw = $result['content'] ?? '';
$parsed = self::parse_json_response( $raw );
```

### 7.2 Legacy AI Function — Context-Aware (Pattern B)

```php
// Prepend context so legacy function writes in-context
$ai_input = $text;
if ( $ai_context ) {
    $ai_input = "[Ngữ cảnh hội thoại]\n{$ai_context}\n\n[Yêu cầu]\n{$text}";
}
$fields = ai_generate_content( $ai_input );
```

### 7.3 AI Prompt chuẩn

- System message: định vai + format output (JSON) + **append `$ai_context` từ `_meta`**
- User message: context + yêu cầu + format mẫu
- Luôn yêu cầu JSON response → dễ parse
- Luôn có fallback khi AI trả sai format
- **`$ai_context` giúp AI hiểu**: user đang nói gì, mục tiêu gì, sub-task nào, project nào

---

## 8. Checklist tạo plugin mới

```
□ Tạo thư mục bizcity-tool-{name}/
□ Viết bootstrap file với header chuẩn
□ Define constants + require files
□ Đăng ký provider qua bizcity_intent_register_plugin()
□ Viết goal patterns (regex tiếng Việt + English)
□ Viết plans (required_slots dùng 'topic' thay 'message')
□ Viết tool callbacks (static methods, return Envelope)
□ Extract $slots['_meta'] ?? [] và $meta['_context'] ?? '' trong mỗi callback
□ Inject $ai_context vào system prompt nếu tool gọi AI (Pattern A hoặc B)
□ Tích hợp Job Trace cho mỗi callback
□ Copy class-job-trace.php từ template
□ Tạo page-agent-profile.php (Touch Bar view)
□ Tạo rewrite rule cho template page
□ Thêm icon 256x256 PNG
□ Test: activate → nói trigger phrase → slot gathering → tool execute
□ Verify: is_callable check pass cho tất cả callbacks
□ Verify: mỗi required slot có prompt tiếng Việt thân thiện
```

---

## 9. File inventory — bizcity-tool-content (plugin mẫu)

| File | Dòng | Mục đích |
|------|------|----------|
| `bizcity-tool-content.php` | ~200 | Bootstrap: header, constants, provider config, rewrite rule |
| `includes/class-tools-content.php` | ~880 | 5 tool callbacks + 3 helpers |
| `includes/class-job-trace.php` | ~280 | Step tracker (copy cho plugin khác) |
| `views/page-agent-profile.php` | ~300 | Touch Bar iframe view |
| `assets/icon.png` | — | Agent icon |
| `index.php` | 1 | Security silence |

### Tool callbacks:

| Method | Goal | Steps | Mô tả |
|--------|------|-------|--------|
| `write_article()` | write_article | T1→T2→T3 | AI viết → ảnh bìa → đăng WP |
| `write_seo_article()` | write_seo_article | T1→T2→T3 | AI viết SEO → ảnh → đăng + meta |
| `rewrite_article()` | rewrite_article | T1→T2→T3 | Tìm bài → AI viết lại → update |
| `translate_and_publish()` | translate_and_publish | T1→T2→T3 | Tìm bài → AI dịch → đăng mới |
| `schedule_post()` | schedule_post | T1→T2 | Parse request → lên lịch |

### Helpers (private):

| Method | Mục đích |
|--------|----------|
| `extract_text()` | Normalize text input từ nhiều nguồn |
| `find_post()` | Tìm post bằng ID / slug / keyword / "mới nhất" |
| `parse_json_response()` | Parse JSON từ AI response (xử lý fences) |

---

## 10. Quy tắc đặt tên

| Item | Convention | Ví dụ |
|------|-----------|-------|
| Plugin folder | `bizcity-tool-{name}` | `bizcity-tool-content` |
| Bootstrap file | `bizcity-tool-{name}.php` | `bizcity-tool-content.php` |
| Tool class | `BizCity_Tool_{Name}` | `BizCity_Tool_Content` |
| Constant prefix | `BZTOOL_{NAME}_` | `BZTOOL_CONTENT_DIR` |
| Provider ID | `tool-{name}` | `tool-content` |
| Template slug | `tool-{name}` | `tool-content` |
| Goal names | snake_case, verb_noun | `write_article`, `schedule_post` |
| Tool names | = goal names | `write_article` |
| Step IDs | `T1`, `T2`, `T3`... | Sequential |
| Trace ID | `jt_{tool}_{hash}` | `jt_write_article_a1b2c3d4e5` |

---

## 11. Dependencies

| Package | Vai trò | Required? |
|---------|---------|-----------|
| `bizcity-intent` (mu-plugin) | Intent Engine + Router + Planner | ✅ Required |
| `bizcity-openrouter` (mu-plugin) | AI chat API | ✅ For AI tools |
| `bizcity-admin-hook` (mu-plugin) | Helper functions (twf_*) | ⚠️ Optional |
| WordPress ≥ 6.3 | Core | ✅ Required |
| PHP ≥ 7.4 | Runtime | ✅ Required |

### Helper functions từ bizcity-admin-hook:

| Function | Mô tả |
|----------|-------|
| `ai_generate_content( $text )` | Legacy AI viết bài (GPT) |
| `twf_wp_create_post( $title, $content, $image_url )` | Tạo post + featured image + Facebook |
| `twf_generate_image_url( $title )` | AI tạo ảnh từ tiêu đề |
| `twf_generate_title_from_content( $content )` | AI tạo title từ content |
| `twf_clean_post_content( $html )` | Sanitize HTML content |
| `twf_parse_post_fields_from_ai( $text )` | Parse title/content từ AI text |
| `twf_parse_schedule_post_ai( $text )` | Parse schedule request từ text |
| `twf_list_latest_posts()` | Lấy danh sách posts gần nhất |
| `twf_search_posts_with_keyword( $kw )` | Tìm posts theo keyword |
