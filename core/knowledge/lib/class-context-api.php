<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Context API — Context building for AI characters (FAQ, semantic, vision)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since 1.2.0
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_Context_API {
    
    private static $instance = null;
    
    /**
     * Configuration
     */
    private $config = [
        'max_tokens' => 3000,
        'quick_knowledge_ratio' => 0.5, // Max 50% for quick knowledge
        'semantic_threshold' => 0.65,
        'semantic_top_k' => 5,
        'vision_enabled' => true,
        'vision_max_images' => 3,
        'vision_detail' => 'auto', // low, high, auto
    ];
    
    /**
     * Supported Vision models
     */
    const VISION_MODELS = [
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4-vision-preview'],
        'openrouter' => [
            'openai/gpt-4o',
            'openai/gpt-4o-mini',
            'anthropic/claude-3.5-sonnet',
            'anthropic/claude-3-opus',
            'anthropic/claude-3-sonnet',
            'anthropic/claude-3-haiku',
            'google/gemini-pro-1.5',
            'google/gemini-flash-1.5',
        ]
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->register_hooks();
    }
    
    /**
     * Register WordPress hooks for external access
     */
    private function register_hooks() {
        // Action hooks for workflow builder
        add_action('bizcity_knowledge_build_context', [$this, 'action_build_context'], 10, 3);
        add_action('bizcity_knowledge_process_vision', [$this, 'action_process_vision'], 10, 2);
        
        // Filter hooks for customization
        add_filter('bizcity_knowledge_context_config', [$this, 'filter_context_config'], 10, 2);
        add_filter('bizcity_knowledge_context_parts', [$this, 'filter_context_parts'], 10, 3);
        
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('bizcity-knowledge/v1', '/context/build', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_build_context'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
        
        register_rest_route('bizcity-knowledge/v1', '/vision/describe', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_describe_image'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }
    
    /**
     * Set configuration
     * 
     * @param array $config Configuration options
     */
    public function set_config($config) {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * Get configuration
     * 
     * @return array Current configuration
     */
    public function get_config() {
        return $this->config;
    }
    
    /**
     * Build knowledge context for a character
     * 
     * Main entry point for context building.
     * 
     * @param int $character_id Character ID
     * @param string $query User query
     * @param array $options Additional options (images, config overrides)
     * @return array Context result with text and metadata
     */
    public function build_context($character_id, $query, $options = []) {
        // Start timing
        $build_start = microtime( true );

        error_log( sprintf(
            '[ContextAPI] build_context START | char_id=%d | query=%s | max_tokens=%s',
            $character_id,
            mb_substr( $query, 0, 80, 'UTF-8' ),
            $options['config']['max_tokens'] ?? $this->config['max_tokens'] ?? '?'
        ) );

        $config = array_merge($this->config, $options['config'] ?? []);
        $config = apply_filters('bizcity_knowledge_context_config', $config, $character_id);
        
        $result = [
            'context' => '',
            'parts' => [],
            'sources' => [],
            'tokens_used' => 0,
            'vision_descriptions' => [],
            'metadata' => [
                'character_id' => $character_id,
                'query' => $query,
                'timestamp' => current_time('mysql')
            ]
        ];
        
        $context_parts = [];
        $total_tokens = 0;
        $max_tokens = $config['max_tokens'];
        
        // Part 1: Process images with Vision API (if provided)
        if (!empty($options['images']) && $config['vision_enabled']) {
            $vision_result = $this->process_images_for_context($options['images'], $query, $config);
            
            if (!empty($vision_result['descriptions'])) {
                $vision_text = "### Nội dung từ hình ảnh:\n" . implode("\n\n", $vision_result['descriptions']);
                $vision_tokens = $this->estimate_tokens($vision_text);
                
                if ($vision_tokens <= $max_tokens * 0.3) { // Max 30% for vision
                    $context_parts[] = [
                        'type' => 'vision',
                        'content' => $vision_text,
                        'tokens' => $vision_tokens
                    ];
                    $total_tokens += $vision_tokens;
                }
                
                $result['vision_descriptions'] = $vision_result['descriptions'];
            }
        }
        
        // Part 2: Quick Knowledge / FAQ (direct injection — relevance-ranked, budget-aware)
        $quick_limit = (int) ( ($max_tokens - $total_tokens) * $config['quick_knowledge_ratio'] );
        $quick_result = $this->build_quick_knowledge_context($character_id, $config, $query, $quick_limit);
        
        if (!empty($quick_result['content'])) {
            $context_parts[] = [
                'type' => 'quick_knowledge',
                'content' => $quick_result['content'],
                'tokens' => $quick_result['tokens'],
                'sources' => $quick_result['sources']
            ];
            $total_tokens += $quick_result['tokens'];
            $result['sources'] = array_merge($result['sources'], $quick_result['sources']);
        }
        
        // Part 3: Semantic search for documents (embedding-based)
        // Skip entirely if character has no embedding chunks (saves ~200-800ms embedding API call)
        $remaining_tokens = $max_tokens - $total_tokens;
        $has_chunks = ( class_exists( 'BizCity_Knowledge_Database' ) )
            ? BizCity_Knowledge_Database::instance()->count_chunks( $character_id ) > 0
            : true; // Assume yes if DB class unavailable

        if ($remaining_tokens > 500 && $has_chunks) {
            $semantic_result = $this->build_semantic_context($character_id, $query, $remaining_tokens, $config);
            
            if (!empty($semantic_result['content'])) {
                $context_parts[] = [
                    'type' => 'semantic',
                    'content' => $semantic_result['content'],
                    'tokens' => $semantic_result['tokens'],
                    'chunks' => $semantic_result['chunks'],
                    'sources' => $semantic_result['sources']
                ];
                $total_tokens += $semantic_result['tokens'];
                $result['sources'] = array_merge($result['sources'], $semantic_result['sources']);
            }
        }
        /*
        // Part 4: Global Memory Knowledge (priority knowledge from user uploads)
        $remaining_tokens = $max_tokens - $total_tokens;
        if ( $remaining_tokens > 200 && class_exists( 'BizCity_User_Memory' ) ) {
            $global_char_id = BizCity_User_Memory::get_global_character_id();
            if ( $global_char_id && $global_char_id !== $character_id ) {
                $global_result = $this->build_semantic_context( $global_char_id, $query, $remaining_tokens, array_merge( $config, [
                    'semantic_top_k'     => 3,
                    'semantic_threshold' => 0.70,
                ] ) );
                if ( ! empty( $global_result['content'] ) ) {
                    $global_content = str_replace(
                        '### Tài liệu liên quan:',
                        '### 🌐 Kiến thức ưu tiên (Global Memory):',
                        $global_result['content']
                    );
                    $context_parts[] = [
                        'type'    => 'global_memory',
                        'content' => $global_content,
                        'tokens'  => $global_result['tokens'],
                        'chunks'  => $global_result['chunks'] ?? [],
                        'sources' => $global_result['sources'] ?? [],
                    ];
                    $total_tokens += $global_result['tokens'];
                    $result['sources'] = array_merge( $result['sources'], $global_result['sources'] ?? [] );
                }
            }
        }*/
        
        // Part 5: Intent Tag Routing — pull knowledge from other characters whose tags match the query
        $remaining_tokens = $max_tokens - $total_tokens;
        if ( $remaining_tokens > 300 && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $tagged_result = $this->build_tagged_knowledge_context( $character_id, $query, $remaining_tokens, $config );
            if ( ! empty( $tagged_result['parts'] ) ) {
                foreach ( $tagged_result['parts'] as $tp ) {
                    $context_parts[] = $tp;
                    $total_tokens += $tp['tokens'];
                    $result['sources'] = array_merge( $result['sources'], $tp['sources'] ?? [] );
                }
            }
        }
        
        // Apply filter for custom context parts
        $context_parts = apply_filters('bizcity_knowledge_context_parts', $context_parts, $character_id, $query);
        
        // Build final context string
        $context_strings = array_map(function($part) {
            return $part['content'];
        }, $context_parts);
        
        $result['context'] = implode("\n\n", $context_strings);
        $result['parts'] = $context_parts;
        $result['tokens_used'] = $total_tokens;

        // Expose intent_tag status in metadata for gateway pipeline logs
        $part_types = array_column( $context_parts, 'type' );
        $result['metadata']['has_intent_tag'] = in_array( 'intent_tag_match', $part_types, true );
        $result['metadata']['intent_tag_chars'] = array_column(
            array_filter( $context_parts, function( $p ) { return $p['type'] === 'intent_tag_match'; } ),
            'character_id'
        );

        // ── Log for admin AJAX Console ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $part_types = array_column( $context_parts, 'type' );
            BizCity_User_Memory::log_router_event( [
                'step'             => 'knowledge_search',
                'message'          => mb_substr( $query, 0, 120, 'UTF-8' ),
                'mode'             => 'context_api',
                'functions_called' => 'BizCity_Knowledge_Context_API::build_context()',
                'pipeline'         => [ 'Vision', 'QuickKnowledge', 'SemanticSearch', 'GlobalMemory', 'IntentTagRouting', 'Filters' ],
                'file_line'        => 'class-context-api.php:~L220',
                'character_id'     => $character_id,
                'tokens_used'      => $total_tokens,
                'max_tokens'       => $max_tokens,
                'parts_found'      => $part_types,
                'has_vision'       => in_array( 'vision', $part_types, true ),
                'has_quick'        => in_array( 'quick_knowledge', $part_types, true ),
                'has_semantic'     => in_array( 'semantic', $part_types, true ),
                'has_global'       => in_array( 'global_memory', $part_types, true ),
                'has_intent_tag'   => in_array( 'intent_tag_match', $part_types, true ),
                'sources_count'    => count( $result['sources'] ),
                'context_length'   => mb_strlen( $result['context'], 'UTF-8' ),
                'build_ms'         => round( ( microtime( true ) - $build_start ) * 1000, 2 ),
            ] );
        }

        return $result;
    }
    
    /**
     * Build quick knowledge context (FAQ, manual entries)
     * 
     * @param int $character_id Character ID
     * @param array $config Configuration
     * @return array Quick knowledge result
     */
    private function build_quick_knowledge_context($character_id, $config, $query = '', $max_tokens = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        // Explode query into keywords (≥2 chars) for SQL LIKE + in-memory scoring
        $query_lower = mb_strtolower( $query, 'UTF-8' );
        $query_words = $query_lower ? array_filter( preg_split( '/\s+/', $query_lower ), function( $w ) {
            return mb_strlen( $w, 'UTF-8' ) >= 2;
        } ) : [];

        // SQL: character-specific + global (character_id=0) + keyword LIKE on content/source_name
        $where = $wpdb->prepare(
            "source_type IN ('quick_faq','manual') AND status = 'ready' AND (character_id = %d OR character_id = 0)",
            $character_id
        );

        if ( ! empty( $query_words ) ) {
            $like_clauses = [];
            foreach ( $query_words as $word ) {
                $esc = '%' . $wpdb->esc_like( $word ) . '%';
                $like_clauses[] = $wpdb->prepare( '(LOWER(content) LIKE %s OR LOWER(source_name) LIKE %s)', $esc, $esc );
            }
            $where .= ' AND (' . implode( ' OR ', $like_clauses ) . ')';
        }

        $sources = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 100" );

        error_log( sprintf(
            '[ContextAPI] build_quick_knowledge SQL | char_id=%d | keywords=%s | sql_rows=%d',
            $character_id,
            implode( ',', $query_words ),
            count( $sources ?: [] )
        ) );

        // Parse content + compute relevance score
        $entries = [];
        foreach ( ( $sources ?: [] ) as $source ) {
            $content = json_decode( $source->content, true );
            $text = '';

            if ( is_array( $content ) ) {
                if ( isset( $content['question'] ) && isset( $content['answer'] ) ) {
                    $text = "Q: {$content['question']}\nA: {$content['answer']}";
                } elseif ( isset( $content['title'] ) && isset( $content['content'] ) ) {
                    $text = "### {$content['title']}\n{$content['content']}";
                }
            } elseif ( ! empty( $source->content ) ) {
                $text = $source->content;
            }

            if ( empty( $text ) ) {
                continue;
            }

            // Score by keyword overlap with query
            $score = 0;
            if ( ! empty( $query_words ) ) {
                $text_lower = mb_strtolower( $text, 'UTF-8' );
                foreach ( $query_words as $w ) {
                    if ( mb_strpos( $text_lower, $w ) !== false ) {
                        $score++;
                    }
                }
            }

            $entries[] = [
                'text'      => $text,
                'source_id' => $source->id,
                'score'     => $score,
            ];
        }

        error_log( sprintf(
            '[ContextAPI] build_quick_knowledge | char_id=%d | total_entries=%d | budget=%s | query=%s',
            $character_id, count( $entries ),
            $max_tokens > 0 ? $max_tokens : 'unlimited',
            mb_substr( $query, 0, 60, 'UTF-8' )
        ) );

        if (empty($entries)) {
            error_log( '[ContextAPI] build_quick_knowledge | NO entries found — returning empty' );
            return ['content' => '', 'tokens' => 0, 'sources' => []];
        }
        
        // Sort: highest relevance first, preserve insertion order for ties
        usort( $entries, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );
        
        // Budget-aware per-entry inclusion
        $header        = "### Kiến thức nhanh:\n";
        $header_tokens = $this->estimate_tokens( $header );
        $budget        = $max_tokens > 0 ? $max_tokens : PHP_INT_MAX;
        $accumulated   = $header_tokens;
        $selected      = [];
        $source_ids    = [];
        $skipped       = 0;
        
        foreach ( $entries as $e ) {
            $entry_tokens = $this->estimate_tokens( $e['text'] );
            if ( $accumulated + $entry_tokens > $budget ) {
                $skipped++;
                continue;
            }
            $selected[]  = $e['text'];
            $source_ids[] = $e['source_id'];
            $accumulated += $entry_tokens;
        }

        error_log( sprintf(
            '[ContextAPI] build_quick_knowledge | selected=%d/%d | skipped=%d | tokens=%d/%s | top_score=%d',
            count( $selected ), count( $entries ), $skipped,
            $accumulated, $max_tokens > 0 ? $max_tokens : 'unlimited',
            $entries[0]['score'] ?? 0
        ) );
        
        if ( empty( $selected ) ) {
            error_log( '[ContextAPI] build_quick_knowledge | ALL entries skipped (budget too small)' );
            return ['content' => '', 'tokens' => 0, 'sources' => []];
        }
        
        $quick_text = $header . implode("\n\n", $selected);
        
        return [
            'content' => $quick_text,
            'tokens' => $accumulated,
            'sources' => $source_ids
        ];
    }
    
    /**
     * Build semantic context using embeddings
     * 
     * @param int $character_id Character ID
     * @param string $query User query
     * @param int $max_tokens Maximum tokens to use
     * @param array $config Configuration
     * @return array Semantic search result
     */
    private function build_semantic_context($character_id, $query, $max_tokens, $config) {
        $embedding = BizCity_Knowledge_Embedding::instance();
        
        $similar_chunks = $embedding->search_similar(
            $query, 
            $character_id, 
            $config['semantic_top_k'], 
            $config['semantic_threshold']
        );
        
        if (empty($similar_chunks)) {
            return ['content' => '', 'tokens' => 0, 'chunks' => [], 'sources' => []];
        }
        
        $doc_context = [];
        $doc_tokens = 0;
        $chunk_data = [];
        $source_ids = [];
        
        foreach ($similar_chunks as $chunk) {
            $chunk_tokens = $this->estimate_tokens($chunk['content']);
            
            if ($doc_tokens + $chunk_tokens <= $max_tokens) {
                $source_name = $chunk['metadata']['source_name'] ?? 'Document';
                $similarity_pct = round($chunk['similarity'] * 100);
                
                $doc_context[] = "[Nguồn: {$source_name}, Độ liên quan: {$similarity_pct}%]\n{$chunk['content']}";
                $doc_tokens += $chunk_tokens;
                
                $chunk_data[] = [
                    'id' => $chunk['id'],
                    'similarity' => $chunk['similarity'],
                    'source_name' => $source_name
                ];
                
                if (!empty($chunk['source_id'])) {
                    $source_ids[] = $chunk['source_id'];
                }
            }
        }
        
        if (empty($doc_context)) {
            return ['content' => '', 'tokens' => 0, 'chunks' => [], 'sources' => []];
        }
        
        $content = "### Tài liệu liên quan:\n" . implode("\n\n---\n\n", $doc_context);
        
        return [
            'content' => $content,
            'tokens' => $doc_tokens,
            'chunks' => $chunk_data,
            'sources' => array_unique($source_ids)
        ];
    }
    
    /**
     * Build knowledge context from other characters whose intent_tags match the user query.
     * 
     * This enables cross-character knowledge routing:
     * - Query all active characters (excluding current) that have intent_tags
     * - Check if the user's message contains any of their tags
     * - Pull quick knowledge + semantic chunks from matching characters
     * - Append as supplementary context so the AI can reference specialized knowledge
     * 
     * @param int    $current_character_id  The character currently handling the conversation
     * @param string $query                 The user's message
     * @param int    $max_tokens            Token budget for tagged knowledge
     * @param array  $config                Pipeline config
     * @return array ['parts' => [...]]
     */
    private function build_tagged_knowledge_context( $current_character_id, $query, $max_tokens, $config ) {
        $result = [ 'parts' => [] ];
        
        $db = BizCity_Knowledge_Database::instance();
        $all_characters = $db->get_characters( [ 'status' => 'active' ] );
        
        if ( empty( $all_characters ) ) {
            return $result;
        }
        
        // Normalize query for matching (lowercase, no diacritics is too aggressive — keep Vietnamese)
        $query_lower = mb_strtolower( $query, 'UTF-8' );
        $remaining  = $max_tokens;
        $matched_ids = [];
        
        foreach ( $all_characters as $char ) {
            if ( (int) $char->id === (int) $current_character_id ) {
                continue; // skip self
            }
            
            // Decode intent_tags
            $tags = [];
            if ( ! empty( $char->intent_tags ) ) {
                $decoded = is_string( $char->intent_tags ) ? json_decode( $char->intent_tags, true ) : $char->intent_tags;
                if ( is_array( $decoded ) ) {
                    $tags = $decoded;
                }
            }
            
            if ( empty( $tags ) ) {
                continue;
            }
            
            // Check if any tag appears in the query
            $matched_tags = [];
            foreach ( $tags as $tag ) {
                $tag_lower = mb_strtolower( trim( $tag ), 'UTF-8' );
                if ( $tag_lower && mb_strpos( $query_lower, $tag_lower ) !== false ) {
                    $matched_tags[] = $tag;
                }
            }
            
            if ( empty( $matched_tags ) ) {
                continue;
            }
            
            // We have a match — pull this character's knowledge
            $matched_ids[] = $char->id;
            
            // Quick knowledge from the matched character
            $quick = $this->build_quick_knowledge_context( $char->id, $config );
            $tagged_content = '';
            $tagged_tokens  = 0;
            
            if ( ! empty( $quick['content'] ) && $quick['tokens'] <= $remaining * 0.5 ) {
                $tagged_content .= $quick['content'];
                $tagged_tokens  += $quick['tokens'];
            }
            
            // Semantic search from the matched character
            $sem_budget = min( $remaining - $tagged_tokens, 1500 );
            if ( $sem_budget > 300 ) {
                $has_chunks = $db->count_chunks( $char->id ) > 0;
                if ( $has_chunks ) {
                    $sem = $this->build_semantic_context( $char->id, $query, $sem_budget, array_merge( $config, [
                        'semantic_top_k'     => 3,
                        'semantic_threshold' => 0.65,
                    ] ) );
                    if ( ! empty( $sem['content'] ) ) {
                        if ( $tagged_content ) {
                            $tagged_content .= "\n\n";
                        }
                        $tagged_content .= $sem['content'];
                        $tagged_tokens  += $sem['tokens'];
                    }
                }
            }
            
            if ( ! empty( $tagged_content ) && $tagged_tokens <= $remaining ) {
                $header = sprintf(
                    '### 🏷️ Kiến thức từ "%s" (tags: %s):',
                    esc_html( $char->name ),
                    implode( ', ', $matched_tags )
                );
                $result['parts'][] = [
                    'type'         => 'intent_tag_match',
                    'content'      => $header . "\n" . $tagged_content,
                    'tokens'       => $tagged_tokens,
                    'character_id' => $char->id,
                    'matched_tags' => $matched_tags,
                    'sources'      => $quick['sources'] ?? [],
                ];
                $remaining -= $tagged_tokens;
            }
            
            // Limit: max 2 additional characters to avoid context bloat
            if ( count( $result['parts'] ) >= 2 ) {
                break;
            }
        }
        
        // Log if any matches found
        if ( ! empty( $result['parts'] ) && class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'intent_tag_routing',
                'message'          => mb_substr( $query, 0, 120, 'UTF-8' ),
                'mode'             => 'tag_match',
                'matched_char_ids' => $matched_ids,
                'total_parts'      => count( $result['parts'] ),
            ] );
        }
        
        return $result;
    }
    
    /**
     * Process images for context using Vision API
     * 
     * @param array $images Array of image URLs or base64 data
     * @param string $query User query for context
     * @param array $config Configuration
     * @return array Vision processing result
     */
    private function process_images_for_context($images, $query, $config) {
        $descriptions = [];
        $errors = [];
        
        // Limit number of images
        $images = array_slice($images, 0, $config['vision_max_images']);
        
        foreach ($images as $image) {
            $result = $this->describe_image($image, $query, $config);
            
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $descriptions[] = $result;
            }
        }
        
        return [
            'descriptions' => $descriptions,
            'errors' => $errors
        ];
    }
    
    /**
     * Describe an image using Vision API
     * 
     * @param string|array $image Image URL, base64, or attachment data
     * @param string $context Optional context for description
     * @param array $config Configuration
     * @return string|WP_Error Image description or error
     */
    public function describe_image($image, $context = '', $config = []) {
        $config = array_merge($this->config, $config);
        
        // Prepare image data
        $image_data = $this->prepare_image_data($image, $config);
        
        if (is_wp_error($image_data)) {
            return $image_data;
        }
        
        // Build prompt for vision
        $prompt = "Hãy mô tả chi tiết nội dung của hình ảnh này.";
        
        if (!empty($context)) {
            $prompt .= " Người dùng đang hỏi về: \"{$context}\". Hãy tập trung vào các thông tin liên quan đến câu hỏi này.";
        }
        
        $prompt .= "\n\nMô tả bằng tiếng Việt, bao gồm:\n";
        $prompt .= "- Nội dung chính của hình ảnh\n";
        $prompt .= "- Văn bản/chữ nếu có\n";
        $prompt .= "- Bảng biểu, số liệu nếu có\n";
        $prompt .= "- Biểu đồ/đồ thị nếu có, giải thích ý nghĩa\n";
        
        // Choose Vision provider
        $provider = $config['vision_provider'] ?? 'openai';
        
        if ($provider === 'openai') {
            return $this->call_openai_vision($image_data, $prompt, $config);
        } else {
            return $this->call_openrouter_vision($image_data, $prompt, $config);
        }
    }
    
    /**
     * Prepare image data for Vision API
     * 
     * @param string|array $image Image input
     * @param array $config Configuration
     * @return array|WP_Error Prepared image data
     */
    private function prepare_image_data($image, $config) {
        // If array with attachment_id
        if (is_array($image) && !empty($image['attachment_id'])) {
            $url = wp_get_attachment_url($image['attachment_id']);
            if (!$url) {
                return new WP_Error('invalid_attachment', 'Could not get attachment URL');
            }
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $url,
                    'detail' => $config['vision_detail']
                ]
            ];
        }
        
        // If array with url
        if (is_array($image) && !empty($image['url'])) {
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image['url'],
                    'detail' => $config['vision_detail']
                ]
            ];
        }
        
        // If array with base64
        if (is_array($image) && !empty($image['base64'])) {
            $mime = $image['mime'] ?? 'image/jpeg';
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mime};base64,{$image['base64']}",
                    'detail' => $config['vision_detail']
                ]
            ];
        }
        
        // If string URL
        if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image,
                    'detail' => $config['vision_detail']
                ]
            ];
        }
        
        // If string base64
        if (is_string($image) && preg_match('/^data:image\//', $image)) {
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image,
                    'detail' => $config['vision_detail']
                ]
            ];
        }
        
        return new WP_Error('invalid_image', 'Invalid image format');
    }
    
    /**
     * Call OpenAI Vision API
     * 
     * @param array $image_data Prepared image data
     * @param string $prompt Vision prompt
     * @param array $config Configuration
     * @return string|WP_Error Description or error
     */
    private function call_openai_vision($image_data, $prompt, $config) {
        $api_key = get_option('twf_openai_api_key', '');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }
        
        $model = $config['vision_model'] ?? 'gpt-4o-mini';
        
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    $image_data
                ]
            ]
        ];
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1000
            ]),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        $error_msg = $body['error']['message'] ?? 'Vision API error';
        return new WP_Error('vision_error', $error_msg);
    }
    
    /**
     * Call OpenRouter Vision API
     * 
     * @param array $image_data Prepared image data
     * @param string $prompt Vision prompt
     * @param array $config Configuration
     * @return string|WP_Error Description or error
     */
    private function call_openrouter_vision($image_data, $prompt, $config) {
        $api_key = get_option('bizcity_knowledge_openrouter_api_key', '');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenRouter API key not configured');
        }
        
        $model = $config['vision_model'] ?? 'openai/gpt-4o-mini';
        
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    $image_data
                ]
            ]
        ];
        
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => home_url(),
                'X-Title' => get_bloginfo('name'),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1000
            ]),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }
        
        $error_msg = $body['error']['message'] ?? 'Vision API error';
        return new WP_Error('vision_error', $error_msg);
    }
    
    /**
     * Build messages array for chat with vision support
     * 
     * @param object $character Character object
     * @param string $user_message User message
     * @param array $history Chat history
     * @param array $images Optional images
     * @return array Messages array for API
     */
    public function build_chat_messages($character, $user_message, $history = [], $images = []) {
        $character_id = $character->id ?? 0;
        
        // Build context with vision if images provided
        $context_result = $this->build_context($character_id, $user_message, [
            'images' => $images
        ]);
        
        $messages = [];
        
        // System prompt with knowledge context
        $system_content = '';
        
        if (!empty($character->system_prompt)) {
            $system_content = $character->system_prompt;
        }
        
        if (!empty($context_result['context'])) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $context_result['context'];
            $system_content .= "\n\n---\n\nHãy sử dụng kiến thức trên để trả lời câu hỏi của người dùng một cách chính xác. Nếu thông tin không có trong kiến thức, hãy trả lời dựa trên hiểu biết chung của bạn và ghi chú rằng thông tin này không từ nguồn kiến thức được cung cấp.";
        }
        
        if (!empty($system_content)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_content
            ];
        }
        
        // Add history (last 10 messages)
        foreach (array_slice($history, -10) as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
        
        // Current message with images (if not already processed to context)
        if (!empty($images) && $this->model_supports_vision($character->model_id ?? '')) {
            // Send images directly to vision-capable model
            $content = [
                ['type' => 'text', 'text' => $user_message]
            ];
            
            foreach ($images as $image) {
                $image_data = $this->prepare_image_data($image, $this->config);
                if (!is_wp_error($image_data)) {
                    $content[] = $image_data;
                }
            }
            
            $messages[] = [
                'role' => 'user',
                'content' => $content
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $user_message
            ];
        }
        
        return [
            'messages' => $messages,
            'context_result' => $context_result
        ];
    }
    
    /**
     * Check if model supports vision
     * 
     * @param string $model_id Model ID
     * @return bool Whether model supports vision
     */
    public function model_supports_vision($model_id) {
        if (empty($model_id)) {
            // Default OpenAI model
            return in_array('gpt-4o-mini', self::VISION_MODELS['openai']);
        }
        
        // Check OpenRouter models
        foreach (self::VISION_MODELS['openrouter'] as $vision_model) {
            if (strpos($model_id, $vision_model) !== false || $model_id === $vision_model) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Estimate tokens for text
     * 
     * @param string $text Text to estimate
     * @return int Estimated tokens
     */
    private function estimate_tokens($text) {
        // Rough estimation: ~4 characters per token for English, ~2 for Vietnamese
        $char_count = mb_strlen($text);
        return (int) ceil($char_count / 3);
    }
    
    // ==================== ACTION HOOKS ====================
    
    /**
     * Action hook: Build context
     * 
     * Usage in workflow:
     * do_action('bizcity_knowledge_build_context', $character_id, $query, function($result) {
     *     // Use $result['context']
     * });
     * 
     * @param int $character_id Character ID
     * @param string $query User query
     * @param callable $callback Callback to receive result
     */
    public function action_build_context($character_id, $query, $callback = null) {
        $result = $this->build_context($character_id, $query);
        
        if (is_callable($callback)) {
            call_user_func($callback, $result);
        }
        
        return $result;
    }
    
    /**
     * Action hook: Process vision
     * 
     * @param array $images Images to process
     * @param callable $callback Callback to receive result
     */
    public function action_process_vision($images, $callback = null) {
        $result = $this->process_images_for_context($images, '', $this->config);
        
        if (is_callable($callback)) {
            call_user_func($callback, $result);
        }
        
        return $result;
    }
    
    // ==================== FILTER HOOKS ====================
    
    /**
     * Filter hook: Modify context config
     * 
     * @param array $config Current config
     * @param int $character_id Character ID
     * @return array Modified config
     */
    public function filter_context_config($config, $character_id) {
        // Allow external modification of config per character
        return $config;
    }
    
    /**
     * Filter hook: Modify context parts
     * 
     * @param array $parts Context parts
     * @param int $character_id Character ID
     * @param string $query User query
     * @return array Modified parts
     */
    public function filter_context_parts($parts, $character_id, $query) {
        // Allow external addition/modification of context parts
        return $parts;
    }
    
    // ==================== REST API ENDPOINTS ====================
    
    /**
     * REST API: Build context
     * 
     * POST /wp-json/bizcity-knowledge/v1/context/build
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function rest_build_context($request) {
        $character_id = $request->get_param('character_id');
        $query = $request->get_param('query');
        $options = $request->get_param('options') ?? [];
        
        if (!$character_id || !$query) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing character_id or query'
            ], 400);
        }
        
        $result = $this->build_context($character_id, $query, $options);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $result
        ]);
    }
    
    /**
     * REST API: Describe image
     * 
     * POST /wp-json/bizcity-knowledge/v1/vision/describe
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function rest_describe_image($request) {
        $image = $request->get_param('image');
        $context = $request->get_param('context') ?? '';
        
        if (!$image) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing image'
            ], 400);
        }
        
        $result = $this->describe_image($image, $context);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message()
            ], 500);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'description' => $result
        ]);
    }

    /* ================================================================
     *  KNOWLEDGE FABRIC — Multi-Scope Context (v3.0.0)
     * ================================================================ */

    /**
     * Build knowledge context from multiple scopes with priority merge.
     *
     * This is the new entry point that replaces single-character build_context()
     * for user-facing conversations. It searches across 4 scopes:
     *
     *   session (most specific) > project > user > agent (broadest)
     *
     * @param array  $scope_params {
     *     @type int    $user_id       Required — primary owner
     *     @type int    $character_id  Agent character (scope=agent)
     *     @type int    $project_id    Active project (optional)
     *     @type string $session_id    Active session (optional)
     * }
     * @param string $query    User query for semantic search
     * @param array  $options  Config overrides, images, etc.
     * @return array  Same shape as build_context(): { context, parts, sources, tokens_used, metadata }
     */
    public function build_multi_scope_context( $scope_params, $query, $options = array() ) {
        $build_start = microtime( true );

        $scope_params = wp_parse_args( $scope_params, array(
            'user_id'      => 0,
            'character_id' => 0,
            'project_id'   => null,
            'session_id'   => '',
        ) );

        $config = array_merge( $this->config, isset( $options['config'] ) ? $options['config'] : array() );
        $config = apply_filters( 'bizcity_knowledge_context_config', $config, $scope_params['character_id'] );

        $max_tokens  = $config['max_tokens'];
        $total_tokens = 0;
        $context_parts = array();

        $result = array(
            'context'              => '',
            'parts'                => array(),
            'sources'              => array(),
            'tokens_used'          => 0,
            'vision_descriptions'  => array(),
            'metadata'             => array(
                'scope_params' => $scope_params,
                'query'        => $query,
                'timestamp'    => current_time( 'mysql' ),
                'multi_scope'  => true,
            ),
        );

        // ── Part 0: Vision (same as build_context) ──
        if ( ! empty( $options['images'] ) && $config['vision_enabled'] ) {
            $vision_result = $this->process_images_for_context( $options['images'], $query, $config );
            if ( ! empty( $vision_result['descriptions'] ) ) {
                $vision_text   = "### Nội dung từ hình ảnh:\n" . implode( "\n\n", $vision_result['descriptions'] );
                $vision_tokens = $this->estimate_tokens( $vision_text );
                if ( $vision_tokens <= $max_tokens * 0.3 ) {
                    $context_parts[] = array(
                        'type'    => 'vision',
                        'content' => $vision_text,
                        'tokens'  => $vision_tokens,
                    );
                    $total_tokens += $vision_tokens;
                }
                $result['vision_descriptions'] = $vision_result['descriptions'];
            }
        }

        if ( ! class_exists( 'BizCity_Knowledge_Fabric' ) ) {
            // Fallback to old single-scope if Fabric not available
            return $this->build_context( (int) $scope_params['character_id'], $query, $options );
        }

        $fabric = BizCity_Knowledge_Fabric::instance();

        // ── Scope priority: session > project > user > agent ──
        $scopes_config = array();

        if ( ! empty( $scope_params['session_id'] ) ) {
            $scopes_config[] = array(
                'scope'       => 'session',
                'label'       => '💬 Phiên hiện tại',
                'budget_pct'  => 0.20,
                'session_id'  => $scope_params['session_id'],
            );
        }

        if ( ! empty( $scope_params['project_id'] ) ) {
            $scopes_config[] = array(
                'scope'      => 'project',
                'label'      => '📁 Dự án',
                'budget_pct' => 0.25,
                'project_id' => (int) $scope_params['project_id'],
                'user_id'    => (int) $scope_params['user_id'],
            );
        }

        if ( ! empty( $scope_params['user_id'] ) ) {
            $scopes_config[] = array(
                'scope'      => 'user',
                'label'      => '👤 Kiến thức cá nhân',
                'budget_pct' => 0.25,
                'user_id'    => (int) $scope_params['user_id'],
            );
        }

        // Agent scope gets remaining budget
        if ( ! empty( $scope_params['character_id'] ) ) {
            $scopes_config[] = array(
                'scope'        => 'agent',
                'label'        => '🤖 Chuyên gia',
                'budget_pct'   => 1.0, // Gets whatever remains
                'character_id' => (int) $scope_params['character_id'],
            );
        }

        // Search each scope
        foreach ( $scopes_config as $sc ) {
            $remaining     = $max_tokens - $total_tokens;
            $scope_budget  = ( $sc['scope'] === 'agent' )
                ? $remaining
                : (int) ceil( $remaining * $sc['budget_pct'] );

            if ( $scope_budget < 200 ) {
                continue;
            }

            if ( $sc['scope'] === 'agent' ) {
                // Agent scope: use existing semantic + quick knowledge methods
                $agent_parts = $this->build_agent_scope_context( (int) $sc['character_id'], $query, $scope_budget, $config );
                foreach ( $agent_parts as $part ) {
                    $context_parts[] = $part;
                    $total_tokens   += $part['tokens'];
                    if ( ! empty( $part['sources'] ) ) {
                        $result['sources'] = array_merge( $result['sources'], $part['sources'] );
                    }
                }
            } else {
                // Fabric scopes: use multi-scope search
                $scope_ids = array();
                foreach ( array( 'user_id', 'project_id', 'session_id', 'character_id' ) as $key ) {
                    if ( isset( $sc[ $key ] ) ) {
                        $scope_ids[ $key ] = $sc[ $key ];
                    }
                }

                $search_results = $fabric->search_multi_scope( $query, array_merge( $scope_ids, array(
                    'max_results' => 5,
                ) ) );

                // Filter to this scope only (search_multi_scope may include others)
                $scope_results = array();
                foreach ( $search_results as $sr ) {
                    if ( isset( $sr['scope'] ) && $sr['scope'] === $sc['scope'] ) {
                        $scope_results[] = $sr;
                    }
                }

                if ( ! empty( $scope_results ) ) {
                    $chunks_text = array();
                    $scope_tokens = 0;

                    foreach ( $scope_results as $sr ) {
                        $chunk_text   = $sr['content'];
                        $chunk_tokens = $this->estimate_tokens( $chunk_text );

                        if ( $scope_tokens + $chunk_tokens > $scope_budget ) {
                            break;
                        }

                        $src_name = ! empty( $sr['source_name'] ) ? $sr['source_name'] : 'Document';
                        $score    = isset( $sr['score'] ) ? round( $sr['score'] * 100 ) : 0;
                        $chunks_text[] = "[{$src_name} ({$score}%)]\n{$chunk_text}";
                        $scope_tokens += $chunk_tokens;
                    }

                    if ( ! empty( $chunks_text ) ) {
                        $part_content = "### {$sc['label']}:\n" . implode( "\n\n---\n\n", $chunks_text );
                        $context_parts[] = array(
                            'type'    => 'fabric_' . $sc['scope'],
                            'content' => $part_content,
                            'tokens'  => $scope_tokens,
                            'scope'   => $sc['scope'],
                            'sources' => array(),
                        );
                        $total_tokens += $scope_tokens;
                    }
                }
            }
        }

        // Filter for custom parts
        $context_parts = apply_filters( 'bizcity_knowledge_context_parts', $context_parts, $scope_params['character_id'], $query );

        // Build final context string
        $context_strings = array_map( function( $part ) {
            return $part['content'];
        }, $context_parts );

        $result['context']     = implode( "\n\n", $context_strings );
        $result['parts']       = $context_parts;
        $result['tokens_used'] = $total_tokens;

        // Log
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $part_types = array_column( $context_parts, 'type' );
            BizCity_User_Memory::log_router_event( array(
                'step'         => 'multi_scope_context',
                'message'      => mb_substr( $query, 0, 120, 'UTF-8' ),
                'mode'         => 'knowledge_fabric',
                'user_id'      => $scope_params['user_id'],
                'character_id' => $scope_params['character_id'],
                'project_id'   => $scope_params['project_id'],
                'session_id'   => mb_substr( $scope_params['session_id'], 0, 40, 'UTF-8' ),
                'parts_found'  => $part_types,
                'tokens_used'  => $total_tokens,
                'max_tokens'   => $max_tokens,
                'build_ms'     => round( ( microtime( true ) - $build_start ) * 1000, 2 ),
            ) );
        }

        return $result;
    }

    /**
     * Build context for agent scope (wraps existing quick_knowledge + semantic methods).
     *
     * @param int    $character_id
     * @param string $query
     * @param int    $budget_tokens
     * @param array  $config
     * @return array  Array of context parts
     */
    private function build_agent_scope_context( $character_id, $query, $budget_tokens, $config ) {
        $parts         = array();
        $total_used    = 0;

        // Quick knowledge
        $quick = $this->build_quick_knowledge_context( $character_id, $config );
        if ( ! empty( $quick['content'] ) && $quick['tokens'] <= $budget_tokens * 0.5 ) {
            $parts[] = array(
                'type'    => 'agent_quick',
                'content' => $quick['content'],
                'tokens'  => $quick['tokens'],
                'sources' => $quick['sources'],
            );
            $total_used += $quick['tokens'];
        }

        // Semantic search
        $remaining = $budget_tokens - $total_used;
        if ( $remaining > 300 ) {
            $db = BizCity_Knowledge_Database::instance();
            $has_chunks = $db->count_chunks( $character_id ) > 0;
            if ( $has_chunks ) {
                $semantic = $this->build_semantic_context( $character_id, $query, $remaining, $config );
                if ( ! empty( $semantic['content'] ) ) {
                    $parts[] = array(
                        'type'    => 'agent_semantic',
                        'content' => $semantic['content'],
                        'tokens'  => $semantic['tokens'],
                        'sources' => $semantic['sources'],
                    );
                }
            }
        }

        return $parts;
    }
}
