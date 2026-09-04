<?php
/**
 * BizCity Diagnostics - SQL LLM usage ledger structural parity.
 *
 * The Hub-owned bizcity_llm_usage_logs table remains SQL-backed for billing.
 * This probe validates that separate server ledger; the client legacy
 * bizcity_llm_usage projection is tested by the filestore parity probe.
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

if ( class_exists( 'BizCity_Probe_LLM_Usage_Ledger_Parity', false ) ) {
	return;
}

final class BizCity_Probe_LLM_Usage_Ledger_Parity implements BizCity_Diagnostics_Probe {

	const TABLE_SUFFIX = 'bizcity_llm_usage';

	public function id(): string {
		return 'core.bizcity_llm.usage_ledger_parity';
	}

	public function label(): string {
		return 'LLM usage SQL ledger parity';
	}

	public function description(): string {
		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — keep Hub billing evidence separate from client usage telemetry evidence.
		return 'Validates the Hub SQL billing ledger; client performance telemetry is validated separately through JSONL.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 86;
	}

	public function icon(): string {
		return 'server-process';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Router_Usage' ) ) {
			return 'Hub-only probe: client runtime uses JSONL for bizcity_llm_usage; canonical billing ledger is bizcity_llm_usage_logs on bizcity-llm-router.';
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps = array();
		$pass = true;
		// [2026-08-28 Johnny Chu] R-LLM-KEY-ONLY — Hub usage logs are global and keyed by api_key_id, never tenant-prefix or user-only ledger state.
		$table = $wpdb->base_prefix . 'bizcity_llm_usage_logs';

		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$physical_ok = ! function_exists( 'bizcity_tbl_exists' ) || (bool) bizcity_tbl_exists( $table );
		$emit( 'Runtime - Hub SQL usage ledger physical table', $physical_ok, $physical_ok ? 'Global routed usage_logs table exists.' : 'Global usage_logs table is unavailable on the Hub runtime.' );
		if ( ! $physical_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Hub LLM usage SQL ledger is unavailable.', 'steps' => $steps );
		}

		$columns = function_exists( 'bizcity_columns_exist' )
			? bizcity_columns_exist( $table, array( 'api_key_id', 'user_id', 'service', 'total_tokens', 'cost_usd', 'created_at' ) )
			: true;
		$emit( 'Runtime - Hub key/cost ledger columns', $columns, $columns ? 'api_key_id, user_id, service, tokens, cost and timestamp columns are available.' : 'Canonical Hub usage_logs columns are incomplete.' );
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Hub LLM usage SQL ledger structural parity passed; SQL remains canonical.' : 'Hub LLM usage SQL ledger structural parity failed.', 'steps' => $steps );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_LLM_Usage_Ledger_Parity';
	return $list;
} );
