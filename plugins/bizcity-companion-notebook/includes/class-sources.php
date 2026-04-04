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
 * Sources — CRUD for webchat_sources.
 */
class BCN_Sources {

    private function table() {
        return BCN_Schema_Extend::table_sources();
    }

    public function add( $project_id, array $data ) {
        global $wpdb;

        $user_id = get_current_user_id();
        $type    = sanitize_text_field( $data['source_type'] ?? 'text' );

        if ( ! in_array( $type, [ 'file', 'url', 'youtube', 'text', 'manual', 'json', 'sql' ], true ) ) {
            return new WP_Error( 'invalid_type', 'Invalid source type' );
        }

        $content_text = $data['content_text'] ?? '';

        // Auto-extract for URL / YouTube (skip when content is already provided).
        if ( ( $type === 'url' || $type === 'youtube' ) && empty( $data['skip_extract'] ) && empty( $content_text ) ) {
            $url = esc_url_raw( $data['source_url'] ?? '' );
            if ( ! $url ) return new WP_Error( 'missing_url', 'URL required' );

            $extractor = new BCN_Source_Extractor();
            $extracted = $extractor->extract( $type, $url );
            if ( ! empty( $extracted['error'] ) ) {
                return new WP_Error( 'extraction_error', $extracted['error'] );
            }
            $content_text = $extracted['text'];
            $data['source_url'] = $url;
        } elseif ( ( $type === 'url' || $type === 'youtube' ) ) {
            // Content pre-filled — ensure source_url is sanitised.
            $data['source_url'] = esc_url_raw( $data['source_url'] ?? '' );
        }

        $title = sanitize_text_field( $data['title'] ?? '' );
        if ( ! $title && ! empty( $data['source_url'] ) ) {
            $title = wp_parse_url( $data['source_url'], PHP_URL_HOST ) ?: 'Source';
        }
        if ( ! $title ) {
            $title = mb_substr( wp_strip_all_tags( $content_text ), 0, 80 ) ?: 'Source';
        }

        $hash = hash( 'sha256', $content_text );

        $wpdb->insert( $this->table(), [
            'user_id'       => $user_id,
            'project_id'    => $project_id,
            'title'         => $title,
            'source_type'   => $type,
            'source_url'    => sanitize_url( $data['source_url'] ?? '' ),
            'attachment_id' => absint( $data['attachment_id'] ?? 0 ) ?: null,
            'content_text'  => $content_text,
            'content_hash'  => $hash,
            'char_count'    => mb_strlen( $content_text ),
            'token_estimate' => (int) ( mb_strlen( $content_text ) / 4 ),
            'status'        => 'ready',
            'metadata'      => wp_json_encode( $data['metadata'] ?? [] ),
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ] );

        $id = $wpdb->insert_id;
        if ( ! $id ) return new WP_Error( 'db_error', 'Could not insert source' );

        do_action( 'bcn_source_added', $id, $project_id );
        return $id;
    }

