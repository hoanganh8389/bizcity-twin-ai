<?php
/**
 * BizCity CRM — WhatsApp Cloud API adapter (skeleton).
 *
 * M7.W2 wave-2 deliverable. Full template-approval cache + 24h window
 * enforcement land in W2.task-3.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W2)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_WhatsApp_Cloud extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'whatsapp_cloud'; }
	public function label(): string { return 'WhatsApp Cloud API'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file', 'template' );
	}

	public function normalize_inbound( array $raw ): ?array {
		$entry   = $raw['entry'][0]  ?? null;
		$change  = $entry['changes'][0]['value'] ?? null;
		$msg     = $change['messages'][0] ?? null;
		$contact = $change['contacts'][0] ?? null;
		$phone_number_id = $change['metadata']['phone_number_id'] ?? '';
		if ( ! $msg || ! $phone_number_id ) { return null; }

		$type = (string) ( $msg['type'] ?? 'text' );
		$text = '';
		$attachments = array();
		if ( $type === 'text' ) {
			$text = (string) ( $msg['text']['body'] ?? '' );
		} elseif ( $type === 'image' ) {
			$attachments[] = array( 'file_type' => 'image', 'data_url' => '', 'meta' => $msg['image'] ?? array() );
		} elseif ( $type === 'document' ) {
			$attachments[] = array( 'file_type' => 'file', 'data_url' => '', 'meta' => $msg['document'] ?? array() );
		}

		return array(
			'inbox_ref'          => (string) $phone_number_id,
			'inbox_name'         => 'WhatsApp ' . $phone_number_id,
			'source_id'          => (string) ( $msg['from'] ?? '' ),
			'contact_name'       => (string) ( $contact['profile']['name'] ?? ( $msg['from'] ?? 'WhatsApp user' ) ),
			'contact_avatar'     => null,
			'content'            => $text,
			'content_type'       => $type === 'text' ? 'text' : ( $attachments ? $attachments[0]['file_type'] : 'text' ),
			'attachments'        => $attachments,
			'external_source_id' => 'wa:' . (string) ( $msg['id'] ?? '' ),
			'received_at'        => isset( $msg['timestamp'] )
				? gmdate( 'Y-m-d H:i:s', (int) $msg['timestamp'] )
				: current_time( 'mysql' ),
		);
	}

	public function send( array $conversation, array $message ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$settings = $inbox['settings_json'] ? json_decode( $inbox['settings_json'], true ) : array();
		if ( ! is_array( $settings ) ) { $settings = array(); }

		$pid = (string) ( $settings['phone_number_id'] ?? '' );
		$tok = (string) ( $settings['access_token']    ?? '' );
		if ( $pid === '' || $tok === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'missing phone_number_id or access_token' );
		}

		$to = $this->resolve_recipient_msisdn( $conversation );
		if ( $to === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve recipient phone' );
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array(
				'preview_url' => false,
				'body'        => (string) ( $message['content'] ?? '' ),
			),
		);
		$resp = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$pid}/messages",
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
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'WA Cloud: ' . $err );
		}
		$mid = (string) ( $decoded['messages'][0]['id'] ?? '' );
		return array(
			'success'            => true,
			'external_source_id' => $mid !== '' ? ( 'wa:' . $mid ) : null,
			'error'              => null,
		);
	}

	private function resolve_recipient_msisdn( array $conversation ): string {
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
					'name'     => 'phone_number_id',
					'label'    => __( 'Phone Number ID', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
					'help'     => __( 'Lấy từ WhatsApp Manager → Phone numbers.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'business_account_id',
					'label'    => __( 'WhatsApp Business Account ID (WABA)', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
				),
				array(
					'name'     => 'access_token',
					'label'    => __( 'System User Access Token', 'bizcity-twin-crm' ),
					'type'     => 'password',
					'required' => true,
					'help'     => __( 'Token vĩnh viễn từ Meta Business Settings → System Users.', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'verify_token',
					'label'    => __( 'Webhook verify token', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => true,
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				'url'    => rest_url( BIZCITY_CRM_REST_NS . '/webhooks/whatsapp' ),
				'note'   => __( 'Đăng ký URL này trong Meta App Dashboard → WhatsApp → Configuration.', 'bizcity-twin-crm' ),
			),
			'docs_url' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/',
		);
	}

	public function verify( array $config ): array {
		$pid   = trim( (string) ( $config['phone_number_id']     ?? '' ) );
		$waba  = trim( (string) ( $config['business_account_id'] ?? '' ) );
		$tok   = trim( (string) ( $config['access_token']        ?? '' ) );
		if ( $pid === '' || $waba === '' || $tok === '' ) {
			return array( 'ok' => false, 'error' => 'phone_number_id / business_account_id / access_token bắt buộc.' );
		}

		// Cheap remote sanity check — GET /{phone_number_id}?fields=display_phone_number.
		$resp = wp_remote_get(
			"https://graph.facebook.com/v19.0/{$pid}?fields=display_phone_number,verified_name",
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'Bearer ' . $tok ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => 'Network error: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code !== 200 || ! is_array( $body ) || isset( $body['error'] ) ) {
			$err = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : ( 'HTTP ' . $code );
			return array( 'ok' => false, 'error' => 'Meta Graph từ chối: ' . $err );
		}
		$display = (string) ( $body['display_phone_number'] ?? $pid );
		return array(
			'ok'             => true,
			'channel_ref_id' => $pid,
			'name'           => 'WhatsApp ' . $display,
		);
	}
}
