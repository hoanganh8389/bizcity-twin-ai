<?php
/**
 * Bizcity Twin AI — Twin Debug
 *
 * Single, request-scoped on/off switch for verbose pipeline tracing across
 * the Twin stack (TwinChat stream-handler, Twin_Agent_Loop, SSE writer,
 * Context Builder, KG Retriever, …).
 *
 *  ┌──────────────────────────┬───────────────────────────────────────────┐
 *  │ Toggle source            │ Notes                                     │
 *  ├──────────────────────────┼───────────────────────────────────────────┤
 *  │ define('BIZCITY_TWIN_DEBUG', true)  in wp-config.php                  │
 *  │ option  bizcity_twin_debug = '1'    persistent (admin UI later)       │
 *  │ query   ?twin_debug=1               per-request override (admins only)│
 *  │ filter  bizcity_twin_debug          last word for programmatic gates  │
 *  └──────────────────────────┴───────────────────────────────────────────┘
 *
 * When ON:
 *   • `Debug::trace()` writes a line to site uploads daily file:
 *     `/uploads/sites/{id}/bizcity-logs/twin-debug/twin-debug-YYYY-MM-DD.log`.
 *   • Same payload is forwarded into `bizcity_intent_pipeline_log` action so
 *     the SSE writer can mirror it as an `event: debug` chunk to the FE.
 *   • FE picks it up in `useTwinChatStream` and prints it under
 *     `console.groupCollapsed('[Twin][BE] …')`.
 *
 * When OFF (default in production):
 *   • All `Debug::trace()` calls return immediately. Zero overhead.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Debug {

	/** @var bool|null memoized gate result for this request */
	private static $enabled = null;

	/** @var float request-start microtime for elapsed timing */
	private static $t0 = 0.0;

	/**
	 * Resolve and memoize the debug gate.
	 */
	public static function is_enabled(): bool {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}

		$on = false;

		if ( defined( 'BIZCITY_TWIN_DEBUG' ) && BIZCITY_TWIN_DEBUG ) {
			$on = true;
		} elseif ( '1' === (string) get_option( 'bizcity_twin_debug', '' ) ) {
			$on = true;
		} elseif ( isset( $_GET['twin_debug'] ) && '1' === (string) $_GET['twin_debug'] && current_user_can( 'manage_options' ) ) {
			$on = true;
		}

		// Final filter — lets other modules force on/off (e.g. integration tests).
		$on = (bool) apply_filters( 'bizcity_twin_debug', $on );

		self::$enabled = $on;
		if ( $on && self::$t0 === 0.0 ) {
			self::$t0 = microtime( true );
		}
		return $on;
	}

	/**
	 * Emit a structured trace entry.
	 *
	 * @param string $scope short namespace, e.g. 'stream', 'agent', 'kg'.
	 * @param string $event short event name, e.g. 'analyzing', 'iter_start'.
	 * @param array  $data  arbitrary structured payload (kept small).
	 */
	public static function trace( string $scope, string $event, array $data = [] ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Per-event mute list — suppress chatty traces from the worker hot path
		// so log files don't get spammed. Override via filter:
		//   add_filter( 'bizcity_twin_debug_mute_events', fn($mut)=>[...] );
		$key   = $scope . '.' . $event;
		$muted = apply_filters(
			'bizcity_twin_debug_mute_events',
			[
				'kg.extract_passage_enqueued',
				'kg.extract_llm_do',
				'kg.extract_llm_done',
			]
		);
		if ( is_array( $muted ) && in_array( $key, $muted, true ) ) {
			return;
		}

		$ms      = self::elapsed_ms();
		$payload = [ 'scope' => $scope, 'event' => $event ] + $data;

		// 1) Daily file line — compact one-liner for post-mortem tracing.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			$json = '[unencodable]';
		}
		self::write_daily_log_line( sprintf( '[TwinDebug %6.0fms] %s.%s %s', $ms, $scope, $event, $json ) );

		// 2) Pipe into the existing pipeline_log action so any attached SSE
		//    writer (TwinChat stream-handler, Intent stream, etc.) can mirror
		//    it to the browser. Tag the step name with `twin_debug:` so the
		//    SSE bridge can route it to the `debug` event channel.
		do_action(
			'bizcity_intent_pipeline_log',
			'twin_debug:' . $scope . '.' . $event,
			$data,
			'debug',
			$ms
		);
	}

	/**
	 * Append a TwinDebug line into site-scoped uploads daily log.
	 */
	private static function write_daily_log_line( string $line ): void {
		$uploads = function_exists( 'wp_upload_dir' )
			? wp_upload_dir( null, true, false )
			: [];

		$base_dir = '';
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$base_dir = (string) $uploads['basedir'];
		}
		if ( $base_dir === '' ) {
			$base_dir = WP_CONTENT_DIR . '/uploads';
		}

		$log_dir = trailingslashit( wp_normalize_path( $base_dir ) ) . 'bizcity-logs/twin-debug';
		if ( ! is_dir( $log_dir ) ) {
			@wp_mkdir_p( $log_dir );
			@file_put_contents( trailingslashit( $log_dir ) . '.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( trailingslashit( $log_dir ) . 'index.php', "<?php // Silence is golden.\n" );
		}

		$path = trailingslashit( $log_dir ) . 'twin-debug-' . gmdate( 'Y-m-d' ) . '.log';
		$line = sprintf( "[%s UTC] %s\n", gmdate( 'd-M-Y H:i:s' ), $line );
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Milliseconds since first `is_enabled()` call (request scope).
	 */
	public static function elapsed_ms(): float {
		if ( self::$t0 === 0.0 ) {
			return 0.0;
		}
		return round( ( microtime( true ) - self::$t0 ) * 1000, 1 );
	}

	/**
	 * For testing / admin reset.
	 */
	public static function _reset_for_tests(): void {
		self::$enabled = null;
		self::$t0      = 0.0;
	}
}
