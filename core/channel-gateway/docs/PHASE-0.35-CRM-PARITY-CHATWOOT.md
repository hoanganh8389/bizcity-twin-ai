# PHASE 0.35 — CRM Parity với Chatwoot (KG-grounded Refactor)

> **Status**: 🗺️ planning · **Owner**: Twin AI Architecture · **Stack**: PHP 8 (WP custom tables) + React 18 + RTK Query + Tailwind + Vite IIFE
> **Anchor commit**: PHASE 0.34 M5/M6 (Composer · Note · Resolve · ContactDrawer · Webhook Replay)
> **Parent docs**:
> - [PHASE-0.32-CRM-INBOX-HUB.md](PHASE-0.32-CRM-INBOX-HUB.md) — RULE R-CRM-1..8 (CRM core)
> - [PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md](PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md) — Inbox FE + trace contract §0.2
> - [PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md](PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md)
> - [PHASE-0.31-TARGET-SCENARIOS.md](PHASE-0.31-TARGET-SCENARIOS.md) — 7 north-star scenarios (S1..S7)
> **Reference (read-only library)**:
> - `plugins/bizcity-twin-crm/_library/chatwoot-develop/` (Rails — chỉ port schema + UX, KHÔNG port code)
> - [bizgpt-custom-flows](../../../../bizgpt-custom-flows/) (Vietnamese keyword + reminder engine)
> - [user-points](../../../../user-points/) (legacy point ledger — sẽ wrap qua Hub)

---

## 0. ⚖ Tuyên ngôn vận hành (Operating Manifesto)

> **Mục tiêu**: Đưa BizCity CRM ngang tầm Chatwoot ở 8 tính năng vận hành (Channel UI, Funnel, Labels, Custom Attrs, Automation Rules, Macros, Working Hours/SLA, Reports), **đồng thời giữ trục KG Hub + Notebook làm bộ não** — mọi rule/macro/report đều có thể tham chiếu `notebook_id` để AI grounded.
>
> **Khác biệt vượt Chatwoot**:
> 1. **Trace contract 4 chế độ** (§0.34 §0.2) — bubble nào cũng có `responder_kind` ∈ {auto, manual, hybrid, system}. Reports dashboard sẽ vẽ được "Auto vs Human vs Hybrid resolution rate" — Chatwoot không có.
> 2. **Notebook-grounded automation** — mọi `automation_rule.action[send_message]` có optional `notebook_id` → call `nb_query_kg` rồi feed vào LLM. Chatwoot chỉ có template tĩnh.
> 3. **Campaign QR + UTM + Loyalty** — port từ `bizgpt-custom-flows` keyword referral, kết hợp `user-points`, gắn QR generator + UTM tracking → funnel data tự động đổ về `bizcity_crm_contacts.acquisition_meta`.

### 0.1 Ranh giới phase

| In-scope | Out-of-scope (defer) |
|---|---|
| Labels / Custom Attributes / Macros / Working Hours / SLA / Automation Rules / Reports / Campaign QR-UTM | Help Center portal · Multi-tenant team views · Voice/Video channel · Visual flow editor (waic_twf đã có) |

### 0.2 Bản đồ kết nối với core đã có

```
                     ┌──────────────────────────────────────┐
                     │          KG HUB (BRAIN)              │
                     │  notebooks · sources · chunks        │
                     │  BizCity_KG_Retriever::ask()         │
                     └─────────────┬────────────────────────┘
                                   │ nb_query_kg(notebook_id, query)   ← THÊM MỚI (M2)
                                   ▼
┌────────────────┐   event   ┌─────────────────────┐   action   ┌───────────────────┐
│ Inbound bubble │──────────►│ Automation Rule     │───────────►│ Macro / Send msg  │
│ (channel msg)  │           │ Engine (M2)         │            │ Add label · Assign│
└────────────────┘           │ conditions+actions  │            │ Snooze · Resolve  │
                             └──────┬──────────────┘            └───────────────────┘
                                    │ emits via Twin_Event_Bus
                                    ▼
                  ┌──────────────────────────────────┐
                  │  bizcity_twin_event_stream       │ (R-EVT-1, R-EVT-2)
                  │  ── projection ──►               │
                  │  reporting_event view (M5)       │  ← Reports Dashboard
                  └──────────────────────────────────┘

Campaign side-channel (M6):
  QR landing → ?utm_*&ref=camp_X → contact upsert + acquisition_meta
                                          │
                                          ▼
                                    automation rule "campaign:camp_X" fires
                                          │
                                          ▼
                                    nb_query_kg(notebook=camp_script) → AI reply
                                          │
                                          ▼
                                    user_points::award(reason='campaign:camp_X')
```

---

## 1. RULE bắt buộc (R-PAR-1..10)

> Đọc TRƯỚC khi viết dòng code đầu tiên. Bổ sung cho R-CRM-1..8 (PHASE 0.32 §0), KHÔNG ghi đè.

### **R-PAR-1 — Schema mở rộng phải reuse 6 bảng CRM hiện có**
- Thêm cột vào `bizcity_crm_*` tables (vd: `conversations.label_list`, `messages.macro_id`) qua **migration script trong `BizCity_CRM_DB_Installer`** — KHÔNG tạo bảng "label_assignments" / "tag_map" / "audit_*" mới (tuân R-EVT-2).
- Bảng MỚI hợp lệ (snapshot state, không phải log): `bizcity_crm_labels`, `bizcity_crm_custom_attribute_definitions`, `bizcity_crm_macros`, `bizcity_crm_working_hours`, `bizcity_crm_sla_policies`, `bizcity_crm_applied_slas`, `bizcity_crm_automation_rules`, `bizcity_crm_campaigns`, `bizcity_crm_campaign_visits`.
- Naming: `{$wpdb->prefix}bizcity_crm_*`, prefix class `BizCity_CRM_*` (R-CRM-8).

