<?php
/**
 * Query Monitor collector for BizCity loader hook snapshots.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-09
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_QM_Loader_Collector', false ) ) {
	return;
}

if ( ! class_exists( 'QM_DataCollector' ) || ! class_exists( 'QM_Data_Fallback' ) ) {
	return;
}

final class BizCity_QM_Loader_Collector extends QM_DataCollector {

	public $id = 'bizcity_loader';

	public function get_storage(): QM_Data {
		return new QM_Data_Fallback();
	}

	public function process(): void {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-QM - publish bounded loader data to Query Monitor.
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - publish the observe-only
		// schema marker and request context without changing runtime loading.
		$this->data->trace_schema = 'bizcity.loader.v2';
		if ( class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' ) ) {
			$this->data->snapshots = BizCity_Diagnostics_Loader_Hook_Panel::snapshots();
		} else {
			$this->data->snapshots = array();
		}
		$this->data->request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) ( function_exists( 'wp_parse_url' )
				? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH )
				: parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) )
			: '';
		$this->data->request_context = array();
		foreach ( (array) $this->data->snapshots as $snapshot ) {
			if ( ! empty( $snapshot['context'] ) && is_array( $snapshot['context'] ) ) {
				$this->data->request_context = $snapshot['context'];
				break;
			}
		}
		$peak_memory_allocated = round( memory_get_peak_usage( true ) / 1048576, 2 );
		$peak_memory_used = round( memory_get_peak_usage( false ) / 1048576, 2 );
		$snapshot_peak_allocated = 0.0;
		$snapshot_peak_used = 0.0;
		$snapshot_raw_consistent = true;
		foreach ( (array) $this->data->snapshots as $snapshot ) {
			if ( isset( $snapshot['memory_metric_raw_consistent'] ) && ! $snapshot['memory_metric_raw_consistent'] ) {
				$snapshot_raw_consistent = false;
			}
			$candidate_allocated = isset( $snapshot['memory_peak_allocated_mb'] )
				? (float) $snapshot['memory_peak_allocated_mb']
				: 0.0;
			if ( $candidate_allocated >= $snapshot_peak_allocated ) {
				$snapshot_peak_allocated = $candidate_allocated;
				$snapshot_peak_used = isset( $snapshot['memory_peak_mb'] )
					? (float) $snapshot['memory_peak_mb']
					: 0.0;
			}
		}
		$has_snapshot_memory = $snapshot_peak_allocated > 0.0;
		$canonical_peak_allocated = $has_snapshot_memory ? $snapshot_peak_allocated : $peak_memory_allocated;
		$canonical_peak_used = $has_snapshot_memory ? $snapshot_peak_used : $peak_memory_used;
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - use one lifecycle
		// snapshot pair for the footer and retain PHP readings for drift evidence.
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - namespace memory
		// fields so Query Monitor data containers cannot overwrite the contract.
		$this->data->bizcity_peak_memory_allocated_mb = $canonical_peak_allocated;
		$this->data->bizcity_peak_memory_used_mb = $canonical_peak_used;
		$this->data->bizcity_memory_metric_consistent = $canonical_peak_allocated >= $canonical_peak_used;
		$this->data->bizcity_memory_raw_metric_consistent = $snapshot_raw_consistent
			&& $peak_memory_allocated >= $peak_memory_used;
		$this->data->bizcity_memory_measurement_source = $has_snapshot_memory ? 'lifecycle_snapshot' : 'php_peak_fallback';
		$this->data->bizcity_memory_metric_raw = array(
			'canonical_peak_used_mb'      => $canonical_peak_used,
			'canonical_peak_allocated_mb' => $canonical_peak_allocated,
			'php_peak_used_mb'            => $peak_memory_used,
			'php_peak_allocated_mb'       => $peak_memory_allocated,
			'raw_metric_consistent'       => $this->data->bizcity_memory_raw_metric_consistent,
		);
		$this->data->peak_memory_mb = $canonical_peak_allocated;
		$this->data->peak_memory_used_mb = $canonical_peak_used;
		$this->data->memory_metric_consistent = $canonical_peak_allocated >= $canonical_peak_used;
		$this->data->memory_metric_raw = array(
			'peak_used_mb'      => $peak_memory_used,
			'peak_allocated_mb' => $peak_memory_allocated,
		);
		$this->data->included_files = count( get_included_files() );
		$this->data->declared_classes = count( get_declared_classes() );
		if ( function_exists( 'bizcity_qm_loader_export_jsonl' ) ) {
			bizcity_qm_loader_export_jsonl( $this->data->snapshots, array(
				'trace_schema' => 'bizcity.loader.v2',
				'qm_version'   => defined( 'QM_VERSION' ) ? (string) QM_VERSION : '',
				'collector'    => 'bizcity_loader',
				'context'      => $this->data->request_context,
			) );
		}
	}
}
