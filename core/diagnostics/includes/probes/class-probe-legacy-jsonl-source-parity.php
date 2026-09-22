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
		$failed = array();
		// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — capture the origin blog before write_contract()/query_contract() may switch_to_blog(main) for a global-scope contract. get_current_blog_id() called fresh inside the reader filter below would otherwise resolve to the main site while still switched, so the source-blog match against ctx.blog_id missed for every global contract (confirmed on VPS blog 1511: append succeeded, read_miss on every global contract).
		$origin_blog_id = (int) get_current_blog_id();
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
				'blog_id' => $origin_blog_id,
				'contract_id' => $contract_id,
			);
			$written = BizCity_JSONL_File_Logger::write_contract( $contract_id, 'info', 'legacy_jsonl_parity', 'Legacy JSONL replacement parity sentinel.', $ctx_data );
			// [2026-09-18 11:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — global contracts are written inside switch_to_blog(main), so the row blog_id is the main site; the source blog lives in ctx.blog_id.
			$scope = (string) ( $contract['storage_scope'] ?? 'blog' );
			$rows = $written ? BizCity_JSONL_File_Logger::query_contract( $contract_id, array(
				'days' => 2,
				'limit' => 100,
				'filter' => function ( $row ) use ( $contract_id, $scope, $origin_blog_id ) {
					// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — compare against the captured origin blog, not a fresh get_current_blog_id(): this closure can run while query_contract() is still switch_to_blog(main) for a global contract.
					$row_ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
					$source_blog = 'blog' === $scope ? (int) ( $row['blog_id'] ?? 0 ) : (int) ( $row_ctx['blog_id'] ?? 0 );
					return (string) ( $row['event'] ?? '' ) === 'legacy_jsonl_parity'
						&& (string) ( $row_ctx['probe_sentinel'] ?? '' ) === $this->sentinel
						&& (string) ( $row_ctx['contract_id'] ?? '' ) === $contract_id
						&& $source_blog === $origin_blog_id;
				},
			) ) : array();
			$read_ok = $written && ! empty( $rows );
			if ( ! $read_ok ) {
				$failed[] = $contract_id . ( $written ? ':read_miss' : ':write_failed' );
			}
			$emit( 'JSONL parity: ' . $contract_id . ' (' . $scope . ')', $read_ok, $read_ok ? 'Contract-first append and scoped reader returned the sentinel.' : ( $written ? 'Append succeeded but the scoped reader did not return the sentinel.' : 'Contract append failed: ' . self::explain_write_failure( $contract ) ) );
		}

		$result = array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? count( $contracts ) . ' JSONL replacement contracts passed current-blog writer/reader parity.' : 'One or more JSONL replacement contracts failed current-blog writer/reader parity.',
			'error' => implode( ', ', $failed ),
			'steps' => $steps,
			'contract_count' => count( $contracts ),
		);
		if ( ! $pass ) {
			// [2026-09-18 11:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — every fail carries a rerun path (R-DDV actionable evidence).
			$result['fix_hint'] = 'Check the failed contract in BizCity_Log_Contract_Registry (folder/module/storage_scope) and that the upload root is writable, then rerun: php bin/diagnostics-run.php --filter=core.legacy_table.jsonl_source_parity --skip-provision --skip-network --format=json';
		}
		return $result;
	}

	public function cleanup(): void {}

	/**
	 * Explain a failed contract append without exposing filesystem paths.
	 *
	 * @param array $contract Registered log contract.
	 * @return string
	 */
	private static function explain_write_failure( array $contract ) {
		// [2026-09-18 11:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — name the failing layer (registry/scope/upload dir) so a write_failed row is actionable.
		$folder = (string) ( $contract['jsonl_folder'] ?? '' );
		$module = (string) ( $contract['jsonl_module'] ?? '' );
		$scope  = (string) ( $contract['storage_scope'] ?? 'blog' );
		$parts  = array( 'scope=' . $scope );
		$parts[] = 'registry_resolve=' . ( is_array( BizCity_Log_Contract_Registry::resolve( $folder, $module ) ) ? 'ok' : 'miss' );
		$switched = false;
		if ( 'global' === $scope && is_multisite() && function_exists( 'get_main_site_id' ) && (int) get_main_site_id() !== (int) get_current_blog_id() ) {
			$parts[] = 'main_site_id=' . (int) get_main_site_id();
			switch_to_blog( (int) get_main_site_id() );
			$switched = true;
		}
		try {
			$parts[] = 'registry_resolve_in_scope=' . ( is_array( BizCity_Log_Contract_Registry::resolve( $folder, $module ) ) ? 'ok' : 'miss' );
			$upload = wp_upload_dir( null, false );
			$base   = (string) ( $upload['basedir'] ?? '' );
			$dir    = $base !== '' ? trailingslashit( $base ) . $folder . DIRECTORY_SEPARATOR . $module : '';
			$parts[] = 'upload_basedir=' . ( $base === '' ? 'empty' : ( is_dir( $base ) ? 'present' : 'missing' ) );
			$parts[] = 'module_dir=' . ( $dir === '' ? 'n/a' : ( is_dir( $dir ) ? ( is_writable( $dir ) ? 'writable' : 'not_writable' ) : 'missing' ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return implode( '; ', $parts );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_JSONL_Source_Parity';
	return $list;
} );
