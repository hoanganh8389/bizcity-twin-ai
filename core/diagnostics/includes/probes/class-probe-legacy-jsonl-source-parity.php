<?php
/**
 * Runtime parity probe for legacy tables whose replacement is canonical JSONL.
 *
 * Writes one content-free sentinel per registered contract and reads it back
 * through the canonical contract reader on the current blog.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-29
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_JSONL_Source_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_JSONL_Source_Parity implements BizCity_Diagnostics_Probe {

	private $sentinel = '';

	public function id(): string {
		return 'core.legacy_table.jsonl_source_parity';
	}

	public function label(): string {
		return 'Legacy tables - JSONL source parity';
	}

	public function description(): string {
		return 'Writes and reads content-free sentinels through every legacy JSONL replacement contract on the current blog.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 25;
	}

	public function icon(): string {
		return 'file-check-2';
	}

	public function estimate_ms(): int {
		return 500;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'jsonl_contract_dependencies_missing', 'Canonical JSONL logger or contract registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — prove JSONL replacement write/read parity through immutable contracts on the current blog.
		$steps = array();
		$pass = true;
		$this->sentinel = 'legacy_jsonl_parity_' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 12 );
		$contracts = array(
			'core.intent.pipeline_trace',
			'core.intent.prompt_log',
			'core.memory.mutation_audit',
			'core.mcp.audit',
			'core.channel_gateway.facebook',
			'core.channel_gateway.zalo_bot',
			'core.knowledge.kg_source_progress',
			'core.knowledge.kg_cleanup_audit',
			'plugins.bizgpt_tool_google.usage_audit',
			'core.skills.usage_audit',
			'core.automation.workflow_trace',
			'core.bizcity_llm.client_usage',
		);
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		foreach ( $contracts as $contract_id ) {
			$contract = BizCity_Log_Contract_Registry::get( $contract_id );
			if ( ! is_array( $contract ) ) {
				$emit( 'Contract: ' . $contract_id, false, 'Registered JSONL contract is missing.' );
				continue;
			}
			$ctx_data = array(
				'probe_sentinel' => $this->sentinel,
				'blog_id' => (int) get_current_blog_id(),
				'contract_id' => $contract_id,
			);
			$written = BizCity_JSONL_File_Logger::write_contract( $contract_id, 'info', 'legacy_jsonl_parity', 'Legacy JSONL replacement parity sentinel.', $ctx_data );
			$rows = $written ? BizCity_JSONL_File_Logger::query_contract( $contract_id, array(
				'days' => 2,
				'limit' => 100,
				'filter' => function ( $row ) use ( $contract_id ) {
					$row_ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
					return (string) ( $row['event'] ?? '' ) === 'legacy_jsonl_parity'
						&& (string) ( $row_ctx['probe_sentinel'] ?? '' ) === $this->sentinel
						&& (string) ( $row_ctx['contract_id'] ?? '' ) === $contract_id
						&& (int) ( $row['blog_id'] ?? 0 ) === (int) get_current_blog_id();
				},
			) ) : array();
			$read_ok = $written && ! empty( $rows );
			$emit( 'JSONL parity: ' . $contract_id, $read_ok, $read_ok ? 'Contract-first append and current-blog reader returned the sentinel.' : 'Contract append or current-blog reader did not return the sentinel.' );
		}

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? count( $contracts ) . ' JSONL replacement contracts passed current-blog writer/reader parity.' : 'One or more JSONL replacement contracts failed current-blog writer/reader parity.',
			'steps' => $steps,
			'contract_count' => count( $contracts ),
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_JSONL_Source_Parity';
	return $list;
} );
