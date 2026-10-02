<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * REST API for Knowledge Module
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_API {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        $namespace = 'bizcity-knowledge/v1';
        
        // Characters
        register_rest_route($namespace, '/characters', [
            'methods' => 'GET',
            'callback' => [$this, 'get_characters'],
            'permission_callback' => [$this, 'check_api_permission'], // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2) — was __return_true.
        ]);
        
        register_rest_route($namespace, '/characters/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_character'],
            'permission_callback' => [$this, 'check_api_permission'], // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2) — was __return_true.
        ]);
        
        // Query character
        register_rest_route($namespace, '/characters/(?P<id>\d+)/query', [
            'methods' => 'POST',
            'callback' => [$this, 'query_character'],
            'permission_callback' => [$this, 'check_api_permission'], // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2) — was __return_true.
        ]);
        
        // Parse intent
        register_rest_route($namespace, '/characters/(?P<id>\d+)/parse-intent', [
            'methods' => 'POST',
            'callback' => [$this, 'parse_intent'],
            'permission_callback' => [$this, 'check_api_permission'],
        ]);
        
        // Knowledge sources
        register_rest_route($namespace, '/characters/(?P<id>\d+)/knowledge', [
            'methods' => 'GET',
            'callback' => [$this, 'get_knowledge'],
            'permission_callback' => [$this, 'check_api_permission'],
        ]);
        
        // Search knowledge
        register_rest_route($namespace, '/search', [
            'methods' => 'POST',
            'callback' => [$this, 'search_knowledge'],
            'permission_callback' => [$this, 'check_api_permission'], // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2) — was __return_true.
        ]);
    }
    
    /**
     * Permission check
     */
    public function check_api_permission($request) {
        // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2, R-GP-4) — every route in this namespace lists, reads, queries or
        // searches Guru data, so none is public. Allowed: a site administrator, or a caller whose X-API-Key EQUALS the stored
        // `bizcity_knowledge_api_key`. It used to pass ANY non-empty X-API-Key when no key was stored (`|| !empty($api_key)`), and the
        // four read/query/search routes were `__return_true`. No stored key now means administrators only — never "any key".
        // [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — Network Super Admin without a local admin role was 403'd here.
        $is_admin = class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::can_manage() : current_user_can('manage_options');
        if ( $is_admin ) {
            return true;
        }

        $api_key    = (string) $request->get_header('X-API-Key');
        $stored_key = get_option('bizcity_knowledge_api_key');
        $stored_key = is_string( $stored_key ) ? $stored_key : '';
        if ( '' !== $stored_key && '' !== $api_key && hash_equals( $stored_key, $api_key ) ) {
            return true;
        }

        $logged_in = is_user_logged_in();
        return new WP_Error(
            $logged_in ? 'kg_v1_forbidden' : 'kg_v1_auth_required',
            $logged_in ? 'Only site administrators or a valid API key can use the Knowledge v1 API.' : 'Authentication is required for the Knowledge v1 API.',
            [
                'status'    => $logged_in ? 403 : 401,
                'hint'      => 'Sign in as a site administrator, or send the configured key in the X-API-Key header.',
                'help_code' => $logged_in ? 'kg_v1_forbidden' : 'kg_v1_auth_required',
            ]
        );
    }
    
    /**
     * GET /characters
     */
    public function get_characters($request) {
        $db = BizCity_Knowledge_Database::instance();
        
        $args = [
            'status' => $request->get_param('status') ?: '',
            'limit' => min(100, (int) $request->get_param('limit') ?: 20),
            'offset' => (int) $request->get_param('offset') ?: 0,
        ];
        
        $characters = $db->get_characters($args);
        
        $result = [];
        foreach ($characters as $char) {
            $c = BizCity_Character::get($char->id);
            if ($c) {
                $result[] = $c->to_market_data();
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $result,
        ]);
    }
    
    /**
     * GET /characters/{id}
     */
    public function get_character($request) {
        $id = $request->get_param('id');
        $character = BizCity_Character::get($id);
        
        if (!$character) {
            return new WP_Error('not_found', 'Character not found', ['status' => 404]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $character->to_market_data(),
        ]);
    }
    
    /**
     * POST /characters/{id}/query
     */
    public function query_character($request) {
        $id = $request->get_param('id');
        $query = $request->get_param('query');
        $session_id = $request->get_param('session_id') ?: wp_generate_uuid4();
        
        if (empty($query)) {
            return new WP_Error('missing_query', 'Query is required', ['status' => 400]);
        }
        
        $result = BizCity_Character::query($id, $query, [
            'session_id' => $session_id,
        ]);
        
        return rest_ensure_response([
            'success' => true,
            'data' => $result,
            'session_id' => $session_id,
        ]);
    }
    
    /**
     * POST /characters/{id}/parse-intent
     */
    public function parse_intent($request) {
        $id = $request->get_param('id');
        $text = $request->get_param('text');
        
        if (empty($text)) {
            return new WP_Error('missing_text', 'Text is required', ['status' => 400]);
        }
        
        $character = BizCity_Character::get($id);
        if (!$character) {
            return new WP_Error('not_found', 'Character not found', ['status' => 404]);
        }
        
        $knowledge = BizCity_Knowledge_Source::get_knowledge_for_character($id);
        // [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — BizCity_Intent_Parser moved to the add-on.
        if ( ! class_exists( 'BizCity_Intent_Parser' ) ) {
            return new WP_Error( 'intent_parser_unavailable', 'Cần add-on BizCity Twin Brain (bizcity-twin-brain-addon).', [ 'status' => 503 ] );
        }
        $parser = BizCity_Intent_Parser::instance();

        $result = $parser->parse($text, $character, $knowledge);
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'intent' => $result['intent'],
                'variables' => $result['variables'],
                'confidence' => $result['confidence'],
            ],
        ]);
    }
    
    /**
     * GET /characters/{id}/knowledge
     */
    public function get_knowledge($request) {
        $id = $request->get_param('id');
        $db = BizCity_Knowledge_Database::instance();
        
        $sources = $db->get_knowledge_sources($id);
        
        $result = [];
        foreach ($sources as $src) {
            $result[] = [
                'id' => $src->id,
                'type' => $src->source_type,
                'name' => $src->source_name,
                'url' => $src->source_url,
                'chunks' => $src->chunks_count,
                'status' => $src->status,
                'synced_at' => $src->last_synced_at,
            ];
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $result,
        ]);
    }
    
    /**
     * POST /search
     */
    public function search_knowledge($request) {
        $character_id = $request->get_param('character_id');
        $query = $request->get_param('query');
        $limit = min(20, (int) $request->get_param('limit') ?: 5);
        
        if (empty($query)) {
            return new WP_Error('missing_query', 'Query is required', ['status' => 400]);
        }
        
        $results = BizCity_Knowledge_Source::search($character_id, $query, $limit);
        
        $data = [];
        foreach ($results as $r) {
            $data[] = [
                'id' => $r->id,
                'content' => $r->content,
                'source_id' => $r->source_id,
            ];
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $data,
        ]);
    }
}
