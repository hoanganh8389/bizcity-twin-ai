<?php
/**
 * BizCity Research — Database schema (3 tables, scope-flexible).
 *
 * Tables:
 *   bizcity_research_sessions  — top-level project/session
 *   bizcity_research_turns     — each Q→Report turn with reasoning_trace
 *   bizcity_research_ingests   — link from session+turn → kg_source / passage
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Research
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_DB {

    const VERSION_OPTION = 'bizcity_research_db_version';
    const SCHEMA_VERSION = '1.0.0';

    public static function table_sessions(): string {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_research_sessions';
    }
    public static function table_turns(): string {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_research_turns';
    }
    public static function table_ingests(): string {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_research_ingests';
    }

    public static function install(): void {
        if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }
        global $wpdb;

        $charset_collate = function_exists( 'bizcity_get_charset_collate' )
            ? bizcity_get_charset_collate()
            : $wpdb->get_charset_collate();

        $sessions = self::table_sessions();
        $turns    = self::table_turns();
        $ingests  = self::table_ingests();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Sessions: scope_type ('character'|'user'), scope_id (character_id or user_id)
        dbDelta( "CREATE TABLE {$sessions} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type      VARCHAR(20)     NOT NULL DEFAULT 'user',
            scope_id        BIGINT UNSIGNED NOT NULL,
            user_id         BIGINT UNSIGNED NOT NULL,
            title           VARCHAR(255)    NOT NULL,
            topic_tags      LONGTEXT        NULL,
            agent_mode      VARCHAR(10)     NOT NULL DEFAULT 'deep',
            status          VARCHAR(20)     NOT NULL DEFAULT 'open',
            total_turns     INT UNSIGNED    NOT NULL DEFAULT 0,
            total_ingested  INT UNSIGNED    NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL,
            updated_at      DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY scope_idx (scope_type, scope_id),
            KEY user_idx (user_id),
            KEY status_idx (status)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$turns} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id       BIGINT UNSIGNED NOT NULL,
            turn_index       INT UNSIGNED    NOT NULL,
            user_query       TEXT            NOT NULL,
            agent_answer_md  LONGTEXT        NULL,
            reasoning_trace  LONGTEXT        NULL,
            source_urls      LONGTEXT        NULL,
            tool_calls_count INT UNSIGNED    NOT NULL DEFAULT 0,
            duration_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
            status           VARCHAR(20)     NOT NULL DEFAULT 'pending',
            error_message    TEXT            NULL,
            trace_id         VARCHAR(64)     NULL,
            created_at       DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY session_idx (session_id),
            KEY trace_idx (trace_id)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$ingests} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      BIGINT UNSIGNED NOT NULL,
            turn_id         BIGINT UNSIGNED NOT NULL,
            scope_type      VARCHAR(20)     NOT NULL,
            scope_id        BIGINT UNSIGNED NOT NULL,
            source_url      VARCHAR(1000)   NOT NULL,
            url_hash        VARCHAR(64)     NOT NULL,
            title           VARCHAR(500)    NULL,
            favicon         VARCHAR(500)    NULL,
            content_md      LONGTEXT        NULL,
            kg_source_id    BIGINT UNSIGNED NULL,
            ingest_status   VARCHAR(20)     NOT NULL DEFAULT 'pending',
            ingest_error    TEXT            NULL,
            created_at      DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_url_uniq (scope_type, scope_id, url_hash),
            KEY session_idx (session_id),
            KEY turn_idx (turn_id),
            KEY kg_source_idx (kg_source_id)
        ) {$charset_collate};" );

        update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
    }
}
