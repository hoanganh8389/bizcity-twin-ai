# 13 — Gap Critique & Readiness Review (audit code thật, 2026-08-29)

> **Status:** ✅ ĐÃ AUDIT — tài liệu này KHÔNG suy đoán. Mọi phát hiện dưới
> đây được xác nhận bằng cách đọc trực tiếp code trong repo (không phải suy
> luận từ tên file/docstring). Mục tiêu: trả lời thẳng câu hỏi *"khung
> `docs/vibe/00-12` đã chắc chưa, có bám đúng core/rule/framework hiện có
> không, và những gì còn thiếu để không chỉ là tài liệu đẹp"*.
>
> Đây cũng là nơi chính thức hoá **tôn chỉ #2** (Twin Data Center, không phải
> chatbot) và đối chiếu lại toàn bộ `docs/vibe/00-12` theo tôn chỉ đó.

---

## 0. Phương pháp

Toàn bộ phát hiện ở đây đến từ 1 lượt audit read-only trên code thật
(2026-08-29), đọc trực tiếp:
`core/twinbrain/includes/class-twinbrain-conversation-router.php`,
`class-twinbrain-runtime.php`, `class-twinbrain-tool-intent-matcher.php`,
`core/twinbrain/bootstrap.php`, `core/knowledge/kg-hub/includes/class-kg-source-service.php`,
`core/knowledge/kg-hub/skeleton/class-notebook-skeleton-service.php`,
`core/knowledge/kg-hub/includes/class-kg-channel-notebook-bridge.php`,
`core/mcp/bootstrap.php`, `core/mcp/rest/class-mcp-http-controller.php`,
`plugins/bizcity-twin-crm/includes/class-rest-controller.php`, và grep toàn
repo cho `implements BizCity_*_Interface` / `add_filter('bizcity_twin_register_tool'`.
Không có phát hiện nào ở đây dựa trên tên file hay giả định — mọi dòng đều có
thể trace lại đúng file:line đã đọc.

## 1. GAP-1 — SDK 7-verb: interface tồn tại, ADOPTION = 0

**Phát hiện:** `core/twin-core/contracts/content-contracts.php` và
`framework-contracts.php` định nghĩa đủ 8 interface (Tool, Agent, Skill,
Channel Adapter, KG Source Adapter, Workflow Block, Persona Provider, Output
Renderer). Nhưng:

- **ZERO class production nào** trong `core/`, `modules/`, `plugins/`
  implement bất kỳ interface nào trong số này. Chỗ duy nhất có
  `implements BizCity_Tool_Interface`/`BizCity_KG_Source_Adapter_Interface`/...
  là `examples/bizcity-reference-plugin/bizcity-reference-plugin.php` (file
  tài liệu/mẫu) và `bin/bizcity-sdk-scaffold.php` (template sinh code).
- Tool đăng ký thật qua filter `bizcity_twin_register_tool` chỉ có 3 nơi:
  `core/twin-core/bootstrap.php:185`, `core/twinbrain/bootstrap.php:323-371`
  (4 tool: `memory_remember`, `memory_forget`, `memory_recall`,
  `ingest_document` — đăng ký bằng closure/instantiate trực tiếp, KHÔNG
  implement `BizCity_Tool_Interface`), và
  `modules/twinweb/includes/class-twinweb-agent-tool-adapters.php:1235`.

**Ý nghĩa:** Tài liệu 07 đánh dấu Tool/KG-Source-Adapter/Skill/Channel là
"✅ Đã có (tên khác)" — điều này ĐÚNG về việc **cơ chế đăng ký tồn tại**
(filter, registry), nhưng **CHƯA đúng** nếu đọc là "đã có plugin nào dùng
thật". Cần sửa nhãn trạng thái để không gây hiểu nhầm cho AI agent đọc vào
rồi tưởng interface đã production-proven.

**Sửa vào tài liệu:** [07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md) — thêm cảnh
báo rõ "cơ chế tồn tại, adoption = 0"; [00](00-VIBE-CANON.md) §6 bảng bằng
chứng cần sửa dòng tương ứng (xem §7 dưới).

