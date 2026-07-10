<?php
/**
 * Webhook Inspector (PHASE 0.33 M4 → M4.1 redesign)
 *
 * Surface mounts as a submenu under the **BizCity CRM** top menu
 * (slug: `bizcity-crm-webhook`), with a fallback Tools menu entry
 * if the CRM plugin isn't loaded yet.
 *
 * REST namespace: `bizcity/cg/v1`
 *   GET  /inspector/logs
 *   GET  /inspector/log/{date}/{id}
 *   GET  /inspector/bindings           ?character_id=  (optional)
 *   POST /inspector/bindings
 *   POST /inspector/bindings/{id}/disable
 *   GET  /inspector/stats
 *   GET  /inspector/gurus              — character roster {id,name,slug,avatar,status}
 *   GET  /inspector/channels           — registered adapters + adapter accounts (best-effort)
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M4)
 * @since 1.5.2 (PHASE 0.33 M4.1 — relocate under CRM menu, dropdown UI)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Webhook_Inspector {

	const NAMESPACE_V1 = 'bizcity-channel/v1';
	const SLUG         = 'bizcity-crm-webhook';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu',    array( __CLASS__, 'register_menu' ), 99 );
	}

	/* ─────────────────────────── Menu ─────────────────────────── */

	public static function register_menu(): void {
		global $admin_page_hooks;
		$parent_crm = isset( $admin_page_hooks['bizcity-crm'] ) ? 'bizcity-crm' : '';

		if ( $parent_crm ) {
			add_submenu_page(
				$parent_crm,
				__( 'Webhook Inspector', 'bizcity' ),
				__( 'BizCity Logs · Webhooks', 'bizcity' ),
				'manage_options',
				self::SLUG,
				array( __CLASS__, 'render_page' )
			);
		} else {
			// Fallback when CRM plugin not active — keep Tools entry so the page is reachable.
			add_management_page(
				__( 'Webhook Inspector', 'bizcity' ),
				__( 'BizCity Logs · Webhooks', 'bizcity' ),
				'manage_options',
				self::SLUG,
				array( __CLASS__, 'render_page' )
			);
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bizcity' ) );
		}
		$nonce     = wp_create_nonce( 'wp_rest' );
		$rest_root = esc_url_raw( rest_url( self::NAMESPACE_V1 ) );
		$today     = class_exists( 'BizCity_Webhook_Log' ) ? BizCity_Webhook_Log::today_key() : '';
		$ttl       = class_exists( 'BizCity_Webhook_Log' ) ? BizCity_Webhook_Log::TTL_DAYS : 3;
		$gurus_url = admin_url( 'admin.php?page=bizcity-knowledge-characters' );
		?>
		<div class="wrap" id="bizcity-webhook-inspector-root">
			<h1><?php esc_html_e( 'Webhook Inspector', 'bizcity' ); ?>
				<span style="font-size:13px;color:#666;font-weight:400">PHASE 0.33 M4 · 1 cánh cổng — 2 dòng chảy</span>
			</h1>
			<p>
				<?php
				printf(
					/* translators: %1$s today partition key, %2$d TTL days */
					esc_html__( 'Today partition: %1$s · TTL %2$d days · Storage: file (wp-content/hook-logs/).', 'bizcity' ),
					'<code>' . esc_html( $today ) . '</code>',
					(int) $ttl
				);
				?>
			</p>
			<h2 class="nav-tab-wrapper">
				<a href="#tab-logs" class="nav-tab nav-tab-active" data-tab="logs"><?php esc_html_e( 'Webhook log', 'bizcity' ); ?></a>
				<a href="#tab-bindings" class="nav-tab" data-tab="bindings"><?php esc_html_e( 'Guru × Channel bindings', 'bizcity' ); ?></a>
				<a href="#tab-stats" class="nav-tab" data-tab="stats"><?php esc_html_e( 'Stats', 'bizcity' ); ?></a>
			</h2>

			<!-- ───── LOGS ───── -->
			<div class="bz-tab" data-tab="logs">
				<form id="bz-log-filter" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<label>Platform
						<select name="platform">
							<option value="">— all —</option>
							<option value="FB_MESS">FB_MESS</option>
							<option value="FB_FEED">FB_FEED</option>
							<option value="ZALO_BOT">ZALO_BOT</option>
							<option value="ZALO_HOTLINE">ZALO_HOTLINE</option>
							<option value="WEBCHAT">WEBCHAT</option>
							<option value="TELEGRAM">TELEGRAM</option>
						</select>
					</label>
					<label>Verify
						<select name="verify_status">
							<option value="">— all —</option>
							<option value="pending">pending</option>
							<option value="verified">verified</option>
							<option value="failed">failed</option>
						</select>
					</label>
					<label>Days <input type="number" name="days" min="1" max="<?php echo (int) $ttl; ?>" value="<?php echo (int) $ttl; ?>" style="width:60px"></label>
					<label>Limit <input type="number" name="limit" min="1" max="500" value="100" style="width:80px"></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Refresh', 'bizcity' ); ?></button>
					<span id="bz-log-count" style="color:#666"></span>
				</form>
				<table class="widefat striped" id="bz-log-table">
					<thead><tr>
						<th style="width:140px">created_at</th>
						<th>platform</th>
						<th>endpoint</th>
						<th style="width:60px">http</th>
						<th style="width:80px">verify</th>
						<th style="width:60px">ms</th>
						<th>guru</th>
						<th>msg_id</th>
						<th style="width:60px"></th>
						<th style="width:80px">replay</th>
					</tr></thead>
					<tbody><tr><td colspan="10"><?php esc_html_e( 'Loading…', 'bizcity' ); ?></td></tr></tbody>
				</table>
				<div id="bz-log-detail" style="margin-top:16px;display:none">
					<h3><?php esc_html_e( 'Row detail', 'bizcity' ); ?> <button type="button" class="button" id="bz-log-detail-close">×</button></h3>
					<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:60vh;overflow:auto"></pre>
				</div>
			</div>

			<!-- ───── BINDINGS ───── -->
			<div class="bz-tab" data-tab="bindings" style="display:none">
				<p style="color:#666">
					<?php
					printf(
						/* translators: %s = link to character admin page */
						esc_html__( 'Mỗi binding khoá %s vào kênh ngoài. Khi tin nhắn đến, listener sẽ lookup binding để route đúng Guru. Bạn cũng có thể bind ngay từ %s.', 'bizcity' ),
						'<strong>1 Guru (character)</strong>',
						'<a href="' . esc_url( $gurus_url ) . '">trang Twin Guru</a>'
					);
					?>
				</p>

				<div class="bz-bind-form-card">
					<h3 style="margin-top:0"><?php esc_html_e( 'Bind a Guru to a channel account', 'bizcity' ); ?></h3>
					<form id="bz-bind-form" class="bz-bind-grid">
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Pick Guru', 'bizcity' ); ?></label>
							<select name="character_id" id="bz-bind-guru" required>
								<option value=""><?php esc_html_e( '— loading gurus —', 'bizcity' ); ?></option>
							</select>
							<div class="bz-bind-preview" id="bz-bind-guru-preview"></div>
						</div>
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Channel platform', 'bizcity' ); ?></label>
							<select name="platform" id="bz-bind-platform" required>
								<option value=""><?php esc_html_e( '— loading channels —', 'bizcity' ); ?></option>
							</select>
						</div>
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Account on that channel', 'bizcity' ); ?></label>
							<select name="account_id" id="bz-bind-account">
								<option value="*">*  (fallback: any account on this platform)</option>
							</select>
							<small style="color:#666"><?php esc_html_e( 'Hoặc nhập tay account_id bên dưới', 'bizcity' ); ?></small>
							<input type="text" name="account_id_manual" id="bz-bind-account-manual" placeholder="page_id / oa_id / site_id" style="margin-top:4px;width:100%">
						</div>
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Reply mode', 'bizcity' ); ?></label>
							<select name="mode" required>
								<option value="auto">auto — Guru AI trả lời 100%</option>
								<option value="manual">manual — CSKH trả lời tay</option>
								<option value="hybrid">hybrid — Guru gợi ý, CSKH duyệt</option>
								<option value="roundrobin">roundrobin — luân phiên trong pool</option>
							</select>
							<small style="color:#666"><?php esc_html_e( 'Cold start: pick auto. Switch later anytime.', 'bizcity' ); ?></small>
						</div>
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Auto reply (legacy flag)', 'bizcity' ); ?></label>
							<label class="bz-switch"><input type="checkbox" name="auto_reply" value="1" checked><span></span> ON</label>
						</div>
						<div class="bz-bind-field">
							<label><?php esc_html_e( 'Fallback assignee (user_id)', 'bizcity' ); ?></label>
							<input type="number" name="fallback_assignee" placeholder="<?php esc_attr_e( 'optional', 'bizcity' ); ?>">
						</div>
						<div class="bz-bind-actions">
							<button type="submit" class="button button-primary button-large"><?php esc_html_e( '+ Save binding (upsert)', 'bizcity' ); ?></button>
							<span id="bz-bind-msg" style="margin-left:12px"></span>
						</div>
					</form>
				</div>

				<h3 style="margin-top:24px"><?php esc_html_e( 'Active bindings', 'bizcity' ); ?></h3>
				<div id="bz-bind-grid" class="bz-bind-cards"><?php esc_html_e( 'Loading…', 'bizcity' ); ?></div>
			</div>

			<!-- ───── STATS ───── -->
			<div class="bz-tab" data-tab="stats" style="display:none">
				<h3><?php esc_html_e( 'Counts by platform', 'bizcity' ); ?></h3>
				<table class="widefat striped" id="bz-stats-table" style="max-width:480px">
					<thead><tr><th>Platform</th><th style="width:100px">Count</th></tr></thead>
					<tbody><tr><td colspan="2"><?php esc_html_e( 'Loading…', 'bizcity' ); ?></td></tr></tbody>
				</table>
				<h3 style="margin-top:24px"><?php esc_html_e( 'Active partitions', 'bizcity' ); ?></h3>
				<ul id="bz-stats-partitions"><li><?php esc_html_e( 'Loading…', 'bizcity' ); ?></li></ul>
			</div>
		</div>

		<style>
			#bz-log-table tbody tr { cursor: pointer }
			#bz-log-table tbody tr:hover { background: #f0f6fc }
			.bz-status-pending  { color: #b26900 }
			.bz-status-verified { color: #00712d }
			.bz-status-failed   { color: #b32d2e }

			/* Binding form */
			.bz-bind-form-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 6px; padding: 16px; max-width: 880px; }
			.bz-bind-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px 18px; align-items: start; }
			.bz-bind-field label { display:block; font-weight: 600; margin-bottom: 4px; font-size: 12px; color:#1d2327; }
			.bz-bind-field select, .bz-bind-field input[type="text"], .bz-bind-field input[type="number"] { width: 100%; }
			.bz-bind-actions { grid-column: 1 / -1; margin-top: 4px; }
			.bz-bind-preview { margin-top:6px; font-size: 12px; color:#555; min-height: 16px; }
			.bz-switch input { vertical-align: middle; }

			/* Binding cards */
			.bz-bind-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; margin-top: 12px; }
			.bz-bind-card  { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 14px; }
			.bz-bind-card-head { display:flex; align-items:center; gap:10px; margin-bottom: 10px; }
			.bz-bind-avatar { width:40px;height:40px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0; }
			.bz-bind-avatar img { width:40px;height:40px;border-radius:50%;object-fit:cover; }
			.bz-bind-avatar span { font-size:22px; }
			.bz-bind-card-title { flex:1; min-width:0; }
			.bz-bind-card-title strong { display:block; line-height:1.2; }
			.bz-bind-card-title small  { color:#666; }
			.bz-bind-pill { display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#eef2ff;color:#3730a3; }
			.bz-bind-pill-disabled { background:#fee2e2; color:#991b1b; }
			.bz-bind-meta { font-size:12px;color:#555;margin: 6px 0; }
			.bz-bind-meta code { font-size: 11px; }
			.bz-bind-card-actions { display:flex; gap:6px; }
			.bz-mode-pill { display:inline-block;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px; }
			.bz-mode-auto       { background:#dcfce7;color:#15803d; }
			.bz-mode-manual     { background:#fee2e2;color:#b91c1c; }
			.bz-mode-hybrid     { background:#fef3c7;color:#a16207; }
			.bz-mode-roundrobin { background:#e0e7ff;color:#4338ca; }
		</style>

		<script>
		(function(){
			const REST_ROOT = <?php echo wp_json_encode( $rest_root ); ?>;
			const NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
			const $  = (s,r) => (r||document).querySelector(s);
			const $$ = (s,r) => Array.from((r||document).querySelectorAll(s));
			const j = (url, opts) => fetch(url, Object.assign({
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json', 'Content-Type': 'application/json' }
			}, opts || {})).then(r => r.json());
			const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

			let GURUS = [];     // [{id,name,slug,avatar,status}]
			let CHANNELS = [];  // [{platform,label,accounts:[{id,label}]}]

			/* ───── Tabs ───── */
			$$('#bizcity-webhook-inspector-root .nav-tab').forEach(tab => {
				tab.addEventListener('click', e => {
					e.preventDefault();
					const name = tab.dataset.tab;
					$$('#bizcity-webhook-inspector-root .nav-tab').forEach(t => t.classList.toggle('nav-tab-active', t === tab));
					$$('#bizcity-webhook-inspector-root .bz-tab').forEach(div => div.style.display = (div.dataset.tab === name ? '' : 'none'));
					if (name === 'bindings') ensureBindingDeps();
					if (name === 'stats')    loadStats();
				});
			});

			/* ───── Logs ───── */
			function loadLogs() {
				const fd = new FormData($('#bz-log-filter'));
				const qs = new URLSearchParams();
				for (const [k, v] of fd.entries()) if (v) qs.set(k, v);
				$('#bz-log-table tbody').innerHTML = '<tr><td colspan="10">Loading…</td></tr>';
				j(REST_ROOT + '/inspector/logs?' + qs.toString()).then(res => {
					const rows = (res && res.data) || [];
					$('#bz-log-count').textContent = rows.length + ' rows';
					if (!rows.length) { $('#bz-log-table tbody').innerHTML = '<tr><td colspan="10">— empty —</td></tr>'; return; }
					$('#bz-log-table tbody').innerHTML = rows.map(r => `
						<tr data-date="${esc(r.log_date)}" data-id="${esc(r.id)}" data-replay="${r.is_replay ? 1 : 0}">
							<td>${esc(r.created_at)}</td>
							<td><code>${esc(r.platform)}</code>${r.is_replay ? ' <span title="replay" style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:8px;font-size:10px">↻</span>' : ''}</td>
							<td><code style="font-size:11px">${esc(r.endpoint)}</code></td>
							<td>${esc(r.http_status)}</td>
							<td><span class="bz-status-${esc(r.verify_status)}">${esc(r.verify_status)}</span></td>
							<td>${esc(r.latency_ms)}</td>
							<td>${r.character_id ? '#' + esc(r.character_id) : '<span style="color:#999">—</span>'}</td>
							<td>${r.channel_message_id ? '#' + esc(r.channel_message_id) : '<span style="color:#999">—</span>'}</td>
							<td><button type="button" class="button button-small bz-log-view">view</button></td>
							<td>${r.is_replay ? '<span style="color:#999;font-size:11px">—</span>' : '<button type="button" class="button button-small bz-log-replay" title="Re-fire this webhook">↻ replay</button>'}</td>
						</tr>
					`).join('');
					$$('#bz-log-table tbody tr').forEach(tr => {
						tr.querySelector('.bz-log-view').addEventListener('click', e => {
							e.stopPropagation();
							loadDetail(tr.dataset.date, tr.dataset.id);
						});
						const btnReplay = tr.querySelector('.bz-log-replay');
						if (btnReplay) {
							btnReplay.addEventListener('click', e => {
								e.stopPropagation();
								replayLog(tr.dataset.date, tr.dataset.id, btnReplay);
							});
						}
					});
				});
			}
			function replayLog(date, id, btn) {
				if (!confirm('Re-fire webhook log #' + id + ' (' + date + ')? Adapters/observers subscribed to bizcity_channel_replay will re-process.')) return;
				const original = btn.textContent;
				btn.disabled = true; btn.textContent = '… replaying';
				j(REST_ROOT + '/inspector/replay/' + encodeURIComponent(date) + '/' + encodeURIComponent(id), { method: 'POST' }).then(res => {
					btn.disabled = false;
					if (res && res.ok) {
						btn.textContent = '✓ #' + (res.replay && res.replay.id ? res.replay.id : '?');
						setTimeout(() => loadLogs(), 600);
					} else {
						btn.textContent = original;
						alert('Replay failed: ' + ((res && (res.message || res.code)) || 'unknown'));
					}
				}).catch(err => {
					btn.disabled = false;
					btn.textContent = original;
					alert('Replay error: ' + err);
				});
			}
			function loadDetail(date, id) {
				$('#bz-log-detail').style.display = '';
				$('#bz-log-detail pre').textContent = 'Loading…';
				j(REST_ROOT + '/inspector/log/' + encodeURIComponent(date) + '/' + encodeURIComponent(id)).then(res => {
					$('#bz-log-detail pre').textContent = JSON.stringify(res.data || res, null, 2);
				});
			}
			$('#bz-log-filter').addEventListener('submit', e => { e.preventDefault(); loadLogs(); });
			$('#bz-log-detail-close').addEventListener('click', () => { $('#bz-log-detail').style.display = 'none'; });
			loadLogs();

			/* ───── Bindings ───── */
			function ensureBindingDeps() {
				const tasks = [];
				if (!GURUS.length)    tasks.push(j(REST_ROOT + '/inspector/gurus').then(r => GURUS = (r && r.data) || []));
				if (!CHANNELS.length) tasks.push(j(REST_ROOT + '/inspector/channels').then(r => CHANNELS = (r && r.data) || []));
				return Promise.all(tasks).then(() => {
					hydrateGuruSelect();
					hydratePlatformSelect();
					loadBindings();
				});
			}
			function hydrateGuruSelect() {
				const sel = $('#bz-bind-guru');
				if (!GURUS.length) { sel.innerHTML = '<option value="">— no Twin Guru yet —</option>'; return; }
				sel.innerHTML = '<option value="">— pick a guru —</option>' + GURUS.map(g =>
					`<option value="${esc(g.id)}">#${esc(g.id)} · ${esc(g.name)} ${g.status ? '['+esc(g.status)+']' : ''}</option>`
				).join('');
				sel.addEventListener('change', () => {
					const g = GURUS.find(x => String(x.id) === sel.value);
					$('#bz-bind-guru-preview').innerHTML = g
						? `<span style="display:inline-flex;gap:8px;align-items:center"><img src="${esc(g.avatar||'')}" style="width:24px;height:24px;border-radius:50%;background:#eee;object-fit:cover" onerror="this.style.display='none'">${esc(g.name)} <code>${esc(g.slug||'')}</code></span>`
						: '';
				});
			}
			function hydratePlatformSelect() {
				const sel = $('#bz-bind-platform');
				if (!CHANNELS.length) { sel.innerHTML = '<option value="">— no channel adapters registered —</option>'; return; }
				sel.innerHTML = '<option value="">— pick a platform —</option>' + CHANNELS.map(c =>
					`<option value="${esc(c.platform)}">${esc(c.platform)} — ${esc(c.label)}</option>`
				).join('');
				sel.addEventListener('change', () => {
					const c = CHANNELS.find(x => x.platform === sel.value);
					const accSel = $('#bz-bind-account');
					const opts = ['<option value="*">*  (fallback: any account on this platform)</option>'];
					if (c && c.accounts && c.accounts.length) {
						opts.push(...c.accounts.map(a => `<option value="${esc(a.id)}">${esc(a.id)} — ${esc(a.label||'')}</option>`));
					}
					accSel.innerHTML = opts.join('');
				});
			}
			function loadBindings() {
				$('#bz-bind-grid').innerHTML = 'Loading…';
				j(REST_ROOT + '/inspector/bindings').then(res => {
					const rows = (res && res.data) || [];
					if (!rows.length) { $('#bz-bind-grid').innerHTML = '<p style="color:#666">— no bindings yet — bấm form trên để tạo binding đầu tiên —</p>'; return; }
					$('#bz-bind-grid').innerHTML = rows.map(b => {
						const g = GURUS.find(x => String(x.id) === String(b.character_id)) || {};
						const ch = CHANNELS.find(x => x.platform === b.platform) || {};
						const isFallback = b.account_id === '*';
						return `
							<div class="bz-bind-card">
								<div class="bz-bind-card-head">
									<div class="bz-bind-avatar">${g.avatar ? `<img src="${esc(g.avatar)}">` : '<span>🤖</span>'}</div>
									<div class="bz-bind-card-title">
										<strong>${esc(g.name||('Guru #'+b.character_id))}</strong>
										<small>${esc(g.slug||'')}</small>
									</div>
									<span class="bz-bind-pill ${b.status==='active'?'':'bz-bind-pill-disabled'}">${esc(b.status||'?')}</span>
								</div>
								<div class="bz-bind-meta">
									<div><strong>${esc(b.platform)}</strong> ${ch.label?'<small>('+esc(ch.label)+')</small>':''}</div>
									<div>account: <code>${esc(b.account_id)}</code> ${isFallback ? '<span style="color:#9ca3af">— fallback</span>' : ''}</div>
								<div>mode: <span class="bz-mode-pill bz-mode-${esc(b.mode||'auto')}">${esc(b.mode||'auto')}</span> · auto-reply: ${b.auto_reply ? '✓' : '—'} · fallback assignee: ${b.fallback_assignee ? '#'+esc(b.fallback_assignee) : '—'}</div>
									<div style="color:#9ca3af;margin-top:4px">updated ${esc(b.updated_at)}</div>
								</div>
								<div class="bz-bind-card-actions">
									<button type="button" class="button button-small bz-bind-disable" data-id="${esc(b.id)}">disable</button>
								</div>
							</div>
						`;
					}).join('');
					$$('.bz-bind-disable').forEach(btn => btn.addEventListener('click', () => {
						if (!confirm('Disable binding #' + btn.dataset.id + '?')) return;
						j(REST_ROOT + '/inspector/bindings/' + btn.dataset.id + '/disable', { method: 'POST', body: '{}' }).then(loadBindings);
					}));
				});
			}
			$('#bz-bind-form').addEventListener('submit', e => {
				e.preventDefault();
				const fd = new FormData(e.target);
				const account = (fd.get('account_id_manual') || '').trim() || fd.get('account_id') || '*';
				const body = {
					platform:          fd.get('platform'),
					account_id:        account,
					character_id:      parseInt(fd.get('character_id'), 10) || 0,
					mode:              fd.get('mode') || 'auto',
					auto_reply:        fd.get('auto_reply') ? 1 : 0,
					fallback_assignee: parseInt(fd.get('fallback_assignee'), 10) || null
				};
				if (!body.platform || !body.character_id) {
					$('#bz-bind-msg').innerHTML = '<span style="color:#b32d2e">Platform + Guru required</span>';
					return;
				}
				$('#bz-bind-msg').textContent = 'Saving…';
				j(REST_ROOT + '/inspector/bindings', { method: 'POST', body: JSON.stringify(body) }).then(res => {
					$('#bz-bind-msg').innerHTML = res.ok
						? '<span style="color:#00712d">✓ Saved → id #' + res.data.id + '</span>'
						: '<span style="color:#b32d2e">Error: ' + esc(res.message || 'unknown') + '</span>';
					loadBindings();
				});
			});

			/* ───── Stats ───── */
			function loadStats() {
				j(REST_ROOT + '/inspector/stats').then(res => {
					const counts = (res.data && res.data.counts_by_platform) || {};
					const parts  = (res.data && res.data.partitions) || [];
					const rows = Object.keys(counts).sort().map(k => `<tr><td><code>${esc(k)}</code></td><td>${esc(counts[k])}</td></tr>`).join('');
					$('#bz-stats-table tbody').innerHTML = rows || '<tr><td colspan="2">— empty —</td></tr>';
					$('#bz-stats-partitions').innerHTML = parts.map(p => `<li><code>${esc(p)}</code></li>`).join('') || '<li>— no partitions —</li>';
				});
			}
		})();
		</script>
		<?php
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/inspector/logs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_logs' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/log/(?P<date>\d{4}_\d{2}_\d{2})/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_log_detail' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/bindings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_bindings_list' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_binding_upsert' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/bindings/(?P<id>\d+)/disable', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_binding_disable' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_stats' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/gurus', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_gurus' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );

		register_rest_route( self::NAMESPACE_V1, '/inspector/channels', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_channels' ),
			'permission_callback' => array( __CLASS__, 'can' ),
		) );
	}

	public static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_logs( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Webhook log class missing' ), 500 );
		}
		$filters = array(
			'platform'      => (string) $req->get_param( 'platform' ),
			'verify_status' => (string) $req->get_param( 'verify_status' ),
			'days'          => (int) $req->get_param( 'days' ),
			'limit'         => (int) $req->get_param( 'limit' ),
		);
		$rows = BizCity_Webhook_Log::query( array_filter( $filters, static function ( $v ) { return $v !== '' && $v !== 0 && $v !== null; } ) );
		return new WP_REST_Response( array( 'ok' => true, 'data' => $rows, 'ts' => time() ), 200 );
	}

	public static function rest_log_detail( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Webhook log class missing' ), 500 );
		}
		$date = (string) $req['date'];
		$id   = (int) $req['id'];
		$row  = BizCity_Webhook_Log::find( $date, $id );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Not found' ), 404 );
		}
		// Body is already an object/string in the file, decode if it looks like JSON.
		if ( isset( $row['body'] ) && is_string( $row['body'] ) && $row['body'] !== '' ) {
			$decoded = json_decode( $row['body'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$row['body_decoded'] = $decoded;
			}
		}
		// Attach linked channel_messages row if present.
		if ( ! empty( $row['channel_message_id'] ) && class_exists( 'BizCity_Channel_Messages' ) ) {
			global $wpdb;
			$msg = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . BizCity_Channel_Messages::table() . ' WHERE id=%d', (int) $row['channel_message_id'] ),
				ARRAY_A
			);
			if ( $msg ) {
				$row['channel_message'] = $msg;
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $row ), 200 );
	}

	public static function rest_bindings_list( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Binding class missing' ), 500 );
		}
		$rows = BizCity_Channel_Binding::all();
		$cid  = (int) $req->get_param( 'character_id' );
		if ( $cid > 0 ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $cid ) {
				return (int) ( $r['character_id'] ?? 0 ) === $cid;
			} ) );
		}
		$platform = strtoupper( trim( (string) $req->get_param( 'platform' ) ) );
		if ( $platform !== '' ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $platform ) {
				return strtoupper( (string) ( $r['platform'] ?? '' ) ) === $platform;
			} ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $rows ), 200 );
	}

	public static function rest_binding_upsert( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Binding class missing' ), 500 );
		}
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) { $body = array(); }
		$args = array(
			'platform'          => strtoupper( trim( (string) ( $body['platform'] ?? '' ) ) ),
			'account_id'        => trim( (string) ( $body['account_id'] ?? '' ) ),
			'character_id'      => (int) ( $body['character_id'] ?? 0 ),
			'mode'              => isset( $body['mode'] ) ? (string) $body['mode'] : '',
			'auto_reply'        => ! empty( $body['auto_reply'] ) ? 1 : 0,
			'fallback_assignee' => isset( $body['fallback_assignee'] ) ? (int) $body['fallback_assignee'] : null,
			'responder_pool'    => isset( $body['responder_pool'] ) && is_array( $body['responder_pool'] ) ? $body['responder_pool'] : array(),
		);
		// [2026-06-09 Johnny Chu] PHASE-D D-WEBCHAT-WILDCARD — WEBCHAT không cần account_id cụ thể;
		// guest user không có OA/Page ID. Tự động dùng '*' (wildcard) khi bỏ trống.
		if ( $args['platform'] === 'WEBCHAT' && $args['account_id'] === '' ) {
			$args['account_id'] = '*';
		}
		if ( $args['platform'] === '' || $args['account_id'] === '' || $args['character_id'] <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'platform, account_id, character_id are required' ), 400 );
		}
		// [2026-06-03 Johnny Chu] GURU-UI W0.1 — R-GCB-7 reject bind tới Guru chưa publish.
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$char = BizCity_Knowledge_Database::instance()->get_character( $args['character_id'] );
			if ( ! $char ) {
				return new WP_REST_Response( array(
					'ok'      => false,
					'code'    => 'guru_not_found',
					'message' => sprintf( 'Guru #%d không tồn tại.', $args['character_id'] ),
				), 404 );
			}
			$status = isset( $char->status ) ? strtolower( (string) $char->status ) : '';
			$allowed_status = array( 'active', 'published' );
			if ( ! in_array( $status, $allowed_status, true ) ) {
				return new WP_REST_Response( array(
					'ok'      => false,
					'code'    => 'guru_not_publishable',
					'message' => sprintf(
						'Không thể bind kênh cho Guru #%d (status=%s). Yêu cầu status ∈ {active, published}.',
						$args['character_id'],
						$status !== '' ? $status : 'empty'
					),
					'data'    => array( 'character_id' => $args['character_id'], 'status' => $status ),
				), 400 );
			}
		}
		$id = BizCity_Channel_Binding::upsert( $args );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Upsert failed' ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'id' => $id ) ), 200 );
	}

	public static function rest_binding_disable( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Binding class missing' ), 500 );
		}
		$id = (int) $req['id'];
		$ok = BizCity_Channel_Binding::disable( $id );
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), $ok ? 200 : 500 );
	}

	public static function rest_stats() {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Webhook log class missing' ), 500 );
		}
		return new WP_REST_Response( array(
			'ok'   => true,
			'data' => array(
				'counts_by_platform' => BizCity_Webhook_Log::counts_by_platform(),
				'partitions'         => BizCity_Webhook_Log::list_partitions(),
				'today'              => BizCity_Webhook_Log::today_key(),
				'ttl_days'           => BizCity_Webhook_Log::TTL_DAYS,
				'storage'            => 'file',
				'root_dir'           => str_replace( ABSPATH, '', BizCity_Webhook_Log::root_dir() ),
			),
		), 200 );
	}

	/**
	 * Return the Twin Guru roster (compact) for select dropdowns.
	 */
	public static function rest_gurus() {
		$out = array();
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$db = BizCity_Knowledge_Database::instance();
			$rows = $db->get_characters( array( 'limit' => 200 ) );
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'id'     => (int) $r->id,
					'name'   => (string) $r->name,
					'slug'   => isset( $r->slug ) ? (string) $r->slug : '',
					'avatar' => isset( $r->avatar ) ? (string) $r->avatar : '',
					'status' => isset( $r->status ) ? (string) $r->status : '',
				);
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), 200 );
	}

	/**
	 * Return registered channel adapters + best-effort account list.
	 *
	 * Adapter classes can optionally implement get_known_accounts(): array of
	 * {id,label} so the picker can offer real values. Falls back to empty.
	 */
	public static function rest_channels() {
		$out = array();
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$bridge = BizCity_Gateway_Bridge::instance();
			$adapters = method_exists( $bridge, 'get_adapters' ) ? (array) $bridge->get_adapters() : array();
			foreach ( $adapters as $platform => $adapter ) {
				$accounts = array();
				if ( is_object( $adapter ) && method_exists( $adapter, 'get_known_accounts' ) ) {
					$accounts = (array) $adapter->get_known_accounts();
				}
				$label = is_object( $adapter ) && method_exists( $adapter, 'label' ) ? (string) $adapter->label() : (string) $platform;
				$out[] = array(
					'platform' => (string) $platform,
					'label'    => $label,
					'accounts' => $accounts,
				);
			}
		}
		// Always include the canonical 5 platforms for easier first-time binding.
		$known = array(
			'FB_MESS'      => 'Facebook Messenger',
			'FB_FEED'      => 'Facebook Page comments',
			'ZALO_BOT'     => 'Zalo Bot (OA)',
			'ZALO_HOTLINE' => 'Zalo Hotline',
			'WEBCHAT'      => 'On-site WebChat',
			'TELEGRAM'     => 'Telegram',
		);
		$existing = array_column( $out, 'platform' );
		foreach ( $known as $p => $lbl ) {
			if ( ! in_array( $p, $existing, true ) ) {
				$out[] = array( 'platform' => $p, 'label' => $lbl, 'accounts' => array() );
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), 200 );
	}
}