### **R-PAR-2 — Mọi action mutate state PHẢI emit event qua `BizCity_Twin_Event_Bus`**
- Action types: `crm_label_assigned`, `crm_label_removed`, `crm_status_changed`, `crm_assignee_changed`, `crm_macro_executed`, `crm_rule_fired`, `crm_sla_breached`, `crm_campaign_visit_recorded`, `crm_points_awarded`.
- Tất cả phải có `parent_event_uuid` (R-EVT-6 causal chain) trỏ về event nguồn (vd: rule `crm_message_received` → fire `crm_rule_fired` → trigger `crm_label_assigned`).
- KHÔNG được dùng `do_action('bizcity_crm_*')` trực tiếp để "ghi log" — phải qua Event Bus.

### **R-PAR-3 — Reports = projection của Event Stream, không phải bảng cache**
- KHÔNG tạo `bizcity_crm_reports_cache` / `_metrics` / `_kpi` tables.
- View materialized hoặc query-on-demand từ `bizcity_twin_event_stream` filter theo `event_type LIKE 'crm_%'`.
- Cho phép daily rollup vào `bizcity_twin_event_stream` row có `event_type='crm_daily_rollup'` + `payload_json={metrics}` — đây là projection hợp lệ (R-EVT-3).
- Chart UI dùng polling 2-5s (R-IMN-5), KHÔNG SSE riêng.

### **R-PAR-4 — Automation Rule Engine PHẢI chạy qua Listener pattern**
- 1 listener class duy nhất: `BizCity_CRM_Automation_Engine` subscribe các event:
  - `crm_message_received` · `crm_message_sent` · `crm_conversation_created` · `crm_conversation_resolved` · `crm_conversation_assigned` · `crm_label_assigned` · `crm_campaign_visit_recorded` · cron tick `crm_sla_check`.
- KHÔNG hard-code rule trong code — tất cả load từ `bizcity_crm_automation_rules` table.
- Action `send_message` với `notebook_id ≠ null` PHẢI gọi qua `BizCity_LLM_Router` với `parent_event_uuid` (R-CRM-3 + R-GW-1) — KHÔNG bypass gateway.

### **R-PAR-5 — Macros + Canned responses dùng cùng template engine**
- Single template renderer: `BizCity_CRM_Template_Renderer::render($template, $context)`.
- Hỗ trợ token: `{{contact.name}}`, `{{conversation.id}}`, `{{inbox.name}}`, `{{user.display_name}}`, `{{kg.answer:notebook=X}}` (lazy-resolve qua `nb_query_kg`).
- CẤM eval / template engine ngoài (Twig, Mustache PHP …) — chỉ regex thay token.

### **R-PAR-6 — Working Hours + SLA tách bạch khỏi cron tự xây**
- Dùng WP `wp_schedule_event` với hook `bizcity_crm_sla_tick` mỗi 1 phút.
- `BizCity_CRM_SLA_Evaluator` đọc `applied_slas` rows `state='active'`, so threshold với `now() - last_inbound_at`. Breach → emit `crm_sla_breached` event → Automation Engine reactor.
- KHÔNG dùng external scheduler / Action Scheduler plugin.

### **R-PAR-7 — Campaign QR-UTM phải pipeline qua Channel Gateway**
- Trang đích campaign (vd: `m.me/<page>?ref=camp_X` hoặc landing `?utm_source=...&campaign=camp_X`) **phải có 1 trong 2** mode:
  1. **Messenger ref**: webhook FB tới → adapter dò `referral.ref` → emit `crm_campaign_visit_recorded` (link contact ↔ campaign).
  2. **Web landing pixel**: shortcode `[bizcity_campaign_track campaign="camp_X"]` ghi cookie + INSERT `bizcity_crm_campaign_visits` + emit event.
- KHÔNG được trực tiếp ghi vào `wp_user_points` từ landing — phải qua `BizCity_CRM_Loyalty_Bridge::award($user_id, $points, ['source'=>'campaign:camp_X', 'event_uuid'=>...])`.

### **R-PAR-8 — Loyalty bridge là wrapper duy nhất với plugin `user-points`**
- `user-points` plugin LEGACY (Google Sheet code-based) **không được sửa**.
- Wrapper `BizCity_CRM_Loyalty_Bridge` exposes:
  - `award($user_id_or_client_id, $points, $meta)` → INSERT vào `wp_user_points` ledger + emit `crm_points_awarded`.
  - `balance($user_id_or_client_id)` → SUM ledger.
  - `history($user_id, $limit)` → trả về events từ `bizcity_twin_event_stream` filter `event_type='crm_points_awarded'`.
- Cột `client_id` (FB PSID) đã có sẵn trong `wp_user_points` (line 188 user-points.php) → bridge map `crm_contacts.external_source_id` ↔ `client_id`.

### **R-PAR-9 — Custom Attributes phải qua Definition table, không phải post_meta**
- 2 bảng: `bizcity_crm_custom_attribute_definitions` (schema) + JSONB column `additional_attributes` trên `crm_contacts` / `crm_conversations` (đã có sẵn).
- Validation tại boundary REST (`bizcity-crm/v1/...`) — fail-loud nếu `display_type=number` mà gửi string (R-EVT-7).
- KHÔNG dùng `wp_postmeta` (CRM tables không phải CPT).

