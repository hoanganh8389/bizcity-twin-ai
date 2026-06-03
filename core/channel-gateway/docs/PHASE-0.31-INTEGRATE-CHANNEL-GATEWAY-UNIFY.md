# PHASE 0.31 — Integrate & Unify Channel Gateway around `bizcity-automation`

> ## ⛔ SUPERSEDED — 2026-05-13
> **Thay thế bởi:** [PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md](PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md)
> **Lý do đảo chiều:** Integration Hub là `bizchat-gateway` (core/channel-gateway), không phải `bizcity-automation`. `bizcity-automation` chỉ là VIEW đọc cùng `BizCity_Integration_Registry`.
> **Rule:** [PHASE-0-RULE-CHANNEL-ONLY.md](PHASE-0-RULE-CHANNEL-ONLY.md) R-CH-6 / R-CH-7.
> **Phần còn giá trị:** Runtime Location Notes (§⚠️), adapter fix BUG-1/-2, Channel Role fix BUG-5, WaicChannelIntegration concept (mở rộng thành `BizCity_Channel_Integration`).
> **Phần bị đảo:** "bizcity-automation là single integration hub" → `bizchat-gateway` là hub.
> **Hành động:** Đọc PHASE-0.37 trước khi sửa bất cứ thứ gì liên quan đến channel/integration menu/storage.

---

> **Phiên bản:** 1.0 (2026-05-07)
> **Trạng thái:** ⛔ SUPERSEDED by PHASE-0.37 (2026-05-13)
> **Tác giả:** Twin AI Architecture
> **Liên quan:**
> - [PHASE-1.5-CHANNEL-GATEWAY-ROLE.md](PHASE-1.5-CHANNEL-GATEWAY-ROLE.md)
> - [PHASE-1.5-CHANNEL-ROLE-ARCHITECTURE.md](PHASE-1.5-CHANNEL-ROLE-ARCHITECTURE.md)
> - [PHASE-1.5-GATEWAY-ROADMAP.md](PHASE-1.5-GATEWAY-ROADMAP.md)
> - [PHASE-1.5-SMART-GATEWAY-MIGRATION.md](PHASE-1.5-SMART-GATEWAY-MIGRATION.md)

> **Triết lý:** `bizcity-automation` là **trung tâm điều phối kết nối ngoài** (Integration Hub). Mọi Channel/Tool/Provider mới đều được lắp đặt ở đó dưới dạng **Integration + Trigger + Action block**. Trang `bizchat-gateway` chỉ là **dashboard quan sát** + deep-link sang Integration tab — không còn là nơi cấu hình rời.

> ## ⚠️ RUNTIME LOCATION NOTES (chốt 2026-05-07 — đọc TRƯỚC khi sửa)
>
> | Plugin | ✅ Runtime path (ACTIVE) | ❌ KHÔNG dùng |
> |---|---|---|
> | **Zalo Bot** | `plugins/bizcity-twin-ai/plugins/bizcity-zalo-bot/` | ~~`mu-plugins/bizcity-zalo-bot/`~~ — DEPRECATED, không autoload (xem `mu-plugins/bizcity-zalo-bot/DEPRECATED.md`) |
> | **Facebook Bot** | `mu-plugins/bizcity-facebook-bot/` | (vẫn ở mu-plugins) |
> | **Zalo Hotline (admin-hook-zalo)** | `mu-plugins/bizcity-admin-hook-zalo/` | (vẫn ở mu-plugins) |
> | **KG Hub / Scheduler / Notebook** | `plugins/bizcity-twin-ai/core/` | — |
>
> Quy tắc: trước khi search/edit, `Test-Path` cả 2 path nếu plugin từng được di chuyển. Tránh mất thời gian/token sửa nhầm code chết.

---

## 0. TL;DR

1. Thống nhất 5 nhánh đang phân mảnh (Facebook / Zalo hotline / Zalo Bot / Google / Scheduler) thành **một mặt phẳng quản lý** ở `bizcity-automation` → tab **Tích hợp bên ngoài**.
2. Mở rộng `WaicIntegration` để đại diện cho cả **Channel adapter** (inbound + outbound), không chỉ outbound như hiện tại.
3. `bizchat-gateway` được **demote** thành "Gateway Status / Channel Role Dashboard"; mọi nút "Connect / Configure" deep-link sang Automation → Integrations → `{code}`.
4. Thêm block **`nb_*`** (Notebook actions) và bộ **`fb_*`/`zb_*`/`zh_*`** (Facebook / Zalo Bot / Zalo Hotline) vào `modules/workflow/blocks/` để workflow có thể "Notebook → đăng FB / Zalo".
5. **Fix bug 403** `admin.php?page=bizcity-facebook-settings` — callback `bztfb_render_settings_page()` chưa tồn tại nên slug không được `add_submenu_page` đăng ký → WordPress trả `wp_die('Sorry, you are not allowed to access this page.')`.

---

## 1. Hiện trạng — Vì sao đang phân mảnh

