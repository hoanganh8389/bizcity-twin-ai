<?php
/**
 * BizCity Diagnostics — modules.personal.profile foundation probe.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

$probe_iface = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR . 'core/diagnostics/includes/interface-diagnostics-probe.php'
	: dirname( __DIR__, 4 ) . '/core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $probe_iface ) ) {
	require_once $probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) || class_exists( 'BizCity_Probe_Personal_Profile', false ) ) {
	return;
}

final class BizCity_Probe_Personal_Profile implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.personal.profile'; }
	public function label(): string { return 'Profile · REST and Schema Foundation'; }
	public function description(): string { return 'Disk / Loader / Runtime: canonical Profile REST namespace, installer version, and Profile tables.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 84; }
	public function icon(): string { return 'id-card'; }
	public function estimate_ms(): int { return 30; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — validate the foundation without mutating tenant data.
		$steps = array();
		$pass  = true;
		$plugin_root = defined( 'BIZCITY_PERSONAL_DIR' ) ? BIZCITY_PERSONAL_DIR : dirname( __DIR__, 2 ) . '/';
		$installer_file = $plugin_root . 'includes/class-personal-installer.php';
		$disk_ok = is_readable( $installer_file );
		$steps[] = array(
			'label' => 'Disk · Profile installer readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Shared Personal installer is present.' : 'Shared Personal installer is missing.',
		);
		if ( ! $disk_ok ) { $pass = false; }

		$schema_version_ok = class_exists( 'BizCity_Personal_Installer' )
			&& defined( 'BizCity_Personal_Installer::SCHEMA_VERSION' )
			&& version_compare( BizCity_Personal_Installer::SCHEMA_VERSION, '1.5.1', '>=' );
		$loader_missing = array();
		if ( ! $schema_version_ok ) { $loader_missing[] = 'schema>=' . '1.5.1'; }
		foreach ( array( 'BizCity_Personal_Profile_REST', 'BizCity_Personal_Profile_Analytics', 'BizCity_Personal_Profile_Channel_Resolver', 'BizCity_Personal_Profile_Chat_Handler', 'BizCity_TwinBrain_Adapter_WebChat' ) as $required_class ) {
			if ( ! class_exists( $required_class ) ) { $loader_missing[] = $required_class; }
		}
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) && ! method_exists( 'BizCity_Personal_Profile_REST', 'register_routes' ) ) { $loader_missing[] = 'BizCity_Personal_Profile_REST::register_routes'; }
		$loader_ok = empty( $loader_missing );
		$steps[] = array(
			'label' => 'Loader · Profile classes and schema version',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Shared installer ' . BizCity_Personal_Installer::SCHEMA_VERSION . ', Profile REST, and WebChat TwinBrain adapter are loaded.' : 'Missing loader contracts: ' . implode( ', ', $loader_missing ),
		);
		if ( ! $loader_ok ) { $pass = false; }

		$route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$route_ok = isset( $routes['/bizcity-profile/v1/profile/cards'] )
				&& isset( $routes['/bizcity-profile/v1/profile/track'] )
				&& isset( $routes['/bizcity-profile/v1/profile/cards/(?P<id>\\d+)/channel-context'] )
				&& isset( $routes['/bizcity-profile/v1/profile/chat/turn'] );
		}
		$steps[] = array(
			'label' => 'Loader · canonical Profile REST routes',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'Cards, track, channel-context, and chat turn routes are registered.' : 'One or more canonical Profile routes are missing.',
		);
		if ( ! $route_ok ) { $pass = false; }

		global $wpdb;
		$tables = array(
			$wpdb->prefix . 'bizcity_personal_profile_cards',
			$wpdb->prefix . 'bizcity_personal_profile_qrcodes',
			$wpdb->prefix . 'bizcity_personal_profile_analytics_events',
		);
		$missing = array();
		foreach ( $tables as $table ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
			if ( 1 !== $found ) { $missing[] = $table; }
		}
		$runtime_ok = empty( $missing );
		$steps[] = array(
			'label' => 'Runtime · Profile tables on current blog/shard',
			'status' => $runtime_ok ? 'pass' : 'warn',
			'detail' => $runtime_ok ? 'All three Profile tables exist.' : 'Missing tables: ' . implode( ', ', $missing ),
		);
		if ( method_exists( $ctx, 'emit_step' ) ) {
			foreach ( $steps as $step ) { $ctx->emit_step( $step ); }
		}

		return array(
			'status' => $pass ? ( $runtime_ok ? 'pass' : 'warn' ) : 'fail',
			'summary' => $pass ? ( $runtime_ok ? 'Profile foundation is ready.' : 'Profile code is loaded; schema still needs provisioning.' ) : 'Profile foundation is incomplete.',
			'error' => $pass ? '' : 'personal_profile_foundation_failed',
			'fix_hint' => $pass && $runtime_ok ? '' : 'Chạy Site Provisioner/Diagnostics trên đúng blog và shard; không bypass router hoặc dùng current DB fallback.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void { /* Read-only. */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Personal_Profile';
	return $list;
} );