### **R-PAR-10 — DDV 100% — diagnostic page 1:1 với roadmap**
- Trang `tools.php?page=bizcity-crm-sprint-diag` (đã có từ PHASE 0.32) thêm section "PHASE 0.35".
- 1 row per task `[T-Pmx.y]` — 4 cột Task / Status / Check / Evidence.
- 3 layers: Disk + Loader (`get_included_files()`) + Runtime (class_exists / route registered / hook attached).
- Live probe: rule fire mock, macro render preview, SLA check force-tick, campaign QR scan simulator.

---

## 2. Mục tiêu pattern (Bảng tổng quan = North-Star)

> Đây là **bản hợp đồng nghiệm thu** của PHASE 0.35. Mỗi row → 1 milestone. Done khi cột "Status sau" = ✓✓ trở lên + diagnostic PASS.

| # | Tính năng | Chatwoot | BizCity hôm nay | Mục tiêu PHASE 0.35 | Milestone | Ưu tiên |
|---|---|:---:|:---:|:---:|:---:|---|
| 1 | **Channel Integration UI** | ✓✓✓ 11+ | ✓ FB/Zalo/Web | ✓✓ + IG, WhatsApp Cloud, Telegram, Email IMAP wizard | M7 | HIGH |
| 2 | **Conversation status + priority** | ✓✓ | ✓ basic | ✓✓ + snoozed_until, priority, waiting_since | M1 | MED |
| 3 | **Labels / Tags** | ✓✓ | ✗ | ✓✓ palette + show_on_sidebar + filter | M3 | HIGH |
| 4 | **Custom Attributes** | ✓✓✓ | ✗ | ✓✓✓ (conv/contact/company × 8 display types) | M3 | HIGH |
| 5 | **Automation Rule Engine** | ✓✓✓ | chỉ AI autoreply | ✓✓✓ + KG-grounded action `send_kg_reply` | **M2** | **CRITICAL** |
| 6 | **Macro / Canned Responses** | ✓✓ | ✗ | ✓✓ + token KG-grounded `{{kg.answer:notebook=X}}` | M3 | HIGH |
| 7 | **Working Hours + SLA Policy** | ✓✓ | ✗ | ✓✓ per-inbox + 3 thresholds (FRT, NRT, RT) | **M4** | **CRITICAL** |
| 8 | **Reports Dashboard** | ✓✓✓ 12+ | ✗ | ✓✓ + cột "Auto vs Human vs Hybrid" (unique) | M5 | HIGH |
| 9 | **Agent KPI** | ✓✓ | ✗ | ✓✓ FRT, resolution time, capacity per user | M5 | HIGH |
| 10 | **CSAT survey** | ✓✓ | ✗ | ✓ minimal (1 question, 5-star, post-resolve) | M5 | MED |
| 11 | **Roles & Permissions** | ✓✓ | WP roles | ✓ 3 cap mới: handle_inbox · manage_rules · view_reports | M1 | MED |
| 12 | **Audit Logs** | ✓ | ✗ | ✓ via Event Stream projection (R-PAR-3) | M5 | LOW |
| 13 | **Campaigns + QR + UTM + Loyalty** | ✓✓ ongoing/oneoff | ✗ | ✓✓✓ QR generator + UTM funnel + user-points bridge | **M6** | HIGH |
| 14 | **Twin Brain / KG / Notebook** | ✗ | ✓✓✓ | giữ nguyên + expose `nb_query_kg` action block | M2 | — |

> **Cộng hưởng KG**: cột mới #14 không thêm tính năng — chỉ cần expose `nb_query_kg(notebook_id, query)` thành callable cho Automation + Macro để tận dụng não đã có. Chatwoot không có "brain" này, đó là điểm BizCity vượt mặt.

---

## 3. Kiến trúc & dòng dữ liệu

### 3.1 Class map mới (sẽ tạo trong `plugins/bizcity-twin-crm/includes/`)

```
includes/
├── class-db-installer.php                  ← MỞ RỘNG (thêm 9 bảng mới + ALTER conversations/messages)
├── automation/
│   ├── class-automation-engine.php         ← Listener subscribe events, evaluate rules
│   ├── class-rule-evaluator.php            ← Match conditions JSONB
│   ├── class-action-runner.php             ← Execute actions (send_msg, label, assign, ...)
│   └── class-action-registry.php           ← Plugin-able action types via filter
├── kg/
│   └── class-nb-query-kg.php               ← Wrapper BizCity_KG_Retriever::ask() — expose to rules/macros
├── labels/
│   └── class-label-repository.php
├── attributes/
│   └── class-custom-attr-definition.php
├── macros/
│   ├── class-macro-repository.php
│   └── class-template-renderer.php         ← R-PAR-5 token engine
├── sla/
│   ├── class-working-hours.php
│   ├── class-sla-policy.php
│   ├── class-sla-evaluator.php             ← Cron tick handler (R-PAR-6)
│   └── class-applied-sla.php
├── reports/
│   ├── class-report-builder.php            ← Query event_stream, aggregate
│   ├── class-agent-summary.php
│   ├── class-inbox-summary.php
│   ├── class-label-summary.php
│   └── class-csat-survey.php
├── campaigns/
│   ├── class-campaign-repository.php
│   ├── class-campaign-tracker.php          ← Pixel + UTM capture
│   ├── class-qr-generator.php              ← endroid/qr-code OR phpqrcode bundled
│   ├── class-loyalty-bridge.php            ← R-PAR-8 wrapper user-points
│   └── class-flow-importer.php             ← Migrate bizgpt_custom_flows → campaign + notebook
└── class-rest-controller.php               ← MỞ RỘNG (+ ~30 routes mới)
```

### 3.2 Schema diff tóm tắt

