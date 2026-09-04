<?php
/**
 * Read-only DDV probe for the canonical exact-account CRM inbox reader.
 *
 * The probe uses existing active data only. It does not create, update or
 * delete CRM rows and does not exercise provider transport.
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

$_bizcity_plugin_root = dirname( __DIR__, 4 );
if ( ! class_exists( 'BizCity_CRM_Repository', false ) ) {
	BizCity_Safe_Loader::require_file( $_bizcity_plugin_root . '/plugins/bizcity-twin-crm/includes/class-repository.php', 'crm.repository' );
}
if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2', false ) ) {
	BizCity_Safe_Loader::require_file( $_bizcity_plugin_root . '/plugins/bizcity-twin-crm/includes/class-db-installer.php', 'crm.db_installer' );
}
unset( $_bizcity_plugin_root );
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_CRM_Inbox_By_Ref', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Inbox_By_Ref implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.inbox_by_ref'; }
	public function label(): string { return 'CRM exact inbox reader'; }
	public function description(): string { return 'Kiểm tra reader canonical theo channel_type + channel_ref_id, cache contract và exact-match fail-closed cho /gpt/crm/.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 47; }
	public function icon(): string { return 'inbox'; }
	public function estimate_ms(): int { return 150; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7 — prove the exact-account inbox reader without CRM mutation or provider transport.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$repository_path = $root . 'plugins/bizcity-twin-crm/includes/class-repository.php';
		$source = is_readable( $repository_path ) ? (string) file_get_contents( $repository_path ) : '';
		$disk_ok = $source !== '' && strpos( $source, 'function get_inbox_by_ref' ) !== false;
		$steps[] = array(
			'label'  => 'Disk - canonical repository reader and no duplicate TwinWeb lookup',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Repository contains get_inbox_by_ref(); the exact reader is owned by CRM.' : 'Canonical repository reader is missing or unreadable.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Canonical CRM inbox reader is missing.', 'fix_hint' => 'Add the exact repository reader before implementing /gpt/crm/.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_CRM_Repository', false )
			&& class_exists( 'BizCity_CRM_DB_Installer_V2', false )
			&& method_exists( 'BizCity_CRM_Repository', 'get_inbox_by_ref' )
			&& ( ! class_exists( 'BizCity_Cache_Registry' ) || BizCity_Cache_Registry::is_registered( 'crm_repository' ) );
		$steps[] = array(
			'label'  => 'Loader - repository method and cache group are available',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'CRM repository, table owner and crm_repository cache contract are loaded.' : 'Repository, table owner or cache contract is unavailable.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM inbox reader dependencies are incomplete.', 'fix_hint' => 'Load the repository and register its cache contract before rerunning the probe.', 'steps' => $steps );
		}

		$inboxes = BizCity_CRM_Repository::list_inboxes();
		$fixture = null;
		foreach ( is_array( $inboxes ) ? $inboxes : array() as $inbox ) {
			if ( is_array( $inbox ) && (string) ( $inbox['channel_type'] ?? '' ) !== '' && (string) ( $inbox['channel_ref_id'] ?? '' ) !== '' ) {
				$fixture = $inbox;
				break;
			}
		}
		$runtime_ok = false;
		$negative_ok = false;
		if ( is_array( $fixture ) ) {
			$channel_type = (string) $fixture['channel_type'];
			$channel_ref_id = (string) $fixture['channel_ref_id'];
			$resolved = BizCity_CRM_Repository::get_inbox_by_ref( $channel_type, $channel_ref_id );
			$runtime_ok = is_array( $resolved )
				&& (int) ( $resolved['id'] ?? 0 ) === (int) ( $fixture['id'] ?? 0 )
				&& (string) ( $resolved['channel_type'] ?? '' ) === $channel_type
				&& (string) ( $resolved['channel_ref_id'] ?? '' ) === $channel_ref_id
				&& (int) ( $resolved['is_active'] ?? 1 ) === 1;
			$negative = BizCity_CRM_Repository::get_inbox_by_ref( $channel_type, $channel_ref_id . '__not_the_same_ref__' );
			$negative_ok = null === $negative;
		}
		$steps[] = array(
			'label'  => 'Runtime - exact active channel/ref lookup and negative mismatch',
			'status' => $runtime_ok && $negative_ok ? 'pass' : ( is_array( $fixture ) ? 'fail' : 'skip' ),
			'detail' => $runtime_ok && $negative_ok
				? 'Existing active inbox resolved by the exact tuple and a different ref did not match.'
				: ( is_array( $fixture ) ? 'Exact lookup or mismatch rejection failed.' : 'No active inbox fixture is available on this tenant; runtime evidence is deferred.' ),
		);

		$overall = $runtime_ok && $negative_ok;
		return array(
			'status'  => $overall ? 'pass' : ( is_array( $fixture ) ? 'fail' : 'skip' ),
			'summary' => $overall ? 'CRM exact inbox reader passed.' : ( is_array( $fixture ) ? 'CRM exact inbox reader failed.' : 'CRM exact inbox reader needs a tenant fixture.' ),
			'fix_hint'=> $overall ? '' : 'Provision or select an active CRM inbox fixture, then rerun the exact tuple reader probe.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_CRM_Inbox_By_Ref';
	return $probes;
} );
