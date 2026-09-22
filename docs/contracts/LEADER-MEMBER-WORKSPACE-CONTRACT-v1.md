# BizCity Leader/Member Workspace Contract v1

> **Contracts:** `leader-task-handoff@1.0.0` · `customer-360-team-view@1.0.0` ·
> `member-customer-360@1.0.0` · `staff-customer-portfolio@1.0.0`
> **Status:** Schema + fixture + catalog entry **có** (C-01, 2026-09-18, catalog `1.10.0`, contract test PASS 30 contracts).
> **Runtime adoption chưa có:** REST hiện trả DTO phẳng (`ok/contract/version/...`), chưa bọc envelope §0.1 — xem §7.
> Schema: `core/twin-core/contracts/schema/public/v1/{leader-task-handoff,customer-360-team-view,member-customer-360,staff-customer-portfolio}.schema.json`.
> **Rule:** [R-LEADER-MEMBER](../rules/PHASE-0-RULE-LEADER-MEMBER-WORKSPACE.md)
> **Phase:** [PHASE-0.50](../../plugins/bizcity-twin-crm/docs/PHASE-0.50-CRM-CUSTOMER-360-LEADER-MEMBER-WORKSPACE-UI-FIRST.md)
> **Depends on:** `user-inbox-scope@1.0.0` (R-USER-INBOX-SPINE §6), journey event envelope (0.48B §3.1),
> `BizCity_CRM_Staff_Policy::can()` (0.48F R-CRMF-2), `extension-storage-context@1.0.0`.

---

## 0. Chung cho cả bốn contract

### 0.1 Envelope

```text
{
  contract          "<name>@<semver>"
  surface           "B2_ADMIN_CRM" | "C_PUBLIC_TWINGPT"      // server-set, không từ request
  principal         { blog_id, actor_user_id }               // actor = current WP user
  subject           { user_id, display_name, team_role }     // B2: có thể ≠ actor ; C: luôn = actor
  as_of             ISO-8601 (site timezone offset)
  coverage          { complete: bool, degraded_sources: [code], truncated: bool }
  data              <contract-specific>
  denied            [{ scope, reason }]                      // reason ∈ bảng 0.3
}
```

### 0.2 Bất biến

1. `surface`, `principal`, `subject` do server điền. Request chỉ gửi **selector** (`owner`, `member`,
   `userId`, `contact_id`, `task_id`), server kiểm lại.
2. Trên `C_PUBLIC_TWINGPT`: `subject.user_id === principal.actor_user_id` luôn đúng; nếu request chứa
   `user_id|owner|member|uid` ⇒ bỏ qua + log reason bucket `c_user_selector_ignored`.
3. Không field nào chứa SĐT thô, provider UID thô, token, đường dẫn file, SQL. SĐT dùng
   `{ account_key (HMAC), masked }`.
4. Field không được phép trên surface ⇒ **vắng mặt**, không gửi kèm cờ ẩn.
5. Thiếu nguồn ⇒ giá trị `null` + mã trong `coverage.degraded_sources`; không trả `0` giả.
6. Mọi mutation có `client_request_id` (idempotent 10 phút) và audit (actor, subject, action, before/after tối
   thiểu, không PII khách).

### 0.3 Reason codes (R-ERROR-UX)

| Code | HTTP | Khi nào |
|---|---|---|
| `member_not_manageable` | 403 | actor không quản lý được subject (`Staff_Policy`) |
| `task_subject_out_of_scope` | 422 | khách/hội thoại gắn vào việc không thuộc scope người nhận |
| `task_transition_invalid` | 409 | chuyển trạng thái không hợp lệ hoặc sai vai trò |
| `task_not_found` | 404 | task không tồn tại **hoặc** không thuộc actor (không phân biệt để tránh dò) |
| `personal_account_quota_reached` | 422 | vượt hạn mức SĐT Zalo Cá nhân / user theo gói (thay `personal_primary_limit_reached`) |
| `contact_not_in_scope` | 404 | contact ngoài mọi scope actor xem được |
| `c_user_selector_ignored` | — | chỉ log, không lỗi |

### 0.4 `Staff_Policy` action mới (bổ sung `MIN_RANK`, agent=1 lead=2 supervisor=3 admin=4)

