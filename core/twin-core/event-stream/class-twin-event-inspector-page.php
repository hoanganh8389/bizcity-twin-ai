<?php
/**
 * BizCity Twin AI — Event Stream Inspector (Admin page)
 *
 * Phase 0.12 Wave F — read-only debug surface for `bizcity_twin_event_stream`.
 *
 * Self-contained: vanilla JS + fetch() to the REST endpoints registered by
 * BizCity_Twin_Event_Stream_REST. No bundler / no React build pipeline.
 *
 * Layout: 3 columns
 *   ┌─ left (28%) ──┬─ center (40%) ──┬─ right (32%) ─┐
 *   │ Recent traces │ Event timeline   │ Payload JSON  │
 *   └───────────────┴──────────────────┴───────────────┘
 *
 * @package BizCity_Twin_AI
 * @since   2026-04-29 (Phase 0.12 Wave F)
 */

defined( 'ABSPATH' ) or die( 'Direct access denied.' );

if ( ! class_exists( 'BizCity_Twin_Event_Inspector_Page' ) ) :

class BizCity_Twin_Event_Inspector_Page {

	const SLUG = 'bizcity-twin-event-inspector';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 12 );
	}

	public static function register_menu(): void {
		// Sit under Intent Monitor when present, fallback to top-level.
		$parent = class_exists( 'BizCity_Intent_Monitor', false )
			? 'bizcity-intent-monitor'
			: null;
		if ( $parent ) {
			add_submenu_page(
				$parent,
				'Twin Event Inspector',
				'Event Inspector',
				'manage_options',
				self::SLUG,
				[ __CLASS__, 'render' ]
			);
		} else {
			add_menu_page(
				'Twin Event Inspector',
				'Event Inspector',
				'manage_options',
				self::SLUG,
				[ __CLASS__, 'render' ],
				'dashicons-chart-line',
				73
			);
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$rest_root = esc_js( rest_url( 'bizcity-twin/v1/' ) );
		$nonce     = esc_js( wp_create_nonce( 'wp_rest' ) );
		$preselect = isset( $_GET['trace_id'] ) ? esc_js( sanitize_text_field( wp_unslash( $_GET['trace_id'] ) ) ) : '';

		?>
		<div class="wrap" id="twin-event-inspector-root">
			<h1 style="display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-chart-line" style="font-size:24px;"></span>
				Twin Event Inspector
				<span style="font-size:12px;color:#666;font-weight:normal;">
					Phase 0.12 — read-only view of <code>bizcity_twin_event_stream</code>
				</span>
			</h1>

			<div id="tei-toolbar" style="display:flex;gap:8px;align-items:center;margin:12px 0;flex-wrap:wrap;">
				<label>
					Trace ID:
					<input type="text" id="tei-trace-input" style="width:340px;font-family:monospace;" placeholder="paste trace_id or pick from list" />
				</label>
				<button type="button" class="button button-primary" id="tei-load-btn">Load events</button>
				<button type="button" class="button" id="tei-refresh-traces">↻ Refresh trace list</button>
				<label>
					Filter type:
					<select id="tei-type-filter">
						<option value="">all</option>
					</select>
				</label>
				<label>
					Source:
					<select id="tei-source-filter">
						<option value="">all</option>
					</select>
				</label>
				<span id="tei-status" style="margin-left:auto;color:#666;font-size:12px;"></span>
			</div>

			<div style="display:grid;grid-template-columns:28% 40% 32%;gap:12px;height:calc(100vh - 220px);min-height:500px;">

				<!-- Left: recent traces -->
				<div style="border:1px solid #ccd0d4;background:#fff;display:flex;flex-direction:column;">
					<div style="padding:8px 10px;background:#f0f0f1;border-bottom:1px solid #ccd0d4;font-weight:600;">
						Recent traces <span id="tei-trace-count" style="color:#666;font-weight:normal;"></span>
					</div>
					<div id="tei-trace-list" style="flex:1;overflow:auto;font-size:12px;"></div>
				</div>

				<!-- Center: event timeline -->
				<div style="border:1px solid #ccd0d4;background:#fff;display:flex;flex-direction:column;">
					<div style="padding:8px 10px;background:#f0f0f1;border-bottom:1px solid #ccd0d4;font-weight:600;">
						Event timeline <span id="tei-event-count" style="color:#666;font-weight:normal;"></span>
					</div>
					<div id="tei-event-list" style="flex:1;overflow:auto;font-size:12px;"></div>
				</div>

				<!-- Right: detail -->
				<div style="border:1px solid #ccd0d4;background:#fff;display:flex;flex-direction:column;">
					<div style="padding:8px 10px;background:#f0f0f1;border-bottom:1px solid #ccd0d4;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
						<span>Payload detail</span>
						<button type="button" class="button button-small" id="tei-copy-json" disabled>Copy JSON</button>
					</div>
					<pre id="tei-detail" style="flex:1;overflow:auto;margin:0;padding:12px;font-size:11px;line-height:1.4;background:#fff;color:#1d2327;">Select an event to inspect.</pre>
				</div>

			</div>
		</div>

		<style>
			#tei-trace-list .tei-trace-row,
			#tei-event-list .tei-event-row {
				padding:6px 10px;border-bottom:1px solid #f0f0f0;cursor:pointer;
				display:flex;flex-direction:column;gap:2px;
			}
			#tei-trace-list .tei-trace-row:hover,
			#tei-event-list .tei-event-row:hover { background:#f6f7f7; }
			#tei-trace-list .tei-trace-row.active,
			#tei-event-list .tei-event-row.active { background:#e8f0fe;border-left:3px solid #2271b1; }
			.tei-pill {
				display:inline-block;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;
			}
			.tei-pill-ok    { background:#d4edda;color:#155724; }
			.tei-pill-err   { background:#f8d7da;color:#721c24; }
			.tei-pill-run   { background:#fff3cd;color:#856404; }
			.tei-pill-src-server   { background:#e7d6ff;color:#5a2ca0; }
			.tei-pill-src-twinchat { background:#d6e4ff;color:#1e3a8a; }
			.tei-pill-src-system   { background:#e2e8f0;color:#475569; }
			.tei-event-stage { font-family:monospace;color:#444;font-size:11px; }
			.tei-event-thinking { color:#1d2327;margin-top:2px; }
			.tei-mono { font-family:monospace; }
			.tei-dim  { color:#777; }
		</style>

		<script>
		(function () {
			const REST = "<?php echo $rest_root; ?>";
			const NONCE = "<?php echo $nonce; ?>";
			const PRESELECT = "<?php echo $preselect; ?>";

			const $ = (id) => document.getElementById(id);
			const setStatus = (msg) => { $('tei-status').textContent = msg || ''; };

			const fmtTime = (ms) => {
				if (!ms) return '';
				const d = new Date(ms);
				return d.toLocaleTimeString('vi-VN', { hour12: false }) + '.' + String(d.getMilliseconds()).padStart(3, '0');
			};
			const fmtDur = (ms) => {
				if (!ms || ms < 0) return '';
				if (ms < 1000) return ms + 'ms';
				return (ms / 1000).toFixed(2) + 's';
			};
			const escHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({
				'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
			}[c]));

			let currentEvents = [];
			let currentSelected = null;

			async function api(path) {
				const res = await fetch(REST + path, { headers: { 'X-WP-Nonce': NONCE } });
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.json();
			}

			async function loadTraces() {
				setStatus('Loading traces…');
				try {
					const data = await api('events/recent_traces?limit=50');
					renderTraces(data.traces || []);
					setStatus(data.count + ' traces');
				} catch (e) {
					setStatus('Error: ' + e.message);
				}
			}

			function renderTraces(traces) {
				$('tei-trace-count').textContent = '(' + traces.length + ')';
				const html = traces.map(t => {
					const pill = t.had_error ? '<span class="tei-pill tei-pill-err">error</span>'
						: t.has_complete ? '<span class="tei-pill tei-pill-ok">done</span>'
						: '<span class="tei-pill tei-pill-run">live</span>';
					return `<div class="tei-trace-row" data-trace="${escHtml(t.trace_id)}">
						<div style="display:flex;justify-content:space-between;gap:6px;">
							<span class="tei-mono" style="font-size:11px;">${escHtml(t.trace_id.substring(0, 32))}</span>
							${pill}
						</div>
						<div class="tei-dim" style="font-size:11px;">
							${t.event_count} events · ${fmtDur(t.duration_ms)} · user #${t.user_id}
						</div>
						<div class="tei-dim" style="font-size:10px;">${fmtTime(t.started_ms)}</div>
					</div>`;
				}).join('');
				$('tei-trace-list').innerHTML = html || '<div style="padding:20px;color:#777;">No traces yet.</div>';
				$('tei-trace-list').querySelectorAll('.tei-trace-row').forEach(el => {
					el.addEventListener('click', () => {
						const tid = el.getAttribute('data-trace');
						$('tei-trace-input').value = tid;
						$('tei-trace-list').querySelectorAll('.tei-trace-row').forEach(r => r.classList.remove('active'));
						el.classList.add('active');
						loadEvents(tid);
					});
				});
			}

			async function loadEvents(traceId) {
				if (!traceId) return;
				setStatus('Loading events for ' + traceId.substring(0, 16) + '…');
				try {
					const type = $('tei-type-filter').value;
					const source = $('tei-source-filter').value;
					const qs = new URLSearchParams({ trace_id: traceId, limit: '500' });
					if (type) qs.set('event_type', type);
					if (source) qs.set('event_source', source);
					const data = await api('events?' + qs.toString());
					currentEvents = data.events || [];
					renderEvents(currentEvents);
					rebuildFilters(currentEvents);
					setStatus(data.count + ' events loaded');
				} catch (e) {
					setStatus('Error: ' + e.message);
				}
			}

			function renderEvents(events) {
				$('tei-event-count').textContent = '(' + events.length + ')';
				if (!events.length) {
					$('tei-event-list').innerHTML = '<div style="padding:20px;color:#777;">No events.</div>';
					return;
				}
				const startMs = events[0].created_epoch_ms;
				const html = events.map((e, i) => {
					const offset = e.created_epoch_ms - startMs;
					const stage = e.payload?.stage || '';
					const thinking = e.payload?.thinking || '';
					const dur = e.payload?.duration_ms;
					const sourceClass = 'tei-pill-src-' + (e.event_source || 'system').replace(/[^a-z]/g, '');
					return `<div class="tei-event-row" data-idx="${i}">
						<div style="display:flex;justify-content:space-between;gap:6px;align-items:center;">
							<span style="font-weight:600;">${escHtml(e.event_type)}</span>
							<span class="tei-pill ${sourceClass}">${escHtml(e.event_source)}</span>
						</div>
						${stage ? `<div class="tei-event-stage">${escHtml(stage)}${dur ? ' · ' + fmtDur(dur) : ''}</div>` : ''}
						${thinking ? `<div class="tei-event-thinking">${escHtml(thinking)}</div>` : ''}
						<div class="tei-dim" style="font-size:10px;">+${fmtDur(offset)} · ${fmtTime(e.created_epoch_ms)}</div>
					</div>`;
				}).join('');
				$('tei-event-list').innerHTML = html;
				$('tei-event-list').querySelectorAll('.tei-event-row').forEach(el => {
					el.addEventListener('click', () => {
						const idx = parseInt(el.getAttribute('data-idx'), 10);
						$('tei-event-list').querySelectorAll('.tei-event-row').forEach(r => r.classList.remove('active'));
						el.classList.add('active');
						selectEvent(idx);
					});
				});
			}

			function selectEvent(idx) {
				const e = currentEvents[idx];
				if (!e) return;
				currentSelected = e;
				$('tei-detail').textContent = JSON.stringify(e, null, 2);
				$('tei-copy-json').disabled = false;
			}

			function rebuildFilters(events) {
				const types = new Set();
				const sources = new Set();
				events.forEach(e => {
					if (e.event_type) types.add(e.event_type);
					if (e.event_source) sources.add(e.event_source);
				});
				const sel = ($id, set) => {
					const cur = $($id).value;
					$($id).innerHTML = '<option value="">all</option>' +
						Array.from(set).sort().map(v => `<option value="${escHtml(v)}"${v === cur ? ' selected' : ''}>${escHtml(v)}</option>`).join('');
				};
				sel('tei-type-filter', types);
				sel('tei-source-filter', sources);
			}

			$('tei-load-btn').addEventListener('click', () => loadEvents($('tei-trace-input').value.trim()));
			$('tei-refresh-traces').addEventListener('click', loadTraces);
			$('tei-trace-input').addEventListener('keydown', (e) => {
				if (e.key === 'Enter') loadEvents(e.currentTarget.value.trim());
			});
			['tei-type-filter', 'tei-source-filter'].forEach(id => {
				$(id).addEventListener('change', () => loadEvents($('tei-trace-input').value.trim()));
			});
			$('tei-copy-json').addEventListener('click', () => {
				if (!currentSelected) return;
				navigator.clipboard.writeText(JSON.stringify(currentSelected, null, 2));
				setStatus('Copied!');
				setTimeout(() => setStatus(''), 1500);
			});

			// Boot
			loadTraces();
			if (PRESELECT) {
				$('tei-trace-input').value = PRESELECT;
				loadEvents(PRESELECT);
			}
		})();
		</script>
		<?php
	}
}

endif;
