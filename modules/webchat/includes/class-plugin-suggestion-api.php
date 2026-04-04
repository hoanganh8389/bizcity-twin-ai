<?php
/**
 * Bizcity Twin AI — Plugin Suggestion API
 * API gợi ý plugin khi @ mention / REST & AJAX endpoints for @ mention suggestions
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Plugin_Suggestion_API {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'wp_ajax_bizcity_get_plugin_suggestions', [ $this, 'ajax_get_suggestions' ] );
        add_action( 'wp_ajax_nopriv_bizcity_get_plugin_suggestions', [ $this, 'ajax_get_suggestions' ] );
        add_action( 'wp_ajax_bizcity_get_plugin_context', [ $this, 'ajax_get_plugin_context' ] );
        add_action( 'wp_ajax_nopriv_bizcity_get_plugin_context', [ $this, 'ajax_get_plugin_context' ] );
        // Pre-Intent estimate — lightweight tool matching before send
        add_action( 'wp_ajax_bizcity_pre_intent_estimate', [ $this, 'ajax_pre_intent_estimate' ] );
        add_action( 'wp_ajax_nopriv_bizcity_pre_intent_estimate', [ $this, 'ajax_pre_intent_estimate' ] );
        // Slash command — tool-level search in bizcity_tool_registry
        add_action( 'wp_ajax_bizcity_search_tools', [ $this, 'ajax_search_tools' ] );
        add_action( 'wp_ajax_nopriv_bizcity_search_tools', [ $this, 'ajax_search_tools' ] );
        // Slash command — skill search in bizcity_skills DB + file system
        add_action( 'wp_ajax_bizcity_search_skills', [ $this, 'ajax_search_skills' ] );
        add_action( 'wp_ajax_nopriv_bizcity_search_skills', [ $this, 'ajax_search_skills' ] );
    }

    /* ================================================================
     * REST API Routes
     * ================================================================ */

    public function register_rest_routes() {
        register_rest_route( 'bizcity-webchat/v1', '/plugin-suggestions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_suggestions' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'query' => [
                    'description' => 'Search query for filtering plugins',
                    'type'        => 'string',
                    'default'     => '',
                ],
                'limit' => [
                    'description' => 'Maximum number of results',
                    'type'        => 'integer', 
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ],
            ],
        ] );

        register_rest_route( 'bizcity-webchat/v1', '/plugin-context/(?P<slug>[a-zA-Z0-9\-_]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_plugin_context' ],
            'permission_callback' => [ $this, 'check_plugin_permission' ],
            'args'                => [
                'slug' => [
                    'description' => 'Plugin slug or template page slug',
                    'type'        => 'string',
                    'required'    => true,
                ],
                'session_id' => [
                    'description' => 'Session ID to get related messages',
                    'type'        => 'string',
                    'default'     => '',
                ],
            ],
        ] );

        // Pre-Intent estimate — lightweight tool matching (REST, for mobile app)
        register_rest_route( 'bizcity-webchat/v1', '/pre-intent-estimate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'rest_pre_intent_estimate' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'message' => [
                    'description' => 'Draft message text to estimate plugin match',
                    'type'        => 'string',
                    'required'    => true,
                ],
            ],
        ] );

        // Slash command — tool-level search (/ command)
        register_rest_route( 'bizcity-webchat/v1', '/search-tools', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_search_tools' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'query' => [
                    'description' => 'Search keyword for tools',
                    'type'        => 'string',
                    'default'     => '',
                ],
                'plugin_slug' => [
                    'description' => 'Optional: scope to tools of a specific plugin',
                    'type'        => 'string',
                    'default'     => '',
                ],
                'limit' => [
                    'description' => 'Maximum number of results',
                    'type'        => 'integer',
                    'default'     => 5,
                    'minimum'     => 1,
                    'maximum'     => 20,
                ],
            ],
        ] );
    }

    /* ================================================================
     * REST Handlers
     * ================================================================ */

    public function rest_get_suggestions( WP_REST_Request $request ) {
        $query = sanitize_text_field( $request->get_param( 'query' ) );
        $limit = absint( $request->get_param( 'limit' ) );

        $suggestions = $this->get_plugin_suggestions( $query, $limit );

        return rest_ensure_response( $suggestions );
    }

    public function rest_get_plugin_context( WP_REST_Request $request ) {
        $slug = sanitize_key( $request->get_param( 'slug' ) );
        $session_id = sanitize_text_field( $request->get_param( 'session_id' ) );

        $context = $this->get_plugin_context( $slug, $session_id );

        return rest_ensure_response( $context );
    }

    /**
     * REST: Pre-Intent estimate for mobile app.
     */
    public function rest_pre_intent_estimate( WP_REST_Request $request ) {
        $message = sanitize_text_field( $request->get_param( 'message' ) );
        if ( mb_strlen( $message ) < 3 ) {
            return rest_ensure_response( array( 'suggestions' => array(), 'highlight' => '' ) );
        }

        return rest_ensure_response( $this->estimate_plugin_match( $message ) );
    }

    /* ================================================================
     * AJAX Handlers
     * ================================================================ */

    public function ajax_get_suggestions() {
        try {
            $nonce = $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
            if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat_nonce' ) && ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
                wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
            }

            $query = sanitize_text_field( $_REQUEST['query'] ?? '' );
            $limit = absint( $_REQUEST['limit'] ?? 20 );

            $suggestions = $this->get_plugin_suggestions( $query, $limit );

            wp_send_json_success( [
                'suggestions' => $suggestions,
                'query'       => $query,
                'count'       => count( $suggestions ),
            ] );

        } catch ( Exception $e ) {
            wp_send_json_error( [
                'message' => 'Internal error: ' . $e->getMessage(),
            ], 500 );
        }
    }

    public function ajax_get_plugin_context() {
        try {
            $nonce = $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
            if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat_nonce' ) && ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
                wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
            }

            $slug = sanitize_key( $_REQUEST['slug'] ?? '' );
            $session_id = sanitize_text_field( $_REQUEST['session_id'] ?? '' );

            if ( empty( $slug ) ) {
                wp_send_json_error( [ 'message' => 'Missing plugin slug' ], 400 );
            }

            $context = $this->get_plugin_context( $slug, $session_id );

            wp_send_json_success( $context );

        } catch ( Exception $e ) {
            wp_send_json_error( [
                'message' => 'Internal error: ' . $e->getMessage(),
            ], 500 );
        }
    }

    /* ================================================================
     * Pre-Intent Estimate — Lightweight tool matching on typing
     * ================================================================
     *
     * Called by the frontend as user types (debounced 400ms).
     * Returns ranked plugin slugs that match the current draft text.
     * No LLM required — pure keyword + tool_registry DB matching.
     *
     * @since v3.9.0
     */

    public function ajax_pre_intent_estimate() {
        // Accept both nonce names for flexibility
        $nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) && ! wp_verify_nonce( $nonce, 'bizcity_chat' ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }

        $message = isset( $_POST['message'] ) ? sanitize_text_field( $_POST['message'] ) : '';
        if ( mb_strlen( $message ) < 3 ) {
            wp_send_json_success( array( 'suggestions' => array(), 'highlight' => '' ) );
        }

        $results = $this->estimate_plugin_match( $message );

        wp_send_json_success( $results );
    }

    /**
     * Estimate which plugin(s) best match a draft message.
     * Pure heuristic — no LLM, fast (<10ms).
     *
     * Strategy (3 layers):
     *   L1. Tool Registry keyword match (goal_label, tool_name, keywords, goal_description)
     *   L2. Market Catalog plugin name/description match
     *   L3. Active gathering state bonus (if user is mid-conversation with a plugin)
     *
     * @param string $message   Draft message text
     * @return array { suggestions: [{slug, score, label, reason}], highlight: slug|'' }
     */
    public function estimate_plugin_match( $message ) {
        global $wpdb;

        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        $plugin_scores = array();  // slug => { score, label, reason, icon }

        // ── L1: Tool Registry keyword matching ──
        $table = $wpdb->prefix . 'bizcity_tool_registry';
       # $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // cái này nặng query nên bỏ    

       # if ( $table_exists ) { 
            $tools = $wpdb->get_results(
                "SELECT tool_name, title, goal, goal_label, goal_description, plugin, description
                 FROM {$table}
                 WHERE active = 1
                 ORDER BY priority ASC
                 LIMIT 100",
                ARRAY_A
            );

            foreach ( $tools as $tool ) {
                $slug  = $tool['plugin'] ?? '';
                if ( empty( $slug ) ) continue;

                $score = 0;
                $reason = '';

                // tool_name exact match (highest signal)
                $tool_name = mb_strtolower( $tool['tool_name'] ?? '', 'UTF-8' );
                if ( $tool_name && mb_strpos( $msg_lower, $tool_name ) !== false ) {
                    $score += 12;
                    $reason = $tool['tool_name'];
                } elseif ( $tool_name && mb_strlen( $msg_lower ) >= 3 && mb_strpos( $tool_name, $msg_lower ) !== false ) {
                    $score += 8;
                    $reason = $tool['tool_name'];
                }

                // goal_label match
                $goal_label = mb_strtolower( $tool['goal_label'] ?? '', 'UTF-8' );
                if ( $goal_label && mb_strpos( $msg_lower, $goal_label ) !== false ) {
                    $score += 10;
                    $reason = $reason ?: $tool['goal_label'];
                } elseif ( $goal_label && mb_strlen( $msg_lower ) >= 3 && mb_strpos( $goal_label, $msg_lower ) !== false ) {
                    $score += 7;
                    $reason = $reason ?: $tool['goal_label'];
                }

                // title match
                $title_lower = mb_strtolower( $tool['title'] ?? '', 'UTF-8' );
                if ( $title_lower && mb_strpos( $msg_lower, $title_lower ) !== false ) {
                    $score += 8;
                    $reason = $reason ?: ( $tool['title'] ?? '' );
                } elseif ( $title_lower && mb_strlen( $msg_lower ) >= 3 && mb_strpos( $title_lower, $msg_lower ) !== false ) {
                    $score += 5;
                    $reason = $reason ?: ( $tool['title'] ?? '' );
                }

                // Keywords from goal_description + description
                $desc_text = ( $tool['goal_description'] ?? '' ) . ' ' . ( $tool['description'] ?? '' );
                $keywords = array_filter(
                    array_unique( explode( ' ', mb_strtolower( $desc_text, 'UTF-8' ) ) ),
                    function( $w ) { return mb_strlen( $w ) > 2; }
                );

                foreach ( $keywords as $kw ) {
                    if ( mb_strpos( $msg_lower, $kw ) !== false ) {
                        $score += 1;
                    }
                }

                // Accumulate per plugin slug
                if ( $score > 0 ) {
                    if ( ! isset( $plugin_scores[ $slug ] ) ) {
                        $plugin_scores[ $slug ] = array(
                            'slug'   => $slug,
                            'score'  => 0,
                            'label'  => $tool['goal_label'] ?: $tool['title'] ?: $slug,
                            'reason' => '',
                            'icon'   => '',
                        );
                    }
                    // Take highest score per plugin (across multiple tools)
                    if ( $score > $plugin_scores[ $slug ]['score'] ) {
                        $plugin_scores[ $slug ]['score']  = $score;
                        $plugin_scores[ $slug ]['reason'] = $reason;
                        $plugin_scores[ $slug ]['label']  = $tool['goal_label'] ?: $tool['title'] ?: $slug;
                    }
                }
            }
        #}

        // ── L2: Market Catalog plugin name/description match ──
        if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();

            foreach ( $agent_plugins as $plugin ) {
                $slug = $plugin['slug'] ?? '';
                if ( empty( $slug ) ) continue;

                $bonus = 0;
                $name_lower = mb_strtolower( $plugin['name'] ?? '', 'UTF-8' );
                $desc_lower = mb_strtolower( $plugin['description'] ?? '', 'UTF-8' );

                if ( $name_lower && mb_strpos( $msg_lower, $name_lower ) !== false ) {
                    $bonus += 6;
                } elseif ( $name_lower && mb_strlen( $msg_lower ) >= 3 && mb_strpos( $name_lower, $msg_lower ) !== false ) {
                    $bonus += 4;
                }
                if ( $desc_lower ) {
                    $desc_words = array_filter(
                        explode( ' ', $desc_lower ),
                        function( $w ) { return mb_strlen( $w ) > 3; }
                    );
                    foreach ( $desc_words as $dw ) {
                        if ( mb_strpos( $msg_lower, $dw ) !== false ) {
                            $bonus += 1;
                        }
                    }
                    $bonus = min( $bonus, 10 ); // cap catalog bonus
                }

                if ( $bonus > 0 ) {
                    if ( ! isset( $plugin_scores[ $slug ] ) ) {
                        $plugin_scores[ $slug ] = array(
                            'slug'   => $slug,
                            'score'  => 0,
                            'label'  => $plugin['name'] ?? $slug,
                            'reason' => '',
                            'icon'   => $plugin['icon_url'] ?? '',
                        );
                    }
                    $plugin_scores[ $slug ]['score'] += $bonus;
                    if ( empty( $plugin_scores[ $slug ]['icon'] ) ) {
                        $plugin_scores[ $slug ]['icon'] = $plugin['icon_url'] ?? '';
                    }
                }
            }
        }

        // ── Sort by score descending, filter minimum ──
        $suggestions = array_values( $plugin_scores );
        usort( $suggestions, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        // Only return plugins with meaningful score
        $min_score = 3;
        $suggestions = array_filter( $suggestions, function( $s ) use ( $min_score ) {
            return $s['score'] >= $min_score;
        } );
        $suggestions = array_values( array_slice( $suggestions, 0, 5 ) );

        // Best match for auto-highlight
        $highlight = '';
        if ( ! empty( $suggestions ) && $suggestions[0]['score'] >= 5 ) {
            $highlight = $suggestions[0]['slug'];
        }

        return array(
            'suggestions' => $suggestions,
            'highlight'   => $highlight,
        );
    }

    /* ================================================================
     * Core Logic
     * ================================================================ */

    /**
     * Get list of plugin suggestions based on query.
     *
     * @param string $query Search query
     * @param int    $limit Maximum results
     * @return array Array of plugin suggestions
     */
    public function get_plugin_suggestions( $query = '', $limit = 20 ) {
        $suggestions = [];

        // Get agent plugins from marketplace catalog
        if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();

            foreach ( $agent_plugins as $plugin ) {
                $name = $plugin['name'] ?? '';
                $slug = $plugin['slug'] ?? '';
                $template_page = $plugin['template_page'] ?? '';

                if ( empty( $name ) || empty( $slug ) ) {
                    continue;
                }

                // Filter by query if provided
                if ( ! empty( $query ) ) {
                    $search_fields = [ $name, $slug, $template_page, $plugin['description'] ?? '' ];
                    $match = false;

                    foreach ( $search_fields as $field ) {
                        if ( stripos( $field, $query ) !== false ) {
                            $match = true;
                            break;
                        }
                    }

                    if ( ! $match ) {
                        continue;
                    }
                }

                $suggestions[] = [
                    'slug'         => $slug,
                    'name'         => $name,
                    'description'  => wp_trim_words( $plugin['description'] ?? '', 15 ),
                    'icon_url'     => $plugin['icon_url'] ?? '',
                    'template_url' => $plugin['template_url'] ?? '',
                    'template_page'=> $template_page,
                    'category'     => $plugin['category'] ?? '',
                    'credit'       => $plugin['credit'] ?? '',
                    'price'        => $plugin['price'] ?? '',
                    'match_score'  => $this->calculate_match_score( $query, $name, $slug, $plugin['description'] ?? '' ),
                ];

                // Limit results
                if ( count( $suggestions ) >= $limit ) {
                    break;
                }
            }

            // Sort by match score (descending) and limit
            usort( $suggestions, function( $a, $b ) {
                return $b['match_score'] <=> $a['match_score'];
            } );

            $suggestions = array_slice( $suggestions, 0, $limit );
        }

        return $suggestions;
    }

    /**
     * Get plugin context for session (missing fields, related messages, etc.).
     *
     * @param string $slug      Plugin slug or template_page slug
     * @param string $session_id Session ID
     * @return array Context data
     */
    public function get_plugin_context( $slug, $session_id = '' ) {
        $context = [
            'plugin' => null,
            'messages' => [],
            'missing_fields' => [],
            'suggestions' => [],
            'slot_info' => [],
        ];

        // Get plugin info
        $plugin_info = $this->find_plugin_by_slug( $slug );
        if ( $plugin_info ) {
            $context['plugin'] = $plugin_info;
        }

        // Get messages related to this plugin in session
        if ( ! empty( $session_id ) ) {
            $context['messages'] = $this->get_plugin_messages_in_session( $slug, $session_id );
        }

        // Get missing fields/slots from Intent Engine
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $context = $this->add_intent_context( $context, $slug, $session_id );
        }

        return $context;
    }

    /* ================================================================
     * Helper Methods
     * ================================================================ */

    /**
     * Find plugin by slug (supports both plugin slug and template_page slug).
     *
     * @param string $slug Plugin slug
     * @return array|null Plugin info or null
     */
    private function find_plugin_by_slug( $slug ) {
        if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();

            foreach ( $agent_plugins as $plugin ) {
                // Match by plugin slug or template_page
                if ( $plugin['slug'] === $slug || $plugin['template_page'] === $slug ) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /**
     * Get messages related to plugin in session.
     *
     * @param string $slug      Plugin slug
     * @param string $session_id Session ID
     * @return array Messages
     */
    private function get_plugin_messages_in_session( $slug, $session_id ) {
        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, message_text, message_from, message_type, plugin_slug, created_at, meta 
             FROM {$table} 
             WHERE session_id = %s AND (plugin_slug = %s OR plugin_slug = '') 
             ORDER BY created_at ASC 
             LIMIT 50",
            $session_id,
            $slug
        ) );

        $result = [];
        foreach ( $messages as $msg ) {
            $result[] = [
                'id'          => (int) $msg->id,
                'text'        => $msg->message_text,
                'from'        => $msg->message_from,
                'type'        => $msg->message_type,
                'plugin_slug' => $msg->plugin_slug,
                'created_at'  => $msg->created_at,
                'meta'        => $msg->meta ? json_decode( $msg->meta, true ) : [],
            ];
        }

        return $result;
    }

    /**
     * Add Intent Engine context (missing slots, etc.).
     *
     * @param array  $context Existing context
     * @param string $slug    Plugin slug
     * @param string $session_id Session ID
     * @return array Updated context
     */
    private function add_intent_context( $context, $slug, $session_id ) {
        // Get tool registry for this plugin to find goals/slots
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $tools = BizCity_Intent_Tools::instance();
            $registry = $tools->get_registry();

            // Find tools for this plugin
            $plugin_tools = [];
            foreach ( $registry as $goal => $info ) {
                if ( isset( $info['plugin'] ) && $info['plugin'] === $slug ) {
                    $plugin_tools[ $goal ] = $info;
                }
            }

            $context['slot_info'] = $plugin_tools;

            // Get active conversations for this session to check missing slots
            if ( ! empty( $session_id ) && class_exists( 'BizCity_Intent_Database' ) ) {
                $intent_db = BizCity_Intent_Database::instance();
                $conversations = $intent_db->get_active_conversations_for_session( $session_id );

                foreach ( $conversations as $conv ) {
                    $slots = json_decode( $conv->slots ?? '{}', true );
                    
                    if ( isset( $plugin_tools[ $conv->goal ] ) ) {
                        $required_slots = $plugin_tools[ $conv->goal ]['slots'] ?? [];
                        $missing = [];

                        foreach ( $required_slots as $slot_name => $slot_info ) {
                            if ( empty( $slots[ $slot_name ] ) ) {
                                $missing[] = [
                                    'name'        => $slot_name,
                                    'label'       => $slot_info['label'] ?? $slot_name,
                                    'description' => $slot_info['description'] ?? '',
                                    'type'        => $slot_info['type'] ?? 'string',
                                    'required'    => $slot_info['required'] ?? true,
                                ];
                            }
                        }

                        if ( ! empty( $missing ) ) {
                            $context['missing_fields'][ $conv->goal ] = $missing;
                        }
                    }
                }
            }
        }

        return $context;
    }

    /**
     * Calculate match score for search query.
     *
     * @param string $query Query string
     * @param string $name  Plugin name
     * @param string $slug  Plugin slug  
     * @param string $desc  Plugin description
     * @return int Match score (0-100)
     */
    private function calculate_match_score( $query, $name, $slug, $desc ) {
        if ( empty( $query ) ) {
            return 50; // Default score when no query
        }

        $score = 0;
        $query_lower = mb_strtolower( $query );

        // Exact name match = 100 points
        if ( mb_strtolower( $name ) === $query_lower ) {
            $score += 100;
        }
        // Name contains query = 80 points
        elseif ( stripos( $name, $query ) !== false ) {
            $score += 80;
        }

        // Slug contains query = 60 points
        if ( stripos( $slug, $query ) !== false ) {
            $score += 60;
        }

        // Description contains query = 30 points
        if ( stripos( $desc, $query ) !== false ) {
            $score += 30;
        }

        return min( $score, 100 );
    }

    /* ================================================================
     * Slash Command — Tool-level search in bizcity_tool_registry
     * ================================================================
     *
     * User types `/keyword` → search tools by goal_description, custom_hints, title.
     * Returns tool-level results (not plugin-level) so user can pick exact tool.
     * Optionally scoped to a specific plugin (when already in @mention context).
     *
     * @since v4.0.0 (Phase 13 — Dual Context Architecture)
     */

    /**
     * REST handler: GET /bizcity-webchat/v1/search-tools?query=...&plugin_slug=...
     */
    public function rest_search_tools( WP_REST_Request $request ) {
        $query       = sanitize_text_field( $request->get_param( 'query' ) );
        $plugin_slug = sanitize_text_field( $request->get_param( 'plugin_slug' ) );
        $limit       = absint( $request->get_param( 'limit' ) );

        $results = $this->search_tools( $query, $plugin_slug, $limit ?: 5 );

        return rest_ensure_response( $results );
    }

    /**
     * AJAX handler: bizcity_search_tools
     */
    public function ajax_search_tools() {
        // Accept both nonce names for flexibility
        $nonce = $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat_nonce' ) && ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) && ! wp_verify_nonce( $nonce, 'bizcity_chat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $query       = sanitize_text_field( $_REQUEST['query'] ?? '' );
        $plugin_slug = sanitize_text_field( $_REQUEST['plugin_slug'] ?? '' );
        $limit       = absint( $_REQUEST['limit'] ?? 5 );

        // Resolve market slug → provider ID (e.g. 'bizcoach-map' → 'bizcoach')
        if ( $plugin_slug && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $plugin_slug = BizCity_Intent_Provider_Registry::instance()->resolve_slug( $plugin_slug );
        }

        if ( mb_strlen( $query ) < 1 ) {
            // Empty query: return all tools (optionally scoped to plugin)
            $results = $this->search_tools( '', $plugin_slug, $limit );
            wp_send_json_success( $results );
            return;
        }

        $results = $this->search_tools( $query, $plugin_slug, $limit );

        wp_send_json_success( $results );
    }

    /**
     * Core tool search logic — searches bizcity_tool_registry by multiple fields.
     *
     * Search strategy (scored):
     *   1. goal exact match → 20 pts
     *   2. title contains keyword → 15 pts
     *   3. goal_label contains keyword → 12 pts
     *   4. custom_hints contains keyword → 10 pts (admin-curated keywords)
     *   5. goal_description word match → 1 pt per word
     *   6. description word match → 1 pt per word (capped at 5)
     *
     * @param string $query        Search keyword(s)
     * @param string $plugin_slug  Optional: scope to one plugin
     * @param int    $limit        Max results (default 5)
     * @return array { tools: [{goal, title, goal_label, goal_description, plugin_slug, icon, score}] }
     */
    public function search_tools( $query = '', $plugin_slug = '', $limit = 5 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bizcity_tool_registry';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( ! $table_exists ) {
            return array( 'tools' => array(), 'query' => $query );
        }

        // Build WHERE clause
        $where = 'WHERE active = 1';
        if ( ! empty( $plugin_slug ) ) {
            $where .= $wpdb->prepare( ' AND plugin = %s', $plugin_slug );
        }

        $tools = $wpdb->get_results(
            "SELECT id, tool_name, title, goal, goal_label, goal_description, 
                    plugin, description, custom_hints, custom_description, priority
             FROM {$table}
             {$where}
             ORDER BY priority ASC
             LIMIT 100",
            ARRAY_A
        );

        if ( empty( $tools ) ) {
            return array( 'tools' => array(), 'query' => $query );
        }

        // If no query, return all tools sorted by priority
        if ( empty( $query ) ) {
            $result_tools = array();
            foreach ( array_slice( $tools, 0, $limit ) as $tool ) {
                $result_tools[] = $this->format_tool_result( $tool, 0, '' );
            }
            return array( 'tools' => $result_tools, 'query' => $query );
        }

        // Score each tool against the query
        $query_lower = mb_strtolower( trim( $query ), 'UTF-8' );
        $query_words = array_filter(
            explode( ' ', $query_lower ),
            function( $w ) { return mb_strlen( $w ) >= 2; }
        );

        $scored = array();
        foreach ( $tools as $tool ) {
            $score  = 0;
            $reason = '';

            // 1. goal exact match (highest signal — user knows the goal name)
            $goal_lower = mb_strtolower( $tool['goal'] ?? '', 'UTF-8' );
            if ( $goal_lower && $goal_lower === $query_lower ) {
                $score += 20;
                $reason = 'goal: ' . $tool['goal'];
            } elseif ( $goal_lower && mb_strpos( $goal_lower, $query_lower ) !== false ) {
                $score += 15;
                $reason = 'goal: ' . $tool['goal'];
            }

            // 2. title contains keyword
            $title_lower = mb_strtolower( $tool['title'] ?? '', 'UTF-8' );
            if ( $title_lower && mb_strpos( $title_lower, $query_lower ) !== false ) {
                $score += 15;
                $reason = $reason ?: ( $tool['title'] ?? '' );
            }

            // 3. goal_label contains keyword
            $label_lower = mb_strtolower( $tool['goal_label'] ?? '', 'UTF-8' );
            if ( $label_lower && mb_strpos( $label_lower, $query_lower ) !== false ) {
                $score += 12;
                $reason = $reason ?: ( $tool['goal_label'] ?? '' );
            }

            // 4. custom_hints contains keyword (admin-curated, high value)
            $hints_lower = mb_strtolower( $tool['custom_hints'] ?? '', 'UTF-8' );
            if ( $hints_lower && mb_strpos( $hints_lower, $query_lower ) !== false ) {
                $score += 10;
                $reason = $reason ?: 'hints match';
            }

            // 5. goal_description word match
            $desc_lower = mb_strtolower( $tool['goal_description'] ?? '', 'UTF-8' );
            if ( $desc_lower ) {
                foreach ( $query_words as $qw ) {
                    if ( mb_strpos( $desc_lower, $qw ) !== false ) {
                        $score += 1;
                    }
                }
            }

            // 6. description word match (capped at 5)
            $full_desc_lower = mb_strtolower( $tool['description'] ?? '', 'UTF-8' );
            if ( $full_desc_lower ) {
                $desc_bonus = 0;
                foreach ( $query_words as $qw ) {
                    if ( mb_strpos( $full_desc_lower, $qw ) !== false ) {
                        $desc_bonus += 1;
                    }
                }
                $score += min( $desc_bonus, 5 );
            }

            // 7. custom_description word match
            $custom_desc_lower = mb_strtolower( $tool['custom_description'] ?? '', 'UTF-8' );
            if ( $custom_desc_lower ) {
                foreach ( $query_words as $qw ) {
                    if ( mb_strpos( $custom_desc_lower, $qw ) !== false ) {
                        $score += 1;
                    }
                }
            }

            if ( $score > 0 ) {
                $scored[] = array(
                    'tool'   => $tool,
                    'score'  => $score,
                    'reason' => $reason,
                );
            }
        }

        // Sort by score descending
        usort( $scored, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        // Return top N
        $result_tools = array();
        foreach ( array_slice( $scored, 0, $limit ) as $item ) {
            $result_tools[] = $this->format_tool_result( $item['tool'], $item['score'], $item['reason'] );
        }

        return array(
            'tools' => $result_tools,
            'query' => $query,
        );
    }

    /**
     * Format a tool registry row into a clean result object for frontend.
     *
     * @param array  $tool   DB row from bizcity_tool_registry
     * @param int    $score  Match score
     * @param string $reason Why this tool matched
     * @return array
     */
    private function format_tool_result( $tool, $score, $reason ) {
        // Try to get plugin icon from Market Catalog
        $icon = '';
        $plugin_name = '';
        $plugin_slug = $tool['plugin'] ?? '';

        if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            static $catalog_cache = null;
            if ( $catalog_cache === null ) {
                $catalog_cache = array();
                $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();
                foreach ( $agent_plugins as $p ) {
                    $s = $p['slug'] ?? '';
                    if ( $s ) {
                        $catalog_cache[ $s ] = $p;
                    }
                }
            }
            if ( isset( $catalog_cache[ $plugin_slug ] ) ) {
                $icon        = $catalog_cache[ $plugin_slug ]['icon_url'] ?? '';
                $plugin_name = $catalog_cache[ $plugin_slug ]['name'] ?? '';
            }
        }

        return array(
            'goal'             => $tool['goal'] ?? '',
            'tool_name'        => $tool['tool_name'] ?? '',
            'title'            => $tool['title'] ?? '',
            'goal_label'       => $tool['goal_label'] ?? '',
            'goal_description' => $tool['goal_description'] ?? '',
            'plugin_slug'      => $plugin_slug,
            'plugin_name'      => $plugin_name,
            'icon'             => $icon,
            'score'            => $score,
            'reason'           => $reason,
        );
    }

    /* ================================================================
     * Skill Search (for / slash command)
     * ================================================================ */

    /**
     * AJAX handler: bizcity_search_skills
     *
     * Searches skill catalog (file-based + DB) for slash command dropdown.
     */
    public function ajax_search_skills() {
        $nonce = $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat_nonce' ) && ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) && ! wp_verify_nonce( $nonce, 'bizcity_chat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $query = sanitize_text_field( $_REQUEST['query'] ?? '' );
        $limit = absint( $_REQUEST['limit'] ?? 8 );

        if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
            wp_send_json_success( [ 'skills' => [], 'query' => $query ] );
            return;
        }

        $mgr    = \BizCity_Skill_Manager::instance();
        $skills = $mgr->get_all_skills();
        $query_lower = mb_strtolower( trim( $query ), 'UTF-8' );

        $scored = [];
        foreach ( $skills as $s ) {
            $fm    = $s['frontmatter'] ?? [];
            $title = $fm['title'] ?? basename( $s['path'] ?? '', '.md' );
            $desc  = $fm['description'] ?? '';
            $name  = $fm['name'] ?? sanitize_title( $title );
            $triggers = $fm['triggers'] ?? [];
            $slash_cmds = $fm['slash_commands'] ?? [];
            $modes = $fm['modes'] ?? [];
            $tools = array_merge( (array) ( $fm['related_tools'] ?? [] ), (array) ( $fm['tools'] ?? [] ) );

            $score = 0;
            $reason = '';

            if ( empty( $query_lower ) ) {
                $score = 1; // show all when empty query
            } else {
                // Name / slug exact match
                if ( mb_strtolower( $name ) === $query_lower ) {
                    $score += 20;
                    $reason = 'name: ' . $name;
                } elseif ( mb_strpos( mb_strtolower( $name ), $query_lower ) !== false ) {
                    $score += 15;
                    $reason = 'name: ' . $name;
                }

                // Title match
                if ( mb_strpos( mb_strtolower( $title ), $query_lower ) !== false ) {
                    $score += 15;
                    $reason = $reason ?: $title;
                }

                // Description match
                if ( $desc && mb_strpos( mb_strtolower( $desc ), $query_lower ) !== false ) {
                    $score += 8;
                    $reason = $reason ?: 'description match';
                }

                // Trigger match
                foreach ( $triggers as $t ) {
                    if ( is_string( $t ) && mb_strpos( mb_strtolower( $t ), $query_lower ) !== false ) {
                        $score += 10;
                        $reason = $reason ?: 'trigger: ' . $t;
                        break;
                    }
                }

                // Slash command match
                foreach ( $slash_cmds as $cmd ) {
                    if ( is_string( $cmd ) && mb_strpos( mb_strtolower( $cmd ), $query_lower ) !== false ) {
                        $score += 12;
                        $reason = $reason ?: 'slash: ' . $cmd;
                        break;
                    }
                }

                // Tool match
                foreach ( $tools as $tool ) {
                    if ( is_string( $tool ) && mb_strpos( mb_strtolower( $tool ), $query_lower ) !== false ) {
                        $score += 5;
                        break;
                    }
                }
            }

            if ( $score > 0 ) {
                $scored[] = [
                    'skill_key'   => $name,
                    'title'       => $title,
                    'description' => mb_substr( $desc, 0, 120 ),
                    'category'    => dirname( $s['path'] ?? '' ),
                    'path'        => $s['path'] ?? '',
                    'modes'       => $modes,
                    'tools'       => $tools,
                    'triggers'    => $triggers,
                    'priority'    => (int) ( $fm['priority'] ?? 50 ),
                    'score'       => $score,
                    'reason'      => $reason,
                ];
            }
        }

        // Sort by score desc, then priority asc
        usort( $scored, function ( $a, $b ) {
            $s = $b['score'] - $a['score'];
            return $s !== 0 ? $s : $a['priority'] - $b['priority'];
        } );

        wp_send_json_success( [
            'skills' => array_slice( $scored, 0, $limit ),
            'query'  => $query,
        ] );
    }

    /* ================================================================
     * Permission Checks
     * ================================================================ */

    public function check_plugin_permission( WP_REST_Request $request ) {
        // For now, allow all users to access plugin context
        // TODO: Add entitlement checks if needed
        return true;
    }
}

// Initialize
if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
    BizCity_Plugin_Suggestion_API::instance();
}