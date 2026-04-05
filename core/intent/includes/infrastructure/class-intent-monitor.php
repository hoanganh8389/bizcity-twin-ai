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
 * BizCity Intent — Monitor Dashboard & Debug Viewer
 *
 * Admin page providing:
 *   1. Overview stats (conversations, intents, tools, errors)
 *   2. Conversation browser (list + detail view with turns)
 *   3. Pipeline debug viewer (trace-level inspection)
 *   4. Log query tool (filter by step, level, user, channel, date)
 *
 * @package BizCity_Intent
 * @since   1.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Monitor {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'wp_ajax_bizcity_intent_monitor_stats',  [ $this, 'ajax_stats' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_convs',  [ $this, 'ajax_conversations' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_detail', [ $this, 'ajax_conversation_detail' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_trace',  [ $this, 'ajax_trace' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_logs',   [ $this, 'ajax_logs' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_export', [ $this, 'ajax_export_json' ] );
        // v3.3.0 — new tabs
        add_action( 'wp_ajax_bizcity_intent_monitor_tools',       [ $this, 'ajax_tools' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_prompt_logs', [ $this, 'ajax_prompt_logs' ] );
        add_action( 'wp_ajax_bizcity_intent_monitor_exec_debug',  [ $this, 'ajax_exec_debug' ] );
        // v3.6.1 — HIL Checklist tab
        add_action( 'wp_ajax_bizcity_intent_monitor_hil', [ $this, 'ajax_hil_checklist' ] );
    }

    /**
     * Register admin menu page.
     */
    public function register_menu() {
        add_menu_page(
            'Intent Monitor',
            'Intent Monitor',
            'manage_options',
            'bizcity-intent-monitor',
            [ $this, 'render_page' ],
            'dashicons-analytics',
            72
        );
    }

    /**
     * Render the main monitor page — a SPA shell with tabs.
     */
    public function render_page() {
        $nonce = wp_create_nonce( 'bizcity_intent_monitor' );
        ?>
        <style>
            .bim-wrap { max-width:1400px; margin:20px auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
            .bim-tabs { display:flex; gap:0; border-bottom:2px solid #0073aa; margin-bottom:20px; }
            .bim-tab { padding:10px 20px; cursor:pointer; background:#f0f0f1; border:1px solid #ccc; border-bottom:none; border-radius:4px 4px 0 0; margin-right:2px; font-size:14px; }
            .bim-tab.active { background:#fff; border-color:#0073aa; border-bottom:2px solid #fff; margin-bottom:-2px; font-weight:600; color:#0073aa; }
            .bim-panel { display:none; }
            .bim-panel.active { display:block; }

            /* Stats */
            .bim-stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
            .bim-stat-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:20px; text-align:center; }
            .bim-stat-card .num { font-size:32px; font-weight:700; color:#0073aa; }
            .bim-stat-card .label { font-size:13px; color:#666; margin-top:4px; }

            /* Tables */
            .bim-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e0e0e0; border-radius:8px; overflow:hidden; }
            .bim-table th { background:#f8f9fa; text-align:left; padding:10px 14px; font-size:13px; border-bottom:2px solid #e0e0e0; }
            .bim-table td { padding:8px 14px; font-size:13px; border-bottom:1px solid #f0f0f1; }
            .bim-table tr:hover td { background:#f8f9fb; }
            .bim-table .clickable { cursor:pointer; color:#0073aa; text-decoration:underline; }

            /* Badges */
            .bim-badge { padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
            .bim-badge-active { background:#e8f5e9; color:#2e7d32; }
            .bim-badge-completed { background:#e3f2fd; color:#1565c0; }
            .bim-badge-expired { background:#fff3e0; color:#ef6c00; }
            .bim-badge-waiting { background:#fff8e1; color:#f57f17; }
            .bim-badge-closed { background:#efebe9; color:#6d4c41; }
            .bim-badge-error { background:#ffebee; color:#c62828; }
            .bim-badge-warn { background:#fff3e0; color:#e65100; }
            .bim-badge-info { background:#e8eaf6; color:#283593; }

            /* Debug trace */
            .bim-trace { background:#1e1e1e; color:#d4d4d4; border-radius:8px; padding:16px; font-family:"Fira Code",Consolas,monospace; font-size:12px; line-height:1.6; overflow-x:auto; }
            .bim-trace .step-line { padding:4px 0; border-bottom:1px solid #333; }
            .bim-trace .step-name { color:#569cd6; font-weight:600; }
            .bim-trace .step-ms { color:#b5cea8; margin-left:8px; }
            .bim-trace .step-data { color:#ce9178; margin-left:20px; display:block; white-space:pre-wrap; }

            /* Filters */
            .bim-filters { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
            .bim-filters select, .bim-filters input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; }
            .bim-filters button { padding:6px 16px; background:#0073aa; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; }
            .bim-filters button:hover { background:#005a87; }

            /* Detail panel */
            .bim-detail-panel { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:20px; margin-top:16px; }
            .bim-detail-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
            .bim-detail-header h3 { margin:0; }
            .bim-back-btn { padding:4px 12px; background:#eee; border:1px solid #ccc; border-radius:4px; cursor:pointer; font-size:12px; }
            .bim-turn { padding:10px 14px; margin:6px 0; border-radius:8px; }
            .bim-turn-user { background:#e3f2fd; border-left:3px solid #1976d2; }
            .bim-turn-assistant { background:#f3e5f5; border-left:3px solid #7b1fa2; }
            .bim-turn-tool { background:#fff3e0; border-left:3px solid #f57c00; }
            .bim-turn .role { font-weight:600; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
            .bim-turn .content { white-space:pre-wrap; font-size:13px; }

            /* Charts placeholder */
            .bim-chart-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
            .bim-chart-box { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:16px; }
            .bim-chart-box h4 { margin:0 0 12px; font-size:14px; color:#333; }
            .bim-bar { display:flex; align-items:center; margin:4px 0; }
            .bim-bar-label { width:140px; font-size:12px; color:#555; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .bim-bar-fill { height:20px; background:#0073aa; border-radius:3px; min-width:2px; transition:width 0.3s; }
            .bim-bar-val { margin-left:8px; font-size:12px; color:#666; }

            .bim-loading { text-align:center; padding:40px; color:#999; }
            .bim-empty { text-align:center; padding:40px; color:#999; font-style:italic; }
            .bim-section-title { font-size:16px; font-weight:600; margin:24px 0 12px; color:#333; }

            /* HIL Checklist Tab */
            .bim-hil-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:16px; margin-bottom:16px; }
            .bim-hil-card.hil-waiting { border-left:4px solid #f0ad4e; }
            .bim-hil-card.hil-active { border-left:4px solid #0073aa; }
            .bim-hil-card.hil-expired { border-left:4px solid #ccc; opacity:0.7; }
            .bim-hil-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
            .bim-hil-header h4 { margin:0; font-size:14px; }
            .bim-hil-meta { font-size:12px; color:#888; }
            .bim-hil-progress { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
            .bim-hil-progress-bar { flex:1; height:8px; background:#eee; border-radius:4px; overflow:hidden; }
            .bim-hil-progress-fill { height:100%; background:#46b450; border-radius:4px; transition:width 0.3s; }
            .bim-hil-progress-text { font-size:12px; color:#666; white-space:nowrap; }
            .bim-hil-slots { list-style:none; padding:0; margin:0; }
            .bim-hil-slot { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid #f5f5f5; font-size:13px; }
            .bim-hil-slot:last-child { border-bottom:none; }
            .bim-hil-slot-icon { font-size:16px; width:20px; text-align:center; flex-shrink:0; }
            .bim-hil-slot-name { font-weight:500; min-width:100px; }
            .bim-hil-slot-type { font-size:11px; color:#999; background:#f5f5f5; padding:1px 6px; border-radius:3px; }
            .bim-hil-slot-value { color:#333; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px; }
            .bim-hil-slot-value.empty { color:#ccc; font-style:italic; }
            .bim-hil-slot-required { font-size:10px; color:#d94f4f; font-weight:600; }
            .bim-hil-slot-optional { font-size:10px; color:#999; }
            .bim-hil-turns-toggle { font-size:12px; color:#0073aa; cursor:pointer; margin-top:8px; display:inline-block; }
            .bim-hil-turns { display:none; margin-top:8px; padding:8px; background:#fafafa; border-radius:4px; }
            .bim-hil-turns.open { display:block; }
            .bim-hil-turn-mini { font-size:12px; margin:4px 0; padding:4px 8px; border-left:2px solid #ddd; }
            .bim-hil-turn-mini.role-user { border-color:#1976d2; }
            .bim-hil-turn-mini.role-assistant { border-color:#7b1fa2; }
            .bim-hil-turn-mini.role-tool { border-color:#f57c00; }
            .bim-hil-turn-mini .role { font-weight:600; text-transform:uppercase; font-size:10px; margin-right:6px; }
        </style>

        <div class="bim-wrap">
            <h1>🔍 Intent Monitor</h1>

            <div class="bim-tabs">
                <div class="bim-tab active" data-tab="overview">📊 Tổng quan</div>
                <div class="bim-tab" data-tab="hil">📋 HIL Checklist</div>
                <div class="bim-tab" data-tab="conversations">💬 Hội thoại</div>
                <div class="bim-tab" data-tab="tools">🔧 Tools</div>
                <div class="bim-tab" data-tab="prompt-logs">📝 Prompt Logs</div>
                <div class="bim-tab" data-tab="executor">⚡ Executor</div>
                <div class="bim-tab" data-tab="debug">🐛 Debug</div>
                <div class="bim-tab" data-tab="logs">📋 Logs</div>
            </div>

            <!-- Tab: HIL Checklist -->
            <div class="bim-panel" id="panel-hil">
                <div class="bim-filters">
                    <select id="hil-status">
                        <option value="">Tất cả</option>
                        <option value="WAITING_USER" selected>Đang chờ user</option>
                        <option value="ACTIVE">Active</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="EXPIRED">Expired</option>
                    </select>
                    <select id="hil-channel">
                        <option value="">Tất cả kênh</option>
                        <option value="adminchat">Admin Chat</option>
                        <option value="webchat">Web Chat</option>
                        <option value="zalo">Zalo</option>
                        <option value="telegram">Telegram</option>
                    </select>
                    <input type="text" id="hil-search" placeholder="Tìm goal hoặc user...">
                    <button onclick="loadHilChecklist()">Tải lại</button>
                    <label style="margin-left:auto;font-size:12px;">
                        <input type="checkbox" id="hil-auto-refresh" checked> Auto-refresh 15s
                    </label>
                </div>
                <div id="hil-content"><div class="bim-loading">Đang tải...</div></div>
            </div>

            <!-- Tab: Overview -->
            <div class="bim-panel active" id="panel-overview">
                <div class="bim-filters">
                    <label>Khoảng thời gian:</label>
                    <select id="stats-days">
                        <option value="1">24h</option>
                        <option value="7" selected>7 ngày</option>
                        <option value="30">30 ngày</option>
                    </select>
                    <button onclick="loadStats()">Tải lại</button>
                </div>
                <div id="stats-content"><div class="bim-loading">Đang tải...</div></div>
            </div>

            <!-- Tab: Conversations -->
            <div class="bim-panel" id="panel-conversations">
                <div class="bim-filters">
                    <select id="conv-channel">
                        <option value="">Tất cả kênh</option>
                        <option value="adminchat">Admin Chat</option>
                        <option value="webchat">Web Chat</option>
                        <option value="zalo">Zalo</option>
                        <option value="telegram">Telegram</option>
                        <option value="facebook">Facebook</option>
                    </select>
                    <select id="conv-status">
                        <option value="">Tất cả trạng thái</option>
                        <option value="ACTIVE">Active</option>
                        <option value="WAITING_USER">Waiting</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="EXPIRED">Expired</option>
                        <option value="CLOSED">Closed</option>
                    </select>
                    <input type="text" id="conv-search" placeholder="Tìm conversation ID hoặc goal...">
                    <button onclick="loadConversations()">Tìm</button>
                </div>
                <div id="conv-list"><div class="bim-loading">Đang tải...</div></div>
                <div id="conv-detail" style="display:none;"></div>
            </div>

            <!-- Tab: Debug -->
            <div class="bim-panel" id="panel-debug">
                <div class="bim-filters">
                    <input type="text" id="debug-conv-id" placeholder="Conversation ID hoặc Trace ID" style="width:300px;">
                    <button onclick="loadDebugTraces()">Xem traces</button>
                </div>
                <div id="debug-content"><div class="bim-empty">Nhập Conversation ID để xem pipeline traces.</div></div>
            </div>

            <!-- Tab: Logs -->
            <div class="bim-panel" id="panel-logs">
                <div class="bim-filters">
                    <select id="log-level">
                        <option value="">Tất cả level</option>
                        <option value="info">Info</option>
                        <option value="warn">Warn</option>
                        <option value="error">Error</option>
                    </select>
                    <select id="log-step">
                        <option value="">Tất cả step</option>
                        <option value="trace_begin">trace_begin</option>
                        <option value="input">input</option>
                        <option value="classify">classify</option>
                        <option value="plan">plan</option>
                        <option value="execute_tool">execute_tool</option>
                        <option value="trace_end">trace_end</option>
                    </select>
                    <input type="text" id="log-conv-id" placeholder="Conversation ID">
                    <button onclick="loadLogs()">Tìm</button>
                    <span style="margin-left:auto;display:flex;gap:6px;">
                        <select id="export-format">
                            <option value="flat">Flat (danh sách)</option>
                            <option value="grouped">Grouped (theo trace)</option>
                        </select>
                        <button onclick="exportLogsJson()" style="background:#2e7d32;">📥 Export JSON</button>
                    </span>
                </div>
                <div id="logs-content"><div class="bim-loading">Đang tải...</div></div>
            </div>

            <!-- Tab: Tool Registry -->
            <div class="bim-panel" id="panel-tools">
                <div class="bim-filters">
                    <input type="text" id="tools-search" placeholder="Tìm tool name, scope, intent_tag...">
                    <select id="tools-source">
                        <option value="">Tất cả nguồn</option>
                        <option value="built_in">Built-in</option>
                        <option value="plugin">Plugin</option>
                        <option value="provider">Provider</option>
                    </select>
                    <button onclick="loadTools()">Tìm</button>
                </div>
                <div id="tools-content"><div class="bim-loading">Đang tải...</div></div>
            </div>

            <!-- Tab: Prompt Logs -->
            <div class="bim-panel" id="panel-prompt-logs">
                <div class="bim-filters">
                    <select id="pl-mode">
                        <option value="">Tất cả mode</option>
                        <option value="emotion">Emotion</option>
                        <option value="reflection">Reflection</option>
                        <option value="knowledge">Knowledge</option>

                        <option value="execution">Execution</option>
                    </select>
                    <select id="pl-channel">
                        <option value="">Tất cả kênh</option>
                        <option value="adminchat">Admin Chat</option>
                        <option value="webchat">Web Chat</option>
                        <option value="zalo">Zalo</option>
                        <option value="telegram">Telegram</option>
                    </select>
                    <input type="text" id="pl-search" placeholder="Tìm trong prompt...">
                    <button onclick="loadPromptLogs()">Tìm</button>
                </div>
                <div id="pl-content"><div class="bim-loading">Đang tải...</div></div>
            </div>

            <!-- Tab: Executor Debug -->
            <div class="bim-panel" id="panel-executor">
                <div class="bim-filters">
                    <select id="exec-status">
                        <option value="">Tất cả trạng thái</option>
                        <option value="queued">Queued</option>
                        <option value="running">Running</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                        <option value="waiting_human">Waiting Human</option>
                    </select>
                    <input type="text" id="exec-search" placeholder="Trace ID hoặc title...">
                    <button onclick="loadExecDebug()">Tìm</button>
                </div>
                <div id="exec-content"><div class="bim-loading">Đang tải...</div></div>
            </div>
        </div>

        <script>
        (function(){
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;

            /* ── Tab switching ── */
            document.querySelectorAll('.bim-tab').forEach(function(tab){
                tab.addEventListener('click', function(){
                    document.querySelectorAll('.bim-tab').forEach(function(t){t.classList.remove('active');});
                    document.querySelectorAll('.bim-panel').forEach(function(p){p.classList.remove('active');});
                    tab.classList.add('active');
                    document.getElementById('panel-'+tab.dataset.tab).classList.add('active');
                    // Auto-load data
                    if(tab.dataset.tab==='overview') loadStats();
                    if(tab.dataset.tab==='hil') loadHilChecklist();
                    if(tab.dataset.tab==='conversations') loadConversations();
                    if(tab.dataset.tab==='tools') loadTools();
                    if(tab.dataset.tab==='prompt-logs') loadPromptLogs();
                    if(tab.dataset.tab==='executor') loadExecDebug();
                });
            });

            /* ── API helper ── */
            function api(action, params, cb){
                params._wpnonce = nonce;
                params.action = action;
                var qs = Object.keys(params).map(function(k){return k+'='+encodeURIComponent(params[k]);}).join('&');
                fetch(ajaxUrl+'?'+qs).then(function(r){return r.json();}).then(function(d){
                    cb(d.success ? d.data : null, d.success ? null : (d.data||{}).message);
                }).catch(function(e){cb(null,e.message);});
            }

            /* ────────────────────────────────────────────
             *  OVERVIEW TAB
             * ──────────────────────────────────────────── */
            window.loadStats = function(){
                var days = document.getElementById('stats-days').value;
                var el = document.getElementById('stats-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_stats',{days:days},function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderStats(data);
                });
            };

            function renderStats(s){
                var el = document.getElementById('stats-content');
                var h = '<div class="bim-stats-grid">';
                h+= statCard(s.conversations.total,'Hội thoại');
                h+= statCard(s.conversations.active,'Đang hoạt động');
                h+= statCard(s.conversations.completed,'Hoàn thành');
                h+= statCard(s.slot_analytics.waiting_count||0,'Đang chờ user','bim-badge-warn');
                h+= statCard(s.log_stats.total_traces,'Pipeline traces');
                h+= statCard(s.log_stats.avg_duration_ms+'ms','TB thời gian xử lý');
                h+= statCard(s.log_stats.errors,'Lỗi','bim-badge-error');
                h+= '</div>';

                h+= '<div class="bim-chart-row">';
                h+= '<div class="bim-chart-box"><h4>🎯 Top Intents (goals)</h4>'+renderBars(s.log_stats.top_goals,'goal','cnt')+'</div>';
                h+= '<div class="bim-chart-box"><h4>🔧 Top Tools</h4>'+renderBars(s.log_stats.top_tools,'tool_name','cnt')+'</div>';
                h+= '</div>';

                h+= '<div class="bim-chart-row">';
                h+= '<div class="bim-chart-box"><h4>📈 Traces theo ngày</h4>'+renderBars(s.log_stats.per_day,'day','traces')+'</div>';
                h+= '<div class="bim-chart-box"><h4>📊 Channels</h4>'+renderBars(s.conversations.by_channel,'channel','cnt')+'</div>';
                h+= '</div>';

                /* ── Slot Analytics Section (v3.6.2) ── */
                var sa = s.slot_analytics||{};
                if((sa.fill_rates&&sa.fill_rates.length)||(sa.abandoned&&sa.abandoned.length)){
                    h+='<h3 style="margin:20px 0 10px;border-top:1px solid #ddd;padding-top:12px;">📊 HIL Slot Analytics</h3>';
                    h+='<div class="bim-chart-row">';
                    if(sa.fill_rates&&sa.fill_rates.length){
                        h+='<div class="bim-chart-box"><h4>✅ Slot Fill Rate by Goal</h4>';
                        h+='<table class="bim-table" style="font-size:12px;"><thead><tr><th>Goal</th><th>Avg Fill %</th><th>Avg Turns</th><th>Executions</th></tr></thead><tbody>';
                        sa.fill_rates.forEach(function(r){
                            var cls=parseInt(r.avg_fill_rate)>=80?'bim-badge-active':(parseInt(r.avg_fill_rate)>=50?'bim-badge-warn':'bim-badge-error');
                            h+='<tr><td>'+escHtml(r.goal)+'</td>';
                            h+='<td><span class="bim-badge '+cls+'">'+r.avg_fill_rate+'%</span></td>';
                            h+='<td>'+r.avg_turns+'</td>';
                            h+='<td>'+r.executions+'</td></tr>';
                        });
                        h+='</tbody></table></div>';
                    }
                    if(sa.abandoned&&sa.abandoned.length){
                        h+='<div class="bim-chart-box"><h4>💀 Most Abandoned Goals</h4>';
                        h+=renderBars(sa.abandoned,'goal','cnt');
                        h+='</div>';
                    }
                    h+='</div>';
                }

                el.innerHTML = h;
            }

            function statCard(val,label,cls){
                return '<div class="bim-stat-card"><div class="num '+(cls||'')+'">'+val+'</div><div class="label">'+label+'</div></div>';
            }

            function renderBars(items,labelKey,valKey){
                if(!items||!items.length) return '<div class="bim-empty">Không có dữ liệu</div>';
                var max = Math.max.apply(null,items.map(function(i){return parseInt(i[valKey])||0;}));
                if(max<1)max=1;
                var h='';
                items.forEach(function(i){
                    var v = parseInt(i[valKey])||0;
                    var pct = Math.round(v/max*100);
                    h+='<div class="bim-bar"><span class="bim-bar-label" title="'+(i[labelKey]||'?')+'">'+(i[labelKey]||'—')+'</span>';
                    h+='<div class="bim-bar-fill" style="width:'+pct+'%"></div>';
                    h+='<span class="bim-bar-val">'+v+'</span></div>';
                });
                return h;
            }

            /* ────────────────────────────────────────────
             *  CONVERSATIONS TAB
             * ──────────────────────────────────────────── */
            window.loadConversations = function(){
                var el = document.getElementById('conv-list');
                var detail = document.getElementById('conv-detail');
                detail.style.display='none';
                el.style.display='block';
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_convs',{
                    channel: document.getElementById('conv-channel').value,
                    status: document.getElementById('conv-status').value,
                    search: document.getElementById('conv-search').value,
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderConvList(data);
                });
            };

            function renderConvList(rows){
                var el = document.getElementById('conv-list');
                if(!rows.length){el.innerHTML='<div class="bim-empty">Không có hội thoại nào.</div>';return;}
                var h='<table class="bim-table"><thead><tr><th>ID</th><th>User</th><th>Channel</th><th>Goal</th><th>Status</th><th>Turns</th><th>Updated</th></tr></thead><tbody>';
                rows.forEach(function(r){
                    var badge = statusBadge(r.status);
                    h+='<tr><td class="clickable" onclick="loadConvDetail(\''+r.conversation_id+'\')">'+shortId(r.conversation_id)+'</td>';
                    h+='<td>'+r.user_id+'</td><td>'+r.channel+'</td>';
                    h+='<td>'+(r.goal_label||r.goal||'—')+'</td>';
                    h+='<td>'+badge+'</td><td>'+r.turn_count+'</td>';
                    h+='<td>'+timeAgo(r.last_activity_at)+'</td></tr>';
                });
                h+='</tbody></table>';
                el.innerHTML=h;
            }

            window.loadConvDetail = function(convId){
                document.getElementById('conv-list').style.display='none';
                var el = document.getElementById('conv-detail');
                el.style.display='block';
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_detail',{conversation_id:convId},function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderConvDetail(data);
                });
            };

            function renderConvDetail(data){
                var el = document.getElementById('conv-detail');
                var c = data.conversation;
                var h='<div class="bim-detail-panel">';
                h+='<div class="bim-detail-header"><h3>💬 '+shortId(c.conversation_id)+' — '+(c.goal_label||c.goal||'Chưa xác định')+'</h3>';
                h+='<button class="bim-back-btn" onclick="loadConversations()">← Quay lại</button></div>';
                h+='<p><strong>Conv ID:</strong> '+c.conversation_id+' &nbsp; <strong>Status:</strong> '+statusBadge(c.status)+' &nbsp; <strong>Channel:</strong> '+c.channel+' &nbsp; <strong>User:</strong> #'+c.user_id+'</p>';
                if(c.slots_json){
                    try{var slots=JSON.parse(c.slots_json);h+='<p><strong>Slots:</strong> <code>'+JSON.stringify(slots)+'</code></p>';}catch(e){}
                }

                h+='<h4>Turns ('+data.turns.length+')</h4>';
                data.turns.forEach(function(t){
                    var cls = 'bim-turn bim-turn-'+t.role;
                    h+='<div class="'+cls+'">';
                    h+='<div class="role">'+t.role+' #'+t.turn_index+'</div>';
                    h+='<div class="content">'+escHtml(t.content||'')+'</div>';
                    if(t.tool_calls){
                        try{var tc=JSON.parse(t.tool_calls);h+='<div style="font-size:11px;color:#888;margin-top:4px;">Tools: '+JSON.stringify(tc)+'</div>';}catch(e){}
                    }
                    h+='</div>';
                });

                // Pipeline traces
                if(data.traces&&data.traces.length){
                    h+='<h4 style="margin-top:20px">🔬 Pipeline Traces</h4>';
                    data.traces.forEach(function(tr){
                        h+='<div style="margin:8px 0"><span class="clickable" onclick="loadTraceInline(\''+tr.trace_id+'\',this)">'+tr.trace_id+'</span>';
                        h+=' — '+tr.step_count+' steps, '+tr.total_ms+'ms, '+tr.started_at+'</div>';
                        h+='<div id="trace-inline-'+tr.trace_id+'"></div>';
                    });
                }

                h+='</div>';
                el.innerHTML=h;
            }

            /* ────────────────────────────────────────────
             *  DEBUG TAB
             * ──────────────────────────────────────────── */
            window.loadDebugTraces = function(){
                var q = document.getElementById('debug-conv-id').value.trim();
                var el = document.getElementById('debug-content');
                if(!q){el.innerHTML='<div class="bim-empty">Nhập Conversation ID hoặc Trace ID.</div>';return;}
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';

                // If starts with trace_ → load single trace
                if(q.indexOf('trace_')===0){
                    api('bizcity_intent_monitor_trace',{trace_id:q},function(data,err){
                        if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                        el.innerHTML=renderTraceView(data);
                    });
                } else {
                    // Load all traces for conversation
                    api('bizcity_intent_monitor_detail',{conversation_id:q},function(data,err){
                        if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                        if(!data.traces||!data.traces.length){
                            el.innerHTML='<div class="bim-empty">Không tìm thấy traces cho conversation này.</div>';
                            return;
                        }
                        var h='<p>Tìm thấy <strong>'+data.traces.length+'</strong> traces cho conv '+shortId(q)+'</p>';
                        data.traces.forEach(function(tr){
                            h+='<div style="margin:12px 0"><h4 class="clickable" onclick="loadTraceDetail(\''+tr.trace_id+'\')">'+tr.trace_id+'</h4>';
                            h+='<span>'+tr.step_count+' steps, '+tr.total_ms+'ms, '+tr.started_at+'</span></div>';
                            h+='<div id="trace-detail-'+tr.trace_id+'"></div>';
                        });
                        el.innerHTML=h;
                    });
                }
            };

            window.loadTraceDetail = function(traceId){
                var el = document.getElementById('trace-detail-'+traceId);
                if(!el) return;
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_trace',{trace_id:traceId},function(data,err){
                    if(err){el.innerHTML='';return;}
                    el.innerHTML=renderTraceView(data);
                });
            };

            window.loadTraceInline = function(traceId, anchor){
                var el = document.getElementById('trace-inline-'+traceId);
                if(!el) return;
                if(el.innerHTML){el.innerHTML='';return;} // toggle
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_trace',{trace_id:traceId},function(data,err){
                    if(err){el.innerHTML='';return;}
                    el.innerHTML=renderTraceView(data);
                });
            };

            function renderTraceView(steps){
                if(!steps||!steps.length) return '<div class="bim-empty">Không có dữ liệu trace.</div>';
                var h='<div class="bim-trace">';
                steps.forEach(function(s){
                    var data = {};
                    try{data=JSON.parse(s.data_json||'{}');}catch(e){}
                    var dataStr = JSON.stringify(data,null,2);
                    h+='<div class="step-line">';
                    h+='<span class="step-name">['+s.step+']</span>';
                    h+='<span class="step-ms">+'+s.duration_ms+'ms</span>';
                    h+=' <span class="bim-badge bim-badge-'+s.level+'">'+s.level+'</span>';
                    h+='<span class="step-data">'+escHtml(dataStr)+'</span>';
                    h+='</div>';
                });
                h+='</div>';
                return h;
            }

            /* ────────────────────────────────────────────
             *  LOGS TAB
             * ──────────────────────────────────────────── */
            window.loadLogs = function(){
                var el = document.getElementById('logs-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_logs',{
                    level: document.getElementById('log-level').value,
                    step: document.getElementById('log-step').value,
                    conversation_id: document.getElementById('log-conv-id').value,
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderLogs(data);
                });
            };

            function renderLogs(rows){
                var el = document.getElementById('logs-content');
                if(!rows.length){el.innerHTML='<div class="bim-empty">Không có logs.</div>';return;}
                var h='<table class="bim-table"><thead><tr><th>Time</th><th>Trace</th><th>Conv</th><th>Step</th><th>Level</th><th>Duration</th><th>Data</th></tr></thead><tbody>';
                rows.forEach(function(r){
                    var data='';
                    try{var d=JSON.parse(r.data_json||'{}');data=JSON.stringify(d);}catch(e){data=r.data_json||'';}
                    if(data.length>120)data=data.substring(0,117)+'...';
                    h+='<tr><td style="white-space:nowrap">'+r.created_at+'</td>';
                    h+='<td class="clickable" onclick="document.getElementById(\'debug-conv-id\').value=\''+r.trace_id+'\';document.querySelector(\'[data-tab=debug]\').click();loadDebugTraces();">'+shortId(r.trace_id)+'</td>';
                    h+='<td>'+shortId(r.conversation_id||'')+'</td>';
                    h+='<td>'+r.step+'</td>';
                    h+='<td><span class="bim-badge bim-badge-'+r.level+'">'+r.level+'</span></td>';
                    h+='<td>'+r.duration_ms+'ms</td>';
                    h+='<td style="font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;" title="'+escAttr(data)+'">'+escHtml(data)+'</td></tr>';
                });
                h+='</tbody></table>';
                el.innerHTML=h;
            }

            /* ── Helpers ── */
            function shortId(id){return id?(id.length>16?id.substring(0,15)+'…':id):'';}

            /* ────────────────────────────────────────────
             *  EXPORT JSON
             * ──────────────────────────────────────────── */
            window.exportLogsJson = function(){
                var params = {
                    action: 'bizcity_intent_monitor_export',
                    _wpnonce: nonce,
                    format: document.getElementById('export-format').value,
                    level: document.getElementById('log-level').value,
                    step: document.getElementById('log-step').value,
                    conversation_id: document.getElementById('log-conv-id').value,
                    limit: 2000
                };
                var qs = Object.keys(params).map(function(k){return k+'='+encodeURIComponent(params[k]);}).join('&');
                fetch(ajaxUrl+'?'+qs).then(function(r){return r.json();}).then(function(d){
                    if(!d.success){alert('Export lỗi: '+(d.data?d.data.message:''));return;}
                    var blob = new Blob([JSON.stringify(d.data,null,2)],{type:'application/json'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'intent-logs-'+new Date().toISOString().slice(0,10)+'.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(a.href);
                }).catch(function(e){alert('Export lỗi: '+e.message);});
            };
            function statusBadge(s){
                var cls = {'ACTIVE':'active','COMPLETED':'completed','EXPIRED':'expired','WAITING_USER':'waiting','CLOSED':'closed'}[s]||'info';
                return '<span class="bim-badge bim-badge-'+cls+'">'+s+'</span>';
            }
            function timeAgo(dt){
                if(!dt)return'—';
                var d=new Date(dt.replace(' ','T')+'Z');
                var diff=Math.floor((Date.now()-d.getTime())/1000);
                if(diff<60)return diff+'s ago';
                if(diff<3600)return Math.floor(diff/60)+'m ago';
                if(diff<86400)return Math.floor(diff/3600)+'h ago';
                return Math.floor(diff/86400)+'d ago';
            }
            function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
            function escAttr(s){return escHtml(s).replace(/"/g,'&quot;');}

            /* ────────────────────────────────────────────
             *  TOOL REGISTRY TAB
             * ──────────────────────────────────────────── */
            window.loadTools = function(){
                var el = document.getElementById('tools-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_tools',{
                    search: (document.getElementById('tools-search')||{}).value||'',
                    source: (document.getElementById('tools-source')||{}).value||'',
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderTools(data);
                });
            };

            function renderTools(data){
                var el = document.getElementById('tools-content');
                var tools = data.tools||[];
                if(!tools.length){el.innerHTML='<div class="bim-empty">Không tìm thấy tool nào.</div>';return;}

                var h='<div class="bim-stats-grid">';
                h+= statCard(data.total||tools.length,'Tổng tools');
                h+= statCard(data.by_source?data.by_source.built_in||0:0,'Built-in');
                h+= statCard(data.by_source?data.by_source.plugin||0:0,'Plugin');
                h+= statCard(data.by_source?data.by_source.provider||0:0,'Provider');
                h+='</div>';

                h+='<table class="bim-table"><thead><tr>';
                h+='<th>Tool Name</th><th>Source</th><th>Plugin</th><th>Version</th>';
                h+='<th>Active</th><th>Capability Tags</th><th>Intent Tags</th><th>Fields</th>';
                h+='</tr></thead><tbody>';

                tools.forEach(function(t){
                    var active = t.active!==false ? '<span class="bim-badge bim-badge-active">Active</span>' : '<span class="bim-badge bim-badge-closed">Inactive</span>';
                    var caps = (t.capability_tags||t.capabilities||[]).join(', ')||'—';
                    var intents = (t.intent_tags||[]).join(', ')||'—';
                    var fields = '';
                    if(t.input_fields){
                        var fnames = typeof t.input_fields==='object' ? Object.keys(t.input_fields) : [];
                        fields = fnames.length ? fnames.join(', ') : '—';
                    }
                    h+='<tr>';
                    h+='<td><strong>'+escHtml(t.tool_name||t.name||'')+'</strong>';
                    if(t.tool_key) h+='<br><span style="font-size:11px;color:#888;">key: '+escHtml(t.tool_key)+'</span>';
                    h+='</td>';
                    h+='<td>'+escHtml(t.source||'—')+'</td>';
                    h+='<td>'+escHtml(t.plugin_slug||'—')+'</td>';
                    h+='<td>'+escHtml(t.version||'—')+'</td>';
                    h+='<td>'+active+'</td>';
                    h+='<td style="font-size:11px;max-width:150px;">'+escHtml(caps)+'</td>';
                    h+='<td style="font-size:11px;max-width:150px;">'+escHtml(intents)+'</td>';
                    h+='<td style="font-size:11px;max-width:200px;">'+escHtml(fields)+'</td>';
                    h+='</tr>';
                });
                h+='</tbody></table>';
                el.innerHTML=h;
            }

            /* ────────────────────────────────────────────
             *  PROMPT LOGS TAB
             * ──────────────────────────────────────────── */
            window.loadPromptLogs = function(){
                var el = document.getElementById('pl-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_prompt_logs',{
                    mode: (document.getElementById('pl-mode')||{}).value||'',
                    channel: (document.getElementById('pl-channel')||{}).value||'',
                    search: (document.getElementById('pl-search')||{}).value||'',
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderPromptLogs(data);
                });
            };

            function renderPromptLogs(data){
                var el = document.getElementById('pl-content');
                var rows = data.rows||[];

                // Stats summary
                var stats = data.stats||{};
                var h='<div class="bim-stats-grid">';
                h+= statCard(stats.total||0,'Tổng prompts');
                h+= statCard(stats.avg_duration||0,'TB ms');
                if(stats.by_mode){
                    stats.by_mode.forEach(function(m){
                        h+= statCard(m.cnt, m.mode||'—');
                    });
                }
                h+='</div>';

                if(!rows.length){
                    h+='<div class="bim-empty">Chưa có prompt log nào.</div>';
                    el.innerHTML=h;
                    return;
                }

                h+='<table class="bim-table"><thead><tr>';
                h+='<th>Time</th><th>User</th><th>Channel</th><th>Mode</th><th>Confidence</th>';
                h+='<th>Intent</th><th>Prompt</th><th>Response</th><th>Duration</th>';
                h+='</tr></thead><tbody>';

                rows.forEach(function(r){
                    var modeCls = {'emotion':'warn','execution':'active','knowledge':'info','reflection':'closed'}[r.detected_mode]||'info';
                    var prompt = r.message||'';
                    if(prompt.length>80) prompt=prompt.substring(0,77)+'...';
                    var resp = r.response_summary||'';
                    if(resp.length>60) resp=resp.substring(0,57)+'...';
                    h+='<tr>';
                    h+='<td style="white-space:nowrap;font-size:12px;">'+(r.created_at||'')+'</td>';
                    h+='<td>'+(r.user_id||0)+'</td>';
                    h+='<td>'+escHtml(r.channel||'')+'</td>';
                    h+='<td><span class="bim-badge bim-badge-'+modeCls+'">'+escHtml(r.detected_mode||'—')+'</span></td>';
                    h+='<td>'+(parseFloat(r.mode_confidence)||0).toFixed(2)+'</td>';
                    h+='<td>'+escHtml(r.intent_key||r.goal||'—')+'</td>';
                    h+='<td style="font-size:12px;max-width:250px;" title="'+escAttr(r.message||'')+'">'+escHtml(prompt)+'</td>';
                    h+='<td style="font-size:12px;max-width:200px;" title="'+escAttr(r.response_summary||'')+'">'+escHtml(resp)+'</td>';
                    h+='<td>'+(r.duration_ms||0)+'ms</td>';
                    h+='</tr>';
                });
                h+='</tbody></table>';
                el.innerHTML=h;
            }

            /* ────────────────────────────────────────────
             *  EXECUTOR DEBUG TAB
             * ──────────────────────────────────────────── */
            window.loadExecDebug = function(){
                var el = document.getElementById('exec-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_exec_debug',{
                    status: (document.getElementById('exec-status')||{}).value||'',
                    search: (document.getElementById('exec-search')||{}).value||'',
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderExecDebug(data);
                });
            };

            function renderExecDebug(data){
                var el = document.getElementById('exec-content');

                if(!data.available){
                    el.innerHTML='<div class="bim-empty">bizcity-executor plugin chưa được cài đặt hoặc chưa active.</div>';
                    return;
                }

                var traces = data.traces||[];
                var stats = data.stats||{};

                var h='<div class="bim-stats-grid">';
                h+= statCard(stats.total||0,'Tổng traces');
                h+= statCard(stats.running||0,'Đang chạy');
                h+= statCard(stats.completed||0,'Hoàn thành');
                h+= statCard(stats.failed||0,'Lỗi');
                h+='</div>';

                if(!traces.length){
                    h+='<div class="bim-empty">Chưa có executor trace nào.</div>';
                    el.innerHTML=h;
                    return;
                }

                h+='<table class="bim-table"><thead><tr>';
                h+='<th>Trace ID</th><th>Title</th><th>Status</th><th>Tasks</th>';
                h+='<th>Conv ID</th><th>User</th><th>Started</th><th>Duration</th>';
                h+='</tr></thead><tbody>';

                traces.forEach(function(t){
                    var sCls = {'queued':'waiting','running':'active','completed':'completed','failed':'error','waiting_human':'warn'}[t.status]||'info';
                    h+='<tr>';
                    h+='<td class="clickable" onclick="document.getElementById(\'debug-conv-id\').value=\''+escAttr(t.trace_id||'')+'\';document.querySelector(\'[data-tab=debug]\').click();loadDebugTraces();">'+shortId(t.trace_id||'')+'</td>';
                    h+='<td>'+escHtml(t.title||'—')+'</td>';
                    h+='<td><span class="bim-badge bim-badge-'+sCls+'">'+escHtml(t.status||'—')+'</span></td>';
                    h+='<td>'+(t.task_count||0)+' / '+(t.tasks_completed||0)+'✓</td>';
                    h+='<td>'+shortId(t.conv_id||'')+'</td>';
                    h+='<td>'+(t.actor_user_id||0)+'</td>';
                    h+='<td style="white-space:nowrap;font-size:12px;">'+(t.started_at||t.created_at||'')+'</td>';
                    h+='<td>'+(t.duration_ms||'—')+'</td>';
                    h+='</tr>';
                });
                h+='</tbody></table>';
                el.innerHTML=h;
            }

            /* ────────────────────────────────────────────
             *  HIL CHECKLIST TAB (v3.6.1)
             * ──────────────────────────────────────────── */
            var hilTimer = null;

            window.loadHilChecklist = function(){
                var el = document.getElementById('hil-content');
                el.innerHTML='<div class="bim-loading">Đang tải...</div>';
                api('bizcity_intent_monitor_hil',{
                    status: (document.getElementById('hil-status')||{}).value||'',
                    channel: (document.getElementById('hil-channel')||{}).value||'',
                    search: (document.getElementById('hil-search')||{}).value||'',
                },function(data,err){
                    if(err){el.innerHTML='<div class="bim-empty">Lỗi: '+err+'</div>';return;}
                    renderHilChecklist(data);
                    // Auto-refresh
                    clearInterval(hilTimer);
                    if(document.getElementById('hil-auto-refresh')&&document.getElementById('hil-auto-refresh').checked){
                        hilTimer = setInterval(function(){
                            if(document.querySelector('.bim-tab.active')&&document.querySelector('.bim-tab.active').dataset.tab==='hil'){
                                loadHilChecklist();
                            } else { clearInterval(hilTimer); }
                        }, 15000);
                    }
                });
            };

            function renderHilChecklist(data){
                var el = document.getElementById('hil-content');
                var items = data.items||[];
                if(!items.length){
                    el.innerHTML='<div class="bim-empty">Không có conversation nào đang hoạt động với goal.</div>';
                    return;
                }

                // Summary stats
                var waiting = items.filter(function(i){return i.status==='WAITING_USER';}).length;
                var active = items.filter(function(i){return i.status==='ACTIVE';}).length;
                var completed = items.filter(function(i){return i.status==='COMPLETED';}).length;

                var h = '<div class="bim-stats-grid">';
                h += statCard(items.length, 'Tổng conversations');
                h += statCard(waiting, 'Đang chờ user', 'bim-badge-waiting');
                h += statCard(active, 'Đang xử lý', 'bim-badge-active');
                h += statCard(completed, 'Hoàn thành', 'bim-badge-completed');
                h += '</div>';

                items.forEach(function(item){
                    var statusCls = item.status === 'WAITING_USER' ? 'hil-waiting' : (item.status === 'ACTIVE' ? 'hil-active' : (item.status === 'EXPIRED' ? 'hil-expired' : ''));
                    var slots = item.slot_checklist || [];
                    var filled = slots.filter(function(s){return s.status==='filled';}).length;
                    var skipped = slots.filter(function(s){return s.status==='skipped';}).length;
                    var total = slots.length;
                    var done = filled + skipped;
                    var pct = total > 0 ? Math.round(done/total*100) : 0;

                    h += '<div class="bim-hil-card '+statusCls+'">';
                    h += '<div class="bim-hil-header">';
                    h += '<h4>' + escHtml(item.goal_label||item.goal||'—') + ' ' + statusBadge(item.status) + '</h4>';
                    h += '<div class="bim-hil-meta">';
                    h += 'User #'+item.user_id+' · '+escHtml(item.channel||'')+' · '+item.turn_count+' turns · '+timeAgo(item.last_activity_at);
                    h += '</div>';
                    h += '</div>';

                    // Progress bar
                    h += '<div class="bim-hil-progress">';
                    h += '<div class="bim-hil-progress-bar"><div class="bim-hil-progress-fill" style="width:'+pct+'%"></div></div>';
                    h += '<div class="bim-hil-progress-text">'+done+'/'+total+' slots ('+pct+'%)</div>';
                    h += '</div>';

                    // Slot checklist
                    h += '<ul class="bim-hil-slots">';
                    slots.forEach(function(s){
                        var icon = '⬜';
                        if(s.status==='filled') icon='✅';
                        else if(s.status==='waiting') icon='⏳';
                        else if(s.status==='skipped') icon='⏭️';
                        else if(s.status==='invalid') icon='⚠️';

                        var reqLabel = s.required ? '<span class="bim-hil-slot-required">required</span>' : '<span class="bim-hil-slot-optional">optional</span>';
                        var value = s.value ? escHtml(s.value) : '<span class="empty">—</span>';
                        if(s.value && s.value.length > 60) value = escHtml(s.value.substring(0,57)) + '...';

                        h += '<li class="bim-hil-slot">';
                        h += '<span class="bim-hil-slot-icon">'+icon+'</span>';
                        h += '<span class="bim-hil-slot-name">'+escHtml(s.field)+'</span>';
                        h += '<span class="bim-hil-slot-type">'+escHtml(s.type||'text')+'</span>';
                        h += reqLabel;
                        h += '<span class="bim-hil-slot-value'+(s.value?'':' empty')+'">'+value+'</span>';
                        if(s.status==='waiting') h += '<span class="bim-badge bim-badge-waiting" style="font-size:10px;">← đang hỏi</span>';
                        h += '</li>';
                    });
                    h += '</ul>';

                    // Turns toggle
                    var cid = item.conversation_id;
                    h += '<span class="bim-hil-turns-toggle" onclick="toggleHilTurns(\''+cid+'\')">▸ Xem lịch sử hỏi-đáp ('+item.turn_count+' turns)</span>';
                    h += '<div class="bim-hil-turns" id="hil-turns-'+cid+'">';
                    if(item.turns && item.turns.length){
                        item.turns.forEach(function(t){
                            h += '<div class="bim-hil-turn-mini role-'+t.role+'">';
                            h += '<span class="role">'+t.role+'</span>';
                            h += escHtml((t.content||'').substring(0,200));
                            h += '</div>';
                        });
                    } else {
                        h += '<div style="font-size:12px;color:#999;">Chưa có turns.</div>';
                    }
                    h += '</div>';

                    h += '</div>';
                });

                el.innerHTML = h;
            }

            window.toggleHilTurns = function(convId){
                var el = document.getElementById('hil-turns-'+convId);
                if(!el) return;
                el.classList.toggle('open');
                var toggle = el.previousElementSibling;
                if(toggle){
                    toggle.textContent = el.classList.contains('open') ? '▾ Ẩn lịch sử' : '▸ Xem lịch sử hỏi-đáp';
                }
            };

            /* ── Auto-load overview on page load ── */
            loadStats();
        })();
        </script>
        <?php
    }

    /* ================================================================
     *  AJAX endpoints
     * ================================================================ */

    /**
     * Verify nonce for all monitor AJAX requests.
     */
    private function verify_access() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_intent_monitor' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }
    }

    /**
     * AJAX: Overview stats.
     */
    public function ajax_stats() {
        $this->verify_access();

        $days = intval( $_REQUEST['days'] ?? 7 );
        if ( $days < 1 ) $days = 7;

        $db = BizCity_Intent_Database::instance();
        global $wpdb;

        $conv_table = $db->conversations_table();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Conversation counts
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" );
        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$conv_table} WHERE status IN ('ACTIVE','WAITING_USER') AND created_at >= %s",
            $since
        ) );
        $completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$conv_table} WHERE status = 'COMPLETED' AND created_at >= %s",
            $since
        ) );

        // By channel
        $by_channel = $wpdb->get_results( $wpdb->prepare(
            "SELECT channel, COUNT(*) AS cnt
             FROM {$conv_table}
             WHERE created_at >= %s
             GROUP BY channel
             ORDER BY cnt DESC",
            $since
        ), ARRAY_A );

        // Log stats
        $log_stats = [];
        if ( class_exists( 'BizCity_Intent_Logger' ) ) {
            $log_stats = BizCity_Intent_Logger::instance()->get_stats( $days );
        }

        // ── Slot analytics: fill rate + HIL efficiency (v3.6.2) ──
        $log_table = BizCity_Intent_Logger::instance()->get_table_name();
        $slot_analytics = [];

        // Average slot fill rate per goal (from slot_fill_rate log entries)
        $fill_rates = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.goal')) AS goal,
                ROUND(AVG(CAST(JSON_EXTRACT(data_json, '$.fill_rate') AS UNSIGNED))) AS avg_fill_rate,
                ROUND(AVG(CAST(JSON_EXTRACT(data_json, '$.turns_taken') AS UNSIGNED)),1) AS avg_turns,
                COUNT(*) AS executions
             FROM {$log_table}
             WHERE step = 'slot_fill_rate'
               AND created_at >= %s
             GROUP BY goal
             ORDER BY executions DESC
             LIMIT 10",
            $since
        ), ARRAY_A );

        // Most abandoned goals (EXPIRED/CLOSED with goal set)
        $abandoned = $wpdb->get_results( $wpdb->prepare(
            "SELECT goal, goal_label, COUNT(*) AS cnt
             FROM {$conv_table}
             WHERE status IN ('EXPIRED','CLOSED')
               AND goal IS NOT NULL AND goal != ''
               AND created_at >= %s
             GROUP BY goal, goal_label
             ORDER BY cnt DESC
             LIMIT 10",
            $since
        ), ARRAY_A );

        // WAITING_USER breakdown (currently stuck conversations)
        $waiting_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$conv_table}
             WHERE status = 'WAITING_USER'"
        );

        $slot_analytics = [
            'fill_rates'    => $fill_rates ?: [],
            'abandoned'     => $abandoned ?: [],
            'waiting_count' => $waiting_count,
        ];

        wp_send_json_success( [
            'conversations' => [
                'total'      => $total,
                'active'     => $active,
                'completed'  => $completed,
                'by_channel' => $by_channel,
            ],
            'log_stats'      => $log_stats,
            'slot_analytics' => $slot_analytics,
        ] );
    }

    /**
     * AJAX: List conversations (admin view with all users).
     */
    public function ajax_conversations() {
        $this->verify_access();

        global $wpdb;
        $db = BizCity_Intent_Database::instance();
        $table = $db->conversations_table();

        $where_parts = [];
        $params      = [];

        $channel = sanitize_text_field( $_REQUEST['channel'] ?? '' );
        $status  = sanitize_text_field( $_REQUEST['status'] ?? '' );
        $search  = sanitize_text_field( $_REQUEST['search'] ?? '' );

        if ( $channel ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $channel;
        }
        if ( $status ) {
            $where_parts[] = 'status = %s';
            $params[]      = $status;
        }
        if ( $search ) {
            $where_parts[] = '(conversation_id LIKE %s OR goal LIKE %s OR goal_label LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

        $sql = "SELECT conversation_id, user_id, channel, goal, goal_label, status, 
                       turn_count, slots_json, created_at, last_activity_at
                FROM {$table}
                {$where}
                ORDER BY last_activity_at DESC
                LIMIT 100";

        if ( ! empty( $params ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        }

        wp_send_json_success( $rows ?: [] );
    }

    /**
     * AJAX: Conversation detail (turns + traces).
     */
    public function ajax_conversation_detail() {
        $this->verify_access();

        $conv_id = sanitize_text_field( $_REQUEST['conversation_id'] ?? '' );
        if ( empty( $conv_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        global $wpdb;
        $db = BizCity_Intent_Database::instance();

        // Get conversation
        $conv = $db->get_conversation( $conv_id );
        if ( ! $conv ) {
            wp_send_json_error( [ 'message' => 'Conversation not found' ] );
        }

        // Get turns
        $turns_table = $db->turns_table();
        $turns = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$turns_table}
             WHERE conversation_id = %s
             ORDER BY turn_index ASC
             LIMIT 200",
            $conv_id
        ), ARRAY_A );

        // Get traces (from logger)
        $traces = [];
        if ( class_exists( 'BizCity_Intent_Logger' ) ) {
            $traces = BizCity_Intent_Logger::instance()->get_traces_for_conversation( $conv_id );
        }

        wp_send_json_success( [
            'conversation' => (array) $conv,
            'turns'        => $turns ?: [],
            'traces'       => $traces ?: [],
        ] );
    }

    /**
     * AJAX: Get single trace detail.
     */
    public function ajax_trace() {
        $this->verify_access();

        $trace_id = sanitize_text_field( $_REQUEST['trace_id'] ?? '' );
        if ( empty( $trace_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing trace_id' ] );
        }

        $steps = BizCity_Intent_Logger::instance()->get_trace( $trace_id );

        wp_send_json_success( $steps ?: [] );
    }

    /**
     * AJAX: Query logs with filters.
     */
    public function ajax_logs() {
        $this->verify_access();

        $filters = [];
        if ( ! empty( $_REQUEST['level'] ) ) {
            $filters['level'] = sanitize_text_field( $_REQUEST['level'] );
        }
        if ( ! empty( $_REQUEST['step'] ) ) {
            $filters['step'] = sanitize_text_field( $_REQUEST['step'] );
        }
        if ( ! empty( $_REQUEST['conversation_id'] ) ) {
            $filters['conversation_id'] = sanitize_text_field( $_REQUEST['conversation_id'] );
        }
        if ( ! empty( $_REQUEST['user_id'] ) ) {
            $filters['user_id'] = intval( $_REQUEST['user_id'] );
        }

        $limit = intval( $_REQUEST['limit'] ?? 200 );
        if ( $limit < 1 || $limit > 1000 ) $limit = 200;

        $logs = BizCity_Intent_Logger::instance()->get_recent( $filters, $limit );

        wp_send_json_success( $logs ?: [] );
    }

    /**
     * AJAX: Export logs as downloadable JSON.
     */
    public function ajax_export_json() {
        $this->verify_access();

        $filters = [];
        if ( ! empty( $_REQUEST['level'] ) ) {
            $filters['level'] = sanitize_text_field( $_REQUEST['level'] );
        }
        if ( ! empty( $_REQUEST['step'] ) ) {
            $filters['step'] = sanitize_text_field( $_REQUEST['step'] );
        }
        if ( ! empty( $_REQUEST['conversation_id'] ) ) {
            $filters['conversation_id'] = sanitize_text_field( $_REQUEST['conversation_id'] );
        }
        if ( ! empty( $_REQUEST['user_id'] ) ) {
            $filters['user_id'] = intval( $_REQUEST['user_id'] );
        }

        $limit  = intval( $_REQUEST['limit'] ?? 2000 );
        $format = sanitize_text_field( $_REQUEST['format'] ?? 'flat' );
        if ( $limit < 1 || $limit > 10000 ) $limit = 2000;

        $data = BizCity_Intent_Logger::instance()->export_json( $filters, $limit, $format );

        wp_send_json_success( [
            'exported_at' => gmdate( 'Y-m-d H:i:s' ),
            'format'      => $format,
            'count'        => count( $data ),
            'filters'      => $filters,
            'logs'         => $data,
        ] );
    }

    /* ================================================================
     *  v3.3.0 — Tool Registry Tab
     * ================================================================ */

    /**
     * AJAX: List all registered tools (from memory + DB).
     */
    public function ajax_tools() {
        $this->verify_access();

        $search = sanitize_text_field( $_REQUEST['search'] ?? '' );
        $source = sanitize_text_field( $_REQUEST['source'] ?? '' );

        $tools = [];
        $by_source = [ 'built_in' => 0, 'plugin' => 0, 'provider' => 0 ];

        // 1. In-memory tools from BizCity_Intent_Tools
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $registry = BizCity_Intent_Tools::instance();
            if ( method_exists( $registry, 'get_all' ) ) {
                $all_tools = $registry->get_all();
            } elseif ( method_exists( $registry, 'list_tools' ) ) {
                $all_tools = $registry->list_tools();
            } else {
                $all_tools = [];
            }

            foreach ( $all_tools as $name => $schema ) {
                $tool_source = $schema['source'] ?? ( $registry->get_tool_source( $name ) ?? 'built_in' );

                $tools[] = [
                    'tool_name'       => $name,
                    'tool_key'        => $schema['tool_key'] ?? '',
                    'description'     => $schema['description'] ?? '',
                    'source'          => $tool_source,
                    'plugin_slug'     => $schema['plugin_slug'] ?? '',
                    'version'         => $schema['version'] ?? '',
                    'active'          => true,
                    'input_fields'    => $schema['input_fields'] ?? [],
                    'capability_tags' => $schema['capability_tags'] ?? [],
                    'intent_tags'     => $schema['intent_tags'] ?? [],
                    'domain_tags'     => $schema['domain_tags'] ?? [],
                    'scope'           => $schema['scope'] ?? 'global',
                    'in_memory'       => true,
                ];

                if ( isset( $by_source[ $tool_source ] ) ) {
                    $by_source[ $tool_source ]++;
                }
            }
        }

        // 2. DB-registered tools from bizcity-executor
        if ( class_exists( 'BizCity_Tool_Registry' ) ) {
            $db_tools = BizCity_Tool_Registry::instance()->list( [] );
            foreach ( $db_tools as $db_tool ) {
                // Check if already in memory list
                $exists = false;
                foreach ( $tools as &$mt ) {
                    if ( $mt['tool_name'] === ( $db_tool['tool_name'] ?? '' ) ) {
                        // Merge DB fields into memory tool
                        $mt['tool_key']        = $db_tool['tool_key']        ?? $mt['tool_key'];
                        $mt['capability_tags'] = $db_tool['capability_tags'] ?? $mt['capability_tags'];
                        $mt['intent_tags']     = $db_tool['intent_tags']     ?? $mt['intent_tags'];
                        $mt['domain_tags']     = $db_tool['domain_tags']     ?? $mt['domain_tags'];
                        $mt['version']         = $db_tool['version']         ?? $mt['version'];
                        $mt['in_db']           = true;
                        $exists = true;
                        break;
                    }
                }
                unset( $mt );

                if ( ! $exists ) {
                    $tool_source = $db_tool['source'] ?? 'plugin';
                    $tools[] = [
                        'tool_name'       => $db_tool['tool_name'] ?? '',
                        'tool_key'        => $db_tool['tool_key']  ?? '',
                        'description'     => $db_tool['description'] ?? '',
                        'source'          => $tool_source,
                        'plugin_slug'     => $db_tool['plugin_slug'] ?? '',
                        'version'         => $db_tool['version'] ?? '',
                        'active'          => (bool) ( $db_tool['active'] ?? true ),
                        'input_fields'    => $db_tool['input_fields'] ?? [],
                        'capability_tags' => $db_tool['capability_tags'] ?? [],
                        'intent_tags'     => $db_tool['intent_tags'] ?? [],
                        'domain_tags'     => $db_tool['domain_tags'] ?? [],
                        'scope'           => $db_tool['scope'] ?? 'global',
                        'in_memory'       => false,
                        'in_db'           => true,
                    ];
                    if ( isset( $by_source[ $tool_source ] ) ) {
                        $by_source[ $tool_source ]++;
                    }
                }
            }
        }

        // Apply filters
        if ( $source ) {
            $tools = array_values( array_filter( $tools, function( $t ) use ( $source ) {
                return ( $t['source'] ?? '' ) === $source;
            } ) );
        }
        if ( $search ) {
            $search_lower = mb_strtolower( $search, 'UTF-8' );
            $tools = array_values( array_filter( $tools, function( $t ) use ( $search_lower ) {
                $haystack = mb_strtolower( implode( ' ', [
                    $t['tool_name'] ?? '',
                    $t['tool_key'] ?? '',
                    $t['description'] ?? '',
                    implode( ' ', $t['capability_tags'] ?? [] ),
                    implode( ' ', $t['intent_tags'] ?? [] ),
                ] ), 'UTF-8' );
                return mb_strpos( $haystack, $search_lower ) !== false;
            } ) );
        }

        wp_send_json_success( [
            'tools'     => $tools,
            'total'     => count( $tools ),
            'by_source' => $by_source,
        ] );
    }

    /* ================================================================
     *  v3.3.0 — Prompt Logs Tab
     * ================================================================ */

    /**
     * AJAX: Query prompt logs.
     */
    public function ajax_prompt_logs() {
        $this->verify_access();

        $db = BizCity_Intent_Database::instance();

        $filters = [];
        if ( ! empty( $_REQUEST['mode'] ) ) {
            $filters['mode'] = sanitize_text_field( $_REQUEST['mode'] );
        }
        if ( ! empty( $_REQUEST['channel'] ) ) {
            $filters['channel'] = sanitize_text_field( $_REQUEST['channel'] );
        }
        if ( ! empty( $_REQUEST['search'] ) ) {
            $filters['search'] = sanitize_text_field( $_REQUEST['search'] );
        }
        if ( ! empty( $_REQUEST['user_id'] ) ) {
            $filters['user_id'] = intval( $_REQUEST['user_id'] );
        }

        $rows  = $db->get_prompt_logs( $filters, 200 );
        $stats = $db->get_prompt_log_stats( 7 );

        wp_send_json_success( [
            'rows'  => $rows,
            'stats' => $stats,
        ] );
    }

    /* ================================================================
     *  v3.3.0 — Executor Debug Tab
     * ================================================================ */

    /**
     * AJAX: List executor traces and stats (reads from executor DB).
     */
    public function ajax_exec_debug() {
        $this->verify_access();

        // Check if executor plugin is installed
        if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
            wp_send_json_success( [ 'available' => false ] );
        }

        global $wpdb;
        $status_filter = sanitize_text_field( $_REQUEST['status'] ?? '' );
        $search        = sanitize_text_field( $_REQUEST['search'] ?? '' );

        // Get traces table (from executor DB class)
        $table = $wpdb->prefix . 'bizcity_executor_traces';

        $where_parts = [];
        $params      = [];

        if ( $status_filter ) {
            $where_parts[] = 'status = %s';
            $params[]      = $status_filter;
        }
        if ( $search ) {
            $where_parts[] = '(trace_id LIKE %s OR title LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

        $sql = "SELECT trace_id, title, status, goal, conv_id, session_id,
                       actor_user_id, priority, started_at, completed_at, created_at,
                       TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW())) * 1000 AS duration_ms
                FROM {$table}
                {$where}
                ORDER BY created_at DESC
                LIMIT 100";

        if ( ! empty( $params ) ) {
            $traces = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        } else {
            $traces = $wpdb->get_results( $sql, ARRAY_A );
        }

        // Enrich with task counts
        $task_table = $wpdb->prefix . 'bizcity_executor_tasks';
        foreach ( $traces as &$t ) {
            $tid = $t['trace_id'];
            $t['task_count'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$task_table} WHERE trace_id = %s", $tid
            ) );
            $t['tasks_completed'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$task_table} WHERE trace_id = %s AND status = 'completed'", $tid
            ) );
        }
        unset( $t );

        // Stats
        $stats = [
            'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'running'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running')" ),
            'completed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" ),
            'failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ),
        ];

        wp_send_json_success( [
            'available' => true,
            'traces'    => $traces ?: [],
            'stats'     => $stats,
        ] );
    }

    /* ================================================================
     *  v3.6.1 — HIL Checklist Tab
     * ================================================================ */

    /**
     * AJAX: List active/waiting conversations with slot-filling checklist.
     *
     * For each conversation with a goal, computes the slot checklist:
     *   - Reads the plan schema (required + optional slots)
     *   - Compares against current slots_json
     *   - Marks each slot as: filled / waiting / pending / skipped / invalid
     *   - Returns turns for inline viewing
     */
    public function ajax_hil_checklist() {
        $this->verify_access();

        global $wpdb;
        $db    = BizCity_Intent_Database::instance();
        $table = $db->conversations_table();

        $where_parts = [];
        $params      = [];

        $status  = sanitize_text_field( $_REQUEST['status'] ?? '' );
        $channel = sanitize_text_field( $_REQUEST['channel'] ?? '' );
        $search  = sanitize_text_field( $_REQUEST['search'] ?? '' );

        // Default: show conversations that have a goal
        $where_parts[] = "goal != ''";

        if ( $status ) {
            $where_parts[] = 'status = %s';
            $params[]      = $status;
        }
        if ( $channel ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $channel;
        }
        if ( $search ) {
            $where_parts[] = '(goal LIKE %s OR goal_label LIKE %s OR CAST(user_id AS CHAR) LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = 'WHERE ' . implode( ' AND ', $where_parts );
        $sql   = "SELECT * FROM {$table} {$where} ORDER BY last_activity_at DESC LIMIT 50";

        if ( ! empty( $params ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        } else {
            $rows = $wpdb->get_results( $sql );
        }

        // Get planner for plan schemas
        $planner = BizCity_Intent_Planner::instance();

        $items = [];
        foreach ( $rows as $row ) {
            $conv_id = $row->conversation_id;
            $goal    = $row->goal;
            $slots   = json_decode( $row->slots_json ?: '{}', true ) ?: [];

            // Get plan schema for this goal
            $plan = $planner->get_plan( $goal );

            $slot_checklist = [];
            if ( $plan ) {
                // Required slots
                foreach ( $plan['required_slots'] as $field => $config ) {
                    $slot_checklist[] = $this->build_slot_status(
                        $field, $config, $slots, $row->waiting_field, true
                    );
                }
                // Optional slots
                foreach ( $plan['optional_slots'] as $field => $config ) {
                    $slot_checklist[] = $this->build_slot_status(
                        $field, $config, $slots, $row->waiting_field, false
                    );
                }
            }

            // Get recent turns (last 20)
            $turns_table = $db->turns_table();
            $turns = $wpdb->get_results( $wpdb->prepare(
                "SELECT role, content, created_at FROM {$turns_table}
                 WHERE conversation_id = %s
                 ORDER BY turn_index ASC
                 LIMIT 20",
                $conv_id
            ), ARRAY_A );

            $items[] = [
                'conversation_id' => $conv_id,
                'user_id'         => (int) $row->user_id,
                'channel'         => $row->channel,
                'goal'            => $goal,
                'goal_label'      => $row->goal_label ?: $goal,
                'status'          => $row->status,
                'turn_count'      => (int) $row->turn_count,
                'waiting_for'     => $row->waiting_for,
                'waiting_field'   => $row->waiting_field,
                'last_activity_at' => $row->last_activity_at,
                'created_at'      => $row->created_at,
                'slot_checklist'  => $slot_checklist,
                'turns'           => $turns ?: [],
            ];
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    /**
     * Build the status object for a single slot in the HIL checklist.
     *
     * @param string $field         Slot field name.
     * @param array  $config        Slot config from plan (type, prompt, default, etc.).
     * @param array  $slots         Current conversation slots.
     * @param string $waiting_field The field currently being asked.
     * @param bool   $required      Whether this is a required slot.
     * @return array Slot status object.
     */
    private function build_slot_status( $field, $config, $slots, $waiting_field, $required, $conv_status = '' ) {
        $type  = $config['type'] ?? 'text';
        $value = $slots[ $field ] ?? null;

        // Determine status
        $status = 'pending';
        if ( $waiting_field === $field ) {
            $status = 'waiting';
        } elseif ( $value !== null && $value !== '' && $value !== [] ) {
            // Check if it's a skip/default value
            $default = $config['default'] ?? null;
            if ( $default !== null && $value === $default && ! $required ) {
                $status = 'skipped';
            } else {
                $status = 'filled';
            }
        } elseif ( strtoupper( $conv_status ) === 'COMPLETED' && ! $required ) {
            // Conversation done — unfilled optional slots are implicitly skipped
            $status = 'skipped';
        }

        $display_value = '';
        if ( is_array( $value ) ) {
            $display_value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
        } elseif ( is_string( $value ) ) {
            $display_value = $value;
        }

        return [
            'field'    => $field,
            'type'     => $type,
            'required' => $required,
            'status'   => $status,
            'value'    => $display_value,
            'prompt'   => $config['prompt'] ?? '',
            'label'    => $config['label'] ?? $field,
        ];
    }
}
