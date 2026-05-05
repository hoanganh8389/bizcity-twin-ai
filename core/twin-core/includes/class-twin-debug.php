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
 *   • `Debug::trace()` writes a line to PHP error_log (tail with `tail -f`).
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

		$ms      = self::elapsed_ms();
		$payload = [ 'scope' => $scope, 'event' => $event ] + $data;

		// 1) error_log line — compact one-liner so tail -f stays readable.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			$json = '[unencodable]';
		}
		error_log( sprintf( '[TwinDebug %6.0fms] %s.%s %s', $ms, $scope, $event, $json ) );

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
