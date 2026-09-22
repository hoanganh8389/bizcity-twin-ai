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
		if ( class_exists( 'BizCity_Admin_Menu', false ) ) {
			return;
		}
		// [2026-08-11 Johnny Chu] PHASE-1.26 — bundled CRM is owned by the unified Workspace registry.
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E1 — Inbox entry uses the employee capability; Channels/Add Inbox/Settings stay admin-only.
		$inbox_cap = self::inbox_cap();
		$surfaces = function_exists( 'bizcity_crm_surface_descriptors' ) ? bizcity_crm_surface_descriptors() : array();
		$inbox = $surfaces['crm.inbox'] ?? array( 'slug' => self::SLUG, 'action' => 'crm.inbox.open', 'render' => array( $this, 'render_inbox_page' ) );
		add_menu_page(
			'BizCity CRM',
			'BizCity CRM',
			$inbox_cap,
			(string) $inbox['slug'],
			array( $this, 'render_inbox_page' ),
			'dashicons-format-chat',
			26
		);
		add_submenu_page( self::SLUG, 'Inbox', 'Inbox', $inbox_cap, self::SLUG, array( $this, 'render_inbox_page' ) );
		foreach ( array( 'crm.channels' => array( 'Channels', 'Channels' ), 'crm.add_inbox' => array( 'Add Inbox', 'Add Inbox' ), 'crm.settings' => array( 'Settings', 'Settings' ), 'crm.identity_queue' => array( 'Identity Queue', 'Identity Queue' ) ) as $id => $label ) {
			if ( empty( $surfaces[ $id ] ) ) { continue; }
			$surface = $surfaces[ $id ];
			$cap = class_exists( 'BizCity_CRM_Authority' ) ? BizCity_CRM_Authority::menu_cap( (string) $surface['action'] ) : 'manage_options';
			add_submenu_page( self::SLUG, $label[0], $label[1], $cap, (string) $surface['slug'], $surface['render'] );
		}
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — identity conflict review and maintenance backfill page.
		add_submenu_page( self::SLUG, 'Identity Queue', 'Identity Queue', 'bizcity_crm_manage_rules', self::SLUG . '-identity-queue', array( $this, 'render_identity_queue_page' ) );
	}

	/** Return the canonical CRM surface descriptors with callbacks bound to this owner. */
	public function surfaces_for( string $placement = 'twin_plugins' ): array {
		$surfaces = function_exists( 'bizcity_crm_surface_descriptors' ) ? bizcity_crm_surface_descriptors() : array();
		foreach ( $surfaces as $id => &$surface ) {
			$surface['placement'] = $placement;
			if ( isset( $surface['render'][1] ) ) {
				$surface['callback'] = array( $this, (string) $surface['render'][1] );
			}
		}
		unset( $surface );
		return $surfaces;
	}

	/**
	 * Capability for the CRM Inbox menu entry (shared with the unified Workspace registry).
	 */
	public static function inbox_cap(): string {
		// [2026-09-19 Johnny Chu] PHASE-0.60 C2 — all CRM placements ask the canonical action resolver, not WordPress capabilities directly.
		if ( class_exists( 'BizCity_CRM_Authority' ) ) {
			return BizCity_CRM_Authority::menu_cap( 'crm.inbox.open' );
		}
		return class_exists( 'BizCity_CRM_Capabilities' ) && method_exists( 'BizCity_CRM_Capabilities', 'inbox_menu_cap' )
			? BizCity_CRM_Capabilities::inbox_menu_cap()
			: 'manage_options';
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, self::SLUG ) === false ) {
			return;
		}

		$dir = BIZCITY_CRM_DIR . '/assets/dist/';
		$url = BIZCITY_CRM_URL . '/assets/dist/';

		// [2026-08-04 Johnny Chu] PHASE-0.48-HOTFIX — built mode requires both assets; never silently load JS without CSS.
		$has_built = is_dir( $dir )
			&& is_readable( $dir . 'inbox-app.js' )
			&& is_readable( $dir . 'inbox-app.css' );

		if ( $has_built ) {
			$css_path = $dir . 'inbox-app.css';
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'bizcity-crm-inbox-app',
					$url . 'inbox-app.css',
					 array(),
					self::asset_version( $css_path )
				);
			}
			wp_enqueue_script(
				'bizcity-crm-inbox-app',
				$url . 'inbox-app.js',
				array( 'wp-element' ),
				self::asset_version( $dir . 'inbox-app.js' ),
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
				self::asset_version( $fb_css )
			);
			wp_enqueue_script(
				'bizcity-crm-inbox-fallback',
				BIZCITY_CRM_URL . '/frontend/fallback/inbox.js',
				array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
				self::asset_version( $fb_js ),
				true
			);
		}

		// [2026-09-20 Johnny Chu] PHASE-0.60 C60-M08 — one canonical block for FE, sourced from
		// the action resolver rather than a raw WP capability name per flag.
		$crm_boot_permissions = array();
		if ( class_exists( 'BizCity_CRM_Authority' ) ) {
			foreach ( array( 'crm.inbox.open', 'crm.inbox.read', 'crm.inbox.handle', 'crm.channel.self_connect', 'crm.channel.manage', 'crm.settings.manage', 'crm.rules.manage', 'crm.team.manage', 'crm.work.lead', 'crm.reports.view', 'crm.sales.write', 'crm.ai.use' ) as $crm_boot_action ) {
				$crm_boot_permissions[ $crm_boot_action ] = BizCity_CRM_Authority::can( $crm_boot_action )['ok'];
			}
		}

		// Bootstrap config — exposed to both built and fallback bundles.
		$config = array(
			'restUrl'          => esc_url_raw( rest_url( BIZCITY_CRM_REST_NS . '/' ) ),
			'schedulerRestUrl' => esc_url_raw( rest_url( 'bizcity-scheduler/v1/' ) ),
			// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — expose channel gateway base for FB retry mutation
			'channelRestUrl'   => esc_url_raw( rest_url( 'bizcity-channel/v1/' ) ),
			// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.41A — expose the canonical Channel Gateway admin target for CRM quick-config links.
			'channelGatewayAdminUrl' => esc_url_raw( admin_url( 'admin.php?page=bizchat-gateway-spa' ) ),
			// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — expose automation engine base for care recipe calls
			'automationRestUrl' => esc_url_raw( rest_url( 'bizcity-automation/v1/' ) ),
			'bzdocRestUrl'     => esc_url_raw( rest_url( 'bzdoc/v1/' ) ),
			'twinUrl'          => esc_url_raw( home_url( '/twin/' ) ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'pollMs'           => 3000,
			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.51 A2 — static per-site change marker (nginx-served
			// JSON, no PHP): FE polls this instead of hammering REST on a fixed interval. Same mechanism modules/
			// twinweb's `/crm/inbox` already relies on (bumped by BizCity_CRM_Repository::invalidate_read_models()
			// on every CRM write, including ones made through this very SPA) — resolved once here since it depends
			// only on the current site, not on any per-request channel/ref like TwinWeb's REST-carried version.
			'changeSignalUrl'  => class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'change_signal_url' )
				? esc_url_raw( BizCity_CRM_Repository::change_signal_url() )
				: '',
			// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CRM-B03 — expose woo_active so ChannelsTab integration panel shows correct status
			'woo_active'       => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ),
			// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — expose current user ID so FE can resolve 'me' filter without extra fetch
			'currentUserId'    => get_current_user_id(),
			// PHASE-0.56 11.B2 (Sơ đồ đội root node, Hướng dẫn 2 lớp) — just a label, not a security field.
			'siteName'         => get_bloginfo( 'name' ),
			// [2026-09-17 Johnny Chu - Chu Hoang Anh] PHASE-0.48F F5-02/F5-03 — namespaces the
			// cross-tab poll-leader lock and the persisted catalog cache key so a shared machine
			// or a multisite network never mixes state across blogs.
			'blogId'           => get_current_blog_id(),
			// [2026-09-21 PHASE-0.63B C-08] The eight-item shell is opt-in per tenant;
			// legacy navigation remains the default until the route migration is verified.
			'featureFlags'     => array(
				'bizcity_crm_shell_v2' => (bool) get_option( 'bizcity_crm_shell_v2', false ),
			),
			'isManager'        => ( ( function_exists( 'is_super_admin' ) && is_super_admin() ) || current_user_can( 'manage_options' ) || current_user_can( 'bizcity_manager' ) ),
			'canManageInboxes' => ( ( function_exists( 'is_super_admin' ) && is_super_admin() ) || current_user_can( 'manage_options' ) ), // [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — match DELETE inbox permission in the rail.
			// PHASE-0.48F UI-review — UI hint only (which section tabs to show); every /crm-staff* and /reports/team-inbox* route re-checks Staff_Policy::can().
			'staffRole'        => class_exists( 'BizCity_CRM_Staff_Policy' ) ? BizCity_CRM_Staff_Policy::role( get_current_user_id() ) : ( ( function_exists( 'is_super_admin' ) && is_super_admin() ) || current_user_can( 'manage_options' ) ? 'admin' : 'none' ),
			'canViewInboxUserGroups' => ( function_exists( 'is_super_admin' ) && is_super_admin() ) || ( class_exists( 'BizCity_CRM_REST_Controller' ) && method_exists( 'BizCity_CRM_REST_Controller', 'can_view_inbox_user_groups' )
				? BizCity_CRM_REST_Controller::can_view_inbox_user_groups()
				: current_user_can( 'manage_options' ) ),
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E2 — let the SPA hide composer/triage actions for read-only viewers; REST still enforces.
			'canHandleInbox'   => class_exists( 'BizCity_CRM_Capabilities' ) && method_exists( 'BizCity_CRM_Capabilities', 'user_can_handle_inbox' )
				? BizCity_CRM_Capabilities::user_can_handle_inbox()
				: current_user_can( 'manage_options' ),
			// [2026-09-20 Johnny Chu] PHASE-0.60 C60-M08 — additive to the legacy ad-hoc flags
			// above (kept as-is to avoid touching their 12 existing FE call sites in one pass).
			// New/changed FE reads `permissions.<action>` instead of guessing from a WP
			// capability name; REST still re-checks `BizCity_CRM_Authority::can()` on every
			// write regardless of this hint (R-ERROR-UX server-decides / UI-reflects).
			'permissions'      => $crm_boot_permissions,
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

		// [2026-08-04 Johnny Chu] PHASE-0.48-HOTFIX — keep CRM shell height chain stable in wp-admin iframe/top-level context.
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.39H — scope the wrap reset to every registered CRM admin body class, not only the top-level Inbox page.
		// Remove default wrap margin only on CRM pages and force full-height inheritance to avoid Inbox pane compression.
		$style_handle = $has_built ? 'bizcity-crm-inbox-app' : 'bizcity-crm-inbox-fallback';
		$crm_body = implode( ', ', array(
			'body.toplevel_page-bizcity-crm',
			'body.toplevel_page_bizcity-crm',
			'body.bizcity-crm_page_bizcity-crm-channels',
			'body.bizcity-crm_page_bizcity-crm-add-inbox',
			'body.bizcity-crm_page_bizcity-crm-settings',
			'body.bizcity-crm_page_bizcity-crm-identity-queue',
		) );
		wp_add_inline_style(
			$style_handle,
			$crm_body . ' .wrap{margin:0 !important;height:calc(100vh - 32px);min-height:620px;}'
			. $crm_body . ' #wpbody-content{padding-bottom:0;}'
			. $crm_body . ' #bizcity-crm-inbox-root{height:100%;min-height:0;}'
		);

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — enqueue WP Media Library so window.wp.media
		// is available in the CRM SPA (needed for PDF/ebook attachment picker in Email rules).
		wp_enqueue_media();
	}

	/**
	 * Use asset mtime for deploy cache busting, with a stable plugin-version fallback.
	 */
	private static function asset_version( string $path ): string {
		// [2026-08-04 Johnny Chu] PHASE-0.48-HOTFIX — time() fallback caused a new asset URL on every request.
		$mtime = @filemtime( $path );
		if ( false !== $mtime && $mtime > 0 ) {
			return (string) $mtime;
		}
		return defined( 'BIZCITY_CRM_VERSION' ) ? BIZCITY_CRM_VERSION : '0.0.0';
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

	public function render_identity_queue_page(): void {
		$rest_root = esc_url_raw( rest_url( BIZCITY_CRM_REST_NS . '/' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity CRM — Identity Conflict Queue', 'bizcity-twin-crm' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Review identity conflicts without auto-merging Contacts. Backfill runs one bounded batch at a time.', 'bizcity-twin-crm' ); ?></p>
			<div id="bizcity-crm-identity-queue" data-rest="<?php echo esc_attr( $rest_root ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<p><?php esc_html_e( 'Loading queue…', 'bizcity-twin-crm' ); ?></p>
			</div>
		</div>
		<style>
			#bizcity-crm-identity-queue .bci-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0}
			#bizcity-crm-identity-queue .bci-card{background:#fff;border:1px solid #ccd0d4;padding:14px;margin:12px 0}
			#bizcity-crm-identity-queue .bci-muted{color:#646970}
			#bizcity-crm-identity-queue .bci-error{border-left:4px solid #d63638;padding:10px;background:#fcf0f1}
			#bizcity-crm-identity-queue table{margin-top:12px}
			#bizcity-crm-identity-queue code{font-size:11px}
		</style>
		<script>
		(function(){
			var root=document.getElementById('bizcity-crm-identity-queue'); if(!root)return;
			var rest=root.dataset.rest, nonce=root.dataset.nonce;
			function api(path, method, body){
				return fetch(rest+path.replace(/^\//,''),{method:method||'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:body?JSON.stringify(body):undefined}).then(function(r){return r.json();});
			}
			function esc(value){var el=document.createElement('span');el.textContent=value==null?'':String(value);return el.innerHTML;}
			function message(text, cls){return '<p class="'+(cls||'bci-muted')+'">'+esc(text)+'</p>';}
			var state={page:1,perPage:25,status:'',source:'',reason:'',search:''};
			function query(){var p=new URLSearchParams({page:state.page,per_page:state.perPage});if(state.status)p.set('status',state.status);if(state.source)p.set('source_type',state.source);if(state.reason)p.set('reason',state.reason);if(state.search)p.set('search',state.search);return '/identity-conflicts?'+p.toString();}
			function load(){
				root.innerHTML=message('Loading…');
				Promise.all([api(query()),api('/identity-backfill/status')]).then(function(res){
					var q=res[0], b=res[1]; if(q.code||b.code){root.innerHTML=message((q.message||b.message||'Request failed'), 'bci-error');return;}
					var payload=q.data||q, rows=payload.items||[], cp=((b.data||b).checkpoints)||{};
					var html='<div class="bci-toolbar"><button class="button button-primary" data-action="claim">Claim next</button><button class="button" data-action="refresh">Refresh</button><span class="bci-muted">Showing '+rows.length+' of '+esc(payload.total||0)+' · page '+esc(payload.page||1)+'/'+esc(payload.total_pages||0)+'</span></div>';
					html+='<div class="bci-card"><h2>Filters</h2><div class="bci-toolbar"><select id="bci-status"><option value="">All statuses</option><option value="open">Open</option><option value="claimed">Claimed</option><option value="resolved">Resolved</option><option value="rejected">Rejected</option><option value="ignored">Ignored</option></select><input id="bci-source" type="text" placeholder="Source: woo_user" /><input id="bci-reason" type="text" placeholder="Reason code" /><input id="bci-search" type="search" placeholder="ID / source ID / user ID" /><select id="bci-per-page"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select><button class="button" data-action="filter">Apply filters</button></div></div>';
					html+='<div class="bci-card"><h2>Historical backfill</h2><p class="bci-muted">Checkpoints: user_points='+esc(cp.user_points||0)+' · exchange='+esc(cp.user_points_exchange||0)+' · Woo page='+esc(cp.woo_orders||1)+'</p><div class="bci-toolbar"><select id="bci-kind"><option value="user_points">User points credit</option><option value="user_points_exchange">User points debit</option><option value="woo_orders">Woo orders</option></select><input id="bci-batch" type="number" min="10" max="500" value="100" /><label><input id="bci-dry" type="checkbox" checked /> Dry run</label><button class="button" data-action="backfill">Run one batch</button><button class="button" data-action="fixtures">Run V2 safety fixtures</button></div><div id="bci-result"></div></div>';
					if(!rows.length){html+=message('No open identity conflicts.');}else{html+='<table class="widefat striped"><thead><tr><th>ID</th><th>Source</th><th>Reason</th><th>Candidates</th><th>Status</th><th>Retry</th><th>Action</th></tr></thead><tbody>';
						rows.forEach(function(row){html+='<tr><td>'+esc(row.id)+'</td><td>'+esc(row.source_type)+' #'+esc(row.source_id)+'</td><td><code>'+esc(row.reason_code)+'</code></td><td>'+esc((row.contact_ids||[]).join(', '))+'</td><td>'+esc(row.status)+'</td><td>'+esc(row.retry_count||0)+'</td><td><button class="button-link" data-detail="'+esc(row.id)+'">History</button> '+(row.contact_ids||[]).map(function(cid){return '<button class="button-link" data-resolve="'+esc(row.id)+'" data-contact="'+esc(cid)+'">Resolve '+esc(cid)+'</button>';}).join(' ')+' <button class="button-link" data-reject="'+esc(row.id)+'">Reject</button> <button class="button-link" data-retry="'+esc(row.id)+'">Retry</button></td></tr>';});
						html+='</tbody></table><div class="bci-toolbar"><button class="button" data-page="prev">Previous</button><button class="button" data-page="next">Next</button></div><div id="bci-history"></div>';}
					root.innerHTML=html;
					root.querySelector('#bci-status').value=state.status;root.querySelector('#bci-source').value=state.source;root.querySelector('#bci-reason').value=state.reason;root.querySelector('#bci-search').value=state.search;root.querySelector('#bci-per-page').value=String(state.perPage);
					root.querySelectorAll('[data-action="refresh"]').forEach(function(el){el.onclick=load;});
					root.querySelectorAll('[data-action="filter"]').forEach(function(el){el.onclick=function(){state.page=1;state.status=root.querySelector('#bci-status').value;state.source=root.querySelector('#bci-source').value.trim();state.reason=root.querySelector('#bci-reason').value.trim();state.search=root.querySelector('#bci-search').value.trim();state.perPage=Number(root.querySelector('#bci-per-page').value);load();};});
					root.querySelectorAll('[data-page="prev"]').forEach(function(el){el.onclick=function(){if(state.page>1){state.page--;load();}};});
					root.querySelectorAll('[data-page="next"]').forEach(function(el){el.onclick=function(){if(state.page<(payload.total_pages||1)){state.page++;load();}};});
					root.querySelectorAll('[data-action="claim"]').forEach(function(el){el.onclick=function(){api('/identity-conflicts/claim','POST',{}).then(load);};});
					root.querySelectorAll('[data-detail]').forEach(function(el){el.onclick=function(){api('/identity-conflicts/'+el.dataset.detail).then(function(data){var item=data.data||data, box=root.querySelector('#bci-history');box.innerHTML='<div class="bci-card"><h3>Conflict #'+esc(item.id)+' audit history</h3>'+(item.audit_history||[]).map(function(a){return '<p><strong>'+esc(a.event_type)+'</strong> · '+esc(a.from_status||'')+' → '+esc(a.to_status||'')+' · actor '+esc(a.actor_user_id||0)+' · '+esc(a.created_at)+'<br><span class="bci-muted">'+esc(a.reason||'')+'</span></p>';}).join('')+'</div>';});};});
					root.querySelectorAll('[data-resolve]').forEach(function(el){el.onclick=function(){var reason=window.prompt('Resolution reason','verified_contact');if(!reason)return;api('/identity-conflicts/'+el.dataset.resolve+'/resolve','POST',{contact_id:Number(el.dataset.contact),resolution_reason:reason}).then(load);};});
					root.querySelectorAll('[data-reject]').forEach(function(el){el.onclick=function(){var reason=window.prompt('Reject reason','not_same_person');if(!reason)return;api('/identity-conflicts/'+el.dataset.reject+'/reject','POST',{resolution_reason:reason}).then(load);};});
					root.querySelectorAll('[data-retry]').forEach(function(el){el.onclick=function(){api('/identity-conflicts/'+el.dataset.retry+'/retry','POST',{error:'manual_retry'}).then(load);};});
					var backfill=root.querySelector('[data-action="backfill"]'); if(backfill)backfill.onclick=function(){var result=root.querySelector('#bci-result');result.innerHTML=message('Running one bounded batch…');api('/identity-backfill/run','POST',{kind:root.querySelector('#bci-kind').value,batch:Number(root.querySelector('#bci-batch').value||100),dry_run:root.querySelector('#bci-dry').checked,reset:false}).then(function(data){var payload=data.data||data;result.innerHTML='<pre>'+esc(JSON.stringify(payload,null,2))+'</pre>';});};
					var fixtures=root.querySelector('[data-action="fixtures"]'); if(fixtures)fixtures.onclick=function(){var result=root.querySelector('#bci-result');if(!window.confirm('Fixtures run inside transactions and rollback. Continue?'))return;result.innerHTML=message('Running V2 fixtures…');api('/identity-fixtures/run','POST',{confirm:'V2'}).then(function(data){var payload=data.data||data;result.innerHTML='<pre>'+esc(JSON.stringify(payload,null,2))+'</pre>';});};
				}).catch(function(error){root.innerHTML=message(error.message||'Request failed','bci-error');});
			}
			load();
		})();
		</script>
		<?php
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
		$this->render_zalo_bot_notify_setting();
		$this->render_training_seed_setting();
		echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=bizcity-crm-sprint-diag' ) ) . '">→ Sprint Diagnostic</a></p>';
		echo '</div>';
	}

	/**
	 * PHASE-0.57 T3-06/T3-07a (D57-5, D57-6) — checkbox "Tự tạo tài liệu đào tạo"
	 * (mặc định bật) + nút "Khôi phục / Cập nhật" cho notebook "Hướng dẫn dùng
	 * Twin CRM". Nút chạy đồng bộ ngay (giống ingest() đồng bộ dùng trong webhook
	 * ở nơi khác của plugin) nên có thể mất vài giây khi phải gọi embeddings.
	 */
	// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T3-06/T3-07a — training seed settings row (checkbox + Khôi phục/Cập nhật).
	private function render_training_seed_setting(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'BizCity_CRM_Training_Seeder' ) ) { return; }

		$nonce_action = 'bzc_crm_training_seed_settings';
		if ( isset( $_POST['bzc_crm_training_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bzc_crm_training_nonce'] ) ), $nonce_action ) ) {
			BizCity_CRM_Training_Seeder::set_enabled( ! empty( $_POST['bzcrm_training_seed_enabled'] ) );
			if ( ! empty( $_POST['bzcrm_training_restore'] ) ) {
				$result = BizCity_CRM_Training_Seeder::restore_now( get_current_user_id() );
				if ( ! empty( $result['ok'] ) ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Đã khôi phục / cập nhật tài liệu đào tạo.', 'bizcity-twin-crm' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Khôi phục thất bại: ', 'bizcity-twin-crm' ) . esc_html( (string) ( $result['error'] ?? '' ) ) . '</p></div>';
				}
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Đã lưu.', 'bizcity-twin-crm' ) . '</p></div>';
			}
		}

		$enabled = BizCity_CRM_Training_Seeder::is_enabled();
		$state   = BizCity_CRM_Training_Seeder::get_state();
		$status  = (string) ( $state['status'] ?? '—' );
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T4-04 — expose KG build evidence collected by the seeder.
		$kg_status    = (string) ( $state['kg_status'] ?? '—' );
		$kg_entities  = (int) ( $state['kg_entities'] ?? 0 );
		$kg_relations = (int) ( $state['kg_relations'] ?? 0 );

		echo '<h2>' . esc_html__( 'Tài liệu đào tạo "Hướng dẫn dùng Twin CRM"', 'bizcity-twin-crm' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( $nonce_action, 'bzc_crm_training_nonce' );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Tự tạo tài liệu đào tạo', 'bizcity-twin-crm' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bzcrm_training_seed_enabled" value="1"' . checked( $enabled, true, false ) . '> ';
		esc_html_e( 'Tự động tạo notebook + nội dung mẫu khi chưa có', 'bizcity-twin-crm' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Trạng thái seed: ', 'bizcity-twin-crm' ) . esc_html( $status );
		if ( ! empty( $state['notebook_id'] ) ) {
			echo ' · notebook #' . (int) $state['notebook_id'];
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Trạng thái KG: ', 'bizcity-twin-crm' ) . esc_html( $kg_status ) . ' · ' . esc_html__( 'Entity: ', 'bizcity-twin-crm' ) . (int) $kg_entities . ' · ' . esc_html__( 'Relation: ', 'bizcity-twin-crm' ) . (int) $kg_relations . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Lưu thay đổi', 'bizcity-twin-crm' ), 'primary', 'submit', false );
		echo ' <button type="submit" name="bzcrm_training_restore" value="1" class="button">' . esc_html__( 'Khôi phục / Cập nhật', 'bizcity-twin-crm' ) . '</button>';
		echo '</form>';
	}

	/**
	 * PHASE-0.50 C-05 (N-08, R-LM-8) — tenant-wide master switch for the leader-assign-work Zalo Bot
	 * ping (`BizCity_CRM_Task_Handoff_Notify`). Off by default. The leader's own per-handoff checkbox
	 * (mockup §3.4) is an ADDITIONAL, narrower gate on top of this — both must be on for a ping to send;
	 * this option alone never sends anything by itself.
	 */
	private function render_zalo_bot_notify_setting(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'BizCity_CRM_Task_Handoff_Notify' ) ) { return; }
		$option = BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED;
		$workflow_option = BizCity_CRM_Task_Handoff_Notify::OPTION_WORKFLOW;
		$nonce_action = 'bzc_crm_zalo_bot_notify';
		if ( isset( $_POST['bzc_crm_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bzc_crm_settings_nonce'] ) ), $nonce_action ) ) {
			update_option( $option, ! empty( $_POST[ $option ] ), false );
			$slug = isset( $_POST[ $workflow_option ]['slug'] ) ? sanitize_key( wp_unslash( $_POST[ $workflow_option ]['slug'] ) ) : '';
			$secret = isset( $_POST[ $workflow_option ]['secret'] ) ? sanitize_text_field( wp_unslash( $_POST[ $workflow_option ]['secret'] ) ) : '';
			update_option( $workflow_option, array( 'slug' => $slug, 'secret' => $secret ), false );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Đã lưu.', 'bizcity-twin-crm' ) . '</p></div>';
		}
		$enabled = (bool) get_option( $option, false );
		$workflow = get_option( $workflow_option, array() );
		$wf_slug = is_array( $workflow ) ? (string) ( $workflow['slug'] ?? '' ) : '';
		$wf_secret = is_array( $workflow ) ? (string) ( $workflow['secret'] ?? '' ) : '';
		echo '<h2>' . esc_html__( 'Thông báo Zalo Bot nội bộ', 'bizcity-twin-crm' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( $nonce_action, 'bzc_crm_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Nhắc việc mới qua Zalo Bot', 'bizcity-twin-crm' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $option ) . '" value="1"' . checked( $enabled, true, false ) . '> ';
		esc_html_e( 'Cho phép gửi', 'bizcity-twin-crm' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Khi bật: nhân viên đã liên kết Zalo Bot (Channel Gateway hoặc /gpt/ Kênh của tôi) nhận một tin ngắn khi được giao việc — chỉ số lượng việc, tên người giao, hạn và đường dẫn tới /gpt/crm/. Không tên, SĐT hay ID khách. Leader còn phải tick "Nhắn Zalo Bot nội bộ" ở từng lần giao thì tin mới thật sự gửi.', 'bizcity-twin-crm' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row">' . esc_html__( 'Kịch bản tuỳ biến (tuỳ chọn)', 'bizcity-twin-crm' ) . '</th><td>';
		echo '<p><label for="bzc-notify-wf-slug">' . esc_html__( 'Slug webhook của workflow', 'bizcity-twin-crm' ) . '</label><br>';
		echo '<input type="text" id="bzc-notify-wf-slug" name="' . esc_attr( $workflow_option ) . '[slug]" value="' . esc_attr( $wf_slug ) . '" class="regular-text" placeholder="vd: crm-task-handoff-ping"></p>';
		echo '<p><label for="bzc-notify-wf-secret">' . esc_html__( 'Secret của trigger', 'bizcity-twin-crm' ) . '</label><br>';
		echo '<input type="password" id="bzc-notify-wf-secret" name="' . esc_attr( $workflow_option ) . '[secret]" value="' . esc_attr( $wf_secret ) . '" class="regular-text" autocomplete="off"></p>';
		echo '<p class="description">' . esc_html__( 'Để trống dùng tin mặc định ở trên. Điền cả hai để đổi sang một workflow bạn tự dựng ở Twin Workflow: trigger Webhook (đặt Secret khớp ô này) → khối "Trả lời Zalo". Workflow nhận chat_id, recipient_user_id, count, kind, leader_name, due_date, link — vẫn không có tên/SĐT/ID khách.', 'bizcity-twin-crm' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=bizcity-automation' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Mở Twin Workflow ↗', 'bizcity-twin-crm' ) . '</a></p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Lưu thay đổi', 'bizcity-twin-crm' ) );
		echo '</form>';
	}
}
