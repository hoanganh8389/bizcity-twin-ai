<?php
/**
 * Facebook — Channel Adapter for Gateway Bridge
 *
 * Implements BizCity_Channel_Adapter so Facebook registers properly
 * in the twin-ai channel-gateway infrastructure.
 *
 * Channel type: Distribution (primarily publish content + one-shot replies).
 * Messenger inbound messages are bridged to the gateway trigger pipeline
 * so Channel Role 'facebook' (knowledge + skill only) is applied.
 *
 * @package BizCity\TwinAI\ToolFacebook
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Channel_Adapter implements BizCity_Channel_Adapter {

	public function get_platform(): string {
		return 'FACEBOOK';
	}

	public function get_prefix(): string {
		return 'fb_';
	}

	public function get_endpoints(): array {
		return [ '/bizfbhook/' ];
	}

	/**
	 * Verify Facebook webhook signature (X-Hub-Signature-256).
	 *
	 * Only enforced if bztfb_app_secret is configured.
	 */
	public function verify_webhook( array $request ): bool {
		$app_secret = get_option( 'bztfb_app_secret', '' );
		if ( empty( $app_secret ) ) {
			return true; // dev mode — signature not required
		}

		$body   = $request['body'] ?? '';
		$header = $request['headers']['x-hub-signature-256']
		       ?? $request['headers']['HTTP_X_HUB_SIGNATURE_256']
		       ?? '';

		if ( empty( $header ) ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );
		return hash_equals( $expected, $header );
	}

	/**
	 * Normalize inbound Messenger message to standard format.
	 *
	 * Called from the FB webhook handler after basic processing,
	 * to produce a standard gateway payload.
	 */
	public function normalize_inbound( array $raw_data ): array {
		$entry     = $raw_data;
		$page_id   = $entry['page_id'] ?? '';
		$psid      = $entry['psid'] ?? '';
		$text      = $entry['text'] ?? '';
		$mid       = $entry['message_id'] ?? '';
		$name      = $entry['sender_name'] ?? '';

		$attachments = [];
		foreach ( ( $entry['attachments'] ?? [] ) as $att ) {
			$attachments[] = [
				'type' => $att['type'] ?? '',
				'url'  => $att['payload']['url'] ?? ( $att['url'] ?? '' ),
			];
		}

		return [
			'platform'    => 'FACEBOOK',
			'chat_id'     => 'fb_' . $page_id . '_' . $psid,
			'user_id'     => $psid,
			'client_name' => $name,
			'message'     => $text,
			'message_id'  => $mid,
			'attachments' => $attachments,
			'event_type'  => 'message',
			'bot_id'      => $page_id,
			'raw'         => $raw_data,
		];
	}

	/**
	 * Send message to Facebook user via Graph API.
	 *
	 * Parses chat_id format: fb_{page_id}_{psid} or fb_{psid}
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		$stripped = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );

		$page_id = '';
		$psid    = $stripped;

		// Try to extract page_id from fb_{page_id}_{psid}
		if ( preg_match( '/^(\d+)_(\d+)$/', $stripped, $m ) ) {
			$page_id = $m[1];
			$psid    = $m[2];
		}

		// Standalone Graph API (bizcity-tool-facebook)
		if ( class_exists( 'BizCity_FB_Database' ) && class_exists( 'BizCity_FB_Graph_API' ) ) {
			$page = $page_id ? BizCity_FB_Database::get_page( $page_id ) : null;

			if ( ! $page ) {
				// Fallback: first connected page
				$pages = BizCity_FB_Database::get_all_pages();
				$page  = ! empty( $pages ) ? $pages[0] : null;
			}

			if ( $page && ! empty( $page['page_access_token'] ) ) {
				$api    = new BizCity_FB_Graph_API( $page['page_access_token'], $page['page_id'] ?? $page_id );
				$result = $api->send_message( $psid, $message );
				$sent   = ! empty( $result['message_id'] ) || ( isset( $result['success'] ) && $result['success'] );

				if ( $sent ) {
					// Log outbound message
					BizCity_FB_Database::log_message( [
						'psid'         => $psid,
						'page_id'      => $page['page_id'] ?? $page_id,
						'direction'    => 'out',
						'message_type' => 'text',
						'message_text' => $message,
					] );
				}

				return $sent;
			}
		}

		// Legacy fallback
		if ( function_exists( 'fbm_send_text_to_user' ) ) {
			return (bool) fbm_send_text_to_user( $psid, $message );
		}

		return false;
	}
}
