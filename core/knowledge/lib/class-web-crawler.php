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
     * Minimum useful content length (words) — below this we try the next tier.
     */
    const MIN_USEFUL_WORDS = 50;

    /**
     * Crawl a single webpage with a 3-tier waterfall.
     *
     * Tier 1 — Direct HTTP + DOM extraction (free, ~1s)
     * Tier 2 — Jina Reader (free, renders JS, ~3-5s)
     * Tier 3 — Tavily Extract via BizCity Search Router (paid, robust for SPA / anti-bot, ~2s)
     *
     * Returns the first tier that yields >= MIN_USEFUL_WORDS words.
     * If all tiers fail, returns the best non-empty result; if everything
     * is empty, returns a WP_Error describing why each tier failed.
     */
    public static function crawl_single_page( $url ) {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'Invalid URL format' );
        }

        $attempts = [];
        $best     = null;

        // ── Tier 1: direct HTTP ─────────────────────────────────────────────
        $r1 = self::fetch_and_extract( $url );
        if ( is_wp_error( $r1 ) ) {
            $attempts[] = 'direct: ' . $r1->get_error_message();
        } else {
            $best = $r1;
            if ( $r1['word_count'] >= self::MIN_USEFUL_WORDS ) {
                return $r1;
            }
            $attempts[] = "direct: only {$r1['word_count']} words";
        }

        // ── Tier 2: Jina Reader ─────────────────────────────────────────────
        $r2 = self::fetch_via_jina( $url );
        if ( is_wp_error( $r2 ) ) {
            $attempts[] = 'jina: ' . $r2->get_error_message();
        } else {
            if ( ! $best || $r2['word_count'] > $best['word_count'] ) {
                $best = $r2;
            }
            if ( $r2['word_count'] >= self::MIN_USEFUL_WORDS ) {
                return $r2;
            }
            $attempts[] = "jina: only {$r2['word_count']} words";
        }

        // ── Tier 3: Tavily Extract (via BizCity Search Router) ──────────────
        $r3 = self::fetch_via_tavily( $url );
        if ( is_wp_error( $r3 ) ) {
            $attempts[] = 'tavily: ' . $r3->get_error_message();
        } else {
            if ( ! $best || $r3['word_count'] > $best['word_count'] ) {
                $best = $r3;
            }
            if ( $r3['word_count'] >= self::MIN_USEFUL_WORDS ) {
                return $r3;
            }
            $attempts[] = "tavily: only {$r3['word_count']} words";
        }

        // All tiers tried. Return best if it has anything; else aggregated error.
        if ( $best && $best['word_count'] > 0 ) {
            $best['attempts'] = $attempts;
            return $best;
        }

        return new WP_Error(
            'all_tiers_failed',
            'Tất cả 3 phương pháp crawl đều thất bại: ' . implode( ' | ', $attempts )
        );
    }

    /**
     * Tier 3 — Fetch via BizCity Search Router (Tavily Extract backend).
     * Best for JS-rendered pages, anti-bot sites, and login-walled content.
     */
    private static function fetch_via_tavily( $url ) {
        if ( ! class_exists( 'BizCity_Search_Client' ) ) {
            return new WP_Error( 'tavily_unavailable', 'BizCity_Search_Client class not loaded' );
        }

        $client = BizCity_Search_Client::instance();
        if ( ! $client->is_ready() ) {
            return new WP_Error( 'tavily_no_api_key', 'BizCity API key chưa cấu hình (Settings → BizCity)' );
        }

        $results = $client->extract( [ $url ] );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        if ( empty( $results ) || empty( $results[0]['raw_content'] ) ) {
            return new WP_Error( 'tavily_empty', 'Tavily extract returned no content' );
        }

        $row     = $results[0];
        $content = trim( (string) $row['raw_content'] );
        $title   = isset( $row['title'] ) ? (string) $row['title'] : '';

        // Normalise whitespace
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );

        return [
            'url'        => $url,
            'title'      => $title,
            'content'    => $content,
            'metadata'   => [ 'title' => $title, 'description' => '', 'url' => $url ],
            'word_count' => self::utf8_word_count( $content ),
            'source'     => 'tavily',
        ];
    }

    /**
     * Fetch URL directly and extract text from HTML.
     */
    private static function fetch_and_extract($url) {
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'                  => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language'         => 'vi-VN,vi;q=0.9,en;q=0.8',
                'Accept-Encoding'         => 'gzip, deflate',
                'DNT'                     => '1',
                'Connection'              => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
            'sslverify'  => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('empty_content', 'No content found');
        }

        $content  = self::extract_text_from_html($html);
        $metadata = self::extract_metadata($html, $url);

        return [
            'url'        => $url,
            'title'      => $metadata['title'],
            'content'    => $content,
            'metadata'   => $metadata,
            'word_count' => self::utf8_word_count($content),
            'source'     => 'direct',
        ];
    }

    /**
     * Fetch page via Jina Reader (https://r.jina.ai/{url}).
     * Returns plain-text / Markdown content suitable for chunking.
     */
    private static function fetch_via_jina($url) {
        // Để tránh PHP/cURL parse query string của target URL thành query string của
        // Jina URL (ví dụ ?ItemID=156802 bị tách ra khỏi path), ta must encode
        // các ký tự ?&#= trong phần query/fragment của target URL.
        $qpos = strpos( $url, '?' );
        if ( $qpos !== false ) {
            $base_part  = substr( $url, 0, $qpos );
            $query_part = substr( $url, $qpos ); // cả dấu ?
            $query_part = str_replace( [ '?', '&', '=', '#' ], [ '%3F', '%26', '%3D', '%23' ], $query_part );
            $jina_url = 'https://r.jina.ai/' . $base_part . $query_part;
        } else {
            $jina_url = 'https://r.jina.ai/' . $url;
        }

        $response = wp_remote_get($jina_url, [
            'timeout'    => 45,
            'user-agent' => 'Mozilla/5.0',
            'headers'    => [
                'Accept'          => 'text/plain, text/markdown, */*',
                'Accept-Language' => 'vi-VN,vi;q=0.9,en;q=0.8',
                'X-No-Cache'      => 'true',
            ],
            'sslverify'  => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return new WP_Error('jina_error', "Jina Reader returned HTTP {$http_code}");
        }

        $text = wp_remote_retrieve_body($response);
        if (empty($text)) {
            return new WP_Error('jina_empty', 'Jina Reader returned empty content');
        }

        // Jina returns Markdown — extract title from first # heading if present
        $title = '';
        if (preg_match('/^#\s+(.+)/m', $text, $m)) {
            $title = trim($m[1]);
        }

        // Strip the Jina header block (lines before the first blank line after metadata)
        // Jina format: "Title: ...\nURL Source: ...\nMarkdown Content:\n\n{content}"
        if (preg_match('/Markdown Content:\s*\n(.+)/si', $text, $body_match)) {
            $text = trim($body_match[1]);
        }

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Fix C (R-TB-HYDRATE follow-up, 2026-05-27) — strip WP chrome
        // (login/admin/logout links + login form labels) from Jina Markdown
        // before persisting. Previously rendered HTML of authenticated WP
        // pages was ingested verbatim → passages like
        // `[Đăng xuất](.../wp-login.php?action=logout&_wpnonce=...)` polluted
        // notebook context. See docs/twinbrain TWINBRAIN-TRACE-2026-05-27.
        $text = self::scrub_wp_chrome( $text );

        return [
            'url'        => $url,
            'title'      => $title,
            'content'    => $text,
            'metadata'   => ['title' => $title, 'description' => '', 'url' => $url],
            'word_count' => self::utf8_word_count($text),
            'source'     => 'jina',
        ];
    }
    
    /**
     * UTF-8 aware word/token counter.
     * PHP's str_word_count() only counts ASCII letters, so it returns 0 for
     * Vietnamese and other Unicode text. This splits on whitespace instead.
     */
    private static function utf8_word_count(string $text): int {
        $text = trim($text);
        if ($text === '') return 0;
        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
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

        // Fix C (2026-05-27) — strip WP login/admin chrome at the DOM level
        // before text extraction. Catches inline login forms / admin bar items
        // that survive when the source page is a logged-in WP render.
        self::strip_wp_chrome_dom( $dom );

        // Extract body text
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        
        $text = self::get_text_from_node($body);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Defense in depth: also scrub any residual WP-chrome lines that
        // slipped through DOM removal (e.g. plain-text "Đăng nhập | Đăng xuất"
        // strings that weren't inside login containers).
        $text = self::scrub_wp_chrome( $text );

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
     * Fix C (R-TB-HYDRATE follow-up, 2026-05-27).
     *
     * Remove WordPress chrome elements from a DOMDocument BEFORE text
     * extraction. Targets login forms, admin bar, logout links, and any
     * <a> pointing at wp-login.php / wp-admin / wp-signup / register.
     *
     * Conservative: only strips clearly admin/auth artefacts. Leaves all
     * post body / page content intact.
     */
    private static function strip_wp_chrome_dom( DOMDocument $dom ): void {
        $xpath = new DOMXPath( $dom );

        // 1) Login form + register/lost-password blocks (by id / class).
        $q = "//form[@id='loginform' or @id='registerform' or @id='lostpasswordform']"
           . " | //*[@id='login' or @id='wpadminbar' or @id='wp-admin-bar-root-default']"
           . " | //*[contains(concat(' ',normalize-space(@class),' '),' login ')"
           .   " or contains(concat(' ',normalize-space(@class),' '),' loginform ')"
           .   " or contains(concat(' ',normalize-space(@class),' '),' wp-login ')"
           .   " or contains(concat(' ',normalize-space(@class),' '),' admin-bar ')"
           .   " or contains(concat(' ',normalize-space(@class),' '),' adminbar ')]";
        $nodes = $xpath->query( $q );
        if ( $nodes ) {
            foreach ( iterator_to_array( $nodes ) as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        // 2) Any <a> whose href targets WP auth/admin endpoints.
        $links = $xpath->query(
            "//a[contains(@href,'wp-login.php')"
          . " or contains(@href,'/wp-admin/')"
          . " or contains(@href,'wp-signup.php')"
          . " or contains(@href,'action=logout')"
          . " or contains(@href,'action=lostpassword')"
          . " or contains(@href,'action=register')]"
        );
        if ( $links ) {
            foreach ( iterator_to_array( $links ) as $a ) {
                if ( $a->parentNode ) {
                    $a->parentNode->removeChild( $a );
                }
            }
        }
    }

    /**
     * Fix C (R-TB-HYDRATE follow-up, 2026-05-27).
     *
     * Scrub WordPress chrome from plain text / Markdown extracted by Jina
     * Reader or DOMDocument. Handles three patterns:
     *
     *   1. Markdown links pointing at wp-login.php / wp-admin / logout /
     *      lostpassword / register → dropped entirely.
     *   2. Lines that are *only* WP auth labels (Đăng nhập / Đăng xuất / Quên
     *      mật khẩu …) separated by | or • → dropped.
     *   3. Stray `wp-login.php?...` URLs floating in body text → dropped.
     *
     * Conservative — only acts on patterns that uniquely identify WP auth
     * chrome. Idempotent.
     */
    private static function scrub_wp_chrome( string $text ): string {
        if ( $text === '' ) return $text;

        // 1) Markdown links to WP auth/admin endpoints \u2014 remove the whole
        // `[label](url)` construct so dangling label fragments don't remain.
        $text = preg_replace(
            '#\[[^\]]*\]\([^)]*(?:wp-login\.php|/wp-admin/|wp-signup\.php|action=(?:logout|lostpassword|register))[^)]*\)#iu',
            '',
            $text
        );

        // 2) Stray bare URLs to those endpoints (after the link wrappers are
        // gone, Jina sometimes leaves trailing URLs).
        $text = preg_replace(
            '#https?://\S*?(?:wp-login\.php|/wp-admin/|wp-signup\.php)\S*#iu',
            '',
            $text
        );

        // 3) Per-line scrub: drop lines that are *only* auth/admin labels
        // glued together with separators. We require ALL non-separator tokens
        // on the line to be auth keywords \u2014 otherwise leave the line alone.
        $auth_keywords = [
            'đăng nhập', 'đăng xuất', 'đăng ký',
            'quên mật khẩu', 'log in', 'log out', 'login', 'logout',
            'sign in', 'sign out', 'sign up', 'register', 'lost password',
            'remember me', 'ghi nhớ', 'tài khoản của tôi',
            'my account', 'wp-admin', 'dashboard',
        ];
        $kw_re = '/^(?:' . implode( '|', array_map( 'preg_quote', $auth_keywords ) ) . ')$/iu';

        $lines = preg_split( "/\r\n|\n|\r/u", $text );
        $out   = [];
        foreach ( $lines as $line ) {
            $trim = trim( $line );
            if ( $trim === '' ) { $out[] = $line; continue; }

            // Split on common separators used by WP chrome menus
            // (pipe, bullet •, middle dot ·, em/en dash, slash, fullwidth comma/period).
            $tokens = preg_split( '/[|•·—–>\/\x{3001}\x{3002}]+/u', $trim, -1, PREG_SPLIT_NO_EMPTY );
            $tokens = array_filter( array_map( 'trim', $tokens ), 'strlen' );
            if ( ! $tokens ) { $out[] = $line; continue; }

            $all_auth = true;
            foreach ( $tokens as $tok ) {
                // Strip leading markdown punctuation (- * + 1.).
                $clean = preg_replace( '/^(?:[-*+]\s+|\d+\.\s+)/u', '', $tok );
                $clean = trim( $clean, " \t\xc2\xa0[](){}.,:;\"'" );
                if ( $clean === '' ) continue;
                if ( ! preg_match( $kw_re, $clean ) ) { $all_auth = false; break; }
            }
            if ( ! $all_auth ) { $out[] = $line; }
        }
        $text = implode( "\n", $out );

        // Collapse the blank gaps we may have just opened.
        $text = preg_replace( '/\n{3,}/u', "\n\n", $text );

        return trim( $text );
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
        
        try {
            self::crawl_recursive($url, $links, $visited, 0, $max_depth, $max_links);
            $unique_links = array_unique($links);
            
            // If we found very few or no links, try a different approach
            if (count($unique_links) <= 1) {
                $fallback_links = self::find_links_regex_fallback($url, $max_links);
                if (!is_wp_error($fallback_links) && count($fallback_links) > count($unique_links)) {
                    return $fallback_links;
                }
            }
            
            return $unique_links;
        } catch (Exception $e) {
            return new WP_Error('crawl_error', 'Failed to crawl sublinks: ' . $e->getMessage());
        }
    }
    
    /**
     * Fallback method to find links using regex when DOM parsing fails
     */
    private static function find_links_regex_fallback($url, $max_links = 50) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('empty_content', 'No HTML content found');
        }
        
        $links = [$url]; // Include the original URL
        $base_domain = parse_url($url, PHP_URL_HOST);
        
        // Use regex to find href attributes
        preg_match_all('/href=["\']([^"\'>]+)["\']/', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $href) {
                if (strpos($href, '#') === 0) continue; // Skip anchors
                if (strpos($href, 'javascript:') === 0) continue; // Skip JS
                if (strpos($href, 'mailto:') === 0) continue; // Skip emails
                
                $absolute_url = self::make_absolute_url($href, $url);
                $link_domain = parse_url($absolute_url, PHP_URL_HOST);
                
                if ($link_domain === $base_domain && !in_array($absolute_url, $links)) {
                    $links[] = $absolute_url;
                    if (count($links) >= $max_links) break;
                }
            }
        }
        
        return $links;
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
        
        // Fetch page with better headers
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'vi-VN,vi;q=0.9,en;q=0.8'
            ],
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
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
        $response = wp_remote_get($sitemap_url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('http_error', "HTTP {$response_code} when fetching sitemap: {$sitemap_url}");
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
