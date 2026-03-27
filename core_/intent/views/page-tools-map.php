<?php
/**
 * BizCity Intent — Tools Map (Universal AI Tools Panel)
 *
 * Displayed inside the Touch Bar iframe when user clicks the "Công cụ AI" icon.
 * Reads active tools from wp_bizcity_tool_registry,
 * groups by plugin, and renders clickable command cards.
 *
 * Each card sends a prompt to the parent chat via postMessage —
 * triggering the Intent Engine → Router → Planner → Tool execution flow.
 *
 * @package BizCity_Intent
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Load tool data from registry ── */
$all_tools = [];
if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
    $all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
}

/* ── Group tools by plugin ── */
$groups = [];
foreach ( $all_tools as $tool ) {
    $plugin = $tool['plugin'] ?: 'other';
    if ( ! isset( $groups[ $plugin ] ) ) {
        $groups[ $plugin ] = [];
    }
    $groups[ $plugin ][] = $tool;
}

$total_tools = count( $all_tools );

/* ══════════════════════════════════════════════════════════════
 *  DYNAMIC PLUGIN METADATA
 *  Source 1: BizCity_Market_Catalog — reads plugin file headers
 *            (Plugin Name, Icon Path, Description, Category).
 *  Source 2: BizCity_Intent_Provider_Registry — provider get_name().
 *  Gradient: auto-generated from a palette indexed by slug hash.
 * ══════════════════════════════════════════════════════════════ */

/* ── Gradient palette moved into bizc_tools_map_gradient() as static ── */

/**
 * Generate a deterministic gradient for a plugin slug.
 */
function bizc_tools_map_gradient( string $slug ): string {
    static $palette = [
        [ '#059669', '#34D399' ],  [ '#4F46E5', '#818CF8' ],
        [ '#2563EB', '#60A5FA' ],  [ '#7C3AED', '#A78BFA' ],
        [ '#DB2777', '#F472B6' ],  [ '#D97706', '#FBBF24' ],
        [ '#DC2626', '#F87171' ],  [ '#0891B2', '#22D3EE' ],
        [ '#9333EA', '#C084FC' ],  [ '#1D4ED8', '#3B82F6' ],
        [ '#EA580C', '#FB923C' ],  [ '#16A34A', '#4ADE80' ],
    ];
    $count = count( $palette );
    $idx   = abs( crc32( $slug ) ) % $count;
    return "linear-gradient(135deg, {$palette[$idx][0]}, {$palette[$idx][1]})";
}

/* ── Build $plugin_meta dynamically ── */
$plugin_meta = [];

// Source 1: Marketplace Catalog (reads plugin file headers: name, icon, description, category)
if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
    $catalog_agents = BizCity_Market_Catalog::get_agent_plugins_with_headers();
    foreach ( $catalog_agents as $agent ) {
        $slug = $agent['slug'] ?? '';
        if ( ! $slug ) continue;

        // Map marketplace slug → tool_registry plugin slug
        // Catalog uses directory slug (bizcity-agent-calo → bizcity-agent-calo)
        // Tool Registry uses short slug (calo)
        // Try to match: if catalog slug contains registry plugin key, use it
        $registry_slug = $slug;
        // Common prefix patterns: bizcity-agent-X → X, bizcity-tool-X → tool-X, bizcity-X-knowledge → X-knowledge
        if ( preg_match( '/^bizcity-agent-(.+)$/', $slug, $m ) ) {
            $registry_slug = $m[1];
        } elseif ( preg_match( '/^bizcity-(tool-.+)$/', $slug, $m ) ) {
            $registry_slug = $m[1];
        } elseif ( preg_match( '/^bizcity-(.+)$/', $slug, $m ) ) {
            $registry_slug = $m[1];
        } elseif ( $slug === 'bizcoach-map' ) {
            $registry_slug = 'bizcoach';
        }

        $plugin_meta[ $registry_slug ] = [
            'icon'        => $agent['icon_url'] ?: '🤖',
            'icon_is_url' => ! empty( $agent['icon_url'] ),
            'name'        => $agent['name'] ?: ucfirst( str_replace( [ '-', '_' ], ' ', $registry_slug ) ),
            'description' => $agent['description'] ?? '',
            'category'    => $agent['category'] ?? '',
            'gradient'    => bizc_tools_map_gradient( $registry_slug ),
        ];
    }
}

