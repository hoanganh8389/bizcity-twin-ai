<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Knowledge Module — AI Assistants & Knowledge Management
 * Module Kiến thức — Các trợ lý AI & Quản lý Kiến thức
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @version    2.0.0
 * @since      2026-02-08
 */

defined('ABSPATH') or die('OOPS...');

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_KNOWLEDGE_DIR' ) ) {
    define('BIZCITY_KNOWLEDGE_DIR', __DIR__ . '/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_VERSION' ) ) {
    define('BIZCITY_KNOWLEDGE_VERSION', '2.0.0');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_INCLUDES' ) ) {
    define('BIZCITY_KNOWLEDGE_INCLUDES', BIZCITY_KNOWLEDGE_DIR . 'includes/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_LIB' ) ) {
    define('BIZCITY_KNOWLEDGE_LIB', BIZCITY_KNOWLEDGE_DIR . 'lib/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_ASSETS' ) ) {
    define('BIZCITY_KNOWLEDGE_ASSETS', BIZCITY_KNOWLEDGE_DIR . 'assets/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_VIEWS' ) ) {
    define('BIZCITY_KNOWLEDGE_VIEWS', BIZCITY_KNOWLEDGE_DIR . 'views/');
}
if ( ! defined( 'BIZCITY_KNOWLEDGE_TEMPLATES' ) ) {
    define('BIZCITY_KNOWLEDGE_TEMPLATES', BIZCITY_KNOWLEDGE_DIR . 'templates/');
}

// Load shared services (used by both admin-ajax and REST API)
if ( ! defined( 'BIZCITY_KNOWLEDGE_SERVICES' ) ) {
    define( 'BIZCITY_KNOWLEDGE_SERVICES', BIZCITY_KNOWLEDGE_DIR . 'services/' );
}
// Skip if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
    return;
}
require_once BIZCITY_KNOWLEDGE_SERVICES . 'class-auth-service.php';
require_once BIZCITY_KNOWLEDGE_SERVICES . 'class-chat-history-service.php';
require_once BIZCITY_KNOWLEDGE_SERVICES . 'class-session-service.php';
require_once BIZCITY_KNOWLEDGE_SERVICES . 'class-project-service.php';
require_once BIZCITY_KNOWLEDGE_SERVICES . 'class-chat-send-service.php';

// Load includes
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-database.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-character.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-knowledge-source.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-admin-menu.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-api.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-profile-context.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-admin-chat.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-chat-gateway.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-user-memory.php';
// Phase 4.5 — Companion Intelligence (§12)
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-emotional-memory.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-emotional-thread-tracker.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-companion-context.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-response-texture-engine.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-agent-binding.php';
// Phase 5.0 — Chat REST API (AJAX→REST migration)
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-chat-rest-api.php';
// Phase 5.1 — Agent REST API (full API for React SPA / mobile)
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-agent-rest-api.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'functions.php';
// Knowledge Fabric Intent Provider — loaded conditionally after Intent Engine boots
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-intent-provider.php';
}

// Load lib
require_once BIZCITY_KNOWLEDGE_LIB . 'class-intent-parser.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-content-importer.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-file-processor.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-embedding.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-file-parser.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-context-api.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-web-crawler.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-chat-api.php'; // Chat with knowledge
require_once BIZCITY_KNOWLEDGE_LIB . 'class-knowledge-fabric.php'; // Knowledge Fabric v3.0 — unified multi-scope pipeline

// Initialize Context API (for hooks)
BizCity_Knowledge_Context_API::instance();

// Initialize User Memory
BizCity_User_Memory::instance();

// Initialize Phase 4.5 — Companion Intelligence (§12)
BizCity_Emotional_Memory::instance();
BizCity_Emotional_Thread_Tracker::instance();
BizCity_Companion_Context::instance();
BizCity_Response_Texture_Engine::instance();

// Initialize Agent Binding
BizCity_Knowledge_Agent_Binding::instance();

// Register Knowledge Fabric Intent Provider with Intent Engine
if ( class_exists( 'BizCity_Knowledge_Intent_Provider' ) ) {
    add_action( 'bizcity_intent_register_providers', function( $registry ) {
        $registry->register( new BizCity_Knowledge_Intent_Provider() );
    } );
}

