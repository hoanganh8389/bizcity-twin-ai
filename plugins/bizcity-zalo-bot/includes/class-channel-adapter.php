<?php
/**
 * Zalo Bot — Channel Adapter for Gateway Bridge
 *
 * Implements BizCity_Channel_Adapter so Zalo Bot registers properly
 * in the twin-ai channel-gateway infrastructure.
 *
 * Responsibilities:
 *   - Platform detection (prefix: zalobot_)
 *   - send_outbound() via BizCity_Zalo_Bot_API
 *   - normalize_inbound() for reference (actual inbound handled by webhook-handler)
 *
 * @package BizCity_Zalo_Bot
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Channel_Adapter implements BizCity_Channel_Adapter {

	public function get_platform(): string {
		return 'ZALO_BOT';
	}

	public function get_prefix(): string {
		return 'zalobot_';
	}

	public function get_endpoints(): array {
		return [ '/zalohook/' ];
	}

	/**
	 * Webhook verification.
	 *
	 * Zalo Bot webhook signature is verified inside class-webhook-handler.php
	 * before this adapter is reached, so always return true here.
	 */
	public function verify_webhook( array $request ): bool {
		return true;
	}

	/**
	 * Normalize inbound Zalo message to standard format.
	 *
	 * Note: The existing webhook handler does its own complex processing
	 * (bot resolution, message type parsing, user linking). This method
	 * serves as a reference for the standard payload format. The actual
	 * bridge flow uses BizCity_Zalo_Bot_Gateway_Bridge::bridge_to_gateway().
	 */
	public function normalize_inbound( array $raw_data ): array {
		$sender   = $raw_data['sender'] ?? [];
		$message  = $raw_data['message'] ?? [];
		$bot_id   = $raw_data['bot_id'] ?? 0;
		$user_id  = $sender['id'] ?? '';

		return [
			'platform'    => 'ZALO_BOT',
			'chat_id'     => 'zalobot_' . $bot_id . '_' . $user_id,
			'user_id'     => $user_id,
			'client_name' => $sender['name'] ?? '',
			'message'     => $message['text'] ?? '',
			'message_id'  => $message['msg_id'] ?? '',
			'attachments' => [],
			'event_type'  => 'message',
			'bot_id'      => (string) $bot_id,
			'raw'         => $raw_data,
		];
	}

	/**
	 * Send message to Zalo user via Bot Platform API.
	 *
	 * Parses chat_id format: zalobot_{bot_id}_{zalo_user_id}
	 * Handles multisite blog switching if needed.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		if ( strpos( $chat_id, 'zalobot_' ) !== 0 ) {
			return false;
		}

		$raw = substr( $chat_id, 8 ); // strip 'zalobot_'
		if ( ! preg_match( '/^(\d+)_(.+)$/', $raw, $m ) ) {
			return false;
		}

		$bot_id       = (int) $m[1];
		$zalo_user_id = $m[2];
		$switched     = false;

		// Multisite: resolve and switch to bot's blog
		if ( is_multisite() && class_exists( 'BizCity_Blog_Resolver' ) ) {
			$target_blog = BizCity_Blog_Resolver::instance()->resolve_bot_blog( $bot_id );
			if ( $target_blog && $target_blog !== get_current_blog_id() ) {
				switch_to_blog( $target_blog );
				$switched = true;
			}
		}

		$sent = false;

		if ( function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			$api = bizcity_get_zalo_bot_api( $bot_id );
			if ( $api ) {
				// Strip markdown for Zalo (does not support rich formatting)
				if ( function_exists( 'bizgpt_zalo_format' ) ) {
					$message = bizgpt_zalo_format( $message );
				}

				$type = $options['type'] ?? 'text';
				if ( $type === 'image' && ! empty( $options['image_url'] ) ) {
					$result = $api->send_photo( $zalo_user_id, $options['image_url'], $message );
				} else {
					$result = $api->send_message( $zalo_user_id, $message );
				}

				$sent = ! is_wp_error( $result );

				if ( ! $sent ) {
					error_log( sprintf( '[Zalo Bot Adapter] ❌ Send failed bot #%d: %s',
						$bot_id, is_wp_error( $result ) ? $result->get_error_message() : 'unknown' ) );
				}
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}

		return $sent;
	}
}
