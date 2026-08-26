<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Working Panel — Context Layers Tab
 *
 * Adds a "🧠 Context" observability panel for super admins (manage_network).
 * Renders context layer snapshots captured by BizCity_Context_Layers_Capture.
 * Polls via AJAX, click-to-inspect detail dialog, per-session persistence.
 *
 * Phase 1.6 Sprint 2 — Working Panel Context Layer Observability
 * 100% prompt phải log đầy đủ context layers, hiện realtime cho super admin.
 *
 * @since   Phase 1.6 v2.0
 * @package BizCity_Twin_AI
 * @see     PHASE-1.6-MEMORY-SPEC-ARCHITECTURE.md §14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Working_Panel_Context {

	/** @var self|null */
	private static $instance = null;

	const NONCE_ACTION = 'bizcity_context_layers';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// AJAX endpoint for polling context layers
		add_action( 'wp_ajax_bizcity_poll_context_layers', array( $this, 'ajax_poll' ) );

		// Render panel in footer (admin + frontend)
		if ( is_admin() ) {
			add_action( 'admin_footer', array( $this, 'render' ), 98 );
		} else {
			add_action( 'wp_footer', array( $this, 'render' ), 98 );
		}
	}

	/**
	 * Permission check — super admin only (manage_network).
	 *
	 * @return bool
	 */
	private function can_view() {
		return current_user_can( 'manage_network' );
	}

	/**
	 * AJAX: Poll context layers for a session.
	 */
	public function ajax_poll() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! $this->can_view() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
			return;
		}

		$session_id = sanitize_text_field( isset( $_POST['session_id'] ) ? $_POST['session_id'] : '' );

		// §20 L5 fix: Removed dead "live" snapshot code path.
		// Static $snapshot is per-request; AJAX poll is a separate request → always empty.
		// Always read from DB (last persisted snapshot).

		// Read from DB (last persisted snapshot)
		if ( ! empty( $session_id ) && class_exists( 'BizCity_WebChat_Database' ) ) {
			$db  = BizCity_WebChat_Database::instance();
			$row = $db->get_session_v3_by_session_id( $session_id );
			if ( $row && ! empty( $row->context_layers_snapshot ) ) {
				$snapshot = json_decode( $row->context_layers_snapshot, true );
				if ( is_array( $snapshot ) ) {
					// Also include session memory spec for full observability
					$spec_data = array();
					if ( ! empty( $row->session_memory_spec ) ) {
						$spec_data = json_decode( $row->session_memory_spec, true );
					}

					wp_send_json_success( array(
						'source'        => 'db',
						'snapshot'      => $snapshot,
						'session_spec'  => is_array( $spec_data ) ? $spec_data : array(),
						'session_mode'  => isset( $row->session_memory_mode ) ? $row->session_memory_mode : 'off',
						'focus_summary' => isset( $row->session_focus_summary ) ? $row->session_focus_summary : '',
					) );
					return;
				}
			}
		}

		wp_send_json_success( array(
			'source'   => 'empty',
			'snapshot' => array( 'layers' => array(), 'total_tokens_est' => 0 ),
		) );
	}

	/**
	 * Render the Context Layers panel HTML + CSS + JS.
	 */
	public function render() {
		if ( ! $this->can_view() ) {
			return;
		}

		// Check feature flag
		if ( ! defined( 'BIZCITY_SESSION_SPEC_ENABLED' ) || ! BIZCITY_SESSION_SPEC_ENABLED ) {
			return;
		}

		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );

		?>
