# PHASE 0.31 — Target Scenarios (Brain ⇄ Channel ⇄ Workflow Unification)

> **Phiên bản:** 1.0 (2026-05-07)
> **Trạng thái:** 🎯 NORTH-STAR / TARGET DEFINITION
> **Quan hệ:** target cụ thể cho [PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md](PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md)

---

## 0. Định nghĩa lõi (chốt từ thảo luận 2026-05-07)

| Khái niệm | Định nghĩa duy nhất |
|---|---|
| **Brain (não)** | KGHub — KG sources + KG graph. Không có brain nào khác. |
| **Notebook** | Một **scope của Brain** (1 notebook = 1 KG context). Định danh bằng `notebook_id`. |
| **TwinChat** | FE để **tạo notebook** + **giao tiếp với Brain qua notebook**. |
| **Integration** | **Tài khoản kết nối ngoài** (FB Page X, Zalo OA Y, Gmail Z, …). Được unify trong `bizcity-automation` tab "Tích hợp bên ngoài". |
| **Trigger** | Sự kiện **vào** từ kênh ngoài (messenger msg, web open, scheduler tick, link click, form submit, zalo msg). |
| **Action** | Hành vi **ra** (gửi mess, đăng FB, gửi email, set scheduler, lưu DB, tích điểm). |
| **Workflow** | Pipeline `Trigger → [Brain query] → [LLM generate] → Action(s)` được vẽ trong Automation Studio. |

→ **Quy tắc bất biến:** `notebook_id` là **biến trung tâm** của mọi workflow có dùng AI. Mọi action LLM đều có thể (tuỳ chọn) gắn `notebook_id` để lấy context KG-grounded.

---

## 1. Bảy kịch bản mục tiêu (canonical scenarios)

> Đây là **7 use case North-Star** — mọi quyết định kiến trúc của PHASE 0.31 phải thoả ít nhất 1 kịch bản. Đặt code `S1..S7` để tham chiếu chéo trong các phase tiếp theo.

### **S1 — CSKH Messenger qua Notebook**
> Khách hàng nhắn qua Facebook Messenger → bot trả lời bằng KG của notebook 22.

```
[TRIGGER]  wu_facebook_message_received(page_id=X)
   ↓ payload: { client_id, message, page_id }
[ACTION]   nb_query_kg(notebook_id=22, query={{message}}, limit=8)
   ↓ context: KG sources + graph paths
[ACTION]   ai_generate_text(prompt=client_prompt + context, provider=openrouter)
   ↓
[ACTION]   wp_send_facebook_bot_text(integration=facebook, account=page_X, to={{client_id}}, text={{ai_output}})
```
**Notebook role:** knowledge-base (RAG). **Channel:** Facebook (in + out).

---

### **S2 — Workflow Zalo trigger → AI viết kịch bản → đăng Facebook**
> Admin nhắn qua Zalo Bot OA "tạo bài viết X" → AI lấy template từ notebook 21 → generate → đăng lên Facebook Page.

```
[TRIGGER]  wu_zalobot_message_received(bot_id=admin_oa)
   ↓ payload: { admin_user_id, message }
[LOGIC]    if intent == 'create_scenario'
[ACTION]   nb_query_kg(notebook_id=21, query={{message}}, scope='scenario_template')
   ↓
[ACTION]   ai_generate_facebook(prompt={{message}} + {{kg_context}})
   ↓
[ACTION]   ai_generate_image(prompt=...)
   ↓
[ACTION]   wp_create_facebook_page_post(integration=facebook, page=Y, text=..., image=...)
   ↓
[ACTION]   wp_send_zalo_bot_text(to=admin, text="✅ Đã đăng: {{post_url}}")
```
**Notebook role:** template/style guide. **Channels:** Zalo (in) + Facebook (out) + Zalo (notify).

---

### **S3 — Web visitor → CSKH AI → Notify admin qua Zalo**
> Khách mở web → trigger câu hỏi → notebook 23 (chăm sóc web) trả lời → đồng thời thông báo cho admin qua Zalo.

```
[TRIGGER]  wu_webchat_message_received(site_id=current)
   ↓ payload: { visitor_id, message, page_url }
[ACTION]   nb_query_kg(notebook_id=23, query={{message}})
[ACTION]   ai_generate_text(prompt=cskh_prompt + context)
[ACTION]   webchat_reply(visitor_id={{visitor_id}}, text={{ai_output}})
[ACTION]   wp_send_zalo_bot_text(to=admin_oa, text="🔔 Khách hỏi: {{message}} | Đã trả lời: {{ai_output}}")
```
**Notebook role:** website FAQ KG. **Channels:** WebChat (in/out) + Zalo Bot (notify).

---

