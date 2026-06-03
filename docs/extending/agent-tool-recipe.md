# Agent Tool Recipe

> Pattern chuẩn để 1 sub-plugin contribute 1 tool vào Twin Agent function-calling
> registry và được các agent (TwinChat, Guru, Persona) tự dùng.

---

## 1. Anatomy

| Component | Trách nhiệm |
|---|---|
| **Tool class** | Implement `BizCity_Tool_Interface` — id, label, schema, run. |
| **Registration filter** | `add_filter( 'bizcity_twin_register_tool', ... )` — push instance. |
| **Schema** | OpenAI / OpenRouter function-calling JSON schema (định nghĩa params). |
| **`run()`** | Pure function nhận `$args` (đã sanitize) + `$context` (conv_id, user_id…) → trả `array{success,result?,error?}`. |
| **(optional) Diagnostic probe** | Smoke test cho tool — xem [sub-plugin-quickstart.md §7](sub-plugin-quickstart.md#7-diagnostic-row-cho-sub-plugin-của-bạn-r-ddv). |

---

## 2. Schema best practice

```php
public function schema() {
    return [
        'name'        => 'crm_lookup_contact',  // snake_case, prefix module.
        'description' => 'Tra cứu thông tin liên hệ trong CRM theo SĐT hoặc email.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'phone' => [ 'type' => 'string', 'description' => 'SĐT VN (10 số).' ],
                'email' => [ 'type' => 'string', 'format' => 'email' ],
            ],
            'required' => [],   // ít nhất 1 trong 2 — handle ở run().
            'oneOf'    => [
                [ 'required' => [ 'phone' ] ],
                [ 'required' => [ 'email' ] ],
            ],
        ],
    ];
}
```

**Quy tắc:**
- `name` ≤ 64 ký tự, `[a-z0-9_]`. Prefix module để tránh xung đột (vd `crm_lookup_contact`).
- `description` ngắn gọn (≤ 120 chars) — agent đọc để quyết khi nào gọi tool.
- Mỗi field có `description` riêng để agent hiểu ý nghĩa.
- Tránh schema sâu > 3 levels — LLM dễ generate sai.

---

## 3. `run()` — guard rails

```php
public function run( array $args, array $context = [] ) {

    /* 1. Validate manually (schema chỉ dùng cho LLM, KHÔNG enforce server-side). */
    $phone = isset( $args['phone'] ) ? preg_replace( '/\D/', '', (string) $args['phone'] ) : '';
    $email = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
    if ( $phone === '' && $email === '' ) {
        return [ 'success' => false, 'error' => 'phone hoặc email required' ];
    }

    /* 2. Permission — context có user_id nếu agent biết. */
    $user_id = (int) ( $context['user_id'] ?? get_current_user_id() );
    if ( ! user_can( $user_id, 'edit_posts' ) ) {
        return [ 'success' => false, 'error' => 'forbidden' ];
    }

    /* 3. Time budget — agent loop có timeout, tool MUST resolve nhanh. */
    $deadline = microtime( true ) + 8;  // 8 seconds hard cap.

    /* 4. Lookup. */
    $row = $phone !== ''
        ? My_CRM::find_by_phone( $phone )
        : My_CRM::find_by_email( $email );

    if ( ! $row ) {
        return [ 'success' => true, 'result' => null, 'message' => 'Không tìm thấy.' ];
    }

    return [
        'success' => true,
        'result'  => [
            'id'        => (int) $row->id,
            'full_name' => (string) $row->full_name,
            'tier'      => (string) ( $row->tier ?? 'standard' ),
        ],
    ];
}
```

**Anti-patterns CẤM:**
- ❌ Throw exception → agent loop crash. Luôn return `success:false`.
- ❌ Trả PII không cần thiết (mật khẩu, token, full payment details).
- ❌ Side-effect không idempotent (vd send mail) mà không có flag `dry_run`.
- ❌ `wp_remote_*` đến `bizcity.vn` thẳng — dùng `BizCity_LLM_Client`.
- ❌ Phụ thuộc super-global (`$_POST`, `$_REQUEST`) — tool có thể được gọi từ cron/CLI.

---

## 4. Long-running tools

Nếu tool cần > 10s (vd image gen, PDF OCR), trả ngay `job_id` rồi để agent poll:

```php
public function run( array $args, array $context = [] ) {
    $job_id = wp_generate_uuid4();
    wp_schedule_single_event( time() + 1, 'my_async_job', [ $job_id, $args ] );

    return [
        'success'  => true,
        'result'   => [ 'job_id' => $job_id, 'status' => 'queued' ],
        'follow_up' => [
            'tool' => 'my_job_status',  // agent có tool riêng để poll.
            'args' => [ 'job_id' => $job_id ],
        ],
    ];
}
```

---

## 5. Multi-tool conversation

Khi tool A gọi xong, agent có thể tự gọi tool B với output của A. Vì vậy:
- **Output của tool nên có structure (array/object)** thay vì string thô — agent reason chính xác hơn.
- Field `_next_hint` (optional) gợi ý agent: `[ '_next_hint' => 'send to send_message tool' ]`.

---

## 6. Test tool bằng diagnostics probe (thay PHPUnit)

```php
class My_CRM_Lookup_Probe implements BizCity_Diagnostics_Probe {
    public function id() { return 'plugins.my_crm.lookup_smoke'; }
    public function label() { return 'CRM Lookup · Smoke'; }
    public function description() { return 'Probe trả về row khi query phone seed.'; }
    public function severity() { return 'warning'; }
    public function order() { return 700; }
    public function icon() { return 'database'; }
    public function estimate_ms() { return 500; }
    public function precondition() {
        return class_exists( 'My_CRM_Lookup_Tool' )
            ? true
            : new WP_Error( 'tool_missing', 'Tool class chưa load.' );
    }
    public function run( $ctx ) {
        $tool = new My_CRM_Lookup_Tool();
        $r = $tool->run( [ 'phone' => '0900000000' ] );
        return [
            'status'  => ! empty( $r['success'] ) ? 'pass' : 'fail',
            'summary' => json_encode( $r['result'] ?? null ),
            'steps'   => [
                [ 'label' => 'Validate args', 'status' => 'pass' ],
                [ 'label' => 'CRM query',     'status' => ! empty( $r['success'] ) ? 'pass' : 'fail' ],
            ],
        ];
    }
    public function cleanup() {}
}
```

CI pipeline tự `php bin/diagnostics-run.php` → exit 1 nếu probe fail → PR bị chặn.

---

## 7. Reference

- Interface: [core/twin-core/contracts/framework-contracts.php](../../core/twin-core/contracts/framework-contracts.php) → `BizCity_Tool_Interface`.
- Existing tool example: [core/twin-core/includes/tools/class-tool-search-kg.php](../../core/twin-core/includes/tools/class-tool-search-kg.php) (KG search).
- Registry: [core/twin-core/includes/class-twin-tool-registry.php](../../core/twin-core/includes/class-twin-tool-registry.php).
- Hooks: [extension/HOOKS.md §2](../extension/HOOKS.md#2-agent--tool-registry).
