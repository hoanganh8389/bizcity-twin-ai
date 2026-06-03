# PHASE 0.3 — CRM CANON · Channel × Campaign × Funnel × Inbox

> **Status:** 📐 CANON — single source of truth cho cụm PHASE 0.31 → 0.35
> **Owner:** Twin AI Architecture
> **Last updated:** 2026-05-24
> **Scope:** Hợp nhất 10 doc rời rạc về Channel Gateway + CRM Inbox + Campaign + Webhook + Scheduler thành 1 dòng chảy duy nhất, đánh giá lại roadmap, chỉ rõ đã có / đang làm / chưa làm.

---

## 0. Vì sao cần CANON này

Trước CANON, tài liệu CRM nằm rải rác ở root plugin (`bizcity-twin-ai/PHASE-0.3*.md`) với 2 vấn đề:

1. **Trục thời gian sai** — PHASE 0.32 (CRM Inbox) được lên kế hoạch trước khi PHASE Campaign/QR scenario có chỗ đứng riêng, dẫn tới phải nhồi campaign vào doc CRM-PARITY (M6) — đảo ngược thứ tự phụ thuộc thực tế ("Campaign sinh phễu → Inbox/Funnel hứng phễu").
2. **Phân tán theo trục module** — Channel Gateway (cấu hình kênh, Page binding, webhook, QR) và CRM (inbox, pipeline, report) bị tách 2 nơi, nhưng thực tế share chung 6 bảng `bizcity_crm_*` + share luồng event `crm_*`.

CANON này:

- **Đảo lại trục thời gian** theo luồng nghiệp vụ (Setup kênh → Tạo phễu → Triển khai engagement asset → Hứng & vận hành CRM).
- **Quy hoạch lại 10 doc** vào folder duy nhất `core/channel-gateway/docs/` (đã move xong, xem [§ 5](#5-doc-relocation)).
- **Đánh giá thẳng** mức độ hoàn thành từng module — chỉ rõ phần BE đã ship, phần FE còn thiếu, phần roadmap chưa khởi động.

---

## 1. Trục thời gian mới (Operating Sequence)

```
┌────────────────────────────────────────────────────────────────────────┐
│  STAGE A — CHANNEL FOUNDATION         (must have, blocks everything)   │
│  • Connect Facebook/Zalo/Web channels (FB Pages, tokens, webhook)      │
│  • Bind Guru × Page (which character speaks on which channel)          │
│  • Webhook inspector, replay, sprint diagnostic                        │
│  Doc anchor: PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md             │
│              PHASE-0.33-WEBHOOK-GURU-UNIFY.md                          │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│  STAGE B — CAMPAIGN / SCENARIO BUILDER  (lead-gen surface)             │
│  • Tạo "kịch bản" = Campaign row: trigger keyword + action + reminder  │
│  • Sinh Messenger ref link + QR (Google Chart API hoặc local SVG)      │
│  • Link tới engagement asset: shortcode, vòng quay may mắn, form page  │
│  • FB Adapter parse `referral.ref` → fire scenario via Automation      │
│  Doc anchor: PHASE-0.35-CRM-CAMPAIGN.md (R-CMP-1..6)                   │
│              §6.W10..W13 của PHASE-0.35-WAVES.md                       │
│  ⚠ Doc cũ đặt mục này trong M6 của PARITY → CANON đẩy lên STAGE B,     │
│    đặt TRƯỚC Inbox vì campaign sinh phễu thì inbox mới có gì để hứng.  │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│  STAGE C — ENGAGEMENT ASSETS  (landing pages khách tương tác)          │
│  • Vòng quay may mắn (lucky wheel)                                     │
│  • Form lead-capture / mini quiz                                       │
│  • Magic-link landing page (đã có template)                            │
│  • Tích/đổi điểm UI (đã có plugin bizcity-crm-tichdiem)                │
│  Doc anchor: chưa có doc riêng — TODO PHASE-0.32-ENGAGEMENT-ASSETS.md  │
│  ⚠ Hiện engagement assets nằm rải rác: bizcity-crm-tichdiem (loyalty   │
│    shortcode + flow), magic-link-landing.php template, frontend của    │
│    bizgpt-dino-tichdiem. Cần consolidate.                              │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│  STAGE D — CRM INBOX + FUNNEL + REPORTING  (vận hành phễu)             │
│  • Inbox unified (FB/Zalo/Web) + AI auto-reply per inbox               │
│  • Sales pipeline Kanban + labels + custom attrs + macros              │
│  • Working hours + SLA + automation rules engine                       │
│  • Reports: funnel analytics, CSAT, agent KPI, loyalty ledger view     │
│  Doc anchor: PHASE-0.32-CRM-INBOX-HUB.md         (R-CRM-1..8)          │
│              PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md (M5/M6 FE)            │
│              PHASE-0.35-CRM-PARITY-CHATWOOT.md   (R-PAR-1..10)         │
│              PHASE-0.35-WAVES.md                 (M1..M9 implementation)│
└────────────────────────────────────────────────────────────────────────┘
```

### 1.1 Tóm lược 1 dòng

> **A**: Cắm kênh & bind Guru · **B**: Tạo kịch bản QR/link · **C**: Trang khách đáp lại · **D**: Inbox & phễu xử lý kết quả.

### 1.2 Renumber proposal (KHÔNG bắt buộc, đề xuất tham khảo)

Hiện file numbering (`0.31 → 0.35`) vẫn dùng được, CANON KHÔNG renumber để không phá history & link cross-doc. Nhưng **logical stage** map như sau:

| Logical Stage | File hiện tại |
|---|---|
| A — Channel Foundation | PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md, PHASE-0.33-WEBHOOK-GURU-UNIFY.md |
| B — Campaign / Scenario | PHASE-0.35-CRM-CAMPAIGN.md, PHASE-0.35-WAVES.md §M6 |
| C — Engagement Assets | *(TODO doc mới)* — hiện code rải ở `plugins/bizcity-crm-tichdiem/` + `templates/magic-link-landing.php` |
| D — CRM Inbox / Funnel | PHASE-0.32-CRM-INBOX-HUB.md, PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md, PHASE-0.35-CRM-PARITY-CHATWOOT.md, PHASE-0.35-WAVES.md (trừ §M6) |

Khi cần renumber thật → đề xuất:
- `PHASE-0.31-CHANNEL-FOUNDATION.md` (merge 0.31-INTEGRATE + 0.33-WEBHOOK)
- `PHASE-0.32-CAMPAIGN-SCENARIO.md` (rename 0.35-CRM-CAMPAIGN)
- `PHASE-0.33-ENGAGEMENT-ASSETS.md` (NEW)
- `PHASE-0.34-CRM-INBOX-HUB.md` (rename 0.32-CRM-INBOX-HUB)
- `PHASE-0.35-CRM-PARITY-CHATWOOT.md` (giữ)

Bro chốt thì em sẽ thực hiện trong 1 commit riêng, kèm batch update mọi cross-link.

---

## 2. Roadmap status — Thực trạng từng module

> Audit lần này: 2026-05-24. Đánh giá theo 3 trục: BE schema/repo, BE REST/cron, FE UI (channel-gateway SPA / wp-admin React app).

### 2.1 STAGE A — Channel Foundation

| Item | BE | REST | FE | Note |
|---|:---:|:---:|:---:|---|
| Channel Gateway core (registry, adapter base, integration) | ✅ | ✅ | ✅ | [core/channel-gateway/includes/class-integration-registry.php](../includes/class-integration-registry.php) + SPA `ChannelsRoute.jsx` |
| FB Pages list + token + test-connection | ✅ | ✅ | ✅ | Pages tab vừa refactor list-view 2026-05-24 |
| Guru × Channel binding (Inspector) | ✅ | ✅ | ✅ | `class-webhook-inspector.php` + `cgGuruBindApi.js` slice |
| Character Quick-Edit (Sheet) | ✅ | ✅ | ✅ | `class-character-quick-edit-rest.php` + `GuruQuickEditSheet.jsx` (2026-05-24) |
| Webhook router + log + replay | ✅ | ✅ | ✅ | `class-webhook-router.php`, `class-webhook-replay.php` |
| Universal Channel Listener | ✅ | — | — | `class-universal-channel-listener.php` |
| Sprint Diagnostic | ✅ | ✅ | ✅ | `class-sprint-diagnostic.php` + `class-phase-037-diagnostic.php` |
| Scheduler (cron + Google sync) | ✅ | ✅ | ✅ | `core/scheduler/` + `FacebookSchedule.jsx` (month/week/day view 2026-05-24) |

**Stage A: gần đủ. Còn thiếu** — Zalo OA wizard UI giống FB tab (BE đã có), Telegram/IG/WhatsApp adapter UI.

### 2.2 STAGE B — Campaign / Scenario Builder

| Item | BE | REST | FE | Note |
|---|:---:|:---:|:---:|---|
| `bizcity_crm_campaigns` table + scenario columns | ✅ | — | — | `plugins/bizcity-twin-crm/includes/campaigns/class-campaign-repository.php` |
| Campaign Ref Codec (encode/decode `camp_<base62>`) | ✅ | — | — | `class-campaign-ref-codec.php` (port từ `twf_encrypt_chat_id`) |
| QR Generator | ✅ | — | — | `class-qr-generator.php` (server-side SVG/PNG) — **CANON cho phép fallback Google Chart API nếu lib local không có** |
| Scenario Dispatcher (run_shortcode / send_message / kg_grounded_reply) | ✅ | — | — | `class-campaign-scenario-dispatcher.php` |
| Campaign tracker + visit ledger | ✅ | — | — | `class-campaign-tracker.php` + `bizcity_crm_campaign_visits` |
| Flow importer (kéo `wp_bizgpt_custom_flows` → CRM campaigns) | ✅ | — | — | `class-flow-importer.php` |
| Conversion bridge + linker | ✅ | — | — | `class-conversion-bridge.php`, `class-conversion-linker.php` |
| Loyalty bridge | ✅ | — | — | `class-loyalty-bridge.php` |
| REST CRUD `/campaigns` + `/messenger-link` + QR endpoint | ✅ | ✅ | — | `class-rest-controller.php` line 1673–1781 |
| FB Adapter parse `referral.ref` → emit `crm_campaign_visit_recorded` | 🟡 | — | — | Cần verify trong `core/channel-gateway/adapters/class-fb-messenger-adapter.php` |
| **Channel Gateway SPA → menu Campaigns** | ❌ | — | ❌ | **CHƯA CÓ** — đây là gap chính bro hỏi hôm nay |

**Stage B: BE 90%, FE 0%.** Roadmap kế tiếp = ship FE Campaigns tab trong channel-gateway SPA (xem [§ 3](#3-fe-campaigns-skeleton-stage-b-fe)).

### 2.3 STAGE C — Engagement Assets

| Item | Trạng thái | Note |
|---|:---:|---|
| Magic-link landing template | ✅ | `plugins/bizcity-twin-crm/templates/magic-link-landing.php` |
| Loyalty shortcodes (tích/đổi điểm) | ✅ | `plugins/bizcity-crm-tichdiem/includes/class-flow-manager.php` + `bizgpt-dino-tichdiem/frontend/` |
| Lucky wheel (vòng quay may mắn) | ❌ | Chưa có. Cần shortcode `[bizcity_lucky_wheel campaign="..."]` + trang admin cấu hình prize |
| Lead-capture form page builder | ❌ | Chưa có. Cần shortcode hoặc page template với token replacement |
| Mini quiz / survey | ❌ | Chưa có |
| Consolidated "Engagement Asset" admin tab | ❌ | Chưa có doc riêng — đề xuất tạo `PHASE-0.33-ENGAGEMENT-ASSETS.md` |

**Stage C: hỗn loạn.** Cần 1 doc riêng quy hoạch lại + 1 admin surface tập trung các asset types.

### 2.4 STAGE D — CRM Inbox / Funnel / Reporting

| Item | BE | REST | FE | Note |
|---|:---:|:---:|:---:|---|
| 6 CRM tables (`inboxes`/`contacts`/`contact_inboxes`/`conversations`/`messages`/`attachments`) | ✅ | — | — | `class-db-installer.php` |
| Repository | ✅ | — | — | `class-repository.php` |
| Event emitter (`crm_message_*`, `crm_conversation_*`) | ✅ | — | — | `class-event-emitter.php` |
| FB Ingestor | ✅ | — | — | `class-fb-ingestor.php` |
| AI auto-reply listener | ✅ | — | — | `class-ai-autoreply-listener.php`, `class-ai-replier.php` |
| Guru resolver | ✅ | — | — | `class-guru-resolver.php` |
| REST controller (inbox/conv/message CRUD + send/snooze/resolve) | ✅ | ✅ | — | `class-rest-controller.php` |
| Inbox React SPA | ✅ | — | ✅ | `plugins/bizcity-twin-crm/frontend/` (đã build, mount qua admin menu) |
| Custom attributes | ✅ | ✅ | 🟡 | `attributes/class-custom-attr-validator.php` — FE form chưa hoàn |
| Automation rules engine | ✅ | 🟡 | ❌ | `automation/` folder — UI rule-builder chưa có |
| Macros / canned response | ✅ | 🟡 | ❌ | `macros/` folder — FE picker chưa có |
| Working hours + SLA | ✅ | 🟡 | ❌ | `sla/` folder — chưa có dashboard breach |
| Reports dashboard (funnel/CSAT/KPI) | 🟡 | ❌ | ❌ | `reports/` folder skeleton — chưa có chart UI |
| Sales pipeline Kanban | 🟡 | — | 🟡 | Có hint trong WAVES M5; reference `ilmly-lms-frontend` |
| Loyalty admin (top members, redeem audit) | 🟡 | — | ❌ | `plugins/bizcity-crm-tichdiem/` |

**Stage D: BE ~80%, FE 30%.** Roadmap dài hơi, chia theo M1..M9 trong [PHASE-0.35-WAVES.md](PHASE-0.35-WAVES.md).

---

## 3. FE Campaigns skeleton (Stage B FE)

> Yêu cầu bro 2026-05-24: thêm menu Campaigns vào channel-gateway SPA, list + Sheet form + QR — chuẩn theo wp-admin/admin.php?page=bizgpt_flows (legacy).

### 3.1 Vị trí menu

- Channel Gateway SPA hiện có sidebar items: Overview, Channels, Add Channel, Health, Logs, Playground, Settings, + per-platform routes (`platform/facebook/*`).
- **Thêm group "Marketing" cao hơn "Settings"** với 1 mục `Campaigns` (icon `Megaphone`). Sau này thêm "Engagement Assets" (Stage C) vào cùng group.

### 3.2 Route + file mapping

| File mới | Rôle |
|---|---|
| `frontend/src/routes/campaigns/CampaignsRoute.jsx` | Wrapper — header bar + DataTable (Tình huống / Hành động / Cập nhật / Link kích hoạt / QR / Thao tác). Filter status + search. |
| `frontend/src/routes/campaigns/CampaignFormSheet.jsx` | Sheet (Dialog right-side) — sections: Basic / Scenario (action_type · shortcode/template · attrs repeater) / Reminder (delay + unit + text + delay-only checkbox) / AI binding (notebook + character + auto-gen prompt) / Loyalty (points award). |
| `frontend/src/routes/campaigns/CampaignRowActions.jsx` | LinkBox: `<input readonly>` m.me URL + Copy + Open + `<img>` QR 120×120. |
| `frontend/src/routes/campaigns/AttributeRows.jsx` | Repeater key/prompt (port UX `attribute-row` của bizgpt_flows). |
| `frontend/src/redux/api/campaignsApi.js` | RTK Query — hit `bizcity-crm/v1/campaigns` (BE đã có). tagTypes `['Campaign','CampaignStats']`. |

### 3.3 BE prerequisites (đã sẵn — không cần code thêm)

- REST routes: `GET/POST /bizcity-crm/v1/campaigns`, `GET /campaigns/{id}/messenger-link?page_id=`, `GET /campaigns/{id}/qr.{png|svg}`, `POST /campaigns/{id}/preview-prompt` — tất cả trong `plugins/bizcity-twin-crm/includes/class-rest-controller.php` line 1673–1781.
- Capabilities: `manage_options` cho admin tab (R-PAR-1 / R-CRM-5).
- Table: `wp_bizcity_crm_campaigns` (xem column list trong [PHASE-0.35-CRM-CAMPAIGN.md § 2](PHASE-0.35-CRM-CAMPAIGN.md#2-schema-delta--bizcity_crm_campaigns)).

### 3.4 Gating: FB Pages prerequisite

- Khi mount `CampaignsRoute`: gọi `useFbListPagesQuery()`. Nếu `pages.length === 0` → render `<EmptyState>` với CTA "Mở Facebook Pages tab để kết nối Page trước".
- KHÔNG hard-block route — admin có thể vẫn vào xem campaigns cũ, chỉ không tạo mới được (form sheet "Save" sẽ disable + tooltip).

### 3.5 QR rendering (CANON quyết định)

- Mặc định: dùng endpoint BE `GET /campaigns/{id}/qr.png?size=240` (server-side, lib local `class-qr-generator.php`).
- Fallback (theo yêu cầu bro): nếu lib local lỗi / không có → BE trả 302 redirect sang `https://chart.googleapis.com/chart?cht=qr&chs=240x240&chl=<encoded_url>` (Google Chart QR API — public, miễn phí, không cần key).
- FE chỉ cần `<img src="${restUrl}/campaigns/${id}/qr.png?size=240" />` — không quan tâm BE chọn lib nào.

---

## 4. Connection map: Campaign ↔ Funnel (Stage B → D)

> Yêu cầu bro: "sau khi tạo kịch bản, có nút liên kết chiến dịch với phễu funnel".

### 4.1 Quan hệ data hiện có

- `bizcity_crm_campaigns.id` → tham chiếu bởi:
  - `bizcity_crm_campaign_visits.campaign_id` (visit ledger — Stage B emits)
  - `bizcity_crm_contacts.acquisition_meta` JSON `{campaign_id, ref, utm}` (Stage D upserts)
  - `bizcity_crm_conversations.metadata` JSON `{source_campaign_id}` (Stage D auto-link)
- `crm_campaign_visit_recorded` event payload `{campaign_id, channel_inbox_id, contact_id, parent_event_uuid}` — Automation Engine listener resolve sang funnel stage.

### 4.2 UI link "Liên kết với phễu" (chưa làm)

- Trong `CampaignFormSheet.jsx` add section **"Funnel mapping"**:
  - Select pipeline (load từ `/bizcity-crm/v1/pipelines` — TODO endpoint)
  - Select target stage (load từ `/pipelines/{id}/stages`)
  - Lưu vào cột mới `bizcity_crm_campaigns.target_pipeline_id` + `target_stage_id` (cần migration).
- Khi `crm_campaign_visit_recorded` fire → Automation Engine tự tạo `crm_conversation` đặt vào stage chỉ định.

### 4.3 Báo cáo funnel xuất hiện ở đâu

- CRM SPA (Stage D) tab **Reports** → "Campaign performance" panel: visits / conversions / avg time-to-convert / loyalty awarded — query từ `bizcity_twin_event_stream` filter `event_type LIKE 'crm_campaign_%'` (R-PAR-3 projection).
- CRM SPA tab **Pipeline** → Kanban Card hiển thị `source_campaign_code` badge + click drill-down sang Campaign detail.

---

## 5. Doc relocation

10 docs sau đã move từ `bizcity-twin-ai/` (plugin root) sang `bizcity-twin-ai/core/channel-gateway/docs/` (2026-05-24):

| File | Vai trò |
|---|---|
| [PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md](PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md) | Channel Gateway unify spec |
| [PHASE-0.31-TARGET-SCENARIOS.md](PHASE-0.31-TARGET-SCENARIOS.md) | 7 north-star scenarios S1..S7 |
| [PHASE-0.32-CRM-INBOX-HUB.md](PHASE-0.32-CRM-INBOX-HUB.md) | CRM Inbox Hub spec + R-CRM-1..8 |
| [PHASE-0.33-WEBHOOK-GURU-UNIFY.md](PHASE-0.33-WEBHOOK-GURU-UNIFY.md) | Webhook ↔ Guru binding unify |
| [PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md](PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md) | Inbox FE (Composer/Note/Resolve/ContactDrawer/Replay) M5/M6 |
| [PHASE-0.35-CRM-PARITY-CHATWOOT.md](PHASE-0.35-CRM-PARITY-CHATWOOT.md) | CRM parity vs Chatwoot + R-PAR-1..10 |
| [PHASE-0.35-CRM-CAMPAIGN.md](PHASE-0.35-CRM-CAMPAIGN.md) | Campaign-as-Scenario builder + R-CMP-1..6 |
| [PHASE-0.35-WAVES.md](PHASE-0.35-WAVES.md) | Implementation waves M1..M9 |
| [PHASE-CG-SCHEDULER.md](PHASE-CG-SCHEDULER.md) | Channel Gateway scheduler / Google Calendar sync |
| [PHASE-CG-SPA-WORKSPACE.md](PHASE-CG-SPA-WORKSPACE.md) | SPA workspace conventions |

### 5.1 Backward-compat

- Cross-link cũ dạng `[link](PHASE-0.32-CRM-INBOX-HUB.md)` từ doc Tier-0 (vd `PHASE-0-RULE-MPR-THINKING.md`, `PHASE-0.19-GURU-INDEX.md`) sẽ **404**. Cần batch grep+update — TODO trong PR riêng sau khi bro confirm.
- Tools chạy off-tree (script lint doc, sprint diag scanner) nếu hard-code đường dẫn → cập nhật theo.

### 5.2 Grep template để fix link cũ

```powershell
$root = "d:\OneDrive\Code\huongnguyen.vibeyeu.com.vn\wp-content\plugins\bizcity-twin-ai"
$pattern = '\(PHASE-0\.(31|32|33|34|35)-[A-Z]'
Get-ChildItem -Path $root -Recurse -Include *.md | Where-Object { $_.FullName -notlike '*\docs\*' } | Select-String -Pattern $pattern
```

→ với mỗi hit, đổi `(PHASE-0.3X-…)` thành `(core/channel-gateway/docs/PHASE-0.3X-…)` hoặc relative tương đương.

---

## 6. RULE hợp nhất (CANON-only)

Bổ sung, KHÔNG ghi đè R-CRM-*, R-PAR-*, R-CMP-* (đã có trong các doc thành phần).

### **R-CANON-1 — STAGE order must hold**

Tài liệu / PR / sprint MỚI thuộc cụm 0.3 PHẢI tự gắn nhãn 1 trong 4 stage (A/B/C/D). Sprint cross-stage → tách 2 task riêng. Vi phạm: PR sẽ bị tag `needs-stage-split`.

### **R-CANON-2 — Campaign trước Inbox**

Mọi feature CRM Inbox MỚI muốn tham chiếu campaign (vd: "show source campaign trong contact drawer", "filter conversation theo campaign_code") PHẢI assert `BizCity_CRM_Campaign_Repository::is_ready()` tồn tại + bảng `bizcity_crm_campaigns` đã migrate. Nếu chưa → graceful degrade (ẩn UI, không 500).

### **R-CANON-3 — Doc lives in `core/channel-gateway/docs/`**

Mọi doc PHASE 0.3x liên quan đến channel/campaign/CRM TỪ 2026-05-24 trở đi PHẢI tạo ở `core/channel-gateway/docs/`. Cấm tạo mới ở plugin root.

### **R-CANON-4 — FE Campaign tab gate by FB Page**

FE tab Campaigns trong channel-gateway SPA PHẢI gọi `useFbListPagesQuery()` và render `EmptyState` (KHÔNG hard-redirect, KHÔNG hide menu) khi không có Page. CTA dẫn về Pages tab.

### **R-CANON-5 — QR fallback chain**

Server `class-qr-generator.php` priority:
1. Local lib (endroid/qr-code hoặc phpqrcode) nếu autoload có.
2. Inline SVG generator (no dependency).
3. 302 redirect Google Chart API `chart.googleapis.com/chart?cht=qr&chs=NxN&chl=…`.

FE KHÔNG được gọi thẳng `chart.googleapis.com` — luôn qua endpoint BE.

### **R-CANON-6 — Funnel mapping qua event, không qua join trực tiếp**

UI "Campaign → Pipeline stage" lưu mapping trong campaign row. Realtime "move to stage" PHẢI emit `crm_conversation_stage_changed` qua Event Bus (R-EVT-6) — KHÔNG được UPDATE thẳng `conversations.pipeline_stage_id` từ Automation Engine bypass event.

---

## 7. Next actions (1 sprint)

1. **[FE-CMP-1]** Ship FE Campaigns route theo § 3 — 1 SPA bundle rebuild.
2. **[BE-CMP-1]** Verify FB adapter emit `crm_campaign_visit_recorded` (có sprint diag probe sẵn không?). Nếu chưa, thêm probe.
3. **[BE-CMP-2]** Verify `class-qr-generator.php` có fallback Google Chart (R-CANON-5). Nếu chưa, patch.
4. **[DOC-CMP-1]** Tạo `PHASE-0.33-ENGAGEMENT-ASSETS.md` skeleton (Stage C planning).
5. **[DOC-FIX-1]** Batch update cross-link cũ tới docs đã move (§ 5.2 grep).
6. **[CRM-FE-1]** Add "Funnel mapping" section vào `CampaignFormSheet.jsx` (cần endpoint `/pipelines` — kéo theo BE work).

---

## 8. Changelog

| Date | Author | Change |
|---|---|---|
| 2026-05-24 | Twin AI Architecture | v1.0 — initial CANON, move 10 docs, define A/B/C/D stages, audit roadmap status, define R-CANON-1..6 |
