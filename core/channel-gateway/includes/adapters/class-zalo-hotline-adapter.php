<?php
/**
 * Zalo Hotline (Zalo Personal) Channel Adapter — Legacy Shim (PHASE 0.37 M3.W3)
 *
 * Zalo Personal / Hotline uses the personal account API (khác Zalo OA/Bot).
 * Outbound delegates to legacy biz_send_message() / send_zalo_botbanhang().
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Hotline_Adapter extends BizCity_Channel_Adapter_Base {

	public function get_platform(): string {
		return 'ZALO_PERSONAL';
	}

	public function get_prefix(): string {
		return 'zalo_';
	}

	public function get_endpoints(): array {
		return [ '/bizcity-channel/v1/webhook/zalo-personal' ];
	}

	/**
	 * Outbound: try send_zalo_botbanhang(), fall back to biz_send_message().
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		$type    = $options['type'] ?? 'text';
		$bot_id  = $options['bot_id'] ?? '';
		$user_id = str_replace( 'zalo_', '', $chat_id );

		if ( function_exists( 'send_zalo_botbanhang' ) ) {
			$result = send_zalo_botbanhang( $user_id, $message, $type, $bot_id );
			if ( $result ) {
				return true;
			}
		}
		if ( function_exists( 'biz_send_message' ) ) {
			biz_send_message( $chat_id, $message );
			return true;
		}
		return false;
	}

	protected function test_connection(): array {
		$ok = function_exists( 'send_zalo_botbanhang' ) || function_exists( 'biz_send_message' );
		return [
			'success' => $ok,
			'error'   => $ok ? '' : 'send_zalo_botbanhang / biz_send_message not loaded',
			'note'    => 'Zalo Personal — uses personal access token',
		];
	}
}
