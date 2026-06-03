# PHASE N — Port `bizgpt-custom-flows` → `core/channel-gateway/includes/flows/`

> **Mục tiêu:** Đưa toàn bộ logic flow (intent matching → shortcode/send_message → reminder cron) ra
> khỏi plugin riêng `bizgpt-custom-flows`, đặt thành sub-module của Channel Gateway. Sau khi port xong
> plugin gốc → `_archived/`.
>
> **Trigger:** User yêu cầu 2026-05-25 — "2 file này cần được port về thành includes\flows\".
> Folder đích đã thống nhất: `core/channel-gateway/includes/flows/`.

---

## 0 · Inventory plugin gốc

| File | LOC | Mục đích | Action |
|---|---|---|---|
| `bizgpt-custom-flows.php` | 248 | Main + cron reminder | Move cron → `class-flow-reminder-cron.php` |
| `includes/custom-flow-handler.php` | 260 | match/run flow steps | → `class-flow-handler.php` |
| `includes/shortcodes.php` | 413 | 7 shortcodes domain-specific | Tách: shortcode whitelist đã có ở `Campaign_Scenario_Dispatcher::SHORTCODE_WHITELIST` — chỉ port các shortcode CHƯA tồn tại |
| `admin/manage-flows-page.php` | 398 | CRUD UI (PHP forms) | → `class-flow-admin-page.php` (giữ tạm) **+** REST + React UI (Phase B) |
| `admin/manage_flow_actions.php` | 160 | UI cho action_config attrs | Merge vào `class-flow-admin-page.php` |
| `admin/manage-flows-page_20260110.php` | 302 | Snapshot cũ | DROP (không port) |
| `admin/menu.php` | 18 | Menu registration | Re-register dưới menu Channel Gateway |
| `includes/flow-database.php` | 1 | empty | DROP |

**Tổng port: ~1100 LOC PHP + ~600 LOC TS/React (mới).**

---

## 1 · Phase Breakdown

### Phase A — Foundation (turn này)

- [x] Plan doc (file này).
- [ ] **R-DCL** schema changelog: `core/diagnostics/changelog/modules.flows.json` v1.0.0.
  - Table mới `bizcity_cg_flows` (rename khỏi `wp_bizgpt_custom_flows`).
  - Columns: `id, message, message_khong_dau, shortcode, action_type, action_config, prompt, output_json, reminder_delay, reminder_unit, reminder_text, delay_only, reply_mode, updated_at`.
  - Column **mới** `reply_mode ENUM('direct','llm') DEFAULT 'direct'` cho task #2.
- [ ] `class-flow-handler.php` (port của `custom-flow-handler.php`).
- [ ] `class-flow-reminder-cron.php` (port cron logic từ main file).
- [ ] `class-flow-admin-page.php` (PHP form UI tạm — giữ chức năng cũ để không downtime).
- [ ] Wire vào `core/channel-gateway/bootstrap.php`.
- [ ] **Migration**: 1-time auto-copy data từ `wp_bizgpt_custom_flows` → `wp_bizcity_cg_flows` khi plugin host hoạt động và plugin cũ vắng. Idempotent (skip nếu rows đã tồn tại).

### Phase B — REST API (DONE 2026-05-25)

- [x] `class-flow-rest.php` namespace **`bizcity/cg/v1`** (đặt cùng CG namespace, KHÔNG `bizcity/v1` để tránh đụng router HUB):
  - `GET    /flows?q=&action_type=&limit=&offset=` — list + filter
  - `GET    /flows/{id}` — detail
  - `POST   /flows` — create
  - `PUT    /flows/{id}` — update
  - `DELETE /flows/{id}` — delete
  - `POST   /flows/{id}/test { text }` — dry-run match
  - `GET    /flows/dropdowns` — shortcode whitelist + reply_modes + reminder_units + placeholders
  - `GET    /flows/health` — counters + migration status (cho probe)
