<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Web Crawler — Crawl websites & extract content for knowledge base
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_Web_Crawler {
    
    /**
     * Crawl a single webpage
     */
    public static function crawl_single_page($url) {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid URL format');
        }
        
        // Fetch HTML content
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'BizCity Knowledge Bot/1.0'
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html)) {
            return new WP_Error('empty_content', 'No content found');
        }
        
        // Extract text content
        $content = self::extract_text_from_html($html);
        
        // Get metadata
        $metadata = self::extract_metadata($html, $url);
        
        return [
            'url' => $url,
            'title' => $metadata['title'],
            'content' => $content,
            'metadata' => $metadata,
            'word_count' => str_word_count($content)
        ];
    }
    
    /**
     * Extract clean text from HTML
     */
    private static function extract_text_from_html($html) {
        // Load HTML with DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        // Remove script, style, nav, footer
        $remove_tags = ['script', 'style', 'nav', 'footer', 'header', 'aside'];
        foreach ($remove_tags as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            while ($elements->length > 0) {
                $elements->item(0)->parentNode->removeChild($elements->item(0));
            }
        }
        
        // Extract body text
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        
        $text = self::get_text_from_node($body);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Recursively get text from DOM node
     */
    private static function get_text_from_node($node) {
        $text = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent . ' ';
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                if (in_array($child->nodeName, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'])) {
                    $text .= self::get_text_from_node($child) . "\n\n";
                } else {
                    $text .= self::get_text_from_node($child) . ' ';
                }
            }
        }
        
        return $text;
    }
    
    /**
     * Extract metadata from HTML
     */
    private static function extract_metadata($html, $url) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $metadata = [
            'title' => '',
            'description' => '',
            'author' => '',
            'published_date' => '',
            'url' => $url
        ];
        
        // Get title
        $title_tag = $dom->getElementsByTagName('title')->item(0);
        if ($title_tag) {
            $metadata['title'] = $title_tag->textContent;
        }
        
        // Get meta tags
        $meta_tags = $dom->getElementsByTagName('meta');
        foreach ($meta_tags as $meta) {
            $name = $meta->getAttribute('name') ?: $meta->getAttribute('property');
            $content = $meta->getAttribute('content');
            
            if (in_array($name, ['description', 'og:description'])) {
                $metadata['description'] = $content;
            } elseif (in_array($name, ['author', 'article:author'])) {
                $metadata['author'] = $content;
            } elseif (in_array($name, ['article:published_time', 'pubdate'])) {
                $metadata['published_date'] = $content;
            }
        }
        
        return $metadata;
    }
    
    /**
     * Find sublinks from a page
     */
    public static function find_sublinks($url, $max_depth = 1, $max_links = 50) {
        $links = [];
        $visited = [];
        
        self::crawl_recursive($url, $links, $visited, 0, $max_depth, $max_links);
        
        return array_unique($links);
    }
    
    /**
     * Recursive crawl for sublinks
     */
    private static function crawl_recursive($url, &$links, &$visited, $depth, $max_depth, $max_links) {
        if ($depth > $max_depth || count($links) >= $max_links) {
            return;
        }
        
        if (in_array($url, $visited)) {
            return;
        }
        
        $visited[] = $url;
        $links[] = $url;
        
        // Fetch page
        $response = wp_remote_get($url, ['timeout' => 15]);
        
        if (is_wp_error($response)) {
            return;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Parse for links
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $base_domain = parse_url($url, PHP_URL_HOST);
        $a_tags = $dom->getElementsByTagName('a');
        
        foreach ($a_tags as $a) {
            $href = $a->getAttribute('href');
            
            if (empty($href) || $href[0] === '#') {
                continue;
            }
            
            // Convert relative to absolute URL
            $absolute_url = self::make_absolute_url($href, $url);
            
            // Only crawl same domain
            if (parse_url($absolute_url, PHP_URL_HOST) === $base_domain) {
                if (!in_array($absolute_url, $visited) && count($links) < $max_links) {
                    self::crawl_recursive($absolute_url, $links, $visited, $depth + 1, $max_depth, $max_links);
                }
            }
        }
    }
    
    /**
     * Parse sitemap XML
     */
    public static function parse_sitemap($sitemap_url) {
        $response = wp_remote_get($sitemap_url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $xml = wp_remote_retrieve_body($response);
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();
        
        $urls = [];
        
        // Check if it's a sitemap index
        $sitemaps = $dom->getElementsByTagName('sitemap');
        if ($sitemaps->length > 0) {
            // It's a sitemap index, parse each sitemap
            foreach ($sitemaps as $sitemap) {
                $loc = $sitemap->getElementsByTagName('loc')->item(0);
                if ($loc) {
                    $sub_sitemap_url = $loc->textContent;
                    $sub_urls = self::parse_sitemap($sub_sitemap_url);
                    if (!is_wp_error($sub_urls)) {
                        $urls = array_merge($urls, $sub_urls);
                    }
                }
            }
        } else {
            // Regular sitemap
            $url_tags = $dom->getElementsByTagName('url');
            foreach ($url_tags as $url_tag) {
                $loc = $url_tag->getElementsByTagName('loc')->item(0);
                if ($loc) {
                    $urls[] = $loc->textContent;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Convert relative URL to absolute
     */
    private static function make_absolute_url($relative_url, $base_url) {
        // Already absolute
        if (parse_url($relative_url, PHP_URL_SCHEME) !== null) {
            return $relative_url;
        }
        
        $parsed_base = parse_url($base_url);
        
        // Protocol-relative URL
        if (substr($relative_url, 0, 2) === '//') {
            return $parsed_base['scheme'] . ':' . $relative_url;
        }
        
        // Root-relative URL
        if ($relative_url[0] === '/') {
            return $parsed_base['scheme'] . '://' . $parsed_base['host'] . $relative_url;
        }
        
        // Relative to current path
        $base_path = $parsed_base['path'] ?? '/';
        $base_path = substr($base_path, 0, strrpos($base_path, '/') + 1);
        
        return $parsed_base['scheme'] . '://' . $parsed_base['host'] . $base_path . $relative_url;
    }
    
    /**
     * Split content into chunks
     */
    public static function split_into_chunks($content, $chunk_size = 500, $overlap = 50) {
        $words = preg_split('/\s+/', $content);
        $chunks = [];
        $total_words = count($words);
        
        for ($i = 0; $i < $total_words; $i += ($chunk_size - $overlap)) {
            $chunk_words = array_slice($words, $i, $chunk_size);
            $chunk_text = implode(' ', $chunk_words);
            
            if (!empty(trim($chunk_text))) {
                $chunks[] = $chunk_text;
            }
        }
        
        return $chunks;
    }
}
