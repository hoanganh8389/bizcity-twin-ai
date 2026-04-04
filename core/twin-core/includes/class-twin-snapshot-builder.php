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

        // Enrich bond from Companion Emotional Memory
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $bond = BizCity_Emotional_Memory::instance()->get_bond_score( $user_id );
            if ( $bond ) {
                $identity['bond_score'] = $bond;
            }
        }

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
    private static function build_focus( int $user_id, string $session_id ): array {
        $focus = [
            'current_focus'     => null,
            'open_loops'        => [],
            'suppression_list'  => [],
            'next_best_actions' => [],
        ];

        // From active intent conversations
        if ( class_exists( 'BizCity_Intent_Database' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_intent_conversations';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
                $active = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, goal, status, created_at
                     FROM {$table}
                     WHERE user_id = %d AND status IN ('active','pending_slots')
                     ORDER BY updated_at DESC LIMIT 5",
                    $user_id
                ) );
                foreach ( $active as $conv ) {
                    if ( ! empty( $conv->goal ) ) {
                        $focus['open_loops'][] = [
                            'type'  => 'intent_goal',
                            'label' => $conv->goal,
                            'id'    => $conv->id,
                        ];
                    }
                }
                if ( ! empty( $focus['open_loops'] ) ) {
                    $top = $focus['open_loops'][0];
                    $focus['current_focus'] = [
                        'type'    => $top['type'],
                        'label'   => $top['label'],
                        'score'   => 0.8,
                        'why_now' => [ 'most_recent_active_intent' ],
                    ];
                }
            }
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
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
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

        // Emotional threads as active_threads
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $em_instance = BizCity_Emotional_Memory::instance();
            $threads = $em_instance->get_emotional(
                $user_id, '', BizCity_Emotional_Memory::TYPE_THREAD, 4
            );
            foreach ( $threads as $t ) {
                $timeline['active_threads'][] = [
                    'topic'  => $t->memory_text ?? '',
                    'status' => 'open',
                ];
            }
        }

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
