<?php
/**
 * DDV probe for PHASE-0.48F F6-06 — `core.crm.staff_policy`.
 *
 * Builds two throw-away teams (supervisor + lead + agent in team A, one agent in
 * team B) and proves the Staff_Policy matrix on the live site: rank floor, same
 * team, no acting on yourself, supervisor cannot create a peer/higher rank via
 * `POST /crm-staff`, the workspace read audit writes one row per day (F-UID-05),
 * and the C task route still ignores a `user_id` selector.
 *
 * Side effects: 4 disposable subscriber users, 2 teams, their membership rows and
 * at most one `workspace_viewed` audit row — all removed in cleanup(). No inbox,
 * conversation, contact, task or Zalo account is touched.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Staff_Policy', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Staff_Policy implements BizCity_Diagnostics_Probe {

	/** @var array<int,int> */
	private $fixture_users = array();

	/** @var array<int,int> */
	private $fixture_teams = array();

	public function id(): string {
		return 'core.crm.staff_policy';
	}

	public function label(): string {
		return 'CRM staff policy matrix (PHASE-0.48F)';
	}

	public function description(): string {
		return 'Checks rank, same-team and self rules of Staff_Policy on throw-away teams, the staff-create rank gate, the workspace read audit and the C selector guard.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 67; }
	public function icon(): string { return 'shield'; }
	public function estimate_ms(): int { return 600; }

	public function precondition() {
		foreach ( array( 'BizCity_CRM_Staff_Policy', 'BizCity_CRM_Team_Manager', 'BizCity_CRM_Staff_REST', 'BizCity_CRM_DB_Installer_V2' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'crm_staff_policy_dependency_missing', $class . ' is not loaded.' );
			}
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'crm_staff_policy_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$operator_id = (int) get_current_user_id();

		$supervisor = $this->create_user( 'sp_sup' );
		$lead       = $this->create_user( 'sp_lead' );
		$agent_a    = $this->create_user( 'sp_agent_a' );
		$agent_b    = $this->create_user( 'sp_agent_b' );
		$team_a     = BizCity_CRM_Team_Manager::create_team( '__healthtest staff policy A', 'DDV probe fixture', $operator_id );
		$team_b     = BizCity_CRM_Team_Manager::create_team( '__healthtest staff policy B', 'DDV probe fixture', $operator_id );
		foreach ( array( $team_a, $team_b ) as $team_id ) {
			if ( $team_id > 0 ) { $this->fixture_teams[] = (int) $team_id; }
		}
		$fixture_ok = $supervisor && $lead && $agent_a && $agent_b && $team_a && $team_b
			&& BizCity_CRM_Team_Manager::add_team_member( $team_a, $supervisor, 'supervisor' )
			&& BizCity_CRM_Team_Manager::add_team_member( $team_a, $lead, 'lead' )
			&& BizCity_CRM_Team_Manager::add_team_member( $team_a, $agent_a, 'agent' )
			&& BizCity_CRM_Team_Manager::add_team_member( $team_b, $agent_b, 'agent' );

		$can = static function ( int $actor, string $action, int $subject = 0 ): array {
			return BizCity_CRM_Staff_Policy::can( $actor, $action, $subject );
		};

		// 1. Lead manages a lower rank in the same team.
		$lead_own = $fixture_ok && $can( $lead, 'staff.view_workspace', $agent_a )['ok'];
		// 2. …but not an agent of another team.
		$lead_other = $fixture_ok ? $can( $lead, 'staff.view_workspace', $agent_b ) : array( 'ok' => true );
		$lead_other_ok = ! $lead_other['ok'] && 'different_team' === ( $lead_other['code'] ?? '' );
		// 3. …and not someone above them.
		$lead_up = $fixture_ok ? $can( $lead, 'staff.view_workspace', $supervisor ) : array( 'ok' => true );
		$lead_up_ok = ! $lead_up['ok'] && 'subject_not_lower_rank' === ( $lead_up['code'] ?? '' );
		// 4. An agent manages nobody.
		$agent_any = $fixture_ok ? $can( $agent_a, 'staff.view_workspace', $agent_b ) : array( 'ok' => true );
		$agent_ok = ! $agent_any['ok'] && array() === (array) BizCity_CRM_Staff_Policy::manageable_user_ids( $agent_a );
		// 5. QR on behalf is supervisor+ (lead refused), supervisor allowed on own team.
		$qr_ok = $fixture_ok && ! $can( $lead, 'phone.qr', $agent_a )['ok'] && $can( $supervisor, 'phone.qr', $agent_a )['ok'];
		// 6. Nobody suspends themselves; supervisor may suspend own agent.
		$self_suspend = $fixture_ok ? $can( $supervisor, 'staff.suspend', $supervisor ) : array( 'ok' => true );
		$suspend_ok = ! $self_suspend['ok'] && 'self_not_allowed' === ( $self_suspend['code'] ?? '' ) && $can( $supervisor, 'staff.suspend', $agent_a )['ok'];

		// 7. POST /crm-staff as the supervisor asking for a supervisor (peer rank) is refused before any user is created.
		$create_detail = 'not run';
		$create_ok = false;
		if ( $fixture_ok && function_exists( 'rest_do_request' ) && function_exists( 'wp_set_current_user' ) ) {
			$email = 'healthtest-sp-create-' . strtolower( substr( md5( wp_generate_uuid4() ), 0, 10 ) ) . '@invalid.test';
			$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? (string) BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
			wp_set_current_user( $supervisor );
			try {
				$request = new WP_REST_Request( 'POST', '/' . $ns . '/crm-staff' );
				$request->set_param( 'email', $email );
				$request->set_param( 'team_role', 'supervisor' );
				$response = rest_do_request( $request );
			} finally {
				wp_set_current_user( $operator_id );
			}
			$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
			$created = get_user_by( 'email', $email );
			if ( $created ) { $this->fixture_users[] = (int) $created->ID; }
			$code = sanitize_key( (string) ( $data['code'] ?? '' ) );
			$create_ok = ! $created && in_array( $code, array( 'subject_not_lower_rank', 'rank_insufficient' ), true );
			$create_detail = 'status=' . ( $response instanceof WP_REST_Response ? (int) $response->get_status() : 0 ) . ', code=' . $code . ', user_created=' . ( $created ? 'yes' : 'no' );
		}

		// 8. Workspace read audit: first read writes one row, a second read the same day writes none.
		$first  = $fixture_ok && BizCity_CRM_Staff_REST::record_workspace_read( $lead, $agent_a, 'ddv_probe' );
		$second = $fixture_ok && BizCity_CRM_Staff_REST::record_workspace_read( $lead, $agent_a, 'ddv_probe' );
		$self   = $fixture_ok && BizCity_CRM_Staff_REST::record_workspace_read( $agent_a, $agent_a, 'ddv_probe' );
		$audit_ok = $first && ! $second && ! $self;

		// 9. The C task route still drops a user selector (R-TWEB, R-LM-3).
		$base = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$c_rest = $base . '/modules/twinweb/includes/class-twinweb-crm-tasks-rest.php';
		$c_source = is_readable( $c_rest ) ? (string) file_get_contents( $c_rest ) : '';
		$selector_ok = '' !== $c_source && false !== strpos( $c_source, 'c_user_selector_ignored' );

		$checks = array(
			array( 'label' => 'Fixture teams built', 'ok' => (bool) $fixture_ok, 'detail' => $fixture_ok ? 'Team A (supervisor, lead, agent) and team B (agent) created.' : 'Could not create the throw-away users/teams.' ),
			array( 'label' => 'Lead manages a lower rank in the same team', 'ok' => $lead_own, 'detail' => $lead_own ? 'staff.view_workspace allowed lead → agent (team A).' : 'Lead was refused on their own agent.' ),
			array( 'label' => 'Lead is refused on another team', 'ok' => $lead_other_ok, 'detail' => 'code=' . sanitize_key( (string) ( $lead_other['code'] ?? 'allowed' ) ) ),
			array( 'label' => 'Lead is refused on a higher rank', 'ok' => $lead_up_ok, 'detail' => 'code=' . sanitize_key( (string) ( $lead_up['code'] ?? 'allowed' ) ) ),
			array( 'label' => 'Agent manages nobody', 'ok' => $agent_ok, 'detail' => $agent_ok ? 'Agent refused and roster empty.' : 'Agent was allowed or has a roster.' ),
			array( 'label' => 'QR on behalf needs supervisor', 'ok' => $qr_ok, 'detail' => $qr_ok ? 'Lead refused, supervisor allowed on own agent.' : 'phone.qr floor is not supervisor.' ),
			array( 'label' => 'No acting on yourself', 'ok' => $suspend_ok, 'detail' => 'self suspend code=' . sanitize_key( (string) ( $self_suspend['code'] ?? 'allowed' ) ) ),
			array( 'label' => 'Supervisor cannot create a peer rank', 'ok' => $create_ok, 'detail' => $create_detail ),
			array( 'label' => 'Workspace read audit is one row per day', 'ok' => $audit_ok, 'detail' => 'first=' . ( $first ? 'written' : 'none' ) . ', second=' . ( $second ? 'written' : 'none' ) . ', self=' . ( $self ? 'written' : 'none' ) ),
			array( 'label' => 'C task route ignores user selectors', 'ok' => $selector_ok, 'detail' => $selector_ok ? 'c_user_selector_ignored is emitted by the /gpt/ task route.' : 'The /gpt/ task route no longer carries the selector guard.' ),
		);

		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Staff_Policy rank/team/self rules, staff-create gate and read audit hold on this site.' : 'Staff_Policy matrix checks failed.',
			'fix_hint' => $pass ? '' : 'Every staff mutation and workspace read must go through Staff_Policy::can() (R-CRMF-2/3) — see PHASE-0.48F §4B.',
			'steps'    => array(),
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$members = BizCity_CRM_DB_Installer_V2::tbl_team_members();
			$teams   = BizCity_CRM_DB_Installer_V2::tbl_teams();
			foreach ( $this->fixture_teams as $team_id ) {
				$wpdb->delete( $members, array( 'team_id' => (int) $team_id ), array( '%d' ) );
				$wpdb->delete( $teams, array( 'id' => (int) $team_id ), array( '%d' ) );
			}
			$audit = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
			foreach ( $this->fixture_users as $user_id ) {
				$wpdb->delete( $members, array( 'user_id' => (int) $user_id ), array( '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$audit}` WHERE event_uuid LIKE %s", 'workspace_read:' . (int) $user_id . ':%' ) );
			}
		}
		if ( function_exists( 'wp_date' ) && count( $this->fixture_users ) >= 3 ) {
			delete_transient( 'bzc_ws_read_' . (int) $this->fixture_users[1] . '_' . (int) $this->fixture_users[2] . '_' . wp_date( 'Ymd' ) );
		}
		foreach ( $this->fixture_users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( (int) $user_id ); }
		}
		if ( class_exists( 'BizCity_CRM_Team_Manager' ) ) { BizCity_CRM_Team_Manager::flush_cache(); }
		$this->fixture_users = array();
		$this->fixture_teams = array();
	}

	private function create_user( $label ): int {
		if ( ! function_exists( 'wp_insert_user' ) ) { return 0; }
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user( array(
			'user_login' => '__healthtest_' . sanitize_key( $label ) . '_' . $suffix,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'healthtest-' . sanitize_key( $label ) . '-' . $suffix . '@invalid.test',
			'role'       => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) { return 0; }
		if ( function_exists( 'add_user_to_blog' ) && function_exists( 'get_current_blog_id' ) ) {
			add_user_to_blog( (int) get_current_blog_id(), (int) $user_id, 'subscriber' );
		}
		$this->fixture_users[] = (int) $user_id;
		return (int) $user_id;
	}
}

// [2026-09-18] PHASE-0.52 P52-T-02 — this probe was declared but never registered, so it never
// appeared in `wp bizcity probe list` (the catalog is built purely from this filter, see
// class-diagnostics-smoke-runner.php::catalog()). Missing this block is why it silently never ran.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Staff_Policy';
	return $list;
} );
