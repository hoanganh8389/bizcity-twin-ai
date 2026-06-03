# PHASE CG-SCHEDULER — Unified Scheduler & Facebook Post Scheduling

> **Status:** v0.2 · 2026-05-23 · **Owner:** Channel Gateway + Scheduler teams
> **Indexed in:** [PHASE-0-CANON.md](../../PHASE-0-CANON.md) Cross-walk (Tier 1 · R-CH + R-SCHEDULER)
> **Pre-req rules:** R-CH (`PHASE-0-RULE-CHANNEL-ONLY.md`), R-DCL (`PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md`), R-DDV, R-1API, R-GW-8.
> **Build order:** FE port (Phase 1 ✅) → BE publisher hook + history backfill (Phase 2) → Diagnostic & polish (Phase 3).
> **Effective:** khi toàn bộ task table §6 PASS trong Sprint Diag §"Sprint CG-SCHEDULER".

> ⚠️ **v0.2 ARCHITECTURE PIVOT (2026-05-23).** Sau khi audit CRM `schedulerApi.js` + `CalendarTab.jsx`,
> bỏ kế hoạch tạo CPT `bizcity_fb_post` riêng (v0.1 cũ — D1 "dual-source"). Lý do: bảng
> `wp_bizcity_crm_events` + cột `metadata` JSON đã đủ để chứa FB-specific fields, và đi qua đó
> **miễn phí** kế thừa Google Calendar 2-way sync + reminder cron + multi-account hub. CPT
> duplicate hoá lưu trữ + bắt ta phải tự code lại sync. Quyết định mới: **plan B — pure
> scheduler events, không CPT**. Tất cả truy vấn "bài đã lên lịch / đã đăng / lỗi" đi qua
> REST `bizcity-scheduler/v1/events?event_type=fb_post`.

---

## 1 · Mục tiêu (TL;DR)

Hợp nhất 2 flow lập lịch hiện đang phân tán:

| Flow | Nguồn | Trạng thái | Đích sau khi unify |
|------|-------|-----------|--------------------|
| **F1 — Sự kiện CRM** | `bizcity-twin-crm` | Đã gọi `bizcity-scheduler/v1` (read-only consumer) | ✅ giữ nguyên |
| **F2 — Lịch đăng bài FB** (tab `📅 Lịch đăng` channel-gateway) | `core/channel-gateway` | ❌ chỉ publish ngay; mock FE từ Phase 1 cũ | ✅ tạo row trong `wp_bizcity_crm_events` (event_type=`fb_post`, metadata.fb_* chứa nội dung/page/image) → scheduler cron tự đăng + Google Calendar tự sync |

Sau khi xong:
- **1 bảng lịch** (`wp_bizcity_crm_events`) chứa cả meeting/training/internal (F1) lẫn fb_post (F2) → Google Calendar thấy đủ.
- **0 CPT mới**, **0 REST namespace mới**, **0 sync code mới** — chỉ thêm 1 PHP listener (~80 dòng) cho cron action.
- **0 fork**: CRM Calendar vẫn dùng REST `bizcity-scheduler/v1`. Channel Gateway dùng cùng REST với `event_type=fb_post`.

---

## 2 · Hiện trạng (đã exploration)

### 2.1 core/scheduler (đã có)
- Table `wp_bizcity_crm_events` schema v3: `id, blog_id, title, event_type, start_at, end_at, status, user_id, all_day, source, reminder_min, google_account_id, google_calendar_id, google_event_id, metadata (JSON), created_at`.
- REST `bizcity-scheduler/v1`: `GET/POST /events`, `PATCH/DELETE /events/{id}`, `POST /events/quick`, `GET /today`, `GET /google/accounts`, `GET/POST /google/settings`, `POST /google/sync`, `POST /google/disconnect`.
- Google Calendar 2-way sync auto trên create/update/delete; multi-account qua BZGoogle Hub.
- Cron `bizcity_scheduler_reminder_scan` (5 phút) → fire action `bizcity_scheduler_reminder_fire` cho từng event sắp đến.
- Hooks: `bizcity_scheduler_event_created/updated/deleted/reminder_fire`.

### 2.2 channel-gateway · Facebook (đã có)
- `class-facebook-page-rest.php` có 2 POST: `/facebook/post` (publish ngay) + `/facebook/ai-compose` (AI draft).
- Sau publish → log `wp_bizcity_channel_messages` (`event_type=post`, `status=sent|failed`).
- FE Phase 1 v0.1 đã ship UI mock với `fbPostsApi.js` (mock). **Phase 1 v0.2 đã rewire** sang `cgSchedulerApi.js` (real REST).

