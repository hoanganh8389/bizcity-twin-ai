<?php
defined( 'ABSPATH' ) || exit;

/**
 * Chat Engine — BizCity Companion v2.
 *
 * Upgraded from simple RAG chatbot to Companion Agent:
 * - Source context + notes context
 * - Journey state (what user is doing, where they are)
 * - Recent timeline events
 * - Follow-up question suggestions
 * - Intent engine proxy + fallback SSE path
 */
class BCN_Chat_Engine {

    public function register_hooks() {
        // Inject Notebook source/notes context into Intent Stream's system prompt filter chain.
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_source_context' ], 15, 2 );
        // Save bot response to BCN messages table after Intent Stream completes.
        add_action( 'bizcity_chat_after_response', [ $this, 'on_response_complete' ], 10, 3 );
        // Block tool execution for NOTEBOOK platform — keep it as a companion/knowledge chat.
        add_filter( 'bizcity_intent_mode_result', [ $this, 'block_execution_for_notebook' ], 10, 2 );
    }

    /**
     * Force knowledge mode for NOTEBOOK platform — disable tool execution.
     * Intent Engine will route to KnowledgePipeline (RAG + AI compose) instead of executor.
     */
    public function block_execution_for_notebook( array $mode_result, array $params ): array {
        // Only affect requests coming from the Notebook (platform_type set in $_POST by handle_sse).
        $platform = sanitize_text_field( wp_unslash( $_POST['platform_type'] ?? '' ) );
        if ( $platform !== 'NOTEBOOK' ) {
            return $mode_result;
        }
        if ( ( $mode_result['mode'] ?? '' ) === BizCity_Mode_Classifier::MODE_EXECUTION ) {
            $mode_result['mode']   = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
            $mode_result['method'] = 'notebook_no_exec';
            error_log( '[BCN Chat] block_execution_for_notebook: execution blocked → knowledge' );
        }
        return $mode_result;
    }

    // ── System Prompt v2 ──

    /**
     * Build the full Companion v2 system prompt.
     */
    public static function build_system_prompt( string $project_id, ?object $project = null ): string {
        error_log( "[BCN] build_system_prompt: project_id={$project_id}" );

        $source_context  = self::build_source_context( $project_id );
        $notes_context   = self::build_notes_context( $project_id );
        $journey_state   = self::build_journey_state( $project_id, $project );
        $recent_events   = self::build_recent_events( $project_id );

        error_log( '[BCN] context: sources=' . mb_strlen( $source_context ) . ' notes=' . mb_strlen( $notes_context ) . ' journey=' . mb_strlen( $journey_state ) . ' events=' . mb_strlen( $recent_events ) );

        $prompt = "Bạn là trợ lý nghiên cứu chuyên sâu (BizCity Research Companion).\n\n"
                . "VAI TRÒ: Bạn là nhà nghiên cứu đồng hành — phân tích tài liệu một cách HỌC THUẬT, SÂU SẮC và CHI TIẾT.\n\n"
                . "PHONG CÁCH TRẢ LỜI:\n"
                . "- Trả lời DÀI, ĐẦY ĐỦ, có cấu trúc rõ ràng (heading, bullet, numbered list)\n"
                . "- Phân tích đa chiều: so sánh, đối chiếu, liên kết các nguồn với nhau\n"
                . "- Trích dẫn cụ thể từ tài liệu: tên nguồn, đoạn trích, số liệu\n"
                . "- Đưa ra nhận xét, đánh giá chuyên gia khi phù hợp\n"
                . "- Kết nối với kiến thức rộng hơn nếu tài liệu cho phép\n"
                . "- Highlight insight quan trọng và hàm ý thực tiễn\n\n"
                . "QUY TẮC:\n"
                . "1. Ưu tiên thông tin từ NGUỒN TÀI LIỆU và GHI CHÚ — luôn trích dẫn nguồn\n"
                . "2. Phân tích sâu: không chỉ tóm tắt mà phải giải thích WHY, HOW, SO WHAT\n"
                . "3. Nếu tài liệu có nhiều góc nhìn → trình bày cả hai và đánh giá\n"
                . "4. Nếu thấy thông tin quan trọng → đề xuất lưu thành NOTE\n\n"
                . "KHI TÀI LIỆU KHÔNG ĐỀ CẬP ĐẾN CHỦ ĐỀ:\n"
                . "- KHÔNG từ chối trả lời, KHÔNG nói ngắn gọn rồi dừng\n"
                . "- Trả lời ĐẦY ĐỦ, CHI TIẾT dựa trên kiến thức của bạn (ghi rõ '📚 Từ kiến thức chung:' ở đầu phần này)\n"
                . "- Sau khi trả lời xong, gợi ý 🔍 để tìm nguồn xác minh/bổ sung (không cắt bỏ nội dung)\n"
                . "- Mục tiêu: user luôn nhận được câu trả lời hữu ích, sau đó có thể làm giàu bằng tài liệu thực tế\n\n"
                . "FORMAT GỢI Ý (BẮT BUỘC cuối mỗi câu trả lời):\n"
                . "---\n💡 **Gợi ý tiếp theo:**\n"
                . "- 🔍 Tìm thêm nguồn về [chủ đề chính user đang hỏi]\n"
                . "- Phân tích sâu hơn về [khía cạnh cụ thể trong tài liệu]\n"
                . "- So sánh [A] với [B] trong bối cảnh [chủ đề]\n\n"
                . "LUẬT GỢI Ý:\n"
                . "- Gợi ý 1: Bắt đầu bằng 🔍, phản ánh chủ đề user vừa hỏi\n"
                . "- Gợi ý 2-3: Dạng hành động — bắt đầu bằng động từ (Phân tích, So sánh, Mở rộng, Giải thích, Tổng hợp, Liệt kê...)\n"
                . "- KHÔNG bao giờ viết dạng 'Bạn muốn...' / 'Bạn có muốn...' / 'Mình có thể...'\n"
                . "- ĐÚNG: 'Phân tích chi tiết cơ chế X' / 'So sánh framework A với B' / 'Tổng hợp các best practices'\n"
                . "- SAI: 'Bạn muốn mình giúp phân tích...' / 'Bạn có muốn tìm hiểu thêm...'\n";

        if ( $source_context ) {
            $prompt .= "\n--- NGUỒN TÀI LIỆU ---\n{$source_context}\n--- HẾT NGUỒN ---\n";
        }
        if ( $notes_context ) {
            $prompt .= "\n--- GHI CHÚ ĐÃ LƯU ---\n{$notes_context}\n--- HẾT GHI CHÚ ---\n";
        }
        if ( $journey_state ) {
            $prompt .= "\n--- TRẠNG THÁI CÔNG VIỆC ---\n{$journey_state}\n--- HẾT ---\n";
        }
        if ( $recent_events ) {
            $prompt .= "\n--- HOẠT ĐỘNG GẦN ĐÂY ---\n{$recent_events}\n--- HẾT ---\n";
        }

        return $prompt;
    }