**Việc cần làm:** Wave 1 (W1-5 trong MASTER-CHECKLIST.md) — migrate lại
chính 4 tool đã có trong `core/twinbrain/bootstrap.php` sang implement
`BizCity_Tool_Interface` thật, thay vì chỉ thêm interface rỗng cho plugin
tương lai.

## 2. GAP-2 — "Vertical Brain Mode" hiện là builtin, KHÔNG phải plugin bridge

**Đây là gap nghiêm trọng nhất** vì nó mâu thuẫn trực tiếp với tuyên bố ở
[01-VERTICAL-BRAIN-PLUGIN-MODEL.md](01-VERTICAL-BRAIN-PLUGIN-MODEL.md):
*"Mỗi plugin = 1 Vertical Brain Mode"*. Thực tế code hôm nay:

- `core/twinbrain/includes/class-twinbrain-conversation-router.php:16` —
  `const SPECIALIZED_ROUTING_ENABLED = false;` — cơ chế route theo vertical
  bị **TẮT** hoàn toàn.
- Cùng file, dòng 22–36: `const VERTICAL_CATALOG` liệt kê cứng 12 vertical
  (`astro`, `quick`, `deep`, `social`, `company`, `med`, `scholar`, `nutri`,
  `law`, `tax`, `gov`, `products`) — đây là **PHP const trong core**, không
  phải registry đọc từ plugin.
- `core/twinbrain/includes/class-twinbrain-runtime.php:746-814` — có nhánh
  hardcoded `if ( $direct_vertical_mode === 'woo_bizops' ) { ... }` ngay
  trong Runtime lõi.
- Có **10 file class riêng cho từng vertical, tất cả nằm trong
  `core/twinbrain/includes/`**, không phải trong 1 plugin nào:
  `class-twinbrain-astro-recall.php`, `class-twinbrain-astro-subject-profile-service.php`,
  `class-twinbrain-web-products.php`, `class-twinbrain-web-woo-bizops.php`,
  `class-twinbrain-web-med.php`, `class-twinbrain-web-law.php`,
  `class-twinbrain-web-tax.php`, `class-twinbrain-web-gov.php`,
  `class-twinbrain-web-nutri.php`, `class-twinbrain-web-scholar.php`,
  `class-twinbrain-web-social.php`.
- `class-twinbrain-tool-intent-matcher.php` không dùng 1 registry mở — nó
  dùng whitelist theo Guru (`BizCity_Guru_Skill_Bridge::tools_for_guru()`,
  dòng 54-95) và có 1 nhánh đặc cách hardcoded cho
  `BizCity_TwinWeb_Agent_Tool_Catalog` (dòng 120).

**Ý nghĩa:** Điều "vertical brain mode" mà founder mô tả — *"mỗi plugin đều
sẽ là 1 vertical brain mode"* — **CHƯA tồn tại theo đúng nghĩa plugin-hoá**.
Hôm nay, mọi vertical là code nội bộ của `core/twinbrain`, được bật/tắt bằng
tham số `web_mode` chứ không phải bằng việc activate 1 plugin. Đây chính là
khoảng cách lớn nhất giữa tầm nhìn `docs/vibe/` và thực tại — không được che
giấu khoảng cách này.

**Sửa vào tài liệu:** [01](01-VERTICAL-BRAIN-PLUGIN-MODEL.md) — thêm callout
⚠️ GAP THẬT ở đầu file (xem §8 áp dụng bên dưới).

**Việc cần làm:** Wave 8 mới trong `12-ROADMAP` (xem MASTER-CHECKLIST.md
W8-1..W8-5) — di trú ĐÚNG 1 vertical (đề xuất `products`, rủi ro thấp nhất)
thành 1 plugin thật, chứng minh khả thi trước khi tuyên bố toàn bộ mô hình
đã pluggable.

## 3. GAP-3 — KG Hub KHÔNG phải trung tâm thật, chỉ là 1 kho có thể ingest thủ công

