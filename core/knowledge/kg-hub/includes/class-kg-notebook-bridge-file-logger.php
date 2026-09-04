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
	const JSONL_FOLDER = 'bizcity-notebook-bridge-logs';
	const JSONL_MODULE = 'capture-lifecycle';
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
			if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
				return false;
			}
			$event = sanitize_key( $event );
			$ctx = array(
				'ts_unix' => microtime( true ),
				'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
				'data'    => self::sanitize_context( $data ),
			);
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — bridge events resolve their framework contract before writing sanitized payloads.
			return (bool) BizCity_JSONL_File_Logger::write_contract( 'core.knowledge.notebook_bridge', sanitize_key( $level ), $event !== '' ? $event : 'bridge_event', $event, $ctx );
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
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return;
		}
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — bridge retention uses the shared whole-file purge primitive.
		BizCity_JSONL_File_Logger::purge_older_than( self::JSONL_FOLDER, self::JSONL_MODULE, self::KEEP_DAYS );
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
