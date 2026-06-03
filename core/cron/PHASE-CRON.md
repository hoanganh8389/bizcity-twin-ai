# PHASE — core/cron · Unified Cron Registry & Dispatcher

> **Scope:** plugin `bizcity-twin-ai` ONLY. Không quản cron của plugin khác.
> **Mục tiêu:** một chỗ duy nhất khai báo + chạy + trace mọi job cron của plugin.
> Phối hợp `core/scheduler/` (event-driven, user-facing reminder + fb_post)
> KHÔNG thay nó — chỉ là tầng hạ tầng đứng dưới.
>
> **Canon rule:** R-DCL · R-DDV · R-GW-8 (xem `PHASE-0-CANON.md`).

---

## 0. Vì sao cần core/cron?

Hiện tại các module tự gọi `wp_schedule_event` ad-hoc:

| Module | Hook | Interval | Trace? |
|---|---|---|---|
| `core/scheduler` | `bizcity_scheduler_reminder_scan` | 5min | partial (transient + WP_DEBUG log) |
| `core/twinbrain` | (TBD) | — | ❌ |
| `modules/twinchat` | (TBD) | — | ❌ |
| `modules/webchat` | (TBD) | — | ❌ |
| `core/knowledge` (KG enrich) | một số `wp_schedule_single_event` | one-shot | ❌ |

Hệ quả thường gặp:
- Không biết job nào đang đăng ký, lần chạy gần nhất, runtime, lỗi.
- Mỗi module tự thêm `cron_schedules` interval (`bizcity_5min`, `bizcity_hourly_kg`, …) → trùng tên dễ vỡ.
- Khó smoke-test "đã schedule chưa, dispatch có nổ hook không, có lỗi gì không".
- MCP tool `cron.*` không thể wrap được vì không có registry.

---

## 1. Roadmap (3 phase)

### Phase 1 — **Registry + Observability** (THIS sprint, 2026-05-23)
> Mục tiêu: thấy được toàn bộ job, không thay đổi hành vi.

| Task | Output | Status |
|---|---|---|
| 1.1 Tạo `core/cron/bootstrap.php` load `BizCity_Cron_Manager`. | bootstrap.php | ☐ DOING |
| 1.2 `BizCity_Cron_Manager::register($id, $hook, $interval, $description, $owner)` API. Idempotent — gọi nhiều lần không nhân đôi. | class-cron-manager.php | ☐ |
| 1.3 Wrap mọi `do_action($hook)` qua manager để ghi `runs` log (start, end, duration, exception). Pattern: `add_action($hook, [$mgr, 'wrap_run'], 1)` chèn trước handler thật. | trace pattern | ☐ |
| 1.4 DB table `bizcity_cron_registry` (1 row / job) + `bizcity_cron_runs` (history, retention 7 ngày). R-DCL: `core/diagnostics/changelog/core.cron.json` v1.0.0. | DB + JSON | ☐ |
| 1.5 Probe `class-probe-cron-registry.php` + register qua `bizcity_diagnostics_register_probes`. Smoke test: (a) job nào đã `wp_next_scheduled` thật, (b) lần chạy gần nhất + status, (c) có schedule miss > 2× interval không. | probe | ☐ |
| 1.6 Refactor `BizCity_Scheduler_Cron::schedule()` gọi `BizCity_Cron_Manager::register('scheduler.reminder', REMINDER_HOOK, '5min', …)` thay vì wp_schedule_event trực tiếp. **Không đổi hook name → backward compat.** | scheduler-cron.php | ☐ |
| 1.7 R-DDV: thêm row "core.cron · registry" vào diagnostic page; build evidence Disk/Loader/Runtime. | diagnostics row | ☐ |

### Phase 2 — **Dispatcher + Retries + MCP** (sprint sau)
> Mục tiêu: cron trở thành tool first-class của TwinBrain.

| Task | Output | Status |
|---|---|---|
| 2.1 `BizCity_Cron_Manager::dispatch_async($job_id, $payload)` → enqueue 1 single event (lùi 5s) thay vì chạy sync. | API | ☐ |
| 2.2 Retry policy mỗi job: `max_retries`, `backoff_seconds`, `dead_letter_queue` (1 row trong runs table với `status='dead'`). | runs schema | ☐ |
| 2.3 Tool `BizCity_TwinBrain_Tool_Cron` đăng ký 4 method MCP: `list_jobs`, `last_runs`, `force_run`, `disable_job`. | tool class | ☐ |
| 2.4 `BizCity_Scheduler_Tools` (memory-like) expose `schedule_reminder({when, title, channel})` → wrap qua `core/scheduler` event row + sync sang Google Calendar (tái dùng `class-scheduler-google.php`). | scheduler tool | ☐ |
| 2.5 Doc R-DCL bump → `core.cron.json` v1.1.0 (thêm cột `retry_count`, `dead_at`). | changelog | ☐ |