// Source 2: Provider Registry (fallback for plugins not in catalog)
if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
    $all_providers = BizCity_Intent_Provider_Registry::instance()->get_all();
    foreach ( $all_providers as $provider ) {
        $pid = $provider->get_id();
        if ( isset( $plugin_meta[ $pid ] ) ) continue; // catalog already has it
        $plugin_meta[ $pid ] = [
            'icon'        => '🤖',
            'icon_is_url' => false,
            'name'        => $provider->get_name() ?: ucfirst( str_replace( [ '-', '_' ], ' ', $pid ) ),
            'description' => '',
            'category'    => '',
            'gradient'    => bizc_tools_map_gradient( $pid ),
        ];
    }
}

// Ensure every plugin slug that appears in tool_registry has a meta entry
foreach ( array_keys( $groups ) as $gslug ) {
    if ( ! isset( $plugin_meta[ $gslug ] ) ) {
        $plugin_meta[ $gslug ] = [
            'icon'        => '🤖',
            'icon_is_url' => false,
            'name'        => ucfirst( str_replace( [ '-', '_' ], ' ', $gslug ) ),
            'description' => '',
            'category'    => '',
            'gradient'    => bizc_tools_map_gradient( $gslug ),
        ];
    }
}

/**
 * Choose human-readable label for a tool.
 * Priority: goal_label (if not raw code) → title (if not raw) → goal_description (truncated).
 */
function bizc_tools_map_label( array $tool ): string {
    $label = $tool['goal_label'] ?? '';
    if ( $label && strpos( $label, '_' ) === false && ! preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
        return $label;
    }
    $title = $tool['title'] ?? '';
    if ( $title && strpos( $title, '_' ) === false && ! preg_match( '/^[a-z0-9_]+$/i', $title ) ) {
        return $title;
    }
    $desc = $tool['goal_description'] ?? '';
    if ( $desc ) {
        return mb_strimwidth( $desc, 0, 50, '…', 'UTF-8' );
    }
    // Last resort: humanize tool_name
    return ucfirst( str_replace( '_', ' ', $tool['tool_name'] ?? 'Tool' ) );
}

/**
 * Choose the prompt message that will be sent to chat when user clicks the tool.
 * Uses goal_label if it's a natural phrase, otherwise goal_description.
 */
function bizc_tools_map_prompt( array $tool ): string {
    $label = $tool['goal_label'] ?? '';
    // If goal_label is a natural phrase (contains Vietnamese or spaces), use it
    if ( $label && strpos( $label, '_' ) === false && ! preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
        return $label;
    }
    // Fallback: use goal_description (always descriptive)
    $desc = $tool['goal_description'] ?? '';
    if ( $desc ) {
        return mb_strimwidth( $desc, 0, 100, '', 'UTF-8' );
    }
    // Last resort: title
    $title = $tool['title'] ?? '';
    if ( $title && strpos( $title, '_' ) === false ) {
        return $title;
    }
    return ucfirst( str_replace( '_', ' ', $tool['tool_name'] ?? '' ) );
}

/**
 * Build a short slot hint from required_slots JSON.
 */
function bizc_tools_map_slots( array $tool ): string {
    $req_json = $tool['required_slots'] ?? '';
    if ( ! $req_json || $req_json === '[]' ) return '';
    $slots = json_decode( $req_json, true );
    if ( ! is_array( $slots ) || empty( $slots ) ) return '';
    $names = [];
    foreach ( $slots as $key => $meta ) {
        if ( is_numeric( $key ) ) continue; // skip if it's indexed array
        $names[] = str_replace( '_', ' ', $key );
    }
    return $names ? implode( ', ', $names ) : '';
}

/**
 * Get example prompts from examples_json column.
 *
 * @return string[]  Array of example prompt strings (max 3 for display).
 */
function bizc_tools_map_examples( array $tool ): array {
    $json = $tool['examples_json'] ?? '';
    if ( ! $json ) return [];
    $examples = json_decode( $json, true );
    if ( ! is_array( $examples ) ) return [];
    return array_slice( $examples, 0, 3 );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Công cụ AI — BizCity Tools Map</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f0f4f8;
    color:#1f2937;
    -webkit-font-smoothing:antialiased;
    overflow-x:hidden;
}

.tm-page{
    max-width:100%;
    margin:0 auto;
    padding:16px 14px 32px;
}