### **S4 — Admin Zalo → AI soạn email → Gửi**
> Admin nhắn Zalo "gửi email cho khách Y nội dung Z" → notebook 24 (email template) → generate → gửi qua SMTP/Gmail.

```
[TRIGGER]  wu_zalobot_message_received(bot_id=admin_oa)
[LOGIC]    if intent == 'send_email'
[ACTION]   ai_extract_json(prompt="extract recipient + subject + brief from {{message}}")
[ACTION]   nb_query_kg(notebook_id=24, scope='email_template', query={{brief}})
[ACTION]   ai_generate_text(prompt=email_writer_prompt + context)
[ACTION]   em_send_email(integration=gmail|smtp, to={{recipient}}, subject={{subject}}, body={{ai_output}})
[ACTION]   wp_send_zalo_bot_text(to=admin, text="✅ Đã gửi email tới {{recipient}}")
```
**Notebook role:** email template/style. **Channels:** Zalo (in) + Email (out).

---

### **S5 — Admin set scheduler → Cron → Nhắc qua Zalo**
> Admin lên lịch nhắc việc → tới giờ → cron fire → gửi Zalo nhắc.

```
[TRIGGER]  sy_schedule(rule={{cron_expr}})       # hiện đã có
[ACTION]   wp_send_zalo_bot_text(to=admin, text="⏰ Nhắc việc: {{schedule_payload.title}}")
```
**Notebook role:** không có. **Channels:** Scheduler (in) + Zalo (out). Đơn giản nhất.

---

### **S6 — Admin Zalo → "đặt lịch cho tôi" → Auto-create scheduler → Nhắc**
> Admin nhắn Zalo "đặt lịch họp 9h sáng mai" → AI parse → tạo scheduler → tới giờ nhắc lại qua Zalo. (Compound: Zalo trigger sinh ra scheduler trigger.)

```
[TRIGGER]  wu_zalobot_message_received(bot_id=admin_oa)
[LOGIC]    if intent == 'create_reminder'
[ACTION]   ai_extract_json(prompt="extract title + datetime + recipient from {{message}}")
[ACTION]   sy_create_schedule(rule={{datetime}}, payload={ title, recipient_zalo_id })
   ↓ (sau N giờ/ngày, sy_schedule trigger fire)
[TRIGGER]  sy_schedule
[ACTION]   wp_send_zalo_bot_text(to={{payload.recipient}}, text="⏰ {{payload.title}}")
```
**Notebook role:** (optional) parse intent. **Channels:** Zalo (in) + Scheduler (cầu) + Zalo (out).
**Đặc thù:** workflow A **sinh ra** trigger cho workflow B → cần `sy_create_schedule` ACTION (chưa có).

---

### **S7 — Quảng cáo link Messenger → Chatbot → Tích điểm + Lead capture**
> Quảng cáo gắn `m.me/<page>?ref=campaign_X` → user click → messenger fire trigger có `ref=campaign_X` → notebook 25 (kịch bản campaign) trả lời → tặng điểm/coupon → lưu lead.

```
[TRIGGER]  wu_facebook_message_received(page_id=X, ref='campaign_*')   # filter theo ref
[ACTION]   nb_query_kg(notebook_id=25, scope='campaign_X')
[ACTION]   ai_generate_text(prompt=campaign_script + context)
[ACTION]   wp_send_facebook_bot_text(to={{client_id}}, text={{ai_output}})
[ACTION]   loyalty_award_points(user_psid={{client_id}}, points=10, campaign='X')
[ACTION]   crm_capture_lead(source='facebook', psid={{client_id}}, campaign='X', meta={...})
   ↓ (nếu user submit form landing page)
[TRIGGER]  wp_form_submitted(form_id=Y)
[ACTION]   loyalty_award_points(...)
[ACTION]   crm_capture_lead(...)
```
**Notebook role:** campaign script. **Channels:** Facebook Messenger (in/out) + Form (in) + Loyalty/CRM (state).
**Đặc thù:** cần 2 thứ mới — `loyalty_*` và `crm_capture_lead` action; trigger filter theo `ref` parameter.

---

## 2. Ma trận yêu cầu rút ra từ 7 scenario

### 2.1. Channels cần unify

| Channel | Inbound | Outbound | Scenarios |
|---|---|---|---|
| Facebook Messenger | ✅ | ✅ | S1, S2 (notify), S7 |
| Facebook Page (post) | — | ✅ | S2 |
| Zalo Bot OA | ✅ | ✅ | S2, S3, S4, S5, S6 |
| Zalo BizCity (hotline) | ✅ | ✅ | (alt cho admin notify) |
| WebChat | ✅ | ✅ | S3 |
| Email (Gmail/SMTP) | — | ✅ | S4 |
| Scheduler | ✅ (cron) | ✅ (set sched) | S5, S6 |
| Form/Landing | ✅ | — | S7 |

### 2.2. Brain operations cần có

