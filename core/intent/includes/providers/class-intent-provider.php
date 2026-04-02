<?php
/**
 * BizCity Intent — Skill Provider (Abstract Base)
 *
 * Each plugin that wants to add "skills" to the AI Agent implements a Provider.
 * A Provider contributes 3 things:
 *
 *   1. **Intents** (goal patterns)  — regex → goal mapping for the Router
 *   2. **Plans**   (slot schemas)   — required/optional slots + tool binding for the Planner
 *   3. **Tools**   (action callables) — actual execution functions
 *
 * Additionally, a Provider can supply **domain context** for the AI brain
 * when the engine needs to compose an answer (e.g., natal chart data for
 * astro questions, card meanings for tarot, etc.).
 *
 * Lifecycle:
 *   1. Plugin creates a Provider subclass
 *   2. On `bizcity_intent_register_providers` action, register it with the Registry
 *   3. Registry automatically merges patterns/plans/tools into the engine
 *   4. When a goal owned by this provider needs AI compose → build_context() is called
 *
 * @package BizCity_Intent
 * @since   1.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

abstract class BizCity_Intent_Provider {

    /* ================================================================
     *  Identity (REQUIRED)
     * ================================================================ */

    /**
     * Unique provider ID (slug). E.g., 'tarot', 'bizcoach', 'admin-hook'.
     *
     * @return string
     */
    abstract public function get_id();

    /**
     * Human-readable name. E.g., 'Tarot Reading', 'BizCoach Map'.
     *
     * @return string
     */
    abstract public function get_name();

    /* ================================================================
     *  Registration hooks — override to supply intents, plans, tools
     * ================================================================ */

    /**
     * Goal patterns for the Router.
     *
     * Return an associative array:
     *   regex_pattern => [
     *       'goal'            => 'goal_id',
     *       'label'           => 'Human label',
     *       'description'     => 'What this goal does',
     *       'extract'         => [ 'slot1', 'slot2' ],
     *
     *       // ── Fair Competition fields (v5.0) ──
     *       'specificity'     => 'narrow',           // 'exact' | 'narrow' | 'broad' (default)
     *                                                //   exact  = 0.95 conf (slash-level precision)
     *                                                //   narrow = 0.90 conf (domain keywords only)
     *                                                //   broad  = 0.65 conf (generic question words)
     *       'negative'        => '/regex/ui',         // Optional: skip if message matches this
     *       'domain_keywords' => [ 'kw1', 'kw2' ],   // Optional: require ≥1 keyword present,
     *                                                 //   otherwise cap confidence at 0.50
     *   ]
     *
     * Specificity tiers ensure narrow/exact patterns always win over broad
     * ones when multiple providers match the same message. Providers with
     * broad patterns should declare 'domain_keywords' to avoid hijacking
     * unrelated intents.
     *
     * @return array
     */
    public function get_goal_patterns() {
        return [];
    }

    /**
     * Execution plans for the Planner.
     *
     * Return an associative array:
     *   goal_id => [
     *       'required_slots' => [ ... ],
     *       'optional_slots' => [ ... ],
     *       'tool'           => 'tool_name' | null,
     *       'ai_compose'     => true|false,
     *       'slot_order'     => [ ... ],
     *   ]
     *
     * @return array
     */
    public function get_plans() {
        return [];
    }

    /**
     * Tool definitions + callables.
     *
     * Return an associative array:
     *   tool_name => [
     *       'label'    => 'Human label',
     *       'callback' => callable,        // fn(array $slots) => array
     *       'slots'    => [ ... ],         // Optional: slot schema
     *
     *       // ── Phase 1 Unified Pipeline (optional, recommended) ──
     *       'trust_tier'   => 0-4,         // TIER 0=auto, 1=quick-confirm, 2=preview,
     *                                      //       3=default, 4=block. Default: 4
     *       'tool_type'    => 'atomic'|'package',  // 'package' = multi-step composite
     *       'sub_tools'    => ['tool_a','tool_b'],  // Only when tool_type='package'
     *       'input_fields' => [             // Typed I/O — enables deterministic mapping
     *           'field_name' => [
     *               'type'     => 'string|int|url|html|json|array|file_url|image_url',
     *               'required' => true|false,
     *               'prompt'   => 'Ask-text khi thiếu field',
     *           ],
     *       ],
     *       'output_fields' => [            // Declared outputs — enables tool chaining
     *           'field_name' => [
     *               'type' => 'string|int|url|html|json|array|file_url|image_url',
     *           ],
     *       ],
     *   ]
     *
     * Each callback MUST return:
     *   [ 'success' => bool, 'complete' => bool, 'message' => string, 'data' => array ]
     *
     * @return array
     */
    public function get_tools() {
        return [];
    }

    /* ================================================================
     *  Profile Context — user profile data for AI personalization
     * ================================================================ */

    /**
     * Get the URL of the frontend profile page for this agent plugin.
     *
     * Each agent plugin creates a WP Page (via Template Page header) where users
     * declare their profile data (birth info, preferences, etc.). This URL is
     * used for:
     *   - Touch Bar iframe content
     *   - Fallback link when user profile is incomplete
     *   - AI can guide user to fill in missing data
     *
     * Override in subclass to return the profile page URL.
     *
     * @return string  Full URL to profile page (empty = no profile page).
     * @since  1.4.0
     */
    public function get_profile_page_url() {
        return '';
    }

    /**
     * Build user profile context for the AI system prompt.
     *
     * Called by the Intent Engine to inject user-specific profile data into
     * the AI prompt. This enables personalized responses based on user's
     * declared information (e.g., birth data for astro, preferences for tarot).
     *
     * If the user has NOT completed their profile, return a fallback message
     * with a link to the profile page so the AI can guide them.
     *
     * @param int $user_id  WordPress user ID.
     * @return array {
     *     @type bool   $complete   Whether profile is sufficiently filled.
     *     @type string $context    Profile context text (for system prompt).
     *     @type string $fallback   Message to display if profile is incomplete.
     * }
     * @since 1.4.0
     */
    public function get_profile_context( $user_id ) {
        return [
            'complete' => false,
            'context'  => '',
            'fallback' => '',
        ];
    }

    /* ================================================================
     *  Context building — called when AI needs domain knowledge
     * ================================================================ */

    /**
     * Get the bizcity-knowledge character_id linked to this plugin agent.
     *
     * Each plugin agent can have its own knowledge base (FAQ, files, URLs)
     * stored in bizcity-knowledge as a Character. This method returns the
     * character_id so the engine can load plugin-specific RAG context.
     *
     * Override in subclass or use wp_options:
     *   get_option( 'bz{prefix}_knowledge_character_id', 0 )
     *
     * @return int  Character ID (0 = no knowledge binding).
     * @since 1.3.0
     */
    public function get_knowledge_character_id() {
        return 0;
    }

    /**
     * Build domain-specific context for the AI system prompt.
     *
     * Called when the engine's action is 'compose_answer' and this provider
     * owns the active goal. The returned string is injected into the AI's
     * system prompt so it can give an informed, context-aware answer.
     *
     * Examples:
     *   - Tarot provider: card meanings, spread layout, user's question
     *   - BizCoach provider: natal chart, transit aspects, coach template
     *   - Voice chat provider: conversation history, user preferences
     *
     * @param string $goal         Active goal ID.
     * @param array  $slots        Current slot values.
     * @param int    $user_id      WordPress user ID.
     * @param array  $conversation Full conversation record.
     * @return string  Context text to inject (empty string = nothing to add).
     */
    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        return '';
    }

    /**
     * Build system prompt instructions specific to this provider's domain.
     *
     * Unlike build_context() which provides DATA, this provides INSTRUCTIONS
     * for how the AI should behave when handling this provider's goals.
     *
     * @param string $goal  Active goal ID.
     * @return string  System instruction text (empty = use default).
     */
    public function get_system_instructions( $goal ) {
        return '';
    }

    /* ================================================================
     *  Ownership check
     * ================================================================ */

    /**
     * List of goal IDs this provider owns.
     * Auto-derived from get_goal_patterns() + get_plans().
     *
     * @return array
     */
    public function get_owned_goals() {
        $goals = [];

        foreach ( $this->get_goal_patterns() as $pattern => $config ) {
            if ( ! empty( $config['goal'] ) ) {
                $goals[] = $config['goal'];
            }
        }

        foreach ( $this->get_plans() as $goal_id => $plan ) {
            if ( ! in_array( $goal_id, $goals, true ) ) {
                $goals[] = $goal_id;
            }
        }

        return $goals;
    }

    /**
     * Check if this provider owns a given goal.
     *
     * @param string $goal
     * @return bool
     */
    public function owns_goal( $goal ) {
        return in_array( $goal, $this->get_owned_goals(), true );
    }

    /* ================================================================
     *  Example prompts — for Tools Map hints
     * ================================================================ */

    /**
     * Get example prompts / hints for display in the Tools Map page.
     *
     * Return an associative array keyed by tool_name or goal_id:
     *   tool_name_or_goal => [
     *       'Vẽ mindmap về Digital Marketing',
     *       'Tạo flowchart quy trình tuyển dụng',
     *       ...
     *   ]
     *
     * These are stored in `examples_json` in the tool registry and rendered
     * as clickable hint chips on the Tools Map page.
     *
     * @return array  [ tool_name => string[] ]
     */
    public function get_examples() {
        return [];
    }
}