/* ── Hero Card (compact) ── */
.tm-hero{
    background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#334155 100%);
    border-radius:16px;
    padding:18px 16px 14px;
    text-align:center;
    color:#fff;
    box-shadow:0 6px 24px rgba(15,23,42,.25);
    position:relative;
    overflow:hidden;
}
.tm-hero::before{
    content:'';position:absolute;top:-50%;right:-30%;
    width:200px;height:200px;
    background:rgba(99,102,241,.15);border-radius:50%;
}
.tm-hero-icon{font-size:36px;margin-bottom:4px;position:relative;z-index:1}
.tm-hero-title{font-size:18px;font-weight:700;margin-bottom:2px;position:relative;z-index:1}
.tm-hero-sub{font-size:12px;opacity:.75;line-height:1.4;position:relative;z-index:1}
.tm-hero-stats{
    display:flex;justify-content:center;gap:14px;
    margin-top:8px;font-size:11px;opacity:.7;
    position:relative;z-index:1;
}
.tm-hero-stats span{display:flex;align-items:center;gap:3px}

/* ── Search ── */
.tm-search{
    position:sticky;top:0;z-index:10;
    background:#f0f4f8;
    padding:10px 0 6px;
}
.tm-search-input{
    width:100%;
    padding:10px 14px 10px 38px;
    border:1.5px solid #d1d5db;
    border-radius:12px;
    font-size:14px;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%239ca3af' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") no-repeat 12px center;
    background-size:15px;
    outline:none;
    transition:border-color .2s,box-shadow .2s;
}
.tm-search-input:focus{
    border-color:#6366f1;
    box-shadow:0 0 0 3px rgba(99,102,241,.15);
}

