<?php
/**
 * BizCity Diagnostics - KG SQL usage ledger structural parity.
 *
 * This table is a billing/quota ledger and intentionally remains SQL-backed.
 * The probe validates the owner contract and read-only summaries; it does not
 * write a synthetic financial event and does not treat KG notebook checks as
 * usage-ledger evidence.
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

if ( class_exists( 'BizCity_Probe_KG_Usage_Ledger_Parity', false ) ) {
	return;
}

final class BizCity_Probe_KG_Usage_Ledger_Parity implements BizCity_Diagnostics_Probe {

	const TABLE_SUFFIX = 'bizcity_kg_usage_log';

	public function id(): string {
		return 'core.knowledge.kg_usage_ledger_parity';
	}

	public function label(): string {
		return 'KG usage SQL ledger parity';
	}

	public function description(): string {
		return 'Validates the SQL-backed KG billing ledger owner, lifecycle policy, physical table, and read-only quota summaries; no JSONL migration or synthetic financial write.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 85;
	}

	public function icon(): string {
		return 'credit-card';
	}

	public function estimate_ms(): int {
		return 220;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			return new WP_Error( 'kg_usage_owner_missing', 'KG cost/usage ledger owner is not loaded.' );
		}
		foreach ( array( 'table', 'summary_today', 'spent_today_usd', 'user_passages_today', 'estimate_cost' ) as $method ) {
			if ( ! method_exists( 'BizCity_KG_Cost_Guard', $method ) ) {
				return new WP_Error( 'kg_usage_owner_api_missing', 'KG cost owner method is missing: ' . $method );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$guard = BizCity_KG_Cost_Guard::instance();
		$table = $guard->table();
		$table_ok = is_string( $table ) && substr( $table, -strlen( self::TABLE_SUFFIX ) ) === self::TABLE_SUFFIX;
		$emit( 'Loader - KG billing owner/table contract', $table_ok, $table_ok ? 'BizCity_KG_Cost_Guard resolves the expected SQL ledger suffix.' : 'KG cost owner resolved an unexpected table.' );

		$physical_ok = ! function_exists( 'bizcity_tbl_exists' ) || (bool) bizcity_tbl_exists( $table );
		$emit( 'Runtime - SQL ledger physical table', $physical_ok, $physical_ok ? 'Physical SQL ledger is available on the active routed context.' : 'Physical SQL ledger is absent; fail-closed read behavior is required.' );

		$summary = $guard->summary_today();
		$summary_ok = is_array( $summary ) && isset( $summary['spent_usd'], $summary['in_tokens'], $summary['out_tokens'], $summary['calls'], $summary['cap_usd'], $summary['pct'] );
		$emit( 'Runtime - SQL billing summary reader', $summary_ok, $summary_ok ? 'summary_today() returned the billing ledger shape.' : 'summary_today() returned an invalid ledger shape.' );

		$spent = $guard->spent_today_usd();
		$passages = $guard->user_passages_today( get_current_user_id() );
		$readers_ok = is_numeric( $spent ) && is_numeric( $passages ) && (float) $spent >= 0 && (int) $passages >= 0;
		$emit( 'Runtime - SQL quota readers', $readers_ok, $readers_ok ? 'spent_today_usd/user_passages_today returned non-negative bounded values.' : 'SQL quota readers returned invalid values.' );

		$cost = $guard->estimate_cost( 'diagnostic', 100, 100 );
		$cost_ok = is_numeric( $cost ) && (float) $cost >= 0;
		$emit( 'Runtime - billing cost calculation', $cost_ok, $cost_ok ? 'estimate_cost() returned a non-negative numeric result.' : 'estimate_cost() returned an invalid result.' );

		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'KG SQL usage ledger structural parity passed; SQL remains canonical.' : 'KG SQL usage ledger structural parity failed.', 'steps' => $steps );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_KG_Usage_Ledger_Parity';
	return $list;
} );
