<?php
/**
 * Channel Gateway — Legacy Gateway Functions (Unified Message Routing & Trigger System)
 *
 * Ported from mu-plugins/bizcity-admin-hook-zalo/includes/gateway-functions.php.
 * All functions wrapped in function_exists() guards to prevent fatal conflicts
 * when the legacy mu-plugin is still active on older installs.
 *
 * Provides:
 *   1. bizcity_gateway_normalize_trigger()  — chuẩn hóa payload mọi platform
 *   2. bizcity_gateway_fire_trigger()       — fire waic_twf_process_flow thống nhất
 *   3. bizcity_gateway_send_message()       — unified send (delegates to BizCity_Gateway_Sender nếu có)
 *   4. bizcity_gateway_detect_platform()    — detect platform từ chat_id prefix
 *   5. bizcity_gateway_resolve_bot_blog_id()— scan blog cho bot_id
 *   6. bizcity_gateway_classify_attachment()— phân loại URL attachment
 *   7. Hook: bizcity_chat_message_processed → bridge to automation
 *   8. Filter: twf_send_message_override    → route webchat/adminchat/zalo_bot
 *
 * [2026-06-11 Johnny Chu] HOTFIX — moved into channel-gateway/legacy/ so these
 * helpers are owned by bizcity-twin-ai, not the mu-plugin.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Legacy
 * @since      2026-06-11
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════
 * 1. NORMALIZE TRIGGER PAYLOAD
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_normalize_trigger' ) ) {
	/**
	 * Chuẩn hóa trigger payload từ mọi nguồn.
	 *
	 * @param array  $data
	 * @param string $platform zalo|zalo_bot|webchat|adminchat|facebook|telegram
	 * @return array
	 */
	function bizcity_gateway_normalize_trigger( $data, $platform = '' ) {
		$trigger = array(
			'platform'        => $platform,
			'client_id'       => '',
			'chat_id'         => '',
			'session_id'      => '',
			'user_id'         => '',
			'display_name'    => '',
			'text'            => '',
			'message_id'      => '',
			'attachment_url'  => '',
			'attachment_type' => '',
			'image_url'       => '',
			'audio_url'       => '',
			'bot_id'          => '',
			'bot_name'        => '',
			'raw'             => $data,
		);

		if ( $platform === 'zalo' ) {
			$trigger['client_id']    = isset( $data['client_id'] )   ? (string) $data['client_id']   : '';
			$trigger['chat_id']      = 'zalo_' . $trigger['client_id'];
			$trigger['text']         = isset( $data['text'] )        ? (string) $data['text']        : '';
			$trigger['message_id']   = isset( $data['message_id'] )  ? (string) $data['message_id']  : '';
			$trigger['display_name'] = isset( $data['client_name'] ) ? (string) $data['client_name'] : '';
			$trigger['twf_platform']  = 'zalo';
			$trigger['twf_client_id'] = $trigger['client_id'];
			$trigger['twf_chat_id']   = $trigger['client_id'];
			$trigger['twf_text']      = $trigger['text'];

		} elseif ( $platform === 'zalo_bot' ) {
			$trigger['client_id']    = isset( $data['from_user_id'] )   ? (string) $data['from_user_id']   : ( isset( $data['client_id'] ) ? (string) $data['client_id'] : '' );
			$trigger['chat_id']      = isset( $data['chat_id'] )        ? (string) $data['chat_id']        : ( 'zalo_' . $trigger['client_id'] );
			$trigger['user_id']      = isset( $data['user_id'] )        ? (string) $data['user_id']        : $trigger['client_id'];
			$trigger['text']         = isset( $data['message_text'] )   ? (string) $data['message_text']   : ( isset( $data['text'] ) ? (string) $data['text'] : '' );
			$trigger['message_id']   = isset( $data['message_id'] )     ? (string) $data['message_id']     : '';
			$trigger['display_name'] = isset( $data['from_user_name'] ) ? (string) $data['from_user_name'] : ( isset( $data['display_name'] ) ? (string) $data['display_name'] : '' );
			$trigger['bot_id']       = isset( $data['bot_id'] )         ? (string) $data['bot_id']         : '';
			$trigger['bot_name']     = isset( $data['bot_name'] )       ? (string) $data['bot_name']       : '';
			$trigger['image_url']    = isset( $data['image_url'] )      ? (string) $data['image_url']      : '';
			if ( isset( $data['message_type'] ) ) {
				$trigger['attachment_type'] = (string) $data['message_type'];
			}

		} elseif ( in_array( $platform, array( 'webchat', 'adminchat' ), true ) ) {
			$trigger['session_id']   = isset( $data['session_id'] )   ? (string) $data['session_id']   : '';
			$trigger['client_id']    = $trigger['session_id'];
			$trigger['chat_id']      = $trigger['session_id'];
			$trigger['user_id']      = isset( $data['user_id'] )     ? (string) $data['user_id']      : '';
			$trigger['text']         = isset( $data['user_message'] ) ? (string) $data['user_message'] : ( isset( $data['text'] ) ? (string) $data['text'] : '' );
			$trigger['message_id']   = isset( $data['message_id'] )  ? (string) $data['message_id']   : uniqid( 'gw_' );
			$trigger['display_name'] = isset( $data['client_name'] ) ? (string) $data['client_name']  : '';
			if ( ! empty( $data['images'] ) && is_array( $data['images'] ) ) {
				$first_img                   = $data['images'][0];
				$trigger['image_url']        = is_string( $first_img ) ? $first_img : ( $first_img['url'] ?? $first_img['data'] ?? '' );
				$trigger['attachment_type']  = 'image';
				$trigger['attachment_url']   = $trigger['image_url'];
			}

		} elseif ( $platform === 'facebook' ) {
			$trigger['client_id']    = isset( $data['client_id'] )    ? (string) $data['client_id']    : '';
			$trigger['chat_id']      = 'fb_' . $trigger['client_id'];
			$trigger['text']         = isset( $data['text'] )         ? (string) $data['text']         : ( isset( $data['message_text'] ) ? (string) $data['message_text'] : '' );
			$trigger['message_id']   = isset( $data['message_id'] )   ? (string) $data['message_id']   : '';
			$trigger['display_name'] = isset( $data['display_name'] ) ? (string) $data['display_name'] : '';
			$trigger['image_url']    = isset( $data['image_url'] )    ? (string) $data['image_url']    : '';

		} elseif ( $platform === 'telegram' ) {
			$trigger['client_id']    = isset( $data['chat_id'] )      ? (string) $data['chat_id']      : '';
			$trigger['chat_id']      = $trigger['client_id'];
			$trigger['text']         = isset( $data['text'] )         ? (string) $data['text']         : '';
			$trigger['message_id']   = isset( $data['message_id'] )   ? (string) $data['message_id']   : '';
			$trigger['display_name'] = isset( $data['display_name'] ) ? (string) $data['display_name'] : '';

		} else {
			foreach ( array( 'client_id', 'chat_id', 'session_id', 'user_id', 'display_name', 'text', 'message_id', 'attachment_url', 'attachment_type', 'image_url', 'audio_url', 'bot_id', 'bot_name' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$trigger[ $key ] = (string) $data[ $key ];
				}
			}
		}

		if ( empty( $trigger['attachment_type'] ) ) {
			if ( ! empty( $trigger['image_url'] ) || ! empty( $trigger['attachment_url'] ) ) {
				$url_to_check           = $trigger['attachment_url'] ?: $trigger['image_url'];
				$trigger['attachment_type'] = function_exists( 'bizcity_gateway_classify_attachment' )
					? bizcity_gateway_classify_attachment( $url_to_check )
					: 'image';
			} else {
				$trigger['attachment_type'] = 'text';
			}
		}

		return $trigger;
	}
}

