# BizCity Intent — Roadmap

> Lõi điều phối (Team Leader) của nền tảng AI Agentic.
> Nhận prompt → Phân loại mode → Lập plan → Giao việc → Trả kết quả.

---

## Tổng quan các Phase

| Phase | Tên | Output chính | Ưu tiên |
|---|---|---|---|
| **P1** | Mode Classifier | Phân loại mode từ user message | 🔴 Critical ✅ |
| **P2** | Intent Engine | Intent → slots → tool/pipeline dispatch | 🔴 Critical ✅ |
| **P3** | Conversation Store | Lưu conversation, turns, slots trong DB | 🔴 Critical ✅ |
| **P4** | Pipeline System | Mode-specific pipelines (chat, planning, execution...) | 🔴 Critical ✅ |
| **P5** | 6-Layer Context Chain | Context layers: profile, history, knowledge, emotion... | 🟠 High ✅ |
| **P6** | Planner Bridge | Kết nối intent → planner → executor pipeline | 🟠 High ✅ |
| **P7** | Intent Monitor | Top-level admin SPA dashboard | 🟠 High ✅ |
| **P8** | Intent Data Browser | 11 sub-menus: 4 intent + 7 planner tables | 🟠 High ✅ |
| **P9** | Inline Expand | Expand row → xem related data inline (intent ↔ executor) | 🟠 High ✅ |
| **P10** | Tool Input Meta | Context injection to tool callbacks (`_meta`) | 🟠 High ✅ |
| **P10b** | Tool Registry & DB Index | DB-persisted tool index + LLM manifest | 🟠 High ✅ |
| **P10c** | Tool Control Panel | Admin UI data-driven routing | 🟠 High ✅ |
| **P10e** | Unified Single-Call Classification | 3 LLM calls → 1, focused top-N, regex bias | 🔴 Critical ✅ |
| **P11** | Human-in-Loop Optimization | Slot-filling accuracy, skip/image/validation | 🔴 Critical ✅ |
| **P12** | Knowledge Fabric | Multi-scope knowledge (user/project/session/agent) | 🟠 High 🔄 |
| **P12.1** | LLM Slot Bridge | Intelligent provide_input extraction + retry/cancel | 🔴 Critical ✅ |
| **P13** | Dual Context Architecture | Emotion Context vs Tool Context, / slash command, tool search | 🔴 Critical 🔄 |
| **P14** | Tool Intelligence Layer | Smart Prediction, Compose Fix, Registry Search, SQL Fix | 🔴 Critical ✅ |
| **P15** | 5-Mode & Knowledge Cleanup | MODE_AMBIGUOUS, deprecate Knowledge Router, clean knowledge prompt | 🔴 Critical ✅ |
| **P16** | Image+Text Smart Routing | Step 1.5C bypass, Step 1.7 suggest-confirm, _images→slot mapping | 🔴 Critical ✅ |
| **P17** | Slot Confirm & Image Fix | Router image guard, planner confirm guard, engine confirm_pending | 🔴 Critical ✅ |
| **P18** | 3-Memory Architecture | Rolling Summary, Episodic Memory, 8-layer context, token tracking | 🟠 High ✅ |
| **P19** | Webchat UI Overhaul + Stream Fix + Model Migration | Markdown/Media/Mermaid rendering, stream truncation fix, minimax→flash, tool suggest off | 🔴 Critical ✅ |
| **P20** | Slot Auto-Map Fix & Accept-Image | no_auto_map flag, accept_image flag, trigger-strip extraction, 6 router guard fixes | 🟠 High ✅ |
| **P21** | HIL Robustness Review | Off-topic escape, type guard, 6-issue audit | 🔴 Critical ✅ |
| **P22** | Twin Core Phase 0 | Context cleanup, tool suggestion removal, Twin Suggest | 🟠 High ✅ |
| **P23** | HIL Pre-confirm Pipeline Hardening | Objective Understanding dedup fix, Router skip for content resume, 3-part defense-in-depth | 🔴 Critical ✅ |

---

## Phase 1 — Mode Classifier ✅

**Mục tiêu:** Phân loại user message thành mode (chat, execution, planning, emotion, natachart...).

### Deliverables
- `includes/class-mode-classifier.php` — Tier 1 rule-based + Tier 2 AI override

### Completed Tasks
- [x] `classify( $message, $context )` → `{ mode, confidence, intent_key, slots }`
- [x] Tier 1: rule-based fast path (patterns, keywords)
- [x] Tier 2: AI provider fallback nếu confidence thấp
- [x] Mode routing: chat, execution, planning, emotion, natachart, astro_forecast...

---

## Phase 2 — Intent Engine ✅

**Mục tiêu:** Xử lý message theo intent: detect intent_key, extract slots, dispatch tool.

### Deliverables
- `includes/class-intent-engine.php` — Core engine
- `includes/class-intent-router.php` — Route intent → goal pattern → pipeline
- `includes/class-intent-stream.php` — Stream interface cho bot-webchat

### Completed Tasks
- [x] `bizcity_intent_process()` — main entry point
- [x] Multi-turn conversation support (slots accumulation)
- [x] Provider abstraction: OpenRouter, Gemini, ChatGPT
- [x] message_id propagation through intent → engine → pipeline → trace

---

## Phase 3 — Conversation Store ✅

**Mục tiêu:** Persistent storage cho conversations, turns, prompt logs, debug logs.

### DB Tables
| Bảng | Mục đích |
|---|---|
| `bizcity_intent_conversations` | Session/goal tracking per conversation |
| `bizcity_intent_turns` | User + assistant messages với intent/slots |
| `bizcity_intent_prompt_logs` | Provider calls, tokens, timing |
| `bizcity_intent_logs` | Step-by-step debug trace |

### Completed Tasks
- [x] `class-conversation-store.php` — CRUD conversations
- [x] `class-turn-store.php` — Insert/list turns
- [x] `class-prompt-log-store.php` — Log provider calls
- [x] Auto-resolve conversation goal/status from turns

---

## Phase 4 — Pipeline System ✅

**Mục tiêu:** Mode-specific processing pipelines.

### Deliverables
- `includes/class-mode-pipeline.php` — Base pipeline interface
- `includes/class-planning-pipeline.php` — Planning mode handler
- `includes/class-execution-pipeline.php` — Execution mode handler

### Completed Tasks
- [x] Pipeline architecture: mode → pipeline class → process()
- [x] Hook system: `bizcity_intent_plan_request`, `bizcity_intent_execution_detected`
- [x] Context propagation: conversation_id, session_id, message_id through pipeline

---

## Phase 5 — 6-Layer Context Chain ✅

**Mục tiêu:** Rich context building cho AI providers.

### 6 Layers
1. **System** — System prompt, character personality
2. **Profile** — User profile, preferences, communication style
3. **History** — Recent conversation turns
4. **Knowledge** — RAG from knowledge base
5. **Emotion** — Sentiment analysis, emotional state
6. **Selective** — Only send relevant layers to save tokens

### Completed Tasks
- [x] Context chain builder with layer composition
- [x] Selective context: only include relevant layers per mode
- [x] Token budget management across layers

---

## Phase 6 — Planner Bridge ✅

**Mục tiêu:** Kết nối intent → planner → executor thành pipeline hoàn chỉnh.

### Flow
```
User message
 → Mode Classifier → mode = planning
   → Planning Pipeline
     → do_action('bizcity_intent_plan_request')
       → Planner: classify → map → playbook → plan
         → do_action('bizcity_planner_plan_ready')
           → Intent Bridge → Executor (dispatch trace + tasks)
```

### Completed Tasks
- [x] `BizCity_Intent_Bridge` — nhận plan từ planner, dispatch executor
- [x] Enrich context: intent_key, playbook_key, variant, source
- [x] Fallback: nếu planner không installed → AI compose bình thường

---

## Phase 7 — Intent Monitor ✅

**Mục tiêu:** Top-level admin SPA dashboard cho Intent system.

### Admin Menu
- **Intent Monitor** — position 72, `dashicons-analytics`, slug `bizcity-intent-monitor`

### Deliverables
- `includes/class-intent-monitor.php` — Admin SPA dashboard
- Tabs: Conversations, Turns, Prompt Logs, Debug Logs, Executor Debug

### Completed Tasks
- [x] `add_menu_page()` — top-level admin menu
- [x] SPA dashboard với AJAX polling
- [x] Tab "⚡ Executor" — hiển thị executor traces + task stats
- [x] AJAX endpoint `bizcity_intent_monitor_exec_debug`

---

## Phase 8 — Intent Data Browser (v3.4.0) ✅

**Mục tiêu:** Admin data browser cho 11 bảng: 4 intent + 7 planner tables.

### Deliverables
- `includes/class-intent-data-browser.php` — 11 sub-menu pages, AJAX handlers
- `views/data-browser.php` — HTML template
- `assets/data-browser.js` — Client-side: AJAX, pagination, filter, sort, export, modal
- `assets/data-browser.css` — Styles, status badges, cross-link chips

### 11 Sub-menus
| Section | Slug | Bảng DB |
|---|---|---|
| Intent | `bizcity-idb-int-conversations` | `bizcity_intent_conversations` |
| Intent | `bizcity-idb-int-turns` | `bizcity_intent_turns` |
| Intent | `bizcity-idb-int-prompt-logs` | `bizcity_intent_prompt_logs` |
| Intent | `bizcity-idb-int-logs` | `bizcity_intent_logs` |
| Planner | `bizcity-idb-plan-candidates` | `bizcity_intent_candidates` |
| Planner | `bizcity-idb-plan-playbooks` | `bizcity_playbooks` |
| Planner | `bizcity-idb-plan-experiments` | `bizcity_tool_experiments` |
| Planner | `bizcity-idb-plan-tool-stats` | `bizcity_tool_stats` |
| Planner | `bizcity-idb-plan-reviews` | `bizcity_trace_reviews` |
| Planner | `bizcity-idb-plan-patches` | `bizcity_registry_patches` |
| Planner | `bizcity-idb-plan-cache` | `bizcity_planner_cache` |

### Tính năng
- [x] Paginated list với filters & free-text search
- [x] Column sorting (click header)
- [x] Export JSON (single table hoặc kèm related records)
- [x] Cross-table navigation: conversation_id, session_id, intent_key → nhảy bảng
- [x] Checkbox select + bulk delete
- [x] Record detail modal + quick-link chips
- [x] CSS badges cho intent + planner statuses (active, draft, paused, proposed, approved, rejected...)
- [x] Cross-links to Executor Monitor pages (executor_trace_id → traces)

### Files
| File | Vai trò |
|---|---|
| `includes/class-intent-data-browser.php` | 11 page configs, 5 AJAX endpoints, query builder |
| `views/data-browser.php` | HTML shell (breadcrumb, filters, table, pagination, modal) |
| `assets/data-browser.js` | Generic client: configurable via `BizIntentBrowser` global |
| `assets/data-browser.css` | Full CSS với intent + planner badges |

---

## Phase 9 — Inline Expand (v3.5.0) ✅

**Mục tiêu:** Nút ▶ expand trên mỗi row để xem related data inline mà không cần mở modal hay rời trang.

### Tính năng
Bấm ▶ trên row → hiển thị panel bên dưới row với tabbed view chứa dữ liệu liên quan:

