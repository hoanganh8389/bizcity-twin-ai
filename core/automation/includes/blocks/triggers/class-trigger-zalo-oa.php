<?php
/**
 * Trigger: Zalo OA inbound message (Zone 1 — Customer Care).
 *
 * Fires khi khách hàng gửi tin nhắn vào Zalo Official Account (Zone 1 — kênh CSKH).
 * Phân biệt với trigger.zalo_inbound (Zone 2 — kênh Zalo Bot admin).
 *
 * Platform: ZALO_OA  (chat_id prefix: zalooa_)
 * Zone:     crm (Zone 1 — customer-facing)
 *
 * [2026-06-24 Johnny Chu] PHASE-0.40 — Thêm trigger Zalo OA cho Zone 1 (R-ZONE).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      PHASE-0.40
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Zalo_OA extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.zalo_oa_inbound'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		return array(
			'label'    => 'Zalo OA · tin nhắn khách',
			'short'    => 'Zalo OA msg',
			'category' => 'trigger',
			'color'    => '#0077b6',
			'icon'     => 'message-circle',
			'defaults' => array(
				'label'       => 'Zalo OA · tin nhắn khách',
				'instance_id' => '',
				'filter'      => '',
				'guru_id'     => 0,
			),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',       'type' => 'text' ),
				array(
					'name'     => 'instance_id',
					'label'    => 'Zalo OA account',
					'type'     => 'channel_instance_picker',
					'platform' => 'ZALO_OA',
					'hint'     => 'để trống = mọi OA',
				),
				array( 'name' => 'filter',  'label' => 'Bộ lọc chứa từ', 'type' => 'text', 'hint' => 'để trống = nhận mọi message' ),
				array( 'name' => 'guru_id', 'label' => 'Guru (chỉ chạy khi binding khớp)', 'type' => 'guru_picker', 'hint' => 'để trống = mọi guru' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-0.40 — Forward trigger payload downstream.
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — Enrich với wp_user_id cho mọi node sau.
		$out = ( isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ) ? $ctx['trigger'] : array();
		if ( empty( $out['wp_user_id'] ) ) {
			$chat_id = (string) ( $out['chat_id'] ?? '' );
			if ( $chat_id !== '' && class_exists( 'BizCity_User_Resolver' ) ) {
				$resolved = (int) BizCity_User_Resolver::instance()->resolve( $chat_id );
				if ( $resolved > 0 ) {
					$out['wp_user_id'] = $resolved;
				}
			}
		}
		return $out;
	}
}
