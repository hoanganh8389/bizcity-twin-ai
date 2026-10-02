<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin Snapshot Builder — Build state object từ mọi event.
 *
 * Snapshot JSON chuẩn cho toàn bộ hệ thống.
 * Cached per user per request. Invalidated on events.
 *
 * Snapshot KHÔNG PHẢI prompt — là state object chuẩn.
 * Prompt cho LLM chỉ là bản render rút gọn từ snapshot.
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Snapshot_Builder {

    /** @var array Per-request cache: key = "{user_id}_{session_id}" */
    private static $cache = [];

    /**
     * Event taxonomy map — references the canonical taxonomy from Data Contract.
     * Used to tag snapshot invalidation sources.
     *
     * @see BizCity_Twin_Data_Contract::event_taxonomy()
     */
    const INVALIDATION_EVENTS = [
        'bizcity_webchat_message_saved'  => 'message_received',
        'bizcity_intent_processed'       => 'goal_progressed',
        'bizcity_chat_message_processed' => 'message_received',
        'bcn_note_created'               => 'note_created',
        'bcn_note_updated'               => 'note_created',
        'bcn_source_added'               => 'knowledge_attached',
        'bizcity_knowledge_ingested'     => 'knowledge_attached',
        'bizcity_tool_registry_changed'  => 'tool_executed',
    ];

    /**
     * Build a full snapshot for a user.
     *
     * @param int    $user_id
     * @param string $session_id
     * @return array Snapshot matching the Twin Snapshot Schema
     */
    public static function build( int $user_id, string $session_id = '' ): array {
        $key = $user_id . '_' . $session_id;
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        $snapshot = [
            'twin_id'      => 'twin_u' . $user_id,
            'version'      => 1,
            'as_of'        => current_time( 'c' ),
            'trace_id'     => 'trace_' . wp_generate_uuid4(),
            'identity'     => self::build_identity( $user_id ),
            'focus'        => self::build_focus( $user_id, $session_id ),
            'timeline'     => self::build_timeline( $user_id, $session_id ),
            'journeys'     => self::build_journeys( $user_id ),
            'memory_refs'  => self::build_memory_refs( $user_id, $session_id ),
            'mode_context' => [], // filled by Context Resolver after mode classification
        ];

        self::$cache[ $key ] = $snapshot;
        return $snapshot;
    }

    /**
     * Invalidate cache (called by event hooks).
     */
    public static function invalidate(): void {
        self::$cache = [];
    }

    /* ================================================================
     * IDENTITY — Who is this user + support style + bond
     * ================================================================ */
    private static function build_identity( int $user_id ): array {
        $user = get_userdata( $user_id );
        $identity = [
            'user_id'              => $user_id,
            'display_name'         => $user ? $user->display_name : '',
            'support_style'        => 'direct_but_warm', // default, refined by Learning Loop later
            'bond_score'           => 5,                  // default
            'preferences'          => [],
            'life_goal_hypotheses' => [],
        ];

        // Enrich from BizCoach profile if available
        if ( function_exists( 'bccm_get_coachee' ) ) {
            $coachee = bccm_get_coachee( $user_id );
            if ( $coachee ) {
                $identity['display_name'] = $coachee->full_name ?: $identity['display_name'];
            }
        }

        // [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14d / R-VERTICAL-AXIS R-VA-6 (CUT, owner 2026-09-30) — companion bond score retired; bond_score keeps its default.

        // Enrich preferences from explicit user memory
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem = BizCity_User_Memory::instance();
            $explicits = $mem->get_memories( [
                'user_id'     => $user_id,
                'memory_tier' => 'explicit',
                'limit'       => 10,
            ] );
            foreach ( $explicits as $m ) {
                $identity['preferences'][] = $m->memory_text;
            }
        }

        return $identity;
    }

    /* ================================================================
     * FOCUS — What is the user currently focused on
     * ================================================================ */
    /**
     * [2026-09-25 Claude Opus 5.5] WP-11 C3b — focus only (active TwinBrain goal), for callers that do not need a full snapshot.
     */
    public static function focus( int $user_id, string $session_id = '' ): array {
        $key = $user_id . '_' . $session_id;
        if ( isset( self::$cache[ $key ]['focus'] ) ) {
            return self::$cache[ $key ]['focus'];
        }
        return self::build_focus( $user_id, $session_id );
    }

    private static function build_focus( int $user_id, string $session_id ): array {
        $focus = [
            'current_focus'     => null,
            'open_loops'        => [],
            'suppression_list'  => [],
            'next_best_actions' => [],
        ];

        // [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C3b (D-26, R-IM-4) — focus comes from the TwinBrain
        // goal loop (event-sourced in twin_event_stream), not from bizcity_intent_conversations, which nothing writes
        // any more. Read-only: identity resolve + one cached, bounded goal scan.
        if ( $user_id <= 0
            || ! class_exists( 'BizCity_Identity_Hub' )
            || ! method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'latest_active_by_identity' ) ) {
            return $focus;
        }
        $blog_id  = (int) get_current_blog_id();
        $identity = BizCity_Identity_Hub::resolve_from_opts( [ 'user_id' => $user_id ], $blog_id );
        $uuid     = is_array( $identity ) ? (string) ( $identity['identity_uuid'] ?? '' ) : '';
        if ( $uuid === '' ) {
            return $focus;
        }
        $goal = BizCity_TwinBrain_Goal_Loop_Repository::latest_active_by_identity( $blog_id, $uuid );
        if ( empty( $goal ) || ! is_array( $goal ) ) {
            return $focus;
        }
        $goal_id = (string) ( $goal['goal_id'] ?? '' );
        $primary = (string) ( $goal['goal_title'] ?? '' );
        $primary = $primary !== '' ? $primary : (string) ( $goal['primary_goal'] ?? '' );
        if ( $primary !== '' ) {
            $focus['open_loops'][] = [ 'type' => 'twin_goal', 'label' => $primary, 'id' => $goal_id ];
        }
        foreach ( array_slice( (array) ( $goal['open_loops'] ?? [] ), 0, 4 ) as $item ) {
            $label = is_array( $item ) ? (string) ( $item['label'] ?? '' ) : (string) $item;
            if ( $label !== '' ) {
                $focus['open_loops'][] = [ 'type' => 'twin_goal_loop', 'label' => $label, 'id' => $goal_id ];
            }
        }
        if ( ! empty( $goal['next_best_action'] ) && is_array( $goal['next_best_action'] ) && ! empty( $goal['next_best_action']['label'] ) ) {
            $focus['next_best_actions'][] = [ 'label' => (string) $goal['next_best_action']['label'], 'source' => 'twin_goal' ];
        }
        if ( ! empty( $focus['open_loops'] ) ) {
            $top = $focus['open_loops'][0];
            $focus['current_focus'] = [
                'type'    => $top['type'],
                'label'   => $top['label'],
                'score'   => 0.8,
                'why_now' => [ ( $goal['session_id'] ?? '' ) === $session_id && $session_id !== '' ? 'active_goal_this_session' : 'active_goal_other_session' ],
            ];
        }

        return $focus;
    }

    /* ================================================================
     * TIMELINE — What happened today + recent events
     * ================================================================ */
    private static function build_timeline( int $user_id, string $session_id ): array {
        $timeline = [
            'today_context'  => [],
            'recent_events'  => [],
            'due_followups'  => [],
            'active_threads' => [],
        ];

        global $wpdb;

        // Recent messages as today_context
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        // [2026-06-28 Johnny Chu] R-SHOW-TABLES — information_schema + wp_cache dual cache
        $_ck_wm = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
        $_p_wm  = wp_cache_get( $_ck_wm, 'bizcity_tbl' );
        if ( false === $_p_wm ) {
            $_p_wm = (int) (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $table
            ) );
            wp_cache_set( $_ck_wm, $_p_wm, 'bizcity_tbl', HOUR_IN_SECONDS );
        }
        if ( $_p_wm ) {
            $today = current_time( 'Y-m-d' );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT message_from, LEFT(message_text, 80) AS preview, created_at
                 FROM {$table}
                 WHERE user_id = %d AND DATE(created_at) = %s AND status = 'visible'
                 ORDER BY id DESC LIMIT 10",
                $user_id, $today
            ) );
            foreach ( array_reverse( $rows ) as $row ) {
                $timeline['today_context'][] = [
                    'time'    => wp_date( 'H:i', strtotime( $row->created_at ) ),
                    'type'    => 'chat',
                    'summary' => $row->preview,
                ];
            }
        }

        // [2026-09-30 Claude Opus 5.5] CORE-REDUCTION R14d / R-VERTICAL-AXIS R-VA-6 (CUT, owner 2026-09-30) — emotional threads retired (this block also used the undefined
        // constant TYPE_THREAD on the emotional-memory class, a latent fatal).

        return $timeline;
    }

    /* ================================================================
     * JOURNEYS — Long-term journey tracking (Phase 4 — stub)
     * ================================================================ */
    private static function build_journeys( int $user_id ): array {
        // Phase 4 — Will have bizcity_twin_journeys table
        return [];
    }

    /* ================================================================
     * MEMORY_REFS — Pointers to related memories/notes/sources
     * ================================================================ */
    private static function build_memory_refs( int $user_id, string $session_id ): array {
        return [
            'user_memory_ids'         => [],
            'episodic_ids'            => [],
            'rolling_ids'             => [],
            'note_ids'                => [],
            'source_ids'              => [],
            'intent_conversation_ids' => [],
        ];
    }
}
