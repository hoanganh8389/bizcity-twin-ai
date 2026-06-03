# Sub-Plugin Quickstart

> Build 1 sub-plugin "hello-world" plug vào BizCity Twin AI framework. Mất 3 phút.

---

## 1. Tạo file plugin

`wp-content/plugins/my-bizcity-tool/my-bizcity-tool.php`:

```php
<?php
/**
 * Plugin Name: My BizCity Tool
 * Description: Hello-world sub-plugin extending BizCity Twin AI.
 * Version:     0.1.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/* Boot sau khi framework đã load contracts. */
add_action( 'plugins_loaded', function() {

    if ( ! class_exists( 'BizCity_Module_Base' ) ) {
        // Framework chưa cài — fail-OPEN, KHÔNG break site.
        return;
    }

    require_once __DIR__ . '/class-my-module.php';
    require_once __DIR__ . '/class-greeting-tool.php';

    ( new My_BizCity_Module() )->boot();

}, 20 );
```

---

## 2. Module class

`class-my-module.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

class My_BizCity_Module extends BizCity_Module_Base {

    protected $module_id      = 'plugins.my_bizcity_tool';
    protected $module_version = '0.1.0';
    protected $module_requires = [
        'php'       => '7.4',
        'wp'        => '6.0',
        'framework' => '1.0.0',
    ];

    protected function register() {
        // Đăng ký REST routes ở đây.
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Plug 1 tool vào Twin Agent registry.
        add_filter( 'bizcity_twin_register_tool', [ $this, 'register_tools' ] );
    }

    public function register_routes() {
        register_rest_route( 'my-bizcity-tool/v1', '/hello', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function() {
                return [ 'success' => true, 'message' => 'Hello from sub-plugin!' ];
            },
        ] );
    }

    /**
     * @param array $list Existing tools.
     * @return array
     */
    public function register_tools( $list ) {
        if ( class_exists( 'My_Greeting_Tool' ) ) {
            $list[] = new My_Greeting_Tool();
        }
        return $list;
    }
}
```

---

## 3. Tool class

`class-greeting-tool.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

class My_Greeting_Tool implements BizCity_Tool_Interface {

    public function id() { return 'my.greeting'; }

    public function label() { return 'My Greeting Tool'; }

    public function schema() {
        return [
            'name'        => 'my_greeting',
            'description' => 'Trả về lời chào cá nhân hoá theo tên.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Tên người dùng',
                    ],
                ],
                'required' => [ 'name' ],
            ],
        ];
    }

    public function run( array $args, array $context = [] ) {
        $name = isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
        if ( $name === '' ) {
            return [ 'success' => false, 'error' => 'name required' ];
        }

        return [
            'success' => true,
            'result'  => sprintf( 'Xin chào %s 👋 từ My BizCity Tool!', sanitize_text_field( $name ) ),
        ];
    }
}
```

---

## 4. Test

1. Activate plugin trong WP Admin.
2. Mở **Tools → BizCity Diagnostics** → chạy probe `agent.tool_registry` → tool `my.greeting` xuất hiện trong list.
3. Trong TwinChat, chat: *"Hãy chào tôi với tên Anh"*. Agent sẽ tự gọi tool `my_greeting` qua function-calling và trả lại kết quả.
4. Verify REST: `curl https://your-site.test/wp-json/my-bizcity-tool/v1/hello`.

---

## 5. Khi cần gọi LLM trong tool của bạn

```php
public function run( array $args, array $context = [] ) {
    if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
        return [ 'success' => false, 'error' => 'gateway_unavailable', '_degraded' => true ];
    }
    $llm = BizCity_LLM_Client::instance();
    if ( ! $llm->is_ready() ) {
        return [ 'success' => false, 'error' => 'api_key_missing', '_degraded' => true ];
    }

    $resp = $llm->chat( [
        [ 'role' => 'user', 'content' => 'Tóm tắt: ' . $args['text'] ],
    ], [ 'purpose' => 'fast', 'timeout' => 30 ] );

    return [ 'success' => true, 'result' => $resp['message'] ?? '' ];
}
```

> 🛑 **Anti-pattern:** đừng `wp_remote_post('https://bizcity.vn/...')` thẳng. Dùng wrapper `BizCity_LLM_Client` để được Bearer auth + retry + fail-OPEN tự động. Xem [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md) §R-GW-8.

---

## 6. Cần API mới chưa có?

Đọc workflow R-GW-API-CATALOG trong [.github/copilot-instructions.md](../../.github/copilot-instructions.md#R-GW-API-CATALOG):
1. Lookup [bizcity-llm-router/docs/api/README.md](../../../bizcity-llm-router/docs/api/README.md) §2 (12 branches).
2. Có endpoint nhưng thiếu wrapper → bổ sung vào `core/bizcity-llm/includes/class-*-client.php`.
3. Thiếu endpoint → mở issue trên GitHub `bizcity-llm-router` để team build (KHÔNG fork tự build endpoint trên client).

---

## 7. Diagnostic row cho sub-plugin của bạn (R-DDV)

```php
// class-greeting-tool-probe.php
class My_Greeting_Tool_Probe implements BizCity_Diagnostics_Probe {
    public function id() { return 'plugins.my_bizcity_tool.smoke'; }
    public function label() { return 'My Greeting Tool · Smoke'; }
    public function description() { return 'Verify tool callable end-to-end.'; }
    public function severity() { return 'warning'; }
    public function order() { return 800; }
    public function icon() { return 'sparkles'; }
    public function estimate_ms() { return 200; }
    public function precondition() { return true; }
    public function run( $ctx ) {
        $tool = new My_Greeting_Tool();
        $r = $tool->run( [ 'name' => 'Smoke' ] );
        return [
            'status'  => ! empty( $r['success'] ) ? 'pass' : 'fail',
            'summary' => $r['result'] ?? ( $r['error'] ?? '' ),
        ];
    }
    public function cleanup() { /* no-op */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function( $list ) {
    $list[] = 'My_Greeting_Tool_Probe';
    return $list;
} );
```

---

## Tham khảo

- Scaffold đầy đủ: [scaffold/bizcity-{slug}.php](../../scaffold/bizcity-%7Bslug%7D.php)
- Hooks catalog: [extension/HOOKS.md](../extension/HOOKS.md)
- Agent tool sâu hơn: [agent-tool-recipe.md](agent-tool-recipe.md)