// WP-Cron: Cleanup expired session knowledge (every 6 hours)
add_action( 'bizcity_knowledge_fabric_cleanup', function() {
    if ( class_exists( 'BizCity_Knowledge_Fabric' ) ) {
        BizCity_Knowledge_Fabric::instance()->cleanup_expired_sessions( 24 );
    }
} );
if ( ! wp_next_scheduled( 'bizcity_knowledge_fabric_cleanup' ) ) {
    wp_schedule_event( time(), 'twicedaily', 'bizcity_knowledge_fabric_cleanup' );
}

// Initialize Chat Gateway (unified endpoint — works for both admin and public)
BizCity_Chat_Gateway::instance();

// Initialize Chat REST API (AJAX→REST migration layer)
BizCity_Chat_REST_API::instance();

// Initialize Agent REST API (full API for React SPA / mobile)
BizCity_Agent_REST_API::instance();

// Initialize Admin Menu
if (is_admin()) {
    BizCity_Knowledge_Admin_Menu::instance();
    BizCity_Admin_Chat::instance();
}

// Initialize REST API
BizCity_Knowledge_API::instance();

/**
 * Main Plugin Class
 */
class BizCity_Knowledge {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Register triggers for bizcity-automation
        add_action('init', [$this, 'register_automation_triggers']);
        
        // Hook into webchat for character processing
        add_filter('bizcity_webchat_process_message', [$this, 'process_with_character'], 10, 3);
        
        // Integration with bizcity-agent-market
        add_filter('bcam_get_agent_data', [$this, 'get_character_for_market'], 10, 2);
        
        // AJAX handlers for knowledge operations
        add_action('wp_ajax_bizcity_knowledge_import_url', [$this, 'ajax_import_url']);
        add_action('wp_ajax_bizcity_knowledge_process_file', [$this, 'ajax_process_file']);
        add_action('wp_ajax_bizcity_knowledge_sync_fanpage', [$this, 'ajax_sync_fanpage']);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        BizCity_Knowledge_Database::instance()->create_tables();
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Register triggers/actions for bizcity-automation workflow
     */
    public function register_automation_triggers() {
        // Trigger: AI Character Response
        add_filter('bizcity_automation_triggers', function($triggers) {
            $triggers['character_response'] = [
                'name' => 'AI Character Response',
                'description' => 'Trigger khi AI character trả lời',
                'group' => 'AI Knowledge',
                'outputs' => [
                    'response' => ['type' => 'string', 'description' => 'Câu trả lời của character'],
                    'intent' => ['type' => 'string', 'description' => 'Intent được phân tích'],
                    'variables' => ['type' => 'object', 'description' => 'Các biến được trích xuất'],
                    'confidence' => ['type' => 'number', 'description' => 'Độ tin cậy của phân tích'],
                ],
            ];
            
            $triggers['knowledge_query'] = [
                'name' => 'Knowledge Query',
                'description' => 'Trigger khi có query đến knowledge base',
                'group' => 'AI Knowledge',
                'outputs' => [
                    'query' => ['type' => 'string'],
                    'matched_knowledge' => ['type' => 'array'],
                    'character_id' => ['type' => 'number'],
                ],
            ];
            
            return $triggers;
        });
        
        // Action: Query Character Knowledge
        add_filter('bizcity_automation_actions', function($actions) {
            $actions['query_character'] = [
                'name' => 'Query AI Character',
                'description' => 'Gửi query đến AI character để xử lý',
                'group' => 'AI Knowledge',
                'inputs' => [
                    'character_id' => ['type' => 'number', 'required' => true],
                    'query' => ['type' => 'string', 'required' => true],
                    'context' => ['type' => 'object', 'required' => false],
                ],
                'outputs' => [
                    'response' => ['type' => 'string'],
                    'intent' => ['type' => 'string'],
                    'variables' => ['type' => 'object'],
                ],
                'callback' => [$this, 'action_query_character'],
            ];
            
            $actions['extract_intent'] = [
                'name' => 'Extract Intent & Variables',
                'description' => 'Phân tích intent và trích xuất variables từ prompt',
                'group' => 'AI Knowledge',
                'inputs' => [
                    'character_id' => ['type' => 'number', 'required' => true],
                    'text' => ['type' => 'string', 'required' => true],
                ],
                'outputs' => [
                    'intent' => ['type' => 'string'],
                    'variables' => ['type' => 'object'],
                    'confidence' => ['type' => 'number'],
                ],
                'callback' => [$this, 'action_extract_intent'],
            ];
            
            return $actions;
        });
    }
    
