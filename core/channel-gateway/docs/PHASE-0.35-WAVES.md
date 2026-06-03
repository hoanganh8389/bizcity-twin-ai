# PHASE 0.35 — Wave Detail & Diagnostics

> **Companion** của [PHASE-0.35-CRM-PARITY-CHATWOOT.md](PHASE-0.35-CRM-PARITY-CHATWOOT.md)
> **Mục đích**: chia nhỏ M1→M7 thành **wave** (sub-sprint 0.5–1 ngày), kèm **bảng class diagnostics** để trace/deblog từng chặng. Mỗi class có:
> - **Disk** path file
> - **Loader** entry trong `bootstrap.php`
> - **Runtime** assertion (class_exists / hook attached / route registered / table column / option)
> - **Live probe** button trong `tools.php?page=bizcity-crm-sprint-diag` tab "PHASE 0.35"
> - **Done marker** = badge ✅ trên row diagnostic + commit tag `[T-P0.35.<m>.<wave>.<task>] DONE`

---

## 0. Quy ước chung

### 0.1 Trace ID format

```
T-P0.35.<M>.<W>.<T>      # M=milestone 1-7, W=wave 1-N, T=task 1-N
ví dụ: T-P0.35.2.3.4 = M2 (Automation), Wave 3, Task 4
```

### 0.2 Class diagnostics row schema

| Cột | Kiểu | Ví dụ |
|---|---|---|
| `task_id` | string | `T-P0.35.2.1.1` |
| `class_name` | string | `BizCity_CRM_Automation_Engine` |
| `disk_path` | path | `includes/automation/class-automation-engine.php` |
| `loader_check` | code | `in_array(..., get_included_files(), true)` |
| `runtime_check` | code | `class_exists() && method_exists()` |
| `hook_check` | code | `has_action('crm_message_received', ...)` |
| `route_check` | code | `rest_get_server()->get_routes()['/bizcity-crm/v1/...']` |
| `live_probe` | button | "Fire mock event" → return JSON evidence |
| `evidence_json` | text | last probe output, ≤ 4KB |
| `status` | enum | `not_started` · `in_progress` · `pass` · `fail` · `skip` |
| `last_run_at` | timestamp | |
| `commit_sha` | string | git short hash khi mark DONE |

### 0.3 Deblog convention

Mỗi class mới phải gọi `BizCity_CRM_Debug::log($scope, $msg, $ctx_array)` ở các điểm sau:
- **enter** method public quan trọng (label `enter`)
- **exit** với kết quả (label `exit_ok` / `exit_fail`)
- **branch** quyết định business (vì sao skip, vì sao retry)

`BizCity_CRM_Debug` là **wrapper mỏng** quanh `BizCity_Twin_Debug_Logger` (đã có), filter theo `WP_DEBUG && defined('BIZCITY_CRM_DEBUG')`. KHÔNG tạo log file riêng — append vào `wp-content/debug.log`.

### 0.4 Done marker

```
✅ Pass cả Disk + Loader + Runtime + Live Probe ≥ 1 lần trong 24h
🟡 Disk + Loader OK, Runtime fail (chưa wire)
🔴 Disk OK, Loader miss (chưa require)
⚪ Chưa tạo file
🟣 Skip (không apply cho env này)
```

---

# 🧱 M1 — Foundation Refactor

> **Wave**: 4 · **Estimated**: 1 sprint · **Risk**: HIGH (ALTER table production)

## M1.W1 — DB Migration safe

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.1.1.1 | `includes/class-db-installer.php` (mở rộng) | `BizCity_CRM_DB_Installer::migrate_v0_35()` | "Run dry-run migration" — show diff, no exec |
| T-P0.35.1.1.2 | same | `add_col_if_missing($tbl, $col, $def)` helper idempotent | "Run real migration" — exec + show row count delta |
| T-P0.35.1.1.3 | same | `BizCity_CRM_DB_Installer::version()` — bump option `bizcity_crm_schema_version=0.35` | option exists |
| T-P0.35.1.1.4 | same | Rollback SQL file `migrations/0.35.rollback.sql` | file exists + downgrade button (admin only) |

**Class diagnostics**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_DB_Installer` | ✓ existing | bootstrap.php L?? | `method_exists('migrate_v0_35')` | dry-run returns JSON of pending ALTERs |

**Rules check**: R-CRM-1 (snapshot), R-PAR-1 (no log table). 

**Deblog scopes**: `db.migrate` (enter/exit per ALTER).

---

## M1.W2 — Capabilities & Roles

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.1.2.1 | `includes/class-capability-installer.php` (NEW) | `BizCity_CRM_Capability_Installer::install()` add 3 caps to `administrator` | dump caps list |
| T-P0.35.1.2.2 | same | `current_user_can('bizcity_crm_handle_inbox')` smoke | true cho admin, false cho subscriber |
| T-P0.35.1.2.3 | same | `BizCity_CRM_Capability_Installer::uninstall()` cho rollback | reversible |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Capability_Installer` | NEW | bootstrap require | `class_exists` + `wp_roles()->is_role()` | "Test cap matrix" → table 3 caps × 4 roles |

---

## M1.W3 — Conversation grid: priority + snoozed

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.1.3.1 | `includes/class-rest-controller.php` | filter args `priority`, `snoozed`, `waiting_since_gt` | `?priority=high` returns subset |
| T-P0.35.1.3.2 | `frontend/src/panels/ConversationList.tsx` | `<PriorityBadge level={...} />` + `<SnoozedClock until={...} />` | DOM mount snapshot |
| T-P0.35.1.3.3 | `frontend/src/api/crmApi.ts` | RTK query `getConversations({priority, snoozed})` cache key | cache key shape |

**Diagnostic**:

| Class/Component | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_REST_Controller::list_conversations()` | existing | autoload | route `/conversations` accepts new args | hit route returns filter-aware count |
| `<PriorityBadge>` | NEW tsx | iife bundle | window scan `.bzc-priority-badge` | screenshot via Playwright (optional) |

---

## M1.W4 — Snooze action + event

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.1.4.1 | REST | `POST /conversations/{id}/snooze` body `{until_ts}` | route exists |
| T-P0.35.1.4.2 | `includes/class-event-emitter.php` | emit `crm_conversation_snoozed` with `parent_event_uuid` | event_stream row inserted |
| T-P0.35.1.4.3 | cron `bizcity_crm_unsnooze_tick` 5' | `BizCity_CRM_Snooze_Reaper::tick()` flip back to `open` when `snoozed_until <= now` | force-tick button |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Snooze_Reaper` | NEW | bootstrap | `wp_next_scheduled('bizcity_crm_unsnooze_tick')` | "Force tick" → returns N flipped |

**M1 DONE checklist**: 14 task ✅ · 4 class probe pass · schema_version=0.35 in option · diagnostic tab "PHASE 0.35 / M1" all green.

---

# 🤖 M2 — Automation Rule Engine + `nb_query_kg` (CRITICAL)

> **Wave**: 6 · **Estimated**: 1.5 sprint · **Risk**: MED (rule loop, LLM timeout)
> **Blocks**: M3.W4 (macro reuse Action_Registry), M4.W3 (SLA breached event), M6.W3 (campaign rule)
>
> **🟢 STATUS (PHASE 0.35 backend pass)**: W1 ✅ · W2 ✅ · W3 ✅ (10 actions; +1 `remove_label` over plan) · W4 ⏳ (KG action — defer pending Brain hand-off contract) · W5 ✅ (4 routes: list/create/get/update/delete + dry-run + actions catalog) · W6 ⚪ (React Rule Builder — defer to FE sprint).
>
> **Backend done marker** (T-P0.35.2.1 → T-P0.35.2.6 in `tools.php?page=bizcity-crm-sprint-diag`):
> - `automation_rules` table + `idx_event_active` + `idx_inbox`
> - `BizCity_CRM_Automation_Engine` subscribes 11 events (incl. M3/M4/M6-ready hooks)
> - `BizCity_CRM_Rule_Evaluator` (12 operators · dot-notation `custom_attr.*`)
> - `BizCity_CRM_Action_Registry` (10 built-ins + filter `bizcity_crm_register_actions`)
> - `BizCity_CRM_Action_Runner` (recursion guard depth ≤ 3 · emits `crm_rule_action_executed` chained via `parent_event_uuid`)
> - REST: `GET/POST /automation-rules` · `GET/PUT/DELETE /automation-rules/{id}` · `POST /automation-rules/{id}/dry-run` · `GET /automation-actions`
> - Permission: `bizcity_crm_manage_rules` (admin auto-granted via `BizCity_CRM_Capabilities`)

## M2.W1 — Engine skeleton + event subscription

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.2.1.1 | `includes/automation/class-automation-engine.php` | `BizCity_CRM_Automation_Engine::boot()` subscribe 8 events | `has_action()` × 8 returns true |
| T-P0.35.2.1.2 | same | `on_event($event_name, $payload, $event_uuid)` → fetch active rules | unit test fan-in |
| T-P0.35.2.1.3 | `includes/automation/class-rule-repository.php` | `find_by_event($event_name)` + cache 60s | cache hit ratio probe |
| T-P0.35.2.1.4 | recursion guard | `Engine::$depth_per_request_id` increment, max 3 | force overflow → emit warning |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Automation_Engine` | NEW | bootstrap | 8 hooks attached | "List subscribers" returns 8 rows |
| `BizCity_CRM_Rule_Repository` | NEW | bootstrap | `wp_cache_get('crm_rules_byevent_*')` works | cache flush button |

**Rules check**: R-PAR-2 (Event Bus), R-PAR-4 (listener pattern), R-EVT-6 (parent_event_uuid).
**Deblog**: `automation.engine` enter/exit per event_name; `automation.engine.depth` warning when ≥ 3.

---

## M2.W2 — Condition evaluator (JSONB)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.2.2.1 | `includes/automation/class-rule-evaluator.php` | `match($conditions, $context)` | unit test 20 cases (in PHPUnit-style array) |
| T-P0.35.2.2.2 | same | operators: `equals`, `not_equals`, `contains`, `regex`, `in`, `not_in`, `gt`, `lt`, `is_empty`, `is_present` | each operator 2 case |
| T-P0.35.2.2.3 | same | scopes: `conversation`, `message`, `contact`, `inbox`, `custom_attr.*` | scope dispatch table |
| T-P0.35.2.2.4 | same | composition `AND`/`OR` (Chatwoot uses flat array AND-only; we extend OR via `{op: "or", children: [...]}`) | nested case |
| T-P0.35.2.2.5 | special: `time_in_business_hours(inbox_id)` (depends M4.W1, mock interface ready) | mock returns true/false |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Rule_Evaluator` | NEW | bootstrap | static class loaded | "Test condition" form: paste JSON cond + JSON context → bool result |

---

## M2.W3 — Action Registry + 9 base actions

| Task | File | Action code | Class/Function |
|---|---|---|---|
| T-P0.35.2.3.1 | `class-action-registry.php` | — | `register($code, $callable, $schema)` + filter `bizcity_crm_register_actions` |
| T-P0.35.2.3.2 | `actions/class-action-add-label.php` | `add_label` | execute(payload, action_args) |
| T-P0.35.2.3.3 | `actions/class-action-remove-label.php` | `remove_label` | |
| T-P0.35.2.3.4 | `actions/class-action-assign-agent.php` | `assign_agent` | round-robin if `agent_id='auto'` |
| T-P0.35.2.3.5 | `actions/class-action-assign-team.php` | `assign_team` | |
| T-P0.35.2.3.6 | `actions/class-action-change-priority.php` | `change_priority` | |
| T-P0.35.2.3.7 | `actions/class-action-change-status.php` | `change_status` | open/pending/resolved/snoozed |
| T-P0.35.2.3.8 | `actions/class-action-snooze.php` | `snooze` | reuse M1.W4 |
| T-P0.35.2.3.9 | `actions/class-action-add-private-note.php` | `add_private_note` | calls existing `/notes` endpoint internal |
| T-P0.35.2.3.10 | `actions/class-action-send-webhook.php` | `send_webhook_event` | wp_remote_post async |

**Diagnostic table** (1 row per action):

| Action code | Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|---|
| `add_label` | `BizCity_CRM_Action_Add_Label` | NEW | bootstrap | registered in registry | "Run" with mock → label assigned in DB |
| `remove_label` | `BizCity_CRM_Action_Remove_Label` | … | … | … | … |
| (× 8 còn lại) | … | … | … | … | … |
| `send_webhook_event` | `BizCity_CRM_Action_Send_Webhook` | … | … | … | hit local httpbin echo URL |

**Rules check**: R-PAR-2 emit `crm_rule_fired` + `crm_<action>_executed` per action.

---

## M2.W4 — `nb_query_kg` callable + `send_kg_reply` action

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.2.4.1 | `includes/kg/class-nb-query-kg.php` | `BizCity_CRM_NB_Query_KG::ask($notebook_id, $query, $opts)` wrapper around `BizCity_KG_Retriever::ask()` | live ask + cite chunks |
| T-P0.35.2.4.2 | same | normalized return shape `{answer, sources[], notebook_id, took_ms}` | shape assertion |
| T-P0.35.2.4.3 | `actions/class-action-send-kg-reply.php` | action `send_kg_reply` — call NB_Query_KG → call `BizCity_LLM_Router->chat()` → push outbound via `BizCity_Gateway_Sender` with `responder_kind='auto'` + `character_id=notebook.character_id` | end-to-end probe |
| T-P0.35.2.4.4 | async wrapper | `wp_schedule_single_event(time(), 'bizcity_crm_run_send_kg_reply', [$args])` — KHÔNG block webhook (Risk §7) | hook scheduled, fires within 60s |
| T-P0.35.2.4.5 | causal chain | LLM call `parent_event_uuid` = `crm_message_received.event_uuid` | event_stream JOIN proves chain |
| T-P0.35.2.4.6 | waic_twf bridge | register `nb_query_kg` block trong `core/workflow-blocks/` (đóng PHASE 0.31 §S1-S4) | block list contains it |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_NB_Query_KG` | NEW | bootstrap | `class_exists` + retriever reachable | "Ask test" notebook=1 query="hi" → JSON |
| `BizCity_CRM_Action_Send_KG_Reply` | NEW | registry | action code `send_kg_reply` in list | "Dry-run" returns generated text without sending |
| Block `nb_query_kg` (workflow) | NEW under waic_twf | filter `waic_twf_blocks` | block in list | execute block standalone |

**Rules check**: R-CRM-3 (Brain hand-off via Event Bus + parent_event_uuid), R-GW-1 (no direct provider), R-PAR-4 (LLM action via Router).
**Deblog**: `automation.kg_reply` with `notebook_id`, `tokens_in/out`, `latency_ms`, `event_uuid`.

---

## M2.W5 — REST CRUD + dry-run

| Task | File | Endpoint | Probe |
|---|---|---|---|
| T-P0.35.2.5.1 | REST | `GET /automation-rules` | route + 5 sample rows |
| T-P0.35.2.5.2 | REST | `POST /automation-rules` (validate conditions JSON + actions JSON) | bad JSON → 400 |
| T-P0.35.2.5.3 | REST | `PUT /automation-rules/{id}` | toggle active flag |
| T-P0.35.2.5.4 | REST | `DELETE /automation-rules/{id}` | row gone + soft delete log event |
| T-P0.35.2.5.5 | REST | `POST /automation-rules/{id}/dry-run` body `{mock_event_payload}` → returns evaluator + actions resolved (no execute) | preview JSON |

**Diagnostic**:

| Route | Auth | Schema | Probe |
|---|---|---|---|
| `/automation-rules*` | cap `bizcity_crm_manage_rules` | JSON schema validated | dry-run with sample payload returns expected actions |

---

## M2.W6 — React Rule Builder UI

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.2.6.1 | `frontend/src/routes/automation/RulesList.tsx` | grid + toggle active | DOM scan |
| T-P0.35.2.6.2 | `frontend/src/routes/automation/RuleEditor.tsx` | event picker + condition cards (drag) + action cards | screenshot |
| T-P0.35.2.6.3 | `frontend/src/routes/automation/ConditionCard.tsx` | scope · operator · value (with custom_attr lookup) | render 5 operators |
| T-P0.35.2.6.4 | `frontend/src/routes/automation/ActionCard.tsx` | dynamic schema per action_code | render 11 actions |
| T-P0.35.2.6.5 | "Test rule" panel — paste mock payload → call dry-run | response shown |

**Diagnostic** (FE):

| Component | Mount selector | Bundle size delta | Probe |
|---|---|---|---|
| `RulesList` | `#bzc-automation-root` | < 30 KB add | "Open page" → ≥ 1 row visible |

**M2 DONE checklist**: 6 wave ✅ · 11 actions probe pass · 1 KG-grounded reply produced live · causal chain JOIN returns row · React UI renders rule editor.

---

# 🏷️ M3 — Labels · Custom Attributes · Macros

> **Wave**: 5 · **Estimated**: 1 sprint · **Risk**: LOW

## M3.W1 — Labels CRUD + assign

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.3.1.1 | `class-db-installer.php` | CREATE `bizcity_crm_labels` | table exists |
| T-P0.35.3.1.2 | `includes/labels/class-label-repository.php` | `find_all`, `find($id)`, `create`, `update`, `delete` | CRUD smoke |
| T-P0.35.3.1.3 | REST `/labels*` | 5 routes | route assertion |
| T-P0.35.3.1.4 | REST `POST /conversations/{id}/labels` body `{labels:[id1,id2]}` (set/replace) | event `crm_label_assigned/removed` |
| T-P0.35.3.1.5 | denorm: update `cached_label_list` on assign | column updated |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Label_Repository` | NEW | bootstrap | `class_exists` + `tbl()` returns prefixed name | "Create test label" → row + cleanup |

## M3.W2 — Label UI (sidebar + chips)

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.3.2.1 | `panels/NavSidebar.tsx` | section "Labels" list `show_on_sidebar=true` + count | DOM count matches REST |
| T-P0.35.3.2.2 | `panels/ContactDrawer.tsx` | `<LabelChips/>` multi-select | chip add/remove |
| T-P0.35.3.2.3 | conversation list filter `?label=urgent` | URL filter works |

> **🟢 STATUS (2026-05-14)**: T-P0.35.3.2.1/2 ✅ · T-P0.35.3.2.3 ✅ — `components/LabelChips.jsx` + `useGetLabelsQuery` + `label_id` filter wired in `ConversationList.jsx`. Diag earlier reported "missing" do OneDrive cloud-only placeholders → đã pin local (`attrib +P /S /D` toàn `frontend/src`).

## M3.W3 — Custom Attribute Definitions

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.3.3.1 | DB | CREATE `bizcity_crm_custom_attribute_definitions` | exists |
| T-P0.35.3.3.2 | `attributes/class-custom-attr-definition.php` | repo CRUD + `validate($key, $value)` per display_type | unit tests 8 types |
| T-P0.35.3.3.3 | REST `/custom-attributes*` (5 routes) | route check |
| T-P0.35.3.3.4 | boundary R-PAR-9: REST `PUT /contacts/{id}` validate `additional_attributes[$key]` against definition | bad input 400 |
| T-P0.35.3.3.5 | UI: `panels/ContactDrawer.tsx` render section "Custom Attributes" — input type per display_type | render 8 types live |

## M3.W4 — Template Renderer + token engine

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.3.4.1 | `macros/class-template-renderer.php` | `render($template, $context)` regex `{{a.b.c}}` | unit 10 cases |
| T-P0.35.3.4.2 | helper `resolve_token('contact.name', $ctx)` recursive | dotted path |
| T-P0.35.3.4.3 | special token `{{kg.answer:notebook=X}}` — lazy call NB_Query_KG (timeout-protected) | live render |
| T-P0.35.3.4.4 | escape: HTML or text mode flag | XSS test |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Template_Renderer` | NEW | bootstrap | `class_exists::render` callable | "Render preview" form |

## M3.W5 — Macros CRUD + Composer integration

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.3.5.1 | DB | CREATE `bizcity_crm_macros` | exists |
| T-P0.35.3.5.2 | repo + REST `/macros*` (5 routes) | route check |
| T-P0.35.3.5.3 | REST `POST /macros/{id}/preview` body `{conversation_id}` → rendered text | preview returns |
| T-P0.35.3.5.4 | macro `actions_json` chain → reuse Action_Registry from M2 | execute chain |
| T-P0.35.3.5.5 | FE: Composer dropdown "Macros" → click insert text + (optional) execute chain side-actions | insert works |

**M3 DONE checklist**: 5 wave ✅ · labels rendered in sidebar · 8 custom attr types render · macro preview returns text · composer macro picker visible.

---

# ⏰ M4 — Working Hours + SLA Policy (CRITICAL)

> **Wave**: 4 · **Estimated**: 1 sprint · **Risk**: HIGH (cron timing, timezone)

