<?php
/**
 * BizCity Diagnostics — modules.personal.profile Wave 5 demo-fix probe.
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
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) || class_exists( 'BizCity_Probe_Personal_Profile_Wave5', false ) ) {
	return;
}

final class BizCity_Probe_Personal_Profile_Wave5 implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.personal.profile.wave5'; }
	public function label(): string { return 'Profile · Wave 5 Demo Fixes'; }
	public function description(): string { return 'Disk / Loader / Runtime: CF7 auto-contact bridge, slug rename, connected-channel accounts route, and FloatChat-by-default template defaults.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 85; }
	public function icon(): string { return 'id-card'; }
	public function estimate_ms(): int { return 30; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 evidence, read-only (no CF7/DB mutation).
		$steps = array();
		$pass  = true;
		$plugin_root = defined( 'BIZCITY_PERSONAL_DIR' ) ? BIZCITY_PERSONAL_DIR : dirname( __DIR__, 2 ) . '/';

		$bridge_file = $plugin_root . 'includes/profile/class-personal-profile-bzpb-bridge.php';
		$rest_file   = $plugin_root . 'includes/profile/class-personal-profile-rest.php';
		$disk_ok = is_readable( $bridge_file ) && is_readable( $rest_file );
		$steps[] = array(
			'label'  => 'Disk · Wave 5 bridge and REST files readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Bridge and REST controller files are present.' : 'Bridge or REST controller file is missing.',
		);
		if ( ! $disk_ok ) { $pass = false; }

		$loader_ok = class_exists( 'BizCity_Personal_Profile_BZPB_Bridge' )
			&& method_exists( 'BizCity_Personal_Profile_BZPB_Bridge', 'ensure_contact_form' )
			&& method_exists( 'BizCity_Personal_Profile_BZPB_Bridge', 'rename_published_page' )
			&& method_exists( 'BizCity_Personal_Profile_BZPB_Bridge', 'get_publish_state' )
			&& class_exists( 'BizCity_Personal_Profile_REST' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'channel_accounts' );
		$steps[] = array(
			'label'  => 'Loader · CF7 auto-contact, slug rename, channel-accounts methods',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Bridge and REST classes expose the Wave 5 methods.' : 'One or more Wave 5 methods are missing from the loaded classes.',
		);
		if ( ! $loader_ok ) { $pass = false; }

		$route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$route_ok = isset( $routes['/bizcity-profile/v1/profile/cards/(?P<id>\\d+)/content'] )
				&& isset( $routes['/bizcity-profile/v1/profile/channel-accounts'] );
		}
		$steps[] = array(
			'label'  => 'Loader · Wave 5 REST routes registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'content and channel-accounts routes are registered.' : 'One or more Wave 5 routes are missing from the REST server.',
		);
		if ( ! $route_ok ) { $pass = false; }

		$template_ok = true;
		$template_detail = array();
		foreach ( array( 'business-card-compact', 'business-card-full' ) as $template_key ) {
			$template_file = $plugin_root . 'includes/profile/templates/' . $template_key . '.json';
			$config = is_readable( $template_file ) ? json_decode( (string) file_get_contents( $template_file ), true ) : null;
			$has_float = false;
			foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
				if ( 'profile-card' !== (string) ( $block['type'] ?? '' ) ) { continue; }
				foreach ( is_array( $block['props']['chatEntrypoints'] ?? null ) ? $block['props']['chatEntrypoints'] : array() as $entry ) {
					if ( 'webchat' === (string) ( $entry['channelCode'] ?? '' ) && ! empty( $entry['enabled'] ) && 'profile_float' === (string) ( $entry['presentation'] ?? '' ) ) {
						$has_float = true;
					}
				}
			}
			if ( ! $has_float ) { $template_ok = false; $template_detail[] = $template_key; }
		}
		$steps[] = array(
			'label'  => 'Runtime · templates default to a fixed bottom-right FloatChat',
			'status' => $template_ok ? 'pass' : 'fail',
			'detail' => $template_ok ? 'Both built-in templates enable webchat/profile_float by default.' : 'Missing default FloatChat entrypoint in: ' . implode( ', ', $template_detail ),
		);
		if ( ! $template_ok ) { $pass = false; }

		$cf7_active = class_exists( 'WPCF7_ContactForm' );
		$steps[] = array(
			'label'  => 'Runtime · Contact Form 7 availability (informational)',
			'status' => $cf7_active ? 'pass' : 'warn',
			'detail' => $cf7_active ? 'CF7 is active; ensure_contact_form() can detect or create a form.' : 'CF7 is not active on this site; new Profile cards will skip auto contact-form attachment.',
		);

		if ( method_exists( $ctx, 'emit_step' ) ) {
			foreach ( $steps as $step ) { $ctx->emit_step( $step ); }
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Wave 5 demo fixes are wired and loadable.' : 'One or more Wave 5 demo fixes are incomplete.',
			'error'    => $pass ? '' : 'personal_profile_wave5_incomplete',
			'fix_hint' => $pass ? '' : 'Kiểm tra lại bridge/REST methods, route registration, và default chatEntrypoints trong 2 template JSON.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void { /* Read-only. */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Personal_Profile_Wave5';
	return $list;
} );
