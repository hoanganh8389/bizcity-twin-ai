<?php
/**
 * Shared per-module JSONL file logger.
 *
 * Stores operational evidence below the current blog upload directory:
 *   bizcity-crm-logs/{module}/YYYY-MM-DD.jsonl
 *   bizcity-memory-logs/{module}/YYYY-MM-DD.jsonl
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_JSONL_File_Logger', false ) ) {
	return;
}

// [2026-08-27 Johnny Chu] HOTFIX — restore the canonical logger class boundary so all methods below parse and load as one PHP class.
class BizCity_JSONL_File_Logger {

	const CRM_FOLDER    = 'bizcity-crm-logs';
	const MEMORY_FOLDER = 'bizcity-memory-logs';
	const CHANNEL_FOLDER = 'bizcity-channel-logs';
	const RETENTION_HOOK = 'bizcity_jsonl_retention';
	const RETENTION_DAYS = 7;
	const RETENTION_FOLDERS = array(
		'bizcity-crm-logs',
		'bizcity-memory-logs',
		'bizcity-intent-logs',
		'bizcity-channel-logs',
		'bizcity-cron-logs',
		'bizcity-automation-logs',
		'bizcity-twin-core-logs',
		'bizcity-twinbrain-logs',
		'bizcity-kg-logs',
		'bizcity-mcp-logs',
		'bizcity-usage-logs',
		'bizcity-notebook-bridge-logs',
		'bizcity-skill-logs',
		'bizcity-webchat-logs',
		'bizcity-facebook-bot-logs',
		'bizcity-zalo-bot-logs',
		'bizcity-google-logs',
		'bizcity-cg-debug-logs',
		'bizcity-cg-logs',
	);

	private static $dir_cache = array();

	public static function register_retention_cron(): void {
		// [2026-08-27 Johnny Chu] HOTFIX — retain the shared retention registration API expected by helper bootstrap.
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'core.helper.jsonl_retention',
			'hook'        => self::RETENTION_HOOK,
			'interval'    => 'daily',
			'owner'       => 'core/helper',
			'description' => 'Bounded retention sweep for shared JSONL evidence folders.',
			'retention'   => self::RETENTION_DAYS,
		) );
	}

	public static function gc_standard_logs(): void {
		$deleted = 0;
		$covered = array();
		if ( class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			foreach ( BizCity_Log_Contract_Registry::all() as $contract_id => $contract ) {
				if ( ! is_array( $contract ) || empty( $contract['jsonl_folder'] ) || empty( $contract['jsonl_module'] ) ) {
					continue;
				}
				$deleted += self::purge_contract( $contract_id, (int) ( $contract['retention_days'] ?? self::RETENTION_DAYS ) );
				$covered[ (string) $contract['jsonl_folder'] ] = true;
			}
		}
		foreach ( self::RETENTION_FOLDERS as $folder ) {
			if ( empty( $covered[ $folder ] ) ) {
				$deleted += self::purge_folder_older_than( $folder, self::RETENTION_DAYS );
			}
		}
		$reconcile = array( 'rebuilt' => 0, 'removed' => 0 );
		if ( class_exists( 'BizCity_Log_Index' ) && BizCity_Log_Index::is_available() ) {
			$reconcile = BizCity_Log_Index::reconcile();
		}
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'shared_jsonl_retention_deleted' => $deleted, 'shared_jsonl_index_rebuilt' => (int) ( $reconcile['rebuilt'] ?? 0 ), 'shared_jsonl_index_removed' => (int) ( $reconcile['removed'] ?? 0 ) ) ) );
			$cron->note_event( 'shared_jsonl_retention', array(
				'deleted_files' => $deleted,
				'retention_days' => self::RETENTION_DAYS,
				'folders' => self::RETENTION_FOLDERS,
				'index_reconcile' => $reconcile,
			) );
		}
	}

	/**
	 * Write one module event. File logging is best-effort and never throws.
	 *
	 * @param string $folder  One of the folder constants.
	 * @param string $module  Sanitized subfolder, for example 'autoreply'.
	 * @param string $level   debug|info|warn|error.
	 * @param string $event   Machine-readable event name.
	 * @param string $message Short operational summary.
	 * @param array  $ctx     Non-sensitive structured context.
	 * @return bool
	 */
	public static function write( $folder, $module, $level, $event, $message, array $ctx = array() ) {
		try {
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — every positional compatibility write must resolve one registered contract or fail closed.
			if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) || ! is_array( BizCity_Log_Contract_Registry::resolve( $folder, $module ) ) ) {
				return false;
			}
			$dir = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return false;
			}

			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — all file names, rows and index dates use explicit UTC timestamps.
			$ts = gmdate( 'Y-m-d\\TH:i:s\\Z' );
			$row = array(
				'ts'         => $ts,
				'blog_id'    => (int) get_current_blog_id(),
				'event_uuid' => self::event_uuid( $ctx ),
				'module'     => self::slug( $module ),
				'level'      => self::slug( $level ),
				'event'      => self::slug( $event ),
				'msg'        => self::scrub_message( $message ),
				'ctx'        => self::scrub( $ctx ),
			);
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}

			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . substr( $ts, 0, 10 ) . '.jsonl';
			// [2026-09-02 Johnny Chu] PHASE-1.30-G2 — deduplicate the event identity while the append lock is held, before creating a second JSONL row.
			$append = self::append_jsonl_line( $file, $line, (string) $row['event_uuid'] );
			if ( ! empty( $append['conflict'] ) ) {
				return false;
			}
			$written = ! empty( $append['written'] );
			$byte_offset = (int) ( $append['offset'] ?? 0 );
			if ( $written ) {
				$indexed_line = (string) ( $append['line'] ?? $line );
				self::index_row( $folder, $module, $row, $indexed_line, $byte_offset );
			}
			return $written;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function write_contract( $contract_id, $level, $event, $message, array $ctx = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — new sources write by immutable contract ID instead of caller-controlled folder/module paths.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return false;
		}
		$contract = BizCity_Log_Contract_Registry::get( $contract_id );
		if ( ! is_array( $contract ) || ! in_array( (string) ( $contract['storage_scope'] ?? 'blog' ), array( 'blog', 'network', 'global' ), true ) ) {
			return false;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — global contracts use the main site's upload root while retaining source blog_id in context.
		return self::with_contract_scope( $contract, static function () use ( $contract, $level, $event, $message, $ctx ) {
			return self::write(
				(string) $contract['jsonl_folder'],
				(string) $contract['jsonl_module'],
				$level,
				$event,
				$message,
				$ctx
			);
		} );
	}

	/**
	 * Write one already-normalized public record under a registered contract.
	 *
	 * This is intentionally narrower than the generic log writer: the caller
	 * owns the validated envelope, while this helper owns the durable append,
	 * pointer offset and row hash.
	 *
	 * @param string              $contract_id Registered contract ID.
	 * @param array<string,mixed> $record     Structured public record.
	 * @return array<string,mixed> Receipt with written/offset/path/hash/indexed.
	 */
	public static function write_contract_record( $contract_id, array $record ) {
		// [2026-09-01 Johnny Chu] R-CH-10 — persist one validated channel diagnostics record and return its lock-captured pointer receipt.
		try {
			if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'contract_registry_missing' );
			}
			$contract = BizCity_Log_Contract_Registry::get( (string) $contract_id );
			if ( ! is_array( $contract ) || (string) ( $contract['storage_scope'] ?? 'blog' ) !== 'blog' ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'contract_unknown' );
			}
			$folder = (string) ( $contract['jsonl_folder'] ?? '' );
			$module = (string) ( $contract['jsonl_module'] ?? '' );
			$dir    = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'log_directory_unavailable' );
			}

			$ts = (string) ( $record['occurred_at'] ?? gmdate( 'Y-m-d\\TH:i:s\\Z' ) );
			if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}T[^ ]+Z$/', $ts ) ) {
				$ts = gmdate( 'Y-m-d\\TH:i:s\\Z' );
			}
			$record['contract'] = (string) ( $record['contract'] ?? $contract_id );
			$record['version']  = (string) ( $record['version'] ?? '1.0.0' );
			$record['blog_id']  = (int) get_current_blog_id();
			$record['occurred_at'] = $ts;
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'record_encode_failed' );
			}

			$date = substr( $ts, 0, 10 );
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
			// [2026-09-02 Johnny Chu] PHASE-1.30-G2 — reuse an identical locked row on retry so one event identity yields one durable JSONL row.
			$append = self::append_jsonl_line( $file, $line, (string) ( $record['event_uuid'] ?? '' ) );
			if ( ! empty( $append['conflict'] ) ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'idempotency_conflict' );
			}
			if ( empty( $append['written'] ) ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'file_append_failed' );
			}

			$relative_file = self::slug( $folder ) . '/' . self::slug( $module ) . '/' . $date . '.jsonl';
			// [2026-09-01 Johnny Chu] R-CH-10 — adapt occurred_at/context for the operational pointer index without adding legacy fields to the public JSONL envelope.
			$index_record = $record;
			$index_record['ts'] = $ts;
			$index_record['ctx'] = is_array( $record['context'] ?? null ) ? $record['context'] : array();
			$index_record['ctx']['trace_id'] = (string) ( $record['trace_id'] ?? '' );
			$index_record['ctx']['parent_event_uuid'] = (string) ( $record['parent_event_uuid'] ?? '' );
			$index_record['ctx']['channel'] = (string) ( $record['channel'] ?? '' );
			$indexed_line = (string) ( $append['line'] ?? $line );
			$indexed = self::index_row( $folder, $module, $index_record, $indexed_line, (int) ( $append['offset'] ?? 0 ), (string) $contract_id, $relative_file );
			return array(
				'written'       => true,
				'indexed'       => (bool) $indexed,
				'contract_id'   => (string) $contract_id,
				'record_id'     => (string) ( $record['record_ref']['context_record_id'] ?? $record['event_uuid'] ?? '' ),
				'event_uuid'    => (string) ( $record['event_uuid'] ?? '' ),
				'relative_file' => $relative_file,
				'byte_offset'   => (int) ( $append['offset'] ?? 0 ),
				'row_hash'      => hash( 'sha256', $line ),
				'blog_id'       => (int) get_current_blog_id(),
				'occurred_at'   => $ts,
			);
		} catch ( \Throwable $e ) {
			return array( 'written' => false, 'indexed' => false, 'reason' => 'logger_exception' );
		}
	}

	public static function write_scoped_contract( $contract_id, array $segments, $level, $event, $message, array $ctx = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — new scoped sources resolve storage and index identity from the registered contract, never hidden ctx metadata.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return false;
		}
		$contract = BizCity_Log_Contract_Registry::get( $contract_id );
		if ( ! is_array( $contract ) || (string) ( $contract['storage_scope'] ?? 'blog' ) !== 'blog' ) {
			return false;
		}
		$folder = (string) $contract['jsonl_folder'];
		$module = self::slug( (string) $contract['jsonl_module'] );
		$parts = array_values( array_filter( array_map( array( __CLASS__, 'slug' ), $segments ) ) );
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — scoped physical paths must start at the registered module root so index pointers can be followed safely.
		if ( $folder === '' || $module === '' || empty( $parts ) || (string) $parts[0] !== $module ) {
			return false;
		}
		try {
			$dir = self::get_scoped_dir( $folder, $parts );
			if ( $dir === '' ) {
				return false;
			}
			$ts = gmdate( 'Y-m-d\\TH:i:s\\Z' );
			$row = array(
				'ts'         => $ts,
				'blog_id'    => (int) get_current_blog_id(),
				'event_uuid' => self::event_uuid( $ctx ),
				'level'      => self::slug( $level ),
				'event'      => self::slug( $event ),
				'msg'        => self::scrub_message( $message ),
				'ctx'        => self::scrub( $ctx ),
			);
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . substr( $ts, 0, 10 ) . '.jsonl';
			// [2026-09-02 Johnny Chu] PHASE-1.30-G2 — scoped writers share the same locked event deduplication boundary.
			$append = self::append_jsonl_line( $file, $line, (string) $row['event_uuid'] );
			if ( ! empty( $append['conflict'] ) ) {
				return false;
			}
			if ( ! empty( $append['written'] ) ) {
				$indexed_line = (string) ( $append['line'] ?? $line );
				self::index_row( $folder, (string) $contract['jsonl_module'], $row, $indexed_line, (int) $append['offset'], $contract_id, $folder . '/' . implode( '/', $parts ) . '/' . substr( $ts, 0, 10 ) . '.jsonl' );
			}
			return ! empty( $append['written'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Write an operational row below a scoped client directory.
	 *
	 * Layout: {folder}/{segment-1}/{segment-2}/YYYY-MM-DD.jsonl.
	 * Segments are sanitized path components; callers must not pass secrets.
	 */
	public static function write_scoped( $folder, array $segments, $level, $event, $message, array $ctx = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — legacy scoped calls must resolve one registered contract before writing or fail closed.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) || empty( $segments ) ) {
			return false;
		}
		$resolved = BizCity_Log_Contract_Registry::resolve( $folder, (string) $segments[0] );
		if ( ! is_array( $resolved ) || empty( $resolved['id'] ) ) {
			return false;
		}
		unset( $ctx['_contract_id'] );
		return self::write_scoped_contract( (string) $resolved['id'], $segments, $level, $event, $message, $ctx );
		/*
		try {
			$dir = self::get_scoped_dir( $folder, $segments );
			if ( $dir === '' ) {
				return false;
			}
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — scoped rows share the same UTC contract as normal rows.
			$ts = gmdate( 'Y-m-d\\TH:i:s\\Z' );
			$row = array(
				'ts'         => $ts,
				'blog_id'    => (int) get_current_blog_id(),
				'event_uuid' => self::event_uuid( $ctx ),
				'level'      => self::slug( $level ),
				'event'      => self::slug( $event ),
				'msg'        => self::scrub_message( $message ),
				'ctx'        => self::scrub( $ctx ),
			);
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . substr( $ts, 0, 10 ) . '.jsonl';
			$append = self::append_jsonl_line( $file, $line );
			$written = ! empty( $append['written'] );
			$byte_offset = (int) ( $append['offset'] ?? 0 );
			if ( $written && ! empty( $ctx['_contract_id'] ) ) {
				self::index_row( $folder, implode( '/', array_map( array( __CLASS__, 'slug' ), $segments ) ), $row, $line, $byte_offset, (string) $ctx['_contract_id'] );
			}
			return $written;
		} catch ( \Throwable $e ) {
			return false;
		}
		*/
	}

	/** Purge one contract's files, then remove only pointers to deleted files. */
	public static function purge_contract( $contract_id, $days ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — apply the registry TTL to one contract and clean its pointer projection after file deletion.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return 0;
		}
		$contract = BizCity_Log_Contract_Registry::get( $contract_id );
		if ( ! is_array( $contract ) ) {
			return 0;
		}
		$days = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$deleted = 0;
		$deleted_files = array();
		foreach ( self::list_jsonl_files( $contract['jsonl_folder'], $contract['jsonl_module'], 5000 ) as $file_info ) {
			$date = (string) ( $file_info['date'] ?? '' );
			if ( $date === '' || $date >= $cutoff ) {
				continue;
			}
			$relative_file = (string) ( $file_info['relative_file'] ?? '' );
			// [2026-09-02  Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G3 — expose an explicit retention-delete veto for disposable failure-injection fixtures; production defaults to allow.
			$delete_allowed = function_exists( 'apply_filters' )
				? apply_filters( 'bizcity_jsonl_allow_retention_delete', true, (string) $contract_id, $relative_file )
				: true;
			if ( $delete_allowed && self::delete_relative_file( $contract['jsonl_folder'], $contract['jsonl_module'], $relative_file ) ) {
				$deleted++;
				$deleted_files[] = $relative_file;
			}
		}
		if ( ! empty( $deleted_files ) && class_exists( 'BizCity_Log_Index' ) && method_exists( 'BizCity_Log_Index', 'purge_for_deleted_paths' ) ) {
			BizCity_Log_Index::purge_for_deleted_paths( $contract_id, $deleted_files );
		}
		return $deleted;
	}

	/**
	 * Return the effective per-blog location used by the logger.
	 *
	 * WordPress does not use uploads/sites/{blog_id} for the main site. The
	 * effective basedir must therefore come from wp_upload_dir().
	 *
	 * @return array<string,mixed>
	 */
	public static function location( $folder, $module, $date = '' ) {
		try {
			$folder = self::slug( $folder );
			$module = self::slug( $module );
			$dir    = self::get_dir( $folder, $module );
			$ts     = (string) $date;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ts ) ) {
				$ts = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			}
			$upload = wp_upload_dir( null, false );
			$file   = $dir !== '' ? rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $ts . '.jsonl' : '';
			return array(
				'blog_id'   => (int) get_current_blog_id(),
				'basedir'   => (string) ( $upload['basedir'] ?? '' ),
				'folder'    => $folder,
				'module'    => $module,
				'directory' => $dir,
				'file'      => $file,
				'exists'    => $file !== '' && file_exists( $file ),
				'writable'  => $dir !== '' && is_writable( $dir ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'blog_id' => (int) get_current_blog_id(),
				'folder'  => self::slug( $folder ),
				'module'  => self::slug( $module ),
				'error'   => get_class( $e ),
			);
		}
	}

	public static function location_scoped( $folder, array $segments, $date = '' ) {
		try {
			$dir = self::get_scoped_dir( $folder, $segments );
			$date = self::valid_date( $date );
			$upload = wp_upload_dir( null, false );
			$file = $dir !== '' ? rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl' : '';
			return array(
				'blog_id' => (int) get_current_blog_id(),
				'basedir' => (string) ( $upload['basedir'] ?? '' ),
				'folder' => self::slug( $folder ),
				'segments' => array_values( array_filter( array_map( array( __CLASS__, 'slug' ), $segments ) ) ),
				'directory' => $dir,
				'file' => $file,
				'exists' => $file !== '' && file_exists( $file ),
				'writable' => $dir !== '' && is_writable( $dir ),
			);
		} catch ( \Throwable $e ) {
			return array( 'folder' => self::slug( $folder ), 'error' => get_class( $e ) );
		}
	}

	public static function read_scoped( $folder, array $segments, $date = '', $limit = 200 ) {
		try {
			$dir = self::get_scoped_dir( $folder, $segments );
			$date = self::valid_date( $date );
			$file = $dir !== '' ? rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl' : '';
			if ( $file === '' || ! file_exists( $file ) ) {
				return array();
			}
			return self::read_streaming( $file, $limit );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function list_dates_scoped( $folder, array $segments, $max = 30 ) {
		try {
			$dir = self::get_scoped_dir( $folder, $segments );
			$files = $dir !== '' ? glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' ) : array();
			$dates = array();
			foreach ( (array) $files as $file ) {
				$date = basename( $file, '.jsonl' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$dates[] = $date;
				}
			}
			rsort( $dates );
			return array_slice( $dates, 0, max( 1, (int) $max ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function error( $folder, $module, $event, $message, array $ctx = array(), $exception = null ) {
		if ( $exception instanceof \Throwable ) {
			$ctx['exception_class'] = get_class( $exception );
			$ctx['exception_file']  = basename( $exception->getFile() ) . ':' . $exception->getLine();
			$ctx['exception_message'] = $exception->getMessage();
		}
		return self::write( $folder, $module, 'error', $event, $message, $ctx );
	}

	/**
	 * Read recent entries from a module log, newest first.
	 *
	 * @param string $folder
	 * @param string $module
	 * @param string $date
	 * @param int    $limit
	 * @param string $level
	 * @return array
	 */
	public static function read( $folder, $module, $date = '', $limit = 200, $level = '' ) {
		// [2026-08-01 Johnny Chu] PHASE-CRM-LOG-SPLIT — read CRM-owned JSONL evidence.
		try {
			$date = self::valid_date( $date );
			$dir  = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return array();
			}
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
			if ( ! file_exists( $file ) ) {
				return array();
			}
			return self::read_streaming( $file, $limit, $level );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function read_page( $folder, $module, $date, $offset = 0, $limit = 200, $level = '' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — expose bounded byte-offset pages for resumable index reconciliation.
		try {
			$date = self::valid_date( $date );
			$dir = self::get_dir( $folder, $module );
			$file = $dir !== '' ? rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl' : '';
			if ( $file === '' || ! is_file( $file ) ) {
				return array( 'rows' => array(), 'next_offset' => 0, 'complete' => true );
			}
			return self::read_page_file( $file, $offset, $limit, $level );
		} catch ( \Throwable $e ) {
			return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
		}
	}

	public static function read_page_location( $folder, $module, $relative_file, $offset = 0, $limit = 200, $level = '' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — follow only registry-derived relative paths; reject traversal before scoped reconcile reads.
		try {
			$folder = self::slug( $folder );
			$module = self::slug( $module );
			$relative_file = str_replace( '\\', '/', trim( (string) $relative_file ) );
			$prefix = $folder . '/' . $module . '/';
			if ( $folder === '' || $module === '' || strpos( $relative_file, $prefix ) !== 0 || strpos( $relative_file, '..' ) !== false ) {
				return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
			}
			$dir = self::get_dir( $folder, $module );
			$nested = substr( $relative_file, strlen( $prefix ) );
			if ( $dir === '' || $nested === '' ) {
				return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
			}
			$file = self::resolve_relative_file( $dir, $nested );
			if ( $file === '' ) {
				return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
			}
			return self::read_page_file( $file, $offset, $limit, $level );
		} catch ( \Throwable $e ) {
			return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
		}
	}

	public static function list_jsonl_files( $folder, $module, $max = 5000 ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — enumerate normal and nested contract files without exposing absolute paths to callers.
		try {
			$dir = self::get_dir( $folder, $module );
			if ( $dir === '' || ! is_dir( $dir ) ) {
				return array();
			}
			$root = realpath( $dir );
			if ( false === $root ) {
				return array();
			}
			$out = array();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() || substr( $file_info->getFilename(), -6 ) !== '.jsonl' ) {
					continue;
				}
				$date = basename( $file_info->getFilename(), '.jsonl' );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					continue;
				}
				$path = realpath( $file_info->getPathname() );
				if ( false === $path || strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $root ) ) ) !== 0 ) {
					continue;
				}
				$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
				$out[] = array(
					'date' => $date,
					'relative_file' => self::slug( $folder ) . '/' . self::slug( $module ) . '/' . $relative,
				);
				if ( count( $out ) >= max( 1, (int) $max ) ) {
					break;
				}
			}
			usort( $out, static function ( $a, $b ) {
				return strcmp( (string) $b['relative_file'], (string) $a['relative_file'] );
			} );
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function verify_pointer( $folder, $module, $relative_file, $offset, $row_hash ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — verify the exact durable line before retaining or deleting an index pointer.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) || ! is_array( BizCity_Log_Contract_Registry::resolve( $folder, $module ) ) ) {
			return array( 'valid' => false, 'row' => array() );
		}
		$page = self::read_page_location( $folder, $module, $relative_file, max( 0, (int) $offset ), 1 );
		$item = isset( $page['rows'][0] ) && is_array( $page['rows'][0] ) ? $page['rows'][0] : array();
		if ( empty( $item ) || (string) ( $item['row_hash'] ?? '' ) !== (string) $row_hash ) {
			return array( 'valid' => false, 'row' => array() );
		}
		return array( 'valid' => true, 'row' => is_array( $item['row'] ?? null ) ? $item['row'] : array() );
	}

	private static function read_page_file( $file, $offset = 0, $limit = 200, $level = '' ) {
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return array( 'rows' => array(), 'next_offset' => max( 0, (int) $offset ), 'complete' => false );
		}
		$offset = max( 0, (int) $offset );
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			return array( 'rows' => array(), 'next_offset' => $offset, 'complete' => false );
		}
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows = array();
		$next_offset = $offset;
		while ( count( $rows ) < $limit && false !== ( $raw = fgets( $handle ) ) ) {
			$row_offset = $next_offset;
			$next_offset = (int) ftell( $handle );
			$row = json_decode( trim( $raw ), true );
			if ( ! is_array( $row ) || ( $level !== '' && (string) ( $row['level'] ?? '' ) !== (string) $level ) ) {
				continue;
			}
			$rows[] = array(
				'row' => $row,
				'byte_offset' => $row_offset,
				'row_hash' => hash( 'sha256', rtrim( $raw, "\r\n" ) ),
			);
		}
		$file_size = @filesize( $file );
		$complete = feof( $handle ) || ( false !== $file_size && $next_offset >= (int) $file_size );
		fclose( $handle );
		return array( 'rows' => $rows, 'next_offset' => $next_offset, 'complete' => $complete );
	}

	private static function resolve_relative_file( $root, $relative ) {
		$root_real = realpath( $root );
		$relative = str_replace( '\\', '/', trim( (string) $relative ) );
		if ( $root_real === false || $relative === '' || strpos( $relative, '..' ) !== false || strpos( $relative, '//' ) !== false || $relative[0] === '/' ) {
			return '';
		}
		$file = $root_real . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $relative, '/' ) );
		$real_file = realpath( $file );
		if ( $real_file === false || strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $root_real ) ) ) !== 0 ) {
			return '';
		}
		return $real_file;
	}

	private static function delete_relative_file( $folder, $module, $relative_file ) {
		$dir = self::get_dir( $folder, $module );
		$prefix = self::slug( $folder ) . '/' . self::slug( $module ) . '/';
		$relative_file = str_replace( '\\', '/', trim( (string) $relative_file ) );
		if ( $dir === '' || strpos( $relative_file, $prefix ) !== 0 ) {
			return false;
		}
		$target = self::resolve_relative_file( $dir, substr( $relative_file, strlen( $prefix ) ) );
		if ( $target === '' || ! is_file( $target ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		return @unlink( $target );
	}

	/**
	 * Query across multiple day-files, newest first, with an optional per-row
	 * filter callback. This is the shared browsing primitive for admin/debug
	 * UIs migrating off SQL — no COUNT/GROUP BY support by design; callers
	 * needing aggregate stats should keep those in SQL, callers that only
	 * need list/browse/detail should use this instead of a table.
	 *
	 * @param string        $folder
	 * @param string        $module
	 * @param array{
	 *   days?:int,        // how many calendar days back to scan (default 30)
	 *   limit?:int,       // max rows to return (default 200)
	 *   level?:string,    // optional exact level match
	 *   filter?:callable, // callable(array $row): bool — return true to keep
	 * } $args
	 * @return array<int,array> Rows newest-first, each entry as decoded from write().
	 */
	public static function query( $folder, $module, array $args = array() ) {
		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — shared multi-day query primitive (Phase B) for every module moving off SQL.
		try {
			$days   = max( 1, (int) ( $args['days']  ?? 7 ) ); // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — default JSONL queries to the active one-week window.
			$limit  = max( 1, (int) ( $args['limit'] ?? 200 ) );
			$level  = (string) ( $args['level'] ?? '' );
			$filter = isset( $args['filter'] ) && is_callable( $args['filter'] ) ? $args['filter'] : null;

			$dates = self::list_dates( $folder, $module, $days );
			if ( empty( $dates ) ) {
				return array();
			}

			$out = array();
			foreach ( $dates as $date ) {
				// [2026-08-27 Johnny Chu] R-LOG-HYBRID — keep each day-file read bounded by the remaining query limit.
				$remaining = max( 1, $limit - count( $out ) );
				$rows = self::read( $folder, $module, $date, $remaining, $level );
				foreach ( $rows as $row ) {
					if ( $filter && ! call_user_func( $filter, $row ) ) {
						continue;
					}
					$out[] = $row;
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public static function query_contract( $contract_id, array $args = array() ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — readers resolve immutable contract ownership before scanning JSONL files.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return array();
		}
		$contract = BizCity_Log_Contract_Registry::get( $contract_id );
		if ( ! is_array( $contract ) || ! in_array( (string) ( $contract['storage_scope'] ?? 'blog' ), array( 'blog', 'network', 'global' ), true ) ) {
			return array();
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — read global usage audit from the same shared upload root used by the writer.
		return (array) self::with_contract_scope( $contract, static function () use ( $contract, $args ) {
			return self::query( (string) $contract['jsonl_folder'], (string) $contract['jsonl_module'], $args );
		} );
	}

	public static function read_contract( $contract_id, $date = '', $limit = 200, $level = '' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — single-date readers use the same immutable contract boundary as multi-day queries.
		if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return array();
		}
		$contract = BizCity_Log_Contract_Registry::get( $contract_id );
		if ( ! is_array( $contract ) || ! in_array( (string) ( $contract['storage_scope'] ?? 'blog' ), array( 'blog', 'network', 'global' ), true ) ) {
			return array();
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — single-date global reads follow the same shared-root boundary.
		return (array) self::with_contract_scope( $contract, static function () use ( $contract, $date, $limit, $level ) {
			return self::read( (string) $contract['jsonl_folder'], (string) $contract['jsonl_module'], $date, $limit, $level );
		} );
	}

	/**
	 * List available log dates for a module, newest first.
	 *
	 * @param string $folder
	 * @param string $module
	 * @param int    $max
	 * @return array
	 */
	public static function list_dates( $folder, $module, $max = 30 ) {
		// [2026-08-01 Johnny Chu] PHASE-CRM-LOG-SPLIT — enumerate CRM-owned log dates.
		try {
			$dir = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return array();
			}
			$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
			if ( ! is_array( $files ) ) {
				return array();
			}
			$dates = array();
			foreach ( $files as $file ) {
				$date = basename( $file, '.jsonl' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$dates[] = $date;
				}
			}
			rsort( $dates );
			return array_slice( $dates, 0, max( 1, (int) $max ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Delete whole date-named .jsonl files older than N days for a module.
	 * This is the "D" (delete) leg of the CRUD contract — retention cron
	 * callers use this instead of touching individual log lines (files are
	 * append-only per day, so retention is always a whole-file operation).
	 *
	 * @param string $folder
	 * @param string $module
	 * @param int    $days   Files dated before (today - $days) are removed.
	 * @return int Number of files deleted (0 on error or nothing to do).
	 */
	public static function purge_older_than( $folder, $module, $days ) {
		// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — file-store retention leg for modules moving off SQL.
		try {
			$dir = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return 0;
			}
			$days      = max( 1, (int) $days );
			$cutoff_ts = time() - ( $days * DAY_IN_SECONDS );
			$files     = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
			if ( ! is_array( $files ) ) {
				return 0;
			}
			$deleted = 0;
			foreach ( $files as $file ) {
				$date = basename( $file, '.jsonl' );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					continue;
				}
				$file_ts = strtotime( $date . ' 00:00:00 UTC' );
				if ( false === $file_ts || $file_ts >= $cutoff_ts ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				if ( @unlink( $file ) ) {
					$deleted++;
				}
			}
			return $deleted;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Delete date files older than N days across every module in a folder. */
	public static function purge_folder_older_than( $folder, $days ): int {
		// [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — sweep all shared
		// JSONL module folders so newly added modules inherit the seven-day policy.
		try {
			$upload = wp_upload_dir( null, false );
			$base   = (string) ( $upload['basedir'] ?? '' );
			$folder = self::slug( $folder );
			if ( $base === '' || $folder === '' ) {
				return 0;
			}
			$root = trailingslashit( $base ) . $folder . DIRECTORY_SEPARATOR;
			$files = array();
			if ( is_dir( $root ) ) {
				// [2026-08-03 Johnny Chu] R-TGL-CS — scoped platform/client JSONL
				// paths are nested; retention must not stop at one directory level.
				try {
					$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
					foreach ( $iterator as $file_info ) {
						if ( $file_info->isFile() ) {
							$files[] = $file_info->getPathname();
						}
					}
				} catch ( \Throwable $e ) {
					$files = array();
				}
			}
			if ( empty( $files ) ) {
				return 0;
			}
			$cutoff_ts = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
			$deleted = 0;
			foreach ( $files as $file ) {
				$date = basename( $file, '.jsonl' );
				$file_ts = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : false;
				if ( false !== $file_ts && $file_ts < $cutoff_ts && @unlink( $file ) ) {
					$deleted++;
				}
			}
			return $deleted;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	private static function index_row( $folder, $module, array $row, $line, $byte_offset, $contract_id = '', $relative_file = '' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — pointer indexing is best-effort after durable file append and never changes write success.
		if ( ! class_exists( 'BizCity_Log_Index' ) ) {
			return false;
		}
		if ( $contract_id === '' && class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			$resolved = BizCity_Log_Contract_Registry::resolve( $folder, $module );
			$contract_id = is_array( $resolved ) ? (string) ( $resolved['id'] ?? '' ) : '';
		}
		if ( $contract_id === '' ) {
			return false;
		}
		return (bool) BizCity_Log_Index::record( $contract_id, $row, array(
			'byte_offset'   => max( 0, (int) $byte_offset ),
			'row_hash'      => hash( 'sha256', (string) $line ),
			'relative_file' => $relative_file !== '' ? $relative_file : self::slug( $folder ) . '/' . self::slug( $module ) . '/' . substr( (string) ( $row['ts'] ?? gmdate( 'Y-m-d' ) ), 0, 10 ) . '.jsonl',
		) );
	}

	private static function read_streaming( $file, $limit = 200, $level = '' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — bound read memory to the requested row limit instead of loading a whole day-file.
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return array();
		}
		$limit = (int) $limit;
		$buffer = array();
		while ( false !== ( $raw = fgets( $handle ) ) ) {
			$row = json_decode( trim( $raw ), true );
			if ( ! is_array( $row ) || ( $level !== '' && (string) ( $row['level'] ?? '' ) !== (string) $level ) ) {
				continue;
			}
			$buffer[] = $row;
			if ( $limit > 0 && count( $buffer ) > $limit ) {
				array_shift( $buffer );
			}
		}
		fclose( $handle );
		return array_reverse( $buffer );
	}

	private static function append_jsonl_line( $file, $line, $event_uuid = '' ) {
		// [2026-09-02 Johnny Chu] PHASE-1.30-G2 — scan the locked day-file for the canonical event identity before append; this closes the cross-process retry race.
		// [2026-09-02 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-G2 — use append-plus mode so the locked stream can read existing event UUIDs before writing.
		$handle = @fopen( $file, 'a+b' );
		if ( false === $handle ) {
			return array( 'written' => false, 'offset' => 0 );
		}
		if ( ! @flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			return array( 'written' => false, 'offset' => 0 );
		}
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — seek to EOF explicitly because Windows append-mode ftell() can otherwise report offset 0 and create an invalid pointer.
		if ( 0 !== @fseek( $handle, 0, SEEK_END ) ) {
			@flock( $handle, LOCK_UN );
			fclose( $handle );
			return array( 'written' => false, 'offset' => 0 );
		}
		$event_uuid = strtolower( trim( (string) $event_uuid ) );
		if ( preg_match( '/^[a-f0-9-]{36}$/', $event_uuid ) ) {
			$existing_offset = 0;
			if ( 0 === @fseek( $handle, 0, SEEK_SET ) ) {
				while ( false !== ( $raw = fgets( $handle ) ) ) {
					$decoded = json_decode( rtrim( $raw, "\r\n" ), true );
					if ( is_array( $decoded ) && strtolower( (string) ( $decoded['event_uuid'] ?? '' ) ) === $event_uuid ) {
						$existing_line = rtrim( $raw, "\r\n" );
						$requested_hash = hash( 'sha256', (string) $line );
						$existing_hash = hash( 'sha256', $existing_line );
						@flock( $handle, LOCK_UN );
						fclose( $handle );
						return array(
							'written'   => $existing_hash === $requested_hash,
							'duplicate' => true,
							'conflict'  => $existing_hash !== $requested_hash,
							'offset'    => $existing_offset,
							'line'      => $existing_line,
						);
					}
					$existing_offset += strlen( $raw );
				}
			}
			if ( 0 !== @fseek( $handle, 0, SEEK_END ) ) {
				@flock( $handle, LOCK_UN );
				fclose( $handle );
				return array( 'written' => false, 'offset' => 0 );
			}
		}
		$offset = (int) ftell( $handle );
		$written = false !== @fwrite( $handle, $line . "\n" );
		@fflush( $handle );
		@flock( $handle, LOCK_UN );
		fclose( $handle );
		return array( 'written' => $written, 'offset' => $offset );
	}

	private static function event_uuid( array $ctx ) {
		$value = (string) ( $ctx['event_uuid'] ?? '' );
		if ( preg_match( '/^[a-f0-9-]{36}$/i', $value ) ) {
			return strtolower( $value );
		}
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$hash = sha1( uniqid( '', true ) );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Run a contract operation in its registered storage scope.
	 *
	 * @param array    $contract Registered log contract.
	 * @param callable $callback Operation to execute.
	 * @return mixed
	 */
	private static function with_contract_scope( array $contract, $callback ) {
		$scope = (string) ( $contract['storage_scope'] ?? 'blog' );
		if ( $scope !== 'global' || ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'switch_to_blog' ) ) {
			return call_user_func( $callback );
		}

		$origin_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$main_blog_id   = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
		if ( $main_blog_id <= 0 || $origin_blog_id === $main_blog_id ) {
			return call_user_func( $callback );
		}

		// [2026-09-01 Johnny Chu] R-MSDB — global JSONL operations use the main site's shared upload root and always restore the originating tenant.
		switch_to_blog( $main_blog_id );
		try {
			return call_user_func( $callback );
		} finally {
			restore_current_blog();
		}
	}

	private static function get_dir( $folder, $module ) {
		$folder = self::slug( $folder );
		$module = self::slug( $module );
		if ( $folder === '' || $module === '' ) {
			return '';
		}
		$upload = wp_upload_dir( null, false );
		$base   = (string) ( $upload['basedir'] ?? '' );
		if ( $base === '' ) {
			return '';
		}
		// [2026-09-01 Johnny Chu] R-CACHE-MSDB - include the resolved physical upload root so switched tenant/shard contexts cannot reuse a stale directory.
		$key = (int) get_current_blog_id() . ':' . md5( $base ) . ':' . $folder . ':' . $module;
		if ( isset( self::$dir_cache[ $key ] ) ) {
			$cached_dir = self::$dir_cache[ $key ];
			if ( is_dir( $cached_dir ) && is_writable( $cached_dir ) ) {
				return $cached_dir;
			}
			unset( self::$dir_cache[ $key ] );
		}
		$root = trailingslashit( $base ) . $folder;
		$dir  = $root . DIRECTORY_SEPARATOR . $module;
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $dir, 0755, true );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}
		self::protect_root( $root );
		self::$dir_cache[ $key ] = $dir;
		return $dir;
	}

	private static function get_scoped_dir( $folder, array $segments ) {
		$folder = self::slug( $folder );
		$parts = array_values( array_filter( array_map( array( __CLASS__, 'slug' ), $segments ) ) );
		if ( $folder === '' || empty( $parts ) ) {
			return '';
		}
		$key = (int) get_current_blog_id() . ':' . $folder . ':' . implode( '/', $parts );
		if ( isset( self::$dir_cache[ $key ] ) ) {
			return self::$dir_cache[ $key ];
		}
		$upload = wp_upload_dir( null, false );
		$base = (string) ( $upload['basedir'] ?? '' );
		if ( $base === '' ) {
			return '';
		}
		$root = trailingslashit( $base ) . $folder;
		$dir = $root;
		foreach ( $parts as $part ) {
			$dir .= DIRECTORY_SEPARATOR . $part;
		}
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $dir, 0755, true );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}
		self::protect_root( $root );
		self::$dir_cache[ $key ] = $dir;
		return $dir;
	}

	private static function valid_date( $date ) {
		// [2026-08-01 Johnny Chu] PHASE-CRM-LOG-SPLIT — constrain reader paths to date filenames.
		$date = (string) $date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		return $date;
	}

	private static function protect_root( $root ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — emit Apache/IIS deny artifacts at the canonical upload root; Nginx still requires deployment evidence.
		if ( ! is_dir( $root ) ) {
			return;
		}
		$artifacts = array(
			'.htaccess' => "Deny from all\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"true\"><remove fileExtension=\".jsonl\" /><add fileExtension=\".jsonl\" allowed=\"false\" /></fileExtensions></requestFiltering></security></system.webServer></configuration>",
			'index.php' => "<?php // Silence is golden.\n",
		);
		foreach ( $artifacts as $name => $body ) {
			$file = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			if ( ! file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $file, $body );
			}
		}
	}

	private static function slug( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9_-]/', '_', $value );
		return trim( (string) $value, '_-' );
	}

	private static function scrub( $value, $depth = 0 ) {
		if ( $depth > 5 ) {
			return '[depth-cap]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — retain numeric usage metrics; credential-shaped token fields remain redacted.
				$safe_metric = in_array( $key_string, array( 'tokens_prompt', 'tokens_completion', 'latency_ms' ), true );
				if ( ! $safe_metric && preg_match( '/token|secret|password|authorization|api[_-]?key|raw|body|message|phone|email/i', $key_string ) ) {
					$out[ $key_string ] = '[redacted]';
				} else {
					$out[ $key_string ] = self::scrub( $item, $depth + 1 );
				}
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[object:' . get_class( $value ) . ']';
		}
		if ( is_string( $value ) ) {
			return substr( $value, 0, 300 );
		}
		return $value;
	}

	private static function scrub_message( $message ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — operational summaries cannot persist credentials, PII or raw payload text.
		$message = substr( trim( (string) $message ), 0, 500 );
		$message = preg_replace( '/Bearer\\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $message );
		$message = preg_replace( '/(?:api[_-]?key|token|secret|password)\\s*[:=]\\s*[^\\s,;]+/i', 'credential=[redacted]', $message );
		$message = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', '[email-redacted]', $message );
		$message = preg_replace( '/(?<!\\d)(?:\\+?\\d[\\d .()-]{7,}\\d)(?!\\d)/', '[phone-redacted]', $message );
		return is_string( $message ) ? $message : '[message-redacted]';
	}
}
