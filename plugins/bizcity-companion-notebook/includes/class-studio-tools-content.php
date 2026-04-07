<?php
/**
 * Studio Built-in Content Tools - Analytics Page + TikTok Script
 *
 * Both tools:
 *  - Save a self-contained HTML file to wp-content/uploads/bizcity-studio/
 *    so full CSS (including style blocks and Google Fonts) is preserved.
 *  - Return content_format:html and data.url pointing to that file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 */

defined( 'ABSPATH' ) || exit;

class BCN_Studio_Tools_Content {

private const PALETTE = [ 'amber', 'purple', 'blue', 'teal', 'green', 'rose', 'cyan', 'gold' ];

/* ====================================================
 *  Registration
 * ==================================================== */

public static function register( BCN_Notebook_Tool_Registry $registry ): void {
$registry->add( [
'type'        => 'analytics_page',
'label'       => 'Trang Analytics',
'description' => 'Tao trang HTML analytics/infographic dep tu du an',
'icon'        => '📊',
'color'       => 'purple',
'category'    => 'publish',
'mode'        => 'built-in',
'available'   => true,
'callback'    => [ self::class, 'generate_analytics_page' ],
] );

$registry->add( [
'type'        => 'tiktok_script',
'label'       => 'Kich ban TikTok',
'description' => 'Tao kich ban video TikTok 60 giay per canh',
'icon'        => '🎬',
'color'       => 'pink',
'category'    => 'content',
'mode'        => 'built-in',
'available'   => true,
'callback'    => [ self::class, 'generate_tiktok_script' ],
] );
}

/* ====================================================
 *  SHARED: Publish HTML as WP page (kses-safe)
 * ==================================================== */

/**
 * Create a WordPress page with full HTML content.
 * Temporarily removes kses filters so <style> and full CSS are preserved.
 *
 * @return array{post_id:int,url:string}
 */
private static function publish_page( string $html, string $title, string $tool_type ): array {
	// Remove kses so <style> tags survive wp_insert_post().
	kses_remove_filters();

	$post_id = wp_insert_post( [
		'post_title'   => sanitize_text_field( $title ),
		'post_content' => $html,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'meta_input'   => [
			'_bcn_studio_output' => '1',
			'_bcn_tool_type'     => $tool_type,
			'_bcn_full_html'     => '1',
		],
	] );

	// Re-enable kses immediately.
	kses_init_filters();

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return [ 'post_id' => 0, 'url' => '' ];
	}

	return [
		'post_id' => (int) $post_id,
		'url'     => (string) get_permalink( $post_id ),
	];
}

/* ====================================================
 *  SHARED: Skeleton -> context text
 * ==================================================== */

private static function build_context_text( array $skeleton ): string {
$parts = [];
$n = $skeleton['nucleus'] ?? [];
if ( $n ) {
$parts[] = 'CHU DE CHINH: ' . ( $n['title'] ?? '' ) . "\n" . ( $n['thesis'] ?? '' );
}
if ( ! empty( $skeleton['skeleton'] ) ) {
$parts[] = 'CAU TRUC:' . "\n" . self::flatten_skeleton( $skeleton['skeleton'] );
}
if ( ! empty( $skeleton['key_points'] ) ) {
$parts[] = 'DIEM CHINH:' . "\n- " . implode( "\n- ", $skeleton['key_points'] );
}
if ( ! empty( $skeleton['entities'] ) ) {
$lines = [];
foreach ( $skeleton['entities'] as $e ) {
$lines[] = ( $e['name'] ?? '' ) . ' (' . ( $e['type'] ?? '' ) . '): ' . ( $e['role'] ?? '' );
}
$parts[] = 'DOI TUONG:' . "\n" . implode( "\n", $lines );
}
if ( ! empty( $skeleton['decisions'] ) ) {
$parts[] = 'QUYET DINH:' . "\n- " . implode( "\n- ", $skeleton['decisions'] );
}
if ( ! empty( $skeleton['timeline'] ) ) {
$tl = [];
foreach ( $skeleton['timeline'] as $t ) {
$tl[] = ( $t['label'] ?? '' ) . ': ' . ( $t['description'] ?? '' );
}
$parts[] = 'TIMELINE:' . "\n" . implode( "\n", $tl );
}
return implode( "\n\n", $parts );
}

