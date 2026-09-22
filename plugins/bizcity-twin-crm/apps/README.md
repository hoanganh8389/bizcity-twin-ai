# `apps/` — nơi ở của Context App, tách khỏi `includes/`

> Quy ước bổ sung 2026-09-21 (theo yêu cầu làm rõ cấu trúc thư mục). Không đổi quyết định kiến trúc nào của
> [PHASE-0.63B](../docs/PHASE-0.63B-CORE-CONTRACT-CONTEXT-APPS.md) hay
> [PHASE-0.60X master](../docs/PHASE-0.60X-PIPELINE-PLATFORM-MASTER.md) — chỉ nói rõ **implementation của một
> Context App đi vào đâu**, để không lẫn với code framework trong `includes/`.

## Ba nơi, ba vai trò khác nhau — đừng nhầm

| Thư mục | Chứa gì | Ai sở hữu | Đổi có ảnh hưởng ai không |
|---|---|---|---|
| `includes/context/` | **Khung**: contract, `BizCity_CRM_Context_App_Registry`, `BizCity_CRM_Context_Resolver` — thứ quyết định app nào hiện, không phải bản thân app | Làn C0, đóng băng sau đó (master §6) | Có — mọi Context App đọc registry này |
| **`apps/<key>/`** *(thư mục này)* | **Implementation** của một Context App *ship kèm lõi* (không phải plugin riêng) — 5 app của C-07 (`stage`, `ops`, `notes`, `documents`, `activity`) và về sau bất kỳ App-* nào ở PHASE-0.60X §5b có cột Plugin = `lõi` (CRM, Schedule, Work, Suggest) | Mỗi làn App sở hữu đúng một thư mục con của mình | Không — sửa `apps/stage/` không đụng `apps/ops/` |
| `plugins/bizcity-crm-pipeline-<kind>/` | Context App / nghiệp vụ là **plugin độc lập thật sự** (Order, Debt, Profile, Location, Supplier, Contract…) | Làn App tương ứng | Không — tắt cả plugin đó là xong |

**Vì sao tách ra khỏi `includes/`:** `includes/` là 60+ file lõi CRM đã có từ trước 0.63 (channel adapter, capabilities,
repository…) — bỏ thêm 5-10 thư mục app mới vào đó thì không phân biệt được đâu là framework, đâu là một tính năng có thể
tắt/bật độc lập. `apps/` trả lời thẳng câu hỏi "muốn xoá/tắt tính năng X thì xoá thư mục nào" mà không cần đọc code.

## Một app trong `apps/` trông như thế nào

```
apps/
  stage/
    manifest.php     ← bắt buộc, entry point duy nhất loader gọi tới
    class-*.php      ← tuỳ chọn: renderer/REST riêng của app, nếu component không đủ
  ops/
    manifest.php
  ...
```

`manifest.php` **tự đăng ký mình**, đúng khuôn chống giả mạo của `BizCity_CRM_Channel_Registry`
(`includes/inbox/class-channel-registry.php:23-38`) — khoá đăng ký phải khớp `key` khai trong manifest:

```php
<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'bizcity_crm_register_context_apps', function ( array $apps ): array {
	$apps['stage'] = array(
		'contract' => 'context-app', 'version' => '1.0.0',
		'key'      => 'stage',                     // phải khớp key đăng ký (chốt chặn giả mạo)
		'label'    => 'Dải bước',
		'provider' => '',                            // rỗng = app lõi, không thuộc plugin nào (C-07)
		'applies_to' => array( 'subject_roles' => array( '*' ), 'pipeline_kinds' => array() ),
		'surfaces' => array( 'b2', 'c' ),
		'position' => 10,
		'render'   => array( 'type' => 'component', 'id' => 'StagePanel' ),
	);
	return $apps;
} );
```

Hình dạng đầy đủ của manifest: xem [0.63B §6.1](../docs/PHASE-0.63B-CORE-CONTRACT-CONTEXT-APPS.md#61-manifest) và contract
`context-app@1.0.0` (làn C0 viết). **Không tự chế thêm trường** ngoài những gì contract đó khai.

## Loader tự nạp — không sửa `bootstrap.php`/`bootstrap-pipeline.php`

`includes/pipeline/bootstrap-pipeline.php` (làn S) đã `glob()` mọi `apps/*/manifest.php` và `require` qua
`BizCity_Safe_Loader` — tắt/thiếu một app không làm sập CRM. Thêm một app mới = tạo thư mục con + `manifest.php`, không
đụng file loader.

## Plugin nghiệp vụ độc lập có nên bắt chước cấu trúc này?

Khuyến khích nhưng không bắt buộc: nếu `plugins/bizcity-crm-pipeline-sales/` (hay bất kỳ plugin nghiệp vụ nào) cũng muốn
tách "app implementation" khỏi "include nội bộ của chính plugin đó", dùng cùng quy ước `apps/<key>/manifest.php` bên trong
plugin của mình. Đây là quy ước đặt tên, không phải một cơ chế nạp dùng chung — mỗi plugin tự `require` file của nó.
