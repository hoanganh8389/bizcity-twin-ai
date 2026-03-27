<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Knowledge Fabric — Unified Multi-Scope Knowledge Pipeline
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      3.0.0
 *
 * Thống nhất mọi luồng kiến thức (file, URL, text, FAQ) qua 1 entry point.
 * Unifies all knowledge flows (file, URL, text, FAQ) through a single entry point.
 * Scope: user / project / session / agent.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Knowledge_Fabric {

    /** @var self|null */
    private static $instance = null;

    /** @var array Valid scopes */
    const VALID_SCOPES = array( 'user', 'project', 'session', 'agent' );

    /** @var array Valid source types */
    const VALID_SOURCE_TYPES = array( 'file', 'url', 'text', 'faq', 'quick_faq', 'manual' );

    /**
     * Singleton accessor.
     *
     * @return self
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ================================================================
     *  INGEST — Unified entry point
     * ================================================================ */

    /**
     * Ingest knowledge from any source type into the scope-aware pipeline.
     *
     * @param array $params {
     *     @type string $source_type   Required: file|url|text|faq
     *     @type string $scope         Required: user|project|session|agent
     *     @type int    $user_id       Required for user/project/session scope
     *     @type int    $character_id  Required for agent scope (default 0)
     *     @type int    $project_id    Optional: for project scope
     *     @type string $session_id    Optional: for session scope
     *     @type string $content       Text/FAQ content body
     *     @type string $url           URL to crawl (url type)
     *     @type int    $attachment_id WordPress attachment ID (file type)
     *     @type string $source_name   Display name (auto-detected if empty)
     *     @type string $scrape_type   URL scrape type: simple_html | simple_text (default simple_html)
     * }
     * @return array|WP_Error { source_id, chunks_count, tokens } or error
     */
    public function ingest( $params ) {
        // ── Defaults ──
        $params = wp_parse_args( $params, array(
            'source_type'   => '',
            'scope'         => 'user',
            'user_id'       => 0,
            'character_id'  => 0,
            'project_id'    => null,
            'session_id'    => '',
            'content'       => '',
            'url'           => '',
            'attachment_id' => 0,
            'source_name'   => '',
            'scrape_type'   => 'simple_html',
        ) );

        // ── Validate ──
        $error = $this->validate_ingest_params( $params );
        if ( is_wp_error( $error ) ) {
            return $error;
        }

        // ── Extract content by source type ──
        $extracted = $this->extract_content( $params );
        if ( is_wp_error( $extracted ) ) {
            return $extracted;
        }

        $content     = $extracted['content'];
        $source_name = ! empty( $params['source_name'] ) ? $params['source_name'] : $extracted['name'];

        // ── Create source record  ──
        $db = BizCity_Knowledge_Database::instance();

        $source_data = array(
            'character_id' => (int) $params['character_id'],
            'user_id'      => (int) $params['user_id'],
            'scope'        => $params['scope'],
            'project_id'   => $params['project_id'] ? (int) $params['project_id'] : null,
            'session_id'   => $params['session_id'],
            'source_type'  => $params['source_type'],
            'source_name'  => $source_name,
            'source_url'   => $params['url'],
            'attachment_id' => $params['attachment_id'] ? (int) $params['attachment_id'] : null,
            'content'      => $content,
            'content_hash' => md5( $content ),
            'status'       => 'processing',
            'settings'     => wp_json_encode( array(
                'scope'       => $params['scope'],
                'ingested_by' => 'fabric',
                'scrape_type' => $params['scrape_type'],
            ) ),
        );

        $source_id = $db->create_knowledge_source( $source_data );

        if ( is_wp_error( $source_id ) ) {
            return $source_id;
        }

        // ── Chunk + Embed ──
        $chunks       = BizCity_Knowledge_Source::chunk_content( $content, 500 );
        $total_tokens = 0;
        $embedding    = class_exists( 'BizCity_Knowledge_Embedding' ) ? BizCity_Knowledge_Embedding::instance() : null;

        foreach ( $chunks as $index => $chunk_text ) {
            $token_count   = BizCity_Knowledge_Source::count_tokens( $chunk_text );
            $total_tokens += $token_count;

            $chunk_data = array(
                'source_id'    => $source_id,
                'character_id' => (int) $params['character_id'],
                'user_id'      => (int) $params['user_id'],
                'scope'        => $params['scope'],
                'project_id'   => $params['project_id'] ? (int) $params['project_id'] : null,
                'session_id'   => $params['session_id'],
                'chunk_index'  => $index,
                'content'      => $chunk_text,
                'token_count'  => $token_count,
                'metadata'     => array(
                    'source_type' => $params['source_type'],
                    'source_name' => $source_name,
                    'scope'       => $params['scope'],
                ),
            );

            // Embed if service available
            if ( $embedding ) {
                $vector = $embedding->create_embedding( $chunk_text );
                if ( ! is_wp_error( $vector ) ) {
                    $chunk_data['embedding'] = $vector;
                }
            }

            $db->create_chunk( $chunk_data );
        }

        // ── Mark source ready ──
        $db->update_source( $source_id, array(
            'chunks_count'  => count( $chunks ),
            'status'        => 'ready',
            'last_synced_at' => current_time( 'mysql' ),
        ) );

        $result = array(
            'source_id'    => $source_id,
            'source_name'  => $source_name,
            'chunks_count' => count( $chunks ),
            'total_tokens' => $total_tokens,
            'scope'        => $params['scope'],
        );

        /**
         * Fires after knowledge is successfully ingested.
         *
         * @param array $result  Ingestion result
         * @param array $params  Original params
         */
        do_action( 'bizcity_knowledge_ingested', $result, $params );

        return $result;
    }

    /* ================================================================
     *  SEARCH — Multi-scope
     * ================================================================ */

    /**
     * Search knowledge across multiple scopes with priority merge.
     *
     * Priority: session > project > user > agent
     *
     * @param string $query       Search query
     * @param array  $scope_params {
     *     @type int    $user_id       Required
     *     @type int    $character_id  Agent character (scope=agent)
     *     @type int    $project_id    Optional
     *     @type string $session_id    Optional
     *     @type int    $max_results   Max total results (default 10)
     * }
     * @return array  Array of { chunk_id, content, score, scope, source_name, source_type }
     */
    public function search_multi_scope( $query, $scope_params ) {
        $scope_params = wp_parse_args( $scope_params, array(
            'user_id'      => 0,
            'character_id' => 0,
            'project_id'   => null,
            'session_id'   => '',
            'max_results'  => 10,
        ) );

        $all_results = array();
        $budget      = (int) $scope_params['max_results'];
        $embedding   = class_exists( 'BizCity_Knowledge_Embedding' ) ? BizCity_Knowledge_Embedding::instance() : null;

        // Embed query once
        $query_vector = null;
        if ( $embedding ) {
            $query_vector = $embedding->create_embedding( $query );
            if ( is_wp_error( $query_vector ) ) {
                $query_vector = null;
            }
        }

        // Priority ordered scopes
        $scopes_to_search = array();

        if ( ! empty( $scope_params['session_id'] ) ) {
            $scopes_to_search[] = array(
                'scope'      => 'session',
                'session_id' => $scope_params['session_id'],
                'max'        => max( 2, (int) ceil( $budget * 0.25 ) ),
            );
        }

        if ( ! empty( $scope_params['project_id'] ) ) {
            $scopes_to_search[] = array(
                'scope'      => 'project',
                'project_id' => (int) $scope_params['project_id'],
                'user_id'    => (int) $scope_params['user_id'],
                'max'        => max( 2, (int) ceil( $budget * 0.25 ) ),
            );
        }

        if ( ! empty( $scope_params['user_id'] ) ) {
            $scopes_to_search[] = array(
                'scope'   => 'user',
                'user_id' => (int) $scope_params['user_id'],
                'max'     => max( 2, (int) ceil( $budget * 0.25 ) ),
            );
        }

        if ( ! empty( $scope_params['character_id'] ) ) {
            $scopes_to_search[] = array(
                'scope'        => 'agent',
                'character_id' => (int) $scope_params['character_id'],
                'max'          => $budget, // Fill remaining with agent
            );
        }

        $db = BizCity_Knowledge_Database::instance();

        foreach ( $scopes_to_search as $scope_cfg ) {
            if ( $budget <= 0 ) {
                break;
            }

            $limit   = min( $scope_cfg['max'], $budget );
            $results = array();

            if ( $query_vector ) {
                // Semantic search
                $results = $this->semantic_search_scope( $db, $scope_cfg, $query_vector, $limit );
            }

            // Fallback: keyword search
            if ( empty( $results ) ) {
                $results = $this->keyword_search_scope( $db, $scope_cfg, $query, $limit );
            }

            foreach ( $results as $r ) {
                $r['scope'] = $scope_cfg['scope'];
                $all_results[] = $r;
                $budget--;
                if ( $budget <= 0 ) {
                    break;
                }
            }
        }

        return $all_results;
    }

    /* ================================================================
     *  PROMOTE / MANAGE
     * ================================================================ */

    /**
     * Promote source scope (session→user, project→user, etc.)
     *
     * @param int    $source_id
     * @param string $new_scope
     * @param array  $params  Optional overrides { user_id, project_id }
     * @return bool|WP_Error
     */
    public function promote_scope( $source_id, $new_scope, $params = array() ) {
        if ( ! in_array( $new_scope, self::VALID_SCOPES, true ) ) {
            return new WP_Error( 'invalid_scope', 'Invalid target scope: ' . $new_scope );
        }

        $db = BizCity_Knowledge_Database::instance();

        $extra = array();
        if ( isset( $params['user_id'] ) ) {
            $extra['user_id'] = (int) $params['user_id'];
        }
        if ( $new_scope === 'project' && isset( $params['project_id'] ) ) {
            $extra['project_id'] = (int) $params['project_id'];
        }
        if ( $new_scope !== 'session' ) {
            $extra['session_id'] = '';
        }

        $db->update_source_scope( $source_id, $new_scope, $extra );

        return true;
    }

    /**
     * Get all sources belonging to a user (across all scopes).
     *
     * @param int         $user_id
     * @param string|null $scope   Filter by scope (null = all)
     * @return array
     */
    public function get_user_sources( $user_id, $scope = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        $where  = 'user_id = %d';
        $values = array( (int) $user_id );

        if ( $scope && in_array( $scope, self::VALID_SCOPES, true ) ) {
            $where  .= ' AND scope = %s';
            $values[] = $scope;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_type, source_name, source_url, scope, project_id, session_id,
                    chunks_count, status, created_at
             FROM {$table}
             WHERE {$where}
             ORDER BY created_at DESC",
            ...$values
        ) );
    }

    /**
     * Delete a knowledge source (with ownership check).
     *
     * @param int $source_id
     * @param int $user_id   Owner user_id (0 = skip check — admin)
     * @return bool|WP_Error
     */
    public function delete_source( $source_id, $user_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        // Ownership check
        if ( $user_id > 0 ) {
            $owner = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE id = %d",
                $source_id
            ) );
            if ( (int) $owner !== (int) $user_id ) {
                return new WP_Error( 'permission_denied', 'You do not own this knowledge source.' );
            }
        }

        $db = BizCity_Knowledge_Database::instance();
        $db->delete_source_and_chunks( $source_id );

        return true;
    }

    /**
     * Cleanup expired session knowledge (intended for WP-Cron).
     *
     * @param int $max_age_hours  Default 24
     * @return int  Deleted count
     */
    public function cleanup_expired_sessions( $max_age_hours = 24 ) {
        $db = BizCity_Knowledge_Database::instance();
        return $db->delete_expired_session_sources( $max_age_hours );
    }

    /* ================================================================
     *  PRIVATE HELPERS
     * ================================================================ */

    /**
     * Validate ingest params.
     *
     * @param array $params
     * @return true|WP_Error
     */
    private function validate_ingest_params( $params ) {
        if ( empty( $params['source_type'] ) || ! in_array( $params['source_type'], self::VALID_SOURCE_TYPES, true ) ) {
            return new WP_Error( 'invalid_source_type', 'source_type must be: ' . implode( ', ', self::VALID_SOURCE_TYPES ) );
        }

        if ( ! in_array( $params['scope'], self::VALID_SCOPES, true ) ) {
            return new WP_Error( 'invalid_scope', 'scope must be: ' . implode( ', ', self::VALID_SCOPES ) );
        }

        // Scope-specific validation
        switch ( $params['scope'] ) {
            case 'user':
            case 'project':
            case 'session':
                if ( empty( $params['user_id'] ) ) {
                    return new WP_Error( 'missing_user_id', 'user_id is required for scope: ' . $params['scope'] );
                }
                break;
            case 'agent':
                if ( empty( $params['character_id'] ) ) {
                    return new WP_Error( 'missing_character_id', 'character_id is required for scope: agent' );
                }
                break;
        }

        if ( $params['scope'] === 'project' && empty( $params['project_id'] ) ) {
            return new WP_Error( 'missing_project_id', 'project_id is required for scope: project' );
        }

        // Source-specific validation
        switch ( $params['source_type'] ) {
            case 'file':
                if ( empty( $params['attachment_id'] ) ) {
                    return new WP_Error( 'missing_attachment', 'attachment_id required for file source' );
                }
                break;
            case 'url':
                if ( empty( $params['url'] ) || ! filter_var( $params['url'], FILTER_VALIDATE_URL ) ) {
                    return new WP_Error( 'invalid_url', 'Valid URL required for url source' );
                }
                break;
            case 'text':
            case 'faq':
            case 'manual':
                if ( empty( $params['content'] ) ) {
                    return new WP_Error( 'missing_content', 'content required for text/faq source' );
                }
                break;
        }

        return true;
    }

    /**
     * Extract content by source type.
     *
     * @param array $params
     * @return array|WP_Error  { content, name }
     */
    private function extract_content( $params ) {
        switch ( $params['source_type'] ) {

            case 'file':
                return $this->extract_from_file( $params['attachment_id'] );

            case 'url':
                return $this->extract_from_url( $params['url'], $params['scrape_type'] );

            case 'text':
            case 'manual':
                $name = ! empty( $params['source_name'] )
                    ? $params['source_name']
                    : mb_substr( $params['content'], 0, 50, 'UTF-8' ) . '...';
                return array( 'content' => $params['content'], 'name' => $name );

            case 'faq':
            case 'quick_faq':
                return $this->extract_from_faq( $params['content'], $params['source_name'] );

            default:
                return new WP_Error( 'unknown_source', 'Unknown source_type: ' . $params['source_type'] );
        }
    }

    /**
     * Extract content from WP attachment (file).
     *
     * @param int $attachment_id
     * @return array|WP_Error { content, name }
     */
    private function extract_from_file( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Attachment file not found: ' . $attachment_id );
        }

        $file_name = basename( $file_path );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        // Image files — describe via Vision API or just store URL
        $image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif' );
        if ( in_array( $ext, $image_exts, true ) ) {
            $url = wp_get_attachment_url( $attachment_id );
            $desc = $this->describe_image( $url );
            $content = ! empty( $desc ) ? $desc : 'Image: ' . $url;
            return array( 'content' => $content, 'name' => $file_name );
        }

        // Document files — use existing FileProcessor
        if ( class_exists( 'BizCity_File_Processor' ) ) {
            $processor = BizCity_File_Processor::instance();
            $content   = $processor->extract_content( $file_path, get_post_mime_type( $attachment_id ) );

            if ( is_wp_error( $content ) ) {
                return $content;
            }

            return array( 'content' => $content, 'name' => $file_name );
        }

        // Fallback: raw text read
        $content = file_get_contents( $file_path );
        if ( $content === false ) {
            return new WP_Error( 'read_failed', 'Cannot read file: ' . $file_path );
        }

        return array( 'content' => $content, 'name' => $file_name );
    }

    /**
     * Extract content from URL.
     *
     * @param string $url
     * @param string $scrape_type
     * @return array|WP_Error { content, name }
     */
    private function extract_from_url( $url, $scrape_type = 'simple_html' ) {
        if ( class_exists( 'BizCity_Content_Importer' ) ) {
            $importer = BizCity_Content_Importer::instance();
            $content  = $importer->fetch_url_content( $url, $scrape_type );

            if ( is_wp_error( $content ) ) {
                return $content;
            }

            $name = parse_url( $url, PHP_URL_HOST );
            if ( empty( $name ) ) {
                $name = mb_substr( $url, 0, 60, 'UTF-8' );
            }

            return array( 'content' => $content, 'name' => $name );
        }

        return new WP_Error( 'no_importer', 'BizCity_Content_Importer not available' );
    }

    /**
     * Extract from FAQ format.
     *
     * @param string $content  JSON or plain text
     * @param string $name
     * @return array { content, name }
     */
    private function extract_from_faq( $content, $name = '' ) {
        $decoded = json_decode( $content, true );

        if ( is_array( $decoded ) && isset( $decoded['question'], $decoded['answer'] ) ) {
            $text = "Q: {$decoded['question']}\nA: {$decoded['answer']}";
            $faq_name = ! empty( $name ) ? $name : mb_substr( $decoded['question'], 0, 60, 'UTF-8' );
            return array( 'content' => $text, 'name' => $faq_name );
        }

        // Plain text FAQ
        $faq_name = ! empty( $name ) ? $name : 'FAQ entry';
        return array( 'content' => $content, 'name' => $faq_name );
    }

    /**
     * Describe an image using Vision API (if available).
     *
     * @param string $image_url
     * @return string  Description text or empty
     */
    private function describe_image( $image_url ) {
        if ( ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            return '';
        }

        $api = BizCity_Knowledge_Context_API::instance();

        // Use process_images_for_context if available
        if ( method_exists( $api, 'process_images_for_context' ) ) {
            $result = $api->process_images_for_context(
                array( $image_url ),
                'Describe this image in detail for knowledge storage.',
                $api->get_config()
            );
            if ( ! empty( $result['descriptions'] ) ) {
                return implode( "\n", $result['descriptions'] );
            }
        }

        return '';
    }

    /**
     * Semantic search within a specific scope.
     *
     * @param BizCity_Knowledge_Database $db
     * @param array  $scope_cfg  { scope, user_id|project_id|session_id|character_id, max }
     * @param array  $query_vector Embedding vector
     * @param int    $limit
     * @return array  Array of { chunk_id, content, score, source_name, source_type }
     */
    private function semantic_search_scope( $db, $scope_cfg, $query_vector, $limit ) {
        $scope_ids = array();
        foreach ( array( 'user_id', 'project_id', 'session_id', 'character_id' ) as $key ) {
            if ( isset( $scope_cfg[ $key ] ) ) {
                $scope_ids[ $key ] = $scope_cfg[ $key ];
            }
        }

        $chunk_rows = $db->get_chunk_embeddings_by_scope( $scope_cfg['scope'], $scope_ids, 500 );

        if ( empty( $chunk_rows ) ) {
            return array();
        }

        // Compute cosine similarity
        $scores = array();
        foreach ( $chunk_rows as $row ) {
            $vector = json_decode( $row->embedding, true );
            if ( ! is_array( $vector ) || empty( $vector ) ) {
                continue;
            }
            $sim = $this->cosine_similarity( $query_vector, $vector );
            if ( $sim >= 0.60 ) {
                $scores[] = array(
                    'chunk_id' => (int) $row->id,
                    'score'    => $sim,
                );
            }
        }

        if ( empty( $scores ) ) {
            return array();
        }

        // Sort by score descending
        usort( $scores, function( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        // Take top N
        $top = array_slice( $scores, 0, $limit );
        $ids = wp_list_pluck( $top, 'chunk_id' );

        // Fetch full chunk data
        $chunks_map = $db->get_chunks_by_ids( $ids );

        $results = array();
        foreach ( $top as $item ) {
            $chunk = isset( $chunks_map[ $item['chunk_id'] ] ) ? $chunks_map[ $item['chunk_id'] ] : null;
            if ( ! $chunk ) {
                continue;
            }

            // Resolve source name
            $source_name = '';
            $source_type = '';
            $meta = json_decode( $chunk->metadata, true );
            if ( is_array( $meta ) ) {
                $source_name = isset( $meta['source_name'] ) ? $meta['source_name'] : '';
                $source_type = isset( $meta['source_type'] ) ? $meta['source_type'] : '';
            }

            $results[] = array(
                'chunk_id'    => $item['chunk_id'],
                'content'     => $chunk->content,
                'score'       => round( $item['score'], 4 ),
                'source_name' => $source_name,
                'source_type' => $source_type,
            );
        }

        return $results;
    }

    /**
     * Keyword search within a specific scope (fallback).
     *
     * @param BizCity_Knowledge_Database $db
     * @param array  $scope_cfg
     * @param string $query
     * @param int    $limit
     * @return array
     */
    private function keyword_search_scope( $db, $scope_cfg, $query, $limit ) {
        global $wpdb;

        $chunks_table  = $wpdb->prefix . 'bizcity_knowledge_chunks';
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';

        $keywords = array_filter( explode( ' ', mb_strtolower( $query, 'UTF-8' ) ) );
        if ( empty( $keywords ) ) {
            return array();
        }

        $where  = array( 'c.scope = %s' );
        $values = array( $scope_cfg['scope'] );

        switch ( $scope_cfg['scope'] ) {
            case 'user':
                $where[]  = 'c.user_id = %d';
                $values[] = (int) $scope_cfg['user_id'];
                break;
            case 'project':
                $where[]  = 'c.project_id = %d';
                $values[] = (int) $scope_cfg['project_id'];
                break;
            case 'session':
                $where[]  = 'c.session_id = %s';
                $values[] = $scope_cfg['session_id'];
                break;
            case 'agent':
                $where[]  = 'c.character_id = %d';
                $values[] = (int) $scope_cfg['character_id'];
                break;
        }

        // Keyword LIKE conditions
        $kw_parts = array();
        foreach ( $keywords as $kw ) {
            $kw = trim( $kw );
            if ( mb_strlen( $kw, 'UTF-8' ) < 2 ) {
                continue;
            }
            $kw_parts[] = $wpdb->prepare( 'c.content LIKE %s', '%' . $wpdb->esc_like( $kw ) . '%' );
        }

        if ( empty( $kw_parts ) ) {
            return array();
        }

        $where[]  = '(' . implode( ' OR ', $kw_parts ) . ')';
        $where_sql = implode( ' AND ', $where );
        $values[]  = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id as chunk_id, c.content, c.metadata,
                    s.source_name, s.source_type
             FROM {$chunks_table} c
             LEFT JOIN {$sources_table} s ON c.source_id = s.id
             WHERE {$where_sql}
             ORDER BY c.id DESC
             LIMIT %d",
            ...$values
        ) );

        $results = array();
        foreach ( $rows as $row ) {
            $results[] = array(
                'chunk_id'    => (int) $row->chunk_id,
                'content'     => $row->content,
                'score'       => 0.5, // Keyword match baseline
                'source_name' => $row->source_name,
                'source_type' => $row->source_type,
            );
        }

        return $results;
    }

    /**
     * Cosine similarity between two vectors.
     *
     * @param array $a
     * @param array $b
     * @return float  0.0 to 1.0
     */
    private function cosine_similarity( $a, $b ) {
        $dot    = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        $len    = min( count( $a ), count( $b ) );

        for ( $i = 0; $i < $len; $i++ ) {
            $ai = (float) $a[ $i ];
            $bi = (float) $b[ $i ];
            $dot    += $ai * $bi;
            $norm_a += $ai * $ai;
            $norm_b += $bi * $bi;
        }

        $denom = sqrt( $norm_a ) * sqrt( $norm_b );

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