## M4.W1 — Working Hours CRUD + helper

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.4.1.1 | DB | CREATE `bizcity_crm_working_hours` (PK composite `inbox_id, day_of_week`) | unique constraint |
| T-P0.35.4.1.2 | seeder | on inbox create → seed 7 rows default 9-18 Mon-Fri | check after add |
| T-P0.35.4.1.3 | `sla/class-working-hours.php` | `is_open($inbox_id, $ts, $tz)` | unit 12 cases (incl. DST, midnight cross) |
| T-P0.35.4.1.4 | REST `/working-hours?inbox_id=X` (GET/PUT) | route check |
| T-P0.35.4.1.5 | UI: per-inbox 7-day grid editor | render |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Working_Hours` | NEW | bootstrap | `is_open()` callable | "Check now" inbox=X → bool + reason |

## M4.W2 — SLA Policy CRUD

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.4.2.1 | DB | CREATE `bizcity_crm_sla_policies` + `bizcity_crm_applied_slas` | exists |
| T-P0.35.4.2.2 | `sla/class-sla-policy.php` repo | CRUD | smoke |
| T-P0.35.4.2.3 | REST `/sla-policies*` 5 routes | check |
| T-P0.35.4.2.4 | new action `apply_sla` in Action_Registry (M2) — apply policy to conversation when rule fires | rule + apply_sla → row in `applied_slas` |

## M4.W3 — SLA Evaluator cron + breach event

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.4.3.1 | `sla/class-sla-evaluator.php` | cron `bizcity_crm_sla_tick` 1' | next_scheduled returns ts |
| T-P0.35.4.3.2 | lock | `set_transient('bizcity_crm_sla_lock', uniq, 90)` — skip if held (Risk §7) | concurrency safe |
| T-P0.35.4.3.3 | calc | for each `applied_slas.state='active'` compute `frt_at`, `nrt_at`, `rt_at` (subtract closed-hours if `only_during_business_hours=true`) | unit test edge cases |
| T-P0.35.4.3.4 | breach | emit `crm_sla_breached` with `parent_event_uuid` of conversation creation | event seen |
| T-P0.35.4.3.5 | met | when conversation resolved before threshold → state=`met` + emit `crm_sla_met` | event seen |
| T-P0.35.4.3.6 | "Force tick" button bypass cron + lock | returns N evaluated, M breached |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_SLA_Evaluator` | NEW | bootstrap (register cron) | `wp_next_scheduled('bizcity_crm_sla_tick')` | force tick |

## M4.W4 — UI badges + Automation rule trigger

| Task | File | Component / Class | Probe |
|---|---|---|---|
| T-P0.35.4.4.1 | `panels/ConversationList.tsx` | `<SLABadge state={...} until={...} />` per row | DOM scan |
| T-P0.35.4.4.2 | `panels/MessageThread.tsx` header | "⏱ FRT in 5m" / "🔴 Breached" | render |
| T-P0.35.4.4.3 | M2 wiring | `crm_sla_breached` is in Engine subscribed events list (M2.W1) | rule fires when breach |

> **🟢 STATUS (2026-05-14)**: T-P0.35.4.4.1 ✅ (`components/SLABadge.jsx` + `useGetConversationSlaQuery`) · T-P0.35.4.4.2 ✅ (`<SLABadge/>` mounted trong `ConversationDetail.jsx` header) · T-P0.35.4.4.3 ✅ (REST `/conversations/{id}/sla` envelope đã pass từ phiên trước).

**M4 DONE checklist**: 4 wave ✅ · cron alive (`wp_cron_status` shows next_run) · 1 forced breach emits event + automation rule reacts · SLA badge visible.

---

# 📊 M5 — Reports Dashboard + CSAT + Audit

> **Wave**: 5 · **Estimated**: 1.5 sprint · **Risk**: MED (event volume)

## M5.W1 — Report Builder query layer

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.5.1.1 | `reports/class-report-builder.php` | `aggregate($metric, $group_by, $range, $filters)` | call returns array |
| T-P0.35.5.1.2 | metrics: `conversations_count`, `incoming_messages_count`, `outgoing_messages_count`, `avg_first_response_time`, `avg_resolution_time`, `resolutions_count`, `csat_avg`, `sla_breach_count` | each metric returns numeric |
| T-P0.35.5.1.3 | group_by: `day`, `agent_id`, `inbox_id`, `label_id`, `responder_kind` | each group key dispatch |
| T-P0.35.5.1.4 | source: query `bizcity_twin_event_stream WHERE event_type LIKE 'crm_%'` indexed | EXPLAIN < 100ms on 100k rows |

## M5.W2 — Daily rollup cron

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.5.2.1 | cron `bizcity_crm_daily_rollup` 03:00 UTC | next_scheduled |
| T-P0.35.5.2.2 | run all metrics for yesterday → INSERT row `event_type='crm_daily_rollup', payload_json={metrics}` | row exists |
| T-P0.35.5.2.3 | report builder prefer rollup row for past days, live aggregate for today | branch test |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Report_Builder` | NEW | bootstrap | `aggregate()` callable | "Run now" date=today → JSON |
| `BizCity_CRM_Daily_Rollup` | NEW | bootstrap (register cron) | next_scheduled | "Force rollup" returns rows written |

## M5.W3 — UI: 6 KPI cards + Agent table

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.5.3.1 | `routes/reports/Overview.tsx` | 6 KPI cards | DOM scan |
| T-P0.35.5.3.2 | `routes/reports/Agents.tsx` | sortable table | sort works |
| T-P0.35.5.3.3 | `routes/reports/Inboxes.tsx` + `Labels.tsx` | similar | render |
| T-P0.35.5.3.4 | date range picker (today / 7d / 30d / custom) | filter calls API |

> **🟢 STATUS (2026-05-14)**: T-P0.35.5.3.1/2/3 ✅ — `Report_Builder::aggregate(group_by=agent_id|inbox_id|label_id)` đã PASS trong diag (rows=1/0/0). Backend fix: thêm `METRIC_ALIASES` (Chatwoot parity: `conversations_opened/closed`, `first_response_time`, `resolution_time`, `csat`, `sla_breaches`, …) + special-case `label_id` qua INNER JOIN `wp_bizcity_crm_conversation_labels` trong `q_conversations_count`/`q_resolutions_count`/`q_avg_first_response_time`/`q_avg_resolution_time`. T-P0.35.5.3.4 ✅ — `BreakdownTable` + 3 sections agent/inbox/label trong `routes/reports/ReportsTab.jsx` (FE đã có sẵn, FAIL trước đó là OneDrive placeholder).

## M5.W4 — "Auto vs Human vs Hybrid" unique chart

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.5.4.1 | REST `/reports/auto-vs-human` group by `responder_kind, day` | 4 series in JSON |
| T-P0.35.5.4.2 | `routes/reports/AutoVsHuman.tsx` line chart (Recharts/Chart.js) | render 4 series |
| T-P0.35.5.4.3 | metric: % of outbound bubbles per responder_kind | sum = 100% |

> **🟢 STATUS (2026-05-14)**: T-P0.35.5.4.1 ✅ (already PASS) · T-P0.35.5.4.2 ✅ — `<AutoVsHuman/>` + `useGetReportsAutoVsHumanQuery` đã wire trong `ReportsTab.jsx` (FAIL trước đó cũng do OneDrive placeholder, fixed).

## M5.W5 — CSAT + Audit tab

| Task | File | Class/Component | Probe |
|---|---|---|---|
| T-P0.35.5.5.1 | `reports/class-csat-survey.php` | on `crm_conversation_resolved` → schedule send (5 min after) message via channel adapter, kind=`csat_survey` | bubble inserted |
| T-P0.35.5.5.2 | inbound listener — if conversation has pending csat + reply matches `1-5` → record + emit `crm_csat_response` | event seen |
| T-P0.35.5.5.3 | REST `/csat/{conversation_id}` POST manual submit (FE widget) | row + event |
| T-P0.35.5.5.4 | Intent Monitor tab "CRM Audit" registered via filter `bizcity_intent_monitor_tabs` (R-IMN-1, R-IMN-4) | tab visible at `admin.php?page=bizcity-intent-monitor&tab=crm-audit` |
| T-P0.35.5.5.5 | tab content: list 50 latest `crm_*` events, filter by event_type, click → drawer with payload | DOM render |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_CSAT_Survey` | NEW | bootstrap | `has_action('crm_conversation_resolved', ...)` | force resolve → bubble queued |
| Tab registration | filter callback | filter has 1+ items | tab opens |

**M5 DONE checklist**: 5 wave ✅ · 6 KPI render with real data · auto-vs-human chart visible · 1 CSAT response captured · Audit tab in Intent Monitor.

---

# 🎯 M6 — Campaigns + QR + UTM + Loyalty (UNIQUE BIZCITY)

> **Wave**: 6 · **Estimated**: 1.5 sprint · **Risk**: MED (loyalty bridge dedupe)

## M6.W1 — Campaign + Visit schema

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.1.1 | DB | CREATE `bizcity_crm_campaigns` + `bizcity_crm_campaign_visits` | exists |
| T-P0.35.6.1.2 | repo `campaigns/class-campaign-repository.php` CRUD | smoke |
| T-P0.35.6.1.3 | REST `/campaigns*` 5 routes | check |
| T-P0.35.6.1.4 | UI route `/campaigns` list + create wizard | render |

## M6.W2 — QR Generator + UTM template

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.2.1 | `campaigns/class-qr-generator.php` | bundle `endroid/qr-code` (composer) OR phpqrcode fallback | `BizCity_CRM_QR_Generator::png($payload)` returns bytes |
| T-P0.35.6.2.2 | REST `GET /campaigns/{id}/qr.{png|svg}` | content-type correct |
| T-P0.35.6.2.3 | UTM builder UI — preview URL + 5 utm fields | URL preview live |
| T-P0.35.6.2.4 | payload format: `https://site/lp?ref=camp_<code>&utm_source={s}&utm_medium={m}&utm_campaign={code}&utm_content={c}` | encode test |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_QR_Generator` | NEW | bootstrap | png() callable | "Generate QR" returns base64 PNG |

## M6.W3 — Visit tracking (2 modes)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.3.1 | mode 1 — FB adapter parse `referral.ref` (existing webhook) → emit `crm_campaign_visit_recorded` | mock webhook test |
| T-P0.35.6.3.2 | mode 2 — `init` hook parse `$_GET['ref']` + `$_GET['utm_*']` → cookie 30d + visit row + event | URL hit + cookie set |
| T-P0.35.6.3.3 | shortcode `[bizcity_campaign_track campaign="X"]` 1×1 GIF for non-SSR landing | shortcode renders pixel |
| T-P0.35.6.3.4 | rate limit per IP/UA (Risk §7) | spam test rejected |
| T-P0.35.6.3.5 | `campaigns/class-campaign-tracker.php` | `record_visit($campaign_id, $client_id_or_cookie, $utm)` | row + event |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Campaign_Tracker` | NEW | bootstrap | `has_action('init', ...)` | "Simulate scan" returns visit_id + event_uuid |

## M6.W4 — Visit ↔ Contact link + auto-trigger

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.4.1 | on `crm_conversation_created` → match cookie/PSID with recent unattributed visit → UPDATE `contacts.acquisition_source` + `_meta_json` | join check |
| T-P0.35.6.4.2 | emit `crm_campaign_converted` (parent=visit event) | event seen |
| T-P0.35.6.4.3 | M2 wiring: rule "WHEN crm_campaign_converted AND campaign.code=X THEN send_kg_reply notebook=Y" | rule fires + AI reply |

## M6.W5 — Loyalty Bridge (R-PAR-8)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.5.1 | `campaigns/class-loyalty-bridge.php` | `award($subject, $points, $meta)` — INSERT `wp_user_points` ledger (existing schema) + dedupe by `meta.event_uuid` | dedupe test (call 2x → 1 row) |
| T-P0.35.6.5.2 | same | `balance($subject)` SUM ledger | numeric |
| T-P0.35.6.5.3 | same | `history($subject, $limit)` from event stream | list |
| T-P0.35.6.5.4 | new action `award_points` in Action_Registry | "Award test 10pts" smoke |
| T-P0.35.6.5.5 | REST `/loyalty/award` POST + `/loyalty/balance/{contact_id}` GET | route + value |
| T-P0.35.6.5.6 | denorm: `contacts.points_balance_cache` updated on award | cached value matches |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Loyalty_Bridge` | NEW | bootstrap | `class_exists` + `wp_user_points` table reachable | "Award test" → balance += N |
| `BizCity_CRM_Action_Award_Points` | NEW | registry | code in list | dry-run shows would-award |

## M6.W6 — Flow Importer (bizgpt → macro+rule)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.6.1 | `campaigns/class-flow-importer.php` | `preview()` reads `wp_bizgpt_custom_flows` → returns array of `{macro_def, rule_def, notebook_link?}` | preview returns N items |
| T-P0.35.6.6.2 | `import($flow_id)` — create macro + rule + (optional) link to notebook | row inserted |
| T-P0.35.6.6.3 | wizard UI under `/campaigns/import` | render preview table + checkboxes |
| T-P0.35.6.6.4 | safe: `imported_from_bizgpt_flow_id` column on macro/rule (snapshot) — re-import overwrites | idempotent |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Flow_Importer` | NEW | bootstrap | `class_exists` + `wp_bizgpt_custom_flows` reachable | "Preview import" returns N rows |

## M6.W7 (bonus inside M6) — Funnel report

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.7.1 | REST `/campaigns/{id}/funnel` — counts: visits, conversations, resolved, points awarded | 4 numbers |
| T-P0.35.6.7.2 | UI funnel chart 4 stages with drop-off % | render |

## M6.W8 — Campaign Authoring UI (FE) — TAB MỚI TRONG INBOX APP

> **Why**: BE đã đủ (M6.W1–W4 done) nhưng admin chưa có nơi tạo/sửa/xem campaign. Đây là FE tab cho phép end-to-end: tạo kịch bản → preview QR + m.me link → copy/download → xem live funnel.

| Task | File | Component / Function | Probe |
|---|---|---|---|
| T-P0.35.6.8.1 | Admin menu submenu "Campaigns" + React route `/campaigns` mounted vào Inbox app shell | tab xuất hiện trong CRM admin |
| T-P0.35.6.8.2 | `assets/fe/src/routes/campaigns/CampaignList.tsx` — DataTable (shadcn), columns: name/code/status/visits/conversions/created — pull `/campaigns` + per-row `/stats` | 1 row created via API hiện ngay |
| T-P0.35.6.8.3 | `assets/fe/src/routes/campaigns/CampaignForm.tsx` — Sheet form (M-FE.W14 chuẩn): name, code (auto-slug), status, landing_url, utm_source/medium/campaign/content/term, loyalty_points_award, **welcome_template_id** (M3.W4 dropdown), **bound_character_id** (Twin Guru dropdown), **bound_notebook_id** | submit → row hydrated correctly |
| T-P0.35.6.8.4 | `assets/fe/src/routes/campaigns/CampaignDetail.tsx` — QR preview (`<img src=/qr.svg>`) + Copy m.me link button + Download PNG/SVG buttons + live funnel widget (visits/conversations/conversion%/points) | preview hiển thị + copy succeeds |
| T-P0.35.6.8.5 | RTK slice `campaignsApi` — list/get/create/update/delete + `getStats(id)` + `getUrl(id)` invalidatesTags chuẩn | optimistic update OK |
| T-P0.35.6.8.6 | i18n keys + dark-mode + cmd+k entry ("New campaign") | switch ngôn ngữ + theme OK |

**Diagnostic**:

| Item | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `inbox-app.js` campaigns route bundle | dist | webpack chunk loaded | router contains `/campaigns` | F12 console: `window.crmRoutes.includes('/campaigns')` |
| Admin menu "Campaigns" entry | menu.php | `add_submenu_page` | `current_user_can('bizcity_crm_manage_campaigns')` gate | tab visible cho editor+ |
| `campaignsApi` RTK slice | bundle | provider mounted | invalidatesTags(['Campaign']) on mutation | E2E: create → list refresh |

## M6.W9 — Campaign ↔ Scenario Binding (BE bridge cho W8 dropdowns)

> **Why**: form M6.W8 cần các dropdown character/template/notebook — schema hiện chưa có cột tương ứng. Wave này thêm cột + ActionRule trigger để khi `crm_campaign_conversion_recorded` bắn → tự switch character + send welcome template + attach notebook context cho AI auto-reply.

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.9.1 | DB bump → thêm 3 cột vào `wp_bizcity_crm_campaigns`: `welcome_template_id BIGINT NULL`, `bound_character_id BIGINT NULL`, `bound_notebook_id BIGINT NULL` (idempotent dbDelta) | `SHOW COLUMNS` thấy 3 cột |
| T-P0.35.6.9.2 | `Campaign_Repository` hydrate + sanitize 3 trường mới (validate FK existence; NULL khi 0/missing) | get/update round-trip giữ giá trị |
| T-P0.35.6.9.3 | `BizCity_CRM_Campaign_Conversion_Bridge` listen `crm_campaign_conversion_recorded` @ priority 30 — nếu campaign có `bound_character_id` → switch_character cho conversation; nếu có `welcome_template_id` → render template (M3.W4) + insert outgoing message qua adapter | dispatch test: 1 conv → character đổi + 1 outgoing msg |
| T-P0.35.6.9.4 | `Action_Registry` thêm action `attach_campaign_context` → đẩy `bound_notebook_id` vào `conversation.context_meta_json.notebook_id` để AI auto-reply listener (M-CRM.M3) ground theo notebook đó | conversation row có notebook_id set |
| T-P0.35.6.9.5 | REST `/campaigns/{id}/dropdowns` GET trả 3 list `{characters, templates, notebooks}` cho FE form W8 | 3 mảng non-empty trên dev install |
| T-P0.35.6.9.6 | Diag: end-to-end — create campaign with binding → record visit → simulate inbound → assert conversion event fired + character switched + welcome template sent + notebook attached | 1 row update + 1 outgoing + meta probe |

**Diagnostic**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Campaign_Conversion_Bridge` | NEW | bootstrap | `has_action('crm_campaign_conversion_recorded', __CLASS__::on_conversion, 30)` | round-trip diag T-P0.35.6.9.6 |
| Schema cols (`welcome_template_id`, `bound_character_id`, `bound_notebook_id`) | dbDelta | DB version bump → 1.10.3 | `SHOW COLUMNS` | 3 cols present, NULL allowed |

**M6 DONE checklist**: end-to-end script S7 (PHASE 0.31): scan QR → cookie + visit row → Messenger or landing → conversation created with `acquisition_source='campaign:camp_X'` → AI reply per notebook → resolve → +50 pts in `wp_user_points` → `points_balance_cache=50` → funnel chart shows 1/1/1/50.

**M6.W8+W9 DONE checklist**: admin mở tab Campaigns → bấm "+ New" → điền form (name="Khai trương", code="khai-truong-2026", character="Cô Hương", welcome_template="Chào mừng KH", notebook="Sản phẩm 2026") → save → mở Detail → tải QR PNG → in poster → khách scan QR → mở Messenger → gửi tin đầu tiên → ngay tức khắc thấy: (1) outgoing welcome message từ template, (2) AI auto-reply tiếp theo grounded theo notebook "Sản phẩm 2026", (3) conversation card trong Inbox tab có badge campaign "khai-truong-2026", (4) funnel widget của campaign +1 visit / +1 conversion.

---

# 📡 M7 — Channel Integration UI hoàn thiện

> **Wave**: 4 · **Estimated**: 1 sprint · **Risk**: HIGH (per-vendor nuances)

## M7.W1 — Wizard "Add Inbox"

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.7.1.1 | `routes/inboxes/AddInbox.tsx` step 1: pick channel type | render 7 cards |
| T-P0.35.7.1.2 | step 2: per-channel form (dynamic from adapter `setup_form_schema()`) | each type renders unique form |
| T-P0.35.7.1.3 | step 3: webhook URL display + verify button | calls adapter `verify()` |

## M7.W2 — Adapter Instagram + WhatsApp Cloud

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.7.2.1 | `adapters/class-adapter-instagram.php` extends interface | webhook echo |
| T-P0.35.7.2.2 | DM + Comments support | mock 2 events |
| T-P0.35.7.2.3 | `adapters/class-adapter-whatsapp-cloud.php` | template + free-form 24h window |
| T-P0.35.7.2.4 | template approval state cache | UI shows status |

**Diagnostic**:

| Adapter | Disk | Loader (registry) | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Adapter_Instagram` | NEW | filter `bizcity_register_channel_integrations` | adapter listed | "Send test" |
| `BizCity_CRM_Adapter_WhatsApp_Cloud` | NEW | same | listed | "Send template" |

## M7.W3 — Adapter Telegram + Email IMAP + Web Widget

| Task | File | Probe |
|---|---|---|
| T-P0.35.7.3.1 | `adapters/class-adapter-telegram.php` | bot getMe |
| T-P0.35.7.3.2 | `adapters/class-adapter-email-imap.php` cron poll | mock email |
| T-P0.35.7.3.3 | `adapters/class-adapter-web-widget.php` | snippet code visible |

## M7.W4 — Health indicator

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.7.4.1 | each adapter implements `health()` returning `{last_inbound_at, last_error, status}` | called |
| T-P0.35.7.4.2 | NavSidebar "Channels" rail shows colored dot per inbox | DOM scan |

## M7.W5 — Bot Plugin Sync (FB · Zalo · Google) 🆕

> **Why**: Audit 2026-05-11 phát hiện 3 adapter (Facebook, Zalo, Email/Google) gọi
> sang sibling plugin bằng symbol/hook **không khớp** với code thật → fallback
> sẽ silently fail trên production. Wave này chuẩn hoá 1-1 contract giữa CRM
> adapter và bot plugin, đồng thời thêm Gmail OAuth provider vào Email adapter.
>
> **Audit baseline** (2026-05-11):
> - bizcity-facebook-bot: B+ (1 method sai tên + 1 namespace lệch)
> - bizcity-zalo-bot: D (constructor sai, 2 method sai tên, REST không tồn tại)
> - bizgpt-tool-google: chưa wire vào Email adapter
>
> **Risk**: HIGH — bất kỳ thay đổi signature nào ở bot plugin đều phá CRM. Wave
> này phải lock contract bằng adapter-side **bridge class** thay vì gọi trực tiếp.

