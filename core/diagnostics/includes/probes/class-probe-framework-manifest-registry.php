<?php
/**
 * DDV probe for the Phase 0.41 manifest registry and framework facade.
 *
 * The probe is request-local and read-only with respect to WordPress storage.
 * It uses the public fixture and never loads a provider or dispatches a route.
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
if ( ! class_exists( 'BizCity_Framework_Manifest_Registry', false ) ) {
	BizCity_Safe_Loader::require_file( dirname( __DIR__, 3 ) . '/twin-core/contracts/class-framework-manifest-registry.php', 'twin_core.framework_manifest_registry' );
}
if ( ! class_exists( 'BizCity_Framework_SDK', false ) ) {
	BizCity_Safe_Loader::require_file( dirname( __DIR__, 3 ) . '/twin-core/contracts/class-framework-sdk.php', 'twin_core.framework_sdk' );
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Framework_Manifest_Registry', false ) ) {
	return;
}

final class BizCity_Probe_Framework_Manifest_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.framework.manifest_registry'; }
	public function label(): string { return 'Framework manifest registry boundary'; }
	public function description(): string { return 'Kiểm tra SDK register(), idempotency, ownership collision và fail-closed policy của manifest registry.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 21; }
	public function icon(): string { return 'library'; }
	public function estimate_ms(): int { return 200; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — prove W2 registry behavior in memory without provider, route or database side effects.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$fixture_path = $root . 'core/twin-core/contracts/schema/public/v1/fixtures/extension-manifest.valid.json';
		$sample_path  = $root . 'core/twin-core/contracts/schema/public/v1/fixtures/class-framework-sample-extension.php';
		$manifest = $this->read_json( $fixture_path );
		$disk_ok = is_array( $manifest ) && is_readable( $fixture_path ) && is_file( $sample_path ) && is_readable( $sample_path );
		$steps[] = array(
			'label'  => 'Disk - manifest registry, SDK and valid fixture are readable',
			'status' => $disk_ok && class_exists( 'BizCity_Framework_Manifest_Registry', false ) && class_exists( 'BizCity_Framework_SDK', false ) ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Registry classes, extension-manifest JSON and public-SDK sample fixture are available.' : 'Manifest fixture, sample extension or registry artifact is missing.',
		);
		if ( ! $disk_ok || ! class_exists( 'BizCity_Framework_Manifest_Registry', false ) || ! class_exists( 'BizCity_Framework_SDK', false ) ) {
			return array( 'status' => 'fail', 'summary' => 'Framework manifest registry artifacts are incomplete.', 'fix_hint' => 'Load the W2 registry and SDK through the Safe Loader and rerun the focused probe.', 'steps' => $steps );
		}

		$loader_ok = method_exists( 'BizCity_Framework_SDK', 'register' ) && method_exists( 'BizCity_Framework_Manifest_Registry', 'register' ) && method_exists( 'BizCity_Framework_Manifest_Registry', 'issues' );
		$steps[] = array(
			'label'  => 'Loader - bounded SDK and registry API are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'BizCity_Framework_SDK::register() and registry catalog APIs are available.' : 'W2 public API is incomplete.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Framework manifest registry API is incomplete.', 'fix_hint' => 'Expose only the bounded W2 registration API and rerun this probe.', 'steps' => $steps );
		}
		if ( ! class_exists( 'BizCity_Framework_Sample_Extension', false ) ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W6 — load the sample extension through the guarded artifact boundary before exercising its public SDK registration.
			BizCity_Safe_Loader::require_file( $sample_path, 'twin_core.framework_sample_extension' );
		}
		$sample_loader_ok = class_exists( 'BizCity_Framework_Sample_Extension', false );
		$steps[] = array(
			'label'  => 'Loader - sample extension uses the public SDK artifact boundary',
			'status' => $sample_loader_ok ? 'pass' : 'fail',
			'detail' => $sample_loader_ok ? 'Named sample extension loaded without provider, database or internal runtime dependencies.' : 'Sample extension fixture did not load through the Safe Loader.',
		);
		if ( ! $sample_loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Framework sample extension did not load.', 'fix_hint' => 'Keep the sample extension contract-only and load it through the Safe Loader.', 'steps' => $steps );
		}

		BizCity_Framework_Manifest_Registry::reset();
		$first = BizCity_Framework_Sample_Extension::register( $manifest );
		$services = $first instanceof BizCity_Framework_Handle ? $first->services() : array();
		$service_ok = array( 'contracts', 'channels', 'context', 'brain', 'actions' ) === $services
			&& false === $first->service( 'provider_tokens' );
		$steps[] = array(
			'label'  => 'Runtime - register returns exactly five bounded services',
			'status' => $service_ok ? 'pass' : 'fail',
			'detail' => $service_ok ? implode( ', ', $services ) : 'Unexpected service surface or provider-token service exposed.',
		);

		$forbidden_fields = array( 'wpdb', 'path', 'file_path', 'provider_token', 'provider_tokens', 'secret', 'api_key' );
		$service_handle_ok = $first instanceof BizCity_Framework_Handle;
		if ( $service_handle_ok ) {
			foreach ( $services as $service_name ) {
				$service_handle = $first->service_handle( $service_name );
				$descriptor = $service_handle instanceof BizCity_Framework_Service_Handle ? $service_handle->describe() : array();
				$service_handle_ok = $service_handle_ok
					&& $service_handle instanceof BizCity_Framework_Service_Handle
					&& $service_name === $service_handle->name()
					&& $service_name === (string) ( $descriptor['name'] ?? '' )
					&& empty( array_intersect( $forbidden_fields, array_keys( $descriptor ) ) );
			}
			$service_handle_ok = $service_handle_ok && false === $first->service_handle( 'provider_tokens' );
		}
		$steps[] = array(
			'label'  => 'Runtime - five service handles expose contract-only descriptors',
			'status' => $service_handle_ok ? 'pass' : 'fail',
			'detail' => $service_handle_ok ? 'Each bounded service has a public descriptor and no storage, path or credential field.' : 'A bounded service handle is missing or exposes an internal field.',
		);

		$channel = BizCity_Framework_Manifest_Registry::channel( 'zalo_bot' );
		$descriptor_ok = is_array( $channel )
			&& 'admin' === (string) ( $channel['zone'] ?? '' )
			&& 'enabled' === (string) ( $channel['crm_policy'] ?? '' )
			&& 'user_bound' === (string) ( $channel['brain_policy'] ?? '' )
			&& 'channel-diagnostics-record@1.x' === (string) ( $channel['log_contract'] ?? '' )
			&& in_array( 'admin', (array) ( $channel['surface_policy'] ?? array() ), true );
		$steps[] = array(
			'label'  => 'Runtime - exact manifest derives channel policy descriptors',
			'status' => $descriptor_ok ? 'pass' : 'fail',
			'detail' => $descriptor_ok ? 'Zone, CRM, Brain, surface and operational log policy came from the registered manifest.' : 'Manifest-derived channel descriptor is incomplete.',
		);

		$replay = BizCity_Framework_SDK::register( $manifest, new stdClass() );
		$idempotent_ok = $replay instanceof BizCity_Framework_Handle && count( BizCity_Framework_Manifest_Registry::all() ) === 1;
		$steps[] = array(
			'label'  => 'Runtime - identical manifest registration is idempotent',
			'status' => $idempotent_ok ? 'pass' : 'fail',
			'detail' => $idempotent_ok ? 'One extension remains registered after identical replay.' : 'Identical replay changed registry state.',
		);
		$sample_runtime_ok = $first instanceof BizCity_Framework_Handle
			&& $first->extension() instanceof BizCity_Framework_Sample_Extension
			&& count( BizCity_Framework_Manifest_Registry::all() ) === 1;
		$steps[] = array(
			'label'  => 'Runtime - sample extension registers without a core allowlist edit',
			'status' => $sample_runtime_ok ? 'pass' : 'fail',
			'detail' => $sample_runtime_ok ? 'The sample channel manifest was discovered and registered through the public SDK only.' : 'Sample extension registration did not produce the expected bounded handle.',
		);

		$extension_conflict = $manifest;
		$extension_conflict['extension_version'] = '1.0.1';
		$extension_conflict_ok = false === BizCity_Framework_SDK::register( $extension_conflict, new stdClass() );
		$channel_conflict = $manifest;
		$channel_conflict['extension_id'] = 'vendor.other-extension';
		$channel_conflict_ok = false === BizCity_Framework_SDK::register( $channel_conflict, new stdClass() );
		$unsupported = $manifest;
		$unsupported['extension_id'] = 'vendor.unsupported';
		$unsupported['capabilities'][] = 'brain.read_private_everywhere';
		$unsupported_ok = false === BizCity_Framework_SDK::register( $unsupported, new stdClass() );
		$fail_closed_ok = $extension_conflict_ok && $channel_conflict_ok && $unsupported_ok;
		$steps[] = array(
			'label'  => 'Runtime - extension/channel/capability conflicts fail closed',
			'status' => $fail_closed_ok ? 'pass' : 'fail',
			'detail' => $fail_closed_ok ? 'Different extension ownership, channel collision and unsupported capability were rejected.' : 'A manifest conflict was accepted.',
		);

		return array(
			'status'  => $service_ok && $service_handle_ok && $descriptor_ok && $idempotent_ok && $sample_runtime_ok && $fail_closed_ok ? 'pass' : 'fail',
			'summary' => $service_ok && $service_handle_ok && $descriptor_ok && $idempotent_ok && $sample_runtime_ok && $fail_closed_ok ? 'Framework manifest registry passed.' : 'Framework manifest registry has runtime failures.',
			'fix_hint'=> $service_ok && $service_handle_ok && $descriptor_ok && $idempotent_ok && $sample_runtime_ok && $fail_closed_ok ? '' : 'Preserve immutable ownership, contract-only service handles, sample registration, manifest-derived channel policy and fail-closed validation.',
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
	$probes[] = 'BizCity_Probe_Framework_Manifest_Registry';
	return $probes;
} );
