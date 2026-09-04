# MASTER CHECKLIST — Twin Framework Closed-Loop Vibe Coding

> **Đây là nơi DUY NHẤT theo dõi trạng thái checklist** cho toàn bộ tầm nhìn
> `docs/vibe/`. Không thêm `- [ ]` mới ở bất kỳ file `00`–`13` nào khác —
> mọi task mới lấy ID mới ở đây, các file khác chỉ trích dẫn ID
> (vd "xem MASTER-CHECKLIST.md W8-3").
>
> **Cách cập nhật:** khi 1 task đổi trạng thái, sửa dòng tương ứng TRONG BẢNG
> (không xoá), rồi thêm 1 dòng vào [Changelog](#changelog) bên dưới với ngày +
> ID + trạng thái mới + bằng chứng (link PR/commit/probe run). Không tự ý xoá
> lịch sử changelog cũ.
>
> **Trạng thái hợp lệ:** `TODO` · `IN PROGRESS` · `BLOCKED` · `DONE` · `WONT DO`.
> `DONE` chỉ được gán khi có bằng chứng Disk/Loader/Runtime (R-DDV) — không
> gán `DONE` chỉ vì code "trông đúng".

---

## §A — Wave 0: Quyết định kiến trúc (không code)

| ID | Task | Nguồn | Trạng thái | Bằng chứng |
|---|---|---|---|---|
| W0-1 | Chốt namespace CLI: `wp bizcity make:*`, không dùng `wp twin` | [09](09-CLI-SCAFFOLDING-WP-BIZCITY.md) §1 | **DONE** | Xác nhận bằng văn bản của Johnny Chu, 2026-08-29 |
| W0-2 | Chốt bổ sung field `taxonomy`/`primary_taxonomy`/`diagnostics`/`logging.contracts[]` vào `manifest.schema.json` | [08](08-TWIN-PLUGIN-MANIFEST-SPEC.md) §3 | TODO | — |
| W0-3 | Chốt tên filter chính thức cho `register_source()`/`register_event()` | [07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md) §3.4–3.5 | TODO | — |
| W0-4 | Chốt tên hook ingest chuẩn cho KG Hub auto-push (`bizcity_kg_ingest_request` hay tên khác) | [12](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md) Wave 9 | TODO | — |

## §B — Wave 1–10: Xây dựng SDK/Framework

### Wave 1 — 7 verb SDK

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W1-1 | `BizCity_Event_Registry::register_source()` facade mới + whitelist R-EVT-2 | **DONE** | `class-twin-event-registry.php`; PHPUnit whitelist test PASS; WordPress probe `core.framework.plugin_sdk` PASS (Disk/Loader/Runtime, PHP 8.1, 2026-08-29) |
| W1-2 | Gói `register_ui()` thành 1 lời gọi (navigation + output renderer) | **DONE** | `class-twin-plugin-sdk.php`; PHPUnit 15/55 PASS; WordPress probe `core.framework.plugin_sdk` verifies facade loaded/exposed (2026-08-29) |
| W1-3 | Chuẩn hoá `register_skill()` thành filter PHP thuần | **DONE** | SDK skill filter + Content Registry merge/boot; PHPUnit 15/55 PASS; WordPress probe `core.framework.plugin_sdk` PASS (2026-08-29) |
| W1-4 | `packages/bizcity-framework-sdk` re-export đủ 7 verb | **DONE** | `packages/bizcity-framework-sdk/src/PluginSdk.php` + PSR-4 mapping; seven-method PHPUnit assertion PASS; WordPress probe PASS (2026-08-29) |
| W1-5 | Migrate 4 tool đang đăng ký ad-hoc trong `core/twinbrain/bootstrap.php` (memory_remember/forget/recall, ingest_document) sang implement `BizCity_Tool_Interface` thật | **DONE** | 4 built-in classes implement both contracts; PHPUnit typed-tool test PASS; WordPress probe verifies all 4 in canonical registry (2026-08-29) |
| W1-6 | Registry runtime adapt global `BizCity_Tool_Interface` và namespaced `BizCity\\Twin\\Contracts\\ToolInterface` (`id/label/schema/run`) vào `BizCity_Twin_Tool` (`name/description/parameters_schema/execute`) | **DONE** | `class-twin-tool-registry.php`; global/namespaced PHPUnit adapter tests PASS; WordPress probe PASS (2026-08-29) |

### Wave 2 — Manifest schema mở rộng

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W2-1 | Thêm field Wave 0 vào `manifest.schema.json` + validator | **DONE** | Schema JSON parse PASS; validator kiểm tra taxonomy/primary_taxonomy/diagnostics/logging.contracts bằng PHP 8.1 (2026-08-29) |
| W2-2 | Update `examples/bizcity-reference-plugin/manifest.json` làm golden fixture | **DONE** | `bizcity.reference` validator PASS; manifest parse PASS (2026-08-29) |
| W2-3 | Chạy `bin/bizcity-manifest-validate.php` trên toàn bộ plugin trong `PLUGIN-CONTRACT-REGISTRY-v1.json` để đo baseline | **DONE** | 9/9 PASS, 0 FAIL, 0 MISSING (PHP 8.1, 2026-08-29) |

### Wave 3 — CLI scaffolding `make:*`

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W3-1 | `BizCity_Framework_CLI_Make` dispatcher (`make:plugin/tool/source/event/diagnostic`) | **DONE** | File-based WP-CLI registration harness PASS: 5/5 commands registered; `class-bizcity-framework-cli.php` PHP lint PASS (PHP 8.1.34, 2026-08-29) |
| W3-2 | Skeleton sinh ra PASS lint ngay ở trạng thái rỗng | **DONE** | Temporary skeleton generated 10 files; strict plugin lint PASS 13/13; generated PHP lint PASS (PHP 8.1.34, 2026-08-29) |
| W3-3 | Cập nhật/đối chiếu `bin/bizcity-sdk-scaffold.php` | **DONE** | `bin/bizcity-sdk-scaffold.php` now supports plugin/tool/source/event/diagnostic generation and emits `twin-plugin.json` + `manifest.json` (PHP lint PASS, 2026-08-29) |

### Wave 4 — Lint + closed loop

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W4-1 | Rule WARN: Tool thiếu description | **DONE** | `bizcity-plugin-diagnostics.php` quality check; strict skeleton run PASS with description check |
| W4-2 | Rule WARN: thiếu khai báo idempotency | **DONE** | `idempotency_declaration` check; strict skeleton run PASS after generator declaration |
| W4-3 | Rule đối chiếu `event registration` | **DONE** | `event_registration` check detects declared events and registration evidence; strict skeleton run PASS |
| W4-4 | Rule `uninstall safety` | **DONE** | `uninstall_safety` check + generated guarded `uninstall.php`; strict skeleton run PASS |
| W4-5 | Lỗi lint luôn trả `{code, message, hint, help_code}` | **DONE** | Every diagnostics check now includes machine-readable error fields; JSON output verified on strict skeleton run |
| W4-6 | Gộp thành `wp bizcity plugin lint <slug> [--json]` | **DONE** | WP-CLI command harness PASS; output includes `command=plugin lint`, `pass/warn/fail`, strict PASS 13/13, exit 0 (2026-08-29) |

### Wave 5 — Filestore/Log/KG Hub adoption (plugin mẫu)

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W5-1 | `demo-vibe-plugin` implement 1 KG Source Adapter thật | **DONE** | `examples/bizcity-reference-plugin/bizcity-reference-plugin.php`; typed adapter tạo source/passage trong central KG Hub, `examples.reference_plugin.wave5` PASS (2026-08-29) |
| W5-2 | `demo-vibe-plugin` ghi log JSONL + `bizcity_log_index` pointer | **DONE** | `BizCity_JSONL_File_Logger::write_contract()` + `BizCity_Log_Index`; pointer được tìm thấy và offset/hash follow đúng JSONL row, `examples.reference_plugin.wave5` PASS (blog 1526, 2026-08-29) |
| W5-3 | Diagnostics probe xác nhận runtime thật (không chỉ static) | **DONE** | `examples.reference_plugin.wave5` PASS: Disk/Loader/Runtime, 1/1 probe, exit 0; PHP 8.1.34, WordPress 6.9, blog 1526, `--skip-provision --skip-network` (2026-08-29) |

### Wave 6 — Scoreboard hợp nhất

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W6-1 | Mở rộng `wp bizcity status` trả `core[]`/`plugins[]` | TODO | — |
| W6-2 | Renderer text + JSON | TODO | — |

### Wave 7 — Đóng băng chuẩn cho plugin mới (policy)

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W7-1 | Công bố bắt buộc `make:plugin` + lint + diagnostics trước merge | TODO | — |
| W7-2 | `PLUGIN-STANDARD.md`/`PLUGIN-TWIN-STANDARD.md` trỏ về `docs/vibe/` | TODO | — |

### Wave 8 — Vertical Brain Plugin Bridge ⛔ MỚI (audit 2026-08-29)

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W8-1 | Bật `SPECIALIZED_ROUTING_ENABLED` ở staging có DDV riêng | TODO | — |
| W8-2 | Chuyển `VERTICAL_CATALOG` (const hardcoded) sang registry đọc từ plugin | TODO | — |
| W8-3 | Di trú 1 vertical mẫu (đề xuất `products`) thành plugin thật | TODO | — |
| W8-4 | Diagnostics probe: vertical mới xuất hiện đúng trong Tool Intent Matcher/Notebook Selector, không regression vertical cũ | TODO | — |
| W8-5 | Ghi rõ các vertical CHƯA di trú (astro/woo_bizops/med/law/tax/gov/nutri/scholar/social) vẫn hardcoded | TODO | — |

### Wave 9 — KG Hub trở thành trung tâm thật (auto-ingest bridge) ⛔ MỚI

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W9-1 | Thiết kế hook ingest chuẩn (xem W0-4) | TODO | — |
| W9-2 | Nối 1 luồng dữ liệu thật rủi ro thấp (CRM/Automation) vào hook | TODO | — |
| W9-3 | Diagnostics probe: sự kiện thật tạo passage KG Hub tự động, không thao tác tay | TODO | — |
| W9-4 | Cập nhật [03-KG-HUB-UNIFICATION.md](03-KG-HUB-UNIFICATION.md) xoá nhãn ⚠️ GAP sau khi có bằng chứng | TODO | — |

### Wave 10 — Twin Data Center Analytics Layer ⛔ MỚI

| ID | Task | Trạng thái | Bằng chứng |
|---|---|---|---|
| W10-1 | Kiểm kê toàn bộ nguồn analytics hiện có (CRM `/reports/*`, MCP Business/Report/Commerce, Scoreboard) | TODO | — |
| W10-2 | Thiết kế 1 điểm truy cập "Twin Data Center Overview" (UI hoặc MCP resource) | TODO | — |
| W10-3 | Đảm bảo tôn trọng ranh giới sở hữu (CRM Repository, MCP tool policy gate) | TODO | — |
| W10-4 | Diagnostics probe: trả dữ liệu thật từ ≥2 plugin/module khác nhau | TODO | — |
| W10-5 | Mọi insight trả về `insight_id`, claim, confidence, `computed_at`, analysis window và method/snapshot version | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.2 |
| W10-6 | Mọi factual claim có citation ID + link dẫn chứng có thể resolve; link lỗi phải hạ verdict về `unverified`/`degraded` | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.1–7.2 |
| W10-7 | Mọi insight có lineage `source_id` → plugin/adapter → event/trace → transformation/query → `computed_at` | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.2 |
| W10-8 | Mọi insight chỉ rõ nơi lưu dữ liệu: SQL contract/table + blog/shard hoặc JSONL contract/file/event/index pointer | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.2 |
| W10-9 | Mọi business reference (order/contact/customer/post/CPT/entity) được resolve theo ID và permission, không copy PII vào insight | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.2 |
| W10-10 | Diagnostics re-run cùng snapshot cho kết quả reproducible; Scoreboard báo citation coverage, lineage completeness, record resolution và unverified rate kèm denominator/window | TODO | [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7.3–7.5 |

## §C — Definition of Done (toàn bộ tầm nhìn)

| ID | Điều kiện | Trạng thái | Bằng chứng |
|---|---|---|---|
| DOD-1 | 7 verb SDK qua đúng 1 package công khai, có ≥1 tool production thật implement interface (không chỉ reference plugin) | TODO | — |
| DOD-2 | `wp bizcity make:plugin` + `wp bizcity plugin lint` + vòng lặp FAIL→AI FIX→PASS chạy thật với 1 AI agent, có log số vòng lặp | TODO | — |
| DOD-3 | Filestore JSONL + `bizcity_log_index` + KG Hub adoption ở plugin mẫu VÀ ≥1 luồng auto-ingest thật | TODO | — |
| DOD-4 | ≥1 vertical brain mode chạy từ plugin ngoài `core/twinbrain` | TODO | — |
| DOD-5 | ≥1 điểm truy cập "Twin Data Center" tổng hợp ≥2 nguồn dữ liệu nghiệp vụ | TODO | — |
| DOD-6 | `wp bizcity status --json` trả Scoreboard hợp nhất CORE + PLUGIN | TODO | — |
| DOD-7 | `PLUGIN-STANDARD.md`/`PLUGIN-TWIN-STANDARD.md` trỏ về `docs/vibe/`; không plugin mới nào bỏ qua quy trình sau Wave 7 | TODO | — |
| DOD-8 | Insight được coi là thành công chỉ khi chính xác trong phạm vi snapshot, có citation/link resolve được, lineage đầy đủ, timestamp/window, business record/CPT reference và storage locator đã xác minh | TODO | W10-5..W10-10 + [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md) §7 |

## §D — Gap remediation từ audit code thật (2026-08-29)

Nguồn: [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md).
Các dòng này là "sự thật hiện trạng" cần đóng, không phải task 1 lần — đóng khi
Wave tương ứng ở §B đạt DONE.

| ID | Gap | Đóng bởi Wave | Trạng thái |
|---|---|---|---|
| GAP-1 | Contract SDK (Tool/Skill/Channel/KG-Source-Adapter/...) có 0 production implementation ngoài reference plugin | Wave 1 (W1-5) | OPEN |
| GAP-2 | Vertical brain 100% hardcoded trong `core/twinbrain` (astro/products/woo_bizops/med/law/tax/gov/nutri/scholar/social), Conversation Router bị tắt (`SPECIALIZED_ROUTING_ENABLED=false`) | Wave 8 | OPEN |
| GAP-3 | Tool Intent Matcher là whitelist theo Guru (`BizCity_Guru_Skill_Bridge::tools_for_guru()`), không phải registry mở cho mọi plugin | Wave 8 (đi kèm) | OPEN |
| GAP-4 | KG Hub ingestion là reactive/manual (user tự chọn nguồn), không có đường auto-push từ CRM/Automation/plugin filestore | Wave 9 | OPEN |
| GAP-5 | Parallel SQL stores (`bizcity_webchat_*`, `bizcity_crm_*`) vẫn là canonical song song với KG Hub/Event Stream, chưa hội tụ | Ngoài phạm vi roadmap này — xem [PHASE-1.29 §9.1](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md) | TRACKED ELSEWHERE |
| GAP-6 | "Twin Data Center" (tôn chỉ #2) chưa có 1 điểm truy cập chính thức dù mầm mống đã có (MCP Business/Report/Commerce, CRM `/reports/*`) | Wave 10 | OPEN |

## §E — Checklist áp dụng cho từng plugin/agent

Các checklist từng nằm rải rác trong `02`, `04`, `06` và `ai.md` được quản lý
tại đây. Tài liệu chuyên môn chỉ giải thích lý do và trỏ về các ID dưới đây;
trạng thái chỉ cập nhật ở bảng này.

### Filestore và log contract (`02-FILESTORE-LOG-INDEX-STANDARD.md`)

| ID | Điều kiện | Trạng thái | Bằng chứng |
|---|---|---|---|
| FS-1 | Không có `dbDelta()`/`CREATE TABLE` cho dữ liệu log-shaped | TODO | — |
| FS-2 | Mọi ghi log đi qua `BizCity_JSONL_File_Logger::write_contract()` | TODO | — |
| FS-3 | Durable business record đi qua `BizCity_Business_JSONL_File_Store` | TODO | — |
| FS-4 | Mỗi contract đăng ký đúng 1 lần ở file scope | TODO | — |
| FS-5 | Contract khai báo `retention_days` tường minh | TODO | — |
| FS-6 | Plugin dùng `BizCity_Log_Explorer`, không dựng viewer log riêng | TODO | — |
| FS-7 | Probe có Disk/Loader/Runtime cho contract và ít nhất 1 dòng log thật | TODO | — |

### SSE và Event Stream (`04-SSE-EVENT-STREAM-UNIFICATION.md`)

| ID | Điều kiện | Trạng thái | Bằng chứng |
|---|---|---|---|
| SSE-1 | Không gọi provider AI trực tiếp bằng `wp_remote_post()`/`curl` | TODO | — |
| SSE-2 | Mọi lời gọi LLM dùng `BizCity_LLM_Client` hoặc wrapper modality chuẩn | TODO | — |
| SSE-3 | Không có SSE endpoint chat riêng trong plugin | TODO | — |
| SSE-4 | Tool trả đúng `output_fields` trong manifest, không echo ngoài Runtime | TODO | — |
| SSE-5 | Progress text-only đi qua Progress Notice Projector đúng Zone 2 | TODO | — |

### Taxonomy Act/Channel/View (`06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md`)

| ID | Điều kiện | Trạng thái | Bằng chứng |
|---|---|---|---|
| TAX-1 | Act có idempotency key cho mọi mutation | TODO | — |
| TAX-2 | Act có approval gate cho hành động nhạy cảm | TODO | — |
| TAX-3 | Act có trace ID xuyên request → response | TODO | — |
| TAX-4 | Act có retry bucket + deadline, không retry vô hạn | TODO | — |
| TAX-5 | Act trả outcome/evidence theo Error UX contract | TODO | — |
| TAX-6 | Act ghi CRM qua Repository, không `$wpdb` trực tiếp | TODO | — |
| TAX-7 | Channel có envelope đủ `platform/account_id/user_id/chat_id/message_id` | TODO | — |
| TAX-8 | Channel khai báo zone `guest_channel` hoặc `user_bound` | TODO | — |
| TAX-9 | Channel dùng namespace `bizcity-channel/v1` | TODO | — |
| TAX-10 | Channel gửi outbound qua `BizCity_Gateway_Sender` | TODO | — |
| TAX-11 | Zone 1 channel ghi dữ liệu hội thoại qua CRM Repository | TODO | — |
| TAX-12 | View đăng ký qua Admin Navigation Registry | TODO | — |
| TAX-13 | View không nhúng lại/ghi đè `/gpt` hoặc CRM Inbox | TODO | — |
| TAX-14 | View chỉ load bundle đúng surface theo R-PERF | TODO | — |
| TAX-15 | View dùng Output Renderer contract khi cần render artifact AI | TODO | — |

### AI coding agent onboarding (`ai.md`)

| ID | Điều kiện | Trạng thái | Bằng chứng |
|---|---|---|---|
| AGENT-1 | Manifest hợp lệ theo schema | TODO | — |
| AGENT-2 | Chỉ dùng 7 verb SDK, không require class nội bộ core | TODO | — |
| AGENT-3 | Có đúng 1 taxonomy chính trong manifest | TODO | — |
| AGENT-4 | Log/dữ liệu vận hành qua JSONL + Log Contract Registry | TODO | — |
| AGENT-5 | Tri thức nộp về KG Hub qua KG Source Adapter | TODO | — |
| AGENT-6 | LLM qua wrapper chuẩn và progress qua Twin Event Bus | TODO | — |
| AGENT-7 | Giao tiếp khách hàng qua CRM/Channel Gateway, không chat UI riêng | TODO | — |
| AGENT-8 | `wp bizcity diagnostics plugin <slug> --json` không có FAIL | TODO | — |
| AGENT-9 | Có Diagnostics probe cho runtime behavior | TODO | — |
| AGENT-10 | PHP changes có R-STAMP đúng format | TODO | — |

---

## Changelog

Ghi mỗi lần trạng thái 1 ID thay đổi. Mới nhất ở trên cùng.

```text
2026-08-29 — W5-1..W5-3 DONE: reference plugin có typed KG Source Adapter,
  JSONL contract append và `bizcity_log_index` pointer/hash follow. Probe
  `examples.reference_plugin.wave5` PASS 1/1, exit 0 trên PHP 8.1.34 /
  WordPress 6.9 / blog 1526 với `--skip-provision --skip-network`; source,
  passage và pointer đều queryable. Lần chạy có full provisioning timeout do
  tenant test thiếu `wp_1526_options`, được tách khỏi evidence của slice này.
2026-08-29 — MASTER-CHECKLIST.md được tạo. Toàn bộ checklist từ 12-ROADMAP
  (Wave 0-7 + Definition of Done) được di trú vào đây, thêm Wave 8-10 +
  §D Gap remediation từ audit code thật (Explore subagent, đọc trực tiếp
  core/twinbrain/includes/class-twinbrain-conversation-router.php,
  class-twinbrain-runtime.php, class-twinbrain-tool-intent-matcher.php,
  core/knowledge/kg-hub/, core/mcp/bootstrap.php,
  plugins/bizcity-twin-crm/includes/class-rest-controller.php).
  W0-1 giữ nguyên trạng thái DONE (đã xác nhận trước đó). Mọi ID khác = TODO.
2026-08-29 — W0-1 DONE: xác nhận dùng `wp bizcity make:plugin` thay vì
  `wp twin make:plugin` (Johnny Chu).
2026-08-29 — W10-5..W10-10 và DOD-8 TODO: bổ sung thước đo thành công cho
  Twin Data Center insight — accuracy trong snapshot, citation/link, source
  và plugin, event/trace lineage, timestamp/window, SQL/JSONL/CPT storage
  locator, business record reference, permission scope và reproducibility.
  Contract canonical: 11-SCOREBOARD-AUDIT-FRAMEWORK.md §7.
2026-08-29 — FS-1..FS-7, SSE-1..SSE-5, TAX-1..TAX-15 và AGENT-1..AGENT-10
  TODO: di chuyển 37 checkbox rải rác từ 02/04/06/ai.md về MASTER-CHECKLIST
  để mọi trạng thái được theo dõi tại một nơi duy nhất.
2026-08-29 — W1-6 IN PROGRESS: thêm `BizCity_Typed_Tool_Adapter` vào
  `BizCity_Twin_Tool_Registry` để public `BizCity_Tool_Interface` đi qua
  registry runtime hiện hữu; thêm `TypedToolAdapterTest.php`. Chưa đánh dấu
  W1-5/DOD-1 vì chưa có production plugin implement typed Tool thật.
2026-08-29 — W1-6 mở rộng: adapter nhận thêm
  `BizCity\\Twin\\Contracts\\ToolInterface` từ package Composer SDK; unit
  fixture kiểm tra cả global contract và namespaced distributable contract.
2026-08-29 — W2-1..W2-3 DONE: thêm `taxonomy`, `primary_taxonomy`,
  `diagnostics`, `logging.contracts[]` vào schema + validator + reference
  manifest; sửa lỗi JSON thiếu `]` trong `plugins/bizcoach-pro/manifest.json`.
  Schema/reference parse PASS; validator batch toàn bộ 9 manifest trong
  `PLUGIN-CONTRACT-REGISTRY-v1.json` PASS 9/9, FAIL 0, MISSING 0 bằng PHP
  8.1.34. Không chạy WP runtime vì Wave 2 là static manifest contract.
2026-08-29 — W3-1 DONE, W3-2 IN PROGRESS, W3-3 DONE: nâng
  `bin/bizcity-sdk-scaffold.php` thành generator dùng chung cho plugin/tool/source/event/diagnostic; thêm `BizCity_Framework_CLI_Make` và đăng ký đủ 5 lệnh `wp bizcity make:*`. Temporary scaffold sinh 10 file ban đầu, manifest validator PASS và toàn bộ PHP lint PASS; WP-CLI registration harness PASS 5/5. Sau khi Wave 4 hoàn tất, strict plugin lint PASS 13/13, nên W3-2 đã chuyển DONE.
2026-08-29 — W1 implementation IN PROGRESS: thêm `BizCity_Event_Registry`,
  `BizCity_Twin_Plugin_SDK` facade 7 verb, typed skill/source filters,
  package `PluginSdk` forwarding và migrate 4 built-in memory/ingest tools
  sang typed contract. Thêm unit fixtures cho taxonomy whitelist, seven verbs
  và bốn built-in tools. Chưa đánh dấu DONE vì PHP/PHPUnit runtime unavailable;
  cần chạy unit + WordPress Loader/Runtime DDV trước khi đóng Wave 1.
2026-08-29 — W1 code surface hoàn tất: Content Registry được boot sau khi
  load để consume typed skill/source filters; main plugin load sớm SDK facade
  và Event Taxonomy/Registry. Các W1-1..W1-6 vẫn IN PROGRESS theo rule R-DDV,
  chờ PHPUnit và WordPress Loader/Runtime evidence trước khi chuyển DONE.
2026-08-29 — W1-1..W1-6 DONE: PHP 8.1 PHPUnit full suite PASS (15 tests,
  2026-08-29 — W4-1..W4-6 DONE: plugin diagnostics có quality checks cho Tool
    description, idempotency, event registration và uninstall safety; mọi check
    có `code/message/hint/help_code`; command `wp bizcity plugin lint` delegate
    về shared diagnostics engine. Strict skeleton evidence PASS 13/13, exit 0;
    WP-CLI command harness PASS, exit 0 (PHP 8.1.34, 2026-08-29).
  55 assertions, exit 0) và focused WordPress Diagnostics
  `core.framework.plugin_sdk` PASS (Disk/Loader/Runtime, 1/1 probe, exit 0).
  Probe xác nhận SDK facade/registries loaded, đủ 7 verbs, event canonical
  accepted + unknown rejected, và 4 built-in memory/ingest tools typed trong
  canonical registry. PHP 8.2 không dùng vì thiếu mbstring; PHP 8.1 có đủ
  extension PHPUnit. WordPress runtime có warning/DDL errors nền ngoài slice
  (Aws S3 missing, REQUEST_METHOD notice, hai CRM ALTER definitions); không
  làm fail SDK probe và không được gán là lỗi Wave 1.
```