| Action code | Mục đích | Scenarios | Trạng thái |
|---|---|---|---|
| `nb_query_kg` | RAG lookup theo notebook_id | S1, S2, S3, S4, S7 | ❌ chưa có |
| `nb_create_note` | Lưu kết quả workflow vào notebook (audit/retrain) | (cross-cut) | ❌ chưa có |
| `nb_attach_artifact` | Đính ảnh/video sinh ra | S2 | ❌ chưa có |

### 2.3. Trigger cần có

| Trigger code | Scenario | Trạng thái |
|---|---|---|
| `wu_facebook_message_received` | S1, S2(out), S7 | ✅ có (cần thêm filter `ref`) |
| `wu_zalobot_message_received` | S2, S3(notify), S4, S6 | ✅ có |
| `wu_webchat_message_received` | S3 | ⚠️ kiểm tra |
| `sy_schedule` | S5, S6 | ✅ có |
| `wp_form_submitted` | S7 | ⚠️ kiểm tra (Gravity/CF7?) |
| `wu_messenger_ref_link` | S7 (filter theo `ref`) | ❌ — có thể là param của trigger có sẵn |

### 2.4. Action mới cần thêm (ngoài channel send)

| Action | Scenarios | Mục đích |
|---|---|---|
| `nb_query_kg` | S1-S4, S7 | RAG (xem 2.2) |
| `wp_create_facebook_page_post` | S2 | Đăng feed (khác send messenger) |
| `sy_create_schedule` | S6 | Workflow A sinh trigger cho workflow B |
| `loyalty_award_points` | S7 | Tích điểm |
| `crm_capture_lead` | S7 | Lưu lead |
| `ai_extract_json` | S4, S6 | Parse intent ra structured payload |

---

## 3. Phản biện vs hiện trạng — gì đang phân mảnh?

> Mọi mảnh đều **đang có** ở đâu đó nhưng **không nói chuyện được với nhau**. Đây là gốc của "rời rạc, lỗi" mà user phản ánh.

### 3.1. Brain (notebook + KGHub) — có nhưng không có cổng cho workflow

| Thành phần | Vị trí hiện tại | Vấn đề |
|---|---|---|
| KGHub query | `core/kg-hub/...` (TwinChat dùng) | Chỉ TwinChat gọi được, **automation/workflow không có wrapper public** |
| Notebook CRUD | `modules/twinchat/...` + CPT/option | Không có REST hay function callable từ block |
| `bizcity_twin_notebook_event` | có hook nhưng ít listener | Chưa có trigger block bám vào |

→ **Hệ quả:** không scenario nào trong S1–S7 chạy được hôm nay vì **không có `nb_query_kg` action**. Đây là blocker số 1.

### 3.2. Channels — có 80% backend, thiếu integration UI + 2 adapter

(Đã phân tích ở phản hồi trước — xem PHASE-0.31 §C.)

| Channel | DB | Adapter | Integration UI | Action block | Trigger block |
|---|---|---|---|---|---|
| FB Messenger | ✅ | ❌ | ❌ | ⚠️ (đi tắt) | ✅ |
| FB Page Post | (dùng same DB) | ❌ | ❌ | ❌ | — |
| Zalo Bot | ✅ | ✅ | ❌ | ⚠️ (đi tắt) | ✅ |
| Zalo Biz | ⚠️ option đơn | ❌ | ❌ | ⚠️ (đi tắt) | ⚠️ (gateway chung) |
| WebChat | ✅ | ❓ | ❌ | ❌ | ❓ |

### 3.3. Loyalty/Lead/Campaign — đang nằm ở `bizgpt-custom-flows` (legacy)

[bizgpt-custom-flows](d:\OneDrive\Code\huongnguyen.vibeyeu.com.vn\wp-content\plugins\bizgpt-custom-flows\bizgpt-custom-flows.php) đang giữ:
- `wp_bizgpt_custom_flows` table: `message`, `shortcode`, `action_type` (`run_shortcode|send_message`), `prompt`, `output_json`, `reminder_delay`, `reminder_unit`, `reminder_text`, `delay_only` → **mini-workflow của riêng nó**.
- Map keyword Vietnamese (`message_khong_dau`) → action.
- Có cả **reminder/scheduler riêng** (`reminder_delay/unit`) → **trùng** với `core/scheduler` + `sy_schedule`.

**Ưu:** đã có sẵn dữ liệu thật về kịch bản keyword + tích điểm shortcode.
**Nhược:** 
1. `action_type` chỉ 2 loại cứng → không mở rộng được như WaicAction.
2. Reminder riêng → chia 2 đường với scheduler chính.
3. Không liên thông notebook (không có khái niệm KG).
4. Trigger duy nhất là "keyword in chat message" — không nối với webhook FB/Zalo.

