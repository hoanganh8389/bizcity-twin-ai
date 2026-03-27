<?php
/**
 * BizCity Intent — Tool Control Panel (Standalone Page)
 *
 * Displayed inside the Touch Bar iframe when admin clicks "Control Panel".
 * Same as admin page but rendered as standalone HTML (no WP admin chrome).
 * Requires `manage_options` capability.
 *
 * @package BizCity_Intent
 * @since   3.7.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Auth: require admin ── */
if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( '⛔ Bạn không có quyền truy cập trang này.', 'Permission Denied', [ 'response' => 403 ] );
}

/* ── Load data ── */
$tool_index = BizCity_Intent_Tool_Index::instance();
$tools      = $tool_index->get_all_for_control_panel();
$counts     = $tool_index->get_counts_by_plugin();
$total      = count( $tools );
$active_cnt = count( array_filter( $tools, function( $t ) { return $t['active']; } ) );
$hints_cnt  = count( array_filter( $tools, function( $t ) { return ! empty( $t['custom_hints'] ); } ) );
$nonce      = wp_create_nonce( 'bizcity_tcp_action' );
$ajax_url   = admin_url( 'admin-ajax.php' );

/**
 * Build slot summary HTML.
 */
function bizc_tcp_slot_summary( string $json, string $icon ): string {
    if ( empty( $json ) ) return '';
    $data = json_decode( $json, true );
    if ( ! is_array( $data ) || empty( $data ) ) return '';
    $names = array_keys( $data );
    return $icon . ' ' . implode( ', ', array_map( function( $n ) {
        return '<span class="tcp-slot-chip">' . esc_html( $n ) . '</span>';
    }, $names ) );
}

/**
 * Generate contextual suggestions for a tool's description & hints.
 */
