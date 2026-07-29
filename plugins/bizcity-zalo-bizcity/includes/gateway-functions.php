<?php
/**
 * BizCity Gateway Functions — Unified Message Routing & Trigger System
 *
 * Cung cấp các hàm helper để:
 *   1. Chuẩn hóa trigger payload từ bất kỳ platform nào
 *   2. Fire waic_twf_process_flow thống nhất
 *   3. Gửi tin nhắn đến bất kỳ platform nào (unified send)
 *   4. Bridge ADMINCHAT/WEBCHAT → automation triggers
 *   5. Override twf_telegram_send_message cho webchat/adminchat routing
 *
 * Include file này từ bizcity-admin-hook-zalo/bootstrap.php
 *
 * @package BizCity_Admin_Hook_Zalo
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════
 * 1. NORMALIZE TRIGGER PAYLOAD
 *
 * Chuẩn hóa dữ liệu trigger từ mọi nguồn thành format chung
 * cho wu_gateway_message_received trigger.
 * ═══════════════════════════════════════════════════════════ */

/**
 * Chuẩn hóa trigger payload cho Gateway
 *
 * @param array  $data     Dữ liệu gốc từ webhook/handler
 * @param string $platform Platform nguồn: zalo, zalo_bot, webchat, adminchat, facebook, telegram
 * @return array Trigger payload chuẩn
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

    // ── Zalo Personal (từ bizcity-admin-hook-zalo bootstrap) ──
    if ( $platform === 'zalo' ) {
        $trigger['client_id']    = isset( $data['client_id'] )    ? (string) $data['client_id']    : '';
        $trigger['chat_id']      = 'zalo_' . $trigger['client_id'];
        $trigger['text']         = isset( $data['text'] )         ? (string) $data['text']         : '';
        $trigger['message_id']   = isset( $data['message_id'] )   ? (string) $data['message_id']   : '';
        $trigger['display_name'] = isset( $data['client_name'] )  ? (string) $data['client_name']  : '';

        // Backward compat: copy twf_ prefix fields
        $trigger['twf_platform']  = 'zalo';
        $trigger['twf_client_id'] = $trigger['client_id'];
        $trigger['twf_chat_id']   = $trigger['client_id'];
        $trigger['twf_text']      = $trigger['text'];
    }

    // ── Zalo Bot OA (từ bizcity-zalo-bot webhook) ──
    elseif ( $platform === 'zalo_bot' ) {
        $trigger['client_id']    = isset( $data['from_user_id'] ) ? (string) $data['from_user_id'] : ( isset( $data['client_id'] ) ? (string) $data['client_id'] : '' );
        $trigger['chat_id']      = isset( $data['chat_id'] )     ? (string) $data['chat_id']      : ( 'zalo_' . $trigger['client_id'] );
        $trigger['user_id']      = isset( $data['user_id'] )     ? (string) $data['user_id']      : $trigger['client_id'];
        $trigger['text']         = isset( $data['message_text'] ) ? (string) $data['message_text'] : ( isset( $data['text'] ) ? (string) $data['text'] : '' );
        $trigger['message_id']   = isset( $data['message_id'] )  ? (string) $data['message_id']   : '';
        $trigger['display_name'] = isset( $data['from_user_name'] ) ? (string) $data['from_user_name'] : ( isset( $data['display_name'] ) ? (string) $data['display_name'] : '' );
        $trigger['bot_id']       = isset( $data['bot_id'] )      ? (string) $data['bot_id']       : '';
        $trigger['bot_name']     = isset( $data['bot_name'] )    ? (string) $data['bot_name']     : '';
        $trigger['image_url']    = isset( $data['image_url'] )   ? (string) $data['image_url']    : '';

        // Attachment from message_type
        if ( isset( $data['message_type'] ) ) {
            $trigger['attachment_type'] = (string) $data['message_type'];
        }
    }

    // ── WebChat / AdminChat (từ BizCity_Chat_Gateway) ──
    elseif ( in_array( $platform, array( 'webchat', 'adminchat' ), true ) ) {
        $trigger['session_id']   = isset( $data['session_id'] )   ? (string) $data['session_id']   : '';
        $trigger['client_id']    = $trigger['session_id'];
        $trigger['chat_id']      = $trigger['session_id'];
        $trigger['user_id']      = isset( $data['user_id'] )     ? (string) $data['user_id']      : '';
        $trigger['text']         = isset( $data['user_message'] ) ? (string) $data['user_message'] : ( isset( $data['text'] ) ? (string) $data['text'] : '' );
        $trigger['message_id']   = isset( $data['message_id'] )  ? (string) $data['message_id']   : uniqid( 'gw_' );
        $trigger['display_name'] = isset( $data['client_name'] ) ? (string) $data['client_name']  : '';

        // Images from chat gateway
        if ( ! empty( $data['images'] ) && is_array( $data['images'] ) ) {
            $first_img = $data['images'][0];
            $trigger['image_url']       = is_string( $first_img ) ? $first_img : ( $first_img['url'] ?? $first_img['data'] ?? '' );
            $trigger['attachment_type']  = 'image';
            $trigger['attachment_url']   = $trigger['image_url'];
        }
    }

    // ── Facebook Messenger ──
    elseif ( $platform === 'facebook' ) {
        $trigger['client_id']    = isset( $data['client_id'] )    ? (string) $data['client_id']    : '';
        $trigger['chat_id']      = 'fb_' . $trigger['client_id'];
        $trigger['text']         = isset( $data['text'] )         ? (string) $data['text']         : ( isset( $data['message_text'] ) ? (string) $data['message_text'] : '' );
        $trigger['message_id']   = isset( $data['message_id'] )   ? (string) $data['message_id']   : '';
        $trigger['display_name'] = isset( $data['display_name'] ) ? (string) $data['display_name'] : '';
        $trigger['image_url']    = isset( $data['image_url'] )    ? (string) $data['image_url']    : '';
    }

    // ── Telegram ──
    elseif ( $platform === 'telegram' ) {
        $trigger['client_id']    = isset( $data['chat_id'] )      ? (string) $data['chat_id']      : '';
        $trigger['chat_id']      = $trigger['client_id'];
        $trigger['text']         = isset( $data['text'] )         ? (string) $data['text']         : '';
        $trigger['message_id']   = isset( $data['message_id'] )   ? (string) $data['message_id']   : '';
        $trigger['display_name'] = isset( $data['display_name'] ) ? (string) $data['display_name'] : '';
    }

    // ── Generic / pass-through ──
    else {
        // Copy fields trực tiếp nếu có
        foreach ( array( 'client_id', 'chat_id', 'session_id', 'user_id', 'display_name', 'text', 'message_id', 'attachment_url', 'attachment_type', 'image_url', 'audio_url', 'bot_id', 'bot_name' ) as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $trigger[ $key ] = (string) $data[ $key ];
            }
        }
    }

    // ── Common: fill empty attachment_type ──
    if ( empty( $trigger['attachment_type'] ) ) {
        if ( ! empty( $trigger['image_url'] ) || ! empty( $trigger['attachment_url'] ) ) {
            $url_to_check = $trigger['attachment_url'] ?: $trigger['image_url'];
            $trigger['attachment_type'] = bizcity_gateway_classify_attachment( $url_to_check );
        } else {
            $trigger['attachment_type'] = 'text';
        }
    }

    return $trigger;
}


/* ═══════════════════════════════════════════════════════════
 * 2. FIRE UNIFIED TRIGGER
 *
 * Chuẩn hóa, log, và fire waic_twf_process_flow
 * ═══════════════════════════════════════════════════════════ */