/* ═══════════════════════════════════════════════════════════
 * 2. FIRE UNIFIED TRIGGER
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_fire_trigger' ) ) {
	/**
	 * Fire unified gateway trigger (waic_twf_process_flow hoặc hook tùy chọn).
	 *
	 * @param array  $trigger
	 * @param array  $raw
	 * @param string $hookName
	 * @return bool
	 */
	function bizcity_gateway_fire_trigger( array $trigger, array $raw = array(), $hookName = 'waic_twf_process_flow' ) {
		$GLOBALS['bizcity_gateway_trigger_fired'] = true;

		$platform = isset( $trigger['platform'] ) ? $trigger['platform'] : 'unknown';
		$text     = isset( $trigger['text'] )     ? $trigger['text']     : '';
		error_log( sprintf(
			'[Gateway] 🚀 Firing %s | platform=%s | text=%s',
			$hookName,
			$platform,
			mb_substr( $text, 0, 60 )
		) );

		if ( function_exists( 'bizcity_aiwu_fire_twf_process_flow' ) ) {
			return bizcity_aiwu_fire_twf_process_flow( $trigger, $raw, $hookName );
		}

		do_action( $hookName, $trigger, $raw );
		return (int) has_action( $hookName ) > 0;
	}
}

/* ═══════════════════════════════════════════════════════════
 * 3. UNIFIED SEND MESSAGE
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_send_message' ) ) {
	/**
	 * Gửi tin nhắn đến bất kỳ kênh nào (auto-detect platform từ chat_id).
	 *
	 * @param string $chat_id
	 * @param string $message
	 * @param string $type   'text'|'image'|'file'
	 * @param array  $extra
	 * @return array{sent:bool,error:string,platform:string}
	 */
	function bizcity_gateway_send_message( $chat_id, $message, $type = 'text', $extra = array() ) {
		$chat_id = trim( (string) $chat_id );
		$message = (string) $message;

		if ( empty( $chat_id ) ) {
			return array( 'sent' => false, 'error' => 'Empty chat_id', 'platform' => '' );
		}

		// Delegate to new Gateway Sender if available (Phase 1 compat)
		if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			return BizCity_Gateway_Sender::instance()->send( $chat_id, $message, $type, $extra );
		}

		$platform = function_exists( 'bizcity_gateway_detect_platform' )
			? bizcity_gateway_detect_platform( $chat_id )
			: 'unknown';

		error_log( sprintf( '[Gateway] 📤 Sending to %s | platform=%s | type=%s', $chat_id, $platform, $type ) );

		switch ( $platform ) {

			case 'zalo':
				$client_id = preg_replace( '/^zalo_/', '', $chat_id );
				if ( function_exists( 'send_zalo_botbanhang' ) ) {
					$send_type = ( $type === 'image' ) ? 'image' : 'text';
					$res       = send_zalo_botbanhang( $message, $client_id, $send_type );
					return array( 'sent' => (bool) $res, 'error' => '', 'platform' => 'zalo' );
				}
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return array( 'sent' => true, 'error' => '', 'platform' => 'zalo' );
				}
				return array( 'sent' => false, 'error' => 'Zalo send function not available', 'platform' => 'zalo' );

			case 'zalo_bot':
				$raw_user_id   = preg_replace( '/^zalobot_/', '', $chat_id );
				$parsed_bot_id = isset( $extra['bot_id'] ) ? (int) $extra['bot_id'] : 0;
				if ( ! $parsed_bot_id && preg_match( '/^(\d+)_(.+)$/', $raw_user_id, $m ) ) {
					$parsed_bot_id = (int) $m[1];
					$raw_user_id   = $m[2];
				}
				$target_blog_id = function_exists( 'bizcity_gateway_resolve_bot_blog_id' )
					? bizcity_gateway_resolve_bot_blog_id( $parsed_bot_id )
					: 0;
				$switched = false;
				if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
					switch_to_blog( $target_blog_id );
					$switched = true;
				}
				if ( class_exists( 'BizCity_Zalo_Bot_Database' ) && class_exists( 'BizCity_Zalo_Bot_API' ) ) {
					$db  = BizCity_Zalo_Bot_Database::instance();
					$bot = $parsed_bot_id ? $db->get_bot( $parsed_bot_id ) : ( ( $bots = $db->get_active_bots() ) ? end( $bots ) : null );
					if ( $bot && ! empty( $bot->bot_token ) ) {
						$api      = new BizCity_Zalo_Bot_API( $bot->bot_token );
						$response = $api->send_message( $raw_user_id, $message );
						if ( $switched ) { restore_current_blog(); }
						if ( is_wp_error( $response ) ) {
							return array( 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'zalo_bot' );
						}
						return array( 'sent' => true, 'error' => '', 'platform' => 'zalo_bot' );
					}
				}
				if ( $switched ) { restore_current_blog(); }
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( 'zalo_' . $raw_user_id, $message );
					return array( 'sent' => true, 'error' => '', 'platform' => 'zalo_bot_fallback' );
				}
				return array( 'sent' => false, 'error' => 'Zalo Bot plugin not active', 'platform' => 'zalo_bot' );

			case 'webchat':
				if ( class_exists( 'BizCity_WebChat_Trigger' ) ) {
					BizCity_WebChat_Trigger::instance()->send_message( $chat_id, $message );
					return array( 'sent' => true, 'error' => '', 'platform' => 'webchat' );
				}
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( array(
						'session_id' => $chat_id, 'user_id' => 0, 'client_name' => 'AI Bot',
						'message_id' => uniqid( 'gw_' ), 'message_text' => $message,
						'message_from' => 'bot', 'platform_type' => 'WEBCHAT',
					) );
					return array( 'sent' => true, 'error' => '', 'platform' => 'webchat' );
				}
				return array( 'sent' => false, 'error' => 'WebChat plugin not active', 'platform' => 'webchat' );

			case 'adminchat':
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( array(
						'session_id' => $chat_id, 'user_id' => 0, 'client_name' => 'AI Bot',
						'message_id' => uniqid( 'gw_adminchat_' ), 'message_text' => $message,
						'message_from' => 'bot', 'platform_type' => 'ADMINCHAT',
					) );
					return array( 'sent' => true, 'error' => '', 'platform' => 'adminchat' );
				}
				return array( 'sent' => false, 'error' => 'AdminChat database not available', 'platform' => 'adminchat' );

			case 'facebook':
				$fb_id = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
				if ( function_exists( 'fbm_send_text_to_user' ) ) {
					$res = fbm_send_text_to_user( $fb_id, $message );
					return array( 'sent' => (bool) $res, 'error' => '', 'platform' => 'facebook' );
				}
				return array( 'sent' => false, 'error' => 'Facebook send function not available', 'platform' => 'facebook' );

			case 'telegram':
				if ( function_exists( 'twf_telegram_send_message' ) ) {
					twf_telegram_send_message( $chat_id, $message, 'HTML' );
					return array( 'sent' => true, 'error' => '', 'platform' => 'telegram' );
				}
				return array( 'sent' => false, 'error' => 'Telegram function not available', 'platform' => 'telegram' );

			default:
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return array( 'sent' => true, 'error' => '', 'platform' => 'fallback' );
				}
				return array( 'sent' => false, 'error' => 'No send method available for: ' . $chat_id, 'platform' => 'unknown' );
		}
	}
}

