<?php
/**
 * BizCity Diagnostics - KG cleanup audit JSONL reader parity.
 *
 * Writes one synthetic audit event through the cleanup owner, reads it back
 * through the public get_log() reader and the immutable log contract, and
 * never invokes orphan detection/reaping or other KG data mutations.
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

if ( class_exists( 'BizCity_Probe_KG_Cleanup_Audit_Parity', false ) ) {
	return;
}

final class BizCity_Probe_KG_Cleanup_Audit_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.knowledge.kg_cleanup_audit';
	const TARGET      = 'diagnostic';
	const ACTION      = 'parity';
	const REASON      = 'diagnostic_parity';

	public function id(): string {
		return 'core.knowledge.kg_cleanup_audit_parity';
	}

	public function label(): string {
		return 'KG cleanup audit JSONL parity';
	}

	public function description(): string {
		return 'Exercises BizCity_KG_Cleanup_Service audit writing and get_log() against the registered JSONL contract without running orphan cleanup.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 83;
	}

	public function icon(): string {
		return 'trash';
	}

	public function estimate_ms(): int {
		return 250;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			return new WP_Error( 'kg_cleanup_owner_missing', 'KG cleanup owner is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'kg_cleanup_contract_missing', 'KG cleanup JSONL dependencies are not loaded.' );
		}
		if ( ! BizCity_Log_Contract_Registry::get( self::CONTRACT_ID ) ) {
			return new WP_Error( 'kg_cleanup_contract_missing', 'KG cleanup audit contract is not registered.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass = true;
		$run_id = 'diag-kg-cleanup-' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 16 );

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

		try {
			$service = BizCity_KG_Cleanup_Service::instance();
			$write_ok = $service->record_audit_event( array(
				'run_id'       => $run_id,
				'trigger_kind' => 'diagnostics',
				'triggered_by' => get_current_user_id(),
				'stage'        => 'diagnostic',
				'target_table' => self::TARGET,
				'target_id'    => 0,
				'action'       => self::ACTION,
				'reason'       => self::REASON,
				'payload'      => array( 'probe' => 'kg_cleanup_audit_parity' ),
			) );
			$emit( 'Runtime - cleanup audit owner writer', $write_ok, $write_ok ? 'record_audit_event() accepted the synthetic audit event.' : 'Cleanup audit owner rejected the synthetic event.' );

			$raw_rows = BizCity_JSONL_File_Logger::query_contract( self::CONTRACT_ID, array(
				'days'   => 2,
				'limit'  => 500,
				'filter' => function ( $row ) use ( $run_id ) {
					$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
					return (string) ( $ctx['run_id'] ?? '' ) === $run_id;
				},
			) );
			$raw_row = isset( $raw_rows[0] ) && is_array( $raw_rows[0] ) ? $raw_rows[0] : array();
			$raw_ctx = is_array( $raw_row['ctx'] ?? null ) ? $raw_row['ctx'] : array();
			$file_ok = ! empty( $raw_row )
				&& (string) ( $raw_ctx['stage'] ?? '' ) === 'diagnostic'
				&& (string) ( $raw_ctx['action'] ?? '' ) === self::ACTION
				&& (string) ( $raw_ctx['reason'] ?? '' ) === self::REASON;
			$emit( 'Runtime - cleanup audit JSONL row', $file_ok, $file_ok ? 'contract reader returned the scoped audit event.' : 'contract reader missed or mis-scoped the audit event.' );

			$owner_rows = $service->get_log( array( 'run_id' => $run_id, 'limit' => 20 ) );
			$owner_ok = false;
			foreach ( (array) $owner_rows as $row ) {
				if ( is_array( $row ) && (string) ( $row['run_id'] ?? '' ) === $run_id ) {
					$owner_ok = (string) ( $row['stage'] ?? '' ) === 'diagnostic'
						&& (string) ( $row['action'] ?? '' ) === self::ACTION
						&& (string) ( $row['reason'] ?? '' ) === self::REASON;
					break;
				}
			}
			$emit( 'Runtime - cleanup audit public reader', $owner_ok, $owner_ok ? 'get_log() returned the same audit shape.' : 'get_log() did not return the synthetic audit row.' );
			$emit( 'Runtime - cleanup audit reader parity', $file_ok && $owner_ok, 'jsonl=' . ( $file_ok ? 'hit' : 'miss' ) . ' · owner=' . ( $owner_ok ? 'hit' : 'miss' ) );

			return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'KG cleanup audit JSONL reader parity passed.' : 'KG cleanup audit parity failed.', 'steps' => $steps );
		} catch ( \Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'KG cleanup audit parity probe threw an exception.', 'error' => $e->getMessage(), 'steps' => $steps );
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_KG_Cleanup_Audit_Parity';
	return $list;
} );
