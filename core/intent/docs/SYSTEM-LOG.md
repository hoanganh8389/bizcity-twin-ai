# BizCity Platform — System Log & Roadmap Sync

> **⚠️ Lưu ý (Phase 1.11+):** Các log cũ tham chiếu 5-mode classifier (emotion/reflection/knowledge/execution/ambiguous), `MODE_EXECUTION`, `MODE_AMBIGUOUS` là **LEGACY**. Hiện chỉ có **2 modes: SINGLE | MULTI** (Smart Classifier). Xem [PHASE-0-RULES.md](../../PHASE-0-RULES.md) §5.
>
> **Mục đích**: File này là **nhật ký sống** của toàn bộ hệ thống.
> Mỗi ngày làm việc — dù là fix bug, thêm tính năng, refactor hay cập nhật tài liệu —
> đều ghi vào đây để đảm bảo toàn bộ mu-plugins luôn **nhất quán, có thể truy vết,
> và sync với ARCHITECTURE.md + Roadmap**.
>
> **Nguyên tắc ghi log:**
> - Ghi ngay sau khi hoàn thành (không ghi trước)
> - Format: `YYYY-MM-DD | Plugin | Mô tả ngắn | Phase liên quan`
> - Trạng thái phase: `✅ Done` | `🔄 In Progress` | `⏳ Pending` | `❌ Blocked`
>
> **Files liên quan:**
> - [ARCHITECTURE.md](ARCHITECTURE.md) — Kiến trúc tổng thể + Roadmap Phases 1–13
> - [PIPELINE-ARCHITECTURE.md](PIPELINE-ARCHITECTURE.md) — Pipeline Orchestration design
> - [PLUGIN-SKELETON-TEMPLATE.md](PLUGIN-SKELETON-TEMPLATE.md) — Mẫu SDK plugin mới
> - [PLUGIN-STANDARD.md](PLUGIN-STANDARD.md) — Chuẩn phát triển plugin

---

## Mục lục

