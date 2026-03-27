# bizcity-tool-content — Plugin Change Log

> **Role**: BizCity SDK Tool Plugin — Content / Blog Agent
> **Category**: Creative / Content
> **Platform Log**: [bizcity-intent/SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md)
> **Architecture**: [ARCHITECTURE.md](../../mu-plugins/bizcity-intent/ARCHITECTURE.md)
> **Roadmap Phase**: Phase 10 — Pipeline Orchestration + Tool Registry

---

## Plugin Status

| Item | Status |
|------|--------|
| Scaffold created | ✅ 2026-03-03 |
| Intent Provider registered | ✅ `bizcity_intent_register_providers` |
| Tool callbacks coded | ✅ 2 tools |
| `bizcity_intent_tools_ready` hook fires | ⏳ Blocked — Known Issue #11 |
| Tool name conflict (`write_article`) | ⚠️ Conflict với built-in — Issue #12 |
| INTENT-SKELETON.md | ⏳ |
| Production active | ⏳ Chờ Phase 10 engine fix |

---

## Tools trong Plugin

| Tool Name | Callback | Wraps |
|-----------|----------|-------|
| `write_article` ⚠️ | `BizCity_Tool_Content::write_article` | `ai_generate_content()` + `twf_wp_create_post()` |
| `schedule_post` | `BizCity_Tool_Content::schedule_post` | `twf_parse_schedule_post_ai()` + `wp_insert_post(future)` |

> ⚠️ `write_article` trùng với built-in trong `class-intent-tools.php`. **Phải đổi thành `content_write_article`** khi Phase 10 implement.

---

## Pipeline I/O

```
Input  ← $slots.message (topic), $slots.image_url ($step[0].data.image_url từ generate_image)
Output → data.content (body), data.title, data.url (post URL), data.image_url
         data.type = 'wp_post', data.id = post_id
```

**Pipeline chains:**
- `generate_image → data.image_url → content_write_article (image_url bìa)`
- `content_write_article → data.content, data.url → fb_post_facebook`
- `content_write_article → data.content → create_video (script)`

---

## Backlog

```
CRITICAL (Phase 10):
  [ ] Rename: write_article → content_write_article
  [ ] Rename: schedule_post → content_schedule_post
  [ ] engine fire bizcity_intent_tools_ready

HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Test pipeline: generate_image → content_write_article → fb_post_facebook

MEDIUM:
  [ ] Tone parameters: formal / casual / storytelling / SEO-optimized
  [ ] Editorial calendar integration
  [ ] Auto-excerpt generation (data.excerpt output)
```

---

## Change Log

### 2026-03-03
- Plugin scaffold tạo — 5 files, 2 tools: `write_article`, `schedule_post`
- Dependency: `bizcity-admin-hook` (content.php, lenlich.php, functions.php)
- Pipeline connector: output `data.image_url` nhận từ bizcity-tool-image upstream

---

*Ref: [bizcity-intent SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md) — Issue #11, #12, #13*

### 2026-03-12
- **[class-function-api.php]** Thêm **planner extension fields** cho cả 3 MCP tool registrations:
  - `tool_content.generate_article`: `capability_tags=[content_generation, seo, blog]`, `intent_tags=[write_article, generate_article, create_content]`, `domain_tags=[content]`
  - `tool_content.generate_image`: `capability_tags=[image_generation, cover_image]`, `intent_tags=[generate_image, write_article]`, `domain_tags=[media, content]`
  - `tool_content.publish_post`: `capability_tags=[publish, wordpress, featured_image]`, `intent_tags=[write_article, publish_post]`, `domain_tags=[content, cms]`
- Mục đích: bizcity-planner Tool Index + Tool Scorer có thể tìm và rank tools theo intent_key khi mapping ad-hoc (không cần playbook)

### 2026-03-13 — Session 3: Fix Worker Không Chạy Sau Ack

**Vấn đề phát hiện:**
1. Bot hiện "⏳ Đã nhận nhiệm vụ: Viết và đăng bài viết (3 bước)" rồi DỪNG — Worker không thực thi
2. `dispatch()` gọi `produce()` nhưng KHÔNG kick worker → tasks nằm trong queue đến khi cron tick
3. Mỗi cron tick chỉ xử lý 1 task → 3 phút tối thiểu cho 3 task (produce→kick→advance chạy tuần tự)
4. WP Cron chỉ fire khi có page load → unreliable

**3 Fixes áp dụng:**

- **[bizcity-executor] `class-intent-bridge.php`**: Thêm `error_log()` vào bridge skip để debug tại sao skip không hoạt động trên production. Log `tool_name`, `has`, `source` tại mỗi bước quyết định.

- **[bizcity-executor] `class-intent-bridge.php` dispatch()**: Sau khi `produce()` + set `$GLOBALS['bizcity_executor_claimed']`, gọi `spawn_cron()` để fire WP Cron ngay lập tức trong HTTP request riêng (non-blocking). Tasks bắt đầu xử lý ASAP thay vì đợi page load trigger cron.

- **[bizcity-executor] `class-queue-producer.php` tick()**: Thay đổi từ single-pass (produce→kick một lần) sang **tight loop** (produce→kick→check→produce→kick→... max 10 cycles, timeout 120s). Toàn bộ chain T1→T2→T3 hoàn thành trong **1 cron tick** thay vì 3 ticks.

**Decision Log:**

| Ngày | Quyết định | Lý do | Ảnh hưởng |
|------|-----------|-------|-----------|
| 2026-03-13 | `spawn_cron()` sau dispatch thay vì tight loop trong dispatch | Tight loop trong `do_action` callback blocks engine → user không thấy ack cho đến khi loop xong. `spawn_cron()` non-blocking, ack gửi ngay. | `class-intent-bridge.php` → `dispatch()` |
| 2026-03-13 | Tight loop trong `tick()` thay vì single-pass | Cho phép toàn bộ workflow chạy trong 1 cron tick. Safety: max 10 cycles + 120s timeout. | `class-queue-producer.php` → `tick()` |
| 2026-03-13 | Always error_log bridge decision | Giúp debug deployment vs code issue khi bridge skip không hoạt động | `class-intent-bridge.php` → `on_execution_detected()` |

### 2026-03-07 — Tool Input Meta & Context Injection

**Scope:** All 5 tool callbacks updated to receive `$slots['_meta']` with 6-layer dual context.

**Changes in `class-tools-content.php`:**
- `write_article()`: Extract `$meta`/`$ai_context` from `_meta`. Prepend context to `ai_generate_content()` input (Pattern B — legacy function).
- `write_seo_article()`: Extract `$meta`/`$ai_context`. Append `$ai_context` to `$sys_seo` system prompt (Pattern A — openrouter).
- `rewrite_article()`: Extract `$meta`/`$ai_context`. Append to `$sys_rewrite` system prompt.
- `translate_and_publish()`: Extract `$meta`/`$ai_context`. Append to `$sys_translate` system prompt.
- `schedule_post()`: Extract `$meta`/`$ai_context` — available for future use (uses legacy parser).

**Pattern chuẩn (mọi callback):**
```php
$meta       = $slots['_meta']    ?? [];
$ai_context = $meta['_context']  ?? '';
// ... later in AI call:
if ( $ai_context ) {
    $sys_prompt .= "\n\n" . $ai_context;
}
```

**Impact:**
- AI viết/dịch/viết lại bài giờ nhận được ngữ cảnh hội thoại → respond đúng context
- Không breaking change — `_meta` luôn có default `[]`
