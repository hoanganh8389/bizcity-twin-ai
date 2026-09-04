<?php
/**
 * BizCity Diagnostics - KG source-progress JSONL reader parity.
 *
 * Exercises the active source-progress owner and compares its public reader
 * with the immutable JSONL log contract. The synthetic event is append-only
 * operational evidence and is retained by the normal seven-day policy.
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

if ( class_exists( 'BizCity_Probe_KG_Source_Progress_Parity', false ) ) {
	return;
}

final class BizCity_Probe_KG_Source_Progress_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.knowledge.kg_source_progress';
	const SOURCE_ID   = 987654321;
	const EVENT       = 'diagnostic_parity';

	public function id(): string {
		return 'core.knowledge.kg_source_progress_parity';
	}

	public function label(): string {
		return 'KG source progress JSONL parity';
	}

	public function description(): string {
		return 'Exercises BizCity_KG_Source_Progress_Log::record() and compares its source reader with the registered JSONL contract while preserving notebook/source tenant scope.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 81;
	}

	public function icon(): string {
		return 'history';
	}

	public function estimate_ms(): int {
		return 250;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			return new WP_Error( 'source_progress_owner_missing', 'KG source-progress owner is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'source_progress_log_contract_missing', 'JSONL logger or log contract registry is not loaded.' );
		}
		if ( ! BizCity_Log_Contract_Registry::get( self::CONTRACT_ID ) ) {
			return new WP_Error( 'source_progress_contract_missing', 'KG source-progress log contract is not registered.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$notebook_id = 987654;
		$passage_id = 876543;
		$trigger_filter = static function () { return 'diagnostics'; };

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

		add_filter( 'bizcity_kg_progress_log_trigger', $trigger_filter, 9999 );
		try {
			// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — drive the active source-progress owner, not a direct logger-only shortcut.
			BizCity_KG_Source_Progress_Log::record( array(
				'notebook_id'   => $notebook_id,
				'source_id'     => self::SOURCE_ID,
				'passage_id'    => $passage_id,
				'event'        => self::EVENT,
				'triggered_by' => 'diagnostics',
				'counts_total' => 3,
				'counts_done'  => 2,
				'counts_error' => 0,
				'payload'      => array( 'probe' => 'kg_source_progress_parity' ),
			) );

			$owner_rows = BizCity_KG_Source_Progress_Log::get_for_source( self::SOURCE_ID, 100 );
			$owner_row = array();
			foreach ( $owner_rows as $row ) {
				if ( is_array( $row ) && (string) ( $row['event'] ?? '' ) === self::EVENT && (int) ( $row['notebook_id'] ?? 0 ) === $notebook_id ) {
					$owner_row = $row;
					break;
				}
			}
			$owner_ok = ! empty( $owner_row )
				&& (int) ( $owner_row['source_id'] ?? 0 ) === self::SOURCE_ID
				&& (int) ( $owner_row['passage_id'] ?? 0 ) === $passage_id;
			$emit( 'Runtime - source-progress owner reader', $owner_ok, $owner_ok ? 'record/get_for_source returned scoped sentinel.' : 'Owner reader did not return the scoped sentinel.' );

			$raw_rows = BizCity_JSONL_File_Logger::query_contract( self::CONTRACT_ID, array(
				'days'   => 2,
				'limit'  => 500,
				'filter' => function ( $row ) use ( $notebook_id ) {
					$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
					return (string) ( $row['event'] ?? '' ) === self::EVENT
						&& (int) ( $ctx['notebook_id'] ?? 0 ) === $notebook_id;
				},
			) );
			$raw_row = isset( $raw_rows[0] ) && is_array( $raw_rows[0] ) ? $raw_rows[0] : array();
			$raw_ctx = is_array( $raw_row['ctx'] ?? null ) ? $raw_row['ctx'] : array();
			$contract_ok = ! empty( $raw_row )
				&& (string) ( $raw_row['event'] ?? '' ) === self::EVENT
				&& (int) ( $raw_ctx['source_id'] ?? 0 ) === self::SOURCE_ID
				&& (int) ( $raw_ctx['passage_id'] ?? 0 ) === $passage_id
				&& (int) ( $raw_row['blog_id'] ?? 0 ) === $blog_id;
			$emit( 'Runtime - JSONL contract reader', $contract_ok, $contract_ok ? 'query_contract returned the same blog/source/passage event.' : 'Registered JSONL contract reader missed or mis-scoped the sentinel.' );

			$parity_ok = $owner_ok && $contract_ok
				&& (int) ( $owner_row['source_id'] ?? 0 ) === (int) ( $raw_ctx['source_id'] ?? 0 )
				&& (int) ( $owner_row['passage_id'] ?? 0 ) === (int) ( $raw_ctx['passage_id'] ?? 0 )
				&& (string) ( $owner_row['event'] ?? '' ) === (string) ( $raw_row['event'] ?? '' );
			$emit( 'Runtime - owner reader parity', $parity_ok, $parity_ok ? 'Owner hydration matches contract event identity.' : 'Owner/API and contract event identity differ.' );

			return array(
				'status'  => $pass ? 'pass' : 'fail',
				'summary' => $pass ? 'KG source-progress JSONL reader parity passed.' : 'KG source-progress JSONL reader parity failed.',
				'steps'   => $steps,
			);
		} catch ( \Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'KG source-progress parity probe threw an exception.', 'error' => $e->getMessage(), 'steps' => $steps );
		} finally {
			remove_filter( 'bizcity_kg_progress_log_trigger', $trigger_filter, 9999 );
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_KG_Source_Progress_Parity';
	return $list;
} );
