# BizCity Plugin Standard v2.0 — Chuẩn Phát Triển AI Tool Plugin

> **Mục tiêu**: Chuẩn kiến trúc cho 100.000+ AI Tool plugins.
> Mỗi plugin = 1 **AI Agent chuyên biệt** trong nền tảng Agentic.
> Active plugin → Agent available. Deactivate → Agent removed.
> **Zero Core Touch. Infinite Scalability.**

> **NEW (v2.1)**: Quy chuẩn hợp nhất cho Twin Core ở file `PLUGIN-TWIN-STANDARD.md`.
> File này vẫn là chuẩn intent chi tiết, còn các yêu cầu cross-domain (shortcut slash/mention, SSE, studio/automation/gateway contract, loader metadata) dùng theo chuẩn hợp nhất.

---

## Mục lục

1. [Kiến trúc 3 trụ cột](#1-kiến-trúc-3-trụ-cột)
2. [Cấu trúc thư mục chuẩn](#2-cấu-trúc-thư-mục-chuẩn)
3. [Trụ cột 1 — Profile View](#3-trụ-cột-1--profile-view)
4. [Trụ cột 2 — Intent Provider & Primary Tool](#4-trụ-cột-2--intent-provider--primary-tool)
5. [Trụ cột 3 — Secondary Tools & Routing](#5-trụ-cột-3--secondary-tools--routing)
6. [Bootstrap — Main Plugin File](#6-bootstrap--main-plugin-file)
7. [Tool Schema & I/O Contract](#7-tool-schema--io-contract)
8. [Goal Patterns — Needles](#8-goal-patterns--needles)
9. [Plans — Slot Gathering](#9-plans--slot-gathering)
10. [Context & System Instructions](#10-context--system-instructions)
11. [Data & Custom Post Type](#11-data--custom-post-type)
12. [Admin Menu](#12-admin-menu)
13. [Chat Integration (Zalo/Bot)](#13-chat-integration-zalobot)
14. [Knowledge Binding (RAG)](#14-knowledge-binding-rag)
15. [Naming Convention](#15-naming-convention)
16. [Tool Trace — Báo tiến độ thực thi](#16-tool-trace--báo-tiến-độ-thực-thi)
17. [Automation Bridge — Workflow Pipeline](#17-automation-bridge--workflow-pipeline)
18. [Scaffold Checklist](#18-scaffold-checklist)
19. [Quick Shortcut Slash Contract](#19-quick-shortcut-slash-contract)

---

## 1. Kiến trúc 3 trụ cột

Mọi BizCity AI Tool plugin đều xây trên **3 trụ cột** giống nhau:

```
┌─────────────────────────────────────────────────────────────────────┐
│                        BizCity AI Tool Plugin                       │
│                                                                     │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────┐ │
│  │  PILLAR 1        │  │  PILLAR 2        │  │  PILLAR 3           │ │
│  │  Profile View    │  │  Primary Tool    │  │  Secondary Tools    │ │
│  │                  │  │                  │  │                     │ │
│  │  /{slug}/        │  │  Intent Engine   │  │  Auto: Registry     │ │
│  │  Hero + Prompt   │  │  Ưu tiên #1     │  │  Manual: /command   │ │
│  │  Guided Commands │  │  khi chat qua    │  │  hoặc regex match   │ │
│  │  → postMessage() │  │  intent engine   │  │                     │ │
│  └────────┬─────────┘  └────────┬─────────┘  └──────────┬──────────┘ │
│           │                     │                        │           │
│           └─────────────────────┼────────────────────────┘           │
│                                 ▼                                    │
│                     ┌─────────────────────┐                         │
│                     │   Intent Engine     │                         │
│                     │   (bizcity-intent)  │                         │
│                     │                     │                         │
│                     │   Router → Planner  │                         │
│                     │   → Tools → Result  │                         │
│                     └─────────────────────┘                         │
└─────────────────────────────────────────────────────────────────────┘
```

### 3 trụ cột giải thích

| # | Trụ cột | Vai trò | Ví dụ |
|---|---------|---------|-------|
| **1** | **Profile View** | Trang frontend `/{slug}/` — hero, prompt input (text + ảnh), guided command buttons. Khi user click → `postMessage()` gửi prompt vào chat | `/tool-woo/` hiện 9 workflow cards, click "Tạo sản phẩm" → gửi prompt vào chat |
| **2** | **Primary Tool** | Tool CHÍNH trong intent provider — luôn ưu tiên khi user chat qua intent engine. 1 plugin = 1 primary goal | `create_product` là primary tool của tool-woo. "Tạo sản phẩm" → luôn route vào đây |
| **3** | **Secondary Tools** | Các tool PHỤ — kích hoạt bằng 2 cách: **(a) Auto** — intent engine match regex rồi route tự động; **(b) Manual** — user gõ `/edit_product` hoặc chọn từ guided commands | `edit_product`, `order_stats` là secondary tools của tool-woo |

### Nguyên tắc cốt lõi

| Nguyên tắc | Mô tả |
|------------|-------|
| **Zero Core Touch** | Plugin mới KHÔNG sửa bất kỳ file nào trong bizcity-intent |
| **Self-contained** | Mỗi Agent tự quản lý: DB, Admin, Assets, Profile View, Intent Provider |
| **Plug-and-Play** | Active → AI Agent ready. Deactivate → Agent removed |
| **Single Primary** | Mỗi plugin có đúng 1 primary tool — goal mặc định khi user nhắc đến plugin |
| **Schema Required** | Mọi tool PHẢI có `schema` đầy đủ: `description` + `input_fields` |
| **Convention over Config** | Tuân theo naming convention, cấu trúc thư mục chuẩn |

---

## 2. Cấu trúc thư mục chuẩn

```
bizcity-{slug}/
├── bizcity-{slug}.php              ← Main bootstrap + Intent Provider registration
├── index.php                        ← Security: "Silence is golden"
├── README.md                        ← Plugin documentation
├── INTENT-SKELETON.md               ← Đặc tả skill (từ PLUGIN-SKELETON-TEMPLATE.md)
│
├── assets/                          ← CSS, JS, images
│   ├── index.php
│   ├── icon.png                     ← Plugin icon (hiện trên Touch Bar)
│   ├── admin.css                    ← Admin dashboard styles (optional)
│   ├── admin.js                     ← Admin dashboard scripts (optional)
│   ├── public.css                   ← Frontend/shortcode styles (optional)
│   └── public.js                    ← Frontend/shortcode scripts (optional)
│
├── includes/                        ← PHP logic files
│   ├── index.php
│   ├── class-tools-{slug}.php       ← Tool callbacks (static methods)
│   ├── class-post-type.php          ← CPT registration (optional — thay thế install.php)
│   ├── admin-menu.php               ← WP Admin menu (optional)
│   ├── ajax.php                     ← AJAX handlers (optional — cho SPA plugins)
│   └── integration-chat.php         ← Chat gateway hooks (optional — cho Zalo/Bot)
│
└── views/                           ← Template files
    ├── index.php
    └── page-agent-profile.php       ← Profile View page (PILLAR 1)
```

> **Minimal plugin** chỉ cần 3 files: `bizcity-{slug}.php` + `includes/class-tools-{slug}.php` + `views/page-agent-profile.php`

---

## 3. Trụ cột 1 — Profile View

Profile View là trang frontend `/{slug}/` — hiển thị trong Touch Bar iframe.
Đây là **cổng vào** để user tương tác với plugin qua chat.

### 3.1. Route Registration

```php
/* Trong bootstrap file (bizcity-{slug}.php) */

add_action( 'init', function() {
    add_rewrite_rule( '^{slug}/?$', 'index.php?bizcity_agent_page={slug}', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === '{slug}' ) {
        include BZTOOL_{PREFIX}_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
```

### 3.2. Profile View Layout (3 phần chuẩn)

File: `views/page-agent-profile.php`

```
┌───────────────────────────────────────┐
│          HERO SECTION                 │  ← Gradient card, icon, tên, stats
│  [Icon] Plugin Name                   │
│  📊 Stat 1  |  📊 Stat 2  |  📊 Stat 3│
└───────────────────────────────────────┘

┌───────────────────────────────────────┐
│         GUIDED COMMANDS               │  ← Cards cho từng workflow
│                                       │
│  [✍️] Primary Tool Label    ← PRIMARY │  ← Click → postMessage primary tool
│  [📝] Secondary Tool 1               │  ← Click → postMessage secondary tool
│  [🔄] Secondary Tool 2               │  ← Click → postMessage secondary tool
│  ...                                  │
└───────────────────────────────────────┘

┌───────────────────────────────────────┐
│         QUICK TIPS                    │  ← Gợi ý prompt mẫu
│  💡 "Tạo sản phẩm áo thun 200k"     │  ← Click → postMessage text
│  💡 "Xem doanh thu 7 ngày"           │
└───────────────────────────────────────┘
```

### 3.3. Guided Command Data (PHP Array)

Mỗi workflow = 1 command card. Click gửi prompt vào parent chat:

```php
<?php
$commands = [
    /* ── PRIMARY TOOL đứng đầu, render nổi bật ── */
    [
        'icon'    => '🛒',
        'label'   => 'Tạo sản phẩm',
        'desc'    => 'AI phân tích mô tả → tạo SP + ảnh → đăng WooCommerce',
        'msg'     => 'Tạo sản phẩm áo thun organic giá 295k',
        'tags'    => [ 'AI tạo', 'Upload ảnh', 'WooCommerce' ],
        'primary' => true,
    ],
    /* ── SECONDARY TOOLS tiếp theo ── */
    [
        'icon'  => '✏️',
        'label' => 'Sửa sản phẩm',
        'desc'  => 'Cập nhật tên, giá, mô tả, ảnh sản phẩm đã có',
        'msg'   => 'Sửa giá sản phẩm #123 thành 200k',
        'tags'  => [ 'Edit', 'WooCommerce' ],
    ],
    [
        'icon'  => '📦',
        'label' => 'Tạo đơn hàng',
        'desc'  => 'AI tạo đơn từ mô tả tự nhiên: khách, SP, SĐT',
        'msg'   => 'Tạo đơn hàng cho chị Lan, 2 áo thun, SĐT 0901234567',
        'tags'  => [ 'Đơn hàng', 'Billing' ],
    ],
    // ... more secondary tools
];
?>
```

### 3.4. Render HTML (Chuẩn CSS Classes)

```html
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $plugin_name ); ?></title>
    <style>/* Xem §3.6 CSS Design System */</style>
</head>
<body>
<div class="tc-profile">

    <!-- ── HERO ── -->
    <div class="tc-hero">
        <img class="tc-hero-icon"
             src="<?php echo esc_url( BZTOOL_{PREFIX}_URL . 'assets/icon.png' ); ?>"
             width="72" height="72" alt="">
        <h1 class="tc-hero-name"><?php echo esc_html( $plugin_name ); ?></h1>
        <div class="tc-hero-stats">
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_1; ?></span>
                <span class="tc-stat-lbl">Label 1</span>
            </div>
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_2; ?></span>
                <span class="tc-stat-lbl">Label 2</span>
            </div>
            <div class="tc-stat">
                <span class="tc-stat-val"><?php echo (int) $stat_3; ?></span>
                <span class="tc-stat-lbl">Label 3</span>
            </div>
        </div>
    </div>

    <!-- ── GUIDED COMMANDS ── -->
    <h2 class="tc-section">🚀 Bắt đầu</h2>

    <?php foreach ( $commands as $cmd ) : ?>
    <div class="tc-cmd<?php echo ! empty( $cmd['primary'] ) ? ' tc-cmd-primary' : ''; ?>"
         data-msg="<?php echo esc_attr( $cmd['msg'] ); ?>">
        <div class="tc-cmd-icon"><?php echo $cmd['icon']; ?></div>
        <div class="tc-cmd-body">
            <div class="tc-cmd-label"><?php echo esc_html( $cmd['label'] ); ?></div>
            <div class="tc-cmd-desc"><?php echo esc_html( $cmd['desc'] ); ?></div>
            <div class="tc-cmd-tags">
                <?php foreach ( $cmd['tags'] as $tag ) : ?>
                <span class="tc-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ── QUICK TIPS ── -->
    <h2 class="tc-section">💡 Mẹo</h2>
    <?php foreach ( $tips as $tip ) : ?>
    <div class="tc-tip" data-msg="<?php echo esc_attr( $tip['msg'] ); ?>">
        <span class="tc-tip-icon"><?php echo $tip['icon']; ?></span>
        <span class="tc-tip-text"><?php echo esc_html( $tip['text'] ); ?></span>
    </div>
    <?php endforeach; ?>

</div>

<!-- ── POSTMESSAGE TO PARENT ── -->
<script>
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var msg = this.getAttribute('data-msg');

        /* Visual feedback */
        this.style.transform = 'scale(0.96)';
        this.style.opacity = '0.7';
        var self = this;
        setTimeout(function() { self.style.transform = ''; self.style.opacity = ''; }, 300);

        /* Send to parent (Dashboard / Webchat) */
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type:   'bizcity_agent_command',
                source: 'bizcity-{slug}',
                text:   msg
            }, '*');
        }
    });
});
</script>
</body>
</html>
```

### 3.5. Biến thể Profile View

| Type | Mô tả | Plugins | Khi nào dùng |
|------|--------|---------|-------------|
| **Type A: Guided Commands** | Hero + command cards → postMessage | tool-content, tool-woo | **Chuẩn mặc định** — đa số plugins dùng pattern này |
| **Type B: Full SPA** | Bottom nav tabs: Create + Monitor + Chat + Settings | video-kling, tool-mindmap | Plugin có async processing, cần form trực tiếp + history |
| **Type C: Shortcode** | `do_shortcode()` trong profile page | agent-calo, chatgpt-knowledge, gemini-knowledge | Plugin có frontend UI phức tạp (table, charts) |

> Khi tạo plugin mới, **luôn bắt đầu bằng Type A** (Guided Commands). Chuyển sang Type B khi plugin có **async processing** (tạo video, sinh ảnh, crawl dữ liệu…) cần form trực tiếp + lịch sử.

### 3.6. Type B BẮT BUỘC 4 Tab

Mọi plugin Type B **PHẢI** có 4 tabs tiêu chuẩn:

```
┌─────────────────────────────────────────────────────────────┐
│  TAB 1: TẠO (Create)  │  Trang thao tác chính             │
│  ─────────────────────────────────────────────────────────  │
│  [Hero] Tên plugin + stats                                  │
│  [Form] Input fields (text/image/params)                    │
│  [Submit] AJAX → reuse Tool Callback                        │
│  [Result] Kết quả ngay lập tức                              │
├─────────────────────────────────────────────────────────────┤
│  TAB 2: MONITOR (Lịch sử)  │  Live console + job tracking  │
│  ─────────────────────────────────────────────────────────  │
│  [Stats Bar] Tổng / Hoàn thành / Đang chạy                 │
│  [Console] Live log (auto-poll)                             │
│  [Job List] Cards + Pipeline Steps + Action Buttons         │
├─────────────────────────────────────────────────────────────┤
│  TAB 3: CHAT (Guided Commands)  │  postMessage → parent    │
│  ─────────────────────────────────────────────────────────  │
│  [Command Cards] Primary + Secondary tools                  │
│  [Quick Tips] Gợi ý prompt mẫu                             │
├─────────────────────────────────────────────────────────────┤
│  TAB 4: CÀI ĐẶT (Settings)  │  API + defaults             │
│  ─────────────────────────────────────────────────────────  │
│  [Admin Fields] API Key, Endpoint (admin-only)              │
│  [User Fields] Default params (all users)                   │
│  [Save] AJAX save → wp_options                              │
├─────────────────────────────────────────────────────────────┤
│  [BOTTOM NAV]  🎬 Tạo  |  📊 Monitor  |  💬 Chat  |  ⚙ Cài đặt  │
└─────────────────────────────────────────────────────────────┘
```

**Tại sao BẮT BUỘC?**
- **Create tab** cho phép user thao tác trực tiếp KHÔNG cần qua chat — nhanh hơn cho power users
- **Monitor tab** đảm bảo user luôn thấy lịch sử thực thi từ CŨNG NHƯ `/chat/` — mọi job dù tạo từ chat hay profile đều hiện ở đây
- **Chat tab** giữ tương thích ngược với Type A (guided commands → postMessage)
- **Settings tab** cho phép user tùy chỉnh, admin quản lý API keys

### 3.7. Tab 1: Create (Trang Thao Tác)

Form trực tiếp để user tạo request KHÔNG CẦN qua chat:

```
┌───────────────────────────────────────┐
│          HERO SECTION                 │  ← Stats: total / done / active
├───────────────────────────────────────┤
│  📷 Upload zone (drag & drop)        │  ← type=file → AJAX upload → WP Media
│                                       │
│  📝 Prompt textarea                   │  ← Mô tả yêu cầu
│                                       │
│  ⚙ Parameter pills / selects         │  ← Choices từ tool schema
│  [5s] [10s] [15s] [20s] [30s]        │     (match plan.required_slots)
│  [Dọc TikTok] [Ngang YouTube]        │
│                                       │
│  🚀 [ Submit Button ]                │  ← AJAX → reuse Tool Callback
│                                       │
│  ✅ Result Card (show after submit)   │  ← Job ID + thông báo xếp hàng
├───────────────────────────────────────┤
│  💡 Quick tips                        │  ← Gợi ý prompt hay
└───────────────────────────────────────┘
```

**Quy tắc Create Tab:**

| Quy tắc | Chi tiết |
|---------|---------|
| **Reuse Tool Callback** | AJAX handler gọi lại `BizCity_Tool_{Name}::primary_tool($slots, $context)` — CÙNG callback với intent engine |
| **Context session** | `session_id = 'profile_direct_' . $user_id` — phân biệt origin |
| **Photo upload** | AJAX → `media_handle_upload()` → trả về URL → dùng làm slot `image_url` |
| **Parameter pills** | Map 1:1 với `plan.required_slots` — type `choice` → pills, type `text` → textarea |
| **Nonce verify** | `check_ajax_referer( '{prefix}_nonce', 'nonce' )` mọi AJAX |

**AJAX Handler Pattern:**

```php
/* includes/class-ajax-{slug}.php */
public static function handle_create() {
    check_ajax_referer( '{prefix}_nonce', 'nonce' );
    $user_id = get_current_user_id();
    if ( ! $user_id ) wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );

    $slots = [
        'message'   => sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ),
        'image_url' => esc_url_raw( $_POST['image_url'] ?? '' ),
        // ... map từ plan.required_slots
    ];

    $context = [
        'user_id'    => $user_id,
        'session_id' => 'profile_direct_' . $user_id,
        'chat_id'    => '',
        'conversation_id' => '',
    ];

    // Reuse CÙNG tool callback dùng bởi Intent Engine
    $result = BizCity_Tool_{Name}::primary_tool( $slots, $context );

    if ( ! empty( $result['success'] ) ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}
```

### 3.8. Tab 2: Monitor (Lịch sử + Live Console)

Trang giám sát **BẮT BUỘC** — hiển thị TẤT CẢ jobs, kể cả jobs tạo từ `/chat/`:

```
┌───────────────────────────────────────┐
│  📊 Monitor Hero                      │
├───────────────────────────────────────┤
│  [🎬 12] [✅ 8] [⏳ 2 đang chạy]     │  ← Stats badges
│                               [🔄 Làm mới]
├───────────────────────────────────────┤
│  > [14:30:05] Monitor ready. ON       │  ← Live console (dark terminal)
│  > [14:30:15] Job #45: processing 30% │
│  > [14:30:25] Job #45: COMPLETED!     │
│  > [14:30:26] Job #45: Media uploaded │
├───────────────────────────────────────┤
│  ┌─────────────────────────────────┐  │
│  │ [Hoàn thành] [Kling 2.6 Pro]   │  │  ← Job card: status + model badge
│  │ Cô gái đi dạo bãi biển...      │  │  ← Prompt (truncated 100 chars)
│  │ ⏱ 10s  📐 9:16  #45            │  │  ← Meta row
│  │ ✅ Submitted → ✅ API → ✅ Done │  │  ← Pipeline steps (checkpoints)
│  │ [✅ Media] [🔗 Copy] [▶ Xem]   │  │  ← Action buttons
│  └─────────────────────────────────┘  │
│  ┌─────────────────────────────────┐  │
│  │ [Đang xử lý] [SeeDance]        │  │
│  │ Video quảng cáo thời trang...   │  │
│  │ ⏱ 15s  📐 16:9  #44            │  │
│  │ ✅ Submitted → ⏳ API Processing │  │  ← Active step has pulse animation
│  │ [████████░░░░░░░ 60%]           │  │  ← Progress bar
│  └─────────────────────────────────┘  │
└───────────────────────────────────────┘
```

**Quy tắc Monitor Tab:**

| Component | BẮT BUỘC | Chi tiết |
|-----------|----------|---------|
| **Stats Bar** | ✅ | Tổng / Hoàn thành / Đang chạy — update mỗi lần poll |
| **Live Console** | ✅ | Dark terminal (`background:#0f172a`), monospace font, max 50 dòng, auto-scroll |
| **Job List** | ✅ | Cards cho **20 jobs gần nhất**, ordered DESC by `created_at` |
| **Pipeline Steps** | ✅ | Dùng `checkpoints` JSON — hiện từng bước đã qua (✅ done / ⏳ active / ⭕ pending) |
| **Action Buttons** | ✅ | Cho completed jobs: Upload Media / Copy Link / View |
| **Auto-poll** | ✅ | `setInterval(pollJobs, 10000)` khi có active jobs, tắt khi không |
| **Change Detection** | ✅ | Track `prevJobStates`, log status transitions vào console |
| **PHP Initial Render** | ✅ | **Server-side render ĐẦY ĐỦ** — pipeline steps + buttons + model badge — KHÔNG đợi JS AJAX |

**AJAX Poll Handler Pattern:**

```php
/* includes/class-ajax-{slug}.php */
public static function handle_poll_jobs() {
    check_ajax_referer( '{prefix}_nonce', 'nonce' );
    $user_id = get_current_user_id();

    $jobs = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, prompt, status, progress, video_url, media_url,
                model, duration, aspect_ratio, checkpoints, error_message,
                created_at
         FROM {$table} WHERE created_by = %d
         ORDER BY created_at DESC LIMIT 20",
        $user_id
    ), ARRAY_A );

    // Parse checkpoints JSON
    foreach ( $jobs as &$j ) {
        $j['checkpoints'] = ! empty( $j['checkpoints'] )
            ? json_decode( $j['checkpoints'], true ) : [];
    }

    $stats = [ 'total' => $total, 'done' => $done, 'active' => $active ];
    wp_send_json_success( [ 'jobs' => $jobs, 'stats' => $stats ] );
}
```

**Pipeline Steps Pattern (JS `renderJobs`):**

```javascript
function pipeStep(label, state) {
    if (state === 'active') return '<span class="bvk-step active">⏳ ' + label + '</span>';
    if (state)              return '<span class="bvk-step done">✅ ' + label + '</span>';
    return '<span class="bvk-step">⭕ ' + label + '</span>';
}

// Mỗi plugin define pipeline riêng dựa theo checkpoints
html += pipeStep('Submitted', true);
html += pipeStep('API Processing', job.status === 'processing' ? 'active' : cp.video_completed);
html += pipeStep('Video Fetched', cp.video_completed || cp.video_fetched);
html += pipeStep('Media Upload',  cp.video_fetched || !!job.media_url);
html += pipeStep('Done', job.status === 'completed');
```

### 3.9. Tab 3: Chat (Guided Commands)

Tab chat **giữ nguyên chuẩn Type A** — command cards + quick tips → `postMessage()`:

```
┌───────────────────────────────────────┐
│  💬 Gửi lệnh qua Chat                │
├───────────────────────────────────────┤
│  [🎬] Tạo video từ ảnh     ← PRIMARY │  ← Click → postMessage primary tool
│  [📊] Kiểm tra trạng thái            │  ← Click → postMessage secondary
│  [📹] Xem danh sách video            │  ← Click → postMessage secondary
├───────────────────────────────────────┤
│  💡 "Video cô gái đi dạo bãi biển"  │  ← Click → postMessage text
│  💡 "Quảng cáo thời trang, 15s"     │
└───────────────────────────────────────┘
```

**Quy tắc**: Cùng `data-msg` + `postMessage({ type: 'bizcity_agent_command' })` như Type A.

### 3.10. Tab 4: Settings (Cài đặt)

```
┌───────────────────────────────────────┐
│  ⚙ Cài đặt                           │
├───────────────────────────────────────┤
│  🔑 API Configuration (admin only)   │
│  [API Key: ••••••••••••]              │
│  [Endpoint: https://api.example.com]  │
├───────────────────────────────────────┤
│  🎛 Mặc định tạo {resource}          │
│  [Model: ▾ Kling 2.6 Pro]            │
│  [Duration: (5s) (10s) (15s)]         │
│  [Ratio: (Dọc) (Ngang) (Vuông)]      │
├───────────────────────────────────────┤
│  💾 [ Lưu cài đặt ]                  │
├───────────────────────────────────────┤
│  ℹ Plugin: v1.0.0 | Gateway | Engine │
└───────────────────────────────────────┘
```

**Quy tắc Settings Tab:**

| Quy tắc | Chi tiết |
|---------|---------|
| **Admin fields** | `current_user_can('manage_options')` guard — API keys, endpoints |
| **User fields** | Default params — model, duration, ratio — mọi logged-in user |
| **Allowlists** | Validate against allowed arrays: `in_array($value, $allowed, true)` |
| **Save AJAX** | `update_option()` cho từng field, nonce + capability check |
| **Info block** | Hiển thị version, gateway, engine, stats |

**AJAX Save Pattern:**

```php
public static function handle_save_settings() {
    check_ajax_referer( '{prefix}_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error();

    // Admin-only fields
    if ( current_user_can( 'manage_options' ) ) {
        if ( isset( $_POST['api_key'] ) ) {
            update_option( '{prefix}_api_key', sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
        }
    }

    // User fields — validate against allowlist
    $allowed_models = [ 'model_a', 'model_b', 'model_c' ];
    if ( isset( $_POST['default_model'] ) && in_array( $_POST['default_model'], $allowed_models, true ) ) {
        update_option( '{prefix}_default_model', sanitize_text_field( $_POST['default_model'] ) );
    }

    wp_send_json_success( [ 'message' => 'Đã lưu cài đặt.' ] );
}
```

### 3.11. Async Notification — Webchat Push (BẮT BUỘC cho Type B)

Khi plugin có **async processing** (cron poll), kết quả **PHẢI** được push vào `bizcity_webchat_messages` để:
- Frontend `bizcity_webchat_session_poll` tự động nhận qua `since_id`
- User thấy kết quả trong chat KHÔNG CẦN mở profile

**Pattern:**

```php
/* includes/class-cron-{slug}.php — khi job hoàn thành */
public static function notify_user_chat( $job_id, $status ) {
    $job  = self::get_job( $job_id );
    $meta = json_decode( $job->metadata, true ) ?: [];

    $session_id      = $meta['session_id'] ?? '';
    $conversation_id = $meta['conversation_id'] ?? '';

    $msg = ( $status === 'completed' )
        ? "✅ **Hoàn thành!**\n{$result_summary}\n▶️ {$result_url}"
        : "❌ **Lỗi:** {$error_message}";

    // ── Direct insert vào webchat_messages ──
    // KHÔNG dùng Chat Gateway get_ai_response() (tốn LLM call, rewrite message)
    if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
        BizCity_WebChat_Database::instance()->log_message( [
            'session_id'             => $session_id,
            'user_id'                => 0,           // bot message
            'client_name'            => 'AI Assistant',
            'message_id'             => '{prefix}_notify_' . $job_id . '_' . time(),
            'message_text'           => $msg,
            'message_from'           => 'bot',
            'message_type'           => 'text',
            'platform_type'          => 'WEBCHAT',
            'plugin_slug'            => 'bizcity-{slug}',
            'tool_name'              => '{primary_tool}',
            'intent_conversation_id' => $conversation_id,
            'meta'                   => [ 'job_id' => $job_id, 'status' => $status ],
        ] );
    }
}
```

**Tại sao KHÔNG dùng `get_ai_response()`?**

| | `log_message()` (✅ đúng) | `get_ai_response()` (❌ sai) |
|--|--------------------------|----------------------------|
| LLM call | Không — insert trực tiếp | Có — tốn token + latency |
| Message content | Giữ nguyên, chính xác | LLM rewrite, có thể sai |
| since_id poll | ✅ Frontend nhận ngay | ❌ Không đảm bảo insert vào đúng session |
| Cost | Free | Token cost mỗi notification |

### 3.12. Bottom Navigation Bar (Chuẩn CSS)

```html
<nav class="bvk-nav">
    <a href="?tab=create" class="bvk-nav-item active" data-tab="create">
        <span class="bvk-nav-icon">🎬</span><span>Tạo</span>
    </a>
    <a href="?tab=monitor" class="bvk-nav-item" data-tab="monitor">
        <span class="bvk-nav-icon">📊</span><span>Monitor</span>
    </a>
    <a href="?tab=chat" class="bvk-nav-item" data-tab="chat">
        <span class="bvk-nav-icon">💬</span><span>Chat</span>
    </a>
    <a href="?tab=settings" class="bvk-nav-item" data-tab="settings">
        <span class="bvk-nav-icon">⚙</span><span>Cài đặt</span>
    </a>
</nav>
```

```css
/* ── Bottom Nav ── */
.bvk-nav {
    position: fixed; bottom: 0; left: 0; right: 0;
    display: flex; background: #fff; border-top: 1px solid #e5e7eb;
    z-index: 100; padding: 6px 0 env(safe-area-inset-bottom, 4px);
}
.bvk-nav-item {
    flex: 1; display: flex; flex-direction: column; align-items: center;
    gap: 2px; padding: 6px 0; text-decoration: none; color: #9ca3af;
    font-size: 10px; font-weight: 600; transition: color .2s;
}
.bvk-nav-item.active { color: var(--brand-color, #f97316); }
.bvk-nav-icon { font-size: 20px; }

/* ── Tab switching ── */
.bvk-tab { display: none; }
.bvk-tab.active { display: block; }
```

```javascript
/* Tab navigation — no page reload */
document.querySelectorAll('.bvk-nav-item').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.bvk-nav-item').forEach(function(l){ l.classList.remove('active'); });
        document.querySelectorAll('.bvk-tab').forEach(function(t){ t.classList.remove('active'); });
        link.classList.add('active');
        var tab = document.getElementById('bvk-tab-' + link.getAttribute('data-tab'));
        if (tab) tab.classList.add('active');
    });
});
```

### 3.13. CSS Design System — Type A (Chuẩn)

```css
/* ── Container ── */
.tc-profile { max-width: 100%; padding: 20px 16px 32px; font-family: system-ui, -apple-system, sans-serif; }

/* ── Hero Card ── */
.tc-hero {
    background: linear-gradient(135deg, var(--hero-from, #4f46e5) 0%, var(--hero-via, #7c3aed) 50%, var(--hero-to, #a78bfa) 100%);
    border-radius: 20px; padding: 28px 20px 20px;
    box-shadow: 0 8px 30px rgba(0,0,0,.15);
    text-align: center; color: #fff;
    position: relative; overflow: hidden;
}
.tc-hero::before {
    content: ''; position: absolute; width: 200px; height: 200px;
    background: rgba(255,255,255,.08); border-radius: 50%;
    top: -60px; right: -40px;
}
.tc-hero-icon { width: 72px; height: 72px; border-radius: 18px; margin-bottom: 12px; }
.tc-hero-name { font-size: 20px; font-weight: 700; margin: 0 0 6px; }
.tc-hero-stats { display: flex; justify-content: center; gap: 24px; margin-top: 16px; }
.tc-stat { text-align: center; }
.tc-stat-val { display: block; font-size: 22px; font-weight: 700; }
.tc-stat-lbl { font-size: 11px; opacity: .8; }

/* ── Section heading ── */
.tc-section { font-size: 15px; font-weight: 600; margin: 24px 0 12px; color: #374151; }

/* ── Command Cards ── */
.tc-cmd {
    display: flex; align-items: flex-start; gap: 14px;
    background: #fff; border-radius: 14px; padding: 14px 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e5e7eb;
    cursor: pointer; transition: all .2s ease;
    margin-bottom: 10px;
}
.tc-cmd:hover { border-color: #c7d2fe; box-shadow: 0 4px 16px rgba(99,102,241,.12); transform: translateY(-1px); }
.tc-cmd:active { transform: scale(.97); }
.tc-cmd-primary { border-color: #c7d2fe; background: linear-gradient(135deg, #fefefe, #f5f3ff); }
.tc-cmd-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.tc-cmd-body { flex: 1; min-width: 0; }
.tc-cmd-label { font-size: 14px; font-weight: 600; color: #1f2937; margin-bottom: 2px; }
.tc-cmd-desc { font-size: 12px; color: #6b7280; line-height: 1.4; margin-bottom: 6px; }
.tc-cmd-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.tc-cmd-tag {
    font-size: 10px; font-weight: 500;
    padding: 2px 7px; border-radius: 6px;
    background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;
}

/* ── Quick Tips ── */
.tc-tip {
    display: flex; align-items: center; gap: 10px;
    background: #fffbeb; border: 1px solid #fde68a;
    border-radius: 10px; padding: 10px 14px;
    cursor: pointer; font-size: 13px; color: #92400e;
    margin-bottom: 8px; transition: all .2s;
}
.tc-tip:hover { background: #fef3c7; }
```

> **Hero gradient tùy chỉnh**: Mỗi plugin set CSS custom properties `--hero-from`, `--hero-via`, `--hero-to` trên `.tc-hero` để tạo brand riêng.

---

## 4. Trụ cột 2 — Intent Provider & Primary Tool

Mỗi plugin đăng ký 1 Intent Provider với Intent Engine.
Provider khai báo: **patterns** (Router), **plans** (Planner), **tools** (Tool callbacks).

### 4.1. Registration Pattern (Chuẩn — Array-based)

Dùng helper `bizcity_intent_register_plugin()` trong `bizcity-{slug}.php`:

```php
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => '{slug}',                           // Unique provider ID
        'name' => 'BizCity Tool — {Name}',             // Display name

        'patterns' => [ /* §8 Goal Patterns */ ],
        'plans'    => [ /* §9 Plans */ ],
        'tools'    => [ /* §4.3 Tools Registration */ ],

        'context'      => function ( $goal, $slots, $user_id, $conversation ) { /* §10 */ },
        'instructions' => function ( $goal ) { /* §10 */ },
        'examples'     => [ /* §4.5 */ ],
    ] );
} );
```

> Class-based provider (extends `BizCity_Intent_Provider`) chỉ dùng khi cần `get_profile_context()` phức tạp (query DB per-user). Xem Phụ lục C.

### 4.2. Primary Tool vs Secondary Tools

Trong `patterns`, **goal cuối cùng** (generic nhất) = primary tool.
Các goal cụ thể hơn ở trên = secondary tools.

```
ORDER MATTERS: specific → generic (top → bottom)

'patterns' => [
    /* SECONDARY — specific patterns FIRST */
    '/sửa sản phẩm|edit product/ui'        => [goal: 'edit_product'],
    '/tạo đơn hàng|đặt hàng/ui'            => [goal: 'create_order'],
    '/báo cáo|thống kê|doanh thu/ui'        => [goal: 'order_stats'],
    ...

    /* PRIMARY — generic pattern LAST (catch-all cho plugin domain) */
    '/tạo sản phẩm|đăng sản phẩm/ui'       => [goal: 'create_product'],  ← PRIMARY
],
```

**Quy tắc Primary Tool:**
- Là goal **generic nhất** — match khi user nói chung về domain
- Đặt **CUỐI CÙNG** trong patterns (fallback sau khi secondary không match)
- Profile View guided command **ĐẦU TIÊN** = primary tool (render nổi bật)
- Khi user click plugin từ Touch Bar → intent engine → primary tool

### 4.3. Tools Registration (với Schema BẮT BUỘC)

Mỗi tool **PHẢI** có `schema` với `description` + `input_fields`:

```php
'tools' => [
    'create_product' => [
        'schema' => [
            'description'  => 'Tạo sản phẩm WooCommerce mới (AI phân tích → upload ảnh → tạo SP)',
            'input_fields' => [
                'topic'     => [ 'required' => true,  'type' => 'text' ],
                'image_url' => [ 'required' => false, 'type' => 'image' ],
            ],
        ],
        'callback' => [ 'BizCity_Tool_Woo', 'create_product' ],
    ],
    'edit_product' => [
        'schema' => [
            'description'  => 'Sửa/cập nhật thông tin sản phẩm WooCommerce đã có',
            'input_fields' => [
                'topic' => [ 'required' => true, 'type' => 'text' ],
            ],
        ],
        'callback' => [ 'BizCity_Tool_Woo', 'edit_product' ],
    ],
    // ... more tools
],
```

**Tại sao schema BẮT BUỘC?**

| Component | Sử dụng schema | Hậu quả nếu thiếu |
|-----------|----------------|-------------------|
| `validate_inputs()` | `schema.input_fields` → check required fields | Bỏ qua validation → runtime error |
| Tool Index DB | `schema.description` → LLM manifest | Tool không hiện trong Unified Classifier |
| Admin Tool Panel | Hiển thị schema trên dashboard | Tool hiện trống, không quản lý được |
| Pipeline planner | `schema.input_fields` → slot mapping | Pipeline không map slots giữa tools |

### 4.4. Input Field Types

| Type | Mô tả | Ví dụ |
|------|--------|-------|
| `text` | Văn bản tự do | `topic`, `question`, `instruction` |
| `choice` | Chọn từ danh sách | `tone`, `language`, `diagram_type` |
| `number` | Số | `so_ngay`, `num_slides`, `price` |
| `date` | Ngày tháng | `from_date`, `to_date` |
| `image` | URL ảnh | `image_url`, `photo_url` |
| `phone` | Số điện thoại | `phone`, `sdt` |

### 4.5. Examples (Gợi ý Tools Map)

```php
'examples' => [
    'create_product' => [
        'Tạo sản phẩm áo thun organic giá 295k',
        'Đăng sản phẩm dịch vụ SEO 500k/tháng',
    ],
    'edit_product' => [
        'Sửa giá SP #123 thành 200k',
        'Cập nhật mô tả sản phẩm mới nhất',
    ],
],
```

---

## 5. Trụ cột 3 — Secondary Tools & Routing

Secondary tools kích hoạt bằng **3 cơ chế**:

### 5.1. Auto — Intent Engine Regex Match

Khi user gõ tin nhắn, Router kiểm tra patterns theo thứ tự top → bottom:

```
User: "Sửa giá SP #123 thành 200k"
 → Router check patterns (top → bottom):
   ✅ '/sửa sản phẩm|cập nhật sản phẩm|chỉnh giá|edit product/ui' MATCH
   → goal = 'edit_product' (secondary)
   ⏭️ Không check tiếp

User: "Đăng sản phẩm mới"
 → Router check patterns:
   ❌ '/sửa sản phẩm.../ui' NOT match
   ❌ '/tạo đơn hàng.../ui' NOT match
   ...
   ✅ '/tạo sản phẩm|đăng sản phẩm/ui' MATCH → goal = 'create_product' (primary)
```

### 5.2. Manual — Slash Command

User gõ `/tool_name` → Engine nhận diện slash command, skip regex:

```
User: "/edit_product sửa giá SP #456 thành 300k"
 → Router Step -1 (slash detect) → goal = 'edit_product'
 → Skip regex matching → straight to Planner
```

### 5.3. @Mention — Direct Provider Route

User gõ `@provider_id ...` → Router redirect thẳng vào provider:

```
User: "@tool-woo tạo sản phẩm mới"
 → Router Step 0 (@mention) → provider = 'tool-woo'
 → Check patterns trong provider → goal = 'create_product'
```

### 5.4. Routing Priority Table

| # | Cơ chế | Trigger | Step | Ví dụ |
|---|--------|---------|------|-------|
| 1 | Slash command | `/tool_name ...` | Step -1 | `/edit_product sửa giá` |
| 2 | @mention | `@provider_id ...` | Step 0 | `@tool-woo tạo sản phẩm` |
| 3 | Auto regex | Chat tự nhiên | Step 0.5+ | "Sửa giá sản phẩm #123" |
| 4 | Guided command | Click từ Profile View | = Auto regex | Click card → postMessage → regex |

---

## 6. Bootstrap — Main Plugin File

File: `bizcity-{slug}/bizcity-{slug}.php`

### 6.1. Plugin Header

```php
<?php
/**
 * Plugin Name:       BizCity Tool — {Name}
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-{slug}
 * Description:       {Mô tả ngắn gọn plugin — 1-2 dòng}
 * Short Description: {1 dòng cho Touch Bar}
 * Quick View:        {Emoji} {Input → AI → Output}
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-{slug}
 * Role:              agent
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/.../cover.png
 * Template Page:     {slug}
 * Category:          {category1}, {category2}
 * Tags:              {tag1}, {tag2}, AI tool
 */
```

**BizCity-specific headers:**

| Field | Bắt buộc | Mô tả |
|-------|----------|-------|
| `Role` | ✅ | Luôn = `agent` — đánh dấu đây là AI Agent plugin |
| `Credit` | ✅ | Chi phí credit mỗi lần dùng (0 = free) |
| `Price` | ✅ | Giá bán plugin (0 = free) |
| `Icon Path` | ✅ | Relative path icon (hiện trên Touch Bar) |
| `Cover URI` | ✅ | URL ảnh bìa cho Marketplace |
| `Template Page` | ✅ | Slug cho profile view route |
| `Short Description` | ✅ | 1 dòng cho Touch Bar |
| `Quick View` | ✅ | Emoji + flow siêu ngắn |
| `Category` | ✅ | Danh mục (comma-separated) |
| `Tags` | ✅ | Tags cho search (comma-separated) |

### 6.2. Constants & Loader

```php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BZTOOL_{PREFIX}_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_{PREFIX}_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_{PREFIX}_VERSION', '1.0.0' );
define( 'BZTOOL_{PREFIX}_SLUG',    'bizcity-{slug}' );

/* ── Load tool callbacks ── */
require_once BZTOOL_{PREFIX}_DIR . 'includes/class-tools-{slug}.php';
```

### 6.3. Complete Bootstrap Flow

Trong 1 file `bizcity-{slug}.php`, tuần tự 3 khối:

```
┌────────────────────────────────────┐
│ 1. Plugin Header                   │  ← WordPress đọc metadata
├────────────────────────────────────┤
│ 2. Constants + Require             │  ← Load dependencies
├────────────────────────────────────┤
│ 3. Intent Provider Registration    │  ← bizcity_intent_register_plugin()
├────────────────────────────────────┤
│ 4. Profile View Route              │  ← add_rewrite_rule + template_redirect
├────────────────────────────────────┤
│ 5. Activation/Deactivation hooks   │  ← flush_rewrite_rules
└────────────────────────────────────┘
```

> **Tham khảo production**: Xem [bizcity-tool-woo.php](../../plugins/bizcity-tool-woo/bizcity-tool-woo.php) — ví dụ hoàn chỉnh từ header đến route.

---

## 7. Tool Schema & I/O Contract

### 7.1. Tool Callback Class

File: `includes/class-tools-{slug}.php`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_{Name} {

    /**
     * Primary Tool
     *
     * @param array $slots Input slots từ Engine
     * @return array Output envelope
     */
    public static function primary_tool( array $slots ) {
        $topic     = $slots['topic'] ?? '';
        $image_url = $slots['image_url'] ?? '';
        $meta      = $slots['_meta'] ?? [];

        // ... business logic ...

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã xử lý thành công!\n🔗 {$url}",
            'data'     => [
                'type'  => 'product',
                'id'    => $product_id,
                'title' => $title,
                'url'   => $url,
            ],
        ];
    }

    /**
     * Secondary Tool
     */
    public static function secondary_tool( array $slots ) {
        // ...
    }
}
```

### 7.2. Output Envelope (BẮT BUỘC)

Mọi tool callback PHẢI return:

```php
[
    'success'        => bool,     // BẮT BUỘC — thao tác OK?
    'complete'       => bool,     // BẮT BUỘC — goal hoàn thành? (true=đóng, false=tiếp tục)
    'message'        => string,   // BẮT BUỘC — message hiển thị cho user
    'data'           => array,    // Optional — structured data cho pipeline
    'missing_fields' => array,    // Optional — cần thêm thông tin → Engine hỏi user
]
```

### 7.3. Output `data` Convention

```php
'data' => [
    // ── Identification ──
    'type'      => string,   // article | image | video | product | order | link | data
    'id'        => int,      // Resource ID (post_id, product_id...)

    // ── Content ──
    'title'     => string,   // Display title
    'content'   => string,   // Content / excerpt
    'url'       => string,   // Permalink
    'image_url' => string,   // Featured image

    // ── Meta (mở rộng) ──
    'meta'      => array,    // Dữ liệu tùy plugin
]
```

### 7.4. _meta Context (auto-injected bởi Engine)

Mỗi tool callback nhận `$slots['_meta']`:

```php
$slots['_meta'] = [
    '_context'     => string,  // Multi-layer context string
    'conv_id'      => int,     // Conversation ID
    'goal'         => string,  // Active goal
    'goal_label'   => string,  // Goal display label
    'character_id' => int,     // Knowledge character ID
    'channel'      => string,  // WEBCHAT | ADMINCHAT | ZALO_BOT
    'message_id'   => string,  // Message ID
    'user_display' => string,  // User display name
    'blog_id'      => int,     // Blog ID (multisite)
];
```

### 7.5. Output Schema — Pipeline Variables (BẮT BUỘC)

Mỗi tool PHẢI khai báo `output_schema` trong tool registration. Schema này định nghĩa **output variables** của tool — tương thích với bizcity-automation `setVariables()`.

```php
'tools' => [
    'write_article' => [
        'callback' => [ 'BizCity_Tool_Content', 'write_article' ],
        'schema'   => [
            'description'  => 'Viết bài blog hoàn chỉnh và đăng WordPress',
            'input_fields' => [ /* ... required_slots + optional_slots */ ],
        ],
        // ── OUTPUT SCHEMA ── pipeline variables
        'output_schema' => [
            'post_id'   => [ 'type' => 'int',    'label' => 'Post ID' ],
            'post_url'  => [ 'type' => 'string', 'label' => 'Post URL' ],
            'title'     => [ 'type' => 'string', 'label' => 'Tiêu đề bài viết' ],
            'image_url' => [ 'type' => 'string', 'label' => 'URL ảnh đại diện' ],
        ],
    ],
],
```

**Quy tắc output_schema:**

| Quy tắc | Chi tiết |
|---------|----------|
| **Tên key** | Khớp với key trong `data` của Output Envelope (§7.3) |
| **Type** | `int`, `string`, `float`, `array`, `bool` |
| **Mapping** | `data.id` → `output_schema.post_id` (hoặc `product_id` tùy plugin) |
| **Pipeline** | Automation node dùng `{{node#ID.post_id}}` để reference output |
| **BẮT BUỘC** | Mọi tool có side-effect (tạo/sửa/xóa resource) PHẢI khai báo `output_schema` |

**Ví dụ mapping Output Envelope → Pipeline Variable:**

```php
// Tool callback return
return [
    'success' => true,
    'data'    => [
        'type'  => 'product',
        'id'    => 123,           // → output_schema['product_id']
        'title' => 'Áo thun AI', // → output_schema['title']
        'url'   => 'https://...',  // → output_schema['product_url']
    ],
];

// Automation pipeline node kế tiếp dùng:
// {{node#5.product_id}}  → 123
// {{node#5.title}}       → "Áo thun AI"
// {{node#5.product_url}} → "https://..."
```

---

## 8. Goal Patterns — Needles

### 8.1. Pattern Structure

```php
'patterns' => [
    '/regex_pattern/ui' => [
        'goal'        => 'goal_id',              // snake_case, unique trong provider
        'label'       => 'Display Label',         // Hiển thị trên dashboard
        'description' => 'Mô tả chi tiết goal',  // Cho LLM Unified Classifier
        'extract'     => [ 'slot1', 'slot2' ],    // Slots extract từ user message
    ],
],
```

### 8.2. Quy tắc viết Needles

| Quy tắc | Giải thích |
|---------|-----------|
| Flag `/ui` | Unicode + case-insensitive — **BẮT BUỘC** cho tiếng Việt |
| Specific → Generic | Patterns cụ thể ĐẶT TRƯỚC, generic đặt cuối (primary) |
| 3-10 biến thể | Mỗi goal cần 3-10 keyword alternatives |
| Có dấu + không dấu | `tạo sản phẩm\|tao san pham` |
| `description` BẮT BUỘC | LLM Unified Classifier dùng khi regex không đủ match |
| Tránh false positive | Test negative cases: "sản phẩm" ≠ "sửa sản phẩm" |

### 8.3. Ví dụ TỐT vs XẤU

```php
/* ✅ TỐT — specific, đủ biến thể, có description */
'/sửa sản phẩm|cập nhật sản phẩm|chỉnh giá|edit product|update sp/ui' => [
    'goal'        => 'edit_product',
    'label'       => 'Sửa sản phẩm',
    'description' => 'Sửa/cập nhật thông tin sản phẩm WooCommerce đã có (tên, giá, mô tả, ảnh, danh mục)',
    'extract'     => [ 'product_id', 'field', 'new_value' ],
],

/* ❌ XẤU — quá generic, thiếu description */
'/sản phẩm/ui' => [ 'goal' => 'edit_product' ],
// "Sản phẩm nào bán chạy?" → false positive (đây là stats, không phải edit)
```

---

## 9. Plans — Slot Gathering

### 9.1. Plan Structure

```php
'plans' => [
    'goal_id' => [
        'required_slots' => [
            'slot_name' => [
                'type'    => 'text|choice|number|image|date|phone',
                'prompt'  => 'Câu hỏi khi thiếu slot',
                'choices' => [ 'a', 'b', 'c' ],   // Chỉ cho type=choice
                'default' => '',                    // Optional default value
            ],
        ],
        'optional_slots' => [ /* cùng format */ ],
        'tool'       => 'tool_name',               // Tool gọi khi đủ slots
        'ai_compose' => false,                      // true = AI soạn thêm, false = tool output only
        'slot_order' => [ 'slot1', 'slot2' ],       // Thứ tự hỏi user
    ],
],
```

### 9.2. Slot Lifecycle

```
1. User message → Router detect goal_id
2. Planner load plan[goal_id] → check required_slots
3. THIẾU slot → status = WAITING_USER, hỏi theo slot_order
4. User trả lời → fill slot → check tiếp
5. ĐỦ required → _confirm_execute card → user "ok"
6. Execute tool(slots) → output → COMPLETED
```

### 9.3. Required vs Optional

| | Required | Optional |
|---|---------|----------|
| Phải có trước execute? | ✅ Có | ❌ Không |
| Hỏi user nếu thiếu? | ✅ Có, theo slot_order | ⏭️ Bỏ qua, dùng default |
| Default value? | Thường không | Nên có |

### 9.4. slot_order

```php
'slot_order' => [ 'topic', 'image_url' ],
```
- Hỏi `topic` trước → hỏi `image_url` sau
- Chỉ liệt kê slots cần hỏi tuần tự
- Optional slots KHÔNG trong slot_order → bỏ qua → dùng default

### 9.5. Slot Config Flags — `no_auto_map` & `accept_image`

**Vấn đề (v4.6.1):** Engine auto-map v4.0.1 ghi raw trigger message vào slot đầu tiên. Ví dụ:
- "tạo sản phẩm nhé" → `topic = "tạo sản phẩm nhé"` (trigger, KHÔNG phải data)
- "giải bài tarot" → `card_info = "giải bài tarot"` (trigger, KHÔNG phải tên lá bài)
- "tạo ảnh nhé" → `creation_mode = "tạo ảnh nhé"` (trigger, KHÔNG phải mode)

→ Planner thấy slot filled → `call_tool` với data sai → ask_field trống → loop.

**Giải pháp:** 2 slot config flags generic:

| Flag | Type | Default | Mô tả |
|---|---|---|---|
| `no_auto_map` | bool | false | Engine skip auto-map, ko ghi raw message vào slot. Dùng cho slot đầu tiên của primary tool khi trigger pattern ≠ data |
| `accept_image` | bool | false | Text slot chấp nhận hình ảnh để **AI phân tích nội dung** (vision). Khác hoàn toàn với `type: 'image'` |

**Khi nào dùng `no_auto_map`?**
- Primary tool có trigger pattern ("viết bài", "tạo ảnh", "giải tarot")
- Slot đầu tiên cần user cung cấp data riêng biệt (chủ đề, ý tưởng, tên lá bài)
- Trigger text KHÔNG phải là data hợp lệ cho slot

#### 9.5.1. Hai loại hình ảnh — Image-as-URL vs Image-as-Analysis

⚠️ **QUAN TRỌNG** — Đây là 2 khái niệm KHÁC NHAU hoàn toàn, provider PHẢI khai báo đúng:

| | **Image-as-URL** (`type: 'image'`) | **Image-as-Analysis** (`accept_image: true`) |
|---|---|---|
| **Mục đích** | URL ảnh là **dữ liệu đầu vào** — lưu trữ, hiển thị, nhúng | Ảnh cần **AI phân tích nội dung** — nhận diện, giải nghĩa |
| **Slot type** | `'image'` | `'text'` (với flag `accept_image: true`) |
| **Engine xử lý** | URL lưu trực tiếp vào slot → tool dùng URL as-is | Auto-fill `'[phân tích từ ảnh]'` vào text slot + pass `_images` cho tool callback |
| **Tool callback nhận** | `$slots['image_url'] = 'https://...'` (URL string) | `$slots['card_info'] = '[phân tích từ ảnh]'` + URL ảnh trong **companion image slot** (ví dụ `card_images`, `photo_url`) |
| **Tool xử lý** | Dùng URL trực tiếp: `set_post_thumbnail()`, `<img src>`, gửi API bên thứ 3 | Gọi Vision AI phân tích ảnh → extract thông tin → xử lý |
| **Ví dụ plugin** | write_article (featured image), create_product (product image), create_video (input image) | tarot (giải nghĩa lá bài), calo (nhận diện món ăn) |
| **User flow** | Upload/paste ảnh → URL lưu → dùng trong output | Upload/paste ảnh → AI xem ảnh → trả lời dựa trên nội dung |

**Image-as-URL — `type: 'image'`:**
```
User: "Viết bài về dinh dưỡng" + upload ảnh
 → Engine: image_url = "https://media.bizcity.vn/.../upload_abc.jpeg"
 → Tool: wp_insert_post() + set_featured_image( image_url )
 → Output: Bài viết kèm ảnh đại diện
```

**Image-as-Analysis — `accept_image: true`:**
```
User: "Giải bài tarot" + gửi ảnh lá bài
 → Engine: card_info = "[phân tích từ ảnh]" + _images = ["https://..."]
 → Tool: gọi Vision AI("Ảnh này là lá bài gì?") → "The Fool"
 → Tool: giải nghĩa lá "The Fool" → Output
```

**Khi nào dùng loại nào?**

| Câu hỏi | Image-as-URL | Image-as-Analysis |
|---|---|---|
| Tool CẦN URL ảnh để nhúng/lưu/gửi đi? | ✅ `type: 'image'` | |
| Tool CẦN biết NỘI DUNG ảnh là gì? | | ✅ `accept_image: true` |
| Ảnh là sản phẩm đầu ra? | ✅ | |
| Ảnh cần AI "đọc" để hiểu? | | ✅ |

#### 9.5.2. Ví dụ khai báo

```php
/* ── Image-as-URL: write_article — ảnh là featured image ── */
'image_url' => [
    'type'    => 'image',          // ← type = image
    'prompt'  => 'Gửi ảnh đại diện cho bài viết 📷',
    // Không cần accept_image — URL lưu trực tiếp
],

/* ── Image-as-URL: create_product — ảnh sản phẩm ── */
'product_image' => [
    'type'    => 'image',          // ← type = image
    'prompt'  => 'Gửi ảnh sản phẩm',
],

/* ── Image-as-URL: create_video — ảnh input cho video ── */
'source_image' => [
    'type'    => 'image',          // ← type = image
    'prompt'  => 'Gửi ảnh gốc để tạo video',
],

/* ── Image-as-Analysis: tarot — ảnh lá bài cần giải nghĩa ── */
// ⚠️ PHẢI có companion image slot để lưu URL ảnh
'card_images' => [
    'type'     => 'image',         // ← companion slot lưu URL ảnh
    'is_array' => true,
    'prompt'   => 'Gửi ảnh các lá bài 📷',
],
'card_info' => [
    'type'         => 'text',      // ← type = text
    'prompt'       => 'Gửi ảnh lá bài hoặc nhắn tên lá bài nhé!',
    'no_auto_map'  => true,
    'accept_image' => true,        // ← flag: Engine auto-fill '[phân tích từ ảnh]'
],

/* ── Image-as-Analysis: calo — ảnh món ăn cần nhận diện ── */
// ⚠️ PHẢI có companion image slot để lưu URL ảnh
'photo_url' => [
    'type'   => 'image',           // ← companion slot lưu URL ảnh
    'prompt' => 'Gửi ảnh món ăn 📷',
],
'food_input' => [
    'type'         => 'text',      // ← type = text
    'prompt'       => 'Gửi ảnh hoặc nhập tên món ăn 🍲',
    'no_auto_map'  => true,
    'accept_image' => true,        // ← flag: Engine auto-fill '[phân tích từ ảnh]'
],
```

#### 9.5.3. Engine Processing Flow

**Flow A — `type: 'image'` (Image-as-URL):**
```
1. User upload ảnh → Router lưu URL vào _images entity
2. Engine map _images[0] → first slot có type:'image'  (Lines 1784-1806)
3. Slot value = URL string trực tiếp
4. Planner thấy slot filled → tiếp tục / call_tool
5. Tool callback: $slots['image_url'] = "https://media.bizcity.vn/..."
6. Tool dùng URL as-is: set_thumbnail(), embed <img>, gửi API
```

**Flow B — `accept_image: true` (Image-as-Analysis):**

⚠️ **YÊU CẦU:** Plugin dùng `accept_image: true` **PHẢI** có thêm 1 companion slot `type: 'image'`
(ví dụ `card_images`, `photo_url`) để Engine lưu URL ảnh riêng biệt.

```
1. User upload ảnh → Router detect accept_image flag (Lines 907-939)
2. Router: method = 'context+accept_image', entities._images = [URLs]
3. Engine: URL → companion image slot (card_images/photo_url)  (Lines 1784-1806)
4. Engine: auto-fill text slot = '[phân tích từ ảnh]'              (Lines 1989-2004)
5. Planner thấy cả 2 slot filled → call_tool
6. Tool callback: $slots['card_images'] = [URLs], $slots['card_info'] = '[phân tích từ ảnh]'
7. Tool: if (!empty($card_images)) → Vision AI phân tích; else → text-only
```

#### 9.5.4. Expected Behavior Matrix

| Input | Slot Config | Engine Action |
|---|---|---|
| "viết bài nhé" | `no_auto_map: true` | Slot trống → planner hỏi user chủ đề |
| "viết bài về AI" | `no_auto_map: true` | `extract_content_after_trigger()` → "AI" → fill slot |
| "viết bài" + upload ảnh | `image_url` type:`image` | URL lưu vào `image_url`, `topic` trống → hỏi |
| "giải bài tarot" + gửi ảnh | `card_info` accept_image | `card_info = '[…ảnh]'` + URL → `card_images` |
| "ghi nhật ký" + gửi ảnh món | `food_input` accept_image | `food_input = '[…ảnh]'` + URL → `photo_url` |
| "tạo video" + upload ảnh | `source_image` type:`image` | URL lưu vào `source_image` trực tiếp |
| "tạo sản phẩm" | `no_auto_map: true` | Slot trống → planner hỏi mô tả sản phẩm |

**Quy tắc áp dụng:**
- Primary tool's main required slot → luôn có `no_auto_map: true`
- Slot nhận URL ảnh để lưu/nhúng/gửi đi → `type: 'image'`
- Slot cần AI phân tích nội dung ảnh → `type: 'text'` + `accept_image: true`
- KHÔNG BAO GIỜ dùng cả `type: 'image'` + `accept_image: true` trên **cùng** slot
- `accept_image: true` **LUÔN** đi kèm 1 companion slot `type: 'image'` (riêng biệt) để lưu URL ảnh
- Tool callback đọc URL ảnh từ companion image slot, **KHÔNG** từ text slot hay `_images`
- Secondary tools / stats tools (ko có trigger conflict) → KHÔNG cần flags

---

## 10. Context & System Instructions

### 10.1. context — Domain Data

Inject dữ liệu business vào AI compose context:

```php
'context' => function ( $goal, $slots, $user_id, $conversation ) {
    $goals = [
        'create_product' => 'Tạo sản phẩm WooCommerce mới',
        'edit_product'   => 'Sửa thông tin sản phẩm đã có',
        // ... all goals
    ];
    return "Plugin: BizCity Tool — {Name}\n"
        . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
        . "Capabilities: {what this plugin can do}\n"
        . "Constraints: {format, language, limits}\n";
},
```

### 10.2. instructions — AI Behavior (Optional)

```php
'instructions' => function ( $goal ) {
    return "Bạn là chuyên gia {domain}.\n"
         . "Quy tắc:\n"
         . "1. {Quy tắc 1}\n"
         . "2. {Quy tắc 2}\n"
         . "Giọng văn: {tone}. Ngôn ngữ: Tiếng Việt.\n";
},
```

### 10.3. context vs instructions

| | `context` | `instructions` |
|---|-----------|---------------|
| Nội dung | **DATA** — DB data, user profile, current state | **RULES** — hành vi AI, giọng văn, format |
| Thay đổi? | Dynamic theo goal/user/conversation | Tĩnh hoặc thay đổi nhẹ theo goal |
| Ví dụ | "Plugin: Tarot\nLá bài: The Fool..." | "Bạn là chuyên gia tarot 20 năm..." |

---

## 11. Data & Custom Post Type

### 11.1. Nguyên tắc — KHÔNG tạo bảng riêng

**BẮT BUỘC**: Plugins KHÔNG tạo bảng SQL riêng. Sử dụng **WordPress Custom Post Type (CPT)** + `wp_postmeta` để lưu trữ dữ liệu.

| ❌ KHÔNG làm | ✅ Chuẩn mới |
|-------------|-------------|
| `CREATE TABLE wp_bz{prefix}_items` | `register_post_type('bizcity-agent-{slug}')` |
| `$wpdb->insert(custom_table)` | `wp_insert_post(['post_type' => 'bizcity-agent-{slug}'])` |
| `$wpdb->get_results("SELECT...")` | `new WP_Query(['post_type' => 'bizcity-agent-{slug}'])` |
| `includes/install.php` + `dbDelta()` | `includes/class-post-type.php` + `register_post_type()` |

**Lý do:**
- WP Cache + Object Cache tự động
- WP_Query native, pagination, meta query sẵn
- REST API free (`/wp-json/wp/v2/bizcity-agent-{slug}/`)
- Admin UI free (nếu `show_ui => true`)
- Không cần migration scripts
- Tương thích với bizcity-automation workflow pipeline (Section 17)

### 11.2. CPT Naming Convention

```
bizcity-agent-{slug}
```

| Plugin | CPT Name | Max 20 chars |
|--------|----------|--------------|
| tool-content | `bizcity-agent-content` | ❌ 22 chars → dùng `bza-content` |
| tool-woo | `bizcity-agent-woo` | ✅ 18 chars |
| agent-calo | `bizcity-agent-calo` | ✅ 19 chars |
| tool-tarot | `bizcity-agent-tarot` | ✅ 20 chars |

> **Lưu ý**: WordPress giới hạn post_type tối đa **20 ký tự**. Nếu `bizcity-agent-{slug}` vượt 20 chars → dùng `bza-{slug}` (prefix rút gọn).

### 11.3. CPT Registration Pattern

File: `includes/class-post-type.php`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_{Name}_Post_Type {

    const POST_TYPE = 'bza-{slug}';  // max 20 chars

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Agent {Name} History',
                'singular_name' => 'Agent {Name} Entry',
            ],
            'public'       => false,      // Không hiện frontend
            'show_ui'      => false,      // Không hiện admin menu (optional: true để debug)
            'show_in_rest' => false,      // Bật nếu cần REST API
            'supports'     => [ 'title', 'editor', 'author', 'custom-fields' ],
            'capability_type' => 'post',
        ] );
    }
}
```

### 11.4. Lưu dữ liệu — wp_insert_post + postmeta

Thay vì `$wpdb->insert()` vào bảng custom:

```php
/* ── Lưu prompt history ── */
$post_id = wp_insert_post( [
    'post_type'    => BizCity_{Name}_Post_Type::POST_TYPE,
    'post_title'   => sanitize_text_field( $prompt ),
    'post_content' => wp_kses_post( $ai_content ),
    'post_status'  => 'publish',
    'post_author'  => $user_id,
] );

if ( $post_id && ! is_wp_error( $post_id ) ) {
    update_post_meta( $post_id, '_bza_goal',      sanitize_text_field( $goal ) );
    update_post_meta( $post_id, '_bza_ai_title',   sanitize_text_field( $ai_title ) );
    update_post_meta( $post_id, '_bza_result_id',  (int) $result_id );
    update_post_meta( $post_id, '_bza_result_url', esc_url_raw( $result_url ) );
    update_post_meta( $post_id, '_bza_image_url',  esc_url_raw( $image_url ) );
    update_post_meta( $post_id, '_bza_status',     'completed' );
}
```

### 11.5. Truy vấn dữ liệu — WP_Query

Thay vì `$wpdb->get_results()`:

```php
/* ── Poll history ── */
$query = new WP_Query( [
    'post_type'      => BizCity_{Name}_Post_Type::POST_TYPE,
    'author'         => $user_id,
    'posts_per_page' => 30,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_query'     => [
        [ 'key' => '_bza_goal', 'value' => $goal ],  // Optional filter
    ],
] );

$items = [];
foreach ( $query->posts as $p ) {
    $items[] = [
        'id'         => $p->ID,
        'prompt'     => $p->post_title,
        'ai_title'   => get_post_meta( $p->ID, '_bza_ai_title', true ),
        'ai_content' => wp_trim_words( wp_strip_all_tags( $p->post_content ), 50 ),
        'result_id'  => get_post_meta( $p->ID, '_bza_result_id', true ),
        'result_url' => get_post_meta( $p->ID, '_bza_result_url', true ),
        'image_url'  => get_post_meta( $p->ID, '_bza_image_url', true ),
        'status'     => get_post_meta( $p->ID, '_bza_status', true ),
        'created_at' => $p->post_date,
    ];
}
```

### 11.6. Meta Key Convention

Mọi postmeta key có prefix `_bza_` (BizCity Agent):

| Meta Key | Type | Mô tả |
|----------|------|--------|
| `_bza_goal` | string | Goal đã thực thi (write_article, create_product...) |
| `_bza_ai_title` | string | Tiêu đề AI generate |
| `_bza_result_id` | int | ID của resource tạo ra (post_id, product_id...) |
| `_bza_result_url` | string | URL của resource tạo ra |
| `_bza_image_url` | string | URL ảnh đại diện |
| `_bza_status` | string | completed \| failed \| pending |
| `_bza_{custom}` | mixed | Meta tùy plugin (e.g. `_bza_price`, `_bza_category`) |

> **Underscore prefix `_`**: Meta key bắt đầu bằng `_` sẽ ẩn khỏi Custom Fields UI trong admin — đây là best practice cho internal data.

### 11.7. Bootstrap Integration

Trong file bootstrap `bizcity-{slug}.php`:

```php
/* ── Load CPT registration (thay thế install.php) ── */
require_once BZTOOL_{PREFIX}_DIR . 'includes/class-post-type.php';
BizCity_{Name}_Post_Type::init();

/* ── KHÔNG CẦN activation hook cho DB ── */
// ❌ register_activation_hook( __FILE__, 'bz{prefix}_install_tables' );
// ✅ CPT tự register trên mỗi request qua init hook
```

---

## 12. Admin Menu

Chỉ cần nếu plugin có settings hoặc data management:

```php
/* includes/admin-menu.php */
add_action( 'admin_menu', function() {
    add_menu_page(
        'BizCity {Name}',
        'BizCity {Name}',
        'manage_options',
        'bizcity-{slug}',
        'bz{prefix}_admin_dashboard',
        'dashicons-admin-tools',
        30
    );
} );
```

---

## 13. Chat Integration (Zalo/Bot)

### Khi nào cần?

- **Mặc định**: Intent Engine đã xử lý chat cho Webchat/AdminChat → KHÔNG cần thêm gì
- Cần `integration-chat.php` chỉ khi muốn **native processing trên Zalo/Bot/Telegram**

### Pattern

```php
/* includes/integration-chat.php */
add_filter( 'bizcity_unified_message_intent', function( $handled, $ctx ) {
    if ( $handled ) return $handled;

    $message = $ctx['message'] ?? '';
    $recent  = $ctx['recent_context'] ?? '';

    // keyword check → process → send reply
    if ( preg_match( '/trigger_pattern/ui', $message ) ) {
        // ... process
        return true; // Đã xử lý
    }

    return $handled;
}, 10, 2 );
```

---

## 14. Knowledge Binding (RAG)

### Khi nào cần?

Khi plugin cần inject **kiến thức chuyên môn** (FAQ, tài liệu) vào AI context.

### Pattern

```php
/* Trong provider registration */
'knowledge_character_id' => function_exists( 'bz{prefix}_get_knowledge_id' )
    ? bz{prefix}_get_knowledge_id() : 0,
```

```php
/* includes/knowledge-binding.php */
function bz{prefix}_get_knowledge_id() {
    return (int) get_option( 'bz{prefix}_knowledge_character_id', 0 );
}
```

Intent Engine tự động query RAG system khi `knowledge_character_id > 0`.

---

## 15. Naming Convention

### 14.1. Plugin Naming

| Item | Pattern | Ví dụ |
|------|---------|-------|
| Plugin folder | `bizcity-{slug}` | `bizcity-tool-woo` |
| Main file | `bizcity-{slug}.php` | `bizcity-tool-woo.php` |
| Text domain | `bizcity-{slug}` | `bizcity-tool-woo` |
| Constant prefix | `BZTOOL_{PREFIX}_` | `BZTOOL_WOO_` |
| Provider ID | `{slug}` | `tool-woo` |

### 14.2. Tool Naming

| Item | Pattern | Ví dụ |
|------|---------|-------|
| Tool name (= goal ID) | `snake_case` | `create_product`, `ask_gemini` |
| Callback class | `BizCity_Tool_{Name}` | `BizCity_Tool_Woo` |
| Callback file | `class-tools-{slug}.php` | `class-tools-woo.php` |
| Methods | `public static function {tool_name}( $slots )` | `::create_product($slots)` |

### 14.3. CSS Classes

| Class | Phần | Ví dụ |
|-------|------|-------|
| `.tc-profile` | Container | Profile view wrapper |
| `.tc-hero` | Hero section | Gradient card |
| `.tc-cmd` | Command card | Guided command |
| `.tc-cmd-primary` | Primary modifier | Primary tool highlight |
| `.tc-tip` | Quick tip | Prompt suggestion |

---

## 16. Tool Trace — Báo tiến độ thực thi

Mọi tool callback **PHẢI** báo tiến độ thực thi realtime để user biết tool đang làm gì.
Sử dụng **Tool Trace API** — chuẩn hoá 3 kênh đồng thời:

| Kênh | Mục đích | Hiển thị |
|------|----------|----------|
| **React WorkingIndicator** | User nhìn tiến độ | SSE `log` → `workingSteps[]` |
| **error_log** | Debug PHP log | `error_log()` |
| **SSE status text** | Typing indicator | `bizcity_intent_status` |

### 15.1. Quick Trace — `bizcity_tool_trace()` (bắt buộc)

Dùng cho tool đơn giản, chỉ cần báo 1-2 dòng tiến độ:

```php
public static function my_tool( array $slots ): array {
    bizcity_tool_trace( '🔍 Đang tìm kiếm...' );
    $results = do_search( $slots['keyword'] );

    bizcity_tool_trace( '📝 Đã tìm thấy ' . count($results) . ' kết quả' );

    return [ 'success' => true, 'message' => '...', 'data' => [...] ];
}
```

### 15.2. Full Trace — `BizCity_Job_Trace` (multi-step tools)

Dùng cho tool có nhiều bước tuần tự (viết bài, tạo ảnh, đăng bài...):

```php
public static function write_article( array $slots ): array {
    $session_id = $slots['_trace_session_id'] ?? $slots['session_id'] ?? '';

    $trace = BizCity_Job_Trace::start( $session_id, 'write_article', [
        'T1' => 'Viết nội dung bài',      // → ✍️ [1/3]
        'T2' => 'Tạo ảnh bìa',            // → 🎨 [2/3]
        'T3' => 'Đăng bài lên WordPress',  // → 📝 [3/3]
    ]);

    // ── Bước 1 ──
    $trace->step( 'T1', 'running' );
    $content = generate_content( $slots['topic'] );
    $trace->step( 'T1', 'done', [ 'title' => $content['title'] ] );

    // ── Bước 2 ──
    $trace->step( 'T2', 'running' );
    $image = generate_image( $content['title'] );
    $trace->step( 'T2', 'done', [ 'image_url' => $image['url'] ] );

    // ── Bước 3 ──
    $trace->step( 'T3', 'running' );
    $post_id = wp_insert_post( ... );
    $trace->step( 'T3', 'done', [ 'post_id' => $post_id ] );

    // Hoàn thành — auto-cleanup sau 1h
    $trace->complete([ 'post_id' => $post_id ]);

    return [ 'success' => true, 'message' => '...', 'data' => [...] ];
}
```

### 15.3. Trace Rules

| Rule | Chi tiết |
|------|----------|
| **BẮT BUỘC** | Mọi tool PHẢI có ít nhất 1 `bizcity_tool_trace()` call |
| **Multi-step** | Tool có ≥2 bước → PHẢI dùng `BizCity_Job_Trace::start()` |
| **Step status** | `running` → `done` hoặc `failed` (KHÔNG skip) |
| **Auto-complete** | Engine tự `complete()`/`fail()` nếu callback quên gọi |
| **Data chaining** | `$trace->get_step_data('T1')` để lấy output bước trước |
| **Error handling** | `$trace->step('T2', 'failed', [], 'API timeout')` |

### 15.4. Icon auto-detection

Tiêu đề step tự động map emoji dựa trên keyword:

| Keyword | Icon | Keyword | Icon |
|---------|------|---------|------|
| viết, nội dung | ✍️ | tìm, search | 🔍 |
| ảnh, hình | 🎨 | gửi, send | 📤 |
| đăng, publish | 📝 | tải, upload | 📥 |
| video | 🎬 | phân tích | 📊 |
| dịch, translate | 🌐 | (mặc định) | ⚙️ |

### 15.5. Frontend rendering

WorkingIndicator hiển thị trace steps cùng pipeline steps:

```
✓ Nhận tin nhắn                    12ms
✓ Chế độ: Thực thi                 45ms
✓ Kế hoạch: write_article          89ms
✓ Thực thi: write_article         102ms
✓ ✍️ [1/3] Viết nội dung bài      2.1s    ← tool_trace
  🎨 [2/3] Tạo ảnh bìa...          5s     ← tool_trace (active)
```

> **Xem chi tiết**: [TOOL-TRACE-SDK.md](TOOL-TRACE-SDK.md)

---

## 17. Automation Bridge — Workflow Pipeline

### 17.1. Tổng quan

Plugin `bizcity-automation` là hệ thống workflow pipeline với các node action. Mỗi Intent Tool có thể trở thành **một node action** trong pipeline, cho phép:

- Chain tools: `AI viết bài → Tạo ảnh → Đăng Facebook → Gửi Zalo`
- Kết hợp intent tools với automation actions (WooCommerce, Email, Calendar...)
- Data flow qua template variables: `{{node#5.post_id}}`

```
┌─────────────────────────────────────────────────────────────────────┐
│                  Automation Pipeline                                │
│                                                                     │
│  ┌─────────┐    ┌─────────────┐    ┌──────────────┐    ┌────────┐  │
│  │ Trigger  │───▶│ IT: Content │───▶│ IT: Facebook │───▶│ Notify │  │
│  │ (Cron)   │    │ write_article│   │ post_fb      │    │ (Zalo) │  │
│  └─────────┘    └──────┬──────┘    └──────┬───────┘    └────────┘  │
│                        │                   │                        │
│                  {{node#1.post_url}}  {{node#2.fb_post_id}}        │
│                                                                     │
│  Prefix: it_ = Intent Tools category                               │
└─────────────────────────────────────────────────────────────────────┘
```

### 17.2. Automation Node Architecture

Mỗi WaicAction node trong bizcity-automation có 3 thành phần:

```php
class WaicAction_it_write_article extends WaicAction {
    // 1. SETTINGS (input fields — UI builder form)
    public function setSettings() {
        $this->_settings = [
            'topic' => ['type' => 'input', 'label' => 'Chủ đề', 'variables' => true],
            'tone'  => ['type' => 'select', 'label' => 'Giọng văn', 'options' => [...]],
        ];
    }

    // 2. VARIABLES (output fields — available cho node kế tiếp)
    public function setVariables() {
        $this->_variables = [
            'post_id'   => 'Post ID',
            'post_url'  => 'Post URL',
            'title'     => 'Tiêu đề',
            'image_url' => 'URL ảnh',
        ];
    }

    // 3. RESULTS (execution — gọi tool callback nội bộ)
    public function getResults( $taskId, $variables, $step = 0 ) {
        // resolve input từ pipeline variables
        $topic = $this->replaceVariables( $this->getParam('topic'), $variables );

        // gọi intent tool callback
        $result = BizCity_Tool_Content::write_article([
            'message' => $topic,
            'tone'    => $this->getParam('tone'),
        ]);

        if ( $result['success'] ) {
            return [
                'result' => [
                    'post_id'   => $result['data']['id'],
                    'post_url'  => $result['data']['url'],
                    'title'     => $result['data']['title'],
                    'image_url' => $result['data']['image_url'] ?? '',
                ],
                'error'  => '',
                'status' => 3,  // 3 = success
            ];
        }

        return ['result' => [], 'error' => $result['message'], 'status' => 7];
    }
}
```

### 17.3. Auto-Registration via Filter

Plugin đăng ký external blocks vào automation bằng 2 filter qua `WaicDispatcher`:

**Filter 1 — External Blocks Paths** (bắt buộc nếu có physical block file):

```php
/* Trong class Automation Provider */
WaicDispatcher::addFilter( 'getExternalBlocksPaths', function( $paths ) {
    $paths[] = MY_PLUGIN_DIR . 'blocks/';
    return $paths;
} );
```

> **LƯU Ý:** Filter internal name = `waic_getExternalBlocksPaths` (WaicDispatcher auto-prefix `waic_`).
> Đây là filter ARRAY — trả về danh sách đường dẫn, KHÔNG phải single path.
> `loadAllBlocks()` merge theo thứ tự: custom (legacy) → external paths → built-in.

**Filter 2 — Block Categories** (bắt buộc — đăng ký category prefix):

```php
WaicDispatcher::addFilter( 'getBlocksCategories', function( $cats ) {
    // Trigger category
    if ( ! isset( $cats['triggers']['bc'] ) ) {
        $cats['triggers']['bc'] = [
            'name' => __( 'BizCity Chat Agent', 'bizcity' ),
            'desc' => __( 'Trigger từ Admin Chat / Webchat', 'bizcity' ),
        ];
    }
    // Action categories
    if ( ! isset( $cats['actions']['bc'] ) ) {
        $cats['actions']['bc'] = [
            'name' => __( 'BizCity Chat Agent', 'bizcity' ),
            'desc' => __( 'Gửi tin nhắn qua Admin Chat', 'bizcity' ),
        ];
    }
    if ( ! isset( $cats['actions']['it'] ) ) {
        $cats['actions']['it'] = [
            'name' => __( 'Intent Tools', 'bizcity' ),
            'desc' => __( 'Các AI Tool từ BizCity Intent Engine', 'bizcity' ),
        ];
    }
    return $cats;
} );
```

> **LƯU Ý:** KHÔNG dùng `add_filter('WaicDispatcher_getBlocksCategories', ...)`.
> PHẢI dùng `WaicDispatcher::addFilter('getBlocksCategories', ...)` — dispatcher tự prefix `waic_`.

### 17.3.1. Hybrid 80/20 — Universal Tool Caller

Thay vì tạo WaicAction riêng cho MỖI tool, dùng block `it_call_tool` chung:

- **80% tools** → dùng `it_call_tool` (chọn tool_id từ dropdown, truyền JSON input)
- **20% specialized** → tạo WaicAction riêng (video-kling polling, multi-step complex...)

```
blocks/
├── triggers/
│   └── bc_adminchat_message.php    ← Trigger: nhận tin nhắn Admin Chat
└── actions/
    ├── bc_send_adminchat.php        ← Action: gửi phản hồi về Admin Chat
    └── it_call_tool.php             ← Universal: gọi bất kỳ Intent Tool
```

`it_call_tool` tự liệt kê tools từ `BizCity_Intent_Tools::instance()->get_all()` làm dropdown.
Output variables chung: `success`, `result_json`, `resource_id`, `resource_url`, `title`, `image_url`, `message`.

Đặt file block tại thư mục đã đăng ký qua `waic_getExternalBlocksPaths`.

### 17.4. Mapping output_schema → WaicAction

| Tool `output_schema` (§7.5) | WaicAction `setVariables()` | Pipeline Variable |
|------------------------------|---------------------------|-------------------|
| `'post_id' => ['type'=>'int', 'label'=>'Post ID']` | `'post_id' => 'Post ID'` | `{{node#ID.post_id}}` |
| `'post_url' => ['type'=>'string', 'label'=>'Post URL']` | `'post_url' => 'Post URL'` | `{{node#ID.post_url}}` |
| `'title' => ['type'=>'string', 'label'=>'Tiêu đề']` | `'title' => 'Tiêu đề'` | `{{node#ID.title}}` |

**Quy tắc:**
- Key names trong `output_schema` PHẢI khớp với key names trong `setVariables()`
- Key names trong `data` return của tool callback PHẢI khớp (trừ `id` → map thành `{type}_id`)
- Tool callback return `data.id` → automation node map thành `{resource_type}_id` (e.g. `post_id`, `product_id`)

### 17.5. Result Format

Automation node result PHẢI tuân theo format:

```php
[
    'result' => [ /* key-value từ output_schema */ ],
    'error'  => '',           // Empty string nếu success
    'status' => 3,            // 3 = success, 7 = error
]
```

### 17.6. Ví dụ Production — tool-woo as Automation Node

```php
/* blocks/actions/it_create_product.php */
class WaicAction_it_create_product extends WaicAction {
    protected $_code = 'it_create_product';

    public function __construct( $block = null ) {
        $this->_name = 'IT: Tạo sản phẩm WooCommerce';
        $this->_desc = 'Gọi AI tạo sản phẩm từ mô tả — output product_id + URL';
        $this->setBlock( $block );
    }

    public function setSettings() {
        $this->_settings = [
            'topic'     => ['type' => 'textarea', 'label' => 'Mô tả sản phẩm', 'variables' => true],
            'image_url' => ['type' => 'input', 'label' => 'URL ảnh', 'variables' => true],
        ];
    }

    public function setVariables() {
        $this->_variables = [
            'product_id'  => 'Product ID',
            'product_url' => 'Product URL',
            'title'       => 'Tên sản phẩm',
            'price'       => 'Giá',
            'image_url'   => 'URL ảnh',
        ];
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $topic = $this->replaceVariables( $this->getParam('topic'), $variables );
        $image = $this->replaceVariables( $this->getParam('image_url'), $variables );

        $result = BizCity_Tool_Woo::create_product([
            'message'   => $topic,
            'image_url' => $image,
        ]);

        if ( $result['success'] ) {
            return [
                'result' => [
                    'product_id'  => $result['data']['id'],
                    'product_url' => $result['data']['url'],
                    'title'       => $result['data']['title'],
                    'price'       => $result['data']['meta']['price'] ?? '',
                    'image_url'   => $result['data']['image_url'] ?? '',
                ],
                'error'  => '',
                'status' => 3,
            ];
        }

        return ['result' => [], 'error' => $result['message'], 'status' => 7];
    }
}
```

---

## 18. Scaffold Checklist

## 19. Quick Shortcut Slash Contract

Mọi quick prompt, quick chat, guided command click trong profile page phải gửi payload chuẩn:

```js
window.parent.postMessage({
    type: 'bizcity_agent_command',
    source: 'bizcity-tool-{name}',
    plugin_slug: 'bizcity-tool-{name}',
    tool_name: 'main_tool_name',
    text: '/main_tool_name Prompt content'
}, '*');
```

Quy tắc bắt buộc:

- Nếu `text` chưa có `/` và có `tool_name` thì prepend `/{tool_name}`.
- Mỗi command/tip trong `page-agent-profile.php` nên có `data-tool`.
- Receiver trong webchat phải fallback ra `main_tool` theo plugin metadata nếu payload cũ không có `tool_name`.

Tham khảo chi tiết ở `PLUGIN-TWIN-STANDARD.md`.

### Minimal Plugin (3 files)

```
✅ bizcity-{slug}.php
   □ Plugin Header (với Role: agent, Credit, Price, Template Page...)
   □ Constants (DIR, URL, VERSION, SLUG)
   □ require_once class-tools-{slug}.php
   □ require_once class-post-type.php + ::init()  (nếu cần lưu history)
   □ bizcity_intent_register_plugin() — patterns, plans, tools (with schema + output_schema), context
   □ Profile View route (add_rewrite_rule + template_redirect)
   □ Activation/deactivation hooks (flush_rewrite_rules)

✅ includes/class-tools-{slug}.php
   □ Primary tool static method — return { success, complete, message }
   □ Secondary tool static methods (if any)

✅ views/page-agent-profile.php
   □ Hero section (icon, name, stats)
   □ Guided commands (primary first, then secondary)
   □ Quick tips
   □ postMessage JavaScript

📁 assets/icon.png — Plugin icon
📁 index.php — Security files (root + includes/ + views/ + assets/)
```

### Type B Plugin — Async Processing (thêm 3 files)

```
✅ (3 files trên) +

✅ includes/class-ajax-{slug}.php
   □ handle_create()        — AJAX form submit → reuse Tool Callback
   □ handle_poll_jobs()     — Trả JSON jobs + stats + checkpoints
   □ handle_upload_photo()  — Upload ảnh → WP Media → URL
   □ handle_upload_to_media() — Manual upload video/file → Media
   □ handle_save_settings() — Lưu API key + defaults → wp_options
   □ Mỗi handler: check_ajax_referer + user check

✅ includes/class-cron-{slug}.php
   □ handle_poll($job_id)     — WP-Cron polling external API
   □ notify_user_chat()       — log_message() vào webchat_messages
   □ download_to_media()      — Fetch result file → WP Media Library
   □ Cleanup: delete_transient() khi job done/failed

✅ views/page-agent-profile.php (Type B — 4 tabs)
   □ Bottom nav: Create + Monitor + Chat + Settings
   □ Tab 1 (Create): Form + AJAX + result card
   □ Tab 2 (Monitor): Stats bar + live console + job list
   □ Tab 2: PHP initial render ĐẦY ĐỦ (pipeline + buttons + model badge)
   □ Tab 2: JS pollJobs() + renderJobs() + detectChanges() + managePollTimer()
   □ Tab 3 (Chat): Guided commands + postMessage
   □ Tab 4 (Settings): Admin API + user defaults + save
```

### Validation Checklist

```
── Provider Registration ──
□ 'id' unique, không trùng provider khác
□ 'patterns' có ≥1 goal (primary đặt CUỐI)
□ Mỗi pattern có 'description' (cho LLM Classifier)
□ Mỗi goal có plan tương ứng
□ Mỗi plan có tool tương ứng

── Tool Schema ──
□ Mỗi tool có 'schema' với 'description' + 'input_fields'
□ Mỗi tool có side-effect PHẢI có 'output_schema' (§7.5)
□ output_schema keys khớp với data keys trong callback return
□ input_fields match required_slots + optional_slots trong plan
□ Callback return envelope: { success, complete, message }
□ Callback class+method tồn tại và callable

── Data & CPT (§11) ──
□ KHÔNG tạo bảng SQL riêng — dùng CPT + wp_postmeta
□ Post type name ≤ 20 chars (bza-{slug} nếu cần rút gọn)
□ register_post_type() trên init hook (KHÔNG dùng activation hook)
□ Meta keys có prefix _bza_ (underscore đầu = hidden)
□ Lưu history bằng wp_insert_post + update_post_meta
□ Query history bằng WP_Query (KHÔNG dùng $wpdb trực tiếp)

── Profile View ──
□ Route /{slug}/ hoạt động
□ Primary tool card ĐẶT ĐẦU TIÊN, có class tc-cmd-primary
□ data-msg attribute có prompt text hợp lý
□ postMessage({ type: 'bizcity_agent_command', source: 'bizcity-{slug}' }) hoạt động

── Profile View Type B (nếu async processing) ──
□ 4 tabs: Create + Monitor + Chat + Settings
□ Bottom nav cố định, tab switch không reload trang
□ Create tab: Form → AJAX → reuse Tool Callback (CÙNG callback với intent engine)
□ Create tab: Photo upload → WP Media → URL slot
□ Create tab: Parameter pills/selects match plan.required_slots
□ Monitor tab: PHP initial render ĐẦY ĐỦ (pipeline steps + buttons + model badge)
□ Monitor tab: Auto-poll 10s khi có active jobs, tắt khi không
□ Monitor tab: Change detection log vào live console
□ Monitor tab: Action buttons cho completed: Upload Media / Copy Link / View
□ Monitor tab: Pipeline steps dùng checkpoints JSON (✅ done / ⏳ active / ⭕ pending)
□ Chat tab: Guided commands + quick tips → postMessage (chuẩn Type A)
□ Settings tab: Admin fields guarded by manage_options capability
□ Settings tab: User fields validated against allowlist arrays
□ Async notification: log_message() trực tiếp vào webchat_messages (KHÔNG dùng get_ai_response)
□ Async notification: session_id + conversation_id từ job metadata

── Automation Bridge (§17, optional) ──
□ output_schema đã khai báo cho mọi tool có side-effect
□ Hybrid 80/20: dùng it_call_tool cho tools đơn giản, WaicAction riêng cho complex
□ External blocks path: WaicDispatcher::addFilter('getExternalBlocksPaths', ...)
□ Category filter: WaicDispatcher::addFilter('getBlocksCategories', ...) → 'it'/'bc' categories
□ Block file đặt tại blocks/actions/ hoặc blocks/triggers/ trong thư mục đã đăng ký
□ it_call_tool: tool_id dropdown + input_json textarea + user_id_source select
□ Custom WaicAction (nếu cần): setSettings() ← plan.required_slots, setVariables() ← output_schema
□ getResults() gọi tool callback → return result/error/status (3=success, 7=error)

── Security ──
□ Mỗi thư mục có index.php
□ if ( ! defined( 'ABSPATH' ) ) exit; ở đầu mỗi PHP file
□ Nonces verified cho AJAX endpoints
□ User input sanitized: sanitize_text_field, esc_html, esc_attr
□ Capabilities checked cho admin endpoints
```

---

## Phụ lục A — Production: Plugin đơn giản (tool-mindmap)

```
bizcity-tool-mindmap/
├── bizcity-tool-mindmap.php    ←  Header + provider + route (1 file)
├── includes/
│   └── class-tools-mindmap.php ←  1 static method: create_diagram($slots)
├── views/
│   └── page-mindmap.php        ←  SPA (Type B): prompt + preview + history
├── assets/
│   └── mindmap.png
└── index.php (×4)
```

- 4 goals → tất cả map về 1 tool `create_diagram`
- Primary = `create_diagram` (generic nhất, đặt cuối)
- Type B profile view (SPA với bottom nav) — vì cần preview diagram

## Phụ lục A2 — Production: Plugin async (video-kling) — Type B chuẩn

```
bizcity-video-kling/
├── bizcity-video-kling.php          ←  Header + provider + route
├── includes/
│   ├── class-tools-kling.php        ←  create_video, check_status, list_videos
│   ├── class-ajax-kling.php         ←  5 AJAX handlers (§3.7-§3.10)
│   ├── class-cron-chat.php          ←  Cron poll + webchat push (§3.11)
│   ├── class-database.php           ←  Jobs + Scripts tables
│   └── class-post-type.php          ←  CPT registration (bza-kling)
├── views/
│   └── page-kling-profile.php       ←  Type B: 4 tabs (Create + Monitor + Chat + Settings)
├── lib/
│   └── kling_api.php                ←  PiAPI gateway wrapper
├── assets/
│   └── icon.png
└── index.php (×5)
```

- 3 tools: `create_video` (primary), `check_video_status`, `list_my_videos`
- **Type B profile**: 4 tabs chuẩn — Create form, Monitor với pipeline steps + action buttons, Chat guided commands, Settings API + defaults
- **Async flow**: Job creation → WP-Cron 15s poll → Video fetch → Media upload → `log_message()` webchat push
- **Reference implementation** cho mọi plugin có async processing

## Phụ lục B — Production: Plugin multi-tool (tool-woo)

```
bizcity-tool-woo/
├── bizcity-tool-woo.php        ←  Header + provider (9 goals, 9 tools) + route
├── includes/
│   └── class-tools-woo.php     ←  9 static methods
├── views/
│   └── page-agent-profile.php  ←  Type A: 9 guided command cards
├── assets/
│   └── icon.png
└── index.php (×4)
```

- 9 goals → 9 patterns → 9 plans → 9 tools → mỗi tool có full schema
- Primary = `create_product` (đặt cuối patterns)
- Type A profile view (Guided Commands + postMessage)

## Phụ lục C — Class-based Provider (agent-calo)

```
bizcity-agent-calo/
├── bizcity-agent-calo.php       ←  Header + require + class-based registration
├── includes/
│   ├── class-intent-provider.php ←  extends BizCity_Intent_Provider
│   ├── class-post-type.php       ←  CPT registration (bza-calo) + postmeta for meals/stats/profiles
│   ├── admin-menu.php            ←  4 submenus
│   ├── ajax.php                  ←  AJAX endpoints
│   └── ...
├── views/ + assets/
```

**Khi nào dùng class-based?**
- Cần `get_profile_context()` phức tạp (query user data per request)
- Cần instance methods chia sẻ giữa tools
- Plugin có nhiều business logic riêng (AI analysis, scoring)

**Class-based registration pattern:**
```php
/* bizcity-agent-calo.php */
require_once BZCALO_DIR . 'includes/class-intent-provider.php';

add_action( 'bizcity_intent_register_providers', function ( $registry ) {
    $registry->register( new BizCity_Calo_Intent_Provider() );
} );
```

```php
/* includes/class-intent-provider.php */
class BizCity_Calo_Intent_Provider extends BizCity_Intent_Provider {
    public function get_id()   { return 'agent-calo'; }
    public function get_name() { return 'BizCity Agent — Calo'; }

    public function get_goal_patterns() { return [ /* ... */ ]; }
    public function get_plans()         { return [ /* ... */ ]; }
    public function get_tools()         { return [ /* ... */ ]; }

    public function build_context( $goal, $slots, $user_id, $conv ) {
        // Query user profile, daily stats, meal history...
        return "User Profile: ...";
    }

    public function get_profile_context() {
        // Per-user data for profile page
        return [ 'daily_calo' => 1800, 'meals_today' => 2 ];
    }
}
```

---

> **Tổng kết**: Với standard v2.0, tạo plugin mới:
>
> 1. Copy minimal scaffold (3 files)
> 2. Thay `{slug}`, `{PREFIX}`, `{Name}`
> 3. Define: patterns (goals), plans (slots), tools (schema + callbacks)
> 4. Build profile view (guided commands)
> 5. Activate → AI Agent tự động available
>
> **3 trụ cột. Schema bắt buộc. Profile View chuẩn. 100.000+ tools.**