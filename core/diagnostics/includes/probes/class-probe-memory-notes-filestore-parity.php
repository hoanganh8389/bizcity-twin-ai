<?php
/**
 * BizCity Diagnostics - filestore parity for TwinChat notes.
 *
 * Writes one synthetic note through the public Notes Service, verifies the
 * folded business record and the owner reader. Unified mirror evidence belongs
 * to the separate dual-write probe.
 * Cleanup uses the canonical note delete/tombstone path and unified mirror
 * delete event; it does not write to the quarantined legacy SQL table.
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

if ( class_exists( 'BizCity_Probe_Memory_Notes_Filestore_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Memory_Notes_Filestore_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'modules.twinchat.memory_notes';
	const SENTINEL    = '__healthtest_filestore_notes_parity_lark21';

	public function id(): string {
		return 'core.memory.notes_filestore_parity';
	}

	public function label(): string {
		return 'Memory notes filestore parity';
	}

	public function description(): string {
		return 'Writes a synthetic note through TwinChat Notes Service and verifies contract-backed filestore persistence, project reader parity, and keyword search.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 80;
	}

	public function icon(): string {
		return 'note';
	}

	public function estimate_ms(): int {
		return 700;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'filestore_classes_missing', 'Filestore contract/store classes are not loaded.' );
		}
		if ( ! BizCity_File_Contract_Registry::has( self::CONTRACT_ID ) ) {
			return new WP_Error( 'notes_contract_missing', 'TwinChat notes business contract is not registered.' );
		}
		if ( ! class_exists( 'BizCity_TwinChat_Notes_Service' ) ) {
			return new WP_Error( 'notes_owner_missing', 'TwinChat Notes Service is not loaded.' );
		}
		if ( get_current_user_id() <= 0 ) {
			return new WP_Error( 'admin_required', 'Probe requires an authenticated admin user.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;

		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();
		$nonce = substr( md5( (string) microtime( true ) . '|' . (string) $user_id . '|' . wp_rand() ), 0, 12 );
		$project_id = 'diag-notes-' . $nonce;
		$title = self::SENTINEL . ' title ' . $nonce;
		$record_id = '';
		$note_id = 0;

		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$status = is_string( $ok ) ? $ok : ( $ok ? 'pass' : 'fail' );
			$step = array(
				'label'  => $label,
				'status' => $status,
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( 'fail' === $status ) {
				$pass = false;
			}
		};

		try {
			$service = new BizCity_TwinChat_Notes_Service();
			$note_id = $service->create( array(
				'project_id' => $project_id,
				'session_id' => 'diag-notes-session-' . $nonce,
				'title'      => $title,
				'content'    => self::SENTINEL . ' content ' . $nonce,
				'note_type'  => 'manual',
				'is_starred' => 1,
				'metadata'   => array( 'diagnostic' => true ),
			) );
			$write_ok = is_int( $note_id ) && $note_id > 0;
			$emit( 'Runtime notes - owner create', $write_ok, 'create result=' . ( is_wp_error( $note_id ) ? $note_id->get_error_code() : var_export( $note_id, true ) ) );
			if ( ! $write_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Notes owner create did not return an integer-compatible ID.', 'steps' => $steps );
			}

			$file_rows = BizCity_Business_JSONL_File_Store::query( self::CONTRACT_ID, array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'limit'   => 100,
				'days'    => 3650,
				'filter'  => function ( $row ) use ( $note_id ) {
					return (int) ( $row['legacy_id'] ?? 0 ) === (int) $note_id;
				},
			) );
			$file_row = isset( $file_rows[0] ) && is_array( $file_rows[0] ) ? $file_rows[0] : array();
			$record_id = (string) ( $file_row['record_id'] ?? '' );
			$file_ok = $record_id !== '' && strpos( (string) ( $file_row['title'] ?? '' ), self::SENTINEL ) !== false;
			$emit( 'Runtime notes - filestore row exists', $file_ok, $file_ok ? ( 'record_id=' . $record_id ) : 'Filestore note row not found.' );

			$reader_rows = $service->get_by_project( $project_id );
			$reader_ok = false;
			foreach ( $reader_rows as $row ) {
				if ( (int) ( $row->id ?? 0 ) === (int) $note_id && strpos( (string) ( $row->title ?? '' ), self::SENTINEL ) !== false ) {
					$reader_ok = true;
					break;
				}
			}
			$emit( 'Runtime notes - reader parity (project API vs filestore)', $reader_ok && $file_ok, 'project rows=' . count( $reader_rows ) . '; filestore=' . ( $file_ok ? 'hit' : 'miss' ) );

			$search_rows = $service->search_by_keyword( $project_id, self::SENTINEL, 20 );
			$search_ok = false;
			foreach ( $search_rows as $row ) {
				if ( (int) ( $row->id ?? 0 ) === (int) $note_id ) {
					$search_ok = true;
					break;
				}
			}
			$emit( 'Runtime notes - keyword reader parity', $search_ok, 'keyword rows=' . count( $search_rows ) );

			// [2026-08-29 Johnny Chu] PHASE-1.30-FILESTORE-CANON — unified SQL mirror evidence is owned by core.memory.unified.dual-write.
			$emit( 'Runtime notes - unified mirror evidence', 'skip', 'Optional unified dual-write evidence is separate from canonical notes filestore parity.' );

			return array(
				'status'  => $pass ? 'pass' : 'fail',
				'summary' => $pass ? 'Notes filestore reader parity passed; unified dual-write is a separate optional gate.' : 'Notes filestore parity failed.',
				'steps'   => $steps,
			);
		} catch ( \Throwable $e ) {
			return array( 'status' => 'fail', 'summary' => 'Notes filestore parity probe threw an exception.', 'error' => $e->getMessage(), 'steps' => $steps );
		} finally {
			if ( $note_id > 0 ) {
				try {
					if ( isset( $service ) && $service instanceof BizCity_TwinChat_Notes_Service ) {
						$service->delete( $note_id );
					}
				} catch ( \Throwable $e ) {
					// Cleanup must not replace the primary probe result.
				}
			}
		}
	}

	public function cleanup(): void {}

	private function table_exists( $table_name ): bool {
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Notes_Filestore_Parity';
	return $list;
} );