private static function flatten_skeleton( array $nodes, int $depth = 0 ): string {
$lines = [];
foreach ( $nodes as $node ) {
$indent  = str_repeat( '  ', $depth );
$lines[] = $indent . '- ' . ( $node['label'] ?? '' ) . ': ' . ( $node['summary'] ?? '' );
if ( ! empty( $node['children'] ) ) {
$lines[] = self::flatten_skeleton( $node['children'], $depth + 1 );
}
}
return implode( "\n", $lines );
}

/* ====================================================
 *  SHARED: BTNET-style base CSS
 *  Matches BTNET_N3_CoreMember_Proposal_2026_v1.html
 * ==================================================== */

private static function base_css(): string {
return "
@import url('https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap');
:root{
  --bg:#0a0d14;--surface:#0f1219;--surface2:#151b25;
  --border:rgba(255,255,255,.07);
  --text:#e2e8f0;--text-dim:#94a3b8;--text-muted:#64748b;
  --amber:#f59e0b;--amber-dim:rgba(245,158,11,.12);--amber-glow:rgba(245,158,11,.3);
  --gold:#c9a84c;--gold-dim:rgba(201,168,76,.12);--gold-glow:rgba(201,168,76,.3);
  --purple:#a78bfa;--purple-dim:rgba(167,139,250,.12);--purple-glow:rgba(167,139,250,.3);
  --blue:#3b82f6;--blue-dim:rgba(59,130,246,.12);--blue-glow:rgba(59,130,246,.3);
  --teal:#14b8a6;--teal-dim:rgba(20,184,166,.12);--teal-glow:rgba(20,184,166,.3);
  --green:#22c55e;--green-dim:rgba(34,197,94,.12);--green-glow:rgba(34,197,94,.3);
  --rose:#f43f5e;--rose-dim:rgba(244,63,94,.12);--rose-glow:rgba(244,63,94,.3);
  --cyan:#06b6d4;--cyan-dim:rgba(6,182,212,.12);--cyan-glow:rgba(6,182,212,.3);
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Be Vietnam Pro',system-ui,sans-serif;font-size:13px;line-height:1.6;padding:24px;min-height:100vh}
.container{max-width:1200px;margin:0 auto}

/* HEADER */
.header{text-align:center;margin-bottom:32px;padding:36px 20px 24px}
.header-badge{display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;padding:5px 16px;border-radius:20px;background:var(--amber-dim);border:1px solid var(--amber-glow);color:var(--amber);margin-bottom:14px}
.header h1{font-family:'Playfair Display',serif;font-size:34px;font-weight:800;line-height:1.2;margin-bottom:10px}
.header h1 .accent{color:var(--amber)}
.header .subtitle{font-size:14px;color:var(--text-dim);max-width:820px;margin:0 auto;line-height:1.7}
.header .sub-note{font-size:11px;color:var(--text-muted);margin-top:8px}

/* STATS BAR */
.stats-bar{display:flex;justify-content:center;gap:40px;flex-wrap:wrap;margin:0 0 28px}
.stat-item{text-align:center}
.stat-num{font-size:32px;font-weight:800;background:linear-gradient(135deg,var(--amber),var(--gold));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.stat-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600}

/* SECTION TITLE */
.section-title{font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--amber);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title::before{content:'';width:3px;height:16px;background:var(--amber);border-radius:2px}
.section-heading{font-size:22px;font-weight:700;margin-bottom:6px;color:var(--text)}
.section-sub{font-size:12px;color:var(--text-dim);margin-bottom:20px;line-height:1.7}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.card-amber::before{background:linear-gradient(90deg,transparent,var(--amber),transparent)}
.card-purple::before{background:linear-gradient(90deg,transparent,var(--purple),transparent)}
.card-blue::before{background:linear-gradient(90deg,transparent,var(--blue),transparent)}
.card-teal::before{background:linear-gradient(90deg,transparent,var(--teal),transparent)}
.card-green::before{background:linear-gradient(90deg,transparent,var(--green),transparent)}
.card-rose::before{background:linear-gradient(90deg,transparent,var(--rose),transparent)}
.card-cyan::before{background:linear-gradient(90deg,transparent,var(--cyan),transparent)}
.card-gold::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}

/* GRIDS */
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:24px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.grid-full{margin-bottom:24px}

/* BADGE */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;padding:3px 10px;border-radius:6px;letter-spacing:.5px}
.badge-amber{background:var(--amber-dim);border:1px solid var(--amber-glow);color:var(--amber)}
.badge-purple{background:var(--purple-dim);border:1px solid var(--purple-glow);color:var(--purple)}
.badge-blue{background:var(--blue-dim);border:1px solid var(--blue-glow);color:var(--blue)}
.badge-teal{background:var(--teal-dim);border:1px solid var(--teal-glow);color:var(--teal)}
.badge-green{background:var(--green-dim);border:1px solid var(--green-glow);color:var(--green)}
.badge-rose{background:var(--rose-dim);border:1px solid var(--rose-glow);color:var(--rose)}
.badge-cyan{background:var(--cyan-dim);border:1px solid var(--cyan-glow);color:var(--cyan)}
.badge-gold{background:var(--gold-dim);border:1px solid var(--gold-glow);color:var(--gold)}