/* ═══════════════════════════════════════════════════════════
 * 4. DETECT PLATFORM FROM CHAT ID
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_detect_platform' ) ) {
	/**
	 * Nhận diện platform từ chat_id prefix.
	 *
	 * @param string $chat_id
	 * @return string
	 */
	function bizcity_gateway_detect_platform( $chat_id ) {
		$chat_id = (string) $chat_id;

		// Delegate to new Gateway Bridge if available
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			return strtolower( BizCity_Gateway_Bridge::instance()->detect_platform( $chat_id ) );
		}

		if ( strpos( $chat_id, 'zalobot_' )    === 0 ) { return 'zalo_bot'; }
		if ( strpos( $chat_id, 'webchat_' )    === 0 ) { return 'webchat'; }
		if ( strpos( $chat_id, 'sess_' )       === 0 ) { return 'webchat'; }
		if ( strpos( $chat_id, 'wcs_' )        === 0 ) { return 'webchat'; }
		if ( strpos( $chat_id, 'adminchat_' )  === 0 ) { return 'adminchat'; }
		if ( strpos( $chat_id, 'admin_chat_' ) === 0 ) { return 'adminchat'; }
		if ( strpos( $chat_id, 'admin_' )      === 0 ) { return 'adminchat'; }
		if ( strpos( $chat_id, 'fb_' )         === 0 ) { return 'facebook'; }
		if ( strpos( $chat_id, 'messenger_' )  === 0 ) { return 'facebook'; }
		if ( strpos( $chat_id, 'zalo_' )       === 0 ) { return 'zalo'; }
		if ( preg_match( '/^-?\d+$/', $chat_id ) )     { return 'telegram'; }

		return 'unknown';
	}
}

