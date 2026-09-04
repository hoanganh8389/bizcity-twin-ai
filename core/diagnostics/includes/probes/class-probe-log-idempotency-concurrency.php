<?php
/**
 * Focused DDV for JSONL append retry and pointer idempotency.
 *
 * Two child PHP processes write the same fixed record. The fixture is isolated
 * under a run-specific registered contract and removed after verification.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Log_Idempotency_Concurrency', false ) ) {
	return;
}

final class BizCity_Probe_Log_Idempotency_Concurrency implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.helper.log_idempotency_concurrency';
	}

	public function label(): string {
		return 'JSONL append concurrency and idempotency';
	}

	public function description(): string {
		return 'Runs two PHP writers against one synthetic event identity, verifies one JSONL row and pointer, retries safely and rejects a pointer hash conflict.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 55;
	}

	public function icon(): string {
		return 'shield';
	}

	public function estimate_ms(): int {
		return 2500;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Index' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'log_idempotency_dependencies_missing', 'JSONL logger, pointer index or contract registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-1.30-G2 - exercise the lock boundary with two real PHP processes and retain only redacted evidence.
		$steps = array();
		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};
		if ( ! BizCity_Log_Index::is_available() ) {
			$emit( 'Physical shard - pointer index available', 'skip', 'Pointer index is not provisioned on the current routed shard.' );
			return array( 'status' => 'skip', 'summary' => 'Concurrent JSONL fixture requires the current-shard pointer index.', 'error' => 'log_index_unavailable', 'fix_hint' => 'Run the Site Provisioner for bizcity_log_index, then rerun this focused probe.', 'steps' => $steps );
		}
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) ) {
			$emit( 'Runtime - two-process concurrency capability', 'skip', 'proc_open/proc_close is unavailable; no sequential test is promoted to concurrency evidence.' );
			return array( 'status' => 'skip', 'summary' => 'The runtime cannot execute the required two-process concurrency fixture.', 'error' => 'concurrency_process_unavailable', 'fix_hint' => 'Enable proc_open for the diagnostics PHP runtime or run the focused probe on the VPS CLI.', 'steps' => $steps );
		}

		$suffix = substr( md5( uniqid( 'g2', true ) ), 0, 12 );
		$contract_id = 'core.helper.log_g2_probe_' . $suffix;
		$folder = 'bizcity-log-g2-probe';
		$module = 'idempotency_' . $suffix;
		$registered = BizCity_Log_Contract_Registry::register( $contract_id, array(
			'owner_module' => 'core/diagnostics',
			'label' => 'G2 JSONL idempotency probe',
			'jsonl_folder' => $folder,
			'jsonl_module' => $module,
			'retention_days' => 1,
			'indexed' => true,
		) );
		$emit( 'Loader - run-specific registered contract', $registered ? 'pass' : 'fail', $registered ? 'Synthetic contract registered with a unique module path.' : 'Synthetic contract could not be registered.' );
		if ( ! $registered ) {
			return array( 'status' => 'fail', 'summary' => 'G2 fixture contract registration failed.', 'error' => 'g2_contract_registration_failed', 'fix_hint' => 'Keep the JSONL contract registry immutable and unique per folder/module.', 'steps' => $steps );
		}

		$event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '00000000-0000-4000-8000-' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$record = array(
			'contract' => $contract_id,
			'version' => '1.0.0',
			'event_uuid' => $event_uuid,
			'event' => 'g2_probe',
			'level' => 'info',
			'occurred_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			'context' => array( 'probe' => 'core.helper.log_idempotency_concurrency', 'idempotency_key' => 'g2:' . $event_uuid ),
		);
		$children = array();
		$worker_script = ( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/' ) . 'bin/log-idempotency-worker.php';
		if ( ! is_file( $worker_script ) || ! is_readable( $worker_script ) ) {
			$emit( 'Disk - guarded two-process worker', 'fail', 'The canonical G2 worker entrypoint is missing or unreadable.' );
			return array( 'status' => 'fail', 'summary' => 'G2 worker entrypoint is unavailable.', 'error' => 'g2_worker_missing', 'fix_hint' => 'Deploy bin/log-idempotency-worker.php with the diagnostics bundle.', 'steps' => $steps );
		}
		$worker_host = sanitize_text_field( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		if ( $worker_host === '' && function_exists( 'site_url' ) ) {
			$site_url = site_url( '/' );
			$worker_host = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		}
		$parts = array( PHP_BINARY, $worker_script, ABSPATH . 'wp-load.php', $contract_id, $folder, $module, base64_encode( wp_json_encode( $record ) ), $worker_host );
		$command = implode( ' ', array_map( array( __CLASS__, 'quote_process_arg' ), $parts ) );
		$spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		for ( $worker = 0; $worker < 2; $worker++ ) {
			$process = proc_open( $command, $spec, $pipes, ABSPATH );
			if ( ! is_resource( $process ) ) {
				$children[] = array( 'started' => false );
				continue;
			}
			$children[] = array( 'started' => true, 'process' => $process, 'pipes' => $pipes );
		}
		$worker_results = array();
		foreach ( $children as $child ) {
			if ( empty( $child['started'] ) ) {
				$worker_results[] = array( 'written' => false, 'reason' => 'child_start_failed' );
				continue;
			}
			$output = stream_get_contents( $child['pipes'][1] ) . stream_get_contents( $child['pipes'][2] );
			fclose( $child['pipes'][1] );
			fclose( $child['pipes'][2] );
			// [2026-09-02 Johnny Chu] PHASE-1.30-G2 - retain only child exit/parse state, never raw worker output.
			$child_code = (int) proc_close( $child['process'] );
			$result = array();
			$receipt_parsed = false;
			if ( preg_match( '/__BIZCITY_G2__([A-Za-z0-9+\\/=]+)/', $output, $matches ) ) {
				$decoded = base64_decode( $matches[1], true );
				$parsed = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
				$receipt_parsed = is_array( $parsed );
				$result = $receipt_parsed ? $parsed : array();
			}
			$result['_process_code'] = $child_code;
			$result['_receipt_parsed'] = $receipt_parsed;
			$worker_results[] = $result;
		}
		$worker_ok = count( $worker_results ) === 2;
		foreach ( $worker_results as $worker_result ) {
			$worker_ok = $worker_ok && ! empty( $worker_result['written'] ) && (string) ( $worker_result['event_uuid'] ?? '' ) === $event_uuid;
		}
		$worker_detail = $worker_ok ? 'Both child PHP processes returned the same synthetic event identity.' : 'Child state=' . wp_json_encode( array_map( function ( $result ) use ( $event_uuid ) { return array( 'code' => (int) ( $result['_process_code'] ?? -1 ), 'parsed' => ! empty( $result['_receipt_parsed'] ), 'written' => ! empty( $result['written'] ), 'event_match' => (string) ( $result['event_uuid'] ?? '' ) === $event_uuid, 'reason' => (string) ( $result['reason'] ?? '' ) ); }, $worker_results ) );
		$emit( 'Runtime - two concurrent writers', $worker_ok ? 'pass' : 'fail', $worker_detail );

		$retry = BizCity_JSONL_File_Logger::write_contract_record( $contract_id, $record );
		$retry_ok = is_array( $retry ) && ! empty( $retry['written'] ) && (string) ( $retry['event_uuid'] ?? '' ) === $event_uuid;
		$emit( 'Runtime - identical retry', $retry_ok ? 'pass' : 'fail', $retry_ok ? 'Retry returned the existing locked row without creating a new event identity.' : 'Identical retry failed or returned an unexpected receipt.' );

		$date = substr( (string) $record['occurred_at'], 0, 10 );
		$source_rows = BizCity_JSONL_File_Logger::read_contract( $contract_id, $date, 50 );
		$matching_rows = array_values( array_filter( $source_rows, function ( $row ) use ( $event_uuid ) {
			return is_array( $row ) && (string) ( $row['event_uuid'] ?? '' ) === $event_uuid;
		} ) );
		$one_source_row = count( $matching_rows ) === 1;
		$emit( 'Runtime - exactly one canonical JSONL row', $one_source_row ? 'pass' : 'fail', $one_source_row ? 'Concurrent append and retry left exactly one source row for the event identity.' : 'The event identity has duplicate or missing JSONL source rows.' );

		$pointers = BizCity_Log_Index::search( array( 'contract_id' => $contract_id, 'event' => 'g2_probe', 'limit' => 20 ) );
		$matching_pointers = array_values( array_filter( $pointers, function ( $pointer ) use ( $event_uuid ) {
			return is_array( $pointer ) && (string) ( $pointer['event_uuid'] ?? '' ) === $event_uuid;
		} ) );
		$one_pointer = count( $matching_pointers ) === 1;
		$pointer_valid = $one_pointer ? BizCity_JSONL_File_Logger::verify_pointer( $matching_pointers[0]['jsonl_folder'], $matching_pointers[0]['jsonl_module'], $matching_pointers[0]['relative_file'], $matching_pointers[0]['byte_offset'], $matching_pointers[0]['row_hash'] ) : array();
		$pointer_ok = $one_pointer && ! empty( $pointer_valid['valid'] );
		$emit( 'Runtime - exactly one verified pointer', $pointer_ok ? 'pass' : 'fail', $pointer_ok ? 'One tenant pointer resolves to the exact JSONL offset and hash.' : 'Pointer count or pointer follow verification failed.' );

		$conflict_ok = false;
		if ( $pointer_ok ) {
			$conflict_pointer = array(
				'relative_file' => $matching_pointers[0]['relative_file'],
				'byte_offset' => (int) $matching_pointers[0]['byte_offset'],
				'row_hash' => str_repeat( '0', 64 ),
			);
			$conflict_ok = false === BizCity_Log_Index::record( $contract_id, $record, $conflict_pointer );
		}
		$emit( 'Runtime - same-event pointer hash conflict', $conflict_ok ? 'pass' : 'fail', $conflict_ok ? 'Changed hash was refused without overwriting the canonical pointer.' : 'Changed hash was accepted or the conflict fixture lacked a verified pointer.' );

		$location = BizCity_JSONL_File_Logger::location( $folder, $module, $date );
		$file = (string) ( $location['file'] ?? '' );
		$relative_file = $folder . '/' . $module . '/' . $date . '.jsonl';
		if ( $file !== '' && is_file( $file ) ) {
			@unlink( $file );
		}
		if ( method_exists( 'BizCity_Log_Index', 'purge_for_deleted_paths' ) ) {
			BizCity_Log_Index::purge_for_deleted_paths( $contract_id, array( $relative_file ) );
		}
		if ( $file !== '' ) {
			@rmdir( dirname( $file ) );
		}

		$ok = $worker_ok && $retry_ok && $one_source_row && $pointer_ok && $conflict_ok;
		return array(
			'status' => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Concurrent JSONL append, retry idempotency and pointer conflict handling passed.' : 'JSONL concurrency or idempotency contract failed.',
			'error' => $ok ? '' : 'log_idempotency_concurrency_failed',
			'fix_hint' => $ok ? '' : 'Keep event identity deduplication inside the append lock, use a unique tenant pointer key and reject changed contract/file/offset/hash combinations.',
			'steps' => $steps,
		);
	}

	private static function quote_process_arg( string $value ): string {
		// [2026-09-02 Johnny Chu] PHASE-1.30-G2 - keep the disposable worker command portable across the local Windows CLI and VPS POSIX shell.
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			return preg_match( '/[\\s"]/', $value ) ? '"' . str_replace( '"', '\\"', $value ) . '"' : $value;
		}
		return escapeshellarg( $value );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Idempotency_Concurrency';
	return $list;
} );