### M7.W5.task-1 — Bridge layer (interface stabilizer)

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.7.5.1.1 | `bridges/class-fb-bot-bridge.php` — wraps `BizCity_Facebook_Bot_Database` + `BizCity_Facebook_Bot_API` | `Bridge::is_available()`, `::get_bot_by_page_id()`, `::send_text()`, `::send_image()`, `::lookup_token()` |
| T-P0.35.7.5.1.2 | `bridges/class-zalo-bot-bridge.php` — wraps `BizCity_Zalo_Bot_Database` + `BizCity_Zalo_Bot_API` (1-arg constructor!) | `::send_text()` calls `send_text_message()`, `::lookup_bot_by_oa()` filters `get_active_bots()` |
| T-P0.35.7.5.1.3 | `bridges/class-google-tool-bridge.php` — wraps `bizgpt-tool-google` OAuth client + Gmail API | `::is_available()`, `::list_oauth_accounts()`, `::gmail_imap_credentials($email)`, `::gmail_send($from,$to,$subj,$body)` |
| T-P0.35.7.5.1.4 | Bridge versioning: each bridge declares `BRIDGE_API_VERSION = '1.0.0'` + checks bot plugin version compat | wizard refuses to create inbox if bridge `is_available()` = false |

**Why bridges?** Adapter đã không bao giờ gọi trực tiếp class của bot plugin nữa. Khi bot plugin đổi signature trong tương lai → chỉ cần update bridge, adapter business logic không đụng vào.

### M7.W5.task-2 — Facebook adapter sync

| Task | File | Action | Probe |
|---|---|---|---|
| T-P0.35.7.5.2.1 | `adapters/class-adapter-facebook.php` thay mọi `BizCity_Facebook_Bot_Database::instance()->...` bằng `BizCity_CRM_Bridge_FB::...` | grep adapter — 0 reference trực tiếp đến `BizCity_Facebook_Bot_*` |
| T-P0.35.7.5.2.2 | Sửa `lookup_page_access_token()` fallback — đổi `get_bots()` → `get_bots_by_user(get_current_user_id())` (method thật) HOẶC bỏ fallback nếu `get_bot_by_page_id()` trả null | unit test với page_id ko tồn tại trả `''` không exception |
| T-P0.35.7.5.2.3 | `setup_form_schema()`: sửa webhook URL từ `bizcity-fbbot/v1/webhook` → `bizcity-facebook-bot/v1/webhook` (namespace thật) | wizard hiển thị URL đúng |
| T-P0.35.7.5.2.4 | `verify()`: gọi `Bridge::get_bot_by_page_id($page_id)` — nếu null thì error "Page chưa được kết nối ở bizcity-facebook-bot" | wizard refuse khi page chưa connect |

### M7.W5.task-3 — Zalo adapter sync (CRITICAL)

| Task | File | Action | Probe |
|---|---|---|---|
| T-P0.35.7.5.3.1 | `adapters/class-adapter-zalo.php` rewrite `send()` strategy 2 — dùng `new BizCity_Zalo_Bot_API($token)` (1 arg!), gọi `send_text_message($uid, $text)` | grep adapter cho `send_text(` ⇒ 0 result |
| T-P0.35.7.5.3.2 | `lookup_oa_access_token()` rewrite — `get_active_bots()` filter bằng `oa_id`, không gọi `get_bot_by_oa_id` | xác nhận với 2 bot OA hoạt động |
| T-P0.35.7.5.3.3 | `setup_form_schema()`: sửa webhook URL từ REST `/wp-json/bizcity-zalobot/v1/webhook` → rewrite `/zalohook/{bot_id}` (URL thật) | wizard hiển thị URL đúng |
| T-P0.35.7.5.3.4 | `verify()`: lookup bot bằng OA id, error rõ ràng nếu chưa tạo bot | block create-inbox nếu OA chưa register |
| T-P0.35.7.5.3.5 | `bizcity_zalo_message_received` payload bridge — confirm Universal Channel Listener đang wrap đúng (audit cho thấy bridge ở `core/channel-gateway/includes/class-universal-channel-listener.php:84,97`); thêm probe check listener registered | sprint-diag PASS |

### M7.W5.task-4 — Google / Gmail OAuth provider

| Task | File | Action | Probe |
|---|---|---|---|
| T-P0.35.7.5.4.1 | `adapters/class-adapter-email-imap.php` — thêm field `provider` ENUM(`generic_imap`, `gmail_oauth`) ở step đầu form | UI hiển thị 2 lựa chọn |
| T-P0.35.7.5.4.2 | Khi `provider=gmail_oauth`: schema render dropdown account từ `Bridge_Google::list_oauth_accounts()` thay vì host/port/password | UI swap fields |
| T-P0.35.7.5.4.3 | `verify()` cho gmail_oauth: gọi `Bridge_Google::test_token($email)` — nếu token expired → return error với link re-auth | wizard show "Re-authorize" button |
| T-P0.35.7.5.4.4 | `send()` cho gmail_oauth inbox: route qua `Bridge_Google::gmail_send()` thay vì `wp_mail()` | outbound message gửi qua Gmail API, header `X-Gmail-API: 1` |
| T-P0.35.7.5.4.5 | Cron `bizcity_crm_email_poll` cho gmail_oauth: dùng Gmail API `users.messages.list` thay vì IMAP poll | sprint-diag tracks `gmail_api_calls` counter |

### M7.W5.task-5 — Diagnostic page row + filter

| Task | File | Action | Probe |
|---|---|---|---|
| T-P0.35.7.5.5.1 | `class-sprint-diagnostic.php` thêm row "Bot Plugin Bridges" — kiểm tra: bot plugin loaded? bridge file present? `BRIDGE_API_VERSION` match? `is_available()=true`? | 3 PASS rows visible |
| T-P0.35.7.5.5.2 | Live probe button "Test FB send" / "Test Zalo send" / "Test Gmail send" gọi adapter `send()` với conversation_id giả + recipient demo | response_code visible |

**Diagnostic** (M7.W5):

| Class | File | Layers | Probe |
|---|---|---|---|
| `BizCity_CRM_Bridge_FB` | `bridges/class-fb-bot-bridge.php` | D·L·R | `is_available()` returns true khi `BizCity_Facebook_Bot_Database` exists |
| `BizCity_CRM_Bridge_Zalo` | `bridges/class-zalo-bot-bridge.php` | D·L·R | `is_available()` + `lookup_bot_by_oa('test_oa')` returns null safely |
| `BizCity_CRM_Bridge_Google` | `bridges/class-google-tool-bridge.php` | D·L·R | `is_available()` reflects `bizgpt-tool-google` plugin status |

### M7.W5 acceptance criteria

- [ ] `grep "BizCity_Facebook_Bot_\|BizCity_Zalo_Bot_\|wp_send_zalo" plugins/bizcity-twin-crm/includes/adapters/` → **0 hits** (tất cả qua bridge)
- [ ] `grep "send_text\b" includes/adapters/class-adapter-zalo.php` → **0 hits** (đã đổi `send_text_message`)
- [ ] Wizard "Add Inbox" → Facebook: nếu page chưa connect ở bot plugin → error rõ ràng "Page chưa được kết nối"
- [ ] Wizard → Zalo: webhook URL hiển thị `/zalohook/{bot_id}` chứ không phải `/wp-json/...`
- [ ] Wizard → Email: dropdown provider hiện cả `Generic IMAP` và `Gmail (OAuth)` nếu Google plugin active
- [ ] Sprint-diag page show 3 row "Bridge: FB / Zalo / Google" với status PASS
- [ ] Manual test: gửi 1 outbound message qua mỗi kênh → message_id thật trả về (không phải fallback uuid)

**M7 DONE checklist** (cập nhật): 5 wave ✅ · 5 new adapters registered · 3 bridges ổn định · wizard creates inbox end-to-end · health dot visible · zero direct bot-plugin coupling.

---

# 🎨 M-FE — Inbox Workspace UI (TwinChat-style tabbed single-screen console)

> **Wave**: 8 · **Estimated**: 2 sprint · **Risk**: LOW (read-only consumes existing PHASE 0.35 backend)
> **Mục tiêu**: nhồi toàn bộ tính năng PHASE 0.35 đã ship backend (M1–M5 + M7) vào **CHÍNH 1 khung React Inbox hiện tại** (`plugins/bizcity-twin-crm/frontend/src/`). Không mở route admin mới, không bật tab trình duyệt mới — mọi thứ chuyển cảnh bằng **horizontal tab bar trên cùng** giống TwinChat Brain (Nexus / Brain Parts / Cards / Memory Hub / Twin Guru / History / Files / Integrations / Plans / Workspace).

## §F.0 — Reflection: BE đã có gì, FE cần consume gì

| Backend ship (PHASE 0.35) | REST endpoint | FE consumer (target) |
|---|---|---|
| M1.W3 conversation grid filters | `GET /conversations?priority=&snoozed=&waiting_since_gt=` | Inbox tab — `<PriorityBadge>` + `<SnoozedClock>` chips trong `ConversationList` |
| M1.W4 snooze | `POST /conversations/{id}/snooze` | Inbox tab — kebab menu trên header `ConversationDetail` |
| M2.W3/W5 automation rules + 11 actions | `GET/POST/PUT/DELETE /automation-rules`, `POST /automation-rules/{id}/dry-run`, `GET /automation-actions` | **Tab "Automation"** — list + drawer editor (Chatwoot pattern: `Index.vue` + `AutomationRuleForm.vue`) |
| M3.W1 labels | `GET/POST /labels`, `POST /conversations/{id}/labels` | **Tab "Labels"** + chip overlay trong `ConversationList` row + `<LabelPicker>` trong `ConversationDetail` header |
| M3.W3 custom attrs | `GET/POST /custom-attributes` | **Tab "Custom Attrs"** + form auto-render trong `ContactDrawer` |
| M3.W4/W5 macros + template renderer | `GET/POST /macros`, `POST /macros/{id}/run`, `POST /render-template` | **Tab "Macros"** + macro picker (⚡) trong `Composer` |
| M4.W1/W2 working hours + sla policies | `GET/POST /working-hours`, `GET/POST /sla-policies` | **Tab "SLA & Hours"** — 2 split panel (grid editor + policy table) |
| M4.W3 applied SLAs | `GET /conversations/{id}/sla` | Inbox — `<SlaBadge>` (FRT due / NRT due / breached) trong header `ConversationDetail` + row `ConversationList` |
| M5.W1 reports aggregate | `GET /reports/aggregate?metric=&group_by=&from=&to=` | **Tab "Reports"** — 6 KPI cards (snapshot 8 metrics) + line/bar chart |
| M5.W4 auto vs human | `GET /reports/auto-vs-human` | **Tab "Reports"** sub-tab → donut chart unique BizCity |
| M5.W5 csat / audit | `POST /csat/{id}`, audit tab via `bizcity_intent_monitor_tabs` | **Tab "Audit"** — pretty wrap of audit table; CSAT score reply chip trong bubble outgoing |
| M7 channels | existing `/inboxes`, `/channels` | **Tab "Channels"** — wizard "Add Inbox" inline (giữ y `BizCity_CRM_Admin_Menu` wizard) |

## §F.1 — Layout target (TwinChat-style)

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ [B] Twin CRM ▸ Inbox console     [Inbox] [Reports] [Automation] [Labels]         │ ← top tab bar
│                                  [Macros] [Custom Attrs] [SLA] [Audit] [Channels]│   (active tab underlined indigo)
├──────────────────────────────────────────────────────────────────────────────────┤
│  ◄── workspace pane (swap by active tab) ──►                                     │
│  e.g. Inbox tab = current 4-pane (ChannelSidebar | ConvList | ConvDetail | Drawer)│
│       Reports tab = KPI grid + charts                                             │
│       Automation tab = rule list (left) + editor drawer (right)                   │
│       Labels/Macros/Attrs/SLA = list (left) + form panel (right)                  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

**Ràng buộc cứng**:
1. **1 viewport duy nhất** — không bao giờ mở popup full-screen / route mới. Editor drawer = right-rail (slide-in 480-560px) hoặc modal sheet 80vh.
2. **Tab state** giữ trong URL hash `#tab=reports` (deep-link share được) — extend `react-router-dom` hiện có.
3. Khung Inbox cũ (App.jsx 4-pane) **không bị phá** — nó trở thành panel của tab `inbox`.
4. Mọi tab khác **lazy-import** (code-split) để không phình bundle Inbox.
5. Component reuse: `<DataGrid>`, `<EditorDrawer>`, `<TokenChip>`, `<JsonPretty>` viết 1 lần dùng cho 5 tab CRUD.

## §F.2 — Chatwoot pattern port (Vue → React, port pattern không port code)

> Chatwoot là Vue 3, ta là React 18 + RTK Query. **CHỈ port layout + UX flow + naming**, KHÔNG copy code.

| Chatwoot file | Pattern lấy về | Áp dụng cho |
|---|---|---|
| `dashboard/routes/dashboard/settings/macros/Index.vue` + `MacroEditor.vue` | List + slide-in editor cùng route, save → optimistic | `routes/macros/MacrosTab.tsx` |
| `dashboard/routes/dashboard/settings/automation/AutomationRuleForm.vue` | Conditions JSON UI: scope select → operator select → value input (with `is_empty` hide value) | `routes/automation/RuleEditor.tsx` |
| `dashboard/routes/dashboard/settings/labels/Index.vue` | Color swatch palette (12 màu cố định) + show_on_sidebar toggle | `routes/labels/LabelsTab.tsx` |
| `dashboard/routes/dashboard/settings/reports/Index.vue` + `ReportContainer.vue` | Range picker (Today / 7d / 30d / Custom) → metric tile grid → chart canvas | `routes/reports/ReportsTab.tsx` |
| `dashboard/routes/dashboard/settings/sla/...` | Per-policy table với 3 SLA threshold inputs (FRT / NRT / RT) | `routes/sla/SlaTab.tsx` |
| `dashboard/components/widgets/conversation/Message.vue` SLA badge + label chip overlay | Inline badge stack pattern | `components/Conversation/HeaderMeta.tsx` |

## §F.3 — Wave plan (8 sub-sprint)

### M-FE.W1 — Tab Shell (top tab bar + workspace switcher) — **GATE**

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.FE.1.1 | `frontend/src/shell/TabBar.tsx` (NEW) | horizontal tab bar 9 entries, indigo underline active | DOM mount snapshot |
| T-P0.35.FE.1.2 | `frontend/src/shell/Workspace.tsx` (NEW) | switch-case lazy mount panel theo `activeTab` | 3 tab swap < 200ms |
| T-P0.35.FE.1.3 | `frontend/src/App.jsx` (refactor) | wrap `<InboxView/>` thành panel, thêm `<TabBar/>` + `<Workspace/>`, sync URL hash `#tab=` | route fallback ok |
| T-P0.35.FE.1.4 | `frontend/src/redux/store.js` | slice `uiTabs` (activeTab, lastVisited{}) | Redux devtools |
| T-P0.35.FE.1.5 | `frontend/src/styles.css` | tab bar 44px, sticky top, no shadow (slate-200 border-bottom) | visual review |

**Diag**: T-P0.35.FE.1 — tab bar render 9 buttons + URL hash round-trip + bundle size delta < 8 KB.

### M-FE.W2 — Inbox tab enhancements (consume M1.W3/W4 + M3 + M4)

| Task | File | Patch | Source endpoint |
|---|---|---|---|
| T-P0.35.FE.2.1 | `components/ConversationList.jsx` | add `<PriorityBadge>` + `<SnoozedClock>` per row; filter pills (Open · Pending · Resolved · Snoozed · All) | `/conversations?priority=&snoozed=` |
| T-P0.35.FE.2.2 | same | `<LabelChips conv={...}>` 1-line truncate w/ `+N` overflow | `cached_label_list` |
| T-P0.35.FE.2.3 | `components/ConversationDetail.jsx` header | `<SlaBadge>` (countdown FRT/NRT/RT — green/amber/red), `<LabelPicker>`, kebab → Snooze 1h/3h/Tomorrow/Custom | `/conversations/{id}/sla` + `/labels` |
| T-P0.35.FE.2.4 | `components/Composer.jsx` | "⚡ Macro" button → palette popover (search + run-preview-insert) | `/macros` + `/macros/{id}/run` |
| T-P0.35.FE.2.5 | `components/Composer.jsx` | token-aware textarea — `{{contact.name}}` chip rendering preview qua `/render-template` (debounce 400ms) | `/render-template` |
| T-P0.35.FE.2.6 | `components/ContactDrawer.jsx` | "Custom Attributes" section — auto-form từ `/custom-attributes?attr_target=contact` | `/custom-attributes`, `/contacts/{id}` |

**Diag**: T-P0.35.FE.2 — visual probe (snapshot 6 components mounted), no console errors, RTK cache key includes new params.

### M-FE.W3 — Reports tab (consume M5.W1/W4)

| Task | File | Component | Source |
|---|---|---|---|
| T-P0.35.FE.3.1 | `routes/reports/ReportsTab.tsx` | layout: range picker (top-right) + 6 KPI cards grid + 2 chart pane | — |
| T-P0.35.FE.3.2 | `routes/reports/KpiCard.tsx` | tile: metric label · big value · sparkline (last 7d) · delta % | `/reports/aggregate?metric=...&group_by=day` |
| T-P0.35.FE.3.3 | `routes/reports/AutoVsHumanDonut.tsx` | donut (auto · manual · hybrid · system) — unique BizCity feature | `/reports/auto-vs-human` |
| T-P0.35.FE.3.4 | `routes/reports/AgentTable.tsx` | rows: agent · #handled · avg FRT · resolutions | `/reports/aggregate?metric=resolutions_count&group_by=agent_id` |
| T-P0.35.FE.3.5 | `routes/reports/RangePicker.tsx` | Today / 7d / 30d / Custom → emits `{from_ts, to_ts}` | URL `?from=&to=` |

**Chart lib**: dùng `recharts` (đã có trong package.json hoặc thêm — < 90KB minified).

**Diag**: T-P0.35.FE.3 — 8 metric tiles populate, donut renders, range swap re-fetches.

### M-FE.W4 — Automation tab (consume M2.W3/W5)

| Task | File | Component | Source |
|---|---|---|---|
| T-P0.35.FE.4.1 | `routes/automation/RulesList.tsx` | table (Name · Event · Active toggle · Last fired) + "+ New Rule" | `/automation-rules` |
| T-P0.35.FE.4.2 | `routes/automation/RuleEditor.tsx` | right-rail drawer 560px: tabs (General · Conditions · Actions · Dry-run) | `/automation-rules/{id}` |
| T-P0.35.FE.4.3 | `routes/automation/ConditionRow.tsx` | scope select (8 scope) → operator (12) → value input (custom_attr lookup) | `/automation-actions` (action catalog reuse) |
| T-P0.35.FE.4.4 | `routes/automation/ActionCard.tsx` | dynamic schema render per action_code (11 actions) | catalog |
| T-P0.35.FE.4.5 | `routes/automation/DryRunPanel.tsx` | paste mock event JSON → POST dry-run → diff viewer | `/automation-rules/{id}/dry-run` |

**Diag**: T-P0.35.FE.4 — create 1 rule e2e, dry-run round-trip, toggle active mutates DB.

### M-FE.W5 — Labels + Macros + Custom Attrs tabs (consume M3.W1/W3/W5)

3 tab cùng pattern (Index left list + EditorDrawer right):

| Tab | Files | Notable |
|---|---|---|
| Labels | `routes/labels/{LabelsTab,AddLabel,EditLabel,ColorSwatch}.tsx` | 12-color palette + show_on_sidebar toggle |
| Macros | `routes/macros/{MacrosTab,MacroForm,MacroPreview}.tsx` | Visibility (private/public/team) + token preview pane |
| Custom Attrs | `routes/attributes/{AttrsTab,AttrForm}.tsx` | display_type select (8 types) → conditional value input (text/number/list/date/checkbox/link/regex/json) |

**Diag**: T-P0.35.FE.5 — CRUD round-trip × 3 entities, optimistic update on toggle.

### M-FE.W6 — SLA & Working Hours tab (consume M4.W1/W2)

| Task | File | Component | Source |
|---|---|---|---|
| T-P0.35.FE.6.1 | `routes/sla/SlaTab.tsx` | 2 sub-tab: "Working Hours" · "SLA Policies" (segmented control) | — |
| T-P0.35.FE.6.2 | `routes/sla/WorkingHoursGrid.tsx` | per-inbox 7-day grid (Mon-Sun × open/close + closed checkbox) | `/working-hours?inbox_id=` |
| T-P0.35.FE.6.3 | `routes/sla/SlaPolicyTable.tsx` | rows: Name · FRT · NRT · RT · Threshold met % (last 7d) | `/sla-policies` |
| T-P0.35.FE.6.4 | `routes/sla/PolicyForm.tsx` | drawer: 3 minute inputs · description · only_business_hours toggle | `/sla-policies/{id}` |

**Diag**: T-P0.35.FE.6 — grid edits PUT-back, policy create/edit round-trip.

### M-FE.W7 — Audit tab + Channels tab (consume M5.W5 + M7)

| Task | File | Component | Source |
|---|---|---|---|
| T-P0.35.FE.7.1 | `routes/audit/AuditTab.tsx` | 50 latest CRM events table (event_type · time · payload preview · UUID) + filter chip | `/events?type_prefix=crm_` (reuse existing) |
| T-P0.35.FE.7.2 | `routes/audit/EventDetailDrawer.tsx` | JSON pretty (collapsible) + parent_event_uuid link → trace chain | — |
| T-P0.35.FE.7.3 | `routes/channels/ChannelsTab.tsx` | inbox list + status dot (M7.W4 health) + "+ Add Inbox" wizard inline | `/inboxes` |
| T-P0.35.FE.7.4 | `routes/channels/AddInboxWizard.tsx` | port wizard từ `BizCity_CRM_Admin_Menu` PHP form sang React stepper (3 step: pick channel → credentials → bind Guru) | `/inboxes` POST |

