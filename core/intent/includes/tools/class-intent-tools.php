<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Tool Registry
 *
 * Central registry for all executable tools.
 * Other plugins register their tools via:
 *   add_action('bizcity_intent_register_tools', function($registry) {
 *       $registry->register('create_product', [
 *           'description' => '...',
 *           'input_fields' => [ 'title' => 'required', 'price' => 'required', ... ],
 *           'hil_questions' => [ 'title' => 'Tên sản phẩm?', ... ],
 *       ], 'ClassName::method');
 *   });
 *
 * Tool callbacks receive (array $slots) and return:
 *   [ 'success' => bool, 'message' => '...', 'data' => [...], 'missing_fields' => [...] ]
 *
 * If a tool returns 'missing_fields', the engine transitions the conversation
 * to WAITING_USER for those fields.
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Tools {

    /** @var self|null */
    private static $instance = null;

    /**
     * Registered tools.
     * Structure: [ 'tool_name' => [ 'schema' => [...], 'callback' => callable ] ]
     *
     * @var array
     */
    private $tools = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Built-in tools will be registered in init_builtin()
        add_action( 'init', [ $this, 'init_builtin' ], 20 );
    }

    /**
     * Register built-in tools that bridge to existing BizCity functions.
     *
     * IMPORTANT: Provider plugins register their own tools during
     * `plugins_loaded` (via bizcity_intent_register_providers → boot()).
     * This runs later on `init` priority 20. Built-in tools are fallback
     * bridges only — if a provider already registered a more specialized
     * version, we SKIP the built-in.
     */
    public function init_builtin() {
        // ── Tool: create_product → bridges to twf_handle_product_post_flow ──
        if ( ! $this->has( 'create_product' ) ) {
        $this->register( 'create_product', [
            'description'  => 'Tạo sản phẩm mới trên WooCommerce/website',
            'input_fields' => [
                'title'       => [ 'required' => true,  'type' => 'text' ],
                'price'       => [ 'required' => true,  'type' => 'number' ],
                'description' => [ 'required' => false, 'type' => 'text' ],
                'image_url'   => [ 'required' => false, 'type' => 'image' ],
            ],
        ], [ $this, 'builtin_create_product' ] );
        }

        // ── Tool: generate_report → bridges to twf_handle_ai_json_report ──
        if ( ! $this->has( 'generate_report' ) ) {
        $this->register( 'generate_report', [
            'description'  => 'Tạo báo cáo kinh doanh',
            'input_fields' => [
                'report_type' => [ 'required' => true,  'type' => 'choice' ],
                'date_range'  => [ 'required' => false, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_generate_report' ] );
        }

        // ── Tool: inventory_report ── (read-only → auto_execute)
        if ( ! $this->has( 'inventory_report' ) ) {
        $this->register( 'inventory_report', [
            'description'  => 'Báo cáo xuất nhập tồn kho',
            'auto_execute' => true,
            'input_fields' => [
                'so_ngay'   => [ 'required' => false, 'type' => 'number', 'default' => 7 ],
                'from_date' => [ 'required' => false, 'type' => 'text' ],
                'to_date'   => [ 'required' => false, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_inventory_report' ] );
        }

        // ── Tool: post_facebook → bridges to twf_handle_facebook_multi_page_post ──
        if ( ! $this->has( 'post_facebook' ) ) {
        $this->register( 'post_facebook', [
            'description'  => 'Đăng bài lên Facebook',
            'input_fields' => [
                'content'   => [ 'required' => true,  'type' => 'text' ],
                'image_url' => [ 'required' => false, 'type' => 'image' ],
            ],
        ], [ $this, 'builtin_post_facebook' ] );
        }

        // ── Tool: write_article → bridges to twf_handle_post_request ──
        if ( ! $this->has( 'write_article' ) ) {
        $this->register( 'write_article', [
            'description'  => 'Viết và đăng bài lên website',
            'input_fields' => [
                'topic'     => [ 'required' => true,  'type' => 'text' ],
                'image_url' => [ 'required' => false, 'type' => 'text' ],
                'tone'      => [ 'required' => false, 'type' => 'choice' ],
                'length'    => [ 'required' => false, 'type' => 'choice' ],
            ],
        ], [ $this, 'builtin_write_article' ] );
        }

        // ── Tool: set_reminder → bridges to twf_create_biztask_from_ai ──
        if ( ! $this->has( 'set_reminder' ) ) {
        $this->register( 'set_reminder', [
            'description'  => 'Tạo nhắc việc / công việc',
            'input_fields' => [
                'what'   => [ 'required' => true,  'type' => 'text' ],
                'when'   => [ 'required' => true,  'type' => 'text' ],
                'repeat' => [ 'required' => false, 'type' => 'choice' ],
            ],
        ], [ $this, 'builtin_set_reminder' ] );
        }

        // ── Tool: create_video → bridges to bizcity-video-kling ──
        if ( ! $this->has( 'create_video' ) ) {
        $this->register( 'create_video', [
            'description'  => 'Tạo video bằng AI (Kling)',
            'input_fields' => [
                'content'      => [ 'required' => true,  'type' => 'text' ],
                'title'        => [ 'required' => false, 'type' => 'text' ],
                'duration'     => [ 'required' => false, 'type' => 'number', 'default' => 5 ],
                'aspect_ratio' => [ 'required' => false, 'type' => 'choice', 'default' => '9:16' ],
                'image_url'    => [ 'required' => false, 'type' => 'image' ],
            ],
        ], [ $this, 'builtin_create_video' ] );
        }

        // ── Tool: edit_product → bridges to twf_handle_edit_product_flow ──
        if ( ! $this->has( 'edit_product' ) ) {
        $this->register( 'edit_product', [
            'description'  => 'Sửa sản phẩm trên website',
            'input_fields' => [
                'product_id' => [ 'required' => true,  'type' => 'text' ],
                'field'      => [ 'required' => false, 'type' => 'text' ],
                'new_value'  => [ 'required' => false, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_edit_product' ] );
        }

        // ── Tool: create_order → bridges to twf_handle_create_order_ai_flow ──
        if ( ! $this->has( 'create_order' ) ) {
        $this->register( 'create_order', [
            'description'  => 'Tạo đơn hàng mới',
            'input_fields' => [
                'customer_name' => [ 'required' => true,  'type' => 'text' ],
                'products'      => [ 'required' => true,  'type' => 'text' ],
                'phone'         => [ 'required' => false, 'type' => 'text' ],
                'note'          => [ 'required' => false, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_create_order' ] );
        }

        // ── Tool: list_orders → bridges to twf_telegram_order_list_report2 ── (read-only → auto_execute)
        if ( ! $this->has( 'list_orders' ) ) {
        $this->register( 'list_orders', [
            'description'  => 'Xem danh sách đơn hàng',
            'auto_execute' => true,
            'input_fields' => [
                'date_range'    => [ 'required' => false, 'type' => 'text' ],
                'status_filter' => [ 'required' => false, 'type' => 'choice' ],
            ],
        ], [ $this, 'builtin_list_orders' ] );
        }

        // ── Tool: find_customer → bridges to twf_handle_find_customer_order_by_phone ──
        if ( ! $this->has( 'find_customer' ) ) {
        $this->register( 'find_customer', [
            'description'  => 'Tìm khách hàng theo SĐT/tên',
            'input_fields' => [
                'search_term' => [ 'required' => true, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_find_customer' ] );
        }

        // ── Tool: customer_stats ── (read-only → auto_execute)
        if ( ! $this->has( 'customer_stats' ) ) {
        $this->register( 'customer_stats', [
            'description'  => 'Thống kê top khách hàng',
            'auto_execute' => true,
            'input_fields' => [
                'so_ngay' => [ 'required' => false, 'type' => 'number', 'default' => 7 ],
            ],
        ], [ $this, 'builtin_customer_stats' ] );
        }

        // ── Tool: product_stats ── (read-only → auto_execute)
        if ( ! $this->has( 'product_stats' ) ) {
        $this->register( 'product_stats', [
            'description'  => 'Thống kê hàng hóa bán chạy',
            'auto_execute' => true,
            'input_fields' => [
                'so_ngay' => [ 'required' => false, 'type' => 'number', 'default' => 7 ],
            ],
        ], [ $this, 'builtin_product_stats' ] );
        }

        // ── Tool: inventory_journal ──
        if ( ! $this->has( 'inventory_journal' ) ) {
        $this->register( 'inventory_journal', [
            'description'  => 'Nhật ký xuất nhập kho',
            'input_fields' => [
                'from_date' => [ 'required' => false, 'type' => 'text' ],
                'to_date'   => [ 'required' => false, 'type' => 'text' ],
                'so_ngay'   => [ 'required' => false, 'type' => 'number', 'default' => 7 ],
            ],
        ], [ $this, 'builtin_inventory_journal' ] );
        }

        // ── Tool: warehouse_receipt ──
        if ( ! $this->has( 'warehouse_receipt' ) ) {
        $this->register( 'warehouse_receipt', [
            'description'  => 'Tạo phiếu nhập kho',
            'input_fields' => [
                'content' => [ 'required' => true, 'type' => 'text' ],
            ],
        ], [ $this, 'builtin_warehouse_receipt' ] );
        }

        // ── Tool: help_guide ── (read-only → auto_execute)
        if ( ! $this->has( 'help_guide' ) ) {
        $this->register( 'help_guide', [
            'description'  => 'Hướng dẫn sử dụng',
            'auto_execute' => true,
            'input_fields' => [
                'topic' => [ 'required' => false, 'type' => 'choice', 'default' => 'tat_ca' ],
            ],
        ], [ $this, 'builtin_help_guide' ] );
        }

        // Note: bizcity_intent_tools_ready hook is now fired from
        // Provider Registry boot() at init:25 (after this method + DB sync).
        // This ensures all late-registered tools are synced to DB.
    }

    /* ================================================================
     *  Registration
     * ================================================================ */

    /**
     * Register a tool.
     *
     * @param string   $name     Unique tool name.
     * @param array    $schema   {
     *   @type string $description   What the tool does.
     *   @type array  $input_fields  [ field_name => [ 'required'=>bool, 'type'=>'...' ] ]
     *   @type array  $output_fields Optional output field descriptions.
     * }
     * @param callable $callback function(array $slots): array
     */
    public function register( $name, array $schema, $callback ) {
        $this->tools[ $name ] = [
            'schema'   => $schema,
            'callback' => $callback,
        ];
    }

    /**
     * Unregister a tool.
     *
     * @param string $name
     */
    public function unregister( $name ) {
        unset( $this->tools[ $name ] );
    }

    /**
     * Check if a tool is registered.
     *
     * @param string $name
     * @return bool
     */
    public function has( $name ) {
        return isset( $this->tools[ $name ] );
    }

    /**
     * Get tool schema.
     *
     * @param string $name
     * @return array|null
     */
    public function get_schema( $name ) {
        return isset( $this->tools[ $name ] ) ? $this->tools[ $name ]['schema'] : null;
    }

    /**
     * Get tool callback.
     *
     * @param string $name Tool name.
     * @return callable|null
     */
    public function get_callback( $name ) {
        return $this->tools[ $name ]['callback'] ?? null;
    }

    /**
     * Check if a tool declares auto_execute (skip confirm for read-only tools).
     *
     * @param string $name Tool name.
     * @return bool
     */
    public function is_auto_execute( $name ) {
        $schema = $this->get_schema( $name );
        return ! empty( $schema['auto_execute'] );
    }

    /**
     * Get tool source (for execution logging + provider classification).
     *
     * S8 fix: distinguish core atomic tools (bizcity_atomic_* callbacks)
     * from plugin tools for SmartClassifier tool filtering.
     *
     * @param string $name Tool name.
     * @return string 'built_in' | 'plugin' | 'provider' | 'unknown'
     */
    public function get_tool_source( $name ) {
        if ( ! isset( $this->tools[ $name ] ) ) {
            return 'unknown';
        }

        $callback = $this->tools[ $name ]['callback'];

        // Check if it's a built-in (method on this class)
        if ( is_array( $callback ) && $callback[0] === $this ) {
            return 'built_in';
        }

        // Check if it's from a provider (class name contains 'Provider')
        if ( is_array( $callback ) && is_object( $callback[0] ) ) {
            $class_name = get_class( $callback[0] );
            if ( strpos( $class_name, 'Provider' ) !== false ) {
                return 'provider';
            }
        }

        // S8: Core atomic tools use bizcity_atomic_* callback naming convention
        // (registered in core/tools/*/bootstrap.php). Treat as built-in.
        if ( is_string( $callback ) && strpos( $callback, 'bizcity_atomic_' ) === 0 ) {
            return 'built_in';
        }

        return 'plugin';
    }

    /**
     * List all registered tools.
     *
     * @return array [ name => schema ]
     */
    public function list_all() {
        $result = [];
        foreach ( $this->tools as $name => $tool ) {
            $result[ $name ] = $tool['schema'];
        }
        return $result;
    }

    /* ================================================================
     *  Execution
     * ================================================================ */

    /**
     * Execute a tool with given slots.
     *
     * @param string $name  Tool name.
     * @param array  $slots Input parameters.
     * @return array {
     *   @type bool   $success
     *   @type string $message       Human-readable result.
     *   @type array  $data          Structured output data.
     *   @type array  $missing_fields  Fields still needed (tool can request more info).
     * }
     */
    public function execute( $name, array $slots ) {
        if ( ! $this->has( $name ) ) {
            return [
                'success'        => false,
                'message'        => "Tool '{$name}' không được tìm thấy.",
                'data'           => [],
                'missing_fields' => [],
            ];
        }

        $tool = $this->tools[ $name ];

        // Validate required fields
        $missing = $this->validate_inputs( $name, $slots );
        if ( ! empty( $missing ) ) {
            return [
                'success'        => false,
                'message'        => 'Thiếu thông tin: ' . implode( ', ', $missing ),
                'data'           => [],
                'missing_fields' => $missing,
            ];
        }

        // ── Inject _trace context so tool callbacks can report progress ──
        // Callbacks access: $slots['_trace'] (BizCity_Job_Trace instance or null)
        // If the tool callback creates its own trace via BizCity_Job_Trace::start(),
        // the _trace slot provides session_id for convenience.
        $session_id_for_trace = $slots['session_id'] ?? ( $slots['_meta']['session_id'] ?? '' );
        $slots['_trace_session_id'] = $session_id_for_trace;

        // Execute callback
        try {
            $callback = $tool['callback'];
            if ( is_callable( $callback ) ) {
                $result = call_user_func( $callback, $slots );
            } else {
                return [
                    'success'        => false,
                    'message'        => "Tool '{$name}' callback không hợp lệ.",
                    'data'           => [],
                    'missing_fields' => [],
                ];
            }

            // Normalize result
            if ( ! is_array( $result ) ) {
                $result = [
                    'success' => true,
                    'message' => (string) $result,
                    'data'    => [],
                ];
            }
            if ( ! isset( $result['missing_fields'] ) ) {
                $result['missing_fields'] = [];
            }

            // Sprint 1E: Warn-only output convention check for pipeline chaining readiness.
            // Tools SHOULD include data.type (+ data.id for write ops) so multi-step
            // pipelines can reference outputs. Warn now, enforce later.
            if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
                if ( empty( $result['data']['type'] ) ) {
                    // Auto-fill data.type from tool name as bootstrap convention
                    $result['data']['type'] = $name;
                    error_log( "[tool-output-convention] Tool '{$name}' missing data.type — auto-filled from tool name" );
                }
            }

            // ── Auto-complete any active trace that the callback forgot to close ──
            $active_trace = BizCity_Job_Trace::current();
            if ( $active_trace && $active_trace->get_status() === 'running' ) {
                if ( ! empty( $result['success'] ) ) {
                    $active_trace->complete( $result['data'] ?? [] );
                } else {
                    $active_trace->fail( $result['message'] ?? 'Unknown error' );
                }
            }

            // ── Phase 1: Auto-save evidence CPT after successful execution ──
            if ( ! empty( $result['success'] ) && class_exists( 'BizCity_Tool_Evidence' ) ) {
                $evidence_context = [
                    'session_id'  => $slots['session_id'] ?? ( $slots['_meta']['session_id'] ?? '' ),
                    'pipeline_id' => $slots['_pipeline_id'] ?? '',
                    'step_index'  => $slots['_step_index'] ?? null,
                    'user_id'     => $slots['user_id'] ?? get_current_user_id(),
                ];
                $evidence_id = BizCity_Tool_Evidence::save( $name, $result, $evidence_context );
                if ( $evidence_id && is_array( $result['data'] ?? null ) ) {
                    $result['data']['evidence_id'] = $evidence_id;
                }
            }

            return $result;

        } catch ( \Exception $e ) {
            error_log( "[BizCity_Intent_Tools] Error executing '{$name}': " . $e->getMessage() );

            // ── Auto-fail any active trace on exception ──
            $active_trace = BizCity_Job_Trace::current();
            if ( $active_trace && $active_trace->get_status() === 'running' ) {
                $active_trace->fail( $e->getMessage() );
            }

            return [
                'success'        => false,
                'message'        => 'Lỗi khi thực hiện: ' . $e->getMessage(),
                'data'           => [],
                'missing_fields' => [],
            ];
        }
    }

    /* ================================================================
     *  Execute with Preconfirm (Phase 1)
     * ================================================================ */

    /**
     * Execute a tool with preconfirm flow.
     *
     * Returns a preconfirm request if the tool requires user confirmation,
     * or executes directly if auto_execute is set or _confirmed is present.
     *
     * @param string $name           Tool name.
     * @param array  $slots          Input parameters.
     * @param array  $session_context Pipeline context.
     * @return array
     */
    public function execute_with_preconfirm( $name, array $slots, $session_context = [] ) {
        if ( ! $this->has( $name ) ) {
            return [
                'success' => false,
                'message' => "Tool '{$name}' không được tìm thấy.",
                'data'    => [],
            ];
        }

        // 1. Validate required fields
        $missing = $this->validate_inputs( $name, $slots );
        if ( ! empty( $missing ) ) {
            return [
                'success'        => false,
                'action'         => 'ask_user',
                'message'        => 'Cần bổ sung: ' . implode( ', ', $missing ),
                'missing_fields' => $missing,
                'current_slots'  => $slots,
            ];
        }

        // 2. Check auto_execute or already confirmed
        $auto = $this->is_auto_execute( $name );
        if ( ! $auto && empty( $slots['_confirmed'] ) ) {
            // 3. Return preconfirm request
            return [
                'success' => false,
                'action'  => 'preconfirm',
                'message' => $this->build_preconfirm_message( $name, $slots ),
                'tool'    => $name,
                'slots'   => $slots,
            ];
        }

        // 4. Execute (remove internal flag before passing to callback)
        unset( $slots['_confirmed'] );

        // Inject pipeline context
        if ( ! empty( $session_context['pipeline_id'] ) ) {
            $slots['_pipeline_id'] = $session_context['pipeline_id'];
        }
        if ( isset( $session_context['step_index'] ) ) {
            $slots['_step_index'] = $session_context['step_index'];
        }

        return $this->execute( $name, $slots );
    }

    /**
     * Build a human-readable preconfirm message showing planned input.
     *
     * @param string $name  Tool name.
     * @param array  $slots Input slots.
     * @return string
     */
    private function build_preconfirm_message( $name, array $slots ) {
        $schema  = $this->get_schema( $name );
        $desc    = $schema['description'] ?? $name;
        $lines   = [ "✅ Sắp thực hiện: **{$desc}**", '' ];

        foreach ( $slots as $field => $value ) {
            if ( str_starts_with( $field, '_' ) ) continue;
            if ( in_array( $field, [ 'session_id', 'user_id', 'platform' ], true ) ) continue;
            if ( $value === '' || $value === null ) continue;

            $display = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value;
            if ( mb_strlen( $display, 'UTF-8' ) > 80 ) {
                $display = mb_substr( $display, 0, 77, 'UTF-8' ) . '...';
            }
            $lines[] = "• **{$field}**: {$display}";
        }

        $lines[] = '';
        $lines[] = 'Xác nhận? ✅ OK | ❌ Hủy | ✏️ Sửa';

        return implode( "\n", $lines );
    }

    /**
     * Validate tool inputs against schema.
     *
     * @param string $name
     * @param array  $slots
     * @return array Missing required field names.
     */
    public function validate_inputs( $name, array $slots ) {
        $schema = $this->get_schema( $name );
        if ( ! $schema || empty( $schema['input_fields'] ) ) {
            return [];
        }

        $missing = [];
        foreach ( $schema['input_fields'] as $field => $config ) {
            if ( ! empty( $config['required'] ) ) {
                $value = $slots[ $field ] ?? null;
                if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
                    $missing[] = $field;
                }
            }
        }

        return $missing;
    }

    /* ================================================================
     *  Built-in tool implementations (bridge to legacy functions)
     * ================================================================ */

    /**
     * Built-in: Create product.
     *
     * @param array $slots
     * @return array
     */
    public function builtin_create_product( array $slots ) {
        if ( function_exists( 'twf_handle_product_post_flow' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $message = [
                'text' => sprintf(
                    'Đăng sản phẩm: %s | %s | %s',
                    $slots['title']       ?? '',
                    $slots['price']       ?? '',
                    $slots['description'] ?? ''
                ),
            ];
            if ( ! empty( $slots['image_url'] ) ) {
                $message['photo'] = [ [ 'file_id' => $slots['image_url'] ] ];
            }

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'create_product', 'sub_step' => '1/1 product_post_flow',
                    'status' => 'running', 'title' => $slots['title'] ?? '',
                ] );
            }

            $result = twf_handle_product_post_flow( $message, $chat_id );

            do_action( 'bizcity_intent_tool_create_product', $slots, $result );

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'create_product', 'sub_step' => '1/1 product_post_flow',
                    'status' => 'success', 'title' => $slots['title'] ?? '', 'price' => $slots['price'] ?? '',
                ] );
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf(
                    '✅ Đã đăng sản phẩm "%s" với giá %s.',
                    $slots['title'] ?? '',
                    $slots['price'] ?? ''
                ),
                'data' => [ 'result' => $result ],
            ];
        }

        if ( class_exists( 'BizCity_Execution_Logger' ) ) {
            BizCity_Execution_Logger::log( 'tool_step', [
                'tool_name' => 'create_product', 'sub_step' => '1/1 product_post_flow',
                'status' => 'error', 'message' => 'twf_handle_product_post_flow không tồn tại',
            ] );
        }

        return [
            'success' => false,
            'message' => 'Chức năng tạo sản phẩm chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Generate report.
     *
     * @param array $slots
     * @return array
     */
    public function builtin_generate_report( array $slots ) {
        if ( function_exists( 'twf_handle_ai_json_report' ) ) {
            $ai_json = wp_json_encode( $slots );
            $report  = twf_handle_ai_json_report( $ai_json );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => $report ?: 'Không có dữ liệu báo cáo.',
                'data'     => [ 'report_text' => $report ],
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng báo cáo chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Inventory report.
     *
     * @param array $slots
     * @return array
     */
    public function builtin_inventory_report( array $slots ) {
        $from_date = $slots['from_date'] ?? '';
        $to_date   = $slots['to_date']   ?? '';
        $so_ngay   = intval( $slots['so_ngay'] ?? 7 );

        // Bridge to legacy XNT report
        if ( function_exists( 'twf_bao_cao_xuat_nhap_ton' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $result  = twf_bao_cao_xuat_nhap_ton( $chat_id, $from_date, $to_date );
            do_action( 'bizcity_intent_tool_inventory_report', $slots, $result );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => is_string( $result ) ? $result : sprintf(
                    '📦 Báo cáo xuất nhập tồn %s đã sẵn sàng.',
                    $from_date ? "từ {$from_date} đến {$to_date}" : "{$so_ngay} ngày gần nhất"
                ),
                'data' => $slots,
            ];
        }

        do_action( 'bizcity_intent_tool_inventory_report', $slots );

        return [
            'success'  => true,
            'complete' => true,
            'message'  => sprintf(
                '📦 Báo cáo xuất nhập tồn %s (chế độ offline).',
                $from_date ? "từ {$from_date} đến {$to_date}" : "{$so_ngay} ngày gần nhất"
            ),
            'data' => $slots,
        ];
    }

    /**
     * Built-in: Post to Facebook — actually calls twf_handle_facebook_multi_page_post.
     */
    public function builtin_post_facebook( array $slots ) {
        $content   = $slots['content'] ?? '';
        $image_url = $slots['image_url'] ?? '';

        if ( function_exists( 'twf_handle_facebook_multi_page_post' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $message = [ 'text' => $content ];
            $data    = [
                'title'     => $content,
                'image_url' => $image_url,
            ];

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'post_facebook', 'sub_step' => '1/1 multi_page_post', 'status' => 'running',
                ] );
            }

            $post_id = twf_handle_facebook_multi_page_post( $chat_id, $message, $data );

            do_action( 'bizcity_intent_tool_post_facebook', $slots, $post_id );

            if ( $post_id ) {
                if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'post_facebook', 'sub_step' => '1/1 multi_page_post',
                        'status' => 'success', 'post_id' => $post_id,
                    ] );
                }
                return [
                    'success'  => true,
                    'complete' => true,
                    'message'  => sprintf(
                        "📘 Đã đăng bài lên tất cả trang Facebook!\n🔗 Post ID: %s",
                        $post_id
                    ),
                    'data' => [ 'post_id' => $post_id ],
                ];
            }

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'post_facebook', 'sub_step' => '1/1 multi_page_post',
                    'status' => 'success', 'message' => 'queued, no post_id yet',
                ] );
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => '📘 Đã gửi yêu cầu đăng Facebook. Kiểm tra trang quản lý để xem kết quả.',
                'data'     => [],
            ];
        }

        // Fallback: try twf_post_to_facebook directly
        if ( function_exists( 'twf_post_to_facebook' ) && $content ) {
            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'post_facebook', 'sub_step' => '1/1 direct_post', 'status' => 'running',
                ] );
            }
            $fb_id = twf_post_to_facebook( $content, '', $image_url, $content );
            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'post_facebook', 'sub_step' => '1/1 direct_post',
                    'status' => $fb_id ? 'success' : 'error',
                    'post_id' => $fb_id ?: null,
                ] );
            }
            return [
                'success'  => ! empty( $fb_id ),
                'complete' => true,
                'message'  => $fb_id
                    ? "📘 Đã đăng lên Facebook! Post ID: {$fb_id}"
                    : '❌ Không thể đăng bài lên Facebook. Kiểm tra cấu hình token.',
                'data' => [ 'fb_post_id' => $fb_id ],
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng đăng Facebook chưa sẵn sàng. Kiểm tra cấu hình Facebook Token.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Write article — actually generates content + creates WP post.
     *
     * Flow: AI generates article → AI generates image → wp_insert_post → cross-post to Facebook.
     * Uses core functions: ai_generate_content(), twf_wp_create_post(), twf_generate_image_url().
     */
    public function builtin_write_article( array $slots ) {
        // Provider plan uses 'message' as primary slot; built-in schema uses 'topic'.
        // Accept both for backward compatibility with new intent-provider plans.
        $topic     = $slots['topic']     ?? $slots['message'] ?? '';
        $content   = $slots['content']   ?? $topic;
        $image_url = $slots['image_url'] ?? '';
        $session_id = $slots['session_id'] ?? '';

        // Handle natural responses for image: "không cần", "tự tạo", "auto", etc.
        // These indicate user wants AI to auto-generate the image
        $skip_image_phrases = ['không', 'không cần', 'tự tạo', 'auto', 'skip', 'bỏ qua', 'thôi', 'khỏi'];
        $should_skip_image = false;
        if ( ! empty( $image_url ) ) {
            $image_lower = mb_strtolower( trim( $image_url ) );
            foreach ( $skip_image_phrases as $phrase ) {
                if ( strpos( $image_lower, $phrase ) !== false ) {
                    $image_url = ''; // Clear so it will auto-generate
                    $should_skip_image = true;
                    break;
                }
            }
        }

        // If image_url is not a valid URL and user didn't explicitly skip,
        // check recent messages for uploaded image attachment
        if ( ! $should_skip_image && ! empty( $image_url ) && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
            $recent_image = $this->get_recent_image_attachment( $session_id );
            if ( $recent_image ) {
                $image_url = $recent_image;
            } else {
                // User said something like "đây" but no image found - auto-generate
                $image_url = '';
            }
        }

        if ( empty( $topic ) && empty( $content ) ) {
            return [
                'success' => false,
                'message' => 'Vui lòng cho em biết chủ đề bài viết.',
                'missing_fields' => [ 'topic' ],
            ];
        }

        // Build the blog-writing prompt (same as twf_handle_post_request)
        $prompt = 'Hãy viết một bài blog hoàn chỉnh bằng tiếng Việt, dạng văn xuôi, ít nhất 700 từ, '
                . 'chia đoạn rõ ràng, văn phong nhẹ nhàng và chuyên nghiệp. '
                . 'Sử dụng thẻ HTML (<b>, <strong>, <em>, <mark>) để nhấn mạnh. '
                . 'KHÔNG dùng markdown. Cuối bài có CTA. '
                . 'Trả về JSON: {"title":"...","content":"..."} '
                . "\n\nChủ đề: ";
        $prompt_text = $prompt . $topic . ( $content !== $topic ? ': ' . $content : '' );

        // Step 1: Generate article content via AI
        if ( function_exists( 'ai_generate_content' ) ) {
            $fields = ai_generate_content( $prompt_text );
        } else {
            return [
                'success' => false,
                'message' => 'Chức năng tạo nội dung AI chưa sẵn sàng.',
                'data'    => [],
            ];
        }

        $post_title   = $fields['title']   ?? $topic;
        $post_content = $fields['content'] ?? '';

        // If AI returned generic fallback title, use the user's topic instead
        if ( empty( $post_title ) || $post_title === 'Bài viết mới' ) {
            $post_title = $topic;
        }

        if ( empty( $post_content ) ) {
            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'write_article', 'sub_step' => '1/3 ai_generate_content',
                    'status' => 'error', 'message' => 'AI trả về nội dung rỗng',
                ] );
            }
            return [
                'success' => false,
                'message' => '❌ AI không tạo được nội dung. Vui lòng thử lại với chủ đề khác.',
                'data'    => [],
            ];
        }

        // Log step 1 success
        if ( class_exists( 'BizCity_Execution_Logger' ) ) {
            BizCity_Execution_Logger::log( 'tool_step', [
                'tool_name' => 'write_article', 'sub_step' => '1/3 ai_generate_content',
                'status' => 'success', 'title' => $post_title,
                'content_len' => mb_strlen( $post_content ),
            ] );
        }

        // Step 2: Generate image if not provided
        if ( function_exists( 'twf_is_valid_image_url' ) && ! twf_is_valid_image_url( $image_url ) ) {
            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'write_article', 'sub_step' => '2/3 generate_image', 'status' => 'running',
                ] );
            }
            if ( function_exists( 'twf_generate_image_url' ) ) {
                $image_url = twf_generate_image_url(
                    $post_title . ' — cinematic, soft natural light, clean background, ultra-detailed'
                );
            }
            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'write_article', 'sub_step' => '2/3 generate_image',
                    'status' => $image_url ? 'success' : 'skipped',
                    'has_image' => ! empty( $image_url ),
                ] );
            }
        }

        // Log step 3 start
        if ( class_exists( 'BizCity_Execution_Logger' ) ) {
            BizCity_Execution_Logger::log( 'tool_step', [
                'tool_name' => 'write_article', 'sub_step' => '3/3 create_wp_post', 'status' => 'running',
                'title' => $post_title, 'has_image' => ! empty( $image_url ),
            ] );
        }

        // Step 3: Create WP post (also sets thumbnail + auto-posts to Facebook)
        if ( function_exists( 'twf_wp_create_post' ) ) {
            $post_id = twf_wp_create_post( $post_title, $post_content, $image_url );
        } else {
            $post_id = wp_insert_post( [
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_author'  => get_current_user_id() ?: 1,
            ] );
        }

        if ( $post_id && ! is_wp_error( $post_id ) ) {
            do_action( 'bizcity_intent_tool_write_article', $slots, $post_id );

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'write_article', 'sub_step' => '3/3 create_wp_post',
                    'status' => 'success', 'post_id' => $post_id, 'title' => $post_title,
                    'url' => get_permalink( $post_id ),
                ] );
            }

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf(
                    "✅ Bài đã đăng thành công!\n📝 %s\n🔗 Xem: %s\n✏️ Sửa: %s",
                    $post_title,
                    get_permalink( $post_id ),
                    admin_url( "post.php?post={$post_id}&action=edit" )
                ),
                'data' => [
                    'type'     => 'article',
                    'id'       => $post_id,
                    'post_id'  => $post_id,
                    'title'    => $post_title,
                    'url'      => get_permalink( $post_id ),
                    'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
                ],
            ];
        }

        if ( class_exists( 'BizCity_Execution_Logger' ) ) {
            BizCity_Execution_Logger::error( 'tool_error', 'write_article: wp_insert_post trả về lỗi hoặc false', [
                'tool' => 'write_article', 'post_title' => $post_title,
            ] );
        }
        return [
            'success' => false,
            'message' => '❌ Không thể đăng bài. Vui lòng thử lại.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Set reminder — actually calls twf_create_biztask_from_ai.
     */
    public function builtin_set_reminder( array $slots ) {
        $what   = $slots['what']   ?? '';
        $when   = $slots['when']   ?? '';
        $repeat = $slots['repeat'] ?? 'once';

        if ( function_exists( 'twf_create_biztask_from_ai' ) ) {
            $chat_id  = 'intent_' . get_current_user_id();
            $arr = [
                'type' => 'nhac_viec',
                'info' => [
                    'what'   => $what,
                    'when'   => $when,
                    'repeat' => $repeat,
                ],
            ];

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'set_reminder', 'sub_step' => '1/1 create_biztask',
                    'status' => 'running', 'title' => $what, 'when' => $when,
                ] );
            }

            $post_id = twf_create_biztask_from_ai( $chat_id, $what, $arr, 'adminchat' );

            do_action( 'bizcity_intent_tool_set_reminder', $slots, $post_id );

            if ( $post_id ) {
                if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'set_reminder', 'sub_step' => '1/1 create_biztask',
                        'status' => 'success', 'post_id' => $post_id, 'title' => $what,
                    ] );
                }
                return [
                    'success'  => true,
                    'complete' => true,
                    'message'  => sprintf(
                        "🔔 Đã tạo nhắc việc thành công!\n📌 Nội dung: %s\n⏰ Thời gian: %s",
                        $what, $when ?: 'Chưa xác định'
                    ),
                    'data' => [ 'task_id' => $post_id ],
                ];
            }
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => sprintf(
                "🔔 Đã ghi nhớ: \"%s\"%s",
                $what,
                $when ? " vào lúc {$when}." : '.'
            ),
            'data' => $slots,
        ];
    }

    /**
     * Built-in: Create video via Kling — saves script, returns link for generation.
     * This is async: script saved immediately, video generation queued separately.
     */
    public function builtin_create_video( array $slots ) {
        if ( class_exists( 'BizCity_Video_Kling_Database' ) ) {
            // Guard: verify save_script() method exists — prevents fatal if Kling plugin
            // renamed or restructured the Database class.
            if ( ! method_exists( 'BizCity_Video_Kling_Database', 'save_script' ) ) {
                error_log( '[INTENT-TOOLS] create_video: BizCity_Video_Kling_Database exists but save_script() method not found' );
                return [
                    'success' => false,
                    'message' => 'Plugin Kling đã cài nhưng thiếu method save_script(). Vui lòng cập nhật plugin.',
                    'data'    => [],
                ];
            }

            $title        = $slots['title'] ?? ( 'Video: ' . mb_substr( $slots['content'] ?? '', 0, 40 ) );
            $content      = $slots['content'] ?? '';
            $duration     = intval( $slots['duration'] ?? 5 );
            $aspect_ratio = $slots['aspect_ratio'] ?? '9:16';
            // Sanitize image_url: must be a valid URL, not user text leaking from slot filling
            $image_url    = $slots['image_url'] ?? '';
            if ( $image_url && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $image_url = '';
            }

            $script_data = [
                'title'        => $title,
                'content'      => $content,
                'duration'     => $duration,
                'aspect_ratio' => $aspect_ratio,
                'model'        => 'kling-v2',
                'status'       => 'active',
                'metadata'     => wp_json_encode( [
                    'image_url'  => $image_url,
                    'source'     => 'intent_engine',
                    'created_by' => get_current_user_id(),
                ] ),
            ];

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'create_video', 'sub_step' => '1/2 save_script',
                    'status' => 'running', 'title' => $title,
                ] );
            }

            $script_id = BizCity_Video_Kling_Database::save_script( $script_data );

            if ( $script_id ) {
                do_action( 'bizcity_intent_tool_create_video', $slots, $script_id );

                $edit_url = admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id );

                if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'create_video', 'sub_step' => '1/2 save_script',
                        'status' => 'success', 'post_id' => $script_id, 'title' => $title, 'url' => $edit_url,
                    ] );
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'create_video', 'sub_step' => '2/2 queue_generation',
                        'status' => 'skipped', 'message' => 'Async — user must click Generate link',
                    ] );
                }

                return [
                    'success'  => true,
                    'complete' => true,  // Script saved = goal achieved
                    'message'  => sprintf(
                        "🎬 Đã tạo script video \"%s\" (%d giây, %s).\n👉 Bấm vào đây để tạo video: %s",
                        $title, $duration, $aspect_ratio, $edit_url
                    ),
                    'data' => [ 'script_id' => $script_id, 'url' => $edit_url ],
                ];
            }

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::error( 'tool_error', 'create_video: save_script thất bại', [
                    'tool' => 'create_video', 'title' => $title,
                ] );
            }

            return [
                'success' => false,
                'message' => 'Không thể tạo script video. Vui lòng thử lại.',
                'data'    => [],
            ];
        }

        return [
            'success' => false,
            'message' => 'Plugin tạo video (Kling) chưa được cài đặt.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Edit product — calls twf_handle_edit_product_flow.
     */
    public function builtin_edit_product( array $slots ) {
        if ( function_exists( 'twf_handle_edit_product_flow' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $message = [ 'text' => sprintf(
                'Sửa sản phẩm %s: %s = %s',
                $slots['product_id'] ?? '',
                $slots['field']      ?? '',
                $slots['new_value']  ?? ''
            ) ];

            twf_handle_edit_product_flow( $message, $chat_id );

            do_action( 'bizcity_intent_tool_edit_product', $slots );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf(
                    '✏️ Đã cập nhật sản phẩm #%s.',
                    $slots['product_id'] ?? '?'
                ),
                'data' => $slots,
            ];
        }
        return [
            'success' => false,
            'message' => 'Chức năng sửa sản phẩm chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Create order — calls twf_handle_create_order_ai_flow.
     */
    public function builtin_create_order( array $slots ) {
        if ( function_exists( 'twf_handle_create_order_ai_flow' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $text    = '';
            if ( ! empty( $slots['customer_name'] ) ) {
                $text .= 'Khách: ' . $slots['customer_name'] . ' ';
            }
            if ( ! empty( $slots['phone'] ) ) {
                $text .= 'SĐT: ' . $slots['phone'] . ' ';
            }
            if ( ! empty( $slots['items'] ) ) {
                $text .= 'Sản phẩm: ' . $slots['items'] . ' ';
            }
            $message = [ 'text' => trim( $text ) ?: 'Tạo đơn hàng mới' ];

            twf_handle_create_order_ai_flow( $message, $chat_id );

            do_action( 'bizcity_intent_tool_create_order', $slots );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf(
                    '🛒 Đã tạo đơn hàng cho khách "%s".',
                    $slots['customer_name'] ?? '?'
                ),
                'data' => $slots,
            ];
        }
        return [
            'success' => false,
            'message' => 'Chức năng tạo đơn chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: List orders — calls twf_telegram_order_list_report2.
     */
    public function builtin_list_orders( array $slots ) {
        $so_ngay   = intval( $slots['so_ngay'] ?? 7 );
        $from_date = $slots['from_date'] ?? gmdate( 'Y-m-d', strtotime( "-{$so_ngay} days" ) );
        $to_date   = $slots['to_date']   ?? gmdate( 'Y-m-d' );

        if ( function_exists( 'twf_telegram_order_list_report2' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            twf_telegram_order_list_report2( $chat_id, $from_date, $to_date );

            do_action( 'bizcity_intent_tool_list_orders', $slots );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf(
                    '📋 Danh sách đơn hàng từ %s đến %s đã được tạo.',
                    $from_date, $to_date
                ),
                'data' => $slots,
            ];
        }

        do_action( 'bizcity_intent_tool_list_orders', $slots );

        return [
            'success'  => true,
            'complete' => true,
            'message'  => sprintf( '📋 Xem đơn hàng: %s', admin_url( 'edit.php?post_type=shop_order' ) ),
            'data'     => $slots,
        ];
    }

    /**
     * Built-in: Find customer — calls twf_handle_find_customer_order_by_phone.
     */
    public function builtin_find_customer( array $slots ) {
        $search = $slots['search_term'] ?? $slots['phone'] ?? '';

        if ( empty( $search ) ) {
            return [
                'success' => false,
                'message' => 'Vui lòng cung cấp tên hoặc SĐT khách hàng.',
                'data'    => [],
                'missing_fields' => [ 'search_term' ],
            ];
        }

        if ( function_exists( 'twf_handle_find_customer_order_by_phone' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            twf_handle_find_customer_order_by_phone( $chat_id, $search );

            do_action( 'bizcity_intent_tool_find_customer', $slots );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf( '🔍 Đã tìm thông tin khách hàng "%s".', $search ),
                'data'     => $slots,
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng tìm khách hàng chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Customer stats — calls twf_bao_cao_top_customers.
     */
    public function builtin_customer_stats( array $slots ) {
        $so_ngay = intval( $slots['so_ngay'] ?? 7 );

        if ( function_exists( 'twf_bao_cao_top_customers' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $result  = twf_bao_cao_top_customers( $chat_id, '', $so_ngay );

            do_action( 'bizcity_intent_tool_customer_stats', $slots, $result );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf( '👥 Thống kê top khách hàng %d ngày gần nhất đã sẵn sàng.', $so_ngay ),
                'data'     => is_array( $result ) ? $result : $slots,
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng thống kê khách hàng chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Product stats — calls twf_bao_cao_top_product.
     */
    public function builtin_product_stats( array $slots ) {
        $so_ngay = intval( $slots['so_ngay'] ?? 7 );

        if ( function_exists( 'twf_bao_cao_top_product' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            $result  = twf_bao_cao_top_product( $chat_id, '', $so_ngay );

            do_action( 'bizcity_intent_tool_product_stats', $slots, $result );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf( '📊 Thống kê hàng hóa bán chạy %d ngày gần nhất đã sẵn sàng.', $so_ngay ),
                'data'     => is_array( $result ) ? $result : $slots,
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng thống kê hàng hóa chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Inventory journal — calls twf_bao_cao_nhat_ky_xuat_nhap.
     */
    public function builtin_inventory_journal( array $slots ) {
        $so_ngay   = intval( $slots['so_ngay'] ?? 7 );
        $from_date = $slots['from_date'] ?? gmdate( 'Y-m-d', strtotime( "-{$so_ngay} days" ) );
        $to_date   = $slots['to_date']   ?? gmdate( 'Y-m-d' );

        if ( function_exists( 'twf_bao_cao_nhat_ky_xuat_nhap' ) ) {
            $chat_id = 'intent_' . get_current_user_id();
            twf_bao_cao_nhat_ky_xuat_nhap( $chat_id, $from_date, $to_date );

            do_action( 'bizcity_intent_tool_inventory_journal', $slots );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => sprintf( '📖 Nhật ký xuất nhập từ %s đến %s đã sẵn sàng.', $from_date, $to_date ),
                'data'     => $slots,
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng nhật ký xuất nhập chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Warehouse receipt — calls twf_parse_phieu_nhap_kho_ai + twf_phieu_nhap_kho_from_telegram.
     */
    public function builtin_warehouse_receipt( array $slots ) {
        $content = $slots['content'] ?? '';

        if ( function_exists( 'twf_parse_phieu_nhap_kho_ai' ) ) {
            $info = twf_parse_phieu_nhap_kho_ai( $content );

            if ( function_exists( 'twf_phieu_nhap_kho_from_telegram' ) && ! empty( $info ) ) {
                $chat_id = 'intent_' . get_current_user_id();
                twf_phieu_nhap_kho_from_telegram( $chat_id, $info );
            }

            do_action( 'bizcity_intent_tool_warehouse_receipt', $slots, $info );

            return [
                'success'  => true,
                'complete' => true,
                'message'  => '📋 Đã xử lý phiếu nhập kho thành công.',
                'data'     => is_array( $info ) ? $info : $slots,
            ];
        }

        return [
            'success' => false,
            'message' => 'Chức năng nhập kho chưa sẵn sàng.',
            'data'    => [],
        ];
    }

    /**
     * Built-in: Help guide — calls twf_ai_telegram_help_content.
     */
    public function builtin_help_guide( array $slots ) {
        $topic = $slots['topic'] ?? 'tat_ca';

        if ( function_exists( 'twf_ai_telegram_help_content' ) ) {
            $msg = twf_ai_telegram_help_content( $topic );
            return [
                'success'  => true,
                'complete' => true,
                'message'  => $msg ?: '📖 Không tìm thấy hướng dẫn cho chủ đề này.',
                'data'     => [ 'topic' => $topic ],
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "📖 Hướng dẫn sử dụng: Gõ lệnh để bắt đầu. Ví dụ:\n"
                       . "• \"Tạo sản phẩm\" - Đăng sản phẩm mới\n"
                       . "• \"Báo cáo\" - Xem báo cáo kinh doanh\n"
                       . "• \"Đăng Facebook\" - Đăng bài lên FB\n"
                       . "• \"Tạo video\" - Tạo video bằng AI\n"
                       . "• \"Nhắc việc\" - Tạo nhắc việc\n"
                       . "• \"Xem tarot\" - Bốc bài tarot\n"
                       . "• \"Hôm nay thế nào\" - Dự báo vận mệnh",
            'data'     => [ 'topic' => $topic ],
        ];
    }

    /**
     * Get recent image attachment from webchat messages.
     * Looks for images uploaded in the last 10 minutes in this session.
     *
     * @param string $session_id Session ID to search in.
     * @return string|null Image URL if found, null otherwise.
     */
    private function get_recent_image_attachment( $session_id ) {
        if ( empty( $session_id ) ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $table )
        );
        if ( ! $table_exists ) {
            return null;
        }

        // Query recent messages with image attachments (last 10 minutes)
        $ten_minutes_ago = date( 'Y-m-d H:i:s', strtotime( '-10 minutes' ) );
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT attachments FROM {$table}
                 WHERE session_id = %s
                   AND attachments IS NOT NULL
                   AND attachments != ''
                   AND attachments != '[]'
                   AND created_at >= %s
                 ORDER BY created_at DESC
                 LIMIT 1",
                $session_id,
                $ten_minutes_ago
            )
        );

        if ( ! $row || empty( $row->attachments ) ) {
            return null;
        }

        $attachments = json_decode( $row->attachments, true );
        if ( ! is_array( $attachments ) ) {
            return null;
        }

        // Find first image attachment
        foreach ( $attachments as $att ) {
            $type = $att['type'] ?? '';
            $url  = $att['url']  ?? '';
            if ( $type === 'image' && ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                return $url;
            }
        }

        return null;
    }
}
