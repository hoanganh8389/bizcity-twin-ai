# API Refactoring Plan — Unified REST for bizcity-app

> **Ngày:** 2026-03-13  
> **Mục tiêu:** Hợp nhất toàn bộ AJAX endpoints về REST API dưới 1 namespace duy nhất, lấy **bizcity-intent** làm lõi, phục vụ React frontend (bizcity-app).

---

## 1. Hiện trạng — Vấn đề cần giải quyết

### 1.1 — Phân mảnh 3 namespace REST

| Namespace | Plugin | Endpoints | Vai trò |
|-----------|--------|-----------|---------|
| `bizcity-intent/v1` | bizcity-intent | 9 GET | Tasks & Sessions (read-only) |
| `bizcity-chat/v1` | bizcity-knowledge | 8 (send, history, auth, emotion, bond) | Chat I/O + Auth |
| `bizcity-webchat/v1` | bizcity-bot-webchat | 5 (plugin-suggestions, tools, pull) | UI helpers |

**Vấn đề:** Frontend phải biết 3 base URL khác nhau. Auth pattern không nhất quán (API key vs nonce vs public).

### 1.2 — 20 AJAX endpoints trong class-admin-dashboard.php

Hiện tại admin dashboard dùng **20 AJAX calls** qua `admin-ajax.php`. Toàn bộ logic này cần chuyển sang REST để bizcity-app (React/Next.js) gọi được mà không cần nonce WordPress.

### 1.3 — Duplicate & Legacy

- `bizcity_chat_stream` đăng ký ở CẢ HAI plugin (Intent priority 10, Knowledge priority 20)
- Chat Gateway có 17 AJAX registrations (3 handlers × nhiều alias backward-compat)
- Session management: 3 cách gọi khác nhau (REST, AJAX từ dashboard, AJAX từ webchat bootstrap)

---

## 2. Phương án — Unified Namespace `bizcity/v1`

### 2.1 — Quyết định kiến trúc

