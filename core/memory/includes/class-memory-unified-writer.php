<?php
/**
 * BizCity Memory — Unified Mirror Writer (Wave 2.8d TBR.MEM-D5).
 *
	 * Compatibility event bridge for memory owners during the Context Bank
	 * migration. It no longer writes memory payloads to `bizcity_memory`.
 *
 * Listens on action `bizcity_memory_mirror_write` emitted by legacy writers:
 *
 *   do_action( 'bizcity_memory_mirror_write', $class, $row, $result );
 *
 *   $class  = 'user' | 'episodic' | 'rolling' | 'session' | 'note'
 *   $row    = canonical fields cho row legacy vừa ghi (assoc array)
 *   $result = 'insert' | 'update' | int row id | bool
 *
	 * Receipt-bearing events are reduced to references and forwarded to the
	 * Context Bank adapter hook. Failures NEVER throw.
 *
 * Schema reference: core/memory/PHASE-MEMORY-CONSOLIDATION.md §2.1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @since      Wave 2.8d (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Memory_Unified_Writer {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'bizcity_memory_mirror_write', [ $this, 'on_mirror_write' ], 20, 3 );
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — consume canonical delete events so unified rows cannot outlive legacy owners.
		add_action( 'bizcity_memory_mirror_delete', [ $this, 'on_mirror_delete' ], 20, 3 );
	}

	/**
	 * Main listener — dispatch per class.
	 *
	 * @param string             $class  'user'|'episodic'|'rolling'|'session'|'note'
	 * @param array              $row    Legacy row data (assoc).
	 * @param string|int|bool    $result Legacy writer result for context.
	 */
	public function on_mirror_write( $class, $row, $result = null ): void {
		if ( ! is_array( $row ) || ! is_string( $class ) || $class === '' ) {
			return;
		}
		$receipt = isset( $row['filestore_receipt'] ) && is_array( $row['filestore_receipt'] )
			? $row['filestore_receipt']
			: array();
		if ( empty( $receipt ) ) {
			return;
		}

		try {
			// [2026-09-01 Johnny Chu] PHASE-CB4.5 — load the Context Bank boundary lazily at the actual memory admission point.
			$this->load_context_bank_runtime();
			// [2026-09-01 Johnny Chu] PHASE-CB4.4 — forward only a filestore pointer; never mirror payload fields into SQL.
			$reference = array(
				'memory_class' => sanitize_key( $class ),
				'record_kind'  => 'memory',
				'source_contract_id' => (string) ( $receipt['contract_id'] ?? '' ),
				'operation'    => is_scalar( $result ) ? (string) $result : 'upsert',
				'blog_id'      => (int) ( $row['blog_id'] ?? get_current_blog_id() ),
				'user_id'      => (int) ( $row['user_id'] ?? 0 ),
				'identity_uuid' => (string) ( $row['identity_uuid'] ?? '' ),
				'session_id'   => (string) ( $row['session_id'] ?? '' ),
				'memory_tier'  => (string) ( $row['memory_tier'] ?? '' ),
				'memory_type'  => (string) ( $row['memory_type'] ?? '' ),
				'memory_key'   => (string) ( $row['memory_key'] ?? '' ),
				'conversation_id' => (string) ( $row['conversation_id'] ?? '' ),
				'notebook_id' => (int) ( $row['notebook_id'] ?? 0 ),
				'entity_type' => (string) ( $row['entity_type'] ?? sanitize_key( $class ) ),
				'entity_key'  => (string) ( $row['entity_key'] ?? $row['conversation_id'] ?? $row['session_id'] ?? $row['memory_key'] ?? '' ),
				'trace_id'    => (string) ( $row['trace_id'] ?? '' ),
				'record_id'    => (string) ( $receipt['record_id'] ?? $row['record_id'] ?? '' ),
				'receipt'      => $receipt,
			);
			// [2026-09-01 Johnny Chu] PHASE-CB4.5 — synchronously admit the receipt so a memory write cannot be mistaken for Context Bank completion when the hook is absent.
			if ( class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ) {
				BizCity_Context_Bank_Memory_Adapter::admit( $class, $reference );
			}
			// Preserve the compatibility event for diagnostics and non-ledger listeners.
			do_action( 'bizcity_context_bank_reference_write', $reference );
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Memory_Unified_Writer] reference ' . $class . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Delete one mirrored legacy row by class and legacy id.
	 *
	 * @param string     $class  Memory class.
	 * @param int        $id     Legacy row id.
	 * @param array|null $scope  Optional blog/identity scope.
	 */
	public function on_mirror_delete( $class, $id, $scope = null ): void {
		$class = sanitize_key( (string) $class );
		$id    = (int) $id;
		$record_id = is_array( $scope ) ? (string) ( $scope['record_id'] ?? '' ) : '';
		$receipt = is_array( $scope ) && is_array( $scope['filestore_receipt'] ?? null ) ? $scope['filestore_receipt'] : array();
		if ( $class === '' || ( $id <= 0 && $record_id === '' ) || empty( $receipt ) ) {
			return;
		}

		try {
			// [2026-09-01 Johnny Chu] PHASE-CB4.5 — load the same boundary for receipt-bearing tombstones.
			$this->load_context_bank_runtime();
			$blog_id = is_array( $scope ) && isset( $scope['blog_id'] )
				? (int) $scope['blog_id']
				: (int) get_current_blog_id();
			// [2026-09-01 Johnny Chu] PHASE-CB4.5 — represent deletes as receipt-bearing Context Bank tombstones, never SQL deletes.
			$reference = array(
				'memory_class' => $class,
				'record_id'   => $record_id,
				'receipt'     => $receipt,
				'record_kind' => 'memory',
				'source_contract_id' => (string) ( $receipt['contract_id'] ?? '' ),
				'legacy_id'   => $id,
				'blog_id'     => $blog_id,
				'scope'       => is_array( $scope ) ? $scope : array(),
			);
			if ( class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ) {
				BizCity_Context_Bank_Memory_Adapter::tombstone( $class, $reference );
			}
			do_action( 'bizcity_context_bank_reference_delete', $reference );
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Memory_Unified_Writer] tombstone reference ' . $class . ' failed: ' . $e->getMessage() );
		}
	}

	private function load_context_bank_runtime(): bool {
		if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
			return (bool) bizcity_context_bank_load_memory_runtime();
		}
		if ( class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false )
			|| ! is_file( $bootstrap )
			|| ! is_readable( $bootstrap ) ) {
			return false;
		}
		BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.memory_writer' );
		return class_exists( 'BizCity_Context_Bank_Memory_Adapter' );
	}

}
