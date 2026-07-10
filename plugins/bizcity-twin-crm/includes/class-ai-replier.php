<?php
/**
 * BizCity CRM — AI Replier (Wave 0.35.G.1+G.2 minimal)
 *
 * Generates a grounded answer for the latest inbound message of a CRM
 * conversation using the notebook attached to that conversation (or its
 * inbox default), then writes the AI reply as an outgoing CRM message and
 * dispatches it via the registered channel adapter (FB/Zalo/…).
 *
 * The full thinking timeline (resolution → KG retrieval → LLM generation →
 * dispatch) is persisted in `crm_messages.ai_metadata_json` so the FE can
 * render `<ThinkingTimeline>` without an extra fetch.
 *
 * Trace shape:
 *   ai_metadata = {
 *     trace_uuid: string,
 *     notebook_id: int,
 *     character_id: int|null,
 *     latency_ms: int,
 *     steps: [
 *       { name: 'resolve_context', ms, detail: { notebook_id, source_count } },
 *       { name: 'kg_retrieval',    ms, detail: { passages, mode, kg_steps:[…] } },
 *       { name: 'llm_generate',    ms, detail: { model, provider, tokens } },
 *       { name: 'dispatch',        ms, detail: { sent, platform, error } },
 *     ],
 *     sources: [ { id, content, source_id, citation:'[src:S{source_id}p{id}]' } ],
 *   }
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_AI_Replier {

	/**
	 * Generate + send an AI reply for the most recent inbound user message
	 * in the given conversation. Returns trace + dispatch result.
	 *
	 * @param int   $conv_id
	 * @param array $opts {
	 *     @type string  prompt        Override the latest inbound text.
	 *     @type bool    dispatch      Default true. False = dry-run (no outbound).
	 *     @type int     notebook_id   Override notebook resolution.
	 *     @type int     character_id  Override character (LLM persona).
	 * }
	 * @return array
	 * @throws \RuntimeException
	 */
	public static function reply( int $conv_id, array $opts = array() ): array {
		// KG retrieval + LLM call can easily exceed 20s on cold notebooks.
		// Detach from FB webhook timeout so the pipeline survives even if the
		// inbound HTTP request gets cut.
		if ( function_exists( 'ignore_user_abort' ) ) { @ignore_user_abort( true ); }
		if ( function_exists( 'set_time_limit' ) )    { @set_time_limit( 120 ); }

		$t0 = microtime( true );
		$trace_uuid = wp_generate_uuid4();
		$steps      = array();
		self::log( "reply START conv#{$conv_id} trace={$trace_uuid} opts=" . wp_json_encode( $opts ) );

		// ── Step 1: resolve context ───────────────────────────────────
		$s1 = microtime( true );
		$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
		if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

		$inbox = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
		if ( ! $inbox ) { throw new \RuntimeException( 'inbox_not_found' ); }

		$inbox_settings = $inbox['settings_json']
			? ( json_decode( (string) $inbox['settings_json'], true ) ?: array() )
			: array();

		// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — resolve canonical identity/session keys for continuity.
		$identity_ctx = class_exists( 'BizCity_CRM_Conversation_Identity_Resolver' )
			? BizCity_CRM_Conversation_Identity_Resolver::resolve_for_conversation( $conv_id )
			: null;
		$llm_session_id     = (string) ( $identity_ctx['llm_session_id'] ?? ( 'crm_' . $conv_id ) );
		$platform_type_hint = (string) ( $identity_ctx['platform_type_hint'] ?? 'crm' );

		// ── Guru-on-Duty resolution: inbox(channel) → binding → character → notebooks.
		// This is the **primary** source of truth: the Twin Guru on Duty wired in
		// the Channel Gateway binding, and the notebooks attached on the Guru's
		// own "Notebooks" tab. Conversation/inbox-level overrides still win.
		$guru_ctx = class_exists( 'BizCity_CRM_Guru_Resolver' )
			? BizCity_CRM_Guru_Resolver::resolve_for_inbox( $inbox )
			: array( 'character_id' => 0, 'guru_uuid' => '', 'notebooks' => array(), 'trace' => array() );

		$character_id = (int) ( $opts['character_id']
			?? $inbox_settings['default_character_id']
			?? $guru_ctx['character_id']
			?? 0 );

		$notebook_id = (int) ( $opts['notebook_id']
			?? $conv['notebook_id']
			?? $inbox['default_notebook_id']
			?? ( $guru_ctx['notebooks'][0] ?? 0 ) );
		// [2026-06-29 Johnny Chu] HOTFIX — notebook is optional when character is bound.
		// Replying without KG retrieval is degraded but valid; only hard-fail when
		// neither notebook NOR character is available.
		if ( $notebook_id <= 0 && $character_id <= 0 ) {
			throw new \RuntimeException( 'no_notebook_attached' );
		}

		// Latest inbound message → prompt.
		$prompt = isset( $opts['prompt'] ) ? trim( (string) $opts['prompt'] ) : '';
		if ( $prompt === '' ) {
			$prompt = self::latest_inbound_text( $conv_id );
		}
		if ( $prompt === '' ) {
			throw new \RuntimeException( 'no_user_message' );
		}

		$nb_source = isset( $opts['notebook_id'] ) ? 'override'
			: ( ! empty( $conv['notebook_id'] ) ? 'conversation'
			: ( ! empty( $inbox['default_notebook_id'] ) ? 'inbox_default'
			: ( ! empty( $guru_ctx['notebooks'] ) ? 'guru_attached' : 'none' ) ) );

		// Resolve service template (role + persona + style + length budget).
		$svc = class_exists( 'BizCity_CRM_Service_Templates' )
			? BizCity_CRM_Service_Templates::resolve_for_character( $character_id, (string) ( $inbox['channel_type'] ?? '' ) )
			: array( 'slug' => 'none', 'template' => array(), 'source' => 'unavailable', 'char_role' => 'both' );

		$steps[] = array(
			'name'   => 'resolve_context',
			'ms'     => self::ms_since( $s1 ),
			'detail' => array(
				'session_id'         => $llm_session_id,
				'platform_type_hint' => $platform_type_hint,
				'identity'           => array(
					'canonical_identity_key' => (string) ( $identity_ctx['canonical_identity_key'] ?? '' ),
					'canonical_session_key'  => (string) ( $identity_ctx['canonical_session_key']  ?? '' ),
					'legacy_session_key'     => (string) ( $identity_ctx['legacy_session_key']     ?? ( 'crm_' . $conv_id ) ),
				),
				'notebook_id'        => $notebook_id,
				'character_id'       => $character_id ?: null,
				'prompt_chars'       => mb_strlen( $prompt ),
				'guru_on_duty'       => $guru_ctx['trace'],
				'notebook_source'    => $nb_source,
				'notebooks_eligible' => $guru_ctx['notebooks'], // for future MPR fan-out (≥3 → multi-perspective)
				'service_template'   => array(
					'slug'              => $svc['slug'],
					'label'             => (string) ( $svc['template']['label'] ?? '' ),
					'role_scope'        => (string) ( $svc['template']['role_scope'] ?? '' ),
					'char_role'         => $svc['char_role'],
					'source'            => $svc['source'],
					'max_chars_target'  => (int) ( $svc['template']['max_chars_target']  ?? 0 ),
					'max_tokens_hint'   => (int) ( $svc['template']['max_tokens_hint']   ?? 0 ),
					'per_chunk_max'     => (int) ( $svc['template']['per_chunk_max_chars'] ?? 0 ),
					'allowed_channels'  => (array) ( $svc['template']['allowed_channels']  ?? array() ),
				),
			),
		);
		self::log( sprintf(
			'→ resolve_context session=%s platform=%s notebook#%d (src=%s, eligible=[%s]) char=%d guru_uuid=%s svc_template=%s (role=%s, max=%dch/%dtok, src=%s) prompt=%s',
			$llm_session_id,
			$platform_type_hint,
			$notebook_id,
			$nb_source,
			implode( ',', $guru_ctx['notebooks'] ),
			$character_id,
			( $guru_ctx['guru_uuid'] ? substr( $guru_ctx['guru_uuid'], 0, 8 ) . '…' : '—' ),
			$svc['slug'],
			$svc['char_role'],
			(int) ( $svc['template']['max_chars_target']  ?? 0 ),
			(int) ( $svc['template']['max_tokens_hint']   ?? 0 ),
			$svc['source'],
			mb_substr( $prompt, 0, 80 )
		) );

		// [2026-06-29 Johnny Chu] HOTFIX diag — log character DB data (system_prompt + quick_faq) visibility
		if ( $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			$_diag_char = BizCity_Knowledge_Database::instance()->get_character( $character_id );
			$_diag_sp   = $_diag_char ? mb_strlen( (string) ( $_diag_char->system_prompt ?? '' ) ) : -1;
			// Count quick_faq sources from DB
			$_diag_faq_count = 0;
			if ( $_diag_char ) {
				global $wpdb;
				$_diag_faq_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources WHERE character_id = %d AND source_type = 'quick_faq'",
					$character_id
				) );
			}
			self::log( sprintf(
				'→ char_context char=%d name=%s system_prompt=%dch quick_faq_rows=%d gateway_class=%s',
				$character_id,
				$_diag_char ? mb_substr( (string) ( $_diag_char->name ?? '?' ), 0, 30 ) : '(not_found)',
				$_diag_sp,
				$_diag_faq_count,
				class_exists( 'BizCity_Chat_Gateway' ) ? 'LOADED' : 'MISSING'
			) );
		}

		// ── Step 2: KG retrieval (Graph-RAG, returns answer + passages) ─
		$s2 = microtime( true );
		// [2026-06-29 Johnny Chu] HOTFIX — when notebook_id=0, skip KG retrieval entirely.
		// Character with system_prompt/FAQ can still reply; KG enrichment is optional.
		$passages  = array();
		$sources   = array();
		$kg_answer = '';
		if ( $notebook_id > 0 ) {
			if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
				self::log( 'FATAL: BizCity_KG_Retriever class not loaded' );
				throw new \RuntimeException( 'kg_retriever_unavailable' );
			}
			self::log( "→ kg_retrieval CALL notebook#{$notebook_id} BizCity_KG_Retriever::instance()->ask(...)" );
			try {
				$rag = BizCity_KG_Retriever::instance()->ask( $notebook_id, $prompt, array(
					'answer' => true,
				) );
			} catch ( \Throwable $e ) {
				self::log( 'kg_retrieval THREW: ' . get_class( $e ) . ' — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
				throw $e;
			}
			if ( ! is_array( $rag ) ) {
				self::log( 'kg_retrieval returned non-array: ' . gettype( $rag ) . ' — ' . substr( wp_json_encode( $rag ), 0, 200 ) );
				$rag = array();
			}
			$passages = is_array( $rag['passages'] ?? null ) ? $rag['passages'] : array();
			foreach ( $passages as $p ) {
				$sources[] = array(
					'id'        => (int) ( $p['id']        ?? 0 ),
					'source_id' => (int) ( $p['source_id'] ?? 0 ),
					'content'   => (string) ( $p['content'] ?? '' ),
					'citation'  => sprintf( '[src:S%dp%d]', (int) ( $p['source_id'] ?? 0 ), (int) ( $p['id'] ?? 0 ) ),
				);
			}
			$kg_answer = (string) ( $rag['answer'] ?? '' );
		} else {
			self::log( "→ kg_retrieval SKIP notebook_id=0 (char-only mode — system_prompt+FAQ only)" );
			$rag = array();
		}
		$steps[] = array(
			'name'   => 'kg_retrieval',
			'ms'     => self::ms_since( $s2 ),
			'detail' => array(
				'passages'      => count( $passages ),
				'mode'          => (string) ( $rag['retrieval_mode'] ?? 'graph_rag' ),
				'kg_steps'      => is_array( $rag['steps'] ?? null ) ? $rag['steps'] : array(),
				'seed_entities' => $rag['retrieval_detail']['entity_texts'] ?? array(),
			),
		);
		self::log( sprintf( '→ kg_retrieval passages=%d mode=%s seeds=%d kg_steps=%d',
			count( $passages ),
			(string) ( $rag['retrieval_mode'] ?? 'graph_rag' ),
			count( $rag['retrieval_detail']['entity_texts'] ?? array() ),
			count( $rag['steps'] ?? array() )
		) );

		// ── Step 3: LLM generate ─────────────────────────────────────
		// Strategy: trust KG answer (already grounded in notebook passages
		// from Step 2). Optionally route through Chat_Gateway when a Twin
		// Guru character is bound, but **inject our notebook passages** as
		// authoritative context — otherwise the gateway's own retrieval
		// (character-scoped Guru Knowledge L2) ignores the notebook the user
		// attached on Inbox/Conversation, leading to generic LLM answers.
		$s3       = microtime( true );
		$reply    = $kg_answer;
		$provider = '';
		$model    = (string) ( get_option( BizCity_KG_Retriever::ANSWER_MODEL_OPTION, BizCity_KG_Retriever::DEFAULT_ANSWER_MODEL ) );
		$usage    = array();
		$llm_note = 'kg-rag-direct';

		// [2026-06-29 Johnny Chu] HOTFIX diag — log whether BizCity_Chat_Gateway is loaded
		self::log( sprintf( '→ gateway_check char=%d gateway=%s passages=%d kg_answer_len=%d',
			$character_id,
			class_exists( 'BizCity_Chat_Gateway' ) ? 'LOADED' : 'MISSING',
			count( $passages ),
			mb_strlen( $kg_answer )
		) );

		if ( $character_id && class_exists( 'BizCity_Chat_Gateway' )
			&& method_exists( 'BizCity_Chat_Gateway', 'instance' ) ) {
			try {
				// Build a context-augmented prompt: prepend the K passages
				// the notebook just retrieved, with citation tags. This
				// forces the gateway's LLM to ground in OUR notebook even
				// when the character has its own Guru Knowledge.
				$prompt_aug = $prompt;
				$persona_prefix = '';
				if ( ! empty( $svc['template'] ) && class_exists( 'BizCity_CRM_Service_Templates' ) ) {
					$persona_prefix = BizCity_CRM_Service_Templates::build_persona_prefix( (array) $svc['template'] );
				}
				if ( ! empty( $passages ) ) {
					$ctx_lines = array( '【Tài liệu nội bộ ưu tiên (notebook#' . $notebook_id . ')】' );
					$cap = 0;
					foreach ( $passages as $i => $p ) {
						$snippet = trim( (string) ( $p['content'] ?? '' ) );
						if ( $snippet === '' ) { continue; }
						if ( mb_strlen( $snippet ) > 800 ) { $snippet = mb_substr( $snippet, 0, 800 ) . '…'; }
						$ctx_lines[] = sprintf( '[src:S%dp%d] %s',
							(int) ( $p['source_id'] ?? 0 ),
							(int) ( $p['id'] ?? 0 ),
							$snippet
						);
						$cap += mb_strlen( $snippet );
						if ( $cap > 4000 ) { break; } // hard cap context size
					}
					$ctx_lines[] = '【Hết tài liệu】';
					$ctx_lines[] = '';
					$ctx_lines[] = 'Nhiệm vụ: dựa CHÍNH XÁC vào các đoạn trên để trả lời câu hỏi của khách. Nếu thiếu thông tin trong tài liệu thì nói rõ "Tôi chưa có thông tin về điều này trong tài liệu". Trích nguồn bằng tag [src:S#p#] khi cần.';
					$ctx_lines[] = '';
					$ctx_lines[] = 'Câu hỏi của khách: ' . $prompt;
					$prompt_aug  = implode( "\n", $ctx_lines );
					self::log( sprintf( '→ llm_generate inject_context passages=%d ctx_chars=%d', count( $passages ), mb_strlen( $prompt_aug ) - mb_strlen( $prompt ) ) );
				}
				if ( $persona_prefix !== '' ) {
					$prompt_aug = $persona_prefix . $prompt_aug;
					self::log( sprintf(
						'→ apply_persona template=%s role=%s prefix_chars=%d total_prompt_chars=%d',
						$svc['slug'], $svc['char_role'], mb_strlen( $persona_prefix ), mb_strlen( $prompt_aug )
					) );
					$steps[] = array(
						'name'   => 'apply_persona',
						'ms'     => 0,
						'detail' => array(
							'template_slug'   => $svc['slug'],
							'template_label'  => (string) ( $svc['template']['label'] ?? '' ),
							'role_scope'      => (string) ( $svc['template']['role_scope'] ?? '' ),
							'char_role'       => $svc['char_role'],
							'prefix_chars'    => mb_strlen( $persona_prefix ),
							'max_chars_target'=> (int) ( $svc['template']['max_chars_target'] ?? 0 ),
							'max_tokens_hint' => (int) ( $svc['template']['max_tokens_hint']  ?? 0 ),
							'source'          => $svc['source'],
						),
					);
				}

				// Hint LLM token ceiling for this turn (Gateway / providers may honor).
				if ( ! empty( $svc['template']['max_tokens_hint'] ) ) {
					$max_tok_hint = (int) $svc['template']['max_tokens_hint'];
					$tok_filter = function ( $val ) use ( $max_tok_hint ) { return $max_tok_hint; };
					add_filter( 'bizcity_chat_max_tokens',         $tok_filter, 99 );
					add_filter( 'bizcity_llm_max_completion_tokens', $tok_filter, 99 );
				}

				// Tell skill matcher to score triggers against the RAW user
				// message only (not the RAG-augmented prompt) — otherwise
				// trigger keywords inside notebook passages cause spurious
				// skill matches and the LLM reply mentions skills that the
				// user never invoked.
				$GLOBALS['_bizcity_skill_match_raw_message'] = (string) $prompt;

				// [2026-06-29 Johnny Chu] HOTFIX — force build_context (quick_faq LIKE search)
				// to use the raw user question, NOT the $prompt_aug which contains notebook
				// passages. Without this, keywords extracted from notebook content flood the
				// LIKE query and the quick_faq scoring becomes unreliable.
				$GLOBALS['_bizcity_knowledge_query_override'] = (string) $prompt;

				$gw_res = BizCity_Chat_Gateway::instance()->get_ai_response(
					$character_id,
					$prompt_aug,
					array(),                        // images
					$llm_session_id,
					'[]',                           // history (Gateway will hydrate by session_id)
					(int) get_current_user_id(),
					$platform_type_hint
				);
				unset( $GLOBALS['_bizcity_skill_match_raw_message'] );
				unset( $GLOBALS['_bizcity_knowledge_query_override'] );
				if ( is_array( $gw_res ) && ! empty( $gw_res['message'] ) ) {
					$reply    = (string) $gw_res['message'];
					$provider = (string) ( $gw_res['provider'] ?? '' );
					$model    = (string) ( $gw_res['model']    ?? $model );
					$usage    = is_array( $gw_res['usage'] ?? null ) ? $gw_res['usage'] : array();
					$llm_note = 'chat-gateway+character';
				} elseif ( is_string( $gw_res ) && $gw_res !== '' ) {
					// Gateway returned an early-error string (e.g. KG not ready).
					$llm_note = 'chat-gateway-error: ' . mb_substr( $gw_res, 0, 80 );
				}
			} catch ( \Throwable $e ) {
				$llm_note = 'chat-gateway-throw: ' . $e->getMessage();
				// keep $reply = $kg_answer (graceful degradation).
			}
			unset( $GLOBALS['_bizcity_skill_match_raw_message'] );
			// Remove transient token-cap filters injected above for this turn.
			if ( isset( $tok_filter ) ) {
				remove_filter( 'bizcity_chat_max_tokens',         $tok_filter, 99 );
				remove_filter( 'bizcity_llm_max_completion_tokens', $tok_filter, 99 );
			}
		}

		if ( $reply === '' ) {
			$reply = '⚠️ Không có dữ liệu trong notebook để trả lời câu hỏi này.';
			$llm_note .= ' [empty-fallback]';
		}

		// Enforce template length budget (post-LLM hard trim).
		$max_chars_target = (int) ( $svc['template']['max_chars_target'] ?? 0 );
		if ( $max_chars_target > 0 && mb_strlen( $reply ) > $max_chars_target * 1.4 ) {
			$orig_len = mb_strlen( $reply );
			$reply    = mb_substr( $reply, 0, (int) ( $max_chars_target * 1.4 ) ) . '…';
			$llm_note .= sprintf( ' [trim:%s %d→%d]', $svc['slug'], $orig_len, mb_strlen( $reply ) );
		}

		$steps[] = array(
			'name'   => 'llm_generate',
			'ms'     => self::ms_since( $s3 ),
			'detail' => array(
				'provider'     => $provider,
				'model'        => $model,
				'usage'        => $usage,
				'reply_chars'  => mb_strlen( $reply ),
				'note'         => $llm_note,
			),
		);
		self::log( sprintf( '→ llm_generate model=%s reply=%d chars note=%s', $model, mb_strlen( $reply ), $llm_note ) );

		// ── Step 4: insert CRM outgoing row + dispatch ────────────────
		$s4 = microtime( true );
		$dispatch = array( 'sent' => false, 'platform' => '', 'error' => 'not-dispatched' );
		$msg_id   = 0;

		$ai_metadata = array(
			'trace_uuid'   => $trace_uuid,
			'identity'     => array(
				'session_id'             => $llm_session_id,
				'platform_type_hint'     => $platform_type_hint,
				'canonical_identity_key' => (string) ( $identity_ctx['canonical_identity_key'] ?? '' ),
				'canonical_session_key'  => (string) ( $identity_ctx['canonical_session_key']  ?? '' ),
				'legacy_session_key'     => (string) ( $identity_ctx['legacy_session_key']     ?? ( 'crm_' . $conv_id ) ),
			),
			'notebook_id'  => $notebook_id,
			'character_id' => $character_id ?: null,
			'latency_ms'   => 0, // back-filled
			'steps'        => $steps,           // will append dispatch step before save
			'sources'      => $sources,
			'prompt'       => $prompt,
		);

		// Push responder context = auto + character so downstream stamping
		// (gateway adapters → Channel_Messages ledger) carries the same trace.
		if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
			BizCity_Responder_Stamper::push( array(
				'kind'         => 'auto',
				'character_id' => $character_id ?: null,
				'user_id'      => (int) get_current_user_id() ?: null,
				'source'       => 'crm-ai-replier',
			) );
		}

		try {
			$msg_id = BizCity_CRM_Repository::insert_message( array(
				'conversation_id'   => $conv_id,
				'inbox_id'          => (int) $conv['inbox_id'],
				'content'           => $reply,
				'content_type'      => 'text',
				'message_type'      => 'outgoing',
				'sender_type'       => 'bot',
				'sender_id'         => null,
				'status'            => 'pending',
				'responder_kind'    => 'auto',
				'character_id'      => $character_id ?: null,
				'ai_metadata'       => $ai_metadata,
			) );

			$want_dispatch = ! isset( $opts['dispatch'] ) || (bool) $opts['dispatch'];
			if ( $want_dispatch && $msg_id ) {
				$tpl_chunk_max = (int) ( $svc['template']['per_chunk_max_chars'] ?? 0 );
				// [2026-07-07 Johnny Chu] HOTFIX — propagate trace_uuid into outbound sender path.
				$dispatch = self::dispatch_via_adapter( $conv, $reply, $tpl_chunk_max, $trace_uuid );

				global $wpdb;
				$wpdb->update(
					BizCity_CRM_DB_Installer_V2::tbl_messages(),
					array( 'status' => $dispatch['sent'] ? 'sent' : 'failed' ),
					array( 'id' => $msg_id )
				);
			}
		} finally {
			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}
		}

		$steps[] = array(
			'name'   => 'dispatch',
			'ms'     => self::ms_since( $s4 ),
			'detail' => $dispatch,
		);
		self::log( sprintf( '→ dispatch sent=%s platform=%s err=%s',
			! empty( $dispatch['sent'] ) ? 'YES' : 'NO',
			(string) ( $dispatch['platform'] ?? '' ),
			(string) ( $dispatch['error']    ?? '' )
		) );

		// Back-fill final latency + steps into the row.
		$ai_metadata['steps']      = $steps;
		$ai_metadata['latency_ms'] = self::ms_since( $t0 );
		if ( $msg_id ) {
			global $wpdb;
			$wpdb->update(
				BizCity_CRM_DB_Installer_V2::tbl_messages(),
				array( 'ai_metadata_json' => wp_json_encode( $ai_metadata ) ),
				array( 'id' => $msg_id )
			);
		}

		return array(
			'message_id'  => $msg_id,
			'trace_uuid'  => $trace_uuid,
			'reply'       => $reply,
			'sources'     => $sources,
			'steps'       => $steps,
			'dispatch'    => $dispatch,
			'notebook_id' => $notebook_id,
			'character_id'=> $character_id ?: null,
			'latency_ms'  => $ai_metadata['latency_ms'],
		);
	}

	/* ─────────── helpers ─────────── */

	private static function latest_inbound_text( int $conv_id ): string {
		$rows = BizCity_CRM_Repository::list_messages( $conv_id, 25, 0 );
		// Newest last (typical insertion order); walk reversed.
		for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
			$r = $rows[ $i ];
			if ( ( $r['message_type'] ?? '' ) === 'incoming' ) {
				$txt = trim( (string) ( $r['content'] ?? '' ) );
				if ( $txt !== '' ) { return $txt; }
			}
		}
		return '';
	}

	/**
	 * FB Messenger limits message[text] to 2000 chars (UTF-16 code units, but
	 * mb_strlen approximation is safe for Vietnamese). Other channels:
	 * Zalo OA = 2000, Telegram = 4096. We pick a conservative chunk size.
	 *
	 * Filter: bizcity_crm_ai_chunk_size (per-platform; defaults below).
	 */
	private static function platform_chunk_size( string $platform ): int {
		$defaults = array(
			'facebook' => 1800,
			'zalo'     => 1800,
			'telegram' => 3800,
		);
		$size = $defaults[ strtolower( $platform ) ] ?? 1800;
		return (int) apply_filters( 'bizcity_crm_ai_chunk_size', $size, $platform );
	}

	/**
	 * Split a long reply into reader-friendly chunks ≤ $max chars.
	 * Prefers boundaries: blank-line → newline → sentence (.!? + non-letter)
	 * → space. Hard-cuts only as last resort. Keeps bullet lists together when
	 * possible. Trims each chunk and drops empties.
	 *
	 * @return string[]
	 */
	public static function chunk_text( string $text, int $max = 1800 ): array {
		$text = trim( $text );
		if ( $text === '' )                  { return array(); }
		if ( mb_strlen( $text ) <= $max )    { return array( $text ); }

		$out  = array();
		$buf  = '';

		// 1st pass: split on blank lines (paragraphs).
		$paragraphs = preg_split( '/\n{2,}/u', $text ) ?: array( $text );

		$flush = static function () use ( &$buf, &$out ) {
			$b = trim( $buf );
			if ( $b !== '' ) { $out[] = $b; }
			$buf = '';
		};

		foreach ( $paragraphs as $p ) {
			$p = trim( $p );
			if ( $p === '' ) { continue; }

			// Paragraph itself fits in current buffer?
			if ( mb_strlen( $buf ) + mb_strlen( $p ) + 2 <= $max ) {
				$buf .= ( $buf === '' ? '' : "\n\n" ) . $p;
				continue;
			}
			// Doesn't fit → flush buffer first.
			$flush();

			// If paragraph alone fits → start fresh buffer with it.
			if ( mb_strlen( $p ) <= $max ) {
				$buf = $p;
				continue;
			}

			// Paragraph too big — split on single newlines (preserve list items).
			$lines = preg_split( '/\n/u', $p ) ?: array( $p );
			foreach ( $lines as $line ) {
				$line = rtrim( $line );
				if ( $line === '' ) { continue; }
				if ( mb_strlen( $buf ) + mb_strlen( $line ) + 1 <= $max ) {
					$buf .= ( $buf === '' ? '' : "\n" ) . $line;
					continue;
				}
				$flush();
				if ( mb_strlen( $line ) <= $max ) {
					$buf = $line;
					continue;
				}

				// Single line too big — split on sentence boundaries.
				$sentences = preg_split( '/(?<=[\.\!\?…])\s+/u', $line ) ?: array( $line );
				foreach ( $sentences as $s ) {
					$s = trim( $s );
					if ( $s === '' ) { continue; }
					if ( mb_strlen( $buf ) + mb_strlen( $s ) + 1 <= $max ) {
						$buf .= ( $buf === '' ? '' : ' ' ) . $s;
						continue;
					}
					$flush();
					if ( mb_strlen( $s ) <= $max ) {
						$buf = $s;
						continue;
					}
					// Sentence still too big — hard cut at word boundary.
					while ( mb_strlen( $s ) > $max ) {
						$cut = mb_substr( $s, 0, $max );
						$sp  = mb_strrpos( $cut, ' ' );
						if ( $sp !== false && $sp > (int) ( $max * 0.6 ) ) {
							$cut = mb_substr( $cut, 0, $sp );
						}
						$out[] = trim( $cut );
						$s     = trim( mb_substr( $s, mb_strlen( $cut ) ) );
					}
					if ( $s !== '' ) { $buf = $s; }
				}
			}
		}
		$flush();

		// Annotate sequence (1/3) when more than one chunk.
		$total = count( $out );
		if ( $total > 1 ) {
			$prefixed = array();
			foreach ( $out as $i => $part ) {
				$tag       = '(' . ( $i + 1 ) . '/' . $total . ') ';
				$room      = $max - mb_strlen( $tag );
				$prefixed[] = $tag . ( mb_strlen( $part ) > $room ? mb_substr( $part, 0, $room ) : $part );
			}
			$out = $prefixed;
		}

		return $out;
	}

	/**
	 * Dispatch the freshly-composed reply through the same channel adapter
	 * that manual replies use (REST post_message). Mirror to Channel Gateway
	 * ledger so the unified outbound stream stays consistent.
	 *
	 * Splits long replies into platform-safe chunks (FB Messenger 2000-char
	 * limit etc.) and sends sequentially. Aggregated dispatch result reports
	 * success only if **all** chunks succeed.
	 *
	 * @return array{sent:bool, platform:string, error:string, mid?:string, chunks?:int, sent_chunks?:int}
	 */
	private static function dispatch_via_adapter( array $conv, string $content, int $chunk_max_override = 0, string $trace_uuid = '' ): array {
		$inbox = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
		$code  = $inbox ? (string) $inbox['channel_type'] : '';

		$max = $chunk_max_override > 0
			? $chunk_max_override
			: self::platform_chunk_size( $code );
		$chunks = self::chunk_text( $content, $max );
		if ( empty( $chunks ) ) {
			return array( 'sent' => false, 'platform' => $code, 'error' => 'empty_content' );
		}
		if ( count( $chunks ) > 1 ) {
			self::log( sprintf( '↳ split reply into %d chunks (max=%d, total_chars=%d)', count( $chunks ), $max, mb_strlen( $content ) ) );
		}

		$adapter = $code && class_exists( 'BizCity_CRM_Channel_Registry' )
			? BizCity_CRM_Channel_Registry::get( $code )
			: null;

		$last_err   = '';
		$last_mid   = '';
		$ok_count   = 0;
		$resolved   = BizCity_CRM_Repository::resolve_chat_id( (int) $conv['id'] );

		foreach ( $chunks as $idx => $part ) {
			$res = null;
			// [2026-07-07 Johnny Chu] HOTFIX — stamp source context for Gateway/Zalo trace logs.
			$_prev_trace_ctx = array_key_exists( '_bizcity_outbound_trace_ctx', $GLOBALS )
				? $GLOBALS['_bizcity_outbound_trace_ctx']
				: null;
			$GLOBALS['_bizcity_outbound_trace_ctx'] = array(
				'source'       => 'crm.ai_replier',
				'trace_id'     => $trace_uuid !== '' ? $trace_uuid : ( 'crm-' . substr( sha1( $content ), 0, 12 ) ),
				'conversation' => (int) ( $conv['id'] ?? 0 ),
				'inbox_id'     => (int) ( $conv['inbox_id'] ?? 0 ),
				'chunk'        => (int) ( $idx + 1 ),
				'chunks'       => (int) count( $chunks ),
			);
			self::log( sprintf(
				'↳ outbound trace source=crm.ai_replier trace=%s conv#%d chunk=%d/%d',
				(string) ( $GLOBALS['_bizcity_outbound_trace_ctx']['trace_id'] ?? '-' ),
				(int) ( $conv['id'] ?? 0 ),
				$idx + 1,
				count( $chunks )
			) );
			// Tap to detect adapter-emitted outbound_logged so we don't mirror twice.
			$gw_emitted = 0;
			$gw_tap     = static function () use ( &$gw_emitted ) { $gw_emitted++; };
			if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
				BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( true );
			}
			add_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
			try {
				if ( $adapter ) {
					$res = $adapter->send( $conv, array(
						'content'      => $part,
						'content_type' => 'text',
					) );
					$ok  = ! empty( $res['success'] );
					$mid = (string) ( $res['external_source_id'] ?? '' );
					$err = (string) ( $res['error'] ?? '' );
				} elseif ( class_exists( 'BizCity_Gateway_Sender' ) && $resolved ) {
					$res = BizCity_Gateway_Sender::instance()->send( $resolved['chat_id'], $part );
					$ok  = ! empty( $res['sent'] );
					$mid = '';
					$err = (string) ( $res['error'] ?? '' );
				} else {
					return array( 'sent' => false, 'platform' => $code, 'error' => 'no_adapter', 'chunks' => count( $chunks ), 'sent_chunks' => $ok_count );
				}
			} finally {
				remove_action( 'bizcity_channel_outbound_logged', $gw_tap, 1 );
				if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
					BizCity_CRM_Facebook_Ingestor::set_crm_outbound_in_flight( false );
				}
				if ( $_prev_trace_ctx === null ) {
					unset( $GLOBALS['_bizcity_outbound_trace_ctx'] );
				} else {
					$GLOBALS['_bizcity_outbound_trace_ctx'] = $_prev_trace_ctx;
				}
			}

			if ( $ok ) {
				$ok_count++;
				$last_mid = $mid ?: $last_mid;
			} else {
				$last_err = $err ?: 'unknown';
				self::log( sprintf( '↳ chunk %d/%d FAILED: %s', $idx + 1, count( $chunks ), $last_err ) );
				break; // stop on first failure to avoid spamming user.
			}

			// Only mirror to channel_messages ledger when the adapter path did
			// not already emit through Gateway_Sender (avoids double row).
			if ( $resolved && $gw_emitted === 0 ) {
				do_action( 'bizcity_channel_outbound_logged', array(
					'chat_id'  => $resolved['chat_id'],
					'platform' => $resolved['platform'],
					'message'  => $part,
					'type'     => 'text',
					'extra'    => array(
						'mid'        => $mid,
						'source'     => 'crm-ai-replier',
						'chunk_idx'  => $idx + 1,
						'chunk_total'=> count( $chunks ),
					),
					'sent'     => true,
					'error'    => '',
				) );
			}

			// Tiny pause so messages arrive in correct order on Messenger UI.
			if ( $idx + 1 < count( $chunks ) ) {
				usleep( 250 * 1000 ); // 250ms
			}
		}

		return array(
			'sent'        => $ok_count === count( $chunks ),
			'platform'    => $code,
			'error'       => $ok_count === count( $chunks ) ? '' : $last_err,
			'mid'         => $last_mid,
			'chunks'      => count( $chunks ),
			'sent_chunks' => $ok_count,
		);
	}

	private static function ms_since( float $t0 ): int {
		return (int) round( ( microtime( true ) - $t0 ) * 1000 );
	}

	private static function log( string $msg ): void {
		error_log( '[bizcity-crm-replier] ' . $msg );
	}
}
