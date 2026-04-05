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
 * Notes — CRUD for memory_notes.
 */
class BCN_Notes {

    private function table() {
        return BCN_Schema_Extend::table_notes();
    }

    public function create( array $data ) {
        global $wpdb;

        $user_id    = get_current_user_id();
        $project_id = sanitize_text_field( $data['project_id'] ?? '' );
        $title      = sanitize_text_field( $data['title'] ?? '' );
        $content    = wp_kses_post( $data['content'] ?? '' );
        $note_type  = sanitize_text_field( $data['note_type'] ?? 'manual' );

        if ( ! in_array( $note_type, [ 'manual', 'chat_pinned', 'auto_pinned', 'studio_generated', 'research_auto' ], true ) ) {
            $note_type = 'manual';
        }

        // Auto-generate title from content.
        if ( ! $title ) {
            $title = mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Ghi chú';
        }

        $wpdb->insert( $this->table(), [
            'user_id'    => $user_id,
            'project_id' => $project_id,
            'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
            'message_id' => absint( $data['message_id'] ?? 0 ) ?: null,
            'title'      => $title,
            'content'    => $content,
            'note_type'  => $note_type,
            'is_starred' => ! empty( $data['is_starred'] ) ? 1 : 0,
            'metadata'   => wp_json_encode( $data['metadata'] ?? [] ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        $id = $wpdb->insert_id;
        if ( ! $id ) return new WP_Error( 'db_error', 'Could not create note' );

        do_action( 'bcn_note_created', $id, $project_id );
        return $id;
    }

    public function update( $id, array $data ) {
        global $wpdb;
        $update = [ 'updated_at' => current_time( 'mysql' ) ];
        if ( isset( $data['title'] ) )      $update['title']      = sanitize_text_field( $data['title'] );
        if ( isset( $data['content'] ) )    $update['content']    = wp_kses_post( $data['content'] );
        if ( isset( $data['is_starred'] ) ) $update['is_starred'] = (int) $data['is_starred'];

        $result = (bool) $wpdb->update( $this->table(), $update, [
            'id'      => $id,
            'user_id' => get_current_user_id(),
        ] );

        if ( $result ) {
            // Fire invalidation action (project_id looked up by caller or via REST)
            $project_id = $data['project_id'] ?? $this->get_project_id( $id );
            if ( $project_id ) {
                do_action( 'bcn_note_updated', $id, $project_id );
            }
        }

        return $result;
    }

    public function delete( $id ) {
        global $wpdb;
        $project_id = $this->get_project_id( $id );

        $result = (bool) $wpdb->delete( $this->table(), [
            'id'      => $id,
            'user_id' => get_current_user_id(),
        ] );

        if ( $result && $project_id ) {
            do_action( 'bcn_note_deleted', $id, $project_id );
        }

        return $result;
    }

    /**
     * Look up the project_id for a note row.
     */
    private function get_project_id( $note_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT project_id FROM {$this->table()} WHERE id = %d LIMIT 1",
            $note_id
        ) ) ?: '';
    }

    public function get_by_project( $project_id ) {
        global $wpdb;
        // Webchat sessions use session_id (wcs_ prefix), not project_id.
        $col = str_starts_with( $project_id, 'wcs_' ) ? 'session_id' : 'project_id';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE {$col} = %s ORDER BY created_at DESC",
            $project_id
        ) );
    }

    /**
     * Create a note on behalf of a specific user (system/AI use).
     * Skips get_current_user_id() — caller must pass user_id.
     */
    public function create_system( array $data ) {
        global $wpdb;

        $user_id    = absint( $data['user_id'] ?? 0 );
        $project_id = sanitize_text_field( $data['project_id'] ?? '' );
        $title      = sanitize_text_field( $data['title'] ?? '' );
        $content    = wp_kses_post( $data['content'] ?? '' );
        $note_type  = sanitize_text_field( $data['note_type'] ?? 'research_auto' );
        $tags       = $data['tags'] ?? '[]';

        if ( ! $title ) {
            $title = mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Research note';
        }

        $wpdb->insert( $this->table(), [
            'user_id'    => $user_id,
            'project_id' => $project_id,
            'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
            'title'      => $title,
            'content'    => $content,
            'tags'       => is_array( $tags ) ? wp_json_encode( $tags ) : $tags,
            'created_by' => 'ai',
            'note_type'  => $note_type,
            'is_starred' => 0,
            'metadata'   => wp_json_encode( $data['metadata'] ?? [] ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        $id = $wpdb->insert_id;
        if ( ! $id ) return new WP_Error( 'db_error', 'Could not create note' );

        do_action( 'bcn_note_created', $id, $project_id );
        return $id;
    }

    /**
     * Keyword search notes by FULLTEXT (title, content, tags).
     * Falls back to LIKE if FULLTEXT index is unavailable.
     *
     * @param string $project_id  Project scope.
     * @param string $keyword     Search query.
     * @param int    $limit       Max results.
     * @return array
     */
    public function search_by_keyword( $project_id, $keyword, $limit = 10 ) {
        global $wpdb;

        $keyword = trim( $keyword );
        if ( ! $keyword ) return $this->get_by_project( $project_id );

        // Try FULLTEXT MATCH first.
        $ft_query = '+' . implode( ' +', array_filter( explode( ' ', preg_replace( '/[^\p{L}\p{N}\s]/u', '', $keyword ) ) ) );

        // Check if FULLTEXT index exists.
        $has_ft = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'ft_search'",
            DB_NAME,
            $this->table()
        ) );

        if ( $has_ft ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT *, MATCH(title, content, tags) AGAINST(%s IN BOOLEAN MODE) AS relevance
                 FROM {$this->table()}
                 WHERE project_id = %s AND MATCH(title, content, tags) AGAINST(%s IN BOOLEAN MODE)
                 ORDER BY FIELD(note_type, 'chat_pinned', 'manual', 'auto_pinned', 'research_auto'), relevance DESC
                 LIMIT %d",
                $ft_query, $project_id, $ft_query, $limit
            ) );

            if ( ! empty( $results ) ) return $results;
        }

        // Fallback: LIKE search.
        $like = '%' . $wpdb->esc_like( $keyword ) . '%';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE project_id = %s AND (title LIKE %s OR content LIKE %s OR tags LIKE %s)
             ORDER BY FIELD(note_type, 'chat_pinned', 'manual', 'auto_pinned', 'research_auto'), created_at DESC
             LIMIT %d",
            $project_id, $like, $like, $like, $limit
        ) );
    }

    /**
     * Pin a chat message as a note.
     */
    public function pin_from_message( $project_id, $message_id, $user_id, $fallback_content = '' ) {
        global $wpdb;

        $msg_table = $wpdb->prefix . 'bizcity_webchat_messages';
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$msg_table} WHERE id = %d",
            $message_id
        ) );

        if ( ! $msg ) {
            // Message ID may be a frontend temp ID (Date.now()) — create note from provided content.
            if ( $fallback_content ) {
                $content = preg_replace( '/\n?---\n?💡[\s\S]*$/u', '', $fallback_content );
                $content = trim( $content );
                $title   = $this->generate_summary_title( $content );
                return $this->create( [
                    'project_id' => $project_id,
                    'title'      => $title,
                    'content'    => $content,
                    'note_type'  => 'chat_pinned',
                ] );
            }
            return new WP_Error( 'not_found', 'Message not found' );
        }

        // Mark message as pinned.
        $meta = json_decode( $msg->meta ?? '{}', true ) ?: [];
        $meta['is_pinned'] = true;
        $wpdb->update( $msg_table, [ 'meta' => wp_json_encode( $meta ) ], [ 'id' => $message_id ] );

        // Strip follow-up suggestions from stored content.
        $content = preg_replace( '/\n?---\n?💡[\s\S]*$/u', '', $msg->message_text );
        $content = trim( $content );

        // Generate a concise summary title using LLM.
        $title = $this->generate_summary_title( $content );

        return $this->create( [
            'project_id' => $project_id,
            'session_id' => $msg->session_id,
            'message_id' => $message_id,
            'title'      => $title,
            'content'    => $content,
            'note_type'  => 'chat_pinned',
        ] );
    }

    /**
     * Generate a concise summary title for a note using LLM.
     */
    private function generate_summary_title( string $content ): string {
        // Ensure OpenRouter helper is available.
        if ( ! function_exists( 'bizcity_openrouter_chat' ) && ! function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $mu = WP_CONTENT_DIR . '/mu-plugins/bizcity-openrouter/bootstrap.php';
            if ( file_exists( $mu ) ) require_once $mu;
        }

        // Fallback if LLM unavailable.
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Ghi chú';
        }

        $preview = mb_substr( $content, 0, 2000 );
        $messages = [
            [ 'role' => 'system', 'content' => 'Tóm tắt nội dung sau thành 1 dòng tiêu đề ngắn gọn (tối đa 80 ký tự), bằng tiếng Việt, nêu ý chính. Chỉ trả lời tiêu đề, không giải thích.' ],
            [ 'role' => 'user', 'content' => $preview ],
        ];

        try {
            $result = bizcity_openrouter_chat( $messages, [ 'max_tokens' => 100, 'purpose' => 'fast' ] );
            $title  = trim( $result['message'] ?? '' );
            // Remove surrounding quotes if any.
            $title = trim( $title, '"\'' );
            if ( $title && mb_strlen( $title ) <= 120 ) {
                return $title;
            }
        } catch ( \Exception $e ) {
            error_log( '[BCN] generate_summary_title error: ' . $e->getMessage() );
        }

        return mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Ghi chú';
    }
}
