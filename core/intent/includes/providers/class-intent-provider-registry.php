<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Provider Registry
 *
 * Central registry that collects all active Skill Providers and
 * aggregates their intents/plans/tools/context into the engine pipeline.
 *
 * Each plugin registers its Provider via:
 *   add_action( 'bizcity_intent_register_providers', function( $registry ) {
 *       $registry->register( new My_Plugin_Intent_Provider() );
 *   } );
 *
 * The registry then:
 *   1. Merges all goal_patterns into the Router via `bizcity_intent_goal_patterns` filter
 *   2. Merges all plans into the Planner via `bizcity_intent_plans` filter
 *   3. Registers all provider tools directly into BizCity_Intent_Tools
 *   4. On compose_answer, calls the owning provider's build_context()
 *
 * @package BizCity_Intent
 * @since   1.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Provider_Registry {

    /** @var self|null */
    private static $instance = null;

    /**
     * Registered providers, keyed by ID.
     *
     * @var BizCity_Intent_Provider[]
     */
    private $providers = [];

    /**
     * Cache: goal → provider_id mapping.
     *
     * @var array
     */
    private $goal_map = [];

    /** @var bool Whether providers have been booted (hooks wired) */
    private $booted = false;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ================================================================
     *  Registration
     * ================================================================ */

    /**
     * Register a provider.
     *
     * @param BizCity_Intent_Provider $provider
     */
    public function register( BizCity_Intent_Provider $provider ) {
        $id = $provider->get_id();
        $this->providers[ $id ] = $provider;

        // Rebuild goal map
        foreach ( $provider->get_owned_goals() as $goal ) {
            $this->goal_map[ $goal ] = $id;
        }
    }

    /**
     * Unregister a provider by ID.
     *
     * @param string $id
     */
    public function unregister( $id ) {
        unset( $this->providers[ $id ] );
        $this->goal_map = array_filter( $this->goal_map, function ( $pid ) use ( $id ) {
            return $pid !== $id;
        } );
    }

    /**
     * Get a provider by ID.
     *
     * @param string $id
     * @return BizCity_Intent_Provider|null
     */
    public function get( $id ) {
        return $this->providers[ $id ] ?? null;
    }

    /**
     * Resolve a WordPress plugin slug (e.g. "bizcity-tarot", "bizcoach-map")
     * to its provider ID (e.g. "tarot", "bizcoach").
     * Returns the original value if already a valid provider ID or no match found.
     *
     * @param string $slug WordPress plugin directory name or provider ID.
     * @return string Resolved provider ID.
     */
    public function resolve_slug( $slug ) {
        if ( empty( $slug ) ) {
            return $slug;
        }
        // Direct match → already a provider ID
        if ( isset( $this->providers[ $slug ] ) ) {
            return $slug;
        }
        // Search all providers: slug contains provider_id or vice versa
        foreach ( $this->providers as $provider_id => $provider ) {
            if ( strpos( $slug, $provider_id ) !== false
                 || strpos( $provider_id, $slug ) !== false
            ) {
                return $provider_id;
            }
        }
        return $slug;
    }

    /**
     * Get all registered providers.
     *
     * @return BizCity_Intent_Provider[]
     */
    public function get_all() {
        return $this->providers;
    }

    /**
     * Get the provider that owns a given goal.
     *
     * @param string $goal
     * @return BizCity_Intent_Provider|null
     */
    public function get_provider_for_goal( $goal ) {
        $pid = $this->goal_map[ $goal ] ?? '';
        return $pid ? ( $this->providers[ $pid ] ?? null ) : null;
    }

    /* ================================================================
     *  Aggregation — merge all providers into engine components
     * ================================================================ */

    /**
     * Boot: wire all provider data into the engine via filters.
     * Called once after all providers have registered.
     */
    public function boot() {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        // ── Diagnostic: log registered providers ──
        if ( defined( 'BIZCITY_INTENT_DEBUG' ) && BIZCITY_INTENT_DEBUG ) {
            $ids = array_keys( $this->providers );
            $goal_count = count( $this->goal_map );
            error_log( '[Intent Registry] boot() — Providers: [' . implode( ', ', $ids ) . '] | Goals mapped: ' . $goal_count );
            foreach ( $this->goal_map as $goal => $pid ) {
                error_log( "  goal={$goal} → provider={$pid}" );
            }
        }

        // 1. Merge goal patterns into Router
        add_filter( 'bizcity_intent_goal_patterns', [ $this, 'merge_goal_patterns' ], 20 );

        // 2. Merge plans into Planner
        add_filter( 'bizcity_intent_plans', [ $this, 'merge_plans' ], 20 );

        // 3. Register provider tools directly into Tool Registry
        $this->register_provider_tools();

        // 4. Inject context for compose_answer
        add_filter( 'bizcity_intent_compose_context', [ $this, 'inject_context' ], 10, 4 );

        // 5. Tool Registry: initial seed (only if table is empty — e.g., fresh install).
        //    Normal updates happen via activated_plugin / deactivated_plugin hooks in bootstrap.
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            BizCity_Intent_Tool_Index::instance()->sync_all( $this->providers );
        }

        // 6. Fire bizcity_intent_tools_ready AFTER built-in tools registered (init:25 > init_builtin at init:20).
        //    External plugins hook into this to register additional tools.
        //    Then re-sync DB to include late registrations.
        add_action( 'init', function () {
            $tools = BizCity_Intent_Tools::instance();
            do_action( 'bizcity_intent_tools_ready', $tools );

            // Re-sync in-memory tools to DB (picks up tools registered by the hook above)
            if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
                BizCity_Intent_Tool_Index::instance()->sync_memory_tools();
            }
        }, 25 );
    }

    /**
     * Filter: merge all provider goal patterns into the Router.
     *
     * Provider patterns are PREPENDED (checked first) so that domain-specific
     * patterns like "thống kê calo" match before generic built-in patterns
     * like "thống kê" → report.
     *
     * @param array $patterns Existing (built-in) patterns.
     * @return array  Provider patterns first, then built-in patterns.
     */
    public function merge_goal_patterns( array $patterns ) {
        $provider_patterns = [];
        foreach ( $this->providers as $provider ) {
            $pp = $provider->get_goal_patterns();
            if ( ! empty( $pp ) ) {
                // Tag each pattern with the provider source for debug tracing
                foreach ( $pp as $regex => $config ) {
                    $config['_provider_source'] = $provider->get_id();
                    $provider_patterns[ $regex ] = $config;
                }
            }
        }
        // Provider patterns FIRST → more specific domain patterns are matched
        // before generic built-in patterns (e.g. "thống kê calo" before "thống kê").
        return array_merge( $provider_patterns, $patterns );
    }

    /**
     * Filter: merge all provider plans into the Planner.
     *
     * @param array $plans Existing plans.
     * @return array
     */
    public function merge_plans( array $plans ) {
        foreach ( $this->providers as $provider ) {
            $provider_plans = $provider->get_plans();
            if ( ! empty( $provider_plans ) ) {
                $plans = array_merge( $plans, $provider_plans );
            }
        }
        return $plans;
    }

    /**
     * Register all provider tools directly into BizCity_Intent_Tools.
     */
    private function register_provider_tools() {
        if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
            return;
        }
        $tool_registry = BizCity_Intent_Tools::instance();
        foreach ( $this->providers as $provider ) {
            $provider_tools = $provider->get_tools();
            foreach ( $provider_tools as $name => $config ) {
                if ( $tool_registry->has( $name ) ) {
                    // Provider tools override built-in and plugin tools (SDK-compliant, has plans/slots).
                    // But do NOT override another provider's tools (first provider wins).
                    $source = $tool_registry->get_tool_source( $name );
                    if ( $source === 'provider' ) {
                        continue; // Another provider already registered — keep first
                    }
                    // built_in or plugin → provider overrides
                }
                $schema   = $config['schema'] ?? [];
                $callback = $config['callback'] ?? null;
                if ( $callback && is_callable( $callback ) ) {
                    $tool_registry->register( $name, $schema, $callback );
                }
            }
        }
    }

    /**
     * Filter: inject provider's domain context when composing AI response.
     *
     * @param string $context       Existing context string.
     * @param string $goal          Active goal.
     * @param array  $conversation  Full conversation record.
     * @param int    $user_id       WP user ID.
     * @return string
     */
    public function inject_context( $context, $goal, $conversation, $user_id ) {
        $provider = $this->get_provider_for_goal( $goal );
        if ( ! $provider ) {
            return $context;
        }

        $slots = $conversation['slots'] ?? [];

        // Get domain context (plugin-specific DB data)
        $domain_ctx = $provider->build_context( $goal, $slots, $user_id, $conversation );

        // Get system instructions
        $instructions = $provider->get_system_instructions( $goal );

        // Get knowledge RAG context (from bizcity-knowledge, per-agent)
        $knowledge_ctx = $this->build_knowledge_context( $provider, $goal, $slots, $conversation );

        $extra = '';
        if ( $instructions ) {
            $extra .= "\n\n=== HƯỚNG DẪN TỪ " . strtoupper( $provider->get_name() ) . " ===\n" . $instructions;
        }
        if ( $domain_ctx ) {
            $extra .= "\n\n=== DỮ LIỆU TỪ " . strtoupper( $provider->get_name() ) . " ===\n" . $domain_ctx;
        }
        if ( $knowledge_ctx ) {
            $extra .= "\n\n=== KIẾN THỨC CHUYÊN MÔN (" . strtoupper( $provider->get_name() ) . ") ===\n" . $knowledge_ctx;
        }

        return $context . $extra;
    }

    /**
     * Build knowledge context from bizcity-knowledge for a specific provider.
     *
     * Each provider can declare a linked character_id via get_knowledge_character_id().
     * If set, this method calls Knowledge Context API to get RAG-based context
     * (semantic search + FAQ + file chunks) specific to this agent's domain.
     *
     * This is the core of the Selective Context Loading optimization:
     * only load knowledge for the specific agent that owns the current goal.
     *
     * @param BizCity_Intent_Provider $provider     The owning provider.
     * @param string                  $goal         Active goal ID.
     * @param array                   $slots        Current slot values.
     * @param array                   $conversation Full conversation record.
     * @return string  Knowledge context text (empty = no knowledge binding).
     * @since 1.3.0
     */
    private function build_knowledge_context( $provider, $goal, $slots, $conversation ) {
        $char_id = $provider->get_knowledge_character_id();
        if ( ! $char_id ) {
            return '';
        }

        if ( ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            return '';
        }

        // Build query from slots or last message
        $query = '';
        if ( ! empty( $slots['question_focus'] ) ) {
            $query = $slots['question_focus'];
        } elseif ( ! empty( $conversation['last_message'] ) ) {
            $query = $conversation['last_message'];
        } elseif ( ! empty( $conversation['messages'] ) ) {
            $messages = $conversation['messages'];
            $last = end( $messages );
            $query = is_array( $last ) ? ( $last['content'] ?? '' ) : (string) $last;
        }

        if ( empty( $query ) ) {
            return '';
        }

        $result = BizCity_Knowledge_Context_API::instance()->build_context(
            $char_id,
            $query,
            [ 'max_tokens' => 2000 ]
        );

        return ! empty( $result['context'] ) ? $result['context'] : '';
    }

    /* ================================================================
     *  Introspection — for Monitor / Debug
     * ================================================================ */

    /**
     * Get a summary of all registered providers for the dashboard.
     *
     * @return array
     */
    public function get_summary() {
        $summary = [];
        foreach ( $this->providers as $id => $provider ) {
            $summary[] = [
                'id'            => $id,
                'name'          => $provider->get_name(),
                'goals'         => $provider->get_owned_goals(),
                'tools'         => array_keys( $provider->get_tools() ),
                'patterns'      => count( $provider->get_goal_patterns() ),
                'plans'         => count( $provider->get_plans() ),
                'has_context'   => method_exists( $provider, 'build_context' ),
                'knowledge_id'  => $provider->get_knowledge_character_id(),
            ];
        }
        return $summary;
    }

    /**
     * Get providers count.
     *
     * @return int
     */
    public function count() {
        return count( $this->providers );
    }
}