### Lookup Paths (dựa trên FK columns của row)
| FK Column | Related Data |
|---|---|
| `conversation_id` | → Conversations, Turns, Prompt Logs, Debug Logs, Executor Traces + Tasks |
| `executor_trace_id` | → Executor Trace + Trace Tasks |
| `message_id` | → Executor Traces (by message_id) + Trace Tasks |
| `session_id` | → Conversations, Prompt Logs |
| `intent_key` | → Planner Candidates, Playbooks |
| `trace_id` | → Executor Trace + Trace Tasks |

### Cross-system Tracing
- Intent tables ↔ Executor tables: link qua `conversation_id`, `message_id`, `executor_trace_id`
- Intent tables ↔ Planner tables: link qua `intent_key`, `playbook_key`
- Expand panel mini-tables có cross-links clickable (trace_id → Executor traces, task_id → Executor tasks...)

### UI/UX
- Tab navigation with count badges
- "View all →" button per tab linking to full data browser page
- Scrollable mini-tables with sticky headers
- AJAX data caching: chỉ fetch 1 lần per row
- Toggle: click ▶ expand, click ▼ collapse

### Files modified
| File | Changes |
|---|---|
| `includes/class-intent-data-browser.php` | +AJAX `bizcity_intent_expand` endpoint, `ajax_expand()` method |
| `assets/data-browser.js` | +expand button, toggle, renderExpandPanel(), renderMiniTable(), tab logic |
| `assets/data-browser.css` | +expand panel, tabs, mini-table, status badges trong expand view |

### Completed Tasks
- [x] AJAX endpoint `bizcity_intent_expand` — fetch related by FK columns
- [x] Expand by `conversation_id` → turns, prompt_logs, debug_logs, executor traces + tasks
- [x] Expand by `executor_trace_id` → executor trace + tasks
- [x] Expand by `message_id` → executor traces (by message_id) + tasks
- [x] Expand by `session_id` → conversations, prompt_logs
- [x] Expand by `intent_key` → candidates, playbooks
- [x] Expand by `trace_id` → executor trace + tasks
- [x] Tabbed expand panel with count badges
- [x] Mini-table rendering with cross-links
- [x] Client-side expand data caching
- [x] CSS: expand panel, tab navigation, mini-tables

---

## Admin Menu Architecture

```
WordPress Admin
 ├─ Intent Monitor (position 72, dashicons-analytics)
 │   ├─ SPA Dashboard (conversations, turns, stats, executor debug)
 │   ├─ 💬 Conversations (bizcity-idb-int-conversations)
 │   ├─ 🔄 Turns (bizcity-idb-int-turns)
 │   ├─ 📝 Prompt Logs (bizcity-idb-int-prompt-logs)
 │   ├─ 🐛 Debug Logs (bizcity-idb-int-logs)
 │   ├─ 🎯 Candidates (bizcity-idb-plan-candidates)
 │   ├─ 📖 Playbooks (bizcity-idb-plan-playbooks)
 │   ├─ 🧪 Experiments (bizcity-idb-plan-experiments)
 │   ├─ 📊 Tool Stats (bizcity-idb-plan-tool-stats)
 │   ├─ ✅ Reviews (bizcity-idb-plan-reviews)
 │   ├─ 🩹 Patches (bizcity-idb-plan-patches)
 │   ├─ 💾 Plan Cache (bizcity-idb-plan-cache)
 │   └─ 🎛️ Control Panel (bizcity-tool-control-panel)
 │
 ├─ Planner Monitor (position 58, dashicons-analytics)
 │   └─ Tabs: Playbooks, Experiments, Patches, Tool Stats, Trace Reviews
 │
 └─ Executor Monitor (position 73, dashicons-controls-repeat)
     ├─ SPA Dashboard (traces overview, retry, cancel)
     ├─ ⚙ Traces (bizcity-exec-traces)
     ├─ ⚙ Tasks (bizcity-exec-trace-tasks)
     ├─ ⚙ Runs (bizcity-exec-trace-runs)
     ├─ ⚙ Queue (bizcity-exec-trace-queue)
     ├─ ⚙ Artifacts (bizcity-exec-trace-artifacts)
     ├─ ⚙ Idempotency (bizcity-exec-trace-idempotency)
     ├─ ⚙ Signals (bizcity-exec-resume-signals)
     └─ ⚙ Tool Registry (bizcity-exec-tool-registry)
```

---

## Phase 10 — Tool Input Meta & Context Injection (v3.5.1) ✅

**Mục tiêu:** Đảm bảo mọi tool callback nhận `$slots['_meta']` chuẩn hóa, chứa dual context 6 lớp để tool thực thi context-aware.

### Deliverables
- `class-context-builder.php` — `build_tool_context(int $max_length = 1200): string`
- `class-intent-engine.php` — `_meta` injection block in `call_tool` case
- All tool plugins updated with `_meta` extraction pattern

### Completed Tasks
- [x] `build_tool_context()` method: export layers 2→5 as compact text (max 1200 chars)
- [x] `_meta` injection in Engine: `_context`, `conv_id`, `goal`, `goal_label`, `character_id`, `channel`, `message_id`, `user_display`, `blog_id`
- [x] bizcity-tool-content: 5 callbacks updated (`write_article`, `write_seo_article`, `rewrite_article`, `translate_and_publish`, `schedule_post`)
- [x] bizcity-tool-woo: 4 callbacks updated (`create_product`, `edit_product`, `create_order`, `warehouse_receipt`)
- [x] bizcoach-map: Audited — 7 tools are data-fetching, no AI calls → `_meta` passthrough, no changes needed
- [x] Verified `validate_inputs()` does not interfere with `_meta` (not in `input_fields` schema)
- [x] 3 context injection patterns documented (Pattern A: openrouter, Pattern B: legacy fn, Pattern C: data-only)

### `_meta` Schema

```php
$slots['_meta'] = [
    '_context'        => string,  // Dual context layers 2-5 (max 1200 chars)
    '_tools_manifest' => string,  // Tool Registry manifest — all active tools (max 800 chars)
    'conv_id'         => string,  // Intent conversation UUID
    'goal'            => string,  // Goal identifier
    'goal_label'      => string,  // Human-readable label
    'character_id'    => string,  // AI character binding
    'channel'         => string,  // webchat | adminchat | telegram | zalo
    'message_id'      => string,  // Original user message ID
    'user_display'    => string,  // User display name
    'blog_id'         => int,     // Multisite blog ID
];
```

---

## Phase 10b — Tool Registry & DB-persisted Tool Index (v3.5.2) ✅

**Mục tiêu:** Khi plugin được kích hoạt, tự đăng ký toàn bộ tools/goals/scenarios/required fields vào DB.
AI Team Leader **luôn biết mình có những gì** thông qua Tool Registry manifest inject vào LLM prompt.

### Deliverables
- `class-intent-tool-index.php` — **BizCity_Intent_Tool_Index** singleton: DDL + `sync_all()` + `build_tools_context()`
- `class-intent-provider-registry.php` — boot() Step 5: `sync_all($providers)` tự động sau khi register tools
- `class-intent-router.php` — `classify_with_llm_primary()` + tool manifest trong system prompt
- `class-intent-engine.php` — `_meta._tools_manifest` cho tool callbacks

### Completed Tasks
- [x] `BizCity_Intent_Tool_Index` class: singleton, `maybe_create_table()` (idempotent DDL)
- [x] `sync_all(array $providers)`: iterate providers + built-in tools → UPSERT → deactivate stale
- [x] `build_tools_context(int $max_length)`: compact manifest text, cached via transient (1h TTL)
- [x] `Provider_Registry::boot()` Step 5 — gọi `sync_all()` sau khi merge tools
- [x] `bootstrap.php` — require_once `class-intent-tool-index.php`
- [x] Router injection — tool manifest appended vào LLM system prompt (max 1200 chars)
- [x] Engine `_meta._tools_manifest` — tool callbacks nhận tool ecosystem info (max 800 chars)
- [x] ARCHITECTURE.md — Section 10c + `_meta` schema table + Phase 10.5b + TOC

### DB Schema: `bizcity_tool_registry`
```
tool_key (UNIQUE), tool_name, plugin, title, description,
goal, goal_label, goal_description, required_slots (JSON), optional_slots (JSON),
input_schema (JSON), output_schema (JSON), callback, capability_tags,
intent_tags, domain_tags, version, active (0/1)
```

### Manifest Format (inject vào LLM)
```
## 🔧 CÔNG CỤ HIỆN CÓ (Tool Registry — N tools)
1. tool_name [plugin] — description | cần: field1*, field2* | tùy chọn: field3
2. ...
```

### Next Steps
- [ ] Monitor: hiển thị `get_counts_by_plugin()` trong Tools tab
- [ ] WP-CLI: `wp bizcity tool-index sync` — manual re-sync command
- [ ] Auto-sync on `activated_plugin` / `deactivated_plugin` hooks (ngoài boot cycle)
- [ ] Planner reads tool list từ Tool Index thay vì hardcode

---

## Phase 10c — Tool Control Panel (v3.7.0) ✅

**Mục tiêu:** Thay thế hardcode routing rules bằng **admin UI data-driven** — admin xem/edit prompt mapping, keyword hints, priority ordering cho mọi tool trong hệ thống.

> **Nguyên tắc:** "Data > Code" — admin chỉnh UI → DB update → transient flush → LLM prompt tự cập nhật.

### Deliverables
| File | Vai trò |
|---|---|
| `class-intent-tool-index.php` | Migration 3: `priority`, `custom_hints`, `custom_description` columns |
| `class-tool-control-panel.php` | **NEW** — Admin page 4 tabs: Tools, Preview Prompt, Flow Diagram, Stats |
| `class-intent-router.php` | Data-driven `build_goal_list_compact()` + priority sorting + hints injection |

### DB Schema Extension (Migration 3)
| Column | Type | Purpose |
|---|---|---|
| `priority` | INT DEFAULT 50 | Thứ tự ưu tiên (lower = higher priority) |
| `custom_hints` | TEXT | Keyword hints `[khi nói: ...]` inject vào LLM prompt |
| `custom_description` | TEXT | Override goal_description trong LLM prompt |

### Admin UI — 4 Tabs
1. **🔧 Công cụ** — Drag-to-reorder, inline edit priority/description/hints, toggle active/inactive
2. **👁️ Preview Prompt** — Xem trước LLM goal list prompt chính xác như Router gửi cho AI
3. **📊 Flow Diagram** — Mermaid.js graph TD: User → Router → Plugin subgraphs → Tools
4. **📈 Thống kê** — Tool counts by plugin, active/inactive summary

### LLM Integration
- `build_goal_list_compact()`: sorts by `priority ASC`, uses `custom_description > goal_description`, appends `[khi nói: ...]` hints
- `build_unified_schema_for_llm()`: same priority + custom desc + hints logic
- QUY TẮC rule #5: Data-driven — "Nếu goal có `[khi nói: ...]` → khi user dùng từ khóa đó → ưu tiên goal này"

### Completed Tasks
- [x] Migration 3: 3 new columns + `idx_priority` index
- [x] Tool Index: 7 new admin methods (CRUD, batch priority, mermaid, effective description)
- [x] Control Panel admin page: 4 tabs, 7 AJAX endpoints, inline CSS/JS
- [x] Router: data-driven goal list, priority sorting, custom descriptions, hints injection
- [x] Bootstrap: registered `class-tool-control-panel.php` + singleton instance
- [x] ARCHITECTURE.md: section 10d + TOC + checklist update
- [x] ROADMAP.md: Admin Menu tree + Phase 10c + Timeline update
- [x] Touchbar: 🎛️ Control Panel + 📊 Tool Stats added (admin `manage_options` only)

