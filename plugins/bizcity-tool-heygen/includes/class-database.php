<?php
/**
 * Database Schema Manager
 *
 * Tables:
 * - bizcity_tool_heygen_characters: AI character profiles (voice, avatar, persona)
 * - bizcity_tool_heygen_jobs: Video lipsync generation jobs
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_HeyGen_Database {

    /**
     * Get table name with WP prefix
     */
    public static function get_table_name( $table ) {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_tool_heygen_' . $table;
    }

    /**
     * Create all tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Table 1: Characters (Nhân vật AI) ──
        $table_characters = self::get_table_name( 'characters' );
        $sql_characters = "CREATE TABLE IF NOT EXISTS `{$table_characters}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL COMMENT 'Tên nhân vật',
            `slug` varchar(100) NOT NULL COMMENT 'Mã định danh nội bộ',
            `description` text DEFAULT NULL COMMENT 'Mô tả ngắn',
            `persona_prompt` text DEFAULT NULL COMMENT 'Prompt tính cách nhân vật',
            `tone_of_voice` varchar(255) DEFAULT NULL COMMENT 'Phong cách giao tiếp',
            `language` varchar(20) DEFAULT 'vi' COMMENT 'Ngôn ngữ mặc định',
            `voice_sample_url` varchar(500) DEFAULT NULL COMMENT 'URL file voice mẫu',
            `voice_sample_attachment_id` bigint(20) DEFAULT NULL COMMENT 'WP Attachment ID voice sample',
            `voice_id` varchar(255) DEFAULT NULL COMMENT 'HeyGen voice_id sau khi clone',
            `voice_clone_status` varchar(50) DEFAULT 'none' COMMENT 'none, cloning, cloned, failed',
            `avatar_id` varchar(255) DEFAULT NULL COMMENT 'HeyGen avatar_id',
            `image_url` varchar(500) DEFAULT NULL COMMENT 'Ảnh đại diện cố định (fallback)',
            `image_attachment_id` bigint(20) DEFAULT NULL COMMENT 'WP Attachment ID avatar image',
            `default_cta` varchar(500) DEFAULT NULL COMMENT 'CTA mặc định',
            `status` varchar(20) DEFAULT 'active' COMMENT 'active, inactive',
            `metadata` longtext DEFAULT NULL COMMENT 'JSON: extra config',
            `created_by` bigint(20) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `status` (`status`),
            KEY `voice_clone_status` (`voice_clone_status`),
            KEY `created_by` (`created_by`)
        ) $charset_collate;";
        dbDelta( $sql_characters );

        // ── Table 2: Jobs (Video lipsync generation) ──
        $table_jobs = self::get_table_name( 'jobs' );
        $sql_jobs = "CREATE TABLE IF NOT EXISTS `{$table_jobs}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `character_id` bigint(20) DEFAULT NULL COMMENT 'FK → characters.id',
            `job_key` varchar(255) NOT NULL COMMENT 'Unique job identifier',
            `task_id` varchar(255) DEFAULT NULL COMMENT 'HeyGen video_id / task_id',
            `script` text NOT NULL COMMENT 'Lời thoại / script text',
            `voice_id` varchar(255) DEFAULT NULL COMMENT 'voice_id đã dùng',
            `avatar_id` varchar(255) DEFAULT NULL COMMENT 'avatar_id đã dùng',
            `image_url` text DEFAULT NULL COMMENT 'Ảnh đại diện đã dùng',
            `mode` varchar(20) DEFAULT 'text' COMMENT 'text (TTS in HeyGen) hoặc tts (TTS trước)',
            `video_url` varchar(500) DEFAULT NULL COMMENT 'Video URL từ HeyGen',
            `media_url` varchar(500) DEFAULT NULL COMMENT 'WordPress Media URL',
            `attachment_id` bigint(20) DEFAULT NULL COMMENT 'WP Attachment ID',
            `status` varchar(50) DEFAULT 'draft' COMMENT 'draft, queued, processing, completed, failed',
            `progress` int(11) DEFAULT 0,
            `error_message` text DEFAULT NULL,
            `checkpoints` longtext DEFAULT NULL COMMENT 'JSON: step completion timestamps',
            `metadata` longtext DEFAULT NULL COMMENT 'JSON: session_id, chat_id, etc.',
            `created_by` bigint(20) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `job_key` (`job_key`),
            KEY `character_id` (`character_id`),
            KEY `task_id` (`task_id`),
            KEY `status` (`status`),
            KEY `created_by` (`created_by`),
            KEY `created_at` (`created_at`)
        ) $charset_collate;";
        dbDelta( $sql_jobs );
    }

    /* ═══════════════════════════════════════════════════════
     *  Characters CRUD
     * ═══════════════════════════════════════════════════════ */

    public static function get_character( $id ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public static function get_character_by_slug( $slug ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );
    }

    public static function get_characters( $args = [] ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );

        $defaults = [
            'status'  => '',
            'orderby' => 'created_at',
            'order'   => 'DESC',
            'limit'   => 50,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = [];

        if ( $args['status'] ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        $allowed_orderby = [ 'created_at', 'name', 'updated_at' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d";
        $params[] = (int) $args['limit'];

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    public static function get_active_characters() {
        global $wpdb;
        $table = self::get_table_name( 'characters' );
        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' OR status IS NULL OR status = '' ORDER BY created_at DESC LIMIT 50"
        );
    }

    public static function get_all_characters() {
        return self::get_characters( [ 'status' => '', 'limit' => 100 ] );
    }

    public static function create_character( $data ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );

        $defaults = [
            'language'           => 'vi',
            'voice_clone_status' => 'none',
            'status'             => 'active',
            'created_by'         => get_current_user_id(),
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        ];
        $data = wp_parse_args( $data, $defaults );

        // Ensure unique slug
        if ( ! empty( $data['slug'] ) ) {
            $base_slug = $data['slug'];
            $suffix    = 2;
            while ( self::get_character_by_slug( $data['slug'] ) ) {
                $data['slug'] = $base_slug . '-' . $suffix;
                $suffix++;
            }
        }

        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function update_character( $id, $data ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );

        $data['updated_at'] = current_time( 'mysql' );
        return $wpdb->update( $table, $data, [ 'id' => $id ] );
    }

    public static function delete_character( $id ) {
        global $wpdb;
        $table = self::get_table_name( 'characters' );
        return $wpdb->delete( $table, [ 'id' => $id ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Jobs CRUD
     * ═══════════════════════════════════════════════════════ */

    public static function get_job( $job_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ) );
    }

    public static function get_job_by_key( $job_key ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ) );
    }

    public static function get_pending_jobs( $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('queued', 'processing')
             AND task_id IS NOT NULL AND task_id != ''
             ORDER BY created_at ASC LIMIT %d",
            $limit
        ) );
    }

    public static function create_job( $data ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );

        $defaults = [
            'job_key'    => 'heygen_' . uniqid(),
            'status'     => 'draft',
            'progress'   => 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ];
        $data = wp_parse_args( $data, $defaults );

        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function update_job( $job_id, $data ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );

        $data['updated_at'] = current_time( 'mysql' );
        return $wpdb->update( $table, $data, [ 'id' => $job_id ] );
    }

    public static function delete_job( $job_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        return $wpdb->delete( $table, [ 'id' => $job_id ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Checkpoints (Pipeline step tracking)
     * ═══════════════════════════════════════════════════════ */

    public static function set_checkpoint( $job_id, $step, $data = null ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );

        $job = self::get_job( $job_id );
        if ( ! $job ) return false;

        $checkpoints = ! empty( $job->checkpoints ) ? json_decode( $job->checkpoints, true ) : [];

        $checkpoints[ $step ] = [
            'completed_at' => current_time( 'mysql' ),
            'data'         => $data,
        ];

        return $wpdb->update(
            $table,
            [ 'checkpoints' => wp_json_encode( $checkpoints ) ],
            [ 'id' => $job_id ]
        ) !== false;
    }

    public static function get_checkpoints( $job_id ) {
        $job = self::get_job( $job_id );
        if ( ! $job || empty( $job->checkpoints ) ) return [];
        return json_decode( $job->checkpoints, true ) ?: [];
    }

    public static function has_checkpoint( $job_id, $step ) {
        $checkpoints = self::get_checkpoints( $job_id );
        return isset( $checkpoints[ $step ] );
    }

    /**
     * Drop all tables (uninstall)
     */
    public static function drop_tables() {
        global $wpdb;
        $tables = [ 'characters', 'jobs' ];
        foreach ( $tables as $t ) {
            $name = self::get_table_name( $t );
            $wpdb->query( "DROP TABLE IF EXISTS {$name}" );
        }
    }
}
