<?php
/**
 * Focused DDV for JSONL pointer reconcile and retention failure handling.
 *
 * The probe uses one run-specific registered contract and removes every
 * disposable file/pointer it creates during cleanup.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Log_Reconcile_Retention', false ) ) {
	return;
}

final class BizCity_Probe_Log_Reconcile_Retention implements BizCity_Diagnostics_Probe {

	private $contract_id = '';
	private $folder = '';
	private $module = '';
	private $files = array();
	private static $retention_denied_contract = '';

	public function id(): string {
		return 'core.helper.log_reconcile_retention';
	}

	public function label(): string {
		return 'JSONL reconcile, stale pointer and retention failure';
	}

	public function description(): string {
		return 'Proves missing-pointer rebuild, stale-hash removal, resumable reconcile and retention deletion failure safety on a disposable contract.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 56;
	}

	public function icon(): string {
		return 'history';
	}

	public function estimate_ms(): int {
		return 1200;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Index' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'log_reconcile_dependencies_missing', 'JSONL logger, pointer index or contract registry is not loaded.' );
		}
		if ( ! BizCity_Log_Index::is_available() ) {
			return new WP_Error( 'log_index_unavailable', 'Pointer index is not provisioned on the current routed shard.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G3 - prove rebuild, stale-pointer removal, resumable cursor and retention failure invariants with disposable files.
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		};

		$this->contract_id = 'core.helper.log_g3_probe_' . substr( md5( uniqid( 'g3', true ) ), 0, 12 );
		$this->folder = 'bizcity-log-g3-probe';
		$this->module = 'reconcile_' . substr( md5( $this->contract_id ), 0, 12 );
		$registered = BizCity_Log_Contract_Registry::register( $this->contract_id, array(
			'owner_module' => 'core/diagnostics',
			'label' => 'G3 JSONL reconcile retention probe',
			'jsonl_folder' => $this->folder,
			'jsonl_module' => $this->module,
			'retention_days' => 1,
			'indexed' => true,
		) );
		$emit( 'Loader - run-specific registered contract', $registered, $registered ? 'Synthetic contract registered with one-day retention and indexed pointer projection.' : 'Synthetic contract registration failed.' );
		if ( ! $registered ) {
			return array( 'status' => 'fail', 'summary' => 'G3 fixture contract registration failed.', 'error' => 'g3_contract_registration_failed', 'fix_hint' => 'Keep the disposable contract unique and registry-approved.', 'steps' => $steps );
		}

		$records = array();
		for ( $index = 1; $index <= 3; $index++ ) {
			$event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf( '00000000-0000-4000-8000-%012d', $index );
			$record = array(
				'contract' => $this->contract_id,
				'version' => '1.0.0',
				'event_uuid' => $event_uuid,
				'event' => 'g3_current',
				'level' => 'info',
				'occurred_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
				'context' => array( 'probe' => 'core.helper.log_reconcile_retention', 'fixture_index' => $index ),
			);
			$result = BizCity_JSONL_File_Logger::write_contract_record( $this->contract_id, $record );
			if ( is_array( $result ) && ! empty( $result['written'] ) ) {
				$records[] = $result;
				$this->files[] = array( 'relative_file' => (string) ( $result['relative_file'] ?? '' ), 'date' => substr( (string) $record['occurred_at'], 0, 10 ) );
			}
		}
		$created_ok = count( $records ) === 3;
		$emit( 'Runtime - disposable indexed source records', $created_ok ? 'pass' : 'fail', $created_ok ? 'Three synthetic rows were written through the canonical receipt/index path.' : 'The disposable source records were not all written.' );
		if ( ! $created_ok ) {
			return array( 'status' => 'fail', 'summary' => 'G3 could not create its disposable source records.', 'error' => 'g3_fixture_write_failed', 'fix_hint' => 'Verify the registered contract and canonical JSONL receipt writer.', 'steps' => $steps );
		}

		$current_relative = (string) $records[0]['relative_file'];
		$removed_before_reconcile = BizCity_Log_Index::purge_for_deleted_paths( $this->contract_id, array( $current_relative ) );
		$missing_before = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_current', 'limit' => 20 ) );
		$missing_ok = $removed_before_reconcile >= 3 && count( $missing_before ) === 0;
		$emit( 'Runtime - missing pointer fixture', $missing_ok ? 'pass' : 'fail', $missing_ok ? 'All disposable pointers were removed while the JSONL source remained.' : 'The missing-pointer fixture was not isolated.' );

		$reconcile_runs = array();
		$complete = false;
		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$reconcile = BizCity_Log_Index::reconcile( $this->contract_id, 1 );
			$reconcile_runs[] = $reconcile;
			$complete = ! empty( $reconcile['complete'] );
			if ( $complete ) {
				break;
			}
		}
		$rebuilt_pointers = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_current', 'limit' => 20 ) );
		$rebuilt_ok = $complete && count( $rebuilt_pointers ) === 3;
		$has_resume = count( $reconcile_runs ) > 1;
		$emit( 'Runtime - bounded reconcile resume and rebuild', $rebuilt_ok && $has_resume ? 'pass' : 'fail', $rebuilt_ok && $has_resume ? 'Reconcile resumed across bounded calls and rebuilt all three pointers without duplicate rows.' : 'Reconcile did not produce a complete multi-call rebuild.' );

		$stale_id = ! empty( $rebuilt_pointers[0]['id'] ) ? (int) $rebuilt_pointers[0]['id'] : 0;
		$stale_mutated = false;
		if ( $stale_id > 0 ) {
			global $wpdb;
			$stale_mutated = false !== $wpdb->update( BizCity_Log_Index::table(), array( 'row_hash' => str_repeat( '0', 64 ) ), array( 'id' => $stale_id, 'blog_id' => (int) get_current_blog_id() ), array( '%s' ), array( '%d', '%d' ) );
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::flush_group( 'bzlogidx' );
			}
		}
		$stale_removed_run = BizCity_Log_Index::reconcile( $this->contract_id, 500 );
		$after_stale_remove = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_current', 'limit' => 20 ) );
		$stale_absent = true;
		foreach ( $after_stale_remove as $pointer ) {
			$stale_absent = $stale_absent && (int) ( $pointer['id'] ?? 0 ) !== $stale_id;
		}
		$stale_rebuilt_run = BizCity_Log_Index::reconcile( $this->contract_id, 500 );
		$after_stale_rebuild = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_current', 'limit' => 20 ) );
		$stale_rebuilt = false;
		foreach ( $after_stale_rebuild as $pointer ) {
			$stale_rebuilt = $stale_rebuilt || (int) ( $pointer['id'] ?? 0 ) !== $stale_id && ! empty( BizCity_JSONL_File_Logger::verify_pointer( $pointer['jsonl_folder'], $pointer['jsonl_module'], $pointer['relative_file'], $pointer['byte_offset'], $pointer['row_hash'] )['valid'] );
		}
		$stale_ok = $stale_mutated && ! empty( $stale_removed_run['complete'] ) && $stale_absent && $stale_rebuilt && count( $after_stale_rebuild ) === 3;
		$emit( 'Runtime - stale hash removal and rebuild', $stale_ok ? 'pass' : 'fail', $stale_ok ? 'Hash-drifted pointer was removed, then rebuilt from the unchanged JSONL source.' : 'Stale pointer removal or subsequent rebuild did not complete.' );

		$old_date = '2020-01-01';
		$old_event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '00000000-0000-4000-8000-000000000099';
		$old_result = BizCity_JSONL_File_Logger::write_contract_record( $this->contract_id, array(
			'contract' => $this->contract_id,
			'version' => '1.0.0',
			'event_uuid' => $old_event_uuid,
			'event' => 'g3_old',
			'level' => 'info',
			'occurred_at' => $old_date . 'T00:00:00Z',
			'context' => array( 'probe' => 'core.helper.log_reconcile_retention', 'fixture_index' => 99 ),
		) );
		$old_relative = is_array( $old_result ) ? (string) ( $old_result['relative_file'] ?? '' ) : '';
		$old_file = BizCity_JSONL_File_Logger::location( $this->folder, $this->module, $old_date );
		$this->files[] = array( 'relative_file' => $old_relative, 'date' => $old_date );
		$old_created = is_array( $old_result ) && ! empty( $old_result['written'] ) && $old_relative !== '' && is_file( (string) ( $old_file['file'] ?? '' ) );
		$emit( 'Runtime - disposable expired source and pointer', $old_created ? 'pass' : 'fail', $old_created ? 'Expired synthetic JSONL file and pointer are available for retention failure testing.' : 'Expired fixture creation failed.' );

		self::$retention_denied_contract = $this->contract_id;
		add_filter( 'bizcity_jsonl_allow_retention_delete', array( __CLASS__, 'deny_retention_delete' ), 10, 3 );
		try {
			$blocked_deleted = BizCity_JSONL_File_Logger::purge_contract( $this->contract_id, 1 );
		} finally {
			remove_filter( 'bizcity_jsonl_allow_retention_delete', array( __CLASS__, 'deny_retention_delete' ), 10 );
			self::$retention_denied_contract = '';
		}
		$blocked_pointer = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_old', 'limit' => 5 ) );
		$blocked_ok = (int) $blocked_deleted === 0 && is_file( (string) ( $old_file['file'] ?? '' ) ) && count( $blocked_pointer ) === 1;
		$emit( 'Runtime - retention deletion failure preserves source and pointer', $blocked_ok ? 'pass' : 'fail', $blocked_ok ? 'Retention veto left the expired file and its pointer intact for retry.' : 'Retention failure removed the file or pointer unexpectedly.' );

		$deleted = BizCity_JSONL_File_Logger::purge_contract( $this->contract_id, 1 );
		$after_delete_pointer = BizCity_Log_Index::search( array( 'contract_id' => $this->contract_id, 'event' => 'g3_old', 'limit' => 5 ) );
		$deleted_ok = $deleted === 1 && ! is_file( (string) ( $old_file['file'] ?? '' ) ) && empty( $after_delete_pointer );
		$emit( 'Runtime - successful retention deletes exact file and pointers', $deleted_ok ? 'pass' : 'fail', $deleted_ok ? 'Successful retry deleted the exact expired file and only its matching pointer.' : 'Successful retention retry did not clean the exact expired fixture.' );

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'JSONL reconcile, stale-pointer rebuild and retention failure handling passed.' : 'JSONL reconcile or retention failure handling failed.',
			'error' => $pass ? '' : 'log_reconcile_retention_failed',
			'fix_hint' => $pass ? '' : 'Keep reconcile cursor scope bounded, remove only invalid pointers, and purge pointers only after exact file deletion succeeds.',
			'steps' => $steps,
		);
	}

	public static function deny_retention_delete( $allow, $contract_id, $relative_file ) {
		return (string) $contract_id === (string) self::$retention_denied_contract ? false : $allow;
	}

	public function cleanup(): void {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G3 - remove only this probe's current-blog pointers and date files after each run.
		if ( $this->contract_id === '' ) {
			return;
		}
		if ( class_exists( 'BizCity_Log_Index' ) ) {
			global $wpdb;
			$wpdb->delete( BizCity_Log_Index::table(), array( 'blog_id' => (int) get_current_blog_id(), 'contract_id' => $this->contract_id ), array( '%d', '%s' ) );
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::flush_group( 'bzlogidx' );
			}
		}
		foreach ( $this->files as $file_info ) {
			$date = (string) ( $file_info['date'] ?? '' );
			$location = BizCity_JSONL_File_Logger::location( $this->folder, $this->module, $date );
			$file = (string) ( $location['file'] ?? '' );
			if ( $file !== '' && is_file( $file ) ) {
				@unlink( $file );
			}
		}
		$location = BizCity_JSONL_File_Logger::location( $this->folder, $this->module );
		$directory = (string) ( $location['directory'] ?? '' );
		if ( $directory !== '' ) {
			@rmdir( $directory );
			@rmdir( dirname( $directory ) );
		}
		$this->contract_id = '';
		$this->folder = '';
		$this->module = '';
		$this->files = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Reconcile_Retention';
	return $list;
} );
