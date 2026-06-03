# PHASE 0.35 — CRM Campaigns / Messenger Referral Scenario Builder

> **Status**: 🗺️ planning · **Owner**: Twin AI · CRM module
> **Anchor commit**: PHASE 0.35 M6 (Campaigns + QR + UTM + Loyalty)
> **Parent docs**:
> - [PHASE-0.35-CRM-PARITY-CHATWOOT.md](PHASE-0.35-CRM-PARITY-CHATWOOT.md) — R-PAR-7 (Campaign pipeline qua Gateway), R-PAR-8 (Loyalty bridge)
> - [PHASE-0.35-WAVES.md](PHASE-0.35-WAVES.md#-m6--campaigns--qr--utm--loyalty-unique-bizcity) — M6.W1..W9
> **Reference (read-only legacy)**:
> - [bizgpt-custom-flows](../../../../bizgpt-custom-flows/) — `wp_bizgpt_custom_flows` + `admin.php?page=bizgpt_flows`
> - hàm `twf_encrypt_chat_id($flow_id)` trong waic_twf — encoder hiện tại của ref code

---

## 0. Vì sao tách doc này

WAVES.md §M6 đã liệt kê high-level (DB / repo / REST / FE skeleton). Doc này **đào sâu Campaign-as-Scenario**: mỗi campaign **đồng thời là 1 kịch bản chatbot** với Messenger referral link riêng — port nguyên UX từ `admin.php?page=bizgpt_flows` vào CRM tab "Campaigns" của twin-crm React app.

**Mục tiêu cuối phase**:
1. Admin mở CRM → Productivity → **Campaigns** → bấm "+ New campaign" → wizard 1 form duy nhất hỏi: Tình huống / Loại phản hồi / Shortcode hoặc Template / Reminder / Attributes / Loyalty points / Notebook ground.
2. Sau khi save → row hiện trong list kèm **m.me link `?ref=camp_<encoded>`** + nút Copy/Mở + ảnh QR 120×120.
3. Khách scan QR / click link → FB webhook gửi `referral.ref` về → adapter decode → resolve campaign → fire kịch bản:
   - Nếu `action_type=run_shortcode` → render shortcode + push outbound.
   - Nếu `action_type=send_message` → call `nb_query_kg` (nếu có notebook) hoặc plain LLM → render qua `BizCity_CRM_Template_Renderer`.
   - Nếu có `reminder_delay > 0` → schedule single-event nhắc lại nếu khách không reply.
   - Nếu có `loyalty_points_award > 0` → `BizCity_CRM_Loyalty_Bridge::award()` (R-PAR-8).
4. Importer 1-click: kéo toàn bộ row `wp_bizgpt_custom_flows` cũ thành CRM campaigns mới (giữ nguyên ref encoding để link cũ vẫn dùng được).

---

## 1. RULE bắt buộc (R-CMP-1..6)

> Bổ sung cho R-PAR-7 / R-PAR-8 (parent doc), KHÔNG ghi đè.

### R-CMP-1 — Campaign IS Scenario (1-1)
- Mỗi row `bizcity_crm_campaigns` **luôn chứa đủ định nghĩa kịch bản** (action_type, shortcode/template, attrs JSON, reminder, prompt). KHÔNG tách bảng `bizcity_crm_campaign_scenarios` — vi phạm R-PAR-1 (snapshot-only).
- Một campaign chỉ có **1 kịch bản primary**. Nếu cần A/B → tạo 2 campaigns code khác nhau, gắn cùng UTM root.

### R-CMP-2 — Ref encoder phải reuse `twf_encrypt_chat_id`
- Encoder/decoder dùng chung với waic_twf để link cũ (sinh từ bizgpt_flows) **vẫn decode được** sang campaign mới sau khi import.
- Format ref: `camp_<base62_id>` (12 ký tự max — FB Messenger ref limit 2048 nhưng giữ short cho QR).
- Decoder trả về `campaign_id` int. KHÔNG được lưu plain ID trong ref (privacy + tránh spider).

### R-CMP-3 — Kịch bản phải pipe qua Automation Engine
- Khi adapter FB nhận `referral.ref` → emit `crm_campaign_visit_recorded` (R-PAR-7 mode 1) **với** `payload.campaign_id` + `payload.scenario_action_type`.
- Automation Engine (M2) detect event này → load campaign → dispatch action qua `Action_Registry`. KHÔNG để FB adapter trực tiếp gọi `Gateway_Sender` — phải đi qua Engine để bám causal chain `parent_event_uuid`.

### R-CMP-4 — Reminder dùng cron Scheduler chuẩn
- Reminder scheduling → `wp_schedule_single_event(time() + $delay_seconds, 'bizcity_crm_campaign_reminder_tick', [$conversation_id, $campaign_id])`.
- Reaper class kiểm tra: nếu khách đã reply (last_inbound > visit_at) → skip. Nếu không → render reminder_text + push outbound + ghi `crm_campaign_reminder_sent`.
- KHÔNG dùng cron 5-phút quét toàn bộ (cách cũ của bizgpt_flows) — sẽ scale kém khi >10K campaigns.

### R-CMP-5 — Attributes là JSON snapshot, không bảng riêng
- Cột `scenario_attrs_json` trong `bizcity_crm_campaigns` lưu mảng `[{key, prompt}]`. Render qua Template Renderer khi cần hỏi lại khách. KHÔNG tạo `bizcity_crm_campaign_attributes` (R-PAR-1).

### R-CMP-6 — QR sinh server-side, không gọi 3rd party
- Hiện bizgpt_flows dùng `api.qrserver.com` → leak data + offline fail. CRM bắt buộc dùng `BizCity_CRM_QR_Generator` (M6.W2) bundle local (`endroid/qr-code` hoặc `phpqrcode` fallback).
- REST endpoint `GET /campaigns/{id}/qr.{png|svg}` cache 1 giờ qua transient.

---

## 2. Schema delta — `bizcity_crm_campaigns`

Bổ sung vào M6.W1.1 (đã có table) — **CỘT MỚI cho scenario builder**:

| Cột | Kiểu | Default | Mục đích |
|---|---|---|---|
| `scenario_action_type` | VARCHAR(20) | `'send_message'` | enum `run_shortcode` · `send_message` · `kg_grounded_reply` · `delay_only` |
| `scenario_shortcode` | TEXT | NULL | dùng khi action_type=run_shortcode (vd `[tim_san_pham keyword="{params.keyword}"]`) |
| `scenario_template` | TEXT | NULL | dùng khi action_type=send_message (Template Renderer string) |
| `scenario_attrs_json` | LONGTEXT | NULL | `[{"key":"phone","prompt":"Bạn cho mình SĐT nhé?"},…]` |
| `scenario_prompt` | TEXT | NULL | system prompt cho LLM (auto-gen khi save nếu blank, port `bizgpt_generate_prompt_from_shortcode`) |
| `reminder_delay` | INT | 0 | số đơn vị (0 = tắt reminder) |
| `reminder_unit` | VARCHAR(10) | `'minutes'` | enum `minutes`·`hours`·`days` |
| `reminder_text` | TEXT | NULL | nội dung tin nhắc lại |
| `reminder_only` | TINYINT(1) | 0 | nếu 1 → KHÔNG trả lời ngay, chỉ gửi sau delay |
| `imported_from_bizgpt_flow_id` | INT | NULL | trace nguồn (M6.W6 importer) |

**Migration script**: extend `BizCity_CRM_DB_Installer::migrate_v0_35()` — idempotent `add_col_if_missing()` (M1.W1.1.2 helper).

> 3 cột bind đã có sẵn từ M6.W9: `welcome_template_id`, `bound_character_id`, `bound_notebook_id` → không tạo lại.

---

## 3. UI map — Campaigns tab (twin-crm React)

Layout 2-pane mirror `bizgpt_flows`:

```
┌─────────────────────────────── Campaigns ───────────────────────────────────┐
│ [Search…] [All status ▾] [⟳]                              [+ New campaign]  │
├──────────────────────────────────────┬──────────────────────────────────────┤
│ LEFT — Form Sheet (40%)              │ RIGHT — List + Hint (60%)            │
│  · Name *                            │  · Hint card (Tip box)               │
│  · Code (auto-slug)                  │  · DataTable:                        │
│  · Status (draft/active/paused)      │     ID · Name · Trigger · Action ·   │
│  · Tình huống / Trigger keyword      │     Updated · Link kích hoạt · ⋯     │
│  · ── Scenario block ──              │       └─ ô link: <input readonly>    │
│    Loại phản hồi (action_type)       │          [Copy] [Open] + QR 120×120  │
│    Shortcode / Template              │                                      │
│    [+ Add attribute] (key + prompt)  │                                      │
│  · ── Reminder block ──              │                                      │
│    Delay + Unit + Text + ☐ delay-only│                                      │
│  · ── AI binding ──                  │                                      │
│    Notebook · Character · Template   │                                      │
│  · ── Loyalty ──                     │                                      │
│    Points award (M6.W5)              │                                      │
│  · Prompt textarea (auto-gen, edit)  │                                      │
│  [Save] [Cancel]                     │                                      │
└──────────────────────────────────────┴──────────────────────────────────────┘
```

**Files** (extend M6.W8):

| File | Rôle | Notes |
|---|---|---|
| `frontend/src/routes/campaigns/CampaignList.jsx` | Wrapper: header bar + grid layout 40/60 | mở Sheet form khi click row hoặc nút "+ New" |
| `frontend/src/routes/campaigns/CampaignForm.jsx` | Sheet form (right-panel inline khi rộng, slide khi narrow) | sections: Basic / Scenario / Reminder / AI / Loyalty |
| `frontend/src/routes/campaigns/CampaignRowActions.jsx` | LinkBox component (`<input readonly>` + Copy + Open + `<img>` QR) | tái sử dụng được trong Detail page |
| `frontend/src/routes/campaigns/AttributeRows.jsx` | Repeater rows for `scenario_attrs_json` | y hệt UX `attribute-row` của bizgpt_flows |
| `frontend/src/redux/api/campaignsApi.js` | RTK slice — list/get/create/update/delete + `getStats(id)` + `getQrUrl(id)` | tagTypes `['Campaign','CampaignStats']` |

---

## 4. M-CRM.M14 → MCRM-CMP-Scenario waves

Bổ sung vào WAVES.md §M6 (sau M6.W9):

### M6.W10 — Schema scenario columns + repository hydrate

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.10.1 | `class-db-installer.php` | `migrate_v0_35()` — add 10 cột bảng 2 (idempotent) | `SHOW COLUMNS` thấy đủ |
| T-P0.35.6.10.2 | `campaigns/class-campaign-repository.php` | hydrate scenario_* + sanitize JSON attrs (max 20 entries) | round-trip get/update giữ nguyên |
| T-P0.35.6.10.3 | helper `BizCity_CRM_Campaign_Ref_Codec::encode($id)` / `decode($ref)` reuse `twf_encrypt_chat_id` nếu có, fallback HMAC-SHA1 truncated | encode→decode trả nguyên id |

### M6.W11 — REST scenario fields + ref endpoint

| Task | Endpoint | Probe |
|---|---|---|
| T-P0.35.6.11.1 | `POST/PUT /campaigns` accept 10 trường mới (validate enum + JSON shape) | bad payload → 400 |
| T-P0.35.6.11.2 | `GET /campaigns/{id}/messenger-link?page_id=` → `{m_me_url, ref, qr_url}` | shape assertion |
| T-P0.35.6.11.3 | `POST /campaigns/{id}/preview-prompt` → trả prompt auto-gen từ shortcode (port `bizgpt_generate_prompt_from_shortcode`) | input `[tim_bai_viet topic="x"]` → prompt non-empty |

### M6.W12 — FB Adapter referral parser

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.12.1 | `core/channel-gateway/adapters/class-fb-messenger-adapter.php` | parse `messaging[].referral.ref` (postback hoặc `m.me`) → `Ref_Codec::decode` → resolve `campaign_id` | mock webhook → event_uuid emitted |
| T-P0.35.6.12.2 | emit `crm_campaign_visit_recorded` payload `{campaign_id, scenario_action_type, channel_inbox_id, contact_id, parent_event_uuid:null}` | event_stream row exists |
| T-P0.35.6.12.3 | dedupe: cùng `client_id + campaign_id` trong 60s → skip 2nd emit | spam test ok |

### M6.W13 — Scenario Dispatcher action

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.13.1 | `actions/class-action-dispatch-campaign-scenario.php` | đăng ký action `dispatch_campaign_scenario` trong Action_Registry; switch theo `scenario_action_type` | dry-run mỗi enum nhánh |
| T-P0.35.6.13.2 | nhánh `run_shortcode` → `do_shortcode` (whitelist 4 SC bizgpt) → push outbound | output non-empty |
| T-P0.35.6.13.3 | nhánh `send_message` → Template Renderer + (nếu `bound_notebook_id`) → `nb_query_kg` chèn `{{kg.answer:notebook=X}}` | reply chứa kg snippet |
| T-P0.35.6.13.4 | nhánh `kg_grounded_reply` → call `Action_Send_KG_Reply` (M2.W4.4.3) với `notebook_id=bound_notebook_id` + `character_id=bound_character_id` | end-to-end probe |
| T-P0.35.6.13.5 | seed rule mặc định: WHEN `crm_campaign_visit_recorded` THEN `dispatch_campaign_scenario` (tạo 1 lần khi installer chạy, dùng `imported_from_default=1` flag để không seed lại) | rule row exists sau install |
| T-P0.35.6.13.6 | nếu `reminder_delay > 0` → `wp_schedule_single_event(..., 'bizcity_crm_campaign_reminder_tick', [conv_id, campaign_id, ref_event_uuid])` | scheduled |
| T-P0.35.6.13.7 | nếu `reminder_only=1` → KHÔNG dispatch ngay, chỉ schedule | adapter không thấy outbound msg ngay |

### M6.W14 — Reminder Reaper

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.14.1 | `campaigns/class-campaign-reminder-reaper.php` listen hook `bizcity_crm_campaign_reminder_tick` | `has_action` true |
| T-P0.35.6.14.2 | Reaper kiểm tra `conversations.last_inbound_at > visit_at` → skip | unit test 2 case |
| T-P0.35.6.14.3 | Render `reminder_text` qua Template Renderer + push outbound + emit `crm_campaign_reminder_sent` (parent = visit event) | event chain JOIN |

### M6.W15 — FE Scenario Form (extend M6.W8)

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.6.15.1 | extend `CampaignForm.jsx` — thêm sections Scenario / Reminder / Attributes (collapsible) | screenshot 5 sections |
| T-P0.35.6.15.2 | `AttributeRows.jsx` — repeater + drag handle + `+ Add` button (mirror bizgpt_flows JS) | add/remove rows ok |
| T-P0.35.6.15.3 | "Auto-fill prompt" button → call `POST /campaigns/{id}/preview-prompt` → fill textarea | prompt textarea populated |
| T-P0.35.6.15.4 | Form validation — nếu `action_type=run_shortcode` thì `scenario_shortcode` required; `=send_message` thì `scenario_template` required | error message |
| T-P0.35.6.15.5 | Live preview m.me link bên dưới Code field — debounce 500ms, call `messenger-link` endpoint khi đủ data | link update khi đổi code |

### M6.W16 — FE List + Link box

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.6.16.1 | extend `CampaignList.jsx` — column "Link kích hoạt" với `<LinkBox>` component | row render link + QR |
| T-P0.35.6.16.2 | `LinkBox.jsx` — `<input readonly onClick=select>` + Copy button (clipboard API + toast "Đã copy!") + Open `<a target=_parent>` (vì FE chạy trong twin iframe — xem M-CRM.M14 fix iframe) + `<img src={qr_url}>` 120×120 | clipboard contains link |
| T-P0.35.6.16.3 | Page selector dropdown — pick `messenger_page_id` từ `bizcity_crm_inboxes WHERE channel_type='facebook'` để build link | dropdown populated |

### M6.W17 — Importer (extend M6.W6)

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.17.1 | `campaigns/class-flow-importer.php::preview_scenario_aware()` — read `wp_bizgpt_custom_flows` ROW → map sang campaign payload đầy đủ (10 cột scenario_*) | preview returns N rows với scenario_action_type detected |
| T-P0.35.6.17.2 | `import_one($flow_id)` — INSERT campaign + giữ `imported_from_bizgpt_flow_id` + reuse cùng ref encoding (link cũ vẫn hoạt động) | encode(new_campaign.id) khớp `twf_encrypt_chat_id($flow_id)` nếu reuse codec |
| T-P0.35.6.17.3 | Import wizard FE `/campaigns/import` — table preview + checkbox + "Import selected" button | bulk import ok |
| T-P0.35.6.17.4 | Idempotent — re-import flow_id đã import → UPDATE thay vì INSERT (theo `imported_from_bizgpt_flow_id` unique index) | call 2 lần → 1 row |

---

## 5. Mapping bizgpt_flows → CRM campaign

| bizgpt-custom-flows | CRM campaign | Ghi chú |
|---|---|---|
| `id` (INT) | `imported_from_bizgpt_flow_id` (snapshot) | giữ trace |
| `message` | `name` + `trigger_keyword` | name = original message; trigger_keyword = lowercase no-accent |
| `message_khong_dau` | (drop) | tính lại runtime nếu cần — không dup |
| `shortcode` | `scenario_shortcode` | giữ nguyên syntax |
| `action_type` | `scenario_action_type` | enum mapping 1-1 (`run_shortcode`/`send_message`) |
| `action_config` (JSON `{attributes:[…]}`) | `scenario_attrs_json` | flatten ra mảng `[{key, prompt}]` |
| `prompt` | `scenario_prompt` | giữ nguyên — KHÔNG auto-regen khi import |
| `reminder_delay` / `reminder_unit` / `reminder_text` / `delay_only` | `reminder_*` / `reminder_only` | rename `delay_only`→`reminder_only` để bớt mơ hồ |
| `output_json` | (drop) | dead column trong source |
| `updated_at` | `updated_at` | giữ |

**Ref code preservation**: `Campaign_Ref_Codec::encode($new_campaign_id)` → kết quả phải khớp `twf_encrypt_chat_id($old_flow_id)` để link/QR cũ in trên brochure vẫn dẫn đúng campaign sau import. Cách:
- Codec dùng cùng salt + algo. Sau import, lưu `legacy_ref` (cột phụ optional) cho `Ref_Codec::decode` thử cả hai và so sánh.
- HOẶC: snapshot mapping `legacy_flow_id → new_campaign_id` vào `wp_options['bizcity_crm_legacy_ref_map']` (small JSON) — decoder thử map trước khi decode.

---

## 6. Diagnostic table (extend WAVES §0.2)

| task_id | class_name | disk_path | runtime_check | live_probe |
|---|---|---|---|---|
| T-P0.35.6.10.1 | `BizCity_CRM_DB_Installer` | `class-db-installer.php` | 10 cột scenario_* tồn tại | dry-run migration JSON |
| T-P0.35.6.10.3 | `BizCity_CRM_Campaign_Ref_Codec` | `campaigns/class-campaign-ref-codec.php` | encode(decode(x))===x | encode 5 ids |
| T-P0.35.6.12.1 | `BizCity_FB_Messenger_Adapter::parse_referral` | adapter | hook `gateway.facebook.parse_message` priority đúng | mock webhook → emit event |
| T-P0.35.6.13.1 | `BizCity_CRM_Action_Dispatch_Campaign_Scenario` | actions | code `dispatch_campaign_scenario` in registry | dry-run 4 nhánh |
| T-P0.35.6.13.5 | seeded automation rule | `automation_rules` row `imported_from_default=1` | row exists | "Run dispatcher with mock visit" → reply produced |
| T-P0.35.6.14.1 | `BizCity_CRM_Campaign_Reminder_Reaper` | campaigns | `has_action('bizcity_crm_campaign_reminder_tick')` | force tick 1 conv → outbound + event |
| T-P0.35.6.15.x | `<CampaignForm>` 5 sections | bundle | `window.crmRoutes['/campaigns']` | render snapshot |
| T-P0.35.6.16.2 | `<LinkBox>` | bundle | clipboard write succeeds | E2E copy → paste khớp |
| T-P0.35.6.17.1 | `BizCity_CRM_Flow_Importer` | campaigns | `wp_bizgpt_custom_flows` reachable | preview returns N rows |

---

## 7. End-to-end smoke test (đóng phase)

1. Admin tạo campaign mới trong CRM → form save OK → list hiện row + m.me link + QR.
2. Click "Open" → tab mở Messenger với prefilled ref.
3. Khách thật nhắn vào page (ref được FB gửi kèm) → webhook log `crm_campaign_visit_recorded` trong event_stream.
4. Trong < 5s, AI auto-reply (auto/hybrid) appears trong CRM Inbox với `responder_kind=auto` + `parent_event_uuid` trỏ đúng visit event.
5. Nếu campaign có reminder 5 phút và khách không reply → đúng 5' sau, reminder_text gửi qua Messenger + bubble mới có `parent_event_uuid` chain visit→reply→reminder.
6. Nếu có loyalty points award → `wp_user_points` ledger row mới + `crm_points_awarded` event.
7. Vào `/campaigns/import` → preview list flows cũ → tick 3 → Import → 3 campaigns mới hiện trong list, link cũ in trên QR cũ vẫn decode đúng campaign mới.
8. Diagnostic page: tất cả 9 row M6.W10..W17 status `pass`.

---

## 8. Out-of-scope (defer phase 0.36+)

- A/B test split (2 ref → 1 contact group) — cần stats engine riêng.
- Cross-channel referral (Zalo OA `oa_link?param=`, Tiktok bio link) — chỉ FB Messenger trong phase này.
- Visual flow editor (multi-step scenario kiểu "if reply contains X then send Y") — đã có waic_twf, sẽ bind vào campaign sau.
- Dashboard funnel chart 4 stages — đã spec ở M6.W7, không lặp ở doc này.

---

## 9. Marketing Asset Studio — biến Campaign thành bộ kit in/post sẵn

> **Insight**: chỉ phát QR thô là **chưa đủ** với marketer. Họ cần: ảnh voucher có mã, name card có QR, post Facebook/Zalo có CTA, leaflet A6 in offline, story 9:16 cho IG/TikTok. Phải có **1 click → ra full bộ asset** với branding + QR ref tự động nhúng.

### 9.1 Asset templates (built-in 6 preset)

| Template | Kích thước (px) | Use case | Layout |
|---|---|---|---|
| `voucher_landscape` | 1200×628 | Facebook post / Zalo share | logo trái · headline lớn · QR góc phải · code text dưới QR |
| `voucher_square` | 1080×1080 | Instagram feed · Zalo OA broadcast | hero ảnh trên · QR + headline dưới |
| `story_vertical` | 1080×1920 | IG/FB/TikTok story | full-bleed bg · QR giữa · CTA dưới |
| `name_card` | 1004×638 (85×54mm @300dpi) | In name card 2 mặt | mặt trước info, mặt sau full QR |
| `leaflet_a6` | 1240×1748 (A6 @300dpi) | In tờ rơi phát tay | hero · 3 bullet benefit · QR + m.me text |
| `table_tent_a5` | 1748×2480 (A5 @300dpi) | Standee bàn quán | QR cực lớn · "Quét để nhận voucher" |

Templates lưu dưới `plugins/bizcity-twin-crm/templates/marketing-assets/*.svg` — SVG có **placeholder tag** `{{QR_IMG}}`, `{{HEADLINE}}`, `{{CODE}}`, `{{BRAND_LOGO}}`, `{{CTA_TEXT}}`, `{{BG_IMAGE}}` để renderer thay thế.

### 9.2 Render pipeline

```
SVG template + Campaign data + Brand kit → Renderer → PNG/PDF/JPEG
                                            │
                                            ├─ inline <img> QR (data:image/png;base64) → BizCity_CRM_QR_Generator
                                            ├─ inline <img> brand_logo (từ Site Brand Kit option)
                                            ├─ replace text placeholders
                                            └─ rasterize (Imagick > GD fallback) hoặc giữ SVG/PDF vector
```

**Class chính**:
- `BizCity_CRM_Asset_Renderer::render($campaign_id, $template_key, $opts): array` → `{mime, bytes, suggested_filename}`
- `BizCity_CRM_Brand_Kit::get()` → `{logo_url, primary_color, secondary_color, font_family, brand_name, hotline}` (lưu `wp_options['bizcity_crm_brand_kit']`)
- `BizCity_CRM_Asset_Cache` → transient 24h theo `(campaign_id, template_key, brand_kit_hash)` — invalid khi campaign update hoặc brand kit đổi

### 9.3 New columns + table

**Bổ sung `bizcity_crm_campaigns`** (extend §2):

| Cột | Kiểu | Mục đích |
|---|---|---|
| `headline` | VARCHAR(120) | tiêu đề lớn in trên asset (vd "Mua 1 tặng 1 tháng 5") |
| `cta_text` | VARCHAR(60) | call-to-action ngắn (vd "Quét QR nhận ngay") |
| `voucher_code` | VARCHAR(40) | mã voucher in lên asset (rỗng = không hiển thị) |
| `hero_media_id` | BIGINT | ảnh nền/sản phẩm (ID media library, đi qua bzdoc nếu cần upload riêng) |
| `asset_kit_meta_json` | LONGTEXT | override per-template (vd voucher_landscape dùng màu khác) |

**Bảng MỚI** `bizcity_crm_campaign_assets` (snapshot — KHÔNG vi phạm R-PAR-1 vì là materialized cache):

| Cột | Kiểu | Notes |
|---|---|---|
| id, campaign_id, template_key | — | unique `(campaign_id, template_key, brand_hash)` |
| mime, file_path | — | path trong uploads (qua `wp_upload_dir`) |
| brand_kit_hash | CHAR(40) | sha1 brand kit khi render |
| size_bytes, width, height | — | metadata |
| created_at, expires_at | — | TTL 7 ngày, auto regen on demand |

### 9.4 REST endpoints

| Endpoint | Trả về |
|---|---|
| `GET /campaigns/{id}/assets/{template_key}.{png\|jpg\|pdf\|svg}` | binary (cache 24h transient + ETag) |
| `GET /campaigns/{id}/assets/manifest` | mảng `[{template_key, urls:{png,pdf,svg}, dimensions, suggested_filename}]` |
| `POST /campaigns/{id}/assets/{template_key}/regenerate` | force flush cache + render lại |
| `GET /campaigns/{id}/assets/zip` | bundle ZIP cả 6 template (PNG + PDF) — cho marketer download 1 click |
| `GET /brand-kit` / `PUT /brand-kit` | quản lý logo/màu/font |

### 9.5 FE — Asset Studio panel

Trong Sheet `CampaignForm.jsx`, thêm tab **Assets** (sau tab Basic/Scenario/Reminder/AI/Loyalty):

```
┌─ Asset Studio ─────────────────────────────────────────────┐
│ Headline:   [Mua 1 tặng 1 tháng 5_______________________]  │
│ CTA:        [Quét QR nhận ngay__________________________]  │
│ Voucher code: [SUMMER25_________________________________]  │
│ Hero image: [ ↑ Upload / Pick from media ]                 │
│ Brand kit:  [Logo · #FF5722 · Inter · "BizCity"]  [Edit]   │
│                                                            │
│ ┌─ Preview grid (2 cols) ───────────────────────────────┐  │
│ │ [voucher_landscape  ] [voucher_square    ]           │  │
│ │ [story_vertical     ] [name_card         ]           │  │
│ │ [leaflet_a6         ] [table_tent_a5     ]           │  │
│ │  hover → ⟳ Regen · ⬇ PNG · ⬇ PDF · 🖨 Print          │  │
│ └─────────────────────────────────────────────────────┘   │
│                                                            │
│ [⬇ Tải bộ kit ZIP]   [🖨 In trực tiếp]   [📋 Copy URLs]   │
└────────────────────────────────────────────────────────────┘
```

**Component files** (extend §3):

| File | Rôle |
|---|---|
| `frontend/src/routes/campaigns/AssetStudioTab.jsx` | tab content trong CampaignForm |
| `frontend/src/routes/campaigns/AssetPreviewCard.jsx` | thẻ preview 1 template + actions |
| `frontend/src/routes/campaigns/BrandKitEditor.jsx` | mini sheet sửa logo/màu/font |
| `frontend/src/routes/campaigns/PrintDialog.jsx` | mở print preview với CSS `@page` size phù hợp |

### 9.6 Print mode (browser direct)

Dùng `window.open(asset_url, 'print').print()` cho PNG, hoặc render SVG inline vào dialog rồi `printElement()` với CSS:

```css
@page { size: A6 portrait; margin: 0; }
@media print { body * { visibility: hidden } .bzc-print-target, .bzc-print-target * { visibility: visible } }
```

### 9.7 New waves (extend §4)

#### M6.W18 — Brand Kit + Asset schema

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.18.1 | `class-db-installer.php` | thêm 5 cột campaign + table `bizcity_crm_campaign_assets` | columns/table exist |
| T-P0.35.6.18.2 | `marketing/class-brand-kit.php` | get/set/hash brand kit | hash deterministic |
| T-P0.35.6.18.3 | REST `/brand-kit` GET/PUT | round-trip ok |
| T-P0.35.6.18.4 | FE `BrandKitEditor.jsx` — color picker + logo upload (qua bzdocApi) | save → toast |

#### M6.W19 — SVG templates + Renderer

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.19.1 | 6 SVG template files trong `templates/marketing-assets/` | placeholder tags valid | render mock returns SVG hợp lệ |
| T-P0.35.6.19.2 | `marketing/class-asset-renderer.php` — replace tags + inline QR/logo base64 | bytes non-empty cho 6 template |
| T-P0.35.6.19.3 | rasterize: try Imagick → fallback `imagecreatefromstring` (GD) → fallback "SVG only" | feature detect probe |
| T-P0.35.6.19.4 | `marketing/class-asset-cache.php` — transient + DB row | 2nd call < 50ms |

#### M6.W20 — REST asset routes + ZIP bundle

| Task | Endpoint | Probe |
|---|---|---|
| T-P0.35.6.20.1 | `GET /campaigns/{id}/assets/{key}.{ext}` content-type đúng + ETag | curl 6 template OK |
| T-P0.35.6.20.2 | `GET /campaigns/{id}/assets/manifest` | shape JSON |
| T-P0.35.6.20.3 | `GET /campaigns/{id}/assets/zip` stream `ZipArchive` | filename `campaign-{code}-kit.zip` mở được |
| T-P0.35.6.20.4 | `POST .../regenerate` flush cache + return manifest mới | 2 lần liên tiếp khác `created_at` |

#### M6.W21 — FE Asset Studio tab

| Task | File | Component | Probe |
|---|---|---|---|
| T-P0.35.6.21.1 | `AssetStudioTab.jsx` integrate vào CampaignForm tabs | tab visible |
| T-P0.35.6.21.2 | `AssetPreviewCard.jsx` — thumbnail + 4 actions (regen/PNG/PDF/print) | actions trigger fetch |
| T-P0.35.6.21.3 | "Tải kit ZIP" button → `<a download>` từ endpoint zip | file downloaded |
| T-P0.35.6.21.4 | `PrintDialog.jsx` — print 1 template với `@page size` phù hợp template | print preview hiển thị đúng size |
| T-P0.35.6.21.5 | "Copy URLs" button — clipboard copy 6 link assets dạng markdown list | clipboard chứa 6 URL |

#### M6.W22 — Brand Kit binding & cache invalidation

| Task | File | Class/Function | Probe |
|---|---|---|---|
| T-P0.35.6.22.1 | listener `bizcity_crm_brand_kit_updated` → flush all asset cache rows | 1 row update → all assets regen on next fetch |
| T-P0.35.6.22.2 | listener `bizcity_crm_campaign_updated` → flush assets của campaign đó | scoped flush |
| T-P0.35.6.22.3 | cron `bizcity_crm_asset_gc` daily → xóa file expired | row count giảm |

### 9.8 Marketing UX additions (mềm hơn — không chỉ tech)

- **Quick start templates**: khi tạo campaign mới, gợi ý chọn 1 trong 5 preset:
  - "🎟 Voucher giảm giá" · "🎁 Quà tặng đăng ký" · "📢 Khai trương" · "🎂 Sinh nhật khách" · "🔔 Nhắc lại đặt lịch"
  - Mỗi preset auto-điền sẵn `headline`, `cta_text`, `scenario_template`, `reminder_text`.
- **Asset preview ngay trong Campaign list**: thumbnail nhỏ 48×48 của `voucher_square` cho mỗi row.
- **One-click "Đăng FB ngay"**: nếu page đã connect → POST `voucher_landscape.png` + `m_me_url` lên feed (đi qua existing FB Graph integration).
- **WhatsApp/Zalo share intent links** sẵn dưới mỗi template: `https://zalo.me/?body={url}`, `https://wa.me/?text={url}`.
- **In hàng loạt name card** (M6.W23 optional): batch chọn N campaign → render trang A4 chứa 8 name card → in trực tiếp.

---

## 10. Updated end-to-end smoke test

(thay §7 sau khi M6.W18..W22 done)

1. Admin tạo campaign "Khai trương" → chọn preset "📢 Khai trương" → các trường auto-điền.
2. Vào tab **Assets** → 6 thumbnail render trong < 3s (lần đầu).
3. Click "🎟 voucher_landscape" → preview lớn → nút Regen / Download PNG / Download PDF / Print → tất cả hoạt động.
4. Bấm "⬇ Tải kit ZIP" → file 2-5MB chứa 6 PNG + 6 PDF + manifest.json.
5. Bấm "📋 Copy URLs" → paste vào notepad ra 6 URL.
6. Edit Brand Kit → đổi màu primary → quay lại Assets → click "⟳ Regen" trên 1 template → màu mới đã apply.
7. (Optional) Bấm "Đăng FB ngay" → bài post xuất hiện trên fanpage với ảnh voucher + caption + m.me link.
8. Khách scan QR trên ảnh in → kịch bản chạy như §7 cũ → loyalty award 100pts.


---

## 11. Class Diagnostics standard (R-DDV compliance)

> **Tuân thủ**: [PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md](PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md) — mọi wave PHẢI có 3 layer probe (class_exists / hook attached / live probe). KHÔNG có PASS giả.

### 11.1 Task ID convention

`T-M6.W{wave}.{step}` — ví dụ `T-M6.W10.1` = wave 10 task 1. Mirror format đang dùng trong `class-sprint-diagnostic.php` (compact 4-field row: `id` / `check` / `status` / `evidence`).

### 11.2 Diagnostic surface — full table cho 13 wave campaign builder

| Task | Class / Hook / Route | Layer | Live probe |
|---|---|---|---|
| **W10 — Schema scenario + ref codec** | | | |
| T-M6.W10.1 | `BizCity_CRM_DB_Installer::migrate_phase_041` | DB | 10 cột `scenario_*` + `reminder_*` + `imported_from_bizgpt_flow_id` `column_exists()` true |
| T-M6.W10.2 | `BizCity_CRM_Campaign_Repository::hydrate` | Runtime | round-trip create→get giữ nguyên `scenario_attrs` array |
| T-M6.W10.3 | `BizCity_CRM_Campaign_Ref_Codec` (encode/decode) | Class | encode(decode(x))===x cho 5 random IDs; collision test 1000 IDs distinct |
| **W11 — REST scenario + messenger-link + preview-prompt** | | | |
| T-M6.W11.1 | `POST /campaigns` validate enum `scenario_action_type` | Route | bad enum → HTTP 400 với error code `bizcity_crm_campaign_invalid` |
| T-M6.W11.2 | `GET /campaigns/{id}/messenger-link` | Route | response shape `{m_me_url, ref, qr_url}`; ref decode về đúng campaign_id |
| T-M6.W11.3 | `POST /campaigns/{id}/preview-prompt` | Route | input `[tim_san_pham keyword="{x}"]` → prompt non-empty + chứa từ `keyword` |
| **W12 — FB referral parser** | | | |
| T-M6.W12.1 | `BizCity_FB_Messenger_Adapter::parse_referral` | Hook | `has_action('gateway.facebook.parse_message')` true |
| T-M6.W12.2 | emit `crm_campaign_visit_recorded` | Event | mock webhook → 1 row event_stream với `payload.campaign_id` set |
| T-M6.W12.3 | dedupe 60s window | Logic | spam 5 visits same client → 1 emit |
| **W13 — Scenario Dispatcher** | | | |
| T-M6.W13.1 | `BizCity_CRM_Action_Dispatch_Campaign_Scenario` registered | Class | `Action_Registry` chứa key `dispatch_campaign_scenario` |
| T-M6.W13.2 | nhánh `run_shortcode` whitelist | Logic | dry-run 4 SC bizgpt → output non-empty |
| T-M6.W13.3 | nhánh `send_message` + KG inject | Logic | reply chứa snippet từ notebook bound |
| T-M6.W13.4 | nhánh `kg_grounded_reply` | Logic | end-to-end probe trả message với citation |
| T-M6.W13.5 | seeded automation rule `imported_from_default=1` | DB | row exists + `triggers_json` chứa event name |
| T-M6.W13.6 | reminder schedule `wp_schedule_single_event` | Cron | `wp_next_scheduled('bizcity_crm_campaign_reminder_tick',[…])` > 0 |
| T-M6.W13.7 | `reminder_only=1` skip immediate dispatch | Logic | adapter không thấy outbound msg ngay; chỉ thấy sau delay |
| **W14 — Reminder Reaper** | | | |
| T-M6.W14.1 | `BizCity_CRM_Campaign_Reminder_Reaper` listener | Hook | `has_action('bizcity_crm_campaign_reminder_tick')` true |
| T-M6.W14.2 | skip-if-replied logic | Logic | 2 unit cases: replied → skip; idle → send |
| T-M6.W14.3 | emit `crm_campaign_reminder_sent` chained | Event | event row `parent_event_uuid` = visit event UUID |
| **W15 — FE Scenario Form** | | | |
| T-M6.W15.1 | `<CampaignForm>` 5 sections collapsible | Bundle | E2E screenshot 5 fieldsets visible |
| T-M6.W15.2 | `<AttributeRows>` repeater add/remove | Bundle | add 3 → remove 1 → state length === 2 |
| T-M6.W15.3 | "Auto-fill prompt" button | Bundle | click → textarea length > 0 |
| T-M6.W15.4 | conditional required validation | Bundle | submit empty `scenario_shortcode` khi `action_type=run_shortcode` → form error |
| T-M6.W15.5 | live preview m.me debounced 500ms | Bundle | type fast → 1 fetch only |
| **W16 — FE List + LinkBox** | | | |
| T-M6.W16.1 | `<LinkBox>` cột "Link kích hoạt" | Bundle | row chứa `<input readonly>` + QR `<img>` |
| T-M6.W16.2 | clipboard copy + toast | Bundle | navigator.clipboard.readText() === link |
| T-M6.W16.3 | page selector dropdown | Bundle | options.length === count(facebook inboxes) |
| **W17 — Importer scenario-aware** | | | |
| T-M6.W17.1 | `BizCity_CRM_Flow_Importer::preview_scenario_aware` | Class | preview returns N rows với `detected_action_type` |
| T-M6.W17.2 | `import_one($flow_id)` reuse codec | Logic | encode(new_id) === legacy `twf_encrypt_chat_id($flow_id)` HOẶC `legacy_ref_map` mapping match |
| T-M6.W17.3 | wizard `/campaigns/import` FE | Bundle | bulk select + import → toast "N imported" |
| T-M6.W17.4 | re-import idempotent | Logic | call 2 lần cùng flow_id → 1 row campaign |
| **W18 — Asset templates** | | | |
| T-M6.W18.1 | 6 SVG templates dưới `templates/marketing-assets/` | Disk | `file_exists()` cả 6 file |
| T-M6.W18.2 | `BizCity_CRM_Asset_Renderer::render` | Class | render `voucher_landscape` PNG bytes > 5KB |
| T-M6.W18.3 | placeholder substitution | Logic | output PNG/SVG chứa `{{HEADLINE}}` đã thay |
| **W19 — Brand Kit + Asset Cache** | | | |
| T-M6.W19.1 | `BizCity_CRM_Brand_Kit::get/set` | Class | round-trip save/load `wp_options['bizcity_crm_brand_kit']` |
| T-M6.W19.2 | `bizcity_crm_campaign_assets` table | DB | `tbl_campaign_assets()` exists + 8 cột |
| T-M6.W19.3 | cache hit vs miss | Logic | render lần 2 < 100ms (transient hit) |
| **W20 — REST asset endpoints** | | | |
| T-M6.W20.1 | `GET /campaigns/{id}/assets/manifest` | Route | returns 6 entries |
| T-M6.W20.2 | `GET /assets/{key}.{png\|pdf\|svg}` ETag | Route | response header `ETag` present |
| T-M6.W20.3 | `GET /assets/zip` bundle | Route | content-type `application/zip` + size > 100KB |
| T-M6.W20.4 | `PUT /brand-kit` permission | Route | non-admin → 403 |
| **W21 — FE Asset Studio tab** | | | |
| T-M6.W21.1 | `<AssetStudioTab>` mount | Bundle | tab visible khi campaign saved |
| T-M6.W21.2 | `<AssetPreviewCard>` 6 thumbnails | Bundle | grid renders 6 cards |
| T-M6.W21.3 | `<PrintDialog>` open | Bundle | window.print() called (mock) |
| **W22 — Cache invalidation** | | | |
| T-M6.W22.1 | listener `bizcity_crm_brand_kit_changed` | Hook | flush all assets transients |
| T-M6.W22.2 | listener `bizcity_crm_campaign_updated` | Hook | flush 1 campaign assets |
| T-M6.W22.3 | cron `bizcity_crm_asset_gc` daily | Cron | `wp_next_scheduled` > 0 |

### 11.3 Done markers (mirror WAVES.md §0.2)

| Marker | Nghĩa |
|---|---|
| ✅ | Disk + Loader + Runtime + Live probe ALL pass |
| 🟡 | Disk + Loader OK, Runtime/probe fail (regression) |
| 🔴 | Disk OK, Loader miss (require_once chưa wire) |
| ⚪ | File chưa tạo |
| 🟣 | Skip (deferred / out-of-scope) |

### 11.4 Live probe surface

Tab admin: `tools.php?page=bizcity-crm-sprint-diag` (dùng chung `BizCity_CRM_Sprint_Diagnostic` đã có). Section **"PHASE 0.35 M6.W10..W22 — Campaign Builder"** thêm vào `compute_tasks_*()` method tương tự M6.W1..W9 hiện tại.

---

## 12. Progress board (manually update khi commit)

> Mirror format `PHASE-0.35-WAVES.md` line 959. Cập nhật cột `Status` + `Date` + `Commit` + `Diag %` mỗi PR. Bug log + Migration health là 2 cột mới riêng cho campaign builder (vì có dependency lên M6.W1 cũ).

| Wave | Title | Status | Date | Commit | Diag % | Bugs | Migration |
|---|---|---|---|---|---|---|---|
| M6.W10 | Schema scenario + Ref Codec | ✅ | 2026-05-13 | uncommitted | 3/3 | — | DB_VERSION 1.13.0 · migrate_phase_041 idempotent |
| M6.W11 | REST scenario + messenger-link + preview-prompt | ✅ | 2026-05-13 | uncommitted | 3/3 | — | reuses can_write cap |
| M6.W12 | FB referral parser | ✅ | 2026-05-13 | uncommitted | 3/3 | bug-001 | resolve_ref tries Ref_Codec first then legacy code |
| M6.W13 | Scenario Dispatcher action | ✅ | 2026-05-13 | uncommitted | 5/5 | bug-001 | 4 branches + queue/drain + seeded default rule (idempotent via DEFAULT_RULE_OPTION) |
| M6.W14 | Reminder Reaper | ✅ | 2026-05-13 | uncommitted | 3/3 | — | hook listener + delay math + skip-if-replied logic verified via reflection probes |
| M6.W15 | FE Scenario Form | ✅ | 2026-05-13 | uncommitted | bundle rebuilt | — | extends `<CampaignForm>` w/ 4-radio action_type + branch fields (template/shortcode+attrs/prompt+notebook) + reminder section (delay/unit/text/only) · uses new `previewCampaignPrompt` mutation hook |
| M6.W16 | FE List + LinkBox | ✅ | 2026-05-13 | uncommitted | bundle rebuilt | — | added `actionBadge()` column in list + dedicated `MessengerLinkBox` panel using `useGetCampaignMessengerLinkQuery` w/ optional page_id picker + ref token surfacing |
| M6.W17 | Importer scenario-aware | ✅ | 2026-05-13 | uncommitted | 4/4 | — | reads `wp_bizgpt_custom_flows` (non-CRM) · re-import idempotent via `imported_from_bizgpt_flow_id` + `idx_imported_flow` |
| M6.W18 | Asset templates (6 SVG) | ✅ | 2026-05-13 | uncommitted | 1/1 | — | 6 SVG files under `templates/marketing-assets/` (voucher_landscape · voucher_square · story_vertical · name_card · leaflet_a6 · table_tent_a5) — placeholder tags `{{BRAND_*}}/{{HEADLINE}}/{{CTA_TEXT}}/{{VOUCHER_CODE}}/{{HOTLINE}}/{{QR_IMG}}` |
| M6.W19 | Brand Kit + Asset Cache | ✅ | 2026-05-13 | uncommitted | 1/1 | — | **Deviation**: chose transient-backed cache (no new DB table) to keep migration footprint zero; `wp_options['bizcity_crm_brand_kit']` + `wp_options['bizcity_crm_asset_cache_index']` (24h TTL). New classes: `BizCity_CRM_Brand_Kit` + `BizCity_CRM_Asset_Cache` + `BizCity_CRM_Asset_Renderer` |
| M6.W20 | REST asset endpoints | ✅ | 2026-05-13 | uncommitted | 2/2 | — | 5 routes: GET/PUT `marketing/brand-kit` · GET `marketing/templates` · GET `campaigns/{id}/assets/manifest` · GET `campaigns/{id}/assets/{key}.{svg\|png\|jpg\|pdf}` (streams binary + ETag + 24h cache header) · POST `campaigns/{id}/assets/{key}/regenerate`. **Deviation**: ZIP bundle endpoint deferred (no consumer in FE yet). Imagick→GD→SVG fallback in renderer. |
| M6.W21 | FE Asset Studio tab | ✅ | 2026-05-13 | uncommitted | bundle rebuilt | — | `AssetStudioPanel.jsx` injected via `<details>` block in `CampaignDetail` (lazy-collapsed). 3 sub-components inline: `BrandKitEditor` (PUT brand-kit), `AssetPreviewCard` (4 format chips · `<object>` for SVG · `<img>` for PNG/JPG · download/regenerate buttons), root with Imagick/GD availability badges. Bundle 666kB JS / 62kB CSS |
| M6.W22 | Cache invalidation | ✅ | 2026-05-13 | uncommitted | 1/1 | — | `BizCity_CRM_Asset_Cache_Invalidator::bootstrap()` registers 3 listeners (`bizcity_crm_event_crm_brand_kit_updated` → flush_all · `..._campaign_updated` / `..._campaign_deleted` → flush_campaign) + daily `bizcity_crm_asset_gc` cron. Convention enforced: prefixed action names per bug-001 |

**Layer legend** (mirror WAVES §0.2): D=Disk, L=Loader, R=Runtime, H=Hook attached, F=Filter registered.

**Diag % format**: `passed/total` của `class-sprint-diagnostic.php` section M6.W{n}.

**Bug log convention**: ghi `[YYYY-MM-DD] short-issue (PR #n fix)` — KHÔNG ghi stack trace ở đây, chỉ link tới GitHub issue.

### Bug log

- **bug-001 · [2026-05-13] Event_Emitter hook prefix mismatch (silent listener never fires)** — `BizCity_CRM_Event_Emitter::emit($type, ...)` chỉ phát `do_action('bizcity_crm_event_'.$type, ...)` (+ generic bus), KHÔNG bao giờ phát raw `$type`. 4 class campaign đã subscribe nhầm vào tên raw → listeners im lặng không fire. **Production impact**: mỗi visit campaign từ 2026-04 tới nay user nhắn FB **không được gắn `contact_id`** → conversion event không emit → Loyalty không cộng điểm + Bridge không attach character/notebook. **Fix**: 5 file — `class-conversion-linker.php`, `class-loyalty-bridge.php`, `class-conversion-bridge.php`, `class-campaign-scenario-dispatcher.php`, + 5 task trong `class-sprint-diagnostic.php` (W3.3, W4.2, W5.2, W9.4, W12.2, W13.2) đều chuyển sang `bizcity_crm_event_<type>` prefix. **Convention**: mọi listener consume Twin Event Stream PHẢI subscribe `bizcity_crm_event_<event_name>`, không bao giờ raw tên. AI_Autoreply + CSAT + Automation_Engine làm đúng từ đầu — grep chúng ra example.

### Risk mitigations (post-W17 hardening, 2026-05-13)

| Risk | Class/file | Fix | Diag |
|---|---|---|---|
| **R1** — `kg_grounded_reply` falls back silently to `send_message` khi `Action_Send_KG_Reply` chưa ship → hard to detect in prod | `class-campaign-scenario-dispatcher.php` `branch_kg_grounded_reply()` | Emit `error_log()` warn + annotate result với `fallback => 'send_message'` + `[kg_action_unavailable]` marker trong `detail` | T-M6.RISK.1 — reflection-invokes branch khi class missing và assert flag/marker xuất hiện |
| **R2** — End-to-end visit→queue→drain→outbound chain mới chỉ verify từng stage qua reflection, chưa chạy thật → không phát hiện regress trong DB layer (bug schema kiểu W14.3) | `class-sprint-diagnostic.php` T-M6.RISK.2 | Tạo campaign live (`Repository::create`) + insert mock conversation → gọi `on_visit_recorded()` rồi `dispatch()` → assert outbound message row exists với template đã render → cleanup | T-M6.RISK.2 |
| **R3** — Reminder reaper relies on WP-Cron, multisite có thể disable cron và silently lose reminders | `class-sprint-diagnostic.php` T-M6.RISK.3 | Probe `wp_schedule_single_event(REMINDER_HOOK)` round-trip + check `DISABLE_WP_CRON`/`ALTERNATE_WP_CRON` constants → WARN nếu cron disabled không có alternate | T-M6.RISK.3 |
| **R4** — Importer-created campaigns relies on seeded W13.5 default rule for runtime dispatch — wiring not obvious từ importer code | `class-flow-importer.php` SCENARIO-AWARE block doc | Added explanation block trên import_one_to_campaign() docstring | doc-only |

---

## 13. Migration Safety Plan

> Phase này extend table cũ (M6.W1 đã chạy ở 2026-04 cho prod). Mọi ALTER PHẢI idempotent + reversible.

### 13.1 Pre-flight check

Trước khi chạy `migrate_phase_041()`:

```php
// Sanity — M6.W1 base table phải tồn tại trước.
$tbl = BizCity_CRM_DB_Installer::tbl_campaigns();
if ( ! BizCity_CRM_DB_Installer::table_exists( $tbl ) ) {
    error_log( '[bizcity-crm] M6.W10 migration skipped — base campaigns table missing. Run install() first.' );
    return array();
}
```

### 13.2 Idempotent ALTER pattern

Reuse helper `column_exists()` + `index_exists()` đã có trong `class-db-installer.php`. Mỗi cột wrap trong:

```php
$add_column( $tbl, 'scenario_action_type', "VARCHAR(20) NOT NULL DEFAULT 'send_message' AFTER bound_notebook_id" );
```

→ Chạy lại N lần đều chỉ thêm 1 lần (SHOW COLUMNS guard). KHÔNG dùng `dbDelta` cho scenario columns vì nó không support `AFTER` clause reliably trên MariaDB 10.4-.

### 13.3 Migration order

1. Bump `BIZCITY_CRM_DB_VERSION` từ `1.12.0` → `1.13.0`.
2. Add `migrate_phase_041()` call cuối `install()` (sau `migrate_phase_040()`).
3. Plugin update → `maybe_upgrade()` detect version mismatch → chạy lại toàn bộ install() → `migrate_phase_041()` add 10 cột.
4. Verify via diagnostic: `T-M6.W10.1` PASS.

### 13.4 Rollback (worst case)

Nếu migration phá data:

```sql
-- Hard rollback (only run on staging — prod data loss)
ALTER TABLE wp_bizcity_crm_campaigns
  DROP COLUMN scenario_action_type,
  DROP COLUMN scenario_shortcode,
  DROP COLUMN scenario_template,
  DROP COLUMN scenario_attrs_json,
  DROP COLUMN scenario_prompt,
  DROP COLUMN reminder_delay,
  DROP COLUMN reminder_unit,
  DROP COLUMN reminder_text,
  DROP COLUMN reminder_only,
  DROP COLUMN imported_from_bizgpt_flow_id;
UPDATE wp_options SET option_value = '1.12.0' WHERE option_name = 'bizcity_crm_db_ver';
```

### 13.5 Detect prior M6.W1 install

```php
// Distinguish "fresh install (need full schema)" vs "upgrade from W1 (need just delta)".
$is_w1_present = BizCity_CRM_DB_Installer::column_exists( $tbl, 'utm_campaign' );
if ( ! $is_w1_present ) {
    // Fresh — let dbDelta in install() create base table first; migrate_phase_041 will be no-op (cols added by dbDelta).
}
```

`dbDelta` (đang chạy trong `install()`) sẽ tạo table với đủ cột nếu lần đầu install. `migrate_phase_041()` chỉ effective trên upgrade path. → Không cần code branching, just **ensure CREATE TABLE statement in `install()` cũng include 10 cột mới** — đảm bảo fresh install không cần chờ migration.

### 13.6 Backfill strategy

Cột mới đều có DEFAULT (`scenario_action_type='send_message'`, `reminder_delay=0`, `reminder_only=0`) → tự động fill cho row cũ. KHÔNG cần backfill job.

Riêng `scenario_prompt`: NULL cho row cũ → khi user mở Form lần đầu sau upgrade, FE call `POST /preview-prompt` để sinh prompt mặc định. KHÔNG eager-backfill (tránh tốn LLM cost cho campaign không bao giờ dùng tới).

### 13.7 Migration health check (CI/CD)

Add to deploy pipeline (sau `wp plugin activate`):

```bash
wp eval 'echo (int) BizCity_CRM_DB_Installer::column_exists( BizCity_CRM_DB_Installer::tbl_campaigns(), "scenario_action_type" );'
# Expected: 1
```

Exit non-zero → block deploy.