---

## Phase 10e — Unified Single-Call Classification (v3.8.0) ✅

**Mục tiêu:** Gộp 3 LLM calls (Mode Classifier + Router Tier 1 + Router Tier 2) thành **1 unified LLM call** — giảm latency 60-70%, giảm token 40-50%, giữ chính xác.

> **Nguyên tắc:** Regex là bias hint (không bypass), LLM là quyết định cuối cùng.
> Focused top-N schema (configurable qua UI) giúp prompt ngắn hơn, chính xác hơn.

### Deliverables
| File | Vai trò |
|---|---|
| `class-mode-classifier.php` | Regex bypass → bias hint; `classify_with_llm()` rewrite với focused schema + inline entity extraction |
| `class-intent-router.php` | 3 methods mới: `build_focused_schema_for_llm()`, `parse_tool_slot_names_with_types()`, `get_top_n_tools()` |
| `class-tool-control-panel.php` | AJAX `ajax_save_settings()` + Settings UI trong Stats tab |
| `page-tool-control-panel.php` | Settings UI (standalone) + JS handler |

### So sánh Architecture

| Metric | v3.7.0 (3 calls) | v3.8.0 (1 call) | Cải thiện |
|---|:-:|:-:|:-:|
| LLM round-trips | 3 | **1** | -67% |
| Input tokens | 1600-2400 | **1100-1500** | -40-50% |
| Latency (Gemini Flash) | 600-1200ms | **200-400ms** | -60-70% |
| Cost per request | ~$0.0003 | **~$0.0001** | -67% |
| Entity extraction | Separate call | **Inline** | 0 extra cost |

### Lưu đồ mới
```
User message
  │
  ├─ [Regex] Goal pre-match (0ms, 0 cost) → bias hint
  │
  ├─ [Cache] SQL classify cache check → hit? return
  │
  ├─ [LLM—UNIFIED] classify_with_llm()
  │   • Focused top-N schema (★ regex hint, type hints)
  │   • BƯỚC 1: mode | BƯỚC 2: intent+goal | BƯỚC 2b: entities | BƯỚC 3: memory
  │   → { mode, intent, goal, entities, filled_slots, missing_slots }
  │
  ├─ [Post-process] Validate goal + sanitize entities
  │
  └─ [Planner] Check slots → ask_user / call_tool
```

### Completed Tasks
- [x] `build_focused_schema_for_llm()` — top-N tools, ★ marker, type hints, token budget
- [x] `parse_tool_slot_names_with_types()` — `name(type:choice1,choice2)` format
- [x] `get_top_n_tools()` — reads `bizcity_tcp_top_n_tools` wp_option
- [x] Regex pre-match changed: bypass → bias hint (set `$regex_likely_goal`)
- [x] `classify_with_llm()` rewritten: accepts `$regex_likely_goal`, focused schema, inline slot extraction
- [x] AJAX `bizcity_tcp_save_settings` endpoint + `update_option()` + transient clear
- [x] Admin page: "⚙️ Cấu hình LLM Prompt" settings section in Stats tab
- [x] Standalone page: Same settings UI + JS handler
- [x] Router Step 0.5: Tier 2 entity extraction fallback when entities empty
- [x] ARCHITECTURE.md: Section 10e + orchestration lifecycle diagram update
- [x] ROADMAP.md: Phase 10e + timeline update
- [x] SYSTEM-LOG.md: Daily log + decision log + version bump

---

## Phase 10f — Dual-Logic Tool Routing & Plugin Gathering (v3.9.0) ✅

**Mục tiêu:** Thiết lập **hai luồng routing song song** — Logic 1 (Registry LLM full-scan) cho automatic classification, Logic 2 (@Tag Direct) cho explicit user @mention — với Plugin Gathering class, plugin_slug DB tracking, và UI badge hiển thị real-time.

> **Nguyên tắc:** Logic 2 (@Tag) luôn ưu tiên (explicit user intent > automatic). Logic 1 là fallback khi user không chỉ định plugin.
> Sau khi goal hoàn thành → auto-cycle: tìm goal mới (quay lại Logic 1 hoặc chờ @Tag mới).

### Vấn đề Phase 10e chưa giải quyết

Pipeline trace với 36+ tools cho thấy LLM accuracy thấp:
- "dự đoán bản đồ sao" → `mode=knowledge conf=0.9` (sai, phải là execution)
- Intent classify → `tarot_reading` (sai, phải là `daily_outlook`/`astro_forecast`)
- Root cause: 36+ tool descriptions quá rộng → LLM confused

### Giải pháp: Dual-Logic

