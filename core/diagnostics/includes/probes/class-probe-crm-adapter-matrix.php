<?php
/**
 * Read-only matrix probe for every adapter in the CRM channel registry.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Adapter_Matrix', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Adapter_Matrix implements BizCity_Diagnostics_Probe {

	private const RESULT_KEYS = array(
		'success',
		'outcome',
		'code',
		'external_source_id',
		'error',
		'retryable',
		'channel_code',
		'contract_version',
	);

	public function id(): string { return 'core.channel.crm_adapter_matrix'; }
	public function label(): string { return 'CRM channel adapter contract matrix'; }
	public function description(): string { return 'Read-only descriptor, normalize, contract acceptance/rejection, and outbound result-shape checks for every registered CRM adapter.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 37; }
	public function icon(): string { return 'list-checks'; }
	public function estimate_ms(): int { return 250; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Channel_Registry' ) || ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
			return 'CRM channel registry or contract is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] R-CRM-ADAPTER-MATRIX — verify every registered adapter without provider transport or CRM writes.
		$adapters = BizCity_CRM_Channel_Registry::all();
		$issues = array();
		$enabled_count = 0;
		$disabled_count = 0;
		$required_result_keys = self::RESULT_KEYS;

		$registration_issues = BizCity_CRM_Channel_Registry::registration_issues();
		$ctx->emit_step( array(
			'label'  => 'Loader · registry provenance',
			'status' => empty( $registration_issues ) ? 'pass' : 'fail',
			'detail' => empty( $registration_issues ) ? count( $adapters ) . ' adapter(s) have matching registry key/code.' : implode( '; ', $registration_issues ),
		) );
		if ( ! empty( $registration_issues ) ) {
			return array(
				'status' => 'fail',
				'summary' => 'CRM adapter registry contains key/code mismatches.',
				'error' => 'adapter_registration_mismatch',
				'fix_hint' => 'Register each adapter under the exact sanitized value returned by adapter->code(), then rerun this matrix.',
			);
		}
		// [2026-09-01 Johnny Chu] R-CRM-ADAPTER-MATRIX - prevent UI/legacy labels from enabling CRM without an active adapter.
		foreach ( array( 'messenger', 'tiktok' ) as $planned_code ) {
			$planned_descriptor = BizCity_CRM_Channel_Contract::describe( $planned_code );
			$planned_ok = empty( $planned_descriptor['crm_enabled'] ) && ! BizCity_CRM_Channel_Registry::get( $planned_code );
			$ctx->emit_step( array(
				'label' => 'Unregistered channel gate · ' . $planned_code,
				'status' => $planned_ok ? 'pass' : 'fail',
				'detail' => $planned_ok ? 'No active adapter and CRM writes remain disabled.' : 'A planned/UI channel is CRM-enabled without a registered adapter.',
			) );
			if ( ! $planned_ok ) {
				$issues[] = $planned_code . ':unregistered_crm_enabled';
			}
		}
		// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-REST-UX — verify disabled Telegram setup routes return the standard envelope before any CRM/provider work.
		if ( class_exists( 'BizCity_CRM_REST_Controller' ) && class_exists( 'WP_REST_Request' ) ) {
			$rest_checks = array(
				'detail' => array( 'method' => 'get_channel_detail', 'request' => new WP_REST_Request( 'GET', '/bizcity-crm/v1/channels/telegram' ) ),
				'verify' => array( 'method' => 'post_channel_verify', 'request' => new WP_REST_Request( 'POST', '/bizcity-crm/v1/channels/telegram/verify' ) ),
				'create' => array( 'method' => 'post_inbox_create', 'request' => new WP_REST_Request( 'POST', '/bizcity-crm/v1/inboxes' ) ),
			);
			$rest_checks['detail']['request']->set_param( 'code', 'telegram' );
			$rest_checks['verify']['request']->set_param( 'code', 'telegram' );
			$rest_checks['verify']['request']->set_param( 'config', array() );
			$rest_checks['create']['request']->set_param( 'channel_type', 'telegram' );
			$rest_checks['create']['request']->set_param( 'config', array() );
			$rest_ok = true;
			foreach ( $rest_checks as $rest_name => $rest_check ) {
				try {
					$response = call_user_func( array( 'BizCity_CRM_REST_Controller', $rest_check['method'] ), $rest_check['request'] );
					$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
					$check_ok = $response instanceof WP_REST_Response
						&& (int) $response->get_status() === 400
						&& (string) ( $data['code'] ?? '' ) === 'channel_not_configured'
						&& (string) ( $data['message'] ?? '' ) !== ''
						&& (string) ( $data['hint'] ?? '' ) !== ''
						&& (string) ( $data['help_code'] ?? '' ) === 'channel_setup';
				} catch ( Throwable $e ) {
					$check_ok = false;
				}
				$ctx->emit_step( array(
					'label' => 'REST disabled UX · telegram/' . $rest_name,
					'status' => $check_ok ? 'pass' : 'fail',
					'detail' => $check_ok ? '400 channel_not_configured with message/hint/help_code; no setup or provider call.' : 'Route did not return the standard disabled-channel envelope.',
				) );
				if ( ! $check_ok ) {
					$rest_ok = false;
					$issues[] = 'telegram:rest_' . $rest_name;
				}
			}
		}

		foreach ( $adapters as $registered_code => $adapter ) {
			$code = sanitize_key( (string) $registered_code );
			$descriptor = BizCity_CRM_Channel_Contract::describe( $code );
			$adapter_code = sanitize_key( (string) $adapter->code() );
			$descriptor_ok = $code !== ''
				&& $adapter_code === $code
				&& (string) ( $descriptor['code'] ?? '' ) === $code
				&& isset( $descriptor['zone'], $descriptor['crm_enabled'], $descriptor['storage'], $descriptor['ai_policy'] )
				&& is_array( $adapter->capabilities() )
				&& is_callable( array( $adapter, 'normalize_inbound' ) )
				&& is_callable( array( $adapter, 'send' ) );
			$ctx->emit_step( array(
				'label' => 'Descriptor · ' . $code,
				'status' => $descriptor_ok ? 'pass' : 'fail',
				'detail' => $descriptor_ok
					? sprintf( '%s · zone=%s · crm_enabled=%s · class=%s', $code, (string) $descriptor['zone'], ! empty( $descriptor['crm_enabled'] ) ? 'true' : 'false', get_class( $adapter ) )
					: 'adapter code, descriptor, capabilities, normalize_inbound, or send contract is incomplete',
			) );
			if ( ! $descriptor_ok ) {
				$issues[] = $code . ':descriptor';
				continue;
			}
			if ( ! empty( $descriptor['crm_enabled'] ) ) {
				$enabled_count++;
			} else {
				$disabled_count++;
			}

			try {
				$minimal = $adapter->normalize_inbound( array() );
				$minimal_ok = null === $minimal || is_array( $minimal );
			} catch ( Throwable $e ) {
				$minimal_ok = false;
			}
			$ctx->emit_step( array(
				'label' => 'Normalize minimum · ' . $code,
				'status' => $minimal_ok ? 'pass' : 'fail',
				'detail' => $minimal_ok ? 'Empty invalid payload is rejected or skipped without an exception.' : 'normalize_inbound([]) threw or returned an invalid shape.',
			) );
			if ( ! $minimal_ok ) {
				$issues[] = $code . ':normalize_minimum';
			}

			$normalized = array(
				'inbox_ref' => 'probe-' . $code,
				'source_id' => 'probe-source',
				'content' => 'probe',
				'content_type' => 'text',
				'attachments' => array(),
				'external_source_id' => 'probe-' . $code,
				'received_at' => '2026-09-01 00:00:00',
				'channel_code' => $code,
			);
			$accepted = BizCity_CRM_Channel_Contract::normalize_inbound( $code, $normalized );
			$accept_ok = ! is_wp_error( $accepted ) && is_array( $accepted )
				&& (string) ( $accepted['channel_code'] ?? '' ) === $code
				&& (string) ( $accepted['contract_version'] ?? '' ) === BizCity_CRM_Channel_Contract::VERSION;
			$expected_accept = ! empty( $descriptor['crm_enabled'] );
			$accept_contract_ok = $expected_accept ? $accept_ok : is_wp_error( $accepted );
			$ctx->emit_step( array(
				'label' => 'Contract acceptance · ' . $code,
				'status' => $accept_contract_ok ? 'pass' : 'fail',
				'detail' => $expected_accept
					? ( $accept_ok ? 'CRM-enabled descriptor accepted a complete normalized envelope.' : 'CRM-enabled descriptor rejected a complete normalized envelope.' )
					: ( is_wp_error( $accepted ) ? 'Disabled/legacy descriptor rejected the envelope fail-closed.' : 'Disabled/legacy descriptor accepted an envelope.' ),
			) );
			if ( ! $accept_contract_ok ) {
				$issues[] = $code . ':contract_acceptance';
			}

			$wrong_channel = $normalized;
			$wrong_channel['channel_code'] = 'probe_wrong_channel';
			$rejected = BizCity_CRM_Channel_Contract::normalize_inbound( $code, $wrong_channel );
			$reject_ok = is_wp_error( $rejected );
			$ctx->emit_step( array(
				'label' => 'Contract rejection · ' . $code,
				'status' => $reject_ok ? 'pass' : 'fail',
				'detail' => $reject_ok ? 'Mismatched payload channel_code rejected.' : 'Mismatched payload channel_code was accepted.',
			) );
			if ( ! $reject_ok ) {
				$issues[] = $code . ':contract_rejection';
			}

			$send_shape = BizCity_CRM_Channel_Contract::normalize_send_result( $code, array( 'success' => true, 'external_source_id' => 'probe-' . $code ) );
			$shape_ok = empty( array_diff( $required_result_keys, array_keys( $send_shape ) ) )
				&& (string) ( $send_shape['channel_code'] ?? '' ) === $code
				&& (string) ( $send_shape['outcome'] ?? '' ) === 'accepted';
			$ctx->emit_step( array(
				'label' => 'Send result shape · ' . $code,
				'status' => $shape_ok ? 'pass' : 'fail',
				'detail' => $shape_ok ? 'Success result has the complete normalized outbound contract.' : 'Normalized outbound result is missing required keys or outcome.',
			) );
			if ( ! $shape_ok ) {
				$issues[] = $code . ':send_result_shape';
			}
		}

		if ( empty( $adapters ) || ! empty( $issues ) ) {
			return array(
				'status' => 'fail',
				'summary' => empty( $adapters ) ? 'No CRM adapters are registered.' : 'CRM adapter contract matrix has failures.',
				'error' => empty( $adapters ) ? 'adapter_catalog_empty' : implode( ',', $issues ),
				'fix_hint' => 'Fix the failing adapter descriptor/normalize/contract result shape, then rerun core.channel.crm_adapter_matrix.',
			);
		}

		return array(
			'status' => 'pass',
			'summary' => sprintf( 'CRM adapter matrix passed for %d registered adapter(s): %d CRM-enabled, %d disabled/legacy fail-closed.', count( $adapters ), $enabled_count, $disabled_count ),
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Adapter_Matrix';
	return $list;
} );