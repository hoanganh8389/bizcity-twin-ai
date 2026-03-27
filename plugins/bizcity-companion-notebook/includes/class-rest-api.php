<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API — All notebook endpoints.
 */
class BCN_REST_API {
    const NS = 'notebook/v1';

    public function register_routes() {
        // Projects.
        register_rest_route( self::NS, '/projects', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_projects' ],  'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_project' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_project' ],    'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_project' ], 'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_project' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Sources.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/sources', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_sources' ],  'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'add_source' ],  'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/sources/upload', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'upload_source' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/sources/(?P<source_id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_source' ],    'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_source' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Messages.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/messages', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_messages' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/messages/(?P<msg_id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'rate_message' ],  'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_message' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Notes.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/notes', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_notes' ],   'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_note' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/notes/search', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'search_notes' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/notes/(?P<note_id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_note' ], 'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_note' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Studio.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/studio', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_studio_outputs' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/studio/generate', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'generate_studio' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/studio/(?P<output_id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_studio_output' ],    'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_studio_output' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/studio/(?P<output_id>\d+)/regenerate', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'regenerate_studio' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/studio/job/(?P<job_id>[a-f0-9]+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'studio_job_status' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/skeleton', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_skeleton' ],      'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'generate_skeleton' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Embeddings.
        register_rest_route( self::NS, '/sources/(?P<source_id>\d+)/embed', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'embed_source' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/sources/(?P<source_id>\d+)/summarize', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'summarize_source' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/embed', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'embed_project' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/embedding-status', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_embedding_status' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/search', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'semantic_search' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Deep Research.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/research', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'research_start' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/research/(?P<job_id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'research_status' ], 'permission_callback' => [ $this, 'check_auth' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'research_cancel' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/research/(?P<job_id>\d+)/import', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'research_import' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );

        // Research Memory — auto-summarize trigger.
        register_rest_route( self::NS, '/projects/(?P<id>[a-f0-9-]+)/research-memory/summarize', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'trigger_research_summarize' ], 'permission_callback' => [ $this, 'check_auth' ] ],
        ] );
    }

    // ── Permission ──
    public function check_auth( $req ) {
        return is_user_logged_in();
    }

    // ── Projects ──
    public function get_projects( $req ) {
        $projects = new BCN_Projects();
        $list = $projects->get_list( get_current_user_id(), [
            'featured' => $req->get_param( 'featured' ),
            'search'   => $req->get_param( 'search' ),
            'orderby'  => $req->get_param( 'orderby' ),
        ] );
        return rest_ensure_response( $list );
    }

    public function get_project( $req ) {
        $project = ( new BCN_Projects() )->get( $req['id'] );
        if ( ! $project ) return new WP_Error( 'not_found', 'Project not found', [ 'status' => 404 ] );
        return rest_ensure_response( $project );
    }

    public function create_project( $req ) {
        $data = $req->get_json_params();
        $id = ( new BCN_Projects() )->create( $data );
        if ( is_wp_error( $id ) ) return $id;
        $project = ( new BCN_Projects() )->get( $id );
        return rest_ensure_response( $project );
    }

    public function update_project( $req ) {
        $data = $req->get_json_params();
        $result = ( new BCN_Projects() )->update( $req['id'], $data );
        if ( is_wp_error( $result ) ) return $result;
        $project = ( new BCN_Projects() )->get( $req['id'] );
        return rest_ensure_response( $project );
    }

    public function delete_project( $req ) {
        ( new BCN_Projects() )->delete( $req['id'] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Sources ──
    public function get_sources( $req ) {
        return rest_ensure_response( ( new BCN_Sources() )->get_by_project( $req['id'] ) );
    }

    public function add_source( $req ) {
        $data = $req->get_json_params();
        $id = ( new BCN_Sources() )->add( $req['id'], $data );
        if ( is_wp_error( $id ) ) return $id;
        $source = ( new BCN_Sources() )->get( $id );
        return rest_ensure_response( $source );
    }

    public function upload_source( $req ) {
        $files = $req->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new WP_Error( 'no_file', 'No file uploaded', [ 'status' => 400 ] );
        }
        $id = ( new BCN_Sources() )->upload( $req['id'], $files['file'] );
        if ( is_wp_error( $id ) ) return $id;
        $source = ( new BCN_Sources() )->get( $id );
        return rest_ensure_response( $source );
    }

    public function get_source( $req ) {
        $source = ( new BCN_Sources() )->get( $req['source_id'] );
        if ( ! $source ) return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
        return rest_ensure_response( $source );
    }

    public function delete_source( $req ) {
        ( new BCN_Sources() )->delete( $req['source_id'] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Messages ──
    public function get_messages( $req ) {
        return rest_ensure_response( ( new BCN_Messages() )->get_by_project( $req['id'], [
            'limit'  => $req->get_param( 'limit' ),
            'before' => $req->get_param( 'before' ),
        ] ) );
    }

    public function rate_message( $req ) {
        $data = $req->get_json_params();
        ( new BCN_Messages() )->rate( $req['msg_id'], $data['rating'] ?? '' );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function delete_message( $req ) {
        ( new BCN_Messages() )->delete( $req['msg_id'] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Notes ──
    public function get_notes( $req ) {
        return rest_ensure_response( ( new BCN_Notes() )->get_by_project( $req['id'] ) );
    }

    public function search_notes( $req ) {
        $keyword = sanitize_text_field( $req->get_param( 'keyword' ) ?? '' );
        return rest_ensure_response( ( new BCN_Notes() )->search_by_keyword( $req['id'], $keyword ) );
    }

    public function create_note( $req ) {
        $data = $req->get_json_params();

        // Handle pin-from-message.
        if ( ( $data['source'] ?? '' ) === 'chat_pinned' && ! empty( $data['source_message_id'] ) ) {
            $fallback = ! empty( $data['content'] ) ? sanitize_textarea_field( $data['content'] ) : '';
            $id = ( new BCN_Notes() )->pin_from_message( $req['id'], $data['source_message_id'], get_current_user_id(), $fallback );
        } else {
            $data['project_id'] = $req['id'];
            $id = ( new BCN_Notes() )->create( $data );
        }

        if ( is_wp_error( $id ) ) return $id;

        // Return the created note.
        global $wpdb;
        $note = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . BCN_Schema_Extend::table_notes() . " WHERE id = %d",
            $id
        ) );
        return rest_ensure_response( $note );
    }

    public function update_note( $req ) {
        $data = $req->get_json_params();
        $result = ( new BCN_Notes() )->update( $req['note_id'], $data );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function delete_note( $req ) {
        ( new BCN_Notes() )->delete( $req['note_id'] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Research Memory ──

    public function trigger_research_summarize( $req ) {
        if ( ! class_exists( 'BCN_Research_Memory' ) ) {
            return rest_ensure_response( [ 'skipped' => true, 'reason' => 'class_not_loaded' ] );
        }

        $project_id = $req['id'];
        $user_id    = get_current_user_id();
        $messages_handler = new BCN_Messages();
        $session_id = $messages_handler->ensure_session( $project_id, $user_id );

        $result = BCN_Research_Memory::instance()->trigger_summarize( $project_id, $session_id, $user_id );
        return rest_ensure_response( $result );
    }

    // ── Studio ──
    public function get_studio_outputs( $req ) {
        return rest_ensure_response( ( new BCN_Studio() )->get_outputs( $req['id'] ) );
    }

    public function generate_studio( $req ) {
        $data      = $req->get_json_params();
        $tool_type = sanitize_text_field( $data['tool_type'] ?? '' );
        $project_id = sanitize_text_field( $req['id'] );
        $user_id   = get_current_user_id();

        // Async: return job_id immediately to avoid Cloudflare 524.
        // Job ID is pure hex so it matches the REST route regex [a-f0-9]+ reliably.
        $job_id = bin2hex( random_bytes( 16 ) );
        set_transient( 'bcn_studio_job_' . $job_id, [
            'status'     => 'processing',
            'started_at' => time(),
        ], 600 );

        // Flush response to client, then continue in background
        ob_end_clean();
        header( 'Content-Type: application/json; charset=UTF-8' );
        $payload = wp_json_encode( [ 'job_id' => $job_id, 'async' => true ] );
        header( 'Content-Length: ' . strlen( $payload ) );
        echo $payload;

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            if ( ob_get_level() ) ob_end_flush();
            flush();
        }

        ignore_user_abort( true );
        set_time_limit( 300 );

        $studio = new BCN_Studio();
        $id     = $studio->generate( $project_id, $tool_type, $user_id );

        if ( is_wp_error( $id ) ) {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'failed',
                'error'  => $id->get_error_message(),
            ], 600 );
        } else {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'completed',
                'data'   => $studio->get_output( $id ),
            ], 600 );
        }

        exit;
    }

    public function get_studio_output( $req ) {
        $output = ( new BCN_Studio() )->get_output( $req['output_id'] );
        if ( ! $output ) return new WP_Error( 'not_found', 'Output not found', [ 'status' => 404 ] );
        return rest_ensure_response( $output );
    }

    public function delete_studio_output( $req ) {
        ( new BCN_Studio() )->delete_output( $req['output_id'] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function regenerate_studio( $req ) {
        $output_id = absint( $req['output_id'] );
        $user_id   = get_current_user_id();

        $job_id = bin2hex( random_bytes( 16 ) );
        set_transient( 'bcn_studio_job_' . $job_id, [
            'status'     => 'processing',
            'started_at' => time(),
        ], 600 );

        ob_end_clean();
        header( 'Content-Type: application/json; charset=UTF-8' );
        $payload = wp_json_encode( [ 'job_id' => $job_id, 'async' => true ] );
        header( 'Content-Length: ' . strlen( $payload ) );
        echo $payload;

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            if ( ob_get_level() ) ob_end_flush();
            flush();
        }

        ignore_user_abort( true );
        set_time_limit( 300 );

        $studio = new BCN_Studio();
        $id     = $studio->regenerate( $output_id );

        if ( is_wp_error( $id ) ) {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'failed',
                'error'  => $id->get_error_message(),
            ], 600 );
        } else {
            set_transient( 'bcn_studio_job_' . $job_id, [
                'status' => 'completed',
                'data'   => $studio->get_output( $id ),
            ], 600 );
        }

        exit;
    }

    public function studio_job_status( $req ) {
        $job_id = sanitize_text_field( $req['job_id'] );
        $job    = get_transient( 'bcn_studio_job_' . $job_id );

        if ( ! $job ) {
            return new WP_Error( 'not_found', 'Job not found or expired', [ 'status' => 404 ] );
        }

        if ( $job['status'] === 'processing' ) {
            return rest_ensure_response( [
                'status'  => 'processing',
                'elapsed' => time() - ( $job['started_at'] ?? time() ),
            ] );
        }

        if ( $job['status'] === 'failed' ) {
            delete_transient( 'bcn_studio_job_' . $job_id );
            return new WP_Error( 'generation_failed', $job['error'] ?? 'Generation failed', [ 'status' => 500 ] );
        }

        $data = $job['data'] ?? null;
        delete_transient( 'bcn_studio_job_' . $job_id );
        return rest_ensure_response( [ 'status' => 'completed', 'data' => $data ] );
    }

    public function get_skeleton( $req ) {
        $skeleton = BCN_Studio_Input_Builder::get_cached( $req['id'] );
        if ( ! $skeleton ) {
            return rest_ensure_response( [ 'empty' => true, 'message' => 'Chưa có skeleton. Nhấn nút Tạo để generate.' ] );
        }
        return rest_ensure_response( $skeleton );
    }

    public function generate_skeleton( $req ) {
        $skeleton = BCN_Studio_Input_Builder::build( $req['id'], [ 'force' => true ] );
        if ( is_wp_error( $skeleton ) ) return $skeleton;
        return rest_ensure_response( $skeleton );
    }

    // ── Embeddings ──
    private function get_embedder() {
        if ( ! class_exists( 'BCN_Embedder' ) ) {
            require_once BCN_INCLUDES . 'class-chunker.php';
            require_once BCN_INCLUDES . 'class-embedder.php';
        }
        return new BCN_Embedder();
    }

    public function embed_source( $req ) {
        $source_id = absint( $req['source_id'] );
        $data  = $req->get_json_params();
        $model = sanitize_text_field( $data['model'] ?? '' );

        try {
            $embedder = $this->get_embedder();
            $result   = $embedder->embed_source( $source_id, $model );
        } catch ( \Throwable $e ) {
            $result = [ 'success' => false, 'chunks' => 0, 'error' => $e->getMessage() ];
        }

        // Always return JSON (never WP_Error 500) so frontend can handle gracefully.
        return rest_ensure_response( $result );
    }

    public function embed_project( $req ) {
        $data  = $req->get_json_params();
        $model = sanitize_text_field( $data['model'] ?? '' );

        $embedder = $this->get_embedder();
        $result   = $embedder->embed_project( $req['id'], $model );

        return rest_ensure_response( $result );
    }

    public function get_embedding_status( $req ) {
        $embedder = $this->get_embedder();
        return rest_ensure_response( $embedder->get_project_status( $req['id'] ) );
    }

    public function semantic_search( $req ) {
        $data  = $req->get_json_params();
        $query = sanitize_text_field( $data['query'] ?? '' );
        $top_k = absint( $data['top_k'] ?? 5 ) ?: 5;

        if ( ! $query ) {
            return new WP_Error( 'missing_query', 'Query text required', [ 'status' => 400 ] );
        }

        $source_ids = [];
        if ( ! empty( $data['source_ids'] ) && is_array( $data['source_ids'] ) ) {
            $source_ids = array_map( 'absint', $data['source_ids'] );
        }

        $embedder = $this->get_embedder();
        $results  = $embedder->search( $query, $req['id'], $top_k, 0.25, $source_ids );

        return rest_ensure_response( $results );
    }

    // ── Source Summarize (SSE streaming) ──

    public function summarize_source( $req ) {
        $source_id = absint( $req['source_id'] );
        $sources   = new BCN_Sources();
        $source    = $sources->get( $source_id );

        if ( ! $source ) {
            return new WP_Error( 'not_found', 'Source not found', [ 'status' => 404 ] );
        }
        if ( (int) $source->user_id !== get_current_user_id() ) {
            return new WP_Error( 'forbidden', 'Not your source', [ 'status' => 403 ] );
        }

        // Check cached summary in source metadata.
        $meta = json_decode( $source->metadata ?? '{}', true ) ?: [];
        if ( ! empty( $meta['ai_summary'] ) ) {
            // Return cached result as JSON (no SSE needed).
            return rest_ensure_response( [
                'cached'    => true,
                'summary'   => $meta['ai_summary'],
                'questions' => $meta['ai_questions'] ?? [],
            ] );
        }

        // Disable output buffering for SSE.
        @ini_set( 'zlib.output_compression', 'Off' );
        while ( ob_get_level() > 0 ) ob_end_clean();
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );

        $content = mb_substr( $source->content_text ?? '', 0, 40000 );
        $title   = $source->title ?? 'Untitled';
        $type    = $source->source_type ?? 'text';
        $url     = $source->source_url ?? '';

        $system = "Bạn là trợ lý nghiên cứu BizCity Notebook.\n\n"
            . "NHIỆM VỤ: Phân tích nguồn tài liệu sau và tạo tóm tắt + gợi ý câu hỏi.\n\n"
            . "THÔNG TIN NGUỒN:\n"
            . "- Tiêu đề: {$title}\n"
            . "- Loại: {$type}\n"
            . ( $url ? "- URL: {$url}\n" : '' )
            . "- Độ dài: " . mb_strlen( $content ) . " ký tự\n\n"
            . "TRẢ LỜI THEO CẤU TRÚC SAU:\n\n"
            . "## Tóm tắt\n"
            . "Tóm tắt nội dung chính 3-5 câu. Nêu rõ chủ đề, các điểm quan trọng.\n\n"
            . "## Nội dung chính\n"
            . "Liệt kê 3-7 điểm chính (bullet points) được đề cập trong tài liệu.\n\n"
            . "## Gợi ý câu hỏi\n"
            . "Liệt kê 4-6 câu hỏi MÀ NGƯỜI DÙNG CÓ THỂ HỎI về nội dung tài liệu này. "
            . "Format mỗi câu hỏi là một bullet point (- ). "
            . "Câu hỏi phải cụ thể, liên quan trực tiếp đến nội dung, viết từ góc nhìn người đọc.\n"
            . "Ví dụ: '- Giải thích chi tiết về [concept X] trong tài liệu' / '- So sánh [A] và [B] được đề cập'\n\n"
            . "QUY TẮC:\n"
            . "- Trả lời bằng tiếng Việt\n"
            . "- Ngắn gọn, súc tích\n"
            . "- Câu hỏi phải dựa trên nội dung THỰC SỰ có trong tài liệu\n";

        $llm_messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => "Nội dung tài liệu:\n\n{$content}" ],
        ];

        if ( ! function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $mu = WP_CONTENT_DIR . '/mu-plugins/bizcity-openrouter/bootstrap.php';
            if ( file_exists( $mu ) ) require_once $mu;
        }

        if ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $full = '';
            bizcity_openrouter_chat_stream( $llm_messages, [], function( $delta ) use ( &$full ) {
                $full .= $delta;
                echo "event: chunk\ndata: " . wp_json_encode( [ 'delta' => $delta, 'full' => $full ] ) . "\n\n";
                if ( ob_get_level() ) ob_flush();
                flush();
            } );

            // Extract suggested questions.
            $questions = [];
            if ( preg_match( '/## Gợi ý câu hỏi.*?\n((?:\s*[-•]\s*.+\n?)+)/u', $full, $m ) ) {
                preg_match_all( '/[-•]\s*(.+)/u', $m[1], $items );
                if ( ! empty( $items[1] ) ) {
                    foreach ( $items[1] as $item ) {
                        $clean = trim( wp_strip_all_tags( $item ) );
                        if ( $clean ) $questions[] = $clean;
                    }
                }
            }

            echo "event: done\ndata: " . wp_json_encode( [
                'summary'   => $full,
                'questions' => array_slice( $questions, 0, 6 ),
            ] ) . "\n\n";

            // Cache summary + questions into source metadata for future loads.
            $meta['ai_summary']   = $full;
            $meta['ai_questions'] = array_slice( $questions, 0, 6 );
            global $wpdb;
            $wpdb->update(
                BCN_Schema_Extend::table_sources(),
                [ 'metadata' => wp_json_encode( $meta ) ],
                [ 'id' => $source_id ],
                [ '%s' ],
                [ '%d' ]
            );
        } else {
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'No LLM provider' ] ) . "\n\n";
        }

        if ( ob_get_level() ) ob_flush();
        flush();
        exit;
    }

    // ── Deep Research ──

    public function research_start( $req ) {
        $data        = $req->get_json_params();
        $query       = sanitize_text_field( $data['query'] ?? '' );
        $max_results = absint( $data['max_results'] ?? 5 );
        $language    = sanitize_text_field( $data['language'] ?? 'vi' );
        $project_id  = sanitize_text_field( $req['id'] );

        if ( empty( $query ) ) {
            return new WP_Error( 'missing_query', 'Query text is required.', [ 'status' => 400 ] );
        }

        $dr     = new BCN_Deep_Research();
        $job_id = $dr->run( $project_id, $query, [ 'max_results' => $max_results, 'language' => $language ] );

        if ( is_wp_error( $job_id ) ) return $job_id;

        // Return current status right away (may already be completed if Action Scheduler unavailable).
        $status = $dr->get_status( $job_id, $project_id );
        if ( is_wp_error( $status ) ) return $status;

        return rest_ensure_response( $status );
    }

    public function research_status( $req ) {
        global $wpdb;
        $project_id = sanitize_text_field( $req['id'] );
        $job_id     = absint( $req['job_id'] );

        $dr     = new BCN_Deep_Research();
        $status = $dr->get_status( $job_id, $project_id );
        if ( is_wp_error( $status ) ) return $status;

        // ── Action Scheduler stale-job fallback ──────────────────────────
        // If the job is still pending/running after 15 seconds, Action Scheduler
        // probably isn't running on this server. Process it synchronously NOW so
        // the user doesn't wait forever.
        if ( in_array( $status['status'], [ 'pending', 'running' ], true ) ) {
            $table = BCN_Schema_Extend::table_research_jobs();
            $row   = $wpdb->get_row( $wpdb->prepare(
                "SELECT created_at, status FROM {$table} WHERE id = %d LIMIT 1",
                $job_id
            ) );
            $age_seconds = $row ? ( time() - strtotime( $row->created_at ) ) : 999;

            if ( $age_seconds > 15 ) {
                error_log( "[BCN Research] Job #{$job_id} stale ({$age_seconds}s, status={$row->status}). Running synchronously." );
                ignore_user_abort( true );
                set_time_limit( 120 );
                $dr->process_job( $job_id );
                $status = $dr->get_status( $job_id, $project_id );
                if ( is_wp_error( $status ) ) return $status;
            }
        }

        // Include debug info (visible in Network tab JSON)
        $status['_debug'] = [
            'server_time' => current_time( 'mysql' ),
            'as_available' => function_exists( 'as_enqueue_async_action' ),
        ];

        return rest_ensure_response( $status );
    }

    public function research_import( $req ) {
        $data          = $req->get_json_params();
        $selected_urls = $data['selected_urls'] ?? [];
        $project_id    = sanitize_text_field( $req['id'] );
        $job_id        = absint( $req['job_id'] );

        if ( empty( $selected_urls ) || ! is_array( $selected_urls ) ) {
            return new WP_Error( 'no_urls', 'No URLs selected for import.', [ 'status' => 400 ] );
        }

        $dr         = new BCN_Deep_Research();
        $source_ids = $dr->import_selected( $job_id, $selected_urls, $project_id );

        if ( is_wp_error( $source_ids ) ) return $source_ids;

        // Return newly imported source rows for immediate display.
        $sources_model = new BCN_Sources();
        $imported = [];
        foreach ( $source_ids as $sid ) {
            $src = $sources_model->get( $sid );
            if ( $src ) $imported[] = $src;
        }

        return rest_ensure_response( [
            'success'    => true,
            'source_ids' => $source_ids,
            'sources'    => $imported,
        ] );
    }

    public function research_cancel( $req ) {
        $project_id = sanitize_text_field( $req['id'] );
        $job_id     = absint( $req['job_id'] );

        $result = ( new BCN_Deep_Research() )->delete_job( $job_id, $project_id );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true ] );
    }
}