/* LABEL */
.label{font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px}
.label-amber{color:var(--amber)}
.label-purple{color:var(--purple)}

/* ITEM / PHASE LIST */
.item-list{display:flex;flex-direction:column;gap:8px}
.item{display:flex;gap:8px;align-items:flex-start;font-size:11.5px;color:var(--text-dim);line-height:1.5}
.item strong{color:var(--text);font-weight:600}
.item-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;margin-top:5px}
.phase-list{display:flex;flex-direction:column;gap:8px}
.phase-item{display:flex;gap:10px;align-items:flex-start}
.phase-num{font-size:10px;font-weight:700;min-width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pn-amber{background:rgba(245,158,11,.2);color:var(--amber)}
.pn-purple{background:rgba(167,139,250,.2);color:var(--purple)}
.pn-blue{background:rgba(59,130,246,.2);color:var(--blue)}
.pn-teal{background:rgba(20,184,166,.2);color:var(--teal)}
.pn-green{background:rgba(34,197,94,.2);color:var(--green)}
.pn-rose{background:rgba(244,63,94,.2);color:var(--rose)}
.pn-cyan{background:rgba(6,182,212,.2);color:var(--cyan)}
.pn-gold{background:rgba(201,168,76,.2);color:var(--gold)}
.phase-txt{font-size:11.5px;color:var(--text-dim);line-height:1.6}
.phase-txt strong{color:var(--text);font-weight:600}
.phase-txt em{font-size:10px;color:var(--text-muted);font-style:normal}

/* FLOW BAR */
.flow-bar{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:14px}
.flow-row{display:flex;align-items:center;flex-wrap:wrap;gap:4px}
.flow-node{border-radius:8px;padding:6px 14px;font-size:11px;font-weight:600;white-space:nowrap}
.fn-amber{background:var(--amber-dim);border:1px solid var(--amber-glow);color:var(--amber)}
.fn-purple{background:var(--purple-dim);border:1px solid var(--purple-glow);color:var(--purple)}
.fn-blue{background:var(--blue-dim);border:1px solid var(--blue-glow);color:var(--blue)}
.fn-teal{background:var(--teal-dim);border:1px solid var(--teal-glow);color:var(--teal)}
.fn-green{background:var(--green-dim);border:1px solid var(--green-glow);color:var(--green)}
.fn-rose{background:var(--rose-dim);border:1px solid var(--rose-glow);color:var(--rose)}
.fn-gold{background:var(--gold-dim);border:1px solid var(--gold-glow);color:var(--gold)}
.flow-arr{font-size:12px;color:var(--text-muted);padding:0 4px}

/* HIGHLIGHT BOX */
.hb{border-radius:10px;padding:14px 16px;margin-bottom:12px}
.hb-amber{background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2)}
.hb-purple{background:rgba(167,139,250,.05);border:1px solid rgba(167,139,250,.2)}
.hb-blue{background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.2)}
.hb-green{background:rgba(34,197,94,.05);border:1px solid rgba(34,197,94,.2)}
.hb-rose{background:rgba(244,63,94,.05);border:1px solid rgba(244,63,94,.2)}
.hb-gray{background:rgba(255,255,255,.02);border:1px solid var(--border)}

