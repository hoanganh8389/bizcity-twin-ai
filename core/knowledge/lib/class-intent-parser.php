<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Intent Parser — Phân tích intent từ tin nhắn người dùng
 * Intent Parser — Analyze user message intent & extract variables
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Intent_Parser {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Parse message to extract intent and variables
     */
    public function parse($message, $character, $knowledge = []) {
        $result = [
            'intent' => '',
            'variables' => [],
            'confidence' => 0,
            'relevant_knowledge' => [],
            'response' => '',
        ];
        
        // 1. Try to match configured intents
        $intent_match = $this->match_configured_intents($message, $character);
        
        if ($intent_match) {
            $result['intent'] = $intent_match['intent'];
            $result['confidence'] = $intent_match['confidence'];
        }
        
        // 2. Extract variables based on character's schema
        $result['variables'] = $this->extract_variables($message, $character);
        
        // 3. Find relevant knowledge
        $result['relevant_knowledge'] = $this->find_relevant_knowledge($message, $knowledge);
        
        // 4. If confidence is low, try AI-based intent detection
        if ($result['confidence'] < 0.5 && class_exists('BizCity_WebChat_AI')) {
            $ai_result = $this->ai_parse_intent($message, $character);
            
            if ($ai_result && $ai_result['confidence'] > $result['confidence']) {
                $result['intent'] = $ai_result['intent'];
                $result['confidence'] = $ai_result['confidence'];
                $result['variables'] = array_merge($result['variables'], $ai_result['variables']);
            }
        }
        
        return $result;
    }
    
    /**
     * Match against configured intents
     */
    private function match_configured_intents($message, $character) {
        $db = BizCity_Knowledge_Database::instance();
        $intents = $db->get_intents($character->id);
        
        if (empty($intents)) {
            return null;
        }
        
        $message_lower = mb_strtolower($message, 'UTF-8');
        $best_match = null;
        $best_score = 0;
        
        foreach ($intents as $intent) {
            $keywords = json_decode($intent->keywords, true) ?: [];
            $examples = json_decode($intent->examples, true) ?: [];
            
            $score = 0;
            $matches = 0;
            
            // Check keywords
            foreach ($keywords as $kw) {
                if (mb_strpos($message_lower, mb_strtolower($kw, 'UTF-8')) !== false) {
                    $matches++;
                    $score += 0.3;
                }
            }
            
            // Check example similarity
            foreach ($examples as $example) {
                $similarity = $this->calculate_similarity($message_lower, mb_strtolower($example, 'UTF-8'));
                if ($similarity > 0.6) {
                    $score += $similarity * 0.5;
                }
            }
            
            // Normalize score
            if (count($keywords) > 0) {
                $keyword_score = $matches / count($keywords);
                $score = max($score, $keyword_score);
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = [
                    'intent' => $intent->intent_name,
                    'confidence' => min(1, $score),
                    'intent_data' => $intent,
                ];
            }
        }
        
        return $best_match;
    }
    
    /**
     * Extract variables based on character's schema
     */
    private function extract_variables($message, $character) {
        $variables = [];
        
        // Get variable schema
        $schema = $character->variables_schema;
        if (is_string($schema)) {
            $schema = json_decode($schema, true) ?: [];
        }
        
        // Common patterns for extraction
        $patterns = [
            // Numbers
            'quantity' => '/(\d+)\s*(cái|chiếc|bộ|kg|g|lít|ml)/i',
            'phone' => '/(0\d{9,10})/',
            'email' => '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/',
            'price' => '/(\d+[\d,.]*)(\s*(đ|đồng|vnđ|k|vnd))/i',
            
            // Product info
            'size' => '/size\s*([SMLXL]{1,3}|\d+)/i',
            'color' => '/(màu|color)\s*([a-zA-Zàáảãạăắằẳẵặâấầẩẫậèéẻẽẹêếềểễệìíỉĩịòóỏõọôốồổỗộơớờởỡợùúủũụưứừửữựỳýỷỹỵđ]+)/i',
            
            // Date/Time
            'date' => '/(\d{1,2}[\/\-]\d{1,2}[\/\-]?\d{0,4})/',
            'time' => '/(\d{1,2}:\d{2})/',
        ];
        
        foreach ($schema as $var_name => $var_type) {
            // Check if we have a pattern for this variable
            if (isset($patterns[$var_name])) {
                if (preg_match($patterns[$var_name], $message, $matches)) {
                    $variables[$var_name] = $matches[1];
                }
            }
        }
        
        // Try to extract product name
        if (isset($schema['product_name']) || isset($schema['product'])) {
            $product = $this->extract_product_name($message);
            if ($product) {
                $variables['product_name'] = $product;
            }
        }
        
        // Cast types
        foreach ($variables as $key => $value) {
            $type = $schema[$key] ?? 'string';
            
            if ($type === 'number' || $type === 'integer') {
                $variables[$key] = intval(preg_replace('/[^\d]/', '', $value));
            } elseif ($type === 'float' || $type === 'double') {
                $variables[$key] = floatval(str_replace(',', '.', preg_replace('/[^\d,.]/', '', $value)));
            }
        }
        
        return $variables;
    }
    
    /**
     * Extract product name from message
     */
    private function extract_product_name($message) {
        // Common patterns for product queries
        $patterns = [
            '/(?:mua|tìm|cần|muốn|đặt|order)\s+(.+?)(?:\s+(?:không|ko|được|giá|bao nhiêu)|$)/i',
            '/(?:sản phẩm|sp)\s+(.+?)(?:\s+(?:không|ko|được|giá)|$)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Find relevant knowledge chunks
     */
    private function find_relevant_knowledge($message, $knowledge) {
        if (empty($knowledge)) {
            return [];
        }
        
        $relevant = [];
        $message_lower = mb_strtolower($message, 'UTF-8');
        $keywords = array_filter(explode(' ', $message_lower));
        
        foreach ($knowledge as $content) {
            $content_lower = mb_strtolower($content, 'UTF-8');
            $score = 0;
            
            foreach ($keywords as $kw) {
                if (mb_strlen($kw) < 2) continue;
                
                if (mb_strpos($content_lower, $kw) !== false) {
                    $score++;
                }
            }
            
            if ($score > 0) {
                $relevant[] = [
                    'content' => $content,
                    'score' => $score,
                ];
            }
        }
        
        // Sort by score
        usort($relevant, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top 5
        return array_column(array_slice($relevant, 0, 5), 'content');
    }
    
    /**
     * Calculate similarity between two strings
     */
    private function calculate_similarity($str1, $str2) {
        $words1 = array_filter(explode(' ', $str1));
        $words2 = array_filter(explode(' ', $str2));
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $common = array_intersect($words1, $words2);
        $total = array_unique(array_merge($words1, $words2));
        
        return count($common) / count($total);
    }
    
    /**
     * Use AI to parse intent (when keyword matching fails)
     */
    private function ai_parse_intent($message, $character) {
        $api_key = get_option('bizcity_knowledge_openai_key') ?: get_option('twf_openai_api_key');
        
        if (empty($api_key)) {
            return null;
        }
        
        // Build intents list
        $db = BizCity_Knowledge_Database::instance();
        $intents = $db->get_intents($character->id);
        
        $intent_list = [];
        foreach ($intents as $i) {
            $intent_list[] = $i->intent_name . ': ' . $i->intent_description;
        }
        
        if (empty($intent_list)) {
            $intent_list = ['greeting', 'question', 'order', 'support', 'other'];
        }
        
        // Build prompt
        $prompt = "Phân tích tin nhắn sau và xác định intent (ý định).\n\n";
        $prompt .= "Tin nhắn: \"{$message}\"\n\n";
        $prompt .= "Các intent có thể:\n" . implode("\n", $intent_list) . "\n\n";
        $prompt .= "Trả lời theo format JSON: {\"intent\": \"<intent_name>\", \"confidence\": <0-1>, \"variables\": {...}}\n";
        $prompt .= "Chỉ trả lời JSON, không giải thích.";
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 200,
                'temperature' => 0.3,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        
        // Parse JSON from response
        if (preg_match('/\{.+\}/s', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && isset($parsed['intent'])) {
                return [
                    'intent' => $parsed['intent'],
                    'confidence' => floatval($parsed['confidence'] ?? 0.7),
                    'variables' => $parsed['variables'] ?? [],
                ];
            }
        }
        
        return null;
    }
}
