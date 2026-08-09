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
		if ( class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' ) ) {
			$this->data->snapshots = BizCity_Diagnostics_Loader_Hook_Panel::snapshots();
		} else {
			$this->data->snapshots = array();
		}
		$this->data->request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) $_SERVER['REQUEST_URI']
			: '';
		$this->data->peak_memory_mb = round( memory_get_peak_usage( true ) / 1048576, 2 );
		$this->data->included_files = count( get_included_files() );
		$this->data->declared_classes = count( get_declared_classes() );
		if ( function_exists( 'bizcity_qm_loader_export_jsonl' ) ) {
			bizcity_qm_loader_export_jsonl( $this->data->snapshots, array(
				'qm_version' => defined( 'QM_VERSION' ) ? (string) QM_VERSION : '',
				'collector'  => 'bizcity_loader',
			) );
		}
	}
}