**ALTER existing**:
```sql
ALTER TABLE bizcity_crm_conversations
  ADD COLUMN priority TINYINT NOT NULL DEFAULT 1,           -- 0 low,1 med,2 high,3 urgent
  ADD COLUMN snoozed_until BIGINT NULL,
  ADD COLUMN waiting_since BIGINT NULL,
  ADD COLUMN first_reply_at BIGINT NULL,
  ADD COLUMN cached_label_list TEXT NULL,                   -- denormalized for grid filter
  ADD COLUMN sla_policy_id BIGINT NULL,
  ADD COLUMN team_id BIGINT NULL,
  ADD INDEX idx_priority_status (priority, status),
  ADD INDEX idx_waiting (waiting_since);

ALTER TABLE bizcity_crm_messages
  ADD COLUMN macro_id BIGINT NULL,
  ADD COLUMN automation_rule_id BIGINT NULL,
  ADD INDEX idx_rule (automation_rule_id);

ALTER TABLE bizcity_crm_contacts
  ADD COLUMN acquisition_source VARCHAR(64) NULL,           -- 'campaign:camp_X' | 'organic' | ...
  ADD COLUMN acquisition_meta_json LONGTEXT NULL,           -- {utm_*, referrer, landing_page, ...}
  ADD COLUMN points_balance_cache INT NOT NULL DEFAULT 0,   -- denormalized; truth in user-points
  ADD INDEX idx_acquisition (acquisition_source);
```

**CREATE new** (xem class-db-installer.php sau khi triển khai):
- `bizcity_crm_labels` (id, account_id, title UNIQUE, color, description, show_on_sidebar)
- `bizcity_crm_custom_attribute_definitions` (id, attribute_key UNIQUE, display_name, attribute_model ENUM, display_type ENUM, attribute_values_json, regex_pattern)
- `bizcity_crm_macros` (id, name, visibility ENUM('personal','global'), actions_json, created_by_id)
- `bizcity_crm_working_hours` (id, inbox_id, day_of_week, open_hour, open_minutes, close_hour, close_minutes, closed_all_day, open_all_day)
- `bizcity_crm_sla_policies` (id, name, frt_threshold_seconds, nrt_threshold_seconds, rt_threshold_seconds, only_during_business_hours)
- `bizcity_crm_applied_slas` (id, sla_policy_id, conversation_id, state ENUM('active','met','breached'), breached_at, last_evaluated_at)
- `bizcity_crm_automation_rules` (id, name, event_name, conditions_json, actions_json, active, run_count, last_run_at)
- `bizcity_crm_campaigns` (id, code UNIQUE, name, status, audience_json, notebook_id, points_reward, qr_data_url, utm_template_json, scheduled_at)
- `bizcity_crm_campaign_visits` (id, campaign_id, contact_id NULL, client_id NULL, utm_json, referrer, landing_url, occurred_at, converted_to_conversation_id NULL)

### 3.3 REST namespace mở rộng

