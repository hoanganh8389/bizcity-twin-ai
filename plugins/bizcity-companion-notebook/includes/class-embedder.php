<?php
defined( 'ABSPATH' ) || exit;

/**
 * BCN_Embedder — Embedding pipeline for notebook sources.
 *
 * Flow: source content_text → chunker → batch embed via OpenRouter → store in source_chunks.
 * Also provides semantic search across a project's embedded chunks.
 */
class BCN_Embedder {

    /** @var int Max texts per embedding API call. */
    const BATCH_SIZE = 20;

    private function table_chunks() {
        return BCN_Schema_Extend::table_source_chunks();
    }

    private function table_sources() {
        return BCN_Schema_Extend::table_sources();
    }

    /**
     * Embed a single source: chunk → embed → store.
     *
     * @param int    $source_id  Source record ID.
     * @param string $model      Override embedding model (empty = use default).
     * @return array { success: bool, chunks: int, error: string }
     */
    public function embed_source( int $source_id, string $model = '' ): array {
        global $wpdb;

        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, project_id, title, content_text, embedding_status FROM {$this->table_sources()} WHERE id = %d",
            $source_id
        ) );

        if ( ! $source ) {
            return [ 'success' => false, 'chunks' => 0, 'error' => 'Source not found.' ];
        }

        if ( empty( $source->content_text ) ) {
            $this->update_source_status( $source_id, 'skipped', 0, '' );
            return [ 'success' => true, 'chunks' => 0, 'error' => '' ];
        }

        // Check OpenRouter readiness.
        if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
            // Fallback: try loading mu-plugin manually.
            $mu_bootstrap = WP_CONTENT_DIR . '/mu-plugins/bizcity-openrouter/bootstrap.php';
            if ( file_exists( $mu_bootstrap ) ) {
                require_once $mu_bootstrap;
            }
        }
        if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
            return [ 'success' => false, 'chunks' => 0, 'error' => 'bizcity-openrouter plugin not available.' ];
        }

        // Mark processing.
        $this->update_source_status( $source_id, 'processing', 0, '' );

        // 1. Chunk the content.
        $chunker = new BCN_Chunker();
        $chunks  = $chunker->chunk( $source->content_text, $source->title );

        if ( empty( $chunks ) ) {
            $this->update_source_status( $source_id, 'skipped', 0, '' );
            return [ 'success' => true, 'chunks' => 0, 'error' => '' ];
        }

        // 2. Delete old chunks for this source (re-embed).
        $wpdb->delete( $this->table_chunks(), [ 'source_id' => $source_id ] );

        // 3. Batch embed.
        $total_stored = 0;
        $used_model   = $model;
        $batches      = array_chunk( $chunks, self::BATCH_SIZE );

        foreach ( $batches as $batch ) {
            $texts = array_map( fn( $c ) => $c['content'], $batch );

            $opts = [];
            if ( $model ) {
                $opts['model'] = $model;
            }

            $result = bizcity_openrouter_embeddings( $texts, $opts );

            if ( ! $result['success'] ) {
                // Mark failed, but keep any chunks already stored.
                $error_msg = mb_substr( $result['error'], 0, 500 );
                $this->update_source_status( $source_id, 'failed', $total_stored, $used_model );
                $wpdb->update( $this->table_sources(), [
                    'error_message' => $error_msg,
                ], [ 'id' => $source_id ] );

                return [ 'success' => false, 'chunks' => $total_stored, 'error' => $result['error'] ];
            }

            $used_model = $result['model'] ?? $used_model;

            // 4. Store chunks + embeddings.
            foreach ( $batch as $i => $chunk ) {
                $embedding = $result['embeddings'][ $i ] ?? null;
                if ( ! $embedding ) continue;

                $wpdb->insert( $this->table_chunks(), [
                    'source_id'       => $source_id,
                    'project_id'      => $source->project_id,
                    'chunk_index'     => $chunk['chunk_index'],
                    'content'         => $chunk['content'],
                    'token_count'     => $chunk['token_count'],
                    'embedding'       => wp_json_encode( $embedding ),
                    'embedding_model' => $used_model,
                    'created_at'      => current_time( 'mysql' ),
                ] );
                $total_stored++;
            }
        }

        // 5. Update source status.
        $this->update_source_status( $source_id, 'done', $total_stored, $used_model );

        return [ 'success' => true, 'chunks' => $total_stored, 'error' => '' ];
    }

    /**
     * Embed all pending sources for a project.
     *
     * @param string $project_id
     * @param string $model  Override embedding model.
     * @return array { total: int, success: int, failed: int, errors: string[] }
     */
    public function embed_project( string $project_id, string $model = '' ): array {
        global $wpdb;

        $sources = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$this->table_sources()}
             WHERE project_id = %s AND status = 'ready'
               AND embedding_status IN ('pending', 'failed')
             ORDER BY id ASC",
            $project_id
        ) );

        $result = [ 'total' => count( $sources ), 'success' => 0, 'failed' => 0, 'errors' => [] ];

        foreach ( $sources as $src ) {
            $r = $this->embed_source( (int) $src->id, $model );
            if ( $r['success'] ) {
                $result['success']++;
            } else {
                $result['failed']++;
                $result['errors'][] = "Source #{$src->id}: " . $r['error'];
            }
        }

        return $result;
    }

    /**
     * Semantic search across a project's embedded chunks.
     *
     * @param string $query       User's search query.
     * @param string $project_id  Project to search within.
     * @param int    $top_k       Number of top results to return.
     * @param float  $threshold   Minimum cosine similarity (0-1).
     * @param int[]  $source_ids  Optional: limit to specific source IDs.
     * @return array[] Sorted by similarity DESC: [ { chunk_id, source_id, content, similarity, token_count } ]
     */
    public function search( string $query, string $project_id, int $top_k = 5, float $threshold = 0.3, array $source_ids = [] ): array {
        global $wpdb;

        if ( empty( $query ) ) {
            return [];
        }

        if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
            $mu_bootstrap = WP_CONTENT_DIR . '/mu-plugins/bizcity-openrouter/bootstrap.php';
            if ( file_exists( $mu_bootstrap ) ) {
                require_once $mu_bootstrap;
            }
        }
        if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
            return [];
        }

        // Embed query.
        $q_result = bizcity_openrouter_embeddings( $query );
        if ( ! $q_result['success'] || empty( $q_result['embeddings'][0] ) ) {
            return [];
        }
        $query_vec = $q_result['embeddings'][0];

        // Load project chunks.
        $where_extra = '';
        if ( ! empty( $source_ids ) ) {
            $ids_csv = implode( ',', array_map( 'intval', $source_ids ) );
            $where_extra = " AND source_id IN ({$ids_csv})";
        }

        $chunks = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_id, content, token_count, embedding
             FROM {$this->table_chunks()}
             WHERE project_id = %s {$where_extra}
             ORDER BY id ASC",
            $project_id
        ) );

        if ( empty( $chunks ) ) {
            return [];
        }

        // Compute cosine similarity.
        $scored = [];
        foreach ( $chunks as $chunk ) {
            $vec = json_decode( $chunk->embedding, true );
            if ( ! is_array( $vec ) ) continue;

            $sim = $this->cosine_similarity( $query_vec, $vec );
            if ( $sim >= $threshold ) {
                $scored[] = [
                    'chunk_id'    => (int) $chunk->id,
                    'source_id'   => (int) $chunk->source_id,
                    'content'     => $chunk->content,
                    'token_count' => (int) $chunk->token_count,
                    'similarity'  => round( $sim, 4 ),
                ];
            }
        }

        // Sort by similarity descending.
        usort( $scored, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

        return array_slice( $scored, 0, $top_k );
    }

    /**
     * Build context string from semantic search results.
     * Used by BCN_Chat_Engine to inject relevant chunks into LLM prompt.
     *
     * @param string $query       User message.
     * @param string $project_id
     * @param int    $max_tokens  Token budget for context.
     * @param int[]  $source_ids  Optional: limit to specific sources.
     * @return string
     */
    public function build_context( string $query, string $project_id, int $max_tokens = 4000, array $source_ids = [] ): string {
        $results = $this->search( $query, $project_id, 10, 0.25, $source_ids );

        if ( empty( $results ) ) {
            return '';
        }

        $parts      = [];
        $used_tokens = 0;

        foreach ( $results as $r ) {
            if ( $used_tokens + $r['token_count'] > $max_tokens ) {
                break;
            }
            $parts[]      = $r['content'];
            $used_tokens += $r['token_count'];
        }

        return implode( "\n\n---\n\n", $parts );
    }

    /**
     * Get embedding status summary for a project.
     *
     * @param string $project_id
     * @return array { total, pending, processing, done, failed, skipped, total_chunks }
     */
    public function get_project_status( string $project_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT embedding_status, COUNT(*) as cnt FROM {$this->table_sources()}
             WHERE project_id = %s AND status = 'ready'
             GROUP BY embedding_status",
            $project_id
        ) );

        $status = [ 'total' => 0, 'pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 ];
        foreach ( $rows as $row ) {
            $status[ $row->embedding_status ] = (int) $row->cnt;
            $status['total'] += (int) $row->cnt;
        }

        $status['total_chunks'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_chunks()} WHERE project_id = %s",
            $project_id
        ) );

        return $status;
    }

    /**
     * Delete all chunks for a source.
     */
    public function delete_source_chunks( int $source_id ): void {
        global $wpdb;
        $wpdb->delete( $this->table_chunks(), [ 'source_id' => $source_id ] );
    }

    /**
     * Delete all chunks for a project.
     */
    public function delete_project_chunks( string $project_id ): void {
        global $wpdb;
        $wpdb->delete( $this->table_chunks(), [ 'project_id' => $project_id ] );
    }

    /* ── Private helpers ── */

    private function update_source_status( int $source_id, string $embed_status, int $chunk_count, string $model ): void {
        global $wpdb;
        $data = [
            'embedding_status' => $embed_status,
            'chunk_count'      => $chunk_count,
        ];
        if ( $model ) {
            $data['embedding_model'] = $model;
        }
        $wpdb->update( $this->table_sources(), $data, [ 'id' => $source_id ] );
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosine_similarity( array $a, array $b ): float {
        $len = min( count( $a ), count( $b ) );
        if ( $len === 0 ) return 0.0;

        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;

        for ( $i = 0; $i < $len; $i++ ) {
            $dot += $a[ $i ] * $b[ $i ];
            $na  += $a[ $i ] * $a[ $i ];
            $nb  += $b[ $i ] * $b[ $i ];
        }

        $denom = sqrt( $na ) * sqrt( $nb );
        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