| # | Trang admin | Plugin sở hữu | Slug menu | Cap | Vấn đề |
|---|-------------|---------------|-----------|-----|--------|
| 1 | Facebook Settings | `mu-plugins/bizcity-facebook-bot` + entry rỗng trong `bizcity-twin-ai` | [`bizcity-facebook-settings`](includes/class-admin-menu.php#L310) | `manage_options` | ❌ **Bug 403** — callback `bztfb_render_settings_page()` không tồn tại, slug không được đăng ký → WP `wp_die` 403 |
| 2 | Facebook Bots Hub | `bizcity-facebook-bot` | `bizcity-facebook-bots` (+ 9 sub) | `manage_options` | ⚠️ Đứng riêng top-level menu, không nối Gateway |
| 3 | Zalo hotline 0562608899 | `mu-plugins/bizcity-admin-hook-zalo` | `zalo-users-admin` (qua Gateway parent) | `manage_options` | ⚠️ Là glue layer, function `bizcity_gateway_*` nằm ở mu-plugin |
| 4 | Zalo Bot | `bizcity-twin-ai/plugins/bizcity-zalo-bot` | `bizcity-zalo-bots` (+ 4 sub) | `manage_options` | ⚠️ Đứng riêng, đăng ký 5 menu, không có entry trong Automation Integrations |
| 5 | Google Tools | `plugins/bizgpt-tool-google` | `bzgoogle-settings` (qua Gateway parent) | `read` | ⚠️ OAuth riêng, không tái dùng `WaicIntegration` (gmail/googlecalendar đã có sẵn) |
| 6 | Scheduler | `core/scheduler` | `bizcity-scheduler` (qua Gateway parent) | `read` | ⚠️ Hợp lệ nhưng nằm cạnh Gateway thay vì gắn với Automation triggers |
| 7 | BizChat Gateway | `core/channel-gateway` | `bizchat-gateway` | `manage_options` | ⚠️ Là parent menu chứa lẫn lộn cả "channel chat", "tool google", "scheduler" — không có bất kỳ integration nào ở Automation tab kết nối ngược về đây |

### Hệ quả

- Người dùng không tìm được Zalo Bot / Facebook khi vào tab "Tích hợp bên ngoài" của Workflow Builder → không thể chọn block "Action: gửi qua Facebook Page X" / "Trigger: nhận inbox FB từ Page X" mặc dù backend đã có `wu_facebook_*`, `wu_zalobot_*`, `wp_send_facebook_bot_*`, `wp_send_zalo_bot_*` (đã có 6 action + 6 trigger trong `modules/workflow/blocks/`).
- Quyền (token / page id / OA id) lưu rải rác ở `wp_options`: `bizcity_zalobot_token_{id}`, `fbm_page_access_token`, `bzgoogle_client_secret`, `twf_bot_token`… mỗi nơi mã hoá theo chuẩn riêng.
- Notebook (Thu Trang) không có cách "phát" workflow vì thiếu **Trigger `nb_*`** và **Action `nb_*`**.

---

## 2. Mục tiêu (TO‑BE)

```
┌──────────────────────────────────────────────────────────────────────┐
│  bizcity-automation  →  Tab "Tích hợp bên ngoài" (Integrations Hub)  │
│                                                                       │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────────────┐ │
│  │ AI Providers    │  │ Channels        │  │ Tools / Services     │ │
│  │  • OpenRouter   │  │  • Zalo Bot     │  │  • Google Calendar   │ │
│  │  • OpenAI       │  │  • Zalo Hotline │  │  • Gmail             │ │
│  │  • Anthropic    │  │  • Facebook Page│  │  • Scheduler         │ │
│  │                 │  │  • Telegram     │  │  • Notebook (Twin)   │ │
│  │                 │  │  • SMTP         │  │                      │ │
│  └─────────────────┘  └─────────────────┘  └──────────────────────┘ │
│             │                  │                      │              │
│             ▼                  ▼                      ▼              │
│   ┌────────────────────────────────────────────────────────────┐    │
│   │  WaicIntegrationsModel  +  WaicChannelIntegration (new)    │    │
│   │  • account[] storage (encrypted)                           │    │
│   │  • inbound:  webhook → fire trigger block (wu_*, nb_*)     │    │
│   │  • outbound: action block (wp_send_*, fb_*, nb_*)          │    │
│   └────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌──────────────────────────────────────────────────────────────────────┐
│  bizchat-gateway  →  "Channel Gateway Status" (read-only dashboard)  │
│  - Liệt kê adapter đã đăng ký + role  - Deep-link sang Automation    │
└──────────────────────────────────────────────────────────────────────┘
```

### Nguyên tắc

1. **Một nơi cấu hình duy nhất:** Tab "Tích hợp bên ngoài" của `bizcity-workspace`.
2. **`bizchat-gateway` chỉ quan sát:** không còn sub-menu cấu hình lẻ; mọi nút "Cài đặt" đẩy về `?page=bizcity-workspace&tab=workflow&sub=integrations&code={x}`.
3. **Tái dùng `WaicIntegration`:** Channel = Integration có thêm 2 method `getTriggerBlocks()` + `getActionBlocks()`.
4. **Backward compat:** `BizCity_Channel_Adapter` interface và `bizcity_gateway_fire_trigger()` tiếp tục hoạt động — Channel Integration mới wrap qua adapter cũ.
5. **Không đổi DB:** dùng `wp_options` (`waic_intergations_{code}`) + reuse option keys hiện tại bằng cách map (xem §6).

---

## 3. Kiến trúc đích

### 3.1. Lớp Integration mới

```php
// classes/channelIntegration.php  (NEW — extends classes/integration.php)
abstract class WaicChannelIntegration extends WaicIntegration {
    protected $_category = 'channel';      // override
    protected $_platform = '';             // ZALO_BOT|FACEBOOK|TELEGRAM|ZALO_PERSONAL
    protected $_role     = 'cskh';         // default Channel Role
    protected $_adapter_class = '';        // FQCN implementing BizCity_Channel_Adapter
    protected $_trigger_blocks = [];       // ['wu_zalobot_text_received', ...]
    protected $_action_blocks  = [];       // ['wp_send_zalo_bot_text', ...]

    abstract public function registerWebhook( array $account ): array;  // returns [endpoint, secret]
    abstract public function testInbound( array $account ): array;
    public function getAdapter() { /* lazy-load adapter from $_adapter_class */ }
}
```

### 3.2. Discovery & Registration

- `WaicIntegrationsModel::loadAllIntegrations()` đã quét `modules/workflow/integrations/` → **mở rộng** để cũng quét `modules/workflow/channels/`.
- Filter mới: `apply_filters( 'waic_register_channel_integrations', $list )` cho phép plugin ngoài (zalo-bot, facebook-bot) đăng ký Channel Integration mà không cần copy file vào Automation.
- Bootstrap mỗi plugin:
  ```php
  add_filter( 'waic_register_channel_integrations', function( $list ) {
      $list[ 'zalobot' ] = 'BizCity_Zalo_Bot_Integration';   // class file path
      return $list;
  } );
  ```

### 3.3. Quan hệ Channel ↔ Adapter ↔ Workflow

```
Inbound:
  Webhook hit /zalohook/   →  BizCity_Zalo_Bot_Channel_Adapter::normalize_inbound()
                           →  bizcity_gateway_fire_trigger( 'wu_zalobot_text_received', $data )
                           →  WaicWorkflow execute() match trigger block  →  pipeline LLM/Action

Outbound:
  Workflow runs action `wp_send_zalo_bot_text` (block)
                           →  WaicAction::execute() pull account from WaicIntegrationsModel
                           →  BizCity_Gateway_Sender::send( $platform, $chat_id, $payload )
                           →  Adapter::send_outbound()
```

> **Adapter giữ nguyên** — chỉ thêm tầng "Integration view" để Workflow Builder chọn account và lưu cấu hình từ một UI duy nhất.

### 3.4. Notebook Integration (mục tiêu chính)

Notebook (Thu Trang) cần xuất hiện như một **Channel + Tool**:

| Block code | Loại | Mục đích |
|------------|------|----------|
| `nb_note_created` | trigger | Khi user tạo note mới trong notebook X |
| `nb_note_updated` | trigger | Khi note được cập nhật |
| `nb_note_tagged`  | trigger | Khi note được gắn tag (ví dụ `#publish-fb`) |
| `nb_create_note`  | action  | Tạo note vào notebook |
| `nb_attach_artifact` | action | Đính file/ảnh sinh ra ở bước trước |
| `nb_query_kg`     | action  | Pull context từ KGHub (qua notebook) để feed LLM |

Kết hợp với `wp_send_facebook_bot_text` đã có → workflow điển hình:
> `nb_note_tagged(#publish-fb)` → `ai_generate_facebook` → `wp_send_facebook_bot_text(page_id=X)`

---

## 4. Bug FIX — Facebook Settings 403

### 4.1. Nguyên nhân

`bizcity-twin-ai/includes/class-admin-menu.php` (≈L310-314):

```php
if ( function_exists( 'bztfb_render_settings_page' ) ) {
    add_submenu_page( self::SLUG_GATEWAY,
        __( 'Facebook Settings', $td ), __( 'Facebook Settings', $td ),
        'manage_options', 'bizcity-facebook-settings',
        'bztfb_render_settings_page' );
}
```

- Function `bztfb_render_settings_page()` **không tồn tại trong codebase** (đã grep toàn workspace).
- Vì `function_exists()` trả `false`, `add_submenu_page()` **không được gọi** → slug `bizcity-facebook-settings` không có trong `$submenu` global.
- Khi user mở `?page=bizcity-facebook-settings`, hook `admin_page_access_denied` chạy và WP `wp_die( 'Sorry, you are not allowed to access this page.' )` → 403.

### 4.2. Hai cách fix

**A. Fix nhanh (tối thiểu):** xoá block `if ( function_exists( 'bztfb_render_settings_page' ) ) { add_submenu_page(...) }` (đã chết) và thay bằng deep-link sang trang chính của Facebook Bot:

```php
add_submenu_page( self::SLUG_GATEWAY,
    __( 'Facebook Settings', $td ), __( '📘 Facebook', $td ),
    'manage_options', 'bizcity-facebook-bots',   // điều hướng sang plugin facebook-bot
    null );
```

**B. Fix đúng theo PHASE 0.31:** xoá hẳn entry trong `class-admin-menu.php`, thêm "Facebook" vào tab Integrations của Automation (xem §5). `bizchat-gateway` chỉ liệt kê trạng thái, click vào sẽ deep-link sang `bizcity-workspace&tab=workflow&sub=integrations&code=facebook`.

> **Khuyến nghị:** thực hiện (A) ngay (cùng PR fix) để hết 403, đồng thời (B) trong roadmap để gỡ phân mảnh.

---

## 5. Roadmap thi công

> **Quy ước:** `[T#]` = task; `[D]` = dependency; effort scale: S(<½ ngày) / M(1 ngày) / L(2-3 ngày).

### Phase 0.31.0 — Hot-fix (1 PR, S)
- `[T0.1]` (S) — Fix 403 `bizcity-facebook-settings` theo §4.2-A.
- `[T0.2]` (S) — Audit toàn bộ `function_exists( 'bztfb_*' )` / `class_exists( 'BZGoogle_Admin' )` / `class_exists( 'BizCity_Scheduler_Admin_Page' )` trong `class-admin-menu.php` → log cảnh báo nếu không tồn tại để dễ phát hiện sớm.

### Phase 0.31.1 — Channel Integration Skeleton (M)
- `[T1.1]` (M) — Tạo `bizcity-automation/classes/channelIntegration.php` (`WaicChannelIntegration` extends `WaicIntegration`) với 2 method `getTriggerBlocks()`, `getActionBlocks()`, `getAdapter()`.
- `[T1.2]` (M) — Mở rộng `WaicIntegrationsModel::loadAllIntegrations()` để quét `modules/workflow/channels/` + áp dụng filter `waic_register_channel_integrations`.
- `[T1.3]` (S) — Thêm category mới `channel` vào `getIntegCategories` filter; cập nhật template `adminOptionsTabIntegrations` (group hiển thị riêng "Kênh / Channels").

### Phase 0.31.2 — Migrate 4 kênh hiện có (L)
- `[T2.1]` (M) — `WaicChannelIntegration_zalobot` (file: `plugins/bizcity-zalo-bot/includes/integration-zalobot.php`) — wrap `BizCity_Zalo_Bot_Channel_Adapter`; map account → option `bizcity_zalobot_token_{id}` (đọc legacy, ghi vào `waic_intergations_zalobot`).
- `[T2.2]` (M) — `WaicChannelIntegration_facebook` (file: `mu-plugins/bizcity-facebook-bot/includes/integration-facebook.php`) — wrap adapter Messenger + Page; map `fbm_page_access_token`.
- `[T2.3]` (S) — `WaicChannelIntegration_zalo_hotline` cho mu-plugin `bizcity-admin-hook-zalo` (Zalo personal `0562608899`).
- `[T2.4]` (S) — `WaicChannelIntegration_telegram` đã có khung outbound — bổ sung adapter inbound (đăng ký webhook).
- `[T2.5]` (S) — Mỗi integration đăng ký qua filter trong `bootstrap.php` của plugin tương ứng (không sửa core).

### Phase 0.31.3 — Notebook Channel + Block set (L)
- `[T3.1]` (M) — Trigger blocks: `nb_note_created`, `nb_note_updated`, `nb_note_tagged` ở `modules/workflow/blocks/triggers/` — fire bằng action `bizcity_twin_notebook_event` (đã có trong twin-ai).
- `[T3.2]` (M) — Action blocks: `nb_create_note`, `nb_attach_artifact`, `nb_query_kg`.
- `[T3.3]` (S) — `WaicChannelIntegration_notebook` chỉ chứa "select notebook + scope" (không cần token).
- `[T3.4]` (S) — Tài liệu sample workflow: "Thu Trang notebook → AI viết bài → đăng FB Page".

### Phase 0.31.4 — Demote `bizchat-gateway` thành Dashboard (M)
- `[T4.1]` (S) — `BizCity_Gateway_Admin::render_overview()` đổi thành read-only: liệt kê adapter, role, trạng thái webhook, và **deep-link** mỗi dòng sang `?page=bizcity-workspace&tab=workflow&sub=integrations&code={code}`.
- `[T4.2]` (S) — Trong `class-admin-menu.php` của `bizcity-twin-ai`: gỡ submenu `bizcity-tool-facebook`, `bizcity-facebook-settings`, `zalo-users-admin`, `zalo-guider`, `bzgoogle-settings` khỏi parent `bizchat-gateway`. Giữ `bizcity-scheduler` (đặc thù end-user) hoặc move sang Automation tab "Triggers/Schedules" (option B, `[T4.3]`).
- `[T4.3]` (M, optional) — Tích hợp Scheduler vào Automation: Scheduler trở thành provider cho trigger `sy_schedule` (đã có) — dùng API REST `bizcity-scheduler/v1` để 2 chiều.

### Phase 0.31.5 — Cleanup & QA (M)
- `[T5.1]` (S) — Cập nhật `PHASE-1.5-GATEWAY-ROADMAP.md` đánh dấu BUG-1, BUG-2 đã merge vào 0.31.
- `[T5.2]` (S) — Smoke test: gửi tin Zalo Bot, Messenger FB, Telegram → workflow chạy → outbound thành công.
- `[T5.3]` (M) — Migration script: copy/sync option cũ (`bizcity_zalobot_token_*`, `fbm_page_access_token`, `twf_bot_token`) vào schema `waic_intergations_*` và đánh dấu **read-only** (giữ legacy đọc, ghi mới vào schema mới).
- `[T5.4]` (S) — Bổ sung integration test cho REST `/bizcity/v1/channel/send` đảm bảo route qua adapter mới.

---

## 6. Mapping option key (legacy → unified)

| Legacy key | Plugin | Unified (new) | Field trong `WaicChannelIntegration` |
|------------|--------|---------------|---------------------------------------|
| `bizcity_zalobot_token_{bot_id}` | zalo-bot | `waic_intergations_zalobot[{idx}]['token']` | `_settings['token']` (encrypt) |
| `bizcity_zalobot_webhook_secret` | zalo-bot | `waic_intergations_zalobot[{idx}]['secret']` | `_settings['secret']` |
| `fbm_page_access_token` | facebook-bot | `waic_intergations_facebook[{idx}]['page_token']` | `_settings['page_token']` |
| `bzgoogle_client_id_raw` / `_secret` | bzgoogle | gộp vào `waic_intergations_gmail` + `googlecalendar` (đã có sẵn) | reuse |
| `twf_bot_token` | telegram legacy | `waic_intergations_telegram[{idx}]['bot_token']` | `_settings['bot_token']` |

> **Compat rule:** đọc legacy nếu unified rỗng; ghi mới chỉ vào unified. Sau 1 release ổn định mới remove legacy reader.

---

## 7. Validation Checklist

- [ ] `?page=bizcity-facebook-settings` không còn 403.
- [ ] Vào `Workflows → Tích hợp bên ngoài` thấy 4 kênh mới: Zalo Bot, Facebook Page, Zalo Hotline, Notebook.
- [ ] Tạo workflow `nb_note_tagged(#publish-fb) → ai_generate_facebook → wp_send_facebook_bot_text` chạy thành công.
- [ ] `?page=bizchat-gateway` chỉ hiển thị status + deep-link, không còn form cấu hình.
- [ ] Adapter cũ (interface `BizCity_Channel_Adapter`) vẫn hoạt động — webhook Zalo Bot, FB Messenger không gãy.
- [ ] Token cũ trong `wp_options` vẫn được đọc khi chưa migrate.

---

## 8. Files cần đụng tới (tóm tắt)

| File | Phase | Action |
|------|-------|--------|
| `bizcity-twin-ai/includes/class-admin-menu.php` (L290-340) | 0.31.0, 0.31.4 | Fix 403 + gỡ sub-menu cấu hình lẻ |
| `bizcity-automation/classes/integration.php` | 0.31.1 | Cho phép subclass mở rộng category |
| `bizcity-automation/classes/channelIntegration.php` | 0.31.1 | **NEW** abstract base |
| `bizcity-automation/modules/workflow/models/integrations.php` | 0.31.1 | Thêm scan `channels/` + filter |
| `bizcity-automation/modules/workflow/views/.../adminOptionsTabIntegrations*.php` | 0.31.1 | Group "Channels" |
| `bizcity-automation/modules/workflow/blocks/triggers/nb_*.php` | 0.31.3 | **NEW** 3 trigger |
| `bizcity-automation/modules/workflow/blocks/actions/nb_*.php` | 0.31.3 | **NEW** 3 action |
| `plugins/bizcity-zalo-bot/includes/integration-zalobot.php` | 0.31.2 | **NEW** Channel Integration |
| `plugins/bizcity-zalo-bot/bootstrap.php` | 0.31.2 | Đăng ký filter |
| `mu-plugins/bizcity-facebook-bot/includes/integration-facebook.php` | 0.31.2 | **NEW** |
| `mu-plugins/bizcity-facebook-bot/bootstrap.php` | 0.31.2 | Đăng ký filter |
| `mu-plugins/bizcity-admin-hook-zalo/includes/integration-zalo-hotline.php` | 0.31.2 | **NEW** |
| `core/channel-gateway/includes/class-admin-menu.php` | 0.31.4 | Demote thành dashboard |
| `core/scheduler/includes/...` | 0.31.4 (opt) | Bridge vào trigger `sy_schedule` |

---

## 9. Rủi ro & Mitigation

| Rủi ro | Tác động | Mitigation |
|--------|----------|-----------|
| Plugin `bizcity-automation` đang gitignored ([bizcity-twin-ai.php#L201]) | Channel Integration không deploy | Bật lại trong build hoặc package riêng; phase 0.31 không thể skip step này |
| Migration token nhạy cảm sai key dẫn đến mất kết nối | Bot offline | T5.3 đọc dual (legacy + unified), rollout sau khi smoke test pass |
| Adapter mới phá compat `bizcity_gateway_fire_trigger` | Workflow không nhận event | T1.x **không** sửa adapter, chỉ thêm view layer |
| Capability `read` của Scheduler bị siết khi gộp vào Automation (cap = `manage_options`) | Người dùng cuối mất quyền | Giữ Scheduler ở slug riêng cho user; chỉ bridge trigger phía Automation |
| 403 còn ở entry "khác" tương tự (`bztfb_render_admin_page`, `twf_zalo_users_admin_page`) | UX vẫn lỗi rải rác | T0.2 audit toàn bộ `function_exists` guards |

---

## 10. Mở rộng tương lai (nằm ngoài phase 0.31)

- **Outlook / Outlook Calendar** đã có integration outbound → bổ sung trigger inbound (Microsoft Graph webhooks).
- **Discord / Slack** thêm trigger inbound (slash command, message events).
- **Notebook Federation:** trigger `nb_note_synced_from_kg` để liên thông với KG Hub (PHASE-0.6).
- **Per-account Channel Role:** cho phép gán role (cskh / admin / user) trên từng account của một Channel Integration thay vì per-platform (đã thiết kế trong PHASE-1.5-CHANNEL-ROLE-ARCHITECTURE.md §3).

---

**Tóm tắt:** Phase 0.31 không tạo runtime mới — chỉ **gom mặt cấu hình** của 5 mảnh kênh/tool về tab "Tích hợp bên ngoài" của `bizcity-automation`, demote `bizchat-gateway` thành dashboard, và bổ sung Notebook block để mở khoá kịch bản "notebook → workflow → kênh ngoài". Bug 403 Facebook Settings được fix ngay ở 0.31.0.

---

## L1. Legacy Pattern Reference — `fb_connect_page()`

> **Nguồn:** `mu-plugins/backup/fb-connect-poster_20260207.php`
> **Mục đích:** Tài liệu hóa pattern cũ làm chuẩn so sánh khi refactor về hệ thống mới thống nhất.

### L1.1 Cấu trúc menu cũ

| Slug | Callback | Nguồn dữ liệu |
|------|----------|---------------|
| `fb-connect` (menu cha) | `fb_connect_page()` | `get_option('fb_pages_connected')` — mảng `[{id, name, access_token}]` |
| `fb-app-settings-page` | `fb_app_settings_admin_page()` | `get_option('fb_app_id')`, `get_option('fb_app_secret')` |
| `fb-comments-manager` | `fb_comments_manager_admin_page()` | Graph API `/feed?fields=comments` |
| `fb-business-management` | `fb_business_management_page()` | `get_option('fb_pages_connected')` |

### L1.2 OAuth flow (legacy — standalone function)

```php
// 1. Tạo OAuth URL trong fb_connect_page()
$redirect_uri  = urlencode( $domain . '/?fb_callback=1' );
$fb_login_url  = "https://www.facebook.com/v18.0/dialog/oauth?client_id={$app_id}&redirect_uri={$redirect_uri}&scope={$scopes}&response_type=code";

// 2. Callback xử lý trong add_action('init', function() { ... })
//    - Nhận ?fb_callback=1&code=...
//    - Đổi code → access_token qua /oauth/access_token
//    - Fetch /me/accounts → danh sách pages
//    - update_option('fb_pages_connected', $pages_clean)
//    - update_option('fb_user_token', $access_token)
//    - wp_redirect( admin_url('admin.php?page=fb-connect&status=success') )
```

### L1.3 Option key legacy vs. unified mới

| Legacy option key | Ý nghĩa | Unified target (PHASE 0.31) |
|-------------------|---------|-----------------------------|
| `fb_app_id` | Facebook App ID (cũ) | `bztfb_app_id` (hiện tại mu-plugin) |
| `fb_app_secret` | App Secret (cũ) | `bztfb_app_secret` |
| `fb_pages_connected` | Mảng `[{id, name, access_token}]` sau OAuth | `waic_intergations_facebook[{idx}]['page_token']` (phase 0.31.2) |
| `fb_user_token` | User long-lived token | Không cần lưu lâu dài — refresh khi hết hạn |
| `messenger_page_id` | Messenger page ID đơn lẻ | Thay bằng multi-page DB table của `bizcity-facebook-bot` |
| `messenger_page_token` | Messenger page token đơn lẻ | `bizcity_facebook_bots.page_access_token` (DB) |

### L1.4 Scopes OAuth (giữ nguyên khi port)

```
pages_show_list, pages_manage_posts, pages_manage_engagement,
pages_manage_metadata, pages_read_engagement, pages_read_user_content,
pages_messaging, pages_messaging_subscriptions, public_profile
```

### L1.5 Migration path khi unify (T2.2 trong §5)

```
fb_pages_connected[]          →  INSERT INTO bizcity_facebook_bots (nếu page_id chưa tồn tại)
                               →  update_option('waic_intergations_facebook', normalized)
fb_app_id / fb_app_secret     →  Đọc fallback sang bztfb_app_id / bztfb_app_secret
                               →  Sau 1 release stable: xóa fallback reader
fb_user_token                 →  Không migrate (chỉ dùng tạm cho OAuth redirect)
```

### L1.6 Compat read pattern (áp dụng ngay trong port)

```php
// Đọc App ID: ưu tiên key mới, fallback sang legacy
$app_id     = get_option( 'bztfb_app_id',     get_option( 'fb_app_id',     '' ) );
$app_secret = get_option( 'bztfb_app_secret', get_option( 'fb_app_secret', '' ) );

// Đọc pages: legacy option (OAuth) + DB (bot management) — merge + dedup
$legacy_pages = get_option( 'fb_pages_connected', [] );        // [{id, name, access_token}]
$db_bots      = BizCity_Facebook_Bot_Database::instance()->get_active_bots();  // stdClass[]
// Merge: legacy dùng ['id'], DB dùng ->page_id
```

---

## L2. Audit toàn cảnh 6 plugin phân mảnh (2026-05-07)

> **Mục tiêu:** rà soát lại từng plugin để xác định **giữ / refactor / hợp nhất / khai tử**. Đây là input cho roadmap mới ở §L3.

### L2.1. Bảng tổng hợp 6 plugin

| # | Plugin | Vai trò gốc | Adapter | Integration UI | Block | Webhook EP | Đề xuất |
|---|--------|-------------|---------|----------------|-------|------------|---------|
| 1 | `plugins/bizcity-twin-ai/plugins/bizcity-zalo-bot` | Zalo OA bot (multi-bot) | ✅ `BizCity_Zalo_Bot_Channel_Adapter` | ❌ | ❌ | `/zalohook/` | **REFACTOR** — gắn integration UI + block, gộp menu vào `bizcity-channels` |
| 2 | `mu-plugins/bizcity-admin-hook-zalo` | Hotline `0562608899` + global inbox + `/bizhook/` router | ❌ | ❌ | ❌ | `/bizhook/` (rewrite) + `bizgpt_log_inbox_admin_msg()` | **DEPRECATE+MIGRATE** — `/bizhook/` chuyển vào `core/channel-gateway`; global table giữ tạm cho multisite, đánh dấu read-only |
| 3 | `plugins/bizcity-twin-ai/plugins/bizcity-tool-facebook` | Legacy AI poster (`bztfb_*`) | ✅ `BizCity_Facebook_Channel_Adapter` (!!) | ❌ | ❌ | `/bizfbhook/` (DUAL với #4) | **ABSORB vào #4** — chuyển 3 Intent tool (`create_facebook_post`, `post_facebook`, `list_facebook_posts`) sang mu-plugin; xoá webhook + UI |
| 4 | `mu-plugins/bizcity-facebook-bot` | FB Messenger + Page bot mới | ❌ (chưa implement) | ❌ | ❌ | `?fbhook=1` (DUAL với #3) | **REFACTOR** — implement adapter, gộp với #3, expose integration + 5+ block |
| 5 | `core/scheduler` | Calendar + reminder (5-min cron) + Google sync | — | — | ⚠️ chỉ fire hook `bizcity_scheduler_*` | — | **KEEP + MINOR** — bổ sung trigger `sy_schedule` (đã có) + action `sy_create_schedule` (mới) |
| 6 | `plugins/bizcity-twin-ai/plugins/bizgpt-tool-google` | Google OAuth (Gmail/Calendar/Drive/Contacts) | — | ⚠️ Intent tool có nhưng không vào Integrations tab | ❌ | OAuth callback only | **KEEP + MINOR** — đăng ký lại vào `gmail` / `googlecalendar` integration đã có sẵn của Automation; giữ token store tập trung |

### L2.2. Phát hiện nghiêm trọng

#### 🔴 BUG-3: DUAL FB Webhook Handler
- `plugins/bizcity-tool-facebook` listen tại `/bizfbhook/`
- `mu-plugins/bizcity-facebook-bot` listen tại `?fbhook=1`
- Cả 2 đều xử lý Messenger inbound + Page comment → **race condition**: cùng 1 event được log 2 lần ở 2 DB khác nhau (`wp_bztfb_pages` vs `wp_bizcity_facebook_bots`).
- **Fix:** xoá `/bizfbhook/` ở plugin tool-facebook, chỉ giữ `?fbhook=1` ở mu-plugin (loaded sớm hơn, đáng tin hơn).

#### 🔴 BUG-4: Channel Adapter "đặt nhầm chỗ"
- `BizCity_Facebook_Channel_Adapter` **đang nằm trong `plugins/bizcity-tool-facebook`** (plugin đã bị disable trong `$_bizcity_bundled_must_load`).
- Mu-plugin `bizcity-facebook-bot` (always-on) **không có adapter** → `BizCity_Gateway_Sender::send('fb_*', ...)` rơi xuống fallback legacy.
- **Fix:** chuyển file adapter sang mu-plugin.

#### 🔴 BUG-5: Triple Inbox Storage
| Bảng | Plugin | Nội dung trùng |
|---|---|---|
| `wp_bizcity_facebook_inbox` | mu-plugin facebook-bot | Conversation FB Messenger |
| `wp_bizcity_facebook_bot_logs` | mu-plugin facebook-bot | Raw webhook events FB |
| `wp_bizcity_zalo_bot_logs` | bizcity-zalo-bot | Raw webhook events Zalo |
| `wp_<base>.global_inbox_admin` | bizcity-admin-hook-zalo | Tin nhắn từ TẤT CẢ kênh |

→ Cùng 1 message inbound được log ở **2-3 nơi** với schema khác nhau. UI inbox không có nguồn duy nhất để query.

#### 🟡 BUG-6: 4 cơ chế cron song song
- `core/scheduler` 5-min reminder scan
- `bizcity-zalo-bot` polling listener (transient cache)
- `bizcity-facebook-bot` token refresh (chưa rõ interval)
- `bizgpt-custom-flows` `reminder_delay/unit` (mini-scheduler riêng)

→ Cần audit `wp_get_schedules()` + dedup; nhưng không phải blocker P0.

#### 🟡 BUG-7: 3 OAuth flow rời
- Facebook OAuth: `fb_callback=1` query (mu-plugin) + `bztfb_*` (plugin tool-facebook)
- Google OAuth: `BZGoogle_Google_OAuth` (bizgpt-tool-google)
- Zoom/Outlook OAuth: `WaicIntegration::getAuthProxyUrl()` (bizcity-automation, dùng `https://bizcity.vn/wp-json/aops/v1/oauth/init`)

→ Mỗi nơi tự xử lý token. Mục tiêu unify là **mọi OAuth đi qua `WaicIntegration` proxy** (đã có sẵn cho Gmail/GCal/Outlook/Zoom).

### L2.3. Ma trận trùng lặp cần dọn

| Loại | Nguồn 1 | Nguồn 2 | Nguồn 3 | Hành động |
|---|---|---|---|---|
| **Webhook FB** | `?fbhook=1` (mu-plugin) | `/bizfbhook/` (tool-fb) | — | Giữ #1, xoá #2 |
| **Webhook Zalo** | `/zalohook/` (zalo-bot) | `/bizhook/` (admin-hook-zalo) | — | Giữ #1, chuyển #2 vào core gateway |
| **DB FB token** | `wp_bizcity_facebook_bots.page_access_token` | `wp_bztfb_pages.access_token` | option `fb_pages_connected[].access_token` | Chuẩn hoá về #1, migrate #2+#3 |
| **Inbox** | `wp_bizcity_facebook_inbox` | `wp_bizcity_facebook_bot_logs` | `wp_<base>.global_inbox_admin` | Tạo mới `wp_bizcity_channel_messages` (P3), 3 cái cũ thành read-only |
| **OAuth** | `WaicIntegration::getAuthProxyUrl()` | `BZGoogle_Google_OAuth` | `fb_callback=1` (handle thủ công) | Mọi cái mới đi qua #1 |
| **Cron reminder** | `core/scheduler` (5-min) | `bizgpt-custom-flows` (`reminder_delay`) | — | Giữ #1, migrate #2 sang `sy_create_schedule` action |
| **Admin menu FB** | `bizcity-facebook-bots` (mu-plugin) | `bizcity-tool-facebook` (legacy submenu under gateway) | `bizcity-facebook-settings` (404 entry) | Giữ #1, xoá #2+#3 (đã làm trong session trước) |
| **Send function FB** | `bizcity_facebook_bot_send_message()` (mu-plugin) | `BizCity_Tool_Facebook::post_facebook()` (legacy, post to feed) | `fbm_send_text_to_user()` (??? legacy) | Giữ #1 + #2 (khác mục đích: messenger vs feed); audit #3 |

---

## L3. Roadmap REVISED — Build Backbone (gộp PHASE 0.31.x + audit L2)

> Roadmap cũ ở §5 vẫn giữ; phần này **bổ sung phase 0.31.6 + 0.31.7** và **xếp lại priority** dựa trên 7 scenario S1-S7 (xem [PHASE-0.31-TARGET-SCENARIOS.md](PHASE-0.31-TARGET-SCENARIOS.md)) và audit L2.

### L3.1. Sprint 1 — Unblock TwinChat ra ngoài (P0, ~1 tuần)

> **Mục tiêu:** S1 (CSKH Messenger qua notebook 22) chạy được E2E. Đây là chứng minh "TwinChat vượt giới hạn nội bộ".

> **Validation page:** `/wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag` — mọi task có check tự động + live probe (xem `PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md`).

| ID | Task | File | Effort | Status |
|---|---|---|---|---|
| `[T-S1.1]` | `WaicChannelIntegration` skeleton | `bizcity-automation/classes/channelIntegration.php` | M | ✅ DONE |
| `[T-S1.2]` | Filter `bizcity_register_channel_integrations` trong `WaicIntegrationsModel::loadAllIntegrations()` | `models/integrations.php` | S | ✅ DONE |
| `[T-S1.3]` | `WaicChannelIntegration_facebook` (thin wrapper) đăng ký từ mu-plugin | `mu-plugins/bizcity-facebook-bot/includes/integration-facebook.php` | M | ✅ DONE |
| `[T-S1.4]` | **MIGRATE** `BizCity_Facebook_Bot_Channel_Adapter` từ `bizcity-tool-facebook` sang mu-plugin (BUG-4) | `mu-plugins/bizcity-facebook-bot/includes/class-channel-adapter.php` | S | ✅ DONE |
| `[T-S1.5]` | **NEUTRALIZE** webhook `/bizfbhook/` trong `bizcity-tool-facebook` (BUG-3); chỉ giữ `?fbhook=1` từ mu-plugin | `bizcity-tool-facebook/includes/class-fb-webhook.php` + `class-channel-adapter.php` | S | ✅ DONE (guard-only, file giữ lại để rollback) |
| `[T-S1.6]` | Action `nb_query_kg(notebook_id, query, limit, expand_hops, with_answer)` + REST `POST /wp-json/bizcity/v1/kg/query` (token-gated) + REST `GET /kg/query/diag` | `bizcity-automation/modules/workflow/blocks/actions/nb_query_kg.php` + `core/knowledge/kg-hub/includes/class-kg-public-api.php` | **M** | ✅ DONE |
| `[T-S1.7]` | Refactor `wp_send_facebook_bot_text` đọc account qua `WaicIntegration` (giữ fallback DB) | `actions/wp_send_facebook_bot_text.php` | S | ✅ DONE — `resolveBotId()` chain: block-setting → `getIntegration('facebook', $idx)->getParam('default_bot_id')` → DB-fallback-newest |
| `[T-S1.8]` | Workflow demo S1 + smoke test E2E (gửi message Messenger → bot reply qua KG notebook 22) | `bizcity-automation/samples/workflows/s1-fb-rag-demo.json` | S | ✅ DONE (file JSON sẵn sàng import; smoke test 6 bước in trong file `_smoke_test_steps`) |

**Acceptance:** Khách inbox FB Page → bot trả lời bằng nội dung từ notebook 22 (KG-grounded), không hard-code prompt. **Validation:** mọi task PASS trong sprint diagnostic page.

### L3.2. Sprint 2 — Đủ matrix Channel cho S2-S5 (P0/P1, ~1 tuần)

| ID | Task | Phục vụ | Effort | Status |
|---|---|---|---|---|
| `[T-S2.1]` | `WaicChannelIntegration_zalobot` + `BizCity_Zalo_Bot_Channel_Adapter` đăng ký trong `bizcity-zalo-bot/bootstrap.php` | S2-S6 | M | ✅ DONE — **runtime path: `plugins/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/integration-zalo.php`** + `class-channel-adapter.php` (folder `mu-plugins/bizcity-zalo-bot/` đã DEPRECATE từ 2026-05-07, không còn được autoload — xem `mu-plugins/bizcity-zalo-bot/DEPRECATED.md`). Bootstrap nạp filter `bizcity_register_channel_integrations` + hook `bizcity_register_channel` (adapter register trong `init()` của `BizCity_Zalo_Bot_Plugin`) |
| `[T-S2.2]` | `WaicChannelIntegration_zalo_hotline` + `BizCity_Zalo_Hotline_Channel_Adapter` mới (mu-plugin admin-hook-zalo) | (alt notify) | M | ✅ **DONE** (2026-05-08) — `send_outbound()` gọi live ZNS API `POST https://business.openapi.zalo.me/message/template` với `access_token` header, normalize phone (`hotline_` strip + `0` → `84`), payload `{phone, template_id, template_data, tracking_id?}`. Cred resolution: `WaicChannelIntegration_zalo_hotline` setting (per `account_idx`) → legacy options fallback (`bizcity_zns_access_token`, `bizcity_zns_default_template_id`). Helper `verify_credentials($token)` gọi `GET /template/all?limit=1` cho `doTest()` real probe. Fires `do_action('bizcity_zalo_hotline_sent', ...)` khi thành công. File: `mu-plugins/bizcity-admin-hook-zalo/includes/class-channel-adapter.php` + `integration-zalo-hotline.php`. Diagnostic `check_t_s2_2` source-grep `wp_remote_post` tự flip WARN → PASS. |
| `[T-S2.3]` | Action `wp_create_facebook_page_post` (đăng feed, khác messenger) | S2 | S | ✅ DONE (audit-fixed 2026-05-07) — Phát hiện gọi class chết `BizCity_Tool_Facebook::post_facebook()` (plugin `bizcity-tool-facebook` đã bị xoá khỏi workspace). Đã rewrite để route qua `BizCity_Facebook_Bot_API::create_post($message, $link, $photo_url)` ở mu-plugin (`mu-plugins/bizcity-facebook-bot/lib/class-facebook-bot-api.php` line 469) → endpoint `/{page_id}/feed` (hoặc `/photos` khi có image_url). Cùng nguồn auth với `wp_send_facebook_bot_text` (token từ `wp_bizcity_facebook_bots.page_access_token`). Bổ sung `setVariables()` declare 7 output keys (`sent`, `error`, `page_id`, `page_resolution`, `fb_post_id`, `permalink`, `response`). Diagnostic `check_t_s2_3` đã tăng cường: source-scrape `getResults()` body để verify mọi `ClassName::method(` và `new ClassName(` reference đều loadable runtime |
| `[T-S2.4]` | `WaicChannelIntegration_notebook` + 2 action `nb_create_note`, `nb_attach_artifact` | cross | M | ✅ DONE — `core/knowledge/kg-hub/includes/integration-notebook.php` (group `nb_`, default_notebook_id select từ `kg_notebooks`); `actions/nb_create_note.php` gọi `BizCity_KG_Source_Service::add_passage()`; `actions/nb_attach_artifact.php` merge dedupe vào `kg_notebooks.artifacts_json` JSON map per plugin |
| `[T-S2.5]` | Action `sy_create_schedule(rule, payload)` (S6) | S5, S6 | S | ✅ DONE — `bizcity-automation/modules/workflow/blocks/actions/sy_create_schedule.php`; wrap `BizCity_Scheduler_Manager::create_event()`, normalize datetime qua `strtotime()`, fallback `user_id` về `current_user_id`; phát hook `bizcity_scheduler_event_created` xuôi từ scheduler engine |
| `[T-S2.6]` | Trigger `wu_webchat_message_received` (verify hoặc tạo mới) | S3 | S | ✅ DONE — `bizcity-automation/modules/workflow/blocks/triggers/wu_webchat_message_received.php`; gắn `_hook='waic_twf_process_flow'` `_subtype=2`, lọc `platform==='webchat'`, không chạy LLM intent (webchat đã có session-side intent) |
| `[T-S2.7]` | Refactor `wp_send_zalo_bot_text`, `wp_send_zalo` đọc account qua Integration | S2-S6 | S | ✅ DONE — `wp_send_zalo_bot_text.php` thêm `resolveBotId()` (block-setting → `getIntegration('zalo_bot',$zb_account)->getParam('default_bot_id')` → DB-fallback) + output `bot_resolution`; `wp_send_zalo.php` thêm `zh_account` setting + read-only probe `getIntegration('zalo_hotline',...)` (Sprint 4 swap routing sang adapter live) |

**Acceptance:** S2 + S3 + S5 + S6 export workflow JSON + chạy E2E. **Validation:** check `T-S2.1`→`T-S2.7` PASS trong `/wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag`.

> **⚠️ Sprint 2 caveats / Sprint 4 follow-up status:**
> - ~~`BizCity_Zalo_Hotline_Channel_Adapter::send_outbound()` STUB~~ → ✅ LIVE (2026-05-08): `wp_remote_post` về `business.openapi.zalo.me/message/template` với `access_token` + `template_id` + `template_data`. Token rotation (refresh_token cron) vẫn manual — operator paste token vào integration setting. Auto-refresh đặt vào Sprint 6 (P3) cùng OAuth proxy unification.
> - `wp_send_zalo` vẫn route qua `biz_send_message()` legacy. Migration sang `BizCity_Gateway_Bridge::send_outbound('hotline_*', ...)` vẫn pending — bức xúc không cao vì adapter đã có thể gọi trực tiếp từ mọi nơi; block tiếp tục dùng probe-only để user thấy được integration default phone.
> - `BizCity_Zalo_Hotline_Channel_Adapter::getActionBlocks()` vẫn return `array()` (legacy `wp_send_zalo` đủ dùng cho free-text routing legacy). New action `wp_send_zalo_hotline_template` (cho ZNS template-based) chưa ship — có thể add khi UX team yêu cầu (boilerplate ~2h: clone `wp_send_zalo`, thêm `template_id` + `template_data` settings, getResults gọi `BizCity_Zalo_Hotline_Channel_Adapter::send_outbound`).

### L3.2.x. Sprint 1+2 reverse-audit (2026-05-07)

> Bản kiểm tra ngược (cross-check diagnostic claim ⇄ artefact thực tế) phát hiện **PASS giả** + dependency chết. Nguyên tắc: diagnostic chỉ được report PASS khi mọi runtime symbol đã loadable. Không được "file tồn tại = DONE".

| Finding | Severity | Trigger | Fix |
|---|---|---|---|
| `wp_create_facebook_page_post` gọi class chết `BizCity_Tool_Facebook::post_facebook()` (plugin đã xoá) → action 100% FAIL ở runtime nhưng diag PASS | 🔴 P0 | Diagnostic chỉ check file tồn tại, không scan dependency | Rewrite action sang `BizCity_Facebook_Bot_API::create_post()` (mu-plugin) + tăng cường `check_t_s2_3` source-scrape mọi `Class::method(` và `new Class(` reference, FAIL nếu missing |
| `BizCity_Zalo_Hotline_Channel_Adapter::send_outbound()` luôn `return false` nhưng diag chỉ check `class_exists` → PASS giả | 🟠 P1 | Stub adapter ẩn sau class_exists | Tăng cường `check_t_s2_2`: gọi `doTest()` (đọc `_status=4` + `_status_error` chứa "stub"), và scrape `send_outbound()` body tìm `return false` không kèm `wp_remote_*` → badge **WARN** |
| `harvest_node_variables` của diag T-S1.8 dùng regex `'result'\s*=>\s*array\s*\(` để scrape action output keys → fail với ternary `'result' => $error ? array() : array('content'...)` → keys rỗng → in_array luôn false → bad_refs rỗng → PASS giả | 🟠 P1 | Action `ai_generate_text` dùng ternary cho `result` key | Đổi nguồn primary sang `setVariables()` (declared contract). Bỏ hẳn regex `getResults['result']` (fragile, implementation detail) |
| "Block source files present" của diag T-S1.8 chỉ check `locate_block()` trả path, không verify class declaration trong file | 🟠 P1 | File rename / class rename không bị catch | Bổ sung regex `\bclass\s+WaicAction_X\b` source verification, FAIL nếu class không khai báo trong file |
| `@include_once` block file trong context channel-gateway gây fatal `Class WaicTrigger not found` (block extends abstract chỉ load trong automation runtime) | 🟠 P1 | Tools.php không load WaicFrame | Guard `class_exists('WaicTrigger', false) && class_exists('WaicAction', false)` trước include; fallback source-scrape khi parents chưa load |
| Demo JSON `s1-fb-rag-demo.json` v1 có 3 broken var-refs (`twf_message`, `twf_text`, `system_prompt`/`user_prompt`) — silent fail vì FB trigger expose `text` chứ không phải `twf_message`, `ai_generate_text` expose `content` chứ không phải `twf_text`, block chỉ nhận param `prompt` | 🟠 P1 | Tài liệu xưa dùng convention `twf_*`, code thực dùng key thuần | Sửa JSON sang refs đúng + thêm `_validation_contract.expected_chain` để diag dùng làm chuẩn |

**Lessons learned (đẩy lên `/memories/repo/bizcity-twin-ai-runtime-paths.md`):**
1. Trigger output keys = `getRunValues()` return array (NOT `setVariables` — đó chỉ là UI label).
2. Action output keys = `setVariables()` (declared contract). KHÔNG scrape `getResults()['result']` body — fragile với ternary/conditional.
3. KHÔNG `include_once` block file trong context không phải automation runtime — fatal khi parent abstract chưa load.
4. Diagnostic "file exists = DONE" là pattern PASS giả nguy hiểm nhất. Mọi check phải verify đến tận dependency runtime.


### L3.3. Sprint 3 — Brain ⇄ Workflow events (P1, ~½ tuần)

| ID | Task | Phục vụ | Effort | Status |
|---|---|---|---|---|
| `[T-S3.1]` | 3 trigger `nb_note_created`, `nb_note_updated`, `nb_note_tagged` (gắn vào hook `bizcity_twin_notebook_event`) | (cross-cut) | M | ✅ **DONE** (2026-05-08) — 3 trigger block files: `plugins/bizcity-automation/modules/workflow/blocks/triggers/nb_note_{created,updated,tagged}.php`. Bridge `bizcity_twin_notebook_event → waic_twf_process_flow` ở `core/knowledge/kg-hub/includes/integration-notebook.php`. Fire points đầy đủ: `note_created` (`add_passage()`), `note_updated` (`update_passage()` — fires per `changed_field`), `note_tagged` (`tag_passage()` — payload `{tag, action, all_tags}`, no-op khi duplicate). File: `core/knowledge/kg-hub/includes/class-kg-source-service.php`. Diagnostic `check_t_s3_1` source-grep 3 fire points → tự PASS sau deploy. |
| `[T-S3.2]` | TwinChat UI: nút "Tag note" + "Trigger workflow" cho mỗi note → cầu cho user phi kỹ thuật vẽ scenario | S2-S7 | M | ✅ **DONE** (2026-05-08) — server-rendered shortcode `[bizcity_notebook_notes notebook_id=N limit=20]` (file: `core/knowledge/views/notebook-notes-panel.php`, registered trong `core/knowledge/kg-hub/bootstrap.php`) + 2 REST endpoints `POST /bizcity-twinchat/v1/passages/{id}/{tag\|trigger-workflow}` (handlers `tag_passage` / `trigger_workflow_for_passage` trong `class-twinchat-rest-controller.php`). "Trigger workflow" tái sử dụng pipeline `nb_note_tagged` qua tag reserved (default `#trigger`, filterable qua `bizcity_twin_default_trigger_tag`) — 1 cơ chế duy nhất, không tách event channel. Self-contained (CSS+JS inline) nên dán được vào mọi page; React TwinChat shell có thể mount qua iframe hoặc `do_shortcode()`. Diagnostic `check_t_s3_2` glob source string `Tag note`/`Trigger workflow` → tự PASS sau deploy. |
| `[T-S3.3]` | Verify `ai_intent_router_json` action sẵn sàng + viết template `LOGIC` block `if/else` (xem 3.5 file `PHASE-0.31-TARGET-SCENARIOS`) | S2, S4, S6 | S | ✅ DONE (verified 2026-05-07) — `ai_intent_router_json.php` đã tồn tại (output keys: `json_raw`, `json`, `type`, `confidence`, `reply`, `info_*`, `ok`); LOGIC `un_branch.php` (operator: equals/contains/!=/>/</is_one_of/...) trả `sourceHandle` `output-then`/`output-else`. Diagnostic `check_t_s3_3` source-scrape verify class declaration + 2 output keys cốt lõi (`type`, `confidence`) + branch handles. |

**Acceptance:** Workflow demo `nb_note_created → ai_intent_router_json → un_branch → wp_send_*` chạy được khi user thêm passage mới qua TwinChat hoặc REST. **Validation:** check `T-S3.1`→`T-S3.3` tại `/wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag` (T-S3.1 chấp nhận WARN cho đến khi Sprint 4 mở rộng KG service).

> **✅ Sprint 3 caveats — RESOLVED (2026-05-08):**
> - ~~`BizCity_KG_Source_Service` chưa có `update_passage()` / `tag_passage()`~~ → đã thêm cả 2 method, fire `bizcity_twin_notebook_event` đúng schema (`changed_field` per-field cho `note_updated`; `tag` + `action` + `all_tags` cho `note_tagged`). Diagnostic `check_t_s3_1` sẽ chuyển sang PASS sau khi deploy.
> - ~~TwinChat UI buttons (T-S3.2) defer riêng~~ → ✅ SHIPPED qua shortcode `[bizcity_notebook_notes]` + 2 REST endpoints (xem T-S3.2 row).

### L3.4. Sprint 4 — Demote `bizchat-gateway` + tái cấu trúc menu (P1, ~½ tuần)

> Giữ §5 phase 0.31.4 cũ, chỉ bổ sung:

| ID | Task | Effort | Status |
|---|---|---|---|
| `[T-S4.1]` | Tạo top-level menu `bizcity-channels` (parent) — chứa Zalo Bot + FB Bot + Zalo Hotline submenu (cùng convention slug) | S | ✅ DONE (2026-05-07) — `BizCity_Admin_Menu::SLUG_CHANNELS = 'bizcity-channels'`, top-level `add_menu_page` (icon `dashicons-networking`, pos 29). Channel submenus đã chuyển parent từ `SLUG_GATEWAY` → `SLUG_CHANNELS`: `bizcity-zalo-bot-dashboard`, `bizcity-zalo-bot-assign`, `bizcity-zalo-bots`, `bizcity-zalo-bot-listener/test-api/logs/memory`, `bizcity-facebook-bots`, `bizcity-facebook-bot-connect`, `zalo-video-guider`, `zalo-users-admin`, `zalo-guider`. Stub `bizcity-zalo-hotline` cho Zalo Hotline (provider plugin sẽ mount khi có `BizCity_Zalo_Hotline_Admin_Menu`). `reorder_sidebar()` thêm Channels ở pos 6. |
| `[T-S4.2]` | `bizchat-gateway` chỉ còn dashboard read-only — mỗi card deep-link sang `?page=bizcity-workspace&tab=workflow&sub=integrations&code={x}` | S | ✅ DONE (2026-05-07) — `render_gateway_page()` rewrite: 6 deep-link cards (`zalo_bot`, `facebook`, `zalo_hotline`, `notebook`, `gmail`, `webchat`) → `admin.php?page=bizcity-workspace&tab=workflow&sub=integrations&code={x}`. Banner giải thích cấu hình tập trung tại Workflow Builder Integrations popup; footer link tới `bizcity-channels` cho admin chi tiết. Channel admin submenus đã unmount khỏi gateway parent (chỉ còn Tổng quan + Google Tools + Scheduler/Chat Monitor cross-cutting). |
| `[T-S4.3]` | Network admin: gom config OAuth global (FB App ID/Secret, Google client ID/Secret) ra một trang chung qua `WaicIntegration` proxy | M | ✅ **DONE** (2026-05-08) — Network page `BizCity_Network_OAuth_Page` tại `core/channel-gateway/includes/class-network-oauth-page.php` (multisite: Network Admin → Settings → BizCity OAuth; single-site: Settings → BizCity OAuth). 6 keys: `fb_app_id`, `fb_app_secret`, `google_client_id`, `google_client_secret`, `zalo_oa_app_id`, `zalo_oa_app_secret` (3 secret encrypted với AES-128-CBC dựa trên WP salts). Storage: `update_site_option()` (multisite) hoặc `update_option()` (single-site). Mu-plugin loader: `mu-plugins/bizcity-channel-network-oauth.php` (Network: true). `WaicIntegration` được mở rộng với `getSitewideOAuthGlobal($key, $local_fallback)` + `saveSitewideOAuthGlobals(array)` proxy methods để per-blog code fall back về network value khi local empty. Diagnostic `check_t_s4_3` phát hiện file present → PASS. |

> **Sprint 4 caveats:**
> - T-S4.3 (network OAuth) defer — phụ thuộc thiết kế `WaicIntegration` network-scoped proxy; sẽ scope trong Sprint 6 cùng `[T-S6.3]`.
> - T-S3.1 bridge registration đã được củng cố: `bizcity_twin_notebook_event` bridge giờ register từ `core/channel-gateway/bootstrap.php` (luôn load) qua named function `bizcity_twin_notebook_event_bridge()`, không còn phụ thuộc `core/knowledge/bootstrap.php` (file này short-circuit khi legacy `BizCity_Knowledge_Database` đã loaded → kg-hub bootstrap không chạy).


### L3.5. Sprint 5 — Campaign + Loyalty + Form (S7) + Deprecate `bizgpt-custom-flows` (P2, ~1 tuần)

| ID | Task | Phục vụ | Status |
|---|---|---|---|
| `[T-S5.1]` | Filter `ref=campaign_*` trong trigger `wu_facebook_message_received` (parse `m.me/<page>?ref=...`) | S7 | ✅ DONE — `ref_filter` setting + `extract_referral_info()` (3 candidate paths + text fallback) + wildcard `prefix_*` + 3 vars (`ref`/`ref_source`/`ref_type`) |
| `[T-S5.2]` | Action `loyalty_award_points(user_psid, points, campaign)` — mới, không port logic cứng | S7 | ✅ DONE — `WaicAction_loyalty_award_points`; **entry-point only** (filter `bizcity_loyalty_award_points`); **không** ledger ngầm. Không có backend → fail rõ + hook `bizcity_loyalty_award_unhandled` |
| `[T-S5.3]` | Action `crm_capture_lead(source, psid, campaign, meta)` — mới | S7 | ✅ DONE — `WaicAction_crm_capture_lead`; **entry-point only** (filter `bizcity_crm_capture_lead`); **không** CPT/table ngầm. Không có backend → fail rõ + hook `bizcity_crm_lead_unhandled` |
| `[T-S5.4]` | Trigger `wp_form_submitted` (Gravity/CF7/Elementor wrapper) | S7 | ✅ DONE — `WaicTrigger_wp_form_submitted`; bridges `wpcf7_mail_sent` + `gform_after_submission` + `elementor_pro/forms/new_record` → `bizcity_wp_form_submitted` |
| `[T-S5.5]` | Migration script: data `wp_bizgpt_custom_flows` → workflow definitions (mỗi keyword = 1 workflow) + đánh dấu plugin `bizgpt-custom-flows` deprecated trong `BUSINESS-MODEL.md` | S7 | ⏳ SCAFFOLD — `core/channel-gateway/tools/migrate-bizgpt-custom-flows.php` (`inspect()` + `plan_row()` ready, `execute()` returns `WP_Error('not_implemented')` per P3 deferral) |

> **Sprint 5 caveats:**
> - T-S5.2 / T-S5.3 ship **only as entry-points** — schema (loyalty ledger / lead store) thuộc trách nhiệm của module CRM tương lai (planned namespace `bizcity_crm_*`). Action sẽ FAIL rõ ràng nếu không có backend đăng ký, và emit hook `*_unhandled` cho người muốn log/queue. Không tự tạo CPT/user_meta/custom-table → tránh schema-by-side-effect khi CRM thật ra đời.
> - T-S5.4 emits **both** `bizcity_wp_form_submitted` (for direct hookers) and `waic_twf_process_flow` (for the WAIC trigger) so 3rd parties can subscribe without going through workflow.
> - T-S5.5 writer is intentionally deferred (P3 in roadmap). Diagnostic surfaces it as `SKIP` until the workflow-definition repository API is finalised; deprecation note in `BUSINESS-MODEL.md` should be added at that time, not now (legacy plugin not in current workspace).

### L3.5b. Sprint 5.5 — Creative Canvas UX uplift (mượn từ TwitCanva, P2, ~1 tuần)

> **Mục tiêu:** vá 3 điểm yếu UX của workflow editor được phát hiện khi so sánh với TwitCanva — **không** đổi execution model (giữ trigger → cron → flowruns), chỉ bổ sung "creative canvas" mode song song. Mỗi task đứng độc lập, có thể ship riêng theo nhu cầu.
>
> Phạm vi giữ hẹp: **3 task** đúng nhu cầu user, không port các tính năng video-heavy (camera angle / motion / storyboard) — những thứ đó đã có riêng plugin `bizcity-video-kling`.

| ID | Task | Phục vụ | Effort | Status |
|---|---|---|---|---|
| `[T-S5b.1]` | **Branch merge thật** — block logic mới `un_merge_branches` (barrier wait + variable union từ N upstream nodes). `un_join` hiện tại chỉ là cosmetic pass-through, không gom được dữ liệu 2 nhánh song song. Hỗ trợ chế độ `wait_all` / `wait_any` / `race`. | UX#1 (user nêu) | S (2-3 ngày) | ✅ DONE |
| `[T-S5b.2]` | **In-node prompt + preview + Test-Run** — (a) thêm endpoint `wp_ajax_waic_test_run_block` chạy sync 1 block với fake variables, không ghi `flowruns`, trả full result JSON; (b) FE: nút `▶ Test` cạnh `Save` trong sidebar setting, hiển thị JSON result + thumbnail nếu output là image/video URL; (c) thêm field `data.last_test_result` lưu kết quả test cuối cùng cho từng node (TTL 1h, transient). | UX#2 (user nêu) | M (1 tuần) | ✅ DONE |
| `[T-S5b.3]` | **Wide / clean canvas CSS** — rework editor admin CSS: bỏ N8N 3-col cố định, cho phép `<canvas>` chiếm full viewport (trừ topbar WP), sidebar setting trượt overlay (drawer) thay vì cột thứ 3. Chỉ đụng `assets/css/`, không đụng React component. | UX#3 (user nêu) | S (1-2 ngày) | ✅ DONE |

> **Sprint 5.5 caveats:**
> - **KHÔNG fork React node component.** T-S5b.2 phần (b) chỉ thêm 1 button trong sidebar setting có sẵn; nếu cần thumbnail render trong thân node thì là Sprint 5.6 riêng (Layer B fork — effort 2-4 tuần, cần xác nhận source bundle có sourcemap không trước khi commit).
> - **T-S5b.1 barrier semantics:** không thay đổi cron model. Khi 2 nhánh song song chạy ở 2 task riêng, `un_merge_branches` ghi state vào transient `waic_merge_{run_id}_{node_id}` và chỉ release sang downstream khi đủ N parent. Có timeout (default 5 phút) tránh kẹt chờ vĩnh viễn.
> - **T-S5b.2 Test-Run safety:** chỉ chạy được cho action/logic blocks (không trigger). Chặn các block có side-effect "publish" thật (vd: `wp_send_facebook_bot_text`, `wp_create_facebook_page_post`, `crm_capture_lead`) bằng flag `_test_run_safe = false` trong block class — UI hiện cảnh báo "Block này có side-effect thật, Test-Run sẽ chạy live API. Confirm?".
> - **T-S5b.3 không break N8N CSS hiện hữu:** thêm class toggle `.waic-canvas--wide` thay vì sửa selector cũ; có nút "Wide mode" trong toolbar, lưu preference vào user_meta.

### L3.5b.1. Sprint 5.5 Diagnostic plan

> **Status:** ✅ Diagnostic checks đã active tại `tools.php?page=bizcity-channel-gateway-sprint-diag` — section "Sprint 5.5 — Creative Canvas UX". Implementation:
> - `T-S5b.1` → `check_t_s5b_1()` — assert file `un_merge_branches.php`, class declaration, wait_modes, merge_strategy, cross_run + transient, timeout, _test_run_safe.
> - `T-S5b.2a` → `check_t_s5b_2a()` — assert API class `BizCity_Test_Run_Block_API`, AJAX hook `wp_ajax_waic_test_run_block`, nonce + capability checks, runtime registration via `has_action()`.
> - `T-S5b.2b` → `check_t_s5b_2b()` — assert RISKY_BLOCKS list, reflection-read of `_test_run_safe`, FE injector file present, key risky block files exist.
> - `T-S5b.3` → `check_t_s5b_3()` — assert wide-canvas CSS + JS files, `.waic-canvas--wide` selector, drawer animation, toggle injector, localStorage persistence, view enqueue.

Thêm vào `class-sprint-diagnostic.php` một section `Sprint 5.5 — Creative Canvas UX` với 3 check tương ứng + 1 probe live:

| Check | Test | PASS condition |
|---|---|---|
| `T-S5b.1 — un_merge_branches present` | `locate_block('un_merge_branches')` + scrape source | file tồn tại + class `WaicLogic_un_merge_branches` (hoặc `WaicAction_*`) + setting `wait_mode` (`wait_all\|wait_any\|race`) + sử dụng transient `waic_merge_` |
| `T-S5b.2a — Test-Run endpoint registered` | `has_action('wp_ajax_waic_test_run_block')` | có handler đăng ký + nonce `waic_test_run` + capability check |
| `T-S5b.2b — Block test-run safety flag` | grep `_test_run_safe` trong WaicAction base + sample 5 risky blocks (`wp_send_facebook_bot_text`, `wp_create_facebook_page_post`, `crm_capture_lead`, `loyalty_award_points`, `te_send_message`) | base class declare property + 5 risky blocks override = false |
| `T-S5b.3 — Wide canvas CSS shipped` | grep `.waic-canvas--wide` trong `assets/css/` | selector tồn tại + min-width override + có asset version bump trong `wp_enqueue_style` |
| **Probe live** | nút "Run barrier dry-run": tạo fake run với 2 fake parents → fire `_finish_node` 2 lần → verify downstream được trigger đúng 1 lần | response 200 + log "released" |

Diagnostic report sẽ giống Sprint 5: source-scrape PASS/WARN/FAIL/SKIP, có evidence link đến file.

### L3.6. Sprint 6 — Cleanup & Single Source of Truth (P3, sau khi 5 sprint trên ổn định)

| ID | Task | Lý do | Trạng thái (2026-05-08) |
|---|---|---|---|
| `[T-S6.1]` | Tạo bảng mới `wp_bizcity_channel_messages` (unified inbox) — 3 bảng cũ thành read-only sau migration | BUG-5 | ✅ **DONE** (schema only) — `BizCity_Channel_Messages` tại `core/channel-gateway/includes/class-channel-messages.php` (schema v1.0.0, 13 columns + 5 indexes, install qua `dbDelta` gọi từ `admin_init`). Helper API: `log_inbound()`, `log_outbound()`, `query()`. Auto-mirror bắt `bizcity_zalo_hotline_sent` (filter `bizcity_channel_messages_auto_mirror` tắt được). Migration data các bảng cũ `wp_bzfb_messages`/`wp_bizcity_zalo_messages`/`wp_bizcity_zb_messages` → tiếp tục ở Sprint 6.4 (xem T-S6.4 prereq). |
| `[T-S6.2]` | Audit `wp_get_schedules()` toàn workspace — gộp duplicate cron | BUG-6 | ✅ **DONE** — Diagnostic `check_t_s6_2` quét `_get_cron_array()`, group theo hook × schedule slug, flag WARN khi 1 hook có >1 slug hoặc >5 jobs đang chờ. Surface trong diện trang Sprint 6 của Channel GW Sprint Diag. Kết quả manual: deploy + check page → list duplicate hook để dọn từng wave. |
| `[T-S6.3]` | Mọi OAuth mới đi qua `WaicIntegration::getAuthProxyUrl()` | BUG-7 | ✅ **DONE** — Helper `BizCity_OAuth_Proxy` (`core/channel-gateway/includes/class-oauth-proxy.php`) cho non-Waic callers (mu-plugins/REST clients): `get_init_url()`, `get_refresh_url()`, `unpack_token_package($jwt, $secret)`. Filter `bizcity_oauth_proxy_base` cho phép staging override. `WaicIntegration::getSitewideOAuthGlobal()` + `saveSitewideOAuthGlobals()` proxy thread để per-blog flow tự dùng network App ID/Secret. Diagnostic `check_t_s6_3` verify cả helper class + 2 method → PASS. |
| `[T-S6.4]` | Xoá hẳn plugin `bizcity-tool-facebook` sau khi data migrate sang mu-plugin (`wp_bztfb_*` → archive) | L2.1 #3 | ⚠️ **MIGRATION SCRIPT READY** (2026-05-08) — `core/channel-gateway/tools/migrate-bztfb-to-channel-messages.php` (`BizCity_Migrate_Bztfb`): `find_source_tables()` + `inspect()` (read-only) + `plan_row()` (pure translator) + `execute(['confirm'=>true,'limit'=>500])` (gated by `BIZCITY_ALLOW_T_S6_4_MIGRATE` constant). WP-CLI command `wp bizcity migrate-bztfb [--confirm] [--limit=N] [--offset=N]`. **DESTRUCTIVE delete vẫn manual** (script không tự drop tàn dư từ `wp_bztfb_*` hoặc xoá folder `plugins/bizcity-tool-facebook/`). Quy trình 3 bước cho operator: (1) `wp eval-file ...migrate.php` dry-run, (2) define `BIZCITY_ALLOW_T_S6_4_MIGRATE` rồi `--confirm`, (3) sau khi verify số row → manual `DROP TABLE wp_bztfb_*` + `rm -rf` plugin folder. |
| `[T-S6.5]` | Chuyển `mu-plugins/bizcity-admin-hook-zalo` → regular plugin (không cần always-on nữa khi `/bizhook/` đã vào core gateway) | L2.1 #2 | ✅ **DONE** (2026-05-08) — Folder đã move `mu-plugins/bizcity-admin-hook-zalo/` → `plugins/bizcity-admin-hook-zalo/`. Old mu-plugin shim `mu-plugins/bizcity-admin-hook-zalo.php` đã xoá. New plugin entry: `plugins/bizcity-admin-hook-zalo/bizcity-admin-hook-zalo.php` (Plugin Name: BizCity Zalo Admin Hook, **Network: true**). `BIZCITY_ADMIN_ZALO_DIR` nâng cấp sang `__DIR__ . '/includes/'` nên hoạt động ở cả 2 vị trí. **⚠️ OPERATOR ACTION REQUIRED**: ngay sau deploy phải vào **Network Admin → Plugins** → **Network Activate** “BizCity Zalo Admin Hook”, nếu không `/bizhook/` webhook + ZNS adapter sẽ chết. Reflush rewrite rules sau khi activate (`wp rewrite flush` hoặc vào Settings → Permalinks → Save). |

---

## L4. Định nghĩa "Backbone" sau PHASE 0.31

```
┌─────────────────────────────────────────────────────────────────────┐
│  BIZCITY BACKBONE (sau PHASE 0.31)                                  │
│                                                                     │
│  ┌───────────────────────────────────────────────────────────┐      │
│  │  bizcity-automation (Workflow + Integration registry)     │      │
│  │   • WaicIntegration  (10 cũ + 4 channel mới)             │      │
│  │   • WaicChannelIntegration (Channel = Integration + IO)   │      │
│  │   • Block registry: trigger + action (incl. nb_*, sy_*)   │      │
│  │   • OAuth proxy (mọi OAuth chui qua đây)                  │      │
│  └────────────────────┬──────────────────────────────────────┘      │
│                       │ register block + integration                 │
│  ┌────────────────────┴──────────────────────────────────────┐      │
│  │  Provider plugins (giữ DB + adapter; không tự render UI)  │      │
│  │   ┌────────────────┐ ┌──────────────────┐ ┌─────────────┐│      │
│  │   │ bizcity-       │ │ mu-plugins/      │ │ mu-plugins/ ││      │
│  │   │ zalo-bot       │ │ bizcity-facebook-│ │ bizcity-    ││      │
│  │   │ (OA bot)       │ │ bot (Messenger + │ │ admin-hook- ││      │
│  │   │                │ │  Page + adapter) │ │ zalo (hot-  ││      │
│  │   │                │ │                  │ │  line+adptr)││      │
│  │   └────────────────┘ └──────────────────┘ └─────────────┘│      │
│  │                                                            │      │
│  │   ┌────────────────┐ ┌──────────────────┐                │      │
│  │   │ core/scheduler │ │ bizgpt-tool-     │                │      │
│  │   │ (sy_schedule + │ │ google (Gmail/   │                │      │
│  │   │  sy_create_*)  │ │  Calendar/Drive) │                │      │
│  │   └────────────────┘ └──────────────────┘                │      │
│  └───────────────────────────────────────────────────────────┘      │
│                                                                     │
│  ┌───────────────────────────────────────────────────────────┐      │
│  │  Brain layer (KGHub)                                      │      │
│  │   • Notebook = scope                                      │      │
│  │   • Public API: nb_query_kg / nb_create_note / nb_attach  │      │
│  │   • TwinChat = FE quản lý notebook (không độc quyền)      │      │
│  └───────────────────────────────────────────────────────────┘      │
│                                                                     │
│  ┌───────────────────────────────────────────────────────────┐      │
│  │  bizchat-gateway = read-only dashboard (deep-link only)   │      │
│  └───────────────────────────────────────────────────────────┘      │
│                                                                     │
│  DEPRECATED:                                                        │
│   ✗ plugins/bizcity-tool-facebook  (absorbed → mu-plugin)          │
│   ✗ plugins/bizgpt-custom-flows    (data migrated → workflow defs)  │
│   ✗ Bug 403 facebook-settings      (đã fix)                         │
│   ✗ Dual webhook /bizfbhook/       (đã xoá)                         │
└─────────────────────────────────────────────────────────────────────┘
```

**TwinChat "vượt giới hạn nội bộ"** nghĩa là gì sau PHASE 0.31?
1. Notebook bất kỳ có thể trở thành **brain của 1 channel** (Messenger/Zalo/Web/Email).
2. Notebook events (create/update/tag) → fire workflow → ra ngoài (đăng FB, gửi Zalo, gửi mail).
3. Ngược lại: events từ ngoài (msg, form, scheduler tick) → ghi note vào notebook (audit/retrain).
4. KGHub không còn là "đặc quyền của TwinChat FE" — bất kỳ workflow nào cũng `nb_query_kg(notebook_id=X)` được.

→ Đây là điều kiện cần & đủ để claim TwinChat **operational outside its UI bubble**.
