<?php
/**
 * Bizcity Twin AI — Working Panel
 * Bảng theo dõi thực thi nổi / Floating execution monitor panel
 *
 * Injected into WP Admin and frontend pages.
 * Desktop: floating bottom-left panel, 360px wide.
 * Mobile: full-width compact strip.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.1.0
 * @see     INTENT-SKELETON.md section 10 — Execution Log Standard
 * @see     class-execution-logger.php — log producer
 * @see     class-user-memory.php ajax_poll_execution_log() — AJAX endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Working_Panel {

    /** @var self|null */
    private static $instance = null;

    /** @var string Nonce action */
    const NONCE_ACTION = 'bizcity_chat';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( is_admin() ) {
            // WP admin pages
            add_action( 'admin_footer', [ $this, 'render' ], 99 );
        } else {
            // Public-facing frontend (e.g. page-aiagent-home.php, standalone chat)
            add_action( 'wp_footer', [ $this, 'render' ], 99 );
        }
    }

    /**
     * Render the floating panel HTML + CSS + JS
     * Called at admin_footer — output is safe inline HTML
     */
    public function render() {
        // On WP admin: require manage_options. On frontend: require login.
        if ( is_admin() ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
        } else {
            if ( ! is_user_logged_in() ) {
                return;
            }
        }

        $nonce      = wp_create_nonce( self::NONCE_ACTION );
        $ajax_url   = is_admin() ? admin_url( 'admin-ajax.php' ) : admin_url( 'admin-ajax.php' );
        $user_id    = get_current_user_id();
        $blog_name  = get_bloginfo( 'name' );

        ?>
<!-- BizCity Working Panel -->
<div id="bwp-wrap" class="bwp-wrap" role="complementary" aria-label="BizCity Execution Monitor">
    <div id="bwp-header" class="bwp-header">
        <span class="bwp-icon" id="bwp-status-icon">⚙️</span>
        <span class="bwp-title" id="bwp-title">Working Panel</span>
        <span class="bwp-badge" id="bwp-badge" style="display:none;"></span>
        <div class="bwp-controls">
            <button class="bwp-ctrl-btn" id="bwp-export-btn" title="Xuất JSON">⬇</button>
            <button class="bwp-ctrl-btn" id="bwp-clear-btn" title="Xóa logs">🗑</button>
            <button class="bwp-ctrl-btn" id="bwp-expand-btn" title="Phóng to">⛶</button>
            <button class="bwp-ctrl-btn" id="bwp-min-btn" title="Thu nhỏ">—</button>
        </div>
    </div>
    <div id="bwp-body" class="bwp-body">
        <!-- Stats bar -->
        <div id="bwp-stats" class="bwp-stats" style="display:none;">
            <span id="bwp-stat-tools">0 tools</span>
            <span class="bwp-stat-sep">·</span>
            <span id="bwp-stat-ok" class="bwp-ok">0 ok</span>
            <span class="bwp-stat-sep">·</span>
            <span id="bwp-stat-err" class="bwp-err">0 err</span>
            <span class="bwp-stat-sep">·</span>
            <span id="bwp-stat-ms" class="bwp-ms">—</span>
        </div>
        <!-- Log list -->
        <div id="bwp-log-list" class="bwp-log-list">
            <div class="bwp-empty">Chưa có hoạt động. Gửi tin nhắn để xem log thực thi...</div>
        </div>
    </div>
    <div class="bwp-resizer" id="bwp-resizer" title="Kéo để thay đổi kích thước"></div>
</div>

<!-- BizCity Working Panel: Minimized button -->
<button id="bwp-fab" class="bwp-fab" title="Mở Execution Monitor" style="display:none;">
    <span class="bwp-fab-icon">⚙️</span>
    <span class="bwp-fab-badge" id="bwp-fab-badge"></span>
</button>

<style id="bwp-styles">
/* ═════════════════════════════════════════════
   BizCity Working Panel — Floating Execution Monitor
   ═════════════════════════════════════════════ */
#bwp-wrap {
    position: fixed;
    bottom: 24px;
    left: 24px;
    width: 360px;
    min-width: 280px;
    max-width: 520px;
    max-height: 520px;
    background: #1e1e2e;
    color: #cdd6f4;
    border-radius: 14px;
    font-family: 'JetBrains Mono', Consolas, 'Courier New', monospace;
    font-size: 11px;
    z-index: 99990;
    box-shadow: 0 8px 40px rgba(0,0,0,.55), 0 0 0 1px rgba(99,102,241,.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: transform .25s ease, opacity .25s ease;

    /* Hidden by default — shown when execution starts */
    transform: translateY(20px);
    opacity: 0;
    pointer-events: none;
}
#bwp-wrap.bwp-visible {
    transform: translateY(0);
    opacity: 1;
    pointer-events: all;
}
#bwp-wrap.bwp-expanded {
    width: 600px !important;
    max-width: 800px !important;
    max-height: 80vh !important;
    bottom: 50% !important;
    left: 50% !important;
    transform: translate(-50%, 50%) !important;
    z-index: 100000 !important;
}
#bwp-wrap.bwp-expanded.bwp-visible {
    transform: translate(-50%, 50%) !important;
}
/* Overlay behind expanded mode */
#bwp-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 99989;
}
#bwp-overlay.active { display: block; }