**Diag**: T-P0.35.FE.7 — audit shows ≥1 row from M2 rule fire, wizard step skip-back works.

### M-FE.W8 — Polish + Diagnostic + Storybook-lite

| Task | File | Notable |
|---|---|---|
| T-P0.35.FE.8.1 | `frontend/src/diag/FeDiagPanel.tsx` | bật bằng `?fe_diag=1` query — render bảng 9 tab × {mounted, render_ms, last_error} |
| T-P0.35.FE.8.2 | `frontend/src/styles.css` | dark mode token (CSS var) — hooks vào `body.bizcity-dark` |
| T-P0.35.FE.8.3 | keyboard | `g i` Inbox · `g r` Reports · `g a` Automation · `g l` Labels · `g m` Macros · `g s` SLA · `g c` Channels (Chatwoot parity) |
| T-P0.35.FE.8.4 | a11y | `role="tablist"` + arrow-key navigation cho TabBar |
| T-P0.35.FE.8.5 | Build | `npm run build` IIFE bundle; verify chunked (8 lazy chunks); update `bootstrap.php` enqueue manifest |
| T-P0.35.FE.8.6 | Sprint diag rows | T-P0.35.FE.1..7 thêm vào `class-sprint-diagnostic.php` (FE asset existence + manifest hash + window flag `BizCityCRM.tabs` array) |

## §F.4 — Backend touch-up cần thiết để hỗ trợ FE

| Patch nhỏ BE phải làm song song | Sprint | Lý do |
|---|---|---|
| `GET /events?type_prefix=crm_&limit=50&parent_uuid=...` | trong M-FE.W7 | Audit tab + trace chain |
| `GET /macros/{id}/preview?context_conversation_id=` | trong M-FE.W2 | Composer macro palette |
| `GET /conversations` thêm field `sla_state` (eager join `applied_slas.state`) | trong M-FE.W2 | tránh N+1 khi list 50 row |
| Health dot REST `GET /inboxes/{id}/health` (M7.W4 đang 1/2 — finish trong FE.W7) | M-FE.W7 | nâng M7.W4 từ 🟡 → ✅ |

## §F.5 — Done definition

- [ ] `bizcity-crm-inbox-root` mount → 9 tab visible, default = Inbox.
- [ ] Mọi tab consume REST đã ship trong M1–M7, không hardcode mock.
- [ ] Bundle size: shell ≤ 220 KB · mỗi tab lazy chunk ≤ 80 KB.
- [ ] FE diag panel `?fe_diag=1` hiển thị 9 tab green.
- [ ] Sprint diag T-P0.35.FE.1..8 PASS.
- [ ] Trace contract §0.34 §0.2 hiển thị (badge AUTO/MANUAL/HYBRID) trên mọi outgoing bubble — đã có ConversationDetail bubble base; chỉ cần style & legend.
- [ ] **Không phá** flow PHASE 0.34 đã ship (Composer · Note · Resolve · ContactDrawer FE-M6).

---

# 🧪 Diagnostic Class Master Index (consolidated)

> Tất cả class mới phải xuất hiện trong bảng dưới — `bizcity-crm-sprint-diag` page render bảng này tự động bằng cách scan filter `bizcity_crm_diagnostic_classes`.

| Milestone | Class | File | Layers | Probe |
|---|---|---|---|---|
| M1.W1 | `BizCity_CRM_DB_Installer` (ext) | `class-db-installer.php` | D·L·R | dry-run migration |
| M1.W2 | `BizCity_CRM_Capability_Installer` | `class-capability-installer.php` | D·L·R | cap matrix |
| M1.W4 | `BizCity_CRM_Snooze_Reaper` | `class-snooze-reaper.php` | D·L·R·H | force tick |
| M2.W1 | `BizCity_CRM_Automation_Engine` | `automation/class-automation-engine.php` | D·L·R·H×8 | list subscribers |
| M2.W1 | `BizCity_CRM_Rule_Repository` | `automation/class-rule-repository.php` | D·L·R | cache stats |
| M2.W2 | `BizCity_CRM_Rule_Evaluator` | `automation/class-rule-evaluator.php` | D·L·R | test condition |
| M2.W3 | `BizCity_CRM_Action_Registry` | `automation/class-action-registry.php` | D·L·R·F | list actions |
| M2.W3 | `BizCity_CRM_Action_*` (×9 base) | `automation/actions/class-action-*.php` | D·L·R | dry-run |
| M2.W4 | `BizCity_CRM_NB_Query_KG` | `kg/class-nb-query-kg.php` | D·L·R | ask test |
| M2.W4 | `BizCity_CRM_Action_Send_KG_Reply` | `automation/actions/class-action-send-kg-reply.php` | D·L·R | dry-run |
| M3.W1 | `BizCity_CRM_Label_Repository` | `labels/class-label-repository.php` | D·L·R | create test |
| M3.W3 | `BizCity_CRM_Custom_Attr_Definition` | `attributes/class-custom-attr-definition.php` | D·L·R | validate sample |
| M3.W4 | `BizCity_CRM_Template_Renderer` | `macros/class-template-renderer.php` | D·L·R | render preview |
| M3.W5 | `BizCity_CRM_Macro_Repository` | `macros/class-macro-repository.php` | D·L·R | preview API |
| M4.W1 | `BizCity_CRM_Working_Hours` | `sla/class-working-hours.php` | D·L·R | check now |
| M4.W2 | `BizCity_CRM_SLA_Policy` | `sla/class-sla-policy.php` | D·L·R | apply test |
| M4.W3 | `BizCity_CRM_SLA_Evaluator` | `sla/class-sla-evaluator.php` | D·L·R·H (cron) | force tick |
| M5.W1 | `BizCity_CRM_Report_Builder` | `reports/class-report-builder.php` | D·L·R | run aggregate |
| M5.W2 | `BizCity_CRM_Daily_Rollup` | `reports/class-daily-rollup.php` | D·L·R·H | force rollup |
| M5.W5 | `BizCity_CRM_CSAT_Survey` | `reports/class-csat-survey.php` | D·L·R·H | force resolve |
| M6.W1 | `BizCity_CRM_Campaign_Repository` | `campaigns/class-campaign-repository.php` | D·L·R | create test |
| M6.W2 | `BizCity_CRM_QR_Generator` | `campaigns/class-qr-generator.php` | D·L·R | generate PNG |
| M6.W3 | `BizCity_CRM_Campaign_Tracker` | `campaigns/class-campaign-tracker.php` | D·L·R·H | simulate scan |
| M6.W5 | `BizCity_CRM_Loyalty_Bridge` | `campaigns/class-loyalty-bridge.php` | D·L·R | award test (dedupe) |
| M6.W5 | `BizCity_CRM_Action_Award_Points` | `automation/actions/class-action-award-points.php` | D·L·R | dry-run |
| M6.W6 | `BizCity_CRM_Flow_Importer` | `campaigns/class-flow-importer.php` | D·L·R | preview import |
| M7.W2 | `BizCity_CRM_Adapter_Instagram` | `adapters/class-adapter-instagram.php` | D·L·R·F | send test |
| M7.W2 | `BizCity_CRM_Adapter_WhatsApp_Cloud` | `adapters/class-adapter-whatsapp-cloud.php` | D·L·R·F | send template |
| M7.W3 | `BizCity_CRM_Adapter_Telegram` | `adapters/class-adapter-telegram.php` | D·L·R·F | bot getMe |
| M7.W3 | `BizCity_CRM_Adapter_Email_IMAP` | `adapters/class-adapter-email-imap.php` | D·L·R·H (cron) | poll mock |
| M7.W3 | `BizCity_CRM_Adapter_Web_Widget` | `adapters/class-adapter-web-widget.php` | D·L·R·F | snippet preview |
| M7.W5 | `BizCity_CRM_Bridge_FB` | `bridges/class-fb-bot-bridge.php` | D·L·R | bot plugin probe |
| M7.W5 | `BizCity_CRM_Bridge_Zalo` | `bridges/class-zalo-bot-bridge.php` | D·L·R | bot plugin probe |
| M7.W5 | `BizCity_CRM_Bridge_Google` | `bridges/class-google-tool-bridge.php` | D·L·R | OAuth account list |

**Layer legend**: D=Disk, L=Loader, R=Runtime, H=Hook attached, F=Filter registered.

---

# 📋 Progress board (manually update khi commit)

| Milestone | Wave | Status | Date | Commit | Diag % |
|---|---|---|---|---|---|
| M1 | W1 DB Migration | ✅ | 2026-05-11 | (uncommitted) | 4/4 |
| M1 | W2 Capabilities | ✅ | 2026-05-11 | (uncommitted) | 3/3 |
| M1 | W3 Grid filters | ✅ | 2026-05-11 | (uncommitted) | 3/3 |
| M1 | W4 Snooze | ✅ | 2026-05-11 | (uncommitted) | 3/3 |
| M2 | W1 Engine skeleton | ✅ | 2026-05-12 | (uncommitted) | 4/4 |
| M2 | W2 Evaluator | ✅ | 2026-05-12 | (uncommitted) | 5/5 |
| M2 | W3 Actions × 10 | ✅ | 2026-05-12 | (uncommitted) | 10/10 |
| M2 | W4 KG action | ⚪ | — | (deferred) | 0/6 |
| M2 | W5 REST | ✅ | 2026-05-12 | (uncommitted) | 5/5 |
| M2 | W6 React UI | ⚪ | — | (deferred) | 0/5 |
| M3 | W1 Labels CRUD | ✅ | 2026-05-13 | (uncommitted) | 5/5 |
| M3 | W2 Label UI | ⚪ | — | (deferred) | 0/3 |
| M3 | W3 Custom Attrs | ✅ | 2026-05-13 | (uncommitted) | 5/5 |
| M3 | W4 Template Renderer | ✅ | 2026-05-13 | (uncommitted) | 4/4 |
| M3 | W5 Macros | ✅ | 2026-05-13 | (uncommitted) | 5/5 |
| M4 | W1 Working Hours | ✅ | 2026-05-14 | (uncommitted) | 4/5 |
| M4 | W2 SLA Policy | ✅ | 2026-05-14 | (uncommitted) | 4/4 |
| M4 | W3 SLA Evaluator | ✅ | 2026-05-14 | (uncommitted) | 5/6 |
| M4 | W4 UI + wire | ⚪ | — | (deferred FE) | 0/3 |
| M5 | W1 Report Builder | ✅ | 2026-05-14 | (uncommitted) | 4/4 |
| M5 | W2 Daily Rollup | ✅ | 2026-05-14 | (uncommitted) | 3/3 |
| M5 | W3 KPI cards | ⚪ | — | (deferred FE) | 0/4 |
| M5 | W4 Auto vs Human | ⚪ | — | (deferred FE; backend route ready) | 0/3 |
| M5 | W5 CSAT + Audit | ✅ | 2026-05-14 | (uncommitted) | 5/5 |
| M6 | W1 Campaign schema | ✅ | 2026-05-15 | (uncommitted) | 3/4 (FE wave deferred → W8) |
| M6 | W2 QR + UTM | ✅ | 2026-05-15 | (uncommitted) | 4/4 |
| M6 | W3 Visit tracking | ✅ | 2026-05-15 | (uncommitted) | 5/5 |
| M6 | W4 Conversion link | ✅ | 2026-05-15 | (uncommitted) | 5/5 (incl. [kiem_tra_diem]+[doi_diem] bridge) |
| M6 | W5 Loyalty Bridge | ⚪ | — | — | 0/6 |
| M6 | W6 Flow Importer | ⚪ | — | — | 0/4 |
| M6 | W7 Funnel report | ⚪ | — | — | 0/2 |
| M6 | W8 Campaign Authoring UI (FE) | ⚪ | — | needs M-FE.W9+W14 | 0/6 |
| M6 | W9 Campaign ↔ Scenario Binding (BE) | ⚪ | — | unblocks W8 dropdowns | 0/6 |
| M7 | W1 Wizard | ✅ | 2026-03-28 | (uncommitted) | 3/3 |
| M7 | W2 IG + WA | 🟡 | 2026-03-28 | (uncommitted) | 2/4 |
| M7 | W3 TG + Email + Web | 🟡 | 2026-03-28 | (uncommitted) | 2/3 |
| M7 | W4 Health | 🟡 | 2026-03-28 | (uncommitted) | 1/2 |
| M7 | W5 Bot Sync (FB/Zalo/Google) | ✅ | 2026-05-11 | (uncommitted) | 5/5 |
| M-FE | W1 Tab Shell | ✅ | 2026-05-15 | (uncommitted) | 5/5 |
| M-FE | W2 Inbox enhancements (badges/labels/sla/macros) | ⚪ | — | (deferred — wait W9 DataTable) | 0/6 |
| M-FE | W3 Reports tab (KPI + auto-vs-human) | ✅ | 2026-05-15 | (uncommitted) | 5/5 |
| M-FE | W4 Automation tab (list + dry-run) | ✅ | 2026-05-15 | (uncommitted) | 5/5 |
| M-FE | W5 Labels+Macros+Attrs tabs | ✅ | 2026-05-15 | (uncommitted) | 3/3 |
| M-FE | W6 SLA & Hours tab | ✅ | 2026-05-15 | (uncommitted) | 4/4 |
| M-FE | W7 Audit + Channels tabs | ✅ | 2026-05-15 | (uncommitted) | 4/4 |
| M-FE | W8 Polish + diag (dark mode, hotkeys, FE diag) | ✅ | 2026-05-12 | (uncommitted) | 6/6 |
| M-FE | W9 shadcn/ui base + DataTable | ✅ | 2026-05-12 | (uncommitted) | 8/8 |
| M-FE | W10 Audit Timeline component | ✅ | 2026-05-12 | (uncommitted) | 4/4 |
| M-FE | W11 Activity Feed (infinite scroll) | ✅ | 2026-05-12 | (uncommitted) | 5/5 |
| M-FE | W12 Find Similar drawer | ✅ | 2026-05-12 | (uncommitted) | 4/4 |
| M-FE | W13 Invoice Line Items editor | ✅ | 2026-05-12 | (uncommitted) | 6/6 |
| M-FE | W14 Sheet Form drawer chuẩn hoá | ✅ | 2026-05-12 | (uncommitted) | 4/4 |
| M-FE | W15 Command palette (cmd+k) | ✅ | 2026-05-12 | (uncommitted) | 3/3 |
| M-FE | W16 Theme + i18n switcher | ✅ | 2026-05-12 | (uncommitted) | 4/4 |
| M-FE | W17 CRM modules BE (Accounts/Contacts/Tasks/Calendar/Documents) + RTK swap | ✅ | 2026-05-12 | (uncommitted) | 6/6 |
| M-CRM | M1 Sales Pipeline (Lead/Opp/Contract) BE | ✅ | 2026-05-12 | (uncommitted) | 6/6 |
| M-CRM | M1.W2 Product Catalog & line-item normalization | ✅ | 2026-05-12 | (uncommitted) | 5/5 |
| M-CRM | M1.W3 Centralized AuditLog + Activity model | ⚪ | — | needs M1 + M4 | 0/5 |
| M-CRM | M1.W4 Multi-currency snapshot rates + FX table | ⚪ | — | needs M1 | 0/4 |
| M-CRM | M1.W5 Sales-stage taxonomy isolation (DB-driven) | ⚪ | — | needs M1 | 0/4 |
| M-CRM | M2 Invoicing BE (lifecycle + tax + PDF) | 🟡 | DB v1.9.0 + REST + cron | DB `_bizcity_crm_*` | 8/8 |
| M-CRM | M3 Email Client BE (IMAP/SMTP sync) | 🟡 | DB v1.9.0 + REST + 5-min poll | DB `_bizcity_crm_*` | 7/7 |
| M-CRM | M4 Audit hardening (diff JSON, soft-delete) | ⚪ | — | enables M-FE.W10 | 0/4 |
| M-CRM | M5 Sales Pipeline FE (Kanban + detail tabs) | ⚪ | — | needs W9 + M1 | 0/6 |
| M-CRM | M6 Invoicing FE (list + detail + W13) | 🟡 | 2026-05-12 | RTK endpoints + InvoicesTab live (CRUD/transition/payments/PDF/send) | 5/6 |
| M-CRM | M7 Email Client FE (Gmail-style) | 🟡 | 2026-05-12 | RTK endpoints + EmailTab 3-pane live (accounts/threads/compose/sync) | 5/5 |
| M-Bridge | W1 Inbox→CRM webhook adapter | ⚪ | — | conv→Activity→Lead | 0/4 |
| M-Bridge | W2 "Convert to Lead/Opp" UI action | ⚪ | — | needs M-CRM.M1 | 0/3 |
| M-RM | R1 MCP server bridge (read-only) | 📝 | — | roadmap only | — |
| M-RM | R2 Vector search microservice | 📝 | — | roadmap only | — |
| M-RM | R3 E2B AI enrichment agent | 📝 | — | roadmap only | — |
| M-CRM | M8 Targets & List Management BE (cold-outbound) | ⚪ | — | needs M1 + M-RAG.M1 | 0/6 |
| M-CRM | M9.W1 Email Campaigns — builder/templates/steps BE | ⚪ | — | needs M8 | 0/7 |
| M-CRM | M9.W2 Email Campaigns — execution + tracking webhooks | ⚪ | — | needs M9.W1 + M3 | 0/6 |
| M-CRM | M10 Reports & Scheduled Exports (CSV/PDF + cron) | ⚪ | — | needs M1 | 0/5 |
| M-CRM | M11 Admin Panel — API keys (3-tier) + Bearer tokens (scoped) | ⚪ | — | needs M4 | 0/6 |
| M-RAG | R1 Vector embeddings + hybrid search (entities + chunks) | ⚪ | — | needs M-CRM.M1 | 0/8 |
| M-RAG | R2 Find Similar entities (DB-driven) | ⚪ | — | needs M-RAG.R1 | 0/3 |
| M-RAG | R3 RAG Q&A over CRM corpus | ⚪ | — | needs M-RAG.R1 | 0/4 |
| M-PM | M1.W1 Project boards/sections/tasks BE | ⚪ | — | standalone | 0/5 |
| M-PM | M1.W2 Kanban DnD + watchers + comments FE | ⚪ | — | needs M-PM.M1.W1 + W9 | 0/5 |
| M-NOTIFY | N1 In-app notifications + digest emails | ⚪ | — | needs M1 | 0/5 |
| M-NOTIFY | N2 Real-time SSE/WS push (notifications + activity) | ⚪ | — | needs N1 | 0/4 |
| M-INT | I1 Outbound webhook subscriptions (HMAC + retry) | ⚪ | — | needs M4 | 0/4 |
| M-INT | I2 OAuth connectors (Slack/Teams/Zapier) | ⚪ | — | needs I1 | 0/4 |
| M-IMP | M1 Bulk CSV import + dry-run + de-dupe | ⚪ | — | needs M1 | 0/4 |
| M-CRM | M12 Calendar Channel — unify `core/scheduler` + `bizgpt-tool-google` into CRM Channel Settings | ⚪ | — | needs M1 + scheduler module + tool-google | 0/5 |
| M-CRM | M13 Loyalty / Referral Channel — bridge `bizgpt-custom-flows` (UTM/QR/referral) into Campaigns + Activities | ⚪ | — | needs M9 + custom-flows plugin | 0/5 |
| M-INFRA | SMTP1 Default-on Gmail/SMTP bridge in `core/smtp/` (port mu-plugin) | ✅ | 2026-05-12 | (uncommitted) | 3/3 |
| M-INFRA | SMTP2 SMTP card in CRM Channel Settings (option-driven UI) | ⚪ | — | needs SMTP1 + M-CRM Channel Settings | 0/3 |

**Legend**: ⚪ not started · 🟡 in progress · ✅ pass · 🔴 blocked · 🟣 skipped · 📝 roadmap only (no code)

---

# 📦 NextCRM-inspired expansion — các quyết định kiến trúc

> Sau khi nghiên cứu `_library/nextcrm-app-main` (Next.js 16 + Prisma + shadcn/ui), đội chốt 5 quyết định sau để mở các wave M-FE.W9→W16, M-CRM.M1→M7, M-Bridge.W1→W2.

## Quyết định kiến trúc

| # | Chủ đề | Quyết định | Ghi chú |
|---|---|---|---|
| 1 | Storage cho Opp/Contract/Invoice/Email | **Dùng WP MySQL hiện tại**, prefix `{$wpdb->prefix}bizcity_crm_*` | Không sử PostgreSQL sidecar; giữ monolith WP để đơn giản hosting |
| 2 | UI library | **Adopt shadcn/ui** — copy components vào `frontend/src/components/ui/`, thay dần `@headlessui/react` | Cài `@radix-ui/*`, `class-variance-authority`, `clsx`, `tailwind-merge`, `lucide-react`, `@tanstack/react-table`, `cmdk`, `@dnd-kit/*` |
| 3 | MCP server | **Roadmap only**, chưa code (M-RM.R1) | Khi cần mới build Node sidecar wrap WP REST |
| 4 | i18n EN/VI | **Bật sau cùng** (M-FE.W16) | Dùng `react-i18next` thuần client-side — không có SSR |
| 5 | Fork NextCRM? | **Không fork** — chỉ học FE UI/UX patterns | Code Prisma/Next.js Server Actions/Inngest không port; chỉ mang components + UX flow |
| 6 | Sequencing | **UI-first** — code toàn bộ FE shell + mock data trước, BE đi sau | Sprint plan: M-FE.W9→W16 + M-CRM.M5/M6/M7 (FE shells với mock) ⟶ rồi mới M-CRM.M1/M2/M3/M4 (BE) |

