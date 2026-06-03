<?php
/**
 * AdminChat Channel Adapter — Legacy Shim (PHASE 0.37 M3.W3)
 *
 * AdminChat is the internal staff chat embedded in wp-admin.
 * Outbound delegates to legacy functions.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 */

defined( 'ABSPATH' ) || exit;

class BizCity_AdminChat_Adapter extends BizCity_Channel_Adapter_Base {

	public function get_platform(): string {
		return 'ADMINCHAT';
	}

	public function get_prefix(): string {
		return 'adminchat_';
	}

	public function get_endpoints(): array {
		return [ '/bizcity-channel/v1/webhook/adminchat' ];
	}

	/**
	 * Outbound: push message to admin chat thread.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		if ( function_exists( 'biz_send_adminchat_message' ) ) {
			return (bool) biz_send_adminchat_message( $chat_id, $message, $options );
		}
		if ( class_exists( 'BizCity_AdminChat_Database' ) && method_exists( 'BizCity_AdminChat_Database', 'insert_bot_message' ) ) {
			$db = BizCity_AdminChat_Database::instance();
			return (bool) $db->insert_bot_message( [
				'session_id' => str_replace( [ 'adminchat_', 'admin_chat_', 'admin_' ], '', $chat_id ),
				'message'    => $message,
				'type'       => $options['type'] ?? 'text',
			] );
		}
		// Fallback: write to WP transient for polling
		$key = 'bizcity_adminchat_queue_' . md5( $chat_id );
		$q   = get_transient( $key ) ?: [];
		$q[] = [ 'message' => $message, 'at' => time() ];
		set_transient( $key, $q, 300 );
		return true;
	}

	protected function test_connection(): array {
		return [
			'success' => true,
			'error'   => '',
			'note'    => 'AdminChat is local — no external API',
		];
	}
}
