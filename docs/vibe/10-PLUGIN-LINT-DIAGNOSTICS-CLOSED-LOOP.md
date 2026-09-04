# 10 — Plugin Lint + Self-Diagnostic Closed Loop

> **Status:** ✅ WAVE 4 LANDED — nền tảng lint (`wp bizcity diagnostics plugin
> <slug>`, `wp bizcity plugin lint <slug>`, `bin/framework-contract-audit.mjs`,
> `bin/validate-plugin-contract-registry.mjs`,
> `bin/bizcity-manifest-validate.php`) đã tồn tại và chạy được. Việc còn
> lại là mở rộng thêm các rule domain-specific trong các wave sau.

---

## 1. Lệnh đề xuất

```bash
wp bizcity plugin lint customer-insight
```

Lệnh này đã được triển khai trong `BizCity_Framework_CLI_Plugin` và delegate
về `bin/bizcity-plugin-diagnostics.php`; không tạo một diagnostics engine thứ
hai. Cờ `--json` trả các mảng `pass`, `warn`, `fail`; cờ `--strict` biến WARN
thành exit code 1.

Output mục tiêu (đúng format user mô tả):

```text
PLUGIN CONTRACT

✓ manifest
✓ namespace
✓ bootstrap
✓ tool schema
✓ permissions
✓ diagnostics
✓ event registration
✓ uninstall safety

WARN
! Tool has no description for Intent Router
! Missing idempotency declaration

FAIL
✗ Source does not expose provenance
```

## 2. Ánh xạ từng dòng checklist vào validator đã có

| Dòng checklist | Validator hiện có | Ghi chú |
|---|---|---|
| `manifest` | `bin/bizcity-manifest-validate.php` | Parse + validate `twin-plugin.json`/`manifest.json` theo schema |
| `namespace` | `bin/framework-contract-audit.mjs` (một phần) | Kiểm tra class prefix, tránh đụng tên với core/plugin khác |
| `bootstrap` | `wp bizcity diagnostics plugin <slug>` (Loader layer) | Class load được, không fatal, hook đăng ký đúng |
| `tool schema` | `bin/bizcity-manifest-validate.php` | Mỗi tool có `input_fields`/`output_fields`, đúng 1 `primary: true` |
| `permissions` | `bin/validate-plugin-contract-registry.mjs` + `CAPABILITY-SECURITY-v1.md` checklist | Permission khai báo có `scope_level`, không xin quyền thừa |
| `diagnostics` | `bizcity_diagnostics_register_probes` filter check | Plugin có ít nhất 1 probe đăng ký |
| `event registration` | ⛔ ROADMAP — cần rule mới đối chiếu `register_event()` khai báo với event thực tế dispatch | Xem gap ở [07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md) §3.5 |
| `uninstall safety` | `bin/validate-legacy-table-lifecycle.mjs` (pattern tương tự) + checklist [PHASE-1.29 §2](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md) | Plugin optional phải có `uninstall.php` idempotent, không DROP bảng không thuộc sở hữu |

## 3. Runtime evidence — không chỉ static lint

`manifest`/`namespace`/`tool schema` là **static check** (không cần
WordPress). `bootstrap`/`diagnostics`/`event registration` là **runtime
check** — bắt buộc chạy `wp bizcity diagnostics plugin <slug> [--json]
[--strict]` (đã tồn tại trong `bin/twin`/`core/cli`) để có bằng chứng
Disk/Loader/Runtime thật, không được coi static lint PASS là đủ (đúng nguyên
tắc R-DDV áp dụng xuyên suốt framework — xem
[FRAMEWORK-GUIDE-v1.md §10](../framework/FRAMEWORK-GUIDE-v1.md)).

```text
static lint  → PASS/FAIL nhanh, không cần WordPress, chạy trong vài giây
runtime probe → PASS/WARN/FAIL, cần WordPress bootstrap, xác nhận hành vi thật
```

## 4. Các rule quality đã ship ở Wave 4

Hai rule quality đã được thêm vào shared checker
`bin/bizcity-plugin-diagnostics.php`:

1. **"Tool has no description for Intent Router"** — kiểm tra
   `capabilities.tools[].schema.description` không rỗng VÀ đủ dài để Tool
   Intent Matcher (Layer 2.5) có thể match cosine similarity có ý nghĩa (tối
   thiểu ~10 từ, không phải placeholder `"TODO"`).
2. **"Missing idempotency declaration"** — với mọi tool có
   `taxonomy` chứa `act` (mutation/side-effect), yêu cầu field
   `schema.idempotent: true|false` tường minh trong manifest; thiếu field
   này là WARN, khai `false` mà không có lý giải (`idempotency_note`) cũng là
  WARN. Skeleton mới khai báo `idempotency: true` nên pass strict ngay khi
  được sinh.

3. **Event registration** — nếu manifest khai báo event, checker đối chiếu
  event ID với `BizCity_Twin_Plugin_SDK::register_event()` trong source.
4. **Uninstall safety** — plugin phải có `uninstall.php` readable; skeleton
  sinh guard `WP_UNINSTALL_PLUGIN` và không sở hữu bảng mặc định.
5. **Error envelope** — mỗi check trả `code`, `message`, `hint`, `help_code`
  cùng `status/evidence/file` để AI coding agent có thể sửa theo dữ liệu máy.

## 5. Vòng lặp FAIL → AI FIX → PASS

```text
wp bizcity plugin lint <slug> --json
  → agent đọc JSON { "pass": [...], "fail": [...], "warn": [...] }
  → với mỗi "fail" item, agent đọc "hint" (giống R-ERROR-UX: code/message/hint/help_code)
  → agent sửa đúng file được chỉ ra
  → agent chạy lại wp bizcity plugin lint <slug> --json
  → lặp tới khi fail.length === 0
  → chạy wp bizcity diagnostics plugin <slug> --strict --json (runtime)
  → lặp tới khi verdict = PASS
```

Điều kiện bắt buộc để vòng lặp này hoạt động không cần con người: mỗi lỗi lint
phải trả về **đường dẫn file + số dòng (nếu có) + gợi ý sửa cụ thể**, không
chỉ tên rule. Đây là yêu cầu thiết kế bắt buộc khi mở rộng
`bin/framework-contract-audit.mjs`/`bin/bizcity-manifest-validate.php` —
không chỉ trả `false`, phải trả object có `hint`.

## 6. Anti-pattern CẤM

- ❌ AI agent tự sửa checklist (xoá dòng lint đang FAIL) thay vì sửa code.
- ❌ Coi static lint PASS là "plugin đã sẵn sàng cài" mà bỏ qua runtime probe.
- ❌ Viết validator riêng cho 1 plugin cụ thể thay vì mở rộng
  `bin/framework-contract-audit.mjs` chung — mọi rule mới phải áp dụng cho
  MỌI plugin, không phải rule cục bộ.

## 7. Tham chiếu

- `bin/framework-contract-audit.mjs`
- `bin/validate-plugin-contract-registry.mjs`
- `bin/bizcity-manifest-validate.php`
- `core/cli/class-bizcity-framework-cli.php` (`diagnostics plugin <slug>`)
- [PHASE-0-RULE-ERROR-UX.md](../rules/PHASE-0-RULE-ERROR-UX.md)
