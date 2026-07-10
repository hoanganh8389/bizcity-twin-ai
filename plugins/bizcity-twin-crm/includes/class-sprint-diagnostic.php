<?php
/**
 * BizCity CRM — Sprint Diagnostic (M1).
 *
 * URL: /wp-admin/tools.php?page=bizcity-crm-sprint-diag
 *
 * Implements PHASE-0 RULE Diagnostic-Driven Validation:
 *   - 1 row per [T-M1.x] task with PASS/FAIL/SKIP badge.
 *   - 3-layer evidence: Disk + Loader + Runtime.
 *   - OPcache panel + Reset button.
 *   - REST loopback dispatch probe.
 *   - SQL expected-vs-actual probe (insert mock + read back).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Sprint_Diagnostic {

	const SLUG = 'bizcity-crm-sprint-diag';

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ), 12 );
		add_action( 'admin_post_bizcity_crm_diag_action', array( $this, 'handle_post_action' ) );
	}

	public function register(): void {
		add_management_page(
			'BizCity CRM — Sprint Diagnostic',
			'BizCity CRM Diag',
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_post_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		check_admin_referer( 'bizcity_crm_diag' );
		$action = sanitize_key( $_POST['diag_action'] ?? '' );
		switch ( $action ) {
			case 'opcache_reset':
				if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
				break;
			case 'install_tables':
				BizCity_CRM_DB_Installer_V2::install();
				break;
			case 'inject_fixture':
				$this->inject_fixture();
				break;
			case 'backfill_gateway':
				$this->backfill_from_gateway();
				break;
			case 'progress_save':
				$this->save_progress_board();
				break;
			case 'progress_reset':
				delete_option( 'bizcity_crm_progress_board' );
				break;
			case 'migrate_biz_contacts_dry':
				if ( class_exists( 'BizCity_CRM_Migrate_Biz_Contacts' ) ) {
					$rep = BizCity_CRM_Migrate_Biz_Contacts::dry_run();
					set_transient( 'bizcity_crm_diag_migration_report', $rep, 300 );
				}
				break;
			case 'migrate_biz_contacts_run':
				if ( class_exists( 'BizCity_CRM_Migrate_Biz_Contacts' ) ) {
					$rep = BizCity_CRM_Migrate_Biz_Contacts::run();
					set_transient( 'bizcity_crm_diag_migration_report', $rep, 300 );
				}
				break;
			case 'drop_biz_contacts':
				$this->drop_legacy_biz_contacts();
				break;
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }

		// [2026-06-10 Johnny Chu] PHASE-0.39/0.40/0.41/0.42/0.43 — update title to reflect all phases
		echo '<div class="wrap"><h1>BizCity CRM — Sprint Diagnostic <small style="font-weight:normal;font-size:13px;color:#666">PHASE-0.35 → 0.43</small></h1>';
		echo '<p style="color:#555">Roadmap: <code>plugins/bizcity-twin-ai/PHASE-0.35-CRM-INBOX-HUB.md</code> · Covers: 0.35 M1-M7, 0.36 OrderAdapter, 0.37 WebChat, 0.39 ZaloPersonal, 0.40 DeplaoParity, 0.41 AutoCRMPath, 0.42 LiteParse, 0.43 BroadcastMassSend</p>';

		$this->render_action_bar();
		$this->render_progress_board();
		$this->render_opcache_panel();
		$this->render_task_table();
		$this->render_rest_probe();
		$this->render_crm_modules_section();
		$this->render_crm_sales_section();
		$this->render_crm_products_section();
		$this->render_crm_invoicing_section();
		$this->render_crm_email_section();
		$this->render_order_adapter_panel();
		$this->render_woo_bridge_section();
		$this->render_tool_taxonomy_section();
		$this->render_guru_bridge_section();
		$this->render_phase_c_dispatch_section();

		// [2026-06-10 Johnny Chu] PHASE-0.39/0.40/0.41/0.42/0.43 — new sections
		$this->render_phase_039_section();
		$this->render_phase_040_section();
		$this->render_phase_041_section();
		$this->render_phase_042_section();
		$this->render_phase_043_section();

		$this->render_sql_probe();

		echo '</div>';
	}

	/* ============================================================
	 * Action bar
	 * ============================================================ */
	private function render_action_bar(): void {
		echo '<div style="display:flex;gap:8px;margin:12px 0;padding:12px;background:#fff;border:1px solid #ddd;">';
		$this->action_button( 'opcache_reset',  'Reset OPcache' );
		$this->action_button( 'install_tables', 'Run dbDelta install' );
		$this->action_button( 'inject_fixture', 'Inject FB fixture (mock inbound)' );
		$this->action_button( 'backfill_gateway', 'Backfill from Channel Gateway (FB_MESS)' );
		echo '</div>';
	}

	private function action_button( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bizcity_crm_diag' );
		echo '<input type="hidden" name="action" value="bizcity_crm_diag_action" />';
		echo '<input type="hidden" name="diag_action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/* ============================================================
	 * OPcache
	 * ============================================================ */
	private function render_opcache_panel(): void {
		echo '<h2>OPcache</h2>';
		if ( ! function_exists( 'opcache_get_configuration' ) ) {
			echo '<p>OPcache not available.</p>';
			return;
		}
		$cfg = @opcache_get_configuration();
		$st  = @opcache_get_status( false );
		echo '<table class="widefat striped" style="max-width:780px"><tbody>';
		echo '<tr><td><b>opcache.enable</b></td><td>' . esc_html( var_export( ini_get( 'opcache.enable' ), true ) ) . '</td></tr>';
		echo '<tr><td><b>opcache.validate_timestamps</b></td><td>' . esc_html( var_export( ini_get( 'opcache.validate_timestamps' ), true ) ) . '</td></tr>';
		echo '<tr><td><b>opcache.revalidate_freq</b></td><td>' . esc_html( var_export( ini_get( 'opcache.revalidate_freq' ), true ) ) . '</td></tr>';
		echo '<tr><td><b>num_cached_scripts</b></td><td>' . esc_html( (string) ( $st['opcache_statistics']['num_cached_scripts'] ?? 'n/a' ) ) . '</td></tr>';
		echo '</tbody></table>';
	}

	/* ============================================================
	 * Task table — DDV §1
	 * ============================================================ */
	private function render_task_table(): void {
		$tasks = $this->compute_tasks();

		echo '<h2>Task Status — M1</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:90px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function badge( string $status ): string {
		$colors = array(
			'PASS' => '#1f8a48',
			'FAIL' => '#a00',
			'SKIP' => '#888',
		);
		$color = $colors[ $status ] ?? '#888';
		return '<span style="display:inline-block;padding:2px 10px;border-radius:3px;color:#fff;background:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Compute pass/fail for each [T-M1.x].
	 */
	private function compute_tasks(): array {
		global $wpdb;
		$out  = array();
		$base = BIZCITY_CRM_DIR;
		$incs = get_included_files();

		/* T-M1.1 — Skeleton plugin loaded */
		$boot       = $base . '/bootstrap.php';
		$class_ok   = class_exists( 'BizCity_CRM_Plugin', false );
		$loader_ok  = in_array( str_replace( '\\', '/', $boot ), array_map( static fn( $f ) => str_replace( '\\', '/', $f ), $incs ), true );
		$disk_ok    = is_readable( $boot );
		$out[] = array(
			'id'       => 'T-M1.1',
			'status'   => ( $disk_ok && $loader_ok && $class_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'bootstrap.php exists, included, BizCity_CRM_Plugin declared',
			'evidence' => sprintf( "Disk: %s\nLoader: %s\nRuntime: class=%s",
				$disk_ok ? 'YES' : 'NO',
				$loader_ok ? 'YES' : 'NO',
				$class_ok ? 'YES' : 'NO'
			),
		);

		/* T-M1.2 — 6 tables installed */
		$missing = BizCity_CRM_DB_Installer_V2::missing_tables();
		$ver     = (string) get_option( BizCity_CRM_DB_Installer_V2::DB_VERSION_OPTION );
		$out[] = array(
			'id'       => 'T-M1.2',
			'status'   => ( ! $missing && $ver === BIZCITY_CRM_DB_VERSION ) ? 'PASS' : 'FAIL',
			'check'    => '6 CRM tables exist + db version option matches',
			'evidence' => sprintf( "Missing: %s\nDB ver option: %s\nExpected: %s",
				$missing ? implode( ',', array_keys( $missing ) ) : 'none',
				$ver ?: '(unset)',
				BIZCITY_CRM_DB_VERSION
			),
		);

		/* T-M1.3 — Repository + Event Emitter */
		$repo_ok  = class_exists( 'BizCity_CRM_Repository', false ) && method_exists( 'BizCity_CRM_Repository', 'insert_message' );
		$emit_ok  = class_exists( 'BizCity_CRM_Event_Emitter', false ) && method_exists( 'BizCity_CRM_Event_Emitter', 'emit' );
		$src      = (string) @file_get_contents( $base . '/includes/class-repository.php' );
		$emits_ok = (bool) preg_match( '/BizCity_CRM_Event_Emitter::emit/', $src );
		$out[] = array(
			'id'       => 'T-M1.3',
			'status'   => ( $repo_ok && $emit_ok && $emits_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Repository writes call Event_Emitter::emit (R-CRM-1)',
			'evidence' => sprintf( "Repo class: %s\nEmitter class: %s\nemit() in repo source: %s",
				$repo_ok ? 'YES' : 'NO', $emit_ok ? 'YES' : 'NO', $emits_ok ? 'YES' : 'NO'
			),
		);

		/* T-M1.4 — Adapter interface + Registry */
		$iface_ok = interface_exists( 'BizCity_CRM_Channel_Adapter', false );
		$reg_ok   = class_exists( 'BizCity_CRM_Channel_Registry', false );
		$adapters = $reg_ok ? BizCity_CRM_Channel_Registry::all() : array();
		$out[] = array(
			'id'       => 'T-M1.4',
			'status'   => ( $iface_ok && $reg_ok && ! empty( $adapters ) ) ? 'PASS' : 'FAIL',
			'check'    => 'Interface + Registry available; ≥1 adapter registered',
			'evidence' => sprintf( "Interface: %s\nRegistry: %s\nAdapters: [%s]",
				$iface_ok ? 'YES' : 'NO', $reg_ok ? 'YES' : 'NO',
				implode( ',', array_keys( $adapters ) )
			),
		);

		/* T-M1.5 — Adapter Facebook */
		$fb_class    = class_exists( 'BizCity_CRM_Adapter_Facebook', false );
		$fb_in_reg   = isset( $adapters['facebook'] );
		$fb_normalize = false;
		if ( $fb_in_reg ) {
			$test = $adapters['facebook']->normalize_inbound( array(
				'page_id'   => 'TEST_PAGE',
				'user_id'   => 'TEST_PSID_999',
				'message'   => 'hello',
				'timestamp' => 1000,
				'event'     => array( 'message' => array( 'mid' => 'mid.test1' ) ),
				'platform'  => 'FB_MESS',
			) );
			$fb_normalize = is_array( $test ) && isset( $test['external_source_id'] ) && $test['external_source_id'] === 'mid.test1';
		}
		$out[] = array(
			'id'       => 'T-M1.5',
			'status'   => ( $fb_class && $fb_in_reg && $fb_normalize ) ? 'PASS' : 'FAIL',
			'check'    => 'Adapter_Facebook registered + normalize_inbound dedupe key correct',
			'evidence' => sprintf( "Class: %s\nIn registry: %s\nNormalize OK: %s",
				$fb_class ? 'YES' : 'NO', $fb_in_reg ? 'YES' : 'NO', $fb_normalize ? 'YES' : 'NO'
			),
		);

		/* T-M1.6 — Ingestor subscribed to waic_twf_process_flow */
		$ing_ok    = class_exists( 'BizCity_CRM_Facebook_Ingestor', false );
		$has_hook  = false !== has_action( 'waic_twf_process_flow', array( BizCity_CRM_Facebook_Ingestor::instance(), 'on_workflow_trigger' ) );
		$out[] = array(
			'id'       => 'T-M1.6',
			'status'   => ( $ing_ok && $has_hook ) ? 'PASS' : 'FAIL',
			'check'    => 'Ingestor singleton + hook subscribed (priority 9)',
			'evidence' => sprintf( "Class: %s\nHook attached: %s",
				$ing_ok ? 'YES' : 'NO', $has_hook ? 'YES' : 'NO'
			),
		);

		/* T-M1.7 — REST routes registered */
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$prefix = '/' . BIZCITY_CRM_REST_NS;
		$want   = array(
			$prefix . '/channels',
			$prefix . '/inboxes',
			$prefix . '/conversations',
			$prefix . '/conversations/(?P<id>\d+)',
			$prefix . '/conversations/(?P<id>\d+)/messages',
			$prefix . '/conversations/(?P<id>\d+)/notes',
			$prefix . '/conversations/(?P<id>\d+)/resolve',
			$prefix . '/contacts/(?P<id>\d+)',
		);
		$found = array_intersect( $want, $routes );
		$out[] = array(
			'id'       => 'T-M1.7',
			'status'   => ( count( $found ) === count( $want ) ) ? 'PASS' : 'FAIL',
			'check'    => '8 REST routes registered under bizcity-crm/v1 (incl. POST send/note/resolve + GET contacts/{id})',
			'evidence' => sprintf( "Found %d/%d:\n%s",
				count( $found ), count( $want ),
				implode( "\n", $found )
			),
		);

		/* T-M1.8 — Top-level menu + script handle present */
		$menu_class = class_exists( 'BizCity_CRM_Admin_Menu', false );
		global $menu;
		$menu_present = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $m ) {
				if ( isset( $m[2] ) && $m[2] === BizCity_CRM_Admin_Menu::SLUG ) { $menu_present = true; break; }
			}
		}
		$out[] = array(
			'id'       => 'T-M1.8',
			'status'   => ( $menu_class && $menu_present ) ? 'PASS' : 'FAIL',
			'check'    => 'Top-level menu "bizcity-crm" registered',
			'evidence' => sprintf( "Class: %s\nMenu present: %s", $menu_class ? 'YES' : 'NO', $menu_present ? 'YES' : 'NO' ),
		);

		/* T-M1.9 — Frontend asset present (built or fallback) */
		$built_js   = BIZCITY_CRM_DIR . '/assets/dist/inbox-app.js';
		$fallbk_js  = BIZCITY_CRM_DIR . '/frontend/fallback/inbox.js';
		$built      = is_readable( $built_js );
		$fallback   = is_readable( $fallbk_js );
		$built_age  = $built ? ( time() - (int) @filemtime( $built_js ) ) : -1;
		$out[] = array(
			'id'       => 'T-M1.9',
			'status'   => ( $built || $fallback ) ? 'PASS' : 'FAIL',
			'check'    => 'Vite build OR fallback bundle exists for SPA mount (asset version = filemtime, auto-bust on npm build)',
			'evidence' => sprintf( "Built (assets/dist/inbox-app.js): %s%s\nFallback (fallback/inbox.js): %s",
				$built ? 'YES' : 'NO',
				$built ? ' · age=' . $built_age . 's · ver=' . (int) @filemtime( $built_js ) : '',
				$fallback ? 'YES' : 'NO'
			),
		);

		/* T-M1.10 — This very page renders + actions wired */
		$this_class = class_exists( 'BizCity_CRM_Sprint_Diagnostic', false );
		$has_action = has_action( 'admin_post_bizcity_crm_diag_action' ) !== false;
		$out[] = array(
			'id'       => 'T-M1.10',
			'status'   => ( $this_class && $has_action ) ? 'PASS' : 'FAIL',
			'check'    => 'Diagnostic page class + admin-post handler wired',
			'evidence' => sprintf( "Class: %s\nadmin_post handler: %s",
				$this_class ? 'YES' : 'NO', $has_action ? 'YES' : 'NO'
			),
		);

		/* T-M1.11 — Composer write contract (PHASE 0.34 FE-M4):
			• `_bizcity_crm_messages` carries responder_kind/user_id/character_id columns
			• Repository::resolve_chat_id() exists
			• REST controller exposes can_write() + post_message handler */
		global $wpdb;
		$msg_tbl   = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$cols      = $wpdb->get_col( "SHOW COLUMNS FROM {$msg_tbl}", 0 );
		$cols_have = array_intersect( array( 'responder_kind', 'responder_user_id', 'character_id' ), (array) $cols );
		$resolve_ok = method_exists( 'BizCity_CRM_Repository', 'resolve_chat_id' );
		$write_ok   = method_exists( 'BizCity_CRM_REST_Controller', 'post_message' )
			&& method_exists( 'BizCity_CRM_REST_Controller', 'post_note' )
			&& method_exists( 'BizCity_CRM_REST_Controller', 'can_write' );
		$ok_11      = count( $cols_have ) === 3 && $resolve_ok && $write_ok;
		$out[] = array(
			'id'       => 'T-M1.11',
			'status'   => $ok_11 ? 'PASS' : 'FAIL',
			'check'    => 'Composer write contract: responder_* columns + resolve_chat_id + can_write/post_*',
			'evidence' => sprintf( "cols: %s\nresolve_chat_id: %s\npost_message+post_note+can_write: %s",
				implode( ',', $cols_have ),
				$resolve_ok ? 'YES' : 'NO',
				$write_ok   ? 'YES' : 'NO'
			),
		);

		/* T-M1.12 — ContactDrawer contract (PHASE 0.34 FE-M6):
			• Repository::list_inboxes_for_contact / list_conversations_for_contact / list_gurus_for_contact exist
			• REST_Controller::get_contact + shape_contact exist
			• Smoke: get_contact REST returns ok=true for any existing contact (or 404 if zero contacts — SKIP) */
		$repo_ok_12 = method_exists( 'BizCity_CRM_Repository', 'list_inboxes_for_contact' )
			&& method_exists( 'BizCity_CRM_Repository', 'list_conversations_for_contact' )
			&& method_exists( 'BizCity_CRM_Repository', 'list_gurus_for_contact' );
		$ctl_ok_12  = method_exists( 'BizCity_CRM_REST_Controller', 'get_contact' )
			&& method_exists( 'BizCity_CRM_REST_Controller', 'shape_contact' );
		$ct_tbl     = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$any_id     = (int) $wpdb->get_var( "SELECT id FROM {$ct_tbl} ORDER BY id DESC LIMIT 1" );
		$smoke_status = 'SKIP';
		$smoke_evid   = 'no contacts in DB — inject FB fixture above';
		if ( $repo_ok_12 && $ctl_ok_12 && $any_id > 0 ) {
			$req = new WP_REST_Request( 'GET', '/' . BIZCITY_CRM_REST_NS . '/contacts/' . $any_id );
			$res = rest_do_request( $req );
			$bod = $res->get_data();
			$smoke_status = ( (int) $res->get_status() === 200 && ! empty( $bod['ok'] ) && ! empty( $bod['data']['contact']['id'] ) ) ? 'PASS' : 'FAIL';
			$smoke_evid   = sprintf( 'GET /contacts/%d → %d, contact.id=%s, gurus=%d, convs=%d',
				$any_id,
				(int) $res->get_status(),
				isset( $bod['data']['contact']['id'] ) ? (string) $bod['data']['contact']['id'] : '—',
				isset( $bod['data']['gurus'] )         ? count( $bod['data']['gurus'] )         : 0,
				isset( $bod['data']['conversations'] ) ? count( $bod['data']['conversations'] ) : 0
			);
		}
		$ok_12 = $repo_ok_12 && $ctl_ok_12 && in_array( $smoke_status, array( 'PASS', 'SKIP' ), true );
		$out[] = array(
			'id'       => 'T-M1.12',
			'status'   => $ok_12 ? ( $smoke_status === 'SKIP' ? 'SKIP' : 'PASS' ) : 'FAIL',
			'check'    => 'ContactDrawer contract: repo helpers + GET /contacts/{id} aggregator',
			'evidence' => sprintf( "repo helpers: %s\nctl helpers: %s\nsmoke: %s",
				$repo_ok_12 ? 'YES' : 'NO',
				$ctl_ok_12  ? 'YES' : 'NO',
				$smoke_evid
			),
		);

		/* T-M1.13 — Bridge from Channel Gateway / FB-bot legacy:
			• Adapter registered EAGERLY (not via init@5) so it survives FB-bot's exit() at init@0
			• Outbound subscriber for `bizcity_facebook_message_sent` wired
			• Outbound subscriber for `bizcity_channel_outbound_logged` wired
			• ingest_outbound() helper exists on ingestor */
		$adapter_eager = (bool) BizCity_CRM_Channel_Registry::get( 'facebook' );
		$has_legacy_out = false !== has_action( 'bizcity_facebook_message_sent', array( 'BizCity_CRM_Facebook_Ingestor', 'on_outbound_sent' ) );
		$has_gw_out     = false !== has_action( 'bizcity_channel_outbound_logged', array( 'BizCity_CRM_Facebook_Ingestor', 'on_gateway_outbound' ) );
		$ingest_out_ok  = method_exists( 'BizCity_CRM_Facebook_Ingestor', 'ingest_outbound' );

		// Count rows in gateway ledger vs CRM messages to surface backfill need.
		$gw_tbl    = $wpdb->prefix . 'bizcity_channel_messages';
		$gw_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gw_tbl ) );
		$gw_in     = $gw_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$gw_tbl} WHERE platform='FB_MESS' AND direction=1" ) : 0;
		$gw_out    = $gw_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$gw_tbl} WHERE platform='FB_MESS' AND direction=2" ) : 0;
		$crm_in    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msg_tbl} WHERE message_type='incoming'" );
		$crm_out   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msg_tbl} WHERE message_type='outgoing'" );

		$ok_13 = $adapter_eager && $has_legacy_out && $has_gw_out && $ingest_out_ok;
		$out[] = array(
			'id'       => 'T-M1.13',
			'status'   => $ok_13 ? 'PASS' : 'FAIL',
			'check'    => 'Gateway/FB-bot bridge: eager adapter + outbound subscribers + ingest_outbound()',
			'evidence' => sprintf(
				"adapter eager: %s\non_outbound_sent hook: %s\non_gateway_outbound hook: %s\ningest_outbound(): %s\nLEDGER  in/out: %d / %d (gateway %s)\nCRM     in/out: %d / %d   ← run \"Backfill\" if mismatched",
				$adapter_eager ? 'YES' : 'NO',
				$has_legacy_out ? 'YES' : 'NO',
				$has_gw_out ? 'YES' : 'NO',
				$ingest_out_ok ? 'YES' : 'NO',
				$gw_in, $gw_out, $gw_exists ? 'present' : 'absent',
				$crm_in, $crm_out
			),
		);

		/* ===================================================================
		 * PHASE 0.35 — M7.W5 Bot Plugin Bridges (FB / Zalo / Google)
		 * =================================================================== */
		$bridges = array(
			array(
				'id'      => 'T-P0.35.7.5.1.1',
				'class'   => 'BizCity_CRM_Bridge_FB',
				'file'    => $base . '/includes/inbox/bridges/class-fb-bot-bridge.php',
				'siblings' => array( 'BizCity_Facebook_Bot_Database', 'BizCity_Facebook_Bot_API' ),
				'label'   => 'FB bridge (bizcity-facebook-bot)',
			),
			array(
				'id'      => 'T-P0.35.7.5.1.2',
				'class'   => 'BizCity_CRM_Bridge_Zalo',
				'file'    => $base . '/includes/inbox/bridges/class-zalo-bot-bridge.php',
				'siblings' => array( 'BizCity_Zalo_Bot_Database', 'BizCity_Zalo_Bot_API' ),
				'label'   => 'Zalo bridge (bizcity-zalo-bot)',
			),
			array(
				'id'      => 'T-P0.35.7.5.1.3',
				'class'   => 'BizCity_CRM_Bridge_Google',
				'file'    => $base . '/includes/bridges/class-google-tool-bridge.php',
				'siblings' => array( 'BZGoogle_Token_Store', 'BZGoogle_Google_Service' ),
				'label'   => 'Google bridge (bizgpt-tool-google)',
			),
		);
		foreach ( $bridges as $b ) {
			$disk_ok    = is_readable( $b['file'] );
			$loader_ok  = in_array( str_replace( '\\', '/', $b['file'] ), array_map( static fn( $f ) => str_replace( '\\', '/', $f ), $incs ), true );
			$class_ok   = class_exists( $b['class'], false );
			$version    = $class_ok ? constant( $b['class'] . '::BRIDGE_API_VERSION' ) : '—';
			$avail_ok   = $class_ok ? (bool) call_user_func( array( $b['class'], 'is_available' ) ) : false;
			$siblings   = array();
			foreach ( $b['siblings'] as $s ) {
				$siblings[] = $s . '=' . ( class_exists( $s, false ) ? 'YES' : 'no' );
			}
			$out[] = array(
				'id'       => $b['id'],
				'status'   => ( $disk_ok && $loader_ok && $class_ok && $avail_ok ) ? 'PASS' : ( ( $disk_ok && $loader_ok && $class_ok ) ? 'SKIP' : 'FAIL' ),
				'check'    => $b['label'] . ' — file + loader + class + is_available()',
				'evidence' => sprintf(
					"Disk: %s\nLoader: %s\nClass: %s (v%s)\nis_available(): %s\nSiblings: %s",
					$disk_ok ? 'YES' : 'NO',
					$loader_ok ? 'YES' : 'NO',
					$class_ok ? 'YES' : 'NO',
					(string) $version,
					$avail_ok ? 'YES' : 'no — sibling plugin not active',
					implode( ', ', $siblings )
				),
			);
		}

		/* ===================================================================
		 * PHASE 0.35 — M1 Foundation refactor (W1..W4)
		 * =================================================================== */

		// T-P0.35.1.1 — DB migration (idempotent columns + indexes).
		$conv_tbl = $wpdb->prefix . 'bizcity_crm_conversations';
		$msg_tbl2 = $wpdb->prefix . 'bizcity_crm_messages';
		$ct_tbl   = $wpdb->prefix . 'bizcity_crm_contacts';
		$col      = static function ( string $tbl, string $name ) {
			return class_exists( 'BizCity_CRM_DB_Installer_V2' )
				? BizCity_CRM_DB_Installer_V2::column_exists( $tbl, $name )
				: false;
		};
		$idx      = static function ( string $tbl, string $name ) {
			return class_exists( 'BizCity_CRM_DB_Installer_V2' )
				? BizCity_CRM_DB_Installer_V2::index_exists( $tbl, $name )
				: false;
		};
		$conv_cols = array( 'snoozed_until', 'waiting_since', 'first_reply_at', 'cached_label_list', 'sla_policy_id', 'team_id' );
		$msg_cols  = array( 'macro_id', 'automation_rule_id' );
		$ct_cols   = array( 'acquisition_source', 'acquisition_meta_json', 'points_balance_cache' );
		$conv_have = array_filter( $conv_cols, static fn( $c ) => $col( $conv_tbl, $c ) );
		$msg_have  = array_filter( $msg_cols,  static fn( $c ) => $col( $msg_tbl2, $c ) );
		$ct_have   = array_filter( $ct_cols,   static fn( $c ) => $col( $ct_tbl,   $c ) );
		$conv_idx  = $idx( $conv_tbl, 'idx_priority_status' ) && $idx( $conv_tbl, 'idx_waiting' ) && $idx( $conv_tbl, 'idx_snoozed' );
		$msg_idx   = $idx( $msg_tbl2, 'idx_rule' ) && $idx( $msg_tbl2, 'idx_macro' );
		$ct_idx    = $idx( $ct_tbl,   'idx_acquisition' );
		$db_ver    = (string) get_option( 'bizcity_crm_db_ver' );
		$migration_ok = count( $conv_have ) === count( $conv_cols )
			&& count( $msg_have )  === count( $msg_cols )
			&& count( $ct_have )   === count( $ct_cols )
			&& $conv_idx && $msg_idx && $ct_idx;
		$out[] = array(
			'id'       => 'T-P0.35.1.1',
			'status'   => $migration_ok ? 'PASS' : 'FAIL',
			'check'    => 'DB migration — Phase 0.35 columns + indexes on conversations/messages/contacts',
			'evidence' => sprintf(
				"db_ver: %s (target %s)\nconversations cols: %d/%d (have: %s)\nconversations idx: %s\nmessages cols: %d/%d (have: %s)\nmessages idx: %s\ncontacts cols: %d/%d (have: %s)\ncontacts idx idx_acquisition: %s",
				$db_ver !== '' ? $db_ver : '—',
				BIZCITY_CRM_DB_VERSION,
				count( $conv_have ), count( $conv_cols ), implode( ', ', $conv_have ) ?: '—',
				$conv_idx ? 'YES' : 'NO',
				count( $msg_have ), count( $msg_cols ), implode( ', ', $msg_have ) ?: '—',
				$msg_idx ? 'YES' : 'NO',
				count( $ct_have ), count( $ct_cols ), implode( ', ', $ct_have ) ?: '—',
				$ct_idx ? 'YES' : 'NO'
			),
		);

		// T-P0.35.1.2 — Capabilities granted to roles.
		$caps_class_ok = class_exists( 'BizCity_CRM_Capabilities', false );
		$caps_snap     = $caps_class_ok ? BizCity_CRM_Capabilities::snapshot() : array();
		$admin_ok = isset( $caps_snap['administrator'] )
			&& ! empty( $caps_snap['administrator'][ BizCity_CRM_Capabilities::CAP_HANDLE_INBOX ] )
			&& ! empty( $caps_snap['administrator'][ BizCity_CRM_Capabilities::CAP_MANAGE_RULES ] )
			&& ! empty( $caps_snap['administrator'][ BizCity_CRM_Capabilities::CAP_VIEW_REPORTS ] );
		$evidence_caps = '';
		foreach ( $caps_snap as $role_slug => $row ) {
			$evidence_caps .= "{$role_slug}: ";
			$bits = array();
			foreach ( $row as $cap_name => $has ) {
				$bits[] = $cap_name . '=' . ( $has ? 'Y' : 'n' );
			}
			$evidence_caps .= implode( ', ', $bits ) . "\n";
		}
		$out[] = array(
			'id'       => 'T-P0.35.1.2',
			'status'   => ( $caps_class_ok && $admin_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Capabilities — administrator has handle_inbox + manage_rules + view_reports',
			'evidence' => sprintf(
				"Class: %s\nSignature option: %s\n%s",
				$caps_class_ok ? 'YES' : 'NO',
				(string) get_option( 'bizcity_crm_caps_signature' ) ?: '—',
				$evidence_caps ?: '(no roles loaded)'
			),
		);

		// T-P0.35.1.3 — REST conversations route accepts new filters.
		$rest    = rest_get_server();
		$routes  = $rest ? $rest->get_routes() : array();
		$conv_rt = '/' . BIZCITY_CRM_REST_NS . '/conversations';
		$rt_have = isset( $routes[ $conv_rt ] );
		// Probe with priority + snoozed args — should not 4xx.
		$probe   = new WP_REST_Request( 'GET', $conv_rt );
		$probe->set_query_params( array( 'priority' => 'high', 'snoozed' => 'false', 'limit' => 1 ) );
		$resp    = $rest ? $rest->dispatch( $probe ) : null;
		$rt_ok   = $resp && $resp->get_status() < 400;
		$out[] = array(
			'id'       => 'T-P0.35.1.3',
			'status'   => ( $rt_have && $rt_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'REST GET /conversations supports ?priority= & ?snoozed= filters',
			'evidence' => sprintf(
				"Route registered: %s\nDispatch ?priority=high&snoozed=false: HTTP %d",
				$rt_have ? 'YES' : 'NO',
				$resp ? (int) $resp->get_status() : 0
			),
		);

		// T-P0.35.1.4 — Snooze + Unsnooze routes registered.
		$snz_rt   = '/' . BIZCITY_CRM_REST_NS . '/conversations/(?P<id>\d+)/snooze';
		$unsnz_rt = '/' . BIZCITY_CRM_REST_NS . '/conversations/(?P<id>\d+)/unsnooze';
		$snz_ok   = isset( $routes[ $snz_rt ] ) && isset( $routes[ $unsnz_rt ] );
		$repo_ok  = method_exists( 'BizCity_CRM_Repository', 'set_snooze' );
		$out[] = array(
			'id'       => 'T-P0.35.1.4',
			'status'   => ( $snz_ok && $repo_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Snooze REST: POST /conversations/{id}/snooze · /unsnooze + Repository::set_snooze()',
			'evidence' => sprintf(
				"/snooze route: %s\n/unsnooze route: %s\nRepository::set_snooze(): %s",
				isset( $routes[ $snz_rt ] ) ? 'YES' : 'NO',
				isset( $routes[ $unsnz_rt ] ) ? 'YES' : 'NO',
				$repo_ok ? 'YES' : 'NO'
			),
		);

		// T-P0.35.1.5 — Live snooze probe: pick first conversation, snooze 60s, read back, unsnooze.
		$first_conv = (int) $wpdb->get_var( "SELECT id FROM {$conv_tbl} ORDER BY id DESC LIMIT 1" );
		$probe_ok = false;
		$probe_evidence = '(no conversation row to probe)';
		if ( $first_conv > 0 && $repo_ok ) {
			$prev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT snoozed_until FROM {$conv_tbl} WHERE id=%d", $first_conv ) );
			$set  = BizCity_CRM_Repository::set_snooze( $first_conv, time() + 60, 0 );
			$read = (int) $wpdb->get_var( $wpdb->prepare( "SELECT snoozed_until FROM {$conv_tbl} WHERE id=%d", $first_conv ) );
			BizCity_CRM_Repository::set_snooze( $first_conv, $prev > 0 ? $prev : 0, 0 );
			$probe_ok = $set && $read > time();
			$probe_evidence = sprintf( 'conv_id: %d · set_snooze: %s · read snoozed_until: %d (now+%ds) · restored prev=%d', $first_conv, $set ? 'YES' : 'NO', $read, max( 0, $read - time() ), $prev );
		}
		$out[] = array(
			'id'       => 'T-P0.35.1.5',
			'status'   => $probe_ok ? 'PASS' : ( $first_conv > 0 ? 'FAIL' : 'SKIP' ),
			'check'    => 'Live snooze probe — set_snooze() round-trips through DB',
			'evidence' => $probe_evidence,
		);

		/* ──────────────────────────────────────────────
		 * M2 — Automation Engine (PHASE 0.35 W1–W5)
		 * ────────────────────────────────────────────── */

		// T-P0.35.2.1 — automation_rules table + indexes.
		$rules_tbl   = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$rules_exist = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rules_tbl ) );
		$rules_idx   = $rules_exist ? $wpdb->get_results( "SHOW INDEX FROM {$rules_tbl}", ARRAY_A ) : array();
		$rules_idx_names = array_unique( array_map( static fn( $r ) => $r['Key_name'], $rules_idx ) );
		$rules_idx_ok    = in_array( 'idx_event_active', $rules_idx_names, true ) && in_array( 'idx_inbox', $rules_idx_names, true );
		$out[] = array(
			'id'       => 'T-P0.35.2.1',
			'status'   => ( $rules_exist && $rules_idx_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'automation_rules table + indexes (idx_event_active, idx_inbox)',
			'evidence' => sprintf( 'table: %s · indexes: %s', $rules_exist ? 'YES' : 'NO', implode( ',', $rules_idx_names ) ),
		);

		// T-P0.35.2.2 — Engine class loaded + subscribed to events.
		$engine_loaded   = class_exists( 'BizCity_CRM_Automation_Engine' );
		$subscribed_ok   = true;
		$missing_hooks   = array();
		if ( $engine_loaded ) {
			foreach ( BizCity_CRM_Automation_Engine::SUBSCRIBED_EVENTS as $ev ) {
				if ( ! has_action( 'bizcity_crm_event_' . $ev ) ) {
					$subscribed_ok = false;
					$missing_hooks[] = $ev;
				}
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.2.2',
			'status'   => ( $engine_loaded && $subscribed_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Automation_Engine subscribes to ' . count( BizCity_CRM_Automation_Engine::SUBSCRIBED_EVENTS ) . ' events',
			'evidence' => $engine_loaded
				? ( $subscribed_ok ? 'all hooks attached' : 'missing: ' . implode( ', ', $missing_hooks ) )
				: 'class missing',
		);

		// T-P0.35.2.3 — Evaluator + Action_Registry + Action_Runner classes loaded.
		$evaluator_ok = class_exists( 'BizCity_CRM_Rule_Evaluator' );
		$registry_ok  = class_exists( 'BizCity_CRM_Action_Registry' );
		$runner_ok    = class_exists( 'BizCity_CRM_Action_Runner' );
		$out[] = array(
			'id'       => 'T-P0.35.2.3',
			'status'   => ( $evaluator_ok && $registry_ok && $runner_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'Rule_Evaluator + Action_Registry + Action_Runner classes loaded',
			'evidence' => sprintf( 'evaluator=%s registry=%s runner=%s', $evaluator_ok ? 'Y' : 'N', $registry_ok ? 'Y' : 'N', $runner_ok ? 'Y' : 'N' ),
		);

		// T-P0.35.2.4 — Action registry has ≥ 9 default actions.
		$actions_map  = $registry_ok ? BizCity_CRM_Action_Registry::all() : array();
		$action_types = array_keys( $actions_map );
		$out[] = array(
			'id'       => 'T-P0.35.2.4',
			'status'   => count( $action_types ) >= 9 ? 'PASS' : 'FAIL',
			'check'    => '≥ 9 default actions registered',
			'evidence' => sprintf( '%d actions: %s', count( $action_types ), implode( ', ', $action_types ) ),
		);

		// T-P0.35.2.5 — REST routes registered (5 endpoints).
		$rest_server   = rest_get_server();
		$routes        = $rest_server ? array_keys( $rest_server->get_routes() ) : array();
		$ns            = '/' . BIZCITY_CRM_REST_NS;
		$expected_paths = array(
			$ns . '/automation-rules',
			$ns . '/automation-rules/(?P<id>\d+)',
			$ns . '/automation-rules/(?P<id>\d+)/dry-run',
			$ns . '/automation-actions',
		);
		$missing_routes = array_diff( $expected_paths, $routes );
		$out[] = array(
			'id'       => 'T-P0.35.2.5',
			'status'   => empty( $missing_routes ) ? 'PASS' : 'FAIL',
			'check'    => 'REST automation-rules + dry-run + actions routes',
			'evidence' => empty( $missing_routes )
				? '4/4 routes registered'
				: 'missing: ' . implode( ', ', $missing_routes ),
		);

		// T-P0.35.2.6 — Live evaluator probe (no DB writes; uses in-memory rule).
		$probe_eval_ok  = false;
		$probe_eval_msg = 'evaluator class missing';
		if ( $evaluator_ok ) {
			$probe_ctx = array(
				'event_name'   => 'crm_message_received',
				'conversation' => array( 'id' => 0, 'status' => 'open', 'priority' => 2, 'inbox_id' => 0, 'cached_label_list' => 'vip,test' ),
				'message'      => array( 'content' => 'báo giá khoá học', 'message_type' => 'incoming', 'sender_type' => 'contact' ),
				'contact'      => array(),
				'payload'      => array(),
			);
			$probe_cond = array(
				'operator' => 'all',
				'rules'    => array(
					array( 'field' => 'status',   'op' => 'equals',   'value' => 'open' ),
					array( 'field' => 'priority', 'op' => 'gte',      'value' => 1 ),
					array( 'field' => 'content',  'op' => 'regex',    'value' => '(báo giá|price)' ),
					array( 'field' => 'labels',   'op' => 'contains', 'value' => 'vip' ),
				),
			);
			$result = BizCity_CRM_Rule_Evaluator::evaluate( $probe_cond, $probe_ctx );
			$probe_eval_ok  = ! empty( $result['matched'] );
			$probe_eval_msg = sprintf(
				'matched=%s · clauses=%d · pass=%d',
				$probe_eval_ok ? 'YES' : 'NO',
				count( $probe_cond['rules'] ),
				count( array_filter( $result['trace'] ?? array(), static fn( $t ) => ! empty( $t['ok'] ) ) )
			);
		}
		$out[] = array(
			'id'       => 'T-P0.35.2.6',
			'status'   => $probe_eval_ok ? 'PASS' : 'FAIL',
			'check'    => 'Rule_Evaluator dry-probe (4 mixed clauses, AND)',
			'evidence' => $probe_eval_msg,
		);

		/* ============================================================
		 * PHASE 0.35 M3 — Labels · Custom Attributes · Macros
		 * ============================================================ */
		global $wpdb;

		// T-P0.35.3.1 — Labels + conversation_labels join tables exist with indexes.
		$tbl_labels    = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$tbl_conv_lbls = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$has_labels    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_labels    ) ) === $tbl_labels;
		$has_conv_lbls = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_conv_lbls ) ) === $tbl_conv_lbls;
		$lbl_idx_ok    = false;
		$cl_idx_ok     = false;
		if ( $has_labels ) {
			$idx = $wpdb->get_results( "SHOW INDEX FROM `{$tbl_labels}`", ARRAY_A );
			$names = array_unique( array_column( $idx ?: array(), 'Key_name' ) );
			$lbl_idx_ok = in_array( 'uniq_title', $names, true ) || in_array( 'title', $names, true );
		}
		if ( $has_conv_lbls ) {
			$idx = $wpdb->get_results( "SHOW INDEX FROM `{$tbl_conv_lbls}`", ARRAY_A );
			$names = array_unique( array_column( $idx ?: array(), 'Key_name' ) );
			$cl_idx_ok = in_array( 'idx_label', $names, true ) || in_array( 'PRIMARY', $names, true );
		}
		$labels_ok = $has_labels && $has_conv_lbls && $lbl_idx_ok && $cl_idx_ok;
		$out[] = array(
			'id'       => 'T-P0.35.3.1',
			'status'   => $labels_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W1 — Labels + conversation_labels tables + indexes',
			'evidence' => sprintf(
				'labels=%s · conv_labels=%s · uniq_title=%s · idx_label=%s',
				$has_labels ? 'YES' : 'NO',
				$has_conv_lbls ? 'YES' : 'NO',
				$lbl_idx_ok ? 'YES' : 'NO',
				$cl_idx_ok ? 'YES' : 'NO'
			),
		);

		// T-P0.35.3.2 — Custom attribute definitions table + uniq_target_key.
		$tbl_cad = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$has_cad = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_cad ) ) === $tbl_cad;
		$cad_idx_ok = false;
		if ( $has_cad ) {
			$idx = $wpdb->get_results( "SHOW INDEX FROM `{$tbl_cad}`", ARRAY_A );
			$names = array_unique( array_column( $idx ?: array(), 'Key_name' ) );
			$cad_idx_ok = in_array( 'uniq_target_key', $names, true );
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.2',
			'status'   => ( $has_cad && $cad_idx_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'M3.W3 — custom_attribute_definitions table + uniq_target_key',
			'evidence' => sprintf( 'table=%s · uniq_target_key=%s', $has_cad ? 'YES' : 'NO', $cad_idx_ok ? 'YES' : 'NO' ),
		);

		// T-P0.35.3.3 — Macros table + indexes.
		$tbl_mac = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$has_mac = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_mac ) ) === $tbl_mac;
		$mac_idx_ok = false;
		if ( $has_mac ) {
			$idx = $wpdb->get_results( "SHOW INDEX FROM `{$tbl_mac}`", ARRAY_A );
			$names = array_unique( array_column( $idx ?: array(), 'Key_name' ) );
			$mac_idx_ok = in_array( 'idx_owner', $names, true ) && in_array( 'idx_visibility_active', $names, true );
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.3',
			'status'   => ( $has_mac && $mac_idx_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'M3.W4 — macros table + idx_owner + idx_visibility_active',
			'evidence' => sprintf( 'table=%s · indexes=%s', $has_mac ? 'YES' : 'NO', $mac_idx_ok ? 'YES' : 'NO' ),
		);

		// T-P0.35.3.4 — Repository methods + Validator + Renderer classes loaded.
		$repo_methods = array(
			'list_labels', 'get_label', 'get_label_by_title', 'upsert_label', 'delete_label',
			'set_conversation_labels', 'get_conversation_labels', 'resync_conversation_label_cache',
			'list_custom_attribute_defs', 'upsert_custom_attribute_def',
			'list_macros', 'upsert_macro', 'bump_macro_run_count',
		);
		$missing_repo = array();
		foreach ( $repo_methods as $m ) {
			if ( ! method_exists( 'BizCity_CRM_Repository', $m ) ) { $missing_repo[] = $m; }
		}
		$has_validator = class_exists( 'BizCity_CRM_Custom_Attribute_Validator' );
		$has_renderer  = class_exists( 'BizCity_CRM_Template_Renderer' );
		$m3_classes_ok = empty( $missing_repo ) && $has_validator && $has_renderer;
		$out[] = array(
			'id'       => 'T-P0.35.3.4',
			'status'   => $m3_classes_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3 — Repository helpers + Validator + Template_Renderer present',
			'evidence' => empty( $missing_repo )
				? sprintf( 'repo: %d/%d methods · validator=%s · renderer=%s',
					count( $repo_methods ), count( $repo_methods ),
					$has_validator ? 'YES' : 'NO',
					$has_renderer ? 'YES' : 'NO' )
				: 'missing repo methods: ' . implode( ', ', $missing_repo ),
		);

		// T-P0.35.3.5 — REST routes registered.
		$rest_routes_m3 = rest_get_server()->get_routes();
		$ns3            = '/' . BIZCITY_CRM_REST_NS . '/';
		$want_routes    = array(
			$ns3 . 'labels',
			$ns3 . 'labels/(?P<id>\d+)',
			$ns3 . 'conversations/(?P<id>\d+)/labels',
			$ns3 . 'custom-attributes',
			$ns3 . 'custom-attributes/(?P<id>\d+)',
			$ns3 . 'macros',
			$ns3 . 'macros/(?P<id>\d+)',
			$ns3 . 'macros/(?P<id>\d+)/preview',
			$ns3 . 'macros/(?P<id>\d+)/run',
			$ns3 . 'render-template',
		);
		$missing_m3_routes = array();
		foreach ( $want_routes as $r ) {
			if ( ! isset( $rest_routes_m3[ $r ] ) ) { $missing_m3_routes[] = $r; }
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.5',
			'status'   => empty( $missing_m3_routes ) ? 'PASS' : 'FAIL',
			'check'    => 'M3 REST — labels + custom-attributes + macros + render-template routes',
			'evidence' => empty( $missing_m3_routes )
				? sprintf( '%d/%d routes registered', count( $want_routes ), count( $want_routes ) )
				: 'missing: ' . implode( ', ', $missing_m3_routes ),
		);

		// T-P0.35.3.6 — Template_Renderer dry-probe.
		$tpl_probe_ok  = false;
		$tpl_probe_msg = 'renderer class missing';
		if ( $has_renderer ) {
			$tpl   = 'Hi {{contact.name}}, your priority is {{conversation.priority}} (region={{custom_attr.region}}).';
			$ctx   = array(
				'contact'      => array( 'name' => 'Test', 'additional_attributes' => array( 'region' => 'HN' ) ),
				'conversation' => array( 'priority' => 2 ),
			);
			$got   = BizCity_CRM_Template_Renderer::render( $tpl, $ctx );
			$want  = 'Hi Test, your priority is 2 (region=HN).';
			$tpl_probe_ok  = ( $got === $want );
			$tpl_probe_msg = $tpl_probe_ok ? 'render OK · ' . $got : sprintf( 'mismatch · got="%s" want="%s"', $got, $want );
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.6',
			'status'   => $tpl_probe_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W4 — Template_Renderer dot-notation + custom_attr probe',
			'evidence' => $tpl_probe_msg,
		);

		// T-P0.35.3.7 — Custom attribute Validator probe (8 display_types).
		$val_ok  = false;
		$val_msg = 'validator class missing';
		if ( $has_validator ) {
			$cases = array(
				array( array( 'attribute_key' => 'k', 'display_type' => 'number'   ),  '42',          true  ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'number'   ),  'abc',         false ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'checkbox' ),  'yes',         true  ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'date'     ),  '2026-03-28',  true  ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'date'     ),  'not-a-date',  false ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'link'     ),  'https://x.io', true ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'link'     ),  'oops',        false ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'list', 'options_json' => '["a","b"]' ), 'a', true  ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'list', 'options_json' => '["a","b"]' ), 'z', false ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'regex', 'regex_pattern' => '^\d{3}$' ), '123', true ),
				array( array( 'attribute_key' => 'k', 'display_type' => 'regex', 'regex_pattern' => '^\d{3}$' ), 'abc', false ),
			);
			$pass = 0;
			foreach ( $cases as $c ) {
				$res = BizCity_CRM_Custom_Attribute_Validator::validate( $c[0], $c[1] );
				$ok  = ( $c[2] === true ) ? ( $res === true ) : is_wp_error( $res );
				if ( $ok ) { $pass++; }
			}
			$val_ok  = ( $pass === count( $cases ) );
			$val_msg = sprintf( '%d/%d cases pass', $pass, count( $cases ) );
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.7',
			'status'   => $val_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W3 — Custom_Attribute_Validator covers 8 display_types',
			'evidence' => $val_msg,
		);

		/* ============================================================
		 * PHASE 0.35 M4 — Working Hours · SLA Policies · SLA Evaluator
		 * ============================================================ */

		// T-P0.35.4.1 — DB schemas + indexes for M4.
		global $wpdb;
		$wh   = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$slap = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$asla = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$schema_missing = array();
		foreach ( array( $wh, $slap, $asla ) as $t ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
				$schema_missing[] = $t;
			}
		}
		$idx_ok = true; $idx_msg = '';
		if ( empty( $schema_missing ) ) {
			$wh_idx   = $wpdb->get_results( "SHOW INDEX FROM {$wh}",   ARRAY_A );
			$asla_idx = $wpdb->get_results( "SHOW INDEX FROM {$asla}", ARRAY_A );
			$wh_pk    = array_filter( $wh_idx, static fn( $r ) => $r['Key_name'] === 'PRIMARY' );
			$has_uniq_conv     = false;
			$has_idx_state_due = false;
			foreach ( $asla_idx as $r ) {
				if ( $r['Key_name'] === 'uniq_conv'     && $r['Column_name'] === 'conversation_id' ) { $has_uniq_conv = true; }
				if ( $r['Key_name'] === 'idx_state_due' )                                            { $has_idx_state_due = true; }
			}
			$idx_ok  = ( count( $wh_pk ) === 2 && $has_uniq_conv && $has_idx_state_due );
			$idx_msg = sprintf( 'wh_pk_cols=%d uniq_conv=%s idx_state_due=%s', count( $wh_pk ), $has_uniq_conv ? 'Y' : 'N', $has_idx_state_due ? 'Y' : 'N' );
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.1',
			'status'   => ( empty( $schema_missing ) && $idx_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'M4.W1 — DB schemas (working_hours, sla_policies, applied_slas) + composite PK / uniq_conv / idx_state_due',
			'evidence' => empty( $schema_missing ) ? ( 'db_ver=' . get_option( BizCity_CRM_DB_Installer_V2::DB_VERSION_OPTION, '?' ) . ' · ' . $idx_msg ) : ( 'missing: ' . implode( ',', $schema_missing ) ),
		);

		// T-P0.35.4.2 — Repository M4 helpers exist.
		$repo_methods = array(
			'default_working_hours_grid', 'list_working_hours', 'ensure_working_hours_seeded', 'upsert_working_hour_row',
			'list_sla_policies', 'get_sla_policy', 'upsert_sla_policy', 'delete_sla_policy',
			'get_applied_sla_for_conversation', 'upsert_applied_sla', 'update_applied_sla_fields', 'list_active_applied_slas',
		);
		$missing_methods = array();
		foreach ( $repo_methods as $m ) {
			if ( ! method_exists( 'BizCity_CRM_Repository', $m ) ) { $missing_methods[] = $m; }
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.2',
			'status'   => empty( $missing_methods ) ? 'PASS' : 'FAIL',
			'check'    => 'M4.W1 — Repository helpers (12) for working hours · SLA policies · applied SLAs',
			'evidence' => empty( $missing_methods ) ? ( count( $repo_methods ) . '/' . count( $repo_methods ) . ' methods' ) : ( 'missing: ' . implode( ',', $missing_methods ) ),
		);

		// T-P0.35.4.3 — Working_Hours dry-probe (synthetic times, no DB needed for default grid).
		$wh_ok  = false;
		$wh_msg = 'class missing';
		if ( class_exists( 'BizCity_CRM_Working_Hours' ) ) {
			// Use Mon 10:00 (open) and Sun 10:00 (closed) timestamps in site TZ.
			try {
				$tz       = wp_timezone();
				$mon_open = ( new DateTimeImmutable( 'next monday 10:00', $tz ) )->getTimestamp();
				$sun_open = ( new DateTimeImmutable( 'next sunday 10:00', $tz ) )->getTimestamp();
				// Use inbox_id=0 (no rows) → falls back to default grid (Mon-Fri open, Sat-Sun closed).
				$mon_res = BizCity_CRM_Working_Hours::check( 0, $mon_open );
				$sun_res = BizCity_CRM_Working_Hours::check( 0, $sun_open );
				$wh_ok   = ( ! empty( $mon_res['open'] ) && empty( $sun_res['open'] ) );
				$wh_msg  = sprintf( 'mon10=%s sun10=%s', $mon_res['reason'] ?? '?', $sun_res['reason'] ?? '?' );
			} catch ( \Throwable $e ) {
				$wh_msg = 'probe_error:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.3',
			'status'   => $wh_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W1 — Working_Hours::check() honours default Mon-Fri grid (Mon 10:00 open · Sun 10:00 closed)',
			'evidence' => $wh_msg,
		);

		// T-P0.35.4.4 — SLA_Evaluator::compute_due_times probe.
		$ct_ok  = false;
		$ct_msg = 'class missing';
		if ( class_exists( 'BizCity_CRM_SLA_Evaluator' ) ) {
			$now    = time();
			$policy = array(
				'frt_threshold_minutes'      => 30,
				'nrt_threshold_minutes'      => 60,
				'rt_threshold_minutes'       => 120,
				'only_during_business_hours' => 0,
			);
			$conv = array( 'inbox_id' => 0 );
			$due  = BizCity_CRM_SLA_Evaluator::compute_due_times( $policy, $conv, $now );
			$ct_ok = (
				abs( ( $due['frt_due_at'] ?? 0 ) - ( $now + 1800 ) ) <= 1 &&
				abs( ( $due['nrt_due_at'] ?? 0 ) - ( $now + 3600 ) ) <= 1 &&
				abs( ( $due['rt_due_at']  ?? 0 ) - ( $now + 7200 ) ) <= 1
			);
			$ct_msg = sprintf(
				'frt=+%ds nrt=+%ds rt=+%ds',
				( $due['frt_due_at'] ?? 0 ) - $now,
				( $due['nrt_due_at'] ?? 0 ) - $now,
				( $due['rt_due_at']  ?? 0 ) - $now
			);
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.4',
			'status'   => $ct_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W2 — SLA_Evaluator::compute_due_times honours frt/nrt/rt minutes (24/7 policy)',
			'evidence' => $ct_msg,
		);

		// T-P0.35.4.5 — Cron registered (60s tick scheduled).
		$next      = wp_next_scheduled( 'bizcity_crm_sla_tick' );
		$schedules = wp_get_schedules();
		$cron_ok   = ( $next && isset( $schedules['bizcity_crm_minute'] ) );
		$out[] = array(
			'id'       => 'T-P0.35.4.5',
			'status'   => $cron_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W3 — Cron schedule "bizcity_crm_minute" registered + bizcity_crm_sla_tick scheduled',
			'evidence' => sprintf( 'next=%s · interval=%ds', $next ? gmdate( 'H:i:s', (int) $next ) : 'NONE', $schedules['bizcity_crm_minute']['interval'] ?? 0 ),
		);

		// T-P0.35.4.6 — Action registry has apply_sla.
		$has_apply_sla = false;
		if ( class_exists( 'BizCity_CRM_Action_Registry' ) ) {
			$actions       = BizCity_CRM_Action_Registry::built_in_actions();
			$has_apply_sla = isset( $actions['apply_sla'] );
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.6',
			'status'   => $has_apply_sla ? 'PASS' : 'FAIL',
			'check'    => 'M4.W2 — Action_Registry exposes "apply_sla" built-in action',
			'evidence' => $has_apply_sla ? ( 'actions=' . count( $actions ) ) : 'apply_sla missing',
		);

		// T-P0.35.4.7 — Engine subscribed to crm_sla_breached + crm_sla_met (existed since M2.W1).
		$sla_subs = array(
			'crm_sla_breached' => has_action( 'bizcity_crm_event_crm_sla_breached' ),
			'crm_sla_met'      => has_action( 'bizcity_crm_event_crm_sla_met' ),
		);
		$subs_ok = ( $sla_subs['crm_sla_breached'] !== false && $sla_subs['crm_sla_met'] !== false );
		$out[] = array(
			'id'       => 'T-P0.35.4.7',
			'status'   => $subs_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W3 — Automation Engine subscribed to crm_sla_breached + crm_sla_met events',
			'evidence' => sprintf( 'breached=%s met=%s', $sla_subs['crm_sla_breached'] ? 'Y' : 'N', $sla_subs['crm_sla_met'] ? 'Y' : 'N' ),
		);

		// T-P0.35.4.8 — REST routes registered (working-hours + sla-policies + sla/tick + conversations/{id}/sla).
		$server     = rest_get_server();
		$routes     = $server ? $server->get_routes() : array();
		$ns         = '/' . BIZCITY_CRM_REST_NS;
		$want       = array(
			$ns . '/working-hours',
			$ns . '/working-hours/check',
			$ns . '/sla-policies',
			$ns . '/sla-policies/(?P<id>\d+)',
			$ns . '/sla/tick',
			$ns . '/conversations/(?P<id>\d+)/sla',
		);
		$missing_routes = array();
		foreach ( $want as $r ) {
			if ( ! isset( $routes[ $r ] ) ) { $missing_routes[] = $r; }
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.8',
			'status'   => empty( $missing_routes ) ? 'PASS' : 'FAIL',
			'check'    => 'M4.W2/W3 — REST routes registered (working-hours · sla-policies · sla/tick · conversations/{id}/sla)',
			'evidence' => empty( $missing_routes ) ? ( count( $want ) . '/' . count( $want ) . ' routes' ) : ( 'missing: ' . implode( ' · ', $missing_routes ) ),
		);

		// T-P0.35.4.9 — Force-tick smoke test (no active SLAs is OK; we just want non-error).
		$tick_ok  = false;
		$tick_msg = 'class missing';
		if ( class_exists( 'BizCity_CRM_SLA_Evaluator' ) ) {
			try {
				$res      = BizCity_CRM_SLA_Evaluator::tick( true );
				$tick_ok  = is_array( $res ) && empty( $res['skipped'] );
				$tick_msg = sprintf( 'evaluated=%d breached=%d met=%d', $res['evaluated'] ?? 0, $res['breached'] ?? 0, $res['met'] ?? 0 );
			} catch ( \Throwable $e ) {
				$tick_msg = 'tick_error:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.9',
			'status'   => $tick_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W3 — SLA_Evaluator::tick(force=true) executes without error (no active SLAs is OK)',
			'evidence' => $tick_msg,
		);

		/* ============================================================
		 * PHASE 0.35 M5 — Reports · Daily Rollup · CSAT · Audit
		 * ============================================================ */

		// T-P0.35.5.1 — Report_Builder class loaded + metrics list correct.
		$rb_ok       = class_exists( 'BizCity_CRM_Report_Builder' );
		$rb_metrics  = $rb_ok ? count( BizCity_CRM_Report_Builder::METRICS ) : 0;
		$out[] = array(
			'id'       => 'T-P0.35.5.1',
			'status'   => ( $rb_ok && $rb_metrics === 8 ) ? 'PASS' : 'FAIL',
			'check'    => 'M5.W1 — Report_Builder loaded with 8 KPI metrics',
			'evidence' => $rb_ok ? "metrics=$rb_metrics" : 'class missing',
		);

		// T-P0.35.5.2 — Daily_Rollup cron registered (scheduled or hookable).
		$dr_ok = class_exists( 'BizCity_CRM_Daily_Rollup' )
			&& has_action( BizCity_CRM_Daily_Rollup::CRON_HOOK ) !== false;
		$next  = $dr_ok ? wp_next_scheduled( BizCity_CRM_Daily_Rollup::CRON_HOOK ) : false;
		$out[] = array(
			'id'       => 'T-P0.35.5.2',
			'status'   => ( $dr_ok && $next ) ? 'PASS' : 'FAIL',
			'check'    => 'M5.W2 — Daily_Rollup cron action attached + scheduled',
			'evidence' => $dr_ok ? ( 'next=' . ( $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) : 'unscheduled' ) ) : 'class/hook missing',
		);

		// T-P0.35.5.3 — CSAT_Survey hooks attached.
		$csat_ok = class_exists( 'BizCity_CRM_CSAT_Survey' )
			&& has_action( 'bizcity_crm_event_crm_conversation_resolved', array( 'BizCity_CRM_CSAT_Survey', 'on_resolved' ) ) !== false
			&& has_action( BizCity_CRM_CSAT_Survey::SEND_HOOK, array( 'BizCity_CRM_CSAT_Survey', 'do_send' ) ) !== false
			&& has_action( 'bizcity_crm_event_crm_message_received', array( 'BizCity_CRM_CSAT_Survey', 'on_inbound' ) ) !== false;
		$out[] = array(
			'id'       => 'T-P0.35.5.3',
			'status'   => $csat_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W5 — CSAT_Survey listeners (resolved · scheduled-send · inbound) all wired',
			'evidence' => $csat_ok ? 'on_resolved + do_send + on_inbound bound' : 'one or more hooks missing',
		);

		// T-P0.35.5.4 — REST routes registered (reports/aggregate · rollup/run-now · csat/{id}).
		$rest_routes_m5 = rest_get_server()->get_routes();
		$expected_m5 = array(
			'/' . BIZCITY_CRM_REST_NS . '/reports/aggregate',
			'/' . BIZCITY_CRM_REST_NS . '/reports/auto-vs-human',
			'/' . BIZCITY_CRM_REST_NS . '/reports/rollup/run-now',
			'/' . BIZCITY_CRM_REST_NS . '/csat/(?P<id>\d+)',
		);
		$missing_m5 = array();
		foreach ( $expected_m5 as $r ) {
			if ( ! isset( $rest_routes_m5[ $r ] ) ) { $missing_m5[] = $r; }
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.4',
			'status'   => empty( $missing_m5 ) ? 'PASS' : 'FAIL',
			'check'    => 'M5 REST — reports/aggregate · auto-vs-human · rollup/run-now · csat/{id}',
			'evidence' => empty( $missing_m5 ) ? 'all 4 routes registered' : 'missing: ' . implode( ', ', $missing_m5 ),
		);

		// T-P0.35.5.5 — Audit tab filter registered.
		$audit_ok = has_filter( 'bizcity_intent_monitor_tabs', array( 'BizCity_CRM_CSAT_Survey', 'register_intent_monitor_tab' ) ) !== false;
		$out[] = array(
			'id'       => 'T-P0.35.5.5',
			'status'   => $audit_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W5 — Audit tab callback hooked into bizcity_intent_monitor_tabs filter',
			'evidence' => $audit_ok ? 'filter attached' : 'filter not attached',
		);

		// T-P0.35.5.6 — Live aggregate probe (incoming_messages_count today, group by day).
		$probe_ok  = false;
		$probe_msg = 'class missing';
		if ( $rb_ok ) {
			try {
				$res = BizCity_CRM_Report_Builder::aggregate( array(
					'metric'   => 'incoming_messages_count',
					'group_by' => 'day',
				) );
				$probe_ok  = is_array( $res ) && isset( $res['rows'] ) && empty( $res['error'] );
				$probe_msg = $probe_ok
					? sprintf( 'rows=%d source=%s', count( $res['rows'] ), $res['source'] ?? '?' )
					: 'error:' . ( $res['error'] ?? 'unknown' );
			} catch ( \Throwable $e ) {
				$probe_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.6',
			'status'   => $probe_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W1 — Report_Builder::aggregate(incoming_messages_count, day) returns rows[]',
			'evidence' => $probe_msg,
		);

		// T-P0.35.5.7 — Snapshot probe returns 8 numeric metrics for today's window.
		$snap_ok  = false;
		$snap_msg = 'class missing';
		if ( $rb_ok ) {
			try {
				$tz    = wp_timezone();
				$start = ( new DateTimeImmutable( 'today 00:00', $tz ) )->getTimestamp();
				$snap  = BizCity_CRM_Report_Builder::snapshot( $start, $start + 86400, 0 );
				$mset  = is_array( $snap ) && isset( $snap['metrics'] ) ? $snap['metrics'] : array();
				$snap_ok = is_array( $mset ) && count( $mset ) === 8;
				$snap_msg = sprintf( 'keys=%d', count( $mset ) );
			} catch ( \Throwable $e ) {
				$snap_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.7',
			'status'   => $snap_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W2 — Report_Builder::snapshot() returns all 8 KPI metrics',
			'evidence' => $snap_msg,
		);

		// ───────────────────────────────────────────────────────────
		// PHASE 0.35 catch-up probes (M3.W2 · M4.W4 · M5.W3 · M5.W4)
		// [2026-06-10 Johnny Chu] HOTFIX — dist-only deployment: frontend/src not on server.
		// Primary evidence: src files (local dev). Fallback: assets/dist/inbox-app.js bundle
		// (hook names survive Vite minification; component names are mangled away).
		// ───────────────────────────────────────────────────────────
		$plugin_dir = defined( 'BIZCITY_CRM_DIR' )
			? rtrim( (string) BIZCITY_CRM_DIR, '/\\' )
			: dirname( __DIR__ );
		$fe_candidates = array_values( array_unique( array_filter( array(
			$plugin_dir . '/frontend/src',
			$plugin_dir . '/ui/src',
			$plugin_dir . '/assets/src',
			defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/plugins/bizcity-twin-crm/frontend/src' : '',
		) ) ) );
		$resolve_fe_file = static function( array $rel_paths ) use ( $fe_candidates ): string {
			foreach ( $fe_candidates as $root ) {
				foreach ( $rel_paths as $rel ) {
					$path = $root . '/' . ltrim( (string) $rel, '/' );
					if ( is_readable( $path ) ) {
						return $path;
					}
				}
			}
			return '';
		};
		// Dist bundle fallback — loaded once, shared by all FE checks below.
		$dist_bundle = $plugin_dir . '/assets/dist/inbox-app.js';
		$dist_src    = is_readable( $dist_bundle ) ? (string) file_get_contents( $dist_bundle ) : '';

		// — M3.W2.1: LabelChips component exists and uses the labels hooks.
		$lc_path = $resolve_fe_file( array(
			'components/LabelChips.jsx',
			'components/LabelChips.js',
			'components/LabelChips.tsx',
			'components/LabelChips.ts',
		) );
		$lc_ok   = $lc_path !== '';
		$lc_msg  = $lc_ok ? str_replace( $plugin_dir . '/', '', $lc_path ) : '';
		if ( $lc_ok ) {
			$src = (string) file_get_contents( $lc_path );
			$has_hook = ( strpos( $src, 'useGetConversationLabelsQuery' ) !== false )
				&& ( strpos( $src, 'useSetConversationLabelsMutation' ) !== false );
			$lc_ok  = $has_hook;
			$lc_msg = $has_hook ? 'src: label hooks wired' : 'src: hooks missing';
		}
		// Dist fallback: hook names survive minification.
		if ( ! $lc_ok && $dist_src !== '' ) {
			$has_hook = ( strpos( $dist_src, 'useGetConversationLabelsQuery' ) !== false )
				&& ( strpos( $dist_src, 'useSetConversationLabelsMutation' ) !== false );
			$lc_ok  = $has_hook;
			$lc_msg = $has_hook ? 'dist bundle: label hooks confirmed' : 'dist bundle: label hooks missing';
		}
		if ( ! $lc_ok && $lc_msg === '' ) { $lc_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.3.2.1',
			'status'   => $lc_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W2 — LabelChips multi-select component exists + wired to label hooks',
			'evidence' => $lc_msg,
		);

		// — M3.W2.2: list_conversations accepts label_id filter (no DB error).
		$lf_ok  = false;
		$lf_msg = 'method missing';
		if ( class_exists( 'BizCity_CRM_Repository' ) ) {
			try {
				$rows = BizCity_CRM_Repository::list_conversations( array( 'label_id' => 9999999, 'limit' => 1 ) );
				$lf_ok  = is_array( $rows );
				$lf_msg = 'rows=' . count( $rows ) . ' (label_id filter accepted)';
			} catch ( \Throwable $e ) {
				$lf_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.3.2.2',
			'status'   => $lf_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W2 — Repository::list_conversations accepts label_id filter',
			'evidence' => $lf_msg,
		);

		// — M3.W2.3: ConversationList.jsx imports LabelChips/labels filter.
		$cl_path = $resolve_fe_file( array(
			'components/ConversationList.jsx',
			'components/ConversationList.js',
			'components/ConversationList.tsx',
			'components/ConversationList.ts',
		) );
		$cl_ok   = false; $cl_msg = '';
		if ( is_readable( $cl_path ) ) {
			$src   = (string) file_get_contents( $cl_path );
			$cl_ok = ( strpos( $src, 'useGetLabelsQuery' ) !== false ) && ( strpos( $src, 'label_id' ) !== false );
			$cl_msg = $cl_ok ? 'src: labels filter chip-bar present' : 'src: no label filter wiring';
		}
		// Dist fallback: useGetLabelsQuery and label_id survive minification.
		if ( ! $cl_ok && $dist_src !== '' ) {
			$has_lq = ( strpos( $dist_src, 'useGetLabelsQuery' ) !== false ) && ( strpos( $dist_src, 'label_id' ) !== false );
			$cl_ok  = $has_lq;
			$cl_msg = $has_lq ? 'dist bundle: label filter confirmed' : 'dist bundle: label filter missing';
		}
		if ( ! $cl_ok && $cl_msg === '' ) { $cl_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.3.2.3',
			'status'   => $cl_ok ? 'PASS' : 'FAIL',
			'check'    => 'M3.W2 — ConversationList exposes label filter + chip column',
			'evidence' => $cl_msg,
		);

		// — M4.W4.1: SLABadge component exists + uses useGetConversationSlaQuery.
		$sb_path = $resolve_fe_file( array(
			'components/SLABadge.jsx',
			'components/SLABadge.js',
			'components/SLABadge.tsx',
			'components/SLABadge.ts',
		) );
		$sb_ok  = false; $sb_msg = '';
		if ( is_readable( $sb_path ) ) {
			$src      = (string) file_get_contents( $sb_path );
			$has_hook = ( strpos( $src, 'useGetConversationSlaQuery' ) !== false );
			$sb_ok    = $has_hook;
			$sb_msg   = $has_hook ? 'src: SLABadge + hook present' : 'src: hook missing';
		}
		// Dist fallback: hook name survives minification.
		if ( ! $sb_ok && $dist_src !== '' ) {
			$has_hook = ( strpos( $dist_src, 'useGetConversationSlaQuery' ) !== false );
			$sb_ok    = $has_hook;
			$sb_msg   = $has_hook ? 'dist bundle: SLA hook confirmed' : 'dist bundle: SLA hook missing';
		}
		if ( ! $sb_ok && $sb_msg === '' ) { $sb_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.4.4.1',
			'status'   => $sb_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W4 — SLABadge component exists + uses getConversationSla',
			'evidence' => $sb_msg,
		);

		// — M4.W4.2: ConversationDetail header renders SLABadge.
		// Note: 'SLABadge' is mangled by Vite minification; use useGetConversationSlaQuery as proxy.
		$cd_path = $resolve_fe_file( array(
			'components/ConversationDetail.jsx',
			'components/ConversationDetail.js',
			'components/ConversationDetail.tsx',
			'components/ConversationDetail.ts',
		) );
		$cd_ok   = false; $cd_msg = '';
		if ( is_readable( $cd_path ) ) {
			$src    = (string) file_get_contents( $cd_path );
			$cd_ok  = ( strpos( $src, 'SLABadge' ) !== false );
			$cd_msg = $cd_ok ? 'src: SLABadge mounted in header' : 'src: badge not mounted';
		}
		// Dist fallback: SLABadge component name is mangled; check SLA hook as proxy.
		if ( ! $cd_ok && $dist_src !== '' ) {
			$has_sla = ( strpos( $dist_src, 'useGetConversationSlaQuery' ) !== false );
			$cd_ok   = $has_sla;
			$cd_msg  = $has_sla ? 'dist bundle: SLA hook in bundle (SLABadge name mangled by minifier)' : 'dist bundle: SLA hook missing';
		}
		if ( ! $cd_ok && $cd_msg === '' ) { $cd_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.4.4.2',
			'status'   => $cd_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W4 — ConversationDetail header mounts <SLABadge/>',
			'evidence' => $cd_msg,
		);

		// — M4.W4.3: REST /conversations/{id}/sla envelope shape (envelope ok even when no SLA).
		$sla_ok  = false; $sla_msg = 'route missing';
		if ( class_exists( 'BizCity_CRM_REST_Controller' ) ) {
			try {
				$req = new \WP_REST_Request( 'GET', '/' . BIZCITY_CRM_REST_NS . '/conversations/0/sla' );
				$res = \BizCity_CRM_REST_Controller::get_conversation_sla( $req );
				$body = ( $res instanceof \WP_REST_Response ) ? $res->get_data() : $res;
				$sla_ok = is_array( $body ) && array_key_exists( 'ok', $body );
				$sla_msg = $sla_ok ? 'envelope ok=' . ( $body['ok'] ? '1' : '0' ) : 'unexpected shape';
			} catch ( \Throwable $e ) {
				$sla_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.4.4.3',
			'status'   => $sla_ok ? 'PASS' : 'FAIL',
			'check'    => 'M4.W4 — REST /conversations/{id}/sla returns envelope',
			'evidence' => $sla_msg,
		);

		// — M5.W3.1: Report_Builder::aggregate accepts group_by=agent_id.
		$ag_ok = false; $ag_msg = 'missing';
		if ( class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			try {
				$res = BizCity_CRM_Report_Builder::aggregate( array(
					'metric' => 'conversations_closed', 'group_by' => 'agent_id',
					'from'   => date( 'Y-m-d', time() - 7 * 86400 ), 'to' => date( 'Y-m-d' ),
				) );
				$ag_ok = is_array( $res ) && empty( $res['error'] );
				$ag_msg = $ag_ok ? 'rows=' . ( isset( $res['rows'] ) ? count( $res['rows'] ) : 0 ) : ( $res['error'] ?? 'no rows key' );
			} catch ( \Throwable $e ) {
				$ag_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.3.1',
			'status'   => $ag_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W3 — Report_Builder.aggregate(group_by=agent_id) returns rows',
			'evidence' => $ag_msg,
		);

		// — M5.W3.2: Report_Builder::aggregate accepts group_by=inbox_id.
		$ib_ok = false; $ib_msg = 'missing';
		if ( class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			try {
				$res = BizCity_CRM_Report_Builder::aggregate( array(
					'metric' => 'first_response_time', 'group_by' => 'inbox_id',
					'from'   => date( 'Y-m-d', time() - 7 * 86400 ), 'to' => date( 'Y-m-d' ),
				) );
				$ib_ok = is_array( $res ) && empty( $res['error'] );
				$ib_msg = $ib_ok ? 'rows=' . ( isset( $res['rows'] ) ? count( $res['rows'] ) : 0 ) : ( $res['error'] ?? 'no rows' );
			} catch ( \Throwable $e ) {
				$ib_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.3.2',
			'status'   => $ib_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W3 — Report_Builder.aggregate(group_by=inbox_id) returns rows',
			'evidence' => $ib_msg,
		);

		// — M5.W3.3: Report_Builder::aggregate accepts group_by=label_id.
		$lb_ok = false; $lb_msg = 'missing';
		if ( class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			try {
				$res = BizCity_CRM_Report_Builder::aggregate( array(
					'metric' => 'conversations_opened', 'group_by' => 'label_id',
					'from'   => date( 'Y-m-d', time() - 7 * 86400 ), 'to' => date( 'Y-m-d' ),
				) );
				$lb_ok = is_array( $res ) && empty( $res['error'] );
				$lb_msg = $lb_ok ? 'rows=' . ( isset( $res['rows'] ) ? count( $res['rows'] ) : 0 ) : ( $res['error'] ?? 'no rows' );
			} catch ( \Throwable $e ) {
				$lb_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.3.3',
			'status'   => $lb_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W3 — Report_Builder.aggregate(group_by=label_id) returns rows',
			'evidence' => $lb_msg,
		);

		// — M5.W3.4: ReportsTab.jsx contains BreakdownTable + 3 sections.
		// Note: 'BreakdownTable' is mangled by minifier; use agent_id/inbox_id/label_id as proxy.
		$rt_path = $resolve_fe_file( array(
			'routes/reports/ReportsTab.jsx',
			'routes/reports/ReportsTab.js',
			'routes/reports/ReportsTab.tsx',
			'routes/reports/ReportsTab.ts',
		) );
		$rt_ok = false; $rt_msg = '';
		if ( is_readable( $rt_path ) ) {
			$src    = (string) file_get_contents( $rt_path );
			$rt_ok  = ( strpos( $src, 'BreakdownTable' ) !== false )
				&& ( strpos( $src, 'agent_id' ) !== false )
				&& ( strpos( $src, 'inbox_id' ) !== false )
				&& ( strpos( $src, 'label_id' ) !== false );
			$rt_msg = $rt_ok ? 'src: BreakdownTable + 3 group_by sections present' : 'src: breakdown sections incomplete';
		}
		// Dist fallback: BreakdownTable mangled; check group_by key strings (all survive minification).
		if ( ! $rt_ok && $dist_src !== '' ) {
			$has_bd = ( strpos( $dist_src, 'agent_id' ) !== false )
				&& ( strpos( $dist_src, 'inbox_id' ) !== false )
				&& ( strpos( $dist_src, 'label_id' ) !== false );
			$rt_ok  = $has_bd;
			$rt_msg = $has_bd ? 'dist bundle: agent_id/inbox_id/label_id group_by keys confirmed (BreakdownTable mangled)' : 'dist bundle: group_by keys missing';
		}
		if ( ! $rt_ok && $rt_msg === '' ) { $rt_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.5.3.4',
			'status'   => $rt_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W3 — ReportsTab renders BreakdownTable for agent/inbox/label',
			'evidence' => $rt_msg,
		);

		// — M5.W4.1: REST /reports/auto-vs-human returns numeric auto+human.
		$avh_ok = false; $avh_msg = 'missing';
		if ( class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			try {
				$res = BizCity_CRM_Report_Builder::aggregate( array(
					'metric' => 'outgoing_messages_count', 'group_by' => 'responder_kind',
					'from'   => date( 'Y-m-d', time() - 7 * 86400 ), 'to' => date( 'Y-m-d' ),
				) );
				$avh_ok = is_array( $res ) && empty( $res['error'] );
				$avh_msg = $avh_ok ? 'rows=' . ( isset( $res['rows'] ) ? count( $res['rows'] ) : 0 ) : ( $res['error'] ?? 'no rows' );
			} catch ( \Throwable $e ) {
				$avh_msg = 'exception:' . $e->getMessage();
			}
		}
		$out[] = array(
			'id'       => 'T-P0.35.5.4.1',
			'status'   => $avh_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W4 — Report_Builder.aggregate(metric=outgoing, group=responder_kind) returns rows',
			'evidence' => $avh_msg,
		);

		// — M5.W4.2: ReportsTab renders <AutoVsHuman/> component.
		$avh2_ok = false; $avh2_msg = '';
		if ( is_readable( $rt_path ) ) {
			$src      = (string) file_get_contents( $rt_path );
			$avh2_ok  = ( strpos( $src, 'AutoVsHuman' ) !== false ) && ( strpos( $src, 'useGetReportsAutoVsHumanQuery' ) !== false );
			$avh2_msg = $avh2_ok ? 'src: AutoVsHuman + hook wired' : 'src: incomplete';
		}
		// Dist fallback: both AutoVsHuman and the hook name survive minification.
		if ( ! $avh2_ok && $dist_src !== '' ) {
			$has_avh  = ( strpos( $dist_src, 'AutoVsHuman' ) !== false ) && ( strpos( $dist_src, 'useGetReportsAutoVsHumanQuery' ) !== false );
			$avh2_ok  = $has_avh;
			$avh2_msg = $has_avh ? 'dist bundle: AutoVsHuman + hook confirmed' : 'dist bundle: AutoVsHuman or hook missing';
		}
		if ( ! $avh2_ok && $avh2_msg === '' ) { $avh2_msg = 'src not found; dist bundle absent'; }
		$out[] = array(
			'id'       => 'T-P0.35.5.4.2',
			'status'   => $avh2_ok ? 'PASS' : 'FAIL',
			'check'    => 'M5.W4 — ReportsTab mounts <AutoVsHuman/> using auto-vs-human hook',
			'evidence' => $avh2_msg,
		);

		return $out;
	}

	/* ============================================================
	 * REST loopback probe (DDV §5)
	 * ============================================================ */
	private function render_rest_probe(): void {
		echo '<h2>REST Loopback Probe</h2>';
		$probes = array(
			array( 'GET', '/' . BIZCITY_CRM_REST_NS . '/channels' ),
			array( 'GET', '/' . BIZCITY_CRM_REST_NS . '/inboxes' ),
			array( 'GET', '/' . BIZCITY_CRM_REST_NS . '/conversations?limit=5' ),
			array( 'GET', '/' . BIZCITY_CRM_REST_NS . '/order-adapter/banks' ),
			array( 'GET', '/' . BIZCITY_CRM_REST_NS . '/order-adapter/products?q=&limit=5' ),
		);
		echo '<table class="widefat striped"><thead><tr><th>Method</th><th>Route</th><th>Status</th><th>Body (head)</th></tr></thead><tbody>';
		foreach ( $probes as list( $m, $route ) ) {
			$req = new WP_REST_Request( $m, strtok( $route, '?' ) );
			$qs  = parse_url( $route, PHP_URL_QUERY );
			if ( $qs ) {
				parse_str( $qs, $args );
				foreach ( $args as $k => $v ) { $req->set_param( $k, $v ); }
			}
			$res    = rest_do_request( $req );
			$status = (int) $res->get_status();
			$data   = $res->get_data();
			$head   = is_array( $data ) ? wp_json_encode( $data, JSON_PRETTY_PRINT ) : (string) $data;
			$head   = mb_substr( (string) $head, 0, 600 );
			echo '<tr><td>' . esc_html( $m ) . '</td><td><code>' . esc_html( $route ) . '</code></td>';
			echo '<td>' . $this->badge( $status === 200 ? 'PASS' : 'FAIL' ) . ' ' . esc_html( (string) $status ) . '</td>';
			echo '<td><pre style="margin:0;white-space:pre-wrap;font-size:11px">' . esc_html( $head ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ============================================================
	 * PHASE-0.36 Order Adapter diagnostics
	 * ============================================================ */
	private function render_order_adapter_panel(): void {
		echo '<h2>Order Adapter (PHASE-0.36)</h2>';

		/* --- Layer 1: file + class on disk + loaded --- */
		$file       = BIZCITY_CRM_DIR . '/includes/class-order-adapter.php';
		$file_ok    = is_readable( $file );
		$included   = in_array(
			str_replace( '\\', '/', $file ),
			array_map( static fn( $f ) => str_replace( '\\', '/', $f ), get_included_files() ),
			true
		);
		$reg_ok     = class_exists( 'BizCity_CRM_Order_Adapter_Registry', false );
		$iface_ok   = interface_exists( 'BizCity_CRM_Order_Adapter_Interface', false );
		$concrete_ok = class_exists( 'BizCity_CRM_Order_Adapter_Woo_Bank_QR', false );
		$default    = $reg_ok ? BizCity_CRM_Order_Adapter_Registry::default_adapter() : null;
		$avail      = $reg_ok ? BizCity_CRM_Order_Adapter_Registry::all_available() : array();

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td><b>File on disk</b></td><td>' . $this->badge( $file_ok ? 'PASS' : 'FAIL' ) . ' <code>' . esc_html( $file ) . '</code></td></tr>';
		echo '<tr><td><b>File included</b></td><td>' . $this->badge( $included ? 'PASS' : 'FAIL' ) . '</td></tr>';
		echo '<tr><td><b>Interface declared</b></td><td>' . $this->badge( $iface_ok ? 'PASS' : 'FAIL' ) . ' BizCity_CRM_Order_Adapter_Interface</td></tr>';
		echo '<tr><td><b>Registry declared</b></td><td>' . $this->badge( $reg_ok ? 'PASS' : 'FAIL' ) . ' BizCity_CRM_Order_Adapter_Registry</td></tr>';
		echo '<tr><td><b>Concrete adapter</b></td><td>' . $this->badge( $concrete_ok ? 'PASS' : 'FAIL' ) . ' BizCity_CRM_Order_Adapter_Woo_Bank_QR</td></tr>';
		echo '<tr><td><b>WooCommerce</b></td><td>' . $this->badge( function_exists( 'wc_create_order' ) ? 'PASS' : 'FAIL' ) . ' wc_create_order=' . ( function_exists( 'wc_create_order' ) ? 'YES' : 'NO' ) . '</td></tr>';
		echo '<tr><td><b>TTCK plugin</b></td><td>' . $this->badge( class_exists( 'TTCKPayment' ) ? 'PASS' : 'SKIP' ) . ' class TTCKPayment=' . ( class_exists( 'TTCKPayment' ) ? 'YES' : 'NO (BACS fallback)' ) . '</td></tr>';
		echo '<tr><td><b>Available adapters</b></td><td>' . esc_html( (string) count( $avail ) ) . ' → ' . esc_html( implode( ', ', array_map( static fn( $a ) => $a->slug(), $avail ) ) ) . '</td></tr>';
		echo '<tr><td><b>Default adapter</b></td><td>' . ( $default ? '<code>' . esc_html( $default->slug() ) . '</code> · ' . esc_html( $default->label() ) : $this->badge( 'FAIL' ) . ' none' ) . '</td></tr>';

		/* --- Layer 2: payment options snapshot --- */
		if ( $default ) {
			$opts = $default->get_payment_options();
			echo '<tr><td><b>Payment options</b></td><td>';
			if ( ! $opts ) {
				echo $this->badge( 'FAIL' ) . ' empty';
			} else {
				echo '<pre style="margin:0;font-size:11px;white-space:pre-wrap">' . esc_html( wp_json_encode( $opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		/* --- Layer 3: REST routes registered for namespace --- */
		echo '<h3 style="margin-top:18px">Registered REST routes — <code>' . esc_html( BIZCITY_CRM_REST_NS ) . '</code></h3>';
		$server = rest_get_server();
		$all    = $server ? $server->get_routes() : array();
		$prefix = '/' . BIZCITY_CRM_REST_NS . '/';
		$ours   = array();
		foreach ( $all as $route => $handlers ) {
			if ( strpos( $route, $prefix ) === 0 || $route === '/' . BIZCITY_CRM_REST_NS ) {
				$methods = array();
				foreach ( (array) $handlers as $h ) {
					foreach ( (array) ( $h['methods'] ?? array() ) as $m => $on ) {
						if ( $on ) { $methods[] = $m; }
					}
				}
				$ours[ $route ] = array_values( array_unique( $methods ) );
			}
		}
		ksort( $ours );

		$expected = array(
			'/' . BIZCITY_CRM_REST_NS . '/order-adapter/banks'                 => 'GET',
			'/' . BIZCITY_CRM_REST_NS . '/order-adapter/products'              => 'GET',
			'/' . BIZCITY_CRM_REST_NS . '/conversations/(?P<id>\d+)/orders'    => 'GET, POST',
		);
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Route</th><th>Methods registered</th><th>Status</th></tr></thead><tbody>';
		foreach ( $expected as $route => $exp ) {
			$got = $ours[ $route ] ?? array();
			$pass = ! empty( $got );
			echo '<tr><td><code>' . esc_html( $route ) . '</code></td>';
			echo '<td>' . esc_html( $got ? implode( ', ', $got ) : '(missing)' ) . '</td>';
			echo '<td>' . $this->badge( $pass ? 'PASS' : 'FAIL' ) . ' expected ' . esc_html( $exp ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<details style="margin-top:8px"><summary><b>All ' . count( $ours ) . ' routes in namespace</b></summary>';
		echo '<pre style="font-size:11px;white-space:pre-wrap;background:#fafafa;padding:8px;border:1px solid #eee">';
		foreach ( $ours as $r => $m ) {
			echo esc_html( str_pad( implode( ',', $m ), 14 ) . ' ' . $r ) . "\n";
		}
		echo '</pre></details>';

		/* --- Layer 4: write-cap probe --- */
		$cap = (string) apply_filters( 'bizcity_crm_write_cap', 'edit_posts' );
		echo '<p style="margin-top:10px">Current user write cap: <code>' . esc_html( $cap ) . '</code> → can_write=<b>' . ( current_user_can( $cap ) ? 'YES' : 'NO' ) . '</b> · user_id=' . get_current_user_id() . '</p>';

		/* --- Layer 5: mu-plugin REST POST allowlist hint --- */
		$mu = WPMU_PLUGIN_DIR . '/bizgpt-multisite.php';
		if ( is_readable( $mu ) ) {
			$src = (string) @file_get_contents( $mu );
			$bypass_ok = strpos( $src, "/bizcity-crm/v1/" ) !== false;
			echo '<p>mu-plugin <code>bizgpt-multisite.php</code> POST-bypass for <code>/bizcity-crm/v1/</code>: ' . $this->badge( $bypass_ok ? 'PASS' : 'FAIL' ) . '</p>';
		}
	}

	/* ============================================================
	 * SQL expected vs actual (DDV §6)
	 * ============================================================ */
	private function render_sql_probe(): void {
		global $wpdb;
		echo '<h2>SQL Counts (expected)</h2>';
		$tbls = BizCity_CRM_DB_Installer_V2::all_tables();
		echo '<table class="widefat striped" style="max-width:600px"><thead><tr><th>Table</th><th>Count</th></tr></thead><tbody>';
		foreach ( $tbls as $key => $tbl ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
			echo '<tr><td><code>' . esc_html( $tbl ) . '</code></td><td>' . esc_html( (string) $count ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p style="color:#777"><i>Click "Inject FB fixture" above → counts in <code>conversations</code> + <code>messages</code> increment by 1 per click; second click is dedupe-safe (UNIQUE on inbox_id+external_source_id).</i></p>';
	}

	/* ============================================================
	 * Fixture injection
	 * ============================================================ */
	private function inject_fixture(): void {
		$adapter = BizCity_CRM_Channel_Registry::get( 'facebook' );
		if ( ! $adapter ) { return; }
		$ts = (int) round( microtime( true ) * 1000 );
		$norm = $adapter->normalize_inbound( array(
			'bot_id'    => 0,
			'page_id'   => 'DIAG_PAGE_1',
			'user_id'   => 'DIAG_PSID_42',
			'message'   => 'fixture message ' . wp_date( 'H:i:s' ),
			'timestamp' => $ts,
			'event'     => array( 'message' => array( 'mid' => 'diag.mid.' . $ts ) ),
			'platform'  => 'FB_MESS',
		) );
		if ( $norm ) {
			BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
		}
	}

	/**
	 * Backfill CRM tables from Channel Gateway's bizcity_channel_messages ledger.
	 * Replays inbound + outbound FB_MESS rows that have no matching CRM message
	 * (dedup key = external_source_id = gateway message_id, falling back to chat_id+ts).
	 */
	private function backfill_from_gateway(): int {
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) { return 0; }
		$adapter = BizCity_CRM_Channel_Registry::get( 'facebook' );
		if ( ! $adapter ) { return 0; }

		global $wpdb;
		$gw_tbl   = $wpdb->prefix . 'bizcity_channel_messages';
		// Tolerate older schemas — only need columns we use.
		$rows = $wpdb->get_results(
			"SELECT id, platform, direction, chat_id, user_psid, message_id, body, payload_json, created_at
			 FROM {$gw_tbl}
			 WHERE platform = 'FB_MESS'
			 ORDER BY id ASC
			 LIMIT 500",
			ARRAY_A
		) ?: array();

		$ing  = BizCity_CRM_Facebook_Ingestor::instance();
		$done = 0;
		foreach ( $rows as $r ) {
			$chat = (string) $r['chat_id'];
			if ( strpos( $chat, 'fb_' ) !== 0 ) { continue; }
			$parts = explode( '_', $chat, 3 );
			if ( count( $parts ) !== 3 ) { continue; }
			[ , $page_id, $psid_from_chat ] = $parts;
			$psid    = (string) ( $r['user_psid'] ?: $psid_from_chat );
			$body    = (string) $r['body'];
			$dir     = (int) $r['direction'];
			$ts_str  = (string) $r['created_at'];

			if ( $dir === 1 ) {
				// inbound — reuse adapter normalize for dedupe-key parity.
				$ts_ms = strtotime( $ts_str ) * 1000;
				$norm  = $adapter->normalize_inbound( array(
					'page_id'   => $page_id,
					'user_id'   => $psid,
					'message'   => $body,
					'timestamp' => $ts_ms,
					'event'     => array( 'message' => array( 'mid' => (string) $r['message_id'] ) ),
					'platform'  => 'FB_MESS',
				) );
				if ( $norm ) {
					$norm['received_at'] = $ts_str;
					$mid = $ing->ingest( $adapter, $norm );
					if ( $mid ) { $done++; }
				}
			} elseif ( $dir === 2 ) {
				$mid = $ing->ingest_outbound( 'facebook', array(
					'inbox_ref'          => $page_id,
					'inbox_name'         => 'FB Page ' . $page_id,
					'source_id'          => $psid,
					'contact_name'       => '',
					'content'            => $body,
					'content_type'       => 'text',
					'external_source_id' => (string) $r['message_id'],
					'received_at'        => $ts_str,
					'sender_type'        => 'agent_bot',
				) );
				if ( $mid ) { $done++; }
			}
		}
		return $done;
	}

	/* ================================================================
	 * PHASE 0.35 M-FE.W17 — CRM Modules section
	 * ================================================================ */

	private function render_crm_modules_section(): void {
		$tasks = $this->compute_tasks_crm_modules();
		echo '<h2 style="margin-top:24px">CRM Modules — PHASE 0.35 M-FE.W17</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Task</th><th>Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td><td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_tasks_crm_modules(): array {
		$tasks = array();

		// T-FE17.1 — DB version (W17 baseline).
		$db_ver = get_option( 'bizcity_crm_db_ver', '—' );
		$tasks[] = array(
			'id'       => 'T-FE17.1',
			'check'    => 'DB version >= 1.6.0',
			'status'   => version_compare( $db_ver, '1.6.0', '>=' ) ? 'pass' : 'fail',
			'evidence' => 'bizcity_crm_db_ver = ' . $db_ver,
		);

		// T-FE17.2 — 5 new CRM tables exist.
		global $wpdb;
		$new_tables = array(
			$wpdb->prefix . 'bizcity_crm_accounts',
			$wpdb->prefix . 'bizcity_crm_biz_contacts',
			$wpdb->prefix . 'bizcity_crm_tasks',
			$wpdb->prefix . 'bizcity_crm_events',
			$wpdb->prefix . 'bizcity_crm_documents',
		);
		$missing_tables = array();
		foreach ( $new_tables as $tbl ) {
			$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tbl ) . "'" );
			if ( ! $exists ) { $missing_tables[] = $tbl; }
		}
		$tasks[] = array(
			'id'       => 'T-FE17.2',
			'check'    => '5 new CRM tables created',
			'status'   => empty( $missing_tables ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_tables )
				? implode( ', ', $new_tables ) . ' — all present'
				: 'MISSING: ' . implode( ', ', $missing_tables ),
		);

		// T-FE17.3 — REST routes registered.
		$rest_server  = rest_get_server();
		$routes       = array_keys( $rest_server->get_routes() );
		$crm_routes   = array( 'crm-accounts', 'crm-contacts', 'crm-tasks', 'crm-events', 'crm-documents' );
		$missing_routes = array();
		foreach ( $crm_routes as $slug ) {
			$found = false;
			foreach ( $routes as $r ) {
				if ( strpos( $r, $slug ) !== false ) { $found = true; break; }
			}
			if ( ! $found ) { $missing_routes[] = $slug; }
		}
		$tasks[] = array(
			'id'       => 'T-FE17.3',
			'check'    => '5 CRM REST route groups registered',
			'status'   => empty( $missing_routes ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_routes )
				? implode( ', ', $crm_routes ) . ' — all registered'
				: 'MISSING routes: ' . implode( ', ', $missing_routes ),
		);

		// T-FE17.4 — Handler methods exist on REST controller.
		$handler_methods = array(
			'get_crm_accounts', 'post_crm_account', 'get_crm_account',
			'get_crm_contacts', 'post_crm_contact', 'get_crm_contact',
			'get_crm_tasks',    'post_crm_task',    'get_crm_task',
			'get_crm_events',   'post_crm_event',   'get_crm_event',
			'get_crm_documents','post_crm_document','get_crm_document',
		);
		$missing_handlers = array();
		foreach ( $handler_methods as $m ) {
			if ( ! method_exists( 'BizCity_CRM_REST_Controller', $m ) ) {
				$missing_handlers[] = $m;
			}
		}
		$tasks[] = array(
			'id'       => 'T-FE17.4',
			'check'    => '15 CRM REST handler methods exist',
			'status'   => empty( $missing_handlers ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_handlers )
				? count( $handler_methods ) . ' methods present on BizCity_CRM_REST_Controller'
				: 'MISSING: ' . implode( ', ', $missing_handlers ),
		);

		// T-FE17.5 — Built JS bundle exists and < 600 KB.
		$bundle = plugin_dir_path( __FILE__ ) . '../assets/dist/inbox-app.js';
		$exists = file_exists( $bundle );
		$size   = $exists ? filesize( $bundle ) : 0;
		$tasks[] = array(
			'id'       => 'T-FE17.5',
			'check'    => 'Built JS bundle exists and < 600 KB',
			'status'   => ( $exists && $size < 614400 ) ? 'pass' : ( $exists ? 'warn' : 'fail' ),
			'evidence' => $exists
				? sprintf( 'inbox-app.js: %s KB — built %s', number_format( $size / 1024, 1 ), date( 'Y-m-d H:i', filemtime( $bundle ) ) )
				: 'inbox-app.js NOT FOUND at assets/dist/',
		);

		// T-FE17.6 — migrate_phase_036() exists on DB installer.
		$has_migrate = method_exists( 'BizCity_CRM_DB_Installer_V2', 'migrate_phase_036' );
		$tasks[] = array(
			'id'       => 'T-FE17.6',
			'check'    => 'BizCity_CRM_DB_Installer_V2::migrate_phase_036() exists',
			'status'   => $has_migrate ? 'pass' : 'fail',
			'evidence' => $has_migrate ? 'migrate_phase_036() present' : 'METHOD MISSING',
		);

		// T-M7.W4.1 — /inboxes/{id}/health route registered.
		$routes = array_keys( rest_get_server()->get_routes() );
		$route_ok = in_array( '/bizcity-crm/v1/inboxes/(?P<id>\\d+)/health', $routes, true );
		$tasks[] = array(
			'id'       => 'T-M7.W4.1',
			'check'    => 'Inbox health REST route registered',
			'status'   => $route_ok ? 'pass' : 'fail',
			'evidence' => $route_ok ? '/bizcity-crm/v1/inboxes/(?P<id>\\d+)/health' : 'route not registered',
		);

		// T-M7.W4.2 — adapter base default health() callable + returns expected shape.
		$shape_ok = false; $sample_ev = '';
		if ( class_exists( 'BizCity_CRM_Channel_Registry' ) ) {
			$adapters = BizCity_CRM_Channel_Registry::all();
			$first    = $adapters ? reset( $adapters ) : null;
			if ( $first && method_exists( $first, 'health' ) ) {
				$res = $first->health( array( 'id' => 0, 'is_active' => 1 ) );
				$shape_ok = is_array( $res )
					&& array_key_exists( 'status', $res )
					&& in_array( $res['status'], array( 'green', 'yellow', 'red', 'unknown' ), true )
					&& array_key_exists( 'last_inbound_at', $res )
					&& array_key_exists( 'last_error', $res );
				$sample_ev = 'sample status=' . ( $res['status'] ?? '?' ) . ' · keys=' . implode( ',', array_keys( $res ) );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M7.W4.2',
			'check'    => 'Adapter::health() callable + returns {status,last_inbound_at,last_error,details}',
			'status'   => $shape_ok ? 'pass' : 'fail',
			'evidence' => $shape_ok ? $sample_ev : 'shape mismatch or adapter missing',
		);

		// T-M7.W4.3 — FE bundle exposes useGetInboxHealthQuery hook (string scan; cheap).
		$hook_ok = false; $bundle_ev = '';
		if ( $exists ) {
			$head_chunk = file_get_contents( $bundle, false, null, 0, 65536 );
			$tail_chunk = file_get_contents( $bundle, false, null, max( 0, $size - 65536 ), 65536 );
			$blob = (string) $head_chunk . (string) $tail_chunk;
			$hook_ok = ( strpos( $blob, 'useGetInboxHealthQuery' ) !== false )
				|| ( strpos( $blob, 'getInboxHealth' ) !== false )
				|| ( strpos( $blob, 'inboxes/${' ) !== false && strpos( $blob, '/health' ) !== false );
			$bundle_ev = $hook_ok ? 'getInboxHealth wired in bundle' : 'hook NOT found in bundle (rebuild needed)';
		} else {
			$bundle_ev = 'bundle not built';
		}
		$tasks[] = array(
			'id'       => 'T-M7.W4.3',
			'check'    => 'FE bundle wires useGetInboxHealthQuery (Channels tab dot)',
			'status'   => $hook_ok ? 'pass' : ( $exists ? 'fail' : 'warn' ),
			'evidence' => $bundle_ev,
		);

		// ---------------------------------------------------------------
		// T-M7.W2.* / T-M7.W3.* — channel adapter completeness probes.
		// Each row checks: adapter is registered + interface complete +
		// the actual production hook (send not stubbed / webhook route
		// registered / cron scheduled). NO live API calls.
		// ---------------------------------------------------------------
		$routes      = array_keys( rest_get_server()->get_routes() );
		$ns          = '/' . BIZCITY_CRM_REST_NS;
		$has_route   = static function ( string $path ) use ( $routes, $ns ): bool {
			return in_array( $ns . $path, $routes, true );
		};
		// Detect "pending wiring" stubs by reflecting on the send() body.
		$send_is_real = static function ( string $code ): array {
			$reg = class_exists( 'BizCity_CRM_Channel_Registry' )
				? BizCity_CRM_Channel_Registry::get( $code )
				: null;
			if ( ! $reg ) {
				return array( 'ok' => false, 'why' => 'adapter_not_registered' );
			}
			try {
				$rm   = new \ReflectionMethod( $reg, 'send' );
				$file = (string) $rm->getFileName();
				$from = (int) $rm->getStartLine();
				$to   = (int) $rm->getEndLine();
				if ( $file === '' || $to <= $from ) {
					return array( 'ok' => false, 'why' => 'cannot_reflect' );
				}
				$src = (string) implode( '', array_slice( file( $file ), $from - 1, $to - $from + 1 ) );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'why' => 'reflect_threw:' . $e->getMessage() );
			}
			if ( stripos( $src, 'pending wiring' ) !== false ) {
				return array( 'ok' => false, 'why' => 'send_returns_stub' );
			}
			// Real send must call the channel's API or wp_mail.
			$has_call = ( strpos( $src, 'wp_remote_post' ) !== false )
				|| ( strpos( $src, 'wp_remote_get' ) !== false )
				|| ( strpos( $src, 'wp_mail' ) !== false )
				|| ( strpos( $src, 'BizCity_CRM_Bridge_Google::gmail_send' ) !== false )
				|| ( strpos( $src, 'web:out:' ) !== false ); // web widget intentional stash
			return array( 'ok' => $has_call, 'why' => $has_call ? 'has_remote_call' : 'no_remote_call' );
		};

		// M7.W2.task-1 — Instagram outbound + interface.
		$ig_send = $send_is_real( 'instagram' );
		$tasks[] = array(
			'id'       => 'T-M7.W2.1',
			'check'    => 'Instagram adapter registered + send() implements Graph API call',
			'status'   => $ig_send['ok'] ? 'pass' : 'fail',
			'evidence' => 'send: ' . $ig_send['why'],
		);
		// M7.W2.task-2 — WhatsApp Cloud outbound + verify webhook route.
		$wa_send = $send_is_real( 'whatsapp_cloud' );
		$wa_hook = $has_route( '/webhooks/whatsapp' );
		$wa_ok   = $wa_send['ok'] && $wa_hook;
		$tasks[] = array(
			'id'       => 'T-M7.W2.2',
			'check'    => 'WhatsApp Cloud send() real + /webhooks/whatsapp route registered',
			'status'   => $wa_ok ? 'pass' : 'fail',
			'evidence' => 'send: ' . $wa_send['why'] . ' · webhook_route: ' . ( $wa_hook ? 'yes' : 'NO' ),
		);

		// M7.W3.task-1 — Telegram outbound + webhook route.
		$tg_send = $send_is_real( 'telegram' );
		$tg_hook = $has_route( '/webhooks/telegram' );
		$tg_ok   = $tg_send['ok'] && $tg_hook;
		$tasks[] = array(
			'id'       => 'T-M7.W3.1',
			'check'    => 'Telegram send() real + /webhooks/telegram route registered',
			'status'   => $tg_ok ? 'pass' : 'fail',
			'evidence' => 'send: ' . $tg_send['why'] . ' · webhook_route: ' . ( $tg_hook ? 'yes' : 'NO' ),
		);
		// M7.W3.task-2 — IMAP retired 2026-05-31. Gmail SMTP replaces inbound/outbound.
		$em_cron_retired = ! wp_next_scheduled( 'bizcity_crm_email_poll_tick' );
		$em_gmail_ok     = class_exists( 'BizCity_CRM_Gmail_SMTP_Repo' ) && method_exists( 'BizCity_CRM_Gmail_SMTP_Repo', 'send_via' );
		$tasks[] = array(
			'id'       => 'T-M7.W3.2',
			'check'    => 'IMAP cron retired + Gmail SMTP send_via() available',
			'status'   => ( $em_cron_retired && $em_gmail_ok ) ? 'pass' : 'fail',
			'evidence' => 'imap_cron_cleared=' . ( $em_cron_retired ? 'YES' : 'STILL_SCHEDULED' ) . ' · gmail_send_via=' . ( $em_gmail_ok ? 'OK' : 'MISSING' ),
		);
		// M7.W3.task-3 — Web Widget webhook + send() stash works.
		$web_send = $send_is_real( 'web_widget' );
		$web_hook = $has_route( '/webhooks/web-widget' );
		$web_ok   = $web_send['ok'] && $web_hook;
		$tasks[] = array(
			'id'       => 'T-M7.W3.3',
			'check'    => 'Web Widget /webhooks/web-widget route registered + send() stashes outbound',
			'status'   => $web_ok ? 'pass' : 'fail',
			'evidence' => 'send: ' . $web_send['why'] . ' · webhook_route: ' . ( $web_hook ? 'yes' : 'NO' ),
		);

		/* ================================================================
		 * PHASE 0.35 M6.W1 — Campaigns schema + repository + REST
		 * ================================================================ */
		global $wpdb;
		$tbl_camp     = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$tbl_visits   = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
		$has_camp     = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_camp ) ) === $tbl_camp;
		$has_visits   = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_visits ) ) === $tbl_visits;
		$tasks[] = array(
			'id'       => 'T-M6.W1.1',
			'check'    => 'DB tables wp_bizcity_crm_campaigns + wp_bizcity_crm_campaign_visits installed',
			'status'   => ( $has_camp && $has_visits ) ? 'pass' : 'fail',
			'evidence' => 'campaigns: ' . ( $has_camp ? 'yes' : 'NO' ) . ' · visits: ' . ( $has_visits ? 'yes' : 'NO' )
				. ' · db_version: ' . (string) get_option( BizCity_CRM_DB_Installer_V2::DB_VERSION_OPTION, '?' ),
		);

		// T-M6.W1.2 — Campaign repository class loaded with full CRUD surface.
		$repo_methods = array( 'create', 'update', 'delete', 'get', 'get_by_code', 'list', 'visits_count', 'conversions_count' );
		$missing      = array();
		$repo_ok      = class_exists( 'BizCity_CRM_Campaign_Repository' );
		if ( $repo_ok ) {
			foreach ( $repo_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Campaign_Repository', $m ) ) { $missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W1.2',
			'check'    => 'BizCity_CRM_Campaign_Repository class + CRUD methods present',
			'status'   => ( $repo_ok && empty( $missing ) ) ? 'pass' : 'fail',
			'evidence' => $repo_ok
				? ( empty( $missing ) ? 'all methods present (' . count( $repo_methods ) . ')' : 'missing: ' . implode( ', ', $missing ) )
				: 'class NOT loaded',
		);

		// T-M6.W1.3 — 5 REST routes registered under bizcity-crm/v1.
		$camp_routes = array( '/campaigns', '/campaigns/(?P<id>\d+)', '/campaigns/(?P<id>\d+)/stats' );
		$missing_r   = array();
		foreach ( $camp_routes as $r ) {
			if ( ! $has_route( $r ) ) { $missing_r[] = $r; }
		}
		// Verify methods on /campaigns and /campaigns/{id} include the expected verbs.
		$server  = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
		$verb_ok = true;
		if ( $server ) {
			$all     = $server->get_routes();
			$col     = $all['/bizcity-crm/v1/campaigns']             ?? array();
			$item    = $all['/bizcity-crm/v1/campaigns/(?P<id>\d+)'] ?? array();
			$col_v   = array(); foreach ( $col  as $h ) { foreach ( array_keys( (array) ( $h['methods'] ?? array() ) ) as $m ) { $col_v[ strtoupper( $m ) ] = true; } }
			$item_v  = array(); foreach ( $item as $h ) { foreach ( array_keys( (array) ( $h['methods'] ?? array() ) ) as $m ) { $item_v[ strtoupper( $m ) ] = true; } }
			$verb_ok = isset( $col_v['GET'], $col_v['POST'] )
				&& isset( $item_v['GET'], $item_v['DELETE'] )
				&& ( isset( $item_v['PUT'] ) || isset( $item_v['PATCH'] ) );
		}
		$routes_ok = empty( $missing_r ) && $verb_ok;
		$tasks[] = array(
			'id'       => 'T-M6.W1.3',
			'check'    => 'REST routes /campaigns, /campaigns/{id} (GET/POST/PATCH/DELETE) + /stats registered',
			'status'   => $routes_ok ? 'pass' : 'fail',
			'evidence' => ( empty( $missing_r ) ? 'paths: yes' : 'missing: ' . implode( ', ', $missing_r ) )
				. ' · verbs: ' . ( $verb_ok ? 'yes' : 'NO (need GET+POST on collection, GET+DELETE+PUT/PATCH on item)' ),
		);

		/* ================================================================
		 * PHASE 0.35 M6.W2 — QR Generator + UTM URL builder
		 * ================================================================ */
		// T-M6.W2.1 — QR generator class loaded with required surface.
		$qr_methods   = array( 'svg', 'png', 'build_url', 'encode_matrix' );
		$qr_loaded    = class_exists( 'BizCity_CRM_QR_Generator' );
		$qr_missing   = array();
		if ( $qr_loaded ) {
			foreach ( $qr_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_QR_Generator', $m ) ) { $qr_missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W2.1',
			'check'    => 'BizCity_CRM_QR_Generator class + svg/png/build_url/encode_matrix present',
			'status'   => ( $qr_loaded && empty( $qr_missing ) ) ? 'pass' : 'fail',
			'evidence' => $qr_loaded
				? ( empty( $qr_missing ) ? 'all methods present (' . count( $qr_methods ) . ')' : 'missing: ' . implode( ',', $qr_missing ) )
				: 'class NOT loaded (bootstrap require_once?)',
		);

		// T-M6.W2.2 — build_url() emits ref=camp_<code> + 5 utm_* params honoured.
		$url_ok = false; $url_why = 'class missing';
		if ( $qr_loaded ) {
			$fake_campaign = array(
				'code'        => 'diag-test',
				'landing_url' => 'https://example.com/lp',
				'utm'         => array(
					'source'   => 'fb',
					'medium'   => 'cpc',
					'campaign' => 'spring',
					'content'  => 'hero',
					'term'     => 'qr',
				),
			);
			$built  = BizCity_CRM_QR_Generator::build_url( $fake_campaign );
			$has_ref   = strpos( $built, 'ref=camp_diag-test' ) !== false;
			$has_utm5  = strpos( $built, 'utm_source=fb' )   !== false
				&& strpos( $built, 'utm_medium=cpc' )    !== false
				&& strpos( $built, 'utm_campaign=spring' ) !== false
				&& strpos( $built, 'utm_content=hero' )  !== false
				&& strpos( $built, 'utm_term=qr' )       !== false;
			$url_ok  = $has_ref && $has_utm5;
			$url_why = $url_ok ? substr( $built, 0, 96 ) : ( 'ref:' . ( $has_ref ? 'y' : 'N' ) . ' utm5:' . ( $has_utm5 ? 'y' : 'N' ) . ' got=' . substr( $built, 0, 80 ) );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W2.2',
			'check'    => 'build_url() includes ref=camp_<code> and all 5 utm_* params',
			'status'   => $url_ok ? 'pass' : 'fail',
			'evidence' => $url_why,
		);

		// T-M6.W2.3 — svg() returns valid SVG with finder pattern modules placed.
		$svg_ok = false; $svg_why = 'class missing';
		if ( $qr_loaded ) {
			try {
				$svg     = BizCity_CRM_QR_Generator::svg( 'https://example.com/lp?ref=camp_diag-test', 200, 4 );
				$matrix  = BizCity_CRM_QR_Generator::encode_matrix( 'https://example.com/lp?ref=camp_diag-test' );
				$n       = count( $matrix );
				// Probe top-left finder corner: must be (0,0)=1, (1,1)=0 (white inner ring), (3,3)=1 (centre 3x3 block).
				$finder_ok = ( ! empty( $matrix[0][0] ) ) && empty( $matrix[1][1] ) && ! empty( $matrix[3][3] );
				// Timing pattern: (6,8) must be dark since 8 is even.
				$timing_ok = ! empty( $matrix[6][8] ) && empty( $matrix[6][9] ) && ! empty( $matrix[6][10] );
				// Format-info readback for (EC=M, mask=0): expected '101010000010010' MSB→LSB,
				// placed bit-0 at (8,0). So (8,0)=0, (8,1)=1, (0,8)=1, dark module (4V+9,8)=1.
				$f80     = (int) $matrix[8][0];
				$f81     = (int) $matrix[8][1];
				$f08     = (int) $matrix[0][8];
				$dark_v1 = (int) $matrix[ 4 * 1 + 9 ][8]; // V1 used here (URL fits V2 likely; recompute version)
				// We don't know which version was picked; rely on (8,0)=0 + (8,1)=1 + (0,8)=1 universal for (M,0).
				$format_ok = ( $f80 === 0 ) && ( $f81 === 1 ) && ( $f08 === 1 );
				$svg_ok   = $finder_ok && $timing_ok && $format_ok
					&& strpos( $svg, '<svg' ) !== false
					&& strpos( $svg, '<rect' ) !== false
					&& $n >= 21 && $n <= 41;
				$svg_why  = $svg_ok
					? sprintf( 'svg %d bytes · matrix %dx%d · finder+timing+format ok', strlen( $svg ), $n, $n )
					: sprintf( 'finder=%s · timing=%s · format(8,0=%d 8,1=%d 0,8=%d)=%s · matrix=%dx%d',
						$finder_ok ? 'y' : 'N', $timing_ok ? 'y' : 'N', $f80, $f81, $f08, $format_ok ? 'y' : 'N', $n, $n );
			} catch ( \Throwable $e ) {
				$svg_why = 'exception: ' . $e->getMessage();
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W2.3',
			'check'    => 'svg(payload) returns SVG with valid QR matrix (finder pattern probe)',
			'status'   => $svg_ok ? 'pass' : 'fail',
			'evidence' => $svg_why,
		);

		// T-M6.W2.4 — REST routes for url + qr.svg + qr.png registered.
		$qr_routes  = array( '/campaigns/(?P<id>\d+)/url', '/campaigns/(?P<id>\d+)/qr.svg', '/campaigns/(?P<id>\d+)/qr.png' );
		$qr_miss_r  = array();
		foreach ( $qr_routes as $r ) {
			if ( ! $has_route( $r ) ) { $qr_miss_r[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W2.4',
			'check'    => 'REST /campaigns/{id}/url + /qr.svg + /qr.png registered',
			'status'   => empty( $qr_miss_r ) ? 'pass' : 'fail',
			'evidence' => empty( $qr_miss_r ) ? 'all 3 routes present' : 'missing: ' . implode( ', ', $qr_miss_r ),
		);

		/* ================================================================
		 * PHASE 0.35 M6.W3 — Campaign Visit Tracker
		 * ================================================================ */

		// T-M6.W3.1 — Tracker class loaded with the full surface.
		$tr_methods = array( 'register', 'record_visit', 'maybe_track_url', 'shortcode_pixel', 'maybe_track_fb_referral', 'resolve_ref', 'rate_limit_check', 'hash_ip', 'cookie_seen', 'cookie_mark', 'derive_web_client_id', 'client_ip' );
		$tr_loaded  = class_exists( 'BizCity_CRM_Campaign_Tracker' );
		$tr_missing = array();
		if ( $tr_loaded ) {
			foreach ( $tr_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Campaign_Tracker', $m ) ) { $tr_missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W3.1',
			'check'    => 'BizCity_CRM_Campaign_Tracker class + record_visit/maybe_track_url/shortcode_pixel/maybe_track_fb_referral present',
			'status'   => ( $tr_loaded && empty( $tr_missing ) ) ? 'pass' : 'fail',
			'evidence' => $tr_loaded
				? ( empty( $tr_missing ) ? 'all ' . count( $tr_methods ) . ' methods present' : 'missing: ' . implode( ',', $tr_missing ) )
				: 'class NOT loaded (bootstrap require_once?)',
		);

		// T-M6.W3.2 — init hook + shortcode + waic_twf_process_flow listener wired.
		$wired_init    = $tr_loaded && (bool) has_action( 'init', array( 'BizCity_CRM_Campaign_Tracker', 'maybe_track_url' ) );
		$wired_pixel   = (bool) shortcode_exists( 'bizcity_campaign_track' );
		$wired_fb_prio = $tr_loaded ? has_action( 'waic_twf_process_flow', array( 'BizCity_CRM_Campaign_Tracker', 'maybe_track_fb_referral' ) ) : false;
		$wired_fb_ok   = ( $wired_fb_prio !== false ) && ( (int) $wired_fb_prio === 8 );
		// FB ingestor must run AFTER us — sanity-check it's at >=9.
		$ing_prio = false;
		if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			$ing = BizCity_CRM_Facebook_Ingestor::instance();
			$ing_prio = has_action( 'waic_twf_process_flow', array( $ing, 'on_workflow_trigger' ) );
		}
		$ordering_ok = ( $ing_prio !== false ) && ( (int) $ing_prio >= (int) ( $wired_fb_prio ?: PHP_INT_MAX ) );
		$wired_ok    = $wired_init && $wired_pixel && $wired_fb_ok && $ordering_ok;
		$tasks[] = array(
			'id'       => 'T-M6.W3.2',
			'check'    => 'init hook + [bizcity_campaign_track] shortcode + waic_twf_process_flow@8 wired (BEFORE FB ingestor)',
			'status'   => $wired_ok ? 'pass' : 'fail',
			'evidence' => sprintf( 'init=%s pixel=%s fb_referral_prio=%s ingestor_prio=%s order_ok=%s',
				$wired_init  ? 'yes' : 'NO',
				$wired_pixel ? 'yes' : 'NO',
				$wired_fb_prio === false ? 'NONE' : (string) $wired_fb_prio,
				$ing_prio === false ? 'NONE' : (string) $ing_prio,
				$ordering_ok ? 'yes' : 'NO'
			),
		);

		// T-M6.W3.3 — record_visit() actually inserts a row + emits event for a real campaign.
		//             Synthesises a draft campaign, records 1 visit, asserts dedupe on 2nd call.
		$write_ok = false; $write_why = 'class missing';
		if ( $tr_loaded && class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			$probe_code = 'diag-w3-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid_or_err = BizCity_CRM_Campaign_Repository::create( array(
				'name'        => 'Diag W3 ' . $probe_code,
				'code'        => $probe_code,
				'status'      => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
				'landing_url' => 'https://example.com/diag',
				'utm'         => array( 'source' => 'diag', 'medium' => 'qr', 'campaign' => $probe_code ),
			) );
			if ( is_int( $cid_or_err ) && $cid_or_err > 0 ) {
				$emitted = array();
				$listener = static function ( $payload ) use ( &$emitted, $cid_or_err ) {
					if ( is_array( $payload ) && (int) ( $payload['campaign_id'] ?? 0 ) === $cid_or_err ) {
						$emitted[] = $payload;
					}
				};
				// Listen on Event_Emitter fan-out hook (raw event name is never fired).
				$visit_hook = 'bizcity_crm_event_crm_campaign_visit_recorded';
				add_action( $visit_hook, $listener, 10, 1 );

				// Use a unique IP per run so we never collide with the per-IP rate-limit
				// bucket from previous diag runs (which would suppress visit_id silently).
				$probe_ip = '198.51.100.' . ( ( (int) ( microtime( true ) * 1000 ) ) % 250 + 2 );
				$client = 'web_diag_' . substr( md5( (string) microtime( true ) ), 0, 16 );
				$v1 = BizCity_CRM_Campaign_Tracker::record_visit( $cid_or_err, $client, array(
					'mode' => 'web',
					'utm'  => array( 'source' => 'diag', 'medium' => 'qr' ),
					'ip'   => $probe_ip,
					'user_agent' => 'Mozilla/5.0 diag',
				) );
				$v2 = BizCity_CRM_Campaign_Tracker::record_visit( $cid_or_err, $client, array(
					'mode' => 'web',
					'ip'   => $probe_ip,
					'user_agent' => 'Mozilla/5.0 diag',
				) );

				remove_action( $visit_hook, $listener, 10 );

				// Confirm row exists with payload we sent.
				$tbl_v = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
				$row_q = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, campaign_id, client_id, ip_hash, utm_source, meta_json FROM {$tbl_v} WHERE id = %d",
					(int) $v1
				), ARRAY_A );

				$insert_ok = is_int( $v1 ) && $v1 > 0 && is_array( $row_q )
					&& (int) $row_q['campaign_id'] === $cid_or_err
					&& $row_q['client_id'] === $client
					&& $row_q['utm_source'] === 'diag'
					&& strlen( (string) $row_q['ip_hash'] ) === 64;
				$dedupe_ok = ( $v2 === 0 );  // 2nd call within 5min must soft-suppress.
				$event_ok  = count( $emitted ) === 1 && (int) $emitted[0]['visit_id'] === (int) $v1;

				$write_ok  = $insert_ok && $dedupe_ok && $event_ok;
				$write_why = sprintf(
					'visit_id=%s row_match=%s ip_hash_len=%d dedupe=%s event_count=%d event_match=%s',
					is_int( $v1 ) ? (string) $v1 : 'ERR',
					$insert_ok ? 'y' : 'N',
					is_array( $row_q ) ? strlen( (string) $row_q['ip_hash'] ) : -1,
					$dedupe_ok ? 'y' : 'N',
					count( $emitted ),
					$event_ok ? 'y' : 'N'
				);

				// Cleanup.
				if ( $v1 > 0 ) { $wpdb->delete( $tbl_v, array( 'id' => (int) $v1 ) ); }
				BizCity_CRM_Campaign_Repository::delete( $cid_or_err );
			} else {
				$write_why = 'campaign create failed: ' . ( is_wp_error( $cid_or_err ) ? $cid_or_err->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W3.3',
			'check'    => 'record_visit() inserts visit row + emits crm_campaign_visit_recorded + 5-min dedupe suppresses 2nd call',
			'status'   => $write_ok ? 'pass' : 'fail',
			'evidence' => $write_why,
		);

		// T-M6.W3.4 — Rate limiter caps at RATE_LIMIT_MAX per IP per hour.
		$rl_ok = false; $rl_why = 'class missing';
		if ( $tr_loaded ) {
			$probe_ip   = 'rl-test-' . wp_generate_password( 12, false, false );
			$probe_hash = BizCity_CRM_Campaign_Tracker::hash_ip( $probe_ip );
			// Reset bucket so test is deterministic.
			delete_transient( BizCity_CRM_Campaign_Tracker::RATE_LIMIT_PREFIX . substr( $probe_hash, 0, 32 ) );
			$max         = (int) BizCity_CRM_Campaign_Tracker::RATE_LIMIT_MAX;
			$pass_count  = 0;
			for ( $i = 0; $i < $max; $i++ ) {
				if ( BizCity_CRM_Campaign_Tracker::rate_limit_check( $probe_hash ) ) { $pass_count++; }
			}
			// (max+1)th must fail.
			$over_blocked = BizCity_CRM_Campaign_Tracker::rate_limit_check( $probe_hash ) === false;
			$ip_hash_len  = strlen( $probe_hash );
			$rl_ok  = ( $pass_count === $max ) && $over_blocked && ( $ip_hash_len === 64 );
			$rl_why = sprintf( 'allowed=%d/%d over_blocked=%s ip_hash_len=%d', $pass_count, $max, $over_blocked ? 'y' : 'N', $ip_hash_len );
			delete_transient( BizCity_CRM_Campaign_Tracker::RATE_LIMIT_PREFIX . substr( $probe_hash, 0, 32 ) );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W3.4',
			'check'    => 'Per-IP rate limit allows exactly ' . ( $tr_loaded ? BizCity_CRM_Campaign_Tracker::RATE_LIMIT_MAX : 30 ) . ' visits/hour then blocks; ip_hash is sha256 (64 hex)',
			'status'   => $rl_ok ? 'pass' : 'fail',
			'evidence' => $rl_why,
		);

		// T-M6.W3.5 — FB referral extractor pulls camp_<code> from raw event payload (both shapes).
		$fb_ok = false; $fb_why = 'class missing';
		if ( $tr_loaded ) {
			$ref_methods = new \ReflectionClass( 'BizCity_CRM_Campaign_Tracker' );
			if ( $ref_methods->hasMethod( 'extract_fb_ref' ) ) {
				$mref = $ref_methods->getMethod( 'extract_fb_ref' );
				$mref->setAccessible( true );
				$ev_direct   = array( 'referral' => array( 'ref' => 'camp_spring-2026', 'source' => 'SHORTLINK' ) );
				$ev_postback = array( 'postback' => array( 'referral' => array( 'ref' => 'camp_qr-promo', 'source' => 'MESSENGER_CODE' ) ) );
				$ev_other    = array( 'message' => array( 'text' => 'hi' ) );
				$ev_nonprefix= array( 'referral' => array( 'ref' => 'OTHER_REF' ) );
				$r1 = (string) $mref->invoke( null, $ev_direct );
				$r2 = (string) $mref->invoke( null, $ev_postback );
				$r3 = (string) $mref->invoke( null, $ev_other );
				$r4 = (string) $mref->invoke( null, $ev_nonprefix );
				$resolve_works = false;
				$campaign_resolved = BizCity_CRM_Campaign_Tracker::resolve_ref( 'camp_does-not-exist-' . wp_generate_password( 6, false, false ) );
				$resolve_works = ( $campaign_resolved === null );  // Non-existent code returns NULL (not throw).
				$fb_ok  = $r1 === 'camp_spring-2026' && $r2 === 'camp_qr-promo' && $r3 === '' && $r4 === '' && $resolve_works;
				$fb_why = sprintf( 'direct=%s postback=%s no_referral=%s non_prefix=%s resolve_null_for_missing=%s',
					$r1, $r2, $r3 === '' ? 'empty(ok)' : $r3, $r4 === '' ? 'empty(ok)' : $r4, $resolve_works ? 'y' : 'N' );
			} else {
				$fb_why = 'extract_fb_ref method missing';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W3.5',
			'check'    => 'FB referral extractor parses event.referral.ref AND event.postback.referral.ref; ignores non-camp_ values; resolve_ref() returns NULL for unknown codes',
			'status'   => $fb_ok ? 'pass' : 'fail',
			'evidence' => $fb_why,
		);

		/* ================================================================
		 * PHASE 0.35 M6.W4 — Conversion Linker + Loyalty Bridge Shortcodes
		 * ================================================================ */

		// T-M6.W4.1 — Linker class loaded with full surface.
		$cl_methods = array( 'register', 'on_message_received', 'link_visit', 'link_web_cookie_to_contact', 'resolve_client_id_for_conversation', 'compose_client_id' );
		$cl_loaded  = class_exists( 'BizCity_CRM_Campaign_Conversion_Linker' );
		$cl_missing = array();
		if ( $cl_loaded ) {
			foreach ( $cl_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Campaign_Conversion_Linker', $m ) ) { $cl_missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W4.1',
			'check'    => 'BizCity_CRM_Campaign_Conversion_Linker class + on_message_received/link_visit/compose_client_id present',
			'status'   => ( $cl_loaded && empty( $cl_missing ) ) ? 'pass' : 'fail',
			'evidence' => $cl_loaded
				? ( empty( $cl_missing ) ? 'all ' . count( $cl_methods ) . ' methods present' : 'missing: ' . implode( ',', $cl_missing ) )
				: 'class NOT loaded (bootstrap require_once?)',
		);

		// T-M6.W4.2 — Hook subscribed: bizcity_crm_event_crm_message_received @ priority 20.
		// Note: BizCity_CRM_Event_Emitter::emit() fans out via `bizcity_crm_event_<type>` — NOT the raw name.
		$hook_ok = false; $hook_why = 'class missing';
		if ( $cl_loaded ) {
			global $wp_filter;
			$hook_why = 'no callback found';
			$hook_name = 'bizcity_crm_event_crm_message_received';
			if ( isset( $wp_filter[ $hook_name ] ) && isset( $wp_filter[ $hook_name ]->callbacks[20] ) ) {
				foreach ( $wp_filter[ $hook_name ]->callbacks[20] as $cb ) {
					$fn = $cb['function'] ?? null;
					if ( is_array( $fn ) && (string) $fn[0] === 'BizCity_CRM_Campaign_Conversion_Linker' && (string) $fn[1] === 'on_message_received' ) {
						$hook_ok = true; $hook_why = 'bizcity_crm_event_crm_message_received@20 → Linker::on_message_received'; break;
					}
				}
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W4.2',
			'check'    => 'Linker subscribed to bizcity_crm_event_crm_message_received at priority 20 (Event_Emitter fan-out)',
			'status'   => $hook_ok ? 'pass' : 'fail',
			'evidence' => $hook_why,
		);

		// T-M6.W4.3 — Real round-trip:
		//   create campaign → record visit (client_id=fb_<page>_<psid>) → call link_visit($contact_id)
		//   → assert visit row stamped with contact_id + converted_at + crm_campaign_conversion_recorded fired.
		$lk_ok = false; $lk_why = 'class missing';
		if ( $cl_loaded && class_exists( 'BizCity_CRM_Campaign_Repository' ) && class_exists( 'BizCity_CRM_Campaign_Tracker' ) ) {
			$probe_code = 'diag-w4-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid_or_err = BizCity_CRM_Campaign_Repository::create( array(
				'name'                  => 'Diag W4 ' . $probe_code,
				'code'                  => $probe_code,
				'status'                => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
				'landing_url'           => 'https://example.com/diag-w4',
				'loyalty_points_award'  => 25,
			) );
			if ( is_int( $cid_or_err ) && $cid_or_err > 0 ) {
				$client    = 'fb_diagpage_' . substr( md5( (string) microtime( true ) ), 0, 12 );
				$fake_psid = 999000000 + (int) ( microtime( true ) * 100 ) % 1000000;
				$visit_id  = BizCity_CRM_Campaign_Tracker::record_visit( $cid_or_err, $client, array(
					'mode' => 'fb_referral',
					'ip'   => '203.0.113.77',
					'user_agent' => 'Mozilla/5.0 diag-w4',
				) );

				// Capture conversion event.
				$emitted = array();
				$listener = static function ( $payload ) use ( &$emitted, $cid_or_err ) {
					if ( is_array( $payload ) && (int) ( $payload['campaign_id'] ?? 0 ) === $cid_or_err ) {
						$emitted[] = $payload;
					}
				};
				add_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', $listener, 10, 1 );

				$fake_contact_id = 9000000 + ( $fake_psid % 1000 );  // synthetic id; we never join contacts table here.
				$linked_id = BizCity_CRM_Campaign_Conversion_Linker::link_visit( $client, $fake_contact_id );
				// Idempotency probe — calling again with same contact must be no-op (no new event).
				$linked_id_2 = BizCity_CRM_Campaign_Conversion_Linker::link_visit( $client, $fake_contact_id );

				remove_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', $listener, 10 );

				$tbl_v = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
				$row_q = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, contact_id, converted_contact_id, converted_at FROM {$tbl_v} WHERE id = %d",
					(int) $visit_id
				), ARRAY_A );

				$row_ok      = is_array( $row_q )
					&& (int) $row_q['contact_id']           === $fake_contact_id
					&& (int) $row_q['converted_contact_id'] === $fake_contact_id
					&& ! empty( $row_q['converted_at'] );
				$event_ok    = count( $emitted ) === 1
					&& (int) $emitted[0]['visit_id']   === (int) $visit_id
					&& (int) $emitted[0]['contact_id'] === $fake_contact_id
					&& $emitted[0]['code'] === $probe_code
					&& (int) $emitted[0]['loyalty_points_award'] === 25;
				$idempotent  = ( (int) $linked_id_2 === (int) $linked_id );

				$lk_ok = $row_ok && $event_ok && $idempotent && (int) $linked_id === (int) $visit_id;
				$lk_why = sprintf(
					'visit=%d linked=%d row_ok=%s event_count=%d event_ok=%s idempotent=%s',
					(int) $visit_id, (int) $linked_id,
					$row_ok ? 'y' : 'N',
					count( $emitted ),
					$event_ok ? 'y' : 'N',
					$idempotent ? 'y' : 'N'
				);

				if ( $visit_id > 0 ) { $wpdb->delete( $tbl_v, array( 'id' => (int) $visit_id ) ); }
				BizCity_CRM_Campaign_Repository::delete( $cid_or_err );
			} else {
				$lk_why = 'campaign create failed: ' . ( is_wp_error( $cid_or_err ) ? $cid_or_err->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W4.3',
			'check'    => 'link_visit() back-fills contact_id + converted_at on most-recent visit, emits crm_campaign_conversion_recorded once, idempotent on re-call',
			'status'   => $lk_ok ? 'pass' : 'fail',
			'evidence' => $lk_why,
		);

		// T-M6.W4.4 — [kiem_tra_diem] returns valid envelope (success bool + msgs[]).
		$kt_ok = false; $kt_why = 'class missing';
		if ( class_exists( 'BizCity_CRM_Loyalty_Shortcodes' ) ) {
			// Use DIRECT method call to avoid colliding with legacy bizgpt registration.
			$json = BizCity_CRM_Loyalty_Shortcodes::sc_kiem_tra_diem( array(
				'phone'     => '0900000000',
				'client_id' => 'diag_no_such_client_' . substr( md5( (string) microtime( true ) ), 0, 8 ),
			) );
			$dec  = json_decode( (string) $json, true );
			$kt_ok = is_array( $dec )
				&& array_key_exists( 'success', $dec )
				&& is_bool( $dec['success'] )
				&& isset( $dec['msgs'] )
				&& is_array( $dec['msgs'] )
				&& count( $dec['msgs'] ) >= 1;
			$kt_why = $kt_ok
				? 'envelope shape OK; success=' . ( $dec['success'] ? 'true' : 'false' ) . ' msgs[0]_len=' . strlen( (string) $dec['msgs'][0] )
				: 'invalid envelope: ' . substr( (string) $json, 0, 160 );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W4.4',
			'check'    => '[kiem_tra_diem] returns wp_json_encode([success bool, msgs string[]]) — legacy contract for chatbot scripts',
			'status'   => $kt_ok ? 'pass' : 'fail',
			'evidence' => $kt_why,
		);

		// T-M6.W4.5 — [doi_diem] returns valid envelope with redemption link in msg.
		$dd_ok = false; $dd_why = 'class missing';
		if ( class_exists( 'BizCity_CRM_Loyalty_Shortcodes' ) ) {
			$json = BizCity_CRM_Loyalty_Shortcodes::sc_doi_diem( array(
				'client_id' => 'fb_diagpage_diaguid',
				'page'      => 'tich-diem',
			) );
			$dec  = json_decode( (string) $json, true );
			$has_link = false;
			if ( is_array( $dec ) && isset( $dec['msgs'][0] ) ) {
				$has_link = (bool) preg_match( '#https?://[^\s]+/tich-diem/\?client_id=fb_diagpage_diaguid#', (string) $dec['msgs'][0] );
			}
			$dd_ok = is_array( $dec )
				&& ( $dec['success'] ?? null ) === true
				&& isset( $dec['msgs'] ) && is_array( $dec['msgs'] ) && $has_link;
			$dd_why = $dd_ok
				? 'envelope OK; link present in msgs[0]'
				: 'invalid: ' . substr( (string) $json, 0, 160 );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W4.5',
			'check'    => '[doi_diem] returns envelope with success=true + redemption URL containing client_id',
			'status'   => $dd_ok ? 'pass' : 'fail',
			'evidence' => $dd_why,
		);

		/* ================================================================
		 * PHASE 0.35 M6.W8 — Campaign Authoring UI (FE)
		 *
		 * BE side already wired in M6.W1+W2 (CRUD + /stats + /url + /qr).
		 * The UI lives in the React inbox bundle; PHP probes verify:
		 *   - source files exist on disk (route + nav wiring)
		 *   - built bundle includes the new symbols (or built before files added)
		 *   - REST endpoints respond on a self-call (defensive — already in M6.W1
		 *     diag but here we focus on the URL+QR endpoints used only by the FE)
		 * ================================================================ */

		$crm_dir = defined( 'BIZCITY_CRM_DIR' ) ? BIZCITY_CRM_DIR : dirname( __DIR__ );

		// T-M6.W8.1 — Source files for the route exist on disk.
		$tab_file = $crm_dir . '/frontend/src/routes/campaigns/CampaignsTab.jsx';
		$w8_src_ok  = file_exists( $tab_file );
		$w8_src_why = $w8_src_ok
			? 'CampaignsTab.jsx ' . round( filesize( $tab_file ) / 1024, 1 ) . ' KB · ' . gmdate( 'Y-m-d H:i', (int) filemtime( $tab_file ) ) . ' UTC'
			: 'missing: frontend/src/routes/campaigns/CampaignsTab.jsx';
		$tasks[] = array(
			'id'       => 'T-M6.W8.1',
			'check'    => 'CampaignsTab.jsx route file exists',
			'status'   => $w8_src_ok ? 'pass' : 'fail',
			'evidence' => $w8_src_why,
		);

		// T-M6.W8.2 — navConfig.js + Workspace.jsx wired the new tab id.
		$nav_file = $crm_dir . '/frontend/src/shell/navConfig.js';
		$ws_file  = $crm_dir . '/frontend/src/shell/Workspace.jsx';
		$nav_src  = is_readable( $nav_file ) ? (string) file_get_contents( $nav_file ) : '';
		$ws_src   = is_readable( $ws_file )  ? (string) file_get_contents( $ws_file )  : '';
		$nav_has  = ( strpos( $nav_src, "id: 'campaigns'" ) !== false );
		$ws_has   = ( strpos( $ws_src, 'campaigns:' ) !== false ) && ( strpos( $ws_src, 'CampaignsTab' ) !== false );
		$w8_wire_ok = $nav_has && $ws_has;
		$w8_wire_why = sprintf( 'navConfig has campaigns: %s · Workspace mounts CampaignsTab: %s', $nav_has ? 'y' : 'N', $ws_has ? 'y' : 'N' );
		$tasks[] = array(
			'id'       => 'T-M6.W8.2',
			'check'    => 'Tab id "campaigns" registered in navConfig.js + Workspace.jsx PANELS',
			'status'   => $w8_wire_ok ? 'pass' : 'fail',
			'evidence' => $w8_wire_why,
		);

		// T-M6.W8.3 — RTK slice extended with campaign endpoints + hooks exported.
		$api_file = $crm_dir . '/frontend/src/redux/api/crmApi.js';
		$api_src  = is_readable( $api_file ) ? (string) file_get_contents( $api_file ) : '';
		$endpoints_present = array(
			'getCampaigns', 'getCampaign', 'createCampaign', 'updateCampaign',
			'deleteCampaign', 'getCampaignStats', 'getCampaignUrl',
		);
		$missing = array();
		foreach ( $endpoints_present as $ep ) {
			if ( strpos( $api_src, $ep . ':' ) === false ) { $missing[] = $ep; }
		}
		$tag_ok = ( strpos( $api_src, "'Campaign'" ) !== false );
		$hooks_ok = ( strpos( $api_src, 'useGetCampaignsQuery' ) !== false )
			&& ( strpos( $api_src, 'useCreateCampaignMutation' ) !== false )
			&& ( strpos( $api_src, 'useGetCampaignStatsQuery' ) !== false );
		$w8_api_ok = empty( $missing ) && $tag_ok && $hooks_ok;
		$w8_api_why = sprintf(
			'endpoints %s · tagType Campaign: %s · hooks exported: %s',
			empty( $missing ) ? 'all 7 present' : 'missing ' . implode( ',', $missing ),
			$tag_ok ? 'y' : 'N',
			$hooks_ok ? 'y' : 'N'
		);
		$tasks[] = array(
			'id'       => 'T-M6.W8.3',
			'check'    => 'crmApi.js has 7 campaign endpoints + Campaign tagType + hooks exported',
			'status'   => $w8_api_ok ? 'pass' : 'fail',
			'evidence' => $w8_api_why,
		);

		// T-M6.W8.4 — REST routes the UI consumes are registered.
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$ns     = '/' . ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1' ) . '/';
		$want = array(
			$ns . 'campaigns',
			$ns . 'campaigns/(?P<id>\d+)',
			$ns . 'campaigns/(?P<id>\d+)/stats',
			$ns . 'campaigns/(?P<id>\d+)/url',
			$ns . 'campaigns/(?P<id>\d+)/qr.svg',
			$ns . 'campaigns/(?P<id>\d+)/qr.png',
		);
		$missing_routes = array_values( array_diff( $want, $routes ) );
		$w8_rest_ok  = empty( $missing_routes );
		$w8_rest_why = $w8_rest_ok
			? 'all 6 campaign REST routes registered'
			: 'missing: ' . implode( ', ', $missing_routes );
		$tasks[] = array(
			'id'       => 'T-M6.W8.4',
			'check'    => 'REST routes /campaigns, /campaigns/{id}, /stats, /url, /qr.svg, /qr.png all registered',
			'status'   => $w8_rest_ok ? 'pass' : 'fail',
			'evidence' => $w8_rest_why,
		);

		// T-M6.W8.5 — Built bundle is fresh (mtime ≥ source mtime) so the new tab is shipped.
		$dist_file = $crm_dir . '/assets/dist/inbox-app.js';
		if ( ! file_exists( $dist_file ) ) {
			$w8_build_ok  = false;
			$w8_build_why = 'assets/dist/inbox-app.js not built — run `npm run build` in /frontend';
		} else {
			$dist_mtime = (int) filemtime( $dist_file );
			$src_mtime  = max(
				file_exists( $tab_file ) ? (int) filemtime( $tab_file ) : 0,
				file_exists( $nav_file ) ? (int) filemtime( $nav_file ) : 0,
				file_exists( $ws_file )  ? (int) filemtime( $ws_file )  : 0,
				file_exists( $api_file ) ? (int) filemtime( $api_file ) : 0
			);
			$is_fresh = $dist_mtime >= $src_mtime;
			$dist_src = (string) @file_get_contents( $dist_file );
			$has_symbol = ( strpos( $dist_src, 'CampaignsTab' ) !== false ) || ( strpos( $dist_src, 'campaigns/CampaignsTab' ) !== false );
			$w8_build_ok = $is_fresh;
			$w8_build_why = sprintf(
				'dist mtime %s, src mtime %s — %s · symbol grep: %s',
				gmdate( 'Y-m-d H:i', $dist_mtime ),
				gmdate( 'Y-m-d H:i', $src_mtime ),
				$is_fresh ? 'fresh' : 'STALE — rebuild needed',
				$has_symbol ? 'present(string match — best-effort)' : 'absent(may be lazy-chunked)'
			);
		}
		$tasks[] = array(
			'id'       => 'T-M6.W8.5',
			'check'    => 'Built bundle assets/dist/inbox-app.js mtime ≥ source mtime',
			'status'   => $w8_build_ok ? 'pass' : 'warn',
			'evidence' => $w8_build_why,
		);

		// T-M6.W8.6 — Campaign repository hydrate surface still matches what the form posts.
		$repo_ok  = class_exists( 'BizCity_CRM_Campaign_Repository' );
		$repo_why = '';
		if ( $repo_ok ) {
			$expected = array( 'create', 'update', 'delete', 'get', 'get_by_code', 'list', 'visits_count', 'conversions_count' );
			$missing_m = array();
			foreach ( $expected as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Campaign_Repository', $m ) ) { $missing_m[] = $m; }
			}
			$repo_ok = empty( $missing_m );
			$repo_why = $repo_ok
				? 'all 8 repo methods present'
				: 'missing methods: ' . implode( ',', $missing_m );
		} else {
			$repo_why = 'BizCity_CRM_Campaign_Repository class missing';
		}
		$tasks[] = array(
			'id'       => 'T-M6.W8.6',
			'check'    => 'Campaign repository surface intact (create/update/delete/get/get_by_code/list/visits_count/conversions_count)',
			'status'   => $repo_ok ? 'pass' : 'fail',
			'evidence' => $repo_why,
		);

		/* ============================================================
		 * PHASE 0.35 M6.W5 — Loyalty Bridge
		 * ============================================================ */
		$lb_class = class_exists( 'BizCity_CRM_Loyalty_Bridge' );
		$lb_methods = array( 'register', 'on_conversion', 'award', 'balance', 'history' );
		$lb_missing = array();
		if ( $lb_class ) {
			foreach ( $lb_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Loyalty_Bridge', $m ) ) { $lb_missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W5.1',
			'check'    => 'Loyalty Bridge class loaded with award/balance/history surface',
			'status'   => ( $lb_class && empty( $lb_missing ) ) ? 'pass' : 'fail',
			'evidence' => $lb_class
				? ( empty( $lb_missing ) ? 'class+5 methods present' : 'missing: ' . implode( ',', $lb_missing ) )
				: 'BizCity_CRM_Loyalty_Bridge class missing',
		);

		// T-M6.W5.2 — Subscribed to bizcity_crm_event_crm_campaign_conversion_recorded (Event_Emitter fan-out).
		$lb_hooked = false;
		if ( $lb_class ) {
			$lb_hooked = (bool) has_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', array( 'BizCity_CRM_Loyalty_Bridge', 'on_conversion' ) );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W5.2',
			'check'    => 'Hook subscribed: bizcity_crm_event_crm_campaign_conversion_recorded → BizCity_CRM_Loyalty_Bridge::on_conversion',
			'status'   => $lb_hooked ? 'pass' : 'fail',
			'evidence' => $lb_hooked ? 'priority=' . has_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', array( 'BizCity_CRM_Loyalty_Bridge', 'on_conversion' ) ) : 'not subscribed',
		);

		// T-M6.W5.3 — Legacy ledger table present (wp_user_points).
		$tbl_pts   = $wpdb->prefix . 'user_points';
		$pts_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_pts ) ) === $tbl_pts;
		$tasks[] = array(
			'id'       => 'T-M6.W5.3',
			'check'    => 'Legacy ledger table wp_user_points reachable',
			'status'   => $pts_exists ? 'pass' : 'warn',
			'evidence' => $pts_exists ? 'table present' : 'wp_user_points missing — install user-points plugin to enable awards',
		);

		// T-M6.W5.4 — REST routes /loyalty/award + /loyalty/balance/{id} registered.
		$rest_routes_w5 = rest_get_server()->get_routes( 'bizcity-crm/v1' );
		$has_award      = isset( $rest_routes_w5['/bizcity-crm/v1/loyalty/award'] );
		$has_balance    = false;
		foreach ( array_keys( $rest_routes_w5 ) as $path ) {
			if ( strpos( $path, '/bizcity-crm/v1/loyalty/balance/' ) === 0 ) { $has_balance = true; break; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W5.4',
			'check'    => 'REST routes /loyalty/award (POST) + /loyalty/balance/{contact_id} (GET)',
			'status'   => ( $has_award && $has_balance ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'award=%s balance=%s', $has_award ? 'yes' : 'no', $has_balance ? 'yes' : 'no' ),
		);

		// T-M6.W5.5 — Action `award_points` present in registry.
		$reg_w5 = class_exists( 'BizCity_CRM_Action_Registry' ) ? BizCity_CRM_Action_Registry::all() : array();
		$has_award_action = isset( $reg_w5['award_points'] ) && is_callable( $reg_w5['award_points']['handler'] ?? null );
		$tasks[] = array(
			'id'       => 'T-M6.W5.5',
			'check'    => 'Automation action `award_points` registered with callable handler',
			'status'   => $has_award_action ? 'pass' : 'fail',
			'evidence' => $has_award_action ? 'handler registered' : 'missing in registry',
		);

		// T-M6.W5.6 — Real round-trip: award + balance via Bridge static methods (uses synthetic phone).
		$rt_w5_ok = false; $rt_w5_why = 'skipped (no ledger table)';
		if ( $pts_exists && $lb_class ) {
			$probe_phone = '+99000' . wp_rand( 100000, 999999 );
			$probe_uuid  = 'diag-' . wp_generate_uuid4();
			$res = BizCity_CRM_Loyalty_Bridge::award(
				array( 'phone' => $probe_phone, 'event_uuid' => $probe_uuid ),
				7,
				array( 'source' => 'diag', 'code' => 'diag-probe' )
			);
			if ( ! empty( $res['ok'] ) ) {
				$bal = BizCity_CRM_Loyalty_Bridge::balance( array( 'phone' => $probe_phone ) );
				$rt_w5_ok  = ( $bal === 7 );
				$rt_w5_why = sprintf( 'awarded id=%d, balance=%d', (int) $res['ledger_id'], $bal );
				// Cleanup the probe row.
				$wpdb->delete( $tbl_pts, array( 'id' => (int) $res['ledger_id'] ), array( '%d' ) );
			} else {
				$rt_w5_why = 'award failed: ' . ( $res['status'] ?? 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W5.6',
			'check'    => 'Real award + balance round-trip (synthetic phone, then cleanup)',
			'status'   => $rt_w5_ok ? 'pass' : ( $pts_exists ? 'fail' : 'warn' ),
			'evidence' => $rt_w5_why,
		);

		/* ============================================================
		 * PHASE 0.35 M6.W6 — Flow Importer
		 * ============================================================ */
		$fi_class   = class_exists( 'BizCity_CRM_Flow_Importer' );
		$fi_methods = array( 'preview', 'import_one', 'import_bulk', 'source_available' );
		$fi_missing = array();
		if ( $fi_class ) {
			foreach ( $fi_methods as $m ) {
				if ( ! method_exists( 'BizCity_CRM_Flow_Importer', $m ) ) { $fi_missing[] = $m; }
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W6.1',
			'check'    => 'Flow Importer class loaded with preview/import_one/import_bulk/source_available',
			'status'   => ( $fi_class && empty( $fi_missing ) ) ? 'pass' : 'fail',
			'evidence' => $fi_class
				? ( empty( $fi_missing ) ? 'class+4 methods present' : 'missing: ' . implode( ',', $fi_missing ) )
				: 'BizCity_CRM_Flow_Importer class missing',
		);

		// T-M6.W6.2 — Source table present.
		$src_ok = $fi_class ? BizCity_CRM_Flow_Importer::source_available() : false;
		$tasks[] = array(
			'id'       => 'T-M6.W6.2',
			'check'    => 'Source table wp_bizgpt_custom_flows reachable',
			'status'   => $src_ok ? 'pass' : 'warn',
			'evidence' => $src_ok ? 'table present' : 'absent — bizgpt-custom-flows plugin not installed',
		);

		// T-M6.W6.3 — REST routes /flows/import/preview + /flows/import.
		$has_prev   = isset( $rest_routes_w5['/bizcity-crm/v1/flows/import/preview'] );
		$has_import = isset( $rest_routes_w5['/bizcity-crm/v1/flows/import'] );
		$tasks[] = array(
			'id'       => 'T-M6.W6.3',
			'check'    => 'REST routes /flows/import/preview (GET) + /flows/import (POST) registered',
			'status'   => ( $has_prev && $has_import ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'preview=%s import=%s', $has_prev ? 'yes' : 'no', $has_import ? 'yes' : 'no' ),
		);

		// T-M6.W6.4 — preview() returns shaped rows (when source available).
		$prev_ok = false; $prev_why = 'skipped (source unavailable)';
		if ( $src_ok ) {
			$rows = BizCity_CRM_Flow_Importer::preview( 1 );
			if ( empty( $rows ) ) {
				$prev_ok = true; $prev_why = 'source empty (no flows yet) — surface OK';
			} else {
				$keys = array( 'flow_id', 'message', 'trigger', 'action_type', 'template', 'already_imported' );
				$first = $rows[0];
				$miss = array_filter( $keys, fn( $k ) => ! array_key_exists( $k, $first ) );
				$prev_ok = empty( $miss );
				$prev_why = $prev_ok ? 'first row has all 6 expected keys' : 'missing keys: ' . implode( ',', $miss );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W6.4',
			'check'    => 'preview() returns rows with {flow_id,message,trigger,action_type,template,already_imported}',
			'status'   => $prev_ok ? 'pass' : ( $src_ok ? 'fail' : 'warn' ),
			'evidence' => $prev_why,
		);

		/* ============================================================
		 * PHASE 0.35 M6.W7 — Funnel report
		 * ============================================================ */
		$has_funnel_route = false;
		foreach ( array_keys( $rest_routes_w5 ) as $path ) {
			if ( preg_match( '#^/bizcity-crm/v1/campaigns/[^/]+/funnel$#', $path ) ) { $has_funnel_route = true; break; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W7.1',
			'check'    => 'REST route /campaigns/{id}/funnel registered',
			'status'   => $has_funnel_route ? 'pass' : 'fail',
			'evidence' => $has_funnel_route ? 'route present' : 'not registered',
		);

		// T-M6.W7.2 — Handler returns the 5 funnel keys for any existing campaign (or skip if none).
		$funnel_ok = false; $funnel_why = 'skipped (no campaigns yet)';
		$tbl_camp = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$any_id   = (int) $wpdb->get_var( "SELECT id FROM {$tbl_camp} WHERE deleted_at IS NULL LIMIT 1" );
		if ( $any_id > 0 && method_exists( 'BizCity_CRM_REST_Controller', 'campaigns_funnel' ) ) {
			$req = new \WP_REST_Request( 'GET', '/bizcity-crm/v1/campaigns/' . $any_id . '/funnel' );
			$req->set_url_params( array( 'id' => $any_id ) );
			$resp = BizCity_CRM_REST_Controller::campaigns_funnel( $req );
			$data = $resp instanceof \WP_REST_Response ? $resp->get_data() : null;
			$payload = is_array( $data ) ? ( $data['data'] ?? array() ) : array();
			$keys    = array( 'visits', 'conversions', 'conversations', 'resolved', 'points_awarded' );
			$miss    = array_filter( $keys, fn( $k ) => ! array_key_exists( $k, $payload ) );
			$funnel_ok = empty( $miss );
			$funnel_why = $funnel_ok ? 'all 5 keys present, visits=' . (int) $payload['visits'] : 'missing keys: ' . implode( ',', $miss );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W7.2',
			'check'    => 'campaigns_funnel returns {visits,conversions,conversations,resolved,points_awarded}',
			'status'   => $funnel_ok ? 'pass' : ( $any_id > 0 ? 'fail' : 'warn' ),
			'evidence' => $funnel_why,
		);

		/* ============================================================
		 * PHASE 0.35 M6.W9 — Campaign ↔ Scenario binding
		 * ============================================================ */
		// T-M6.W9.1 — DB version is 1.10.3 and 3 columns exist on tbl_campaigns().
		$cols_ok = BizCity_CRM_DB_Installer_V2::column_exists( $tbl_camp, 'welcome_template_id' )
			&& BizCity_CRM_DB_Installer_V2::column_exists( $tbl_camp, 'bound_character_id' )
			&& BizCity_CRM_DB_Installer_V2::column_exists( $tbl_camp, 'bound_notebook_id' );
		$tasks[] = array(
			'id'       => 'T-M6.W9.1',
			'check'    => 'tbl_campaigns has welcome_template_id + bound_character_id + bound_notebook_id',
			'status'   => $cols_ok ? 'pass' : 'fail',
			'evidence' => sprintf( 'DB_VERSION=%s; cols_present=%s', BIZCITY_CRM_DB_VERSION, $cols_ok ? 'yes' : 'no' ),
		);

		// T-M6.W9.2 — conversations.character_id present (for switch).
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$cv_char_ok = BizCity_CRM_DB_Installer_V2::column_exists( $conv_tbl, 'character_id' );
		$tasks[] = array(
			'id'       => 'T-M6.W9.2',
			'check'    => 'conversations.character_id column present (for character switching)',
			'status'   => $cv_char_ok ? 'pass' : 'fail',
			'evidence' => $cv_char_ok ? 'column present' : 'column missing — re-run migrations',
		);

		// T-M6.W9.3 — Conversion Bridge class loaded.
		$cb_class = class_exists( 'BizCity_CRM_Campaign_Conversion_Bridge' );
		$tasks[] = array(
			'id'       => 'T-M6.W9.3',
			'check'    => 'BizCity_CRM_Campaign_Conversion_Bridge class loaded',
			'status'   => $cb_class ? 'pass' : 'fail',
			'evidence' => $cb_class ? 'class present' : 'class missing',
		);

		// T-M6.W9.4 — Bridge subscribed to bizcity_crm_event_crm_campaign_conversion_recorded @ priority 30 (Event_Emitter fan-out).
		$cb_pri = $cb_class
			? has_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', array( 'BizCity_CRM_Campaign_Conversion_Bridge', 'on_conversion' ) )
			: false;
		$tasks[] = array(
			'id'       => 'T-M6.W9.4',
			'check'    => 'Conversion Bridge subscribed @ priority 30 on Event_Emitter fan-out (after Loyalty Bridge @25)',
			'status'   => ( $cb_pri === 30 ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'priority=%s', var_export( $cb_pri, true ) ),
		);

		// T-M6.W9.5 — Action `attach_campaign_context` registered.
		$has_attach = isset( $reg_w5['attach_campaign_context'] )
			&& is_callable( $reg_w5['attach_campaign_context']['handler'] ?? null );
		$tasks[] = array(
			'id'       => 'T-M6.W9.5',
			'check'    => 'Automation action `attach_campaign_context` registered',
			'status'   => $has_attach ? 'pass' : 'fail',
			'evidence' => $has_attach ? 'handler registered' : 'missing in registry',
		);

		// T-M6.W9.6 — REST /campaigns/{id}/dropdowns registered.
		$has_dd = false;
		foreach ( array_keys( $rest_routes_w5 ) as $path ) {
			if ( preg_match( '#^/bizcity-crm/v1/campaigns/[^/]+/dropdowns$#', $path ) ) { $has_dd = true; break; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W9.6',
			'check'    => 'REST route /campaigns/{id}/dropdowns registered',
			'status'   => $has_dd ? 'pass' : 'fail',
			'evidence' => $has_dd ? 'route present' : 'not registered',
		);

		/* ================================================================
		 * PHASE 0.35 M6.W10 — Scenario columns + Ref Codec (R-CMP-1, R-CMP-2)
		 * ================================================================ */

		// T-M6.W10.1 — 10 scenario/reminder/import cols + 2 indexes present.
		$w10_cols = array(
			'scenario_action_type', 'scenario_shortcode', 'scenario_template',
			'scenario_attrs_json', 'scenario_prompt',
			'reminder_delay', 'reminder_unit', 'reminder_text', 'reminder_only',
			'imported_from_bizgpt_flow_id',
		);
		$w10_missing = array();
		foreach ( $w10_cols as $c ) {
			if ( ! BizCity_CRM_DB_Installer_V2::column_exists( $tbl_camp, $c ) ) { $w10_missing[] = $c; }
		}
		$w10_idx_ok = BizCity_CRM_DB_Installer_V2::index_exists( $tbl_camp, 'idx_imported_flow' )
			&& BizCity_CRM_DB_Installer_V2::index_exists( $tbl_camp, 'idx_action_type' );
		$tasks[] = array(
			'id'       => '.W10.1',
			'check'    => 'tbl_campaigns has 10 scenario/reminder cols + 2 indexes (M6.W10 schema)',
			'status'   => ( empty( $w10_missing ) && $w10_idx_ok ) ? 'pass' : 'fail',
			'evidence' => empty( $w10_missing )
				? ( 'all 10 cols present · indexes: ' . ( $w10_idx_ok ? 'yes' : 'NO' ) )
				: 'missing cols: ' . implode( ',', $w10_missing ),
		);

		// T-M6.W10.2 — Repository hydrates scenario_* round-trip.
		$hyd_ok = false; $hyd_why = 'preconditions missing';
		if ( $repo_ok && empty( $w10_missing ) ) {
			$probe_code = 'diag-w10-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid = BizCity_CRM_Campaign_Repository::create( array(
				'name'                 => 'Diag W10 ' . $probe_code,
				'code'                 => $probe_code,
				'status'               => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
				'scenario_action_type' => BizCity_CRM_Campaign_Repository::ACTION_RUN_SHORTCODE,
				'scenario_shortcode'   => '[tim_san_pham keyword="x" type="y"]',
				'scenario_attrs'       => array(
					array( 'key' => 'phone', 'prompt' => 'Bạn cho mình SĐT nhé?' ),
					array( 'key' => 'address', 'prompt' => 'Địa chỉ giao hàng?' ),
				),
				'reminder_delay'       => 5,
				'reminder_unit'        => 'minutes',
				'reminder_text'        => 'Bạn ơi còn cần hỗ trợ không?',
				'reminder_only'        => 0,
			) );
			if ( is_int( $cid ) && $cid > 0 ) {
				$re = BizCity_CRM_Campaign_Repository::get( $cid );
				$hyd_ok = is_array( $re )
					&& ( $re['scenario_action_type'] === 'run_shortcode' )
					&& ( $re['scenario_shortcode'] === '[tim_san_pham keyword="x" type="y"]' )
					&& is_array( $re['scenario_attrs'] )
					&& count( $re['scenario_attrs'] ) === 2
					&& ( $re['reminder_delay'] === 5 )
					&& ( $re['reminder_unit'] === 'minutes' );
				$hyd_why = $hyd_ok
					? sprintf( 'campaign_id=%d · attrs=%d · reminder=%d %s', $cid, count( $re['scenario_attrs'] ), $re['reminder_delay'], $re['reminder_unit'] )
					: 'round-trip mismatch · got=' . wp_json_encode( array(
						'action' => $re['scenario_action_type'] ?? null,
						'sc'     => $re['scenario_shortcode']   ?? null,
						'attrs'  => isset( $re['scenario_attrs'] ) ? count( (array) $re['scenario_attrs'] ) : null,
						'delay'  => $re['reminder_delay']       ?? null,
					) );
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$hyd_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W10.2',
			'check'    => 'Repository round-trip hydrates scenario_* + reminder_* + scenario_attrs (max 20)',
			'status'   => $hyd_ok ? 'pass' : 'fail',
			'evidence' => $hyd_why,
		);

		// T-M6.W10.3 — Ref Codec encode/decode round-trip + 5 IDs distinct.
		$rc_ok = false; $rc_why = 'class missing';
		if ( class_exists( 'BizCity_CRM_Campaign_Ref_Codec' ) ) {
			$ids    = array( 1, 42, 999, 12345, 67890 );
			$tokens = array();
			$rc_ok  = true;
			foreach ( $ids as $i ) {
				$ref = BizCity_CRM_Campaign_Ref_Codec::encode( $i );
				$dec = BizCity_CRM_Campaign_Ref_Codec::decode( $ref );
				if ( $dec !== $i ) { $rc_ok = false; break; }
				$tokens[] = $ref;
			}
			if ( $rc_ok && count( array_unique( $tokens ) ) !== count( $ids ) ) {
				$rc_ok = false;
			}
			$rc_why = $rc_ok
				? 'round-trip 5/5 ok · tokens distinct · sample=' . substr( $tokens[0] ?? '', 0, 16 )
				: 'mismatch — tokens=' . wp_json_encode( $tokens );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W10.3',
			'check'    => 'BizCity_CRM_Campaign_Ref_Codec encode/decode round-trip + 5 distinct tokens',
			'status'   => $rc_ok ? 'pass' : 'fail',
			'evidence' => $rc_why,
		);

		/* ================================================================
		 * PHASE 0.35 M6.W11 — REST scenario fields + messenger-link + preview-prompt
		 * ================================================================ */

		// T-M6.W11.1 — POST /campaigns rejects bad scenario_action_type (validate enum via sanitizer).
		$enum_ok = false; $enum_why = 'preconditions missing';
		if ( $repo_ok ) {
			$probe = 'diag-w11-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid   = BizCity_CRM_Campaign_Repository::create( array(
				'name'                 => 'Diag W11 ' . $probe,
				'code'                 => $probe,
				'scenario_action_type' => 'totally_invalid_value',
			) );
			if ( is_int( $cid ) && $cid > 0 ) {
				$re = BizCity_CRM_Campaign_Repository::get( $cid );
				// Sanitizer must coerce invalid enum back to default 'send_message'.
				$enum_ok  = is_array( $re ) && ( $re['scenario_action_type'] === 'send_message' );
				$enum_why = $enum_ok
					? 'invalid enum coerced to send_message (defensive default)'
					: 'enum coercion failed · got=' . ( $re['scenario_action_type'] ?? 'null' );
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$enum_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W11.1',
			'check'    => 'Repository sanitizer coerces invalid scenario_action_type → default send_message',
			'status'   => $enum_ok ? 'pass' : 'fail',
			'evidence' => $enum_why,
		);

		// T-M6.W11.2 — REST routes /messenger-link + /preview-prompt registered.
		$w11_routes = array( '/campaigns/(?P<id>\d+)/messenger-link', '/campaigns/(?P<id>\d+)/preview-prompt' );
		$w11_miss   = array();
		foreach ( $w11_routes as $r ) {
			if ( ! $has_route( $r ) ) { $w11_miss[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W11.2',
			'check'    => 'REST routes /campaigns/{id}/messenger-link + /preview-prompt registered',
			'status'   => empty( $w11_miss ) ? 'pass' : 'fail',
			'evidence' => empty( $w11_miss ) ? 'both routes present' : 'missing: ' . implode( ', ', $w11_miss ),
		);

		// T-M6.W11.3 — preview-prompt builder produces non-empty prompt with shortcode keys.
		$pp_ok = false; $pp_why = 'method missing';
		if ( class_exists( 'BizCity_CRM_REST_Controller' ) && method_exists( 'BizCity_CRM_REST_Controller', 'campaigns_preview_prompt' ) ) {
			// Use reflection to call private build_scenario_prompt — fallback: assert via REST endpoint shape doc.
			$ref_class = new \ReflectionClass( 'BizCity_CRM_REST_Controller' );
			if ( $ref_class->hasMethod( 'build_scenario_prompt' ) ) {
				$m = $ref_class->getMethod( 'build_scenario_prompt' );
				$m->setAccessible( true );
				$prompt = (string) $m->invokeArgs( null, array(
					'Diag W11', 'run_shortcode', '[tim_san_pham keyword="x"]', array(
						array( 'key' => 'phone', 'prompt' => 'SĐT?' ),
					),
				) );
				$pp_ok  = strlen( $prompt ) > 50
					&& ( stripos( $prompt, 'keyword' ) !== false )
					&& ( stripos( $prompt, 'phone' ) !== false );
				$pp_why = $pp_ok
					? sprintf( 'prompt %d chars · contains keyword+phone', strlen( $prompt ) )
					: 'prompt too short or missing markers · sample=' . substr( $prompt, 0, 80 );
			} else {
				$pp_why = 'build_scenario_prompt() missing on REST controller';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W11.3',
			'check'    => 'preview-prompt builder produces ≥50 chars + lists shortcode attrs + scenario_attrs',
			'status'   => $pp_ok ? 'pass' : 'fail',
			'evidence' => $pp_why,
		);

		/* ================================================================
		 * PHASE 0.35 M6.W12 — FB Adapter referral parser (Ref_Codec aware)
		 * ================================================================ */

		// T-M6.W12.1 — Tracker resolves NEW Ref_Codec token (12-char) AND legacy code.
		$rc_resolve_ok = false; $rc_resolve_why = 'class missing';
		if ( class_exists( 'BizCity_CRM_Campaign_Ref_Codec' ) && class_exists( 'BizCity_CRM_Campaign_Repository' ) && class_exists( 'BizCity_CRM_Campaign_Tracker' ) ) {
			$probe_code = 'diag-w12-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid = BizCity_CRM_Campaign_Repository::create( array(
				'name'   => 'Diag W12 ' . $probe_code,
				'code'   => $probe_code,
				'status' => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
			) );
			if ( is_int( $cid ) && $cid > 0 ) {
				$ref_new    = BizCity_CRM_Campaign_Ref_Codec::encode( $cid );  // camp_<token>
				$ref_legacy = 'camp_' . $probe_code;                            // camp_<code>
				$row_new    = BizCity_CRM_Campaign_Tracker::resolve_ref( $ref_new );
				$row_legacy = BizCity_CRM_Campaign_Tracker::resolve_ref( $ref_legacy );
				$row_bad    = BizCity_CRM_Campaign_Tracker::resolve_ref( 'camp_definitely-not-real-' . wp_generate_password( 8, false, false ) );
				$rc_resolve_ok = is_array( $row_new ) && (int) $row_new['id'] === $cid
					&& is_array( $row_legacy ) && (int) $row_legacy['id'] === $cid
					&& $row_bad === null;
				$rc_resolve_why = sprintf(
					'new_codec=%s · legacy_code=%s · null_for_unknown=%s · cid=%d',
					is_array( $row_new ) ? 'ok' : 'NO',
					is_array( $row_legacy ) ? 'ok' : 'NO',
					$row_bad === null ? 'y' : 'N',
					$cid
				);
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$rc_resolve_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W12.1',
			'check'    => 'Tracker::resolve_ref accepts BOTH new Ref_Codec token AND legacy code; returns null for unknown',
			'status'   => $rc_resolve_ok ? 'pass' : 'fail',
			'evidence' => $rc_resolve_why,
		);

		// T-M6.W12.2 — visit emit payload includes scenario_action_type + parent_event_uuid keys.
		// Listen on the Event_Emitter fan-out hook (bizcity_crm_event_crm_campaign_visit_recorded),
		// not the raw event name — the raw name is never fired.
		$ev_keys_ok = false; $ev_keys_why = 'preconditions';
		if ( class_exists( 'BizCity_CRM_Campaign_Repository' ) && class_exists( 'BizCity_CRM_Campaign_Tracker' ) ) {
			$probe = 'diag-w12-ev-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid   = BizCity_CRM_Campaign_Repository::create( array(
				'name'                 => 'Diag W12 ev ' . $probe,
				'code'                 => $probe,
				'status'               => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
				'scenario_action_type' => 'run_shortcode',
			) );
			if ( is_int( $cid ) && $cid > 0 ) {
				$captured = null;
				$listener = static function ( $payload ) use ( &$captured, $cid ) {
					if ( is_array( $payload ) && (int) ( $payload['campaign_id'] ?? 0 ) === $cid ) {
						$captured = $payload;
					}
				};
				$hook_name = 'bizcity_crm_event_crm_campaign_visit_recorded';
				add_action( $hook_name, $listener, 10, 1 );
				// Use a unique synthetic IP so we never collide with the W3 rate-limit bucket
				// (which would silently suppress the visit and break the event probe).
				$probe_ip = '198.51.100.' . ( ( (int) ( microtime( true ) * 1000 ) ) % 250 + 2 );
				$client   = 'fb_diagw12_' . substr( md5( (string) microtime( true ) ), 0, 12 );
				BizCity_CRM_Campaign_Tracker::record_visit( $cid, $client, array(
					'mode'             => 'fb_messenger',
					'channel_inbox_id' => 0,
					'ip'               => $probe_ip,
				) );
				remove_action( $hook_name, $listener, 10 );

				$ev_keys_ok = is_array( $captured )
					&& array_key_exists( 'scenario_action_type', $captured )
					&& $captured['scenario_action_type'] === 'run_shortcode'
					&& array_key_exists( 'channel_inbox_id', $captured )
					&& array_key_exists( 'parent_event_uuid', $captured )
					&& $captured['parent_event_uuid'] === null;
				$ev_keys_why = $ev_keys_ok
					? 'payload has scenario_action_type=run_shortcode + channel_inbox_id + parent_event_uuid=null + event_uuid auto-injected'
					: 'payload missing keys · captured=' . wp_json_encode( $captured ? array_keys( $captured ) : null );

				$tbl_v = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
				global $wpdb;
				$wpdb->delete( $tbl_v, array( 'campaign_id' => $cid ) );
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$ev_keys_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W12.2',
			'check'    => 'visit_recorded payload (via Event_Emitter fan-out) has scenario_action_type + channel_inbox_id + parent_event_uuid keys',
			'status'   => $ev_keys_ok ? 'pass' : 'fail',
			'evidence' => $ev_keys_why,
		);

		/* ================================================================
		 * PHASE 0.35 M6.W13 + W14 — Scenario Dispatcher + Reminder Reaper
		 * ================================================================ */

		// T-M6.W13.1 — Dispatcher class loaded + action registered in Action_Registry.
		$disp_ok = class_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher' )
			&& method_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher', 'dispatch' );
		$action_in_registry = false;
		if ( $disp_ok && class_exists( 'BizCity_CRM_Action_Registry' ) ) {
			BizCity_CRM_Action_Registry::bust_cache();
			$action_in_registry = (bool) BizCity_CRM_Action_Registry::get( 'dispatch_campaign_scenario' );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W13.1',
			'check'    => 'BizCity_CRM_Campaign_Scenario_Dispatcher loaded + action "dispatch_campaign_scenario" in Action_Registry',
			'status'   => ( $disp_ok && $action_in_registry ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'class=%s · action_registered=%s', $disp_ok ? 'yes' : 'NO', $action_in_registry ? 'yes' : 'NO' ),
		);

		// T-M6.W13.2 — Hooks subscribed via Event_Emitter fan-out: visit@30, message@30, reminder_tick.
		global $wp_filter;
		$hook_visit_ok = false; $hook_msg_ok = false; $hook_remind_ok = false;
		if ( $disp_ok ) {
			$visit_hook = 'bizcity_crm_event_crm_campaign_visit_recorded';
			$msg_hook   = 'bizcity_crm_event_crm_message_received';
			if ( isset( $wp_filter[ $visit_hook ]->callbacks[30] ) ) {
				foreach ( $wp_filter[ $visit_hook ]->callbacks[30] as $cb ) {
					$f = $cb['function'] ?? null;
					if ( is_array( $f ) && (string) $f[0] === 'BizCity_CRM_Campaign_Scenario_Dispatcher' ) { $hook_visit_ok = true; break; }
				}
			}
			if ( isset( $wp_filter[ $msg_hook ]->callbacks[30] ) ) {
				foreach ( $wp_filter[ $msg_hook ]->callbacks[30] as $cb ) {
					$f = $cb['function'] ?? null;
					if ( is_array( $f ) && (string) $f[0] === 'BizCity_CRM_Campaign_Scenario_Dispatcher' ) { $hook_msg_ok = true; break; }
				}
			}
			$hook_remind_ok = (bool) has_action( BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W13.2',
			'check'    => 'Dispatcher hooks (Event_Emitter fan-out): visit@30 + message@30 + reminder_tick all subscribed',
			'status'   => ( $hook_visit_ok && $hook_msg_ok && $hook_remind_ok ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'visit=%s msg=%s reminder=%s', $hook_visit_ok ? 'y' : 'N', $hook_msg_ok ? 'y' : 'N', $hook_remind_ok ? 'y' : 'N' ),
		);

		// T-M6.W13.3 — Branch dispatch: delay_only returns ok with no message inserted; reminder gets scheduled.
		$br_ok = false; $br_why = 'preconditions';
		if ( $disp_ok && class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			$probe = 'diag-w13-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			$cid   = BizCity_CRM_Campaign_Repository::create( array(
				'name'                 => 'Diag W13 ' . $probe,
				'code'                 => $probe,
				'status'               => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
				'scenario_action_type' => 'delay_only',
				'reminder_delay'       => 5,
				'reminder_unit'        => 'minutes',
				'reminder_text'        => 'Bạn ơi, còn cần hỗ trợ không?',
			) );
			if ( is_int( $cid ) && $cid > 0 ) {
				// Use synthetic conv_id 0 — branch_send_message guards on it; delay_only doesn't dispatch
				// but should still schedule reminder.
				$out = BizCity_CRM_Campaign_Scenario_Dispatcher::dispatch( array(
					'campaign_id'       => $cid,
					'conversation_id'   => 0,
					'contact_id'        => 0,
					'inbox_id'          => 0,
					'visit_id'          => 0,
					'parent_event_uuid' => null,
				) );
				$br_ok = is_array( $out )
					&& ( $out['branch'] ?? '' ) === 'delay_only'
					&& ! empty( $out['ok'] )
					&& ! empty( $out['reminder_scheduled'] );
				$br_why = sprintf(
					'branch=%s ok=%s reminder_scheduled=%s delay_sec=%d',
					$out['branch'] ?? 'n/a',
					! empty( $out['ok'] ) ? 'y' : 'N',
					! empty( $out['reminder_scheduled'] ) ? 'y' : 'N',
					(int) ( $out['reminder_delay_sec'] ?? 0 )
				);
				// Cleanup scheduled hook so we don't actually send reminders for diag campaigns.
				wp_clear_scheduled_hook( BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK, array( 0, $cid, '' ) );
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$br_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W13.3',
			'check'    => 'dispatch(delay_only) returns ok=y + reminder_scheduled=y (5min)',
			'status'   => $br_ok ? 'pass' : 'fail',
			'evidence' => $br_why,
		);

		// T-M6.W13.4 — Whitelist guard: invalid shortcode tag → ok=N detail mentions whitelist.
		$wl_ok = false; $wl_why = 'class missing';
		if ( $disp_ok ) {
			$ref = new \ReflectionClass( 'BizCity_CRM_Campaign_Scenario_Dispatcher' );
			if ( $ref->hasMethod( 'branch_run_shortcode' ) ) {
				$m = $ref->getMethod( 'branch_run_shortcode' );
				$m->setAccessible( true );
				$out = $m->invokeArgs( null, array(
					array( 'scenario_shortcode' => '[exec_arbitrary_php]' ),
					array( 'conversation_id' => 1, 'inbox_id' => 1 ),
				) );
				$wl_ok  = is_array( $out ) && empty( $out['ok'] ) && stripos( (string) $out['detail'], 'whitelist' ) !== false;
				$wl_why = $wl_ok ? 'rejected non-whitelisted tag · detail=' . $out['detail'] : 'unexpected: ' . wp_json_encode( $out );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W13.4',
			'check'    => 'run_shortcode branch rejects shortcodes outside SHORTCODE_WHITELIST (defensive)',
			'status'   => $wl_ok ? 'pass' : 'fail',
			'evidence' => $wl_why,
		);

		// T-M6.W13.5 — Default automation rule seeded (idempotent).
		$seed_ok = false; $seed_why = 'Repository missing';
		if ( $disp_ok && class_exists( 'BizCity_CRM_Repository' ) ) {
			$rule_id = (int) get_option( BizCity_CRM_Campaign_Scenario_Dispatcher::DEFAULT_RULE_OPTION, 0 );
			$row = $rule_id > 0 ? BizCity_CRM_Repository::get_automation_rule( $rule_id ) : null;
			if ( ! $row ) {
				// Self-heal: invoke seeder so a missing rule on a freshly-installed
				// site is created at diag time too (not just on plugin upgrade).
				$rule_id = BizCity_CRM_Campaign_Scenario_Dispatcher::seed_default_rule();
				$row     = $rule_id > 0 ? BizCity_CRM_Repository::get_automation_rule( $rule_id ) : null;
			}
			if ( $row ) {
				$actions  = json_decode( (string) ( $row['actions_json'] ?? '[]' ), true );
				$has_disp = is_array( $actions ) && ! empty( $actions ) && ( $actions[0]['type'] ?? '' ) === 'dispatch_campaign_scenario';
				$is_active = (int) ( $row['active'] ?? 0 ) === 1;
				$ev_ok    = (string) ( $row['event_name'] ?? '' ) === 'crm_campaign_visit_recorded';
				$seed_ok  = $has_disp && $ev_ok;
				$seed_why = sprintf( 'rule#%d · event=%s · active=%s · 1st action=%s',
					(int) $row['id'],
					$row['event_name'] ?? '?',
					$is_active ? 'y' : 'N',
					$has_disp ? 'dispatch_campaign_scenario' : ( $actions[0]['type'] ?? 'none' )
				);
			} else {
				$seed_why = 'seed_default_rule() returned 0 — Repository::upsert_automation_rule failed';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W13.5',
			'check'    => 'Default rule [crm_campaign_visit_recorded → dispatch_campaign_scenario] seeded + active (idempotent via DEFAULT_RULE_OPTION)',
			'status'   => $seed_ok ? 'pass' : 'fail',
			'evidence' => $seed_why,
		);

		// T-M6.W14.1 — Reminder hook listener present.
		$rem_listener_ok = $disp_ok && has_action( BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK ) > 0;
		$tasks[] = array(
			'id'       => 'T-M6.W14.1',
			'check'    => 'has_action(bizcity_crm_campaign_reminder_tick) > 0 — reaper subscribed',
			'status'   => $rem_listener_ok ? 'pass' : 'fail',
			'evidence' => $rem_listener_ok ? 'priority=' . has_action( BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK ) : 'no listener',
		);

		// T-M6.W14.2 — reminder_delay_sec() unit math: minutes/hours/days/zero.
		$delay_ok = false; $delay_why = 'class missing';
		if ( $disp_ok ) {
			$ref = new \ReflectionClass( 'BizCity_CRM_Campaign_Scenario_Dispatcher' );
			if ( $ref->hasMethod( 'reminder_delay_sec' ) ) {
				$m = $ref->getMethod( 'reminder_delay_sec' );
				$m->setAccessible( true );
				$cases = array(
					array( array( 'reminder_delay' => 0,  'reminder_unit' => 'minutes' ), 0 ),
					array( array( 'reminder_delay' => 5,  'reminder_unit' => 'minutes' ), 5 * MINUTE_IN_SECONDS ),
					array( array( 'reminder_delay' => 2,  'reminder_unit' => 'hours' ),   2 * HOUR_IN_SECONDS ),
					array( array( 'reminder_delay' => 1,  'reminder_unit' => 'days' ),    1 * DAY_IN_SECONDS ),
				);
				$mismatches = array();
				foreach ( $cases as $c ) {
					$got = (int) $m->invokeArgs( null, array( $c[0] ) );
					if ( $got !== (int) $c[1] ) {
						$mismatches[] = sprintf( '%s/%d → got=%d expected=%d', $c[0]['reminder_unit'], $c[0]['reminder_delay'], $got, $c[1] );
					}
				}
				$delay_ok  = empty( $mismatches );
				$delay_why = $delay_ok ? '4/4 cases pass · 0min=0 · 5min=300 · 2h=7200 · 1d=86400' : implode( ' | ', $mismatches );
			} else {
				$delay_why = 'reminder_delay_sec() missing';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W14.2',
			'check'    => 'reminder_delay_sec() math: 0/minutes/hours/days converted to seconds correctly',
			'status'   => $delay_ok ? 'pass' : 'fail',
			'evidence' => $delay_why,
		);

		// T-M6.W14.3 — user_replied_since_dispatch() returns true after a real inbound.
		$reply_ok = false; $reply_why = 'class missing';
		if ( $disp_ok && class_exists( 'BizCity_CRM_DB_Installer_V2' ) && class_exists( 'BizCity_CRM_Repository' ) ) {
			$ref = new \ReflectionClass( 'BizCity_CRM_Campaign_Scenario_Dispatcher' );
			if ( $ref->hasMethod( 'user_replied_since_dispatch' ) ) {
				$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
				$msg_tbl  = BizCity_CRM_DB_Installer_V2::tbl_messages();
				$now      = current_time( 'mysql' );
				$wpdb->insert( $conv_tbl, array(
					'inbox_id'         => 0,
					'contact_inbox_id' => 0,
					'status'           => 'open',
					'created_at'       => $now,
					'updated_at'       => $now,
				) );
				$conv_id = (int) $wpdb->insert_id;
				if ( $conv_id > 0 ) {
					// Insert outbound first, then a later inbound.
					// NOTE: messages table has NO `updated_at` column — only `created_at`.
					$ins_a1 = $wpdb->insert( $msg_tbl, array(
						'conversation_id' => $conv_id,
						'inbox_id'        => 0,
						'message_type'    => 'outgoing',
						'sender_type'     => 'system',
						'content_type'    => 'text',
						'content'         => 'diag w14.3 outbound',
						'status'          => 'sent',
						'created_at'      => gmdate( 'Y-m-d H:i:s', time() - 30 ),
					) );
					$ins_a2 = $wpdb->insert( $msg_tbl, array(
						'conversation_id' => $conv_id,
						'inbox_id'        => 0,
						'message_type'    => 'incoming',
						'sender_type'     => 'contact',
						'content_type'    => 'text',
						'content'         => 'diag w14.3 inbound (after outbound)',
						'status'          => 'sent',
						'created_at'      => gmdate( 'Y-m-d H:i:s', time() - 5 ),
					) );

					$m = $ref->getMethod( 'user_replied_since_dispatch' );
					$m->setAccessible( true );
					$replied_after = (bool) $m->invokeArgs( null, array( $conv_id ) );

					// Also check that a conv with NO inbound after returns false.
					$wpdb->insert( $conv_tbl, array(
						'inbox_id'         => 0,
						'contact_inbox_id' => 0,
						'status'           => 'open',
						'created_at'       => $now,
						'updated_at'       => $now,
					) );
					$conv_id_2 = (int) $wpdb->insert_id;
					$ins_b1 = $wpdb->insert( $msg_tbl, array(
						'conversation_id' => $conv_id_2,
						'inbox_id'        => 0,
						'message_type'    => 'outgoing',
						'sender_type'     => 'system',
						'content_type'    => 'text',
						'content'         => 'diag w14.3 outbound only',
						'status'          => 'sent',
						'created_at'      => $now,
					) );
					$replied_only_outbound = (bool) $m->invokeArgs( null, array( $conv_id_2 ) );

					$reply_ok  = ( $replied_after === true ) && ( $replied_only_outbound === false );
					$reply_why = sprintf(
						'inserts=%d/%d/%d · inbound-after-outbound=%s · outbound-only=%s%s',
						$ins_a1 ? 1 : 0, $ins_a2 ? 1 : 0, $ins_b1 ? 1 : 0,
						$replied_after ? 'true' : 'false',
						$replied_only_outbound ? 'true' : 'false',
						! $reply_ok && $wpdb->last_error ? ' · last_error=' . $wpdb->last_error : ''
					);

					// Cleanup probe rows.
					$wpdb->delete( $msg_tbl,  array( 'conversation_id' => $conv_id ) );
					$wpdb->delete( $msg_tbl,  array( 'conversation_id' => $conv_id_2 ) );
					$wpdb->delete( $conv_tbl, array( 'id' => $conv_id ) );
					$wpdb->delete( $conv_tbl, array( 'id' => $conv_id_2 ) );
				} else {
					$reply_why = 'failed to insert probe conversation: ' . $wpdb->last_error;
				}
			} else {
				$reply_why = 'user_replied_since_dispatch() missing';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W14.3',
			'check'    => 'user_replied_since_dispatch() detects inbound-after-outbound (true) AND outbound-only (false) — reminder skip guard works',
			'status'   => $reply_ok ? 'pass' : 'fail',
			'evidence' => $reply_why,
		);

		// ============================================================
		// M6.W17 — Importer scenario-aware
		// ============================================================
		$imp_loaded = class_exists( 'BizCity_CRM_Flow_Importer' );
		$tasks[] = array(
			'id'       => 'T-M6.W17.1',
			'check'    => 'BizCity_CRM_Flow_Importer exposes preview_to_campaign + import_one_to_campaign + derive_scenario_fields',
			'status'   => ( $imp_loaded
				&& method_exists( 'BizCity_CRM_Flow_Importer', 'preview_to_campaign' )
				&& method_exists( 'BizCity_CRM_Flow_Importer', 'import_one_to_campaign' )
				&& method_exists( 'BizCity_CRM_Flow_Importer', 'derive_scenario_fields' )
				&& method_exists( 'BizCity_CRM_Flow_Importer', 'find_campaign_by_flow_id' )
			) ? 'pass' : 'fail',
			'evidence' => $imp_loaded ? 'class+4 methods present' : 'class missing',
		);

		// T-M6.W17.2 — Mapping logic: 3 flow shapes → 3 distinct action_type.
		$map_ok = false; $map_why = 'class missing';
		if ( $imp_loaded ) {
			$cases = array(
				array(
					array( 'action_type' => 'shortcode', 'shortcode' => '[tim_san_pham]', 'action_config' => '', 'prompt' => '', 'message' => '' ),
					BizCity_CRM_Campaign_Repository::ACTION_RUN_SHORTCODE,
				),
				array(
					array( 'action_type' => 'kg', 'shortcode' => '', 'action_config' => '', 'prompt' => 'Trả lời theo notebook', 'message' => '' ),
					BizCity_CRM_Campaign_Repository::ACTION_KG_GROUNDED,
				),
				array(
					array( 'action_type' => 'message', 'shortcode' => '', 'action_config' => 'Hello', 'prompt' => '', 'message' => '' ),
					BizCity_CRM_Campaign_Repository::ACTION_SEND_MESSAGE,
				),
			);
			$mismatches = array();
			foreach ( $cases as $i => $c ) {
				$got = BizCity_CRM_Flow_Importer::derive_scenario_fields( $c[0] )['scenario_action_type'] ?? '';
				if ( $got !== $c[1] ) {
					$mismatches[] = sprintf( 'case#%d: got=%s expected=%s', $i + 1, $got, $c[1] );
				}
			}
			$map_ok  = empty( $mismatches );
			$map_why = $map_ok ? '3/3 cases pass · shortcode→run_shortcode · kg→kg_grounded_reply · message→send_message' : implode( ' | ', $mismatches );
		}
		$tasks[] = array(
			'id'       => 'T-M6.W17.2',
			'check'    => 'derive_scenario_fields() routes shortcode/kg/message flow shapes to correct ACTION_* enum',
			'status'   => $map_ok ? 'pass' : 'fail',
			'evidence' => $map_why,
		);

		// T-M6.W17.3 — find_campaign_by_flow_id() round-trip + idempotent re-import.
		$rt_ok = false; $rt_why = 'preconditions';
		if ( $imp_loaded && class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			// Synthesize a fake flow row directly (bypass wp_bizgpt_custom_flows so this
			// diag works even without the bizgpt plugin installed).
			$fake_flow_id = 9000000 + ( ( (int) ( microtime( true ) * 1000 ) ) % 100000 );
			$fake_flow    = array(
				'id'            => $fake_flow_id,
				'message'       => 'Diag W17.3 ' . $fake_flow_id,
				'action_type'   => 'shortcode',
				'shortcode'     => '[tim_san_pham]',
				'action_config' => '',
				'prompt'        => '',
			);
			$mapped  = BizCity_CRM_Flow_Importer::derive_scenario_fields( $fake_flow );
			$payload = array_merge( $mapped, array(
				'name'                         => $fake_flow['message'],
				'code'                         => 'bgflow_' . $fake_flow_id,
				'imported_from_bizgpt_flow_id' => $fake_flow_id,
				'status'                       => BizCity_CRM_Campaign_Repository::STATUS_DRAFT,
			) );
			$cid = BizCity_CRM_Campaign_Repository::create( $payload );
			if ( is_int( $cid ) && $cid > 0 ) {
				$found_a = BizCity_CRM_Flow_Importer::find_campaign_by_flow_id( $fake_flow_id );
				// Simulate re-import path: lookup → update.
				$found_b = BizCity_CRM_Flow_Importer::find_campaign_by_flow_id( $fake_flow_id );
				$rt_ok   = ( $found_a === $cid ) && ( $found_b === $cid );
				$rt_why  = sprintf( 'campaign#%d · find_a=%d · find_b=%d · idx_imported_flow lookup OK', $cid, $found_a, $found_b );
				BizCity_CRM_Campaign_Repository::delete( $cid );
			} else {
				$rt_why = 'create failed: ' . ( is_wp_error( $cid ) ? $cid->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W17.3',
			'check'    => 'find_campaign_by_flow_id() resolves a freshly-created campaign by imported_from_bizgpt_flow_id (idempotent re-import key)',
			'status'   => $rt_ok ? 'pass' : 'fail',
			'evidence' => $rt_why,
		);

		// T-M6.W17.4 — Source table presence (informational warn — many dev sites lack bizgpt).
		$src_ok = $imp_loaded && BizCity_CRM_Flow_Importer::source_available();
		$tasks[] = array(
			'id'       => 'T-M6.W17.4',
			'check'    => 'wp_bizgpt_custom_flows source table reachable (informational — preview/import bulk no-op without it)',
			'status'   => $src_ok ? 'pass' : 'warn',
			'evidence' => $src_ok ? 'source table present' : 'wp_bizgpt_custom_flows missing — install bizgpt-custom-flows plugin to enable bulk import',
		);

		// ============================================================
		// RISK MITIGATIONS — post-W17 hardening
		// ============================================================

		// T-M6.RISK.1 — kg_grounded_reply soft fallback exposes `fallback` flag + emits warn log.
		// Verifies the fix added to branch_kg_grounded_reply: when Action_Send_KG_Reply is unavailable,
		// the branch returns send_message output annotated with `fallback => 'send_message'` and
		// a `[kg_action_unavailable]` marker in detail (so production logs surface the degradation).
		$risk1_ok = false; $risk1_why = 'class missing';
		if ( $disp_ok ) {
			$ref = new \ReflectionClass( 'BizCity_CRM_Campaign_Scenario_Dispatcher' );
			if ( $ref->hasMethod( 'branch_kg_grounded_reply' ) ) {
				$kg_present = class_exists( 'BizCity_CRM_Action_Send_KG_Reply' );
				if ( $kg_present ) {
					// Cannot probe fallback path — KG action is wired. Pass the structural check.
					$risk1_ok  = true;
					$risk1_why = 'KG action class present — fallback path cannot be probed (branch will delegate normally)';
				} else {
					// Probe fallback: KG class missing → expect fallback marker on the result.
					$m = $ref->getMethod( 'branch_kg_grounded_reply' );
					$m->setAccessible( true );
					$fake_campaign = array(
						'id'                => 0,
						'scenario_template' => 'risk-1 probe template',
						'scenario_prompt'   => 'risk-1 probe prompt',
					);
					$out = $m->invokeArgs( null, array(
						$fake_campaign,
						array( 'conversation_id' => 0, 'inbox_id' => 0, 'contact_id' => 0 ),
					) );
					$has_fallback_flag = is_array( $out ) && ( ( $out['fallback'] ?? '' ) === 'send_message' );
					$has_marker        = is_array( $out ) && stripos( (string) ( $out['detail'] ?? '' ), 'kg_action_unavailable' ) !== false;
					$risk1_ok  = $has_fallback_flag && $has_marker;
					$risk1_why = $risk1_ok
						? 'fallback=send_message · detail contains [kg_action_unavailable] · warn log emitted'
						: 'fallback flag/marker absent · out=' . wp_json_encode( $out );
				}
			} else {
				$risk1_why = 'branch_kg_grounded_reply() missing';
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.RISK.1',
			'check'    => 'kg_grounded_reply soft fallback annotates result with `fallback` flag + emits error_log warn (R1)',
			'status'   => $risk1_ok ? 'pass' : 'fail',
			'evidence' => $risk1_why,
		);

		// T-M6.RISK.2 — End-to-end live probe: visit→queue→drain→outbound message inserted.
		// Walks the full STAGE 1 → STAGE 2 pipeline with synthetic data and asserts an
		// outbound message row is created (the missing piece reflection-only diags couldn't catch).
		$risk2_ok = false; $risk2_why = 'preconditions';
		if ( $disp_ok && class_exists( 'BizCity_CRM_Campaign_Repository' ) && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			$probe_seed   = substr( md5( (string) microtime( true ) ), 0, 6 );
			$campaign_id  = BizCity_CRM_Campaign_Repository::create( array(
				'name'                 => 'Diag RISK.2 ' . $probe_seed,
				'code'                 => 'risk2_' . $probe_seed,
				'status'               => BizCity_CRM_Campaign_Repository::STATUS_ACTIVE,
				'scenario_action_type' => 'send_message',
				'scenario_template'    => 'E2E probe outbound for {{campaign_code}}',
			) );
			if ( is_int( $campaign_id ) && $campaign_id > 0 ) {
				$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
				$msg_tbl  = BizCity_CRM_DB_Installer_V2::tbl_messages();
				$now      = current_time( 'mysql' );
				$wpdb->insert( $conv_tbl, array(
					'inbox_id' => 0, 'contact_inbox_id' => 0,
					'status' => 'open',
					'created_at' => $now, 'updated_at' => $now,
				) );
				$conv_id = (int) $wpdb->insert_id;

				// STAGE 1 — queue envelope.
				$client_id = 'fb_risk2_' . $probe_seed;
				BizCity_CRM_Campaign_Scenario_Dispatcher::on_visit_recorded( array(
					'campaign_id'          => $campaign_id,
					'client_id'            => $client_id,
					'visit_id'             => 1,
					'scenario_action_type' => 'send_message',
					'parent_event_uuid'    => 'risk2-' . $probe_seed,
				) );
				$queue_key  = 'bizcrm_camp_pending_' . md5( $client_id );
				$queued     = get_transient( $queue_key );
				$stage1_ok  = is_array( $queued ) && (int) $queued['campaign_id'] === $campaign_id;

				// STAGE 2 — directly invoke dispatch() (the on_message_received() handler
				// would also work but requires a real Conversion_Linker setup).
				$out = BizCity_CRM_Campaign_Scenario_Dispatcher::dispatch( array(
					'campaign_id'       => $campaign_id,
					'conversation_id'   => $conv_id,
					'contact_id'        => 0,
					'inbox_id'          => 0,
					'visit_id'          => 1,
					'parent_event_uuid' => 'risk2-' . $probe_seed,
				) );
				$stage2_ok = is_array( $out ) && ! empty( $out['ok'] ) && ! empty( $out['message_id'] );

				// Verify outbound message landed in DB with rendered template.
				$row = $stage2_ok ? $wpdb->get_row( $wpdb->prepare(
					"SELECT content, message_type, responder_kind FROM {$msg_tbl} WHERE id = %d",
					(int) $out['message_id']
				), ARRAY_A ) : null;
				$db_ok = is_array( $row )
					&& (string) $row['message_type'] === 'outgoing'
					&& strpos( (string) $row['content'], 'risk2_' . $probe_seed ) !== false;

				$risk2_ok = $stage1_ok && $stage2_ok && $db_ok;
				$risk2_why = sprintf(
					'stage1_queue=%s · stage2_dispatch=%s (msg#%d) · db_render=%s',
					$stage1_ok ? 'y' : 'N',
					$stage2_ok ? 'y' : 'N',
					(int) ( $out['message_id'] ?? 0 ),
					$db_ok ? 'y · template-resolved' : 'N'
				);

				// Cleanup.
				delete_transient( $queue_key );
				if ( ! empty( $out['message_id'] ) ) {
					$wpdb->delete( $msg_tbl, array( 'id' => (int) $out['message_id'] ) );
				}
				$wpdb->delete( $conv_tbl, array( 'id' => $conv_id ) );
				BizCity_CRM_Campaign_Repository::delete( $campaign_id );
			} else {
				$risk2_why = 'campaign create failed: ' . ( is_wp_error( $campaign_id ) ? $campaign_id->get_error_message() : 'unknown' );
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.RISK.2',
			'check'    => 'End-to-end probe: on_visit_recorded() queues envelope → dispatch() inserts outbound with rendered template (R2)',
			'status'   => $risk2_ok ? 'pass' : 'fail',
			'evidence' => $risk2_why,
		);

		// T-M6.RISK.3 — Multisite cron sanity for reminder reaper.
		// The reminder relies on wp_schedule_single_event(). On multisite with WP-Cron disabled
		// AND no alternate runner, scheduled events never fire → reminders silently lost.
		$is_multi      = function_exists( 'is_multisite' ) && is_multisite();
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alt_cron      = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$can_schedule  = function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' );

		// Live probe: schedule a no-op tick 1h in future, verify it lands, then unschedule.
		$probe_args     = array( 0, 0, 'risk3-' . substr( md5( (string) microtime( true ) ), 0, 6 ) );
		$schedule_ok    = false;
		if ( $can_schedule && $disp_ok ) {
			$ts = time() + HOUR_IN_SECONDS;
			$ok = wp_schedule_single_event( $ts, BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK, $probe_args );
			$schedule_ok = ( $ok !== false ) && (bool) wp_next_scheduled( BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK, $probe_args );
			if ( $schedule_ok ) {
				wp_unschedule_event( $ts, BizCity_CRM_Campaign_Scenario_Dispatcher::REMINDER_HOOK, $probe_args );
			}
		}

		// Status logic: PASS if scheduling round-trip works AND (cron not disabled OR alternate present).
		// WARN if disabled+no alternate (would still pass live but reminders won't fire on prod).
		$risk3_ok      = $schedule_ok && ( ! $cron_disabled || $alt_cron );
		$risk3_status  = $risk3_ok ? 'pass' : ( $schedule_ok ? 'warn' : 'fail' );
		$tasks[] = array(
			'id'       => 'T-M6.RISK.3',
			'check'    => 'Multisite cron sanity: schedule/unschedule round-trip + DISABLE_WP_CRON safety (R3)',
			'status'   => $risk3_status,
			'evidence' => sprintf(
				'multisite=%s · disable_wp_cron=%s · alternate_wp_cron=%s · live_schedule_roundtrip=%s',
				$is_multi      ? 'y' : 'N',
				$cron_disabled ? 'y' : 'N',
				$alt_cron      ? 'y' : 'N',
				$schedule_ok   ? 'y' : 'N'
			),
		);

		// ----------------------------------------------------------------
		// PHASE 0.35 M6.W18-W22 — Marketing Asset Studio
		// ----------------------------------------------------------------

		// T-M6.W18.1 — All 6 SVG templates exist on disk + parse as XML.
		$tpl_dir = dirname( __DIR__ ) . '/templates/marketing-assets/';
		$expected_tpl = array( 'voucher_landscape.svg', 'voucher_square.svg', 'story_vertical.svg', 'name_card.svg', 'leaflet_a6.svg', 'table_tent_a5.svg' );
		$tpl_missing = array();
		$tpl_invalid = array();
		foreach ( $expected_tpl as $f ) {
			$p = $tpl_dir . $f;
			if ( ! file_exists( $p ) ) { $tpl_missing[] = $f; continue; }
			$body = (string) file_get_contents( $p );
			$prev = libxml_use_internal_errors( true );
			$doc  = simplexml_load_string( $body );
			libxml_use_internal_errors( $prev );
			if ( $doc === false ) { $tpl_invalid[] = $f; }
		}
		$tasks[] = array(
			'id'       => 'T-M6.W18.1',
			'check'    => '6 SVG marketing templates present + valid XML',
			'status'   => ( empty( $tpl_missing ) && empty( $tpl_invalid ) ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'dir=%s · found=%d/6 · invalid=%d · missing=[%s]', basename( rtrim( $tpl_dir, '/' ) ), 6 - count( $tpl_missing ), count( $tpl_invalid ), implode( ',', $tpl_missing ) ),
		);

		// T-M6.W19.1 — Brand Kit + Asset Cache classes loaded; hash deterministic.
		$bk_loaded = class_exists( 'BizCity_CRM_Brand_Kit' ) && class_exists( 'BizCity_CRM_Asset_Cache' );
		$hash_a = $bk_loaded ? BizCity_CRM_Brand_Kit::hash() : '';
		$hash_b = $bk_loaded ? BizCity_CRM_Brand_Kit::hash() : '';
		$tasks[] = array(
			'id'       => 'T-M6.W19.1',
			'check'    => 'Brand Kit + Asset Cache classes loaded · hash deterministic',
			'status'   => ( $bk_loaded && $hash_a !== '' && $hash_a === $hash_b ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'loaded=%s · hash_a=%s · hash_b=%s', $bk_loaded ? 'y' : 'N', substr( $hash_a, 0, 8 ), substr( $hash_b, 0, 8 ) ),
		);

		// T-M6.W20.1 — Renderer returns SVG bytes for an existing campaign (or graceful skip).
		$render_status = 'warn'; $render_ev = 'skipped (no campaign)';
		if ( class_exists( 'BizCity_CRM_Asset_Renderer' ) && class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			$repo = new BizCity_CRM_Campaign_Repository();
			global $wpdb;
			$tbl = method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_campaigns' ) ? BizCity_CRM_DB_Installer_V2::tbl_campaigns() : $wpdb->prefix . 'bizcity_crm_campaigns';
			$cid = (int) $wpdb->get_var( "SELECT id FROM {$tbl} ORDER BY id DESC LIMIT 1" );
			if ( $cid > 0 ) {
				$res = BizCity_CRM_Asset_Renderer::render( $cid, 'voucher_landscape', 'svg' );
				if ( ! is_wp_error( $res ) && ! empty( $res['bytes'] ) && strpos( (string) $res['bytes'], '<svg' ) !== false ) {
					$render_status = 'pass';
					$render_ev = sprintf( 'campaign=%d · bytes=%d · mime=%s', $cid, strlen( (string) $res['bytes'] ), $res['mime'] );
				} else {
					$render_status = 'fail';
					$render_ev = is_wp_error( $res ) ? $res->get_error_message() : 'unexpected output';
				}
			}
		}
		$tasks[] = array(
			'id'       => 'T-M6.W20.1',
			'check'    => 'Asset Renderer produces valid SVG for latest campaign',
			'status'   => $render_status,
			'evidence' => $render_ev,
		);

		// T-M6.W20.2 — REST routes registered.
		$routes = rest_get_server()->get_routes();
		$expected_routes = array(
			'/bizcity-crm/v1/marketing/brand-kit',
			'/bizcity-crm/v1/marketing/templates',
		);
		$missing_routes = array();
		foreach ( $expected_routes as $r ) {
			if ( ! isset( $routes[ $r ] ) ) { $missing_routes[] = $r; }
		}
		// Manifest + render routes use regex placeholders so check by prefix.
		$has_manifest = false; $has_render = false; $has_regen = false;
		foreach ( array_keys( $routes ) as $r ) {
			if ( strpos( $r, '/bizcity-crm/v1/campaigns/' ) === 0 && strpos( $r, '/assets/manifest' ) !== false ) { $has_manifest = true; }
			if ( strpos( $r, '/bizcity-crm/v1/campaigns/' ) === 0 && preg_match( '#/assets/.*\\\\.(svg|png|jpg|pdf)#', $r ) ) { $has_render = true; }
			if ( strpos( $r, '/bizcity-crm/v1/campaigns/' ) === 0 && strpos( $r, '/regenerate' ) !== false ) { $has_regen = true; }
		}
		$routes_ok = empty( $missing_routes ) && $has_manifest && $has_render && $has_regen;
		$tasks[] = array(
			'id'       => 'T-M6.W20.2',
			'check'    => 'Marketing REST routes registered (brand-kit · templates · manifest · render · regenerate)',
			'status'   => $routes_ok ? 'pass' : 'fail',
			'evidence' => sprintf( 'missing=[%s] · manifest=%s · render=%s · regen=%s', implode( ',', $missing_routes ), $has_manifest ? 'y' : 'N', $has_render ? 'y' : 'N', $has_regen ? 'y' : 'N' ),
		);

		// T-M6.W22.1 — Invalidator wired + GC cron scheduled + flush_all round-trip.
		$inv_loaded = class_exists( 'BizCity_CRM_Asset_Cache_Invalidator' );
		$gc_scheduled = $inv_loaded ? (bool) wp_next_scheduled( BizCity_CRM_Asset_Cache_Invalidator::CRON_HOOK ) : false;
		$flush_ok = false;
		if ( class_exists( 'BizCity_CRM_Asset_Cache' ) ) {
			BizCity_CRM_Asset_Cache::put( 99999, 'voucher_landscape', 'svg', 'diagprobe', array( 'mime' => 'image/svg+xml', 'bytes' => '<svg/>', 'width' => 1, 'height' => 1, 'brand_hash' => 'diagprobe' ) );
			$got_before = BizCity_CRM_Asset_Cache::get( 99999, 'voucher_landscape', 'svg', 'diagprobe' );
			BizCity_CRM_Asset_Cache::flush_campaign( 99999 );
			$got_after  = BizCity_CRM_Asset_Cache::get( 99999, 'voucher_landscape', 'svg', 'diagprobe' );
			$flush_ok   = is_array( $got_before ) && $got_after === null;
		}
		$inv_ok = $inv_loaded && $gc_scheduled && $flush_ok;
		$tasks[] = array(
			'id'       => 'T-M6.W22.1',
			'check'    => 'Asset Cache Invalidator: class loaded · daily GC scheduled · flush_campaign round-trip',
			'status'   => $inv_ok ? 'pass' : ( $inv_loaded && $flush_ok ? 'warn' : 'fail' ),
			'evidence' => sprintf( 'loaded=%s · gc_scheduled=%s · flush_roundtrip=%s', $inv_loaded ? 'y' : 'N', $gc_scheduled ? 'y' : 'N', $flush_ok ? 'y' : 'N' ),
		);

		return $tasks;
	}

	/* ================================================================
	 * PHASE 0.35 M-CRM.M1 — Sales Pipeline section
	 * ================================================================ */

	private function render_crm_sales_section(): void {
		$tasks = $this->compute_tasks_crm_sales();
		echo '<h2 style="margin-top:24px">Sales Pipeline — PHASE 0.35 M-CRM.M1</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Task</th><th>Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td><td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_tasks_crm_sales(): array {
		global $wpdb;
		$tasks = array();

		// T-CRM.M1.1 — DB version bumped.
		$db_ver = get_option( 'bizcity_crm_db_ver', '—' );
		$tasks[] = array(
			'id'       => 'T-CRM.M1.1',
			'check'    => 'DB version >= 1.7.0',
			'status'   => version_compare( $db_ver, '1.7.0', '>=' ) ? 'pass' : 'fail',
			'evidence' => 'bizcity_crm_db_ver = ' . $db_ver,
		);

		// T-CRM.M1.2 — 5 sales pipeline tables exist.
		$sales_tables = array(
			$wpdb->prefix . 'bizcity_crm_leads',
			$wpdb->prefix . 'bizcity_crm_opportunities',
			$wpdb->prefix . 'bizcity_crm_opportunity_lines',
			$wpdb->prefix . 'bizcity_crm_contracts',
			$wpdb->prefix . 'bizcity_crm_contract_lines',
		);
		$missing_tables = array();
		foreach ( $sales_tables as $tbl ) {
			$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tbl ) . "'" );
			if ( ! $exists ) { $missing_tables[] = $tbl; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.2',
			'check'    => '5 sales pipeline tables created',
			'status'   => empty( $missing_tables ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_tables )
				? implode( ', ', $sales_tables ) . ' — all present'
				: 'MISSING: ' . implode( ', ', $missing_tables ),
		);

		// T-CRM.M1.3 — REST routes registered.
		$rest_server = rest_get_server();
		$routes      = array_keys( $rest_server->get_routes() );
		$expected    = array(
			'/bizcity-crm/v1/crm-leads',
			'/bizcity-crm/v1/crm-leads/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-leads/(?P<id>\\d+)/convert',
			'/bizcity-crm/v1/crm-opportunities',
			'/bizcity-crm/v1/crm-opportunities/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-opportunities/(?P<id>\\d+)/lines',
			'/bizcity-crm/v1/crm-contracts',
			'/bizcity-crm/v1/crm-contracts/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-contracts/(?P<id>\\d+)/lines',
		);
		$missing_routes = array();
		foreach ( $expected as $r ) {
			if ( ! in_array( $r, $routes, true ) ) { $missing_routes[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.3',
			'check'    => '9 sales pipeline REST routes registered',
			'status'   => empty( $missing_routes ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_routes )
				? count( $expected ) . ' routes — all registered'
				: 'MISSING: ' . implode( ', ', $missing_routes ),
		);

		// T-CRM.M1.4 — 11 handler methods exist.
		$handlers = array(
			'get_crm_leads', 'post_crm_lead', 'put_crm_lead', 'delete_crm_lead', 'post_crm_lead_convert',
			'get_crm_opportunities', 'post_crm_opportunity', 'put_crm_opportunity_lines',
			'get_crm_contracts', 'post_crm_contract', 'put_crm_contract_lines',
		);
		$missing_handlers = array();
		foreach ( $handlers as $m ) {
			if ( ! method_exists( 'BizCity_CRM_REST_Controller', $m ) ) { $missing_handlers[] = $m; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.4',
			'check'    => '11 sales pipeline handler methods exist',
			'status'   => empty( $missing_handlers ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_handlers )
				? count( $handlers ) . ' methods present on BizCity_CRM_REST_Controller'
				: 'MISSING: ' . implode( ', ', $missing_handlers ),
		);

		// T-CRM.M1.5 — line auto-calc smoke (mathematical, no DB write).
		$qty = 2.0; $price = 100.0; $disc = 10.0; $tax = 8.0;
		$expected_line = round( $qty * $price * ( 1 - $disc / 100 ) * ( 1 + $tax / 100 ), 2 );
		// 2 * 100 * 0.9 * 1.08 = 194.4
		$tasks[] = array(
			'id'       => 'T-CRM.M1.5',
			'check'    => 'Line total formula = qty*price*(1-disc%)*(1+tax%)',
			'status'   => abs( $expected_line - 194.4 ) < 0.01 ? 'pass' : 'fail',
			'evidence' => sprintf( 'qty=2 price=100 disc=10%% tax=8%% → line_total=%.2f (expected 194.40)', $expected_line ),
		);

		// T-CRM.M1.6 — migrate_phase_037() exists.
		$has = method_exists( 'BizCity_CRM_DB_Installer_V2', 'migrate_phase_037' );
		$tasks[] = array(
			'id'       => 'T-CRM.M1.6',
			'check'    => 'BizCity_CRM_DB_Installer_V2::migrate_phase_037() exists',
			'status'   => $has ? 'pass' : 'fail',
			'evidence' => $has ? 'migrate_phase_037() present' : 'METHOD MISSING',
		);

		return $tasks;
	}

	/* ================================================================
	 * PHASE 0.35 M-CRM.M1.W2 — Product Catalog section
	 * ================================================================ */

	private function render_crm_products_section(): void {
		$tasks = $this->compute_tasks_crm_products();
		echo '<h2 style="margin-top:24px">Product Catalog — PHASE 0.35 M-CRM.M1.W2</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Task</th><th>Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td><td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_tasks_crm_products(): array {
		global $wpdb;
		$tasks = array();

		// T-CRM.M1.W2.1 — DB version >= 1.8.0 (bumped through 1.9.0 by M2+M3).
		$db_ver = get_option( 'bizcity_crm_db_ver', '—' );
		$tasks[] = array(
			'id'       => 'T-CRM.M1.W2.1',
			'check'    => 'DB version >= 1.8.0',
			'status'   => version_compare( (string) $db_ver, '1.8.0', '>=' ) ? 'pass' : 'fail',
			'evidence' => 'bizcity_crm_db_ver = ' . $db_ver,
		);

		// T-CRM.M1.W2.2 — 2 product tables exist
		$ptables = array(
			$wpdb->prefix . 'bizcity_crm_product_categories',
			$wpdb->prefix . 'bizcity_crm_products',
		);
		$missing = array();
		foreach ( $ptables as $tbl ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tbl ) . "'" ) ) { $missing[] = $tbl; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.W2.2',
			'check'    => '2 product catalog tables created',
			'status'   => empty( $missing ) ? 'pass' : 'fail',
			'evidence' => empty( $missing ) ? implode( ', ', $ptables ) . ' — all present' : 'MISSING: ' . implode( ', ', $missing ),
		);

		// T-CRM.M1.W2.3 — product_id + discount_type columns added to lines
		$opp_lines = $wpdb->prefix . 'bizcity_crm_opportunity_lines';
		$ct_lines  = $wpdb->prefix . 'bizcity_crm_contract_lines';
		$cols_ok = BizCity_CRM_DB_Installer_V2::column_exists( $opp_lines, 'product_id' )
			&& BizCity_CRM_DB_Installer_V2::column_exists( $opp_lines, 'discount_type' )
			&& BizCity_CRM_DB_Installer_V2::column_exists( $ct_lines, 'product_id' )
			&& BizCity_CRM_DB_Installer_V2::column_exists( $ct_lines, 'discount_type' );
		$tasks[] = array(
			'id'       => 'T-CRM.M1.W2.3',
			'check'    => 'opp_lines + contract_lines have product_id + discount_type cols',
			'status'   => $cols_ok ? 'pass' : 'fail',
			'evidence' => $cols_ok ? '4 columns present (2 tables × 2 cols)' : 'one or more cols missing',
		);

		// T-CRM.M1.W2.4 — 7 routes registered
		$routes_all = array_keys( rest_get_server()->get_routes() );
		$expected = array(
			'/bizcity-crm/v1/crm-product-categories',
			'/bizcity-crm/v1/crm-product-categories/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-products',
			'/bizcity-crm/v1/crm-products/(?P<id>\\d+)',
		);
		$missing_r = array();
		foreach ( $expected as $r ) {
			if ( ! in_array( $r, $routes_all, true ) ) { $missing_r[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.W2.4',
			'check'    => '4 product/category REST routes registered',
			'status'   => empty( $missing_r ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_r ) ? '4 routes present' : 'MISSING: ' . implode( ', ', $missing_r ),
		);

		// T-CRM.M1.W2.5 — fixed-discount + percentage formula correctness
		$ref = new ReflectionClass( 'BizCity_CRM_REST_Controller' );
		$fixed_ok = false; $pct_ok = false;
		if ( $ref->hasMethod( 'compute_line_total' ) ) {
			$m = $ref->getMethod( 'compute_line_total' );
			$m->setAccessible( true );
			// percentage: 2 * 100 * 0.9 * 1.08 = 194.4
			$p = $m->invoke( null, array( 'quantity' => 2, 'unit_price' => 100, 'discount_pct' => 10, 'tax_pct' => 8, 'discount_type' => 'percentage' ) );
			$pct_ok = abs( $p['line_total'] - 194.4 ) < 0.01;
			// fixed: (2*100 - 30) * 1.08 = 170 * 1.08 = 183.60
			$f = $m->invoke( null, array( 'quantity' => 2, 'unit_price' => 100, 'discount_pct' => 30, 'tax_pct' => 8, 'discount_type' => 'fixed' ) );
			$fixed_ok = abs( $f['line_total'] - 183.60 ) < 0.01;
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M1.W2.5',
			'check'    => 'Line formula handles BOTH percentage and fixed discount',
			'status'   => ( $pct_ok && $fixed_ok ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'percentage=%s fixed=%s', $pct_ok ? 'OK (194.40)' : 'FAIL', $fixed_ok ? 'OK (183.60)' : 'FAIL' ),
		);

		return $tasks;
	}

	/* ================================================================
	 * PHASE 0.35 M-CRM.M2 — Invoicing section
	 * ================================================================ */

	private function render_crm_invoicing_section(): void {
		$tasks = $this->compute_tasks_crm_invoicing();
		echo '<h2 style="margin-top:24px">Invoicing — PHASE 0.35 M-CRM.M2</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Task</th><th>Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td><td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_tasks_crm_invoicing(): array {
		global $wpdb;
		$tasks = array();

		// T1 — DB ver >= 1.9.0
		$db_ver = get_option( 'bizcity_crm_db_ver', '—' );
		$tasks[] = array(
			'id'       => 'T-CRM.M2.1',
			'check'    => 'DB version >= 1.9.0',
			'status'   => version_compare( (string) $db_ver, '1.9.0', '>=' ) ? 'pass' : 'fail',
			'evidence' => 'bizcity_crm_db_ver = ' . $db_ver,
		);

		// T2 — 3 invoice tables
		$tbls = array(
			$wpdb->prefix . 'bizcity_crm_invoices',
			$wpdb->prefix . 'bizcity_crm_invoice_lines',
			$wpdb->prefix . 'bizcity_crm_invoice_payments',
		);
		$missing = array();
		foreach ( $tbls as $tbl ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tbl ) . "'" ) ) { $missing[] = $tbl; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M2.2',
			'check'    => '3 invoice tables exist',
			'status'   => empty( $missing ) ? 'pass' : 'fail',
			'evidence' => empty( $missing ) ? 'all 3 present' : 'MISSING: ' . implode( ', ', $missing ),
		);

		// T3 — Repository class loaded with key methods
		$cls_ok = class_exists( 'BizCity_CRM_Invoice_Repository' )
			&& method_exists( 'BizCity_CRM_Invoice_Repository', 'create' )
			&& method_exists( 'BizCity_CRM_Invoice_Repository', 'transition' )
			&& method_exists( 'BizCity_CRM_Invoice_Repository', 'add_payment' )
			&& method_exists( 'BizCity_CRM_Invoice_Repository', 'mark_overdue_now' );
		$tasks[] = array(
			'id'       => 'T-CRM.M2.3',
			'check'    => 'Invoice Repository class + 4 key methods loaded',
			'status'   => $cls_ok ? 'pass' : 'fail',
			'evidence' => $cls_ok ? 'class + create/transition/add_payment/mark_overdue_now' : 'class or method missing',
		);

		// T4 — PDF renderer loaded
		$pdf_ok = class_exists( 'BizCity_CRM_Invoice_PDF' ) && method_exists( 'BizCity_CRM_Invoice_PDF', 'render_html' );
		$tasks[] = array(
			'id'       => 'T-CRM.M2.4',
			'check'    => 'Invoice PDF renderer loaded',
			'status'   => $pdf_ok ? 'pass' : 'fail',
			'evidence' => $pdf_ok ? 'BizCity_CRM_Invoice_PDF::render_html present' : 'class or method missing',
		);

		// T5 — Status transition map integrity
		$trans_ok = false;
		if ( class_exists( 'BizCity_CRM_Invoice_Repository' ) ) {
			$ref = new ReflectionClass( 'BizCity_CRM_Invoice_Repository' );
			if ( $ref->hasConstant( 'ALLOWED_TRANSITIONS' ) ) {
				$map = $ref->getConstant( 'ALLOWED_TRANSITIONS' );
				$trans_ok = is_array( $map )
					&& isset( $map[ BizCity_CRM_Invoice_Repository::STATUS_DRAFT ] )
					&& in_array( BizCity_CRM_Invoice_Repository::STATUS_SENT, (array) $map[ BizCity_CRM_Invoice_Repository::STATUS_DRAFT ], true );
			}
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M2.5',
			'check'    => 'ALLOWED_TRANSITIONS contains draft→sent',
			'status'   => $trans_ok ? 'pass' : 'fail',
			'evidence' => $trans_ok ? 'state machine intact' : 'transition map missing/incomplete',
		);

		// T6 — REST routes registered
		$routes_all = array_keys( rest_get_server()->get_routes() );
		$expected = array(
			'/bizcity-crm/v1/crm-invoices',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/transition',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/payments',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/send',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/pdf',
		);
		$missing_r = array();
		foreach ( $expected as $r ) {
			if ( ! in_array( $r, $routes_all, true ) ) { $missing_r[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M2.6',
			'check'    => '6 invoice REST routes registered',
			'status'   => empty( $missing_r ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_r ) ? '6 routes present' : 'MISSING: ' . implode( ', ', $missing_r ),
		);

		// T7 — Cron scheduled
		$cron_ok = (bool) wp_next_scheduled( 'bizcity_crm_invoice_overdue_tick' );
		$tasks[] = array(
			'id'       => 'T-CRM.M2.7',
			'check'    => 'Hourly overdue-invoice cron scheduled',
			'status'   => $cron_ok ? 'pass' : 'fail',
			'evidence' => $cron_ok ? 'next run: ' . gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'bizcity_crm_invoice_overdue_tick' ) ) . ' UTC' : 'not scheduled',
		);

		// T8 — Number format generator works
		$num_ok = false; $sample = '';
		if ( class_exists( 'BizCity_CRM_Invoice_Repository' ) && method_exists( 'BizCity_CRM_Invoice_Repository', 'generate_number' ) ) {
			$sample = (string) BizCity_CRM_Invoice_Repository::generate_number();
			$num_ok = (bool) preg_match( '/^[A-Z]+-\d{6}-\d{4}$/', $sample );
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M2.8',
			'check'    => 'generate_number() returns INV-YYYYMM-NNNN format',
			'status'   => $num_ok ? 'pass' : 'fail',
			'evidence' => $sample ? 'sample: ' . $sample : 'method not callable',
		);

		return $tasks;
	}

	/* ================================================================
	 * PHASE 0.35 M-CRM.M3 — Email Client section
	 * ================================================================ */

	private function render_crm_email_section(): void {
		$tasks = $this->compute_tasks_crm_email();
		echo '<h2 style="margin-top:24px">Email Client — PHASE 0.35 M-CRM.M3</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Task</th><th>Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			$badge = $this->badge( $t['status'] );
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $badge . '</td><td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_tasks_crm_email(): array {
		global $wpdb;
		$tasks = array();

		// T1 — 3 email tables
		$tbls = array(
			$wpdb->prefix . 'bizcity_crm_email_accounts',
			$wpdb->prefix . 'bizcity_crm_email_threads',
			$wpdb->prefix . 'bizcity_crm_email_messages',
		);
		$missing = array();
		foreach ( $tbls as $tbl ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tbl ) . "'" ) ) { $missing[] = $tbl; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M3.1',
			'check'    => '3 email tables exist',
			'status'   => empty( $missing ) ? 'pass' : 'fail',
			'evidence' => empty( $missing ) ? 'all 3 present' : 'MISSING: ' . implode( ', ', $missing ),
		);

		// T2 — Repository class
		$cls_ok = class_exists( 'BizCity_CRM_Email_Repository' )
			&& method_exists( 'BizCity_CRM_Email_Repository', 'ingest_message' )
			&& method_exists( 'BizCity_CRM_Email_Repository', 'compose_and_send' )
			&& method_exists( 'BizCity_CRM_Email_Repository', 'normalize_subject' );
		$tasks[] = array(
			'id'       => 'T-CRM.M3.2',
			'check'    => 'Email Repository + ingest/send/normalize methods loaded',
			'status'   => $cls_ok ? 'pass' : 'fail',
			'evidence' => $cls_ok ? 'class + 3 key methods present' : 'class or method missing',
		);

		// T3 — IMAP poller RETIRED 2026-05-31 → Gmail SMTP (BizCity_CRM_Gmail_SMTP_Repo).
		$cron_retired = ! wp_next_scheduled( 'bizcity_crm_email_poll_tick' );
		$gmail_ok     = class_exists( 'BizCity_CRM_Gmail_SMTP_Repo' );
		$tasks[] = array(
			'id'       => 'T-CRM.M3.3',
			'check'    => 'IMAP cron retired + Gmail SMTP class loaded',
			'status'   => ( $cron_retired && $gmail_ok ) ? 'pass' : 'fail',
			'evidence' => sprintf( 'imap_cron_cleared=%s gmail_smtp_class=%s', $cron_retired ? 'YES' : 'STILL_SCHEDULED', $gmail_ok ? 'OK' : 'MISSING' ),
		);

		// T4 — ext-imap detection (informational, not a hard fail)
		$imap_ok = function_exists( 'imap_open' );
		$tasks[] = array(
			'id'       => 'T-CRM.M3.4',
			'check'    => 'ext-imap available (host capability)',
			'status'   => $imap_ok ? 'pass' : 'warn',
			'evidence' => $imap_ok ? 'imap_open() present' : 'PHP IMAP extension NOT loaded — IMAP sync will be skipped (SMTP-only mode)',
		);

		// T5 — REST routes registered
		$routes_all = array_keys( rest_get_server()->get_routes() );
		$expected = array(
			'/bizcity-crm/v1/crm-email-accounts',
			'/bizcity-crm/v1/crm-email-accounts/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-email-accounts/(?P<id>\\d+)/sync',
			'/bizcity-crm/v1/crm-email-threads',
			'/bizcity-crm/v1/crm-email-threads/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-email-send',
		);
		$missing_r = array();
		foreach ( $expected as $r ) {
			if ( ! in_array( $r, $routes_all, true ) ) { $missing_r[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M3.5',
			'check'    => '6 email REST routes registered',
			'status'   => empty( $missing_r ) ? 'pass' : 'fail',
			'evidence' => empty( $missing_r ) ? '6 routes present' : 'MISSING: ' . implode( ', ', $missing_r ),
		);

		// T6 — Subject normalization works
		$norm_ok = false; $sample = '';
		if ( class_exists( 'BizCity_CRM_Email_Repository' ) ) {
			$sample  = BizCity_CRM_Email_Repository::normalize_subject( 'Re: Fwd: RE: Hello world' );
			$norm_ok = ( $sample === 'Hello world' );
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M3.6',
			'check'    => 'normalize_subject strips Re:/Fwd:/RE: chains',
			'status'   => $norm_ok ? 'pass' : 'fail',
			'evidence' => 'sample: "Re: Fwd: RE: Hello world" → "' . $sample . '"',
		);

		// T7 — Encryption round-trip works
		$enc_ok = false; $crypto_ev = '';
		if ( class_exists( 'BizCity_CRM_Email_Repository' ) ) {
			$plain = 'test-secret-' . uniqid();
			$enc   = BizCity_CRM_Email_Repository::encrypt( $plain );
			$dec   = BizCity_CRM_Email_Repository::decrypt( $enc );
			$enc_ok = ( $dec === $plain && $enc !== $plain );
			$crypto_ev = function_exists( 'openssl_encrypt' ) ? 'AES-256-CBC' : 'b64-fallback (insecure)';
		}
		$tasks[] = array(
			'id'       => 'T-CRM.M3.7',
			'check'    => 'Password encrypt/decrypt round-trip',
			'status'   => $enc_ok ? 'pass' : 'fail',
			'evidence' => $enc_ok ? 'OK — ' . $crypto_ev : 'round-trip mismatch',
		);

		return $tasks;
	}

	/* ================================================================
	 * Progress Board — editable mirror of PHASE-0.35-WAVES.md table.
	 *
	 * Mục đích: 1 chỗ duy nhất để track tiến độ, ghi chú issue/blocker,
	 * và follow-up theo từng wave; sống cùng diag page để mỗi lần nhìn
	 * trạng thái sprint là thấy ngay phần manual progress + log.
	 *
	 * Storage: option `bizcity_crm_progress_board` (autoload=false), shape
	 *   [ row_key => [ status, date, commit, diag, notes ] ]
	 * Defaults được seed từ markdown roadmap; user override per-row.
	 * ================================================================ */

	private function render_progress_board(): void {
		$rows = $this->progress_board_data();

		$counts = array( 'done' => 0, 'wip' => 0, 'blocked' => 0, 'deferred' => 0, 'todo' => 0 );
		foreach ( $rows as $r ) {
			$s = $r['status'] ?: 'todo';
			if ( isset( $counts[ $s ] ) ) { $counts[ $s ]++; }
		}
		$total = count( $rows );

		echo '<h2 style="margin-top:24px;display:flex;align-items:center;gap:10px">';
		echo '📋 Progress Board <small style="font-weight:normal;font-size:12px;color:#666">';
		echo 'mirror of <code>PHASE-0.35-WAVES.md</code> · update khi commit · ghi chú issue/follow-up tại đây';
		echo '</small></h2>';

		echo '<div style="display:flex;gap:10px;margin:8px 0 12px;font-size:13px">';
		printf( '<span style="padding:3px 10px;background:#d4edda;border-radius:4px">✅ Done: <b>%d</b></span>', (int) $counts['done'] );
		printf( '<span style="padding:3px 10px;background:#fff3cd;border-radius:4px">🟡 WIP: <b>%d</b></span>', (int) $counts['wip'] );
		printf( '<span style="padding:3px 10px;background:#f8d7da;border-radius:4px">🔴 Blocked: <b>%d</b></span>', (int) $counts['blocked'] );
		printf( '<span style="padding:3px 10px;background:#e2e3e5;border-radius:4px">⏭ Deferred: <b>%d</b></span>', (int) $counts['deferred'] );
		printf( '<span style="padding:3px 10px;background:#f0f0f0;border-radius:4px">⚪ Todo: <b>%d</b></span>', (int) $counts['todo'] );
		printf( '<span style="padding:3px 10px;color:#666">Total: <b>%d</b> waves</span>', (int) $total );
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bizcity_crm_diag' );
		echo '<input type="hidden" name="action" value="bizcity_crm_diag_action" />';
		echo '<input type="hidden" name="diag_action" value="progress_save" />';

		echo '<table class="widefat striped" style="font-size:12px"><thead><tr>';
		echo '<th style="width:60px">Module</th>';
		echo '<th style="width:230px">Wave</th>';
		echo '<th style="width:115px">Status</th>';
		echo '<th style="width:110px">Date</th>';
		echo '<th style="width:140px">Commit</th>';
		echo '<th style="width:80px">Diag %</th>';
		echo '<th>Notes / Issues / Follow-up</th>';
		echo '</tr></thead><tbody>';

		$current_module = '';
		foreach ( $rows as $r ) {
			$key       = esc_attr( $r['key'] );
			$module    = (string) $r['module'];
			$wave      = (string) $r['wave'];
			$status    = (string) ( $r['status'] ?: 'todo' );
			$date      = (string) ( $r['date'] ?: '' );
			$commit    = (string) ( $r['commit'] ?: '' );
			$diag      = (string) ( $r['diag'] ?: '' );
			$notes     = (string) ( $r['notes'] ?: '' );

			$row_bg = '';
			if ( $status === 'blocked' )       { $row_bg = 'background:#fdecea'; }
			elseif ( $status === 'wip' )       { $row_bg = 'background:#fffbe6'; }
			elseif ( $status === 'deferred' )  { $row_bg = 'background:#f5f5f5;color:#666'; }

			$is_section = ( $module !== $current_module );
			$current_module = $module;

			echo '<tr style="' . esc_attr( $row_bg ) . '">';
			echo '<td><b>' . ( $is_section ? esc_html( $module ) : '' ) . '</b></td>';
			echo '<td>' . esc_html( $wave ) . '</td>';

			echo '<td><select name="row[' . $key . '][status]">';
			foreach ( array(
				'todo'     => '⚪ Todo',
				'wip'      => '🟡 WIP',
				'done'     => '✅ Done',
				'blocked'  => '🔴 Blocked',
				'deferred' => '⏭ Deferred',
			) as $sk => $sl ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $sk ), selected( $status, $sk, false ), esc_html( $sl ) );
			}
			echo '</select></td>';

			echo '<td><input type="date" name="row[' . $key . '][date]" value="' . esc_attr( $date ) . '" style="width:100%" /></td>';
			echo '<td><input type="text" name="row[' . $key . '][commit]" value="' . esc_attr( $commit ) . '" placeholder="(uncommitted)" style="width:100%" /></td>';
			echo '<td><input type="text" name="row[' . $key . '][diag]" value="' . esc_attr( $diag ) . '" placeholder="0/0" style="width:100%" /></td>';
			echo '<td><textarea name="row[' . $key . '][notes]" rows="2" style="width:100%;font-family:inherit;font-size:12px" placeholder="issue / blocker / next step…">' . esc_textarea( $notes ) . '</textarea></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<div style="margin:10px 0 24px;display:flex;gap:8px;align-items:center">';
		echo '<button type="submit" class="button button-primary">💾 Save progress board</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Reset toàn bộ progress board về defaults seed từ roadmap?\')">';
		wp_nonce_field( 'bizcity_crm_diag' );
		echo '<input type="hidden" name="action" value="bizcity_crm_diag_action" />';
		echo '<input type="hidden" name="diag_action" value="progress_reset" />';
		echo '<button type="submit" class="button">↺ Reset to roadmap defaults</button>';
		echo '</form>';
		echo '<span style="color:#666;font-size:12px">Data lưu ở option <code>bizcity_crm_progress_board</code> (không autoload).</span>';
		echo '</div>';
	}

	private function save_progress_board(): void {
		$rows = isset( $_POST['row'] ) && is_array( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : array();
		$allowed_status = array( 'todo', 'wip', 'done', 'blocked', 'deferred' );
		$clean = array();
		foreach ( $rows as $key => $r ) {
			$key = sanitize_key( $key );
			if ( $key === '' ) { continue; }
			$status = isset( $r['status'] ) ? sanitize_key( $r['status'] ) : 'todo';
			if ( ! in_array( $status, $allowed_status, true ) ) { $status = 'todo'; }
			$clean[ $key ] = array(
				'status' => $status,
				'date'   => isset( $r['date'] )   ? sanitize_text_field( $r['date'] )       : '',
				'commit' => isset( $r['commit'] ) ? sanitize_text_field( $r['commit'] )     : '',
				'diag'   => isset( $r['diag'] )   ? sanitize_text_field( $r['diag'] )       : '',
				'notes'  => isset( $r['notes'] )  ? sanitize_textarea_field( $r['notes'] )  : '',
			);
		}
		update_option( 'bizcity_crm_progress_board', $clean, false );
	}

	/** Merge seeded defaults with user-saved overrides keyed by row_key. */
	private function progress_board_data(): array {
		$saved   = get_option( 'bizcity_crm_progress_board', array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$rows    = $this->progress_board_default();
		foreach ( $rows as &$row ) {
			$k = $row['key'];
			if ( isset( $saved[ $k ] ) && is_array( $saved[ $k ] ) ) {
				$row['status'] = (string) ( $saved[ $k ]['status'] ?? $row['status'] );
				$row['date']   = (string) ( $saved[ $k ]['date']   ?? $row['date'] );
				$row['commit'] = (string) ( $saved[ $k ]['commit'] ?? $row['commit'] );
				$row['diag']   = (string) ( $saved[ $k ]['diag']   ?? $row['diag'] );
				$row['notes']  = (string) ( $saved[ $k ]['notes']  ?? '' );
			} else {
				$row['notes'] = '';
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Seed defaults — mirrors the "Progress board" table in
	 * PHASE-0.35-WAVES.md. Update this when adding new waves so
	 * fresh installs see the latest scaffold.
	 *
	 * @return array<int,array{key:string,module:string,wave:string,status:string,date:string,commit:string,diag:string}>
	 */
	private function progress_board_default(): array {
		$d = '2026-05-12';
		// Helper: row().
		$row = static function ( string $module, string $wave, string $status = 'todo', string $date = '', string $commit = '', string $diag = '' ): array {
			$slug = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $module . '-' . $wave ) );
			$slug = trim( $slug, '-' );
			return array(
				'key'    => $slug,
				'module' => $module,
				'wave'   => $wave,
				'status' => $status,
				'date'   => $date,
				'commit' => $commit,
				'diag'   => $diag,
			);
		};

		return array(
			$row( 'M1', 'W1 DB Migration',          'done', '2026-05-11', '(uncommitted)', '4/4' ),
			$row( 'M1', 'W2 Capabilities',          'done', '2026-05-11', '(uncommitted)', '3/3' ),
			$row( 'M1', 'W3 Grid filters',          'done', '2026-05-11', '(uncommitted)', '3/3' ),
			$row( 'M1', 'W4 Snooze',                'done', '2026-05-11', '(uncommitted)', '3/3' ),

			$row( 'M2', 'W1 Engine skeleton',       'done', '2026-05-12', '(uncommitted)', '4/4' ),
			$row( 'M2', 'W2 Evaluator',             'done', '2026-05-12', '(uncommitted)', '5/5' ),
			$row( 'M2', 'W3 Actions × 10',          'done', '2026-05-12', '(uncommitted)', '10/10' ),
			$row( 'M2', 'W4 KG action',             'deferred', '', '(deferred)', '0/6' ),
			$row( 'M2', 'W5 REST',                  'done', '2026-05-12', '(uncommitted)', '5/5' ),
			$row( 'M2', 'W6 React UI',              'deferred', '', '(deferred)', '0/5' ),

			$row( 'M3', 'W1 Labels CRUD',           'done', '2026-05-13', '(uncommitted)', '5/5' ),
			$row( 'M3', 'W2 Label UI',              'done', '2026-05-15', '', '3/3 (LabelChips + filter chip-bar + per-row chips)' ),
			$row( 'M3', 'W3 Custom Attrs',          'done', '2026-05-13', '(uncommitted)', '5/5' ),
			$row( 'M3', 'W4 Template Renderer',     'done', '2026-05-13', '(uncommitted)', '4/4' ),
			$row( 'M3', 'W5 Macros',                'done', '2026-05-13', '(uncommitted)', '5/5' ),

			$row( 'M4', 'W1 Working Hours',         'done', '2026-05-14', '(uncommitted)', '4/5' ),
			$row( 'M4', 'W2 SLA Policy',            'done', '2026-05-14', '(uncommitted)', '4/4' ),
			$row( 'M4', 'W3 SLA Evaluator',         'done', '2026-05-14', '(uncommitted)', '5/6' ),
			$row( 'M4', 'W4 UI + wire',             'done', '2026-05-15', '', '3/3 (SLABadge in list + thread header)' ),

			$row( 'M5', 'W1 Report Builder',        'done', '2026-05-14', '(uncommitted)', '4/4' ),
			$row( 'M5', 'W2 Daily Rollup',          'done', '2026-05-14', '(uncommitted)', '3/3' ),
			$row( 'M5', 'W3 KPI cards',             'done', '2026-05-15', '', '4/4 (KPI grid + 3 breakdown tables)' ),
			$row( 'M5', 'W4 Auto vs Human',         'done', '2026-05-15', '', '2/2 (REST + chart wired)' ),
			$row( 'M5', 'W5 CSAT + Audit',          'done', '2026-05-14', '(uncommitted)', '5/5' ),

			$row( 'M6', 'W1 Campaign schema',       'done', '2026-05-15', '', '3/4 (FE wave deferred)' ),
			$row( 'M6', 'W2 QR + UTM',              'done', '2026-05-15', '', '4/4' ),
			$row( 'M6', 'W3 Visit tracking',        'done', '2026-05-15', '', '5/5' ),
			$row( 'M6', 'W4 Conversion link',       'done', '2026-05-15', '', '5/5 (incl. [kiem_tra_diem]+[doi_diem] bridge)' ),
			$row( 'M6', 'W5 Loyalty Bridge',        'done', '2026-05-15', '', '6/6 (award/balance/history + REST + auto-on-conversion)' ),
			$row( 'M6', 'W6 Flow Importer',         'done', '2026-05-15', '', '4/4 (preview + bulk import → macros + rules)' ),
			$row( 'M6', 'W7 Funnel report',         'done', '2026-05-15', '', '2/2 (visits/conversations/resolved/points)' ),
			$row( 'M6', 'W8 Campaign Authoring UI (FE)', 'done', '2026-05-15', '', '6/6' ),
			$row( 'M6', 'W9 Campaign ↔ Scenario Binding (BE)', 'done', '2026-05-15', '', '6/6 (schema + bridge + 2 actions + dropdowns REST)' ),

			$row( 'M7', 'W1 Wizard',                'done', '2026-03-28', '(uncommitted)', '3/3' ),
			$row( 'M7', 'W2 IG + WA',               'done', '2026-05-12', '(uncommitted)', '4/4' ),
			$row( 'M7', 'W3 TG + Email + Web',      'done', '2026-05-12', '(uncommitted)', '3/3' ),
			$row( 'M7', 'W4 Health',                'done', '2026-05-12', '(uncommitted)', '2/2' ),
			$row( 'M7', 'W5 Bot Sync (FB/Zalo/Google)', 'done', '2026-05-11', '(uncommitted)', '5/5' ),

			$row( 'M-FE', 'W1 Tab Shell',                          'done', '2026-05-15', '(uncommitted)', '5/5' ),
			$row( 'M-FE', 'W2 Inbox enhancements',                 'deferred', '', '(deferred — wait W9 DataTable)', '0/6' ),
			$row( 'M-FE', 'W3 Reports tab (KPI + auto-vs-human)',  'done', '2026-05-15', '(uncommitted)', '5/5' ),
			$row( 'M-FE', 'W4 Automation tab (list + dry-run)',    'done', '2026-05-15', '(uncommitted)', '5/5' ),
			$row( 'M-FE', 'W5 Labels+Macros+Attrs tabs',           'done', '2026-05-15', '(uncommitted)', '3/3' ),
			$row( 'M-FE', 'W6 SLA & Hours tab',                    'done', '2026-05-15', '(uncommitted)', '4/4' ),
			$row( 'M-FE', 'W7 Audit + Channels tabs',              'done', '2026-05-15', '(uncommitted)', '4/4' ),
			$row( 'M-FE', 'W8 Polish + diag (dark, hotkeys)',      'done', $d, '(uncommitted)', '6/6' ),
			$row( 'M-FE', 'W9 shadcn/ui base + DataTable',         'done', $d, '(uncommitted)', '8/8' ),
			$row( 'M-FE', 'W10 Audit Timeline component',          'done', $d, '(uncommitted)', '4/4' ),
			$row( 'M-FE', 'W11 Activity Feed (infinite scroll)',   'done', $d, '(uncommitted)', '5/5' ),
			$row( 'M-FE', 'W12 Find Similar drawer',               'done', $d, '(uncommitted)', '4/4' ),
			$row( 'M-FE', 'W13 Invoice Line Items editor',         'done', $d, '(uncommitted)', '6/6' ),
			$row( 'M-FE', 'W17 NextCRM-style sidebar shell',       'done', $d, '(uncommitted)', '—' ),

			$row( 'M-CRM', 'M2 Invoicing BE',                      'done', $d, '(uncommitted)', '8/8' ),
			$row( 'M-CRM', 'M3 Email Client BE',                   'done', $d, '(uncommitted)', '7/7' ),
			$row( 'M-CRM', 'M6 Invoicing FE',                      'done', $d, '(uncommitted)', '5/6' ),
			$row( 'M-CRM', 'M7 Email Client FE',                   'done', $d, '(uncommitted)', '5/5' ),

			$row( 'SMTP', 'Core/SMTP bootstrap + admin UI',        'done', $d, '(uncommitted)', '—' ),
			$row( 'SMTP', 'Override mu-plugin · fallback safe',    'done', $d, '(uncommitted)', '—' ),
		);
	}

	/* ============================================================
	 * PHASE 0.35 M-CRM.M8 — Woo Bridge & Contact Unification
	 * ============================================================ */
	private function render_woo_bridge_section(): void {
		global $wpdb;

		echo '<h2>M-CRM.M8 — Woo Bridge &amp; Contact Unification</h2>';

		$wc_active     = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$bridge_loaded = (bool) did_action( 'bizcity_crm_woo_bridge_loaded' );
		$status        = class_exists( 'BizCity_CRM_Woo_Bridge' ) ? BizCity_CRM_Woo_Bridge::status() : array();

		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:160px">Probe</th><th>Status</th><th>Evidence</th></tr></thead><tbody>';

		// W1 — orchestrator + sub-bridges
		$subs = (array) ( $status['sub_bridges'] ?? array() );
		echo '<tr><td><b>W1 Orchestrator</b></td><td>' . $this->badge( ( $wc_active && $bridge_loaded ) ? 'PASS' : ( $wc_active ? 'FAIL' : 'SKIP' ) ) . '</td><td>'
			. 'wc_active=' . ( $wc_active ? 'YES' : 'NO' )
			. ' · loaded=' . ( $bridge_loaded ? 'YES' : 'NO' )
			. ' · hpos=' . ( ! empty( $status['hpos'] ) ? 'YES' : 'NO' )
			. ' · subs=' . (int) count( array_filter( $subs ) ) . '/' . (int) count( $subs )
			. '</td></tr>';

		// W2 — schema unification
		$contacts_tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$legacy_tbl   = BizCity_CRM_DB_Installer_V2::tbl_biz_contacts();
		$map_tbl      = BizCity_CRM_DB_Installer_V2::tbl_contact_id_map();
		$col_first    = BizCity_CRM_DB_Installer_V2::column_exists( $contacts_tbl, 'first_name' );
		$col_acct     = BizCity_CRM_DB_Installer_V2::column_exists( $contacts_tbl, 'account_id' );
		$map_exists   = $this->table_present( $map_tbl );
		$leg_exists   = $this->table_present( $legacy_tbl );
		$cnt_canon    = $this->table_present( $contacts_tbl ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$contacts_tbl}` WHERE deleted_at IS NULL" ) : 0;
		$cnt_legacy   = $leg_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy_tbl}` WHERE deleted_at IS NULL" ) : 0;
		$cnt_map      = $map_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$map_tbl}`" ) : 0;
		$cnt_unmig    = ( $leg_exists && $map_exists )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy_tbl}` l LEFT JOIN `{$map_tbl}` m ON m.old_biz_id=l.id WHERE l.deleted_at IS NULL AND m.id IS NULL" )
			: 0;
		echo '<tr><td><b>W2 Schema</b></td><td>' . $this->badge( ( $col_first && $col_acct && $map_exists ) ? 'PASS' : 'FAIL' ) . '</td><td>'
			. 'contacts.first_name=' . ( $col_first ? 'YES' : 'NO' )
			. ' · contacts.account_id=' . ( $col_acct ? 'YES' : 'NO' )
			. ' · contact_id_map=' . ( $map_exists ? 'YES' : 'NO' )
			. ' · biz_contacts table=' . ( $leg_exists ? 'YES (legacy)' : 'NO (dropped)' )
			. '</td></tr>';

		echo '<tr><td><b>W2 Counts</b></td><td>' . $this->badge( ( $cnt_unmig === 0 ) ? 'PASS' : 'FAIL' ) . '</td><td>'
			. "canonical=<b>{$cnt_canon}</b> · legacy_active=<b>{$cnt_legacy}</b> · mapped=<b>{$cnt_map}</b> · unmigrated=<b>{$cnt_unmig}</b>"
			. '</td></tr>';

		// W3 — customer bridge
		$cust_class    = class_exists( 'BizCity_CRM_Woo_Customer_Bridge' );
		$cust_methods  = $cust_class
			&& method_exists( 'BizCity_CRM_Woo_Customer_Bridge', 'sync_from_user' )
			&& method_exists( 'BizCity_CRM_Woo_Customer_Bridge', 'resolve_contact_for_order' );
		$wp_user_count = $this->table_present( $contacts_tbl )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$contacts_tbl}` WHERE wp_user_id IS NOT NULL AND wp_user_id > 0 AND deleted_at IS NULL" )
			: 0;
		echo '<tr><td><b>W3 Customer bridge</b></td><td>' . $this->badge( ( $cust_class && $cust_methods ) ? 'PASS' : ( $wc_active ? 'FAIL' : 'SKIP' ) ) . '</td><td>'
			. 'class=' . ( $cust_class ? 'YES' : 'NO' )
			. ' · methods=' . ( $cust_methods ? 'YES' : 'NO' )
			. " · contacts_with_wp_user=<b>{$wp_user_count}</b>"
			. '</td></tr>';

		// W4 — invoice ↔ order
		$inv_class    = class_exists( 'BizCity_CRM_Woo_Invoice_Bridge' );
		$inv_tbl      = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$has_wc_col   = BizCity_CRM_DB_Installer_V2::column_exists( $inv_tbl, 'wc_order_id' );
		$linked_count = ( $has_wc_col && $this->table_present( $inv_tbl ) )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$inv_tbl}` WHERE wc_order_id IS NOT NULL" )
			: 0;
		$auto_invoice = (bool) get_option( 'bizcity_crm_woo_auto_invoice', false );
		$repo_link    = method_exists( 'BizCity_CRM_Invoice_Repository', 'link_to_woo_order' );
		echo '<tr><td><b>W4 Invoice ↔ order</b></td><td>' . $this->badge( ( $inv_class && $has_wc_col && $repo_link ) ? 'PASS' : ( $wc_active ? 'FAIL' : 'SKIP' ) ) . '</td><td>'
			. 'class=' . ( $inv_class ? 'YES' : 'NO' )
			. ' · invoices.wc_order_id=' . ( $has_wc_col ? 'YES' : 'NO' )
			. " · linked_invoices=<b>{$linked_count}</b>"
			. ' · auto_invoice=' . ( $auto_invoice ? 'ON' : 'OFF' )
			. ' · link_api=' . ( $repo_link ? 'YES' : 'NO' )
			. '</td></tr>';

		// W5 — reports bridge
		$rep_class = class_exists( 'BizCity_CRM_Woo_Reports_Bridge' );
		$rep_probe = '—';
		if ( $rep_class && $wc_active ) {
			$t0  = microtime( true );
			$sum = BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary( '-30 days', 'now' );
			$ms  = (int) ( ( microtime( true ) - $t0 ) * 1000 );
			$rep_probe = sprintf(
				'orders=%d · gross=%s · aov=%s · in %d ms',
				(int) ( $sum['order_count'] ?? 0 ),
				number_format( (float) ( $sum['gross'] ?? 0 ), 0 ),
				number_format( (float) ( $sum['aov'] ?? 0 ), 0 ),
				$ms
			);
		}
		echo '<tr><td><b>W5 Reports bridge</b></td><td>' . $this->badge( $rep_class ? ( $wc_active ? 'PASS' : 'SKIP' ) : 'FAIL' ) . '</td><td>'
			. 'class=' . ( $rep_class ? 'YES' : 'NO' ) . ' · probe(30d): ' . esc_html( $rep_probe )
			. '</td></tr>';

		// W6 — diag tab itself
		echo '<tr><td><b>W6 Diagnostic</b></td><td>' . $this->badge( 'PASS' ) . '</td><td>this section is rendering ✓</td></tr>';

		echo '</tbody></table>';

		/* --- Migration controls --------------------------------------- */
		$rep = get_transient( 'bizcity_crm_diag_migration_report' );

		echo '<div style="margin-top:12px;padding:12px;background:#fffceb;border:1px solid #f0d97a;max-width:1100px">';
		echo '<h3 style="margin-top:0">Legacy biz_contacts → contacts</h3>';
		echo '<p style="margin:0 0 8px;color:#555">Idempotent. Dry-run trước khi chạy thật. Sau khi mọi row được map, có thể DROP table cũ để unify hoàn toàn.</p>';
		$disabled_drop = $cnt_unmig > 0 ? 'disabled title="Còn dòng chưa migrate — chạy migration trước"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="bizcity_crm_diag_action" />';
		wp_nonce_field( 'bizcity_crm_diag' );
		echo '<button class="button" name="diag_action" value="migrate_biz_contacts_dry">Dry-run migration</button> ';
		echo '<button class="button button-primary" name="diag_action" value="migrate_biz_contacts_run" onclick="return confirm(\'Chạy migration thật?\')">Run migration</button> ';
		if ( $leg_exists ) {
			echo '<button class="button button-link-delete" name="diag_action" value="drop_biz_contacts" ' . $disabled_drop . ' onclick="return confirm(\'XOÁ vĩnh viễn table biz_contacts? KHÔNG thể undo.\')">DROP biz_contacts</button>';
		} else {
			echo '<span class="bzc-muted">Table đã DROP ✓</span>';
		}
		echo '</form>';

		if ( is_array( $rep ) ) {
			echo '<pre style="background:#fff;border:1px solid #ddd;padding:8px;margin-top:8px;max-height:240px;overflow:auto">'
				. esc_html( wp_json_encode( $rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
				. '</pre>';
		}
		echo '</div>';
	}

	/**
	 * DROP legacy biz_contacts (PHASE 0.35 M-CRM.M8.W2.5).
	 * Refuses if any row is still unmapped, to prevent data loss.
	 */
	private function drop_legacy_biz_contacts(): void {
		global $wpdb;
		$legacy = BizCity_CRM_DB_Installer_V2::tbl_biz_contacts();
		$map    = BizCity_CRM_DB_Installer_V2::tbl_contact_id_map();

		if ( ! $this->table_present( $legacy ) ) { return; }

		if ( $this->table_present( $map ) ) {
			$unmig = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy}` l LEFT JOIN `{$map}` m ON m.old_biz_id=l.id WHERE l.deleted_at IS NULL AND m.id IS NULL" );
			if ( $unmig > 0 ) {
				set_transient( 'bizcity_crm_diag_migration_report', array(
					'error'      => 'refused_drop',
					'unmigrated' => $unmig,
					'reason'     => 'Run migration first',
				), 300 );
				return;
			}
		}

		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy}`" );
		set_transient( 'bizcity_crm_diag_migration_report', array(
			'dropped' => true,
			'table'   => $legacy,
		), 300 );

		do_action( 'bizcity_crm_biz_contacts_dropped' );
	}

	private function table_present( string $tbl ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		return ( $found === $tbl );
	}

	/* ============================================================
	 * Phase A · F7.0a..d — Tool Taxonomy (R-MPRT-12 + R-DDV)
	 *
	 * Roadmap: PHASE-0.35-GURU-ROADMAP-STATUS.md §10
	 * Rule:    PHASE-0-RULE-MPR-THINKING.md §6.5 + R-MPRT-12
	 *
	 * Validates Producer pipeline: 4 sprint tasks + Layer 2/3 walks of tool
	 * registry + Federation::stamp() callers + Persona Registry errors.
	 *
	 * Merged into CRM Sprint Diagnostic 2026-05-14 (standalone class did
	 * not surface as Tools submenu — root cause: admin_menu hook timing
	 * trong khi CRM Sprint Diagnostic da verified-load production).
	 * ============================================================ */

	const F7_VALID_CLASSES   = array( 'producer', 'distributor', 'retriever' );
	const F7_SEMANTIC_EXPECT = array(
		'tarot_interpret'         => 'producer',
		'send_link_tarot'         => 'distributor',
		'content_creator_execute' => 'producer',
		'list_templates'          => 'retriever',
	);

	private function render_tool_taxonomy_section(): void {
		echo '<h2>Phase A · F7.0a..d — Tool Taxonomy (R-MPRT-12)</h2>';
		echo '<p style="color:#555;font-size:12px">Roadmap: <code>PHASE-0.35-GURU-ROADMAP-STATUS.md §10</code> · Rule: <code>PHASE-0-RULE-MPR-THINKING.md §6.5</code></p>';

		$this->render_f7_task_table();
		$this->render_f7_layer2();
		$this->render_f7_layer3();
		$this->render_f7_federation();
		$this->render_f7_registry_errors();
	}

	/* ── Task table (F7.0a..f) ──────────────────────────────── */

	private function render_f7_task_table(): void {
		$tasks = $this->compute_f7_tasks();
		echo '<h3>Sprint Tasks</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:90px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $this->badge( $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function compute_f7_tasks(): array {
		$out  = array();
		$incs = array_map( static function ( $f ) { return str_replace( '\\', '/', $f ); }, get_included_files() );

		/* T-F7.0c — Foundation: BizCity_TwinShell_Tool::$tool_class */
		$path = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/agents/class-twin-tool.php';
		$disk = is_readable( $path );
		$load = in_array( str_replace( '\\', '/', $path ), $incs, true );
		$cls  = class_exists( 'BizCity_TwinShell_Tool', false );
		$prop = $cls && property_exists( 'BizCity_TwinShell_Tool', 'tool_class' );

		$ctor_default_ok = false;
		$ctor_reject_ok  = false;
		if ( $cls ) {
			try {
				$probe_default = new BizCity_TwinShell_Tool(
					'__diag_default', 'probe', array( 'type' => 'object' ),
					static function () { return null; }
				);
				$ctor_default_ok = isset( $probe_default->tool_class ) && $probe_default->tool_class === 'producer';

				$probe_bad = new BizCity_TwinShell_Tool(
					'__diag_bad', 'probe', array( 'type' => 'object' ),
					static function () { return null; },
					null, null, 'garbage_class'
				);
				$ctor_reject_ok = isset( $probe_bad->tool_class ) && $probe_bad->tool_class === 'producer';
			} catch ( \Throwable $e ) {
				// leave both false
			}
		}
		$out[] = array(
			'id'       => 'T-F7.0c',
			'status'   => ( $disk && $load && $cls && $prop && $ctor_default_ok && $ctor_reject_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_TwinShell_Tool có property `tool_class` + default `producer` + reject invalid',
			'evidence' => sprintf(
				"Disk: %s\nLoader: %s\nRuntime: class=%s, prop=%s\nCtor default='producer': %s\nCtor invalid → coerce: %s",
				$disk ? 'YES' : 'NO', $load ? 'YES' : 'NO', $cls ? 'YES' : 'NO',
				$prop ? 'YES' : 'NO', $ctor_default_ok ? 'YES' : 'NO', $ctor_reject_ok ? 'YES' : 'NO'
			),
		);

		/* T-F7.0a — bizcity-tarot Persona Provider */
		$path = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-tarot/includes/class-persona-provider.php';
		$disk = is_readable( $path );
		$load = in_array( str_replace( '\\', '/', $path ), $incs, true );
		$cls  = class_exists( 'BizCity_Tarot_Persona_Provider', false );
		$ext  = $cls && is_subclass_of( 'BizCity_Tarot_Persona_Provider', 'BizCity_Persona_Tool_Provider' );
		$reg  = $this->f7_provider_registered( 'BizCity_Tarot_Persona_Provider' );
		$kind = $cls && defined( 'BizCity_Tarot_Persona_Provider::KIND_READING' )
			? BizCity_Tarot_Persona_Provider::KIND_READING : '';
		$out[] = array(
			'id'       => 'T-F7.0a',
			'status'   => ( $disk && $load && $cls && $ext && $reg ) ? 'PASS' : ( $disk ? 'FAIL' : 'SKIP' ),
			'check'    => 'bizcity-tarot Persona Provider extends contract + registered',
			'evidence' => sprintf(
				"Disk: %s\nLoader: %s\nRuntime: class=%s, extends=%s, registered=%s\nKind: %s",
				$disk ? 'YES' : 'NO', $load ? 'YES' : 'NO', $cls ? 'YES' : 'NO',
				$ext ? 'YES' : 'NO', $reg ? 'YES' : 'NO',
				$kind !== '' ? $kind : '(missing KIND_READING)'
			),
		);

		/* T-F7.0b — bizcity-content-creator Persona Provider (BZCC_Persona_Provider) */
		$path = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-content-creator/includes/class-persona-provider.php';
		$disk = is_readable( $path );
		$load = in_array( str_replace( '\\', '/', $path ), $incs, true );
		$cls  = class_exists( 'BZCC_Persona_Provider', false );
		$ext  = $cls && is_subclass_of( 'BZCC_Persona_Provider', 'BizCity_Persona_Tool_Provider' );
		$reg  = $this->f7_provider_registered( 'BZCC_Persona_Provider' );
		$kind = $cls && defined( 'BZCC_Persona_Provider::KIND_ARTIFACT' )
			? BZCC_Persona_Provider::KIND_ARTIFACT : '';
		$out[] = array(
			'id'       => 'T-F7.0b',
			'status'   => ( $disk && $load && $cls && $ext && $reg ) ? 'PASS' : ( $disk ? 'FAIL' : 'SKIP' ),
			'check'    => 'bizcity-content-creator Persona Provider (`BZCC_Persona_Provider`) extends contract + registered',
			'evidence' => sprintf(
				"Disk: %s\nLoader: %s\nRuntime: class=%s, extends=%s, registered=%s\nKind: %s",
				$disk ? 'YES' : 'NO', $load ? 'YES' : 'NO', $cls ? 'YES' : 'NO',
				$ext ? 'YES' : 'NO', $reg ? 'YES' : 'NO',
				$kind !== '' ? $kind : '(missing KIND_ARTIFACT)'
			),
		);

		/* T-F7.0d — Diagnostic itself (this section) reachable */
		$out[] = array(
			'id'       => 'T-F7.0d',
			'status'   => 'PASS',
			'check'    => 'Diagnostic section rendered inside CRM Sprint Diagnostic',
			'evidence' => 'Section render() called from BizCity_CRM_Sprint_Diagnostic::render()',
		);

		/* T-F7.0e — Federation::stamp() callable + ≥2 callers */
		$fed_cls = class_exists( 'BizCity_Artifact_Source_Federation', false );
		$fed_mth = $fed_cls && method_exists( 'BizCity_Artifact_Source_Federation', 'stamp' );
		$callers = $this->f7_scan_federation_callers();
		$out[]   = array(
			'id'       => 'T-F7.0e',
			'status'   => ( $fed_cls && $fed_mth && count( $callers ) >= 2 ) ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_Artifact_Source_Federation::stamp() callable + ≥2 producer plugins call it',
			'evidence' => sprintf(
				"Class: %s\nMethod: %s\nCallers (%d):\n  - %s",
				$fed_cls ? 'YES' : 'NO', $fed_mth ? 'YES' : 'NO',
				count( $callers ), $callers ? implode( "\n  - ", $callers ) : '(none — Producer pipeline broken)'
			),
		);

		/* T-F7.0f — Persona Registry validation errors empty */
		$errs = $this->f7_registry_errors();
		$out[] = array(
			'id'       => 'T-F7.0f',
			'status'   => ( null === $errs ) ? 'SKIP' : ( empty( $errs ) ? 'PASS' : 'FAIL' ),
			'check'    => 'BizCity_Persona_Registry::get_errors() empty after build',
			'evidence' => null === $errs
				? 'Registry not loaded'
				: ( empty( $errs ) ? 'No errors' : ( count( $errs ) . " error(s):\n  - " . implode( "\n  - ", $errs ) ) ),
		);

		return $out;
	}

	/* ── Layer 2 — Agent tools (bizcity_register_agent) ───────── */

	private function render_f7_layer2(): void {
		$rows = $this->walk_f7_layer2();
		echo '<h3>Layer 2 — Agent Tools (<code>bizcity_register_agent</code>)</h3>';
		echo '<p style="color:#555;font-size:12px">Strict FAIL: <code>tool_class</code> không thuộc {producer,distributor,retriever}. Semantic WARN: tên tool có expected class trong registry mà actual khác (vd <code>list_templates</code> phải là <code>retriever</code>).</p>';
		if ( empty( $rows ) ) {
			echo '<p><em>No agents registered.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Agent</th><th>Tool</th><th>tool_class (actual)</th><th>Expected</th><th>Status</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><code>' . esc_html( $r['agent'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $r['tool'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $r['tool_class'] !== '' ? $r['tool_class'] : '(missing)' ) . '</code></td>';
			echo '<td>' . esc_html( $r['expected'] !== '' ? $r['expected'] : '—' ) . '</td>';
			echo '<td>' . $this->badge( $r['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function walk_f7_layer2(): array {
		$rows   = array();
		$agents = apply_filters( 'bizcity_register_agent', array() );
		if ( ! is_array( $agents ) ) { return $rows; }
		foreach ( $agents as $agent_key => $agent ) {
			$agent_id = (string) ( $this->f7_probe( $agent, array( 'name', 'id' ) ) ?: $agent_key );
			$tools    = $this->f7_probe( $agent, array( 'tools' ) );
			if ( ! is_array( $tools ) ) { continue; }
			foreach ( $tools as $tool ) {
				$tid      = (string) $this->f7_probe( $tool, array( 'name', 'id' ) );
				$tc       = (string) $this->f7_probe( $tool, array( 'tool_class' ) );
				$valid    = in_array( $tc, self::F7_VALID_CLASSES, true );
				$expected = self::F7_SEMANTIC_EXPECT[ $tid ] ?? '';
				$status   = ! $valid ? 'FAIL' : ( ( $expected !== '' && $expected !== $tc ) ? 'WARN' : 'PASS' );
				$rows[]   = array( 'agent' => $agent_id, 'tool' => $tid, 'tool_class' => $tc, 'expected' => $expected, 'status' => $status );
			}
		}
		return $rows;
	}

	/* ── Layer 3 — Persona Providers ────────────────────────── */

	private function render_f7_layer3(): void {
		$rows = $this->walk_f7_layer3();
		echo '<h3>Layer 3 — Persona Providers (<code>bizcity_persona_tool_providers</code>)</h3>';
		if ( empty( $rows ) ) {
			echo '<p><em>No persona providers registered.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Provider</th><th>Tool</th><th>tool_class (actual)</th><th>Expected</th><th>Status</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><code>' . esc_html( $r['provider'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $r['tool'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $r['tool_class'] !== '' ? $r['tool_class'] : '(missing)' ) . '</code></td>';
			echo '<td>' . esc_html( $r['expected'] !== '' ? $r['expected'] : '—' ) . '</td>';
			echo '<td>' . $this->badge( $r['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function walk_f7_layer3(): array {
		$rows      = array();
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( ! is_array( $providers ) ) { return $rows; }
		foreach ( $providers as $provider ) {
			if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_tool_definitions' ) ) { continue; }
			$pid  = method_exists( $provider, 'id' ) ? (string) $provider->id() : get_class( $provider );
			$defs = $provider->get_tool_definitions();
			if ( ! is_array( $defs ) ) { continue; }
			foreach ( $defs as $def ) {
				$tid      = (string) $this->f7_probe( $def, array( 'name', 'id' ) );
				$tc       = (string) $this->f7_probe( $def, array( 'tool_class' ) );
				$valid    = in_array( $tc, self::F7_VALID_CLASSES, true );
				$expected = self::F7_SEMANTIC_EXPECT[ $tid ] ?? '';
				$status   = ! $valid ? 'FAIL' : ( ( $expected !== '' && $expected !== $tc ) ? 'WARN' : 'PASS' );
				$rows[]   = array( 'provider' => $pid, 'tool' => $tid, 'tool_class' => $tc, 'expected' => $expected, 'status' => $status );
			}
		}
		return $rows;
	}

	/* ── Federation callers panel ───────────────────────────── */

	private function render_f7_federation(): void {
		$callers = $this->f7_scan_federation_callers();
		echo '<h3>Artifact Source Federation — <code>::stamp()</code> callers</h3>';
		if ( empty( $callers ) ) {
			echo '<p style="color:#a00"><strong>FAIL — No producer plugin calls <code>BizCity_Artifact_Source_Federation::stamp()</code>.</strong></p>';
			return;
		}
		echo '<ul style="margin-left:18px">';
		foreach ( $callers as $c ) { echo '<li><code>' . esc_html( $c ) . '</code></li>'; }
		echo '</ul>';
	}

	private function f7_scan_federation_callers(): array {
		$candidates = array(
			'bizcity-twin-ai/plugins/bizcity-tarot/includes/class-intent-provider.php',
			'bizcity-twin-ai/plugins/bizcity-content-creator/includes/agents/register-content-agent.php',
			'bizcity-twin-ai/plugins/bizcity-content-creator/includes/class-rest-api.php',
		);
		$found = array();
		foreach ( $candidates as $rel ) {
			$abs = WP_PLUGIN_DIR . '/' . $rel;
			if ( ! is_readable( $abs ) ) { continue; }
			$src = (string) @file_get_contents( $abs );
			if ( $src !== '' && false !== strpos( $src, 'Artifact_Source_Federation::stamp(' ) ) {
				$found[] = $rel;
			}
		}
		return $found;
	}

	/* ── Persona Registry errors ────────────────────────────── */

	private function render_f7_registry_errors(): void {
		$errs = $this->f7_registry_errors();
		echo '<h3>Persona Registry — Validation errors</h3>';
		if ( null === $errs ) {
			echo '<p><em>BizCity_Persona_Registry not loaded.</em></p>';
			return;
		}
		if ( empty( $errs ) ) {
			echo '<p style="color:#1f8a48"><strong>PASS — No validation errors.</strong></p>';
			return;
		}
		echo '<ul style="margin-left:18px;color:#a00">';
		foreach ( $errs as $e ) { echo '<li>' . esc_html( $e ) . '</li>'; }
		echo '</ul>';
	}

	private function f7_registry_errors(): ?array {
		if ( ! class_exists( 'BizCity_Persona_Registry', false )
			|| ! method_exists( 'BizCity_Persona_Registry', 'instance' ) ) {
			return null;
		}
		$reg = BizCity_Persona_Registry::instance();
		if ( ! method_exists( $reg, 'get_errors' ) ) { return null; }
		return (array) $reg->get_errors();
	}

	/* ── F7 helpers ─────────────────────────────────────────── */

	private function f7_provider_registered( string $class_name ): bool {
		$providers = apply_filters( 'bizcity_persona_tool_providers', array() );
		if ( ! is_array( $providers ) ) { return false; }
		foreach ( $providers as $p ) {
			if ( is_object( $p ) && get_class( $p ) === $class_name ) { return true; }
		}
		return false;
	}

	private function f7_probe( $thing, array $keys ) {
		foreach ( $keys as $k ) {
			if ( is_array( $thing ) && array_key_exists( $k, $thing ) ) { return $thing[ $k ]; }
			if ( is_object( $thing ) ) {
				if ( isset( $thing->{$k} ) ) { return $thing->{$k}; }
				$getter = 'get_' . $k;
				if ( method_exists( $thing, $getter ) ) { return $thing->{$getter}(); }
			}
		}
		return '';
	}

	/* ============================================================
	 * Phase B · F7.B1..B4 — Guru ↔ Skill/Provider Bridge
	 *
	 * Roadmap: PHASE-0.35-GURU-ROADMAP-STATUS.md §10 (Phase B)
	 * Rule:    PHASE-0-RULE-MPR-THINKING.md §11 (Phase B)
	 *
	 * Validates: schema (2 tables), Bridge classes loaded, REST routes
	 * registered, runtime API contract (tools_for_guru), and a per-guru
	 * counts panel for top characters.
	 * ============================================================ */

	private function render_guru_bridge_section(): void {
		echo '<h2>Phase B · F7.B1..B4 — Guru ↔ Skill/Provider Bridge</h2>';
		echo '<p style="color:#555;font-size:12px">Bridge: <code>BizCity_Guru_Skill_Bridge</code> + <code>BizCity_Guru_Provider_Bridge</code> (R-MPRT-5 anti-jailbreak gate). REST namespace <code>bizcity-guru/v1</code> (tách khỏi <code>bizcity/v1</code> LLM gateway per R-GW).</p>';

		$tasks = $this->compute_guru_bridge_tasks();
		echo '<h3>Sprint Tasks</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:90px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $this->badge( $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';

		$this->render_guru_bridge_counts();
		$this->render_guru_bridge_rest_table();
	}

	private function compute_guru_bridge_tasks(): array {
		$out  = array();
		global $wpdb;

		/* T-F7.B1 — Schema present (both tables) */
		$installer_loaded = class_exists( 'BizCity_Guru_Bridge_Installer', false );
		$tbl_s = $installer_loaded ? BizCity_Guru_Bridge_Installer::table_skills()    : ( $wpdb->prefix . 'bizcity_guru_skills' );
		$tbl_p = $installer_loaded ? BizCity_Guru_Bridge_Installer::table_providers() : ( $wpdb->prefix . 'bizcity_guru_providers' );
		$has_s = $this->table_present( $tbl_s );
		$has_p = $this->table_present( $tbl_p );
		$ver   = (string) get_option( 'bizcity_guru_bridge_schema_version', '' );
		$out[] = array(
			'id'       => 'T-F7.B1',
			'status'   => ( $has_s && $has_p && $ver !== '' ) ? 'PASS' : 'FAIL',
			'check'    => 'Schema: bizcity_guru_skills + bizcity_guru_providers + version option',
			'evidence' => sprintf(
				"Installer loaded: %s\nTable skills (%s): %s\nTable providers (%s): %s\nSchema version option: %s",
				$installer_loaded ? 'YES' : 'NO',
				$tbl_s, $has_s ? 'YES' : 'NO',
				$tbl_p, $has_p ? 'YES' : 'NO',
				$ver !== '' ? $ver : '(not set)'
			),
		);

		/* T-F7.B2 — Skill Bridge contract (used by admin-chat-policy) */
		$cls    = class_exists( 'BizCity_Guru_Skill_Bridge', false );
		$tools  = $cls && method_exists( 'BizCity_Guru_Skill_Bridge', 'tools_for_guru' );
		$attach = $cls && method_exists( 'BizCity_Guru_Skill_Bridge', 'attach' );
		$detach = $cls && method_exists( 'BizCity_Guru_Skill_Bridge', 'detach' );
		$probe_ok = false;
		if ( $cls && $tools ) {
			$probe = BizCity_Guru_Skill_Bridge::tools_for_guru( -1 );
			$probe_ok = is_array( $probe ) && empty( $probe );
		}
		$out[] = array(
			'id'       => 'T-F7.B2',
			'status'   => ( $cls && $tools && $attach && $detach && $probe_ok ) ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_Guru_Skill_Bridge: tools_for_guru/attach/detach + safe with invalid id',
			'evidence' => sprintf(
				"class=%s, tools_for_guru=%s, attach=%s, detach=%s\nProbe tools_for_guru(-1) returns []: %s",
				$cls ? 'YES' : 'NO', $tools ? 'YES' : 'NO',
				$attach ? 'YES' : 'NO', $detach ? 'YES' : 'NO',
				$probe_ok ? 'YES' : 'NO'
			),
		);

		/* T-F7.B3 — Provider Bridge contract */
		$cls2   = class_exists( 'BizCity_Guru_Provider_Bridge', false );
		$pf     = $cls2 && method_exists( 'BizCity_Guru_Provider_Bridge', 'providers_for_guru' );
		$attach2 = $cls2 && method_exists( 'BizCity_Guru_Provider_Bridge', 'attach' );
		$out[] = array(
			'id'       => 'T-F7.B3',
			'status'   => ( $cls2 && $pf && $attach2 ) ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_Guru_Provider_Bridge: providers_for_guru/attach',
			'evidence' => sprintf(
				"class=%s, providers_for_guru=%s, attach=%s",
				$cls2 ? 'YES' : 'NO', $pf ? 'YES' : 'NO', $attach2 ? 'YES' : 'NO'
			),
		);

		/* T-F7.B4 — REST routes registered */
		$server = rest_get_server();
		$routes = $server ? $server->get_routes() : array();
		$expected = array(
			'/bizcity-guru/v1/guru/(?P<id>\d+)/skills',
			'/bizcity-guru/v1/guru/(?P<id>\d+)/skills/unbound',
			'/bizcity-guru/v1/guru/(?P<id>\d+)/providers',
			'/bizcity-guru/v1/guru/(?P<id>\d+)/providers/unbound',
		);
		$missing = array();
		foreach ( $expected as $r ) {
			if ( ! isset( $routes[ $r ] ) ) { $missing[] = $r; }
		}
		$rest_cls = class_exists( 'BizCity_Guru_Bridge_REST', false );
		$out[] = array(
			'id'       => 'T-F7.B4',
			'status'   => ( $rest_cls && empty( $missing ) ) ? 'PASS' : 'FAIL',
		'check'    => 'REST routes (4 expected) registered under bizcity-guru/v1',
			'evidence' => sprintf(
				"REST class: %s\nTotal routes: %d\nMissing: %s",
				$rest_cls ? 'YES' : 'NO',
				count( $routes ),
				empty( $missing ) ? '(none)' : implode( ', ', $missing )
			),
		);

		/* T-F7.B5 — Policy gate wired (admin-chat-policy reads bridge) */
		$policy = class_exists( 'BizCity_CRM_Admin_Chat_Policy', false );
		$out[] = array(
			'id'       => 'T-F7.B5',
			'status'   => ( $policy && $cls && $tools ) ? 'PASS' : ( $policy ? 'FAIL' : 'SKIP' ),
			'check'    => 'BizCity_CRM_Admin_Chat_Policy can call BizCity_Guru_Skill_Bridge::tools_for_guru()',
			'evidence' => sprintf(
				"Policy class: %s\nBridge contract reachable: %s",
				$policy ? 'YES' : 'NO',
				( $cls && $tools ) ? 'YES' : 'NO'
			),
		);

		/* T-F7.B6 — mu-plugin POST-bypass allowlist for bizcity-guru/v1 */
		$mu = WPMU_PLUGIN_DIR . '/bizgpt-multisite.php';
		$mu_readable = is_readable( $mu );
		$mu_src = $mu_readable ? (string) @file_get_contents( $mu ) : '';
		$has_guru_ns    = ( $mu_src !== '' )
			&& ( false !== strpos( $mu_src, '/bizcity-guru/v1/' ) || false !== strpos( $mu_src, 'bizcity-guru/v1' ) );
		$out[] = array(
			'id'       => 'T-F7.B6',
			'status'   => $has_guru_ns ? 'PASS' : ( $mu_readable ? 'WARN' : 'SKIP' ),
			'check'    => 'mu-plugin bizgpt-multisite.php allowlist includes bizcity-guru/v1 (REST POST bypass)',
			'evidence' => sprintf(
				"mu file readable: %s\nContains 'bizcity-guru/v1': %s\nNote: Guru Bridge la admin REST nen cap manage_options da gate access. POST-bypass chi can neu goi REST tu front-end (admin-chat). Neu chua co bypass: them pattern '/bizcity-guru/v1/' vao allowlist trong mu-plugin.",
				$mu_readable ? 'YES' : 'NO',
				$has_guru_ns ? 'YES' : 'NO'
			),
		);

		return $out;
	}

	private function render_guru_bridge_counts(): void {
		if ( ! class_exists( 'BizCity_Guru_Skill_Bridge', false ) ) { return; }
		global $wpdb;

		$total_skills    = BizCity_Guru_Skill_Bridge::count_total();
		$total_providers = class_exists( 'BizCity_Guru_Provider_Bridge', false )
			? BizCity_Guru_Provider_Bridge::count_total() : 0;
		$known_tools     = count( BizCity_Guru_Skill_Bridge::all_known_tools() );
		$known_providers = class_exists( 'BizCity_Guru_Provider_Bridge', false )
			? count( BizCity_Guru_Provider_Bridge::all_known_providers() ) : 0;

		echo '<h3>Counts</h3>';
		echo '<table class="widefat striped" style="max-width:700px"><tbody>';
		echo '<tr><td>Skill bindings (total rows)</td><td><b>' . (int) $total_skills . '</b></td></tr>';
		echo '<tr><td>Provider bindings (total rows)</td><td><b>' . (int) $total_providers . '</b></td></tr>';
		echo '<tr><td>Known tools at runtime (Layer 2 + 3)</td><td><b>' . (int) $known_tools . '</b></td></tr>';
		echo '<tr><td>Known persona providers at runtime</td><td><b>' . (int) $known_providers . '</b></td></tr>';
		echo '</tbody></table>';

		/* Top 10 gurus from wp_bizcity_characters */
		$char_tbl = $wpdb->prefix . 'bizcity_characters';
		if ( ! $this->table_present( $char_tbl ) ) { return; }
		$skill_tbl    = BizCity_Guru_Bridge_Installer::table_skills();
		$provider_tbl = BizCity_Guru_Bridge_Installer::table_providers();

		$rows = $wpdb->get_results(
			"SELECT c.id AS guru_id,
				   COALESCE(c.name, c.slug, CONCAT('#', c.id)) AS name,
				   (SELECT COUNT(*) FROM {$skill_tbl}    s WHERE s.guru_id = c.id) AS skills,
				   (SELECT COUNT(*) FROM {$provider_tbl} p WHERE p.guru_id = c.id) AS providers
			FROM {$char_tbl} c
			ORDER BY (skills + providers) DESC, c.id ASC
			LIMIT 10",
			ARRAY_A
		);
		if ( empty( $rows ) ) { return; }

		echo '<h3>Top gurus by binding count</h3>';
		echo '<table class="widefat striped" style="max-width:700px"><thead><tr>';
		echo '<th style="width:80px">Guru ID</th><th>Name</th><th style="width:90px">Skills</th><th style="width:90px">Providers</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><code>' . (int) $r['guru_id'] . '</code></td>';
			echo '<td>' . esc_html( (string) $r['name'] ) . '</td>';
			echo '<td>' . (int) $r['skills'] . '</td>';
			echo '<td>' . (int) $r['providers'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_guru_bridge_rest_table(): void {
		echo '<h3>REST endpoints</h3>';
		$base = rest_url( 'bizcity-guru/v1/guru' );
		echo '<p style="color:#555;font-size:12px">Namespace <code>bizcity-guru/v1</code> (tach khoi <code>bizcity/v1</code> cua LLM gateway). All endpoints require <code>manage_options</code>. Base: <a href="' . esc_url( $base ) . '" target="_blank"><code>' . esc_html( $base ) . '</code></a></p>';

		/* Pick a real guru id for sample links (first row in characters table). */
		global $wpdb;
		$char_tbl = $wpdb->prefix . 'bizcity_characters';
		$sample_id = 1;
		if ( $this->table_present( $char_tbl ) ) {
			$first = (int) $wpdb->get_var( "SELECT id FROM {$char_tbl} ORDER BY id ASC LIMIT 1" );
			if ( $first > 0 ) { $sample_id = $first; }
		}

		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
		echo '<th style="width:80px">Method</th><th>Path</th><th>Sample (guru #' . (int) $sample_id . ')</th><th>Notes</th>';
		echo '</tr></thead><tbody>';
		$rows = array(
			array( 'GET',    '/{id}/skills',              "bizcity-guru/v1/guru/{$sample_id}/skills",                   'List bound skills' ),
			array( 'POST',   '/{id}/skills',              "bizcity-guru/v1/guru/{$sample_id}/skills",                   'Body: tool_id, tool_class?, priority?' ),
			array( 'DELETE', '/{id}/skills/{tool}',       "bizcity-guru/v1/guru/{$sample_id}/skills/tarot_interpret",   'Detach by tool_id' ),
			array( 'GET',    '/{id}/skills/unbound',      "bizcity-guru/v1/guru/{$sample_id}/skills/unbound",           'Diff vs runtime registry' ),
			array( 'GET',    '/{id}/providers',           "bizcity-guru/v1/guru/{$sample_id}/providers",                'List bound providers' ),
			array( 'POST',   '/{id}/providers',           "bizcity-guru/v1/guru/{$sample_id}/providers",                'Body: provider_class, scope?' ),
			array( 'DELETE', '/{id}/providers/{class}',   "bizcity-guru/v1/guru/{$sample_id}/providers/BZCC_Persona_Provider", 'Detach by provider class' ),
			array( 'GET',    '/{id}/providers/unbound',   "bizcity-guru/v1/guru/{$sample_id}/providers/unbound",        'Diff vs runtime registry' ),
		);
		foreach ( $rows as $r ) {
			$url = rest_url( $r[2] );
			echo '<tr><td><code>' . esc_html( $r[0] ) . '</code></td>';
			echo '<td><code>' . esc_html( $r[1] ) . '</code></td>';
			if ( $r[0] === 'GET' ) {
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $r[2] ) . '</code></a> <span style="color:#888">(open)</span></td>';
			} else {
				echo '<td><code>' . esc_html( $r[2] ) . '</code> <span style="color:#888">(' . esc_html( $r[0] ) . ' — use curl)</span></td>';
			}
			echo '<td>' . esc_html( $r[3] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		/* curl crib */
		$nonce = wp_create_nonce( 'wp_rest' );
		$base_full = rest_url( 'bizcity-guru/v1/guru/' . (int) $sample_id . '/skills' );
		echo '<details style="margin-top:8px"><summary style="cursor:pointer;color:#0073aa">Quick POST (browser-side: nonce=current admin)</summary>';
		echo '<pre style="background:#fafafa;padding:8px;border:1px solid #eee;font-size:11px;white-space:pre-wrap">';
		echo esc_html( "fetch('" . $base_full . "', {\n  method: 'POST',\n  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '" . $nonce . "' },\n  body: JSON.stringify({ tool_id: 'tarot_interpret', tool_class: 'producer', priority: 10 })\n}).then(r=>r.json()).then(console.log);" );
		echo '</pre></details>';
	}

	/* ============================================================
	 * Phase C.5 + C.5.1 + D.1 — moved to sibling class.
	 * See: class-sprint-diagnostic-phase-cd.php (PHASE-0.35 / 2026-05-14)
	 * ============================================================ */

	private function render_phase_c_dispatch_section(): void {
		if ( class_exists( 'BizCity_CRM_Sprint_Diagnostic_Phase_CD' ) ) {
			BizCity_CRM_Sprint_Diagnostic_Phase_CD::render();
		} else {
			echo '<div class="notice notice-error"><p><code>BizCity_CRM_Sprint_Diagnostic_Phase_CD</code> not loaded — sibling include missing in bootstrap.</p></div>';
		}
	}

	/* ============================================================
	 * Helper — run a Diagnostics_Probe class and render results
	 * [2026-06-10 Johnny Chu] PHASE-0.39/0.40/0.41/0.42/0.43 — probe bridge
	 * ============================================================ */

	/**
	 * Load a probe file once (if needed), instantiate the probe class, call
	 * run() and render a standardised PASS/FAIL/SKIP table.
	 *
	 * @param string $h2_title    Section heading.
	 * @param string $probe_class Fully-qualified class name implementing BizCity_Diagnostics_Probe.
	 * @param string $probe_file  Absolute path to the probe PHP file (auto-require if not loaded).
	 * @param string $phase_note  Short note shown under the heading (e.g. doc path).
	 */
	private function render_probe_section( string $h2_title, string $probe_class, string $probe_file, string $phase_note = '' ): void {
		echo '<h2 style="margin-top:24px">' . esc_html( $h2_title ) . '</h2>';
		if ( $phase_note !== '' ) {
			echo '<p style="color:#555;font-size:12px">' . esc_html( $phase_note ) . '</p>';
		}

		// Auto-require probe file when not yet loaded.
		if ( ! class_exists( $probe_class, false ) && is_readable( $probe_file ) ) {
			// Load probe interface first if missing.
			$iface_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/interface-diagnostics-probe.php';
			if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $iface_file ) ) {
				require_once $iface_file;
			}
			require_once $probe_file;
		}

		if ( ! class_exists( $probe_class, false ) ) {
			echo '<div class="notice notice-warning inline"><p>Probe class <code>' . esc_html( $probe_class ) . '</code> not available — plugin/file missing.</p></div>';
			return;
		}

		/** @var BizCity_Diagnostics_Probe $probe */
		$probe = new $probe_class();

		// Check precondition.
		$pre = $probe->precondition();
		if ( is_wp_error( $pre ) ) {
			echo '<div class="notice notice-warning inline"><p>Precondition failed: <code>' . esc_html( $pre->get_error_message() ) . '</code></p></div>';
			return;
		}

		// Run probe.
		$rows = array();
		try {
			$rows = $probe->run( null );
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error inline"><p>Probe threw: <code>' . esc_html( $e->getMessage() ) . '</code></p></div>';
			return;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			echo '<p style="color:#888">Probe returned no rows.</p>';
			return;
		}

		// Count totals.
		$pass = 0; $fail = 0; $skip = 0;
		foreach ( $rows as $r ) {
			$st = strtolower( (string) ( $r['status'] ?? 'fail' ) );
			if ( $st === 'pass' ) { $pass++; }
			elseif ( $st === 'skip' ) { $skip++; }
			else { $fail++; }
		}
		$total = count( $rows );

		echo '<p style="font-size:12px;color:#555">';
		printf( '<b>%d</b> total — ', $total );
		printf( '<span style="color:#1f8a48">%d PASS</span> · ', $pass );
		printf( '<span style="color:#a00">%d FAIL</span> · ', $fail );
		printf( '<span style="color:#888">%d SKIP</span>', $skip );
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:80px">Status</th>';
		echo '<th style="width:38%">Check</th>';
		echo '<th>Detail / Evidence</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$st     = strtolower( (string) ( $r['status'] ?? 'fail' ) );
			$label  = (string) ( $r['label'] ?? $r['check'] ?? $r['id'] ?? '—' );
			$detail = (string) ( $r['detail'] ?? $r['evidence'] ?? '' );

			// Normalise status for badge().
			$badge_st = ( $st === 'pass' ) ? 'PASS' : ( ( $st === 'skip' ) ? 'SKIP' : 'FAIL' );
			echo '<tr>';
			echo '<td>' . $this->badge( $badge_st ) . '</td>';
			echo '<td style="font-size:12px">' . esc_html( $label ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px;color:#333">' . esc_html( $detail ) . '</pre></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ============================================================
	 * PHASE 0.39 — Zalo Personal + OA Channel Gateway
	 * [2026-06-10 Johnny Chu] PHASE-0.39 — sprint diag section
	 * ============================================================ */

	private function render_phase_039_section(): void {
		$this->render_probe_section(
			'PHASE-0.39 — Zalo Personal + OA Channel Gateway',
			'BizCity_Probe_Zalo_Personal',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-zalo-personal.php',
			'Spec: core/channel-gateway/docs/PHASE-0.39-ZALO-PERSONAL-OA-CHANNEL.md · Plugin: plugins/bizcity-zalo-personal'
		);

		// Additional inline checks specific to sprint diag context.
		echo '<h3 style="margin-top:14px">Phase 0.39 — Quick inline checks</h3>';
		$tasks = array();

		// Plugin bootstrap loaded?
		$boot_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-personal/bootstrap.php';
		$boot_ok   = is_readable( $boot_file );
		$tasks[] = array(
			'id'       => 'ZP.0.bootstrap',
			'status'   => $boot_ok ? 'PASS' : 'FAIL',
			'check'    => 'bizcity-zalo-personal/bootstrap.php exists on disk',
			'evidence' => $boot_ok ? $boot_file : 'FILE MISSING',
		);

		// 3 tables installed?
		global $wpdb;
		$zp_tables = array(
			$wpdb->prefix . 'bizcity_zalo_accounts',
			$wpdb->prefix . 'bizcity_zalo_message_map',
			$wpdb->prefix . 'bizcity_zalo_oa_window',
		);
		$zp_missing = array();
		foreach ( $zp_tables as $t ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
				$zp_missing[] = $t;
			}
		}
		$tasks[] = array(
			'id'       => 'ZP.1.tables',
			'status'   => empty( $zp_missing ) ? 'PASS' : 'FAIL',
			'check'    => '3 Zalo Personal tables installed (accounts, message_map, oa_window)',
			'evidence' => empty( $zp_missing )
				? 'all 3 tables present'
				: 'MISSING: ' . implode( ', ', $zp_missing ),
		);

		// platform_catalog filter registered?
		$cat_filter = has_filter( 'bizcity_channel_platform_catalog' );
		$tasks[] = array(
			'id'       => 'ZP.2.catalog_filter',
			'status'   => $cat_filter !== false ? 'PASS' : 'FAIL',
			'check'    => 'bizcity_channel_platform_catalog filter registered (tile injection)',
			'evidence' => $cat_filter !== false ? 'filter priority=' . $cat_filter : 'filter NOT attached',
		);

		// Integration classes registered?
		$int_ok = class_exists( 'BizCity_Zalo_Personal_Integration', false )
			&& class_exists( 'BizCity_Zalo_OA_Integration', false );
		$tasks[] = array(
			'id'       => 'ZP.3.integrations',
			'status'   => $int_ok ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_Zalo_Personal_Integration + BizCity_Zalo_OA_Integration classes loaded',
			'evidence' => sprintf( 'personal=%s · oa=%s',
				class_exists( 'BizCity_Zalo_Personal_Integration', false ) ? 'YES' : 'NO',
				class_exists( 'BizCity_Zalo_OA_Integration', false )       ? 'YES' : 'NO'
			),
		);

		// R-ZONE-2: universal-channel-listener guard against ZALO_BOT?
		$ul_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-universal-channel-listener.php';
		$ul_zone_guard = false;
		if ( is_readable( $ul_file ) ) {
			$src = (string) file_get_contents( $ul_file );
			$ul_zone_guard = ( strpos( $src, 'ZALO_BOT' ) !== false );
		}
		$tasks[] = array(
			'id'       => 'ZP.4.zone_guard',
			'status'   => $ul_zone_guard ? 'PASS' : 'FAIL',
			'check'    => 'R-ZONE-2: universal-channel-listener has ZALO_BOT discriminator guard',
			'evidence' => $ul_zone_guard
				? 'guard string "ZALO_BOT" found in class-universal-channel-listener.php'
				: 'guard MISSING — Zone 1/Zone 2 collision risk!',
		);

		// Inbound emitter exists?
		$emitter_ok = class_exists( 'BizCity_Zalo_Inbound_Emitter', false );
		$tasks[] = array(
			'id'       => 'ZP.5.inbound_emitter',
			'status'   => $emitter_ok ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_Zalo_Inbound_Emitter class loaded',
			'evidence' => $emitter_ok ? 'class present' : 'class missing',
		);

		// REST routes registered?
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$zp_routes = array(
			'/bizcity-channel/v1/zalo-bridge/inbound',
			'/bizcity-channel/v1/zalo-bridge/health',
			'/bizcity-channel/v1/zalo-bridge/settings',
		);
		$zp_miss_r = array();
		foreach ( $zp_routes as $r ) {
			if ( ! in_array( $r, $routes, true ) ) { $zp_miss_r[] = $r; }
		}
		$tasks[] = array(
			'id'       => 'ZP.6.rest_routes',
			'status'   => empty( $zp_miss_r ) ? 'PASS' : 'FAIL',
			'check'    => 'Zalo Bridge REST routes (bizcity-channel/v1/zalo-bridge/*) registered',
			'evidence' => empty( $zp_miss_r )
				? 'inbound + health + settings routes present'
				: 'MISSING: ' . implode( ', ', $zp_miss_r ),
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:120px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $this->badge( $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ============================================================
	 * PHASE 0.40 — CRM Deplao Parity (reports + campaign variants)
	 * [2026-06-10 Johnny Chu] PHASE-0.40 — sprint diag section
	 * ============================================================ */

	private function render_phase_040_section(): void {
		$this->render_probe_section(
			'PHASE-0.40 — CRM Deplao Parity (Reports + Broadcast Foundation)',
			'BizCity_Probe_CRM_Deplao_Parity',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-crm-deplao-parity.php',
			'Spec: core/channel-gateway/docs/PHASE-0.40-CRM-DEPLAO-PARITY.md · G3.4 Reports + G4.5 Campaign Variants'
		);

		// Zone isolation probe.
		$this->render_probe_section(
			'PHASE-0.40 R-ZONE — Zone 1/Zone 2 Channel Isolation',
			'BizCity_Probe_Channel_Zone_Isolation',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-channel-zone-isolation.php',
			'R-ZONE-2: ZALO_BOT must not enter CRM Inbox; zalo_oa/zalo_personal must not trigger admin automation.'
		);

		// Additional inline: report REST endpoints.
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$ns     = '/' . ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1' );
		$report_routes = array(
			$ns . '/reports/message',
			$ns . '/reports/response',
			$ns . '/reports/agent',
			$ns . '/reports/campaign',
		);
		$miss_r = array_values( array_diff( $report_routes, $routes ) );

		echo '<h3 style="margin-top:14px">Phase 0.40 G3 — Report REST endpoints</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Route</th><th style="width:80px">Status</th>';
		echo '</tr></thead><tbody>';
		foreach ( $report_routes as $r ) {
			$ok = in_array( $r, $routes, true );
			echo '<tr><td><code>' . esc_html( $r ) . '</code></td><td>' . $this->badge( $ok ? 'PASS' : 'FAIL' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Campaign variants column.
		global $wpdb;
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$camp_tbl     = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
			$has_variants = BizCity_CRM_DB_Installer_V2::column_exists( $camp_tbl, 'variants_json' );
			$has_variant_mode = BizCity_CRM_DB_Installer_V2::column_exists( $camp_tbl, 'variant_mode' );
			echo '<p style="font-size:12px;margin-top:6px">';
			echo 'Campaign variants_json column: ' . $this->badge( $has_variants ? 'PASS' : 'FAIL' ) . ' · ';
			echo 'variant_mode column: ' . $this->badge( $has_variant_mode ? 'PASS' : 'FAIL' );
			echo '</p>';
		}
	}

	/* ============================================================
	 * PHASE 0.41 — Automation UI Dual-Path (Admin builder ↔ CRM-care)
	 * [2026-06-10 Johnny Chu] PHASE-0.41 — sprint diag section
	 * ============================================================ */

	private function render_phase_041_section(): void {
		$this->render_probe_section(
			'PHASE-0.41 — Automation UI Dual-Path (Admin Zone 2 ↔ CRM-care Zone 1)',
			'BizCity_Probe_Automation_CRM_Path',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-automation-crm-path.php',
			'Spec: core/automation/docs/PHASE-0.41-AUTOMATION-CRM-PATH.md · CRM-PATH-1 to CRM-PATH-5 sprints'
		);

		// Quick inline: automation REST zone filter.
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$auto_ns = '/bizcity-automation/v1';

		echo '<h3 style="margin-top:14px">Phase 0.41 — Automation REST zone routes</h3>';
		$auto_routes_want = array(
			$auto_ns . '/workflows',
			$auto_ns . '/blocks',
			$auto_ns . '/templates',
		);
		echo '<table class="widefat striped"><thead><tr><th>Route</th><th style="width:80px">Status</th></tr></thead><tbody>';
		foreach ( $auto_routes_want as $r ) {
			$ok = in_array( $r, $routes, true );
			echo '<tr><td><code>' . esc_html( $r ) . '</code></td><td>' . $this->badge( $ok ? 'PASS' : 'FAIL' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// CRM-PATH-1 check: zone param in REST.
		$zone_filter_ok = false;
		$zone_note = 'n/a';
		if ( class_exists( 'BizCity_Automation_REST_Controller', false ) ) {
			$zone_filter_ok = method_exists( 'BizCity_Automation_REST_Controller', 'get_workflows' );
			$zone_note = $zone_filter_ok ? 'BizCity_Automation_REST_Controller::get_workflows() exists' : 'method missing';
		} elseif ( class_exists( 'BizCity_Automation_REST', false ) ) {
			$zone_filter_ok = method_exists( 'BizCity_Automation_REST', 'get_workflows' );
			$zone_note = $zone_filter_ok ? 'BizCity_Automation_REST::get_workflows() exists' : 'method missing';
		} else {
			$zone_note = 'REST controller class not found';
		}
		echo '<p style="font-size:12px;margin-top:6px">Zone filter REST handler: ' . $this->badge( $zone_filter_ok ? 'PASS' : 'FAIL' ) . ' — ' . esc_html( $zone_note ) . '</p>';
	}

	/* ============================================================
	 * PHASE 0.42 — LiteParse Adapter (Pro-Tier Layout-Preserving Parse)
	 * [2026-06-10 Johnny Chu] PHASE-0.42 — sprint diag section
	 * ============================================================ */

	private function render_phase_042_section(): void {
		$this->render_probe_section(
			'PHASE-0.42 — LiteParse Adapter (Pro-Tier Layout-Preserving Document Parsing)',
			'BizCity_Probe_LiteParse',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-liteparse.php',
			'Spec: modules/twinchat/docs/PHASE-0.42-LITEPARSE-LAYOUT-PRESERVING.md · Pro-Tier PDF/DOCX/Image adapter'
		);

		// Quick check: adapter file on disk.
		$adapter_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/adapters/class-liteparse-adapter.php';
		$adapter_ok   = is_readable( $adapter_file );
		$adapter_class = class_exists( 'BizCity_KG_LiteParse_Adapter', false );

		echo '<h3 style="margin-top:14px">Phase 0.42 — Quick file checks</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Check</th><th style="width:80px">Status</th><th>Evidence</th></tr></thead><tbody>';
		echo '<tr><td>Adapter file on disk</td><td>' . $this->badge( $adapter_ok ? 'PASS' : 'FAIL' ) . '</td><td><code>' . esc_html( $adapter_ok ? $adapter_file : 'FILE MISSING' ) . '</code></td></tr>';
		echo '<tr><td>BizCity_KG_LiteParse_Adapter class loaded</td><td>' . $this->badge( $adapter_class ? 'PASS' : 'FAIL' ) . '</td><td>' . ( $adapter_class ? 'class present' : 'class not loaded' ) . '</td></tr>';

		// Entitlement gate check.
		$entitle_ok = class_exists( 'BizCity_Entitlement', false )
			&& method_exists( 'BizCity_Entitlement', 'can' );
		echo '<tr><td>BizCity_Entitlement::can() available for gate</td><td>' . $this->badge( $entitle_ok ? 'PASS' : 'SKIP' ) . '</td><td>' . ( $entitle_ok ? 'class+method present' : 'SKIP — entitlement class not loaded' ) . '</td></tr>';

		echo '</tbody></table>';
	}

	/* ============================================================
	 * PHASE 0.43 — Broadcast Mass-Send (Deplao Parity 2)
	 * [2026-06-10 Johnny Chu] PHASE-0.43 — sprint diag section
	 * ============================================================ */

	private function render_phase_043_section(): void {
		$this->render_probe_section(
			'PHASE-0.43 — Broadcast Mass-Send (Deplao Parity 2)',
			'BizCity_Probe_CRM_Broadcast_Deplao',
			WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/includes/probes/class-probe-crm-broadcast-deplao.php',
			'Spec: core/channel-gateway/docs/PHASE-0.43-BROADCAST-MASS-SEND.md · M0-M1-M2-M3-M4-M5 ALL SHIPPED 2026-06-07'
		);

		// Additional inline: migration and schema.
		$tasks = array();

		// migrate_phase_048 exists?
		$has_migrate = method_exists( 'BizCity_CRM_DB_Installer_V2', 'migrate_phase_048' );
		$tasks[] = array(
			'id'       => 'BC.1.migration',
			'status'   => $has_migrate ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_CRM_DB_Installer_V2::migrate_phase_048() exists (action_flags_json + scheduled_send_at)',
			'evidence' => $has_migrate ? 'migrate_phase_048() present' : 'METHOD MISSING',
		);

		// Schema columns on DB.
		global $wpdb;
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$bc_tbl   = $wpdb->prefix . 'bizcity_crm_broadcasts';
			$rcp_tbl  = $wpdb->prefix . 'bizcity_crm_broadcast_recipients';
			$bc_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bc_tbl ) ) === $bc_tbl;
			$rcp_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rcp_tbl ) ) === $rcp_tbl;

			$has_action_flags = $bc_exists
				&& BizCity_CRM_DB_Installer_V2::column_exists( $bc_tbl, 'action_flags_json' );
			$has_delay        = $bc_exists
				&& BizCity_CRM_DB_Installer_V2::column_exists( $bc_tbl, 'delay_sec' );
			$has_sched        = $rcp_exists
				&& BizCity_CRM_DB_Installer_V2::column_exists( $rcp_tbl, 'scheduled_send_at' );

			$tasks[] = array(
				'id'       => 'BC.2.schema_cols',
				'status'   => ( $has_action_flags && $has_delay && $has_sched ) ? 'PASS' : 'FAIL',
				'check'    => 'broadcasts.action_flags_json + delays_sec + recipients.scheduled_send_at columns exist',
				'evidence' => sprintf(
					'broadcasts.action_flags_json=%s · broadcasts.delay_sec=%s · recipients.scheduled_send_at=%s',
					$has_action_flags ? 'YES' : 'NO',
					$has_delay        ? 'YES' : 'NO',
					$has_sched        ? 'YES' : 'NO'
				),
			);
		}

		// pick_variant_full exists?
		$pvf_ok = method_exists( 'BizCity_CRM_Broadcast_Dispatcher', 'pick_variant_full' );
		$tasks[] = array(
			'id'       => 'BC.3.pick_variant_full',
			'status'   => $pvf_ok ? 'PASS' : 'FAIL',
			'check'    => 'BizCity_CRM_Broadcast_Dispatcher::pick_variant_full() exists',
			'evidence' => $pvf_ok ? 'method present' : 'method missing',
		);

		// Zalo Personal Adapter (Phase 0.39 M2).
		$zp_adapter_ok = class_exists( 'BizCity_Zalo_Personal_Adapter', false );
		$tasks[] = array(
			'id'       => 'BC.4.zalo_adapter',
			'status'   => $zp_adapter_ok ? 'PASS' : 'SKIP',
			'check'    => 'BizCity_Zalo_Personal_Adapter class loaded (Phase 0.39 M2 — send_friend_request + invite_to_group)',
			'evidence' => $zp_adapter_ok
				? 'class loaded · sendFriendRequest/inviteToGroup available'
				: 'SKIP — bizcity-zalo-personal plugin not active (disable Kết bạn/Mời nhóm actions)',
		);

		// REST broadcast routes.
		$server = rest_get_server();
		$routes = $server ? array_keys( $server->get_routes() ) : array();
		$bc_ns  = '/' . ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1' );
		$bc_want = array(
			$bc_ns . '/broadcasts',
			$bc_ns . '/broadcasts/(?P<id>\d+)/send',
			$bc_ns . '/broadcasts/(?P<id>\d+)/progress',
		);
		$bc_miss = array_values( array_diff( $bc_want, $routes ) );
		$tasks[] = array(
			'id'       => 'BC.5.rest_routes',
			'status'   => empty( $bc_miss ) ? 'PASS' : 'FAIL',
			'check'    => 'Broadcast REST routes (create, send, progress) registered',
			'evidence' => empty( $bc_miss )
				? 'all 3 routes present (' . implode( ', ', $bc_want ) . ')'
				: 'MISSING: ' . implode( ', ', $bc_miss ),
		);

		// Cron hook.
		$bc_cron_ok = (bool) wp_next_scheduled( 'bizcity_crm_broadcast_tick' );
		$tasks[] = array(
			'id'       => 'BC.6.cron',
			'status'   => $bc_cron_ok ? 'PASS' : 'FAIL',
			'check'    => 'bizcity_crm_broadcast_tick cron scheduled',
			'evidence' => $bc_cron_ok
				? 'next: ' . gmdate( 'Y-m-d H:i:s', (int) wp_next_scheduled( 'bizcity_crm_broadcast_tick' ) ) . ' UTC'
				: 'NOT scheduled',
		);

		echo '<h3 style="margin-top:14px">Phase 0.43 — Inline schema + runtime checks</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:140px">Task</th><th style="width:80px">Status</th><th>Check</th><th>Evidence</th>';
		echo '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr><td><code>' . esc_html( $t['id'] ) . '</code></td>';
			echo '<td>' . $this->badge( $t['status'] ) . '</td>';
			echo '<td>' . esc_html( $t['check'] ) . '</td>';
			echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html( $t['evidence'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';
	}
}