<!-- BizCity Context Layers Panel (Phase 1.6) -->
<div id="bctx-panel" class="bctx-panel" style="display:none;">
	<div class="bctx-header" id="bctx-header">
		<span class="bctx-icon">🧠</span>
		<span class="bctx-title">Context Layers</span>
		<span class="bctx-badge" id="bctx-badge" style="display:none;"></span>
		<span class="bctx-mode" id="bctx-mode"></span>
		<div class="bctx-controls">
			<button class="bctx-btn" id="bctx-refresh" title="Refresh">↻</button>
			<button class="bctx-btn" id="bctx-expand" title="Expand">⛶</button>
			<button class="bctx-btn" id="bctx-close" title="Close">✕</button>
		</div>
	</div>
	<div class="bctx-body" id="bctx-body">
		<!-- Session Spec Summary -->
		<div class="bctx-section" id="bctx-spec-section">
			<div class="bctx-section-title">SESSION SPEC</div>
			<div id="bctx-spec-content" class="bctx-spec-content">
				<span class="bctx-empty">No session spec yet</span>
			</div>
		</div>
		<!-- Context Layers List -->
		<div class="bctx-section">
			<div class="bctx-section-title">LAYERS <span id="bctx-token-total" class="bctx-token-badge"></span></div>
			<div id="bctx-layers-list" class="bctx-layers-list">
				<span class="bctx-empty">Send a message to capture layers...</span>
			</div>
		</div>
	</div>
	<!-- Detail Dialog -->
	<div class="bctx-dialog" id="bctx-dialog" style="display:none;">
		<div class="bctx-dialog-header">
			<span id="bctx-dialog-title">Layer Detail</span>
			<div class="bctx-dialog-controls">
				<button class="bctx-btn" id="bctx-dialog-copy" title="Copy JSON">📋</button>
				<button class="bctx-btn" id="bctx-dialog-toggle" title="Toggle content/meta">📄</button>
				<button class="bctx-btn" id="bctx-dialog-close">✕</button>
			</div>
		</div>
		<div class="bctx-dialog-tabs">
			<button class="bctx-tab active" data-tab="content">Content</button>
			<button class="bctx-tab" data-tab="meta">Meta JSON</button>
		</div>
		<div class="bctx-dialog-body-wrap">
			<pre class="bctx-dialog-body" id="bctx-dialog-body"></pre>
		</div>
	</div>
</div>

<!-- FAB to toggle Context Panel -->
<button id="bctx-fab" class="bctx-fab" title="🧠 Context Layers">🧠</button>

<style id="bctx-styles">
/* ═══════════════════════════════════════════
   BizCity Context Layers Panel — Phase 1.6
   ═══════════════════════════════════════════ */