| Method | Path | Owner | Phase |
|---|---|---|---|
| GET/POST/PUT/DEL | `/bizcity-crm/v1/labels` | M3 | Labels |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/custom-attributes` | M3 | Attrs |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/macros` | M3 | Macros |
| POST | `/bizcity-crm/v1/macros/{id}/preview` | M3 | Render token |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/automation-rules` | M2 | Rules |
| POST | `/bizcity-crm/v1/automation-rules/{id}/dry-run` | M2 | Test rule |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/working-hours` | M4 | WH |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/sla-policies` | M4 | SLA |
| GET | `/bizcity-crm/v1/reports/summary` | M5 | Top KPI |
| GET | `/bizcity-crm/v1/reports/agents` | M5 | Agent KPI |
| GET | `/bizcity-crm/v1/reports/inboxes` | M5 | Inbox KPI |
| GET | `/bizcity-crm/v1/reports/labels` | M5 | Label dist |
| GET | `/bizcity-crm/v1/reports/sla` | M5 | SLA breach |
| GET | `/bizcity-crm/v1/reports/auto-vs-human` | M5 | Trace contract dashboard |
| POST | `/bizcity-crm/v1/csat/{conversation_id}` | M5 | Submit CSAT |
| GET/POST/PUT/DEL | `/bizcity-crm/v1/campaigns` | M6 | Campaigns |
| GET | `/bizcity-crm/v1/campaigns/{id}/qr` | M6 | QR PNG/SVG |
| POST | `/bizcity-crm/v1/campaigns/{id}/track` | M6 | Pixel ingest |
| GET | `/bizcity-crm/v1/campaigns/{id}/funnel` | M6 | Funnel report |
| POST | `/bizcity-crm/v1/loyalty/award` | M6 | Award points |
| GET | `/bizcity-crm/v1/loyalty/balance/{contact_id}` | M6 | Balance |

Observability (R-IMN-3 namespace):
| Method | Path | Purpose |
|---|---|---|
| GET | `/bizcity-intent-monitor/v1/crm-rules-runs` | Rule fire history (event projection) |
| GET | `/bizcity-intent-monitor/v1/crm-sla-events` | SLA breach timeline |
| GET | `/bizcity-intent-monitor/v1/crm-campaigns-funnel` | Visit → conversation → conversion |

---

## 4. Milestones (M1 → M7)

> Mỗi milestone "Slice" tự đứng được (deployable). DDV diagnostic id format `T-P0.35.<milestone>.<task>`.

### **M1 — Foundation refactor (1 sprint)**
**Goal**: ALTER existing tables + thêm priority/snoozed/cached_label_list + 3 capabilities mới + DB installer migration safe.

| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.1.1 | DB migration script (idempotent ALTER) | column exists check |
| T-P0.35.1.2 | Add capabilities `bizcity_crm_handle_inbox` / `bizcity_crm_manage_rules` / `bizcity_crm_view_reports` to admin role | `current_user_can()` smoke |
| T-P0.35.1.3 | Conversation grid hiển thị priority + snoozed badge | DOM render check |
| T-P0.35.1.4 | REST `/conversations` filter: `?priority=high&snoozed=true` | route assertion |
| T-P0.35.1.5 | Snooze REST `POST /conversations/{id}/snooze` | event `crm_conversation_snoozed` emitted |

### **M2 — Automation Rule Engine + `nb_query_kg` (1.5 sprint) — CRITICAL**
**Goal**: Nguyên ngày Chatwoot bay sang BizCity.

| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.2.1 | Class `BizCity_CRM_Automation_Engine` subscribe 8 event names | `has_action()` × 8 |
| T-P0.35.2.2 | `BizCity_CRM_Rule_Evaluator` — JSONB condition matcher (status, label, content regex, custom_attr, time_in_business_hours) | unit test 20 cases |
| T-P0.35.2.3 | `BizCity_CRM_Action_Registry` — register 12 action types via filter `bizcity_crm_register_actions` | filter has 12 items |
| T-P0.35.2.4 | Action: `add_label`, `remove_label`, `assign_agent`, `assign_team`, `change_priority`, `change_status`, `snooze`, `resolve`, `send_message` | dry-run UI green |
| T-P0.35.2.5 | Action `send_kg_reply` — dùng `nb_query_kg` + `BizCity_LLM_Router` (R-CRM-3, R-PAR-4) | real LLM round-trip |
| T-P0.35.2.6 | Action `add_private_note`, `send_webhook_event` | webhook receiver echo |
| T-P0.35.2.7 | REST CRUD `/automation-rules` + dry-run endpoint | 5 routes registered |
| T-P0.35.2.8 | React tab "Automation" trong InboxLayout (rule builder UI — condition/action cards) | bundle size delta |
| T-P0.35.2.9 | Wire `nb_query_kg(notebook_id, query)` callable cho cả waic_twf workflow blocks (đóng gap PHASE 0.31 §S1-S4) | block list contains it |
| T-P0.35.2.10 | Causal chain: rule fire emit `crm_rule_fired` với `parent_event_uuid` của trigger event | event_stream join check |

### **M3 — Labels · Custom Attributes · Macros (1 sprint)**
| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.3.1 | `bizcity_crm_labels` table + REST CRUD + label palette UI (12 màu preset) | route + UI smoke |
| T-P0.35.3.2 | Label assign/unassign trong ConversationDrawer (multi-select chip) | event `crm_label_assigned` |
| T-P0.35.3.3 | Sidebar filter "Labels" reuse Chatwoot UX (collapse list `show_on_sidebar=true`) | DOM count |
| T-P0.35.3.4 | `bizcity_crm_custom_attribute_definitions` table + REST CRUD | 5 routes |
| T-P0.35.3.5 | ContactDrawer + ConversationDrawer hiển thị custom attrs theo `display_type` (text/number/date/list/checkbox/link/currency/percent) | render 8 types |
| T-P0.35.3.6 | Boundary validation R-PAR-9 (regex_pattern + display_type cast) | 400 on bad input |
| T-P0.35.3.7 | `BizCity_CRM_Template_Renderer` (R-PAR-5) hỗ trợ 5 token + lazy `{{kg.answer:notebook=X}}` | unit test 10 cases |
| T-P0.35.3.8 | `bizcity_crm_macros` table + REST CRUD + Macro picker trong Composer | macro list dropdown |
| T-P0.35.3.9 | Macro chain action (như Chatwoot): mỗi macro chứa `actions_json` array → reuse Action_Registry M2 | reuse confirmed |

### **M4 — Working Hours + SLA Policy (1 sprint) — CRITICAL**
| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.4.1 | `bizcity_crm_working_hours` table + REST + UI per-inbox 7-day grid | 7 rows seeded per inbox |
| T-P0.35.4.2 | Helper `BizCity_CRM_Working_Hours::is_open(inbox_id, ts)` + timezone aware | unit test 12 cases |
| T-P0.35.4.3 | `bizcity_crm_sla_policies` table + REST + UI form (3 thresholds + only_business_hours toggle) | route + form save |
| T-P0.35.4.4 | `bizcity_crm_applied_slas` — apply policy khi conversation tạo (rule action mới `apply_sla`) | row insert |
| T-P0.35.4.5 | `BizCity_CRM_SLA_Evaluator` cron 1' (R-PAR-6) → emit `crm_sla_breached` | force-tick button + breach event |
| T-P0.35.4.6 | Conversation row badge "⏱ FRT 5m" / "🔴 BREACH NRT" | DOM check |
| T-P0.35.4.7 | Automation rule trigger mới `crm_sla_breached` (M2 wiring) | rule fires on breach |

### **M5 — Reports Dashboard + CSAT + Audit (1.5 sprint)**
| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.5.1 | `BizCity_CRM_Report_Builder` — query `bizcity_twin_event_stream` filter `crm_*` + group by day/agent/inbox/label | 6 metrics |
| T-P0.35.5.2 | Daily rollup cron → row `event_type='crm_daily_rollup'` (R-PAR-3 projection) | rollup row exists |
| T-P0.35.5.3 | React route `/reports/overview` — 6 KPI card (FRT, RT, Resolutions, Open count, SLA breach %, CSAT avg) | bundle render |
| T-P0.35.5.4 | `/reports/agents` — table per WP user (FRT, RT, count, capacity util) | sortable cols |
| T-P0.35.5.5 | `/reports/inboxes` + `/reports/labels` | render |
| T-P0.35.5.6 | **`/reports/auto-vs-human`** — line chart % responder_kind theo ngày (unique BizCity, dùng trace contract §0.34 §0.2) | 4 series visible |
| T-P0.35.5.7 | CSAT: tự động send post-resolve qua channel (1 question 1-5⭐) — tag bubble `kind='csat_survey'` | message inserted |
| T-P0.35.5.8 | CSAT response handler khi user trả `1-5` → INSERT `crm_csat_responses_view` projection + emit event | event seen |
| T-P0.35.5.9 | Intent Monitor tab "CRM Audit" (R-IMN-1) — list 50 sự kiện gần nhất, filter by event_type | tab registered via filter |

### **M6 — Campaigns + QR + UTM + Loyalty (1.5 sprint) — UNIQUE BIZCITY**
**Goal**: Học UX Chatwoot Campaign (ongoing/oneoff), nhưng nội dung lấy từ `bizgpt-custom-flows` (kịch bản keyword) + thêm QR/UTM (mới) + cộng điểm qua `user-points` bridge.

| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.6.1 | `bizcity_crm_campaigns` + `_campaign_visits` tables | installer creates |
| T-P0.35.6.2 | REST CRUD `/campaigns` — fields: code, notebook_id, points_reward, utm_template_json, scheduled_at, audience_json | 5 routes |
| T-P0.35.6.3 | QR generator endpoint `/campaigns/{id}/qr.png` — embed `https://site/?ref=camp_X&utm_source=qr&utm_medium=offline&utm_campaign={code}` | PNG bytes returned |
| T-P0.35.6.4 | Landing pixel shortcode `[bizcity_campaign_track]` (R-PAR-7 mode 2) — set cookie + INSERT visit + emit `crm_campaign_visit_recorded` | DB row + event |
| T-P0.35.6.5 | Facebook adapter (PHASE 0.31) đọc `referral.ref=camp_*` → emit cùng event (R-PAR-7 mode 1) | mock webhook test |
| T-P0.35.6.6 | Auto-link visit ↔ contact: khi conversation tạo → match cookie/PSID → UPDATE `acquisition_source='campaign:camp_X'` + `acquisition_meta_json={utm_*}` | join check |
| T-P0.35.6.7 | Auto-trigger automation rule khi visit chuyển thành conversation → AI chào theo notebook_id của campaign | rule fires + AI bubble |
| T-P0.35.6.8 | `BizCity_CRM_Loyalty_Bridge::award()` (R-PAR-8) — wrap `wp_user_points` ledger + emit `crm_points_awarded` | balance += N |
| T-P0.35.6.9 | Action mới `award_points` trong Action_Registry — admin set "khi conversation_resolved + label=purchase → award 50 pts" | rule + bridge integration |
| T-P0.35.6.10 | `BizCity_CRM_Flow_Importer` — wizard "Import từ bizgpt_custom_flows" — mỗi row → 1 macro + 1 automation rule (event=`crm_message_received`, condition `content matches keyword`) + optional notebook_id | importer dry-run preview |
| T-P0.35.6.11 | Funnel report `/campaigns/{id}/funnel` — Visits → Conversations → Resolved → Points Awarded — bar chart | 4-step funnel renders |
| T-P0.35.6.12 | React route `/campaigns` — list + create (with QR preview + UTM builder + notebook picker + reward field) | bundle size delta |

