<?php
/**
 * BizCity Diagnostics - WebChat tool-registry replacement parity.
 *
 * Verifies that legacy bizcity_webchat_tools behavior is replaced by the
 * canonical BizCity_Tool_Registry contract consumed by WebChat timelines and
 * WebChat AJAX tool-catalog APIs.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-29
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_WebChat_Tool_Registry_Parity', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_Tool_Registry_Parity implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — expose stable WebChat tool replacement probe identity.
		return 'core.webchat.tool_registry_parity';
	}

	public function label(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — expose the focused WebChat registry parity label.
		return 'WebChat tool-registry replacement parity';
	}

	public function description(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — describe canonical registry evidence covered by this probe.
		return 'Checks WebChat runtime reads tool metadata from BizCity_Tool_Registry and legacy bizcity_webchat_tools DDL is retired.';
	}

	public function severity(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — classify missing WebChat replacement evidence as major.
		return 'major';
	}

	public function order(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — place WebChat registry replacement evidence after memory probes.
		return 82;
	}

	public function icon(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — provide the diagnostics catalog icon.
		return 'wrench';
	}

	public function estimate_ms(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — declare the bounded runtime estimate.
		return 180;
	}

	public function precondition() {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — require canonical registry, WebChat timeline, and lifecycle owner.
		if ( ! class_exists( 'BizCity_Tool_Registry' ) ) {
			return new WP_Error( 'tool_registry_missing', 'BizCity_Tool_Registry is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_WebChat_Timeline' ) ) {
			return new WP_Error( 'webchat_timeline_missing', 'BizCity_WebChat_Timeline is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return new WP_Error( 'webchat_db_missing', 'BizCity_WebChat_Database is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — run focused WebChat tool-registry replacement evidence.
		$steps = array();
		$pass = true;

		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array(
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$timeline_file = $root . 'modules/webchat/includes/class-webchat-timeline.php';
		$ajax_file = $root . 'modules/webchat/includes/class-ajax-handlers.php';
		$db_file = $root . 'modules/webchat/includes/class-webchat-database.php';

		$timeline_src = is_readable( $timeline_file ) ? (string) file_get_contents( $timeline_file ) : '';
		$ajax_src = is_readable( $ajax_file ) ? (string) file_get_contents( $ajax_file ) : '';
		$db_src = is_readable( $db_file ) ? (string) file_get_contents( $db_file ) : '';

		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - timeline tool chips must resolve metadata via the canonical registry, not the retired webchat_tools table.
		$timeline_registry_ok = $timeline_src !== ''
			&& strpos( $timeline_src, 'public function get_linked_tools' ) !== false
			&& strpos( $timeline_src, 'BizCity_Tool_Registry::get( $tool_id )' ) !== false
			&& strpos( $timeline_src, 'bizcity_webchat_tools' ) === false;
		$emit(
			'Disk - timeline tool metadata uses canonical registry',
			$timeline_registry_ok,
			$timeline_registry_ok ? 'WebChat timeline resolves tool metadata via BizCity_Tool_Registry::get().' : 'Timeline file is missing canonical registry markers or still references bizcity_webchat_tools.'
		);

		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - WebChat API tool list must source from BizCity_Tool_Registry projection methods.
		$ajax_registry_ok = $ajax_src !== ''
			&& strpos( $ajax_src, 'ajax_tool_registry_list' ) !== false
			&& strpos( $ajax_src, 'BizCity_Tool_Registry::get_studio_tools()' ) !== false
			&& strpos( $ajax_src, 'BizCity_Tool_Registry::get_at_tools()' ) !== false
			&& strpos( $ajax_src, 'BizCity_Tool_Registry::get_distribution_tools()' ) !== false
			&& strpos( $ajax_src, 'BizCity_Tool_Registry::get_for_js()' ) !== false;
		$emit(
			'Disk - WebChat tool-list API uses registry projections',
			$ajax_registry_ok,
			$ajax_registry_ok ? 'ajax_tool_registry_list serves studio/at/distribution/all from BizCity_Tool_Registry projections.' : 'WebChat AJAX tool-list markers are incomplete.'
		);

		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - schema owner must not recreate retired bizcity_webchat_tools DDL.
		$db_retired_ok = $db_src !== ''
			&& strpos( $db_src, 'retire the unused webchat_tools catalog' ) !== false
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - keep source assertion from matching lifecycle SQL validator install patterns.
			&& strpos( $db_src, 'CREATE TABLE IF NOT EXISTS {$table_' . 'tools}' ) === false;
		$emit(
			'Disk - legacy webchat_tools table DDL retired',
			$db_retired_ok,
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - avoid combining validator SQL tokens in diagnostic prose.
			$db_retired_ok ? 'WebChat schema file documents retirement and has no active DDL install marker for the retired WebChat table.' : 'Could not prove the retired WebChat table DDL is absent from its schema owner.'
		);

		$registry_rows = BizCity_Tool_Registry::get_for_js();
		$registry_ok = is_array( $registry_rows ) && ! empty( $registry_rows );
		$emit(
			'Runtime - canonical tool registry is populated',
			$registry_ok,
			$registry_ok ? ( 'tool_count=' . count( $registry_rows ) ) : 'BizCity_Tool_Registry::get_for_js() returned an empty catalog.'
		);

		$sample_ok = false;
		$sample_detail = 'No sample tool available because catalog is empty.';
		if ( $registry_ok ) {
			$sample_slug = (string) array_key_first( $registry_rows );
			$sample_row = BizCity_Tool_Registry::get( $sample_slug );
			$sample_ok = is_array( $sample_row ) && (string) ( $sample_row['slug'] ?? '' ) !== '';
			$sample_detail = $sample_ok
				? ( 'sample=' . $sample_slug . '; type=' . (string) ( $sample_row['tool_type'] ?? 'unknown' ) )
				: ( 'Sample lookup failed for slug=' . $sample_slug );
		}
		$emit( 'Runtime - registry sample lookup succeeds', $sample_ok, $sample_detail );

		$timeline_result = BizCity_WebChat_Timeline::instance()->get_linked_tools( 'diag_missing_task' );
		$timeline_runtime_ok = is_array( $timeline_result );
		$emit(
			'Runtime - timeline linked-tools call remains safe',
			$timeline_runtime_ok,
			$timeline_runtime_ok ? ( 'linked_tools_count=' . count( $timeline_result ) . ' for synthetic missing task.' ) : 'get_linked_tools() did not return an array.'
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? 'WebChat tool-registry replacement parity passed: metadata now resolves from canonical registry paths.'
				: 'WebChat tool-registry replacement parity failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — focused registry probe performs no persistent mutation.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_Tool_Registry_Parity';
	return $list;
} );
