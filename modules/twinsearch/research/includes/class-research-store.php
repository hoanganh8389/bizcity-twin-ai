<?php
/**
 * Research Store — CRUD + permission helpers for sessions/turns/ingests.
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Store {

    /* ────────────── Sessions ────────────── */

    public static function create_session( array $args ): int {
        global $wpdb;
        $now = current_time( 'mysql' );

        $row = [
            'scope_type'  => self::sanitize_scope( $args['scope_type'] ?? 'user' ),
            'scope_id'    => max( 0, (int) ( $args['scope_id'] ?? 0 ) ),
            'user_id'     => max( 0, (int) ( $args['user_id'] ?? get_current_user_id() ) ),
            'title'       => mb_substr( sanitize_text_field( $args['title'] ?? __( 'Research Project', 'bizcity-twin-ai' ) ), 0, 250 ),
            'topic_tags'  => isset( $args['topic_tags'] ) && is_array( $args['topic_tags'] )
                ? wp_json_encode( array_map( 'sanitize_text_field', $args['topic_tags'] ) ) : null,
            'agent_mode'  => in_array( $args['agent_mode'] ?? 'deep', [ 'fast', 'deep' ], true ) ? $args['agent_mode'] : 'deep',
            'status'      => 'open',
            'total_turns' => 0,
            'total_ingested' => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $wpdb->insert( BizCity_Research_DB::table_sessions(), $row );
        $id = (int) $wpdb->insert_id;

        BizCity_Research_Event_Emitter::emit( 'research_session_created', [
            'session_id'  => $id,
            'scope_type'  => $row['scope_type'],
            'scope_id'    => $row['scope_id'],
            'agent_mode'  => $row['agent_mode'],
        ] );
        return $id;
    }

    public static function update_session( int $id, array $patch ): bool {
        global $wpdb;
        $allowed = [ 'title', 'status', 'agent_mode', 'topic_tags' ];
        $data    = [];
        foreach ( $patch as $k => $v ) {
            if ( ! in_array( $k, $allowed, true ) ) continue;
            if ( $k === 'topic_tags' && is_array( $v ) ) {
                $data[ $k ] = wp_json_encode( array_map( 'sanitize_text_field', $v ) );
            } elseif ( $k === 'title' ) {
                $data[ $k ] = mb_substr( sanitize_text_field( $v ), 0, 250 );
            } elseif ( $k === 'status' && in_array( $v, [ 'open', 'archived' ], true ) ) {
                $data[ $k ] = $v;
            } elseif ( $k === 'agent_mode' && in_array( $v, [ 'fast', 'deep' ], true ) ) {
                $data[ $k ] = $v;
            }
        }
        if ( empty( $data ) ) return false;
        $data['updated_at'] = current_time( 'mysql' );
        return (bool) $wpdb->update( BizCity_Research_DB::table_sessions(), $data, [ 'id' => $id ] );
    }

    public static function get_session( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . BizCity_Research_DB::table_sessions() . ' WHERE id = %d',
            $id
        ), ARRAY_A );
        return $row ? self::shape_session( $row ) : null;
    }

    public static function list_sessions( string $scope_type, int $scope_id, int $user_id ): array {
        global $wpdb;
        $tbl  = BizCity_Research_DB::table_sessions();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE scope_type = %s AND scope_id = %d AND user_id = %d ORDER BY updated_at DESC LIMIT 200",
            $scope_type, $scope_id, $user_id
        ), ARRAY_A );
        return array_map( [ __CLASS__, 'shape_session' ], $rows ?: [] );
    }

    public static function delete_session( int $id ): bool {
        global $wpdb;
        $wpdb->delete( BizCity_Research_DB::table_turns(),    [ 'session_id' => $id ] );
        $wpdb->delete( BizCity_Research_DB::table_ingests(),  [ 'session_id' => $id ] );
        return (bool) $wpdb->delete( BizCity_Research_DB::table_sessions(), [ 'id' => $id ] );
    }

    private static function shape_session( array $r ): array {
        $tags = $r['topic_tags'] ? json_decode( (string) $r['topic_tags'], true ) : [];
        return [
            'id'             => (int) $r['id'],
            'scope_type'     => (string) $r['scope_type'],
            'scope_id'       => (int) $r['scope_id'],
            'user_id'        => (int) $r['user_id'],
            'title'          => (string) $r['title'],
            'topic_tags'     => is_array( $tags ) ? $tags : [],
            'agent_mode'     => (string) $r['agent_mode'],
            'status'         => (string) $r['status'],
            'total_turns'    => (int) $r['total_turns'],
            'total_ingested' => (int) $r['total_ingested'],
            'created_at'     => $r['created_at'],
            'updated_at'     => $r['updated_at'],
        ];
    }

    /* ────────────── Turns ────────────── */

    public static function create_turn( int $session_id, string $query, string $trace_id = '' ): int {
        global $wpdb;
        $tbl     = BizCity_Research_DB::table_turns();
        $next    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(turn_index), 0) + 1 FROM {$tbl} WHERE session_id = %d", $session_id
        ) );
        $wpdb->insert( $tbl, [
            'session_id' => $session_id,
            'turn_index' => $next,
            'user_query' => $query,
            'status'     => 'pending',
            'trace_id'   => $trace_id ?: wp_generate_uuid4(),
            'created_at' => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function finalize_turn( int $turn_id, array $patch ): void {
        global $wpdb;
        $data = [
            'status'           => $patch['status']           ?? 'done',
            'agent_answer_md'  => $patch['agent_answer_md']  ?? '',
            'reasoning_trace'  => isset( $patch['reasoning_trace'] ) ? wp_json_encode( $patch['reasoning_trace'] ) : null,
            'source_urls'      => isset( $patch['source_urls'] ) ? wp_json_encode( $patch['source_urls'] ) : null,
            'tool_calls_count' => (int) ( $patch['tool_calls_count'] ?? 0 ),
            'duration_ms'      => (int) ( $patch['duration_ms'] ?? 0 ),
            'error_message'    => $patch['error_message'] ?? null,
        ];
        $wpdb->update( BizCity_Research_DB::table_turns(), $data, [ 'id' => $turn_id ] );

        // Bump session counter
        $session_id = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT session_id FROM ' . BizCity_Research_DB::table_turns() . ' WHERE id = %d', $turn_id
        ) );
        if ( $session_id > 0 && ( $data['status'] ?? '' ) !== 'error' ) {
            $wpdb->query( $wpdb->prepare(
                'UPDATE ' . BizCity_Research_DB::table_sessions()
                . ' SET total_turns = total_turns + 1, updated_at = %s WHERE id = %d',
                current_time( 'mysql' ), $session_id
            ) );
        }
    }

    public static function get_turn( int $turn_id ): ?array {
        global $wpdb;
        $r = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . BizCity_Research_DB::table_turns() . ' WHERE id = %d', $turn_id
        ), ARRAY_A );
        return $r ? self::shape_turn( $r ) : null;
    }

    public static function list_turns( int $session_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . BizCity_Research_DB::table_turns()
            . ' WHERE session_id = %d ORDER BY turn_index ASC',
            $session_id
        ), ARRAY_A );
        return array_map( [ __CLASS__, 'shape_turn' ], $rows ?: [] );
    }

    private static function shape_turn( array $r ): array {
        return [
            'id'               => (int) $r['id'],
            'session_id'       => (int) $r['session_id'],
            'turn_index'       => (int) $r['turn_index'],
            'user_query'       => (string) $r['user_query'],
            'agent_answer_md'  => (string) ( $r['agent_answer_md'] ?? '' ),
            'reasoning_trace'  => $r['reasoning_trace'] ? json_decode( (string) $r['reasoning_trace'], true ) : [],
            'source_urls'      => $r['source_urls']     ? json_decode( (string) $r['source_urls'], true )     : [],
            'tool_calls_count' => (int) $r['tool_calls_count'],
            'duration_ms'      => (int) $r['duration_ms'],
            'status'           => (string) $r['status'],
            'trace_id'         => (string) ( $r['trace_id'] ?? '' ),
            'created_at'       => $r['created_at'],
        ];
    }

    /* ────────────── Permission gate ────────────── */

    /**
     * Can the current user access this scope?
     * - scope=user      → only the user themself (or admin)
     * - scope=character → user must own the character OR have manage_options
     */
    public static function user_can_access( string $scope_type, int $scope_id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 ) return false;

        if ( $scope_type === 'user' ) {
            if ( $scope_id === $user_id ) return true;
            return user_can( $user_id, 'manage_options' );
        }
        if ( $scope_type === 'character' ) {
            if ( user_can( $user_id, 'manage_options' ) ) return true;
            // Query characters table directly (table exists whenever knowledge module is installed).
            global $wpdb;
            $tbl   = $wpdb->prefix . 'bizcity_characters';
            $owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$tbl} WHERE id = %d", $scope_id ) );
            if ( $owner > 0 && $owner === $user_id ) return true;
        }
        if ( $scope_type === 'notebook' ) {
            if ( user_can( $user_id, 'manage_options' ) ) return true;
            global $wpdb;
            // Notebook table created by twinchat module.
            $tbl   = $wpdb->prefix . 'bizcity_twinchat_notebooks';
            $owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$tbl} WHERE id = %d", $scope_id ) );
            if ( $owner > 0 && $owner === $user_id ) return true;
        }
        return false;
    }

    public static function user_can_session( int $session_id, int $user_id = 0 ): bool {
        $s = self::get_session( $session_id );
        if ( ! $s ) return false;
        return self::user_can_access( $s['scope_type'], $s['scope_id'], $user_id );
    }

    /* ────────────── Helpers ────────────── */

    public static function sanitize_scope( string $s ): string {
        return in_array( $s, [ 'character', 'user', 'notebook' ], true ) ? $s : 'user';
    }

    public static function url_hash( string $url ): string {
        return hash( 'sha256', strtolower( trim( $url ) ) );
    }
}
