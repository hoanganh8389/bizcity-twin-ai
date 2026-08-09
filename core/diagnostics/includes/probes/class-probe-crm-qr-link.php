<?php
/**
 * [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — DDV for option-backed CRM QR Links.
 *
 * Probe ID: core.crm.qr_link
 * Layers: Disk changelog/source, Loader repository/option contract, Runtime REST routes.
 *
 * @package BizCity_Twin_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_probe_interface = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_probe_interface ) ) {
		require_once $_probe_interface;
	}
	unset( $_probe_interface );
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_CRM_QR_Link', false ) ) {
	return;
}

// [2026-08-01 Johnny Chu] HOTFIX — implement the mandatory probe cleanup contract to prevent PHP 7.4 fatal on class declaration.
final class BizCity_Probe_CRM_QR_Link implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.qr_link'; }
	public function label(): string { return 'CRM QR Link (PHASE-CG-QR-LINK)'; }
	public function description(): string { return 'Kiểm tra schema, loader và REST routes cho QR Link custom URL/Page.'; }
	public function severity(): string { return 'info'; }
	public function icon(): string { return 'qr-code'; }
	public function estimate_ms(): int { return 250; }
	public function order(): int { return 47; }
	public function tags(): array { return array( 'crm', 'qr-link', 'phase-cg-qr-link' ); }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		$results = array();
		$plugin_root = defined( 'BIZCITY_TWIN_AI_PATH' )
			? (string) BIZCITY_TWIN_AI_PATH
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		if ( substr( $plugin_root, -1 ) !== '/' ) {
			$plugin_root .= '/';
		}

		$json_path = $plugin_root . 'core/diagnostics/changelog/modules.twin-crm.json';
		$schema_ok = false;
		$schema_version = 'n/a';
		if ( file_exists( $json_path ) ) {
			$parsed = json_decode( (string) file_get_contents( $json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$schema_version = isset( $parsed['current_version'] ) ? (string) $parsed['current_version'] : 'n/a';
			$schema_ok = version_compare( $schema_version, '1.25.0', '>=' )
				&& empty( $parsed['tables']['bizcity_crm_qr_links'] );
		}
		$results[] = array(
			'id' => 'disk.schema',
			'label' => 'Disk · QR Link does not add a CRM table',
			'status' => $schema_ok ? 'pass' : 'fail',
			'detail' => 'current_version = ' . $schema_version,
		);

		$repo_file = $plugin_root . 'plugins/bizcity-twin-crm/includes/campaigns/class-qr-link-repository.php';
		$rest_file = $plugin_root . 'plugins/bizcity-twin-crm/includes/class-rest-controller.php';
		$disk_ok = file_exists( $repo_file ) && file_exists( $rest_file );
		$results[] = array(
			'id' => 'disk.source',
			'label' => 'Disk · QR Link repository and REST controller exist',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'source files found' : 'one or more source files missing',
		);

		$loader_ok = class_exists( 'BizCity_CRM_QR_Link_Repository' )
			&& method_exists( 'BizCity_CRM_QR_Link_Repository', 'list' )
			&& defined( 'BizCity_CRM_QR_Link_Repository::OPTION_KEY' );
		$results[] = array(
			'id' => 'loader.repository',
			'label' => 'Loader · QR Link repository and option contract loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'classes and methods available' : 'repository or installer helper not loaded',
		);

		$route_ok = false;
		$route_detail = 'REST server not initialised';
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			foreach ( array_keys( $routes ) as $route ) {
				if ( strpos( $route, '/bizcity-crm/v1/qr-links' ) !== false ) {
					$route_ok = true;
					$route_detail = 'route pattern found: ' . $route;
					break;
				}
			}
			if ( ! $route_ok ) {
				$route_detail = 'QR Link route pattern not registered';
			}
		}
		$results[] = array(
			'id' => 'runtime.rest_route',
			'label' => 'Runtime · /bizcity-crm/v1/qr-links route registered',
			'status' => $route_ok ? 'pass' : 'skip',
			'detail' => $route_detail,
		);

		return $results;
	}

	public function cleanup(): void {
		// [2026-08-01 Johnny Chu] HOTFIX — read-only probe creates no artifacts to remove.
	}
}