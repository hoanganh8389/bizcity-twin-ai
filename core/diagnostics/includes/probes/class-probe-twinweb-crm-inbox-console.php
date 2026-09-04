<?php
/**
 * Read-only DDV probe for the W7.7 exact-account Twin GPT CRM console path.
 *
 * The probe checks route ownership and safe negative handling. It does not
 * create CRM rows, send messages or call a provider.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_TwinWeb_CRM_Inbox_Console', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_CRM_Inbox_Console implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.crm_inbox_console'; }
	public function label(): string { return 'Twin GPT exact CRM inbox console'; }
	public function description(): string { return 'Kiểm tra route read-only /gpt/crm exact account, identity-first scope và fail-closed input.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 71; }
	public function icon(): string { return 'messages-square'; }
	public function estimate_ms(): int { return 200; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7 — prove the read-only C-surface route rejects invalid/unauthorized account selectors before CRM data access.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_path = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$source = is_readable( $rest_path ) ? (string) file_get_contents( $rest_path ) : '';
		$disk_ok = $source !== '' && strpos( $source, "'/crm/inbox'" ) !== false && strpos( $source, 'get_crm_exact_inbox' ) !== false;
		$steps[] = array(
			'label'  => 'Disk - exact CRM console route artifact is readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'TwinWeb contains the read-only /crm/inbox route and callback.' : 'Exact CRM console route artifact is missing or unreadable.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Twin GPT CRM console route is missing.', 'fix_hint' => 'Register the read-only exact-account route before adding UI.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_TwinWeb_REST', false )
			&& method_exists( 'BizCity_TwinWeb_REST', 'register_routes' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_crm_exact_inbox' )
			&& class_exists( 'BizCity_CRM_Repository', false )
			&& method_exists( 'BizCity_CRM_Repository', 'get_inbox_by_ref' )
			&& class_exists( 'BizCity_CRM_Inbox_Access', false )
			&& method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_scope' );
		$steps[] = array(
			'label'  => 'Loader - TwinWeb route and canonical scope owners are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Route callback, exact repository reader and C-surface scope resolver are available.' : 'TwinWeb route or canonical scope dependency is unavailable.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Twin GPT CRM console dependencies are incomplete.', 'fix_hint' => 'Load TwinWeb, CRM repository and Inbox Access before registering the route.', 'steps' => $steps );
		}

		$invalid_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
		$invalid_request->set_param( 'channel', 'zalo_bot' );
		$invalid_request->set_param( 'ref', 'probe-invalid-zone' );
		$invalid_result = BizCity_TwinWeb_REST::instance()->get_crm_exact_inbox( $invalid_request );
		$invalid_code = is_wp_error( $invalid_result ) ? $invalid_result->get_error_code() : ( is_object( $invalid_result ) && method_exists( $invalid_result, 'get_data' ) ? (string) ( $invalid_result->get_data()['code'] ?? '' ) : '' );
		$invalid_ok = in_array( $invalid_code, array( 'invalid_param', 'auth_required', 'permission_denied' ), true );
		$steps[] = array(
			'label'  => 'Runtime - unsupported Zone 2 selector fails closed',
			'status' => $invalid_ok ? 'pass' : 'fail',
			'detail' => $invalid_ok ? 'zalo_bot is rejected by the C-surface channel allowlist before a CRM result is returned.' : 'Unsupported channel selector was not rejected.',
		);

		$scope = BizCity_CRM_Inbox_Access::resolve_scope( (int) get_current_user_id(), 'c' );
		$allowed = isset( $scope['inbox_ids'] ) && is_array( $scope['inbox_ids'] ) ? array_map( 'intval', $scope['inbox_ids'] ) : array();
		$foreign_ref = 'probe-foreign-account-' . substr( md5( (string) get_current_blog_id() ), 0, 10 );
		$foreign_request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/crm/inbox' );
		$foreign_request->set_param( 'channel', 'facebook' );
		$foreign_request->set_param( 'ref', $foreign_ref );
		$foreign_result = BizCity_TwinWeb_REST::instance()->get_crm_exact_inbox( $foreign_request );
		$foreign_data = is_object( $foreign_result ) && method_exists( $foreign_result, 'get_data' ) ? (array) $foreign_result->get_data() : array();
		$foreign_code = is_wp_error( $foreign_result ) ? $foreign_result->get_error_code() : (string) ( $foreign_data['code'] ?? '' );
		$foreign_ok = in_array( $foreign_code, array( 'permission_denied', 'auth_required' ), true )
			&& ( empty( $allowed ) || ! empty( $scope['inbox_ids'] ) );
		$steps[] = array(
			'label'  => 'Runtime - foreign exact account does not enter the C-surface scope',
			'status' => $foreign_ok ? 'pass' : 'fail',
			'detail' => $foreign_ok ? 'A ref not resolved to an allowed inbox returns the explicit permission boundary.' : 'Foreign account selector was not rejected by the C-surface scope.',
		);

		$overall = $invalid_ok && $foreign_ok;
		return array(
			'status'  => $overall ? 'pass' : 'fail',
			'summary' => $overall ? 'Twin GPT exact CRM inbox console passed safe negative checks.' : 'Twin GPT exact CRM inbox console has scope failures.',
			'fix_hint'=> $overall ? '' : 'Resolve identity and C-surface inbox scope before reading the exact CRM account.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_CRM_Inbox_Console';
	return $probes;
} );
