<?php
/**
 * BizCity — Session Detail (Chi tiết phiên chat) — Messages View
 *
 * Standalone page: /chat-sessions/{id}/
 * Consumes REST API:
 *   GET /bizcity-intent/v1/sessions/{id}
 *   GET /bizcity-intent/v1/sessions/{id}/messages
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url( home_url( '/chat-sessions/' ) ) );
    exit;
}

$session_pk = absint( get_query_var( 'bizcity_session_pk', 0 ) );
$api_base   = esc_url( rest_url( 'bizcity-intent/v1' ) );
$nonce      = wp_create_nonce( 'wp_rest' );
$is_iframe  = isset( $_GET['bizcity_iframe'] );
$sessions_url = $is_iframe ? home_url( '/chat-sessions/?bizcity_iframe=1' ) : home_url( '/chat-sessions/' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>💬 Chi tiết phiên chat — BizCity</title>
<style>
:root {
    --bg: #0f172a; --surface: #1e293b; --border: #334155;
    --text: #e2e8f0; --muted: #94a3b8; --accent: #3b82f6;
    --green: #10b981; --red: #ef4444; --yellow: #f59e0b;
    --user-bg: #1e3a5f; --bot-bg: #1e293b;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
.container { max-width: 760px; margin: 0 auto; padding: 24px 16px; }
.back-link { color: var(--accent); text-decoration: none; font-size: 13px; }
.back-link:hover { text-decoration: underline; }

/* Session header */
.session-header {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 20px; margin: 16px 0;
}
.session-header h1 { font-size: 20px; margin-bottom: 6px; }
.session-header .meta-row { font-size: 12px; color: var(--muted); display: flex; gap: 16px; flex-wrap: wrap; margin-top: 4px; }
.session-header .status-badge {
    display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 10px;
    border-radius: 12px; margin-left: 8px;
}

/* Chat bubbles */
.chat-area { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.bubble {
    max-width: 85%; padding: 10px 14px; border-radius: 12px;
    font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
}
.bubble.user { background: var(--user-bg); align-self: flex-end; border-bottom-right-radius: 4px; }
.bubble.bot { background: var(--bot-bg); border: 1px solid var(--border); align-self: flex-start; border-bottom-left-radius: 4px; }
.bubble.system { background: transparent; border: 1px dashed var(--border); align-self: center; font-size: 12px; color: var(--muted); text-align: center; max-width: 95%; }
.bubble-time { font-size: 10px; color: var(--muted); margin-top: 2px; }
.bubble-role { font-size: 10px; color: var(--muted); margin-bottom: 2px; font-weight: 600; text-transform: uppercase; }
.bubble-plugin { font-size: 10px; color: var(--purple); margin-bottom: 2px; }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 16px; }
.pagination button {
    background: var(--surface); color: var(--text); border: 1px solid var(--border);
    padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;
}
.pagination button:hover:not(:disabled) { border-color: var(--accent); }
.pagination button:disabled { opacity: .4; cursor: default; }
.pagination .pg-info { font-size: 13px; color: var(--muted); }

.loading { text-align: center; padding: 24px; color: var(--muted); }
.error-msg { text-align: center; padding: 24px; color: var(--red); }