function bizc_tcp_generate_suggestions( array $row ): array {
    $tool_name = $row['tool_name'] ?? '';
    $goal      = $row['goal'] ?? $tool_name;
    $plugin    = $row['plugin'] ?? '';
    $label     = $row['goal_label'] ?? $row['title'] ?? '';
    $desc      = $row['goal_description'] ?? $row['description'] ?? '';

    $haystack = mb_strtolower( $tool_name . ' ' . $goal . ' ' . $label . ' ' . $desc . ' ' . $plugin, 'UTF-8' );

    $kw_map = [
        'write|viết|soạn|tạo bài|content'    => [ 'viết bài', 'tạo nội dung', 'soạn content' ],
        'article|bài viết|blog'               => [ 'bài viết', 'blog post', 'đăng bài' ],
        'seo'                                  => [ 'SEO', 'tối ưu SEO', 'từ khóa SEO' ],
        'rewrite|viết lại|chỉnh sửa'          => [ 'viết lại', 'sửa bài', 'chỉnh sửa' ],
        'translate|dịch|chuyển ngữ'            => [ 'dịch bài', 'dịch tiếng', 'chuyển ngữ' ],
        'product|sản phẩm'                     => [ 'sản phẩm', 'tạo sản phẩm', 'đăng bán' ],
        'order|đơn hàng'                       => [ 'đơn hàng', 'tạo đơn', 'đặt hàng' ],
        'tarot'                                => [ 'tarot', 'bói bài', 'rút bài tarot' ],
        'astro|tử vi|horoscope'                => [ 'tử vi', 'chiêm tinh', 'horoscope' ],
        'natal|lá số|birth chart'              => [ 'lá số', 'bản đồ sao', 'natal chart' ],
        'gemini'                               => [ 'hỏi gemini', 'dùng gemini', 'google AI' ],
        'chatgpt|gpt|openai'                   => [ 'hỏi chatgpt', 'dùng GPT', 'hỏi AI openai' ],
        'knowledge|kiến thức|tra cứu'          => [ 'kiến thức', 'tra cứu', 'tìm hiểu' ],
        'train|học|huấn luyện'                 => [ 'học file này', 'huấn luyện AI', 'thêm kiến thức' ],
        'search|tìm kiếm'                      => [ 'tìm kiếm', 'search', 'tra cứu' ],
        'schedule|lịch|hẹn giờ'                => [ 'lên lịch đăng', 'hẹn giờ', 'schedule' ],
        'video|kịch bản'                       => [ 'tạo video', 'kịch bản video', 'script video' ],
        'image|ảnh|hình'                       => [ 'tạo ảnh', 'hình minh họa', 'upload ảnh' ],
        'warehouse|kho|nhập kho'               => [ 'nhập kho', 'xuất kho', 'tồn kho' ],
        'calo|nutrition|dinh dưỡng'            => [ 'tính calo', 'dinh dưỡng', 'calories' ],
        'report|báo cáo|thống kê'              => [ 'báo cáo', 'thống kê', 'xem report' ],
        'map|bản đồ|vị trí'                    => [ 'bản đồ', 'vị trí', 'tìm địa điểm' ],
        'forecast|dự báo'                      => [ 'dự báo', 'dự đoán', 'forecast' ],
        'help|hướng dẫn|guide'                 => [ 'hướng dẫn', 'trợ giúp', 'cách dùng' ],
        'email|thư'                            => [ 'gửi email', 'viết thư', 'soạn mail' ],
        'summary|tóm tắt'                      => [ 'tóm tắt', 'summary', 'rút gọn' ],
    ];

    $hint_suggestions = [];
    foreach ( $kw_map as $pattern => $suggestions ) {
        foreach ( explode( '|', $pattern ) as $kw ) {
            if ( mb_strpos( $haystack, $kw ) !== false ) {
                $hint_suggestions = array_merge( $hint_suggestions, $suggestions );
                break;
            }
        }
    }
    $hint_suggestions = array_unique( $hint_suggestions );

    $desc_suggestions = [];
    if ( $label && ! preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
        $desc_suggestions[] = 'Khi user muốn ' . mb_strtolower( $label, 'UTF-8' );
    }
    $action = str_replace( '_', ' ', $tool_name );
    $desc_suggestions[] = 'Dùng khi user yêu cầu ' . $action;
    if ( $desc ) {
        $desc_suggestions[] = mb_substr( $desc, 0, 80, 'UTF-8' );
    }
    $desc_suggestions = array_values( array_unique( $desc_suggestions ) );

    return [
        'hints' => array_slice( $hint_suggestions, 0, 6 ),
        'desc'  => array_slice( $desc_suggestions, 0, 3 ),
    ];
}

/**
 * Render suggestion chips HTML for the standalone page.
 */
function bizc_tcp_render_chips( array $items, string $target_class ): string {
    if ( empty( $items ) ) return '';
    $chips = '';
    foreach ( $items as $s ) {
        $chips .= '<span class="tcp-suggest-chip" data-target="' . esc_attr( $target_class ) . '" '
                . 'data-value="' . esc_attr( $s ) . '" title="Click để điền">'
                . esc_html( $s ) . '</span>';
    }
    return '<div class="tcp-suggest-row"><small class="tcp-suggest-label">💡 Gợi ý:</small>' . $chips . '</div>';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🎛️ Tool Control Panel — BizCity</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f0f4f8;
    color:#1f2937;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
    font-size:13px;
}

/* ── Page Container ── */
.tcp-page{
    max-width:100%;
    margin:0 auto;
    padding:16px 14px 32px;
}