/* ── Plugin Tab Bar ── */
.tm-tabs{
    display:flex;gap:6px;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:none;
    padding:4px 0 8px;
    scroll-snap-type:x proximity;
}
.tm-tabs::-webkit-scrollbar{display:none}
.tm-tab{
    flex-shrink:0;
    display:flex;align-items:center;gap:5px;
    padding:6px 12px;
    border-radius:20px;
    border:1.5px solid #e5e7eb;
    background:#fff;
    font-size:12.5px;font-weight:500;
    color:#6b7280;
    cursor:pointer;
    transition:all .2s;
    white-space:nowrap;
    -webkit-tap-highlight-color:transparent;
    scroll-snap-align:start;
}
.tm-tab:hover{border-color:#c7d2fe;color:#4f46e5}
.tm-tab:active{transform:scale(.95)}
.tm-tab.active{
    background:#4f46e5;color:#fff;border-color:#4f46e5;
    box-shadow:0 2px 8px rgba(79,70,229,.25);
}
.tm-tab-icon{
    width:20px;height:20px;border-radius:5px;
    display:flex;align-items:center;justify-content:center;
    font-size:13px;overflow:hidden;
}
.tm-tab-icon img{width:16px;height:16px;border-radius:3px;object-fit:cover}
.tm-tab.active .tm-tab-icon{opacity:.9}
.tm-tab-count{
    font-size:10px;font-weight:600;
    background:rgba(0,0,0,.08);
    padding:1px 5px;border-radius:8px;
    min-width:16px;text-align:center;
}
.tm-tab.active .tm-tab-count{background:rgba(255,255,255,.25);color:#fff}

/* ── Plugin Group ── */
.tm-group{margin-top:16px}
.tm-group-header{
    display:flex;align-items:center;gap:10px;
    padding:6px 2px 4px;
}
.tm-group-icon{
    width:32px;height:32px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:18px;color:#fff;flex-shrink:0;
    box-shadow:0 2px 8px rgba(0,0,0,.12);
}
.tm-group-info{flex:1;min-width:0}
.tm-group-name{font-size:13px;font-weight:700;color:#1f2937}
.tm-group-count{font-size:10.5px;color:#9ca3af;margin-top:1px}

/* ── Tool Cards Grid (2-col on mobile) ── */
.tm-group-tools{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
    margin-top:6px;
}

/* ── Tool Card (vertical card style) ── */
.tm-tool{
    display:flex;flex-direction:column;
    background:#fff;border-radius:14px;padding:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    border:1px solid #e5e7eb;
    cursor:pointer;transition:all .18s ease;
    text-decoration:none;color:inherit;
    -webkit-tap-highlight-color:transparent;
    position:relative;
    min-height:90px;
}
.tm-tool:hover{
    border-color:#c7d2fe;
    box-shadow:0 4px 16px rgba(99,102,241,.1);
    transform:translateY(-1px);
}
.tm-tool:active{transform:scale(.97);box-shadow:0 1px 3px rgba(0,0,0,.06)}

.tm-tool-top{
    display:flex;align-items:center;gap:8px;margin-bottom:6px;
}
.tm-tool-dot{
    width:8px;height:8px;border-radius:50%;
    flex-shrink:0;
}
.tm-tool-plugin-badge{
    font-size:9px;font-weight:600;
    padding:1px 6px;border-radius:6px;
    background:#f1f5f9;color:#64748b;
    border:1px solid #e2e8f0;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    max-width:calc(100% - 20px);
}
.tm-tool-body{flex:1;min-width:0}
.tm-tool-label{font-size:13px;font-weight:600;color:#1f2937;line-height:1.3;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tm-tool-desc{font-size:11px;color:#6b7280;margin-top:3px;line-height:1.35;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tm-tool-slots{
    display:flex;gap:3px;margin-top:6px;flex-wrap:wrap;
}
.tm-tool-slot{
    font-size:9px;font-weight:500;
    padding:1px 5px;border-radius:4px;
    background:#f0fdf4;color:#16a34a;
    border:1px solid #bbf7d0;
}
.tm-tool-arrow{
    position:absolute;top:10px;right:10px;
    color:#d1d5db;font-size:14px;transition:color .2s;
}
.tm-tool:hover .tm-tool-arrow{color:#6366f1}

/* ── Example Hints ── */
.tm-tool-examples{
    display:flex;flex-direction:column;gap:3px;margin-top:6px;
}
.tm-tool-example{
    font-size:10px;color:#4f46e5;
    padding:2px 6px;border-radius:5px;
    background:#eef2ff;border:1px solid #e0e7ff;
    cursor:pointer;transition:all .15s;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    max-width:100%;
}
.tm-tool-example:hover{
    background:#c7d2fe;border-color:#a5b4fc;color:#3730a3;
}
.tm-tool-example:active{transform:scale(.97)}
.tm-tool-example::before{content:'💡 ';font-size:9px}

/* ── Empty State ── */
.tm-empty{
    text-align:center;padding:48px 20px;
}
.tm-empty-icon{font-size:48px;margin-bottom:12px}
.tm-empty h3{font-size:18px;color:#374151;margin-bottom:6px}
.tm-empty p{font-size:13px;color:#9ca3af;line-height:1.5}

/* ── Footer ── */
.tm-footer{
    text-align:center;margin-top:24px;padding-top:16px;
    border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;
}

/* ── No-results ── */
.tm-no-results{
    display:none;text-align:center;padding:32px 16px;
    color:#9ca3af;font-size:14px;
}
.tm-no-results.visible{display:block}

/* ── Single-column on very narrow screens, 3-col on wider ── */
@media (max-width: 340px) {
    .tm-page{padding:10px 8px 20px}
    .tm-hero{padding:14px 12px 10px}
    .tm-hero-title{font-size:16px}
    .tm-group-tools{grid-template-columns:1fr}
    .tm-tool{padding:10px}
}
@media (min-width: 480px) {
    .tm-group-tools{grid-template-columns:repeat(3,1fr)}
}

/* ── Fade-in animation for groups ── */
.tm-group{animation:tmFadeIn .3s ease both}
@keyframes tmFadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
</style>
</head>
<body>

<div class="tm-page">

    <!-- ══ Hero Card ══ -->
    <div class="tm-hero">
        <div class="tm-hero-icon">🧰</div>
        <div class="tm-hero-title">Công cụ AI</div>
        <div class="tm-hero-sub">
            Tất cả <?php echo (int) $total_tools; ?> công cụ AI sẵn sàng phục vụ bạn.<br>
            Chạm vào công cụ → gửi lệnh → AI thực hiện tự động.
        </div>
        <div class="tm-hero-stats">
            <span>⚡ <?php echo (int) $total_tools; ?> tools</span>
            <span>📦 <?php echo count( $groups ); ?> plugins</span>
            <span>🤖 Auto-execute</span>
        </div>
    </div>

    <?php if ( empty( $all_tools ) ): ?>
    <!-- Empty state -->
    <div class="tm-empty">
        <div class="tm-empty-icon">📭</div>
        <h3>Chưa có công cụ nào</h3>
        <p>Hãy cài đặt và kích hoạt các plugin AI Agent từ Chợ AI<br>để có công cụ sử dụng.</p>
    </div>
    <?php else: ?>

    <!-- ══ Search Bar ══ -->
    <div class="tm-search">
        <input type="text" class="tm-search-input" id="tm-search"
               placeholder="Tìm công cụ... (vd: viết bài, tạo sản phẩm, tarot)"
               autocomplete="off" spellcheck="false">
    </div>

    <!-- ══ Plugin Tabs (scrollable pills) ══ -->
    <div class="tm-tabs" id="tm-tabs">
        <div class="tm-tab active" data-filter="all">
            <span class="tm-tab-icon">🧰</span>
            Tất cả
            <span class="tm-tab-count"><?php echo (int) $total_tools; ?></span>
        </div>
        <?php foreach ( $groups as $plugin_id => $tools ):
            $meta = $plugin_meta[ $plugin_id ] ?? [
                'icon'        => '🤖',
                'icon_is_url' => false,
                'name'        => ucfirst( str_replace( [ '-', '_' ], ' ', $plugin_id ) ),
                'gradient'    => bizc_tools_map_gradient( $plugin_id ),
            ];
        ?>
        <div class="tm-tab" data-filter="<?php echo esc_attr( $plugin_id ); ?>">
            <span class="tm-tab-icon">
                <?php if ( ! empty( $meta['icon_is_url'] ) ): ?>
                    <img src="<?php echo esc_url( $meta['icon'] ); ?>" alt="" loading="lazy">
                <?php else: ?>
                    <?php echo $meta['icon']; ?>
                <?php endif; ?>
            </span>
            <?php echo esc_html( $meta['name'] ); ?>
            <span class="tm-tab-count"><?php echo count( $tools ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="tm-no-results" id="tm-no-results">
        🔍 Không tìm thấy công cụ nào phù hợp
    </div>

    <!-- ══ Tool Groups ══ -->
    <div id="tm-groups">
    <?php foreach ( $groups as $plugin_id => $tools ): 
        $meta = $plugin_meta[ $plugin_id ] ?? [
            'icon'        => '🤖',
            'icon_is_url' => false,
            'name'        => ucfirst( str_replace( [ '-', '_' ], ' ', $plugin_id ) ),
            'description' => '',
            'category'    => '',
            'gradient'    => bizc_tools_map_gradient( $plugin_id ),
        ];
    ?>
    <div class="tm-group" data-plugin="<?php echo esc_attr( $plugin_id ); ?>">
        <div class="tm-group-header">
            <div class="tm-group-icon" style="background:<?php echo $meta['gradient']; ?>">
                <?php if ( ! empty( $meta['icon_is_url'] ) ): ?>
                    <img src="<?php echo esc_url( $meta['icon'] ); ?>" alt="" style="width:20px;height:20px;border-radius:5px;object-fit:cover" loading="lazy">
                <?php else: ?>
                    <?php echo $meta['icon']; ?>
                <?php endif; ?>
            </div>
            <div class="tm-group-info">
                <div class="tm-group-name"><?php echo esc_html( $meta['name'] ); ?></div>
                <div class="tm-group-count"><?php echo count( $tools ); ?> công cụ<?php
                    if ( ! empty( $meta['category'] ) ) echo ' · ' . esc_html( $meta['category'] );
                ?></div>
            </div>
        </div>
        <div class="tm-group-tools">
        <?php foreach ( $tools as $tool ):
            $label     = bizc_tools_map_label( $tool );
            $desc      = $tool['goal_description'] ?? '';
            $prompt    = bizc_tools_map_prompt( $tool );
            $slots_str = bizc_tools_map_slots( $tool );
            $examples  = bizc_tools_map_examples( $tool );
        ?>
            <div class="tm-tool"
                 data-msg="<?php echo esc_attr( $prompt ); ?>"
                 data-tool="<?php echo esc_attr( $tool['tool_name'] ?? '' ); ?>"
                 data-plugin="<?php echo esc_attr( $plugin_id ); ?>"
                 data-search="<?php echo esc_attr( mb_strtolower( $label . ' ' . $desc . ' ' . $plugin_id . ' ' . ( $meta['name'] ?? '' ) . ' ' . ( $tool['tool_name'] ?? '' ) . ' ' . implode( ' ', $examples ), 'UTF-8' ) ); ?>">
                <div class="tm-tool-top">
                    <div class="tm-tool-dot" style="background:<?php echo $meta['gradient']; ?>"></div>
                    <span class="tm-tool-plugin-badge"><?php echo esc_html( $meta['name'] ); ?></span>
                </div>
                <div class="tm-tool-body">
                    <div class="tm-tool-label"><?php echo esc_html( $label ); ?></div>
                    <?php if ( $desc ): ?>
                    <div class="tm-tool-desc"><?php echo esc_html( $desc ); ?></div>
                    <?php endif; ?>
                    <?php if ( $slots_str ): ?>
                    <div class="tm-tool-slots">
                        <?php foreach ( explode( ', ', $slots_str ) as $s ): ?>
                        <span class="tm-tool-slot"><?php echo esc_html( $s ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $examples ) ): ?>
                    <div class="tm-tool-examples">
                        <?php foreach ( $examples as $ex ): ?>
                        <span class="tm-tool-example" data-example-msg="<?php echo esc_attr( $ex ); ?>"><?php echo esc_html( $ex ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="tm-tool-arrow">→</div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- Footer -->
    <div class="tm-footer">
        BizCity Tools Map · <?php echo (int) $total_tools; ?> tools
        · <?php echo count( $groups ); ?> plugins
        · v<?php echo esc_html( defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '1.0' ); ?>
    </div>

</div><!-- /.tm-page -->

<script>
(function() {
    'use strict';

    var activeFilter = 'all';

    /* ── Send command to parent chat via postMessage ── */
    document.querySelectorAll('.tm-tool[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target.classList.contains('tm-tool-example')) return;
            e.preventDefault();
            var msg = this.getAttribute('data-msg');
            if (!msg) return;
            this.style.transform = 'scale(0.96)';
            this.style.opacity = '0.7';
            var self = this;
            setTimeout(function() { self.style.transform = ''; self.style.opacity = ''; }, 200);
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'bizcity_agent_command', source: 'bizcity-tools-map', text: msg }, '*');
            }
        });
    });

    /* ── Example hint chips ── */
    document.querySelectorAll('.tm-tool-example[data-example-msg]').forEach(function(chip) {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var msg = this.getAttribute('data-example-msg');
            if (!msg) return;
            this.style.transform = 'scale(0.95)';
            this.style.background = '#c7d2fe';
            var self = this;
            setTimeout(function() { self.style.transform = ''; self.style.background = ''; }, 250);
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'bizcity_agent_command', source: 'bizcity-tools-map', text: msg }, '*');
            }
        });
    });

    /* ── Tab filtering ── */
    var tabs     = document.querySelectorAll('.tm-tab');
    var groups   = document.querySelectorAll('.tm-group');
    var noResults = document.getElementById('tm-no-results');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            activeFilter = this.getAttribute('data-filter');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            applyFilters();
        });
    });

    /* ── Search ── */
    var searchInput = document.getElementById('tm-search');
    if (searchInput) {
        var debounceTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(applyFilters, 150);
        });
    }

    /* ── Combined filter (tab + search) ── */
    function applyFilters() {
        var query = (searchInput ? searchInput.value : '').trim().toLowerCase();
        var anyVisible = false;

        groups.forEach(function(group) {
            var pluginId = group.getAttribute('data-plugin');
            var tabMatch = (activeFilter === 'all' || pluginId === activeFilter);

            if (!tabMatch) {
                group.style.display = 'none';
                return;
            }

            var tools = group.querySelectorAll('.tm-tool');
            var groupVisible = false;

            tools.forEach(function(tool) {
                var searchText = tool.getAttribute('data-search') || '';
                if (!query || searchText.indexOf(query) !== -1) {
                    tool.style.display = '';
                    groupVisible = true;
                    anyVisible = true;
                } else {
                    tool.style.display = 'none';
                }
            });

            group.style.display = groupVisible ? '' : 'none';
        });

        if (noResults) {
            noResults.className = anyVisible || (!query && activeFilter === 'all') ? 'tm-no-results' : 'tm-no-results visible';
        }
    }
})();
</script>

</body>
</html>