```
┌─────────────────────────────────────────────────────────────────┐
│  PHƯƠNG ÁN: MỘT NAMESPACE DUY NHẤT                              │
│                                                                   │
│  Namespace: bizcity/v1                                           │
│  File:      bizcity-intent/includes/class-unified-rest-api.php   │
│  Plugin:    bizcity-intent (LÕI)                                 │
│                                                                   │
│  Lý do:                                                          │
│  • Intent là Team Leader / Orchestrator — mọi thứ đi qua nó    │
│  • 1 namespace = 1 base URL cho React app                       │
│  • Auth nhất quán: JWT token + API key + WP cookie fallback     │
│  • Knowledge chỉ là plugin phụ (gọi nội bộ qua PHP class)      │
│  • bizcity-chat/v1 và bizcity-webchat/v1 sẽ DEPRECATED         │
│                                                                   │
│  Frontend chỉ cần:                                               │
│  const API_BASE = '/wp-json/bizcity/v1'                         │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 — Tại sao không giữ `bizcity-intent/v1`?

- Tên quá dài, chữ "intent" là internal concept — user/frontend không cần biết
- `bizcity/v1` ngắn gọn, sạch, platform-level (không phải plugin-level)
- Mở rộng tự nhiên: `bizcity/v2` sau này

### 2.3 — Giữ lại gì từ hiện tại?

| Giữ nguyên (reuse logic) | Deprecated (giữ backward-compat 3 tháng) |
|---------------------------|-------------------------------------------|
| `class-intent-rest-api.php` — query logic tasks/sessions | `bizcity-chat/v1/*` — redirect → `bizcity/v1/*` |
| `class-chat-rest-api.php` — send/history/auth logic | `bizcity-webchat/v1/*` — redirect → `bizcity/v1/*` |
| `class-plugin-suggestion-api.php` — search tools/plugins | Admin AJAX endpoints — giữ cho admin dashboard cũ |
| `class-intent-stream.php` — SSE streaming | |
| `class-chat-gateway.php` — AI pipeline core | |

---

## 3. Endpoint Map — bizcity/v1

### 3.1 — Auth Group

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| POST | `/auth/login` | `bizcity-chat/v1/auth/login` | Email/phone + password → JWT token |
| POST | `/auth/register` | `bizcity-chat/v1/auth/register` | Đăng ký (phone/email) |
| POST | `/auth/social/{provider}` | **MỚI** | OAuth social login (Google, Facebook) |
| GET | `/auth/me` | **MỚI** | Verify token → user profile |
| POST | `/auth/refresh` | **MỚI** | Refresh JWT token |
| POST | `/auth/logout` | **MỚI** | Invalidate token |

**Auth strategy cho React app:**
- JWT token ưu tiên (stateless, mobile-friendly)
- API key header cho external integrations
- WP cookie fallback cho admin dashboard legacy

### 3.2 — Chat Group (Core messaging)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| POST | `/chat/send` | `bizcity-chat/v1/send` + `bizcity_chat_send` AJAX | Gửi message → AI response (non-streaming) |
| GET | `/chat/stream` | `bizcity_chat_stream` AJAX | SSE streaming response |
| GET | `/chat/history` | `bizcity-chat/v1/history` | Lịch sử chat theo session |
| DELETE | `/chat/history` | `bizcity-chat/v1/history` DELETE | Xóa lịch sử |
| POST | `/chat/upload` | `bizcity_webchat_upload` AJAX | Upload file (audio/image) |
| GET | `/chat/pull` | `bizcity-webchat/v1/pull` + `bizcity_webchat_pull` AJAX | Poll new messages |

### 3.3 — Sessions Group (ChatGPT-style conversations)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/sessions` | `bizcity-intent/v1/sessions` + `bizcity_webchat_sessions` AJAX | List sessions (paginated) |
| POST | `/sessions` | `bizcity_webchat_session_create` AJAX | Tạo session mới (lazy) |
| GET | `/sessions/stats` | `bizcity-intent/v1/sessions/stats` | Thống kê sessions |
| GET | `/sessions/{id}` | `bizcity-intent/v1/sessions/{id}` | Chi tiết 1 session |
| PUT | `/sessions/{id}` | **MỚI** (rename/update) | Đổi tên session |
| DELETE | `/sessions/{id}` | **MỚI** | Xóa/archive session |
| GET | `/sessions/{id}/messages` | `bizcity-intent/v1/sessions/{id}/messages` + `bizcity_webchat_session_messages` AJAX | Messages của session |
| POST | `/sessions/{id}/messages` | (qua `/chat/send` với session_id) | Alias → `/chat/send` |
| GET | `/sessions/by-sid/{sid}` | `bizcity-intent/v1/sessions/by-sid/{sid}` | Lookup by UUID |
| PUT | `/sessions/{id}/move` | `bizcity_webchat_session_move` AJAX | Move session vào project |
| POST | `/sessions/{id}/gen-title` | `bizcity_webchat_session_gen_title` AJAX | AI generate title |

### 3.4 — Tasks Group (Nhiệm vụ / Intent Conversations)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/tasks` | `bizcity-intent/v1/tasks` + `bizcity_intent_conversations` AJAX | List tasks (paginated) |
| GET | `/tasks/stats` | `bizcity-intent/v1/tasks/stats` | Thống kê tasks |
| GET | `/tasks/{id}` | `bizcity-intent/v1/tasks/{id}` | Chi tiết 1 task |
| GET | `/tasks/{id}/turns` | `bizcity-intent/v1/tasks/{id}/turns` + `bizcity_intent_turns` AJAX | Lịch sử turns |
| POST | `/tasks/{id}/cancel` | `bizcity_intent_cancel` AJAX | Hủy task |
| POST | `/tasks/close-all` | `bizcity_intent_close_all` AJAX | Đóng tất cả tasks |

### 3.5 — Projects Group (Dự án / Folders)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/projects` | `bizcity_project_list` AJAX | List projects |
| POST | `/projects` | `bizcity_project_create` AJAX | Tạo project mới |
| GET | `/projects/{id}` | **MỚI** | Chi tiết project |
| PUT | `/projects/{id}` | `bizcity_project_rename` + `bizcity_project_update` AJAX | Update (rename, character binding) |
| DELETE | `/projects/{id}` | `bizcity_project_delete` AJAX | Xóa project |
| GET | `/projects/{id}/sessions` | `bizcity_webchat_sessions` AJAX (với project_id) | Sessions trong project |

### 3.6 — Tools Group (Tool Registry & Plugin Agents)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/tools/search` | `bizcity-webchat/v1/search-tools` + `bizcity_search_tools` AJAX | Tìm kiếm tool (slash command) |
| GET | `/tools/estimate` | `bizcity-webchat/v1/pre-intent-estimate` + `bizcity_pre_intent_estimate` AJAX | Pre-intent suggestion |
| GET | `/agents` | `bizcity-webchat/v1/plugin-suggestions` + `bizcity_get_plugin_suggestions` AJAX | List plugin agents |
| GET | `/agents/{slug}` | `bizcity-webchat/v1/plugin-context/{slug}` + `bizcity_get_plugin_context` AJAX | Agent context/detail |

### 3.7 — User Group

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/user/profile` | **MỚI** | User profile data |
| PUT | `/user/profile` | **MỚI** | Update profile |
| GET | `/user/settings` | **MỚI** | Cài đặt user (theme, preferences) |
| PUT | `/user/settings` | **MỚI** | Update cài đặt |
| PUT | `/user/password` | **MỚI** | Đổi mật khẩu |
| GET | `/user/memory` | **MỚI** | User Memory entries |
| GET | `/user/rolling-memory` | `bizcity_rolling_memory_get` AJAX | Rolling Memory tracker |

### 3.8 — Companion Group (Emotion/Bond)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/companion/emotion` | `bizcity-chat/v1/emotion` | Estimate emotion |
| GET | `/companion/bond` | `bizcity-chat/v1/bond` | Bond score |

### 3.9 — Dev/Debug Group (admin only)

| Method | Route | Nguồn hiện tại | Mô tả |
|--------|-------|-----------------|--------|
| GET | `/debug/router-log` | `bizcity_memory_poll_router` AJAX | Router console logs |
| GET | `/debug/execution-log` | `bizcity_poll_execution_log` AJAX | Execution pipeline logs |

---

## 4. Mapping: bizcity-app Routes → API Calls

### 4.1 — Frontend Routes

| App Route | API Calls Needed | Tương ứng admin-dashboard |
|-----------|-----------------|---------------------------|
| `/sign-in` | `POST /auth/login` | `bizcity_aiagent_login` AJAX |
| `/sign-up` | `POST /auth/register` | `bizcity_aiagent_register` AJAX |
| `/new-chat` | `POST /sessions` (lazy), `POST /chat/send`, `GET /chat/stream` | `ensureSession()` + SSE stream |
| `/chat/[id]` | `GET /sessions/{id}/messages`, `POST /chat/send`, `GET /chat/stream`, `GET /chat/pull` | `loadSession()` + SSE + polling |
| `/explore` | `GET /agents` | `_loadPluginChips()` |
| `/custom-bots` | `GET /projects`, `POST /projects`, `PUT /projects/{id}`, `DELETE /projects/{id}` | Projects CRUD AJAX |
| `/ai-generator` | `GET /tools/search`, `POST /chat/send` (with tool_goal) | `/slash` + tool_goal routing |
| `/upgrade-plan` | `GET /user/subscription` | **MỚI** |
| Sidebar | `GET /sessions`, `GET /tasks`, `PUT /sessions/{id}`, `DELETE /sessions/{id}` | `loadSessions()` + `loadIntentConversations()` |

### 4.2 — Reuse Score từ admin-dashboard

```
┌───────────────────────────────────────────────────────────────────┐
│  Feature Reuse: admin-dashboard.php → bizcity-app                  │
│                                                                     │
│  ✅ 90% — Chat send (SSE streaming + REST fallback + polling)     │
│  ✅ 90% — Session CRUD (create, load, list, rename, delete)       │
│  ✅ 90% — Task list + cancel + turns                              │
│  ✅ 85% — Project CRUD + move sessions                            │
│  ✅ 85% — @mention plugin agents + pre-intent estimate            │
│  ✅ 85% — /slash tool search                                      │
│  ✅ 80% — Plugin context mode (HIL focus)                         │
│  ✅ 80% — Message polling (4-layer dedup)                         │
│  ✅ 80% — AI title generation                                     │
│  ✅ 75% — Search modal (grouped by date)                          │
│  ✅ 70% — Image upload + attachments                              │
│  ❌ 0%  — Touch Bar / Agent Iframe Panels (admin-specific)        │
│  ❌ 0%  — Router Console (dev-only)                               │
│                                                                     │
│  TỔNG: ~85% logic có thể reuse                                    │
│  Chỉ cần wrap AJAX handlers thành REST route callbacks             │
└───────────────────────────────────────────────────────────────────┘
```

---

## 5. Implementation Plan — 5 Sprints

### Sprint 1: Foundation — Unified REST + Auth (1 ngày)

**File mới:** `class-unified-rest-api.php`

```php
class BizCity_Unified_REST_API {
    const NAMESPACE = 'bizcity/v1';
    
    // Delegate to existing classes:
    // - Tasks/Sessions → BizCity_Intent_REST_API logic
    // - Chat send/history → BizCity_Chat_Gateway logic  
    // - Tools/Agents → BizCity_Plugin_Suggestion_API logic
    // - Auth → existing login/register logic
}
```

**Tasks:**
1. Tạo `class-unified-rest-api.php` — đăng ký tất cả routes dưới `bizcity/v1`
2. Auth: JWT token issuance trong `/auth/login` → `wp_generate_password()` based token + transient store
3. Auth middleware: check JWT → API key → WP cookie (3 tiers)
4. `/auth/me` — verify token, return user data
5. Wire trong `bootstrap.php`

### Sprint 2: Chat & Sessions (1 ngày)

**Wrap existing logic vào REST:**
1. `/chat/send` → delegate to `BizCity_Chat_Gateway::ajax_send()` logic
2. `/chat/stream` → delegate to SSE handler (REST-compatible SSE)
3. `/sessions` CRUD — wrap `bizcity_webchat_sessions/create/move` AJAX handlers
4. `/sessions/{id}/gen-title` — wrap `bizcity_webchat_session_gen_title`
5. `/chat/upload` — wrap `bizcity_webchat_upload`
6. `/chat/pull` — wrap existing pull logic

### Sprint 3: Tasks & Projects (0.5 ngày)

**Mostly already REST — just re-register under new namespace:**
1. `/tasks/*` — copy from `class-intent-rest-api.php`, add POST cancel/close-all
2. `/projects/*` — wrap AJAX handlers from `class-admin-dashboard.php` JS → find PHP handlers

### Sprint 4: Tools, Agents, User (0.5 ngày)

1. `/tools/*` — wrap `class-plugin-suggestion-api.php` logic
2. `/agents/*` — same source
3. `/user/*` — new endpoints for profile/settings/memory

### Sprint 5: Deprecation + Frontend Wiring (1 ngày)

1. Add `_deprecated` headers to old namespace routes
2. Create `bizcity-app/lib/api.ts` — central HTTP client
3. Create `bizcity-app/lib/auth.ts` — JWT token management
4. Wire first page: `/sign-in` → `/auth/login`
5. Wire: `/new-chat` → sessions + chat/send + stream

---

## 6. File Structure After Refactoring

```
bizcity-intent/
  includes/
    class-unified-rest-api.php      ← NEW: Master router, 40+ endpoints
    class-intent-rest-api.php       ← KEEP: Reuse as internal service class
    class-intent-engine.php         ← KEEP: Core orchestrator (unchanged)
    class-intent-stream.php         ← KEEP: SSE handler (called by unified API)
    class-rolling-memory.php        ← KEEP: AJAX → REST migration
    ...

bizcity-knowledge/
  includes/
    class-chat-rest-api.php         ← DEPRECATED: redirect → bizcity/v1
    class-chat-gateway.php          ← KEEP: AI pipeline (called internally)
    ...

bizcity-bot-webchat/
  includes/
    class-plugin-suggestion-api.php ← DEPRECATED: redirect → bizcity/v1
    class-admin-dashboard.php       ← KEEP: Legacy admin UI (unchanged)
    ...

bizcity-app/
  lib/
    api.ts                          ← NEW: Axios/fetch wrapper + interceptors
    auth.ts                         ← NEW: JWT token store + refresh
    types.ts                        ← NEW: TypeScript types matching API responses
  hooks/
    useAuth.ts                      ← NEW: Auth context hook
    useChat.ts                      ← NEW: Chat send/stream hook
    useSessions.ts                  ← NEW: Sessions CRUD hook
    useTasks.ts                     ← NEW: Tasks hook
    useProjects.ts                  ← NEW: Projects CRUD hook
  stores/
    chatList.ts                     ← REFACTOR: localStorage → API calls
    auth.ts                         ← NEW: Auth state (Zustand)
```

---

## 7. Auth Flow — JWT cho React App

```
┌─────────────────────────────────────────────────────────────────┐
│  React App Auth Flow                                              │
│                                                                   │
│  1. POST /bizcity/v1/auth/login                                 │
│     Body: { username, password }                                 │
│     Response: { token, user: { id, name, email, avatar } }      │
│                                                                   │
│  2. Store token in:                                              │
│     • Memory (Zustand) — primary                                │
│     • httpOnly cookie — SSE/streaming needs cookie              │
│     • localStorage — persist across tabs (encrypted)            │
│                                                                   │
│  3. All subsequent requests:                                     │
│     Header: Authorization: Bearer <token>                        │
│                                                                   │
│  4. Token expiry: 7 days                                        │
│     Refresh: POST /auth/refresh (trước khi expire)              │
│                                                                   │
│  5. SSE streaming:                                               │
│     Cannot send custom headers → dùng cookie auth fallback      │
│     Hoặc truyền token qua query param (encrypted)               │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Migration Path — Không breaking change

```
Phase 1: Thêm bizcity/v1 (parallel)
  └─ Cả 3 namespace cũ VẪN hoạt động
  └─ bizcity/v1 delegate nội bộ đến các class hiện tại
  └─ admin-dashboard.php KHÔNG SỬA — vẫn dùng AJAX

Phase 2: Frontend dùng bizcity/v1
  └─ bizcity-app gọi bizcity/v1 exclusively
  └─ admin-dashboard vẫn dùng AJAX song song

Phase 3: Deprecate cũ (sau 3 tháng)
  └─ Thêm Deprecated header cho bizcity-chat/v1 và bizcity-webchat/v1
  └─ Log warning khi AJAX endpoints bị gọi
  └─ Không xóa — chỉ deprecate
```

---

## 9. Tổng kết endpoint count

| Group | Endpoints | Nguồn | Status |
|-------|-----------|-------|--------|
| Auth | 6 | 2 existing + 4 new | 🆕 |
| Chat | 6 | 5 existing (wrapped) + 1 new | ♻️ |
| Sessions | 11 | 6 existing REST + 5 existing AJAX (wrapped) | ♻️ |
| Tasks | 6 | 4 existing REST + 2 existing AJAX (wrapped) | ♻️ |
| Projects | 6 | 5 existing AJAX (wrapped) + 1 new | ♻️ |
| Tools | 2 | 2 existing (wrapped) | ♻️ |
| Agents | 2 | 2 existing (wrapped) | ♻️ |
| User | 5 | 1 existing AJAX + 4 new | 🆕 |
| Companion | 2 | 2 existing REST (rewired) | ♻️ |
| Debug | 2 | 2 existing AJAX (wrapped) | ♻️ |
| **TOTAL** | **48** | **24 existing + 14 wrapped AJAX + 10 new** | |

> **85% reuse** từ code đã xây dựng. Chỉ cần wrap vào REST callbacks, không viết lại logic.

---

## 10. Ưu tiên cho bizcity-app

Dựa vào bizcity-app hiện tại (100% static/mock), thứ tự kết nối:

| Ưu tiên | Route | API endpoints cần |
|---------|-------|-------------------|
| **P1** | Auth flow | `/auth/login`, `/auth/register`, `/auth/me` |
| **P2** | New Chat + Chat | `/sessions`, `/chat/send`, `/chat/stream` |
| **P3** | Sidebar | `/sessions` list, `/tasks` list |
| **P4** | Chat History | `/sessions/{id}/messages` |
| **P5** | Projects (Custom Bots) | `/projects` CRUD |
| **P6** | Explore (Agents) | `/agents` list |
| **P7** | AI Generator | `/tools/search`, `/chat/send` with tool_goal |
| **P8** | Settings/Profile | `/user/settings`, `/user/profile` |
