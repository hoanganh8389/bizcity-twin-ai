<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Plugin Agent Binding — Bi-directional binding between Intent Providers & Characters
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Manages binding between Plugin Agents (bizcity-intent Providers)
 * and Knowledge Characters.
 *
 * When a plugin registers an Intent Provider with a knowledge_character_id,
 * this class ensures:
 *   1. The character exists (auto-create if missing)
 *   2. The mapping is registered in wp_options for fast lookup
 *   3. Knowledge context is correctly routed to the owning plugin
 *   4. Admin UI shows linked knowledge sources per plugin
 *
 * Mapping format (stored in wp_option 'bizcity_knowledge_agent_bindings'):
 * {
 *   "provider_id": {
 *     "character_id": 123,
 *     "provider_name": "Tarot Agent",
 *     "plugin_type": "agent",
 *     "auto_created": true,
 *     "bound_at": "2026-02-27 10:00:00"
 *   }
 * }
 *
 * plugin_type values:
 *   - 'agent'  = AI Agent plugin (bizcity-intent Provider, has goals/tools/plans)
 *   - 'legacy' = Legacy WordPress plugin (no Provider, uses character only for knowledge)
 *
 * @package BizCity_Knowledge
 * @since   2.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Knowledge_Agent_Binding {

    /** @var self|null */
    private static $instance = null;

    /** Option name for binding map */
    const OPTION_KEY = 'bizcity_knowledge_agent_bindings';

    /** Plugin type constants */
    const TYPE_AGENT  = 'agent';   // AI Agent plugin (Intent Provider)
    const TYPE_LEGACY = 'legacy';  // Legacy WP plugin (character only)

    /** @var array Cached binding map */
    private $bindings = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Hook after providers are registered to sync bindings
        add_action( 'bizcity_intent_register_providers', [ $this, 'sync_bindings' ], 999 );

        // Admin: show agent binding info on character edit page
        add_action( 'bizcity_knowledge_character_meta', [ $this, 'render_agent_meta' ], 10, 1 );

        // Filter: enrich character list with agent info
        add_filter( 'bizcity_knowledge_character_list_item', [ $this, 'enrich_character_list' ], 10, 2 );
    }

    /* ================================================================
     * PUBLIC: Get all bindings
     *
     * @return array  provider_id => { character_id, provider_name, auto_created, bound_at }
     * ================================================================ */
    public function get_bindings() {
        if ( is_null( $this->bindings ) ) {
            $this->bindings = get_option( self::OPTION_KEY, [] );
            if ( ! is_array( $this->bindings ) ) {
                $this->bindings = [];
            }
        }
        return $this->bindings;
    }

    /* ================================================================
     * PUBLIC: Get binding for a specific provider
     *
     * @param string $provider_id
     * @return array|null
     * ================================================================ */
    public function get_binding( $provider_id ) {
        $bindings = $this->get_bindings();
        return $bindings[ $provider_id ] ?? null;
    }

    /* ================================================================
     * PUBLIC: Get character_id for a provider
     *
     * @param string $provider_id
     * @return int  Character ID (0 if not bound)
     * ================================================================ */
    public function get_character_id( $provider_id ) {
        $binding = $this->get_binding( $provider_id );
        return $binding ? intval( $binding['character_id'] ) : 0;
    }

    /* ================================================================
     * PUBLIC: Get provider_id for a character
     *
     * @param int $character_id
     * @return string|null  Provider ID, or null if not bound.
     * ================================================================ */
    public function get_provider_id( $character_id ) {
        $bindings = $this->get_bindings();
        foreach ( $bindings as $pid => $binding ) {
            if ( intval( $binding['character_id'] ) === intval( $character_id ) ) {
                return $pid;
            }
        }
        return null;
    }

    /* ================================================================
     * PUBLIC: Manually bind a provider to a character
     *
     * @param string $provider_id
     * @param int    $character_id
     * @param string $provider_name
     * @return bool
     * ================================================================ */
    public function bind( $provider_id, $character_id, $provider_name = '', $plugin_type = self::TYPE_AGENT ) {
        $bindings = $this->get_bindings();

        $bindings[ $provider_id ] = [
            'character_id'  => intval( $character_id ),
            'provider_name' => $provider_name,
            'plugin_type'   => in_array( $plugin_type, [ self::TYPE_AGENT, self::TYPE_LEGACY ] ) ? $plugin_type : self::TYPE_AGENT,
            'auto_created'  => false,
            'bound_at'      => current_time( 'mysql' ),
        ];

        $this->bindings = $bindings;
        return update_option( self::OPTION_KEY, $bindings );
    }

    /* ================================================================
     * PUBLIC: Unbind a provider
     *
     * @param string $provider_id
     * @return bool
     * ================================================================ */
    public function unbind( $provider_id ) {
        $bindings = $this->get_bindings();
        if ( ! isset( $bindings[ $provider_id ] ) ) {
            return true;
        }

        unset( $bindings[ $provider_id ] );
        $this->bindings = $bindings;
        return update_option( self::OPTION_KEY, $bindings );
    }

    /* ================================================================
     * HOOK: Sync bindings when providers register
     *
     * For each registered provider that declares a knowledge_character_id,
     * ensure the binding map is up-to-date.
     * For providers that declare `get_knowledge_character_id() = 0` but
     * expect auto-creation, create a character on the fly.
     *
     * @param BizCity_Intent_Provider_Registry $registry
     * ================================================================ */
    public function sync_bindings( $registry ) {
        if ( ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            return;
        }

        $providers = $registry->get_all();
        $bindings  = $this->get_bindings();
        $changed   = false;

        foreach ( $providers as $id => $provider ) {
            $declared_char_id = $provider->get_knowledge_character_id();

            if ( $declared_char_id > 0 ) {
                // Provider explicitly declares a character_id → always TYPE_AGENT
                if ( ! isset( $bindings[ $id ] ) || intval( $bindings[ $id ]['character_id'] ) !== $declared_char_id ) {
                    $bindings[ $id ] = [
                        'character_id'  => $declared_char_id,
                        'provider_name' => $provider->get_name(),
                        'plugin_type'   => self::TYPE_AGENT,
                        'auto_created'  => false,
                        'bound_at'      => current_time( 'mysql' ),
                    ];
                    $changed = true;
                }
            }
            // If provider returns 0 but already has a binding → keep it (admin set it manually)
        }

        if ( $changed ) {
            $this->bindings = $bindings;
            update_option( self::OPTION_KEY, $bindings );
        }
    }

    /* ================================================================
     * PUBLIC: Auto-create a character for a provider (if needed)
     *
     * @param string $provider_id
     * @param string $provider_name
     * @param string $description
     * @param string $system_prompt
     * @return int  Character ID
     * ================================================================ */
    public function auto_create_character( $provider_id, $provider_name, $description = '', $system_prompt = '' ) {
        if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
            return 0;
        }

        // Check if already bound
        $existing = $this->get_character_id( $provider_id );
        if ( $existing ) {
            return $existing;
        }

        $db = BizCity_Knowledge_Database::instance();

        // Create a character for this provider
        $slug = sanitize_title( 'agent-' . $provider_id );

        // Check if slug already exists
        $existing_char = $db->get_character_by_slug( $slug );
        if ( $existing_char ) {
            $char_id = intval( $existing_char->id );
        } else {
            $char_id = $db->create_character( [
                'name'        => $provider_name . ' — Knowledge Base',
                'slug'        => $slug,
                'description' => $description ?: "Knowledge base cho AI Agent: {$provider_name}",
                'system_prompt' => $system_prompt ?: "Bạn là {$provider_name}, một AI Agent chuyên biệt.",
                'status'      => 'active',
                'author_id'   => get_current_user_id() ?: 1,
            ] );
        }

        if ( $char_id && ! is_wp_error( $char_id ) ) {
            $bindings = $this->get_bindings();
            $bindings[ $provider_id ] = [
                'character_id'  => $char_id,
                'provider_name' => $provider_name,
                'plugin_type'   => self::TYPE_AGENT,
                'auto_created'  => true,
                'bound_at'      => current_time( 'mysql' ),
            ];
            $this->bindings = $bindings;
            update_option( self::OPTION_KEY, $bindings );
        }

        return intval( $char_id );
    }

    /* ================================================================
     * ADMIN: Render agent binding meta on character edit page
     *
     * @param object $character  Character row from DB.
     * ================================================================ */
    public function render_agent_meta( $character ) {
        $char_id     = intval( $character->id );
        $provider_id = $this->get_provider_id( $char_id );

        if ( ! $provider_id ) {
            return;
        }

        $binding = $this->get_binding( $provider_id );
        $ptype   = $binding['plugin_type'] ?? self::TYPE_AGENT;
        $badge   = $ptype === self::TYPE_AGENT
            ? '<span style="background:#10b981;color:#fff;padding:2px 8px;border-radius:8px;font-size:11px;">🤖 Agent</span>'
            : '<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:8px;font-size:11px;">📦 Legacy</span>';

        echo '<div class="bizcity-agent-binding-meta" style="background:#f0f8ff;border:1px solid #b8d4e3;padding:10px;margin:10px 0;border-radius:5px;">';
        echo '<strong>Plugin Binding:</strong> ';
        echo esc_html( $binding['provider_name'] ?? $provider_id ) . ' ' . $badge;
        if ( ! empty( $binding['auto_created'] ) ) {
            echo ' <span style="color:#999;">(auto-created)</span>';
        }
        echo '<br><small>Provider ID: <code>' . esc_html( $provider_id ) . '</code> | Type: <code>' . esc_html( $ptype ) . '</code></small>';
        echo '</div>';
    }

    /* ================================================================
     * FILTER: Enrich character list with agent binding info
     *
     * @param array  $item       Character list item.
     * @param object $character  Character row.
     * @return array
     * ================================================================ */
    public function enrich_character_list( $item, $character ) {
        $char_id     = intval( $character->id );
        $provider_id = $this->get_provider_id( $char_id );

        if ( $provider_id ) {
            $binding = $this->get_binding( $provider_id );
            $item['agent_id']    = $provider_id;
            $item['agent_name']  = $binding['provider_name'] ?? $provider_id;
            $item['plugin_type'] = $binding['plugin_type'] ?? self::TYPE_AGENT;
        }

        return $item;
    }

    /* ================================================================
     * PUBLIC: Get summary of all bindings (for Monitor/Debug)
     *
     * @return array
     * ================================================================ */
    public function get_summary() {
        $bindings = $this->get_bindings();
        $summary  = [];

        foreach ( $bindings as $pid => $binding ) {
            $char_name = '';
            if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
                $char = BizCity_Knowledge_Database::instance()->get_character( $binding['character_id'] );
                $char_name = $char ? $char->name : '(deleted)';
            }

            $summary[] = [
                'provider_id'    => $pid,
                'provider_name'  => $binding['provider_name'],
                'plugin_type'    => $binding['plugin_type'] ?? self::TYPE_AGENT,
                'character_id'   => $binding['character_id'],
                'character_name' => $char_name,
                'auto_created'   => $binding['auto_created'] ?? false,
                'bound_at'       => $binding['bound_at'] ?? '',
            ];
        }

        return $summary;
    }
}
