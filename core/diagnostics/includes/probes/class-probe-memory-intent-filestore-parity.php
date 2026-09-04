<?php
/**
 * BizCity Diagnostics - filestore parity for intent memory owners.
 *
 * Runtime evidence (non-destructive):
 * 1) Drive one rolling-memory row through owner hook.
 * 2) Verify contract-backed filestore row + owner reader parity.
 * 3) Drive one episodic-memory row through owner hook.
 * 4) Verify contract-backed filestore row + owner reader parity.
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

if ( class_exists( 'BizCity_Probe_Memory_Intent_Filestore_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Memory_Intent_Filestore_Parity implements BizCity_Diagnostics_Probe {

	const EPISODIC_CONTRACT = 'core.intent.episodic_memory';
	const ROLLING_CONTRACT  = 'core.intent.rolling_memory';
	const EPISODIC_SENTINEL = '__healthtest_filestore_episodic_parity_lark21';
	const ROLLING_SENTINEL  = '__healthtest_filestore_rolling_parity_lark21';

	public function id(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — expose stable probe identity for replacement evidence.
		return 'core.memory.intent_filestore_parity';
	}

	public function label(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — expose the intent-memory filestore parity label.
		return 'Intent memory filestore parity';
	}

	public function description(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — describe owner write/read evidence covered by this probe.
		return 'Writes synthetic rolling and episodic records through owner hooks, then verifies contract-backed filestore rows and owner reader parity.';
	}

	public function severity(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — classify missing intent-memory replacement evidence as critical.
		return 'critical';
	}

	public function order(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — place intent-memory replacement evidence after existing memory probes.
		return 81;
	}

	public function icon(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — provide the diagnostics catalog icon.
		return 'database';
	}

	public function estimate_ms(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — declare the bounded runtime estimate.
		return 700;
	}

	public function precondition() {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — require filestore owners and authenticated runtime scope before execution.
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'filestore_classes_missing', 'Filestore contract/store classes are not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Episodic_Memory' ) || ! class_exists( 'BizCity_Rolling_Memory' ) ) {
			return new WP_Error( 'intent_memory_owner_missing', 'Intent memory owner classes are not loaded.' );
		}
		if ( get_current_user_id() <= 0 ) {
			return new WP_Error( 'admin_required', 'Probe requires an authenticated admin user.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — run focused rolling/episodic filestore write/read parity.
		$steps = array();
		$pass = true;

		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();
		$nonce = substr( md5( (string) microtime( true ) . '|' . (string) $user_id . '|' . wp_rand() ), 0, 12 );
		$session_id = 'diag-intent-filestore-' . $nonce;
		$conversation_id = 'diag-intent-conv-' . $nonce;

		$rolling_record_id = '';
		$episodic_record_id = '';
		$identity_uuid = '';

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

		$contracts_ok = BizCity_File_Contract_Registry::has( self::EPISODIC_CONTRACT )
			&& BizCity_File_Contract_Registry::has( self::ROLLING_CONTRACT );
		$emit(
			'Disk/Loader - intent memory business contracts registered',
			$contracts_ok,
			$contracts_ok ? 'core.intent.episodic_memory + core.intent.rolling_memory contracts are available.' : 'One or both intent memory contracts are missing.'
		);
		if ( ! $contracts_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Intent memory business contracts are not fully registered.',
				'steps'   => $steps,
			);
		}

		$constants_ok = defined( 'BizCity_Episodic_Memory::BUSINESS_CONTRACT_ID' )
			&& defined( 'BizCity_Rolling_Memory::BUSINESS_CONTRACT_ID' )
			&& BizCity_Episodic_Memory::BUSINESS_CONTRACT_ID === self::EPISODIC_CONTRACT
			&& BizCity_Rolling_Memory::BUSINESS_CONTRACT_ID === self::ROLLING_CONTRACT;
		$emit(
			'Disk - owner classes point to canonical contracts',
			$constants_ok,
			$constants_ok ? 'BUSINESS_CONTRACT_ID constants match the registered filestore contracts.' : 'Owner BUSINESS_CONTRACT_ID constants do not match canonical contract IDs.'
		);
		if ( ! $constants_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Owner contract constants are not aligned with canonical filestore contracts.',
				'steps'   => $steps,
			);
		}

		try {
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - resolve one stable owner scope for both rolling and episodic write parity assertions.
			$scope = class_exists( 'BizCity_Memory_Identity_Scope' )
				? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $user_id, 'session_id' => $session_id ) )
				: array( 'user_id' => $user_id, 'session_id' => $session_id, 'blog_id' => $blog_id, 'identity_uuid' => '' );
			$identity_uuid = (string) ( $scope['identity_uuid'] ?? '' );
			$identity_ok = $identity_uuid !== '';
			$emit(
				'Runtime - identity owner resolved',
				$identity_ok,
				$identity_ok ? ( 'identity_uuid=' . $identity_uuid ) : 'identity_uuid is missing for intent memory owner scope.'
			);
			if ( ! $identity_ok ) {
				return array(
					'status'  => 'fail',
					'summary' => 'Unable to resolve identity_uuid for intent memory parity test.',
					'steps'   => $steps,
				);
			}

			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - rolling-memory parity uses owner hook write path then validates filestore and owner readers.
			$rolling_goal = self::ROLLING_SENTINEL . '-' . $nonce;
			BizCity_Rolling_Memory::instance()->on_intent_processed(
				array(
					'conversation_id' => $conversation_id,
					'goal'            => $rolling_goal,
					'goal_label'      => $rolling_goal,
					'status'          => 'IN_PROGRESS',
					'action'          => 'reply',
				),
				array(
					'user_id'    => $user_id,
					'session_id' => $session_id,
					'message'    => self::ROLLING_SENTINEL . ' message ' . $nonce,
				)
			);

			$rolling_record_id = $this->rolling_record_id( $blog_id, $user_id, $identity_uuid, $conversation_id );
			$rolling_file_row = BizCity_Business_JSONL_File_Store::find( self::ROLLING_CONTRACT, $rolling_record_id, array( 'blog_id' => $blog_id ) );
			$rolling_file_ok = is_array( $rolling_file_row )
				&& (string) ( $rolling_file_row['conversation_id'] ?? '' ) === $conversation_id
				&& (string) ( $rolling_file_row['identity_uuid'] ?? '' ) === $identity_uuid
				&& strpos( (string) ( $rolling_file_row['goal_label'] ?? '' ), self::ROLLING_SENTINEL ) !== false;
			$emit(
				'Runtime rolling - filestore row exists',
				$rolling_file_ok,
				$rolling_file_ok ? ( 'record_id=' . $rolling_record_id ) : 'Rolling filestore row not found for synthetic conversation.'
			);

			$rolling_row = BizCity_Rolling_Memory::instance()->get_by_conversation( $conversation_id, $identity_uuid );
			$rolling_reader_ok = is_object( $rolling_row )
				&& (string) ( $rolling_row->conversation_id ?? '' ) === $conversation_id;
			$active_rows = BizCity_Rolling_Memory::instance()->get_active_for_user( $user_id, $session_id, $identity_uuid );
			$active_hit = false;
			foreach ( (array) $active_rows as $item ) {
				if ( is_object( $item ) && (string) ( $item->conversation_id ?? '' ) === $conversation_id ) {
					$active_hit = true;
					break;
				}
			}
			$emit(
				'Runtime rolling - owner reader parity',
				$rolling_reader_ok && $active_hit && $rolling_file_ok,
				'get_by_conversation=' . ( $rolling_reader_ok ? 'hit' : 'miss' ) . '; get_active_for_user rows=' . count( (array) $active_rows ) . '; filestore=' . ( $rolling_file_ok ? 'hit' : 'miss' )
			);

			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - episodic-memory parity uses the owner tool-event path, avoiding completion-summary provider calls in diagnostics.
			$episodic_goal = self::EPISODIC_SENTINEL . '-' . $nonce;
			$episodic_tool = 'diagnostic_' . $nonce;
			BizCity_Episodic_Memory::instance()->on_intent_processed(
				array(
					'conversation_id' => $conversation_id,
					'goal'            => $episodic_goal,
					'goal_label'      => $episodic_goal,
					'status'          => 'IN_PROGRESS',
					'action'          => 'call_tool',
					'meta'            => array( 'tool_name' => $episodic_tool ),
				),
				array(
					'user_id'    => $user_id,
					'session_id' => $session_id,
					'message'    => self::EPISODIC_SENTINEL . ' message ' . $nonce,
				)
			);

			$event_key = 'tool_usage:' . $episodic_goal . ':' . $episodic_tool;
			$episodic_record_id = $this->episodic_record_id( $blog_id, $user_id, $identity_uuid, $event_key );
			$episodic_file_row = BizCity_Business_JSONL_File_Store::find( self::EPISODIC_CONTRACT, $episodic_record_id, array( 'blog_id' => $blog_id ) );
			$episodic_file_ok = is_array( $episodic_file_row )
				&& (string) ( $episodic_file_row['event_key'] ?? '' ) === $event_key
				&& (string) ( $episodic_file_row['identity_uuid'] ?? '' ) === $identity_uuid
				&& strpos( (string) ( $episodic_file_row['event_text'] ?? '' ), $episodic_goal ) !== false;
			$emit(
				'Runtime episodic - filestore row exists',
				$episodic_file_ok,
				$episodic_file_ok ? ( 'record_id=' . $episodic_record_id ) : 'Episodic filestore row not found for synthetic completed goal.'
			);

			$context = BizCity_Episodic_Memory::instance()->build_context( $user_id, $episodic_goal, $identity_uuid );
			$context_ok = is_string( $context ) && strpos( $context, $episodic_goal ) !== false;
			$emit(
				'Runtime episodic - owner reader parity',
				$context_ok && $episodic_file_ok,
				'build_context=' . ( $context_ok ? 'contains_sentinel_goal' : 'missing_sentinel_goal' ) . '; filestore=' . ( $episodic_file_ok ? 'hit' : 'miss' )
			);

			return array(
				'status'  => $pass ? 'pass' : 'fail',
				'summary' => $pass
					? 'Intent memory filestore parity passed for rolling and episodic owners.'
					: 'Intent memory filestore parity failed for one or more owner checks.',
				'steps'   => $steps,
			);
		} catch ( \Throwable $e ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Intent memory filestore parity probe threw an exception.',
				'error'   => $e->getMessage(),
				'steps'   => $steps,
			);
		} finally {
			if ( $rolling_record_id !== '' ) {
				BizCity_Business_JSONL_File_Store::delete( self::ROLLING_CONTRACT, $rolling_record_id, array(
					'blog_id'       => $blog_id,
					'user_id'       => $user_id,
					'identity_uuid' => $identity_uuid,
				) );
			}
			if ( $episodic_record_id !== '' ) {
				BizCity_Business_JSONL_File_Store::delete( self::EPISODIC_CONTRACT, $episodic_record_id, array(
					'blog_id'       => $blog_id,
					'user_id'       => $user_id,
					'identity_uuid' => $identity_uuid,
				) );
			}
		}
	}

	public function cleanup(): void {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — owner probe uses tombstones in run() finally for cleanup.
	}

	private function rolling_record_id( $blog_id, $user_id, $identity_uuid, $conversation_id ) {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — derive the rolling record key from tenant and verified owner scope.
		$scope = (int) $blog_id . '|' . (string) $identity_uuid . '|' . (int) $user_id . '|' . (string) $conversation_id;
		if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
			return 'rm_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
		}
		return 'rm_' . hash( 'sha256', $scope );
	}

	private function episodic_record_id( $blog_id, $user_id, $identity_uuid, $event_key ) {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — derive the episodic record key from tenant and verified owner scope.
		$scope = (int) $blog_id . '|' . (string) $identity_uuid . '|' . (int) $user_id . '|' . (string) $event_key;
		if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
			return 'ep_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
		}
		return 'ep_' . hash( 'sha256', $scope );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Intent_Filestore_Parity';
	return $list;
} );