- [x] Permission: `manage_options`.
- [x] Init: `BizCity_CG_Flow_REST::init()` trong `flows/bootstrap.php`.
- [x] **Diagnostic probe** `BizCity_Probe_CG_Flows` (`core/diagnostics/includes/probes/class-probe-cg-flows.php`):
  - Layer 1 (Disk): 5 file flows/* tồn tại + no BOM + CG bootstrap require.
  - Layer 2 (Loader): 3 class load + backward-compat fn.
  - Layer 3 (Runtime): table + column `reply_mode` + REST route registered + `strip_accents('chào bạn')==='chao ban'` + INSERT/SELECT/DELETE round-trip.

### Phase C — React UI (DONE 2026-05-25)

- [x] Route `/flows` trong CG admin SPA (`core/channel-gateway/frontend/src/routes/flows/`).
- [x] Components:
  - `FlowsRoute.jsx` — table list + search + action_type filter + health badge + per-row test button.
  - `FlowFormSheet.jsx` — Sheet với toàn bộ fields, shortcode chips, attrs repeater (reuse `campaigns/AttributeRows.jsx`), `reply_mode` radio (chỉ hiện khi action=send_message), reminder block.
- [x] RTK Query slice: `redux/api/flowsApi.js` (8 endpoints).
- [x] Wired vào `redux/store.js` + `shell/navConfig.js` (MARKETING_NAV) + `shell/Workspace.jsx` (route).

### Phase D — Cleanup

- [ ] Sau khi UI mới chạy ổn 1 sprint → user move `bizgpt-custom-flows/` vào `_archived/`.
- [ ] `class-flow-admin-page.php` (PHP form) chuyển sang `@deprecated` + redirect sang `/wp-admin/admin.php?page=bizcity-channel-gateway#/flows`.

---

## 2 · Database Migration Strategy

```
1. Auto-create bizcity_cg_flows via R-DCL Auto_Create probe.
2. On first admin load (after plugin update):
   - if (count(wp_bizgpt_custom_flows) > 0 && count(wp_bizcity_cg_flows) == 0)
     → INSERT INTO bizcity_cg_flows SELECT *, 'direct' AS reply_mode FROM wp_bizgpt_custom_flows;
   - Set option `bizcity_cg_flows_migrated_from_bizgpt = 1`.
3. Sau migration, plugin cũ chuyển sang READ-ONLY mode (deactivate hook handler nếu mới hơn ver X).
```

**KHÔNG drop bảng cũ** — giữ làm backup tới khi user manual archive plugin.

---

## 3 · Reply Mode Field (task #2)

```php
'reply_mode' ENUM('direct','llm') DEFAULT 'direct'
```

- `direct`: gửi raw `prompt` text trực tiếp (đã render placeholder) — bỏ qua LLM call.
- `llm`: pass `prompt` qua `BizCity_LLM_Client::chat()` với system "trợ lý CSKH thân thiện" → message kết quả gửi cho khách.

Handler branch:

```php
if ( 'send_message' === $row->action_type ) {
    $msg_text = self::render_placeholders( $row->prompt, $hook_data );
    if ( 'llm' === $row->reply_mode ) {
        $msg_text = self::generate_via_llm( $msg_text );
    }
    // gửi qua adapter ...
}
```

UI: 2 radio buttons cạnh trường prompt:
- ⚪ Trả lời trực tiếp văn bản này
- ⚪ Sinh qua LLM (system prompt CSKH)

---

## 4 · Hook Compatibility Layer

Plugin cũ expose:
- `bizgpt_handle_guest_flow($question)` — entry point từ webchat
- `bizgpt_cron_check_reminders` — cron tick
- `bizgpt_send_reminder_to_client` action

Trong port mới:
- Class methods: `BizCity_CG_Flow_Handler::handle_guest_flow($q)`, `BizCity_CG_Flow_Reminder_Cron::tick()`.
- Backward-compat wrappers (gỡ sau Phase D):
  ```php
  if ( ! function_exists( 'bizgpt_handle_guest_flow' ) ) {
      function bizgpt_handle_guest_flow( $q ) {
          return BizCity_CG_Flow_Handler::instance()->handle_guest_flow( $q );
      }
  }
  ```

---

## 5 · Ràng buộc R-DCL (BẮT BUỘC trước khi commit)

1. `modules.flows.json` MUST có `current_version` ≥ history max.
2. Mọi column phải có `since:` khớp version.
3. Chạy `php core/diagnostics/validate-schema-changelog.php` → exit 0.
4. KHÔNG dbDelta / CREATE TABLE / ALTER TABLE bên ngoài auto-create flow.

---

## 6 · Definition of Done — Phase A

- [x] Validator R-DCL pass.
- [x] `bizcity_cg_flows` table tự tạo khi user vào diagnostic page lần đầu.
- [x] Migration copy thành công khi cả 2 table cùng tồn tại + đích rỗng.
- [x] Admin menu hub Channel Gateway xuất hiện submenu "Flows" mở PHP form UI cũ.
- [ ] FB Messenger campaign vẫn nhận tin nhắn (regression test).
- [x] `reply_mode='direct'` flow gửi text raw — không gọi LLM (verify qua debug log không có `llm_chat_call`).

---

## 7 · BE Audit — Risk Inventory khi archive `bizgpt-custom-flows`

> Thực hiện 2026-05-25 trước Phase D.

| Asset | Hiện trạng | Risk khi archive | Mitigation |
|---|---|---|---|
| `bizgpt_log_chat_message()` | ✅ Re-defined ở `modules/webchat/includes/functions.php:207` | None | — |
| `bizgpt_get_webchat_identity()` | ✅ Re-defined ở `plugins/bizcity-admin-hook-zalo/bootstrap-AsterX18840u.php:165` | None | — |
| Shortcode `kiem_tra_diem`, `doi_diem` | ✅ Re-defined ở `class-loyalty-shortcodes.php:46,49` | None | — |
| Shortcode `tim_san_pham`, `tim_bai_viet`, `dat_hang`, `tim_chuong_trinh_uu_dai`, `tin_tuc_moi_nhat`, `kiem_tra_diem_simple` | ❌ CHỈ tồn tại trong `bizgpt-custom-flows/includes/shortcodes.php` | **BREAK** mọi flow rows đang dùng 6 shortcode này | **Port sớm** sang `core/channel-gateway/includes/flows/shortcodes/` hoặc giữ plugin cũ active READ-ONLY |
| Reminder cron `bizgpt_cron_check_reminders` | ❌ Chưa port | **BREAK** mọi reminder text + `delay_only` flow | Port sang `class-flow-reminder-cron.php` (Phase A.2), đăng ký qua `BizCity_Cron_Manager::register()` + R-CRON-META `note()`/`note_event()` |
| Table `wp_bizgpt_inbox` | ⚠️ External dep của reminder cron | Reminder cron fail nếu vắng | Verify còn trong Site Provisioner hoặc inline declare trong `modules.flows.json` |

**Khuyến nghị**: KHÔNG archive `bizgpt-custom-flows/` cho tới khi (a) port 6 shortcodes thiếu và (b) port reminder cron xong. Hiện tại plugin host + plugin cũ CO-EXIST OK vì backward-compat wrappers chỉ define khi function chưa tồn tại.

---

**Tổng kết:** Phase B + C đã hoàn thành 2026-05-25. Còn lại: Phase A.2 (reminder cron + 6 shortcodes thiếu) + Phase D (archive plugin cũ).
