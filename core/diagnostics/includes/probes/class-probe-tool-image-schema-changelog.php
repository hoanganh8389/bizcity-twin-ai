<?php
/**
 * Tool Image schema changelog and registry probe.
 *
 * Read-only. It never provisions or drops Tool Image tables. When the
 * standalone plugin is not active, loader/runtime evidence is SKIP rather
 * than a false PASS or FAIL.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-07
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
if ( class_exists( 'BizCity_Probe_Tool_Image_Schema_Changelog', false ) ) {
	return;
}

final class BizCity_Probe_Tool_Image_Schema_Changelog implements BizCity_Diagnostics_Probe {

	private function expected_tables(): array {
		return array(
			'bztimg_jobs',
			'bztimg_template_categories',
			'bztimg_templates',
			'bztimg_projects',
			'bztimg_compositions',
			'bztimg_editor_shapes',
			'bztimg_editor_frames',
			'bztimg_editor_fonts',
			'bztimg_editor_text_presets',
			'bztimg_editor_templates',
		);
	}

	public function id(): string { return 'modules.tool_image.schema_changelog'; }
	public function label(): string { return 'Tool Image schema changelog and registry'; }
	public function description(): string { return 'Checks the 10-table Tool Image R-DCL catalog and central Schema Registry without provisioning or cleanup.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 54; }
	public function icon(): string { return 'database'; }
	public function estimate_ms(): int { return 80; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$expected = $this->expected_tables();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$changelog_file = $root . 'core/diagnostics/changelog/modules.tool-image.json';
		$raw = is_readable( $changelog_file ) ? (string) file_get_contents( $changelog_file ) : '';
		$data = $raw !== '' ? json_decode( $raw, true ) : null;
		$disk_ok = is_array( $data )
			&& (string) ( $data['module_id'] ?? '' ) === 'modules.tool-image'
			&& count( (array) ( $data['tables'] ?? array() ) ) === count( $expected )
			&& ! array_diff( $expected, array_keys( (array) ( $data['tables'] ?? array() ) ) );
		$steps[] = array(
			'label' => 'Disk - Tool Image R-DCL changelog',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'modules.tool-image.json declares all 10 expected tables.' : 'Tool Image changelog is missing, invalid, or does not declare exactly the 10 expected tables.',
		);

		$catalog_ok = false;
		if ( class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			$catalog_names = array();
			foreach ( BizCity_Diagnostics_Table_Registry::get_tables() as $row ) {
				if ( is_array( $row ) && (string) ( $row['name'] ?? '' ) !== '' ) {
					$catalog_names[] = (string) $row['name'];
				}
			}
			$catalog_ok = count( array_intersect( $expected, $catalog_names ) ) === count( $expected );
		}
		$steps[] = array(
			'label' => 'Loader - Diagnostics table catalog',
			'status' => $catalog_ok ? 'pass' : 'fail',
			'detail' => $catalog_ok ? 'Diagnostics catalog contains all 10 Tool Image table owners.' : 'Diagnostics catalog is missing one or more Tool Image table owners.',
		);

		$tool_active = defined( 'BZTIMG_VERSION' ) || function_exists( 'bztimg_install_tables' ) || class_exists( 'BizCity_Tool_Image', false );
		$registry_ok = false;
		if ( $tool_active && class_exists( 'BizCity_Schema_Registry' ) ) {
			$registry_ok = true;
			foreach ( $expected as $table_name ) {
				if ( ! BizCity_Schema_Registry::is_registered( $table_name ) ) {
					$registry_ok = false;
					break;
				}
			}
		}
		$registry_status = ! $tool_active ? 'skip' : ( $registry_ok ? 'pass' : 'fail' );
		$registry_detail = ! $tool_active
			? 'Standalone Tool Image is not active in this runtime; Schema Registry evidence is deferred.'
			: ( $registry_ok ? 'All 10 Tool Image tables are registered in BizCity_Schema_Registry.' : 'Tool Image is active but one or more tables are missing from BizCity_Schema_Registry.' );
		$steps[] = array(
			'label' => 'Runtime - central Schema Registry',
			'status' => $registry_status,
			'detail' => $registry_detail,
		);

		foreach ( $steps as $step ) {
			$ctx->emit_step( $step );
		}

		if ( ! $disk_ok || ! $catalog_ok || ( $tool_active && ! $registry_ok ) ) {
			return array(
				'status' => 'fail',
				'summary' => 'Tool Image schema changelog or registry contract is incomplete.',
				'fix_hint' => 'Restore the 10-table modules.tool-image.json/catalog/Schema Registry contract, then rerun this probe.',
				'steps' => $steps,
			);
		}
		if ( ! $tool_active ) {
			return array(
				'status' => 'skip',
				'summary' => 'Tool Image static catalog passed; runtime Schema Registry evidence is deferred because the standalone plugin is inactive.',
				'error' => 'tool_image_inactive',
				'fix_hint' => 'Run this probe on a target where bizcity-tool-image is active to prove the 10 runtime registrations.',
				'steps' => $steps,
			);
		}
		return array(
			'status' => 'pass',
			'summary' => 'Tool Image schema changelog and 10-table Schema Registry contract passed.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Tool_Image_Schema_Changelog';
	return $probes;
} );
