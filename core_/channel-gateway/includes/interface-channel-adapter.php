<?php
/**
 * Channel Adapter Interface
 *
 * Mọi channel plugin (Zalo Bot, Telegram, Facebook…) implements interface này,
 * đăng ký adapter qua hook `bizcity_register_channel`.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_Channel_Adapter {

	/**
	 * Platform identifier.
	 *
	 * @return string E.g. 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_BOT', 'FACEBOOK'.
	 */
	public function get_platform(): string;

	/**
	 * Chat ID prefix for this platform.
	 *
	 * Used by Gateway Bridge to detect which adapter owns a chat_id.
	 * E.g. 'zalobot_', 'fb_', 'zalo_'. Telegram returns '' (numeric IDs).
	 *
	 * @return string Prefix string (including trailing underscore if applicable).
	 */
	public function get_prefix(): string;

	/**
	 * Webhook endpoints this adapter handles.
	 *
	 * Gateway Bridge registers rewrite rules / REST routes for these.
	 * E.g. ['/zalohook/'] or ['/bizcity/v1/webhook/telegram'].
	 *
	 * @return string[] List of endpoint paths.
	 */
	public function get_endpoints(): array;

	/**
	 * Verify webhook authenticity (token, signature, etc.).
	 *
	 * @param array $request Associative array with 'headers', 'body', 'params'.
	 * @return bool True if verified.
	 */
	public function verify_webhook( array $request ): bool;

	/**
	 * Normalize inbound webhook payload → standard trigger format.
	 *
	 * @param array $raw_data Raw webhook data.
	 * @return array Standard payload:
	 *   'platform'    => string,
	 *   'chat_id'     => string (with platform prefix),
	 *   'user_id'     => string (platform user ID),
	 *   'client_name' => string,
	 *   'message'     => string,
	 *   'attachments' => array,
	 *   'event_type'  => string ('message'|'follow'|'unfollow'),
	 *   'raw'         => array,
	 */
	public function normalize_inbound( array $raw_data ): array;

	/**
	 * Send outbound message to user.
	 *
	 * @param string $chat_id  Full chat_id with platform prefix.
	 * @param string $message  Message text.
	 * @param array  $options  Extra options (type, image_url, etc.).
	 * @return bool True on success.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool;
}
