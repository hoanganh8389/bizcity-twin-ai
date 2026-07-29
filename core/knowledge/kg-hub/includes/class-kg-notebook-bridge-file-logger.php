<?php
/**
 * BizCity KG Notebook Bridge File Logger
 *
 * Dedicated JSONL evidence stream for channel -> notebook capture lifecycle.
 *
 * Path:
 *   {upload_basedir}/bizcity_notebook_bridge_logs/YYYY-MM-DD.jsonl
 *
 * Every line is one JSON object (JSONL), intended for automation tail/parse:
 *   capture_received -> notebook_resolved -> ingest_item_* -> capture_batch_done
 *
 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — add bridge-specific structured log.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub
 * @since      PHASE-0.46 W4.5
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Notebook_Bridge_File_Logger', false ) ) {
	return;
}

final class BizCity_KG_Notebook_Bridge_File_Logger {

	const BASE_FOLDER = 'bizcity_notebook_bridge_logs';
	const KEEP_DAYS   = 14;

	/** @var array<int,string> keyed by blog_id — see R-MSDB.7 cache isolation. */
	private static $dir_cache = array();

	/**
	 * Boot optional hooks.
	 *
	 * Logger writes are direct and can be called without init(); init() only
	 * wires the nightly GC to the core/cron manager when available.
	 */
	public static function init(): void {
		self::bind_gc_hook();
		add_action( 'init', array( __CLASS__, 'bind_gc_hook' ), 20 );
	}

	/**
	 * Try binding to core/cron GC hook once the manager class is loaded.
	 */
	public static function bind_gc_hook(): void {
		static $bound = false;
		if ( $bound || ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		add_action( BizCity_Cron_Manager::GC_HOOK, array( __CLASS__, 'gc_old_logs' ) );
		$bound = true;
	}

	/**
	 * Append one JSONL event row.
	 *
	 * @param string $event machine-readable event name.
	 * @param array  $data  context payload (avoid raw content text / secrets).
	 * @param string $level info|warn|error
	 */
	public static function log( string $event, array $data = array(), string $level = 'info' ): bool {
		try {
			$dir = self::get_log_dir();
			if ( $dir === '' ) {
				return false;
			}

			$entry = array(
				'ts'      => gmdate( 'c' ),
				'ts_unix' => microtime( true ),
				'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'level'   => sanitize_key( $level ),
				'event'   => sanitize_key( $event ),
				'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
				'data'    => self::sanitize_context( $data ),
			);

			$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) || $line === '' ) {
				return false;
			}

			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . gmdate( 'Y-m-d' ) . '.jsonl';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			// Logger must never throw.
			if ( function_exists( 'error_log' ) ) {
				error_log( '[bizcity-notebook-bridge-logger] write failed: ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Keep only the latest KEEP_DAYS files.
	 */
	public static function gc_old_logs(): void {
		try {
			$dir = self::get_log_dir();
			if ( $dir === '' || ! is_dir( $dir ) ) {
				return;
			}
			$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
			if ( ! is_array( $files ) || count( $files ) <= self::KEEP_DAYS ) {
				return;
			}
			sort( $files, SORT_STRING );
			$delete = array_slice( $files, 0, max( 0, count( $files ) - self::KEEP_DAYS ) );
			foreach ( $delete as $file ) {
				if ( is_string( $file ) && file_exists( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $file );
				}
			}
		} catch ( \Throwable $e ) {
			// Silent by design.
		}
	}

	/**
	 * Resolve and create base log directory.
	 *
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-8 (R-MSDB.7) — cache key
	 * MUST include blog_id. A bare static string here would, on any
	 * persistent PHP worker that serves multiple blogs without restarting
	 * (or after any switch_to_blog() earlier in the same process), silently
	 * keep writing every subsequent blog's bridge events into the FIRST
	 * blog's uploads dir — no error, just events vanishing from the site the
	 * user actually expects to see them in.
	 */
	private static function get_log_dir(): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( isset( self::$dir_cache[ $blog_id ] ) && self::$dir_cache[ $blog_id ] !== '' ) {
			return self::$dir_cache[ $blog_id ];
		}

		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$base = wp_normalize_path( (string) $uploads['basedir'] );
		$dir  = trailingslashit( $base ) . self::BASE_FOLDER;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index_file, "<?php // Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
		}

		self::$dir_cache[ $blog_id ] = $dir;
		return $dir;
	}

	/**
	 * Keep context machine-readable while stripping large/unsafe payloads.
	 */
	private static function sanitize_context( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( $k === '' ) {
				continue;
			}

			if ( in_array( $k, array( 'content', 'content_text', 'raw_text', 'token', 'api_key', 'password', 'sql' ), true ) ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$out[ $k ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$nested = array();
				foreach ( $value as $n_key => $n_value ) {
					$nk = sanitize_key( (string) $n_key );
					if ( $nk === '' ) {
						continue;
					}
					if ( is_scalar( $n_value ) || null === $n_value ) {
						$nested[ $nk ] = $n_value;
					}
				}
				$out[ $k ] = $nested;
			}
		}

		return $out;
	}
}
