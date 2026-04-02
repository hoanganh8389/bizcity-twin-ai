<?php
/**
 * Plugin Name:       BizCity Tool Sheet
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-sheet
 * Description:       AI tạo workbook, bảng kế hoạch, bảng ngân sách và studio spreadsheet MVP cho Twin AI. Có intent provider, CPT lưu workbook và template page editor shell.
 * Short Description: Chat để tạo bảng tính và mở Sheet Studio chỉnh sửa, phân tích, export.
 * Quick View:        Sheets + AI → tạo workbook → chỉnh sửa → phân tích → export
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-sheet
 * Role:              agent
 * Featured:          false
 * Notebook:          false
 * public:            false
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/sheet.svg
 * Cover URI:         https://i0.wp.com/tirabassi.com/wp-content/uploads/2024/09/Top-10-Excel-AI-Tools-in-2024-1.webp?w=1000&ssl=1
 * Template Page:     tool-sheet
 * Category:          spreadsheet, excel, sheets, dashboard
 * Tags:              spreadsheet, excel, sheets, dashboard, formula, budget, KPI, AI tool
 * Plan:              free
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool Sheet</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt.';
        echo '</p></div>';
    } );
    return;
}

define( 'BZTOOL_SHEET_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_SHEET_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_SHEET_VERSION', '0.1.0' );
define( 'BZTOOL_SHEET_SLUG',    'bizcity-tool-sheet' );

require_once BZTOOL_SHEET_DIR . 'includes/class-tools-sheet.php';

BizCity_Tool_Sheet::init();

add_action( 'bizcity_intent_register_providers', function( $registry ) {
    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) return;

    bizcity_intent_register_plugin( $registry, [
        'id'   => 'tool-sheet',
        'name' => 'BizCity Tool - Spreadsheet Studio',

        'patterns' => [
            '/phân tích file excel|phân tích bảng tính|analyze spreadsheet|analyze excel|đọc workbook/ui' => [
                'goal'        => 'analyze_sheet_data',
                'label'       => 'Phân tích bảng tính',
                'description' => 'Đọc dữ liệu bảng tính, tóm tắt cấu trúc, gợi ý insight và cột số liệu',
                'extract'     => [ 'sheet_data', 'analysis_goal' ],
            ],
            '/tạo công thức|điền công thức|fill formula|áp công thức|formula range/ui' => [
                'goal'        => 'fill_formula_range',
                'label'       => 'Điền công thức',
                'description' => 'Sinh công thức cho một vùng dữ liệu hoặc tạo patch công thức cho workbook',
                'extract'     => [ 'formula_goal', 'target_range', 'sheet_name' ],
            ],
            '/xuất file excel|export bảng tính|export workbook|tải workbook|xuất csv|xuất json/ui' => [
                'goal'        => 'export_sheet_file',
                'label'       => 'Xuất workbook',
                'description' => 'Xuất workbook ra JSON hoặc CSV ở bản MVP, chuẩn bị cho XLSX/PDF phase sau',
                'extract'     => [ 'workbook_id', 'export_format' ],
            ],
            '/tạo bảng tính|tạo file excel|làm bảng kế hoạch|làm bảng ngân sách|tạo spreadsheet|tạo workbook|sheet/ui' => [
                'goal'        => 'create_sheet_from_prompt',
                'label'       => 'Tạo workbook',
                'description' => 'Tạo workbook mới từ mô tả tự nhiên như ngân sách, KPI dashboard, bảng chấm công, kế hoạch doanh thu',
                'extract'     => [ 'topic', 'sheet_purpose', 'rows_estimate' ],
            ],
        ],

        'plans' => [
            'create_sheet_from_prompt' => [
                'required_slots' => [
                    'topic' => [
                        'type'         => 'text',
                        'prompt'       => 'Bạn muốn tạo bảng tính cho mục đích gì? Ví dụ: ngân sách marketing, KPI team sales, chấm công nhân viên.',
                        'no_auto_map'  => true,
                    ],
                ],
                'optional_slots' => [
                    'sheet_purpose' => [
                        'type'    => 'choice',
                        'prompt'  => 'Loại workbook?',
                        'choices' => [ 'budget', 'dashboard', 'tracker', 'roster', 'inventory', 'finance', 'custom', 'auto' ],
                        'default' => 'auto',
                    ],
                    'rows_estimate' => [
                        'type'    => 'number',
                        'prompt'  => 'Ước lượng số dòng dữ liệu cần tạo?',
                        'default' => 12,
                    ],
                ],
                'tool'       => 'create_sheet_from_prompt',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'sheet_purpose', 'rows_estimate' ],
            ],
            'analyze_sheet_data' => [
                'required_slots' => [
                    'sheet_data' => [
                        'type'         => 'text',
                        'prompt'       => 'Gửi dữ liệu bảng tính, CSV hoặc mô tả vùng dữ liệu cần phân tích.',
                        'no_auto_map'  => true,
                    ],
                ],
                'optional_slots' => [
                    'analysis_goal' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn phân tích theo hướng nào?',
                        'choices' => [ 'summary', 'quality', 'trend', 'dashboard', 'formula', 'auto' ],
                        'default' => 'auto',
                    ],
                ],
                'tool'       => 'analyze_sheet_data',
                'ai_compose' => false,
                'slot_order' => [ 'sheet_data', 'analysis_goal' ],
            ],
            'fill_formula_range' => [
                'required_slots' => [
                    'formula_goal' => [ 'type' => 'text', 'prompt' => 'Bạn cần công thức gì? Ví dụ: tính tổng cột doanh thu, margin %, rolling average.' ],
                    'target_range' => [ 'type' => 'text', 'prompt' => 'Công thức áp vào vùng nào? Ví dụ: D2:D20.' ],
                ],
                'optional_slots' => [
                    'sheet_name' => [ 'type' => 'text', 'prompt' => 'Tên sheet cần áp dụng nếu có nhiều tab.' ],
                    'workbook_id' => [ 'type' => 'number', 'prompt' => 'ID workbook nếu muốn gắn patch vào file đã lưu.' ],
                ],
                'tool'       => 'fill_formula_range',
                'ai_compose' => false,
                'slot_order' => [ 'formula_goal', 'target_range', 'sheet_name', 'workbook_id' ],
            ],
            'export_sheet_file' => [
                'required_slots' => [
                    'workbook_id' => [ 'type' => 'number', 'prompt' => 'Cho mình ID workbook cần export.' ],
                ],
                'optional_slots' => [
                    'export_format' => [
                        'type'    => 'choice',
                        'prompt'  => 'Chọn định dạng export.',
                        'choices' => [ 'json', 'csv', 'xlsx', 'pdf' ],
                        'default' => 'json',
                    ],
                ],
                'tool'       => 'export_sheet_file',
                'ai_compose' => false,
                'slot_order' => [ 'workbook_id', 'export_format' ],
            ],
        ],

        'tools' => [
            'create_sheet_from_prompt' => [
                'schema' => [
                    'description'  => 'Tạo workbook spreadsheet mới từ prompt tự nhiên như ngân sách, KPI dashboard, bảng tồn kho hoặc tracker.',
                    'input_fields' => [
                        'topic'         => [ 'required' => true,  'type' => 'text' ],
                        'sheet_purpose' => [ 'required' => false, 'type' => 'choice' ],
                        'rows_estimate' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'output_schema' => [
                    'workbook_id'    => [ 'type' => 'int' ],
                    'title'          => [ 'type' => 'string' ],
                    'workbook_json'  => [ 'type' => 'string' ],
                    'sheet_url'      => [ 'type' => 'string' ],
                    'sheet_purpose'  => [ 'type' => 'string' ],
                ],
                'callback' => [ 'BizCity_Tool_Sheet', 'create_sheet_from_prompt' ],
            ],
            'analyze_sheet_data' => [
                'schema' => [
                    'description'  => 'Phân tích dữ liệu bảng tính, xác định header, cột số, số dòng, chất lượng dữ liệu và gợi ý insight.',
                    'input_fields' => [
                        'sheet_data'    => [ 'required' => true,  'type' => 'text' ],
                        'analysis_goal' => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'output_schema' => [
                    'row_count'       => [ 'type' => 'int' ],
                    'column_count'    => [ 'type' => 'int' ],
                    'headers'         => [ 'type' => 'array' ],
                    'numeric_columns' => [ 'type' => 'array' ],
                    'insights'        => [ 'type' => 'array' ],
                ],
                'callback' => [ 'BizCity_Tool_Sheet', 'analyze_sheet_data' ],
            ],
            'fill_formula_range' => [
                'schema' => [
                    'description'  => 'Sinh patch công thức cho một vùng ô hoặc lưu gợi ý công thức gắn với workbook.',
                    'input_fields' => [
                        'formula_goal' => [ 'required' => true,  'type' => 'text' ],
                        'target_range' => [ 'required' => true,  'type' => 'text' ],
                        'sheet_name'   => [ 'required' => false, 'type' => 'text' ],
                        'workbook_id'  => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'output_schema' => [
                    'formula'      => [ 'type' => 'string' ],
                    'target_range' => [ 'type' => 'string' ],
                    'sheet_name'   => [ 'type' => 'string' ],
                    'patch_id'     => [ 'type' => 'string' ],
                ],
                'callback' => [ 'BizCity_Tool_Sheet', 'fill_formula_range' ],
            ],
            'export_sheet_file' => [
                'schema' => [
                    'description'  => 'Xuất workbook đã lưu ra JSON hoặc CSV trong MVP, chuẩn bị giao diện export XLSX/PDF ở phase kế tiếp.',
                    'input_fields' => [
                        'workbook_id'   => [ 'required' => true,  'type' => 'number' ],
                        'export_format' => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'output_schema' => [
                    'workbook_id'   => [ 'type' => 'int' ],
                    'export_format' => [ 'type' => 'string' ],
                    'file_name'     => [ 'type' => 'string' ],
                    'payload'       => [ 'type' => 'string' ],
                ],
                'callback' => [ 'BizCity_Tool_Sheet', 'export_sheet_file' ],
            ],
        ],

        'examples' => [
            'create_sheet_from_prompt' => [
                'Tạo bảng ngân sách marketing 12 tháng',
                'Làm dashboard KPI cho team sales theo tháng',
                'Tạo bảng chấm công nhân viên theo ca',
                'Tạo file tồn kho và nhập xuất hàng hóa',
            ],
            'analyze_sheet_data' => [
                'Phân tích bảng doanh thu này xem có cột nào là chỉ số chính',
                'Đọc file CSV này và gợi ý dashboard',
            ],
            'fill_formula_range' => [
                'Điền công thức tính tổng doanh thu vào cột E từ E2:E20',
                'Tạo công thức margin phần trăm cho cột G',
            ],
            'export_sheet_file' => [
                'Xuất workbook 123 thành CSV',
                'Export file 88 ra JSON',
            ],
        ],

        'context' => function( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: BizCity Tool Sheet\n"
                . "Domain: Spreadsheet studio MVP cho workbook, bảng kế hoạch, dashboard và công thức.\n"
                . "MVP: lưu workbook bằng CPT + JSON; UI editor shell ở /tool-sheet/; export JSON/CSV; chuẩn bị gắn SpreadJS hoặc engine tương đương ở phase sau.\n"
                . 'Goal: ' . $goal . "\n";
        },
    ] );
} );

add_action( 'init', function() {
    add_rewrite_rule( '^tool-sheet/?$', 'index.php?bizcity_agent_page=tool-sheet', 'top' );
} );

add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );

add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-sheet' ) {
        include BZTOOL_SHEET_DIR . 'views/page-sheet.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
