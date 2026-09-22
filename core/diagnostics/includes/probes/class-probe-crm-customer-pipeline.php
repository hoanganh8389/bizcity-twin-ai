<?php
/**
 * DDV probe for PHASE-0.52 R-PIPE — `core.crm.customer_pipeline`.
 *
 * Proves on a live site: the pipeline read model + the one writer are loaded, B2 and C routes are served,
 * tenant settings default to a 3-day stuck threshold, the resolver keeps "newer facts only push up",
 * a member (C) cannot change a customer outside their own channels and nothing is written when refused,
 * leaders set monthly goals (Staff_Policy `staff.set_goal`, not on yourself), and the 5 public contracts exist.
 *
 * Side effects: one disposable subscriber user, deleted in cleanup(). No contact, opportunity, task or audit row.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Customer_Pipeline', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Customer_Pipeline implements BizCity_Diagnostics_Probe {

	/** @var int[] */
	private $fixture_users = array();

	public function id(): string { return 'core.crm.customer_pipeline'; }
	public function label(): string { return 'CRM customer pipeline (PHASE-0.52)'; }
	public function description(): string { return 'Checks the customer pipeline services, B2/C routes, default settings, push-up-only resolution, member scope refusal without writes, goal policy and public contracts.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'kanban'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		foreach ( array( 'BizCity_CRM_Customer_Pipeline', 'BizCity_CRM_Pipeline_Stage_Service', 'BizCity_CRM_Staff_Policy', 'BizCity_CRM_DB_Installer_V2' ) as $class ) {
			if ( ! class_exists( $class ) ) { return new WP_Error( 'crm_pipeline_dependency_missing', $class . ' is not loaded.' ); }
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'crm_pipeline_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;

		// 1. Routes (B2 + C).
		$routes = function_exists( 'rest_get_server' ) ? array_keys( (array) rest_get_server()->get_routes() ) : array();
		$crm = '/' . ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1' );
		$tw = '/bizcity-twinweb/v1';
		$expected = array(
			$crm . '/crm-pipeline/board', $crm . '/crm-pipeline/contacts/(?P<id>\d+)', $crm . '/crm-contacts/(?P<id>\d+)/stage',
			$crm . '/crm-pipeline/segments', $crm . '/crm-tasks/load', $crm . '/crm-settings/pipeline', $crm . '/crm-staff/(?P<id>\d+)/space', $crm . '/crm-staff/(?P<id>\d+)/goal',
			$tw . '/crm/pipeline', $tw . '/crm/pipeline/today', $tw . '/crm/pipeline/contacts/(?P<id>\d+)/stage', $tw . '/crm/me/space',
		);
		$missing = array_values( array_diff( $expected, $routes ) );
		$routes_ok = empty( $missing ) && ! empty( $routes );

		// 2. Settings: default 3 days for every open stage (R-PIPE-8, Q52-2).
		$defaults = BizCity_CRM_Customer_Pipeline::default_settings();
		$settings_ok = array( 3, 3, 3, 3 ) === array_values( array_intersect_key( $defaults['stuck_days'], array_flip( BizCity_CRM_Customer_Pipeline::OPEN_STAGES ) ) );

		// 3. Resolver: manual won without an order is allowed; a newer order pushes up; an older fact never pulls down.
		$now = time();
		$d = DAY_IN_SECONDS;
		$s = BizCity_CRM_Customer_Pipeline::default_settings();
		$a = BizCity_CRM_Customer_Pipeline::resolve( array( 'created_ts' => $now - 40 * $d, 'first_out_ts' => $now - 5 * $d ), array( 'stage' => 'won', 'at_ts' => $now - $d, 'source' => 'manual' ), $now, $s );
		$b = BizCity_CRM_Customer_Pipeline::resolve( array( 'created_ts' => $now - 40 * $d, 'paid' => 1, 'first_paid_ts' => $now - $d, 'last_order_ts' => $now - $d ), array( 'stage' => 'quote', 'at_ts' => $now - 5 * $d, 'source' => 'manual' ), $now, $s );
		$c = BizCity_CRM_Customer_Pipeline::resolve( array( 'created_ts' => $now - 40 * $d, 'first_out_ts' => $now - $d ), array( 'stage' => 'consult', 'at_ts' => $now - 3 * $d, 'source' => 'manual' ), $now, $s );
		$resolver_ok = 'won' === $a['stage'] && $a['manual_won_pending'] && 'won' === $b['stage'] && 'consult' === $c['stage'];

		// 4. Member scope: a user with no channels cannot change any customer, and nothing is written.
		$fixture = $this->create_user( 'pipeline' );
		$ct_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$opp_t = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$any_contact = (int) $wpdb->get_var( "SELECT id FROM `{$ct_t}` WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1" );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$opp_t}`" );
		$refusal = $fixture > 0 && $any_contact > 0 ? BizCity_CRM_Pipeline_Stage_Service::change( $fixture, $any_contact, array( 'to' => 'won' ), 'c' ) : null;
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$opp_t}`" );
		$scope_ok = $any_contact <= 0 || ( is_wp_error( $refusal ) && 'contact_not_in_scope' === $refusal->get_error_code() && $before === $after );

		// 5. Goal policy: leader+ action, never on yourself.
		$min = (array) BizCity_CRM_Staff_Policy::MIN_RANK;
		$goal_ok = 2 === (int) ( $min['staff.set_goal'] ?? 0 ) && in_array( 'staff.set_goal', (array) BizCity_CRM_Staff_Policy::SELF_FORBIDDEN, true );

		// 6. Public contracts.
		$base = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$schema_root = $base . '/core/twin-core/contracts/schema/public/v1';
		$missing_contracts = array();
		foreach ( array( 'care-outcome', 'pipeline-stage-change', 'customer-pipeline-board', 'member-pipeline', 'member-space' ) as $cid ) {
			if ( ! is_readable( $schema_root . '/' . $cid . '.schema.json' ) || ! is_readable( $schema_root . '/fixtures/' . $cid . '.valid.json' ) ) { $missing_contracts[] = $cid; }
		}
		$contracts_ok = empty( $missing_contracts );

		$checks = array(
			array( 'label' => 'Pipeline routes are served (B2 + C)', 'ok' => $routes_ok, 'detail' => $routes_ok ? '8 B2 and 4 C routes registered.' : 'Missing: ' . implode( ', ', array_map( 'sanitize_text_field', $missing ) ) ),
			array( 'label' => 'Stuck threshold defaults to 3 days', 'ok' => $settings_ok, 'detail' => $settings_ok ? 'Every open stage defaults to 3 days (editable in settings).' : 'Default stuck days are not 3.' ),
			array( 'label' => 'Resolver: manual moves allowed, newer facts only push up', 'ok' => $resolver_ok, 'detail' => $resolver_ok ? 'Manual won kept (pending order), newer order promotes quote → won, older message does not pull consult down.' : 'Resolution rules differ from R-PIPE-2.' ),
			array( 'label' => 'Member cannot change a customer outside their channels', 'ok' => $scope_ok, 'detail' => $scope_ok ? ( $any_contact > 0 ? 'Refused with contact_not_in_scope; no opportunity written.' : 'No contact on this site to test against.' ) : 'Refusal code=' . ( is_wp_error( $refusal ) ? sanitize_key( $refusal->get_error_code() ) : 'none' ) . ', opportunities before=' . $before . ', after=' . $after ),
			array( 'label' => 'Monthly goal is set by the leader', 'ok' => $goal_ok, 'detail' => $goal_ok ? 'staff.set_goal needs lead+ and is forbidden on yourself.' : 'staff.set_goal policy missing or allows self.' ),
			array( 'label' => 'R-PIPE public contracts on disk', 'ok' => $contracts_ok, 'detail' => $contracts_ok ? '5 schemas with fixtures present.' : 'Missing: ' . implode( ', ', $missing_contracts ) ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Customer pipeline services, routes, scope and policy hold.' : 'Customer pipeline checks failed.',
			'fix_hint' => $pass ? '' : 'See docs/rules/PHASE-0-RULE-CUSTOMER-PIPELINE.md (R-PIPE) and PHASE-0.52 §15.',
			'steps'    => array(),
		);
	}

	public function cleanup(): void {
		foreach ( $this->fixture_users as $uid ) { if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( (int) $uid ); } }
		$this->fixture_users = array();
	}

	private function create_user( string $label ): int {
		if ( ! function_exists( 'wp_insert_user' ) ) { return 0; }
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$uid = wp_insert_user( array( 'user_login' => '__healthtest_' . $label . '_' . $suffix, 'user_pass' => wp_generate_password( 32, true, true ), 'user_email' => 'healthtest-' . $label . '-' . $suffix . '@invalid.test', 'role' => 'subscriber' ) );
		if ( is_wp_error( $uid ) || (int) $uid <= 0 ) { return 0; }
		if ( function_exists( 'add_user_to_blog' ) ) { add_user_to_blog( (int) get_current_blog_id(), (int) $uid, 'subscriber' ); }
		$this->fixture_users[] = (int) $uid;
		return (int) $uid;
	}
}

// [2026-09-18] PHASE-0.52 P52-T-02 — this probe was declared but never registered, so it never
// appeared in `wp bizcity probe list` (the catalog is built purely from this filter, see
// class-diagnostics-smoke-runner.php::catalog()). Missing this block is why it silently never ran.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Customer_Pipeline';
	return $list;
} );