| Action | MIN_RANK | SELF | Dùng cho |
|---|---|---|---|
| `contact.view_by_owner` | 2 | cho phép (xem khách của chính mình = hành vi cũ) | W1 `owner=` |
| `task.assign` | 2 | cho phép tự giao cho mình | W4 giao / cancel / reassign / reopen |
| `task.view_board` | 2 | — | W4 bảng đội |

Action không khai báo ⇒ admin-only (hành vi fail-closed sẵn có của `Staff_Policy::can()`).

---

## 1. `leader-task-handoff@1.0.0`

**Owner storage:** `bizcity_crm_tasks` (không bảng mới). Trạng thái ở `status` (VARCHAR).

### 1.1 Task DTO — B2 (leader)

```text
task {
  task_id
  title, instructions            // instructions ≤ 2000 ký tự, plain text
  priority                       low | medium | high | urgent
  due_at                         ISO-8601 | null   (lưu due_date; giờ nếu có ở audit/meta — xem C-03)
  status                         sent | accepted | in_progress | done | returned | cancelled
  overdue                        bool (tính: due_at < now && status ∉ {done, cancelled})
  assigned_by                    { user_id, display_name }         // = created_by
  assignee                       { user_id, display_name, team_role }
  template_code                  callback | reactivate | send_quote | post_purchase_care | custom
  subjects[]                     { kind: contact|conversation, contact_id, conversation_id?,
                                   display_name, stage?, touched: bool }
  progress                       { touched, total }
  batch_key                      string | null      // correlation việc hàng loạt
  timeline[]                     { at, actor_user_id, from, to, reason_code? }
  result                         { note_excerpt, note_ref } | null
  can[]                          ["cancel","reassign","reopen"]   // gợi ý UI, không phải ACL
}
```

### 1.2 Task DTO — C (member)

```text
task {
  task_id, title, instructions, priority, due_at, status, overdue
  assigned_by       { display_name }              // không user_id của leader
  template_code
  subjects[]        { kind, conversation_id?, inbox_id?, display_name (theo policy C),
                      phone_masked?, can_open_thread: bool }
  progress          { touched, total }
  seen              bool
  can[]             ["accept","start","complete","return"]
}
```

Vắng mặt trên C: `assignee` (là chính mình), `timeline` của người khác, `batch_key`, `result` của task khác,
ghi chú riêng leader, doanh thu, KPI.

### 1.3 Chuyển trạng thái

| Từ → Đến | Ai | Điều kiện |
|---|---|---|
| `sent → accepted` | assignee (C) | — |
| `accepted → in_progress` | assignee | — |
| `sent/accepted → in_progress` | assignee | cho phép bỏ qua bước nhận |
| `accepted/in_progress → done` | assignee | `result_note` tuỳ template (bắt buộc với `send_quote`, `reactivate`) |
| `sent/accepted/in_progress → returned` | assignee | `reason_code ∈ {wrong_person, customer_unreachable, out_of_scope, other}` + ghi chú |
| `returned → sent` | leader (`task.assign`) | `reassign` (người nhận mới) hoặc `reopen` (cùng người) |
| `* (≠ done) → cancelled` | leader | — |
| `done → sent` | leader | `reopen` + lý do |

`seen` ghi lần đầu member mở task trên C (audit, không đổi `status`).

### 1.4 Create request (B2)

```text
POST /crm-tasks/handoff
{
  client_request_id
  assignee_user_id                // selector
  title, instructions, priority, due_at, template_code
  subjects: { contact_ids: [..≤200] } | { conversation_id } | {}
  out_of_scope_policy             "reject" | "strip"
  notify: { zalo_bot: bool }
}
→ 201 { contract, data: { batch_key, created: [task_id], stripped: [{contact_id, reason}] } }
```

Server: `Staff_Policy::can(actor,'task.assign',assignee)` → với mỗi subject kiểm
`contact ∈ user-inbox-scope(assignee) ∩ contact_scope` và Zalo Personal exact-owner → áp policy → tạo
1 task / (assignee, contact) → audit → emit event admin-branch `crm.task.assigned` (không PII khách).

### 1.5 Thông báo

- `/gpt/`: badge số task `status=sent && !seen`.
- Zone 2 Zalo Bot (tuỳ chọn): "Bạn có {n} việc mới từ {leader_display_name}, hạn {date}." — không tên/SĐT khách,
  throttle 1 tin / assignee / 10 phút.