### **M7 — Channel Integration UI hoàn thiện (1 sprint)**
| Task | Deliverable | Diag |
|---|---|---|
| T-P0.35.7.1 | Wizard "Add Inbox" — chọn channel type → render adapter-specific form | UI flow smoke |
| T-P0.35.7.2 | Adapter Instagram (Meta Graph + Comments) | webhook echo |
| T-P0.35.7.3 | Adapter WhatsApp Cloud API (template message + free-form 24h window) | send test msg |
| T-P0.35.7.4 | Adapter Telegram Bot (Bot API) | webhook echo |
| T-P0.35.7.5 | Adapter Email IMAP (Cron poll + threading by Message-ID) | inbound row |
| T-P0.35.7.6 | Web Widget snippet generator (script + design preset) | snippet renders |
| T-P0.35.7.7 | Channel "Health" indicator trong Inbox list (last successful inbound, last error) | UI badge |

---

## 5. Roadmap timeline & dependencies

```
Sprint 1   M1 ████████ (foundation)
Sprint 2   M2 ████████████ (automation engine — CRITICAL, blocks M3.9, M4.7, M6.7, M6.9)
Sprint 3       M3 ████████ (labels/attrs/macros — needs M2 Action_Registry)
Sprint 4           M4 ████████ (WH + SLA — emits event consumed by M2 engine)
Sprint 5               M5 ████████████ (reports — reads event stream from M2/M3/M4)
Sprint 6               M5 ████  M6 ████████████ (campaigns — depends on M2 + M3 + Loyalty bridge)
Sprint 7                       M6 ████  M7 ████████ (channels)
```

**Critical path**: M1 → M2 → (M3 ∥ M4) → M5 → M6 → M7.
**M6 + M7 có thể chạy song song** sau khi M2/M3 done.

---

## 6. Phản biện & quyết định kiến trúc

### 6.1 "Tại sao không port nguyên Chatwoot Rails app?"
- **Trả lời**: 90 % giá trị Chatwoot là **schema + UX + rule semantics**, KHÔNG phải Rails code. Port code = ôm Postgres + Sidekiq + Redis + Ruby runtime (không có sẵn trong WP host LiteSpeed). Chi phí infra > giá trị. Schema port nguyên — code reimplement bằng PHP idiomatic theo style WP plugin.