> **UI-first sprint plan (đã chốt 2026-05-12)**:
> 1. M-FE.W9 shadcn base + DataTable
> 2. M-FE.W14 Sheet Form chuẩn hoá
> 3. M-FE.W15 Command palette + M-FE.W16 Theme toggle (dark mode chỉ — i18n để cuối)
> 4. **M-CRM.M5/M6/M7 FE shells** (Sales / Invoices / Email tabs) chạy mock data, in-memory hoặc fixture
> 5. M-FE.W10 Audit Timeline + W11 Activity Feed (mounted vào inbox detail, mock)
> 6. ⟶ Sau khi UI hoàn chỉnh, lùi về làm **M-CRM.M1/M2/M3/M4 BE** thật, swap mock → RTK Query endpoints
> 7. M-FE.W16 i18n ⟶ lock cuối

## Convention bind của các bảng mới (M-CRM)

Tất cả bảng mới tuân thủ R-DB:
- Tiền tố: `{$wpdb->prefix}bizcity_crm_<entity>` (snake_case số ít)
- Charset: `utf8mb4` / collation `utf8mb4_unicode_ci`
- Cột bắt buộc: `id BIGINT UNSIGNED PK AUTO_INCREMENT`, `created_at DATETIME DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP`, `created_by BIGINT UNSIGNED`, `deleted_at DATETIME NULL` (soft delete)
- Mọi mutation đi qua `Bizcity_CRM_Audit_Log::write()` ghi diff JSON vào `bizcity_crm_audit_log`
- Mọi REST endpoint dưới `bizcity-crm/v1/`, response `{ok, data, error?}` — như hiện tại

## Danh sách bảng sẽ tạo (R-DB checklist)

```
bizcity_crm_account               -- (nâng cấp contact hiện có, opt)
bizcity_crm_lead                  -- M-CRM.M1.W1
bizcity_crm_opportunity           -- M-CRM.M1.W2
bizcity_crm_opportunity_line      -- M-CRM.M1.W2 junction
bizcity_crm_contract              -- M-CRM.M1.W3
bizcity_crm_contract_line         -- M-CRM.M1.W3 junction
bizcity_crm_activity              -- M-CRM.M4 (replace single notes)
bizcity_crm_activity_link         -- M-CRM.M4 multi-entity link
bizcity_crm_audit_log             -- M-CRM.M4 (nâng cấp audit hiện tại)
bizcity_crm_invoice               -- M-CRM.M2.W1
bizcity_crm_invoice_line          -- M-CRM.M2.W2 junction
bizcity_crm_invoice_payment       -- M-CRM.M2.W3
bizcity_crm_invoice_series        -- M-CRM.M2.W4
bizcity_crm_tax_rate              -- M-CRM.M2.W4
bizcity_crm_currency              -- M-CRM.M2.W5 (multi-FX, base + rate)
bizcity_crm_email_account         -- M-CRM.M3.W1 (IMAP/SMTP creds, encrypted)
bizcity_crm_email_message         -- M-CRM.M3.W2
bizcity_crm_email_folder          -- M-CRM.M3.W2
```

---

# 🎨 M-FE.W9 — shadcn/ui base + DataTable port

**Goal**: Đặt nền móng UI library shadcn cho toàn bộ tabs sắp tới (M-CRM FE + những tab refactor).

## Steps
1. Install deps: `@radix-ui/react-{dialog,dropdown-menu,popover,select,tabs,toast,tooltip,checkbox,switch,label,slot}`, `class-variance-authority`, `clsx`, `tailwind-merge`, `lucide-react`, `@tanstack/react-table`, `cmdk`.
2. Setup `frontend/src/lib/utils.js` exporting `cn(...inputs)` helper (clsx+tailwind-merge).
3. Copy ~12 base shadcn components vào `frontend/src/components/ui/`: `button.jsx`, `input.jsx`, `select.jsx`, `dialog.jsx`, `sheet.jsx`, `tabs.jsx`, `table.jsx`, `dropdown-menu.jsx`, `tooltip.jsx`, `badge.jsx`, `card.jsx`, `toast.jsx` + `use-toast.js`.
4. Build `components/ui/data-table.jsx` — generic TanStack Table v8 wrapper:
   - props: `columns`, `data`, `pageCount`, `onPaginationChange`, `onSortingChange`, `onFiltersChange`, `loading`
   - features: column visibility toggle, sort header, search input, pagination footer
   - đồng bộ với RTK Query: `data` phải là `{rows, total}`
5. Refactor 3 tab hiện có để dùng `<DataTable>`: AutomationTab (rules), AttrsTab, SLA policies — chứng minh rép drop-in.
6. Refactor `tailwind.config.cjs` thêm shadcn theme tokens (`colors.background, foreground, primary, ...`) + `animate-accordion-down/up` keyframes.
7. Build `Toaster` mount trên App root, replace tất cả `window.alert()` bằng `toast()` (12 chỗ trong M-FE.W4/W5/W6).
8. **Output**: bundle delta ≤ +120KB gzip; một snapshot screenshot AutomationTab với DataTable mới.

**Files cần sửa**: `package.json`, `tailwind.config.cjs`, `frontend/src/components/ui/*` (mới), `routes/automation/AutomationTab.jsx`, `routes/attributes/AttrsTab.jsx`, `routes/sla/SlaTab.jsx`, `App.jsx` (mount Toaster).

---

# 🎨 M-FE.W10 — Audit Timeline component

**Goal**: Render history change diff per-entity — enable cho mọi detail page (Contact, Conv, Lead, Opp, Contract, Invoice).

## Steps
1. Build `components/audit/AuditTimeline.jsx` — vẽ cột dọc, sort theo `created_at desc`, group theo ngày.
2. Build `components/audit/AuditEntry.jsx` — 1 cụm: avatar user + action badge (`created/updated/deleted/restored`) + timestamp + JSON diff key-value.
3. RTK Query endpoint `getAuditLog({entity_type, entity_id, before_id?})` — backend `M-CRM.M4`.
4. Drop vào tab `History` của mỗi detail page.

---

# 🎨 M-FE.W11 — Activity Feed (infinite scroll)

**Goal**: Replace simple notes bằng feed activity gộp `note/call/meeting/email/task`, link đa entity.

## Steps
1. RTK Query `useGetActivitiesInfinite({entity_type, entity_id})` — compound cursor `(created_at, id)`.
2. `components/activities/ActivityFeed.jsx` — IntersectionObserver lấy trang tiếp.
3. `components/activities/ActivityCard.jsx` — icon by type, body markdown.
4. `components/activities/ActivityForm.jsx` — Sheet drawer create/edit, type selector + rich content.
5. Drop vào tab `Activities` của Contact/Conv/Lead/Opp/Contract.

---

# 🎨 M-FE.W12 — Find Similar drawer (deferred)

**Goal**: Dưới mỗi detail page, nút "Tìm tương tự" mở drawer hiển thị top-K records cosine-similar.

**Bloc by**: M-RM.R2 (vector search microservice). Scaffold UI trước, mock data, bật khi M-RM.R2 ready.

---

# 🎨 M-FE.W13 — Invoice Line Items editor

**Goal**: Bảng inline-editable cho dynamic line items với auto-calc.

## Steps
1. `components/invoice/LineItemsEditor.jsx`:
   - Columns: Product/Description, Qty, Unit price, Discount %, Tax rate, Subtotal, VAT, Total
   - Auto-recalc on blur per row + grand total + VAT summary buckets
2. Hook `useInvoiceMath(lines, currency)` returns `{subtotal, totalTax, total, taxBreakdown[]}`.
3. RTK Query `upsertInvoiceLines({invoice_id, lines[]})` — batch.
4. Currency display via `Intl.NumberFormat` (locale từ future i18n).
5. "+ Add line" và row delete, drag handle reorder (dnd-kit later).
6. Cân nắc edit when invoice `status === 'draft'` only.

---

# 🎨 M-FE.W14 — Sheet Form chuẩn hoá

**Goal**: Pattern create/edit duy nhất cho mọi entity — dùng shadcn Sheet.

## Steps
1. `components/forms/EntitySheet.jsx` — wrapper Sheet + `react-hook-form` + zod resolver.
2. Helpers: `useEntityForm(schema, defaultValues)`, `<FormField/>`, `<FormError/>`.
3. Migrate: LabelForm, MacroForm, AttrForm, PolicyForm — về dùng EntitySheet để thay form rời rạc.
4. Documentation snippet trong file này.

---

# 🎨 M-FE.W15 — Command palette (⌘+K)

**Goal**: Jump nhanh giữa tabs / entities / actions.

## Steps
1. Install `cmdk`. Mount `<CommandPalette/>` global; mở bằng ⌘+K / Ctrl+K.
2. Action catalog: `goto:tab/<id>`, `goto:conv/<id>` (search inbox), `action:new-rule`, `action:new-label`, `action:new-macro`...
3. Recent items section (lastVisited slice).
4. Keyboard nav, fuzzy match.

---

# 🎨 M-FE.W16 — Theme + i18n switcher (sau cùng)

## Steps
1. Dark mode via Tailwind `class` strategy + `next-themes`-equivalent (build nhỏ, store in localStorage).
2. CSS variables setup cho shadcn (`--background, --foreground, --muted...`) hai theme.
3. `react-i18next` + 2 namespace `vi/en` — wrap các text của tất cả tabs.
4. `<LangSwitch/>` và `<ThemeToggle/>` gắn vào TabBar góc phải.

---

# 🗄️ M-CRM.M1 — Sales Pipeline BE (Lead/Opp/Contract)

**Goal**: 3 bảng + REST + capabilities + audit hookup.

## Steps (waves nội bộ)
- **W1 Lead schema + REST**: `bizcity_crm_lead` (cols: `name, email, phone, source, status, owner_id, score, contact_id?`). REST CRUD `/leads`. Capabilities `crm_lead_view/edit`.
- **W2 Opportunity + line items**: `bizcity_crm_opportunity` (`title, account_id, contact_id, lead_id?, sales_stage, probability, amount, currency, expected_close_date, owner_id`) + `bizcity_crm_opportunity_line` (`opportunity_id, product, qty, unit_price, discount_percent, tax_rate`). REST `/opportunities`, `/opportunities/{id}/lines`.
- **W3 Contract + line items**: `bizcity_crm_contract` (`number, account_id, opportunity_id?, start_date, end_date, renewal_date, status, total_amount, currency`) + `bizcity_crm_contract_line`. REST `/contracts`.
- **W4 Conversion API**: `POST /leads/{id}/convert` → tạo opportunity; `POST /opportunities/{id}/convert-to-contract`.
- **W5 Pipeline reports**: mở rộng `Report_Builder` thêm metrics `pipeline_value`, `win_rate`, `avg_deal_size`, group by `sales_stage`.
- **W6 Sprint diag**: rows `T-P0.35.CRM.M1.{1..6}`.

---

# 🗄️ M-CRM.M2 — Invoicing BE

## Steps
- **W1 Invoice schema + lifecycle**: `bizcity_crm_invoice` (`number, type[invoice|credit_note|proforma|receipt], account_id, contact_id, status[draft|issued|paid|partially_paid|cancelled], issue_date, due_date, currency, subtotal, total_tax, total, balance_due, opportunity_id?, contract_id?`). State machine.
- **W2 Line items + tax engine**: `bizcity_crm_invoice_line` (`position, description, qty, unit_price, discount_percent, tax_rate_id, subtotal, vat, total`). Server-side recompute on PUT.
- **W3 Payments**: `bizcity_crm_invoice_payment` (`invoice_id, amount, paid_at, method, note`). Auto-update `balance_due` + `status`.
- **W4 Series + Tax rates**: `bizcity_crm_invoice_series` (auto-numbering `INV-{YYYY}-{####}`), `bizcity_crm_tax_rate` (`name, rate, region`). REST CRUD admin-only.
- **W5 Currency + FX**: `bizcity_crm_currency` (`code, symbol, base_rate, updated_at`). `Intl.NumberFormat`-friendly.
- **W6 PDF generation**: PHP `mPDF` hoặc `dompdf` — endpoint `GET /invoices/{id}/pdf`.
- **W7 Send email**: REST `POST /invoices/{id}/send` — dùng `wp_mail` hoặc Resend bridge.
- **W8 Sprint diag**: rows `T-P0.35.CRM.M2.{1..8}`.

---

# 🗄️ M-CRM.M3 — Email Client BE (IMAP/SMTP)

## Steps
- **W1 Account schema**: `bizcity_crm_email_account` (`user_id, provider, imap_host, imap_port, smtp_host, smtp_port, username, password_enc, last_uid_inbox, last_uid_sent`). Encrypt password with `wp_salt()` + AES-256-GCM helper.
- **W2 Folder + Message tables**: `bizcity_crm_email_folder` (`account_id, name, path, last_uid`), `bizcity_crm_email_message` (`account_id, folder_id, uid, message_id, from, to[], cc[], subject, body_html, body_text, has_attachments, contact_id?, conversation_id?, raw_size, received_at, read`).
- **W3 IMAP sync cron**: WP-Cron 5 min, hỗ trợ `php-imap` hoặc `webklex/php-imap` (composer). Incremental UID pull.
- **W4 SMTP send**: REST `POST /emails/send` — dùng `PHPMailer` (bó sẵn WP).
- **W5 Auto-link**: match `from`/`to` với `bizcity_crm_contact` — set `contact_id`.
- **W6 Sprint diag**: rows `T-P0.35.CRM.M3.{1..6}`.

---

# 🗄️ M-CRM.M4 — Audit hardening

**Goal**: Backend cho M-FE.W10. Tạo bảng audit chuẩn + diff JSON, soft-delete xuyên suốt.

## Steps
- **W1**: `bizcity_crm_audit_log` (`entity_type, entity_id, action, changes_json, user_id, request_id, created_at`). Class `Bizcity_CRM_Audit_Log` với `write($entity_type, $entity_id, $action, $before, $after)` gọi `wp_json_encode( diffObjects($before, $after) )`.
- **W2**: Helper `diff_objects($a, $b)` — deep diff field-level.
- **W3**: REST `GET /audit-log?entity_type=&entity_id=&before_id=` (admin + entity owner).
- **W4**: Soft-delete: thêm `deleted_at` vào mọi bảng CRM hiện có (lead/opp/contract/invoice/activity); helper query macặc định exclude. Action `restore` REST.

---

# 🗄️ M-CRM.M5/M6/M7 — FE của sales/invoice/email

- **M5**: Pages `Pipeline` (Kanban dnd-kit drag opportunity giữa sales stages), Lead list, Opp/Contract detail với tabs `Overview/LineItems/Activities/Documents/History`. Thêm tab top-level **Sales** (ô tộc mới trong TabBar.jsx).
- **M6**: Tabs top-level **Invoices** — list (DataTable), detail (PDF preview pane + W13 line items editor + payments tab + send button).
- **M7**: Tab top-level **Email** — layout 3 cột (folder sidebar / message list virtualized / preview pane). Compose modal Tiptap.

---

# 🔗 M-Bridge.W1 — Inbox→CRM webhook adapter

**Goal**: Mỗi conversation `resolved` trong Twin Inbox → tự sinh `bizcity_crm_activity` link contact + (optional) lead.

## Steps
1. Listener `crm_conversation_resolved` event → tạo activity type `chat_session` với `body=summary, contact_id, conversation_id`.
2. Nếu contact chưa có `lead_id`, hiển nút "Convert to Lead" trong `ConversationDetail.jsx`.
3. REST `POST /conversations/{id}/convert-to-lead` → tạo lead, link conversation.
4. Sprint diag: `T-P0.35.BRIDGE.W1.{1..4}`.

## M-Bridge.W2 — "Convert to Lead/Opp" UI
- Button trong tab Inbox / detail → mở Sheet form để tạo Lead/Opportunity với prefill từ contact + summary.

---

# 🗺️ M-RM (Roadmap-only — chưa code)

## R1 — MCP server bridge (read-only)
Node sidecar wrap `/bizcity-crm/v1/*` REST → expose qua MCP tools (Bearer token SHA-256). Inspired by `lib/mcp/tools/*` của NextCRM. Defer.

## R2 — Vector search microservice
Qdrant hoặc Postgres+pgvector sidecar. Auto-embed contact/lead/opp/conv qua webhook. Endpoint `find-similar/{type}/{id}`. Defer (bị M-FE.W12 block-soft).

## R3 — E2B AI enrichment agent
E2B Chrome sandbox + Claude tool-loop. Trigger "Enrich Contact" → fan-out C-level discovery. Defer.

---

# 🛠️ Deblog quick reference

| Scope | When emitted | Key fields | Filter env |
|---|---|---|---|
| `db.migrate` | per ALTER | `table`, `column`, `result` | always |
| `automation.engine` | enter/exit on_event | `event_name`, `rule_count`, `event_uuid` | `WP_DEBUG` |
| `automation.engine.depth` | recursion ≥ 3 | `depth`, `request_id`, `chain[]` | always (warn) |
| `automation.action.<code>` | per action exec | `rule_id`, `args`, `result`, `error?` | `WP_DEBUG` |
| `automation.kg_reply` | KG action lifecycle | `notebook_id`, `tokens`, `latency_ms`, `event_uuid` | always |
| `sla.evaluator` | per tick | `evaluated`, `breached`, `met`, `lock_held` | always |
| `campaign.tracker` | per visit | `campaign_id`, `mode` (msgr/web), `client_id`, `utm` | always |
| `loyalty.bridge` | per award/dedupe | `subject`, `points`, `event_uuid`, `dedupe_hit` | always |
| `report.builder` | per aggregate | `metric`, `group_by`, `range`, `rows`, `took_ms` | `WP_DEBUG` |

Tail command for live dev:
```powershell
Get-Content -Wait -Tail 30 .\wp-content\debug.log | Select-String 'BIZCITY_CRM|automation\.|sla\.|campaign\.|loyalty\.'
```

---

**End of PHASE 0.35 wave detail.**

---

## §H — M-CRM.M6 + M7 FE Implementation Notes (2026-05-12)

### M6 Invoicing FE
- `frontend/src/redux/api/crmApi.js` — Added 9 endpoints under tag `CrmInvoice`:
  `getCrmInvoices`, `getCrmInvoice`, `createCrmInvoice`, `updateCrmInvoice`,
  `deleteCrmInvoice`, `transitionCrmInvoice`, `addCrmInvoicePayment`,
  `deleteCrmInvoicePayment`, `sendCrmInvoice`.
- `frontend/src/routes/invoices/InvoicesTab.jsx` — Rewrote from mock to live API:
  - KPI cards (issued / paid / outstanding) computed from live data.
  - DataTable with status filter (`status` query → BE).
  - Create sheet with `LineItemsEditor`; FE↔BE field mapping
    (`qty`↔`quantity`, `discount_percent`↔`discount_pct`, `tax_rate`↔`tax_pct`).
  - Detail sheet: state-machine transition buttons (only legal next-states),
    Payments tab (add/delete + table), Send tab (email + PDF link), Delete (only draft/voided).
  - PDF: rendered as `<a target="_blank">` to
    `…/crm-invoices/{id}/pdf?_wpnonce=…` (raw HTML, not JSON).

### M7 Email Client FE
- `frontend/src/redux/api/crmApi.js` — Added 10 endpoints under tags
  `CrmEmailAccount` + `CrmEmailThread`:
  `getCrmEmailAccounts`, `getCrmEmailAccount`, `createCrmEmailAccount`,
  `updateCrmEmailAccount`, `deleteCrmEmailAccount`, `syncCrmEmailAccount`,
  `getCrmEmailThreads`, `getCrmEmailThread`, `markCrmEmailThreadRead`, `sendCrmEmail`.