### 2.3 bizcity-twin-crm · Calendar (đã có & unified)
- `CalendarTab.jsx` gọi RTK `schedulerApi.js` → `/wp-json/bizcity-scheduler/v1/`. Tham chiếu thiết kế cho channel-gateway port.

---

## 3 · Kiến trúc đích (v0.2 — Plan B)

```
┌──────────────────────────────────────────────────────────────────────┐
│  wp_bizcity_crm_events  (đã có, KHÔNG migration)                     │
│  ├─ event_type      = 'fb_post'   (NEW value, no schema change)      │
│  ├─ title           = "📘 {first 60 chars of content}…"              │
│  ├─ start_at        = scheduled time (DATETIME)                      │
│  ├─ end_at          = start_at + 5min placeholder                    │
│  ├─ status          = active|done|cancelled                          │
│  ├─ source          = 'channel_gateway'                              │
│  ├─ google_account_id (optional) → Google Calendar sync              │
│  └─ metadata JSON:                                                   │
│       fb_page_id, fb_page_name, fb_content, fb_image_url,            │
│       fb_post_id (filled sau khi publish),                           │
│       fb_permalink (filled), fb_publish_status                       │
│       (pending|publishing|published|failed),                         │
│       fb_error (string khi failed)                                   │
└──────────────────────────────────────────────────────────────────────┘

           ┌──────────────────────────────────────────────────┐
F1 (CRM):  │  User click "Sự kiện mới" tại CRM Calendar       │
           │   → POST /bizcity-scheduler/v1/events             │
           │     event_type=meeting|internal|workshop|training │
           └──────────────────────────────────────────────────┘

           ┌──────────────────────────────────────────────────┐
F2 (FB):   │  User click "Lên lịch" tại channel-gateway       │
           │   → POST /bizcity-scheduler/v1/events             │
           │     event_type=fb_post, metadata.fb_*={…}         │
           │   → core/scheduler tạo row + Google sync          │
           └──────────────────────────────────────────────────┘

           ┌──────────────────────────────────────────────────┐
Cron:      │  scheduler_reminder_scan (mỗi 5') fire           │
           │   `bizcity_scheduler_reminder_fire`               │
           │   ↓                                               │
           │  BizCity_FB_Publisher::on_reminder_fire($event)   │
           │   if $event->event_type !== 'fb_post' → return    │
           │   if metadata.fb_publish_status !== 'pending' → ↩ │
           │   → mark publishing → call Graph publish          │
           │   → update metadata.fb_post_id, fb_permalink      │
           │   → mark fb_publish_status=published, event=done  │
           │   (on error → fb_publish_status=failed + fb_error)│
           └──────────────────────────────────────────────────┘
```

---

## 4 · Quyết định kiến trúc (v0.2)