/**
 * Fire unified gateway trigger
 *
 * Sử dụng thay vì trực tiếp do_action('waic_twf_process_flow', ...)
 * để đảm bảo payload được chuẩn hóa và AIWU hooked flows được boot.
 *
 * @param array  $trigger  Trigger payload (đã normalize hoặc raw)
 * @param array  $raw      Raw webhook data (optional)
 * @param string $hookName Hook name, mặc định 'waic_twf_process_flow'
 * @return bool  True nếu có listener
 */
function bizcity_gateway_fire_trigger( array $trigger, array $raw = array(), $hookName = 'waic_twf_process_flow' ) {
    // Mark that gateway trigger has been fired for this request (prevents double-fire from bridge)
    $GLOBALS['bizcity_gateway_trigger_fired'] = true;

    // Log
    $platform = $trigger['platform'] ?? 'unknown';
    $text     = $trigger['text'] ?? '';
    error_log( sprintf(
        '[Gateway] 🚀 Firing %s | platform=%s | text=%s',
        $hookName,
        $platform,
        mb_substr( $text, 0, 60 )
    ) );

    // Delegate to bizcity_aiwu_fire_twf_process_flow nếu có (đã xử lý doHookedFlows)
    if ( function_exists( 'bizcity_aiwu_fire_twf_process_flow' ) ) {
        return bizcity_aiwu_fire_twf_process_flow( $trigger, $raw, $hookName );
    }

    // Fallback: fire trực tiếp
    do_action( $hookName, $trigger, $raw );
    return (int) has_action( $hookName ) > 0;
}