/* Linked tasks */
.linked-task {
    display: inline-block; background: rgba(139,92,246,.1); border: 1px solid rgba(139,92,246,.3);
    border-radius: 6px; padding: 2px 8px; font-size: 11px; color: #a78bfa;
    text-decoration: none; margin-top: 4px;
}
.linked-task:hover { border-color: #a78bfa; }
</style>
</head>
<body>
<div class="container">
    <a href="<?php echo esc_url( $sessions_url ); ?>" class="back-link">← Danh sách phiên chat</a>

    <div id="session-header" class="session-header" style="display:none"></div>
    <div class="chat-area" id="chat-area">
        <div class="loading">Đang tải...</div>
    </div>
    <div class="pagination" id="pagination"></div>
</div>

<script>
(function() {
    var API       = <?php echo wp_json_encode( $api_base ); ?>;
    var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
    var SESSION_PK = <?php echo wp_json_encode( $session_pk ); ?>;
    var TASKS_BASE = <?php echo wp_json_encode( home_url( '/tasks/' ) ); ?>;

    var msgPage = 1, msgPerPage = 50;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function fetchAPI(path) {
        return fetch(API + path, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }

    function fmtTime(dt) {
        if (!dt) return '';
        try { return new Date(dt.replace(' ', 'T') + 'Z').toLocaleString('vi-VN'); } catch(e) { return dt; }
    }

    function statusStyle(s) {
        s = (s || '').toLowerCase();
        if (s === 'active') return 'background:rgba(59,130,246,.15);color:#3b82f6';
        if (s === 'closed') return 'background:rgba(148,163,184,.15);color:#94a3b8';
        return 'background:rgba(148,163,184,.15);color:#94a3b8';
    }

    // Load session header
    fetchAPI('/sessions/' + SESSION_PK).then(function(data) {
        if (data.code) {
            document.getElementById('chat-area').innerHTML = '<div class="error-msg">' + esc(data.message || 'Không tìm thấy phiên chat') + '</div>';
            return;
        }
        var hdr = document.getElementById('session-header');
        hdr.style.display = 'block';

        hdr.innerHTML =
            '<h1>' + esc(data.title || 'Phiên #' + data.id) + '<span class="status-badge" style="' + statusStyle(data.status) + '">' + esc(data.status || 'active') + '</span></h1>' +
            '<div class="meta-row">' +
                '<span>🆔 ' + esc(data.session_id || '') + '</span>' +
                '<span>💬 ' + (data.message_count || 0) + ' tin nhắn</span>' +
                (data.platform_type ? '<span>📱 ' + esc(data.platform_type) + '</span>' : '') +
                '<span>🕐 Tạo: ' + fmtTime(data.created_at) + '</span>' +
                '<span>🔄 Cập nhật: ' + fmtTime(data.last_message_at || data.updated_at) + '</span>' +
            '</div>';

        loadMessages();
    });

    function loadMessages() {
        var area = document.getElementById('chat-area');
        area.innerHTML = '<div class="loading">Đang tải tin nhắn...</div>';

        fetchAPI('/sessions/' + SESSION_PK + '/messages?page=' + msgPage + '&per_page=' + msgPerPage).then(function(data) {
            if (!data.messages || !data.messages.length) {
                area.innerHTML = '<div class="loading" style="color:#94a3b8">Chưa có tin nhắn nào</div>';
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            area.innerHTML = data.messages.map(function(m) {
                var from = (m.message_from || '').toLowerCase();
                var cls = (from === 'user' || from === 'customer') ? 'user' : (from === 'system' ? 'system' : 'bot');
                var linkedTask = '';
                if (m.intent_conversation_id) {
                    linkedTask = '<a href="' + esc(TASKS_BASE + m.intent_conversation_id + '/') + '" class="linked-task">🎯 Nhiệm vụ #' + esc(String(m.intent_conversation_id)) + '</a>';
                }
                return '<div class="bubble ' + cls + '">' +
                    '<div class="bubble-role">' + esc(m.message_from || 'bot') + '</div>' +
                    (m.plugin_slug ? '<div class="bubble-plugin">⚙ ' + esc(m.plugin_slug) + '</div>' : '') +
                    esc(m.message_text || '(empty)') +
                    linkedTask +
                    (m.created_at ? '<div class="bubble-time">' + fmtTime(m.created_at) + '</div>' : '') +
                '</div>';
            }).join('');

            // Pagination
            var pg = document.getElementById('pagination');
            if (data.total_pages <= 1) { pg.innerHTML = ''; return; }
            pg.innerHTML =
                '<button id="mp-prev"' + (data.page <= 1 ? ' disabled' : '') + '>← Trước</button>' +
                '<span class="pg-info">Trang ' + data.page + ' / ' + data.total_pages + '</span>' +
                '<button id="mp-next"' + (data.page >= data.total_pages ? ' disabled' : '') + '>Sau →</button>';
            document.getElementById('mp-prev').addEventListener('click', function() { if (msgPage > 1) { msgPage--; loadMessages(); } });
            document.getElementById('mp-next').addEventListener('click', function() { if (msgPage < data.total_pages) { msgPage++; loadMessages(); } });
        });
    }
})();
</script>
</body>
</html>