/* ═══════════════════════════════════════════════════════════
 * 4.5 RESOLVE BOT BLOG ID
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_resolve_bot_blog_id' ) ) {
	/**
	 * Tìm blog_id chứa bot_id (scan multisite).
	 *
	 * @param int $bot_id
	 * @return int
	 */
	function bizcity_gateway_resolve_bot_blog_id( $bot_id ) {
		global $wpdb;

		if ( ! $bot_id ) { return 0; }

		static $cache = array();
		if ( isset( $cache[ $bot_id ] ) ) { return $cache[ $bot_id ]; }

		$cached_blog_id = get_transient( 'zalobot_source_blog_' . $bot_id );
		if ( $cached_blog_id ) {
			$cache[ $bot_id ] = (int) $cached_blog_id;
			return $cache[ $bot_id ];
		}

		$table_current = $wpdb->prefix . 'bizcity_zalo_bots';
		if ( bizcity_tbl_exists( $table_current ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_current} WHERE id = %d", $bot_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists ) {
				$cache[ $bot_id ] = get_current_blog_id();
				return $cache[ $bot_id ];
			}
		}

		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) && method_exists( 'BizCity_Zalo_Bot_Dashboard', 'resolve_user_for_bot' ) ) {
			$wp_user_id = BizCity_Zalo_Bot_Dashboard::resolve_user_for_bot( (int) $bot_id );
			if ( $wp_user_id ) {
				$primary_blog = get_user_meta( $wp_user_id, 'primary_blog', true );
				if ( $primary_blog ) {
					$cache[ $bot_id ] = (int) $primary_blog;
					return $cache[ $bot_id ];
				}
			}
		}

		if ( is_multisite() ) {
			$blogs = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE archived = 0 AND deleted = 0 ORDER BY blog_id DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $blogs as $bid ) {
				$tbl = $wpdb->get_blog_prefix( $bid ) . 'bizcity_zalo_bots';
				if ( ! bizcity_tbl_exists( $tbl ) ) { continue; } // [2026-06-21 Johnny Chu] R-SHOW-TABLES
				$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE id = %d", $bot_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $found ) {
					$cache[ $bot_id ] = (int) $bid;
					return $cache[ $bot_id ];
				}
			}
		}

		$cache[ $bot_id ] = 0;
		return 0;
	}
}

