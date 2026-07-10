<?php
/**
 * BizCity Zalo Bot – Gateway Bridge
 *
 * Tích hợp bizcity-zalo-bot với bizcity-admin-hook-zalo gateway.
 *
 * Chức năng:
 *   1. Khi webhook nhận tin nhắn → resolve WP user_id từ bot assignment
 *   2. Normalize trigger qua bizcity_gateway_normalize_trigger()
 *   3. Fire unified trigger qua bizcity_gateway_fire_trigger()
 *   4. Override send message cho zalobot_ prefix chat_id
 *   5. Bridge: bizcity_zalo_message_received → gateway trigger
 *
 * @package BizCity_Zalo_Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Gateway_Bridge {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Bridge: bizcity_zalo_message_received → gateway
		add_action( 'bizcity_zalo_message_received', array( $this, 'bridge_to_gateway' ), 10, 1 );

		// Bridge: bizcity_zalo_bot_webhook_event → gateway (for all event types)
		add_action( 'bizcity_zalo_bot_webhook_event', array( $this, 'bridge_generic_event_to_gateway' ), 10, 3 );

		// Override: send message for zalobot_ prefix
		add_filter( 'twf_send_message_override', array( $this, 'override_send_for_zalobot' ), 7, 5 );
		add_filter( 'twf_telegram_send_photo_override', array( $this, 'override_send_photo_for_zalobot' ), 7, 5 );
	}

	/* ═══════════════════════════════════════════════════════════
	 * 1. BRIDGE: bizcity_zalo_message_received → Gateway
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Bridge từ bizcity_zalo_message_received action sang gateway
	 *
	 * Được fire khi webhook handler (class-webhook-handler.php) xử lý tin nhắn.
	 * Ưu tiên dùng twin-ai Gateway Bridge (BizCity_Gateway_Bridge).
	 * Fallback legacy gateway-functions.php nếu Gateway Bridge chưa load.
	 */
	public function bridge_to_gateway( $message_data ) {
		if ( ! is_array( $message_data ) || empty( $message_data ) ) {
			return;
		}

		// [2026-06-07 Johnny Chu] PHASE-0.40 G0.2 R-ZONE-2 — discriminator bail.
		// zalo_oa and zalo_personal carry customer messages (Zone 1 CRM care).
		// This bridge is Zone 2 only — bail so customers don’t trigger admin automation.
		$code = (string) ( $message_data['code'] ?? '' );
		if ( $code === 'zalo_oa' || $code === 'zalo_personal' ) {
			return;
		}

		$bot_id    = isset( $message_data['bot_id'] )    ? (int) $message_data['bot_id'] : 0;
		$bot_name  = isset( $message_data['bot_name'] )  ? $message_data['bot_name']     : '';
		$user_id_z = isset( $message_data['from_user_id'] ) ? $message_data['from_user_id'] : '';
		$text      = isset( $message_data['message_text'] ) ? $message_data['message_text'] : '';
		$msg_id    = isset( $message_data['message_id'] )   ? $message_data['message_id']   : '';
		$img_url   = isset( $message_data['image_url'] )    ? $message_data['image_url']    : '';

		// Resolve WordPress user_id — per-user link takes priority over bot-owner assignment
		$wp_user_id = 0;
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) && ! empty( $user_id_z ) && $bot_id > 0 ) {
			$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $user_id_z, $bot_id );
		}
		if ( $wp_user_id === 0 ) {
			// Fallback: bot-owner assignment (pre-linker behavior)
			$wp_user_id = $this->resolve_wp_user( $bot_id );
		}

		$display_name = isset( $message_data['from_user_name'] ) ? $message_data['from_user_name'] : '';

		// Log
		error_log( sprintf(
			'[Zalo Bot Gateway Bridge] 🔗 Bot #%d → WP User #%d | text=%s',
			$bot_id,
			$wp_user_id,
			mb_substr( $text, 0, 60 )
		) );

		// ── PRIMARY: Use twin-ai Gateway Bridge ──
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$payload = [
				'platform'    => 'ZALO_BOT',
				'chat_id'     => 'zalobot_' . $bot_id . '_' . $user_id_z,
				'user_id'     => $user_id_z,
				'client_name' => $display_name,
				'message'     => $text,
				'message_id'  => $msg_id,
				'attachments' => [],
				'event_type'  => 'message',
				'bot_id'      => (string) $bot_id,
				'bot_name'    => $bot_name,
				'wp_user_id'  => $wp_user_id,
				'image_url'   => $img_url,
				'raw'         => $message_data,
			];

			BizCity_Gateway_Bridge::instance()->fire_trigger( $payload, $message_data );
			return;
		}

		// ── FALLBACK: Legacy gateway-functions.php ──
		if ( function_exists( 'bizcity_gateway_normalize_trigger' ) ) {
			$data = array(
				'from_user_id'   => $user_id_z,
				'client_id'      => $user_id_z,
				'chat_id'        => 'zalobot_' . $bot_id . '_' . $user_id_z,
				'user_id'        => $wp_user_id ? (string) $wp_user_id : $user_id_z,
				'message_text'   => $text,
				'text'           => $text,
				'message_id'     => $msg_id,
				'display_name'   => $display_name,
				'bot_id'         => (string) $bot_id,
				'bot_name'       => $bot_name,
				'image_url'      => $img_url,
				'wp_user_id'     => $wp_user_id,
				'message_type'   => isset( $message_data['message_type'] ) ? $message_data['message_type'] : 'text',
			);

			$trigger = bizcity_gateway_normalize_trigger( $data, 'zalo_bot' );
			$trigger['wp_user_id'] = $wp_user_id;

			if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
				bizcity_gateway_fire_trigger( $trigger, $message_data );
			}
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 * 2. BRIDGE: Generic event → Gateway
	 * ═══════════════════════════════════════════════════════════ */

	public function bridge_generic_event_to_gateway( $bot, $event_name, $data ) {
		// Chỉ bridge các event chính
		$bridgeable = array( 'message.text.received', 'message.image.received' );
		if ( ! in_array( $event_name, $bridgeable, true ) ) {
			return;
		}

		// bridge_to_gateway đã xử lý qua bizcity_zalo_message_received
		// Nên ở đây chỉ xử lý trường hợp action chưa fire
		// (tránh double fire bằng cách check flag)
		if ( did_action( 'bizcity_zalo_message_received' ) && doing_action( 'bizcity_zalo_bot_webhook_event' ) ) {
			return; // Đã bridge qua bizcity_zalo_message_received rồi
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 * 3. OVERRIDE SEND MESSAGE: zalobot_ prefix
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Override twf_send_message cho chat_id có prefix zalobot_
	 */
	public function override_send_for_zalobot( $default, $chat_id, $text, $parse_mode = '', $reply_markup = null ) {
		if ( $default !== false ) {
			return $default;
		}

		// Parse zalobot_{bot_id}_{zalo_user_id}
		$parsed = $this->parse_zalobot_chat_id( $chat_id );
		if ( ! $parsed ) {
			return false; // Not a zalobot chat_id
		}

		$bot_id       = $parsed['bot_id'];
		$zalo_user_id = $parsed['zalo_user_id'];

		// Resolve blog_id from bot assignment và switch context
		$target_blog_id = $this->resolve_bot_blog_id( $bot_id );
		$switched = false;
		
		if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
			error_log( sprintf( '[Zalo Bot Gateway] 🔄 Switched to blog #%d for bot #%d', $target_blog_id, $bot_id ) );
		}

		// MUST use Bot Platform API (send_message), NOT OA API (send_text_message)
		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api ) {
			error_log( '[Zalo Bot Gateway] ❌ Bot #' . $bot_id . ' not found in blog #' . get_current_blog_id() );
			if ( $switched ) {
				restore_current_blog();
			}
			return false;
		}

		// Xóa markdown (###, **bold**, v.v.) trước khi gửi vì Zalo Bot không hỗ trợ markdown
		if ( function_exists( 'bizgpt_zalo_format' ) ) {
			$text = bizgpt_zalo_format( $text );
		}

		$result = $api->send_message( $zalo_user_id, $text );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $result ) ) {
			error_log( '[Zalo Bot Gateway] ❌ Send failed: ' . $result->get_error_message() );
			return false;
		}

		error_log( sprintf( '[Zalo Bot Gateway] ✅ Sent text to bot #%d user %s', $bot_id, $zalo_user_id ) );
		return true;
	}

	/**
	 * Override twf_telegram_send_photo cho chat_id zalobot_
	 */
	public function override_send_photo_for_zalobot( $default, $chat_id, $photo_url, $caption = '', $extra = array() ) {
		if ( $default !== false ) {
			return $default;
		}

		$parsed = $this->parse_zalobot_chat_id( $chat_id );
		if ( ! $parsed ) {
			return false;
		}

		$bot_id       = $parsed['bot_id'];
		$zalo_user_id = $parsed['zalo_user_id'];

		// Resolve blog_id from bot assignment và switch context
		$target_blog_id = $this->resolve_bot_blog_id( $bot_id );
		$switched = false;
		
		if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		// MUST use Bot Platform API (send_photo), NOT OA API
		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api ) {
			error_log( '[Zalo Bot Gateway] ❌ Bot #' . $bot_id . ' not found for photo send in blog #' . get_current_blog_id() );
			if ( $switched ) {
				restore_current_blog();
			}
			return false;
		}

		$result = $api->send_photo( $zalo_user_id, $photo_url, $caption );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $result ) ) {
			error_log( '[Zalo Bot Gateway] ❌ Send photo failed: ' . $result->get_error_message() );
			return false;
		}

		error_log( sprintf( '[Zalo Bot Gateway] ✅ Sent photo to bot #%d user %s', $bot_id, $zalo_user_id ) );
		return true;
	}

	/* ═══════════════════════════════════════════════════════════
	 * 4. HELPER: Resolve WordPress user_id from bot_id
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Resolve WordPress user_id for a given bot_id
	 *
	 * @param int $bot_id
	 * @return int WordPress user_id or 0 if not assigned
	 */
	public function resolve_wp_user( $bot_id ) {
		if ( ! class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
			return 0;
		}

		return BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot( (int) $bot_id );
	}

	/**
	 * Resolve blog_id for a given bot_id
	 *
	 * Scans all blogs in multisite to find where the bot exists
	 *
	 * @param int $bot_id
	 * @return int blog_id or 0 if not found
	 */
	private function resolve_bot_blog_id( $bot_id ) {
		global $wpdb;

		// Cache key for this request
		static $cache = array();
		if ( isset( $cache[ $bot_id ] ) ) {
			return $cache[ $bot_id ];
		}

		// Priority 1: Check cached source_blog_id from webhook handler
		$cached_blog_id = get_transient( 'zalobot_source_blog_' . $bot_id );
		if ( $cached_blog_id ) {
			$cache[ $bot_id ] = (int) $cached_blog_id;
			error_log( sprintf( '[Zalo Bot Gateway] 🎯 Using cached source_blog_id=%d for bot #%d', $cached_blog_id, $bot_id ) );
			return $cache[ $bot_id ];
		}

		// Priority 2: try current blog
		$table_current = $wpdb->prefix . 'bizcity_zalo_bots';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_current}'" ) === $table_current;
		
		if ( $table_exists ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table_current} WHERE id = %d",
				$bot_id
			) );

			if ( $exists ) {
				$cache[ $bot_id ] = get_current_blog_id();
				return $cache[ $bot_id ];
			}
		}

		// Second: try to resolve from user assignment → user's primary blog
		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
			$wp_user_id = BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot( (int) $bot_id );
			if ( $wp_user_id ) {
				$primary_blog = get_user_meta( $wp_user_id, 'primary_blog', true );
				if ( $primary_blog ) {
					$cache[ $bot_id ] = (int) $primary_blog;
					return $cache[ $bot_id ];
				}
			}
		}

		// Third: scan recent blogs from wp_blogs (multisite)
		if ( is_multisite() ) {
			$blogs = $wpdb->get_col(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = 0 AND deleted = 0 ORDER BY blog_id DESC LIMIT 50"
			);

			foreach ( $blogs as $blog_id ) {
				$table_name = $wpdb->get_blog_prefix( $blog_id ) . 'bizcity_zalo_bots';
				
				// Check if table exists first
				$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
				if ( ! $table_exists ) {
					continue;
				}

				$found = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE id = %d",
					$bot_id
				) );

				if ( $found ) {
					$cache[ $bot_id ] = (int) $blog_id;
					error_log( sprintf( '[Zalo Bot Gateway] 🔍 Found bot #%d in blog #%d', $bot_id, $blog_id ) );
					return $cache[ $bot_id ];
				}
			}
		}

		$cache[ $bot_id ] = 0;
		return 0;
	}

	/**
	 * Parse zalobot_ prefix chat_id
	 *
	 * Format: zalobot_{bot_id}_{zalo_user_id}
	 *
	 * @param string $chat_id
	 * @return array|false ['bot_id' => int, 'zalo_user_id' => string]
	 */
	private function parse_zalobot_chat_id( $chat_id ) {
		if ( strpos( $chat_id, 'zalobot_' ) !== 0 ) {
			return false;
		}

		// Remove prefix
		$rest = substr( $chat_id, 8 ); // After 'zalobot_'

		// Split by first underscore
		$pos = strpos( $rest, '_' );
		if ( $pos === false ) {
			return false;
		}

		$bot_id       = intval( substr( $rest, 0, $pos ) );
		$zalo_user_id = substr( $rest, $pos + 1 );

		if ( $bot_id <= 0 || empty( $zalo_user_id ) ) {
			return false;
		}

		return array(
			'bot_id'       => $bot_id,
			'zalo_user_id' => $zalo_user_id,
		);
	}
}

/* ═══════════════════════════════════════════════════════════
 * GLOBAL HELPER FUNCTIONS
 * ═══════════════════════════════════════════════════════════ */

/**
 * Resolve WordPress user_id từ Zalo Bot ID
 *
 * @param int $bot_id
 * @return int WordPress user_id or 0
 */
function bizcity_zalobot_resolve_wp_user( $bot_id ) {
	if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
		return BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot( (int) $bot_id );
	}
	return 0;
}

/**
 * Lấy danh sách bot_ids được gán cho WordPress user
 *
 * @param int $user_id WordPress user ID
 * @return array
 */
function bizcity_zalobot_get_user_bots( $user_id ) {
	if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
		return BizCity_Zalo_Bot_Dashboard::get_user_bot_ids( (int) $user_id );
	}
	return array();
}
