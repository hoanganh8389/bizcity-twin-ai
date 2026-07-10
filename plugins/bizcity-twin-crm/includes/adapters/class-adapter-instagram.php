<?php
/**
 * BizCity CRM — Instagram Direct adapter (skeleton).
 *
 * M7.W2 wave-1 deliverable — registers a wizard-discoverable channel so admins
 * can pre-create an inbox row. Inbound/outbound wiring is staged for W2.task-2
 * once the FB Bot plugin's IG webhook bridge is published.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W2)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Instagram extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'instagram'; }
	public function label(): string { return 'Instagram Direct'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'quick_reply' );
	}

	public function normalize_inbound( array $raw ): ?array {
		// IG Graph API webhook payload bridges to FB messaging shape; reuse FB
		// adapter normalizer once IG webhook is wired in bizcity-facebook-bot.
		$ig_id = (string) ( $raw['ig_account_id'] ?? '' );
		$uid   = (string) ( $raw['from_user_id']  ?? '' );
		$text  = (string) ( $raw['message_text']  ?? '' );
		$mid   = (string) ( $raw['message_id']    ?? '' );
		if ( $ig_id === '' || $uid === '' || $mid === '' ) { return null; }

		return array(
			'inbox_ref'          => $ig_id,
			'inbox_name'         => 'Instagram ' . ( $raw['ig_account_name'] ?? $ig_id ),
			'source_id'          => $uid,
			'contact_name'       => (string) ( $raw['from_user_name'] ?? ( 'IG ' . substr( $uid, -6 ) ) ),
			'contact_avatar'     => $raw['from_avatar'] ?? null,
			'content'            => $text,
			'content_type'       => 'text',
			'attachments'        => array(),
			'external_source_id' => 'ig:' . $mid,
			'received_at'        => (string) ( $raw['received_at'] ?? current_time( 'mysql' ) ),
		);
	}

	public function send( array $conversation, array $message ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$settings = $inbox['settings_json'] ? json_decode( $inbox['settings_json'], true ) : array();
		if ( ! is_array( $settings ) ) { $settings = array(); }

		$ig_id = (string) ( $settings['ig_account_id']  ?? '' );
		$page  = (string) ( $settings['linked_page_id'] ?? '' );
		$tok   = (string) ( $settings['access_token']   ?? '' );
		// Fallback: reuse the page's stored access_token from FB Bot plugin.
		if ( $tok === '' && $page !== '' && function_exists( 'bizcity_facebook_bot_get_page_token' ) ) {
			$tok = (string) bizcity_facebook_bot_get_page_token( $page );
		}
		if ( $ig_id === '' || $tok === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'missing ig_account_id or access_token' );
		}

		$psid = $this->resolve_recipient_psid( $conversation );
		if ( $psid === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve recipient PSID' );
		}

		$body = array(
			'recipient'      => array( 'id' => $psid ),
			'message'        => array( 'text' => (string) ( $message['content'] ?? '' ) ),
			'messaging_type' => 'RESPONSE',
		);
		$resp = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$ig_id}/messages",
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $tok,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'network: ' . $resp->get_error_message() );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $decoded ) || isset( $decoded['error'] ) ) {
			$err = $decoded['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'IG Graph: ' . $err );
		}
		$mid = (string) ( $decoded['message_id'] ?? '' );
		return array(
			'success'            => true,
			'external_source_id' => $mid !== '' ? ( 'ig:' . $mid ) : null,
			'error'              => null,
		);
	}

	private function resolve_recipient_psid( array $conversation ): string {
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( ! $ci_id ) { return ''; }
		$tbl = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
	}

	public function setup_form_schema(): array {
		return array(
			'fields'   => array(
				array(
					'name'     => 'ig_account_id',
					'label'    => __( 'Instagram Business Account ID', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'help'     => __( 'Lấy từ Meta Business Suite — IG account đã liên kết với FB Page.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'linked_page_id',
					'label'    => __( 'Linked Facebook Page ID', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'help'     => __( 'Page mà IG account đã connect — dùng cùng access_token.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'access_token',
					'label'    => __( 'Page Access Token (optional)', 'bizcity-twin-crm' ),
					'type'     => 'password',
					'required' => false,
					'help'     => __( 'Để trống nếu plugin bizcity-facebook-bot đã lưu token cho Page tương ứng.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'display_name',
					'label'    => __( 'Tên hiển thị', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => false,
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				'url'    => home_url( '/wp-json/bizcity-fbbot/v1/webhook' ),
				'note'   => __( 'IG dùng chung webhook với FB Page (Meta Graph). Cần subscribe field "messages" + "message_reactions" trong FB App.', 'bizcity-twin-crm' ),
			),
			'docs_url' => 'https://developers.facebook.com/docs/messenger-platform/instagram',
		);
	}

	public function verify( array $config ): array {
		$ig = trim( (string) ( $config['ig_account_id']  ?? '' ) );
		$pg = trim( (string) ( $config['linked_page_id'] ?? '' ) );
		if ( $ig === '' || ! ctype_digit( $ig ) ) {
			return array( 'ok' => false, 'error' => 'IG account ID phải là chuỗi số.' );
		}
		if ( $pg === '' || ! ctype_digit( $pg ) ) {
			return array( 'ok' => false, 'error' => 'Linked Page ID phải là chuỗi số.' );
		}
		$name = trim( (string) ( $config['display_name'] ?? '' ) );
		return array(
			'ok'             => true,
			'channel_ref_id' => $ig,
			'name'           => $name !== '' ? $name : ( 'Instagram ' . $ig ),
			'hints'          => array( __( 'Inbound/outbound chưa active — chờ M7.W2 hoàn thiện.', 'bizcity-twin-crm' ) ),
		);
	}
}