**Phát hiện:**

- Ingestion vào KG Hub hiện là **reactive theo hành động cụ thể**:
  `core/knowledge/kg-hub/skeleton/class-notebook-skeleton-service.php:66-73`
  đăng ký các hook `bizcity_twinchat_after_ingest`, `bizcity_kg_source_inserted`,
  `bizcity_doc_source_added` — nghĩa là cần MỘT hành động ingest cụ thể (do
  user/TwinChat/BizDoc chủ động) đã xảy ra TRƯỚC, service này chỉ build lại
  skeleton, không tự khởi tạo ingest.
- `core/knowledge/kg-hub/includes/class-kg-source-service.php` —
  `list_available_sources()` (dòng 26-58) và `attach_source()` (dòng 192)
  cho thấy mô hình là **user tự chọn nguồn để gắn vào notebook**, không có
  cơ chế "plugin tự động đẩy dữ liệu vào" mà không cần con người bấm nút.
- Grep tương quan CRM/Automation với `kg-hub/` chỉ tìm thấy
  `class-kg-channel-notebook-bridge.php:395-495` — đây là cầu nối
  **MỘT CHIỀU NGƯỢC LẠI** (từ KG Hub context đẩy attachment VÀO Automation
  Pending State), không phải CRM/Automation đẩy dữ liệu VÀO KG Hub.
- **Không tìm thấy bằng chứng nào** cho: hội thoại CRM tự động thành KG
  source; Automation run tự động thành KG passage; CRM contact tự động thành
  KG entity.

**Ý nghĩa:** Câu *"tất cả dòng chảy, rule unify cuối cùng đều phải về KG
Hub"* là **định hướng đúng và chưa bị vi phạm về nguyên tắc**, nhưng về mặt
kỹ thuật, **con đường tự động để hiện thực hoá điều đó chưa tồn tại**. KG
Hub hôm nay là nơi *có thể* ingest, không phải nơi *tự động* nhận mọi dòng
chảy.

**Sửa vào tài liệu:** [03](03-KG-HUB-UNIFICATION.md) — thêm callout ⚠️ GAP
THẬT.

**Việc cần làm:** Wave 9 mới (MASTER-CHECKLIST.md W9-1..W9-4) — thiết kế 1
hook ingest chuẩn dùng được cho MỌI plugin, nối thử với 1 luồng dữ liệu rủi
ro thấp trước (không phải toàn bộ tin nhắn khách hàng — vấn đề PII).

## 4. Đối chiếu lại với PHASE-1.29 §9.1 (đã biết từ trước, xác nhận lại)

Audit xác nhận đúng như [PHASE-1.29 §9.1](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md)
đã ghi nhận: `bizcity_webchat_messages`/`sessions`/`projects`/`tasks` và
`bizcity_crm_*` vẫn là **kho SQL song song đang hoạt động**, không phải chỉ
là "bảng cũ chờ dọn dẹp". Điều này nghĩa là ngay cả trước khi nói tới plugin
mới, **framework hiện tại còn 2-3 nguồn thật cạnh nhau cho cùng 1 khái niệm
hội thoại** — KG Hub/Event Stream là 1 trong số đó, không phải cái duy nhất.
Đây là gap đã có chủ tracking riêng (không phải phạm vi roadmap `docs/vibe/`
này), nên chỉ ghi nhận ở đây làm bối cảnh, theo dõi tại GAP-5 trong
MASTER-CHECKLIST.md, không mở wave mới trùng lặp.

## 5. Điểm sáng cần biết — đừng bỏ qua khi làm Wave 10

Điều bất ngờ tích cực từ audit: **mầm mống "Twin Data Center" đã tồn tại**,
chỉ chưa được lộ diện thành 1 tầng chính thức:

- `core/mcp/bootstrap.php` (dòng 96-115) đã load
  `BizCity_Business_MCP_Service` (sales + customer metrics, cross-plugin),
  `BizCity_Report_Brain_MCP_Service` (report dataset, cross-plugin),
  `BizCity_Commerce_Brain_MCP_Service` (WooCommerce catalog/orders/customers).
