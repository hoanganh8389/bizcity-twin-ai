<?php
/**
 * Trigger: Zalo inbound message.
 *
 * Runtime: hook `bizcity_channel_inbound` (BE-4) sẽ enqueue run, payload chứa
 * `{ channel: 'zalo', text, user_id, page_id, ... }`. Execute ở đây chỉ
 * forward payload từ ctx['trigger'] về cho downstream nodes.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Triggers
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Trigger_Zalo extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'trigger.zalo_inbound'; }
	public function kind(): string { return 'trigger'; }
	public function meta(): array {
		// [2026-06-24 Johnny Chu] PHASE-0.40 — Renamed label to 'Zalo Bot' to distinguish from Zalo OA (Zone 1).
		return array(
			'label'    => 'Zalo Bot · tin nhắn mới',
			'short'    => 'Zalo Bot msg',
			'category' => 'trigger',
			'color'    => '#7c3aed',
			'icon'     => 'message-circle',
			'defaults' => array( 'label' => 'Zalo Bot · tin nhắn mới', 'instance_id' => '', 'filter' => '', 'guru_id' => 0 ),
			'fields'   => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',    'type' => 'text' ),
				array( 'name' => 'instance_id', 'label' => 'Zalo Bot account', 'type' => 'channel_instance_picker', 'platform' => 'ZALO_BOT', 'hint' => 'để trống = mọi bot' ),
				array( 'name' => 'filter',      'label' => 'Bộ lọc chứa từ',  'type' => 'text', 'hint' => 'để trống = nhận mọi message' ),
				// [2026-06-02 Johnny Chu] GURU W1 — cross-cutting guru filter.
				array( 'name' => 'guru_id',     'label' => 'Guru (chỉ chạy khi binding khớp)', 'type' => 'guru_picker', 'hint' => 'để trống = mọi guru' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — Enrich trigger output với wp_user_id.
		// Resolve ngay tại trigger để mọi node sau đều có {{trigger.wp_user_id}} sẵn.
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