/* ═══════════════════════════════════════════════════════════
 * 5. CLASSIFY ATTACHMENT URL
 * ═══════════════════════════════════════════════════════════ */

if ( ! function_exists( 'bizcity_gateway_classify_attachment' ) ) {
	/**
	 * Phân loại attachment từ URL.
	 *
	 * @param string $url
	 * @return string 'image'|'audio'|'video'|'file'|'text'|'unknown'
	 */
	function bizcity_gateway_classify_attachment( $url ) {
		$url = (string) $url;
		if ( $url === '' ) { return 'text'; }
		if ( strpos( $url, 'data:image/' ) === 0 ) { return 'image'; }
		if ( strpos( $url, 'data:audio/' ) === 0 ) { return 'audio'; }

		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$ext   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$audio = array( 'aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga', 'opus', 'webm' );
		$image = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff' );
		$video = array( 'mp4', 'mov', 'avi', 'wmv' );

		if ( $ext && in_array( $ext, $audio, true ) ) { return 'audio'; }
		if ( $ext && in_array( $ext, $image, true ) ) { return 'image'; }
		if ( $ext && in_array( $ext, $video, true ) ) { return 'video'; }
		if ( $ext ) { return 'file'; }
		return 'unknown';
	}
}

/* ═══════════════════════════════════════════════════════════
 * 6. BRIDGE: bizcity_chat_message_processed → automation
 * ═══════════════════════════════════════════════════════════ */

