<?php
/**
 * BizCity — Chat Sessions List (Phiên chat) — Full View
 *
 * Standalone page: /chat-sessions/
 * Consumes REST API: /wp-json/bizcity-intent/v1/sessions
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url( home_url( '/chat-sessions/' ) ) );
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
<title>💬 Phiên chat — BizCity</title>
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

/* Session list */
.session-list { display: flex; flex-direction: column; gap: 8px; }
.session-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
    padding: 14px 16px; cursor: pointer; transition: border-color .15s;
    display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit;
}
.session-card:hover { border-color: var(--accent); }
.session-icon { font-size: 18px; flex-shrink: 0; }
.session-body { flex: 1; min-width: 0; }
.session-title { font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.session-preview { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.session-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
.session-meta span { margin-right: 12px; }
.session-status {
    font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;
    white-space: nowrap; flex-shrink: 0;
}
.status-active { background: rgba(59,130,246,.15); color: var(--accent); }
.status-closed { background: rgba(148,163,184,.15); color: var(--muted); }
.status-expired { background: rgba(148,163,184,.15); color: var(--muted); }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; }
.pagination button {
    background: var(--surface); color: var(--text); border: 1px solid var(--border);
    padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;
}
.pagination button:hover:not(:disabled) { border-color: var(--accent); }
.pagination button:disabled { opacity: .4; cursor: default; }
.pagination .pg-info { font-size: 13px; color: var(--muted); }

.empty-state { text-align: center; padding: 48px 0; color: var(--muted); }
.loading { text-align: center; padding: 24px; color: var(--muted); }

@media (max-width: 600px) {
    .container { padding: 16px 8px; }
    .session-card { flex-wrap: wrap; }
    .session-status { order: -1; }
}
</style>
</head>
<body>
<div class="container">
    <?php if ( ! $is_iframe ) : ?>
    <a href="<?php echo esc_url( admin_url() ); ?>" class="back-link">← Quay lại Admin</a>
    <?php endif; ?>
    <h1>💬 Phiên chat</h1>
    <p class="subtitle">Toàn bộ phiên trò chuyện — nhấn vào từng phiên để xem chi tiết tin nhắn</p>

    <div class="stats-bar" id="stats-bar"></div>

    <div class="filters">
        <input type="text" id="f-search" placeholder="Tìm kiếm phiên chat...">
        <select id="f-status">
            <option value="all">Tất cả trạng thái</option>
            <option value="active">🟢 Đang hoạt động</option>
            <option value="closed">🔒 Đã đóng</option>
            <option value="expired">⌛ Hết hạn</option>
        </select>
    </div>

    <div class="session-list" id="session-list">
        <div class="loading">Đang tải...</div>
    </div>

    <div class="pagination" id="pagination"></div>
</div>

<script>
(function() {
    var API   = <?php echo wp_json_encode( $api_base ); ?>;
    var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
    var BASE  = <?php echo wp_json_encode( home_url( '/chat-sessions/' ) ); ?>;
    var IFRAME_SUFFIX = <?php echo $is_iframe ? "'?bizcity_iframe=1'" : "''"; ?>;

    var state = { page: 1, per_page: 20, status: 'all', search: '' };
    var debounce;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function timeAgo(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T') + 'Z');
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60) return 'vừa xong';
        if (diff < 3600) return Math.floor(diff/60) + ' phút trước';
        if (diff < 86400) return Math.floor(diff/3600) + ' giờ trước';
        return Math.floor(diff/86400) + ' ngày trước';
    }

    function fetchAPI(path) {
        return fetch(API + path, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }

    function statusIcon(s) {
        s = (s || '').toLowerCase();
        if (s === 'active') return '🟢';
        if (s === 'closed') return '🔒';
        return '⌛';
    }

    function loadStats() {
        fetchAPI('/sessions/stats').then(function(data) {
            var bar = document.getElementById('stats-bar');
            var total = 0; Object.values(data).forEach(function(v) { total += v; });
            var html = '<div class="stat-chip' + (state.status === 'all' ? ' active' : '') + '" data-status="all">📊 Tất cả<span class="count">' + total + '</span></div>';
            var labels = { active: '🟢 Đang hoạt động', closed: '🔒 Đã đóng', expired: '⌛ Hết hạn' };
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
        var list = document.getElementById('session-list');
        list.innerHTML = '<div class="loading">Đang tải...</div>';

        var qs = '?page=' + state.page + '&per_page=' + state.per_page + '&status=' + encodeURIComponent(state.status);
        if (state.search) qs += '&search=' + encodeURIComponent(state.search);

        fetchAPI('/sessions' + qs).then(function(data) {
            if (!data.items || !data.items.length) {
                list.innerHTML = '<div class="empty-state">Chưa có phiên chat nào' + (state.search ? ' phù hợp' : '') + '</div>';
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            list.innerHTML = data.items.map(function(s) {
                var st = (s.status || 'active').toLowerCase();
                return '<a href="' + esc(BASE + s.id + '/' + IFRAME_SUFFIX) + '" class="session-card">' +
                    '<span class="session-icon">' + statusIcon(st) + '</span>' +
                    '<div class="session-body">' +
                        '<div class="session-title">' + esc(s.title || s.session_id || 'Phiên #' + s.id) + '</div>' +
                        (s.last_message ? '<div class="session-preview">' + esc(s.last_message) + '</div>' : '') +
                        '<div class="session-meta">' +
                            '<span>💬 ' + (s.message_count || 0) + ' tin nhắn</span>' +
                            (s.platform_type ? '<span>📱 ' + esc(s.platform_type) + '</span>' : '') +
                            '<span>' + timeAgo(s.last_message_at || s.updated_at) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<span class="session-status status-' + esc(st) + '">' + esc(st) + '</span>' +
                '</a>';
            }).join('');

            // Pagination
            var pg = document.getElementById('pagination');
            pg.innerHTML =
                '<button id="pg-prev"' + (data.page <= 1 ? ' disabled' : '') + '>← Trước</button>' +
                '<span class="pg-info">Trang ' + data.page + ' / ' + data.total_pages + ' (' + data.total + ' phiên)</span>' +
                '<button id="pg-next"' + (data.page >= data.total_pages ? ' disabled' : '') + '>Sau →</button>';
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
