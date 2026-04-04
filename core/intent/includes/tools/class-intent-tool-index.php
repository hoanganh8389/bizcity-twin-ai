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
 * BizCity Intent — Tool Index (DB-persisted Tool Registry)
 *
 * Syncs all registered Intent Provider tools/goals/plans into a DB table
 * (`bizcity_tool_registry`) so the AI Team Leader has a persistent, queryable
 * index of every available tool across all active plugins.
 *
 * Primary purposes:
 *   1. **LLM Context** — Build a compact manifest for the Router/Planner LLM
 *      so it knows exactly what tools exist + what inputs they need.
 *   2. **Marketplace Awareness** — When a plugin is activated from the Chợ,
 *      its tools immediately appear in the registry and the AI can use them.
 *   3. **Execution Planning** — The Planner can query the registry to build
 *      better slot schemas and missing-field prompts.
 *
 * Lifecycle:
 *   1. Provider Registry boot() → calls sync_all()
 *   2. sync_all() iterates providers → upserts tool rows into DB
 *   3. build_tools_context() reads DB → returns compact text for LLM
 *   4. Context is injected into Router LLM prompt + Planner slot reasoning
 *
 * @package BizCity_Intent
 * @since   3.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Tool_Index {

    /** @var self|null */
    private static $instance = null;

    /** @var string DB table name */
    private $table;

    /** @var string Transient key for cached manifest */
    const MANIFEST_CACHE_KEY = 'bizcity_tool_manifest';

    /** @var int Manifest cache TTL (seconds) — rebuild on every boot anyway */
    const MANIFEST_TTL = 3600;

    /**
     * Schema version — bump this number when adding new migrations.
     * Stored in wp_options as `bizcity_tool_registry_schema_ver`.
     */
    const SCHEMA_VERSION = 7;

    /** @var string wp_options key for stored schema version */
    const SCHEMA_VERSION_KEY = 'bizcity_tool_registry_schema_ver';
    /** @var string Autoloaded option flag — skips COUNT query when table already seeded */
    const SEEDED_FLAG_KEY = 'bizcity_tool_registry_seeded';

    /** @var string Autoloaded option — fingerprint of last synced in-memory tools */
    const MEMORY_SYNC_KEY = 'bizcity_tool_registry_memory_hash';

    /**
     * Check whether the tool_registry table exists using the autoloaded
     * schema-version option — zero extra DB queries on the hot path.
     * Result is statically cached per request.
     *
     * @return bool
     */
    private function table_ready(): bool {
        static $ready = null;
        if ( $ready !== null ) {
            return $ready;
        }
        $ready = ( (int) get_option( self::SCHEMA_VERSION_KEY, 0 ) ) >= 1;
        return $ready;
    }

    /**
     * Real table-existence check — used ONLY inside migrations (runs once per version bump).
     * Cached per request so multiple migrate_to_N() calls share one query.
     *
     * @return bool
     */
    private function table_exists_raw(): bool {
        static $exists = null;
        if ( $exists !== null ) {
            return $exists;
        }
        global $wpdb;
        // Use DATABASE() (runtime current DB on shard) instead of DB_NAME
        // constant (always the global DB in sharded multisite).
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $this->table
        ) ) > 0;
        return $exists;
    }

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bizcity_tool_registry';
    }

    /* ================================================================
     *  DDL — Versioned schema migrations
     *
     *  How it works:
     *    - SCHEMA_VERSION const = current target version (bump on new migration)
     *    - wp_options stores the last-applied version
     *    - ensure_schema() compares: stored < target → run_migrations()
     *    - After migrations done → update stored version
     *    - Result: ZERO extra queries on normal requests (just 1 get_option)
     * ================================================================ */

    /**
     * Ensure the table schema matches SCHEMA_VERSION.
     *
     * Called once per sync_all(). Reads 1 option from cache (autoloaded).
     * If version matches → returns immediately. No SHOW TABLES / SHOW COLUMNS.
     */
    public function ensure_schema() {
        $stored = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );

        if ( $stored >= self::SCHEMA_VERSION ) {
            return; // Already up-to-date — zero queries
        }

        $this->run_migrations( $stored );

        update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true ); // autoload=yes

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Intent Tool Index] Schema migrated %d → %d',
                $stored, self::SCHEMA_VERSION
            ) );
        }
    }

    /**
     * Execute all migrations from $from_version+1 up to SCHEMA_VERSION.
     *
     * Each migration is a private method: migrate_to_N().
     * Add new migrations at the bottom, bump SCHEMA_VERSION const.
     *
     * @param int $from_version  Last applied version (0 = fresh install).
     */
    private function run_migrations( int $from_version ) {
        // Migration 1: Create full table (fresh install or executor-only table)
        if ( $from_version < 1 ) {
            $this->migrate_to_1();
        }

        // Migration 2: Add columns missing from executor's original schema
        if ( $from_version < 2 ) {
            $this->migrate_to_2();
        }

        // Migration 3: Add priority, custom_hints, custom_description for Tool Control Panel
        if ( $from_version < 3 ) {
            $this->migrate_to_3();
        }

        // Migration 4: Add auto_execute flag for Sprint 1B (skip confirm for trusted tools)
        if ( $from_version < 4 ) {
            $this->migrate_to_4();
        }

        // Migration 5: Add trust_tier + tool_type for Phase 1 Unified Pipeline
        if ( $from_version < 5 ) {
            $this->migrate_to_5();
        }

        // Migration 6: Add accepts_skill + content_tier for Phase 1.4 Content Tool Core
        if ( $from_version < 6 ) {
            $this->migrate_to_6();
        }

        // Migration 7: Repair — re-run migrations 3-6 for shards where
        // table_exists_raw() previously returned false due to checking
        // DB_NAME (global DB) instead of DATABASE() (runtime shard DB).
        if ( $from_version < 7 ) {
            $this->migrate_to_3();
            $this->migrate_to_4();
            $this->migrate_to_5();
            $this->migrate_to_6();
        }
    }

    /**
     * Migration 1: Create the tool_registry table with full schema.
     * Uses dbDelta — safe if table already exists (will add missing cols via dbDelta).
     */
    private function migrate_to_1() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tool_key        VARCHAR(128) NOT NULL DEFAULT '',
            tool_name       VARCHAR(128) NOT NULL,
            version         VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
            plugin          VARCHAR(128) NOT NULL DEFAULT '',
            title           VARCHAR(255) NOT NULL DEFAULT '',
            description     TEXT         DEFAULT NULL,
            permissions     VARCHAR(255) NOT NULL DEFAULT 'read',
            input_schema    LONGTEXT     DEFAULT NULL,
            output_schema   LONGTEXT     DEFAULT NULL,
            error_schema    LONGTEXT     DEFAULT NULL,
            side_effects    VARCHAR(255) DEFAULT NULL,
            cost_model      TEXT         DEFAULT NULL,
            rate_limits     TEXT         DEFAULT NULL,
            idempotency     TINYINT(1)   NOT NULL DEFAULT 0,
            dry_run         TINYINT(1)   NOT NULL DEFAULT 0,
            max_retries     INT UNSIGNED NOT NULL DEFAULT 2,
            callback        VARCHAR(255) NOT NULL DEFAULT '',
            rollback        VARCHAR(255) DEFAULT NULL,
            active          TINYINT(1)   NOT NULL DEFAULT 1,
            capability_tags LONGTEXT     DEFAULT NULL,
            intent_tags     LONGTEXT     DEFAULT NULL,
            domain_tags     LONGTEXT     DEFAULT NULL,
            goal            VARCHAR(128) DEFAULT NULL,
            goal_label      VARCHAR(255) DEFAULT NULL,
            goal_description TEXT        DEFAULT NULL,
            required_slots  LONGTEXT     DEFAULT NULL,
            optional_slots  LONGTEXT     DEFAULT NULL,
            examples_json   LONGTEXT     DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tool_key (tool_key),
            KEY idx_plugin  (plugin),
            KEY idx_active  (active),
            KEY idx_goal    (goal)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Migration 2: Add columns that bizcity-executor's original schema was missing.
     *
     * Checks each column with SHOW COLUMNS (runs ONCE, then version stored).
     * Subsequent requests skip entirely via version check in ensure_schema().
     */
    private function migrate_to_2() {
        global $wpdb;

        // Table doesn't exist yet — migrate_to_1's dbDelta will create it fully
        if ( ! $this->table_exists_raw() ) {
            return;
        }

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$this->table}", 0 );
        if ( empty( $existing ) ) {
            return;
        }
        $existing = array_map( 'strtolower', $existing );

        // Columns we need — col_name => ALTER ADD definition
        $needed = [
            'callback'         => "VARCHAR(255) NOT NULL DEFAULT '' AFTER description",
            'rollback'         => "VARCHAR(255) DEFAULT NULL AFTER callback",
            'active'           => "TINYINT(1) NOT NULL DEFAULT 1 AFTER rollback",
            'capability_tags'  => "LONGTEXT DEFAULT NULL AFTER active",
            'intent_tags'      => "LONGTEXT DEFAULT NULL AFTER capability_tags",
            'domain_tags'      => "LONGTEXT DEFAULT NULL AFTER intent_tags",
            'goal'             => "VARCHAR(128) DEFAULT NULL AFTER domain_tags",
            'goal_label'       => "VARCHAR(255) DEFAULT NULL AFTER goal",
            'goal_description' => "TEXT DEFAULT NULL AFTER goal_label",
            'required_slots'   => "LONGTEXT DEFAULT NULL AFTER goal_description",
            'optional_slots'   => "LONGTEXT DEFAULT NULL AFTER required_slots",
            'examples_json'    => "LONGTEXT DEFAULT NULL AFTER optional_slots",
        ];

        foreach ( $needed as $col => $definition ) {
            if ( ! in_array( strtolower( $col ), $existing, true ) ) {
                $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // Ensure indexes
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$this->table}", ARRAY_A );
        $index_names = array_column( $indexes, 'Key_name' );

        if ( ! in_array( 'idx_active', $index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD INDEX idx_active (active)" );
        }
        if ( ! in_array( 'idx_goal', $index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD INDEX idx_goal (goal)" );
        }
    }

    /**
     * Migration 3: Add columns for Tool Control Panel.
     *
     * - `priority`           INT — Sort order for LLM goal list (lower = higher priority, default 50)
     * - `custom_hints`       TEXT — Admin-editable keywords/concepts that trigger this tool
     * - `custom_description` TEXT — Admin-editable description override for LLM prompt
     *
     * These columns are NEVER overwritten by sync — they are user-managed.
     */
    private function migrate_to_3() {
        global $wpdb;

        if ( ! $this->table_exists_raw() ) {
            return;
        }

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$this->table}", 0 );
        if ( empty( $existing ) ) {
            return;
        }
        $existing = array_map( 'strtolower', $existing );

        $needed = [
            'priority'           => "INT NOT NULL DEFAULT 50 AFTER active",
            'custom_hints'       => "TEXT DEFAULT NULL AFTER priority",
            'custom_description' => "TEXT DEFAULT NULL AFTER custom_hints",
        ];

        foreach ( $needed as $col => $definition ) {
            if ( ! in_array( strtolower( $col ), $existing, true ) ) {
                $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // Add priority index for sorted queries
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$this->table}", ARRAY_A );
        $index_names = array_column( $indexes, 'Key_name' );

        if ( ! in_array( 'idx_priority', $index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD INDEX idx_priority (priority)" );
        }
    }

    /**
     * Migration 4: Add auto_execute column for Sprint 1B.
     *
     * - `auto_execute` TINYINT(1) — Whether tool can skip confirm (read-only tools).
     *   Default 0 (require confirm). Set to 1 for trusted read-only tools.
     */
    private function migrate_to_4() {
        global $wpdb;

        if ( ! $this->table_exists_raw() ) {
            return;
        }

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$this->table}", 0 );
        if ( empty( $existing ) ) {
            return;
        }
        $existing = array_map( 'strtolower', $existing );

        if ( ! in_array( 'auto_execute', $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `auto_execute` TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_description" );
        }
    }

    /**
     * Migration 5: Add trust_tier + tool_type columns for Phase 1 Unified Pipeline.
     *
     *   trust_tier  — TIER 0 (auto) → 4 (block). Controls pre-confirm behaviour.
     *   tool_type   — 'atomic' (single action) or 'package' (multi-step composite).
     */
    private function migrate_to_5() {
        global $wpdb;

        if ( ! $this->table_exists_raw() ) {
            return;
        }

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$this->table}", 0 );
        if ( empty( $existing ) ) {
            return;
        }
        $existing = array_map( 'strtolower', $existing );

        if ( ! in_array( 'trust_tier', $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `trust_tier` INT NOT NULL DEFAULT 4 AFTER `auto_execute`" );
        }

        if ( ! in_array( 'tool_type', $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `tool_type` VARCHAR(20) NOT NULL DEFAULT 'atomic' AFTER `trust_tier`" );
        }

        // Index for trust_tier queries (Planner filters by tier)
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$this->table} WHERE Key_name = 'idx_trust_tier'", ARRAY_A );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD INDEX `idx_trust_tier` (`trust_tier`)" );
        }
    }

    /**
     * Migration 6: Add accepts_skill + content_tier for Phase 1.4 Content Tool Core.
     *
     * - `accepts_skill`  TINYINT(1) — Whether tool can receive skill injection (atomic content tools).
     * - `content_tier`   TINYINT    — NULL=not content, 1=produce, 2=distribute, 3=utility.
     */
    private function migrate_to_6() {
        global $wpdb;

        if ( ! $this->table_exists_raw() ) {
            return;
        }

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$this->table}", 0 );
        if ( empty( $existing ) ) {
            return;
        }
        $existing = array_map( 'strtolower', $existing );

        if ( ! in_array( 'accepts_skill', $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `accepts_skill` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can receive skill injection' AFTER `tool_type`" );
        }

        if ( ! in_array( 'content_tier', $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN `content_tier` TINYINT DEFAULT NULL COMMENT 'NULL=not content, 1=produce, 2=distribute, 3=utility' AFTER `accepts_skill`" );
        }

        // Index for accepts_skill queries
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$this->table} WHERE Key_name = 'idx_accepts_skill'", ARRAY_A );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$this->table} ADD INDEX `idx_accepts_skill` (`accepts_skill`)" );
        }
    }

    /* ================================================================
     *  Sync — persist Provider tools into DB
     *
     *  Three entry-points:
     *    sync_all()       — initial seed (runs ONCE if table is empty)
     *    sync_provider()  — single provider (on plugin activate)
     *    unsync_plugin()  — deactivate by slug (on plugin deactivate)
     * ================================================================ */

    /**
     * Initial full sync — only runs if the table is EMPTY (first boot / fresh install).
     *
     * Called by Provider Registry boot(). On subsequent boots, the table already
     * contains data from activate/deactivate events, so this is a no-op.
     *
     * @param BizCity_Intent_Provider[] $providers  All registered providers.
     */
    public function sync_all( array $providers ) {
        global $wpdb;

        // Versioned schema check — 1 get_option (autoloaded, zero queries when up-to-date)
        $this->ensure_schema();

        // Fast path: autoloaded option flag (zero DB queries after first successful seed)
        if ( get_option( self::SEEDED_FLAG_KEY ) ) {
            return;
        }

        // Fallback: check DB — only runs once, then sets flag
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE active = 1" );
        if ( $count > 0 ) {
            update_option( self::SEEDED_FLAG_KEY, 1, true );
            return;
        }

        // Table is empty — full initial seed
        $active_keys = [];

        foreach ( $providers as $provider ) {
            $this->upsert_provider_tools( $provider, $active_keys );
        }

        // Also sync built-in tools from BizCity_Intent_Tools
        $this->sync_builtin_tools( $active_keys );

        // Clear manifest cache + set seeded flag
        delete_transient( self::MANIFEST_CACHE_KEY );
        update_option( self::SEEDED_FLAG_KEY, 1, true );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Intent Tool Index] sync_all() — initial seed: ' . count( $active_keys ) . ' tools' );
        }
    }

    /**
     * Sync a single provider's tools into the registry.
     *
     * Called when a plugin is activated (via `activated_plugin` hook).
     * Only touches rows owned by this provider — does NOT deactivate other plugins' tools.
     *
     * @param BizCity_Intent_Provider $provider  The provider to sync.
     */
    public function sync_provider( BizCity_Intent_Provider $provider ) {
        global $wpdb;

        $this->ensure_schema();

        $active_keys = [];
        $this->upsert_provider_tools( $provider, $active_keys );

        // Clear caches so LLM sees the new tools
        delete_transient( self::MANIFEST_CACHE_KEY );
        delete_option( self::MEMORY_SYNC_KEY );

        // Fire event for downstream consumers (Router cache, Monitor panel, etc.)
        do_action( 'bizcity_tool_registry_changed', 'activate', $provider->get_id(), $active_keys );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Intent Tool Index] sync_provider(' . $provider->get_id() . ') — ' . count( $active_keys ) . ' tools upserted' );
        }
    }

    /**
     * Deactivate all tools owned by a plugin slug.
     *
     * Called when a plugin is deactivated (via `deactivated_plugin` hook).
     * Sets `active = 0` — rows remain for audit/history. Does NOT delete.
     *
     * @param string $plugin_slug  Plugin directory slug (e.g. 'bizcity-tarot').
     * @return int  Number of rows deactivated.
     */
    public function unsync_plugin( string $plugin_slug ): int {
        global $wpdb;

        $this->ensure_schema();

        $affected = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table} SET active = 0 WHERE plugin = %s AND active = 1",
            $plugin_slug
        ) );

        if ( $affected > 0 ) {
            delete_transient( self::MANIFEST_CACHE_KEY );
            delete_option( self::SEEDED_FLAG_KEY );
            delete_option( self::MEMORY_SYNC_KEY );
            do_action( 'bizcity_tool_registry_changed', 'deactivate', $plugin_slug, [] );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[Intent Tool Index] unsync_plugin({$plugin_slug}) — {$affected} tools deactivated" );
        }

        return $affected;
    }

    /**
     * Force full re-sync — replaces all tool data and deactivates stale entries.
     *
     * Use this for:
     *   - WP-CLI manual re-sync
     *   - Admin "Rebuild Tool Registry" button
     *   - After bulk plugin operations
     *
     * @param BizCity_Intent_Provider[] $providers  All registered providers.
     */
    public function force_sync_all( array $providers ) {
        global $wpdb;

        $this->ensure_schema();

        $active_keys = [];

        foreach ( $providers as $provider ) {
            $this->upsert_provider_tools( $provider, $active_keys );
        }

        $this->sync_builtin_tools( $active_keys );

        // Deactivate tools from providers that are no longer active
        if ( ! empty( $active_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $active_keys ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->table} SET active = 0 WHERE tool_key NOT IN ({$placeholders}) AND active = 1",
                ...$active_keys
            ) );
        }

        delete_transient( self::MANIFEST_CACHE_KEY );
        update_option( self::SEEDED_FLAG_KEY, 1, true );
        delete_option( self::MEMORY_SYNC_KEY );
        do_action( 'bizcity_tool_registry_changed', 'force_sync', 'all', $active_keys );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Intent Tool Index] force_sync_all() — ' . count( $active_keys ) . ' tools synced' );
        }
    }

    /**
     * Upsert a single provider's tools into the DB.
     *
     * Internal helper shared by sync_all(), sync_provider(), force_sync_all().
     *
     * @param BizCity_Intent_Provider $provider     The provider.
     * @param array                   &$active_keys Collected tool_keys (appended).
     */
    private function upsert_provider_tools( BizCity_Intent_Provider $provider, array &$active_keys ) {
        global $wpdb;

        $provider_id = $provider->get_id();
        $tools       = $provider->get_tools();
        $plans       = $provider->get_plans();
        $patterns    = $provider->get_goal_patterns();
        $examples    = method_exists( $provider, 'get_examples' ) ? $provider->get_examples() : [];

        // Build goal → plan lookup
        $plan_map = [];
        foreach ( $plans as $goal_id => $plan ) {
            $plan_map[ $goal_id ] = $plan;
        }

        // Build goal → pattern lookup
        $pattern_map = [];
        foreach ( $patterns as $regex => $config ) {
            $goal_id = $config['goal'] ?? '';
            if ( $goal_id ) {
                $pattern_map[ $goal_id ] = $config;
            }
        }

        foreach ( $tools as $tool_name => $tool_config ) {
            $schema   = $tool_config['schema'] ?? $tool_config;
            $callback = $tool_config['callback'] ?? '';
            $label    = $tool_config['label'] ?? ( $schema['label'] ?? $tool_name );
            $desc     = $schema['description'] ?? '';

            // Find associated plan (tool_name often matches goal_id)
            $plan       = $plan_map[ $tool_name ] ?? [];
            $goal_id    = $tool_name;
            $goal_label = $pattern_map[ $tool_name ]['label'] ?? $label;
            $goal_desc  = $pattern_map[ $tool_name ]['description'] ?? $desc;

            // Build input_schema from plan's required + optional slots
            $required_slots = $plan['required_slots'] ?? ( $schema['input_fields'] ?? [] );
            $optional_slots = $plan['optional_slots'] ?? [];
            $input_schema   = $this->build_input_schema( $required_slots, $optional_slots );

            // Determine callback string for DB storage
            $callback_str = $this->callback_to_string( $callback );

            $tool_key = $provider_id . ':' . $tool_name;
            $active_keys[] = $tool_key;

            // Resolve examples for this tool (keyed by tool_name or goal_id)
            $tool_examples = $examples[ $tool_name ] ?? $examples[ $goal_id ] ?? [];

            // Upsert
            $row = [
                'tool_key'        => $tool_key,
                'tool_name'       => $tool_name,
                'version'         => defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '3.4.0',
                'plugin'          => $provider_id,
                'title'           => mb_substr( $label, 0, 255 ),
                'description'     => $desc,
                'callback'        => $callback_str,
                'active'          => 1,
                'goal'            => $goal_id,
                'goal_label'      => $goal_label,
                'goal_description'=> $goal_desc,
                'required_slots'  => wp_json_encode( $required_slots, JSON_UNESCAPED_UNICODE ),
                'optional_slots'  => wp_json_encode( $optional_slots, JSON_UNESCAPED_UNICODE ),
                'examples_json'   => ! empty( $tool_examples ) ? wp_json_encode( $tool_examples, JSON_UNESCAPED_UNICODE ) : null,
                'input_schema'    => wp_json_encode( $input_schema, JSON_UNESCAPED_UNICODE ),
                'intent_tags'     => wp_json_encode( array_keys( $pattern_map ), JSON_UNESCAPED_UNICODE ),
                'domain_tags'     => wp_json_encode( [ $provider_id ], JSON_UNESCAPED_UNICODE ),
                'trust_tier'      => (int) ( $schema['trust_tier'] ?? 4 ),
                'tool_type'       => in_array( $schema['tool_type'] ?? '', [ 'atomic', 'package' ], true )
                                         ? $schema['tool_type'] : 'atomic',
            ];

            // Check existing
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE tool_key = %s LIMIT 1",
                $tool_key
            ) );

            if ( $existing_id ) {
                unset( $row['tool_key'] ); // Don't update the key
                $wpdb->update( $this->table, $row, [ 'id' => $existing_id ] );
            } else {
                $wpdb->insert( $this->table, $row );
            }
        }
    }

    /**
     * Sync built-in tools (from BizCity_Intent_Tools) that aren't provider-owned.
     *
     * @param array &$active_keys  Collected tool_keys (appended).
     */
    private function sync_builtin_tools( array &$active_keys ) {
        if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
            return;
        }

        global $wpdb;
        $tools = BizCity_Intent_Tools::instance();
        $all   = $tools->list_all();

        foreach ( $all as $name => $schema ) {
            $tool_key = 'builtin:' . $name;
            // Skip if already synced by a provider
            if ( in_array( $tool_key, $active_keys, true ) ) {
                continue;
            }
            // Skip provider-owned tools (they have provider: prefix)
            $found_provider = false;
            foreach ( $active_keys as $key ) {
                if ( strpos( $key, ':' . $name ) !== false && strpos( $key, 'builtin:' ) !== 0 ) {
                    $found_provider = true;
                    break;
                }
            }
            if ( $found_provider ) {
                continue;
            }

            $active_keys[] = $tool_key;

            $required = [];
            $optional = [];
            foreach ( ( $schema['input_fields'] ?? [] ) as $field => $config ) {
                if ( ! empty( $config['required'] ) ) {
                    $required[ $field ] = $config;
                } else {
                    $optional[ $field ] = $config;
                }
            }

            $row = [
                'tool_key'       => $tool_key,
                'tool_name'      => $name,
                'version'        => defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '3.4.0',
                'plugin'         => 'builtin',
                'title'          => $schema['description'] ?? $name,
                'description'    => $schema['description'] ?? '',
                'callback'       => 'BizCity_Intent_Tools::builtin_' . $name,
                'active'         => 1,
                'goal'           => $name,
                'goal_label'     => $schema['description'] ?? $name,
                'required_slots' => wp_json_encode( $required, JSON_UNESCAPED_UNICODE ),
                'optional_slots' => wp_json_encode( $optional, JSON_UNESCAPED_UNICODE ),
                'input_schema'   => wp_json_encode( $this->build_input_schema( $required, $optional ), JSON_UNESCAPED_UNICODE ),
                'accepts_skill'  => ! empty( $schema['accepts_skill'] ) ? 1 : 0,
                'content_tier'   => isset( $schema['content_tier'] ) ? (int) $schema['content_tier'] : null,
                'tool_type'      => in_array( $schema['tool_type'] ?? '', [ 'atomic', 'package' ], true )
                                        ? $schema['tool_type'] : 'atomic',
            ];

            // Check by tool_key OR (tool_name + version) to avoid duplicate key on uk_tool_version
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE tool_key = %s OR (tool_name = %s AND version = %s) LIMIT 1",
                $tool_key, $name, $row['version']
            ) );

            if ( $existing_id ) {
                unset( $row['tool_key'] );
                $wpdb->update( $this->table, $row, [ 'id' => $existing_id ] );
            } else {
                $wpdb->insert( $this->table, $row );
            }
        }
    }

    /**
     * Sync in-memory tools from BizCity_Intent_Tools to DB.
     *
     * Called after bizcity_intent_tools_ready fires (init:25) to pick up
     * tools registered by external plugins via the hook.
     * Uses a fingerprint of tool names to skip re-sync when nothing changed.
     */
    public function sync_memory_tools() {
        $this->ensure_schema();

        // Build fingerprint of current in-memory tools to detect changes
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $all_names = array_keys( BizCity_Intent_Tools::instance()->list_all() );
            sort( $all_names );
            // sync_rev: bump when sync_builtin_tools() $row schema changes (e.g. new columns)
            $sync_rev = 2; // v2: added accepts_skill, content_tier, tool_type
            $current_hash = md5( implode( '|', $all_names ) . '|' . ( defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : '' ) . '|rev' . $sync_rev );
        } else {
            $current_hash = 'empty';
        }

        $stored_hash = get_option( self::MEMORY_SYNC_KEY );
        if ( $stored_hash === $current_hash ) {
            return; // Tools unchanged — skip expensive DB upserts
        }

        $active_keys = [];
        $this->sync_builtin_tools( $active_keys );

        if ( ! empty( $active_keys ) ) {
            delete_transient( self::MANIFEST_CACHE_KEY );
        }

        update_option( self::MEMORY_SYNC_KEY, $current_hash, true );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Intent Tool Index] sync_memory_tools() — ' . count( $active_keys ) . ' tools synced' );
        }
    }

    /* ================================================================
     *  Context — build compact tool manifest for LLM
     * ================================================================ */

    /**
     * Build a compact text manifest of all active tools for LLM injection.
     *
     * Format (designed for minimal tokens, maximum signal):
     *   ## 🔧 CÔNG CỤ HIỆN CÓ (Tool Registry)
     *   1. create_product [Admin] — Tạo sản phẩm WooCommerce | cần: title*, price* | tùy chọn: description, image_url
     *   2. write_article [Tool-Content] — Viết bài đăng web | cần: topic* | tùy chọn: tone, length
     *   ...
     *
     * @param int $max_length  Max characters for the manifest text.
     * @return string  Manifest text (empty if no tools).
     */
    public function build_tools_context( int $max_length = 2000 ): string {
        // Try cache first
        $cached = get_transient( self::MANIFEST_CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        if ( ! $this->table_ready() ) {
            return '';
        }

        $rows = $wpdb->get_results(
            "SELECT tool_name, plugin, title, goal_label, goal_description, required_slots, optional_slots, custom_hints, custom_description, priority
             FROM {$this->table}
             WHERE active = 1
             ORDER BY priority ASC, plugin ASC, tool_name ASC",
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return '';
        }

        $lines = [];
        $idx   = 0;
        foreach ( $rows as $row ) {
            $idx++;
            $plugin   = $row['plugin'] ?: 'core';
            $name     = $row['tool_name'];
            $label    = $row['goal_label'] ?: $row['title'] ?: $name;

            // Custom description overrides provider description
            $desc = ! empty( $row['custom_description'] )
                ? $row['custom_description']
                : ( $row['goal_description'] ?: '' );

            // Parse required/optional slot NAMES only (compact)
            $required = $this->parse_slot_names( $row['required_slots'], true );
            $optional = $this->parse_slot_names( $row['optional_slots'], false );

            $slot_text = '';
            if ( $required ) {
                $slot_text .= ' | cần: ' . $required;
            }
            if ( $optional ) {
                $slot_text .= ' | tùy chọn: ' . $optional;
            }

            // Append custom routing hints if set
            $hints = ! empty( $row['custom_hints'] )
                ? ' [hints: ' . mb_substr( $row['custom_hints'], 0, 60, 'UTF-8' ) . ']'
                : '';

            $desc_short = $desc ? ( ' — ' . mb_substr( $desc, 0, 80, 'UTF-8' ) ) : '';
            $line = "{$idx}. {$name} [{$plugin}]{$desc_short}{$slot_text}{$hints}";
            $lines[] = $line;
        }

        $header = "## 🔧 CÔNG CỤ HIỆN CÓ (Tool Registry — {$idx} tools)\n";
        $header .= "Mỗi tool có tên, plugin sở hữu, mô tả, và các trường đầu vào cần thiết.\n\n";
        $body   = implode( "\n", $lines );
        $text   = $header . $body;

        // Truncate if needed
        if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length - 3, 'UTF-8' ) . '...';
        }

        set_transient( self::MANIFEST_CACHE_KEY, $text, self::MANIFEST_TTL );

        return $text;
    }

    /**
     * Get all active tools as structured array (for Monitor/Debug).
     * v4.3.4: Static cache — avoids repeated DB queries within the same request.
     *
     * @return array
     */
    public function get_all_active(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        global $wpdb;

        if ( ! $this->table_ready() ) {
            $cache = [];
            return $cache;
        }

        $cache = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE active = 1 ORDER BY priority ASC, plugin ASC, tool_name ASC",
            ARRAY_A
        ) ?: [];

        return $cache;
    }

    /**
     * Get tool count by plugin.
     *
     * @return array [ plugin_id => count ]
     */
    public function get_counts_by_plugin(): array {
        global $wpdb;

        if ( ! $this->table_ready() ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT plugin, COUNT(*) as cnt FROM {$this->table} WHERE active = 1 GROUP BY plugin ORDER BY cnt DESC",
            ARRAY_A
        );

        $result = [];
        foreach ( $rows ?: [] as $row ) {
            $result[ $row['plugin'] ] = (int) $row['cnt'];
        }
        return $result;
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Build a normalized input_schema from required + optional slots.
     *
     * @param array $required
     * @param array $optional
     * @return array
     */
    private function build_input_schema( array $required, array $optional ): array {
        $schema = [ 'type' => 'object', 'properties' => [], 'required' => [] ];

        foreach ( $required as $field => $config ) {
            $type   = $config['type'] ?? 'string';
            $prompt = $config['prompt'] ?? '';
            $schema['properties'][ $field ] = [
                'type'        => $type,
                'description' => $prompt ?: "Required: {$field}",
            ];
            $schema['required'][] = $field;
        }

        foreach ( $optional as $field => $config ) {
            $type   = $config['type'] ?? 'string';
            $prompt = $config['prompt'] ?? '';
            $schema['properties'][ $field ] = [
                'type'        => $type,
                'description' => $prompt ?: "Optional: {$field}",
            ];
        }

        return $schema;
    }

    /**
     * Convert a callable to a string for DB storage.
     *
     * @param mixed $callback
     * @return string
     */
    private function callback_to_string( $callback ): string {
        if ( is_string( $callback ) ) {
            return $callback;
        }
        if ( is_array( $callback ) && count( $callback ) === 2 ) {
            $class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
            $method = $callback[1];
            return $class . '::' . $method;
        }
        if ( $callback instanceof \Closure ) {
            return 'Closure';
        }
        return 'unknown';
    }

    /**
     * Parse slot names from JSON for compact display.
     *
     * @param string $json       JSON string of slot definitions.
     * @param bool   $is_required If true, append * to each name.
     * @return string  Comma-separated slot names.
     */
    private function parse_slot_names( ?string $json, bool $is_required ): string {
        if ( empty( $json ) ) {
            return '';
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data ) ) {
            return '';
        }
        $names = array_keys( $data );
        if ( $is_required ) {
            $names = array_map( function ( $n ) { return $n . '*'; }, $names );
        }
        return implode( ', ', $names );
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function get_table(): string {
        return $this->table;
    }

    /**
     * Get a single tool row by tool_key.
     *
     * Used by Core Planner to read trust_tier, tool_type, etc.
     *
     * @param string $key  tool_key (e.g. 'provider_id:tool_name' or 'builtin:tool_name').
     * @return object|null  DB row or null.
     */
    public function get_tool_by_key( string $key ) {
        global $wpdb;

        if ( ! $this->table_ready() ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE tool_key = %s AND active = 1 LIMIT 1",
            $key
        ) );
    }

    /**
     * Find a tool by tool_name (any provider prefix).
     *
     * Falls back to searching by tool_name when tool_key is unknown.
     *
     * @param string $name  Tool name (e.g. 'create_product').
     * @return object|null  DB row or null.
     */
    public function get_tool_by_name( string $name ) {
        global $wpdb;

        if ( ! $this->table_ready() ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE tool_name = %s AND active = 1 ORDER BY priority ASC LIMIT 1",
            $name
        ) );
    }

    /* ================================================================
     *  Tool Control Panel — Admin-editable fields
     *
     *  These methods update user-managed columns that sync NEVER overwrites.
     *  Used by class-tool-control-panel.php AJAX handlers.
     * ================================================================ */

    /**
     * Update admin-editable fields for a tool (priority, hints, description, active).
     *
     * @param int   $tool_id  Row ID.
     * @param array $data     Keys: priority, custom_hints, custom_description, active.
     * @return bool True on success.
     */
    public function update_tool_admin_fields( int $tool_id, array $data ): bool {
        global $wpdb;

        $allowed = [ 'priority', 'custom_hints', 'custom_description', 'active' ];
        $update  = [];

        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $update[ $key ] = $data[ $key ];
            }
        }

        if ( empty( $update ) ) {
            return false;
        }

        $result = $wpdb->update( $this->table, $update, [ 'id' => $tool_id ] );

        if ( $result !== false ) {
            delete_transient( self::MANIFEST_CACHE_KEY );
            do_action( 'bizcity_tool_registry_changed', 'admin_edit', $tool_id, array_keys( $update ) );
        }

        return $result !== false;
    }

    /**
     * Get a single tool row by ID.
     *
     * @param int $tool_id  Row ID.
     * @return array|null
     */
    public function get_tool( int $tool_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $tool_id
        ), ARRAY_A );

        return $row ?: null;
    }

    /**
     * Batch update priority ordering for multiple tools.
     *
     * @param array $order  [ tool_id => priority_value, ... ]
     * @return int Number of rows updated.
     */
    public function batch_update_priority( array $order ): int {
        global $wpdb;

        $updated = 0;
        foreach ( $order as $tool_id => $priority ) {
            $result = $wpdb->update(
                $this->table,
                [ 'priority' => (int) $priority ],
                [ 'id' => (int) $tool_id ]
            );
            if ( $result ) {
                $updated++;
            }
        }

        if ( $updated > 0 ) {
            delete_transient( self::MANIFEST_CACHE_KEY );
            do_action( 'bizcity_tool_registry_changed', 'priority_reorder', 'batch', [] );
        }

        return $updated;
    }

    /**
     * Get all tools (including inactive) for Control Panel display.
     *
     * @return array
     */
    public function get_all_for_control_panel(): array {
        global $wpdb;

        if ( ! $this->table_ready() ) {
            return [];
        }

        return $wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY priority ASC, plugin ASC, tool_name ASC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Build the effective description for a tool (custom_description > goal_description).
     *
     * @param array $row Tool row from DB.
     * @return string
     */
    public function get_effective_description( array $row ): string {
        if ( ! empty( $row['custom_description'] ) ) {
            return $row['custom_description'];
        }
        return $row['goal_description'] ?: $row['description'] ?: '';
    }

    /**
     * Build Mermaid flow diagram from all active tools.
     *
     * Generates a flowchart showing: User → Router → Goal → Tool → Plugin
     *
     * @return string Mermaid diagram code.
     */
    public function build_mermaid_flow(): string {
        $tools = $this->get_all_active();

        if ( empty( $tools ) ) {
            return 'graph LR\n  A[No active tools]';
        }

        $lines   = [ 'graph TD' ];
        $lines[] = '  USER([🧑 User Message])';
        $lines[] = '  ROUTER{{"🧠 AI Router<br/>LLM Classification"}}';
        $lines[] = '  USER --> ROUTER';
        $lines[] = '';

        // Group tools by plugin
        $by_plugin = [];
        foreach ( $tools as $row ) {
            $plugin = $row['plugin'] ?: 'builtin';
            $by_plugin[ $plugin ][] = $row;
        }

        $plugin_idx = 0;
        foreach ( $by_plugin as $plugin => $plugin_tools ) {
            $plugin_idx++;
            $plugin_id   = 'P' . $plugin_idx;
            $plugin_label = str_replace( [ 'bizcity-', '-' ], [ '', ' ' ], $plugin );
            $plugin_label = ucwords( $plugin_label );

            $lines[] = "  subgraph {$plugin_id}[\"{$plugin_label}\"]";

            foreach ( $plugin_tools as $row ) {
                $tool_id  = 'T_' . preg_replace( '/[^a-zA-Z0-9]/', '_', $row['tool_name'] );
                $label    = $row['goal_label'] ?: $row['title'] ?: $row['tool_name'];
                $priority = $row['priority'] ?? 50;
                $hints    = ! empty( $row['custom_hints'] )
                    ? '<br/>🔑 ' . mb_substr( $row['custom_hints'], 0, 40, 'UTF-8' )
                    : '';

                $lines[] = "    {$tool_id}[\"🔧 {$label}{$hints}<br/>⚡ {$row['tool_name']}<br/>📊 P{$priority}\"]";
            }

            $lines[] = '  end';
            $lines[] = '';

            // Connect router to each tool in this plugin
            foreach ( $plugin_tools as $row ) {
                $tool_id = 'T_' . preg_replace( '/[^a-zA-Z0-9]/', '_', $row['tool_name'] );
                $goal    = $row['goal'] ?: $row['tool_name'];
                $lines[] = "  ROUTER -->|\"goal: {$goal}\"| {$tool_id}";
            }

            $lines[] = '';
        }

        return implode( "\n", $lines );
    }
}