- `plugins/bizcity-twin-crm/includes/class-rest-controller.php` (dòng
  313-789+) có **8+ REST endpoint báo cáo** (`/reports/aggregate`,
  `/reports/auto-vs-human`, `/reports/campaign`, `/reports/agent`,
  `/dashboard/funnel-overview`...) tổng hợp dữ liệu đa kênh (Facebook, Zalo,
  automation, agent AI).
- Đây chính là bằng chứng framework **đã đi đúng hướng** analytics/dashboard
  ở tầng CRM và MCP — chỉ là nó đang nằm rải rác dưới dạng "tool phụ"/"REST
  phụ" của từng module, chưa được đóng khung thành 1 khái niệm "Twin Data
  Center" chính thức mà user có thể trỏ vào và nói "đây là nơi phân tích
  chiến lược doanh nghiệp của tôi".

**Việc cần làm:** Wave 10 (MASTER-CHECKLIST.md W10-1..W10-4) — **KHÔNG xây
mới**, chỉ kiểm kê + lộ diện + hợp nhất các nguồn này thành 1 điểm truy cập.

## 6. Tôn chỉ #2: Twin Framework là Twin Data Center, không phải chatbot

> *"Hướng đi của Twin Framework không phải là xây dựng 1 chatbot thông
> minh, mà là trở thành 1 Twin Data Center — tập trung dòng chảy dữ liệu,
> các plugin, ứng dụng của doanh nghiệp về 1 chỗ để phân tích, tổng hợp, trợ
> giúp doanh nghiệp định hướng, đánh giá, đưa ra chiến lược qua dashboard.
> Thế mạnh là phân tích, tối ưu, graph hoá dữ liệu khi đã unify về 1 nơi. Từ
> đó doanh nghiệp chỉ cần cắm MCP vào là có thể dùng Claude/ChatGPT làm
> việc."* — Johnny Chu, 2026-08-29.

### 6.1 Vì sao cần tuyên bố rõ tôn chỉ này

Đọc lại `docs/vibe/00-12` (trước khi có tài liệu này): 100% khung được viết
theo góc nhìn **"làm sao 1 plugin giúp TwinBrain trả lời chat tốt hơn"**
(Draft→Reflection→Final Gate, MPR Thinking Timeline, Notebook Selector...).
Đây KHÔNG sai — hội thoại vẫn là 1 giao diện quan trọng — nhưng nó không
phải mục tiêu cuối. Nếu không tuyên bố rõ tôn chỉ #2, có rủi ro thật: mọi
wave tiếp theo (kể cả Wave 1-7 đã viết) sẽ tối ưu 1 chiều cho "câu trả lời
chat hay hơn" mà quên mất lớp "phân tích/dashboard/chiến lược" — đúng như
hiện trạng hôm nay (Wave 10 phải bổ sung riêng vì lớp này chưa từng được nhắc
tới ở Wave 1-7).

### 6.2 Hệ quả kỹ thuật của tôn chỉ #2

| Khía cạnh | Trước tôn chỉ #2 (chỉ chatbot) | Sau tôn chỉ #2 (Twin Data Center) |
|---|---|---|
| Vai trò KG Hub | Nguồn tri thức để trả lời câu hỏi | Nguồn tri thức VÀ nguồn graph để phân tích quan hệ/xu hướng dữ liệu doanh nghiệp |
| Vai trò plugin | Cung cấp evidence + tool cho 1 lượt chat | Cung cấp evidence + tool cho chat, ĐỒNG THỜI expose dữ liệu structured cho tầng phân tích/dashboard (Wave 10) |
| Vai trò filestore JSONL ([02](02-FILESTORE-LOG-INDEX-STANDARD.md)) | Chỉ để audit/debug | Còn là nguồn dữ liệu thô cho graph hoá xu hướng theo thời gian (log = time-series business signal) |
| Vai trò MCP ([core/mcp](../../core/mcp/)) | Cho phép Claude/ChatGPT gọi tool | Cho phép Claude/ChatGPT **truy vấn dữ liệu tổng hợp đa plugin** (đã có mầm mống — Business/Report/Commerce MCP service) |
| Thước đo thành công | "Câu trả lời đúng, có citation" | "Câu trả lời đúng" VÀ "doanh nghiệp có thể nhìn thấy insight tổng hợp mà trước đây phải tự tổng hợp tay từ N hệ thống" |

