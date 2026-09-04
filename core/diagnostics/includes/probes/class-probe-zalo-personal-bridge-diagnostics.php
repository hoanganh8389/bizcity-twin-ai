<?php
/**
 * Focused D1B-Q probe for Zalo Personal QR operation readiness.
 *
 * This probe uses deterministic response fixtures only. It does not call the
 * sidecar, send email, mutate CRM rows or claim production readiness.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since PHASE-0.39E (2026-09-03)
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Zalo_Personal_Bridge_Diagnostics', false ) ) {
	return;
}

final class BizCity_Probe_Zalo_Personal_Bridge_Diagnostics implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.zalo-personal.bridge_diagnostics'; }
	public function label(): string { return 'Zalo Personal QR operation readiness'; }
	public function description(): string { return 'Checks D1B-Q QR response normalization, payload compatibility, degraded semantics, correlation and notification-owner wiring without provider transport.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 46; }
	public function icon(): string { return 'qr-code'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) || ! method_exists( 'BizCity_Zalo_Bridge_REST', 'normalize_qr_result' ) ) {
			return new WP_Error( 'zalo_qr_normalizer_missing', 'Zalo QR operation normalizer is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 11:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.39E-D1B-Q — prove QR operation response boundaries with deterministic fixtures and no provider side effects.
		$steps = array();
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$rest_file = $root . 'plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-rest.php';
		$dispatcher_file = $root . 'core/channel-gateway/includes/class-notify-dispatcher.php';
		$hub_file = $root . '../bizcity-llm-router/includes/class-router-zalo-personal-bridge-rest.php';
		$sidecar_file = $root . 'plugins/bizcity-zalo-personal/_library/zca-bridge-main/src/wp/wpRoutes.ts';
		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$dispatcher_source = is_readable( $dispatcher_file ) ? (string) file_get_contents( $dispatcher_file ) : '';
		$hub_source = is_readable( $hub_file ) ? (string) file_get_contents( $hub_file ) : '';
		$sidecar_source = is_readable( $sidecar_file ) ? (string) file_get_contents( $sidecar_file ) : '';
		$disk_ok = $rest_source !== ''
			&& strpos( $rest_source, 'function normalize_qr_result' ) !== false
			&& strpos( $rest_source, 'qrImageBase64' ) !== false
			&& strpos( $rest_source, 'qr_response_empty' ) !== false
			&& strpos( $rest_source, 'qr/reset' ) !== false
			&& strpos( $hub_source, 'qr/reset' ) !== false
			&& strpos( $sidecar_source, '/wp/accounts/:id/qr/reset' ) !== false
			&& $dispatcher_source !== ''
			&& strpos( $dispatcher_source, 'function on_qr_operation_result' ) !== false
			&& strpos( $dispatcher_source, 'bridge_qr_operation_failed' ) !== false
			&& strpos( $dispatcher_source, 'bridge_qr_operation_recovered' ) !== false
			&& strpos( $dispatcher_source, 'wp_mail()' ) !== false
			&& strpos( $dispatcher_source, 'if ( $smtp_uid === \'\' )' ) === false;
		$emit( 'Disk - QR normalizer and independent notification owner exist', $disk_ok, $disk_ok ? 'REST normalizer, payload compatibility and QR alert owner artifacts are present.' : 'QR normalizer or independent notification owner artifact is missing.' );

		$loader_ok = class_exists( 'BizCity_Zalo_Bridge_REST', false )
			&& method_exists( 'BizCity_Zalo_Bridge_REST', 'normalize_qr_result' )
			&& class_exists( 'BizCity_Notify_Dispatcher', false )
			&& method_exists( 'BizCity_Notify_Dispatcher', 'on_qr_operation_result' );
		$emit( 'Loader - REST normalizer and notification hook are available', $loader_ok, $loader_ok ? 'Active classes expose the D1B-Q operation boundary and existing notification owner.' : 'D1B-Q classes or notification hook are not loaded.' );

		$operation_id = 'probe-operation';
		$request_id = 'probe-request';
		$empty_failure = BizCity_Zalo_Bridge_REST::normalize_qr_result( array(
			'ok' => false,
			'_degraded' => true,
			'message' => 'raw upstream response must not cross boundary',
			'error' => 'raw provider detail must not cross boundary',
		), $operation_id, $request_id );
		$empty_ok = empty( $empty_failure['ok'] )
			&& ! empty( $empty_failure['_degraded'] )
			&& (string) ( $empty_failure['code'] ?? '' ) === 'qr_operation_failed'
			&& (string) ( $empty_failure['reason_bucket'] ?? '' ) === 'qr_response_empty'
			&& (string) ( $empty_failure['operation_id'] ?? '' ) === $operation_id
			&& (string) ( $empty_failure['request_id'] ?? '' ) === $request_id
			&& strpos( wp_json_encode( $empty_failure ), 'raw upstream' ) === false
			&& strpos( wp_json_encode( $empty_failure ), 'raw provider' ) === false;
		$emit( 'Runtime - 200 degraded and empty upstream message are explicit', $empty_ok, $empty_ok ? 'Empty QR failure remains degraded with safe R-ERROR-UX fields and opaque correlation.' : 'Empty QR failure was accepted, leaked upstream detail or lost correlation.' );

		$image_result = BizCity_Zalo_Bridge_REST::normalize_qr_result( array(
			'ok' => true,
			'success' => true,
			'qrImageBase64' => 'data:image/png;base64,probe-fixture',
		), $operation_id, $request_id );
		$image_ok = ! empty( $image_result['ok'] )
			&& (string) ( $image_result['qr_base64'] ?? '' ) === 'data:image/png;base64,probe-fixture'
			&& (string) ( $image_result['reason_bucket'] ?? '' ) === 'qr_generated';
		$emit( 'Runtime - qrImageBase64 maps to canonical qr_base64', $image_ok, $image_ok ? 'Sidecar-compatible QR payload is exposed only under the canonical response key.' : 'Sidecar QR payload key was not normalized.' );

		$empty_payload = BizCity_Zalo_Bridge_REST::normalize_qr_result( array( 'ok' => true, 'success' => true, 'qr_base64' => '' ), $operation_id, $request_id );
		$empty_payload_ok = empty( $empty_payload['ok'] ) && ! empty( $empty_payload['_degraded'] ) && (string) ( $empty_payload['reason_bucket'] ?? '' ) === 'qr_response_empty';
		$emit( 'Runtime - success without QR payload is rejected', $empty_payload_ok, $empty_payload_ok ? 'A transport success with an empty image cannot claim QR readiness.' : 'An empty QR payload was incorrectly accepted as success.' );

		$stage_result = BizCity_Zalo_Bridge_REST::normalize_qr_result( array( 'ok' => false, '_degraded' => true, 'stage' => 'relay', 'code' => 'relay_auth_failed' ), $operation_id, $request_id );
		$stage_ok = (string) ( $stage_result['stage'] ?? '' ) === 'relay' && (string) ( $stage_result['reason_bucket'] ?? '' ) === 'relay_auth_failed' && (string) ( $stage_result['operation_status'] ?? '' ) === 'degraded';
		$emit( 'Runtime - first failing stage and operation status survive', $stage_ok, $stage_ok ? 'Relay failures remain distinct from QR payload failures and are explicitly degraded.' : 'The operation stage or degraded status was overwritten by a generic QR failure.' );

		$connected_result = BizCity_Zalo_Bridge_REST::normalize_qr_result( array( 'ok' => false, '_degraded' => true, 'error' => 'already_connected', 'stage' => 'session' ), $operation_id, $request_id );
		$connected_ok = empty( $connected_result['_degraded'] )
			&& (string) ( $connected_result['operation_status'] ?? '' ) === 'blocked'
			&& (string) ( $connected_result['code'] ?? '' ) === 'invalid_param'
			&& (string) ( $connected_result['reason_bucket'] ?? '' ) === 'already_connected'
			&& (string) ( $connected_result['help_code'] ?? '' ) === 'invalid_param_generic';
		$emit( 'Runtime - already-connected session is blocked without incident degradation', $connected_ok, $connected_ok ? 'An active session is presented as an actionable user state and does not enter QR outage alerting.' : 'An active session was incorrectly classified as a degraded QR incident.' );

		$state_scope = 'probe-' . substr( md5( wp_generate_uuid4() ), 0, 16 );
		$state_key = 'bizcity_zalo_qr_state_' . substr( md5( ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '|' . $state_scope ), 0, 24 );
		$state_payload = array( 'account_id_hash' => $state_scope, 'operation_id' => $operation_id, 'request_id' => $request_id, 'stage' => 'qr_generation', 'reason' => 'qr_response_empty' );
		$pre_wp_mail = function () { return true; };
		add_filter( 'pre_wp_mail', $pre_wp_mail );
		$failure_transition_ok = false;
		$recovery_transition_ok = false;
		try {
			BizCity_Notify_Dispatcher::on_qr_operation_result( array_merge( $state_payload, array( 'state' => 'qr_operation_failed' ) ) );
			BizCity_Notify_Dispatcher::on_qr_operation_result( array_merge( $state_payload, array( 'state' => 'qr_operation_failed' ) ) );
			$failed_state = get_transient( $state_key );
			$failure_transition_ok = is_array( $failed_state ) && ! empty( $failed_state['incident_active'] ) && (int) ( $failed_state['failures'] ?? 0 ) === 2;
			BizCity_Notify_Dispatcher::on_qr_operation_result( array_merge( $state_payload, array( 'state' => 'qr_operation_success' ) ) );
			BizCity_Notify_Dispatcher::on_qr_operation_result( array_merge( $state_payload, array( 'state' => 'qr_operation_success' ) ) );
			$recovered_state = get_transient( $state_key );
			$recovery_transition_ok = is_array( $recovered_state ) && empty( $recovered_state['incident_active'] ) && (int) ( $recovered_state['successes'] ?? 0 ) === 2;
		} finally {
			remove_filter( 'pre_wp_mail', $pre_wp_mail );
			delete_transient( $state_key );
		}
		$state_ok = $failure_transition_ok && $recovery_transition_ok;
		$emit( 'Runtime - QR alert transitions use 2 failures and 2 successes', $state_ok, $state_ok ? 'The existing notification owner opens after two failures and recovers after two successes.' : 'QR alert thresholds did not preserve the independent incident state.' );

		$no_direct_fallback = strpos( $rest_source, 'wp_remote_post' ) === false && strpos( $rest_source, 'bridgeUrl' ) === false;
		$emit( 'Runtime - WordPress REST boundary has no direct sidecar fallback', $no_direct_fallback, $no_direct_fallback ? 'Browser-facing QR route delegates through the server-side bridge client only.' : 'Direct sidecar transport marker found in the QR REST boundary.' );

		$pass = $disk_ok && $loader_ok && $empty_ok && $image_ok && $empty_payload_ok && $stage_ok && $connected_ok && $state_ok && $no_direct_fallback;
		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'D1B-Q fixture checks passed for QR response normalization and bounded failure semantics.' : 'D1B-Q fixture checks failed; QR operation readiness remains degraded.',
			'fix_hint' => $pass ? '' : 'Keep control health separate, normalize QR payloads at the server boundary and preserve reason/correlation without upstream body leakage.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalo_Personal_Bridge_Diagnostics';
	return $list;
} );