/* DIVIDER and FOOTER */
.divider{height:1px;background:var(--border);margin:32px 0}
.footer{text-align:center;font-size:10px;color:var(--text-muted);margin-top:40px;padding:16px;border-top:1px solid var(--border);line-height:1.8}

@media(max-width:900px){
  .grid-2,.grid-3,.grid-4{grid-template-columns:1fr}
  .stats-bar{gap:20px}
  .header h1{font-size:24px}
}
";
}

private static function html_wrap( string $title, string $body, string $css ): string {
$t = esc_html( $title );
$ts = current_time( 'd/m/Y H:i' );
return "<!DOCTYPE html>
<html lang=\"vi\">
<head>
<meta charset=\"UTF-8\">
<meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\">
<title>{$t}</title>
<style>{$css}</style>
</head>
<body>
<div class=\"container\">
{$body}
</div>
</body>
</html>";
}

/* ====================================================
 *  TOOL 1: Analytics Page
 * ==================================================== */

public static function generate_analytics_page( array $skeleton ): array {
$nucleus = $skeleton['nucleus'] ?? [];
$context = self::build_context_text( $skeleton );

$data = self::llm_extract_analytics_data( $context );
if ( is_wp_error( $data ) ) {
$data = self::skeleton_to_analytics_data( $skeleton );
}

$html  = self::render_analytics_html( $data );
$title = sanitize_text_field( $data['title'] ?? ( $nucleus['title'] ?? 'Analytics' ) );
$page  = self::publish_page( $html, $title . ' — BizCity Analytics', 'analytics_page' );

return [
'content'        => $html,
'content_format' => 'html',
'title'          => $title . ' — Analytics',
'data'           => [ 'type' => 'analytics_page', 'id' => $page['post_id'], 'url' => $page['url'] ],
];
}

private static function llm_extract_analytics_data( string $context ) {
if ( ! function_exists( 'bizcity_openrouter_chat' ) && ! function_exists( 'bizcity_llm_chat' ) ) {
return new WP_Error( 'no_llm', 'LLM not available' );
}
$colors = implode( '|', self::PALETTE );
$prompt = "Trich xuat du lieu analytics tu tai lieu sau. Tra ve JSON THUAN, khong markdown, khong giai thich.\n"
. "{\n"
. '  "title":"Tieu de 5-8 tu",' . "\n"
. '  "subtitle":"Mo ta phu 1 cau",' . "\n"
. '  "badge":"Nhan ngan VD BAO CAO Q1 2026",' . "\n"
. '  "highlight_stats":[{"label":"Ten","value":"Gia tri so"}],' . "\n"
. '  "sections":[{"title":"Ten muc","color":"' . $colors . '","items":[{"title":"...","body":"...","badge":""}]}],' . "\n"
. '  "flow_nodes":[{"label":"Buoc","color":"amber|blue|teal|green|purple"}],' . "\n"
. '  "key_insights":["Insight 1"],' . "\n"
. '  "open_questions":["Cau hoi 1"]' . "\n"
. "}\n\n"
. "highlight_stats: 3-6 chi so thuc, bo qua neu khong co so cu the.\n"
. "sections: 2-4 muc, moi muc 3-6 items.\n"
. "flow_nodes: 4-8 nut quy trinh, [] neu khong co.\n"
. "key_insights: 3-5 insight truc tiep tu tai lieu.\n"
. "open_questions: 2-3, [] neu khong co.\n\n"
. "NGU CANH:\n" . $context;

$messages = [
[ 'role' => 'system', 'content' => 'Tra ve JSON thuan, khong co bat ky text nao khac.' ],
[ 'role' => 'user',   'content' => $prompt ],
];
$result = function_exists( 'bizcity_openrouter_chat' )
? bizcity_openrouter_chat( $messages, [ 'max_tokens' => 2000, 'temperature' => 0.2 ] )
: bizcity_llm_chat( $messages, [ 'max_tokens' => 2000, 'temperature' => 0.2 ] );

if ( empty( $result['success'] ) ) {
return new WP_Error( 'llm_failed', $result['error'] ?? 'LLM failed' );
}
$text = trim( $result['message'] ?? '' );
if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) {
$text = trim( $m[1] );
}
$json = json_decode( $text, true );
if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json ) ) {
return new WP_Error( 'parse_error', 'Cannot parse JSON' );
}
return $json;
}

