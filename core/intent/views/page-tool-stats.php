<?php
/**
 * BizCity Intent — Tool Stats (Standalone Page)
 *
 * Displayed inside the Touch Bar iframe when admin clicks "Tool Stats".
 * Reads from `bizcity_tool_stats` table and renders in mobile-friendly format.
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
global $wpdb;
$table = $wpdb->prefix . 'bizcity_tool_stats';

// Check table exists
$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;

$stats = [];
$summary = [ 'total' => 0, 'total_calls' => 0, 'avg_success' => 0, 'tools_with_fails' => 0 ];

if ( $table_exists ) {
    $stats = $wpdb->get_results(
        "SELECT * FROM `{$table}` ORDER BY n_calls DESC, updated_at DESC LIMIT 200",
        ARRAY_A
    );

    if ( ! empty( $stats ) ) {
        $summary['total'] = count( $stats );
        $summary['total_calls'] = array_sum( array_column( $stats, 'n_calls' ) );
        $rates = array_filter( array_column( $stats, 'success_rate' ), function( $v ) { return $v !== null && $v !== ''; } );
        $summary['avg_success'] = $rates ? round( array_sum( $rates ) / count( $rates ), 1 ) : 0;
        $summary['tools_with_fails'] = count( array_filter( $stats, function( $s ) { return (int) $s['n_fail'] > 0; } ) );
    }
}

/**
 * Format millisecond number with color.
 */
function bizc_ts_fmt_ms( $val ): string {
    if ( $val === null || $val === '' ) return '<span style="color:#d1d5db">—</span>';
    $ms = (int) $val;
    $color = $ms < 500 ? '#059669' : ( $ms < 2000 ? '#d97706' : '#dc2626' );
    return '<span style="color:' . $color . ';font-weight:600">' . number_format( $ms ) . '<small>ms</small></span>';
}

/**
 * Format success rate with color.
 */
