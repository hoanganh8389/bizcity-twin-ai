<?php
/**
 * Research Agent — PHP ReAct loop port of Tavily Chat (app.py + agent.py).
 *
 * Streams NDJSON events (1 JSON object per \n line):
 *   {type:"research_phase", phase:"planning|searching|generating", status, label, duration_ms?}
 *   {type:"tool_start",  tool_name, tool_type, operation_index, content:{params}}
 *   {type:"tool_end",    tool_name, tool_type, operation_index, content:"<json_string>", duration_ms}
 *   {type:"chatbot",     content:"<single char>"}
 *   {type:"research_done", source_count}
 *   {type:"error",       message}
 *
 * NOT a function-calling agent — uses the Thought/Action/Action Input/Observation
 * ReAct text protocol (matches Tavily's create_react_agent behavior). The LLM
 * outputs ReAct text, we parse Action+Action Input, dispatch tools, append
 * observation back into messages, loop. Final Answer is filtered & streamed.
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Agent {

    private array $session;
    private int   $turn_id;
    private string $user_query;
    private string $mode;          // 'fast' | 'deep'
    private string $trace_id;
    private float  $start_t;
    private array  $emitted_phases = [];

    public function __construct( array $session, int $turn_id, string $user_query, string $mode, string $trace_id = '' ) {
        $this->session    = $session;
        $this->turn_id    = $turn_id;
        $this->user_query = trim( $user_query );
        $this->mode       = in_array( $mode, [ 'fast', 'deep' ], true ) ? $mode : 'deep';
        $this->trace_id   = $trace_id ?: wp_generate_uuid4();
        $this->start_t    = microtime( true );
    }

    public function run(): void {
        $max_tools = $this->mode === 'deep' ? 5 : 2;

        $system_prompt = $this->mode === 'deep'
            ? BizCity_Research_Prompts::get_reasoning( $this->session )
            : BizCity_Research_Prompts::get_simple( $this->session );

        $messages = [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user',   'content' => "Question: {$this->user_query}" ],
        ];

        $counter         = 0;
        $all_source_urls = [];
        $trace           = [];
        $final_answer    = '';

        BizCity_Research_Event_Emitter::emit( 'research_turn_started', [
            'session_id' => $this->session['id'],
            'turn_id'    => $this->turn_id,
            'mode'       => $this->mode,
            'trace_id'   => $this->trace_id,
        ] );

        error_log( sprintf( '[TwinSearch Agent] run() START — turn=%d mode=%s query="%s"', $this->turn_id, $this->mode, mb_substr( $this->user_query, 0, 80 ) ) );
        $this->emit_phase( 'planning', 'active', __( 'Planning', 'bizcity-twin-ai' ) );

        for ( $i = 0; $i < $max_tools + 1; $i++ ) {
            error_log( sprintf( '[TwinSearch Agent] LLM call #%d — turn=%d msgs=%d', $i, $this->turn_id, count( $messages ) ) );
            $resp = $this->call_llm( $messages );
            if ( empty( $resp['success'] ) ) {
                error_log( sprintf( '[TwinSearch Agent] LLM FAILED #%d — turn=%d error=%s', $i, $this->turn_id, $resp['error'] ?? '?' ) );
                $this->emit( [ 'type' => 'error', 'payload' => [ 'message' => $resp['error'] ?? 'LLM call failed' ] ] );
                $final_answer = __( 'Xin lỗi, đã có lỗi khi gọi mô hình AI: ', 'bizcity-twin-ai' ) . ( $resp['error'] ?? '' );
                break;
            }
            error_log( sprintf( '[TwinSearch Agent] LLM OK #%d — turn=%d text_len=%d text_preview=%.300s', $i, $this->turn_id, mb_strlen( (string) $resp['message'] ), (string) $resp['message'] ) );
            $llm_text = (string) $resp['message'];

            // Parse ReAct text. If LLM provided "Final Answer:" — we're done.
            $parsed = self::parse_react( $llm_text );

            if ( $parsed['final_answer'] !== null ) {
                // Reject early final answer when no tools have been called yet.
                // The LLM sometimes hallucinates citations without running Tavily.
                if ( $counter === 0 ) {
                    error_log( sprintf( '[TwinSearch Agent] Early Final Answer on LLM#%d (counter=0) — running forced TavilySearch turn=%d', $i, $this->turn_id ) );

                    // Open searching phase
                    $this->emit_phase( 'planning',  'complete' );
                    $this->emit_phase( 'searching', 'active', __( 'Searching', 'bizcity-twin-ai' ) );

                    $step_t = microtime( true );
                    $this->emit( [
                        'type'    => 'tool_start',
                        'payload' => [ 'index' => 0, 'tool_type' => 'search', 'query' => $this->user_query, 'url' => '' ],
                    ] );

                    $raw_tool = BizCity_Research_Tool_Router::call( 'TavilySearch', [ 'query' => $this->user_query ] );
                    $result   = self::shape_search_result( $raw_tool );
                    $step_ms  = (int) round( ( microtime( true ) - $step_t ) * 1000 );

                    // Collect source URLs from the real search
                    foreach ( ( $result['results'] ?? [] ) as $item ) {
                        if ( ! empty( $item['url'] ) ) {
                            $all_source_urls[ $item['url'] ] = [
                                'url'             => (string) $item['url'],
                                'title'           => (string) ( $item['title'] ?? $item['url'] ),
                                'favicon'         => (string) ( $item['favicon'] ?? '' ),
                                'origin'          => 'search',
                                'operation_index' => 0,
                            ];
                        }
                    }

                    $this->emit( [
                        'type'    => 'tool_end',
                        'payload' => [ 'index' => 0, 'tool_type' => 'search', 'results' => $result['results'] ?? [], 'duration_ms' => $step_ms ],
                    ] );

                    $counter++;

                    // Feed real observation + ask for Final Answer using the real data
                    $observation = self::build_observation_text( $result );
                    $messages[]  = [ 'role' => 'assistant', 'content' => $llm_text ];
                    $messages[]  = [
                        'role'    => 'user',
                        'content' => "Observation: {$observation}\n(Bạn còn " . ( $max_tools - $counter ) . " tool call. Bây giờ hãy viết 'Final Answer:' kèm câu trả lời markdown đầy đủ bằng tiếng Việt, trích dẫn các nguồn từ kết quả tìm kiếm.)",
                    ];
                    continue;  // LLM will now reply with real data
                }
                $final_answer = $parsed['final_answer'];
                error_log( sprintf( '[TwinSearch Agent] final_answer found in LLM #%d — turn=%d len=%d', $i, $this->turn_id, mb_strlen( $final_answer ) ) );
                break;
            }

            if ( $parsed['action'] === null || $parsed['action_input'] === null ) {
                // LLM returned something unparseable (plain answer or garbled ReAct).
                // Use the raw text as the answer — strip internals will clean it.
                $final_answer = $llm_text;
                error_log( sprintf( '[TwinSearch Agent] no action parsed — turn=%d using llm_text as answer (len=%d)', $this->turn_id, mb_strlen( $llm_text ) ) );
                break;
            }

            // First tool → close planning, open searching
            if ( $i === 0 ) {
                $this->emit_phase( 'planning',  'complete' );
                $this->emit_phase( 'searching', 'active', __( 'Searching', 'bizcity-twin-ai' ) );
            }

            $tool_name  = $parsed['action'];
            $tool_input = $parsed['action_input'];
            $tool_type  = BizCity_Research_Tool_Router::get_type( $tool_name );
            $step_t     = microtime( true );

            $this->emit( [
                'type'    => 'tool_start',
                'payload' => [
                    'index'     => $counter,
                    'tool_type' => $tool_type,
                    'query'     => is_string( $tool_input ) ? $tool_input : ( $tool_input['query'] ?? $tool_input['url'] ?? '' ),
                    'url'       => is_array( $tool_input ) ? ( $tool_input['url'] ?? ( isset( $tool_input['urls'][0] ) ? $tool_input['urls'][0] : '' ) ) : '',
                ],
            ] );
            BizCity_Research_Event_Emitter::emit( 'research_tool_start', [
                'session_id'      => $this->session['id'],
                'turn_id'         => $this->turn_id,
                'tool'            => $tool_name,
                'tool_type'       => $tool_type,
                'operation_index' => $counter,
            ] );

            error_log( sprintf( '[TwinSearch Agent] tool_call #%d — turn=%d tool=%s input=%s', $counter, $this->turn_id, $tool_name, json_encode( $tool_input ) ) );
            $raw = BizCity_Research_Tool_Router::call( $tool_name, is_array( $tool_input ) ? $tool_input : [ 'query' => (string) $tool_input ] );
            error_log( sprintf( '[TwinSearch Agent] tool_result #%d — turn=%d tool=%s raw_len=%d', $counter, $this->turn_id, $tool_name, strlen( (string) json_encode( $raw ) ) ) );

            // Summarize Extract+Crawl; pass Search through
            $result = ( $tool_type === 'extract' || $tool_type === 'crawl' )
                ? BizCity_Research_Summarizer::summarize( $raw, $this->user_query )
                : self::shape_search_result( $raw );

            $step_ms = (int) round( ( microtime( true ) - $step_t ) * 1000 );

            // Collect source URLs (with origin tag for the source list)
            foreach ( ( $result['items'] ?? $result['results'] ?? [] ) as $item ) {
                if ( ! empty( $item['url'] ) ) {
                    $all_source_urls[ $item['url'] ] = [
                        'url'              => (string) $item['url'],
                        'title'            => (string) ( $item['title'] ?? $item['url'] ),
                        'favicon'          => (string) ( $item['favicon'] ?? '' ),
                        'origin'           => $tool_type,
                        'operation_index'  => $counter,
                    ];
                }
            }

            $this->emit( [
                'type'    => 'tool_end',
                'payload' => [
                    'index'      => $counter,
                    'tool_type'  => $tool_type,
                    'results'    => $result['items'] ?? $result['results'] ?? [],
                    'duration_ms'=> $step_ms,
                ],
            ] );
            BizCity_Research_Event_Emitter::emit( 'research_tool_end', [
                'session_id'      => $this->session['id'],
                'turn_id'         => $this->turn_id,
                'tool'            => $tool_name,
                'tool_type'       => $tool_type,
                'operation_index' => $counter,
                'urls_count'      => count( $result['urls'] ?? [] ),
                'duration_ms'     => $step_ms,
            ] );

            $trace[] = [
                'tool'        => $tool_name,
                'tool_type'   => $tool_type,
                'input'       => $tool_input,
                'urls'        => $result['urls'] ?? [],
                'duration_ms' => $step_ms,
            ];

            // Feed observation back to the LLM
            $observation = self::build_observation_text( $result );
            $messages[]  = [ 'role' => 'assistant', 'content' => $llm_text ];
            $messages[]  = [ 'role' => 'user',      'content' => "Observation: {$observation}\n(Bạn còn " . ( $max_tools - $counter - 1 ) . " tool call. Nếu đã đủ thông tin, hãy viết 'Thought: I now know the final answer' rồi đến 'Final Answer:' kèm câu trả lời markdown đầy đủ bằng tiếng Việt.)" ];

            $counter++;
            if ( $counter >= $max_tools ) {
                // Force final answer call
                $messages[] = [ 'role' => 'user', 'content' => "Bạn đã hết tool call. Bây giờ hãy viết 'Final Answer:' kèm câu trả lời markdown đầy đủ bằng tiếng Việt, có trích dẫn nguồn." ];
            }
        }

        // Searching → done; Generating Report → active
        // If no tools were used, mark planning complete before generating
        if ( $counter === 0 ) {
            $this->emit_phase( 'planning', 'complete' );
        }
        $this->emit_phase( 'searching',  'complete' );
        $this->emit_phase( 'generating', 'active', __( 'Generating Report', 'bizcity-twin-ai' ) );

        // If we don't yet have a final answer, do one last LLM call without tools.
        if ( $final_answer === '' ) {
            error_log( sprintf( '[TwinSearch Agent] final_answer empty after loop — turn=%d triggering fallback LLM call', $this->turn_id ) );
            $messages[] = [ 'role' => 'user', 'content' => "Hãy viết 'Final Answer:' kèm câu trả lời markdown đầy đủ bằng tiếng Việt." ];
            $resp = $this->call_llm( $messages );
            if ( ! empty( $resp['success'] ) ) {
                error_log( sprintf( '[TwinSearch Agent] fallback LLM OK — turn=%d text_len=%d preview=%.300s', $this->turn_id, mb_strlen( (string) $resp['message'] ), (string) $resp['message'] ) );
                $parsed = self::parse_react( (string) $resp['message'] );
                $final_answer = $parsed['final_answer'] ?? (string) $resp['message'];
            }
        }
        $raw_final = $final_answer;
        $final_answer = self::strip_react_internals( $final_answer );
        error_log( sprintf( '[TwinSearch Agent] strip_react_internals — turn=%d before_len=%d after_len=%d', $this->turn_id, mb_strlen( $raw_final ), mb_strlen( $final_answer ) ) );
        // Recovery: if stripping emptied the answer, ask the LLM directly
        if ( $final_answer === '' && $raw_final !== '' ) {
            error_log( sprintf( '[TwinSearch Agent] strip produced empty — turn=%d doing recovery LLM call', $this->turn_id ) );
            $messages[] = [ 'role' => 'user', 'content' => "Phản hồi trước của bạn chưa đầy đủ. Hãy viết lại câu trả lời markdown hoàn chỉnh bằng tiếng Việt, bắt đầu ngay bằng nội dung chưa cần tiêu đề 'Final Answer:'." ];
            $resp = $this->call_llm( $messages );
            if ( ! empty( $resp['success'] ) ) {
                error_log( sprintf( '[TwinSearch Agent] recovery LLM OK — turn=%d text_len=%d preview=%.300s', $this->turn_id, mb_strlen( (string) $resp['message'] ), (string) $resp['message'] ) );
                $final_answer = self::strip_react_internals( (string) $resp['message'] );
                // Last resort: use raw if still empty
                if ( $final_answer === '' ) {
                    $final_answer = (string) $resp['message'];
                }
            }
        }

        // Stream report in word chunks (much more efficient than char-by-char)
        $words = preg_split( '/(?<=\s)|(?=\s)/u', $final_answer, -1, PREG_SPLIT_NO_EMPTY );
        $chunk = '';
        foreach ( $words as $word ) {
            $chunk .= $word;
            if ( mb_strlen( $chunk ) >= 40 ) {
                $this->emit( [ 'type' => 'report_chunk', 'payload' => [ 'text' => $chunk ] ] );
                $chunk = '';
            }
        }
        if ( $chunk !== '' ) {
            $this->emit( [ 'type' => 'report_chunk', 'payload' => [ 'text' => $chunk ] ] );
        }

        $this->emit_phase( 'generating', 'complete' );

        $duration_ms  = (int) round( ( microtime( true ) - $this->start_t ) * 1000 );
        $sources_list = array_values( $all_source_urls );

        BizCity_Research_Store::finalize_turn( $this->turn_id, [
            'status'           => 'done',
            'agent_answer_md'  => $final_answer,
            'reasoning_trace'  => $trace,
            'source_urls'      => $sources_list,
            'tool_calls_count' => $counter,
            'duration_ms'      => $duration_ms,
        ] );

        $this->emit( [
            'type'    => 'turn_done',
            'payload' => [
                'turn_id'      => $this->turn_id,
                'source_count' => count( $sources_list ),
                'duration_ms'  => $duration_ms,
            ],
        ] );
        BizCity_Research_Event_Emitter::emit( 'research_turn_completed', [
            'session_id'      => $this->session['id'],
            'turn_id'         => $this->turn_id,
            'duration_ms'     => $duration_ms,
            'tool_calls_count'=> $counter,
            'source_count'    => count( $sources_list ),
        ] );
    }

    /* ────────────── Internal: LLM ────────────── */

    private function call_llm( array $messages ): array {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return [ 'success' => false, 'error' => 'BizCity_LLM_Client not loaded — gateway client missing.' ];
        }
        $client = BizCity_LLM_Client::instance();
        return $client->chat( $messages, [
            'purpose'     => $this->mode === 'deep' ? 'reasoning' : 'chat',
            'temperature' => $this->mode === 'deep' ? 0.4 : 0.6,
            'max_tokens'  => 4000,
        ] );
    }

    /* ────────────── Internal: emit helpers ────────────── */

    private function emit( array $data ): void {
        $json = wp_json_encode( $data );
        error_log( sprintf( '[TwinSearch Agent] emit turn=%d → %s', $this->turn_id, mb_substr( $json, 0, 200 ) ) );
        echo $json . "\n";
        if ( function_exists( 'ob_get_level' ) ) {
            while ( ob_get_level() > 0 ) @ob_end_flush();
        }
        @flush();
    }

    private function emit_phase( string $phase, string $status, string $label = '' ): void {
        // Avoid emitting a duplicate completion if status already past
        $key = $phase . ':' . $status;
        if ( isset( $this->emitted_phases[ $key ] ) ) return;
        $this->emitted_phases[ $key ] = true;

        $payload = [
            'phase'  => $phase,
            'status' => $status,
        ];
        if ( $label !== '' ) $payload['label'] = $label;
        if ( $status === 'complete' ) {
            $payload['duration_ms'] = (int) round( ( microtime( true ) - $this->start_t ) * 1000 );
        }
        $this->emit( [ 'type' => 'phase', 'payload' => $payload ] );
    }

    /* ────────────── Internal: ReAct parsing ────────────── */

    /**
     * Parse a ReAct LLM output into action / action_input / final_answer.
     */
    public static function parse_react( string $text ): array {
        $out = [ 'action' => null, 'action_input' => null, 'final_answer' => null ];

        // Final Answer wins outright.
        if ( preg_match( '/Final Answer:\s*(.+)$/su', $text, $m ) ) {
            $out['final_answer'] = trim( $m[1] );
            return $out;
        }

        // Action: <name>
        if ( preg_match( '/Action:\s*([A-Za-z0-9_]+)/u', $text, $am ) ) {
            $out['action'] = trim( $am[1] );
        }

        // Action Input: ... (until a blank line / Observation / Thought / EOF)
        if ( preg_match( '/Action Input:\s*(.+?)(?=\n\s*(?:Observation:|Thought:|Action:|Final Answer:|$))/su', $text, $im ) ) {
            $raw = trim( $im[1] );
            // Try JSON first
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                $out['action_input'] = $json;
            } else {
                // Strip wrapping quotes
                $stripped = trim( $raw, " \"'" );
                // For TavilyExtract: comma-separated URLs → array
                if ( $out['action'] && stripos( $out['action'], 'extract' ) !== false && strpos( $stripped, 'http' ) !== false ) {
                    $urls = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $stripped ) ), function( $u ) {
                        return filter_var( $u, FILTER_VALIDATE_URL );
                    } );
                    $out['action_input'] = [ 'urls' => array_values( $urls ) ];
                } elseif ( $out['action'] && stripos( $out['action'], 'crawl' ) !== false ) {
                    $out['action_input'] = [ 'url' => $stripped ];
                } else {
                    $out['action_input'] = [ 'query' => $stripped ];
                }
            }
        }

        return $out;
    }

    /**
     * Filter out Thought/Action/Observation lines from any leftover text.
     * Also strips raw Tavily JSON arrays ([{"url":...}]) and outer
     * ```markdown ... ``` fences the LLM may wrap the report in.
     */
    public static function strip_react_internals( string $text ): string {
        // Strip embedded JSON arrays that look like Tavily search results.
        // The LLM sometimes includes the observation JSON in its output.
        $text = preg_replace( '/\[\s*\{\s*"url"\s*:.+?\}\s*\]/su', '', $text ) ?? $text;

        $lines = preg_split( '/\r?\n/', $text );
        $skip  = [ 'Thought:', 'Action:', 'Action Input:', 'Observation:', 'Question:', 'Final Answer:' ];
        $out   = [];
        foreach ( $lines as $ln ) {
            $t = ltrim( $ln );
            $hit = false;
            foreach ( $skip as $p ) {
                if ( stripos( $t, $p ) === 0 ) { $hit = true; break; }
            }
            if ( ! $hit ) $out[] = $ln;
        }
        $clean = trim( implode( "\n", $out ) );

        // Strip an outer ```markdown / ``` fence if the LLM wrapped its whole answer.
        // Without this ReactMarkdown would render the entire report as a code block,
        // showing literal **bold** and [link](url) syntax instead of formatted HTML.
        if ( preg_match( '/^```(?:markdown|md)?\s*\n(.+?)\n```\s*$/su', $clean, $m ) ) {
            $clean = trim( $m[1] );
        }
        return $clean;
    }

    /* ────────────── Internal: result shaping ────────────── */

    private static function shape_search_result( array $raw ): array {
        if ( empty( $raw['success'] ) ) {
            return [ 'results' => [], 'urls' => [], 'favicons' => [], 'error' => $raw['error'] ?? 'Search failed' ];
        }
        $items = [];
        $urls  = [];
        $favs  = [];
        foreach ( ( $raw['results'] ?? [] ) as $r ) {
            $items[] = [
                'url'            => (string) ( $r['url']     ?? '' ),
                'title'          => (string) ( $r['title']   ?? '' ),
                'content'        => (string) ( $r['content'] ?? '' ),
                'score'          => (float)  ( $r['score']   ?? 0 ),
                'published_date' => (string) ( $r['published_date'] ?? '' ),
                'favicon'        => (string) ( $r['favicon'] ?? '' ),
            ];
            if ( ! empty( $r['url'] ) )     $urls[] = $r['url'];
            if ( ! empty( $r['favicon'] ) ) $favs[] = $r['favicon'];
        }
        return [
            'results'  => $items,
            'urls'     => $urls,
            'favicons' => $favs,
            'answer'   => (string) ( $raw['answer'] ?? '' ),
        ];
    }

    private static function build_observation_text( array $result ): string {
        if ( ! empty( $result['summary'] ) ) {
            return (string) $result['summary'];
        }
        if ( ! empty( $result['answer'] ) ) {
            $obs = "Câu trả lời ngắn: " . $result['answer'] . "\n\n";
            $obs .= "Các nguồn:\n";
            foreach ( ( $result['results'] ?? [] ) as $i => $r ) {
                $obs .= ($i+1) . ". [" . ( $r['title'] ?? '' ) . "](" . $r['url'] . ") — " . mb_substr( $r['content'] ?? '', 0, 280 ) . "\n";
            }
            return $obs;
        }
        if ( ! empty( $result['results'] ) ) {
            $obs = "Tìm thấy " . count( $result['results'] ) . " kết quả:\n";
            foreach ( $result['results'] as $i => $r ) {
                $obs .= ($i+1) . ". [" . ( $r['title'] ?? '' ) . "](" . $r['url'] . ") — " . mb_substr( $r['content'] ?? '', 0, 280 ) . "\n";
            }
            return $obs;
        }
        if ( ! empty( $result['error'] ) ) return 'Tool error: ' . $result['error'];
        return 'Không có dữ liệu trả về.';
    }
}