private static function skeleton_to_analytics_data( array $skeleton ): array {
$n  = $skeleton['nucleus'] ?? [];
$pl = self::PALETTE;
$s  = [];
foreach ( array_slice( $skeleton['skeleton'] ?? [], 0, 4 ) as $i => $node ) {
$items = [];
foreach ( $node['children'] ?? [] as $c ) {
$items[] = [ 'title' => $c['label'] ?? '', 'body' => $c['summary'] ?? '', 'badge' => '' ];
}
if ( empty( $items ) ) {
$items[] = [ 'title' => $node['summary'] ?? '', 'body' => '', 'badge' => '' ];
}
$s[] = [ 'title' => $node['label'] ?? 'Muc ' . ( $i + 1 ), 'color' => $pl[ $i % count( $pl ) ], 'items' => $items ];
}
return [
'title'           => $n['title'] ?? 'Analytics',
'subtitle'        => $n['thesis'] ?? '',
'badge'           => 'PHAN TICH DU LIEU',
'highlight_stats' => [],
'sections'        => $s,
'flow_nodes'      => [],
'key_insights'    => array_slice( $skeleton['key_points'] ?? [], 0, 5 ),
'open_questions'  => array_slice( $skeleton['open_questions'] ?? [], 0, 3 ),
];
}