- `frontend/src/routes/email/EmailTab.jsx` — Rewrote from mock to live, Gmail-style 3-pane:
  - **Left**: account list (label/email), Sync button → `poll_account()`,
    "Unread only" toggle, Add/Edit/Delete account dialogs.
  - **Middle**: thread list with subject + participants (parsed from
    `participants_json`) + `message_count` + `unread_count` badge + search by subject.
  - **Right**: thread reader, all messages chronologically, Reply pre-fills
    `to`, `subject (Re: …)`, `thread_id`, `in_reply_to` (last message's `message_id_header`).
  - Compose modal: account picker, To/Cc/Bcc (csv ok — BE splits), Subject, body_html.

---

# 🛒 M-CRM.M8 — Woo Bridge & Contact Unification (2026-05-13)

> **Wave**: 6 · **Estimated**: 1.5 sprint · **Risk**: HIGH (data migration + dual-read window)
> **Mục tiêu**: chấm dứt fragmentation `bizcity_crm_contacts` ↔ `bizcity_crm_biz_contacts`,
> đưa **WooCommerce làm single source of truth** cho Order/Customer/Revenue, và unify Reports
> dashboard từ Woo + CRM events. Tất cả adapter/bridge ship dưới `includes/woo/` để dễ quản.

## Phân tích phản biện (decision record)

### Vấn đề observed (production hiện tại)
1. **Contact phân mảnh** — 2 bảng độc lập, không FK, không sync:
   - `bizcity_crm_contacts` (đã có `wp_user_id`, `acquisition_source`, `points_balance_cache`)
     → ingest từ FB Messenger, Zalo, web widget qua `class-fb-ingestor.php`.
   - `bizcity_crm_biz_contacts` (B2B style: `first_name/last_name/title/account_id`)
     → form "+ New contact" UI insert qua `class-rest-controller.php::post_crm_contact()`.
   - Hệ quả: 1 khách FB Messenger + 1 khách self-registered = 2 hồ sơ tách biệt; KPI
     "tổng khách hàng" không tính được; remarketing/loyalty không cross-channel.
2. **Đơn hàng inbox không link Invoices/Reports**:
   - `class-order-adapter.php` gọi `wc_create_order()` → nằm ở Woo (`wc_orders` HPOS).
   - Tab "Invoices" đọc `bizcity_crm_invoices` table riêng — **không thấy đơn từ inbox**.
   - Tab "Reports" (`get_reports_aggregate`) chỉ count conversations/messages — **không touch
     revenue/AOV/refund của Woo**.
   - Dashboard hardcode demo numbers.
3. **Contact ↔ Woo customer drift**: contact có `wp_user_id` nhưng KHÔNG đọc/sync
   `wp_usermeta` (`billing_first_name`, `billing_phone`, `billing_address_1`, …) → CRM
   không hiển thị địa chỉ giao hàng / lịch sử mua đầy đủ.

### Lựa chọn kiến trúc (chốt)

| Quyết định | Lý do | Trade-off chấp nhận |
|---|---|---|
| **Woo = source of truth** cho Order/Customer/Revenue/Refund/Tax/Shipping | Tận dụng HPOS, Subscriptions, Tax zones, AffiliateWP/SliceWP, REST API, Reports core, gateway plugins (MoMo/VNPay/ZaloPay). Tránh duplicate logic + drift. | Phụ thuộc Woo (đã là hard dep cho order-adapter rồi). |
| **`bizcity_crm_contacts` = canonical contact** (giữ nguyên), `biz_contacts` deprecate | Bảng `contacts` đã có `wp_user_id`, `acquisition_source`, KEY `idx_wp_user`, `idx_email`, `idx_phone` — sẵn sàng làm canonical. `biz_contacts` chỉ là B2B overlay (account_id/title) → có thể move qua `additional_attributes` JSON hoặc bảng phụ `crm_contact_b2b_meta(contact_id PK)`. | Migrate FK của `crm_leads.contact_id`, `crm_opportunities.contact_id`, `crm_invoices.contact_id` từ `biz_contacts.id` → `contacts.id` (one-shot mapping table). |
| **`crm_invoices.wc_order_id`** column NULLable | Cho phép invoice link tới Woo order (auto-paid khi Woo `paid`); invoice "pro-forma" không link vẫn dùng được. | Hai-chiều sync cần lock + idempotent guard. |
| **Reports = unified adapter** đọc Woo `wc_get_orders()` + CRM events | KHÔNG copy data sang bảng riêng. Cache 5 phút qua transient cho dashboard. | Query nặng → cần indexes + chunked aggregation. |
| **Tất cả Woo bridge → `includes/woo/`** | Tách riêng để (a) skip-load nếu Woo inactive, (b) review/test/owner riêng, (c) future plugins (B2B Suite, Subscriptions) đặt cùng. | Refactor `class-order-adapter.php` (đã có) sang `includes/woo/class-woo-order-bridge.php`. |

### Counter-arguments đã cân nhắc

- **"Sao không bỏ luôn `crm_invoices` table, chỉ dùng Woo?"** → Vẫn giữ vì cần (a) pro-forma/quote
  trước khi khách confirm (Woo bắt buộc có order line + customer), (b) invoice cho dịch vụ
  CRM nội bộ không qua Woo (consulting fee, retainer), (c) email-thread tích hợp
  `class-invoice-pdf.php` đã ship M-CRM.M2.
- **"Contact dual-write có rủi ro race?"** → Dùng dual-read window 1 sprint: tất cả READ ưu tiên
  `contacts`, fallback `biz_contacts`; tất cả WRITE chỉ vào `contacts`. Sau khi monitor 0 traffic
  vào `biz_contacts` → drop bảng + redirect FK.
- **"Tại sao không dùng AffiliateWP table-level integration?"** → Nằm ngoài scope wave này; chỉ
  cần Woo order có meta `_bizcity_campaign_id` từ M6 → AffiliateWP tự thấy qua hook chuẩn.

## M-CRM.M8.W1 — Woo Bridge bootstrap & directory layout

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W1.1 | `includes/woo/class-woo-bridge.php` (NEW) | `BizCity_CRM_Woo_Bridge::boot()` — guard `class_exists('WooCommerce')`, register sub-bridges | "Woo bridge loaded?" → JSON `{wc_active, hpos_enabled, subbridges:[]}` |
| T-P0.35.M8.W1.2 | `includes/woo/class-woo-customer-bridge.php` (NEW) | maps `wp_user_id ↔ crm_contacts.id`, reads/writes `wp_usermeta` billing/shipping fields | probe with sample user_id → returns merged contact + woo meta |
| T-P0.35.M8.W1.3 | `bootstrap.php` mở rộng | conditional require khi `function_exists('WC')`; load order: AFTER `class-db-installer`, BEFORE `class-rest-controller` | `did_action('bizcity_crm_woo_bridge_loaded') === 1` |
| T-P0.35.M8.W1.4 | `class-order-adapter.php` MOVE → `includes/woo/class-woo-order-bridge.php` | preserve interface `BizCity_CRM_Order_Adapter_Interface` (BC alias), all `wc_create_order` calls relocated | regression: tạo đơn từ inbox ConversationDetail vẫn pass |

**Class diagnostics**:

| Class | Disk | Loader | Runtime | Probe |
|---|---|---|---|---|
| `BizCity_CRM_Woo_Bridge` | new `includes/woo/class-woo-bridge.php` | `bootstrap.php` (conditional on Woo active) | `class_exists` + `did_action('bizcity_crm_woo_bridge_loaded')` | dump JSON `{wc_version, hpos, sub_bridges}` |
| `BizCity_CRM_Woo_Customer_Bridge` | `includes/woo/class-woo-customer-bridge.php` | same | `method_exists('sync_from_user')` + `method_exists('write_billing_meta')` | sync test user_id → diff before/after |

**Rules check**: R-CRM-1 (snapshot tables, no log), R-PAR-1, R-WOO-1 (NEW: Woo as SoT for Order).

## M-CRM.M8.W2 — Contact unification migration (HIGH RISK)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W2.1 | `includes/class-db-installer.php` | `migrate_v0_36_contact_unify()` — adds `crm_contacts` cols: `first_name VARCHAR(95)`, `last_name VARCHAR(95)`, `title VARCHAR(120)`, `account_id BIGINT NULL` (B2B overlay merged) | dry-run shows pending ALTERs |
| T-P0.35.M8.W2.2 | NEW migration script `includes/woo/migrations/migrate-biz-contacts-to-contacts.php` | reads each `biz_contacts` row → upsert into `contacts` by email/phone (or insert new); writes mapping table `bizcity_crm_contact_id_map(old_biz_id, new_contact_id, migrated_at)` | dry-run returns `{would_insert, would_merge_by_email, would_merge_by_phone, conflicts}` |
| T-P0.35.M8.W2.3 | UPDATE `class-rest-controller.php` `get_crm_contacts/get_crm_contact/post_crm_contact/put_crm_contact/delete_crm_contact` | dual-read: SELECT từ `contacts` UNION ALL `biz_contacts` (LEFT JOIN map để dedupe); WRITE only `contacts` | hit `/crm-contacts` → returns merged list |
| T-P0.35.M8.W2.4 | UPDATE FK columns | `crm_leads.contact_id`, `crm_opportunities.contact_id`, `crm_invoices.contact_id` redirected qua mapping table → cập nhật bằng UPDATE JOIN | counts trước/sau bằng nhau |
| T-P0.35.M8.W2.5 | DROP `biz_contacts` (chỉ sau monitor 1 sprint dual-read 0 errors) | gắn behind admin button "Confirm legacy table drop" + downgrade SQL | rollback file exists |

**Diagnostics**:
| Probe | Expected output |
|---|---|
| `count_contacts_canonical` | `SELECT COUNT(*) FROM crm_contacts` |
| `count_contacts_legacy` | `SELECT COUNT(*) FROM crm_biz_contacts WHERE deleted_at IS NULL` |
| `count_unmigrated` | rows in `biz_contacts` chưa có entry trong `contact_id_map` |
| `dual_read_traffic` | hit counter cho fallback path (target: 0 sau 7 ngày → safe drop) |

## M-CRM.M8.W3 — Woo Customer ↔ CRM Contact 2-way sync

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W3.1 | `includes/woo/class-woo-customer-bridge.php` | `on_user_register()`, `on_profile_update()` hooks → upsert `crm_contacts` từ `wp_users` + `wp_usermeta` (`billing_first_name/last_name/email/phone/address_1/address_2/city/state/postcode/country`) | tạo user mới → contact xuất hiện trong CRM |
| T-P0.35.M8.W3.2 | same | `on_crm_contact_save()` → khi save contact có `wp_user_id` set, mirror back vào `wp_usermeta` billing | edit contact → check `usermeta` updated |
| T-P0.35.M8.W3.3 | same | `resolve_contact_for_order(WC_Order)` — match `customer_id` → `wp_user_id` → contact; nếu guest, dùng `billing_email/billing_phone` để dedupe | new Woo order from guest → contact dedupe đúng |
| T-P0.35.M8.W3.4 | `class-fb-ingestor.php` | khi tạo contact mới từ social, nếu `email/phone` match `wp_users` → set `wp_user_id` luôn | FB user with linked Woo account → unified |

**Events emitted**:
- `bizcity_crm_contact_synced_from_woo` (with `direction:'pull'|'push'`, `field_diff`)
- `bizcity_crm_contact_woo_link_resolved` (with `match_method:'user_id'|'email'|'phone'`)

## M-CRM.M8.W4 — Invoice ↔ Woo Order link

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W4.1 | `class-db-installer.php` | ADD COLUMN `crm_invoices.wc_order_id BIGINT UNSIGNED NULL` + `KEY idx_wc_order` | column exists |
| T-P0.35.M8.W4.2 | `includes/woo/class-woo-invoice-bridge.php` (NEW) | `on_order_created($order_id)` → optional auto-create `crm_invoice` (status=sent, link `wc_order_id`); behind setting `bizcity_crm_woo_auto_invoice` (default OFF) | tạo Woo order → invoice xuất hiện |
| T-P0.35.M8.W4.3 | same | `on_order_status_changed($order_id, $from, $to)` → mirror status: `processing→sent`, `completed→paid`, `refunded→refunded`, `failed/cancelled→voided`. Lock-guarded để tránh loop với `class-invoice-repository.php` payment-flip logic. | change Woo order status → invoice transition tracked |
| T-P0.35.M8.W4.4 | `includes/invoicing/class-invoice-repository.php` | `link_to_woo_order(invoice_id, wc_order_id)` API + UI button trong InvoiceDetail "Link existing Woo order" (M-FE follow-up) | manual link 1 invoice cũ → status sync |
| T-P0.35.M8.W4.5 | `class-order-adapter.php` (now `includes/woo/class-woo-order-bridge.php`) | sau khi `wc_create_order()` thành công, nếu setting auto-invoice ON → emit `bizcity_woo_order_to_invoice` event để `class-woo-invoice-bridge` pick up | tạo đơn từ inbox → invoice tự sinh + link |

## M-CRM.M8.W5 — Unified Reports/Dashboard

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W5.1 | `includes/woo/class-woo-reports-bridge.php` (NEW) | `get_revenue_summary($from, $to)` — uses `wc_get_orders` aggregated by status; cached 5min trong transient `bizcity_crm_reports_woo_v1_{hash}` | probe: sample range → JSON `{gross, net, refunds, order_count, aov, paid_count}` |
| T-P0.35.M8.W5.2 | same | `get_revenue_by_campaign($from, $to)` — query `wc_orders` có meta `_bizcity_campaign_id` (đã set bởi M6 attribution) → group by campaign | per-campaign revenue table |
| T-P0.35.M8.W5.3 | same | `get_top_customers($from, $to, $limit)` — top customers by Woo spend, JOIN `crm_contacts.wp_user_id` để show CRM name/avatar | top-N list |
| T-P0.35.M8.W5.4 | UPDATE `class-rest-controller.php::get_reports_aggregate()` | thay vì chỉ count conversations, MERGE với `Woo_Reports_Bridge` output → trả `{conversations:{...}, revenue:{...}, customers:{...}, campaigns:{...}}` | `/crm-reports/aggregate?range=7d` returns unified blob |
| T-P0.35.M8.W5.5 | NEW route `/crm-reports/woo-summary` | proxy thuần đến bridge cho dashboard widgets độc lập | route registered |
| T-P0.35.M8.W5.6 | FE `BizDashboardCard` (M-FE) | bỏ hardcode demo numbers, gọi `useGetCrmReportsAggregateQuery({range})` | dashboard show real Woo data |

**Events emitted**:
- `bizcity_crm_reports_cache_refreshed` (with `bucket`, `duration_ms`)

## M-CRM.M8.W6 — Diagnostic & FE polish

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.M8.W6.1 | `class-sprint-diagnostic.php` | section "M-CRM.M8 Woo Bridge" với 6 wave rows × probes ở trên | tab xuất hiện trong tools.php |
| T-P0.35.M8.W6.2 | FE `routes/contacts/ContactsTab.jsx` | Khi contact có `wp_user_id`, hiển thị badge "Woo customer" + panel "Billing address" (đọc từ `additional_attributes.billing` BE đã merge từ usermeta) + tab "Đơn hàng Woo" (existing `class-order-adapter::list_orders_for_contact`) | UI live test |
| T-P0.35.M8.W6.3 | FE `routes/invoices/InvoiceDetail.jsx` | Nếu `wc_order_id` set, show link "Mở trong WooCommerce ↗" → admin URL `post.php?post={id}&action=edit` (HPOS-aware) | click → mở Woo order |
| T-P0.35.M8.W6.4 | FE `BizDashboardCard.jsx` | wire vào `/crm-reports/aggregate`; loading skeleton; error fallback | live numbers |

## Directory layout sau wave (mục tiêu cuối)

```
plugins/bizcity-twin-crm/includes/
  woo/                                          ← NEW — tất cả Woo bridge gom 1 chỗ
    class-woo-bridge.php                        ← orchestrator + guard
    class-woo-customer-bridge.php               ← wp_users ↔ crm_contacts
    class-woo-order-bridge.php                  ← (renamed từ class-order-adapter.php)
    class-woo-invoice-bridge.php                ← order status → invoice status
    class-woo-reports-bridge.php                ← revenue/AOV/top-customers
    migrations/
      migrate-biz-contacts-to-contacts.php
  invoicing/  (existing M-CRM.M2)
  email/      (existing M-CRM.M3)
```

## Done definition (M-CRM.M8 hoàn thành)

- ✅ `biz_contacts` table dropped (sau monitor dual-read 0 traffic 7 ngày).
- ✅ Mọi contact mới (form UI hoặc social ingest) ghi vào `crm_contacts` only.
- ✅ Khách Woo register → contact tự sinh; sửa contact → billing meta đồng bộ.
- ✅ Đơn hàng inbox → Woo order + (optional) invoice link 2 chiều.
- ✅ Dashboard Reports show số liệu thực từ Woo (revenue, AOV, top customers, by-campaign).
- ✅ Diagnostic tab "M-CRM.M8" all green ≥ 24h.
- ✅ Code Woo-related đều nằm dưới `includes/woo/` (grep `wc_*` ngoài thư mục đó = 0 trừ test/diag).

**End of M-CRM.M8 — Woo Bridge & Contact Unification.**
  - Account form: IMAP (host/port/secure/user/pass/folder) + SMTP toggle
    (use BizCity SMTP global vs explicit host/port/user/pass). Passwords shown as
    `(giữ nguyên — nhập để đổi)` placeholder when editing; empty value not sent.

### Bundle
- After build: `assets/dist/inbox-app.js` = 598.13 kB (gzip 172.51 kB) — under 600 kB target.
- `assets/dist/inbox-app.css` = 61.83 kB.

### Auth & security
- All requests auto-send `X-WP-Nonce` (from `window.BIZCITY_CRM_BOOT.restNonce`)
  via existing `crmApi` `prepareHeaders`. PDF iframe URL appends `?_wpnonce=…` so
  the raw-HTML route honours the same permission gate (`can_write` filter).
- Account passwords: form field `imap_pass` / `smtp_pass`; if user leaves blank
  on edit, FE deletes those keys before PUT — BE keeps existing encrypted secret.
  Listing returns masked `***` for `imap_pass_enc` / `smtp_pass_enc`.

# 🪞 Reflection — M-CRM.M1 vs NextCRM (port research)

> Sau khi ship M-CRM.M1 (Lead/Opp/Contract BE, 5 tables, 9 routes, 11 handlers, line auto-calc), team đối chiếu với `_library/nextcrm-app-main` (Next.js 16 + Prisma) để xác định gap & port roadmap.

## Gap chính của ta so với NextCRM

### Lead
- Thiếu taxonomy tables: `lead_sources`, `lead_statuses`, `lead_types` (NextCRM tách thành 3 bảng FK; ta đang dùng cột varchar tự do).
- Thiếu `referred_by`, `campaign_id` (referral / marketing attribution).

### Opportunity
- Thiếu `budget` (ngân sách KH so với amount thực).
- Thiếu `last_activity` / `last_activity_by` (cần khi build Activity feed).
- Thiếu `snapshot_rate` (tỷ giá chụp tại thời điểm tạo deal).
- `stage` đang hard-code enum (qualification/proposal/...) — NextCRM dùng table `Sales_Stages` có `probability` + `order` cho phép custom theo org.

### Contract
- Thiếu `customer_signed_date` + `company_signed_date` (chỉ có `signed_date` đơn).
- Thiếu `renewal_reminder_date` (tự động nhắc gia hạn).
- Lifecycle chỉ 4 trạng thái — NextCRM thêm NOTSTARTED / INPROGRESS / SIGNED workflow.

### Account / Contact
- Account thiếu `annual_revenue`, `employees`, `member_of` (parent company), `vat`, billing/shipping address (6 cols).
- Contact thiếu `birthday`, `personal_email`, 7 social handles (twitter, linkedin, ...).

### Models hoàn toàn thiếu
- **Products catalog** (`crm_products` + `crm_product_categories`): SKU, unit_price/cost, recurring billing, currency.
- **Activities**: call/meeting/note/email với junction `crm_activity_links` (1 activity ↔ N entities).
- **AuditLog tập trung**: thay vì cột audit/entity, NextCRM dùng 1 bảng `audit_log(entity_type, entity_id, action, changes JSON, user_id)`.
- **ExchangeRate**: tỷ giá ECB-sync để FX revaluation.

## 5 pattern đáng port

| # | Pattern | Lợi ích chính |
|---|---------|---------------|
| 1 | Discount type enum (PERCENTAGE/FIXED) | Hiện ta chỉ có `discount_pct` — thêm cột `discount_type` cho phép giảm giá tuyệt đối. |
| 2 | Currency `snapshot_rate` | Bảo toàn giá trị deal khi tỷ giá biến động; tách "giá ký HĐ" và "giá ghi sổ". |
| 3 | Centralized `audit_log` | Dashboard audit-toàn-hệ-thống, hỗ trợ undo/restore (kết nối M-CRM.M4). |
| 4 | Activity model + junction links | 1 hoạt động link nhiều thực thể (Opp + Account + Contact); enable feed per-user. |
| 5 | Sales-stage taxonomy DB-driven | Org-specific pipelines, probability-weighted forecasting không cần code. |

## Anti-pattern bỏ qua

- ❌ Cột `v` (version) ở mọi bảng — ta đã có `updated_at`, không cần.
- ❌ JSON cho structured data (tags, notes) — ta đã prefer dedicated junction tables.
- ❌ Validation chỉ ở Zod layer — ta giữ DB-level FK + cap check ở REST layer (defense-in-depth).

## Roadmap port → 4 wave follow-up đã thêm vào board

- **M-CRM.M1.W2** — Product Catalog & line-item normalization (5 checks).
- **M-CRM.M1.W3** — Centralized AuditLog + Activity model (5 checks; bridge sang M4).
- **M-CRM.M1.W4** — Multi-currency snapshot rates + FX table (4 checks).
- **M-CRM.M1.W5** — Sales-stage taxonomy isolation, DB-driven (4 checks).

> Ưu tiên: W2 (mở khoá invoice/M2) → W3 (mở khoá M4) → W5 (forecasting) → W4 (multi-currency, optional cho B2C VN-only).

---

**End of M-CRM.M1 reflection.**

---

# 🔬 NextCRM v2 reflection — comprehensive deep-dive (2026-05-12)

> Sau khi M-CRM.M1.W2 (Product Catalog) ship, đội re-audit toàn bộ NextCRM repo (`_library/nextcrm-app-main`) — Prisma schema (~62KB, 22+ models), 10 API domain, 15 page surface. Kết luận: chúng ta mới phủ ~50% feature surface của NextCRM. 8 domain còn thiếu hoàn toàn → mở thêm 9 milestone/wave mới (đã thêm vào board ở §Roadmap board phía trên).

## §1 — Domain coverage matrix (sau update)

| Domain | NextCRM models | Status ta | Wave bind | Coverage |
|---|---|---|---|---|
| Sales Pipeline | Opportunity + OppLine + SalesStages | ✅ | M-CRM.M1 / .W5 | 100% |
| Lead | Lead + LeadSource + LeadStatus | ✅ | M-CRM.M1.W1 | 90% |
| Account / Contact | Account, Contact, IndustryType, Watcher | ✅ | M-CRM.M1 | 85–90% |
| Contract + Lines | Contract + ContractLine | ✅ | M-CRM.M1 + .W2 | 100% |
| Product Catalog | Product + Category + Currency + TaxRate | ✅ | **M-CRM.M1.W2** | 100% |
| Tasks/Activities | Task, Activity, Comment, junction | 🟡 | M-CRM.M1.W3 (planned) | 70% |
| Documents | Document + DocumentChunk | 🟡 | M-CRM.M1 + M-RAG.R1 | 50% |
| Audit / History | crm_AuditLog (centralized + diff JSON) | 🟡 | M-CRM.M1.W3 + M4 | 60% |
| Currency / FX | Currency + ExchangeRate + snapshot | 🟡 | M-CRM.M1.W4 (planned) | planned |
| Invoicing | Invoice, Line, Payment, Series, TaxRate | 🟡 | M-CRM.M2 (BE shipped 2026-05-12) | 75% |
| Email Client | EmailAccount + Email + Embedding + junction | 🟡 | M-CRM.M3 (BE shipped 2026-05-12) | 75% |
| **🆕 Targets (cold outbound)** | Target, TargetList, junction, Enrichment | ⚪ | **M-CRM.M8 (NEW)** | 0% |
| **🆕 Email Campaigns** | Campaign + Template + Step + Send + tracking | ⚪ | **M-CRM.M9 (NEW)** | 0% |
| **🆕 Vector / RAG** | 5× Embedding tables + DocumentChunk | ⚪ | **M-RAG.R1–R3 (NEW)** | 0% |
| **🆕 Reports & Schedules** | ReportConfig + ReportSchedule + cron | ⚪ | **M-CRM.M10 (NEW)** | 0% |
| **🆕 Notifications** | NotificationRecord + digest | ⚪ | **M-NOTIFY.N1–N2 (NEW)** | 0% |
| **🆕 Project boards** | Boards + Sections + BoardWatchers + DnD Tasks | ⚪ | **M-PM.M1 (NEW)** | 0% |
| **🆕 Webhooks/Integrations** | WebhookSubscription + OAuth connectors | ⚪ | **M-INT.I1–I2 (NEW)** | 0% |
| **🆕 Admin Panel** | ApiKey (3-tier scoped) + ApiToken (Bearer, SHA-256) | ⚪ | **M-CRM.M11 (NEW)** | 0% |
| **🆕 Bulk import** | CSV/Excel import + de-dupe + dry-run | ⚪ | **M-IMP.M1 (NEW)** | 0% |

## §2 — 9 milestone/wave NEW thêm vào board

### M-CRM.M8 — Targets & List Management (cold-outbound) · 6 checks
**Tables**: `crm_target`, `crm_target_list`, `crm_target_list_item` (junction M:N), `crm_target_enrichment` (firecrawl/api results JSON), `crm_target_to_contact` (conversion link).
**REST**: 8 endpoints (CRUD targets/lists + bulk-add + enrichment trigger + convert-to-contact).
**Use case**: Import danh sách prospect lạnh → enrich (firecrawl/manual) → segment vào list → feed sang Campaign (M9). KHÁC với Lead (lead = đã có engagement; target = chưa có).
**Dep**: M-CRM.M1.

### M-CRM.M9 — Email Campaigns · 2 waves · 13 checks
- **W1 — Builder/Templates/Steps · 7 checks**: tables `crm_campaign`, `crm_campaign_template` (HTML/MJML body), `crm_campaign_step` (delay+template ordered), `crm_campaign_to_target_list` junction. REST CRUD + preview render.
- **W2 — Execution + tracking · 6 checks**: table `crm_campaign_send` (per-recipient state: queued/sent/opened/clicked/bounced/unsubscribed), unsubscribe token, webhook receiver cho Resend/Mailgun, WP-Cron worker quét steps đến hạn. Enable analytics dashboard.
**Dep**: M8 (target list source) + M3 (SMTP send infra) hoặc dùng `wp_mail()` bridge.

### M-RAG.R1 — Vector embeddings + hybrid search · 8 checks
**Tables (one per entity hoặc 1 polymorphic)**: `crm_embedding(entity_type, entity_id, model, dim, vector LONGBLOB|JSON, content_hash, created_at)` + `crm_document_chunk(document_id, position, text, vector, tokens)`.
**Provider**: re-use `bizcity-openrouter` để gọi `text-embedding-3-small` (1536-dim) hoặc local model. Vector storage: store as JSON blob ban đầu → migrate `mysqlvector` plugin / sidecar Postgres+pgvector khi >50k records.
**Search**: hybrid = WP MySQL FULLTEXT (BM25) + cosine similarity (PHP fallback hoặc microservice).
**Job**: WP-Cron backfill embeddings cho rows mới sau mỗi save (post_save hook).
**Dep**: M-CRM.M1.

### M-RAG.R2 — Find Similar entities · 3 checks
RTK endpoint `/find-similar?entity=lead&id=123&k=5` → top-K cosine. UI hook drawer (M-FE.W12 đã có shell).
**Dep**: R1.

### M-RAG.R3 — RAG Q&A · 4 checks
Endpoint `/rag/ask` → retrieve K chunks → assemble prompt → forward sang openrouter. Dùng cho admin chatbot "ask your CRM".
**Dep**: R1.

### M-CRM.M10 — Reports & Scheduled Exports · 5 checks
**Tables**: `crm_report_config(name, category, query_json, columns_json, owner_id, sharing)`, `crm_report_schedule(report_id, cron_expr, recipients, format)`.
**Categories**: sales | leads | accounts | activity | campaigns | users.
**Export**: CSV (PHP fputcsv) + PDF (dompdf hoặc tcpdf — đã có trong WP ecosystem). Email delivery qua `wp_mail`.
**Cron**: WP-Cron + per-report custom hook.
**Dep**: M-CRM.M1.

### M-CRM.M11 — Admin Panel: API keys + Bearer tokens · 6 checks
**Tables**:
- `crm_api_key(scope, provider, encrypted_value, label)` — 3 tier: env (file) > system (DB) > user (DB scoped to user_id). AES-256-GCM (sodium_crypto_secretbox) keyed off WP `AUTH_KEY`.
- `crm_api_token(name, hash SHA-256, scopes JSON, last_used, expires_at, revoked_at)` — Bearer token cho external apps gọi REST.
**Scopes**: `CONTACT:read`, `LEAD:write`, `CAMPAIGN:send`, … áp dụng tại `wrap()` REST layer.
**UI**: tab `Settings → API` trong CRM admin.
**Dep**: M4 (audit ghi mọi mint/revoke).

### M-PM.M1 — Project boards · 2 waves · 10 checks
- **W1 BE · 5 checks**: `crm_board`, `crm_board_section`, `crm_board_task`, `crm_board_watcher`, `crm_board_task_comment`. REST CRUD + reorder endpoints.
- **W2 FE · 5 checks**: Kanban DnD (`@dnd-kit/sortable` đã cài cho W9), watchers, comments, task templates.
**Dep**: standalone (không cần M-CRM khác).

### M-NOTIFY.N1–N2 — Notifications · 9 checks
- **N1 · 5 checks**: `crm_notification(user_id, type, entity_type, entity_id, payload, read_at, created_at)`. Triggers: assignment, comment, mention, task_due, lead_converted, opp_stage_change, email_received. Dispatch async qua WP-Cron. Daily digest email (rollup unread).
- **N2 · 4 checks**: SSE endpoint `/stream/notifications` (long-poll fallback) — push real-time vào Inbox bell.
**Dep**: M-CRM.M1.

### M-INT.I1–I2 — Outbound webhooks + OAuth · 8 checks
- **I1 · 4 checks**: `crm_webhook_subscription(event, target_url, secret, active, last_status, last_at)` — fire on CRUD; HMAC SHA-256 signature; WP-Cron retry (exponential backoff 1m/5m/30m/3h, max 5).
- **I2 · 4 checks**: OAuth client connectors: Slack (channel post), Teams, Zapier (catch-hook). Token storage in M11 keystore.
**Dep**: M4 (audit log fires).

### M-IMP.M1 — Bulk CSV import · 4 checks
Wizard: upload → header mapping → dry-run validation → commit batched insert (transaction-like via WP-Cron chunks). De-dupe theo email/phone/sku. Status table `crm_import_job`.
**Dep**: M-CRM.M1.

## §3 — Defer / Skip explicit list

| ❌ Item | Reason |
|---|---|
| NextAuth / Better Auth | Dùng WP user/cap có sẵn (filterable cap `bizcity_crm_write_cap`) |
| Resend SDK | Bridge qua `wp_mail` + plugin SMTP của user |
| UploadThing | `wp_upload_dir` + S3 abstraction trong bizcity-openrouter |
| Inngest | WP-Cron đủ cho phase 1; bridge sang Inngest/queue thật khi cần scale |
| Prisma ORM | `$wpdb` + thin query builder helpers |
| React Email | PHP template + `wp_mail`; FE preview qua `dangerouslySetInnerHTML` |
| E2B sandbox | Phase 2; firecrawl/openrouter trước cho enrichment |
| MCP server (127 tools) | Defer Q3+; market riêng như "AI API layer" |
| Postgres + pgvector hard-dep | Phase 1: store vectors as JSON blob; migrate sidecar khi >50k |
| Employees module | Low priority — dùng WP users |
| Databox widget demo | Không phải feature, chỉ là demo dashboard |

## §4 — Engineering patterns đáng port (đánh giá lại)

| Pattern | Đánh giá | Effort | Khi nào |
|---|---|---|---|
| Centralized AuditLog + JSON diff | 🟢 must-have | low | M-CRM.M1.W3 (đã planned) |
| Soft-delete + `deleted_at` everywhere | ✅ đã làm | done | — |
| Discount type enum + snapshot rate | ✅ đã làm (W2) + W4 planned | done/planned | — |
| Vector embeddings + chunking | 🟢 huge UX | medium | M-RAG.R1 |
| WP-Cron worker pattern (campaign send + retries) | 🟢 must | low | M9.W2, M-INT.I1, M-NOTIFY |
| Scope-based access tokens | 🟢 enables external API | medium | M-CRM.M11 |
| Hash-chain audit (tamper detect) | 🟡 nice (compliance) | medium | M-CRM.M4 (optional) |
| Real-time SSE | 🟡 nice | medium | M-NOTIFY.N2 |
| DnD Kanban + watchers | 🟡 nice | low (W9 đã có deps) | M-PM.M1.W2 |
| GDPR data export | 🟡 nice | low | M-CRM.M4 (đính kèm) |

## §5 — Suggested execution order (revised)

> Mục tiêu: ship được **cold outbound machine** (target → list → campaign → tracking) sớm nhất vì đây là revenue lever rõ nhất cho B2C VN.

1. ✅ **M-CRM.M1** (sales pipeline BE) — DONE
2. ✅ **M-CRM.M1.W2** (product catalog) — DONE 2026-05-12
3. ⏭ **M-CRM.M1.W3** Centralized AuditLog + Activity model — UNLOCKS M4 + M-FE.W10/W11 đã ship
4. ⏭ **M-CRM.M1.W5** Sales-stage taxonomy DB-driven — quick win cho forecasting
5. ⏭ **M-CRM.M8** Targets & List — foundation outbound
6. ⏭ **M-RAG.R1** Vector embeddings + hybrid search — enable Find Similar (W12) + Q&A
7. ⏭ **M-CRM.M9.W1** Campaign builder — pre-launch content
8. ⏭ **M-CRM.M9.W2** Campaign exec + tracking — revenue moment
9. ⏭ **M-CRM.M3** Email Client (W1 sync, W2 compose) — synergy với campaign
10. ⏭ **M-CRM.M2** Invoicing BE — close loop sau khi deal thắng
11. ⏭ **M-CRM.M10** Reports & schedules — exec dashboard
12. ⏭ **M-CRM.M4** Audit hardening — compliance polish
13. ⏭ **M-CRM.M11** Admin / API keys — enable external integrations
14. ⏭ **M-PM.M1**, **M-NOTIFY.N1–N2**, **M-INT.I1–I2**, **M-IMP.M1**, **M-CRM.M1.W4** (FX) — parallel polish wave

> Note: M-CRM.M5/M6/M7 cũ (FE shells cho Sales/Invoice/Email Kanban) vẫn giữ ID để mount khi BE tương ứng (M1/M2/M3) ready.

---

**End of NextCRM v2 reflection — 2026-05-12. 50% surface ported · 9 new milestones queued · cold-outbound path = top revenue priority.**

---

# 🔗 Cross-module integration map (2026-05-12)

> Mạch bind giữa các module độc lập đã có sẵn trong workspace ↔ các wave CRM mới. Mục tiêu: KHÔNG re-implement lại những gì module cũ đã làm; CRM chỉ **adopt** + **surface** chúng dưới dạng Channel/Activity unified.

## §A — `core/scheduler` ⟶ M-CRM.M12 Calendar Channel

**Module hiện có**: [core/scheduler/bootstrap.php](plugins/bizcity-twin-ai/core/scheduler/bootstrap.php) — DB-based events, Google Calendar sync, reminder cron, 9 atomic Intent tools (`scheduler_*`), public `/scheduler/` SPA.

**Hooks/extension points đã expose** (do scheduler bootstrap):
- `bizcity_scheduler_event_created|updated|deleted` — entity lifecycle
- `bizcity_scheduler_reminder_fire` — reminder trigger
- `bizcity_scheduler_google_synced` — sau mỗi pull Google
- `apply_filters( 'bizcity_scheduler_context', '', $user_id )` — inject agenda vào LLM prompt

**Tool-google plugin** ([bizgpt-tool-google.php](plugins/bizgpt-tool-google/bizgpt-tool-google.php)) đã có OAuth Hub trung tâm (Gmail + Calendar + Drive + Contacts) — KHÔNG cần re-implement OAuth.

**Wave M-CRM.M12 v2 — Calendar Unification (REVISED 2026-05-13)**

> **Bối cảnh phát hiện 2026-05-13**: tồn tại HAI hệ event song song:
> - `core/scheduler/` với `wp_bizcity_scheduler_events` (DATETIME, có Google sync 1-account, reminder cron, 9 atomic tools, REST `bizcity-scheduler/v1`).
> - `bizcity-twin-crm/` với `wp_bizcity_crm_events` (BIGINT unix, attendees_json, related_entity_*, KHÔNG có Google/reminder/status/description).
> - Adapter `BizCity_CRM_Scheduler_Adapter` đã ghi key `metadata` vào scheduler row nhưng cột không tồn tại → bị drop âm thầm (latent bug).
>
> **Decision**: Hợp nhất về MỘT bảng duy nhất, naming theo namespace CRM. REST giữ `bizcity-scheduler/v1` (backward-compat). Google chuyển sang `bizgpt-tool-google` Hub đa account.

**Locked decisions**:
- Source-of-truth: `wp_bizcity_crm_events` (RENAME từ `wp_bizcity_scheduler_events`).
- Class `BizCity_Scheduler_Manager` giữ tên, chỉ đổi `TABLE` constant.
- FE CRM Calendar bỏ endpoint `bizcity-crm/v1/crm-events`, gọi thẳng `bizcity-scheduler/v1/events`.
- Google: bỏ option `bizcity_scheduler_google`, dùng `bizgpt-tool-google` token store (multi-account/per-user). Cột mới `google_account_id`.
- Webchat fallback: user chưa connect Google → event vẫn lưu DB, log warning, không throw.

**Schema cuối cùng — `wp_bizcity_crm_events`** (18 cột scheduler hiện có + 3 mới):
```
+ event_type        VARCHAR(32) DEFAULT 'meeting'   -- meeting/workshop/training/internal/personal
+ metadata          LONGTEXT NULL                   -- JSON {attendees, related_entity_type, related_entity_id, contact_id, conversation_id, channel}
+ google_account_id BIGINT UNSIGNED NULL            -- FK → bzgoogle_accounts.id (per-user multi-account)
+ KEY idx_event_type (event_type)
```

**Lộ trình 8 phase** (sequential, ship từng phase độc lập):

| # | Phase | Phạm vi | Trạng thái |
|---|---|---|---|
| 1 | Fix svg-painter dequeue trên hub sub-page | `class-admin-page.php` thêm `wp_dequeue_script('svg-painter')` khi `is_hub_subpage` | ✅ 2026-05-13 |
| 2 | Schema migrate v3 | `SCHEMA_VERSION` 2→3. `migrate_to_3()`: rename CRM legacy table → `*_legacy_<date>`, RENAME scheduler→crm_events, ADD 3 cột, backfill từ legacy (FROM_UNIXTIME + JSON_OBJECT). CRM `class-db-installer.php` bỏ block CREATE TABLE `crm_events` | ⏭ |
| 3 | Smoke test 30d, drop legacy | Manual command | ⏭ |
| 4 | Google adapter → tool-google Hub | `class-scheduler-google.php` refactor sang `BZGoogle_Token_Store::get_account($user_id)`. UI scheduler thêm dropdown account/calendar. Migrate option cũ → 1 bzgoogle_accounts row | ⏭ |
| 5 | Tools + adapter inject metadata | `BizCity_Scheduler_Tools` thêm params `event_type/attendees/contact_id/conversation_id/google_account_id`. CRM adapter inject từ conversation context | ⏭ |
| 6 | FE swap to scheduler API | `frontend/src/redux/api/crmApi.js` thêm endpoint scheduler. `CalendarTab.jsx` chuyển sang `useGetSchedulerEventsQuery({event_type:[meeting,workshop,training,internal]})`. Bật update/delete UI. Endpoint `/crm-events` còn làm proxy + header `X-Deprecated` | ⏭ |
| 7 | Reverse matcher Google→CRM | Listen `bizcity_scheduler_event_created` khi `source='google_sync'`, match `attendees[].email` ↔ `bizcity_crm_contacts.email` → set `metadata.contact_id` | ⏭ |
| 8 | Cleanup | Drop `*_legacy_*` table, xóa proxy `/crm-events`, xóa dead code adapter | ⏭ |

**KHÔNG làm trong M12**: tạo lại event storage thứ 2, OAuth flow Google riêng, reminder cron — đã có hết trong scheduler module.

**Sprint diag checks**:
- v2.1: SCHEMA_VERSION = 3, table `bizcity_crm_events` exists, `bizcity_scheduler_events` không tồn tại, có cột `event_type/metadata/google_account_id`.
- v2.4: option `bizcity_scheduler_google` không còn, có ≥1 row `bzgoogle_accounts` linked qua `google_account_id`.
- v2.6: REST GET `bizcity-scheduler/v1/events?event_type=meeting` trả response shape FE-compatible.

## §B — `bizgpt-custom-flows` ⟶ M-CRM.M13 Loyalty / Referral Channel

**Module hiện có**: `plugins/bizgpt-custom-flows/` — UTM campaigns + QR code generator + referral message tracking, dùng cho loyalty / tích điểm.

**Wave M-CRM.M13 Loyalty/Referral Channel — 5 checks**:
1. CRM Channel Settings thêm card "Loyalty & Referral" → link sang `bizgpt-custom-flows` admin page (single source of truth, không duplicate UI).
2. Khi M9.W2 ship: campaign send link tự gắn UTM (`utm_source=bizcrm_camp_{id}`) qua hook `bizcity_crm_campaign_link_filter`. UTM được custom-flows nhận diện ngược → log conversion.
3. QR code generator (custom-flows) callable từ CRM Campaign detail — "Generate QR for this campaign" button → embed PNG + tracking.
4. Referral attribution: khi WP user signup từ link ref, custom-flows fire `bizgpt_custom_flows_referral_captured` → CRM listener tạo Lead + Activity (source=`referral`, parent_user_id từ flow).
5. Sprint diag check: UTM filter applied + Lead created from referral webhook (smoke).

**KHÔNG làm trong M13**: tạo lại UTM/QR/loyalty engine — đã có. CRM chỉ là consumer của events.

## §C — Channel Settings unified shape

> Tất cả 3 channel (Email Client M3, Calendar M12, Loyalty M13) cùng render trong 1 tab `Settings → Channels` của CRM SPA, theo shape:
>
> ```js
> { id, name, icon, status: 'connected'|'disconnected'|'partial',
>   connectUrl, configUrl, lastSyncAt, statsCount, manageVia: 'crm'|'external_module' }
> ```
>
> `manageVia='external_module'` → nút "Open settings" deep-link sang module owner (scheduler/tool-google/custom-flows). CRM KHÔNG store credential, chỉ track connection state.

## §D — Linkage matrix (analyses ↔ waves)

| Analysis section | Bind tới wave | Cross-ref |
|---|---|---|
| §1 Domain coverage matrix | toàn bộ M-CRM.M*-M11 + M-RAG/M-PM/M-NOTIFY/M-INT | board rows |
| §2.M-CRM.M9 Campaigns | + §B Loyalty bridge | M13 wave |
| §2.M-CRM.M3 Email Client | + §A Scheduler bridge cho meeting invites | M12 wave |
| §2.M-RAG.R1 Vector | feed embeddings từ scheduler events + activities | M12 → R1 |
| Engineering pattern "WP-Cron worker" | scheduler đã proven pattern → reuse cho M9.W2 + M-NOTIFY + M-INT | implementation reference |
| OAuth Hub (`bizgpt-tool-google`) | reuse 100% cho M3 (Gmail) + M12 (Calendar); KHÔNG thêm credential UI mới | M3 + M12 |

## §E — Updated execution order (after this revision)

1. ✅ M-CRM.M1 + W2 (DONE)
2. ⏭ M-CRM.M1.W3 AuditLog + Activity model ← **MUST FIRST** (M12/M13 listeners cần `crm_activity` table)
3. ⏭ M-CRM.M1.W5 Sales-stage taxonomy
4. ⏭ **M-CRM.M12 Calendar Channel** ← quick win, low effort (chỉ wire hook, không build storage)
5. ⏭ M-CRM.M8 Targets
6. ⏭ M-RAG.R1 Vector
7. ⏭ M-CRM.M9.W1 Campaigns builder
8. ⏭ M-CRM.M9.W2 Campaign exec + tracking ⟶ ngay sau đó **M-CRM.M13 Loyalty bridge** (UTM filter chỉ kích hoạt khi M9.W2 ship)
9. ⏭ M-CRM.M3 Email Client (W1 sync, W2 compose)
10. ⏭ M-CRM.M2, M10, M4, M11, rest…

> **Insight**: M12 (Calendar Channel) leo lên top 4 vì effort thấp + value cao + chứng minh được pattern "adopt external module as Channel" cho M13 sau này.

## §F — SMTP Bridge (M-INFRA.SMTP1 — shipped 2026-05-12)

**Module vừa ship**: [core/smtp/bootstrap.php](plugins/bizcity-twin-ai/core/smtp/bootstrap.php) — port nguyên vẹn logic của legacy [mu-plugins/bizcity-smtp-gmail.php](mu-plugins/bizcity-smtp-gmail.php) vào trong plugin Twin AI để hoạt động **default-on** theo plugin lifecycle.

### Configuration precedence (cao → thấp)
1. **PHP constants** trong `wp-config.php` (`BIZCITY_SMTP_HOST/PORT/USER/PASS/FROM/FROM_NAME/SECURE/AUTH`)
2. **WP option** `bizcity_smtp_settings` (admin-editable, tương lai bind vào CRM Channel Settings card SMTP — wave SMTP2)
3. None → module no-op, `wp_mail()` tiếp tục dùng default WP behavior

### Sentinel guard (không double-fire)
Hard guard `BIZCITY_SMTP_LOADED` đặt khi bind → nếu mu-plugin cũ vẫn còn trong `mu-plugins/`, mu-plugin chạy trước (đã register hooks) và module này skip an toàn. Filter `bizcity_smtp_config` cho phép override per-tenant trong multisite.

### Migration steps
1. ✅ `core/smtp/bootstrap.php` shipped — default-on, no-op nếu thiếu config.
2. ⏭ Move `BIZCITY_SMTP_*` define từ mu-plugin sang `wp-config.php` (giữ nguyên credential cho production).
3. ⏭ Delete `wp-content/mu-plugins/bizcity-smtp-gmail.php` sau khi xác nhận email vẫn hoạt động.
4. ⏭ (Tương lai SMTP2) Thêm SMTP card vào CRM Channel Settings tab; admin nhập creds qua UI → ghi vào option `bizcity_smtp_settings` → module auto-applies trang sau.

### Functional reductions vs legacy mu-plugin
- ❌ **Bỏ** custom `lostpassword_redirect` + `retrieve_password_*` filters (BizCity-specific, không thuộc trach nhiệm của SMTP module). Nếu cần cho production → tách thành module riêng `core/auth-emails/` hoặc giữ nguyên trong site-specific mu-plugin.
- ✅ Giữ nguyên: `phpmailer_init` SMTP override, `wp_mail_from`, `wp_mail_from_name` — đó là core SMTP behavior.

### 3 sprint-diag checks (board count 3/3)
1. `class_exists('BizCity_SMTP')` sau khi plugin load.
2. Nếu config có đủ (host+user+pass+from) → hook `phpmailer_init` priority 999 đã bound.
3. `BIZCITY_SMTP_LOADED` constant defined sau bind → idempotent guard.

---

**End of cross-module integration map — 2026-05-12.**

---

# §G — M-CRM.M2 + M3 Implementation Notes (2026-05-12)

## M-CRM.M2 — Invoicing BE (8/8 tasks shipped)

**Files added/changed**:
- `plugins/bizcity-twin-crm/includes/invoicing/class-invoice-repository.php` — sole write gate, lifecycle state machine (`draft → sent → paid/overdue/voided/refunded`), totals recompute, payment auto-flip-to-paid, `generate_number()` (format `INV-YYYYMM-NNNN`).
- `plugins/bizcity-twin-crm/includes/invoicing/class-invoice-pdf.php` — A4 print-friendly HTML (browser-print → PDF), VND/USD formatting, status badges, payment history; `send_by_email()` uses `wp_mail()` → core/smtp bridge.
- `plugins/bizcity-twin-crm/includes/invoicing/class-invoice-cron.php` — hourly `bizcity_crm_invoice_overdue_tick`, lock-guarded.
- `class-db-installer.php` — 3 new tables (`crm_invoices`, `crm_invoice_lines`, `crm_invoice_payments`); DB ver `1.9.0`.
- `class-rest-controller.php` — 8 routes (list/CRUD + transition + payments × 2 + send + pdf), namespace `bizcity-crm/v1/`.
- `bootstrap.php` — requires + cron register.
- `class-sprint-diagnostic.php` — section "Invoicing" with 8 task rows.

**REST surface** (all permission `can_write`):
```
GET    /crm-invoices?status=&account_id=&q=&limit=&offset=
POST   /crm-invoices                                  → create (optional `lines` array)
GET    /crm-invoices/{id}                             → with lines + payments
PUT    /crm-invoices/{id}                             → lines mutable only in draft
DELETE /crm-invoices/{id}                             → only draft/voided
POST   /crm-invoices/{id}/transition  body:{status}   → state-machine validated
GET    /crm-invoices/{id}/payments
POST   /crm-invoices/{id}/payments    body:{amount,method?,paid_at?}  → auto-PAID when fully paid
DELETE /crm-invoices/payments/{pid}                   → recomputes totals
POST   /crm-invoices/{id}/send        body:{to,subject?}              → wp_mail; auto draft→sent
GET    /crm-invoices/{id}/pdf                         → raw text/html (no JSON envelope)
```

**Events emitted** (Twin Event Stream):
- `crm_invoice_created`
- `crm_invoice_status_changed` (with `from`, `to`)
- `crm_invoice_payment_added`
- `crm_invoice_payment_deleted`
- `crm_invoice_marked_overdue`

## M-CRM.M3 — Email Client BE (7/7 tasks shipped)

**Files added/changed**:
- `plugins/bizcity-twin-crm/includes/email/class-email-repository.php` — accounts CRUD (passwords encrypted at-rest using AES-256-CBC keyed off `AUTH_KEY`; b64 fallback if no openssl), thread resolver (in-reply-to → subject-window → new), `ingest_message()` for IMAP poller, `compose_and_send()` (uses `wp_mail` → core/smtp), `mark_thread_read()`, `normalize_subject()` (strips Re:/Fwd:/RE:/Tr:/Sv: chains).
- `plugins/bizcity-twin-crm/includes/email/class-email-poller.php` — 5-min cron `bizcity_crm_email_poll_tick`, graceful skip when `function_exists('imap_open')` is false, per-account lock, paged UID-fetch, multipart body extraction, MIME header decoding, attachment metadata.
- `class-db-installer.php` — 3 tables (`crm_email_accounts`, `crm_email_threads`, `crm_email_messages`).
- `class-rest-controller.php` — 6 routes (accounts CRUD + sync trigger + threads list/get/read + send).
- `bootstrap.php` — requires + cron schedule registration.
- `class-sprint-diagnostic.php` — section "Email Client" with 7 task rows (incl. ext-imap detection + encryption round-trip).

**REST surface** (all permission `can_write`):
```
GET    /crm-email-accounts
POST   /crm-email-accounts            body:{label,email,imap_host,imap_pass,...}
GET    /crm-email-accounts/{id}                       → secrets masked as "***"
PUT    /crm-email-accounts/{id}
DELETE /crm-email-accounts/{id}                       → soft-delete
POST   /crm-email-accounts/{id}/sync                  → manual poll trigger (throws if no ext-imap)
GET    /crm-email-threads?account_id=&unread_only=&search=&limit=&offset=
GET    /crm-email-threads/{id}                        → with messages array
POST   /crm-email-threads/{id}/read                   → bulk mark read
POST   /crm-email-send                body:{account_id,to,cc?,bcc?,subject,body_html,thread_id?,in_reply_to?}
```

**Events emitted**:
- `crm_email_received` (after IMAP ingest)
- `crm_email_sent`

**Security notes**:
- IMAP/SMTP passwords stored in `imap_pass_enc` / `smtp_pass_enc` (TEXT, AES-256-CBC ciphertext base64-encoded with `aes:` prefix).
- `get_account()` masks both fields to `"***"`; only `get_account_with_passwords()` (private to poller) decrypts.
- IMAP connection uses `/novalidate-cert` by default (override via `bizcity_crm_imap_flags` filter for stricter envs).

## What still ships in M2/M3 follow-ups (not this drop)

- M2.W4 — auto-numbering **series** + tax-rate library (currently uses simple `INV-YYYYMM-NNNN` + per-line tax_pct).
- M2.W5 — multi-currency FX table (currently 1.0 default; columns exist).
- M3.W5 — auto-link from-address → `bizcity_crm_contact` (FK `contact_id` exists on threads via `related_entity_*`).
- M-CRM.M6/M7 — FE work (separate sprint; depends on M-FE.W9 + W13).

**End of §G — M-CRM.M2/M3 implementation notes.**

---

# §H — M-CRM.M14 Documents Hub + M-MEDIA.U1 Media Output Unify (planned 2026-05-13)

> **Rule cha:** [PHASE-0-RULE-OUTPUT-FILES.md](PHASE-0-RULE-OUTPUT-FILES.md) (R-OF-1 → R-OF-9).
> **Mục tiêu:** dòng dữ liệu đầu ra duy nhất:
> *Lệnh* → `wp_bizcity_webchat_studio_jobs/outputs` → *file* → `wp_bzdoc_documents` → *view* (CRM Documents tab + TwinChat Notebook Files).

## Bối cảnh phát hiện 2026-05-13

| Component | Storage hiện tại | Unify? | Ghi chú |
|---|---|---|---|
| `bizcity-doc` | `wp_bzdoc_documents` (đã có `notebook_id`, `source_skeleton_version`) | ✅ Hub canonical | Thiếu cột `generator`, `origin`, `job_id` |
| TwinChat Studio jobs | `wp_bizcity_webchat_studio_jobs` + `_outputs` | ✅ Có | Hook `bizcity_twinchat_studio_generated` đã fire — chưa promote sang bzdoc tự động |
| `bizcity-tool-image` | Media Library + endpoint `/image-editor/v1/...` | ⚠️ Một phần | Một số path đã ghi vào bzdoc, một số trả attachment URL trực tiếp |
| `bizcity-video-kling` | endpoint `/bzvideo/v1/...` + likely table riêng | ❌ Chưa | Không có FK về bzdoc/notebook |
| `bizcity-content-creator` | wp_posts custom hoặc table riêng | ❌ Chưa | Long-form content không có notebook binding |
| CRM Productivity > Documents tab | UI stub "(0) — upload sẽ thêm trong M-CRM.M5" | ❌ Chưa BE | Cần đọc bzdoc_documents với cột `notebook` |

→ Kích hoạt 2 milestone mới: **M-CRM.M14** (CRM-side, view + upload) và **M-MEDIA.U1** (cross-plugin, hub-side migration).

---

## 🗄️ M-CRM.M14 — Documents Hub (CRM view)

> **Wave**: 4 · **Estimated**: 0.5 sprint · **Risk**: LOW (chỉ là view + upload proxy)
> **Phụ thuộc**: bzdoc_documents schema bổ sung cột (M-MEDIA.U1.W1).
> **Thay thế tham chiếu cũ "M-CRM.M5"** trong UI stub Documents (đó là alias sai trong text — Documents thực tế ở M14).

### M14.W1 — Schema patch bzdoc_documents (compat shim phía CRM)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.14.1.1 | `plugins/bizcity-doc/includes/class-installer.php` | `migrate_v2_5()` — add cột `generator VARCHAR(64)`, `origin ENUM('upload','generated')`, `job_id BIGINT NULL`, `media_id BIGINT NULL` (FK wp_posts.ID), `parent_event_uuid CHAR(36) NULL`; index `(notebook_id, doc_type)`, `(origin, status)`, `(generator, doc_type)`, `(media_id)` | dry-run diff JSON |
| T-P0.35.14.1.2 | same | bump `bzdoc_schema_version=2.5` | option exists |
| T-P0.35.14.1.3 | same | backfill: `origin='upload'` cho row hiện tại không có `job_id`; `generator='bizcity-doc'` mặc định | row count delta |

### M14.W2 — REST mở rộng `/bzdoc/v1/documents` (filter view)

| Task | File | Endpoint | Probe |
|---|---|---|---|
| T-P0.35.14.2.1 | `plugins/bizcity-doc/includes/class-rest-api.php` | `GET /bzdoc/v1/documents` accept query: `notebook_id`, `doc_type`, `generator`, `origin`, `q`, `limit`, `offset`, `sort` | hit endpoint trả filter-aware list |
| T-P0.35.14.2.2 | same | `POST /bzdoc/v1/documents` (upload đa MIME, store vào Media Library, ghi metadata vào bzdoc với `origin='upload'`) | upload file → row inserted |
| T-P0.35.14.2.3 | same | `DELETE /bzdoc/v1/documents/{id}` soft-delete (status=`deleted`) + cron `bzdoc_retention_tick` xoá file vật lý sau 30 ngày | force tick |
| T-P0.35.14.2.4 | same | `GET /bzdoc/v1/health` aggregate generator health (R-OF-9) | JSON shape |

### M14.W3 — CRM Documents tab FE (view)

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.14.3.1 | `plugins/bizcity-twin-crm/frontend/src/redux/api/bzdocApi.js` (NEW) | RTK slice `bzdocApi` (reducerPath `'bzdocApi'`) — `useGetDocumentsQuery({notebook_id, doc_type, generator, origin})`, `useUploadDocumentMutation`, `useDeleteDocumentMutation` | slice mounted in store |
| T-P0.35.14.3.2 | `plugins/bizcity-twin-crm/includes/class-admin-menu.php` | expose `window.BIZCITY_CRM_BOOT.bzdocRestUrl = '/wp-json/bzdoc/v1/'` | inline script contains key |
| T-P0.35.14.3.3 | `frontend/src/routes/documents/DocumentsTab.jsx` (NEW, replace stub) | bảng cột: Tên · Loại · **Notebook** (link) · Generator · Origin · Dung lượng · Người tạo · Thời gian · Hành động | DOM scan; click notebook → deep-link `?page=bizchat-gateway&group=knowledge&sub=notebook&id={nb}` |
| T-P0.35.14.3.4 | same | nút "Tải lên" → modal Sheet với picker notebook (optional) → POST upload | upload event triggers row appear |
| T-P0.35.14.3.5 | same | filter chips: `Tất cả · Đã tải lên · Đã sinh · Ảnh · Video · Tài liệu` (map sang `origin`/`doc_type`) | filter changes URL params |

### M14.W4 — Notebook deep-link + reverse view

| Task | File | Probe |
|---|---|---|
| T-P0.35.14.4.1 | TwinChat Notebook page — thêm tab "Files" đọc `GET /bzdoc/v1/documents?notebook_id={id}` | tab visible |
| T-P0.35.14.4.2 | Documents row → click cell `Notebook` → mở Notebook tab Files đúng vị trí | E2E click probe |
| T-P0.35.14.4.3 | Skeleton bump khi delete document có `origin='upload'` (vì là source) — trigger `BizCity_KG_Skeleton_Adapter::bump_version($nb_id)` | skeleton_version +1 |

**M14 DONE checklist**: 4 wave ✅ · 1 schema migrate · 4 REST routes · CRM tab render thật + upload + filter + notebook link.

---

## 🎬 M-MEDIA.U1 — Media Output Unify (hub-side migration)

> **Wave**: 5 · **Estimated**: 1 sprint · **Risk**: MED (3 plugin legacy phải shim)
> **Phụ thuộc**: M-CRM.M14.W1 (schema bzdoc_documents có cột `generator`, `origin`, `job_id`).
> **Mục tiêu**: tool-image, video-kling, content-creator **đều ghi metadata** vào `wp_bzdoc_documents` qua helper `bzdoc_register_document()`. File vật lý vẫn có thể nằm Media Library/CDN, nhưng **bản ghi truy vết duy nhất** là bzdoc.

### U1.W1 — Helper API + generator registry

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.1.1 | `plugins/bizcity-doc/includes/api-helpers.php` (NEW) | function `bzdoc_register_document(array $args): int\|WP_Error` — validate generator whitelist, fill skeleton_version từ notebook_id nếu chưa set, fire `bzdoc_document_created` | call helper → row exists + hook fired |
| T-P0.35.U1.1.2 | `plugins/bizcity-doc/includes/class-generator-registry.php` (NEW) | `register($slug, $health_callable, $capabilities)` + filter `bzdoc_register_generator` | list contains 4 default + 3 plugin slugs |
| T-P0.35.U1.1.3 | same | interface `BzDoc_Generator_Health` (R-OF-9) | implementations probe-able |

### U1.W2 — Studio Job → bzdoc auto-promote

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.2.1 | `modules/twinchat/includes/studio/class-studio-job-manager.php` | sau `bizcity_twinchat_studio_generated`, nếu output có `file_url` + `notebook_id` → auto-call `bzdoc_register_document()` với `origin='generated'`, `job_id=$job_id`, `generator='twinchat-studio'`; cập nhật `studio_outputs.promoted_doc_id` | fire mock generated → row in bzdoc |
| T-P0.35.U1.2.2 | filter `bizcity_twinchat_studio_skip_promote` để plugin tự xử lý promote | filter wired |

### U1.W3 — bizcity-tool-image shim

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.3.1 | `plugins/bizcity-tool-image/includes/class-image-engine.php` | wrap end-of-generate → call `bzdoc_register_document([generator='bizcity-tool-image', doc_type='image', origin='generated', job_id, notebook_id, file_url, mime, size_bytes, schema_json=>{prompt,model,seed,size}])` | image gen → bzdoc row |
| T-P0.35.U1.3.2 | implement `BzDoc_Generator_Health` cho tool-image (queue depth, last error) | health endpoint returns ok |
| T-P0.35.U1.3.3 | shim đọc — endpoint cũ `/image-editor/v1/...` vẫn trả URL (compat) nhưng metadata đi qua bzdoc | dual-write smoke |

### U1.W4 — bizcity-video-kling shim

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.4.1 | `plugins/bizcity-video-kling/includes/class-kling-engine.php` | sau khi job Kling hoàn tất → `bzdoc_register_document([generator='bizcity-video-kling', doc_type='video', origin='generated', file_url, mime='video/mp4', schema_json=>{prompt,model,duration_s,aspect_ratio,kling_task_id}])` | video done → row in bzdoc |
| T-P0.35.U1.4.2 | implement `BzDoc_Generator_Health` (kling API quota, last error) | health |
| T-P0.35.U1.4.3 | bảng `wp_bizcity_kling_videos` (nếu có) marked deprecated → shim đọc bzdoc trước | dual-read works |

### U1.W5 — bizcity-content-creator shim

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.5.1 | `plugins/bizcity-content-creator/includes/class-creator-engine.php` | sau khi tạo content (markdown/long-form) → `bzdoc_register_document([generator='bizcity-content-creator', doc_type='markdown', origin='generated', file_url=null, schema_json=>{content_md, template_id, tone, length, language}])` (lưu content_md trong schema_json hoặc upload .md vào Media → file_url) | content gen → row in bzdoc |
| T-P0.35.U1.5.2 | implement `BzDoc_Generator_Health` | health |
| T-P0.35.U1.5.3 | wp_posts custom (nếu có) → marked deprecated, shim đọc bzdoc trước khi đọc posts | dual-read |

### U1.W6 — Diagnostic + compliance audit

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.U1.6.1 | `plugins/bizcity-doc/includes/class-output-files-diag.php` (NEW) | render trang `tools.php?page=bzdoc-output-files-diag` chạy 5 grep ở R-OF §7 | trang reachable, scan results |
| T-P0.35.U1.6.2 | tab "Generators" — list từ registry + health + last_success_at + queue_depth | UI mount |
| T-P0.35.U1.6.3 | tab "Migration" — đếm row mỗi generator + % có notebook_id + % có skeleton_version | counters |
| T-P0.35.U1.6.4 | whitelist file `phase-output-files-whitelist.json` cho legacy chấp nhận | parse OK |

**U1 DONE checklist**: 6 wave ✅ · 1 helper API · 4 generator implement health · 3 plugin shim ghi bzdoc · diagnostic tab green ≥ 95%.

---

## §H Risks

| # | Risk | Mitigation |
|---|---|---|
| 1 | Dual-write race (plugin gen vẫn ghi store cũ + bzdoc) → mất sync | Helper `bzdoc_register_document` idempotent theo `(generator, job_id)` UNIQUE — tránh trùng |
| 2 | Skeleton bump quá nhiều khi user upload nhiều file | Throttle `bump_version` 1 lần / 5s / notebook (debounce) |
| 3 | File vật lý orphan khi soft-delete | Cron `bzdoc_retention_tick` chạy daily, xoá file > 30 ngày trong status=`deleted` |
| 4 | CRM Documents tab list quá lớn (notebook nhiều file) | Pagination `?limit=&offset=` mặc định 50 + virtual scroll FE |
| 5 | Generator legacy không implement health → diag fail | Default health stub trả `{ok:true, queue_depth:null}` để không block |
| 6 | wp_posts content-creator có hàng nghìn entry | Migration script chạy chunk 500/batch + progress bar admin notice |

## §H Definition of Done (toàn cụm)

- Schema bzdoc_documents có đủ 4 cột mới + 3 index, version=2.5.
- 4 endpoint REST `/bzdoc/v1/...` reachable + permission đúng.
- CRM Documents tab render thật, hiển thị 2 nguồn (upload + generated), cột notebook deep-link hoạt động.
- 3 plugin (tool-image, video-kling, content-creator) ghi bzdoc song song với store cũ trong ≥ 7 ngày liên tục → đủ điều kiện chuyển sang phase tắt fallback.
- Diagnostic page `bzdoc-output-files-diag` báo cáo R-OF compliance ≥ 95%.
- Hook `bizcity_twinchat_studio_generated` auto-promote 100% output có `file_url`.
- Test E2E: tạo notebook → upload PDF → gen image qua tool-image → cả 2 hiện trong CRM Documents tab + Notebook Files tab cùng lúc.

**End of §H — Documents Hub + Media Output Unify roadmap.**


