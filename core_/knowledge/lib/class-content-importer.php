<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Content Importer — Import nội dung từ website, fanpage làm kiến thức
 * Content Importer — Import content from websites, fanpages as knowledge
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Content_Importer {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Import content from URL
     */
    public function import_from_url($url, $character_id, $scrape_type = 'simple_html') {
        $content = $this->fetch_url_content($url, $scrape_type);
        
        if (is_wp_error($content)) {
            return $content;
        }
        
        // Save as knowledge source
        return BizCity_Knowledge_Source::add_url($character_id, $url, $scrape_type);
    }
    
    /**
     * Fetch URL content
     */
    public function fetch_url_content($url, $scrape_type = 'simple_html') {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid URL');
        }
        
        // Fetch page
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; BizCityBot/1.0; +https://bizcity.ai)',
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html)) {
            return new WP_Error('empty_response', 'Empty response from URL');
        }
        
        // Process based on scrape type
        switch ($scrape_type) {
            case 'simple_text':
                return $this->extract_text_only($html);
                
            case 'simple_html':
            default:
                return $this->extract_simple_html($html);
        }
    }
    
    /**
     * Extract text only (no HTML tags)
     */
    public function extract_text_only($html) {
        // Remove script and style
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/si', '', $html);
        
        // Remove HTML tags
        $text = strip_tags($html);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Extract simple HTML (keep important tags)
     */
    public function extract_simple_html($html) {
        // Try to find main content area
        $content = '';
        
        // Common content selectors
        $selectors = [
            '/<article[^>]*>(.*?)<\/article>/si',
            '/<main[^>]*>(.*?)<\/main>/si',
            '/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/si',
            '/<div[^>]*id="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/si',
        ];
        
        foreach ($selectors as $selector) {
            if (preg_match($selector, $html, $matches)) {
                $content = $matches[1];
                break;
            }
        }
        
        // If no content found, use body
        if (empty($content)) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
                $content = $matches[1];
            } else {
                $content = $html;
            }
        }
        
        // Remove unwanted elements
        $content = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $content);
        $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/si', '', $content);
        $content = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $content);
        $content = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $content);
        $content = preg_replace('/<header[^>]*>.*?<\/header>/si', '', $content);
        $content = preg_replace('/<aside[^>]*>.*?<\/aside>/si', '', $content);
        $content = preg_replace('/<form[^>]*>.*?<\/form>/si', '', $content);
        
        // Keep only allowed tags
        $allowed_tags = '<h1><h2><h3><h4><h5><h6><p><br><ul><ol><li><strong><em><b><i><a>';
        $content = strip_tags($content, $allowed_tags);
        
        // Clean up
        $content = preg_replace('/\s+/', ' ', $content);
        $content = str_replace(['> <', '><'], ['>', '>'], $content);
        
        // Convert to readable text with structure
        $content = preg_replace('/<h([1-6])[^>]*>/i', "\n\n## ", $content);
        $content = str_replace(['</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'], "\n", $content);
        $content = preg_replace('/<p[^>]*>/i', "\n", $content);
        $content = str_replace('</p>', "\n", $content);
        $content = preg_replace('/<li[^>]*>/i', "\n• ", $content);
        $content = str_replace('</li>', '', $content);
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        
        // Strip remaining tags
        $content = strip_tags($content);
        
        // Final cleanup
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        return trim($content);
    }
    
    /**
     * Sync content from Facebook Fanpage
     */
    public function sync_fanpage($fanpage_id, $character_id) {
        // Check if Facebook integration is available
        $access_token = get_option('bizcity_facebook_access_token');
        
        if (empty($access_token)) {
            return new WP_Error('no_token', 'Facebook access token not configured');
        }
        
        // Fetch posts from fanpage
        $url = "https://graph.facebook.com/v18.0/{$fanpage_id}/posts";
        $url .= "?fields=message,created_time,permalink_url";
        $url .= "&access_token={$access_token}";
        $url .= "&limit=50";
        
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('fb_error', $body['error']['message']);
        }
        
        $posts = $body['data'] ?? [];
        
        if (empty($posts)) {
            return new WP_Error('no_posts', 'No posts found on fanpage');
        }
        
        // Combine posts into knowledge
        $content = "Nội dung từ Facebook Fanpage:\n\n";
        
        foreach ($posts as $post) {
            if (!empty($post['message'])) {
                $content .= "---\n";
                $content .= $post['message'] . "\n";
                $content .= "Đăng ngày: " . $post['created_time'] . "\n\n";
            }
        }
        
        // Save as knowledge source
        $db = BizCity_Knowledge_Database::instance();
        
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'fanpage',
            'source_name' => 'Facebook Fanpage: ' . $fanpage_id,
            'source_url' => 'https://facebook.com/' . $fanpage_id,
            'content' => $content,
            'content_hash' => md5($content),
            'status' => 'ready',
            'last_synced_at' => current_time('mysql'),
            'settings' => json_encode(['fanpage_id' => $fanpage_id]),
        ]);
        
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        
        // Create chunks
        $chunks = BizCity_Knowledge_Source::chunk_content($content);
        
        foreach ($chunks as $index => $chunk_content) {
            $db->create_chunk([
                'source_id' => $source_id,
                'character_id' => $character_id,
                'chunk_index' => $index,
                'content' => $chunk_content,
                'token_count' => BizCity_Knowledge_Source::count_tokens($chunk_content),
                'metadata' => ['fanpage_id' => $fanpage_id],
            ]);
        }
        
        // Update chunks count
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['chunks_count' => count($chunks)],
            ['id' => $source_id]
        );
        
        return [
            'source_id' => $source_id,
            'posts_count' => count($posts),
            'chunks_count' => count($chunks),
        ];
    }
    
    /**
     * Crawl website (multiple pages)
     */
    public function crawl_website($start_url, $character_id, $max_depth = 2, $max_pages = 20) {
        $visited = [];
        $to_visit = [$start_url];
        $all_content = [];
        
        $base_host = parse_url($start_url, PHP_URL_HOST);
        
        while (!empty($to_visit) && count($visited) < $max_pages) {
            $url = array_shift($to_visit);
            
            if (in_array($url, $visited)) {
                continue;
            }
            
            // Check same domain
            if (parse_url($url, PHP_URL_HOST) !== $base_host) {
                continue;
            }
            
            $visited[] = $url;
            
            // Fetch content
            $content = $this->fetch_url_content($url, 'simple_text');
            
            if (!is_wp_error($content) && !empty($content)) {
                $all_content[] = [
                    'url' => $url,
                    'content' => $content,
                ];
            }
            
            // Find links on page (if depth allows)
            if (count($visited) < $max_pages && $max_depth > 0) {
                $links = $this->extract_links($url);
                foreach ($links as $link) {
                    if (!in_array($link, $visited) && !in_array($link, $to_visit)) {
                        $to_visit[] = $link;
                    }
                }
            }
        }
        
        if (empty($all_content)) {
            return new WP_Error('no_content', 'Could not crawl any content from website');
        }
        
        // Combine and save
        $combined = '';
        foreach ($all_content as $page) {
            $combined .= "## Source: {$page['url']}\n\n{$page['content']}\n\n---\n\n";
        }
        
        // Save to database
        $db = BizCity_Knowledge_Database::instance();
        
        $source_id = $db->create_knowledge_source([
            'character_id' => $character_id,
            'source_type' => 'url',
            'source_name' => 'Website Crawl: ' . $base_host,
            'source_url' => $start_url,
            'content' => $combined,
            'content_hash' => md5($combined),
            'status' => 'ready',
            'last_synced_at' => current_time('mysql'),
            'settings' => json_encode([
                'crawl_depth' => $max_depth,
                'pages_crawled' => count($all_content),
            ]),
        ]);
        
        // Create chunks
        $chunks = BizCity_Knowledge_Source::chunk_content($combined);
        
        foreach ($chunks as $index => $chunk_content) {
            $db->create_chunk([
                'source_id' => $source_id,
                'character_id' => $character_id,
                'chunk_index' => $index,
                'content' => $chunk_content,
                'token_count' => BizCity_Knowledge_Source::count_tokens($chunk_content),
            ]);
        }
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            ['chunks_count' => count($chunks)],
            ['id' => $source_id]
        );
        
        return [
            'source_id' => $source_id,
            'pages_crawled' => count($all_content),
            'chunks_count' => count($chunks),
        ];
    }
    
    /**
     * Extract links from page
     */
    private function extract_links($url) {
        $response = wp_remote_get($url, ['timeout' => 15]);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        $html = wp_remote_retrieve_body($response);
        $links = [];
        
        // Find all href links
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            $base = parse_url($url);
            $base_url = $base['scheme'] . '://' . $base['host'];
            
            foreach ($matches[1] as $href) {
                // Skip anchors, javascript, etc
                if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $href)) {
                    continue;
                }
                
                // Make absolute URL
                if (strpos($href, '//') === 0) {
                    $href = $base['scheme'] . ':' . $href;
                } elseif (strpos($href, '/') === 0) {
                    $href = $base_url . $href;
                } elseif (strpos($href, 'http') !== 0) {
                    $href = $base_url . '/' . $href;
                }
                
                // Clean URL
                $href = strtok($href, '#');
                $href = rtrim($href, '/');
                
                if (!in_array($href, $links)) {
                    $links[] = $href;
                }
            }
        }
        
        return array_slice($links, 0, 50);
    }
}