    /**
     * Process message with assigned character
     */
    public function process_with_character($response, $message, $context) {
        $character_id = $context['character_id'] ?? get_option('bizcity_knowledge_default_character');
        
        if (empty($character_id)) {
            return $response;
        }
        
        $character = BizCity_Character::get($character_id);
        if (!$character) {
            return $response;
        }
        
        // Get character's knowledge
        $knowledge = BizCity_Knowledge_Source::get_knowledge_for_character($character_id);
        
        // Parse intent
        $intent_parser = BizCity_Intent_Parser::instance();
        $parsed = $intent_parser->parse($message, $character, $knowledge);
        
        // Fire trigger for automation
        do_action('bizcity_knowledge_character_response', [
            'character_id' => $character_id,
            'message' => $message,
            'intent' => $parsed['intent'],
            'variables' => $parsed['variables'],
            'response' => $parsed['response'],
        ]);
        
        return $parsed['response'];
    }
    
    /**
     * Get character data for bizcity-agent-market
     */
    public function get_character_for_market($agent_data, $character_id) {
        $character = BizCity_Character::get($character_id);
        
        if (!$character) {
            return $agent_data;
        }
        
        return array_merge($agent_data, [
            'name' => $character->name,
            'avatar' => $character->avatar,
            'description' => $character->description,
            'capabilities' => $character->capabilities,
            'industries' => $character->industries,
            'rating' => $character->get_rating(),
            'total_conversations' => $character->get_total_conversations(),
        ]);
    }
    
    /**
     * Action callback: Query character
     */
    public function action_query_character($inputs) {
        $character_id = $inputs['character_id'];
        $query = $inputs['query'];
        $context = $inputs['context'] ?? [];
        
        $result = BizCity_Character::query($character_id, $query, $context);
        
        return [
            'response' => $result['response'],
            'intent' => $result['intent'],
            'variables' => $result['variables'],
        ];
    }
    
    /**
     * Action callback: Extract intent
     */
    public function action_extract_intent($inputs) {
        $character_id = $inputs['character_id'];
        $text = $inputs['text'];
        
        $character = BizCity_Character::get($character_id);
        $knowledge = BizCity_Knowledge_Source::get_knowledge_for_character($character_id);
        
        $parser = BizCity_Intent_Parser::instance();
        $result = $parser->parse($text, $character, $knowledge);
        
        return [
            'intent' => $result['intent'],
            'variables' => $result['variables'],
            'confidence' => $result['confidence'],
        ];
    }
    
    /**
     * AJAX: Import content from URL
     */
    public function ajax_import_url() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $url = esc_url_raw($_POST['url'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $scrape_type = sanitize_text_field($_POST['scrape_type'] ?? 'simple_html');
        
        if (empty($url) || empty($character_id)) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }
        
        $importer = BizCity_Content_Importer::instance();
        $result = $importer->import_from_url($url, $character_id, $scrape_type);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Process uploaded file (CSV, Excel, PDF, JSON)
     */
    public function ajax_process_file() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $character_id = intval($_POST['character_id'] ?? 0);
        
        if (empty($attachment_id) || empty($character_id)) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }
        
        $processor = BizCity_File_Processor::instance();
        $result = $processor->process_file($attachment_id, $character_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Sync fanpage content
     */
    public function ajax_sync_fanpage() {
        check_ajax_referer('bizcity_knowledge', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $fanpage_id = sanitize_text_field($_POST['fanpage_id'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        
        if (empty($fanpage_id) || empty($character_id)) {
            wp_send_json_error(['message' => 'Missing required parameters']);
        }
        
        $importer = BizCity_Content_Importer::instance();
        $result = $importer->sync_fanpage($fanpage_id, $character_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    BizCity_Knowledge::instance();
}, 5);

// Global helper function — guarded against redeclaration from legacy mu-plugin
if ( ! function_exists( 'bizcity_knowledge' ) ) {
    function bizcity_knowledge() {
        return BizCity_Knowledge::instance();
    }
}