### 6.2 "Reports đáng lẽ cache vào bảng riêng cho nhanh?"
- **Phản biện**: tạo bảng cache vi phạm R-EVT-2. Giải pháp đúng: **daily rollup row trong `bizcity_twin_event_stream`** (R-PAR-3) — đây là projection hợp lệ (R-EVT-3). Query realtime cho today, rollup cho > 24h. Đủ nhanh trên dữ liệu CSKH (~10k events/ngày).

### 6.3 "Tại sao không bỏ `bizgpt-custom-flows` và viết lại trong CRM?"
- **Trả lời**: Plugin LEGACY có dữ liệu thật (hàng trăm keyword tiếng Việt + reminder đã chạy production). Migration risk cao. **Giải pháp**: import wizard (T-P0.35.6.10) chuyển 1 row → 1 macro + 1 rule, giữ plugin gốc đến khi tất cả flows được migrate xong → mới deactivate.

### 6.4 "user-points là plugin rời rạc, sao không tích hợp sâu?"
- **Trả lời**: Tích hợp sâu = sửa plugin gốc = breakage cho user-facing features đã chạy (Google Sheet code redemption). Wrapper `Loyalty_Bridge` (R-PAR-8) là Strangler Fig pattern — tất cả CRM code chỉ gọi Bridge, plugin legacy không bị động vào. Khi đủ thời gian → reimplement nội bộ Bridge để bỏ plugin gốc.

### 6.5 "Automation engine có overlap với `waic_twf_*` workflow của bizcity-twin-ai?"
- **Trả lời**: Có, nhưng **2 bài toán khác**:
  - `waic_twf_*` = visual flow editor + multi-trigger workflow (cron, form, event) → general automation.
  - `BizCity_CRM_Automation_Engine` = **inbox-scoped rules** (assign, label, snooze, SLA action) — UX kiểu Chatwoot rule list, không node graph.
  - Cộng hưởng: action `send_kg_reply` của CRM rule chỉ là wrapper gọi xuống `waic_twf` block `nb_query_kg + ai_generate` → **share executor, khác UI**. KHÔNG duplicate logic LLM.

### 6.6 "Campaign QR + UTM có cần pixel server riêng?"
- **Trả lời**: Không. Tận dụng:
  1. WordPress `init` hook để parse `?ref=camp_*` + `?utm_*` từ `$_GET` → set cookie 30 ngày.
  2. Shortcode `[bizcity_campaign_track]` — invisible 1×1 GIF cho landing page nào không SSR (vd: builder Elementor).
  3. FB Messenger ref đã native trong webhook (không cần pixel).

---

## 7. Risk register

| Risk | Mức | Mitigation |
|---|---|---|
| Migration ALTER table fail trên production | HIGH | Idempotent script, dry-run trên staging, rollback SQL prepared, snapshot pre-deploy |
| Automation rule infinite loop (rule fire event → fire same rule) | MED | Recursion guard: `rule.last_run_at < 1s` → skip; max depth 3 trong cùng request_id |
| SLA cron overlap (1' tick chậm hơn 1') | MED | Lock via `wp_options` row `bizcity_crm_sla_lock` + 90s TTL |
| LLM call trong rule action gây timeout HTTP request | HIGH | Action `send_kg_reply` PHẢI async qua WP cron (`schedule_single_event` +0s) — không block webhook response |
| Loyalty bridge double-award (rule fire 2x) | HIGH | Bridge dedupe bằng `event_uuid` UNIQUE trong meta — second insert no-op |
| Campaign QR scan từ bot/scraper inflate visits | LOW | Rate limit per IP/UA; bỏ visit nếu không có cookie consent + `Sec-Fetch-Dest=image` |
| Custom attribute schema drift (admin đổi `display_type` sau khi đã lưu data) | MED | UI cảnh báo + script migration tool (cast best-effort + log fail) |

---

## 8. DDV — Diagnostic page (R-PAR-10)

`tools.php?page=bizcity-crm-sprint-diag` thêm tab **"PHASE 0.35"**:
- 7 collapsible sections (M1..M7)
- Mỗi task 1 row: ✅ Disk · ✅ Loader · ✅ Runtime · 🔘 Live Probe button
- Live probes:
  - **M2**: "Fire mock rule" — chọn rule + paste mock event payload → engine evaluate + show actions resolved
  - **M3**: "Render macro preview" — chọn macro + contact_id → output text
  - **M4**: "Force SLA tick" — bypass cron, evaluate now
  - **M5**: "Replay event range" — pick date range, recompute report builder
  - **M6**: "Simulate QR scan" — gọi pixel endpoint với mock UTM → assert visit row + event
  - **M7**: "Adapter ping" — mỗi adapter có method `ping()` (return last successful inbound)

### 8.1 Progress check (tiến độ pass)

> **Live source of truth**:
> - WP admin → **Tools → BizCity CRM Diag** (`tools.php?page=bizcity-crm-sprint-diag`) — bảng 4 cột Task/Status/Check/Evidence với 3 layer (Disk + Loader + Runtime). Đây là nơi nhanh nhất để soi *chỗ nào đang lỗi*.
> - Wave-level board: [PHASE-0.35-WAVES.md §Progress board](PHASE-0.35-WAVES.md) (cập nhật mỗi sprint).
>
> Bảng dưới là snapshot **milestone-level** — chấm trạng thái roll-up từ các T-P0.35.* tasks. Click vào sprint-diag để xem chi tiết từng row.

**Legend**: ⚪ chưa bắt đầu · 🟡 đang làm (≥1 task done, chưa hết) · ✅ done (tất cả task PASS) · ❌ fail (có task FAIL trên diag)

