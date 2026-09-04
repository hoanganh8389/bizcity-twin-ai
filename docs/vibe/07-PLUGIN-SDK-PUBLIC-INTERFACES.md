# 07 — Twin Plugin SDK: 7 Interface Công Khai

> **Status:** 🟡 MỘT PHẦN — các contract/registry nền đã có; adapter runtime
> cho `BizCity_Tool_Interface` vừa được bổ sung nhưng **production adoption
> vẫn chưa hoàn tất**. `register_event` và facade hợp nhất `register_ui` còn
> là roadmap (đánh dấu ⛔ đúng chỗ bên dưới).

---

## 1. Vì sao chỉ 7 verb

Class map nội bộ của `core/` hiện có **230+ class**. Không thể yêu cầu một
lập trình viên cộng đồng hay một AI coding agent đọc hết class map đó trước
khi viết plugin đầu tiên. Giải pháp: thu hẹp **toàn bộ diện tiếp xúc công
khai** xuống đúng 7 hàm đăng ký (register verbs) — mọi thứ khác là chi tiết
triển khai nội bộ mà plugin không cần biết.

```text
Twin Plugin SDK
├── register_plugin()      → khai báo plugin + manifest
├── register_tool()        → khai báo 1 hành động Tool
├── register_skill()       → khai báo 1 kỹ năng tái sử dụng
├── register_source()      → khai báo 1 nguồn tri thức nạp vào KG Hub
├── register_event()       → khai báo 1 điểm plugin cần lắng nghe/emit event
├── register_diagnostic()  → khai báo probe self-check
└── register_ui()          → khai báo 1 mục điều hướng/workspace
```

Core lo phần còn lại: Intent, Memory, KG, Citation, LLM, Auth, Trace, Logging,
Scheduler, Permissions, unify channel gateway, đảm bảo mọi thứ chảy qua 1 Twin
Event Stream — đúng như liệt kê của user, và đúng với
[00-VIBE-CANON.md](00-VIBE-CANON.md) §4 "Ranh giới quyền sở hữu".

## 2. Ánh xạ 7 verb vào code hiện có

| Verb | Trạng thái | Cơ chế nền hiện có | File |
|---|---|---|---|
| `register_plugin()` | ✅ Đã có (tên khác) | `bizcity_register_module` filter + `BizCity_Module_Registry` | `core/twin-core/contracts/class-module-registry.php` |
| `register_tool()` | ✅ Đã có (tên khác) | `bizcity_twin_register_tool` filter + `BizCity_Tool_Interface` | `core/twin-core/includes/class-twin-tool-registry.php`, `contracts/framework-contracts.php` |
| `register_skill()` | 🟡 Một phần | `BizCity_Skill_Interface` (content contract) đã có, nhưng runtime hiện chủ yếu dựa bảng `bizcity_skills` (DB row), chưa có 1 filter đăng ký PHP thuần tương đương `register_tool()` | `core/twin-core/contracts/content-contracts.php`, `core/skills/` |
| `register_source()` | ✅ Đã có (tên khác) | `BizCity_KG_Source_Adapter_Interface` | `core/twin-core/contracts/content-contracts.php` |
| `register_event()` | ⛔ ROADMAP | Hiện tại: Runtime tự emit event nội bộ qua Twin Event Bus; plugin chỉ có thể *lắng nghe* qua WP action hook đã fire (vd `bizcity_twin_event_dispatched`), KHÔNG có facade để plugin *khai báo* 1 loại event mới của riêng nó theo whitelist R-EVT-2 | Cần: `BizCity_Event_Registry::register_source()` (mới) |
| `register_diagnostic()` | ✅ Đã có (tên khác) | `bizcity_diagnostics_register_probes` filter | `core/diagnostics/bootstrap.php` |
| `register_ui()` | 🟡 Một phần | `BizCity_Admin_Navigation_Registry` đã có cho navigation entry; chưa có facade hợp nhất cho "1 dòng đăng ký cả navigation + output renderer" | `core/twin-core/contracts/class-admin-navigation-registry.php` |

> **Adoption note (2026-08-29):** `BizCity_Twin_Tool_Registry` hiện đã nhận
> object implement `BizCity_Tool_Interface` hoặc
> `BizCity\\Twin\\Contracts\\ToolInterface` qua
> `BizCity_Typed_Tool_Adapter`, nhưng đây mới là compatibility bridge. Chưa
> dùng kết quả này để đánh dấu `W1-5`/`DOD-1` — cần ít nhất một plugin ngoài
> `examples/` implement và chạy typed Tool thật.

## 3. Chi tiết từng verb

### 3.1 `register_plugin()`

```php
add_filter( 'bizcity_register_module', function ( $modules ) {
    $modules[] = new My_Plugin_Module(); // implements BizCity_Module_Interface
    return $modules;
} );
```

Yêu cầu: class implement `BizCity_Module_Interface` (lifecycle: `boot()`,
`requirements()`, `id()`), và có `twin-plugin.json` hợp lệ (xem
[08](08-TWIN-PLUGIN-MANIFEST-SPEC.md)).

### 3.2 `register_tool()`

```php
add_filter( 'bizcity_twin_register_tool', function ( $registry ) {
    $registry['my_plugin.create_order'] = new My_Create_Order_Tool();
    return $registry;
} );
```

Tool PHẢI implement `BizCity_Tool_Interface` — có `schema()` trả về
`description`, `input_fields`, `output_fields` (đúng 3-layer tool model của
[PLUGIN-TWIN-STANDARD.md](../extending/PLUGIN-TWIN-STANDARD.md) §4.1).

