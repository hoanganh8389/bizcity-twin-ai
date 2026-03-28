<?php
/**
 * BizCity Smart Gateway — Client-Side Proxy to Server Intelligence.
 *
 * Collects context from local DB classes, sends to bizcity-llm-router
 * server for AI processing (Intent Engine + Twin Core + LLM), receives
 * response + mutations, and applies mutations back locally.
 *
 * Feature-flagged via BIZCITY_SMART_GATEWAY_ENABLED (default: false).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\LLM
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Smart_Gateway {

    /**
     * Collect all context data from local DB classes into a flat array
     * matching the server's expected $context structure.
     *
     * @param array $params {
     *   message:        string  User message
     *   user_id:        int     WP user ID
     *   session_id:     string  Session ID
     *   character_id:   int     Character ID
     *   platform_type:  string  ADMINCHAT|WEBCHAT|NOTEBOOK|...
     *   images:         array   Image URLs
     *   kci_ratio:      int     0-100
     *   mention_override: bool
     * }
     * @return array Context bundle for server
     */
    public function collect_context( array $params ): array {
        $user_id       = (int) ( $params['user_id'] ?? get_current_user_id() );
        $session_id    = $params['session_id'] ?? '';
        $character_id  = (int) ( $params['character_id'] ?? 0 );
        $platform_type = $params['platform_type'] ?? 'ADMINCHAT';
        $message       = $params['message'] ?? '';
        $images        = $params['images'] ?? [];
        $kci_ratio     = (int) ( $params['kci_ratio'] ?? 80 );

        $context = [
            'session_id'       => $session_id,
            'platform_type'    => $platform_type,
            'message'          => $message,
            'character_id'     => $character_id,
            'images'           => $images,
            'kci_ratio'        => $kci_ratio,
            'mention_override' => ! empty( $params['mention_override'] ),
            'site_name'        => get_bloginfo( 'name' ),
            'plugin_slug'      => sanitize_text_field( (string) ( $params['plugin_slug'] ?? '' ) ),
            'provider_hint'    => sanitize_text_field( (string) ( $params['provider_hint'] ?? '' ) ),
            'routing_mode'     => sanitize_text_field( (string) ( $params['routing_mode'] ?? '' ) ),
            'tool_goal'        => sanitize_text_field( (string) ( $params['tool_goal'] ?? '' ) ),
            'tool_name'        => sanitize_text_field( (string) ( $params['tool_name'] ?? '' ) ),
        ];

        // ── Character Prompt ──
        if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
            $kdb       = BizCity_Knowledge_Database::instance();
            $character = $kdb->get_character( $character_id );
            if ( ! $character && method_exists( $kdb, 'get_characters' ) ) {
                $chars     = $kdb->get_characters( [ 'status' => 'active', 'limit' => 1 ] );
                $character = ! empty( $chars ) ? $chars[0] : null;
            }
            if ( $character ) {
                $context['character_prompt'] = $character->system_prompt ?? '';
                $context['model']           = $character->model_id ?? '';
                $context['temperature']     = isset( $character->creativity_level )
                    ? (float) $character->creativity_level
                    : 0.7;
            }
        }

        // ── User Memory Context ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem = BizCity_User_Memory::instance();
            $context['user_memory_context'] = $mem->build_memory_context(
                $user_id, $session_id, $session_id
            );
        }

        // ── Profile Context (astro/coaching) ──
        if ( class_exists( 'BizCity_Profile_Context' ) ) {
            $pc = BizCity_Profile_Context::instance();
            $context['profile_context'] = $pc->build_user_context(
                $user_id, $session_id, $platform_type, [ 'coach_type' => '' ]
            );
            $context['transit_context'] = $pc->build_transit_context(
                $message, $user_id, $session_id, $platform_type, ''
            );
        }

        // ── Provider Profile Context ──
        if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $providers = BizCity_Intent_Provider_Registry::instance()->get_all();
            $provider_ctx = '';
            foreach ( $providers as $provider ) {
                if ( method_exists( $provider, 'get_profile_context' ) ) {
                    $pc_result = $provider->get_profile_context( $user_id );
                    if ( ! empty( $pc_result['context'] ) ) {
                        $provider_ctx .= $pc_result['context'] . "\n";
                    }
                }
            }
            $context['provider_profile_context'] = trim( $provider_ctx );
        }

        // ── Knowledge RAG (skip for short casual messages) ──
        $skip_knowledge = false;
        $trimmed_msg = trim( $message );
        if ( mb_strlen( $trimmed_msg, 'UTF-8' ) <= 20 ) {
            $casual = '/^(h[ie]|hello|xin chào|chào|hey|ok|thanks?|cảm ơn|vâng|ừm?|dạ|bye|tạm biệt|good|tốt|được|oke?y?|ơi|vui|alo|đi)/iu';
            if ( preg_match( $casual, $trimmed_msg ) ) {
                $skip_knowledge = true;
            }
        }
        if ( ! $skip_knowledge && class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            $kca    = BizCity_Knowledge_Context_API::instance();
            $result = $kca->build_context( $character_id, $message, [
                'max_tokens'     => 3000,
                'include_vision' => ! empty( $images ),
                'images'         => $images,
            ] );
            $knowledge = $result['context'] ?? '';
            // Append keyword search
            if ( function_exists( 'bizcity_knowledge_search_character' ) ) {
                $keyword = bizcity_knowledge_search_character( $message, $character_id );
                if ( ! empty( $keyword ) ) {
                    $knowledge .= "\n" . $keyword;
                }
            }
            $context['knowledge_context'] = trim( $knowledge );
        }

        // ── Conversation History ──
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            $db      = BizCity_WebChat_Database::instance();
            $history = [];

            // Latest DB messages
            if ( method_exists( $db, 'get_recent_messages' ) ) {
                $raw = $db->get_recent_messages( $session_id, $platform_type, 10 );
                foreach ( (array) $raw as $msg ) {
                    $role = ( $msg->message_from ?? '' ) === 'bot' ? 'assistant' : 'user';
                    $history[] = [
                        'role'    => $role,
                        'content' => $msg->message_text ?? '',
                    ];
                }
            }
            $context['conversation_history'] = $history;

            // Session title (for suggest engine)
            if ( method_exists( $db, 'get_session' ) ) {
                $session = $db->get_session( $session_id );
                $context['session_title'] = $session->title ?? '';
            }
        }

        // ── Intent Conversation Messages ──
        if ( class_exists( 'BizCity_Intent_Database' ) ) {
            $idb = BizCity_Intent_Database::instance();

            // Active conversation
            if ( method_exists( $idb, 'find_active_conversation' ) ) {
                $active_conv = $idb->find_active_conversation( $user_id, 'webchat', $session_id );
                if ( $active_conv ) {
                    $context['active_goal']       = $active_conv->goal ?? '';
                    $context['active_goal_label'] = $active_conv->goal_label ?? '';
                    $context['conversation_id']   = $active_conv->conversation_id ?? '';
                    $context['active_slots']      = ! empty( $active_conv->slots_json )
                        ? json_decode( $active_conv->slots_json, true ) ?: []
                        : [];
                    // Full conversation object for server Intent Router / Planner
                    $context['active_conversation'] = [
                        'id'            => $active_conv->id ?? '',
                        'conversation_id' => $active_conv->conversation_id ?? '',
                        'goal'          => $active_conv->goal ?? '',
                        'goal_label'    => $active_conv->goal_label ?? '',
                        'status'        => $active_conv->status ?? '',
                        'slots'         => $context['active_slots'],
                        'waiting_for'   => $active_conv->waiting_for ?? '',
                        'waiting_field' => $active_conv->waiting_field ?? '',
                    ];
                }
            }

            // Rolling summary
            if ( method_exists( $idb, 'get_conversation' ) && ! empty( $context['conversation_id'] ) ) {
                $conv = $idb->get_conversation( $context['conversation_id'] );
                $context['rolling_summary'] = $conv->rolling_summary ?? '';
            }

            // Intent conversation messages for context resolver
            if (
                class_exists( 'BizCity_WebChat_Database' ) &&
                method_exists( BizCity_WebChat_Database::instance(), 'get_recent_messages_by_intent_conversation' ) &&
                ! empty( $context['conversation_id'] )
            ) {
                $intent_msgs = BizCity_WebChat_Database::instance()
                    ->get_recent_messages_by_intent_conversation(
                        $context['conversation_id'],
                        $session_id,
                        15
                    );
                $formatted = '';
                foreach ( (array) $intent_msgs as $m ) {
                    $from = ( $m->message_from ?? '' ) === 'bot' ? 'AI' : 'User';
                    $formatted .= "[{$from}]: " . ( $m->message_text ?? '' ) . "\n";
                }
                $context['intent_conversation_messages'] = trim( $formatted );
            }
        }

        // ── Tool Manifest ──
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $context['tool_manifest'] = BizCity_Intent_Tool_Index::instance()
                ->build_tools_context( 1500 );
        }

        // ── Episodic + Rolling Memory (for suggest engine) ──
        if ( class_exists( 'BizCity_Episodic_Memory' ) ) {
            $context['episodic_habits'] = BizCity_Episodic_Memory::instance()
                ->get_habits( $user_id );
        }
        if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
            $rm = BizCity_Rolling_Memory::instance();
            $context['rolling_active']    = $rm->get_active_for_user( $user_id, $session_id );
            $context['rolling_completed'] = $rm->get_recently_completed( $user_id, 60 );
        }

        // ── User Memories Typed (for suggest engine) ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $context['user_memories_typed'] = BizCity_User_Memory::instance()
                ->get_memories( [
                    'user_id'    => $user_id,
                    'session_id' => $session_id,
                    'limit'      => 5,
                    'order_by'   => 'score',
                ] );
        }

        // ── Client Engine Result (if local Intent Engine already classified) ──
        if ( ! empty( $params['client_engine_result'] ) && is_array( $params['client_engine_result'] ) ) {
            $context['client_engine_result'] = $params['client_engine_result'];
        }

        return $context;
    }

    /**
     * Call server — blocking JSON.
     *
     * @param array $params  Same as collect_context() params
     * @return array {success, response, engine_result, focus_profile, mutations, suggestions, usage, ...}
     */
    public function resolve( array $params ): array {
        $context = $this->collect_context( $params );

        $client = BizCity_LLM_Client::instance();
        $url    = $client->get_gateway_url() . '/wp-json/bizcity/v1/ai/resolve';

        $body = wp_json_encode( [
            'message' => $params['message'] ?? '',
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE );

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $client->get_api_key(),
            ],
            'body'    => $body,
            'timeout' => 90,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $status = wp_remote_retrieve_response_code( $response );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 || empty( $result['success'] ) ) {
            return [
                'success' => false,
                'error'   => $result['error'] ?? "HTTP {$status}",
            ];
        }

        // Apply mutations locally
        if ( ! empty( $result['mutations'] ) ) {
            $this->apply_mutations( $result['mutations'], $params );
        }

        return $result;
    }

    /**
     * Call server — SSE streaming.
     *
     * Streams chunks to the callback, handles engine events internally.
     *
     * @param array         $params    Same as collect_context() params
     * @param callable      $on_chunk  fn( string $delta, string $full_text )
     * @param callable|null $on_event  fn( string $event_type, array $payload )
     * @return array {success, response, engine_result, focus_profile, mutations, suggestions, usage, trace, tool_call, debug}
     */
    public function resolve_stream( array $params, callable $on_chunk, callable $on_event = null ): array {
        $context = $this->collect_context( $params );

        $client = BizCity_LLM_Client::instance();
        $url    = $client->get_gateway_url() . '/wp-json/bizcity/v1/ai/resolve/stream';

        $body = wp_json_encode( [
            'message' => $params['message'] ?? '',
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE );

        $engine_data = null;
        $done_data   = null;
        $tool_call   = null;
        $full_text   = '';
        $event_type  = '';
        $trace       = [];
        $event_count = 0;
        $raw_preview = '';
        $sse_buffer  = '';

        if ( $on_event ) {
            $client_trace = [
                'step'  => 'client_context',
                'level' => 'info',
                'data'  => $this->summarize_context_trace( $context, $params ),
            ];
            $trace[] = $client_trace;
            $on_event( 'trace', $client_trace );
            $on_event( 'status', [ 'text' => 'Smart Gateway: dang gom context local...' ] );
        }

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $client->get_api_key(),
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_BUFFERSIZE     => 1024,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use (
                &$engine_data, &$done_data, &$tool_call, &$full_text, &$event_type, &$trace, &$event_count, &$raw_preview, &$sse_buffer, $on_chunk, $on_event
            ) {
                if ( strlen( $raw_preview ) < 2000 ) {
                    $raw_preview .= substr( $data, 0, 2000 - strlen( $raw_preview ) );
                }

                // Buffer-based line parsing: accumulate data and process complete lines
                $sse_buffer .= $data;
                while ( ( $nl = strpos( $sse_buffer, "\n" ) ) !== false ) {
                    $line       = substr( $sse_buffer, 0, $nl );
                    $sse_buffer = substr( $sse_buffer, $nl + 1 );
                    $line       = trim( $line );
                    if ( $line === '' ) {
                        continue;
                    }
                    if ( strpos( $line, 'event: ' ) === 0 ) {
                        $event_type = substr( $line, 7 );
                        continue;
                    }
                    if ( strpos( $line, 'data: ' ) !== 0 ) {
                        continue;
                    }

                    $json = json_decode( substr( $line, 6 ), true );
                    if ( ! is_array( $json ) ) {
                        continue;
                    }

                    $event_count++;

                    switch ( $event_type ) {
                        case 'engine':
                            $engine_data = $json;
                            if ( $on_event ) {
                                $on_event( 'engine', $json );
                            }
                            break;
                        case 'trace':
                            $trace[] = $json;
                            if ( $on_event ) {
                                $on_event( 'trace', $json );
                            }
                            break;
                        case 'status':
                            if ( $on_event ) {
                                $on_event( 'status', $json );
                            }
                            break;
                        case 'content':
                            $delta      = $json['text'] ?? '';
                            $full_text .= $delta;
                            $on_chunk( $delta, $full_text );
                            break;
                        case 'tool_call':
                            $tool_call = $json;
                            if ( $on_event ) {
                                $on_event( 'tool_call', $json );
                            }
                            break;
                        case 'done':
                            $done_data = $json;
                            if ( $on_event ) {
                                $on_event( 'done_meta', $json );
                            }
                            break;
                        case 'error':
                            // Surface error as content
                            $err_msg    = $json['message'] ?? 'Server error';
                            $full_text .= $err_msg;
                            $on_chunk( $err_msg, $full_text );
                            if ( $on_event ) {
                                $on_event( 'error', $json );
                            }
                            break;
                    }
                }
                return strlen( $data );
            },
        ] );

        error_log( '[SmartGateway] resolve_stream START | url=' . $url . ' | session=' . ( $params['session_id'] ?? '' ) . ' | platform=' . ( $params['platform_type'] ?? '' ) . ' | msg=' . self::truncate_text( (string) ( $params['message'] ?? '' ), 120 ) );
        curl_exec( $ch );
        $curl_errno = curl_errno( $ch );
        $curl_error = curl_error( $ch );
        $http_code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
        curl_close( $ch );

        error_log( '[SmartGateway] resolve_stream END | http=' . $http_code . ' | curl_errno=' . $curl_errno . ' | events=' . $event_count . ' | full_len=' . strlen( $full_text ) . ' | engine=' . ( $engine_data ? '1' : '0' ) . ' | done=' . ( $done_data ? '1' : '0' ) . ' | content_type=' . $content_type . ' | preview=' . self::truncate_text( preg_replace( '/\s+/', ' ', $raw_preview ), 500 ) );

        // Apply mutations from done event
        if ( ! empty( $done_data['mutations'] ) ) {
            $this->apply_mutations( $done_data['mutations'], $params );
        }

        if ( $curl_errno ) {
            return [
                'success' => false,
                'error'   => 'cURL error: ' . $curl_errno . ( $curl_error ? ' | ' . $curl_error : '' ),
                'debug'   => [
                    'http_code'    => $http_code,
                    'content_type' => $content_type,
                    'event_count'  => $event_count,
                    'raw_preview'  => self::truncate_text( $raw_preview, 500 ),
                ],
            ];
        }

        if ( $http_code !== 200 ) {
            return [
                'success' => false,
                'error'   => 'Gateway stream HTTP ' . $http_code,
                'debug'   => [
                    'http_code'    => $http_code,
                    'content_type' => $content_type,
                    'event_count'  => $event_count,
                    'raw_preview'  => self::truncate_text( $raw_preview, 500 ),
                ],
            ];
        }

        if ( $event_count === 0 && empty( $full_text ) && empty( $engine_data ) && empty( $done_data ) ) {
            return [
                'success' => false,
                'error'   => 'Gateway stream returned no parseable SSE events.',
                'debug'   => [
                    'http_code'    => $http_code,
                    'content_type' => $content_type,
                    'event_count'  => $event_count,
                    'raw_preview'  => self::truncate_text( $raw_preview, 500 ),
                ],
            ];
        }

        return [
            'success'       => true,
            'response'      => $full_text,
            'engine_result' => $engine_data ?? [],
            'focus_profile' => $done_data['focus_profile'] ?? [],
            'mutations'     => $done_data['mutations'] ?? [],
            'suggestions'   => $done_data['suggestions'] ?? [],
            'usage'         => $done_data['usage'] ?? [],
            'tool_call'     => $tool_call ?? [],
            'trace'         => $trace,
            'debug'         => array_merge( $done_data['debug'] ?? [], [
                'http_code'    => $http_code,
                'content_type' => $content_type,
                'event_count'  => $event_count,
            ] ),
        ];
    }

    /**
     * Apply mutations returned by server back to local DB.
     *
     * @param array $mutations Mutation instructions from server
     */
    /**
     * Apply mutations returned by server back to local DB.
     *
     * @param array $mutations  Mutation instructions from server
     * @param array $params     Original request params (user_id, session_id, etc.) for enrichment
     */
    private function apply_mutations( array $mutations, array $params = [] ): void {
        // Rolling memory
        if ( ! empty( $mutations['rolling_memory']['data'] ) ) {
            $op   = $mutations['rolling_memory']['op'] ?? '';
            $data = $mutations['rolling_memory']['data'];
            if ( $op === 'upsert' && class_exists( 'BizCity_Rolling_Memory' ) ) {
                BizCity_Rolling_Memory::instance()->upsert( $data );
            }
        }

        // Episodic memory
        if ( ! empty( $mutations['episodic_memory']['data'] ) ) {
            $data = $mutations['episodic_memory']['data'];
            if ( class_exists( 'BizCity_Episodic_Memory' ) ) {
                BizCity_Episodic_Memory::instance()->record( $data );
            }
        }

        // Intent conversation state
        if ( ! empty( $mutations['conversation']['data'] ) ) {
            $op   = $mutations['conversation']['op'] ?? '';
            $data = $mutations['conversation']['data'];

            // Enrich with local context — server only sends goal/slots/status
            if ( ! empty( $params ) ) {
                $data = array_merge( [
                    'user_id'      => (int) ( $params['user_id'] ?? get_current_user_id() ),
                    'session_id'   => $params['session_id'] ?? '',
                    'channel'      => strtolower( $params['platform_type'] ?? 'webchat' ),
                    'character_id' => (int) ( $params['character_id'] ?? 0 ),
                    'goal_label'   => $data['goal'] ?? '',
                ], $data );
            }

            if ( class_exists( 'BizCity_Intent_Database' ) ) {
                $idb = BizCity_Intent_Database::instance();
                if ( $op === 'create' ) {
                    $result = $idb->insert_conversation( $data );
                    error_log( '[SmartGateway] mutation conversation CREATE | goal=' . ( $data['goal'] ?? '' ) . ' | status=' . ( $data['status'] ?? '' ) . ' | result=' . ( $result ?: 'FAILED' ) );
                } elseif ( $op === 'update' && ! empty( $data['id'] ) ) {
                    $idb->update_conversation( $data['id'], $data );
                    error_log( '[SmartGateway] mutation conversation UPDATE | id=' . $data['id'] . ' | status=' . ( $data['status'] ?? '' ) );
                }
            }
        }

        // User memory (save/forget)
        if ( ! empty( $mutations['user_memory'] ) ) {
            $op = $mutations['user_memory']['op'] ?? '';
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                $mem = BizCity_User_Memory::instance();
                if ( $op === 'save' && ! empty( $mutations['user_memory']['data'] ) ) {
                    $mem->save_memory( $mutations['user_memory']['data'] );
                } elseif ( $op === 'forget' && ! empty( $mutations['user_memory']['id'] ) ) {
                    $mem->delete_memory( (int) $mutations['user_memory']['id'] );
                }
            }
        }
    }

    private function summarize_context_trace( array $context, array $params ): array {
        return [
            'platform_type'           => $context['platform_type'] ?? '',
            'session_id'              => $context['session_id'] ?? '',
            'character_id'            => (int) ( $context['character_id'] ?? 0 ),
            'message_preview'         => $this->truncate_text( (string) ( $params['message'] ?? '' ), 120 ),
            'message_length'          => mb_strlen( (string) ( $params['message'] ?? '' ), 'UTF-8' ),
            'history_count'           => count( $context['conversation_history'] ?? [] ),
            'images_count'            => count( $context['images'] ?? [] ),
            'knowledge_length'        => mb_strlen( (string) ( $context['knowledge_context'] ?? '' ), 'UTF-8' ),
            'tool_manifest_length'    => mb_strlen( (string) ( $context['tool_manifest'] ?? '' ), 'UTF-8' ),
            'provider_context_length' => mb_strlen( (string) ( $context['provider_profile_context'] ?? '' ), 'UTF-8' ),
            'has_active_goal'         => ! empty( $context['active_goal'] ),
            'active_goal'             => $context['active_goal'] ?? '',
            'session_title'           => $this->truncate_text( (string) ( $context['session_title'] ?? '' ), 80 ),
        ];
    }

    private function truncate_text( string $text, int $limit = 160 ): string {
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
            return $text;
        }

        return mb_substr( $text, 0, $limit, 'UTF-8' ) . '...';
    }
}
