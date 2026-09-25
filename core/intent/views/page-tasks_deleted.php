<?php
/**
 * BizCity — Tasks List (Nhiệm vụ) — Full View
 *
 * Standalone page: /tasks/
 * Consumes REST API: /wp-json/bizcity-intent/v1/tasks
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url( home_url( '/tasks/' ) ) );
    exit;
}

$api_base = esc_url( rest_url( 'bizcity-intent/v1' ) );
$nonce    = wp_create_nonce( 'wp_rest' );
$is_iframe = isset( $_GET['bizcity_iframe'] );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__( '🎯 Nhiệm vụ — BizCity', 'bizcity-twin-ai' ); ?></title>
<style>
:root {
    --bg: #0f172a; --surface: #1e293b; --border: #334155;
    --text: #e2e8f0; --muted: #94a3b8; --accent: #3b82f6;
    --green: #10b981; --red: #ef4444; --yellow: #f59e0b; --purple: #8b5cf6;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
.container { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
h1 { font-size: 24px; margin-bottom: 8px; }
.subtitle { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
.back-link { color: var(--accent); text-decoration: none; font-size: 13px; }
.back-link:hover { text-decoration: underline; }

/* Stats bar */
.stats-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.stat-chip { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 8px 16px; font-size: 13px; cursor: pointer; transition: border-color .2s; }
.stat-chip:hover, .stat-chip.active { border-color: var(--accent); }
.stat-chip .count { font-weight: 700; margin-left: 4px; }

/* Filters */
.filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filters input, .filters select {
    background: var(--surface); color: var(--text); border: 1px solid var(--border);
    padding: 8px 12px; border-radius: 6px; font-size: 13px; outline: none;
}
.filters input:focus, .filters select:focus { border-color: var(--accent); }
.filters input { flex: 1; min-width: 200px; }

/* Task list */
.task-list { display: flex; flex-direction: column; gap: 8px; }
.task-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
    padding: 14px 16px; cursor: pointer; transition: border-color .15s;
    display: flex; align-items: center; gap: 12px;
}
.task-card:hover { border-color: var(--accent); }
.task-icon { font-size: 18px; flex-shrink: 0; }
.task-body { flex: 1; min-width: 0; }
.task-title { font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.task-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
.task-meta span { margin-right: 12px; }
.task-status {
    font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;
    white-space: nowrap; flex-shrink: 0;
}
.status-ACTIVE, .status-IN_PROGRESS { background: rgba(59,130,246,.15); color: var(--accent); }
.status-COMPLETED { background: rgba(16,185,129,.15); color: var(--green); }
.status-CANCELLED, .status-FAILED { background: rgba(239,68,68,.15); color: var(--red); }
.status-WAITING_USER { background: rgba(245,158,11,.15); color: var(--yellow); }
.status-EXPIRED, .status-CLOSED { background: rgba(148,163,184,.15); color: var(--muted); }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; }
.pagination button {
    background: var(--surface); color: var(--text); border: 1px solid var(--border);
    padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;
}
.pagination button:hover:not(:disabled) { border-color: var(--accent); }
.pagination button:disabled { opacity: .4; cursor: default; }
.pagination .pg-info { font-size: 13px; color: var(--muted); }

/* Empty / loading */
.empty-state { text-align: center; padding: 48px 0; color: var(--muted); }
.loading { text-align: center; padding: 24px; color: var(--muted); }

