<?php
/**
 * Basic read/search parity for the canonical JSONL logger and pointer index.
 *
 * The JSONL row remains the source of truth. The SQL index is tested only as
 * a tenant-scoped pointer that can find and verify that row.
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

if ( class_exists( 'BizCity_Probe_JSONL_Search_Query_Index_Parity', false ) ) {
	return;
}

final class BizCity_Probe_JSONL_Search_Query_Index_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.intent.pipeline_trace';
	const EVENT       = 'legacy_basic_search_query_probe';

	public function id(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - expose the pre-SQL-retirement basic read/search gate.
		return 'core.helper.jsonl_search_query_index_parity';
	}

	public function label(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - identify canonical logger and pointer-index basic coverage.
		return 'JSONL search/query and pointer-index parity';
	}

	public function description(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - document that JSONL is canonical and the index is pointer-only.
		return 'Writes one content-free sentinel through the registered JSONL contract, reads it through query/read APIs, finds its tenant-scoped pointer and verifies the pointer hash.';
	}

	public function severity(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - missing basic read/search parity blocks SQL-writer retirement.
		return 'critical';
	}

	public function order(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - run after the canonical index contract probe.
		return 54;
	}

	public function icon(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - provide the diagnostics catalog icon.
		return 'search-check';
	}

	public function estimate_ms(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - declare the bounded probe estimate.
		return 250;
	}

	public function precondition() {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - require the canonical logger, registry and pointer index runtime.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'jsonl_dependencies_missing', 'Canonical JSONL logger or contract registry is not loaded.' );
		}
		if ( ! BizCity_Log_Contract_Registry::has( self::CONTRACT_ID ) ) {
			return new WP_Error( 'jsonl_contract_missing', 'The selected JSONL contract is not registered.' );
		}
		if ( ! class_exists( 'BizCity_Log_Index' ) ) {
			return new WP_Error( 'log_index_missing', 'BizCity_Log_Index is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - execute basic canonical JSONL query/read/index parity.
		$steps = array();
		$pass = true;
		$sentinel = 'jsonl_basic_search_query_' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 12 );
		$ref_id = 'diag-ref-' . substr( md5( $sentinel ), 0, 12 );
		$blog_id = (int) get_current_blog_id();

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

		$contract = BizCity_Log_Contract_Registry::get( self::CONTRACT_ID );
		$contract_ok = is_array( $contract )
			&& ! empty( $contract['indexed'] )
			&& (string) ( $contract['storage_scope'] ?? 'blog' ) === 'blog'
			&& (string) ( $contract['jsonl_folder'] ?? '' ) !== ''
			&& (string) ( $contract['jsonl_module'] ?? '' ) !== '';
		$emit(
			'Disk/Loader - indexed JSONL contract is registered',
			$contract_ok,
			$contract_ok ? 'Contract is blog-scoped, indexed and has an immutable folder/module location.' : 'Contract is missing indexed blog scope or folder/module metadata.'
		);
		if ( ! $contract_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Indexed JSONL contract metadata is incomplete.', 'steps' => $steps );
		}

		$index_available = BizCity_Log_Index::is_available();
		$emit(
			'Loader - tenant pointer index is physically available',
			$index_available,
			$index_available ? ( 'table=' . BizCity_Log_Index::table() ) : 'The pointer index is unavailable on the current blog/shard.'
		);
		if ( ! $index_available ) {
			return array( 'status' => 'fail', 'summary' => 'Pointer index is unavailable; SQL retirement cannot proceed.', 'steps' => $steps );
		}

		$written = BizCity_JSONL_File_Logger::write_contract(
			self::CONTRACT_ID,
			'info',
			self::EVENT,
			'Basic JSONL search/query parity sentinel.',
			array(
				'probe_sentinel' => $sentinel,
				'ref_id'         => $ref_id,
				'blog_id'        => $blog_id,
			)
		);
		$emit(
			'Runtime - canonical JSONL write',
			$written,
			$written ? 'Content-free sentinel appended through write_contract().' : 'write_contract() did not append the sentinel.'
		);
		if ( ! $written ) {
			return array( 'status' => 'fail', 'summary' => 'Canonical JSONL write failed before read/search checks.', 'steps' => $steps );
		}

		$matches = function ( $row ) use ( $sentinel ) {
			$row_ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
			return (string) ( $row['event'] ?? '' ) === self::EVENT
				&& (string) ( $row_ctx['probe_sentinel'] ?? '' ) === $sentinel
				&& (int) ( $row['blog_id'] ?? 0 ) === (int) get_current_blog_id();
		};
		$query_rows = BizCity_JSONL_File_Logger::query_contract( self::CONTRACT_ID, array(
			'days'   => 2,
			'limit'  => 20,
			'filter' => $matches,
		) );
		$query_ok = is_array( $query_rows ) && count( $query_rows ) === 1;
		$emit(
			'Runtime - contract query returns sentinel',
			$query_ok,
			$query_ok ? 'query_contract() returned exactly one current-blog sentinel.' : 'query_contract() did not return exactly one sentinel.'
		);

		$today_rows = BizCity_JSONL_File_Logger::read_contract( self::CONTRACT_ID, gmdate( 'Y-m-d' ), 20, 'info' );
		$read_count = 0;
		foreach ( (array) $today_rows as $row ) {
			if ( $matches( $row ) ) {
				$read_count++;
			}
		}
		$read_ok = $read_count === 1;
		$emit(
			'Runtime - single-date read returns sentinel',
			$read_ok,
			$read_ok ? 'read_contract() returned the same sentinel from today file.' : 'read_contract() did not return exactly one sentinel.'
		);

		$pointer_rows = BizCity_Log_Index::search( array(
			'contract_id' => self::CONTRACT_ID,
			'event'      => self::EVENT,
			'ref_id'     => $ref_id,
			'limit'      => 20,
		) );
		$pointer = array();
		foreach ( (array) $pointer_rows as $row ) {
			if ( (int) ( $row['blog_id'] ?? 0 ) === $blog_id && (string) ( $row['event'] ?? '' ) === self::EVENT ) {
				$pointer = $row;
				break;
			}
		}
		$pointer_ok = ! empty( $pointer );
		$emit(
			'Runtime - pointer index search finds sentinel',
			$pointer_ok,
			$pointer_ok ? ( 'search() returned pointer id=' . (int) $pointer['id'] . ' for current blog.' ) : 'search() did not return the current-blog sentinel pointer.'
		);

		$follow_ok = false;
		$follow_detail = 'Pointer verification was not reached.';
		if ( $pointer_ok ) {
			$verified = BizCity_JSONL_File_Logger::verify_pointer(
				(string) $pointer['jsonl_folder'],
				(string) $pointer['jsonl_module'],
				(string) $pointer['relative_file'],
				(int) $pointer['byte_offset'],
				(string) $pointer['row_hash']
			);
			$follow_ok = ! empty( $verified['valid'] );
			$follow_detail = $follow_ok ? 'Pointer offset and hash resolve to the canonical JSONL row.' : 'Pointer offset or hash does not resolve to the canonical JSONL row.';
			if ( ! $follow_ok ) {
				$pointer_page = BizCity_JSONL_File_Logger::read_page_location(
					(string) $pointer['jsonl_folder'],
					(string) $pointer['jsonl_module'],
					(string) $pointer['relative_file'],
					(int) $pointer['byte_offset'],
					1
				);
				$actual_item = isset( $pointer_page['rows'][0] ) && is_array( $pointer_page['rows'][0] ) ? $pointer_page['rows'][0] : array();
				$follow_detail .= ' ' . wp_json_encode( array(
					'relative_file' => (string) $pointer['relative_file'],
					'offset'        => (int) $pointer['byte_offset'],
					'expected_hash' => substr( (string) $pointer['row_hash'], 0, 12 ),
					'actual_hash'   => substr( (string) ( $actual_item['row_hash'] ?? '' ), 0, 12 ),
					'page_rows'     => count( (array) ( $pointer_page['rows'] ?? array() ) ),
				) );
			}
		}
		$emit( 'Runtime - pointer hash follow', $follow_ok, $follow_detail );

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'Basic JSONL query/read/search and pointer verification passed on the current blog.'
				: 'Basic JSONL query/read/search and pointer verification failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - probe data is content-free diagnostic evidence and is retained under logger retention policy.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_JSONL_Search_Query_Index_Parity';
	return $list;
} );
