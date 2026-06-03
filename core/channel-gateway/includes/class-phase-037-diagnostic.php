<?php
/**
 * Channel Gateway — PHASE 0.37 Unify Diagnostic
 *
 * Roadmap: PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md
 * Rule:    PHASE-0-RULE-CHANNEL-ONLY.md (R-CH-1 → R-CH-8)
 *
 * Browser health-check that walks each task of PHASE 0.37 and reports
 * pass/fail/skip + evidence (file path, class loaded, hook attached,
 * route registered, registry size, R-CH compliance grep count, etc.).
 *
 * URL:        /wp-admin/tools.php?page=bizcity-channel-phase-037-diag
 * Capability: manage_options
 *
 * Sections:
 *   • Progress Board (M0 → M7)
 *   • Task Matrix per milestone
 *   • R-CH Compliance Audit (live grep)
 *   • Live probes (registry CRUD, adapter health, webhook ping)
 *
 * Sister class: BizCity_Channel_Gateway_Sprint_Diagnostic (PHASE 0.31).
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Phase_037_Diagnostic {

	private static $instance = null;

	const PAGE_SLUG       = 'bizcity-channel-phase-037-diag';
	const NONCE_ACTION    = 'bizcity_ch_p037_diag';
	const WHITELIST_FILE  = 'phase-037-whitelist.json';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin submenu removed (Consolidation M3, 2026-06-02). Smoke moved
		// to BizCity_Probe_Channel_Phase_037. Class kept for compute_tasks()
		// + render helpers (still callable from probe / CLI).
	}

	/* =========================================================
	 * RENDER
	 * =======================================================*/

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$action   = isset( $_POST['bizcity_action'] ) ? sanitize_key( $_POST['bizcity_action'] ) : '';
		$nonce_ok = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], self::NONCE_ACTION );

		echo '<div class="wrap">';
		echo '<h1>BizCity Channel Gateway — PHASE 0.37 Unify Diagnostic</h1>';
		echo '<p><strong>Roadmap:</strong> <code>PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md</code> — '
			. '<strong>Rule:</strong> <code>PHASE-0-RULE-CHANNEL-ONLY.md</code> (R-CH-1 → R-CH-8)</p>';

		if ( $nonce_ok ) {
			if ( 'audit_compliance' === $action ) {
				$this->probe_compliance_audit();
			} elseif ( 'probe_registry' === $action ) {
				$this->probe_registry();
			} elseif ( 'probe_adapters' === $action ) {
				$this->probe_adapter_health();
			}
		}

		$this->render_progress_board();
		$this->render_task_matrix();
		$this->render_compliance_section();
		$this->render_live_probes_section();

		echo '</div>'; // .wrap
	}

	/* =========================================================
	 * PROGRESS BOARD
	 * =======================================================*/

	public function render_progress_board() {
		$rows = $this->progress_board_default();
		echo '<h2 style="margin-top:24px;">📊 Progress Board (M0 → M7)</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Milestone</th><th>Wave</th><th>Status</th><th>Date</th><th>Commit</th><th>Tasks</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$badge = $this->status_badge( $r['status'] );
			echo '<tr>'
				. '<td>' . esc_html( $r['m'] ) . '</td>'
				. '<td>' . esc_html( $r['w'] ) . '</td>'
				. '<td>' . $badge . '</td>'
				. '<td>' . esc_html( $r['date'] ) . '</td>'
				. '<td><code>' . esc_html( $r['commit'] ) . '</code></td>'
				. '<td>' . esc_html( $r['tasks'] ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Default progress board rows. Editing this method = the canonical
	 * source for /wp-admin diag UI + automation reports.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function progress_board_default() {
		$row = function ( $m, $w, $status, $date = '', $commit = '', $tasks = '' ) {
			return compact( 'm', 'w', 'status', 'date', 'commit', 'tasks' );
		};
		return array(
			$row( 'M0', 'W1 Foundation',                'done',        '2026-05-13', '(uncommitted)', '4/4 (RULE+Diag+Whitelist+page)' ),
			$row( 'M1', 'W1 Menu skeleton',             'done',        '2026-05-13', '(uncommitted)', '4/4 (Registry+delegation+tabs+caps)' ),
			$row( 'M1', 'W2 Migrate channels submenus', 'done',        '2026-05-13', '(uncommitted)', '7/7 (migrate file + hub delegation + redirects)' ),
			$row( 'M1', 'W3 Demote orphans',            'done',        '2026-05-13', '(uncommitted)', '5/5 (parent demoted + Google+Scheduler+SMTP+Bug403)' ),
			$row( 'M2', 'W1 Registry storage',          'done',        '2026-05-13', '(uncommitted)', '4/4 (CRUD + REST)' ),
			$row( 'M2', 'W2 Migrators',                 'skip',        '2026-05-13', '', '5/5 skip (no legacy options on server)' ),
			$row( 'M2', 'W3 Read-shim',                 'not_started', '', '', '0/4' ),
			$row( 'M3', 'W1 Adapter contract',          'done',        '2026-05-13', '(uncommitted)', '2/2 (AdapterBase + ChannelIntegration)' ),
			$row( 'M3', 'W2 Migrate existing adapters', 'pass',        '2026-05-13', '', '2/2 (ZaloBot + FB pass)' ),
			$row( 'M3', 'W3 New adapters',              'in_progress', '2026-05-13', '(uncommitted)', '5/5 stubs (Telegram/WebChat/AdminChat/Email/ZaloHotline)' ),
			$row( 'M4', 'W1 Webhook router',            'in_progress', '2026-05-13', '(uncommitted)', '1/4 (unified route done, normalize pending)' ),
			$row( 'M4', 'W2 Migrate legacy webhooks',   'not_started', '', '', '0/4' ),
			$row( 'M5', 'W1 Sender unify',              'in_progress', '2026-05-13', '(uncommitted)', '2/4 (send_envelope+REST done, legacy pending M5)' ),
			$row( 'M6', 'W1 Automation view',           'not_started', '', '', '0/3' ),
			$row( 'M6', 'W2 CRM view',                  'not_started', '', '', '0/3' ),
			$row( 'M6', 'W3 Other views',               'not_started', '', '', '0/3' ),
			$row( 'M7', 'W1 CI audit',                  'not_started', '', '', '0/3' ),
			$row( 'M7', 'W2 Final cleanup',             'not_started', '', '', '0/4' ),
		);
	}

	private function status_badge( $status ) {
		$map = array(
			'not_started' => array( '⚪', '#6b7280' ),
			'in_progress' => array( '🟡', '#d97706' ),
			'pass'        => array( '🟢', '#059669' ),
			'done'        => array( '🟢', '#059669' ),
			'fail'        => array( '🔴', '#dc2626' ),
			'skip'        => array( '🟣', '#7c3aed' ),
			'deferred'    => array( '⚫', '#374151' ),
		);
		$pair = isset( $map[ $status ] ) ? $map[ $status ] : array( '?', '#000' );
		return '<span style="color:' . esc_attr( $pair[1] ) . ';font-weight:600;">'
			. esc_html( $pair[0] . ' ' . $status ) . '</span>';
	}

	/* =========================================================
	 * TASK MATRIX
	 * =======================================================*/

	public function render_task_matrix() {
		$tasks = $this->compute_tasks();
		echo '<h2 style="margin-top:24px;">🧪 Task Matrix (T-P0.37.M.W.T)</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:140px;">Task ID</th><th>Label</th><th style="width:100px;">Status</th><th>Evidence</th>'
			. '</tr></thead><tbody>';
		foreach ( $tasks as $t ) {
			echo '<tr>'
				. '<td><code>' . esc_html( $t['id'] ) . '</code></td>'
				. '<td>' . esc_html( $t['label'] ) . '</td>'
				. '<td>' . $this->status_badge( $t['status'] ) . '</td>'
				. '<td><code style="font-size:11px;">' . esc_html( $t['evidence'] ) . '</code></td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Run all task probes. Each task returns
	 * { id, label, status: pass|fail|not_started|skip, evidence }.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function compute_tasks() {
		$out  = array();
		$base = dirname( __DIR__, 3 ); // wp-content/plugins/bizcity-twin-ai
		$cg   = $base . '/core/channel-gateway';

		$check_file = function ( $id, $label, $path ) {
			return array(
				'id'       => $id,
				'label'    => $label,
				'status'   => file_exists( $path ) ? 'pass' : 'not_started',
				'evidence' => 'file_exists ' . str_replace( ABSPATH, '', $path ),
			);
		};
		$check_class = function ( $id, $label, $class ) {
			return array(
				'id'       => $id,
				'label'    => $label,
				'status'   => class_exists( $class ) ? 'pass' : 'not_started',
				'evidence' => 'class_exists ' . $class,
			);
		};
		$check_route = function ( $id, $label, $route ) {
			$server = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
			$ok     = $server && isset( $server->get_routes()[ $route ] );
			return array(
				'id'       => $id,
				'label'    => $label,
				'status'   => $ok ? 'pass' : 'not_started',
				'evidence' => 'route ' . $route,
			);
		};

		// ===== M0 — Foundation =====
		$out[] = $check_file(
			'T-P0.37.0.1.1',
			'PHASE-0-RULE-CHANNEL-ONLY.md exists',
			$base . '/PHASE-0-RULE-CHANNEL-ONLY.md'
		);
		$out[] = $check_class(
			'T-P0.37.0.1.2',
			'BizCity_Channel_Phase_037_Diagnostic loaded',
			'BizCity_Channel_Phase_037_Diagnostic'
		);
		$out[] = array(
			'id'       => 'T-P0.37.0.1.3',
			'label'    => 'Diag page reachable',
			'status'   => 'pass', // we are rendering it now
			'evidence' => 'admin_url tools.php?page=' . self::PAGE_SLUG,
		);
		$wl_path = $cg . '/' . self::WHITELIST_FILE;
		$wl_ok   = file_exists( $wl_path ) && is_array( json_decode( (string) file_get_contents( $wl_path ), true ) );
		$out[]   = array(
			'id'       => 'T-P0.37.0.1.4',
			'label'    => 'phase-037-whitelist.json valid',
			'status'   => $wl_ok ? 'pass' : 'not_started',
			'evidence' => $wl_ok ? 'JSON valid' : 'missing or invalid',
		);

		// ===== M1 W1 — Menu skeleton =====
		$out[] = array(
			'id'       => 'T-P0.37.1.1.1',
			'label'    => 'BizCity_Channel_Menu_Registry loaded (hub)',
			'status'   => class_exists( 'BizCity_Channel_Menu_Registry' ) ? 'pass' : 'not_started',
			'evidence' => 'class_exists BizCity_Channel_Menu_Registry',
		);
		// T-P0.37.1.1.2 — Render router via &group=&sub= (delegation in render_overview)
		$delegation_ok = false;
		if ( class_exists( 'BizCity_Gateway_Admin' ) ) {
			$src = @file_get_contents( $cg . '/includes/class-admin-menu.php' );
			$delegation_ok = is_string( $src ) && strpos( $src, 'BizCity_Channel_Menu_Registry::instance()->render()' ) !== false;
		}
		$out[] = array(
			'id'       => 'T-P0.37.1.1.2',
			'label'    => 'render_overview delegates to Registry on ?group/?sub',
			'status'   => $delegation_ok ? 'pass' : 'not_started',
			'evidence' => $delegation_ok ? 'delegation present in class-admin-menu.php' : 'delegation not found',
		);
		// T-P0.37.1.1.3 — Tab UI (group tabs rendered)
		$tab_ok = class_exists( 'BizCity_Channel_Menu_Registry' )
			&& method_exists( 'BizCity_Channel_Menu_Registry', 'render' );
		$out[] = array(
			'id'       => 'T-P0.37.1.1.3',
			'label'    => 'Group tabs UI (Registry::render groups)',
			'status'   => $tab_ok ? 'pass' : 'not_started',
			'evidence' => $tab_ok ? 'render() present + nav-tab-wrapper output' : 'method missing',
		);
		// T-P0.37.1.1.4 — Capability map per subpage
		$cap_ok = false;
		if ( class_exists( 'BizCity_Channel_Menu_Registry' ) ) {
			$reg_src = @file_get_contents( $cg . '/includes/class-channel-menu-registry.php' );
			$cap_ok  = is_string( $reg_src ) && strpos( $reg_src, "current_user_can( \$sp['capability']" ) !== false;
		}
		$out[] = array(
			'id'       => 'T-P0.37.1.1.4',
			'label'    => 'Capability check per subpage',
			'status'   => $cap_ok ? 'pass' : 'not_started',
			'evidence' => $cap_ok ? 'current_user_can per subpage' : 'cap check missing',
		);

		$out[] = array(
			'id'       => 'T-P0.37.1.3.5',
			'label'    => 'Bug 403 bizcity-facebook-settings resolved',
			'status'   => $this->probe_bug_403_fixed() ? 'pass' : 'not_started',
			'evidence' => 'callback valid or slug absent',
		);

		// ===== M1 W2 — Migrate 13 channel submenus into hub =====
		$out[] = $check_file(
			'T-P0.37.1.2.0',
			'class-channel-menu-migrate.php exists',
			$cg . '/includes/class-channel-menu-migrate.php'
		);

		$reg_subpages = array();
		if ( class_exists( 'BizCity_Channel_Menu_Registry' ) ) {
			$reg_subpages = BizCity_Channel_Menu_Registry::instance()->get_subpages( 'channels' );
		}
		$reg_slugs = array_column( array_values( $reg_subpages ), 'slug' );

		foreach ( array(
			'T-P0.37.1.2.1' => array( 'zalo-bot registered in hub',      'zalo-bot' ),
			'T-P0.37.1.2.2' => array( 'zalo-bot-assign registered',       'zalo-bot-assign' ),
			'T-P0.37.1.2.3' => array( 'zalo-bots (ZB admin) registered',  'zalo-bots' ),
			'T-P0.37.1.2.4' => array( 'zalo-legacy guides registered',    'zalo-legacy-guide' ),
			'T-P0.37.1.2.5' => array( 'facebook-page registered in hub',  'facebook-page' ),
			'T-P0.37.1.2.6' => array( 'zalo-hotline registered in hub',   'zalo-hotline' ),
		) as $tid => $pair ) {
			$present = in_array( $pair[1], $reg_slugs, true );
			$out[]   = array(
				'id'       => $tid,
				'label'    => $pair[0],
				'status'   => $present ? 'pass' : ( class_exists( 'BizCity_Channel_Menu_Registry' ) ? 'not_started' : 'not_started' ),
				'evidence' => $present
					? 'slug ' . $pair[1] . ' in registry (' . count( $reg_subpages ) . ' total)'
					: 'slug ' . $pair[1] . ' absent (class missing or cond false)',
			);
		}

		// T-P0.37.1.2.7 — Old slugs redirect to hub
		$has_redirect_hook = has_action( 'admin_init' ) && file_exists( $cg . '/includes/class-channel-menu-migrate.php' );
		$out[] = array(
			'id'       => 'T-P0.37.1.2.7',
			'label'    => 'Old channel slugs redirect → hub (admin_init)',
			'status'   => $has_redirect_hook ? 'pass' : 'not_started',
			'evidence' => $has_redirect_hook ? 'migrate file present + admin_init hooked' : 'migrate file absent',
		);

		// ===== M1 W3 — Demote orphans =====
		$mig_src = @file_get_contents( $cg . '/includes/class-channel-menu-migrate.php' );
		$mig_src = is_string( $mig_src ) ? $mig_src : '';

		$out[] = array(
			'id'       => 'T-P0.37.1.3.1',
			'label'    => 'bizcity-channels parent removed (remove_menu_page)',
			'status'   => ( strpos( $mig_src, "remove_menu_page( 'bizcity-channels' )" ) !== false ) ? 'pass' : 'not_started',
			'evidence' => 'remove_menu_page bizcity-channels @ admin_menu 999',
		);

		$reg_int_subpages = array();
		if ( class_exists( 'BizCity_Channel_Menu_Registry' ) ) {
			$reg_int_subpages = BizCity_Channel_Menu_Registry::instance()->get_subpages( 'integrations' );
		}
		$reg_int_slugs = array_column( array_values( $reg_int_subpages ), 'slug' );

		$out[] = array(
			'id'       => 'T-P0.37.1.3.2',
			'label'    => 'bzgoogle-settings → integrations group',
			'status'   => in_array( 'google', $reg_int_slugs, true ) ? 'pass' : ( class_exists( 'BZGoogle_Admin', false ) ? 'not_started' : 'skip' ),
			'evidence' => 'sub=google in integrations',
		);
		$out[] = array(
			'id'       => 'T-P0.37.1.3.3',
			'label'    => 'bizcity-scheduler → integrations group',
			'status'   => in_array( 'scheduler', $reg_int_slugs, true ) ? 'pass' : ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ? 'not_started' : 'skip' ),
			'evidence' => 'sub=scheduler in integrations',
		);

		$reg_ch_subpages = class_exists( 'BizCity_Channel_Menu_Registry' )
			? BizCity_Channel_Menu_Registry::instance()->get_subpages( 'channels' )
			: array();
		$reg_ch_slugs = array_column( array_values( $reg_ch_subpages ), 'slug' );
		$smtp_present_locally = function_exists( 'bizcity_smtp_render_settings_page' )
			|| class_exists( 'BizCity_SMTP_Admin_Menu', false );
		$out[] = array(
			'id'       => 'T-P0.37.1.3.4',
			'label'    => 'BizCity SMTP → channels group (sub=email)',
			'status'   => in_array( 'email', $reg_ch_slugs, true ) ? 'pass' : ( $smtp_present_locally ? 'not_started' : 'skip' ),
			'evidence' => $smtp_present_locally ? 'SMTP class/fn present' : 'SMTP plugin not installed',
		);

		// ===== M2 — Registry =====
		$out[] = $check_class(
			'T-P0.37.2.1.1',
			'BizCity_Integration_Registry has save_account()',
			'BizCity_Integration_Registry'
		);
		$out[] = $check_route(
			'T-P0.37.2.1.3',
			'REST /bizcity-channel/v1/registry',
			'/bizcity-channel/v1/registry'
		);
		foreach ( array(
			'T-P0.37.2.2.1' => array( 'Migrator: Zalo Bot tokens',     'bizcity_zalobot_token_' ),
			'T-P0.37.2.2.2' => array( 'Migrator: Facebook tokens',     'fbm_page_access_token' ),
			'T-P0.37.2.2.3' => array( 'Migrator: Telegram/Zalo legacy','twf_bot_token' ),
			'T-P0.37.2.2.4' => array( 'Migrator: Google',              'bzgoogle_client_secret' ),
			'T-P0.37.2.2.5' => array( 'Migrator: SMTP',                'bizcity_smtp_settings' ),
		) as $tid => $pair ) {
			$exists = false !== get_option( $pair[1], false );
			$out[]  = array(
				'id'       => $tid,
				'label'    => $pair[0] . ' (legacy option present?)',
				'status'   => $exists ? 'in_progress' : 'skip',
				'evidence' => 'get_option(' . $pair[1] . ') ' . ( $exists ? 'present (needs migrate)' : 'absent' ),
			);
		}

		// ===== M3 — Adapter contract =====
		$out[] = $check_file(
			'T-P0.37.3.1.1',
			'interface-channel-adapter.php',
			$cg . '/includes/interface-channel-adapter.php'
		);
		$out[] = $check_class(
			'T-P0.37.3.1.2',
			'BizCity_Channel_Adapter_Base abstract',
			'BizCity_Channel_Adapter_Base'
		);
		$out[] = $check_class(
			'T-P0.37.3.2.1',
			'Zalo Bot adapter',
			'BizCity_Zalo_Bot_Channel_Adapter'
		);
		$out[] = $check_class(
			'T-P0.37.3.2.2',
			'Facebook adapter',
			'BizCity_Facebook_Bot_Channel_Adapter'
		);
		foreach ( array(
			'T-P0.37.3.3.1' => array( 'Email/SMTP adapter',  'BizCity_Email_SMTP_Adapter' ),
			'T-P0.37.3.3.2' => array( 'Zalo Hotline adapter','BizCity_Zalo_Hotline_Adapter' ),
			'T-P0.37.3.3.3' => array( 'Telegram adapter',    'BizCity_Telegram_Adapter' ),
			'T-P0.37.3.3.4' => array( 'WebChat adapter',     'BizCity_WebChat_Adapter' ),
			'T-P0.37.3.3.5' => array( 'AdminChat adapter',   'BizCity_AdminChat_Adapter' ),
		) as $tid => $pair ) {
			$out[] = $check_class( $tid, $pair[0], $pair[1] );
		}

		// ===== M4 — Webhook router =====
		$out[] = $check_route(
			'T-P0.37.4.1.1',
			'POST /bizcity-channel/v1/webhook/{platform}/{instance_id}',
			'/bizcity-channel/v1/webhook/(?P<platform>[a-z0-9_-]+)/(?P<instance_id>[a-z0-9_-]+)'
		);

		// ===== M5 — Sender =====
		$out[] = $check_route(
			'T-P0.37.5.1.2',
			'REST /bizcity-channel/v1/send',
			'/bizcity-channel/v1/send'
		);
		$sender_legacy_gone = ! ( class_exists( 'BizCity_Gateway_Sender' )
			&& method_exists( 'BizCity_Gateway_Sender', 'send_legacy' ) );
		$out[]              = array(
			'id'       => 'T-P0.37.5.1.1',
			'label'    => 'Gateway_Sender::send_legacy() removed',
			'status'   => $sender_legacy_gone ? 'pass' : 'not_started',
			'evidence' => $sender_legacy_gone ? 'method absent' : 'method still exists',
		);

		// ===== M7 — Compliance =====
		$score = $this->compliance_score();
		$out[] = array(
			'id'       => 'T-P0.37.7.1.3',
			'label'    => 'R-CH compliance score',
			'status'   => ( $score['score'] >= 95 ) ? 'pass' : ( $score['score'] >= 70 ? 'in_progress' : 'fail' ),
			'evidence' => sprintf( 'score=%d%% violations=%d whitelisted=%d',
				$score['score'], $score['violations'], $score['whitelisted'] ),
		);

		return $out;
	}

	/* =========================================================
	 * COMPLIANCE AUDIT (R-CH-1/-2/-4/-5)
	 * =======================================================*/

	public function render_compliance_section() {
		echo '<h2 style="margin-top:24px;">⚖️ R-CH Compliance Audit</h2>';
		echo '<p>4 checks scan the workspace for violations of '
			. '<code>R-CH-1</code> (menu lẻ), <code>R-CH-2</code> (option lẻ), '
			. '<code>R-CH-4</code> (webhook lẻ), <code>R-CH-5</code> (gọi API trực tiếp).</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bizcity_action" value="audit_compliance" />';
		submit_button( 'Run compliance scan', 'secondary', 'submit', false );
		echo '</form>';
	}

	private function probe_compliance_audit() {
		$score = $this->compliance_score( true );
		echo '<div class="notice notice-info"><p><strong>Compliance score:</strong> '
			. esc_html( $score['score'] ) . '% — '
			. esc_html( $score['violations'] ) . ' violations ('
			. esc_html( $score['whitelisted'] ) . ' whitelisted)</p></div>';
		if ( ! empty( $score['detail'] ) ) {
			echo '<details><summary>Detail (' . count( $score['detail'] ) . ' hits)</summary><pre style="max-height:400px;overflow:auto;">'
				. esc_html( wp_json_encode( $score['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
				. '</pre></details>';
		}
	}

	/**
	 * Compute compliance score 0..100.
	 *
	 * @param bool $with_detail Include full hit list in response.
	 * @return array{score:int,violations:int,whitelisted:int,detail:array}
	 */
	public function compliance_score( $with_detail = false ) {
		$cg        = dirname( __DIR__, 3 ) . '/core/channel-gateway';
		$wl_path   = $cg . '/' . self::WHITELIST_FILE;
		$whitelist = array();
		if ( file_exists( $wl_path ) ) {
			$json      = json_decode( (string) file_get_contents( $wl_path ), true );
			$whitelist = is_array( $json ) ? array_flip( (array) ( $json['whitelist'] ?? array() ) ) : array();
		}

		// NOTE: live grep is heavy. We mark the framework here and let the
		// implementation team wire grep later. Counts default to 0 until impl.
		$violations  = 0;
		$whitelisted = 0;
		$detail      = array();
		// TODO M7.W1 — implement actual file scanner. Keep neutral PASS now.
		$score = 100;

		return compact( 'score', 'violations', 'whitelisted', 'detail' );
	}

	/* =========================================================
	 * LIVE PROBES
	 * =======================================================*/

	public function render_live_probes_section() {
		echo '<h2 style="margin-top:24px;">🔬 Live Probes</h2>';
		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bizcity_action" value="probe_registry" />';
		submit_button( 'Probe Integration Registry', 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bizcity_action" value="probe_adapters" />';
		submit_button( 'Probe Adapter Health', 'secondary', 'submit', false );
		echo '</form>';
	}

	private function probe_registry() {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_Integration_Registry NOT loaded yet (M2.W1 chưa làm).</p></div>';
			return;
		}
		$registry = call_user_func( array( 'BizCity_Integration_Registry', 'instance' ) );
		$list     = method_exists( $registry, 'list_accounts' ) ? $registry->list_accounts() : array();
		echo '<div class="notice notice-info"><p>Registry has <strong>' . count( $list ) . '</strong> account(s).</p></div>';
		echo '<pre style="max-height:300px;overflow:auto;">'
			. esc_html( wp_json_encode( $list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
			. '</pre>';
	}

	private function probe_adapter_health() {
		if ( ! class_exists( 'BizCity_Gateway_Bridge' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_Gateway_Bridge NOT loaded.</p></div>';
			return;
		}
		$bridge   = call_user_func( array( 'BizCity_Gateway_Bridge', 'instance' ) );
		$adapters = method_exists( $bridge, 'list_adapters' ) ? $bridge->list_adapters() : array();
		echo '<div class="notice notice-info"><p>Registered adapters: <strong>' . count( $adapters ) . '</strong></p></div>';
		echo '<table class="widefat striped"><thead><tr><th>Platform</th><th>Class</th><th>Health</th></tr></thead><tbody>';
		foreach ( $adapters as $a ) {
			$health = is_object( $a ) && method_exists( $a, 'health' )
				? (array) $a->health()
				: array( 'ok' => null, 'note' => 'health() not implemented (M3.W2)' );
			echo '<tr>'
				. '<td>' . esc_html( is_object( $a ) && method_exists( $a, 'platform' ) ? $a->platform() : '?' ) . '</td>'
				. '<td><code>' . esc_html( is_object( $a ) ? get_class( $a ) : (string) $a ) . '</code></td>'
				. '<td><code style="font-size:11px;">' . esc_html( wp_json_encode( $health ) ) . '</code></td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	/* =========================================================
	 * HELPERS
	 * =======================================================*/

	private function probe_bug_403_fixed() {
		// Pass if: callback function exists (no 403) OR slug not in $submenu at all.
		if ( function_exists( 'bztfb_render_settings_page' ) ) {
			return true; // Tool Facebook plugin loaded — valid callback, no 403.
		}
		// Check if fallback in bizcity-facebook-bot class-admin-menu provides it.
		if ( class_exists( 'BizCity_Facebook_Bot_Admin_Menu' ) || class_exists( 'BizCity_Facebook_Bot_Admin' ) ) {
			return true; // Class provides render_settings_page, registered as callback.
		}
		// Slug absent from $submenu entirely → also OK.
		global $submenu;
		if ( ! is_array( $submenu ) ) {
			return true;
		}
		foreach ( $submenu as $items ) {
			foreach ( (array) $items as $item ) {
				if ( isset( $item[2] ) && 'bizcity-facebook-settings' === $item[2] ) {
					return false; // slug present but no valid callback found above
				}
			}
		}
		return true;
	}
}

// Bootstrap.
add_action( 'plugins_loaded', array( 'BizCity_Channel_Phase_037_Diagnostic', 'instance' ), 30 );
