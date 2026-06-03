<?php
/**
 * Channel-binding UI injector for the Twin Guru (Characters) admin page.
 *
 * PHASE 0.33 M4.1 — đưa việc bind kênh vào ngay tấm thẻ Guru,
 * bên cạnh sources/chats/rating, để user không phải qua trang Inspector.
 *
 * Strategy:
 *  - Hook `admin_enqueue_scripts`, detect any page slug containing
 *    `bizcity-knowledge-characters` (matches list view + edit view).
 *  - Inject inline JS that:
 *      1. Fetches `/inspector/bindings` + `/inspector/channels`,
 *      2. Groups bindings by character_id,
 *      3. Mounts a `.bk-char-card-channels` block inside each
 *         `.bk-char-card[data-character-id="…"]` card,
 *      4. Opens a small inline form to add a new binding (POST
 *         `/inspector/bindings`) — Guru ID is pre-filled from the card.
 *
 * REST: same `bizcity/cg/v1` namespace as the Inspector.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.2
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Character_Page_Binding_UI {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue( $hook ): void {
		if ( ! is_string( $hook ) || strpos( $hook, 'bizcity-knowledge-characters' ) === false ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$handle = 'bizcity-cg-character-bind';
		// Empty stub script so we can attach inline JS via wp_add_inline_script + boot data.
		wp_register_script( $handle, '', array( 'jquery' ), '1.5.2', true );

		$boot = array(
			'restRoot' => esc_url_raw( rest_url( self::NAMESPACE_V1 ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'i18n'     => array(
				'channels'    => __( 'Channels', 'bizcity' ),
				'addChannel'  => __( '+ Bind channel', 'bizcity' ),
				'noBindings'  => __( 'no channel bound', 'bizcity' ),
				'platform'    => __( 'Platform', 'bizcity' ),
				'account'     => __( 'Account ID', 'bizcity' ),
				'cancel'      => __( 'Cancel', 'bizcity' ),
				'save'        => __( 'Save', 'bizcity' ),
				'saving'      => __( 'Saving…', 'bizcity' ),
				'disable'     => __( 'disable', 'bizcity' ),
				'confirmOff'  => __( 'Disable this binding?', 'bizcity' ),
				'failed'      => __( 'Save failed', 'bizcity' ),
				'manage'      => __( 'manage in CRM', 'bizcity' ),
				'manageUrl'   => admin_url( 'admin.php?page=bizcity-crm-webhook#tab-bindings' ),
			),
		);

		wp_add_inline_script( $handle, 'window.BIZCITY_CG_CHAR_BIND = ' . wp_json_encode( $boot ) . ';', 'before' );
		wp_add_inline_script( $handle, self::js_body() );
		wp_add_inline_style( 'wp-admin', self::css_body() );
		wp_enqueue_script( $handle );
	}

	private static function css_body(): string {
		return <<<CSS
		.bk-char-card-channels{margin:8px 0;padding:8px 10px;background:#f8fafc;border-radius:6px;border:1px solid #e5e7eb;font-size:12px;}
		.bk-char-card-channels-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
		.bk-char-card-channels-head strong{font-size:11px;color:#374151;text-transform:uppercase;letter-spacing:.5px;}
		.bk-char-card-channels-head a{font-size:11px;color:#6b7280;text-decoration:none;}
		.bk-char-card-channels-head a:hover{color:#2563eb;}
		.bk-channel-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;margin:2px 4px 2px 0;border-radius:10px;background:#dbeafe;color:#1e40af;font-weight:600;font-size:11px;}
		.bk-channel-pill.is-disabled{background:#fee2e2;color:#991b1b;}
		.bk-channel-pill code{background:transparent;padding:0;font-size:10px;color:inherit;opacity:.8;}
		.bk-channel-pill .bk-mode{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:0 4px;border-radius:6px;background:rgba(255,255,255,.7);color:inherit;}
		.bk-channel-pill button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:13px;line-height:1;padding:0 0 0 2px;opacity:.7;}
		.bk-channel-pill button:hover{opacity:1;color:#b91c1c;}
		.bk-bind-add{display:inline-block;margin-top:4px;padding:2px 8px;border:1px dashed #93c5fd;border-radius:10px;background:transparent;color:#2563eb;font-size:11px;cursor:pointer;}
		.bk-bind-add:hover{background:#eff6ff;}
		.bk-bind-form{display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap;}
		.bk-bind-form select,.bk-bind-form input{font-size:12px;padding:2px 6px;}
		.bk-bind-form button{font-size:11px;padding:2px 8px;cursor:pointer;}
		.bk-bind-empty{color:#9ca3af;font-style:italic;}
CSS;
	}

	private static function js_body(): string {
		return <<<'JS'
		(function(){
			if (!window.BIZCITY_CG_CHAR_BIND) return;
			var CFG = window.BIZCITY_CG_CHAR_BIND;
			var REST = CFG.restRoot, NONCE = CFG.nonce, T = CFG.i18n;
			var CHANNELS = [];

			function $(s, r) { return (r||document).querySelector(s); }
			function $$(s, r) { return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
			function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }
			function api(url, opts){
				return fetch(url, Object.assign({
					credentials:'same-origin',
					headers: { 'X-WP-Nonce': NONCE, 'Accept':'application/json', 'Content-Type':'application/json' }
				}, opts || {})).then(function(r){ return r.json(); });
			}

			function boot(){
				var cards = $$('.bk-char-card[data-character-id]');
				if (!cards.length) return;
				Promise.all([
					api(REST + '/inspector/bindings'),
					api(REST + '/inspector/channels')
				]).then(function(res){
					var bindings = (res[0] && res[0].data) || [];
					CHANNELS    = (res[1] && res[1].data) || [];
					var byChar = {};
					bindings.forEach(function(b){
						var k = String(b.character_id || 0);
						(byChar[k] = byChar[k] || []).push(b);
					});
					cards.forEach(function(card){
						var cid = card.getAttribute('data-character-id');
						mountSection(card, cid, byChar[String(cid)] || []);
					});
				}).catch(function(){ /* fail silently — keep card UI usable */ });
			}

			function mountSection(card, cid, bindings){
				var existing = card.querySelector('.bk-char-card-channels');
				if (existing) existing.remove();
				var wrap = document.createElement('div');
				wrap.className = 'bk-char-card-channels';
				wrap.setAttribute('data-character-id', cid);
				wrap.innerHTML =
					'<div class="bk-char-card-channels-head">' +
						'<strong>' + esc(T.channels) + '</strong>' +
						'<a href="' + esc(T.manageUrl) + '" target="_blank" rel="noopener">' + esc(T.manage) + ' →</a>' +
					'</div>' +
					'<div class="bk-bind-pills"></div>' +
					'<div class="bk-bind-form-host"></div>';

				// Place between .bk-char-card-stats and .bk-char-card-meta if possible.
				var stats = card.querySelector('.bk-char-card-stats');
				var meta  = card.querySelector('.bk-char-card-meta');
				if (stats && stats.parentNode) {
					stats.parentNode.insertBefore(wrap, meta || stats.nextSibling);
				} else {
					card.appendChild(wrap);
				}

				renderPills(wrap, cid, bindings);
			}

			function renderPills(wrap, cid, bindings){
				var pills = wrap.querySelector('.bk-bind-pills');
				if (!bindings.length) {
					pills.innerHTML = '<span class="bk-bind-empty">' + esc(T.noBindings) + '</span> ';
				} else {
					pills.innerHTML = bindings.map(function(b){
						var cls = (b.status === 'active') ? '' : ' is-disabled';
						var mode = b.mode || 'auto';
						return '<span class="bk-channel-pill' + cls + '" data-binding-id="' + esc(b.id) + '">' +
							esc(b.platform) + ' <code>' + esc(b.account_id) + '</code>' +
							'<span class="bk-mode" title="reply mode">' + esc(mode) + '</span>' +
							'<button type="button" title="' + esc(T.disable) + '">×</button>' +
						'</span>';
					}).join('');
				}
				// + Bind button
				var addBtn = document.createElement('button');
				addBtn.type = 'button';
				addBtn.className = 'bk-bind-add';
				addBtn.textContent = T.addChannel;
				pills.appendChild(addBtn);

				addBtn.addEventListener('click', function(){ openForm(wrap, cid); });

				$$('.bk-channel-pill button', pills).forEach(function(btn){
					btn.addEventListener('click', function(){
						var pill = btn.closest('.bk-channel-pill');
						var id = pill && pill.getAttribute('data-binding-id');
						if (!id) return;
						if (!confirm(T.confirmOff + ' #' + id)) return;
						api(REST + '/inspector/bindings/' + id + '/disable', { method:'POST', body:'{}' })
							.then(function(){ refreshOne(wrap, cid); });
					});
				});
			}

			function openForm(wrap, cid){
				var host = wrap.querySelector('.bk-bind-form-host');
				if (host.querySelector('form')) return;
				var platOpts = '<option value="">— ' + esc(T.platform) + ' —</option>' +
					CHANNELS.map(function(c){
						return '<option value="' + esc(c.platform) + '">' + esc(c.platform) + '</option>';
					}).join('');
				host.innerHTML =
					'<form class="bk-bind-form">' +
						'<select name="platform" required>' + platOpts + '</select>' +
						'<input type="text" name="account_id" placeholder="' + esc(T.account) + ' (or *)" required style="width:140px">' +
						'<select name="mode" title="reply mode">' +
							'<option value="auto">auto</option>' +
							'<option value="manual">manual</option>' +
							'<option value="hybrid">hybrid</option>' +
							'<option value="roundrobin">roundrobin</option>' +
						'</select>' +
						'<button type="submit" class="button button-small button-primary">' + esc(T.save) + '</button>' +
						'<button type="button" class="button button-small bk-cancel">' + esc(T.cancel) + '</button>' +
						'<span class="bk-bind-msg" style="font-size:11px;margin-left:6px"></span>' +
					'</form>';
				var form = host.querySelector('form');
				host.querySelector('.bk-cancel').addEventListener('click', function(){ host.innerHTML = ''; });
				form.addEventListener('submit', function(e){
					e.preventDefault();
					var fd = new FormData(form);
					var msg = host.querySelector('.bk-bind-msg');
					msg.textContent = T.saving;
					api(REST + '/inspector/bindings', { method:'POST', body: JSON.stringify({
						platform:     fd.get('platform'),
						account_id:   (fd.get('account_id') || '*').trim(),
						character_id: parseInt(cid, 10),
						mode:         fd.get('mode') || 'auto',
						auto_reply:   (fd.get('mode') === 'manual') ? 0 : 1
					})}).then(function(res){
						if (res && res.ok) {
							host.innerHTML = '';
							refreshOne(wrap, cid);
						} else {
							msg.textContent = (res && res.message) || T.failed;
						}
					});
				});
			}

			function refreshOne(wrap, cid){
				api(REST + '/inspector/bindings?character_id=' + encodeURIComponent(cid)).then(function(res){
					var rows = (res && res.data) || [];
					renderPills(wrap, cid, rows);
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', boot);
			} else {
				boot();
			}
		})();
JS;
	}
}
