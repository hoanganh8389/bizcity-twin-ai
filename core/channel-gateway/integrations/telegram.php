<?php
/**
 * Integration: Telegram — Bot Token.
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Telegram extends BizCity_Integration {

	protected string $code     = 'telegram';
	protected string $category = 'messenger';
	protected string $logo     = 'TE';
	protected string $name     = 'Telegram';
	protected string $desc     = 'Kết nối Telegram Bot API';
	protected int    $order    = 7;

	public function get_settings(): array {
		return [
			'name'      => [ 'type' => 'input', 'label' => 'Tên cấu hình', 'plh' => 'Tên nội bộ để phân biệt', 'default' => '' ],
			'bot_token' => [ 'type' => 'input', 'label' => 'Bot Token *',   'plh' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', 'encrypt' => true, 'default' => '' ],
			'chat_id'   => [ 'type' => 'input', 'label' => 'Chat ID',      'plh' => 'Chat ID (số) hoặc @channelname', 'default' => '' ],
		];
	}

	public function do_test(): void {
		$token = $this->get_decrypted_param( 'bot_token' );
		if ( empty( $token ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = 'Bot Token là bắt buộc';
			return;
		}

		$resp = wp_remote_get( "https://api.telegram.org/bot{$token}/getMe", [ 'timeout' => 10 ] );
		if ( is_wp_error( $resp ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $resp->get_error_message();
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['ok'] ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $body['description'] ?? 'Invalid bot token';
			return;
		}

		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}
}
