<?php
/**
 * BizCity Diagnostics - Google usage audit reader/writer parity.
 *
 * Exercises BZGoogle_REST_API::log_usage() with a synthetic audit event and
 * reads it back through the public history endpoint plus the registered JSONL
 * contract. The event is deliberately content-free and follows normal audit
 * retention; no Google provider call is made.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Google_Usage_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Google_Usage_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'plugins.bizgpt_tool_google.usage_audit';
	const SERVICE     = 'diagnostics';
	const ACTION      = 'parity';
	const SUMMARY     = '__healthtest_google_usage_parity_lark21';

	public function id(): string {
		return 'integrations.google_usage_parity';
	}

	public function label(): string {
		return 'Google usage audit JSONL parity';
	}

	public function description(): string {
		return 'Exercises BZGoogle_REST_API usage logging and public history reader, then verifies the registered JSONL contract keeps blog/user/service/action scope.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 82;
	}

	public function icon(): string {
		return 'graph-line';
	}

	public function estimate_ms(): int {
		return 250;
	}

	public function precondition() {
		if ( ! class_exists( 'BZGoogle_REST_API' ) ) {
			return new WP_Error( 'google_usage_owner_missing', 'Google usage owner is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'google_usage_contract_missing', 'Google usage JSONL dependencies are not loaded.' );
		}
		if ( ! BizCity_Log_Contract_Registry::get( self::CONTRACT_ID ) ) {
			return new WP_Error( 'google_usage_contract_missing', 'Google usage audit contract is not registered.' );
		}
		if ( ! class_exists( 'WP_REST_Request' ) ) {
			return new WP_Error( 'rest_request_missing', 'WordPress REST request class is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();

		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array(
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$started = microtime( true );
		BZGoogle_REST_API::log_usage( $blog_id, $user_id, self::SERVICE, self::ACTION, self::SUMMARY, 'success' );
		$emit( 'Runtime - Google usage owner writer', true, 'log_usage() accepted a content-free synthetic audit event.' );

		$raw_rows = BizCity_JSONL_File_Logger::query_contract( self::CONTRACT_ID, array(
			'days'   => 2,
			'limit'  => 500,
			'filter' => function ( $row ) use ( $blog_id, $user_id ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				return (int) ( $ctx['blog_id'] ?? 0 ) === $blog_id
					&& (int) ( $ctx['user_id'] ?? 0 ) === $user_id
					&& (string) ( $ctx['request_summary'] ?? '' ) === self::SUMMARY;
			},
		) );
		$raw_row = isset( $raw_rows[0] ) && is_array( $raw_rows[0] ) ? $raw_rows[0] : array();
		$raw_ctx = is_array( $raw_row['ctx'] ?? null ) ? $raw_row['ctx'] : array();
		$file_ok = ! empty( $raw_row )
			&& (string) ( $raw_ctx['service'] ?? '' ) === self::SERVICE
			&& (string) ( $raw_ctx['action'] ?? '' ) === self::ACTION
			&& (string) ( $raw_ctx['response_status'] ?? '' ) === 'success';
		$emit( 'Runtime - Google usage JSONL row', $file_ok, $file_ok ? 'contract reader returned the scoped usage event.' : 'contract reader missed or mis-scoped the usage event.' );

		$request = new WP_REST_Request( 'GET', '/bizgpt-google/v1/history' );
		$request->set_param( 'limit', 200 );
		$request->set_param( 'offset', 0 );
		$response = BZGoogle_REST_API::get_history( $request );
		$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		$history_ok = is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] );
		$history_hit = false;
		if ( $history_ok ) {
			foreach ( $data['items'] as $item ) {
				if ( is_array( $item ) && (string) ( $item['request_summary'] ?? '' ) === self::SUMMARY ) {
					$history_hit = (string) ( $item['service'] ?? '' ) === self::SERVICE
						&& (string) ( $item['action'] ?? '' ) === self::ACTION;
					break;
				}
			}
		}
		$emit( 'Runtime - Google usage public reader', $history_hit, $history_hit ? 'get_history() returned the same service/action audit row.' : 'get_history() did not return the synthetic usage row.' );

		$emit( 'Runtime - Google usage reader parity', $file_ok && $history_hit, sprintf( 'jsonl=%s · public_history=%s · elapsed_ms=%d', $file_ok ? 'hit' : 'miss', $history_hit ? 'hit' : 'miss', (int) round( ( microtime( true ) - $started ) * 1000 ) ) );

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Google usage audit JSONL/public-reader parity passed.' : 'Google usage audit parity failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Google_Usage_Parity';
	return $list;
} );
