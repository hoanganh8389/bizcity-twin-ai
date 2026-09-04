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
	// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — dedicated operational bucket for Zalo Personal archive failures.
	const CH_ZALO_PERSONAL   = 'zalo_personal';
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
	 * Write one normalized channel diagnostics record.
	 *
	 * @param array<string,mixed> $record Public channel-diagnostics-record shape.
	 * @return array<string,mixed> Durable write receipt or failure reason.
	 */
	public static function write_record( array $record ) {
		// [2026-09-01 Johnny Chu] R-CH-10 — one structured channel diagnostics writer with exact account and Zalo channel boundaries.
		try {
			$channel = sanitize_key( (string) ( $record['channel'] ?? '' ) );
			$allowed_channels = array(
				self::CH_EMAIL,
				self::CH_FACEBOOK,
				self::CH_MESSENGER,
				self::CH_ZALO_OA,
				self::CH_ZALO_BOT,
				self::CH_ZALO_PERSONAL,
				self::CH_ZALO_ZNS,
				self::CH_TELEGRAM,
				self::CH_WEBCHAT,
				self::CH_CF7,
				self::CH_CHANNEL_GATEWAY,
				self::CH_ASTRO,
			);
			if ( $channel === '' || ! in_array( $channel, $allowed_channels, true ) || $channel === 'zalo' ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'invalid_channel' );
			}

			$record['contract'] = 'channel-diagnostics-record';
			$record['version']  = (string) ( $record['version'] ?? '1.0.0' );
			$record['channel']  = $channel;
			$record['zone']     = self::normalize_zone( $channel, $record['zone'] ?? '' );
			$record['direction'] = self::normalize_direction( $record['direction'] ?? 'internal' );
			$record['level']     = self::normalize_level( $record['level'] ?? self::LEVEL_INFO );
			$record['event']     = sanitize_key( (string) ( $record['event'] ?? 'channel_event' ) );
			$record['stage']     = self::normalize_stage( $record['stage'] ?? '' );
			$record['event_uuid'] = self::ensure_event_uuid( $record['event_uuid'] ?? '' );
			$record['trace_id']    = (string) ( $record['trace_id'] ?? '' );
			$record['occurred_at'] = self::normalize_timestamp( $record['occurred_at'] ?? '' );
			if ( isset( $record['message'] ) ) {
				$record['message'] = self::safe_message( $record['message'] );
			}
			$record['producer'] = self::normalize_producer( $record['producer'] ?? array() );
			$account_input = is_array( $record['account'] ?? null ) ? $record['account'] : array();
			if ( $channel !== self::CH_CHANNEL_GATEWAY && $channel !== self::CH_ASTRO && empty( $account_input['account_id'] ) ) {
				// [2026-09-01 Johnny Chu] R-CH-10 — real channel events must fail closed when exact account scope is missing.
				return array( 'written' => false, 'indexed' => false, 'reason' => 'account_scope_required' );
			}
			$record['account'] = self::normalize_account( $record['account'] ?? array(), $channel );
			$record['pipeline_status'] = self::normalize_pipeline_status( $record['pipeline_status'] ?? array() );
			// [2026-09-01 Johnny Chu] R-CH-10 — this immutable row exists only after its operational append succeeds.
			$record['pipeline_status']['operational_logged'] = 'success';
			$record['context'] = self::scrub_context( is_array( $record['context'] ?? null ) ? $record['context'] : array() );

			$contract_id = self::contract_id( $channel );
			if ( $contract_id === '' ) {
				return array( 'written' => false, 'indexed' => false, 'reason' => 'channel_contract_missing' );
			}
			if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'write_contract_record' ) ) {
				$receipt = BizCity_JSONL_File_Logger::write_contract_record( $contract_id, $record );
				return $receipt;
			}

			return array( 'written' => false, 'indexed' => false, 'reason' => 'canonical_logger_missing' );
		} catch ( \Throwable $e ) {
			return array( 'written' => false, 'indexed' => false, 'reason' => 'channel_logger_exception' );
		}
	}

	/**
	 * Write one log entry to the channel's JSONL file.
	 *
	* NEVER throws. On any failure returns false without writing to the shared PHP diagnostic stream.
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
		// [2026-09-01 Johnny Chu] R-CH-10 — legacy positional API delegates to the single structured writer.
		if ( class_exists( 'BizCity_Chat_Correlation' ) ) {
			$ctx = BizCity_Chat_Correlation::ensure( $ctx, $event );
		}
		$physical_channel = sanitize_key( (string) $channel );
		return ! empty( self::write_record( array(
			'channel'    => $physical_channel,
			'level'      => $level,
			'event'      => $event,
			'stage'      => self::stage_for_event( $event ),
			'direction'  => self::direction_for_event( $event ),
			'event_uuid' => $ctx['event_uuid'] ?? '',
			'trace_id'   => $ctx['trace_id'] ?? '',
			'producer'   => array( 'module' => 'core/channel-gateway', 'version' => '1.0.0' ),
			'account'    => self::account_from_context( $ctx, $physical_channel ),
			'pipeline_status' => array(
				'context_captured' => 'not_applicable',
				'ledger_indexed'   => 'pending',
				'kg_candidate'     => 'not_candidate',
			),
			'message'    => self::safe_message( $message ),
			'context'    => $ctx,
		) ) );
	}

	private static function append_line( $file, $line ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — capture channel pointer offset inside the exclusive append lock.
		$handle = @fopen( $file, 'ab' );
		if ( false === $handle || ! @flock( $handle, LOCK_EX ) ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			return array( 'written' => false, 'offset' => 0 );
		}
		$offset = (int) ftell( $handle );
		$written = false !== @fwrite( $handle, $line . "\n" );
		@fflush( $handle );
		@flock( $handle, LOCK_UN );
		fclose( $handle );
		return array( 'written' => $written, 'offset' => $offset );
	}

	private static function new_event_uuid() {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf( '%s-%s-4%s-8%s-%s', substr( sha1( uniqid( '', true ) ), 0, 8 ), substr( sha1( uniqid( '', true ) ), 8, 4 ), substr( sha1( uniqid( '', true ) ), 13, 3 ), substr( sha1( uniqid( '', true ) ), 17, 3 ), substr( sha1( uniqid( '', true ) ), 20, 12 ) );
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

	private static function normalize_zone( $channel, $zone ) {
		$zone = sanitize_key( (string) $zone );
		if ( in_array( $zone, array( 'customer', 'admin', 'system' ), true ) ) {
			return $zone;
		}
		if ( in_array( $channel, array( self::CH_FACEBOOK, self::CH_MESSENGER, self::CH_ZALO_OA, self::CH_ZALO_PERSONAL, self::CH_WEBCHAT ), true ) ) {
			return 'customer';
		}
		if ( in_array( $channel, array( self::CH_ZALO_BOT, self::CH_TELEGRAM ), true ) ) {
			return 'admin';
		}
		return 'system';
	}

	private static function normalize_direction( $direction ) {
		$direction = sanitize_key( (string) $direction );
		return in_array( $direction, array( 'inbound', 'outbound', 'internal' ), true ) ? $direction : 'internal';
	}

	private static function normalize_level( $level ) {
		$level = sanitize_key( (string) $level );
		return in_array( $level, array( self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARN, self::LEVEL_ERROR ), true ) ? $level : self::LEVEL_INFO;
	}

	private static function normalize_stage( $stage ) {
		$stage = sanitize_key( (string) $stage );
		$allowed = array( 'intake', 'normalize', 'persist', 'dispatch', 'delivery', 'archive', 'context', 'ledger', 'kg', 'reconcile', 'system' );
		return in_array( $stage, $allowed, true ) ? $stage : 'system';
	}

	private static function normalize_timestamp( $timestamp ) {
		$timestamp = trim( (string) $timestamp );
		return preg_match( '/^\d{4}-\d{2}-\d{2}T[^ ]+Z$/', $timestamp ) ? $timestamp : gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	private static function ensure_event_uuid( $event_uuid ) {
		$event_uuid = trim( (string) $event_uuid );
		return preg_match( '/^[a-f0-9-]{36}$/i', $event_uuid ) ? strtolower( $event_uuid ) : self::new_event_uuid();
	}

	private static function normalize_producer( $producer ) {
		$producer = is_array( $producer ) ? $producer : array();
		$module = sanitize_text_field( (string) ( $producer['module'] ?? 'core/channel-gateway' ) );
		$version = (string) ( $producer['version'] ?? '1.0.0' );
		if ( $module === '' ) {
			$module = 'core/channel-gateway';
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version ) ) {
			$version = '1.0.0';
		}
		return array( 'module' => $module, 'version' => $version );
	}

	private static function normalize_account( $account, $channel ) {
		$account = is_array( $account ) ? $account : array();
		$account_id = sanitize_text_field( (string) ( $account['account_id'] ?? '' ) );
		$account_key = sanitize_text_field( (string) ( $account['account_key'] ?? '' ) );
		$scope = sanitize_key( (string) ( $account['scope'] ?? '' ) );
		if ( $account_id === '' ) {
			$account_id = 'system:' . $channel;
			$scope = 'system';
		} elseif ( ! in_array( $scope, array( 'exact', 'platform', 'system' ), true ) ) {
			$scope = 'exact';
		}
		$out = array( 'scope' => $scope, 'account_id' => substr( $account_id, 0, 190 ) );
		if ( $account_key !== '' ) {
			$out['account_key'] = substr( $account_key, 0, 191 );
		}
		if ( ! empty( $account['label'] ) ) {
			$out['label'] = substr( sanitize_text_field( (string) $account['label'] ), 0, 120 );
		}
		return $out;
	}

	private static function normalize_pipeline_status( $status ) {
		$status = is_array( $status ) ? $status : array();
		$allowed = array( 'success', 'failed', 'pending', 'skipped', 'not_applicable' );
		$out = array();
		foreach ( array( 'operational_logged', 'context_captured', 'ledger_indexed' ) as $key ) {
			$value = sanitize_key( (string) ( $status[ $key ] ?? '' ) );
			$out[ $key ] = in_array( $value, $allowed, true ) ? $value : ( $key === 'operational_logged' ? 'pending' : 'not_applicable' );
		}
		$kg = sanitize_key( (string) ( $status['kg_candidate'] ?? 'not_candidate' ) );
		$out['kg_candidate'] = in_array( $kg, array( 'pending', 'approved', 'rejected', 'deferred', 'not_candidate', 'not_applicable' ), true ) ? $kg : 'not_candidate';
		return $out;
	}

	private static function safe_message( $message ) {
		// [2026-09-01 Johnny Chu] R-CH-10 — keep operational summaries bounded and redact common credential/PII shapes before JSONL persistence.
		$message = trim( (string) $message );
		$message = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message );
		$message = preg_replace( '/\b(?:Bearer\s+|biz[-_])[A-Za-z0-9._\-]{8,}\b/i', '[redacted-token]', $message );
		$message = preg_replace( '/(?<!\d)(?:\+?\d[\d .()\-]{7,}\d)(?!\d)/', '[redacted-phone]', $message );
		return substr( $message, 0, 500 );
	}

	private static function account_from_context( array $ctx, $channel ) {
		$account_id = '';
		foreach ( array( 'account_id', 'channel_account_id', 'bot_id', 'oa_id', 'page_id', 'account_uid', 'client_id' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && (string) $ctx[ $key ] !== '' ) {
				$account_id = (string) $ctx[ $key ];
				break;
			}
		}
		return array(
			'account_id' => $account_id,
			'account_key' => (string) ( $ctx['account_key'] ?? '' ),
			'scope' => $account_id !== '' ? 'exact' : 'system',
		);
	}

	private static function stage_for_event( $event ) {
		$event = sanitize_key( (string) $event );
		if ( strpos( $event, 'webhook' ) !== false || strpos( $event, 'intake' ) !== false ) { return 'intake'; }
		if ( strpos( $event, 'normalize' ) !== false || strpos( $event, 'received' ) !== false ) { return 'normalize'; }
		if ( strpos( $event, 'send' ) !== false || strpos( $event, 'deliver' ) !== false ) { return 'delivery'; }
		if ( strpos( $event, 'archive' ) !== false ) { return 'archive'; }
		if ( strpos( $event, 'ledger' ) !== false || strpos( $event, 'index' ) !== false ) { return 'ledger'; }
		return 'system';
	}

	private static function direction_for_event( $event ) {
		$event = sanitize_key( (string) $event );
		if ( strpos( $event, 'received' ) !== false || strpos( $event, 'inbound' ) !== false ) { return 'inbound'; }
		if ( strpos( $event, 'sent' ) !== false || strpos( $event, 'outbound' ) !== false || strpos( $event, 'send' ) !== false ) { return 'outbound'; }
		return 'internal';
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
			$contract_id = self::contract_id( $channel );
			if ( $contract_id !== '' && class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'read_contract' ) ) {
				// [2026-08-27 Johnny Chu] R-LOG-HYBRID — registered channel reads use the canonical bounded reader.
				return (array) BizCity_JSONL_File_Logger::read_contract( $contract_id, $date, $limit, $level );
			}
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
	 * Query normalized channel diagnostics across registered channel contracts.
	 *
	 * @param array<string,mixed> $args Typed channel/account/event filters.
	 * @return array<int,array<string,mixed>> Newest rows first.
	 */
	public static function query_records( array $args = array() ) {
		// [2026-09-01 Johnny Chu] R-CH-10 — one bounded account-scoped reader for every channel diagnostics consumer.
		$channels = array( self::CH_EMAIL, self::CH_FACEBOOK, self::CH_MESSENGER, self::CH_ZALO_OA, self::CH_ZALO_BOT, self::CH_ZALO_PERSONAL, self::CH_ZALO_ZNS, self::CH_TELEGRAM, self::CH_WEBCHAT, self::CH_CF7, self::CH_CHANNEL_GATEWAY, self::CH_ASTRO );
		$requested_channel = sanitize_key( (string) ( $args['channel'] ?? '' ) );
		if ( $requested_channel !== '' ) {
			if ( ! in_array( $requested_channel, $channels, true ) ) {
				return array();
			}
			$channels = array( $requested_channel );
		}
		$days = max( 1, min( 31, (int) ( $args['days'] ?? 3 ) ) );
		$limit = max( 1, min( 5000, (int) ( $args['limit'] ?? 200 ) ) );
		$level = sanitize_key( (string) ( $args['level'] ?? '' ) );
		$account_id = sanitize_text_field( (string) ( $args['account_id'] ?? '' ) );
		$event = sanitize_key( (string) ( $args['event'] ?? '' ) );
		$stage = sanitize_key( (string) ( $args['stage'] ?? '' ) );
		$trace_id = sanitize_text_field( (string) ( $args['trace_id'] ?? '' ) );
		$date = sanitize_text_field( (string) ( $args['date'] ?? '' ) );
		$query = sanitize_text_field( (string) ( $args['q'] ?? '' ) );
		$status_filters = array();
		foreach ( array( 'operational_logged', 'context_captured', 'ledger_indexed', 'kg_candidate' ) as $status_key ) {
			$status_value = sanitize_key( (string) ( $args[ $status_key ] ?? '' ) );
			if ( $status_value !== '' ) {
				$status_filters[ $status_key ] = $status_value;
			}
		}
		if ( $date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}
		$rows = array();
		$seen = array();
		foreach ( $channels as $channel ) {
			$contract_id = self::contract_id( $channel );
			if ( $contract_id === '' || ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
				continue;
			}
			$channel_rows = BizCity_JSONL_File_Logger::query_contract( $contract_id, array(
				'days' => $days,
				'limit' => $limit,
				'level' => $level,
				'filter' => function ( $row ) use ( $channel, $account_id, $event, $stage, $trace_id, $date, $query, $status_filters ) {
					if ( ! is_array( $row ) ) { return false; }
					if ( sanitize_key( (string) ( $row['channel'] ?? $channel ) ) !== $channel ) { return false; }
					if ( $date !== '' && substr( (string) ( $row['occurred_at'] ?? $row['ts'] ?? '' ), 0, 10 ) !== $date ) { return false; }
					$account = is_array( $row['account'] ?? null ) ? $row['account'] : array();
					if ( $account_id !== '' && (string) ( $account['account_id'] ?? '' ) !== $account_id ) { return false; }
					if ( $event !== '' && sanitize_key( (string) ( $row['event'] ?? '' ) ) !== $event ) { return false; }
					if ( $stage !== '' && sanitize_key( (string) ( $row['stage'] ?? '' ) ) !== $stage ) { return false; }
					if ( $trace_id !== '' && (string) ( $row['trace_id'] ?? '' ) !== $trace_id ) { return false; }
					if ( $query !== '' ) {
						$haystack = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row ) : json_encode( $row );
						if ( ! is_string( $haystack ) || stripos( $haystack, $query ) === false ) { return false; }
					}
					$pipeline = is_array( $row['pipeline_status'] ?? null ) ? $row['pipeline_status'] : array();
					foreach ( $status_filters as $status_key => $status_value ) {
						if ( (string) ( $pipeline[ $status_key ] ?? '' ) !== $status_value ) { return false; }
					}
					return true;
				},
			) );
			foreach ( is_array( $channel_rows ) ? $channel_rows : array() as $row ) {
				$key = (string) ( $row['event_uuid'] ?? '' );
				if ( $key !== '' && isset( $seen[ $key ] ) ) { continue; }
				if ( $key !== '' ) { $seen[ $key ] = true; }
				$rows[] = $row;
			}
		}
		usort( $rows, function ( $left, $right ) {
				$left_time = (string) ( $left['occurred_at'] ?? $left['ts'] ?? '' );
				$right_time = (string) ( $right['occurred_at'] ?? $right['ts'] ?? '' );
				return strcmp( $right_time, $left_time );
			} );
		return array_slice( $rows, 0, $limit );
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
			$contract_id = self::contract_id( $channel );
			if ( $contract_id !== '' && class_exists( 'BizCity_JSONL_File_Logger' ) && class_exists( 'BizCity_Log_Contract_Registry' ) ) {
				$contract = BizCity_Log_Contract_Registry::get( $contract_id );
				if ( is_array( $contract ) ) {
					return BizCity_JSONL_File_Logger::list_dates( $contract['jsonl_folder'], $contract['jsonl_module'], $max );
				}
			}
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

	/** Delete whole date files older than the channel retention window. */
	public static function purge_older_than( $channel, $days ): int {
		// [2026-08-01 Johnny Chu] PHASE-1.27-CHANNEL-RETENTION — keep channel
		// evidence bounded without deleting individual append-only JSONL rows.
		try {
			$contract_id = self::contract_id( $channel );
			if ( $contract_id !== '' && class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'purge_contract' ) ) {
				// [2026-08-27 Johnny Chu] R-LOG-HYBRID — registered channel retention uses the canonical contract policy.
				return (int) BizCity_JSONL_File_Logger::purge_contract( $contract_id, $days );
			}
			$dir = self::get_log_dir( $channel );
			if ( $dir === '' ) {
				return 0;
			}
			$cutoff_ts = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
			$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
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
				if ( false !== $file_ts && $file_ts < $cutoff_ts && @unlink( $file ) ) {
					$deleted++;
				}
			}
			return $deleted;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	// ──────────────────────────────────────────────────────────────────
	// Internal helpers
	// ──────────────────────────────────────────────────────────────────

	private static function contract_id( $channel ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — resolve only registry-owned channel identities; unregistered compatibility channels retain their legacy facade path.
		$channel = sanitize_key( (string) $channel );
		$contract_id = 'core.channel_gateway.' . $channel;
		return class_exists( 'BizCity_Log_Contract_Registry' ) && BizCity_Log_Contract_Registry::has( $contract_id ) ? $contract_id : '';
	}

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

	private static function scrub_context( $value, $depth = 0 ) {
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
					$out[ $key_string ] = self::scrub_context( $item, $depth + 1 );
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
