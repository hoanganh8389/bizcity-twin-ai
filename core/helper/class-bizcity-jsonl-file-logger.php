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
		'bizcity-twin-core-logs',
		'bizcity-twinbrain-logs',
		'bizcity-kg-logs',
		'bizcity-mcp-logs',
		'bizcity-usage-logs',
	);

	private static $dir_cache = array();

	public static function register_retention_cron(): void {
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
		foreach ( self::RETENTION_FOLDERS as $folder ) {
			$deleted += self::purge_folder_older_than( $folder, self::RETENTION_DAYS );
		}
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'shared_jsonl_retention_deleted' => $deleted ) ) );
			$cron->note_event( 'shared_jsonl_retention', array(
				'deleted_files' => $deleted,
				'retention_days' => self::RETENTION_DAYS,
				'folders' => self::RETENTION_FOLDERS,
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
			$dir = self::get_dir( $folder, $module );
			if ( $dir === '' ) {
				return false;
			}

			$ts = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\\TH:i:s' ) : gmdate( 'Y-m-d\\TH:i:s' );
			$row = array(
				'ts'      => $ts,
				'blog_id' => (int) get_current_blog_id(),
				'module'  => self::slug( $module ),
				'level'   => self::slug( $level ),
				'event'   => self::slug( $event ),
				'msg'     => substr( (string) $message, 0, 500 ),
				'ctx'     => self::scrub( $ctx ),
			);
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}

			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . substr( $ts, 0, 10 ) . '.jsonl';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
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
		try {
			$dir = self::get_scoped_dir( $folder, $segments );
			if ( $dir === '' ) {
				return false;
			}
			$ts = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\\TH:i:s' ) : gmdate( 'Y-m-d\\TH:i:s' );
			$row = array(
				'ts'      => $ts,
				'blog_id' => (int) get_current_blog_id(),
				'level'   => self::slug( $level ),
				'event'   => self::slug( $event ),
				'msg'     => substr( (string) $message, 0, 500 ),
				'ctx'     => self::scrub( $ctx ),
			);
			$line = function_exists( 'wp_json_encode' )
				? wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . substr( $ts, 0, 10 ) . '.jsonl';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			return false;
		}
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
			$entries = array();
			foreach ( array_reverse( (array) @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) as $raw ) {
				$row = json_decode( $raw, true );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$entries[] = $row;
				if ( $limit > 0 && count( $entries ) >= (int) $limit ) {
					break;
				}
			}
			return $entries;
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
			$lines   = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$entries = array();
			foreach ( array_reverse( (array) $lines ) as $raw ) {
				$obj = json_decode( $raw, true );
				if ( ! is_array( $obj ) || ( $level !== '' && ( $obj['level'] ?? '' ) !== $level ) ) {
					continue;
				}
				$entries[] = $obj;
				if ( $limit > 0 && count( $entries ) >= (int) $limit ) {
					break;
				}
			}
			return $entries;
		} catch ( \Throwable $e ) {
			return array();
		}
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
				$rows = self::read( $folder, $module, $date, 0, $level );
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

	private static function get_dir( $folder, $module ) {
		$folder = self::slug( $folder );
		$module = self::slug( $module );
		if ( $folder === '' || $module === '' ) {
			return '';
		}
		$key = (int) get_current_blog_id() . ':' . $folder . ':' . $module;
		if ( isset( self::$dir_cache[ $key ] ) ) {
			return self::$dir_cache[ $key ];
		}
		$upload = wp_upload_dir( null, false );
		$base   = (string) ( $upload['basedir'] ?? '' );
		if ( $base === '' ) {
			return '';
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
		if ( ! file_exists( $root . DIRECTORY_SEPARATOR . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $root . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\nOptions -Indexes\n" );
		}
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
		if ( ! file_exists( $root . DIRECTORY_SEPARATOR . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $root . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\nOptions -Indexes\n" );
		}
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
				if ( preg_match( '/token|secret|password|authorization|api[_-]?key|raw|body|message|phone|email/i', $key_string ) ) {
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
}