### 3.3 `register_skill()`

🟡 Hiện tại: dùng bảng `bizcity_skills` qua `core/skills` (không phải PHP
filter). Cho tới khi facade PHP thuần được ship, plugin implement
`BizCity_Skill_Interface` và đăng ký row qua `BizCity_Skill_Database` API sẵn
có. Roadmap: gói thành filter tương tự `register_tool()` (xem
[12](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md) Wave 1).

### 3.4 `register_source()`

```php
add_filter( 'bizcity_kg_register_source_adapter', function ( $adapters ) {
    $adapters['my_plugin.orders'] = new My_Orders_KG_Source_Adapter();
    return $adapters;
} );
```

> Ghi chú: filter name ở trên là **đề xuất chuẩn hoá** — hiện KG Source
> Adapter interface đã tồn tại nhưng cơ chế đăng ký cụ thể theo từng plugin
> cần audit thêm trước khi coi là filter cố định (xem gap trong
> [12](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md)).

### 3.5 `register_event()` (⛔ ROADMAP)

Đề xuất facade:

```php
BizCity_Event_Registry::register_source( 'my_plugin.order_created', array(
    'schema'      => array( 'order_id' => 'int', 'total' => 'float' ),
    'zone'        => 'act',
    'description' => 'Fires when My Plugin creates a new order.',
) );
```

Không tự `do_action()`/`add_action()` tuỳ tiện cho event nghiệp vụ mới — mọi
`event_type`/`decision.stage` mới đi vào Event Stream phải qua whitelist
R-EVT-2 trước (xem [04-SSE-EVENT-STREAM-UNIFICATION.md](04-SSE-EVENT-STREAM-UNIFICATION.md)
§5). `register_event()` là nơi khai báo ý định đó một cách tường minh, để
Diagnostics kiểm tra thay vì phải đoán.

### 3.6 `register_diagnostic()`

```php
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
    $probes[] = new My_Plugin_Probe(); // Disk / Loader / Runtime checks
    return $probes;
} );
```

### 3.7 `register_ui()`

```php
BizCity_Admin_Navigation_Registry::register( array(
    'id'         => 'my_plugin.workspace',
    'slug'       => 'my-plugin',
    'label'      => 'My Plugin Workspace',
    'capability' => 'manage_options',
    'renderer'   => 'my_plugin.workspace',
) );
```

## 4. Nguyên tắc thiết kế SDK

1. **Opt-in, không bắt buộc migrate plugin cũ** — pattern đã áp dụng cho
   `BizCity_Module_Interface` (PHASE-0.99.2): plugin dùng PHP array thuần vẫn
   chạy được, interface chỉ là lớp type-safety bọc ngoài.
2. **Không dùng lại tên hàm đã tồn tại với ý nghĩa khác.** `register_tool()`
   phải map 1-1 vào `bizcity_twin_register_tool`, không tạo hệ thống song
   song.
3. **Không public hoá thêm interface thứ 8** trừ khi chứng minh được 7 verb
   hiện tại không đủ — mọi nhu cầu mới trước tiên thử nhét vào 1 trong 7 verb
   bằng field mở rộng trong manifest.
4. Diện phân phối là `packages/bizcity-framework-sdk` (Composer) và
   `packages/twin-ui-sdk` (npm) — 2 package này chỉ **re-export/alias** từ
   `core/twin-core/contracts/`, không định nghĩa lại interface (nguồn thật
   duy nhất luôn là `core/twin-core/contracts/`).

## 5. Facade đăng ký chuẩn cho plugin mới

Khi framework runtime đã load, plugin mới dùng facade này thay vì tự gọi
registry nội bộ:

```php
BizCity_Twin_Plugin_SDK::register_tool( new My_Tool() );
BizCity_Twin_Plugin_SDK::register_skill( new My_Skill() );
BizCity_Twin_Plugin_SDK::register_source( new My_KG_Source() );
BizCity_Twin_Plugin_SDK::register_event( 'tool_result', array(
    'source'      => 'tool',
    'owner'       => 'my-plugin',
    'description' => 'My plugin tool result.',
) );
BizCity_Twin_Plugin_SDK::register_diagnostic( new My_Probe() );
BizCity_Twin_Plugin_SDK::register_ui( array(
    'navigation'      => $navigation_item,
    'output_renderer' => new My_Renderer(),
) );
```

`BizCity_Twin_Plugin_SDK` chỉ là facade mỏng: Tool/Skill/Source/Diagnostic
được đưa vào các filter canonical hiện hữu; UI đi qua Navigation Registry và
Content Registry; Event Registry chặn event type ngoài taxonomy. Package
Composer cung cấp cùng 7 method qua `BizCity\\Twin\\Sdk\\PluginSdk` và
fail-graceful khi chạy ngoài WordPress.

Trạng thái implementation/adoption được theo dõi tại
[MASTER-CHECKLIST.md W1-1..W1-6](MASTER-CHECKLIST.md), không cập nhật bằng
checkbox riêng trong tài liệu này.

## 5. Tham chiếu

- `core/twin-core/contracts/framework-contracts.php`
- `core/twin-core/contracts/content-contracts.php`
- `core/twin-core/contracts/class-module-registry.php`
- `core/twin-core/contracts/class-admin-navigation-registry.php`
- [PHASE-1.21-FRAMEWORK-CONSTITUTION-SCHEMA-SDK.md](../roadmaps/PHASE-1.21-FRAMEWORK-CONSTITUTION-SCHEMA-SDK.md) §3
