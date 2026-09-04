# 09 — CLI Scaffolding: `wp bizcity make:*`

> **Status:** 🟡 MỘT PHẦN — namespace đã **CHỐT** và 5 command đã được
> đăng ký; engine scaffold dùng chung đã landed. Việc còn thiếu là runtime
> hardening và plugin lint hợp nhất (W3-2/W4-6), theo dõi tại
> [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md).

---

## 1. Namespace: `wp bizcity` (đã CHỐT, không phải `wp twin`)

> **Quyết định 2026-08-29 (Johnny Chu):** xác nhận dùng `wp bizcity make:plugin`
> thay vì `wp twin make:plugin`. Mục này không còn là đề xuất chờ sign-off —
> mọi wave code từ đây trở đi PHẢI dùng namespace `bizcity`.

Yêu cầu gốc đề xuất cú pháp `wp twin make:plugin customer-insight`. Tuy nhiên,
[FRAMEWORK-GUIDE-v1.md §1.3](../framework/FRAMEWORK-GUIDE-v1.md) và
[PHASE-1.31-WP-CLI-BIZCITY-COMMAND-FAMILY.md](../roadmaps/PHASE-1.31-WP-CLI-BIZCITY-COMMAND-FAMILY.md)
đã chốt: **root namespace WP-CLI là `bizcity`**, hiện thực tại
`core/cli/class-bizcity-framework-cli.php` với các họ lệnh đã đăng ký
(`bizcity status`, `bizcity diagnostics`, `bizcity tools`, `bizcity cron`,
`bizcity tool-index`, `bizcity knowledge`, `bizcity memory`,
`bizcity contracts`, `bizcity brain`).

Tạo thêm một binary/namespace `wp twin` song song sẽ vi phạm chính nguyên tắc
"không tạo 2 pipeline song song cho cùng 1 khái niệm" (`PHASE-1.31` §"reuse
the diagnostics-verdict contract, no parallel Diagnostics engine"). Vì vậy:

> **Quy ước chính thức của tài liệu này:** mọi ví dụ `wp twin make:*` trong
> yêu cầu gốc của founder được hiện thực hoá thành **`wp bizcity make:*`**.
> `php bin/twin` (không có prefix `wp`) vẫn là 1 cửa CLI độc lập không cần
> WordPress cho phần `validate`/`test`/`doctor`/`inspect` — hai cửa này không
> mâu thuẫn: `bin/twin` dùng khi KHÔNG có WordPress runtime; `wp bizcity`
> dùng khi CÓ WordPress runtime (cần tạo file thật trong `wp-content/plugins/`).

## 2. Lệnh scaffold chính thức

```bash
wp bizcity make:plugin customer-insight
wp bizcity make:tool analyze_customer --plugin=customer-insight
wp bizcity make:source crm --plugin=customer-insight
wp bizcity make:event order_created --plugin=customer-insight
wp bizcity make:diagnostic --plugin=customer-insight
```

### 2.1 `make:plugin` — sinh skeleton đầy đủ

```text
customer-insight/
├── twin-plugin.json
├── customer-insight.php        ← WordPress plugin header + register_plugin()
├── src/
│   ├── Tool.php                ← implements BizCity_Tool_Interface (rỗng, TODO)
│   ├── Source.php               ← implements BizCity_KG_Source_Adapter_Interface (rỗng, TODO)
│   └── Events.php               ← khai báo register_event() (rỗng, TODO)
├── diagnostics/
│   └── probes.php               ← 1 probe mẫu Disk/Loader/Runtime
├── tests/
│   └── smoke.php                ← fixture test tối thiểu
└── README.md
```

### 2.2 `make:tool` / `make:source` / `make:event` / `make:diagnostic`

Sinh thêm 1 file mới vào `src/`/`diagnostics/` của plugin đã có, tự động thêm
dòng đăng ký tương ứng vào bootstrap — không yêu cầu người dùng tự nhớ cú
pháp filter.

## 3. Nền tảng đã có để build trên đó (không viết lại từ đầu)

| Cần | Đã có | Ghi chú |
|---|---|---|
| Cơ chế CLI hợp nhất, cùng verdict JSON | `bin/twin` (`doctor/validate/test/diagnostics/inspect`), `core/cli/class-bizcity-framework-cli.php` (`wp bizcity ...`) | Thêm dispatcher mới `BizCity_Framework_CLI_Make` theo đúng pattern các dispatcher hiện có (`BizCity_Framework_CLI_Tools`, `..._Cron`, ...) |
| Script sinh code có sẵn | `bin/bizcity-sdk-scaffold.php` | Kiểm tra lại xem đã cover đủ 5 lệnh `make:*` hay chỉ scaffold tổng thể — nếu thiếu, mở rộng script này thay vì viết file mới song song |
| Template thư mục mẫu | `scaffold/` (`bizcity-{slug}.php`, `includes/class-intent-provider.php`, `includes/class-tools-sample.php`...) | `scaffold/` hiện theo chuẩn 3-trụ-cột cũ (`PLUGIN-STANDARD.md`) — cần đối chiếu và có thể tái dùng phần view/admin-menu, còn phần Tool/Source đổi sang implement interface mới |
| Plugin mẫu implement đủ 8 capability | `examples/bizcity-reference-plugin/` | Dùng làm "vàng chuẩn" khi sinh nội dung file skeleton — không tự nghĩ ra cấu trúc khác |
| Validator chạy ngay sau scaffold | `bin/bizcity-manifest-validate.php` | `make:plugin` nên tự động chạy validator này ngay sau khi sinh xong, báo PASS/FAIL luôn |

## 4. Nguyên tắc AI-agent-first khi scaffold

> *"Như vậy AI không cần sáng tác architecture, tiết kiệm rất nhiều token,
> giảm thiểu rủi ro ảo giác, chỉ điền logic vào skeleton chuẩn."*

Điều này đặt ra yêu cầu cụ thể cho việc build `make:*`:

1. Mỗi file sinh ra phải có **TODO comment rõ ràng** tại đúng vị trí cần điền
   logic nghiệp vụ (vd `// TODO: implement fetch_candidates() — trả về mảng
   passage thô từ nguồn dữ liệu của bạn`).
2. Không sinh code "đoán" logic nghiệp vụ — chỉ sinh cấu trúc + type hint +
   docblock mô tả input/output mong đợi.
3. File sinh ra phải PASS lint (xem [10](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md))
   ngay ở trạng thái rỗng (trước khi AI điền logic) — nếu skeleton mặc định
   đã FAIL lint, đó là lỗi của SDK, không phải lỗi của AI agent.

## 5. Tham chiếu

- `bin/twin`
- `bin/bizcity-sdk-scaffold.php`
- `core/cli/class-bizcity-framework-cli.php`
- [PHASE-1.31-WP-CLI-BIZCITY-COMMAND-FAMILY.md](../roadmaps/PHASE-1.31-WP-CLI-BIZCITY-COMMAND-FAMILY.md)
- `scaffold/`, `examples/bizcity-reference-plugin/`
