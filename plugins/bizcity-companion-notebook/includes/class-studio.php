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
 * Studio — Generate outputs using Notebook Tool Registry + Studio Input Builder.
 */
class BCN_Studio {

    private function table() {
        return BCN_Schema_Extend::table_studio_outputs();
    }

    /**
     * Available studio tools — from registry.
     */
    public static function get_available_tools() {
        return BCN_Notebook_Tool_Registry::get_all();
    }

    /**
     * Generate studio output.
     *
     * Flow: build Skeleton JSON (cached) → dispatch to tool → save output.
     */
    public function generate( $project_id, $tool_type, $user_id ) {
        error_log( sprintf(
            '[BCN Studio] generate() called. project_id=%s, tool_type=%s, user_id=%d',
            $project_id, $tool_type, $user_id
        ) );

        // Check if a valid cached skeleton exists before building.
        $cached = BCN_Studio_Input_Builder::get_cached( $project_id );
        if ( $cached ) {
            error_log( sprintf(
                '[BCN Studio] Cached skeleton found. version=%s, source_count=%d, note_count=%d, generated_at=%s, has_raw_text=%s',
                $cached['version'] ?? '?',
                $cached['meta']['source_count'] ?? 0,
                $cached['meta']['note_count'] ?? 0,
                $cached['meta']['timestamp'] ?? '?',
                ! empty( $cached['_raw_text'] ) ? 'YES (' . strlen( $cached['_raw_text'] ) . ' chars)' : 'NO'
            ) );
        } else {
            error_log( '[BCN Studio] No valid cached skeleton — will build fresh via LLM.' );
        }

        // Build Skeleton JSON (or retrieve from cache).
        $skeleton = BCN_Studio_Input_Builder::build( $project_id );

        error_log( sprintf(
            '[BCN Studio] Skeleton ready. keys=%s | has_skeleton=%s | has_raw_text=%s',
            implode( ', ', array_keys( $skeleton ) ),
            ! empty( $skeleton['skeleton'] ) ? 'YES (' . count( $skeleton['skeleton'] ) . ' nodes)' : 'NO',
            ! empty( $skeleton['_raw_text'] ) ? 'YES (' . strlen( $skeleton['_raw_text'] ) . ' chars)' : 'NO'
        ) );

        if ( empty( $skeleton['skeleton'] ) && empty( $skeleton['_raw_text'] ) ) {
            error_log( '[BCN Studio] ERROR: skeleton and _raw_text both empty — aborting. project_id=' . $project_id );
            return new WP_Error( 'no_content', 'Cần có nguồn hoặc ghi chú để tạo nội dung' );
        }

        // Dispatch to registered tool callback — all tools receive same skeleton.
        error_log( '[BCN Studio] Dispatching to tool: ' . $tool_type );
        $result = BCN_Notebook_Tool_Registry::execute( $tool_type, $skeleton );

        if ( is_wp_error( $result ) ) {
            error_log( '[BCN Studio] Tool execution FAILED: ' . $result->get_error_message() );
            return $result;
        }

        error_log( '[BCN Studio] Tool executed OK. Saving output...' );
        $output_id = $this->save_output( $project_id, $tool_type, $result, $user_id, $skeleton );
        error_log( '[BCN Studio] Output saved. output_id=' . $output_id );

        do_action( 'bcn_studio_generated', $output_id, $tool_type, $project_id );
        return $output_id;
    }


    private function save_output( $project_id, $tool_type, $result, $user_id, $studio_input = null ) {
        global $wpdb;

        // Webchat sessions use wcs_ prefix — store in session_id, clear project_id.
        $is_webchat = str_starts_with( $project_id, 'wcs_' );
        $session_id = $is_webchat ? $project_id : '';
        $real_project_id = $is_webchat ? '' : $project_id;

        $data = [
            'user_id'          => $user_id,
            'project_id'       => $real_project_id,
            'session_id'       => $session_id,
            'caller'           => 'studio',
            'tool_type'        => $tool_type,
            'title'            => sanitize_text_field( $result['title'] ?? '' ),
            'content'          => $result['content'] ?? '',
            'content_format'   => sanitize_text_field( $result['content_format'] ?? 'json' ),
            'source_count'     => (int) ( $studio_input['meta']['source_count'] ?? 0 ),
            'note_count'       => (int) ( $studio_input['meta']['note_count'] ?? 0 ),
            'external_post_id' => absint( $result['data']['id'] ?? 0 ) ?: null,
            'external_url'     => sanitize_url( $result['data']['url'] ?? '' ),
            'status'           => 'ready',
            'created_at'       => current_time( 'mysql' ),
        ];

        // Save input snapshot for regeneration (ARCHITECTURE-V3 §4.7)
        if ( $studio_input ) {
            $data['input_snapshot'] = wp_json_encode( $studio_input, JSON_UNESCAPED_UNICODE );
        }

        $wpdb->insert( $this->table(), $data );
        return $wpdb->insert_id;
    }

    // ── CRUD ──

    /**
     * Get studio outputs.
     *
     * Architecture:
     *   - Webchat (sidebar): query by session_id
     *   - Notebook companion: query by project_id
     *
     * @param string $project_id  Notebook project UUID, or empty string.
     * @param string $tool_type   Optional tool_type filter.
     * @param string $caller      Optional caller filter.
     * @param string $session_id  Webchat session ID (takes priority over project_id when set).
     */
    public function get_outputs( $project_id, $tool_type = '', $caller = '', $session_id = '' ) {
        global $wpdb;

        $cols = $wpdb->get_col( "DESCRIBE {$this->table()}", 0 ) ?: [];
        $has_session_col = in_array( 'session_id', $cols, true );

        if ( $session_id && $has_session_col ) {
            // Webchat path: strict session_id lookup.
            $where = $wpdb->prepare( "WHERE session_id = %s", $session_id );
        } else {
            // Notebook path: project_id lookup.
            $where = $wpdb->prepare( "WHERE project_id = %s", $project_id );
        }

        if ( $tool_type ) {
            $where .= $wpdb->prepare( " AND tool_type = %s", $tool_type );
        }
        if ( $caller ) {
            $where .= $wpdb->prepare( " AND caller = %s", $caller );
        }

        $session_col = $has_session_col ? 'session_id,' : '';
        return $wpdb->get_results(
            "SELECT id, project_id, {$session_col} tool_type, title, content, content_format, source_count, note_count,
                    caller, tool_id, invoke_id, external_url, external_post_id, status, created_at
             FROM {$this->table()} {$where} ORDER BY created_at DESC"
        );
    }

    public function get_output( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ) );
    }

    public function delete_output( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), [
            'id'      => $id,
            'user_id' => get_current_user_id(),
        ] );
    }

    public function regenerate( $id ) {
        $output = $this->get_output( $id );
        if ( ! $output ) return new WP_Error( 'not_found', 'Output not found' );

        $this->delete_output( $id );
        // Webchat outputs store the ID in session_id, notebook in project_id.
        $scope_id = ! empty( $output->session_id ) ? $output->session_id : $output->project_id;
        return $this->generate( $scope_id, $output->tool_type, get_current_user_id() );
    }
}