### 6.2.1 Insight thành công phải kiểm chứng được

Không dùng từ "insight chính xác" như một nhãn cảm tính. Một insight hợp lệ
phải trả lời được toàn bộ chuỗi sau:

```text
claim chính xác trong snapshot nào?
  → citation/link dẫn chứng nào mở được?
  → source/plugin/adapter nào cung cấp?
  → event/trace nào ghi lại dòng chảy?
  → dữ liệu được quan sát lúc nào, trong window nào?
  → đơn hàng/bản ghi/CPT/entity nào liên quan?
  → SQL contract/table, JSONL filestore/index pointer nào lưu dữ liệu?
```

Nếu chỉ có câu kết luận và một URL nhưng không resolve được tới bản ghi,
event, thời điểm hoặc nơi lưu trữ, đó là **prose có dẫn nguồn**, chưa phải
insight đã audit. Contract chính thức, envelope và các metric như
`citation_coverage`, `lineage_completeness`, `record_resolution_rate` và
`reproducibility_rate` được định nghĩa tại
[11-SCOREBOARD-AUDIT-FRAMEWORK.md §7](11-SCOREBOARD-AUDIT-FRAMEWORK.md); trạng
thái triển khai chỉ cập nhật tại
[MASTER-CHECKLIST.md W10-5..W10-10](MASTER-CHECKLIST.md).

### 6.3 Tôn chỉ #2 KHÔNG có nghĩa là

- ❌ Xây 1 sản phẩm BI/data warehouse độc lập tách khỏi TwinBrain (xem
  "Ngoài phạm vi" trong [12-ROADMAP](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md) §4).
- ❌ Bỏ chuẩn chat/MPR Thinking Timeline đã xây ở Wave 1-7 — chúng vẫn cần
  thiết, chỉ không còn là MỤC TIÊU CUỐI.
- ❌ Yêu cầu mọi plugin phải tự làm dashboard riêng — dashboard tổng hợp là
  TRÁCH NHIỆM CỦA TẦNG TWIN DATA CENTER (Wave 10), plugin chỉ cần expose dữ
  liệu đúng chuẩn (manifest, filestore, KG Source Adapter).

## 7. Sửa chữa cần áp dụng vào từng file `00-12` (bảng tổng hợp)