/* ── Header (drag handle) ── */
.bwp-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    background: #313244;
    border-radius: 14px 14px 0 0;
    cursor: grab;
    flex-shrink: 0;
    user-select: none;
    touch-action: none;
}
.bwp-header:active, #bwp-wrap.bwp-dragging .bwp-header {
    cursor: grabbing;
}
#bwp-wrap.bwp-dragging {
    transition: none !important;
    opacity: 0.95;
}
.bwp-icon { font-size: 14px; transition: transform .4s; }
.bwp-icon.spin { animation: bwp-spin 1s linear infinite; }
@keyframes bwp-spin { to { transform: rotate(360deg); } }
.bwp-title {
    flex: 1;
    font-weight: 700;
    font-size: 11px;
    color: #cdd6f4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bwp-badge {
    background: #f38ba8;
    color: #1e1e2e;
    font-size: 9px;
    font-weight: 800;
    padding: 1px 5px;
    border-radius: 6px;
    min-width: 16px;
    text-align: center;
}
.bwp-badge.ok { background: #a6e3a1; }
.bwp-controls { display: flex; gap: 3px; flex-shrink: 0; }
.bwp-ctrl-btn {
    background: #45475a;
    border: none;
    color: #cdd6f4;
    padding: 2px 7px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 10px;
    line-height: 1.4;
    transition: background .15s;
}
.bwp-ctrl-btn:hover { background: #6c7086; color: #f9e2af; }

/* ── Stats bar ── */
.bwp-stats {
    padding: 4px 10px;
    background: #181825;
    font-size: 10px;
    display: flex;
    gap: 4px;
    align-items: center;
    flex-shrink: 0;
    border-bottom: 1px solid #313244;
}
.bwp-stat-sep { color: #45475a; }
.bwp-ok  { color: #a6e3a1; font-weight: 700; }
.bwp-err { color: #f38ba8; font-weight: 700; }
.bwp-ms  { color: #f9e2af; }

/* ── Body / Log list ── */
.bwp-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 60px;
}
.bwp-log-list {
    flex: 1;
    overflow-y: auto;
    padding: 6px 8px;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.bwp-log-list::-webkit-scrollbar { width: 4px; }
.bwp-log-list::-webkit-scrollbar-thumb { background: #45475a; border-radius: 4px; }
.bwp-empty { color: #6c7086; font-style: italic; padding: 8px 4px; font-size: 10px; }

/* ── Log Entry ── */
.bwp-entry {
    padding: 4px 6px;
    border-radius: 5px;
    border-left: 2px solid transparent;
    animation: bwp-entry-in .2s ease;
}
@keyframes bwp-entry-in { from { opacity:0; transform:translateX(-6px); } }
.bwp-entry.step-tool_invoke       { border-left-color: #89b4fa; }
.bwp-entry.step-tool_result       { border-left-color: #a6e3a1; background: rgba(166,227,161,.06); }
.bwp-entry.step-tool_result.fail  { border-left-color: #f38ba8; background: rgba(243,139,168,.06); }
.bwp-entry.step-tool_step         { border-left-color: #fab387; background: rgba(250,179,135,.04); }
.bwp-entry.step-tool_step.error   { border-left-color: #f38ba8; }
.bwp-entry.step-tool_step.success { border-left-color: #a6e3a1; }
.bwp-entry.step-pipeline_start    { border-left-color: #cba6f7; }
.bwp-entry.step-pipeline_step     { border-left-color: #89dceb; }
.bwp-entry.step-pipeline_complete { border-left-color: #a6e3a1; }
.bwp-entry.step-error             { border-left-color: #f38ba8; background: rgba(243,139,168,.1); }
.bwp-entry.step-goal_update       { border-left-color: #f9e2af; }
.bwp-entry.step-slot_resolve      { border-left-color: #74c7ec; }
.success { background: unset !important; }
.bwp-entry-hd {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
    line-height: 1.5;
}
.bwp-step-badge {
    font-size: 9px;
    font-weight: 800;
    padding: 0 5px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}
.step-tool_invoke .bwp-step-badge        { background:#1e3a5f;color:#89b4fa; }
.step-tool_result .bwp-step-badge        { background:#1a3a2a;color:#a6e3a1; }
.step-tool_result.fail .bwp-step-badge   { background:#3a1e1e;color:#f38ba8; }
.step-tool_step .bwp-step-badge          { background:#3a2a1a;color:#fab387; }
.step-tool_step.success .bwp-step-badge  { background:#1a3a2a;color:#a6e3a1; }
.step-tool_step.error .bwp-step-badge    { background:#3a1e1e;color:#f38ba8; }
.step-pipeline_start .bwp-step-badge     { background:#2e1a4a;color:#cba6f7; }
.step-pipeline_step .bwp-step-badge      { background:#1a3a3a;color:#89dceb; }
.step-pipeline_complete .bwp-step-badge  { background:#1a3a2a;color:#a6e3a1; }
.step-error .bwp-step-badge              { background:#3a1e1e;color:#f38ba8; }
.step-goal_update .bwp-step-badge        { background:#3a3a1e;color:#f9e2af; }
.step-slot_resolve .bwp-step-badge       { background:#1a2e3a;color:#74c7ec; }

.bwp-tool-name { color: #cdd6f4; font-weight: 700; font-size: 11px; }
.bwp-ms-badge  { color: #f9e2af; font-size: 9px; margin-left: auto; }
.bwp-ts        { color: #45475a; font-size: 9px; }
.bwp-status-icon { font-size: 11px; }

.bwp-entry-detail {
    margin: 2px 0 0 18px;
    color: #9399b2;
    font-size: 10px;
    line-height: 1.4;
    word-break: break-all;
}
.bwp-entry-detail.success { color: #a6e3a1; }
.bwp-entry-detail.error   { color: #f38ba8; }
.bwp-entry-detail.info    { color: #89dceb; }

/* Waiting indicator for pending invoke (hourglass icon, no animation) */
.bwp-running {
    display: inline-block;
    font-size: 10px;
    line-height: 1;
    flex-shrink: 0;
}
.bwp-running::before {
    content: '⏳';
}

/* Pipeline step progress */
.bwp-progress {
    display: flex;
    gap: 3px;
    margin: 3px 0 2px 18px;
}
.bwp-progress-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #45475a;
    transition: background .3s;
}
.bwp-progress-dot.done    { background: #a6e3a1; }
.bwp-progress-dot.active  { background: #89b4fa; animation: bwp-dot-pulse 1s infinite; }
.bwp-progress-dot.error   { background: #f38ba8; }
@keyframes bwp-dot-pulse { 0%,100%{opacity:.6} 50%{opacity:1} }

/* ── FAB (minimized state) ── */
.bwp-fab {
    position: fixed;
    bottom: 24px;
    left: 24px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #313244;
    border: 2px solid rgba(99,102,241,.35);
    color: #cdd6f4;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99990;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
    transition: transform .2s, box-shadow .2s;
    padding: 0;
}
.bwp-fab:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 24px rgba(99,102,241,.4);
}
.bwp-fab-badge {
    position: absolute;
    top: -2px; right: -2px;
    background: #f38ba8;
    color: #1e1e2e;
    font-size: 8px;
    font-weight: 800;
    width: 16px; height: 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid #1e1e2e;
}
.bwp-fab-badge:empty { display: none; }

/* ── Resizer ── */
.bwp-resizer {
    height: 4px;
    background: linear-gradient(90deg, transparent 20%, rgba(99,102,241,.2) 50%, transparent 80%);
    cursor: ns-resize;
    flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════
   MOBILE (≤600px) — Full-width strip above touchbar
   ═══════════════════════════════════════════════════
   The touchbar is ~56px tall, so we sit at bottom:56px.
   Panel collapses to a single 40px header strip and
   expands upward when tapped. The FAB circle is hidden
   on mobile — the strip itself acts as the indicator.
 ═══════════════════════════════════════════════════ */
@media (max-width: 600px) {
    /* ── Base panel: full-width strip ── */
    #bwp-wrap {
        top: 76px !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        border-radius: 12px 12px 0 0 !important;
        /* Collapsed: show only 40px header strip */
        max-height: 40px !important;
        /* Always visible when has entries — override translateY default hidden state */
        transform: translateY(0) !important;
        transition: max-height .35s ease, opacity .25s ease !important;
    }
    /* Already visible on mobile — just opacity */
    #bwp-wrap.bwp-visible {
        transform: translateY(0) !important;
        opacity: 1 !important;
        pointer-events: all !important;
    }
    /* Expanded: slides up to show step feed */
    #bwp-wrap.bwp-mobile-expanded {
        max-height: 55vh !important;
        border-radius: 16px 16px 0 0 !important;
    }
    /* Pulse border on mobile when active */
    #bwp-wrap.bwp-active {
        box-shadow: 0 -2px 0 rgba(99,102,241,.8), 0 -8px 24px rgba(99,102,241,.2) !important;
        animation: bwp-border-pulse-mobile 1.8s infinite !important;
    }
    @keyframes bwp-border-pulse-mobile {
        0%,100% { box-shadow: 0 -2px 0 rgba(99,102,241,.7), 0 -8px 24px rgba(99,102,241,.2); }
        50%      { box-shadow: 0 -3px 0 rgba(139,92,246,1),  0 -8px 24px rgba(139,92,246,.35); }
    }
    /* Header: tap target for expand/collapse */
    .bwp-header {
        cursor: pointer;
        padding: 8px 12px !important;
        border-radius: 12px 12px 0 0 !important;
    }
    /* Expand chevron hint */
    .bwp-header::after {
        content: '▲';
        font-size: 8px;
        color: #6c7086;
        margin-left: 4px;
        transition: transform .25s;
    }
    #bwp-wrap.bwp-mobile-expanded .bwp-header::after {
        transform: rotate(180deg);
    }
    /* Hide desktop-only controls on mobile */
    #bwp-expand-btn,
    .bwp-resizer {
        display: none !important;
    }
    /* FAB circle: hidden on mobile — strip is the indicator */
    .bwp-fab {
        display: none !important;
    }
    /* Body scroll area */
    .bwp-log-list {
        max-height: calc(55vh - 80px);
    }
    /* Overlay: not used on mobile */
    #bwp-overlay {
        display: none !important;
    }
}
</style>

<!-- BizCity Working Panel Overlay -->
<div id="bwp-overlay"></div>

<script id="bwp-script">
(function () {
    'use strict';

    /* ── Config ── */
    var AJAX_URL     = <?php echo wp_json_encode( $ajax_url ); ?>;
    var NONCE        = <?php echo wp_json_encode( $nonce ); ?>;
    var IDLE_INTERVAL   = 8000;  // 8s when no activity
    var ACTIVE_INTERVAL = 1000;  // 1s when actively executing
    var AUTO_HIDE_AFTER = 30000; // Auto-hide 30s after last log entry
    var MAX_ENTRIES     = 60;    // Max entries in panel

    /* ── State ── */
    var state = {
        visible:     false,
        minimized:   localStorage.getItem('bwp_minimized') === '1',
        expanded:    false,
        active:      false,
        lastMicro:   0,        // latest microtime seen — used to detect new entries
        errorCount:  0,
        toolCount:   0,
        okCount:     0,
        entries:     [],       // rendered entries (by microtime key)
        allLogs:     [],       // raw log objects for export
        subStepKeys: {},       // key: tool_name|sub_step → microtime of last DOM entry
        sessionId:   window.bizcCurrentSessionId || window.bizcSessionId || '',
        convId:      window.bizcCurrentConvId    || window.bizcConvId    || '',
        autoHideTimer: null,
        pollTimer:   null,
        pollInterval: IDLE_INTERVAL,
        // Inline mode state — renders inside chat typing indicator
        inlineMode:       false,
        inlineContainer:  null,  // DOM element for inline exec container
        inlineLogEl:      null,  // log list inside inline container
        inlineHeaderEl:   null,  // header inside inline container
        inlineBadgeEl:    null,  // badge inside inline header
        inlineIconEl:     null,  // icon inside inline header
    };

    /* ── DOM refs ── */
    var wrap     = document.getElementById('bwp-wrap');
    var fab      = document.getElementById('bwp-fab');
    var header   = document.getElementById('bwp-header');
    var titleEl  = document.getElementById('bwp-title');
    var badgeEl  = document.getElementById('bwp-badge');
    var iconEl   = document.getElementById('bwp-status-icon');
    var statsEl  = document.getElementById('bwp-stats');
    var logList  = document.getElementById('bwp-log-list');
    var minBtn   = document.getElementById('bwp-min-btn');
    var expandBtn= document.getElementById('bwp-expand-btn');
    var clearBtn = document.getElementById('bwp-clear-btn');
    var exportBtn= document.getElementById('bwp-export-btn');
    var fabBadge = document.getElementById('bwp-fab-badge');
    var overlay  = document.getElementById('bwp-overlay');
    var statTools= document.getElementById('bwp-stat-tools');
    var statOk   = document.getElementById('bwp-stat-ok');
    var statErr  = document.getElementById('bwp-stat-err');
    var statMs   = document.getElementById('bwp-stat-ms');

    if (!wrap || !fab) return; // Safety guard

    /* ── Detect mobile ── */
    var isMobile = function() { return window.innerWidth <= 600; };

    /* ═══════════════════════════════════════════
       DRAG & DROP — works on desktop + mobile
       ═══════════════════════════════════════════ */
    var dragTracker = { dragged: false }; // Shared flag to prevent click after drag
    (function initDrag() {
        var dragState = { dragging: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };
        var STORAGE_KEY = 'bwp_position';

        // Restore saved position
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                var pos = JSON.parse(saved);
                if (pos.left !== undefined && pos.top !== undefined) {
                    wrap.style.left = pos.left + 'px';
                    wrap.style.top = pos.top + 'px';
                    wrap.style.bottom = 'auto';
                    wrap.style.right = 'auto';
                }
            }
        } catch (e) {}

        function getPointer(e) {
            if (e.touches && e.touches.length) {
                return { x: e.touches[0].clientX, y: e.touches[0].clientY };
            }
            return { x: e.clientX, y: e.clientY };
        }

        function onStart(e) {
            // Don't drag if clicking buttons inside header
            if (e.target.closest && e.target.closest('.bwp-ctrl-btn')) return;
            // Don't drag if expanded (centered modal)
            if (wrap.classList.contains('bwp-expanded')) return;

            var ptr = getPointer(e);
            var rect = wrap.getBoundingClientRect();

            dragState.dragging = true;
            dragState.startX = ptr.x;
            dragState.startY = ptr.y;
            dragState.origLeft = rect.left;
            dragState.origTop = rect.top;

            wrap.classList.add('bwp-dragging');

            // Prevent text selection
            e.preventDefault();
        }

        function onMove(e) {
            if (!dragState.dragging) return;

            var ptr = getPointer(e);
            var dx = ptr.x - dragState.startX;
            var dy = ptr.y - dragState.startY;

            // Mark as dragged if moved more than 5px (threshold for tap vs drag)
            if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
                dragTracker.dragged = true;
            }

            var newLeft = dragState.origLeft + dx;
            var newTop = dragState.origTop + dy;

            // Clamp within viewport
            var maxX = window.innerWidth - 80;
            var maxY = window.innerHeight - 40;
            newLeft = Math.max(0, Math.min(newLeft, maxX));
            newTop = Math.max(0, Math.min(newTop, maxY));

            wrap.style.left = newLeft + 'px';
            wrap.style.top = newTop + 'px';
            wrap.style.bottom = 'auto';
            wrap.style.right = 'auto';
        }

        function onEnd() {
            if (!dragState.dragging) return;
            dragState.dragging = false;
            wrap.classList.remove('bwp-dragging');

            // Reset dragged flag after short delay (so click handler can check it)
            setTimeout(function() { dragTracker.dragged = false; }, 50);

            // Save position to localStorage
            try {
                var rect = wrap.getBoundingClientRect();
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    left: Math.round(rect.left),
                    top: Math.round(rect.top)
                }));
            } catch (e) {}
        }

        // Mouse events
        header.addEventListener('mousedown', onStart);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);

        // Touch events
        header.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onEnd);
        document.addEventListener('touchcancel', onEnd);
    })();

    /* ── Mobile: tap header to expand/collapse strip ── */
    header.addEventListener('click', function(e) {
        if (!isMobile()) return;
        // Don't toggle if was a drag, not a tap
        if (dragTracker.dragged) return;
        // Don't toggle if a button inside header was clicked
        if (e.target.closest && e.target.closest('.bwp-ctrl-btn')) return;
        wrap.classList.toggle('bwp-mobile-expanded');
    });

    /* ── Restore minimized state ── */
    if (state.minimized && !isMobile()) {
        wrap.style.display = 'none';
        fab.style.display = 'flex';
    }

    /* ── Visibility ── */
    function show() {
        if (state.inlineMode) return; // Don't show floating panel when inline mode is active
        if (state.minimized && !isMobile()) return;
        state.visible = true;
        wrap.classList.add('bwp-visible');
        // On mobile: also auto-expand for a moment when new task starts
        if (isMobile() && state.active) {
            wrap.classList.add('bwp-mobile-expanded');
        }
    }
    function hide() {
        state.visible = false;
        wrap.classList.remove('bwp-visible');
        wrap.classList.remove('bwp-active');
        if (isMobile()) wrap.classList.remove('bwp-mobile-expanded');
    }
    function minimize() {
        if (isMobile()) {
            // On mobile: just collapse the strip, don't FAB
            wrap.classList.remove('bwp-mobile-expanded');
            return;
        }
        state.minimized = true;
        localStorage.setItem('bwp_minimized', '1');
        wrap.style.display = 'none';
        fab.style.display = 'flex';
        if (state.errorCount > 0) {
            fabBadge.textContent = state.errorCount;
        }
    }
    function restore() {
        state.minimized = false;
        localStorage.removeItem('bwp_minimized');
        if (!isMobile()) {
            wrap.style.display = 'flex';
            fab.style.display = 'none';
            fabBadge.textContent = '';
        }
        if (state.entries.length > 0) {
            show();
        }
    }
    function toggleExpand() {
        state.expanded = !state.expanded;
        if (state.expanded) {
            wrap.classList.add('bwp-expanded');
            overlay.classList.add('active');
            expandBtn.textContent = '⊠';
            expandBtn.title = 'Thu nhỏ';
        } else {
            wrap.classList.remove('bwp-expanded');
            overlay.classList.remove('active');
            expandBtn.textContent = '⛶';
            expandBtn.title = 'Phóng to';
        }
    }

    /* ── Controls ── */
    exportBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var payload = { exported_at: new Date().toISOString(), session_id: state.sessionId, logs: state.allLogs };
        var json = JSON.stringify(payload, null, 2);
        var blob = new Blob([json], { type: 'application/json' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'bwp-logs-' + Date.now() + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
    minBtn.addEventListener('click', function(e) { e.stopPropagation(); minimize(); });
    fab.addEventListener('click', function() { restore(); show(); });
    expandBtn.addEventListener('click', function(e) { e.stopPropagation(); toggleExpand(); });
    overlay.addEventListener('click', function() { if (state.expanded) toggleExpand(); });
    clearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        logList.innerHTML = '<div class="bwp-empty">Đã xóa logs.</div>';
        statsEl.style.display = 'none';
        state.entries = [];
        state.allLogs = [];
        state.subStepKeys = {};
        state.toolCount = state.okCount = state.errorCount = 0;
        state.lastMicro = 0;
        badgeEl.style.display = 'none';
        if (fabBadge) fabBadge.textContent = '';
        _setIdle();
    });

    /* ── Progress bar drag ── */
    var _resizing = false, _resizeStartY = 0, _resizeStartH = 0;
    document.getElementById('bwp-resizer').addEventListener('mousedown', function(e) {
        _resizing = true;
        _resizeStartY = e.clientY;
        _resizeStartH = wrap.offsetHeight;
        e.preventDefault();
    });
    document.addEventListener('mousemove', function(e) {
        if (!_resizing) return;
        var dy = _resizeStartY - e.clientY;
        var newH = Math.max(120, Math.min(600, _resizeStartH + dy));
        wrap.style.maxHeight = newH + 'px';
    });
    document.addEventListener('mouseup', function() { _resizing = false; });

    /* ── Session / Conv sync ── */
    function _syncSession() {
        state.sessionId = window.bizcCurrentSessionId || window.bizcSessionId || state.sessionId || '';
        state.convId    = window.bizcCurrentConvId    || window.bizcConvId    || state.convId    || '';
    }

    /* ── Step type config ── */
    var STEP_INFO = {
        tool_invoke:       { icon: '↪', label: 'INVOKE',   spin: true  },
        tool_result:       { icon: '✅', label: 'RESULT',   spin: false },
        tool_step:         { icon: '⋯', label: 'STEP',     spin: true  },
        pipeline_start:    { icon: '▶', label: 'PIPELINE', spin: false },
        pipeline_step:     { icon: '⋯', label: 'P-STEP',   spin: true  },
        pipeline_complete: { icon: '🏁', label: 'DONE',     spin: false },
        error:             { icon: '❌', label: 'ERROR',    spin: false },
        goal_update:       { icon: '🎯', label: 'GOAL',     spin: false },
        slot_resolve:      { icon: '🔗', label: 'RESOLVE',  spin: false },
    };

    /* ── Render one log entry ── */
    function _renderEntry(log) {
        var step   = log.step || 'unknown';
        var info   = STEP_INFO[step] || { icon:'•', label: step.toUpperCase(), spin: false };
        // For tool_step: only spin when status is 'running'
        if (step === 'tool_step') {
            var _st = log.status || 'running';
            info = { icon: info.icon, label: info.label, spin: (_st === 'running') };
        }
        var isFail = (step === 'tool_result' && log.success === false) ||
                     (step === 'error');
        var ms     = log.duration_ms ? Math.round(log.duration_ms) + 'ms' : '';
        var ts     = (log.timestamp || '').slice(11, 19); // HH:MM:SS

        var div = document.createElement('div');
        div.className = 'bwp-entry step-' + step + (isFail ? ' fail' : '');
        div.dataset.micro = String(log.microtime || 0);

        /* Header row */
        var hd = '<div class="bwp-entry-hd">';
        if (info.spin) hd += '<span class="bwp-running"></span>';
        else           hd += '<span class="bwp-status-icon">' + info.icon + '</span>';
        hd += '<span class="bwp-step-badge">' + _esc(info.label) + '</span>';

        /* Tool name / goal / message */
        var name = log.tool_name || log.goal_id || log.error_type || log.step_name || log.template || '';
        if (name) hd += '<span class="bwp-tool-name">' + _esc(name) + '</span>';

        if (ms)  hd += '<span class="bwp-ms-badge">' + _esc(ms) + '</span>';
        if (ts)  hd += '<span class="bwp-ts">' + _esc(ts) + '</span>';
        hd += '</div>';

        /* Detail row */
        var detail = '';
        if (step === 'tool_invoke') {
            var params = log.params;
            if (params && typeof params === 'object') {
                var keys = Object.keys(params).slice(0, 4);
                detail = keys.map(function(k){
                    var v = params[k];
                    if (typeof v === 'string') v = v.substring(0, 60) + (v.length > 60 ? '…' : '');
                    return '<b>' + _esc(k) + '</b>: ' + _esc(String(v));
                }).join('<br>');
            }
        } else if (step === 'tool_result') {
            var msg = log.message || '';
            if (msg) detail = '<span class="' + (isFail ? 'error' : 'success') + '">' + _esc(msg.substring(0, 120)) + '</span>';
            if (log.data_type) detail += (detail ? ' · ' : '') + '<span class="info">type: ' + _esc(log.data_type) + '</span>';
            if (log.data_id)   detail += ' <span class="info">id: ' + _esc(String(log.data_id)) + '</span>';
        } else if (step === 'pipeline_start') {
            detail = 'Template: <b>' + _esc(log.template || '') + '</b> · ' + (log.step_count || 0) + ' steps';
        } else if (step === 'pipeline_step') {
            detail = 'Step ' + ((log.step_index !== undefined) ? (log.step_index + 1) : '?') + ': ' + _esc(log.step_name || '');
            if (log.status) detail += ' <span class="info">(' + _esc(log.status) + ')</span>';
        } else if (step === 'pipeline_complete') {
            var pcStatus = log.status || '';
            detail = '<span class="' + (pcStatus === 'success' ? 'success' : 'error') + '">' + _esc(pcStatus.toUpperCase()) + '</span>';
            if (log.duration_ms) detail += ' in ' + Math.round(log.duration_ms) + 'ms';
        } else if (step === 'error') {
            var errCtx = log.context || {};
            detail = '<span class="error">' + _esc(log.message || '') + '</span>';
            if (errCtx.tool || errCtx.step) detail += '<br><span class="info">at: ' + _esc(errCtx.tool || errCtx.step || '') + '</span>';
        } else if (step === 'goal_update') {
            detail = 'Goal: <b>' + _esc(log.goal_id || '') + '</b> → ' + _esc(log.status || '');
            if (log.missing_info && log.missing_info.length) detail += ' · missing: ' + _esc(log.missing_info.join(', '));
        } else if (step === 'tool_step') {
            var subStep = log.sub_step || '';
            var stStatus = log.status || 'running';
            if (subStep) detail = '<b>' + _esc(subStep) + '</b>';
            if (stStatus) {
                var stCls = stStatus === 'success' ? 'success' : (stStatus === 'error' ? 'error' : 'info');
                detail += (detail ? ' → ' : '') + '<span class="' + stCls + '">' + _esc(stStatus) + '</span>';
            }
            // Extra info
            if (log.title) detail += '<br>📝 ' + _esc(String(log.title).substring(0, 70));
            if (log.content_len) detail += ' (' + log.content_len + ' chars)';
            if (log.post_id) detail += ' · post#' + log.post_id;
            if (log.message && stStatus !== 'success') {
                detail += '<br><span class="error">' + _esc(String(log.message).substring(0, 100)) + '</span>';
            }
            // Apply status class to entry
            if (stStatus === 'success') div.classList.add('success');
            if (stStatus === 'error')   div.classList.add('error');
        } else if (step === 'slot_resolve') {
            var found = log.found !== false;
            detail = _esc(log.expression || '') + ' → ';
            if (found) {
                var rv = String(log.resolved || '');
                detail += '<span class="success">' + _esc(rv.substring(0, 80)) + '</span>';
            } else {
                detail += '<span class="error">NOT FOUND</span>';
            }
        }

        div.innerHTML = hd + (detail ? '<div class="bwp-entry-detail">' + detail + '</div>' : '');
        return div;
    }

    function _esc(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Set active/idle state ── */
    function _setActive() {
        if (!state.active) {
            state.active = true;
            wrap.classList.add('bwp-active');
            iconEl.classList.add('spin');
            iconEl.textContent = '⚙️';
            _setPollInterval(ACTIVE_INTERVAL);
            // On mobile: auto-expand when task starts
            if (isMobile() && !state.inlineMode) wrap.classList.add('bwp-mobile-expanded');
        }
        _resetAutoHide();
        _updateInlineHeader();
    }
    function _setIdle() {
        state.active = false;
        wrap.classList.remove('bwp-active');
        iconEl.classList.remove('spin');
        iconEl.textContent = state.errorCount > 0 ? '⚠️' : '✅';
        _setPollInterval(IDLE_INTERVAL);
        _updateInlineHeader();
        // On mobile: auto-collapse after task done
        if (isMobile() && !state.inlineMode) {
            setTimeout(function() {
                wrap.classList.remove('bwp-mobile-expanded');
            }, 4000); // Keep expanded 4s so user can read the result
        }
    }
    function _resetAutoHide() {
        if (state.autoHideTimer) clearTimeout(state.autoHideTimer);
        state.autoHideTimer = setTimeout(function() {
            _setIdle();
            // Don't auto-hide if there are errors — keep visible
            if (state.errorCount === 0) {
                // Subtle fade — just go back to idle polling
            }
        }, AUTO_HIDE_AFTER);
    }

    /* ── Polling ── */
    function _setPollInterval(ms) {
        if (state.pollInterval === ms) return;
        state.pollInterval = ms;
        _stopPoll();
        _startPoll();
    }
    function _startPoll() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(_poll, state.pollInterval);
    }
    function _stopPoll() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    function _poll() {
        _syncSession();
        var data = {
            action:     'bizcity_poll_execution_log',
            nonce:      NONCE,
            session_id: state.sessionId,
        };
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status !== 200) return;
            try {
                var r = JSON.parse(xhr.responseText);
                if (!r.success) return;
                _processLogs(r.data.logs || [], r.data.stats || {});
            } catch(e) { /* JSON parse error — silent */ }
        };
        xhr.send(_buildParams(data));
    }
    function _buildParams(obj) {
        return Object.keys(obj).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k] || '');
        }).join('&');
    }


    function _processLogs(logs, stats) {
        if (!logs || !logs.length) return;

        // logs are newest-first — find entries newer than lastMicro
        var newEntries = [];
        for (var i = 0; i < logs.length; i++) {
            var t = parseFloat(logs[i].microtime || 0);
            if (t > state.lastMicro) {
                newEntries.push(logs[i]);
                if (t > state.lastMicro) state.lastMicro = t;
            }
        }

        if (!newEntries.length) return; // Nothing new

        // We have new execution entries → activate panel
        _setActive();
        if (!state.inlineMode && !state.visible && !state.minimized) {
            show();
        }

        // Determine render target (inline or floating panel)
        var targetLogList = _getLogTarget();

        // Clear "empty" placeholder
        var emptyEl = targetLogList.querySelector('.bwp-empty');
        if (emptyEl) emptyEl.remove();

        // Insert new entries at top (newest first matches the data order)
        // newEntries are in newest-first order (matching the API)
        var INLINE_MAX = 8; // Max entries in inline mode (keep compact)
        for (var j = 0; j < newEntries.length; j++) {
            var log = newEntries[j];

            // Dedup tool_step sub_steps: replace old "running" entry when success/error arrives
            if (log.step === 'tool_step' && log.sub_step) {
                var _key = (log.tool_name || '') + '|' + log.sub_step;
                var _oldMicro = state.subStepKeys[_key];
                if (_oldMicro) {
                    var _oldEl = targetLogList.querySelector('[data-micro="' + _oldMicro + '"]');
                    if (_oldEl) _oldEl.remove();
                }
                state.subStepKeys[_key] = String(log.microtime || 0);
            }

            var el  = _renderEntry(log);
            targetLogList.insertBefore(el, targetLogList.firstChild);
            state.entries.push(log.microtime);
            state.allLogs.unshift(log);

            // Count stats
            if (log.step === 'tool_invoke') state.toolCount++;
            if (log.step === 'tool_result') {
                if (log.success === true)  state.okCount++;
                if (log.success === false) state.errorCount++;
            }
            if (log.step === 'error') state.errorCount++;
        }

        // Trim old entries
        var maxEntries = state.inlineMode ? INLINE_MAX : MAX_ENTRIES;
        while (targetLogList.children.length > maxEntries) {
            targetLogList.removeChild(targetLogList.lastChild);
        }

        // Check if pipeline completed — slow down poll
        var hasPipelineComplete = newEntries.some(function(l) {
            return l.step === 'pipeline_complete' || l.step === 'tool_result';
        });
        if (hasPipelineComplete) {
            // Slow down after pipeline done
            setTimeout(_setIdle, 3000);
        }

        // Update title + badge
        _updateStats(stats);

        // Update inline header if in inline mode
        _updateInlineHeader();

        // Scroll chat to keep inline panel visible
        if (state.inlineMode) _inlineScrollChat();

        // Update FAB badge if minimized
        if (state.minimized && state.errorCount > 0) {
            fabBadge.textContent = state.errorCount;
        }
    }

    function _updateStats(apiStats) {
        /* Use our counted values for accuracy, fall back to API stats */
        var tools = state.toolCount  || (apiStats.tools_invoked || 0);
        var ok    = state.okCount    || (apiStats.tools_succeeded || 0);
        var err   = state.errorCount || (apiStats.errors || 0);

        if (tools > 0 || err > 0) {
            statsEl.style.display = 'flex';
            statTools.textContent = tools + ' tools';
            statOk.textContent    = ok + ' ok';
            statErr.textContent   = err + ' err';
            statMs.textContent    = (apiStats.duration_ms ? Math.round(apiStats.duration_ms) + 'ms total' : '');
        }

        if (err > 0) {
            badgeEl.textContent   = err;
            badgeEl.style.display = 'inline-block';
            badgeEl.classList.remove('ok');
            titleEl.textContent   = 'Working Panel ⚠️';
        } else if (tools > 0) {
            badgeEl.textContent   = tools;
            badgeEl.style.display = 'inline-block';
            badgeEl.classList.add('ok');
            titleEl.textContent   = 'Working Panel';
        }
    }

    /* ═══════════════════════════════════════════════════
       INLINE MODE — Renders execution trace inside chat typing indicator
       Instead of floating panel, shows compact logs below typing dots
       ═══════════════════════════════════════════════════ */

    function _activateInlineMode(inlineExecId) {
        var container = document.getElementById(inlineExecId);
        if (!container) return false;

        state.inlineMode = true;
        state.inlineContainer = container;

        // Build inline DOM structure
        container.innerHTML =
            '<div class="bizc-inline-exec-header" id="' + inlineExecId + '-hd">' +
            '<span class="bwp-icon spin" id="' + inlineExecId + '-icon">⚙️</span>' +
            '<span>Đang xử lý...</span>' +
            '<span class="bizc-inline-exec-badge" id="' + inlineExecId + '-badge" style="display:none;"></span>' +
            '</div>' +
            '<div class="bizc-inline-exec-trace" id="' + inlineExecId + '-trace" style="display:none;"></div>' +
            '<div class="bizc-inline-exec-logs" id="' + inlineExecId + '-logs"></div>';

        state.inlineHeaderEl = document.getElementById(inlineExecId + '-hd');
        state.inlineIconEl = document.getElementById(inlineExecId + '-icon');
        state.inlineBadgeEl = document.getElementById(inlineExecId + '-badge');
        state.inlineLogEl = document.getElementById(inlineExecId + '-logs');

        // Show the inline container
        container.classList.add('active');

        // Click header to toggle log visibility
        state.inlineHeaderEl.addEventListener('click', function() {
            var logs = state.inlineLogEl;
            if (logs) logs.style.display = logs.style.display === 'none' ? 'flex' : 'none';
        });

        // Hide the floating panel — inline takes over
        hide();

        // Scroll chat to show the inline panel
        _inlineScrollChat();

        return true;
    }

    function _deactivateInlineMode() {
        state.inlineMode = false;
        state.inlineContainer = null;
        state.inlineLogEl = null;
        state.inlineHeaderEl = null;
        state.inlineBadgeEl = null;
        state.inlineIconEl = null;
    }

    function _inlineScrollChat() {
        // Scroll the chat messages container to bottom
        if (state.inlineContainer) {
            var msgsContainer = state.inlineContainer.closest('.bizc-messages') ||
                                state.inlineContainer.closest('#bizc-messages');
            if (msgsContainer) {
                msgsContainer.scrollTop = msgsContainer.scrollHeight;
            }
        }
    }

    /** Check if inline container is still in DOM (typing indicator wasn't removed yet) */
    function _isInlineAlive() {
        if (!state.inlineMode || !state.inlineContainer) return false;
        // Check if the element is still connected to the document
        return document.body.contains(state.inlineContainer);
    }

    /** Get the active log target — inline log element if in inline mode, else floating panel logList */
    function _getLogTarget() {
        if (state.inlineMode && state.inlineLogEl) {
            if (_isInlineAlive()) return state.inlineLogEl;
            // Inline container was removed from DOM — deactivate
            _deactivateInlineMode();
        }
        return logList;
    }

    /** Update inline header with current stats */
    function _updateInlineHeader() {
        if (!state.inlineMode || !state.inlineHeaderEl) return;

        var icon = state.inlineIconEl;
        var badge = state.inlineBadgeEl;

        if (state.active) {
            if (icon) { icon.classList.add('spin'); icon.textContent = '⚙️'; }
        } else {
            if (icon) {
                icon.classList.remove('spin');
                icon.textContent = state.errorCount > 0 ? '⚠️' : '✅';
            }
        }

        if (badge) {
            var totalActions = state.toolCount + state.okCount;
            if (state.errorCount > 0) {
                badge.textContent = state.errorCount + ' err';
                badge.classList.add('has-errors');
                badge.style.display = 'inline-block';
            } else if (totalActions > 0) {
                badge.textContent = state.toolCount + ' tools · ' + state.okCount + ' ok';
                badge.classList.remove('has-errors');
                badge.style.display = 'inline-block';
            }
        }
    }

    /* ── External event triggers ── */

    // When chat dashboard sends a message → immediately start active polling
    window.addEventListener('bizcityTaskStarted', function(e) {
        // Update session + conv from event if provided
        if (e.detail) {
            if (e.detail.sessionId) state.sessionId = e.detail.sessionId;
            if (e.detail.convId)   state.convId    = e.detail.convId;
        }
        _syncSession();
        _setActive();

        // Try to activate inline mode (renders inside chat typing indicator)
        var inlineActivated = false;
        if (e.detail && e.detail.inlineExecId) {
            inlineActivated = _activateInlineMode(e.detail.inlineExecId);
        }

        // Only show floating panel if inline mode didn't activate
        if (!inlineActivated) {
            show();
        }

        // Poll immediately
        _poll();
    });

    // When a task completes → switch to idle after short cooldown
    window.addEventListener('bizcityTaskCompleted', function() {
        setTimeout(_setIdle, 2000);
        // Deactivate inline mode — the typing indicator will be removed by chat dashboard
        if (state.inlineMode) {
            // Update header to show completion before it gets removed
            _updateInlineHeader();
            setTimeout(function() { _deactivateInlineMode(); }, 1000);
        }
    });

    // Sync session when dashboard updates it
    window.addEventListener('bizcitySessionChanged', function(e) {
        if (e.detail) {
            if (e.detail.sessionId) {
                state.sessionId = e.detail.sessionId;
                state.lastMicro = 0;
            }
            if (e.detail.convId) {
                state.convId = e.detail.convId;
            }
        }
    });

    /* ── Start polling ── */
    _startPoll();

    /* ── Reset position helper ── */
    function resetPosition() {
        wrap.style.left = '24px';
        wrap.style.bottom = '24px';
        wrap.style.top = 'auto';
        wrap.style.right = 'auto';
        try { localStorage.removeItem('bwp_position'); } catch(e) {}
    }

    /* ── Double-click header to reset position ── */
    header.addEventListener('dblclick', function(e) {
        if (e.target.closest && e.target.closest('.bwp-ctrl-btn')) return;
        if (wrap.classList.contains('bwp-expanded')) return;
        resetPosition();
    });

    /* ── Expose for external use ── */
    window.BizCityWorkingPanel = {
        show:    show,
        hide:    hide,
        poll:    _poll,
        setSession: function(sid, convId) {
            state.sessionId = sid;
            state.lastMicro = 0;
            if (convId) { state.convId = convId; }
        },
        setConv: function(convId) {
            state.convId = convId;
        },
        trigger: function(sessionId, convId) {
            if (sessionId) state.sessionId = sessionId;
            if (convId)    state.convId    = convId;
            _setActive(); show(); _poll();
        },
        resetPosition: resetPosition,
    };

})();
</script>
<?php
    }
}

// DEPRECATED v4.9.3 — old floating bwp-wrap panel, replaced by React bizc-working-panel
BizCity_Working_Panel::instance();