| | Logic 1 (Registry LLM) | Logic 2 (@Tag Direct) |
|---|---|---|
| Trigger | User gõ message bình thường | User @mention plugin_slug |
| Tool scope | 36+ (full registry) | 1-6 (provider's tools only) |
| LLM calls | 1 unified call (v3.8.0) | 0-1 (skip nếu single-goal) |
| Accuracy | ~60-70% (36+ tools) | ~95% (narrow scope) |
| Cost | ~$0.0001/request | $0 (single-goal) → ~$0.00005 (multi-goal) |
| Filter | Priority 5 (Intent Engine) | Priority 2 (Plugin Gathering) |

### Deliverables
| File | Plugin | Vai trò |
|---|---|---|
| `class-plugin-gathering.php` | bizcity-bot-webchat | Core: Tool Registry lookup, state management, slot filling, execution bridge |
| `class-webchat-database.php` | bizcity-bot-webchat | Migration v3.2.0: `plugin_slug` column + composite indexes |
| `class-chat-gateway.php` | bizcity-knowledge | Parse plugin_slug from POST, log to DB, echo in response |
| `class-intent-engine.php` | bizcity-intent | Step 2.0: provider_hint → force MODE_EXECUTION + conf 0.95 |
| `class-intent-router.php` | bizcity-intent | Step 0: single-goal provider skip LLM entirely (0 cost) |
| `class-admin-dashboard.php` | bizcity-bot-webchat | @mention UI, floating indicator removal, plugin badge during loading |
| `ARCHITECTURE.md` | bizcity-intent | §10f rewrite: 12 sub-sections, dual-logic documentation |

### Completed Tasks
- [x] `class-plugin-gathering.php` — Singleton, filter hook @2, init/continue/process
- [x] Tool Registry lookup: `get_tools_for_plugin()`, `get_tool_by_goal()`, `parse_tool_schema()`
- [x] State management: transient-based, 30min TTL, session_id + plugin_slug key
- [x] Slot filling: extract entities → merge → recalculate missing → ask or execute
- [x] Execution bridge: `BizCity_Intent_Tools::execute()` or direct callback
- [x] Auto-continue: detect active gathering state even without @ mention
- [x] Cancel/skip detection: "hủy", "bỏ qua", "skip", etc.
- [x] DB Migration v3.2.0: SCHEMA_VERSION bump, `plugin_slug` column + 2 indexes
- [x] `class-webchat-database.php`: `get_messages_by_session_and_plugin()`, `get_last_plugin_slug_in_session()`
- [x] `class-chat-gateway.php`: Log bot reply in pre_reply path with plugin_slug
- [x] `class-intent-engine.php`: Step 2.0 provider_hint + Step 2.0 force MODE_EXECUTION
- [x] `class-intent-router.php`: Step 0 single-goal skip LLM
- [x] @mention autocomplete UI (Touch Bar data, dropdown, keyboard navigation)
- [x] Plugin context header in input area
- [x] Remove floating indicator div + cleanup JS functions
- [x] Plugin badge during loading: typing indicator + SSE first-chunk + AJAX fallback
- [x] ARCHITECTURE.md §10f: 12 sub-sections comprehensive dual-logic documentation
- [x] SYSTEM-LOG.md: Daily log + decision log + version bump
- [x] ROADMAP.md: Phase 10f + timeline update
- [ ] LLM-assisted entity extraction (replace regex for complex slots)
- [ ] Multi-tool gathering (user switches tools mid-session)
- [ ] Gathering progress UI in frontend (visual slot tracker)
- [ ] Webchat widget (public side) support — currently ADMINCHAT only

---

## Phase 11 — Human-in-Loop Slot-Filling Optimization (v3.6.3) ✅

**Mục tiêu:** Tối ưu vòng lặp cốt lõi: Tool Registry → Intent Classification → Conversation Tracking → Slot Gathering (Human-in-Loop) → Tool Execution → Verify Done.

> **Milestone quan trọng**: Đảm bảo assistant luôn hỏi đúng, trong phạm vi Intent Goal,
> cho đến khi fill đủ slots → kick call_tool → execute → verify → complete → sẵn sàng goal mới.

### Core Loop Đã Hoạt Động

```
User Message
  → [1] Tool Registry: AI biết mình có gì (N tools manifest)
  → [2] Mode+Intent Classifier: xác định goal + extract entities
  → [3] Conversation Mgr: tạo/resume conversation_id, lưu goal + slots
  → [4] Planner: so sánh slots vs plan schema
       ├─ Missing required? → ask_user (field + prompt)
       ├─ Missing optional in slot_order? → ask_user
       └─ All filled → call_tool ($tool_slots complete)
  → [5] set_waiting(type, field) → WAITING_USER
  → [6] User reply → Router classify provide_input → fill slot
  → [7] Loop back to [4] until all slots filled
  → [8] call_tool → execute function → Tool Output Envelope
  → [9] Verify: success + complete → COMPLETED
  → [10] Ready for next intent goal
```

### Bug Fixes Đã Apply (v3.5.2 → v3.6.0)

| # | Bug | File | Fix |
|---|-----|------|-----|
| B1 | Image stored as array not string | `class-intent-engine.php` Step 4c | `update_slots()` for non-array slots, `append_slot()` only for `is_array` |
| B2 | "bỏ qua" loops forever | `class-intent-engine.php` Step 4c | Preserve `$prev_waiting_field` before `resume()`, re-inject for Planner |
| B3 | `$ai_result['content']` wrong key | `class-tools-content.php`, `class-tools-woo.php` | Changed to `$ai_result['message']` (8 methods) |
| B4 | Tool receives array for image_url | `class-tools-content.php` | `is_array()` safety: `$image_url[0] ?? ''` |

### Optimization Tasks (Next Steps)

#### Sprint 1: Slot Accuracy & Validation (🔴 Critical)

- [x] **O1: Deduplicate Planner built-in plans**
  - Problem: Planner hardcodes 20 plans (write_article, create_product...) that plugin providers also register
  - Fix: Keep only generic plans (report, help_guide, daily_outlook) in built-in. Remove all domain-specific plans that plugins provide
  - Files: `class-intent-planner.php` `init_plans()`

- [x] **O2: Plugin plan skip_on support**
  - Problem: `skip_on` phrases only in built-in plans, plugin plans miss them
  - Fix: Planner `plan()` method should check `skip_on` array in optional_slots config universally
  - Add default skip phrases when `type = 'image'` + `'default' => ''`: auto-add `['bỏ qua', 'skip', 'tự tạo', 'không', 'auto']`
  - Files: `class-intent-planner.php` `plan()` optional slot loop

- [x] **O3: Inject plan schema into LLM classify prompt**
  - Problem: LLM doesn't know which slot names to extract → entities miss slot keys
  - Fix: When `has_active_goal` + `WAITING_USER`, inject `"Expected fields: topic (text), image_url (image), tone (choice: casual/professional/friendly)"` into LLM system prompt
  - Files: `class-intent-router.php` `classify_with_llm_primary()`

- [x] **O9: Slot value validation before call_tool**
  - Problem: Invalid slot values (price = "abc", url = "not a url") cause tool runtime failures
  - Fix: Planner validates each slot against type rules before returning `call_tool`
  - Validation rules: `text` → non-empty, `number` → `is_numeric()`, `image` → `filter_var(URL)`, `choice` → value in choices array
  - If invalid → `ask_user` with specific error: "Giá phải là số, bạn nhập lại nhé"
  - Files: `class-intent-planner.php` new `validate_slot()` method

#### Sprint 2: UX & Intelligence (🟡 Medium)

- [x] **O4: Single-turn multi-slot extraction**
  - "Viết bài về AI, ảnh tự tạo nhé" → extract topic="AI" AND skip image_url
  - Planner post-process: scan message for skip phrases against all optional slots, not just waiting_field
  - New `message_implies_skip()` method: type-specific contextual patterns (image: "ảnh tự tạo", "ko cần ảnh") + generic field_name+skip_verb
  - Files: `class-intent-planner.php` + `class-intent-engine.php` (raw_message passthrough)

- [x] **O5: Smart slot ordering**
  - Adapt `slot_order` based on what's already filled (skip already-provided slots)
  - If primary content slot (topic/message/content) >100 chars → auto-fill secondary optionals (tone/length/style/format) with defaults
  - Files: `class-intent-planner.php` `plan()` optional loop

- [x] **O8: Context-aware re-ask on tool missing_fields**
  - Tool returns `missing_fields: ['topic']` → Engine builds filled slots summary from plan schema
  - Appends "(Đã có: topic: \"AI\", tone: \"casual\")" to raw_prompt before LLM smoothing
  - Files: `class-intent-engine.php` tool result handler

- [x] **O10: Conversation stale cleanup**
  - WP-Cron hourly schedule for reliable stale cleanup (not dependent on web requests)
  - `find_expired_conversation()` SQL: finds EXPIRED/CLOSED with goal within 2h for session resumption
  - Files: `bootstrap.php`, `class-intent-conversation.php`, `class-intent-database.php`

- [x] **NEW: HIL Checklist Tab in Intent Monitor**
  - Todo-style checklist showing real-time slot-filling progress per conversation
  - Slot status icons: ✅ filled, ⏳ waiting, ⬜ pending, ⏭️ skipped
  - Progress bar per conversation, filters (status/channel/search), 15s auto-refresh
  - Inline turns viewer (expand/collapse)
  - Files: `class-intent-monitor.php` (CSS + HTML + JS + AJAX endpoint + backend handler)

#### Sprint 3: Cross-Goal Intelligence (🟢 Done)

- [x] **O6: Cross-goal slot inheritance**
  - Khi user switch goal A→B, nếu B có cùng slot names (topic, image_url) → auto-inherit values
  - LLM-extracted entities take priority, then inherited, then empty
  - Logs inheritance via `cross_goal_inherit` log event
  - Files: `class-intent-engine.php` Step 4b new_goal handler

- [x] **Conversation analytics dashboard**
  - Widget trong Intent Monitor Overview: Slot Fill Rate by Goal, Most Abandoned Goals, Waiting count
  - SQL analytics: avg fill rate, avg turns, execution count per goal
  - Files: `class-intent-monitor.php` (AJAX `ajax_stats` + JS `renderStats`)

- [x] **Slot fill rate tracking**
  - Log slot completeness at call_tool time: `{goal, total_slots, filled_count, fill_rate, turns_taken}`
  - Stored as `slot_fill_rate` step in intent_logs table for analytics queries
  - Files: `class-intent-engine.php` call_tool case

- [x] **HIL Checklist tab in Webchat Router Console**
  - 4th tab "✅ HIL Slots" in dev admin webchat router console
  - Real-time slot-filling todo checklist (via `bizcity_intent_monitor_hil` AJAX)
  - Slot status icons: ✅ filled, ⏳ waiting, ⬜ pending, ⏭️ skipped
  - Progress bar per conversation, auto-refresh 15s
  - Files: `class-admin-dashboard.php` (bizcity-bot-webchat)

- [x] **Codebase cleanup: remove planning/coding mode dead code**
  - Removed `MODE_PLANNING` + `MODE_CODING` constants from mode-classifier
  - Cleaned stale comments in engine (6 modes → 4 modes)
  - Removed planning/coding filter options from Intent Monitor
  - Updated mode-pipeline doc block
  - Files: `class-mode-classifier.php`, `class-intent-engine.php`, `class-intent-monitor.php`, `class-mode-pipeline.php`

#### Hotfix: v3.6.3 — Image+Text Fix + Attachment-First Flow

- [x] **Image+Text Slot Loss Fix**
  - Problem: Router `if(image)/elseif(text)` was mutually exclusive — "giá 120k" + image → text lost, product created with "Chưa set giá"
  - Fix: 3 locations (Step 0.5, Step 2, Step 3a) now call `extract_text_entities_alongside_image()` when both image and text present
  - New method: Price regex (`/(\d[\d.,]*)\s*(k|K|đ|d|vnđ|vnd|nghìn|ngàn|triệu|tr)?/u`) + name/title extraction for unfilled non-image slots
  - Files: `class-intent-router.php` (+87 lines)

- [x] **Attachment-First Flow (Buffer → Ask → Inject)**
  - Problem: Images sent before any goal → fell through to knowledge mode passthrough, lost context
  - Fix: Step 1.5A — buffer images in `_pending_attachments` slot, reply "📎 Đã nhận N ảnh! Bạn muốn làm gì với nó?"
  - Step 1.5B — next text message triggers inject: merge buffered images + pending text, clear buffer, resume normal flow
  - Trigger condition: `!empty($images) && !$has_active_goal && mb_strlen($msg_trimmed) < 10`
  - Files: `class-intent-engine.php` (+83 lines)

- [x] **HIL Webchat Nonce Fix**
  - Problem: Webchat sends `bizcity_chat` nonce, but HIL tab used `bizcity_intent_monitor` nonce → "Invalid nonce" error
  - Fix: New AJAX endpoint `bizcity_webchat_hil_slots` using `check_ajax_referer('bizcity_chat', 'nonce')` — same pattern as executor
  - JS rewrite: XHR GET with `_bizcChatNonce`, queries by session_id/conv_id
  - Files: `class-intent-monitor.php` (+73 lines), `class-admin-dashboard.php` (JS rewrite)

### Completed Tasks
- [x] B1-B4: Image attachment + skip + API key + array safety fixes
- [x] Vòng lặp core: Router → Planner → ask_user → provide_input → call_tool working
- [x] Plugin plan override mechanism: `array_merge()` in `merge_plans()`
- [x] ARCHITECTURE.md updated with Phase 10c section + optimization backlog
- [x] ROADMAP.md updated with Phase 11
- [x] **Sprint 1 — O1**: Removed 9 duplicate built-in plans (784→599 lines) — `class-intent-planner.php`
- [x] **Sprint 1 — O2**: Universal skip_on detection — `is_skip_phrase()` helper + default skip phrases for all optional slots
- [x] **Sprint 1 — O3**: LLM classify now injects waiting field schema — `build_waiting_field_schema()` in `class-intent-router.php`
- [x] **Sprint 1 — O9**: Slot validation before call_tool — `validate_slot()` method, validates text/number/image/choice/date types
- [x] Version bumped: v3.5.2 → v3.6.0
- [x] **Sprint 2 — O4**: Single-turn multi-slot extraction — `message_implies_skip()` method + raw_message passthrough
- [x] **Sprint 2 — O5**: Smart slot ordering — primary >100 chars → auto-fill secondary optionals with defaults
- [x] **Sprint 2 — O8**: Context-aware re-ask — filled slots summary appended to missing_fields prompt
- [x] **Sprint 2 — O10**: Stale cleanup cron — WP-Cron hourly + `find_expired_conversation()` DB method
- [x] **Sprint 2 — HIL Checklist Tab**: Todo-style slot checklist in Intent Monitor (CSS + HTML + JS + AJAX)
- [x] Version bumped: v3.6.0 → v3.6.1
- [x] **Sprint 3 — O6**: Cross-goal slot inheritance — auto-inherit overlapping slots when switching goals
- [x] **Sprint 3 — Slot fill rate**: Log `{goal, fill_rate, turns_taken}` at call_tool time for analytics
- [x] **Sprint 3 — Analytics dashboard**: Slot fill rate table + abandoned goals chart in Overview tab
- [x] **Sprint 3 — HIL Webchat Tab**: "✅ HIL Slots" tab in webchat router console with real-time checklist
- [x] **Sprint 3 — Cleanup**: Removed planning/coding dead code from 4 files (constants, comments, UI options)
- [x] Version bumped: v3.6.1 → v3.6.2
- [x] **Hotfix — Image+Text Slot Loss**: Router `if(image)/elseif(text)` was mutually exclusive — fixed 3 locations + new `extract_text_entities_alongside_image()` method with price/name extraction
- [x] **Hotfix — Attachment-First Flow**: Steps 1.5A (buffer) + 1.5B (inject) in engine — images sent before goal are buffered, acknowledged, then injected when user states intent
- [x] **Hotfix — HIL Webchat Nonce Fix**: New `bizcity_webchat_hil_slots` AJAX endpoint using `bizcity_chat` nonce (same as executor), JS rewrite in webchat dashboard
- [x] Version bumped: v3.6.2 → v3.6.3

---

## Phase 12 — Knowledge Fabric (v4.0.0) 🔄

> **Mục tiêu:** Thống nhất 3 luồng kiến thức rời rạc (project files, session context, knowledge base)
> thành **một mạng tri thức đa tầng** lấy `user_id` làm trung tâm.
>
> **Nguyên tắc:** User-first — mọi kiến thức đều phục vụ `user_id`.
> Từ "tôi muốn học file này" đến "nhớ link này giúp" — tất cả đi qua một pipeline duy nhất.

### Kiến trúc Knowledge Fabric — 4 Scope

```
┌─────────────────────────────────────────────────────────────┐
│              KNOWLEDGE FABRIC — 4 SCOPE                      │
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐│
│  │  SCOPE 1: USER (user_id)                                 ││
│  │  "Kiến thức cá nhân" — file, URL, text do user upload    ││
│  │  Mọi session đều truy cập được                          ││
│  └─────────────────────────────────────────────────────────┘│
│  ┌─────────────────────────────────────────────────────────┐│
│  │  SCOPE 2: PROJECT (project_id)                           ││
│  │  "Kiến thức dự án" — file/FAQ gắn với project cụ thể    ││
│  │  Chỉ active khi user đang trong project đó              ││
│  └─────────────────────────────────────────────────────────┘│
│  ┌─────────────────────────────────────────────────────────┐│
│  │  SCOPE 3: SESSION (session_id)                           ││
│  │  "Kiến thức tạm" — file/link gửi trong chat             ││
│  │  Tự expire khi session đóng (hoặc promote lên user)     ││
│  └─────────────────────────────────────────────────────────┘│
│  ┌─────────────────────────────────────────────────────────┐│
│  │  SCOPE 4: AGENT (character_id) — giữ nguyên             ││
│  │  "Kiến thức chuyên gia" — domain expertise              ││
│  │  Admin quản lý, mọi user dùng chung                     ││
│  └─────────────────────────────────────────────────────────┘│
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐│
│  │  CONTEXT MERGE (priority):                                ││
│  │  session > project > user > agent                        ││
│  │  → build_multi_scope_context() thay cho character-only   ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

### Sprint 1: Schema Migration (0.5d) ✅

**Mục tiêu:** Thêm columns `user_id`, `scope`, `project_id`, `session_id` vào `bizcity_knowledge_sources` + `bizcity_knowledge_chunks`. Backward compatible — tất cả data cũ = scope `agent`.

- [x] Migration v3.0.0: ALTER TABLE thêm 4 columns + composite indexes
- [x] Backfill existing data: `scope = 'agent'`, `user_id = 0`
- [x] Bump SCHEMA_VERSION: `2.1.0` → `3.0.0`
- [x] Scope-aware query methods: `get_sources_by_scope`, `get_chunk_embeddings_by_scope`, etc.

### Sprint 2: Knowledge Fabric Class (1d) ✅

**Mục tiêu:** Unified ingestion pipeline — 1 method `ingest()` cho mọi loại input.

- [x] `lib/class-knowledge-fabric.php` — **BizCity_Knowledge_Fabric** singleton (~580 LOC)
- [x] `ingest(array $params)` — file/URL/text/FAQ → scope-aware chunk + embed
- [x] `promote_scope($source_id, $new_scope)` — session→user, project→user
- [x] `search_multi_scope(string $query, array $scopes)` — merge results by priority
- [x] Reuse existing: `BizCity_File_Processor`, `BizCity_Content_Importer`, `BizCity_Embedding`
- [x] `cleanup_expired_sessions()` + WP-Cron (twicedaily)

### Sprint 3: Intent Provider — train_knowledge (1d) ✅

**Mục tiêu:** User nói "hãy học file này", "nhớ link đó" → Intent Engine route đến Knowledge Fabric.

- [x] `bizcity-knowledge/includes/class-intent-provider.php` — Provider (~450 LOC)
- [x] Goals: `train_knowledge`, `search_knowledge`, `manage_knowledge` (9 regex patterns)
- [x] Slots: `source_type`, `scope`, `content`, `action` + `_meta` auto-extract
- [x] Tool callbacks: `knowledge_train`, `knowledge_search`, `knowledge_manage`
- [x] `build_context()` + `get_system_instructions()` per goal

### Sprint 4: Multi-scope Context Router (1d) ✅

**Mục tiêu:** Thay thế single `character_id` query → multi-scope merge.

- [x] `build_multi_scope_context()` trong `class-context-api.php` (~200 LOC)
- [x] Priority merge: session(20%) > project(25%) > user(25%) > agent(remaining)
- [x] Token budget allocation per scope with min 200-token threshold
- [x] `build_agent_scope_context()` — wraps existing quick+semantic for agent scope
- [x] `get_multi_scope_knowledge_context()` helper in `class-mode-pipeline.php`
- [x] Filter: `bizcity_knowledge_context_parts`

### Sprint 5: UI & Polish (0.5d) ✅

**Mục tiêu:** Scope selector trong webchat + admin knowledge browser.

- [x] Admin: Knowledge Sources browser with scope filter bar (4 scope tabs + counts)
- [x] Scope badge column in character knowledge sources table
- [x] Promote button: session/project → user (AJAX with live UI update)
- [x] CSS: `.bk-scope-badge` + `.bk-scope-{agent|user|project|session}` styles
- [ ] Webchat: scope badge khi upload file (future — needs widget.js integration)

### Deliverables
| File | Plugin | Vai trò |
|---|---|---|
| `lib/class-knowledge-fabric.php` | bizcity-knowledge | Unified ingestion + multi-scope search |
| `includes/class-intent-provider.php` | bizcity-knowledge | Intent Provider goals: train/search/manage |
| `includes/class-database.php` migration | bizcity-knowledge | Schema v3.0.0 — scope columns |
| `lib/class-context-api.php` upgrade | bizcity-knowledge | `build_multi_scope_context()` |

### Completed Tasks
- [x] Architecture research: 2 deep-dive subagents (40KB findings)
- [x] Architecture design: Knowledge Fabric 4-scope model
- [x] KNOWLEDGE-FABRIC-ARCHITECTURE.md created in bizcity-knowledge
- [x] Schema migration v3.0.0 coded

---

## Phase 12.1 — LLM Slot Bridge (v3.9.1) ✅

> **Mục tiêu:** Nâng cấp vòng HIL provide_input từ simple text mapping → LLM-powered slot extraction.
> Giải quyết 3 vấn đề: choice fuzzy-match, number/date normalize, multi-slot extraction.
>
> **Phụ thuộc:** P11 (HIL Optimization), P10e (Unified Single-Call)

### Sprint 1: LLM Slot Bridge Core (0.5d) ✅

- [x] **Router: `fill_waiting_field_entities()` decision tree**
  - `needs_llm` check: choice/number/date types, OR missing_count > 1
  - LLM path → `extract_provide_input_with_llm()` → fill entities
  - Fallback → simple text mapping (original behavior preserved)
  - Files: `class-intent-router.php` (+60 LOC)

- [x] **Router: `extract_provide_input_with_llm()` new method**
  - Builds slot schema context (type, choices, filled/missing status)
  - Fetches 6 recent conversation turns for context
  - LLM call: `temperature=0.05`, `max_tokens=250`, `purpose=slot_extract`
  - Output: `{slots: {}, understood: bool, clarification: string}`
  - Handles: fuzzy choice match, multi-slot extraction, normalization
  - Files: `class-intent-router.php` (+120 LOC)

- [x] **Engine: Step 4c retry/cancel handling**
  - `_slot_extract_failed` → retry (max 1) with clarification message
  - Retry exhausted → cancel suggestion (Hủy hoặc @mention plugin khác)
  - Clean pseudo-entities from `$slot_updates` and `$turn_slots`
  - Success → reset `_slot_retry_count`, resume() → planner
  - Files: `class-intent-engine.php` (+80 LOC)

### v3.7 Bug Fixes (pre-LLM Slot Bridge) ✅

- [x] **Fix A: small_talk → provide_input override**
  - WAITING_USER + LLM says small_talk → override to provide_input
  - Prevents slot values like "tài chính" being classified as small_talk
  - Files: `class-intent-router.php` Step 2 (+15 LOC)

- [x] **Fix B: Step 0.5 provider_hint guard**
  - Multi-goal providers (tarot: tarot_reading + tarot_interpret) need full classification
  - Step 0.5 allows pre-classified result even with provider_hint
  - Files: `class-intent-router.php` Step 0.5 (+5 LOC)

- [x] **Fix C: Image URL in turn history**
  - User turns with attachments now include image_url content blocks
  - LLM retains image context across conversation
  - Files: `class-intent-stream.php` (+10 LOC)

### Deliverables

| File | Thay đổi | LOC |
|---|---|---|
| `class-intent-router.php` | `fill_waiting_field_entities()` LLM decision tree | +60 |
| `class-intent-router.php` | `extract_provide_input_with_llm()` new method | +120 |
| `class-intent-engine.php` | Step 4c retry/cancel + pseudo-entity cleanup | +85 |
| `class-intent-router.php` | v3.7 small_talk override + provider_hint guard | +20 |
| `class-intent-stream.php` | v3.7 image_url turn history | +10 |

---

## Phase 13 — Dual Context Architecture (v4.0) 🔄

> **Mục tiêu:** Tách hệ thống context thành 2 lớp: Emotion Context (đầy đủ, 70%) cho chat tự nhiên + Tool Context (compact, 30%) cho execution. Thêm `/` slash command để user chọn TOOL cụ thể (không chỉ plugin). Giảm misclassification bằng cách thu hẹp tool scope trước khi gọi LLM.
>
> **Phụ thuộc:** P11 (HIL Optimization), P10e (Unified Single-Call), P12.1 (LLM Slot Bridge), P10f (Dual-Logic)
>
> **Triết lý:** 70/30 — Emotion-first, Tool-ready. Hệ thống ưu tiên cảm xúc người dùng (70%), chỉ chuyển sang Tool Context compact khi đã xác định chính xác tool cần thực thi (30%).

### Sprint 1: `/` Slash Command — AJAX + UI (1d)

- [ ] **Backend: `bizcity_search_tools` AJAX handler**
  - Search `bizcity_tool_registry` by: `goal_description`, `custom_hints`, `title`
  - MySQL `LIKE '%keyword%'` OR `MATCH ... AGAINST` fulltext
  - Return: `[{goal, title, goal_description, plugin_slug, icon}]` top 5
  - Files: `class-intent-router.php` (+80 LOC)

- [ ] **Frontend: `/` trigger alongside `@` in input**
  - Detect `/` at start of input → open tool-search dropdown (reuse mention dropdown CSS)
  - Each item: tool icon + title + goal_description truncated
  - On select: auto-set `plugin_slug` + `goal` → enter plugin-context-mode → send first HIL prompt
  - Files: `class-admin-dashboard.php` (+120 LOC JS)

- [ ] **Context Header UI update**
  - Plugin mode header: `"Nhấn / để tìm tools làm việc của trợ lý này"`
  - Tool selected: `"🔮 Đang thực hiện: {tool_label} | ✕ Thoát"`
  - Files: `class-admin-dashboard.php` (+15 LOC)

### Sprint 2: Pre-Intent Tool Suggest (0.5d)

- [ ] **Pre-Intent keyword search in `bizcity_tool_registry`**
  - When no plugin selected + user sends message → extract keywords
  - Search `goal_description`, `custom_hints` for top 5 matching tools
  - Display as chips: `"Bạn muốn dùng tool nào? [Xem Tarot] [Xem Tương Hợp] [Hỏi AI]"`
  - If user ignores → fallback to full Emotion Context chat
  - Files: `class-intent-router.php` (+60 LOC), `class-admin-dashboard.php` (+40 LOC)

### Sprint 3: Tool Context Layer (1d)

- [ ] **New `build_tool_execution_context()` in Context Builder**
  - L1: System identity (compact, 200 chars max)
  - L2: Intent conversation context (current conv only)
  - L3: User memory (compact, 400 chars max)
  - L4: Tool schema (fields, choices, current state)
  - Total: ~800-1000 chars vs ~3000+ chars Emotion Context
  - Files: `class-context-builder.php` (+80 LOC)

- [ ] **Context switching in Mode Pipeline**
  - mode=emotion/reflection/knowledge → Emotion Context (full 6-layer)
  - mode=execution + tool confirmed → Tool Context (compact 4-layer)
  - Files: `class-mode-pipeline.php` (+20 LOC), `class-intent-stream.php` (+15 LOC)

### Sprint 4: Post-Tool Satisfaction (0.5d)

- [ ] **Post-tool completion detection**
  - After tool callback returns → send result + ask satisfaction
  - "ok cảm ơn" / "tốt rồi" → mark COMPLETED, exit tool mode, return Emotion Context
  - "chưa hài lòng" / "sai rồi" → offer retry or cancel with empathetic response
  - Regex patterns + LLM fallback for ambiguous responses
  - Files: `class-intent-engine.php` (+60 LOC), `class-intent-router.php` (+30 LOC)

### Sprint 5: Integration Test + Tuning (1d)

- [ ] **End-to-end test scenarios**
  - Scenario A: User types "bốc bài" → Pre-Intent suggests [Xem Tarot] → user picks → HIL → complete
  - Scenario B: User types `/tarot` → dropdown shows tarot_reading → select → HIL → complete
  - Scenario C: User @mention tarot plugin → `/` shows only tarot tools → select → HIL
  - Scenario D: User sends "buồn quá" → no tool match → Emotion Context chat (full 6-layer)
  - Benchmark: execution mode < 800ms (vs current 2400ms)

### Deliverables

| File | Thay đổi | LOC |
|---|---|---|
| `class-intent-router.php` | `bizcity_search_tools` AJAX + pre-intent keyword search | +170 |
| `class-admin-dashboard.php` | `/` slash command UI + context header + tool chips | +175 |
| `class-context-builder.php` | `build_tool_execution_context()` compact 4-layer | +80 |
| `class-mode-pipeline.php` | Context layer switching (emotion ↔ tool) | +20 |
| `class-intent-stream.php` | Tool Context injection for execution mode | +15 |
| `class-intent-engine.php` | Post-tool satisfaction detection + retry/cancel | +60 |

---

## Phase 14 — Tool Intelligence Layer (v4.3) ✅

> **Ngày:** 2026-03-10
>
> **Mục tiêu:** Nâng cấp toàn bộ tool pipeline — từ phân loại (Registry Search), dự đoán tool (Smart Prediction), enrich context (Tool Suggest with Context), đến sửa luồng compose (Empty Message Fix) và SQL query bugs.
>
> **Phụ thuộc:** P10f (Dual-Logic), P11 (HIL), P12.1 (LLM Slot Bridge), P13 (Dual Context)

### v4.3.0 — Tool Registry Keyword Rescue ✅

**Vấn đề:** Bot nói "chưa có trợ lý chuyên về Tarot" dù tool TỒN TẠI trong DB.

- [x] Router Step 3c.5: `search_tool_registry_by_message()` (~90 LOC)
- [x] Keyword scoring: goal(20), title(15), label(12), hints(10), plugin(10), desc(2/1)
- [x] Threshold: score ≥ 10 → RESCUE classification
- [x] Stream `end_reminder` tool verification
- [x] Gateway `end_reminder` tool verification (2 paths)
- [x] Intent Monitor: `tool_registry_search` + `tool_registry_verify` pipe steps

### v4.3.0 — SQL Fix (bizcity-tarot) ✅

**Vấn đề:** MariaDB error — empty FROM clause + wrong column names.

- [x] `$t['items']` → `$t['cards']` (2 queries: line 263, 431)
- [x] `name_vi` → `card_name_vi`, `name_en` → `card_name_en`, `category` → `card_type`
- [x] `data_json` → direct `upright_vi`/`reversed_vi` columns
- [x] File: `class-intent-provider.php` (bizcity-tarot)

### v4.3.1 — Tool Suggest with Context ✅

**Vấn đề:** TOOL_EXISTS nhưng LLM thiếu tool description + conversation context.

- [x] Stream: `$_suggest_tool_data` property + TOOL_EXISTS enrich + SSE `suggest_tool` field
- [x] Gateway: `build_system_prompt()` + `prepare_llm_call()` TOOL_EXISTS branches enriched
- [x] Frontend: `.bizc-tool-suggest-card` CSS + SSE done handler + click → `_selectTool()` + `enterPluginContextMode()`
- [x] Context sources: `rolling_summary` (priority 1), last 6 `webchat_messages` (priority 2)

### v4.3.2 — Smart Tool Prediction (Provider Scope Guard) ✅

**Vấn đề:** @mention plugin → Scope Guard luôn liệt kê tool list bất kể ý định user.

- [x] Logic 1: Auto-intent — Router đã classify goal thuộc provider → dùng luôn
- [x] Logic 2: Keyword match — Tokenize goal keys + labels, match message (score ≥ 10)
- [x] Logic 3: Single tool — Provider chỉ có 1 tool → predict trực tiếp
- [x] Reply: "Mình sẽ dùng **{label}** nhé?" + other tools nếu có
- [x] `set_goal()` pre-set → user reply "chạy" → Planner tiếp quản tự nhiên
- [x] Intent Monitor: `predicted_tool` + `prediction_method` trong `provider_scope_guard` log
- [x] File: `class-intent-engine.php` Step 3.6 (~80 LOC net)

### v4.3.3 — Empty Message Compose Fix ✅

**Vấn đề:** Tarot interpret → bot message trống trong DB.

- [x] Root cause: `elseif ( ai_compose && !empty(message) )` — `!empty('')` = false → skip compose
- [x] Fix: `elseif ( ai_compose )` — always route to compose_answer khi tool set complete=false
- [x] File: `class-intent-engine.php` line 1930 (1 line logic change)

### v4.3.4 — Pipeline Optimization + Cancel UX ✅

**4 cải tiến:**

1. **WAITING_USER Status Skip** — Bỏ `🤔 Đang phân tích...` flash cho WAITING_USER (Mode Classifier đã return sớm, 0 LLM)
   - [x] Engine Step 2: condition `do_action` on `!$is_waiting_user_s1`

2. **Tool Registry Static Cache** — `get_all_active()` cache trong request, giảm 2-4 DB query → 1
   - [x] `class-intent-tool-index.php`: static cache variable

3. **Tool Registry Dedup** — Stream reuse Router 3c.5 scoring result thay vì re-scan keyword
   - [x] Engine: pass `_router_registry_search` qua `$result['meta']`
   - [x] Stream: check Router result first, fallback keyword scan only khi Router không chạy

4. **Cross-goal Type Validation** — Slot inheritance kiểm tra type compatibility
   - [x] number: `is_numeric()`, image: `FILTER_VALIDATE_URL`, choice/select: check `$cfg['options']`

5. **Cancel Button (HIL section)** — Nút ✕ hover-show trên mỗi task chưa hoàn thành
   - [x] CSS + JS: `.bizc-intent-cancel` với transition opacity
   - [x] AJAX: gọi `bizcity_intent_cancel` (backend đã có sẵn)
   - [x] Refresh intent list sau cancel

### Deliverables (v4.3.0–v4.3.4)

| File | Plugin | Thay đổi |
|---|---|---|
| `class-intent-router.php` | bizcity-intent | Step 3c.5 keyword rescue + search method |
| `class-intent-engine.php` | bizcity-intent | Scope Guard prediction + compose fix + WAITING_USER skip + cross-goal type validation + registry passthrough |
| `class-intent-stream.php` | bizcity-intent | TOOL_EXISTS enrich + suggest_tool SSE + registry dedup |
| `class-intent-tool-index.php` | bizcity-intent | `get_all_active()` static cache |
| `class-chat-gateway.php` | bizcity-knowledge | TOOL_EXISTS enrich (2 methods) |
| `class-admin-dashboard.php` | bizcity-bot-webchat | Suggest card + Cancel button CSS/JS |
| `class-intent-provider.php` | bizcity-tarot | SQL table/column fix |

---

## Phase 15 — 5-Mode Classification & Knowledge Cleanup ✅

**Mục tiêu:** (A) Dọn dẹp knowledge mode — loại bỏ Provider Expansion (Gemini/ChatGPT giờ là execution tools). (B) Thêm MODE_AMBIGUOUS cho tin nhắn mơ hồ.

### Deliverables
- Deprecated `class-knowledge-router.php` — Knowledge Router + Provider Registry inactive
- `BizCity_Ambiguous_Pipeline` trong `class-mode-pipeline.php`
- `MODE_AMBIGUOUS = 'ambiguous'` trong `class-mode-classifier.php`
- LLM prompt updated: 5 modes (emotion, reflection, knowledge, execution, ambiguous)

### Completed Tasks
- [x] Deprecate Knowledge Router auto-registration hook (priority 999)
- [x] Remove knowledge_router meta enrichment từ engine Step 2.5
- [x] Remove "dùng Gemini/ChatGPT" suggestion từ Knowledge Pipeline prompt
- [x] Add `MODE_AMBIGUOUS` constant + `VALID_MODES` array (5 modes)
- [x] Add `BizCity_Ambiguous_Pipeline` class (lightweight, no RAG, max_tokens 300)
- [x] Update classifier LLM prompt: mode #5 ambiguous + examples
- [x] Update 3 fallback points: confidence < 0.6 → ambiguous (was knowledge)
- [x] Update `get_mode_label()` with ambiguous label
- [x] Update engine routing_branch for ambiguous mode
- [x] Simplify stream pipeline direct reply comment

### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-mode-classifier.php` | bizcity-intent | `MODE_AMBIGUOUS`, `VALID_MODES[5]`, LLM prompt, 3 fallback points, `get_mode_label()` |
| `class-mode-pipeline.php` | bizcity-intent | `BizCity_Ambiguous_Pipeline` + registered in constructor |
| `class-intent-engine.php` | bizcity-intent | Step 2.5 comment, routing_branch, mode comment 4→5 |
| `class-knowledge-router.php` | bizcity-intent | Auto-registration DEPRECATED |
| `class-intent-stream.php` | bizcity-intent | Comment cleanup |

---

## Phase 16 — Image+Text Smart Routing & Tool Suggest Confirmation (v4.3.6–v4.3.7) ✅

**Mục tiêu:** Sửa pipeline khi user gửi ảnh + lệnh đồng thời ("tạo video từ ảnh này" + 📷). Trước đây Step 1.5C ép knowledge mode → Router bị skip → AI free-form → user confirm → keyword rescue match SAI tool. Thêm cơ chế 2-turn funnel: Turn 1 filter N→3, Turn 2 confirm O(1).

> **Nguyên tắc:** "Funnel hai giai đoạn" — heavy work (scan N tools) ở Turn 1, Turn 2 luôn O(1) bất kể registry size.

### Root Cause Analysis
```
User: "tạo video từ ảnh này nhé" + 📷
 → Step 1.5C fires (image + text≥10 + no goal + no HIL)
 → mode=knowledge override → Router SKIPPED
 → AI free-forms: "Bạn muốn dùng create_video không?"
 → User: "ok bạn làm nhé"
 → tool_registry_keyword rescue → WRONG match (daily_outlook_check, score=42)
```

### Deliverables

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-engine.php` | bizcity-intent | v4.3.6: Smart bypass at Step 1.5C override, `_images`→named slot mapping (2 paths) |
| `class-intent-engine.php` | bizcity-intent | v4.3.7: Step 1.7 handler + mode override + `detect_tool_suggest_confirm()` + persist `_image_text_suggested_tools` |
| `bizcity-video-kling.php` | bizcity-video-kling | Pattern regex expanded (b-roll, video nền), `message`→optional, `image_url` first in slot_order |
| `class-tools-kling.php` | bizcity-video-kling | Fix `$context` always `[]` → read `$slots['user_id']`, default prompt for image-only |

### Changes — v4.3.6: Smart Bypass & Image Slot Mapping

1. **Smart bypass at Step 1.5C override** (~line 493)
   - Before overriding to knowledge mode, scan `get_goal_patterns()`
   - If message matches any registered pattern → `skip_override=true` → `mode=execution, method=image_text_pattern_bypass`
   - Prevents forced knowledge mode for unambiguous commands with image

2. **`_images` → named image slot mapping** (Step 4b, ~line 1553 & ~line 1596)
   - When `new_goal` creates conversation, `_images` entity doesn't match plan's `image_url` field
   - Auto-mapping: find first `type:'image'` slot in plan → fill with image URL → unset `_images`
   - Applied to BOTH code paths: cross-goal switch + fresh conversation
   - Clear `_session_pending_images` after consumption

### Changes — v4.3.7: Tool Suggest Confirmation (Step 1.7)

3. **Persist `_image_text_suggested_tools`** in conv slots (~line 318)
   - Step 1.5C now stores matched tools in DB (alongside `_session_pending_images`)
   - Survives across turns (params are ephemeral)

4. **Step 1.7 handler** (between Step 1.6 and Context Builder, ~line 421)
   - Conditions: no active goal + no images + `_image_text_suggested_tools` + `_session_pending_images` in conv slots
   - `detect_tool_suggest_confirm()` checks user confirmation
   - When confirmed: recover images + set `tool_goal` hint + clear buffers
   - Router's existing slash-command shortcut fires → 0 LLM cost

5. **Mode override** (after Step 1.5C override block, ~line 529)
   - Forces `mode=execution` when `_suggest_confirm` is set

6. **`detect_tool_suggest_confirm()` method** (~line 3900)
   - Path 1 (specific): user mentions tool name/label → return that tool
   - Path 2 (generic): short message ≤40 chars + affirmative regex → return top-scored tool
   - 7 Vietnamese + English confirmation patterns

### Scalability Analysis

| Component | Complexity | Status |
|---|---|---|
| Step 1.7 handler | O(1) — reads 3 pre-filtered tools | ✅ Scale-proof |
| `detect_tool_suggest_confirm()` | O(1) — 7 regex × 1 msg | ✅ Scale-proof |
| `find_matching_tools_for_suggest()` | O(N×W) brute-force | ⚠️ Needs index at ~2K+ tools |
| Smart bypass pattern scan | O(P) with break-early | ⚠️ Acceptable to ~50K patterns |

> **Future TODO**: Replace `find_matching_tools_for_suggest()` with inverted index or FULLTEXT search when tool count exceeds ~500. See [repo memory note](/memories/repo/bizcity-intent-future-todo.md).

### Completed Tasks
- [x] Smart bypass at Step 1.5C: scan `get_goal_patterns()` before knowledge override
- [x] `_images` → named image slot mapping (cross-goal + fresh conversation paths)
- [x] `_session_pending_images` cleanup after new_goal consumes images
- [x] Persist `_image_text_suggested_tools` in conv slots
- [x] Step 1.7 Tool Suggest Confirmation handler
- [x] Mode override for `_suggest_confirm` flag
- [x] `detect_tool_suggest_confirm()` private method (2 detection paths)
- [x] Video-kling: pattern regex expanded, message→optional, image_url first
- [x] Video-kling: $context bug fixed, default prompt for image-only
- [x] All files verified error-free

---

## Phase 17 — Slot Confirm & Image Attachment Flow Fix (v4.3.8) ✅

> **Ngày:** 2026-07-02
>
> **Mục tiêu:** Sửa 3 bug liên quan trong luồng HIL slot-filling + confirm. (A) Image attachment bị drop khi user gửi ảnh-only vào router guards. (B) Planner hỏi lại optional slots khi đang ở confirm state. (C) `_awaiting_confirm` flag bị mất qua DB refresh chain.
>
> **Phụ thuộc:** P11 (HIL), P16 (Image+Text Smart Routing)

### Tình huống tái hiện

Tool `write_article` (bizcity-tool-content): User nhập topic → bot hỏi optional `image_url` → user gửi ảnh → bot **hỏi lại** (vòng lặp). Nếu bỏ qua ảnh → confirm card hiện đúng → bấm "ok" → confirm card hiện **lần 2** thay vì execute.

### Root Cause

1. **`fill_waiting_field_entities()`** (`return` on empty text) → image-only messages bị drop tại `centralized_waiting_guard` + `slash_command_direct`
2. **Planner optional slot loop** không check `_awaiting_confirm` → hỏi lại image_url sau confirm
3. **`resume()` + DB refresh** làm mất `_awaiting_confirm` flag giữa `provide_input` → `call_tool` handlers

### Deliverables

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-router.php` | bizcity-intent | Image attachment check trước `fill_waiting_field_entities()` tại 2 guards |
| `class-intent-planner.php` | bizcity-intent | `_awaiting_confirm` break guard trước optional slot foreach |
| `class-intent-engine.php` | bizcity-intent | `$confirm_pending` in-memory flag (init + set + check) |

### Completed Tasks
- [x] Router: Image attachment injection tại `centralized_waiting_guard` (waiting_for=image + attachments)
- [x] Router: Image attachment injection tại `slash_command_direct` provide_input
- [x] Planner: `$awaiting_confirm` guard — break optional slot loop khi `_awaiting_confirm` set
- [x] Engine: `$confirm_pending = false` init trước Step 4
- [x] Engine: `$confirm_pending = true` khi `prev_waiting_field === '_confirm_execute'`
- [x] Engine: `$awaiting_confirm = $confirm_pending || slots['_awaiting_confirm']` trong call_tool case
- [x] All files verified error-free

### Architectural Pattern: Cross-Handler In-Memory Flag

Khi state cần truyền giữa 2 handler trong cùng `process()` call mà DB refresh có thể làm mất → dùng `$flag` variable thay vì phụ thuộc slots_json. Pattern áp dụng: `$confirm_pending`, `$intent_was_reclassified`.

---

## Phase 19 — Webchat UI Overhaul + Stream Fix + Model Migration (v4.6) ✅

**Mục tiêu:** Nâng cấp giao diện /chat/ lên chuẩn modern (full Markdown, media preview, mermaid), fix gẫy stream do thinking model, migration model LLM ổn định hơn, tắt tool suggestion sai.

### Deliverables
- `views/src/components/Message.jsx` — ReactMarkdown + MediaPreview + Mermaid code blocks
- `views/src/components/Mermaid.jsx` — **NEW** — Mermaid v11 async renderer
- `views/src/utils/format.js` — Cleanup formatMsg, keep formatTime
- `views/vite.config.js` — `base: './'` fix chunk 404
- `class-intent-engine.php` — Stream fix + tool suggest disabled
- `class-openrouter-models.php` — minimax → gemini-2.5-flash
- `class-executor-messenger.php` + `class-intent-bridge.php` — Model migration

### Completed Tasks
- [x] **Markdown Rendering**: react-markdown + remark-gfm + rehype-raw → full headers, bold, italic, lists, links, tables, code blocks, inline HTML
- [x] **Media Preview**: Auto-detect URL → img/video/audio based on extension
- [x] **Mermaid Diagrams**: Mermaid v11 async API, unique IDs, neutral theme, white background
- [x] **Vite Build**: `base: './'` fix chunk loading 404s from WordPress
- [x] **Model Migration**: 9 occurrences minimax/minimax-m2.5 → google/gemini-2.5-flash across 4 files
- [x] **Stream Fix**: compose_natural_ask_prompt + smooth_tool_ask_prompt: google/gemini-2.5-pro → google/gemini-2.5-flash, max_tokens 200→500 (thinking model reasoning tokens ate output budget)
- [x] **Tool Suggest Off**: Step 1.5C find_matching_tools_for_suggest + Step 1.7 tool suggest confirm → commented out (inaccurate matching)
- [x] **DB Context**: plugin_slug, tool_name, client_name propagation fix

### Root Cause Analysis — Stream Truncation

```
┌─────────────────────────────────────────────────────────────────┐
│  BUG: Messages truncated to 13-26 chars                          │
│  Example: "Đã rõ, vũ trụ" (13 chars) instead of full sentence  │
│                                                                   │
│  compose_natural_ask_prompt()                                     │
│   → bizcity_openrouter_chat(model='google/gemini-2.5-pro',       │
│                              max_tokens=200)                      │
│   → Gemini 2.5 Pro = THINKING MODEL                             │
│   → Internal reasoning: ~170-180 tokens (invisible)              │
│   → Remaining for output: ~20-30 tokens = 13-26 chars           │
│                                                                   │
│  FIX: Switch to gemini-2.5-flash (non-thinking) + max_tokens=500│
│   → Full 500 tokens available for output                         │
└─────────────────────────────────────────────────────────────────┘
```

### Bài học

1. **Thinking models (gemini-2.5-pro) eat max_tokens** — reasoning tokens count against budget. Use non-thinking models (flash) for short utility calls.
2. **Vite base path matters** — WordPress loads assets from subdirectories, `base: './'` ensures chunk imports use relative paths.
3. **Tool suggestion accuracy** — `find_matching_tools_for_suggest()` often matches wrong tools (e.g., astrology for food logging). Disable until better matching algorithm.
4. **wp_footer() is required** — Removing it prevents WordPress from injecting enqueued scripts → React app won't load.

---

## Phase 20 — Slot Auto-Map Fix & Accept-Image ✅

**Phiên bản:** v4.6.1  
**Ngày:** 2026-03-15  
**Mục tiêu:** Fix 2 critical bugs — (1) ask_field trống do engine auto-map ghi đè raw trigger vào slot, (2) hình ảnh bị drop khi waiting_for !== 'image'.

### Bug 1 — ask_field Empty (Auto-Map Overwrite)

**Triệu chứng:** User gõ "ghi nhật ký bữa ăn nhé" → engine auto-map fills `food_input` với raw command → planner thấy slot đã filled → trả `call_tool` với `ask_field` trống → loop.

**Nguyên nhân:** Auto-map v4.0.1 blindly maps raw message → first unfilled required text slot, kể cả khi message là trigger chứ ko phải data.

**Giải pháp:**
- `no_auto_map` slot config flag — engine skip auto-map cho slot này
- `extract_content_after_trigger($message, $intent)` — strip trigger regex, lấy nội dung còn lại (nếu >3 chars)
- Nếu message chỉ có trigger → slot trống → planner detect missing → ask user

### Bug 2 — Images Dropped for Text Slots

**Triệu chứng:** User gửi ảnh món ăn khi `waiting_for = 'food_input'` (type=text) → 6 router guards chỉ capture images khi `waiting_for === 'image'` → ảnh bị mất.

**Giải pháp:**
- `accept_image` slot config flag — cho phép text slot nhận image
- 6 router guard locations updated: luôn pass `_images` entities khi có attachments
- Engine provide_input: auto-fill text slot với `'[phân tích từ ảnh]'` placeholder khi accept_image=true
- Image-only guards: check `accept_image` flag → loosen guard → route as `provide_input`

### Slot Config Flags (Generic)

| Flag | Type | Default | Mô tả |
|---|---|---|---|
| `no_auto_map` | bool | false | Engine skip auto-map cho slot này |
| `accept_image` | bool | false | Text slot nhận image input |

### Files Changed

| File | Changes |
|---|---|
| `class-intent-engine.php` | auto-map `no_auto_map` check, `extract_content_after_trigger()`, provide_input `accept_image` auto-fill |
| `class-intent-router.php` | 6 guard locations: `_images` pass-through + `accept_image` bypass |
| `class-intent-provider.php` (calo) | `food_input` slot: `no_auto_map: true, accept_image: true` |

---

## Phase 23 — HIL Pre-confirm Pipeline Hardening (v4.9.1 / v3.9.2) ✅

**Phiên bản:** Intent v3.9.2, Objective Understanding v4.9.0–v4.9.1  
**Ngày:** 2026-03-31  
**Mục tiêu:** Fix 2 critical bugs trong HIL Content-First Pre-confirm flow cho multi-objective messages.

### Case 1 — Objective Understanding Dedup Bug ✅

**Triệu chứng:** "đăng bài lên web rồi đăng facebook" → single-goal `write_article` (Facebook segment bị mất)  
**Nguyên nhân:** `resolve_intents()` segment 0 blindly inherited Router's primary goal (`post_facebook`), dedup collapsed cả 2 segments → `is_multi=false`  
**Fix:** Segment 0 validates text against primary goal's regex pattern trước khi inherit. Falls through to regex matching nếu no match.  
**File:** `orchestration/class-objective-understanding.php`

### Case 2 — Content Reply Misrouted to Mindmap ✅

**Triệu chứng:** Sau khi Content Gate hỏi "Nội dung cụ thể là gì?", user reply "Xây dựng bản sao song sinh số giúp việc bằng AI agent" → Router classify thành `new_goal:mindmap` → hỏi "Loại sơ đồ?"

**Nguyên nhân:** 3-point chain failure:
1. **G1** Step 3 Router: LLM classify content text → mindmap (8750ms)
2. **G2** Step 4b: `new_goal:mindmap` → CLOSE write_article conv → CREATE mindmap conv → mất preconfirm slots
3. **G3** Step 4.4: `analyze()` trên content text → `is_multi=false` → wrong single-goal path

**Fix — 3-part defense-in-depth:**

| Fix | Vị trí | Mô tả |
|-----|--------|--------|
| **Fix 1** (Primary) | Step 2.9 — before Router | Skip Router khi `_multi_preconfirm_state ∈ [content_provided, confirmed]`, construct synthetic `new_goal:{existing_goal}` |
| **Fix 2** (Defense) | Step 4b — conversation close guard | `$is_preconfirm_resume` check: giữ conversation khi same goal + preconfirm active |
| **Fix 3** (Defense) | Step 4.4 — cached analysis | Load `_multi_preconfirm_analysis` thay vì re-analyze content text |

### Nguyên lý thiết kế

> Khi engine đang trong HIL pre-confirm flow (đã hỏi content → chờ user trả lời), user's message là **CONTENT**, không phải **COMMAND**.  
> Router không nên phân loại content text — đó là vi phạm "conversational contract".  
> State machine (`asking_content → content_provided → confirmed`) phải được tôn trọng xuyên suốt pipeline.

### Files Changed

| File | Changes |
|------|--------|
| `class-intent-engine.php` | Step 2.9 skip Router, Step 4b preconfirm guard, Step 4.4 cached analysis |
| `class-objective-understanding.php` | Segment 0 regex validation before inheriting primary goal |
| `bootstrap.php` | Version bump 3.9.1 → 3.9.2 |

---

## Dependency Map

```
P1 (Mode Classifier)
 └─ P2 (Intent Engine)
     ├─ P3 (Conversation Store) ← persistent multi-turn
     ├─ P4 (Pipeline System)    ← mode → pipeline dispatch
     │   └─ P6 (Planner Bridge) ← connect to planner + executor
     └─ P5 (6-Layer Context)    ← rich context for AI
         ├─ P10 (Tool Meta)      ← context injection to tool callbacks
         │   └─ P10b (Tool Index) ← DB-persisted registry + LLM manifest
         │       └─ P10c (Control Panel) ← admin UI, data-driven routing
         │           ├─ P10e (Unified Call) ← 3→1 LLM, focused top-N
         │           │   └─ P10f (Dual-Logic) ← @Tag Direct + Plugin Gathering
         │           └─ P11 (HiL Optimization) ← slot accuracy, skip, validation
         │               └─ P12.1 (LLM Slot Bridge) ← intelligent provide_input extraction
         │                   └─ P13 (Dual Context) ← Emotion/Tool context, / slash cmd
         │                       └─ P14 (Tool Intelligence) ← prediction, compose, registry search
         │                           └─ P15 (5-Mode & Knowledge Cleanup) ← ambiguous mode, deprecate router
         │                               └─ P16 (Image+Text Smart Routing) ← Step 1.5C bypass, Step 1.7 suggest-confirm
         │                                   └─ P17 (Slot Confirm & Image Fix) ← router image guard, planner confirm guard, engine confirm_pending
         │                                       └─ P19 (Webchat UI + Stream Fix) ← Markdown, Mermaid, stream fix, model migration
                                           └─ P20 (Slot Auto-Map Fix) ← no_auto_map, accept_image, trigger-strip, router guards
                                               └─ P21 (HIL Robustness) ← off-topic escape, type guard, 6-issue audit
                                                   └─ P22 (Twin Core Phase 0) ← context cleanup, tool suggestion removal, Twin Suggest
                                                       └─ P23 (HIL Pre-confirm Hardening) ← ObjUnderstanding dedup, Router skip, 3-layer defense
         │       └─ P12 (Knowledge Fabric) ← unified multi-scope knowledge
         └─ P7 (Intent Monitor) ← admin SPA dashboard
             ├─ P8 (Data Browser)   ← 11 sub-menus (4 intent + 7 planner)
             │   └─ P9 (Inline Expand) ← expand rows with related data
             └─ Future: P16+ extensions
```

---

## Timeline

| Phase | Effort | Ghi chú |
|---|---|---|
| P1 | 1 ngày | ✅ Done — Mode Classifier (rule + AI) |
| P2 | 3-4 ngày | ✅ Done — Intent Engine + Router + Stream |
| P3 | 1-2 ngày | ✅ Done — 4 DB tables, CRUD stores |
| P4 | 2-3 ngày | ✅ Done — Pipeline architecture |
| P5 | 2 ngày | ✅ Done — 6-Layer Context Chain |
| P6 | 1-2 ngày | ✅ Done — Planner Bridge + Executor dispatch |
| P7 | 2-3 ngày | ✅ Done — Intent Monitor SPA dashboard |
| P8 | 1.5 ngày | ✅ Done — Data Browser (11 sub-menus, export, modal, cross-links) |
| P9 | 0.5 ngày | ✅ Done — Inline Expand (tabbed panel, cross-system tracing) |
| P10 | 0.5 ngày | ✅ Done — Tool Input Meta & Context Injection |
| P10b | 0.5 ngày | ✅ Done — Tool Registry & DB-persisted Tool Index |
| P10c | 0.5 ngày | ✅ Done — Tool Control Panel (admin UI, data-driven routing) |
| P10e | 0.5 ngày | ✅ Done — Unified Single-Call Classification (3→1 LLM, focused top-N, regex bias) |
| P10f | 1 ngày | ✅ Done — Dual-Logic Tool Routing (Logic 1 Registry LLM + Logic 2 @Tag Direct, Plugin Gathering, UI badge) |
| P11 Sprint 1 | 1-2 ngày | ✅ Done — Slot accuracy & validation (O1, O2, O3, O9) |
| P11 Sprint 2 | 1-2 ngày | ✅ Done — UX & intelligence (O4, O5, O8, O10) + HIL Checklist Tab |
| P11 Sprint 3 | 1 ngày | ✅ Done — Cross-goal intelligence (O6, analytics, slot tracking, cleanup) |
| P11 Hotfix | 0.5 ngày | ✅ Done — Image+text fix, attachment-first flow, HIL nonce fix (v3.6.3) |
| P12 Sprint 1 | 0.5 ngày | ✅ Done — Schema migration (scope columns, intent_conversation_id) |
| P12 Sprint 1b | 0.5 ngày | ✅ Done — HIL Focus Mode (focus_mode signal, scoped queries, cancel, frontend lifecycle) |
| P12.1 | 0.5 ngày | ✅ Done — LLM Slot Bridge (intelligent provide_input, retry/cancel, v3.7 bug fixes) |
| P13 Sprint 1 | 1 ngày | 🔄 `/` Slash Command — AJAX handler + frontend UI + context header |
| P13 Sprint 2 | 0.5 ngày | ⬜ Pre-Intent Tool Suggest (keyword search + tool chips) |
| P13 Sprint 3 | 1 ngày | ⬜ Tool Context Layer (compact 4-layer + context switching) |
| P13 Sprint 4 | 0.5 ngày | ⬜ Post-Tool Satisfaction (completion detection + retry/cancel) |
| P13 Sprint 5 | 1 ngày | ⬜ Integration Test + Tuning (4 scenarios + benchmark) |
| P14 | 0.5 ngày | ✅ Done — Tool Intelligence Layer (v4.3: Registry Search, SQL Fix, Suggest with Context, Smart Prediction, Compose Fix) |
| P15 | 0.5 ngày | ✅ Done — 5-Mode Classification & Knowledge Cleanup (v4.4: MODE_AMBIGUOUS, deprecate Knowledge Router, clean knowledge prompt) |
| P16 | 0.5 ngày | ✅ Done — Image+Text Smart Routing (v4.3.6–v4.3.7: smart bypass, _images→slot mapping, Step 1.7 suggest-confirm, detect_tool_suggest_confirm) |
| P17 | 0.5 ngày | ✅ Done — Slot Confirm & Image Fix (v4.3.8: router image guard, planner confirm guard, engine confirm_pending flag) |
| P12 Sprint 2 | 1 ngày | ⬜ Knowledge Fabric class (unified ingest + multi-scope search) |
| P12 Sprint 3 | 1 ngày | ⬜ Intent Provider (train/search/manage knowledge goals) |
| P12 Sprint 4 | 1 ngày | ⬜ Multi-scope context router |
| P12 Sprint 5 | 0.5 ngày | ⬜ UI scope selector + promote |
| P18 | 0.5 ngày | ✅ Done — 3-Memory Architecture (v4.5: Rolling Summary mỗi 5 turns, Episodic Memory events, 8-layer context, token_count tracking, Recent Window 6 msgs) |
| P19 | 0.5 ngày | ✅ Done — Webchat UI Overhaul (v4.6: ReactMarkdown, MediaPreview, Mermaid v11, stream fix gemini-2.5-pro→flash, minimax→flash migration, tool suggest disabled) |
| P20 | 0.5 ngày | ✅ Done — Slot Auto-Map Fix & Accept-Image (v4.6.1: no_auto_map, accept_image, extract_content_after_trigger, 6 router guard fixes) |
| P21 | 0.5 ngày | ✅ Done — HIL Robustness Review & Hardening (v4.7.1: 6-issue audit, off-topic/greeting escape, type guard fallback, defense-in-depth stack) |
| P22 | 1 ngày | ✅ Done — Twin Core Phase 0: Context Cleanup & Follow-up (v5.0) — 6 new files + 13 patches |
| P23 | 0.5 ngày | ✅ Done — HIL Pre-confirm Pipeline Hardening (v4.9.1/v3.9.2: ObjUnderstanding dedup, Router skip, 3-layer defense) |
| **Total** | **~38-43 ngày** | P1-P23 done (~34d) + P12 remaining (~4d) + P13 (~4d) |
