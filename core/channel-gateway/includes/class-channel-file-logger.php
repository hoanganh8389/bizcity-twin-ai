<?php
/**
 * BizCity Channel File Logger — Universal JSONL file logger for ALL channels.
 *
 * ═══════════════════════════════════════════════════════════════
 * RULE TUYỆT ĐỐI — R-CH-FILE-LOG (Tier 1 Canon, 2026-06-19)
 * ═══════════════════════════════════════════════════════════════
 *
 * Mọi channel (email, facebook, zalo_oa, zalo_bot, messenger,
 * telegram, webchat, cf7…) BẮT BUỘC có log JSONL theo ngày, per blog_id,
 * tách theo channel — KHÔNG phụ thuộc DB (file-only, NEVER throws).
 *
 * Full spec: core/channel-gateway/docs/RULE-CHANNEL-FILE-LOG.md
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * Đường dẫn (wp_upload_dir() tự xử lý /sites/{blog_id}/ trên Multisite):
 *   {upload_basedir}/bizcity-channel-logs/{channel}/YYYY-MM-DD.jsonl
 *
 * Ví dụ (sub-site blog_id=2):
 *   .../uploads/sites/2/bizcity-channel-logs/email/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/facebook/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/messenger/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/zalo_oa/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/zalo_bot/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/telegram/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/webchat/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/cf7/2026-06-19.jsonl
 *   .../uploads/sites/2/bizcity-channel-logs/channel_gateway/2026-06-19.jsonl
 *
 * Ví dụ (main site blog_id=1):
 *   .../uploads/bizcity-channel-logs/email/2026-06-19.jsonl
 *
 * Mỗi dòng là 1 JSON object (JSONL format):
 *   {"ts":"2026-06-19T11:58:00","blog_id":2,"channel":"email","level":"info",
 *    "event":"send_ok","msg":"Sent OK","ctx":{...}}
 *
 * Ghi chú bảo mật:
 *   - .htaccess "Deny from all" tự động tạo tại bizcity-channel-logs/
 *   - Không ghi provider keys, passwords, PII vào ctx (OWASP A05)
 *   - file_put_contents dùng LOCK_EX để tránh interleave concurrency
 *
 * PHP 7.4 compatible — no union types, no nullsafe, no match.
 *
 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — BizCity Channel File Logger
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 0.37.2
 */

defined( 'ABSPATH' ) || exit;

// class_exists guard: this file may be require_once'd from both
// core/channel-gateway AND plugins/bizcity-twin-crm — only define once.
if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) { return; }

class BizCity_Channel_File_Logger {

	/** Base folder name under uploads dir. */
	const BASE_FOLDER = 'bizcity-channel-logs';

	/**
	 * Channel name constants.
	 * These are the canonical folder names used for per-channel log files.
	 */
	const CH_EMAIL           = 'email';
	const CH_FACEBOOK        = 'facebook';
	const CH_MESSENGER       = 'messenger';
	const CH_ZALO_OA         = 'zalo_oa';
	const CH_ZALO_BOT        = 'zalo_bot';
	// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS (Zalo Notification Service) dedicated channel folder
	const CH_ZALO_ZNS        = 'zalo_zns';
	const CH_TELEGRAM        = 'telegram';
	const CH_WEBCHAT         = 'webchat';
	const CH_CF7             = 'cf7';
	const CH_CHANNEL_GATEWAY = 'channel_gateway'; // Generic fallback
	// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — astro hub call log (client → llm router)
	const CH_ASTRO           = 'astro';

	/** Log level constants. */
	const LEVEL_DEBUG = 'debug';
	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warn';
	const LEVEL_ERROR = 'error';

	/**
	 * Runtime cache: resolved log dirs keyed by "{blog_id}:{channel}".
	 * Keyed by blog_id so switch_to_blog() context is safe.
	 *
	 * @var array<string,string>
	 */
	private static $dir_cache = array();

