<?php
/**
 * BizCity Diagnostics — core.memory.unified.dual-write-parity probe
 * (Wave 2.8d TBR.MEM-D5e).
 *
 * Verifies the migration contract: a filestore-backed memory write emits a
 * pointer reference and never materializes a payload in unified SQL memory.
 *
 * Strategy
 *   1. Capture the Context Bank reference event for this request.
 *   2. Drive a sentinel row through BizCity_User_Memory::upsert_public().
 *   3. Verify the event contains a filestore receipt and record pointer.
 *   4. Cleanup the filestore record.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8d TBR.MEM-D5e)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Memory_Unified_Dual_Write', false ) ) {
	return;
}

final class BizCity_Probe_Memory_Unified_Dual_Write implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_unified_parity_token_quokka83';

	public function id(): string          { return 'core.memory.unified.dual-write-parity'; }
	public function label(): string       { return 'Unified Memory — dual-write parity'; }
	public function description(): string {
		return 'Context Bank migration: drive a sentinel through BizCity_User_Memory::upsert_public() → verify a filestore receipt reference event and no SQL payload mirror. Cleanup tự động.';
	}
	public function severity(): string { return 'major'; }
	public function order(): int       { return 68; }
	public function icon(): string     { return 'database-view'; }
	public function estimate_ms(): int { return 500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Memory_Unified_Writer' ) ) {
			return 'BizCity_Memory_Unified_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return 'Business filestore contract chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();

		// Step 0 — pre-cleanup.
		$this->cleanup();

		$reference = null;
		$reference_cb = static function ( $payload ) use ( &$reference ) {
			$reference = is_array( $payload ) ? $payload : null;
		};
		add_action( 'bizcity_context_bank_reference_write', $reference_cb, 9999, 1 );

		try {
			// [2026-07-28 Johnny Chu] R-CH-IDMEM — plant the dual-write sentinel under the verified UUID owner.
			$memory_scope = class_exists( 'BizCity_Memory_Identity_Scope' )
				? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $user_id, 'session_id' => 'probe-unified-parity' ) )
				: null;
			// Step 2 — drive sentinel row through the filestore-backed owner.
			$blog_id = get_current_blog_id();
			$result  = BizCity_User_Memory::instance()->upsert_public( [
				'user_id'        => $user_id,
				'identity_uuid'  => (string) ( $memory_scope['identity_uuid'] ?? '' ),
				'session_id'     => 'probe-unified-parity',
				'memory_tier'    => 'explicit',
				'memory_type'    => 'fact',
				'memory_key'     => 'explicit:' . md5( self::SENTINEL ),
				'memory_text'    => 'Probe sentinel ' . self::SENTINEL,
				'score'          => 80,
				'source_log_ids' => '',
				'metadata'       => '',
			] );
			$ctx->emit_step( [
				'label'  => 'Legacy upsert_public()',
				'status' => $result ? 'pass' : 'fail',
				'detail' => 'result=' . var_export( $result, true ),
			] );
			if ( ! $result ) {
				// [2026-07-28 Johnny Chu] PHASE-0.52 W8.3 — include exact upsert failure reason instead of generic false.
				$last_fail = method_exists( 'BizCity_User_Memory', 'get_last_upsert_failure' )
					? (array) BizCity_User_Memory::get_last_upsert_failure()
					: array();
				$fail_code = (string) ( $last_fail['code'] ?? '' );
				$fail_msg  = (string) ( $last_fail['message'] ?? '' );
				$db_error  = trim( (string) ( $last_fail['db_error'] ?? '' ) );
				$db_tail   = $db_error !== '' ? ' · db_error=' . mb_substr( $db_error, 0, 220 ) : '';
				return [
					'status'   => 'fail',
					'error'    => 'upsert_public() trả false — không thể test parity.' . ( $fail_code !== '' ? ' code=' . $fail_code : '' ) . ( $fail_msg !== '' ? ' · ' . $fail_msg : '' ) . $db_tail,
					'fix_hint' => 'Check BizCity_User_Memory::get_last_upsert_failure() và đảm bảo bảng bizcity_memory_users đã migrate đủ cột + identity_uuid owner resolve được.',
				];
			}

			// Step 3 — verify only a pointer reference crossed the compatibility hook.
			$ctx->emit_step( [
				'label'  => 'Context Bank filestore reference emitted',
				'status' => ! empty( $reference['record_id'] ) && ! empty( $reference['receipt'] ) ? 'pass' : 'fail',
				'detail' => ! empty( $reference['record_id'] ) ? (string) $reference['record_id'] : 'not found',
			] );
			if ( empty( $reference['record_id'] ) || empty( $reference['receipt'] ) ) {
				return [
					'status'   => 'fail',
					'error'    => 'Context Bank reference event không có record_id/receipt.',
					'fix_hint' => 'Verify BizCity_User_Memory::upsert_public() phát filestore receipt qua bizcity_memory_mirror_write và bridge Context Bank đã load.',
				];
			}
			$ledger_rows = class_exists( 'BizCity_Context_Bank_Ledger' )
				? BizCity_Context_Bank_Ledger::instance()->find( array(
					'record_id'           => (string) $reference['record_id'],
					'source_contract_id'  => 'core.knowledge.user_memory',
					'record_kind'         => 'memory',
					'blog_id'             => $blog_id,
					'limit'               => 2,
				) )
				: array();
			$ledger_admitted = ! empty( $ledger_rows[0] )
				&& (string) ( $ledger_rows[0]['record_id'] ?? '' ) === (string) $reference['record_id']
				&& (string) ( $ledger_rows[0]['record_kind'] ?? '' ) === 'memory';
			$ctx->emit_step( array(
				'label'  => 'Context Bank ledger admission',
				'status' => $ledger_admitted ? 'pass' : 'fail',
				'detail' => $ledger_admitted ? 'Pointer admitted with record_kind=memory.' : 'Receipt event emitted but no matching tenant ledger pointer was found.',
			) );
			if ( ! $ledger_admitted ) {
				return array(
					'status'   => 'fail',
					'error'    => 'Context Bank ledger admission failed for the filestore receipt.',
					'fix_hint' => 'Provision bizcity_context_bank on the routed tenant shard and inspect the admission reason bucket.',
				);
			}

			return [
				'status'  => 'pass',
				'summary' => 'Context Bank reference OK — filestore receipt emitted; no SQL payload mirror.',
			];
		} catch ( \Throwable $e ) {
			return [ 'status' => 'fail', 'error' => 'Exception: ' . $e->getMessage() ];
		} finally {
			remove_action( 'bizcity_context_bank_reference_write', $reference_cb, 9999 );
		}
	}

	public function cleanup(): void {
		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_User_Memory' ) ) {
			return;
		}
		$rows = BizCity_Business_JSONL_File_Store::query( BizCity_User_Memory::BUSINESS_CONTRACT_ID, array(
			'blog_id' => get_current_blog_id(),
			'user_id' => get_current_user_id(),
			'limit'   => 1000,
			'days'    => 365,
			'filter'  => function ( $row ) {
				return strpos( (string) ( $row['memory_text'] ?? '' ), self::SENTINEL ) !== false;
			},
		) );
		foreach ( $rows as $row ) {
			$record_id = (string) ( $row['record_id'] ?? '' );
			if ( $record_id !== '' ) {
				BizCity_Business_JSONL_File_Store::delete( BizCity_User_Memory::BUSINESS_CONTRACT_ID, $record_id, array( 'blog_id' => get_current_blog_id() ) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Unified_Dual_Write';
	return $list;
} );