| ID  | Quyết định | Lý do |
|-----|-----------|-------|
| D1  | **Pure scheduler events** (không CPT). `metadata` JSON đủ chứa fb_page_id / fb_content / fb_image_url / fb_post_id / fb_permalink / fb_publish_status. | Đỡ duplicate; tự động hưởng Google sync + multi-account hub + reminder cron. |
| D2  | **WP cron publish only** (không dùng FB native `scheduled_publish_time`). Cron `bizcity_scheduler_reminder_scan` (5') đăng vào thời điểm `start_at`. | Đơn giản 1 path; không phải xử lý dual-source state machine (FB native vs local). Trade-off: site sleep tối có thể trễ 5'; chấp nhận. |
| D3  | **Sync FE bằng đúng RTK slice ported từ CRM** (`cgSchedulerApi.js` mirror `schedulerApi.js`). | Same shape → CRM CalendarTab + FB Schedule có thể share component sau này. |
| D4  | **Tab `📅 Lịch đăng`** (calendar grid) + tab `📄 Bài đăng` (list) — 2 view khác data shape ban đầu. **Phase 2** sẽ thống nhất tab `📄 Bài đăng` cũng đọc từ scheduler events. | Tách view theo intent. |
| D5  | **Không backfill lịch sử Graph** vào events. Bài đăng cũ chỉ hiển thị qua "Bài đăng (live Graph)" view. | Tránh duplicate + đơn giản. |
| D6  | **Cancel = update event.status='cancelled' + metadata.fb_publish_status='cancelled'**. Publisher hook check status='active' trước khi đăng → cancelled tự skip. **Xoá hẳn = `DELETE /events/{id}`**. | Cancel reversible (chuyển lại active được). Delete xoá hẳn audit. |
| D7  | **Google account flow**: dùng nguyên hệ `useGetGoogleAccountsQuery` + dialog GoogleSheet trong calendar (đã ship Phase 1). | Reuse 100% chain `/google/settings` → `/google/auth_url` → `/google/sync` của scheduler. |

---

## 5 · Roadmap

### Phase 1 — FE rewire to real scheduler (✅ DONE 2026-05-23)

| ID | Task | Files | Status |
|----|------|-------|--------|
| **T-CG-SCH.1.1** | Sidebar add tab `📅 Lịch đăng` vào `FACEBOOK_TABS` | [navConfig.js](frontend/src/shell/navConfig.js) | ✅ |
| **T-CG-SCH.1.2** | Đổi label `📄 Bài đã đăng` → `📄 Bài đăng` | navConfig.js | ✅ |
| **T-CG-SCH.1.3** | Refactor `FacebookPosts.jsx`: status filter pills + table | FacebookPosts.jsx | ✅ |
| **T-CG-SCH.1.4** | `FacebookCreatePost.jsx` thêm radio "Đăng ngay / Lên lịch" + datetime + Google account select (real `useGetGoogleAccountsQuery`) | [FacebookCreatePost.jsx](frontend/src/routes/platform/facebook/FacebookCreatePost.jsx) | ✅ |
| **T-CG-SCH.1.5** | Component `FacebookSchedule.jsx` — calendar grid month-view, ô trống → drawer "Lên lịch", chip → drawer "Chi tiết" | [FacebookSchedule.jsx](frontend/src/routes/platform/facebook/FacebookSchedule.jsx) | ✅ |
| **T-CG-SCH.1.6** | **RTK slice `cgSchedulerApi.js`** port từ CRM `schedulerApi.js`, trỏ `bizcity-scheduler/v1` qua `BIZCITY_CG_BOOT.schedulerRestUrl`. 9 hooks: `useGet/Create/Update/DeleteSchedulerEvent`, `useGetGoogleAccounts`, `useSyncGoogle`, `useGet/Save/DisconnectGoogle*` | [cgSchedulerApi.js](frontend/src/redux/api/cgSchedulerApi.js) | ✅ |
| **T-CG-SCH.1.7** | Wire route `/p/facebook_page/schedule` + reducer + middleware | [store.js](frontend/src/redux/store.js), Workspace.jsx | ✅ |
| **T-CG-SCH.1.8** | Vite rebuild clean (44 kB CSS, 491 kB JS) | — | ✅ |
| **T-CG-SCH.1.9** | **PHP** expose `schedulerRestUrl: '/wp-json/bizcity-scheduler/v1/'` trong `BIZCITY_CG_BOOT` | [class-admin-menu-spa.php](includes/class-admin-menu-spa.php) | ✅ |
| **T-CG-SCH.1.10** | Calendar header có Sheet "Google" — connect/configure/disconnect + sync button (port `GoogleSettingsForm` từ CRM) | FacebookSchedule.jsx | ✅ |

### Phase 2 — BE: Publisher hook (~80 dòng PHP, không migration)

| ID | Task | Files | Status |
|----|------|-------|--------|
| **T-CG-SCH.2.1** | Class `BizCity_FB_Publisher` — singleton, subscribe `bizcity_scheduler_reminder_fire`. Check `$event['event_type'] === 'fb_post'`, `status === 'active'`, `metadata.fb_publish_status === 'pending'` | core/channel-gateway/includes/class-fb-publisher.php (new) | ☐ |
| **T-CG-SCH.2.2** | `BizCity_FB_Publisher::publish_event($event)`: load page_id + page_name + content + image_url từ metadata → gọi Graph publish (reuse `BizCity_Facebook_Page_REST::do_publish` helper). On success: update event metadata `{fb_post_id, fb_permalink, fb_publish_status:'published'}` + event status='done'. On fail: `{fb_publish_status:'failed', fb_error}` + event status='active' (retry tới lúc admin manual cancel) | class-fb-publisher.php | ☐ |
| **T-CG-SCH.2.3** | Refactor `do_publish` private method trong [class-facebook-page-rest.php](includes/adapters/class-facebook-page-rest.php) → public static helper reuse được. | class-facebook-page-rest.php | ☐ |
| **T-CG-SCH.2.4** | Idempotent guard: nếu `metadata.fb_post_id` đã set → skip publish (đề phòng cron fire 2 lần). | class-fb-publisher.php | ☐ |
| **T-CG-SCH.2.5** | Permission: cron run as `wp_set_current_user(0)`. Publisher dùng page access_token đã saved trong `wp_options` `bizcity_facebook_pages_<page_id>` thay vì `current_user_can`. | class-fb-publisher.php | ☐ |
| **T-CG-SCH.2.6** | Register class trong [bootstrap.php](bootstrap.php): `BizCity_FB_Publisher::instance()` hook `plugins_loaded` priority 20 (sau core/scheduler init). | bootstrap.php | ☐ |
| **T-CG-SCH.2.7** | **R-DCL**: `core/diagnostics/changelog/modules.channel-gateway.json` bump version + push history `{change: "Add fb_post event_type metadata contract"}`. Run validator. | changelog JSON | ☐ |

### Phase 3 — Diagnostic & polish

| ID | Task | Status |
|----|------|--------|
| **T-CG-SCH.3.1** | Probe `class-probe-fb-publisher.php`: assert action subscribed + count `event_type=fb_post, status=active, metadata.fb_publish_status=pending, start_at < now` > 0 → list stuck events | ☐ |
| **T-CG-SCH.3.2** | Probe `class-probe-fb-schedule-cron.php`: verify cron `bizcity_scheduler_reminder_scan` next_run ≤ now+600s | ☐ |
| **T-CG-SCH.3.3** | UI hiển thị `fb_publish_status` badge ("Đã đăng" / "Đang đăng" / "Lỗi đăng") + tooltip mostre `fb_error` khi failed | ☐ |
| **T-CG-SCH.3.4** | `📄 Bài đăng` tab: thêm view "Đã lên lịch / Lỗi" cũng đọc từ scheduler events (deprecate mock `fbPostsApi.js`) | ☐ |
| **T-CG-SCH.3.5** | Sprint Diagnostic rows PASS toàn bộ §6 trong [Sprint Diag](/wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag) §"Sprint CG-SCHEDULER" | ☐ |
| **T-CG-SCH.3.6** | Manual "Đăng lại" cho event failed: nút trong drawer → reset `fb_publish_status='pending'` + `start_at=now+5min` → cron sẽ retry | ☐ |
| **T-CG-SCH.3.7** | Xoá file mock `fbPostsApi.js` + reducer wire khỏi store sau khi T-CG-SCH.3.4 xong | ☐ |

---

## 6 · Task table tổng (R-DDV — 1 row ↔ 1 probe Sprint Diag)

| ID | Sprint check | Evidence |
|----|-------------|----------|
| T-CG-SCH.1.6 | `cgSchedulerApi.js` reducer present trong store + REST hit `/bizcity-scheduler/v1/events` 200 OK | DevTools Network |
| T-CG-SCH.1.9 | BOOT có `schedulerRestUrl` | `window.BIZCITY_CG_BOOT.schedulerRestUrl` defined |
| T-CG-SCH.1.10 | Sheet Google mở được, save/connect/disconnect không 4xx/5xx | manual smoke |
| T-CG-SCH.2.1 | `has_action('bizcity_scheduler_reminder_fire', [BizCity_FB_Publisher::instance(), 'on_reminder_fire'])` > 0 | probe |
| T-CG-SCH.2.2 | Tạo event `fb_post` start_at=now-1min → cron next tick → metadata.fb_post_id set | integration test |
| T-CG-SCH.3.2 | `wp_next_scheduled('bizcity_scheduler_reminder_scan') - time() < 600` | probe |

---

## 7 · REST surface (đích — không tạo namespace mới)

```
# core/scheduler (đã có — REUSE 100%)
GET    /wp-json/bizcity-scheduler/v1/events?from=&to=&event_type=fb_post&status=
POST   /wp-json/bizcity-scheduler/v1/events
  body: { event_type: 'fb_post', title, start_at, end_at, source: 'channel_gateway',
          status: 'active', google_account_id?,
          metadata: { fb_page_id, fb_page_name, fb_content, fb_image_url,
                      fb_publish_status: 'pending' } }
PATCH  /wp-json/bizcity-scheduler/v1/events/{id}   ← cancel = {status:'cancelled'}
DELETE /wp-json/bizcity-scheduler/v1/events/{id}
GET    /wp-json/bizcity-scheduler/v1/google/accounts
POST   /wp-json/bizcity-scheduler/v1/google/sync
GET    /wp-json/bizcity-scheduler/v1/google/settings
POST   /wp-json/bizcity-scheduler/v1/google/settings
POST   /wp-json/bizcity-scheduler/v1/google/disconnect

# channel-gateway (GIỮ NGUYÊN — không thêm /facebook/posts/* mới)
POST   /wp-json/bizcity-channel/v1/facebook/post       (publish ngay)
POST   /wp-json/bizcity-channel/v1/facebook/ai-compose (AI draft)
```

R-1API: token FB / Google đều ở server. FE chỉ gọi same-origin với `X-WP-Nonce`.

---

## 8 · Hooks contract

### 8.1 Hooks core/scheduler PHẢI provide (đã có)
- `do_action('bizcity_scheduler_reminder_fire', array $event)` — Publisher subscribe để đăng.
- Reminder scan filter event với `start_at <= now + reminder_min*60` → channel-gateway dùng `reminder_min=0` để fire đúng lúc `start_at`.

### 8.2 Hooks channel-gateway sẽ phát (Phase 2)
- `do_action('bizcity_fb_post_publish_start', int $event_id, array $event)`.
- `do_action('bizcity_fb_post_published', int $event_id, string $fb_post_id, string $permalink)`.
- `do_action('bizcity_fb_post_failed', int $event_id, string $error)`.

---

## 9 · Changelog discipline (R-DCL)

- **Schema changelog JSON**: `core/diagnostics/changelog/modules.channel-gateway.json` bump version khi thêm `metadata.fb_*` contract (Phase 2). Validator phải exit 0.
- `metadata` là JSON column nên KHÔNG cần `dbDelta`. Chỉ document contract trong JSON dưới key `event_metadata_contracts.fb_post = {since, fields: [fb_page_id, fb_page_name, fb_content, fb_image_url, fb_post_id, fb_permalink, fb_publish_status, fb_error]}`.
- **Rule pinned**: R-CH + R-SCHEDULER.

---

## 10 · Anti-patterns CẤM

- ❌ Tạo CPT `bizcity_fb_post` (v0.1 plan — đã reject).
- ❌ Tạo REST namespace `bizcity-channel/v1/fb-posts/*` mới (đã reject).
- ❌ Fork core/scheduler để thêm FB logic → bridge qua `bizcity_scheduler_reminder_fire`.
- ❌ FE gọi trực tiếp Graph API từ browser (R-GW-8).
- ❌ Publisher fire mà không check idempotent (đã có `fb_post_id` → return ngay).
- ❌ Trust client-supplied `start_at` không validate ≥ now+10min trong PHP layer (Phase 2).
- ❌ FE đọc/ghi `fb_publish_status` trực tiếp (chỉ Publisher PHP touch).

---

## 11 · Open questions (defer)

| # | Question | Defer reason |
|---|----------|-------------|
| Q1 | Multi-page bulk schedule (1 bài → N pages) | Sau Phase 3 |
| Q2 | Recurring post (mỗi thứ 2 đăng lại bài X) | Extend metadata.recurrence; defer Wave 2 |
| Q3 | Approval workflow (draft → manager approve → schedule) | Defer Wave 3 |
| Q4 | Áp dụng cùng pattern cho Zalo OA / Telegram | Sau khi FB ổn → generalize `event_type` enum |

---

## 12 · CHANGELOG (file này)

| Date       | Author                          | Change |
| ---------- | ------------------------------- | ------ |
| 2026-05-23 | Channel Gateway + Scheduler team | **v0.2 — Architecture pivot to Plan B.** Bỏ CPT `bizcity_fb_post`, dùng `wp_bizcity_crm_events` + `event_type=fb_post` + `metadata.fb_*`. Phase 1 FE rewire sang `cgSchedulerApi.js` (port từ CRM `schedulerApi.js`). Calendar có Sheet Google connect/sync. Phase 2 rút từ 9 task xuống 7 task (chỉ cần publisher hook + R-DCL bump). |
| 2026-05-23 | Channel Gateway + Scheduler team | v0.1 — Spec dual-source CPT + events. (Superseded by v0.2.) |
