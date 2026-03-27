<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat Send Service — Unified chat send pipeline (AJAX + REST)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      2.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Chat_Send_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send a chat message through the full AI pipeline.
     *
     * @param array $args {
     *   @type string $message        User message text
     *   @type int    $character_id   Character to chat with (0 = default)
     *   @type string $session_id     Session identifier
     *   @type array  $images         Array of image URLs
     *   @type string $platform_type  ADMINCHAT | WEBCHAT
     *   @type string $plugin_slug    Plugin context (@ mention)
     *   @type string $routing_mode   automatic | direct | ...
     *   @type string $provider_hint  Hint for LLM provider
     *   @type int    $user_id        User ID (0 = guest)
     *   @type string $history_json   JSON-encoded conversation history
     * }
     * @return array|WP_Error {
     *   reply, message, provider, model, usage, vision_used,
     *   plugin_slug, bot_message_id, conversation_id, action, goal, goal_label
     * }
     */
    public function send( $args ) {
        $message       = $args['message'] ?? '';
        $character_id  = intval( $args['character_id'] ?? 0 );
        $session_id    = $args['session_id'] ?? '';
        $images        = $args['images'] ?? [];
        $platform_type = strtoupper( $args['platform_type'] ?? 'WEBCHAT' );
        $plugin_slug   = $args['plugin_slug'] ?? '';
        $routing_mode  = $args['routing_mode'] ?? 'automatic';
        $provider_hint = $args['provider_hint'] ?? '';
        $user_id       = intval( $args['user_id'] ?? get_current_user_id() );
        $history_json  = $args['history_json'] ?? '[]';

        $history_svc = BizCity_Chat_History_Service::instance();
        $session_svc = BizCity_Session_Service::instance();

        // Resolve defaults
        if ( ! $character_id ) {
            $character_id = $session_svc->get_default_character_id();
        }

        if ( empty( $message ) && empty( $images ) ) {
            return new WP_Error( 'empty_message', 'Tin nhắn trống' );
        }

        if ( ! $session_id ) {
            $session_id = $session_svc->generate_session_id( $platform_type, $user_id );
        }

        $user        = get_userdata( $user_id );
        $client_name = ( $user && $user->ID ) ? ( $user->display_name ?: $user->user_login ) : 'Guest';

        // ── Log user message ──
        $history_svc->log_message( [
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'client_name'   => $client_name,
            'message_id'    => uniqid( 'svc_' ),
            'message_text'  => $message ?: '[Image]',
            'message_from'  => 'user',
            'message_type'  => ! empty( $images ) ? 'image' : 'text',
            'attachments'   => $images,
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
        ] );

        // ── Pre-AI filter (intent engine, plugin gathering, etc.) ──
        // SKIPPED for WEBCHAT — frontend widget is knowledge-only, no execution.
        $pre_reply = null;
        if ( $platform_type !== 'WEBCHAT' ) {
            $pre_reply = apply_filters( 'bizcity_chat_pre_ai_response', null, [
                'message'       => $message,
                'character_id'  => $character_id,
                'session_id'    => $session_id,
                'user_id'       => $user_id,
                'platform_type' => $platform_type,
                'images'        => $images,
                'plugin_slug'   => $plugin_slug,
                'provider_hint' => $provider_hint,
                'routing_mode'  => $routing_mode,
            ] );
        }

        // Pre-AI filter intercepted → return its response
        if ( is_array( $pre_reply ) && ! empty( $pre_reply['message'] ) ) {
            $effective_slug = $pre_reply['plugin_slug'] ?? $plugin_slug;
            $bot_msg_id     = uniqid( 'intent_bot_' );

            $history_svc->log_message( [
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI Assistant',
                'message_id'    => $bot_msg_id,
                'message_text'  => $pre_reply['message'],
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'plugin_slug'   => $effective_slug,
                'meta'          => [
                    'character_id' => $character_id,
                    'via'          => $pre_reply['action'] ?? 'pre_ai_filter',
                    'goal'         => $pre_reply['goal'] ?? '',
                    'plugin_slug'  => $effective_slug,
                ],
            ] );

            do_action( 'bizcity_chat_message_processed', [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'user_message'  => $message,
                'bot_reply'     => $pre_reply['message'],
                'images'        => $images,
                'goal'          => $pre_reply['goal'] ?? '',
                'goal_label'    => $pre_reply['goal_label'] ?? '',
            ] );

            return [
                'reply'           => $pre_reply['message'],
                'message'         => $pre_reply['message'],
                'plugin_slug'     => $effective_slug,
                'bot_message_id'  => $bot_msg_id,
                'conversation_id' => $pre_reply['conversation_id'] ?? '',
                'action'          => $pre_reply['action'] ?? '',
                'goal'            => $pre_reply['goal'] ?? '',
                'goal_label'      => $pre_reply['goal_label'] ?? '',
                'via'             => 'pre_ai_filter',
            ];
        }

        // ── AI Response via Chat Gateway ──
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            return new WP_Error( 'no_gateway', 'Chat Gateway not available' );
        }

        try {
            $reply_data = $gateway->get_ai_response(
                $character_id,
                $message,
                $images,
                $session_id,
                $history_json,
                $user_id,
                $platform_type
            );
        } catch ( Exception $e ) {
            error_log( '[ChatSendService] Error: ' . $e->getMessage() );
            return new WP_Error( 'ai_error', 'Có lỗi xảy ra: ' . $e->getMessage() );
        }

        // ── Log bot reply ──
        $bot_msg_id = uniqid( 'svc_bot_' );
        $history_svc->log_message( [
            'session_id'    => $session_id,
            'user_id'       => 0,
            'client_name'   => $reply_data['character_name'] ?? 'AI Assistant',
            'message_id'    => $bot_msg_id,
            'message_text'  => $reply_data['message'],
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
            'meta'          => [
                'provider'     => $reply_data['provider'] ?? '',
                'model'        => $reply_data['model'] ?? '',
                'usage'        => $reply_data['usage'] ?? [],
                'vision_used'  => $reply_data['vision_used'] ?? false,
                'character_id' => $character_id,
                'plugin_slug'  => $plugin_slug,
                'routing_mode' => $routing_mode,
            ],
        ] );

        do_action( 'bizcity_chat_message_processed', [
            'platform_type' => $platform_type,
            'session_id'    => $session_id,
            'character_id'  => $character_id,
            'user_id'       => $user_id,
            'user_message'  => $message,
            'bot_reply'     => $reply_data['message'],
            'images'        => $images,
            'provider'      => $reply_data['provider'] ?? '',
            'model'         => $reply_data['model'] ?? '',
            'plugin_slug'   => $plugin_slug,
        ] );

        return [
            'reply'          => $reply_data['message'],
            'message'        => $reply_data['message'],
            'provider'       => $reply_data['provider'] ?? '',
            'model'          => $reply_data['model'] ?? '',
            'usage'          => $reply_data['usage'] ?? [],
            'vision_used'    => $reply_data['vision_used'] ?? false,
            'plugin_slug'    => $plugin_slug,
            'bot_message_id' => $bot_msg_id,
            'via'            => 'ai_response',
        ];
    }

    /**
     * Parse images from raw POST data.
     *
     * @param array $post_data  $_POST array
     * @return array  Array of image URLs
     */
    public function parse_images( $post_data ) {
        $images = [];

        if ( ! empty( $post_data['images'] ) ) {
            $raw = json_decode( stripslashes( $post_data['images'] ?? '[]' ), true ) ?: [];
            if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
                $images = bizcity_convert_images_to_media_urls( $raw );
            } else {
                $images = $raw;
            }
        }

        if ( ! empty( $post_data['image_data'] ) ) {
            $single = $post_data['image_data'];
            if ( function_exists( 'bizcity_save_base64_to_media' ) && strpos( $single, 'data:image/' ) === 0 ) {
                $media = bizcity_save_base64_to_media( $single );
                if ( ! is_wp_error( $media ) ) {
                    $images[] = $media['url'];
                }
            } else {
                $images[] = $single;
            }
        }

        return $images;
    }

    /**
     * @return BizCity_Chat_Gateway|null
     */
    private function get_gateway() {
        if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
            return BizCity_Chat_Gateway::instance();
        }
        return null;
    }
}
