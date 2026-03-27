<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat History Service — Unified chat history & message logging
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

class BizCity_Chat_History_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get chat history for a session.
     *
     * @param string $session_id
     * @param string $platform_type  ADMINCHAT | WEBCHAT
     * @param int    $limit
     * @return array  Array of message arrays
     */
    public function get_history( $session_id, $platform_type = 'ADMINCHAT', $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table}
             WHERE session_id = %s AND platform_type = %s
             ORDER BY id ASC
             LIMIT %d",
            $session_id,
            $platform_type,
            $limit
        ) );

        $history = [];
        foreach ( (array) $rows as $row ) {
            $meta        = $row->meta ? json_decode( $row->meta, true ) : [];
            $attachments = $row->attachments ? json_decode( $row->attachments, true ) : [];

            $images = [];
            if ( is_array( $attachments ) ) {
                foreach ( $attachments as $att ) {
                    if ( is_string( $att ) && $att !== '' ) {
                        $images[] = $att;
                    } elseif ( is_array( $att ) ) {
                        $url = $att['url'] ?? $att['data'] ?? '';
                        if ( $url ) {
                            $images[] = $url;
                        }
                    }
                }
            }

            $history[] = [
                'id'          => (int) $row->id,
                'message_id'  => $row->message_id,
                'message'     => $row->message_text,
                'msg'         => $row->message_text,  // backward-compat alias
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $attachments,
                'images'      => $images,
                'time'        => $row->created_at,
                'created_ts'  => isset( $row->created_ts ) ? (int) $row->created_ts : 0,
                'meta'        => $meta,
                'plugin_slug' => $row->plugin_slug ?? '',
            ];
        }

        return $history;
    }

    /**
     * Log a message to the webchat_messages table.
     *
     * @param array $data {
     *   session_id, user_id, client_name, message_id, message_text,
     *   message_from, message_type, attachments, platform_type, plugin_slug, meta
     * }
     * @return int|false  Insert ID or false
     */
    public function log_message( $data ) {
        // Prefer delegating to WebChat Database class if available
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            BizCity_WebChat_Database::instance()->log_message( $data );
            // Fire hook for global logger
            do_action( 'bizcity_webchat_message_saved', array_merge( $data, [
                'blog_id' => get_current_blog_id(),
            ] ) );
            return true;
        }

        // Fallback: direct insert
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return false;
        }

        $result = $wpdb->insert( $table, [
            'conversation_id' => 0,
            'session_id'      => $data['session_id'] ?? '',
            'user_id'         => $data['user_id'] ?? 0,
            'client_name'     => $data['client_name'] ?? '',
            'message_id'      => $data['message_id'] ?? '',
            'message_text'    => $data['message_text'] ?? '',
            'message_from'    => $data['message_from'] ?? 'user',
            'message_type'    => $data['message_type'] ?? 'text',
            'plugin_slug'     => $data['plugin_slug'] ?? '',
            'attachments'     => is_array( $data['attachments'] ?? null ) ? wp_json_encode( $data['attachments'] ) : '',
            'platform_type'   => $data['platform_type'] ?? 'WEBCHAT',
            'meta'            => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : '',
        ] );

        // Fire hook
        do_action( 'bizcity_webchat_message_saved', array_merge( $data, [
            'blog_id' => get_current_blog_id(),
        ] ) );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Clear history for a session.
     *
     * @param string $session_id
     * @param string $platform_type  ADMINCHAT | WEBCHAT
     * @return array { cleared: bool, deleted_count: int }
     */
    public function clear_history( $session_id, $platform_type = 'WEBCHAT' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $deleted = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
            $deleted = $wpdb->delete( $table, [
                'session_id'    => $session_id,
                'platform_type' => $platform_type,
            ] );
        }

        // Close conversation
        $conv_table = $wpdb->prefix . 'bizcity_webchat_conversations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$conv_table'" ) === $conv_table ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$conv_table} SET status = 'closed', ended_at = NOW() WHERE session_id = %s AND platform_type = %s",
                $session_id,
                $platform_type
            ) );
        }

        return [
            'cleared'       => true,
            'deleted_count' => (int) $deleted,
        ];
    }

    /**
     * Poll for new messages since a given ID.
     *
     * @param string $session_id
     * @param int    $since_id
     * @param int    $limit
     * @return array
     */
    public function poll( $session_id, $since_id, $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table}
             WHERE session_id = %s AND id > %d
             ORDER BY id ASC LIMIT %d",
            $session_id,
            $since_id,
            $limit
        ) );

        $result = [];
        foreach ( (array) $rows as $m ) {
            $result[] = [
                'id'          => (int) $m->id,
                'text'        => $m->message_text,
                'from'        => $m->message_from,
                'client_name' => $m->client_name ?? '',
                'created_at'  => $m->created_at,
                'created_ts'  => (int) $m->created_ts,
                'attachments' => ! empty( $m->attachments ) ? json_decode( $m->attachments, true ) : [],
            ];
        }

        return $result;
    }
}
