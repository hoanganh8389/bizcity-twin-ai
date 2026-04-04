<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Messages — Wrapper for webchat_messages with platform_type='NOTEBOOK'.
 */
class BCN_Messages {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_messages';
    }

    private function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_sessions';
    }

    private function column_exists( $table, $column ) {
        global $wpdb;
        static $cache = [];
        $key = $table . '.' . $column;
        if ( ! isset( $cache[ $key ] ) ) {
            $cache[ $key ] = (bool) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'" );
        }
        return $cache[ $key ];
    }

    /**
     * Ensure a NOTEBOOK session exists for project, create if needed.
     */
    public function ensure_session( $project_id, $user_id ) {
        global $wpdb;

        // Find active NOTEBOOK session for this project.
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->sessions_table()}
             WHERE project_id = %s AND user_id = %d AND platform_type = 'NOTEBOOK' AND status = 'active'
             ORDER BY last_message_at DESC LIMIT 1",
            $project_id, $user_id
        ) );

        if ( $session ) return $session->session_id;

        // Create new session.
        $session_id = 'nb_' . wp_generate_uuid4();
        $wpdb->insert( $this->sessions_table(), [
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'project_id'    => $project_id,
            'title'         => 'Notebook Chat',
            'platform_type' => 'NOTEBOOK',
            'status'        => 'active',
            'started_at'    => current_time( 'mysql' ),
            'last_message_at' => current_time( 'mysql' ),
        ] );

        return $session_id;
    }

    public function create( array $data ) {
        global $wpdb;

        $row = [
            'session_id'    => $data['session_id'],
            'user_id'       => $data['user_id'],
            'message_text'  => $data['message_text'],
            'message_from'  => $data['message_from'],
            'message_type'  => $data['message_type'] ?? 'text',
            'plugin_slug'   => 'notebook',
            'platform_type' => 'NOTEBOOK',
            'status'        => 'visible',
            'meta'          => wp_json_encode( $data['meta'] ?? [] ),
            'intent_conversation_id' => $data['intent_conversation_id'] ?? '',
            'created_at'    => current_time( 'mysql' ),
        ];

        // Always write project_id — column exists on all current installations (v5+).
        if ( ! empty( $data['project_id'] ) ) {
            $row['project_id'] = $data['project_id'];
        }

        // Add token columns only if they exist in the table.
        if ( $this->column_exists( $this->table(), 'input_tokens' ) ) {
            $row['input_tokens']  = $data['input_tokens'] ?? 0;
            $row['output_tokens'] = $data['output_tokens'] ?? 0;
        }

        $wpdb->insert( $this->table(), $row );

        $id = $wpdb->insert_id;

        // Update session counters.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->sessions_table()}
             SET message_count = message_count + 1, last_message_at = %s
             WHERE session_id = %s",
            current_time( 'mysql' ), $data['session_id']
        ) );

        return $id;
    }

    public function get_by_project( $project_id, array $args = [] ) {
        global $wpdb;

        $limit  = min( absint( $args['limit'] ?? 50 ), 200 );
        $before = absint( $args['before'] ?? 0 );
        $t      = $this->table();

        // Query directly by project_id column on the messages table.
        // Use subquery to get the LATEST N messages, then order ASC for chronological display.
        $where = "m.project_id = %s AND m.platform_type = 'NOTEBOOK' AND m.status = 'visible'";
        $params = [ $project_id ];

        if ( $before ) {
            $where .= ' AND m.id < %d';
            $params[] = $before;
        }

        $params[] = $limit;

        $sql = "SELECT * FROM (
                    SELECT m.id, m.session_id, m.message_text AS content,
                           CASE m.message_from WHEN 'user' THEN 'user' ELSE 'assistant' END AS role,
                           m.meta, m.created_at
                    FROM {$t} m
                    WHERE {$where}
                    ORDER BY m.id DESC
                    LIMIT %d
                ) sub ORDER BY sub.id ASC";

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    public function rate( $id, $rating ) {
        global $wpdb;

        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT meta FROM {$this->table()} WHERE id = %d",
            $id
        ) );
        if ( ! $msg ) return false;

        $meta = json_decode( $msg->meta ?? '{}', true ) ?: [];
        $meta['rating'] = sanitize_text_field( $rating );

        return (bool) $wpdb->update( $this->table(), [
            'meta' => wp_json_encode( $meta ),
        ], [ 'id' => $id ] );
    }

    public function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->update( $this->table(), [
            'status' => 'deleted',
        ], [
            'id'      => $id,
            'user_id' => get_current_user_id(),
        ] );
    }
}
