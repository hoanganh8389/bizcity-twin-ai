<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Project Service — Shared project CRUD with auto character/knowledge creation
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      2.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Project_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_db() {
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            return BizCity_WebChat_Database::instance();
        }
        return null;
    }

    /**
     * List projects for a user.
     *
     * @param int $user_id
     * @return array
     */
    public function list_projects( $user_id ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return [];
        }

        // V3
        if ( method_exists( $db, 'get_projects_for_user' ) ) {
            $projects = $db->get_projects_for_user( $user_id );
            $result   = [];
            foreach ( (array) $projects as $p ) {
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
            return $result;
        }

        // Legacy: user_meta
        $projects = get_user_meta( $user_id, 'bizcity_projects', true );
        if ( ! is_array( $projects ) ) {
            return [];
        }

        foreach ( $projects as &$p ) {
            global $wpdb;
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_webchat_conversations WHERE user_id = %d AND project_id = %s AND status = 'active'",
                $user_id,
                $p['id']
            ) );
            $p['session_count'] = (int) $count;
        }

        return $projects;
    }

    /**
     * Create a project with optional auto-character creation.
     *
     * @param int    $user_id
     * @param string $name
     * @param string $icon
     * @param int    $character_id  0 = auto-create
     * @return array|WP_Error  { id, pk, name, icon, character_id }
     */
    public function create_project( $user_id, $name, $icon = '📁', $character_id = 0 ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        if ( empty( $name ) ) {
            return new WP_Error( 'empty_name', 'Tên dự án không được để trống' );
        }

        // Auto-create character
        if ( ! $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $kdb  = BizCity_Knowledge_Database::instance();
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
                'owner_id'      => '',
            ] );

            if ( ! is_wp_error( $new_char_id ) && $new_char_id > 0 ) {
                $character_id = (int) $new_char_id;
                $this->create_default_knowledge_source( $kdb, $character_id, $name );
            }
        }

        // V3
        if ( method_exists( $db, 'create_project' ) ) {
            $result = $db->create_project( $user_id, $name, [
                'icon'         => $icon,
                'character_id' => $character_id,
            ] );

            if ( empty( $result['id'] ) || $result['id'] === 0 ) {
                global $wpdb;
                return new WP_Error( 'db_error', 'Lỗi tạo dự án: ' . $wpdb->last_error );
            }

            // Update character's owner_id
            if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
                $kdb = BizCity_Knowledge_Database::instance();
                $kdb->update_character( $character_id, [
                    'owner_id' => $result['project_id'] ?? '',
                ] );
            }

            $this->log_operation( 'create', 'project', [
                'project_pk'   => $result['id'] ?? 0,
                'project_uuid' => $result['project_id'] ?? '',
                'name'         => $name,
                'character_id' => $character_id,
            ] );

            return [
                'id'           => $result['project_id'],
                'pk'           => $result['id'],
                'name'         => $name,
                'icon'         => $icon,
                'character_id' => $character_id,
            ];
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

        return [
            'id'           => $project_id,
            'name'         => $name,
            'icon'         => $icon,
            'character_id' => $character_id,
        ];
    }

    /**
     * Rename a project.
     *
     * @param int|string $project_id
     * @param string     $name
     * @param int        $user_id
     * @return true|WP_Error
     */
    public function rename_project( $project_id, $name, $user_id ) {
        $db = $this->get_db();
        if ( ! $db || ! method_exists( $db, 'update_project' ) ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        if ( empty( $name ) ) {
            return new WP_Error( 'empty_name', 'Tên dự án không được để trống' );
        }

        $db->update_project( $project_id, [ 'name' => $name ] );
        return true;
    }

    /**
     * Update project settings.
     *
     * @param int|string $project_id
     * @param array      $data  { character_id, icon, knowledge_ids, ... }
     * @param int        $user_id
     * @return true|WP_Error
     */
    public function update_project( $project_id, $data, $user_id ) {
        $db = $this->get_db();
        if ( ! $db || ! method_exists( $db, 'update_project' ) ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $db->update_project( $project_id, $data );
        return true;
    }

    /**
     * Delete a project.
     *
     * @param int|string $project_id
     * @param int        $user_id
     * @return true|WP_Error
     */
    public function delete_project( $project_id, $user_id ) {
        $db = $this->get_db();
        if ( ! $db || ! method_exists( $db, 'delete_project' ) ) {
            return new WP_Error( 'no_db', 'Database not available' );
        }

        $db->delete_project( $project_id );
        return true;
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    private function create_default_knowledge_source( $kdb, $character_id, $project_name ) {
        if ( ! method_exists( $kdb, 'create_knowledge_source' ) ) {
            return;
        }

        $kdb->create_knowledge_source( [
            'character_id' => $character_id,
            'name'         => 'Kiến thức mặc định - ' . $project_name,
            'type'         => 'quick_faq',
            'content'      => wp_json_encode( [
                [
                    'question' => 'Dự án này là gì?',
                    'answer'   => "Đây là dự án \"{$project_name}\". Bạn có thể thêm kiến thức cho trợ lý AI tại đây.",
                ],
            ] ),
            'status' => 'active',
        ] );
    }

    private function log_operation( $action, $type, $data = [], $session_id = '' ) {
        if ( class_exists( 'BizCity_User_Memory' ) && method_exists( 'BizCity_User_Memory', 'log_router_event' ) ) {
            BizCity_User_Memory::instance()->log_router_event( [
                'step'       => "service_{$type}_{$action}",
                'input_data' => $data,
                'session_id' => $session_id,
            ] );
        }
    }
}