/* ═══════════════════════════════════════════════════════════
 * 3. UNIFIED SEND MESSAGE
 *
 * Gửi tin nhắn đến bất kỳ platform nào, tự detect từ chat_id
 * ═══════════════════════════════════════════════════════════ */

/**
 * Gửi tin nhắn đến bất kỳ kênh nào
 *
 * @param string $chat_id  Chat ID có prefix platform (zalo_xxx, webchat_xxx, sess_xxx, fb_xxx...)
 * @param string $message  Nội dung tin nhắn
 * @param string $type     Loại: 'text', 'image', 'file'
 * @param array  $extra    Dữ liệu bổ sung (image_url, caption, bot_id...)
 * @return array ['sent' => bool, 'error' => string, 'platform' => string]
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

    // Detect platform
    $platform = bizcity_gateway_detect_platform( $chat_id );

    error_log( sprintf( '[Gateway] 📤 Sending to %s | platform=%s | type=%s', $chat_id, $platform, $type ) );

    switch ( $platform ) {

        // ── Zalo Personal ──
        case 'zalo':
            $client_id = preg_replace( '/^zalo_/', '', $chat_id );
            if ( function_exists( 'send_zalo_botbanhang' ) ) {
                $send_type = ( $type === 'image' ) ? 'image' : 'text';
                $res = send_zalo_botbanhang( $message, $client_id, $send_type );
                return array( 'sent' => (bool) $res, 'error' => '', 'platform' => 'zalo' );
            }
            if ( function_exists( 'biz_send_message' ) ) {
                biz_send_message( $chat_id, $message );
                return array( 'sent' => true, 'error' => '', 'platform' => 'zalo' );
            }
            return array( 'sent' => false, 'error' => 'Zalo send function not available', 'platform' => 'zalo' );

        // ── Zalo Bot OA ──
        case 'zalo_bot':
            $raw_user_id = preg_replace( '/^zalobot_/', '', $chat_id );
            // Parse zalobot_{bot_id}_{zalo_user_id} format
            $parsed_bot_id = isset( $extra['bot_id'] ) ? (int) $extra['bot_id'] : 0;
            if ( ! $parsed_bot_id && preg_match( '/^(\d+)_(.+)$/', $raw_user_id, $m ) ) {
                $parsed_bot_id = (int) $m[1];
                $raw_user_id   = $m[2];
            }
            
            // Find correct blog and switch context
            $target_blog_id = bizcity_gateway_resolve_bot_blog_id( $parsed_bot_id );
            $switched = false;
            
            if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
                switch_to_blog( $target_blog_id );
                $switched = true;
                error_log( sprintf( '[Gateway] 🔄 Switched to blog #%d for bot #%d', $target_blog_id, $parsed_bot_id ) );
            }
            
            if ( class_exists( 'BizCity_Zalo_Bot_Database' ) && class_exists( 'BizCity_Zalo_Bot_API' ) ) {
                $bot_id = $parsed_bot_id;
                $db     = BizCity_Zalo_Bot_Database::instance();

                if ( $bot_id ) {
                    $bot = $db->get_bot( $bot_id );
                } else {
                    $bots = $db->get_active_bots();
                    $bot  = ! empty( $bots ) ? end( $bots ) : null;
                }

                if ( $bot && ! empty( $bot->bot_token ) ) {
                    $api      = new BizCity_Zalo_Bot_API( $bot->bot_token );
                    $response = $api->send_message( $raw_user_id, $message );
                    
                    if ( $switched ) {
                        restore_current_blog();
                    }
                    
                    if ( is_wp_error( $response ) ) {
                        return array( 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'zalo_bot' );
                    }
                    return array( 'sent' => true, 'error' => '', 'platform' => 'zalo_bot' );
                }
            }
            
            if ( $switched ) {
                restore_current_blog();
            }
            
            // Fallback qua zalo personal
            $fallback = 'zalo_' . $raw_user_id;
            if ( function_exists( 'biz_send_message' ) ) {
                biz_send_message( $fallback, $message );
                return array( 'sent' => true, 'error' => '', 'platform' => 'zalo_bot_fallback' );
            }
            return array( 'sent' => false, 'error' => 'Zalo Bot plugin not active', 'platform' => 'zalo_bot' );

        // ── WebChat ──
        case 'webchat':
            if ( class_exists( 'BizCity_WebChat_Trigger' ) ) {
                BizCity_WebChat_Trigger::instance()->send_message( $chat_id, $message );
                return array( 'sent' => true, 'error' => '', 'platform' => 'webchat' );
            }
            if ( class_exists( 'BizCity_WebChat_Database' ) ) {
                BizCity_WebChat_Database::instance()->log_message( array(
                    'session_id'    => $chat_id,
                    'user_id'       => 0,
                    'client_name'   => 'AI Bot',
                    'message_id'    => uniqid( 'gw_' ),
                    'message_text'  => $message,
                    'message_from'  => 'bot',
                    'platform_type' => 'WEBCHAT',
                ) );
                return array( 'sent' => true, 'error' => '', 'platform' => 'webchat' );
            }
            return array( 'sent' => false, 'error' => 'WebChat plugin not active', 'platform' => 'webchat' );

        // ── Admin Chat ──
        case 'adminchat':
            if ( class_exists( 'BizCity_WebChat_Database' ) ) {
                BizCity_WebChat_Database::instance()->log_message( array(
                    'session_id'    => $chat_id,
                    'user_id'       => 0,
                    'client_name'   => 'AI Bot',
                    'message_id'    => uniqid( 'gw_adminchat_' ),
                    'message_text'  => $message,
                    'message_from'  => 'bot',
                    'platform_type' => 'ADMINCHAT',
                ) );
                return array( 'sent' => true, 'error' => '', 'platform' => 'adminchat' );
            }
            return array( 'sent' => false, 'error' => 'AdminChat database not available', 'platform' => 'adminchat' );

        // ── Facebook ──
        case 'facebook':
            $fb_id = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
            if ( function_exists( 'fbm_send_text_to_user' ) ) {
                $res = fbm_send_text_to_user( $fb_id, $message );
                return array( 'sent' => (bool) $res, 'error' => '', 'platform' => 'facebook' );
            }
            return array( 'sent' => false, 'error' => 'Facebook send function not available', 'platform' => 'facebook' );

        // ── Telegram ──
        case 'telegram':
            if ( function_exists( 'twf_telegram_send_message' ) ) {
                twf_telegram_send_message( $chat_id, $message, 'HTML' );
                return array( 'sent' => true, 'error' => '', 'platform' => 'telegram' );
            }
            return array( 'sent' => false, 'error' => 'Telegram function not available', 'platform' => 'telegram' );

        // ── Fallback ──
        default:
            if ( function_exists( 'biz_send_message' ) ) {
                biz_send_message( $chat_id, $message );
                return array( 'sent' => true, 'error' => '', 'platform' => 'fallback' );
            }
            return array( 'sent' => false, 'error' => 'No send method available for: ' . $chat_id, 'platform' => 'unknown' );
    }
}


/* ═══════════════════════════════════════════════════════════
 * 4. DETECT PLATFORM FROM CHAT ID
 * ═══════════════════════════════════════════════════════════ */

