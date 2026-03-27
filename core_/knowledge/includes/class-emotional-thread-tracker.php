<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Emotional Thread Tracker — Tracks emotional/life topics across conversations
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Uses bizcity_memory_users table with memory_type = 'emotional_thread'.
 *
 * Thread lifecycle (stored in metadata JSON):
 *   OPEN      → emotionally active, AI should acknowledge if topic resurfaces
 *   RESOLVED  → user indicated the issue is resolved / has moved on
 *   EXPIRED   → no activity for EXPIRE_DAYS days → auto-archived
 *   RECURRING → topic reopened after resolve/expire → bump recurrence_count
 *
 * Lifecycle methods:
 *   open_thread()   — create or reopen a thread
 *   resolve_thread() — mark as resolved
 *   expire_threads() — batch expire stale threads (cron / session-end)
 *   get_open_threads() — fetch all active threads for a user
 *   get_followup_due() — threads where follow_up_after <= NOW
 *
 * @package  BizCity_Knowledge
 * @version  1.0.0
 * @since    2026-03-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Emotional_Thread_Tracker {

    /* ── Singleton ─────────────────────────────────────────── */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ── Constants ──────────────────────────────────────────── */
    const MEMORY_TYPE   = 'emotional_thread';
    const STATUS_OPEN      = 'open';
    const STATUS_RESOLVED  = 'resolved';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_RECURRING = 'recurring';

    const EXPIRE_DAYS      = 14;   // auto-expire thread if no activity for N days
    const FOLLOWUP_HOURS   = 48;   // default follow-up window in hours

    /* ── Constructor ────────────────────────────────────────── */
    private function __construct() {
        // Auto-expire stale threads at session start (lightweight check)
        add_action( 'bizcity_session_start', [ $this, 'expire_threads' ], 10, 1 );
        add_action( 'bizcity_session_end',   [ $this, 'expire_threads' ], 10, 1 );
    }

    /* ================================================================
     * OPEN — create a new thread or reopen an existing one
     *
     * @param int    $user_id
     * @param string $session_id
     * @param string $topic       Short identifier, e.g. "job_stress"
     * @param array  $args {
     *   intensity       int    1-5
     *   valence         string 'pos'|'neg'|'neutral'
     *   description     string human-readable description
     *   follow_up_hours int    hours until AI suggests follow-up (default 48)
     * }
     * @return string|false  'insert', 'update', or false
     * ================================================================ */
    public function open_thread( $user_id, $session_id, $topic, $args = [] ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return false;
        }

        $args = wp_parse_args( $args, [
            'intensity'       => 3,
            'valence'         => 'neutral',
            'description'     => $topic,
            'follow_up_hours' => self::FOLLOWUP_HOURS,
        ] );

        $key    = self::MEMORY_TYPE . ':' . sanitize_key( $topic );
        $now_ts = time();

        // Check if thread already exists
        $existing = $this->get_thread( $user_id, $key );
        $was_resolved_or_expired = $existing
            && in_array( $existing['status'], [ self::STATUS_RESOLVED, self::STATUS_EXPIRED ], true );

        $status    = $was_resolved_or_expired ? self::STATUS_RECURRING : self::STATUS_OPEN;
        $recur_cnt = $existing ? intval( isset( $existing['recurrence_count'] ) ? $existing['recurrence_count'] : 0 ) + ( $was_resolved_or_expired ? 1 : 0 ) : 0;

        $metadata = [
            'status'           => $status,
            'topic'            => $topic,
            'intensity'        => (int) $args['intensity'],
            'valence'          => $args['valence'],
            'opened_at'        => ( $existing && isset( $existing['opened_at'] ) ) ? $existing['opened_at'] : date( 'Y-m-d H:i:s', $now_ts ),
            'last_activity_at' => date( 'Y-m-d H:i:s', $now_ts ),
            'follow_up_after'  => date( 'Y-m-d H:i:s', $now_ts + $args['follow_up_hours'] * 3600 ),
            'recurrence_count' => $recur_cnt,
        ];

        $description = $args['description'] ?: $topic;

        return BizCity_User_Memory::instance()->upsert_public( [
            'user_id'     => (int) $user_id,
            'session_id'  => '',   // threads are global
            'memory_tier' => 'extracted',
            'memory_type' => self::MEMORY_TYPE,
            'memory_key'  => $key,
            'memory_text' => "[Thread: {$status}] {$description}",
            'score'       => 60 + (int) $args['intensity'] * 6,   // 66-90
            'metadata'    => wp_json_encode( $metadata ),
        ] );
    }

    /* ================================================================
     * RESOLVE — mark a thread as resolved
     *
     * @param int    $user_id
     * @param string $topic   Same topic string passed to open_thread()
     * @return bool
     * ================================================================ */
    public function resolve_thread( $user_id, $topic ) {
        global $wpdb;

        $key     = self::MEMORY_TYPE . ':' . sanitize_key( $topic );
        $table   = $wpdb->prefix . 'bizcity_memory_users';
        $blog_id = get_current_blog_id();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, metadata FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_key = %s LIMIT 1",
            $blog_id,
            (int) $user_id,
            $key
        ) );

        if ( ! $row ) {
            return false;
        }

        $meta               = json_decode( $row->metadata, true ) ?: [];
        $meta['status']     = self::STATUS_RESOLVED;
        $meta['resolved_at'] = current_time( 'mysql' );

        $wpdb->update(
            $table,
            [
                'metadata'   => wp_json_encode( $meta ),
                'memory_text' => "[Thread: resolved] {$meta['topic']}",
                'score'      => 40,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $row->id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /* ================================================================
     * EXPIRE — auto-expire threads with no activity for EXPIRE_DAYS
     *
     * Called on session start/end. Uses a transient lock to avoid
     * running more than once per hour per user.
     *
     * @param int $user_id
     * ================================================================ */
    public function expire_threads( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $lock_key = 'bizcity_thread_expire_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, HOUR_IN_SECONDS );

        global $wpdb;
        $table      = $wpdb->prefix . 'bizcity_memory_users';
        $blog_id    = get_current_blog_id();
        $cutoff     = date( 'Y-m-d H:i:s', time() - self::EXPIRE_DAYS * DAY_IN_SECONDS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, metadata FROM {$table}
             WHERE blog_id = %d AND user_id = %d AND memory_type = %s
             AND updated_at < %s",
            $blog_id, $user_id, self::MEMORY_TYPE, $cutoff
        ) );

        foreach ( (array) $rows as $row ) {
            $meta = json_decode( $row->metadata, true ) ?: [];
            if ( isset( $meta['status'] ) && $meta['status'] === self::STATUS_OPEN ) {
                $meta['status']     = self::STATUS_EXPIRED;
                $meta['expired_at'] = current_time( 'mysql' );
                $wpdb->update(
                    $table,
                    [ 'metadata' => wp_json_encode( $meta ), 'score' => 20, 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $row->id ],
                    [ '%s', '%d', '%s' ],
                    [ '%d' ]
                );
            }
        }
    }

    /* ================================================================
     * GET OPEN THREADS — active threads for context injection
     *
     * @param int    $user_id
     * @param int    $limit
     * @return array  of decoded metadata arrays with added 'memory_text' key
     * ================================================================ */
    public function get_open_threads( $user_id, $limit = 5 ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return [];
        }

        $rows = BizCity_User_Memory::instance()->get_memories( [
            'user_id'     => (int) $user_id,
            'session_id'  => '',
            'memory_type' => self::MEMORY_TYPE,
            'limit'       => 20,
            'order_by'    => 'score',
        ] );

        $open = [];
        foreach ( $rows as $row ) {
            $meta = json_decode( $row->metadata, true ) ?: [];
            $status = isset( $meta['status'] ) ? $meta['status'] : self::STATUS_OPEN;
            if ( in_array( $status, [ self::STATUS_OPEN, self::STATUS_RECURRING ], true ) ) {
                $meta['memory_text'] = $row->memory_text;
                $meta['memory_key']  = $row->memory_key;
                $open[] = $meta;
            }
        }

        return array_slice( $open, 0, $limit );
    }

    /* ================================================================
     * GET FOLLOWUP DUE — threads where AI should gently check in
     *
     * @param int $user_id
     * @return array  of metadata arrays with follow_up_after in the past
     * ================================================================ */
    public function get_followup_due( $user_id ) {
        $open = $this->get_open_threads( $user_id, 10 );
        $now  = time();
        $due  = [];

        foreach ( $open as $meta ) {
            if ( empty( $meta['follow_up_after'] ) ) {
                continue;
            }
            if ( strtotime( $meta['follow_up_after'] ) <= $now ) {
                $due[] = $meta;
            }
        }

        return $due;
    }

    /* ================================================================
     * HELPER — get a single thread by key (internal)
     * ================================================================ */
    private function get_thread( $user_id, $memory_key ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'bizcity_memory_users';
        $blog_id = get_current_blog_id();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT metadata FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_key = %s LIMIT 1",
            $blog_id, (int) $user_id, $memory_key
        ) );

        if ( ! $row ) {
            return null;
        }

        return json_decode( $row->metadata, true ) ?: [];
    }
}
