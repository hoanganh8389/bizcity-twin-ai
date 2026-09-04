<?php
/**
 * Runtime parity probe for the client LLM usage filestore replacement.
 *
 * Usage telemetry is operational evidence for performance and reporting. It
 * is deliberately outside the Context Bank memory/reference contracts.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-01
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_LLM_Usage_Filestore_Parity', false ) ) {
	return;
}

final class BizCity_Probe_LLM_Usage_Filestore_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.bizcity_llm.client_usage';
	const SUMMARY     = '__healthtest_llm_usage_filestore_parity_20260901';

	public function id(): string { return 'core.bizcity_llm.usage_filestore_parity'; }
	public function label(): string { return 'LLM usage filestore parity'; }
	public function description(): string { return 'Verifies client LLM usage write, scoped aggregation and daily reporting through JSONL, outside Context Bank memory storage.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 87; }
	public function icon(): string { return 'activity'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		$required = array( 'BizCity_LLM_Usage_File_Log', 'BizCity_JSONL_File_Logger', 'BizCity_Log_Contract_Registry', 'BizCity_Legacy_Table_Policy' );
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'llm_usage_filestore_dependency_missing', $class . ' is not loaded.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — prove usage telemetry is JSONL operational evidence, not Context Bank business memory.
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) { $pass = false; }
		};

		$contract = BizCity_Log_Contract_Registry::get( self::CONTRACT_ID );
		$contract_ok = is_array( $contract )
			&& in_array( 'bizcity_llm_usage', (array) ( $contract['related_sql_tables'] ?? array() ), true )
			&& (string) ( $contract['storage_scope'] ?? 'blog' ) === 'blog';
		$emit( 'Runtime - client usage JSONL contract', $contract_ok, $contract_ok ? 'Legacy client usage projections resolve to the tenant-scoped JSONL contract.' : 'Client usage contract is missing the legacy ledger relation or blog scope.' );

		$written = BizCity_LLM_Usage_File_Log::write( array(
			'blog_id' => $blog_id, 'user_id' => $user_id, 'service' => 'llm', 'endpoint' => 'chat',
			'surface' => 'diagnostics', 'purpose' => 'healthcheck', 'model_used' => 'healthtest-model',
			'success' => true, 'latency_ms' => 17,
			'usage' => array( 'prompt_tokens' => 11, 'completion_tokens' => 7 ),
			'error' => '', 'request_summary' => self::SUMMARY,
		) );
		$filters = array( 'blog_id' => $blog_id, 'user_id' => $user_id );
		$stats = $written ? BizCity_LLM_Usage_File_Log::get_stats( '2d', $filters ) : array();
		$stats_ok = $written && (int) ( $stats['total_calls'] ?? 0 ) > 0 && (int) ( $stats['total_tokens'] ?? 0 ) >= 18;
		$emit( 'Runtime - usage write and scoped aggregate', $stats_ok, $stats_ok ? 'JSONL usage write was included in blog/user-scoped calls and token totals.' : sprintf( 'write=%s calls=%d tokens=%d blog=%d user=%d', $written ? 'ok' : 'fail', (int) ( $stats['total_calls'] ?? 0 ), (int) ( $stats['total_tokens'] ?? 0 ), $blog_id, $user_id ) );

		$daily = $written ? BizCity_LLM_Usage_File_Log::get_daily_history( '2d', $filters ) : array();
		$daily_ok = false;
		foreach ( $daily as $row ) {
			if ( is_array( $row ) && (int) ( $row['calls'] ?? 0 ) > 0 && (int) ( $row['tokens'] ?? 0 ) >= 18 ) { $daily_ok = true; break; }
		}
		$emit( 'Runtime - daily usage report', $daily_ok, $daily_ok ? 'Daily calls/tokens are derived from the same JSONL usage source.' : sprintf( 'daily_rows=%d blog=%d user=%d', count( $daily ), $blog_id, $user_id ) );

		$sql_blocked = BizCity_Legacy_Table_Policy::install_blocked( 'bizcity_llm_usage' )
			&& ! BizCity_Legacy_Table_Policy::allow_sql( 'bizcity_llm_usage', 'create' )
			&& ! BizCity_Legacy_Table_Policy::allow_sql( 'bizcity_llm_usage', 'read' )
			&& ! BizCity_Legacy_Table_Policy::allow_sql( 'bizcity_llm_usage', 'write' )
			&& ! BizCity_Legacy_Table_Policy::allow_sql( 'bizcity_llm_usage', 'delete' );
		$emit( 'Runtime - legacy SQL ledger blocked', $sql_blocked, $sql_blocked ? 'The legacy client usage ledger is refused for install/read/write/delete.' : 'A legacy usage SQL operation is still permitted.' );

		$cb_allowed = true;
		if ( class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) && method_exists( 'BizCity_Context_Bank_Scope_Resolver', 'allowed_contracts' ) ) {
			$cb_allowed = ! in_array( self::CONTRACT_ID, (array) BizCity_Context_Bank_Scope_Resolver::allowed_contracts(), true );
		}
		$emit( 'Runtime - usage excluded from Context Bank', $cb_allowed, $cb_allowed ? 'Usage telemetry is not an allowlisted Context Bank memory/reference contract.' : 'Usage contract was incorrectly allowlisted for Context Bank.' );

		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'LLM usage filestore parity and Context Bank exclusion passed.' : 'LLM usage filestore parity failed.', 'steps' => $steps );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_LLM_Usage_Filestore_Parity';
	return $list;
} );