<?php
/**
 * BizCity Twin AI — Deprecation helper (Phase 0.99.3).
 *
 * Lightweight notifier for renamed / removed public APIs. Designed to
 * help 3rd-party sub-plugin authors detect framework drift early without
 * breaking production sites.
 *
 * Usage
 * -----
 *   BizCity_Deprecation::notify(
 *       'BizCity_Old_Class::old_method()',
 *       'BizCity_New_Class::new_method()',
 *       '1.0.0'
 *   );
 *
 *   BizCity_Deprecation::notify_filter(
 *       'old_filter_name',
 *       'bizcity_new_filter',
 *       '1.0.0'
 *   );
 *
 * Output
 * ------
 *   - `error_log()` warning when `WP_DEBUG=true` (developer feedback).
 *   - `do_action( 'bizcity_deprecation_notice', $payload )` for monitors.
 *   - SILENT in production (no E_USER_DEPRECATED to avoid breaking sites).
 *
 * Silencing
 * ---------
 *   add_filter( 'bizcity_deprecation_silent', '__return_true' );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      1.0.0  (Phase 0.99.3 — 2026-06-01)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Deprecation' ) ) {

	final class BizCity_Deprecation {

		/** @var array<string,bool> seen tokens (de-dupe within request) */
		private static $seen = [];

		/**
		 * Report a deprecated class / method / function call.
		 *
		 * @param string      $old        e.g. `BizCity_Router_Proxy::generate_image()`
		 * @param string|null $new        Replacement, or null if removed.
		 * @param string      $since      Framework version that deprecated it.
		 * @param string|null $reason     Optional human-readable explanation.
		 */
		public static function notify( $old, $new = null, $since = '1.0.0', $reason = null ) {
			$token = 'symbol:' . $old;
			if ( isset( self::$seen[ $token ] ) ) {
				return;
			}
			self::$seen[ $token ] = true;

			$payload = [
				'kind'   => 'symbol',
				'old'    => (string) $old,
				'new'    => $new !== null ? (string) $new : '',
				'since'  => (string) $since,
				'reason' => (string) ( $reason ?? '' ),
				'caller' => self::caller_summary(),
			];

			self::dispatch( $payload );
		}

		/**
		 * Report a deprecated filter / action hook.
		 *
		 * @param string      $old_hook  Old hook name.
		 * @param string|null $new_hook  Replacement hook name, or null if removed.
		 * @param string      $since
		 * @param string|null $reason
		 */
		public static function notify_filter( $old_hook, $new_hook = null, $since = '1.0.0', $reason = null ) {
			$token = 'hook:' . $old_hook;
			if ( isset( self::$seen[ $token ] ) ) {
				return;
			}
			self::$seen[ $token ] = true;

			$payload = [
				'kind'   => 'hook',
				'old'    => (string) $old_hook,
				'new'    => $new_hook !== null ? (string) $new_hook : '',
				'since'  => (string) $since,
				'reason' => (string) ( $reason ?? '' ),
				'caller' => self::caller_summary(),
			];

			self::dispatch( $payload );
		}

		/**
		 * Report a deprecated option / constant.
		 *
		 * @param string $kind  `option` | `constant` | `transient`.
		 * @param string $old
		 * @param string|null $new
		 * @param string $since
		 */
		public static function notify_storage( $kind, $old, $new = null, $since = '1.0.0' ) {
			$token = $kind . ':' . $old;
			if ( isset( self::$seen[ $token ] ) ) {
				return;
			}
			self::$seen[ $token ] = true;

			self::dispatch( [
				'kind'   => (string) $kind,
				'old'    => (string) $old,
				'new'    => $new !== null ? (string) $new : '',
				'since'  => (string) $since,
				'reason' => '',
				'caller' => self::caller_summary(),
			] );
		}

		/* ── Internals ──────────────────────────────────────────────── */

		/**
		 * @param array<string,string> $payload
		 */
		private static function dispatch( array $payload ) {
			/**
			 * Filter — silence all deprecation notices (production sites).
			 *
			 * @since 1.0.0
			 *
			 * @param bool                 $silent  Default false (notices ON).
			 * @param array<string,string> $payload Notice payload.
			 */
			$silent = (bool) apply_filters( 'bizcity_deprecation_silent', false, $payload );
			if ( $silent ) {
				return;
			}

			/**
			 * Action — observers (monitors, error-reporter) can subscribe.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,string> $payload kind, old, new, since, reason, caller.
			 */
			do_action( 'bizcity_deprecation_notice', $payload );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$msg = sprintf(
					'[BizCity Deprecation] %s `%s` is deprecated since %s%s%s',
					$payload['kind'],
					$payload['old'],
					$payload['since'],
					$payload['new'] !== '' ? ' — use `' . $payload['new'] . '`' : ' (removed)',
					$payload['caller'] !== '' ? ' · called from ' . $payload['caller'] : ''
				);
				if ( $payload['reason'] !== '' ) {
					$msg .= ' · ' . $payload['reason'];
				}
				error_log( $msg );
			}
		}

		/**
		 * Identify caller file:line, skipping our own frames.
		 *
		 * @return string e.g. `plugins/foo/bar.php:123`
		 */
		private static function caller_summary() {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
			foreach ( $trace as $frame ) {
				if ( ! isset( $frame['file'] ) ) {
					continue;
				}
				if ( strpos( $frame['file'], 'helpers-deprecation.php' ) !== false ) {
					continue;
				}
				$file = str_replace( '\\', '/', $frame['file'] );
				$base = defined( 'WP_PLUGIN_DIR' )
					? str_replace( '\\', '/', WP_PLUGIN_DIR )
					: '';
				if ( $base !== '' && strpos( $file, $base ) === 0 ) {
					$file = ltrim( substr( $file, strlen( $base ) ), '/' );
				}
				return $file . ':' . ( isset( $frame['line'] ) ? (int) $frame['line'] : 0 );
			}
			return '';
		}
	}
}
