<?php
/**
 * DDV probe for W3.5 manifest versus legacy CRM contract compatibility.
 *
 * This probe exercises only normalized envelopes and policy boundaries. It does
 * not call a provider, create CRM rows or replace the legacy adapter bridge.
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
if ( class_exists( 'BizCity_Probe_Channel_Manifest_Compat', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Manifest_Compat implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.channel.manifest_compat'; }
	public function label(): string { return 'Channel manifest compatibility bridge'; }
	public function description(): string { return 'Kiểm tra manifest và adapter CRM dùng cùng channel code, normalized envelope và zone policy; không gọi provider.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 23; }
	public function icon(): string { return 'git-compare-arrows'; }
	public function estimate_ms(): int { return 300; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — prove manifest/legacy CRM parity with synthetic normalized envelopes before any allowlist cutover.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$manifest_file = $root . 'plugins/bizcity-twin-crm/manifests/builtin-channel-manifests.json';
		$manifests = $this->read_json( $manifest_file );
		$expected = array( 'zalo_bot', 'zalo_oa', 'zalo_personal', 'facebook' );
		$manifest_channels = array();
		foreach ( (array) $manifests as $manifest ) {
			foreach ( (array) ( $manifest['channels'] ?? array() ) as $channel ) {
				if ( is_array( $channel ) && isset( $channel['slug'] ) ) {
					$manifest_channels[ (string) $channel['slug'] ] = $channel;
				}
			}
		}
		$disk_ok = is_array( $manifests ) && count( $manifest_channels ) === count( $expected ) && ! array_diff( $expected, array_keys( $manifest_channels ) );
		$steps[] = array(
			'label'  => 'Disk - current manifest set is complete',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'zalo_bot, zalo_oa, zalo_personal and facebook are present.' : 'Manifest package is missing an expected current channel.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Current channel manifest package is incomplete.', 'fix_hint' => 'Restore the four current channel manifests and rerun this probe.', 'steps' => $steps );
		}

		$registry_ok = class_exists( 'BizCity_Framework_Manifest_Registry' ) && count( BizCity_Framework_Manifest_Registry::channels() ) >= count( $expected );
		$steps[] = array(
			'label'  => 'Loader - manifest registry exposes current channels',
			'status' => $registry_ok ? 'pass' : 'fail',
			'detail' => $registry_ok ? 'All current manifest channels are registered.' : 'Current manifest channels are not registered in the framework catalog.',
		);
		if ( ! $registry_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Current channel manifests are not registered.', 'fix_hint' => 'Register CRM manifests after framework and adapter bootstrap, then rerun this probe.', 'steps' => $steps );
		}

		$parity_ok = true;
		foreach ( $expected as $slug ) {
			$adapter = class_exists( 'BizCity_CRM_Channel_Registry' ) ? BizCity_CRM_Channel_Registry::get( $slug ) : null;
			$manifest_channel = BizCity_Framework_Manifest_Registry::channel( $slug );
			$descriptor = class_exists( 'BizCity_CRM_Channel_Contract' ) ? BizCity_CRM_Channel_Contract::describe( $slug ) : array();
			$envelope = array(
				'inbox_ref' => 'probe_account_' . $slug,
				'source_id' => 'probe_identity_' . $slug,
				'content' => 'manifest compatibility probe',
				'content_type' => 'text',
				'attachments' => array(),
				'external_source_id' => 'probe_event_' . $slug,
				'received_at' => '2026-09-02 12:00:00',
				'channel_code' => $slug,
			);
			$normalized = class_exists( 'BizCity_CRM_Channel_Contract' ) ? BizCity_CRM_Channel_Contract::normalize_inbound( $slug, $envelope ) : null;
			$accepted = is_array( $normalized );
			$adapter_ok = is_object( $adapter ) && method_exists( $adapter, 'code' ) && $slug === (string) $adapter->code();
			$policy_ok = is_array( $manifest_channel )
				&& (string) ( $manifest_channel['zone'] ?? '' ) === (string) ( $descriptor['zone'] ?? '' )
				&& ( 'enabled' === (string) ( $manifest_channel['crm_policy'] ?? '' ) ) === ! empty( $descriptor['crm_enabled'] )
				&& (string) ( $manifest_channel['identity_policy'] ?? '' ) !== ''
				&& (string) ( $manifest_channel['context_policy'] ?? '' ) !== '';
			if ( ! $accepted || ! $adapter_ok || ! $policy_ok ) {
				$parity_ok = false;
			}
			$steps[] = array(
				'label'  => 'Runtime - ' . $slug . ' manifest/adapter/envelope parity',
				'status' => $accepted && $adapter_ok && $policy_ok ? 'pass' : 'fail',
				'detail' => $accepted && $adapter_ok && $policy_ok ? 'Exact adapter code, manifest policy and CRM normalized envelope agree.' : 'Manifest, adapter or CRM envelope decision diverged.',
			);
		}

		$legacy_rejected = true;
		foreach ( array( 'zalo', 'messenger' ) as $legacy_code ) {
			$legacy_envelope = array(
				'inbox_ref' => 'probe_legacy',
				'source_id' => 'probe_legacy_identity',
				'content' => 'legacy quarantine probe',
				'content_type' => 'text',
				'attachments' => array(),
				'external_source_id' => 'probe_legacy_event_' . $legacy_code,
				'received_at' => '2026-09-02 12:00:00',
				'channel_code' => $legacy_code,
			);
			$legacy_result = BizCity_CRM_Channel_Contract::normalize_inbound( $legacy_code, $legacy_envelope );
			$rejected = is_wp_error( $legacy_result ) && 'channel_zone_not_crm' === (string) $legacy_result->get_error_code();
			$legacy_rejected = $legacy_rejected && $rejected;
			$steps[] = array(
				'label'  => 'Runtime - bare ' . $legacy_code . ' remains fail-closed',
				'status' => $rejected ? 'pass' : 'fail',
				'detail' => $rejected ? 'Legacy compatibility label cannot create a CRM normalized envelope.' : 'Legacy compatibility label was accepted unexpectedly.',
			);
		}

		$pass = $parity_ok && $legacy_rejected;
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Channel manifest compatibility passed.' : 'Channel manifest compatibility has parity failures.',
			'fix_hint'=> $pass ? '' : 'Keep exact manifest slug/adapter code parity and preserve bare legacy channel quarantine.',
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
	$probes[] = 'BizCity_Probe_Channel_Manifest_Compat';
	return $probes;
} );