/**
 * Nhận diện platform từ chat_id prefix
 *
 * @param string $chat_id
 * @return string Platform name
 */
function bizcity_gateway_detect_platform( $chat_id ) {
    $chat_id = (string) $chat_id;

    // Delegate to new Gateway Bridge if available (Phase 1 compat)
    if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
        $result = BizCity_Gateway_Bridge::instance()->detect_platform( $chat_id );
        // Bridge returns uppercase — convert to legacy lowercase format
        return strtolower( $result );
    }

    if ( strpos( $chat_id, 'zalobot_' )    === 0 ) return 'zalo_bot';
    if ( strpos( $chat_id, 'webchat_' )    === 0 ) return 'webchat';
    if ( strpos( $chat_id, 'sess_' )       === 0 ) return 'webchat';
    if ( strpos( $chat_id, 'wcs_' )        === 0 ) return 'webchat'; // V3 session UUID
    if ( strpos( $chat_id, 'adminchat_' )  === 0 ) return 'adminchat';
    if ( strpos( $chat_id, 'admin_chat_' ) === 0 ) return 'adminchat'; // backward compat
    if ( strpos( $chat_id, 'admin_' )       === 0 ) return 'adminchat'; // backward compat
    if ( strpos( $chat_id, 'fb_' )         === 0 ) return 'facebook';
    if ( strpos( $chat_id, 'messenger_' )  === 0 ) return 'facebook';
    if ( strpos( $chat_id, 'zalo_' )       === 0 ) return 'zalo';

    // Numeric chat_id → Telegram
    if ( preg_match( '/^-?\d+$/', $chat_id ) ) return 'telegram';

    return 'unknown';
}