	// ──────────────────────────────────────────────────────────────────
	// Write API
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Write one log entry to the channel's JSONL file.
	 *
	 * NEVER throws. On any failure returns false and falls back to error_log().
	 *
	 * @param string $channel  One of CH_* constants or any lowercase_slug.
	 * @param string $level    One of LEVEL_* constants.
	 * @param string $event    Machine-readable event name, e.g. 'send_ok', 'send_failed'.
	 * @param string $message  Human-readable summary (truncated at 500 chars).
	 * @param array  $ctx      Key→value context (rule_id, recipient, error…).
	 *                         DO NOT include passwords, tokens, full SQL, PII.
	 * @return bool
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

			$line = json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( $line === false ) { return false; }
			$line .= "\n";

			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
			return true;
		} catch ( \Throwable $e ) {
			// Logger MUST NEVER THROW. Fall back to error_log as last resort.
			error_log( '[bizcity-channel-logger] write() failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Convenience shortcut for error-level writes that include a PHP exception.
	 *
	 * @param string          $channel
	 * @param string          $event
	 * @param string          $message
	 * @param array           $ctx
	 * @param \Throwable|null $e  Optional exception — adds class, message, file:line, compact trace.
	 * @return bool
	 */
	public static function error( $channel, $event, $message, array $ctx = array(), $e = null ) {
		if ( $e !== null ) {
			$ctx['exception_class']   = get_class( $e );
			$ctx['exception_message'] = $e->getMessage();
			$ctx['exception_file']    = basename( $e->getFile() ) . ':' . $e->getLine();
			$frames = array();
			foreach ( array_slice( $e->getTrace(), 0, 5 ) as $f ) {
				$frames[] = trim(
					( isset( $f['file'] ) ? basename( $f['file'] ) . ':' . ( $f['line'] ?? '?' ) : '' )
					. ' ' . ( $f['class'] ?? '' ) . ( $f['type'] ?? '' ) . ( $f['function'] ?? '' )
				);
			}
			$ctx['exception_trace'] = $frames;
		}
		return self::write( $channel, self::LEVEL_ERROR, $event, $message, $ctx );
	}

	// ──────────────────────────────────────────────────────────────────
	// Read API (admin/diagnostic use only)
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Read entries from a channel's log file (newest-first).
	 *
	 * @param string $channel
	 * @param string $date     Y-m-d string, defaults to today.
	 * @param int    $limit    Max rows to return (0 = all, up to 5000).
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
			foreach ( array_reverse( (array) $lines ) as $raw ) {
				$obj = json_decode( $raw, true );
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
	 * @return string[]
	 */
	public static function list_dates( $channel, $max = 30 ) {
		try {
			$dir   = self::get_log_dir( $channel );
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

	// ──────────────────────────────────────────────────────────────────
	// Internal helpers
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Resolve (and create if needed) the filesystem directory for a channel's logs.
	 * Returns '' on failure — caller must treat '' as "skip logging".
	 *
	 * wp_upload_dir() already returns the per-site path on Multisite:
	 *   Main site : .../uploads/
	 *   Sub-site  : .../uploads/sites/{blog_id}/
	 * So we must NOT append blog_id manually.
	 */
	private static function get_log_dir( $channel ) {
		$channel = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $channel ) );
		if ( $channel === '' ) { return ''; }

		// Cache key includes blog_id so switch_to_blog() contexts are isolated.
		$blog_id   = (int) get_current_blog_id();
		$cache_key = $blog_id . ':' . $channel;
		if ( isset( self::$dir_cache[ $cache_key ] ) ) {
			return self::$dir_cache[ $cache_key ];
		}

		$upload = wp_upload_dir();
		$base   = (string) ( $upload['basedir'] ?? '' );
		if ( $base === '' ) { return ''; }

		$base_log = $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER;
		$dir      = $base_log . DIRECTORY_SEPARATOR . $channel;

		// Create directory tree if needed.
		if ( ! file_exists( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $dir, 0755, true );
		}

		// Protect parent dir from web access (runs once per new install).
		$htaccess = $base_log . DIRECTORY_SEPARATOR . '.htaccess';
		if ( file_exists( $base_log ) && ! file_exists( $htaccess ) ) {
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
