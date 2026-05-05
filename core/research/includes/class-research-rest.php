<?php
/**
 * Research REST Controller — namespace: bizcity/research/v1
 *
 * Endpoints:
 *   GET    /sessions?scope_type=&scope_id=
 *   POST   /sessions
 *   GET    /sessions/{id}
 *   PATCH  /sessions/{id}
 *   DELETE /sessions/{id}
 *   POST   /sessions/{id}/chat            → returns { turn_id, stream_url }
 *   GET    /sessions/{id}/stream?turn_id  → NDJSON stream (auth via cookie+nonce)
 *   GET    /turns/{id}
 *   POST   /turns/{id}/ingest             → { urls: [...] }
 *   GET    /scope/ingests?scope_type=&scope_id=
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_REST {

    const NS = 'bizcity/research/v1';

    private static ?self $instance = null;
    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_routes(): void {
        register_rest_route( self::NS, '/sessions', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_sessions' ],
                'permission_callback' => [ $this, 'auth_logged_in' ],
                'args'                => [
                    'scope_type' => [ 'required' => true,  'type' => 'string' ],
                    'scope_id'   => [ 'required' => true,  'type' => 'integer' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_session' ],
                'permission_callback' => [ $this, 'auth_logged_in' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_session' ],
                'permission_callback' => [ $this, 'auth_session' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'patch_session' ],
                'permission_callback' => [ $this, 'auth_session' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_session' ],
                'permission_callback' => [ $this, 'auth_session' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_turn' ],
            'permission_callback' => [ $this, 'auth_session' ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/stream', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'stream_turn' ],
            'permission_callback' => [ $this, 'auth_session' ],
        ] );

        // Wave 0.18.5d — combined create-turn + stream (single POST, matches FE client design)
        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/turns/stream', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_and_stream_turn' ],
            'permission_callback' => [ $this, 'auth_session' ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_turn' ],
            'permission_callback' => [ $this, 'auth_turn' ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)/ingest', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'ingest_turn' ],
            'permission_callback' => [ $this, 'auth_turn' ],
        ] );

        register_rest_route( self::NS, '/scope/ingests', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_scope_ingests' ],
            'permission_callback' => [ $this, 'auth_logged_in' ],
        ] );

        // ─── Wave 0.18.1.6 — Persona capability + Sources sync ────────────
        register_rest_route( self::NS, '/capability/(?P<character_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_capability' ],
            'permission_callback' => [ $this, 'auth_logged_in' ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)/sources', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_turn_sources' ],
            'permission_callback' => [ $this, 'auth_turn' ],
            'args'                => [
                'scope_type' => [ 'required' => true, 'type' => 'string' ],
                'scope_id'   => [ 'required' => true, 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)/source/attach', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'attach_turn_source' ],
            'permission_callback' => [ $this, 'auth_turn' ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)/source/detach', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'detach_turn_source' ],
            'permission_callback' => [ $this, 'auth_turn' ],
        ] );

        register_rest_route( self::NS, '/turns/(?P<id>\d+)/source/attach-report', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'attach_turn_report' ],
            'permission_callback' => [ $this, 'auth_turn' ],
        ] );
    }

    /* ────────────── Permission callbacks ────────────── */

    public function auth_logged_in(): bool {
        return is_user_logged_in();
    }

    public function auth_session( WP_REST_Request $req ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'Login required', 'bizcity-twin-ai' ), [ 'status' => 401 ] );
        }
        $id = (int) $req['id'];
        if ( ! BizCity_Research_Store::user_can_session( $id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Forbidden', 'bizcity-twin-ai' ), [ 'status' => 403 ] );
        }
        return true;
    }

    public function auth_turn( WP_REST_Request $req ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'Login required', 'bizcity-twin-ai' ), [ 'status' => 401 ] );
        }
        $turn = BizCity_Research_Store::get_turn( (int) $req['id'] );
        if ( ! $turn || ! BizCity_Research_Store::user_can_session( (int) $turn['session_id'] ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Forbidden', 'bizcity-twin-ai' ), [ 'status' => 403 ] );
        }
        return true;
    }

    /* ────────────── Handlers ────────────── */

    public function list_sessions( WP_REST_Request $req ) {
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req['scope_type'] );
        $scope_id   = max( 0, (int) $req['scope_id'] );
        $user_id    = get_current_user_id();
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id, $user_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Forbidden', 'bizcity-twin-ai' ), [ 'status' => 403 ] );
        }
        return rest_ensure_response( [
            'items' => BizCity_Research_Store::list_sessions( $scope_type, $scope_id, $user_id ),
        ] );
    }

    public function create_session( WP_REST_Request $req ) {
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );
        $user_id    = get_current_user_id();
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id, $user_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Forbidden', 'bizcity-twin-ai' ), [ 'status' => 403 ] );
        }
        $id = BizCity_Research_Store::create_session( [
            'scope_type' => $scope_type,
            'scope_id'   => $scope_id,
            'user_id'    => $user_id,
            'title'      => (string) $req->get_param( 'title' ),
            'topic_tags' => (array)  $req->get_param( 'topic_tags' ),
            'agent_mode' => (string) ( $req->get_param( 'agent_mode' ) ?: 'deep' ),
        ] );
        $session = BizCity_Research_Store::get_session( $id );
        // Add session_id alias so FE client can read either .id or .session_id
        $session['session_id'] = $session['id'];
        return rest_ensure_response( $session );
    }

    public function get_session( WP_REST_Request $req ) {
        $id      = (int) $req['id'];
        $session = BizCity_Research_Store::get_session( $id );
        $turns   = BizCity_Research_Store::list_turns( $id );
        return rest_ensure_response( [ 'session' => $session, 'turns' => $turns ] );
    }

    public function patch_session( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        BizCity_Research_Store::update_session( $id, $req->get_json_params() ?: [] );
        return rest_ensure_response( BizCity_Research_Store::get_session( $id ) );
    }

    public function delete_session( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        BizCity_Research_Store::delete_session( $id );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    public function create_turn( WP_REST_Request $req ) {
        $session_id = (int) $req['id'];
        $session    = BizCity_Research_Store::get_session( $session_id );
        $query      = trim( (string) $req->get_param( 'query' ) );
        if ( $query === '' ) {
            return new WP_Error( 'rest_invalid_param', 'query is required', [ 'status' => 400 ] );
        }

        $mode    = (string) ( $req->get_param( 'mode' ) ?: $session['agent_mode'] );
        $turn_id = BizCity_Research_Store::create_turn( $session_id, $query, wp_generate_uuid4() );

        $stream_url = add_query_arg(
            [
                'turn_id' => $turn_id,
                'mode'    => $mode,
                '_wpnonce'=> wp_create_nonce( 'wp_rest' ),
            ],
            rest_url( self::NS . '/sessions/' . $session_id . '/stream' )
        );

        return rest_ensure_response( [
            'turn_id'    => $turn_id,
            'stream_url' => $stream_url,
        ] );
    }

    /**
     * Stream NDJSON. Note: REST sends the response after callback returns,
     * so we short-circuit by emitting + flushing inside the callback and
     * exiting.  Compatible with most WP environments.
     */
    public function stream_turn( WP_REST_Request $req ) {
        $session_id = (int) $req['id'];
        $turn_id    = (int) $req->get_param( 'turn_id' );
        $mode       = (string) ( $req->get_param( 'mode' ) ?: 'deep' );

        $session = BizCity_Research_Store::get_session( $session_id );
        $turn    = BizCity_Research_Store::get_turn( $turn_id );
        if ( ! $session || ! $turn || (int) $turn['session_id'] !== $session_id ) {
            return new WP_Error( 'rest_invalid', 'Invalid session/turn', [ 'status' => 404 ] );
        }
        // Prevent re-running a turn that has already been processed.
        if ( $turn['status'] !== 'pending' ) {
            return new WP_Error( 'rest_conflict', 'Turn already processed (status: ' . $turn['status'] . ')', [ 'status' => 409 ] );
        }

        // Disable any output buffering and prep streaming headers
        @ini_set( 'zlib.output_compression', '0' );
        @ini_set( 'output_buffering', '0' );
        @ini_set( 'implicit_flush', '1' );
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' );
        }
        nocache_headers();
        header( 'Content-Type: application/x-ndjson; charset=utf-8' );
        header( 'X-Accel-Buffering: no' );
        header( 'Cache-Control: no-cache, no-store' );

        while ( ob_get_level() > 0 ) @ob_end_flush();
        @flush();

        // No time limit
        @set_time_limit( 0 );
        ignore_user_abort( true );

        try {
            $agent = new BizCity_Research_Agent( $session, $turn_id, $turn['user_query'], $mode, $turn['trace_id'] );
            $agent->run();
        } catch ( Throwable $e ) {
            echo wp_json_encode( [ 'type' => 'error', 'message' => $e->getMessage() ] ) . "\n";
            BizCity_Research_Store::finalize_turn( $turn_id, [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ] );
        }
        exit;
    }

    /**
     * Wave 0.18.5d — Combined create-turn + NDJSON stream.
     * POST /sessions/{id}/turns/stream  body: { query, mode }
     *
     * Creates a new turn then immediately streams the NDJSON output.
     * Emits `turn_start` event first so the FE can record the turn_id for cancel.
     */
    public function create_and_stream_turn( WP_REST_Request $req ) {
        $session_id = (int) $req['id'];
        $session    = BizCity_Research_Store::get_session( $session_id );
        $query      = trim( (string) $req->get_param( 'query' ) );
        if ( $query === '' ) {
            return new WP_Error( 'rest_invalid_param', 'query is required', [ 'status' => 400 ] );
        }
        $mode    = (string) ( $req->get_param( 'mode' ) ?: ( $session['agent_mode'] ?? 'deep' ) );
        $turn_id = BizCity_Research_Store::create_turn( $session_id, $query, wp_generate_uuid4() );

        error_log( sprintf(
            '[TwinSearch] create_and_stream_turn START — session=%d turn=%d mode=%s query="%s"',
            $session_id, $turn_id, $mode, mb_substr( $query, 0, 80 )
        ) );

        // Streaming headers
        @ini_set( 'zlib.output_compression', '0' );
        @ini_set( 'output_buffering', '0' );
        @ini_set( 'implicit_flush', '1' );
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' );
        }
        nocache_headers();
        header( 'Content-Type: application/x-ndjson; charset=utf-8' );
        header( 'X-Accel-Buffering: no' );
        header( 'Cache-Control: no-cache, no-store' );
        while ( ob_get_level() > 0 ) @ob_end_flush();
        @flush();
        @set_time_limit( 0 );
        ignore_user_abort( true );

        // Emit turn_id first so FE can track for cancel
        echo wp_json_encode( [ 'type' => 'turn_start', 'payload' => [ 'turn_id' => $turn_id ] ] ) . "\n";
        @flush();

        try {
            $turn  = BizCity_Research_Store::get_turn( $turn_id );
            $agent = new BizCity_Research_Agent( $session, $turn_id, $turn['user_query'], $mode, $turn['trace_id'] );
            $agent->run();
            error_log( sprintf( '[TwinSearch] create_and_stream_turn DONE — session=%d turn=%d', $session_id, $turn_id ) );
        } catch ( Throwable $e ) {
            error_log( sprintf( '[TwinSearch] create_and_stream_turn ERROR — session=%d turn=%d: %s', $session_id, $turn_id, $e->getMessage() ) );
            echo wp_json_encode( [ 'type' => 'error', 'payload' => [ 'message' => $e->getMessage() ] ] ) . "\n";
            BizCity_Research_Store::finalize_turn( $turn_id, [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
            ] );
        }
        exit;
    }

    public function get_turn( WP_REST_Request $req ) {
        return rest_ensure_response( BizCity_Research_Store::get_turn( (int) $req['id'] ) );
    }

    public function ingest_turn( WP_REST_Request $req ) {
        $turn_id = (int) $req['id'];
        $turn    = BizCity_Research_Store::get_turn( $turn_id );
        if ( ! $turn ) {
            return new WP_Error( 'rest_invalid', 'Turn not found', [ 'status' => 404 ] );
        }
        $urls = (array) $req->get_param( 'urls' );
        if ( empty( $urls ) ) {
            return new WP_Error( 'rest_invalid_param', 'urls is required', [ 'status' => 400 ] );
        }
        $r = BizCity_Research_Ingest_Service::ingest_urls( (int) $turn['session_id'], $turn_id, $urls );
        return rest_ensure_response( $r );
    }

    public function list_scope_ingests( WP_REST_Request $req ) {
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id ) ) {
            return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }
        return rest_ensure_response( [
            'items' => BizCity_Research_Ingest_Service::list_for_scope( $scope_type, $scope_id ),
        ] );
    }

    /* ────────────── Wave 0.18.1.6 — Capability + Sync handlers ────────────── */

    /**
     * GET /capability/{character_id}
     * Returns the research capability for the character (or 404 if disabled).
     */
    public function get_capability( WP_REST_Request $req ) {
        $character_id = (int) $req['character_id'];
        if ( $character_id <= 0 ) {
            return new WP_Error( 'rest_invalid', 'character_id required', [ 'status' => 400 ] );
        }
        if ( ! class_exists( 'BizCity_Persona_Registry' ) ) {
            return new WP_Error( 'persona_registry_missing', 'Persona registry not loaded', [ 'status' => 500 ] );
        }
        $capability = BizCity_Persona_Registry::instance()
            ->get_research_capability_for_character( $character_id );

        if ( ! $capability ) {
            return rest_ensure_response( [
                'character_id' => $character_id,
                'enabled'      => false,
            ] );
        }
        return rest_ensure_response( array_merge(
            [ 'character_id' => $character_id ],
            $capability
        ) );
    }

    /**
     * GET /turns/{id}/sources?scope_type=...&scope_id=...
     * Returns the union of source URLs from the turn enriched with attach state.
     */
    public function list_turn_sources( WP_REST_Request $req ) {
        $turn_id    = (int) $req['id'];
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );

        if ( $scope_id <= 0 ) {
            return new WP_Error( 'rest_invalid', 'scope_id required', [ 'status' => 400 ] );
        }
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id ) ) {
            return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }
        return rest_ensure_response( [
            'turn_id'    => $turn_id,
            'scope_type' => $scope_type,
            'scope_id'   => $scope_id,
            'items'      => BizCity_Research_Ingest_Service::list_for_turn_with_state( $turn_id, $scope_type, $scope_id ),
        ] );
    }

    /**
     * POST /turns/{id}/source/attach  body: { url, scope_type, scope_id }
     */
    public function attach_turn_source( WP_REST_Request $req ) {
        $turn_id    = (int) $req['id'];
        $url        = trim( (string) $req->get_param( 'url' ) );
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );

        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'rest_invalid', 'Valid url required', [ 'status' => 400 ] );
        }
        if ( $scope_id <= 0 ) {
            return new WP_Error( 'rest_invalid', 'scope_id required', [ 'status' => 400 ] );
        }
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id ) ) {
            return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }
        $r = BizCity_Research_Ingest_Service::attach_url( $turn_id, $url, $scope_type, $scope_id );
        return rest_ensure_response( $r );
    }

    /**
     * POST /turns/{id}/source/detach  body: { url, scope_type, scope_id }
     */
    public function detach_turn_source( WP_REST_Request $req ) {
        $turn_id    = (int) $req['id'];
        $url        = trim( (string) $req->get_param( 'url' ) );
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );

        if ( $url === '' ) {
            return new WP_Error( 'rest_invalid', 'url required', [ 'status' => 400 ] );
        }
        if ( $scope_id <= 0 ) {
            return new WP_Error( 'rest_invalid', 'scope_id required', [ 'status' => 400 ] );
        }
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id ) ) {
            return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }
        $r = BizCity_Research_Ingest_Service::detach_url( $turn_id, $url, $scope_type, $scope_id );
        return rest_ensure_response( $r );
    }

    /**
     * POST /turns/{id}/source/attach-report  body: { scope_type, scope_id }
     * Attaches the synthesized report markdown itself as a KG source.
     */
    public function attach_turn_report( WP_REST_Request $req ) {
        $turn_id    = (int) $req['id'];
        $scope_type = BizCity_Research_Store::sanitize_scope( (string) $req->get_param( 'scope_type' ) );
        $scope_id   = max( 0, (int) $req->get_param( 'scope_id' ) );

        if ( $scope_id <= 0 ) {
            return new WP_Error( 'rest_invalid', 'scope_id required', [ 'status' => 400 ] );
        }
        if ( ! BizCity_Research_Store::user_can_access( $scope_type, $scope_id ) ) {
            return new WP_Error( 'rest_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }
        $r = BizCity_Research_Ingest_Service::attach_report( $turn_id, $scope_type, $scope_id );
        return rest_ensure_response( $r );
    }
}