/* ═══════════════════════════════════════════════════════════
 * 4.5 RESOLVE BOT BLOG ID
 *
 * Scan multisite blogs to find where bot_id exists
 * ═══════════════════════════════════════════════════════════ */

/**
 * Resolve blog_id for a given bot_id
 *
 * Scans all blogs in multisite to find where the bot exists
 *
 * @param int $bot_id
 * @return int blog_id or 0 if not found
 */
function bizcity_gateway_resolve_bot_blog_id( $bot_id ) {
    global $wpdb;

    if ( ! $bot_id ) {
        return 0;
    }

    // Cache key for this request
    static $cache = array();
    if ( isset( $cache[ $bot_id ] ) ) {
        return $cache[ $bot_id ];
    }

    // Priority 1: Check cached source_blog_id from webhook handler
    $cached_blog_id = get_transient( 'zalobot_source_blog_' . $bot_id );
    if ( $cached_blog_id ) {
        $cache[ $bot_id ] = (int) $cached_blog_id;
        error_log( sprintf( '[Gateway] 🎯 Using cached source_blog_id=%d for bot #%d', $cached_blog_id, $bot_id ) );
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
            // [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache
            $primary_blog = class_exists( 'BizCity_User_Meta_Cache' )
                ? BizCity_User_Meta_Cache::get( $wp_user_id, 'primary_blog', '' )
                : get_user_meta( $wp_user_id, 'primary_blog', true );
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
                error_log( sprintf( '[Gateway] 🔍 Found bot #%d in blog #%d', $bot_id, $blog_id ) );
                return $cache[ $bot_id ];
            }
        }
    }

    $cache[ $bot_id ] = 0;
    return 0;
}


/* ═══════════════════════════════════════════════════════════
 * 5. CLASSIFY ATTACHMENT URL
 * ═══════════════════════════════════════════════════════════ */

/**
 * Phân loại attachment từ URL
 *
 * @param string $url
 * @return string 'image', 'audio', 'video', 'file', 'unknown'
 */
function bizcity_gateway_classify_attachment( $url ) {
    $url = (string) $url;
    if ( $url === '' ) return 'text';

    // Base64 data
    if ( strpos( $url, 'data:image/' ) === 0 ) return 'image';
    if ( strpos( $url, 'data:audio/' ) === 0 ) return 'audio';

    $path = (string) parse_url( $url, PHP_URL_PATH );
    $ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

    $audio = array( 'aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga', 'opus', 'webm' );
    $image = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff' );
    $video = array( 'mp4', 'mov', 'avi', 'wmv' );

    if ( $ext && in_array( $ext, $audio, true ) ) return 'audio';
    if ( $ext && in_array( $ext, $image, true ) ) return 'image';
    if ( $ext && in_array( $ext, $video, true ) ) return 'video';
    if ( $ext ) return 'file';

    return 'unknown';
}