private static function render_analytics_html( array $data ): string {
$css   = self::base_css();
$title = esc_html( $data['title'] ?? 'Analytics' );
$badge = esc_html( strtoupper( $data['badge'] ?? 'PHAN TICH' ) );
$sub   = esc_html( $data['subtitle'] ?? '' );
$sl    = $sub ? "<p class=\"subtitle\">{$sub}</p>" : '';
$pal   = self::PALETTE;

// Stats bar
$sh = '';
foreach ( $data['highlight_stats'] ?? [] as $s ) {
$sh .= '<div class="stat-item"><div class="stat-num">' . esc_html( $s['value'] ?? '-' ) . '</div><div class="stat-label">' . esc_html( $s['label'] ?? '' ) . '</div></div>';
}
$sb = $sh ? "<div class=\"stats-bar\">{$sh}</div><div class=\"divider\"></div>" : '';

// Sections
$secs = $data['sections'] ?? [];
$sg   = count( $secs ) >= 3 ? 'grid-3' : 'grid-2';
$si   = '';
foreach ( $secs as $ix => $sec ) {
$c  = $sec['color'] ?? $pal[ $ix % count( $pal ) ];
$pn = 'pn-' . $c;
$ri = '';
foreach ( $sec['items'] ?? [] as $j => $item ) {
$body  = ! empty( $item['body'] ) ? '<em> - ' . esc_html( $item['body'] ) . '</em>' : '';
$bdg   = ! empty( $item['badge'] ) ? ' <span class="badge badge-' . esc_attr( $c ) . '">' . esc_html( $item['badge'] ) . '</span>' : '';
$ri   .= '<div class="phase-item"><div class="phase-num ' . $pn . '">' . ( $j + 1 ) . '</div><div class="phase-txt"><strong>' . esc_html( $item['title'] ?? '' ) . '</strong>' . $body . $bdg . '</div></div>';
}
$si .= '<div class="card card-' . esc_attr( $c ) . '"><div class="label label-' . esc_attr( $c ) . '">' . esc_html( strtoupper( $c ) ) . '</div><h3 style="font-size:15px;font-weight:700;margin-bottom:14px;color:var(--text)">' . esc_html( $sec['title'] ?? '' ) . '</h3><div class="phase-list">' . $ri . '</div></div>';
}
$secb = $si ? "<div class=\"{$sg}\">{$si}</div>" : '';

// Flow nodes
$fb = '';
if ( ! empty( $data['flow_nodes'] ) ) {
$fp  = [ 'amber', 'purple', 'blue', 'teal', 'green', 'rose', 'gold' ];
$fh  = '';
foreach ( $data['flow_nodes'] as $i => $node ) {
if ( $i > 0 ) {
$fh .= '<span class="flow-arr">-&gt;</span>';
}
$fc  = $node['color'] ?? $fp[ $i % count( $fp ) ];
$fh .= '<div class="flow-node fn-' . esc_attr( $fc ) . '">' . esc_html( $node['label'] ?? '' ) . '</div>';
}
$fb = '<div class="divider"></div><div class="grid-full"><div class="section-title">Quy Trinh</div><div class="flow-bar"><div class="flow-row">' . $fh . '</div></div></div>';
}

// Insights
$ib = '';
if ( ! empty( $data['key_insights'] ) ) {
$lis = '';
foreach ( $data['key_insights'] as $ins ) {
$lis .= '<div class="item"><div class="item-dot" style="background:var(--amber)"></div><div>' . esc_html( $ins ) . '</div></div>';
}
$ib = '<div class="hb hb-amber"><div class="label label-amber">Key Insights</div><div class="item-list" style="margin-top:8px">' . $lis . '</div></div>';
}

// Open questions
$oq = '';
if ( ! empty( $data['open_questions'] ) ) {
$lis = '';
foreach ( $data['open_questions'] as $q ) {
$lis .= '<div class="item"><div class="item-dot" style="background:var(--purple)"></div><div>' . esc_html( $q ) . '</div></div>';
}
$oq = '<div class="hb hb-purple"><div class="label label-purple">Cau Hoi Mo</div><div class="item-list" style="margin-top:8px">' . $lis . '</div></div>';
}
$extra = ( $ib || $oq ) ? '<div class="divider"></div><div class="grid-2">' . $ib . $oq . '</div>' : '';

$ts   = current_time( 'd/m/Y H:i' );
$body = "
  <div class=\"header\">
    <div class=\"header-badge\">&#x1F4CA; {$badge}</div>
    <h1><span class=\"accent\">{$title}</span></h1>
    {$sl}
  </div>

  {$sb}

  <div class=\"section-title\">Phan Tich Chi Tiet</div>
  {$secb}

  {$fb}

  {$extra}

  <div class=\"footer\">&#x1F4CA; BizCity Studio Analytics &middot; {$ts}</div>";

return self::html_wrap( $title . ' - BizCity Analytics', $body, $css );
}

/* ====================================================
 *  TOOL 2: TikTok Script
 * ==================================================== */

public static function generate_tiktok_script( array $skeleton ): array {
$nucleus = $skeleton['nucleus'] ?? [];
$context = self::build_context_text( $skeleton );
$topic   = $nucleus['title'] ?? 'chu de';

$data = self::llm_extract_tiktok_data( $context );
if ( is_wp_error( $data ) ) {
$data = [
'title'    => $topic,
'target'   => 'Nguoi dung mang xa hoi',
'music'    => 'Nhac trending',
'scenes'   => [],
'hashtags' => [],
'notes'    => [ (string) $data->get_error_message() ],
];
}

$html  = self::render_tiktok_html( $data );
$title = sanitize_text_field( $data['title'] ?? $topic );
$page  = self::publish_page( $html, $title . ' — Kịch bản TikTok', 'tiktok_script' );

return [
'content'        => $html,
'content_format' => 'html',
'title'          => $title . ' - Kich ban TikTok',
'data'           => [ 'type' => 'tiktok_script', 'id' => $page['post_id'], 'url' => $page['url'] ],
];
}