@media (max-width: 600px) {
    .container { padding: 16px 8px; }
    .task-card { flex-wrap: wrap; }
    .task-status { order: -1; }
}
</style>
</head>
<body>
<div class="container">
    <?php if ( ! $is_iframe ) : ?>
    <a href="<?php echo esc_url( admin_url() ); ?>" class="back-link">← <?php echo esc_html__( 'Quay lại Admin', 'bizcity-twin-ai' ); ?></a>
    <?php endif; ?>
    <h1><?php echo esc_html__( '🎯 Nhiệm vụ', 'bizcity-twin-ai' ); ?></h1>
    <p class="subtitle"><?php echo esc_html__( 'Toàn bộ nhiệm vụ đã thực hiện — nhấn vào từng nhiệm vụ để xem chi tiết hội thoại', 'bizcity-twin-ai' ); ?></p>

    <div class="stats-bar" id="stats-bar"></div>

    <div class="filters">
        <input type="text" id="f-search" placeholder="<?php echo esc_attr__( 'Tìm kiếm nhiệm vụ...', 'bizcity-twin-ai' ); ?>">
        <select id="f-status">
            <option value="all"><?php echo esc_html__( 'Tất cả trạng thái', 'bizcity-twin-ai' ); ?></option>
            <option value="ACTIVE">🔄 <?php echo esc_html__( 'Đang thực hiện', 'bizcity-twin-ai' ); ?></option>
            <option value="WAITING_USER">⏳ <?php echo esc_html__( 'Chờ người dùng', 'bizcity-twin-ai' ); ?></option>
            <option value="COMPLETED">✅ <?php echo esc_html__( 'Hoàn thành', 'bizcity-twin-ai' ); ?></option>
            <option value="CANCELLED">❌ <?php echo esc_html__( 'Đã hủy', 'bizcity-twin-ai' ); ?></option>
            <option value="EXPIRED">⌛ <?php echo esc_html__( 'Hết hạn', 'bizcity-twin-ai' ); ?></option>
        </select>
    </div>

    <div class="task-list" id="task-list">
        <div class="loading"><?php echo esc_html__( 'Đang tải...', 'bizcity-twin-ai' ); ?></div>
    </div>

    <div class="pagination" id="pagination"></div>
</div>

