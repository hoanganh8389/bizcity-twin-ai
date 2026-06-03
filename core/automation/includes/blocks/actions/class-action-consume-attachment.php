<?php
/**
 * Action: Consume pending attachment + clear slot.
 *
 * Đọc `attachment_url` đã lưu từ turn trước (qua `action.capture_attachment`),
 * trả ra ctx output để các block sau dùng (vd `action.publish_wp_post`,
 * `action.publish_fb_post`). Mặc định CLEAR pending slot sau khi đọc — đảm
 * bảo không leak state sang turn sau.
 *
 * Output: { attachment_url, intent, slots, found }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Consume_Attachment extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.consume_attachment'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Lấy ảnh/file đã lưu',
			'short'    => 'consume_attachment',
			'category' => 'state',
			'color'    => '#0891b2',
			'icon'     => 'download',
			'defaults' => array(
				'label'      => 'consume_attachment',
				'clear_slot' => 1,
			),
			'fields' => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị',        'type' => 'text' ),
				array( 'name' => 'clear_slot', 'label' => 'Xoá slot sau khi đọc','type' => 'checkbox' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return new WP_Error( 'no_chat_id', 'consume_attachment: trigger.chat_id rỗng.' );
		}

		// Resume payload đã được matcher đặt vào ctx.trigger._resume.
		$resume = $ctx['trigger']['_resume'] ?? array();
		$state  = is_array( $resume ) && ! empty( $resume )
			? $resume
			: BizCity_Automation_Pending_State::get( $chat_id );

		$url    = (string) ( $state['attachment_url'] ?? '' );
		$intent = (string) ( $state['intent']         ?? '' );
		$slots  = is_array( $state['slots'] ?? null ) ? $state['slots'] : array();

		if ( ! empty( $data['clear_slot'] ) ) {
			BizCity_Automation_Pending_State::clear( $chat_id );
		}

		return array(
			'attachment_url' => $url,
			'intent'         => $intent,
			'slots'          => $slots,
			'found'          => $url !== '',
		);
	}
}
