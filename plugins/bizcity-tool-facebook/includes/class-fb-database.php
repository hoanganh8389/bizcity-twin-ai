<?php
/**
 * BizCity Tool Facebook — Own Database Manager
 *
 * Self-contained DB tables — NO dependency on bizcity-facebook-bot.
 * Tables use prefix `bztfb_` to avoid collision.
 *
 * Schema:
 *   bztfb_pages      — Connected FB pages (page_id, token, IG account)
 *   bztfb_inbox      — Messenger conversations (PSID + last status)
 *   bztfb_messages   — Raw message log (in/out)
 *   bztfb_comments   — Comment log + AI reply tracking
 *   bztfb_posts_log  — Posts published via pipeline (for analytics)
 *
 * @package BizCity\TwinAI\ToolFacebook
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_FB_Database {

    const SCHEMA_VERSION = '2.1.0';
    const OPT_VERSION    = 'bztfb_db_version';

    /* ── Table names ── */
    public static function pages_table():    string { global $wpdb; return $wpdb->prefix . 'bztfb_pages'; }
    public static function inbox_table():    string { global $wpdb; return $wpdb->prefix . 'bztfb_inbox'; }
    public static function messages_table(): string { global $wpdb; return $wpdb->prefix . 'bztfb_messages'; }
    public static function comments_table(): string { global $wpdb; return $wpdb->prefix . 'bztfb_comments'; }
    public static function posts_log_table():string { global $wpdb; return $wpdb->prefix . 'bztfb_posts_log'; }

    /* ══════════════════════════════════════════════════════════════════
     *  INSTALL / UPGRADE
     * ══════════════════════════════════════════════════════════════════ */

    public static function install(): void {
        if ( get_option( self::OPT_VERSION ) === self::SCHEMA_VERSION ) return;

        global $wpdb;
        $cc = $wpdb->get_charset_collate();

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        // ── Pages table ──────────────────────────────────────────────
        // One row per connected Facebook Page (per WP site).
        dbDelta( "CREATE TABLE IF NOT EXISTS " . self::pages_table() . " (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            page_id         varchar(100) NOT NULL,
            page_name       varchar(255) DEFAULT '',
            page_access_token text       NOT NULL,
            app_id          varchar(100) DEFAULT '',
            app_secret      varchar(255) DEFAULT '',
            verify_token    varchar(100) DEFAULT 'bizgpt',
            category        varchar(100) DEFAULT '',
            ig_account_id   varchar(100) DEFAULT '',
            user_id         bigint(20)   DEFAULT 0,
            status          varchar(20)  DEFAULT 'active',
            webhook_subscribed tinyint(1) DEFAULT 0,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY page_id (page_id),
            KEY status (status),
            KEY user_id (user_id)
        ) $cc;" );

        // ── Inbox table ───────────────────────────────────────────────
        // One row per Messenger conversation thread (PSID × Page).
        dbDelta( "CREATE TABLE IF NOT EXISTS " . self::inbox_table() . " (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            psid            varchar(100) NOT NULL,
            page_id         varchar(100) NOT NULL,
            display_name    varchar(255) DEFAULT '',
            profile_pic     text,
            last_message    text,
            last_sender     varchar(20)  DEFAULT 'user',
            last_msg_id     varchar(200) DEFAULT '',
            unread          int(11)      DEFAULT 0,
            bot_enabled     tinyint(1)   DEFAULT 1,
            session_data    longtext,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY psid_page (psid, page_id),
            KEY page_id (page_id),
            KEY updated_at (updated_at)
        ) $cc;" );

        // ── Messages table ────────────────────────────────────────────
        // Raw message log — inbound + outbound.
        dbDelta( "CREATE TABLE IF NOT EXISTS " . self::messages_table() . " (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            psid            varchar(100) NOT NULL,
            page_id         varchar(100) NOT NULL,
            message_id      varchar(200) DEFAULT '',
            direction       varchar(10)  DEFAULT 'in',
            message_type    varchar(20)  DEFAULT 'text',
            message_text    text,
            attachment_type varchar(50)  DEFAULT '',
            attachment_url  text,
            raw_payload     longtext,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY psid_page (psid, page_id),
            KEY message_id (message_id),
            KEY direction (direction),
            KEY created_at (created_at)
        ) $cc;" );

        // ── Comments table ────────────────────────────────────────────
        // Comment events from webhook — tracks reply status.
        dbDelta( "CREATE TABLE IF NOT EXISTS " . self::comments_table() . " (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            page_id         varchar(100) NOT NULL,
            fb_post_id      varchar(200) NOT NULL,
            post_type       varchar(50)  DEFAULT 'feed',
            comment_id      varchar(200) NOT NULL,
            parent_comment_id varchar(200) DEFAULT '',
            sender_id       varchar(100) DEFAULT '',
            sender_name     varchar(255) DEFAULT '',
            message         text,
            ai_reply        text,
            is_replied      tinyint(1)   DEFAULT 0,
            is_hidden       tinyint(1)   DEFAULT 0,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            updated_at      datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY comment_id (comment_id),
            KEY page_id (page_id),
            KEY fb_post_id (fb_post_id),
            KEY sender_id (sender_id),
            KEY is_replied (is_replied)
        ) $cc;" );

        // ── Posts log table ───────────────────────────────────────────
        // Record every published post (pipeline analytics).
        dbDelta( "CREATE TABLE IF NOT EXISTS " . self::posts_log_table() . " (
            id              bigint(20)   NOT NULL AUTO_INCREMENT,
            page_id         varchar(100) NOT NULL,
            post_type       varchar(20)  DEFAULT 'feed',
            fb_post_id      varchar(200) DEFAULT '',
            message         text,
            image_url       text,
            video_url       text,
            status          varchar(20)  DEFAULT 'published',
            wp_post_id      bigint(20)   DEFAULT 0,
            user_id         bigint(20)   DEFAULT 0,
            created_at      datetime     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY page_id (page_id),
            KEY post_type (post_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $cc;" );

        update_option( self::OPT_VERSION, self::SCHEMA_VERSION );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  PAGES CRUD
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Get all active pages for this site.
     *
     * @return array  Array of row arrays { page_id, page_name, page_access_token, ig_account_id, ... }
     */
    public static function get_active_pages(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::pages_table() . " WHERE status = 'active' ORDER BY id ASC",
            ARRAY_A
        );
        return $rows ?: [];
    }

    /**
     * Get a single page record by page_id.
     *
     * @param  string $page_id  FB Page ID.
     * @return array|null
     */
    public static function get_page( string $page_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::pages_table() . " WHERE page_id = %s", $page_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Upsert a page record (insert or update on page_id conflict).
     *
     * @param  array $data { page_id, page_name, page_access_token, ig_account_id?, category?, user_id? }
     * @return bool
     */
    public static function save_page( array $data ): bool {
        global $wpdb;

        $existing = self::get_page( $data['page_id'] );

        if ( $existing ) {
            $updated = $wpdb->update(
                self::pages_table(),
                array_intersect_key( $data, array_flip( [
                    'page_name', 'page_access_token', 'ig_account_id',
                    'category', 'status', 'webhook_subscribed',
                ] ) ),
                [ 'page_id' => $data['page_id'] ]
            );
            return $updated !== false;
        }

        $result = $wpdb->insert( self::pages_table(), [
            'page_id'           => $data['page_id'],
            'page_name'         => $data['page_name']         ?? '',
            'page_access_token' => $data['page_access_token'] ?? '',
            'ig_account_id'     => $data['ig_account_id']     ?? '',
            'category'          => $data['category']          ?? '',
            'user_id'           => $data['user_id']           ?? get_current_user_id(),
            'status'            => 'active',
        ] );
        return $result !== false;
    }

    /**
     * Delete a page by page_id.
     *
     * @param  string $page_id
     * @return bool
     */
    public static function delete_page( string $page_id ): bool {
        global $wpdb;
        return $wpdb->delete( self::pages_table(), [ 'page_id' => $page_id ] ) !== false;
    }

    /**
     * Build a BizCity_FB_Graph_API instance for a given page_id.
     * Returns null if page not found or token missing.
     *
     * @param  string $page_id
     * @return BizCity_FB_Graph_API|null
     */
    public static function api_for_page( string $page_id ): ?BizCity_FB_Graph_API {
        $page = self::get_page( $page_id );
        if ( ! $page || empty( $page['page_access_token'] ) ) return null;
        return new BizCity_FB_Graph_API( $page['page_access_token'], $page_id );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  INBOX CRUD
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Upsert inbox entry for a PSID.
     *
     * @param  string $psid     Messenger PSID.
     * @param  string $page_id  Page ID.
     * @param  array  $data     Additional columns to update.
     * @return void
     */
    public static function upsert_inbox( string $psid, string $page_id, array $data = [] ): void {
        global $wpdb;
        $table = self::inbox_table();
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table WHERE psid = %s AND page_id = %s", $psid, $page_id )
        );

        $row = array_merge( [
            'psid'    => $psid,
            'page_id' => $page_id,
        ], $data );

        if ( $existing ) {
            $wpdb->update( $table, $row, [ 'psid' => $psid, 'page_id' => $page_id ] );
        } else {
            $wpdb->insert( $table, $row );
        }
    }

    /**
     * Get inbox thread for a PSID.
     *
     * @param  string $psid
     * @param  string $page_id
     * @return array|null
     */
    public static function get_inbox( string $psid, string $page_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::inbox_table() . " WHERE psid = %s AND page_id = %s", $psid, $page_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  MESSAGES LOG
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Log a message (inbound or outbound).
     *
     * @param  array $data { psid, page_id, message_id?, direction, message_type, message_text?, ... }
     * @return void
     */
    public static function log_message( array $data ): void {
        global $wpdb;
        $wpdb->insert( self::messages_table(), [
            'psid'            => $data['psid']           ?? '',
            'page_id'         => $data['page_id']        ?? '',
            'message_id'      => $data['message_id']     ?? '',
            'direction'       => $data['direction']      ?? 'in',
            'message_type'    => $data['message_type']   ?? 'text',
            'message_text'    => $data['message_text']   ?? '',
            'attachment_type' => $data['attachment_type'] ?? '',
            'attachment_url'  => $data['attachment_url'] ?? '',
            'raw_payload'     => isset( $data['raw_payload'] ) ? wp_json_encode( $data['raw_payload'] ) : '',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  COMMENTS LOG
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Insert or ignore a comment event.
     *
     * @param  array $data { comment_id, page_id, fb_post_id, sender_id, sender_name, message, ... }
     * @return void
     */
    public static function log_comment( array $data ): void {
        global $wpdb;
        $table = self::comments_table();

        // Skip if already logged (unique key: comment_id)
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table WHERE comment_id = %s", $data['comment_id'] )
        );
        if ( $exists ) return;

        $wpdb->insert( $table, [
            'page_id'          => $data['page_id']           ?? '',
            'fb_post_id'       => $data['fb_post_id']        ?? '',
            'post_type'        => $data['post_type']         ?? 'feed',
            'comment_id'       => $data['comment_id']        ?? '',
            'parent_comment_id'=> $data['parent_comment_id'] ?? '',
            'sender_id'        => $data['sender_id']         ?? '',
            'sender_name'      => $data['sender_name']       ?? '',
            'message'          => $data['message']           ?? '',
        ] );
    }

    /**
     * Mark a comment as replied.
     *
     * @param  string $comment_id
     * @param  string $ai_reply   The text that was sent.
     * @return void
     */
    public static function mark_comment_replied( string $comment_id, string $ai_reply = '' ): void {
        global $wpdb;
        $wpdb->update(
            self::comments_table(),
            [ 'is_replied' => 1, 'ai_reply' => $ai_reply ],
            [ 'comment_id' => $comment_id ]
        );
    }

    /**
     * Get pending (unreplied) comments for a page.
     *
     * @param  string $page_id
     * @param  int    $limit
     * @return array
     */
    public static function get_pending_comments( string $page_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::comments_table() . " WHERE page_id = %s AND is_replied = 0 ORDER BY created_at ASC LIMIT %d",
                $page_id, $limit
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  POSTS LOG
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Log a published post.
     *
     * @param  array $data { page_id, post_type, fb_post_id, message, image_url?, video_url?, wp_post_id?, user_id? }
     * @return void
     */
    public static function log_post( array $data ): void {
        global $wpdb;
        $wpdb->insert( self::posts_log_table(), [
            'page_id'    => $data['page_id']   ?? '',
            'post_type'  => $data['post_type'] ?? 'feed',
            'fb_post_id' => $data['fb_post_id'] ?? '',
            'message'    => $data['message']   ?? '',
            'image_url'  => $data['image_url'] ?? '',
            'video_url'  => $data['video_url'] ?? '',
            'status'     => $data['status']    ?? 'published',
            'wp_post_id' => $data['wp_post_id'] ?? 0,
            'user_id'    => $data['user_id']   ?? get_current_user_id(),
        ] );
    }
}
