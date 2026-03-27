<?php
/**
 * Gateway Sender — Unified outbound message dispatch
 *
 * Looks up the appropriate adapter by chat_id prefix and delegates send_outbound().
 * Falls back to legacy send functions when no adapter is registered.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Gateway_Sender {

	/** @var self|null */
	private static $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Send a message to any channel.
	 *
	 * @param string $chat_id  Chat ID with platform prefix.
	 * @param string $message  Message text.
	 * @param string $type     Type: 'text', 'image', 'file'.
	 * @param array  $extra    Extra data (image_url, caption, bot_id…).
	 * @return array ['sent' => bool, 'error' => string, 'platform' => string]
	 */
	public function send( string $chat_id, string $message, string $type = 'text', array $extra = [] ): array {
		$chat_id = trim( $chat_id );
		if ( $chat_id === '' ) {
			return [ 'sent' => false, 'error' => 'Empty chat_id', 'platform' => '' ];
		}

		$bridge   = BizCity_Gateway_Bridge::instance();
		$platform = $bridge->detect_platform( $chat_id );

		/**
		 * Filter outbound message before sending.
		 *
		 * @param string $message  Message text.
		 * @param string $chat_id  Target chat ID.
		 * @param string $platform Detected platform.
		 */
		$message = apply_filters( 'bizcity_channel_before_send', $message, $chat_id, $platform );

		error_log( sprintf( '[Channel Gateway] 📤 Sending to %s | platform=%s | type=%s', $chat_id, $platform, $type ) );

		// Try registered adapter first
		$adapter = $bridge->get_adapter( $platform );
		if ( $adapter ) {
			$options = array_merge( $extra, [ 'type' => $type ] );
			$sent    = $adapter->send_outbound( $chat_id, $message, $options );
			$result  = [
				'sent'     => $sent,
				'error'    => $sent ? '' : 'Adapter send_outbound returned false',
				'platform' => $platform,
			];

			/**
			 * Fires after an outbound message is sent.
			 *
			 * @param array  $result   Send result.
			 * @param string $chat_id  Target chat ID.
			 * @param string $platform Platform identifier.
			 */
			do_action( 'bizcity_channel_after_send', $result, $chat_id, $platform );

			$this->log_outbound( $chat_id, $message, $platform, $result['sent'] );
			return $result;
		}

		// No adapter registered — use legacy send
		$result = $this->send_legacy( $chat_id, $message, $type, $extra, $platform );

		do_action( 'bizcity_channel_after_send', $result, $chat_id, $platform );
		$this->log_outbound( $chat_id, $message, $result['platform'], $result['sent'] );

		return $result;
	}

	/**
	 * Legacy send — mirrors bizcity_gateway_send_message() from gateway-functions.php.
	 *
	 * Maintains backward compat until all channels have adapter plugins.
	 *
	 * @param string $chat_id
	 * @param string $message
	 * @param string $type
	 * @param array  $extra
	 * @param string $platform
	 * @return array
	 */
	private function send_legacy( string $chat_id, string $message, string $type, array $extra, string $platform ): array {
		$platform_lower = strtolower( $platform );

		switch ( $platform_lower ) {
			case 'zalo_personal':
			case 'zalo':
				$client_id = preg_replace( '/^zalo_/', '', $chat_id );
				if ( function_exists( 'send_zalo_botbanhang' ) ) {
					$send_type = ( $type === 'image' ) ? 'image' : 'text';
					$res = send_zalo_botbanhang( $message, $client_id, $send_type );
					return [ 'sent' => (bool) $res, 'error' => '', 'platform' => 'ZALO_PERSONAL' ];
				}
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_PERSONAL' ];
				}
				return [ 'sent' => false, 'error' => 'Zalo send function not available', 'platform' => 'ZALO_PERSONAL' ];

			case 'zalo_bot':
				$raw_user_id  = preg_replace( '/^zalobot_/', '', $chat_id );
				$parsed_bot_id = isset( $extra['bot_id'] ) ? (int) $extra['bot_id'] : 0;
				if ( ! $parsed_bot_id && preg_match( '/^(\d+)_(.+)$/', $raw_user_id, $m ) ) {
					$parsed_bot_id = (int) $m[1];
					$raw_user_id   = $m[2];
				}

				// Resolve blog for zalo bot
				$target_blog_id = 0;
				if ( class_exists( 'BizCity_Blog_Resolver' ) ) {
					$target_blog_id = BizCity_Blog_Resolver::instance()->resolve_bot_blog( $parsed_bot_id );
				} elseif ( function_exists( 'bizcity_gateway_resolve_bot_blog_id' ) ) {
					$target_blog_id = bizcity_gateway_resolve_bot_blog_id( $parsed_bot_id );
				}

				$switched = false;
				if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
					switch_to_blog( $target_blog_id );
					$switched = true;
				}

				if ( class_exists( 'BizCity_Zalo_Bot_Database' ) && class_exists( 'BizCity_Zalo_Bot_API' ) ) {
					$db  = BizCity_Zalo_Bot_Database::instance();
					$bot = $parsed_bot_id ? $db->get_bot( $parsed_bot_id ) : null;
					if ( ! $bot ) {
						$bots = $db->get_active_bots();
						$bot  = ! empty( $bots ) ? end( $bots ) : null;
					}

					if ( $bot && ! empty( $bot->bot_token ) ) {
						$api      = new BizCity_Zalo_Bot_API( $bot->bot_token );
						$response = $api->send_message( $raw_user_id, $message );
						if ( $switched ) restore_current_blog();

						if ( is_wp_error( $response ) ) {
							return [ 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'ZALO_BOT' ];
						}
						return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_BOT' ];
					}
				}

				if ( $switched ) restore_current_blog();

				// Fallback to zalo personal
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( 'zalo_' . $raw_user_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_BOT_FALLBACK' ];
				}
				return [ 'sent' => false, 'error' => 'Zalo Bot plugin not active', 'platform' => 'ZALO_BOT' ];

			case 'webchat':
				if ( class_exists( 'BizCity_WebChat_Trigger' ) ) {
					BizCity_WebChat_Trigger::instance()->send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'WEBCHAT' ];
				}
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( [
						'session_id'    => $chat_id,
						'user_id'       => 0,
						'client_name'   => 'AI Bot',
						'message_id'    => uniqid( 'gw_' ),
						'message_text'  => $message,
						'message_from'  => 'bot',
						'platform_type' => 'WEBCHAT',
					] );
					return [ 'sent' => true, 'error' => '', 'platform' => 'WEBCHAT' ];
				}
				return [ 'sent' => false, 'error' => 'WebChat plugin not active', 'platform' => 'WEBCHAT' ];

			case 'adminchat':
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( [
						'session_id'    => $chat_id,
						'user_id'       => 0,
						'client_name'   => 'AI Bot',
						'message_id'    => uniqid( 'gw_adminchat_' ),
						'message_text'  => $message,
						'message_from'  => 'bot',
						'platform_type' => 'ADMINCHAT',
					] );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ADMINCHAT' ];
				}
				return [ 'sent' => false, 'error' => 'AdminChat database not available', 'platform' => 'ADMINCHAT' ];

			case 'facebook':
				$fb_id = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
				if ( function_exists( 'fbm_send_text_to_user' ) ) {
					$res = fbm_send_text_to_user( $fb_id, $message );
					return [ 'sent' => (bool) $res, 'error' => '', 'platform' => 'FACEBOOK' ];
				}
				return [ 'sent' => false, 'error' => 'Facebook send function not available', 'platform' => 'FACEBOOK' ];

			case 'telegram':
				if ( function_exists( 'twf_telegram_send_message' ) ) {
					twf_telegram_send_message( $chat_id, $message, 'HTML' );
					return [ 'sent' => true, 'error' => '', 'platform' => 'TELEGRAM' ];
				}
				return [ 'sent' => false, 'error' => 'Telegram function not available', 'platform' => 'TELEGRAM' ];

			default:
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'FALLBACK' ];
				}
				return [ 'sent' => false, 'error' => 'No send method available for: ' . $chat_id, 'platform' => 'UNKNOWN' ];
		}
	}

	/**
	 * Log outbound message to global_inbox_admin.
	 *
	 * @param string $chat_id
	 * @param string $message
	 * @param string $platform
	 * @param bool   $sent
	 */
	private function log_outbound( string $chat_id, string $message, string $platform, bool $sent ): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'global_inbox_admin';

		// Only log if table exists (checked once per request)
		static $table_exists = null;
		if ( null === $table_exists ) {
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		}
		if ( ! $table_exists ) {
			return;
		}

		$wpdb->insert( $table, [
			'client_id'     => $chat_id,
			'client_name'   => 'AI Bot',
			'platform_type' => strtoupper( $platform ),
			'message_text'  => mb_substr( $message, 0, 10000 ),
			'message_type'  => 'outbound',
			'created_at'    => current_time( 'mysql' ),
			'blog_id'       => get_current_blog_id(),
		] );
	}
}
