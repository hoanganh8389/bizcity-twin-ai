<?php
/**
 * DDV probe for current CRM channel manifest registration.
 *
 * Verifies only the policy/adapter boundary. Provider credentials and network
 * transport are deliberately outside this probe.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Channel_Manifest_Registration', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Manifest_Registration implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.manifest_registration'; }
	public function label(): string { return 'Current channel manifest registration'; }
	public function description(): string { return 'Kiểm tra bốn manifest CRM hiện tại khớp adapter code, zone, identity và CRM policy.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 22; }
	public function icon(): string { return 'route'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — prove current-channel manifest/adapter parity without provider transport or business writes.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$manifest_file = $root . 'plugins/bizcity-twin-crm/manifests/builtin-channel-manifests.json';
		$manifests = $this->read_json( $manifest_file );
		$expected = array( 'zalo_bot', 'zalo_oa', 'zalo_personal', 'facebook' );
		$actual = array();
		foreach ( (array) $manifests as $manifest ) {
			foreach ( (array) ( $manifest['channels'] ?? array() ) as $channel ) {
				if ( is_array( $channel ) && isset( $channel['slug'] ) ) {
					$actual[] = (string) $channel['slug'];
				}
			}
		}
		sort( $actual );
		$expected_sorted = $expected;
		sort( $expected_sorted );
		$disk_ok = is_array( $manifests ) && $expected_sorted === $actual;
		$steps[] = array(
			'label'  => 'Disk - current channel manifest package is complete',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Four exact current channel manifests are readable.' : 'Manifest package is missing or has an unexpected channel set.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Current channel manifest package is incomplete.', 'fix_hint' => 'Restore the four exact channel manifests and rerun this focused probe.', 'steps' => $steps );
		}

		$registry_ok = class_exists( 'BizCity_Framework_Manifest_Registry' ) && count( BizCity_Framework_Manifest_Registry::channels() ) >= 4;
		$steps[] = array(
			'label'  => 'Loader - current manifests are registered in the framework catalog',
			'status' => $registry_ok ? 'pass' : 'fail',
			'detail' => $registry_ok ? 'Framework registry exposes the current channel slugs.' : 'Current channel manifests were not registered.',
		);
		if ( ! $registry_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Current channel manifests are not loaded.', 'fix_hint' => 'Load CRM built-in manifest registration after framework SDK bootstrap and rerun this probe.', 'steps' => $steps );
		}

		$parity_ok = true;
		$parity = array();
		foreach ( $expected as $slug ) {
			$descriptor = BizCity_Framework_Manifest_Registry::channel( $slug );
			$adapter = class_exists( 'BizCity_CRM_Channel_Registry' ) ? BizCity_CRM_Channel_Registry::get( $slug ) : null;
			$adapter_ok = is_object( $adapter ) && method_exists( $adapter, 'code' ) && $slug === (string) $adapter->code();
			$policy_ok = is_array( $descriptor )
				&& isset( $descriptor['zone'], $descriptor['identity_policy'], $descriptor['crm_policy'], $descriptor['brain_policy'], $descriptor['context_policy'] );
			$parity[ $slug ] = $adapter_ok && $policy_ok;
			if ( ! $parity[ $slug ] ) {
				$parity_ok = false;
			}
		}
		$steps[] = array(
			'label'  => 'Runtime - manifest slug matches adapter code and policy fields',
			'status' => $parity_ok ? 'pass' : 'fail',
			'detail' => $parity_ok ? 'zalo_bot, zalo_oa, zalo_personal and facebook match exact adapter and policy descriptors.' : wp_json_encode( $parity ),
		);

		return array(
			'status'  => $parity_ok ? 'pass' : 'fail',
			'summary' => $parity_ok ? 'Current CRM channel manifest registration passed.' : 'Current CRM channel manifest registration has parity failures.',
			'fix_hint'=> $parity_ok ? '' : 'Keep manifest slug equal to adapter code and resolve each channel with explicit zone and identity policy.',
			'steps'   => $steps,
		);
	}

	private function read_json( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		try {
			$value = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			return is_array( $value ) ? $value : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Channel_Manifest_Registration';
	return $probes;
} );
