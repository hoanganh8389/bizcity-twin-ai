<?php
/**
 * BizCoach Pro — Astro Log
 *
 * Thin wrapper around BizCity_Channel_File_Logger for the 'astro' channel.
 * Logs every client → hub (LLM Router) astrology call to:
 *   {upload_basedir}/bizcity-channel-logs/astro/YYYY-MM-DD.jsonl
 *
 * On Multisite with blog_id=1488:
 *   .../uploads/sites/1488/bizcity-channel-logs/astro/2026-07-03.jsonl
 *
 * Usage (PHP):
 *   BizCoach_Pro_Astro_Log::info( 'natal_request', 'Calling FAA2 natal', [ 'user_id' => 5 ] );
 *   BizCoach_Pro_Astro_Log::ok(   'natal_response', 'FAA2 natal ok',     [ 'planets' => 10, 'latency_ms' => 420 ] );
 *   BizCoach_Pro_Astro_Log::fail( 'natal_response', 'FAA2 natal failed', [ 'error' => 'timeout' ] );
 *   BizCoach_Pro_Astro_Log::transit_range_call( $input, $result );   // structured shortcut
 *
 * Rules (R-CH-FILE-LOG):
 *   1. File log TRƯỚC DB call — evidence even when DB throws.
 *   2. NEVER logs provider API keys, tokens, passwords, full PII.
 *   3. NEVER throws — all failures are swallowed + error_log fallback.
 *
 * PHP 7.4 compatible.
 *
 * [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — astro channel logger
 *
 * @package BizCoach_Pro
 * @since   0.4.3
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) { return; }

class BizCoach_Pro_Astro_Log {

	const CHANNEL = 'astro';

	// ──────────────────────────────────────────────────────────────────
	// Public log API
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Log at info level.
	 *
	 * @param string $event  e.g. 'natal_request', 'transit_range_ok'
	 * @param string $msg    Human-readable summary.
	 * @param array  $ctx    Key→value context (no tokens/PII).
	 */
	public static function info( $event, $msg = '', array $ctx = array() ) {
		self::write( 'info', $event, $msg, $ctx );
	}

	/**
	 * Log successful API call.
	 */
	public static function ok( $event, $msg = '', array $ctx = array() ) {
		self::write( 'info', $event, $msg, array_merge( array( 'status' => 'ok' ), $ctx ) );
	}

	/**
	 * Log failed API call.
	 */
	public static function fail( $event, $msg = '', array $ctx = array() ) {
		self::write( 'error', $event, $msg, array_merge( array( 'status' => 'fail' ), $ctx ) );
	}

	/**
	 * Log warning.
	 */
	public static function warn( $event, $msg = '', array $ctx = array() ) {
		self::write( 'warn', $event, $msg, $ctx );
	}

	// ──────────────────────────────────────────────────────────────────
	// Structured shortcuts for common call patterns
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Log a natal chart request + response as two rows (request then result).
	 *
	 * @param array  $input  Canonical input (datetime_utc, lat, lon, tz).
	 * @param array  $result Provider natal() response.
	 * @param int    $coachee_id
	 * @param string $provider_id  e.g. 'faa2_western'.
	 */
	public static function natal_call( array $input, array $result, $coachee_id = 0, $provider_id = 'faa2_western' ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — structured natal log
		$ok = ! empty( $result['success'] );
		self::write(
			$ok ? 'info' : 'error',
			$ok ? 'natal_ok' : 'natal_failed',
			$ok ? 'Natal chart fetched OK' : 'Natal chart fetch failed',
			array(
				'provider'     => $provider_id,
				'coachee_id'   => (int) $coachee_id,
				'datetime_utc' => $input['datetime_utc'] ?? '',
				'lat'          => $input['lat'] ?? '',
				'lon'          => $input['lon'] ?? '',
				'tz'           => $input['tz'] ?? '',
				'planet_count' => $ok ? count( (array) ( $result['planets'] ?? array() ) ) : 0,
				'house_count'  => $ok ? count( (array) ( $result['houses']  ?? array() ) ) : 0,
				'latency_ms'   => $result['latency_ms'] ?? null,
				'error'        => $ok ? null : ( $result['message'] ?? $result['msg'] ?? 'unknown' ),
			)
		);
	}

	/**
	 * Log a transit_range call (day-by-day 30-day range).
	 *
	 * @param array  $input    { natal_planets, start_date, num_days, outer_only }.
	 * @param array  $result   Provider transit_range() response.
	 * @param int    $coachee_id
	 * @param string $source   'cap_filter' | 'self_service_rest' | 'direct'.
	 */
	public static function transit_range_call( array $input, array $result, $coachee_id = 0, $source = 'cap_filter' ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — structured transit_range log
		$ok = ! empty( $result['success'] );
		self::write(
			$ok ? 'info' : 'error',
			$ok ? 'transit_range_ok' : 'transit_range_failed',
			$ok ? 'Transit range fetched OK' : 'Transit range failed',
			array(
				'provider'       => 'faa2_western',
				'source'         => $source,
				'coachee_id'     => (int) $coachee_id,
				'start_date'     => $input['start_date']   ?? '',
				'num_days'       => $input['num_days']      ?? '',
				'outer_only'     => $input['outer_only']    ?? false,
				'natal_planets'  => isset( $input['natal_planets'] ) ? count( (array) $input['natal_planets'] ) : 0,
				'days_returned'  => $ok ? count( (array) ( $result['daily'] ?? array() ) ) : 0,
				'api_calls'      => $ok ? ( $result['_debug']['api_calls']   ?? null ) : null,
				'cache_hits'     => $ok ? ( $result['_debug']['cache_hits']  ?? null ) : null,
				'latency_ms'     => $result['latency_ms'] ?? null,
				'error'          => $ok ? null : ( $result['message'] ?? $result['msg'] ?? 'unknown' ),
			)
		);
	}

	/**
	 * Log a transit snapshot call (single-day, for current sky).
	 *
	 * @param string $transit_date  Y-m-d.
	 * @param array  $result        Provider transit() response.
	 */
	public static function transit_snap_call( $transit_date, array $result ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — transit snapshot log
		$ok = ! empty( $result['success'] );
		self::write(
			$ok ? 'info' : 'warn',
			$ok ? 'transit_snap_ok' : 'transit_snap_failed',
			$ok ? 'Transit snapshot ok' : 'Transit snapshot failed',
			array(
				'provider'     => 'faa2_western',
				'transit_date' => $transit_date,
				'planet_count' => $ok ? count( (array) ( $result['planets'] ?? array() ) ) : 0,
				'latency_ms'   => $result['latency_ms'] ?? null,
				'error'        => $ok ? null : ( $result['message'] ?? 'unknown' ),
			)
		);
	}

	/**
	 * Log a compare_natal call (FAA2 vs FAA).
	 *
	 * @param array $faa2_result
	 * @param array $faa_result
	 * @param int   $mismatches
	 */
	public static function compare_call( array $faa2_result, array $faa_result, $mismatches = 0 ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — dual compare log
		$ok2 = ! empty( $faa2_result['success'] );
		$ok1 = ! empty( $faa_result['success'] );
		self::write(
			( $ok2 && $ok1 && $mismatches === 0 ) ? 'info' : 'warn',
			'compare_natal',
			'Dual-provider natal compare',
			array(
				'faa2_ok'         => $ok2,
				'faa2_planets'    => $ok2 ? count( (array) ( $faa2_result['planets'] ?? array() ) ) : 0,
				'faa2_latency_ms' => $faa2_result['latency_ms'] ?? null,
				'faa_ok'          => $ok1,
				'faa_planets'     => $ok1 ? count( (array) ( $faa_result['planets'] ?? array() ) ) : 0,
				'faa_latency_ms'  => $faa_result['latency_ms'] ?? null,
				'sign_mismatches' => $mismatches,
			)
		);
	}

	// ──────────────────────────────────────────────────────────────────
	// Internal
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Core write — delegates to BizCity_Channel_File_Logger.
	 * Falls back to error_log if class is not available.
	 */
	private static function write( $level, $event, $msg, array $ctx ) {
		try {
			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write( self::CHANNEL, $level, $event, $msg, $ctx );
			} else {
				// Fallback: write to a daily file in uploads directly (no external dep)
				self::fallback_write( $level, $event, $msg, $ctx );
			}
		} catch ( \Throwable $e ) {
			error_log( '[bcpro/astro-log] write() failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Fallback writer when BizCity_Channel_File_Logger is not loaded yet.
	 * Uses the same path convention so files are readable by the admin page.
	 */
	private static function fallback_write( $level, $event, $msg, array $ctx ) {
		$upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( $base === '' ) { return; }

		$dir  = $base . DIRECTORY_SEPARATOR . 'bizcity-channel-logs' . DIRECTORY_SEPARATOR . self::CHANNEL;
		if ( ! file_exists( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		if ( ! is_dir( $dir ) ) { return; }

		$ts   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\TH:i:s' ) : gmdate( 'Y-m-d\TH:i:s' );
		$date = substr( $ts, 0, 10 );
		$row  = array(
			'ts'      => $ts,
			'blog_id' => (int) get_current_blog_id(),
			'channel' => self::CHANNEL,
			'level'   => (string) $level,
			'event'   => (string) $event,
			'msg'     => substr( (string) $msg, 0, 500 ),
			'ctx'     => $ctx,
		);
		$line = json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $line === false ) { return; }
		@file_put_contents( $dir . DIRECTORY_SEPARATOR . $date . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );
	}
}
