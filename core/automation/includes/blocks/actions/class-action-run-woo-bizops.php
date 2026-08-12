<?php
/**
 * Action: Run Woo BizOps query.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops' ) ) {
	return;
}

final class BizCity_Automation_Action_Run_Woo_Bizops extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.run_woo_bizops'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label' => 'Woo BizOps',
			'short' => 'run_woo_bizops',
			'category' => 'commerce',
			'color' => '#b45309',
			'icon' => 'bar-chart-3',
			'defaults' => array(
				'label' => 'Woo BizOps',
				'query' => '{{trigger.text}}',
				'max_results' => 10,
			),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'query', 'label' => 'Câu hỏi Woo BizOps', 'type' => 'textarea', 'hint' => '{{trigger.text}}' ),
				array( 'name' => 'max_results', 'label' => 'Số dòng tối đa', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — thin admin-gated automation wrapper.
		$query = trim( (string) $this->resolve( $data['query'] ?? '{{trigger.text}}', $ctx ) );
		if ( $query === '' ) {
			return $this->fail( 'invalid_param', 'Câu hỏi Woo BizOps đang rỗng.', 'Nhập câu hỏi về doanh thu, đơn hàng hoặc điểm.', 'invalid_param_generic' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service' ) ) {
			return $this->fail( 'module_not_loaded', 'Woo BizOps chưa được nạp.', 'Liên hệ kỹ thuật để bật module Woo BizOps.', 'woo_bizops_not_ready' );
		}
		$owner_user_id = $this->resolve_owner_user_id( $ctx );
		if ( $owner_user_id <= 0 ) {
			return $this->fail( 'permission_denied', 'Không xác định được chủ tài khoản Woo.', 'Liên kết Zalo Bot với tài khoản quản trị WooCommerce.', 'admin_capability_required' );
		}
		$result = BizCity_TwinBrain_Woo_Bizops_Resolver_Service::instance()->resolve_by_query( $query, array(
			'user_id' => $owner_user_id,
			'surface' => 'automation_zalobot',
			'max_results' => max( 1, min( 20, (int) ( $data['max_results'] ?? 10 ) ) ),
		) );
		if ( empty( $result['success'] ) ) {
			return array_merge( $result, array( 'ok' => 0, 'answer_md' => (string) ( $result['message'] ?? '' ), 'error_code' => (string) ( $result['code'] ?? 'woo_bizops_failed' ), 'error_message' => (string) ( $result['message'] ?? '' ) ) );
		}
		$this->note_event( 'woo_bizops_query_done', array( 'intent_group' => (string) ( $result['intent_group'] ?? '' ) ) );
		return array_merge( $result, array(
			'ok' => 1,
			'answer_md' => (string) ( $result['answer_md'] ?? '' ),
			'metrics_json' => wp_json_encode( (array) ( $result['metrics'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'citations_json' => wp_json_encode( (array) ( $result['citations'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'error_code' => '',
			'error_message' => '',
		) );
	}

	private function fail( string $code, string $message, string $hint, string $help_code ): array {
		return class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
	}
}
