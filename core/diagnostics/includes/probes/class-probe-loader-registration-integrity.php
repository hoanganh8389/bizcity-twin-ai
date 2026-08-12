<?php
/**
 * Diagnostics probe: PHASE-1.23 hook/route/cron registration integrity.
 *
 * Read-only audit. REST route evidence is skipped when the REST server is not
 * available in the current request; this avoids treating an admin HTML request
 * as proof that REST routes are missing.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Loader_Registration_Integrity', false ) ) {
	return;
}

final class BizCity_Probe_Loader_Registration_Integrity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.loader.registration_integrity'; }
	public function label(): string { return 'PHASE-1.23 hook/route/cron registration integrity'; }
	public function description(): string {
		return 'Audits duplicate hook registrations, available REST routes and cron schedule visibility without changing registration.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 19; }
	public function icon(): string { return 'list-checks'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return new WP_Error( 'loader_registration_hooks_missing', 'WordPress hook registry chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W3 - inspect registration
		// evidence only; no hooks, routes or schedules are modified.
		global $wp_filter;
		$duplicate_hooks = array();
		$hook_keys = array();
		$hook_count = 0;
		$callback_count = 0;
		foreach ( (array) $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}
			$hook_count++;
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback_data ) {
					$callback_count++;
					$callback = isset( $callback_data['function'] ) ? $callback_data['function'] : null;
					$key = self::callback_key( (string) $hook_name, (string) $priority, $callback );
					if ( isset( $hook_keys[ $key ] ) ) {
						$duplicate_hooks[] = $key;
					} else {
						$hook_keys[ $key ] = true;
					}
				}
			}
		}

		$routes = array();
		$route_status = 'skip';
		$route_duplicates = array();
		if ( function_exists( 'rest_get_server' ) ) {
			$server = rest_get_server();
			if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
				$route_status = 'pass';
				$routes = (array) $server->get_routes();
				$route_keys = array();
				foreach ( $routes as $route => $handlers ) {
					foreach ( (array) $handlers as $handler ) {
						$callback = is_array( $handler ) && isset( $handler['callback'] ) ? $handler['callback'] : null;
							$methods = is_array( $handler ) && isset( $handler['methods'] )
								? ( is_array( $handler['methods'] ) ? implode( ',', array_keys( $handler['methods'] ) ) : (string) $handler['methods'] )
								: '';
							$key = (string) $route . '|' . $methods . '|' . self::callback_key( '', '', $callback );
						if ( isset( $route_keys[ $key ] ) ) {
							$route_duplicates[] = $key;
						} else {
							$route_keys[ $key ] = true;
						}
					}
				}
			}
		}

		$schedules = function_exists( 'wp_get_schedules' ) ? (array) wp_get_schedules() : array();
		$required_schedules = array( 'bizcity_twinweb_artifact_jobs_minute', 'bizcity_crm_3min', 'bizcity_tier_1min', 'bizcity_tier_5min', 'bizcity_tier_10min' );
		$missing_schedules = array_values( array_diff( $required_schedules, array_keys( $schedules ) ) );

		$ctx->emit_step( array(
			'label'  => 'Runtime · hook registration identity',
			'status' => empty( $duplicate_hooks ) ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'hooks'             => $hook_count,
				'callbacks'         => $callback_count,
				'duplicate_hooks'   => array_slice( $duplicate_hooks, 0, 20 ),
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · REST route registration identity',
			'status' => empty( $route_duplicates ) ? $route_status : 'fail',
			'detail' => self::json_detail( array(
				'status'            => $route_status,
				'route_count'       => count( $routes ),
				'duplicate_routes'  => array_slice( $route_duplicates, 0, 20 ),
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · required cron schedules visible',
			'status' => empty( $missing_schedules ) ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'schedule_count' => count( $schedules ),
				'missing'        => $missing_schedules,
			) ),
		) );

		$pass = empty( $duplicate_hooks ) && empty( $route_duplicates ) && empty( $missing_schedules );
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Hook, available REST route and required cron registration evidence is stable.'
				: 'Duplicate or missing registration evidence detected; inspect steps before changing loader timing.',
			'fix_hint' => $pass ? '' : 'Compare registration owner, lifecycle phase and loader combination before removing or deferring a callback.',
		);
	}

	private static function callback_key( string $hook, string $priority, $callback ): string {
		$label = '';
		if ( is_string( $callback ) ) {
			$label = $callback;
		} elseif ( is_array( $callback ) ) {
			$owner = isset( $callback[0] )
				? ( is_object( $callback[0] ) ? 'object:' . spl_object_hash( $callback[0] ) : (string) $callback[0] )
				: '';
			$method = isset( $callback[1] ) ? (string) $callback[1] : '';
			$label = $owner . '::' . $method;
		} elseif ( is_object( $callback ) ) {
			$label = 'object:' . spl_object_hash( $callback ) . ':' . get_class( $callback );
		} else {
			$label = gettype( $callback );
		}
		return $hook . '|' . $priority . '|' . $label;
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_Loader_Registration_Integrity';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Registration_Integrity', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Registration_Integrity', 'register' ) );
}
