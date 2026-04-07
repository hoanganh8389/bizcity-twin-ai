<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Projects — Wrapper around webchat_projects with notebook_mode filter.
 */
class BCN_Projects {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_projects';
    }

    /**
     * Create a notebook project.
     */
    public function create( array $data ) {
        global $wpdb;

        $project_id = wp_generate_uuid4();
        $user_id    = get_current_user_id();
        $title      = sanitize_text_field( $data['title'] ?? 'Untitled notebook' );
        $desc       = sanitize_textarea_field( $data['description'] ?? '' );
        $settings   = wp_json_encode( [
            'notebook_mode'   => true,
            'cover_image_url' => sanitize_url( $data['cover_image_url'] ?? '' ),
            'is_featured'     => ! empty( $data['is_featured'] ),
        ] );

        $result = $wpdb->insert( $this->table(), [
            'project_id'  => $project_id,
            'user_id'     => $user_id,
            'name'        => $title,
            'description' => $desc,
            'icon'        => '📓',
            'settings'    => $settings,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );

        if ( ! $result ) {
            return new WP_Error( 'db_error', 'Could not create project' );
        }

        do_action( 'bcn_project_created', $project_id, $user_id );

        return $project_id;
    }

    public function update( $project_id, array $data ) {
        global $wpdb;

        $update = [ 'updated_at' => current_time( 'mysql' ) ];
        if ( isset( $data['title'] ) )       $update['name']        = sanitize_text_field( $data['title'] );
        if ( isset( $data['description'] ) ) $update['description'] = sanitize_textarea_field( $data['description'] );

        // Handle settings merge.
        $existing = $this->get( $project_id );
        if ( ! $existing ) return new WP_Error( 'not_found', 'Project not found' );

        $settings = json_decode( $existing->settings, true ) ?: [];
        if ( isset( $data['cover_image_url'] ) ) $settings['cover_image_url'] = sanitize_url( $data['cover_image_url'] );
        if ( isset( $data['is_featured'] ) )     $settings['is_featured']     = (bool) $data['is_featured'];
        $update['settings'] = wp_json_encode( $settings );

        return (bool) $wpdb->update(
            $this->table(),
            $update,
            [ 'project_id' => $project_id, 'user_id' => get_current_user_id() ]
        );
    }

    public function delete( $project_id ) {
        global $wpdb;
        $user_id = get_current_user_id();

        // CASCADE: delete sources, notes, studio_outputs, sessions+messages.
        $wpdb->delete( BCN_Schema_Extend::table_sources(), [ 'project_id' => $project_id ] );
        $wpdb->delete( BCN_Schema_Extend::table_notes(), [ 'project_id' => $project_id ] );
        $wpdb->delete( BCN_Schema_Extend::table_studio_outputs(), [ 'project_id' => $project_id ] );

        // Delete notebook sessions + messages.
        $sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
        $messages_table = $wpdb->prefix . 'bizcity_webchat_messages';

        $session_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT session_id FROM {$sessions_table} WHERE project_id = %s AND platform_type = 'NOTEBOOK'",
            $project_id
        ) );

        if ( $session_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$messages_table} WHERE session_id IN ({$placeholders})",
                ...$session_ids
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$sessions_table} WHERE project_id = %s AND platform_type = 'NOTEBOOK'",
                $project_id
            ) );
        }

        return (bool) $wpdb->delete( $this->table(), [
            'project_id' => $project_id,
            'user_id'    => $user_id,
        ] );
    }

    /**
     * Normalize a DB row for the JS frontend.
     * Maps project_id → id, name → title, decodes settings JSON.
     */
    private function format_row( $row ) {
        if ( ! $row ) return null;
        $row->id       = $row->project_id;
        $row->title    = $row->name;
        $row->settings = is_string( $row->settings ) ? json_decode( $row->settings, true ) : $row->settings;
        return $row;
    }

    public function get( $project_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE project_id = %s",
            $project_id
        ) );
        if ( ! $row ) return null;

        // Enrich with counts — check both BCN table (bizcity_rces) and webchat_sources.
        $bcn_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . BCN_Schema_Extend::table_sources() . " WHERE project_id = %s",
            $project_id
        ) );
        $wcs_table = $wpdb->prefix . 'bizcity_webchat_sources';
        $wcs_count = 0;
        if ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'project_id'",
            $wpdb->prefix . 'bizcity_webchat_sources'
        ) ) ) {
            $wcs_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wcs_table} WHERE project_id = %s AND (session_id = '' OR session_id IS NULL)",
                $project_id
            ) );
        }
        $row->source_count = $bcn_count + $wcs_count;
        $row->note_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . BCN_Schema_Extend::table_notes() . " WHERE project_id = %s",
            $project_id
        ) );

        return $this->format_row( $row );
    }

    public function get_list( $user_id, array $args = [] ) {
        global $wpdb;

        $orderby = ( $args['orderby'] ?? 'updated_at' ) === 'created_at' ? 'created_at' : 'updated_at';
        $order   = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $where = $wpdb->prepare(
            "WHERE user_id = %d AND settings LIKE %s",
            $user_id,
            '%notebook_mode%'
        );

        if ( ! empty( $args['featured'] ) ) {
            $where .= " AND settings LIKE '%is_featured\":true%'";
        }

        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where .= $wpdb->prepare( " AND (name LIKE %s OR description LIKE %s)", $search, $search );
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table()} {$where} ORDER BY {$orderby} {$order} LIMIT 100"
        );

        // Enrich with source counts and normalize for JS.
        // Counts from both BCN (bizcity_rces) and webchat_sources (project-scoped rows).
        $source_table = BCN_Schema_Extend::table_sources();
        $wcs_table    = $wpdb->prefix . 'bizcity_webchat_sources';
        $has_project_col = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'project_id'",
            $wcs_table
        ) );
        foreach ( $rows as &$row ) {
            $bcn_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$source_table} WHERE project_id = %s",
                $row->project_id
            ) );
            $wcs_count = 0;
            if ( $has_project_col ) {
                $wcs_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wcs_table} WHERE project_id = %s AND (session_id = '' OR session_id IS NULL)",
                    $row->project_id
                ) );
            }
            $row->source_count = $bcn_count + $wcs_count;
            $this->format_row( $row );
        }

        return $rows;
    }
}