| Milestone | Scope | Status | Done / Total tasks | Diag tab | Ghi chú gần nhất |
|---|---|:---:|:---:|---|---|
| **M1** Foundation | ALTER tables · capabilities · grid filters · snooze | ✅ | 5 / 5 | `T-P0.35.1.*` | DB v1.2.0 + 3 caps + snooze REST live (2026-05-11). |
| **M2** Automation Engine | Listener · evaluator · 12 actions · `nb_query_kg` · REST · React UI | 🟡 | 6 / 10 | `T-P0.35.2.*` | Backend done (W1+W2+W3+W5 + diag); KG action (W4) + React UI (W6) deferred. DB v1.3.0 + 11 events + 10 actions + 4 REST routes (2026-05-11). |
| **M3** Labels · Attrs · Macros | Label palette · custom attribute defs · macro template renderer | 🟡 | 7 / 9 | `T-P0.35.3.*` | Backend hoàn tất (DB + Repository + REST + Validator + Renderer + Macro_Run); chỉ còn React UI sidebar+composer (W2 + W5 FE). |
| **M4** Working Hours + SLA | WH grid · SLA policy · cron evaluator · breach event | 🟡 | 6 / 7 | `T-P0.35.4.*` | **CRITICAL** — backend done: WH per-inbox grid + Working_Hours::check() + SLA policies CRUD + applied_slas state-machine + 60s cron tick + apply_sla action + crm_sla_breached/met events wired to Engine. UI badges (W4) deferred to FE sprint.
| **M5** Reports + CSAT + Audit | Report builder · daily rollup · KPI cards · auto-vs-human · CSAT · audit tab | ⚪ | 0 / 9 | `T-P0.35.5.*` | Đọc projection event_stream từ M2/M3/M4. |
| **M6** Campaigns + QR + UTM + Loyalty | Campaign CRUD · QR · pixel · auto-link visit · loyalty bridge · flow importer · funnel | ⚪ | 0 / 12 | `T-P0.35.6.*` | UNIQUE BIZCITY — wrap user-points (R-PAR-8). |
| **M7.W1-W4** Channel UI core | Wizard · IG · WhatsApp Cloud · Telegram · Email IMAP · Web widget · Health badge | 🟡 | 6 / 7 | `T-P0.35.7.1..7` | Done ngoại trừ Health badge polish. |
| **M7.W5** Bot Plugin Sync | 3 bridges (FB/Zalo/Google) · adapter sync · Gmail OAuth provider · diag rows | ✅ | 5 / 5 | `T-P0.35.7.5.*` | Bridge + diag rows live (2026-05-11). |

**Tổng quan**: 35 / 64 tasks done (~55 %). M4 backend xong: per-inbox Working_Hours grid (default Mon-Fri 9-18, hold overnight crossover) + SLA policies (FRT/NRT/RT minutes · only_during_business_hours flag) + applied_slas state machine (active→breached→met→cancelled) + 60s cron tick (lock-guarded, 90s TTL) + `apply_sla` automation action + `crm_sla_breached` / `crm_sla_met` events subscribed by Engine → rule reactor closed-loop. Còn lại: React UI (M3.W2 sidebar + W5 composer picker + M4.W4 SLA badges — defer cùng M2.W6).

**Cách dùng để soi lỗi nhanh**:
1. Mở `tools.php?page=bizcity-crm-sprint-diag` trên WP admin.
2. Filter cột **Status = FAIL** → xem cột Evidence để biết:
   - Disk = NO → file chưa tồn tại trên đĩa.
   - Loader = NO → file có nhưng chưa được `require` trong `bootstrap.php`.
   - Class = NO → file load nhưng class chưa định nghĩa (typo / namespace sai).
   - `is_available() = no` → sibling plugin chưa active (vd: `bizgpt-tool-google` chưa cài).
3. Đối với row **SKIP** (vàng): file + class OK nhưng dependency runtime chưa sẵn — không phải lỗi code, chỉ cần activate plugin liên quan.
4. Live probe button (cột phải): chạy 1 vòng end-to-end (gửi mock event / fire rule / send test message) để xác minh runtime.

---

## 9. Out-of-scope (defer to PHASE 0.36+)

- Multi-tenant Inbox (per-team isolation)
- Voice / Video channel (Twilio, Daily.co)
- Multi-language CSAT survey
- Advanced segment query builder (kiểu Chatwoot Contact Segments với boolean groups)
- Mobile app native (chỉ responsive web)
- Help Center portal (đã có KGHub — out of scope)

---

## 10. Acceptance criteria cho PHASE 0.35 = DONE

1. ✅ Tất cả 14 row trong **§2 Bảng tổng quan** đạt mục tiêu cột "Mục tiêu PHASE 0.35".
2. ✅ Diagnostic page tab "PHASE 0.35" — **100% task PASS** ở cả 3 layer (Disk + Loader + Runtime).
3. ✅ Demo end-to-end script S7 (PHASE 0.31): scan QR → landing → Messenger → AI reply theo notebook campaign → resolve → tự cộng 50 điểm → contact có `acquisition_source='campaign:camp_X'` + `points_balance_cache=50`.
4. ✅ 1 báo cáo "Auto vs Human vs Hybrid" hiển thị 4 series với data ≥ 7 ngày.
5. ✅ 1 SLA policy active + 1 conversation breach → automation rule fire `assign_to_supervisor` → notification.
6. ✅ Migration wizard import ≥ 10 rows từ `wp_bizgpt_custom_flows` thành macro + rule + (optional) notebook link.

---

**End of PHASE 0.35.**
