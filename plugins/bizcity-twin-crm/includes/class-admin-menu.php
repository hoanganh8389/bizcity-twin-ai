<?php
/**
 * BizCity CRM — Admin Menu + script enqueue.
 *
 * Top-level menu `BizCity CRM` (slug `bizcity-crm`) — operations surface.
 * Observability lives under Intent Monitor (R-CRM-6, R-IMN-1).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Admin_Menu {

	const SLUG = 'bizcity-crm';

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register(): void {
		add_menu_page(
			'BizCity CRM',
			'BizCity CRM',
			'manage_options',
			self::SLUG,
			array( $this, 'render_inbox_page' ),
			'dashicons-format-chat',
			26
		);
		add_submenu_page( self::SLUG, 'Inbox',    'Inbox',    'manage_options', self::SLUG,                array( $this, 'render_inbox_page' ) );
		add_submenu_page( self::SLUG, 'Channels', 'Channels', 'manage_options', self::SLUG . '-channels', array( $this, 'render_channels_page' ) );
		add_submenu_page( self::SLUG, 'Add Inbox', 'Add Inbox', 'manage_options', self::SLUG . '-add-inbox', array( $this, 'render_add_inbox_wizard' ) );
		add_submenu_page( self::SLUG, 'Settings', 'Settings', 'manage_options', self::SLUG . '-settings', array( $this, 'render_settings_page' ) );
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, self::SLUG ) === false ) {
			return;
		}

		$dir = BIZCITY_CRM_DIR . '/assets/dist/';
		$url = BIZCITY_CRM_URL . '/assets/dist/';

		// Prefer Vite-built bundle if present.
		$has_built = is_dir( $dir ) && file_exists( $dir . 'inbox-app.js' );

		if ( $has_built ) {
			$css_path = $dir . 'inbox-app.css';
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'bizcity-crm-inbox-app',
					$url . 'inbox-app.css',
					 array(),
					 (string) ( @filemtime( $css_path ) ?: time() )
				);
			}
			wp_enqueue_script(
				'bizcity-crm-inbox-app',
				$url . 'inbox-app.js',
				array( 'wp-element' ),
				(string) ( @filemtime( $dir . 'inbox-app.js' ) ?: time() ),
				true
			);
		} else {
			// Zero-build fallback — vanilla React via wp.element.
			$fb_css = BIZCITY_CRM_DIR . '/frontend/fallback/inbox.css';
			$fb_js  = BIZCITY_CRM_DIR . '/frontend/fallback/inbox.js';
			wp_enqueue_style(
				'bizcity-crm-inbox-fallback',
				BIZCITY_CRM_URL . '/frontend/fallback/inbox.css',
				array(),
				(string) ( @filemtime( $fb_css ) ?: time() )
			);
			wp_enqueue_script(
				'bizcity-crm-inbox-fallback',
				BIZCITY_CRM_URL . '/frontend/fallback/inbox.js',
				array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
				(string) ( @filemtime( $fb_js ) ?: time() ),
				true
			);
		}

		// Bootstrap config — exposed to both built and fallback bundles.
		$config = array(
			'restUrl'          => esc_url_raw( rest_url( BIZCITY_CRM_REST_NS . '/' ) ),
			'schedulerRestUrl' => esc_url_raw( rest_url( 'bizcity-scheduler/v1/' ) ),
			// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — expose channel gateway base for FB retry mutation
			'channelRestUrl'   => esc_url_raw( rest_url( 'bizcity-channel/v1/' ) ),
			// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — expose automation engine base for care recipe calls
			'automationRestUrl' => esc_url_raw( rest_url( 'bizcity-automation/v1/' ) ),
			'bzdocRestUrl'     => esc_url_raw( rest_url( 'bzdoc/v1/' ) ),
			'twinUrl'          => esc_url_raw( home_url( '/twin/' ) ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'pollMs'           => 3000,
			// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CRM-B03 — expose woo_active so ChannelsTab integration panel shows correct status
			'woo_active'       => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ),
			// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — expose current user ID so FE can resolve 'me' filter without extra fetch
			'currentUserId'    => get_current_user_id(),
			'isManager'        => ( current_user_can( 'manage_options' ) || current_user_can( 'bizcity_manager' ) ),
			'i18n'             => array(
				'title'           => __( 'BizCity CRM Inbox', 'bizcity-twin-crm' ),
				'noChannels'      => __( 'Chưa có inbox nào — hãy kết nối Facebook Page hoặc Zalo OA.', 'bizcity-twin-crm' ),
				'noConversations' => __( 'Chưa có hội thoại.', 'bizcity-twin-crm' ),
				'selectConv'      => __( 'Chọn một hội thoại để xem nội dung.', 'bizcity-twin-crm' ),
			),
		);
		$inline = 'window.BIZCITY_CRM_BOOT = ' . wp_json_encode( $config ) . ';';
		wp_add_inline_script(
			$has_built ? 'bizcity-crm-inbox-app' : 'bizcity-crm-inbox-fallback',
			$inline,
			'before'
		);

		// Remove WP admin `.wrap` margin so the CRM shell fills the content area flush.
		$style_handle = $has_built ? 'bizcity-crm-inbox-app' : 'bizcity-crm-inbox-fallback';
		wp_add_inline_style( $style_handle, '.wrap{margin:0 !important}' );

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — enqueue WP Media Library so window.wp.media
		// is available in the CRM SPA (needed for PDF/ebook attachment picker in Email rules).
		wp_enqueue_media();
	}

	/* ------- pages ------- */

	public function render_inbox_page(): void {
		echo '<div class="wrap">';
		echo '<div id="bizcity-crm-inbox-root" style="min-height:600px;"></div>';
		echo '</div>';
	}

	public function render_channels_page(): void {
		$adapters = BizCity_CRM_Channel_Registry::all();
		$wizard_url = admin_url( 'admin.php?page=' . self::SLUG . '-add-inbox' );
		echo '<div class="wrap"><h1>' . esc_html__( 'BizCity CRM — Channels', 'bizcity-twin-crm' );
		echo ' <a class="page-title-action" href="' . esc_url( $wizard_url ) . '">' . esc_html__( '+ Add Inbox', 'bizcity-twin-crm' ) . '</a></h1>';
		echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Label</th><th>Capabilities</th><th>Wizard</th></tr></thead><tbody>';
		foreach ( $adapters as $a ) {
			$has_wizard = method_exists( $a, 'setup_form_schema' );
			echo '<tr>';
			echo '<td><code>' . esc_html( $a->code() ) . '</code></td>';
			echo '<td>' . esc_html( $a->label() ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $a->capabilities() ) ) . '</td>';
			if ( $has_wizard ) {
				echo '<td><a class="button" href="' . esc_url( add_query_arg( 'channel', $a->code(), $wizard_url ) ) . '">→ ' . esc_html__( 'Setup', 'bizcity-twin-crm' ) . '</a></td>';
			} else {
				echo '<td>—</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Existing inboxes table.
		$inboxes = BizCity_CRM_Repository::list_inboxes();
		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Existing inboxes', 'bizcity-twin-crm' ) . '</h2>';
		if ( empty( $inboxes ) ) {
			echo '<p><em>' . esc_html__( 'No inboxes yet — use the wizard to add one.', 'bizcity-twin-crm' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Channel</th><th>Ref</th><th>Name</th><th>Active</th><th>Created</th></tr></thead><tbody>';
			foreach ( $inboxes as $r ) {
				echo '<tr>';
				echo '<td>' . (int) $r['id'] . '</td>';
				echo '<td>' . esc_html( $r['channel_type'] ) . '</td>';
				echo '<td><code>' . esc_html( $r['channel_ref_id'] ) . '</code></td>';
				echo '<td>' . esc_html( $r['name'] ) . '</td>';
				echo '<td>' . ( (int) $r['is_active'] === 1 ? '✓' : '—' ) . '</td>';
				echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * M7.W1 — "Add Inbox" wizard (PHP shell + vanilla JS via wp.apiFetch).
	 */
	public function render_add_inbox_wizard(): void {
		$adapters = BizCity_CRM_Channel_Registry::all();
		$preselect = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
		$rest_root = esc_url_raw( rest_url( BIZCITY_CRM_REST_NS . '/' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );

		// Channel cards data.
		$cards = array();
		foreach ( $adapters as $a ) {
			if ( ! method_exists( $a, 'setup_form_schema' ) ) { continue; }
			$cards[] = array(
				'code'         => $a->code(),
				'label'        => $a->label(),
				'capabilities' => $a->capabilities(),
			);
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity CRM — Add Inbox', 'bizcity-twin-crm' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Wizard 3 bước: chọn kênh → cấu hình → verify & tạo inbox.', 'bizcity-twin-crm' ); ?></p>
			<div id="bizcity-crm-add-inbox-root"
				data-rest="<?php echo esc_attr( $rest_root ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-channels="<?php echo esc_attr( wp_json_encode( $cards ) ); ?>"
				data-preselect="<?php echo esc_attr( $preselect ); ?>"
				style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin-top:16px;">
				<p><em><?php esc_html_e( 'Loading wizard…', 'bizcity-twin-crm' ); ?></em></p>
			</div>
		</div>
		<style>
			.bcw-step-nav { display:flex; gap:8px; margin-bottom:16px; }
			.bcw-step-nav span { padding:6px 12px; border-radius:16px; background:#f0f0f1; font-size:12px; }
			.bcw-step-nav span.active { background:#2271b1; color:#fff; }
			.bcw-step-nav span.done { background:#00a32a; color:#fff; }
			.bcw-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
			.bcw-card { border:1px solid #ddd; border-radius:6px; padding:14px; cursor:pointer; transition:all .15s; background:#fff; }
			.bcw-card:hover { border-color:#2271b1; box-shadow:0 2px 6px rgba(34,113,177,.15); }
			.bcw-card.selected { border-color:#2271b1; background:#f0f6fc; }
			.bcw-card h3 { margin:0 0 6px 0; font-size:14px; }
			.bcw-card .caps { font-size:11px; color:#666; }
			.bcw-form-row { margin-bottom:14px; }
			.bcw-form-row label { display:block; font-weight:600; margin-bottom:4px; }
			.bcw-form-row input[type=text],.bcw-form-row input[type=password],.bcw-form-row input[type=url],.bcw-form-row textarea,.bcw-form-row select { width:100%; max-width:520px; }
			.bcw-form-row .help { font-size:12px; color:#666; margin-top:4px; }
			.bcw-webhook-box { background:#f6f7f7; border-left:4px solid #2271b1; padding:12px; margin-top:18px; font-family:monospace; font-size:12px; word-break:break-all; }
			.bcw-msg { padding:10px 14px; border-radius:4px; margin:14px 0; }
			.bcw-msg.error { background:#fcf0f1; border-left:4px solid #d63638; color:#8a1d1f; }
			.bcw-msg.success { background:#edfaef; border-left:4px solid #00a32a; color:#0a4f1a; }
			.bcw-actions { margin-top:20px; display:flex; gap:10px; }
		</style>
		<script>
		(function(){
			var root = document.getElementById('bizcity-crm-add-inbox-root');
			if (!root) return;
			var REST  = root.dataset.rest;
			var NONCE = root.dataset.nonce;
			var CHANNELS = JSON.parse(root.dataset.channels || '[]');
			var PRE = root.dataset.preselect || '';

			var state = { step:1, channel:null, schema:null, config:{}, verify:null, created:null };

			function api(path, opts){
				opts = opts || {};
				return fetch(REST + path.replace(/^\//,''), {
					method: opts.method || 'GET',
					credentials: 'same-origin',
					headers: Object.assign({
						'Content-Type':'application/json',
						'X-WP-Nonce': NONCE
					}, opts.headers || {}),
					body: opts.body ? JSON.stringify(opts.body) : undefined
				}).then(function(r){ return r.json(); });
			}

			function h(tag, attrs, kids){
				var el = document.createElement(tag);
				if (attrs) Object.keys(attrs).forEach(function(k){
					if (k === 'class') el.className = attrs[k];
					else if (k === 'html') el.innerHTML = attrs[k];
					else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') el.addEventListener(k.slice(2), attrs[k]);
					else el.setAttribute(k, attrs[k]);
				});
				(kids || []).forEach(function(c){
					if (c == null) return;
					el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
				});
				return el;
			}

			function nav(){
				var labels = ['1. Chọn kênh','2. Cấu hình','3. Verify & tạo'];
				var nav = h('div',{class:'bcw-step-nav'});
				labels.forEach(function(l, i){
					var s = h('span',{}, [l]);
					if ((i+1) === state.step) s.className = 'active';
					else if ((i+1) < state.step) s.className = 'done';
					nav.appendChild(s);
				});
				return nav;
			}

			function render(){
				root.innerHTML = '';
				root.appendChild(nav());
				if (state.step === 1) renderStep1();
				else if (state.step === 2) renderStep2();
				else if (state.step === 3) renderStep3();
			}

			function renderStep1(){
				var box = h('div',{class:'bcw-cards'});
				CHANNELS.forEach(function(c){
					var card = h('div', {
						class: 'bcw-card' + (state.channel === c.code ? ' selected' : ''),
						onclick: function(){ state.channel = c.code; render(); }
					}, [
						h('h3',{},[c.label]),
						h('div',{class:'caps'},['Capabilities: ' + (c.capabilities||[]).join(', ')]),
						h('div',{class:'caps'},['Code: ' + c.code])
					]);
					box.appendChild(card);
				});
				root.appendChild(box);

				var actions = h('div',{class:'bcw-actions'},[
					h('button',{
						class:'button button-primary',
						disabled: state.channel ? null : 'disabled',
						onclick: function(){ if (state.channel) loadSchema(); }
					},['Tiếp tục →'])
				]);
				root.appendChild(actions);
			}

			function loadSchema(){
				api('/channels/' + encodeURIComponent(state.channel)).then(function(res){
					if (!res || !res.ok) {
						alert('Không tải được schema: ' + (res && res.error || 'unknown'));
						return;
					}
					state.schema = res.data && res.data.schema || { fields: [] };
					state.config = {};
					(state.schema.fields || []).forEach(function(f){
						if (f.default !== undefined) state.config[f.name] = f.default;
					});
					state.step = 2;
					render();
				});
			}

			function renderStep2(){
				var schema = state.schema || { fields: [] };
				var form = h('div',{});
				(schema.fields || []).forEach(function(f){
					var row = h('div',{class:'bcw-form-row'});
					row.appendChild(h('label',{}, [f.label + (f.required ? ' *' : '')]));
					var input;
					if (f.type === 'textarea') {
						input = h('textarea',{rows:3, name:f.name, placeholder:f.placeholder || ''});
						input.value = state.config[f.name] || '';
					} else if (f.type === 'select') {
						input = h('select',{name:f.name});
						Object.keys(f.options || {}).forEach(function(v){
							var o = h('option',{value:v}, [f.options[v]]);
							if (state.config[f.name] === v) o.setAttribute('selected','');
							input.appendChild(o);
						});
					} else {
						input = h('input',{type: f.type === 'password' ? 'password' : 'text', name:f.name, placeholder: f.placeholder || ''});
						input.value = state.config[f.name] || '';
					}
					input.addEventListener('input', function(e){ state.config[f.name] = e.target.value; });
					input.addEventListener('change', function(e){ state.config[f.name] = e.target.value; });
					row.appendChild(input);
					if (f.help) row.appendChild(h('div',{class:'help'},[f.help]));
					form.appendChild(row);
				});

				if (schema.webhook) {
					var wh = h('div',{class:'bcw-webhook-box'},[
						h('strong',{},['Webhook URL (' + schema.webhook.method + '): ']),
						h('br',{}),
						schema.webhook.url,
						h('div',{style:'color:#555;font-family:sans-serif;margin-top:6px;'},[schema.webhook.note || ''])
					]);
					form.appendChild(wh);
				}

				root.appendChild(form);

				var actions = h('div',{class:'bcw-actions'},[
					h('button',{class:'button', onclick:function(){ state.step = 1; render(); }},['← Quay lại']),
					h('button',{class:'button button-primary', onclick:doVerify},['Verify & next →'])
				]);
				root.appendChild(actions);
			}

			function doVerify(){
				api('/channels/' + encodeURIComponent(state.channel) + '/verify', {
					method:'POST', body:{ config: state.config }
				}).then(function(res){
					if (!res || !res.ok) {
						alert('Verify lỗi REST: ' + (res && res.error || 'unknown'));
						return;
					}
					state.verify = res.data;
					state.step = 3;
					render();
				});
			}

			function renderStep3(){
				var v = state.verify || {};
				var box = h('div',{});
				if (state.created) {
					box.appendChild(h('div',{class:'bcw-msg success'},[
						'Inbox đã tạo: ID #' + state.created.inbox_id + ' — ' + (state.created.name || '')
					]));
					(state.created.verify_hints || []).forEach(function(t){
						box.appendChild(h('div',{class:'bcw-msg'},[t]));
					});
					box.appendChild(h('div',{class:'bcw-actions'},[
						h('a',{class:'button button-primary', href: '<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-channels' ) ); ?>'},['Xem danh sách inbox']),
						h('a',{class:'button', href: window.location.pathname + '?page=<?php echo esc_attr( self::SLUG ); ?>-add-inbox'},['+ Add another'])
					]));
				} else if (v.ok) {
					box.appendChild(h('div',{class:'bcw-msg success'},[
						'✓ Verify OK — sẽ tạo inbox: ' + (v.name || state.channel) + ' (ref ' + (v.channel_ref_id || '?') + ')'
					]));
					(v.hints || []).forEach(function(t){
						box.appendChild(h('div',{class:'bcw-msg'},[t]));
					});
					box.appendChild(h('div',{class:'bcw-actions'},[
						h('button',{class:'button', onclick:function(){ state.step = 2; render(); }},['← Sửa cấu hình']),
						h('button',{class:'button button-primary', onclick:doCreate},['Tạo inbox →'])
					]));
				} else {
					box.appendChild(h('div',{class:'bcw-msg error'},[
						'✗ Verify thất bại: ' + (v.error || 'unknown')
					]));
					(v.hints || []).forEach(function(t){
						box.appendChild(h('div',{class:'bcw-msg'},[t]));
					});
					box.appendChild(h('div',{class:'bcw-actions'},[
						h('button',{class:'button', onclick:function(){ state.step = 2; render(); }},['← Sửa cấu hình'])
					]));
				}
				root.appendChild(box);
			}

			function doCreate(){
				api('/inboxes', {
					method:'POST',
					body:{ channel_type: state.channel, config: state.config }
				}).then(function(res){
					if (!res || !res.ok) {
						alert('Tạo inbox lỗi: ' + (res && res.error || 'unknown'));
						return;
					}
					state.created = res.data;
					render();
				});
			}

			// Pre-select channel from query string.
			if (PRE && CHANNELS.some(function(c){ return c.code === PRE; })) {
				state.channel = PRE;
				loadSchema();
			} else {
				render();
			}
		})();
		</script>
		<?php
	}

	public function render_settings_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BizCity CRM — Settings', 'bizcity-twin-crm' ) . '</h1>';
		echo '<p>' . esc_html__( 'Auto-reply per inbox, business hours và default notebook sẽ có ở M3.', 'bizcity-twin-crm' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=bizcity-crm-sprint-diag' ) ) . '">→ Sprint Diagnostic</a></p>';
		echo '</div>';
	}
}
