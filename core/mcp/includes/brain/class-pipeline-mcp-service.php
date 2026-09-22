<?php
/**
 * BizCity_CRM_Pipeline_MCP_Bridge — read-only pipeline metrics bridge.
 *
 * Delegates to the canonical CRM reporting rollup owner. It never reads CRM
 * tables directly, mutates runs, sends notifications or exposes customer PII.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since 2026-09-22 (PHASE-0.63A WP-8.4)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_MCP_Bridge', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_MCP_Bridge {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Read pipeline lifecycle metrics from the canonical reporting owner.
	 *
	 * @param array $args Tool arguments.
	 * @param array $ctx  MCP request context.
	 * @return array|WP_Error
	 */
	public function get_metrics( array $args, array $ctx ) {
		if ( ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) {
			return array( '_degraded' => true, 'reason' => 'crm_reporting_unavailable', 'items' => array() );
		}
		$allowed_metrics = array(
			'pipeline_stage_changed',
			'pipeline_step_done',
			'pipeline_sla_breached',
			'pipeline_sla_met',
			'pipeline_exception_opened',
			'pipeline_exception_acknowledged',
			'pipeline_exception_resolved',
		);
		$metric = sanitize_key( (string) ( $args['metric'] ?? '' ) );
		if ( '' !== $metric && ! in_array( $metric, $allowed_metrics, true ) ) {
			return new WP_Error( 'pipeline_metric_invalid', 'Metric pipeline không hợp lệ.', array( 'status' => 400 ) );
		}
		$items = BizCity_CRM_Reporting_Rollup::get_rollups( array(
			'from' => isset( $args['from'] ) ? (string) $args['from'] : '',
			'to' => isset( $args['to'] ) ? (string) $args['to'] : '',
			'dimension_type' => isset( $args['dimension_type'] ) ? (string) $args['dimension_type'] : '',
			'metric' => $metric,
			'limit' => isset( $args['limit'] ) ? (int) $args['limit'] : 200,
		) );
		return array(
			'contract' => 'pipeline-metrics',
			'version' => '1.0.0',
			'read_only' => true,
			'source' => 'BizCity_CRM_Reporting_Rollup::get_rollups',
			'metrics' => $allowed_metrics,
			'items' => is_array( $items ) ? $items : array(),
		);
	}
}