private static function llm_extract_tiktok_data( string $context ) {
if ( ! function_exists( 'bizcity_openrouter_chat' ) && ! function_exists( 'bizcity_llm_chat' ) ) {
return new WP_Error( 'no_llm', 'LLM not available' );
}
$prompt = "Tao kich ban TikTok 60 giay tu noi dung tai lieu. Tra ve JSON THUAN:\n"
. "{\n"
. "  \"title\":\"Tieu de video viral 5-7 tu\",\n"
. "  \"target\":\"Doi tuong muc tieu cu the\",\n"
. "  \"music\":\"Goi y am thanh/trend sound\",\n"
. "  \"scenes\":[\n"
. "    {\"label\":\"HOOK\",\"timing\":\"0-3\",\"color\":\"rose\",\"camera\":\"mo ta goc quay\",\"voiceover\":\"cau noi manh\",\"caption\":\"text man hinh\"},\n"
. "    {\"label\":\"VAN DE\",\"timing\":\"3-12\",\"color\":\"amber\",\"camera\":\"...\",\"voiceover\":\"...\",\"caption\":\"...\"},\n"
. "    {\"label\":\"GIA TRI\",\"timing\":\"12-40\",\"color\":\"blue\",\"camera\":\"...\",\"voiceover\":\"...\",\"caption\":\"...\"},\n"
. "    {\"label\":\"BANG CHUNG\",\"timing\":\"40-52\",\"color\":\"teal\",\"camera\":\"...\",\"voiceover\":\"...\",\"caption\":\"...\"},\n"
. "    {\"label\":\"CALL TO ACTION\",\"timing\":\"52-60\",\"color\":\"green\",\"camera\":\"...\",\"voiceover\":\"...\",\"caption\":\"...\"}\n"
. "  ],\n"
. "  \"hashtags\":[\"#tag1\"],\n"
. "  \"notes\":[\"ghi chu san xuat 1\"]\n"
. "}\n\n"
. "QUY TAC: HOOK gay to mo trong 3 giay dau. Loi thoai doi thuong. Caption toi da 6 tu. Hashtags 8-12 tag.\n\n"
. "NGU CANH:\n" . $context;

$messages = [
[ 'role' => 'system', 'content' => 'Ban la chuyen gia TikTok viral. Tra ve JSON thuan.' ],
[ 'role' => 'user',   'content' => $prompt ],
];
$result = function_exists( 'bizcity_openrouter_chat' )
? bizcity_openrouter_chat( $messages, [ 'max_tokens' => 2500, 'temperature' => 0.75 ] )
: bizcity_llm_chat( $messages, [ 'max_tokens' => 2500, 'temperature' => 0.75 ] );

if ( empty( $result['success'] ) ) {
return new WP_Error( 'llm_failed', $result['error'] ?? 'LLM failed' );
}
$text = trim( $result['message'] ?? '' );
if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) {
$text = trim( $m[1] );
}
$json = json_decode( $text, true );
if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json ) ) {
return new WP_Error( 'parse_error', 'Cannot parse TikTok JSON' );
}
return $json;
}