/* ═══════════════════════════════════════════════════════════
 * 6. BRIDGE: ADMINCHAT / WEBCHAT → AUTOMATION TRIGGERS
 *
 * Hook vào bizcity_chat_message_processed (từ BizCity_Chat_Gateway)
 * để fire waic_twf_process_flow cho ADMINCHAT và WEBCHAT.
 *
 * Điều này cho phép workflow automation lắng nghe từ admin chat
 * và webchat mà không cần code custom.
 * ═══════════════════════════════════════════════════════════ */

if ( ! has_action( 'bizcity_chat_message_processed', 'bizcity_gateway_bridge_chat_to_automation' ) ) {
    add_action( 'bizcity_chat_message_processed', 'bizcity_gateway_bridge_chat_to_automation', 10, 1 );
}

/**
 * Bridge: Khi Chat Gateway xử lý xong message → fire automation trigger
 *
 * @param array $event_data Dữ liệu từ bizcity_chat_message_processed action
 */
function bizcity_gateway_bridge_chat_to_automation( $event_data ) {
    if ( ! is_array( $event_data ) ) return;

    // Skip if new Channel Gateway already handled this at @9
    if ( defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) {
        return;
    }

    $platform_type = strtolower( $event_data['platform_type'] ?? '' );

    // Map platform_type → platform
    $platform_map = array(
        'adminchat' => 'adminchat',
        'webchat'   => 'webchat',
    );

    $platform = isset( $platform_map[ $platform_type ] ) ? $platform_map[ $platform_type ] : '';
    if ( empty( $platform ) ) return;

    // WEBCHAT: skip workflow automation — webchat is knowledge-only, should NOT trigger TWF
    if ( $platform === 'webchat' ) {
        return;
    }

    // Skip if workflow trigger was already fired earlier in this request
    // (e.g. by handle_sse early trigger before intent processing)
    if ( ! empty( $GLOBALS['bizcity_gateway_trigger_fired'] ) ) {
        return;
    }

    // Build normalized trigger
    $trigger = bizcity_gateway_normalize_trigger( $event_data, $platform );

    // Prevent infinite loop: don't fire if already in a trigger context
    global $waic_current_trigger;
    if ( ! empty( $waic_current_trigger ) ) {
        error_log( '[Gateway Bridge] Skipping — already in trigger context' );
        return;
    }

    // Fire unified trigger
    bizcity_gateway_fire_trigger( $trigger, (array) $event_data );
}


/* ═══════════════════════════════════════════════════════════
 * 7. OVERRIDE: twf_send_message_override cho webchat/adminchat
 *
 * Khi biz_send_message() hoặc twf_telegram_send_message() được
 * gọi với chat_id webchat_xxx hoặc adminchat_xxx, route đến
 * WebChat/AdminChat thay vì Telegram.
 * ═══════════════════════════════════════════════════════════ */

if ( ! has_filter( 'twf_send_message_override', 'bizcity_gateway_override_send_for_webchat' ) ) {
    add_filter( 'twf_send_message_override', 'bizcity_gateway_override_send_for_webchat', 8, 5 );
}

/**
 * Override twf_telegram_send_message cho webchat và adminchat chat_id
 *
 * @param mixed  $default
 * @param string $chat_id
 * @param string $text
 * @param string $parse_mode
 * @param mixed  $reply_markup
 * @return mixed
 */
function bizcity_gateway_override_send_for_webchat( $default, $chat_id, $text, $parse_mode = '', $reply_markup = null ) {
    // Nếu filter trước đó đã xử lý → pass through (including new Gateway at @7)
    if ( $default !== false ) return $default;

    // Skip if new Channel Gateway already handled at @7
    if ( defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) {
        return false;
    }

    $platform = bizcity_gateway_detect_platform( $chat_id );

    if ( $platform === 'webchat' || $platform === 'adminchat' ) {
        $result = bizcity_gateway_send_message( $chat_id, $text );
        return $result['sent'] ? array( 'status' => 'sent', 'platform' => $platform ) : false;
    }

    // Zalo Bot OA routing (ngoài zalo_ personal)
    if ( $platform === 'zalo_bot' ) {
        $result = bizcity_gateway_send_message( $chat_id, $text );
        return $result['sent'] ? array( 'status' => 'sent', 'platform' => 'zalo_bot' ) : false;
    }

    return false; // Fallback — để logic gốc xử lý
}