    /**
     * Handle file upload — uses WP media library.
     */
    public function upload( $project_id, array $file ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Validate file type.
        $allowed = [
            'pdf', 'txt', 'md', 'docx', 'doc', 'csv', 'json', 'sql',
            'pptx', 'ppt', 'xlsx', 'xls',
            'mp3', 'wav', 'm4a', 'ogg', 'webm', 'flac',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg',
        ];
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed, true ) ) {
            return new WP_Error( 'invalid_file', 'File type not allowed. Allowed: ' . implode( ', ', $allowed ) );
        }

        // Allow audio/image MIME types through WP upload.
        add_filter( 'upload_mimes', function ( $mimes ) {
            $mimes['mp3']  = 'audio/mpeg';
            $mimes['wav']  = 'audio/wav';
            $mimes['m4a']  = 'audio/m4a';
            $mimes['ogg']  = 'audio/ogg';
            $mimes['webm'] = 'audio/webm';
            $mimes['flac'] = 'audio/flac';
            return $mimes;
        } );

        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_error', $upload['error'] );
        }

        // Create WP attachment.
        $attachment_id = wp_insert_attachment( [
            'post_title'     => sanitize_file_name( $file['name'] ),
            'post_mime_type' => $upload['type'],
            'post_status'    => 'private',
        ], $upload['file'] );

        // Extract text.
        $extractor = new BCN_Source_Extractor();
        $type_map  = [
            'pdf' => 'pdf', 'txt' => 'text', 'md' => 'text',
            'docx' => 'docx', 'doc' => 'docx',
            'pptx' => 'pptx', 'ppt' => 'pptx',
            'xlsx' => 'xlsx', 'xls' => 'xlsx',
            'csv' => 'csv', 'json' => 'json', 'sql' => 'sql',
            'mp3' => 'audio', 'wav' => 'audio', 'm4a' => 'audio', 'ogg' => 'audio', 'webm' => 'audio', 'flac' => 'audio',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image', 'gif' => 'image', 'bmp' => 'image', 'svg' => 'image',
        ];
        $extracted = $extractor->extract( $type_map[ $ext ] ?? 'text', $upload['file'] );

        return $this->add( $project_id, [
            'title'         => pathinfo( $file['name'], PATHINFO_FILENAME ),
            'source_type'   => 'file',
            'attachment_id' => $attachment_id,
            'content_text'  => $extracted['text'] ?? '',
        ] );
    }

    public function delete( $id ) {
        global $wpdb;

        // Remove related embedding chunks (safe — table may not exist yet).
        try {
            if ( class_exists( 'BCN_Embedder' ) ) {
                $embedder = new BCN_Embedder();
                $embedder->delete_source_chunks( (int) $id );
            }
        } catch ( \Throwable $e ) {
            // Silently continue — chunks table may not exist.
        }

        // Delete attachment if exists.
        $source = $this->get( $id );
        $project_id = $source ? ( $source->project_id ?? '' ) : '';
        if ( $source && ! empty( $source->attachment_id ) ) {
            wp_delete_attachment( $source->attachment_id, true );
        }

        $result = (bool) $wpdb->delete( $this->table(), [
            'id'      => $id,
            'user_id' => get_current_user_id(),
        ] );

        if ( $result && $project_id ) {
            do_action( 'bcn_source_deleted', $id, $project_id );
        }

        return $result;
    }

    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ) );
    }

    public function get_by_project( $project_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, project_id, title, source_type, source_url, char_count,
                    token_estimate, status, embedding_status, chunk_count, created_at
             FROM {$this->table()}
             WHERE project_id = %s ORDER BY created_at DESC",
            $project_id
        ) );
    }

    /**
     * Concatenate all source content for a project (for LLM context).
     */
    public function get_all_content( $project_id, $max_chars = 120000 ) {
        global $wpdb;

        $table = $this->table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, content_text FROM {$table}
             WHERE project_id = %s AND status = 'ready'
             ORDER BY created_at ASC",
            $project_id
        ) );

        error_log( "[BCN] get_all_content: project={$project_id}, table={$table}, rows=" . count( $rows ) );
        if ( $wpdb->last_error ) {
            error_log( "[BCN] get_all_content SQL error: {$wpdb->last_error}" );
        }

        $parts      = [];
        $total_chars = 0;

        foreach ( $rows as $row ) {
            $text = "[Nguồn: {$row->title}]\n{$row->content_text}\n";
            if ( $total_chars + mb_strlen( $text ) > $max_chars ) break;
            $parts[]     = $text;
            $total_chars += mb_strlen( $text );
        }

        $result = implode( "\n---\n", $parts );
        error_log( "[BCN] get_all_content: total_chars=" . mb_strlen( $result ) );
        return $result;
    }

    public function get_total_tokens( $project_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(token_estimate) FROM {$this->table()} WHERE project_id = %s AND status = 'ready'",
            $project_id
        ) );
    }
}
