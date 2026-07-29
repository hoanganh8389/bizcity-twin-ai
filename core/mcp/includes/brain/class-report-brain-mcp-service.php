<?php
/**
 * BizCity_Report_Brain_MCP_Service — read-only report planning/data bridge.
 *
 * Builds datasets from canonical CRM/Woo report services. It does not persist
 * reports, render files, or save anything to a notebook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-28 (PHASE-0.54-MCP Wave N)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — report Brain tools remain read-only.
final class BizCity_Report_Brain_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function list_templates( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — expose stable report recipes without a new template table.
		return array(
			'source'    => 'mcp_builtin',
			'templates' => array(
				array(
					'slug'        => 'weekly_business_review',
					'title'       => 'Báo cáo tuần kinh doanh',
					'description' => 'Doanh thu, đơn hàng, tồn kho và đề xuất việc cần làm trong tuần tới.',
					'datasets'    => array( 'sales', 'inventory', 'brain_context' ),
				),
				array(
					'slug'        => 'weekly_customer_operations',
					'title'       => 'Báo cáo tuần chăm sóc khách hàng',
					'description' => 'Hội thoại, tin nhắn, xử lý, CSAT và SLA theo khoảng thời gian.',
					'datasets'    => array( 'customer', 'brain_context' ),
				),
			),
		);
	}

	public function build_dataset( array $args, array $ctx ) {
		// [2026-07-28 Johnny Chu] PHASE-0.54-MCP — compose read-only canonical datasets for a later report draft.
		$from = $this->date_arg( $args, 'from', -7 );
		$to   = $this->date_arg( $args, 'to', 0 );
		$include_sales = ! array_key_exists( 'include_sales', $args ) || ! empty( $args['include_sales'] );
		$include_customer = ! array_key_exists( 'include_customer', $args ) || ! empty( $args['include_customer'] );
		$include_inventory = ! empty( $args['include_inventory'] );
		$metrics = isset( $args['customer_metrics'] ) ? array_values( array_unique( array_map( 'sanitize_key', (array) $args['customer_metrics'] ) ) ) : array( 'conversations_count', 'incoming_messages_count', 'resolutions_count', 'csat_avg', 'sla_breach_count' );
		$group_by = isset( $args['group_by'] ) ? sanitize_key( (string) $args['group_by'] ) : 'none';
		$datasets = array();
		$business = class_exists( 'BizCity_Business_MCP_Service' ) ? BizCity_Business_MCP_Service::instance() : null;
		if ( $include_sales ) {
			$datasets['sales'] = $business ? $business->get_sales_metrics( array( 'from' => $from, 'to' => $to ), $ctx ) : array( '_degraded' => true, 'reason' => 'business_service_unavailable' );
		}
		if ( $include_customer ) {
			$datasets['customer'] = $business ? $business->get_customer_metrics( array( 'from' => $from, 'to' => $to, 'metrics' => $metrics, 'group_by' => $group_by ), $ctx ) : array( '_degraded' => true, 'reason' => 'business_service_unavailable' );
		}
		if ( $include_inventory ) {
			$datasets['inventory'] = $business ? $business->get_inventory_metrics( array( 'limit' => isset( $args['inventory_limit'] ) ? (int) $args['inventory_limit'] : 100, 'low_stock_threshold' => isset( $args['low_stock_threshold'] ) ? (int) $args['low_stock_threshold'] : 5 ), $ctx ) : array( '_degraded' => true, 'reason' => 'business_service_unavailable' );
		}
		if ( empty( $datasets ) ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Chọn ít nhất một nhóm dataset để xây dựng báo cáo.', array( 'status' => 400 ) );
		}
		return array(
			'dataset_id' => 'rds_' . str_replace( '-', '', wp_generate_uuid4() ),
			'from'       => $from,
			'to'         => $to,
			'group_by'   => $group_by,
			'datasets'   => $datasets,
			'read_only'  => true,
			'next_steps' => array( 'Dùng dataset này để build context và tạo draft báo cáo; chưa có file hoặc side effect nào được tạo.' ),
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
}
