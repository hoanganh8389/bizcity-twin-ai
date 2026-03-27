<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Character Model — AI Assistant profiles & configuration
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Character {
    
    public $id;
    public $name;
    public $slug;
    public $avatar;
    public $description;
    public $system_prompt;
    public $capabilities;
    public $industries;
    public $variables_schema;
    public $settings;
    public $status;
    public $author_id;
    public $market_id;
    public $total_conversations;
    public $total_messages;
    public $rating;
    public $created_at;
    public $updated_at;
    
    /**
     * Get character by ID
     */
    public static function get($id) {
        $db = BizCity_Knowledge_Database::instance();
        $row = $db->get_character($id);
        
        if (!$row) {
            return null;
        }
        
        return self::from_row($row);
    }
    
    /**
     * Get character by slug
     */
    public static function get_by_slug($slug) {
        $db = BizCity_Knowledge_Database::instance();
        $row = $db->get_character_by_slug($slug);
        
        if (!$row) {
            return null;
        }
        
        return self::from_row($row);
    }
    
    /**
     * Create character from DB row
     */
    private static function from_row($row) {
        $char = new self();
        
        foreach ($row as $key => $value) {
            if (property_exists($char, $key)) {
                // Decode JSON fields
                if (in_array($key, ['capabilities', 'industries', 'variables_schema', 'settings'])) {
                    $char->$key = json_decode($value, true) ?: [];
                } else {
                    $char->$key = $value;
                }
            }
        }
        
        return $char;
    }
    
    /**
     * Query character with a message
     */
    public static function query($character_id, $query, $context = []) {
        $character = self::get($character_id);
        
        if (!$character) {
            return [
                'response' => 'Character not found.',
                'intent' => '',
                'variables' => [],
            ];
        }
        
        // Get knowledge for context
        $knowledge = BizCity_Knowledge_Source::get_knowledge_for_character($character_id);
        
        // Parse intent and extract variables
        $parser = BizCity_Intent_Parser::instance();
        $parsed = $parser->parse($query, $character, $knowledge);
        
        // Generate response using AI
        $response = $character->generate_response($query, $parsed, $context);
        
        // Log conversation
        $character->log_conversation($context['session_id'] ?? '', $query, $response, $parsed);
        
        return [
            'response' => $response,
            'intent' => $parsed['intent'],
            'variables' => $parsed['variables'],
            'confidence' => $parsed['confidence'] ?? 0,
        ];
    }
    
    /**
     * Generate AI response
     */
    public function generate_response($query, $parsed, $context = []) {
        $api_key = get_option('bizcity_knowledge_openai_key') ?: get_option('twf_openai_api_key');
        
        if (empty($api_key)) {
            return $this->get_fallback_response($query, $parsed);
        }
        
        // Build messages
        $messages = [
            [
                'role' => 'system',
                'content' => $this->build_system_message($parsed),
            ],
        ];
        
        // Add conversation history if available
        if (!empty($context['history'])) {
            foreach ($context['history'] as $msg) {
                $messages[] = [
                    'role' => $msg['from'] === 'user' ? 'user' : 'assistant',
                    'content' => $msg['content'],
                ];
            }
        }
        
        // Add current query with context
        $user_message = $query;
        if (!empty($parsed['relevant_knowledge'])) {
            $user_message .= "\n\n[Relevant Knowledge]\n" . implode("\n", array_slice($parsed['relevant_knowledge'], 0, 3));
        }
        
        $messages[] = ['role' => 'user', 'content' => $user_message];
        
        // Call OpenAI
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => 1024,
                'temperature' => 0.7,
            ]),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) {
            return $this->get_fallback_response($query, $parsed);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['choices'][0]['message']['content'] ?? $this->get_fallback_response($query, $parsed);
    }
    
    /**
     * Build system message with character context
     */
    private function build_system_message($parsed) {
        $message = !empty($this->system_prompt) 
            ? $this->system_prompt 
            : "Bạn là {$this->name}. {$this->description}";
        
        // Add capabilities
        if (!empty($this->capabilities)) {
            $caps = is_array($this->capabilities) ? implode(', ', $this->capabilities) : $this->capabilities;
            $message .= "\n\nKhả năng của bạn: {$caps}";
        }
        
        // Add detected intent info
        if (!empty($parsed['intent'])) {
            $message .= "\n\n[Intent detected: {$parsed['intent']}]";
        }
        
        // Add output format instruction
        if (!empty($this->variables_schema)) {
            $message .= "\n\nKhi trả lời, hãy trích xuất thông tin theo format: " . json_encode($this->variables_schema);
        }
        
        return $message;
    }
    
    /**
     * Fallback response when AI unavailable
     */
    private function get_fallback_response($query, $parsed) {
        // Check if we have relevant knowledge
        if (!empty($parsed['relevant_knowledge'])) {
            return $parsed['relevant_knowledge'][0];
        }
        
        return "Xin lỗi, tôi chưa có thông tin về vấn đề này. Bạn có thể mô tả rõ hơn được không?";
    }
    
    /**
     * Log conversation
     */
    private function log_conversation($session_id, $query, $response, $parsed) {
        if (empty($session_id)) {
            return;
        }
        
        $db = BizCity_Knowledge_Database::instance();
        
        $db->log_conversation([
            'character_id' => $this->id,
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'platform' => 'webchat',
            'message_count' => 1,
            'last_intent' => $parsed['intent'] ?? '',
            'extracted_variables' => $parsed['variables'] ?? [],
            'status' => 'active',
        ]);
        
        // Update character stats
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bizcity_characters 
             SET total_conversations = total_conversations + 1, 
                 total_messages = total_messages + 2
             WHERE id = %d",
            $this->id
        ));
    }
    
    /**
     * Get rating
     */
    public function get_rating() {
        return number_format($this->rating ?: 0, 1);
    }
    
    /**
     * Get total conversations
     */
    public function get_total_conversations() {
        return $this->total_conversations ?: 0;
    }
    
    /**
     * Prepare for market listing
     */
    public function to_market_data() {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'avatar' => $this->avatar,
            'description' => $this->description,
            'capabilities' => $this->capabilities,
            'industries' => $this->industries,
            'rating' => $this->get_rating(),
            'total_conversations' => $this->get_total_conversations(),
            'status' => $this->status,
            'author_id' => $this->author_id,
        ];
    }
}
