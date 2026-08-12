<?php
/**
 * Action: Run Woo BizOps executive digest.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops_Digest' ) ) {
	return;
}

final class BizCity_Automation_Action_Run_Woo_Bizops_Digest extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.run_woo_bizops_digest'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label' => 'Woo BizOps · Executive Digest',
			'short' => 'run_woo_bizops_digest',
			'category' => 'commerce',
			'color' => '#92400e',
			'icon' => 'clipboard-list',
			'defaults' => array(
				'label' => 'Woo BizOps · Executive Digest',
				'include_cohort' => false,
			),
			'fields' => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'include_cohort', 'label' => 'Bao gồm repeat cohort', 'type' => 'toggle', 'hint' => 'Tắt mặc định để tránh quét lớn trong cron.' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — cron-safe executive digest with per-query evidence.
		if ( ! class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service' ) ) {
			return $this->fail( 'module_not_loaded', 'Woo BizOps chưa được nạp.', 'Liên hệ kỹ thuật để bật module Woo BizOps.', 'woo_bizops_not_ready' );
		}
		$owner_user_id = $this->resolve_owner_user_id( $ctx );
		if ( $owner_user_id <= 0 ) {
			$this->note_event( 'woo_bizops_digest_failed', array( 'reason' => 'owner_missing' ) );
			return $this->fail( 'permission_denied', 'Không xác định được chủ tài khoản Woo.', 'Cấu hình owner có quyền quản trị Woo cho workflow digest.', 'admin_capability_required' );
		}

		$queries = array(
			'doanh thu hôm nay',
			'đơn cần giao hôm nay',
			'báo cáo tồn kho sắp hết hàng',
		);
		if ( ! empty( $data['include_cohort'] ) ) {
			$queries[] = 'khách mua lần 2 tháng này';
		}
		$sections = array();
		$failed = 0;
		foreach ( $queries as $query ) {
			$result = BizCity_TwinBrain_Woo_Bizops_Resolver_Service::instance()->resolve_by_query( $query, array(
				'user_id' => $owner_user_id,
				'surface' => 'automation_woo_bizops_digest',
			) );
			$ok = ! empty( $result['success'] );
			$this->note_event( $ok ? 'woo_bizops_digest_query_ok' : 'woo_bizops_digest_query_failed', array(
				'intent_group' => (string) ( $result['intent_group'] ?? '' ),
				'reason' => $ok ? '' : (string) ( $result['code'] ?? $result['_degraded'] ?? 'query_failed' ),
			) );
			if ( $ok ) {
				$sections[] = (string) ( $result['answer_md'] ?? '' );
			} else {
				$failed++;
			}
		}
		if ( empty( $sections ) ) {
			$this->note_event( 'woo_bizops_digest_failed', array( 'reason' => 'all_queries_failed', 'query_count' => count( $queries ) ) );
			return $this->fail( 'woo_bizops_failed', 'Không tạo được báo cáo Woo BizOps.', 'Kiểm tra WooCommerce, quyền owner và Diagnostics rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$this->note_event( 'woo_bizops_digest_done', array( 'query_count' => count( $queries ), 'failed_count' => $failed ) );
		return array(
			'ok' => 1,
			'success' => true,
			'intent_group' => 'executive_digest',
			'answer_md' => "# Woo BizOps · Executive Digest\n\n" . implode( "\n\n---\n\n", $sections ),
			'query_count' => count( $queries ),
			'failed_count' => $failed,
			'degraded' => $failed > 0 ? 'partial_digest' : '',
			'error_code' => '',
			'error_message' => '',
		);
	}

	private function fail( string $code, string $message, string $hint, string $help_code ): array {
		return class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
	}
}
