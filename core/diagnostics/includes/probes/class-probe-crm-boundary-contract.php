<?php
/**
 * DDV probe for PHASE-0.60 — `core.crm.boundary_contract`.
 *
 * Guards the CRM public-boundary contract (docs/PHASE-0.60-CRM-FRAMEWORK-
 * CONSUMER-BOUNDARY-CONTRACTS.md) so a future edit cannot silently reintroduce
 * the "parent menu manage_options, child manage_network, Setting Panel a third
 * capability" drift that phase fixed. Checks, in order:
 *
 *   1. Disk/Loader  — the four C1/C2/C4/C5 contract files exist and load.
 *   2. Persona matrix — `BizCity_CRM_Authority::can()` for a throw-away
 *      subscriber, a throw-away `bizcity_crm_staff` account with an EMPTY
 *      inbox scope, and a throw-away editor, against the oracle in §5 of the
 *      phase doc (only the parts a subscriber-role fixture can exercise
 *      safely — the operator's own admin identity covers the admin row).
 *   3. Menu/Setting-Panel parity — every gate that is supposed to ask
 *      `BizCity_CRM_Authority::menu_cap()` for the same action actually
 *      returns the same capability string.
 *   4. Zone Registry — `zalo_personal` still declares `managed_1api` as its
 *      default transport (§8-Q4).
 *   5. REST permission-callback allowlist — every route under the CRM REST
 *      namespace uses a known callback name; a brand-new, unreviewed name
 *      fails the probe instead of silently shipping (§3.2/§7 step 6).
 *   6. `manage_options` literal count under `CRM/includes/**` (excluding the
 *      `contracts/` facade and `class-capabilities.php`, the two files
 *      allowed to define what the literal means) never increases from the
 *      first-run baseline stored in an option (§7 step 7).
 *
 * Side effects: up to 3 disposable subscriber users and their `bizcity_crm_staff`
 * role/team-less state — all removed in cleanup(). No inbox, conversation,
 * contact or Zalo account is touched. Never mutates CRM data.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-20 (PHASE-0.60)
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Boundary_Contract', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Boundary_Contract implements BizCity_Diagnostics_Probe {

	const BASELINE_OPTION = 'bizcity_crm_boundary_manage_options_baseline';

	/** @var array<int,int> */
	private $fixture_users = array();

	public function id(): string { return 'core.crm.boundary_contract'; }
	public function label(): string { return 'CRM public boundary contract (PHASE-0.60)'; }
	public function description(): string { return 'Kiểm tra Actor/Authority/Zone Registry, đồng bộ capability menu/Setting Panel, allowlist REST permission callback và không tăng manage_options literal.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 68; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 500; }

	public function precondition() {
		foreach ( array( 'BizCity_CRM_Actor', 'BizCity_CRM_Authority', 'BizCity_CRM_Zone_Registry' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'crm_boundary_dependency_missing', $class . ' is not loaded — PHASE-0.60 contract files are missing or CRM plugin is inactive.' );
			}
		}
		if ( ! function_exists( 'bizcity_crm_surface_descriptors' ) ) {
			return new WP_Error( 'crm_boundary_surfaces_missing', 'bizcity_crm_surface_descriptors() is not loaded.' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'crm_boundary_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$operator_id = (int) get_current_user_id();

		// ── 1. Disk/Loader ──────────────────────────────────────────────
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$crm_dir = $root . 'plugins/bizcity-twin-crm/';
		$contract_files = array(
			'includes/contracts/class-crm-actor.php',
			'includes/contracts/class-crm-authority.php',
			'includes/contracts/class-crm-zone-registry.php',
			'includes/contracts/crm-surfaces.php',
		);
		$missing = array();
		foreach ( $contract_files as $relative ) {
			if ( ! is_readable( $crm_dir . $relative ) ) { $missing[] = $relative; }
		}
		$steps[] = array(
			'label'  => 'Disk — the four C1/C2/C4/C5 contract files exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? implode( ', ', $contract_files ) : 'Missing: ' . implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'PHASE-0.60 contract files are missing on disk.', 'steps' => $steps );
		}

		// ── 2. Persona matrix (throw-away fixture users) ────────────────
		$subscriber = $this->create_user( 'bnd_sub', array() );
		$staff_no_scope = $this->create_user( 'bnd_staff', array( 'bizcity_crm_staff' ) );
		$editor = $this->create_user( 'bnd_editor', array( 'editor' ) );
		$fixture_ok = $subscriber && $staff_no_scope && $editor;
		$steps[] = array(
			'label'  => 'Fixture users built (subscriber, empty-scope staff, editor)',
			'status' => $fixture_ok ? 'pass' : 'fail',
			'detail' => $fixture_ok ? 'ids ' . $subscriber . '/' . $staff_no_scope . '/' . $editor : 'wp_insert_user() failed for one or more throw-away accounts',
		);

		$can_as = static function ( int $user_id, string $action ) use ( $operator_id ): array {
			wp_set_current_user( $user_id );
			try {
				return BizCity_CRM_Authority::can( $action );
			} finally {
				wp_set_current_user( $operator_id );
			}
		};

		$oracle_failures = array();
		if ( $fixture_ok ) {
			// Subscriber: every gated action must be closed (§5 last row).
			foreach ( array( 'crm.inbox.open', 'crm.sales.write', 'crm.ai.use', 'crm.rules.manage' ) as $action ) {
				if ( $can_as( $subscriber, $action )['ok'] ) { $oracle_failures[] = "subscriber:$action should be denied"; }
			}
			// Empty-scope staff: inbox.open and self_connect and sales.write and ai.use must be OPEN (§8-Q1/Q3/Q5) — scope is enforced elsewhere (C3), not here.
			foreach ( array( 'crm.inbox.open', 'crm.channel.self_connect', 'crm.sales.write', 'crm.ai.use' ) as $action ) {
				if ( ! $can_as( $staff_no_scope, $action )['ok'] ) { $oracle_failures[] = "staff_no_scope:$action should be allowed"; }
			}
			// Empty-scope staff must still be refused admin-only actions.
			foreach ( array( 'crm.channel.manage', 'crm.settings.manage' ) as $action ) {
				if ( $can_as( $staff_no_scope, $action )['ok'] ) { $oracle_failures[] = "staff_no_scope:$action should be denied"; }
			}
			// Editor: sales.write + ai.use open (§8-Q1/Q5), rules.manage closed (§8-Q2).
			if ( ! $can_as( $editor, 'crm.sales.write' )['ok'] ) { $oracle_failures[] = 'editor:crm.sales.write should be allowed'; }
			if ( ! $can_as( $editor, 'crm.ai.use' )['ok'] ) { $oracle_failures[] = 'editor:crm.ai.use should be allowed'; }
			if ( $can_as( $editor, 'crm.rules.manage' )['ok'] ) { $oracle_failures[] = 'editor:crm.rules.manage should be denied'; }
		}
		$steps[] = array(
			'label'  => 'Persona matrix matches PHASE-0.60 §5 oracle (subscriber/empty-scope staff/editor slice)',
			'status' => $fixture_ok && empty( $oracle_failures ) ? 'pass' : 'fail',
			'detail' => empty( $oracle_failures ) ? 'subscriber closed, empty-scope staff open on inbox/self-connect/sales/ai, editor open on sales/ai and closed on rules' : implode( '; ', $oracle_failures ),
		);

		// ── 3. Menu / Setting Panel capability parity ───────────────────
		$parity_failures = array();
		if ( class_exists( 'BizCity_CRM_Admin_Menu' ) && method_exists( 'BizCity_CRM_Admin_Menu', 'inbox_cap' ) ) {
			$menu_cap = BizCity_CRM_Admin_Menu::inbox_cap();
			$authority_cap = BizCity_CRM_Authority::menu_cap( 'crm.inbox.open' );
			if ( $menu_cap !== $authority_cap ) {
				$parity_failures[] = "BizCity_CRM_Admin_Menu::inbox_cap() ({$menu_cap}) != Authority::menu_cap('crm.inbox.open') ({$authority_cap})";
			}
		} else {
			$parity_failures[] = 'BizCity_CRM_Admin_Menu::inbox_cap() is not available';
		}
		$surfaces = bizcity_crm_surface_descriptors();
		foreach ( $surfaces as $id => $surface ) {
			$action = (string) ( $surface['action'] ?? '' );
			if ( '' === $action ) { $parity_failures[] = "surface {$id} has no action"; continue; }
			// Every descriptor's action must be resolvable by the authority (no typo'd action id).
			$probe_result = BizCity_CRM_Authority::can( $action );
			if ( '' === (string) ( $probe_result['required'] ?? '' ) && 'permission_denied' === $probe_result['code'] && '' === $probe_result['why'] ) {
				// can() returns ok=false/code=permission_denied by default for an unknown action too,
				// so an empty `required` string is the tell — the switch() in Authority::can() has no case for it.
				$parity_failures[] = "surface {$id} action '{$action}' is not a known Authority action";
			}
		}
		$steps[] = array(
			'label'  => 'Menu / Setting Panel / surface descriptors ask the same resolver',
			'status' => empty( $parity_failures ) ? 'pass' : 'fail',
			'detail' => empty( $parity_failures ) ? 'inbox_cap() == menu_cap(), every surface action is known to Authority::can()' : implode( '; ', $parity_failures ),
		);

		// ── 4. Zone Registry — Zalo Cá nhân default transport (§8-Q4) ───
		$zp_zone = BizCity_CRM_Zone_Registry::for_channel( 'zalo_personal' );
		$transport_ok = ( 'managed_1api' === ( $zp_zone['transport_default'] ?? '' ) ) && ( 'owner_only' === ( $zp_zone['access_mode'] ?? '' ) );
		$steps[] = array(
			'label'  => 'Zone Registry — zalo_personal default transport is managed_1api, access owner_only',
			'status' => $transport_ok ? 'pass' : 'fail',
			'detail' => 'transport_default=' . (string) ( $zp_zone['transport_default'] ?? '(none)' ) . ', access_mode=' . (string) ( $zp_zone['access_mode'] ?? '(none)' ),
		);

		// ── 5. REST permission-callback allowlist ───────────────────────
		$known_methods = array(
			'can_write', 'can_manage_rules', 'can_use_crm', 'can_read', 'can_view_quota',
			'can_write_inbox_scope', 'can_view_reports', 'can_read_inbox_scope',
			'can_read_contact_scope', 'can_write_contact_scope', 'can_manage_teams', 'can_lead',
			'can_manage_assignment_policy', 'can_manage', 'permission', 'can_run_macro',
			'can_read_inbox_id_scope', 'can_preview_macro', 'can_write_order_care_move',
			'can_write_board_move', 'can_view_inbox_user_groups', 'can_ai_use_inbox_scope',
		);
		$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? (string) BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		$unknown_callbacks = array();
		$route_count = 0;
		$closure_count = 0;
		$return_true_count = 0;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			foreach ( $routes as $route => $handlers ) {
				if ( 0 !== strpos( (string) $route, '/' . $ns . '/' ) ) { continue; }
				foreach ( (array) $handlers as $handler ) {
					$cb = $handler['permission_callback'] ?? null;
					$route_count++;
					if ( $cb instanceof Closure ) { $closure_count++; continue; }
					if ( '__return_true' === $cb ) { $return_true_count++; continue; }
					if ( is_array( $cb ) && isset( $cb[1] ) ) {
						if ( ! in_array( (string) $cb[1], $known_methods, true ) ) {
							$unknown_callbacks[] = $route . ' → ' . ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . (string) $cb[1];
						}
						continue;
					}
					if ( is_string( $cb ) && '' !== $cb ) {
						$unknown_callbacks[] = $route . ' → ' . $cb;
					}
				}
			}
		}
		$steps[] = array(
			'label'  => 'REST permission callbacks under ' . $ns . ' are all on the reviewed allowlist',
			'status' => empty( $unknown_callbacks ) ? 'pass' : 'fail',
			'detail' => empty( $unknown_callbacks )
				? "{$route_count} route handler(s) checked, {$closure_count} closure(s), {$return_true_count} __return_true (webhook) — see PHASE-0.60 §3.2/C60-P03 before adding a new name"
				: 'Unreviewed callback(s): ' . implode( '; ', array_slice( $unknown_callbacks, 0, 10 ) ),
		);

		// ── 6. manage_options literal count never increases ─────────────
		$manage_options_count = 0;
		$includes_dir = $crm_dir . 'includes';
		if ( is_dir( $includes_dir ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) { continue; }
				$path = str_replace( '\\', '/', $file->getPathname() );
				if ( false !== strpos( $path, '/contracts/' ) || false !== strpos( $path, 'class-capabilities.php' ) ) { continue; }
				$source = (string) file_get_contents( $file->getPathname() );
				$manage_options_count += substr_count( $source, 'manage_options' );
			}
		}
		$baseline = (int) get_option( self::BASELINE_OPTION, 0 );
		if ( $baseline <= 0 ) {
			update_option( self::BASELINE_OPTION, $manage_options_count, false );
			$baseline = $manage_options_count;
		}
		$literal_ok = $manage_options_count <= $baseline;
		$steps[] = array(
			'label'  => 'manage_options literal count under CRM/includes/** has not increased',
			'status' => $literal_ok ? 'pass' : 'fail',
			'detail' => "current={$manage_options_count}, baseline={$baseline} (excludes contracts/ and class-capabilities.php)",
		);

		$ok = empty( $missing ) && $fixture_ok && empty( $oracle_failures ) && empty( $parity_failures )
			&& $transport_ok && empty( $unknown_callbacks ) && $literal_ok;
		return array(
			'status'   => $ok ? 'pass' : 'fail',
			'summary'  => $ok ? 'CRM public boundary contract holds.' : 'CRM public boundary contract has drifted — see steps.',
			'fix_hint' => 'Route every new CRM menu/route/Setting-Panel gate through BizCity_CRM_Authority::can()/menu_cap(); see plugins/bizcity-twin-crm/docs/PHASE-0.60-CRM-FRAMEWORK-CONSUMER-BOUNDARY-CONTRACTS.md §7.',
			'steps'    => $steps,
		);
	}

	private function create_user( string $prefix, array $roles ): int {
		$login = '__healthtest_' . $prefix . '_' . substr( md5( wp_generate_uuid4() ), 0, 8 );
		$email = $login . '@invalid.test';
		$user_id = wp_insert_user( array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => $roles[0] ?? 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) { return 0; }
		$user_id = (int) $user_id;
		foreach ( array_slice( $roles, 1 ) as $extra_role ) {
			( new WP_User( $user_id ) )->add_role( $extra_role );
		}
		if ( empty( $roles ) ) {
			( new WP_User( $user_id ) )->set_role( 'subscriber' );
		}
		$this->fixture_users[] = $user_id;
		return $user_id;
	}

	public function cleanup(): void {
		if ( empty( $this->fixture_users ) ) { return; }
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->fixture_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->fixture_users = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_CRM_Boundary_Contract';
	return $probes;
} );
