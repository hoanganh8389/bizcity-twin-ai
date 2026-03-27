<?php
/**
 * Database Schema Manager
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Database {
    
    /**
     * Get table name with prefix
     */
    public static function get_table_name( $table ) {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_kling_' . $table;
    }
    
    /**
     * Create all tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        // Table 1: Scripts (Kịch bản)
        $table_scripts = self::get_table_name( 'scripts' );
        $sql_scripts = "CREATE TABLE IF NOT EXISTS `{$table_scripts}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `content` text NOT NULL,
            `duration` int(11) DEFAULT 30 COMMENT 'Total duration in seconds',
            `aspect_ratio` varchar(10) DEFAULT '9:16',
            `model` varchar(50) DEFAULT '2.6|pro',
            `metadata` longtext DEFAULT NULL,
            `created_by` bigint(20) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`)
        ) $charset_collate;";
        dbDelta( $sql_scripts );
        
        // Table 2: Jobs (Video generation jobs)
        $table_jobs = self::get_table_name( 'jobs' );
        $sql_jobs = "CREATE TABLE IF NOT EXISTS `{$table_jobs}` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `script_id` bigint(20) DEFAULT NULL,
            `job_key` varchar(255) NOT NULL COMMENT 'Unique job identifier',
            `task_id` varchar(255) DEFAULT NULL COMMENT 'Provider task ID',
            `prompt` text NOT NULL,
            `image_url` text DEFAULT NULL COMMENT 'Source image URL',
            `duration` int(11) DEFAULT 30,
            `aspect_ratio` varchar(10) DEFAULT '9:16',
            `model` varchar(50) DEFAULT '2.6|pro',
            `video_url` varchar(500) DEFAULT NULL COMMENT 'Original video URL from provider',
            `media_url` varchar(500) DEFAULT NULL COMMENT 'WordPress Media URL',
            `attachment_id` bigint(20) DEFAULT NULL COMMENT 'WP Attachment ID',
            `status` varchar(50) DEFAULT 'draft' COMMENT 'draft, queued, processing, completed, failed',
            `progress` int(11) DEFAULT 0,
            `error_message` text DEFAULT NULL,
            `chain_id` varchar(100) DEFAULT NULL COMMENT 'Chain group identifier',
            `parent_job_id` bigint(20) DEFAULT NULL COMMENT 'Parent job for extend_video',
            `segment_index` int(11) DEFAULT 1 COMMENT 'Segment number in chain (1, 2, 3...)',
            `total_segments` int(11) DEFAULT 1 COMMENT 'Total segments in chain',
            `is_final` tinyint(1) DEFAULT 1 COMMENT 'Is this the final video in chain',
            `checkpoints` longtext DEFAULT NULL COMMENT 'JSON: step completion timestamps',
            `temp_files` longtext DEFAULT NULL COMMENT 'JSON: temporary file paths for resume',
            `metadata` longtext DEFAULT NULL,
            `created_by` bigint(20) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `job_key` (`job_key`),
            KEY `script_id` (`script_id`),
            KEY `task_id` (`task_id`),
            KEY `status` (`status`),
            KEY `chain_id` (`chain_id`),
            KEY `parent_job_id` (`parent_job_id`),
            KEY `created_at` (`created_at`)
        ) $charset_collate;";
        dbDelta( $sql_jobs );
        
        // Add chain columns if not exist (for existing installations)
        self::maybe_add_chain_columns();
        
        // Add checkpoints columns
        self::maybe_add_checkpoints_columns();
    }
    
    /**
     * Add chain columns to existing table
     */
    public static function maybe_add_chain_columns() {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        // Check if chain_id column exists
        $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'chain_id'" );
        
        if ( empty( $column_exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} 
                ADD COLUMN `chain_id` varchar(100) DEFAULT NULL COMMENT 'Chain group identifier' AFTER `error_message`,
                ADD COLUMN `parent_job_id` bigint(20) DEFAULT NULL COMMENT 'Parent job for extend_video' AFTER `chain_id`,
                ADD COLUMN `segment_index` int(11) DEFAULT 1 COMMENT 'Segment number in chain' AFTER `parent_job_id`,
                ADD COLUMN `total_segments` int(11) DEFAULT 1 COMMENT 'Total segments in chain' AFTER `segment_index`,
                ADD COLUMN `is_final` tinyint(1) DEFAULT 1 COMMENT 'Is final video in chain' AFTER `total_segments`,
                ADD KEY `chain_id` (`chain_id`),
                ADD KEY `parent_job_id` (`parent_job_id`)
            " );
        }
    }
    
    /**
     * Add checkpoints columns to existing table
     */
    public static function maybe_add_checkpoints_columns() {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        // Check if checkpoints column exists
        $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'checkpoints'" );
        
        if ( empty( $column_exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} 
                ADD COLUMN `checkpoints` longtext DEFAULT NULL COMMENT 'JSON: step completion timestamps' AFTER `is_final`,
                ADD COLUMN `temp_files` longtext DEFAULT NULL COMMENT 'JSON: temporary file paths for resume' AFTER `checkpoints`
            " );
        }
    }
    
    /**
     * Set a checkpoint for a job step
     * 
     * @param int    $job_id     Job ID
     * @param string $step       Step name (video_submitted, video_completed, video_fetched, tts_generated, audio_merged, media_uploaded)
     * @param mixed  $data       Optional data to store with checkpoint
     * @return bool
     */
    public static function set_checkpoint( $job_id, $step, $data = null ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        $job = self::get_job( $job_id );
        if ( ! $job ) return false;
        
        $checkpoints = ! empty( $job->checkpoints ) ? json_decode( $job->checkpoints, true ) : array();
        
        $checkpoints[ $step ] = array(
            'completed_at' => current_time( 'mysql' ),
            'data'         => $data,
        );
        
        return $wpdb->update(
            $table,
            array( 'checkpoints' => wp_json_encode( $checkpoints ) ),
            array( 'id' => $job_id )
        ) !== false;
    }
    
    /**
     * Get all checkpoints for a job
     */
    public static function get_checkpoints( $job_id ) {
        $job = self::get_job( $job_id );
        if ( ! $job || empty( $job->checkpoints ) ) return array();
        return json_decode( $job->checkpoints, true ) ?: array();
    }
    
    /**
     * Check if a step is completed
     */
    public static function has_checkpoint( $job_id, $step ) {
        $checkpoints = self::get_checkpoints( $job_id );
        return isset( $checkpoints[ $step ] );
    }
    
    /**
     * Save temporary file paths for resume
     */
    public static function save_temp_files( $job_id, $files ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        $job = self::get_job( $job_id );
        if ( ! $job ) return false;
        
        $existing = ! empty( $job->temp_files ) ? json_decode( $job->temp_files, true ) : array();
        $merged = array_merge( $existing, $files );
        
        return $wpdb->update(
            $table,
            array( 'temp_files' => wp_json_encode( $merged ) ),
            array( 'id' => $job_id )
        ) !== false;
    }
    
    /**
     * Get temp files for a job
     */
    public static function get_temp_files( $job_id ) {
        $job = self::get_job( $job_id );
        if ( ! $job || empty( $job->temp_files ) ) return array();
        return json_decode( $job->temp_files, true ) ?: array();
    }
    
    /**
     * Get resume point - which step to continue from
     * 
     * @return array ['step' => 'step_name', 'data' => [...]]  
     */
    public static function get_resume_point( $job_id ) {
        $steps = array(
            'video_submitted',
            'video_completed', 
            'video_fetched',
            'tts_generated',
            'audio_merged',
            'media_uploaded',
            'cleanup_done',
        );
        
        $checkpoints = self::get_checkpoints( $job_id );
        $temp_files = self::get_temp_files( $job_id );
        
        foreach ( $steps as $step ) {
            if ( ! isset( $checkpoints[ $step ] ) ) {
                return array(
                    'step'        => $step,
                    'checkpoints' => $checkpoints,
                    'temp_files'  => $temp_files,
                );
            }
        }
        
        return array(
            'step'        => 'completed',
            'checkpoints' => $checkpoints,
            'temp_files'  => $temp_files,
        );
    }
    
    /**
     * Drop all tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array( 'scripts', 'jobs' );
        
        foreach ( $tables as $table ) {
            $table_name = self::get_table_name( $table );
            $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        }
    }
    
    /**
     * Get job by ID
     */
    public static function get_job( $job_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ) );
    }
    
    /**
     * Get job by key
     */
    public static function get_job_by_key( $job_key ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ) );
    }
    
    /**
     * Get pending jobs (queued or processing)
     */
    public static function get_pending_jobs( $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE status IN ('queued', 'processing')
            AND task_id IS NOT NULL
            AND task_id != ''
            ORDER BY created_at ASC
            LIMIT %d
        ", $limit ) );
    }
    
    /**
     * Update job status
     */
    public static function update_job( $job_id, $data ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        $data['updated_at'] = current_time( 'mysql' );
        
        return $wpdb->update( $table, $data, array( 'id' => $job_id ) );
    }
    
    /**
     * Create new job
     */
    public static function create_job( $data ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        $defaults = array(
            'job_key' => 'kling_' . uniqid(),
            'status' => 'draft',
            'progress' => 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        );
        
        $data = wp_parse_args( $data, $defaults );
        
        $wpdb->insert( $table, $data );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Delete job
     */
    public static function delete_job( $job_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->delete( $table, array( 'id' => $job_id ) );
    }
    
    /**
     * Get script by ID
     */
    public static function get_script( $script_id ) {
        global $wpdb;
        $table = self::get_table_name( 'scripts' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $script_id ) );
    }
    
    /**
     * Get all scripts
     */
    public static function get_scripts( $args = array() ) {
        global $wpdb;
        $table = self::get_table_name( 'scripts' );
        
        $defaults = array(
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 50,
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        return $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM {$table}
            ORDER BY {$args['orderby']} {$args['order']}
            LIMIT %d
        ", $args['limit'] ) );
    }
    
    /**
     * Create script
     */
    public static function create_script( $data ) {
        global $wpdb;
        $table = self::get_table_name( 'scripts' );
        
        $defaults = array(
            'duration' => 30,
            'aspect_ratio' => '9:16',
            'model' => '2.6|pro',
            'created_by' => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        );
        
        $data = wp_parse_args( $data, $defaults );
        
        $wpdb->insert( $table, $data );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update script
     */
    public static function update_script( $script_id, $data ) {
        global $wpdb;
        $table = self::get_table_name( 'scripts' );
        
        $data['updated_at'] = current_time( 'mysql' );
        
        return $wpdb->update( $table, $data, array( 'id' => $script_id ) );
    }
    
    /**
     * Delete script
     */
    public static function delete_script( $script_id ) {
        global $wpdb;
        
        // Delete associated jobs
        $jobs_table = self::get_table_name( 'jobs' );
        $wpdb->delete( $jobs_table, array( 'script_id' => $script_id ) );
        
        // Delete script
        $table = self::get_table_name( 'scripts' );
        return $wpdb->delete( $table, array( 'id' => $script_id ) );
    }
    
    /**
     * Get jobs by script ID
     */
    public static function get_jobs_by_script( $script_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE script_id = %d
            ORDER BY created_at DESC
        ", $script_id ) );
    }
    
    /**
     * Get latest active job for a script (queued, processing, or recently completed chain)
     */
    public static function get_latest_active_job( $script_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        // First try to find queued or processing job
        $job = $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE script_id = %d
            AND status IN ('queued', 'processing', 'draft')
            ORDER BY created_at DESC
            LIMIT 1
        ", $script_id ) );
        
        if ( $job ) {
            return $job;
        }
        
        // If none, get latest job (completed or failed) from last 30 minutes for chain continuation
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE script_id = %d
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ORDER BY created_at DESC
            LIMIT 1
        ", $script_id ) );
    }
    
    /**
     * Get statistics
     */
    public static function get_stats() {
        global $wpdb;
        $jobs_table = self::get_table_name( 'jobs' );
        $scripts_table = self::get_table_name( 'scripts' );
        
        $stats = $wpdb->get_row( "
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                MAX(created_at) as last_job_created,
                MAX(CASE WHEN status = 'completed' THEN updated_at END) as last_completion
            FROM {$jobs_table}
        " );
        
        // Add script count
        $stats->total_scripts = $wpdb->get_var( "SELECT COUNT(*) FROM {$scripts_table}" );
        
        return $stats;
    }
    
    /**
     * Get jobs by chain ID
     * 
     * @param string $chain_id Chain identifier
     * @return array Jobs in this chain ordered by segment_index
     */
    public static function get_jobs_by_chain( $chain_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE chain_id = %s
            ORDER BY segment_index ASC
        ", $chain_id ) );
    }
    
    /**
     * Get chain status summary
     * 
     * @param string $chain_id Chain identifier
     * @return object Chain status
     */
    public static function get_chain_status( $chain_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                chain_id,
                COUNT(*) as total_jobs,
                MAX(total_segments) as total_segments,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_segments,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_segments,
                SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) as pending_segments,
                MAX(segment_index) as current_segment,
                MAX(CASE WHEN is_final = 1 THEN id END) as final_job_id,
                MAX(CASE WHEN is_final = 1 AND status = 'completed' THEN video_url END) as final_video_url
            FROM {$table}
            WHERE chain_id = %s
            GROUP BY chain_id
        ", $chain_id ) );
    }
    
    /**
     * Get the latest completed job in a chain (for getting task_id to extend)
     * 
     * @param string $chain_id Chain identifier
     * @return object|null Latest completed job
     */
    public static function get_latest_completed_in_chain( $chain_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE chain_id = %s AND status = 'completed'
            ORDER BY segment_index DESC
            LIMIT 1
        ", $chain_id ) );
    }
    
    /**
     * Get next pending job in chain
     * 
     * @param string $chain_id Chain identifier
     * @return object|null Next pending job
     */
    public static function get_next_pending_in_chain( $chain_id ) {
        global $wpdb;
        $table = self::get_table_name( 'jobs' );
        
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$table}
            WHERE chain_id = %s AND status IN ('draft', 'queued', 'processing')
            ORDER BY segment_index ASC
            LIMIT 1
        ", $chain_id ) );
    }
    
    /**
     * Generate unique chain ID
     * 
     * @return string Chain ID
     */
    public static function generate_chain_id() {
        return 'chain_' . time() . '_' . wp_rand( 1000, 9999 );
    }
}
