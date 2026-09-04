# 12 — Roadmap: Closed-Loop Vibe Coding

> **Status:** ⛔ ROADMAP — waves thực thi cho toàn bộ tầm nhìn ở
> [00-VIBE-CANON.md](00-VIBE-CANON.md) (tôn chỉ #1: thêm plugin không sửa
> core; tôn chỉ #2: Twin Data Center, không phải chatbot). Không phần nào ở
> đây được coi là DONE chỉ vì tài liệu tồn tại — mỗi wave cần R-DDV evidence
> riêng.
>
> **Trạng thái checklist KHÔNG còn theo dõi trong file này.** Từ 2026-08-29,
> mọi checkbox (Wave 0–10 + Definition of Done) được hợp nhất và cập nhật
> tại **[MASTER-CHECKLIST.md](MASTER-CHECKLIST.md)** — file này chỉ giữ phần
> tường thuật (mô tả việc cần làm, exit gate, rủi ro). Xem
> [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md)
> để biết vì sao Wave 8–10 được thêm vào (audit code thật 2026-08-29).

---

## 0. Nguyên tắc thực thi

- Không xây lại những gì đã có (manifest schema, contracts, CLI, Diagnostics
  engine) — chỉ lấp khoảng trống đã xác định ở mỗi tài liệu 01–11 và ở
  [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md).
- Mọi thay đổi schema/DDL tuân R-DCL + R-CR + Site Provisioner + R-DDV.
- Mọi thay đổi PHP giữ PHP 7.4 compat + R-STAMP.
- Không sửa `core/` để "làm gọn cho 1 plugin cụ thể" — nếu cần sửa core, đó
  là dấu hiệu SDK chưa đủ tổng quát, phải quay lại thiết kế 7 verb
  ([07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md)) trước.
- Mọi wave PHẢI phục vụ tôn chỉ #2 (Twin Data Center): ưu tiên khả năng
  *unify dữ liệu nhiều plugin về 1 chỗ để phân tích/graph hoá/dashboard*
  ngang hàng với khả năng *trả lời chat hay* — không tối ưu 1 chiều cho
  chatbot rồi bỏ quên lớp phân tích chiến lược.
- Checklist trạng thái **chỉ sống ở [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md)**
  — không thêm `- [ ]` mới vào file roadmap này; thêm task mới thì thêm ID
  mới vào MASTER-CHECKLIST.md và trích dẫn ID đó ở đây.

## 1. Wave plan

### Wave 0 — Đóng băng quyết định kiến trúc (không code)

Việc cần làm — trạng thái & checkbox: `MASTER-CHECKLIST.md §A (W0-1..W0-3)`.

1. Chốt namespace CLI: `wp bizcity make:*` (xem
   [09](09-CLI-SCAFFOLDING-WP-BIZCITY.md)). `wp twin make:*` KHÔNG được dùng.
   → **W0-1: ĐÃ CHỐT** (Johnny Chu, 2026-08-29).
2. Chốt bổ sung field `taxonomy`/`primary_taxonomy`/`diagnostics`/
   `logging.contracts[]` vào `manifest.schema.json` (xem
   [08](08-TWIN-PLUGIN-MANIFEST-SPEC.md) §3). → **W0-2**.
3. Chốt tên filter chính thức cho `register_source()`/`register_event()`
   (hiện là đề xuất trong [07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md), chưa phải
   code thật). → **W0-3**.

**Exit gate:** có 1 quyết định ghi lại bằng văn bản cho mỗi mục trên (đã ghi
tại MASTER-CHECKLIST.md), không để ngỏ khi bắt đầu Wave 1.

### Wave 1 — Hoàn thiện 7 verb SDK

Checkbox: `MASTER-CHECKLIST.md §B (W1-1..W1-4)`.

1. Thêm `BizCity_Event_Registry::register_source()` (facade mới) + whitelist
   đối chiếu R-EVT-2 trước khi cho dispatch.
2. Gói `register_ui()` thành 1 lời gọi duy nhất (navigation + output
   renderer), thay vì phải gọi 2 API riêng.
3. Chuẩn hoá `register_skill()` thành filter PHP thuần tương đương
   `register_tool()`, giữ tương thích ngược với bảng `bizcity_skills`.
4. Cập nhật `packages/bizcity-framework-sdk` re-export đủ 7 verb.

**Exit gate:** `examples/bizcity-reference-plugin/` dùng được cả 7 verb qua
đúng 1 file bootstrap ngắn gọn, không gọi trực tiếp class nội bộ `core/*`.

> ⚠️ **Audit 2026-08-29 (xem [13](13-GAP-CRITIQUE-READINESS-REVIEW.md) §1):**
> hiện tại ZERO class production nào implement 7 interface này ngoài
> `examples/bizcity-reference-plugin/`; 4 tool đã đăng ký trong
> `core/twinbrain/bootstrap.php` dùng closure/ad-hoc, không implement
> `BizCity_Tool_Interface`. Wave 1 phải tự chứng minh bằng cách migrate lại
> chính 4 tool đó sang interface thật, không chỉ thêm interface rỗng.

### Wave 2 — Manifest schema mở rộng

Checkbox: `MASTER-CHECKLIST.md §B (W2-1..W2-3)`.

1. Thêm field ở Wave 0 vào `manifest.schema.json` + validator.
2. Update `examples/bizcity-reference-plugin/manifest.json` làm ví dụ mẫu
   đầy đủ nhất (golden fixture).
3. Chạy lại `bin/bizcity-manifest-validate.php` trên toàn bộ plugin hiện có
   trong `PLUGIN-CONTRACT-REGISTRY-v1.json` để đo baseline compliance.

**Exit gate:** validator PASS cho reference plugin; baseline compliance của
plugin hiện có được ghi lại (không cần 100% ngay, nhưng phải đo được).

### Wave 3 — CLI scaffolding `make:*`

Checkbox: `MASTER-CHECKLIST.md §B (W3-1..W3-3)`.

1. `BizCity_Framework_CLI_Make` dispatcher: `make:plugin`, `make:tool`,
   `make:source`, `make:event`, `make:diagnostic`.
2. Skeleton sinh ra PHẢI PASS lint (Wave 4) ngay ở trạng thái rỗng.
3. Cập nhật `bin/bizcity-sdk-scaffold.php` nếu tái dùng được, hoặc ghi rõ lý
   do tại sao cần dispatcher mới thay vì mở rộng script cũ.

**Exit gate:** `wp bizcity make:plugin demo-vibe-plugin` sinh ra 1 plugin
chạy được, activate không fatal, xuất hiện trong Plugin Contract Registry sau
khi đăng ký thủ công.

### Wave 4 — Lint + closed loop

Checkbox: `MASTER-CHECKLIST.md §B (W4-1..W4-5)`.

1. Mở rộng `bin/framework-contract-audit.mjs` với 2 rule WARN mới (mô tả Tool
   thiếu description, thiếu khai báo idempotency) — xem
   [10](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md) §4.
2. Thêm rule đối chiếu `event registration` (Wave 1's `register_event()`) với
   event thực tế được dispatch.
3. Thêm rule `uninstall safety` dựa theo checklist đã có ở
   [PHASE-1.29 §2](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md).
4. Đảm bảo mọi lỗi lint trả về `{code, message, hint, help_code}` — không trả
   `false` trần.
5. Gộp thành 1 lệnh `wp bizcity plugin lint <slug> [--json]` theo đúng format
   output ở [10](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md) §1.

**Exit gate:** chạy thử vòng lặp FAIL→AI FIX→PASS thật với 1 AI coding agent
trên `demo-vibe-plugin` từ Wave 3, ghi lại số vòng lặp cần thiết.

### Wave 5 — Filestore/Log/KG Hub adoption cho plugin mẫu

Checkbox: `MASTER-CHECKLIST.md §B (W5-1..W5-3)`.

1. `demo-vibe-plugin` implement 1 KG Source Adapter thật (xem
   [03](03-KG-HUB-UNIFICATION.md)), ingest được ít nhất 1 passage thật vào KG
   Hub.
2. `demo-vibe-plugin` ghi log qua `BizCity_JSONL_File_Logger` +
   `BizCity_Log_Contract_Registry`, xác nhận có pointer trong
   `bizcity_log_index` (nếu `indexed: true`).
3. Diagnostics probe của plugin xác nhận cả 2 luồng trên bằng Runtime
   evidence thật (không chỉ static check).

**Exit gate:** Log Explorer (Tools → BizCity Logs) hiển thị đúng dòng log của
`demo-vibe-plugin`; câu hỏi test qua TwinBrain trả về citation trỏ đúng nguồn
đã ingest từ plugin.

> ⚠️ Wave này CHƯA đủ để coi "KG Hub adoption" là xong — nó chỉ chứng minh 1
> plugin mẫu ingest được. Việc biến KG Hub thành trung tâm THẬT (auto-ingest,
> không cần user tự chọn nguồn) là Wave 9 riêng, xem bên dưới.

### Wave 6 — Scoreboard hợp nhất

Checkbox: `MASTER-CHECKLIST.md §B (W6-1..W6-2)`.

1. Mở rộng `wp bizcity status` để trả thêm khối `core[]`/`plugins[]` theo
   contract ở [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §3.
2. Renderer text (CLI) + renderer JSON — không cần renderer admin UI mới nếu
   Diagnostics admin page đã đủ hiển thị tương đương.

**Exit gate:** `wp bizcity status --json` trả `verdict: "READY"` cho
`demo-vibe-plugin` sau khi hoàn tất Wave 1–5.

### Wave 7 — Đóng băng chuẩn cho plugin mới (policy, không phải code)

Checkbox: `MASTER-CHECKLIST.md §B (W7-1..W7-2)`.

1. Công bố: mọi plugin mới (nội bộ lẫn cộng đồng) từ thời điểm này BẮT BUỘC
   đi qua `wp bizcity make:plugin` + PASS lint + PASS diagnostics trước khi
   merge/release.
2. Cập nhật `docs/extending/PLUGIN-STANDARD.md`/`PLUGIN-TWIN-STANDARD.md` trỏ
   về `docs/vibe/` làm lộ trình bắt buộc cho plugin mới (giữ nguyên nội dung
   cũ cho plugin đã tồn tại — không hồi tố ép buộc ngay lập tức).

**Exit gate:** README chính của repo (`docs/README.md`/`SUMMARY.md`) có mục
trỏ vào `docs/vibe/README.md` như điểm khởi đầu cho người đóng góp mới.

### Wave 8 — Vertical Brain Plugin Bridge (biến builtin thành pluggable) ⛔ MỚI

> Bắt buộc đọc [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md)
> §2 trước khi code wave này — đây là gap nghiêm trọng nhất tìm thấy khi audit
> code thật 2026-08-29: TwinBrain hiện chưa có "vertical brain plugin" nào cả,
> 100% vertical (astro/products/woo_bizops/med/law/tax/gov/nutri/scholar/social)
> đang là class hardcoded trong `core/twinbrain/includes/class-twinbrain-web-*.php`.

Checkbox: `MASTER-CHECKLIST.md §B (W8-1..W8-5)`.

1. Bật `BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED`
   trong 1 môi trường staging có DDV riêng — không bật thẳng production.
2. Chuyển `VERTICAL_CATALOG` (const hardcoded trong Conversation Router) từ
   PHP const sang registry đọc được từ plugin (`register_source()`/khai báo
   `capabilities.kg_source_adapters[]`/`web_mode_provider` trong manifest —
   quyết định tên field cụ thể ở Wave 0 mở rộng).
3. Chọn ĐÚNG 1 vertical builtin hiện có (đề xuất: `products` — ít rủi ro nhất,
   đã có `class-twinbrain-web-products.php`) và di trú thành 1 plugin thật
   implement `BizCity_KG_Source_Adapter_Interface` + `register_tool()`, xoá
   dần logic hardcoded tương ứng khỏi `core/twinbrain` sau khi plugin thay thế
   chạy PASS song song (không xoá trước khi có bằng chứng tương đương).
4. Viết Diagnostics probe xác nhận: (a) vertical mới do plugin cung cấp xuất
   hiện trong Tool Intent Matcher/Notebook Selector đúng như vertical builtin
   cũ; (b) không có regression cho các vertical hardcoded còn lại.
5. Ghi lại rõ ràng: các vertical KHÔNG di trú trong wave này (astro, woo_bizops,
   med, law, tax, gov, nutri, scholar, social) vẫn là hardcoded — không được
   coi Wave 8 "xong" chỉ vì 1 vertical mẫu chạy được.

**Exit gate:** có ít nhất 1 vertical brain mode chạy 100% từ 1 plugin ngoài
`core/twinbrain`, có Diagnostics PASS, và tài liệu hoá rõ các vertical còn lại
vẫn cần di trú (không tự nhận toàn bộ gap đã đóng).

### Wave 9 — KG Hub trở thành trung tâm thật (auto-ingest bridge) ⛔ MỚI

> Xem [13](13-GAP-CRITIQUE-READINESS-REVIEW.md) §3. Audit xác nhận: ingestion
> KG Hub hiện là **reactive theo hành động thủ công** (`bizcity_kg_source_inserted`,
> `bizcity_doc_source_added`, `bizcity_twinchat_after_ingest`) — không có
> đường tự động nào từ CRM/Automation/plugin filestore đẩy dữ liệu vào KG Hub.

Checkbox: `MASTER-CHECKLIST.md §B (W9-1..W9-4)`.

1. Thiết kế 1 hook chuẩn (`bizcity_kg_ingest_request` hoặc tương đương — chốt
   tên ở Wave 0 mở rộng) mà BẤT KỲ plugin/module nào (kể cả CRM, Automation)
   có thể `do_action()` để yêu cầu ingest 1 tài liệu/bản ghi vào KG Hub, thay
   vì chỉ chờ user bấm "attach source" trong UI.
2. Chọn 1 luồng dữ liệu thật để chứng minh (đề xuất: 1 loại sự kiện CRM ít rủi
   ro, ví dụ ghi chú/nội dung do agent tạo ra, KHÔNG phải toàn bộ tin nhắn
   khách hàng — tránh vi phạm quyền riêng tư/PII) và nối vào hook mới.
3. Diagnostics probe xác nhận: sự kiện CRM/Automation thật tạo ra 1 passage
   mới trong `bizcity_kg_passages` mà KHÔNG cần thao tác tay của user.
4. Cập nhật [03-KG-HUB-UNIFICATION.md](03-KG-HUB-UNIFICATION.md) xoá bỏ nhãn
   ⚠️ GAP sau khi có bằng chứng runtime thật (không chỉ code tồn tại).

**Exit gate:** có ít nhất 1 luồng dữ liệu ngoài "user tự upload" chảy tự động
vào KG Hub, có Diagnostics PASS xác nhận runtime thật.

### Wave 10 — Twin Data Center Analytics Layer (lộ diện, không phải xây mới) ⛔ MỚI

> Xem [13](13-GAP-CRITIQUE-READINESS-REVIEW.md) §6. Điểm quan trọng: **mầm
> mống đã tồn tại** — `BizCity_Business_MCP_Service`, `BizCity_Report_Brain_MCP_Service`,
> `BizCity_Commerce_Brain_MCP_Service` (`core/mcp/bootstrap.php`) và CRM
> `/reports/*` (8+ endpoint cross-channel) đã aggregate dữ liệu nhiều nguồn.
> Wave này KHÔNG xây 1 BI platform mới — nó **lộ diện và hợp nhất** những gì
> đã có thành 1 tầng chính thức, đúng tôn chỉ #2.

Checkbox: `MASTER-CHECKLIST.md §B (W10-1..W10-4)`.

1. Kiểm kê toàn bộ nguồn analytics hiện có (CRM `/reports/*`, MCP
   Business/Report/Commerce service, Diagnostics Scoreboard từ Wave 6) —
   ghi vào 1 bảng nguồn dữ liệu duy nhất (không tạo thêm nguồn mới ở bước này).
2. Thiết kế 1 "Twin Data Center Overview" — có thể là 1 trang admin hợp nhất
   HOẶC 1 MCP resource/tool tổng hợp — đọc từ các nguồn ở bước 1, KHÔNG viết
   lại logic tổng hợp (chỉ render/tổng hợp read-only).
3. Đảm bảo tầng này tôn trọng đúng ranh giới đã có: CRM data qua CRM Repository
   (không bypass), MCP tool policy gate vẫn áp dụng (không mở toàn bộ mặc định).
4. Diagnostics probe xác nhận: trang/MCP resource tổng hợp trả về dữ liệu thật
   từ ít nhất 2 plugin/module khác nhau (không chỉ 1 nguồn).

**Exit gate:** có 1 điểm truy cập duy nhất (UI hoặc MCP) cho phép nhìn thấy
dữ liệu tổng hợp từ ≥2 plugin nghiệp vụ khác nhau, được ghi rõ trong tài liệu
là "Twin Data Center" — không phải một dashboard đơn lẻ đổi tên.

## 2. Rủi ro & anti-pattern cần tránh

| Rủi ro | Mitigation |
|---|---|
| Tạo `wp twin` song song `wp bizcity` gây 2 nguồn thật CLI | Chốt quyết định ở Wave 0 trước khi code bất kỳ dòng nào |
| Thêm field manifest mới nhưng quên update validator | Wave 2 luôn đi kèm cập nhật `bin/bizcity-manifest-validate.php` trong cùng PR |
| Lint rule mới làm TOÀN BỘ plugin cũ FAIL đột ngột (breaking) | Rule mới mặc định là WARN trong 1 minor version, chỉ nâng FAIL sau khi baseline compliance đã đo (Wave 2) và có lộ trình migrate |
| `register_event()` bị lạm dụng để bypass whitelist R-EVT-2 | Facade chỉ được "khai báo ý định", core vẫn giữ quyền từ chối event không qua RFC |
| Scoreboard trở thành nguồn thật thứ 2 khác với Diagnostics gốc | Scoreboard chỉ là renderer, không lưu trạng thái riêng — xem [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §5 |
| Plugin mẫu (`demo-vibe-plugin`) bị coi là "production" rồi không dọn dẹp | Đánh dấu rõ plugin này là fixture nội bộ, xoá hoặc chuyển vào `examples/` sau khi Wave 6 xong |
| Wave 8 xoá hết vertical hardcoded cùng lúc, gây regression diện rộng | Chỉ di trú ĐÚNG 1 vertical mẫu trước, giữ nguyên các vertical còn lại cho tới khi có DDV song song PASS |
| Wave 9 ingest tự động vô tình đẩy PII/dữ liệu nhạy cảm khách hàng vào KG Hub (có thể lộ qua citation cho user khác) | Chọn luồng dữ liệu rủi ro thấp trước, review quyền sở hữu (`owner_scope_where()`) trước khi mở rộng sang dữ liệu khách hàng thật |
| Wave 10 bị hiểu nhầm là "xây BI platform mới", lấn sang tạo pipeline song song | Chỉ tổng hợp/hiển thị nguồn đã tồn tại (CRM reports, MCP services) — không viết lại logic phân tích |

## 3. Definition of Done (toàn bộ tầm nhìn `docs/vibe/`)

Checklist đầy đủ + trạng thái theo dõi: **[MASTER-CHECKLIST.md §C](MASTER-CHECKLIST.md)**.
Tóm tắt tường thuật (không phải nơi cập nhật trạng thái):

- 7 verb SDK hoạt động qua đúng 1 package công khai
  (`packages/bizcity-framework-sdk`), có ít nhất 1 tool production thật
  (không phải chỉ reference plugin) implement interface.
- `wp bizcity make:plugin` + `wp bizcity plugin lint <slug>` + vòng lặp
  FAIL→AI FIX→PASS chạy được thật với 1 AI coding agent, có log số vòng lặp.
- Filestore JSONL + `bizcity_log_index` + KG Hub adoption chứng minh được ở
  plugin mẫu (Wave 5) VÀ ít nhất 1 luồng auto-ingest thật (Wave 9).
- Có ít nhất 1 vertical brain mode chạy từ plugin ngoài `core/twinbrain`
  (Wave 8), không còn 100% hardcoded.
- Có 1 điểm truy cập "Twin Data Center" tổng hợp ≥2 nguồn dữ liệu nghiệp vụ
  (Wave 10), không chỉ là dashboard đơn lẻ.
- `wp bizcity status --json` trả về Scoreboard hợp nhất CORE + PLUGIN.
- `docs/extending/PLUGIN-STANDARD.md`/`PLUGIN-TWIN-STANDARD.md` trỏ về
  `docs/vibe/` cho plugin mới; không có plugin mới nào bỏ qua quy trình sau
  Wave 7.

## 4. Ngoài phạm vi (explicit non-goals)

- Không viết lại Intent Engine/TwinBrain Runtime hiện có.
- Không đổi API 1-API/Gateway hiện có (R-1API-AUTH, R-LLM-KEY-ONLY đã đủ chặt).
- Không bắt buộc migrate ngay 100+ plugin hiện có sang chuẩn mới — chỉ áp
  dụng bắt buộc cho plugin MỚI (xem Wave 7).
- Không xoá toàn bộ vertical hardcoded trong `core/twinbrain` cùng lúc (Wave
  8 chỉ di trú 1 vertical mẫu, phần còn lại là backlog riêng, không phải nợ
  phải trả hết trong roadmap này).
- Không rebrand Twin framework thành 1 sản phẩm BI/data-center độc lập tách
  khỏi TwinBrain — "Twin Data Center" (tôn chỉ #2) là 1 LỚP PHÂN TÍCH bổ sung
  trên cùng dữ liệu đã unify, không phải sản phẩm/pipeline thứ hai.
- Không tạo schema-driven execution engine — JSON Schema chỉ dùng để validate,
  không dùng để thực thi runtime (giữ nguyên nguyên tắc đã chốt ở
  [PHASE-1.21 §3.3](../roadmaps/PHASE-1.21-FRAMEWORK-CONSTITUTION-SCHEMA-SDK.md)).
