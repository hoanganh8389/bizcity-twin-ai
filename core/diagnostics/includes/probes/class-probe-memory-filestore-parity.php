<?php
/**
 * BizCity Diagnostics - filestore parity for legacy memory users/session owners.
 *
 * Runtime evidence (non-destructive):
 * 1) Write sentinel rows through owner APIs.
 * 2) Verify filestore contract rows exist.
 * 3) Verify owner readers can read the same sentinel rows (reader parity).
 * 4) Unified-memory mirror evidence is optional and belongs to the separate
 *    dual-write probe; a missing mirror table must not invalidate filestore parity.
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

if ( class_exists( 'BizCity_Probe_Memory_Filestore_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Memory_Filestore_Parity implements BizCity_Diagnostics_Probe {

	const USER_CONTRACT    = 'core.knowledge.user_memory';
	const SESSION_CONTRACT = 'modules.webchat.session_memory';
	const USER_SENTINEL    = '__healthtest_filestore_user_parity_lark21';
	const SESSION_SENTINEL = '__healthtest_filestore_session_parity_lark21';

	public function id(): string {
		return 'core.memory.filestore_parity';
	}

	public function label(): string {
		return 'Memory users/session filestore parity';
	}

	public function description(): string {
		return 'Writes sentinel records through BizCity_User_Memory and BizCity_WebChat_Memory, then verifies owner reader parity against the contract-backed filestore.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 79;
	}

	public function icon(): string {
		return 'database';
	}

	public function estimate_ms(): int {
		return 900;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'filestore_classes_missing', 'Filestore contract/store classes are not loaded.' );
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) || ! class_exists( 'BizCity_WebChat_Memory' ) ) {
			return new WP_Error( 'memory_owner_missing', 'Memory owner classes are not loaded.' );
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

		$contracts_ok = BizCity_File_Contract_Registry::has( self::USER_CONTRACT )
			&& BizCity_File_Contract_Registry::has( self::SESSION_CONTRACT );
		$emit(
			'Disk/Loader - memory business contracts registered',
			$contracts_ok,
			$contracts_ok ? 'core.knowledge.user_memory + modules.webchat.session_memory are available.' : 'One or both memory business contracts are missing.'
		);
		if ( ! $contracts_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Memory business contracts are not fully registered.',
				'steps'   => $steps,
			);
		}

		$user_record_id = '';
		$session_record_id = '';
		$user_memory_key = '';
		$session_memory_key = '';
		$user_identity_uuid = '';
		$user_session_id = '';
		$session_session_id = '';

		try {
			$nonce = substr( md5( (string) microtime( true ) . '|' . (string) $user_id . '|' . wp_rand() ), 0, 12 );

			// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — runtime parity for bizcity_memory_users through owner API + contract read model.
			$user_session_id = 'diag-user-filestore-' . $nonce;
			$user_memory_key = 'diag:user:' . $nonce;
			$scope = class_exists( 'BizCity_Memory_Identity_Scope' )
				? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $user_id, 'session_id' => $user_session_id ) )
				: array( 'identity_uuid' => '', 'user_id' => $user_id, 'session_id' => $user_session_id );
			$user_identity_uuid = (string) ( $scope['identity_uuid'] ?? '' );
			$identity_ok = $user_identity_uuid !== '';
			$emit(
				'Runtime users - identity owner resolved',
				$identity_ok,
				$identity_ok ? ( 'identity_uuid=' . $user_identity_uuid ) : 'identity_uuid is missing for user-memory write scope.'
			);
			if ( ! $identity_ok ) {
				return array(
					'status'  => 'fail',
					'summary' => 'Unable to resolve identity_uuid for user-memory parity test.',
					'steps'   => $steps,
				);
			}

			$user_result = BizCity_User_Memory::instance()->upsert_public( array(
				'user_id'       => $user_id,
				'session_id'    => $user_session_id,
				'identity_uuid' => $user_identity_uuid,
				'memory_tier'   => 'explicit',
				'memory_type'   => 'fact',
				'memory_key'    => $user_memory_key,
				'memory_text'   => self::USER_SENTINEL . ' #' . $nonce,
				'score'         => 88,
			) );
			$user_write_ok = $user_result === 'insert' || $user_result === 'update';
			$emit(
				'Runtime users - owner write',
				$user_write_ok,
				'user write result=' . var_export( $user_result, true )
			);

			$user_record_id = $this->user_record_id( $blog_id, $user_id, $user_session_id, $user_identity_uuid, $user_memory_key );
			$user_file_row = BizCity_Business_JSONL_File_Store::find( self::USER_CONTRACT, $user_record_id, array( 'blog_id' => $blog_id ) );
			$user_file_ok = is_array( $user_file_row )
				&& (string) ( $user_file_row['memory_key'] ?? '' ) === $user_memory_key
				&& strpos( (string) ( $user_file_row['memory_text'] ?? '' ), self::USER_SENTINEL ) !== false;
			$emit(
				'Runtime users - filestore row exists',
				$user_file_ok,
				$user_file_ok ? ( 'record_id=' . $user_record_id ) : 'Filestore row not found for user sentinel.'
			);

			$user_reader_rows = BizCity_User_Memory::instance()->get_memories( array(
				'user_id'       => $user_id,
				'session_id'    => $user_session_id,
				'identity_uuid' => $user_identity_uuid,
				'limit'         => 20,
			) );
			$user_reader_ok = false;
			if ( is_array( $user_reader_rows ) ) {
				foreach ( $user_reader_rows as $row ) {
					$key = is_object( $row ) ? (string) ( $row->memory_key ?? '' ) : (string) ( $row['memory_key'] ?? '' );
					if ( $key === $user_memory_key ) {
						$user_reader_ok = true;
						break;
					}
				}
			}
			$emit(
				'Runtime users - reader parity (API vs filestore)',
				$user_reader_ok && $user_file_ok,
				'user API rows=' . count( (array) $user_reader_rows ) . '; filestore=' . ( $user_file_ok ? 'hit' : 'miss' )
			);

			// [2026-08-29 Johnny Chu] PHASE-1.30-FILESTORE-CANON — unified SQL mirror is tested by core.memory.unified.dual-write, not by the filestore parity gate.
			$emit( 'Runtime users - unified mirror evidence', 'skip', 'Optional unified dual-write evidence is owned by core.memory.unified.dual-write; filestore parity is independent.' );

			// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — runtime parity for bizcity_memory_session via owner upsert + contract read model.
			$session_session_id = 'diag-session-filestore-' . $nonce;
			$session_memory_key = 'diag:session:' . $nonce;
			$session_result = BizCity_WebChat_Memory::upsert_public( array(
				'session_id'         => $session_session_id,
				'user_id'            => $user_id,
				'client_name'        => 'Diagnostics',
				'memory_type'        => 'goal',
				'memory_key'         => $session_memory_key,
				'memory_text'        => self::SESSION_SENTINEL . ' #' . $nonce,
				'score'              => 84,
				'source_message_ids' => '0',
				'last_seen'          => current_time( 'mysql' ),
			) );
			$session_write_ok = $session_result === 'insert' || $session_result === 'update';
			$emit(
				'Runtime session - owner write',
				$session_write_ok,
				'session write result=' . var_export( $session_result, true )
			);

			$session_record_id = $this->session_record_id( $blog_id, $session_session_id, $user_id, $session_memory_key );
			$session_file_row = BizCity_Business_JSONL_File_Store::find( self::SESSION_CONTRACT, $session_record_id, array( 'blog_id' => $blog_id ) );
			$session_file_ok = is_array( $session_file_row )
				&& (string) ( $session_file_row['memory_key'] ?? '' ) === $session_memory_key
				&& strpos( (string) ( $session_file_row['memory_text'] ?? '' ), self::SESSION_SENTINEL ) !== false;
			$emit(
				'Runtime session - filestore row exists',
				$session_file_ok,
				$session_file_ok ? ( 'record_id=' . $session_record_id ) : 'Filestore row not found for session sentinel.'
			);

			$session_reader_rows = BizCity_WebChat_Memory::get_memories( array(
				'session_id' => $session_session_id,
				'user_id'    => $user_id,
				'limit'      => 20,
			) );
			$session_reader_ok = false;
			if ( is_array( $session_reader_rows ) ) {
				foreach ( $session_reader_rows as $row ) {
					$key = is_object( $row ) ? (string) ( $row->memory_key ?? '' ) : (string) ( $row['memory_key'] ?? '' );
					if ( $key === $session_memory_key ) {
						$session_reader_ok = true;
						break;
					}
				}
			}
			$emit(
				'Runtime session - reader parity (API vs filestore)',
				$session_reader_ok && $session_file_ok,
				'session API rows=' . count( (array) $session_reader_rows ) . '; filestore=' . ( $session_file_ok ? 'hit' : 'miss' )
			);

			// [2026-08-29 Johnny Chu] PHASE-1.30-FILESTORE-CANON — keep the unified mirror gate separate from the canonical business filestore owner check.
			$emit( 'Runtime session - unified mirror evidence', 'skip', 'Optional unified dual-write evidence is owned by core.memory.unified.dual-write; filestore parity is independent.' );

			return array(
				'status'  => $pass ? 'pass' : 'fail',
				'summary' => $pass
					? 'Filestore reader parity passed for users/session memory owners; unified mirror is a separate optional gate.'
					: 'Filestore parity failed for one or more memory owners.',
				'steps'   => $steps,
			);
		} catch ( \Throwable $e ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Memory filestore parity probe threw an exception.',
				'error'   => $e->getMessage(),
				'steps'   => $steps,
			);
		} finally {
			$this->cleanup_sentinel_rows(
				$user_record_id,
				$session_record_id,
				$user_memory_key,
				$session_memory_key,
				$user_session_id,
				$session_session_id,
				$user_identity_uuid,
				$blog_id,
				$user_id
			);
		}
	}

	public function cleanup(): void {
		// Runtime cleanup uses exact sentinel IDs from run().
	}

	private function user_record_id( $blog_id, $user_id, $session_id, $identity_uuid, $memory_key ) {
		$scope = (int) $blog_id . '|' . (int) $user_id . '|' . (string) $session_id . '|' . (string) $identity_uuid . '|' . (string) $memory_key;
		if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
			return 'um_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
		}
		return 'um_' . hash( 'sha256', $scope );
	}

	private function session_record_id( $blog_id, $session_id, $user_id, $memory_key ) {
		$scope = (int) $blog_id . '|' . (string) $session_id . '|' . (int) $user_id . '|' . (string) $memory_key;
		if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
			return 'ws_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
		}
		return 'ws_' . hash( 'sha256', $scope );
	}

	private function table_exists( $table_name ) {
		global $wpdb;
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
	}

	private function cleanup_sentinel_rows( $user_record_id, $session_record_id, $user_memory_key, $session_memory_key, $user_session_id, $session_session_id, $user_identity_uuid, $blog_id, $user_id ) {
		global $wpdb;

		if ( $user_record_id !== '' ) {
			BizCity_Business_JSONL_File_Store::delete( self::USER_CONTRACT, $user_record_id, array( 'blog_id' => (int) $blog_id, 'user_id' => (int) $user_id, 'identity_uuid' => (string) $user_identity_uuid ) );
		}
		if ( $session_record_id !== '' ) {
			BizCity_Business_JSONL_File_Store::delete( self::SESSION_CONTRACT, $session_record_id, array( 'blog_id' => (int) $blog_id, 'user_id' => (int) $user_id ) );
		}

		$unified_table = class_exists( 'BizCity_Memory_Unified_Installer' ) ? BizCity_Memory_Unified_Installer::table() : '';
		if ( $unified_table !== '' && $this->table_exists( $unified_table ) ) {
			if ( $user_memory_key !== '' ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$unified_table} WHERE blog_id = %d AND user_id = %d AND session_id = %s AND identity_uuid = %s AND memory_class = %s AND memory_key = %s",
					(int) $blog_id,
					(int) $user_id,
					(string) $user_session_id,
					(string) $user_identity_uuid,
					'user',
					(string) $user_memory_key
				) );
			}
			if ( $session_memory_key !== '' ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$unified_table} WHERE blog_id = %d AND user_id = %d AND session_id = %s AND memory_class = %s AND memory_key = %s",
					(int) $blog_id,
					(int) $user_id,
					(string) $session_session_id,
					'session',
					'ws:' . (string) $session_memory_key
				) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Filestore_Parity';
	return $list;
} );