### 1.6 Tương thích task cũ

`bizcity_crm_tasks.status` mặc định hiện là `open` (installer `:598`) và task cũ có thể dùng
`open/in_progress/done`. Quy tắc đọc, không migrate dữ liệu:

- Task có `created_by ≠ assignee_id` ⇒ là việc được giao: `open → sent`, `in_progress`, `done` giữ nguyên.
- Task có `created_by = assignee_id` hoặc `created_by` null ⇒ việc cá nhân, **không** hiện trên bảng giao việc W4,
  vẫn hiện ở "Việc của tôi" (MyWorkTab / W5 nhóm "Việc tự tạo").
- Writer mới chỉ ghi vocabulary §1.3. **[Cần xác minh]** toàn bộ giá trị `status` đang có trong production trước C-01.

---

## 2. `customer-360-team-view@1.0.0` (chỉ `B2_ADMIN_CRM`)

```text
data {
  contact        { contact_id, display_name, avatar_ref, identity_state: strong|matched|ambiguous|conflict,
                   channels[] { channel_code, account_key, phone_masked?, inbox_id } }
  ownership      { current: { user_id, display_name, since },
                   history[]: { user_id, display_name, from, to, reason_code } ,
                   caring_accounts[]: { account_key, phone_masked, owner_user_id, session_state } }
  stage          { lens, code, evidence_state, as_of } | null
  overview       { open_risks[], open_tasks_count, unpaid_orders_count, next_action? }
  journey        { events[] <journey_event 0.48B §3.1>, next_cursor }
  staff_touches[] { user_id, display_name, channel_code, account_key?, replies, first_response_seconds?,
                    last_touch_at }                               // AI và broadcast là dòng riêng actor_type
  orders         { items[] { order_id, status, total, paid_at?, attributed_user_id?,
                             attribution: assignee_at_create|creator_fallback|backfill_event|backfill_current },
                   lifetime_paid_total?, currency }
  marketing      { acquisition_source, first_seen_at, campaigns[] { campaign_id, name, touched_at,
                   response: none|replied|clicked|ordered, attribution_state }, labels[], segment?, score?,
                   segment_as_of? }
  tasks[]        <leader-task-handoff B2 DTO rút gọn>
  conflicts[]    { other_contact_id?, reason }     // other_contact_id chỉ khi actor là admin tenant
  deep_links     { workspace_thread?: "#/workspace/:userId/inbox/:inboxId/conv/:convId" }
}
```

Quy tắc: contact phải thuộc scope của ít nhất một subject actor quản lý được. `staff_touches` chỉ gồm nhân viên
actor quản lý được; nhân viên ngoài đội gộp thành `{ user_id: null, display_name: "Nhân viên khác" }`.
Không có nội dung tin nhắn đầy đủ.

## 3. `member-customer-360@1.0.0` (chỉ `C_PUBLIC_TWINGPT`)

```text
data {
  contact       { contact_id, display_name (policy C), phone_masked?, channels_in_scope[] { channel_code, inbox_id } }
  stage         { lens, code, evidence_state, as_of } | null
  journey       { events[] (≤ 5, visibility=customer_care), has_more }
  previously_cared  bool              // không tên người, không thời điểm
  tasks[]       <leader-task-handoff C DTO, chỉ task của current user về khách này>
  care          <contact-care hiện có 0.48C §3.15>
  orders[]      { order_id, status, total, paid_at? }   // chỉ đơn gắn hội thoại/khách trong scope member
}
```

Vắng mặt: `ownership`, `staff_touches`, attribution, marketing campaigns của đội, doanh thu lifetime, ghi chú của
người khác, conflicts.

## 4. `staff-customer-portfolio@1.0.0` (chỉ `B2_ADMIN_CRM`)

```text
data {
  range           { from, to, preset: today|7d|30d|custom }
  accounts[]      { account_key, phone_masked, channel_code: "zalo_personal", session_state, customers_count }
  business_inboxes[] { inbox_id, channel_code, name, customers_count }
  stages          { new, consulting, purchased, repeat, dormant, at_risk }      // null khi thiếu policy
  funnel          { new_by_source: { <source>: n }, touched, need_known, ordered, repeat,
                    rates: { ordered_over_touched: { num, den } } }             // ẩn rate khi den < 10
  attention[]     { kind: dormant_buyers|unreplied|task_overdue, count, drill_down }
  tasks           { sent, accepted, in_progress, done, returned, overdue, on_time_rate: { num, den } }
}
```

