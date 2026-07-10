<?php
/**
 * BizCity CRM — Telegram Bot adapter (skeleton).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M7.W3)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Telegram extends BizCity_CRM_Adapter_Base {

	public function code(): string  { return 'telegram'; }
	public function label(): string { return 'Telegram Bot'; }

	public function capabilities(): array {
		return array( 'text', 'image', 'file', 'quick_reply' );
	}

	public function normalize_inbound( array $raw ): ?array {
		$msg = $raw['message'] ?? null;
		if ( ! $msg || empty( $msg['from']['id'] ) ) { return null; }

		$bot_id = (string) ( $raw['_bot_id'] ?? '' ); // injected by webhook handler
		$chat_id = (string) ( $msg['chat']['id'] ?? '' );
		$inbox_ref = $bot_id !== '' ? $bot_id : $chat_id;

		$text = (string) ( $msg['text'] ?? $msg['caption'] ?? '' );
		$attachments = array();
		if ( ! empty( $msg['photo'] ) ) {
			$attachments[] = array( 'file_type' => 'image', 'data_url' => '', 'meta' => array( 'photo_sizes' => $msg['photo'] ) );
		}
		if ( ! empty( $msg['document'] ) ) {
			$attachments[] = array( 'file_type' => 'file', 'data_url' => '', 'meta' => $msg['document'] );
		}

		$from_name = trim( ( $msg['from']['first_name'] ?? '' ) . ' ' . ( $msg['from']['last_name'] ?? '' ) );
		if ( $from_name === '' ) { $from_name = (string) ( $msg['from']['username'] ?? ( 'TG ' . $msg['from']['id'] ) ); }

		return array(
			'inbox_ref'          => $inbox_ref,
			'inbox_name'         => 'Telegram bot ' . $inbox_ref,
			'source_id'          => (string) $msg['from']['id'],
			'contact_name'       => $from_name,
			'contact_avatar'     => null,
			'content'            => $text,
			'content_type'       => $attachments ? $attachments[0]['file_type'] : 'text',
			'attachments'        => $attachments,
			'external_source_id' => 'tg:' . $bot_id . ':' . (string) ( $msg['message_id'] ?? '' ),
			'received_at'        => isset( $msg['date'] )
				? gmdate( 'Y-m-d H:i:s', (int) $msg['date'] )
				: current_time( 'mysql' ),
		);
	}

	public function send( array $conversation, array $message ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'inbox not found' );
		}
		$settings = $inbox['settings_json'] ? json_decode( $inbox['settings_json'], true ) : array();
		$token = (string) ( $settings['bot_token'] ?? '' );
		if ( $token === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'missing bot_token' );
		}
		$chat_id = $this->resolve_chat_id_from_conversation( $conversation );
		if ( $chat_id === '' ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'cannot resolve telegram chat_id' );
		}
		$resp = wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
			'timeout' => 10,
			'body'    => array(
				'chat_id' => $chat_id,
				'text'    => (string) ( $message['content'] ?? '' ),
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'external_source_id' => null, 'error' => $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			return array(
				'success'            => false,
				'external_source_id' => null,
				'error'              => isset( $body['description'] ) ? (string) $body['description'] : 'telegram_error',
			);
		}
		$mid = (string) ( $body['result']['message_id'] ?? '' );
		return array( 'success' => true, 'external_source_id' => $mid, 'error' => null );
	}

	public function setup_form_schema(): array {
		return array(
			'fields'   => array(
				array(
					'name'     => 'bot_token',
					'label'    => __( 'Bot Token', 'bizcity-twin-crm' ),
					'type'     => 'password',
					'required' => true,
					'help'     => __( 'Token cấp bởi @BotFather (định dạng 123456:ABC-DEF...).', 'bizcity-twin-crm' ),
				),
				array(
					'name'     => 'webhook_secret',
					'label'    => __( 'Webhook secret token', 'bizcity-twin-crm' ),
					'type'     => 'text',
					'required' => false,
					'help'     => __( 'Tùy chọn — Telegram sẽ gửi header X-Telegram-Bot-Api-Secret-Token để xác thực.', 'bizcity-twin-crm' ),
				),
			),
			'webhook'  => array(
				'method' => 'POST',
				'url'    => rest_url( BIZCITY_CRM_REST_NS . '/webhooks/telegram' ),
				'note'   => __( 'Sau khi save, gọi Telegram setWebhook với URL này. Wizard sẽ tự gọi nếu verify thành công.', 'bizcity-twin-crm' ),
			),
			'docs_url' => 'https://core.telegram.org/bots/api#setwebhook',
		);
	}

	public function verify( array $config ): array {
		$tok = trim( (string) ( $config['bot_token'] ?? '' ) );
		if ( $tok === '' || strpos( $tok, ':' ) === false ) {
			return array( 'ok' => false, 'error' => 'Bot token không hợp lệ.' );
		}
		$resp = wp_remote_get( "https://api.telegram.org/bot{$tok}/getMe", array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => 'Network error: ' . $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			return array( 'ok' => false, 'error' => 'Telegram getMe thất bại: ' . ( $body['description'] ?? 'unknown' ) );
		}
		$bot_id   = (string) ( $body['result']['id']       ?? '' );
		$bot_name = (string) ( $body['result']['username'] ?? $bot_id );
		return array(
			'ok'             => true,
			'channel_ref_id' => $bot_id,
			'name'           => '@' . $bot_name,
		);
	}

	private function resolve_chat_id_from_conversation( array $conversation ): string {
		global $wpdb;
		$ci_id = (int) ( $conversation['contact_inbox_id'] ?? 0 );
		if ( ! $ci_id ) { return ''; }
		$tbl = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT source_id FROM {$tbl} WHERE id = %d", $ci_id ) );
	}
}
