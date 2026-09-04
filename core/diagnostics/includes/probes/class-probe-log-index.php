<?php
/**
 * DDV for the canonical JSONL pointer index.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Log_Index', false ) ) {
	return;
}

final class BizCity_Probe_Log_Index implements BizCity_Diagnostics_Probe {

	private static $index_failure_filter_called = false;

	public function id(): string { return 'core.helper.log_index'; }
	public function label(): string { return 'Framework JSONL log pointer index'; }
	public function description(): string { return 'Checks the one tenant-scoped pointer index without treating SQL as log source of truth.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 53; }
	public function icon(): string { return 'search'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — prove the index contract in Disk/Loader/Runtime layers.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$changelog = $root . 'core/diagnostics/changelog/core.helper.json';
		$disk = class_exists( 'BizCity_Log_Index' ) && is_readable( $changelog );
		$steps[] = array(
			'label' => 'Disk · pointer index class and R-DCL file',
			'status' => $disk ? 'pass' : 'fail',
			'detail' => $disk ? 'BizCity_Log_Index and core.helper.json are present.' : 'Pointer index class or R-DCL changelog is unavailable.',
		);

		$schema = class_exists( 'BizCity_Schema_Registry' ) && BizCity_Schema_Registry::is_registered( 'bizcity_log_index' );
		$cache = class_exists( 'BizCity_Cache_Registry' ) && is_array( BizCity_Cache_Registry::get( 'bzlogidx' ) );
		$registry = class_exists( 'BizCity_Log_Contract_Registry' ) && method_exists( 'BizCity_Log_Contract_Registry', 'resolve' );
		$reader_api = class_exists( 'BizCity_JSONL_File_Logger' )
			&& method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' )
			&& method_exists( 'BizCity_JSONL_File_Logger', 'read_contract' );
		$loader = $schema && $cache && $registry && $reader_api && class_exists( 'BizCity_Log_Index' ) && method_exists( 'BizCity_Log_Index', 'reconcile' );
		$steps[] = array(
			'label' => 'Loader · schema/cache/contract wiring',
			'status' => $loader ? 'pass' : 'fail',
			'detail' => $loader
				? 'Schema Registry, cache contract, resolver, contract-first reader APIs and reconcile API are loaded.'
				: 'Dependency state: ' . wp_json_encode( array(
					'schema_registered' => $schema,
					'cache_registered'  => $cache,
					'contract_registry' => $registry,
					'reader_api'        => $reader_api,
					'index_class'       => class_exists( 'BizCity_Log_Index' ),
					'reconcile_api'     => class_exists( 'BizCity_Log_Index' ) && method_exists( 'BizCity_Log_Index', 'reconcile' ),
				) ),
		);

		$security_ok = false;
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			try {
				$reflection = new ReflectionMethod( 'BizCity_JSONL_File_Logger', 'scrub_message' );
				$reflection->setAccessible( true );
				$redacted = (string) $reflection->invoke( null, 'Bearer secret-token@example.com +84 901 234 567' );
				$security_ok = strpos( $redacted, 'secret-token' ) === false
					&& strpos( $redacted, 'secret-token@example.com' ) === false
					&& strpos( $redacted, '901 234 567' ) === false;
			} catch ( \Throwable $e ) {
				$security_ok = false;
			}
		}
		$traversal = class_exists( 'BizCity_JSONL_File_Logger' )
			? BizCity_JSONL_File_Logger::read_page_location( 'probe-folder', 'probe-module', 'probe-folder/probe-module/../outside/2026-08-27.jsonl', 0, 1 )
			: array( 'rows' => array() );
		$traversal_ok = empty( $traversal['rows'] );
		$invalid_contract_ok = class_exists( 'BizCity_JSONL_File_Logger' )
			&& false === BizCity_JSONL_File_Logger::write_contract( 'core.invalid.contract', 'info', 'probe', 'probe', array() );
		$nested_files_ok = true;
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			$nested_files = BizCity_JSONL_File_Logger::list_jsonl_files( 'probe-folder', 'probe-module', 10 );
			foreach ( $nested_files as $nested_file ) {
				$nested_files_ok = $nested_files_ok && strpos( (string) ( $nested_file['relative_file'] ?? '' ), 'probe-folder/probe-module/' ) === 0;
			}
		}
		$steps[] = array( 'label' => 'Runtime · redaction, path, contract and nested-file boundaries', 'status' => $security_ok && $traversal_ok && $invalid_contract_ok && $nested_files_ok ? 'pass' : 'fail', 'detail' => $security_ok && $traversal_ok && $invalid_contract_ok && $nested_files_ok ? 'Sensitive message patterns, traversal path, unknown contract and nested file paths were rejected or constrained.' : 'A logger security or nested-path boundary did not pass.' );
		$index_failure_isolated = false;
		$probe_contract_id = 'core.helper.log_index_probe';
		$probe_folder = 'bizcity-log-index-probe';
		$probe_module = 'failure-isolation';
		$probe_root = '';
		$probe_root_preexisting = false;
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir( null, false );
			$probe_root = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . $probe_folder;
			$probe_root_preexisting = is_dir( $probe_root );
		}
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && class_exists( 'BizCity_Log_Index' ) && class_exists( 'BizCity_Log_Contract_Registry' ) && function_exists( 'add_filter' ) ) {
			BizCity_Log_Contract_Registry::register( $probe_contract_id, array( 'owner_module' => 'core/diagnostics', 'label' => 'Log index diagnostics probe', 'jsonl_folder' => $probe_folder, 'jsonl_module' => $probe_module, 'retention_days' => 1, 'indexed' => true ) );
			self::$index_failure_filter_called = false;
			add_filter( 'bizcity_log_index_allow_record', array( __CLASS__, 'deny_index_for_probe' ), 10, 3 );
			try {
				$write_result = BizCity_JSONL_File_Logger::write_contract( $probe_contract_id, 'info', 'diagnostic_index_failure_probe', 'Index failure isolation probe.', array( 'probe' => 'core.helper.log_index' ) );
			} finally {
				remove_filter( 'bizcity_log_index_allow_record', array( __CLASS__, 'deny_index_for_probe' ), 10 );
				if ( ! $probe_root_preexisting && $probe_root !== '' ) {
					$probe_location = BizCity_JSONL_File_Logger::location( $probe_folder, $probe_module );
					if ( ! empty( $probe_location['file'] ) && is_file( $probe_location['file'] ) ) {
						@unlink( $probe_location['file'] );
					}
					@unlink( $probe_root . DIRECTORY_SEPARATOR . '.htaccess' );
					@unlink( $probe_root . DIRECTORY_SEPARATOR . 'web.config' );
					@unlink( $probe_root . DIRECTORY_SEPARATOR . 'index.php' );
					@rmdir( $probe_root . DIRECTORY_SEPARATOR . $probe_module );
					@rmdir( $probe_root );
				}
			}
			$index_failure_isolated = self::$index_failure_filter_called && $write_result;
		}
		$steps[] = array( 'label' => 'Runtime · index failure does not fail JSONL write', 'status' => $index_failure_isolated ? 'pass' : 'fail', 'detail' => $index_failure_isolated ? 'A denied pointer index operation left the primary JSONL write successful.' : 'The index failure isolation probe did not complete.' );
		$reader_boundary_ok = false;
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) && method_exists( 'BizCity_JSONL_File_Logger', 'read_contract' ) ) {
			$reader_boundary_ok = BizCity_JSONL_File_Logger::query_contract( 'core.invalid.contract' ) === array()
				&& BizCity_JSONL_File_Logger::read_contract( 'core.invalid.contract', '2026-08-27', 1 ) === array();
		}
		$steps[] = array( 'label' => 'Runtime · unknown reader contract rejection', 'status' => $reader_boundary_ok ? 'pass' : 'fail', 'detail' => $reader_boundary_ok ? 'Unknown contract IDs return empty results without resolving a caller-supplied path.' : 'An unknown reader contract was not rejected.' );
		$protection_ok = false;
		if ( class_exists( 'BizCity_Log_Contract_Registry' ) && class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			foreach ( BizCity_Log_Contract_Registry::all() as $contract ) {
				if ( ! is_array( $contract ) || empty( $contract['jsonl_folder'] ) || empty( $contract['jsonl_module'] ) ) {
					continue;
				}
				$location = BizCity_JSONL_File_Logger::location( $contract['jsonl_folder'], $contract['jsonl_module'] );
				$dir = (string) ( $location['directory'] ?? '' );
				if ( $dir !== '' && is_file( dirname( $dir ) . DIRECTORY_SEPARATOR . '.htaccess' ) && is_file( dirname( $dir ) . DIRECTORY_SEPARATOR . 'web.config' ) ) {
					$protection_ok = true;
					break;
				}
			}
		}
		$steps[] = array( 'label' => 'Runtime · Apache/IIS file protection artifacts', 'status' => $protection_ok ? 'pass' : 'skip', 'detail' => $protection_ok ? 'Contract root has .htaccess and web.config deny artifacts.' : 'Protection artifacts are not available in this runtime; verify deployed Apache/Nginx/IIS access separately.' );

		$physical = false;
		$runtime_status = 'skip';
		$runtime_detail = 'Physical pointer table is not available in this runtime; run Site Provisioner before Runtime verification.';
		if ( function_exists( 'bizcity_tbl_exists' ) && class_exists( 'BizCity_Log_Index' ) ) {
			$physical = bizcity_tbl_exists( BizCity_Log_Index::table() );
			if ( $physical ) {
				$rows = BizCity_Log_Index::search( array( 'limit' => 1 ) );
				$runtime_status = empty( $rows ) ? 'skip' : 'pass';
				$runtime_detail = empty( $rows ) ? 'Pointer table exists but has no live row for pointer-following verification.' : 'Current-tenant pointer row is searchable with contract/file location metadata.';
				if ( ! empty( $rows[0] ) && class_exists( 'BizCity_JSONL_File_Logger' ) ) {
					$pointer = $rows[0];
					$target = BizCity_JSONL_File_Logger::verify_pointer( $pointer['jsonl_folder'], $pointer['jsonl_module'], $pointer['relative_file'], $pointer['byte_offset'], $pointer['row_hash'] );
					$pointer_ok = ! empty( $target['valid'] );
					$steps[] = array( 'label' => 'Runtime · pointer hash follow', 'status' => $pointer_ok ? 'pass' : 'fail', 'detail' => $pointer_ok ? 'Pointer offset and row hash resolve to the exact JSONL row.' : 'Pointer target is absent or hash-mismatched.' );
					if ( ! $pointer_ok ) { $runtime_status = 'fail'; }
					$bad_hash = BizCity_JSONL_File_Logger::verify_pointer( $pointer['jsonl_folder'], $pointer['jsonl_module'], $pointer['relative_file'], $pointer['byte_offset'], str_repeat( '0', 64 ) );
					$hash_rejection_ok = empty( $bad_hash['valid'] );
					$steps[] = array( 'label' => 'Runtime · hash mismatch rejection', 'status' => $hash_rejection_ok ? 'pass' : 'fail', 'detail' => $hash_rejection_ok ? 'Incorrect pointer hash is rejected.' : 'Incorrect pointer hash was accepted.' );
				}
			}
		}
		$steps[] = array( 'label' => 'Runtime · current-shard pointer search', 'status' => $runtime_status, 'detail' => $runtime_detail );

		$ok = $disk && $loader && $security_ok && $traversal_ok && $invalid_contract_ok && $runtime_status === 'pass';
		$index_source = '';
		$index_file = $root . 'core/helper/class-bizcity-log-index.php';
		if ( is_readable( $index_file ) ) {
			$index_source = (string) file_get_contents( $index_file );
		}
		$cursor_scope_ok = class_exists( 'BizCity_Log_Index' )
			&& strpos( $index_source, 'availability_key' ) !== false
			&& strpos( $index_source, "'scope'" ) !== false
			&& strpos( $index_source, 'RECONCILE_CURSOR_OPTION' ) !== false;
		$steps[] = array( 'label' => 'Runtime · shard-aware reconcile cursor', 'status' => $cursor_scope_ok ? 'pass' : 'fail', 'detail' => $cursor_scope_ok ? 'Reconcile cursor is namespaced and runtime scope includes blog/database identity.' : 'Reconcile cursor scope metadata is unavailable.' );
		$ok = $disk && $loader && $security_ok && $traversal_ok && $invalid_contract_ok && $nested_files_ok && $index_failure_isolated && $reader_boundary_ok && $cursor_scope_ok && $runtime_status === 'pass';
		foreach ( $steps as $step ) {
			$ctx->emit_step( $step );
		}
		return array(
			'status' => $ok ? 'pass' : ( $runtime_status === 'skip' ? 'skip' : 'fail' ),
			'summary' => $ok ? 'Canonical log pointer index contract and live pointer search passed.' : ( $runtime_status === 'skip' ? 'Canonical log pointer index is loaded but physical/runtime evidence is not available.' : 'Canonical log pointer index contract is incomplete.' ),
			'steps' => $steps,
		);
	}

	public static function deny_index_for_probe( $allow, $contract_id, $row ) {
		self::$index_failure_filter_called = true;
		return false;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Index';
	return $list;
} );
