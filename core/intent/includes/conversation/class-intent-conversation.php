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
 * BizCity Intent — Conversation Manager
 *
 * Manages conversation lifecycle: create, resume, update slots, complete.
 * Each conversation tracks:
 *   - goal (what the user wants to achieve)
 *   - slots (parameters collected so far)
 *   - status (ACTIVE / WAITING_USER / COMPLETED / CLOSED / EXPIRED)
 *   - rolling_summary (condensed context of the conversation)
 *   - open_loops (unfinished sub-tasks)
 *
 * A conversation is identified by conversation_id (UUID).
 * Active lookup is by (user_id + channel) or (session_id + channel).
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Conversation {

    /** @var self|null */
    private static $instance = null;

    /** @var BizCity_Intent_Database */
    private $db;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->db = BizCity_Intent_Database::instance();
    }

    /* ================================================================
     *  Get or Create active conversation
     *
     *  Core entry point: finds the most recent active conversation
     *  for the user/channel, or creates a new one.
     * ================================================================ */

    /**
     * Get the active conversation or create one if none exists.
     *
     * @param int    $user_id      WordPress user ID (0 for guest).
     * @param string $channel      Channel identifier.
     * @param string $session_id   Session ID (for guests).
     * @param int    $character_id AI character ID.
     * @return array Normalized conversation data.
     */
    public function get_or_create( $user_id, $channel = 'webchat', $session_id = '', $character_id = 0 ) {
        $conv = $this->get_active( $user_id, $channel, $session_id );

        if ( $conv ) {
            return $conv;
        }

        // Create new conversation
        return $this->create( $user_id, $channel, $session_id, $character_id );
    }

    /**
     * Find the active conversation for user+channel.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return array|null Normalized data or null.
     */
    public function get_active( $user_id, $channel = 'webchat', $session_id = '' ) {
        $row = $this->db->find_active_conversation( $user_id, $channel, $session_id );

        if ( ! $row ) {
            return null;
        }

        return $this->normalize( $row );
    }

    /**
     * Create a new conversation.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @param int    $character_id
     * @param string $goal
     * @param array  $initial_slots
     * @return array Normalized conversation data.
     */
    public function create( $user_id, $channel = 'webchat', $session_id = '', $character_id = 0, $goal = '', $initial_slots = [] ) {
        $conversation_id = $this->db->insert_conversation( [
            'user_id'      => $user_id,
            'channel'       => $channel,
            'session_id'    => $session_id,
            'character_id'  => $character_id,
            'goal'          => $goal,
            'status'        => 'ACTIVE',
            'slots'         => $initial_slots,
        ] );

        if ( ! $conversation_id ) {
            return [
                'conversation_id' => '',
                'user_id'         => $user_id,
                'channel'         => $channel,
                'session_id'      => $session_id,
                'goal'            => $goal,
                'status'          => 'ACTIVE',
                'slots'           => $initial_slots,
                'waiting_for'     => '',
                'waiting_field'   => '',
                'rolling_summary' => '',
                'open_loops'      => [],
                'turn_count'      => 0,
                'error'           => 'Failed to create conversation',
            ];
        }

        $row = $this->db->get_conversation( $conversation_id );
        return $this->normalize( $row );
    }

    /* ================================================================
     *  O10: Stale conversation resume detection (v3.6.1)
     * ================================================================ */

    /**
     * Find the most recently expired or closed conversation with a goal
     * for the same user+channel+session. Used for resume confirmation.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return array|null Normalized conversation data or null.
     */
    public function find_recently_expired( $user_id, $channel, $session_id ) {
        $row = $this->db->find_expired_conversation( $user_id, $channel, $session_id );
        if ( ! $row || empty( $row->goal ) ) {
            return null;
        }
        return $this->normalize( $row );
    }

    /**
     * Find the most recently COMPLETED conversation with a goal (within 2 min).
     * Used for post-tool satisfaction detection.
     *
     * @since v4.0.0 Phase 13
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return array|null Normalized conversation or null.
     */
    public function find_recently_completed( $user_id, $channel, $session_id ) {
        $row = $this->db->find_recently_completed_conversation( $user_id, $channel, $session_id );
        if ( ! $row || empty( $row->goal ) ) {
            return null;
        }
        return $this->normalize( $row );
    }

    /* ================================================================
     *  Update operations
     * ================================================================ */

    /**
     * Set the goal for a conversation.
     *
     * @param string $conversation_id
     * @param string $goal        Goal identifier (e.g. 'daily_outlook', 'create_product').
     * @param string $goal_label  Human-readable label.
     * @return bool
     */
    public function set_goal( $conversation_id, $goal, $goal_label = '' ) {
        return $this->db->update_conversation( $conversation_id, [
            'goal'       => $goal,
            'goal_label' => $goal_label,
        ] );
    }

    /**
     * Update slots — merges new data into existing slots.
     *
     * @param string $conversation_id
     * @param array  $new_slots Key-value pairs to merge.
     * @return bool
     */
    public function update_slots( $conversation_id, array $new_slots ) {
        $conv = $this->db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            return false;
        }

        $current_slots = json_decode( $conv->slots_json ?: '{}', true ) ?: [];
        $merged        = array_merge( $current_slots, $new_slots );

        return $this->db->update_conversation( $conversation_id, [
            'slots_json' => wp_json_encode( $merged ),
        ] );
    }

    /**
     * Append items to a specific slot that is an array (e.g. card_images[]).
     *
     * @param string $conversation_id
     * @param string $slot_name
     * @param mixed  $value
     * @return bool
     */
    public function append_slot( $conversation_id, $slot_name, $value ) {
        $conv = $this->db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            return false;
        }

        $current_slots = json_decode( $conv->slots_json ?: '{}', true ) ?: [];

        if ( ! isset( $current_slots[ $slot_name ] ) || ! is_array( $current_slots[ $slot_name ] ) ) {
            $current_slots[ $slot_name ] = [];
        }

        if ( is_array( $value ) ) {
            $current_slots[ $slot_name ] = array_merge( $current_slots[ $slot_name ], $value );
        } else {
            $current_slots[ $slot_name ][] = $value;
        }

        return $this->db->update_conversation( $conversation_id, [
            'slots_json' => wp_json_encode( $current_slots ),
        ] );
    }

    /**
     * Set the conversation to WAITING_USER state.
     *
     * @param string $conversation_id
     * @param string $waiting_for   What we're waiting for: 'text' | 'image' | 'confirm' | 'choice'
     * @param string $waiting_field The specific slot field we need.
     * @return bool
     */
    public function set_waiting( $conversation_id, $waiting_for = 'text', $waiting_field = '' ) {
        return $this->db->update_conversation( $conversation_id, [
            'status'        => 'WAITING_USER',
            'waiting_for'   => $waiting_for,
            'waiting_field' => $waiting_field,
        ] );
    }

    /**
     * Resume conversation from WAITING_USER to ACTIVE.
     *
     * @param string $conversation_id
     * @return bool
     */
    public function resume( $conversation_id ) {
        return $this->db->update_conversation( $conversation_id, [
            'status'        => 'ACTIVE',
            'waiting_for'   => '',
            'waiting_field' => '',
        ] );
    }

    /**
     * Mark conversation as COMPLETED.
     *
     * @param string $conversation_id
     * @param string $final_summary
     * @return bool
     */
    public function complete( $conversation_id, $final_summary = '' ) {
        $update = [
            'status'       => 'COMPLETED',
            'completed_at' => current_time( 'mysql' ),
        ];
        if ( $final_summary ) {
            $update['rolling_summary'] = $final_summary;
        }
        return $this->db->update_conversation( $conversation_id, $update );
    }

    /**
     * Close conversation (user-initiated close or timeout).
     *
     * @param string $conversation_id
     * @return bool
     */
    public function close( $conversation_id ) {
        return $this->db->update_conversation( $conversation_id, [
            'status' => 'CLOSED',
        ] );
    }

    /**
     * Cancel conversation (user-initiated via close button in plugin focus mode).
     * Marks status as CANCELLED so the HIL loop stops and the cột Nhiệm vụ
     * shows that the user actively chose to abort this goal.
     *
     * @param string $conversation_id
     * @param string $reason  Optional cancellation reason.
     * @return bool
     */
    public function cancel( $conversation_id, $reason = 'user_cancel' ) {
        return $this->db->update_conversation( $conversation_id, [
            'status'          => 'CANCELLED',
            'rolling_summary' => $reason,
        ] );
    }

    /**
     * Update rolling summary.
     *
     * @param string $conversation_id
     * @param string $summary
     * @return bool
     */
    public function update_summary( $conversation_id, $summary ) {
        return $this->db->update_conversation( $conversation_id, [
            'rolling_summary' => $summary,
        ] );
    }

    /**
     * Increment turn count and touch last_activity_at.
     *
     * @param string $conversation_id
     * @return bool
     */
    public function increment_turn( $conversation_id ) {
        $conv = $this->db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            return false;
        }
        return $this->db->update_conversation( $conversation_id, [
            'turn_count' => $conv->turn_count + 1,
        ] );
    }

    /**
     * Add a turn (message) to the conversation.
     *
     * @param string $conversation_id
     * @param string $role        'user' | 'assistant' | 'system' | 'tool'
     * @param string $content     Message text.
     * @param array  $extra       Optional: attachments, intent, slots_delta, tool_calls, meta.
     * @return int|false Turn ID.
     */
    public function add_turn( $conversation_id, $role, $content, array $extra = [] ) {
        $turn_index = $this->db->count_turns( $conversation_id );

        $turn_id = $this->db->insert_turn( array_merge( $extra, [
            'conversation_id' => $conversation_id,
            'turn_index'      => $turn_index,
            'role'            => $role,
            'content'         => $content,
        ] ) );

        if ( $turn_id ) {
            $this->increment_turn( $conversation_id );
        }

        return $turn_id;
    }

    /**
     * Get conversation turns (message history).
     *
     * @param string $conversation_id
     * @param int    $limit
     * @return array
     */
    public function get_turns( $conversation_id, $limit = 50 ) {
        $rows = $this->db->get_turns( $conversation_id, $limit );
        $result = [];

        foreach ( $rows as $row ) {
            $result[] = [
                'turn_index'  => (int) $row->turn_index,
                'role'        => $row->role,
                'content'     => $row->content,
                'attachments' => json_decode( $row->attachments ?: '[]', true ) ?: [],
                'intent'      => $row->intent,
                'slots_delta' => json_decode( $row->slots_delta ?: '{}', true ) ?: [],
                'tool_calls'  => json_decode( $row->tool_calls ?: '[]', true ) ?: [],
                'meta'        => json_decode( $row->meta ?: '{}', true ) ?: [],
                'created_at'  => $row->created_at,
            ];
        }

        return $result;
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Normalize a DB row to a clean array.
     *
     * @param object $row
     * @return array
     */
    private function normalize( $row ) {
        return [
            'conversation_id' => $row->conversation_id,
            'user_id'         => (int) $row->user_id,
            'session_id'      => $row->session_id,
            'channel'         => $row->channel,
            'character_id'    => (int) $row->character_id,
            'goal'            => $row->goal,
            'goal_label'      => $row->goal_label,
            'status'          => $row->status,
            'slots'           => json_decode( $row->slots_json ?: '{}', true ) ?: [],
            'slots_json'      => $row->slots_json ?: '{}',
            'waiting_for'     => $row->waiting_for,
            'waiting_field'   => $row->waiting_field,
            'rolling_summary' => $row->rolling_summary,
            'open_loops'      => $row->open_loops ?: '[]',
            'project_id'      => $row->project_id ?? '',
            'turn_count'      => (int) $row->turn_count,
            'created_at'      => $row->created_at,
            'updated_at'      => $row->updated_at,
            'last_activity_at' => $row->last_activity_at,
        ];
    }
}