if ( ! has_action( 'bizcity_chat_message_processed', 'bizcity_gateway_bridge_chat_to_automation' ) ) {
	add_action( 'bizcity_chat_message_processed', 'bizcity_gateway_bridge_chat_to_automation', 10, 1 );
}

if ( ! function_exists( 'bizcity_gateway_bridge_chat_to_automation' ) ) {
	/**
	 * Bridge: Chat Gateway → fire automation trigger (ADMINCHAT only).
	 *
	 * @param array $event_data
	 */
	function bizcity_gateway_bridge_chat_to_automation( $event_data ) {
		if ( ! is_array( $event_data ) ) { return; }
		if ( defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) { return; } // new CG handles this
		if ( ! empty( $GLOBALS['bizcity_gateway_trigger_fired'] ) ) { return; }

		$platform_type = strtolower( isset( $event_data['platform_type'] ) ? $event_data['platform_type'] : '' );
		$platform      = in_array( $platform_type, array( 'adminchat', 'webchat' ), true ) ? $platform_type : '';
		if ( empty( $platform ) || $platform === 'webchat' ) { return; }

		global $waic_current_trigger;
		if ( ! empty( $waic_current_trigger ) ) { return; }

		$trigger = bizcity_gateway_normalize_trigger( $event_data, $platform );
		bizcity_gateway_fire_trigger( $trigger, (array) $event_data );
	}
}

/* ═══════════════════════════════════════════════════════════
 * 7. FILTER: twf_send_message_override
 * ═══════════════════════════════════════════════════════════ */

if ( ! has_filter( 'twf_send_message_override', 'bizcity_gateway_override_send_for_webchat' ) ) {
	add_filter( 'twf_send_message_override', 'bizcity_gateway_override_send_for_webchat', 8, 5 );
}

if ( ! function_exists( 'bizcity_gateway_override_send_for_webchat' ) ) {
	/**
	 * Route webchat/adminchat/zalo_bot chat_id qua Gateway thay vì Telegram.
	 *
	 * @param mixed  $default
	 * @param string $chat_id
	 * @param string $text
	 * @param string $parse_mode
	 * @param mixed  $reply_markup
	 * @return mixed
	 */
	function bizcity_gateway_override_send_for_webchat( $default, $chat_id, $text, $parse_mode = '', $reply_markup = null ) {
		if ( $default !== false ) { return $default; }
		if ( defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) { return false; }

		$platform = function_exists( 'bizcity_gateway_detect_platform' )
			? bizcity_gateway_detect_platform( $chat_id )
			: 'unknown';

		if ( in_array( $platform, array( 'webchat', 'adminchat', 'zalo_bot' ), true ) ) {
			$result = bizcity_gateway_send_message( $chat_id, $text );
			return $result['sent'] ? array( 'status' => 'sent', 'platform' => $platform ) : false;
		}

		return false;
	}
}
