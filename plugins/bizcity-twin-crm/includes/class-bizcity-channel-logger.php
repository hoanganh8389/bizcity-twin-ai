<?php
/**
 * BizCity Channel File Logger — CRM plugin shim.
 *
 * The canonical implementation is in:
 *   core/channel-gateway/includes/class-channel-file-logger.php
 *
 * This file re-uses that class via class_exists() guard and is kept
 * here so bizcity-twin-crm can be deployed without core/channel-gateway
 * (e.g. standalone CRM installations).
 *
 * Full rule spec: core/channel-gateway/docs/RULE-CHANNEL-FILE-LOG.md
 *
 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — CRM shim for BizCity_Channel_File_Logger
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.2
 */

defined( 'ABSPATH' ) || exit;

// If core/channel-gateway already loaded the canonical class, nothing to do.
// This guard is what makes it safe to require_once from both bootstrap files.
if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
	return;
}

// Canonical class not loaded — load our own copy so CRM works standalone.
// (Copy kept in sync with the CG version — see diff history for divergence.)

// phpcs:disable
class BizCity_Channel_File_Logger {

	const BASE_FOLDER      = 'bizcity-channel-logs';
	const CH_EMAIL         = 'email';
	const CH_FACEBOOK      = 'facebook';
	const CH_MESSENGER     = 'messenger';
	const CH_ZALO_OA       = 'zalo_oa';
	const CH_ZALO_BOT      = 'zalo_bot';
	const CH_TELEGRAM      = 'telegram';
	const CH_WEBCHAT       = 'webchat';
	const CH_CF7           = 'cf7';
	const CH_CHANNEL_GATEWAY = 'channel_gateway';
	const LEVEL_DEBUG      = 'debug';
	const LEVEL_INFO       = 'info';
	const LEVEL_WARN       = 'warn';
	const LEVEL_ERROR      = 'error';

	private static $dir_cache = array();

	/**
	 * Write one log entry.
	 *
	 * @param string $channel  One of CH_* constants or any lowercase_slug.
	 * @param string $level    One of LEVEL_* constants.
	 * @param string $event    Machine-readable event name, e.g. 'send_ok', 'send_failed', 'hook_error'.
	 * @param string $message  Human-readable summary (≤ 200 chars).
	 * @param array  $ctx      Extra key→value context (rule_id, recipient, subject, error…).
	 *
	 * @return bool True if written to file, false on any failure (never throws).
	 */
	public static function write( $channel, $level, $event, $message, array $ctx = array() ) {
		try {
			$dir = self::get_log_dir( $channel );
			if ( $dir === '' ) { return false; }

			$ts   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\TH:i:s' ) : gmdate( 'Y-m-d\TH:i:s' );
			$date = substr( $ts, 0, 10 );

			$entry = array(
				'ts'      => $ts,
				'blog_id' => (int) get_current_blog_id(),
				'channel' => (string) $channel,
				'level'   => (string) $level,
				'event'   => (string) $event,
				'msg'     => substr( (string) $message, 0, 500 ),
				'ctx'     => $ctx,
			);

			$line = json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
			return true;
		} catch ( \Throwable $e ) {
			// Logger must NEVER throw. Last-resort: native error_log.
			error_log( '[bizcity-channel-logger] write() failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Shortcut for error-level write with exception capture.
	 *
	 * @param string     $channel
	 * @param string     $event
	 * @param string     $message
	 * @param array      $ctx
	 * @param \Throwable $e       Optional exception to include trace in ctx.
	 */
	public static function error( $channel, $event, $message, array $ctx = array(), $e = null ) {
		if ( $e !== null ) {
			$ctx['exception_class']   = get_class( $e );
			$ctx['exception_message'] = $e->getMessage();
			$ctx['exception_file']    = basename( $e->getFile() ) . ':' . $e->getLine();
			// Compact trace — only first 5 frames
			$frames = array();
			foreach ( array_slice( $e->getTrace(), 0, 5 ) as $f ) {
				$frames[] = ( isset( $f['file'] ) ? basename( $f['file'] ) . ':' . ( $f['line'] ?? '?' ) : '' )
					. ' ' . ( $f['class'] ?? '' ) . ( $f['type'] ?? '' ) . ( $f['function'] ?? '' );
			}
			$ctx['exception_trace'] = $frames;
		}
		return self::write( $channel, self::LEVEL_ERROR, $event, $message, $ctx );
	}

	/**
	 * Read a log file and return decoded entries.
	 *
	 * @param string $channel
	 * @param string $date     Y-m-d, defaults to today.
	 * @param int    $limit    Max rows to return (0 = all).
	 * @param string $level    Filter by level ('' = all).
	 * @return array
	 */
	public static function read( $channel, $date = '', $limit = 200, $level = '' ) {
		try {
			if ( $date === '' ) {
				$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			}
			$dir  = self::get_log_dir( $channel );
			if ( $dir === '' ) { return array(); }
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
			if ( ! file_exists( $file ) ) { return array(); }

			$lines   = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$entries = array();
			foreach ( array_reverse( (array) $lines ) as $line ) {
				$obj = json_decode( $line, true );
				if ( ! is_array( $obj ) ) { continue; }
				if ( $level !== '' && ( $obj['level'] ?? '' ) !== $level ) { continue; }
				$entries[] = $obj;
				if ( $limit > 0 && count( $entries ) >= $limit ) { break; }
			}
			return $entries;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * List available log dates for a channel (most recent first).
	 *
	 * @param string $channel
	 * @param int    $max
	 * @return string[] Y-m-d strings
	 */
	public static function list_dates( $channel, $max = 30 ) {
		try {
			$dir = self::get_log_dir( $channel );
			if ( $dir === '' ) { return array(); }
			$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
			if ( ! is_array( $files ) ) { return array(); }
			$dates = array();
			foreach ( $files as $f ) {
				$dates[] = basename( $f, '.jsonl' );
			}
			rsort( $dates );
			return array_slice( $dates, 0, $max );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Get (and create if needed) the directory for a channel's logs.
	 * Returns '' on failure.
	 */
	private static function get_log_dir( $channel ) {
		$channel = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $channel ) );
		if ( $channel === '' ) { return ''; }

		// Key includes blog_id so switch_to_blog() context is safe
		$blog_id   = (int) get_current_blog_id();
		$cache_key = $blog_id . ':' . $channel;
		if ( isset( self::$dir_cache[ $cache_key ] ) ) {
			return self::$dir_cache[ $cache_key ];
		}

		// wp_upload_dir() already returns the per-site path on Multisite
		// e.g. .../uploads/sites/2/  — do NOT add blog_id manually.
		$upload = wp_upload_dir();
		$base   = (string) ( $upload['basedir'] ?? '' );
		if ( $base === '' ) { return ''; }

		$base_log_dir = $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER;
		$dir          = $base_log_dir . DIRECTORY_SEPARATOR . $channel;

		if ( ! file_exists( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $dir, 0755, true );
		}

		// Drop .htaccess at the base log dir level to block web access
		$htaccess = $base_log_dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Deny from all\nOptions -Indexes\n" );
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}

		self::$dir_cache[ $cache_key ] = $dir;
		return $dir;
	}
}