private static function render_tiktok_html( array $data ): string {
$css    = self::base_css();
$title  = esc_html( $data['title'] ?? 'Kich ban TikTok' );
$target = esc_html( $data['target'] ?? '' );
$music  = esc_html( $data['music'] ?? '' );
$icons  = [ 'HOOK' => '&#x1F3A3;', 'VAN DE' => '&#x1F525;', 'GIA TRI' => '&#x1F48E;', 'BANG CHUNG' => '&#x2705;', 'CALL TO ACTION' => '&#x1F4E3;' ];

// Scene cards
$sh = '';
foreach ( $data['scenes'] ?? [] as $sc ) {
$c   = esc_attr( $sc['color'] ?? 'amber' );
$lbl = esc_html( $sc['label'] ?? '' );
$tm  = esc_html( $sc['timing'] ?? '' );
$ico = $icons[ $sc['label'] ?? '' ] ?? '&#x1F3AC;';
$cam = esc_html( $sc['camera'] ?? '' );
$vo  = esc_html( $sc['voiceover'] ?? '' );
$cap = esc_html( $sc['caption'] ?? '' );
$sh .= "<div class=\"card card-{$c}\">
  <div style=\"display:flex;align-items:center;justify-content:space-between;margin-bottom:14px\">
    <span class=\"badge badge-{$c}\">{$ico} {$lbl}</span>
    <span style=\"font-size:10px;font-weight:700;color:var(--{$c});background:var(--{$c}-dim);border:1px solid var(--{$c}-glow);padding:3px 12px;border-radius:100px\">&#x23F1; {$tm} giay</span>
  </div>
  <div class=\"label\">&#x1F4F8; GOC QUAY / HINH ANH</div>
  <div class=\"phase-txt\" style=\"margin-bottom:14px\">{$cam}</div>
  <div class=\"label\">&#x1F399; LOI THOAI</div>
  <div class=\"hb hb-{$c}\" style=\"margin:6px 0 14px;font-size:13px;font-style:italic;line-height:1.8;color:var(--text)\">&ldquo;{$vo}&rdquo;</div>
  <div class=\"label\">&#x1F4F1; CAPTION ON-SCREEN</div>
  <div style=\"background:rgba(0,0,0,.5);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px;font-weight:700;color:var(--text);letter-spacing:.3px;line-height:1.5\">{$cap}</div>
</div>";
}
$scb = $sh ? "<div class=\"grid-2\">{$sh}</div>" : '<p style="color:var(--text-muted)">Chua co canh nao.</p>';

// Hashtags
$hh = '';
foreach ( $data['hashtags'] ?? [] as $tag ) {
$hh .= '<span class="badge badge-purple" style="font-size:11px;padding:5px 12px">' . esc_html( $tag ) . '</span> ';
}
$hb = $hh ? '<div class="divider"></div><div class="section-title">Hashtag</div><div class="hb hb-gray grid-full" style="display:flex;flex-wrap:wrap;gap:8px">' . $hh . '</div>' : '';

// Notes
$nh = '';
foreach ( $data['notes'] ?? [] as $i => $note ) {
$nh .= '<div class="phase-item"><div class="phase-num pn-amber">' . ( $i + 1 ) . '</div><div class="phase-txt">' . esc_html( $note ) . '</div></div>';
}
$nb = $nh ? '<div class="divider"></div><div class="section-title">Ghi Chu San Xuat</div><div class="card card-amber"><div class="phase-list">' . $nh . '</div></div>' : '';

$ts   = current_time( 'd/m/Y H:i' );
$body = "
  <div class=\"header\">
    <div class=\"header-badge\" style=\"background:var(--rose-dim);border-color:var(--rose-glow);color:var(--rose)\">&#x1F3AC; KICH BAN TIKTOK 60 GIAY</div>
    <h1 style=\"-webkit-text-fill-color:unset\"><span style=\"color:var(--rose)\">{$title}</span></h1>
    <div style=\"display:flex;justify-content:center;gap:28px;flex-wrap:wrap;margin-top:16px\">
      <div style=\"font-size:11px;color:var(--text-dim)\">&#x1F465; Target: <strong style=\"color:var(--text)\">{$target}</strong></div>
      <div style=\"font-size:11px;color:var(--text-dim)\">&#x23F1; Thoi luong: <strong style=\"color:var(--amber)\">60 giay</strong></div>
      <div style=\"font-size:11px;color:var(--text-dim)\">&#x1F3B5; Nhac: <strong style=\"color:var(--text)\">{$music}</strong></div>
    </div>
  </div>

  <div class=\"section-title\">Timeline Canh Quay</div>
  {$scb}

  {$hb}

  {$nb}

  <div class=\"footer\">&#x1F3AC; BizCity Studio &middot; Kich ban TikTok &middot; {$ts}</div>";

return self::html_wrap( $title . ' - Kich ban TikTok', $body, $css );
}
}

// ── Registration ──
add_action( 'bcn_register_notebook_tools', [ 'BCN_Studio_Tools_Content', 'register' ] );

// ── Template override: serve full HTML for studio pages, bypassing WP theme ──
add_action( 'template_redirect', function () {
	if ( ! is_singular( 'page' ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! get_post_meta( $post_id, '_bcn_full_html', true ) ) {
		return;
	}
	// Output the stored HTML directly — no theme wrapper.
	$content = get_post_field( 'post_content', $post_id, 'raw' );
	if ( $content ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted full-HTML studio output.
		echo $content;
		exit;
	}
} );