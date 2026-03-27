<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Knowledge Source Model — Manages sources (FAQ, files, URLs, fanpages)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *

     * Get all knowledge for a character
     */
defined('ABSPATH') or die('OOPS...');
   
class BizCity_Knowledge_Source {
    
    public $id;
    public $character_id;
    public $source_type;
    public $source_name;
    public $source_url;
    public $attachment_id;
    public $post_id;
    public $content;
    public $chunks_count;
    public $status;
    public $last_synced_at;
    
    
    public static function get_knowledge_for_character($character_id) {
        $db = BizCity_Knowledge_Database::instance();
        
        // Get all chunks
        $chunks = $db->get_knowledge_chunks($character_id, 500);
        
        $knowledge = [];
        foreach ($chunks as $chunk) {
            $knowledge[] = $chunk->content;
        }
        
        // Also get quick_faq posts
        $sources = $db->get_knowledge_sources($character_id, 'ready');
        foreach ($sources as $source) {
            if ($source->source_type === 'quick_faq' && $source->post_id) {
                $post = get_post($source->post_id);
                if ($post) {
                    $knowledge[] = $post->post_title . ': ' . $post->post_content;
                }
            }
        }
        
        return $knowledge;
    }
    
    /**
     * Add Quick FAQ as knowledge source
     */
    public static function add_quick_faq($character_id, $post_id) {
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'quick_faq') {
            return new WP_Error('invalid_post', 'Invalid quick_faq post');
        }
        
        $db = BizCity_Knowledge_Database::instance();
        
        // Create source
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'quick_faq',
            'source_name' => $post->post_title,
            'post_id' => $post_id,
            'content' => $post->post_content,
            'content_hash' => md5($post->post_content),
            'status' => 'ready',
            'last_synced_at' => current_time('mysql'),
        ]);
        
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        
        // Create chunk
        $db->create_chunk([
            'source_id' => $source_id,
            'character_id' => $character_id,
            'chunk_index' => 0,
            'content' => $post->post_title . "\n\n" . $post->post_content,
            'token_count' => self::count_tokens($post->post_content),
            'metadata' => [
                'post_id' => $post_id,
                'type' => 'quick_faq',
            ],
        ]);
        
        // Update chunks count
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['chunks_count' => 1],
            ['id' => $source_id]
        );
        
        return $source_id;
    }
    
    /**
     * Add file as knowledge source
     */
    public static function add_file($character_id, $attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('file_not_found', 'File not found');
        }
        
        $db = BizCity_Knowledge_Database::instance();
        $processor = BizCity_File_Processor::instance();
        
        // Get file info
        $file_name = basename($file_path);
        $mime_type = get_post_mime_type($attachment_id);
        
        // Create source first
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'file',
            'source_name' => $file_name,
            'attachment_id' => $attachment_id,
            'status' => 'processing',
        ]);
        
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        
        // Process file based on type
        $content = $processor->extract_content($file_path, $mime_type);
        
        if (is_wp_error($content)) {
            $db->update_source($source_id, [
                'status' => 'error',
                'error_message' => $content->get_error_message(),
            ]);
            return $content;
        }
        
        // Chunk content
        $chunks = self::chunk_content($content);
        
        // Save chunks
        foreach ($chunks as $index => $chunk_content) {
            $db->create_chunk([
                'source_id' => $source_id,
                'character_id' => $character_id,
                'chunk_index' => $index,
                'content' => $chunk_content,
                'token_count' => self::count_tokens($chunk_content),
                'metadata' => [
                    'attachment_id' => $attachment_id,
                    'file_name' => $file_name,
                ],
            ]);
        }
        
        // Update source
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [
                'content' => $content,
                'content_hash' => md5($content),
                'chunks_count' => count($chunks),
                'status' => 'ready',
                'last_synced_at' => current_time('mysql'),
            ],
            ['id' => $source_id]
        );
        
        return $source_id;
    }
    
    /**
     * Add URL as knowledge source
     */
    public static function add_url($character_id, $url, $scrape_type = 'simple_html') {
        $db = BizCity_Knowledge_Database::instance();
        $importer = BizCity_Content_Importer::instance();
        
        // Create source
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'url',
            'source_name' => parse_url($url, PHP_URL_HOST),
            'source_url' => $url,
            'status' => 'processing',
            'settings' => json_encode(['scrape_type' => $scrape_type]),
        ]);
        
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        
        // Fetch and process URL
        $content = $importer->fetch_url_content($url, $scrape_type);
        
        if (is_wp_error($content)) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'bizcity_knowledge_sources',
                [
                    'status' => 'error',
                    'error_message' => $content->get_error_message(),
                ],
                ['id' => $source_id]
            );
            return $content;
        }
        
        // Chunk content
        $chunks = self::chunk_content($content);
        
        // Save chunks
        foreach ($chunks as $index => $chunk_content) {
            $db->create_chunk([
                'source_id' => $source_id,
                'character_id' => $character_id,
                'chunk_index' => $index,
                'content' => $chunk_content,
                'token_count' => self::count_tokens($chunk_content),
                'metadata' => [
                    'url' => $url,
                ],
            ]);
        }
        
        // Update source
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            [
                'content' => $content,
                'content_hash' => md5($content),
                'chunks_count' => count($chunks),
                'status' => 'ready',
                'last_synced_at' => current_time('mysql'),
            ],
            ['id' => $source_id]
        );
        
        return $source_id;
    }
    
    /**
     * Chunk content into smaller pieces
     */
    public static function chunk_content($content, $max_tokens = 500) {
        $chunks = [];
        $paragraphs = preg_split('/\n\n+/', $content);
        
        $current_chunk = '';
        $current_tokens = 0;
        
        foreach ($paragraphs as $para) {
            $para_tokens = self::count_tokens($para);
            
            if ($current_tokens + $para_tokens > $max_tokens && !empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                $current_chunk = '';
                $current_tokens = 0;
            }
            
            $current_chunk .= $para . "\n\n";
            $current_tokens += $para_tokens;
        }
        
        if (!empty($current_chunk)) {
            $chunks[] = trim($current_chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Estimate token count (simple approximation)
     */
    public static function count_tokens($text) {
        // Rough estimation: 4 chars per token for English, 2 for Vietnamese
        return max(1, (int) ceil(mb_strlen($text) / 3));
    }
    
    /**
     * Search knowledge by query
     */
    public static function search($character_id, $query, $limit = 5) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        
        // Simple keyword search (can be enhanced with vector search later)
        $keywords = array_filter(explode(' ', mb_strtolower($query)));
        
        if (empty($keywords)) {
            return [];
        }
        
        $where_parts = [];
        foreach ($keywords as $kw) {
            $where_parts[] = $wpdb->prepare("content LIKE %s", '%' . $wpdb->esc_like($kw) . '%');
        }
        
        $where_sql = implode(' OR ', $where_parts);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE character_id = %d AND ({$where_sql})
             ORDER BY id DESC LIMIT %d",
            $character_id,
            $limit
        ));
    }
}
