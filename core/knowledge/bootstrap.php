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
    define('BIZCITY_KNOWLEDGE_VERSION', '2.0.7');
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
// Skip if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
    return;
}

// [2026-08-29 Johnny Chu] R-METADATA-CACHE — use the shared helper for early knowledge loads instead of a module-local polyfill.
if ( ! class_exists( 'BizCity_Table_Metadata', false ) ) {
    $_kg_safe_loader = dirname( __DIR__ ) . '/helper/class-bizcity-safe-loader.php';
    if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $_kg_safe_loader ) && is_readable( $_kg_safe_loader ) ) {
        require_once $_kg_safe_loader;
    }
    unset( $_kg_safe_loader );
}
if ( class_exists( 'BizCity_Safe_Loader', false ) && ! class_exists( 'BizCity_Table_Metadata', false ) ) {
    BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/helper/class-bizcity-table-metadata.php', 'helper.table_metadata.early' );
}

// [2026-06-11 Johnny Chu] R-PERF — Admin/REST/AJAX context gate (same pattern as bizcity-twin-ai.php)
// Frontend HTML renders (GET /chat/, GET /app/) do NOT need admin menus, REST controllers,
// or chat gateway — those only fire during wp_ajax_* or /wp-json/ requests.
// [2026-06-29 Johnny Chu] HOTFIX — fallback gate extended to cover ALL webhook URL patterns.
// Facebook webhook is /?fbhook=1 or /bizfbhook/ — NOT /wp-json/. Without these patterns,
// $_kg_admin_ctx=false → class-chat-gateway.php NOT loaded → system_prompt + quick_faq
// never injected. This fix also covers the case when mu-plugin loads this file before
// $_bizcity_admin_ctx is set in bizcity-twin-ai.php.
$_kg_admin_ctx = isset( $_bizcity_admin_ctx )
    ? $_bizcity_admin_ctx
    : (
        is_admin()
        || ( defined( 'DOING_CRON' ) && DOING_CRON )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || (
            ! empty( $_SERVER['REQUEST_URI'] )
            && (
                false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' )
                // [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-12 R10 — /bizhook/ (retired Zalo Hotline webhook) removed.
                || false !== strpos( $_SERVER['REQUEST_URI'], '/zalohook/' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/bizfbhook' )
                || false !== strpos( $_SERVER['REQUEST_URI'], '/tool-' )
                || preg_match( '#^/doc/?(\?|$)#', $_SERVER['REQUEST_URI'] )
            )
        )
        || (
            ! empty( $_SERVER['QUERY_STRING'] )
            && (
                false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fbhook=1' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'biz_fb_oauth' )
                || false !== strpos( (string) $_SERVER['QUERY_STRING'], 'fb_callback=1' )
            )
        )
    );

// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14c / R-VERTICAL-AXIS R-VA-6 (CUT) — the five services/ classes (auth, chat history,
// session, project, chat send; 55 KB loaded on every request) served only the path C REST APIs below, which were off
// unless BIZCITY_LEGACY_PATH_C. All seven files, plus the hook-less admin chat, are in core/_archived/knowledge-path-c/.

// Load includes
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-database.php';

// Force migration early for metadata column fix
if (is_admin()) {
    add_action('admin_init', function() {
        BizCity_Knowledge_Database::maybe_create_tables();
    }, 1);
}

require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-character.php';
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-knowledge-source.php';
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — class-user-memory.php moved to its real owner,
// core/memory/includes/ (same class name, same tables); core/memory/bootstrap.php now requires + instantiates it.
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 (R-LEAN-4, R-GURU-SOURCE) — class-profile-context.php,
// class-agent-binding.php, class-intent-provider.php, lib/class-intent-parser.php, lib/class-context-api.php and
// lib/class-chat-api.php moved to the add-on (bizcity-twin-brain-addon/knowledge-legacy/); loaded + instantiated
// below through BizCity_Addon_Locator, at the same points in this file as before.
$_bizcity_knowledge_legacy = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'knowledge-legacy/bootstrap.php' ) : '';
if ( '' !== $_bizcity_knowledge_legacy ) {
    require_once $_bizcity_knowledge_legacy;
}
unset( $_bizcity_knowledge_legacy );

// [2026-06-11 Johnny Chu] R-PERF — Admin-only (~277 KB): admin menu + admin chat
if ( is_admin() ) {
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-admin-menu.php';
}