1. [Core Plugin Registry](#1-core-plugin-registry)
2. [SDK Compliance Matrix](#2-sdk-compliance-matrix)
3. [Roadmap Phase Status](#3-roadmap-phase-status)
4. [Daily Change Log](#4-daily-change-log)
5. [Per-Plugin Backlog](#5-per-plugin-backlog)
6. [Known Issues & Debt](#6-known-issues--debt)
7. [Decision Log](#7-decision-log)

---

## 1. Core Plugin Registry

> Tất cả plugins trong `mu-plugins/` được coi là **lõi hệ thống**.
> Plugins trong `plugins/` là **Agent mở rộng** (cần SDK compliance).

### MU-Plugins (Lõi — Luôn Active)

| Plugin | Version | Role | Layer | Status |
|--------|---------|------|-------|--------|
| **bizcity-intent** | v3.9.1 | Team Leader / Intent Engine | L2 Intent Router + Planner + Tool Registry + Prompt Logging + Classify Cache + Unified Single-Call + LLM Slot Bridge | ✅ Active |
| **bizcity-knowledge** | v1.0.0 | Knowledge & RAG Layer | L6 Knowledge Context | ✅ Active |
| **bizcity-openrouter** | v? | LLM API Gateway | Infrastructure | ✅ Active |
| **bizcity-bot-webchat** | v? | Webchat Gateway | L0 Gateway | ✅ Active |
| **bizcity-admin-hook** | v? | Admin Chat Gateway | L0 Gateway | ✅ Active |
| **bizcity-admin-hook-zalo** | v? | Zalo Gateway routing | L0 Gateway | ✅ Active |
| **bizcity-zalo-bot** | v1.4.0 | Zalo Bot + OA Manager | L0 Gateway + Agent | ✅ Active |
| **bizcity-automation** | v? | Workflow Orchestrator | Automation Layer | ✅ Active |
| **bizcity-facebook-bot** | v? | Facebook Bot Gateway | L0 Gateway | ✅ Active |
| **bizcity-brain-level** | v? | Coaching Levels Agent | Agent (Personal) | ✅ Active |
| **bizcity-web** | v? | Web/Content Functions | Infrastructure | ✅ Active |
| **bizcity-market-plugin** | v1.1.7 | Plugin Marketplace | Platform Layer | ✅ Active |
| **bizcity-wallet** | v? | Credit & Wallet | Platform Layer | ✅ Active |
| **bizcity-dashboard** | v? | Admin Dashboard | Admin UI | ✅ Active |
| **bizcity-bot-agent** | v1.0.0 | Chat Monitor & Pipeline Logger | Observability | ✅ Active |
| **bizcity-mcp** | v? | Model Context Protocol | Infrastructure | ✅ Active |
| **bizcity-openrouter** | v? | OpenRouter Gateway | Infrastructure | ✅ Active |
| **bizcity-market-automation** | v? | Market Automation | Platform Layer | ✅ Active |
| **bizcity-script-shortcode** | v? | Script & Shortcode | Utility | ✅ Active |
| **bizcity-market-theme** | v? | Market Theme | UI Layer | ✅ Active |
| **bizcity-executor** | v1.2.0 | MCP Execution Engine | L3 Executor (trace-driven) | ⏸️ Built, Mostly Bypassed — skip built-in tools |
| **bizcity-planner** | v1.0.0 | Intelligence Planner | L2.5 Planner (classify→plan→dispatch) | ⏸️ Built, Bypassed — only MODE_PLANNING |
| **bizcity-preflight** | v1.0.0 | Pre-flight Input Gate | Cross-cutting validation | ⏸️ Built, Passive — snapshots only |

### Plugins/ (Agent Extensions — SDK-compliant)

| Plugin | Version | Category | SDK Status |
|--------|---------|----------|------------|
| **bizcity-tarot** | v? | Personal / Spiritual | Partial — cần Layer 1.7 + Pipeline I/O |
| **bizcoach-map** | v? | Personal / Spiritual | Partial — có INTENT-SKELETON |
| **bizcity-video-kling** | v1.0.0 | Creative / Content | Partial — có INTENT-SKELETON |
| **bizcity-voice-chat** | v? | Creative / Hardware | Basic || **bizcity-tool-woo** | v1.0.0 | Business / WooCommerce | Scaffold ✅ — chờ Phase 10 hook fire |
| **bizcity-tool-content** | v1.0.0 | Creative / Content | Scaffold ✅ — chờ Phase 10 hook fire |
| **bizcity-tool-facebook** | v1.0.0 | Social / Facebook | Scaffold ✅ — chờ Phase 10 hook fire |
| **bizcity-tool-reminder** | v1.0.0 | Productivity / Task | Scaffold ✅ — chờ Phase 10 hook fire |
| **bizcity-tool-image** | v1.0.0 | Creative / AI Image | Scaffold ✅ — chờ Phase 10 hook fire |
---

## 2. SDK Compliance Matrix

> Mỗi plugin được check theo [SDK Compliance Checklist](ARCHITECTURE.md#25-sdk-compliance-checklist-mỗi-plugin-mới-phải-pass).
> `✅` = Done | `⏳` = Cần làm | `N/A` = Không áp dụng

| Plugin | Registration Hook | Tool Output Envelope | data.type + data.id | Pipeline-ready | Profile Context | INTENT-SKELETON.md |
|--------|:-:|:-:|:-:|:-:|:-:|:-:|
| bizcity-intent | ✅ (tự là lõi) | ✅ | ✅ | ✅ | N/A | ✅ |
| bizcity-knowledge | N/A | N/A | N/A | N/A | N/A | ⏳ |
| bizcity-zalo-bot | ⏳ | ⏳ | ⏳ | ⏳ | N/A | ⏳ |
| bizcity-automation | ⏳ | ⏳ | ⏳ | ⏳ | N/A | ⏳ |
| bizcity-brain-level | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ | ⏳ |
| bizcity-facebook-bot | ⏳ | ⏳ | ⏳ | ⏳ | N/A | ⏳ |
| bizcity-bot-webchat | N/A | N/A | N/A | N/A | N/A | ⏳ |
| bizcity-video-kling | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| bizcity-tarot | ✅ | ✅ | ⏳ | ⏳ | ✅ | ✅ |
| bizcoach-map | ✅ | ✅ | ⏳ | ⏳ | ✅ | ✅ |
| **bizcity-tool-woo** | ✅ | ✅ | ✅ | ✅ | N/A | ✅ |
| **bizcity-tool-content** | ✅ | ✅ | ✅ | ✅ | N/A | ⏳ |
| **bizcity-tool-facebook** | ✅ | ✅ | ✅ | ✅ | N/A | ⏳ |
| **bizcity-tool-reminder** | ✅ | ✅ | ✅ | ✅ | N/A | ⏳ |
| **bizcity-tool-image** | ✅ | ✅ | ✅ | ✅ | N/A | ⏳ |

> ⚠️ **Phase 10 Blocker**: `bizcity_intent_tools_ready` hook chưa được fire từ engine → tool callbacks của tất cả bizcity-tool-* chưa thực sự active. Built-in tools trong `class-intent-tools.php` vẫn đang xử lý. Xem Known Issue #11.

---

## 3. Roadmap Phase Status

> Đồng bộ với [ARCHITECTURE.md Section 15](ARCHITECTURE.md#15-roadmap-phát-triển).
> Cập nhật khi có thay đổi trạng thái.

| Phase | Mô tả | Status | % Done | Ghi chú |
|-------|-------|--------|--------|---------|
| **Phase 10.4** | **🚨 Pre-flight Input Gate — Missing Field Detection & Polling** | 🔄 In Progress | 60% | Core engine DONE (preflight_validate, extract_answers, partial plan transient). Cần: poll UX, multi-tool schema aggregation, conversation history scan, 1000+ tool scale audit |
| Phase 1 | Foundation — Gateway + Intent Engine | ✅ Done | 100% | Stable |
| Phase 2 | Knowledge Layer + RAG | ✅ Done | 100% | Stable v1.0 |
| Phase 3 | User Memory + Agent Binding | ✅ Done | 95% | `class-user-memory.php` stable |
| Phase 4 | Empathic Intelligence — Style & Emotion | 🔄 In Progress | 90% | 6 branches ACTIONABLE ✅ VERIFIED — test cases passed |
| **Phase 4.5** | **Companion Intelligence** | 🔄 In Progress | 92% | 4 classes OK; hook fired; goal-abandon logic ✅; bond score pipeline active; emotional smoothing ✅ |
| Phase 5 | Goal Tracker + Adaptive Intel | ⏳ Pending | 0% | Design done |
| Phase 6 | Rhythm Manager + Prompt Builder Upgrade | ⏳ Pending | 0% | Design done |
| Phase 7 | Knowledge Binding + Character Role Architecture | ✅ Done | 90% | `owner_type` done, `build_provider_context` pending |
| Phase 8 | 6-Layer Dual Context + Webchat Sessions | ✅ Done | 95% | Schema V3.0 done; rolling_summary pending |
| Phase 8.5 | Webchat Schema V3.0 | ✅ Done | 95% | `build_rolling_summary` pending |
| Phase 9 | Profile Context Provider | ✅ Done | 85% | Subscription + cross-agent sharing pending |
| **Phase 10** | **Pipeline Orchestration + Tool Registry** | 🔄 In Progress | 85% | Execution Logger ✅; Working Panel ✅; `tool_step` logging ✅; Working Panel floating UI ✅; `bizcityTaskStarted/Completed` events ✅; **bizcity-executor v1.2.0** ✅; **bizcity-planner v1.0.0** ✅; intent→planner→executor wiring ✅; prompt_logs DB ✅; 3 new admin tabs (Tools, Prompt Logs, Executor) ✅; **CodingPipeline** ✅; **Planner seed data** ✅; **Tool content planner tags** ✅; cần: fire `bizcity_intent_tools_ready`, more tool plugins, conflict resolution |
| **Phase 10.1** | **Execution Monitor (Working Panel)** | ✅ Done | 100% | `class-working-panel.php` — floating panel, auto-poll, live step feed, JS event hooks |
| **Phase 10.2** | **Executor + Planner MU-Plugins** | ✅ Done | 100% | bizcity-executor 11+ classes, bizcity-planner 21 files, intent→planner→executor hooks wired |
| **Phase 10.3** | **Observability (Prompt Logs + Admin Tabs)** | ✅ Done | 100% | `bizcity_intent_prompt_logs` 26-col table; Intent Monitor 7 tabs (Tools, Prompt Logs, Executor) |
| **Phase 10c** | **Human-in-Loop Pipeline Audit** | ✅ Done | 100% | B1-B4 bug fixes, 10 optimization proposals, ARCHITECTURE.md + ROADMAP.md updated |
| **Phase 11** | **Human-in-Loop Slot-Filling Optimization** | ✅ Done | 100% | Sprint 1: O1, O2, O3, O9 ✅ | Sprint 2: O4, O5, O8, O10, HIL Checklist Tab ✅ | Sprint 3: O6, analytics, HIL webchat, cleanup ✅ |
| **Phase 10e** | **Unified Single-Call Classification (v3.8.0)** | ✅ Done | 100% | 3 LLM calls → 1; focused top-N schema; regex bias hint; configurable top_n_tools UI; inline entity extraction |
| **Phase 10f** | **Dual-Logic Tool Routing & Plugin Gathering (v3.9.0)** | 🔄 In Progress | 70% | Logic 1 (Registry LLM) + Logic 2 (@Tag Direct); Plugin Gathering class; plugin_slug DB flow; UI badge during loading; floating indicator removed; ARCHITECTURE.md §10f rewritten; cần: LLM entity extraction, multi-tool gathering, public webchat support |
| **Phase 12.1** | **LLM Slot Bridge (v3.9.1)** | ✅ Done | 100% | Intelligent provide_input extraction; choice fuzzy-match; multi-slot; retry/cancel; v3.7 bug fixes (small_talk override, provider_hint guard, image turn history) |
| Phase 10.5 | Profile Context + Cross-Agent Personalization | ⏳ Pending | 10% | Partial từ Phase 9 |
| Phase 12 | Platform SDK & Plugin Marketplace | ⏳ Pending | 20% | bizcity-market-plugin là nền tảng |
| **Phase 13** | **Dual Context Architecture (v4.0)** | 🔄 In Progress | 70% | Sprint 1 ✅, Sprint 3 ✅, Sprint 4 partial ✅; P13.2 Pre-flight Confirm ✅; P13.3 Step-by-Step SSE ✅; P13.4 Tool Pill `/slug` ✅ |
| **Phase 13.2** | **Pre-flight Confirmation (v4.1)** | ✅ Done | 100% | Inline confirm in engine `call_tool`; `build_confirm_prompt()`; skip for no-visible-slot tools |
| **Phase 13.3** | **Step-by-Step SSE Status Messages (v4.1)** | ✅ Done | 100% | 13 SSE status messages across full pipeline (classify → plan → confirm → execute → callback) |
| **Phase 13.4** | **Tool Pill `/slug` — Logic 2 Universal Routing (v4.2)** | ✅ Done | 100% | Frontend `/tool_name` prefix in sendMsg; Backend DB+pattern dual lookup; `.bizc-msg-slash` styled bubble |
| **Phase 15** | **5-Mode Classification & Knowledge Cleanup (v4.4)** | ✅ Done | 100% | MODE_AMBIGUOUS; deprecate Knowledge Router; clean knowledge prompt; 5 files changed |
| **Phase 16** | **Image+Text Smart Routing (v4.3.6–v4.3.7)** | ✅ Done | 100% | Smart bypass Step 1.5C; `_images`→slot mapping; Step 1.7 suggest-confirm; `detect_tool_suggest_confirm()`; video-kling fixes |
| **Phase 18** | **3-Memory Architecture (v4.5)** | ✅ Done | 100% | Rolling Summary mỗi 5 turns (fast LLM), Episodic Memory events, 8-layer context, token_count tracking, Recent Window 6 msgs, UI tracker |
| **Phase 19** | **Webchat UI Overhaul + Stream Fix (v4.6)** | ✅ Done | 100% | ReactMarkdown + MediaPreview + Mermaid v11, stream truncation fix (thinking model→flash), minimax→gemini-2.5-flash migration, tool suggest disabled |
| **Phase 20** | **Slot Auto-Map Fix & Accept-Image (v4.6.1)** | ✅ Done | 100% | `no_auto_map` + `accept_image` slot flags, `extract_content_after_trigger()`, 6 router guard fixes, image→text slot auto-fill |
| **Phase 21** | **Production Fixes — Confirm Flow + Auto-Select (v4.6.2)** | ✅ Done | 100% | confirm_phrases 35+, followup: prefix strip, highlight auto-select, preIntentEstimate bi-directional matching |
| **Phase 22** | **Calo Plugin — 3 Bug Fixes (v4.6.3)** | ✅ Done | 100% | photo_url image slot resolution, _raw_message meal detection, resolved_tool_name tracking |
| **Phase 23** | **Frontend UI Fix — 4 Bugs (v4.6.4)** | ✅ Done | 100% | Send icon fill:none, sidebar scrollbar, iframe postMessage, tool click auto-mention |
| **Phase 24** | **Frontend Chat Page Fix & Guest Direct Chat (v4.6.5)** | ✅ Done | 100% | page-aiagent-home.php stripped; type="module" priority 99999; nuclear CSS; guest 5-msg trial; WC login overlay |
| **Phase 25** | **LLM-Based Confirm Analyzer (v4.7.0)** | ✅ Done | 100% | 3-tier confirm analysis (fast regex + LLM); 5 intents: accept, accept_modify, modify, reject, new_goal; slot enrichment |
| **Phase 26** | **HIL Robustness Review & Hardening (v4.7.1)** | ✅ Done | 100% | 6-issue audit; Off-topic/greeting escape (Mode Classifier); Type guard fallback (Router); Issues 3–6 confirmed fixed in prior phases |
| **Phase 27** | **Twin Core Phase 0 — Context Cleanup & Follow-up (v5.0)** | ✅ Done | 100% | bizcity-twin-core mu-plugin (6 new files); Focus Gate context pollution fix (5 patches); Tool suggestion removal (4 injection points); Twin Suggest follow-up questions; Twin Trace pipeline visibility (7+2 files) |
| **Phase 28** | **Role Confusion Fix — Anti-Identity-Reversal (v5.0.1)** | ✅ Done | 100% | Memory extraction role-anchoring; Memory header role boundary; Bond 9-10 guardrail; Conversation label disambiguation; 3 role blocks hardened |
| Phase 14 | Multi-Tenant AI Platform | ⏳ Pending | 0% | (moved from old P13) |

---

## 4. Daily Change Log

> Format: `### YYYY-MM-DD`
> Mỗi entry: `- [Plugin] Mô tả thay đổi — Phase X`

---

### 2026-03-23 — Session 14: Role Confusion Fix — Anti-Identity-Reversal (v5.0.1)

> **Mục tiêu**: Fix AI role reversal — AI tự xưng bằng tên user ("Chu đây!"), nhập vai thành user thay vì trợ lý.

#### 🐛 Bug: AI Role Reversal

**Triệu chứng**:
1. AI nói "À, Chu đây! 😄" → tự xưng mình LÀ user
2. AI nói "Mày có cao kiến gì không?" → dùng "mày" với user thật (đảo vai)
3. AI nói "Anh Chu đẹp trai đã hiểu rõ hơn" → khen user như thể user vừa giải thích

**3 Root Causes**:

| # | Nguyên nhân | File |
|:-:|-------------|------|
| RC1 | Memory extraction không anchor vai trò — `[identity] Tên Chu` → AI đọc "Tôi là Chu" | `class-user-memory.php` |
| RC2 | Memory injection "GHI ĐÈ MỌI HƯỚNG DẪN" + "BẮT BUỘC TUÂN THỦ" → override role block | `class-user-memory.php` |
| RC3 | Bond 9-10 "hoàn toàn tự nhiên" + xưng mày tao → AI mất ranh giới vai trò | `class-companion-context.php` |

#### 🔧 Fixes Applied (4 areas)

| # | Area | Change |
|:-:|------|--------|
| 1 | **Memory extraction prompt** | Thêm "⛔ QUY TẮC QUAN TRỌNG VỀ VAI TRÒ" — viết ngôi thứ ba, phân biệt user vs AI |
| 2 | **Memory injection header** | Header mới "KÝ ỨC VỀ USER" + "⛔ RANH GIỚI VAI TRÒ" — xóa "GHI ĐÈ MỌI HƯỚNG DẪN" |
| 3 | **Bond tone directive** | Thêm guardrail tier 9-10: "DÙ THÂN MẬT, bạn vẫn là AI Trợ lý" |
| 4 | **Role blocks × 3** | Thêm "⛔ RANH GIỚI VAI TRÒ BẮT BUỘC" — cấm tự xưng tên user, cấm nhập vai |
| 5 | **Conversation history labels** | `Chủ Nhân (User)` / `AI Trợ lý (Bạn)` + chú giải role |

#### 📁 Files Changed

| File | Plugin | Change |
|------|--------|--------|
| `class-user-memory.php` | bizcity-knowledge | PATCH — extraction prompt + injection header |
| `class-companion-context.php` | bizcity-knowledge | PATCH — bond tone guardrail |
| `class-intent-stream.php` | bizcity-intent | PATCH — role block + conversation labels |
| `class-chat-gateway.php` | bizcity-knowledge | PATCH — 2 role blocks |

---

### 2026-03-22 — Session 13: Twin Core Phase 0 — Context Cleanup & Tool Suggestion Removal (v5.0)

> **Mục tiêu**: (1) Build Twin Core Phase 0 — Focus Gate chặn context pollution. (2) Xóa toàn bộ proactive tool suggestion, thay bằng follow-up questions dựa trên memory + session.

#### 🏗️ Twin Core — 6 NEW files

| File | Mô tả |
|------|--------|
| `bizcity-twin-core.php` | MU-plugin loader (NEW) |
| `bootstrap.php` | Entry point, load classes, hook Focus Gate pri 1, feature flags |
| `class-focus-router.php` | 6-mode focus profile resolver (emotion/reflection/knowledge/planning/execution/studio) |
| `class-focus-gate.php` | Static gate trên filter chain — `should_inject()`, `get_memory_mode()`, `ensure_resolved()` |
| `class-twin-trace.php` | Pipeline trace logger — fires `twin:*` SSE steps |
| `class-twin-suggest.php` | Follow-up question builder — memory hints + session hints → gợi ý gợi mở |

#### 🔧 Context Pollution Fix — 5 PATCH files

| File | Change |
|------|--------|
| `class-profile-context.php` | Gate astro + coaching sections via `should_inject()` + trace |
| `class-chat-gateway.php` | Gate transit + response rules + `ensure_resolved()` + trace; Fix 4 tool suggestion END REMINDER blocks |
| `class-user-memory.php` | Focus-filtered memory (explicit/relevant/all) + trace |
| `class-context-builder.php` | Gate session/cross_session/project layers + trace |
| `class-companion-context.php` | Gate companion injection + trace |

#### 🚫 Tool Suggestion Removal — 4 injection points fixed

| # | Location | Trước (v4.x) | Sau (v5.0) |
|:-:|----------|-------------|------------|
| 1 | `intent-stream.php` role block L1262 | "gợi ý kích hoạt AI Agent từ Chợ AI Agent" | "KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent" |
| 2 | `intent-stream.php` END REMINDER L1577-1600 | "HÃY HỎI: Bạn có muốn dùng công cụ X không? 🎯" + suggest_tool SSE | Xóa tool block → "đặt 1-2 câu hỏi gợi mở" |
| 3 | `chat-gateway.php` build_system_prompt END REMINDER | Tool label + description + "HÃY HƯỚNG DẪN CỤ THỂ" | Xóa tool block → "đặt 1-2 câu hỏi gợi mở" |
| 4 | `chat-gateway.php` prepare_llm_call END REMINDER | Tool label + description + "HÃY HƯỚNG DẪN CỤ THỂ" | Xóa tool block → "đặt 1-2 câu hỏi gợi mở" |

#### 💡 Twin Suggest — thay thế tool suggestion

- `BizCity_Twin_Suggest::build()` inject vào cả 2 path:
  - Path A: `build_system_prompt()` section 7.6 (non-streaming)
  - Path B: `build_llm_messages()` after end_reminder (SSE streaming)
- Gather: episodic memory habits, rolling memory active/completed, user memory goals/pain
- Output: 2 câu hỏi gợi mở context-aware, mode-specific tonality

#### 📡 Twin Trace — pipeline visibility

- 7 PHP files injected with `twin:*` trace steps
- 2 ChatPanel.jsx files updated with 6 new step labels
- Steps: `twin:focus_resolve`, `twin:gate`, `twin:prompt_start`, `twin:layer:*`, `twin:memory`, `twin:prompt_summary`

#### 📁 Files Changed (total: 13 files)

| File | Plugin | Change |
|------|--------|--------|
| `bizcity-twin-core.php` | bizcity-twin-core | NEW — mu-plugin loader |
| `bootstrap.php` | bizcity-twin-core | NEW+PATCH — entry point, hooks, feature flags |
| `class-focus-router.php` | bizcity-twin-core | NEW — 6-mode resolver |
| `class-focus-gate.php` | bizcity-twin-core | NEW+PATCH — gate + ensure_resolved + trace |
| `class-twin-trace.php` | bizcity-twin-core | NEW — trace logger |
| `class-twin-suggest.php` | bizcity-twin-core | NEW — follow-up questions |
| `class-intent-stream.php` | bizcity-intent | PATCH — role block + END REMINDER + SSE done + Twin Suggest |
| `class-chat-gateway.php` | bizcity-knowledge | PATCH — 2 END REMINDERs + trace + Twin Suggest |
| `class-profile-context.php` | bizcity-knowledge | PATCH — gate + trace |
| `class-user-memory.php` | bizcity-knowledge | PATCH — gate + trace |
| `class-companion-context.php` | bizcity-knowledge | PATCH — gate + trace |
| `class-context-builder.php` | bizcity-intent | PATCH — gate + trace |
| `ChatPanel.jsx` ×2 | webchat + notebook | PATCH — 6 twin:* step labels |

---

### 2026-03-20 — Session 12: HIL Robustness Review & Hardening (v4.7.1)

> **Mục tiêu**: Tổng rà soát 6 vấn đề HIL — xác nhận 4 issues đã fix, vá 2 lỗ hổng còn lại (off-topic escape + type guard).

#### 📋 6-Issue HIL Audit

| # | Vấn đề | Kết quả | Phase gốc |
|:-:|--------|---------|:---------:|
| 1 | Auto-Continue Hijacking (nuốt tin nhắn lạc đề) | 🔧 Fixed v4.7.1 | — |
| 2 | Raw Entity Extraction Garbage (fallback gán rác) | 🔧 Fixed v4.7.1 | — |
| 3 | Emotion Gate @mention bypass | ✅ Already fixed | v4.0 |
| 4 | WAITING_USER infinite loop (retry vô hạn) | ✅ Already fixed | v3.9.1 |
| 5 | Vietnamese keyword false positives | ✅ Already fixed | v4.6.5 |
| 6 | Confirm/Image loop | ✅ Already fixed | v4.7.0 |

#### 🔧 Fix 1: Off-Topic / Greeting Escape — `class-mode-classifier.php`

- Thêm layer thứ 3 vào WAITING_USER escape stack (sau new-goal regex + knowledge-inquiry)
- Detect greeting patterns: `hi`, `chào`, `xin chào`, `alo`, `bạn ơi`
- Detect meta-questions: `chức năng này là gì?`, `tool này dùng để làm gì?`
- Action: `$skip_provide_input = true` → LLM classify → non-execution branch
- Pipeline: User "hi" → skip provide_input → LLM → ambiguous/knowledge → natural response

#### 🔧 Fix 2: Type Guard Fallback Path — `class-intent-router.php`

- Guard `fill_waiting_field_entities()` fallback (khi LLM unavailable)
- `number` type: reject nếu message không chứa digit → clarification
- `choice` type: always reject without LLM → show available choices
- `text` type: pass through (hành vi cũ, không cần guard)

#### 📁 Files Changed

| File | Change |
|------|--------|
| `includes/class-mode-classifier.php` | Off-topic/greeting escape block (~L274–300) |
| `includes/class-intent-router.php` | Type guard in fallback path (~L2283–2310) |
| `ARCHITECTURE.md` | Section 27: HIL Robustness Review & Hardening |
| `SYSTEM-LOG.md` | Session 12 log + Phase 26 roadmap row |
| `ROADMAP.md` | Phase P21 entry |

---

### 2026-03-15 — Session 11: LLM-Based Confirm Analyzer (v4.7.0)

> **Mục tiêu**: Thay thế regex binary "ok/not-ok" confirm check bằng LLM-based 5-intent analyzer — hỗ trợ enrichment, reject, new_goal.

#### 🐛 Bug: Confirm flow không xử lý supplementary/enrichment responses

**Vấn đề**: Khi confirm card hiển thị (ví dụ prompt="Tạo ảnh giúp tôi"), user trả lời "xịn xò, studio chuyên nghiệp, có người mẫu đứng cạnh":
- Old: Regex không match "ok" → gọi `parse_confirm_correction()` → LLM prompt bias "SỬA/THAY ĐỔI" → không detect enrichment → re-show confirm cũ.
- New: `BizCity_Confirm_Analyzer` → LLM detect `accept_modify` → merge "xịn xò..." vào prompt → execute ngay.

**Approach (giống Claude/ChatGPT)**: Mỗi user response trong confirm context có thể multi-intent. LLM phân tích tổng thể thay vì binary check.

#### 🆕 `class-confirm-analyzer.php` — 3-Tier Analysis

| Tier | Method | Cost | Trường hợp |
|------|--------|------|-----------|
| Tier 1 | Fast accept (regex) | 0 | "ok", "được", "tiếp tục", ~35 phrases |
| Tier 2 | Fast reject (regex) | 0 | "hủy", "thôi", "không", ~20 phrases |
| Tier 3 | LLM analysis | ~$0.001 | Mọi response khác → 5-intent classification |

#### 5 Intent Types

| Intent | Hành vi | Ví dụ |
|--------|---------|-------|
| `accept` | Execute as-is | "ok", "chạy đi" |
| `accept_modify` | Update slots + execute (KHÔNG re-confirm) | "xịn xò, studio chuyên nghiệp" (enriches prompt) |
| `modify` | Update slots + re-show confirm | "sửa lại chủ đề thành dinh dưỡng" |
| `reject` | Complete conversation | "hủy", "thôi", "không làm" |
| `new_goal` | Complete + redirect | User nói chủ đề hoàn toàn khác |

**Key innovation**: `accept_modify` — LLM nhận diện user đang bổ sung/enrichment (không phải sửa) → MERGE giá trị cũ + mới vào slot → execute ngay, không re-confirm tránh loop.

#### LLM Prompt Design
- Input: Slot schema (field, type, current_value, choices) + user message
- Output: `{"intent": "accept_modify", "slot_updates": {"prompt": "merged enriched value"}}`
- Temperature: 0.1 (near-deterministic)
- Validation: Chỉ accept slot_updates cho fields defined in plan

#### Engine Integration
Thay thế block regex `$confirm_phrases` + `parse_confirm_correction()` tại Step 6 `call_tool` case bằng single `BizCity_Confirm_Analyzer::instance()->analyze()` call.

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-confirm-analyzer.php` | bizcity-intent | **NEW** — 3-tier confirm response analyzer (fast regex + LLM) |
| `class-intent-engine.php` | bizcity-intent | Replace regex confirm + `parse_confirm_correction()` with `BizCity_Confirm_Analyzer` 5-intent flow |
| `bootstrap.php` | bizcity-intent | `require_once class-confirm-analyzer.php` |

---

### 2026-03-15 — Session 10: Frontend Chat Page Fix & Guest Direct Chat (v4.6.5)

> **Mục tiêu**: Fix `/chat/` frontend page không load React app; cho phép guest trò chuyện 5 tin trước khi yêu cầu đăng nhập.

#### 🐛 Bug: Frontend `/chat/` trang trắng — `import.meta` outside module

**Vấn đề**: Trang `coachluukieu.com/chat/` không hiển thị gì, console error: `Cannot use 'import.meta' outside a module`. Trang admin wp-admin chat React load bình thường.

**Root cause (3 lớp)**:
1. **PHP template bloat**: `page-aiagent-home.php` render `<header>`, drawers, mobile JS (~200 dòng HTML/JS) TRƯỚC `<div id="root">`. React app dùng `h-dvh` (100dvh) + body `overflow:hidden` → bị đẩy xuống dưới viewport, không thấy.
2. **`type="module"` bị mất**: `do_enqueue_react_assets()` thêm `type="module"` qua `script_loader_tag` tại priority 10. Nhưng Flatsome theme thêm `type='text/javascript'` trước đó → browser dùng `type` attribute đầu tiên → ES module `import.meta` syntax error.
3. **Guest fallback sai**: Khi `!$is_logged_in`, template gọi `BizCity_WebChat_Dashboard_Minimal::enqueue_assets()` (legacy jQuery) thay vì React.

**Fix**:
1. **Strip template to shell**: Xoá toàn bộ PHP-rendered UI (header, drawers, mobile JS, rolling memory tracker). Body chỉ còn `render_dashboard_react()` + WC login overlay cho guest.
2. **Nuclear CSS rule**: `body.aiagent-home-body > *:not(#root):not(#aiagent-auth-overlay):not(script):not(style):not(link):not(noscript) { display: none !important; }` — ẩn mọi theme element leak từ `wp_head()`.
3. **Force `type="module"` tại priority 99999**: Regex strip mọi `type=` attribute cũ trước, rồi thêm `type="module"` → đảm bảo ES module chạy đúng.
4. **Always React**: Bỏ hết `$ui_mode`, `$template_style`, `$guest_chat_enabled` conditional. Mọi user (logged-in + guest) đều dùng React.

#### 🆕 Guest Direct Chat (5-message trial)

**Trước**: Guest phải đăng nhập mới thấy chat. Hoặc fallback minimal dashboard (legacy, thường bị lỗi).

**Sau**: Guest mở `/chat/` → thấy React chat đầy đủ → nhắn được 5 tin → hết quota hiện WC login dialog overlay (không redirect).

| Thay đổi | Chi tiết |
|----------|---------|
| `GUEST_MSG_LIMIT` | 3 → **5** |
| Hết quota | `window.location.href` → `window.bizcShowAuthOverlay()` (WC dialog popup) |
| Link "Đăng nhập" trong hint | Cũng gọi auth overlay thay vì navigate |
| `bizcShowAuthOverlay()` | Global function PHP define, React gọi khi cần |

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `page-aiagent-home.php` | bizcity-bot-webchat | Strip 300+ dòng HTML/JS redundant; chỉ còn React mount + WC login overlay; force `type="module"` priority 99999; nuclear CSS hide rule |
| `PromptForm.jsx` | bizcity-bot-webchat (React) | `GUEST_MSG_LIMIT` 3→5; `bizcShowAuthOverlay()` thay redirect; login link onClick handler |

---

### 2026-03-15 — Session 9: Frontend UI Fix — 4 bugs (v4.6.4)

> **Mục tiêu**: Fix 4 frontend UI bugs: send button icon, sidebar scrollbar, iframe shortcuts, auto-mention on tool click.

#### 🐛 Bug 1: Send button icon invisible

**Vấn đề**: `button.pf-send.active` hiển thị blue box nhưng không có arrow icon bên trong.

**Root cause**: Flatsome/WP theme CSS set `svg { fill: currentColor }` globally. Lucide icons dùng `stroke` (not fill) → fill override khiến SVG path bị lấp.

**Fix**: Thêm `.pf-send svg { fill: none; }` vào `index.css`.

#### 🐛 Bug 2: Sidebar task list double scrollbar

**Vấn đề**: Danh sách "Nhiệm vụ" có 2 thanh scroll đè lên nhau.

**Root cause**: Parent container (`flex-1 overflow-y-auto`) + inner task list (`max-h-[180px] overflow-y-auto`) → nested scrollbar.

**Fix**: Bỏ `max-h-[180px] overflow-y-auto` khỏi task list container trong `Sidebar.jsx`.

#### 🐛 Bug 3: Iframe tool-content shortcuts không gửi message

**Vấn đề**: Trong trang iframe `/tool-content/`, click quick shortcut buttons → `postMessage` gửi nhưng React app không nhận.

**Root cause**: React app KHÔNG CÓ `postMessage` listener. Legacy PHP dashboard (`class-admin-dashboard.2026.03.0.php` line 5253) có nhưng chưa migrate sang React.

**Fix**: Thêm `window.addEventListener('message', handleIframeMessage)` trong `App.jsx` (always mounted). nhận `bizcity_agent_command` type, resolve plugin mention từ `source` field against agent cache, dispatch `SET_MENTION` + `SET_ACTIVE_PANEL('chat')` + `SET_PENDING_COMMAND`.

#### 🐛 Bug 4: Tool click không auto-set mention

**Vấn đề**: Click tool trong ToolsCatalog hoặc welcome screen → gửi prompt nhưng không set `plugin_slug`, `tool_name` → intent_conversations không track đúng.

**Root cause**: `handleToolClick` chỉ pass `prompt`, không có `pluginSlug`. `handleWelcomeTool` gửi `pluginSlug: ''`.

**Fix**:
- `ToolsCatalog.jsx`: `handleToolClick(tool, group)` + `handleExampleClick(e, example, group)` pass `group.plugin`
- `ChatPanel.jsx`: `handleWelcomeTool` resolve short slug → full slug via agent cache (`a.slug.endsWith('-' + slug)`)
- `ChatContext.jsx`: Thêm `SET_PENDING_COMMAND` action
- `PromptForm.jsx`: Expose `window.__bizcAgentsCache` for cross-component access

#### Short slug → Full slug mapping

`build_tools_catalog()` PHP strips `bizcity-` prefix: `bizcity-tool-content` → `tool-content`.
Agent cache has full slugs. Fixed with `a.slug.endsWith('-' + slug)` matching.

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `index.css` | bizcity-bot-webchat (React) | `.pf-send svg { fill: none; }` |
| `Sidebar.jsx` | bizcity-bot-webchat (React) | Bỏ `max-h-[180px] overflow-y-auto` khỏi task list |
| `App.jsx` | bizcity-bot-webchat (React) | `postMessage` listener for iframe commands |
| `ChatContext.jsx` | bizcity-bot-webchat (React) | `SET_PENDING_COMMAND` action |
| `ChatPanel.jsx` | bizcity-bot-webchat (React) | `pendingCommand` effect + `handleWelcomeTool` plugin resolution |
| `ToolsCatalog.jsx` | bizcity-bot-webchat (React) | Pass `group` to click handlers |
| `PromptForm.jsx` | bizcity-bot-webchat (React) | `window.__bizcAgentsCache` exposure |

---

### 2026-03-15 — Session 8: Calo Plugin — 3 Bug Fixes (v4.6.3)

> **Mục tiêu**: Fix 3 production bugs trong bizcity-agent-calo: photo_url không filled, meal type detection sai, tool_name trống trong messages.

#### 🐛 Bug 1: `photo_url` không filled khi user gửi ảnh

**Vấn đề**: User gửi ảnh bữa ăn nhưng `photo_url` slot không nhận ảnh, `_images` bị gán vào `food_input` (text slot) thay vì `photo_url` (image slot).

**Root cause**: `_waiting_field` trỏ vào `food_input` (text slot) → engine map `_images` vào đó. Không resolve tới image slot thực (`photo_url`).

**Fix**: Engine `provide_input` khi `_waiting_field` trỏ vào non-image slot, tự tìm slot `type:'image'` đầu tiên trong plan và gán ảnh vào đó.

#### 🐛 Bug 2: Meal type detection sai

**Vấn đề**: `auto_detect_meal_type()` chỉ dùng `food_input` text để detect bữa ăn. Nhưng khi user gửi ảnh, `food_input = '[phân tích từ ảnh]'` → không detect được "bữa sáng/trưa/tối".

**Fix**: Truyền `_raw_message` (tin nhắn gốc của user) vào `auto_detect_meal_type()`: `$this->auto_detect_meal_type( $food_input . ' ' . $raw_msg )`.

#### 🐛 Bug 3: `tool_name` trống trong messages

**Vấn đề**: Message tracking (`update_message_tracking()`) đọc `$result['meta']['tool_name']` nhưng field này trống → `intent_conversations` không có `tool_name`.

**Root cause**: `tool_name` chỉ set khi `action=call_tool` (line 629 planner), nhưng trống cho nhiều paths khác.

**Fix**: Thêm `resolved_tool_name` trước Step 6 switch trong engine — resolve từ `$plan_result['tool_name']` hoặc fallback `$this->planner->get_plan()['tool']`.

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-engine.php` | bizcity-intent | `_images` field resolution (non-image slot → image slot); `resolved_tool_name` before Step 6 |
| `class-intent-provider.php` | bizcity-agent-calo | `_raw_message` for meal type detection |

---

### 2026-03-15 — Session 7: Production Fixes — Confirm Flow + Frontend Auto-Select (v4.6.2)

> **Mục tiêu**: Fix production bugs (confirm flow, followup interception, planner prefix), frontend auto-select plugin, preIntentEstimate matching.

#### 🐛 Bug 1: Confirm flow — user nói "ok" nhưng engine không hiểu

**Fix**: Mở rộng `confirm_phrases` lên ~35 Vietnamese phrases (ok, được, đồng ý, xác nhận, chạy đi, etc.)

#### 🐛 Bug 2: Step 1.6C — user muốn thoát nhưng bị kẹt

**Fix**: Thêm escape hatch tại Step 1.6C cho `_awaiting_confirm` state.

#### 🐛 Bug 3: Planner `followup:` prefix

**Fix**: `get_plan()` strip `followup:` prefix trong tool name.

#### 🐛 Bug 4: URL bị truncate trong confirm prompt

**Fix**: Show full URL values trong `build_confirm_prompt()`.

#### 🆕 Frontend auto-select via `highlight` field

**Trước**: Frontend auto-select plugin khi `matchSlugs.length === 1`.
**Sau**: Dùng backend `highlight` field (top-scored slug) — chính xác hơn khi nhiều partial matches.

#### 🆕 preIntentEstimate bi-directional matching

**Trước**: Chỉ check `query.includes(keyword)` — short queries như "gemini" không match vì keyword dài hơn query.
**Sau**: Thêm reverse matching (`keyword.includes(query)`) với 4 điểm: tool_name +8, goal_label +7, title +5, L2 catalog name +4. Yêu cầu `mb_strlen >= 3`.

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-engine.php` | bizcity-intent | confirm_phrases expanded ~35; Step 1.6C escape hatch; URL full display in confirm prompt |
| `class-intent-planner.php` | bizcity-intent | `followup:` prefix stripping in `get_plan()` |
| `class-plugin-suggestion-api.php` | bizcity-bot-webchat | Bi-directional matching (4 reverse match points) |
| `PromptForm.jsx` | bizcity-bot-webchat (React) | `autoMentionRef` auto-select; `highlight` field usage |

---

### 2026-03-15 — Session 6: Slot Auto-Map Fix & Accept-Image (v4.6.1)

> **Mục tiêu**: Fix `ask_field` trống (planner không detect missing slots) và cho phép user gửi ảnh thay text cho slot.

#### 🐛 Bug 1: `ask_field` trống — auto-map lấy raw command làm slot value

**Vấn đề**: Khi user nói "ghi nhật ký bữa ăn nhé", engine auto-map toàn bộ message vào `food_input` slot. Planner thấy slot đã filled → trả `call_tool` ngay với `ask_field: ""`, không hỏi user "Bạn đã ăn gì?".

**Root cause**: `class-intent-engine.php` có đoạn auto-map (v4.0.1) fill message → first required text slot. Calo's `food_input` cần tên món ăn cụ thể, không phải câu lệnh trigger.

**Fix**:
1. **`no_auto_map` flag** (slot config) — Provider đánh dấu để engine skip auto-map cho slot đó
2. **`extract_content_after_trigger()`** (engine helper) — Strip trigger pattern, lấy phần content thực (VD: "ghi bữa ăn **phở bò**" → "phở bò")
3. Nếu trigger strip ra chuỗi < 3 ký tự → planner sẽ hỏi user

#### 🐛 Bug 2: Ảnh bị drop khi `waiting_for !== 'image'`

**Vấn đề**: Khi system hỏi "Bạn đã ăn gì?", `waiting_for=text`, `waiting_field=food_input`. User gửi ảnh bữa ăn → router guard chỉ capture images khi `waiting_for === 'image'` → **ảnh bị bỏ qua hoàn toàn**.

**Root cause**: Router có 6 guard locations, tất cả đều kiểm tra `(waiting_for === 'image')` trước khi pass `_images` vào entities.

**Fix** (3 lớp):
1. **Router guards** (6 vị trí) — Khi `waiting_for !== 'image'` nhưng user gửi ảnh → vẫn pass `_images` vào entities
2. **Image-only guard** (step 3a + LLM path) — Kiểm tra plan có slot `accept_image` → không drop ảnh xuống step 3b
3. **Engine provide_input** — Sau khi map ảnh vào `photo_url`, kiểm tra `prev_waiting_field` có `accept_image` → auto-fill `food_input = '[phân tích từ ảnh]'`

#### 🆕 Slot Config Flags

| Flag | Type | Mô tả |
|------|------|-------|
| `no_auto_map` | `bool` | Engine không auto-fill raw command message vào slot này |
| `accept_image` | `bool` | Slot text chấp nhận ảnh thay thế. Tool callback dùng AI Vision phân tích |

#### Expected Behavior Matrix

| User action | `food_input` | `photo_url` | Next step |
|---|---|---|---|
| "ghi bữa ăn nhé" | `""` (trigger only) | `""` | → `ask_user` "Bạn đã ăn gì?" |
| "ghi bữa ăn phở bò" | `"phở bò"` (trigger stripped) | `""` | → `call_tool` (text analyze) |
| Image only | `"[phân tích từ ảnh]"` | image URL | → `call_tool` (AI Vision) |
| Text + image | `"phở bò"` | image URL | → `call_tool` (Vision priority) |

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-engine.php` | bizcity-intent | `no_auto_map` check in auto-map loop, `extract_content_after_trigger()` helper, `accept_image` auto-fill in provide_input |
| `class-intent-router.php` | bizcity-intent | 6 guard locations: pass `_images` for non-image slots; step 3a + LLM guard: `accept_image` check |
| `class-intent-provider.php` | bizcity-agent-calo | `food_input`: add `no_auto_map: true`, `accept_image: true` |

---

### 2026-03-14 — Session 5: Webchat UI Overhaul + Stream Fix + Model Migration (v4.6)

> **Mục tiêu**: Nâng cấp giao diện /chat/ (Markdown, Media Preview, Mermaid), fix gẫy stream, thay model LLM, tắt tool suggest sai.

#### 🎨 Markdown Rendering Upgrade (`Message.jsx`, `format.js`)
- **Cài đặt**: `react-markdown` + `remark-gfm` + `rehype-raw`
- **Thay thế**: Custom `formatMsg()` → `<ReactMarkdown>` trong `Message.jsx`
- **Hỗ trợ đầy đủ**: headers, bold, italic, lists, links, tables, code blocks, inline HTML
- **Cleanup**: Xóa body cũ của `formatMsg()` (giữ stub cho compatibility), re-add `formatTime()` export

#### 🎨 Media Preview Auto-detect (`Message.jsx`)
- **MediaPreview component**: Detect URL trong message → auto render `<img>`, `<video>`, `<audio>` dựa trên extension
- **Extensions**: `.jpg/.jpeg/.png/.gif/.webp/.svg` → image, `.mp4/.webm/.mov` → video, `.mp3/.wav/.ogg` → audio
- **UX**: Click ảnh → open new tab, video/audio có controls

#### 🎨 Mermaid Diagram Rendering (`Mermaid.jsx` — NEW)
- **Component Mermaid.jsx**: Render mermaid code blocks từ markdown
- **Mermaid v11 async API**: `const { svg } = await mermaid.render(id, chart)` → `setSvgHtml(svg)`
- **Unique IDs**: useRef counter tránh conflict khi nhiều diagrams
- **Theme**: `neutral` + white background với border-radius
- **Integration**: Custom `code` component trong ReactMarkdown — lang `mermaid` → `<Mermaid>`, còn lại → `<pre><code>`

#### 🔧 Vite Build Fix (`vite.config.js`)
- **Vấn đề**: Chunk files 404 khi load từ WordPress (path mismatch)
- **Fix**: Thêm `base: './'` vào vite.config.js → relative paths cho tất cả chunks
- **wp_footer**: Confirm cần giữ `wp_footer()` — xóa gây black screen (React app không load được)

#### 🔧 Model Migration: minimax → gemini-2.5-flash
- **Lý do**: `minimax/minimax-m2.5` hay bị lỗi, timeout, không ổn định
- **Thay thế**: 9 occurrences across 4 files → `google/gemini-2.5-flash`
- **Files**: `class-openrouter-models.php` (DEFAULTS), `class-intent-engine.php` (compose), `class-executor-messenger.php` (LLM_MODEL), `class-intent-bridge.php` (2 model refs)

#### 🔧 Stream Truncation Fix (`class-intent-engine.php`)
- **Root cause**: `compose_natural_ask_prompt()` + `smooth_tool_ask_prompt()` dùng `google/gemini-2.5-pro` (thinking model) với `max_tokens=200` → reasoning tokens ăn hết budget → output chỉ còn 13-26 chars
- **Fix**: Đổi sang `google/gemini-2.5-flash` (non-thinking) + `max_tokens=500`
- **Evidence**: Console log `done fullLen=13` cho message "Đã rõ, vũ trụ" — server-side đã truncate trước khi gửi SSE

#### 🔧 Tool Suggestion Disabled (`class-intent-engine.php`)
- **Lý do**: `find_matching_tools_for_suggest()` matching sai tool — gợi ý không chính xác (ví dụ: gợi ý "Tư vấn chiêm tinh" cho "ghi thông tin bữa ăn", gợi ý "Nhật ký xuất nhập kho" cho bữa ăn)
- **Step 1.5C**: Comment out tool matching + `_image_text_suggested_tools` slot persistence. Giữ lại `_session_pending_images` buffering.
- **Step 1.7**: Comment out toàn bộ Tool Suggest Confirmation block
- **Step 1.5C mode override**: Tự vô hiệu vì `_image_text_suggest` flag không còn được set

#### 🔧 DB Context Fix (`class-intent-engine.php`)
- **Vấn đề**: `plugin_slug`, `tool_name`, `client_name` không được lưu vào DB message
- **Fix**: Patch propagation trong engine xử lý ask_user/complete actions
- **PHP fix**: Thay null-safe operator `?->` bằng explicit null checks (PHP 7.x compat)

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `views/src/components/Message.jsx` | bizcity-bot-webchat | ReactMarkdown + MediaPreview + Mermaid integration |
| `views/src/components/Mermaid.jsx` | bizcity-bot-webchat | **NEW** — Mermaid v11 async renderer component |
| `views/src/utils/format.js` | bizcity-bot-webchat | Remove old formatMsg body, keep stub, re-add formatTime |
| `views/vite.config.js` | bizcity-bot-webchat | `base: './'` for chunk path fix |
| `views/package.json` | bizcity-bot-webchat | +react-markdown, remark-gfm, rehype-raw, mermaid |
| `class-intent-engine.php` | bizcity-intent | Stream fix (2.5-pro→flash), tool suggest disabled, DB context fix |
| `class-openrouter-models.php` | bizcity-openrouter | DEFAULTS: minimax→gemini-2.5-flash |
| `class-executor-messenger.php` | bizcity-executor | LLM_MODEL: minimax→gemini-2.5-flash |
| `class-intent-bridge.php` | bizcity-executor | 2 model refs: minimax→gemini-2.5-flash |

---

### 2026-03-13 — Session 4: Phase 18 — 3-Memory Architecture (v4.5)

> **Phase 18** — Kiến trúc 3-Memory: Rolling Summary + Episodic Memory + User Memory. DB lean, chỉ lưu những gì cần thiết để tái tạo context.

#### 🔧 Rolling Memory (`class-rolling-memory.php` — NEW)
- **DB table** `bizcity_rolling_memory`: user_id, session_id, conversation_id, goal, window_summary, scores, status, summary_token_count
- **Trigger**: Mỗi 5 turns (`window_turn_count % 5 === 0`) → gọi LLM `purpose: 'fast'` tóm tắt
- **Token tracking**: `estimate_tokens()` = `ceil(mb_strlen / 4)` → lưu `summary_token_count`
- **Bidirectional scoring**: `user_goal_score` + `bot_satisfaction_score` (0-100)
- **AJAX**: `bizcity_rolling_memory_get` cho UI polling
- **Hook**: `bizcity_intent_processed` @10 + `bizcity_chat_message_processed` @15

#### 🔧 Episodic Memory (`class-episodic-memory.php` — NEW)
- **DB table** `bizcity_episodic_memory`: user_id, event_type, event_key, event_text, importance, times_seen, token_count
- **8 event types**: goal_success, goal_cancel, pain_point, satisfaction, tool_usage, habit, decision, preference_change
- **Dedup**: Upsert by `event_key` → bump `times_seen` + `importance += 5`
- **Token tracking**: `estimate_tokens()` chạy khi insert và update
- **Limit**: MAX_PER_USER = 200, auto-prune by importance
- **Cron**: `bizcity_episodic_daily_aggregate` (daily)

#### 🔧 Context Builder (`class-context-builder.php`)
- **8-layer chain**: UserMem(99) > Rolling > Episodic > Intent(L2) > Session(L3) > Cross(L4) > Project(L5) > Plugin(L6)
- **Layer 3**: 12 → 6 messages (Recent Window giảm, Rolling Summary bổ sung)
- **Token saving**: ~40-60% giảm so với 12 messages raw

#### 🔧 Intent Engine (`class-intent-engine.php`)
- `enrich_slot_from_session()`: Strategy 1 dùng Rolling Memory trước, Strategy 2 fallback webchat
- `update_rolling_summary()`: `purpose: 'planner'` → `'fast'` (giảm chi phí 5-10x)

#### 🔧 Bootstrap (`bootstrap.php`)
- Load order: ...rolling-memory → episodic-memory → context-builder → engine
- Cron schedule: `bizcity_episodic_daily_aggregate` (daily)

#### 🎨 UI (`page-aiagent-home.php` + `aiagent-home.css`)
- Right drawer: Rolling Memory real-time tracker
- AJAX polling mỗi 15s, visibility-aware (pause khi tab hidden)
- Active goals: progress bar + scores
- Recently completed: goal_label + completion_summary

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-rolling-memory.php` | bizcity-intent | **NEW** — class, DB table, AJAX, hooks, estimate_tokens, summary_token_count |
| `class-episodic-memory.php` | bizcity-intent | **NEW** — class, DB table, cron, 8 event types, token_count tracking |
| `class-context-builder.php` | bizcity-intent | 8-layer chain, inject Rolling + Episodic, Layer 3: 12→6 msgs |
| `class-intent-engine.php` | bizcity-intent | enrich_slot Rolling Memory, update_rolling_summary purpose: fast |
| `bootstrap.php` | bizcity-intent | Load order + cron schedule |
| `page-aiagent-home.php` | bizcity-bot-webchat | Right drawer UI + AJAX polling |
| `aiagent-home.css` | bizcity-bot-webchat | Rolling Memory UI styles |

---

### 2026-03-11 — Session 3: Phase 16 — Image+Text Smart Routing & Tool Suggest Confirmation (v4.3.6–v4.3.7)

> **Phase 16** — Fix pipeline khi user gửi ảnh + lệnh đồng thời. Thêm Step 1.7 Tool Suggest Confirmation cho chuyển mode knowledge→execution nhịp nhàng.

#### 🔧 v4.3.6: Smart Bypass & Image Slot Mapping

**Vấn đề**: "tạo video từ ảnh này nhé" + 📷 → Step 1.5C ép `mode=knowledge` → Router bị skip → AI free-form → user confirm "ok" → keyword rescue match SAI tool (`daily_outlook_check`).

**Engine fixes (class-intent-engine.php):**
- **Smart bypass at Step 1.5C override**: Scan `get_goal_patterns()` trước khi override. Nếu message match pattern → `mode=execution, method=image_text_pattern_bypass, confidence=0.95`
- **`_images` → named image slot mapping** (Step 4b): Khi `new_goal` được tạo với `_images` entity, auto-map vào slot đầu tiên có `type:'image'` trong plan. Áp dụng cho CẢ HAI path (cross-goal switch + fresh conversation)
- **`_session_pending_images` cleanup**: Clear buffer sau khi new_goal consume images

**Video-kling fixes:**
- **bizcity-video-kling.php**: Pattern regex thêm `b[- ]?r[o]+l[l]?|video nền`. `message` → `optional_slots`. `image_url` first in `slot_order`. `model` → `extract` list
- **class-tools-kling.php**: Fix `$context` always `[]` → read `$slots['user_id']`. Default prompt cho image-only: "Tạo video chuyển động tự nhiên, cinematic từ ảnh này"

#### 🔄 v4.3.7: Step 1.7 Tool Suggest Confirmation

**Vấn đề**: Khi Step 1.5C đúng (text mơ hồ + ảnh), AI trả lời knowledge + gợi ý tool. User confirm "ok làm đi" → không có cơ chế chuyển sang execution.

**Giải pháp — Funnel 2-turn:**

| Turn | Action | Cost |
|------|--------|------|
| Turn 1 | Step 1.5C: scan N tools → top 3 → persist conv slots → knowledge mode + suggest | 0 LLM (keyword scoring) |
| Turn 2 | Step 1.7: check 3 tools × confirm regex → set `tool_goal` → Router shortcut | 0 LLM (regex + shortcut) |

**4 thay đổi:**
1. **Persist `_image_text_suggested_tools`** trong conv slots (Step 1.5C) — survive across turns
2. **Step 1.7 handler** (giữa Step 1.6 và Context Builder) — detect confirm + recover images + set `tool_goal`
3. **Mode override** (sau Step 1.5C override block) — force `mode=execution` khi `_suggest_confirm`
4. **`detect_tool_suggest_confirm()`** method — Path 1: specific tool name/label match. Path 2: short affirmative message → top-scored tool

#### ⚠️ Scalability Note

- `find_matching_tools_for_suggest()` = O(N×W) brute-force → **bottleneck tại ~2K+ tools**
- Step 1.7 + `detect_tool_suggest_confirm()` = O(1) — **không bị ảnh hưởng bởi N**
- **Future TODO**: Thay `find_matching_tools_for_suggest()` bằng inverted index hoặc FULLTEXT khi vượt ~500 tools

#### Files Changed

| File | Plugin | Thay đổi |
|------|--------|----------|
| `class-intent-engine.php` | bizcity-intent | Smart bypass, `_images`→slot mapping (2 paths), persist suggested tools, Step 1.7 handler, mode override, `detect_tool_suggest_confirm()` |
| `bizcity-video-kling.php` | bizcity-video-kling | Pattern regex, message→optional, image_url first, model extract |
| `class-tools-kling.php` | bizcity-video-kling | $context→$slots fix, default prompt, session_id/_meta reads |

---

### 2026-03-11 — Session 2: Phase 15 — 5-Mode Classification & Knowledge Cleanup (v4.4)

> **Phase 15** — Dọn dẹp knowledge mode (loại bỏ Provider Expansion) + thêm MODE_AMBIGUOUS.

#### 🧹 Knowledge Mode Cleanup

**Vấn đề**: Knowledge Router (4-scenario S0/S1/S2/S3) đã không còn active — Gemini/ChatGPT đã chuyển sang execution tools. Code remains as dead code.

**Thay đổi:**
- **`class-knowledge-router.php`**: Deprecated auto-registration hook (priority 999). Router Pipeline KHÔNG override built-in nữa
- **`class-intent-engine.php`**: Xóa `knowledge_router` meta enrichment trong Step 2.5 pipeline logging
- **`class-mode-pipeline.php`**: Xóa gợi ý "dùng Gemini/ChatGPT" khỏi Knowledge Pipeline system prompt
- **`class-intent-stream.php`**: Simplified comment — generic "Pipeline direct reply"

#### 🆕 MODE_AMBIGUOUS — Mode thứ 5

**Vấn đề**: Tin nhắn mơ hồ ("hi", "hmm", "ok") bị phân vào knowledge mode → lãng phí RAG + context loading.

**5 modes:** emotion → reflection → knowledge → execution → **ambiguous**

| File | Thay đổi |
|------|----------|
| `class-mode-classifier.php` | `MODE_AMBIGUOUS = 'ambiguous'`, `VALID_MODES` 4→5, LLM prompt mode #5 với ví dụ, 3 fallback points (confidence < 0.6, invalid mode, LLM unavail) → ambiguous, `get_mode_label()` |
| `class-mode-pipeline.php` | `BizCity_Ambiguous_Pipeline` class: lightweight (no RAG), max_tokens 300, temperature 0.7, memory null, tool hints from Tool Index |
| `class-intent-engine.php` | Step 2 comment 4→5 modes, routing_branch += 'ambiguous', Step 2.5 comment |

---

### 2026-03-11 — Session 1: Phase 13.2, 13.3, 13.4 — Pre-flight Confirm + SSE Steps + Tool Pill Logic 2

> **Phase 13.2** — Pre-flight confirmation logic (moved from bizcity-preflight → bizcity-intent engine inline).
> **Phase 13.3** — 13 step-by-step SSE status messages across entire pipeline.
> **Phase 13.4** — Tool Pill `/tool_name` prefix in message + backend Logic 2 universal routing.

#### 🛡️ Phase 13.2 — Pre-flight Confirmation (Inline in Engine)

**Vấn đề**: `bizcity-preflight` plugin không active. Confirmation logic cần nằm trong engine.

**`class-intent-engine.php`** (bizcity-intent):
- **`case 'call_tool':`** (~L1728-1818): Thêm confirmation block ĐẦU case
  - `_awaiting_confirm` slot flag + `waiting_field = '_confirm_execute'`
  - Positive phrase matching: `ok`, `chạy`, `thực hiện`, `đồng ý`, `xác nhận`, `yes`, `go`
  - Non-confirm reply → re-runs Planner → `ask_user` or re-show confirmation
  - **Skip confirmation** cho tools KHÔNG có visible slots (chạy thẳng)
- **`build_confirm_prompt()`** (~L2469): Helper method
  - Numbered list filled slots + goal label header
  - Footer: "Gõ **OK** để thực hiện, hoặc nhập lại thông tin cần sửa."

#### 📡 Phase 13.3 — Step-by-Step SSE Status Messages (13 total)

**Vấn đề**: User không biết engine đang làm gì → cảm giác lag.

**`class-intent-engine.php`** — 6 NEW status messages:
1. `🔍 Đang phân tích yêu cầu...` (trước classify)
2. `🧠 Đã hiểu yêu cầu — {goal_label}` (sau classify)
3. `📋 Đang lập kế hoạch thực hiện...` (trước plan)
4. `❓ Cần thêm thông tin — {field_label}` (ask_user)
5. `✅ Đã đủ thông tin — chờ xác nhận` (confirm)
6. `⚡ Đang thực hiện {tool_label}...` (trước execute)

**`class-intent-stream.php`** — 7 EXISTING status messages đã có từ trước.

**Tổng: 13 SSE status messages** xuyên suốt pipeline.

#### 🏷️ Phase 13.4 — Tool Pill `/slug` + Logic 2 Universal Routing

**Mục tiêu**: Khi user chọn tool chip → pill `/tool_name` hiện trong input, gửi kèm message. Backend parse `/slug` để route tool — hoạt động trên MỌI channel (Telegram, Zalo, REST...).

**`class-admin-dashboard.php`** (bizcity-bot-webchat):
- **`sendMsg()`**: Prepend `'/' + tool_name + ' '` trước text khi `_selectedTool` set
- **`appendMsg()`**: Detect `/slug` prefix → wrap trong `<span class="bizc-msg-slash">` (green pill style)
- **CSS `.bizc-msg-slash`**: Monospace font, green bg/border, rounded pill, 0.85em

**`class-intent-stream.php`** (bizcity-intent):
- **Logic 2 parsing** (sau `$tool_goal = $_REQUEST['tool_goal']`):
  - Regex: `/^\/([a-z0-9_]+)(?:\s+(.*))?$/si`
  - **L2-a (primary)**: DB query `bizcity_tool_registry WHERE tool_name = slug OR goal = slug` → handles `tool_name ≠ goal`
  - **L2-b (fallback)**: `get_goal_patterns()` check for pattern-only goals
  - Strip `/slug ` from message → engine gets clean prompt
  - FormData `tool_goal` takes precedence (Logic 2 only fires if no direct param)

**Dual-path redundancy**:
| Channel | Logic 1 (FormData) | Logic 2 (/slug in text) |
|---------|-------------------|------------------------|
| Admin chat | ✅ `tool_goal` param | ✅ backup (nhưng Logic 1 đã đủ) |
| Telegram/Zalo | ❌ không có FormData | ✅ primary routing mechanism |
| REST API | ❌ tùy client | ✅ universal fallback |

#### 📝 Documentation Updates

| File | Thay đổi |
|------|----------|
| `SYSTEM-LOG.md` | Phase Status: P13.2, 13.3, 13.4 rows; Daily Log entry |
| `ARCHITECTURE.md` | New §21: Tool Pill Logic 2 Universal Routing |
| `ROADMAP.md` | P13 sprints checkmarks; P13.2-13.4 sub-sections |

---

### 2026-03-19 — Session: Phase 13.1 — Inline Tool Chips + Provider Scope Guard + Slug Resolution

> **Phase 13.1** — UX: inline tool chips trong context header. Backend: Provider Scope Guard chặn cross-provider misrouting. Critical fix: slug→provider_id resolution toàn hệ thống.

#### 🎨 Frontend — Inline Tool Chips in Context Header

**Vấn đề**: Khi chọn plugin qua @mention, user phải gõ `/` mới thấy danh sách tools. Cần hiện tool chips ngay trong context header.

**`class-admin-dashboard.php`** (bizcity-bot-webchat):
- **HTML**: Wrapped context header trong `.bizc-context-header-top`, thêm `<div class="bizc-context-tools-row" id="bizc-context-tools">` cho tool chips
- **CSS**: `.bizc-plugin-context-header` → `flex-direction: column`. Thêm `.bizc-context-header-top` (flex row), `.bizc-context-tools-row` (flex, overflow-x:auto, hidden scrollbar, `.has-tools` toggle), `.bizc-tool-chip` (pill shape, purple border, active state)
- **JS**: `_contextToolsCache = {}` (per-slug cache), `_loadContextTools(pluginSlug)` (AJAX fetch all tools), `_renderContextToolChips(tools, pluginSlug)` (render chips with data attributes)
- **`enterPluginContextMode()`**: auto-gọi `_loadContextTools(pluginSlug)`
- **`exitPluginContextMode()`**: cleanup `$('#bizc-context-tools').empty().removeClass('has-tools')`
- **Click handler**: `.bizc-tool-chip` → highlight active + `_selectTool()`
- **`_clearToolSelection()`**: thêm `.bizc-tool-chip.active` removal

#### 🛡️ Backend — Provider Scope Guard (3-Layer)

**Vấn đề**: User chọn @bizcoach-map, gửi "xem cho tôi" → misroute sang knowledge provider → "Bạn cần hướng dẫn về chủ đề gì? 📖 1. Tất cả 2. Đơn hàng..."

**Router Step 0.5** (`class-intent-router.php`): Reject unified LLM `$pre_goal` thuộc provider khác `$provider_hint`
**Router Step 2** (`class-intent-router.php`): Downgrade LLM `new_goal` from wrong provider → `small_talk` + `confidence=0.5` + `+provider_scope_rejected` method
**Engine Step 3.6** (`class-intent-engine.php`): Provider Scope Guard — khi `$provider_hint` set + no active goal + wrong provider/small_talk → list provider's tools: "**{name}** có thể giúp bạn:\n\n{numbered_list}\nBạn muốn sử dụng công cụ nào? 💡"

#### 🔑 Critical Fix — Slug → Provider ID Resolution

**Root cause**: Frontend gửi WordPress plugin slug (`bizcity-tarot`, `bizcoach-map`), nhưng `bizcity_tool_registry.plugin` lưu provider ID (`tarot`, `bizcoach`). `$registry->get('bizcity-tarot')` → `null` → TOÀN BỘ @mention system fail silently.

**Giải pháp**: `resolve_slug()` method trong `BizCity_Intent_Provider_Registry`:
1. Direct match (slug = provider ID) → return
2. `strpos()` fuzzy: `bizcity-tarot` contains `tarot` → return `tarot`

**Applied to 4 files:**
| File | Thay đổi |
|------|----------|
| `class-intent-provider-registry.php` | New `resolve_slug($slug)` method |
| `class-intent-stream.php` | `$provider_hint` resolved ngay khi nhận từ `$_REQUEST` |
| `class-intent-router.php` | `$provider_hint` resolved ở đầu `classify()` |
| `class-intent-engine.php` | `$provider_hint` resolved ở đầu `process()` |
| `class-plugin-suggestion-api.php` | `$plugin_slug` resolved trong `ajax_search_tools()` |

---

### 2026-03-18 — Session: Phase 13 — Dual Context Architecture (v4.0)

> **Phase 13** — Tách hệ thống context thành 2 lớp: Emotion Context (70%) + Tool Context (30%). Thêm `/` slash command tool search. Giải quyết misclassification "bốc bài" → check_synastry.
> **Triết lý**: 70/30 — Emotion-first, Tool-ready.

#### 📐 Architecture Design — §19 Dual Context Architecture

**Vấn đề gốc**: User gửi "bốc bài nhé" → Mode Classifier nhận 36+ tools → LLM confused → trả `check_synastry` (bizcoach) thay vì `tarot_reading` (tarot). Root cause: quá nhiều tools, context bloated (2400ms).

**Giải pháp**: Dual Context Architecture:
1. **Emotion Context** (Full 6-layer, L1-L7): Cho emotion/reflection/knowledge modes — giữ nguyên
2. **Tool Context** (Compact 4-layer, L1-L4): Cho execution mode — ~800 chars vs ~3000+ chars
3. **`/` Slash Command**: User gõ `/` → search `bizcity_tool_registry` → chọn TOOL cụ thể → auto-select plugin
4. **Pre-Intent Tool Suggest**: Extract keywords → search tool registry → suggest top 5 tools as chips
5. **Post-Tool Satisfaction**: "ok cảm ơn" → COMPLETED; "chưa hài lòng" → retry/cancel

**3-Step Tool Selection Pipeline:**
```
Step 1: Scope Narrowing
  - Manual: @mention plugin → / search tools within plugin
  - Auto: keywords → search registry → suggest top 5 tools

Step 2: Focus Mode + HIL
  - Tool confirmed → Tool Context (compact) → fields prompt → HIL loop

Step 3: Post-tool
  - Result shown → satisfaction check → complete or retry
```

#### 📝 Documentation Updates

| File | Thay đổi |
|------|----------|
| `ARCHITECTURE.md` | Vision: thêm "Triết lý 70/30" paragraph |
| `ARCHITECTURE.md` | TOC: thêm §19 |
| `ARCHITECTURE.md` | New §19: Dual Context Architecture (~200 dòng, §19.1-19.8) |
| `ROADMAP.md` | Phase table: thêm P13 row |
| `ROADMAP.md` | New P13 section: Sprint 1-5 (~90 dòng) |
| `ROADMAP.md` | Dependency Map: P12.1 → P13 node |
| `ROADMAP.md` | Timeline: P13 Sprint 1-5 rows (total ~4d) |
| `SYSTEM-LOG.md` | Phase Status: P13 row + P14 rename |
| `SYSTEM-LOG.md` | Daily Log: this entry |

#### ⚡ Sprint 1 — `/` Slash Command Implementation

**Backend: `class-plugin-suggestion-api.php`** (bizcity-bot-webchat):
- REST route `GET /bizcity-webchat/v1/search-tools` + AJAX `bizcity_search_tools`
- `search_tools($query, $plugin_slug, $limit)`: 7-layer scoring — goal exact (20pts), title (15pts), goal_label (12pts), custom_hints (10pts), goal_description words (1pt each), description words (capped 5), custom_description words
- `format_tool_result()`: Market Catalog icon lookup fallback

**Frontend: `class-admin-dashboard.php`** (bizcity-bot-webchat, ~200 LOC JS):
- Variables: `_slashActive`, `_slashQuery`, `_slashIdx`, `_slashSearchTimer`, `_selectedTool`
- `_searchTools(query)`: AJAX → `bizcity_search_tools`, scoped to `_pluginSlug`
- `_renderSlashDropdown(tools)`: reuse `bizc-mention-dropdown`, items with `data-type="tool"`
- `_selectTool()`: auto-selects parent plugin, enters plugin-context-mode, updates context header
- Input regex: `(?:^|\s)\/([\S]*)$` alongside `@([\w-]*)$`
- Keyboard: ArrowDown/Up/Enter/Tab/Escape for slash dropdown
- `sendMsgStream()`: appends `tool_goal` + `tool_name` in FormData
- Context header: `/ để tìm tools · Agent`
- Placeholder (4 locations): `"Nhập tin nhắn... (@ chọn agent · / tìm tool)"`

**Server: `class-intent-stream.php`** (bizcity-intent):
- `$tool_goal` extraction from `$_REQUEST['tool_goal']`
- Passed to `bizcity_intent_process()` params
- Tool Context activation: khi `method === 'slash_command_direct'` → `set_tool_context_mode(true)`

**Router: `class-intent-router.php`** (bizcity-intent):
- `classify()` 6th param `$context = []` with `tool_goal`
- Step -1: Slash command direct → `intent=new_goal, method=slash_command_direct, confidence=1.0`
- 0 LLM cost, instant routing

**Engine: `class-intent-engine.php`** (bizcity-intent):
- `router->classify()` call passes 6th param `['tool_goal' => $params['tool_goal'] ?? '']`

#### 🧠 Sprint 3 — Tool Context Layer

**Context Builder: `class-context-builder.php`** (bizcity-intent):
- Property `$use_tool_context` (bool) + `set_tool_context_mode()` / `is_tool_context_mode()`
- `build_tool_execution_context($max_length = 800)`: compact context with L2 (Intent Conv, max 500 chars) + L3 (last 3 session messages)
- `inject_context_layers()`: dual-path — when `$use_tool_context` → compact output (~800 chars), reset flag; otherwise → full 6-layer (~3000+ chars)

#### 🔄 Sprint 4 — Post-Tool Satisfaction Detection

**Database: `class-intent-database.php`** (bizcity-intent, +30 LOC):
- `find_recently_completed_conversation($user_id, $channel, $session_id)`: COMPLETED + goal + within 2 min

**Conversation: `class-intent-conversation.php`** (bizcity-intent, +15 LOC):
- `find_recently_completed()`: wrapper → normalize

**Router: `class-intent-router.php`** (bizcity-intent, +50 LOC):
- `detect_post_tool_satisfaction($message)`: regex-based, 0 LLM cost
- Satisfied: ok/cảm ơn/tốt rồi/đúng rồi/xong rồi/hay lắm/tuyệt/👍
- Retry: sai rồi/chưa đúng/làm lại/thử lại/chỉnh lại/sửa lại

**Engine: `class-intent-engine.php`** (bizcity-intent, +60 LOC):
- Step 1.6: Post-Tool Satisfaction Detection (after Step 1.5B, before Step 2)
- No active goal + recently completed (2 min) + satisfaction regex match
- Satisfied → "😊 Vui vì đã giúp được!" → COMPLETED with log
- Retry → re-open conversation with prev goal + prev slots → WAITING_USER "🔄 Để mình làm lại nhé!"
- No match → fall through to normal pipeline

| File | LOC trước | LOC sau | Thay đổi |
|------|-----------|---------|----------|
| `class-plugin-suggestion-api.php` (webchat) | ~750 | ~890 | +140 (REST + AJAX + search_tools + format) |
| `class-admin-dashboard.php` (webchat) | ~6900 | ~7100 | +200 (slash command UI) |
| `class-intent-stream.php` | ~1590 | ~1610 | +20 (tool_goal + Tool Context) |
| `class-intent-router.php` | ~2100 | ~2250 | +150 (Step -1 + satisfaction detection) |
| `class-intent-engine.php` | ~3070 | ~3190 | +120 (classify context + Step 1.6) |
| `class-context-builder.php` | ~640 | ~710 | +70 (Tool Context mode) |
| `class-intent-database.php` | ~770 | ~800 | +30 (find_recently_completed) |
| `class-intent-conversation.php` | ~500 | ~515 | +15 (find_recently_completed) |

---

### 2026-03-17 — Session: Phase 12.1 — LLM Slot Bridge (v3.9.1)

> **Phase 12.1** — Intelligent provide_input slot extraction via LLM
> **Version bump**: v3.9.0 → v3.9.1

#### 🏗️ LLM Slot Bridge — Router (`class-intent-router.php`)

**Vấn đề**: `fill_waiting_field_entities()` chỉ dùng simple text mapping — gán nguyên message vào waiting_field. Fail với choice (cần key match), number/date (cần normalize), multi-slot (chỉ fill 1).

**Giải pháp**: LLM Slot Bridge — lightweight LLM call (~100-200ms) extract + normalize slot values.

**Thay đổi 1 — `fill_waiting_field_entities()` decision tree** (+60 LOC):
- `image_url` → regex only (không LLM)
- `needs_llm = true` khi: field type ∈ {choice, number, date} HOẶC missing_count > 1
- `needs_llm = true` → call `extract_provide_input_with_llm()`
- LLM `understood=true` → fill entities, `_slot_retry_count=0`
- LLM `understood=false` → `_slot_extract_failed=true` + clarification
- LLM fail → fall through to simple mapping

**Thay đổi 2 — `extract_provide_input_with_llm()` new method** (+120 LOC):
- Build slot schema context: type, choices, filled/missing status, marker
- Fetch 6 recent conversation turns for context
- LLM call: `temperature=0.05`, `max_tokens=250`, `purpose=slot_extract`
- JSON output: `{slots: {field: value}, understood: bool, clarification: string}`
- Handles: fuzzy choice match ("tài chính" → `tai_chinh`), multi-slot ("tài chính, 3 lá" → 2 slots)

| File | Thay đổi |
|------|----------|
| `class-intent-router.php` | `fill_waiting_field_entities()` +60 LOC: LLM decision tree |
| `class-intent-router.php` | `extract_provide_input_with_llm()` +120 LOC: new method |

#### 🏗️ Engine Retry/Cancel — Step 4c (`class-intent-engine.php`)

**Vấn đề**: Khi LLM Slot Bridge trả `understood=false` — cần retry hoặc cancel gracefully.

**Giải pháp**: Step 4c retry/cancel block trước `resume()`:
- `_slot_retry_count < 1` → retry: re-ask user với clarification, giữ WAITING_USER
- `_slot_retry_count >= 1` → cancel suggest: "Nhấn ❌ Hủy hoặc @tên_plugin"
- Clean pseudo-entities (`_slot_extract_failed`, `_slot_extract_clarification`, `_slot_retry_count`) from `$slot_updates` and `$turn_slots`
- Success → reset retry counter, resume normal flow

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | Step 4c +80 LOC: retry/cancel block |
| `class-intent-engine.php` | +5 LOC: clean pseudo-entities from slot_updates & turn_slots |

#### 🔧 v3.7 Bug Fixes (3 fixes for tarot/image handling)

**Fix A — small_talk → provide_input override** (`class-intent-router.php` Step 2, +15 LOC):
- WAITING_USER + LLM says small_talk → override to provide_input
- "tài chính", "3 lá" are slot values, not small talk

**Fix B — Step 0.5 provider_hint guard** (`class-intent-router.php` Step 0.5, +5 LOC):
- Multi-goal providers (tarot: tarot_reading + tarot_interpret) need full classification
- Step 0.5 allows pre-classified result even with provider_hint set

**Fix C — Image URL in turn history** (`class-intent-stream.php`, +10 LOC):
- Historical user turns with attachments now include image_url content blocks
- LLM retains image context across conversation

#### 📝 Documentation Update

| File | Thay đổi |
|------|----------|
| `ARCHITECTURE.md` | TOC: thêm §16, §17, §18. New §18: LLM Slot Bridge (~180 dòng) |
| `ROADMAP.md` | Phase table + P12.1 section + Dependency Map + Timeline |
| `SYSTEM-LOG.md` | Phase Status + Daily Log entry |

---

### 2026-03-15 — Session: Phase 10f — Dual-Logic Tool Routing & Plugin Gathering (v3.9.0)

> **Phase 10f** — Document + refine the two routing logics; UI badge; floating indicator removal
> **Version bump**: v3.8.0 → v3.9.0

#### 🏗️ ARCHITECTURE.md Section 10f — Complete Rewrite

**Vấn đề**: Section 10f cũ chỉ mô tả Plugin Gathering (Logic 2) — thiếu context dual-logic routing.

**Giải pháp**: Viết lại toàn bộ §10f thành 12 sub-sections (10f.0 → 10f.12) bao gồm:
- **Logic 1** (Registry LLM): 36+ tools, mode_classify → intent_classify → slot_analyze → planner
- **Logic 2** (@Tag Direct): User @mention → provider_hint → 1-6 tools → skip full LLM scan
- So sánh pipeline trace (vấn đề Logic 1 với tarot_reading session)
- Conversation_id consistency mapping
- Auto-cycle logic (goal ok → tự tìm goal mới)
- UI badge documentation

| File | Thay đổi |
|------|----------|
| `ARCHITECTURE.md` | §10f hoàn toàn rewrite: ~250 dòng mới, xóa ~220 dòng duplicate cũ |

#### 🎨 UI: Remove Floating Indicator + Plugin Badge During Loading

**Vấn đề 1**: `bizc-context-floating-indicator` div thừa — khi user đã chọn plugin qua @mention, không cần floating badge nữa (đã có context header).

**Vấn đề 2**: Plugin slug badge chỉ hiển thị SAU khi bot trả lời xong — không thấy badge lúc đang loading.

**Fix 1**: Xóa floating indicator HTML div + cleanup 3 JS functions (`enterPluginContextMode`, `exitPluginContextMode`, `autoHideFloatingIndicator`).

**Fix 2**: Thêm plugin badge hiển thị tại **3 điểm**:
1. **Typing indicator** — khi user gửi message, badge hiện ngay trên "đang suy nghĩ..."
2. **SSE streaming bot bubble** — badge hiện khi chunk đầu tiên tạo bubble mới
3. **AJAX fallback** — badge hiện khi response trả về

**Kỹ thuật**: Dùng `_sendPluginSlug` / `_ssePluginSlug` / `_ajaxPluginSlug` JS variables để persist plugin_slug qua lifecycle (vì `clearMention()` xóa context trước khi response về).

| File | Thay đổi |
|------|----------|
| `class-admin-dashboard.php` | Xóa floating indicator div; cleanup 3 JS functions; thêm badge logic tại typing/SSE/AJAX (10 code blocks modified) |

---

### 2026-03-08 — Session: Phase 10e — Unified Single-Call Classification (v3.8.0)

> **Phase 10e** — Merge 3 LLM calls into 1 unified call
> **Version bump**: v3.7.0 → v3.8.0

#### 🔧 Bug Fix: Duplicate `get_goal_patterns()` method

**Vấn đề**: `PHP Fatal error: Cannot redeclare BizCity_Intent_` tại line 1528 của `class-intent-router.php`. Hai method `get_goal_patterns()` giống nhau.

**Fix**: Xóa method đầu tiên (simple version), giữ lại version vói v3.6.4 docblock.

| File | Thay đổi |
|------|----------|
| `class-intent-router.php` | Xóa duplicate `get_goal_patterns()` method |

#### 🔧 Bug Fix: Mindmap topic không được extract

**Vấn đề**: Intent nhận diện được `create_mindmap` goal qua regex nhưng Planner vẫn hỏi lại topic vì entities=[]. Root cause: regex_goal bypass LLM, return sớm với entities rỗng, Router Step 0.5 return early skip Tier 2.

**Fix**: Thêm Tier 2 LLM entity extraction vào Router Step 0.5 khi entities rỗng + goal có required_slots + message có nội dung.

| File | Thay đổi |
|------|----------|
| `class-intent-router.php` | Step 0.5: thêm Tier 2 entity extraction khi entities empty |

#### 🏗️ Unified Single-Call Architecture (v3.8.0)

**Vấn đề**: 3 LLM calls tuần tự (Mode Classifier + Router Tier 1 + Router Tier 2) tốn 1600-2400 tok, 600-1200ms, ~$0.0003/request.

**Giải pháp**: Gộp thành 1 unified LLM call với focused top-N schema + regex bias hint.

**Thay đổi chính:**

1. **Regex pre-match → bias hint (không bypass)**
   - Trước: regex match → bypass LLM (return entities=[]) → mất entity extraction
   - Sau: regex match → set `$regex_likely_goal` → truyền vào LLM call làm gợi ý

2. **Focused top-N schema với type hints**
   - `build_focused_schema_for_llm()`: chỉ inject top N tools (configurable, default 10)
   - ★ marker cho regex-matched tool
   - Type hints: `topic(text)`, `spread(choice:3 lá,5 lá,10 lá)`

3. **Unified LLM prompt**
   - BƯỚC 1: Mode (4 options)
   - BƯỚC 2: Intent + Goal (nếu execution)
   - BƯỚC 2b: Entity/Slot extraction (inline, không cần call riêng)
   - BƯỚC 3: Memory check

4. **Configurable top_n_tools setting**
   - `bizcity_tcp_top_n_tools` wp_option (default 10, min 3, max 50)
   - UI trong Control Panel Stats tab (admin page + standalone)
   - AJAX `bizcity_tcp_save_settings`

**Kết quả:**
| Metric | Trước (3 calls) | Sau (1 call) | Cải thiện |
|---|:-:|:-:|:-:|
| LLM round-trips | 3 | 1 | -67% |
| Input tokens | 1600-2400 | 1100-1500 | -40-50% |
| Latency | 600-1200ms | 200-400ms | -60-70% |
| Cost | ~$0.0003 | ~$0.0001 | -67% |

| File | Thay đổi |
|------|----------|
| `class-mode-classifier.php` | Regex bypass → bias hint; `classify_with_llm()` rewrite: nhận `$regex_likely_goal`, focused schema, inline entity extraction |
| `class-intent-router.php` | 3 new methods: `build_focused_schema_for_llm()`, `parse_tool_slot_names_with_types()`, `get_top_n_tools()` |
| `class-tool-control-panel.php` | AJAX `ajax_save_settings()` + Settings UI (Stats tab) + JS handler |
| `page-tool-control-panel.php` | Settings UI (standalone) + JS save handler |

---

### 2026-03-08 — Session: Phase 11 Sprint 1 — Human-in-Loop Slot-Filling Optimization

> **Phase 11 Sprint 1** — Slot Accuracy & Validation (O1, O2, O3, O9)
> **Version bump**: v3.5.2 → v3.6.0

#### 🧹 O1: DEDUPLICATE PLANNER BUILT-IN PLANS

**Vấn đề**: `class-intent-planner.php` hardcode 20 built-in plans. 9 plans trùng với plugin providers (tool-content, tool-woo) → Plugin plans OVERWRITE built-in qua `array_merge()` nhưng built-in code vẫn chiếm ~200 lines thừa.

**Giải pháp**: Xóa 9 built-in plans đã có plugin cung cấp. Giữ lại 11 plans generic/unconfirmed.

**Plans removed** (9): `create_product`, `edit_product`, `create_order`, `find_customer`, `customer_stats`, `product_stats`, `inventory_report`, `warehouse_receipt`, `write_article`

**Plans kept** (11): `daily_outlook`, `astro_forecast`, `report`, `inventory_journal`, `post_facebook`, `set_reminder`, `list_orders`, `create_video`, `help_guide`

| File | Thay đổi |
|------|----------|
| `class-intent-planner.php` | Xóa 9 built-in plans, ~200 lines removed, add comment blocks cho plugin-owned plans |

#### 🎯 O3: INJECT PLAN SCHEMA INTO LLM CLASSIFY PROMPT

**Vấn đề**: Khi WAITING_USER, LLM Tier 1 chỉ biết `waiting=image_url` nhưng không biết field type/choices → misclassify "bỏ qua" thành new_goal thay vì provide_input.

**Giải pháp**: Inject field schema (name, type, choices) vào conv_context khi WAITING_USER.

| File | Thay đổi |
|------|----------|
| `class-intent-router.php` | `classify_with_llm_fast()` — inject `FIELD: field_name (type) [choices]` vào conv_context khi WAITING_USER |

#### ⏭️ O2: UNIVERSAL SKIP_ON FOR PLUGIN PLANS

**Vấn đề**: `skip_on` phrases chỉ có trong built-in plans (đã xóa). Plugin plans thiếu `skip_on` → user nói "bỏ qua" nhưng optional slot không được skip.

**Giải pháp**: Planner `plan()` auto-detect skip phrases cho optional slots. Default skip phrases: `['bỏ qua', 'skip', 'không', 'không cần', 'auto', 'tự tạo', 'next', 'tiếp']`.

| File | Thay đổi |
|------|----------|
| `class-intent-planner.php` | `plan()` optional slot loop — check `$skip_on` từ config hoặc fallback default. Detect skip → fill default + move next |

#### ✅ O9: SLOT VALUE VALIDATION BEFORE CALL_TOOL

**Vấn đề**: Invalid slot values (price="abc", url="not a url") gây tool runtime failures.

**Giải pháp**: `validate_slot()` method mới — check theo type rules trước khi return `call_tool`. Invalid → `ask_user` with specific error.

| File | Thay đổi |
|------|----------|
| `class-intent-planner.php` | New `validate_slot()` method. Validation: text→non-empty, number→is_numeric, image→valid URL, choice→in choices array |

---

### 2026-03-08 — Session 2: Phase 11 Sprint 2 — UX & Intelligence + HIL Checklist Tab

> **Phase 11 Sprint 2** — UX & Intelligence (O4, O5, O8, O10) + NEW HIL Checklist Tab
> **Version bump**: v3.6.0 → v3.6.1

#### 🔀 O4: SINGLE-TURN MULTI-SLOT EXTRACTION

**Vấn đề**: "Viết bài về AI, ảnh tự tạo nhé" — Planner chỉ fill `topic` mà không skip `image_url`. User phải nói "bỏ qua" ở turn riêng.

**Giải pháp**: 
- Engine inject `$intent['raw_message'] = $message` trước `plan()` call
- Planner scan toàn bộ unfilled optional slots against raw_message via `message_implies_skip()`
- `message_implies_skip()` — type-specific patterns: image ("ảnh tự tạo", "ko cần ảnh", "không cần hình") + generic field_name+skip_verb combinations

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | Inject `$intent['raw_message']` before `plan()` call (+1 line) |
| `class-intent-planner.php` | O4 multi-slot scan block after merge step (+15 lines). New `message_implies_skip()` method (+52 lines) |

#### 🧠 O5: SMART SLOT ORDERING

**Vấn đề**: User nhập topic dài (>100 chars) chứa đủ context, nhưng Planner vẫn hỏi tone/length/style/format → annoying extra turns.

**Giải pháp**: Detect primary content slots (topic/message/content/description/name). Nếu >100 chars → auto-fill secondary optionals (tone/length/style/format) with defaults, skip asking.

| File | Thay đổi |
|------|----------|
| `class-intent-planner.php` | O5 smart ordering block (+12 lines) — primary slot length check → bulk auto-fill secondary optionals |

#### 💬 O8: CONTEXT-AWARE RE-ASK ON MISSING_FIELDS

**Vấn đề**: Tool trả `missing_fields` → Engine hỏi generic prompt không có context. User không biết mình đã cung cấp gì.

**Giải pháp**: Build `$filled_summary_parts` từ plan schema — collect all filled slot labels+values (truncated 30 chars). Append "(Đã có: topic: \"AI\", tone: \"casual\")" vào raw_prompt trước LLM smoothing.

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | Enhanced missing_fields handler: build filled_summary context (+13 lines) |

#### 🕐 O10: STALE CLEANUP CRON

**Vấn đề**: `maybe_cleanup()` dùng transient lock + web request → unreliable. Conversations WAITING_USER có thể treo vô hạn.

**Giải pháp**:
- WP-Cron hourly schedule `bizcity_intent_stale_cleanup` trong `bootstrap.php`
- `find_recently_expired()` trong Conversation class → delegates to DB
- `find_expired_conversation()` SQL: EXPIRED/CLOSED with goal, same user/session/channel, within 2h

| File | Thay đổi |
|------|----------|
| `bootstrap.php` | WP-Cron schedule + action handler (+8 lines) |
| `class-intent-conversation.php` | New `find_recently_expired()` method (+20 lines) |
| `class-intent-database.php` | New `find_expired_conversation()` SQL method (+32 lines) |

#### 📋 NEW: HIL CHECKLIST TAB IN INTENT MONITOR

**Vấn đề**: Không có cách nào để admin theo dõi quá trình HIL slot-filling đang diễn ra. Debug phải đọc raw logs.

**Giải pháp**: Tab mới "📋 HIL Checklist" trong Intent Monitor SPA — todo-style checklist hiển thị real-time slot progress.

**Tính năng**:
- Card per conversation với progress bar (filled/total slots)
- Slot status icons: ✅ filled, ⏳ waiting, ⬜ pending, ⏭️ skipped
- Filters: status (default WAITING_USER), channel, search text
- Auto-refresh 15s (toggle)
- Inline turns viewer (expand/collapse per conversation)
- Stats grid: total conversations, waiting, completed, average fill rate

| File | Thay đổi |
|------|----------|
| `class-intent-monitor.php` | CSS styles (+35 lines), tab button (+1 line), panel HTML (+25 lines), JS tab-switch (+2 lines), `loadHilChecklist()` + `renderHilChecklist()` + `toggleHilTurns()` (+120 lines), AJAX registration (+1 line), `ajax_hil_checklist()` + `build_slot_status()` backend methods (+160 lines) |

#### 📊 SPRINT 2 FILE CHANGE SUMMARY

| File | Lines before → after | Change |
|------|---------------------|--------|
| `class-intent-planner.php` | 758 → 843 | +85 lines (O4 scan + O5 ordering + `message_implies_skip()`) |
| `class-intent-engine.php` | 2538 → 2561 | +23 lines (O4 raw_message + O8 filled context) |
| `class-intent-monitor.php` | 1284 → 1629 | +345 lines (HIL Checklist Tab: CSS + HTML + JS + AJAX) |
| `class-intent-conversation.php` | 378 → 398 | +20 lines (O10 `find_recently_expired()`) |
| `class-intent-database.php` | 741 → 773 | +32 lines (O10 `find_expired_conversation()`) |
| `bootstrap.php` | 245 → 253 | +8 lines (O10 cron schedule) |

---

### 2026-03-08 — Session 3: Phase 11 Sprint 3 — Cross-Goal Intelligence + Codebase Cleanup

> **Phase 11 Sprint 3** — Cross-Goal Intelligence (O6) + Slot Analytics + HIL Webchat Tab + Codebase Cleanup
> **Version bump**: v3.6.1 → v3.6.2
> **Modes cleanup**: Removed `planning` & `coding` modes entirely — system now has 4 active modes only (emotion, reflection, knowledge, execution)

#### 🧹 CODEBASE CLEANUP — REMOVE DEAD PLANNING/CODING REFERENCES

**Vấn đề**: `MODE_PLANNING` + `MODE_CODING` constants vẫn tồn tại "for compatibility" nhưng không bao giờ được dùng. Stale comments vẫn nói "6 modes". UI filters vẫn hiện planning/coding options.

**Giải pháp**: Audit toàn bộ 22 include files từ bootstrap. Xóa sạch dead code, stale comments, unused UI elements.

| File | Thay đổi |
|------|----------|
| `class-mode-classifier.php` | Xóa `MODE_PLANNING` + `MODE_CODING` constants (-2 lines), xóa commented-out VALID_MODES entries (-2 lines), xóa stale labels trong `get_mode_label()` (-1 line), thêm doc comment cho VALID_MODES |
| `class-intent-engine.php` | Fix stale comment "6 modes" → "4 modes" (L166), xóa "planning" khỏi 2 dispatch comments |
| `class-intent-monitor.php` | Xóa `<option value="planning">` + `<option value="coding">` từ prompt log filters, xóa `'planning':'waiting'` từ JS modeCls badge mapping |
| `class-mode-pipeline.php` | Updated doc block: xóa PlanningPipeline/CodingPipeline references, cập nhật "4 active modes" |

#### 🔄 O6: CROSS-GOAL SLOT INHERITANCE

**Vấn đề**: Khi user chuyển từ goal A sang goal B (VD: "viết bài" → "đăng bài"), tất cả slots đã fill đều bị mất. User phải nhập lại thông tin đã cung cấp.

**Giải pháp**: Trong `new_goal` handler, khi detect goal thay đổi:
1. Lấy `$prev_slots` từ conversation hiện tại
2. Lấy plan schema mới via `$this->planner->get_plan($intent['goal'])`
3. Iterate new plan fields → tìm overlapping slot names có value trong prev_slots
4. Merge: `array_merge($inherited_slots, $intent['entities'])` — LLM entities win over inherited
5. Log `cross_goal_inherit` event với danh sách inherited fields

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | O6 block trong new_goal handler (+35 lines): detect goal change → get prev_slots → get new plan → find overlaps → merge → log |

#### 📊 SLOT FILL RATE TRACKING

**Vấn đề**: Không có metric nào để đo UX quality của slot-filling process. Không biết goal nào cần nhiều turns, goal nào user hay bỏ dở.

**Giải pháp**: Log `slot_fill_rate` step tại thời điểm `call_tool` — trước khi gọi executor. Bao gồm: goal, tool_name, total_slots, filled_count, fill_rate (%), turns_taken.

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | Slot fill rate logging block before executor dispatch (+30 lines): get plan schema → count fields → compute fill rate → log |

#### 📈 ANALYTICS DASHBOARD IN INTENT MONITOR

**Vấn đề**: Overview tab chỉ có basic stats. Không có slot-level analytics để identify problematic goals.

**Giải pháp**: Enhanced `ajax_stats()` + `renderStats()` với "📊 HIL Slot Analytics" section:
- SQL queries: avg fill rate per goal, most abandoned goals, waiting count
- Fill rate table: color-coded badges (🟢 ≥80%, 🟡 ≥50%, 🔴 <50%)
- Abandoned goals bar chart
- Waiting conversations stat card

| File | Thay đổi |
|------|----------|
| `class-intent-monitor.php` | `ajax_stats()` +45 lines (3 new SQL queries), `renderStats()` +25 lines (analytics HTML rendering) |

#### ✅ HIL CHECKLIST TAB IN WEBCHAT ROUTER CONSOLE

**Vấn đề**: HIL Checklist chỉ có trong admin Intent Monitor. Developer/tester làm việc trong webchat router console không thấy được slot progress real-time.

**Giải pháp**: Thêm tab thứ 4 "✅ HIL Slots" vào webchat router console (`bizcity-bot-webchat`):
- Tab button + panel div + expanded mode CSS
- `_bizcLoadHilChecklist()`: AJAX fetch từ `bizcity_intent_monitor_hil` endpoint (reuse backend), auto-refresh 15s
- `_bizcRenderHilPanel()`: Stats bar (total, waiting, avg fill rate) + per-conversation cards với:
  - Goal label + channel/turns info
  - Progress bar (color-coded)
  - Slot checklist: ✅ filled `#a6e3a1`, ⏳ waiting `#fab387`, ⬜ pending `#585b70`, ⏭️ skipped `#6c7086`
  - Value snippets (truncated)

| File | Thay đổi |
|------|----------|
| `class-admin-dashboard.php` (bizcity-bot-webchat) | Tab button (+1 line), panel div (+3 lines), expanded CSS (+1 line), `bizcSwitchLogTab()` updated (+5 lines), `_bizcLoadHilChecklist()` (+40 lines), `_bizcRenderHilPanel()` (+55 lines) |

#### 📊 SPRINT 3 FILE CHANGE SUMMARY

| File | Lines before → after | Change |
|------|---------------------|--------|
| `class-mode-classifier.php` | 562 → 555 | -7 lines (removed dead constants + stale comments) |
| `class-intent-engine.php` | 2562 → 2628 | +66 lines (O6 + slot fill rate + comment cleanup) |
| `class-intent-monitor.php` | 1629 → 1700 | +71 lines (analytics + UI cleanup) |
| `class-mode-pipeline.php` | 552 → 548 | -4 lines (doc cleanup) |
| `class-admin-dashboard.php` (webchat) | 5846 → 5963 | +117 lines (HIL webchat tab) |
| `bootstrap.php` | 253 → 253 | version bump only |

---

### 2026-03-08 — Session 4: v3.6.3 — Image+Text Fix + Attachment-First Flow + HIL Nonce Fix

> **Hotfix Release** — 3 critical fixes: image+text slot loss, attachment-first flow, HIL webchat nonce
> **Version bump**: v3.6.2 → v3.6.3

#### 🖼️ IMAGE+TEXT SLOT LOSS FIX

**Vấn đề**: User gửi "giá 120k" kèm ảnh trong flow `create_product` → product tạo với "Chưa set giá". Text bị mất vì Router dùng `if(image)/elseif(text)` — mutually exclusive.

**Root cause**: 3 locations trong `class-intent-router.php` (Step 0.5, Step 2, Step 3a) có pattern:
```php
if (!empty($images) && $waiting_for === 'image') { /* store image, skip text */ }
elseif (!empty($waiting_field)) { /* extract text entities */ }
```
Khi image present → text extraction bị skip hoàn toàn.

**Giải pháp**: Sửa cả 3 locations: khi image present VÀ text non-empty → gọi `extract_text_entities_alongside_image()` bên trong image branch.

**New method `extract_text_entities_alongside_image()`** (~80 lines):
- Get plan schema via `$planner->get_plan($goal)`
- Iterate unfilled non-image slots trong plan
- **Price extraction**: regex `/(\d[\d.,]*)\s*(k|K|đ|d|vnđ|vnd|nghìn|ngàn|triệu|tr)?/u` với multiplier logic (k→×1000, triệu/tr→×1000000)
- **Name/title extraction**: Sau khi remove numeric patterns, text ≥3 chars → fill first matching `name/title/tên/product_name/description/mô_tả` slot

| File | Thay đổi |
|------|----------|
| `class-intent-router.php` | 3 if/elseif locations fixed (Step 0.5, Step 2, Step 3a), new `extract_text_entities_alongside_image()` method (+87 lines total, 1480→1567 lines) |

#### 📎 ATTACHMENT-FIRST FLOW (BUFFER → ASK → INJECT)

**Vấn đề**: User gửi ảnh/file TRƯỚC khi có goal → rơi vào knowledge mode passthrough, mất context. User muốn gửi ảnh trước rồi mới nói "tạo sản phẩm từ ảnh này".

**Giải pháp**: 2 bước mới trong engine `process()`:

**Step 1.5A — Attachment Buffer** (~40 lines):
- Trigger: `!empty($images) && !$has_active_goal && !$is_waiting_user && mb_strlen($msg_trimmed) < 10`
- Buffer `_pending_attachments` array vào `slots_json` via `update_slots()`
- Nếu có short text (VD: "giá 120k") → lưu `_pending_text`
- Set WAITING_USER, reply "📎 Đã nhận N ảnh! Bạn muốn làm gì với nó?"
- Log `attachment_buffer` event, early return

**Step 1.5B — Attachment Inject** (~30 lines):
- Trigger: `!empty($pending_attachments) && !empty($msg_trimmed)` (text message arrives with buffered attachments)
- Merge pending attachments vào `$images` + `$params['images']`
- Prepend `_pending_text` to `$message` nếu chưa có
- Clear `_pending_attachments` + `_pending_text` từ slots
- Log `attachment_inject` event, tiếp tục flow bình thường

| File | Thay đổi |
|------|----------|
| `class-intent-engine.php` | Step 1.5A + 1.5B added after conv_id assignment, before Context Builder (+83 lines total, 2629→2712 lines) |

#### 🔐 HIL WEBCHAT NONCE FIX

**Vấn đề**: Tab "✅ HIL Slots" trong webchat router console hiện "Lỗi tải HIL data" với `{"success":false,"data":{"message":"Invalid nonce"}}`. Webchat JS dùng `bizcity_chat` nonce, nhưng HIL endpoint check `bizcity_intent_monitor` nonce.

**Giải pháp**: Tạo AJAX endpoint riêng cho webchat context:
- New action: `wp_ajax_bizcity_webchat_hil_slots`
- Dùng `check_ajax_referer('bizcity_chat', 'nonce')` — cùng pattern với executor endpoints
- Query by session_id/conv_id (không phải global list), `WHERE goal != ''`, `LIMIT 20`
- JS rewrite: XHR GET với `_bizcChatNonce`, sends session_id + conv_id từ `window.bizcCurrentSessionId/bizcCurrentConvId`

| File | Thay đổi |
|------|----------|
| `class-intent-monitor.php` | New AJAX registration + `ajax_webchat_hil_slots()` method (+73 lines, 1701→1774 lines) |
| `class-admin-dashboard.php` (webchat) | `_bizcLoadHilChecklist()` rewritten: XHR GET to new endpoint, `_bizcRenderHilPanel()` updated: `data.items` instead of `data.conversations`, local stats calc (5965→5975 lines) |
| `bootstrap.php` | Version bump 3.6.2 → 3.6.3 |

#### 📊 SESSION 4 FILE CHANGE SUMMARY

| File | Lines before → after | Change |
|------|---------------------|--------|
| `class-intent-router.php` | 1480 → 1567 | +87 lines (3 if/elseif fixes + text extraction method) |
| `class-intent-engine.php` | 2629 → 2712 | +83 lines (Step 1.5A/1.5B attachment flow) |
| `class-intent-monitor.php` | 1701 → 1774 | +73 lines (webchat HIL endpoint) |
| `class-admin-dashboard.php` (webchat) | 5965 → 5975 | +10 lines (JS rewrite, net) |
| `bootstrap.php` | 253 → 253 | version bump only |

---

### 2026-03-15 — Session: LLM-First Mode Classifier v3.0 + SQL Classification Cache + Tool Self-Awareness

> **Phase 10.5c** — SQL Classification Cache: tránh gọi LLM lặp lại
> **Phase 10.5d** — LLM-First Mode Classifier v3.0: xóa ~80 regex patterns
> **Phase 10.5e** — Tool Manifest in System Prompt: AI biết mình có gì
> **Version bump**: v3.3.1 → v3.4.0

#### 🧠 LLM-FIRST MODE CLASSIFIER v3.0

**Vấn đề**: Mode Classifier v2 dùng pattern-first (regex match trước, LLM fallback). Dẫn đến false-positive khi keyword xuất hiện trong câu thoại bình thường (VD: "tôi buồn" → emotion, nhưng thực ra muốn hỏi execution).

**Giải pháp**: Đảo ngược priority: LLM classify primary → memory-only patterns cho edge cases.

**Files đã sửa:**

| File | Thay đổi |
|------|----------|
| `class-mode-classifier.php` | Xóa 6 pattern property arrays (~80 patterns), `init_patterns()` → 16 memory-only, `classify()` restructured LLM-first, xóa `score_all_modes()`, tool-aware LLM prompt, `llm_model` in meta. 698→547 lines |
| `class-intent-engine.php` | Xóa Step 2.1 Provider Goal Pre-check (regex override scan tất cả goal patterns) |

#### 💾 SQL CLASSIFICATION CACHE

**Vấn đề**: Cùng một câu hỏi + context tương tự, LLM phải classify lại mỗi lần → tốn tokens, chậm response.

**Giải pháp**: Cache 2 lớp (mode + intent) vào SQL, tra cứu trước khi gọi LLM.

**Files đã tạo:**

| File | Mô tả |
|------|--------|
| `class-intent-classify-cache.php` (NEW — 517 lines) | Singleton cache class. SHA256 key = normalize(msg) + context_hash. TTL mode 7d, intent 3d, high-conf ×2. In-memory dedup. `get_mode/set_mode`, `get_intent/set_intent`, `cleanup`, `invalidate_goal`, `flush`, `get_stats` |

**Files đã sửa:**

| File | Thay đổi |
|------|----------|
| `class-mode-classifier.php` | Inject cache check trước LLM, cache write sau LLM |
| `class-intent-router.php` | Inject cache check trước Step 2 LLM, cache write sau success. Flag `$intent_cache_hit` ngăn write lại cache-read |
| `class-intent-database.php` | Thêm `BizCity_Intent_Classify_Cache::instance()->maybe_create_table()` vào migration |
| `bootstrap.php` | `require_once class-intent-classify-cache.php`, version 3.3.1 → 3.4.0 |

**DB Schema:**

```sql
CREATE TABLE bizcity_intent_classify_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(64) NOT NULL UNIQUE,
    message_norm TEXT,
    context_hash VARCHAR(64),
    -- Mode (Layer 1)
    mode VARCHAR(30), mode_confidence DECIMAL(3,2), is_memory TINYINT(1),
    -- Intent (Layer 2)
    intent VARCHAR(100), goal VARCHAR(100), goal_label VARCHAR(200),
    entities_json TEXT, intent_confidence DECIMAL(3,2),
    suggested_tools TEXT, missing_fields TEXT, goal_objective TEXT,
    -- Meta
    hit_count INT DEFAULT 0, last_hit_at DATETIME,
    source_model VARCHAR(100), expires_at DATETIME,
    created_at DATETIME, updated_at DATETIME
);
```

#### 🔧 TOOL MANIFEST IN SYSTEM PROMPT (Step 7.5)

**Vấn đề**: Khi user hỏi "bạn có công cụ gì?", AI không biết vì tool manifest chỉ inject vào internal classifier prompt, không vào user-facing system prompt.

**Giải pháp**: Thêm Step 7.5 trong `build_system_prompt()` — inject `build_tools_context(1500)` giữa Role Block (Step 7) và Filters (Step 8).

**Files đã sửa:**

| File | Thay đổi |
|------|----------|
| `class-chat-gateway.php` (bizcity-knowledge) | Step 7.5: inject `BizCity_Intent_Tool_Index::build_tools_context(1500)`. Instruction: "Khi Chủ Nhân hỏi, HÃY liệt kê CỤ THỂ". Pipeline log `7.5:Tools` |

---

### 2026-03-14 — Session: @Mention Agent Targeting + Knowledge Plugin Upgrades + BizCoach Fix

> **Phase 11** — @Mention Architecture: type @AgentName in chat to target specific agent
> **Phase 10** — chatgpt-knowledge + gemini-knowledge Intent Provider upgrade
> **Bugfix** — bizcoach-map platform column display mismatch

#### 🏗️ @MENTION AGENT TARGETING SYSTEM

**Tính năng mới:** Cho phép user type `@tên_agent` trong chat input để chỉ định trực tiếp agent xử lý, giống ChatGPT's @GPTs. Giải quyết vấn đề LLM Router phân loại sai khi nhiều agents có goals tương tự.

**Flow:**

```
User types "@chatgpt hỏi về AI"
  → JS: @autocomplete dropdown (từ Touch Bar agent data)
  → JS: chọn agent → provider_hint="chatgpt-knowledge" gắn vào FormData
  → PHP: handle_sse() extract provider_hint → pass to Engine
  → Engine Step 2.0: provider_hint match → force MODE_EXECUTION (0.95 conf)
  → Router Step 0: 
      ├─ Single-goal provider → SKIP LLM entirely (0 cost!) → new_goal direct
      └─ Multi-goal provider → LLM with bias prompt "Ưu tiên agent X"
  → Execution continues as normal
```

**Files đã sửa (4 files, 4 layers):**

| File | Layer | Thay đổi |
|------|-------|----------|
| `class-admin-dashboard.php` (bizcity-bot-webchat) | Client JS | @mention CSS dropdown + autocomplete + tag badge + provider_hint in FormData |
| `class-intent-stream.php` (bizcity-intent) | Server Gateway | Extract `$_REQUEST['provider_hint']` → pass to Engine |
| `class-intent-engine.php` (bizcity-intent) | Engine | Step 2.0: @MENTION PROVIDER HINT — force execution + log override |
| `class-intent-router.php` (bizcity-intent) | Router | Step 0: single-goal skip LLM (0 cost) / multi-goal LLM bias prompt |

**Cost optimization:**
- Single-goal provider (e.g. calo plugin) → 0 LLM token cost (skip classify entirely)
- Multi-goal provider → same cost but higher accuracy (bias prompt)

#### ✅ Knowledge Plugin Intent Provider Upgrades

**bizcity-chatgpt-knowledge** + **bizcity-gemini-knowledge** — upgraded từ empty stub Intent Providers sang full SDK-compliant registration với `bizcity_intent_register_plugin()`.

Mỗi plugin giờ có:
- **3 goals**: ask (hỏi), bookmark (lưu), history (lịch sử)
- **3 plans**: slot schemas cho từng goal (question required, depth optional)
- **3 tool callbacks**: class-tools-{chatgpt,gemini}-knowledge.php (NEW files)
- **Context function**: user preferences + recent search topics
- **System instructions**: personality + behavior prompt
- **Fallback**: if `bizcity_intent_register_plugin()` not available → old class-based provider

**Files đã tạo:**
- `bizcity-chatgpt-knowledge/includes/class-tools-chatgpt-knowledge.php` (3 tools)
- `bizcity-gemini-knowledge/includes/class-tools-gemini-knowledge.php` (3 tools)

**Files đã sửa:**
- `bizcity-chatgpt-knowledge/bizcity-chatgpt-knowledge.php` — full provider config
- `bizcity-gemini-knowledge/bizcity-gemini-knowledge.php` — full provider config

#### 🐛 BizCoach Map Platform Column Fix

- **[bizcoach-map]** `admin-user-profiles.php` — 2 display references `$coachee['platform']` → `$coachee['platform_type']`. SQL đã đúng `platform_type` trong local code, nhưng production chạy version cũ dùng `p.platform` → lỗi Unknown column.

---

### 2026-03-06 — Session: T3 Publish_Post Simplified + Double Message Fix + Architecture Pivot

> **Phase 10.5** — T3 publish_post simplified v2 (remove bottlenecks, < 30s target)
> **Phase 10** — Double message + 5-hour timestamp bug fix in ADMINCHAT
> **Decision**: Pivot to Simple Execution — bypass Planner/Preflight for production speed

#### 🏗️ ARCHITECTURE PIVOT: Simple Execution First

**Quyết định chiến lược:** Bỏ qua bizcity-planner và bizcity-preflight giai đoạn đầu, tập trung vào **Simple Input → Direct Execution** pattern để nhanh chóng đưa tools vào production.

**Kiến trúc thực tế đang chạy production:**

```
User message
  → Chat Gateway (bizcity_chat_pre_ai_response filter)
    → Intent Engine::process()
      → Step 2: Mode Classifier (6 modes)
      → Step 2.5: Non-execution → mode pipeline (emotion/knowledge/etc), EXIT
      → Step 3: Intent Router::classify() — goal pattern matching + LLM
      → Step 4: Goal/slot management (new_goal, provide_input, continue_goal)
      → Step 5: Inline Planner::plan() — SIMPLE slot-check (NOT bizcity-planner mu-plugin)
      → Step 6: Execute action:
          ask_user  → hỏi missing slot
          call_tool → bizcity_intent_execution_detected hook
            → Executor Bridge: built-in tool? YES → skip → inline tools->execute()
            → Tool callback chạy SYNC → kết quả ngay → reply
```

**Component status thực tế:**

| Component | Status | Vai trò |
|-----------|--------|---------|
| **Mode Classifier** | ✅ ACTIVE | Routes 6 modes: emotion/reflection/knowledge/planning/coding/execution |
| **Intent Router** | ✅ ACTIVE | Goal pattern matching (regex + LLM) cho execution mode |
| **Inline Planner** (`class-intent-planner.php`) | ✅ ACTIVE | Simple slot-checker: ask missing fields → call_tool. ~20 built-in plans |
| **Intent Tools** | ✅ ACTIVE | Built-in tool registry + sync execute callbacks |
| **Tool Plugins** (e.g. bizcity-tool-content) | ✅ ACTIVE | **Developer-Packaged Pipeline**: self-contained multi-step logic bên trong callback |
| **bizcity-planner** | Loaded, BYPASSED | Chỉ active cho MODE_PLANNING (hiếm). Không dùng cho execution mode |
| **bizcity-executor** | Loaded, MOSTLY BYPASSED | Skip built-in tools. Chỉ active cho async workflow_map-registered multi-step |
| **bizcity-preflight** | Loaded, PASSIVE | Captures snapshots only. Không block execution |

#### 🐛 T3 Publish_Post Simplified v2 (bizcity-tool-content)

**Vấn đề gốc:** `_tool_content_publish_post_inner` chạy 60-90s, vượt LEASE_SECONDS=90s hoặc XHR timeout=120s → T3 stuck.

**3 bottleneck đã xóa:**

| Bottleneck | Thời gian | Fix |
|------------|-----------|-----|
| `wp_kses_post($content)` | 2-3s trên HTML dài | Xóa — `wp_insert_post` tự handle |
| `_tool_content_gather_dual_context()` | 3-5s (2 DB queries) | Xóa — T1/T2 đã pass data via template resolution |
| `_tool_content_download_featured_image()` | 30s × 2 retry = 60s max | Giảm: 1 attempt, 15s timeout, dùng `twf_upload_image_to_media_library()` |

- **[bizcity-tool-content]** `class-function-api.php` — REWRITE `_tool_content_publish_post_inner` as "SIMPLIFIED v2": timing logs ở mỗi step (START → INSERT → IMAGE → DONE), target < 30s total — Phase 10.5
- **[bizcity-tool-content]** Giữ `_tool_content_download_featured_image()` legacy function nhưng v2 handler không gọi nó — Phase 10.5

#### 🐛 Double Message + 5-Hour Timestamp Fix (ADMINCHAT)

**Vấn đề:** Bot messages xuất hiện 2 lần trong ADMINCHAT, timestamps cách nhau đúng 5 giờ (UTC vs UTC+7).

**Root cause analysis:**

1. **Timezone mismatch**: MySQL server dùng timezone khác UTC. `pollNewMessages()` JS append `'Z'` vào `created_at` (coi là UTC), nhưng `Date.now()` là actual UTC epoch → diff 5 giờ → dedup fail
2. **Dedup window quá phụ thuộc timestamp**: Dedup layer 3 so sánh text + time window (5 phút). Khi timestamp sai 5 giờ → window không bao giờ match → bot message từ poll hiện lại
3. **Race condition**: SSE stream done → `syncLastMsgId()` chạy trước DB insert hoàn tất → poll starts với stale `lastMsgId` → tìm thấy bot message → hiện lại

**Fix (3 files):**

- **[bizcity-bot-webchat]** `class-ajax-handlers.php` — `ajax_session_poll()`: SQL thêm `UNIX_TIMESTAMP(created_at) AS created_ts` → UTC epoch chính xác — Phase 10
- **[bizcity-bot-webchat]** `class-ajax-handlers.php` — `ajax_session_messages()`: thêm `created_ts` field — Phase 10
- **[bizcity-bot-webchat]** `class-webchat-database.php` — `get_messages_by_session_id()`: SQL thêm `UNIX_TIMESTAMP(created_at)` — Phase 10
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` — 4 điểm load history: dùng `m.created_ts * 1000` thay vì `new Date(m.created_at).getTime()` — Phase 10
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` — Poll dedup layer 3: BỎ time window check, dùng **text-only matching** (exact text match → always dedup; partial 100 chars → dedup nếu text > 50 chars) — Phase 10

#### ⚠️ Known Issue Discovered: `ajax_stream()` Missing Pre-Reply Filter

- **[bizcity-knowledge]** `class-chat-gateway.php` `ajax_stream()` (line 1214) — KHÔNG apply `bizcity_chat_pre_ai_response` filter. Intent Engine hoàn toàn bị bypass khi SSE streaming works. Chỉ `ajax_send()` (line 209) có filter. Cần fix nếu muốn Intent Engine handle SSE requests → Known Issue #25

---

### 2026-03-06 — Session (earlier): Pre-flight Input Gate + Worker Bug Fixes

> **Phase 10.4** — Pre-flight Missing Field Detection & Polling UX (CODE BUILT, DEFERRED)
> **Phase 10.2** — Worker produce() ordering fix + retry status fix + safety try/catch

> ⚠️ Pre-flight code đã xây dựng nhưng **DEFERRED** — production dùng Inline Planner slot-check thay thế

#### 🚨 CRITICAL NEW PHASE: Pre-flight Input Gate (Phase 10.4)

**Vấn đề gốc:** Khi user nói "đăng bài về chủ đề dinh dưỡng", system chạy ngay mà KHÔNG kiểm tra xem `topic` đã đủ dài / đủ rõ chưa. Thiếu hoàn toàn bước xác minh input trước khi tạo trace.

**Cơ chế đã triển khai:**

```
dispatch() → expand_single_tool_plan()
     │
     ▼
preflight_validate()        ← KIỂM TRA MỌI required fields từ Tool Registry input_schema
     │                         ├─ Bỏ qua {{T*.output.*}} templates (Worker resolve runtime)
     │                         ├─ Check empty / null
     │                         └─ Check minLength constraint
     │
     ├─── fields OK ──→ create trace → enqueue → execute bình thường
     │
     └─── fields MISSING
              │
              ├─ build_preflight_prompt()       → "❓ Mình cần thêm thông tin..."
              ├─ send_preflight_ask()            → Gửi vào chat session
              ├─ set_transient(partial_plan)     → Lưu plan + missing fields (30 phút TTL)
              └─ return 'preflight_waiting'      → DỪNG, không tạo trace
                        │
                  User trả lời
                        │
              intercept_executor_reply()
                        │
              extract_preflight_answers()
                ├─ 1 field  → reply nguyên văn = giá trị
                └─ N fields → LLM structured extraction (Gemini Flash Lite)
                        │
              Merge vào plan → Re-validate
                ├─ Vẫn thiếu → hỏi lại (loop)
                └─ Đủ hết   → delete transient → dispatch() → chạy workflow
```

- **[bizcity-executor]** ✅ `class-intent-bridge.php` — thêm `preflight_validate()`: duyệt mỗi task, lấy `input_schema.required` + `properties.*.minLength` từ Tool Registry, skip output templates `{{T*.output.*}}`, collect missing fields — Phase 10.4
- **[bizcity-executor]** ✅ `class-intent-bridge.php` — thêm `build_preflight_prompt()`: sinh prompt dạng "❓ Mình cần thêm một số thông tin..." liệt kê từng field + description — Phase 10.4
- **[bizcity-executor]** ✅ `class-intent-bridge.php` — thêm `extract_preflight_answers()`: single-field fast path (reply IS value) + multi-field LLM extraction (Gemini Flash Lite, temp=0.1) + fallback assign first field — Phase 10.4
- **[bizcity-executor]** ✅ `class-intent-bridge.php` `dispatch()` — insert preflight check giữa `expand_single_tool_plan()` và `store->create()`. Nếu missing → gửi ask, lưu transient, return 'preflight_waiting' — Phase 10.4
- **[bizcity-executor]** ✅ `class-intent-bridge.php` `intercept_executor_reply()` — check partial plan transient trước human gate. Nếu có → extract answers → merge → re-validate → loop hoặc dispatch — Phase 10.4
- **[bizcity-executor]** ✅ `class-executor-messenger.php` — thêm `send_preflight_ask()`: gửi missing fields question vào chat session, type `executor_preflight_ask`, event `preflight_ask` — Phase 10.4
- **[bizcity-executor]** ✅ Transient-based cross-request state: `bizcity_executor_partial_plan_{session_id}` với 30 phút TTL — Phase 10.4

#### 🐛 Worker Bug Fixes (3 critical bugs)

- **[bizcity-executor]** 🐛 **ROOT CAUSE #1**: `class-worker.php` `succeed_task()` — `do_action()` chạy TRƯỚC `produce()`. Nếu bất kỳ hook nào throw exception (Execution Logger, Artifact Store, Messenger), `produce()` KHÔNG BAO GIỜ chạy → T3 không bao giờ được enqueue → stuck tại PENDING mãi mãi. **Fix**: đảo thứ tự — `produce()` chạy TRƯỚC `do_action()` + wrap `do_action` trong try/catch — Phase 10.2
- **[bizcity-executor]** 🐛 **ROOT CAUSE #2**: `class-worker.php` `fail_task()` — retry đặt task status = `'pending'`, nhưng `process()` load tasks filter = `['queued', 'running', 'failed']` → task 'pending' invisible → task_not_found → cascading failure. **Fix**: (A) thêm `'pending'` vào filter, (B) đổi retry status thành `'queued'` — Phase 10.2
- **[bizcity-executor]** 🐛 `class-executor-monitor.php` `ajax_execute_step()` — wrap `run_one_for_trace()` trong try/catch. Nếu Worker exception → produce() và check_completion vẫn chạy (defense-in-depth) — Phase 10.2
- **[bizcity-executor]** `class-queue-producer.php` `produce_ready_tasks()` — thêm diagnostic logging: candidate count, SKIP reason, ENQUEUED job_id, ENQUEUE FAILED error — Phase 10.2
- **[bizcity-bot-webchat]** `assets/widget.js` `scrollToBottom()` — thêm `if (!container.length) return;` guard chống TypeError khi `#bizchat-messages` không tồn tại — Phase 10.1

---

### 2026-03-13 — Session: Fix "Vui lòng cung cấp: 0" Bug + Executor→Logger Bridge

> **Phase 10.2** — Fix broken slot-filling, add missing goal patterns, auto-fill message, wire executor events to Working Panel

#### 🐛 CRITICAL BUG FIX: "Vui lòng cung cấp: 0"
- **[bizcity-tool-content]** `class-intent-provider.php` — `get_plans()` `required_slots` dùng **numeric array** `['message']` thay vì associative `['message' => ['type' => '...', 'prompt' => '...']]`. Planner `foreach ($plan['required_slots'] as $field => $config)` iterates `$field=0, $config='message'` → `slot_filled($merged_slots, 0)` → not found → asks "Vui lòng cung cấp: 0". **Root cause**: missing format validation giữa provider và planner contract. **Fix**: full rewrite associative arrays + proper prompts — Phase 10.2
- **[bizcity-tool-content]** `class-intent-provider.php` — `optional_slots` cùng bug: `['image_url', 'title']` là numeric array. Fixed → associative with type/choices config — Phase 10.2

#### Intent Provider Expansion (bizcity-tool-content)
- **[bizcity-tool-content]** `get_goal_patterns()` — 2 patterns → **5 patterns**: `rewrite_article`, `write_seo_article`, `translate_and_publish`, `schedule_post`, `write_article`. Pattern order: specific first (viết lại → SEO → dịch → lên lịch → viết bài). "Viết lại bài" giờ match `rewrite_article` thay vì `write_article` — Phase 10.2
- **[bizcity-tool-content]** `get_plans()` — 2 plans → **5 plans**: mỗi plan có associative `required_slots` + `optional_slots` + `slot_order` đúng. `rewrite_article` yêu cầu `post_id`; `translate_and_publish` yêu cầu `post_id` + `target_lang` — Phase 10.2
- **[bizcity-tool-content]** `get_tools()` — 2 tools → **5 tools**: thêm `write_seo_article`, `rewrite_article`, `translate_and_publish` — khớp 4 workflow đã có trong `class-function-api.php` — Phase 10.2
- **[bizcity-tool-content]** `get_mode_patterns()` — thêm `viết lại|seo|dịch bài` cho mode boost — Phase 10.2
- **[bizcity-tool-content]** `build_context()` — mở rộng context labels map cho 5 goals — Phase 10.2

#### Auto-fill 'message' Slot
- **[bizcity-intent]** `class-intent-engine.php` Step 4b (new_goal) — thêm auto-fill: `$intent['entities']['message'] = $message` khi LLM không extract 'message' entity. Nhiều workflow dùng `{{slots.message}}` làm primary instruction — user's natural language text chính là message. Tránh hỏi lại thừa kiểu "Bạn muốn viết gì?" khi user đã nói rõ — Phase 10.2

#### Executor → Logger Bridge
- **[bizcity-executor]** `bootstrap.php` — thêm 5 hook listeners bridge executor events → `BizCity_Execution_Logger`: `bizcity_executor_trace_created` → `pipeline_start()`, `bizcity_executor_task_started` → `tool_invoke()`, `bizcity_executor_task_completed` → `tool_result()`, `bizcity_executor_completed` → `pipeline_complete('success')`, `bizcity_executor_failed` → `pipeline_complete('error')`. Working Panel's `_poll()` endpoint giờ nhận được executor task lifecycle entries bên cạnh intent pipeline logs — Phase 10.2

---

### 2026-03-13 — Session 2: Fix Executor Not Publishing Articles

> **Phase 10.2** — 3 root causes cho "ko đăng bài được + báo thành công sai"

#### 🐛 CRITICAL: Executor Claims nhưng Không Đăng Bài
- **Root cause 1 — Executor claim ngăn inline execution**: `dispatch()` expand `write_article` → 3-task workflow → `task_count=3 > 1` → sets `$GLOBALS['bizcity_executor_claimed']` → Intent Engine skips inline `tools->execute()`. Worker chạy async WP Cron (every minute) nhưng user kỳ vọng immediate results (URL + edit link). **Triệu chứng**: User thấy "⏳ Đã nhận nhiệm vụ..." hoặc AI compose fake success thay vì actual post URL.
- **Root cause 2 — Slot name mismatch**: Provider plan `required_slots: { message: {...} }` nhưng built-in `builtin_write_article()` reads `$slots['topic']`. Khi inline runs: `topic` = '' → `missing_fields: ['topic']` → engine asks for field → confusion.
- **Root cause 3 — Logger bridge wrong key**: `$row['plan_snapshot']` không tồn tại (correct key: `plan_json`). Và `plan_json` là JSON string (đã `wp_json_encode()`), không phải array → `count($row['plan_snapshot']['tasks'])` = 0.

#### Fix 1: Built-in write_article accepts `message` as alias for `topic`
- **[bizcity-intent]** `class-intent-tools.php` line 642 — `$topic = $slots['topic'] ?? $slots['message'] ?? '';`. Backward compatible: cũ dùng `topic`, mới dùng `message`, cả hai đều hoạt động — Phase 10.2

#### Fix 2: Bridge skips async dispatch for built-in tools
- **[bizcity-executor]** `class-intent-bridge.php::on_execution_detected()` — thêm check: nếu `BizCity_Intent_Tools::has($tool_name)` && `get_tool_source() === 'built_in'` → return null (skip dispatch). Inline execution runs synchronously → user nhận kết quả ngay (post URL, edit link). Executor async chỉ claim tools KHÔNG có built-in handler — Phase 10.2

#### Fix 3: Logger bridge correct key + json_decode
- **[bizcity-executor]** `bootstrap.php` `bizcity_executor_trace_created` hook — đổi `$row['plan_snapshot']` → `json_decode($row['plan_json'], true)`. Giờ `task_count` và `workflow` title hiển thị đúng trong execution log — Phase 10.2

---

### 2026-03-12 — Session: Full System Audit + Production Fixes + CodingPipeline

> **Phase 10 + 10.2 + 10.3** — Rà soát toàn bộ intent→planner→executor flow, fix 4 production bugs, thêm CodingPipeline, seed data cho planner, planner extension tags cho tool-content

#### Production Bug Fixes (Deploy bizcity-executor + bizcity-planner lên production)
- **[bizcity-executor]** 🐛 FIX: `class-executor-db.php` `sql_tool_registry()` — MariaDB không hỗ trợ functional indexes `CAST()`. Đổi sang prefix indexes `(191)` cho `tool_key`, `plugin_slug` — Phase 10.2
- **[bizcity-chatgpt-knowledge]** 🐛 FIX: `class-intent-provider.php` — `build_context($message, $user_id, $extra=[])` sai signature → PHP Declaration Warning. Sửa thành `build_context($goal, array $slots, $user_id, array $conversation)` khớp parent `BizCity_Intent_Provider` — Phase 7
- **[bizcity-gemini-knowledge]** 🐛 FIX: `class-intent-provider.php` — cùng declaration warning. Sửa `build_context()` + `get_system_instructions($goal)` khớp parent — Phase 7
- **[bizcity-intent]** 🐛 **CRITICAL FIX**: `class-intent-engine.php` line 862 — `do_action('bizcity_intent_execution_detected', wp_json_encode($plan_result), ...)` gửi JSON **string** nhưng executor's `on_execution_detected(array $plan_json, ...)` expect **array** → PHP TypeError → request killed → user không nhận response. **Root cause**: biến `$plan_json` đặt tên ambiguous, 2 plugins viết ở thời điểm khác nhau không có contract test. **Fix**: pass `$plan_result` (array) trực tiếp — Phase 10.2

#### CodingPipeline — Mode Pipeline Mới
- **[bizcity-intent]** ✅ **NEW** `BizCity_Coding_Pipeline` trong `class-mode-pipeline.php` — Xử lý mode `coding` (Builder Mode). Trước đây mode=coding KHÔNG có pipeline → fall through sang execution Step 3 → misroute coding requests thành tool actions — Phase 10
- **[bizcity-intent]** CodingPipeline: action=`compose`, temperature=0.3, system prompt tối ưu cho code generation (code blocks, step-by-step, best practices, edge cases) — Phase 10
- **[bizcity-intent]** CodingPipeline: inject Knowledge context (technical docs) + Profile context — Phase 10
- **[bizcity-intent]** `BizCity_Mode_Pipeline_Registry`: đăng ký `BizCity_Coding_Pipeline` — giờ có **5 built-in pipelines** (Emotion, Reflection, Knowledge, Planning, Coding) cho 5/6 modes. Chỉ `execution` mode đi qua Intent Router — Phase 10

#### Planner Seed Data + DB Upgrade
- **[bizcity-planner]** `class-planner-db.php` DB version 1.0.0 → **1.1.0** — trigger re-install + seed — Phase 10.2
- **[bizcity-planner]** ✅ **NEW** `seed_intent_candidates()` — 7 seed records mapping 5 built-in intents → tool_keys: `write_article` → 3 tools (generate_article + generate_image + publish_post), `generate_image` → 1 tool, `send_notification` → 1, `generate_video` → 1, `data_analysis` → 1. Dùng INSERT IGNORE → safe re-run — Phase 10.2
- **[bizcity-planner]** ✅ **NEW** `seed_playbooks()` — 1 playbook template `write_article_v1` (3-task DAG: T1→T2→T3 với dependencies + `{{T*.output.*}}` template variables). Dùng existence check → safe re-run — Phase 10.2

#### bizcity-tool-content Planner Extension Tags
- **[bizcity-tool-content]** `class-function-api.php` — thêm `capability_tags`, `intent_tags`, `domain_tags`, `constraints_json` cho cả 3 MCP tool registrations. Cho Planner's Tool Index tìm + Scorer rank tools theo intent_key — Phase 10.2

#### bizcity-planner.php MU-Plugin Loader
- **[bizcity-planner]** ✅ **NEW** `bizcity-planner.php` loader tại mu-plugins root — tương tự bizcity-intent.php pattern. Priority: 15 (sau executor 10) — Phase 10.2

#### Full System Audit — Findings Summary
> Rà soát toàn bộ 4-branch routing (emotion → knowledge → planning → execution),
> planner (classify → map → playbook → dispatch), executor (bridge → trace → queue → worker → callback),
> working panel integration, function API scaffolds.

**4-Branch Routing Audit**:
- ✅ EmotionPipeline: empathy + safety_guard + intensity-based routing
- ✅ ReflectionPipeline: mirror + coaching texture
- ✅ KnowledgePipeline: 4-scenario router (S0-S3) + provider dispatch
- ✅ PlanningPipeline: 2-phase (planner hook → AI fallback)
- ✅ **CodingPipeline**: MỚI thêm session này — enriched compose, temp=0.3
- ✅ Execution: Intent Router → Planner → call_tool → executor hook → inline fallback

**Planner Audit**:
- ✅ `class-planner-core.php`: receive → classify → map → cache → dispatch. WORKS - fully implemented
- ✅ `class-intent-classifier.php`: hybrid (rule+DB+fallback), 5 built-in intents, min confidence 0.40. WORKS
- ✅ `class-mapping-engine.php`: playbook → A/B variant → task list → HIL → verifier. WORKS (data dependency resolved by seed)
- ✅ `class-planner-db.php`: 7 tables, seed data. WORKS
- ⚠️ Tool Index reads from executor's `wp_bizcity_tool_registry` — by design, single source of truth

**Executor Audit**:
- ✅ `class-trace-store.php`: create trace + tasks, get_snapshot, dependencies_met. WORKS
- ✅ `class-queue-producer.php`: tick() → recover_stale → produce_ready → advance_completed → kick_worker. WORKS (WP Cron every minute + inline)
- ✅ `class-worker.php`: real `call_user_func($tool['callback'], $input, $context)` execution, retry logic, exponential backoff, dead-letter. WORKS - NOT stub
- ✅ `class-tool-registry.php`: in-memory + DB upsert, 9+ required fields validation. WORKS
- ✅ `class-intent-bridge.php`: hooks both `bizcity_intent_execution_detected` + `bizcity_planner_plan_ready`. WORKS (after string-vs-array fix)
- ✅ `class-executor-monitor.php`: 5 AJAX endpoints (poll, retry, cancel, get_run, session_traces). WORKS

**Working Panel Audit**:
- ✅ Executor Admin Monitor: 3-tab panel (Traces, Tool Registry, Recent Runs), 3s polling. WORKS
- ✅ Webchat Working Panel: 1660-line floating monitor, dual polling (exec log + executor traces). WORKS
- ✅ Event bridge: `bizcityTaskStarted`/`bizcityTaskCompleted`/`bizcitySessionChanged`. WIRED
- ✅ `bizcity_executor_session_traces` endpoint (nopriv) — Working Panel consumes via `conv_id`/`session_id`. WORKS

**Function API Audit**:
- ✅ `bizcity-tool-content`: 3 MCP tools registered + 1 workflow (`write_article` → 3-task DAG). WORKS
- ✅ Scaffold template: `bizcity-executor/scaffold/class-function-api.php` — complete manual template. EXISTS
- ⚠️ Only 1 tool plugin currently registered (bizcity-tool-content). Need more agent plugins.

---

### 2026-03-11 — Session: Intent→Planner→Executor Architecture Wiring + Observability

> **Phase 10.2 + 10.3** — Wire full intent→planner→executor flow + prompt logging + 3 new admin tabs

#### Phase 10.2 — bizcity-executor Skeleton + DB (Prior sessions)
- **[bizcity-executor]** ✅ **NEW** MU-plugin — 11+ PHP classes: Intent Bridge, Worker, Task Runner, DB, Monitor, Tool Registry, etc. — Phase 10.2
- **[bizcity-executor]** DB v1.2.0: 8 tables — `tool_registry` (12 planner fields + `tool_key`), `traces`, `tasks`, `task_deps`, `verifications`, `cost_logs`, `permissions`, `ab_experiments` — Phase 10.2
- **[bizcity-executor]** `class-intent-bridge.php`: hooks `bizcity_intent_execution_detected` + `bizcity_planner_plan_ready` — Phase 10.2

#### Phase 10.2 — bizcity-planner Skeleton (Prior sessions)
- **[bizcity-planner]** ✅ **NEW** MU-plugin — 21 files: 12 classes, views, assets, ARCHITECTURE.md, ROADMAP.md — Phase 10.2
- **[bizcity-planner]** 7 DB tables — `plans`, `plan_tasks`, `playbooks`, `playbook_variants`, `tool_scores`, `ab_experiments`, `ab_assignments` — Phase 10.2
- **[bizcity-planner]** `class-planner-core.php`: receive → classify → map → playbook → plan → dispatch — Phase 10.2

#### Phase 10.2 — Intent→Planner→Executor Hook Wiring
- **[bizcity-intent]** `class-mode-pipeline.php` `BizCity_Planning_Pipeline::process()` **REWRITTEN** — 2-phase dispatch: Phase 1 fires `do_action('bizcity_intent_plan_request', $message, $plan_context)`, checks `$GLOBALS['bizcity_planner_claimed']`; Phase 2 falls back to AI compose if planner not installed — Phase 10.2
- **[bizcity-planner]** `class-planner-core.php::on_plan_request()` — sets `$GLOBALS['bizcity_planner_claimed']` with plan_id, task_count, title after `make_plan()` success — Phase 10.2
- **[bizcity-executor]** `class-intent-bridge.php` — added `bizcity_planner_plan_ready` hook + `on_plan_ready()` method. Enriches context with `intent_key`, `playbook_key`, `variant`, `source=planner` — Phase 10.2
- **[bizcity-intent]** **KEY FIX**: `bizcity_intent_plan_request` hook was NEVER fired from intent engine. Planning mode only went to AI compose. Now properly dispatched from Planning Pipeline. — Phase 10.2

#### Phase 10.3 — Prompt Logging DB
- **[bizcity-intent]** `class-intent-database.php` — added `$table_prompt_logs` property, `prompt_logs_table()` accessor, CREATE TABLE `bizcity_intent_prompt_logs` (26 columns) — Phase 10.3
- **[bizcity-intent]** `class-intent-database.php` — added `insert_prompt_log()`, `get_prompt_logs()` (filtered + paginated), `get_prompt_log_stats()` (total, avg duration, by mode) — Phase 10.3
- **[bizcity-intent]** Prompt log columns: session_id, conversation_id, user_id, channel, character_id, blog_id, message, images_count, detected_mode, mode_confidence, mode_method, intent_key, goal, goal_label, slots_json, context_summary, context_layers_json, pipeline_class, pipeline_action, tool_calls_json, provider_used, executor_trace_id, planner_plan_id, response_summary, response_action, duration_ms, created_at — Phase 10.3
- **[bizcity-intent]** `bootstrap.php` — bumped version 3.2.0 → **3.3.0**. Added `bizcity_intent_processed` hook (priority 99) auto-logging every processed request — Phase 10.3

#### Phase 10.3 — Intent Monitor Admin Tabs (3 new tabs)
- **[bizcity-intent]** `class-intent-monitor.php` — 3 new AJAX registrations: `bizcity_intent_monitor_tools`, `bizcity_intent_monitor_prompt_logs`, `bizcity_intent_monitor_exec_debug` — Phase 10.3
- **[bizcity-intent]** `class-intent-monitor.php` — **🔧 Tools** tab: merged in-memory (`BizCity_Intent_Tools`) + DB (`BizCity_Tool_Registry`) tools, filter by source/search. Columns: tool_name, tool_key, source, plugin_slug, version, active, capability_tags, intent_tags, input_fields — Phase 10.3
- **[bizcity-intent]** `class-intent-monitor.php` — **📝 Prompt Logs** tab: stats grid (total, avg_duration, by_mode) + filtered table from `bizcity_intent_prompt_logs`. Filter by mode, channel, search. Columns: time, user, channel, mode badge, confidence, intent, prompt excerpt, response excerpt, duration — Phase 10.3
- **[bizcity-intent]** `class-intent-monitor.php` — **⚡ Executor** tab: reads `bizcity_executor_traces` + `bizcity_executor_tasks` tables. Stats grid (total, running, completed, failed). Columns: trace_id, title, status badge, task counts, conv_id, user, started_at, duration — Phase 10.3
- **[bizcity-intent]** Intent Monitor now has **7 tabs** (was 4): Conversations, Turns, Characters, Config, **Tools**, **Prompt Logs**, **Executor** — Phase 10.3

#### Documentation Updates
- **[bizcity-planner]** ROADMAP.md — added Phase 11 (Intent Wiring v1.1.0) with full flow diagram — Phase 10.2
- **[bizcity-executor]** ROADMAP.md — added Phase 6c (Intent Monitor Integration v1.2.0) — Phase 10.2

---

### 2026-03-10 — Session: Execution Monitor + Working Panel + tool_step Logging Standard

> **Phase 10.1** — Bản lề quan sát thực thi: thêm Working Panel + tiêu chuẩn log tool_step cho tất cả built-in tools

#### Phase 10.1 — Working Panel (Floating Execution Monitor)
- **[bizcity-bot-webchat]** ✅ **NEW** `includes/class-working-panel.php` — Floating execution monitor panel (bottom-left), inject vào tất cả WP Admin pages cho `manage_options` users — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel: auto-poll `bizcity_poll_execution_log` mỗi 8s (idle) / 1s (active), hiển thị step entries color-coded theo type — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel: `STEP_INFO` map với icon + label + spin flag cho tất cả log types kể cả `tool_step` mới — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel: `window.BizCityWorkingPanel.trigger(sessionId)` global API — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel: `bwp-active` + `bwp-fab` CSS classes, Catppuccin dark theme, spinner animation cho running steps — Phase 10.1
- **[bizcity-bot-webchat]** `bootstrap.php`: `require_once` `class-working-panel.php` sau admin dashboard — Phase 10.1

#### Phase 10.1 — JS Event System (Admin Dashboard ↔ Working Panel)
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` `_doSend()`: dispatch `bizcityTaskStarted` CustomEvent với `sessionId` khi chat message gửi đi — Phase 10.1
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` `processStream()` done block: dispatch `bizcityTaskCompleted` CustomEvent khi stream kết thúc — Phase 10.1
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` `startNewChat()`: dispatch `bizcitySessionChanged` CustomEvent với `sessionId` mới — Phase 10.1

#### Phase 10.1 — `tool_step` Log Type (Tiêu chuẩn sub-step logging)
> **Mới: `tool_step`** — log type dành cho từng bước nhỏ bên trong 1 tool function.
> Data keys chuẩn: `tool_name`, `sub_step` (format `N/Total label`), `status` (`running|success|error|skipped`), `title`, `message`, `post_id`, `url`, `content_len`

- **[bizcity-intent]** `class-intent-tools.php` — thêm `BizCity_Execution_Logger::log('tool_step', [...])` vào tất cả 5 built-in multi-step tools — Phase 10.1
- **[bizcity-intent]** `builtin_write_article`: log 3 sub-steps — `1/3 ai_generate_content`, `2/3 generate_image`, `3/3 create_wp_post` — running/success/error/skipped — Phase 10.1
- **[bizcity-intent]** `builtin_create_product`: log `1/1 product_post_flow` — running → success/error — Phase 10.1
- **[bizcity-intent]** `builtin_post_facebook`: log `1/1 multi_page_post` và fallback `1/1 direct_post` — running → success/error — Phase 10.1
- **[bizcity-intent]** `builtin_set_reminder`: log `1/1 create_biztask` — running → success — Phase 10.1
- **[bizcity-intent]** `builtin_create_video`: log `1/2 save_script` (running→success) + `2/2 queue_generation` (skipped, async) — Phase 10.1
- **[bizcity-intent]** `builtin_create_video` + `builtin_write_article`: thêm `BizCity_Execution_Logger::error()` trên failure paths — Phase 10.1

#### Phase 10.1 — Dashboard Console Panel (Execution Log) Update
- **[bizcity-bot-webchat]** `class-admin-dashboard.php` `_renderExecLogEntry()`: thêm `tool_step` vào `stepColors` map (màu `#fab387`) — Phase 10.1
- **[bizcity-bot-webchat]** `_renderExecLogEntry()`: thêm `case 'tool_step'` — hiển thị `sub_step`, `status` icon (✅/❌/⏭/⋯), `title`, `content_len`, `post_id`, link `view`, `message` (error) — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel: thêm CSS class `.step-tool_step` + `.step-tool_step.success/.error` với màu tương ứng — Phase 10.1
- **[bizcity-bot-webchat]** Working Panel `_renderEntry()`: thêm `else if (step === 'tool_step')` branch — Phase 10.1

---

### 2026-03-09 — Session: Minimal ChatGPT-Style UI

- **[bizcity-bot-webchat]** Tạo `assets/css/chat-minimal.css` — ChatGPT-inspired minimal theme CSS: sidebar hidden on mobile, touchbar minimizable, no avatar icons, maximized chat area — UX Redesign
- **[bizcity-bot-webchat]** Tạo `assets/js/chat-minimal.js` — JavaScript cho minimal theme: sidebar toggle, touchbar minimize, lazy-load iframe, guest message limit — UX Redesign
- **[bizcity-bot-webchat]** Tạo `includes/class-admin-dashboard-minimal.php` — Minimal dashboard class với ChatGPT-style rendering: clean header, floating input, conversation history — UX Redesign
- **[bizcity-bot-webchat]** Cập nhật `templates/page-aiagent-home.php` — Thêm template switcher: option `bizcity_webchat_template` chọn `minimal` hoặc `legacy`, URL override `?template=minimal` — UX Redesign
- **[bizcity-bot-webchat]** Guest chat support: khi `minimal` template + `bizcity_webchat_guest_chat=1`, guest có thể chat với giới hạn `bizcity_webchat_guest_limit` tin nhắn trước khi yêu cầu đăng nhập — UX Redesign
- **[bizcity-bot-webchat]** Touchbar minimize: `.bizc-m-tb-minimize` button cho phép ẩn touchbar, trạng thái lưu vào localStorage — UX Redesign
- **[bizcity-bot-webchat]** Mobile-first sidebar: sidebar ẩn mặc định trên mobile (≤768px), hiện qua hamburger menu — UX Redesign

---

### 2026-03-04 — Session 5 (Execution Log Console)

- **[bizcity-intent]** ✅ **NEW** `class-execution-logger.php` — Singleton Execution Logger cho pipeline/tool execution tracking — Phase 10
- **[bizcity-intent]** Execution Logger step types: `pipeline_start`, `pipeline_step`, `pipeline_complete`, `tool_invoke`, `tool_result`, `slot_resolve`, `goal_update`, `error` — Phase 10
- **[bizcity-intent]** `BizCity_Execution_Logger::set_session()` — thiết lập session context cho logging — Phase 10
- **[bizcity-intent]** `BizCity_Execution_Logger::tool_invoke()` / `tool_result()` — log tool calls với invoke_id pairing — Phase 10
- **[bizcity-intent]** `get_logs()`, `get_pipeline_trace()`, `get_stats()` — query methods cho dashboard — Phase 10
- **[bizcity-intent]** Transient-based storage: `bizcity_exec_log_{session}` với TTL 2 hours, max 100 logs — Phase 10
- **[bizcity-intent]** `class-intent-tools.php` thêm `get_tool_source()` — trả về 'built_in' | 'plugin' | 'provider' — Phase 10
- **[bizcity-intent]** `class-intent-engine.php` tích hợp Execution Logger: `set_session()` tại boot, `tool_invoke()` + `tool_result()` tại `call_tool` action — Phase 10
- **[bizcity-knowledge]** `class-user-memory.php` thêm `ajax_poll_execution_log()` AJAX endpoint — Phase 10
- **[bizcity-bot-webchat]** Dashboard **Execution Log Panel**: Tab switcher "🧠 Tư duy" | "⚙️ Thực thi" — Phase 10
- **[bizcity-bot-webchat]** `_fetchExecLogs()` + `_renderExecLogEntry()` JavaScript functions — Phase 10
- **[bizcity-bot-webchat]** Poll button giờ fetch cả Router Log và Execution Log song song — Phase 10
- **[bizcity-bot-webchat]** Execution Log panel styles: color-coded step badges, stats header, tool result visualization — Phase 10

---

### 2026-03-04 — Session 4 (Empathic Intelligence Routing)

- **[bizcity-intent]** Add **Step 2.1.5 Intensity Detection** vào `class-intent-engine.php` — gọi `estimate_intensity()` ngay sau `mode_classify`, trước `goal_abandon`. Log step `intensity_detect` với intensity level, empathy_flag, routing_branch — Phase 4
- **[bizcity-intent]** 6 Routing Branches: `execution` | `knowledge` | `reflection` | `emotion_low` | `emotion_high` | `emotion_critical` — determined by mode × intensity threshold — Phase 4
- **[bizcity-intent]** Store `intensity`, `empathy_flag`, `routing_branch` vào `$result['meta']` cho downstream (intent_stream, texture engine) — Phase 4
- **[bizcity-knowledge]** `estimate_intensity()` đã được đổi sang `public` (Session 3) → Engine có thể gọi trực tiếp — Phase 4
- **[bizcity-bot-webchat]** Dashboard `_renderLogEntry()`: thêm display logic cho step `intensity_detect` — hiện intensity bar (color-coded 1-5), empathy flag, routing branch icon — Phase 4
- **[bizcity-bot-webchat]** JSON Export optimization: remove `full_prompt`, `prompt_head`, `prompt_tail` từ `_bizcRouterRawLogs` trước khi export — giảm ~80% JSON size — Phase 4
- **[bizcity-intent]** Add **Emotional Smoothing** cho tool `missing_fields` prompts: **ALWAYS** wrap raw prompt qua fast LLM để giữ mạch cảm xúc — Phase 4
- **[bizcity-intent]** FIX: Bỏ rào cản `$intensity >= 2` — smoothing giờ apply cho MỌI tool prompt vì conversation có thể có emotional context từ turns trước — Phase 4
- **[bizcity-intent]** Add `smooth_tool_ask_prompt()` helper: lấy context từ recent turns + intensity level → generate empathetic question transition — Phase 4
- **[bizcity-bot-webchat]** Dashboard `_renderLogEntry()`: thêm display logic cho step `emotional_smooth` — hiện raw vs smoothed prompt comparison — Phase 4
- **[bizcity-intent]** **CRITICAL FIX**: `smooth_tool_ask_prompt()` sử dụng INTENT conversation turns (chỉ tool state) thay vì WEBCHAT session history → không thấy emotional context. Sửa: lấy messages từ `BizCity_WebChat_Database::get_recent_messages_for_context()` — Phase 4
- **[bizcity-intent]** Thêm empathy marker detection: khi `intensity=1` nhưng recent context chứa "hiểu/nặng/áp lực/khó khăn" → inject tone instruction "Trước đó AI đã thể hiện sự đồng cảm..." — Phase 4
- **[bizcity-intent]** Add `emotional_smooth_fail` log step khi smoothing LLM fail → debug visibility — Phase 4
- **[bizcity-intent]** Pass `routing_branch` to `bizcity_chat_system_prompt` filter args — downstream có thể đọc branch để điều chỉnh behavior — Phase 4
- **[bizcity-knowledge]** **MAJOR**: `class-response-texture-engine.php` giờ nhận `routing_branch` và có branch-specific texture rules — Phase 4
- **[bizcity-knowledge]** Add `branch_specific_rules()` method: 6 branches có actionable texture instructions — Phase 4
- **[bizcity-knowledge]** **emotion_critical** branch: Safety Guard với hotline 1800 599 911, không bắt đầu với giải pháp, thừa nhận sâu cảm xúc — Phase 4
- **[bizcity-knowledge]** **emotion_high** branch: Deep empathy texture, prioritize emotional support, chậm rãi — Phase 4
- **[bizcity-knowledge]** **emotion_low** branch: Casual empathy, acknowledge nhẹ rồi chuyển sang task nếu user có yêu cầu — Phase 4  
- **[bizcity-knowledge]** **reflection** branch: Coaching/mirror texture, đặt câu hỏi mở, không vội đưa ý kiến — Phase 4
- **[bizcity-intent]** Add `has_branch_texture` và `routing_branch` vào final_prompt log — debug visibility cho branches — Phase 4
- **[bizcity-intent]** ✅ **VERIFIED**: 6 Routing Branches hoạt động ACTIONABLE — test cases passed:
  - `reflection` (intensity=1): "suy ngẫm về hướng đi sự nghiệp" → AI: "Mình hiểu mà..." ✅
  - `emotion_high` (intensity=3): "rất lo lắng, không biết chọn hướng nào" → AI: "Nghe có vẻ bạn đang rất lo lắng..." ✅  
  - `emotion_critical` (intensity=5): "kiệt sức, không còn sức, vô nghĩa" → AI: "Mình rất lo lắng khi nghe điều này..." ✅
  - `knowledge`, `execution`, `emotion_low`: pass-through to mode rules ✅

---

### 2026-03-03 — Session 2 (Tool Plugin Scaffold)

- **[bizcity-tool-woo]** Tạo plugin mới — 9 files, 11 tools bao gồm WooCommerce products/orders/reports/inventory + wraps legacy `twf_*` functions — Phase 10
- **[bizcity-tool-content]** Tạo plugin mới — 5 files, tools: `write_article`, `schedule_post` — wraps `ai_generate_content`, `twf_parse_schedule_post_ai` — Phase 10
- **[bizcity-tool-facebook]** Tạo plugin mới — 5 files, tool: `post_facebook` — wraps `twf_handle_facebook_request` — Phase 10
- **[bizcity-tool-reminder]** Tạo plugin mới — 5 files, tools: `set_reminder`, `list_reminders` — wraps `biztask` CPT + OpenAI parse — Phase 10
- **[bizcity-tool-image]** Tạo plugin mới — 5 files, tools: `generate_image`, `generate_image_and_save` — wraps `twf_generate_image_url` (gpt-image-1) — Phase 10
- **[bizcity-intent]** Phát hiện conflict: `bizcity_intent_tools_ready` hook chưa được fire từ engine. Built-in tools trong `class-intent-tools.php` (init priority 20) chiếm `write_article`, `create_product`, `post_facebook` trước → new plugins bị block bởi `has()` guard — Known Issue #11
- **[bizcity-intent]** Phát hiện conflict 2: `class-intent-provider-registry.php::register_provider_tools()` dùng first-wins policy; mu-plugins load trước plugins/ → admin-hook tools luôn thắng — Known Issue #11
- **[bizcity-admin-hook]** `class-intent-provider.php` đã khai báo TẤT CẢ goals trùng với new tool plugins → goal_map cuối cùng do last-registered wins (plugins/ load sau mu-plugins) nhưng tool callback vẫn là built-in — Known Issue #11

---

### 2026-03-03 — Session 3 (Companion Intelligence Fixes)

- **[bizcity-intent]** Fix Bug: `daily_outlook`/`astro_forecast` pattern quá greedy — `tháng này` trong câu không liên quan trigger sai BizCoach flow. Pattern mới yêu cầu có từ khoá dự báo rõ ràng — Phase 4.5
- **[bizcity-intent]** Fix Bug: `bizcity_chat_after_response` hook chưa bao giờ được fire từ `class-intent-stream.php` → `tier_extracted: 0` mãi mãi, bond score không tăng. Thêm `do_action('bizcity_chat_after_response', ...)` sau SSE stream + batch path — Phase 4.5
- **[bizcity-intent]** Add **Step 2.2 Forecast Goal Abandon** vào `class-intent-engine.php`: khi `mode=execution via context` + active goal là `daily_outlook`/`astro_forecast` `WAITING_USER` + message không match slot answer → abandon goal + reset mode sang `emotion` — Phase 4.5
- **[bizcity-intent]** Hybrid C Knowledge Response: inject Gemini reply (`action=reply`) vào system prompt như `## 🔍 KIẾN THỨC MỞ RỘNG` + Team Leader role instruction bao gồm "Tuy nhiên, từ những gì mình được biết..." — Phase 4.5
- **[bizcity-intent]** Debug log step `4.5:KnowledgeExp ✓ HybridC` + `has_knowledge_expansion` field — Phase 4.5
- **[bizcity-intent]** Verified: Layer 1.7 `## 💛 RELATIONSHIP CONTEXT` xuất hiện đầy đủ trong `prompt_tail` — Phase 4.5 ✅
- **[bizcity-intent]** Verified: Response Texture `## 🎨 RESPONSE TEXTURE` xuất hiện đúng vị trí trong full_prompt — Phase 4.5 ✅
- **[bizcity-intent]** Verified: User memory "Nguyệt Anh" được call đúng trong response sau khi fix Bu g 1 — Phase 3 ✅

---

### 2026-03-03 — Session 1 (Companion Intelligence)

- **[bizcity-tarot]** Fix gateway routing `wcs_` sessions không được nhận diện → `gateway-functions.php` trả `'webchat'` cho prefix `wcs_` — Phase 1
- **[bizcity-tarot]** Fix `push_reading` không INSERT vào DB với WEBCHAT/ADMINCHAT sessions — `integration-chat.php` direct INSERT — Phase 1
- **[bizcity-tarot]** Thêm cột `ai_reply LONGTEXT` + migration `install.php` — Phase 9
- **[bizcity-tarot]** Thêm 2 AJAX endpoints: `bct_update_reading_ai` + `bct_get_reading` — Phase 9
- **[bizcity-tarot]** `public.js`: `state.lastReadingId` tracking + save AI reply sau `interpret` — Phase 9
- **[bizcity-tarot]** `page-tarot-profile.php`: thêm "🔮 Xem luận giải" button + modal — Phase 9
- **[bizcity-intent]** Tạo `PIPELINE-ARCHITECTURE.md` — thiết kế đầy đủ Pipeline Orchestrator (15 sections) — Phase 10
- **[bizcity-intent]** Cập nhật `PLUGIN-SKELETON-TEMPLATE.md` — thêm JSON Schema I/O + Pipeline I/O Compatibility Checklist — Phase 10
- **[bizcity-intent]** Cập nhật `ARCHITECTURE.md` — Platform SDK vision: Section 2 mới (Plugin SDK Standard), Section 12 (Companion Intelligence), Phase 4.5, Phase 10 refactor, Phase 12+13 — Design
- **[bizcity-intent]** Tạo `SYSTEM-LOG.md` — file tracking hệ thống này — Meta
- **[bizcity-knowledge]** Verify Issue #6: `class-user-memory.php` vẫn tồn tại + required tại `bootstrap.php:38` → CLOSED — Phase 3
- **[bizcity-tarot]** Verify Issue #9: `ai_reply` migration dùng `SHOW COLUMNS` guard → safe trên production → CLOSED — Phase 9
- **[bizcity-knowledge]** Phase 4.5: Tạo `class-emotional-memory.php` — CRUD emotional types + auto-extract + bond score — Phase 4.5
- **[bizcity-knowledge]** Phase 4.5: Tạo `class-emotional-thread-tracker.php` — Thread lifecycle OPEN/RESOLVED/EXPIRED/RECURRING — Phase 4.5
- **[bizcity-knowledge]** Phase 4.5: Tạo `class-companion-context.php` — Layer 1.7 Relationship Context (Pri 97) — Phase 4.5
- **[bizcity-knowledge]** Phase 4.5: Tạo `class-response-texture-engine.php` — Response Texture Matrix (Pri 48) — Phase 4.5
- **[bizcity-knowledge]** Phase 4.5: Thêm `upsert_public()` wrapper vào `class-user-memory.php` — Phase 4.5
- **[bizcity-knowledge]** Phase 4.5: Cập nhật `bootstrap.php` — require + init 4 companion classes — Phase 4.5

---

### 2026-02-xx (trước session này — từ conversation history)

- **[bizcity-intent]** `class-context-builder.php` Layer 1.5 BizCoach Context (Pri 95) — Phase 8
- **[bizcity-intent]** Webchat Schema V3.0: `bizcity_webchat_projects` + `bizcity_webchat_sessions` — Phase 8.5
- **[bizcity-knowledge]** Agent binding `owner_type` + migration v2.1.0 — Phase 7
- **[bizcity-intent]** 7-Layer Dual Context Chain (v3.1) — Phase 8
- **[bizcity-intent]** Profile Context Provider abstract: `get_profile_context()` + `get_profile_page_url()` — Phase 9

---

## 5. Per-Plugin Backlog

> Việc cụ thể cần làm cho từng plugin, ưu tiên theo roadmap.
> Di chuyển sang "Daily Change Log" khi hoàn thành.

### bizcity-intent (Lõi — Intent Engine)

```
PRIORITY HIGH (Phase 10):
  [x] class-execution-logger.php — Singleton logger for pipeline/tool execution
  [x] Execution Logger AJAX endpoint (ajax_poll_execution_log)
  [x] Dashboard Execution Log Panel with tab switcher
  [x] tool_step log type — sub-step logging standard cho built-in tools (Phase 10.1)
  [x] builtin_write_article: 3-step logging (ai_generate_content → generate_image → create_wp_post)
  [x] builtin_create_product / post_facebook / set_reminder / create_video: tool_step logging
  [x] Intent→Planner dispatch: Planning Pipeline fires bizcity_intent_plan_request (Phase 10.2)
  [x] bizcity_intent_prompt_logs table (26 columns) + CRUD + stats queries (Phase 10.3)
  [x] bizcity_intent_processed hook — auto-log every prompt (Phase 10.3)
  [x] Intent Monitor: 🔧 Tools tab — merged in-memory + DB tools (Phase 10.3)
  [x] Intent Monitor: 📝 Prompt Logs tab — stats grid + filtered table (Phase 10.3)
  [x] Intent Monitor: ⚡ Executor tab — traces + task counts (Phase 10.3)
  [x] CodingPipeline — mode=coding → enriched compose, temp=0.3, code-specific system prompt
  [ ] class-tool-registry.php — Singleton, register() + get_all() + execute()
  [ ] Hook bizcity_intent_tools_ready
  [ ] class-intent-pipeline.php — Pipeline::run($plan, $ctx)
  [ ] class-pipeline-template.php — 4 default templates
  [ ] class-pipeline-resolver.php — resolve $slots.field, $step[N].data.field
  [ ] Planner: thêm action run_pipeline
  [ ] Checkpoint pipeline vào conversation.open_loops
  [ ] Pipeline memory persistence — persist pipeline process() memory array to User Memory

PRIORITY HIGH (Phase 4.5 — Companion):
  [x] class-emotional-memory.php — CRUD + auto-extract (bizcity-knowledge/includes/)
  [x] class-companion-context.php — Layer 1.7 builder (Pri 97) (bizcity-knowledge/includes/)
  [x] class-emotional-thread-tracker.php — OPEN/RESOLVED lifecycle (bizcity-knowledge/includes/)
  [x] class-response-texture-engine.php — intensity × empathy × mode matrix (bizcity-knowledge/includes/)
  [x] BizCity_User_Memory::upsert_public() — public wrapper added for companion classes
  [x] bootstrap.php — require + init tất cả 4 classes mới
  [x] `bizcity_chat_after_response` hook — đã fire từ SSE + batch path trong intent_stream
  [x] Forecast goal abandon (Step 2.2) — emotion shift detection + auto-reset
  [x] Hybrid C Knowledge Response — Gemini reply inject vào system prompt
  [x] Pattern fix: daily_outlook/astro_forecast không còn match sai
  [ ] Mode Classifier: thêm companion trigger detection (mode 7)
  [ ] DB: existing bizcity_user_memory table đủ dùng — verify new memory_type values được index

PRIORITY MEDIUM (Phase 8.5 còn dở):
  [ ] build_rolling_summary($session_id) — LLM summarize mỗi 10 messages
  [ ] get_project_context($project_id)
  [ ] Public projects discoverable via intent

PRIORITY LOW:
  [ ] WP-CLI command: wp bizcity sdk-check <plugin>
  [ ] Tool Registry UI trong wp-admin
```

### bizcity-bot-webchat

```
PRIORITY HIGH (Phase 10.1 — Execution Monitor):
  [x] class-working-panel.php — Floating execution monitor panel (bottom-left, 360px)
  [x] Working Panel: auto-poll bizcity_poll_execution_log (8s idle / 1s active)
  [x] Working Panel: STEP_INFO map cho tất cả log types kể cả tool_step
  [x] Working Panel: CSS color-coded per step type, spinner animation, bwp-active pulse
  [x] bootstrap.php: require_once class-working-panel.php
  [x] class-admin-dashboard.php: bizcityTaskStarted + bizcityTaskCompleted + bizcitySessionChanged events
  [x] _renderExecLogEntry(): thêm tool_step case + stepColors

COMPLETED (2026-03-06 — Double Message + Timestamp Fix):
  [x] class-ajax-handlers.php: UNIX_TIMESTAMP(created_at) AS created_ts trong poll + messages
  [x] class-webchat-database.php: UNIX_TIMESTAMP in get_messages_by_session_id
  [x] class-admin-dashboard.php: JS dùng m.created_ts * 1000 (4 history load points)
  [x] class-admin-dashboard.php: Poll dedup layer 3 → text-only matching (bỏ broken time window)

PRIORITY MEDIUM:
  [ ] build_rolling_summary integration — hiện session summary trong Working Panel header
  [ ] Working Panel: Export sub-step log to clipboard
  [ ] Working Panel: per-tool timing / duration display
```

### bizcity-knowledge

```
PRIORITY MEDIUM:
  [ ] build_provider_context($provider_id, $query) — convenience API
  [ ] Admin Settings tab "Đào tạo kiến thức" per plugin agent
  [ ] Auto-sync: khi plugin data thay đổi → re-index knowledge
  [ ] INTENT-SKELETON.md cho plugin này

PRIORITY LOW:
  [ ] API helper: bizcity_knowledge_sync_plugin_data()
```

### bizcity-executor (MU-Plugin — Execution Engine)

> **⚠️ STATUS: Built, MOSTLY BYPASSED in production.** Executor loaded nhưng `on_execution_detected()` skip built-in tools (return null). Chỉ active cho async workflow_map-registered multi-step. Xem Decision Log 2026-03-06 "PIVOT: Simple Execution First".

```
COMPLETED (infrastructure built):
  [x] 11+ PHP classes — Intent Bridge, Worker, Task Runner, DB, Monitor, Tool Registry, etc.
  [x] DB v1.2.0: 8 tables (tool_registry, traces, tasks, task_deps, verifications, cost_logs, permissions, ab_experiments)
  [x] class-intent-bridge.php: hooks bizcity_intent_execution_detected + bizcity_planner_plan_ready
  [x] on_plan_ready(): enriches context with intent_key, playbook_key, variant, source=planner
  [x] Intent Monitor: ⚡ Executor tab — traces + task counts
  [x] Worker call_user_func execution — REAL execution with retry, backoff, dead-letter
  [x] Queue Producer: WP Cron every minute + inline kick_worker
  [x] FIX: CAST indexes → prefix indexes (191) cho MariaDB
  [x] FIX: string-vs-array TypeError in execution_detected hook
  [x] FIX: succeed_task() produce() ordering — produce BEFORE do_action + try/catch
  [x] FIX: fail_task() retry status 'pending' → 'queued' + process() filter includes 'pending'
  [x] FIX: ajax_execute_step() try/catch around run_one_for_trace (defense-in-depth)
  [x] Queue Producer diagnostic logging (candidate count, skip reason, enqueue result)

DEFERRED (Phase 10.4 — Pre-flight Input Gate): 🔒 CODE BUILT, CHƯA ACTIVE
  Lý do defer: Inline Planner slot-check đủ tốt cho ~20 tools hiện tại.
  Sẽ enable khi scale lên 1000+ tools hoặc cần advanced input validation.
  [x] preflight_validate() — check required + minLength from Tool Registry input_schema
  [x] build_preflight_prompt() — sinh prompt hỏi user
  [x] extract_preflight_answers() — single-field fast path + LLM multi-field extraction
  [x] dispatch() insert preflight check before trace creation
  [x] intercept_executor_reply() handles partial plan resume
  [x] send_preflight_ask() in Messenger
  [x] Transient-based partial plan storage (30 min TTL)
  [-] 🔴 Conversation history scan — DEFERRED
  [-] 🔴 Full JSON Schema draft-7 validation — DEFERRED
  [-] 🔴 Schema Aggregator — DEFERRED
  [-] 🔴 Poll Builder — DEFERRED
  [-] 🟠 DB-based partial plan storage — DEFERRED
  [-] 🟠 Multi-tool input conflict detection — DEFERRED
  [-] 🟠 Smart field auto-fill — DEFERRED
  [-] 🟡 Pre-flight analytics — DEFERRED
  [-] 🟡 Admin UI: Missing Field Dashboard — DEFERRED

DEFERRED (Future Phases):
  [-] Phase P7: Idempotency — retry/skip/deduplicate logic
  [-] Phase P8: Verifier — auto-verify task outputs
  [-] Phase P9: Permission Guard — per-user, per-tool access control
  [-] Phase P10: Cost Monitoring — token + API cost tracking per trace
  [-] JSON Schema draft-7 input validation (currently required fields only)

PRIORITY MEDIUM:
  [ ] Tool Registry CRUD admin tab (standalone)
  [ ] Trace detail view — step-by-step task inspection with input/output
  [ ] Retry failed tasks from admin UI
  [ ] Export traces as JSON for AI debug
  [ ] Scaffold CLI: wp bizcity scaffold-tool {slug} (currently manual copy template)
```

### bizcity-planner (MU-Plugin — Intelligence Planner)

> **⚠️ STATUS: Built, BYPASSED in production.** Planner loaded nhưng chỉ active cho MODE_PLANNING (hiếm). 90%+ traffic dùng Inline Planner (`class-intent-planner.php` trong bizcity-intent) — pure slot-check, không LLM. Xem Decision Log 2026-03-06 "PIVOT: Simple Execution First".

```
COMPLETED (infrastructure built):
  [x] 21 files: 12 classes, views, assets, ARCHITECTURE.md, ROADMAP.md
  [x] 7 DB tables (plans, plan_tasks, playbooks, playbook_variants, tool_scores, ab_experiments, ab_assignments)
  [x] class-planner-core.php: receive → classify → map → playbook → plan → dispatch
  [x] $GLOBALS['bizcity_planner_claimed'] claiming pattern wired
  [x] DB v1.1.0: seed intent_candidates (7 records) + seed playbooks (write_article_v1)
  [x] bizcity-planner.php MU loader created

DEFERRED (sẽ enable khi cần advanced planning):
  Lý do defer: Inline Planner ~20 built-in plans đủ cho current tools.
  [-] LLM-based plan generation (currently rule+DB hybrid)
  [-] Tool Scorer formula with real data (currently placeholder)
  [-] A/B engine with real experiments
  [-] More playbook templates (generate_image, data_analysis, etc.)
  [-] Admin UI: Plan detail view — checklist with task status
  [-] Admin UI: Playbook CRUD
  [-] Admin UI: A/B experiment dashboard
  [-] Integrate with bizcity-automation workflows
```

### bizcity-automation

```
PRIORITY HIGH (Phase 10):
  [ ] Refactor WaicAction blocks → SDK-compliant tool callbacks
  [ ] Đăng ký tools qua bizcity_intent_tools_ready
  [ ] Tool output envelope: {success, complete, message, data}
  [ ] INTENT-SKELETON.md

PRIORITY MEDIUM:
  [ ] Multi-agent workflow: A → B → C data passing (dùng Pipeline mới)
  [ ] Scheduling + triggers tích hợp với Pipeline steps
```

### bizcity-zalo-bot

```
PRIORITY MEDIUM:
  [ ] class-intent-provider.php — đăng ký tools qua bizcity_intent_tools_ready
  [ ] Tool: post_zalo_message, send_zalo_template, get_oa_followers
  [ ] Tool output envelope chuẩn
  [ ] INTENT-SKELETON.md
  [ ] bizcity_mode_execution_patterns filter — Zalo-related keywords
```

### bizcity-facebook-bot

```
PRIORITY MEDIUM:
  [ ] class-intent-provider.php — đăng ký tools
  [ ] Tool: post_facebook, send_facebook_message, get_page_insights
  [ ] INTENT-SKELETON.md
```

### bizcity-brain-level

```
PRIORITY MEDIUM:
  [ ] class-intent-provider.php — đăng ký tools
  [ ] get_profile_context($user_id) — brain level context
  [ ] Tool: get_brain_level, recommend_next_step
  [ ] INTENT-SKELETON.md
```

### bizcity-tarot

```
PRIORITY MEDIUM:
  [ ] data.type = 'tarot_reading', data.id = reading_id — pipeline conventions
  [ ] Pipeline I/O Compatibility Checklist pass
  [ ] Emotional memory integration: reading kết quả → emotional milestone
```

### bizcoach-map

```
PRIORITY MEDIUM:
  [ ] data.id, data.type trong tool output — pipeline conventions
  [ ] Pipeline I/O Compatibility Checklist pass
  [ ] Cross-agent profile sharing với bizcity-tarot
```

### bizcity-video-kling

```
PRIORITY LOW:
  [ ] data.video_url trong tool output chuẩn hóa
  [ ] Pipeline I/O: nhận image_url từ $step[N].data.image_url
  [ ] Pipeline template: article_to_video kết nối write_article → create_video
```

### bizcity-market-plugin

```
PRIORITY MEDIUM (Phase 12):
  [ ] Hiển thị Tool Registry từ bizcity-intent trong marketplace UI
  [ ] SDK Compliance status badge cho mỗi plugin trong catalog
  [ ] Pipeline Template builder UI

PRIORITY LOW:
  [ ] Plugin Validator: auto-check SDK Compliance trước khi publish
```

### bizcity-bot-agent

```
PRIORITY MEDIUM:
  [ ] Log pipeline run events khi Phase 10 implement xong
  [ ] Dashboard: pipeline runs analytics
  [ ] Export pipeline trace JSON cho AI debug
```

### bizcity-tool-woo (NEW)

```
PRIORITY CRITICAL (Phase 10 blocker):
  [ ] FIX: engine phải fire `bizcity_intent_tools_ready` — xem Known Issue #11
  [ ] FIX: đổi tên tool thành namespace: woo_create_product, woo_create_order,
           woo_generate_report, woo_inventory_report, woo_list_orders, woo_find_customer,
           woo_customer_stats, woo_product_stats, woo_inventory_journal, woo_warehouse_receipt
      (tránh conflict với built-in names trong class-intent-tools.php)
  [ ] Hoặc: xóa built-in tools trong class-intent-tools.php (giữ là fallback) khi plugin tương động active

PRIORITY HIGH:
  [ ] WooCommerce dep check: if (!class_exists('WooCommerce')) return error envelope sach
  [ ] INTENT-SKELETON.md — tạo file khai báo I/O schema + pipeline mapping
  [ ] Test: woo_create_product → data.id, data.url → pipeline downstream
  [ ] Test: woo_generate_report chain với write_article (report → content)

PRIORITY MEDIUM:
  [ ] Admin menu tab mỏ cho plugin (Product list, Order list)
  [ ] Profile context: woo loyalty score của user vào build_context()
```

### bizcity-tool-content (NEW)

```
PRIORITY CRITICAL (Phase 10 blocker):
  [ ] FIX: đổi tên tool: content_write_article, content_schedule_post
      Hoặc xóa built-in write_article trong class-intent-tools.php khi plugin active
  [ ] fire `bizcity_intent_tools_ready` cần được implement ở engine

PRIORITY HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Test: content_write_article → data.content, data.url, data.image_url
  [ ] Pipeline test: generate_image → data.image_url → content_write_article

PRIORITY MEDIUM:
  [ ] Lên lịch post: tích hợp với Editorial Calendar admin view
  [ ] Tone/style options: formal, casual, storytelling
```

### bizcity-tool-facebook (NEW)

```
PRIORITY CRITICAL (Phase 10 blocker):
  [ ] FIX: đổi tên tool: fb_post_facebook
      Hoặc xóa built-in post_facebook trong class-intent-tools.php khi plugin active

PRIORITY HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Test pipeline: write_article → fb_post_facebook (content + url + image_url)

PRIORITY MEDIUM:
  [ ] Tool: fb_get_page_insights — stats lượt like/reach công kỳ feedback
  [ ] Multi-page support: chọn page target từ slots
```

### bizcity-tool-reminder (NEW)

```
PRIORITY CRITICAL (Phase 10 blocker):
  [ ] FIX: đổi tên tool: reminder_set, reminder_list
      Hoặc xóa built-in set_reminder trong class-intent-tools.php khi plugin active

PRIORITY HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Verify biztask CPT exists (cần bizcity-admin-hook/flows/bizgpt_task.php loaded)
  [ ] Test: reminder_set → data.id (biztask post ID), data.title, data.meta.due_date

PRIORITY MEDIUM:
  [ ] Integration: biết cần nhắc lún nào → WP Cron auto-notify qua Telegram/Zalo
  [ ] get_profile_context: nạn nhẬn sắp tới của user inject vào system prompt
```

### bizcity-tool-image (NEW)

```
PRIORITY CRITICAL (Phase 10 blocker):
  [ ] FIX: đổi tên tool: image_generate, image_generate_and_save
      (không conflict với built-ins vì engine chưa có built-in generate_image)
  [ ] fire `bizcity_intent_tools_ready` cần được implement ở engine — Known Issue #11

PRIORITY HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Test: image_generate → data.image_url → consumed by write_article, post_facebook, create_product
  [ ] Pipeline I/O: đây là step đầu nhất trong pipeline tạo content — verify $step[0].data.image_url

PRIORITY MEDIUM:
  [ ] Fallback model: gpt-image-1 → dall-e-3 khi quota hết
  [ ] Style presets: realistic/anime/watercolor (inject vào prompt)
  [ ] Upscale: gọi lại với size=hd sau khi draft
```

---

## 6. Known Issues & Debt

> Bugs và technical debt đã biết, chưa fix.

| # | Plugin | Issue | Severity | Phase | Ghi chú |
|---|--------|-------|----------|-------|---------|
| 1 | bizcity-intent | Planner chỉ single-step, chưa có run_pipeline action | ~~High~~ Medium | Phase 10 | ⚠️ PARTIAL — bizcity-planner + bizcity-executor giờ handle multi-step; intent engine Pipeline::run() chưa implement |
| 2 | bizcity-intent | open_loops field tồn tại nhưng chưa được dùng | Medium | Phase 10 | Sẽ dùng cho pipeline checkpoint |
| 3 | bizcity-knowledge | build_provider_context() chưa có convenience method | Medium | Phase 7 | Provider tự gọi thủ công |
| 4 | bizcity-knowledge | Layer 1.7 Relationship Context chưa implement | ~~High~~ | Phase 4.5 | ✅ RESOLVED — `class-companion-context.php` Pri 97 đã tạo |
| 5 | bizcity-intent | Companion Mode chưa có trigger detection | High | Phase 4.5 | ⚠️ PARTIAL — Matrix done; Mode Classifier chưa thêm mode 7 |
| 6 | bizcity-knowledge | User Memory class đã bị xóa (class-user-memory.php) | ~~Medium~~ | Phase 3 | ✅ CLOSED — File tồn tại, required tại bootstrap.php:38 |
| 7 | all plugins | SDK registration hooks chưa được implement ngoài bizcity-intent | High | Phase 10 | bizcity_intent_tools_ready hook chưa có takers |
| 8 | bizcity-intent | build_rolling_summary() chưa implement | Medium | Phase 8.5 | Webchat sessions thiếu summary |
| 9 | bizcity-tarot | ai_reply column mới thêm — cần verify migration chạy đúng trên production | Medium | Phase 9 | ALTER TABLE có thể fail nếu đã tồn tại |
| 10 | bizcity-automation | WaicAction blocks không tuân theo SDK tool output envelope | Medium | Phase 10 | Cần refactor trước khi pipeline integrate |
| **11** | **bizcity-intent + all bizcity-tool-*** | **`bizcity_intent_tools_ready` hook chưa bị fire từ engine** — new tool plugins chưa active | **Critical** | **Phase 10** | Root cause: hook chỉ được design trong ARCHITECTURE.md nhưng chưa implement trong `class-intent-tools.php`. Hoặc cần fire từ `class-intent-engine.php::boot()` sau khi providers registered |
| **12** | **bizcity-intent** | **Tool name conflict: built-ins trong `class-intent-tools::init_builtin()` claim `write_article`, `create_product`, `post_facebook`, `set_reminder` trước** — `has()` guard block new plugins | **High** | **Phase 10** | Built-ins dùng first-wins policy. Giải pháp: (A) namespace tools mới (woo_create_product), hoặc (B) built-ins kiểm tra plugin active rồi skip, hoặc (C) thêm priority field vào Tool Registry |
| **13** | **bizcity-intent + bizcity-admin-hook** | **Goal map vs Tool Registry dịch: goal_map last-wins (plugins win) nhưng tool callback first-wins (built-in wins)** | **High** | **Phase 10** | Kết quả: Provider context của bizcity-tool-content thắng cho goal `write_article` nhưng callback vẫn chạy `builtin_write_article`. Cần thống nhất resolution policy |
| ~~**14**~~ | ~~**bizcity-intent**~~ | ~~**Forecast goal sticky bug** — active `daily_outlook`/`astro_forecast` WAITING_USER làm bot tiếp tục hỏi menu khi user chuyển chủ đề~~ | ~~High~~ | Phase 4.5 | ✅ **RESOLVED** — Step 2.2 Forecast Goal Abandon logic trong `class-intent-engine.php` |
| ~~**15**~~ | ~~**bizcity-intent**~~ | ~~**`bizcity_chat_after_response` không được fire** từ `class-intent-stream.php` → CI hook không chạy~~ | ~~High~~ | Phase 4.5 | ✅ **RESOLVED** — `do_action` được thêm vào cả SSE và batch path |
| **16** | **bizcity-planner** | ~~**Planner AI logic chưa implement thực**~~ | ~~High~~ Medium | Phase 10.2 | ✅ **PARTIALLY RESOLVED** — `classify()` rule-based+DB hybrid đã implement. `make_plan()` full pipeline (cache→classify→map→dispatch). Chưa có LLM-based planning. |
| **17** | **bizcity-planner** | ~~**Playbooks table trống**~~ | ~~Medium~~ | Phase 10.2 | ✅ **RESOLVED** — `seed_playbooks()` trong DB v1.1.0 tạo `write_article_v1` playbook (3-task template). `seed_intent_candidates()` tạo 7 mappings cho 5 intents. |
| **18** | **bizcity-executor** | ~~**Worker chưa thực sự chạy queue**~~ | ~~High~~ | Phase 10.2 | ✅ **RESOLVED** — Audit xác nhận Worker.process() có real `call_user_func($tool['callback'])` execution, WP Cron `bizcity_every_minute` kicks worker inline. NOT stub. |
| **19** | **bizcity-executor** | **`load_from_db()` never called at boot** — Tools persisted in DB won't have callbacks restored. Callbacks từ `call_user_func` không serialize được. Chỉ tools registered trong current request (via `bizcity_executor_register_tools` action) mới có callbacks. | Low | Phase 10.2 | By design cho non-class callbacks. Class-based callbacks sẽ broken nếu dùng `load_from_db()`. |
| **20** | **bizcity-executor** | **JSON Schema validation incomplete** — `validate_input()` chỉ check required fields. Full JSON Schema draft-7 validation là TODO. `validate_output()` no-op. | Medium | Phase 10.2 | Acceptable cho Phase hiện tại. Draft-7 validator cho Phase P7. |
| **21** | **bizcity-executor** | **Single-process WP Cron worker** — Worker chạy inline trong cron tick, không concurrent. Tool callback >30s sẽ timeout. | Medium | Phase 10.2 | Acceptable cho workload hiện tại (1 plugin, 3 tools). Cần async mechanism khi scale. |
| **23** | **bizcity-executor** | **Pre-flight chỉ check required + minLength** — Chưa validate full JSON Schema draft-7 (type, enum, pattern, min/max, oneOf). Chưa scan conversation history trước khi hỏi. Chưa poll UX (chỉ plain text ask). | High | Phase 10.4 | Xem Phase 10.4 backlog: cần schema aggregator, conversation scan, poll builder, 1000+ tool scale |
| **24** | **bizcity-executor** | **Partial plan transient TTL=30 phút** — Nếu user trả lời sau 30 phút → plan mất, phải hỏi lại từ đầu. Cần persist vào DB cho long-running conversations. | Medium | Phase 10.4 | Chuyển sang DB storage khi stable |
| **22** | **bizcity-intent** | **Pipeline memory results dead code** — Pipeline `process()` returns `memory` array nhưng engine KHÔNG persist nó. EmotionPipeline + ReflectionPipeline trả `memory` items nhưng bị bỏ qua. | Medium | Phase 10 | Cần hook vào `BizCity_User_Memory` hoặc remove memory field từ pipeline response. |
| ~~**25**~~ | ~~**bizcity-intent**~~ | ~~**Knowledge Router dead code** — `class-knowledge-router.php` (612 lines, 2 classes, 1 hook) không plugin nào gọi `register_provider()` nữa. Gemini/ChatGPT đã chuyển sang execution tools.~~ | ~~Low~~ | Phase 15 | ✅ **RESOLVED** — Auto-registration hook DEPRECATED (v4.4). Classes kept for reference. Built-in Knowledge Pipeline handles all knowledge-mode messages. |
| **25** | **bizcity-knowledge** | **`ajax_stream()` KHÔNG apply `bizcity_chat_pre_ai_response` filter** — Intent Engine hoàn toàn bị bypass khi SSE streaming. Chỉ `ajax_send()` có filter. Kết quả: khi SSE works, Intent Engine không xử lý → messages đi thẳng lên LLM không qua goal/slot/tool pipeline. | **Critical** | Phase 10 | Cần thêm `bizcity_chat_pre_ai_response` filter vào `ajax_stream()` trước `prepare_llm_call()`, giống `ajax_send()` |

---

## 7. Decision Log

> Những quyết định kỹ thuật quan trọng và lý do đằng sau,
> để tránh "tại sao lại làm vậy?" sau này.

| Date | Decision | Lý do | Ảnh hưởng |
|------|----------|-------|-----------|
| 2026-03-03 | Layer 1.7 Relationship Context tại Pri 97 (giữa BizCoach 95 và UserMemory 99) | Bond/emotion tone phải gần user message hơn profile nhưng thấp hơn explicit memory | `class-companion-context.php` inject tại pri 97 |
| 2026-03-03 | Pipeline state lưu vào `open_loops` field hiện có | Tránh thêm DB column mới, `open_loops` đã tồn tại nhưng chưa dùng | `bizcity_intent_conversations.open_loops` |
| 2026-03-03 | Tool output envelope `{success, complete, message, data, missing_fields}` — unchanged | Không phá vỡ hệ thống existing, Pipeline Executor dùng cùng contract | Tất cả plugins cũ không cần sửa signature |
| 2026-03-03 | `data.type` + `data.id` là mandatory trong pipeline mode | Pipeline Resolver cần biết loại output để routing đúng | Validate ở Pipeline Resolver, warn nếu thiếu |
| 2026-03-03 | PHP < 7.4 constraint cho toàn hệ thống | Server production đang chạy PHP 7.0+ | Không dùng arrow functions, named arguments, match{} |
| 2026-02-xx | WordPress Multisite với global tables trên `$wpdb->gwpdb` | Chia sẻ characters, knowledge giữa tất cả blogs | `BizCity_WPDB_Router` trong `wp-content/db.php` |
| 2026-02-xx | `owner_type + owner_id` trên `bizcity_characters` | Tách biệt Provider Character vs Legacy Character, tránh nhầm character_id | 1000+ plugins có thể có character riêng không conflict |
| 2026-02-xx | `wcs_` prefix cho webchat session IDs | Phân biệt với `adminchat_*` và intent conversation IDs | Gateway routing dựa vào prefix |
| 2026-03-03 | Namespace tool names cho bizcity-tool-* plugins (woo\_create\_product vs create\_product) | Tránh first-wins block trong Tool Registry. Built-ins trong `class-intent-tools.php` giữ là generic fallback, plugins có domain-specific name đảm bảo được đăng ký. | Tất cả goal_patterns trong provider phải map đúnh tên tool mới; LLM tool list sẽ thấy cả hai (legacy + domain) — LLM tự chọn domain version khi có |
| 2026-03-03 | fire `bizcity_intent_tools_ready` từ `class-intent-tools::init_builtin()` sau khi register built-ins | Đảm bảo external plugins có cơ hội ghi đè hoặc bổ sung vào registry sau built-ins | Hook phải được fire DƯỚI `init` priority (sau priority 20 của init_builtin) |
| 2026-03-03 | Step 2.2 Forecast Goal Abandon ở tầng Engine (class-intent-engine.php) thay vì Router | Router không có access vào conversation_mgr để gọi `complete()`. Engine tầng đúng vì đây là lifecycle transition, không phải routing decision. | `class-intent-engine.php` Step 2.2 insert sau mode_log block, trước Step 2.3 Memory Save |
| 2026-03-03 | Hybrid C: inject Gemini knowledge reply vào system prompt (thay vì chat message riêng) | Nếu inject như user/assistant message riêng → LLM xử lý như đoạn hội thoại, mất context role. Inject vào system prompt → Team Leader thấy như "expert briefing" và tự nhiên dùng "Tuy nhiên, từ những gì mình được biết..." | `build_llm_messages()` trong class-intent-stream.php |
| 2026-03-04 | Intensity detection ở Step 2.1.5 (Engine) thay vì chỉ intent_stream | Engine là điểm đầu tiên nhận message sau mode_classify → có thể log ngay cho debug console. intent_stream chỉ dùng cho pre-compute before filters. | Intensity log trong `class-intent-engine.php`, intensity pre-compute vẫn còn trong `class-intent-stream.php` cho backward compat |
| 2026-03-04 | 6 Routing Branches: mode × intensity → branch | Đơn giản hóa routing decision: execution/knowledge/reflection pass-through, emotion phụ thuộc intensity threshold (low <3, high 3-4, critical 5) | `routing_branch` trong `$result['meta']` cho downstream pipeline/conditionals |
| 2026-03-04 | Remove full_prompt từ JSON export của Router Log | `full_prompt` có thể 20-50KB per log → export 50 logs = 1-2.5MB JSON. Strip trước export giảm còn ~5% | `_bizcRouterRawLogs` map xóa `full_prompt`, `prompt_head`, `prompt_tail` |
| 2026-03-04 | Emotional Smoothing ALWAYS apply cho tool prompts (không cần intensity >= 2) | Conversation có thể có emotional context từ turns trước mà message hiện tại intensity = 1 (calm). Ví dụ: user nói "hôm nay hơi nặng" → AI đồng cảm → user nói "ko đóng gói được" (intensity=1) → tool hỏi "Tên SP?" gây gãy mạch. Chi phí ~100-200ms chấp nhận được. | `smooth_tool_ask_prompt()` gọi cho MỌI missing_fields prompt |
| 2026-03-04 | Emotional Smoothing phải lấy WEBCHAT session messages, KHÔNG dùng intent conversation turns | Intent conversation chỉ chứa tool execution state (slots, tool calls), KHÔNG có actual chat dialogue (user nói "nặng nề", AI đồng cảm). `$conversation['turns']` thiếu emotional context → smooth prompt không biết đang có mạch cảm xúc. | `smooth_tool_ask_prompt()` gọi `BizCity_WebChat_Database::get_recent_messages_for_context()` thay vì `$conversation['turns']` |
| 2026-03-04 | Branch-specific texture rules với priority cao hơn mode-based rules | Branches (`emotion_low`, `emotion_high`, `emotion_critical`, `reflection`) cần override texture chung. Đặt `$branch_rules` đầu tiên trong `$parts` array để LLM thấy rõ nhất. | `branch_specific_rules()` trả về chuỗi instruction cụ thể; execution/knowledge pass-through (dùng mode rules) |
| 2026-03-04 | emotion_critical PHẢI có Safety Guard với hotline cụ thể | Khi intensity=5 + mode=emotion, user có thể đang khủng hoảng. Inject hotline 1800 599 911 (miễn phí 24/7) + instruction: không bắt đầu với giải pháp, thừa nhận sâu, hỏi user có ổn không. | `branch_specific_rules()` case `emotion_critical` — chỉ trigger khi mode=emotion + intensity>=5 |
| 2026-03-04 | Execution Logger tách riêng khỏi Router Log | Router Log (routing decisions + prompt building) và Execution Log (pipeline + tool execution) có concerns khác nhau. Tách ra giúp debug dễ hơn, không bị lẫn lộn. Transient key riêng: `bizcity_exec_log_{session}` vs `bizcity_router_log_{session}` | `class-execution-logger.php` trong bizcity-intent/includes/, dashboard có 2 tabs |
| 2026-03-04 | Execution Logger dùng invoke_id để pair tool_invoke với tool_result | Khi có nhiều tool calls song song hoặc nested, cần ID để match invoke/result. `invoke_id` format: `inv_` + 6 char random. Log giữ lại cả hai cho traceability. | `tool_invoke()` trả về invoke_id, `tool_result()` nhận invoke_id |
| 2026-03-11 | bizcity-executor + bizcity-planner là MU-plugins riêng (không nằm trong bizcity-intent) | Separation of concerns: Intent = classify + dispatch, Planner = intelligence + plan, Executor = trace + execute. Boot order: intent(5) → executor(10) → planner(15). | 3 MU-plugins phối hợp qua WordPress hooks, không import trực tiếp |
| 2026-03-11 | Planner claiming pattern dùng `$GLOBALS['bizcity_planner_claimed']` | Giống executor pattern (`$GLOBALS['bizcity_executor_claimed']`). Planning Pipeline kiểm tra global sau fire `bizcity_intent_plan_request`. Nếu claimed → return plan ack; nếu không → fallback AI compose. | Không dependency injection, nhẹ, backward-compatible khi planner chưa installed |
| 2026-03-11 | Planning Pipeline 2-phase dispatch: hook first → AI compose fallback | Phase 1 fire `bizcity_intent_plan_request` cho planner plugin. Phase 2 only if planner NOT claimed → AI compose with planning system prompt. Đảm bảo planning mode luôn có response dù planner installed hay không. | `class-mode-pipeline.php::BizCity_Planning_Pipeline::process()` |
| 2026-03-11 | Executor hooks cả `bizcity_intent_execution_detected` + `bizcity_planner_plan_ready` | Hai entry points: (A) trực tiếp từ intent execution mode, (B) gián tiếp qua planner. `on_plan_ready()` enriches context với planner metadata (intent_key, playbook_key, variant) trước dispatch. | `class-intent-bridge.php` constructor hooks cả hai actions |
| 2026-03-11 | Prompt logging ở tầng bootstrap hook (priority 99) không phải từng class riêng lẻ | Hook `bizcity_intent_processed` là single point of truth — mọi pipeline đều fire qua đây. Tránh scatter logging across 6+ classes. DB-based (không transient) → query/filter/stats. | `bootstrap.php` closure at `add_action('bizcity_intent_processed', ..., 99)` |
| 2026-03-11 | Prompt log 26 columns thay vì normalized tables | Denormalized cho query performance. Mỗi prompt = 1 row with all context inline. JSON columns cho dynamic data (slots, context_layers, tool_calls). Tradeoff: redundancy vs simplicity. | `bizcity_intent_prompt_logs` table — flat structure, 6 indexes |
| 2026-03-11 | 3 admin tabs mới đặt trong Intent Monitor (không tạo admin page riêng) | Tập trung observability 1 nơi. Intent Monitor đã có tab system → extend dễ. Executor + Planner tabs trỏ về cùng data nhưng context là "nhìn từ góc Intent Engine". | `class-intent-monitor.php` — 7 tabs total, AJAX-loaded |
| 2026-03-12 | CodingPipeline action=`compose` thay vì `reply` | Coding requests cần AI generate code — không có pre-built reply. Pipeline chỉ enrich system prompt với coding-specific instructions + knowledge context, rồi delegate cho Chat Gateway compose. Temperature=0.3 cho code accuracy. | `class-mode-pipeline.php` — `BizCity_Coding_Pipeline::process()` luôn trả `action=compose` |
| 2026-03-12 | Seed data dùng INSERT IGNORE (intent_candidates) + existence check (playbooks) | Safe re-run: khi DB version bump, seed methods chạy lại nhưng không duplicate. User edits/additions ở DB không bị overwrite. | `class-planner-db.php` v1.1.0 — `seed_intent_candidates()`, `seed_playbooks()` |
| 2026-03-12 | Planner Tool Index reads from executor's `wp_bizcity_tool_registry` — single source of truth | Không duplicate tool data giữa 2 plugins. Executor owns registry, planner chỉ reads. Planner extension fields (`capability_tags`, `intent_tags`, etc.) được upsert bởi executor khi tool register. | `class-tool-index.php` trong bizcity-planner reads executor-owned table |
| 2026-03-12 | Worker chạy inline trong WP Cron (không async HTTP, không background process) | WordPress environment constraint — most hosts block loopback HTTP + disable `pcntl_fork`. Inline execution simple, predictable. Cron fires every minute → worker processes up to 5 jobs per tick. Scale limit: ~5 tools/minute. Acceptable cho Phase 10 workload. | `class-queue-producer.php::kick_worker()` calls `Worker::run(5)` synchronously |
| 2026-03-13 | Auto-fill `message` from raw user input in new_goal handler | Nhiều tool plans dùng `{{slots.message}}` làm primary instruction. User's natural language text (e.g. "Viết bài về du lịch Đà Nẵng") chính là message. Nếu LLM không extract 'message' entity → slot trống → hỏi thừa. | `class-intent-engine.php` Step 4b — `$intent['entities']['message'] = $message` conditional |
| 2026-03-13 | Goal pattern order: specific before generic (viết lại → SEO → dịch → lên lịch → viết bài) | PHP `preg_match` runs patterns in order. "Viết lại bài" chứa "viết bài" → nếu generic pattern ở trước thì "viết lại" match thành `write_article` thay vì `rewrite_article`. First-match-wins. | `class-intent-provider.php` trong bizcity-tool-content — pattern array order |
| 2026-03-13 | Executor hooks bridge to BizCity_Execution_Logger via bootstrap.php closures | Executor fires `do_action()` cho task lifecycle nhưng không có listener log vào execution logger. Working Panel polls `bizcity_poll_execution_log` → cần data. Bridge qua closures trong bootstrap thay vì sửa executor classes — separation of concerns, no circular dependency. | `bootstrap.php` — 5 `add_action()` closures; `class_exists('BizCity_Execution_Logger')` guard |
| 2026-03-13 | Built-in inline handler ưu tiên hơn executor async cho tools đã có sync callback | Executor claim multi-task workflow → ack ⏳ → async WP Cron worker xử lý. User kỳ vọng immediate results (URL + edit link) như legacy flow. Built-in `write_article` synchronous → publish xong ~5s. Async executor chỉ nên claim tools CHƯA CÓ inline handler. | `class-intent-bridge.php::on_execution_detected()` — check `get_tool_source('built_in')` → skip dispatch |
| 2026-03-13 | Slot name mismatch: Provider plan dùng `message`, built-in dùng `topic` — accept cả hai | Provider plan (tool-content) dùng `message` làm primary slot (tự nhiên hơn). Built-in callback expect `topic`. Thay vì mandatory rename một phía → accept alias: `$slots['topic'] ?? $slots['message']` — backward compatible, minimal change. | `class-intent-tools.php::builtin_write_article()` line 642 |
| 2026-03-13 | Logger bridge `plan_snapshot` → `plan_json` + json_decode | `BizCity_Trace_Store::create()` hàm `wp_json_encode()` plan_json trước khi lưu DB. Bridge hook nhận `$row` từ DB → `$row['plan_json']` là JSON string, không phải array. Key cũng sai tên (`plan_snapshot` thay vì `plan_json`). Fix: `json_decode()` + correct key. | `bootstrap.php` — `bizcity_executor_trace_created` hook |
| 2026-03-06 | Pre-flight Input Gate là Phase độc lập (10.4) không gộp vào P3 hay P4 | Pre-flight là cross-cutting concern: cần Tool Registry (P3) + Intent Bridge (P4) + Messenger (P13) + LLM extraction + conversation scan + poll UX. Phức tạp đủ để tách thành phase riêng. Khi scale lên 1000+ tools, phase này quyết định AI có thông minh hỏi đúng thứ cần hỏi hay không. | `class-intent-bridge.php` — `preflight_validate()`, `extract_preflight_answers()`, transient partial plan |
| 2026-03-06 | **PIVOT: Simple Execution First — Bypass Planner/Preflight cho production** | **Lý do**: (1) Inline Planner (`class-intent-planner.php`) đủ tốt cho slot-checking — không cần external planner. (2) bizcity-tool-content chứng minh "Developer-Packaged Pipeline" pattern hoạt động — tool tự đóng gói multi-step logic (T1→T2→T3), không cần executor orchestrate. (3) Pre-flight code built nhưng chưa cần — Inline Planner's slot-check đã hỏi missing fields. (4) **Nhược điểm** chấp nhận: chưa có A/B test plans, chưa có playbook selection, chưa có advanced input validation. Sẽ enable khi scale lên 1000+ tools. | Executor skip built-in tools (`on_execution_detected` → `return null`). Planner only fires on MODE_PLANNING. Preflight captures snapshots only. |
| 2026-03-08 | **Unified Single-Call Classification (v3.8.0)**: Gộp 3 LLM calls thành 1 | 3 calls tuần tự tốn nhiều token + latency. Mode Classifier đã làm gần đủ việc (mode + intent + goal) → Router Tier 1 lặp lại. Tier 2 (entity) có thể inline vào prompt chính. Kết hợp focused top-N schema để giảm prompt size. | `class-mode-classifier.php` — `classify_with_llm()` rewrite; `class-intent-router.php` — 3 new methods |
| 2026-03-08 | **Regex pre-match = bias hint, không bypass LLM** | Trước: regex match → bypass LLM → entities=[] → Planner hỏi lại. Vẫn cần LLM để extract entities. Regex chỉ nên gợi ý (★ marker) để LLM ưu tiên goal, không thay thế LLM. Trade-off: mất 0ms fast-path nhưng gain inline entity extraction. | `class-mode-classifier.php` — regex block changed from `return` to `$regex_likely_goal` |
| 2026-03-08 | **Configurable top_n_tools với Admin UI** | Token budget phụ thuộc số tools. Admin cần kiểm soát trade-off accuracy/speed. Default 10 là cân bằng (8-12 tools = ~1000-1500 tok). UI trong Stats tab là hiện có, không cần tab mới. | `class-tool-control-panel.php` + `page-tool-control-panel.php` — Settings UI + AJAX handler |
| 2026-03-14 | @mention provider_hint: Engine Step 2.0 trước mode classification | User chỉ định agent → bypass mode classify (force execution). Inject trước Step 2.1 (provider goal pre-check) vì @mention là explicit user intent, mạnh hơn pattern matching. | `class-intent-engine.php` Step 2.0 — `$provider_hint` → force MODE_EXECUTION + conf 0.95 |
| 2026-03-14 | Router Step 0: single-goal provider skip LLM entirely (0 cost) | Khi provider chỉ có 1 goal + user đã @mention → không cần LLM phân loại, trả new_goal trực tiếp. Tiết kiệm ~100-300ms + token cost. Multi-goal provider vẫn cần LLM nhưng có bias prompt. | `class-intent-router.php` Step 0 — `count($provider_goals) === 1` → return immediately |
| 2026-03-14 | @mention autocomplete source từ Touch Bar #bizc-tb-data JSON | Tái sử dụng data đã có (agent slug, name, icon từ `BizCity_Market_Catalog`). Không cần AJAX call mới. Dropdown filter client-side, instant. | `class-admin-dashboard.php` — `_getMentionAgents()` reads `#bizc-tb-data` |
| 2026-03-14 | Knowledge plugins dùng `bizcity_intent_register_plugin()` + fallback class-based | Nếu `bizcity_intent_register_plugin()` chưa available (plugin load order), fallback về require class-intent-provider.php truyền thống. Forward-compatible. | `bizcity-chatgpt-knowledge.php`, `bizcity-gemini-knowledge.php` — `function_exists()` guard |
| 2026-03-15 | **Dual-Logic Routing Architecture (v3.9.0)**: Logic 1 (Registry LLM full-scan) + Logic 2 (@Tag Direct narrow-scope) | Pipeline trace chứng minh Logic 1 accuracy thấp với 36+ tools: "dự đoán bản đồ sao" → mode=knowledge (sai), rồi intent=tarot_reading (sai). Logic 2 (@Tag) thu hẹp tool list từ 36 → 1-6, tăng accuracy ~95%. Hai logic bổ sung nhau: Logic 2 cho explicit user intent, Logic 1 cho automatic classification. | ARCHITECTURE.md §10f — 12 sub-sections; `class-plugin-gathering.php` filter @pri 2; `class-intent-engine.php` Step 2.0 provider_hint |
| 2026-03-15 | **Remove floating indicator, keep only context header** | Floating indicator thừa khi user đã chọn plugin qua @mention (context header đủ). Giảm visual noise. Badge chuyển sang hiển thị trực tiếp trên typing indicator + bot bubble. | `class-admin-dashboard.php` — xóa `bizc-context-floating-indicator` div + 3 JS functions |
| 2026-03-15 | **Plugin badge hiển thị ngay lúc loading** (3 touchpoints) | User cần biết response đang từ plugin nào TRƯỚC KHI bot trả lời xong. Dùng `_sendPluginSlug`/`_ssePluginSlug`/`_ajaxPluginSlug` JS vars để persist slug qua lifecycle (vì `clearMention()` xóa context sớm). | `class-admin-dashboard.php` — typing indicator, SSE first-chunk bubble, AJAX fallback |
| 2026-03-20 | **Off-topic/greeting escape dùng regex (không gọi LLM)** | Greeting + meta-question patterns ngắn, xác định → regex đủ chính xác, 0 cost. Gọi LLM cho "hi" quá tốn. Regex false-positive thấp vì chỉ match khi WAITING_USER + không phải action-verb. | `class-mode-classifier.php` — 2 regex patterns, `$skip_provide_input = true` |
| 2026-03-20 | **Type guard chỉ reject number/choice, không reject text** | `text` type fallback vẫn hợp lý khi LLM unavailable — user gõ text tự do cho topic/content. `number` + `choice` cần validation rõ ràng vì format sai = lỗi tool. Trade-off: strict reject → user phải nhập lại (UX tốt hơn lỗi tool). | `class-intent-router.php` — `fill_waiting_field_entities()` fallback path |
| 2026-03-22 | **Twin Core Phase 0 — Focus Gate context pollution fix** | AI gợi ý chiêm tinh khi user hỏi dinh dưỡng: root cause là MỌI layer context luôn inject bất kể mode. Focus Gate `should_inject(layer)` check mode → chặn astro/transit/coaching khi không cần. 6 focus modes: emotion, reflection, knowledge, planning, execution, studio. | `bizcity-twin-core/includes/class-focus-gate.php`, `class-focus-router.php` — gate trên `bizcity_chat_system_prompt` filter pri 1 |
| 2026-03-22 | **4 injection points gây duplicate tool suggestion → xóa toàn bộ** | AI gợi ý "Hỏi ChatGPT" duplicate 2 lần: (1) role block trong `build_llm_messages()`, (2) END REMINDER trong `build_llm_messages()`, (3) END REMINDER trong `build_system_prompt()`, (4) END REMINDER trong `prepare_llm_call()`. SSE streaming dùng path riêng (build_llm_messages — LEGACY), không đi qua build_system_prompt → fix 1 path không đủ. | `class-intent-stream.php` + `class-chat-gateway.php` — xóa tool label/desc/guide, thay bằng "KHÔNG gợi ý công cụ" + follow-up questions |
| 2026-03-22 | **Twin Suggest: follow-up questions thay tool suggestions** | Thay vì gợi ý tool, AI đặt 1-2 câu hỏi gợi mở dựa trên: (1) historical memory (episodic habits, rolling memory, user goals/pain), (2) current session (intent goal, open slots, session title). Mode-aware tonality (emotion→empathetic, planning→action-oriented). | `bizcity-twin-core/includes/class-twin-suggest.php` — inject vào cả `build_system_prompt()` section 7.6 + `build_llm_messages()` after end_reminder |
| 2026-03-22 | **suggest_tool SSE done payload disabled** | Frontend render tool activation card từ `done.suggest_tool` — gây thêm 1 lớp tool suggestion nữa ngoài text response. Disable cả `suggest_tool` + `suggest_tools` trong SSE done event. | `class-intent-stream.php` — commented out L929-936 |
| 2026-03-23 | **Role confusion fix: 3 root causes, 4 fix areas** | AI tự xưng "Chu đây!" do: (1) Memory extraction không anchor ngôi thứ ba → `[identity] Tên Chu` → AI đọc "Tôi là Chu", (2) "GHI ĐÈ MỌI HƯỚNG DẪN" override role block, (3) Bond 9-10 "hoàn toàn tự nhiên" dissolves role boundary. Fix: extraction prompt role-anchoring, memory header "⛔ RANH GIỚI VAI TRÒ", bond guardrail, 3 role blocks hardened. | `class-user-memory.php`, `class-companion-context.php`, `class-intent-stream.php`, `class-chat-gateway.php` |
| 2026-03-06 | Single-field fast path: 1 missing → reply IS value (không gọi LLM) | 90% case chỉ thiếu 1 field (e.g. topic). Gọi LLM cho 1 field tốn ~200ms + token unnecessarily. User gần như luôn trả lời đúng field. | `extract_preflight_answers()` — `count($missing) === 1 → assign directly` |
| 2026-03-06 | produce() BEFORE do_action() trong succeed_task() | `do_action()` fires 3 hooks (Logger p10, Artifact p30, Messenger p50). Nếu BẤT KỲ hook nào throw → produce() không chạy → next task never enqueued → trace stuck. Đảo thứ tự = guarantee task chain progression dù hooks crash. | `class-worker.php::succeed_task()` — produce first, then try/catch do_action |
| 2026-03-06 | Retry status = 'queued' thay vì 'pending' | Task queue semantics: 'pending' = chưa bao giờ vào queue, 'queued' = đã vào queue chờ lease. fail_task() retry = đưa LẠI vào queue → phải là 'queued'. 'pending' bị process() exclude (đúng vì pending = chưa enqueue). | `class-worker.php::fail_task()` — `$this->trace_store->update_task_status(..., 'queued')` |
| 2026-03-11 | **Deprecate Knowledge Router (4-scenario S0/S1/S2/S3)** — không plugin nào gọi `register_provider()` | Gemini/ChatGPT đã chuyển sang execution tools (`bizcity_intent_register_providers`). Knowledge Router 612 lines dead code. Giữ classes cho reference nhưng xóa auto-registration hook. | `class-knowledge-router.php` — hook pri 999 removed. Built-in Knowledge Pipeline là duy nhất cho mode=knowledge |
| 2026-03-11 | **MODE_AMBIGUOUS thay thế knowledge fallback** cho tin nhắn mơ hồ ("hi", "hmm", "ok") | Trước: confidence < 0.6 → `MODE_KNOWLEDGE` → RAG + agent context loading (tốn token/time). Ambiguous mode lightweight: no RAG, max_tokens 300, temperature 0.7, memory null. Tool hints từ Tool Index. | `class-mode-classifier.php` — 3 fallback points → ambiguous. `class-mode-pipeline.php` — `BizCity_Ambiguous_Pipeline` registered in constructor |
| 2026-03-16 | **Phase 19: Unified REST API `bizcity/v1`** — hợp nhất 3 namespace + 20 AJAX thành 48 REST endpoints | Single namespace cho bizcity-app React frontend. JWT auth (transient-backed). Delegate to existing service layer (no logic duplication). Old namespaces preserved for backward compat. | `class-unified-rest-api.php` — 48 routes, `lib/api.ts` — frontend fetch lib, `API-REFACTORING-PLAN.md` — master plan |
| 2026-03-13 | `parse_confirm_correction()` — LLM-based slot correction tại confirm step (v4.6) | User respond confirm card bằng "sửa chủ đề thành X" → trước đây re-show confirm y hệt (không parse). Giờ dùng LLM (temp=0.05, ~200tok) parse correction: build slot schema + current values → extract slot mới → update_slots → re-show updated confirm card. | `class-intent-engine.php` — new method `parse_confirm_correction()` + call_tool confirm handler |
| 2026-03-13 | Acknowledge filled slots trước khi hỏi slot tiếp (v4.6) | `compose_natural_ask_prompt()` trước chỉ hỏi slot tiếp. Giờ bổ sung: (1) filled_summary context, (2) LLM instruction "CÂU ĐẦU xác nhận thông tin vừa nhận → CÂU SAU hỏi tiếp". Kết quả: "OK chủ đề là X nhé! 📝 Gửi thêm ảnh cho mình nhé". | `class-intent-engine.php` — `compose_natural_ask_prompt()` prompt rewrite + filled_summary context |
| 2026-03-13 | LLM Slot Bridge rule 4: hỗ trợ correction cho filled slots (v4.6) | `extract_provide_input_with_llm()` trước chỉ extract cho MISSING slots. Thêm rule: "Nếu user muốn SỬA slot đã FILLED → extract giá trị MỚI để override". Cho phép user nói "thay đổi chủ đề thành Y" giữa lúc đang hỏi image_url. | `class-intent-router.php` — LLM prompt rule 4 addition |
| 2026-03-13 | `message_norm` VARCHAR(500) overflow fix — `mb_substr()` → `mb_strcut()` | `mb_substr($str, 0, 500)` cắt 500 characters (tối đa 2000 bytes utf8mb4). MySQL VARCHAR(500) utf8 giới hạn 500 bytes. Vietnamese text 3 bytes/char → overflow. `mb_strcut()` cắt đúng 500 bytes, tôn trọng boundary UTF-8. | `class-intent-classify-cache.php` — `make_cache_key()` |

---

## Cách dùng file này

### Khi bắt đầu session làm việc mới:
1. Đọc **Section 3** (Roadmap Phase Status) — xem ưu tiên hiện tại
2. Đọc **Section 5** (Per-Plugin Backlog) của plugin sẽ làm
3. Đọc **Section 6** (Known Issues) xem có blocker nào không

### Khi hoàn thành một task:
1. Thêm entry vào **Section 4** (Daily Change Log): `YYYY-MM-DD | Plugin | Mô tả | Phase`
2. Cập nhật checkbox trong **Section 5** (Per-Plugin Backlog): `[x]`
3. Cập nhật **Section 3** nếu phase % thay đổi đáng kể
4. Thêm vào **Section 6** nếu phát hiện issue mới
5. Thêm vào **Section 7** nếu có quyết định kỹ thuật quan trọng

### Khi thiết kế tính năng mới:
1. Cập nhật [ARCHITECTURE.md](ARCHITECTURE.md) trước (design first)
2. Thêm vào **Section 5** backlog
3. Thêm vào **Section 3** roadmap nếu phase mới

---

*Last updated: 2026-03-22 (Twin Core Phase 0 — Context Cleanup & Follow-up v5.0) | Maintained by: GitHub Copilot + Team*