→ **Đây chính là S7** cần port: lấy keyword + shortcode payload chuyển thành **action `loyalty_award_points` + `crm_capture_lead`**, lấy reminder chuyển thành **`sy_create_schedule`**.

### 3.4. Scheduler — có nhưng không có "create from action"

`core/scheduler` + `sy_schedule` trigger đã có, nhưng **không có `sy_create_schedule` action** → S6 không chạy được. Đây là missing piece nhỏ nhưng quan trọng.

### 3.5. Bridge giữa "trigger nhận intent dạng tự nhiên" và "action structured"

S2/S4/S6 đều có dạng "user nhắn câu tự nhiên qua Zalo → workflow phân biệt intent → đi nhánh". Hiện tại workflow builder không có **branch theo intent**:
- Cần `ai_intent_router_json` (đã có trong list action!) làm bộ "switch case".
- Cần `LOGIC` block dạng `if/else` theo output của step trước (cần kiểm tra abstract block có sẵn chưa).

---

## 4. Priority matrix — làm gì trước?

| Task | Phục vụ scenarios | Effort | Priority |
|---|---|---|---|
| `nb_query_kg` action + REST wrapper cho KGHub | S1, S2, S3, S4, S7 | M | **P0** (blocker tất cả) |
| 3 Channel Integration (FB, Zalobot, Zalobiz) — xem PHASE 0.31.2 | All | M | **P0** |
| `WaicChannelIntegration` skeleton | All | M | **P0** |
| `nb_create_note`, `nb_attach_artifact` action | S2 (audit), cross | S | P1 |
| `wp_create_facebook_page_post` action | S2 | S | P1 |
| `sy_create_schedule` action | S6 | S | P1 |
| `wu_webchat_message_received` trigger (verify) | S3 | S | P1 |
| Filter `ref=campaign_*` cho `wu_facebook_message_received` | S7 | S | P2 |
| `loyalty_award_points` + `crm_capture_lead` (port từ bizgpt-custom-flows) | S7 | M | P2 |
| `wp_form_submitted` trigger (Gravity/CF7 wrapper) | S7 | S | P2 |
| Logic block `if/else` (verify có chưa) | S2, S4, S6 | S | P1 |
| Migrate `bizgpt-custom-flows` keyword DB → workflow definitions + deprecate plugin | S7 | L | P3 |

---

## 5. Đối chiếu lại nguyên tắc kiến trúc PHASE 0.31

7 scenario này khẳng định và **làm rõ thêm** triết lý PHASE 0.31:

> **`bizcity-automation` là backbone**, mọi thứ khác (FB plugin, Zalo plugin, scheduler, custom-flows, twinchat) chỉ là **provider** đăng ký block + integration vào đó.

Cụ thể:
1. **Brain (KGHub) = Service provider** → expose `nb_query_kg` action. TwinChat vẫn là FE để **tạo/quản lý notebook**, nhưng **không độc quyền** quyền đọc Brain.
2. **Channels = Integration + (action+trigger) pair** → unify trong tab "Tích hợp bên ngoài". Plugin FB/Zalo chỉ giữ DB + adapter, không tự render UI cấu hình rời.
3. **Scheduler = Trigger + Action** (`sy_schedule` + `sy_create_schedule`) → workflow tự đặt lịch cho workflow khác (S6).
4. **Loyalty/CRM/Form (bizgpt-custom-flows)** → tách thành 3 action độc lập + 1 trigger form, **bỏ** keyword router cứng (đã có `ai_intent_router_json`).

→ Sau khi 7 scenario chạy được, `bizgpt-custom-flows` có thể **deprecate** hoàn toàn (data migrate sang workflow definitions).

---

## 6. Acceptance test — workflow phải chạy được sau PHASE 0.31

Mỗi scenario S1–S7 phải có:
- [ ] 1 workflow definition export được dạng JSON ở `Automation Studio`.
- [ ] Test E2E từ trigger thật (gửi message thật / set cron 1 phút) tới action thật (kiểm tra DB, kiểm tra inbox).
- [ ] Log đầy đủ ở `bizcity_workflow_runs` (TBD table) với từng step.
- [ ] UI tab "Tích hợp bên ngoài" có entry cho mọi channel/integration scenario đó dùng.

---

## 7. Liên kết

- Roadmap chi tiết: [PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md](PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md)
- Brain unification: [PHASE-0-RULE-BRAIN-UNIFICATION.md](PHASE-0-RULE-BRAIN-UNIFICATION.md)
- KG Hub contract: [PHASE-0-RULE-KG-HUB-CONTRACT.md](PHASE-0-RULE-KG-HUB-CONTRACT.md)
- Channel Gateway: [PHASE-1.5-GATEWAY-ROADMAP.md](PHASE-1.5-GATEWAY-ROADMAP.md)