/* ── Hero Card ── */
.tcp-hero{
    background:linear-gradient(135deg,#1e293b 0%,#334155 50%,#475569 100%);
    border-radius:16px;
    padding:20px 18px 16px;
    text-align:center;
    color:#fff;
    box-shadow:0 4px 20px rgba(15,23,42,.25);
    position:relative;
    overflow:hidden;
}
.tcp-hero::before{
    content:'';position:absolute;top:-40%;right:-20%;
    width:160px;height:160px;
    background:rgba(99,102,241,.15);border-radius:50%;
}
.tcp-hero-icon{font-size:36px;margin-bottom:4px;position:relative;z-index:1}
.tcp-hero-title{font-size:18px;font-weight:700;position:relative;z-index:1}
.tcp-hero-sub{font-size:12px;opacity:.7;margin-top:4px;position:relative;z-index:1}
.tcp-hero-stats{
    display:flex;justify-content:center;gap:14px;
    margin-top:10px;font-size:11px;opacity:.8;
    position:relative;z-index:1;
}
.tcp-hero-stats span{display:flex;align-items:center;gap:3px}

/* ── Tabs ── */
.tcp-tabs{
    display:flex;gap:0;margin-top:16px;
    border-bottom:2px solid #e5e7eb;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}
.tcp-tab{
    padding:10px 16px;
    font-size:13px;font-weight:600;
    color:#6b7280;
    background:none;border:none;
    cursor:pointer;white-space:nowrap;
    border-bottom:2px solid transparent;
    margin-bottom:-2px;
    transition:all .2s;
}
.tcp-tab:hover{color:#374151;background:rgba(0,0,0,.03)}
.tcp-tab.active{color:#4f46e5;border-bottom-color:#4f46e5}

/* ── Tab Content ── */
.tcp-tab-content{display:none;margin-top:12px}
.tcp-tab-content.active{display:block}

/* ── Toolbar ── */
.tcp-toolbar{
    display:flex;gap:8px;margin-bottom:12px;
    flex-wrap:wrap;align-items:center;
}
.tcp-btn{
    display:inline-flex;align-items:center;gap:4px;
    padding:8px 14px;border-radius:10px;
    font-size:12px;font-weight:600;
    border:1px solid #d1d5db;
    background:#fff;color:#374151;
    cursor:pointer;transition:all .15s;
    -webkit-tap-highlight-color:transparent;
}
.tcp-btn:hover{background:#f3f4f6;border-color:#9ca3af}
.tcp-btn:active{transform:scale(.97)}
.tcp-btn-primary{background:#4f46e5;color:#fff;border-color:#4f46e5}
.tcp-btn-primary:hover{background:#4338ca}
.tcp-status{font-size:12px;font-weight:600;color:#059669;margin-left:auto}

/* ── Tool Card (mobile-friendly instead of table) ── */
.tcp-tool-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:14px;
    margin-bottom:10px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
    transition:all .2s;
}
.tcp-tool-card.inactive{opacity:.5;background:#fafafa}
.tcp-tool-card.saved{animation:tcpFlash .6s ease}
@keyframes tcpFlash{0%{background:#d1fae5}100%{background:#fff}}

.tcp-card-header{
    display:flex;align-items:center;gap:10px;
    margin-bottom:10px;
}
.tcp-card-priority{
    width:40px;text-align:center;
    font-size:12px;font-weight:700;
    padding:4px;border:1.5px solid #d1d5db;
    border-radius:8px;background:#f9fafb;
    outline:none;
}
.tcp-card-priority:focus{border-color:#4f46e5;box-shadow:0 0 0 2px rgba(79,70,229,.15)}
.tcp-card-toggle{
    position:relative;width:34px;height:20px;flex-shrink:0;
}
.tcp-card-toggle input{opacity:0;width:0;height:0;position:absolute}
.tcp-card-toggle .slider{
    position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;
    background:#d1d5db;border-radius:20px;transition:.25s;
}
.tcp-card-toggle .slider::before{
    position:absolute;content:"";
    height:16px;width:16px;left:2px;bottom:2px;
    background:#fff;border-radius:50%;transition:.25s;
    box-shadow:0 1px 3px rgba(0,0,0,.15);
}
.tcp-card-toggle input:checked + .slider{background:#059669}
.tcp-card-toggle input:checked + .slider::before{transform:translateX(14px)}

.tcp-card-info{flex:1;min-width:0}
.tcp-card-goal{font-size:13px;font-weight:700;color:#1f2937;word-break:break-word}
.tcp-card-label{font-size:11px;color:#9ca3af;margin-top:1px}
.tcp-card-plugin{
    font-size:10px;font-weight:600;
    padding:2px 8px;border-radius:6px;
    background:#ede9fe;color:#7c3aed;
    flex-shrink:0;
}
.tcp-card-save{
    width:32px;height:32px;border-radius:8px;
    border:1px solid #d1d5db;background:#fff;
    cursor:pointer;font-size:16px;
    display:flex;align-items:center;justify-content:center;
    transition:all .15s;flex-shrink:0;
}
.tcp-card-save:hover{background:#f0fdf4;border-color:#86efac}
.tcp-card-save:active{transform:scale(.9)}

/* ── Card Fields ── */
.tcp-field{margin-bottom:8px}
.tcp-field-label{
    font-size:10px;font-weight:600;
    color:#6b7280;text-transform:uppercase;
    letter-spacing:.5px;margin-bottom:3px;
}
.tcp-field-provider{
    font-size:11px;color:#9ca3af;
    background:#f9fafb;padding:4px 8px;
    border-radius:6px;margin-bottom:4px;
    border-left:3px solid #e5e7eb;
}
.tcp-textarea{
    width:100%;
    font-size:12px;
    padding:8px 10px;
    border:1.5px solid #e5e7eb;
    border-radius:8px;
    resize:vertical;
    min-height:36px;
    outline:none;
    transition:border-color .2s,box-shadow .2s;
    font-family:inherit;
}
.tcp-textarea:focus{border-color:#4f46e5;box-shadow:0 0 0 2px rgba(79,70,229,.1)}
.tcp-textarea::placeholder{color:#d1d5db}

/* ── Slots ── */
.tcp-slots-row{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.tcp-slot-chip{
    font-size:9px;font-weight:500;
    padding:2px 6px;border-radius:5px;
    background:#f0fdf4;color:#16a34a;
    border:1px solid #bbf7d0;
}
.tcp-slot-chip.opt{background:#fffbeb;color:#d97706;border-color:#fde68a}

/* ── Suggestion chips ── */
.tcp-suggest-row{margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.tcp-suggest-label{color:#b0b0b0;font-size:10px;margin-right:2px}
.tcp-suggest-chip{
    display:inline-block;font-size:10px;padding:2px 7px;
    background:#f0f5ff;color:#3b82f6;border:1px solid #bfdbfe;
    border-radius:10px;cursor:pointer;transition:all .15s;
    white-space:nowrap;user-select:none;
}
.tcp-suggest-chip:hover{background:#3b82f6;color:#fff;border-color:#3b82f6}
.tcp-suggest-chip:active{transform:scale(.95)}

/* ── Preview Tab ── */
.tcp-preview-box{margin-bottom:16px}
.tcp-preview-box h4{font-size:13px;font-weight:700;margin-bottom:8px;color:#374151}
.tcp-pre{
    background:#1e293b;color:#e2e8f0;
    padding:14px;border-radius:10px;
    font-size:11px;line-height:1.6;
    overflow-x:auto;white-space:pre-wrap;word-break:break-word;
    max-height:400px;overflow-y:auto;
    font-family:'JetBrains Mono',Consolas,monospace;
}

/* ── Mermaid Tab ── */
.tcp-mermaid-box{
    background:#fff;padding:16px;border:1px solid #e5e7eb;
    border-radius:12px;min-height:200px;overflow:auto;
}
.tcp-mermaid-box svg{max-width:100%}

/* ── Stats Tab ── */
.tcp-stats-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:10px;margin-bottom:16px;
}
.tcp-stat-card{
    background:#fff;border:1px solid #e5e7eb;
    border-radius:12px;padding:16px;text-align:center;
}
.tcp-stat-num{font-size:28px;font-weight:800;color:#4f46e5}
.tcp-stat-lbl{font-size:11px;color:#6b7280;margin-top:2px}

.tcp-plugin-table{width:100%;border-collapse:collapse}
.tcp-plugin-table th{
    font-size:11px;text-transform:uppercase;letter-spacing:.5px;
    color:#6b7280;text-align:left;padding:8px 10px;
    border-bottom:2px solid #e5e7eb;
}
.tcp-plugin-table td{
    padding:8px 10px;border-bottom:1px solid #f3f4f6;font-size:13px;
}
.tcp-plugin-table tr:last-child td{border-bottom:none}

/* ── Empty placeholder ── */
.tcp-loading{text-align:center;padding:32px;color:#9ca3af;font-size:14px}

/* ── Footer ── */
.tcp-footer{
    text-align:center;margin-top:20px;padding-top:14px;
    border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;
}

/* ── Responsive ── */
@media (max-width: 380px){
    .tcp-page{padding:10px 8px 20px}
    .tcp-hero{padding:16px 12px 12px}
    .tcp-stats-grid{grid-template-columns:1fr}
}
@media (min-width: 768px){
    .tcp-stats-grid{grid-template-columns:repeat(4,1fr)}
}
</style>
</head>
<body>

<div class="tcp-page">

    <!-- ══ Hero ══ -->
    <div class="tcp-hero">
        <div class="tcp-hero-icon">🎛️</div>
        <div class="tcp-hero-title">Tool Control Panel</div>
        <div class="tcp-hero-sub">Cấu hình prompt, ưu tiên, từ khóa cho AI Router</div>
        <div class="tcp-hero-stats">
            <span>⚡ <?php echo $total; ?> tools</span>
            <span>✅ <?php echo $active_cnt; ?> active</span>
            <span>📦 <?php echo count( $counts ); ?> plugins</span>
            <span>🔑 <?php echo $hints_cnt; ?> hints</span>
        </div>
    </div>

    <!-- ══ Tabs ══ -->
    <div class="tcp-tabs" id="tcp-tabs">
        <button class="tcp-tab active" data-tab="tools">🔧 Công cụ</button>
        <button class="tcp-tab" data-tab="preview">👁️ Preview</button>
        <button class="tcp-tab" data-tab="flow">📊 Flow</button>
        <button class="tcp-tab" data-tab="stats">📈 Stats</button>
    </div>

    <!-- ════════════════════════════════════
         TAB 1: Tools
    ════════════════════════════════════ -->
    <div class="tcp-tab-content active" id="tcp-panel-tools">
        <div class="tcp-toolbar">
            <button class="tcp-btn tcp-btn-primary" id="tcp-force-sync">🔄 Đồng bộ</button>
            <button class="tcp-btn" id="tcp-save-order">💾 Lưu thứ tự</button>
            <span class="tcp-status" id="tcp-status"></span>
        </div>

        <div id="tcp-tools-list">
        <?php foreach ( $tools as $row ) :
            $id           = (int) $row['id'];
            $active       = (int) $row['active'];
            $priority     = (int) ( $row['priority'] ?? 50 );
            $goal         = $row['goal'] ?: $row['tool_name'];
            $label        = $row['goal_label'] ?: $row['title'] ?: $row['tool_name'];
            $plugin       = $row['plugin'] ?: 'builtin';
            $desc_prov    = $row['goal_description'] ?: $row['description'] ?: '';
            $desc_custom  = $row['custom_description'] ?? '';
            $hints        = $row['custom_hints'] ?? '';
            $req_html     = bizc_tcp_slot_summary( $row['required_slots'] ?? '', '🔴' );
            $opt_json     = $row['optional_slots'] ?? '';
            $opt_data     = json_decode( $opt_json, true );
            $opt_html     = '';
            if ( is_array( $opt_data ) && ! empty( $opt_data ) ) {
                $opt_html = '⚪ ' . implode( ', ', array_map( function( $n ) {
                    return '<span class="tcp-slot-chip opt">' . esc_html( $n ) . '</span>';
                }, array_keys( $opt_data ) ) );
            }
            $card_class = $active ? '' : ' inactive';
        ?>
        <div class="tcp-tool-card<?php echo $card_class; ?>" data-tool-id="<?php echo $id; ?>">
            <div class="tcp-card-header">
                <input type="number" class="tcp-card-priority" value="<?php echo $priority; ?>"
                       min="1" max="999" data-tool-id="<?php echo $id; ?>">
                <label class="tcp-card-toggle">
                    <input type="checkbox" class="tcp-toggle-cb" data-tool-id="<?php echo $id; ?>"
                           <?php checked( $active, 1 ); ?>>
                    <span class="slider"></span>
                </label>
                <div class="tcp-card-info">
                    <div class="tcp-card-goal"><?php echo esc_html( $goal ); ?></div>
                    <div class="tcp-card-label"><?php echo esc_html( $label ); ?></div>
                </div>
                <span class="tcp-card-plugin"><?php echo esc_html( $plugin ); ?></span>
                <button class="tcp-card-save" data-tool-id="<?php echo $id; ?>" title="Lưu">💾</button>
            </div>

            <?php if ( $desc_prov ) : ?>
            <div class="tcp-field-provider">📦 <?php echo esc_html( mb_substr( $desc_prov, 0, 120, 'UTF-8' ) ); ?></div>
            <?php endif; ?>

            <?php $suggestions = bizc_tcp_generate_suggestions( $row ); ?>
            <div class="tcp-field">
                <div class="tcp-field-label">Mô tả cho AI (override)</div>
                <textarea class="tcp-textarea tcp-desc" data-tool-id="<?php echo $id; ?>"
                          placeholder="✏️ Nhập mô tả tùy chỉnh cho AI Router..."
                          rows="2"><?php echo esc_textarea( $desc_custom ); ?></textarea>
                <?php if ( empty( $desc_custom ) && ! empty( $suggestions['desc'] ) ) echo bizc_tcp_render_chips( $suggestions['desc'], 'tcp-desc' ); ?>
            </div>

            <div class="tcp-field">
                <div class="tcp-field-label">Từ khóa / Hints</div>
                <textarea class="tcp-textarea tcp-hints" data-tool-id="<?php echo $id; ?>"
                          placeholder="🔑 Từ khóa kích hoạt tool này (VD: viết bài, tạo sản phẩm...)"
                          rows="2"><?php echo esc_textarea( $hints ); ?></textarea>
                <?php if ( empty( $hints ) && ! empty( $suggestions['hints'] ) ) echo bizc_tcp_render_chips( $suggestions['hints'], 'tcp-hints' ); ?>
            </div>

            <?php if ( $req_html || $opt_html ) : ?>
            <div class="tcp-slots-row">
                <?php echo $req_html; ?>
                <?php echo $opt_html; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ════════════════════════════════════
         TAB 2: Preview Prompt
    ════════════════════════════════════ -->
    <div class="tcp-tab-content" id="tcp-panel-preview">
        <div class="tcp-preview-box">
            <h4>🧠 Goal List Compact (fast classification)</h4>
            <pre class="tcp-pre" id="tcp-pre-compact">Đang tải...</pre>
        </div>
        <div class="tcp-preview-box">
            <h4>📋 Full Manifest (system prompt)</h4>
            <pre class="tcp-pre" id="tcp-pre-full">Đang tải...</pre>
        </div>
    </div>

    <!-- ════════════════════════════════════
         TAB 3: Flow Diagram
    ════════════════════════════════════ -->
    <div class="tcp-tab-content" id="tcp-panel-flow">
        <div class="tcp-mermaid-box">
            <div id="tcp-mermaid-render" class="tcp-loading">📊 Đang tải sơ đồ...</div>
        </div>
        <details style="margin-top:10px">
            <summary style="font-size:12px;color:#6b7280;cursor:pointer">📋 Xem source code</summary>
            <pre class="tcp-pre" id="tcp-mermaid-src" style="margin-top:6px"></pre>
        </details>
    </div>

    <!-- ════════════════════════════════════
         TAB 4: Statistics
    ════════════════════════════════════ -->
    <div class="tcp-tab-content" id="tcp-panel-stats">
        <div class="tcp-stats-grid">
            <div class="tcp-stat-card">
                <div class="tcp-stat-num"><?php echo $total; ?></div>
                <div class="tcp-stat-lbl">Tổng công cụ</div>
            </div>
            <div class="tcp-stat-card">
                <div class="tcp-stat-num"><?php echo $active_cnt; ?></div>
                <div class="tcp-stat-lbl">Đang hoạt động</div>
            </div>
            <div class="tcp-stat-card">
                <div class="tcp-stat-num"><?php echo count( $counts ); ?></div>
                <div class="tcp-stat-lbl">Plugins</div>
            </div>
            <div class="tcp-stat-card">
                <div class="tcp-stat-num"><?php echo $hints_cnt; ?></div>
                <div class="tcp-stat-lbl">Có custom hints</div>
            </div>
        </div>

        <table class="tcp-plugin-table">
            <thead><tr><th>Plugin</th><th>Số tools</th></tr></thead>
            <tbody>
            <?php foreach ( $counts as $plugin => $cnt ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $plugin ); ?></strong></td>
                    <td><?php echo (int) $cnt; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ── Settings: LLM Prompt Configuration ── -->
        <div style="margin-top:24px; padding:16px; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0;">
            <h3 style="margin:0 0 8px 0; font-size:15px;">⚙️ Cấu hình LLM Prompt</h3>
            <p style="font-size:12px; color:#6b7280; margin:0 0 12px 0;">Điều chỉnh cách AI Router inject tool schema vào prompt phân loại.</p>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <label for="tcp-top-n-tools" style="font-size:13px; font-weight:600;">Top N Tools:</label>
                <input type="number" id="tcp-top-n-tools"
                    value="<?php echo esc_attr( BizCity_Intent_Router::get_top_n_tools() ); ?>"
                    min="3" max="50" step="1"
                    style="width:70px; padding:6px 10px; border-radius:8px; border:1px solid #d1d5db; font-size:14px; text-align:center;">
                <button type="button" class="tcp-btn tcp-btn-primary" id="tcp-save-settings" style="padding:6px 16px;">💾 Lưu</button>
                <span id="tcp-settings-status" style="font-size:12px; font-weight:600;"></span>
            </div>
            <div style="font-size:11px; color:#9ca3af; margin-top:8px; line-height:1.5;">
                🔹 min 3, max 50, mặc định 10 · Regex pre-match → ★ tool ưu tiên đầu<br>
                🔹 Nhỏ hơn = prompt ngắn + nhanh + rẻ · Lớn hơn = chính xác hơn nhưng tốn token
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="tcp-footer">
        Tool Control Panel · v<?php echo esc_html( defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '3.8' ); ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
<script>
(function(){
    'use strict';

    var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
    var AJAX  = <?php echo wp_json_encode( $ajax_url ); ?>;

    /* ── Tabs ── */
    var tabs = document.querySelectorAll('.tcp-tab');
    var panels = document.querySelectorAll('.tcp-tab-content');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(function(t) { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var target = 'tcp-panel-' + tab.dataset.tab;
            document.getElementById(target).classList.add('active');
            // Lazy-load
            if (tab.dataset.tab === 'preview') loadPreview();
            if (tab.dataset.tab === 'flow') loadMermaid();
        });
    });

    /* ── Status ── */
    function showStatus(msg, isErr) {
        var el = document.getElementById('tcp-status');
        el.textContent = msg;
        el.style.color = isErr ? '#dc2626' : '#059669';
        setTimeout(function() { el.textContent = ''; }, 3000);
    }

    /* ── AJAX helper ── */
    function ajaxPost(action, extra, cb) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        if (extra) {
            for (var k in extra) {
                if (extra.hasOwnProperty(k)) fd.append(k, extra[k]);
            }
        }
        fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) { cb(res); })
            .catch(function(err) { showStatus('❌ ' + err.message, true); });
    }

    /* ── Suggestion chips: click to fill ── */
    document.querySelectorAll('.tcp-suggest-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var targetClass = this.dataset.target;
            var value = this.dataset.value;
            var card = this.closest('.tcp-tool-card');
            if (!card) return;
            var textarea = card.querySelector('.' + targetClass);
            if (!textarea) return;
            if (textarea.value.trim()) {
                textarea.value = textarea.value.trim() + ', ' + value;
            } else {
                textarea.value = value;
            }
            textarea.focus();
            this.style.opacity = '0.4';
            this.style.pointerEvents = 'none';
        });
    });

    /* ── Save single tool ── */
    document.querySelectorAll('.tcp-card-save').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.toolId;
            var card = document.querySelector('.tcp-tool-card[data-tool-id="' + id + '"]');
            ajaxPost('bizcity_tcp_save_tool', {
                tool_id: id,
                custom_description: card.querySelector('.tcp-desc').value,
                custom_hints: card.querySelector('.tcp-hints').value,
                priority: card.querySelector('.tcp-card-priority').value
            }, function(res) {
                if (res.success) {
                    showStatus('✅ Đã lưu tool #' + id);
                    card.classList.add('saved');
                    setTimeout(function() { card.classList.remove('saved'); }, 700);
                } else {
                    showStatus('❌ ' + (res.data || 'Error'), true);
                }
            });
        });
    });

    /* ── Toggle active ── */
    document.querySelectorAll('.tcp-toggle-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var id = this.dataset.toolId;
            var active = this.checked ? 1 : 0;
            var card = document.querySelector('.tcp-tool-card[data-tool-id="' + id + '"]');
            ajaxPost('bizcity_tcp_toggle_active', {
                tool_id: id,
                active: active
            }, function(res) {
                if (res.success) {
                    if (active) { card.classList.remove('inactive'); }
                    else { card.classList.add('inactive'); }
                    showStatus(active ? '✅ Đã bật' : '⏸️ Đã tắt');
                }
            });
        });
    });

    /* ── Save priority order ── */
    document.getElementById('tcp-save-order').addEventListener('click', function() {
        var order = {};
        document.querySelectorAll('.tcp-card-priority').forEach(function(input) {
            order[input.dataset.toolId] = parseInt(input.value) || 50;
        });
        ajaxPost('bizcity_tcp_reorder', {
            order: JSON.stringify(order)
        }, function(res) {
            if (res.success) {
                showStatus('✅ Đã lưu thứ tự (' + res.data.updated + ' tools)');
            }
        });
    });

    /* ── Force sync ── */
    document.getElementById('tcp-force-sync').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '⏳ Đang đồng bộ...';
        ajaxPost('bizcity_tcp_force_sync', {}, function(res) {
            btn.disabled = false;
            btn.textContent = '🔄 Đồng bộ';
            if (res.success) {
                showStatus('✅ ' + res.data.message);
                setTimeout(function() { location.reload(); }, 600);
            } else {
                showStatus('❌ ' + (res.data || 'Sync failed'), true);
            }
        });
    });

    /* ── Save settings (top_n_tools) ── */
    document.getElementById('tcp-save-settings').addEventListener('click', function() {
        var topN = document.getElementById('tcp-top-n-tools').value;
        ajaxPost('bizcity_tcp_save_settings', {
            top_n_tools: topN
        }, function(res) {
            var el = document.getElementById('tcp-settings-status');
            if (res.success) {
                el.textContent = '✅ ' + res.data.message;
                el.style.color = '#059669';
            } else {
                el.textContent = '❌ ' + (res.data || 'Error');
                el.style.color = '#dc2626';
            }
            setTimeout(function() { el.textContent = ''; }, 3000);
        });
    });

    /* ── Preview ── */
    var previewLoaded = false;
    function loadPreview() {
        if (previewLoaded) return;
        previewLoaded = true;
        ajaxPost('bizcity_tcp_preview_prompt', {}, function(res) {
            if (res.success) {
                document.getElementById('tcp-pre-compact').textContent = res.data.compact || '(trống)';
                document.getElementById('tcp-pre-full').textContent = res.data.full || '(trống)';
            }
        });
    }

    /* ── Mermaid ── */
    var mermaidLoaded = false;
    function loadMermaid() {
        if (mermaidLoaded) return;
        mermaidLoaded = true;
        ajaxPost('bizcity_tcp_get_mermaid', {}, function(res) {
            if (res.success && res.data.mermaid) {
                var src = res.data.mermaid;
                document.getElementById('tcp-mermaid-src').textContent = src;
                var container = document.getElementById('tcp-mermaid-render');
                container.innerHTML = '';
                container.className = '';
                var div = document.createElement('div');
                div.className = 'mermaid';
                div.textContent = src;
                container.appendChild(div);
                if (window.mermaid) {
                    mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
                    mermaid.run({ nodes: [div] });
                }
            }
        });
    }

})();
</script>

</body>
</html>
