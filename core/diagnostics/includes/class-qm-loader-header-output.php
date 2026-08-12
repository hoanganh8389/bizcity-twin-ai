<?php
/**
 * Query Monitor headers output for BizCity loader observability.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-09
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'QM_Output_Headers' ) || class_exists( 'BizCity_QM_Loader_Header_Output', false ) ) {
	return;
}

final class BizCity_QM_Loader_Header_Output extends QM_Output_Headers {

	public function get_output(): array {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - emit compact REST metrics only.
		$data = $this->collector->get_data();
		$snapshots = isset( $data->snapshots ) && is_array( $data->snapshots )
			? $data->snapshots
			: array();
		$capture_overhead = array();
		foreach ( $snapshots as $phase => $snapshot ) {
			$capture_overhead[] = (string) $phase . ':' . (float) ( $snapshot['capture_overhead_delta_kb'] ?? 0 ) . 'KB';
		}

		return array(
			'peak-memory-mb' => (float) ( $data->bizcity_peak_memory_allocated_mb ?? $data->peak_memory_mb ?? 0 ),
			'peak-memory-used-mb' => (float) ( $data->bizcity_peak_memory_used_mb ?? $data->peak_memory_used_mb ?? 0 ),
			'included-files' => (int) ( $data->included_files ?? 0 ),
			'declared-classes' => (int) ( $data->declared_classes ?? 0 ),
			'capture-overhead' => implode( ',', $capture_overhead ),
		);
	}
}
