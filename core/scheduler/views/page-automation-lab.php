<?php
/**
 * BizCity Scheduler — Automation Lab view (admin)
 *
 * Vanilla JS + fetch + native CSS. Self-contained, no build step.
 *
 * Expected scope vars (from class-scheduler-automation-lab.php::render):
 *   - $rest_base : string  /wp-json/bizcity-scheduler/v1
 *   - $nonce     : string  wp_rest nonce
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-04 (Phase 0.37)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );
?>
<div class="wrap" id="sclab-root">
	<h1 style="margin-bottom:4px;">🧪 Scheduler Automation Lab</h1>
	<p style="color:#666;margin-top:0;">
		Test <code>ai_context.automation.on_fire[]</code> chains without waiting for cron.
		Tất cả request đều dùng <code>manage_options</code> capability.
	</p>

	<div class="sclab-grid">

		<!-- ═══ Widget 1: Recipe Builder ═══════════════════════════════ -->
		<section class="sclab-card">
			<h2 class="sclab-h2"><span class="sclab-num">1</span> Recipe Builder</h2>
			<p class="sclab-sub">Tạo event mới kèm chain automation. Click <b>Schedule</b> sẽ lưu vào DB; click <b>Schedule + Fire Now</b> sẽ chạy chain ngay lập tức.</p>

			<label class="sclab-label">Title</label>
			<input id="sclab-title" type="text" class="sclab-input" placeholder="TEST automation chain" value="TEST automation chain">

			<label class="sclab-label">Start at</label>
			<div class="sclab-row">
				<input id="sclab-start" type="datetime-local" class="sclab-input">
				<button type="button" class="button" data-offset="5">+5m</button>
				<button type="button" class="button" data-offset="60">+1h</button>
				<button type="button" class="button" data-offset="1440">+1d</button>
			</div>

			<label class="sclab-label">Reminder minutes before start</label>
			<input id="sclab-reminder" type="number" class="sclab-input sclab-input--narrow" value="0" min="0" max="1440">

			<label class="sclab-label">
				Chain JSON
				<button type="button" id="sclab-validate" class="button button-secondary" style="float:right;">Validate</button>
			</label>
			<textarea id="sclab-chain" class="sclab-input sclab-textarea" rows="10"></textarea>
			<div id="sclab-validate-out" class="sclab-validate-out"></div>

			<label class="sclab-label">Skill ref (optional)</label>
			<input id="sclab-skill" type="text" class="sclab-input" placeholder="scheduler/scheduled-fb-post.md">

			<div class="sclab-row sclab-row--actions">
				<button type="button" id="sclab-schedule" class="button button-primary">Schedule</button>
				<button type="button" id="sclab-schedule-fire" class="button button-primary">Schedule + Fire Now</button>
				<button type="button" id="sclab-load-tools" class="button">Reload tool list</button>
			</div>

			<div id="sclab-schedule-out" class="sclab-out"></div>
		</section>

		<!-- ═══ Widget 2: Fire-Now Existing Event ═══════════════════════ -->
		<section class="sclab-card">
			<h2 class="sclab-h2"><span class="sclab-num">2</span> Fire Now (existing event)</h2>
			<p class="sclab-sub">Đã có event với <code>ai_context.automation</code> trong DB? Nhập ID để fire ngay (bypass cron).</p>

			<label class="sclab-label">Event ID</label>
			<div class="sclab-row">
				<input id="sclab-fire-id" type="number" class="sclab-input sclab-input--narrow" placeholder="e.g. 123">
				<button type="button" id="sclab-fire" class="button button-primary">Fire reminder_fire NOW</button>
			</div>
			<div id="sclab-fire-out" class="sclab-out"></div>
		</section>

		<!-- ═══ Widget 3: Live Timeline ═════════════════════════════════ -->
		<section class="sclab-card sclab-card--wide">
			<h2 class="sclab-h2">
				<span class="sclab-num">3</span> Live Timeline
				<label style="float:right;font-size:12px;font-weight:400;">
					<input type="checkbox" id="sclab-autorefresh" checked> Auto-refresh 3s
				</label>
			</h2>
			<p class="sclab-sub">Đọc trực tiếp từ <code>bizcity_cron_runs.meta.events[]</code> — group theo <code>automation_chain_started → automation_chain_done</code>.</p>
			<div id="sclab-timeline" class="sclab-timeline">
				<div class="sclab-empty">Đang tải…</div>
			</div>
		</section>

		<!-- ═══ Widget 4: Tool Registry ═════════════════════════════════ -->
		<section class="sclab-card">
			<h2 class="sclab-h2"><span class="sclab-num">4</span> Tool Registry Snapshot</h2>
			<p class="sclab-sub">Danh sách tool đăng ký qua Intent Provider — click để chèn skeleton vào Chain JSON.</p>
			<div id="sclab-tools" class="sclab-tools">
				<div class="sclab-empty">Đang tải…</div>
			</div>
		</section>

	</div>
</div>

<style>
#sclab-root { max-width: 1400px; }
#sclab-root code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
.sclab-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-top: 16px;
}
.sclab-card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 8px;
	padding: 16px 18px;
	box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.sclab-card--wide { grid-column: 1 / -1; }
.sclab-h2 {
	margin: 0 0 4px;
	font-size: 14px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}
.sclab-num {
	display: inline-flex;
	width: 22px; height: 22px;
	border-radius: 50%;
	background: linear-gradient(135deg, #6366f1, #8b5cf6);
	color: #fff;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 700;
}
.sclab-sub { color: #6b7280; font-size: 12px; margin: 4px 0 12px; }
.sclab-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #374151;
	margin: 10px 0 4px;
}
.sclab-input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid #d1d5db;
	border-radius: 6px;
	font-size: 13px;
	font-family: inherit;
	box-sizing: border-box;
}
.sclab-input--narrow { width: 180px; }
.sclab-textarea {
	font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
	font-size: 12px;
	line-height: 1.5;
	resize: vertical;
}
.sclab-row {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}
.sclab-row--actions { margin-top: 12px; }
.sclab-out, .sclab-validate-out {
	margin-top: 10px;
	padding: 8px 12px;
	border-radius: 6px;
	font-size: 12px;
	font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
	white-space: pre-wrap;
	word-break: break-word;
	min-height: 0;
}
.sclab-out:empty, .sclab-validate-out:empty { display: none; }
.sclab-out--ok      { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.sclab-out--err     { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.sclab-out--warn    { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.sclab-timeline { display: flex; flex-direction: column; gap: 8px; max-height: 600px; overflow-y: auto; }
.sclab-chain-row {
	border: 1px solid #e5e7eb;
	border-left-width: 4px;
	border-radius: 6px;
	padding: 10px 14px;
	background: #fafafa;
	font-size: 12px;
}
.sclab-chain-row--ok      { border-left-color: #10b981; }
.sclab-chain-row--failed  { border-left-color: #ef4444; }
.sclab-chain-row--partial { border-left-color: #f59e0b; }
.sclab-chain-row--running { border-left-color: #6366f1; }
.sclab-chain-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-weight: 600;
	color: #1f2937;
	margin-bottom: 6px;
}
.sclab-chain-time { color: #9ca3af; font-weight: 400; font-size: 11px; }
.sclab-step {
	display: flex;
	gap: 8px;
	padding: 3px 0;
	font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
	font-size: 11.5px;
	border-top: 1px dashed #e5e7eb;
	padding-top: 4px;
	margin-top: 4px;
}
.sclab-step:first-of-type { border-top: 0; margin-top: 0; padding-top: 0; }
.sclab-step__icon { width: 16px; flex-shrink: 0; }
.sclab-step__body { flex: 1; }
.sclab-step__msg { color: #6b7280; }
.sclab-empty { color: #9ca3af; font-size: 12px; padding: 12px; text-align: center; }
.sclab-tools { display: flex; flex-direction: column; gap: 4px; max-height: 320px; overflow-y: auto; }
.sclab-tool {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border-radius: 5px;
	cursor: pointer;
	border: 1px solid transparent;
	font-size: 12px;
}
.sclab-tool:hover { background: #eef2ff; border-color: #c7d2fe; }
.sclab-tool__name { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; color: #4338ca; font-weight: 600; }
.sclab-tool__label { color: #6b7280; }
.sclab-tool__req { margin-left: auto; font-size: 10px; color: #ef4444; }
@media (max-width: 960px) {
	.sclab-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
	'use strict';

	var REST  = <?php echo wp_json_encode( $rest_base ); ?>;
	var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

	var $ = function (id) { return document.getElementById(id); };

	var DEFAULT_CHAIN = {
		version: 1,
		on_fire: [
			{
				tool: 'scheduler_get_today_agenda',
				args: {},
				on_error: 'continue'
			}
		]
	};

	function pad(n) { return String(n).padStart(2, '0'); }

	function toLocalInput(d) {
		return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) +
			'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function setStart(offsetMin) {
		var d = new Date(Date.now() + offsetMin * 60000);
		$('sclab-start').value = toLocalInput(d);
	}

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' }, opts.headers || {});
		return fetch(REST + path, opts).then(function (r) {
			return r.json().then(function (j) { return { status: r.status, body: j }; });
		});
	}

	function showOut(el, kind, text) {
		el.className = 'sclab-out sclab-out--' + kind;
		el.textContent = text;
	}

	function fmtTs(iso) {
		if (!iso) return '';
		try {
			var d = new Date(iso);
			return d.toLocaleString();
		} catch (e) { return iso; }
	}

	/* ── Widget 1: Recipe Builder ───────────────────────────────────── */

	$('sclab-chain').value = JSON.stringify(DEFAULT_CHAIN, null, 2);
	setStart(5);

	document.querySelectorAll('[data-offset]').forEach(function (btn) {
		btn.addEventListener('click', function () { setStart(parseInt(btn.dataset.offset, 10)); });
	});

	$('sclab-validate').addEventListener('click', function () {
		var out = $('sclab-validate-out');
		var raw;
		try { raw = JSON.parse($('sclab-chain').value); }
		catch (e) {
			out.className = 'sclab-validate-out sclab-out--err';
			out.textContent = 'JSON parse error: ' + e.message;
			return;
		}
		api('/automation/validate', { method: 'POST', body: JSON.stringify({ automation: raw }) })
			.then(function (r) {
				var b = r.body;
				if (b.ok && !b.warnings.length) {
					out.className = 'sclab-validate-out sclab-out--ok';
					out.textContent = '✅ Valid — no warnings.';
				} else if (b.ok) {
					out.className = 'sclab-validate-out sclab-out--warn';
					out.textContent = '⚠️ Valid with warnings:\n' + b.warnings.map(function (w) { return '• ' + w; }).join('\n');
				} else {
					out.className = 'sclab-validate-out sclab-out--err';
					out.textContent = '❌ Errors:\n' + b.errors.map(function (e) { return '• ' + e; }).join('\n')
						+ (b.warnings.length ? '\n\n⚠️ Warnings:\n' + b.warnings.map(function (w) { return '• ' + w; }).join('\n') : '');
				}
			});
	});

	function buildEventPayload() {
		var chain;
		try { chain = JSON.parse($('sclab-chain').value); }
		catch (e) { throw new Error('Chain JSON invalid: ' + e.message); }

		var startLocal = $('sclab-start').value;
		if (!startLocal) { throw new Error('Start at is required'); }
		// datetime-local → "YYYY-MM-DDTHH:MM" — convert to "YYYY-MM-DD HH:MM:00" (local).
		var startAt = startLocal.replace('T', ' ') + ':00';

		return {
			title: $('sclab-title').value || 'TEST automation chain',
			start_at: startAt,
			reminder_min: parseInt($('sclab-reminder').value, 10) || 0,
			source: 'ai_plan',
			ai_context: {
				automation: chain,
				skill_ref: $('sclab-skill').value || '',
				created_by: 'automation-lab'
			}
		};
	}

	function scheduleEvent(thenFire) {
		var out = $('sclab-schedule-out');
		var payload;
		try { payload = buildEventPayload(); }
		catch (e) { showOut(out, 'err', e.message); return; }

		showOut(out, 'warn', 'Creating event…');

		api('/events', { method: 'POST', body: JSON.stringify(payload) })
			.then(function (r) {
				if (r.status >= 400 || r.body.error) {
					showOut(out, 'err', 'Create failed: ' + (r.body.error || ('HTTP ' + r.status)));
					return;
				}
				var ev = r.body.event || r.body;
				var eid = ev.id || ev.event_id;
				if (!eid) {
					showOut(out, 'err', 'Created but no event ID in response: ' + JSON.stringify(r.body));
					return;
				}

				if (!thenFire) {
					showOut(out, 'ok', '✅ Scheduled event #' + eid + ' for ' + payload.start_at + '. Reminder cron sẽ fire khi tới giờ.');
					$('sclab-fire-id').value = eid;
					refreshTimeline();
					return;
				}

				showOut(out, 'warn', 'Created event #' + eid + ', firing now…');
				return api('/automation/fire-now', { method: 'POST', body: JSON.stringify({ event_id: eid }) })
					.then(function (rr) {
						if (rr.body.ok) {
							showOut(out, 'ok', '✅ Event #' + eid + ' fired in ' + rr.body.ms + 'ms. Xem Timeline bên dưới.');
						} else {
							showOut(out, 'err', '❌ Fire failed: ' + (rr.body.error || 'unknown'));
						}
						$('sclab-fire-id').value = eid;
						refreshTimeline();
					});
			})
			.catch(function (err) { showOut(out, 'err', 'Request error: ' + err.message); });
	}

	$('sclab-schedule').addEventListener('click', function () { scheduleEvent(false); });
	$('sclab-schedule-fire').addEventListener('click', function () { scheduleEvent(true); });

	/* ── Widget 2: Fire Now existing ────────────────────────────────── */

	$('sclab-fire').addEventListener('click', function () {
		var out = $('sclab-fire-out');
		var eid = parseInt($('sclab-fire-id').value, 10);
		if (!eid) { showOut(out, 'err', 'Cần Event ID hợp lệ.'); return; }

		showOut(out, 'warn', 'Firing reminder for event #' + eid + '…');
		api('/automation/fire-now', { method: 'POST', body: JSON.stringify({ event_id: eid }) })
			.then(function (r) {
				if (r.body.ok) {
					showOut(out, 'ok', '✅ Fired in ' + r.body.ms + 'ms. ' + (r.body.hint || ''));
				} else {
					showOut(out, 'err', '❌ ' + (r.body.error || 'unknown error') + ' (HTTP ' + r.status + ')');
				}
				refreshTimeline();
			});
	});

	/* ── Widget 3: Live Timeline ────────────────────────────────────── */

	function renderTimeline(chains) {
		var box = $('sclab-timeline');
		if (!chains.length) {
			box.innerHTML = '<div class="sclab-empty">Chưa có chain nào chạy. Bấm "Schedule + Fire Now" để test.</div>';
			return;
		}
		box.innerHTML = chains.map(function (c) {
			var stepHtml = c.steps.map(function (s) {
				var icon = s.ok ? '✅' : '❌';
				var body = '<b>step ' + s.idx + '</b> · ' + s.tool;
				var msg  = s.ok
					? (s.message ? ' → ' + escapeHtml(s.message) : '')
					: ' → <span style="color:#dc2626;">' + escapeHtml(s.reason || 'error') + (s.error ? ': ' + escapeHtml(s.error) : '') + '</span>';
				return '<div class="sclab-step"><span class="sclab-step__icon">' + icon + '</span><span class="sclab-step__body">' + body + '<span class="sclab-step__msg">' + msg + '</span></span></div>';
			}).join('');
			var statusLabel = c.status === 'ok' ? '✅ OK' : (c.status === 'failed' ? '❌ FAILED' : (c.status === 'partial' ? '⚠️ PARTIAL' : '⏳ RUNNING'));
			return '<div class="sclab-chain-row sclab-chain-row--' + c.status + '">' +
				'<div class="sclab-chain-head">' +
					'<span>' + statusLabel + ' · event #' + c.event_id + ' · ' + c.steps.length + '/' + c.step_count + ' steps' +
					(c.skill_ref ? ' · <code>' + escapeHtml(c.skill_ref) + '</code>' : '') +
					'</span>' +
					'<span class="sclab-chain-time">' + fmtTs(c.started_at) + '</span>' +
				'</div>' + (stepHtml || '<div class="sclab-empty" style="text-align:left;padding:4px 0;">(no steps yet)</div>') + '</div>';
		}).join('');
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
		});
	}

	function refreshTimeline() {
		api('/automation/recent?limit=20')
			.then(function (r) { renderTimeline(r.body.chains || []); })
			.catch(function () { /* silent */ });
	}

	var timelineTimer = null;
	function startAutoRefresh() {
		stopAutoRefresh();
		timelineTimer = setInterval(refreshTimeline, 3000);
	}
	function stopAutoRefresh() {
		if (timelineTimer) { clearInterval(timelineTimer); timelineTimer = null; }
	}
	$('sclab-autorefresh').addEventListener('change', function (e) {
		if (e.target.checked) startAutoRefresh(); else stopAutoRefresh();
	});

	/* ── Widget 4: Tool Registry ────────────────────────────────────── */

	function loadTools() {
		var box = $('sclab-tools');
		box.innerHTML = '<div class="sclab-empty">Loading…</div>';
		api('/automation/tools')
			.then(function (r) {
				var tools = r.body.tools || [];
				if (!tools.length) {
					box.innerHTML = '<div class="sclab-empty">No tools registered (Intent Provider chưa khởi tạo?).</div>';
					return;
				}
				box.innerHTML = tools.map(function (t) {
					var req = t.required && t.required.length ? '<span class="sclab-tool__req">req: ' + t.required.join(', ') + '</span>' : '';
					return '<div class="sclab-tool" data-tool="' + escapeHtml(t.name) + '" data-req="' + escapeHtml((t.required || []).join(',')) + '">' +
						'<span class="sclab-tool__name">' + escapeHtml(t.name) + '</span>' +
						'<span class="sclab-tool__label">' + escapeHtml(t.label || '') + '</span>' +
						req + '</div>';
				}).join('');
				box.querySelectorAll('.sclab-tool').forEach(function (el) {
					el.addEventListener('click', function () { insertToolStep(el.dataset.tool, el.dataset.req); });
				});
			});
	}

	function insertToolStep(name, reqCsv) {
		var args = {};
		(reqCsv ? reqCsv.split(',') : []).filter(Boolean).forEach(function (k) { args[k] = ''; });
		var step = { tool: name, args: args, on_error: 'continue' };

		var chain;
		try { chain = JSON.parse($('sclab-chain').value); }
		catch (e) { chain = { version: 1, on_fire: [] }; }
		if (!chain.on_fire) chain.on_fire = [];
		chain.on_fire.push(step);
		$('sclab-chain').value = JSON.stringify(chain, null, 2);
	}

	$('sclab-load-tools').addEventListener('click', loadTools);

	/* ── Bootstrap ──────────────────────────────────────────────────── */

	loadTools();
	refreshTimeline();
	startAutoRefresh();
})();
</script>
