<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Session Service — Shared session CRUD for admin & REST API
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

class BizCity_Session_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ──────────────────────────────────────────────────────────
     * DB helper
     * ────────────────────────────────────────────────────────── */

    /**
     * @return BizCity_WebChat_Database|null
     */
    private function get_db() {
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            return BizCity_WebChat_Database::instance();
        }
        return null;
    }

    /* ──────────────────────────────────────────────────────────
     * Session ID generation (unifies 3 implementations)
     * ────────────────────────────────────────────────────────── */

    /**
     * Generate or retrieve a session ID.
     *
     * @param string $platform_type  ADMINCHAT | WEBCHAT
     * @param int    $user_id        0 = guest
     * @return string
     */
    public function generate_session_id( $platform_type = 'WEBCHAT', $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        // Logged-in user on ADMINCHAT → deterministic session ID
        if ( $user_id && $platform_type === 'ADMINCHAT' ) {
            return 'adminchat_' . get_current_blog_id() . '_' . $user_id;
        }

        // Logged-in user on WEBCHAT → deterministic
        if ( $user_id ) {
            return 'webchat_' . get_current_blog_id() . '_' . $user_id;
        }

        // Guest: cookie-based
        if ( isset( $_COOKIE['bizcity_session_id'] ) ) {
            return $_COOKIE['bizcity_session_id'];
        }

        $session_id = 'sess_' . wp_generate_uuid4();
        if ( ! headers_sent() ) {
            setcookie( 'bizcity_session_id', $session_id, time() + 86400 * 30, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }

        return $session_id;
    }

    /**
     * Get default character ID (unifies 3 implementations).
     *
     * @return int
     */
    public function get_default_character_id() {
        $cid = intval( get_option( 'bizcity_webchat_default_character_id', 0 ) );

        if ( ! $cid ) {
            $opts = get_option( 'pmfacebook_options', [] );
            $cid  = isset( $opts['default_character_id'] ) ? intval( $opts['default_character_id'] ) : 0;
        }

        if ( ! $cid && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $db    = BizCity_Knowledge_Database::instance();
            $chars = $db->get_characters( [ 'status' => 'active', 'limit' => 1 ] );
            if ( ! empty( $chars ) ) {
                $cid = (int) $chars[0]->id;
            }
        }

        return $cid;
    }

    /**
     * Detect platform type from request context.
     *
     * @return string  ADMINCHAT | WEBCHAT
     */
    public function detect_platform_type() {
        // Explicit from request
        $pt_raw = $_POST['platform_type'] ?? $_GET['platform_type'] ?? '';
        if ( $pt_raw ) {
            $pt = strtoupper( sanitize_text_field( $pt_raw ) );
            if ( in_array( $pt, [ 'ADMINCHAT', 'WEBCHAT' ], true ) ) {
                return $pt;
            }
        }

        // Infer from AJAX action name
        $action = $_POST['action'] ?? '';
        if ( strpos( $action, 'admin_chat' ) !== false ) {
            return 'ADMINCHAT';
        }

        // Admin context
        if ( is_admin() || ( defined( 'DOING_AJAX' ) && current_user_can( 'edit_posts' ) && strpos( $action, 'bizcity_chat_' ) === 0 ) ) {
            if ( current_user_can( 'edit_posts' ) ) {
                return 'ADMINCHAT';
            }
        }

        return 'WEBCHAT';
    }

    /* ──────────────────────────────────────────────────────────
     * Session CRUD
     * ────────────────────────────────────────────────────────── */

    /**
     * List sessions for a user.
     *
     * @param int    $user_id
     * @param string $platform     ADMINCHAT | WEBCHAT
     * @param string $project_id   Optional project filter
     * @param string $search       Optional search term
     * @param int    $limit
     * @return array
     */
    public function list_sessions( $user_id, $platform = 'ADMINCHAT', $project_id = null, $search = '', $limit = 50 ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return [];
        }

        $sessions = [];
        if ( method_exists( $db, 'get_sessions_v3_for_user' ) ) {
            $sessions = $db->get_sessions_v3_for_user( $user_id, $platform, $search ? 100 : $limit, $project_id );
        } elseif ( method_exists( $db, 'get_sessions_for_user' ) ) {
            $sessions = $db->get_sessions_for_user( $user_id, $platform, $search ? 100 : $limit, $project_id );
        }

        $result = [];
        foreach ( (array) $sessions as $s ) {
            // Filter by search term
            if ( $search ) {
                $title_match   = stripos( $s->title ?? '', $search ) !== false;
                $preview_match = stripos( $s->last_message_preview ?? '', $search ) !== false;
                if ( ! $title_match && ! $preview_match ) {
                    continue;
                }
            }

            $result[] = [
                'id'            => (int) $s->id,
                'session_id'    => $s->session_id,
                'title'         => $s->title ?: '',
                'project_id'    => $s->project_id ?? '',
                'message_count' => (int) ( $s->message_count ?? 0 ),
                'last_message'  => $s->last_message_preview ?? '',
                'started_at'    => $s->started_at,
                'last_activity' => $s->last_message_at ?? $s->started_at,
            ];
        }

        return $result;
    }

    /**
     * Create a new session.
     *
     * @param int    $user_id
     * @param string $platform
     * @param string $title
     * @param string $project_id
     * @return array|WP_Error  { id, session_id, title }
     */
    public function create_session( $user_id, $platform = 'ADMINCHAT', $title = '', $project_id = '' ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $client_name = '';
        $user = get_userdata( $user_id );
        if ( $user ) {
            $client_name = $user->display_name;
        }

        if ( method_exists( $db, 'create_session_v3' ) ) {
            $result = $db->create_session_v3( $user_id, $client_name, $platform, $title, [
                'project_id' => $project_id,
            ] );
        } else {
            $result = $db->create_session( $user_id, $client_name, $platform, $title );
            if ( ! empty( $project_id ) && ! empty( $result['id'] ) ) {
                $db->update_session_project( (int) $result['id'], $project_id );
            }
        }

        $this->log_operation( 'create', 'session', [
            'session_pk'   => $result['id'] ?? 0,
            'session_uuid' => $result['session_id'] ?? '',
            'title'        => $title ?: '(auto)',
            'project_id'   => $project_id ?: '(none)',
        ], $result['session_id'] ?? '' );

        return $result;
    }

    /**
     * Rename a session.
     *
     * @param int    $session_pk
     * @param string $title
     * @param int    $user_id    For ownership verification
     * @return true|WP_Error
     */
    public function rename_session( $session_pk, $title, $user_id ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_pk ) : null;
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found' );
        }

        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_pk, [ 'title' => $title, 'title_generated' => 0 ] );
        }

        return true;
    }

    /**
     * Move session to a different project.
     *
     * @param int    $session_pk
     * @param string $project_id
     * @param int    $user_id
     * @return true|WP_Error
     */
    public function move_session( $session_pk, $project_id, $user_id ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_pk ) : null;
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found' );
        }

        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_pk, [ 'project_id' => $project_id ] );
        }

        return true;
    }

    /**
     * Delete a session.
     *
     * @param int $session_pk
     * @param int $user_id
     * @return true|WP_Error
     */
    public function delete_session( $session_pk, $user_id ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_pk ) : null;
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found' );
        }

        if ( method_exists( $db, 'delete_session_v3' ) ) {
            $db->delete_session_v3( $session_pk );
        }

        return true;
    }

    /**
     * Get messages for a session.
     *
     * @param string|int $raw_id     PK (numeric) or UUID (string)
     * @param int        $user_id
     * @param int        $limit
     * @return array|WP_Error  { id, session_id, messages[] }
     */
    public function get_session_messages( $raw_id, $user_id, $limit = 100 ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $session = null;
        if ( is_numeric( $raw_id ) ) {
            $session = method_exists( $db, 'get_session_v3' )
                ? $db->get_session_v3( intval( $raw_id ) )
                : $db->get_session( intval( $raw_id ) );
        } else {
            $session = method_exists( $db, 'get_session_v3_by_session_id' )
                ? $db->get_session_v3_by_session_id( sanitize_text_field( $raw_id ) )
                : null;
        }

        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found' );
        }

        $session_uuid = $session->session_id ?? '';
        $messages     = $db->get_messages_by_session_id( $session_uuid, $limit );

        $result = [];
        foreach ( (array) $messages as $m ) {
            $result[] = [
                'id'          => (int) $m->id,
                'text'        => $m->message_text,
                'from'        => $m->message_from,
                'client_name' => $m->client_name ?? '',
                'created_at'  => $m->created_at,
                'created_ts'  => isset( $m->created_ts ) ? (int) $m->created_ts : 0,
                'attachments' => $m->attachments ? json_decode( $m->attachments, true ) : [],
            ];
        }

        return [
            'id'         => (int) $session->id,
            'session_id' => $session_uuid,
            'title'      => $session->title ?? '',
            'messages'   => $result,
        ];
    }

    /**
     * Generate AI title for a session.
     *
     * @param int    $session_pk
     * @param string $user_msg
     * @param string $bot_reply
     * @param int    $user_id
     * @return array|WP_Error  { title, source }
     */
    public function generate_title( $session_pk, $user_msg, $bot_reply, $user_id ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_pk ) : null;
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found' );
        }

        // Skip if manually titled
        if ( ! empty( $session->title ) && (int) ( $session->title_generated ?? 0 ) === 0 ) {
            return [ 'title' => $session->title, 'source' => 'manual' ];
        }

        // Try AI
        $title  = $this->ai_generate_title( $user_msg, $bot_reply );
        $source = 'ai';

        // Fallback to truncation
        if ( empty( $title ) ) {
            $title  = $this->truncate_title( $user_msg );
            $source = 'truncate';
        }

        // Save
        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_pk, [
                'title'           => $title,
                'title_generated' => 1,
            ] );
        }

        $this->log_operation( 'gen_title', 'session', [
            'session_pk' => $session_pk,
            'title'      => $title,
            'source'     => $source,
        ], $session->session_id ?? '' );

        return [ 'title' => $title, 'source' => $source ];
    }

    /**
     * Close all active sessions for a user.
     *
     * @param int    $user_id
     * @param string $platform
     * @return int  Number of sessions closed
     */
    public function close_all( $user_id, $platform = 'ADMINCHAT' ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions_v3';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
            return (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = 'closed', ended_at = NOW() WHERE user_id = %d AND platform_type = %s AND status = 'active'",
                $user_id,
                $platform
            ) );
        }

        // Legacy
        $conv_table = $wpdb->prefix . 'bizcity_webchat_conversations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$conv_table'" ) === $conv_table ) {
            return (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$conv_table} SET status = 'closed', ended_at = NOW() WHERE user_id = %d AND platform_type = %s AND status = 'active'",
                $user_id,
                $platform
            ) );
        }

        return 0;
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    private function ai_generate_title( $user_msg, $bot_reply ) {
        if ( ! class_exists( 'BizCity_OpenRouter_API' ) ) {
            return '';
        }

        try {
            $api = BizCity_OpenRouter_API::instance();
            $prompt = "Hãy tạo một tiêu đề ngắn gọn (tối đa 6 từ, tiếng Việt) cho cuộc hội thoại dựa trên:\nNgười dùng: {$user_msg}\nBot: {$bot_reply}\n\nChỉ trả về tiêu đề, không giải thích.";

            $response = $api->chat( [
                [ 'role' => 'user', 'content' => $prompt ],
            ], [
                'model'      => 'openai/gpt-4o-mini',
                'max_tokens' => 30,
            ] );

            $title = trim( $response['choices'][0]['message']['content'] ?? '' );
            $title = trim( $title, '"\'""' );
            return mb_substr( $title, 0, 100 );
        } catch ( Exception $e ) {
            return '';
        }
    }

    private function truncate_title( $message ) {
        $clean = strip_tags( $message );
        if ( mb_strlen( $clean ) > 50 ) {
            return mb_substr( $clean, 0, 47 ) . '...';
        }
        return $clean;
    }

    private function log_operation( $action, $type, $data = [], $session_id = '' ) {
        if ( class_exists( 'BizCity_User_Memory' ) && method_exists( 'BizCity_User_Memory', 'log_router_event' ) ) {
            BizCity_User_Memory::instance()->log_router_event( [
                'step'       => "service_{$type}_{$action}",
                'input_data' => $data,
                'session_id' => $session_id,
            ] );
        }
    }
}
