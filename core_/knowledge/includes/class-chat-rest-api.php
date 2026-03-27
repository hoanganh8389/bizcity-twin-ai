<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat REST API — Clean REST endpoints wrapping Chat Gateway
 * Namespace: bizcity-chat/v1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Endpoints:
 *   POST   /send            — Send message (non-streaming)
 *   GET    /history          — Chat history
 *   DELETE /history          — Clear history
 *   GET    /stream           — SSE stream (token-by-token)
 *   GET    /session          — Get/create session ID
 *   POST   /auth/login       — AJAX-free login
 *   POST   /auth/register    — AJAX-free register
 *
 * Auth: Uses WordPress nonce (X-WP-Nonce header) + cookie auth.
 *       Guest endpoints available for WEBCHAT platform.
 *
 * @package  BizCity_Knowledge
 * @version  1.0.0
 * @since    2026-03-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Chat_REST_API {

    /* ── Singleton ─────────────────────────────────────────── */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const NAMESPACE_V1 = 'bizcity-chat/v1';

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ================================================================
     * REGISTER ROUTES
     * ================================================================ */
    public function register_routes() {

        // POST /send — Send message (non-streaming)
        register_rest_route( self::NAMESPACE_V1, '/send', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_send' ),
            'permission_callback' => array( $this, 'permission_send' ),
            'args'                => array(
                'message'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
                'session_id'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'character_id'  => array( 'type' => 'integer', 'default' => 0 ),
                'images'        => array( 'type' => 'array', 'default' => array() ),
                'plugin_slug'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'routing_mode'  => array( 'type' => 'string', 'default' => 'automatic', 'sanitize_callback' => 'sanitize_text_field' ),
                'provider_hint' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'platform_type' => array( 'type' => 'string', 'default' => 'WEBCHAT', 'sanitize_callback' => 'sanitize_text_field' ),
                'tool_goal'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'tool_name'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // GET /history — Chat history
        register_rest_route( self::NAMESPACE_V1, '/history', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_history' ),
            'permission_callback' => '__return_true', // Public — guest can read own session
            'args'                => array(
                'session_id'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'platform_type' => array( 'type' => 'string', 'default' => 'WEBCHAT', 'sanitize_callback' => 'sanitize_text_field' ),
                'limit'         => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
            ),
        ) );

        // DELETE /history — Clear history
        register_rest_route( self::NAMESPACE_V1, '/history', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_clear' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'session_id'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'platform_type' => array( 'type' => 'string', 'default' => 'WEBCHAT', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // GET /session — Get or create session ID
        register_rest_route( self::NAMESPACE_V1, '/session', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_session' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'platform_type' => array( 'type' => 'string', 'default' => 'WEBCHAT', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // POST /auth/login — Login without admin-ajax
        register_rest_route( self::NAMESPACE_V1, '/auth/login', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_login' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'username' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'password' => array( 'type' => 'string', 'required' => true ),
            ),
        ) );

        // POST /auth/register — Register without admin-ajax
        register_rest_route( self::NAMESPACE_V1, '/auth/register', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_register' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'phone'        => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'display_name' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'password'     => array( 'type' => 'string', 'required' => true ),
            ),
        ) );

        // GET /emotion — Get current emotional state for a message
        register_rest_route( self::NAMESPACE_V1, '/emotion', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_emotion' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'text' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );

        // GET /bond — Get user's bond score
        register_rest_route( self::NAMESPACE_V1, '/bond', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_bond' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
    }

    /* ================================================================
     * PERMISSION CALLBACKS
     * ================================================================ */

    /**
     * Send permission: logged-in users always allowed.
     * Guests allowed for WEBCHAT platform only.
     */
    public function permission_send( $request ) {
        if ( is_user_logged_in() ) {
            return true;
        }
        $platform = $request->get_param( 'platform_type' );
        return in_array( $platform, array( 'WEBCHAT', 'webchat' ), true );
    }

    /* ================================================================
     * ENDPOINT HANDLERS
     * ================================================================ */

    /**
     * POST /send — Send message to AI
     */
    public function rest_send( $request ) {
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Gateway not available' ), 500 );
        }

        $message       = $request->get_param( 'message' );
        $session_id    = $request->get_param( 'session_id' );
        $character_id  = intval( $request->get_param( 'character_id' ) );
        $images        = $request->get_param( 'images' );
        $plugin_slug   = $request->get_param( 'plugin_slug' );
        $routing_mode  = $request->get_param( 'routing_mode' );
        $provider_hint = $request->get_param( 'provider_hint' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );
        $tool_goal     = $request->get_param( 'tool_goal' );
        $tool_name     = $request->get_param( 'tool_name' );

        if ( empty( $message ) && empty( $images ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Tin nhắn trống' ), 400 );
        }

        if ( ! $character_id ) {
            $character_id = $this->get_default_character_id();
        }

        if ( ! $session_id ) {
            $session_id = $this->generate_session_id( $platform_type );
        }

        $user_id     = get_current_user_id();
        $user        = wp_get_current_user();
        $client_name = $user->ID ? ( $user->display_name ?: $user->user_login ) : 'Guest';

        // Log user message
        $this->log_message( array(
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'client_name'   => $client_name,
            'message_id'    => uniqid( 'rest_' ),
            'message_text'  => $message ?: '[Image]',
            'message_from'  => 'user',
            'message_type'  => ! empty( $images ) ? 'image' : 'text',
            'attachments'   => is_array( $images ) ? $images : array(),
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
        ) );

        // Pre-AI filter (plugin gathering, intent engine intercept)
        // SKIPPED for WEBCHAT — frontend widget is knowledge-only, no execution.
        $pre_reply = null;
        if ( $platform_type !== 'WEBCHAT' ) {
            $pre_reply = apply_filters( 'bizcity_chat_pre_ai_response', null, array(
                'message'       => $message,
                'character_id'  => $character_id,
                'session_id'    => $session_id,
                'user_id'       => $user_id,
                'platform_type' => $platform_type,
                'images'        => is_array( $images ) ? $images : array(),
                'plugin_slug'   => $plugin_slug,
                'provider_hint' => $provider_hint,
                'routing_mode'  => $routing_mode,
                'tool_goal'     => $tool_goal,
                'tool_name'     => $tool_name,
            ) );
        }

        if ( is_array( $pre_reply ) && ! empty( $pre_reply['message'] ) ) {
            $bot_msg_id = uniqid( 'rest_bot_' );
            $effective_slug = isset( $pre_reply['plugin_slug'] ) ? $pre_reply['plugin_slug'] : $plugin_slug;

            $this->log_message( array(
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI Assistant',
                'message_id'    => $bot_msg_id,
                'message_text'  => $pre_reply['message'],
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'plugin_slug'   => $effective_slug,
            ) );

            return new WP_REST_Response( array(
                'success'     => true,
                'data'        => array(
                    'message'        => $pre_reply['message'],
                    'plugin_slug'    => $effective_slug,
                    'action'         => isset( $pre_reply['action'] ) ? $pre_reply['action'] : '',
                    'goal'           => isset( $pre_reply['goal'] ) ? $pre_reply['goal'] : '',
                    'goal_label'     => isset( $pre_reply['goal_label'] ) ? $pre_reply['goal_label'] : '',
                    'focus_mode'     => isset( $pre_reply['focus_mode'] ) ? $pre_reply['focus_mode'] : 'none',
                    'bot_message_id' => $bot_msg_id,
                    'session_id'     => $session_id,
                    'conversation_id' => isset( $pre_reply['conversation_id'] ) ? $pre_reply['conversation_id'] : '',
                ),
            ), 200 );
        }

        // AI Response
        try {
            $reply_data = $gateway->get_ai_response(
                $character_id, $message, is_array( $images ) ? $images : array(),
                $session_id, '[]', $user_id, $platform_type
            );
        } catch ( Exception $e ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Có lỗi xảy ra: ' . $e->getMessage(),
            ), 500 );
        }

        $bot_msg_id = uniqid( 'rest_bot_' );
        $this->log_message( array(
            'session_id'    => $session_id,
            'user_id'       => 0,
            'client_name'   => isset( $reply_data['character_name'] ) ? $reply_data['character_name'] : 'AI Assistant',
            'message_id'    => $bot_msg_id,
            'message_text'  => $reply_data['message'],
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
        ) );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'message'        => $reply_data['message'],
                'provider'       => isset( $reply_data['provider'] ) ? $reply_data['provider'] : '',
                'model'          => isset( $reply_data['model'] ) ? $reply_data['model'] : '',
                'usage'          => isset( $reply_data['usage'] ) ? $reply_data['usage'] : array(),
                'vision_used'    => isset( $reply_data['vision_used'] ) ? $reply_data['vision_used'] : false,
                'plugin_slug'    => $plugin_slug,
                'focus_mode'     => 'none',
                'bot_message_id' => $bot_msg_id,
                'session_id'     => $session_id,
            ),
        ), 200 );
    }

    /**
     * GET /history — Retrieve chat history
     */
    public function rest_history( $request ) {
        $session_id    = $request->get_param( 'session_id' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );
        $limit         = intval( $request->get_param( 'limit' ) );

        $history = $this->get_history( $session_id, $platform_type, $limit );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'messages'   => $history,
                'count'      => count( $history ),
                'session_id' => $session_id,
            ),
        ), 200 );
    }

    /**
     * DELETE /history — Clear chat history
     */
    public function rest_clear( $request ) {
        $session_id    = $request->get_param( 'session_id' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $deleted = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
            $deleted = $wpdb->delete( $table, array(
                'session_id'    => $session_id,
                'platform_type' => $platform_type,
            ) );
        }

        // Close conversation
        $conv_table = $wpdb->prefix . 'bizcity_webchat_conversations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$conv_table'" ) === $conv_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$conv_table} SET status = 'closed', ended_at = NOW() WHERE session_id = %s AND platform_type = %s",
                $session_id, $platform_type
            ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array( 'cleared' => true, 'deleted_count' => intval( $deleted ) ),
        ), 200 );
    }

    /**
     * GET /session — Get or create session ID
     */
    public function rest_session( $request ) {
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );
        $session_id    = $this->generate_session_id( $platform_type );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'session_id'    => $session_id,
                'platform_type' => $platform_type,
                'user_id'       => get_current_user_id(),
                'is_guest'      => ! is_user_logged_in(),
            ),
        ), 200 );
    }

    /**
     * POST /auth/login — Authenticate user
     */
    public function rest_login( $request ) {
        $username = $request->get_param( 'username' );
        $password = $request->get_param( 'password' );

        $user = wp_signon( array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ) );

        if ( is_wp_error( $user ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $user->get_error_message(),
            ), 401 );
        }

        wp_set_current_user( $user->ID );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'user_id'      => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
            ),
        ), 200 );
    }

    /**
     * POST /auth/register — Create new user
     */
    public function rest_register( $request ) {
        $phone        = $request->get_param( 'phone' );
        $display_name = $request->get_param( 'display_name' );
        $password     = $request->get_param( 'password' );

        // Generate username from phone
        $username = 'user_' . preg_replace( '/[^0-9]/', '', $phone );

        if ( username_exists( $username ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Số điện thoại đã được đăng ký',
            ), 409 );
        }

        $user_id = wp_create_user( $username, $password, $username . '@phone.local' );
        if ( is_wp_error( $user_id ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $user_id->get_error_message(),
            ), 400 );
        }

        // Update display name and meta
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $display_name ?: $phone,
        ) );
        update_user_meta( $user_id, 'phone', $phone );

        // Auto-login
        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'user_id'      => $user_id,
                'display_name' => $display_name ?: $phone,
                'nonce'        => wp_create_nonce( 'wp_rest' ),
            ),
        ), 201 );
    }

    /**
     * GET /emotion — Estimate emotion for a text string
     */
    public function rest_emotion( $request ) {
        $text = $request->get_param( 'text' );

        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Emotional Memory not available',
            ), 503 );
        }

        $result = BizCity_Emotional_Memory::instance()->estimate_emotion( $text );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $result,
        ), 200 );
    }

    /**
     * GET /bond — Get current user's bond score
     */
    public function rest_bond( $request ) {
        $user_id = get_current_user_id();

        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Emotional Memory not available',
            ), 503 );
        }

        $bond = BizCity_Emotional_Memory::instance()->get_bond_score( $user_id );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'bond_score' => $bond,
                'user_id'    => $user_id,
            ),
        ), 200 );
    }

    /* ================================================================
     * INTERNAL HELPERS
     * ================================================================ */

    private function get_gateway() {
        if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
            return BizCity_Chat_Gateway::instance();
        }
        return null;
    }

    private function get_default_character_id() {
        $id = intval( get_option( 'bizcity_webchat_default_character_id', 0 ) );
        if ( ! $id ) {
            $bot_setup = get_option( 'pmfacebook_options', array() );
            $id = isset( $bot_setup['default_character_id'] ) ? intval( $bot_setup['default_character_id'] ) : 0;
        }
        return $id;
    }

    private function generate_session_id( $platform_type = 'WEBCHAT' ) {
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();

        if ( $user_id ) {
            return strtolower( $platform_type ) . '_' . $blog_id . '_' . $user_id;
        }

        // Guest: use cookie-based session
        if ( isset( $_COOKIE['bizcity_session_id'] ) ) {
            return sanitize_text_field( $_COOKIE['bizcity_session_id'] );
        }

        $session_id = 'sess_' . wp_generate_uuid4();
        if ( ! headers_sent() ) {
            setcookie( 'bizcity_session_id', $session_id, time() + ( 86400 * 30 ), '/' );
        }

        return $session_id;
    }

    private function get_history( $session_id, $platform_type = 'WEBCHAT', $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return array();
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE session_id = %s AND platform_type = %s
             ORDER BY id ASC
             LIMIT %d",
            $session_id, $platform_type, $limit
        ) );

        $history = array();
        foreach ( (array) $rows as $row ) {
            $meta        = $row->meta ? json_decode( $row->meta, true ) : array();
            $attachments = $row->attachments ? json_decode( $row->attachments, true ) : array();

            $images = array();
            if ( is_array( $attachments ) ) {
                foreach ( $attachments as $att ) {
                    if ( is_string( $att ) && $att !== '' ) {
                        $images[] = $att;
                    } elseif ( is_array( $att ) ) {
                        $url = isset( $att['url'] ) ? $att['url'] : ( isset( $att['data'] ) ? $att['data'] : '' );
                        if ( $url ) {
                            $images[] = $url;
                        }
                    }
                }
            }

            $history[] = array(
                'id'          => intval( $row->id ),
                'message_id'  => $row->message_id,
                'message'     => $row->message_text,
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $attachments,
                'images'      => $images,
                'created_at'  => $row->created_at,
                'meta'        => $meta,
            );
        }

        return $history;
    }

    private function log_message( $data ) {
        $gateway = $this->get_gateway();
        if ( $gateway && method_exists( $gateway, 'log_message' ) ) {
            // Gateway's log_message is private — use the raw DB insert
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_webchat_messages';

            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
                return;
            }

            $wpdb->insert( $table, array(
                'session_id'    => isset( $data['session_id'] ) ? $data['session_id'] : '',
                'user_id'       => intval( isset( $data['user_id'] ) ? $data['user_id'] : 0 ),
                'client_name'   => isset( $data['client_name'] ) ? $data['client_name'] : '',
                'message_id'    => isset( $data['message_id'] ) ? $data['message_id'] : uniqid( 'msg_' ),
                'message_text'  => isset( $data['message_text'] ) ? $data['message_text'] : '',
                'message_from'  => isset( $data['message_from'] ) ? $data['message_from'] : 'user',
                'message_type'  => isset( $data['message_type'] ) ? $data['message_type'] : 'text',
                'attachments'   => isset( $data['attachments'] ) ? wp_json_encode( $data['attachments'] ) : '[]',
                'platform_type' => isset( $data['platform_type'] ) ? $data['platform_type'] : 'WEBCHAT',
                'meta'          => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : '{}',
                'created_at'    => current_time( 'mysql' ),
            ) );
        }
    }
}