function bizc_ts_fmt_rate( $val ): string {
    if ( $val === null || $val === '' ) return '<span style="color:#d1d5db">—</span>';
    $rate = (float) $val;
    $color = $rate >= 95 ? '#059669' : ( $rate >= 80 ? '#d97706' : '#dc2626' );
    return '<span style="color:' . $color . ';font-weight:700">' . number_format( $rate, 1 ) . '%</span>';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📊 Tool Stats — BizCity</title>
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

.ts-page{max-width:100%;margin:0 auto;padding:16px 14px 32px}

/* ── Hero Card ── */
.ts-hero{
    background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#2563eb 100%);
    border-radius:16px;padding:20px 18px 16px;
    text-align:center;color:#fff;
    box-shadow:0 4px 20px rgba(15,23,42,.25);
    position:relative;overflow:hidden;
}
.ts-hero::before{
    content:'';position:absolute;top:-40%;right:-20%;
    width:160px;height:160px;
    background:rgba(96,165,250,.15);border-radius:50%;
}
.ts-hero-icon{font-size:36px;margin-bottom:4px;position:relative;z-index:1}
.ts-hero-title{font-size:18px;font-weight:700;position:relative;z-index:1}
.ts-hero-sub{font-size:12px;opacity:.7;margin-top:4px;position:relative;z-index:1}

/* ── Summary Cards ── */
.ts-summary{
    display:grid;grid-template-columns:repeat(2,1fr);
    gap:10px;margin-top:16px;
}
.ts-sum-card{
    background:#fff;border:1px solid #e5e7eb;
    border-radius:12px;padding:14px;text-align:center;
}
.ts-sum-num{font-size:24px;font-weight:800;color:#2563eb}
.ts-sum-num.warn{color:#d97706}
.ts-sum-num.danger{color:#dc2626}
.ts-sum-num.ok{color:#059669}
.ts-sum-lbl{font-size:10px;color:#6b7280;margin-top:2px;text-transform:uppercase;letter-spacing:.5px}

/* ── Search ── */
.ts-search{
    position:sticky;top:0;z-index:10;
    background:#f0f4f8;padding:12px 0;
}
.ts-search-input{
    width:100%;padding:10px 14px 10px 38px;
    border:1.5px solid #d1d5db;border-radius:12px;
    font-size:14px;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%239ca3af' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") no-repeat 12px center;
    background-size:15px;outline:none;
    transition:border-color .2s,box-shadow .2s;
}
.ts-search-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}

/* ── Stat Card ── */
.ts-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:14px;
    margin-bottom:10px;
    box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.ts-card-header{
    display:flex;align-items:center;gap:8px;
    margin-bottom:10px;
}
.ts-card-tool{font-size:13px;font-weight:700;color:#1f2937;flex:1;word-break:break-word}
.ts-card-env{
    font-size:10px;font-weight:600;
    padding:2px 8px;border-radius:6px;
    background:#dbeafe;color:#2563eb;
}
.ts-card-window{
    font-size:10px;color:#9ca3af;
}

.ts-card-metrics{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:8px;
}
.ts-metric{
    text-align:center;
    padding:8px 4px;
    background:#f9fafb;
    border-radius:8px;
}
.ts-metric-val{font-size:14px;font-weight:700}
.ts-metric-lbl{font-size:9px;color:#6b7280;margin-top:2px;text-transform:uppercase;letter-spacing:.3px}

.ts-card-detail{
    display:grid;grid-template-columns:repeat(2,1fr);
    gap:6px;margin-top:8px;font-size:11px;
}
.ts-detail-item{
    display:flex;justify-content:space-between;
    padding:4px 8px;background:#f9fafb;border-radius:6px;
}
.ts-detail-item .lbl{color:#6b7280}
.ts-detail-item .val{font-weight:600}

.ts-card-time{
    font-size:10px;color:#d1d5db;margin-top:8px;text-align:right;
}

/* ── Empty State ── */
.ts-empty{text-align:center;padding:40px 20px}
.ts-empty-icon{font-size:48px;margin-bottom:10px}
.ts-empty h3{font-size:16px;color:#374151;margin-bottom:4px}
.ts-empty p{font-size:12px;color:#9ca3af}

/* ── No results ── */
.ts-no-results{display:none;text-align:center;padding:24px;color:#9ca3af;font-size:13px}
.ts-no-results.visible{display:block}

/* ── Footer ── */
.ts-footer{
    text-align:center;margin-top:20px;padding-top:14px;
    border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;
}

@media (max-width: 380px){
    .ts-page{padding:10px 8px 20px}
    .ts-card-metrics{grid-template-columns:repeat(2,1fr)}
}
@media (min-width: 768px){
    .ts-summary{grid-template-columns:repeat(4,1fr)}
}
</style>
</head>
<body>

<div class="ts-page">

    <!-- ══ Hero ══ -->
    <div class="ts-hero">
        <div class="ts-hero-icon">📊</div>
        <div class="ts-hero-title">Tool Stats</div>
        <div class="ts-hero-sub">Thống kê hiệu năng và độ tin cậy của các tool AI</div>
    </div>

    <!-- ══ Summary ══ -->
    <div class="ts-summary">
        <div class="ts-sum-card">
            <div class="ts-sum-num"><?php echo $summary['total']; ?></div>
            <div class="ts-sum-lbl">Tools tracked</div>
        </div>
        <div class="ts-sum-card">
            <div class="ts-sum-num"><?php echo number_format( $summary['total_calls'] ); ?></div>
            <div class="ts-sum-lbl">Total calls</div>
        </div>
        <div class="ts-sum-card">
            <div class="ts-sum-num ok"><?php echo $summary['avg_success']; ?>%</div>
            <div class="ts-sum-lbl">Avg success</div>
        </div>
        <div class="ts-sum-card">
            <div class="ts-sum-num <?php echo $summary['tools_with_fails'] > 0 ? 'warn' : 'ok'; ?>"><?php echo $summary['tools_with_fails']; ?></div>
            <div class="ts-sum-lbl">Tools w/ fails</div>
        </div>
    </div>

    <?php if ( ! $table_exists || empty( $stats ) ) : ?>
    <div class="ts-empty">
        <div class="ts-empty-icon"><?php echo $table_exists ? '📭' : '⚠️'; ?></div>
        <h3><?php echo $table_exists ? 'Chưa có dữ liệu thống kê' : 'Bảng tool_stats chưa tồn tại'; ?></h3>
        <p><?php echo $table_exists
            ? 'Dữ liệu sẽ xuất hiện khi tools được sử dụng.'
            : 'Planner plugin cần được kích hoạt để tạo bảng này.'; ?></p>
    </div>
    <?php else : ?>

    <!-- ══ Search ══ -->
    <div class="ts-search">
        <input type="text" class="ts-search-input" id="ts-search"
               placeholder="Tìm tool... (vd: write_article, create_product)"
               autocomplete="off" spellcheck="false">
    </div>

    <div class="ts-no-results" id="ts-no-results">🔍 Không tìm thấy tool nào</div>

    <!-- ══ Tool Stats Cards ══ -->
    <div id="ts-cards">
    <?php foreach ( $stats as $row ) :
        $tool_key = $row['tool_key'] ?? '—';
        $env      = $row['env'] ?? 'prod';
        $window   = $row['window_days'] ?? '—';
    ?>
    <div class="ts-card"
         data-search="<?php echo esc_attr( mb_strtolower( $tool_key . ' ' . $env, 'UTF-8' ) ); ?>">
        <div class="ts-card-header">
            <div class="ts-card-tool"><?php echo esc_html( $tool_key ); ?></div>
            <span class="ts-card-env"><?php echo esc_html( $env ); ?></span>
            <span class="ts-card-window"><?php echo (int) $window; ?>d</span>
        </div>

        <div class="ts-card-metrics">
            <div class="ts-metric">
                <div class="ts-metric-val"><?php echo number_format( (int) $row['n_calls'] ); ?></div>
                <div class="ts-metric-lbl">Calls</div>
            </div>
            <div class="ts-metric">
                <div class="ts-metric-val"><?php echo bizc_ts_fmt_rate( $row['success_rate'] ?? null ); ?></div>
                <div class="ts-metric-lbl">Success</div>
            </div>
            <div class="ts-metric">
                <div class="ts-metric-val"><?php echo bizc_ts_fmt_ms( $row['p50_ms'] ?? null ); ?></div>
                <div class="ts-metric-lbl">P50</div>
            </div>
        </div>

        <div class="ts-card-detail">
            <div class="ts-detail-item">
                <span class="lbl">✅ Success</span>
                <span class="val"><?php echo number_format( (int) ( $row['n_success'] ?? 0 ) ); ?></span>
            </div>
            <div class="ts-detail-item">
                <span class="lbl">❌ Fail</span>
                <span class="val" style="color:<?php echo (int) $row['n_fail'] > 0 ? '#dc2626' : '#059669'; ?>"><?php echo number_format( (int) ( $row['n_fail'] ?? 0 ) ); ?></span>
            </div>
            <div class="ts-detail-item">
                <span class="lbl">🔄 Retry</span>
                <span class="val"><?php echo number_format( (int) ( $row['n_retry'] ?? 0 ) ); ?></span>
            </div>
            <div class="ts-detail-item">
                <span class="lbl">P95</span>
                <span class="val"><?php echo bizc_ts_fmt_ms( $row['p95_ms'] ?? null ); ?></span>
            </div>
            <?php if ( isset( $row['retry_rate'] ) && $row['retry_rate'] !== null ) : ?>
            <div class="ts-detail-item">
                <span class="lbl">Retry rate</span>
                <span class="val"><?php echo number_format( (float) $row['retry_rate'], 1 ); ?>%</span>
            </div>
            <?php endif; ?>
            <?php if ( isset( $row['avg_cost'] ) && $row['avg_cost'] !== null && (float) $row['avg_cost'] > 0 ) : ?>
            <div class="ts-detail-item">
                <span class="lbl">Avg cost</span>
                <span class="val">$<?php echo number_format( (float) $row['avg_cost'], 4 ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $row['updated_at'] ) ) : ?>
        <div class="ts-card-time">⏱ <?php echo esc_html( $row['updated_at'] ); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- Footer -->
    <div class="ts-footer">
        Tool Stats · <?php echo count( $stats ); ?> records
        · v<?php echo esc_html( defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '3.7' ); ?>
    </div>
</div>

<script>
(function(){
    'use strict';
    var searchInput = document.getElementById('ts-search');
    var noResults = document.getElementById('ts-no-results');
    var cards = document.querySelectorAll('.ts-card');

    if (searchInput) {
        var timer;
        searchInput.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(doSearch, 150);
        });
    }

    function doSearch() {
        var q = (searchInput.value || '').trim().toLowerCase();
        var any = false;
        cards.forEach(function(card) {
            var s = card.getAttribute('data-search') || '';
            if (!q || s.indexOf(q) !== -1) {
                card.style.display = '';
                any = true;
            } else {
                card.style.display = 'none';
            }
        });
        if (noResults) {
            noResults.className = any || !q ? 'ts-no-results' : 'ts-no-results visible';
        }
    }
})();
</script>

</body>
</html>