// [2026-06-11 Johnny Chu] R-PERF — REST/AJAX-only (~247 KB): chat gateway + REST controllers
// All wp_ajax_nopriv_* handlers go through admin-ajax.php (is_admin()=true) → gating is safe.
if ( $_kg_admin_ctx ) {
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-api.php';
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-character-quick-edit-rest.php';
    // [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-09 T5a–T5c — Guru editor REST (R1–R9) for /twinkg/.
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-guru-service.php';
    require_once BIZCITY_KNOWLEDGE_INCLUDES . 'class-guru-admin-rest.php';
    // [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — class-chat-gateway.php moved to the add-on; same
    // R-PERF gate as before, the locator only resolves where the file is.
    $_bizcity_chat_gateway_file = class_exists( 'BizCity_Addon_Locator', false ) ? BizCity_Addon_Locator::file( 'knowledge-legacy/includes/class-chat-gateway.php' ) : '';
    if ( '' !== $_bizcity_chat_gateway_file ) {
        require_once $_bizcity_chat_gateway_file;
    }
    unset( $_bizcity_chat_gateway_file );
}
// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14d / R-VERTICAL-AXIS R-VA-6 (CUT, owner 2026-09-30) — Phase 4.5 "Companion Intelligence" is off: emotional memory,
// thread tracker, companion context and response texture are in core/_archived/knowledge-companion/. Their writer hooks
// (bizcity_chat_after_response, bizcity_session_start/end) had no emitter left, so they only replayed frozen signals
// into TwinBrain prompts. Existing rows stay in the memory store; BizCity_User_Memory hides their types from prompts.
require_once BIZCITY_KNOWLEDGE_INCLUDES . 'functions.php';

// Load lib
require_once BIZCITY_KNOWLEDGE_LIB . 'class-content-importer.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-file-processor.php';
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — class-embedding.php and class-file-parser.php
// moved to core/kg-hub/includes/ (same class names); core/kg-hub/bootstrap.php now requires them directly instead
// of relying on core/knowledge having loaded them first.
require_once BIZCITY_KNOWLEDGE_LIB . 'class-web-crawler.php';
require_once BIZCITY_KNOWLEDGE_LIB . 'class-knowledge-fabric.php'; // Knowledge Fabric v3.0 — unified multi-scope pipeline

// Phase 0.3 — Knowledge Graph Hub (KG-Hub)
// 2026-05-01 — restored after OneDrive merge conflict dropped this require.
// Without this, BizCity_KG_Rest_Controller never registers and the REST
// routes `bizcity-knowledge/v2/notebooks` / `/graph` return 404.
// [2026-09-24 Claude Opus 5.5] CORE-REDUCTION WP-11 Part A — KG-Hub now lives at core/kg-hub/ (a peer of
// core/knowledge). Same gate as before: it still loads here, only the path changed. The legacy location is
// tried second so a partially deployed tree keeps working; a missing KG-Hub is logged, never silent.
$_bizcity_kg_hub_bootstrap = '';
foreach ( array( dirname( BIZCITY_KNOWLEDGE_DIR ) . '/kg-hub/bootstrap.php', BIZCITY_KNOWLEDGE_DIR . 'kg-hub/bootstrap.php' ) as $_bizcity_kg_hub_candidate ) {
    if ( is_file( $_bizcity_kg_hub_candidate ) ) {
        $_bizcity_kg_hub_bootstrap = $_bizcity_kg_hub_candidate;
        break;
    }
}
if ( '' === $_bizcity_kg_hub_bootstrap ) {
    error_log( '[bizcity] kg_hub_bootstrap_missing: core/kg-hub/bootstrap.php not found — KG-Hub routes will 404' );
} elseif ( class_exists( 'BizCity_Safe_Loader', false ) ) {
    // R-SAFE-LOADER: no unguarded fallback — the Safe Loader is loaded by core/helper before any module bootstrap.
    BizCity_Safe_Loader::require_file( $_bizcity_kg_hub_bootstrap, 'knowledge.kg_hub.bootstrap' );
} else {
    error_log( '[bizcity] kg_hub_bootstrap_skipped: BizCity_Safe_Loader unavailable' );
}
unset( $_bizcity_kg_hub_bootstrap, $_bizcity_kg_hub_candidate );

// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 — User Memory instance() moved to core/memory/bootstrap.php.
// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — Context API / Agent Binding instance() calls and the
// Knowledge Fabric Intent Provider registration moved into bizcity-twin-brain-addon/knowledge-legacy/bootstrap.php
// (called earlier in this file, right after class-character.php/class-knowledge-source.php); their constructors
// only register WordPress hooks (no synchronous KG-Hub use), so running them before KG-Hub loads is safe.

// WP-Cron: Cleanup expired session knowledge (every 6 hours)
add_action( 'bizcity_knowledge_fabric_cleanup', function() {
    if ( class_exists( 'BizCity_Knowledge_Fabric' ) ) {
        BizCity_Knowledge_Fabric::instance()->cleanup_expired_sessions( 24 );
    }
} );
if ( ! wp_next_scheduled( 'bizcity_knowledge_fabric_cleanup' ) ) {
    wp_schedule_event( time(), 'twicedaily', 'bizcity_knowledge_fabric_cleanup' );
}

// Initialize Chat Gateway, REST controllers (gated — only loaded in admin/REST/AJAX context)
// [2026-06-11 Johnny Chu] R-PERF — class_exists guards match the require_once gates above
if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
    BizCity_Chat_Gateway::instance();
}
// [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14c / R-VERTICAL-AXIS R-VA-6 (CUT) — REST bizcity-chat/v1 and bizcity-agent/v1 (path C,
// flag-only since WP-11 C2) are archived; BIZCITY_LEGACY_PATH_C no longer brings them back.

// Initialize Admin Menu (admin context only)
if ( is_admin() ) {
    if ( class_exists( 'BizCity_Knowledge_Admin_Menu' ) ) BizCity_Knowledge_Admin_Menu::instance();
}

// Initialize REST API
if ( class_exists( 'BizCity_Knowledge_API' ) ) {
    BizCity_Knowledge_API::instance();
}

// ── Phase 5.2 — Legal AI Module ───────────────────────────────────────────
// 2026-05-21 — REMOVED. Module `core/knowledge/legal/` đã bị xoá khỏi codebase
// (crawler/chunker/graph-builder/REST/character/...). Cron events cũ vẫn được
// unschedule idempotent dưới đây để không còn tick rỗng trên các site đã từng
// bật `BIZCITY_LEGAL_ENABLED`.
add_action( 'init', function() {
    foreach ( [ 'bizcity_legal_crawl_batch', 'bizcity_legal_discover', 'bizcity_legal_graph_batch' ] as $hook ) {
        $ts = wp_next_scheduled( $hook );
        while ( $ts ) {
            wp_unschedule_event( $ts, $hook );
            $ts = wp_next_scheduled( $hook );
        }
    }
}, 4 );

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

    /**
     * [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — same fix as
     * BizCity_Knowledge_Admin_Menu::can_manage() (class-admin-menu.php), applied here for this
     * class's own ajax_* handlers (see docs/audits/FRAMEWORK-CONTRACT-AUDIT-2026-07-30.md).
     */
    private static function can_manage(): bool {
        return class_exists( 'BizCity_Network_Admin_Capability' )
            ? BizCity_Network_Admin_Capability::can_manage()
            : current_user_can( 'manage_options' );
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
        
        // [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14f — the import_url / process_file / sync_fanpage AJAX handlers had no caller (live code or dist bundles); removed.
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
        // [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — BizCity_Intent_Parser moved to the add-on; this
        // method is unreachable anyway (bizcity_webchat_process_message is never applied), but guard it in case
        // that changes before it is archived in K-4.
        if ( ! class_exists( 'BizCity_Intent_Parser' ) ) {
            return $response;
        }
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

        // [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — BizCity_Intent_Parser moved to the add-on; this
        // method is unreachable anyway (bizcity_automation_actions is never applied), guarded for the same reason.
        if ( ! class_exists( 'BizCity_Intent_Parser' ) ) {
            return [ 'intent' => '', 'variables' => [], 'confidence' => 0 ];
        }
        $parser = BizCity_Intent_Parser::instance();
        $result = $parser->parse($text, $character, $knowledge);
        
        return [
            'intent' => $result['intent'],
            'variables' => $result['variables'],
            'confidence' => $result['confidence'],
        ];
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
