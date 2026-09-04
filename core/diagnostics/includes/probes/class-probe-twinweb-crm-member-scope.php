<?php
/**
 * DDV probe for the Twin GPT member-safe CRM projection.
 *
 * The probe only checks the loaded route and invokes the read-only projection
 * with the current authenticated identity. It never creates or mutates CRM
 * rows and never uses a posted owner or inbox id.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-25
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Member_Scope', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Member_Scope implements BizCity_Diagnostics_Probe {

	public static function register( $probes ) {
		if ( ! in_array( 'BizCity_Probe_TwinWeb_CRM_Member_Scope', $probes, true ) ) {
			$probes[] = 'BizCity_Probe_TwinWeb_CRM_Member_Scope';
		}
		return $probes;
	}

	public function id(): string { return 'modules.twin_gpt.crm_member_scope'; }
	public function label(): string { return 'Twin GPT member-safe CRM scope'; }
	public function description(): string { return 'Kiểm tra identity-first CRM projection cho conversation, care task và Woo order mà không mở quyền tenant-wide cho /gpt/.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 66; }
	public function icon(): string { return 'user-round-check'; }
	public function estimate_ms(): int { return 500; }
	public function precondition() { return true; }
	public function cleanup(): void {}

	public function run( $ctx ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8-DDV — verify member scope, route and response redaction without mutation.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$backend_files = array(
			'modules/twinweb/includes/class-twinweb-crm-projection.php',
			'modules/twinweb/includes/class-twinweb-rest.php',
			'modules/twinweb/includes/class-twinweb-identity.php',
			'plugins/bizcity-twin-crm/includes/class-inbox-access.php',
			'plugins/bizcity-twin-crm/includes/class-repository.php',
		);
		$frontend_files = array(
			'modules/twinweb/ui/src/pages/MyChannelsPage.tsx',
		);
		$missing_backend = array();
		foreach ( $backend_files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) { $missing_backend[] = $relative; }
		}
		$steps[] = array(
			'layer' => 'Disk',
			'label' => 'F8 member projection backend artifacts exist',
			'status' => empty( $missing_backend ) ? 'pass' : 'fail',
			'detail' => empty( $missing_backend ) ? implode( ', ', $backend_files ) : implode( ', ', $missing_backend ),
		);
		if ( ! empty( $missing_backend ) ) {
			return array( 'status' => 'fail', 'summary' => 'F8 member projection artifacts are incomplete.', 'steps' => $steps );
		}

		$missing_frontend = array();
		foreach ( $frontend_files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) { $missing_frontend[] = $relative; }
		}
		$steps[] = array(
			'layer' => 'Disk',
			'label' => 'F8 member projection frontend source',
			'status' => empty( $missing_frontend ) ? 'pass' : 'skip',
			'detail' => empty( $missing_frontend )
				? 'Development source file is present.'
				: 'Development source is absent; built Twin GPT dist/runtime remains authoritative: ' . implode( ', ', $missing_frontend ),
		);

		$loaded = class_exists( 'BizCity_TwinWeb_REST' )
			&& class_exists( 'BizCity_TwinWeb_CRM_Projection' )
			&& class_exists( 'BizCity_TwinWeb_Identity' )
			&& class_exists( 'BizCity_CRM_Inbox_Access' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_member_projection' );
		$steps[] = array(
			'layer' => 'Loader',
			'label' => 'TwinWeb identity, REST and CRM projection classes are loaded',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'Identity-first handler and projection service are available.' : 'One or more F8 classes/methods are not loaded.',
		);
		if ( ! $loaded ) {
			return array( 'status' => 'fail', 'summary' => 'F8 member projection is not loaded.', 'steps' => $steps );
		}

		$route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$route_ok = isset( $routes['/bizcity-twinweb/v1/crm/me'] );
		}
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Member CRM route is registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? '/bizcity-twinweb/v1/crm/me' : 'Route missing from rest_get_server().',
		);

		$identity = BizCity_TwinWeb_Identity::current();
		$identity_ok = is_array( $identity ) && (int) ( $identity['user_id'] ?? 0 ) > 0 && empty( $identity['is_guest'] );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Authenticated identity is resolved before member projection',
			'status' => $identity_ok ? 'pass' : 'fail',
			'detail' => $identity_ok ? 'user_id resolved; guest path is not admitted to private CRM projection.' : 'No authenticated TwinWeb identity available.',
		);
		if ( ! $identity_ok ) {
			return array( 'status' => 'fail', 'summary' => 'F8 identity-first precondition failed.', 'steps' => $steps );
		}

		$projection = BizCity_TwinWeb_CRM_Projection::get_for_identity( $identity, 5 );
		$shape_ok = is_array( $projection )
			&& true === ( $projection['member_safe'] ?? false )
			&& isset( $projection['scope']['owner_user_id'] )
			&& (int) $projection['scope']['owner_user_id'] === (int) $identity['user_id']
			&& isset( $projection['conversations'], $projection['care_tasks'], $projection['orders'] )
			&& is_array( $projection['conversations'] )
			&& is_array( $projection['care_tasks'] )
			&& is_array( $projection['orders'] );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Projection is member-safe and shaped for CRM views',
			'status' => $shape_ok ? 'pass' : 'fail',
			'detail' => $shape_ok ? 'owner_user_id matches resolved identity; conversations/tasks/orders arrays returned.' : 'Unexpected member projection shape or owner mismatch.',
		);

		// [2026-08-25 Johnny Chu] PHASE-0.39F-S1 — verify forged identity and posted scope IDs cannot widen member projection.
		$forged_identity = $identity;
		$forged_identity['user_id'] = (int) $identity['user_id'] + 1;
		$forged_projection = BizCity_TwinWeb_CRM_Projection::get_for_identity( $forged_identity, 5 );
		$identity_mismatch_ok = is_array( $forged_projection )
			&& ! empty( $forged_projection['_degraded'] )
			&& 'crm_identity_mismatch' === (string) ( $forged_projection['reason'] ?? '' )
			&& empty( $forged_projection['conversations'] )
			&& empty( $forged_projection['care_tasks'] )
			&& empty( $forged_projection['orders'] );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Forged projection identity is rejected before CRM reads',
			'status' => $identity_mismatch_ok ? 'pass' : 'fail',
			'detail' => $identity_mismatch_ok ? 'Mismatched user_id returns degraded empty projection.' : 'Mismatched identity was not rejected fail-closed.',
		);

		$posted_scope_ok = false;
		if ( class_exists( 'WP_REST_Request' ) ) {
			$forged_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/me' );
			$forged_request->set_param( 'owner_id', (int) $identity['user_id'] + 1 );
			$forged_request->set_param( 'inbox_id', 999999999 );
			$forged_request->set_param( 'account_id', 'forged-account' );
			$forged_response = BizCity_TwinWeb_REST::instance()->get_crm_member_projection( $forged_request );
			$forged_data = is_object( $forged_response ) && method_exists( $forged_response, 'get_data' )
				? $forged_response->get_data()
				: array();
			$posted_scope_ok = is_array( $forged_data )
				&& ! empty( $forged_data['success'] )
				&& isset( $forged_data['data']['scope']['owner_user_id'] )
				&& (int) $forged_data['data']['scope']['owner_user_id'] === (int) $identity['user_id'];
		}
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Posted owner/inbox/account IDs cannot widen member scope',
			'status' => $posted_scope_ok ? 'pass' : 'fail',
			'detail' => $posted_scope_ok ? 'Projection owner remains the resolved current user.' : 'Forged request scope parameters changed or obscured the owner projection.',
		);

		$private_ok = true;
		$forbidden = array( 'team_members', 'assignment_policy', 'private_notes', 'provider_token', 'access_token' );
		$encoded = wp_json_encode( $projection );
		foreach ( $forbidden as $needle ) {
			if ( false !== strpos( strtolower( (string) $encoded ), $needle ) ) { $private_ok = false; break; }
		}
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Projection does not expose operator/private credential fields',
			'status' => $private_ok ? 'pass' : 'fail',
			'detail' => $private_ok ? 'No team administration, assignment policy or provider credential field found.' : 'Forbidden private field found in projection.',
		);

		$status = $route_ok && $identity_ok && $shape_ok && $identity_mismatch_ok && $posted_scope_ok && $private_ok ? 'pass' : 'fail';
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'Twin GPT member-safe CRM projection contract passed without mutation.' : 'Twin GPT member-safe CRM projection has runtime gaps.',
			'fix_hint' => 'Resolve identity before CRM query and keep member projection scoped to contact ownership plus resolver-derived inbox scope.',
			'steps' => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_TwinWeb_CRM_Member_Scope', 'register' ), 10, 1 );
