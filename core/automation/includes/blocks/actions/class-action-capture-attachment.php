<?php
/**
 * Action: Capture attachment URL into pending_state (multi-turn).
 *
 * Replaces legacy `twf_handle_image_attachment` transient pattern.
 * Stores media URL keyed by canonical chat_id, with TTL (default 15').
 *
 * Output ctx fields: { attachment_url, ttl }.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Capture_Attachment extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.capture_attachment'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Lưu ảnh / file đính kèm',
			'short'    => 'capture_attachment',
			'category' => 'state',
			'color'    => '#0891b2',
			'icon'     => 'paperclip',
			'defaults' => array(
				'label'    => 'capture_attachment',
				'url'      => '{{trigger.media_url}}',
				'ttl_min'  => 15,
			),
			'fields' => array(
				array( 'name' => 'label',   'label' => 'Tên hiển thị',         'type' => 'text' ),
				array( 'name' => 'url',     'label' => 'URL nguồn (template)', 'type' => 'text' ),
				array( 'name' => 'ttl_min', 'label' => 'TTL (phút)',           'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return new WP_Error( 'no_chat_id', 'capture_attachment: trigger.chat_id rỗng.' );
		}
		$url = (string) $this->resolve( $data['url'] ?? '{{trigger.media_url}}', $ctx );
		if ( $url === '' ) {
			return array( 'attachment_url' => '', '_skipped' => 'no_url' );
		}
		$ttl = max( 1, (int) ( $data['ttl_min'] ?? 15 ) ) * MINUTE_IN_SECONDS;

		BizCity_Automation_Pending_State::patch( $chat_id, array(
			'attachment_url' => $url,
		), $ttl );

		return array( 'attachment_url' => $url, 'ttl' => $ttl );
	}
}
