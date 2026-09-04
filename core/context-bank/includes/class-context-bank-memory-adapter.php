<?php
/**
 * Context Bank memory adapter.
 *
 * Owns the shared admission and bounded read path for the five encrypted
 * business-memory contracts. The ledger stores pointers only; payloads remain
 * in BizCity_Business_JSONL_File_Store.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Memory_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Memory_Adapter {

	const RECORD_KIND = 'memory';

	private static $contracts = array(
		'user'     => 'core.knowledge.user_memory',
		'episodic' => 'core.intent.episodic_memory',
		'rolling'  => 'core.intent.rolling_memory',
		'session'  => 'modules.webchat.session_memory',
		'note'     => 'modules.twinchat.memory_notes',
	);

	/**
	 * Admit one filestore receipt into the pointer ledger.
	 *
	 * @param string $memory_class Memory owner class key.
	 * @param array  $reference   Owner fields plus filestore receipt.
	 * @return array
	 */
	public static function admit( $memory_class, array $reference ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — synchronously admit every memory receipt into the tenant pointer ledger.
		$memory_class = sanitize_key( (string) $memory_class );
		$contract_id = self::contract_for_class( $memory_class );
		$receipt = isset( $reference['receipt'] ) && is_array( $reference['receipt'] ) ? $reference['receipt'] : array();
		$record_id = (string) ( $receipt['record_id'] ?? $reference['record_id'] ?? '' );
		if ( $contract_id === '' || $record_id === '' || empty( $receipt ) ) {
			return array( 'ok' => false, 'reason' => 'memory_reference_shape_invalid' );
		}
		if ( (string) ( $receipt['contract_id'] ?? '' ) !== $contract_id ) {
			return array( 'ok' => false, 'reason' => 'memory_contract_mismatch' );
		}
		if ( ! self::load_runtime() || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'reason' => 'context_bank_ledger_unavailable' );
		}
		$table = BizCity_Context_Bank_Ledger::table();
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			return array( 'ok' => false, 'reason' => 'context_bank_ledger_not_provisioned' );
		}
		$normalized = array_merge( $reference, array(
			'source_contract_id' => $contract_id,
			'record_id'         => $record_id,
			'record_kind'       => self::RECORD_KIND,
			'operation'         => (string) ( $receipt['operation'] ?? $reference['operation'] ?? 'upsert' ),
			'lifecycle_status'  => (string) ( $reference['lifecycle_status'] ?? 'active' ),
			'scope_key'         => (string) ( $reference['scope_key'] ?? self::scope_key( $reference ) ),
			'entity_type'       => (string) ( $reference['entity_type'] ?? $memory_class ),
			'entity_key'        => (string) ( $reference['entity_key'] ?? $reference['conversation_id'] ?? $reference['session_id'] ?? $reference['memory_key'] ?? $record_id ),
			'receipt'           => $receipt,
		) );
		return BizCity_Context_Bank_Ledger::instance()->record( $normalized );
	}

	/**
	 * Admit a tombstone receipt.
	 *
	 * @param string $memory_class Memory owner class key.
	 * @param array  $reference   Receipt and owner scope.
	 * @return array
	 */
	public static function tombstone( $memory_class, array $reference ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — preserve delete receipt and invalidate the exact Context Bank pointer.
		$reference['operation'] = 'delete';
		$reference['lifecycle_status'] = 'deleted';
		return self::admit( $memory_class, $reference );
	}

	/**
	 * Read verified business records through ledger pointers only.
	 *
	 * @param string $contract_id Memory contract ID.
	 * @param array  $filters     Ledger filters plus limit.
	 * @return array<int,array>
	 */
	public static function query( $contract_id, array $filters = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — replace direct day-file scans with bounded ledger query and verified pointer follow.
		if ( ! self::load_runtime() || ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return array();
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) ) {
			return array();
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — restrict memory reads to the server-resolved owner before querying ledger metadata.
		$scope = BizCity_Context_Bank_Access::scope_filters( $filters );
		if ( empty( $scope['ok'] ) ) {
			return array();
		}
		$filters = is_array( $scope['filters'] ?? null ) ? $scope['filters'] : $filters;
		$contract_id = (string) $contract_id;
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$ledger_filters = array(
			'source_contract_id' => $contract_id,
			'record_kind'        => self::RECORD_KIND,
			'blog_id'            => (int) ( $filters['blog_id'] ?? get_current_blog_id() ),
			'limit'              => min( 500, $limit * 3 ),
		);
		foreach ( array( 'record_id', 'identity_uuid', 'scope_key', 'trace_id', 'lifecycle_status' ) as $field ) {
			if ( isset( $filters[ $field ] ) && (string) $filters[ $field ] !== '' ) {
				$ledger_filters[ $field ] = (string) $filters[ $field ];
			}
		}
		if ( isset( $filters['conversation_id'] ) && (string) $filters['conversation_id'] !== '' ) {
			$ledger_filters['entity_type'] = 'rolling';
			$ledger_filters['entity_key'] = (string) $filters['conversation_id'];
		}
		if ( isset( $filters['session_id'] ) && (string) $filters['session_id'] !== '' ) {
			$ledger_filters['entity_type'] = isset( $filters['entity_type'] ) ? (string) $filters['entity_type'] : 'session';
			$ledger_filters['entity_key'] = (string) $filters['session_id'];
		}
		foreach ( array( 'wp_user_id', 'user_id', 'contact_id', 'conversation_id', 'notebook_id' ) as $field ) {
			if ( isset( $filters[ $field ] ) && (int) $filters[ $field ] > 0 ) {
				$ledger_field = 'user_id' === $field ? 'wp_user_id' : $field;
				$ledger_filters[ $ledger_field ] = (int) $filters[ $field ];
			}
		}
		$rows = BizCity_Context_Bank_Ledger::instance()->find( $ledger_filters );
		$out = array();
		foreach ( (array) $rows as $pointer ) {
			if ( count( $out ) >= $limit || ! is_array( $pointer ) ) {
				break;
			}
			if ( (string) ( $pointer['operation'] ?? 'upsert' ) === 'delete' || (string) ( $pointer['lifecycle_status'] ?? '' ) === 'deleted' ) {
				continue;
			}
			$verified = BizCity_Context_Bank_Ledger::instance()->follow( (string) ( $pointer['record_id'] ?? '' ), $ledger_filters );
			if ( empty( $verified['ok'] ) ) {
				continue;
			}
			$followed = BizCity_Business_JSONL_File_Store::read_receipt( $contract_id, $pointer );
			if ( ! is_array( $followed ) || empty( $followed['ok'] ) || ! is_array( $followed['record'] ?? null ) ) {
				continue;
			}
			$record = $followed['record'];
			if ( ! self::matches_record( $record, $filters ) ) {
				continue;
			}
			if ( isset( $filters['filter'] ) && is_callable( $filters['filter'] ) && ! call_user_func( $filters['filter'], $record ) ) {
				continue;
			}
			$out[] = $record;
		}
		return $out;
	}

	/** @return string */
	public static function contract_for_class( $memory_class ) {
		$key = sanitize_key( (string) $memory_class );
		return isset( self::$contracts[ $key ] ) ? self::$contracts[ $key ] : '';
	}

	/** @return array<string,string> */
	public static function contracts() {
		return self::$contracts;
	}

	private static function scope_key( array $reference ) {
		$identity = trim( (string) ( $reference['identity_uuid'] ?? '' ) );
		if ( $identity !== '' ) {
			return $identity;
		}
		$user_id = (int) ( $reference['user_id'] ?? 0 );
		$session = sanitize_key( (string) ( $reference['session_id'] ?? '' ) );
		return 'u_' . $user_id . ( $session !== '' ? '_s_' . $session : '' );
	}

	private static function load_runtime() {
		if ( class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			return false;
		}
		BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.memory_adapter' );
		return class_exists( 'BizCity_Context_Bank_Ledger' );
	}

	private static function matches_record( array $record, array $filters ) {
		foreach ( array( 'record_id', 'blog_id', 'user_id', 'identity_uuid', 'event_type', 'session_id', 'conversation_id', 'notebook_id' ) as $field ) {
			if ( ! array_key_exists( $field, $filters ) || $filters[ $field ] === '' || $filters[ $field ] === null ) {
				continue;
			}
			if ( (string) ( $record[ $field ] ?? '' ) !== (string) $filters[ $field ] ) {
				return false;
			}
		}
		return true;
	}
}