| File | Vấn đề tìm thấy | Sửa gì |
|---|---|---|
| [00-VIBE-CANON.md](00-VIBE-CANON.md) | Chỉ có tôn chỉ #1, chưa có tôn chỉ #2; bảng §6 "bằng chứng hiện trạng" ghi contract "✅ Đã có" mà không nói rõ adoption=0 | Thêm tôn chỉ #2 (đã áp dụng — xem file), sửa dòng bảng liên quan contract + thêm dòng Vertical Bridge/KG Hub center = ⛔ GAP THẬT |
| [01-VERTICAL-BRAIN-PLUGIN-MODEL.md](01-VERTICAL-BRAIN-PLUGIN-MODEL.md) | Mô tả cơ chế như đã sẵn sàng, không nói rõ 100% verticals đang hardcoded | Thêm callout ⚠️ GAP THẬT đầu file (đã áp dụng — xem file) |
| [02-FILESTORE-LOG-INDEX-STANDARD.md](02-FILESTORE-LOG-INDEX-STANDARD.md) | Đúng về rule, nhưng thiếu liên kết với vai trò "dữ liệu thô cho phân tích" theo tôn chỉ #2 | Không sửa gấp — rule R-LOG-HYBRID vẫn đúng nguyên trạng; liên kết analytics đã ghi ở §6.2 bảng trên, đủ dùng |
| [03-KG-HUB-UNIFICATION.md](03-KG-HUB-UNIFICATION.md) | Mô tả như đã unify, không nói rõ ingestion là thủ công/reactive | Thêm callout ⚠️ GAP THẬT đầu file (đã áp dụng — xem file) |
| [07-PLUGIN-SDK-PUBLIC-INTERFACES.md](07-PLUGIN-SDK-PUBLIC-INTERFACES.md) | Bảng trạng thái "✅ Đã có (tên khác)" gây hiểu nhầm production-proven | Không sửa gấp — đã có disclaimer "🟡 MỘT PHẦN" ở đầu file; GAP-1 ở đây đủ làm rõ context, tránh double-edit không cần thiết |
| [11-SCOREBOARD-AUDIT-FRAMEWORK.md](11-SCOREBOARD-AUDIT-FRAMEWORK.md) | Scoreboard chỉ đo CORE health + PLUGIN lint, chưa có dòng cho tầng Twin Data Center | Sẽ mở rộng ở Wave 10 (W10-4) — chưa cần sửa file này ngay, ghi nhận ở đây làm backlog |
| [12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md) | Không có Wave nào cho GAP-1/2/3 và tôn chỉ #2 | Đã thêm Wave 8/9/10 + tôn chỉ #2 vào §0 (đã áp dụng — xem file) |
| [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md) | Chưa tồn tại trước đây (checklist phân mảnh ở 12-ROADMAP) | Đã tạo mới, hợp nhất toàn bộ checklist + §D Gap remediation (đã áp dụng — xem file) |

## 8. Kết luận: khung `docs/vibe/` đã chắc chưa?

**Về mặt nguyên tắc/quyền sở hữu (00, 04, 05, 06):** Chắc — các rule trích
dẫn (R-GW-8, R-ZONE-CHANNEL, R-LOG-HYBRID, R-KG-HUB-CONTRACT, R-EVENT-STREAM)
đều là rule Tier-1 đã ratify từ trước, không phải phát minh mới, và việc áp
dụng lại cho ranh giới plugin là hợp lý.

**Về mặt hạ tầng kỹ thuật đã "có sẵn để dùng ngay" (07, 08, 09, 10, 11):**
Chắc một phần — manifest schema, CLI, validator, contracts đều là file thật,
chạy được. Nhưng **mức độ "sẵn sàng cho vibe coding" đã bị đánh giá lạc
quan hơn thực tế** ở 2 điểm cụ thể: (a) SDK interfaces = 0 adoption (GAP-1),
(b) không có Wave nào xử lý việc "vertical brain" hiện là builtin thay vì
pluggable (GAP-2) — đây là gap TRỰC TIẾP mâu thuẫn với tuyên bố cốt lõi của
tài liệu 01.

**Về mặt "trung tâm hoá dữ liệu" (03):** Chưa chắc — KG Hub là kho có thể
dùng, nhưng chưa phải trung tâm TỰ ĐỘNG như tuyên bố (GAP-3). Cần Wave 9 để
biến tuyên bố thành sự thật kỹ thuật.

**Về tôn chỉ #2 (Twin Data Center):** Trước tài liệu này — HOÀN TOÀN VẮNG
MẶT trong `docs/vibe/00-12`. Sau tài liệu này — đã tuyên bố rõ, có Wave 10
riêng, và quan trọng nhất: đã xác nhận **mầm mống kỹ thuật đã tồn tại** (MCP
Business/Report/Commerce, CRM reports) nên đây không phải xây từ số 0.

**Tổng kết 1 câu:** Khung tài liệu đúng hướng và bám đúng rule/core hiện có,
nhưng đã tuyên bố một số khả năng ("mỗi plugin = 1 vertical brain",
"tất cả về KG Hub") ở thì hiện tại trong khi sự thật code là thì tương lai —
tài liệu này (13) và Wave 8-10 mới tồn tại chính xác để đóng khoảng cách đó.