<script>
(function() {
    var API   = <?php echo wp_json_encode( $api_base ); ?>;
    var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
    var BASE  = <?php echo wp_json_encode( home_url( '/tasks/' ) ); ?>;
    var IFRAME_SUFFIX = <?php echo $is_iframe ? "'?bizcity_iframe=1'" : "''"; ?>;
    var i18n  = <?php echo wp_json_encode([
        'just_now'      => __( 'vừa xong', 'bizcity-twin-ai' ),
        'minutes_ago'   => __( ' phút trước', 'bizcity-twin-ai' ),
        'hours_ago'     => __( ' giờ trước', 'bizcity-twin-ai' ),
        'days_ago'      => __( ' ngày trước', 'bizcity-twin-ai' ),
        'all'           => __( 'Tất cả', 'bizcity-twin-ai' ),
        'running'       => __( '🔄 Đang chạy', 'bizcity-twin-ai' ),
        'completed'     => __( '✅ Hoàn thành', 'bizcity-twin-ai' ),
        'cancelled'     => __( '❌ Đã hủy', 'bizcity-twin-ai' ),
        'waiting'       => __( '⏳ Chờ', 'bizcity-twin-ai' ),
        'expired'       => __( '⌛ Hết hạn', 'bizcity-twin-ai' ),
        'closed'        => __( '🔒 Đóng', 'bizcity-twin-ai' ),
        'loading'       => __( 'Đang tải...', 'bizcity-twin-ai' ),
        'no_tasks'      => __( 'Chưa có nhiệm vụ nào', 'bizcity-twin-ai' ),
        'matching'      => __( ' phù hợp', 'bizcity-twin-ai' ),
        'prev'          => __( '← Trước', 'bizcity-twin-ai' ),
        'page'          => __( 'Trang', 'bizcity-twin-ai' ),
        'next'          => __( 'Sau →', 'bizcity-twin-ai' ),
        'turns'         => __( ' lượt', 'bizcity-twin-ai' ),
        'tasks'         => __( ' nhiệm vụ', 'bizcity-twin-ai' ),
    ]); ?>;

    var state = { page: 1, per_page: 20, status: 'all', search: '' };
    var debounce;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function statusIcon(s, goal) {
        s = (s || '').toUpperCase(); goal = (goal || '').toLowerCase();
        if (goal.indexOf('knowledge') === 0 || goal.indexOf('mode:knowledge') === 0) return '📚';
        if (goal.indexOf('mode:emotion') === 0) return '💛';
        if (goal.indexOf('mode:reflection') === 0) return '🪞';
        if (s === 'COMPLETED') return '✅';
        if (s === 'CANCELLED' || s === 'FAILED') return '❌';
        if (s === 'ACTIVE' || s === 'IN_PROGRESS') return '🔄';
        if (s === 'WAITING_USER') return '⏳';
        return '⏳';
    }

    function timeAgo(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T') + 'Z');
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60) return i18n.just_now;
        if (diff < 3600) return Math.floor(diff/60) + i18n.minutes_ago;
        if (diff < 86400) return Math.floor(diff/3600) + i18n.hours_ago;
        return Math.floor(diff/86400) + i18n.days_ago;
    }

    function fetchAPI(path) {
        return fetch(API + path, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }

    function loadStats() {
        fetchAPI('/tasks/stats').then(function(data) {
            var bar = document.getElementById('stats-bar');
            var total = 0; Object.values(data).forEach(function(v) { total += v; });
            var html = '<div class="stat-chip' + (state.status === 'all' ? ' active' : '') + '" data-status="all">📊 ' + i18n.all + '<span class="count">' + total + '</span></div>';
            var labels = { ACTIVE: i18n.running, COMPLETED: i18n.completed, CANCELLED: i18n.cancelled, WAITING_USER: i18n.waiting, EXPIRED: i18n.expired, CLOSED: i18n.closed };
            Object.keys(data).forEach(function(k) {
                html += '<div class="stat-chip' + (state.status === k ? ' active' : '') + '" data-status="' + k + '">' + (labels[k] || k) + '<span class="count">' + data[k] + '</span></div>';
            });
            bar.innerHTML = html;
            bar.querySelectorAll('.stat-chip').forEach(function(el) {
                el.addEventListener('click', function() {
                    state.status = this.dataset.status;
                    state.page = 1;
                    document.getElementById('f-status').value = state.status;
                    load();
                });
            });
        });
    }

    function load() {
        var list = document.getElementById('task-list');
        list.innerHTML = '<div class="loading">' + i18n.loading + '</div>';

        var qs = '?page=' + state.page + '&per_page=' + state.per_page + '&status=' + encodeURIComponent(state.status);
        if (state.search) qs += '&search=' + encodeURIComponent(state.search);

        fetchAPI('/tasks' + qs).then(function(data) {
            if (!data.items || !data.items.length) {
                list.innerHTML = '<div class="empty-state">' + i18n.no_tasks + (state.search ? i18n.matching : '') + '</div>';
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            list.innerHTML = data.items.map(function(t) {
                return '<a href="' + esc(BASE + t.id + '/' + IFRAME_SUFFIX) + '" class="task-card" style="text-decoration:none;color:inherit">' +
                    '<span class="task-icon">' + statusIcon(t.status, t.goal) + '</span>' +
                    '<div class="task-body">' +
                        '<div class="task-title">' + esc(t.title || t.goal) + '</div>' +
                        '<div class="task-meta">' +
                            '<span>🗂 ' + esc(t.goal) + '</span>' +
                            '<span>💬 ' + t.turn_count + i18n.turns + '</span>' +
                            '<span>' + timeAgo(t.last_activity_at) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<span class="task-status status-' + esc(t.status) + '">' + esc(t.status) + '</span>' +
                '</a>';
            }).join('');

            // Pagination
            var pg = document.getElementById('pagination');
            pg.innerHTML =
                '<button id="pg-prev"' + (data.page <= 1 ? ' disabled' : '') + '>' + i18n.prev + '</button>' +
                '<span class="pg-info">' + i18n.page + ' ' + data.page + ' / ' + data.total_pages + ' (' + data.total + i18n.tasks + ')</span>' +
                '<button id="pg-next"' + (data.page >= data.total_pages ? ' disabled' : '') + '>' + i18n.next + '</button>';
            document.getElementById('pg-prev').addEventListener('click', function() { if (state.page > 1) { state.page--; load(); } });
            document.getElementById('pg-next').addEventListener('click', function() { if (state.page < data.total_pages) { state.page++; load(); } });

            loadStats();
        });
    }

    // Events
    document.getElementById('f-search').addEventListener('input', function() {
        clearTimeout(debounce);
        var v = this.value;
        debounce = setTimeout(function() { state.search = v; state.page = 1; load(); }, 300);
    });
    document.getElementById('f-status').addEventListener('change', function() {
        state.status = this.value; state.page = 1; load();
    });

    // Init
    loadStats();
    load();
})();
</script>
</body>
</html>