    // ── SSE Handler ──

    public function handle_sse() {
        check_ajax_referer( 'bcn_ajax' );

        $project_id = sanitize_text_field( wp_unslash( $_POST['project_id'] ?? '' ) );
        $message    = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
        $user_id    = get_current_user_id();

        if ( ! $user_id || ! $project_id || ! $message ) {
            wp_send_json_error( 'Missing required fields' );
        }

        // Verify ownership.
        $projects = new BCN_Projects();
        $project  = $projects->get( $project_id );

        if ( ! $project || (int) $project->user_id !== $user_id ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Ensure session exists.
        $messages_handler = new BCN_Messages();
        $session_id = $messages_handler->ensure_session( $project_id, $user_id );

        // Save user message.
        $user_msg_id = $messages_handler->create( [
            'project_id'   => $project_id,
            'session_id'   => $session_id,
            'user_id'      => $user_id,
            'message_text' => $message,
            'message_from' => 'user',
        ] );

        // Store for filter hook.
        set_transient( "bcn_sse_project_{$user_id}", $project_id, 120 );

        error_log( "[BCN] handle_sse: project_id={$project_id}, user_id={$user_id}, session_id={$session_id}" );
        error_log( "[BCN] handle_sse: bcn_has_intent=" . ( bcn_has_intent() ? '1' : '0' ) );

        // No-source guard: when the project has no sources yet, short-circuit and
        // suggest Deep Research instead of letting the LLM say "no information".
        $sources_check = new BCN_Sources();
        if ( empty( $sources_check->get_by_project( $project_id ) ) ) {
            $this->stream_no_sources_suggestion( $project_id, $project, $message, $session_id, $user_id, $user_msg_id );
            return;
        }

        // Intent detection — route search/research requests to AddSourceDialog.
        $intent = BCN_Intent_Detector::detect( $message );
        if ( in_array( $intent['intent'], [ BCN_Intent_Detector::INTENT_SEARCH, BCN_Intent_Detector::INTENT_START_RESEARCH ], true ) ) {
            $auto_start = ( $intent['intent'] === BCN_Intent_Detector::INTENT_START_RESEARCH );

            // G1: Context-aware routing — if project already has sources and the intent
            // is SEARCH (not START_RESEARCH), only open dialog for *explicit* web-search
            // phrases (e.g. "thêm nguồn", "tìm trên web"). Generic queries like
            // "tìm hiểu thêm về X" should fall through to the AI answering from docs.
            if (
                ! $auto_start
                && $intent['intent'] === BCN_Intent_Detector::INTENT_SEARCH
                && ! BCN_Intent_Detector::is_explicit_web_search( $message )
            ) {
                // Not an explicit web-search request — let AI answer from existing sources.
                // Fall through to Intent Engine / fallback below.
            } else {
                $this->stream_open_deep_research( $project_id, $project, $message, $intent['query'], $session_id, $user_id, $user_msg_id, $auto_start );
                return;
            }
        }

        // Intent Engine proxy — route through Intent Stream's full filter chain
        // so Notebook receives user memory, rolling memory, and episodic memory.
        if ( bcn_has_intent() ) {
            error_log( '[BCN] → Intent Engine path' );
            // Set $_REQUEST + $_POST for Intent Stream (reads $_REQUEST).
            $proxy_fields = [
                'action'              => 'bizcity_chat_stream',
                'session_id'          => $session_id,
                'platform_type'       => 'NOTEBOOK',
                'notebook_project_id' => $project_id,
                'project_id'          => $project_id,
                '_bcn_user_msg_id'    => $user_msg_id, // Pass BCN's user msg DB id so Intent Stream skips duplicate + done event includes it
            ];
            foreach ( $proxy_fields as $k => $v ) {
                $_POST[ $k ]    = $v;
                $_REQUEST[ $k ] = $v;
            }
            // Execution is blocked via bizcity_intent_mode_result filter (block_execution_for_notebook).
            unset( $_POST['forced_mode'], $_REQUEST['forced_mode'] );

            // Invoke Intent Stream's handle_sse() — registered at wp_ajax_bizcity_chat_stream.
            // It streams SSE output directly and calls exit.
            do_action( 'wp_ajax_bizcity_chat_stream' );
            return;
        }

        // Fallback: direct OpenRouter call.
        error_log( '[BCN] → Fallback SSE path' );
        $this->fallback_sse( $project_id, $project, $message, $session_id, $user_id, $user_msg_id );
    }

    // ── No-source suggestion (Deep Research CTA) ──

    /**
     * When a project has no sources, use LLM streaming to generate a context-aware
     * Deep Research suggestion based on conversation history + missing-knowledge gaps.
     */
    private function stream_no_sources_suggestion( $project_id, $project, $original_query, $session_id, $user_id, $user_msg_id ) {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );

        $topic = ! empty( $project->title ) && strtolower( $project->title ) !== 'untitled'
            ? $project->title
            : mb_substr( $original_query, 0, 80 );

        // ── 1. Gather conversation history for context ──
        $messages_handler = new BCN_Messages();
        $recent = $messages_handler->get_by_project( $project_id, [ 'limit' => 30 ] );

        $conv_lines = [];
        foreach ( $recent as $msg ) {
            $role = $msg->role === 'user' ? 'User' : 'AI';
            $conv_lines[] = "{$role}: " . mb_substr( $msg->content, 0, 500 );
        }
        $conversation_block = ! empty( $conv_lines )
            ? "\n--- LỊCH SỬ HỘI THOẠI ---\n" . implode( "\n", $conv_lines ) . "\n--- HẾT ---\n"
            : '';

        // ── 2. Build LLM prompt with conversation context ──
        $system = "Bạn là trợ lý nghiên cứu BizCity Notebook.\n\n"
            . "TÌNH HUỐNG: Người dùng đang trong Notebook project \"{$topic}\" nhưng CHƯA CÓ tài liệu/nguồn nào.\n"
            . "Câu hỏi hiện tại: \"{$original_query}\"\n"
            . $conversation_block
            . "\nNHIỆM VỤ — trả lời theo cấu trúc sau:\n\n"
            . "1. MỞ ĐẦU (1-2 câu): Thông báo Notebook chưa có tài liệu, gợi ý nhấn nút **Thêm nguồn**.\n\n"
            . "2. TÓM TẮT NGỮ CẢNH (nếu có hội thoại trước): Tóm gọn 1-2 câu nội dung người dùng đang nghiên cứu.\n\n"
            . "3. TỪ KHÓA CẦN TÌM: Liệt kê 3-5 cụm từ khóa/chủ đề CỤ THỂ mà người dùng cần tìm tài liệu:\n"
            . "   **Từ khóa cần tìm:** `keyword1`, `keyword2`, `keyword3`\n\n"
            . "4. KẾT THÚC bằng block gợi ý (format BẮT BUỘC):\n"
            . "---\n💡 **Gợi ý tiếp theo:**\n"
            . "- 🔍 [cụm từ tìm kiếm cụ thể nhất, phản ánh ngữ cảnh hội thoại — dùng làm search query]\n"
            . "- 🔍 [cụm từ tìm kiếm bổ sung từ góc nhìn khác]\n"
            . "- [câu hỏi follow-up hữu ích từ góc nhìn user]\n\n"
            . "QUY TẮC:\n"
            . "- Gợi ý 🔍 PHẢI là cụm từ tìm kiếm (search query) cụ thể, KHÔNG phải câu hỏi, KHÔNG bắt đầu bằng 'Thêm nguồn về'\n"
            . "- Ví dụ ĐÚNG: '🔍 AI Agent architecture multi-agent framework' / '🔍 LangChain RAG pipeline tutorial'\n"
            . "- Ví dụ SAI: '🔍 Thêm nguồn về AI' / '🔍 Bạn có muốn tìm hiểu...'\n"
            . "- Từ khóa phải phản ánh CHÍNH XÁC ngữ cảnh hội thoại + câu hỏi hiện tại\n"
            . "- Trả lời bằng tiếng Việt, ngắn gọn\n";

        $llm_messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $original_query ],
        ];

        // ── 3. Stream LLM response ──
        if ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $full_response = '';
            bizcity_openrouter_chat_stream( $llm_messages, [ 'purpose' => 'chat' ], function( $delta ) use ( &$full_response ) {
                $full_response .= $delta;
                echo "event: chunk\ndata: " . wp_json_encode( [ 'delta' => $delta, 'full' => $full_response ] ) . "\n\n";
                if ( ob_get_level() ) ob_flush();
                flush();
            } );

            // Persist bot message.
            $bot_msg_id = $messages_handler->create( [
                'project_id'   => $project_id,
                'session_id'   => $session_id,
                'user_id'      => $user_id,
                'message_text' => $full_response,
                'message_from' => 'bot',
            ] );

            // Extract suggestions + enriched search query from LLM response.
            $suggestions  = self::extract_suggestions( $full_response );
            $search_query = self::extract_deep_research_query( $suggestions, $original_query );

            // Note: no auto_pin here — this is a search-redirect path (no substantive answer).

            echo "event: done\ndata: " . wp_json_encode( [
                'message'             => [ 'id' => $bot_msg_id, 'role' => 'assistant', 'content' => $full_response ],
                'userMessage'         => [ 'id' => $user_msg_id ],
                'suggestions'         => $suggestions,
                'open_deep_research'  => true,
                'deep_research_query' => $search_query,
                'deep_research_auto'  => false,
            ] ) . "\n\n";
        } else {
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'No LLM provider available' ] ) . "\n\n";
        }

        if ( ob_get_level() ) ob_flush();
        flush();
        exit;
    }

    /**
     * Extract the best Deep Research search query from suggestions.
     * Uses the first 🔍 pill text, stripping the emoji prefix.
     */
    private static function extract_deep_research_query( array $suggestions, string $fallback ): string {
        foreach ( $suggestions as $s ) {
            if ( mb_strpos( $s, '🔍' ) === 0 ) {
                return trim( preg_replace( '/^🔍\s*/', '', $s ) );
            }
        }
        return mb_substr( $fallback, 0, 80 );
    }

    /**
     * Stream a context-aware Deep Research suggestion.
     * Uses LLM streaming + conversation summary to generate focused search queries.
     *
     * @param bool $auto_start  When true (start_research intent), dialog will auto-submit immediately.
     */
    private function stream_open_deep_research( $project_id, $project, $original_query, $clean_query, $session_id, $user_id, $user_msg_id, bool $auto_start = false ) {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );

        $topic = ! empty( $clean_query ) ? $clean_query : (
            ! empty( $project->title ) && strtolower( $project->title ) !== 'untitled'
                ? $project->title
                : mb_substr( $original_query, 0, 80 )
        );

        // ── 1. Gather conversation history + existing source names ──
        $messages_handler = new BCN_Messages();
        $recent = $messages_handler->get_by_project( $project_id, [ 'limit' => 30 ] );

        $conv_lines = [];
        foreach ( $recent as $msg ) {
            $role = $msg->role === 'user' ? 'User' : 'AI';
            $conv_lines[] = "{$role}: " . mb_substr( $msg->content, 0, 500 );
        }
        $conversation_block = ! empty( $conv_lines )
            ? "\n--- LỊCH SỬ HỘI THOẠI ---\n" . implode( "\n", $conv_lines ) . "\n--- HẾT ---\n"
            : '';

        // Source names — so LLM knows what's already available vs what's missing.
        $sources = new BCN_Sources();
        $all_sources = $sources->get_by_project( $project_id );
        $source_names = array_map( fn( $s ) => $s->title, array_slice( $all_sources, 0, 15 ) );
        $source_block = ! empty( $source_names )
            ? "\n--- TÀI LIỆU HIỆN CÓ ---\n" . implode( "\n", $source_names ) . "\n--- HẾT ---\n"
            : '';

        // ── 2. Build LLM prompt ──
        $system = "Bạn là trợ lý nghiên cứu BizCity Notebook.\n\n"
            . "TÌNH HUỐNG: Người dùng muốn tìm thêm nguồn tài liệu cho project \"{$topic}\".\n"
            . "Yêu cầu gốc: \"{$original_query}\"\n"
            . $conversation_block
            . $source_block
            . "\nNHIỆM VỤ — trả lời theo cấu trúc:\n\n"
            . "1. XÁC NHẬN ngắn gọn (1-2 câu): Cho biết bạn đang mở hộp tìm kiếm.\n\n"
            . "2. TÓM TẮT (nếu có hội thoại): 1-2 câu tóm tắt nội dung nghiên cứu.\n\n"
            . "3. PHÂN TÍCH KHOẢNG TRỐNG: So sánh nội dung hội thoại/câu hỏi vs tài liệu hiện có → chỉ ra 3-5 từ khóa/chủ đề CÒN THIẾU:\n"
            . "   **Từ khóa cần tìm thêm:** `keyword1`, `keyword2`, `keyword3`\n\n"
            . "4. KẾT THÚC bằng block gợi ý (format BẮT BUỘC):\n"
            . "---\n💡 **Gợi ý tiếp theo:**\n"
            . "- 🔍 [search query cụ thể nhất dựa trên ngữ cảnh hội thoại + khoảng trống kiến thức]\n"
            . "- 🔍 [search query bổ sung từ góc nhìn khác]\n"
            . "- [câu hỏi follow-up hữu ích]\n\n"
            . "QUY TẮC:\n"
            . "- Gợi ý 🔍 PHẢI là cụm từ tìm kiếm (search query) cụ thể, KHÔNG phải câu hỏi\n"
            . "- Ví dụ ĐÚNG: '🔍 multi-agent orchestration LangGraph tutorial' / '🔍 RAG chunking strategies comparison'\n"
            . "- Từ khóa PHẢI phản ánh những gì TÀI LIỆU CHƯA CÓ (khoảng trống)\n"
            . "- Trả lời bằng tiếng Việt, ngắn gọn\n";

        $llm_messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $original_query ],
        ];

        // ── 3. Stream LLM response ──
        if ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $full_response = '';
            bizcity_openrouter_chat_stream( $llm_messages, [ 'purpose' => 'chat' ], function( $delta ) use ( &$full_response ) {
                $full_response .= $delta;
                echo "event: chunk\ndata: " . wp_json_encode( [ 'delta' => $delta, 'full' => $full_response ] ) . "\n\n";
                if ( ob_get_level() ) ob_flush();
                flush();
            } );

            // Persist bot message.
            $bot_msg_id = $messages_handler->create( [
                'project_id'   => $project_id,
                'session_id'   => $session_id,
                'user_id'      => $user_id,
                'message_text' => $full_response,
                'message_from' => 'bot',
            ] );

            // Extract context-aware search query from LLM suggestions.
            $suggestions  = self::extract_suggestions( $full_response );
            $search_query = self::extract_deep_research_query( $suggestions, $clean_query ?: $original_query );

            // Note: no auto_pin here — this is a search-redirect path (no substantive answer).

            echo "event: done\ndata: " . wp_json_encode( [
                'message'              => [ 'id' => $bot_msg_id, 'role' => 'assistant', 'content' => $full_response ],
                'userMessage'          => [ 'id' => $user_msg_id ],
                'suggestions'          => $suggestions,
                'open_deep_research'   => true,
                'deep_research_query'  => $search_query,
                'deep_research_auto'   => $auto_start,
            ] ) . "\n\n";
        } else {
            // Fallback: static response.
            $label = $auto_start
                ? "Mình đang tìm nguồn về **{$topic}** cho bạn..."
                : "Mở hộp tìm kiếm nguồn về **{$topic}** — nhấn nút tìm để bắt đầu!";
            echo "event: chunk\ndata: " . wp_json_encode( [ 'delta' => $label, 'full' => $label ] ) . "\n\n";

            $bot_msg_id = $messages_handler->create( [
                'project_id'   => $project_id,
                'session_id'   => $session_id,
                'user_id'      => $user_id,
                'message_text' => $label,
                'message_from' => 'bot',
            ] );

            echo "event: done\ndata: " . wp_json_encode( [
                'message'              => [ 'id' => $bot_msg_id, 'role' => 'assistant', 'content' => $label ],
                'userMessage'          => [ 'id' => $user_msg_id ],
                'suggestions'          => [ "🔍 {$topic}" ],
                'open_deep_research'   => true,
                'deep_research_query'  => $topic,
                'deep_research_auto'   => $auto_start,
            ] ) . "\n\n";
        }

        if ( ob_get_level() ) ob_flush();
        flush();
        exit;
    }

    // ── Fallback SSE ──

    private function fallback_sse( $project_id, $project, $message, $session_id, $user_id, $user_msg_id ) {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );

        // ── TWIN CONTEXT RESOLVER: single-call delegation ──
        if ( defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) && BIZCITY_TWIN_RESOLVER_ENABLED
             && class_exists( 'BizCity_Twin_Context_Resolver' ) ) {
            $system_prompt = BizCity_Twin_Context_Resolver::build_system_prompt( 'notebook', [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'platform_type' => 'NOTEBOOK',
                'via'           => 'notebook_fallback',
                'project_id'    => $project_id,
            ] );

            // Notebook data context (sources, notes) — not yet in Resolver
            $data_context = self::build_notebook_data_context( $project_id, $project );
            if ( ! empty( $data_context ) ) {
                $system_prompt .= $data_context;
            }

            // Notebook follow-up format
            $system_prompt .= "\n\nCuối mỗi câu trả lời, gợi ý 2-3 hành động tiếp theo:\n"
                . "---\n💡 **Gợi ý tiếp theo:**\n"
                . "- 🔍 Tìm thêm nguồn về [chủ đề] (nếu tài liệu chưa đủ)\n"
                . "- Phân tích / So sánh / Mở rộng [khía cạnh cụ thể]\n"
                . "- Tổng hợp / Giải thích [chủ đề liên quan]\n\n"
                . "Gợi ý bắt đầu bằng động từ hành động, KHÔNG viết dạng AI hỏi lại user.\n";
        } else {
            // ── LEGACY FALLBACK — definition blocks consolidated in Twin Context Resolver ──
            $system_prompt = "Bạn là trợ lý nghiên cứu chuyên sâu. Hỗ trợ Chủ Nhân trong Notebook nghiên cứu.\n";
            $system_prompt .= "Trả lời bằng tiếng Việt, chi tiết, sâu sắc, có cấu trúc.\n\n";

            $data_context = self::build_notebook_data_context( $project_id, $project );
            if ( ! empty( $data_context ) ) {
                $system_prompt .= $data_context;
            }

            $system_prompt .= "\n\nCuối mỗi câu trả lời, gợi ý 2-3 hành động tiếp theo:\n"
                . "---\n💡 **Gợi ý tiếp theo:**\n"
                . "- 🔍 Tìm thêm nguồn về [chủ đề] (nếu tài liệu chưa đủ)\n"
                . "- Phân tích / So sánh / Mở rộng [khía cạnh cụ thể]\n"
                . "- Tổng hợp / Giải thích [chủ đề liên quan]\n\n"
                . "Gợi ý bắt đầu bằng động từ hành động, KHÔNG viết dạng AI hỏi lại user.\n";
        }

        // Get recent messages for conversation context.
        $messages_handler = new BCN_Messages();
        $recent = $messages_handler->get_by_project( $project_id, [ 'limit' => 20 ] );

        $llm_messages = [ [ 'role' => 'system', 'content' => $system_prompt ] ];
        foreach ( $recent as $msg ) {
            $llm_messages[] = [ 'role' => $msg->role, 'content' => $msg->content ];
        }
        $llm_messages[] = [ 'role' => 'user', 'content' => $message ];

        if ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $full_response = '';
            bizcity_openrouter_chat_stream( $llm_messages, [ 'purpose' => 'chat' ], function( $delta ) use ( &$full_response ) {
                $full_response .= $delta;
                echo "event: chunk\ndata: " . wp_json_encode( [ 'delta' => $delta, 'full' => $full_response ] ) . "\n\n";
                if ( ob_get_level() ) ob_flush();
                flush();
            } );

            // Save bot message.
            $bot_msg_id = $messages_handler->create( [
                'project_id'   => $project_id,
                'session_id'   => $session_id,
                'user_id'      => $user_id,
                'message_text' => $full_response,
                'message_from' => 'bot',
            ] );

            // Extract follow-up suggestions from response.
            $suggestions = self::extract_suggestions( $full_response );

            // Auto-save substantive response content as auto_pinned note.
            self::auto_pin_response( $project_id, $session_id, $user_id, $full_response, $message );

            echo "event: done\ndata: " . wp_json_encode( [
                'message'     => [ 'id' => $bot_msg_id, 'role' => 'assistant', 'content' => $full_response ],
                'userMessage' => [ 'id' => $user_msg_id ],
                'suggestions' => $suggestions,
            ] ) . "\n\n";
        } else {
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'No LLM provider available' ] ) . "\n\n";
        }

        if ( ob_get_level() ) ob_flush();
        flush();
        exit;
    }

    // ── Context Builders ──

    /**
     * Build ONLY the notebook data sections (sources, notes, journey, events).
     * Used by inject_source_context (pri 15) to append data without a competing persona.
     */
    public static function build_notebook_data_context( string $project_id, ?object $project = null ): string {
        $source_context = self::build_source_context( $project_id );
        $notes_context  = self::build_notes_context( $project_id );
        $journey_state  = self::build_journey_state( $project_id, $project );
        $recent_events  = self::build_recent_events( $project_id );

        $parts = [];
        if ( $source_context ) {
            $parts[] = "--- NGUỒN TÀI LIỆU (Notebook) ---\n{$source_context}\n--- HẾT NGUỒN ---";
        }
        if ( $notes_context ) {
            $parts[] = "--- GHI CHÚ ĐÃ LƯU ---\n{$notes_context}\n--- HẾT GHI CHÚ ---";
        }
        if ( $journey_state ) {
            $parts[] = "--- TRẠNG THÁI CÔNG VIỆC ---\n{$journey_state}\n--- HẾT ---";
        }
        if ( $recent_events ) {
            $parts[] = "--- HOẠT ĐỘNG GẦN ĐÂY ---\n{$recent_events}\n--- HẾT ---";
        }

        if ( empty( $parts ) ) return '';

        return "\n\n## 📓 NOTEBOOK RESEARCH CONTEXT:\n"
             . "Chủ Nhân đang làm việc trong Notebook nghiên cứu — dưới đây là tài liệu và ghi chú.\n"
             . "Hãy ưu tiên trả lời dựa trên tài liệu này. Nếu không tìm thấy → thừa nhận ngắn gọn.\n\n"
             . implode( "\n\n", $parts );
    }

    public static function build_source_context( $project_id, $max_tokens = 30000 ) {
        $sources = new BCN_Sources();
        return $sources->get_all_content( $project_id, $max_tokens * 4 );
    }

    private static function build_notes_context( $project_id, $max_tokens = 5000 ) {
        $notes = new BCN_Notes();
        $all   = $notes->get_by_project( $project_id );

        $parts      = [];
        $total_chars = 0;
        $max_chars   = $max_tokens * 4;

        foreach ( $all as $note ) {
            $text = "[Ghi chú: {$note->title}]\n{$note->content}\n";
            if ( $total_chars + mb_strlen( $text ) > $max_chars ) break;
            $parts[]     = $text;
            $total_chars += mb_strlen( $text );
        }

        return implode( "\n---\n", $parts );
    }

    /**
     * Build journey state — what user is working on, project context.
     */
    private static function build_journey_state( string $project_id, ?object $project = null ): string {
        if ( ! $project ) {
            $project = ( new BCN_Projects() )->get( $project_id );
        }
        if ( ! $project ) return '';

        $sources = new BCN_Sources();
        $all_sources = $sources->get_by_project( $project_id );
        $source_count = count( $all_sources );
        $embedded_count = 0;
        $source_names = [];
        foreach ( $all_sources as $s ) {
            $source_names[] = $s->title;
            if ( ( $s->embedding_status ?? '' ) === 'done' ) $embedded_count++;
        }

        $notes = new BCN_Notes();
        $note_count = count( $notes->get_by_project( $project_id ) );

        $state = [
            'project'      => $project->title ?? 'Untitled',
            'source_count' => $source_count,
            'embedded'     => $embedded_count,
            'note_count'   => $note_count,
            'sources'      => array_slice( $source_names, 0, 10 ),
        ];

        return wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
    }

    /**
     * Build recent events timeline from chat history.
     */
    private static function build_recent_events( string $project_id ): string {
        $messages = new BCN_Messages();
        $recent = $messages->get_by_project( $project_id, [ 'limit' => 10 ] );

        if ( empty( $recent ) ) return '';

        $events = [];
        foreach ( $recent as $msg ) {
            $time = wp_date( 'H:i', strtotime( $msg->created_at ) );
            $who  = $msg->role === 'user' ? 'Người dùng' : 'AI';
            $preview = mb_substr( wp_strip_all_tags( $msg->content ), 0, 60 );
            $events[] = "- {$time} {$who}: {$preview}";
        }

        return implode( "\n", $events );
    }

    // ── Follow-up Suggestions ──

    /**
     * Extract follow-up suggestions from AI response text.
     * Looks for "Gợi ý tiếp theo:" block at end of response.
     */
    public static function extract_suggestions( string $response ): array {
        $suggestions = [];
        // Match bullet items after "Gợi ý" heading. Supports -, •, * bullets.
        if ( preg_match( '/💡\s*\*?\*?Gợi ý.*?\*?\*?:?\s*\n((?:\s*[-•*]\s*.+\n?)+)/u', $response, $m ) ) {
            preg_match_all( '/[-•*]\s*(.+)/u', $m[1], $items );
            if ( ! empty( $items[1] ) ) {
                foreach ( $items[1] as $item ) {
                    $clean = trim( wp_strip_all_tags( $item ) );
                    if ( $clean ) $suggestions[] = $clean;
                }
            }
        }
        return array_slice( $suggestions, 0, 5 );
    }

    // ── Intent Engine Hooks ──

    /**
     * Inject Notebook source/notes context into Intent Stream's system prompt.
     * Hooked on bizcity_chat_system_prompt at priority 15.
     *
     * Only injects DATA (sources, notes, journey) — NOT a competing persona.
     * The AI persona/role/identity comes from Chat Gateway (Trợ lý Team Leader).
     */
    public function inject_source_context( $prompt, $args ) {
        if ( ( $args['platform_type'] ?? '' ) !== 'NOTEBOOK' ) return $prompt;

        $project_id = sanitize_text_field( $_REQUEST['notebook_project_id'] ?? '' );
        if ( ! $project_id ) return $prompt;

        $data_context = self::build_notebook_data_context( $project_id );

        // Inject Research Memory context (keyword-matched from previous sessions).
        $research_context = '';
        if ( class_exists( 'BCN_Research_Memory' ) ) {
            $user_message = sanitize_text_field( $_REQUEST['message'] ?? $_POST['message'] ?? '' );
            $research_context = BCN_Research_Memory::instance()->build_research_context( $project_id, $user_message );
        }

        if ( empty( $data_context ) && empty( $research_context ) ) return $prompt;

        // Notebook-specific instructions for handling missing info + follow-up format.
        $suggestion_rules = "\n\n## 📓 NOTEBOOK — QUY TẮC TRẢ LỜI:\n"
            . "KHI TÀI LIỆU KHÔNG ĐỀ CẬP ĐẾN CHỦ ĐỀ:\n"
            . "- KHÔNG từ chối, KHÔNG nói 'tôi không có thông tin', KHÔNG trả lời ngắn gọn rồi dừng\n"
            . "- Trả lời ĐẦY ĐỦ, CHI TIẾT dựa trên kiến thức của bạn — ghi rõ '📚 Từ kiến thức chung:' ở đầu phần này\n"
            . "- Sau khi trả lời xong mới gợi ý 🔍 để tìm nguồn xác minh\n"
            . "- Mục tiêu: user LUÔN nhận được câu trả lời hữu ích\n\n"
            . "FORMAT GỢI Ý (BẮT BUỘC cuối mỗi câu trả lời):\n"
            . "---\n💡 **Gợi ý tiếp theo:**\n"
            . "- 🔍 Tìm thêm nguồn về [chủ đề user đang hỏi]\n"
            . "- Phân tích sâu hơn về [khía cạnh cụ thể]\n"
            . "- So sánh / Tổng hợp [chủ đề liên quan]\n\n"
            . "Gợi ý bắt đầu bằng ĐỘNG TỪ hành động (Phân tích, So sánh, Mở rộng, Giải thích, Tổng hợp, Liệt kê...).\n"
            . "KHÔNG viết dạng 'Bạn muốn...' / 'Mình có thể...' — hãy viết như một lệnh hành động.\n";

        return $prompt . $data_context . $research_context . $suggestion_rules;
    }

    // ── Auto-pin Response Content ──

    /**
     * Auto-save key content from a substantive AI response as an auto_pinned note.
     * Called after the AI provides a real answer (Intent Engine or fallback path).
     * NOT called from search/no-source redirect paths.
     *
     * Saves condensed answer body + action-oriented follow-up directions.
     * Skips: short replies, pure search-redirect responses (only 🔍 pills), no useful content.
     *
     * @param string $project_id
     * @param string $session_id
     * @param int    $user_id
     * @param string $bot_reply   Full bot response text.
     * @param string $user_query  Original user message (used for title/tags).
     */
    private static function auto_pin_response( $project_id, $session_id, $user_id, string $bot_reply, string $user_query ) {
        if ( ! $project_id || ! $user_id ) return;

        // Skip very short replies — not substantive.
        if ( mb_strlen( wp_strip_all_tags( $bot_reply ) ) < 200 ) return;

        // Extract suggestions, filter out 🔍 search pills.
        $all_suggestions = self::extract_suggestions( $bot_reply );
        $action_pills    = array_values( array_filter( $all_suggestions, fn( $s ) => mb_strpos( $s, '🔍' ) !== 0 ) );

        // Skip if no action-oriented suggestions — pure search-redirect response, nothing to pin.
        if ( empty( $action_pills ) ) return;

        // Strip the "💡 Gợi ý" block to get the clean answer body.
        $answer_body = preg_replace( '/---\s*\n💡.*$/us', '', $bot_reply );
        $answer_body = trim( wp_strip_all_tags( $answer_body ) );
        if ( mb_strlen( $answer_body ) < 100 ) return;

        // Build note: condensed answer + action directions.
        $content  = mb_substr( $answer_body, 0, 800 );
        $content .= "\n\n💡 Hướng nghiên cứu:\n" . implode( "\n", array_map( fn( $s ) => "• {$s}", $action_pills ) );

        $title = 'Ghi chú: ' . mb_substr( wp_strip_all_tags( $user_query ), 0, 60 );

        // Extract keyword tags from user query.
        $words = array_filter( preg_split( '/\s+/', mb_strtolower( $user_query ) ) );
        $stop  = [ 'là', 'và', 'của', 'có', 'cho', 'này', 'với', 'được', 'trong', 'không', 'từ', 'một', 'các', 'những', 'về', 'như', 'gì', 'nào', 'hãy', 'the', 'is', 'a', 'an', 'of', 'to', 'in' ];
        $tags  = array_values( array_slice( array_filter( $words, fn( $w ) => mb_strlen( $w ) > 1 && ! in_array( $w, $stop, true ) ), 0, 5 ) );

        $notes = new BCN_Notes();
        $notes->create_system( [
            'user_id'    => $user_id,
            'project_id' => $project_id,
            'session_id' => $session_id,
            'title'      => $title,
            'content'    => $content,
            'tags'       => $tags,
            'note_type'  => 'auto_pinned',
            'metadata'   => [ 'source' => 'chat_response', 'query' => mb_substr( $user_query, 0, 200 ) ],
        ] );
    }

    // ── Intent Engine After-Response Hook ──

    /**
     * After Intent Stream completes, update the bot message with project_id
     * if Intent Stream already logged it. No duplicate insert needed since
     * Intent Stream now passes project_id through to log_webchat_message().
     */
    public function on_response_complete( $user_message, $bot_reply, $context ) {
        $platform = $context['platform'] ?? '';
        if ( $platform !== 'NOTEBOOK' ) return;

        $project_id = sanitize_text_field( $_REQUEST['notebook_project_id'] ?? $_REQUEST['project_id'] ?? '' );
        $session_id = $context['session_id'] ?? '';
        $user_id    = (int) ( $context['user_id'] ?? 0 );

        if ( ! $project_id || ! $session_id || ! $user_id ) return;

        // Auto-save substantive response content as auto_pinned note (Intent Engine path).
        if ( is_string( $bot_reply ) && is_string( $user_message ) ) {
            self::auto_pin_response( $project_id, $session_id, $user_id, $bot_reply, $user_message );
        }

        // Trigger Research Memory LLM summary every N turns.
        if ( class_exists( 'BCN_Research_Memory' ) ) {
            BCN_Research_Memory::instance()->maybe_summarize( $project_id, $session_id, $user_id );
        }
    }
}
