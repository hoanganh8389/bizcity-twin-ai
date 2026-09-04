<?php
/**
 * Canonical tenant-scoped pointer index for shared JSONL logs.
 *
 * The JSONL file is canonical. This table stores only searchable pointers and
 * redacted correlation metadata so it can be rebuilt without data loss.
 *
 * Cache Contract: group `bzlogidx`; keys include blog/database and all search
 * filters; writes, purge and reconcile flush the group; TTL is short.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Log_Index', false ) ) {
	return;
}

final class BizCity_Log_Index {

	const TABLE_SUFFIX      = 'bizcity_log_index';
	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'bizcity_log_index_db_version';
	const RECONCILE_CURSOR_OPTION = 'bizcity_log_index_reconcile_cursor_v1';

	private static $table_ready = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function ensure() {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — provision one rebuildable pointer index through the central installer path.
		global $wpdb;
		self::register_schema();
		$table = self::table();
		if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $table ) && (string) get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return true;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( class_exists( 'BizCity_Safe_Loader' ) && is_file( $upgrade_file ) && is_readable( $upgrade_file ) ) {
				BizCity_Safe_Loader::require_file( $upgrade_file, 'helper.log_index.dbdelta' );
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return false;
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_uuid CHAR(36) NOT NULL,
			contract_id VARCHAR(191) NOT NULL,
			jsonl_folder VARCHAR(191) NOT NULL,
			jsonl_module VARCHAR(191) NOT NULL,
			log_date DATE NOT NULL,
			ts DATETIME NOT NULL,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			event VARCHAR(191) NOT NULL,
			ref_id VARCHAR(191) NULL,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			relative_file VARCHAR(255) NOT NULL,
			byte_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
			row_hash CHAR(64) NOT NULL,
			meta_json TEXT NULL,
			indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_blog_event (blog_id, event_uuid),
			KEY idx_contract_date (contract_id, log_date),
			KEY idx_event (event),
			KEY idx_ref (ref_id),
			KEY idx_blog_date (blog_id, log_date)
		) {$charset};";
		dbDelta( $sql );
		// [2026-09-02 Johnny Chu] R-METADATA-CACHE — clear a cached missing-table result before verifying the pointer index created by dbDelta().
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $table );
		}
		$exists = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $table ) : false;
		if ( $exists ) {
			self::set_table_ready( true );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
		return $exists;
	}

	public static function is_available() {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — maintenance callers must avoid repeated metadata errors when the optional pointer table is not provisioned.
		$key = self::availability_key();
		if ( isset( self::$table_ready[ $key ] ) ) {
			return (bool) self::$table_ready[ $key ];
		}
		self::$table_ready[ $key ] = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( self::table() );
		return self::$table_ready[ $key ];
	}

	public static function reset_availability() {
		self::$table_ready[ self::availability_key() ] = null;
		unset( self::$table_ready[ self::availability_key() ] );
	}

	public static function is_enabled( $contract_id = '', array $row = array() ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G4 — expose one owner-controlled rollback boundary; disabled indexing never disables canonical JSONL writes.
		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}
		return (bool) apply_filters( 'bizcity_log_index_enabled', true, (string) $contract_id, $row );
	}

	public static function register_schema() {
		// [2026-08-27 Johnny Chu] R-CR — late helper loading must still register the table before Site Provisioner invokes dbDelta().
		if ( ! class_exists( 'BizCity_Schema_Registry' ) || BizCity_Schema_Registry::is_registered( self::TABLE_SUFFIX ) ) {
			return;
		}
		BizCity_Schema_Registry::register(
			self::TABLE_SUFFIX,
			'core.helper',
			self::DB_VERSION,
			self::DB_VERSION_OPTION,
			array( __CLASS__, 'ensure' )
		);
	}

	public static function record( $contract_id, array $row, array $pointer = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — index only a registry-approved contract after its JSONL row is durable.
		try {
			if ( ! self::is_enabled( $contract_id, $row ) ) {
				return false;
			}
			if ( function_exists( 'apply_filters' ) && ! apply_filters( 'bizcity_log_index_allow_record', true, $contract_id, $row ) ) {
				return false;
			}
			if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
				return false;
			}
			$contract = BizCity_Log_Contract_Registry::get( $contract_id );
			if ( ! is_array( $contract ) || empty( $contract['indexed'] ) || (string) ( $contract['storage_scope'] ?? 'blog' ) !== 'blog' ) {
				return false;
			}
			global $wpdb;
			$ready_key = self::availability_key();
			if ( isset( self::$table_ready[ $ready_key ] ) && ! self::$table_ready[ $ready_key ] ) {
				return false;
			}
			if ( ! isset( self::$table_ready[ $ready_key ] ) ) {
				self::$table_ready[ $ready_key ] = self::is_available();
			}
			if ( ! self::$table_ready[ $ready_key ] ) {
				return false;
			}
			$folder = (string) ( $contract['jsonl_folder'] ?? '' );
			$module = (string) ( $contract['jsonl_module'] ?? '' );
			if ( $folder === '' || $module === '' ) {
				return false;
			}
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$event_uuid = self::event_uuid( $contract_id, $row );
			$ts = self::mysql_timestamp( (string) ( $row['ts'] ?? $row['created_at'] ?? '' ) );
			$log_date = substr( $ts, 0, 10 );
			$line = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : json_encode( $row );
			$row_hash = (string) ( $pointer['row_hash'] ?? hash( 'sha256', is_string( $line ) ? $line : serialize( $row ) ) );
			$relative_file = (string) ( $pointer['relative_file'] ?? ( $folder . '/' . $module . '/' . $log_date . '.jsonl' ) );
			$path_prefix = $folder . '/' . $module . '/';
			if ( strpos( str_replace( '\\', '/', $relative_file ), $path_prefix ) !== 0 || strpos( $relative_file, '..' ) !== false ) {
				return false;
			}
			$byte_offset = max( 0, (int) ( $pointer['byte_offset'] ?? 0 ) );
			$ref_id = self::reference_id( $row );
			$meta_json = self::meta_json( $row );
			// [2026-08-30 Johnny Chu] R-LOG-IDEMPOTENCY - avoid a duplicate unique-key query for an already indexed event.
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT contract_id, relative_file, byte_offset, row_hash FROM ' . self::table() . ' WHERE blog_id = %d AND event_uuid = %s LIMIT 1', $blog_id, $event_uuid ), ARRAY_A );
			if ( is_array( $existing ) ) {
				return (string) $existing['contract_id'] === (string) $contract_id
					&& (string) $existing['relative_file'] === $relative_file
					&& (int) $existing['byte_offset'] === $byte_offset
					&& (string) $existing['row_hash'] === $row_hash;
			}
			// [2026-08-30 Johnny Chu] R-LOG-IDEMPOTENCY - make the unique-key race an ignored duplicate instead of a DB warning.
			$result = $wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (event_uuid, contract_id, jsonl_folder, jsonl_module, log_date, ts, level, event, ref_id, blog_id, relative_file, byte_offset, row_hash, meta_json) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s)',
				$event_uuid,
				(string) $contract_id,
				$folder,
				$module,
				$log_date,
				$ts,
				sanitize_key( (string) ( $row['level'] ?? 'info' ) ),
				sanitize_key( (string) ( $row['event'] ?? 'log' ) ),
				$ref_id,
				$blog_id,
				$relative_file,
				$byte_offset,
				$row_hash,
				$meta_json
			) );
			if ( false !== $result && 0 === (int) $result ) {
				return true;
			}
			if ( false !== $result ) {
				if ( class_exists( 'BizCity_Cache' ) ) {
					BizCity_Cache::flush_group( 'bzlogidx' );
				}
				return true;
			}
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, contract_id, relative_file, byte_offset, row_hash FROM ' . self::table() . ' WHERE blog_id = %d AND event_uuid = %s LIMIT 1', $blog_id, $event_uuid ), ARRAY_A );
			if ( ! is_array( $existing ) ) {
				return false;
			}
			return (string) $existing['contract_id'] === (string) $contract_id
				&& (string) $existing['relative_file'] === $relative_file
				&& (int) $existing['byte_offset'] === $byte_offset
				&& (string) $existing['row_hash'] === $row_hash;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function search( array $args = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — bounded current-tenant pointer search; full content is fetched from JSONL on demand.
		try {
			global $wpdb;
			$cache_key = 'search_' . md5( (string) wp_json_encode( array( 'blog_id' => get_current_blog_id(), 'db' => (string) ( $wpdb->dbname ?? '' ), 'args' => $args ) ) );
			if ( class_exists( 'BizCity_Cache' ) ) {
				$cached = BizCity_Cache::get( 'bzlogidx', $cache_key );
				if ( false !== $cached ) {
					return (array) $cached;
				}
			}
			$where = array( 'blog_id = %d' );
			$params = array( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
			if ( ! empty( $args['contract_id'] ) ) {
				if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) || ! BizCity_Log_Contract_Registry::has( (string) $args['contract_id'] ) ) {
					return array();
				}
				$where[] = 'contract_id = %s';
				$params[] = sanitize_text_field( (string) $args['contract_id'] );
			}
			if ( ! empty( $args['level'] ) ) {
				$where[] = 'level = %s';
				$params[] = sanitize_key( (string) $args['level'] );
			}
			if ( ! empty( $args['event'] ) ) {
				$where[] = 'event = %s';
				$params[] = sanitize_key( (string) $args['event'] );
			}
			if ( ! empty( $args['ref_id'] ) ) {
				$where[] = 'ref_id = %s';
				$params[] = sanitize_text_field( (string) $args['ref_id'] );
			}
			if ( isset( $args['after_id'] ) && (int) $args['after_id'] > 0 ) {
				$where[] = 'id > %d';
				$params[] = (int) $args['after_id'];
			}
			foreach ( array( 'date_from' => '>=', 'date_to' => '<=' ) as $key => $operator ) {
				if ( ! empty( $args[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args[ $key ] ) ) {
					$where[] = 'log_date ' . $operator . ' %s';
					$params[] = (string) $args[ $key ];
				}
			}
			$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
			$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
			$params[] = $limit;
			$params[] = $offset;
			$order = isset( $args['after_id'] ) && (int) $args['after_id'] > 0 ? 'id ASC' : 'ts DESC, id DESC';
			$sql = 'SELECT id, event_uuid, contract_id, jsonl_folder, jsonl_module, log_date, ts, level, event, ref_id, blog_id, relative_file, byte_offset, row_hash, meta_json, indexed_at FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d';
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
			$rows = (array) $wpdb->get_results( $prepared, ARRAY_A );
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::set( 'bzlogidx', $cache_key, $rows, BizCity_Cache::TTL_SHORT );
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function purge_for_deleted_files( $contract_id, array $log_dates ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — remove pointers only after their contract-owned JSONL date-file is gone.
		// [2026-08-27 Johnny Chu] HOTFIX — date-only deletion is intentionally disabled because scoped contracts may have multiple files on one date; callers must provide exact relative files.
		unset( $contract_id, $log_dates );
		return 0;
	}

	public static function purge_for_deleted_paths( $contract_id, array $relative_files ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — delete only pointers whose exact JSONL relative file was deleted successfully.
		$relative_files = array_values( array_unique( array_filter( array_map( 'strval', $relative_files ), static function ( $relative_file ) use ( $contract_id ) {
			$contract = class_exists( 'BizCity_Log_Contract_Registry' ) ? BizCity_Log_Contract_Registry::get( $contract_id ) : null;
			if ( ! is_array( $contract ) ) {
				return false;
			}
			$prefix = (string) $contract['jsonl_folder'] . '/' . (string) $contract['jsonl_module'] . '/';
			return $relative_file !== '' && strpos( str_replace( '\\', '/', $relative_file ), $prefix ) === 0 && strpos( $relative_file, '..' ) === false;
		} ) ) );
		if ( $contract_id === '' || empty( $relative_files ) ) {
			return 0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $relative_files ), '%s' ) );
		$params = array_merge(
			array( (int) get_current_blog_id(), (string) $contract_id ),
			$relative_files
		);
		$sql = 'DELETE FROM ' . self::table() . ' WHERE blog_id = %d AND contract_id = %s AND relative_file IN (' . $placeholders . ')';
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$result = $wpdb->query( $prepared );
		if ( false !== $result && class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'bzlogidx' );
		}
		return false === $result ? 0 : (int) $result;
	}

	public static function reconcile( $contract_id = '', $batch_limit = 200 ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — resumable bounded rebuild prevents large JSONL sources from being reported complete after one partial scan.
		$contracts = class_exists( 'BizCity_Log_Contract_Registry' ) ? BizCity_Log_Contract_Registry::all() : array();
		if ( $contract_id !== '' ) {
			$contracts = isset( $contracts[ $contract_id ] ) ? array( $contract_id => $contracts[ $contract_id ] ) : array();
		}
		$contracts = array_filter( $contracts, static function ( $contract ) {
			return is_array( $contract ) && ! empty( $contract['indexed'] ) && (string) ( $contract['storage_scope'] ?? 'blog' ) === 'blog';
		} );
		$ids = array_keys( $contracts );
		$cursor = get_option( self::RECONCILE_CURSOR_OPTION, array() );
		$cursor = is_array( $cursor ) ? $cursor : array();
		$cursor_identity = self::availability_key();
		if ( (string) ( $cursor['scope'] ?? '' ) !== $cursor_identity ) {
			$cursor = array();
		}
		if ( $contract_id !== '' && (string) ( $cursor['contract_id'] ?? '' ) !== $contract_id ) {
			$cursor = array();
		}
		$batch_limit = max( 1, min( 500, (int) $batch_limit ) );
		$rebuilt = 0;
		$removed = 0;
		$pointer_cursor = (int) ( $cursor['pointer_id'] ?? 0 );
		$start = 0;
		if ( ! empty( $cursor['contract_id'] ) ) {
			$found = array_search( (string) $cursor['contract_id'], $ids, true );
			$start = false === $found ? 0 : (int) $found;
		}
		$pointer_phase = (string) ( $cursor['phase'] ?? '' ) === 'pointers';
		if ( ! $pointer_phase ) {
		for ( $i = $start; $i < count( $ids ); $i++ ) {
			$id = (string) $ids[ $i ];
			$contract = $contracts[ $id ];
			$folder = (string) $contract['jsonl_folder'];
			$module = (string) $contract['jsonl_module'];
			$files = class_exists( 'BizCity_JSONL_File_Logger' ) ? BizCity_JSONL_File_Logger::list_jsonl_files( $folder, $module, 5000 ) : array();
			$file_start = ( (string) ( $cursor['contract_id'] ?? '' ) === $id && ! empty( $cursor['relative_file'] ) ) ? array_search( (string) $cursor['relative_file'], array_column( $files, 'relative_file' ), true ) : 0;
			$file_start = false === $file_start ? 0 : (int) $file_start;
			for ( $d = $file_start; $d < count( $files ); $d++ ) {
				$file_info = is_array( $files[ $d ] ) ? $files[ $d ] : array();
				$date = (string) ( $file_info['date'] ?? '' );
				$relative_file = (string) ( $file_info['relative_file'] ?? '' );
				$offset = ( (string) ( $cursor['contract_id'] ?? '' ) === $id && (string) ( $cursor['relative_file'] ?? '' ) === $relative_file ) ? (int) ( $cursor['byte_offset'] ?? 0 ) : 0;
				$page = BizCity_JSONL_File_Logger::read_page_location( $folder, $module, $relative_file, $offset, $batch_limit );
				foreach ( (array) ( $page['rows'] ?? array() ) as $item ) {
					$row = is_array( $item['row'] ?? null ) ? $item['row'] : array();
					if ( self::record( $id, $row, array( 'byte_offset' => (int) ( $item['byte_offset'] ?? 0 ), 'row_hash' => (string) ( $item['row_hash'] ?? '' ), 'relative_file' => $relative_file ) ) ) {
						$rebuilt++;
					}
				}
				if ( empty( $page['complete'] ) ) {
					update_option( self::RECONCILE_CURSOR_OPTION, array( 'scope' => $cursor_identity, 'contract_id' => $id, 'relative_file' => $relative_file, 'byte_offset' => (int) ( $page['next_offset'] ?? $offset ) ), false );
					if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( 'bzlogidx' ); }
					return array( 'rebuilt' => $rebuilt, 'removed' => $removed, 'complete' => false, 'cursor' => array( 'scope' => $cursor_identity, 'contract_id' => $id, 'relative_file' => $relative_file, 'byte_offset' => (int) ( $page['next_offset'] ?? $offset ) ) );
				}
				$cursor = array();
			}
			$cursor = array();
		}
		}
		$pointer_rows = self::search( array( 'contract_id' => $contract_id, 'limit' => 200, 'after_id' => $pointer_cursor ) );
		foreach ( $pointer_rows as $pointer ) {
			$pointer_cursor = max( $pointer_cursor, (int) ( $pointer['id'] ?? 0 ) );
			$target_contract = isset( $contracts[ (string) $pointer['contract_id'] ] ) ? $contracts[ (string) $pointer['contract_id'] ] : null;
			if ( ! is_array( $target_contract ) || ! class_exists( 'BizCity_JSONL_File_Logger' ) ) { continue; }
			$verification = BizCity_JSONL_File_Logger::verify_pointer( $target_contract['jsonl_folder'], $target_contract['jsonl_module'], $pointer['relative_file'], $pointer['byte_offset'], $pointer['row_hash'] );
			if ( ! empty( $verification['valid'] ) ) {
				continue;
			}
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — remove both absent and hash-drifted pointers so the next file phase can rebuild the exact identity.
			$location = BizCity_JSONL_File_Logger::read_page_location( $target_contract['jsonl_folder'], $target_contract['jsonl_module'], $pointer['relative_file'], $pointer['byte_offset'], 1 );
			$stale_pointer = empty( $location['rows'] ) || (string) ( $location['rows'][0]['row_hash'] ?? '' ) !== (string) $pointer['row_hash'];
			if ( $stale_pointer ) {
				global $wpdb;
				$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE id = %d AND blog_id = %d', (int) $pointer['id'], (int) get_current_blog_id() ) );
				if ( false !== $deleted ) { $removed += (int) $deleted; }
			}
		}
		if ( count( $pointer_rows ) >= 200 ) {
			$next_cursor = array( 'scope' => $cursor_identity, 'phase' => 'pointers', 'contract_id' => $contract_id, 'pointer_id' => $pointer_cursor );
			update_option( self::RECONCILE_CURSOR_OPTION, $next_cursor, false );
			if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( 'bzlogidx' ); }
			return array( 'rebuilt' => $rebuilt, 'removed' => $removed, 'complete' => false, 'cursor' => $next_cursor );
		}
		delete_option( self::RECONCILE_CURSOR_OPTION );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'bzlogidx' );
		}
		return array( 'rebuilt' => $rebuilt, 'removed' => $removed, 'complete' => true, 'cursor' => array() );
	}

	private static function event_uuid( $contract_id, array $row ) {
		$value = (string) ( $row['event_uuid'] ?? '' );
		if ( preg_match( '/^[a-f0-9-]{36}$/i', $value ) ) {
			return strtolower( $value );
		}
		$seed = $contract_id . '|' . (string) ( $row['ts'] ?? '' ) . '|' . (string) ( $row['event'] ?? '' ) . '|' . (string) ( $row['row_hash'] ?? '' );
		$hash = sha1( $seed );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-5' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	private static function availability_key() {
		global $wpdb;
		return (int) get_current_blog_id() . ':' . (string) ( $wpdb->dbname ?? '' );
	}

	private static function set_table_ready( $ready ) {
		self::$table_ready[ self::availability_key() ] = (bool) $ready;
	}

	private static function mysql_timestamp( $value ) {
		$timestamp = $value !== '' ? strtotime( $value ) : false;
		return false === $timestamp ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function reference_id( array $row ) {
		$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : $row;
		foreach ( array( 'ref_id', 'trace_id', 'run_id', 'workflow_id', 'conversation_id', 'event_uuid', 'user_id' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && (string) $ctx[ $key ] !== '' ) {
				return substr( sanitize_text_field( (string) $ctx[ $key ] ), 0, 191 );
			}
		}
		return null;
	}

	private static function meta_json( array $row ) {
		$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
		$meta = array();
		foreach ( array( 'trace_id', 'parent_event_uuid', 'run_id', 'workflow_id', 'conversation_id', 'user_id' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && is_scalar( $ctx[ $key ] ) ) {
				$meta[ $key ] = substr( sanitize_text_field( (string) $ctx[ $key ] ), 0, 191 );
			}
		}
		return empty( $meta ) ? null : wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}

BizCity_Log_Index::register_schema();

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzlogidx', 'core.helper', array(
		'search_{blog_db_args_hash}' => array( 'ttl' => 60, 'desc' => 'Bounded current-tenant canonical log pointer search.' ),
	) );
}
