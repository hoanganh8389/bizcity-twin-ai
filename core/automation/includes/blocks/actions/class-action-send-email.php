<?php
/**
 * Action: Send email via wp_mail (delegate qua core/smtp khi có).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Send_Email extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'action.send_email'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Gửi email',
			'short'    => 'send_email',
			'category' => 'output',
			'color'    => '#be185d',
			'icon'     => 'mail',
			'defaults' => array( 'label' => 'send_email', 'to' => '', 'subject' => '', 'body' => '' ),
			'fields'   => array(
				array( 'name' => 'label',   'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'to',      'label' => 'Người nhận',   'type' => 'text' ),
				array( 'name' => 'subject', 'label' => 'Tiêu đề',      'type' => 'text' ),
				array( 'name' => 'body',    'label' => 'Nội dung',     'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$to      = sanitize_email( (string) $this->resolve( $data['to'] ?? '', $ctx ) );
		$subject = (string) $this->resolve( $data['subject'] ?? '', $ctx );
		$body    = (string) $this->resolve( $data['body'] ?? '', $ctx );

		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', 'send_email: địa chỉ không hợp lệ.', array( 'to' => $to ) );
		}
		if ( $subject === '' ) {
			return new WP_Error( 'empty_subject', 'send_email: subject rỗng.' );
		}

		// PG-S9 — dry-run mock.
		if ( ! empty( $ctx['_dry_run'] ) ) {
			return array( 'queued' => true, 'to' => $to, 'dry' => true, 'subject' => $subject );
		}

		$ok = wp_mail( $to, $subject, $body );
		return array( 'queued' => (bool) $ok, 'to' => $to );
	}
}
