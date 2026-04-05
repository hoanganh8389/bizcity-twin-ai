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
 * BizCity Intent — Classification Cache (SQL-based)
 *
 * Lưu kết quả phân loại (mode + intent) vào SQL thay vì gọi LLM mỗi lần.
 * Khi tin nhắn tương tự + context tương tự xuất hiện lại → trả kết quả từ cache,
 * tiết kiệm 1-2 LLM calls (~200-600ms + cost).
 *
 * Cache Key = SHA256( normalize(message) + context_fingerprint )
 * Context Fingerprint = goal + status + waiting_field + provider_hint
 *
 * Cache Layers:
 *   Layer 1 (Mode)   — mode + confidence + is_memory
 *   Layer 2 (Intent) — intent + goal + entities + suggested_tools + missing_fields
 *
 * TTL:
 *   - Mode cache: 7 days (mode classification rarely changes)
 *   - Intent cache: 3 days (goal patterns may evolve faster)
 *   - High-confidence (≥ 0.85): extended TTL × 2
 *   - Auto-cleanup: records older than 30 days or hit_count=0 after 7 days
 *
 * @package BizCity_Intent
 * @since   3.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Classify_Cache {

    /** @var self|null */
    private static $instance = null;

    /** @var wpdb */
    private $wpdb;

    /** @var string Table name */
    private $table;

    /** TTL constants (seconds) */
    const MODE_TTL          = 604800;   // 7 days
    const INTENT_TTL        = 259200;   // 3 days
    const HIGH_CONF_MULT    = 2;        // ×2 for confidence ≥ 0.85
    const CLEANUP_AGE_DAYS  = 30;       // Auto-delete after 30 days
    const CLEANUP_IDLE_DAYS = 7;        // Delete 0-hit after 7 days

    /** Minimum confidence to cache — raised from 0.60 to avoid caching borderline results */
    const MIN_CACHE_CONFIDENCE = 0.70;

    /** In-memory cache for current request (avoid repeated SQL queries) */
    private $request_cache = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'bizcity_intent_classify_cache';
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public function get_table() {
        return $this->table;
    }

    /* ================================================================
     *  CREATE TABLE
     * ================================================================ */

    /**
     * Create/update cache table. Called from DB migration.
     */
    public function maybe_create_table() {
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_key       VARCHAR(64)  NOT NULL,
            message_norm    VARCHAR(500) NOT NULL DEFAULT '',
            context_hash    VARCHAR(64)  NOT NULL DEFAULT '',

            -- Layer 1: Mode classification
            mode            VARCHAR(30)  DEFAULT NULL,
            mode_confidence DECIMAL(4,3) DEFAULT 0.000,
            is_memory       TINYINT(1)   DEFAULT 0,

            -- Layer 2: Intent classification
            intent          VARCHAR(30)  DEFAULT NULL,
            goal            VARCHAR(100) DEFAULT NULL,
            goal_label      VARCHAR(255) DEFAULT NULL,
            entities_json   LONGTEXT     DEFAULT NULL,
            intent_confidence DECIMAL(4,3) DEFAULT 0.000,
            suggested_tools TEXT         DEFAULT NULL,
            missing_fields  TEXT         DEFAULT NULL,
            goal_objective  TEXT         DEFAULT NULL,

            -- Meta
            hit_count       INT UNSIGNED DEFAULT 0,
            last_hit_at     DATETIME     DEFAULT NULL,
            source_model    VARCHAR(128) DEFAULT '',
            created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            expires_at      DATETIME     DEFAULT NULL,

            UNIQUE KEY uk_cache_key (cache_key),
            KEY idx_message (message_norm(120)),
            KEY idx_expires (expires_at),
            KEY idx_goal (goal),
            KEY idx_hits (hit_count)
        ) {$charset};";

        $this->wpdb->query( $sql );
    }

    /* ================================================================
     *  CACHE KEY GENERATION
     * ================================================================ */

    /**
     * Normalize message for cache key generation.
     * Lowercase, trim, collapse whitespace, remove punctuation duplicates.
     *
     * @param string $message
     * @return string
     */
    public function normalize_message( $message ) {
        $text = mb_strtolower( trim( $message ), 'UTF-8' );
        // Collapse multiple spaces/newlines
        $text = preg_replace( '/\s+/u', ' ', $text );
        // Remove trailing punctuation duplicates: "!!!" → "!", "???" → "?"
        $text = preg_replace( '/([!?.]){2,}/u', '$1', $text );
        // Remove leading/trailing quotes (ASCII + Unicode curly quotes)
        $text = preg_replace( '/^[\'"\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}]+|[\'"\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}]+$/u', '', $text );
        return $text;
    }

    /**
     * Build context fingerprint from conversation state.
     *
     * @param array|null $conversation
     * @param string     $provider_hint
     * @return string
     */
    public function build_context_fingerprint( $conversation = null, $provider_hint = '' ) {
        $parts = [
            'goal'    => $conversation['goal'] ?? '',
            'status'  => $conversation['status'] ?? '',
            'wfield'  => $conversation['waiting_field'] ?? '',
            'hint'    => $provider_hint,
        ];
        return implode( '|', $parts );
    }

    /**
     * Generate cache key = SHA256( normalized_message + context_fingerprint ).
     *
     * @param string     $message
     * @param array|null $conversation
     * @param string     $provider_hint
     * @return array { cache_key, message_norm, context_hash }
     */
    public function make_cache_key( $message, $conversation = null, $provider_hint = '' ) {
        $message_norm = $this->normalize_message( $message );
        $context_fp   = $this->build_context_fingerprint( $conversation, $provider_hint );
        $context_hash = hash( 'sha256', $context_fp );
        $cache_key    = hash( 'sha256', $message_norm . '||' . $context_fp );

        return [
            'cache_key'    => $cache_key,
            'message_norm' => mb_strcut( $message_norm, 0, 500, 'UTF-8' ),
            'context_hash' => $context_hash,
        ];
    }

    /* ================================================================
     *  LAYER 1: MODE CACHE (Read / Write)
     * ================================================================ */

    /**
     * Look up cached mode classification.
     *
     * @param string     $message
     * @param array|null $conversation
     * @return array|null  { mode, confidence, is_memory } or null if miss
     */
    public function get_mode( $message, $conversation = null ) {
        $key_data  = $this->make_cache_key( $message, $conversation );
        $cache_key = $key_data['cache_key'];

        // In-memory cache (same request)
        if ( isset( $this->request_cache[ $cache_key ]['mode'] ) ) {
            return $this->request_cache[ $cache_key ]['mode'];
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT mode, mode_confidence, is_memory
                 FROM {$this->table}
                 WHERE cache_key = %s
                   AND mode IS NOT NULL
                   AND ( expires_at IS NULL OR expires_at > NOW() )
                 LIMIT 1",
                $cache_key
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['mode'] ) ) {
            return null;
        }

        // Skip entries below MIN_CACHE_CONFIDENCE — entries stored before
        // the threshold was raised are treated as cache MISS (fresh LLM call).
        if ( floatval( $row['mode_confidence'] ) < self::MIN_CACHE_CONFIDENCE ) {
            return null;
        }

        // Update hit counter (async-safe, fire-and-forget)
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET hit_count = hit_count + 1, last_hit_at = NOW()
                 WHERE cache_key = %s",
                $cache_key
            )
        );

        $result = [
            'mode'       => $row['mode'],
            'confidence' => floatval( $row['mode_confidence'] ),
            'is_memory'  => (bool) $row['is_memory'],
        ];

        // Store in request cache
        $this->request_cache[ $cache_key ]['mode'] = $result;

        error_log( "[CLASSIFY-CACHE] MODE HIT: '{$key_data['message_norm']}' → {$row['mode']} ({$row['mode_confidence']})" );

        return $result;
    }

    /**
     * Store mode classification result.
     *
     * @param string     $message
     * @param array|null $conversation
     * @param array      $mode_result { mode, confidence, is_memory }
     * @param string     $source_model
     */
    public function set_mode( $message, $conversation, $mode_result, $source_model = '' ) {
        if ( ( $mode_result['confidence'] ?? 0 ) < self::MIN_CACHE_CONFIDENCE ) {
            return; // Don't cache low-confidence results
        }

        $key_data = $this->make_cache_key( $message, $conversation );
        $ttl      = self::MODE_TTL;
        if ( ( $mode_result['confidence'] ?? 0 ) >= 0.85 ) {
            $ttl *= self::HIGH_CONF_MULT;
        }

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                    (cache_key, message_norm, context_hash, mode, mode_confidence, is_memory, source_model, expires_at)
                 VALUES (%s, %s, %s, %s, %f, %d, %s, DATE_ADD(NOW(), INTERVAL %d SECOND))
                 ON DUPLICATE KEY UPDATE
                    mode = VALUES(mode),
                    mode_confidence = VALUES(mode_confidence),
                    is_memory = VALUES(is_memory),
                    source_model = VALUES(source_model),
                    expires_at = VALUES(expires_at)",
                $key_data['cache_key'],
                $key_data['message_norm'],
                $key_data['context_hash'],
                $mode_result['mode'],
                $mode_result['confidence'],
                $mode_result['is_memory'] ? 1 : 0,
                $source_model,
                $ttl
            )
        );

        // Store in request cache
        $this->request_cache[ $key_data['cache_key'] ]['mode'] = [
            'mode'       => $mode_result['mode'],
            'confidence' => $mode_result['confidence'],
            'is_memory'  => (bool) ( $mode_result['is_memory'] ?? false ),
        ];
    }

    /* ================================================================
     *  LAYER 2: INTENT CACHE (Read / Write)
     * ================================================================ */

    /**
     * Look up cached intent classification.
     *
     * @param string     $message
     * @param array|null $conversation
     * @param string     $provider_hint
     * @return array|null  Full intent result or null if miss
     */
    public function get_intent( $message, $conversation = null, $provider_hint = '' ) {
        $key_data  = $this->make_cache_key( $message, $conversation, $provider_hint );
        $cache_key = $key_data['cache_key'];

        // In-memory cache
        if ( isset( $this->request_cache[ $cache_key ]['intent'] ) ) {
            return $this->request_cache[ $cache_key ]['intent'];
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT intent, goal, goal_label, entities_json,
                        intent_confidence, suggested_tools, missing_fields, goal_objective
                 FROM {$this->table}
                 WHERE cache_key = %s
                   AND intent IS NOT NULL
                   AND ( expires_at IS NULL OR expires_at > NOW() )
                 LIMIT 1",
                $cache_key
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['intent'] ) ) {
            return null;
        }

        // Update hit counter
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET hit_count = hit_count + 1, last_hit_at = NOW()
                 WHERE cache_key = %s",
                $cache_key
            )
        );

        $result = [
            'intent'          => $row['intent'],
            'goal'            => $row['goal'] ?? '',
            'goal_label'      => $row['goal_label'] ?? '',
            'entities'        => json_decode( $row['entities_json'] ?? '{}', true ) ?: [],
            'confidence'      => floatval( $row['intent_confidence'] ),
            'suggested_tools' => json_decode( $row['suggested_tools'] ?? '[]', true ) ?: [],
            'missing_fields'  => json_decode( $row['missing_fields'] ?? '[]', true ) ?: [],
            'goal_objective'  => $row['goal_objective'] ?? '',
            'method'          => 'cache',
        ];

        $this->request_cache[ $cache_key ]['intent'] = $result;

        error_log( "[CLASSIFY-CACHE] INTENT HIT: '{$key_data['message_norm']}' → {$row['intent']}:{$row['goal']} ({$row['intent_confidence']})" );

        return $result;
    }

    /**
     * Store intent classification result.
     *
     * @param string     $message
     * @param array|null $conversation
     * @param string     $provider_hint
     * @param array      $intent_result
     * @param string     $source_model
     */
    public function set_intent( $message, $conversation, $provider_hint, $intent_result, $source_model = '' ) {
        if ( ( $intent_result['confidence'] ?? 0 ) < self::MIN_CACHE_CONFIDENCE ) {
            return;
        }

        $key_data = $this->make_cache_key( $message, $conversation, $provider_hint );
        $ttl      = self::INTENT_TTL;
        if ( ( $intent_result['confidence'] ?? 0 ) >= 0.85 ) {
            $ttl *= self::HIGH_CONF_MULT;
        }

        $entities_json  = wp_json_encode( $intent_result['entities'] ?? [], JSON_UNESCAPED_UNICODE );
        $tools_json     = wp_json_encode( $intent_result['suggested_tools'] ?? [], JSON_UNESCAPED_UNICODE );
        $missing_json   = wp_json_encode( $intent_result['missing_fields'] ?? [], JSON_UNESCAPED_UNICODE );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                    (cache_key, message_norm, context_hash,
                     intent, goal, goal_label, entities_json,
                     intent_confidence, suggested_tools, missing_fields, goal_objective,
                     source_model, expires_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s, DATE_ADD(NOW(), INTERVAL %d SECOND))
                 ON DUPLICATE KEY UPDATE
                    intent = VALUES(intent),
                    goal = VALUES(goal),
                    goal_label = VALUES(goal_label),
                    entities_json = VALUES(entities_json),
                    intent_confidence = VALUES(intent_confidence),
                    suggested_tools = VALUES(suggested_tools),
                    missing_fields = VALUES(missing_fields),
                    goal_objective = VALUES(goal_objective),
                    source_model = VALUES(source_model),
                    expires_at = VALUES(expires_at)",
                $key_data['cache_key'],
                $key_data['message_norm'],
                $key_data['context_hash'],
                $intent_result['intent'] ?? '',
                $intent_result['goal'] ?? '',
                $intent_result['goal_label'] ?? '',
                $entities_json,
                $intent_result['confidence'] ?? 0,
                $tools_json,
                $missing_json,
                $intent_result['goal_objective'] ?? '',
                $source_model,
                $ttl
            )
        );

        $this->request_cache[ $key_data['cache_key'] ]['intent'] = $intent_result;
    }

    /* ================================================================
     *  CLEANUP
     * ================================================================ */

    /**
     * Remove expired and idle cache entries.
     * Call via WP-Cron or admin action.
     */
    public function cleanup() {
        // 1. Remove expired
        $deleted_expired = $this->wpdb->query(
            "DELETE FROM {$this->table}
             WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );

        // 2. Remove idle (0 hits after CLEANUP_IDLE_DAYS)
        $idle_days = self::CLEANUP_IDLE_DAYS;
        $deleted_idle = $this->wpdb->query(
            "DELETE FROM {$this->table}
             WHERE hit_count = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL {$idle_days} DAY)"
        );

        // 3. Remove very old (regardless of hits)
        $max_days = self::CLEANUP_AGE_DAYS;
        $deleted_old = $this->wpdb->query(
            "DELETE FROM {$this->table}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$max_days} DAY)"
        );

        error_log( "[CLASSIFY-CACHE] Cleanup: expired={$deleted_expired}, idle={$deleted_idle}, old={$deleted_old}" );

        return [
            'expired' => $deleted_expired,
            'idle'    => $deleted_idle,
            'old'     => $deleted_old,
        ];
    }

    /**
     * Invalidate all cache entries for a specific goal.
     * Call when tool definitions change.
     *
     * @param string $goal
     * @return int Number of deleted rows
     */
    public function invalidate_goal( $goal ) {
        return $this->wpdb->delete( $this->table, [ 'goal' => $goal ] );
    }

    /**
     * Flush entire cache.
     *
     * @return int Number of deleted rows
     */
    public function flush() {
        return $this->wpdb->query( "TRUNCATE TABLE {$this->table}" );
    }

    /* ================================================================
     *  STATS (for admin dashboard)
     * ================================================================ */

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function get_stats() {
        $total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
        $with_mode   = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE mode IS NOT NULL" );
        $with_intent = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE intent IS NOT NULL" );
        $total_hits  = (int) $this->wpdb->get_var( "SELECT COALESCE(SUM(hit_count), 0) FROM {$this->table}" );
        $expired     = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE expires_at < NOW()" );

        $top_goals = $this->wpdb->get_results(
            "SELECT goal, COUNT(*) as cnt, SUM(hit_count) as total_hits
             FROM {$this->table}
             WHERE goal IS NOT NULL AND goal != ''
             GROUP BY goal
             ORDER BY total_hits DESC
             LIMIT 10",
            ARRAY_A
        );

        return [
            'total_entries'   => $total,
            'mode_cached'     => $with_mode,
            'intent_cached'   => $with_intent,
            'total_hits'      => $total_hits,
            'expired_pending' => $expired,
            'top_goals'       => $top_goals,
        ];
    }
}
