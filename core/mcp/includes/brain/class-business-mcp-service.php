<?php
/**
 * BizCity_Business_MCP_Service — read-only business metrics for MCP Brain.
 *
 * Metrics are delegated to the active CRM/WooCommerce report services. This
 * class never writes business data and degrades explicitly when an optional
 * source plugin is not loaded.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-28 (PHASE-0.54-MCP Wave L)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — read-only business metrics bridge.
final class BizCity_Business_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_sales_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — delegate revenue aggregation to the canonical CRM Woo bridge.
		$from = $this->date_arg( $args, 'from', -30 );
		$to   = $this->date_arg( $args, 'to', 0 );
		if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
			return array( '_degraded' => true, 'reason' => 'crm_woo_reports_unavailable', 'from' => $from, 'to' => $to, 'summary' => $this->zero_sales( $from, $to ) );
		}
		return array(
			'from'    => $from,
			'to'      => $to,
			'source'  => 'BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary',
			'summary' => BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary( $from, $to ),
		);
	}

	public function get_customer_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — aggregate selected CRM KPIs through the canonical report builder.
		if ( ! class_exists( 'BizCity_CRM_Report_Builder' ) ) {
			return array( '_degraded' => true, 'reason' => 'crm_report_builder_unavailable', 'metrics' => array() );
		}
		$from    = $this->date_arg( $args, 'from', -30 );
		$to      = $this->date_arg( $args, 'to', 0 );
		$metrics = isset( $args['metrics'] ) ? array_values( array_unique( array_map( 'sanitize_key', (array) $args['metrics'] ) ) ) : array( 'conversations_count', 'incoming_messages_count', 'outgoing_messages_count', 'resolutions_count', 'csat_avg', 'sla_breach_count' );
		$metrics = array_slice( $metrics, 0, 12 );
		$group_by = isset( $args['group_by'] ) ? sanitize_key( (string) $args['group_by'] ) : 'none';
		$out      = array();
		foreach ( $metrics as $metric ) {
			$result = BizCity_CRM_Report_Builder::aggregate( array(
				'metric'   => $metric,
				'group_by' => $group_by,
				'from'     => $from,
				'to'       => $to,
				'inbox_id' => isset( $args['inbox_id'] ) ? (int) $args['inbox_id'] : 0,
				'agent_id' => isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0,
			) );
			if ( isset( $result['error'] ) ) {
				return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Metric CRM không hợp lệ: ' . $metric . '.', array( 'status' => 400 ) );
			}
			$out[ $metric ] = $result;
		}
		return array( 'from' => $from, 'to' => $to, 'group_by' => $group_by, 'source' => 'BizCity_CRM_Report_Builder::aggregate', 'metrics' => $out );
	}

	public function get_inventory_metrics( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — bounded WooCommerce read-only inventory summary.
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'total_products' => 0, 'scanned_products' => 0, 'low_stock_count' => 0, 'out_of_stock_count' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$query = wc_get_products( array( 'limit' => $limit, 'status' => array( 'publish', 'draft' ), 'paginate' => true, 'return' => 'objects' ) );
		$products = is_object( $query ) && isset( $query->products ) ? (array) $query->products : (array) $query;
		$total = is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $products );
		$low_threshold = isset( $args['low_stock_threshold'] ) ? max( 0, (int) $args['low_stock_threshold'] ) : 5;
		$low = 0;
		$out = 0;
		$stock_units = 0;
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'managing_stock' ) ) {
				continue;
			}
			$quantity = $product->get_stock_quantity();
			if ( $product->get_stock_status() === 'outofstock' || ( $quantity !== null && (float) $quantity <= 0 ) ) {
				$out++;
			} elseif ( $product->managing_stock() && $quantity !== null && (float) $quantity <= $low_threshold ) {
				$low++;
			}
			if ( $quantity !== null ) {
				$stock_units += max( 0, (int) $quantity );
			}
		}
		return array(
			'source'             => 'WooCommerce CRUD API',
			'total_products'     => $total,
			'scanned_products'   => count( $products ),
			'scan_limit'         => $limit,
			'partial'            => $total > count( $products ),
			'low_stock_threshold' => $low_threshold,
			'low_stock_count'    => $low,
			'out_of_stock_count' => $out,
			'stock_units_scanned'=> $stock_units,
		);
	}

	private function date_arg( array $args, $key, $offset_days ) {
		if ( ! empty( $args[ $key ] ) ) {
			$value = sanitize_text_field( (string) $args[ $key ] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $value;
			}
		}
		return date( 'Y-m-d', current_time( 'timestamp' ) + ( (int) $offset_days * DAY_IN_SECONDS ) );
	}

	private function zero_sales( $from, $to ) {
		return array( 'gross' => 0.0, 'net' => 0.0, 'refunds' => 0.0, 'order_count' => 0, 'paid_count' => 0, 'aov' => 0.0, 'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND', 'from' => $from, 'to' => $to );
	}
}
