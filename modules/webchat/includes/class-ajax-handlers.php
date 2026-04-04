<?php
/**
 * Bizcity Twin AI — WebChat AJAX Handlers
 * Xử lý AJAX cho Project và Session / AJAX handlers for Project & Session CRUD
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_WebChat_Ajax_Handlers {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Project CRUD
        add_action( 'wp_ajax_bizcity_project_list',   [ $this, 'ajax_project_list' ] );
        add_action( 'wp_ajax_bizcity_project_create', [ $this, 'ajax_project_create' ] );
        add_action( 'wp_ajax_bizcity_project_rename', [ $this, 'ajax_project_rename' ] );
        add_action( 'wp_ajax_bizcity_project_update', [ $this, 'ajax_project_update' ] );
        add_action( 'wp_ajax_bizcity_project_delete', [ $this, 'ajax_project_delete' ] );

        // Session management (V3)
        add_action( 'wp_ajax_bizcity_webchat_sessions',           [ $this, 'ajax_session_list' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_create',     [ $this, 'ajax_session_create' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_rename',     [ $this, 'ajax_session_rename' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_move',       [ $this, 'ajax_session_move' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_delete',     [ $this, 'ajax_session_delete' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_messages',   [ $this, 'ajax_session_messages' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_poll',       [ $this, 'ajax_session_poll' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_gen_title',  [ $this, 'ajax_session_gen_title' ] );
        add_action( 'wp_ajax_bizcity_webchat_close_all',          [ $this, 'ajax_close_all_sessions' ] );

        // Guest (nopriv) — allow free-tier messaging without login
        add_action( 'wp_ajax_nopriv_bizcity_webchat_session_create',   [ $this, 'ajax_session_create' ] );
        add_action( 'wp_ajax_nopriv_bizcity_webchat_session_messages', [ $this, 'ajax_session_messages' ] );
        add_action( 'wp_ajax_nopriv_bizcity_webchat_session_poll',     [ $this, 'ajax_session_poll' ] );

        // Guest (nopriv) — return empty lists so React app doesn't 400
        add_action( 'wp_ajax_nopriv_bizcity_webchat_sessions',       [ $this, 'ajax_session_list' ] );
        add_action( 'wp_ajax_nopriv_bizcity_project_list',           [ $this, 'ajax_project_list' ] );
        add_action( 'wp_ajax_nopriv_bizcity_intent_conversations',   [ $this, 'ajax_intent_conversations' ] );

        // Pin / Archive
        add_action( 'wp_ajax_bizcity_webchat_session_pin',        [ $this, 'ajax_session_pin' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_archive',    [ $this, 'ajax_session_archive' ] );
        add_action( 'wp_ajax_bizcity_webchat_session_restore',    [ $this, 'ajax_session_restore' ] );
        add_action( 'wp_ajax_bizcity_webchat_archived_sessions',  [ $this, 'ajax_archived_sessions' ] );

        // Intent Conversations (Tasks / Nhiệm vụ)
        add_action( 'wp_ajax_bizcity_intent_conversations', [ $this, 'ajax_intent_conversations' ] );
        add_action( 'wp_ajax_bizcity_intent_cancel',        [ $this, 'ajax_intent_cancel' ] );
        add_action( 'wp_ajax_bizcity_intent_complete',       [ $this, 'ajax_intent_complete' ] );

        // LLM Settings (from React dashboard)
        add_action( 'wp_ajax_bizcity_llm_get_settings',     [ $this, 'ajax_llm_get_settings' ] );
        add_action( 'wp_ajax_bizcity_llm_save_settings',    [ $this, 'ajax_llm_save_settings' ] );
        add_action( 'wp_ajax_bizcity_llm_test_connection',  [ $this, 'ajax_llm_test_connection' ] );
        add_action( 'wp_ajax_bizcity_llm_register_api_key', [ $this, 'ajax_llm_register_api_key' ] );
        add_action( 'wp_ajax_bizcity_llm_get_usage',        [ $this, 'ajax_llm_get_usage' ] );

        // Trace History (Working Panel — Sprint 1.7)
        add_action( 'wp_ajax_bizcity_fetch_trace_history', [ $this, 'ajax_fetch_trace_history' ] );

        // Phase 1.8 — Message Actions + Notes + Sources
        add_action( 'wp_ajax_bizcity_webchat_pin_message',   [ $this, 'ajax_pin_message' ] );
        add_action( 'wp_ajax_bizcity_webchat_rate_message',  [ $this, 'ajax_rate_message' ] );
        add_action( 'wp_ajax_bizcity_webchat_notes',         [ $this, 'ajax_get_notes' ] );
        add_action( 'wp_ajax_bizcity_webchat_create_note',   [ $this, 'ajax_create_note' ] );
        add_action( 'wp_ajax_bizcity_webchat_delete_note',   [ $this, 'ajax_delete_note' ] );
        add_action( 'wp_ajax_bizcity_webchat_sources',       [ $this, 'ajax_get_sources' ] );
        add_action( 'wp_ajax_bizcity_webchat_add_source',    [ $this, 'ajax_add_source' ] );
        add_action( 'wp_ajax_bizcity_webchat_delete_source', [ $this, 'ajax_delete_source' ] );

        // Web Search (Tavily)
        add_action( 'wp_ajax_bizcity_webchat_web_search_start',  [ $this, 'ajax_web_search_start' ] );
        add_action( 'wp_ajax_bizcity_webchat_web_search_status', [ $this, 'ajax_web_search_status' ] );
        add_action( 'wp_ajax_bizcity_webchat_web_search_import', [ $this, 'ajax_web_search_import' ] );
        add_action( 'wp_ajax_bizcity_webchat_web_search_cancel', [ $this, 'ajax_web_search_cancel' ] );

        // Studio Outputs (delegated to companion-notebook BCN_Studio)
        add_action( 'wp_ajax_bizcity_webchat_studio_outputs',       [ $this, 'ajax_studio_outputs' ] );
        add_action( 'wp_ajax_bizcity_webchat_studio_generate',      [ $this, 'ajax_studio_generate' ] );
        add_action( 'wp_ajax_bizcity_webchat_studio_delete_output', [ $this, 'ajax_studio_delete_output' ] );
        add_action( 'wp_ajax_bizcity_webchat_studio_skeleton',      [ $this, 'ajax_studio_skeleton' ] );
    }

    /**
     * Log session/project operations to Router Console.
     *
     * @param string $action     The action being performed (create, rename, move, delete, etc.)
     * @param string $type       'session' or 'project'
     * @param array  $data       Additional data to log
     * @param string $session_id Optional session_id for routing the log
     */
    private function log_operation( $action, $type, $data = [], $session_id = '' ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) return;

        $log = [
            'step'             => $type . '_' . $action,
            'message'          => ucfirst( $type ) . ' ' . $action,
            'mode'             => 'webchat_crud',
            'functions_called' => "ajax_{$type}_{$action}()",
            'file_line'        => 'class-ajax-handlers.php',
            'user_id'          => get_current_user_id(),
        ];

        $log = array_merge( $log, $data );

        BizCity_User_Memory::log_router_event( $log, $session_id );
    }

    /**
     * Verify nonce and get DB instance.
     *
     * @return BizCity_WebChat_Database|null
     */
    private function verify_and_get_db() {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return null;
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'Database class not found' ], 500 );
            return null;
        }

        $db = BizCity_WebChat_Database::instance();
        
        // V3: Ensure new tables exist (auto-migration fallback)
        $this->ensure_v3_tables( $db );

        return $db;
    }

    /**
     * Ensure V3 tables exist (projects, sessions).
     * This is a fallback in case migration didn't run.
     */
    private function ensure_v3_tables( $db ) {
        static $checked = false;
        if ( $checked ) return;
        $checked = true;

        global $wpdb;
        $projects_table = $wpdb->prefix . 'bizcity_webchat_projects';
        $sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
        
        // Quick check — if either V3 table doesn't exist, run create_tables()
        $projects_exists = $wpdb->get_var( "SHOW TABLES LIKE '$projects_table'" );
        $sessions_exists = $wpdb->get_var( "SHOW TABLES LIKE '$sessions_table'" );
        
        if ( $projects_exists !== $projects_table || $sessions_exists !== $sessions_table ) {
            $db->create_tables();
            update_option( 'bizcity_webchat_db_version', BizCity_WebChat_Database::SCHEMA_VERSION );
        }
    }

    /* ================================================================
     *  PROJECT HANDLERS
     * ================================================================ */

    /**
     * List projects for current user.
     */
    public function ajax_project_list() {
        // Guests have no projects — return empty immediately
        if ( ! get_current_user_id() ) {
            wp_send_json_success( [] );
            return;
        }

        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $user_id = get_current_user_id();
        
        // V3: Use database table
        if ( method_exists( $db, 'get_projects_for_user' ) ) {
            $projects = $db->get_projects_for_user( $user_id );
            
            // Format for frontend
            $result = [];
            foreach ( $projects as $p ) {
                $result[] = [
                    'id'            => $p->project_id,
                    'pk'            => (int) $p->id,
                    'name'          => $p->name,
                    'icon'          => $p->icon ?: '📁',
                    'color'         => $p->color ?: '#6366f1',
                    'character_id'  => (int) $p->character_id,
                    'session_count' => (int) $p->session_count,
                    'created'       => $p->created_at,
                ];
            }
            
            wp_send_json_success( $result );
            return;
        }
        
        // Legacy: user_meta
        $projects = get_user_meta( $user_id, 'bizcity_projects', true );
        if ( ! is_array( $projects ) ) {
            $projects = [];
        }

        // Add session counts
        foreach ( $projects as &$p ) {
            $count = $db->wpdb->get_var( $db->wpdb->prepare(
                "SELECT COUNT(*) FROM {$db->wpdb->prefix}bizcity_webchat_conversations WHERE user_id = %d AND project_id = %s AND status = 'active'",
                $user_id,
                $p['id']
            ) );
            $p['session_count'] = (int) $count;
        }

        wp_send_json_success( $projects );
    }

    /**
     * Create a new project.
     * Also auto-creates a character in bizcity_characters and binds knowledge_source to it.
     */
    public function ajax_project_create() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $name = sanitize_text_field( $_POST['name'] ?? '' );
        $icon = sanitize_text_field( $_POST['icon'] ?? '📁' );
        $character_id = intval( $_POST['character_id'] ?? 0 );

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Tên dự án không được để trống' ] );
            return;
        }

        $user_id = get_current_user_id();

        // Auto-create a character for this project if none specified
        if ( ! $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $kdb = BizCity_Knowledge_Database::instance();
            $slug = 'project-' . sanitize_title( $name ) . '-' . substr( wp_generate_uuid4(), 0, 8 );

            $new_char_id = $kdb->create_character( [
                'name'          => $icon . ' ' . $name,
                'slug'          => $slug,
                'description'   => 'Nhân vật cho dự án: ' . $name,
                'system_prompt' => "Bạn là trợ lý AI cho dự án \"{$name}\". Hãy hỗ trợ người dùng dựa trên kiến thức và ngữ cảnh của dự án này.",
                'model_id'      => 'GPT-4o-mini',
                'status'        => 'active',
                'author_id'     => $user_id,
                'owner_type'    => 'project',
                'owner_id'      => '',   // will update after project is created
            ] );

            if ( ! is_wp_error( $new_char_id ) && $new_char_id > 0 ) {
                $character_id = (int) $new_char_id;

                // Create a default knowledge_source placeholder for this character
                $this->create_default_knowledge_source( $kdb, $character_id, $name );
            }
        }

        // V3: Use database table
        if ( method_exists( $db, 'create_project' ) ) {
            $result = $db->create_project( $user_id, $name, [
                'icon'         => $icon,
                'character_id' => $character_id,
            ] );

            // Check if insert actually succeeded
            if ( empty( $result['id'] ) || $result['id'] === 0 ) {
                global $wpdb;
                error_log( '[bizcity-webchat] ajax_project_create: insert failed, last_error=' . $wpdb->last_error );
                wp_send_json_error( [
                    'message' => 'Lỗi tạo dự án trong database: ' . $wpdb->last_error,
                ] );
                return;
            }

            // Update the character's owner_id with the project UUID
            if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
                $kdb = BizCity_Knowledge_Database::instance();
                $kdb->update_character( $character_id, [
                    'owner_id' => $result['project_id'] ?? '',
                ] );
            }

            // Log to Router Console
            $this->log_operation( 'create', 'project', [
                'project_pk'   => $result['id'] ?? 0,
                'project_uuid' => $result['project_id'] ?? '',
                'name'         => $name,
                'character_id' => $character_id,
                'status'       => 'success',
            ] );

            wp_send_json_success( [
                'id'           => $result['project_id'],
                'pk'           => $result['id'],
                'name'         => $name,
                'icon'         => $icon,
                'character_id' => $character_id,
            ] );
            return;
        }

        // Legacy: user_meta
        $projects = get_user_meta( $user_id, 'bizcity_projects', true );
        if ( ! is_array( $projects ) ) {
            $projects = [];
        }

        $project_id = 'proj_' . wp_generate_uuid4();
        $projects[] = [
            'id'           => $project_id,
            'name'         => $name,
            'icon'         => $icon,
            'character_id' => $character_id,
            'created'      => current_time( 'mysql' ),
        ];

        update_user_meta( $user_id, 'bizcity_projects', $projects );

        wp_send_json_success( [
            'id'           => $project_id,
            'name'         => $name,
            'icon'         => $icon,
            'character_id' => $character_id,
        ] );
    }

    /**
     * Create a default knowledge_source entry for a project character.
     *
     * @param BizCity_Knowledge_Database $kdb
     * @param int    $character_id
     * @param string $project_name
     */
    private function create_default_knowledge_source( $kdb, $character_id, $project_name ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return;
        }

        $wpdb->insert( $table, [
            'character_id' => (int) $character_id,
            'source_type'  => 'manual',
            'source_name'  => 'Kiến thức dự án: ' . $project_name,
            'content'      => 'Đây là nguồn kiến thức mặc định cho dự án "' . $project_name . '". Hãy thêm nội dung, FAQ, hoặc tài liệu tham khảo tại đây.',
            'chunks_count' => 0,
            'status'       => 'ready',
            'created_at'   => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * Rename a project.
     */
    public function ajax_project_rename() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $name = sanitize_text_field( $_POST['name'] ?? '' );

        if ( empty( $project_id ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Missing required fields' ] );
            return;
        }

        $user_id = get_current_user_id();

        // V3: Use database table
        if ( method_exists( $db, 'get_project_by_uuid' ) ) {
            $project = $db->get_project_by_uuid( $project_id );
            if ( ! $project || (int) $project->user_id !== $user_id ) {
                wp_send_json_error( [ 'message' => 'Project not found' ] );
                return;
            }
            
            $db->rename_project( (int) $project->id, $name );
            wp_send_json_success();
            return;
        }

        // Legacy: user_meta
        $projects = get_user_meta( $user_id, 'bizcity_projects', true );
        if ( ! is_array( $projects ) ) {
            wp_send_json_error( [ 'message' => 'No projects found' ] );
            return;
        }

        foreach ( $projects as &$p ) {
            if ( $p['id'] === $project_id ) {
                $p['name'] = $name;
                break;
            }
        }

        update_user_meta( $user_id, 'bizcity_projects', $projects );
        wp_send_json_success();
    }

    /**
     * Update project settings (character_id, settings, etc.).
     */
    public function ajax_project_update() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( empty( $project_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing project_id' ] );
            return;
        }

        $user_id = get_current_user_id();

        // V3 only
        if ( ! method_exists( $db, 'get_project_by_uuid' ) ) {
            wp_send_json_error( [ 'message' => 'V3 database required' ] );
            return;
        }

        $project = $db->get_project_by_uuid( $project_id );
        if ( ! $project || (int) $project->user_id !== $user_id ) {
            wp_send_json_error( [ 'message' => 'Project not found' ] );
            return;
        }

        $data = [];
        if ( isset( $_POST['name'] ) ) {
            $data['name'] = sanitize_text_field( $_POST['name'] );
        }
        if ( isset( $_POST['icon'] ) ) {
            $data['icon'] = sanitize_text_field( $_POST['icon'] );
        }
        if ( isset( $_POST['character_id'] ) ) {
            $data['character_id'] = intval( $_POST['character_id'] );
        }
        if ( isset( $_POST['description'] ) ) {
            $data['description'] = sanitize_textarea_field( $_POST['description'] );
        }
        if ( isset( $_POST['is_public'] ) ) {
            $data['is_public'] = intval( $_POST['is_public'] );
        }
        if ( isset( $_POST['knowledge_ids'] ) ) {
            $data['knowledge_ids'] = sanitize_text_field( $_POST['knowledge_ids'] );
        }

        $db->update_project( (int) $project->id, $data );
        
        // Log to Router Console
        $this->log_operation( 'update', 'project', [
            'project_id'   => $project_id,
            'project_pk'   => (int) $project->id,
            'project_name' => $project->name,
            'updated_fields' => array_keys( $data ),
            'character_id' => $data['character_id'] ?? null,
            'status'       => 'success',
        ] );
        
        wp_send_json_success();
    }

    /**
     * Delete a project.
     */
    public function ajax_project_delete() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        if ( empty( $project_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing project_id' ] );
            return;
        }

        $user_id = get_current_user_id();

        // V3: Use database table
        if ( method_exists( $db, 'get_project_by_uuid' ) ) {
            $project = $db->get_project_by_uuid( $project_id );
            if ( ! $project || (int) $project->user_id !== $user_id ) {
                wp_send_json_error( [ 'message' => 'Project not found' ] );
                return;
            }
            
            $db->delete_project( (int) $project->id );
            wp_send_json_success();
            return;
        }

        // Legacy: user_meta
        $projects = get_user_meta( $user_id, 'bizcity_projects', true );
        if ( ! is_array( $projects ) ) {
            wp_send_json_success();
            return;
        }

        $projects = array_filter( $projects, function( $p ) use ( $project_id ) {
            return $p['id'] !== $project_id;
        } );

        update_user_meta( $user_id, 'bizcity_projects', array_values( $projects ) );

        // Unassign sessions from this project (legacy)
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bizcity_webchat_conversations',
            [ 'project_id' => '' ],
            [ 'project_id' => $project_id, 'user_id' => $user_id ]
        );

        wp_send_json_success();
    }

    /* ================================================================
     *  SESSION HANDLERS
     * ================================================================ */

    /**
     * List sessions for current user (optionally filtered by project).
     * Supports search parameter for filtering by title.
     */
    public function ajax_session_list() {
        // Guests have no saved sessions — return empty immediately
        if ( ! get_current_user_id() ) {
            wp_send_json_success( [] );
            return;
        }

        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $user_id    = get_current_user_id();
        $project_id = isset( $_REQUEST['project_id'] ) ? sanitize_text_field( $_REQUEST['project_id'] ) : null;
        $search     = isset( $_REQUEST['search'] ) ? sanitize_text_field( $_REQUEST['search'] ) : '';

        // V3: Use new sessions table
        $sessions = [];
        if ( method_exists( $db, 'get_sessions_v3_for_user' ) ) {
            $sessions = $db->get_sessions_v3_for_user( $user_id, 'ADMINCHAT', $search ? 100 : 50, $project_id );
        } else {
            $sessions = $db->get_sessions_for_user( $user_id, 'ADMINCHAT', $search ? 100 : 50, $project_id );
        }

        $result = [];
        foreach ( $sessions as $s ) {
            // Skip archived sessions in normal list
            if ( ( $s->status ?? 'active' ) === 'archived' ) {
                continue;
            }

            // Filter by search term if provided
            if ( $search ) {
                $title_match = stripos( $s->title ?? '', $search ) !== false;
                $preview_match = stripos( $s->last_message_preview ?? '', $search ) !== false;
                if ( ! $title_match && ! $preview_match ) {
                    continue;
                }
            }

            $meta = ! empty( $s->meta ) ? json_decode( $s->meta, true ) : [];
            
            $result[] = [
                'id'            => (int) $s->id,
                'session_id'    => $s->session_id,
                'title'         => $s->title ?: '',
                'project_id'    => $s->project_id ?? '',
                'message_count' => (int) ( $s->message_count ?? 0 ),
                'last_message'  => $s->last_message_preview ?? '',
                'started_at'    => $s->started_at,
                'last_activity' => $s->last_message_at ?? $s->started_at,
                'is_pinned'     => ! empty( $meta['is_pinned'] ),
                'status'        => $s->status ?? 'active',
            ];
        }

        wp_send_json_success( $result );
    }

    /**
     * Create a new session.
     */
    public function ajax_session_create() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $user_id     = get_current_user_id();
        $client_name = $user_id ? wp_get_current_user()->display_name : 'Guest';
        $project_id  = sanitize_text_field( $_POST['project_id'] ?? '' );
        $title       = sanitize_text_field( $_POST['title'] ?? '' );

        // V3: Use new sessions table
        if ( method_exists( $db, 'create_session_v3' ) ) {
            $result = $db->create_session_v3( $user_id, $client_name, 'ADMINCHAT', $title, [
                'project_id' => $project_id,
            ] );
            
            // Log to Router Console
            $this->log_operation( 'create', 'session', [
                'session_pk'    => $result['id'] ?? 0,
                'session_uuid'  => $result['session_id'] ?? '',
                'title'         => $title ?: '(auto)',
                'project_id'    => $project_id ?: '(none)',
                'status'        => 'success',
            ], $result['session_id'] ?? '' );
            
            wp_send_json_success( $result );
            return;
        }

        // Legacy
        $result = $db->create_session( $user_id, $client_name, 'ADMINCHAT', $title );
        
        // Update project_id if provided
        if ( ! empty( $project_id ) && ! empty( $result['id'] ) ) {
            $db->update_session_project( (int) $result['id'], $project_id );
        }

        wp_send_json_success( $result );
    }

    /**
     * Rename a session.
     */
    public function ajax_session_rename() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = intval( $_POST['session_id'] ?? 0 );
        $title      = sanitize_text_field( $_POST['title'] ?? '' );

        if ( ! $session_id || empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Missing required fields' ] );
            return;
        }

        // Verify ownership
        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : $db->get_session( $session_id );
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        // V3
        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_id, [ 'title' => $title, 'title_generated' => 0 ] );
        } else {
            $db->update_session_title( $session_id, $title );
        }

        // Log to Router Console
        $this->log_operation( 'rename', 'session', [
            'session_pk'    => $session_id,
            'session_uuid'  => $session->session_id ?? '',
            'old_title'     => $session->title ?? '',
            'new_title'     => $title,
            'status'        => 'success',
        ], $session->session_id ?? '' );

        wp_send_json_success();
    }

    /**
     * Move session to a project.
     */
    public function ajax_session_move() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) {
            return;
        }

        $session_id = intval( $_POST['session_id'] ?? 0 );
        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );

        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        // Verify ownership
        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : null;

        if ( ! $session && method_exists( $db, 'get_session_by_uuid' ) ) {
            $session = $db->get_session_by_uuid( $session_id );
        }

        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        $old_project_id = $session->project_id ?? '';
        
        // V3: move
        $move_result = false;
        if ( method_exists( $db, 'move_session_to_project' ) ) {
            $move_result = $db->move_session_to_project( (int) $session->id, $project_id );
        } else {
            wp_send_json_error( [ 'message' => 'move_session_to_project method not found' ] );
            return;
        }

        // Log to Router Console
        $this->log_operation( 'move', 'session', [
            'session_pk'     => $session_id,
            'session_uuid'   => $session->session_id ?? '',
            'session_title'  => $session->title ?? '',
            'from_project'   => $old_project_id ?: '(root)',
            'to_project'     => $project_id ?: '(root)',
            'status'         => 'success',
        ], $session->session_id ?? '' );

        wp_send_json_success( [
            'moved'        => true,
            'session_pk'   => (int) $session->id,
            'from_project' => $old_project_id,
            'to_project'   => $project_id,
        ] );
    }

    /**
     * Delete a session.
     */
    public function ajax_session_delete() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = intval( $_POST['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        // Verify ownership
        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : $db->get_session( $session_id );
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        $session_uuid  = $session->session_id ?? '';
        $session_title = $session->title ?? '';
        
        // V3
        if ( method_exists( $db, 'delete_session_v3' ) ) {
            $db->delete_session_v3( $session_id );
        } else {
            $db->delete_session( $session_id );
        }

        // Log to Router Console
        $this->log_operation( 'delete', 'session', [
            'session_pk'    => $session_id,
            'session_uuid'  => $session_uuid,
            'session_title' => $session_title,
            'status'        => 'success',
        ], $session_uuid );

        wp_send_json_success();
    }

    /**
     * Get messages for a session.
     */
    public function ajax_session_messages() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $raw_id = $_REQUEST['session_id'] ?? '';
        if ( empty( $raw_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        // Accept both numeric PK (e.g. 123) and UUID string (e.g. wcs_xxx, adminchat_X_Y)
        $session = null;
        if ( is_numeric( $raw_id ) ) {
            $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( intval( $raw_id ) ) : $db->get_session( intval( $raw_id ) );
        } else {
            $session = method_exists( $db, 'get_session_v3_by_session_id' ) ? $db->get_session_v3_by_session_id( sanitize_text_field( $raw_id ) ) : null;
        }
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        // Query messages by UUID session_id (column in messages table), NOT by PK
        $session_uuid = $session->session_id ?? '';
        $messages = $db->get_messages_by_session_id( $session_uuid, 100 );

        $result = [];
        foreach ( $messages as $m ) {
            $result[] = [
                'id'          => (int) $m->id,
                'text'        => $m->message_text,
                'from'        => $m->message_from,
                'client_name' => $m->client_name ?? '',
                'created_at'  => $m->created_at,
                'created_ts'  => isset( $m->created_ts ) ? (int) $m->created_ts : 0,
                'attachments' => $m->attachments ? json_decode( $m->attachments, true ) : [],
                'plugin_slug' => $m->plugin_slug ?? '',
                'rating'      => $m->rating ?? '',
                'is_pinned'   => (int) ( $m->is_pinned ?? 0 ),
            ];
        }

        // Return session PK + UUID + messages so JS can sync sessionId & currentWcId
        wp_send_json_success( [
            'id'         => (int) $session->id,
            'session_id' => $session_uuid,
            'messages'   => $result,
        ] );
    }

    /**
     * Poll for new messages since a given message ID.
     * Lightweight endpoint for realtime push-back (e.g. tarot result).
     */
    public function ajax_session_poll() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = sanitize_text_field( $_REQUEST['session_id'] ?? '' );
        $since_id   = intval( $_REQUEST['since_id'] ?? 0 );

        if ( empty( $session_id ) || ! $since_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id or since_id' ] );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table} WHERE session_id = %s AND id > %d ORDER BY id ASC LIMIT 20",
            $session_id,
            $since_id
        ) );

        $result = [];
        foreach ( $rows as $m ) {
            $result[] = [
                'id'          => (int) $m->id,
                'text'        => $m->message_text,
                'from'        => $m->message_from,
                'client_name' => $m->client_name ?? '',
                'created_at'  => $m->created_at,
                'created_ts'  => (int) $m->created_ts,
                'attachments' => ! empty( $m->attachments ) ? json_decode( $m->attachments, true ) : [],
                'plugin_slug' => $m->plugin_slug ?? '',
                'rating'      => $m->rating ?? '',
                'is_pinned'   => (int) ( $m->is_pinned ?? 0 ),
            ];
        }

        wp_send_json_success( [ 'messages' => $result ] );
    }

    /**
     * AI-generate a short title for a session based on first user message + bot reply.
     * Falls back to simple truncation if AI is unavailable.
     */
    public function ajax_session_gen_title() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_pk  = intval( $_POST['session_id'] ?? 0 );
        $user_msg    = sanitize_text_field( $_POST['user_message'] ?? '' );
        $bot_reply   = sanitize_text_field( $_POST['bot_reply'] ?? '' );

        if ( ! $session_pk ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        // Verify ownership
        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_pk ) : null;
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        // Skip if title was manually set by user (not auto-generated)
        if ( ! empty( $session->title ) && (int) ( $session->title_generated ?? 0 ) === 0 ) {
            wp_send_json_success( [ 'title' => $session->title, 'source' => 'manual' ] );
            return;
        }

        // Try AI-powered title generation
        $title = $this->generate_ai_title( $user_msg, $bot_reply );
        $source = 'ai';

        // Fallback: truncate user message
        if ( empty( $title ) ) {
            $title = $this->truncate_title( $user_msg );
            $source = 'truncate';
        }

        // Save to DB
        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_pk, [
                'title'           => $title,
                'title_generated' => 1,
            ] );
        }

        // Log to Router Console
        $this->log_operation( 'gen_title', 'session', [
            'session_pk'  => $session_pk,
            'title'       => $title,
            'source'      => $source,
        ], $session->session_id ?? '' );

        wp_send_json_success( [ 'title' => $title, 'source' => $source ] );
    }

    /**
     * Use OpenRouter AI to generate a concise session title.
     *
     * @param string $user_msg  First user message
     * @param string $bot_reply First bot reply
     * @return string|null  Title or null on failure
     */
    private function generate_ai_title( $user_msg, $bot_reply ) {
        if ( ! class_exists( 'BizCity_OpenRouter_API' ) ) {
            return null;
        }

        $api = BizCity_OpenRouter_API::instance();
        if ( ! $api->is_ready() ) {
            return null;
        }

        $prompt  = "Hãy tạo tiêu đề ngắn gọn (3-6 từ tiếng Việt) cho cuộc hội thoại này.\n";
        $prompt .= "Người dùng: " . mb_substr( $user_msg, 0, 200 ) . "\n";
        if ( ! empty( $bot_reply ) ) {
            $prompt .= "Trợ lý: " . mb_substr( $bot_reply, 0, 200 ) . "\n";
        }
        $prompt .= "\nChỉ trả về tiêu đề, không giải thích, không dấu ngoặc kép.";

        $result = $api->chat(
            [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            [
                'purpose'       => 'classify',        // uses fast/cheap model
                'max_tokens'    => 30,
                'temperature'   => 0.5,
                'no_fallback'   => true,               // don't waste fallback on title gen
            ]
        );

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $title = trim( $result['message'], " \t\n\r\"'" );
            // Sanity check: reasonable length
            if ( mb_strlen( $title ) >= 2 && mb_strlen( $title ) <= 80 ) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Simple title by truncating user message.
     */
    private function truncate_title( $message ) {
        $message = trim( preg_replace( '/\s+/', ' ', $message ) );
        if ( empty( $message ) ) return 'Hội thoại mới';
        if ( mb_strlen( $message ) <= 40 ) return $message;
        $t = mb_substr( $message, 0, 40 );
        $sp = mb_strrpos( $t, ' ' );
        if ( $sp > 20 ) $t = mb_substr( $t, 0, $sp );
        return $t . '...';
    }

    /**
     * Close all active sessions for current user.
     */
    public function ajax_close_all_sessions() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $user_id = get_current_user_id();
        $count   = $db->close_all_sessions( $user_id, 'ADMINCHAT' );

        wp_send_json_success( [ 'closed' => $count ] );
    }

    /* ================================================================
     *  PIN / ARCHIVE HANDLERS
     * ================================================================ */

    /**
     * Toggle pin status for a session (stored in meta JSON).
     */
    public function ajax_session_pin() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = intval( $_POST['session_id'] ?? 0 );
        $pinned     = ! empty( $_POST['pinned'] );

        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : null;
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        $meta = ! empty( $session->meta ) ? json_decode( $session->meta, true ) : [];
        $meta['is_pinned'] = $pinned;

        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_id, [ 'meta' => wp_json_encode( $meta ) ] );
        }

        wp_send_json_success( [ 'pinned' => $pinned ] );
    }

    /**
     * Archive a session (set status to 'archived').
     */
    public function ajax_session_archive() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = intval( $_POST['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : null;
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_id, [ 'status' => 'archived' ] );
        }

        $this->log_operation( 'archive', 'session', [
            'session_pk'    => $session_id,
            'session_uuid'  => $session->session_id ?? '',
            'session_title' => $session->title ?? '',
            'status'        => 'success',
        ], $session->session_id ?? '' );

        wp_send_json_success();
    }

    /**
     * Restore an archived session (set status back to 'active').
     */
    public function ajax_session_restore() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $session_id = intval( $_POST['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            return;
        }

        $session = method_exists( $db, 'get_session_v3' ) ? $db->get_session_v3( $session_id ) : null;
        if ( ! $session || (int) $session->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Session not found' ] );
            return;
        }

        if ( method_exists( $db, 'update_session_v3' ) ) {
            $db->update_session_v3( $session_id, [ 'status' => 'active' ] );
        }

        wp_send_json_success();
    }

    /**
     * List archived sessions for current user.
     */
    public function ajax_archived_sessions() {
        $db = $this->verify_and_get_db();
        if ( ! $db ) return;

        $user_id = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND platform_type = 'ADMINCHAT' AND status = 'archived' ORDER BY last_message_at DESC LIMIT 50",
            $user_id
        ) );

        $result = [];
        foreach ( $rows as $s ) {
            $result[] = [
                'id'            => (int) $s->id,
                'session_id'    => $s->session_id,
                'title'         => $s->title ?: '',
                'project_id'    => $s->project_id ?? '',
                'started_at'    => $s->started_at,
                'last_activity' => $s->last_message_at ?? $s->started_at,
            ];
        }

        wp_send_json_success( $result );
    }

    /* ================================================================
     *  LLM Settings — handlers for React SettingsPanel
     * ================================================================ */

    /**
     * Verify nonce + admin capability for LLM settings.
     */
    private function verify_llm_admin(): bool {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return false;
        }
        $cap = 'manage_options';
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền quản trị.' ], 403 );
            return false;
        }
        return true;
    }

    /**
     * GET LLM settings — mode, key preview, gateway URL, models config, usage stats.
     */
    public function ajax_llm_get_settings(): void {
        if ( ! $this->verify_llm_admin() ) return;

        $mode        = get_site_option( 'bizcity_llm_mode', 'gateway' );
        $api_key     = get_site_option( 'bizcity_llm_api_key', '' );
        $gateway_url = get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );
        $tavily_key  = get_site_option( 'bizcity_tavily_api_key', '' );
        $settings    = get_site_option( 'bizcity_llm_settings', [] );

        // Purpose-model mapping
        $models = [];
        $purposes = class_exists( 'BizCity_LLM_Models' ) ? BizCity_LLM_Models::purposes() : [];
        foreach ( $purposes as $purpose ) {
            $defaults  = class_exists( 'BizCity_LLM_Models' ) ? BizCity_LLM_Models::DEFAULTS : [];
            $fallbacks = class_exists( 'BizCity_LLM_Models' ) ? BizCity_LLM_Models::FALLBACK_DEFAULTS : [];
            $models[ $purpose ] = [
                'model'    => $settings[ 'model_' . $purpose ] ?? ( $defaults[ $purpose ] ?? '' ),
                'fallback' => $settings[ 'model_fallback_' . $purpose ] ?? ( $fallbacks[ $purpose ] ?? '' ),
                'no_fallback' => ! empty( $settings[ 'no_fallback_' . $purpose ] ),
            ];
        }

        // Available models catalog
        $catalog = [];
        if ( class_exists( 'BizCity_LLM_Client' ) ) {
            $available = BizCity_LLM_Client::instance()->get_available_models();
            foreach ( $available as $m ) {
                $catalog[] = [
                    'id'      => $m['id'] ?? '',
                    'name'    => $m['name'] ?? $m['id'] ?? '',
                    'context' => $m['context_length'] ?? $m['ctx'] ?? 0,
                ];
            }
            usort( $catalog, fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );
        }

        // Usage stats (24h / 7d)
        $stats = [];
        if ( class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            $stats = [
                'day'        => BizCity_LLM_Usage_Log::get_stats( 1 ),
                'week'       => BizCity_LLM_Usage_Log::get_stats( 7 ),
                'top_models' => BizCity_LLM_Usage_Log::get_top_models( 5 ),
            ];
        }

        wp_send_json_success( [
            'mode'        => $mode,
            'apiKey'      => $api_key ? substr( $api_key, 0, 8 ) . '••••••••' : '',
            'hasKey'      => ! empty( $api_key ),
            'gatewayUrl'  => $gateway_url,
            'tavilyKey'   => $tavily_key ? substr( $tavily_key, 0, 6 ) . '••••' : '',
            'hasTavilyKey'=> ! empty( $tavily_key ),
            'siteName'    => $settings['site_name'] ?? '',
            'timeout'     => (int) ( $settings['timeout'] ?? 60 ),
            'models'      => $models,
            'purposes'    => $purposes,
            'catalog'     => $catalog,
            'stats'       => $stats,
        ] );
    }

    /**
     * SAVE LLM settings from React panel.
     */
    public function ajax_llm_save_settings(): void {
        if ( ! $this->verify_llm_admin() ) return;

        $mode = sanitize_text_field( $_POST['mode'] ?? 'gateway' );
        if ( ! in_array( $mode, [ 'gateway', 'direct' ], true ) ) $mode = 'gateway';
        update_site_option( 'bizcity_llm_mode', $mode );

        if ( ! empty( $_POST['gateway_url'] ) ) {
            update_site_option( 'bizcity_llm_gateway_url', esc_url_raw( $_POST['gateway_url'] ) );
        }

        // Only update API key if a new one is explicitly provided (not the masked preview)
        if ( ! empty( $_POST['api_key'] ) && strpos( $_POST['api_key'], '••' ) === false ) {
            update_site_option( 'bizcity_llm_api_key', sanitize_text_field( $_POST['api_key'] ) );
        }

        if ( isset( $_POST['tavily_key'] ) && strpos( $_POST['tavily_key'], '••' ) === false ) {
            update_site_option( 'bizcity_tavily_api_key', sanitize_text_field( $_POST['tavily_key'] ) );
        }

        // Settings array
        $settings = get_site_option( 'bizcity_llm_settings', [] );
        if ( isset( $_POST['site_name'] ) ) {
            $settings['site_name'] = sanitize_text_field( $_POST['site_name'] );
        }
        if ( isset( $_POST['timeout'] ) ) {
            $settings['timeout'] = max( 10, min( 300, intval( $_POST['timeout'] ) ) );
        }

        // Purpose-model assignments (sent as JSON)
        if ( ! empty( $_POST['models_json'] ) ) {
            $models_data = json_decode( wp_unslash( $_POST['models_json'] ), true );
            if ( is_array( $models_data ) ) {
                foreach ( $models_data as $purpose => $cfg ) {
                    $purpose = sanitize_key( $purpose );
                    if ( isset( $cfg['model'] ) ) {
                        $settings[ 'model_' . $purpose ] = sanitize_text_field( $cfg['model'] );
                    }
                    if ( isset( $cfg['fallback'] ) ) {
                        $settings[ 'model_fallback_' . $purpose ] = sanitize_text_field( $cfg['fallback'] );
                    }
                    $settings[ 'no_fallback_' . $purpose ] = ! empty( $cfg['no_fallback'] ) ? 1 : 0;
                }
            }
        }

        update_site_option( 'bizcity_llm_settings', $settings );

        if ( class_exists( 'BizCity_LLM_Client' ) ) {
            BizCity_LLM_Client::instance()->bust_models_cache();
        }

        wp_send_json_success( [ 'message' => 'Cài đặt đã được lưu.' ] );
    }

    /**
     * Test LLM connection (gateway or direct).
     */
    public function ajax_llm_test_connection(): void {
        if ( ! $this->verify_llm_admin() ) return;

        $mode = get_site_option( 'bizcity_llm_mode', 'gateway' );
        $key  = get_site_option( 'bizcity_llm_api_key', '' );

        if ( empty( $key ) ) {
            wp_send_json_error( [ 'message' => 'Chưa có API key. Hãy nhập hoặc đăng ký key trước.' ] );
            return;
        }

        if ( $mode === 'gateway' ) {
            $gateway = class_exists( 'BizCity_LLM_Client' )
                ? BizCity_LLM_Client::instance()->get_gateway_url()
                : get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );
            // [2026-03-25] Unified API namespace: migrate llm/router/v1/models → bizcity/v1/llm/models
            // $url = $gateway . '/wp-json/llm/router/v1/models';
            $url = $gateway . '/wp-json/bizcity/v1/llm/models';
        } else {
            $url = 'https://openrouter.ai/api/v1/models';
        }

        $response = wp_remote_get( $url, [
            'timeout'     => 15,
            'redirection' => 0, // Don't follow redirects — auth header gets stripped
            'headers'     => [ 'Authorization' => 'Bearer ' . $key ],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
            return;
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $raw_body     = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        // Redirect → auth header was about to be stripped; surface the redirect URL
        if ( $code >= 300 && $code < 400 ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            wp_send_json_error( [
                'message' => "HTTP {$code} redirect → {$location}. Hãy cập nhật Gateway URL cho đúng (bao gồm https://).",
            ] );
            return;
        }

        // Strip BOM if present & trim whitespace
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $raw_body = trim( $raw_body );

        $body = json_decode( $raw_body, true );

        // Non-JSON response (WAF block, HTML error page, etc.)
        if ( $body === null && json_last_error() !== JSON_ERROR_NONE ) {
            $preview  = mb_substr( strip_tags( $raw_body ), 0, 200 );
            $json_err = json_last_error_msg();
            wp_send_json_error( [
                'message' => "HTTP {$code} — JSON decode failed: {$json_err} (Content-Type: {$content_type}). Preview: {$preview}",
            ] );
            return;
        }

        if ( $code === 200 ) {
            // Server returned 200 but marked success=false → auth/server error
            if ( isset( $body['success'] ) && ! $body['success'] ) {
                wp_send_json_error( [
                    'message' => $body['error'] ?? $body['message'] ?? 'Server returned success=false.',
                ] );
                return;
            }

            $count = count( $body['data'] ?? $body['models'] ?? [] );
            wp_send_json_success( [
                'message'    => "Kết nối thành công — {$count} model khả dụng.",
                'modelCount' => $count,
            ] );
        } else {
            wp_send_json_error( [
                'message' => $body['error']['message'] ?? $body['message'] ?? $body['error'] ?? "HTTP {$code}",
            ] );
        }
    }

    /**
     * Register a new API key from gateway.
     */
    public function ajax_llm_register_api_key(): void {
        if ( ! $this->verify_llm_admin() ) return;

        $gateway = class_exists( 'BizCity_LLM_Client' )
            ? BizCity_LLM_Client::instance()->get_gateway_url()
            : get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );

        $site  = home_url();
        $label = sanitize_text_field( $_POST['label'] ?? wp_parse_url( $site, PHP_URL_HOST ) );
        $email = get_option( 'admin_email', '' );
        if ( empty( $email ) ) {
            $email = get_site_option( 'admin_email', '' );
        }

        // [2026-03-25] Unified API namespace: migrate bizcity/llmhub/v1/register-key → bizcity/v1/register-key
        // $response = wp_remote_post( $gateway . '/wp-json/bizcity/llmhub/v1/register-key', [
        $response = wp_remote_post( $gateway . '/wp-json/bizcity/v1/register-key', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'site_url' => $site,
                'label'    => $label,
                'email'    => $email,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['api_key'] ) ) {
            update_site_option( 'bizcity_llm_api_key', sanitize_text_field( $body['api_key'] ) );
            update_site_option( 'bizcity_llm_mode', 'gateway' );
            wp_send_json_success( [
                'message'    => 'API key đã được tạo và lưu tự động!',
                'keyPreview' => substr( $body['api_key'], 0, 12 ) . '…',
            ] );
        } else {
            wp_send_json_error( [
                'message' => $body['message'] ?? $body['error'] ?? "HTTP {$code} — Đăng ký thất bại.",
            ] );
        }
    }

    /**
     * Get usage stats + recent log entries.
     */
    public function ajax_llm_get_usage(): void {
        if ( ! $this->verify_llm_admin() ) return;

        if ( ! class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            wp_send_json_success( [ 'available' => false ] );
            return;
        }

        $page  = max( 1, intval( $_POST['page'] ?? 1 ) );
        $limit = 30;

        wp_send_json_success( [
            'available'  => true,
            'statsDay'   => BizCity_LLM_Usage_Log::get_stats( 1 ),
            'statsWeek'  => BizCity_LLM_Usage_Log::get_stats( 7 ),
            'topModels'  => BizCity_LLM_Usage_Log::get_top_models( 5 ),
            'recent'     => BizCity_LLM_Usage_Log::get_recent( $limit, ( $page - 1 ) * $limit ),
            'page'       => $page,
        ] );
    }

    /* ================================================================
     * Intent Conversations (Tasks / Nhiệm vụ)
     * ================================================================ */

    /**
     * List active intent conversations for current user.
     */
    public function ajax_intent_conversations(): void {
        // Guests have no intent conversations — return empty immediately
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_success( [] );
            return;
        }

        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid nonce.', 403 );
            return;
        }

        if ( ! class_exists( 'BizCity_Task_Service' ) ) {
            wp_send_json_success( [] );
            return;
        }

        $service = BizCity_Task_Service::instance();
        $result  = $service->list_tasks( $user_id, [
            'channel'  => 'adminchat',
            'status'   => 'all',
            'per_page' => 20,
        ] );

        wp_send_json_success( $result['items'] ?? [] );
    }

    /**
     * Cancel an intent conversation.
     */
    public function ajax_intent_cancel(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid nonce.', 403 );
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Not logged in.', 403 );
            return;
        }

        $conv_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
        if ( ! $conv_id ) {
            wp_send_json_error( 'Missing conversation_id.' );
            return;
        }

        if ( ! class_exists( 'BizCity_Intent_Conversation' ) ) {
            wp_send_json_error( 'Intent module not available.' );
            return;
        }

        BizCity_Intent_Conversation::instance()->cancel( $conv_id, 'user_cancel' );
        wp_send_json_success( [ 'cancelled' => true ] );
    }

    /**
     * Mark an intent conversation as completed.
     */
    public function ajax_intent_complete(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( 'Invalid nonce.', 403 );
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Not logged in.', 403 );
            return;
        }

        $conv_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
        if ( ! $conv_id ) {
            wp_send_json_error( 'Missing conversation_id.' );
            return;
        }

        if ( ! class_exists( 'BizCity_Intent_Conversation' ) ) {
            wp_send_json_error( 'Intent module not available.' );
            return;
        }

        BizCity_Intent_Conversation::instance()->complete( $conv_id );
        wp_send_json_success( [ 'completed' => true ] );
    }

    /* ================================================================
     * TRACE HISTORY — Working Panel reads from trace tables
     * Sprint 1.7 — replaces transient-only source with DB persistence
     * ================================================================ */

    /**
     * Fetch trace history for session or user (Working Panel).
     *
     * Super admins (`manage_network`) can query any user's traces via `user_id` param.
     * Regular users only see their own traces.
     *
     * POST params:
     *   session_id  (string) — fetch traces for this session
     *   user_id     (int)    — optional, super admin only: fetch another user's traces
     *   limit       (int)    — max traces to return (default 10, max 50)
     */
    public function ajax_fetch_trace_history(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        if ( ! class_exists( 'BizCity_Trace_Store' ) ) {
            wp_send_json_error( [ 'message' => 'Trace store not available' ], 500 );
            return;
        }

        $session_id     = sanitize_text_field( $_POST['session_id'] ?? '' );
        $requested_uid  = absint( $_POST['user_id'] ?? 0 );
        $limit          = min( absint( $_POST['limit'] ?? 10 ), 50 );
        $current_uid    = get_current_user_id();
        $is_super_admin = current_user_can( 'manage_network' );

        // Determine target user_id
        $target_uid = $current_uid;
        if ( $requested_uid && $requested_uid !== $current_uid ) {
            if ( ! $is_super_admin ) {
                wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
                return;
            }
            $target_uid = $requested_uid;
        }

        $store  = BizCity_Trace_Store::instance();
        $traces = [];

        if ( $session_id ) {
            $traces = $store->get_session_traces( $session_id, $limit );
        } else {
            $traces = $store->get_user_traces( $target_uid, $limit );
        }

        // For each trace, fetch its tasks (steps/thinking)
        $result = [];
        foreach ( $traces as $trace ) {
            $tasks = $store->get_tasks( $trace['trace_id'] );

            // Convert tasks to WorkingPanel-compatible format
            $steps = [];
            foreach ( $tasks as $task ) {
                $step_data = [
                    'thinking'        => $task['thinking'] ?: '',
                    'tool_name'       => $task['tool_name'] ?: '',
                    'skill_resolve'   => $task['skill_resolve'] ?: '',
                    'status'          => $task['status'],
                    'elapsed_ms'      => (int) $task['duration_ms'],
                ];

                // Decode context layers for rich display
                if ( ! empty( $task['context_summary'] ) ) {
                    $ctx = json_decode( $task['context_summary'], true );
                    if ( is_array( $ctx ) ) {
                        $step_data['context_layers'] = $ctx;
                    }
                }
                if ( ! empty( $task['token_usage'] ) ) {
                    $tok = json_decode( $task['token_usage'], true );
                    if ( is_array( $tok ) ) {
                        $step_data['token_usage'] = $tok;
                    }
                }
                if ( ! empty( $task['error_message'] ) ) {
                    $step_data['error'] = $task['error_message'];
                }

                $steps[] = [
                    'step'     => $task['step'],
                    'thinking' => $task['thinking'] ?: '',
                    'ts'       => strtotime( $task['created_at'] ) * 1000,
                    'ms'       => (int) $task['duration_ms'],
                    'data'     => $step_data,
                ];
            }

            $result[] = [
                'trace_id'   => $trace['trace_id'],
                'session_id' => $trace['session_id'],
                'title'      => $trace['title'],
                'status'     => $trace['status'],
                'mode'       => $trace['mode'],
                'skill_key'  => $trace['skill_key'],
                'total_ms'   => (int) $trace['total_ms'],
                'tokens'     => (int) $trace['input_tokens'] + (int) $trace['output_tokens'],
                'created_at' => $trace['created_at'],
                'user_id'    => (int) $trace['user_id'],
                'steps'      => $steps,
            ];
        }

        wp_send_json_success( [
            'traces'         => $result,
            'is_super_admin' => $is_super_admin,
            'target_user_id' => $target_uid,
        ] );
    }

    /* ══════════════════════════════════════════════════════
     *  Phase 1.8 — Message Actions (Pin / Rate)
     * ══════════════════════════════════════════════════════ */

    /**
     * Pin a bot message → create a note in webchat_notes.
     */
    public function ajax_pin_message(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $message_id = absint( $_POST['message_id'] ?? 0 );
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $user_id    = get_current_user_id();

        if ( ! $message_id ) {
            wp_send_json_error( [ 'message' => 'Missing message_id' ] );
            return;
        }

        $table_msg   = $wpdb->prefix . 'bizcity_webchat_messages';
        $table_notes = $wpdb->prefix . 'bizcity_webchat_notes';

        // Get message content
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT message_text, session_id FROM {$table_msg} WHERE id = %d",
            $message_id
        ) );

        if ( ! $msg ) {
            wp_send_json_error( [ 'message' => 'Message not found' ] );
            return;
        }

        $sid = $session_id ?: $msg->session_id;
        $title = mb_substr( wp_strip_all_tags( $msg->message_text ), 0, 100, 'UTF-8' );

        // Create note
        $wpdb->insert( $table_notes, [
            'session_id'        => $sid,
            'user_id'           => $user_id,
            'note_type'         => 'chat_pinned',
            'title'             => $title,
            'content'           => $msg->message_text,
            'source_message_id' => $message_id,
            'created_at'        => current_time( 'mysql' ),
        ] );

        $note_id = $wpdb->insert_id;

        // Mark message as pinned
        $wpdb->update( $table_msg, [ 'is_pinned' => 1 ], [ 'id' => $message_id ] );

        wp_send_json_success( [
            'note_id'    => $note_id,
            'title'      => $title,
            'content'    => $msg->message_text,
            'note_type'  => 'chat_pinned',
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Rate a message (good / bad).
     */
    public function ajax_rate_message(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $message_id = absint( $_POST['message_id'] ?? 0 );
        $rating     = sanitize_text_field( $_POST['rating'] ?? '' );

        if ( ! $message_id || ! in_array( $rating, [ 'good', 'bad', '' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid params' ] );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'bizcity_webchat_messages',
            [ 'rating' => $rating ],
            [ 'id' => $message_id ]
        );

        wp_send_json_success( true );
    }

    /* ══════════════════════════════════════════════════════
     *  Phase 1.8 — Notes CRUD
     * ══════════════════════════════════════════════════════ */

    /**
     * Get notes for a session.
     */
    public function ajax_get_notes(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $user_id    = get_current_user_id();
        $search     = sanitize_text_field( $_POST['search'] ?? '' );
        $table      = $wpdb->prefix . 'bizcity_webchat_notes';

        $where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
        if ( $session_id ) {
            $where .= $wpdb->prepare( " AND session_id = %s", $session_id );
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND (title LIKE %s OR content LIKE %s)", $like, $like );
        }

        $notes = $wpdb->get_results(
            "SELECT id, session_id, note_type, title, LEFT(content, 500) AS content, source_message_id, created_at, updated_at
             FROM {$table} {$where} ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );

        wp_send_json_success( [ 'notes' => $notes ?: [] ] );
    }

    /**
     * Create a note manually.
     */
    public function ajax_create_note(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $title      = sanitize_text_field( $_POST['title'] ?? '' );
        $content    = wp_kses_post( $_POST['content'] ?? '' );
        $note_type  = sanitize_text_field( $_POST['note_type'] ?? 'quick_note' );
        $user_id    = get_current_user_id();

        if ( ! $title && ! $content ) {
            wp_send_json_error( [ 'message' => 'Title or content required' ] );
            return;
        }

        $table = $wpdb->prefix . 'bizcity_webchat_notes';
        $wpdb->insert( $table, [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'note_type'  => $note_type,
            'title'      => $title ?: mb_substr( wp_strip_all_tags( $content ), 0, 100, 'UTF-8' ),
            'content'    => $content,
            'created_at' => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [
            'id'         => $wpdb->insert_id,
            'title'      => $title,
            'content'    => $content,
            'note_type'  => $note_type,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Delete a note.
     */
    public function ajax_delete_note(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $note_id = absint( $_POST['note_id'] ?? 0 );
        $user_id = get_current_user_id();

        if ( ! $note_id ) {
            wp_send_json_error( [ 'message' => 'Missing note_id' ] );
            return;
        }

        $table   = $wpdb->prefix . 'bizcity_webchat_notes';
        $deleted = $wpdb->delete( $table, [ 'id' => $note_id, 'user_id' => $user_id ] );

        if ( $deleted ) {
            wp_send_json_success( true );
        } else {
            wp_send_json_error( [ 'message' => 'Note not found or access denied' ] );
        }
    }

    /* ══════════════════════════════════════════════════════
     *  Phase 1.8 — Sources CRUD
     * ══════════════════════════════════════════════════════ */

    /**
     * Get sources for a session or user.
     */
    public function ajax_get_sources(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $user_id    = get_current_user_id();
        $table      = $wpdb->prefix . 'bizcity_webchat_sources';

        $where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
        if ( $session_id ) {
            $where .= $wpdb->prepare( " AND (session_id = %s OR session_id = '')", $session_id );
        }

        $sources = $wpdb->get_results(
            "SELECT id, session_id, source_type, title, url, embedding_status, chunk_count, created_at
             FROM {$table} {$where} ORDER BY created_at DESC LIMIT 50",
            ARRAY_A
        );

        wp_send_json_success( [ 'sources' => $sources ?: [] ] );
    }

    /**
     * Add a source (URL, text, or file reference).
     */
    public function ajax_add_source(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $session_id  = sanitize_text_field( $_POST['session_id'] ?? '' );
        $source_type = sanitize_text_field( $_POST['source_type'] ?? 'url' );
        $title       = sanitize_text_field( $_POST['title'] ?? '' );
        $url         = esc_url_raw( $_POST['url'] ?? '' );
        $content     = wp_kses_post( $_POST['content'] ?? '' );
        $user_id     = get_current_user_id();

        if ( ! in_array( $source_type, [ 'url', 'text', 'file' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid source_type' ] );
            return;
        }

        if ( $source_type === 'url' && ! $url ) {
            wp_send_json_error( [ 'message' => 'URL required' ] );
            return;
        }
        if ( $source_type === 'text' && ! $content ) {
            wp_send_json_error( [ 'message' => 'Content required' ] );
            return;
        }

        // Auto-generate title from URL if missing
        if ( ! $title ) {
            if ( $url ) {
                $title = wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH );
                $title = mb_substr( $title, 0, 200, 'UTF-8' );
            } else {
                $title = mb_substr( wp_strip_all_tags( $content ), 0, 100, 'UTF-8' );
            }
        }

        $table = $wpdb->prefix . 'bizcity_webchat_sources';
        $wpdb->insert( $table, [
            'session_id'       => $session_id,
            'user_id'          => $user_id,
            'source_type'      => $source_type,
            'title'            => $title,
            'url'              => $url,
            'content'          => $content,
            'embedding_status' => 'pending',
            'created_at'       => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [
            'id'               => $wpdb->insert_id,
            'source_type'      => $source_type,
            'title'            => $title,
            'url'              => $url,
            'embedding_status' => 'pending',
            'chunk_count'      => 0,
            'created_at'       => current_time( 'mysql' ),
        ] );
    }

    /**
     * Delete a source.
     */
    public function ajax_delete_source(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        global $wpdb;
        $source_id = absint( $_POST['source_id'] ?? 0 );
        $user_id   = get_current_user_id();

        if ( ! $source_id ) {
            wp_send_json_error( [ 'message' => 'Missing source_id' ] );
            return;
        }

        $table   = $wpdb->prefix . 'bizcity_webchat_sources';
        $deleted = $wpdb->delete( $table, [ 'id' => $source_id, 'user_id' => $user_id ] );

        if ( $deleted ) {
            wp_send_json_success( true );
        } else {
            wp_send_json_error( [ 'message' => 'Source not found or access denied' ] );
        }
    }

    // ── Web Search (Tavily) — synchronous mode ──────────────────────────

    /**
     * Start a web search via Tavily. Since Tavily is fast (<10s),
     * this returns results directly (synchronous, no polling required).
     * The frontend polls for compatibility with the notebook UI pattern.
     */
    public function ajax_web_search_start(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        $query       = sanitize_text_field( $_POST['query'] ?? '' );
        $max_results = absint( $_POST['max_results'] ?? 5 );
        $session_id  = sanitize_text_field( $_POST['session_id'] ?? '' );

        if ( empty( $query ) ) {
            wp_send_json_error( [ 'message' => 'Query required' ] );
            return;
        }

        // Load Tavily client from companion-notebook or core
        if ( ! class_exists( 'BCN_Tavily_Client' ) ) {
            $tavily_path = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-companion-notebook/includes/class-tavily-client.php';
            if ( file_exists( $tavily_path ) ) {
                require_once $tavily_path;
            }
        }

        if ( ! class_exists( 'BCN_Tavily_Client' ) ) {
            wp_send_json_error( [ 'message' => 'Tavily client not available. Install companion-notebook plugin.' ] );
            return;
        }

        $results = BCN_Tavily_Client::search( $query, min( $max_results, 10 ) );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( [ 'message' => $results->get_error_message() ] );
            return;
        }

        // Generate a simple job_id for frontend compatibility
        $job_id = 'ws_' . wp_generate_uuid4();

        // Normalize scores to 0-5 star scale (Tavily returns 0-1)
        $candidates = [];
        foreach ( $results as $item ) {
            $candidates[] = [
                'url'     => $item['url'],
                'title'   => $item['title'],
                'excerpt' => $item['excerpt'] ?? '',
                'content' => $item['content'] ?? '',
                'domain'  => $item['domain'] ?? '',
                'score'   => round( ( $item['score'] ?? 0 ) * 5, 1 ),
            ];
        }

        // Store results transiently for import step
        set_transient( 'bizc_ws_' . $job_id, [
            'session_id' => $session_id,
            'query'      => $query,
            'candidates' => $candidates,
            'user_id'    => get_current_user_id(),
        ], 600 ); // 10 min TTL

        wp_send_json_success( [
            'job_id'     => $job_id,
            'status'     => 'completed',
            'candidates' => $candidates,
        ] );
    }

    /**
     * Check web search status (for polling compatibility).
     * Since we do synchronous search, this just returns the cached results.
     */
    public function ajax_web_search_status(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }

        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        $data   = get_transient( 'bizc_ws_' . $job_id );

        if ( ! $data ) {
            wp_send_json_success( [ 'status' => 'not_found' ] );
            return;
        }

        wp_send_json_success( [
            'job_id'     => $job_id,
            'status'     => 'completed',
            'candidates' => $data['candidates'] ?? [],
        ] );
    }

    /**
     * Import selected web search results as sources.
     */
    public function ajax_web_search_import(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Login required' ], 401 );
            return;
        }

        $job_id       = sanitize_text_field( $_POST['job_id'] ?? '' );
        $selected_raw = $_POST['selected_urls'] ?? '';
        $selected     = is_array( $selected_raw ) ? $selected_raw : json_decode( stripslashes( $selected_raw ), true );

        if ( empty( $job_id ) || empty( $selected ) || ! is_array( $selected ) ) {
            wp_send_json_error( [ 'message' => 'Missing job_id or selected_urls' ] );
            return;
        }

        $data = get_transient( 'bizc_ws_' . $job_id );
        if ( ! $data ) {
            wp_send_json_error( [ 'message' => 'Search results expired. Please search again.' ] );
            return;
        }

        if ( (int) $data['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
            return;
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'bizcity_webchat_sources';
        $user_id    = get_current_user_id();
        $session_id = $data['session_id'] ?? '';
        $sources    = [];
        $selected_set = array_flip( array_map( 'esc_url_raw', $selected ) );

        foreach ( $data['candidates'] as $candidate ) {
            if ( ! isset( $selected_set[ $candidate['url'] ] ) ) continue;

            $title   = sanitize_text_field( $candidate['title'] ?: $candidate['url'] );
            $url     = esc_url_raw( $candidate['url'] );
            $content = wp_kses_post( $candidate['content'] ?? $candidate['excerpt'] ?? '' );

            $wpdb->insert( $table, [
                'session_id'       => $session_id,
                'user_id'          => $user_id,
                'source_type'      => 'url',
                'title'            => $title,
                'url'              => $url,
                'content'          => $content,
                'embedding_status' => 'pending',
                'created_at'       => current_time( 'mysql' ),
            ] );

            $sources[] = [
                'id'               => $wpdb->insert_id,
                'source_type'      => 'url',
                'title'            => $title,
                'url'              => $url,
                'embedding_status' => 'pending',
                'chunk_count'      => 0,
                'created_at'       => current_time( 'mysql' ),
            ];
        }

        // Clean up transient
        delete_transient( 'bizc_ws_' . $job_id );

        wp_send_json_success( [ 'sources' => $sources ] );
    }

    /**
     * Cancel a web search (cleanup transient).
     */
    public function ajax_web_search_cancel(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }

        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        if ( $job_id ) {
            delete_transient( 'bizc_ws_' . $job_id );
        }
        wp_send_json_success( true );
    }

    // ── Studio Outputs (Phase 1.8) — delegates to BCN_Studio ──

    /**
     * List studio outputs for a session (uses session_id as project_id).
     */
    public function ajax_studio_outputs(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authenticated' ], 401 );
            return;
        }
        if ( ! class_exists( 'BCN_Studio' ) ) {
            wp_send_json_success( [] );
            return;
        }

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $session_id ) {
            wp_send_json_success( [] );
            return;
        }

        $studio  = new BCN_Studio();
        $outputs = $studio->get_outputs( $session_id );
        wp_send_json_success( $outputs ?: [] );
    }

    /**
     * Generate a studio output for a session.
     */
    public function ajax_studio_generate(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authenticated' ], 401 );
            return;
        }
        if ( ! class_exists( 'BCN_Studio' ) ) {
            wp_send_json_error( [ 'message' => 'Companion Notebook plugin chưa kích hoạt' ], 400 );
            return;
        }

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $tool_type  = sanitize_key( $_POST['tool_type'] ?? '' );
        if ( ! $session_id || ! $tool_type ) {
            wp_send_json_error( [ 'message' => 'Missing session_id or tool_type' ], 400 );
            return;
        }

        $studio    = new BCN_Studio();
        $output_id = $studio->generate( $session_id, $tool_type, get_current_user_id() );

        if ( is_wp_error( $output_id ) ) {
            wp_send_json_error( [ 'message' => $output_id->get_error_message() ], 400 );
            return;
        }

        $output = $studio->get_output( $output_id );
        wp_send_json_success( $output );
    }

    /**
     * Delete a studio output.
     */
    public function ajax_studio_delete_output(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authenticated' ], 401 );
            return;
        }
        if ( ! class_exists( 'BCN_Studio' ) ) {
            wp_send_json_error( [ 'message' => 'Companion Notebook plugin chưa kích hoạt' ], 400 );
            return;
        }

        $output_id = absint( $_POST['output_id'] ?? 0 );
        if ( ! $output_id ) {
            wp_send_json_error( [ 'message' => 'Missing output_id' ], 400 );
            return;
        }

        $studio = new BCN_Studio();
        $result = $studio->delete_output( $output_id );
        wp_send_json_success( $result );
    }

    /**
     * Get skeleton JSON for a session.
     */
    public function ajax_studio_skeleton(): void {
        if ( ! check_ajax_referer( 'bizcity_webchat', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token' ], 403 );
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authenticated' ], 401 );
            return;
        }
        if ( ! class_exists( 'BCN_Studio_Input_Builder' ) ) {
            wp_send_json_error( [ 'message' => 'Companion Notebook plugin chưa kích hoạt' ], 400 );
            return;
        }

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ], 400 );
            return;
        }

        $skeleton = BCN_Studio_Input_Builder::build( $session_id );
        wp_send_json_success( $skeleton );
    }
}
