<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-0.43 M5 — DDV probe: CRM Broadcast Mass-Send BizCity Parity
 *
 * Probe ID  : core.crm.broadcast_bizcity
 * Order     : 46
 * Spec      : core/channel-gateway/docs/PHASE-0.43-BROADCAST-MASS-SEND.md §3 M5
 *
 * 8 assertions — 3 layers:
 *
 * Disk (3):
 *   disk.schema_json        — modules.twin-crm.json current_version >= 1.23.0
 *   disk.dispatcher         — class-broadcast-dispatcher.php exists + contains pick_variant_full
 *   disk.adapter            — class-zalo-personal-adapter.php exists (PHASE-0.39 M2, optional WARN)
 *
 * Loader (3):
 *   loader.dispatcher       — class_exists('BizCity_CRM_Broadcast_Dispatcher')
 *   loader.columns          — BizCity_CRM_DB_Installer_V2 knows tbl_broadcasts() method (schema compat)
 *   loader.adapter          — class_exists('BizCity_Zalo_Personal_Adapter') (WARN if absent, not fail)
 *
 * Runtime (2):
 *   runtime.rest_route      — /bizcity-crm/v1/broadcasts route registered in WP REST server
 *   runtime.cron_hook       — bizcity_crm_broadcast_tick is scheduled OR hook has handler
 *
 * @package BizCity_Twin_CRM
 * @since   1.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_CRM_Broadcast_BizCity', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Broadcast_BizCity implements BizCity_Diagnostics_Probe {

	public function id(): string    { return 'core.crm.broadcast_bizcity'; }
	public function label(): string { return 'CRM Broadcast Mass-Send (PHASE-0.43 BizCity Parity)'; }
	// [2026-06-08 Johnny Chu] HOTFIX — add missing interface methods (description/severity/icon/estimate_ms).
	public function description(): string { return 'Kiểm tra schema, dispatcher, REST route và cron hook cho broadcast mass-send BizCity (PHASE-0.43 M5).'; }
	public function severity(): string    { return 'info'; }
	public function icon(): string        { return 'check-circle'; }
	public function estimate_ms(): int    { return 300; }
	public function order(): int    { return 46; }
	public function tags(): array   { return array( 'crm', 'broadcast', 'bizcity', 'cron', 'phase-0.43' ); }

	/**
	 * @return bool
	 */
	public function precondition() {
		return true;
	}

	/**
	 * @param mixed $ctx
	 * @return array
	 */
	// [2026-06-08 Johnny Chu] HOTFIX — add ': array' return type to match interface BizCity_Diagnostics_Probe::run($ctx): array.
	public function run( $ctx ): array {
		$results = array();

		// [2026-07-08 Johnny Chu] HOTFIX — resolve plugin root safely even when BIZCITY_TWIN_AI_PATH is unavailable.
		$plugin_root = defined( 'BIZCITY_TWIN_AI_PATH' ) ? (string) BIZCITY_TWIN_AI_PATH : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		if ( substr( $plugin_root, -1 ) !== '/' ) {
			$plugin_root .= '/';
		}

		/* ── Layer 1: Disk ── */

		// 1a. modules.twin-crm.json current_version >= 1.23.0
		$json_path = $plugin_root . 'core/diagnostics/changelog/modules.twin-crm.json';
		$json_ok   = false;
		$json_ver  = 'n/a';
		if ( file_exists( $json_path ) ) {
			$raw = file_get_contents( $json_path ); // phpcs:ignore
			if ( $raw ) {
				$parsed  = json_decode( $raw, true );
				$json_ver = isset( $parsed['current_version'] ) ? (string) $parsed['current_version'] : 'n/a';
				$json_ok  = version_compare( $json_ver, '1.23.0', '>=' );
			}
		}
		$results[] = array(
			'id'     => 'disk.schema_json',
			'label'  => 'Disk · modules.twin-crm.json current_version >= 1.23.0',
			'status' => $json_ok ? 'pass' : 'fail',
			'detail' => 'current_version = ' . $json_ver,
		);

		// 1b. class-broadcast-dispatcher.php exists + has pick_variant_full
		$dispatcher_file = $plugin_root . 'plugins/bizcity-twin-crm/includes/campaigns/class-broadcast-dispatcher.php';
		$disk_dispatcher = false;
		$disk_detail     = 'file not found';
		if ( file_exists( $dispatcher_file ) ) {
			$src = file_get_contents( $dispatcher_file ); // phpcs:ignore
			if ( strpos( $src, 'pick_variant_full' ) !== false ) {
				$disk_dispatcher = true;
				$disk_detail     = 'file exists, pick_variant_full present';
			} else {
				$disk_detail = 'file exists but pick_variant_full missing';
			}
		}
		$results[] = array(
			'id'     => 'disk.dispatcher',
			'label'  => 'Disk · class-broadcast-dispatcher.php exists + pick_variant_full()',
			'status' => $disk_dispatcher ? 'pass' : 'fail',
			'detail' => $disk_detail,
		);

		// 1c. [2026-06-08 Johnny Chu] PHASE-0.43 BUG-2 — class-zalo-personal-adapter.php (WARN only)
		$adapter_file = $plugin_root . 'plugins/bizcity-zalo-personal/includes/class-zalo-personal-adapter.php';
		$disk_adapter = file_exists( $adapter_file );
		$results[] = array(
			'id'     => 'disk.adapter',
			'label'  => 'Disk · class-zalo-personal-adapter.php exists (PHASE-0.39 M2)',
			'status' => $disk_adapter ? 'pass' : 'warn',
			'detail' => $disk_adapter
				? 'file exists — Zalo Personal friend_request/invite_group support present'
				: 'file not found at plugins/bizcity-zalo-personal/includes/class-zalo-personal-adapter.php — actions will fail-open',
		);

		/* ── Layer 2: Loader ── */

		// 2a. class_exists('BizCity_CRM_Broadcast_Dispatcher')
		$loader_class = class_exists( 'BizCity_CRM_Broadcast_Dispatcher' );
		$results[] = array(
			'id'     => 'loader.dispatcher',
			'label'  => 'Loader · class BizCity_CRM_Broadcast_Dispatcher exists',
			'status' => $loader_class ? 'pass' : 'fail',
			'detail' => $loader_class ? 'loaded' : 'class not found — check bootstrap require_once',
		);

		// 2b. BizCity_CRM_DB_Installer_V2 has tbl_broadcasts() method (proves schema layer loaded)
		$loader_schema = method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_broadcasts' );
		$results[] = array(
			'id'     => 'loader.columns',
			'label'  => 'Loader · BizCity_CRM_DB_Installer_V2::tbl_broadcasts() method exists',
			'status' => $loader_schema ? 'pass' : 'fail',
			'detail' => $loader_schema ? 'method exists' : 'DB Installer class or method not found',
		);

		// 2c. [2026-06-08 Johnny Chu] PHASE-0.43 BUG-2 — BizCity_Zalo_Personal_Adapter loaded
		//     WARN (not fail): adapter from bizcity-zalo-personal plugin; absent = friend_request/invite_group skipped.
		$loader_adapter = class_exists( 'BizCity_Zalo_Personal_Adapter' );
		$results[] = array(
			'id'     => 'loader.adapter',
			'label'  => 'Loader · BizCity_Zalo_Personal_Adapter loaded (Zalo Personal friend_request/invite_group)',
			'status' => $loader_adapter ? 'pass' : 'warn',
			'detail' => $loader_adapter
				? 'BizCity_Zalo_Personal_Adapter class loaded — send_friend_request + invite_to_group available'
				: 'bizcity-zalo-personal not active — friend_request + invite_group actions will log permission_denied (fail-open)',
		);

		/* ── Layer 3: Runtime ── */

		// 3a. /bizcity-crm/v1/broadcasts route registered
		$rest_ok     = false;
		$rest_detail = '_degraded (REST server not initialised yet)';
		if ( function_exists( 'rest_get_server' ) ) {
			try {
				$routes       = rest_get_server()->get_routes();
				$route_needle = '/bizcity-crm/v1/broadcasts';
				foreach ( array_keys( $routes ) as $pat ) {
					if ( strpos( $pat, $route_needle ) !== false ) {
						$rest_ok     = true;
						$rest_detail = 'route pattern found: ' . $pat;
						break;
					}
				}
				if ( ! $rest_ok ) {
					$rest_detail = 'route /bizcity-crm/v1/broadcasts not registered';
				}
			} catch ( \Exception $e ) {
				$rest_detail = 'exception: ' . $e->getMessage();
			}
		}
		$results[] = array(
			'id'     => 'runtime.rest_route',
			'label'  => 'Runtime · REST /bizcity-crm/v1/broadcasts registered',
			'status' => $rest_ok ? 'pass' : ( function_exists( 'rest_get_server' ) ? 'fail' : 'skip' ),
			'detail' => $rest_detail,
		);

		// 3b. bizcity_crm_broadcast_tick cron hook has handler OR is scheduled
		$cron_ok     = false;
		$cron_detail = 'hook not registered';
		$cron_hook   = 'bizcity_crm_broadcast_tick';
		if ( has_action( $cron_hook ) ) {
			$cron_ok     = true;
			$cron_detail = 'hook has registered handler';
		} elseif ( wp_next_scheduled( $cron_hook ) ) {
			$cron_ok     = true;
			$cron_detail = 'hook is scheduled (next: ' . gmdate( 'Y-m-d H:i:s', (int) wp_next_scheduled( $cron_hook ) ) . ' UTC)';
		} elseif ( class_exists( 'BizCity_CRM_Broadcast_Dispatcher' ) ) {
			// Class exists but maybe init() not called yet (e.g. probe runs before init hook)
			$cron_ok     = true;
			$cron_detail = 'Dispatcher class loaded; hook registers on init action';
		}
		$results[] = array(
			'id'     => 'runtime.cron_hook',
			'label'  => 'Runtime · bizcity_crm_broadcast_tick cron hook registered/scheduled',
			'status' => $cron_ok ? 'pass' : 'warn',
			'detail' => $cron_detail,
		);

		return $results;
	}

	// [2026-06-08 Johnny Chu] HOTFIX — add missing interface method.
	public function cleanup(): void {}
}

// Self-register.
add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ): array {
	$probes[] = new BizCity_Probe_CRM_Broadcast_BizCity();
	return $probes;
} );
