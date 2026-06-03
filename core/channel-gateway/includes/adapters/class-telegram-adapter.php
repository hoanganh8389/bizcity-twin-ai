<?php
/**
 * Telegram Channel Adapter — Legacy Shim (PHASE 0.37 M3.W3)
 *
 * Delegates outbound to the legacy `twf_telegram_send_message()` function
 * while providing the standard adapter interface for Gateway Bridge routing.
 *
 * M4 will replace normalize_inbound() with real Telegram webhook parsing.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Telegram_Adapter extends BizCity_Channel_Adapter_Base {

	public function get_platform(): string {
		return 'TELEGRAM';
	}

	/**
	 * Telegram uses numeric chat IDs — no string prefix.
	 */
	public function get_prefix(): string {
		return '';
	}

	public function get_endpoints(): array {
		return [ '/bizcity-channel/v1/webhook/telegram' ];
	}

	/**
	 * Outbound: delegate to legacy twf_telegram_send_message().
	 * M5.W1 will remove this delegation.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		if ( function_exists( 'twf_telegram_send_message' ) ) {
			$result = twf_telegram_send_message( $chat_id, $message );
			return (bool) $result;
		}
		// Fallback: use wp_remote_post if bot token is configured.
		$token = get_option( 'twf_bot_token', '' );
		if ( ! $token ) {
			return false;
		}
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/sendMessage',
			[
				'timeout' => 10,
				'body'    => [
					'chat_id' => $chat_id,
					'text'    => $message,
				],
			]
		);
		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Health: verify bot token resolves via Telegram getMe.
	 */
	protected function test_connection(): array {
		$token = get_option( 'twf_bot_token', '' );
		if ( ! $token ) {
			return [ 'success' => false, 'error' => 'twf_bot_token not set', 'note' => '' ];
		}
		$resp = wp_remote_get(
			'https://api.telegram.org/bot' . $token . '/getMe',
			[ 'timeout' => 5 ]
		);
		if ( is_wp_error( $resp ) ) {
			return [ 'success' => false, 'error' => $resp->get_error_message(), 'note' => '' ];
		}
		$code = wp_remote_retrieve_response_code( $resp );
		return [
			'success' => $code === 200,
			'error'   => $code !== 200 ? "HTTP $code" : '',
			'note'    => '',
		];
	}
}