`drill_down` là URL hash B2 (§3.7 PHASE-0.50), không chứa SĐT hay tên khách.

---

## 5. Validation & DDV

| Layer | Kiểm |
|---|---|
| Disk | schema JSON 4 contract + fixture hợp lệ/không hợp lệ (fixture không hợp lệ phải có: SĐT thô, `subject ≠ actor` trên C, `staff_touches` trên C, rate với `den < 10`) |
| Loader | serializer B2 và C là hai class khác nhau; route C không gọi REST admin |
| Runtime | LM-T1..LM-T11 (R-LEADER-MEMBER §3) |

Probe (planned): `core.crm.leader_member_workspace`, `twingpt.crm.task_inbox_scope`,
`core.channel.personal_multi_account_owner`.

## 6. Versioning

Thêm field optional ⇒ minor. Đổi nghĩa trạng thái, bỏ field, đổi surface của field ⇒ major + migration note.
Vocabulary `status` của `leader-task-handoff` là public: thêm trạng thái mới là **major**.

## 7. Schema 1.0.0 — đã chốt so với bản đề xuất (2026-09-18, C-01)

Schema bám DTO mà source đã trả (PHASE-0.50 §11–§12) thay vì bản nháp §1–§4, để runtime adoption chỉ còn là bọc envelope.

| Contract | Khác bản nháp | Lý do |
|---|---|---|
| chung | `contract` + `version` là hai field (theo catalog), không phải `"name@semver"` | quy ước catalog public v1; route `/crm-tasks/board` hiện vẫn trả `"leader-task-handoff@1.0.0"` — sửa khi adopt |
| `leader-task-handoff` | `due_at` = ngày `YYYY-MM-DD`; `template_code` optional (không lưu); `subjects` ≤ 1 (1 task / khách); subject C có thêm `channel`, `ref`; `timeline[].action` = hậu tố audit `handoff_*` (`sent/seen/accepted/in_progress/done/returned/cancel/reassign/reopen`) | C-03 không thêm cột; `/gpt/crm/` mở inbox theo `channel`+`ref` |
| `customer-360-team-view` | theo DTO `team-360`: `owner`, `conversations[]`, `touched_by[]`, `automated_replies` (số), `orders{available,items}`, `identity_conflicts{open}`, `can`; chưa có `ownership.history`, `stage`, `journey`, `campaigns` | chưa có nguồn (§12 W2 "thiếu") — thêm là **minor** |
| `member-customer-360` | `journey` = `{stage, paid_order_count, last_touch_at, previously_cared, milestones[≤5]}` (§11.6) thay `events[]`; `contact` cho phép `phone`/`email` | xem mục cần quyết định bên dưới |
| `staff-customer-portfolio` | theo DTO portfolio: `phones[]`, `buckets[]` (value `null` khi thiếu nguồn), `sources{}`, `funnel{touched,ordered,rate,rate_basis,repeat}`; `rate_basis.den ≥ 10` | D3; runtime chưa trả `rate_basis` |

Kiểm tra ngữ nghĩa trong `core/twin-core/contracts/tests/run-contract-tests.mjs` (validator cấu trúc không biểu diễn được):
trên `C_PUBLIC_TWINGPT` `subject.user_id === principal.actor_user_id`; task C không có `assignee/timeline/result/batch_key/updated_at`
và `assigned_by.user_id`; `can[]` C chỉ gồm hành động member, B2 chỉ gồm hành động leader.

**Cần quyết định / chênh lệch runtime so với bất biến §0.2-3 (không có SĐT thô):**
1. `team-360` hiện trả `contact` qua `shape_crm_contact()` (có SĐT/email thô) trên B2 — schema chỉ cho `phone_masked`.
2. C Customer 360 trả `profile.phone`/`email` thô để member sửa thông tin khách **của chính hội thoại mình** (0.48C §3.15) —
   schema đang cho phép. Product chọn: giữ (ngoại lệ ghi rõ) hay chuyển sang masked + form sửa riêng.
3. Subject C của task có `ref` = `channel_ref_id` của inbox — **[Cần xác minh]** có phải provider UID thô với Zalo Cá nhân không.
