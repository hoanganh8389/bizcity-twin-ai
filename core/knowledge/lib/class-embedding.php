<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Embedding Service — OpenAI embeddings, vector search, text chunking
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_Embedding {
    
    private static $instance = null;

    /**
     * In-memory embedding cache (request-scoped)
     * @var array  hash => embedding vector
     */
    private $embed_cache = [];

    /**
     * Cache statistics for current request
     * @var array
     */
    private $cache_stats = [ 'hits_memory' => 0, 'hits_transient' => 0, 'misses' => 0 ];
    
    /**
     * Embedding model to use
     */
    const MODEL = 'text-embedding-3-small';
    
    /**
     * Dimensions for the embedding vector
     */
    const DIMENSIONS = 1536;
    
    /**
     * Max tokens per chunk
     */
    const CHUNK_SIZE = 500;
    
    /**
     * Overlap between chunks
     */
    const CHUNK_OVERLAP = 50;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get OpenAI API key
     */
    private function get_api_key() {
        return get_option('twf_openai_api_key', '');
    }
    
    /**
     * Create embedding for text (with 2-level cache)
     *
     * Cache layers:
     *   L1 — in-memory array  (instant, same PHP request)
     *   L2 — wp_transient     (DB, TTL 5 min, survives across requests)
     *
     * @param string $text Text to embed
     * @return array|WP_Error Embedding vector or error
     */
    public function create_embedding($text) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }
        
        // Truncate text if too long (max ~8000 tokens for embedding model)
        $text = $this->truncate_text($text, 8000);

        // ── Cache key: md5 of normalised text + model ──
        $normalised  = mb_strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ), 'UTF-8' );
        $cache_hash  = md5( self::MODEL . ':' . $normalised );
        $transient_k = 'bk_emb_' . $cache_hash;

        // L1: in-memory (same request — instant)
        if ( isset( $this->embed_cache[ $cache_hash ] ) ) {
            $this->cache_stats['hits_memory']++;
            return $this->embed_cache[ $cache_hash ];
        }

        // L2: wp_transient (cross-request — DB read, no HTTP)
        $cached = get_transient( $transient_k );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            $this->embed_cache[ $cache_hash ] = $cached;
            $this->cache_stats['hits_transient']++;
            return $cached;
        }

        // L3: API call (slow — 200-800ms)
        $this->cache_stats['misses']++;
        
        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => self::MODEL,
                'input' => $text
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['data'][0]['embedding'])) {
            $embedding = $body['data'][0]['embedding'];

            // Store in both cache layers (TTL 5 min for transient)
            $this->embed_cache[ $cache_hash ] = $embedding;
            set_transient( $transient_k, $embedding, 5 * MINUTE_IN_SECONDS );

            return $embedding;
        }
        
        $error_msg = $body['error']['message'] ?? 'Unknown embedding error';
        return new WP_Error('embedding_error', $error_msg);
    }

    /**
     * Get cache statistics for the current request.
     * @return array
     */
    public function get_cache_stats() {
        return $this->cache_stats;
    }
    
    /**
     * Create embeddings for multiple texts (batch)
     * 
     * @param array $texts Array of texts
     * @return array Array of embeddings or errors
     */
    public function create_embeddings_batch($texts) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }
        
        // Truncate each text
        $texts = array_map(function($text) {
            return $this->truncate_text($text, 8000);
        }, $texts);
        
        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => self::MODEL,
                'input' => $texts
            ]),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['data'])) {
            $embeddings = [];
            foreach ($body['data'] as $item) {
                $embeddings[$item['index']] = $item['embedding'];
            }
            ksort($embeddings);
            return array_values($embeddings);
        }
        
        $error_msg = $body['error']['message'] ?? 'Unknown embedding error';
        return new WP_Error('embedding_error', $error_msg);
    }
    
    /**
     * Chunk text into smaller pieces
     * 
     * @param string $text Text to chunk
     * @param int $chunk_size Max tokens per chunk
     * @param int $overlap Overlap between chunks
     * @return array Array of chunks
     */
    public function chunk_text($text, $chunk_size = null, $overlap = null) {
        $chunk_size = $chunk_size ?? self::CHUNK_SIZE;
        $overlap = $overlap ?? self::CHUNK_OVERLAP;
        
        // Split by paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $paragraphs = array_filter(array_map('trim', $paragraphs));
        
        $chunks = [];
        $current_chunk = '';
        $current_tokens = 0;
        
        foreach ($paragraphs as $para) {
            $para_tokens = $this->estimate_tokens($para);
            
            // If paragraph alone is larger than chunk size, split it
            if ($para_tokens > $chunk_size) {
                // Save current chunk if not empty
                if (!empty(trim($current_chunk))) {
                    $chunks[] = trim($current_chunk);
                }
                
                // Split large paragraph by sentences
                $sentences = preg_split('/(?<=[.!?])\s+/', $para);
                $current_chunk = '';
                $current_tokens = 0;
                
                foreach ($sentences as $sentence) {
                    $sentence_tokens = $this->estimate_tokens($sentence);
                    
                    if ($current_tokens + $sentence_tokens > $chunk_size && !empty(trim($current_chunk))) {
                        $chunks[] = trim($current_chunk);
                        // Keep some overlap
                        $overlap_text = $this->get_overlap_text($current_chunk, $overlap);
                        $current_chunk = $overlap_text . ' ' . $sentence;
                        $current_tokens = $this->estimate_tokens($current_chunk);
                    } else {
                        $current_chunk .= ' ' . $sentence;
                        $current_tokens += $sentence_tokens;
                    }
                }
                continue;
            }
            
            // Check if adding paragraph exceeds chunk size
            if ($current_tokens + $para_tokens > $chunk_size && !empty(trim($current_chunk))) {
                $chunks[] = trim($current_chunk);
                // Keep some overlap
                $overlap_text = $this->get_overlap_text($current_chunk, $overlap);
                $current_chunk = $overlap_text . "\n\n" . $para;
                $current_tokens = $this->estimate_tokens($current_chunk);
            } else {
                $current_chunk .= "\n\n" . $para;
                $current_tokens += $para_tokens;
            }
        }
        
        // Add remaining chunk
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Get overlap text from end of chunk
     */
    private function get_overlap_text($text, $overlap_tokens) {
        $words = explode(' ', $text);
        $overlap_words = intval($overlap_tokens * 0.75); // Approximate words from tokens
        
        if (count($words) <= $overlap_words) {
            return $text;
        }
        
        return implode(' ', array_slice($words, -$overlap_words));
    }
    
    /**
     * Estimate token count (rough approximation)
     * ~4 chars per token for English, ~2 for Vietnamese
     */
    public function estimate_tokens($text) {
        // Check if Vietnamese
        if (preg_match('/[\x{0080}-\x{FFFF}]/u', $text)) {
            return intval(mb_strlen($text) / 2);
        }
        return intval(strlen($text) / 4);
    }
    
    /**
     * Truncate text to max tokens
     */
    private function truncate_text($text, $max_tokens) {
        $current_tokens = $this->estimate_tokens($text);
        
        if ($current_tokens <= $max_tokens) {
            return $text;
        }
        
        // Approximate chars to keep
        $ratio = $max_tokens / $current_tokens;
        $max_chars = intval(mb_strlen($text) * $ratio * 0.9); // 10% buffer
        
        return mb_substr($text, 0, $max_chars);
    }
    
    /**
     * Calculate cosine similarity between two vectors (optimized).
     *
     * @param array $vec1 First vector
     * @param array $vec2 Second vector
     * @return float Similarity score (0-1)
     */
    public function cosine_similarity( $vec1, $vec2 ) {
        $len = count( $vec1 );
        if ( $len !== count( $vec2 ) || $len === 0 ) {
            return 0.0;
        }

        $dot = 0.0;
        $n1  = 0.0;
        $n2  = 0.0;

        for ( $i = 0; $i < $len; $i++ ) {
            $a    = $vec1[ $i ];
            $b    = $vec2[ $i ];
            $dot += $a * $b;
            $n1  += $a * $a;
            $n2  += $b * $b;
        }

        if ( $n1 == 0.0 || $n2 == 0.0 ) {
            return 0.0;
        }

        return $dot / ( sqrt( $n1 ) * sqrt( $n2 ) );
    }
    
    /**
     * Search for similar chunks
     * 
     * @param string $query Query text
     * @param int $character_id Character ID
     * @param int $top_k Number of results to return
     * @param float $threshold Minimum similarity threshold
     * @return array Array of similar chunks with scores
     */
    public function search_similar($query, $character_id, $top_k = 5, $threshold = 0.7) {
        $search_start = microtime( true );

        /* ── L0: Search result cache (full result TTL 10 min) ── */
        $cache_key = 'bk_sr_' . md5( $query . '|' . $character_id . '|' . $top_k . '|' . $threshold );

        // L0a: in-memory (same request)
        static $result_cache = [];
        if ( isset( $result_cache[ $cache_key ] ) ) {
            $this->_log_search_hit( 'L0_memory', $query, $character_id, $result_cache[ $cache_key ], $search_start );
            return $result_cache[ $cache_key ];
        }
        // L0b: transient (cross-request, 10 min TTL — knowledge data rarely changes)
        $cached = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            $result_cache[ $cache_key ] = $cached;
            $this->_log_search_hit( 'L0_transient', $query, $character_id, $cached, $search_start );
            return $cached;
        }

        /* ── Early exit: skip embedding API call if character has no chunks ── */
        $db = BizCity_Knowledge_Database::instance();
        $chunk_count = $db->count_chunks( $character_id );
        if ( $chunk_count === 0 ) {
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'semantic_search',
                    'message'          => mb_substr( $query, 0, 80, 'UTF-8' ),
                    'mode'             => 'embedding',
                    'functions_called' => 'search_similar() → SKIP (0 chunks)',
                    'file_line'        => 'class-embedding.php::search_similar',
                    'character_id'     => $character_id,
                    'total_chunks'     => 0,
                    'total_ms'         => round( ( microtime( true ) - $search_start ) * 1000, 2 ),
                    'skip_reason'      => 'no_embedding_chunks',
                ] );
            }
            return [];
        }

        // Create embedding for query (uses L1/L2 cache)
        $query_embedding = $this->create_embedding($query);
        $embed_ms = round( ( microtime( true ) - $search_start ) * 1000, 2 );
        
        if (is_wp_error($query_embedding)) {
            return [];
        }
        
        /* ── Load decoded embedding vectors (with file cache) ── */
        $t0 = microtime( true );
        $embeddings = $this->_load_character_embeddings( $character_id );
        $chunks_ms  = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        $embed_source = $embeddings['_source'] ?? 'db';
        unset( $embeddings['_source'] );

        if ( empty( $embeddings ) ) {
            return [];
        }

        /* ── Cosine similarity (optimised loop) ── */
        $t0       = microtime( true );
        $matches  = [];

        foreach ( $embeddings as $chunk_id => $chunk_vec ) {
            $sim = $this->cosine_similarity( $query_embedding, $chunk_vec );
            if ( $sim >= $threshold ) {
                $matches[] = [ 'chunk_id' => (int) $chunk_id, 'similarity' => $sim ];
            }
        }
        $cosine_ms = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // Sort by similarity descending
        usort( $matches, function ( $a, $b ) {
            return $b['similarity'] <=> $a['similarity'];
        } );
        $top_matches = array_slice( $matches, 0, $top_k );

        /* ── Load full chunk data only for matched IDs ── */
        $t_detail = microtime( true );
        $matched_ids = array_column( $top_matches, 'chunk_id' );
        $db          = BizCity_Knowledge_Database::instance();
        $full_chunks = $db->get_chunks_by_ids( $matched_ids );
        $detail_ms   = round( ( microtime( true ) - $t_detail ) * 1000, 2 );

        // Build final results with full content
        $final = [];
        foreach ( $top_matches as $match ) {
            $cid  = $match['chunk_id'];
            $full = $full_chunks[ $cid ] ?? null;
            if ( ! $full ) {
                continue;
            }
            $final[] = [
                'chunk_id'   => $cid,
                'content'    => $full->content,
                'similarity' => $match['similarity'],
                'source_id'  => $full->source_id,
                'metadata'   => json_decode( $full->metadata ?? '{}', true ),
            ];
        }

        // Store in L0 cache (10 min TTL)
        $result_cache[ $cache_key ] = $final;
        set_transient( $cache_key, $final, 10 * MINUTE_IN_SECONDS );

        // Log with timing breakdown + cache stats
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $stats = $this->get_cache_stats();
            BizCity_User_Memory::log_router_event( [
                'step'             => 'semantic_search',
                'message'          => mb_substr( $query, 0, 80, 'UTF-8' ),
                'mode'             => 'embedding',
                'functions_called' => 'search_similar() v2',
                'pipeline'         => [
                    '1:Embedding'    . ( $embed_ms < 5 ? ' ✓ CACHED' : ' ✓ API' ),
                    '2:LoadEmbeds (' . $embed_source . ')',
                    '3:CosineSim ✓',
                    '4:LoadDetails ✓',
                    '5:RankTopK ✓',
                ],
                'file_line'        => 'class-embedding.php::search_similar',
                'character_id'     => $character_id,
                'total_chunks'     => count( $embeddings ),
                'matched_chunks'   => count( $matches ),
                'top_k'            => $top_k,
                'threshold'        => $threshold,
                'embed_ms'         => $embed_ms,
                'chunks_load_ms'   => $chunks_ms,
                'cosine_ms'        => $cosine_ms,
                'detail_ms'        => $detail_ms,
                'total_ms'         => round( ( microtime( true ) - $search_start ) * 1000, 2 ),
                'embed_source'     => $embed_source,
                'cache_hit'        => ( $stats['hits_memory'] + $stats['hits_transient'] ) > 0,
                'cache_stats'      => $stats,
                'result_cached'    => 'stored (10 min TTL)',
            ] );
        }
        
        return $final;
    }

    /* ================================================================
     *  Decoded embedding cache (file-based)
     *
     *  Storing decoded float arrays via serialize()/unserialize() is
     *  ~10× faster than json_decode() × 1000 per request.
     *  Cache file: wp-content/cache/bk-embeds/char_{id}.php
     *  Invalidation: automatic when chunk count changes.
     * ================================================================ */

    /**
     * Load decoded embedding vectors for a character.
     *
     * Priority: static memory → file cache → DB (json_decode).
     *
     * @param int $character_id
     * @return array [ chunk_id => [float...], ..., '_source' => 'memory'|'file'|'db' ]
     */
    private function _load_character_embeddings( $character_id ) {
        // In-memory cache (same request, different queries)
        static $mem = [];
        if ( isset( $mem[ $character_id ] ) ) {
            $data = $mem[ $character_id ];
            $data['_source'] = 'memory';
            return $data;
        }

        $db          = BizCity_Knowledge_Database::instance();
        $chunk_count = $db->count_chunks( $character_id );
        $cache_dir   = WP_CONTENT_DIR . '/cache/bk-embeds';
        $cache_file  = $cache_dir . '/char_' . intval( $character_id ) . '.php';

        // Try file cache
        if ( file_exists( $cache_file ) ) {
            $raw = @file_get_contents( $cache_file );
            if ( $raw !== false ) {
                $data = @unserialize( $raw );
                if ( is_array( $data ) && ( $data['_count'] ?? 0 ) === $chunk_count ) {
                    unset( $data['_count'] );
                    $mem[ $character_id ] = $data;
                    $data['_source'] = 'file';
                    return $data;
                }
            }
        }

        // Fall back to DB: load only id + embedding (lightweight query)
        $raw_chunks = $db->get_chunk_embeddings( $character_id, 1000 );
        if ( empty( $raw_chunks ) ) {
            return [];
        }

        $vectors = [];
        foreach ( $raw_chunks as $row ) {
            $vec = json_decode( $row->embedding, true );
            if ( ! empty( $vec ) && is_array( $vec ) ) {
                $vectors[ (int) $row->id ] = $vec;
            }
        }

        // Store to file cache
        if ( ! empty( $vectors ) ) {
            $to_store = $vectors;
            $to_store['_count'] = $chunk_count;
            if ( ! is_dir( $cache_dir ) ) {
                @mkdir( $cache_dir, 0755, true );
            }
            @file_put_contents( $cache_file, serialize( $to_store ) );
        }

        $mem[ $character_id ] = $vectors;
        $vectors['_source']   = 'db';
        return $vectors;
    }

    /**
     * Invalidate the file-based embedding cache for a character.
     *
     * Call this after adding/updating/deleting chunks.
     *
     * @param int $character_id
     */
    public function invalidate_embedding_cache( $character_id ) {
        $cache_file = WP_CONTENT_DIR . '/cache/bk-embeds/char_' . intval( $character_id ) . '.php';
        if ( file_exists( $cache_file ) ) {
            @unlink( $cache_file );
        }

        // Also clear all L0 result transients for this character
        // (We can't enumerate transients easily, but the chunk count change in _load_character_embeddings
        //  will naturally invalidate the file cache. L0 result transients will expire in 10 min.)
    }

    /**
     * Log a search result cache hit.
     */
    private function _log_search_hit( $layer, $query, $character_id, $results, $start ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) return;
        BizCity_User_Memory::log_router_event( [
            'step'             => 'semantic_search',
            'message'          => mb_substr( $query, 0, 80, 'UTF-8' ),
            'mode'             => 'embedding',
            'functions_called' => 'search_similar() → ' . $layer . ' HIT',
            'file_line'        => 'class-embedding.php::search_similar',
            'character_id'     => $character_id,
            'matched_results'  => count( $results ),
            'total_ms'         => round( ( microtime( true ) - $start ) * 1000, 2 ),
            'cache_hit'        => true,
            'cache_layer'      => $layer,
        ] );
    }
    
    /**
     * Process and embed a knowledge source
     * 
     * @param int $source_id Knowledge source ID
     * @param string $content Text content to embed
     * @return array|WP_Error Result with chunks count or error
     */
    public function process_source($source_id, $content) {
        global $wpdb;
        
        // Get source info
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE id = %d",
            $source_id
        ));
        
        if (!$source) {
            return new WP_Error('not_found', 'Source not found');
        }
        
        $character_id = $source->character_id;
        
        // Update source status to processing
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['status' => 'processing'],
            ['id' => $source_id]
        );
        
        try {
            // Chunk the content
            $chunks = $this->chunk_text($content);
            
            if (empty($chunks)) {
                throw new Exception('No content to process');
            }
            
            // Delete existing chunks for this source
            $wpdb->delete(
                $wpdb->prefix . 'bizcity_knowledge_chunks',
                ['source_id' => $source_id]
            );
            
            // Create embeddings in batches of 20
            $batch_size = 20;
            $total_chunks = count($chunks);
            $db = BizCity_Knowledge_Database::instance();
            
            for ($i = 0; $i < $total_chunks; $i += $batch_size) {
                $batch = array_slice($chunks, $i, $batch_size);
                $embeddings = $this->create_embeddings_batch($batch);
                
                if (is_wp_error($embeddings)) {
                    throw new Exception($embeddings->get_error_message());
                }
                
                // Save chunks with embeddings
                foreach ($batch as $j => $chunk_content) {
                    $db->create_chunk([
                        'source_id' => $source_id,
                        'character_id' => $character_id,
                        'chunk_index' => $i + $j,
                        'content' => $chunk_content,
                        'token_count' => $this->estimate_tokens($chunk_content),
                        'embedding' => $embeddings[$j],
                        'metadata' => [
                            'source_name' => $source->source_name,
                            'source_type' => $source->source_type
                        ]
                    ]);
                }
            }
            
            // Update source status to ready
            $wpdb->update(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'status' => 'ready',
                    'chunks_count' => $total_chunks,
                    'content_hash' => md5($content),
                    'last_synced_at' => current_time('mysql')
                ],
                ['id' => $source_id]
            );
            
            return [
                'success' => true,
                'chunks_count' => $total_chunks,
                'message' => "Đã xử lý {$total_chunks} chunks"
            ];
            
        } catch (Exception $e) {
            // Update source status to error
            $wpdb->update(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'status' => 'error',
                    'error_message' => $e->getMessage()
                ],
                ['id' => $source_id]
            );
            
            return new WP_Error('processing_error', $e->getMessage());
        } finally {
            // ── Invalidate embedding file cache after any chunk change ──
            $this->invalidate_embedding_cache( $character_id );
        }
    }
}