### Phase 3 — **Coordinated scheduling + Lock-free shards** (Q3 2026)
> Mục tiêu: chạy được nhiều site cùng dùng cùng DB shard mà không deadlock.

| Task | Output | Status |
|---|---|---|
| 3.1 Distributed lock qua `wp_options` advisory key (TTL 30s, auto-renew). | lock class | ☐ |
| 3.2 Drift detection: nếu `last_run_at` < `expected_next - 2 × interval` → fire `bizcity_cron_drift` action + log dashboard alert. | drift probe | ☐ |
| 3.3 Per-job circuit breaker: 5 lần failed liên tiếp → auto-disable + admin notice. | breaker | ☐ |

---

## 2. Contract — `BizCity_Cron_Manager`

```php
BizCity_Cron_Manager::instance()->register([
    'id'          => 'scheduler.reminder',          // unique, dot-namespaced, owner.purpose
    'hook'        => 'bizcity_scheduler_reminder_scan',
    'interval'    => 'bizcity_5min',                // WP cron schedule key
    'owner'       => 'core/scheduler',              // module path
    'description' => 'Scan due reminders & fire bizcity_scheduler_reminder_fire',
    'singleton'   => true,                          // skip if already scheduled
    'retention'   => 7,                             // days to keep in runs
]);
```

Khi cron tick:
1. WP fire `bizcity_scheduler_reminder_scan`.
2. Manager's pre-hook (priority 1) → INSERT row `{job_id, started_at}` vào `bizcity_cron_runs`.
3. Real handler chạy.
4. Manager's post-hook (priority PHP_INT_MAX) → UPDATE row `{ended_at, duration_ms, status, error?}`.

Nếu handler throw → caught bởi shutdown handler, ghi `status='error', error=msg, trace=…`.

---

## 3. Unify với `core/scheduler/`

| Layer | Trách nhiệm | Source of truth |
|---|---|---|
| `core/cron/` | "Hook X chạy mỗi 5 phút." Hạ tầng, không biết business. | `bizcity_cron_registry` |
| `core/scheduler/` | "Event row #123 (fb_post) đến giờ → fire reminder." Business event. | `bizcity_crm_events` |
| `modules/*` | Subscribe `bizcity_scheduler_reminder_fire` để publish FB / gửi push / email. | — |

→ `BizCity_Scheduler_Cron` Phase 1.6 KHÔNG xoá hook — chỉ đăng ký qua manager để được trace.

---

## 4. MCP-readiness (Phase 2)

TwinBrain tool layout dự kiến:

```
core/twinbrain/includes/tools/
  class-twinbrain-tool-cron.php          // wraps core/cron manager
  class-twinbrain-tool-scheduler.php     // wraps core/scheduler events
```

Method ví dụ (`cron.list_jobs`):

```json
{
  "jobs": [
    {
      "id": "scheduler.reminder",
      "hook": "bizcity_scheduler_reminder_scan",
      "interval": "bizcity_5min",
      "next_run_at": 1748056800,
      "last_run_at": 1748056500,
      "last_status": "ok",
      "last_duration_ms": 124,
      "owner": "core/scheduler"
    }
  ]
}
```

Trợ lý ngoài (MCP client) gọi tool → trả JSON deterministic → có thể debug từ xa.

---

## 5. R-DCL & R-DDV checklist (Phase 1)

- [ ] `core/diagnostics/changelog/core.cron.json` v1.0.0 — khai báo `bizcity_cron_registry` + `bizcity_cron_runs`.
- [ ] Validator: `php core/diagnostics/validate-schema-changelog.php` exit 0.
- [ ] Probe `cron.registry-smoke` thêm row PASS/FAIL vào diagnostic page.
- [ ] Auto-create đảm bảo 2 bảng tồn tại trên fresh install.

---

## 6. Anti-patterns CẤM

- ❌ `wp_schedule_event` trực tiếp trong module mới sau khi Phase 1 ship.
- ❌ Đăng ký lại job với `id` đã tồn tại (manager phải reject + log).
- ❌ Đặt `interval` literal số giây thay vì tên schedule (`'300'`).
- ❌ Wrap quanh hook không phải do mình declare (sẽ tạo trace ma).
- ❌ Cross-plugin: register hook của plugin khác (R-GW-8 — đứng yên trong phạm vi `bizcity-twin-ai`).

---

## 7. Changelog

| Date | Change |
|---|---|
| 2026-05-23 | v0.1 — Initial phase plan (Phase 1 = Registry + Observability, Phase 2 = Dispatcher + MCP, Phase 3 = Coordination). |
