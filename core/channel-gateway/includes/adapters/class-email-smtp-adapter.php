<?php
/**
 * Email / SMTP Channel Adapter (PHASE 0.37 M3.W3)
 *
 * Routes outbound messages as emails via wp_mail().
 * The "chat_id" for email is formatted as: email_{md5(email_address)}.
 * The actual address is resolved from a stored account or a lookup.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Email_SMTP_Adapter extends BizCity_Channel_Adapter_Base {

	public function get_platform(): string {
		return 'SMTP';
	}

	public function get_prefix(): string {
		return 'email_';
	}

	public function get_endpoints(): array {
		// Email does not have an inbound webhook in this iteration.
		return [];
	}

	public function describe_capabilities(): array {
		return [ 'outbound' ]; // inbound requires mailbox polling (M4+)
	}

	/**
	 * Outbound: send via wp_mail().
	 *
	 * chat_id format: email_{recipient_email} OR email_{md5(email)}.
	 * The raw email is passed in $options['to'] if available.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		$to = $options['to'] ?? '';
		if ( ! $to ) {
			// Strip prefix and try to unmask.
			$raw = str_replace( 'email_', '', $chat_id );
			$to  = is_email( $raw ) ? $raw : '';
		}
		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}
		$subject = $options['subject'] ?? get_bloginfo( 'name' ) . ' — Tin nhắn';
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$body    = nl2br( wp_kses( $message, [ 'br' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [] ] ] ) );

		return wp_mail( $to, $subject, $body, $headers );
	}

	protected function test_connection(): array {
		// SMTP test: verify wp_mail is callable (SMTP plugin configured or native).
		if ( ! function_exists( 'wp_mail' ) ) {
			return [ 'success' => false, 'error' => 'wp_mail() not available', 'note' => '' ];
		}
		$smtp = get_option( 'bizcity_smtp_settings', [] );
		return [
			'success' => true,
			'error'   => '',
			'note'    => empty( $smtp ) ? 'Using WP default mailer' : 'Custom SMTP configured',
		];
	}
}