.bctx-panel {
	position: fixed;
	bottom: 24px;
	right: 24px;
	width: 380px;
	max-height: 520px;
	background: #1a1b26;
	color: #a9b1d6;
	border-radius: 12px;
	font-family: 'JetBrains Mono', Consolas, monospace;
	font-size: 11px;
	z-index: 99991;
	box-shadow: 0 8px 32px rgba(0,0,0,.5), 0 0 0 1px rgba(122,162,247,.2);
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.bctx-panel.expanded {
	width: 600px;
	max-height: 80vh;
	bottom: 10%;
	right: 10%;
}
.bctx-header {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 10px;
	background: #24283b;
	border-radius: 12px 12px 0 0;
	cursor: grab;
	user-select: none;
}
.bctx-icon { font-size: 14px; }
.bctx-title { flex:1; font-weight:700; color:#c0caf5; white-space:nowrap; }
.bctx-badge { background:#bb9af7; color:#1a1b26; font-size:9px; font-weight:800; padding:1px 5px; border-radius:4px; }
.bctx-mode { font-size:9px; padding:1px 6px; border-radius:3px; font-weight:700; text-transform:uppercase; }
.bctx-mode.chat { background:#1a3a2a; color:#9ece6a; }
.bctx-mode.goal { background:#3a2a1a; color:#e0af68; }
.bctx-mode.pipeline { background:#1e3a5f; color:#7aa2f7; }
.bctx-controls { display:flex; gap:3px; }
.bctx-btn { background:#414868; border:none; color:#a9b1d6; padding:2px 7px; border-radius:4px; cursor:pointer; font-size:10px; }
.bctx-btn:hover { background:#565f89; color:#c0caf5; }
.bctx-body { flex:1; overflow-y:auto; padding:6px 8px; }
.bctx-body::-webkit-scrollbar { width:4px; }
.bctx-body::-webkit-scrollbar-thumb { background:#414868; border-radius:4px; }
.bctx-section { margin-bottom:8px; }
.bctx-section-title { font-size:9px; font-weight:800; color:#565f89; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px; }
.bctx-token-badge { font-size:9px; color:#bb9af7; font-weight:600; }
.bctx-spec-content { padding:4px 6px; background:#24283b; border-radius:6px; font-size:10px; line-height:1.5; }
.bctx-spec-content .spec-field { color:#565f89; }
.bctx-spec-content .spec-value { color:#c0caf5; }
.bctx-empty { color:#414868; font-style:italic; }

/* Layer cards */
.bctx-layer {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 5px 6px;
	border-radius: 5px;
	border-left: 3px solid #414868;
	margin-bottom: 2px;
	cursor: pointer;
	transition: background .1s;
}
.bctx-layer:hover { background: rgba(122,162,247,.08); }
.bctx-layer.profile   { border-left-color: #9ece6a; }
.bctx-layer.transit    { border-left-color: #e0af68; }
.bctx-layer.session_spec, .bctx-layer.session_base { border-left-color: #bb9af7; }
.bctx-layer.task_spec  { border-left-color: #7aa2f7; }
.bctx-layer.knowledge  { border-left-color: #7dcfff; }
.bctx-layer.skill      { border-left-color: #73daca; }
.bctx-layer.focus_gate { border-left-color: #f7768e; }
.bctx-layer.system_base { border-left-color: #414868; }
.bctx-layer-name { flex:1; font-weight:600; color:#c0caf5; font-size:10px; }
.bctx-layer-tokens { font-size:9px; color:#565f89; }
.bctx-layer-chars { font-size:9px; color:#414868; }
.bctx-layer-gated { font-size:8px; color:#f7768e; margin-left:2px; }

/* Detail dialog */
.bctx-dialog {
	position: absolute;
	inset: 0;
	background: #1a1b26;
	z-index: 2;
	display: flex;
	flex-direction: column;
}
.bctx-dialog-header {
	display: flex;
	align-items: center;
	padding: 8px 10px;
	background: #24283b;
	font-weight: 700;
	color: #c0caf5;
}
.bctx-dialog-header span { flex:1; }
.bctx-dialog-controls { display:flex; gap:3px; }
.bctx-dialog-tabs { display:flex; gap:0; border-bottom:1px solid #414868; }
.bctx-tab { background:none; border:none; color:#565f89; padding:5px 12px; font-size:10px; font-weight:700; cursor:pointer; border-bottom:2px solid transparent; }
.bctx-tab.active { color:#7aa2f7; border-bottom-color:#7aa2f7; }
.bctx-tab:hover { color:#a9b1d6; }
.bctx-dialog-body-wrap { flex:1; overflow:auto; }
.bctx-dialog-body {
	padding: 8px;
	margin: 0;
	font-size: 10px;
	line-height: 1.5;
	color: #a9b1d6;
	white-space: pre-wrap;
	word-break: break-word;
}
.bctx-dialog-body .json-key { color: #7aa2f7; }
.bctx-dialog-body .json-str { color: #9ece6a; }
.bctx-dialog-body .json-num { color: #ff9e64; }
.bctx-dialog-body .json-bool { color: #bb9af7; }
.bctx-dialog-body .json-null { color: #565f89; }
.bctx-copy-toast { position:absolute; bottom:8px; right:8px; background:#9ece6a; color:#1a1b26; padding:3px 8px; border-radius:4px; font-size:9px; font-weight:700; opacity:0; transition:opacity .3s; }
.bctx-copy-toast.show { opacity:1; }

/* FAB button */
.bctx-fab {
	position: fixed;
	bottom: 24px;
	right: 24px;
	width: 40px;
	height: 40px;
	border-radius: 50%;
	background: #24283b;
	border: 1px solid rgba(122,162,247,.3);
	color: #c0caf5;
	font-size: 18px;
	cursor: pointer;
	z-index: 99990;
	box-shadow: 0 4px 16px rgba(0,0,0,.4);
	transition: transform .15s, box-shadow .15s;
	display: flex;
	align-items: center;
	justify-content: center;
}
.bctx-fab:hover { transform:scale(1.1); box-shadow:0 6px 20px rgba(0,0,0,.5); }

/* Mobile */
@media (max-width: 768px) {
	.bctx-panel { width:100%; right:0; bottom:0; border-radius:12px 12px 0 0; max-height:60vh; }
	.bctx-fab { bottom:80px; right:16px; }
}
</style>

<script id="bctx-script">
(function() {
	'use strict';
	var AJAX_URL   = <?php echo wp_json_encode( $ajax_url ); ?>;
	var NONCE      = <?php echo wp_json_encode( $nonce ); ?>;
	var POLL_MS      = 5000;
	var POLL_MAX     = 30000;
	var pollTimer    = null;
	var pollInterval = POLL_MS;
	var visible      = false;
	var expanded     = false;
	var lastLayers   = [];
	var lastLayersSig = '[]';

	var panel      = document.getElementById('bctx-panel');
	var fab        = document.getElementById('bctx-fab');
	var badge      = document.getElementById('bctx-badge');
	var modeEl     = document.getElementById('bctx-mode');
	var specEl     = document.getElementById('bctx-spec-content');
	var layersList = document.getElementById('bctx-layers-list');
	var tokenTotal = document.getElementById('bctx-token-total');
	var dialog     = document.getElementById('bctx-dialog');
	var dialogBody = document.getElementById('bctx-dialog-body');
	var dialogTitle= document.getElementById('bctx-dialog-title');

	/* ── Toggle panel ── */
	fab.addEventListener('click', function() {
		visible = !visible;
		panel.style.display = visible ? 'flex' : 'none';
		fab.style.display = visible ? 'none' : 'flex';
		if (visible) { poll(); startPoll(); }
		else { stopPoll(); }
	});

	document.getElementById('bctx-close').addEventListener('click', function() {
		visible = false;
		panel.style.display = 'none';
		fab.style.display = 'flex';
		stopPoll();
	});

	document.getElementById('bctx-expand').addEventListener('click', function() {
		expanded = !expanded;
		panel.classList.toggle('expanded', expanded);
	});

	document.getElementById('bctx-refresh').addEventListener('click', function() { poll(); });

	document.getElementById('bctx-dialog-close').addEventListener('click', function() {
		dialog.style.display = 'none';
	});

	/* ── Dialog tabs + copy ── */
	var currentTab = 'content';
	var currentLayer = null;

	var tabs = dialog.querySelectorAll('.bctx-tab');
	for (var t = 0; t < tabs.length; t++) {
		tabs[t].addEventListener('click', function() {
			for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');
			this.classList.add('active');
			currentTab = this.getAttribute('data-tab');
			renderDialog();
		});
	}

	document.getElementById('bctx-dialog-copy').addEventListener('click', function() {
		var text = dialogBody.textContent;
		navigator.clipboard.writeText(text).then(function() {
			var toast = document.createElement('div');
			toast.className = 'bctx-copy-toast show';
			toast.textContent = 'Copied!';
			dialog.style.position = 'relative';
			dialog.appendChild(toast);
			setTimeout(function() { toast.remove(); }, 1500);
		});
	});

	document.getElementById('bctx-dialog-toggle').addEventListener('click', function() {
		currentTab = (currentTab === 'content') ? 'meta' : 'content';
		for (var i = 0; i < tabs.length; i++) {
			tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === currentTab);
		}
		renderDialog();
	});

	/* ── Detect active session_id ── */
	function getSessionId() {
		// Try common DOM locations
		var el = document.querySelector('[data-session-id]');
		if (el) return el.getAttribute('data-session-id');
		// Try global JS var
		if (window.bizcitySessionId) return window.bizcitySessionId;
		if (window.bizc_chat_config && window.bizc_chat_config.session_id) return window.bizc_chat_config.session_id;
		return '';
	}

	/* ── Poll AJAX ── */
	function poll() {
		var fd = new FormData();
		fd.append('action', 'bizcity_poll_context_layers');
		fd.append('nonce', NONCE);
		fd.append('session_id', getSessionId());

		fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res.success) renderData(res.data);
			})
			.catch(function() {});
	}

	// §20 D4 fix: Exponential backoff — double interval when unchanged, reset on new data
	function startPoll() { stopPoll(); pollInterval = POLL_MS; schedulePoll(); }
	function schedulePoll() { pollTimer = setTimeout(function(){ poll(); schedulePoll(); }, pollInterval); }
	function stopPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } pollInterval = POLL_MS; }

	/* ── Render ── */
	function renderData(data) {
		var snapshot = data.snapshot || {};
		var layers   = snapshot.layers || [];
		var spec     = data.session_spec || {};
		var mode     = data.session_mode || 'off';
		var focus    = data.focus_summary || '';

		// §20 D4: Backoff — if layers unchanged, double poll interval; else reset
		var sig = JSON.stringify(layers);
		if (sig === lastLayersSig) {
			pollInterval = Math.min(pollInterval * 2, POLL_MAX);
		} else {
			pollInterval = POLL_MS;
		}
		lastLayersSig = sig;

		// Mode badge
		modeEl.textContent = mode;
		modeEl.className = 'bctx-mode ' + mode;

		// Session spec summary
		if (spec && spec.version) {
			var html = '';
			if (spec.current_topic) html += '<span class="spec-field">topic:</span> <span class="spec-value">' + esc(spec.current_topic) + '</span><br>';
			if (spec.current_focus) html += '<span class="spec-field">focus:</span> <span class="spec-value">' + esc(spec.current_focus) + '</span><br>';
			if (spec.mode) html += '<span class="spec-field">mode:</span> <span class="spec-value">' + esc(spec.mode) + '</span><br>';
			if (spec.recent_facts && spec.recent_facts.length) {
				html += '<span class="spec-field">facts:</span> ';
				for (var i = 0; i < spec.recent_facts.length; i++) {
					html += '<span class="spec-value">• ' + esc(spec.recent_facts[i]) + '</span><br>';
				}
			}
			if (spec.open_loops && spec.open_loops.length) {
				html += '<span class="spec-field">open_loops:</span> ';
				for (var j = 0; j < spec.open_loops.length; j++) {
					html += '<span class="spec-value">• ' + esc(spec.open_loops[j]) + '</span><br>';
				}
			}
			if (spec.updated_at) html += '<span class="spec-field">updated:</span> <span class="spec-value">' + esc(spec.updated_at) + '</span>';
			specEl.innerHTML = html || '<span class="bctx-empty">Empty spec</span>';
		} else {
			specEl.innerHTML = '<span class="bctx-empty">No session spec yet</span>';
		}

		// Token total
		var totalTokens = snapshot.total_tokens_est || 0;
		var finalTokens = snapshot.final_prompt_tokens_est || 0;
		tokenTotal.textContent = totalTokens + ' tok (layers) / ' + finalTokens + ' tok (final)';

		// Layers badge
		if (layers.length > 0) {
			badge.textContent = layers.length;
			badge.style.display = 'inline-block';
		} else {
			badge.style.display = 'none';
		}

		// Render layers
		if (layers.length === 0) {
			layersList.innerHTML = '<span class="bctx-empty">No layers captured yet</span>';
			return;
		}

		lastLayers = layers;
		var html2 = '';
		for (var k = 0; k < layers.length; k++) {
			var l = layers[k];
			var name = l.name || 'unknown';
			var cls  = name.replace(/[^a-z_]/g, '');
			html2 += '<div class="bctx-layer ' + cls + '" data-idx="' + k + '">';
			html2 += '<span class="bctx-layer-name">' + esc(name) + '</span>';
			html2 += '<span class="bctx-layer-tokens">~' + (l.tokens_est || 0) + ' tok</span>';
			html2 += '<span class="bctx-layer-chars">' + (l.chars || 0) + ' ch</span>';
			if (l.gated) html2 += '<span class="bctx-layer-gated">🔒 ' + esc(l.gated) + '</span>';
			html2 += '</div>';
		}
		layersList.innerHTML = html2;

		// Click to detail
		var cards = layersList.querySelectorAll('.bctx-layer');
		for (var m = 0; m < cards.length; m++) {
			cards[m].addEventListener('click', function() {
				var idx = parseInt(this.getAttribute('data-idx'), 10);
				showDetail(idx);
			});
		}
	}

	function showDetail(idx) {
		var layer = lastLayers[idx];
		if (!layer) return;
		currentLayer = layer;
		currentTab = 'content';
		// Reset tab active state
		var tabs2 = dialog.querySelectorAll('.bctx-tab');
		for (var i = 0; i < tabs2.length; i++) {
			tabs2[i].classList.toggle('active', tabs2[i].getAttribute('data-tab') === 'content');
		}
		dialogTitle.textContent = (layer.name || 'Layer') + ' — ' + (layer.chars || 0) + ' chars, ~' + (layer.tokens_est || 0) + ' tokens';
		renderDialog();
		dialog.style.display = 'flex';
	}

	function renderDialog() {
		if (!currentLayer) return;
		if (currentTab === 'content') {
			// Show full content text (or preview if full not available)
			var fullText = currentLayer.content || currentLayer.preview || '(no content captured)';
			dialogBody.innerHTML = '';
			dialogBody.textContent = fullText;
		} else {
			// Show full JSON metadata with syntax highlighting
			var meta = {
				name: currentLayer.name,
				priority: currentLayer.priority,
				source: currentLayer.source,
				gated: currentLayer.gated || null,
				chars: currentLayer.chars,
				tokens_est: currentLayer.tokens_est,
				preview: currentLayer.preview
			};
			dialogBody.innerHTML = syntaxHighlight(JSON.stringify(meta, null, 2));
		}
	}

	function syntaxHighlight(json) {
		json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(match) {
			var cls = 'json-num';
			if (/^"/.test(match)) {
				if (/:$/.test(match)) {
					cls = 'json-key';
				} else {
					cls = 'json-str';
				}
			} else if (/true|false/.test(match)) {
				cls = 'json-bool';
			} else if (/null/.test(match)) {
				cls = 'json-null';
			}
			return '<span class="' + cls + '">' + match + '</span>';
		});
	}

	function esc(s) {
		if (!s) return '';
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(s));
		return d.innerHTML;
	}
})();
</script>
<?php
	}
}

// Auto-init
BizCity_Working_Panel_Context::instance();
